<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Campus_access_concurrency_probe — BUG-053 concurrency proof (2026-08-10).
 *
 * Campus_access_probe_core's 90 assertions exercise dual-approval SEQUENTIALLY
 * (each recordApproval() call awaited before the next), which cannot exercise
 * the actual race BUG-053 was filed against: two overlapping read-modify-write
 * calls against the SAME campusAccessRequests document. PHP has no threads and
 * this stack has no pcntl on Windows, so "true" concurrency here means two
 * genuinely separate OS processes — the same unit of concurrency real
 * simultaneous HTTP requests get from two Apache/PHP-FPM workers. `run()` is
 * the coordinator: it seeds one fresh gate-pass request per iteration, then
 * launches two `worker` child processes via non-blocking proc_open() (both
 * proc_open() calls happen BEFORE either process's output is read, so both
 * are genuinely running in parallel — reading a pipe would otherwise block
 * and serialize what's supposed to be concurrent).
 *
 * SCENARIO: the same request's teacher slot is DENIED by one process while
 * the parent slot is APPROVED by the other, fired concurrently. This is the
 * exact failure mode BUG-053 documented: a parent-approval write racing a
 * teacher-denial write must never silently revert the denial back to
 * pending_approval. Two interleavings are possible and BOTH are correct
 * post-fix (the CAS mutator re-reads fresh state on every retry):
 *   (a) denial commits first  -> parent's write still lands (parent has no
 *       identity restriction) but the RECOMPUTED overall status is 'denied'
 *       (any denial denies the whole request, matching probe [10]).
 *   (b) parent commits first  -> teacher's denial then lands via a clean CAS
 *       retry against the fresh (parent-approved) state, again recomputing
 *       to 'denied'.
 * The one outcome that must NEVER occur (the original bug): final status
 * lands on 'pending_approval' with the denial silently lost/overwritten.
 *
 * INVOCATION
 *   SCHOOL_ID=SCH_D94FE8F7AD SESSION_YEAR=2026-27 php index.php campus_access_concurrency_probe run 10
 *   (iteration count optional, default 5)
 *
 * Exit codes: 0 = every iteration landed on a valid post-fix outcome;
 * 1 = env vars missing; 2 = at least one iteration reproduced the bug or hit
 * an unexpected error.
 */
class Campus_access_concurrency_probe extends CI_Controller
{
    private string $schoolA = '';
    private string $sessionYear = '';
    /** @var array<int,array{col:string,id:string}> */
    private array $created = [];

    public function __construct()
    {
        parent::__construct();
        if (!$this->input->is_cli_request()) {
            show_error('Campus_access_concurrency_probe is CLI-only.', 403);
        }
        $this->load->library('firestore_service', null, 'fs');
        $this->load->library('numbering_service', null, 'numbering');
        $this->load->library('gate_pass_service', null, 'gatepass');
        $this->load->helper('rbac');

        $this->schoolA = (string) (getenv('SCHOOL_ID') ?: '');
        $this->sessionYear = (string) (getenv('SESSION_YEAR') ?: '');
        if ($this->schoolA === '' || $this->sessionYear === '') {
            fwrite(STDERR, "ERROR: SCHOOL_ID and SESSION_YEAR env vars are required.\n");
            exit(1);
        }
    }

    private function _initSchool(): void
    {
        $this->fs->init($this->schoolA, $this->sessionYear);
        $this->numbering->init($this->fs, $this->schoolA, $this->sessionYear);
    }

    private function _asActor(string $id, string $name): void
    {
        $this->gatepass->init($this->fs, $this->numbering, $this->schoolA, $this->sessionYear, $id, $name);
    }

    public function run($iterations = 5)
    {
        $iterations = max(1, (int) $iterations);
        $this->_initSchool();
        $this->_asActor('concurrency_probe', 'Concurrency Probe');

        $this->fs->setEntity('staff', 'CP_TEACHER', ['id' => 'CP_TEACHER', 'name' => 'Concurrency Probe Teacher'], false);
        $this->fs->setEntity('students', 'CP_STUDENT', ['id' => 'CP_STUDENT', 'name' => 'Concurrency Probe Student', 'className' => 'Class 9th', 'section' => 'Section CP'], false);
        $this->created[] = ['col' => 'staff', 'id' => 'CP_TEACHER'];
        $this->created[] = ['col' => 'students', 'id' => 'CP_STUDENT'];

        $badOutcomes = 0;
        $php = PHP_BINARY !== '' ? PHP_BINARY : 'php';
        // Pass null (not a reconstructed array) so the child inherits this
        // process's FULL environment unchanged — SCHOOL_ID/SESSION_YEAR are
        // already in it (this process only got here because its own
        // constructor read them via getenv() successfully). An earlier
        // version rebuilt $env from $_ENV (often empty under XAMPP's default
        // variables_order) + just these two vars, which silently dropped
        // whatever this Firestore client needs to resolve the right
        // project/credentials — auth still succeeded (shared token cache)
        // but every read came back empty, not because of the bug under
        // test but because of a broken test harness.
        putenv('SCHOOL_ID=' . $this->schoolA);
        putenv('SESSION_YEAR=' . $this->sessionYear);
        $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];

        for ($i = 1; $i <= $iterations; $i++) {
            $req = $this->gatepass->createEarlyLeaveRequest([
                'personType' => 'student', 'personId' => 'CP_STUDENT', 'personName' => 'Concurrency Probe Student',
                'reason' => 'Concurrency probe iteration ' . $i, 'requestedTime' => '13:00', 'teacherStaffId' => 'CP_TEACHER',
            ]);
            $requestId = (string) $req['id'];
            $this->created[] = ['col' => 'campusAccessRequests', 'id' => $requestId];

            $cmdTeacherDeny = escapeshellarg($php) . ' index.php campus_access_concurrency_probe worker ' . escapeshellarg($requestId) . ' teacher denied';
            $cmdParentApprove = escapeshellarg($php) . ' index.php campus_access_concurrency_probe worker ' . escapeshellarg($requestId) . ' parent approved';

            // Both proc_open() calls MUST happen before either pipe is read —
            // reading pipe A to completion first would block until process A
            // exits, starting process B only afterward (i.e. sequential, not
            // concurrent — exactly what this probe exists to NOT do).
            $procA = proc_open($cmdTeacherDeny, $descriptors, $pipesA, getcwd(), null);
            $procB = proc_open($cmdParentApprove, $descriptors, $pipesB, getcwd(), null);
            if (!is_resource($procA) || !is_resource($procB)) {
                fwrite(STDERR, "ERROR: proc_open failed for iteration {$i}\n");
                exit(1);
            }

            $outA = stream_get_contents($pipesA[1]);
            $errA = stream_get_contents($pipesA[2]);
            fclose($pipesA[1]);
            fclose($pipesA[2]);
            $codeA = proc_close($procA);

            $outB = stream_get_contents($pipesB[1]);
            $errB = stream_get_contents($pipesB[2]);
            fclose($pipesB[1]);
            fclose($pipesB[2]);
            $codeB = proc_close($procB);

            $resA = json_decode(trim($outA), true);
            $resB = json_decode(trim($outB), true);

            // Read the final state via a THIRD fresh process, not this one.
            // Firestore_rest_client memoizes reads per-instance (invalidated
            // only by writes made through that SAME instance — see
            // getDocument()'s "PERF: request-scoped read memo" comment). This
            // coordinator already read this exact doc once, inside
            // createEarlyLeaveRequest()'s own return statement — so a direct
            // $this->fs->getEntity() here would return that memoized
            // pre-worker snapshot, not what the two external processes just
            // wrote. A fresh process has an empty cache, same as the workers.
            $cmdVerify = escapeshellarg($php) . ' index.php campus_access_concurrency_probe verify ' . escapeshellarg($requestId);
            $procV = proc_open($cmdVerify, $descriptors, $pipesV, getcwd(), null);
            $outV = stream_get_contents($pipesV[1]);
            fclose($pipesV[1]);
            fclose($pipesV[2]);
            proc_close($procV);
            $final = json_decode(trim($outV), true);

            $teacherDenied = ($final['approvals']['teacher']['status'] ?? '') === 'denied';
            $overallDenied = ($final['status'] ?? '') === 'denied';
            $ok = $teacherDenied && $overallDenied;

            printf(
                "[%s] iteration %d — requestId=%s teacherWorker(code=%d)=%s parentWorker(code=%d)=%s final.status=%s final.approvals.teacher.status=%s final.approvals.parent.status=%s\n",
                $ok ? 'PASS' : 'FAIL',
                $i,
                $requestId,
                $codeA,
                $resA === null ? "UNPARSEABLE: {$outA} {$errA}" : json_encode($resA),
                $codeB,
                $resB === null ? "UNPARSEABLE: {$outB} {$errB}" : json_encode($resB),
                $final['status'] ?? 'MISSING',
                $final['approvals']['teacher']['status'] ?? 'MISSING',
                $final['approvals']['parent']['status'] ?? 'MISSING'
            );
            if (!$ok) $badOutcomes++;
        }

        $this->_teardown();

        if ($badOutcomes > 0) {
            printf("\n%d of %d iteration(s) FAILED — the denial was lost or the final state was inconsistent. BUG-053 NOT proven fixed.\n", $badOutcomes, $iterations);
            exit(2);
        }
        printf("\nAll %d iteration(s) landed on a valid post-fix outcome — teacher denial always won, regardless of interleaving. BUG-053 concurrency proof PASSED.\n", $iterations);
    }

    /** Child process entry point — one recordApproval() call, then exit. */
    public function worker($requestId, $role, $decision)
    {
        $this->_initSchool();
        if ($role === 'teacher') {
            $this->_asActor('CP_TEACHER', 'Concurrency Probe Teacher');
            $extra = [];
        } else {
            $this->_asActor('CP_FRONTDESK', 'Concurrency Probe Front Desk');
            $extra = $decision === 'approved'
                ? ['guardianName' => 'Concurrency Probe Guardian', 'relationship' => 'Parent', 'consentMethod' => 'phone']
                : [];
        }
        try {
            $result = $this->gatepass->recordApproval((string) $requestId, (string) $role, (string) $decision, 'concurrency probe worker', $extra);
            echo json_encode(['ok' => true, 'status' => $result['status'] ?? null]);
        } catch (\Throwable $e) {
            echo json_encode(['ok' => false, 'error' => get_class($e), 'message' => $e->getMessage()]);
        }
    }

    /** Fresh-process read of the final doc state — see the comment at its call site for why this can't be a direct read in the coordinator's own process. */
    public function verify($requestId)
    {
        $this->_initSchool();
        $doc = $this->fs->getEntity('campusAccessRequests', (string) $requestId);
        echo json_encode($doc);
    }

    private function _teardown(): void
    {
        foreach ($this->created as $row) {
            $this->fs->removeEntity($row['col'], $row['id']);
        }
        echo sprintf("teardown: removed %d probe doc(s)\n", count($this->created));
    }
}
