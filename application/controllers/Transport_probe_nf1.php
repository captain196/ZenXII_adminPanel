<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Transport_probe_nf1 — Firestore-native student search (NF-1, Option B).
 *
 * The Transport controller's search_students() called
 * $this->operations_accounting->search_students() but F3 removed that library
 * load → undefined property → fatal 500. Fix routes the search through
 * Transport_service::searchStudents() (Firestore-native, school-scoped, cached).
 *
 *   G1 name substring match returns the right students in the expected shape
 *   G2 id substring match works
 *   G3 limit is honoured
 *   G4 <2 chars returns [] (no engine call, no fatal)
 *   G5 cross-tenant: searching under a different tenant returns none (school-scoped)
 *
 * Clears the per-school cache key around the run so it reads fresh. Self-teardown.
 * CLI-only.
 *   SCHOOL_ID=SCH_D94FE8F7AD SESSION_YEAR=2026-27 \
 *     php index.php transport_probe_nf1 run
 */
class Transport_probe_nf1 extends CI_Controller
{
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
        $this->fs->init($sid, $sess);
        $this->numbering->init($this->fs, $sid, $sess);
        $this->transport->init($this->fs, $this->numbering, $sid, $sess, 'probe_nf1');

        $passed = 0; $failed = 0;
        $pass = function (string $m) use (&$passed) { echo "  PASS: {$m}\n"; $passed++; };
        $fail = function (string $m) use (&$failed) { echo "  FAIL: {$m}\n"; $failed++; };

        $ts  = (int) (microtime(true) * 1000);
        $now = date('c');
        // Distinct, unique-per-run names/ids so cache/other data never collide.
        $tag = "ZQ{$ts}";                    // rare token → won't match real students
        $sA = "PROBE_NF1_A_{$ts}"; $nA = "{$tag}alice";
        $sB = "PROBE_NF1_B_{$ts}"; $nB = "{$tag}bob";
        $sC = "PROBE_NF1_C_{$ts}"; $nC = "{$tag}alfred";
        $cacheKey = 'trn_students_' . md5($sid);
        $clearCache = function () use ($cacheKey) {
            try { $this->load->driver('cache', ['adapter' => 'file']); $this->cache->delete($cacheKey); } catch (\Throwable $e) {}
        };

        echo "==================================================\n";
        echo " NF-1 Firestore-native student search probe\n";
        echo " tenant={$sid}\n";
        echo "==================================================\n";

        try {
            $clearCache();
            $this->fs->setEntity('students', $sA, ['studentId'=>$sA,'userId'=>$sA,'schoolId'=>$sid,'name'=>$nA,'className'=>'Class 5','section'=>'A','status'=>'Active','session'=>$sess,'created_at'=>$now], false);
            $this->fs->setEntity('students', $sB, ['studentId'=>$sB,'userId'=>$sB,'schoolId'=>$sid,'name'=>$nB,'className'=>'Class 6','section'=>'B','status'=>'Active','session'=>$sess,'created_at'=>$now], false);
            $this->fs->setEntity('students', $sC, ['studentId'=>$sC,'userId'=>$sC,'schoolId'=>$sid,'name'=>$nC,'className'=>'Class 7','section'=>'C','status'=>'Active','session'=>$sess,'created_at'=>$now], false);
            $this->fixtures = [$sA, $sB, $sC];
            $clearCache();   // force fresh read incl. seeds

            echo "\n[G1] name substring '{$tag}al' matches alice + alfred, correct shape\n";
            $r1 = (array) $this->transport->searchStudents("{$tag}al");
            $ids = array_map(fn($x) => (string)($x['id'] ?? ''), $r1);
            sort($ids); $exp = [$sA, $sC]; sort($exp);
            $shapeOk = $r1 !== [] && isset($r1[0]['id'], $r1[0]['name'], $r1[0]['class'], $r1[0]['section'], $r1[0]['user_id']);
            ($ids === $exp && $shapeOk)
                ? $pass("2 matches with full shape {id,name,class,section,user_id}: " . implode(', ', $ids))
                : $fail("got " . json_encode($ids) . " shapeOk=" . var_export($shapeOk, true));

            echo "\n[G2] id substring matches the exact student\n";
            $r2 = (array) $this->transport->searchStudents(strtolower($sB));
            (count($r2) === 1 && (string)($r2[0]['id'] ?? '') === $sB)
                ? $pass("id search returned {$sB}")
                : $fail("id search wrong: " . json_encode(array_map(fn($x)=>$x['id']??'?', $r2)));

            echo "\n[G3] limit honoured\n";
            $r3 = (array) $this->transport->searchStudents($tag, 2);   // all 3 share $tag
            (count($r3) === 2)
                ? $pass("limit=2 returned 2 of 3 matches")
                : $fail("limit not honoured: got " . count($r3));

            echo "\n[G4] <2 chars returns [] (no fatal, no engine)\n";
            $r4 = (array) $this->transport->searchStudents('a');
            ($r4 === [])
                ? $pass("short query returned [] gracefully")
                : $fail("short query returned " . count($r4));

            echo "\n[G5] cross-tenant: re-init service to a different tenant → none\n";
            $other = "SCH_PROBE_NF1_OTHER_{$ts}";
            // Re-init BOTH fs and the service to the other tenant (as a real
            // request would) so the cache key AND the school-scoped read both
            // move to the other tenant.
            $this->fs->init($other, $sess);
            $this->numbering->init($this->fs, $other, $sess);
            $this->transport->init($this->fs, $this->numbering, $other, $sess, 'probe_nf1_other');
            try { $this->load->driver('cache', ['adapter'=>'file']); $this->cache->delete('trn_students_' . md5($other)); } catch (\Throwable $e) {}
            $r5 = (array) $this->transport->searchStudents("{$tag}al");
            // restore tenant A for teardown
            $this->fs->init($sid, $sess);
            $this->numbering->init($this->fs, $sid, $sess);
            $this->transport->init($this->fs, $this->numbering, $sid, $sess, 'probe_nf1');
            (count(array_filter($r5, fn($x) => in_array((string)($x['id']??''), [$sA,$sB,$sC], true))) === 0)
                ? $pass("no probe students leaked into another tenant's search (school-scoped read + per-school cache key)")
                : $fail("cross-tenant leak: " . json_encode(array_map(fn($x)=>$x['id']??'?',$r5)));
        } catch (\Throwable $e) {
            $fail("unexpected exception: " . $e->getMessage());
        } finally {
            echo "\n[TEARDOWN]\n";
            foreach ($this->fixtures as $id) { try { $this->fs->removeEntity('students', $id); } catch (\Throwable $e) {} }
            $clearCache();
            echo "  cleaned " . count($this->fixtures) . " docs + cache\n";
        }

        echo "\n==================================================\n";
        echo " RESULT: passed={$passed} failed={$failed}\n";
        echo "==================================================\n";
        exit($failed === 0 ? 0 : 2);
    }
}
