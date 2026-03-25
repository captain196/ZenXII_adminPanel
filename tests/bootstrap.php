<?php
/**
 * PHPUnit Bootstrap — GraderIQ SaaS ERP
 *
 * Sets up the minimum environment so that CI3 controller/library classes
 * can be instantiated and tested without a running web server.
 *
 * What it does:
 *  1. Defines CI constants (BASEPATH, APPPATH, FCPATH, ENVIRONMENT).
 *  2. Loads the Composer autoloader.
 *  3. Registers a PSR-4 autoloader for test support classes.
 *  4. Defines GRADER_DEBUG as false (unless overridden by an env var).
 *  5. Provides global CI stubs (log_message, redirect, show_error, base_url).
 */

// ── Paths ──────────────────────────────────────────────────────────────────────
define('FCPATH',   realpath(__DIR__ . '/../') . DIRECTORY_SEPARATOR);
define('BASEPATH', realpath(__DIR__ . '/../system') . DIRECTORY_SEPARATOR);
define('APPPATH',  realpath(__DIR__ . '/../application') . DIRECTORY_SEPARATOR);
define('ENVIRONMENT', 'testing');
define('CI_VERSION', '3.1.13');

// ── Debug mode always off in tests ─────────────────────────────────────────────
defined('GRADER_DEBUG') OR define('GRADER_DEBUG', false);

// ── Composer autoload ──────────────────────────────────────────────────────────
require_once FCPATH . 'vendor/autoload.php';

// ── Autoload test support classes ──────────────────────────────────────────────
spl_autoload_register(function (string $class): void {
    $map = [
        'Tests\\Support\\' => __DIR__ . '/Support/',
        'Tests\\Unit\\'    => __DIR__ . '/Unit/',
        'Tests\\Integration\\' => __DIR__ . '/Integration/',
    ];
    foreach ($map as $prefix => $dir) {
        if (strpos($class, $prefix) === 0) {
            $rel  = str_replace($prefix, '', $class);
            $file = $dir . str_replace('\\', '/', $rel) . '.php';
            if (file_exists($file)) require_once $file;
        }
    }
});

// ── Global CI stubs (only defined if the CI system isn't loaded) ───────────────
if (!function_exists('log_message')) {
    function log_message(string $level, string $message): void
    {
        // Captured by LogCapture helper in tests; silent otherwise
        if (isset($GLOBALS['__test_log_capture'])) {
            $GLOBALS['__test_log_capture'][] = ['level' => $level, 'msg' => $message];
        }
    }
}

if (!function_exists('redirect')) {
    function redirect(string $uri = '', string $method = 'auto', ?int $code = null): void
    {
        // Store last redirect target so tests can assert it
        $GLOBALS['__test_last_redirect'] = $uri;
        throw new \Tests\Support\RedirectException($uri);
    }
}

if (!function_exists('show_error')) {
    function show_error(string $message, int $status_code = 500): void
    {
        throw new \Tests\Support\ShowErrorException($message, $status_code);
    }
}

if (!function_exists('base_url')) {
    function base_url(string $uri = ''): string
    {
        return rtrim(getenv('GRADER_BASE_URL') ?: 'http://localhost/Grader/school/', '/') . '/' . ltrim($uri, '/');
    }
}

if (!function_exists('config_item')) {
    function config_item(string $item): mixed { return null; }
}

if (!function_exists('set_time_limit')) {
    function set_time_limit(int $seconds): void {}
}

// ── Result directory ───────────────────────────────────────────────────────────
$resultsDir = __DIR__ . '/results';
if (!is_dir($resultsDir)) mkdir($resultsDir, 0755, true);
