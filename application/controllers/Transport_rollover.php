<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Transport_rollover — F3 Student Assignment session rollover (2026-07-07).
 *
 * Auto-continuing chunked rollover (Refinement #1): server processes chunks
 * sequentially in one invocation until either:
 *   - All eligible students processed (complete)
 *   - Wall-clock ceiling hit inside Transport_service (returns in_progress;
 *     subsequent invocation resumes from checkpoint)
 *   - Fatal error → checkpoint frozen with status='error' for admin retry
 *
 * Checkpoint is recovery-only, not admin-interaction-required.
 *
 * Endpoints:
 *   POST /transport_rollover/start        — from_session, to_session
 *   POST /transport_rollover/continue     — from_session, to_session (resume)
 *   GET  /transport_rollover/status       — from_session, to_session
 *   POST /transport_rollover/reset        — clears checkpoint (admin power action)
 */
class Transport_rollover extends MY_Controller
{

    public function __construct()
    {
        parent::__construct();
        require_permission('Transport');
        $this->load->library('Fee_lifecycle', null, 'feeLifecycle');
        $this->feeLifecycle->init($this->firebase, $this->school_name, $this->session_year, $this->admin_id ?? 'system');
        $this->load->library('numbering_service', null, 'numbering');
        $this->numbering->init($this->fs, $this->school_name, $this->session_year);
        $this->load->library('transport_service', null, 'transport');
        $this->transport->init(
            $this->fs, $this->numbering,
            $this->school_name, $this->session_year,
            $this->admin_id ?? 'system'
        );
    }

    /**
     * POST /transport_rollover/start
     * Params: from_session, to_session
     *
     * Kicks off (or resumes) a rollover — the service auto-continues chunks
     * until wall-clock ceiling or completion. Client polls /status for
     * progress; on 'in_progress' status, client invokes /continue to
     * resume the remaining students.
     */
    public function start()
    {
        require_permission('Transport', 'transport_rollover_start', 'manage');
        $from = trim($this->input->post('from_session') ?? '');
        $to   = trim($this->input->post('to_session')   ?? '');
        if ($from === '' || $to === '' || $from === $to) {
            $this->json_error('from_session and to_session are required and must differ.');
        }
        try {
            $r = $this->transport->rolloverSession($from, $to);
        } catch (\InvalidArgumentException $e) {
            $this->json_error($e->getMessage());
        } catch (\Throwable $e) {
            log_message('error', 'Transport_rollover::start failed: ' . $e->getMessage());
            $this->json_error('Rollover failed: ' . $e->getMessage());
        }
        // ROLL-1 (2026-08-19): the rollover state must NOT be called 'status'.
        //
        // json_success() does array_merge(['status'=>'success'], $data), so $data
        // WINS: a payload key named 'status' silently overwrote the envelope with
        // 'complete' / 'in_progress'. The client's first line is
        // `if (r.status !== 'success')`, so every response — including a
        // successful one — took the failure branch, painted a red "Failed", and
        // returned. The auto-continue branch that resumes the remaining students
        // was therefore unreachable: a rollover that hit the wall-clock ceiling
        // stopped for good, and a completed one reported failure.
        //
        // Renamed rather than reordered: the envelope's `status` means
        // transport-level success/error everywhere in this codebase, and a
        // payload must not redefine it.
        $this->json_success([
            'rollover_status' => $r['status'],
            'processed'     => $r['processed'],
            'batch_id'      => $r['batch_id'],
            'checkpoint_id' => $r['checkpoint_id'],
            'message'       => $this->_statusMessage($r['status'], $r['processed']),
        ]);
    }

    /**
     * POST /transport_rollover/continue
     * Resumes an in-progress rollover. Same params as start; service reads
     * checkpoint and continues where it left off.
     */
    public function continue_rollover()
    {
        $this->start();   // service is idempotent — checkpoint drives resume
    }

    /** GET /transport_rollover/status?from_session=X&to_session=Y */
    public function status()
    {
        require_permission('Transport', 'transport_view', 'view');
        $from = trim($this->input->get('from_session') ?? '');
        $to   = trim($this->input->get('to_session')   ?? '');
        if ($from === '' || $to === '') {
            $this->json_error('from_session and to_session are required.');
        }
        $docId = "{$this->school_name}_ROLLOVER_{$from}_{$to}";
        $cp = $this->fs->getEntity('rolloverCheckpoint', $docId);
        // ROLL-1: same envelope collision as start() — a payload key named
        // 'status' overwrites json_success()'s own. Reported under
        // rollover_status so a caller can tell "the request succeeded" from
        // "the rollover is not started".
        if (!is_array($cp)) {
            $this->json_success(['rollover_status' => 'not_started', 'processed' => 0, 'checkpoint_id' => $docId]);
        }
        $this->json_success([
            'rollover_status' => (string) ($cp['status'] ?? 'unknown'),
            'processed'       => (int) ($cp['processed_count'] ?? 0),
            'error_count'     => (int) ($cp['error_count']    ?? 0),
            'batch_id'        => (string) ($cp['batch_id']    ?? ''),
            'started_at'      => (string) ($cp['started_at']  ?? ''),
            'last_update_at'  => (string) ($cp['last_update_at'] ?? ''),
            'completed_at'    => (string) ($cp['completed_at'] ?? ''),
            'checkpoint_id'   => $docId,
        ]);
    }

    /**
     * POST /transport_rollover/reset — clears the checkpoint. Power action
     * for when a rollover needs to restart from zero (e.g., aborted mid-way
     * and admin wants to redo).
     */
    public function reset()
    {
        require_permission('Transport', 'transport_rollover_reset', 'manage');
        $from = trim($this->input->post('from_session') ?? '');
        $to   = trim($this->input->post('to_session')   ?? '');
        if ($from === '' || $to === '') {
            $this->json_error('from_session and to_session are required.');
        }
        $docId = "{$this->school_name}_ROLLOVER_{$from}_{$to}";
        try {
            $this->fs->remove('rolloverCheckpoint', $this->fs->docId($docId));
        } catch (\Throwable $e) {
            log_message('error', 'Transport_rollover::reset remove failed: ' . $e->getMessage());
        }
        $this->json_success(['message' => 'Rollover checkpoint cleared.']);
    }

    private function _statusMessage(string $status, int $processed): string
    {
        switch ($status) {
            case 'complete':
                return "Rollover complete: {$processed} students processed.";
            case 'in_progress':
                return "Rollover in progress: {$processed} students so far. Click Continue to resume.";
            case 'error':
                return "Rollover errored after {$processed} students. Check checkpoint for details.";
            default:
                return "Rollover status: {$status}, {$processed} processed.";
        }
    }
}
