<?php
namespace KdvLeads\Admin;

use KdvLeads\Database\Schema;
use KdvLeads\Storage\PrivateStorage;

defined('ABSPATH') || exit;

final class DiagnosticPage
{
    public static function init(): void
    {
        add_action('admin_post_kdv_sample_pdf', [self::class, 'sample_pdf']);
        add_action('admin_post_kdv_test_email', [self::class, 'test_email']);
    }

    /** Sends a test e-mail to the current user, bypassing the queue. */
    public static function test_email(): void
    {
        if (!current_user_can(\KdvLeads\Config::CAP_SETTINGS)) {
            wp_die('Accès refusé.', 403);
        }
        check_admin_referer('kdv_test_email');
        $to = wp_get_current_user()->user_email;
        $from = (string) \KdvLeads\Config::get('from_email');
        $headers = ['Content-Type: text/html; charset=UTF-8'];
        if (is_email($from)) {
            $headers[] = 'From: KDV Motors <' . $from . '>';
        }
        $err = \KdvLeads\Mail\Mailer::wp_mail([$to], '[KDV] E-mail de test', "<p>Ce message confirme que l'envoi d'e-mails fonctionne.</p>", "Ce message confirme que l'envoi d'e-mails fonctionne.", $headers);
        wp_safe_redirect(add_query_arg(['kdv_test' => $err === '' ? 'ok' : rawurlencode($err)], admin_url('admin.php?page=kdv-diagnostic')));
        exit;
    }

    /** Streams an example estimation PDF built from fake data (nothing is stored). */
    public static function sample_pdf(): void
    {
        if (!current_user_can(\KdvLeads\Config::CAP_SETTINGS)) {
            wp_die('Accès refusé.', 403);
        }
        check_admin_referer('kdv_sample_pdf');
        $lead = [
            'id' => 0, 'reference' => 'KDV-EST-' . gmdate('Ymd') . '-0001', 'type' => 'estimation',
            'full_name' => 'Jean Dupont', 'phone' => '+33612345678', 'email' => 'jean.dupont@example.com',
            'sale_timeframe' => 'asap', 'created_at' => gmdate('Y-m-d H:i:s'),
            'payload' => wp_json_encode([
                'immatriculation' => 'AB-123-CD', 'marque' => 'BMW', 'modele' => 'Série 3', 'finition' => 'M Sport',
                'annee_mise_en_circulation' => 2020, 'kilometrage' => 65000, 'motorisation' => 'diesel', 'boite' => 'automatique',
                'etat_general' => 'bon', 'etat_habitacle' => 'propre', 'accident' => 'aucun', 'suivi_entretien' => 'complet',
                'equipements' => ['gps', 'cuir', 'camera_recul'], 'autre_equipement' => 'Attelage',
            ]),
        ];
        try {
            $bytes = \KdvLeads\Pdf\PdfGenerator::render($lead);
        } catch (\Throwable $e) {
            wp_die(esc_html($e->getMessage()), 'Erreur PDF', 500);
        }
        nocache_headers();
        header('Content-Type: application/pdf');
        header('Content-Disposition: inline; filename="' . \KdvLeads\Pdf\PdfGenerator::filename($lead) . '"');
        header('X-Content-Type-Options: nosniff');
        echo $bytes; // phpcs:ignore WordPress.Security.EscapeOutput
        exit;
    }

    /** @return array<int, array{0:string,1:string,2:string}> label, status (ok|warn|error), detail */
    public static function checks(): array
    {
        global $wpdb;
        $rows = [];
        $add = static function (string $label, string $status, string $detail) use (&$rows): void {
            $rows[] = [$label, $status, $detail];
        };

        $add('Version PHP', version_compare(PHP_VERSION, '8.1', '>=') ? 'ok' : 'error', PHP_VERSION . ' (8.1 minimum)');

        foreach (['gd', 'imagick', 'fileinfo', 'exif', 'mbstring'] as $ext) {
            $loaded = extension_loaded($ext);
            $add('Extension ' . $ext, $loaded ? 'ok' : ($ext === 'imagick' ? 'warn' : 'error'), $loaded ? 'chargée' : 'absente' . ($ext === 'imagick' ? ' (facultative, GD suffit)' : ''));
        }
        $add('mPDF', class_exists('\Mpdf\Mpdf') ? 'ok' : 'error', class_exists('\Mpdf\Mpdf') ? 'disponible' : 'absent : dossier vendor/ manquant');

        $mem = wp_convert_hr_to_bytes((string) ini_get('memory_limit'));
        $add('memory_limit', $mem === -1 || $mem >= 256 * MB_IN_BYTES ? 'ok' : 'warn', (string) ini_get('memory_limit') . ' (256M recommandé)');
        foreach (['upload_max_filesize', 'post_max_size', 'max_file_uploads', 'max_execution_time'] as $ini) {
            $add($ini, 'ok', (string) ini_get($ini));
        }

        // Private storage.
        $dir = PrivateStorage::path();
        $ready = PrivateStorage::ensure();
        $add('Stockage privé', $ready ? 'ok' : 'error', $dir . ($ready ? ' (lecture/écriture OK)' : ' : non accessible en écriture'));
        $add('Stockage hors webroot', PrivateStorage::is_outside_webroot() ? 'ok' : 'warn',
            PrivateStorage::is_outside_webroot() ? 'oui' : 'non : protégé par .htaccess uniquement (définir KDV_STORAGE_PATH)');
        $add('Accès public au stockage', ...self::public_access_check($ready));

        // Web server: the .htaccess protection only exists on Apache (and compatible servers).
        $software = isset($_SERVER['SERVER_SOFTWARE']) ? (string) $_SERVER['SERVER_SOFTWARE'] : 'inconnu';
        $apache_like = (bool) preg_match('/apache|litespeed/i', $software);
        $outside = PrivateStorage::is_outside_webroot();
        $add('Serveur web', ($apache_like || $outside) ? 'ok' : 'warn',
            $software . ($apache_like || $outside ? '' : ' : les fichiers .htaccess sont ignorés, définir KDV_STORAGE_PATH hors de la racine web'));

        // Anti-abuse: which IP the limiter sees, and whether the bot check is on.
        $ip = \KdvLeads\Http\AntiSpam::client_ip();
        $private = !filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
        $mode = (string) \KdvLeads\Config::get('proxy_mode');
        $add('Adresse IP vue par le serveur', ($private && $mode === '') ? 'warn' : 'ok',
            $ip . ($private && $mode === '' ? ' (adresse privée : si le site est derrière un proxy ou un CDN, régler « Anti-spam » dans les Réglages, sinon la limite par IP s\'appliquerait à tous les visiteurs)' : '')
            . ($mode !== '' ? ' [lue dans ' . ($mode === 'cloudflare' ? 'CF-Connecting-IP' : 'X-Forwarded-For') . ']' : ''));
        $turnstile = (string) \KdvLeads\Config::get('turnstile_secret_key') !== '' && (string) \KdvLeads\Config::get('turnstile_site_key') !== '';
        $add('Anti-robot (Cloudflare Turnstile)', $turnstile ? 'ok' : 'warn', $turnstile ? 'actif' : 'non configuré : recommandé en production (Réglages → Anti-spam)');
        $add('Mémoire PHP pour les photos', (function_exists('wp_convert_hr_to_bytes') && (int) wp_convert_hr_to_bytes((string) ini_get('memory_limit')) >= 256 * MB_IN_BYTES) || (int) ini_get('memory_limit') === -1 ? 'ok' : 'warn',
            (string) ini_get('memory_limit') . ' (les photos trop lourdes pour la mémoire disponible sont refusées proprement)');

        // Cron.
        $add('WP-Cron', defined('DISABLE_WP_CRON') && DISABLE_WP_CRON ? 'ok' : 'warn',
            defined('DISABLE_WP_CRON') && DISABLE_WP_CRON ? 'désactivé (cron système attendu)' : 'actif : recommander un cron système toutes les 5 min');
        $last = (int) get_option('kdv_worker_last_run', 0);
        $add('Dernier passage du worker e-mail', $last ? 'ok' : 'warn', $last ? wp_date('d/m/Y H:i:s', $last) : 'jamais (le cron WordPress n\'a pas encore tourné)');

        // Failed emails and DB.
        $queue = Schema::table('email_queue');
        $exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $queue)) === $queue;
        if ($exists) {
            $failed = (int) $wpdb->get_var("SELECT COUNT(*) FROM `$queue` WHERE status IN ('failed','abandoned')"); // phpcs:ignore WordPress.DB
            $add('E-mails en échec', $failed ? 'error' : 'ok', (string) $failed);
        } else {
            $add('Tables kdv_*', 'error', 'absentes : réactiver le plugin');
        }
        $add('Version du schéma', get_option('kdv_db_version') ? 'ok' : 'error', (string) get_option('kdv_db_version'));

        return $rows;
    }

    /** Writes a probe file and requests it over HTTP: must be 403/404. */
    private static function public_access_check(bool $ready): array
    {
        if (!$ready) {
            return ['warn', 'non testé (stockage inaccessible)'];
        }
        $url = PrivateStorage::public_url();
        if ($url === null) {
            return ['ok', 'hors webroot : aucune URL publique'];
        }
        $name = 'kdv-probe-' . wp_generate_password(8, false) . '.txt';
        $file = PrivateStorage::path() . '/' . $name;
        @file_put_contents($file, 'probe');
        $res = wp_remote_get($url . '/' . $name, ['timeout' => 8, 'sslverify' => false, 'redirection' => 0]);
        @unlink($file);

        if (is_wp_error($res)) {
            return ['warn', 'test impossible : ' . $res->get_error_message()];
        }
        $code = (int) wp_remote_retrieve_response_code($res);
        return in_array($code, [401, 403, 404], true)
            ? ['ok', 'refusé (HTTP ' . $code . ')']
            : ['error', 'FICHIER ACCESSIBLE PUBLIQUEMENT (HTTP ' . $code . ') : définir KDV_STORAGE_PATH hors webroot'];
    }

    public static function render(): void
    {
        $icons = ['ok' => '✅', 'warn' => '⚠️', 'error' => '❌'];
        Chrome::open('Diagnostic', 'État de l\'hébergement, du stockage et des e-mails');
        if (isset($_GET['kdv_test'])) {
            $r = sanitize_text_field(wp_unslash($_GET['kdv_test']));
            echo $r === 'ok' ? '<div class="notice notice-success"><p>E-mail de test envoyé.</p></div>' : '<div class="notice notice-error"><p>Échec de l\'envoi : ' . esc_html(rawurldecode($r)) . '</p></div>';
        }
        echo '<table class="widefat striped" style="max-width:900px"><tbody>';
        foreach (self::checks() as [$label, $status, $detail]) {
            printf('<tr><td style="width:30%%"><strong>%s</strong></td><td style="width:40px">%s</td><td>%s</td></tr>',
                esc_html($label), esc_html($icons[$status]), esc_html($detail));
        }
        echo '</tbody></table>';
        echo '<p><a class="button" target="_blank" href="' . esc_url(wp_nonce_url(admin_url('admin-post.php?action=kdv_sample_pdf'), 'kdv_sample_pdf')) . '">Générer un PDF d\'exemple</a></p>';
        echo '<p><a class="button" href="' . esc_url(wp_nonce_url(admin_url('admin-post.php?action=kdv_test_email'), 'kdv_test_email')) . '">Envoyer un e-mail de test</a> <span class="description">(à votre adresse de connexion)</span></p>';
        Chrome::close();
    }
}
