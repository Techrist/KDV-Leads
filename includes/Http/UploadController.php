<?php
namespace KdvLeads\Http;

use KdvLeads\Config;
use KdvLeads\Database\FileRepository;
use KdvLeads\Storage\ImageProcessor;

defined('ABSPATH') || exit;

/** Photo uploads for the search journey (and the optional estimation photo step, §8). */
final class UploadController
{
    public const MAX_FILES = 5;
    public const CLEANUP_HOOK = 'kdv_cleanup_uploads';

    public static function init(): void
    {
        add_action('rest_api_init', [self::class, 'routes']);
        add_action(self::CLEANUP_HOOK, [self::class, 'cleanup']);
        if (!wp_next_scheduled(self::CLEANUP_HOOK)) {
            wp_schedule_event(time() + 300, 'hourly', self::CLEANUP_HOOK);
        }
    }

    public static function routes(): void
    {
        $public = '__return_true'; // public form: protected by nonce, token, quotas
        register_rest_route(RestController::NS, '/upload-session', ['methods' => 'POST', 'callback' => [self::class, 'session'], 'permission_callback' => $public]);
        register_rest_route(RestController::NS, '/uploads', ['methods' => 'POST', 'callback' => [self::class, 'upload'], 'permission_callback' => $public]);
        register_rest_route(RestController::NS, '/uploads/(?P<id>\d+)', ['methods' => 'DELETE', 'callback' => [self::class, 'delete'], 'permission_callback' => $public]);
    }

    private static function fail(int $status, string $message): \WP_REST_Response
    {
        return new \WP_REST_Response(['success' => false, 'message' => $message], $status);
    }

    private static function nonce_ok(\WP_REST_Request $req): bool
    {
        return (bool) wp_verify_nonce((string) $req->get_header('X-WP-Nonce'), 'wp_rest');
    }

    /** Light per-IP throttle shared by session creation and uploads. */
    private static function throttled(string $bucket, int $limit): bool
    {
        $key = 'kdv_up_' . $bucket . '_' . substr(AntiSpam::ip_hash(), 0, 24);
        $n = (int) get_transient($key);
        if ($n >= $limit) {
            return true;
        }
        set_transient($key, $n + 1, HOUR_IN_SECONDS);
        return false;
    }

    public static function session(\WP_REST_Request $req): \WP_REST_Response
    {
        if (!self::nonce_ok($req)) {
            return self::fail(403, 'Votre session a expiré. Rechargez la page.');
        }
        if (self::throttled('session', 20)) {
            return self::fail(429, 'Trop de tentatives. Réessayez plus tard.');
        }
        FileRepository::purge_expired_tokens();
        return new \WP_REST_Response(['success' => true, 'token' => FileRepository::create_token()], 201);
    }

    public static function upload(\WP_REST_Request $req): \WP_REST_Response
    {
        if (!self::nonce_ok($req)) {
            return self::fail(403, 'Votre session a expiré. Rechargez la page.');
        }
        $token = (string) $req->get_param('token');
        $row = FileRepository::valid_token($token);
        if (!$row) {
            return self::fail(403, 'Session d\'envoi expirée. Rechargez la page.');
        }
        if ((int) $row['file_count'] >= self::MAX_FILES) {
            return self::fail(422, 'Vous pouvez ajouter ' . self::MAX_FILES . ' photos au maximum.');
        }
        if (self::throttled('file', 60)) {
            return self::fail(429, 'Trop d\'envois. Réessayez plus tard.');
        }

        $files = $req->get_file_params();
        $f = $files['photo'] ?? null;
        if (!$f || ($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file((string) $f['tmp_name'])) {
            $too_big = ($f['error'] ?? 0) === UPLOAD_ERR_INI_SIZE || ($f['error'] ?? 0) === UPLOAD_ERR_FORM_SIZE;
            return self::fail(422, $too_big ? 'Cette photo est trop volumineuse.' : 'Aucune photo reçue.');
        }
        $max = (int) Config::get('max_photo_mb') * 1024 * 1024;
        if ($max > 0 && (int) $f['size'] > $max) {
            return self::fail(422, 'Cette photo dépasse ' . (int) Config::get('max_photo_mb') . ' Mo.');
        }

        try {
            $img = ImageProcessor::process((string) $f['tmp_name']);
        } catch (\RuntimeException $e) {
            return self::fail(422, $e->getMessage());
        }

        $id = FileRepository::create([
            'upload_token'  => $token,
            'kind'          => 'photo',
            'path'          => $img['path'],
            'original_name' => mb_substr(sanitize_file_name((string) ($f['name'] ?? 'photo.jpg')), 0, 190),
            'mime'          => $img['mime'],
            'size'          => $img['size'],
            'width'         => $img['width'],
            'height'        => $img['height'],
        ]);
        if (!$id) {
            ImageProcessor::delete($img['path']);
            return self::fail(500, 'Enregistrement impossible. Réessayez.');
        }
        FileRepository::bump_token($token, 1);

        return new \WP_REST_Response(['success' => true, 'id' => $id, 'width' => $img['width'], 'height' => $img['height']], 201);
    }

    public static function delete(\WP_REST_Request $req): \WP_REST_Response
    {
        if (!self::nonce_ok($req)) {
            return self::fail(403, 'Votre session a expiré.');
        }
        $token = (string) $req->get_header('X-KDV-Upload-Token');
        $file = FileRepository::find((int) $req['id']);
        // Only the session that uploaded a not-yet-submitted photo may remove it.
        if (!$file || $file['lead_id'] !== null || !hash_equals((string) $file['upload_token'], $token)) {
            return self::fail(404, 'Photo introuvable.');
        }
        ImageProcessor::delete((string) $file['path']);
        FileRepository::delete((int) $file['id']);
        FileRepository::bump_token($token, -1);
        return new \WP_REST_Response(['success' => true]);
    }

    /** Cron: photos never attached to a lead after 24 h are removed from disk and database. */
    public static function cleanup(): void
    {
        foreach (FileRepository::orphans(DAY_IN_SECONDS) as $file) {
            ImageProcessor::delete((string) $file['path']);
            FileRepository::delete((int) $file['id']);
        }
        FileRepository::purge_expired_tokens();
    }
}
