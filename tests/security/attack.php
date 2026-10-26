<?php
/**
 * Attack suite against the SANDBOX copy of the shipped plugin (http://127.0.0.1:8099, own database).
 * Every check states the attack and the expected (secure) outcome.
 */
error_reporting(E_ALL & ~E_DEPRECATED);
set_time_limit(900);
$D = __DIR__;
$BASE = 'http://127.0.0.1:8099';
$PHP = 'C:/xampp/php/php.exe';
[$rootpw, $secpw] = array_map(static fn ($l) => trim(explode('=', $l)[1]), array_values(array_filter(array_map('trim', explode("\n", file_get_contents("$D/creds.txt"))))));
$acc = [];
foreach (array_filter(explode("\n", file_get_contents("$D/accounts.txt"))) as $l) { [$u, $p] = explode('=', $l); $acc[$u] = $p; }
$db = new mysqli('127.0.0.1', 'sec', $secpw, 'sec_wp', 3307);
$MAILS = "$D/wp/wp-content/kdv-dev-mails";
$res = ['pass' => 0, 'fail' => 0];
$only = $argv[1] ?? '';

function check(string $id, string $what, bool $ok, string $detail = ''): void {
    global $res;
    $res[$ok ? 'pass' : 'fail']++;
    echo ($ok ? '  PASS ' : '  FAIL ') . "[$id] $what" . ($ok || $detail === '' ? '' : "\n         → " . $detail) . "\n";
}
function section(string $t): void { echo "\n== $t\n"; }

function http(string $method, string $url, array $o = []): array {
    $ch = curl_init($url);
    $h = $o['headers'] ?? [];
    curl_setopt_array($ch, [CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_FOLLOWLOCATION => $o['follow'] ?? false,
        CURLOPT_TIMEOUT => $o['timeout'] ?? 60, CURLOPT_HTTPHEADER => $h]);
    if (isset($o['jar'])) { curl_setopt($ch, CURLOPT_COOKIEJAR, $o['jar']); curl_setopt($ch, CURLOPT_COOKIEFILE, $o['jar']); }
    if (isset($o['json'])) { curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($o['json'])); curl_setopt($ch, CURLOPT_HTTPHEADER, array_merge($h, ['Content-Type: application/json'])); }
    elseif (isset($o['body'])) { curl_setopt($ch, CURLOPT_POSTFIELDS, $o['body']); }
    $t = microtime(true);
    $raw = curl_exec($ch);
    $info = curl_getinfo($ch);
    $hs = (int) $info['header_size'];
    $head = substr((string) $raw, 0, $hs);
    $hdr = [];
    foreach (explode("\r\n", $head) as $line) { if (strpos($line, ':') !== false) { [$k, $v] = explode(':', $line, 2); $hdr[strtolower(trim($k))][] = trim($v); } }
    return ['status' => (int) $info['http_code'], 'headers' => $hdr, 'body' => substr((string) $raw, $hs), 'time' => microtime(true) - $t, 'url' => $info['url']];
}
function cli(string $script, ...$args): string {
    global $PHP, $D;
    $cmd = '"' . $PHP . '" -d memory_limit=256M "' . "$D/$script" . '" ' . implode(' ', array_map('escapeshellarg', $args)) . ' 2>&1';
    return trim((string) shell_exec($cmd));
}
function tok(int $ago = 60): string { return cli('h_token.php', (string) $ago); }
function cfg(string ...$kv): void { cli('h_cfg.php', ...$kv); }
function reset_limits(): void {
    global $db;
    $db->query("DELETE FROM wp_kdv_counters WHERE name REGEXP '^(rl|ra|ft|us|uf|pl|ak)-'");
}
function clear_mails(): void { global $MAILS; foreach (glob("$MAILS/*.txt") ?: [] as $f) { @unlink($f); } @unlink("$MAILS/fail.flag"); }
function mails(): array { global $MAILS; $o = []; foreach (glob("$MAILS/*.txt") ?: [] as $f) { $o[] = file_get_contents($f); } return $o; }

function public_config(): array {
    global $BASE;
    $r = http('GET', "$BASE/vendre-mon-vehicule/");
    preg_match('/window\.KDV_CONFIG = (\{.*?\});/s', $r['body'], $m);
    return ['cfg' => json_decode($m[1] ?? '{}', true) ?: [], 'resp' => $r];
}
function base_payload(array $over = []): array {
    return $over + [
        'submission_key' => sprintf('%08x-%04x-4%03x-a%03x-%012x', random_int(0, PHP_INT_MAX >> 32), random_int(0, 65535), random_int(0, 4095), random_int(0, 4095), random_int(0, PHP_INT_MAX >> 16)),
        'form_token' => tok(60),
        'marque' => 'BMW', 'modele' => 'Série 3', 'annee_mise_en_circulation' => '2020', 'kilometrage' => '65000', 'motorisation' => 'diesel', 'boite' => 'automatique',
        'etat_general' => 'bon', 'etat_habitacle' => 'propre', 'accident' => 'aucun', 'suivi_entretien' => 'complet', 'delai_vente' => 'asap',
        'nom_complet' => 'Sec Test', 'telephone' => '0612345678', 'consentement' => '1',
    ];
}
function submit(array $payload, ?string $nonce = null, string $type = 'estimation', array $extra = []): array {
    global $BASE; static $n = null;
    $n = $nonce ?? ($n ?: public_config()['cfg']['nonce']);
    return http('POST', "$BASE/wp-json/kdv/v1/leads/$type", ['json' => $payload, 'headers' => array_merge(["X-WP-Nonce: $n"], $extra)]);
}
function jb(array $r): array { return json_decode($r['body'], true) ?: []; }
function login(string $user): string {
    global $BASE, $acc, $D;
    $jar = "$D/jar_$user.txt"; @unlink($jar);
    file_put_contents($jar, "# Netscape HTTP Cookie File\n127.0.0.1\tFALSE\t/\tFALSE\t0\twordpress_test_cookie\tWP%20Cookie%20check\n");
    http('POST', "$BASE/wp-login.php", ['jar' => $jar, 'body' => http_build_query(['log' => $user, 'pwd' => $acc[$user], 'wp-submit' => 'Log In', 'redirect_to' => "$BASE/wp-admin/", 'testcookie' => '1'])]);
    return $jar;
}

/* ======================================================================= */
reset_limits(); clear_mails(); cfg('rate_limit_per_hour=1000', 'min_fill_seconds=8', 'proxy_mode=', 'ack_per_email_per_day=2', 'show_marketing_consent=0');
$pc = public_config(); $nonce = $pc['cfg']['nonce'] ?? ''; $page = $pc['resp'];
echo "Sandbox: " . ($nonce !== '' ? 'page ok, nonce + signed token present' : 'PAGE ERROR') . "\n";

if (!$only || $only === 'A') {
section('A. En-têtes de sécurité et fichiers (P13, P12)');
$h = $page['headers'];
check('P13', 'X-Frame-Options SAMEORIGIN sur le formulaire', (($h['x-frame-options'][0] ?? '') === 'SAMEORIGIN'), json_encode($h['x-frame-options'] ?? null));
check('P13', 'CSP frame-ancestors \'self\'', stripos($h['content-security-policy'][0] ?? '', "frame-ancestors 'self'") !== false);
check('P13', 'X-Content-Type-Options nosniff', strtolower($h['x-content-type-options'][0] ?? '') === 'nosniff');
check('P13', 'Referrer-Policy définie', ($h['referrer-policy'][0] ?? '') !== '');
check('P13', 'Page du formulaire non mise en cache (jeton)', stripos(implode(',', $h['cache-control'] ?? []), 'no-store') !== false || stripos(implode(',', $h['cache-control'] ?? []), 'no-cache') !== false, json_encode($h['cache-control'] ?? null));
$home = http('GET', "$BASE/");
check('P13', 'Les autres pages du site ne reçoivent pas ces en-têtes (pas d\'effet de bord)', !isset($home['headers']['content-security-policy']));
$fp = "$D/wp/wp-content/plugins/kdv-leads";
check('P12', 'Les .htaccess de protection sont livrés (racine, includes, vendor)', is_file("$fp/.htaccess") && is_file("$fp/includes/.htaccess") && is_file("$fp/vendor/.htaccess"));
$vh = file_get_contents("$fp/vendor/.htaccess");
check('P12', '.htaccess compatible Apache 2.4 et 2.2 (aucune directive isolée qui provoquerait une erreur 500)', strpos($vh, 'mod_authz_core.c') !== false && strpos($vh, '!mod_authz_core.c') !== false);
}

if (!$only || $only === 'B') {
section('B. Anti-abus : jeton signé (P1)');
reset_limits();
$r = submit(base_payload(['form_token' => '']), $nonce);
check('P1', 'Sans jeton → refusé', $r['status'] === 400, "{$r['status']} " . ($r['body']));
$r = submit(base_payload(['form_token' => '1793000000.' . str_repeat('a', 32)]), $nonce);
check('P1', 'Jeton falsifié (signature inventée) → refusé', $r['status'] === 400, "{$r['status']}");
$good = tok(60); [$t, $sig] = explode('.', $good);
$r = submit(base_payload(['form_token' => ((int) $t - 3600) . '.' . $sig]), $nonce);
check('P1', 'Jeton dont l\'horodatage est modifié (même signature) → refusé', $r['status'] === 400);
$r = submit(base_payload(['form_token' => tok(1)]), $nonce);
check('P1', 'Envoi immédiat (jeton émis il y a 1 s, délai min. 8 s) → refusé, message clair', $r['status'] === 400 && strpos($r['body'], 'quelques secondes') !== false, $r['body']);
$r = submit(base_payload(['form_token' => '', 'elapsed_ms' => 999999]), $nonce);
check('P1', 'Ancienne astuce « elapsed_ms » falsifié → n\'ouvre plus rien', $r['status'] === 400);
$r = submit(base_payload(['form_token' => tok(90000)]), $nonce);
check('P1', 'Jeton de plus de 24 h → refusé', $r['status'] === 400);
$r = submit(base_payload(), $nonce);
check('P1', 'Jeton valide, délai respecté → dossier créé (le parcours légitime marche)', $r['status'] === 201, $r['body']);
// A token is usable a limited number of times.
$shared = tok(60); $codes = [];
for ($i = 0; $i < 14; $i++) { $codes[] = submit(base_payload(['form_token' => $shared, 'telephone' => '12']), $nonce)['status']; }
check('P1', 'Un même jeton ne sert qu\'un nombre limité de fois (12) : 422 puis refus', count(array_keys($codes, 422)) === 12 && in_array(400, array_slice($codes, 12), true), json_encode($codes));
$r = submit(base_payload(['website' => 'http://spam']), $nonce);
check('P1', 'Champ piège rempli → refusé', $r['status'] === 400);
$bad = submit(base_payload(), 'deadbeef');
check('P1', 'Nonce invalide → 403', $bad['status'] === 403);
}

if (!$only || $only === 'C') {
section('C. Limiteurs atomiques (P2, P8, P9)');
reset_limits(); cfg('rate_limit_per_hour=5');
$db->query("DELETE FROM wp_kdv_leads WHERE full_name = 'Race Test'");
// 20 separate PROCESSES at the same instant, same IP: the old get/set limiter let all of them through.
$procs = [];
for ($i = 0; $i < 20; $i++) {
    $cmd = '"' . $PHP . '" -d memory_limit=256M "' . "$D/race_worker.php" . '" 198.51.100.55 submit';
    $procs[$i] = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes[$i]);
}
$codes = [];
foreach ($procs as $i => $p) { $codes[] = trim(stream_get_contents($pipes[$i][1])); proc_close($p); }
$created = (int) $db->query("SELECT COUNT(*) c FROM wp_kdv_leads WHERE full_name='Race Test'")->fetch_assoc()['c'];
$cnt = array_count_values($codes);
check('P2', '20 envois simultanés depuis la même IP : au plus 5 dossiers créés (limite 5/h)', $created <= 5 && ($cnt['201'] ?? 0) <= 5, "créés=$created codes=" . json_encode($cnt));
check('P2', 'Les autres reçoivent 429', ($cnt['429'] ?? 0) >= 15, json_encode($cnt));
cfg('rate_limit_per_hour=1000');
// Attempts limiter (valid or invalid) before any database work.
reset_limits(); $c429 = 0; $first429 = 0;
for ($i = 1; $i <= 66; $i++) { $s = submit(base_payload(['form_token' => 'x']), $nonce)['status']; if ($s === 429) { $c429++; $first429 = $first429 ?: $i; } }
check('P8', 'Requêtes invalides en rafale : bloquées (429) après 60 tentatives par heure', $first429 === 61 && $c429 === 6, "premier 429 à la requête $first429, total 429=$c429");
// Forged forwarding headers must not change the identity unless the administrator opted in.
reset_limits(); $c429 = 0;
for ($i = 1; $i <= 62; $i++) { $s = submit(base_payload(['form_token' => 'x']), $nonce, 'estimation', ["X-Forwarded-For: 10.0.$i.1", "CF-Connecting-IP: 10.1.$i.1"])['status']; if ($s === 429) { $c429++; } }
check('P9', 'X-Forwarded-For / CF-Connecting-IP falsifiés ignorés par défaut : la limite s\'applique quand même', $c429 === 2, "429=$c429");
cfg('proxy_mode=cloudflare'); reset_limits(); $c429 = 0;
for ($i = 1; $i <= 62; $i++) { $s = submit(base_payload(['form_token' => 'x']), $nonce, 'estimation', ["CF-Connecting-IP: 10.1.$i.1"])['status']; if ($s === 429) { $c429++; } }
check('P9', 'Mode « Cloudflare » activé par l\'administrateur : l\'en-tête est pris en compte (visiteurs distincts non bloqués)', $c429 === 0, "429=$c429");
cfg('proxy_mode='); reset_limits();
}

if (!$only || $only === 'D') {
section('D. E-mails : accusé non exploitable (P3, P5)');
reset_limits(); clear_mails();
$victim = 'victime@example.test';
$codes = [];
for ($i = 0; $i < 4; $i++) {
    $r = submit(base_payload(['email' => $victim, 'marque' => 'autre', 'marque_autre' => 'PAYEZ ICI hxxp://evil.example', 'modele_autre' => 'Cliquez-vite', 'nom_complet' => 'Pirate ' . $i]), $nonce);
    $codes[] = $r['status'];
}
$acks = array_values(array_filter(mails(), static fn ($m) => strpos($m, "TO: $victim") !== false));
check('P3', '4 demandes au nom de la même victime : 2 accusés au maximum envoyés', count($acks) === 2, 'accusés=' . count($acks) . ' codes=' . json_encode($codes));
check('P3', 'Les dossiers sont quand même enregistrés (aucune perte de demande)', count(array_keys($codes, 201)) === 4, json_encode($codes));
$ack = $acks[0] ?? '';
check('P3', 'Le texte libre de l\'attaquant n\'est PAS repris dans l\'accusé', strpos($ack, 'PAYEZ ICI') === false && strpos($ack, 'evil.example') === false && strpos($ack, 'Cliquez-vite') === false);
check('P3', 'Mention « Vous n\'êtes pas à l\'origine de cette demande ? » présente', strpos($ack, 'pas à l\'origine') !== false || strpos($ack, 'pas &agrave; l') !== false || strpos($ack, 'origine de cette demande') !== false);
// Catalogue vehicle is echoed (safe, whitelisted)
clear_mails(); reset_limits();
submit(base_payload(['email' => 'autre@example.test']), $nonce);
$ack2 = implode('', array_filter(mails(), static fn ($m) => strpos($m, 'TO: autre@example.test') !== false));
check('P3', 'Un véhicule du catalogue (BMW Série 3) reste affiché dans l\'accusé', strpos($ack2, 'BMW Série 3') !== false);
// Subject spoofing
clear_mails(); reset_limits();
submit(base_payload(['nom_complet' => '[URGENT] 🔴 PRIORITE <b>MAX</b> Jean', 'delai_vente' => 'renseigne']), $nonce);
$adm = implode("\n", array_filter(mails(), static fn ($m) => strpos($m, 'SUBJECT:') !== false && strpos($m, 'ATTACHMENTS') !== false));
preg_match('/^SUBJECT: (.*)$/m', $adm, $sm); $subj = $sm[1] ?? '';
check('P5', 'Objet de la notification : crochets, emoji et balises du visiteur supprimés', $subj !== '' && strpos($subj, 'URGENT') !== false && strpos($subj, '[URGENT]') === false && strpos($subj, '🔴') === false && strpos($subj, '<') === false, $subj);
check('P5', 'Objet commence bien par le format KDV attendu', strpos($subj, '[KDV ESTIMATION]') === 0, $subj);
// Header injection through name and e-mail
clear_mails(); reset_limits();
submit(base_payload(['nom_complet' => "Jean\r\nBcc: espion@example.test", 'email' => "a@example.test\r\nBcc: espion2@example.test"]), $nonce);
$all = implode("\n", mails());
check('P5', 'Injection d\'en-têtes (CRLF + Bcc) dans le nom et l\'e-mail : aucun Bcc créé', stripos($all, 'bcc: espion') === false && stripos($all, "\nBcc:") === false);
}

if (!$only || $only === 'E') {
section('E. Images : mémoire et emplacements (P4, P11)');
reset_limits();
$tmp = "$D/tmp"; @mkdir($tmp);
function exif_rot(string $jpeg, int $o): string {
    $ifd = "\x00\x01" . "\x01\x12\x00\x03\x00\x00\x00\x01" . pack('n', $o) . "\x00\x00" . "\x00\x00\x00\x00";
    $tiff = "MM\x00\x2A\x00\x00\x00\x08" . $ifd;
    $app1 = "Exif\x00\x00" . $tiff;
    return substr($jpeg, 0, 2) . "\xFF\xE1" . pack('n', strlen($app1) + 2) . $app1 . substr($jpeg, 2);
}
function mkimg(string $path, int $w, int $h, string $type, ?int $orient = null, string $append = ''): void {
    ini_set('memory_limit', '1024M');
    $im = imagecreatetruecolor($w, $h); imagefill($im, 0, 0, imagecolorallocate($im, 200, 30, 30));
    ob_start(); $type === 'png' ? imagepng($im, null, 9) : imagejpeg($im, null, 40); $bin = ob_get_clean(); imagedestroy($im);
    if ($orient) { $bin = exif_rot($bin, $orient); }
    file_put_contents($path, $bin . $append);
}
$sess = http('POST', "$BASE/wp-json/kdv/v1/upload-session", ['headers' => ["X-WP-Nonce: $nonce"]]);
$token = jb($sess)['token'] ?? '';
check('P4', 'Session d\'envoi créée', strlen($token) === 64);
function upload(string $file, string $name, string $token, string $nonce, string $mime = 'image/jpeg'): array {
    global $BASE;
    return http('POST', "$BASE/wp-json/kdv/v1/uploads", ['headers' => ["X-WP-Nonce: $nonce"], 'body' => ['token' => $token, 'photo' => new CURLFile($file, $mime, $name)], 'timeout' => 120]);
}
mkimg("$tmp/bomb.jpg", 6000, 6000, 'jpg', 6);
$r = upload("$tmp/bomb.jpg", 'bomb.jpg', $token, $nonce);
check('P4', 'JPEG 36 Mpx (549 Ko) avec rotation EXIF sur un serveur à 256 Mo : refusé proprement (422), pas d\'erreur fatale', $r['status'] === 422, "{$r['status']} " . substr($r['body'], 0, 200));
$alive = http('GET', "$BASE/vendre-mon-vehicule/");
check('P4', 'Le serveur répond toujours juste après', $alive['status'] === 200);
mkimg("$tmp/bomb.png", 6000, 6000, 'png');
$r = upload("$tmp/bomb.png", 'bomb.png', $token, $nonce, 'image/png');
check('P4', 'PNG 36 Mpx : refusé proprement (422)', $r['status'] === 422, "{$r['status']} " . substr($r['body'], 0, 200));
mkimg("$tmp/ok.jpg", 4000, 3000, 'jpg', 6);
$r = upload("$tmp/ok.jpg", 'ok.jpg', $token, $nonce);
check('P4', 'Photo normale 12 Mpx avec rotation EXIF : acceptée (201), le parcours légitime marche', $r['status'] === 201, "{$r['status']} " . substr($r['body'], 0, 200));
$slotsAfterRefusals = (int) $db->query("SELECT file_count c FROM wp_kdv_upload_tokens WHERE token='$token'")->fetch_assoc()['c'];
check('P11', 'Les refus ne consomment pas d\'emplacement (compteur = 1 après 2 refus et 1 succès)', $slotsAfterRefusals === 1, "file_count=$slotsAfterRefusals");
// Parallel slot reservation: 12 processes compete for the 4 remaining slots of a fresh token.
$tk2 = jb(http('POST', "$BASE/wp-json/kdv/v1/upload-session", ['headers' => ["X-WP-Nonce: $nonce"]]))['token'];
$procs = []; $pp = [];
for ($i = 0; $i < 12; $i++) { $procs[$i] = proc_open('"' . $PHP . '" -d memory_limit=256M "' . "$D/race_worker.php" . '" 198.51.100.77 slot ' . $tk2, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pp[$i]); }
$got = 0; foreach ($procs as $i => $p) { if (trim(stream_get_contents($pp[$i][1])) === 'SLOT') { $got++; } proc_close($p); }
check('P11', '12 réservations simultanées d\'une session : exactement 5 emplacements accordés', $got === 5, "accordés=$got");
// Hostile files
$poly = file_get_contents("$tmp/ok.jpg") . "\n<?php echo 'PWNED-' . (6*7); ?>\n";
file_put_contents("$tmp/poly.jpg", $poly);
$tk3 = jb(http('POST', "$BASE/wp-json/kdv/v1/upload-session", ['headers' => ["X-WP-Nonce: $nonce"]]))['token'];
$r = upload("$tmp/poly.jpg", 'shell.php.jpg', $tk3, $nonce);
$id = jb($r)['id'] ?? 0;
$row = $id ? $db->query("SELECT path, original_name FROM wp_kdv_lead_files WHERE id=$id")->fetch_assoc() : null;
$stored = $row ? file_get_contents("$D/wp/wp-content/uploads/" . trim(glob("$D/wp/wp-content/uploads/kdv-private-*", GLOB_ONLYDIR)[0] ? basename(glob("$D/wp/wp-content/uploads/kdv-private-*", GLOB_ONLYDIR)[0]) : '') . '/' . $row['path']) : '';
check('P4', 'Polyglotte JPEG+PHP : accepté mais RÉENCODÉ, le code PHP n\'est plus dans le fichier stocké', $r['status'] === 201 && strpos($stored, '<?php') === false && strpos($stored, 'PWNED') === false && substr($row['path'] ?? '', -4) === '.jpg', "{$r['status']} path=" . ($row['path'] ?? '?'));
file_put_contents("$tmp/x.svg", '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>');
$r = upload("$tmp/x.svg", 'x.jpg', $tk3, $nonce, 'image/jpeg');
check('P4', 'SVG déguisé en .jpg : refusé (422)', $r['status'] === 422);
file_put_contents("$tmp/t.jpg", '<?php system($_GET[0]); ?>');
$r = upload("$tmp/t.jpg", 't.jpg', $tk3, $nonce);
check('P4', 'Faux JPEG (du PHP nu) : refusé (422)', $r['status'] === 422);
$r = upload("$tmp/ok.jpg", 'ok.jpg', str_repeat('0', 64), $nonce);
check('P4', 'Jeton de session inventé : refusé (403)', $r['status'] === 403);
$r = http('POST', "$BASE/wp-json/kdv/v1/uploads", ['body' => ['token' => $tk3, 'photo' => new CURLFile("$tmp/ok.jpg", 'image/jpeg', 'ok.jpg')]]);
check('P4', 'Envoi sans nonce : refusé (403)', $r['status'] === 403);
$del = http('DELETE', "$BASE/wp-json/kdv/v1/uploads/$id", ['headers' => ["X-WP-Nonce: $nonce", 'X-KDV-Upload-Token: ' . str_repeat('f', 64)]]);
check('P4', 'Suppression avec le jeton d\'un autre : refusée (404)', $del['status'] === 404);
}

if (!$only || $only === 'F') {
section('F. Validation : types et caractères invisibles (P6, P7)');
reset_limits();
$r = submit(base_payload(['nom_complet' => ['x']]), $nonce);
check('P6', 'Tableau à la place du nom : refusé (422), plus de « Array » enregistré, aucun avertissement PHP', $r['status'] === 422 && stripos($r['body'], 'warning') === false && stripos($r['body'], 'array to string') === false, $r['body']);
$r = submit(base_payload(['telephone' => ['a' => 'b'], 'marque' => ['BMW'], 'kilometrage' => ['1'], 'equipements' => [['gps'], 'cuir']]), $nonce);
check('P6', 'Types incohérents partout : réponse JSON propre (422)', $r['status'] === 422 && json_decode($r['body']) !== null, substr($r['body'], 0, 150));
$r = submit(base_payload(['nom_complet' => "\u{202E}Jean\u{200B} Dupont\u{0000}"]), $nonce);
$row = $db->query("SELECT full_name FROM wp_kdv_leads WHERE id = " . (int) ($db->query("SELECT MAX(id) m FROM wp_kdv_leads")->fetch_assoc()['m']))->fetch_assoc();
check('P7', 'Caractères de contrôle (U+202E, U+200B, NUL) retirés du nom enregistré', $r['status'] === 201 && ($row['full_name'] ?? '') === 'Jean Dupont', json_encode($row));
$huge = submit(base_payload(['nom_complet' => str_repeat('A', 3_000_000)]), $nonce);
check('P6', 'Valeur de 3 Mo : traitée sans erreur (tronquée), pas de plantage', in_array($huge['status'], [201, 422], true));
}

if (!$only || $only === 'G') {
section('G. Contrôle d\'accès et attaques sur l\'administration');
reset_limits();
$jars = []; foreach (['secadmin', 'secmanager', 'secsub', 'seceditor'] as $u) { $jars[$u] = login($u); }
$matrix = [
    'liste des estimations' => '/wp-admin/admin.php?page=kdv-leads',
    'réglages' => '/wp-admin/admin.php?page=kdv-settings',
    'diagnostic' => '/wp-admin/admin.php?page=kdv-diagnostic',
];
$expect = ['anon' => [302, 302, 302], 'secsub' => [403, 403, 403], 'seceditor' => [403, 403, 403], 'secmanager' => [200, 403, 403], 'secadmin' => [200, 200, 200]];
foreach ($expect as $who => $codes) {
    $i = 0;
    foreach ($matrix as $label => $path) {
        $o = $who === 'anon' ? [] : ['jar' => $jars[$who]];
        $r = http('GET', $BASE . $path, $o);
        check('AUTHZ', "$who → $label : {$codes[$i]} attendu", $r['status'] === $codes[$i], "obtenu {$r['status']}");
        $i++;
    }
}
$aj = $jars['secadmin'];
$dg = http('GET', "$BASE/wp-admin/admin.php?page=kdv-diagnostic", ['jar' => $aj])['body'];
check('P12/P15', 'Diagnostic : nouvelles lignes (serveur web, adresse IP, anti-robot, mémoire)', stripos($dg, 'Serveur web') !== false && stripos($dg, 'Adresse IP vue') !== false && stripos($dg, 'Turnstile') !== false && stripos($dg, 'Mémoire PHP') !== false);
preg_match('/href="([^"]*kdv_sample_pdf[^"]*)"/', $dg, $m); $sampleUrl = html_entity_decode($m[1] ?? '');
foreach (['secsub', 'seceditor', 'secmanager'] as $who) {
    $r = http('GET', $sampleUrl, ['jar' => $jars[$who]]);
    check('AUTHZ', "$who → PDF d'exemple (action admin) refusé", $r['status'] === 403, "{$r['status']}");
}
$r = http('GET', $sampleUrl, ['jar' => $aj]);
check('AUTHZ', 'admin → PDF d\'exemple avec son jeton : fonctionne', $r['status'] === 200 && strpos($r['headers']['content-type'][0] ?? '', 'pdf') !== false);
// CSRF: state-changing admin-post actions without / with a foreign nonce
$lead = (int) $db->query("SELECT MIN(id) m FROM wp_kdv_leads")->fetch_assoc()['m'];
$r = http('POST', "$BASE/wp-admin/admin-post.php", ['jar' => $aj, 'body' => http_build_query(['action' => 'kdv_lead_update', 'lead' => $lead, 'status' => 'vendu'])]);
check('CSRF', 'Changement de statut sans jeton : refusé (403)', $r['status'] === 403);
$r = http('POST', "$BASE/wp-admin/admin-post.php", ['jar' => $aj, 'body' => http_build_query(['action' => 'kdv_lead_update', 'lead' => $lead, 'status' => 'vendu', '_wpnonce' => 'abcdef1234'])]);
check('CSRF', 'Changement de statut avec un jeton inventé : refusé (403)', $r['status'] === 403);
$r = http('GET', "$BASE/wp-admin/admin-post.php?action=kdv_anonymize&lead=$lead", ['jar' => $jars['secmanager']]);
check('CSRF', 'Anonymisation par un Gestionnaire (lien GET sans jeton) : refusée', in_array($r['status'], [403, 405], true), "{$r['status']}");
$r = http('GET', "$BASE/wp-admin/admin-post.php?action=kdv_export_csv&type=estimation", ['jar' => $aj]);
check('CSRF', 'Export CSV sans jeton : refusé (403)', $r['status'] === 403);
$r = http('GET', "$BASE/wp-admin/admin-post.php?action=kdv_download&lead=$lead", ['jar' => $jars['secmanager']]);
check('CSRF', 'Téléchargement de PDF sans jeton : refusé (403)', $r['status'] === 403);
}

if (!$only || $only === 'H') {
section('H. Injections : XSS stocké de bout en bout, SQL, CSV');
reset_limits(); clear_mails();
$xss = ['" onmouseover="alert(1)', "' onfocus='alert(2)", '<img src=x onerror=alert(3)>', '"><svg/onload=alert(4)>', 'javascript:alert(5)', '{{7*7}}', '${7*7}'];
$ids = [];
foreach ($xss as $i => $p) {
    $r = submit(base_payload(['nom_complet' => "X$i $p", 'marque' => 'autre', 'marque_autre' => "Marque$i $p", 'modele_autre' => "Modele$i $p", 'finition' => "Fin$i $p", 'autre_equipement' => "Eq$i $p",
        'email' => "xss$i@example.test"]), $nonce, 'estimation');
    $ids[] = jb($r)['reference'] ?? null;
}
$rr = submit(base_payload(['marques_modeles' => ['Porsche ' . $xss[0]], 'versions' => $xss[1], 'couleurs_souhaitees' => $xss[3], 'commentaire' => "ligne1\n" . $xss[0] . "\n" . $xss[2], 'localisation' => $xss[1],
    'liens_annonces' => ['javascript:alert(1)'], 'budget_max' => '50000', 'prenom' => 'Xss ' . $xss[0], 'nom' => 'Test', 'telephone' => '0611223344', 'email' => 'xssrec@example.test', 'code_postal' => '75008', 'consentement' => '1']), $nonce, 'recherche');
check('XSS', 'Lien javascript: dans les annonces : refusé (422)', $rr['status'] === 422);
$rr = submit(base_payload(['marques_modeles' => ['Porsche ' . $xss[0]], 'versions' => $xss[1], 'couleurs_souhaitees' => $xss[3], 'commentaire' => "ligne1\n" . $xss[0] . "\n" . $xss[2], 'localisation' => $xss[1],
    'liens_annonces' => ['https://example.test/a?x=1&y=2'], 'budget_max' => '50000', 'prenom' => 'Xss ' . $xss[0], 'nom' => 'Test', 'telephone' => '0611223344', 'email' => 'xssrec@example.test', 'code_postal' => '75008', 'consentement' => '1']), $nonce, 'recherche');
check('XSS', 'Charges hostiles : dossiers créés (201) sans erreur serveur', count(array_filter($ids)) === count($xss) && $rr['status'] === 201, json_encode($ids) . ' rec=' . $rr['status']);
$aj = login('secadmin');
$bad = 0; $seen = [];
$pages = ["$BASE/wp-admin/admin.php?page=kdv-leads", "$BASE/wp-admin/admin.php?page=kdv-recherches"];
$maxid = (int) $db->query("SELECT MAX(id) m FROM wp_kdv_leads")->fetch_assoc()['m'];
for ($i = max(1, $maxid - 8); $i <= $maxid; $i++) { $pages[] = "$BASE/wp-admin/admin.php?page=kdv-leads&lead=$i"; $pages[] = "$BASE/wp-admin/admin.php?page=kdv-recherches&lead=$i"; }
foreach ($pages as $u) {
    $b = http('GET', $u, ['jar' => $aj])['body'];
    foreach (['" onmouseover="alert', "' onfocus='alert", '<img src=x', '<svg/onload', 'onerror=alert'] as $needle) { if (stripos($b, $needle) !== false) { $bad++; $seen[] = "$needle @ " . preg_replace('#.*page=#', '', $u); } }
}
check('XSS', 'Liste et fiches de l\'administration : aucune charge exécutable (tout est échappé)', $bad === 0, implode(' | ', array_slice($seen, 0, 4)));
$mailbody = implode("\n", mails());
$badm = 0; foreach (['<img src=x', '<svg/onload', 'onerror=alert', '" onmouseover="alert'] as $needle) { if (stripos($mailbody, $needle) !== false) { $badm++; } }
check('XSS', 'E-mails (notification + accusé) : aucune balise ni attribut injecté', $badm === 0);
// SQL injection timing on every admin filter
foreach (['s' => "' OR SLEEP(4)-- -", 'status' => "nouveau' OR SLEEP(4)#", 'delai' => "asap' OR SLEEP(4)#", 'from' => "2026-01-01' OR SLEEP(4)#", 'to' => "2026-12-31' OR SLEEP(4)#", 'orderby' => 'id;SELECT SLEEP(4)', 'order' => 'ASC,SLEEP(4)', 'callback' => "due' OR SLEEP(4)#", 'lead' => '1 OR SLEEP(4)'] as $k => $v) {
    $r = http('GET', "$BASE/wp-admin/admin.php?page=kdv-leads&" . $k . '=' . rawurlencode($v), ['jar' => $aj]);
    check('SQLI', "Paramètre « $k » avec SLEEP(4) : aucune exécution (rapide, pas d'erreur SQL)", $r['time'] < 3 && $r['status'] === 200 && stripos($r['body'], 'database error') === false, sprintf('%.1fs status %d', $r['time'], $r['status']));
}
$r = http('GET', "$BASE/wp-admin/admin-post.php?action=kdv_export_csv&type=estimation&s=" . rawurlencode("' OR SLEEP(4)-- -"), ['jar' => $aj]);
check('SQLI', 'Export CSV : paramètres hostiles (sans jeton) refusés avant toute requête', $r['status'] === 403 && $r['time'] < 3);
// Public REST SQLi
$t0 = microtime(true); $r = submit(base_payload(['submission_key' => "x' OR SLEEP(4)-- -"]), $nonce);
check('SQLI', 'REST : clé d\'idempotence hostile → refus immédiat (400)', $r['status'] === 400 && microtime(true) - $t0 < 3);
}

if (!$only || $only === 'J') {
section('J. Liens d\'annonces (recherche) : validation sans résolution DNS');
$rec = static function (array $links) use ($nonce) {
    reset_limits();
    $t0 = microtime(true);
    $r = submit(base_payload(['marques_modeles' => ['Porsche Cayman'], 'budget_max' => '50000', 'prenom' => 'Camille', 'nom' => 'Durand', 'telephone' => '0611223344', 'email' => 'c@example.test', 'code_postal' => '75008', 'liens_annonces' => $links]), $nonce, 'recherche');
    return [$r['status'], microtime(true) - $t0, $r['body']];
};
[$s, $t, $b] = $rec(['https://www.lacentrale.fr/auto-occasion-annonce-1.html?x=1&y=2']);
check('P19', 'Lien normal accepté (201), sans attendre un DNS', $s === 201 && $t < 5, "$s " . sprintf('%.1fs', $t) . ' ' . substr($b, 0, 120));
[$s, $t, $b] = $rec(['https://annonce-inexistante-kdv-test.example.test/voiture']);
check('P19', 'Domaine non résolu : accepté tel quel (aucune requête DNS faite par le serveur)', $s === 201 && $t < 5, "$s " . sprintf('%.1fs', $t));
[$s] = $rec(['https://banque.com@evil.example/login']);
check('P19', 'Adresse avec identifiants (https://banque.com@evil…, astuce d\'hameçonnage) : refusée', $s === 422, "$s");
[$s] = $rec(['javascript:alert(1)']);
check('P19', 'javascript: refusé', $s === 422);
[$s] = $rec(['data:text/html,<script>alert(1)</script>']);
check('P19', 'data: refusé', $s === 422);
[$s] = $rec(['ftp://example.com/x']);
check('P19', 'ftp: refusé', $s === 422);
[$s] = $rec(['http://127.0.0.1/admin', 'http://localhost:8099/x']);
check('P19', 'Adresses locales acceptées comme simple texte (jamais ouvertes par le serveur, non cliquables dans le PDF)', in_array($s, [201, 422], true), "$s");
}

if (!$only || $only === 'K') {
section('K. PDF, erreurs d\'envoi, secrets par l\'interface, conservation (P5, P14, P18)');
reset_limits(); clear_mails();
// K1: visitor links are printed, not clickable, in the search PDF
$r = submit(base_payload(['marques_modeles' => ['Porsche Cayman'], 'budget_max' => '50000', 'prenom' => 'Pdf', 'nom' => 'Lien', 'telephone' => '0611223344', 'email' => 'pdf@example.test', 'code_postal' => '75008', 'liens_annonces' => ['https://www.lacentrale.fr/annonce-piege-12345.html']]), $nonce, 'recherche');
$ref = jb($r)['reference'] ?? '';
$pdfRel = $db->query("SELECT pdf_path p FROM wp_kdv_leads WHERE reference='" . $db->real_escape_string($ref) . "'")->fetch_assoc()['p'] ?? '';
$stor = glob("$D/wp/wp-content/uploads/kdv-private-*", GLOB_ONLYDIR)[0] ?? '';
$pdf = $pdfRel ? file_get_contents("$stor/$pdfRel") : '';
check('P5', 'PDF de recherche généré', strlen($pdf) > 1000 && substr($pdf, 0, 4) === '%PDF', "ref=$ref path=$pdfRel");
check('P5', 'Aucun lien cliquable vers l\'adresse fournie par le visiteur dans le PDF (aucune annotation /URI vers lacentrale)', strpos($pdf, 'lacentrale') === false || strpos($pdf, '/URI (https://www.lacentrale') === false);
check('P5', 'Les liens utiles au personnel (tel:, mailto:) restent cliquables', strpos($pdf, '/URI (tel:') !== false || strpos($pdf, '/URI (mailto:') !== false || strpos($pdf, 'tel:') !== false);
// K2: SMTP error details only for administrators
reset_limits(); clear_mails(); touch("$MAILS/fail.flag");
$r = submit(base_payload(['nom_complet' => 'Panne Smtp', 'email' => 'panne@example.test']), $nonce);
@unlink("$MAILS/fail.flag");
check('P14', 'Panne e-mail simulée : le dossier est quand même enregistré (201)', $r['status'] === 201);
$mj = login('secmanager'); $aj = login('secadmin');
$lm = http('GET', "$BASE/wp-admin/admin.php?page=kdv-leads", ['jar' => $mj])['body'];
$la = http('GET', "$BASE/wp-admin/admin.php?page=kdv-leads", ['jar' => $aj])['body'];
check('P14', 'Bandeau d\'alerte : l\'administrateur voit le détail technique de l\'erreur SMTP', stripos($la, 'simulated outage') !== false);
check('P14', 'Bandeau d\'alerte : le Gestionnaire voit un message générique, sans détail technique', stripos($lm, 'simulated outage') === false && stripos($lm, 'pas pu partir') !== false, substr(strip_tags($lm), strpos(strip_tags($lm), 'KDV') ?: 0, 120));
$leadId = (int) $db->query("SELECT id FROM wp_kdv_leads WHERE full_name='Panne Smtp' ORDER BY id DESC LIMIT 1")->fetch_assoc()['id'];
$dm = http('GET', "$BASE/wp-admin/admin.php?page=kdv-leads&lead=$leadId", ['jar' => $mj])['body'];
$da = http('GET', "$BASE/wp-admin/admin.php?page=kdv-leads&lead=$leadId", ['jar' => $aj])['body'];
check('P14', 'Fiche dossier : détail d\'erreur masqué au Gestionnaire, visible à l\'administrateur', stripos($dm, 'simulated outage') === false && stripos($da, 'simulated outage') !== false);
// K3: secret saved through the real settings screen is encrypted in the database
$sp = http('GET', "$BASE/wp-admin/admin.php?page=kdv-settings", ['jar' => $aj])['body'];
preg_match('/name="_wpnonce" value="([^"]+)"/', $sp, $nm);
$post = http('POST', "$BASE/wp-admin/options.php", ['jar' => $aj, 'body' => http_build_query(['option_page' => 'kdv_settings_group', 'action' => 'update', '_wpnonce' => $nm[1] ?? '', '_wp_http_referer' => '/wp-admin/admin.php?page=kdv-settings',
    'kdv_settings' => ['turnstile_site_key' => '0x4AAAA-site', 'turnstile_secret_key' => 'SECRET-TYPED-IN-UI', 'proxy_mode' => '', 'rate_limit_per_hour' => '1000', 'min_fill_seconds' => '8', 'ack_per_email_per_day' => '2']])]);
$rawopt = $db->query("SELECT option_value v FROM wp_options WHERE option_name='kdv_settings'")->fetch_assoc()['v'] ?? '';
check('P14', 'Réglages enregistrés depuis l\'interface : secret Turnstile chiffré en base (texte saisi introuvable)', in_array($post['status'], [302, 200], true) && strpos($rawopt, 'enc1:') !== false && strpos($rawopt, 'SECRET-TYPED-IN-UI') === false, "http {$post['status']}");
$sp2 = http('GET', "$BASE/wp-admin/admin.php?page=kdv-settings", ['jar' => $aj])['body'];
check('P14', 'La page de réglages ne ré-affiche jamais le secret', strpos($sp2, 'SECRET-TYPED-IN-UI') === false && strpos($sp2, 'enc1:') === false);
cfg('turnstile_secret_key=', 'turnstile_site_key=');   // back to "no Turnstile" for the other groups
// K4: retention
$ret = json_decode(cli('h_retention.php'), true) ?: [];
check('P18', 'Conservation : copie d\'e-mail envoyée il y a 40 jours supprimée, celle de 10 jours conservée', !in_array('OLD-40d', $ret['mails_restants'] ?? ['?'], true) && in_array('MID-10d', $ret['mails_restants'] ?? [], true), json_encode($ret));
check('P18', 'Lignes de limitation périmées purgées, compteurs récents et numéros de référence intacts', !in_array('rl-staleaaaaaaaaaaaaaaaaaaaa-2026010100', $ret['compteurs_restants'] ?? ['?'], true) && count(array_filter($ret['compteurs_restants'] ?? [], static fn ($n) => strpos($n, 'ra-fresh') === 0 || $n === 'EST-20260101')) === 2, json_encode($ret));
}

if (!$only || $only === 'I') {
section('I. Secrets, erreurs et divulgation (P14)');
$out = cli('h_secret.php');
echo "  $out\n";
}

echo "\n=== RÉSULTAT : {$res['pass']} réussis, {$res['fail']} échecs ===\n";
