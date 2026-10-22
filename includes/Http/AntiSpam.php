<?php
namespace KdvLeads\Http;

use KdvLeads\Config;

defined('ABSPATH') || exit;

final class AntiSpam
{
    /** Salted hash of the client IP (never stored in clear). */
    public static function ip_hash(): string
    {
        $ip = isset($_SERVER['REMOTE_ADDR']) ? (string) $_SERVER['REMOTE_ADDR'] : '';
        return hash_hmac('sha256', $ip, wp_salt('auth'));
    }

    /**
     * @return string|null error message when the submission must be refused
     */
    public static function check(array $in): ?string
    {
        // Honeypot: a human never fills this field.
        if (!empty($in['website'])) {
            return 'Votre demande n\'a pas pu être envoyée.';
        }

        // Minimum fill time.
        $min = (int) Config::get('min_fill_seconds');
        if ($min > 0 && (int) ($in['elapsed_ms'] ?? 0) < $min * 1000) {
            return 'Merci de prendre quelques secondes pour vérifier vos informations, puis de renvoyer votre demande.';
        }

        // Turnstile, only when configured.
        $secret = (string) Config::get('turnstile_secret_key');
        if ($secret !== '' && !self::turnstile_ok($secret, (string) ($in['cf-turnstile-response'] ?? ''))) {
            return 'La vérification anti-robot a échoué. Rechargez la page puis réessayez.';
        }

        return null;
    }

    /** Per-IP hourly limit. Counts attempts, not successes. */
    public static function rate_limited(): bool
    {
        $limit = (int) Config::get('rate_limit_per_hour');
        if ($limit <= 0) {
            return false;
        }
        $key = 'kdv_rl_' . substr(self::ip_hash(), 0, 32);
        $count = (int) get_transient($key);
        if ($count >= $limit) {
            return true;
        }
        set_transient($key, $count + 1, HOUR_IN_SECONDS);
        return false;
    }

    private static function turnstile_ok(string $secret, string $token): bool
    {
        if ($token === '') {
            return false;
        }
        $res = wp_remote_post('https://challenges.cloudflare.com/turnstile/v0/siteverify', [
            'timeout' => 8,
            'body'    => ['secret' => $secret, 'response' => $token, 'remoteip' => $_SERVER['REMOTE_ADDR'] ?? ''],
        ]);
        if (is_wp_error($res)) {
            return true; // Fail open: a Cloudflare outage must not lose leads.
        }
        $body = json_decode((string) wp_remote_retrieve_body($res), true);
        return !empty($body['success']);
    }
}
