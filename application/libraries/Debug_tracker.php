<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Debug_tracker — Singleton that collects all debug events during a request.
 *
 * Activated only when GRADER_DEBUG === true (flag file exists).
 * Writes JSONL entries to application/logs/debug_YYYY-MM-DD.log.
 *
 * Entry types: REQUEST · FIREBASE_READ · FIREBASE_WRITE · FIREBASE_ERROR ·
 *              SCHEMA_MISMATCH · UNAUTHORIZED · SLOW_OP · AJAX_ERROR · SCHEMA_CHECK
 *
 * Callers:
 *   - Firebase.php           → record_firebase_op()
 *   - Debug_logger hook      → record_request() + flush()
 *   - MY_Superadmin_Controller → record_unauthorized()
 *   - Superadmin_debug       → log_ajax_error()
 */
class Debug_tracker
{
    /* ── Thresholds ─────────────────────────────────────────────────── */
    const SLOW_MS        = 500;    // Firebase op flagged as slow above this
    const MAX_ENTRIES    = 2000;   // Safety cap per request
    const LOG_DIR        = 'logs'; // relative to APPPATH
    const LOG_PREFIX     = 'debug_';

    /* ── Singleton ───────────────────────────────────────────────────── */
    private static $instance  = null;

    /* ── In-memory buffer ────────────────────────────────────────────── */
    private $entries   = [];
    private $req_start = 0; // microtime(true) at request start

    public function __construct()
    {
        $this->req_start = microtime(true);
    }

    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /* ── Schema definitions ──────────────────────────────────────────── */

    /**
     * Returns [required_fields[], schema_label] for a given Firebase path,
     * or null if no schema is defined for that path.
     */
    private static function _schema_for(string $path): ?array
    {
        $parts = explode('/', $path);

        // System/Schools/{school_id}
        if (count($parts) === 3 && $parts[0] === 'System' && $parts[1] === 'Schools') {
            return [['profile','subscription','status'], 'System/Schools/{school_id}'];
        }
        // System/Plans/{id}
        if (count($parts) === 3 && $parts[0] === 'System' && $parts[1] === 'Plans') {
            return [['name','price','billing_cycle','modules'], 'System/Plans/{id}'];
        }
        // System/Payments/{id}
        if (count($parts) === 3 && $parts[0] === 'System' && $parts[1] === 'Payments') {
            return [['school_name','amount','status','invoice_date'], 'System/Payments/{id}'];
        }
        // System/Backups/{uid}/{id}
        if (count($parts) === 4 && $parts[0] === 'System' && $parts[1] === 'Backups') {
            return [['backup_id','filename','backup_type','size_bytes','created_at','created_by'], 'System/Backups/{uid}/{id}'];
        }
        // System/BackupSchedule
        if ($path === 'System/BackupSchedule') {
            return [['enabled','frequency','retention','cron_key'], 'System/BackupSchedule'];
        }
        // Users/Admin/{code}/{adminId}
        if (count($parts) === 4 && $parts[0] === 'Users' && $parts[1] === 'Admin') {
            return [['name','email','Role','password'], 'Users/Admin/{code}/{id}'];
        }
        return null;
    }

    /* ── Public recording methods ────────────────────────────────────── */

    /**
     * Record a Firebase operation.
     *
     * @param string     $op          READ | WRITE | DELETE | PUSH | SHALLOW | EXISTS | COPY
     * @param string     $path        Firebase path
     * @param float      $duration_ms Elapsed time in milliseconds
     * @param mixed      $result      Returned value (used for size calc + schema check)
     * @param string|null $error      Exception message if failed
     * @param string     $caller      "ClassName::method" of the CI controller that triggered it
     */
    public function record_firebase_op(
        string $op,
        string $path,
        float  $duration_ms,
               $result,
        ?string $error,
        string  $caller = ''
    ): void {
        if (count($this->entries) >= self::MAX_ENTRIES) return;

        $type      = $error ? 'FIREBASE_ERROR' : ($op === 'READ' || $op === 'SHALLOW' ? 'FIREBASE_READ' : 'FIREBASE_WRITE');
        $size_bytes = $this->_data_size($result);

        $entry = [
            'type'        => $type,
            'op'          => $op,
            'path'        => $path,
            'duration_ms' => round($duration_ms, 2),
            'size_bytes'  => $size_bytes,
            'caller'      => $caller,
            'ts'          => date('Y-m-d H:i:s'),
        ];
        if ($error) $entry['error'] = $error;
        $this->entries[] = $entry;

        // Flag slow operations separately for easy filtering
        if ($duration_ms >= self::SLOW_MS) {
            $this->entries[] = [
                'type'        => 'SLOW_OP',
                'op'          => $op,
                'path'        => $path,
                'duration_ms' => round($duration_ms, 2),
                'caller'      => $caller,
                'ts'          => date('Y-m-d H:i:s'),
            ];
        }

        // Schema check on read results (arrays only)
        if ($op === 'READ' && !$error && is_array($result)) {
            $this->_check_schema($path, $result);
        }
    }

    /**
     * Record the incoming HTTP request (called by the hook).
     */
    public function record_request(
        string $uri,
        string $method,
        string $controller,
        string $action,
        string $ip
    ): void {
        $this->entries[] = [
            'type'       => 'REQUEST',
            'uri'        => $uri,
            'method'     => strtoupper($method),
            'controller' => $controller,
            'action'     => $action,
            'ip'         => $ip,
            'ts'         => date('Y-m-d H:i:s'),
        ];
    }

    /**
     * Update the REQUEST entry with the final duration (called at post_system).
     */
    public function close_request(float $duration_ms): void
    {
        foreach ($this->entries as &$e) {
            if ($e['type'] === 'REQUEST') {
                $e['duration_ms'] = round($duration_ms, 2);
                break;
            }
        }
    }

    /**
     * Record an unauthorized access attempt.
     */
    public function record_unauthorized(string $uri, string $ip, string $sa_id = ''): void
    {
        $this->entries[] = [
            'type'   => 'UNAUTHORIZED',
            'uri'    => $uri,
            'ip'     => $ip,
            'sa_id'  => $sa_id,
            'ts'     => date('Y-m-d H:i:s'),
        ];
    }

    /**
     * Record a client-side AJAX error (posted by the JS ajaxError handler).
     */
    public function record_ajax_error(
        string $url,
        int    $status,
        string $error,
        string $preview = '',
        string $ip = ''
    ): void {
        $this->entries[] = [
            'type'            => 'AJAX_ERROR',
            'url'             => $url,
            'status'          => $status,
            'error'           => $error,
            'response_preview'=> substr($preview, 0, 300),
            'ip'              => $ip,
            'ts'              => date('Y-m-d H:i:s'),
        ];
    }

    /* ── Schema checking ─────────────────────────────────────────────── */

    private function _check_schema(string $path, array $data): void
    {
        $def = self::_schema_for($path);
        if ($def === null) return;
        [$required, $label] = $def;

        $missing = array_values(array_diff($required, array_keys($data)));
        if (!empty($missing)) {
            $this->entries[] = [
                'type'    => 'SCHEMA_MISMATCH',
                'path'    => $path,
                'schema'  => $label,
                'missing' => $missing,
                'present' => array_keys($data),
                'ts'      => date('Y-m-d H:i:s'),
            ];
        }
    }

    /* ── Flush to log file ───────────────────────────────────────────── */

    /**
     * Write all buffered entries to today's debug log file as JSONL.
     * Called by the post_system hook.
     */
    public function flush(): void
    {
        if (empty($this->entries)) return;

        $file = APPPATH . self::LOG_DIR . '/' . self::LOG_PREFIX . date('Y-m-d') . '.log';
        $lines = '';
        foreach ($this->entries as $entry) {
            $lines .= json_encode($entry, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
        }

        // Append — multiple requests per day accumulate
        file_put_contents($file, $lines, FILE_APPEND | LOCK_EX);
        $this->entries = []; // clear buffer after flush
    }

    /* ── Helpers ─────────────────────────────────────────────────────── */

    private function _data_size($data): int
    {
        if ($data === null) return 0;
        return strlen(json_encode($data) ?: '');
    }

    /**
     * Resolve the calling CI controller::method from the call stack.
     * Skips frames inside Firebase.php and Debug_tracker.php.
     */
    public static function get_caller(): string
    {
        $trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 10);
        foreach ($trace as $frame) {
            $file = $frame['file'] ?? '';
            if (strpos($file, 'Firebase.php') !== false) continue;
            if (strpos($file, 'Debug_tracker.php') !== false) continue;
            $class = $frame['class'] ?? '';
            $fn    = $frame['function'] ?? '';
            if ($class && $fn) return $class . '::' . $fn;
        }
        return 'unknown';
    }

    /* ── Static helpers for reading logs (used by Superadmin_debug) ──── */

    /**
     * Parse a debug log file and return an array of decoded entries.
     * Optionally filter by one or more entry types.
     */
    public static function read_log(string $date, array $types = []): array
    {
        $file = APPPATH . self::LOG_DIR . '/' . self::LOG_PREFIX . $date . '.log';
        if (!file_exists($file)) return [];

        $entries = [];
        $handle  = fopen($file, 'rb');
        if (!$handle) return [];

        while (($line = fgets($handle)) !== false) {
            $line = trim($line);
            if ($line === '') continue;
            $obj = json_decode($line, true);
            if (!is_array($obj)) continue;
            if (!empty($types) && !in_array($obj['type'] ?? '', $types, true)) continue;
            $entries[] = $obj;
        }
        fclose($handle);
        return $entries;
    }

    /**
     * List all debug log files (returns dates as YYYY-MM-DD strings, newest first).
     */
    public static function available_dates(): array
    {
        $dir   = APPPATH . self::LOG_DIR . '/';
        $files = glob($dir . self::LOG_PREFIX . '*.log');
        if (!$files) return [];

        $dates = [];
        foreach ($files as $f) {
            $base = basename($f, '.log');
            $date = str_replace(self::LOG_PREFIX, '', $base);
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
                $dates[] = $date;
            }
        }
        rsort($dates);
        return $dates;
    }

    /**
     * Build aggregated stats from a log file.
     */
    public static function compute_stats(string $date): array
    {
        $entries = self::read_log($date);

        $stats = [
            'total_requests'   => 0,
            'total_firebase'   => 0,
            'firebase_reads'   => 0,
            'firebase_writes'  => 0,
            'firebase_errors'  => 0,
            'schema_mismatches'=> 0,
            'unauthorized'     => 0,
            'ajax_errors'      => 0,
            'slow_ops'         => 0,
            'avg_request_ms'   => 0,
            'avg_firebase_ms'  => 0,
            'top_paths'        => [],
        ];

        $req_times = [];
        $fb_times  = [];
        $path_counts = [];

        foreach ($entries as $e) {
            switch ($e['type']) {
                case 'REQUEST':
                    $stats['total_requests']++;
                    if (isset($e['duration_ms'])) $req_times[] = $e['duration_ms'];
                    break;
                case 'FIREBASE_READ':
                    $stats['total_firebase']++;
                    $stats['firebase_reads']++;
                    if (isset($e['duration_ms'])) $fb_times[] = $e['duration_ms'];
                    $path_counts[$e['path'] ?? ''] = ($path_counts[$e['path'] ?? ''] ?? 0) + 1;
                    break;
                case 'FIREBASE_WRITE':
                    $stats['total_firebase']++;
                    $stats['firebase_writes']++;
                    if (isset($e['duration_ms'])) $fb_times[] = $e['duration_ms'];
                    break;
                case 'FIREBASE_ERROR':  $stats['firebase_errors']++;  break;
                case 'SCHEMA_MISMATCH': $stats['schema_mismatches']++; break;
                case 'UNAUTHORIZED':    $stats['unauthorized']++;      break;
                case 'AJAX_ERROR':      $stats['ajax_errors']++;       break;
                case 'SLOW_OP':         $stats['slow_ops']++;          break;
            }
        }

        $stats['avg_request_ms']  = $req_times ? round(array_sum($req_times) / count($req_times), 1) : 0;
        $stats['avg_firebase_ms'] = $fb_times  ? round(array_sum($fb_times)  / count($fb_times),  1) : 0;

        arsort($path_counts);
        $stats['top_paths'] = array_slice($path_counts, 0, 10, true);

        return $stats;
    }

    /* ── Debug mode flag helpers ─────────────────────────────────────── */

    public static function is_enabled(): bool
    {
        return defined('GRADER_DEBUG') && GRADER_DEBUG;
    }

    public static function enable(): bool
    {
        return (bool) file_put_contents(APPPATH . 'logs/.debug_enabled', date('Y-m-d H:i:s'));
    }

    public static function disable(): bool
    {
        $f = APPPATH . 'logs/.debug_enabled';
        return file_exists($f) ? unlink($f) : true;
    }
}
