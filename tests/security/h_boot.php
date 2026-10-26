<?php
// Loads the sandbox WordPress for CLI helpers.
$_SERVER['HTTP_HOST'] = '127.0.0.1:8099'; $_SERVER['REQUEST_URI'] = '/'; $_SERVER['SERVER_SOFTWARE'] = 'PHP built-in';
if (!isset($_SERVER['REMOTE_ADDR'])) { $_SERVER['REMOTE_ADDR'] = '127.0.0.1'; }
require __DIR__ . '/wp/wp-load.php';
