<?php
/**
 * PHP built-in-server router — replacement for the OS-locked router.php.
 *
 * Serves an existing static file as-is; routes every other request through
 * CodeIgniter's front controller (index.php) so clean URLs like /attendance work.
 *
 * Start with:
 *   PHP_CLI_SERVER_WORKERS=8 php -S 127.0.0.1:8080 \
 *       -d max_execution_time=120 -d memory_limit=512M devrouter.php
 */
$uri  = urldecode(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH));
$root = __DIR__;
$file = $root . $uri;

// Existing real asset (css/js/img/…) → let the built-in server return it directly.
if ($uri !== '/' && is_file($file)) {
    return false;
}

// Everything else → CodeIgniter front controller.
$_SERVER['SCRIPT_NAME'] = '/index.php';
$_SERVER['SCRIPT_FILENAME'] = $root . '/index.php';
require $root . '/index.php';
