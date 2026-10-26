<?php
namespace KdvLeads\Privacy;

use KdvLeads\Config;
use KdvLeads\Database\FileRepository;
use KdvLeads\Database\LeadRepository;
use KdvLeads\Database\Schema;
use KdvLeads\LeadStatuses;
use KdvLeads\Mail\MessageBuilder;
use KdvLeads\Storage\ImageProcessor;
use KdvLeads\Storage\PrivateStorage;

defined('ABSPATH') || exit;

/**
 * GDPR: WordPress export/erase tools (by e-mail), per-lead export/anonymisation (works for phone-only
 * requests), and the retention cron. Anonymised leads keep reference, vehicle and dates for statistics.
 */
final class Privacy
{
    public const CRON_HOOK = 'kdv_retention_cleanup';

    /** Payload keys that identify or may describe the person. */
    private const PERSONAL_KEYS = ['nom_complet', 'telephone', 'email', 'prenom', 'nom', 'code_postal', 'commentaire', 'localisation', 'autre_equipement'];

    public static function init(): void
    {
        add_filter('wp_privacy_personal_data_exporters', static function (array $e): array {
            $e['kdv-leads'] = ['exporter_friendly_name' => 'Dossiers KDV Motors', 'callback' => [self::class, 'export']];
            return $e;
        });
        add_filter('wp_privacy_personal_data_erasers', static function (array $e): array {
            $e['kdv-leads'] = ['eraser_friendly_name' => 'Dossiers KDV Motors', 'callback' => [self::class, 'erase']];
            return $e;
        });
        add_action('admin_init', [self::class, 'policy_text']);
        add_action(self::CRON_HOOK, [self::class, 'retention']);
        if (!wp_next_scheduled(self::CRON_HOOK)) {
            wp_schedule_event(time() + 600, 'daily', self::CRON_HOOK);
        }
    }

    public static function policy_text(): void
    {
        if (!function_exists('wp_add_privacy_policy_content')) {
            return;
        }
        $months = (int) Config::get('retention_months');
        wp_add_privacy_policy_content('KDV Leads', wp_kses_post(
            '<h2>Demandes d\'estimation et recherches personnalisées</h2>'
            . '<p>Les informations saisies (coordonnées, véhicule, projet) servent uniquement à vous recontacter dans le cadre de votre demande. '
            . 'Elles sont transmises à KDV Motors et à nos prestataires techniques (hébergeur, service d\'envoi d\'e-mails). '
            . 'Elles sont conservées ' . ($months > 0 ? $months . ' mois après le dernier contact' : 'selon la durée définie par KDV Motors') . ', puis anonymisées. '
            . 'Vous pouvez demander l\'accès, la rectification ou l\'effacement de vos données auprès de KDV Motors.</p>'
        ));
    }

    /** Complete, human-readable copy of one lead (also used by the per-lead JSON export). */
    public static function lead_as_array(array $lead): array
    {
        $paris = new \DateTimeZone('Europe/Paris');
        $fmt = static fn ($utc) => $utc ? (new \DateTimeImmutable((string) $utc, new \DateTimeZone('UTC')))->setTimezone($paris)->format('d/m/Y H:i') : '';
        $out = [
            'Référence' => $lead['reference'],
            'Type' => $lead['type'] === 'recherche' ? 'Recherche personnalisée' : 'Estimation',
            'Date de la demande' => $fmt($lead['created_at']),
            'Statut' => LeadStatuses::label($lead['type'], $lead['status']),
            'Nom' => $lead['full_name'],
            'Téléphone' => $lead['phone'],
            'E-mail' => (string) $lead['email'],
            'Code postal' => (string) $lead['postal_code'],
            'Immatriculation' => (string) $lead['plate'],
            'Véhicule' => $lead['vehicle_label'],
            'Consentement au recontact' => $lead['data_consent'] ? 'Oui (texte v' . $lead['consent_text_version'] . ')' : 'Non',
            'Consentement marketing' => $lead['marketing_consent'] ? 'Oui' : 'Non',
            'Notes internes' => (string) $lead['internal_notes'],
            'Rappel prévu' => $fmt($lead['next_callback_at']),
        ];
        foreach (MessageBuilder::sections($lead) as $title => $rows) {
            if ($title === 'Demande') {
                continue;
            }
            foreach ($rows as $label => $value) {
                $out[$title . ' : ' . $label] = $value;
            }
        }
        $out['Photos'] = count(FileRepository::for_lead((int) $lead['id']));
        return array_filter($out, static fn ($v) => $v !== '' && $v !== null);
    }

    /** WordPress personal-data exporter (matched on e-mail address). */
    public static function export(string $email, int $page = 1): array
    {
        $items = [];
        foreach (LeadRepository::find_by_email($email) as $lead) {
            $data = [];
            foreach (self::lead_as_array($lead) as $name => $value) {
                $data[] = ['name' => (string) $name, 'value' => (string) $value];
            }
            $items[] = ['group_id' => 'kdv-leads', 'group_label' => 'Demandes KDV Motors', 'group_description' => 'Demandes d\'estimation et de recherche', 'item_id' => 'kdv-lead-' . $lead['id'], 'data' => $data];
        }
        return ['data' => $items, 'done' => true];
    }

    /** WordPress personal-data eraser (matched on e-mail address). */
    public static function erase(string $email, int $page = 1): array
    {
        $removed = false;
        foreach (LeadRepository::find_by_email($email) as $lead) {
            if ($lead['pdf_status'] !== 'erased') {
                self::anonymize((int) $lead['id']);
                $removed = true;
            }
        }
        return ['items_removed' => $removed, 'items_retained' => false, 'messages' => [], 'done' => true];
    }

    /**
     * Irreversibly strip personal data from a lead: contact fields, personal answers, notes,
     * PDF, photos, queued e-mail copies and e-mail addresses in the history.
     */
    public static function anonymize(int $lead_id, ?int $user_id = null): bool
    {
        global $wpdb;
        $lead = LeadRepository::find($lead_id);
        if (!$lead) {
            return false;
        }

        foreach (FileRepository::for_lead($lead_id) as $f) {
            ImageProcessor::delete((string) $f['path']);
            FileRepository::delete((int) $f['id']);
        }
        if (!empty($lead['pdf_path'])) {
            PrivateStorage::delete((string) $lead['pdf_path']);
        }
        $wpdb->delete(Schema::table('email_queue'), ['lead_id' => $lead_id]);
        $wpdb->update(Schema::table('lead_events'), ['data' => null], ['lead_id' => $lead_id]);

        $payload = json_decode((string) $lead['payload'], true) ?: [];
        foreach (self::PERSONAL_KEYS as $k) {
            unset($payload[$k]);
        }
        $source = json_decode((string) $lead['source'], true) ?: [];
        unset($source['referrer'], $source['landing']);

        LeadRepository::update($lead_id, [
            'full_name'        => 'Anonymisé',
            'first_name'       => null,
            'last_name'        => null,
            'phone'            => '',
            'email'            => null,
            'postal_code'      => null,
            'plate'            => null,
            'ip_hash'          => '',
            'internal_notes'   => null,
            'next_callback_at' => null,
            'pdf_path'         => null,
            'pdf_status'       => 'erased',
            'payload'          => wp_json_encode($payload, JSON_UNESCAPED_UNICODE),
            'source'           => wp_json_encode($source, JSON_UNESCAPED_UNICODE),
        ]);
        LeadRepository::log_event($lead_id, 'anonymized', [], $user_id);
        return true;
    }

    /** Daily cron: anonymise leads whose last activity is older than the retention period. */
    public static function retention(): void
    {
        // Lead anonymisation follows the configured retention (0 = never anonymise automatically).
        $months = (int) Config::get('retention_months');
        if ($months > 0) {
            foreach (LeadRepository::due_for_retention($months) as $row) {
                self::anonymize((int) $row['id']);
            }
        }
        // Whatever the retention setting: mail copies carry personal data and rate-limit rows are only useful for hours.
        // Sent messages are not kept beyond 30 days.
        global $wpdb;
        \KdvLeads\Database\Counters::purge();
        $q = Schema::table('email_queue');
        $wpdb->query($wpdb->prepare("DELETE FROM `$q` WHERE status = 'sent' AND sent_at < %s", gmdate('Y-m-d H:i:s', time() - 30 * DAY_IN_SECONDS))); // phpcs:ignore WordPress.DB
    }
}
