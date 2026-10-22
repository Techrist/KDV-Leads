<?php
namespace KdvLeads\Http;

use KdvLeads\Config;
use KdvLeads\FormOptions as O;

defined('ABSPATH') || exit;

/** Server-side validation and cleaning. Never trusts the client. */
final class Validator
{
    /**
     * @param array $in raw decoded JSON body
     * @return array{0: array<string,string>, 1: array} [errors by field, clean data]
     */
    public static function estimation(array $in): array
    {
        $errors = [];
        $c = [];
        $text = static fn ($k, $max = 255) => mb_substr(trim(sanitize_text_field((string) ($in[$k] ?? ''))), 0, $max);
        $err = static function (string $field, string $msg) use (&$errors): void {
            $errors[$field] ??= $msg;
        };

        // Vehicle.
        $c['immatriculation'] = '';
        $plate = $text('immatriculation', 12);
        if ($plate !== '') {
            $norm = self::normalize_plate($plate);
            if ($norm === null) {
                $err('immatriculation', 'Format d\'immatriculation invalide (ex : AB-123-CD).');
            } else {
                $c['immatriculation'] = $norm;
            }
        }

        $vehicles = O::vehicles();
        $brand = $text('marque', 60);
        $model = $text('modele', 60);
        $c['marque'] = '';
        $c['modele'] = '';
        if ($brand === 'autre') {
            $label = $text('marque_autre', 60);
            if (mb_strlen($label) < 2) {
                $err('marque_autre', 'Précisez la marque.');
            }
            $other_model = $text('modele_autre', 60);
            if ($other_model === '') {
                $err('modele_autre', 'Précisez le modèle.');
            }
            $c['marque'] = $label;
            $c['modele'] = $other_model;
        } elseif (!isset($vehicles[$brand])) {
            $err('marque', 'Sélectionnez une marque.');
        } else {
            $c['marque'] = $brand;
            if ($model === 'autre') {
                $other_model = $text('modele_autre', 60);
                if ($other_model === '') {
                    $err('modele_autre', 'Précisez le modèle.');
                }
                $c['modele'] = $other_model;
            } elseif (!in_array($model, $vehicles[$brand], true)) {
                $err('modele', 'Sélectionnez un modèle.');
            } else {
                $c['modele'] = $model;
            }
        }

        $c['finition'] = $text('finition', 60);
        if ($c['finition'] === '' && Config::get('finition_required')) {
            $err('finition', 'Ce champ est obligatoire.');
        }

        $year = (int) ($in['annee_mise_en_circulation'] ?? 0);
        if ($year < 1970 || $year > (int) wp_date('Y')) {
            $err('annee_mise_en_circulation', 'Sélectionnez l\'année de mise en circulation.');
        }
        $c['annee_mise_en_circulation'] = $year;

        $km_raw = preg_replace('/\s+/u', '', (string) ($in['kilometrage'] ?? ''));
        if (!preg_match('/^\d{1,6}$/', (string) $km_raw)) {
            $err('kilometrage', 'Saisissez un kilométrage entre 0 et 999 999.');
            $c['kilometrage'] = 0;
        } else {
            $c['kilometrage'] = (int) $km_raw;
        }

        foreach ([
            'motorisation'    => [O::motorisations(), 'Sélectionnez une motorisation.'],
            'boite'           => [O::boites(), 'Sélectionnez une boîte de vitesses.'],
            'etat_general'    => [O::etat_general(), 'Veuillez faire un choix.'],
            'etat_habitacle'  => [O::etat_habitacle(), 'Veuillez faire un choix.'],
            'accident'        => [O::accident(), 'Veuillez faire un choix.'],
            'suivi_entretien' => [O::suivi_entretien(), 'Veuillez faire un choix.'],
            'delai_vente'     => [O::delais(), 'Veuillez indiquer votre délai de vente.'],
        ] as $field => [$allowed, $msg]) {
            $v = $text($field, 32);
            if (!array_key_exists($v, $allowed)) {
                $err($field, $msg);
                $v = '';
            }
            $c[$field] = $v;
        }

        // Equipment (optional).
        $eq = is_array($in['equipements'] ?? null) ? $in['equipements'] : [];
        $c['equipements'] = array_values(array_intersect(array_map('strval', $eq), array_keys(O::equipements())));
        $c['autre_equipement'] = $text('autre_equipement', 200);

        // Contact.
        $c['nom_complet'] = $text('nom_complet', 120);
        if (mb_strlen($c['nom_complet']) < 2) {
            $err('nom_complet', 'Indiquez votre nom et prénom.');
        }

        $phone = self::normalize_phone((string) ($in['telephone'] ?? ''), (bool) Config::get('accept_foreign_phones'));
        if ($phone === null) {
            $err('telephone', 'Saisissez un numéro de téléphone valide (ex : 06 12 34 56 78).');
        }
        $c['telephone'] = (string) $phone;

        $email = trim((string) ($in['email'] ?? ''));
        $c['email'] = '';
        if ($email !== '') {
            if (strlen($email) > 190 || !is_email($email)) {
                $err('email', 'Saisissez une adresse e-mail valide.');
            } else {
                $c['email'] = sanitize_email($email);
            }
        }

        // Consent.
        if (empty($in['consentement'])) {
            $err('consentement', 'Vous devez accepter pour continuer.');
        }
        $c['consentement'] = !empty($in['consentement']) ? 1 : 0;
        $c['consentement_marketing'] = (!empty($in['consentement_marketing']) && Config::get('show_marketing_consent')) ? 1 : 0;

        return [$errors, $c];
    }

    /** Clean a list of free-text entries (chips). */
    private static function text_list($raw, int $max_items, int $max_len): array
    {
        if (!is_array($raw)) {
            return [];
        }
        $out = [];
        foreach ($raw as $v) {
            $v = mb_substr(trim(sanitize_text_field((string) $v)), 0, $max_len);
            if ($v !== '' && !in_array(mb_strtolower($v), array_map('mb_strtolower', $out), true)) {
                $out[] = $v;
            }
            if (count($out) >= $max_items) {
                break;
            }
        }
        return $out;
    }

    /** Whitelisted multi-choice: keeps only known keys. */
    private static function choices($raw, array $allowed): array
    {
        $raw = is_array($raw) ? array_map('strval', $raw) : [];
        return array_values(array_intersect(array_keys($allowed), $raw));
    }

    /**
     * Custom search journey.
     *
     * @return array{0: array<string,string>, 1: array}
     */
    public static function recherche(array $in): array
    {
        $errors = [];
        $c = [];
        $text = static fn ($k, $max = 255) => mb_substr(trim(sanitize_text_field((string) ($in[$k] ?? ''))), 0, $max);
        $err = static function (string $field, string $msg) use (&$errors): void {
            $errors[$field] ??= $msg;
        };
        $int_or_null = static function ($v, int $min, int $max): ?int {
            $d = preg_replace('/\s+/u', '', (string) $v);
            return ($d !== '' && preg_match('/^\d{1,7}$/', $d) && (int) $d >= $min && (int) $d <= $max) ? (int) $d : null;
        };

        // Vehicle wanted.
        $c['marques_modeles'] = self::text_list($in['marques_modeles'] ?? [], 10, 80);
        if (!$c['marques_modeles']) {
            $err('marques_modeles', 'Indiquez au moins une marque ou un modèle.');
        }
        $c['versions'] = $text('versions', 200);

        $max_year = (int) wp_date('Y') + 1;
        $c['annee_min'] = $int_or_null($in['annee_min'] ?? '', 1970, $max_year);
        $c['annee_max'] = $int_or_null($in['annee_max'] ?? '', 1970, $max_year);
        if ($c['annee_min'] && $c['annee_max'] && $c['annee_min'] > $c['annee_max']) {
            $err('annee_max', 'L\'année maximum doit être postérieure à l\'année minimum.');
        }
        $km = trim((string) ($in['km_max'] ?? ''));
        $c['km_max'] = $int_or_null($km, 0, 999999);
        if ($km !== '' && $c['km_max'] === null) {
            $err('km_max', 'Saisissez un kilométrage entre 0 et 999 999.');
        }
        $c['carburant'] = self::choices($in['carburant'] ?? [], O::motorisations());
        $c['carrosserie'] = self::choices($in['carrosserie'] ?? [], O::carrosseries());
        $boite = $text('boite', 20);
        $c['boite'] = array_key_exists($boite, O::boites()) ? $boite : '';
        $c['couleurs_souhaitees'] = $text('couleurs_souhaitees', 150);
        $c['couleurs_exclues'] = $text('couleurs_exclues', 150);

        // Budget and options.
        $c['budget_min'] = $int_or_null($in['budget_min'] ?? '', 0, 9999999);
        $c['budget_max'] = $int_or_null($in['budget_max'] ?? '', 1, 9999999);
        if ($c['budget_max'] === null) {
            $err('budget_max', 'Indiquez votre budget maximum.');
        } elseif ($c['budget_min'] !== null && $c['budget_min'] > $c['budget_max']) {
            $err('budget_min', 'Le budget minimum doit être inférieur au budget maximum.');
        }
        $c['options_indispensables'] = self::text_list($in['options_indispensables'] ?? [], 15, 60);
        $c['options_souhaitables'] = self::text_list($in['options_souhaitables'] ?? [], 15, 60);
        $c['origine_acceptee'] = self::choices($in['origine_acceptee'] ?? [], O::origines());
        $flex = $text('flexibilite', 20);
        $c['flexibilite'] = array_key_exists($flex, O::flexibilites()) ? $flex : '';

        // Details.
        $date = $text('date_achat_envisagee', 20);
        $c['date_achat_envisagee'] = array_key_exists($date, O::delais()) ? $date : '';
        $c['localisation'] = $text('localisation', 100);
        $reprise = $text('reprise', 5);
        $c['reprise'] = in_array($reprise, ['oui', 'non'], true) ? $reprise : '';
        $c['commentaire'] = mb_substr(trim(sanitize_textarea_field((string) ($in['commentaire'] ?? ''))), 0, 2000);

        $links = is_array($in['liens_annonces'] ?? null) ? $in['liens_annonces'] : [];
        $clean_links = [];
        foreach ($links as $l) {
            $l = trim((string) $l);
            if ($l === '') {
                continue;
            }
            $url = esc_url_raw($l, ['http', 'https']);
            if ($url === '' || strlen($url) > 300 || !wp_http_validate_url($url)) {
                $err('liens_annonces', 'Chaque lien doit commencer par http:// ou https://.');
                continue;
            }
            $clean_links[] = $url;
        }
        if (count($clean_links) > 5) {
            $err('liens_annonces', 'Cinq liens maximum.');
        }
        $c['liens_annonces'] = array_slice($clean_links, 0, 5);

        // Contact.
        $c['prenom'] = $text('prenom', 100);
        $c['nom'] = $text('nom', 100);
        if (mb_strlen($c['prenom']) < 2) {
            $err('prenom', 'Indiquez votre prénom.');
        }
        if (mb_strlen($c['nom']) < 2) {
            $err('nom', 'Indiquez votre nom.');
        }
        $phone = self::normalize_phone((string) ($in['telephone'] ?? ''), (bool) Config::get('accept_foreign_phones'));
        if ($phone === null) {
            $err('telephone', 'Saisissez un numéro de téléphone valide (ex : 06 12 34 56 78).');
        }
        $c['telephone'] = (string) $phone;

        $email = trim((string) ($in['email'] ?? ''));
        if ($email === '' || strlen($email) > 190 || !is_email($email)) {
            $err('email', 'Saisissez une adresse e-mail valide.');
            $c['email'] = '';
        } else {
            $c['email'] = sanitize_email($email);
        }
        $cp = $text('code_postal', 10);
        if (!preg_match('/^\d{5}$/', $cp)) {
            $err('code_postal', 'Saisissez un code postal à 5 chiffres.');
        }
        $c['code_postal'] = $cp;
        $creneau = $text('creneau_rappel', 20);
        $c['creneau_rappel'] = array_key_exists($creneau, O::creneaux()) ? $creneau : '';

        if (empty($in['consentement'])) {
            $err('consentement', 'Vous devez accepter pour continuer.');
        }
        $c['consentement'] = !empty($in['consentement']) ? 1 : 0;
        $c['consentement_marketing'] = (!empty($in['consentement_marketing']) && Config::get('show_marketing_consent')) ? 1 : 0;

        // Photos are validated against the upload token when the lead is saved (RestController).
        $photos = [];
        foreach ((array) ($in['photos'] ?? []) as $p) {
            if (is_array($p) && isset($p['file_id']) && (int) $p['file_id'] > 0) {
                $photos[] = ['file_id' => (int) $p['file_id'], 'angle' => mb_substr(sanitize_text_field((string) ($p['angle'] ?? '')), 0, 32)];
            }
        }
        $c['photos'] = array_slice($photos, 0, 5);
        $c['upload_token'] = preg_match('/^[0-9a-f]{64}$/', (string) ($in['upload_token'] ?? '')) ? (string) $in['upload_token'] : '';

        return [$errors, $c];
    }

    /** SIV (AA-123-AA) or FNI (123-ABC-45) → canonical upper-case, or null. */
    public static function normalize_plate(string $raw): ?string
    {
        $s = strtoupper(preg_replace('/[\s\-]/', '', $raw));
        if (preg_match('/^([A-HJ-NP-TV-Z]{2})(\d{3})([A-HJ-NP-TV-Z]{2})$/', $s, $m)) {
            return $m[1] . '-' . $m[2] . '-' . $m[3];
        }
        if (preg_match('/^(\d{1,4})([A-Z]{1,3})(\d{2,3})$/', $s, $m)) {
            return $m[1] . '-' . $m[2] . '-' . $m[3];
        }
        return null;
    }

    /** French numbers → E.164 (+33…); foreign numbers only with an explicit international prefix. */
    public static function normalize_phone(string $raw, bool $accept_foreign): ?string
    {
        $s = preg_replace('/[\s.\-()]/', '', $raw);
        if (preg_match('/^(?:0|\+33|0033)([1-9]\d{8})$/', $s, $m)) {
            return '+33' . $m[1];
        }
        if ($accept_foreign && preg_match('/^(?:\+|00)([1-9]\d{7,14})$/', $s, $m)) {
            return '+' . $m[1];
        }
        return null;
    }

    /** Whitelist of marketing source keys. */
    public static function source(array $in): array
    {
        $s = is_array($in['source'] ?? null) ? $in['source'] : [];
        $out = [];
        foreach (['utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'referrer', 'landing'] as $k) {
            $out[$k] = mb_substr(sanitize_text_field((string) ($s[$k] ?? '')), 0, 255);
        }
        $out['gclid'] = empty($s['gclid']) ? 0 : 1;
        $out['fbclid'] = empty($s['fbclid']) ? 0 : 1;
        return $out;
    }
}
