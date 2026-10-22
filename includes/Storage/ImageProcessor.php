<?php
namespace KdvLeads\Storage;

defined('ABSPATH') || exit;

/**
 * Server-side image hygiene (GD): decode → fix EXIF orientation → resize → re-encode as JPEG.
 * Re-encoding with GD drops all metadata (including GPS), so no EXIF ever reaches the storage.
 */
final class ImageProcessor
{
    public const MAX_SIDE = 1600;
    public const THUMB_SIDE = 400;
    /** Refuse decoding images above this many pixels (GD uses ~5 bytes/pixel). */
    public const MAX_PIXELS = 36000000;

    private const TYPES = [
        IMAGETYPE_JPEG => 'image/jpeg',
        IMAGETYPE_PNG  => 'image/png',
        IMAGETYPE_WEBP => 'image/webp',
    ];

    /**
     * @return array{path:string, thumb:string, width:int, height:int, size:int, mime:string}
     * @throws \RuntimeException with a user-displayable French message
     */
    public static function process(string $tmp_file): array
    {
        if (!function_exists('imagecreatetruecolor')) {
            throw new \RuntimeException('Le traitement d\'image est indisponible sur le serveur.');
        }

        // Real type: finfo + getimagesize must agree and be whitelisted (the client MIME is never trusted).
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mime = (string) $finfo->file($tmp_file);
        $info = @getimagesize($tmp_file);
        if (!$info || !isset(self::TYPES[$info[2]]) || self::TYPES[$info[2]] !== $mime) {
            throw new \RuntimeException('Format non pris en charge. Utilisez une photo JPEG, PNG ou WebP.');
        }
        if ($info[0] * $info[1] > self::MAX_PIXELS) {
            throw new \RuntimeException('Cette image est trop grande. Réduisez-la puis réessayez.');
        }

        $src = match ($info[2]) {
            IMAGETYPE_JPEG => @imagecreatefromjpeg($tmp_file),
            IMAGETYPE_PNG  => @imagecreatefrompng($tmp_file),
            IMAGETYPE_WEBP => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($tmp_file) : false,
        };
        if (!$src) {
            throw new \RuntimeException('Cette image n\'a pas pu être lue.');
        }

        if ($info[2] === IMAGETYPE_JPEG && function_exists('exif_read_data')) {
            $src = self::apply_orientation($src, $tmp_file);
        }

        $full = self::fit($src, self::MAX_SIDE, true);
        $thumb = self::fit($full, self::THUMB_SIDE, true);
        imagedestroy($src);

        if (!PrivateStorage::ensure()) {
            throw new \RuntimeException('Stockage indisponible.');
        }
        $root = PrivateStorage::path();
        $name = bin2hex(random_bytes(16)) . '.jpg';
        foreach (['photos', 'thumbs'] as $d) {
            if (!is_dir("$root/$d") && !wp_mkdir_p("$root/$d")) {
                throw new \RuntimeException('Stockage indisponible.');
            }
        }
        if (!imagejpeg($full, "$root/photos/$name", 82) || !imagejpeg($thumb, "$root/thumbs/$name", 75)) {
            throw new \RuntimeException('Enregistrement de l\'image impossible.');
        }
        $w = imagesx($full);
        $h = imagesy($full);
        imagedestroy($full);
        imagedestroy($thumb);

        return ['path' => "photos/$name", 'thumb' => "thumbs/$name", 'width' => $w, 'height' => $h, 'size' => (int) filesize("$root/photos/$name"), 'mime' => 'image/jpeg'];
    }

    /** Remove the files of one photo (full size and derived thumbnail). */
    public static function delete(string $relative_path): void
    {
        $root = PrivateStorage::path();
        foreach ([$relative_path, self::thumb_path($relative_path)] as $rel) {
            $abs = realpath("$root/$rel");
            $real_root = realpath($root);
            if ($abs && $real_root && strpos($abs, $real_root . DIRECTORY_SEPARATOR) === 0 && is_file($abs)) {
                @unlink($abs);
            }
        }
    }

    public static function thumb_path(string $photo_path): string
    {
        return preg_replace('#^photos/#', 'thumbs/', $photo_path);
    }

    /** Scale down to fit $max (never up); flattens transparency on white. */
    private static function fit($img, int $max, bool $flatten)
    {
        $w = imagesx($img);
        $h = imagesy($img);
        $scale = min(1, $max / max($w, $h));
        $nw = max(1, (int) round($w * $scale));
        $nh = max(1, (int) round($h * $scale));
        $out = imagecreatetruecolor($nw, $nh);
        if ($flatten) {
            imagefill($out, 0, 0, imagecolorallocate($out, 255, 255, 255));
        }
        imagecopyresampled($out, $img, 0, 0, 0, 0, $nw, $nh, $w, $h);
        return $out;
    }

    private static function apply_orientation($img, string $file)
    {
        $exif = @exif_read_data($file);
        $o = (int) ($exif['Orientation'] ?? 1);
        $angle = [3 => 180, 6 => -90, 8 => 90][$o] ?? 0;
        if ($angle) {
            $rot = imagerotate($img, $angle, 0);
            if ($rot) {
                imagedestroy($img);
                return $rot;
            }
        }
        return $img;
    }
}
