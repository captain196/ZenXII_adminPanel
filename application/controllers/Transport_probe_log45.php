<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Transport_probe_log45 — event/trip safety gates (LOG-4 + LOG-5).
 *
 * LOG-5: the roster membership check EXEMPTED no_show, so markStudentNoShow(anyId)
 *   fired a safety-exempt trip.student_no_show to an unrelated parent + inflated
 *   the tally. Fix requires a no_show to be a rostered student.
 * LOG-4: only scheduleEvent validated escort HR-active status; updateEvent /
 *   addEventEscort / dispatchEventTrip did not, so a terminated staff member
 *   could be recorded/kept as a safety escort. Fix extracts _assertEscortsActive
 *   and runs it on every escort-mutating path + at dispatch.
 *
 *   G1 (LOG-5) no_show for a NON-rostered student is rejected
 *   G2 (LOG-5) no_show for a ROSTERED student still succeeds
 *   G3 (LOG-4) updateEvent with a TERMINATED escort is rejected
 *   G4 (LOG-4) updateEvent with an ACTIVE escort succeeds
 *
 * Self-teardown. CLI-only.
 *   SCHOOL_ID=SCH_D94FE8F7AD SESSION_YEAR=2026-27 \
 *     php index.php transport_probe_log45 run
 */
class Transport_probe_log45 extends CI_Controller
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
        try {
            $this->fs->init($sid, $sess);
            $this->numbering->init($this->fs, $sid, $sess);
            $this->transport->init($this->fs, $this->numbering, $sid, $sess, 'transport_probe_log45');
        } catch (\Throwable $e) { fwrite(STDERR, "ERROR: init — " . $e->getMessage() . "\n"); exit(1); }

        $passed = 0; $failed = 0;
        $pass = function (string $m) use (&$passed) { echo "  PASS: {$m}\n"; $passed++; };
        $fail = function (string $m) use (&$failed) { echo "  FAIL: {$m}\n"; $failed++; };

        $ts  = (int) (microtime(true) * 1000);
        $now = date('c');
        $tid = "PROBE_L45_TRP_{$ts}";
        $eid = "PROBE_L45_EVT_{$ts}";
        $stuRostered = "PROBE_L45_STU_R_{$ts}";
        $stuOther    = "PROBE_L45_STU_X_{$ts}";
        $staOk   = "PROBE_L45_STA_OK_{$ts}";
        $staTerm = "PROBE_L45_STA_TERM_{$ts}";

        echo "==================================================\n";
        echo " LOG-4/LOG-5 event+trip safety gate probe\n";
        echo " tenant={$sid} session={$sess}\n";
        echo "==================================================\n";

        try {
            // Trip InProgress with a one-student frozen roster.
            $this->fs->setEntity('tripLogs', $tid, [
                'id'=>$tid,'schoolId'=>$sid,'session'=>$sess,'status'=>'InProgress',
                'trip_direction'=>'Pickup','route_id'=>'','trip_date'=>date('Y-m-d'),
                'roster_student_ids'=>[$stuRostered],'checkpoints'=>[],'created_at'=>$now,
            ], false);
            $this->fixtures[] = ['tripLogs', $tid];
            // Staff: one Active, one Inactive (terminated).
            $this->fs->setEntity('staff', $staOk,   ['id'=>$staOk,'schoolId'=>$sid,'status'=>'Active','name'=>'OK Escort','created_at'=>$now], false);
            $this->fs->setEntity('staff', $staTerm, ['id'=>$staTerm,'schoolId'=>$sid,'status'=>'Inactive','name'=>'Term Escort','created_at'=>$now], false);
            $this->fixtures[] = ['staff', $staOk];
            $this->fixtures[] = ['staff', $staTerm];
            // Event in Planned state (updateEvent accepts Planned/Scheduled).
            $this->fs->setEntity('transportEvents', $eid, [
                'id'=>$eid,'schoolId'=>$sid,'session'=>$sess,'status'=>'Planned',
                'event_type'=>'Excursion','title'=>'L45 Event','event_date'=>date('Y-m-d'),
                'assigned_vehicle_ids'=>[],'escorts'=>[],'created_at'=>$now,
            ], false);
            $this->fixtures[] = ['transportEvents', $eid];

            echo "\n[G1] no_show for a NON-rostered student is rejected\n";
            try {
                $this->transport->markStudentNoShow($tid, $stuOther, 'probe');
                $fail("no_show accepted for a non-rostered student (wrong parent would be alerted)");
            } catch (\Throwable $e) {
                (stripos($e->getMessage(), 'roster') !== false)
                    ? $pass("rejected: " . $e->getMessage())
                    : $fail("rejected but not on roster grounds: " . $e->getMessage());
            }

            echo "\n[G2] no_show for a ROSTERED student still succeeds\n";
            try {
                $r = (array) $this->transport->markStudentNoShow($tid, $stuRostered, 'probe');
                $this->fixtures[] = ['transportAttendance', "{$sid}_{$tid}_{$stuRostered}_no_show"];
                (isset($r['id']) || isset($r['docId']) || $r !== [])
                    ? $pass("rostered no_show recorded")
                    : $fail("rostered no_show returned empty");
            } catch (\Throwable $e) {
                $fail("rostered no_show threw: " . $e->getMessage());
            }

            echo "\n[G3] updateEvent with a TERMINATED escort is rejected\n";
            try {
                $this->transport->updateEvent($eid, ['escorts' => [['staff_id' => $staTerm, 'role' => 'Chaperone']]]);
                $fail("terminated staff accepted as escort (safety personnel not HR-Active)");
            } catch (\Throwable $e) {
                (stripos($e->getMessage(), 'not Active in HR') !== false)
                    ? $pass("rejected: " . $e->getMessage())
                    : $fail("rejected but not on HR grounds: " . $e->getMessage());
            }

            echo "\n[G4] updateEvent with an ACTIVE escort succeeds\n";
            try {
                $this->transport->updateEvent($eid, ['escorts' => [['staff_id' => $staOk, 'role' => 'Chaperone']]]);
                $ev = (array) $this->transport->getEvent($eid);
                (count((array) ($ev['escorts'] ?? [])) === 1)
                    ? $pass("active escort accepted (1 escort recorded)")
                    : $fail("active escort not recorded: " . json_encode($ev['escorts'] ?? null));
            } catch (\Throwable $e) {
                $fail("active escort threw: " . $e->getMessage());
            }
        } catch (\Throwable $e) {
            $fail("unexpected exception: " . $e->getMessage());
        } finally {
            echo "\n[TEARDOWN]\n";
            foreach ($this->fixtures as [$col, $id]) { try { $this->fs->removeEntity($col, $id); } catch (\Throwable $e) {} }
            echo "  cleaned " . count($this->fixtures) . " docs\n";
        }

        echo "\n==================================================\n";
        echo " RESULT: passed={$passed} failed={$failed}\n";
        echo "==================================================\n";
        exit($failed === 0 ? 0 : 2);
    }
}
