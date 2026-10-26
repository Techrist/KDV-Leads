<?php
// Sandbox only: install WordPress, activate the shipped plugin, create test accounts.
$_SERVER['HTTP_HOST'] = '127.0.0.1:8099';
$_SERVER['REQUEST_URI'] = '/';
$_SERVER['SERVER_SOFTWARE'] = 'PHP built-in';
require __DIR__ . '/wp/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/upgrade.php';
require_once ABSPATH . 'wp-admin/includes/plugin.php';

if (!is_blog_installed()) {
    wp_install('KDV Sandbox', 'secadmin', 'admin@example.test', true, '', 'AdminPass-' . bin2hex(random_bytes(4)), 'fr_FR');
}
$accounts = [];
foreach (['secadmin' => 'administrator', 'secmanager' => 'kdv_gestionnaire', 'secsub' => 'subscriber', 'seceditor' => 'editor'] as $login => $role) {
    $pw = 'Pw-' . bin2hex(random_bytes(6));
    $u = get_user_by('login', $login);
    if (!$u && $login !== 'secadmin') {
        $id = wp_insert_user(['user_login' => $login, 'user_pass' => $pw, 'user_email' => "$login@example.test", 'role' => $role]);
    }
    if ($u || $login === 'secadmin') {
        $u = get_user_by('login', $login);
        wp_set_password($pw, $u->ID);
    }
    $accounts[] = "$login=$pw";
}
// activation creates tables, role, pages
$r = activate_plugin('kdv-leads/kdv-leads.php');
echo is_wp_error($r) ? 'ACTIVATION ERROR ' . $r->get_error_message() : 'plugin activated', "\n";
// roles created by activation: make sure the manager has it
$m = get_user_by('login', 'secmanager'); if ($m) { $m->set_role('kdv_gestionnaire'); }
update_option('permalink_structure', '/%postname%/');
flush_rewrite_rules(true);
file_put_contents(__DIR__ . '/accounts.txt', implode("\n", $accounts) . "\n");
echo 'tables: ', implode(',', $GLOBALS['wpdb']->get_col("SHOW TABLES LIKE '%kdv_%'")), "\n";
foreach (['vendre-mon-vehicule', 'recherche-personnalisee', 'estimation-merci'] as $slug) { echo $slug, ' => ', get_page_by_path($slug) ? 'page ok' : 'MISSING', "\n"; }
