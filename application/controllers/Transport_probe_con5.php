<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Transport_probe_con5 — rollover history idempotency (CON-5).
 *
 * _appendAssignmentHistoryEvent minted a numbering id + wrote merge:false, so a
 * concurrent rollover re-pump double-processed a chunk → DUPLICATE session_rolled
 * history docs + a doubled numbering counter. Fix: in a batch/rollover context
 * (batchId != '') use a DETERMINISTIC id per (student, batch, event) so a
 * re-pump overwrites the same doc. Manual actions keep sequential numbering.
 *
 *   G1 two batch appends for the same (student,batch,event) → SAME id, ONE doc
 *   G2 two manual appends (no batchId) → DIFFERENT ids, TWO docs (distinct actions)
 *
 * Reflection-drives the private helper. Self-teardown. CLI-only.
 *   SCHOOL_ID=SCH_D94FE8F7AD SESSION_YEAR=2026-27 \
 *     php index.php transport_probe_con5 run
 */
class Transport_probe_con5 extends CI_Controller
{
    private const COL = 'routeAssignmentHistory';
    private array $fixtures = [];

    public function __construct()
    {
        parent::__construct();
        if (!$this->input->is_cli_request()) { show_error('CLI-only.', 403); }
        $this->load->library('firebase',          null, 'firebase');
        $this->load->library('firestore_service', null, 'fs');
        $this->load->library('numbering_service', null, 'numbering');
        $this->load->library('transport_service', null, 'transport');
    }

    public function run(): void
    {
        $sid  = (string) (getenv('SCHOOL_ID')    ?: '');
        $sess = (string) (getenv('SESSION_YEAR') ?: '');
        if ($sid === '' || $sess === '') { fwrite(STDERR, "ERROR: SCHOOL_ID + SESSION_YEAR required.\n"); exit(1); }
        try {
            $this->fs->init($sid, $sess);
            $this->numbering->init($this->fs, $sid, $sess);
            $this->transport->init($this->fs, $this->numbering, $sid, $sess, 'transport_probe_con5');
        } catch (\Throwable $e) { fwrite(STDERR, "ERROR: init — " . $e->getMessage() . "\n"); exit(1); }

        $passed = 0; $failed = 0;
        $pass = function (string $m) use (&$passed) { echo "  PASS: {$m}\n"; $passed++; };
        $fail = function (string $m) use (&$failed) { echo "  FAIL: {$m}\n"; $failed++; };

        $rm = new \ReflectionMethod($this->transport, '_appendAssignmentHistoryEvent');
        $rm->setAccessible(true);
        $append = fn(string $stu, string $evt, string $src, string $batch) =>
            (string) $rm->invoke($this->transport, $stu, $sess, $evt, ['session'=>'A'], ['session'=>'B'], $src, $batch, 'probe');

        $ts    = (int) (microtime(true) * 1000);
        $stu   = "PROBE_C5_STU_{$ts}";
        $batch = "PROBE_C5_BATCH_{$ts}";

        echo "==================================================\n";
        echo " CON-5 rollover history idempotency probe\n";
        echo " tenant={$sid} session={$sess}\n";
        echo "==================================================\n";

        try {
            echo "\n[G1] two batch appends (same student,batch,event) → one doc\n";
            $id1 = $append($stu, 'session_rolled', 'rollover', $batch);
            $id2 = $append($stu, 'session_rolled', 'rollover', $batch);   // concurrent re-pump
            $this->fixtures[] = $id1; $this->fixtures[] = $id2;
            $doc1 = $this->fs->getEntity(self::COL, $id1);
            ($id1 === $id2 && is_array($doc1))
                ? $pass("deterministic id reused ({$id1}) → single history doc on re-pump")
                : $fail("ids differ or doc missing: id1={$id1} id2={$id2}");

            echo "\n[G2] two manual appends (no batchId) → two distinct docs\n";
            $m1 = $append($stu, 'updated', 'manual', '');
            $m2 = $append($stu, 'updated', 'manual', '');
            $this->fixtures[] = $m1; $this->fixtures[] = $m2;
            ($m1 !== $m2 && is_array($this->fs->getEntity(self::COL, $m1)) && is_array($this->fs->getEntity(self::COL, $m2)))
                ? $pass("manual actions keep sequential numbering ({$m1} != {$m2}) → two distinct docs")
                : $fail("manual ids not distinct: m1={$m1} m2={$m2}");
        } catch (\Throwable $e) {
            $fail("unexpected exception: " . $e->getMessage());
        } finally {
            echo "\n[TEARDOWN]\n";
            foreach (array_unique($this->fixtures) as $id) { try { $this->fs->removeEntity(self::COL, $id); } catch (\Throwable $e) {} }
            echo "  cleaned\n";
        }

        echo "\n==================================================\n";
        echo " RESULT: passed={$passed} failed={$failed}\n";
        echo "==================================================\n";
        exit($failed === 0 ? 0 : 2);
    }
}
