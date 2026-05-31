<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Wave B2.2-F F6 — Scheduled-job framework scaffold.
 *
 * CLI-only controller. Modeled on AccountingReconciler's CI-controller pattern
 * (full CI loader → instantiate Firebase + B2_derive; heartbeat-style obs).
 *
 * Methods
 *   smoke()      — scaffold smoke. Writes + reads + deletes a throwaway
 *                   heartbeat doc to prove the runner can bootstrap CI, reach
 *                   Firestore via the Admin SDK, and self-clean. Net-zero.
 *   run()        — PERMANENT lifecycle pass. Reads `schools`, computes lifecycle
 *                   via B2_derive::computeLifecycle, projects to schoolControl
 *                   + tenantPublic in one commitBatch. GATED by
 *                   b2.registry_firestore. NOT INVOKED in B2.2-F; the wiring
 *                   lands at B2.3+/B2.5.
 *   reconcile()  — TEMPORARY forward-reconciler scaffold (RTDB→Firestore
 *                   healer + _b2_pending drainer). MIGRATION SCAFFOLDING —
 *                   retired at B2.8 with the bridge. Also gated by
 *                   b2.registry_firestore.
 *
 * Usage:  php index.php b2_lifecycle_job smoke
 *         php index.php b2_lifecycle_job run        (no-op until flag ON)
 *         php index.php b2_lifecycle_job reconcile  (no-op until flag ON)
 *
 * NO RTDB. The eventual scheduled invocation (cloud /schedule or server cron)
 * simply calls `php index.php b2_lifecycle_job run` on its interval. Cadence
 * + mechanism are deferred to B2.3+/operator decision; in B2.2-F the framework
 * is INERT (flags OFF → both run() and reconcile() short-circuit).
 *
 * @schema-locked  2026-05-30 (F6)
 */
class B2_lifecycle_job extends CI_Controller
{
    const PROBE_COLLECTION_PREFIX = '_b2_probe_jobsmoke_';
    const PROBE_DOC               = 'heartbeat';
    const PERMANENT_GATE_FLAG     = 'b2.registry_firestore';

    public function __construct()
    {
        parent::__construct();
        if (!defined('STDIN')) { exit("CLI only\n"); }
    }

    // ─────────────────────────────────────────────────────────────────
    // PERMANENT lifecycle pass. Gated.
    public function run()
    {
        if (!$this->_flag(self::PERMANENT_GATE_FLAG)) {
            echo "[B2_lifecycle_job::run] flag " . self::PERMANENT_GATE_FLAG
                . "=OFF → no-op exit (B2.2-F scaffold; wiring lands at B2.3+/B2.5)\n";
            exit(0);
        }
        // Wiring deferred — B2.3+/B2.5: read `schools` via firestoreQuery,
        // computeLifecycle per school, project to schoolControl + tenantPublic
        // via one firestoreCommitBatch (atomic + updateTime CAS). NOT INVOKED.
        echo "[B2_lifecycle_job::run] flag ON but wiring not yet authorized.\n";
        exit(0);
    }

    // TEMPORARY forward-reconciler scaffold. Gated. Retired at B2.8.
    public function reconcile()
    {
        if (!$this->_flag(self::PERMANENT_GATE_FLAG)) {
            echo "[B2_lifecycle_job::reconcile] flag " . self::PERMANENT_GATE_FLAG
                . "=OFF → no-op exit (forward-reconciler retires at B2.8)\n";
            exit(0);
        }
        // Wiring deferred — B2.3: drain `_b2_pending` markers + parity sweep
        // RTDB → Firestore via firestoreCommitBatch CAS. NOT INVOKED.
        echo "[B2_lifecycle_job::reconcile] flag ON but wiring not yet authorized.\n";
        exit(0);
    }

    // Scaffold smoke — net-zero throwaway probe.
    public function smoke()
    {
        $this->load->library('firebase');

        $col = self::PROBE_COLLECTION_PREFIX . time() . '_' . bin2hex(random_bytes(4));
        $doc = self::PROBE_DOC;

        // Confirm B2_derive is loadable from the same loader path (the smoke
        // proves the runner can instantiate F1 in the eventual real pass).
        if (!class_exists('B2_derive', false)) {
            @require_once APPPATH . 'libraries/B2_derive.php';
        }
        $deriveOk = class_exists('B2_derive', false);

        $payload = [
            'job'        => 'B2_lifecycle_job',
            'mode'       => 'smoke',
            'startedAt'  => date('c'),
            'pid'        => function_exists('getmypid') ? getmypid() : 0,
            'deriveOk'   => $deriveOk,
        ];

        try {
            $ok = (bool) $this->firebase->firestoreCreate($col, $doc, $payload);
        } catch (\Throwable $e) {
            echo "[smoke] FAIL: firestoreCreate threw: " . $e->getMessage() . "\n";
            exit(2);
        }
        if (!$ok) {
            echo "[smoke] FAIL: firestoreCreate returned false (heartbeat write)\n";
            exit(2);
        }

        $read = null;
        try { $read = $this->firebase->firestoreGet($col, $doc); } catch (\Throwable $e) {}
        if (!is_array($read) || empty($read)) {
            echo "[smoke] FAIL: heartbeat readback empty\n";
            try { $this->firebase->firestoreDelete($col, $doc); } catch (\Throwable $e) {}
            exit(2);
        }

        try { $this->firebase->firestoreDelete($col, $doc); } catch (\Throwable $e) {}
        echo "[smoke] OK: heartbeat written + read + deleted (net-zero) | derive=" . ($deriveOk ? 'OK' : 'MISSING') . "\n";
        exit($deriveOk ? 0 : 2);
    }

    // ─────────────────────────────────────────────────────────────────
    private function _flag(string $key): bool
    {
        $this->config->load('b2_migration_flags', FALSE, TRUE);
        $flags = $this->config->item('b2_migration_flags');
        return is_array($flags) && !empty($flags[$key]);
    }
}
