<?php
defined('BASEPATH') or exit('No direct script access allowed');

/** Session stand-in so the REAL has_permission() can be driven from CLI.
 *  Same technique as Transport_probe_f14_authz / _edit_scope. */
class Req_Approval_Session_Stub
{
    private array $d = [];
    public function __construct(array $d) { $this->d = $d; }
    public function userdata($k = null) { return $k === null ? $this->d : ($this->d[$k] ?? null); }
    public function set_userdata($k, $v = null): void { $this->d[$k] = $v; }
}

/**
 * Transport_probe_req_approval — Phase 2 RUNTIME proof of:
 *
 *     request_transport (edit)  ->  pending worklist
 *          ->  approve_transport_request (manage)
 *          ->  assignStudent()  ->  canonical studentRoutes assignment
 *
 * WHY THIS EXISTS: F14 gate H12 asserts by SOURCE INSPECTION that approval
 * delegates to assignStudent(). It passed while the path threw
 * "student_id and route_id are required." for its entire life. Only an
 * executed request->approval->assignment can prove the workflow, and only a
 * read of the PERSISTED Firestore document can prove the assignment exists.
 *
 * Proof categories are kept separate and never conflated:
 *   AUTHORIZATION · WORKFLOW · PERSISTENCE · INTEGRITY
 *
 *   SCHOOL_ID=SCH_202512 SESSION_YEAR=2026-27 php index.php transport_probe_req_approval run
 */
class Transport_probe_req_approval extends CI_Controller
{
    private const ALLOWED_TENANT = 'SCH_202512';
    private const MARK = 'PROBE_REQAPPR';

    public $fs = null; public $numbering = null; public $transport = null;
    private int $pass = 0; private int $fail = 0; private array $failures = [];
    private array $fixtures = [];
    private string $tag = ''; private string $sid = ''; private string $sess = '';

    public function __construct() { parent::__construct(); if (!is_cli()) { show_404(); } }

    private function ok(string $i, string $w): void { $this->pass++; printf("  PASS  %-7s %s\n", $i, $w); }
    private function bad(string $i, string $w, string $d): void
    { $this->fail++; $this->failures[] = "{$i} — {$w}: {$d}"; printf("  FAIL  %-7s %s\n          → %s\n", $i, $w, $d); }
    private function eq(string $i, string $w, $e, $g): void
    { $e === $g ? $this->ok($i, $w) : $this->bad($i, $w, 'expected ' . var_export($e, true) . ', got ' . var_export($g, true)); }
    private function truthy(string $i, string $w, $v, string $d = ''): void
    { $v ? $this->ok($i, $w) : $this->bad($i, $w, $d !== '' ? $d : 'falsy'); }

    private function principal(string $level): void
    {
        $CI =& get_instance();
        $CI->session = new Req_Approval_Session_Stub([
            'admin_role'       => $level === 'manage' ? 'Transport Manager' : 'Driver',
            'rbac_permissions' => ['Transport'],
            'rbac_levels'      => ['Transport' => $level],
        ]);
    }
    private function actAs(string $a): void
    { $this->transport->init($this->fs, $this->numbering, $this->sid, $this->sess, $a); }

    private function flat($row): array
    {
        if (!is_array($row)) { return []; }
        if (isset($row['data']) && is_array($row['data'])) {
            $d = $row['data'];
            if (!empty($row['id']) && !isset($d['id'])) { $d['id'] = $row['id']; }
            return $d;
        }
        return $row;
    }

    /** Canonical assignments for a student — PERSISTENCE source of truth. */
    private function assignments(string $studentId): array
    {
        try {
            $rows = (array) $this->fs->schoolWhere('studentRoutes',
                [['studentId', '==', $studentId]], null, 'ASC', 20);
            return array_map(fn($r) => $this->flat($r), $rows);
        } catch (\Throwable $e) { echo '  (assignment read failed: ' . $e->getMessage() . ")\n"; return []; }
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
        echo " Phase 2 — request -> approval -> assignment (RUNTIME)\n";
        echo " tenant={$this->sid}  session={$this->sess}\n";
        echo "==================================================\n";

        $this->load->library('firestore_service', null, 'fs');   $this->fs->init($this->sid, $this->sess);
        $this->load->library('numbering_service', null, 'numbering'); $this->numbering->init($this->fs, $this->sid, $this->sess);
        $this->load->library('transport_service', null, 'transport');
        $this->load->helper('rbac');

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
        $stu   = self::MARK . '_STU_' . $this->tag;
        $route = self::MARK . '_RT_'  . $this->tag;
        $stop  = self::MARK . '_STP_' . $this->tag;
        $now   = date('c');

        echo "\n  fixtures: student={$stu}\n            route={$route}\n            stop={$stop}\n";

        $this->fs->setEntity('routes', $route, ['id' => $route, 'schoolId' => $this->sid,
            'name' => 'ReqAppr Route', 'status' => 'Active', 'direction' => 'Both',
            'created_at' => $now, 'updated_at' => $now], false);
        $this->fixtures[] = ['routes', $route];
        $this->fs->setEntity('transportStops', $stop, ['stopId' => $stop, 'schoolId' => $this->sid,
            'route_id' => $route, 'name' => 'ReqAppr Stop', 'order' => 1, 'status' => 'Active',
            'created_at' => $now, 'updated_at' => $now], false);
        $this->fixtures[] = ['transportStops', $stop];
        // A student doc is required by assignStudent()'s validation context.
        $this->fs->setEntity('students', $stu, ['id' => $stu, 'studentId' => $stu,
            'schoolId' => $this->sid, 'session' => $this->sess, 'name' => 'ReqAppr Student',
            'className' => '5', 'section' => 'A', 'status' => 'Active',
            'created_at' => $now, 'updated_at' => $now], false);
        $this->fixtures[] = ['students', $stu];

        // ── TEST 1 — REQUEST CREATION (WORKFLOW + PERSISTENCE) ───────────
        echo "\n[T1] request creation (real requestTransport)\n";
        $this->eq('T1-D', 'PRE: no canonical assignment before approval', 0, count($this->assignments($stu)));

        $this->principal('edit'); $this->actAs(self::MARK . '_DRV');
        $res = null;
        try { $res = $this->transport->requestTransport(
            ['studentId' => $stu, 'route_id' => $route, 'stop_id' => $stop,
             'type' => 'both', 'effective_from' => date('Y-m-d'),
             'student_name' => 'ReqAppr Student', 'student_class' => '5'],
            'mid_session'); }
        catch (\Throwable $e) { $this->bad('T1-A', 'request created', $e->getMessage()); }

        $reqId = is_array($res) ? (string) ($res['requestId'] ?? '') : '';
        if ($reqId !== '') { $this->fixtures[] = ['transportRequests', $reqId]; }
        $this->truthy('T1-A', 'request actually created', $reqId !== '');
        $this->eq('T1-B', 'outcome is pending', 'pending', is_array($res) ? (string) ($res['outcome'] ?? '') : '');

        $reqDoc = $reqId !== '' ? $this->flat($this->fs->getEntity('transportRequests', $reqId)) : [];
        $this->eq('T1-C', 'persisted request references the correct student', $stu, (string) ($reqDoc['studentId'] ?? ''));
        $this->eq('T1-B2', 'persisted status is pending', 'pending', (string) ($reqDoc['status'] ?? ''));
        echo "        actual persisted request keys: " . implode(', ', array_keys($reqDoc)) . "\n";
        echo "        route_id='" . (string) ($reqDoc['route_id'] ?? '(absent)')
           . "'  stop_id='" . (string) ($reqDoc['stop_id'] ?? '(absent)') . "'\n";
        $this->eq('T1-D2', 'STILL no canonical assignment after the request', 0, count($this->assignments($stu)));

        // ── TEST 2 — AUTHORIZATION (kept separate from workflow) ─────────
        echo "\n[T2] authorization separation\n";
        $this->principal('edit');
        $this->eq('T2-A', 'AUTHZ: Driver/edit is DENIED Transport:manage', false, has_permission('Transport', 'manage'));
        $this->principal('manage');
        $this->eq('T2-B', 'AUTHZ: manage principal is GRANTED Transport:manage', true, has_permission('Transport', 'manage'));
        echo "        NOTE: approve_transport_request gates on require_permission(...,'manage');\n";
        echo "              the HTTP 403 for Driver/edit is SOURCE-VERIFIED, not exercised here.\n";

        // ── TEST 3 — REAL APPROVAL (WORKFLOW) ────────────────────────────
        echo "\n[T3] real approval path\n";
        if ($reqId === '') { $this->bad('T3-A', 'approval executed', 'no request to approve'); return; }
        $this->principal('manage'); $this->actAs(self::MARK . '_MGR');
        $appr = null; $err = '';
        try {
            $appr = $this->transport->approveTransportRequest($reqId, [
                'route_id' => $route, 'stop_id' => $stop, 'type' => 'both',
                'service_days' => [], 'effective_from' => date('Y-m-d'),
                'effective_to' => '', 'monthly_fee' => null, 'notes' => 'PROBE_REQAPPR approval',
            ]);
        } catch (\Throwable $e) { $err = $e->getMessage(); }

        $this->truthy('T3-A', 'approval completed without exception', $err === '', "threw: {$err}");
        $this->truthy('T3-B', 'the OLD exception is gone ("student_id and route_id are required")',
            stripos($err, 'student_id and route_id are required') === false, "still throwing: {$err}");

        // ── TEST 4 — CANONICAL ASSIGNMENT (PERSISTENCE) ──────────────────
        echo "\n[T4] canonical assignment — persisted document\n";
        $rows = $this->assignments($stu);
        $this->eq('T4-A', 'EXACTLY ONE canonical studentRoutes assignment exists', 1, count($rows));
        if (count($rows) === 1) {
            $a = $rows[0];
            if (!empty($a['id'])) { $this->fixtures[] = ['studentRoutes', (string) $a['id']]; }
            $this->eq('T4-B', 'assignment student is the canonical Student ID', $stu, (string) ($a['studentId'] ?? ''));
            $this->eq('T4-C', 'assignment route correct',   $route, (string) ($a['route_id'] ?? ''));
            $this->eq('T4-D', 'assignment stop correct',    $stop,  (string) ($a['stop_id']  ?? ''));
            $this->eq('T4-E', 'assignment school correct',  $this->sid,  (string) ($a['schoolId'] ?? ''));
            $this->eq('T4-F', 'assignment session correct', $this->sess, (string) ($a['session'] ?? ''));
            echo "        actual assignment keys: " . implode(', ', array_keys($a)) . "\n";
        } else {
            foreach ($rows as $a) { $this->fixtures[] = ['studentRoutes', (string) ($a['id'] ?? '')]; }
        }

        // ── TEST 5 — REQUEST STATE (report ACTUAL schema) ────────────────
        echo "\n[T5] request lifecycle state after approval\n";
        $after = $this->flat($this->fs->getEntity('transportRequests', $reqId));
        echo "        actual post-approval keys: " . implode(', ', array_keys($after)) . "\n";
        $this->truthy('T5-A', 'status is no longer pending',
            (string) ($after['status'] ?? '') !== 'pending', "status='" . (string) ($after['status'] ?? '') . "'");
        echo "        status='" . (string) ($after['status'] ?? '(absent)')
           . "'  assignment_id='" . (string) ($after['assignment_id'] ?? '(absent)')
           . "'  reviewed_by='" . (string) ($after['reviewed_by'] ?? '(absent)')
           . "'  reviewed_at='" . (string) ($after['reviewed_at'] ?? '(absent)') . "'\n";
        // ── T5-B — CANONICAL LINKAGE PROOF (was BLOCKED) ─────────────────
        //
        // The old assertion compared request.assignment_id (an ENTITY id)
        // against studentRoutes.id (the SCHOOL-PREFIXED document id) and failed
        // on a representation difference, proving nothing either way.
        //
        // getEntity($col, $entityId) -> get($col, docId($entityId)) and
        // docId() returns "{schoolId}_{entityId}" — verified in
        // Firestore_service. So the stored entity id is EXACTLY the right input
        // to the canonical lookup. This now performs that real lookup instead
        // of comparing strings.
        $aid = (string) ($after['assignment_id'] ?? '');
        $this->truthy('T5-B0', 'request carries a non-empty assignment_id', $aid !== '');
        if ($aid !== '') {
            $resolved = $this->flat($this->fs->getEntity('studentRoutes', $aid));
            $this->truthy('T5-B', 'CANONICAL LOOKUP: getEntity(studentRoutes, assignment_id) resolves a real document',
                $resolved !== [], "getEntity returned nothing for entity id '{$aid}'");
            if ($resolved !== []) {
                // The resolved document must BE the assignment approval created.
                $this->eq('T5-B1', 'resolved assignment studentId matches', $stu,  (string) ($resolved['studentId'] ?? ''));
                $this->eq('T5-B2', 'resolved assignment route_id matches',  $route, (string) ($resolved['route_id'] ?? ''));
                $this->eq('T5-B3', 'resolved assignment stop_id matches',   $stop,  (string) ($resolved['stop_id']  ?? ''));
                $this->eq('T5-B4', 'resolved assignment schoolId matches',  $this->sid,  (string) ($resolved['schoolId'] ?? ''));
                $this->eq('T5-B5', 'resolved assignment session matches',   $this->sess, (string) ($resolved['session']  ?? ''));
                // Identity is compared on a field BOTH read shapes carry.
                // schoolWhere() returns enveloped rows (flat() injects the
                // prefixed doc id); getEntity() returns flat fields with no
                // injected id. Comparing 'id' across the two would repeat the
                // representation trap this gate exists to close. created_at
                // uniquely stamps the record and is present in both.
                if (count($rows) === 1) {
                    $this->eq('T5-B6', 'resolved document IS the assignment created by approval',
                        (string) ($rows[0]['created_at'] ?? 'A'), (string) ($resolved['created_at'] ?? 'B'));
                }
            }
        }
        $this->truthy('T5-C', 'history/audit field still present',
            isset($after['history']) || isset($after['review_notes']),
            'neither history nor review_notes found');

        // ── TEST 6 — DUPLICATE PROTECTION (INTEGRITY) ────────────────────
        echo "\n[T6] duplicate protection\n";
        $err2 = '';
        try { $this->transport->approveTransportRequest($reqId, [
            'route_id' => $route, 'stop_id' => $stop, 'type' => 'both',
            'service_days' => [], 'effective_from' => date('Y-m-d'),
            'effective_to' => '', 'monthly_fee' => null, 'notes' => 'PROBE_REQAPPR re-approve']); }
        catch (\Throwable $e) { $err2 = $e->getMessage(); }
        $this->truthy('T6-A', 're-approving an already-approved request is REFUSED', $err2 !== '',
            'second approval was accepted');
        $this->eq('T6-B', 'still EXACTLY ONE assignment after the retry', 1, count($this->assignments($stu)));

        // ── TEST 7 — SRNO / Student ID ───────────────────────────────────
        echo "\n[T7] SRNO / canonical Student ID\n";
        $rows2 = $this->assignments($stu);
        $sidVal = $rows2 ? (string) ($rows2[0]['studentId'] ?? '') : '';
        $this->eq('T7-A', 'assignment stores the canonical Student ID', $stu, $sidVal);
        $this->truthy('T7-B', 'no srNo persisted on the canonical assignment',
            $rows2 ? !array_key_exists('srNo', $rows2[0]) : false,
            'srNo unexpectedly present on studentRoutes');
        echo "  INFO  T7-C    fixture was identified by Student ID, NOT by SRNO.\n";
        echo "                SRNO->StudentID equivalence is NOT runtime-proven here.\n";
        echo "                Sr_no_service / srNoIndex were neither read nor written.\n";
    }

    private function teardown(): void
    {
        echo "\n[TEARDOWN]\n";
        $removed = 0; $failed = 0; $seen = [];
        foreach (array_reverse($this->fixtures) as [$col, $id]) {
            if ($id === '' || isset($seen[$col . '|' . $id])) { continue; }
            $seen[$col . '|' . $id] = true;
            try { $this->fs->removeEntity($col, $id); $removed++; }
            catch (\Throwable $e) { $failed++; echo "  FAILED remove {$col}:{$id} — " . $e->getMessage() . "\n"; }
        }
        echo "  probe records : " . count($seen) . "\n";
        echo "  removed       : {$removed}" . ($failed ? " (failed {$failed})" : '') . "\n";
        echo "  tenant config : NOT modified · SRNO/srNoIndex: NOT touched\n";
    }
}
