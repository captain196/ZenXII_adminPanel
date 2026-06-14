<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Stream_b_telemetry — minimum viable telemetry for Phase II pilot.
 *
 * Per-call NDJSON record appended to application/logs/stream_b_phase2_telemetry.log
 * with the 9 fields required by pilot acceptance criteria:
 *
 *   ts              | school_id         | code_path
 *   t_total_ms      | http_status       | cas_attempts
 *   cas_final_outcome | cache_hit       | rtdb_writes_count
 *
 * Best-effort semantics — any telemetry failure is caught and ignored;
 * the originating request always succeeds regardless of log state.
 *
 * Architectural lock:
 *   - writes to PHP filesystem ONLY (operational metadata)
 *   - NO Firestore writes / NO RTDB writes
 *   - NO bridge, fallback, dual-read, or dual-write
 *
 * Usage (dispatcher pattern):
 *   $this->load->library('stream_b_telemetry');
 *   $this->stream_b_telemetry->begin('mark_staff_day', $schoolId);
 *   $this->stream_b_telemetry->update(['code_path' => 'fs']);
 *   ...
 *   $this->stream_b_telemetry->update(['cas_attempts' => N, 'cache_hit' => true]);
 *   $this->stream_b_telemetry->commit(200);   // or 4xx/5xx
 */
class Stream_b_telemetry
{
    const LOG_RELATIVE = 'logs/stream_b_phase2_telemetry.log';

    /** Active record being built. Singleton per request (CodeIgniter is single-threaded). */
    private array $record  = [];
    private string $active = '';
    private float  $t0     = 0.0;

    /**
     * Open a new telemetry record. Replaces any in-flight record from the same
     * request (defensive — only one active call should exist per request).
     * Returns the request_id for caller reference.
     */
    public function begin(string $action, string $schoolId): string
    {
        try {
            $this->active = $this->_uuid();
            $this->t0     = microtime(true);
            $this->record = [
                'ts'         => date('c'),
                'request_id' => $this->active,
                'school_id'  => $schoolId,
                'action'     => $action,
                // The rest are populated by update() and commit().
            ];
            return $this->active;
        } catch (\Throwable $e) {
            $this->active = '';
            return '';
        }
    }

    /**
     * Merge fields into the active record. No-op if no record is active or
     * if any error occurs (best-effort).
     */
    public function update(array $fields): void
    {
        if ($this->active === '') return;
        try {
            foreach ($fields as $k => $v) $this->record[$k] = $v;
        } catch (\Throwable $e) { /* swallow */ }
    }

    /**
     * Finalize and flush the active record. Sets t_total_ms + http_status,
     * appends a single JSON line to the log file with LOCK_EX, then clears
     * state. Best-effort: any I/O error is logged via log_message and ignored.
     */
    public function commit(int $httpStatus = 200): void
    {
        if ($this->active === '') return;
        try {
            $this->record['t_total_ms'] = (int) ((microtime(true) - $this->t0) * 1000);
            // Allow update() to have set http_status explicitly (4xx/5xx paths)
            if (!isset($this->record['http_status'])) {
                $this->record['http_status'] = $httpStatus;
            }
            $line = json_encode($this->record) . "\n";
            @file_put_contents(APPPATH . self::LOG_RELATIVE, $line, FILE_APPEND | LOCK_EX);
        } catch (\Throwable $e) {
            if (function_exists('log_message')) {
                log_message('warning', 'Stream_b_telemetry::commit failed: ' . $e->getMessage());
            }
        } finally {
            $this->record = [];
            $this->active = '';
        }
    }

    /** Discard the active record without flushing. */
    public function abort(): void
    {
        $this->record = [];
        $this->active = '';
    }

    /** Test helper: returns the current in-flight record (or empty array). */
    public function _peek(): array { return $this->record; }

    private function _uuid(): string
    {
        return sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand(0, 0xffff), mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            (mt_rand(0, 0x0fff) | 0x4000),
            (mt_rand(0, 0x3fff) | 0x8000),
            mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
        );
    }
}
