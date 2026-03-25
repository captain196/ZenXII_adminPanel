<?php
// Router for PHP built-in server (CodeIgniter)
$uri = urldecode(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH));

// Serve static files directly
if ($uri !== '/' && file_exists(__DIR__ . $uri)) {
    return false;
}

// Route everything else through index.php
$_SERVER['CI_ENV'] = 'development';
require_once __DIR__ . '/index.php';
