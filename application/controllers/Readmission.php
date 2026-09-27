<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Readmission — the ONE endpoint that may invoke Sr_no_service::relink().
 *
 * Per SRNO_RE_ADMISSION_ARCHITECTURE_AUDIT.md (Strategy A, approved
 * 2026-08-13): a verified re-admission gets a brand-new Student ID (created
 * through the existing, UNMODIFIED Sis::enroll_student() — this controller
 * never creates a student), and this controller's confirm() then links that
 * new record to the prior one and preserves the SRNO, as ONE atomic
 * operation (Sr_no_service::relink — see that file for the transaction).
 *
 * Deliberately NOT touching Sis::enroll_student(): that method is the most
 * complex, already-hardened, already-heavily-modified controller action in
 * this repository (per the Phase 0 audit). Re-admission is therefore
 * implemented as an ADDITIVE second step an operator takes AFTER ordinary
 * enrolment, not a code path threaded through it — this is a deliberate
 * blast-radius decision, not an oversight (see
 * SRNO_RE_ADMISSION_IMPLEMENTATION_REPORT.md §1).
 *
 * RBAC — hard role-name gate, not the graded SIS:manage capability. The
 * original SR design's own §11.3 left "confirm the role for
 * correct_sr_number, given the current RBAC label-allowlist gap" as an open
 * decision; this controller resolves it the same way for the sibling
 * relink operation: Super Admin / School Super Admin (always) or literal
 * Principal — deliberately narrower than Sis::MANAGE_ROLES, which also
 * includes Admin/Vice Principal/Front Office. Moving an SRNO's ownership is
 * exactly the class of action the design already reserves for Principal-
 * level correction.
 *
 * No `previousStudentId`/SRNO field is ever accepted from the client for
 * DIRECT persistence — every value this controller writes is either
 * re-validated against the live Firestore record or produced server-side.
 */
class Readmission extends MY_Controller
{
    /** Deliberately narrower than Sis::MANAGE_ROLES — see class docblock. */
    private const RELINK_ROLES = ['Super Admin', 'School Super Admin', 'Principal'];

    public function __construct()
    {
        parent::__construct();
        $this->load->library('firebase');
        $this->load->library('firestore_service', null, 'fs');
    }

    private function _require_principal(string $action): void
    {
        $role = (string) ($this->admin_role ?? '');
        foreach (self::RELINK_ROLES as $r) {
            if (strcasecmp($role, $r) === 0) return;
        }
        log_message('warning', "Readmission::{$action} denied for role=[{$role}] admin={$this->admin_id}");
        $this->json_error('This action requires Principal-level authorization.', 403);
    }

    /** Canonical service handle. */
    private function _svc(): Sr_no_service
    {
        $this->load->library('Sr_no_service', null, 'srnoSvc');
        return $this->srnoSvc->init($this->firebase, $this->fs, $this->school_id, (string) ($this->admin_id ?? ''));
    }

    /**
     * $this->fs is bound to the CURRENT request's schoolId (set at
     * MY_Controller::__construct time from the operator's own session/
     * claims) — docId($studentId) therefore ALWAYS resolves within the
     * caller's own tenant. A Student ID belonging to a different school
     * cannot be fetched through this method under any input, by
     * construction, not by an added check — this is what makes the
     * cross-school previous_student_id rejection in confirm() structural
     * rather than merely validated.
     */
    private function _student(string $studentId): ?array
    {
        $doc = $this->fs->get('students', $this->fs->docId($studentId));
        return is_array($doc) ? $doc : null;
    }


    /**
     * POST — confirm a verified re-admission: link the new Student ID to
     * the prior one and relink the prior SRNO onto it, atomically.
     *
     * Params: student_id (the NEW, already-created record), previous_student_id
     * (the OLD record this is confirmed to be the same real-world student
     * as), reason (optional free text for the audit trail).
     *
     * Per SRNO_RE_ADMISSION_ARCHITECTURE_AUDIT.md Part U §1 / Section 20:
     * if $previous_student_id does not resolve to a real, existing student
     * record, this is REJECTED outright — the system never falsely
     * establishes continuity it cannot verify. A school recalling a
     * long-gone student with no ZenX record must use the ordinary manual-
     * entry SRNO path on a fresh admission, with no previousStudentId link.
     */
    public function confirm()
    {
        $this->_require_principal('confirm');
        if ($this->input->method() !== 'post') return $this->json_error('POST required.');

        $newStudentId = $this->safe_path_segment((string) $this->input->post('student_id'), 'student_id');
        $oldStudentId = $this->safe_path_segment((string) $this->input->post('previous_student_id'), 'previous_student_id');
        $reason       = trim((string) ($this->input->post('reason') ?? 're-admission'));

        if ($newStudentId === $oldStudentId) {
            return $this->json_error('A student cannot be re-admitted as itself.');
        }

        $newStu = $this->_student($newStudentId);
        if ($newStu === null) {
            return $this->json_error('The new admission record was not found.', 404);
        }
        // Section 6 — never allow the client's own claim to establish
        // continuity a second time; a prior confirm() already set this.
        if (!empty($newStu['previousStudentId'])) {
            return $this->json_error('This admission record is already linked to a prior Student ID.');
        }

        $oldStu = $this->_student($oldStudentId);
        if ($oldStu === null) {
            // Section 20 — no old ZenX record exists. Do NOT falsely
            // establish continuity. The operator's only safe path here is
            // the ordinary manual SRNO entry on the new admission, with no
            // link — this endpoint is not that path. This ALSO fires (by
            // construction, not a special case) for a cross-school
            // previous_student_id: _student() reads via $this->fs, which is
            // bound to the CURRENT operator's own schoolId, so a Student ID
            // belonging to a different school can never resolve here at
            // all — see class docblock note in _student().
            log_audit('SIS', 'readmission_link_rejected_no_prior_record', $newStudentId,
                "previous_student_id={$oldStudentId} not found — continuity cannot be verified (not found in this school)", []);
            return $this->json_error('No existing record was found for the prior Student ID in this school. A re-admission link cannot be established without a verifiable prior record.', 404);
        }

        // Circular-link guard — enforced inside Sr_no_service itself (see
        // Sr_no_service::relink()'s own cycle check), not duplicated here.
        // A cycle can only be introduced by calling confirm() with the roles
        // reversed relative to an EARLIER, already-established link (e.g.
        // confirm(student_id=STU0001, previous_student_id=STU0047) after
        // STU0047.previousStudentId was already set to STU0001) — the "new
        // record has no previousStudentId yet" check above does NOT catch
        // this on its own, because STU0001 never had one written; only the
        // forward direction did. relink() below refuses it.

        // A student cannot be simultaneously "current" under two active
        // records — the prior record must already be in a left-the-school
        // state before it can be superseded.
        $oldStatus = (string) ($oldStu['status'] ?? $oldStu['Status'] ?? 'Active');
        if (strcasecmp($oldStatus, 'Active') === 0) {
            return $this->json_error('The prior Student ID is still Active. Withdraw or issue a TC for it before confirming a re-admission.');
        }

        $oldSrNo = trim((string) ($oldStu['srNo'] ?? ''));
        if ($oldSrNo === '') {
            return $this->json_error('The prior Student ID has no SRNO on file to preserve. Assign an SRNO to the new admission normally instead.');
        }

        $res = $this->_svc()->relink($oldSrNo, $oldStudentId, $newStudentId, $reason !== '' ? $reason : 're-admission');

        if (empty($res['ok'])) {
            $reasonCode = (string) ($res['reason'] ?? 'unknown');
            $messages = [
                'expected_owner_mismatch' => 'The SRNO no longer belongs to the prior Student ID given — it may already have been relinked.',
                'srno_not_found'          => 'The SRNO record could not be found.',
                'contention_exhausted'    => 'This SRNO is being updated by another request. Please retry.',
                'same_student_id'         => 'The new and prior Student IDs must be different.',
                'invalid_format'          => 'The prior record\'s SRNO is not in a valid format.',
            ];
            return $this->json_error($messages[$reasonCode] ?? 'Could not confirm the re-admission link.');
        }

        // Bidirectional history entry for the prior record too — link
        // direction is new -> old on students.previousStudentId (written
        // atomically inside relink()); this mirrors it into crmApplications-
        // style traceability without ever writing to STU-old's own record
        // beyond what relink() already wrote server-side.
        log_audit('SIS', 'readmission_confirmed', $newStudentId,
            "Re-admission confirmed: {$oldStudentId} -> {$newStudentId}, SRNO {$oldSrNo} preserved", [
                'previousStudentId' => $oldStudentId,
                'srNo' => $oldSrNo,
                'reason' => $reason,
                'noop' => ($res['reason'] ?? '') === 'noop',
            ]);

        // Phase 9 (2026-08-13) — APAAR follows the PERSON (LOCKED policy,
        // Part 8): if the prior record has an APAAR on file, relink it onto
        // the new Student ID too, via a SEPARATE service and a SEPARATE
        // atomic operation from the SRNO relink above — deliberately not
        // merged into Sr_no_service::relink()'s own batch (APAAR is a
        // distinct identity domain, per SR_NUMBER_AND_APAAR_ID_DESIGN.md
        // §4/§6). APAAR remains optional at every stage: a prior record
        // with no APAAR on file simply never reaches this block, and that
        // is not an error. If a prior record DOES have an APAAR but the
        // relink itself fails (contention, cycle, etc.), the SRNO relink
        // above has ALREADY committed and is not rolled back — this mirrors
        // this codebase's established "primary op commits, secondary op is
        // best-effort with a surfaced warning" pattern (e.g. enroll_student's
        // fee-assignment step), never a silent partial success.
        $apaarLinked = false;
        $apaarWarning = null;
        $oldApaarId = trim((string) ($oldStu['apaarId'] ?? ''));
        if ($oldApaarId !== '') {
            $this->load->library('Apaar_service', null, 'apaarSvc');
            $apaarRes = $this->apaarSvc->init($this->firebase, $this->fs, $this->school_id, (string) ($this->admin_id ?? ''))
                ->relink($oldApaarId, $oldStudentId, $newStudentId, $reason !== '' ? $reason : 're-admission');
            if (!empty($apaarRes['ok'])) {
                $apaarLinked = true;
            } else {
                $apaarReasonCode = (string) ($apaarRes['reason'] ?? 'unknown');
                // Phase 9 correction pass (2026-08-13) — the prior wording
                // ("carry it forward manually from the student profile")
                // promised a manual-recovery UI that does not exist
                // (application/views/edit_student.php has no APAAR field).
                // Corrected to state the true, current status only.
                $apaarWarning = "SRNO was relinked successfully. The re-admission was completed, but the prior record's APAAR ID could not be carried forward automatically (reason: {$apaarReasonCode}); APAAR requires administrator follow-up.";
                log_message('warning', "Readmission::confirm APAAR relink failed {$oldStudentId} -> {$newStudentId} reason={$apaarReasonCode}");
            }
        }

        // Phase 10.2 (2026-08-13) — RE-ADMISSION IS A NEW ADMISSION EVENT,
        // so it receives a BRAND-NEW Admission Number. This is the deliberate
        // opposite of SRNO/APAAR (both PRESERVED, because they identify the
        // PERSON) — Admission Number identifies the EVENT, and this is a new
        // event. It is explicitly NOT derived from the preserved SRNO, NOT
        // from the class, and NOT from the old Admission Number.
        //
        // This closes the Phase 10.1 gap where a re-admitted student kept an
        // Admission Number computed from a since-replaced SRNO. Best-effort
        // and non-blocking, matching the APAAR block above: the SRNO relink
        // has already committed by this point and is never rolled back for a
        // secondary-step failure.
        $newAdmissionNo = '';
        $admNoWarning = null;
        try {
            $admYear = substr((string) $this->session_year, 0, 4);
            if (!preg_match('/^\d{4}$/', $admYear)) $admYear = date('Y');
            $this->load->library('Admission_no_service', null, 'admNoSvc');
            $admRes = $this->admNoSvc->init($this->firebase, $this->fs, $this->school_id, (string) ($this->admin_id ?? ''))
                ->allocate($this->school_id, $newStudentId, $admYear);
            if (!empty($admRes['ok'])) {
                $newAdmissionNo = (string) $admRes['admissionNo'];
                $this->fs->update('students', $this->fs->docId($newStudentId), [
                    'Admission Number' => $newAdmissionNo,
                    'admissionNumber'  => $newAdmissionNo,
                    'updatedAt'        => date('c'),
                ]);
            } else {
                $admNoWarning = 'The re-admission was completed, but a new Admission Number could not be generated automatically; it requires administrator follow-up.';
                log_message('error', "Readmission::confirm admission-number allocation failed for {$newStudentId} reason=" . ($admRes['reason'] ?? 'unknown'));
            }
        } catch (\Throwable $e) {
            $admNoWarning = 'The re-admission was completed, but a new Admission Number could not be generated automatically; it requires administrator follow-up.';
            log_message('error', "Readmission::confirm admission-number allocation threw for {$newStudentId}: " . $e->getMessage());
        }

        return $this->json_success([
            'newStudentId'      => $newStudentId,
            'previousStudentId' => $oldStudentId,
            'srNo'              => $oldSrNo,
            'admissionNo'       => $newAdmissionNo,   // Phase 10.2 — NEW for this admission event
            'admissionNoWarning'=> $admNoWarning,
            'linked'            => true,
            'apaarLinked'       => $apaarLinked,
            'apaarWarning'      => $apaarWarning,
        ]);
    }

    /**
     * GET — SRNO -> currentStudentId, for Student 360/SIS search integration.
     * View-level graded capability; no legacy role fallback needed since
     * this is a pure lookup, not a mutation.
     */
    public function resolve()
    {
        $this->_require_role([], 'srno_resolve', 'SIS', 'view');

        $srNo = trim((string) $this->input->get('srno'));
        if ($srNo === '') return $this->json_error('Missing srno.');

        $res = $this->_svc()->resolve($srNo);
        if (empty($res['ok'])) {
            return $this->json_error('No student found for that SRNO.', 404);
        }

        return $this->json_success([
            'currentStudentId' => $res['currentStudentId'],
            'srNo'             => $res['srNo'],
        ]);
    }
}
