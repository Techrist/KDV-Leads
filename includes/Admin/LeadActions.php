<?php
namespace KdvLeads\Admin;

use KdvLeads\Config;
use KdvLeads\Database\EmailQueueRepository;
use KdvLeads\Database\LeadRepository;
use KdvLeads\Mail\Mailer;
use KdvLeads\Mail\QueueWorker;
use KdvLeads\Pdf\PdfGenerator;

defined('ABSPATH') || exit;

/** admin-post.php handlers for the lead screens. Every one checks capability and nonce. */
final class LeadActions
{
    private const ACTIONS = ['quick_status', 'lead_update', 'lead_note', 'regen_pdf', 'resend', 'download', 'image', 'export_csv', 'export_lead', 'anonymize'];

    public static function init(): void
    {
        foreach (self::ACTIONS as $a) {
            add_action('admin_post_kdv_' . $a, [self::class, 'handle_' . $a]);
        }
    }

    private static function guard(string $nonce_action): void
    {
        if (!current_user_can(Config::CAP_LEADS)) {
            wp_die('Accès refusé.', 403);
        }
        check_admin_referer($nonce_action);
    }

    private static function lead_id(): int
    {
        return isset($_REQUEST['lead']) ? (int) $_REQUEST['lead'] : 0; // phpcs:ignore WordPress.Security.NonceVerification
    }

    private static function back(array $lead, string $msg): void
    {
        $page = $lead['type'] === 'recherche' ? 'kdv-recherches' : 'kdv-leads';
        wp_safe_redirect(add_query_arg(['page' => $page, 'lead' => (int) $lead['id'], 'kdv_msg' => $msg], admin_url('admin.php')));
        exit;
    }

    private static function lead_or_die(): array
    {
        $lead = LeadRepository::find(self::lead_id());
        if (!$lead) {
            wp_die('Dossier introuvable.', 404);
        }
        return $lead;
    }

    public static function handle_quick_status(): void
    {
        self::guard('kdv_quick_status_' . self::lead_id());
        $lead = self::lead_or_die();
        $status = isset($_GET['status']) ? sanitize_key(wp_unslash($_GET['status'])) : '';
        LeadRepository::set_status((int) $lead['id'], $status, get_current_user_id());
        $page = isset($_GET['back']) ? sanitize_key(wp_unslash($_GET['back'])) : 'kdv-leads';
        wp_safe_redirect(admin_url('admin.php?page=' . $page));
        exit;
    }

    public static function handle_lead_update(): void
    {
        self::guard('kdv_lead_' . self::lead_id());
        $lead = self::lead_or_die();
        $id = (int) $lead['id'];

        $status = isset($_POST['status']) ? sanitize_key(wp_unslash($_POST['status'])) : $lead['status'];
        LeadRepository::set_status($id, $status, get_current_user_id());

        // Callback date: entered in Europe/Paris, stored in UTC.
        $raw = isset($_POST['next_callback_at']) ? sanitize_text_field(wp_unslash($_POST['next_callback_at'])) : '';
        $utc = null;
        if ($raw !== '') {
            try {
                $utc = (new \DateTimeImmutable($raw, new \DateTimeZone('Europe/Paris')))->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
            } catch (\Exception $e) {
                $utc = null;
            }
        }
        if ($utc !== $lead['next_callback_at']) {
            LeadRepository::update($id, ['next_callback_at' => $utc]);
            LeadRepository::log_event($id, 'callback_set', ['at' => $utc], get_current_user_id());
        }
        self::back($lead, 'saved');
    }

    public static function handle_lead_note(): void
    {
        self::guard('kdv_note_' . self::lead_id());
        $lead = self::lead_or_die();
        $text = isset($_POST['note']) ? sanitize_textarea_field(wp_unslash($_POST['note'])) : '';
        if ($text !== '') {
            $user = wp_get_current_user();
            $stamp = wp_date('d/m/Y H:i') . ' · ' . $user->display_name;
            $notes = '[' . $stamp . "]\n" . $text . "\n\n" . (string) $lead['internal_notes'];
            LeadRepository::update((int) $lead['id'], ['internal_notes' => $notes]);
            LeadRepository::log_event((int) $lead['id'], 'note_added', [], get_current_user_id());
        }
        self::back($lead, 'note');
    }

    public static function handle_regen_pdf(): void
    {
        self::guard('kdv_regen_' . self::lead_id());
        $lead = self::lead_or_die();
        $ok = PdfGenerator::generate_for_lead((int) $lead['id']);
        self::back($lead, $ok ? 'pdf_ok' : 'pdf_fail');
    }

    public static function handle_resend(): void
    {
        self::guard('kdv_resend_' . self::lead_id());
        $lead = self::lead_or_die();
        $queue_id = isset($_REQUEST['queue']) ? (int) $_REQUEST['queue'] : 0; // phpcs:ignore WordPress.Security.NonceVerification
        if ($queue_id) {
            $item = EmailQueueRepository::find($queue_id);
            if (!$item || (int) $item['lead_id'] !== (int) $lead['id']) {
                wp_die('E-mail introuvable.', 404);
            }
            EmailQueueRepository::requeue($queue_id);
            QueueWorker::attempt($queue_id);
        } else {
            Mailer::resend_notification($lead);
        }
        LeadRepository::log_event((int) $lead['id'], 'email_resent', ['queue' => $queue_id], get_current_user_id());
        self::back($lead, 'resent');
    }

    public static function handle_download(): void
    {
        self::guard('kdv_download_' . self::lead_id());
        FileDownloadController::pdf(self::lead_id());
    }

    public static function handle_image(): void
    {
        $file = isset($_GET['file']) ? (int) $_GET['file'] : 0;
        self::guard('kdv_image_' . $file);
        FileDownloadController::image($file, !empty($_GET['thumb']));
    }

    /** Data-subject access request answered from the lead page (works for phone-only requests). */
    public static function handle_export_lead(): void
    {
        self::guard('kdv_export_lead_' . self::lead_id());
        $lead = self::lead_or_die();
        LeadRepository::log_event((int) $lead['id'], 'data_exported', [], get_current_user_id());
        nocache_headers();
        header('Content-Type: application/json; charset=UTF-8');
        header('Content-Disposition: attachment; filename="' . $lead['reference'] . '_donnees.json"');
        header('X-Content-Type-Options: nosniff');
        echo wp_json_encode(\KdvLeads\Privacy\Privacy::lead_as_array($lead), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        exit;
    }

    public static function handle_anonymize(): void
    {
        self::guard('kdv_anonymize_' . self::lead_id());
        if (!current_user_can(Config::CAP_SETTINGS)) {
            wp_die('Seuls les administrateurs peuvent anonymiser un dossier.', 403);
        }
        $lead = self::lead_or_die();
        \KdvLeads\Privacy\Privacy::anonymize((int) $lead['id'], get_current_user_id());
        self::back($lead, 'anonymized');
    }

    public static function handle_export_csv(): void
    {
        self::guard('kdv_export_csv');
        $g = static fn ($k) => isset($_GET[$k]) ? sanitize_text_field(wp_unslash($_GET[$k])) : '';
        $type = $g('type') === 'recherche' ? 'recherche' : 'estimation';
        CsvExporter::stream($type, array_filter([
            'status' => $g('status'), 'delai' => $g('delai'), 'from' => $g('from'), 'to' => $g('to'), 's' => $g('s'),
        ]));
    }
}
