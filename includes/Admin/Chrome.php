<?php
namespace KdvLeads\Admin;

use KdvLeads\Config;

defined('ABSPATH') || exit;

/** Shared look of the "Dossiers KDV" screens: top bar with logo, KPI cards, stylesheet. */
final class Chrome
{
    public static function init(): void
    {
        add_action('admin_enqueue_scripts', [self::class, 'assets']);
    }

    private static function is_kdv_screen(): bool
    {
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        return $screen && strpos((string) $screen->id, 'kdv') !== false;
    }

    public static function assets(): void
    {
        if (!self::is_kdv_screen()) {
            return;
        }
        wp_enqueue_style('kdv-admin', KDV_LEADS_URL . 'assets/css/kdv-admin.css', [], KDV_LEADS_VERSION);
        $red = (string) Config::get('brand_red');
        if (preg_match('/^#[0-9A-Fa-f]{6}$/', $red)) {
            wp_add_inline_style('kdv-admin', '.kdv-admin{--kdv-red:' . $red . ';}');
        }
    }

    /** Opens the page wrapper and prints the top bar. Close with Chrome::close(). */
    public static function open(string $title, string $subtitle = '', string $actions_html = ''): void
    {
        $logo = KDV_LEADS_DIR . 'assets/img/logo-kdv.png';
        echo '<div class="wrap kdv-admin"><header class="kdv-bar">';
        if (is_readable($logo)) {
            echo '<img class="kdv-bar__logo" src="' . esc_url(KDV_LEADS_URL . 'assets/img/logo-kdv.png') . '" alt="KDV Motors">';
        } else {
            echo '<span class="kdv-bar__logo kdv-bar__logo--text">KD<b>V</b></span>';
        }
        echo '<div class="kdv-bar__title"><h1>' . esc_html($title) . '</h1>';
        if ($subtitle !== '') {
            echo '<p>' . esc_html($subtitle) . '</p>';
        }
        echo '</div><div class="kdv-bar__actions">' . $actions_html . '</div></header>'; // phpcs:ignore WordPress.Security.EscapeOutput -- built by callers with escaping
        // WordPress prints admin notices after the first heading; keep them below our bar.
        echo '<hr class="wp-header-end">';
    }

    public static function close(): void
    {
        echo '</div>';
    }

    /**
     * KPI cards. Each item: [label, value, url, tone] with tone in '', 'alert', 'warn'.
     *
     * @param array<int, array{0:string,1:int,2:string,3:string}> $items
     */
    public static function kpis(array $items): void
    {
        echo '<div class="kdv-kpis">';
        foreach ($items as [$label, $value, $url, $tone]) {
            printf(
                '<a class="kdv-kpi%1$s" href="%2$s"><span class="kdv-kpi__value">%3$d</span><span class="kdv-kpi__label">%4$s</span></a>',
                $tone !== '' ? ' kdv-kpi--' . esc_attr($tone) : '', esc_url($url), (int) $value, esc_html($label)
            );
        }
        echo '</div>';
    }
}
