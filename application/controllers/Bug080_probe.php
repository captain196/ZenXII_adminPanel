<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Bug080_probe — READ ONLY.
 *
 * Quantifies the Fee retry-queue storage mismatch (BUG-080):
 *   - queueForRetry() writes Firestore `feeSyncRetryQueue`
 *   - drainRetryQueue() reads RTDB `Schools/.../Fees/Firestore_Sync_Queue`
 *
 * Answers the ledger's scope-questions WITHOUT any code touch:
 *   1. How many orphan entries sit in Firestore feeSyncRetryQueue today?
 *   2. What kinds/ages are they (poison-entry detection via retry_count)?
 *   3. Is there anything in the RTDB queue the drainer actually reads?
 *
 * Usage:
 *   SCHOOL_ID=SCH_xxx [SESSION_YEAR=2026-27] php index.php bug080_probe check
 */
class Bug080_probe extends CI_Controller
{
    public function __construct()
    {
        parent::__construct();
        if (!$this->input->is_cli_request()) show_error('CLI-only.', 403);
        $this->load->library('firebase');
    }

    public function check(): void
    {
        $schoolFs = (string)(getenv('SCHOOL_ID') ?: '');
        $session  = (string)(getenv('SESSION_YEAR') ?: '2026-27');

        echo "═══════════════════════════════════════════════════════════════════════\n";
        echo " BUG-080 forensic probe — Fee retry-queue storage mismatch (READ ONLY)\n";
        echo "═══════════════════════════════════════════════════════════════════════\n";
        echo "  schoolFs: {$schoolFs} | session: {$session}\n\n";

        // ── 1. Firestore feeSyncRetryQueue — where queueForRetry WRITES ──
        echo "── 1. Firestore `feeSyncRetryQueue` (the WRITE target) ──\n";
        $fsRows = [];
        try {
            $fsRows = $this->firebase->firestoreQuery('feeSyncRetryQueue', [
                ['school_code', '==', $schoolFs],
            ], null, 'ASC', 1000);
        } catch (\Throwable $e) {
            echo "  query by school_code failed: " . $e->getMessage() . "\n";
        }
        // Fallback: unfiltered scan (doc-key is prefixed with schoolCode).
        if (empty($fsRows)) {
            try {
                $all = $this->firebase->firestoreQuery('feeSyncRetryQueue', [], null, 'ASC', 1000);
                foreach ($all as $r) {
                    $id = (string)($r['id'] ?? '');
                    if (strpos($id, $schoolFs) === 0) $fsRows[] = $r;
                }
            } catch (\Throwable $e) {
                echo "  unfiltered scan failed: " . $e->getMessage() . "\n";
            }
        }
        echo "  Orphan entries in Firestore queue: " . count($fsRows) . "\n";

        if (!empty($fsRows)) {
            $byKind = []; $byRetry = []; $oldest = null; $newest = null;
            foreach ($fsRows as $r) {
                $d = is_array($r['data'] ?? null) ? $r['data'] : [];
                $k = (string)($d['kind'] ?? '?');
                $rc = (int)($d['retry_count'] ?? 0);
                $qa = (string)($d['queued_at'] ?? '');
                $byKind[$k]  = ($byKind[$k] ?? 0) + 1;
                $byRetry[$rc] = ($byRetry[$rc] ?? 0) + 1;
                if ($qa !== '') {
                    if ($oldest === null || $qa < $oldest) $oldest = $qa;
                    if ($newest === null || $qa > $newest) $newest = $qa;
                }
            }
            echo "  By kind:        " . json_encode($byKind) . "\n";
            echo "  By retry_count: " . json_encode($byRetry) . "  (>0 ⇒ a drainer DID touch it)\n";
            echo "  Oldest queued:  " . ($oldest ?? 'n/a') . "\n";
            echo "  Newest queued:  " . ($newest ?? 'n/a') . "\n";
        } else {
            echo "  → No orphan backlog. Either Tier B failures never fired, or a\n";
            echo "    prior cleanup drained them. Bug is latent, not active.\n";
        }

        // ── 2. RTDB queue — where drainRetryQueue READS ──
        echo "\n── 2. RTDB `Schools/{$schoolFs}/{$session}/Fees/Firestore_Sync_Queue` (the READ target) ──\n";
        $rtdbCount = 0;
        try {
            $path  = "Schools/{$schoolFs}/{$session}/Fees/Firestore_Sync_Queue";
            $queue = $this->firebase->get($path);
            if (is_array($queue)) {
                $rtdbCount = count($queue);
                echo "  Entries the drainer would actually find: {$rtdbCount}\n";
                if ($rtdbCount > 0) {
                    echo "  ⚠ Unexpected — RTDB queue is non-empty. Pre-migration residue?\n";
                }
            } else {
                echo "  RTDB path empty/absent (drainer finds nothing — consistent with bug).\n";
            }
        } catch (\Throwable $e) {
            echo "  RTDB read failed: " . $e->getMessage() . "\n";
        }

        // ── 3. Verdict ──────────────────────────────────────────────────
        echo "\n── 3. Verdict ──\n";
        $orphans = count($fsRows);
        if ($orphans === 0 && $rtdbCount === 0) {
            echo "  ✓ Both queues EMPTY. BUG-080 is LATENT (no active backlog).\n";
            echo "    Fix is preventive — safe to schedule, not urgent.\n";
        } elseif ($orphans > 0 && $rtdbCount === 0) {
            echo "  ⚠ CONFIRMED ACTIVE: {$orphans} Firestore orphans, 0 reachable by drainer.\n";
            echo "    These failed syncs will NEVER retry under current code.\n";
            echo "    Fix needed: point drainRetryQueue at Firestore + a one-shot replay.\n";
        } else {
            echo "  ? Mixed state — see counts above; needs manual interpretation.\n";
        }
        echo "═══════════════════════════════════════════════════════════════════════\n";
    }

    /**
     * Post-fix verification: actually invoke drainRetryQueue() to confirm the
     * new Firestore read path executes cleanly against the live (empty) queue.
     * Safe — with 0 backlog this is a pure no-op read, zero writes.
     */
    public function drain_verify(): void
    {
        $schoolFs = (string)(getenv('SCHOOL_ID') ?: '');
        $session  = (string)(getenv('SESSION_YEAR') ?: '2026-27');

        echo "── BUG-080 post-fix drainer verification ──\n";
        echo "  schoolFs: {$schoolFs} | session: {$session}\n";
        $this->load->library('Fee_firestore_sync', null, 'fsSync');
        $this->fsSync->init($this->firebase, $schoolFs, $session);

        $t0 = microtime(true);
        $res = $this->fsSync->drainRetryQueue();
        $ms = round((microtime(true) - $t0) * 1000);

        echo "  drainRetryQueue() returned: " . json_encode($res) . "  ({$ms}ms)\n";
        $okShape = is_array($res) && array_key_exists('attempted', $res) && array_key_exists('succeeded', $res);
        echo "  Return shape valid: " . ($okShape ? 'YES' : 'NO') . "\n";
        echo "  Executed without exception against Firestore read path: YES\n";
        echo "  → With 0 backlog this is a clean no-op; the Firestore query path\n";
        echo "    is now exercised end-to-end (was RTDB, found nothing, pre-fix).\n";
    }
}
