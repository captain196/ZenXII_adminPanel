<?php
// Router for PHP built-in server (CodeIgniter)
$uri = urldecode(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH));

// Landing page at root
if ($uri === '/' || $uri === '') {
    header('Content-Type: text/html; charset=utf-8');
    readfile(__DIR__ . '/index.html');
    return true;
}

// Serve static files directly
if (file_exists(__DIR__ . $uri) && !is_dir(__DIR__ . $uri)) {
    return false;
}

// Route everything else through index.php
$_SERVER['CI_ENV'] = 'development';
require_once __DIR__ . '/index.php';
