<?php
namespace KdvLeads;

defined('ABSPATH') || exit;

final class Plugin
{
    public static function boot(): void
    {
        // Run pending migrations (e.g. after a plugin update without re-activation).
        if (get_option('kdv_db_version') !== Config::DB_VERSION) {
            Database\Schema::install();
        }

        Security\Secrets::migrate(Config::OPTION);

        // Gestionnaires land on "Dossiers KDV" after login (§12.1).
        add_filter('login_redirect', static function ($to, $requested, $user) {
            if ($user instanceof \WP_User && in_array(Config::ROLE, (array) $user->roles, true)) {
                return admin_url('admin.php?page=kdv-leads');
            }
            return $to;
        }, 10, 3);

        Frontend\Shortcodes::init();
        Http\RestController::init();
        Http\SecurityHeaders::init();
        Http\UploadController::init();
        Pdf\PdfGenerator::init();
        Mail\Mailer::init();
        Mail\QueueWorker::init();
        Privacy\Privacy::init();

        if (is_admin()) {
            Admin\Menu::init();
        }
    }
}
