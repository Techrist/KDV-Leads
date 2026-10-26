<?php
namespace KdvLeads\Mail;

use KdvLeads\Database\EmailQueueRepository;
use KdvLeads\Database\LeadRepository;

defined('ABSPATH') || exit;

final class QueueWorker
{
    public const HOOK = 'kdv_process_email_queue';
    public const ALERT_OPTION = 'kdv_mail_alert';

    public static function init(): void
    {
        add_filter('cron_schedules', static function (array $s): array {
            $s['kdv_five_minutes'] = ['interval' => 300, 'display' => 'Toutes les 5 minutes (KDV)'];
            return $s;
        });
        add_action(self::HOOK, [self::class, 'run']);
        add_action('admin_notices', [self::class, 'notice']);
        if (!wp_next_scheduled(self::HOOK)) {
            wp_schedule_event(time() + 60, 'kdv_five_minutes', self::HOOK);
        }
    }

    /** Cron entry point: process every due message. */
    public static function run(): void
    {
        update_option('kdv_worker_last_run', time(), false);
        if (get_transient('kdv_queue_lock')) {
            return;
        }
        set_transient('kdv_queue_lock', 1, 240);
        try {
            foreach (EmailQueueRepository::due(20) as $item) {
                self::attempt((int) $item['id']);
            }
        } finally {
            delete_transient('kdv_queue_lock');
        }
    }

    /** Try to send one queued message and record the outcome. */
    public static function attempt(int $id): bool
    {
        $item = EmailQueueRepository::find($id);
        if (!$item || !in_array($item['status'], ['pending', 'failed'], true)) {
            return false;
        }
        $error = Mailer::send_item($item);

        if ($error === '') {
            EmailQueueRepository::mark_sent($id);
            if ($item['lead_id']) {
                LeadRepository::log_event((int) $item['lead_id'], 'email_sent', ['kind' => $item['kind'], 'to' => $item['to_email']]);
            }
            if ($item['kind'] === 'admin_notification') {
                delete_option(self::ALERT_OPTION); // delivery works again
            }
            return true;
        }

        $first_failure = (int) $item['attempts'] === 0;
        $status = EmailQueueRepository::mark_failed($id, $error);
        if ($item['lead_id']) {
            LeadRepository::log_event((int) $item['lead_id'], 'email_failed', ['kind' => $item['kind'], 'status' => $status, 'error' => $error]);
        }
        error_log('[kdv-leads] e-mail #' . $id . ' (' . $item['kind'] . ') failed: ' . $error);

        if ($item['kind'] !== 'admin_alert') {
            if ($status === 'abandoned') {
                self::alert($item, 'abandonné après plusieurs tentatives', $error);
            } elseif ($first_failure && $item['kind'] === 'admin_notification') {
                self::alert($item, 'premier échec d\'envoi (nouvelle tentative automatique prévue)', $error);
            }
        }
        return false;
    }

    private static function alert(array $item, string $what, string $error): void
    {
        $lead = $item['lead_id'] ? LeadRepository::find((int) $item['lead_id']) : null;
        $ref = $lead['reference'] ?? '(sans dossier)';
        $msg = sprintf('E-mail « %s » du dossier %s : %s. Erreur : %s', $item['kind'], $ref, $what, $error);

        update_option(self::ALERT_OPTION, ['time' => time(), 'message' => $msg], false);

        EmailQueueRepository::enqueue([
            'lead_id'   => $item['lead_id'] ?: null,
            'kind'      => 'admin_alert',
            'to_email'  => Mailer::tech_admin(),
            'subject'   => '[KDV ALERTE] Problème d\'envoi d\'e-mail',
            'body_html' => '<p>' . esc_html($msg) . '</p><p>Le dossier est enregistré et consultable dans l\'administration WordPress (Dossiers KDV).</p>',
            'body_text' => $msg . "\nLe dossier est enregistré et consultable dans l'administration WordPress (Dossiers KDV).\n",
        ]);
    }

    public static function notice(): void
    {
        $alert = get_option(self::ALERT_OPTION);
        if (!$alert || !current_user_can(\KdvLeads\Config::CAP_LEADS)) {
            return;
        }
        echo '<div class="notice notice-error"><p><strong>KDV : problème d\'envoi d\'e-mail.</strong> '
            . esc_html(current_user_can(\KdvLeads\Config::CAP_SETTINGS) ? $alert['message'] : 'Un e-mail n\'a pas pu partir. Prévenez l\'administrateur du site.') . ' (' . esc_html(wp_date('d/m/Y H:i', (int) $alert['time'])) . ') '
            . '<a href="' . esc_url(admin_url('admin.php?page=kdv-diagnostic')) . '">Diagnostic</a></p></div>';
    }
}
