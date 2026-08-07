<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Transport_probe_con7 — CON-7 notice-idempotency claim-before-fanout probe.
 *
 * Validates, against LIVE Firestore, the create-if-not-exists CAS claim +
 * release mechanism that Transport_notifier now uses to make deduped-category
 * emission race-safe (claim BEFORE fanout, release on empty/failed fanout):
 *
 *   G1 createEntity(claim) on a fresh id → TRUE (first caller wins → emits)
 *   G2 createEntity(same id) again      → FALSE (409 already claimed → the
 *      concurrent loser dedupes instead of double-emitting)
 *   G3 release (removeEntity) then createEntity again → TRUE (a fanout that
 *      emitted nothing / threw releases its claim, so a retry can re-claim)
 *
 * Sequential calls exercise the exact atomic primitive; true concurrency is
 * guaranteed by Firestore's single-winner create semantics (same 409-CAS the
 * numbering claim docs rely on). Self-teardown. CLI-only.
 *   SCHOOL_ID=SCH_D94FE8F7AD SESSION_YEAR=2026-27 \
 *     php index.php transport_probe_con7 run
 */
class Transport_probe_con7 extends CI_Controller
{
    private const COL = 'transportNoticeIdempotency';

    public function __construct()
    {
        parent::__construct();
        if (!$this->input->is_cli_request()) {
            show_error('Transport_probe_con7 is CLI-only.', 403);
        }
        $this->load->library('firebase',          null, 'firebase');
        $this->load->library('firestore_service', null, 'fs');
    }

    public function run(): void
    {
        $sid  = (string) (getenv('SCHOOL_ID')    ?: '');
        $sess = (string) (getenv('SESSION_YEAR') ?: '');
        if ($sid === '' || $sess === '') {
            fwrite(STDERR, "ERROR: SCHOOL_ID + SESSION_YEAR required.\n");
            exit(1);
        }
        try {
            $this->fs->init($sid, $sess);
        } catch (\Throwable $e) {
            fwrite(STDERR, "ERROR: init failed — " . $e->getMessage() . "\n");
            exit(1);
        }

        $passed = 0; $failed = 0;
        $pass = function (string $m) use (&$passed) { echo "  PASS: {$m}\n"; $passed++; };
        $fail = function (string $m) use (&$failed) { echo "  FAIL: {$m}\n"; $failed++; };

        $entityId = 'PROBE_CON7_' . (int) (microtime(true) * 1000);
        $doc = ['category' => 'probe.category', 'claimedAt' => date('c'), 'expiresAt' => date('c', time() + 300)];

        echo "==================================================\n";
        echo " CON-7 Notice Idempotency Claim (CAS) Probe\n";
        echo " tenant={$sid} doc={$entityId}\n";
        echo "==================================================\n";

        try {
            echo "\n[G1] first claim — createEntity must WIN\n";
            $r1 = $this->fs->createEntity(self::COL, $entityId, $doc);
            $r1 === true ? $pass("first createEntity won the claim")
                         : $fail("first createEntity returned " . var_export($r1, true));

            echo "\n[G2] duplicate claim — createEntity must LOSE (409 → dedupe)\n";
            $r2 = $this->fs->createEntity(self::COL, $entityId, $doc);
            $r2 === false ? $pass("second createEntity lost (already claimed) — concurrent double-fire dedupes")
                          : $fail("second createEntity returned " . var_export($r2, true) . " (expected false)");

            echo "\n[G3] release then re-claim — retry must succeed after release\n";
            $this->fs->removeEntity(self::COL, $entityId);
            $r3 = $this->fs->createEntity(self::COL, $entityId, $doc);
            $r3 === true ? $pass("re-claim after release won — retry is not permanently blocked")
                         : $fail("re-claim returned " . var_export($r3, true) . " (expected true)");
        } catch (\Throwable $e) {
            $fail("exception: " . $e->getMessage());
        } finally {
            echo "\n[TEARDOWN]\n";
            try { $this->fs->removeEntity(self::COL, $entityId); echo "  removed {$entityId}\n"; }
            catch (\Throwable $e) { /* best-effort */ }
        }

        echo "\n==================================================\n";
        echo " RESULT: passed={$passed} failed={$failed}\n";
        echo "==================================================\n";
        exit($failed === 0 ? 0 : 2);
    }
}
