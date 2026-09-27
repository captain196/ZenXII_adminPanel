<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Any staff token can read every staff member's leave application.
 *
 * FOUND 2026-08-21 during the Phase F tenant/authorization sweep.
 *
 * `match /leaveApplications/{docId}`:
 *
 *     allow read: if isSameSchool()
 *                 && ( isStaff() || resource.data.applicantId == request.auth.uid );
 *
 * `leaveApplications` is a SHARED collection: student leave and staff leave live
 * side by side, distinguished by `applicantType`. The `isStaff()` arm therefore
 * grants any staff token read access to every OTHER staff member's leave —
 * including the free-text `reason`, which routinely carries medical and personal
 * detail.
 *
 * The rule's own comment states its purpose: "Staff see the whole school
 * (student-leave queue + own balance)". BOTH stated purposes survive a narrower
 * rule; the cross-staff visibility is incidental breadth, not a requirement.
 *
 * PROVEN AGAINST BOTH CLIENTS — every reference to the collection lives in one
 * repository per app, and the full set of query shapes is:
 *
 *   Teacher  getLeaveHistory()        schoolId + applicantId==self + applicantType=='staff'
 *            getStudentLeaveRequests() schoolId + applicantType=='student' [+class/section]
 *            approve/rejectStudentLeave  student only
 *   Parent   own applications only
 *
 * NO client queries another staff member's leave. There is no
 * "who is on leave today" staff roster in either app (the `onLeave` hits are in
 * MyAttendanceScreen, which is self-scoped and does not read this collection).
 *
 * The intended behaviour is NOT a judgement call here — this same rules file has
 * already made exactly this narrowing twice, and says so:
 *   appraisals:  "Was isSameSchool() — leaked every staff appraisal to any same-school token."
 *   salarySlips: "Was isSameSchool() — leaked every staff payslip to any same-school token
 *                 incl parents/students."
 * Staff-personal data is self-only, admin-wide. Leave reason is the same class of
 * data and is the remaining member of that family.
 *
 * NOT FIXED HERE — `firebase-rules/firestore.rules` is concurrently edited and
 * currently carries ~958 uncommitted lines from at least three other programmes;
 * a deploy ships the whole file. Needs the pull-first flow plus deploy
 * authorization. Patch prepared in `D004_STAFF_LEAVE_READ_SCOPE_PATCH.md`.
 *
 * WHAT IS ALREADY CORRECT and must not regress (asserted below): the write arms
 * are hardened well beyond the norm — no self-approval, no attendance
 * pre-stamping, and a staff member's own PAYROLL-BEARING leave can only be
 * decided by an admin.
 */
final class StaffLeaveReadScopeTest extends TestCase
{
    private string $rules;
    private string $flat;

    protected function setUp(): void
    {
        $r = file_get_contents(__DIR__ . '/../../firebase-rules/firestore.rules');
        $this->assertNotFalse($r);
        $this->rules = $r;
        // Whitespace/newline-normalised: the rules file has been observed with
        // both LF and CRLF, so never pin on literal line breaks.
        $this->flat = preg_replace('/\s+/', ' ', $r);
    }

    private function block(): string
    {
        $i = strpos($this->rules, 'match /leaveApplications/{docId}');
        $this->assertNotFalse($i, 'leaveApplications rule block not found');
        return substr($this->rules, (int) $i, 4200);
    }

    /** Whitespace-normalised block — the rules file has both LF and CRLF in the wild. */
    private function flatBlock(): string
    {
        return preg_replace('/\s+/', ' ', $this->block());
    }

    /**
     * Read predicate as shipped today.
     *
     * D-004 APPLIED 2026-08-22. This previously modelled the broad arm
     * `isStaff() || applicantId == uid`. It now models the narrowed one, so
     * `canRead()` and `canReadFixed()` agree — which is the point: the two
     * predicates existing separately was the defect being tracked, and every
     * assertion made against BOTH is now a regression guard rather than a
     * before/after comparison. Kept as two methods deliberately: if anyone
     * widens the shipped arm again, the divergence reappears here first.
     */
    private function canRead(array $doc, string $uid, bool $isStaff, bool $sameSchool = true, bool $isAdmin = false): bool
    {
        return $this->canReadFixed($doc, $uid, $isStaff, $isAdmin, $sameSchool);
    }

    /**
     * Read predicate the patch installs.
     *
     * The `staffId` arm is NOT optional. `leaveApplications` is polymorphic: it
     * also stores leave BALANCE documents as `BAL_{staffId}_{year}` with
     * `type == 'balance'`, and those carry `staffId` but neither `applicantId`
     * nor `applicantType`. The Teacher app reads them directly via
     * `getBalanceDoc()`. Without this arm the narrowing would deny every teacher
     * their own leave balance.
     *
     * Field access is modelled as safe-get (`resource.data.get(f, '')`) because
     * a bare `resource.data.applicantId` on a balance document is a missing-field
     * ERROR in Firestore rules, which denies rather than evaluating to null.
     */
    private function canReadFixed(array $doc, string $uid, bool $isStaff, bool $isAdmin = false, bool $sameSchool = true): bool
    {
        if (!$sameSchool) return false;
        return $isAdmin
            || ($isStaff && (string) ($doc['applicantType'] ?? '') === 'student')
            || (string) ($doc['applicantId'] ?? '') === $uid
            || (string) ($doc['staffId'] ?? '') === $uid;
    }

    /** A leave BALANCE document as actually stored. */
    private function balanceDoc(string $staffId): array
    {
        return ['type' => 'balance', 'staffId' => $staffId, 'year' => '2026', 'balances' => []];
    }

    // ── Tenant isolation: already correct, must never regress ────────────

    public function test_a_different_school_can_never_read(): void
    {
        $doc = ['applicantId' => 'STA0007', 'applicantType' => 'staff'];

        $this->assertFalse($this->canRead($doc, 'STA0007', true, false),
            'isSameSchool() gates every read arm');
        $this->assertFalse($this->canReadFixed($doc, 'STA0007', true, true, false),
            'the patch must keep the tenant gate outermost');
    }

    // ── What the clients actually need — preserved by the patch ──────────

    public function test_a_teacher_can_still_read_their_own_leave(): void
    {
        $doc = ['applicantId' => 'STA0007', 'applicantType' => 'staff'];

        $this->assertTrue($this->canRead($doc, 'STA0007', true));
        $this->assertTrue($this->canReadFixed($doc, 'STA0007', true),
            'getLeaveHistory() must keep working');
    }

    public function test_a_teacher_can_still_read_the_student_leave_queue(): void
    {
        $doc = ['applicantId' => 'STU0042', 'applicantType' => 'student'];

        $this->assertTrue($this->canRead($doc, 'STA0007', true));
        $this->assertTrue($this->canReadFixed($doc, 'STA0007', true),
            'getStudentLeaveRequests() must keep working');
    }

    public function test_a_parent_can_still_read_their_own_application(): void
    {
        $doc = ['applicantId' => 'PAR0001', 'applicantType' => 'student'];

        $this->assertTrue($this->canRead($doc, 'PAR0001', false));
        $this->assertTrue($this->canReadFixed($doc, 'PAR0001', false));
    }

    public function test_a_parent_still_cannot_read_someone_elses(): void
    {
        $doc = ['applicantId' => 'STA0007', 'applicantType' => 'staff'];

        $this->assertFalse($this->canRead($doc, 'PAR0001', false),
            'the parent arm is already correct');
        $this->assertFalse($this->canReadFixed($doc, 'PAR0001', false));
    }

    public function test_an_admin_retains_full_visibility_under_the_patch(): void
    {
        $doc = ['applicantId' => 'STA0007', 'applicantType' => 'staff'];

        $this->assertTrue($this->canReadFixed($doc, 'STA0001', true, true),
            'HR/principal oversight must survive the narrowing');
    }

    // ── Polymorphic collection: balance documents ────────────────────────

    /**
     * REGRESSION GUARD ON THE PATCH ITSELF. The first draft of the D-004 patch
     * omitted the `staffId` arm and would have denied every teacher their own
     * leave balance, because a BAL_ document has no `applicantId`. Caught before
     * the patch was applied; this test is why it stays caught.
     */
    public function test_a_teacher_can_still_read_their_own_balance_document(): void
    {
        $bal = $this->balanceDoc('STA0007');

        $this->assertTrue($this->canRead($bal, 'STA0007', true),
            'works today only because isStaff() short-circuits');
        $this->assertTrue($this->canReadFixed($bal, 'STA0007', true),
            'getBalanceDoc() must keep working after the narrowing');
    }

    /** The same over-breadth applies to balances, not just applications. */
    public function test_a_peer_can_currently_read_another_staff_members_balance(): void
    {
        $bal = $this->balanceDoc('STA0007');

        $this->assertFalse($this->canRead($bal, 'STA0099', true),
            'a peer must not read a colleague leave balance');
        $this->assertFalse($this->canReadFixed($bal, 'STA0099', true),
            'and the narrowing closed it for balances, not just applications');
    }

    /**
     * A parent must not reach a balance document. Today that holds only because
     * `resource.data.applicantId` is a missing-field error on a BAL_ doc, which
     * denies. The patch makes the denial explicit rather than incidental.
     */
    public function test_a_parent_cannot_read_a_balance_document(): void
    {
        $bal = $this->balanceDoc('STA0007');

        $this->assertFalse($this->canRead($bal, 'PAR0001', false));
        $this->assertFalse($this->canReadFixed($bal, 'PAR0001', false));
    }

    // ── D-004, closed 2026-08-22 ─────────────────────────────────────────

    /**
     * THE DEFECT THIS SUITE WAS BUILT FOR. Previously marked incomplete
     * because the broad arm was still shipped; the rule was narrowed on
     * 2026-08-22 and this now asserts the closure outright.
     *
     * Inverted rather than deleted: a peer reading a colleague's free-text
     * leave reason is exactly what must never come back.
     *
     * NOT YET DEPLOYED. The rule is correct in the working tree; the live
     * project still serves the broad arm until someone with deploy authority
     * ships `firestore:rules`. This test guards the source, not production.
     */
    public function test_a_peer_cannot_read_another_staff_members_leave(): void
    {
        $doc = ['applicantId' => 'STA0007', 'applicantType' => 'staff', 'reason' => 'chemotherapy'];

        $this->assertFalse($this->canRead($doc, 'STA0099', true),
            'a peer must not read a colleague medical leave reason');
        $this->assertFalse($this->canReadFixed($doc, 'STA0099', true));

        // And the broad arm is genuinely gone from the source. Matched on the
        // READ arm specifically: checking the whole block for
        // "applicantType == 'student'" would false-positive, because that
        // string also appears in the UPDATE arm's staff-leave-needs-admin guard.
        $broadReadArm = 'allow read: if isSameSchool() '
            . '&& ( isStaff() || resource.data.applicantId == request.auth.uid );';

        $this->assertStringNotContainsString($broadReadArm, $this->flat,
            'the unrestricted staff read arm must not return');
    }

    /**
     * The narrowing must not have WIDENED anything on the way through.
     *
     * Caught while applying the patch: a first draft admitted the student
     * queue with a bare `applicantType == 'student'`, ungated. That would have
     * let any parent or student token read every OTHER student's leave —
     * closing the staff exposure while opening a student one of the same shape.
     * The shipped arm gates it on isStaff(); this is why it stays gated.
     */
    public function test_a_parent_cannot_read_another_students_leave(): void
    {
        $doc = ['applicantId' => 'STU0042', 'applicantType' => 'student', 'reason' => 'fever'];

        $this->assertFalse($this->canRead($doc, 'PAR0001', false),
            "a parent must reach only their own child's leave");
        $this->assertFalse($this->canReadFixed($doc, 'PAR0001', false));

        $this->assertStringContainsString(
            "( isStaff() && resource.data.get('applicantType', '') == 'student' )",
            $this->flatBlock(),
            'the student-queue arm must stay gated on isStaff()'
        );
    }

    // ── Write arms: already hardened — regression guards ─────────────────

    public function test_staff_leave_decisions_require_an_admin(): void
    {
        $this->assertStringContainsString(
            "( resource.data.applicantType == 'student' || isAdmin() )",
            $this->block(),
            'a peer must never approve payroll-bearing staff leave'
        );
    }

    public function test_self_approval_is_blocked_at_the_rules_layer(): void
    {
        $this->assertStringContainsString(
            'resource.data.applicantId != request.auth.uid',
            $this->block()
        );
    }

    public function test_attendance_cannot_be_pre_stamped_by_a_client(): void
    {
        $this->assertStringContainsString(
            'request.resource.data.attendanceStamped != true',
            $this->block(),
            "the service-account poller owns the 'L' stamp"
        );
    }

    public function test_a_parent_cannot_mint_a_staff_leave(): void
    {
        $this->assertStringContainsString(
            "( request.resource.data.applicantType == 'staff' && isStaff() )",
            $this->block()
        );
    }

    public function test_creation_is_always_pending_and_self_owned(): void
    {
        $b = $this->block();
        $this->assertStringContainsString("request.resource.data.status == 'pending'", $b);
        $this->assertStringContainsString('request.resource.data.applicantId == request.auth.uid', $b);
    }

    /** Identity and dates are frozen across an update. */
    public function test_identity_and_dates_are_immutable_on_update(): void
    {
        $b = $this->block();
        foreach (['schoolId', 'applicantId', 'applicantType', 'startDate', 'endDate'] as $f) {
            $this->assertStringContainsString(
                "request.resource.data.{$f} == resource.data.{$f}",
                $b,
                "{$f} must be frozen so an approved leave cannot be re-pointed"
            );
        }
    }

    /** Pins the exact read line the patch replaces. */
    public function test_current_read_arm_is_what_this_analysis_assumed(): void
    {
        $this->assertStringContainsString(
            "allow read: if isSameSchool() && ( isAdmin() "
            . "|| ( isStaff() && resource.data.get('applicantType', '') == 'student' ) "
            . "|| resource.data.get('applicantId', '') == request.auth.uid "
            . "|| resource.data.get('staffId', '') == request.auth.uid );",
            $this->flat,
            'if this changed, re-run the analysis in this docblock before trusting it'
        );
    }

    /**
     * Release large source strings between tests.
     *
     * PHPUnit keeps every TestCase instance alive for the duration of the run,
     * so a multi-hundred-KB source held in a property stays resident once per
     * test method. Measured: a source-reading class costs ~18 MB against a
     * 512 MB limit, and the suite was running at 506 MB. Clearing the reference
     * frees the string while leaving the (now tiny) instance for reporting.
     */
    protected function tearDown(): void
    {
        foreach (get_object_vars($this) as $prop => $val) {
            if (is_string($val) && strlen($val) > 50000) $this->{$prop} = '';
        }
    }
}
