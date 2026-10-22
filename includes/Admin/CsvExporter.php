<?php
namespace KdvLeads\Admin;

use KdvLeads\Database\LeadRepository;
use KdvLeads\FormOptions as O;
use KdvLeads\LeadStatuses;

defined('ABSPATH') || exit;

final class CsvExporter
{
    /** Neutralise spreadsheet formulas (CSV injection): prefix risky cells with an apostrophe. */
    public static function cell($v): string
    {
        $s = (string) $v;
        if ($s !== '' && strpos("=+-@\t\r", $s[0]) !== false) {
            return "'" . $s;
        }
        return $s;
    }

    public static function headers(): array
    {
        return ['Référence', 'Date', 'Statut', 'Délai de vente', 'Nom et prénom', 'Téléphone', 'E-mail', 'Immatriculation', 'Marque', 'Modèle',
            'Finition', 'Année', 'Kilométrage', 'Motorisation', 'Boîte', 'État général', 'Habitacle', 'Accident', 'Suivi d\'entretien',
            'Équipements', 'Rappel prévu', 'Source', 'Consentement données', 'Consentement marketing'];
    }

    public static function row(array $l): array
    {
        $d = json_decode((string) $l['payload'], true) ?: [];
        $name = static fn (array $map, $k) => isset($map[$k]) ? (is_array($map[$k]) ? $map[$k][0] : $map[$k]) : '';
        $eq = [];
        foreach ((array) ($d['equipements'] ?? []) as $k) {
            $all = O::equipements();
            if (isset($all[$k])) {
                $eq[] = $all[$k][0];
            }
        }
        if (!empty($d['autre_equipement'])) {
            $eq[] = $d['autre_equipement'];
        }
        $paris = new \DateTimeZone('Europe/Paris');
        $fmt = static fn ($utc) => $utc ? (new \DateTimeImmutable($utc, new \DateTimeZone('UTC')))->setTimezone(new \DateTimeZone('Europe/Paris'))->format('d/m/Y H:i') : '';
        $src = json_decode((string) $l['source'], true) ?: [];

        return [
            $l['reference'], $fmt($l['created_at']), LeadStatuses::label($l['type'], $l['status']),
            $name(O::delais(), $l['sale_timeframe']), $l['full_name'], $l['phone'], $l['email'], $l['plate'],
            $d['marque'] ?? '', $d['modele'] ?? '', $d['finition'] ?? '', $l['vehicle_year'], $l['mileage'],
            $name(O::motorisations(), $d['motorisation'] ?? ''), $name(O::boites(), $d['boite'] ?? ''),
            $name(O::etat_general(), $d['etat_general'] ?? ''), $name(O::etat_habitacle(), $d['etat_habitacle'] ?? ''),
            $name(O::accident(), $d['accident'] ?? ''), $name(O::suivi_entretien(), $d['suivi_entretien'] ?? ''),
            implode(', ', $eq), $fmt($l['next_callback_at']),
            implode(' / ', array_filter([$src['utm_source'] ?? '', $src['utm_medium'] ?? '', $src['utm_campaign'] ?? ''])),
            $l['data_consent'] ? 'Oui' : 'Non', $l['marketing_consent'] ? 'Oui' : 'Non',
        ];
    }

    /** Streams the export of the current filters. UTF-8 with BOM, ';' separator. */
    public static function stream(string $type, array $filters): void
    {
        $rows = [];
        $page = 1;
        do {
            $res = LeadRepository::search($type, $filters + ['per_page' => 200, 'page' => $page]);
            foreach ($res['items'] as $l) {
                $rows[] = $l;
            }
            $page++;
        } while (count($rows) < $res['total'] && $res['items']);

        LeadRepository::log_event(0, 'csv_export', ['type' => $type, 'filters' => $filters, 'rows' => count($rows)], get_current_user_id());

        nocache_headers();
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="KDV_' . strtoupper($type) . '_' . wp_date('Ymd_His') . '.csv"');
        header('X-Content-Type-Options: nosniff');
        $out = fopen('php://output', 'w');
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, array_map([self::class, 'cell'], self::headers()), ';');
        foreach ($rows as $l) {
            fputcsv($out, array_map([self::class, 'cell'], self::row($l)), ';');
        }
        fclose($out);
        exit;
    }
}
