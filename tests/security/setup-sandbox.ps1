# Builds an ISOLATED sandbox to attack the shipped plugin without touching the real site or its database.
#
#   - own MariaDB data directory, port 3307, loopback only, root password generated at random
#   - copy of the WordPress core of the local site (no uploads, no other plugins)
#   - the release zip (dist/kdv-leads-*.zip) extracted as the only plugin
#   - PHP built-in web server on 127.0.0.1:8099 with a 256 MB memory limit (like shared hosting)
#
# Usage (PowerShell, from the project root):
#   powershell -ExecutionPolicy Bypass -File tests\security\setup-sandbox.ps1
#   php -d memory_limit=1024M <sandbox>\attack.php          # all groups, or: attack.php A  (A..K)
#
# Limits: the built-in PHP server ignores .htaccess, so the Apache-only protections (403 on vendor/, includes/,
# private storage) must be checked on Apache (curl http://localhost/...). Stop the sandbox with -Stop.
param(
    [string] $Path = "$env:TEMP\kdv-sandbox",
    [string] $WordPressSource = 'C:\xampp\htdocs\kdv-wp',
    [string] $Php = 'C:\xampp\php\php.exe',
    [string] $XamppMysql = 'C:\xampp\mysql\bin',
    [switch] $Stop
)
$ErrorActionPreference = 'Stop'
$here = Split-Path -Parent $MyInvocation.MyCommand.Path
$root = Resolve-Path "$here\..\.."

if ($Stop) {
    Get-CimInstance Win32_Process | Where-Object { $_.CommandLine -and $_.CommandLine -like "*$Path*" } | ForEach-Object { Stop-Process -Id $_.ProcessId -Force }
    "Sandbox processes stopped."
    return
}

New-Item -ItemType Directory -Force $Path | Out-Null
foreach ($f in 'attack.php','h_boot.php','h_token.php','h_cfg.php','h_secret.php','h_retention.php','race_worker.php','install1.php','install.php') { Copy-Item "$here\$f" $Path -Force }

$rnd = { -join ((48..57) + (65..90) + (97..122) | Get-Random -Count 20 | ForEach-Object { [char]$_ }) }
$rootpw = & $rnd; $secpw = & $rnd
[System.IO.File]::WriteAllText("$Path\creds.txt", "root=$rootpw`nsec=$secpw`n")

# 1) database
& "$XamppMysql\mysql_install_db.exe" --datadir="$Path\db" --password=$rootpw | Out-Null
Start-Process "$XamppMysql\mysqld.exe" -ArgumentList '--no-defaults', "--datadir=$Path\db", '--port=3307', '--bind-address=127.0.0.1', '--skip-name-resolve', '--console', '--innodb-buffer-pool-size=32M' -WindowStyle Hidden -RedirectStandardError "$Path\mysqld.err"
Start-Sleep -Seconds 10
& "$XamppMysql\mysql.exe" -u root "-p$rootpw" -h 127.0.0.1 -P 3307 -e "CREATE DATABASE sec_wp CHARACTER SET utf8mb4; CREATE USER 'sec'@'127.0.0.1' IDENTIFIED BY '$secpw'; GRANT ALL ON sec_wp.* TO 'sec'@'127.0.0.1'; FLUSH PRIVILEGES;"

# 2) WordPress + the shipped plugin
$wp = "$Path\wp"
robocopy $WordPressSource $wp /E /XJ /XD uploads kdv-dev-mails mu-plugins plugins /XF wp-config.php .htaccess debug.log /NFL /NDL /NJH /NJS /NP | Out-Null
New-Item -ItemType Directory -Force "$wp\wp-content\plugins", "$wp\wp-content\uploads", "$wp\wp-content\mu-plugins", "$wp\wp-content\kdv-dev-mails" | Out-Null
$zip = Get-ChildItem "$root\dist" -Filter 'kdv-leads-*.zip' | Sort-Object LastWriteTime -Descending | Select-Object -First 1
Expand-Archive $zip.FullName -DestinationPath "$wp\wp-content\plugins" -Force

# mail capture (sandbox only): e-mails are written to files instead of being sent
@'
<?php
add_filter('pre_wp_mail', function ($null, $atts) {
    $dir = WP_CONTENT_DIR . '/kdv-dev-mails';
    if (file_exists($dir . '/fail.flag')) {
        do_action('wp_mail_failed', new WP_Error('wp_mail_failed', 'SMTP connect() failed (simulated outage)', $atts));
        return false;
    }
    $att = array_map('basename', (array) $atts['attachments']);
    file_put_contents($dir . '/' . gmdate('His') . '-' . wp_generate_password(5, false) . '.txt',
        'TO: ' . implode(',', (array) $atts['to']) . "\nSUBJECT: " . $atts['subject'] . "\nHEADERS: " . json_encode($atts['headers']) . "\nATTACHMENTS: " . implode(',', $att) . "\n\n" . $atts['message']);
    return true;
}, 10, 2);
'@ | Set-Content "$wp\wp-content\mu-plugins\kdv-dev-mail.php" -Encoding ASCII

# 3) configuration (no BOM: a BOM before <?php breaks headers/cookies)
$salts = (@('AUTH_KEY','SECURE_AUTH_KEY','LOGGED_IN_KEY','NONCE_KEY','AUTH_SALT','SECURE_AUTH_SALT','LOGGED_IN_SALT','NONCE_SALT') | ForEach-Object {
    "define('$_', '" + (-join ((48..57) + (65..90) + (97..122) | Get-Random -Count 48 | ForEach-Object { [char]$_ })) + "');" }) -join "`n"
$cfg = @"
<?php
define('DB_NAME', 'sec_wp'); define('DB_USER', 'sec'); define('DB_PASSWORD', '$secpw'); define('DB_HOST', '127.0.0.1:3307');
define('DB_CHARSET', 'utf8mb4'); define('DB_COLLATE', '');
$salts
`$table_prefix = 'wp_';
define('WP_DEBUG', false);
define('WP_HOME', 'http://127.0.0.1:8099'); define('WP_SITEURL', 'http://127.0.0.1:8099');
define('DISABLE_WP_CRON', true);
if (!defined('ABSPATH')) { define('ABSPATH', __DIR__ . '/'); }
require_once ABSPATH . 'wp-settings.php';
"@
[System.IO.File]::WriteAllText("$wp\wp-config.php", $cfg, (New-Object System.Text.UTF8Encoding($false)))
Copy-Item "$here\router.php" "$Path\router.php" -Force

& $Php -d memory_limit=256M "$Path\install1.php"
& $Php -d memory_limit=256M "$Path\install.php"

# 4) web server for the sandbox
Start-Process $Php -ArgumentList '-d', 'memory_limit=256M', '-d', 'upload_max_filesize=20M', '-d', 'post_max_size=40M', '-S', '127.0.0.1:8099', '-t', $wp, "$Path\router.php" -WindowStyle Hidden -RedirectStandardError "$Path\php-server.log"
Start-Sleep -Seconds 3
"Sandbox ready: http://127.0.0.1:8099  (folder: $Path)"
"Run the attacks:  $Php -d memory_limit=1024M $Path\attack.php"
