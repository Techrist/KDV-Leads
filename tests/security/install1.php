<?php
define('WP_INSTALLING', true);
$_SERVER['HTTP_HOST'] = '127.0.0.1:8099'; $_SERVER['REQUEST_URI'] = '/'; $_SERVER['SERVER_SOFTWARE'] = 'PHP built-in';
require __DIR__ . '/wp/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/upgrade.php';
if (!is_blog_installed()) { $r = wp_install('KDV Sandbox', 'secadmin', 'admin@example.test', true, '', 'Init-' . bin2hex(random_bytes(6)), 'fr_FR'); echo "installed user ", $r['user_id'], "\n"; } else { echo "already installed\n"; }
