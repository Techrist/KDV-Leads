<?php
namespace KdvLeads\Admin;

use KdvLeads\Database\LeadRepository;
use KdvLeads\FormOptions;
use KdvLeads\LeadStatuses;
use KdvLeads\Mail\MessageBuilder;

defined('ABSPATH') || exit;

if (!class_exists('WP_List_Table')) {
    require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

final class LeadListTable extends \WP_List_Table
{
    private string $type;
    private string $page_slug;

    public function __construct(string $type, string $page_slug)
    {
        parent::__construct(['singular' => 'dossier', 'plural' => 'leads', 'ajax' => false]);
        $this->type = $type;
        $this->page_slug = $page_slug;
    }

    /** Current filters from the query string (sanitised). */
    public function filters(): array
    {
        $g = static fn ($k) => isset($_GET[$k]) ? sanitize_text_field(wp_unslash($_GET[$k])) : ''; // phpcs:ignore WordPress.Security.NonceVerification
        $status = $g('status');
        $delai = $g('delai');
        return [
            'status'  => array_key_exists($status, LeadStatuses::for_type($this->type)) ? $status : '',
            'delai'   => array_key_exists($delai, FormOptions::delais()) ? $delai : '',
            'from'    => $g('from'),
            'to'      => $g('to'),
            's'       => $g('s'),
            'orderby' => $g('orderby'),
            'order'   => $g('order'),
            'callback' => $g('callback') === 'due' ? 'due' : '',
            'open'    => $g('open') === '1' ? '1' : '',
        ];
    }

    public function get_columns(): array
    {
        $cols = ['cb' => '<input type="checkbox">', 'reference' => 'Référence', 'date' => 'Date'];
        if ($this->type === 'estimation') {
            $cols['delai'] = 'Délai de vente';
        }
        $cols += ['vehicle' => 'Véhicule'];
        if ($this->type === 'estimation') {
            $cols['km'] = 'Km';
        } else {
            $cols['budget'] = 'Budget max';
        }
        $cols += ['name' => 'Nom', 'phone' => 'Téléphone'];
        if ($this->type === 'estimation') {
            $cols['callback'] = 'Rappel prévu';
        }
        return $cols + ['source' => 'Source', 'status' => 'Statut'];
    }

    protected function get_sortable_columns(): array
    {
        return ['reference' => ['reference', false], 'date' => ['date', true], 'km' => ['km', false], 'name' => ['name', false]];
    }

    protected function get_bulk_actions(): array
    {
        $a = [];
        foreach (LeadStatuses::for_type($this->type) as $k => $label) {
            $a['status_' . $k] = 'Statut → ' . $label;
        }
        return $a;
    }

    protected function get_views(): array
    {
        global $wpdb;
        $t = \KdvLeads\Database\Schema::table('leads');
        $counts = $wpdb->get_results($wpdb->prepare("SELECT status, COUNT(*) n FROM `$t` WHERE type = %s GROUP BY status", $this->type), OBJECT_K); // phpcs:ignore WordPress.DB
        $current = $this->filters()['status'];
        $base = admin_url('admin.php?page=' . $this->page_slug);
        $total = array_sum(array_map(static fn ($o) => (int) $o->n, $counts));

        $views = ['all' => sprintf('<a href="%s"%s>Tous <span class="count">(%d)</span></a>', esc_url($base), $current === '' ? ' class="current"' : '', $total)];
        foreach (LeadStatuses::for_type($this->type) as $k => $label) {
            $n = isset($counts[$k]) ? (int) $counts[$k]->n : 0;
            $views[$k] = sprintf('<a href="%s"%s>%s <span class="count">(%d)</span></a>', esc_url(add_query_arg('status', $k, $base)), $current === $k ? ' class="current"' : '', esc_html($label), $n);
        }
        return $views;
    }

    protected function extra_tablenav($which): void
    {
        if ($which !== 'top') {
            return;
        }
        $f = $this->filters();
        echo '<div class="alignleft actions">';
        if ($this->type === 'estimation') {
            echo '<select name="delai"><option value="">Tous les délais</option>';
            foreach (FormOptions::delais() as $k => $d) {
                printf('<option value="%s"%s>%s</option>', esc_attr($k), selected($f['delai'], $k, false), esc_html($d[0]));
            }
            echo '</select>';
        }
        printf(' Du <input type="date" name="from" value="%s"> au <input type="date" name="to" value="%s"> ', esc_attr($f['from']), esc_attr($f['to']));
        submit_button('Filtrer', '', 'filter_action', false);

        $export = wp_nonce_url(add_query_arg(array_merge(['action' => 'kdv_export_csv', 'type' => $this->type], array_filter([
            'status' => $f['status'], 'delai' => $f['delai'], 'from' => $f['from'], 'to' => $f['to'], 's' => $f['s'],
        ])), admin_url('admin-post.php')), 'kdv_export_csv');
        echo ' <a class="button" href="' . esc_url($export) . '">Exporter en CSV</a></div>';
    }

    public function no_items(): void
    {
        echo 'Aucun dossier.';
    }

    public function prepare_items(): void
    {
        $this->process_bulk();
        $f = $this->filters();
        $per_page = 20;
        $res = LeadRepository::search($this->type, $f + ['per_page' => $per_page, 'page' => $this->get_pagenum()]);
        $this->items = $res['items'];
        $this->set_pagination_args(['total_items' => $res['total'], 'per_page' => $per_page]);
        $this->_column_headers = [$this->get_columns(), [], $this->get_sortable_columns(), 'reference'];
    }

    private function process_bulk(): void
    {
        $action = $this->current_action();
        if (!$action || strpos($action, 'status_') !== 0) {
            return;
        }
        check_admin_referer('bulk-' . $this->_args['plural']);
        if (!current_user_can(\KdvLeads\Config::CAP_LEADS)) {
            wp_die('Accès refusé.', 403);
        }
        $status = substr($action, 7);
        $ids = isset($_REQUEST['lead_ids']) ? array_map('intval', (array) wp_unslash($_REQUEST['lead_ids'])) : [];
        foreach ($ids as $id) {
            $lead = LeadRepository::find($id);
            if ($lead && $lead['type'] === $this->type) {
                LeadRepository::set_status($id, $status, get_current_user_id());
            }
        }
        wp_safe_redirect(remove_query_arg(['action', 'action2', 'lead_ids', '_wpnonce', '_wp_http_referer', 'filter_action']));
        exit;
    }

    protected function column_cb($item): string
    {
        return '<input type="checkbox" name="lead_ids[]" value="' . (int) $item['id'] . '">';
    }

    protected function column_reference($item): string
    {
        $url = admin_url('admin.php?page=' . $this->page_slug . '&lead=' . (int) $item['id']);
        $actions = ['open' => '<a href="' . esc_url($url) . '">Ouvrir</a>'];
        if ($this->type === 'estimation') {
            foreach (['a_rappeler' => 'À rappeler', 'contacte' => 'Contacté'] as $st => $label) {
                if ($item['status'] !== $st) {
                    $link = wp_nonce_url(admin_url('admin-post.php?action=kdv_quick_status&lead=' . (int) $item['id'] . '&status=' . $st . '&back=' . rawurlencode($this->page_slug)), 'kdv_quick_status_' . (int) $item['id']);
                    $actions[$st] = '<a href="' . esc_url($link) . '">' . esc_html($label) . '</a>';
                }
            }
        }
        return '<strong><a href="' . esc_url($url) . '">' . esc_html($item['reference']) . '</a></strong>' . $this->row_actions($actions);
    }

    protected function column_date($item): string
    {
        $dt = new \DateTimeImmutable($item['created_at'], new \DateTimeZone('UTC'));
        return esc_html($dt->setTimezone(new \DateTimeZone('Europe/Paris'))->format('d/m/Y H:i'));
    }

    protected function column_delai($item): string
    {
        return self::delai_badge((string) $item['sale_timeframe']);
    }

    public static function delai_badge(string $key): string
    {
        $all = FormOptions::delais();
        if (!isset($all[$key])) {
            return '—';
        }
        $cls = $key === 'asap' ? 'kdv-badge--asap' : ($key === '1_mois' ? 'kdv-badge--month' : 'kdv-badge--low');
        return '<span class="kdv-badge ' . $cls . '">' . esc_html($all[$key][0]) . '</span>';
    }

    protected function column_vehicle($item): string
    {
        return esc_html($item['vehicle_label']) . ($item['vehicle_year'] ? ' <span class="description">(' . (int) $item['vehicle_year'] . ')</span>' : '');
    }

    protected function column_km($item): string
    {
        return $item['mileage'] !== null ? esc_html(MessageBuilder::km((int) $item['mileage'])) . ' km' : '—';
    }

    protected function column_budget($item): string
    {
        return $item['budget_max'] ? esc_html(number_format((int) $item['budget_max'], 0, ',', ' ')) . ' €' : '—';
    }

    protected function column_name($item): string
    {
        return esc_html($item['full_name']);
    }

    protected function column_phone($item): string
    {
        return '<a href="tel:' . esc_attr($item['phone']) . '">' . esc_html($item['phone']) . '</a>';
    }

    /** Planned call-back: red when overdue on an open lead. */
    protected function column_callback($item): string
    {
        if (empty($item['next_callback_at'])) {
            return '<span class="description">—</span>';
        }
        $ts = (new \DateTimeImmutable($item['next_callback_at'], new \DateTimeZone('UTC')))->getTimestamp();
        $label = wp_date('d/m H:i', $ts);
        $open = !in_array($item['status'], LeadStatuses::closed(), true);
        $cls = $open && $ts <= time() ? 'kdv-badge--due' : 'kdv-badge--soon';
        return '<span class="kdv-badge ' . $cls . '">' . ($open && $ts <= time() ? '⏰ ' : '') . esc_html($label) . '</span>';
    }

    protected function column_source($item): string
    {
        $s = json_decode((string) $item['source'], true) ?: [];
        $bits = array_filter([$s['utm_source'] ?? '', $s['utm_medium'] ?? '']);
        return esc_html($bits ? implode(' / ', $bits) : 'Direct');
    }

    protected function column_status($item): string
    {
        $closed = in_array($item['status'], LeadStatuses::closed(), true) ? ' kdv-badge--closed' : '';
        return '<span class="kdv-badge kdv-badge--status' . $closed . '">' . esc_html(LeadStatuses::label($this->type, $item['status'])) . '</span>';
    }

    protected function column_default($item, $column_name): string
    {
        return '';
    }
}
