<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Debug_logger — CodeIgniter hook that records request lifecycle data.
 *
 * pre_controller  : captures request metadata + initialises tracker timer.
 * post_system     : closes request entry with duration + flushes log file.
 *
 * Only active when GRADER_DEBUG === true.
 */
class Debug_logger
{
    private static $_req_start = 0;

    /**
     * Hook: pre_controller
     * Fires after routing but before the controller constructor runs.
     */
    public function pre_request(): void
    {
        self::$_req_start = microtime(true);

        if (!defined('GRADER_DEBUG') || !GRADER_DEBUG) return;

        // Load tracker (may not be autoloaded at this point)
        if (!class_exists('Debug_tracker', false)) {
            require_once APPPATH . 'libraries/Debug_tracker.php';
        }

        $CI  =& get_instance();
        $uri = isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '/';

        // Resolve controller/method from the router (available after pre_controller)
        $controller = class_exists('CI_Router') ? '' : '';
        try {
            $router = load_class('Router', 'core');
            $controller = $router->fetch_class();
            $action     = $router->fetch_method();
        } catch (\Exception $e) {
            $controller = 'unknown';
            $action     = 'unknown';
        }

        $ip = isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : 'cli';

        Debug_tracker::getInstance()->record_request(
            $uri,
            $_SERVER['REQUEST_METHOD'] ?? 'GET',
            $controller,
            $action,
            $ip
        );
    }

    /**
     * Hook: post_system
     * Fires after the final output has been sent.
     */
    public function post_system(): void
    {
        $duration = (microtime(true) - self::$_req_start) * 1000;

        if (!defined('GRADER_DEBUG') || !GRADER_DEBUG) return;
        if (!class_exists('Debug_tracker', false)) return;

        $tracker = Debug_tracker::getInstance();
        $tracker->close_request($duration);
        $tracker->flush();
    }
}
