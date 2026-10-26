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
    /** Absolute ceiling (pixels). The real limit also depends on the memory this server can spare, see assert_memory(). */
    public const MAX_PIXELS = 24000000;

    /** Peak bytes per pixel measured with GD while decoding (PNG needs the most), without the rotation copy. */
    private const BYTES_PER_PIXEL = [IMAGETYPE_JPEG => 4.6, IMAGETYPE_PNG => 7.2, IMAGETYPE_WEBP => 6.0];

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

        // Decide BEFORE decoding: a 500 KB file can expand to hundreds of MB and would otherwise end in a fatal error.
        $angle = $info[2] === IMAGETYPE_JPEG ? self::orientation_angle($tmp_file) : 0;
        self::assert_memory((int) $info[0], (int) $info[1], (int) $info[2], $angle !== 0);

        $src = match ($info[2]) {
            IMAGETYPE_JPEG => @imagecreatefromjpeg($tmp_file),
            IMAGETYPE_PNG  => @imagecreatefrompng($tmp_file),
            IMAGETYPE_WEBP => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($tmp_file) : false,
        };
        if (!$src) {
            throw new \RuntimeException('Cette image n\'a pas pu être lue.');
        }

        if ($angle) {
            $src = self::rotate($src, $angle);
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

    /** Rotation (degrees for imagerotate) needed to honour the EXIF orientation of a JPEG, or 0. */
    private static function orientation_angle(string $file): int
    {
        if (!function_exists('exif_read_data')) {
            return 0;
        }
        $exif = @exif_read_data($file);
        return [3 => 180, 6 => -90, 8 => 90][(int) ($exif['Orientation'] ?? 1)] ?? 0;
    }

    private static function rotate($img, int $angle)
    {
        $rot = imagerotate($img, $angle, 0);
        if ($rot) {
            imagedestroy($img);
            return $rot;
        }
        return $img;
    }

    /**
     * Refuse the image if decoding it would not fit in the memory still available to PHP.
     * Estimated from the pixel count, the format and whether a rotated copy is needed, plus a margin
     * for WordPress itself and the resampling buffers.
     */
    public static function assert_memory(int $w, int $h, int $type, bool $rotate): void
    {
        if (function_exists('wp_raise_memory_limit')) {
            wp_raise_memory_limit('image');
        }
        $limit = function_exists('wp_convert_hr_to_bytes') ? (int) wp_convert_hr_to_bytes((string) ini_get('memory_limit')) : 0;
        if ($limit <= 0) {
            return;   // unlimited
        }
        $need = $w * $h * (self::BYTES_PER_PIXEL[$type] ?? 7.2) + ($rotate ? $w * $h * 4 : 0) + 24 * 1048576;
        if ($need > $limit - memory_get_usage(true)) {
            throw new \RuntimeException('Cette image est trop grande pour être traitée. Réduisez-la puis réessayez.');
        }
    }
}
