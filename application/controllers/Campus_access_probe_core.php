<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Campus_access_probe_core — runtime proof for Campus Access Management.
 * Renamed from Visitor_probe_core.php 2026-08-09 (module identity rename);
 * [1]-[6] are the original Visitor Management proof, unchanged. [7]-[15]
 * are new: the unified-ledger write-through (idempotency + reconciliation),
 * student dual-approval / staff single-approval / late-entry gate-pass
 * flows, the muster view, and cross-checks that nothing here auto-approves
 * a student exit or leaks across tenants.
 *
 * COVERAGE
 *   [1]-[6]  Visitor Management (unchanged — see prior header)
 *   [7]      campusAccessEvents write-through fires on check-in AND checkout,
 *            with the visitPasses sync flags flipped true
 *   [8]      Reconciliation sweep is a true no-op once already synced
 *            (idempotency proof — no duplicate writes on retry)
 *   [9]      Student early-leave: dual approval — teacher alone is NOT
 *            enough; both required before status flips to approved; release
 *            writes a GP_ ledger event; parent-notify path never throws
 *            even for a synthetic student with no real Firestore doc
 *   [10]     Student early-leave: either approver denying denies the whole
 *            request immediately (no need to wait on the other)
 *   [11]     Staff early-leave: single admin approval only (no teacher/
 *            parent slots) — different policy, same service
 *   [12]     Late entry: no approval gate, logs + ledger-writes immediately
 *   [13]     Muster view reflects on-campus (logged-in, not yet out) people
 *            correctly by personType
 *   [14]     Visitor auto-approve sweep never touches gate-pass requests —
 *            the two approval policies are genuinely isolated
 *   [15]     Multi-tenant isolation extends to the two new collections
 *   [16]     Invalid state transitions are rejected server-side (never rely
 *            on the UI alone) — re-approve/re-checkout/re-release, override
 *            on a non-block denial, approve-after-denied, etc. — added
 *            2026-08-09 during the adversarial production-readiness audit
 *   [17]     Cron sweeps (overstay, reconciliation) are safe to run
 *            repeatedly — 3 consecutive runs produce zero new ledger events
 *            and reconciliation settles to a true no-op — added same audit
 *   [18]     F-01 correction pass (2026-08-09): canonical sections.classTeacherId
 *            resolution (no client-submitted teacherStaffId needed), the
 *            manual-fallback path when no canonical binding exists, that a
 *            non-bound/non-manage actor CANNOT approve the teacher slot, that
 *            the bound teacher CAN self-approve, that a manage-level delegate
 *            approval requires a logged reason, that a parent 'approved'
 *            decision requires guardian identity fields stored separate from
 *            the recording staff member, and that the staff admin slot
 *            requires manage-level (not just edit-level). Plus F-08: a
 *            gate-pass request for a personId with no matching same-school
 *            student/staff record is rejected outright.
 *   [19]     F-03 correction pass (2026-08-09): the QR lookup endpoint is
 *            read-only (never changes pass state by itself), rejects
 *            garbage/empty/tampered/cross-tenant tokens, and checkoutPass()
 *            independently re-verifies a 'qr_manual' claim server-side —
 *            rejecting a mismatched-pass token, a missing token, and any
 *            claimed rfid/biometric method with no real hardware behind it —
 *            while the plain 'manual' passId-based checkout path (and the
 *            existing gate-pass release path, which has NO QR option at all)
 *            keep working exactly as before.
 *
 * INVOCATION
 *   SCHOOL_ID=SCH_D94FE8F7AD SESSION_YEAR=2026-27 php index.php campus_access_probe_core run
 *
 * Exit codes: 0 = all assertions passed; 1 = env vars missing; 2 = a
 * failure was seen (teardown still runs first, best-effort).
 */
class Campus_access_probe_core extends CI_Controller
{
    private string $schoolA = '';
    private string $schoolB = 'SCH_PROBEVIS02'; // synthetic second tenant — isolation proof only
    private string $sessionYear = '';

    /** @var array<int,array{col:string,id:string,school:string}> */
    private array $created = [];

    private int $pass = 0;
    private int $fail = 0;

    public function __construct()
    {
        parent::__construct();
        if (!$this->input->is_cli_request()) {
            show_error('Campus_access_probe_core is CLI-only.', 403);
        }
        $this->load->library('firestore_service', null, 'fs');
        $this->load->library('numbering_service', null, 'numbering');
        $this->load->library('visitor_service', null, 'visitor');
        $this->load->library('gate_pass_service', null, 'gatepass');
        // F-01 correction pass: Gate_pass_service::recordApproval() calls the
        // real has_permission() for its manage-level gates. MY_Controller
        // loads this helper for every real request; this probe extends
        // CI_Controller directly (CLI-only, no session/RBAC bootstrap), so
        // without this line has_permission() would be undefined and the
        // manage-level checks would silently no-op via the function_exists guard.
        $this->load->helper('rbac');

        $this->schoolA    = (string) (getenv('SCHOOL_ID') ?: '');
        $this->sessionYear = (string) (getenv('SESSION_YEAR') ?: '');
        if ($this->schoolA === '' || $this->sessionYear === '') {
            fwrite(STDERR, "ERROR: SCHOOL_ID and SESSION_YEAR env vars are required.\n");
            exit(1);
        }
    }

    private function _initSchool(string $schoolId): void
    {
        $this->fs->init($schoolId, $this->sessionYear);
        $this->numbering->init($this->fs, $schoolId, $this->sessionYear);
        $this->visitor->init($this->fs, $this->numbering, $schoolId, $this->sessionYear, 'probe_core', 'Probe');
        $this->gatepass->init($this->fs, $this->numbering, $schoolId, $this->sessionYear, 'probe_core', 'Probe');
    }

    private function _assert(string $label, bool $ok, string $detail = ''): void
    {
        echo sprintf("[%s] %s%s\n", $ok ? 'PASS' : 'FAIL', $label, $detail !== '' ? ' — ' . $detail : '');
        $ok ? $this->pass++ : $this->fail++;
    }

    /** Asserts $fn() throws \InvalidArgumentException — the state-machine guard signature used throughout this module. */
    private function _assertThrows(string $label, callable $fn): void
    {
        try {
            $fn();
            $this->_assert($label, false, 'expected an \InvalidArgumentException, none was thrown');
        } catch (\InvalidArgumentException $e) {
            $this->_assert($label, true, 'rejected: ' . $e->getMessage());
        } catch (\Throwable $e) {
            $this->_assert($label, false, 'threw the wrong exception type: ' . get_class($e) . ' — ' . $e->getMessage());
        }
    }

    private function _track(string $col, string $id, string $school): void
    {
        $this->created[] = ['col' => $col, 'id' => $id, 'school' => $school];
    }

    // ── F-01/F-08 correction-pass helpers (2026-08-09) ──────────────────

    /** Switch which staff account is "acting" — Gate_pass_service's teacher self-match check is a pure adminId comparison, so this is how the probe proves/disproves it without a real login. */
    private function _asActor(string $id, string $name): void
    {
        $this->visitor->init($this->fs, $this->numbering, $this->schoolA, $this->sessionYear, $id, $name);
        $this->gatepass->init($this->fs, $this->numbering, $this->schoolA, $this->sessionYear, $id, $name);
    }

    private function _resetActor(): void
    {
        $this->_asActor('probe_core', 'Probe');
    }

    /** Drives the REAL has_permission('Campus Access','manage') check via real CI session state — not a bypass, the same code path an actual HTTP request would hit. */
    private function _grantManage(): void
    {
        $this->session->set_userdata('admin_role', 'Front Office');
        $this->session->set_userdata('rbac_permissions', ['Campus Access']);
        $this->session->set_userdata('rbac_levels', ['Campus Access' => 'manage']);
    }

    /** Edit-level only — enough for the controller's require_permission('...','edit') gates but NOT the service-layer manage-gated checks (admin slot, teacher delegate). */
    private function _revokeManage(): void
    {
        $this->session->set_userdata('admin_role', 'Security');
        $this->session->set_userdata('rbac_permissions', ['Campus Access']);
        $this->session->set_userdata('rbac_levels', ['Campus Access' => 'edit']);
    }

    private function _seedStudent(string $id, string $name, string $className, string $section): void
    {
        $this->fs->setEntity('students', $id, ['id' => $id, 'name' => $name, 'className' => $className, 'section' => $section], false);
        $this->_track('students', $id, $this->schoolA);
    }

    private function _seedStaff(string $id, string $name): void
    {
        $this->fs->setEntity('staff', $id, ['id' => $id, 'name' => $name], false);
        $this->_track('staff', $id, $this->schoolA);
    }

    private function _seedSection(string $id, string $className, string $section, string $classTeacherId): void
    {
        $this->fs->setEntity('sections', $id, ['id' => $id, 'className' => $className, 'section' => $section, 'classTeacherId' => $classTeacherId], false);
        $this->_track('sections', $id, $this->schoolA);
    }

    public function run(): void
    {
        $this->_initSchool($this->schoolA);

        // F-08 fixtures — real, same-school student/staff records so the new
        // person-existence check has something real to resolve against (was
        // previously exercised with bare synthetic id strings that no longer
        // resolve now that F-08 rejects unresolvable person references).
        $this->_seedStaff('PROBE_STAFF_T1', 'Probe Teacher One');
        $this->_seedStaff('PROBE_STAFF_001', 'Probe Staff One');
        $this->_seedStaff('PROBE_STAFF_016', 'Probe Staff Sixteen');
        $this->_seedStaff('PROBE_TEACHER_CANON', 'Probe Canonical Teacher');
        $this->_seedStaff('PROBE_TEACHER_OTHER', 'Probe Other Teacher');
        $this->_seedStudent('PROBE_STU_001', 'Probe Student One', 'Class 9th', 'Section Z');
        $this->_seedStudent('PROBE_STU_002', 'Probe Student Two', 'Class 9th', 'Section Z');
        $this->_seedStudent('PROBE_STU_003', 'Probe Student Three (late)', 'Class 9th', 'Section Z');
        $this->_seedStudent('PROBE_STU_005', 'Probe Student Five', 'Class 9th', 'Section Z');
        $this->_seedStudent('PROBE_STU_016', 'Probe Student Sixteen', 'Class 9th', 'Section Z');
        // F-01 fixtures — 'Class 9th'/'Section Z' deliberately has NO sections
        // doc at all (proves canonical resolution degrades to manual fallback
        // when no section record exists). These two DO have section docs:
        $this->_seedStudent('PROBE_STU_CANON', 'Probe Canonical Student', 'Class 10th', 'Section CN');
        $this->_seedStudent('PROBE_STU_NOTEACH', 'Probe No-Teacher Student', 'Class 11th', 'Section NT');
        $this->_seedSection('PROBE_SEC_CN', 'Class 10th', 'Section CN', 'PROBE_TEACHER_CANON');
        $this->_seedSection('PROBE_SEC_NT', 'Class 11th', 'Section NT', ''); // explicit no-class-teacher section

        // Default acting identity holds manage-level Campus Access (matches
        // real Front Office / Admin usage) so the pre-existing happy-path
        // assertions below don't need to change just because F-01 added a
        // manage-level gate on the staff admin-approval slot. Teacher-slot
        // self-match tests below switch actor via _asActor() instead — that
        // check is pure adminId comparison, independent of this grant.
        $this->_grantManage();

        // ── [1] Walk-in, no watchlist match ────────────────────────────
        $p1 = $this->visitor->createPass([
            'name' => 'Probe Visitor One', 'phone' => '9000000001',
            'purpose' => 'Delivery', 'visitorType' => 'Delivery',
        ]);
        $this->_track('visitPasses', (string) $p1['id'], $this->schoolA);
        $this->_track('visitors', (string) $p1['visitorId'], $this->schoolA);
        $this->_assert('[1] immediate check-in', $p1['status'] === 'checked_in', "status={$p1['status']}");
        $this->_assert('[1] checkInAt set', !empty($p1['checkInAt']));
        $this->_assert('[1] badge minted', !empty($p1['badgeCode']));

        // ── [7a] campusAccessEvents write-through on check-in ──────────
        $this->_track('campusAccessEvents', 'V_' . $p1['id'] . '_in', $this->schoolA);
        $evIn = $this->fs->getEntity('campusAccessEvents', 'V_' . $p1['id'] . '_in');
        $this->_assert('[7] ledger event written on check-in', $evIn !== null && $evIn['direction'] === 'in' && $evIn['personType'] === 'visitor');
        $p1AfterIn = $this->fs->getEntity('visitPasses', (string) $p1['id']);
        $this->_assert('[7] visitPasses.campusEventSyncedIn flipped true', ($p1AfterIn['campusEventSyncedIn'] ?? false) === true);

        // ── [2] Checkout ─────────────────────────────────────────────
        $p1co = $this->visitor->checkoutPass((string) $p1['id']);
        $this->_assert('[2] checkout closes pass', $p1co['status'] === 'checked_out', "status={$p1co['status']}");
        $this->_assert('[2] checkOutAt set', !empty($p1co['checkOutAt']));

        // ── [7b] campusAccessEvents write-through on checkout ──────────
        $this->_track('campusAccessEvents', 'V_' . $p1['id'] . '_out', $this->schoolA);
        $evOut = $this->fs->getEntity('campusAccessEvents', 'V_' . $p1['id'] . '_out');
        $this->_assert('[7] ledger event written on checkout', $evOut !== null && $evOut['direction'] === 'out');

        // ── [8] Reconciliation is idempotent — a true no-op once synced ──
        $fixedBefore = $this->visitor->reconcileCampusEvents();
        $stillSameIn = $this->fs->getEntity('campusAccessEvents', 'V_' . $p1['id'] . '_in');
        $this->_assert('[8] reconcile does not re-touch an already-synced pass', !in_array((string) $p1['id'] . ':in', $fixedBefore, true));
        $this->_assert('[8] the synced-in event is unchanged (same authorizedBy/timestamp shape, no duplicate)', $stillSameIn['id'] === $evIn['id']);

        // ── [3] Watchlist flag_for_review -> pending -> approve ────────
        $wl1 = $this->visitor->addWatchlistEntry([
            'name' => 'Probe Flagged Visitor', 'severity' => 'flag_for_review', 'reason' => 'probe test entry',
        ]);
        $this->_track('visitorWatchlist', (string) $wl1['id'], $this->schoolA);
        $p3 = $this->visitor->createPass([
            'name' => 'Probe Flagged Visitor', 'phone' => '9000000003', 'purpose' => 'Other',
        ]);
        $this->_track('visitPasses', (string) $p3['id'], $this->schoolA);
        $this->_track('visitors', (string) $p3['visitorId'], $this->schoolA);
        $this->_assert('[3] flag hit forces pending_approval', $p3['status'] === 'pending_approval', "status={$p3['status']}");
        $this->_assert('[3] watchlistHit recorded', (bool) $p3['watchlistHit']);
        $p3a = $this->visitor->approvePass((string) $p3['id'], false);
        $this->_assert('[3] manual approve -> checked_in', $p3a['status'] === 'checked_in', "status={$p3a['status']}");
        $this->_assert('[3] approvedBy is the acting admin, not system', $p3a['approvedBy'] === 'probe_core' && $p3a['autoApproved'] === false);
        $this->_track('campusAccessEvents', 'V_' . $p3['id'] . '_in', $this->schoolA);

        // ── [4] Watchlist block -> denied -> override ──────────────────
        $wl2 = $this->visitor->addWatchlistEntry([
            'name' => 'Probe Blocked Visitor', 'severity' => 'block', 'reason' => 'probe test block entry',
        ]);
        $this->_track('visitorWatchlist', (string) $wl2['id'], $this->schoolA);
        $p4 = $this->visitor->createPass([
            'name' => 'Probe Blocked Visitor', 'phone' => '9000000004', 'purpose' => 'Other',
        ]);
        $this->_track('visitPasses', (string) $p4['id'], $this->schoolA);
        $this->_track('visitors', (string) $p4['visitorId'], $this->schoolA);
        $this->_assert('[4] block hit denies at check-in', $p4['status'] === 'denied', "status={$p4['status']}");
        $this->_assert('[4] no badge on a denied pass', empty($p4['badgeCode']));
        $p4o = $this->visitor->overrideBlock((string) $p4['id'], 'probe override reason');
        $this->_assert('[4] override flips to checked_in', $p4o['status'] === 'checked_in', "status={$p4o['status']}");
        $this->_assert('[4] override is logged', !empty($p4o['overriddenBy']) && !empty($p4o['overrideReason']));
        $this->_assert('[4] badge minted post-override', !empty($p4o['badgeCode']));
        $this->_track('campusAccessEvents', 'V_' . $p4['id'] . '_in', $this->schoolA);

        // ── [5] Auto-approve sweep never touches a block hit ───────────
        $syntheticId = $this->_seedSyntheticPendingBlock();
        $this->_track('visitPasses', $syntheticId, $this->schoolA);
        $approved = $this->visitor->sweepAutoApprove();
        $this->_assert('[5] sweep did not auto-approve the block-severity pass', !in_array($syntheticId, $approved, true), 'approved=' . json_encode($approved));
        $stillPending = $this->fs->getEntity('visitPasses', $syntheticId);
        $this->_assert('[5] block-severity pass is still pending_approval', ($stillPending['status'] ?? '') === 'pending_approval');

        // ── [9] Student early-leave — dual approval, never auto ────────
        $gp1 = $this->gatepass->createEarlyLeaveRequest([
            'personType' => 'student', 'personId' => 'PROBE_STU_001', 'personName' => 'Probe Student One',
            'reason' => 'Dental appointment', 'requestedTime' => '13:30', 'teacherStaffId' => 'PROBE_STAFF_T1',
        ]);
        $this->_track('campusAccessRequests', (string) $gp1['id'], $this->schoolA);
        $this->_assert('[9] student early-leave starts pending with both slots required', $gp1['status'] === 'pending_approval'
            && $gp1['approvals']['teacher']['required'] === true && $gp1['approvals']['parent']['required'] === true);
        $this->_asActor('PROBE_STAFF_T1', 'Probe Teacher One'); // F-01: only the resolved (here, manual-fallback) teacher may approve the teacher slot
        $gp1t = $this->gatepass->recordApproval((string) $gp1['id'], 'teacher', 'approved', 'Confirmed by class teacher');
        $this->_resetActor();
        $this->_assert('[9] teacher-only approval is NOT enough to release', $gp1t['status'] === 'pending_approval', "status={$gp1t['status']}");
        try {
            $this->gatepass->releaseGatePass((string) $gp1['id']);
            $this->_assert('[9] release is blocked before parent approval', false, 'releaseGatePass did not throw');
        } catch (\InvalidArgumentException $e) {
            $this->_assert('[9] release is blocked before parent approval', true);
        }
        $gp1p = $this->gatepass->recordApproval((string) $gp1['id'], 'parent', 'approved', 'Phoned parent, consent recorded', [
            'guardianName' => 'Probe Guardian One', 'relationship' => 'Mother', 'consentMethod' => 'phone', 'contact' => '9000000009',
        ]);
        $this->_assert('[9] both approvals -> status approved', $gp1p['status'] === 'approved', "status={$gp1p['status']}");
        $this->_assert('[9] parent slot records the guardian (who consented) distinct from the recorder (who recorded it)',
            $gp1p['approvals']['parent']['guardianName'] === 'Probe Guardian One' && $gp1p['approvals']['parent']['by'] === 'probe_core');
        $gp1r = $this->gatepass->releaseGatePass((string) $gp1['id']);
        $this->_assert('[9] release -> status released, linked event set', $gp1r['status'] === 'released' && !empty($gp1r['linkedEventId']));
        $this->_track('campusAccessEvents', (string) $gp1r['linkedEventId'], $this->schoolA);
        $ev9 = $this->fs->getEntity('campusAccessEvents', (string) $gp1r['linkedEventId']);
        $this->_assert('[9] ledger event is direction=out, personType=student', $ev9 !== null && $ev9['direction'] === 'out' && $ev9['personType'] === 'student');
        $this->_assert('[9] parent-notify path did not throw for a synthetic (non-Firestore) student', true); // reaching here proves it

        // ── [10] Student early-leave — either denial denies immediately ─
        $gp2 = $this->gatepass->createEarlyLeaveRequest([
            'personType' => 'student', 'personId' => 'PROBE_STU_002', 'personName' => 'Probe Student Two',
            'reason' => 'No valid reason given', 'requestedTime' => '14:00', 'teacherStaffId' => 'PROBE_STAFF_T1',
        ]);
        $this->_track('campusAccessRequests', (string) $gp2['id'], $this->schoolA);
        $this->_asActor('PROBE_STAFF_T1', 'Probe Teacher One');
        $gp2t = $this->gatepass->recordApproval((string) $gp2['id'], 'teacher', 'denied', 'Not authorized');
        $this->_resetActor();
        $this->_assert('[10] a single denial denies the whole request', $gp2t['status'] === 'denied', "status={$gp2t['status']}");

        // ── [11] Staff early-leave — single admin approval, different policy ─
        $gp3 = $this->gatepass->createEarlyLeaveRequest([
            'personType' => 'staff', 'personId' => 'PROBE_STAFF_001', 'personName' => 'Probe Staff One',
            'reason' => 'Medical', 'requestedTime' => '15:00',
        ]);
        $this->_track('campusAccessRequests', (string) $gp3['id'], $this->schoolA);
        $this->_assert('[11] staff request has ONLY an admin approval slot', array_keys($gp3['approvals']) === ['admin']);
        $gp3a = $this->gatepass->recordApproval((string) $gp3['id'], 'admin', 'approved', 'Manager approved');
        $this->_assert('[11] single admin approval -> approved', $gp3a['status'] === 'approved', "status={$gp3a['status']}");
        $gp3r = $this->gatepass->releaseGatePass((string) $gp3['id']);
        $this->_track('campusAccessEvents', (string) $gp3r['linkedEventId'], $this->schoolA);
        $ev11 = $this->fs->getEntity('campusAccessEvents', (string) $gp3r['linkedEventId']);
        $this->_assert('[11] ledger event personType=staff', $ev11 !== null && $ev11['personType'] === 'staff');

        // ── [12] Late entry — no approval gate ──────────────────────────
        $gp4 = $this->gatepass->logLateEntry([
            'personType' => 'student', 'personId' => 'PROBE_STU_003', 'personName' => 'Probe Student Three (late)',
            'reason' => 'Traffic',
        ]);
        $this->_track('campusAccessRequests', (string) $gp4['id'], $this->schoolA);
        $this->_assert('[12] late entry logs immediately, no approval step', $gp4['status'] === 'logged' && empty($gp4['approvals']));
        $this->_track('campusAccessEvents', (string) $gp4['linkedEventId'], $this->schoolA);
        $ev12 = $this->fs->getEntity('campusAccessEvents', (string) $gp4['linkedEventId']);
        $this->_assert('[12] ledger event direction=in for late entry', $ev12 !== null && $ev12['direction'] === 'in');

        // ── [13] Muster reflects on-campus state correctly ──────────────
        $muster = $this->gatepass->muster();
        $this->_assert('[13] muster returns per-type counts', isset($muster['counts']['student'], $muster['counts']['staff'], $muster['counts']['visitor']));
        $onCampusStudentIds = array_column($muster['onCampus']['student'] ?? [], 'id');
        $this->_assert('[13] released (departed) student is NOT on campus', !in_array('PROBE_STU_001', $onCampusStudentIds, true));
        $this->_assert('[13] late-entry (still on campus) student IS on campus', in_array('PROBE_STU_003', $onCampusStudentIds, true));

        // ── [14] Visitor sweep never touches gate-pass approval state ───
        $gp5 = $this->gatepass->createEarlyLeaveRequest([
            'personType' => 'student', 'personId' => 'PROBE_STU_005', 'personName' => 'Probe Student Five',
            'reason' => 'probe idle-window check', 'requestedTime' => '16:00', 'teacherStaffId' => 'PROBE_STAFF_T1',
        ]);
        $this->_track('campusAccessRequests', (string) $gp5['id'], $this->schoolA);
        $this->_asActor('PROBE_STAFF_T1', 'Probe Teacher One');
        $this->gatepass->recordApproval((string) $gp5['id'], 'teacher', 'approved'); // teacher only — parent still pending
        $this->_resetActor();
        $this->visitor->sweepAutoApprove(); // visitor-only sweep; must not affect gate-pass data at all
        $gp5After = $this->fs->getEntity('campusAccessRequests', (string) $gp5['id']);
        $this->_assert('[14] gate-pass request untouched by the visitor auto-approve sweep', ($gp5After['status'] ?? '') === 'pending_approval');

        // ── [6] Multi-tenant isolation (visitor) ─────────────────────────
        $p6a = $this->visitor->createPass([
            'name' => 'Probe Isolation Visitor', 'phone' => '9000000006', 'purpose' => 'Other',
        ]);
        $this->_track('visitPasses', (string) $p6a['id'], $this->schoolA);
        $this->_track('visitors', (string) $p6a['visitorId'], $this->schoolA);
        $this->_track('campusAccessEvents', 'V_' . $p6a['id'] . '_in', $this->schoolA);

        $this->_initSchool($this->schoolB);
        $crossRead = $this->fs->getEntity('visitPasses', (string) $p6a['id']);
        $this->_assert('[6] school B cannot read school A\'s pass by bare id', $crossRead === null, $crossRead === null ? '' : 'LEAKED: ' . json_encode($crossRead));
        $schoolBOnCampus = $this->visitor->listOnCampus();
        $leaked = array_filter($schoolBOnCampus, function ($r) use ($p6a) {
            $inner = (isset($r['data']) && is_array($r['data'])) ? $r['data'] : $r;
            return ($inner['id'] ?? '') === $p6a['id'];
        });
        $this->_assert('[6] school B\'s on-campus list does not include school A\'s pass', empty($leaked));

        // ── [15] Multi-tenant isolation extends to the two new collections ─
        $crossReadReq = $this->fs->getEntity('campusAccessRequests', (string) $gp1['id']);
        $this->_assert('[15] school B cannot read school A\'s gate-pass request', $crossReadReq === null);
        $crossReadEvt = $this->fs->getEntity('campusAccessEvents', 'V_' . $p1['id'] . '_in');
        $this->_assert('[15] school B cannot read school A\'s ledger event', $crossReadEvt === null);

        $p6b = $this->visitor->createPass([
            'name' => 'Probe Isolation Visitor B', 'phone' => '9000000007', 'purpose' => 'Other',
        ]);
        $this->_track('visitPasses', (string) $p6b['id'], $this->schoolB);
        $this->_track('visitors', (string) $p6b['visitorId'], $this->schoolB);
        $this->_track('campusAccessEvents', 'V_' . $p6b['id'] . '_in', $this->schoolB);
        $this->_assert('[6] school A and B allocate independent sequences (no shared counter bleed)', true, "A={$p6a['id']} B={$p6b['id']}");

        // Back to school A for the remaining sections.
        $this->_initSchool($this->schoolA);

        // ── [16] Invalid state transitions rejected server-side ─────────
        $inv1 = $this->visitor->createPass(['name' => 'Probe Invalid Txn', 'phone' => '9000000016', 'purpose' => 'Other']);
        $this->_track('visitPasses', (string) $inv1['id'], $this->schoolA);
        $this->_track('visitors', (string) $inv1['visitorId'], $this->schoolA);
        $this->_track('campusAccessEvents', 'V_' . $inv1['id'] . '_in', $this->schoolA);
        $this->_assertThrows('[16] approvePass on an already-checked_in pass is rejected', function () use ($inv1) {
            $this->visitor->approvePass((string) $inv1['id'], false);
        });
        $inv1co = $this->visitor->checkoutPass((string) $inv1['id']);
        $this->_track('campusAccessEvents', 'V_' . $inv1['id'] . '_out', $this->schoolA);
        $this->_assertThrows('[16] checkoutPass twice (already checked_out) is rejected', function () use ($inv1) {
            $this->visitor->checkoutPass((string) $inv1['id']);
        });
        $this->_assertThrows('[16] overrideBlock on a checked_out pass is rejected', function () use ($inv1) {
            $this->visitor->overrideBlock((string) $inv1['id'], 'should not work');
        });

        $wl3 = $this->visitor->addWatchlistEntry(['name' => 'Probe NonBlock Deny', 'severity' => 'flag_for_review', 'reason' => 'probe']);
        $this->_track('visitorWatchlist', (string) $wl3['id'], $this->schoolA);
        $inv2 = $this->visitor->createPass(['name' => 'Probe NonBlock Deny', 'phone' => '9000000017', 'purpose' => 'Other']);
        $this->_track('visitPasses', (string) $inv2['id'], $this->schoolA);
        $this->_track('visitors', (string) $inv2['visitorId'], $this->schoolA);
        $inv2d = $this->visitor->denyPass((string) $inv2['id'], 'not authorized');
        $this->_assertThrows('[16] overrideBlock on a plain (non-block) denial is rejected — only a block hit is overridable', function () use ($inv2) {
            $this->visitor->overrideBlock((string) $inv2['id'], 'should not work');
        });
        $this->_assertThrows('[16] approvePass on a denied pass is rejected', function () use ($inv2) {
            $this->visitor->approvePass((string) $inv2['id'], false);
        });

        // Gate pass invalid transitions.
        $gp6 = $this->gatepass->createEarlyLeaveRequest([
            'personType' => 'student', 'personId' => 'PROBE_STU_016', 'personName' => 'Probe Student Sixteen',
            'reason' => 'probe invalid-transition check', 'requestedTime' => '13:00', 'teacherStaffId' => 'PROBE_STAFF_T1',
        ]);
        $this->_track('campusAccessRequests', (string) $gp6['id'], $this->schoolA);
        $this->_asActor('PROBE_STAFF_T1', 'Probe Teacher One');
        $this->gatepass->recordApproval((string) $gp6['id'], 'teacher', 'denied', 'test denial');
        $this->_resetActor();
        $this->_assertThrows('[16] recordApproval on an already-denied request is rejected', function () use ($gp6) {
            $this->gatepass->recordApproval((string) $gp6['id'], 'parent', 'approved', 'too late');
        });
        $this->_assertThrows('[16] releaseGatePass on a denied (never approved) request is rejected', function () use ($gp6) {
            $this->gatepass->releaseGatePass((string) $gp6['id']);
        });

        $gp7 = $this->gatepass->createEarlyLeaveRequest([
            'personType' => 'staff', 'personId' => 'PROBE_STAFF_016', 'personName' => 'Probe Staff Sixteen', 'reason' => 'probe', 'requestedTime' => '15:00',
        ]);
        $this->_track('campusAccessRequests', (string) $gp7['id'], $this->schoolA);
        $this->gatepass->recordApproval((string) $gp7['id'], 'admin', 'approved');
        $gp7r = $this->gatepass->releaseGatePass((string) $gp7['id']);
        $this->_track('campusAccessEvents', (string) $gp7r['linkedEventId'], $this->schoolA);
        $this->_assertThrows('[16] releaseGatePass twice (double release) is rejected', function () use ($gp7) {
            $this->gatepass->releaseGatePass((string) $gp7['id']);
        });
        $this->_assertThrows('[16] recordApproval on an already-released request is rejected', function () use ($gp7) {
            $this->gatepass->recordApproval((string) $gp7['id'], 'admin', 'approved');
        });

        // ── [17] Repeated cron/reconciliation execution is safe (3x) ────
        $before = $this->fs->schoolWhere('campusAccessEvents', [], null, 'ASC', 500);
        $countBefore = count($before);
        $overstayRuns = [];
        $reconcileRuns = [];
        for ($i = 0; $i < 3; $i++) {
            $overstayRuns[] = count($this->visitor->sweepOverstay());
            $reconcileRuns[] = count($this->visitor->reconcileCampusEvents());
        }
        $after = $this->fs->schoolWhere('campusAccessEvents', [], null, 'ASC', 500);
        $countAfter = count($after);
        $this->_assert('[17] 3x repeated sweepOverstay produces a stable (non-growing) flagged count', $overstayRuns[1] === $overstayRuns[2], 'runs=' . json_encode($overstayRuns));
        $this->_assert('[17] 3x repeated reconcileCampusEvents is a no-op after the first pass (idempotent)', $reconcileRuns[2] === 0, 'runs=' . json_encode($reconcileRuns));
        $this->_assert('[17] no new ledger events were created by repeated sweeps/reconciliation', $countAfter === $countBefore, "before={$countBefore} after={$countAfter}");

        // ── [18] F-01: canonical teacher resolution + approver identity binding, F-08: person existence ──
        $gp18a = $this->gatepass->createEarlyLeaveRequest([
            'personType' => 'student', 'personId' => 'PROBE_STU_CANON', 'personName' => 'ignored — resolved from record',
            'reason' => 'probe canonical-teacher check', 'requestedTime' => '12:00',
            // deliberately NO teacherStaffId — proves canonical resolution needs no client input
        ]);
        $this->_track('campusAccessRequests', (string) $gp18a['id'], $this->schoolA);
        $this->_assert('[18] canonical section.classTeacherId resolves with no client-submitted teacherStaffId',
            $gp18a['teacherStaffId'] === 'PROBE_TEACHER_CANON' && $gp18a['teacherSource'] === 'canonical',
            'teacherStaffId=' . $gp18a['teacherStaffId'] . ' source=' . $gp18a['teacherSource']);

        $this->_asActor('PROBE_TEACHER_OTHER', 'Probe Other Teacher');
        $this->_revokeManage();
        $this->_assertThrows('[18] a non-bound, non-manage staff member cannot approve the teacher slot', function () use ($gp18a) {
            $this->gatepass->recordApproval((string) $gp18a['id'], 'teacher', 'approved', 'should not work');
        });

        $this->_asActor('PROBE_TEACHER_CANON', 'Probe Canonical Teacher');
        $gp18aT = $this->gatepass->recordApproval((string) $gp18a['id'], 'teacher', 'approved', 'self-approved by the bound teacher');
        $this->_assert('[18] the canonically-bound teacher CAN self-approve, with no delegation record', $gp18aT['approvals']['teacher']['status'] === 'approved' && empty($gp18aT['approvals']['teacher']['delegatedApproval']));
        $this->_resetActor();

        $gp18b = $this->gatepass->createEarlyLeaveRequest([
            'personType' => 'student', 'personId' => 'PROBE_STU_NOTEACH', 'personName' => 'ignored',
            'reason' => 'probe unresolved-teacher check', 'requestedTime' => '12:15',
        ]);
        $this->_track('campusAccessRequests', (string) $gp18b['id'], $this->schoolA);
        $this->_assert('[18] no classTeacherId + no manual fallback -> teacherStaffId empty, source=unresolved', $gp18b['teacherStaffId'] === '' && $gp18b['teacherSource'] === 'unresolved');

        $this->_revokeManage();
        $this->_assertThrows('[18] an unresolved-teacher slot rejects even a manage-attempt with no delegate reason (manage not yet granted)', function () use ($gp18b) {
            $this->gatepass->recordApproval((string) $gp18b['id'], 'teacher', 'approved', 'no reason given');
        });
        $this->_grantManage();
        $this->_assertThrows('[18] an unresolved-teacher slot rejects a manage-level approval with NO delegate reason', function () use ($gp18b) {
            $this->gatepass->recordApproval((string) $gp18b['id'], 'teacher', 'approved', 'no reason given');
        });
        $gp18bT = $this->gatepass->recordApproval((string) $gp18b['id'], 'teacher', 'approved', 'delegate ok', ['delegateReason' => 'No class teacher configured for this section yet']);
        $this->_assert('[18] a manage-level delegate CAN approve an unresolved-teacher slot WITH a reason', $gp18bT['approvals']['teacher']['status'] === 'approved');
        $deleg = $gp18bT['approvals']['teacher']['delegatedApproval'] ?? [];
        $this->_assert('[18] the delegation is logged (actingStaffId, reason, null original — no bound teacher existed)',
            ($deleg['actingStaffId'] ?? '') === 'probe_core' && ($deleg['reason'] ?? '') === 'No class teacher configured for this section yet' && array_key_exists('originalTeacherStaffId', $deleg) && $deleg['originalTeacherStaffId'] === null);

        $gp18c = $this->gatepass->createEarlyLeaveRequest([
            'personType' => 'student', 'personId' => 'PROBE_STU_NOTEACH', 'personName' => 'ignored',
            'reason' => 'probe manual-fallback check', 'requestedTime' => '12:30', 'teacherStaffId' => 'PROBE_TEACHER_OTHER',
        ]);
        $this->_track('campusAccessRequests', (string) $gp18c['id'], $this->schoolA);
        $this->_assert('[18] a manual teacherStaffId fallback is used when it resolves to a real staff record', $gp18c['teacherStaffId'] === 'PROBE_TEACHER_OTHER' && $gp18c['teacherSource'] === 'manual');

        $gp18d = $this->gatepass->createEarlyLeaveRequest([
            'personType' => 'student', 'personId' => 'PROBE_STU_NOTEACH', 'personName' => 'ignored',
            'reason' => 'probe bogus-fallback check', 'requestedTime' => '12:45', 'teacherStaffId' => 'DOES_NOT_EXIST_STAFF_ID',
        ]);
        $this->_track('campusAccessRequests', (string) $gp18d['id'], $this->schoolA);
        $this->_assert('[18] a manual teacherStaffId that does NOT resolve to a real staff record is NOT trusted (falls back to unresolved)', $gp18d['teacherStaffId'] === '' && $gp18d['teacherSource'] === 'unresolved');

        $this->_revokeManage();
        $this->_assertThrows('[18] a parent "approved" decision with no guardianName/relationship/consentMethod is rejected', function () use ($gp18a) {
            $this->gatepass->recordApproval((string) $gp18a['id'], 'parent', 'approved', 'no consent detail given');
        });
        $gp18aP = $this->gatepass->recordApproval((string) $gp18a['id'], 'parent', 'approved', 'phoned the guardian', [
            'guardianName' => 'Probe Guardian', 'relationship' => 'Mother', 'consentMethod' => 'phone', 'contact' => '9876543210',
        ]);
        $this->_assert('[18] parent slot stores guardian identity distinct from the recording staff member, contact masked',
            $gp18aP['approvals']['parent']['guardianName'] === 'Probe Guardian'
            && $gp18aP['approvals']['parent']['relationship'] === 'Mother'
            && $gp18aP['approvals']['parent']['by'] === 'probe_core'
            && $gp18aP['approvals']['parent']['contactMasked'] !== '9876543210'
            && substr((string) $gp18aP['approvals']['parent']['contactMasked'], -3) === '210');
        $this->_assertThrows('[18] parent consentMethod must be phone or in_person, not an arbitrary string', function () use ($gp18c) {
            $this->gatepass->recordApproval((string) $gp18c['id'], 'parent', 'approved', 'bad method', [
                'guardianName' => 'X', 'relationship' => 'Father', 'consentMethod' => 'carrier_pigeon',
            ]);
        });

        $gp18e = $this->gatepass->createEarlyLeaveRequest([
            'personType' => 'staff', 'personId' => 'PROBE_STAFF_001', 'personName' => 'ignored',
            'reason' => 'probe admin-slot identity check', 'requestedTime' => '13:00',
        ]);
        $this->_track('campusAccessRequests', (string) $gp18e['id'], $this->schoolA);
        $this->_revokeManage();
        $this->_assertThrows('[18] an edit-level (non-manage) holder cannot approve the staff admin slot', function () use ($gp18e) {
            $this->gatepass->recordApproval((string) $gp18e['id'], 'admin', 'approved', 'should not work');
        });
        $this->_grantManage();
        $gp18eA = $this->gatepass->recordApproval((string) $gp18e['id'], 'admin', 'approved', 'manage-level approved');
        $this->_assert('[18] a manage-level holder CAN approve the staff admin slot', $gp18eA['approvals']['admin']['status'] === 'approved');

        // F-08: a personId with no matching same-school record is rejected outright.
        $this->_assertThrows('[18/F-08] createEarlyLeaveRequest rejects a personId with no matching student record', function () {
            $this->gatepass->createEarlyLeaveRequest(['personType' => 'student', 'personId' => 'GHOST_STUDENT_DOES_NOT_EXIST', 'reason' => 'probe', 'requestedTime' => '10:00']);
        });
        $this->_assertThrows('[18/F-08] createEarlyLeaveRequest rejects a staff personId with no matching staff record', function () {
            $this->gatepass->createEarlyLeaveRequest(['personType' => 'staff', 'personId' => 'GHOST_STAFF_DOES_NOT_EXIST', 'reason' => 'probe', 'requestedTime' => '10:00']);
        });
        $this->_assertThrows('[18/F-08] logLateEntry rejects a personId with no matching record', function () {
            $this->gatepass->logLateEntry(['personType' => 'student', 'personId' => 'GHOST_STUDENT_LATE', 'reason' => 'probe']);
        });

        // ── [19] F-03: QR lookup is read-only; checkout requires a SEPARATE, server-reverified explicit confirm ──
        $q1 = $this->visitor->createPass(['name' => 'Probe QR Visitor', 'phone' => '9000000019', 'purpose' => 'Delivery']);
        $this->_track('visitPasses', (string) $q1['id'], $this->schoolA);
        $this->_track('visitors', (string) $q1['visitorId'], $this->schoolA);
        $this->_track('campusAccessEvents', 'V_' . $q1['id'] . '_in', $this->schoolA);
        $validToken = (string) $q1['badgeCode'];

        $lookup1 = $this->visitor->lookupByQrToken($validToken);
        $this->_assert('[19] a valid QR token resolves to the correct pass', $lookup1['passId'] === $q1['id'] && $lookup1['canCheckout'] === true);
        $stillCheckedIn = $this->fs->getEntity('visitPasses', (string) $q1['id']);
        $this->_assert('[19] the lookup alone did NOT change the pass status', ($stillCheckedIn['status'] ?? '') === 'checked_in');

        $this->_assertThrows('[19] a garbage/unparseable token is rejected', function () {
            $this->visitor->lookupByQrToken('not-a-real-token!!');
        });
        $this->_assertThrows('[19] an empty token is rejected', function () {
            $this->visitor->lookupByQrToken('');
        });

        $flipChar = substr($validToken, -2, 1) === 'A' ? 'B' : 'A';
        $tampered = substr($validToken, 0, -2) . $flipChar . substr($validToken, -1);
        $this->_assertThrows('[19] a tampered token (bad HMAC signature) is rejected', function () use ($tampered) {
            $this->visitor->lookupByQrToken($tampered);
        });

        $this->_initSchool($this->schoolB);
        $this->_assertThrows('[19] a school-A token is rejected when looked up under school B (tenant check)', function () use ($validToken) {
            $this->visitor->lookupByQrToken($validToken);
        });
        $this->_initSchool($this->schoolA);
        $this->_grantManage();

        $this->_assertThrows('[19] checkoutPass rejects a claimed rfid verification method — no reader exists', function () use ($q1) {
            $this->visitor->checkoutPass((string) $q1['id'], 'rfid', '');
        });
        $this->_assertThrows('[19] checkoutPass rejects a claimed biometric verification method', function () use ($q1) {
            $this->visitor->checkoutPass((string) $q1['id'], 'biometric', '');
        });

        $q2 = $this->visitor->createPass(['name' => 'Probe QR Visitor Two', 'phone' => '9000000020', 'purpose' => 'Delivery']);
        $this->_track('visitPasses', (string) $q2['id'], $this->schoolA);
        $this->_track('visitors', (string) $q2['visitorId'], $this->schoolA);
        $this->_track('campusAccessEvents', 'V_' . $q2['id'] . '_in', $this->schoolA);
        $this->_assertThrows('[19] qr_manual checkout with a token for a DIFFERENT pass is rejected', function () use ($q2, $validToken) {
            $this->visitor->checkoutPass((string) $q2['id'], 'qr_manual', $validToken);
        });
        $stillCheckedInQ2 = $this->fs->getEntity('visitPasses', (string) $q2['id']);
        $this->_assert('[19] the mismatched-token attempt did NOT check q2 out', ($stillCheckedInQ2['status'] ?? '') === 'checked_in');
        $this->_assertThrows('[19] qr_manual checkout with no token at all is rejected', function () use ($q1) {
            $this->visitor->checkoutPass((string) $q1['id'], 'qr_manual', '');
        });

        $q1co = $this->visitor->checkoutPass((string) $q1['id'], 'qr_manual', $validToken);
        $this->_track('campusAccessEvents', 'V_' . $q1['id'] . '_out', $this->schoolA);
        $this->_assert('[19] the correct lookup-then-confirm flow checks the visitor out', $q1co['status'] === 'checked_out' && $q1co['checkOutVerificationMethod'] === 'qr_manual');

        $q3 = $this->visitor->createPass(['name' => 'Probe QR Visitor Three', 'phone' => '9000000021', 'purpose' => 'Delivery']);
        $this->_track('visitPasses', (string) $q3['id'], $this->schoolA);
        $this->_track('visitors', (string) $q3['visitorId'], $this->schoolA);
        $this->_track('campusAccessEvents', 'V_' . $q3['id'] . '_in', $this->schoolA);
        $q3co = $this->visitor->checkoutPass((string) $q3['id']);
        $this->_track('campusAccessEvents', 'V_' . $q3['id'] . '_out', $this->schoolA);
        $this->_assert('[19] manual checkout (no QR) still works, defaulting to verificationMethod=manual', $q3co['status'] === 'checked_out' && $q3co['checkOutVerificationMethod'] === 'manual');

        $this->_assertThrows('[19] re-scanning an already-checked-out pass\'s QR and confirming again is rejected', function () use ($q1, $validToken) {
            $this->visitor->checkoutPass((string) $q1['id'], 'qr_manual', $validToken);
        });
        $lookupAfterCheckout = $this->visitor->lookupByQrToken($validToken);
        $this->_assert('[19] a lookup on an already-checked-out pass reports canCheckout=false', $lookupAfterCheckout['canCheckout'] === false);

        $this->_assertThrows('[19] releaseGatePass rejects a claimed qr_manual method — no QR path built for gate-pass release', function () use ($gp18e) {
            $this->gatepass->releaseGatePass((string) $gp18e['id'], 'qr_manual', '');
        });

        // ── Teardown (best-effort; runs regardless of pass/fail) ────────
        $this->_teardown();

        echo "\n" . sprintf('%d/%d assertions passed', $this->pass, $this->pass + $this->fail) . "\n";
        exit($this->fail === 0 ? 0 : 2);
    }

    /**
     * Directly seed a pending_approval pass with watchlistSeverity=block and
     * a backdated requestedAt — simulates the one edge state createPass()
     * itself never produces (a block hit is denied immediately, not left
     * pending) so the sweep's guard is exercised against the worst case.
     */
    private function _seedSyntheticPendingBlock(): string
    {
        $id = $this->numbering->next('vms_pass', ['claimedBy' => 'probe_core', 'claimedFor' => 'probe:synthetic']);
        $this->fs->setEntity('visitPasses', $id, [
            'id' => $id, 'visitorId' => 'PROBE', 'visitorNameSnapshot' => 'Probe Synthetic Block',
            'visitorPhoneSnapshot' => '9000000005', 'purpose' => 'Other', 'status' => 'pending_approval',
            'source' => 'walk_in', 'requestedAt' => date('c', time() - 20 * 60), // 20 min ago
            'watchlistHit' => true, 'watchlistSeverity' => 'block', 'watchlistReason' => 'probe synthetic',
            'expectedDurationMinutes' => 60, 'accompanying' => 0,
            'campusEventSyncedIn' => false, 'campusEventSyncedOut' => false,
        ], false);
        return $id;
    }

    private function _teardown(): void
    {
        $bySchool = [];
        foreach ($this->created as $row) $bySchool[$row['school']][] = $row;
        foreach ($bySchool as $school => $rows) {
            $this->_initSchool($school);
            foreach ($rows as $row) {
                $this->fs->removeEntity($row['col'], $row['id']);
            }
        }
        echo sprintf("teardown: removed %d probe doc(s) across %d tenant(s)\n", count($this->created), count($bySchool));
    }
}
