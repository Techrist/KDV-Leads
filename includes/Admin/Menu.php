<?php
namespace KdvLeads\Admin;

use KdvLeads\Config;
use KdvLeads\Database\LeadRepository;

defined('ABSPATH') || exit;

final class Menu
{
    public const SLUG = 'kdv-leads';

    public static function init(): void
    {
        add_action('admin_menu', [self::class, 'register']);
        add_action('admin_init', [SettingsPage::class, 'register_settings']);
        Chrome::init();
        DiagnosticPage::init();
        LeadActions::init();
    }

    private static function bubble(string $type): string
    {
        $n = LeadRepository::count_status($type, LeadRepository::STATUS_NEW);
        return $n > 0 ? ' <span class="awaiting-mod">' . (int) $n . '</span>' : '';
    }

    public static function register(): void
    {
        $new_est = self::bubble('estimation');
        $new_rec = self::bubble('recherche');
        $total = LeadRepository::count_status('estimation', LeadRepository::STATUS_NEW) + LeadRepository::count_status('recherche', LeadRepository::STATUS_NEW);
        $top = 'Dossiers KDV' . ($total > 0 ? ' <span class="awaiting-mod">' . (int) $total . '</span>' : '');

        add_menu_page('Dossiers KDV', $top, Config::CAP_LEADS, self::SLUG, [self::class, 'render_estimations'], 'dashicons-car', 26);
        add_submenu_page(self::SLUG, 'Estimations vendeurs', 'Estimations vendeurs' . $new_est, Config::CAP_LEADS, self::SLUG, [self::class, 'render_estimations']);
        add_submenu_page(self::SLUG, 'Recherches acheteurs', 'Recherches acheteurs' . $new_rec, Config::CAP_LEADS, 'kdv-recherches', [self::class, 'render_recherches']);
        add_submenu_page(self::SLUG, 'Réglages', 'Réglages', Config::CAP_SETTINGS, 'kdv-settings', [SettingsPage::class, 'render']);
        add_submenu_page(self::SLUG, 'Diagnostic', 'Diagnostic', Config::CAP_SETTINGS, 'kdv-diagnostic', [DiagnosticPage::class, 'render']);
    }

    public static function render_estimations(): void
    {
        self::render_list('estimation', self::SLUG, 'Estimations vendeurs', 'Demandes de dépôt-vente, classées par urgence de vente');
    }

    public static function render_recherches(): void
    {
        self::render_list('recherche', 'kdv-recherches', 'Recherches acheteurs', 'Recherches personnalisées, les plus récentes en premier');
    }

    private static function render_list(string $type, string $slug, string $title, string $subtitle): void
    {
        if (!current_user_can(Config::CAP_LEADS)) {
            wp_die('Accès refusé.', 403);
        }
        $lead = isset($_GET['lead']) ? (int) $_GET['lead'] : 0; // phpcs:ignore WordPress.Security.NonceVerification
        if ($lead > 0) {
            LeadDetailPage::render($lead);
            return;
        }

        $table = new LeadListTable($type, $slug);
        $table->prepare_items();
        $f = $table->filters();
        $base = admin_url('admin.php?page=' . $slug);
        $k = LeadRepository::kpis($type);

        Chrome::open($title, $subtitle);
        if ($type === 'estimation') {
            Chrome::kpis([
                ['Nouveaux dossiers', $k['new'], add_query_arg('status', 'nouveau', $base), $k['new'] > 0 ? 'alert' : ''],
                ['Vente dès que possible', $k['asap_open'], add_query_arg(['delai' => 'asap', 'open' => '1'], $base), $k['asap_open'] > 0 ? 'alert' : ''],
                ['Rappels à faire', $k['callbacks_due'], add_query_arg('callback', 'due', $base), $k['callbacks_due'] > 0 ? 'warn' : ''],
                ['Dossiers en cours', $k['open'], add_query_arg('open', '1', $base), ''],
                ['Reçus ces 7 jours', $k['week'], add_query_arg('from', wp_date('Y-m-d', time() - 6 * DAY_IN_SECONDS), $base), ''],
            ]);
        } else {
            Chrome::kpis([
                ['Nouvelles recherches', $k['new'], add_query_arg('status', 'nouveau', $base), $k['new'] > 0 ? 'alert' : ''],
                ['Recherches en cours', $k['open'], add_query_arg('open', '1', $base), ''],
                ['Reçues ces 7 jours', $k['week'], add_query_arg('from', wp_date('Y-m-d', time() - 6 * DAY_IN_SECONDS), $base), ''],
            ]);
        }

        $table->views();
        echo '<form method="get"><input type="hidden" name="page" value="' . esc_attr($slug) . '">';
        foreach (['status', 'callback', 'open'] as $keep) {
            if ($f[$keep] !== '') {
                echo '<input type="hidden" name="' . esc_attr($keep) . '" value="' . esc_attr($f[$keep]) . '">';
            }
        }
        $table->search_box('Rechercher', 'kdv-lead-search');
        $table->display();
        echo '</form>';
        Chrome::close();
    }
}
