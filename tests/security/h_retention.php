<?php
// P18 + counters: old sent e-mail copies and stale rate-limit rows are purged by the daily retention task.
require __DIR__ . '/h_boot.php';
global $wpdb;
$q = $wpdb->prefix . 'kdv_email_queue'; $c = $wpdb->prefix . 'kdv_counters';
$mk = static function (string $sent, string $subject) use ($wpdb, $q) {
    $wpdb->insert($q, ['lead_id' => null, 'kind' => 'client_ack', 'to_email' => 'old@example.test', 'subject' => $subject, 'body_html' => 'x', 'body_text' => 'x',
        'status' => 'sent', 'attempts' => 1, 'sent_at' => $sent, 'created_at' => $sent]);
};
$mk(gmdate('Y-m-d H:i:s', time() - 40 * 86400), 'OLD-40d');
$mk(gmdate('Y-m-d H:i:s', time() - 10 * 86400), 'MID-10d');
$wpdb->query("INSERT INTO $c (name,value) VALUES ('rl-staleaaaaaaaaaaaaaaaaaaaa-2026010100', 3), ('ra-freshbbbbbbbbbbbbbbbbbbbb-" . gmdate('YmdH') . "', 2), ('EST-20260101', 9) ON DUPLICATE KEY UPDATE value=value");
\KdvLeads\Privacy\Privacy::retention();
$left = $wpdb->get_col("SELECT subject FROM $q WHERE subject IN ('OLD-40d','MID-10d')");
$cnt  = $wpdb->get_col("SELECT name FROM $c WHERE name IN ('rl-staleaaaaaaaaaaaaaaaaaaaa-2026010100','ra-freshbbbbbbbbbbbbbbbbbbbb-" . gmdate('YmdH') . "','EST-20260101')");
echo json_encode(['mails_restants' => $left, 'compteurs_restants' => $cnt]);
