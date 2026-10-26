<?php
namespace KdvLeads\Security;

defined('ABSPATH') || exit;

/**
 * Encrypts secrets (Turnstile secret key, registration-API key) before they are stored in wp_options,
 * so a leaked database dump or backup does not expose them. AES-256-GCM, key derived from the site's
 * secret salt (wp-config.php). If the salts are ever changed, the secrets simply have to be typed again.
 *
 * Legacy plain-text values are still read, and are encrypted at the next save (or by migrate()).
 */
final class Secrets
{
    private const PREFIX = 'enc1:';

    /** Option keys holding secrets. */
    public const KEYS = ['turnstile_secret_key', 'plate_api_key'];

    private static function key(): string
    {
        return hash('sha256', 'kdv-secrets|' . wp_salt('auth'), true);
    }

    public static function encrypt(string $plain): string
    {
        if ($plain === '' || !function_exists('openssl_encrypt') || strpos($plain, self::PREFIX) === 0) {
            return $plain;
        }
        $iv = random_bytes(12);
        $tag = '';
        $ct = openssl_encrypt($plain, 'aes-256-gcm', self::key(), OPENSSL_RAW_DATA, $iv, $tag);
        return $ct === false ? $plain : self::PREFIX . base64_encode($iv . $tag . $ct);
    }

    public static function decrypt(string $stored): string
    {
        if (strpos($stored, self::PREFIX) !== 0) {
            return $stored;   // legacy plain text (or empty)
        }
        $raw = base64_decode(substr($stored, strlen(self::PREFIX)), true);
        if ($raw === false || strlen($raw) < 29 || !function_exists('openssl_decrypt')) {
            return '';
        }
        $plain = openssl_decrypt(substr($raw, 28), 'aes-256-gcm', self::key(), OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, 12, 16));
        return $plain === false ? '' : $plain;
    }

    /** One-time encryption of secrets saved in clear by an earlier version. */
    public static function migrate(string $option): void
    {
        if (get_option('kdv_secrets_v1')) {
            return;
        }
        $opts = get_option($option);
        if (is_array($opts)) {
            foreach (self::KEYS as $k) {
                if (!empty($opts[$k]) && is_string($opts[$k])) {
                    $opts[$k] = self::encrypt($opts[$k]);
                }
            }
            update_option($option, $opts, false);
        }
        update_option('kdv_secrets_v1', 1, false);
    }
}
