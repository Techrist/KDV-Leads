<?php
namespace KdvLeads\Http;

use KdvLeads\Frontend\Shortcodes;

defined('ABSPATH') || exit;

/**
 * Security headers on the pages that carry a KDV form or confirmation.
 *
 * Deliberately NOT a full Content-Security-Policy: it would break the theme, analytics and consent banner of the site.
 * frame-ancestors + X-Frame-Options stop the form from being embedded in another site (clickjacking).
 */
final class SecurityHeaders
{
    public static function init(): void
    {
        add_action('template_redirect', [self::class, 'send'], 1);
    }

    public static function send(): void
    {
        if (headers_sent() || !is_singular()) {
            return;
        }
        $form = Shortcodes::page_has_shortcode('kdv_estimation', 'kdv_recherche');
        $thanks = Shortcodes::page_has_shortcode('kdv_estimation_merci', 'kdv_recherche_merci');
        if (!$form && !$thanks) {
            return;
        }
        header('X-Frame-Options: SAMEORIGIN');
        header("Content-Security-Policy: frame-ancestors 'self'");
        header('X-Content-Type-Options: nosniff');
        header('Referrer-Policy: strict-origin-when-cross-origin');
        header('Permissions-Policy: camera=(), microphone=(), geolocation=(), payment=(), usb=()');
        if ($form) {
            // The page embeds a signed token and a nonce: it must not be served stale by shared caches.
            nocache_headers();
        }
    }
}
