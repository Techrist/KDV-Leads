<?php
// usage: php h_cfg.php key=value ...  -> merges settings (no sanitiser, sandbox only)
require __DIR__ . '/h_boot.php';
$o = get_option('kdv_settings', []); if (!is_array($o)) { $o = []; }
foreach (array_slice($argv, 1) as $kv) { [$k, $v] = explode('=', $kv, 2); $o[$k] = is_numeric($v) ? (int) $v : $v; }
update_option('kdv_settings', $o);
echo "ok";
