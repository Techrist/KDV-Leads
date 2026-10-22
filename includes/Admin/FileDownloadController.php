<?php
namespace KdvLeads\Admin;

use KdvLeads\Database\LeadRepository;
use KdvLeads\Storage\PrivateStorage;

defined('ABSPATH') || exit;

/** The only way a stored file reaches a browser: capability + nonce, then a streamed download. */
final class FileDownloadController
{
    /** A stored photo (or its thumbnail). */
    public static function image(int $file_id, bool $thumb): void
    {
        $file = \KdvLeads\Database\FileRepository::find($file_id);
        if (!$file || $file['kind'] !== 'photo') {
            wp_die('Image introuvable.', 404);
        }
        $rel = $thumb ? \KdvLeads\Storage\ImageProcessor::thumb_path((string) $file['path']) : (string) $file['path'];
        $root = realpath(PrivateStorage::path());
        $abs = realpath(PrivateStorage::path() . '/' . $rel);
        if (!$root || !$abs || strpos($abs, $root . DIRECTORY_SEPARATOR) !== 0 || !is_file($abs)) {
            wp_die('Image introuvable.', 404);
        }
        nocache_headers();
        header('Content-Type: image/jpeg');
        header('Content-Length: ' . filesize($abs));
        header('Content-Disposition: ' . ($thumb ? 'inline' : 'attachment') . '; filename="' . basename($abs) . '"');
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: private, no-store');
        readfile($abs);
        exit;
    }

    public static function pdf(int $lead_id): void
    {
        $lead = LeadRepository::find($lead_id);
        if (!$lead || empty($lead['pdf_path'])) {
            wp_die('PDF introuvable.', 404);
        }
        $root = realpath(PrivateStorage::path());
        $file = realpath(PrivateStorage::path() . '/' . $lead['pdf_path']);
        // Refuse anything that resolves outside the private storage (path traversal).
        if (!$root || !$file || strpos($file, $root . DIRECTORY_SEPARATOR) !== 0 || !is_file($file)) {
            wp_die('PDF introuvable.', 404);
        }

        LeadRepository::log_event($lead_id, 'pdf_downloaded', [], get_current_user_id());

        nocache_headers();
        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="' . basename($file) . '"');
        header('Content-Length: ' . filesize($file));
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: private, no-store');
        readfile($file);
        exit;
    }
}
