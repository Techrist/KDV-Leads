<?php
namespace KdvLeads\Http;

use KdvLeads\Config;
use KdvLeads\Database\Counters;
use KdvLeads\Database\FileRepository;
use KdvLeads\Database\LeadRepository;
use KdvLeads\Database\ReferenceGenerator;

defined('ABSPATH') || exit;

final class RestController
{
    public const NS = 'kdv/v1';

    public static function init(): void
    {
        add_action('rest_api_init', [self::class, 'routes']);
    }

    public static function routes(): void
    {
        register_rest_route(self::NS, '/plate/(?P<plate>[A-Za-z0-9\- ]{5,12})', [
            'methods'             => 'GET',
            'callback'            => [self::class, 'plate'],
            'permission_callback' => '__return_true', // nonce checked in the handler, only once a provider is active
        ]);
        foreach (['estimation', 'recherche'] as $type) {
            register_rest_route(self::NS, '/leads/' . $type, [
                'methods'             => 'POST',
                'callback'            => static fn (\WP_REST_Request $r) => self::submit($r, $type),
                'permission_callback' => '__return_true', // public form: protected by nonce + anti-spam
            ]);
        }
    }

    /** GET /plate/{plaque}: inactive (HTTP 501) while no provider is configured. */
    public static function plate(\WP_REST_Request $req): \WP_REST_Response
    {
        $lookup = apply_filters('kdv_plate_lookup', new \KdvLeads\Vehicle\NullPlateLookup());
        if (!$lookup instanceof \KdvLeads\Vehicle\PlateLookup || !$lookup->enabled()) {
            return self::fail(501, 'La recherche par immatriculation n\'est pas activée.');
        }
        if (!wp_verify_nonce((string) $req->get_header('X-WP-Nonce'), 'wp_rest')) {
            return self::fail(403, 'Votre session a expiré. Rechargez la page.');
        }
        $plate = Validator::normalize_plate((string) $req['plate']);
        if ($plate === null) {
            return self::fail(422, 'Format d\'immatriculation invalide.');
        }
        // Every lookup costs money: strict per-IP limit.
        if (Counters::hit(Counters::hourly('pl', AntiSpam::client_ip())) > 10) {
            return self::fail(429, 'Trop de recherches. Saisissez les informations manuellement.');
        }

        try {
            $data = $lookup->lookup($plate);
        } catch (\Throwable $e) {
            error_log('[kdv-leads] plate lookup failed: ' . $e->getMessage());
            return self::fail(502, 'Recherche momentanément indisponible. Saisissez les informations manuellement.');
        }
        return new \WP_REST_Response(['success' => $data !== null, 'data' => $data ?: (object) []], $data !== null ? 200 : 404);
    }

    private static function fail(int $status, string $message, array $errors = []): \WP_REST_Response
    {
        return new \WP_REST_Response(['success' => false, 'message' => $message, 'errors' => (object) $errors], $status);
    }

    /**
     * Processing order (§9.2): nonce → idempotence → anti-spam → validation → save.
     * A lead is saved before the PDF and the e-mails; neither can make the submission fail.
     */
    private static function submit(\WP_REST_Request $req, string $type): \WP_REST_Response
    {
        if (!wp_verify_nonce((string) $req->get_header('X-WP-Nonce'), 'wp_rest')) {
            return self::fail(403, 'Votre session a expiré. Rechargez la page : vos informations sont conservées.');
        }
        // Cheap, atomic, before any database lookup: stops hammering whether the payload is valid or not.
        if (AntiSpam::attempts_exceeded()) {
            return self::fail(429, 'Trop de requêtes depuis votre connexion. Réessayez dans quelques minutes.');
        }
        $in = $req->get_json_params();
        if (!is_array($in)) {
            return self::fail(400, 'Requête invalide.');
        }

        $key = (string) ($in['submission_key'] ?? '');
        if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $key)) {
            return self::fail(400, 'Requête invalide.');
        }
        $key = strtolower($key);
        if ($existing = LeadRepository::find_by_submission_key($key)) {
            return new \WP_REST_Response(['success' => true, 'reference' => $existing['reference']], 200);
        }

        if ($msg = AntiSpam::check($in)) {
            return self::fail(400, $msg);
        }

        [$errors, $c] = $type === 'recherche' ? Validator::recherche($in) : Validator::estimation($in);
        if ($errors) {
            return self::fail(422, 'Certaines informations sont à corriger.', $errors);
        }
        if (AntiSpam::rate_limited()) {
            return self::fail(429, 'Trop de demandes envoyées depuis votre connexion. Réessayez plus tard ou appelez-nous.');
        }

        $row = $type === 'recherche' ? self::recherche_row($c) : self::estimation_row($c);
        $row += [
            'reference'            => ReferenceGenerator::generate($type),
            'type'                 => $type,
            'submission_key'       => $key,
            'source'               => wp_json_encode(Validator::source($in), JSON_UNESCAPED_UNICODE),
            'data_consent'         => $c['consentement'],
            'marketing_consent'    => $c['consentement_marketing'],
            'consent_text_version' => mb_substr((string) Config::get('consent_text_version'), 0, 20),
            'ip_hash'              => AntiSpam::ip_hash(),
        ];
        $lead = LeadRepository::create($row);
        if (!$lead) {
            return self::fail(500, 'Votre demande n\'a pas pu être enregistrée. Merci de réessayer ou de nous appeler.');
        }

        if (!empty($c['photos']) && $c['upload_token'] !== '') {
            $n = FileRepository::attach($c['upload_token'], $c['photos'], (int) $lead['id']);
            if ($n > 0) {
                LeadRepository::log_event((int) $lead['id'], 'photos_attached', ['count' => $n]);
            }
        }

        // PDF and e-mails hook in here; a failing listener must never fail the submission.
        try {
            do_action('kdv_lead_created', $lead);
        } catch (\Throwable $e) {
            error_log('[kdv-leads] kdv_lead_created listener failed: ' . $e->getMessage());
        }

        return new \WP_REST_Response(['success' => true, 'reference' => $lead['reference']], 201);
    }

    private static function estimation_row(array $c): array
    {
        $label = trim(implode(' ', array_filter([$c['marque'], $c['modele'], $c['finition']])));
        return [
            'full_name'      => $c['nom_complet'],
            'phone'          => $c['telephone'],
            'email'          => $c['email'] !== '' ? $c['email'] : null,
            'plate'          => $c['immatriculation'] !== '' ? $c['immatriculation'] : null,
            'vehicle_label'  => mb_substr($label, 0, 190),
            'vehicle_year'   => $c['annee_mise_en_circulation'],
            'mileage'        => $c['kilometrage'],
            'sale_timeframe' => $c['delai_vente'],
            'payload'        => wp_json_encode($c, JSON_UNESCAPED_UNICODE),
        ];
    }

    private static function recherche_row(array $c): array
    {
        $payload = $c;
        unset($payload['upload_token']); // a secret session id, not a customer answer
        return [
            'full_name'     => mb_substr($c['prenom'] . ' ' . $c['nom'], 0, 120),
            'first_name'    => $c['prenom'],
            'last_name'     => $c['nom'],
            'phone'         => $c['telephone'],
            'email'         => $c['email'],
            'postal_code'   => $c['code_postal'],
            'vehicle_label' => mb_substr(implode(', ', $c['marques_modeles']), 0, 190),
            'budget_min'    => $c['budget_min'],
            'budget_max'    => $c['budget_max'],
            'payload'       => wp_json_encode($payload, JSON_UNESCAPED_UNICODE),
        ];
    }
}
