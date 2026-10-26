<?php
namespace KdvLeads\Mail;

use KdvLeads\Config;
use KdvLeads\Database\Counters;
use KdvLeads\Database\EmailQueueRepository;
use KdvLeads\Database\LeadRepository;
use KdvLeads\Storage\PrivateStorage;

defined('ABSPATH') || exit;

/**
 * Every e-mail goes through kdv_email_queue: a mail outage can delay a message but never lose a lead.
 * The lead is saved before any of this runs (see RestController).
 */
final class Mailer
{
    private static string $last_error = '';
    private static string $alt_body = '';

    public static function init(): void
    {
        add_action('kdv_lead_created', [self::class, 'on_lead_created'], 10);
        add_action('wp_mail_failed', static function (\WP_Error $err): void {
            self::$last_error = $err->get_error_message();
        });
    }

    /** KDV recipients; falls back so that a lead notification always has somewhere to go. */
    public static function recipients(): array
    {
        $raw = (string) Config::get('recipients');
        $list = array_filter(array_map('sanitize_email', array_map('trim', explode(',', $raw))), 'is_email');
        if (!$list) {
            $fallback = (string) Config::get('tech_admin_email');
            $list = is_email($fallback) ? [$fallback] : [(string) get_option('admin_email')];
        }
        return array_values(array_unique($list));
    }

    public static function tech_admin(): string
    {
        $mail = (string) Config::get('tech_admin_email');
        return is_email($mail) ? $mail : (string) get_option('admin_email');
    }

    private static function from_address(): string
    {
        $from = (string) Config::get('from_email');
        return is_email($from) ? $from : self::recipients()[0];
    }

    public static function on_lead_created(array $lead): void
    {
        if (!in_array($lead['type'] ?? '', ['estimation', 'recherche'], true)) {
            return;
        }
        $lead = LeadRepository::find((int) $lead['id']) ?: $lead; // pick up pdf_path set a moment ago
        $ids = [];

        $ids[] = EmailQueueRepository::enqueue([
            'lead_id'   => (int) $lead['id'],
            'kind'      => 'admin_notification',
            'to_email'  => implode(',', self::recipients()),
            'subject'   => MessageBuilder::admin_subject($lead),
            'body_html' => MessageBuilder::admin_html($lead, self::pdf_file($lead) !== null),
            'body_text' => MessageBuilder::admin_text($lead),
        ]);

        // Acknowledgement only when the customer gave an e-mail (it is optional).
        // The address is not verified: cap what one address can receive per day, so the form cannot be used
        // to mail-bomb a third party.
        $ack_allowed = !empty($lead['email']) && is_email($lead['email'])
            && Counters::hit(Counters::daily('ak', strtolower((string) $lead['email']))) <= max(1, (int) Config::get('ack_per_email_per_day'));
        if (!empty($lead['email']) && !$ack_allowed) {
            LeadRepository::log_event((int) $lead['id'], 'ack_skipped', ['reason' => 'daily limit per address']);
        }
        if ($ack_allowed) {
            $ids[] = EmailQueueRepository::enqueue([
                'lead_id'   => (int) $lead['id'],
                'kind'      => 'client_ack',
                'to_email'  => $lead['email'],
                'subject'   => MessageBuilder::client_subject($lead),
                'body_html' => MessageBuilder::client_html($lead),
                'body_text' => MessageBuilder::client_text($lead),
            ]);
        }

        // Immediate attempt; anything that fails is left to the cron worker.
        foreach ($ids as $id) {
            try {
                QueueWorker::attempt((int) $id);
            } catch (\Throwable $e) {
                error_log('[kdv-leads] immediate send failed: ' . $e->getMessage());
            }
        }
    }

    /** Manual "Renvoyer la notification KDV" from the lead page. */
    public static function resend_notification(array $lead): void
    {
        $id = EmailQueueRepository::enqueue([
            'lead_id'   => (int) $lead['id'],
            'kind'      => 'admin_notification',
            'to_email'  => implode(',', self::recipients()),
            'subject'   => MessageBuilder::admin_subject($lead),
            'body_html' => MessageBuilder::admin_html($lead, self::pdf_file($lead) !== null),
            'body_text' => MessageBuilder::admin_text($lead),
        ]);
        QueueWorker::attempt($id);
    }

    /** Absolute path of the lead's PDF if it exists and fits the size cap. */
    private static function pdf_file(array $lead): ?string
    {
        if (empty($lead['pdf_path']) || ($lead['pdf_status'] ?? '') !== 'ok') {
            return null;
        }
        $file = PrivateStorage::path() . '/' . $lead['pdf_path'];
        $max = (int) Config::get('pdf_max_mb') * 1024 * 1024;
        if (!is_readable($file) || ($max > 0 && filesize($file) > $max)) {
            return null;
        }
        return $file;
    }

    /**
     * Send one queue item through wp_mail().
     *
     * @return string '' on success, otherwise the error message
     */
    public static function send_item(array $item): string
    {
        $lead = $item['lead_id'] ? LeadRepository::find((int) $item['lead_id']) : null;
        $from_name = (string) Config::get('from_name') ?: 'KDV Motors';
        $kdv_address = self::from_address();

        $headers = [
            'Content-Type: text/html; charset=UTF-8',
            'From: ' . self::header_safe($from_name) . ' <' . $kdv_address . '>',
        ];
        $reply_to = $kdv_address;
        if ($item['kind'] === 'admin_notification' && $lead && !empty($lead['email']) && is_email($lead['email'])) {
            $reply_to = $lead['email'];
        }
        $headers[] = 'Reply-To: ' . $reply_to;

        $attachments = [];
        if ($item['kind'] === 'admin_notification' && $lead && ($file = self::pdf_file($lead))) {
            $attachments[] = $file;
        }

        return self::wp_mail(explode(',', (string) $item['to_email']), (string) $item['subject'], (string) $item['body_html'], (string) $item['body_text'], $headers, $attachments);
    }

    /** Direct send (diagnostic test e-mail, alerts); returns '' or the error. */
    public static function wp_mail(array $to, string $subject, string $html, string $text, array $headers, array $attachments = []): string
    {
        self::$last_error = '';
        self::$alt_body = $text;
        $set_alt = static function ($phpmailer): void {
            $phpmailer->AltBody = self::$alt_body;
        };
        add_action('phpmailer_init', $set_alt);
        try {
            $ok = wp_mail($to, $subject, $html, $headers, $attachments);
        } catch (\Throwable $e) {
            $ok = false;
            self::$last_error = $e->getMessage();
        }
        remove_action('phpmailer_init', $set_alt);

        if ($ok) {
            return '';
        }
        return self::$last_error !== '' ? self::$last_error : 'wp_mail() a renvoyé false.';
    }

    private static function header_safe(string $s): string
    {
        return str_replace(['"', "\r", "\n", '<', '>'], '', $s);
    }
}
