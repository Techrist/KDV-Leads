<?php
namespace KdvLeads\Database;

defined('ABSPATH') || exit;

/**
 * Atomic counters in kdv_counters (same table as the daily reference numbers).
 *
 * One INSERT … ON DUPLICATE KEY UPDATE increments and reads in a single statement, so concurrent
 * requests can never both pass a limit (unlike get_transient() followed by set_transient()).
 * Names end with a time bucket (…-YmdH or …-Ymd) so old rows can be purged.
 */
final class Counters
{
    /** Prefixes of the rate-limit rows (reference counters such as EST-20261022 are never touched). */
    private const PREFIXES = ['rl', 'ra', 'ft', 'us', 'uf', 'pl', 'ak'];

    /** Increment counter $name and return its new value (1 on first hit). */
    public static function hit(string $name): int
    {
        global $wpdb;
        $t = Schema::table('counters');
        $wpdb->query($wpdb->prepare(
            "INSERT INTO `$t` (name, value) VALUES (%s, LAST_INSERT_ID(1)) ON DUPLICATE KEY UPDATE value = LAST_INSERT_ID(value + 1)", // phpcs:ignore WordPress.DB
            substr($name, 0, 48)
        ));
        return (int) $wpdb->get_var('SELECT LAST_INSERT_ID()');
    }

    /** Hourly bucket name for a key, e.g. rl-<24 hex>-2026102611. */
    public static function hourly(string $prefix, string $key): string
    {
        return $prefix . '-' . substr(hash('sha256', $key), 0, 24) . '-' . gmdate('YmdH');
    }

    /** Daily bucket name for a key, e.g. ak-<24 hex>-20261026. */
    public static function daily(string $prefix, string $key): string
    {
        return $prefix . '-' . substr(hash('sha256', $key), 0, 24) . '-' . gmdate('Ymd');
    }

    /** Delete rate-limit rows whose time bucket is older than ~26 hours. */
    public static function purge(): void
    {
        global $wpdb;
        $t = Schema::table('counters');
        $cutoff = gmdate('YmdH', time() - 26 * HOUR_IN_SECONDS);
        $re = '^(' . implode('|', self::PREFIXES) . ')-';
        $wpdb->query($wpdb->prepare("DELETE FROM `$t` WHERE name REGEXP %s AND SUBSTRING_INDEX(name, '-', -1) < %s", $re, $cutoff)); // phpcs:ignore WordPress.DB
    }
}
