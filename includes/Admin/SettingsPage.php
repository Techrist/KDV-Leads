<?php
namespace KdvLeads\Admin;

use KdvLeads\Config;

defined('ABSPATH') || exit;

final class SettingsPage
{
    private const GROUP = 'kdv_settings_group';

    /** field key => [label, type, help] */
    private static function fields(): array
    {
        return [
            'Destinataires et e-mails' => [
                'recipients'       => ['Destinataire(s) KDV', 'text', 'Adresses séparées par des virgules.'],
                'from_email'       => ['Adresse expéditrice', 'email', 'Sur le domaine kdv-motors.com (SPF/DKIM/DMARC).'],
                'from_name'        => ['Nom expéditeur', 'text', ''],
                'email_logo_url'   => ['Adresse du logo dans les e-mails', 'url', 'Laisser vide pour utiliser le logo du plugin, visible dans les e-mails une fois le site en ligne. Pour un test depuis un site local, collez l\'adresse (https) d\'une image hébergée en ligne.'],
                'tech_admin_email' => ['E-mail administrateur technique', 'email', 'Reçoit les alertes d\'échec d\'envoi.'],
                'subject_priority_prefix' => ['Ajouter « 🔴 » à l\'objet si vente dès que possible', 'checkbox', ''],
            ],
            'Formulaire estimation' => [
                'finition_required'      => ['Finition obligatoire', 'checkbox', ''],
                'accept_foreign_phones'  => ['Accepter les numéros étrangers', 'checkbox', ''],
                'show_marketing_consent' => ['Afficher le consentement marketing', 'checkbox', 'Case distincte, facultative, non cochée.'],
                'photos_in_estimation'   => ['Étape photos facultative dans l\'estimation', 'checkbox', 'Réactive le module d\'upload (§8).'],
                'privacy_url'            => ['URL de la politique de confidentialité', 'url', ''],
                'consent_text_version'   => ['Version du texte de consentement', 'text', ''],
            ],
            'Anti-spam' => [
                'min_fill_seconds'     => ['Délai minimum de remplissage (s)', 'number', ''],
                'rate_limit_per_hour'  => ['Soumissions max. par IP et par heure', 'number', ''],
                'turnstile_site_key'   => ['Cloudflare Turnstile : clé de site', 'text', 'Laisser vide pour désactiver.'],
                'turnstile_secret_key' => ['Cloudflare Turnstile : clé secrète', 'password', ''],
            ],
            'Divers' => [
                'brand_red'         => ['Rouge de la marque', 'text', 'Valeur exacte du logo.'],
                'noindex_recherche' => ['Page recherche en noindex', 'checkbox', ''],
                'retention_months'  => ['Conservation des données (mois)', 'number', '36 mois = 3 ans'],
                'pdf_max_mb'        => ['Taille max. du PDF joint (Mo)', 'number', ''],
                'max_photo_mb'      => ['Taille max. par photo (Mo)', 'number', ''],
                'plate_api_key'     => ['Clé API immatriculation', 'password', 'Inactive tant que le champ est vide.'],
                'delete_data_on_uninstall' => ['Supprimer toutes les données à la désinstallation', 'checkbox', 'Dangereux : désactivé par défaut.'],
            ],
        ];
    }

    public static function register_settings(): void
    {
        register_setting(self::GROUP, Config::OPTION, [
            'type'              => 'array',
            'sanitize_callback' => [self::class, 'sanitize'],
            'default'           => Config::defaults(),
        ]);
    }

    public static function sanitize($input): array
    {
        $input = is_array($input) ? wp_unslash($input) : [];
        $current = Config::all();
        $out = $current;

        foreach (self::fields() as $group) {
            foreach ($group as $key => [$label, $type]) {
                $raw = $input[$key] ?? null;
                switch ($type) {
                    case 'checkbox':
                        $out[$key] = empty($raw) ? 0 : 1;
                        break;
                    case 'number':
                        $out[$key] = max(0, (int) $raw);
                        break;
                    case 'email':
                        $out[$key] = sanitize_email((string) $raw);
                        break;
                    case 'url':
                        $out[$key] = esc_url_raw((string) $raw);
                        break;
                    case 'password':
                        // Empty = keep the stored secret (the field is never pre-filled).
                        if ($raw !== null && $raw !== '') {
                            $out[$key] = sanitize_text_field((string) $raw);
                        }
                        break;
                    default:
                        $out[$key] = sanitize_text_field((string) $raw);
                }
            }
        }

        $color = (string) ($out['brand_red'] ?? '');
        $out['brand_red'] = preg_match('/^#[0-9A-Fa-f]{6}$/', $color) ? $color : $current['brand_red'];

        $faq = [];
        foreach ((array) ($input['faq'] ?? []) as $item) {
            $q = sanitize_text_field((string) ($item['q'] ?? ''));
            $a = sanitize_textarea_field((string) ($item['a'] ?? ''));
            if ($q !== '' || $a !== '') {
                $faq[] = ['q' => $q, 'a' => $a];
            }
        }
        $out['faq'] = $faq;

        return $out;
    }

    public static function render(): void
    {
        $s = Config::all();
        Chrome::open('Réglages', 'Destinataires, formulaire, anti-spam et FAQ');
        echo '<form method="post" action="options.php">';
        settings_fields(self::GROUP);

        foreach (self::fields() as $title => $group) {
            echo '<h2>' . esc_html($title) . '</h2><table class="form-table" role="presentation">';
            foreach ($group as $key => [$label, $type, $help]) {
                $name = Config::OPTION . '[' . $key . ']';
                $val = $s[$key] ?? '';
                echo '<tr><th scope="row"><label for="kdv-' . esc_attr($key) . '">' . esc_html($label) . '</label></th><td>';
                if ($type === 'checkbox') {
                    printf('<input type="checkbox" id="kdv-%s" name="%s" value="1" %s>', esc_attr($key), esc_attr($name), checked(!empty($val), true, false));
                } else {
                    printf('<input class="regular-text" type="%s" id="kdv-%s" name="%s" value="%s" %s>',
                        esc_attr($type), esc_attr($key), esc_attr($name),
                        $type === 'password' ? '' : esc_attr((string) $val),
                        $type === 'password' ? 'placeholder="' . ($val !== '' ? '(enregistrée)' : '') . '" autocomplete="new-password"' : '');
                }
                if ($help !== '') {
                    echo '<p class="description">' . esc_html($help) . '</p>';
                }
                echo '</td></tr>';
            }
            echo '</table>';
        }

        echo '<h2>Questions fréquentes (FAQ)</h2><p class="description">Textes validés par le client. Les mentions [À FOURNIR] sont à compléter.</p>';
        $faq = $s['faq'];
        $faq[] = ['q' => '', 'a' => ''];
        foreach ($faq as $i => $item) {
            printf(
                '<p><input class="large-text" type="text" name="%1$s[faq][%2$d][q]" value="%3$s" placeholder="Question"><br><textarea class="large-text" rows="3" name="%1$s[faq][%2$d][a]" placeholder="Réponse">%4$s</textarea></p>',
                esc_attr(Config::OPTION), (int) $i, esc_attr($item['q']), esc_textarea($item['a'])
            );
        }

        submit_button();
        echo '</form>';
        Chrome::close();
    }
}
