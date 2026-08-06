<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Transport_probe_recipient — canonical parent-notification recipient contract.
 *
 * The parent-facing fanouts resolved recipients from absent fields
 * (parent_db_key / parentId / parent_id) → ZERO recipients. Fix: a single
 * canonical resolver (_resolveStudentRecipients) maps studentId → the student
 * doc's `userId` field (the id the Parent App authenticates as and queries its
 * inbox by). This validates delivery the way the Parent App reads it — by the
 * `userId` field — plus dedup + cross-tenant isolation.
 *
 *   G1 _resolveStudentRecipients returns the student `userId` FIELD (not studentId)
 *   G2 duplicate + repeated student ids dedupe to one userId each
 *   G3 trip.student_no_show (PARENTS_OF_STUDENTS) writes a notification the
 *      Parent App would find via userId == the student's userId
 *   G4 trip.delay (PARENTS_OF_ROUTE) delivers to every rostered student's userId
 *   G5 cross-tenant: resolving the same studentId under a DIFFERENT tenant
 *      returns nothing (school-scoped reads — no recipient leakage)
 *
 * Self-teardown. CLI-only.
 *   SCHOOL_ID=SCH_D94FE8F7AD SESSION_YEAR=2026-27 \
 *     php index.php transport_probe_recipient run
 */
class Transport_probe_recipient extends CI_Controller
{
    private array $fixtures = [];       // [collection, docId] via fs->removeEntity
    private array $rawFixtures = [];    // full docId via firestoreDelete

    public function __construct()
    {
        parent::__construct();
        if (!$this->input->is_cli_request()) { show_error('CLI-only.', 403); }
        $this->load->library('firebase',          null, 'firebase');
        $this->load->library('firestore_service', null, 'fs');
        $this->load->library('numbering_service', null, 'numbering');
        $this->load->library('transport_service', null, 'transport');
        $this->load->library('transport_notifier', null, 'notifier');
    }

    public function run(): void
    {
        $sid  = (string) (getenv('SCHOOL_ID')    ?: '');
        $sess = (string) (getenv('SESSION_YEAR') ?: '');
        if ($sid === '' || $sess === '') { fwrite(STDERR, "ERROR: SCHOOL_ID + SESSION_YEAR required.\n"); exit(1); }
        try {
            $this->fs->init($sid, $sess);
            $this->numbering->init($this->fs, $sid, $sess);
            $this->transport->init($this->fs, $this->numbering, $sid, $sess, 'probe_recipient');
            $this->notifier->init($this->fs, $this->numbering, $this->transport, $sid, $sess, 'probe_recipient');
        } catch (\Throwable $e) { fwrite(STDERR, "ERROR: init — " . $e->getMessage() . "\n"); exit(1); }

        $passed = 0; $failed = 0;
        $pass = function (string $m) use (&$passed) { echo "  PASS: {$m}\n"; $passed++; };
        $fail = function (string $m) use (&$failed) { echo "  FAIL: {$m}\n"; $failed++; };

        $ts  = (int) (microtime(true) * 1000);
        $now = date('c');
        $stuX = "PROBE_RCP_STU_X_{$ts}"; $uX = "PROBE_RCP_UID_X_{$ts}";
        $stuY = "PROBE_RCP_STU_Y_{$ts}"; $uY = "PROBE_RCP_UID_Y_{$ts}";
        $rt   = "PROBE_RCP_RT_{$ts}";

        $reflResolve = new \ReflectionMethod($this->notifier, '_resolveStudentRecipients');
        $reflResolve->setAccessible(true);
        $resolve = fn(array $ids) => (array) $reflResolve->invoke($this->notifier, $ids);

        // Query notifications the way the Parent App does: by the userId field.
        $inbox = function (string $uid, string $noticeId) use ($sid, $sess): int {
            try {
                $rows = (array) $this->fs->schoolWhere('notifications', [['userId', '==', $uid]], null, 'ASC', 50);
            } catch (\Throwable $_) { return 0; }
            $n = 0;
            foreach ($rows as $r) {
                $inner = (isset($r['data']) && is_array($r['data'])) ? $r['data'] : $r;
                if ((string) ($inner['userId'] ?? '') === $uid
                    && (string) (($inner['data']['groupId'] ?? '')) === $noticeId) {
                    $n++;
                    $this->rawFixtures[] = ['notifications', (string) ($r['id'] ?? '')];
                }
            }
            return $n;
        };

        echo "==================================================\n";
        echo " Canonical parent-notification recipient probe\n";
        echo " tenant={$sid} session={$sess}\n";
        echo "==================================================\n";

        try {
            // Seed two students with DISTINCT userId fields (userId != studentId).
            $this->fs->setEntity('students', $stuX, ['studentId'=>$stuX,'schoolId'=>$sid,'userId'=>$uX,'name'=>'Rcp X','status'=>'Active','session'=>$sess,'created_at'=>$now], false);
            $this->fs->setEntity('students', $stuY, ['studentId'=>$stuY,'schoolId'=>$sid,'userId'=>$uY,'name'=>'Rcp Y','status'=>'Active','session'=>$sess,'created_at'=>$now], false);
            $this->fixtures[] = ['students', $stuX]; $this->fixtures[] = ['students', $stuY];
            // Route membership for both (parents_of_route path).
            foreach ([$stuX, $stuY] as $s) {
                $srid = "PROBE_RCP_SR_{$s}";
                $this->fs->setEntity('studentRoutes', $srid, ['schoolId'=>$sid,'studentId'=>$s,'route_id'=>$rt,'session'=>$sess,'status'=>'Active','created_at'=>$now], false);
                $this->fixtures[] = ['studentRoutes', $srid];
            }

            echo "\n[G1] resolver returns the student userId FIELD (not studentId)\n";
            $r1 = $resolve([$stuX, $stuY]); sort($r1); $exp = [$uX, $uY]; sort($exp);
            ($r1 === $exp)
                ? $pass("resolved to userId fields: " . implode(', ', $r1))
                : $fail("got " . json_encode($r1) . " expected " . json_encode($exp));

            echo "\n[G2] duplicate/repeated student ids dedupe\n";
            $r2 = $resolve([$stuX, $stuX, $stuY, $stuX]); sort($r2);
            ($r2 === $exp)
                ? $pass("deduped to " . implode(', ', $r2))
                : $fail("dedup failed: " . json_encode($r2));

            echo "\n[G3] trip.student_no_show delivered to the student's userId inbox\n";
            $res3 = (array) $this->notifier->notify('trip.student_no_show', [
                'trip_id'=>"PROBE_RCP_TRIP_{$ts}", 'student_id'=>$stuX, 'student_ids'=>[$stuX],
                'route_id'=>$rt, 'trip_date'=>date('Y-m-d'), 'direction'=>'Pickup',
            ]);
            $nid3 = (string) ($res3['notice_id'] ?? '');
            ((int)($res3['per_user_count'] ?? 0) === 1 && $inbox($uX, $nid3) === 1 && $inbox($uY, $nid3) === 0)
                ? $pass("delivered to userId={$uX} only (per_user_count=1); UY correctly not notified")
                : $fail("per_user_count=" . ($res3['per_user_count'] ?? '?') . " inboxX=" . $inbox($uX,$nid3) . " inboxY=" . $inbox($uY,$nid3));

            echo "\n[G4] trip.delay (parents_of_route) delivered to BOTH rostered userIds\n";
            $res4 = (array) $this->notifier->notify('trip.delay', [
                'route_id'=>$rt, 'trip_id'=>"PROBE_RCP_TRIP2_{$ts}", 'delay_minutes'=>10, 'trip_date'=>date('Y-m-d'),
            ]);
            $nid4 = (string) ($res4['notice_id'] ?? '');
            ((int)($res4['per_user_count'] ?? 0) === 2 && $inbox($uX, $nid4) === 1 && $inbox($uY, $nid4) === 1)
                ? $pass("delivered to both userIds {$uX},{$uY} (per_user_count=2)")
                : $fail("per_user_count=" . ($res4['per_user_count'] ?? '?') . " inboxX=" . $inbox($uX,$nid4) . " inboxY=" . $inbox($uY,$nid4));

            echo "\n[G5] cross-tenant isolation: resolve under a different tenant → empty\n";
            $otherSid = "SCH_PROBE_OTHER_{$ts}";
            $this->fs->init($otherSid, $sess);          // re-point fs to a different tenant
            $r5 = $resolve([$stuX, $stuY]);
            $this->fs->init($sid, $sess);               // restore tenant A for teardown
            ($r5 === [])
                ? $pass("no recipients resolved for another tenant (school-scoped reads — no leakage)")
                : $fail("cross-tenant leak: " . json_encode($r5));
        } catch (\Throwable $e) {
            $fail("unexpected exception: " . $e->getMessage());
        } finally {
            echo "\n[TEARDOWN]\n";
            foreach ($this->fixtures as [$col, $id]) { try { $this->fs->removeEntity($col, $id); } catch (\Throwable $e) {} }
            foreach ($this->rawFixtures as [$col, $id]) { if ($id !== '') { try { $this->firebase->firestoreDelete($col, $id); } catch (\Throwable $e) {} } }
            echo "  cleaned " . (count($this->fixtures) + count($this->rawFixtures)) . " docs\n";
        }

        echo "\n==================================================\n";
        echo " RESULT: passed={$passed} failed={$failed}\n";
        echo "==================================================\n";
        exit($failed === 0 ? 0 : 2);
    }
}
