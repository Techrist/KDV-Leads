<?php
namespace KdvLeads;

defined('ABSPATH') || exit;

final class Activator
{
    public static function activate(): void
    {
        Database\Schema::install();
        self::register_role();
        Storage\PrivateStorage::ensure();
        self::create_pages();

        if (get_option(Config::OPTION) === false) {
            add_option(Config::OPTION, Config::defaults(), '', false);
        }
    }

    private static function register_role(): void
    {
        add_role(Config::ROLE, 'Gestionnaire KDV', [
            'read'                => true,
            Config::CAP_LEADS     => true,
        ]);

        $admin = get_role('administrator');
        if ($admin) {
            $admin->add_cap(Config::CAP_LEADS);
            $admin->add_cap(Config::CAP_SETTINGS);
        }
    }

    /** Public pages (§1). Existing pages are never overwritten. */
    private static function create_pages(): void
    {
        $pages = [
            ["vendre-mon-vehicule", "Vendre mon véhicule", "[kdv_estimation]"],
            ["estimation-merci", "Merci pour votre demande", "[kdv_estimation_merci]"],
            ["recherche-personnalisee", "Recherche personnalisée", "[kdv_recherche]"],
            ["recherche-merci", "Merci pour votre recherche", "[kdv_recherche_merci]"],
        ];
        foreach ($pages as [$slug, $title, $content]) {
            if (get_page_by_path($slug)) {
                continue;
            }
            wp_insert_post([
                "post_type"    => "page",
                "post_status"  => "publish",
                "post_name"    => $slug,
                "post_title"   => $title,
                "post_content" => $content,
            ]);
        }
    }
}
