<?php
namespace KdvLeads\Frontend;

use KdvLeads\Config;
use KdvLeads\FormOptions;

defined('ABSPATH') || exit;

final class Shortcodes
{
    private const TAGS = ['kdv_estimation', 'kdv_estimation_merci', 'kdv_recherche', 'kdv_recherche_merci'];

    public static function init(): void
    {
        add_shortcode('kdv_estimation', [self::class, 'estimation']);
        add_shortcode('kdv_estimation_merci', [self::class, 'estimation_merci']);
        add_shortcode('kdv_recherche', [self::class, 'recherche']);
        add_shortcode('kdv_recherche_merci', [self::class, 'recherche_merci']);
        add_action('wp_enqueue_scripts', [self::class, 'assets']);
        add_filter('wp_robots', [self::class, 'robots']);
        add_filter('get_pages', [self::class, 'hide_from_page_lists'], 10, 1);
    }

    /**
     * The search page is reached by direct link only and the thank-you pages are never navigation targets:
     * keep them out of automatic page lists (themes that list every page in the menu).
     */
    public static function hide_from_page_lists(array $pages): array
    {
        if (is_admin() || (defined('REST_REQUEST') && REST_REQUEST)) {
            return $pages;
        }
        return array_values(array_filter($pages, static function ($p): bool {
            return !($p instanceof \WP_Post)
                || !(has_shortcode($p->post_content, 'kdv_recherche')
                    || has_shortcode($p->post_content, 'kdv_recherche_merci')
                    || has_shortcode($p->post_content, 'kdv_estimation_merci'));
        }));
    }

    private static function page_has_shortcode(string ...$tags): bool
    {
        $post = get_post();
        if (!$post instanceof \WP_Post) {
            return false;
        }
        foreach ($tags as $tag) {
            if (has_shortcode($post->post_content, $tag)) {
                return true;
            }
        }
        return false;
    }

    public static function assets(): void
    {
        if (!is_singular() || !self::page_has_shortcode(...self::TAGS)) {
            return;
        }
        $ver = KDV_LEADS_VERSION;
        $red = (string) Config::get('brand_red');
        wp_enqueue_style('kdv-forms', KDV_LEADS_URL . 'assets/css/kdv-forms.css', [], $ver);
        if (preg_match('/^#[0-9A-Fa-f]{6}$/', $red)) {
            wp_add_inline_style('kdv-forms', '.kdv-app{--kdv-red:' . $red . ';}');
        }

        if (self::page_has_shortcode('kdv_estimation_merci', 'kdv_recherche_merci')) {
            wp_enqueue_script('kdv-thanks', KDV_LEADS_URL . 'assets/js/kdv-thanks.js', [], $ver, true);
        }

        wp_add_inline_style(
            'kdv-forms',
            '.kdv-app,.kdv-hero{'
            . "--kdv-ill-suv:url('" . esc_url_raw(KDV_LEADS_URL . 'assets/img/ill-suv.webp') . "');"
            . "--kdv-ill-wheel:url('" . esc_url_raw(KDV_LEADS_URL . 'assets/img/ill-wheel.webp') . "');"
            . "--kdv-ill-m5:url('" . esc_url_raw(KDV_LEADS_URL . 'assets/img/ill-m5.webp') . "');"
            . '}'
        );

        $is_estimation = self::page_has_shortcode('kdv_estimation');
        $is_recherche = self::page_has_shortcode('kdv_recherche');
        if (!$is_estimation && !$is_recherche) {
            return;
        }

        wp_enqueue_script('kdv-form', KDV_LEADS_URL . 'assets/js/kdv-form.js', [], $ver, true);
        if (Config::get('turnstile_site_key')) {
            wp_enqueue_script('kdv-turnstile', 'https://challenges.cloudflare.com/turnstile/v0/api.js', [], null, ['strategy' => 'defer', 'in_footer' => true]);
        }

        $config = [
            'nonce'          => wp_create_nonce('wp_rest'),
            'homeUrl'        => esc_url_raw(home_url('/')),
            'consentVersion' => (string) Config::get('consent_text_version'),
        ];
        if ($is_estimation) {
            wp_enqueue_script('kdv-estimation', KDV_LEADS_URL . 'assets/js/kdv-estimation.js', ['kdv-form'], $ver, true);
            wp_enqueue_script('kdv-faq', KDV_LEADS_URL . 'assets/js/kdv-faq.js', [], $ver, true);
            $config += [
                'endpoint'  => esc_url_raw(rest_url('kdv/v1/leads/estimation')),
                'thanksUrl' => esc_url_raw(home_url('/estimation-merci')),
                'vehicles'  => FormOptions::vehicles(),
            ];
        } else {
            wp_enqueue_script('kdv-uploader', KDV_LEADS_URL . 'assets/js/kdv-uploader.js', ['kdv-form'], $ver, true);
            wp_enqueue_script('kdv-recherche', KDV_LEADS_URL . 'assets/js/kdv-recherche.js', ['kdv-form', 'kdv-uploader'], $ver, true);
            $config += [
                'endpoint'     => esc_url_raw(rest_url('kdv/v1/leads/recherche')),
                'thanksUrl'    => esc_url_raw(home_url('/recherche-merci')),
                'uploadBase'   => esc_url_raw(rest_url('kdv/v1')),
                'maxPhotos'    => 5,
                'maxPhotoMb'   => (int) Config::get('max_photo_mb'),
            ];
        }
        wp_add_inline_script('kdv-form', 'window.KDV_CONFIG = ' . wp_json_encode($config) . ';', 'before');
    }

    /** Thank-you pages are never indexed; the search page follows the noindex option. */
    public static function robots(array $robots): array
    {
        $noindex = self::page_has_shortcode('kdv_estimation_merci', 'kdv_recherche_merci')
            || (Config::get('noindex_recherche') && self::page_has_shortcode('kdv_recherche'));
        if ($noindex) {
            $robots['noindex'] = true;
            $robots['follow'] = true;
        }
        return $robots;
    }

    public static function estimation(): string
    {
        return self::render('estimation-form', ['faq' => self::public_faq()]);
    }

    public static function estimation_merci(): string
    {
        return self::render('merci', ['kind' => 'estimation']);
    }

    public static function recherche(): string
    {
        return self::render('recherche-form', []);
    }

    public static function recherche_merci(): string
    {
        return self::render('merci', ['kind' => 'recherche']);
    }

    /**
     * FAQ entries still carrying an [À FOURNIR] placeholder are never published.
     *
     * @return array<int, array{q:string,a:string}>
     */
    public static function public_faq(): array
    {
        $out = [];
        foreach ((array) Config::get('faq') as $item) {
            $q = trim((string) ($item['q'] ?? ''));
            $a = trim((string) ($item['a'] ?? ''));
            if ($q === '' || $a === '' || stripos($q . $a, '[À FOURNIR') !== false) {
                continue;
            }
            $out[] = ['q' => $q, 'a' => $a];
        }
        return $out;
    }

    private static function render(string $view, array $vars): string
    {
        $file = KDV_LEADS_DIR . 'includes/Frontend/views/' . $view . '.php';
        if (!is_readable($file)) {
            return '';
        }
        $settings = Config::all();
        extract($vars, EXTR_SKIP);
        ob_start();
        include $file;
        return (string) ob_get_clean();
    }
}
