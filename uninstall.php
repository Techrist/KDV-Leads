<?php
/**
 * Uninstall: data is kept by default (leads must never be lost by accident).
 * Only the opt-in setting `delete_data_on_uninstall` triggers a purge.
 */

defined('WP_UNINSTALL_PLUGIN') || exit;

$settings = get_option('kdv_settings', []);
if (empty($settings['delete_data_on_uninstall'])) {
    return;
}

global $wpdb;
foreach (['kdv_leads', 'kdv_lead_files', 'kdv_email_queue', 'kdv_lead_events', 'kdv_counters', 'kdv_upload_tokens'] as $table) {
    $wpdb->query('DROP TABLE IF EXISTS `' . esc_sql($wpdb->prefix . $table) . '`'); // phpcs:ignore WordPress.DB
}
delete_option('kdv_settings');
delete_option('kdv_db_version');
delete_option('kdv_storage_dir');
remove_role('kdv_gestionnaire');
