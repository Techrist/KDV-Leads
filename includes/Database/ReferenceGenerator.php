<?php
namespace KdvLeads\Database;

defined('ABSPATH') || exit;

/** KDV-EST-AAAAMMJJ-NNNN / KDV-REC-AAAAMMJJ-NNNN, daily counter per type (Europe/Paris). */
final class ReferenceGenerator
{
    private const PREFIX = ['estimation' => 'EST', 'recherche' => 'REC'];

    public static function generate(string $type, ?\DateTimeInterface $now = null): string
    {
        global $wpdb;
        $code = self::PREFIX[$type] ?? 'EST';
        $now = $now ?? new \DateTimeImmutable('now', new \DateTimeZone('Europe/Paris'));
        $day = $now->setTimezone(new \DateTimeZone('Europe/Paris'))->format('Ymd');
        $table = Schema::table('counters');
        $name = $code . '-' . $day;

        // Atomic increment: concurrent submissions never get the same number.
        $wpdb->query($wpdb->prepare(
            "INSERT INTO `$table` (name, value) VALUES (%s, LAST_INSERT_ID(1)) ON DUPLICATE KEY UPDATE value = LAST_INSERT_ID(value + 1)", // phpcs:ignore WordPress.DB
            $name
        ));
        $n = (int) $wpdb->get_var('SELECT LAST_INSERT_ID()');

        return self::format($code, $day, $n);
    }

    public static function format(string $code, string $day, int $n): string
    {
        return sprintf('KDV-%s-%s-%04d', $code, $day, $n);
    }
}
