<?php
// P14: secrets encrypted at rest, legacy plain-text migrated, error details hidden from non-admins.
require __DIR__ . '/h_boot.php';
use KdvLeads\Config; use KdvLeads\Security\Secrets; use KdvLeads\Admin\SettingsPage;
$out = [];
$ok = static function (string $name, bool $v, string $d = '') use (&$out) { $out[] = ($v ? 'PASS' : 'FAIL') . " [P14] $name" . ($v || $d === '' ? '' : " → $d"); };

// 1) saving a secret through the settings sanitiser stores it encrypted, reading it back decrypts
$saved = SettingsPage::sanitize(['turnstile_secret_key' => '0x4AAAAA-super-secret', 'plate_api_key' => 'plate-key-123']);
update_option('kdv_settings', $saved);
$raw = get_option('kdv_settings');
$ok('clé Turnstile enregistrée chiffrée (préfixe enc1:, texte clair absent)', strpos($raw['turnstile_secret_key'], 'enc1:') === 0 && strpos($raw['turnstile_secret_key'], 'super-secret') === false, $raw['turnstile_secret_key']);
$ok('Config::get() déchiffre correctement', Config::get('turnstile_secret_key') === '0x4AAAAA-super-secret' && Config::get('plate_api_key') === 'plate-key-123');
global $wpdb;
$dump = (string) $wpdb->get_var("SELECT option_value FROM {$wpdb->options} WHERE option_name='kdv_settings'");
$ok('Le texte clair n\'apparaît pas dans la base (dump SQL)', strpos($dump, 'super-secret') === false && strpos($dump, 'plate-key-123') === false);
// 2) saving the page again with the secret field left empty keeps the stored secret
$again = SettingsPage::sanitize(['turnstile_secret_key' => '', 'rate_limit_per_hour' => '7']);
update_option('kdv_settings', $again);
$ok('Champ secret laissé vide à l\'enregistrement : secret conservé', Config::get('turnstile_secret_key') === '0x4AAAAA-super-secret');
// 3) legacy plain-text value is migrated by the one-time routine
update_option('kdv_settings', array_merge(get_option('kdv_settings'), ['turnstile_secret_key' => 'legacy-plain-secret']), false);
delete_option('kdv_secrets_v1');
Secrets::migrate(Config::OPTION);
$raw2 = get_option('kdv_settings');
$ok('Ancienne valeur en clair migrée (chiffrée) au démarrage', strpos($raw2['turnstile_secret_key'], 'enc1:') === 0 && Config::get('turnstile_secret_key') === 'legacy-plain-secret');
// 4) numeric bounds
$b = SettingsPage::sanitize(['rate_limit_per_hour' => '999999', 'ack_per_email_per_day' => '-5', 'min_fill_seconds' => '99999', 'proxy_mode' => 'evil']);
$ok('Bornes des réglages (limite 1000, accusés ≥ 1, délai ≤ 600) et liste blanche du proxy', $b['rate_limit_per_hour'] === 1000 && $b['ack_per_email_per_day'] === 1 && $b['min_fill_seconds'] === 600 && $b['proxy_mode'] === '', json_encode([$b['rate_limit_per_hour'], $b['ack_per_email_per_day'], $b['min_fill_seconds'], $b['proxy_mode']]));
// restore a clean state
update_option('kdv_settings', array_merge(get_option('kdv_settings'), ['turnstile_secret_key' => '', 'plate_api_key' => '', 'rate_limit_per_hour' => 1000]), false);
echo implode("\n  ", $out);
