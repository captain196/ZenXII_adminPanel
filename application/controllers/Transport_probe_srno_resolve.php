<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Transport_probe_srno_resolve — RUNTIME proof that a Transport user may
 * identify the same student by EITHER identifier:
 *
 *     Student ID ─┐
 *                 ├─► Transport_service::searchStudents() ─► canonical Student ID
 *     SRNO ───────┘
 *
 * WHY: every prior claim of "SRNO lookup works" was SOURCE-VERIFIED only —
 * searchStudents() matches $s['id'] and $s['srNo']. That is not evidence the
 * two inputs converge at runtime. SR-C below is the assertion that matters.
 *
 * SRNO SAFETY: this probe NEVER calls Sr_no_service, never allocates an SRNO,
 * and never touches srNoIndex or its counters. The fixture's srNo is written
 * directly onto an isolated PROBE_* student document — the same way the other
 * Transport probes seed student fields — so the canonical SRNO system is not
 * involved at all. SR-G/SR-H verify it stayed untouched.
 *
 *   SCHOOL_ID=SCH_202512 SESSION_YEAR=2026-27 php index.php transport_probe_srno_resolve run
 */
class Transport_probe_srno_resolve extends CI_Controller
{
    private const ALLOWED_TENANT = 'SCH_202512';
    private const MARK = 'PROBE_SRNORES';

    public $fs = null; public $numbering = null; public $transport = null;
    private int $pass = 0; private int $fail = 0; private array $failures = [];
    private array $fixtures = [];
    private string $tag = ''; private string $sid = ''; private string $sess = '';

    public function __construct() { parent::__construct(); if (!is_cli()) { show_404(); } }

    private function ok(string $i, string $w): void { $this->pass++; printf("  PASS  %-6s %s\n", $i, $w); }
    private function bad(string $i, string $w, string $d): void
    { $this->fail++; $this->failures[] = "{$i} — {$w}: {$d}"; printf("  FAIL  %-6s %s\n          → %s\n", $i, $w, $d); }
    private function eq(string $i, string $w, $e, $g): void
    { $e === $g ? $this->ok($i, $w) : $this->bad($i, $w, 'expected ' . var_export($e, true) . ', got ' . var_export($g, true)); }
    private function truthy(string $i, string $w, $v, string $d = ''): void
    { $v ? $this->ok($i, $w) : $this->bad($i, $w, $d !== '' ? $d : 'falsy'); }

    private function flat($r): array
    {
        if (!is_array($r)) { return []; }
        if (isset($r['data']) && is_array($r['data'])) {
            $d = $r['data']; if (!empty($r['id']) && !isset($d['id'])) { $d['id'] = $r['id']; }
            return $d;
        }
        return $r;
    }

    /** Drop the 300s student index so a freshly-seeded fixture is visible. */
    private function bustIndex(): void
    {
        try { $this->load->driver('cache', ['adapter' => 'file']); } catch (\Throwable $_) {}
        try { $this->cache->delete('trn_students_' . md5($this->sid)); } catch (\Throwable $_) {}
    }

    public function run(): void
    {
        $this->sid  = (string) (getenv('SCHOOL_ID') ?: '');
        $this->sess = (string) (getenv('SESSION_YEAR') ?: '');
        if ($this->sid === '' || $this->sess === '') { fwrite(STDERR, "ERROR: SCHOOL_ID + SESSION_YEAR required.\n"); exit(1); }
        if ($this->sid !== self::ALLOWED_TENANT) {
            fwrite(STDERR, "REFUSED: writes fixtures; runs only on " . self::ALLOWED_TENANT . ".\n"); exit(1);
        }
        $this->tag = (string) (int) (microtime(true) * 1000);

        echo "==================================================\n";
        echo " SRNO <-> Student ID resolution (RUNTIME)  tenant={$this->sid}\n";
        echo "==================================================\n";

        $this->load->library('firestore_service', null, 'fs');   $this->fs->init($this->sid, $this->sess);
        $this->load->library('numbering_service', null, 'numbering'); $this->numbering->init($this->fs, $this->sid, $this->sess);
        $this->load->library('transport_service', null, 'transport');
        $this->transport->init($this->fs, $this->numbering, $this->sid, $this->sess, self::MARK . '_ACTOR');

        try { $this->gates(); }
        catch (\Throwable $e) { $this->bad('EXC', 'unhandled', $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine()); }
        finally { $this->teardown(); }

        echo "\n==================================================\n";
        echo " RESULT: pass={$this->pass} fail={$this->fail}\n";
        if ($this->failures !== []) { echo "\n DEFECTS:\n"; foreach ($this->failures as $f) { echo "   • {$f}\n"; } }
        echo "==================================================\n";
        if ($this->fail > 0) { exit(1); }
    }

    private function gates(): void
    {
        $stu  = self::MARK . '_STU_' . $this->tag;
        // A numeric SRNO deliberately UNRELATED to the Student ID, so a
        // derivation bug cannot make the test pass by accident.
        $srNo = '9' . substr($this->tag, -6);
        $now  = date('c');

        echo "\n  fixture student = {$stu}\n  fixture srNo    = {$srNo}  (deliberately not derived from the ID)\n";

        $this->fs->setEntity('students', $stu, [
            'id' => $stu, 'studentId' => $stu, 'schoolId' => $this->sid, 'session' => $this->sess,
            'name' => 'SrNoResolve Probe', 'className' => '5', 'section' => 'A',
            'status' => 'Active', 'srNo' => $srNo,
            'created_at' => $now, 'updated_at' => $now,
        ], false);
        $this->fixtures[] = ['students', $stu];

        // Capture the ORIGINAL srNo for the integrity gates.
        $before = (string) ($this->flat($this->fs->getEntity('students', $stu))['srNo'] ?? '');
        $this->eq('SR-0', 'fixture seeded with the expected srNo', $srNo, $before);

        $this->bustIndex();

        // ── SR-A — resolution by canonical Student ID ────────────────────
        $byId = (array) $this->transport->searchStudents($stu, 20);
        $hitId = null;
        foreach ($byId as $r) { if ((string) ($r['id'] ?? '') === $stu) { $hitId = $r; break; } }
        $this->truthy('SR-A', 'RESOLUTION: Student ID input resolves the fixture', is_array($hitId),
            'searchStudents(StudentID) returned ' . count($byId) . ' rows, fixture not among them');

        // ── SR-B — resolution by SRNO ────────────────────────────────────
        $bySr = (array) $this->transport->searchStudents($srNo, 20);
        $hitSr = null;
        foreach ($bySr as $r) { if ((string) ($r['id'] ?? '') === $stu) { $hitSr = $r; break; } }
        $this->truthy('SR-B', 'RESOLUTION: SRNO input resolves the SAME fixture', is_array($hitSr),
            'searchStudents(SRNO) returned ' . count($bySr) . ' rows, fixture not among them');

        // ── SR-C — THE equivalence assertion ─────────────────────────────
        $idFromId = is_array($hitId) ? (string) ($hitId['id'] ?? '') : '(none)';
        $idFromSr = is_array($hitSr) ? (string) ($hitSr['id'] ?? '') : '(none)';
        $this->truthy('SR-C', 'EQUIVALENCE: both inputs resolve to the EXACT SAME canonical Student ID',
            $idFromId !== '(none)' && $idFromId === $idFromSr && $idFromId === $stu,
            "byId='{$idFromId}' bySr='{$idFromSr}' expected='{$stu}'");

        // ── SR-D / SR-E — the identifiers are not confused ───────────────
        $this->truthy('SR-D', 'SRNO is NOT returned as the canonical identifier',
            $idFromSr !== $srNo, "resolver returned the SRNO '{$srNo}' as the id");
        $this->truthy('SR-E', 'identifier fields stay distinct (id != srNo on the row)',
            is_array($hitSr) && (string) ($hitSr['srNo'] ?? '') === $srNo && $idFromSr !== $srNo);

        // ── SR-F — Transport convergence: the value the UI posts back ────
        $userIdFromSr = is_array($hitSr) ? (string) ($hitSr['user_id'] ?? '') : '';
        $this->eq('SR-F', 'CONVERGENCE: SRNO row carries the canonical Student ID as its post value',
            $stu, $userIdFromSr);

        // ── SR-G / SR-H — SRNO integrity ─────────────────────────────────
        $after = (string) ($this->flat($this->fs->getEntity('students', $stu))['srNo'] ?? '');
        $this->eq('SR-G', 'INTEGRITY: srNo unchanged after both resolutions', $srNo, $after);
        $this->truthy('SR-H', 'INTEGRITY: no srNoIndex claim created for this probe srNo',
            !is_array($this->fs->getEntity('srNoIndex', $srNo)),
            'an srNoIndex claim exists for ' . $srNo . ' — the probe must not have created one');

        // ── SR-I — request persists the canonical Student ID ─────────────
        $res = null;
        try { $res = $this->transport->requestTransport(
            ['studentId' => $stu, 'student_name' => 'SrNoResolve Probe', 'student_class' => '5',
             'requested_area' => 'PROBE area'], 'mid_session'); }
        catch (\Throwable $e) { $this->bad('SR-I', 'request created', $e->getMessage()); }
        $reqId = is_array($res) ? (string) ($res['requestId'] ?? '') : '';
        if ($reqId !== '') { $this->fixtures[] = ['transportRequests', $reqId]; }
        $reqDoc = $reqId !== '' ? $this->flat($this->fs->getEntity('transportRequests', $reqId)) : [];
        $this->eq('SR-I', 'PERSISTENCE: transportRequests.studentId is the canonical Student ID',
            $stu, (string) ($reqDoc['studentId'] ?? ''));
        $this->truthy('SR-I2', 'PERSISTENCE: transportRequests carries NO srNo',
            $reqDoc !== [] && !array_key_exists('srNo', $reqDoc));

        // ── SR-J — no assignment, and none may carry srNo ────────────────
        $rows = [];
        try {
            foreach ((array) $this->fs->schoolWhere('studentRoutes',
                [['studentId', '==', $stu]], null, 'ASC', 10) as $r) { $rows[] = $this->flat($r); }
        } catch (\Throwable $_) {}
        $this->eq('SR-J', 'PERSISTENCE: request alone creates NO canonical assignment', 0, count($rows));
    }

    private function teardown(): void
    {
        echo "\n[TEARDOWN]\n";
        $removed = 0; $failed = 0;
        foreach (array_reverse($this->fixtures) as [$col, $id]) {
            if ($id === '') { continue; }
            try { $this->fs->removeEntity($col, $id); $removed++; }
            catch (\Throwable $e) { $failed++; echo "  FAILED remove {$col}:{$id} — " . $e->getMessage() . "\n"; }
        }
        $this->bustIndex();
        echo "  probe records : " . count($this->fixtures) . "\n";
        echo "  removed       : {$removed}" . ($failed ? " (failed {$failed})" : '') . "\n";
        echo "  Sr_no_service / srNoIndex / counters : NOT called, NOT written\n";
    }
}
