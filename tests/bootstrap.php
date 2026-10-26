<?php
/**
 * PHPUnit bootstrap: unit tests run WITHOUT WordPress. Only the few WP helpers the tested classes
 * call are stubbed here. Database-dependent behaviour (list order, queue, REST) is covered by
 * tests/integration/recette.php, which runs inside a real WordPress.
 */

define('ABSPATH', __DIR__ . '/');
define('KDV_LEADS_DIR', dirname(__DIR__) . '/');
define('KDV_LEADS_URL', 'http://localhost/kdv-leads/');
define('KDV_LEADS_VERSION', 'test');
define('DAY_IN_SECONDS', 86400);

require_once KDV_LEADS_DIR . 'vendor/autoload.php';

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

if (!function_exists('get_option')) {
    function get_option($name, $default = false) { return $GLOBALS['kdv_test_options'][$name] ?? $default; }
}
if (!function_exists('sanitize_text_field')) {
    function sanitize_text_field($s) { return trim(preg_replace('/[\r\n\t ]+/', ' ', strip_tags((string) $s))); }
}
if (!function_exists('sanitize_textarea_field')) {
    function sanitize_textarea_field($s) { return trim(strip_tags((string) $s)); }
}
if (!function_exists('sanitize_email')) {
    function sanitize_email($s) { return strtolower(trim((string) $s)); }
}
if (!function_exists('is_email')) {
    function is_email($s) { return (bool) filter_var($s, FILTER_VALIDATE_EMAIL); }
}
if (!function_exists('esc_url_raw')) {
    function esc_url_raw($u, $protocols = null) { return preg_match('#^https?://#i', (string) $u) ? (string) $u : ''; }
}
if (!function_exists('wp_http_validate_url')) {
    function wp_http_validate_url($u) { return (bool) filter_var($u, FILTER_VALIDATE_URL); }
}
if (!function_exists('wp_date')) {
    function wp_date($format, $ts = null) { return date($format, $ts ?? time()); }
}
if (!function_exists('wp_strip_all_tags')) {
    function wp_strip_all_tags($s) { return strip_tags((string) $s); }
}
if (!function_exists('wp_json_encode')) {
    function wp_json_encode($d, $flags = 0) { return json_encode($d, $flags); }
}
if (!function_exists('esc_html')) {
    function esc_html($s) { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); }
}
if (!function_exists('esc_attr')) {
    function esc_attr($s) { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); }
}
if (!function_exists('esc_url')) {
    function esc_url($s) { return (string) $s; }
}
if (!function_exists('admin_url')) {
    function admin_url($p = '') { return 'http://localhost/wp-admin/' . $p; }
}
if (!function_exists('remove_accents')) {
    function remove_accents($s) {
        return strtr((string) $s, ['é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e', 'à' => 'a', 'â' => 'a', 'î' => 'i', 'ï' => 'i', 'ô' => 'o', 'ù' => 'u', 'û' => 'u', 'ç' => 'c',
            'É' => 'E', 'È' => 'E', 'À' => 'A', 'Ç' => 'C']);
    }
}

if (!defined('MB_IN_BYTES')) { define('MB_IN_BYTES', 1048576); }
if (!defined('HOUR_IN_SECONDS')) { define('HOUR_IN_SECONDS', 3600); }
if (!function_exists('wp_salt')) {
    function wp_salt($scheme = 'auth') { return 'unit-test-salt-' . $scheme; }
}
if (!function_exists('wp_convert_hr_to_bytes')) {
    function wp_convert_hr_to_bytes($v) {
        $v = strtolower(trim((string) $v)); $n = (int) $v;
        return match (substr($v, -1)) { 'g' => $n * 1073741824, 'm' => $n * 1048576, 'k' => $n * 1024, default => $n };
    }
}
if (!function_exists('wp_parse_url')) {
    function wp_parse_url($u) { return parse_url($u); }
}
