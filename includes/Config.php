<?php
namespace KdvLeads;

defined('ABSPATH') || exit;

/**
 * Central place for configurable values. Items still undecided in the spec
 * use the default below until KDV decides; they are overridable in Réglages.
 */
final class Config
{
    public const OPTION = 'kdv_settings';
    public const DB_VERSION = '1';
    public const CAP_LEADS = 'kdv_manage_leads';
    public const CAP_SETTINGS = 'kdv_manage_settings';
    public const ROLE = 'kdv_gestionnaire';

    public static function defaults(): array
    {
        return [
            'email_logo_url'          => '',          // public URL of the logo for e-mails (empty = plugin file)
            'brand_red'               => '#D10A11',   // exact logo red
            'recipients'              => '',          // KDV recipient(s), comma separated
            'from_email'              => '',          // e.g. contact@kdv-motors.com
            'from_name'               => 'KDV Motors',
            'tech_admin_email'        => '',
            'subject_priority_prefix' => 1,           // "🔴 " prefix in the subject
            'show_marketing_consent'  => 0,
            'accept_foreign_phones'   => 1,
            'finition_required'       => 0,
            'photos_in_estimation'    => 0,           // §8, module reactivable
            'noindex_recherche'       => 1,
            'retention_months'        => 36,          // 3 ans
            'min_fill_seconds'        => 8,
            'rate_limit_per_hour'     => 5,
            'turnstile_site_key'      => '',
            'turnstile_secret_key'    => '',
            'plate_api_key'           => '',          // P1, inactive tant que vide
            'pdf_max_mb'              => 7,
            'max_photo_mb'            => 10,
            'privacy_url'             => '',
            'consent_text_version'    => '1.0',
            'delete_data_on_uninstall' => 0,
            'faq'                     => self::default_faq(),
        ];
    }

    /** Verbatim client texts (§6.7). Q5 end and Q6 are still missing. */
    public static function default_faq(): array
    {
        return [
            [
                'q' => "L'estimation de mon véhicule est-elle gratuite ?",
                'a' => "Oui, l'estimation de votre véhicule est 100 % gratuite et sans engagement. Vous êtes libre de donner suite ou non à notre proposition, sans aucune obligation de vente.",
            ],
            [
                'q' => 'Le prix estimé est-il le prix de vente définitif ?',
                'a' => "Non, l'estimation constitue une première indication de la valeur de votre véhicule. Son état général, son historique d'entretien et ses caractéristiques seront examinés avec votre conseiller KDV afin de définir ensemble un prix de mise en vente adapté au marché.",
            ],
            [
                'q' => 'Quels sont les frais pour vendre mon véhicule avec KDV ?',
                'a' => 'Aucun frais à prévoir ! Nous définissons ensemble un prix net vendeur, qui correspond au montant exact que vous recevrez après la vente de votre véhicule. Aucun frais caché, aucune mauvaise surprise.',
            ],
            [
                'q' => 'Puis-je continuer à utiliser mon véhicule pendant sa mise en vente ?',
                'a' => 'Oui ! Chez KDV, vous avez le choix : conserver votre véhicule et continuer à l\'utiliser, ou nous le confier directement dans nos locaux pour sa mise en vente.',
            ],
            [
                'q' => 'Pourquoi confier la vente de mon véhicule à KDV plutôt que de le vendre moi-même ?',
                'a' => 'Avec KDV, vendez votre véhicule en toute sérénité ! Nous nous occupons de tout : annonces, gestion des acheteurs, démarches administratives et sécurisation du paiement. Vous gagnez du temps, évitez les contraintes… [À FOURNIR : fin du texte]',
            ],
            [
                'q' => '[À FOURNIR : question n°6]',
                'a' => '[À FOURNIR : réponse n°6]',
            ],
        ];
    }

    public static function all(): array
    {
        $saved = get_option(self::OPTION, []);
        return array_merge(self::defaults(), is_array($saved) ? $saved : []);
    }

    /** @return mixed */
    public static function get(string $key)
    {
        return self::all()[$key] ?? null;
    }
}
