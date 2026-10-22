<?php
namespace KdvLeads\Pdf;

use KdvLeads\Config;
use KdvLeads\Database\FileRepository;
use KdvLeads\Database\LeadRepository;
use KdvLeads\Storage\PrivateStorage;

defined('ABSPATH') || exit;

final class PdfGenerator
{
    public const CRON_HOOK = 'kdv_regenerate_pdf';
    /** Retry delays (seconds) after a failed generation. */
    private const RETRY = [600, 3600, 21600];

    public static function init(): void
    {
        add_action('kdv_lead_created', [self::class, 'on_lead_created'], 5);
        add_action(self::CRON_HOOK, [self::class, 'regenerate'], 10, 2);
    }

    public static function on_lead_created(array $lead): void
    {
        self::generate_for_lead((int) $lead['id']);
    }

    public static function regenerate(int $lead_id, int $attempt = 0): void
    {
        self::generate_for_lead($lead_id, $attempt);
    }

    /**
     * Generate and store the PDF; never throws. On failure the lead is flagged and a retry is scheduled.
     */
    public static function generate_for_lead(int $lead_id, int $attempt = 0): bool
    {
        $lead = LeadRepository::find($lead_id);
        if (!$lead) {
            return false;
        }
        try {
            $relative = self::write($lead);
            LeadRepository::update($lead_id, ['pdf_path' => $relative, 'pdf_status' => 'ok']);
            LeadRepository::log_event($lead_id, 'pdf_generated', ['path' => $relative]);
            return true;
        } catch (\Throwable $e) {
            error_log('[kdv-leads] PDF failed for lead ' . $lead_id . ': ' . $e->getMessage());
            LeadRepository::update($lead_id, ['pdf_status' => 'failed']);
            LeadRepository::log_event($lead_id, 'pdf_failed', ['error' => $e->getMessage(), 'attempt' => $attempt]);
            if (isset(self::RETRY[$attempt])) {
                wp_schedule_single_event(time() + self::RETRY[$attempt], self::CRON_HOOK, [$lead_id, $attempt + 1]);
            }
            return false;
        }
    }

    /** Render the PDF to storage and return its path relative to the private storage root. */
    public static function write(array $lead): string
    {
        $bytes = self::render($lead);

        $root = PrivateStorage::path();
        $dir = $root . '/pdf';
        if (!PrivateStorage::ensure() || (!is_dir($dir) && !wp_mkdir_p($dir))) {
            throw new \RuntimeException('Stockage privé inaccessible en écriture.');
        }
        $relative = 'pdf/' . self::filename($lead);
        if (file_put_contents($root . '/' . $relative, $bytes) === false) {
            throw new \RuntimeException('Écriture du PDF impossible.');
        }
        return $relative;
    }

    /** @return string PDF binary */
    public static function render(array $lead): string
    {
        if (!class_exists('\Mpdf\Mpdf')) {
            throw new \RuntimeException('mPDF absent (dossier vendor/ manquant).');
        }
        $tmp = PrivateStorage::path() . '/tmp';
        if (!is_dir($tmp)) {
            wp_mkdir_p($tmp);
        }

        $red = (string) Config::get('brand_red');
        $d = json_decode((string) ($lead['payload'] ?? ''), true) ?: [];
        $tz = new \DateTimeZone('Europe/Paris');
        $created_dt = (new \DateTimeImmutable((string) $lead['created_at'], new \DateTimeZone('UTC')))->setTimezone($tz);
        $created = $created_dt->format('d/m/Y \à H\hi');
        $photos = self::photos_for($lead);
        $template = (($lead['type'] ?? 'estimation') === 'recherche') ? 'recherche' : 'estimation';

        ob_start();
        include KDV_LEADS_DIR . 'includes/Pdf/templates/' . $template . '.php';
        $html = (string) ob_get_clean();

        $mpdf = new \Mpdf\Mpdf([
            'mode'          => 'utf-8',
            'format'        => 'A4',
            'orientation'   => 'P',
            'tempDir'       => $tmp,
            'default_font'  => 'dejavusans',
            'margin_left'   => 14,
            'margin_right'  => 14,
            'margin_top'    => 14,
            'margin_bottom' => 20,
            'margin_footer' => 8,
        ]);
        $mpdf->SetTitle((string) $lead['reference']);
        $mpdf->SetAuthor('KDV Motors');
        $mpdf->SetHTMLFooter(
            '<table width="100%" style="font-size:7.5pt;color:#6B6B72;border-top:0.5pt solid #C9C9CE;"><tr>'
            . '<td width="80%">' . esc_html($lead['reference']) . ' · ' . esc_html($created_dt->format('d/m/Y H:i'))
            . '<br>Document transmis par le propriétaire, informations déclaratives non vérifiées</td>'
            . '<td width="20%" style="text-align:right;">Page {PAGENO}/{nbpg}</td></tr></table>'
        );
        $mpdf->WriteHTML($html);

        return $mpdf->Output('', \Mpdf\Output\Destination::STRING_RETURN);
    }

    /** Photos of a lead for the PDF: absolute paths, in order (none for unsaved/sample leads). */
    private static function photos_for(array $lead): array
    {
        if (empty($lead['id'])) {
            return [];
        }
        $root = PrivateStorage::path();
        $out = [];
        foreach (FileRepository::for_lead((int) $lead['id']) as $i => $f) {
            $abs = $root . '/' . $f['path'];
            if (is_readable($abs)) {
                $out[] = ['path' => $abs, 'caption' => 'Photo ' . ($i + 1)];
            }
        }
        return $out;
    }

    /** KDV_ESTIMATION_20261009_0001_BMW_Serie-3.pdf */
    public static function filename(array $lead): string
    {
        $type = ($lead['type'] ?? 'estimation') === 'recherche' ? 'RECHERCHE' : 'ESTIMATION';
        $d = json_decode((string) ($lead['payload'] ?? ''), true) ?: [];
        $date = '00000000';
        $num = '0000';
        if (preg_match('/^KDV-[A-Z]{3}-(\d{8})-(\d{4})$/', (string) ($lead['reference'] ?? ''), $m)) {
            [$date, $num] = [$m[1], $m[2]];
        }
        $brand_raw = (string) ($d['marque'] ?? '');
        $model_raw = (string) ($d['modele'] ?? '');
        if (($lead['type'] ?? '') === 'recherche') {
            // First wanted vehicle, written freely ("Porsche Cayman"): first word = brand, the rest = model.
            $parts = preg_split('/\s+/', trim((string) ($d['marques_modeles'][0] ?? '')), 2);
            $brand_raw = (string) ($parts[0] ?? '');
            $model_raw = (string) ($parts[1] ?? '');
        }
        $brand = strtoupper(self::slug($brand_raw, 'Vehicule'));
        $model = self::slug($model_raw, 'Modele');
        return sprintf('KDV_%s_%s_%s_%s_%s.pdf', $type, $date, $num, $brand, $model);
    }

    public static function slug(string $s, string $fallback): string
    {
        $s = remove_accents($s);
        $s = trim((string) preg_replace('/[^A-Za-z0-9]+/', '-', $s), '-');
        $s = substr($s, 0, 30);
        return $s !== '' ? $s : $fallback;
    }
}
