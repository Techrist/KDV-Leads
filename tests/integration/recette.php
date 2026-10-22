<?php
/**
 * Automated acceptance checks, run inside a real WordPress (CLI):
 *
 *   php tests/integration/recette.php <path-to-wp-load.php> [site-url]
 *
 * Creates clearly named test leads ("RECETTE ...") and cleans nothing afterwards: run on a local/staging site.
 * E-mail checks need the dev capture mu-plugin (wp-content/kdv-dev-mails) and are skipped otherwise.
 */

$wp_load = $argv[1] ?? '';
$site = rtrim($argv[2] ?? 'http://localhost/kdv-wp', '/');
if (!is_readable($wp_load)) {
    fwrite(STDERR, "Usage: php recette.php /path/to/wp-load.php [site-url]\n");
    exit(2);
}
$_SERVER['HTTP_HOST'] = parse_url($site, PHP_URL_HOST) ?: 'localhost';
$_SERVER['REQUEST_URI'] = '/';
$_SERVER['REMOTE_ADDR'] = '198.51.100.' . random_int(1, 250);
require $wp_load;
do_action('rest_api_init');

use KdvLeads\Database\LeadRepository;
use KdvLeads\Storage\PrivateStorage;

global $wpdb;
$results = ['pass' => 0, 'fail' => 0, 'skip' => 0];
$check = static function (string $name, bool $ok, string $detail = '') use (&$results): void {
    $results[$ok ? 'pass' : 'fail']++;
    echo ($ok ? '  PASS ' : '  FAIL ') . $name . ($detail !== '' && !$ok ? "  [$detail]" : '') . "\n";
};
$skip = static function (string $name, string $why) use (&$results): void {
    $results['skip']++;
    echo "  SKIP $name ($why)\n";
};

$submit = static function (array $over, string $type = 'estimation'): array {
    $base = $type === 'estimation' ? [
        'marque' => 'BMW', 'modele' => 'Série 3', 'finition' => 'M Sport', 'annee_mise_en_circulation' => '2020', 'kilometrage' => '65 000',
        'motorisation' => 'diesel', 'boite' => 'automatique', 'etat_general' => 'bon', 'etat_habitacle' => 'propre', 'accident' => 'aucun',
        'suivi_entretien' => 'complet', 'equipements' => ['gps'], 'delai_vente' => 'asap', 'nom_complet' => 'RECETTE Test',
        'telephone' => '06 12 34 56 78', 'consentement' => '1',
    ] : [
        'marques_modeles' => ['Porsche Cayman'], 'budget_max' => '90 000', 'prenom' => 'RECETTE', 'nom' => 'Recherche', 'telephone' => '0611223344',
        'email' => 'recette.recherche@example.com', 'code_postal' => '75008', 'consentement' => '1',
    ];
    $req = new WP_REST_Request('POST', '/kdv/v1/leads/' . $type);
    $req->set_header('X-WP-Nonce', wp_create_nonce('wp_rest'));
    $req->set_header('Content-Type', 'application/json');
    $req->set_body(json_encode(array_merge($base, ['submission_key' => wp_generate_uuid4(), 'elapsed_ms' => 30000], $over)));
    $res = rest_do_request($req);
    return [$res->get_status(), $res->get_data()];
};
$clear_limits = static function () use ($wpdb): void {
    $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '%kdv_rl_%'");
};
$mail_dir = WP_CONTENT_DIR . '/kdv-dev-mails';
$mails_available = is_dir($mail_dir) && file_exists(WP_CONTENT_DIR . '/mu-plugins/kdv-dev-mail.php');
$mails_since = static function (int $t) use ($mail_dir): array {
    $out = [];
    foreach (glob($mail_dir . '/*.txt') ?: [] as $f) {
        if (filemtime($f) >= $t) {
            $out[] = (string) file_get_contents($f);
        }
    }
    return $out;
};

echo "== 1. Installation\n";
foreach (['leads', 'lead_files', 'email_queue', 'lead_events', 'counters', 'upload_tokens'] as $t) {
    $check("table kdv_$t", $wpdb->get_var("SHOW TABLES LIKE '{$wpdb->prefix}kdv_$t'") !== null);
}
foreach (['vendre-mon-vehicule', 'estimation-merci', 'recherche-personnalisee', 'recherche-merci'] as $slug) {
    $check("page /$slug", (bool) get_page_by_path($slug));
}
$check('role kdv_gestionnaire', (bool) get_role('kdv_gestionnaire'));
$check('mPDF available', class_exists('\Mpdf\Mpdf'));
$check('private storage writable', PrivateStorage::ensure());

echo "== 2. Estimation submission\n";
$clear_limits();
$t0 = time();
$key = wp_generate_uuid4();
[$st, $data] = $submit(['submission_key' => $key, 'email' => 'recette.client@example.com', 'nom_complet' => 'RECETTE Client']);
$check('201 + reference', $st === 201 && preg_match('/^KDV-EST-\d{8}-\d{4}$/', $data['reference'] ?? '') === 1, json_encode($data));
$lead = LeadRepository::find_by_submission_key($key);
$check('lead saved as "nouveau"', $lead && $lead['status'] === 'nouveau' && $lead['phone'] === '+33612345678');
$check('PDF generated', $lead && $lead['pdf_status'] === 'ok' && is_readable(PrivateStorage::path() . '/' . $lead['pdf_path']));
$check('PDF name pattern', $lead && preg_match('/^pdf\/KDV_ESTIMATION_\d{8}_\d{4}_BMW_Serie-3\.pdf$/', $lead['pdf_path']) === 1, (string) ($lead['pdf_path'] ?? ''));
[$st2, $data2] = $submit(['submission_key' => $key]);
$check('same key = same lead (idempotent)', $st2 === 200 && $data2['reference'] === $data['reference']);
$check('only one row for the key', (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->prefix}kdv_leads WHERE submission_key = %s", $key)) === 1);

if ($mails_available) {
    $m = $mails_since($t0);
    $admin = array_values(array_filter($m, static fn ($x) => strpos($x, 'SUBJECT: 🔴 [KDV ESTIMATION]') !== false));
    $ack = array_values(array_filter($m, static fn ($x) => strpos($x, 'TO: recette.client@example.com') !== false));
    $check('KDV notification sent with PDF attached', $admin && strpos($admin[0], 'ATTACHMENTS: KDV_ESTIMATION_') !== false);
    $check('Reply-To = customer e-mail', $admin && strpos($admin[0], 'Reply-To: recette.client@example.com') !== false);
    $check('client acknowledgement sent', (bool) $ack);
} else {
    $skip('e-mail checks', 'dev mail capture not installed');
}
$before = count($mails_available ? $mails_since($t0) : []);
$clear_limits();
[$st3] = $submit(['nom_complet' => 'RECETTE SansMail', 'email' => '']);
$check('no e-mail given: lead saved', $st3 === 201);
if ($mails_available) {
    $acks = array_filter($mails_since($t0), static fn ($x) => strpos($x, 'SUBJECT: Votre demande') !== false && strpos($x, 'SansMail') !== false);
    $check('no acknowledgement without e-mail', count($acks) === 0);
}

echo "== 3. Validation and anti-spam\n";
$clear_limits();
[$st] = $submit(['telephone' => '12', 'consentement' => '']);
$check('invalid phone + missing consent = 422', $st === 422);
[$st] = $submit(['delai_vente' => '']);
$check('missing sale timeframe = 422', $st === 422);
[$st] = $submit(['website' => 'http://spam']);
$check('honeypot = 400', $st === 400);
[$st] = $submit(['elapsed_ms' => 500]);
$check('submitted too fast = 400', $st === 400);
$bad = new WP_REST_Request('POST', '/kdv/v1/leads/estimation');
$bad->set_header('X-WP-Nonce', 'bad');
$check('bad nonce = 403', rest_do_request($bad)->get_status() === 403);

echo "== 4. Admin list priority order\n";
$clear_limits();
$mk = [];
foreach (['renseigne' => 'R-RENSEIGNE', '3_mois' => 'R-3MOIS', '1_mois' => 'R-1MOIS', 'asap' => 'R-ASAP'] as $d => $name) {
    $clear_limits();
    [$s, $dd] = $submit(['delai_vente' => $d, 'nom_complet' => "RECETTE $name"]);
    $mk[$name] = $dd['reference'] ?? '';
}
$res = LeadRepository::search('estimation', ['per_page' => 200, 'search' => '']);
$order = array_values(array_filter(array_column($res['items'], 'full_name'), static fn ($n) => strpos($n, 'RECETTE R-') === 0));
// Newest first inside the same urgency: take the first occurrence of each of our four names.
$seen = [];
foreach ($order as $n) { $seen[$n] ??= count($seen); }
$check('asap < 1 mois < 3 mois < renseigne', ($seen['RECETTE R-ASAP'] ?? 9) < ($seen['RECETTE R-1MOIS'] ?? 9) && ($seen['RECETTE R-1MOIS'] ?? 9) < ($seen['RECETTE R-3MOIS'] ?? 9) && ($seen['RECETTE R-3MOIS'] ?? 9) < ($seen['RECETTE R-RENSEIGNE'] ?? 9), json_encode($seen));
$id_lost = (int) $wpdb->get_var("SELECT id FROM {$wpdb->prefix}kdv_leads WHERE full_name = 'RECETTE R-ASAP' ORDER BY id DESC LIMIT 1");
LeadRepository::set_status($id_lost, 'perdu');
$res = LeadRepository::search('estimation', ['per_page' => 200]);
$pos_lost = array_search($id_lost, array_map('intval', array_column($res['items'], 'id')), true);
$check('closed leads sink to the end', $pos_lost !== false && $pos_lost > 0 && $res['items'][0]['status'] !== 'perdu');

echo "== 5. Separation of journeys\n";
$clear_limits();
[$st, $data] = $submit([], 'recherche');
$check('recherche submission', $st === 201 && strpos($data['reference'] ?? '', 'KDV-REC-') === 0, json_encode($data));
$est = LeadRepository::search('estimation', ['per_page' => 200, 's' => 'Cayman']);
$rec = LeadRepository::search('recherche', ['per_page' => 200, 's' => 'Cayman']);
$check('recherche lead absent from estimations', $est['total'] === 0 && $rec['total'] >= 1);

echo "== 6. Private storage\n";
$url = PrivateStorage::public_url();
if ($url) {
    $probe = PrivateStorage::path() . '/recette-probe.txt';
    file_put_contents($probe, 'x');
    $r = wp_remote_get($url . '/recette-probe.txt', ['timeout' => 8, 'sslverify' => false]);
    @unlink($probe);
    $code = is_wp_error($r) ? 0 : (int) wp_remote_retrieve_response_code($r);
    $check('file inside storage is not publicly readable (403/404)', in_array($code, [401, 403, 404], true), "HTTP $code");
    if ($lead) {
        $r = wp_remote_get($url . '/' . $lead['pdf_path'], ['timeout' => 8, 'sslverify' => false]);
        $code = is_wp_error($r) ? 0 : (int) wp_remote_retrieve_response_code($r);
        $check('direct URL to the PDF is refused', in_array($code, [401, 403, 404], true), "HTTP $code");
    }
} else {
    $skip('public access test', 'storage is outside the web root');
}

echo "== 7. GDPR\n";
$x = \KdvLeads\Privacy\Privacy::export('recette.client@example.com');
$check('export by e-mail finds the lead', count($x['data']) >= 1);
\KdvLeads\Privacy\Privacy::erase('recette.client@example.com');
$gone = LeadRepository::find_by_submission_key($key);
$check('erase anonymises and removes the PDF', $gone['full_name'] === 'Anonymisé' && $gone['phone'] === '' && !is_readable(PrivateStorage::path() . '/' . $lead['pdf_path']));

echo "\nResult: {$results['pass']} passed, {$results['fail']} failed, {$results['skip']} skipped\n";
exit($results['fail'] ? 1 : 0);
