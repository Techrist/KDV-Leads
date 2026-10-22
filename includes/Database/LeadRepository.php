<?php
namespace KdvLeads\Database;

defined('ABSPATH') || exit;

final class LeadRepository
{
    public const STATUS_NEW = 'nouveau';

    public static function find_by_submission_key(string $key): ?array
    {
        global $wpdb;
        $t = Schema::table('leads');
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM `$t` WHERE submission_key = %s", $key), ARRAY_A); // phpcs:ignore WordPress.DB
        return $row ?: null;
    }

    public static function find(int $id): ?array
    {
        global $wpdb;
        $t = Schema::table('leads');
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM `$t` WHERE id = %d", $id), ARRAY_A); // phpcs:ignore WordPress.DB
        return $row ?: null;
    }

    /**
     * Insert a lead. Returns the stored row, or null on failure.
     * A concurrent duplicate (same submission_key) returns the existing row.
     */
    public static function create(array $row): ?array
    {
        global $wpdb;
        $t = Schema::table('leads');
        $now = gmdate('Y-m-d H:i:s');
        $row += ['status' => self::STATUS_NEW, 'pdf_status' => 'pending', 'created_at' => $now, 'updated_at' => $now];

        // A duplicate submission_key is an expected race (double click): keep it out of the HTTP response.
        $previous = $wpdb->suppress_errors(true);
        $ok = $wpdb->insert($t, $row);
        $wpdb->suppress_errors($previous);
        if ($ok === false) {
            $existing = self::find_by_submission_key((string) ($row['submission_key'] ?? ''));
            if ($existing) {
                return $existing;
            }
            error_log('[kdv-leads] lead insert failed: ' . $wpdb->last_error);
            return null;
        }
        $id = (int) $wpdb->insert_id;
        self::log_event($id, 'created', ['type' => $row['type'] ?? '']);
        return self::find($id);
    }

    /**
     * List leads of one type.
     *
     * Default order: open statuses first, then sale urgency (asap → 3 mois → je me renseigne), then newest first.
     *
     * @param array{status?:string,delai?:string,from?:string,to?:string,s?:string,orderby?:string,order?:string,per_page?:int,page?:int} $args
     * @return array{items: array[], total: int}
     */
    public static function search(string $type, array $args = []): array
    {
        global $wpdb;
        $t = Schema::table('leads');
        [$where, $params] = self::where($type, $args);

        $per_page = max(1, min(200, (int) ($args['per_page'] ?? 20)));
        $offset = (max(1, (int) ($args['page'] ?? 1)) - 1) * $per_page;
        $dir = strtoupper((string) ($args['order'] ?? 'DESC')) === 'ASC' ? 'ASC' : 'DESC';

        $closed = "'" . implode("','", array_map('esc_sql', \KdvLeads\LeadStatuses::closed())) . "'";
        $orders = [
            'date'      => "created_at $dir",
            'reference' => "reference $dir",
            'km'        => "mileage $dir",
            'name'      => "full_name $dir",
        ];
        $orderby = $args['orderby'] ?? '';
        $order_sql = $orders[$orderby]
            ?? "(status IN ($closed)) ASC, CASE sale_timeframe WHEN 'asap' THEN 1 WHEN '1_mois' THEN 2 WHEN '3_mois' THEN 3 WHEN 'renseigne' THEN 4 ELSE 5 END ASC, created_at DESC";

        $total = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM `$t` WHERE $where", $params)); // phpcs:ignore WordPress.DB
        $items = (array) $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM `$t` WHERE $where ORDER BY $order_sql LIMIT %d OFFSET %d", // phpcs:ignore WordPress.DB
            array_merge($params, [$per_page, $offset])
        ), ARRAY_A);

        return ['items' => $items, 'total' => $total];
    }

    /** @return array{0:string,1:array} SQL fragment (prepared placeholders) and params */
    private static function where(string $type, array $args): array
    {
        global $wpdb;
        $w = ['type = %s'];
        $p = [$type];

        if (!empty($args['status'])) {
            $w[] = 'status = %s';
            $p[] = (string) $args['status'];
        }
        if (!empty($args['delai'])) {
            $w[] = 'sale_timeframe = %s';
            $p[] = (string) $args['delai'];
        }
        if (($args['callback'] ?? '') === 'due') {
            $closed = "'" . implode("','", array_map('esc_sql', \KdvLeads\LeadStatuses::closed())) . "'";
            $w[] = "(next_callback_at IS NOT NULL AND next_callback_at <= %s AND status NOT IN ($closed))";
            $p[] = gmdate('Y-m-d H:i:s');
        }
        if (($args['open'] ?? '') === '1') {
            $closed = "'" . implode("','", array_map('esc_sql', \KdvLeads\LeadStatuses::closed())) . "'";
            $w[] = "status NOT IN ($closed)";
        }
        // Dates are entered in Europe/Paris, stored in UTC.
        $paris = new \DateTimeZone('Europe/Paris');
        $utc = new \DateTimeZone('UTC');
        if (!empty($args['from']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $args['from'])) {
            $w[] = 'created_at >= %s';
            $p[] = (new \DateTimeImmutable($args['from'] . ' 00:00:00', $paris))->setTimezone($utc)->format('Y-m-d H:i:s');
        }
        if (!empty($args['to']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $args['to'])) {
            $w[] = 'created_at <= %s';
            $p[] = (new \DateTimeImmutable($args['to'] . ' 23:59:59', $paris))->setTimezone($utc)->format('Y-m-d H:i:s');
        }
        $s = trim((string) ($args['s'] ?? ''));
        if ($s !== '') {
            $like = '%' . $wpdb->esc_like($s) . '%';
            $parts = ['full_name LIKE %s', 'vehicle_label LIKE %s', 'email LIKE %s', 'plate LIKE %s', 'reference LIKE %s', 'phone LIKE %s'];
            $p = array_merge($p, [$like, $like, $like, $like, $like, $like]);
            $digits = ltrim((string) preg_replace('/\D/', '', $s), '0'); // "06 12 34" matches +33612…
            if (strlen($digits) >= 4) {
                $parts[] = 'phone LIKE %s';
                $p[] = '%' . $wpdb->esc_like($digits) . '%';
            }
            $w[] = '(' . implode(' OR ', $parts) . ')';
        }
        return [implode(' AND ', $w), $p];
    }

    /**
     * Figures for the dashboard cards.
     *
     * @return array{new:int, asap_open:int, callbacks_due:int, week:int, open:int}
     */
    public static function kpis(string $type): array
    {
        $count = static fn (array $args): int => (int) self::search($type, $args + ['per_page' => 1])['total'];
        return [
            'new'           => self::count_status($type, self::STATUS_NEW),
            'asap_open'     => $type === 'estimation' ? $count(['delai' => 'asap', 'open' => '1']) : 0,
            'callbacks_due' => $count(['callback' => 'due']),
            'week'          => $count(['from' => wp_date('Y-m-d', time() - 6 * DAY_IN_SECONDS)]),
            'open'          => $count(['open' => '1']),
        ];
    }

    public static function count_status(string $type, string $status): int
    {
        global $wpdb;
        $t = Schema::table('leads');
        return (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM `$t` WHERE type = %s AND status = %s", $type, $status)); // phpcs:ignore WordPress.DB
    }

    /** Change status and fire the integration hook. */
    public static function set_status(int $id, string $status, ?int $user_id = null): bool
    {
        $lead = self::find($id);
        if (!$lead || !array_key_exists($status, \KdvLeads\LeadStatuses::for_type($lead['type']))) {
            return false;
        }
        if ($lead['status'] === $status) {
            return true;
        }
        self::update($id, ['status' => $status]);
        self::log_event($id, 'status_changed', ['from' => $lead['status'], 'to' => $status], $user_id);
        do_action('kdv_lead_status_changed', self::find($id), $lead['status'], $status);
        return true;
    }

    public static function events(int $lead_id): array
    {
        global $wpdb;
        $t = Schema::table('lead_events');
        return (array) $wpdb->get_results($wpdb->prepare("SELECT * FROM `$t` WHERE lead_id = %d ORDER BY id DESC LIMIT 100", $lead_id), ARRAY_A); // phpcs:ignore WordPress.DB
    }

    /** @return array[] leads using this e-mail (case-insensitive) */
    public static function find_by_email(string $email): array
    {
        global $wpdb;
        $t = Schema::table('leads');
        return (array) $wpdb->get_results($wpdb->prepare("SELECT * FROM `$t` WHERE email = %s ORDER BY id ASC", $email), ARRAY_A); // phpcs:ignore WordPress.DB
    }

    /** Leads past the retention period (last activity), not yet anonymised. */
    public static function due_for_retention(int $months, int $limit = 100): array
    {
        global $wpdb;
        $t = Schema::table('leads');
        $cut = gmdate('Y-m-d H:i:s', (int) strtotime('-' . $months . ' months'));
        return (array) $wpdb->get_results($wpdb->prepare("SELECT id FROM `$t` WHERE updated_at < %s AND pdf_status <> 'erased' LIMIT %d", $cut, $limit), ARRAY_A); // phpcs:ignore WordPress.DB
    }

    public static function update(int $id, array $data): bool
    {
        global $wpdb;
        $data['updated_at'] = gmdate('Y-m-d H:i:s');
        return $wpdb->update(Schema::table('leads'), $data, ['id' => $id]) !== false;
    }

    public static function log_event(int $lead_id, string $event, array $data = [], ?int $user_id = null): void
    {
        global $wpdb;
        $wpdb->insert(Schema::table('lead_events'), [
            'lead_id'    => $lead_id,
            'user_id'    => $user_id,
            'event'      => $event,
            'data'       => wp_json_encode($data),
            'created_at' => gmdate('Y-m-d H:i:s'),
        ]);
    }
}
