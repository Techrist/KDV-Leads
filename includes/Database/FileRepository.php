<?php
namespace KdvLeads\Database;

defined('ABSPATH') || exit;

/** kdv_lead_files + kdv_upload_tokens. Paths are relative to the private storage root. */
final class FileRepository
{
    public const TOKEN_TTL = DAY_IN_SECONDS;

    /* ---------- Upload sessions ---------- */

    public static function create_token(): string
    {
        global $wpdb;
        $token = bin2hex(random_bytes(32));
        $wpdb->insert(Schema::table('upload_tokens'), [
            'token'      => $token,
            'created_at' => gmdate('Y-m-d H:i:s'),
            'expires_at' => gmdate('Y-m-d H:i:s', time() + self::TOKEN_TTL),
            'file_count' => 0,
        ]);
        return $token;
    }

    /** @return array|null the token row if it exists and has not expired */
    public static function valid_token(string $token): ?array
    {
        global $wpdb;
        if (!preg_match('/^[0-9a-f]{64}$/', $token)) {
            return null;
        }
        $t = Schema::table('upload_tokens');
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM `$t` WHERE token = %s AND expires_at > %s", $token, gmdate('Y-m-d H:i:s')), ARRAY_A); // phpcs:ignore WordPress.DB
        return $row ?: null;
    }

    /**
     * Reserve one photo slot for a session, atomically (no check-then-insert race): returns false when the
     * session is full or expired. Release it with bump_token($token, -1) if the upload then fails.
     */
    public static function reserve_slot(string $token, int $max): bool
    {
        global $wpdb;
        $t = Schema::table('upload_tokens');
        $n = $wpdb->query($wpdb->prepare(
            "UPDATE `$t` SET file_count = file_count + 1 WHERE token = %s AND expires_at > %s AND file_count < %d", // phpcs:ignore WordPress.DB
            $token, gmdate('Y-m-d H:i:s'), $max
        ));
        return $n === 1;
    }

    public static function bump_token(string $token, int $delta): void
    {
        global $wpdb;
        $t = Schema::table('upload_tokens');
        $wpdb->query($wpdb->prepare("UPDATE `$t` SET file_count = GREATEST(0, CAST(file_count AS SIGNED) + %d) WHERE token = %s", $delta, $token)); // phpcs:ignore WordPress.DB
    }

    /* ---------- Files ---------- */

    public static function create(array $row): int
    {
        global $wpdb;
        $wpdb->insert(Schema::table('lead_files'), $row + ['created_at' => gmdate('Y-m-d H:i:s')]);
        return (int) $wpdb->insert_id;
    }

    public static function find(int $id): ?array
    {
        global $wpdb;
        $t = Schema::table('lead_files');
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM `$t` WHERE id = %d", $id), ARRAY_A); // phpcs:ignore WordPress.DB
        return $row ?: null;
    }

    public static function delete(int $id): void
    {
        global $wpdb;
        $wpdb->delete(Schema::table('lead_files'), ['id' => $id]);
    }

    /** Attach uploaded photos to a lead; only unattached files of the same token qualify. Returns attached count. */
    public static function attach(string $token, array $photos, int $lead_id): int
    {
        global $wpdb;
        $t = Schema::table('lead_files');
        $n = 0;
        foreach (array_slice($photos, 0, 5) as $i => $p) {
            $ok = $wpdb->query($wpdb->prepare(
                "UPDATE `$t` SET lead_id = %d, position = %d, angle = %s WHERE id = %d AND upload_token = %s AND lead_id IS NULL AND kind = 'photo'", // phpcs:ignore WordPress.DB
                $lead_id, $i, (string) ($p['angle'] ?? ''), (int) $p['file_id'], $token
            ));
            $n += $ok ? 1 : 0;
        }
        return $n;
    }

    /** @return array[] photos of a lead, in order */
    public static function for_lead(int $lead_id): array
    {
        global $wpdb;
        $t = Schema::table('lead_files');
        return (array) $wpdb->get_results($wpdb->prepare("SELECT * FROM `$t` WHERE lead_id = %d AND kind = 'photo' ORDER BY position ASC, id ASC", $lead_id), ARRAY_A); // phpcs:ignore WordPress.DB
    }

    /** @return array[] files never attached to a lead and older than $seconds */
    public static function orphans(int $seconds): array
    {
        global $wpdb;
        $t = Schema::table('lead_files');
        return (array) $wpdb->get_results($wpdb->prepare("SELECT * FROM `$t` WHERE lead_id IS NULL AND created_at < %s", gmdate('Y-m-d H:i:s', time() - $seconds)), ARRAY_A); // phpcs:ignore WordPress.DB
    }

    public static function purge_expired_tokens(): void
    {
        global $wpdb;
        $t = Schema::table('upload_tokens');
        $wpdb->query($wpdb->prepare("DELETE FROM `$t` WHERE expires_at < %s", gmdate('Y-m-d H:i:s', time() - DAY_IN_SECONDS))); // phpcs:ignore WordPress.DB
    }
}
