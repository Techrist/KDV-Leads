<?php
// usage: php h_token.php <seconds_ago>  -> prints a validly SIGNED form token issued that long ago
require __DIR__ . '/h_boot.php';
echo \KdvLeads\Http\FormToken::issue(time() - (int) ($argv[1] ?? 30));
