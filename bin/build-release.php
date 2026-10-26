<?php
/**
 * Builds dist/kdv-leads-<version>.zip, ready to upload in WordPress (Extensions → Ajouter → Téléverser).
 *
 *   composer install --no-dev --optimize-autoloader
 *   php bin/build-release.php
 *
 * - vendor/ is included (no SSH / Composer needed on the host) but must be a production install.
 * - mPDF ships ~90 MB of fonts; only the DejaVu family (used by the PDFs) is kept in the zip.
 * - Entries use forward slashes and a single top-level "kdv-leads/" folder, as WordPress expects.
 */

$root = dirname(__DIR__);
if (!class_exists('ZipArchive')) {
    fwrite(STDERR, "The PHP zip extension is required.\n");
    exit(1);
}
if (is_dir($root . '/vendor/phpunit')) {
    fwrite(STDERR, "vendor/ contains dev dependencies. Run: composer install --no-dev --optimize-autoloader\n");
    exit(1);
}
if (!is_readable($root . '/vendor/autoload.php') || !is_dir($root . '/vendor/mpdf/mpdf')) {
    fwrite(STDERR, "vendor/ is missing mPDF. Run: composer install --no-dev --optimize-autoloader\n");
    exit(1);
}

$main = (string) file_get_contents($root . '/kdv-leads.php');
if (!preg_match('/^\s*\*\s*Version:\s*([0-9][0-9A-Za-z.\-]*)/m', $main, $m)) {
    fwrite(STDERR, "Cannot read the plugin version.\n");
    exit(1);
}
$version = $m[1];

$include = ['kdv-leads.php', 'uninstall.php', 'composer.json', 'composer.lock', 'README.md', '.htaccess', 'includes', 'assets', 'vendor'];
$skip_font = static function (string $rel): bool {
    // Keep only the DejaVu family (and its licence note) from mPDF's font folder.
    if (strpos($rel, 'vendor/mpdf/mpdf/ttfonts/') !== 0) {
        return false;
    }
    return strpos(basename($rel), 'DejaVu') !== 0;
};
$skip = static function (string $rel): bool {
    return (bool) preg_match('#(^|/)(\.git|\.github|\.phpunit\.cache|tests|\.DS_Store|Thumbs\.db)(/|$)#', $rel)
        || preg_match('#^vendor/mpdf/mpdf/(tmp|tests|examples)/#', $rel)
        || substr($rel, -4) === '.log';
};

@mkdir($root . '/dist', 0775, true);
$zip_path = $root . "/dist/kdv-leads-$version.zip";
@unlink($zip_path);
$zip = new ZipArchive();
if ($zip->open($zip_path, ZipArchive::CREATE) !== true) {
    fwrite(STDERR, "Cannot create $zip_path\n");
    exit(1);
}

$count = 0;
$add = static function (string $rel) use ($root, $zip, $skip, $skip_font, &$count): void {
    if ($skip($rel) || $skip_font($rel)) {
        return;
    }
    $zip->addFile($root . '/' . $rel, 'kdv-leads/' . $rel);
    $count++;
};
foreach ($include as $item) {
    $path = $root . '/' . $item;
    if (is_file($path)) {
        $add($item);
        continue;
    }
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $file) {
        if ($file->isFile()) {
            $add(str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1)));
        }
    }
}
$zip->close();

$size = filesize($zip_path);
file_put_contents($zip_path . '.sha256', hash_file('sha256', $zip_path) . '  ' . basename($zip_path) . "\n");
printf("%s\n  %d files, %.1f MB, sha256 %s\n", $zip_path, $count, $size / 1048576, substr(hash_file('sha256', $zip_path), 0, 16) . '…');
