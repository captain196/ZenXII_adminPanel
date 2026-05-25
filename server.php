<?php
// Router for PHP's built-in server. Emulates Apache + .htaccess just enough
// to (a) serve the static landing + assets directly, (b) hand everything
// else to CodeIgniter's front controller.
//
// Run with:
//   php -S localhost:8181 -t /Users/yuggi/Desktop/Zennxii_adminPanel \
//       /Users/yuggi/Desktop/Zennxii_adminPanel/server.php

$uri  = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$path = __DIR__ . $uri;

// 1) If a real file exists on disk, serve it directly.
if ($uri !== '/' && file_exists($path) && !is_dir($path)) {
    return false;
}

// 2) "/" → landing page.
if ($uri === '/' || $uri === '') {
    $index = __DIR__ . '/index.html';
    if (file_exists($index)) {
        header('Content-Type: text/html; charset=utf-8');
        readfile($index);
        return true;
    }
}

// 3) Anything else falls through to CI's index.php.
$_SERVER['SCRIPT_NAME']     = '/index.php';
$_SERVER['SCRIPT_FILENAME'] = __DIR__ . '/index.php';
require __DIR__ . '/index.php';
