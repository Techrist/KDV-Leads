<?php
namespace KdvLeads\Http;

use KdvLeads\Config;
use KdvLeads\Database\Counters;

defined('ABSPATH') || exit;

final class AntiSpam
{
    /** Attempts of any kind per IP and hour (valid or not): stops hammering of the public endpoint. */
    public const MAX_ATTEMPTS_PER_HOUR = 60;

    /**
     * Visitor IP. REMOTE_ADDR by default. Behind a proxy or CDN, REMOTE_ADDR is the proxy, so the
     * administrator can name the header to trust (Réglages → Anti-spam). Never enabled by default:
     * trusting these headers on a site that is NOT behind such a proxy would let anyone fake their IP.
     */
    public static function client_ip(): string
    {
        $remote = isset($_SERVER['REMOTE_ADDR']) ? (string) $_SERVER['REMOTE_ADDR'] : '';
        $ip = filter_var($remote, FILTER_VALIDATE_IP) ? $remote : '0.0.0.0';

        $mode = (string) Config::get('proxy_mode');
        $candidate = '';
        if ($mode === 'cloudflare' && !empty($_SERVER['HTTP_CF_CONNECTING_IP'])) {
            $candidate = trim((string) $_SERVER['HTTP_CF_CONNECTING_IP']);
        } elseif ($mode === 'forwarded' && !empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            // One trusted proxy appends the real client address as the LAST entry; earlier ones are client-supplied.
            $parts = array_map('trim', explode(',', (string) $_SERVER['HTTP_X_FORWARDED_FOR']));
            $candidate = (string) end($parts);
        }
        return ($candidate !== '' && filter_var($candidate, FILTER_VALIDATE_IP)) ? $candidate : $ip;
    }

    /** Salted hash of the client IP (never stored in clear). */
    public static function ip_hash(): string
    {
        return hash_hmac('sha256', self::client_ip(), wp_salt('auth'));
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

        // Signed form token: the elapsed time is computed by the server, not reported by the browser.
        if ($msg = FormToken::verify((string) ($in['form_token'] ?? ''))) {
            return $msg;
        }

        // Turnstile, only when configured.
        $secret = (string) Config::get('turnstile_secret_key');
        if ($secret !== '' && !self::turnstile_ok($secret, (string) ($in['cf-turnstile-response'] ?? ''))) {
            return 'La vérification anti-robot a échoué. Rechargez la page puis réessayez.';
        }

        return null;
    }

    /** True once an IP has made too many attempts this hour (atomic). Call first, before any database work. */
    public static function attempts_exceeded(): bool
    {
        return Counters::hit(Counters::hourly('ra', self::client_ip())) > self::MAX_ATTEMPTS_PER_HOUR;
    }

    /** Per-IP hourly limit on submissions that reached the save step (atomic). */
    public static function rate_limited(): bool
    {
        $limit = (int) Config::get('rate_limit_per_hour');
        if ($limit <= 0) {
            return false;
        }
        return Counters::hit(Counters::hourly('rl', self::client_ip())) > $limit;
    }

    private static function turnstile_ok(string $secret, string $token): bool
    {
        if ($token === '') {
            return false;
        }
        $res = wp_remote_post('https://challenges.cloudflare.com/turnstile/v0/siteverify', [
            'timeout' => 8,
            'body'    => ['secret' => $secret, 'response' => $token, 'remoteip' => self::client_ip()],
        ]);
        if (is_wp_error($res)) {
            // Fail open on purpose: a Cloudflare outage must not lose leads. The failure is logged.
            error_log('[kdv-leads] Turnstile unreachable, check skipped: ' . $res->get_error_message());
            return true;
        }
        $body = json_decode((string) wp_remote_retrieve_body($res), true);
        return !empty($body['success']);
    }
}
