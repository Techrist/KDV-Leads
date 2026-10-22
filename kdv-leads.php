<?php
/**
 * Plugin Name:       KDV Leads
 * Description:       Demandes d'estimation (dépôt-vente) et recherches personnalisées pour KDV Motors.
 * Version:           0.1.0
 * Requires PHP:      8.1
 * Requires at least: 6.0
 * Author:            KDV Motors
 * Text Domain:       kdv-leads
 */

defined('ABSPATH') || exit;

define('KDV_LEADS_VERSION', '0.1.0');
define('KDV_LEADS_FILE', __FILE__);
define('KDV_LEADS_DIR', plugin_dir_path(__FILE__));
define('KDV_LEADS_URL', plugin_dir_url(__FILE__));

if (version_compare(PHP_VERSION, '8.1', '<')) {
    add_action('admin_notices', static function () {
        echo '<div class="notice notice-error"><p>'
            . esc_html('KDV Leads nécessite PHP 8.1 ou supérieur.')
            . '</p></div>';
    });
    return;
}

if (is_readable(KDV_LEADS_DIR . 'vendor/autoload.php')) {
    require_once KDV_LEADS_DIR . 'vendor/autoload.php';
}

spl_autoload_register(static function (string $class): void {
    $prefix = 'KdvLeads\\';
    if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
        return;
    }
    $file = KDV_LEADS_DIR . 'includes/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (is_readable($file)) {
        require_once $file;
    }
});

register_activation_hook(__FILE__, ['KdvLeads\\Activator', 'activate']);

register_deactivation_hook(__FILE__, static function () {
    wp_clear_scheduled_hook('kdv_process_email_queue');
    wp_clear_scheduled_hook('kdv_cleanup_uploads');
    wp_clear_scheduled_hook('kdv_retention_cleanup');
});

add_action('plugins_loaded', ['KdvLeads\\Plugin', 'boot']);
