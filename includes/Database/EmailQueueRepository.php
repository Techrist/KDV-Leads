<?php
namespace KdvLeads\Database;

defined('ABSPATH') || exit;

final class EmailQueueRepository
{
    /** Delay (s) before retry number N+1 after failure N: 5 min, 15 min, 1 h, 3 h, 12 h, 24 h. */
    public const BACKOFF = [300, 900, 3600, 10800, 43200, 86400];

    /**
     * Seconds to wait before the next attempt after $failures failed attempts,
     * or null when the e-mail must be abandoned.
     */
    public static function backoff(int $failures): ?int
    {
        return self::BACKOFF[$failures - 1] ?? null;
    }

    public static function enqueue(array $row): int
    {
        global $wpdb;
        $now = gmdate('Y-m-d H:i:s');
        $wpdb->insert(Schema::table('email_queue'), $row + [
            'status'          => 'pending',
            'attempts'        => 0,
            'next_attempt_at' => $now,
            'created_at'      => $now,
        ]);
        return (int) $wpdb->insert_id;
    }

    public static function find(int $id): ?array
    {
        global $wpdb;
        $t = Schema::table('email_queue');
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM `$t` WHERE id = %d", $id), ARRAY_A); // phpcs:ignore WordPress.DB
        return $row ?: null;
    }

    /** @return array[] due items (pending or waiting for retry) */
    public static function due(int $limit = 20): array
    {
        global $wpdb;
        $t = Schema::table('email_queue');
        return (array) $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM `$t` WHERE status IN ('pending','failed') AND next_attempt_at <= %s ORDER BY id ASC LIMIT %d", // phpcs:ignore WordPress.DB
            gmdate('Y-m-d H:i:s'), $limit
        ), ARRAY_A);
    }

    public static function mark_sent(int $id): void
    {
        global $wpdb;
        $t = Schema::table('email_queue');
        $wpdb->query($wpdb->prepare("UPDATE `$t` SET status='sent', attempts = attempts + 1, sent_at = %s, last_error = NULL WHERE id = %d", gmdate('Y-m-d H:i:s'), $id)); // phpcs:ignore WordPress.DB
    }

    /**
     * Record a failed attempt. Returns the new status ('failed' = retry planned, 'abandoned').
     */
    public static function mark_failed(int $id, string $error): string
    {
        global $wpdb;
        $t = Schema::table('email_queue');
        $row = self::find($id);
        $failures = (int) ($row['attempts'] ?? 0) + 1;
        $delay = self::backoff($failures);
        $status = $delay === null ? 'abandoned' : 'failed';
        $next = gmdate('Y-m-d H:i:s', time() + ($delay ?? 0));
        $wpdb->update($t, [
            'status'          => $status,
            'attempts'        => $failures,
            'next_attempt_at' => $next,
            'last_error'      => mb_substr($error, 0, 1000),
        ], ['id' => $id]);
        return $status;
    }

    /** Manual resend from the lead page: back to the queue immediately. */
    public static function requeue(int $id): void
    {
        global $wpdb;
        $wpdb->update(Schema::table('email_queue'), [
            'status' => 'pending', 'attempts' => 0, 'next_attempt_at' => gmdate('Y-m-d H:i:s'),
        ], ['id' => $id]);
    }

    public static function count_problems(): int
    {
        global $wpdb;
        $t = Schema::table('email_queue');
        return (int) $wpdb->get_var("SELECT COUNT(*) FROM `$t` WHERE status IN ('failed','abandoned')"); // phpcs:ignore WordPress.DB
    }
}
