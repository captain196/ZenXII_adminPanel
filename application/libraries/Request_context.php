<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Request_context — Per-request tracing and metrics collection.
 *
 * Generates a unique request_id for every HTTP request and tracks
 * Firebase call count, total latency, and error count. Logs a
 * summary at request end for observability.
 *
 * Usage:
 *   Autoloaded in MY_Controller constructor.
 *   Access anywhere via: get_instance()->request_context
 *
 * Request ID format: "req_" + 12 hex chars (e.g., "req_6a3f1b2c4d5e")
 * Attached to all log entries for cross-reference.
 */
class Request_context
{
    /** @var string Unique request identifier */
    private $request_id;

    /** @var float Request start time (microtime) */
    private $start_time;

    /** @var int Firebase operation count */
    private $firebase_ops = 0;

    /** @var float Total Firebase latency (seconds) */
    private $firebase_latency = 0.0;

    /** @var int Firebase error count */
    private $firebase_errors = 0;

    /** @var int Cache hit count (token cache, etc.) */
    private $cache_hits = 0;

    /** @var bool Whether summary has been logged */
    private $finalized = false;

    /** Slow request threshold (milliseconds) */
    private const SLOW_REQUEST_MS = 3000;

    /** Slow Firebase aggregate threshold (milliseconds) */
    private const SLOW_FIREBASE_MS = 2000;

    public function __construct()
    {
        $this->request_id = 'req_' . bin2hex(random_bytes(6));
        $this->start_time = microtime(true);

        // Set as response header for client-side correlation
        if (!headers_sent()) {
            header('X-Request-Id: ' . $this->request_id);
        }
    }

    /**
     * Get the unique request ID.
     */
    public function id(): string
    {
        return $this->request_id;
    }

    /**
     * Record a Firebase operation.
     *
     * @param float $latency_seconds  Operation duration in seconds
     * @param bool  $is_error         Whether the operation failed
     */
    public function record_firebase_op(float $latency_seconds, bool $is_error = false): void
    {
        $this->firebase_ops++;
        $this->firebase_latency += $latency_seconds;
        if ($is_error) {
            $this->firebase_errors++;
        }
    }

    /**
     * Record a cache hit (token cache, search cache, etc.).
     */
    public function record_cache_hit(): void
    {
        $this->cache_hits++;
    }

    /**
     * Get current metrics snapshot.
     */
    public function metrics(): array
    {
        $elapsed = (microtime(true) - $this->start_time) * 1000;  // ms
        return [
            'request_id'       => $this->request_id,
            'elapsed_ms'       => round($elapsed, 1),
            'firebase_ops'     => $this->firebase_ops,
            'firebase_latency_ms' => round($this->firebase_latency * 1000, 1),
            'firebase_errors'  => $this->firebase_errors,
            'cache_hits'       => $this->cache_hits,
        ];
    }

    /**
     * Finalize the request — log summary metrics.
     * Called automatically by the destructor or explicitly.
     */
    public function finalize(): void
    {
        if ($this->finalized) return;
        $this->finalized = true;

        $m = $this->metrics();

        // Only log if there were Firebase operations (skip static asset requests)
        if ($m['firebase_ops'] === 0) return;

        $CI =& get_instance();
        $uri = isset($CI->uri) ? $CI->uri->uri_string() : 'unknown';

        // Determine severity
        $level = 'info';
        if ($m['elapsed_ms'] > self::SLOW_REQUEST_MS) {
            $level = 'error';
        } elseif ($m['firebase_latency_ms'] > self::SLOW_FIREBASE_MS) {
            $level = 'error';
        } elseif ($m['firebase_errors'] > 0) {
            $level = 'error';
        }

        $summary = sprintf(
            'REQUEST_METRICS [%s] uri=%s elapsed=%sms firebase_ops=%d firebase_ms=%s errors=%d cache_hits=%d',
            $this->request_id,
            $uri,
            $m['elapsed_ms'],
            $m['firebase_ops'],
            $m['firebase_latency_ms'],
            $m['firebase_errors'],
            $m['cache_hits']
        );

        log_message($level, $summary);
    }

    /**
     * Destructor — auto-finalize on request end.
     */
    public function __destruct()
    {
        $this->finalize();
    }
}
