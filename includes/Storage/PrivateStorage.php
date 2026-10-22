<?php
namespace KdvLeads\Storage;

defined('ABSPATH') || exit;

/** Private file storage: KDV_STORAGE_PATH if defined, else a protected folder in uploads. */
final class PrivateStorage
{
    public static function path(): string
    {
        if (defined('KDV_STORAGE_PATH') && KDV_STORAGE_PATH) {
            return rtrim((string) KDV_STORAGE_PATH, '/\\');
        }
        $dir = get_option('kdv_storage_dir');
        if (!$dir) {
            $dir = 'kdv-private-' . bin2hex(random_bytes(8));
            update_option('kdv_storage_dir', $dir, false);
        }
        $uploads = wp_upload_dir(null, false);
        return rtrim($uploads['basedir'], '/\\') . '/' . $dir;
    }

    /** Delete one stored file by its path relative to the storage root (never outside it). */
    public static function delete(string $relative): void
    {
        $root = realpath(self::path());
        $abs = realpath(self::path() . '/' . $relative);
        if ($root && $abs && strpos($abs, $root . DIRECTORY_SEPARATOR) === 0 && is_file($abs)) {
            @unlink($abs);
        }
    }

    public static function is_outside_webroot(): bool
    {
        $real = realpath(self::path());
        $root = realpath(ABSPATH);
        return $real && $root && strpos($real, $root) !== 0;
    }

    /** Create the folder and protection files. Returns false on failure. */
    public static function ensure(): bool
    {
        $dir = self::path();
        if (!is_dir($dir) && !wp_mkdir_p($dir)) {
            return false;
        }
        if (!file_exists($dir . '/.htaccess')) {
            @file_put_contents($dir . '/.htaccess', "Require all denied\nDeny from all\n");
        }
        if (!file_exists($dir . '/index.php')) {
            @file_put_contents($dir . '/index.php', "<?php // Silence is golden.\n");
        }
        return is_dir($dir) && is_writable($dir);
    }

    /** Public URL the folder would have inside uploads, or null when outside the webroot. */
    public static function public_url(): ?string
    {
        if (self::is_outside_webroot()) {
            return null;
        }
        $uploads = wp_upload_dir(null, false);
        $base = wp_normalize_path($uploads['basedir']);
        $dir = wp_normalize_path(self::path());
        if (strpos($dir, $base) !== 0) {
            return null;
        }
        return $uploads['baseurl'] . substr($dir, strlen($base));
    }
}
