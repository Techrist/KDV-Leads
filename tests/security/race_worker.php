<?php
// usage: php race_worker.php <ip> <mode>   (separate PROCESS = real concurrency against the same database)
$_SERVER['REMOTE_ADDR'] = $argv[1] ?? '198.51.100.55';
require __DIR__ . '/h_boot.php';
do_action('rest_api_init');
if (($argv[2] ?? '') === 'slot') {
    echo \KdvLeads\Database\FileRepository::reserve_slot($argv[3], 5) ? 'SLOT' : 'full';
    exit;
}
$req = new WP_REST_Request('POST', '/kdv/v1/leads/estimation');
$req->set_header('X-WP-Nonce', wp_create_nonce('wp_rest')); $req->set_header('Content-Type', 'application/json');
$req->set_body(json_encode(['submission_key' => wp_generate_uuid4(), 'form_token' => \KdvLeads\Http\FormToken::issue(time() - 60),
  'marque' => 'BMW', 'modele' => 'Série 3', 'annee_mise_en_circulation' => '2020', 'kilometrage' => '65000', 'motorisation' => 'diesel', 'boite' => 'automatique',
  'etat_general' => 'bon', 'etat_habitacle' => 'propre', 'accident' => 'aucun', 'suivi_entretien' => 'complet', 'delai_vente' => 'asap',
  'nom_complet' => 'Race Test', 'telephone' => '0612345678', 'consentement' => '1']));
$res = rest_do_request($req);
echo $res->get_status();
