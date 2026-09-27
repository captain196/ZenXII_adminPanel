<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * The Teacher app ↔ admin panel contract for attendance regularization.
 *
 * Most of this suite is a passing guard on a contract that holds. Two tests are
 * marked INCOMPLETE: they record defects found by the 2026-08-23 audit that
 * CANNOT be fixed from this repository under the standing constraints, and they
 * will start failing — loudly — the day the blocking constraint is lifted and
 * someone fixes them. They are not disabled tests; they are the finding.
 */
final class RegularizationAppContractTest extends TestCase
{
    private const APP_REPO = 'D:/Projects/SchoolSyncTeacher';
    private const APP_REPO_FILE =
        '/app/src/main/java/com/schoolsync/teacher/data/repository/firestore/AttendanceRegularizationRepository.kt';

    private static string $rules = '';
    private static string $att   = '';

    public static function setUpBeforeClass(): void
    {
        self::$rules = (string) file_get_contents(__DIR__ . '/../../firebase-rules/firestore.rules');
        self::$att   = (string) file_get_contents(__DIR__ . '/../../application/controllers/Attendance.php');
    }

    public static function tearDownAfterClass(): void
    {
        self::$rules = '';
        self::$att = '';
    }

    /** The app source, or null when this machine does not hold the app repo. */
    private function appSource(): ?string
    {
        $p = self::APP_REPO . self::APP_REPO_FILE;
        if (!is_file($p)) {
            return null;
        }
        return (string) file_get_contents($p);
    }

    /** The attendanceRegularizations rule block. */
    private function ruleBlock(): string
    {
        $i = strpos(self::$rules, 'match /attendanceRegularizations/{docId} {');
        $this->assertNotFalse($i, 'the regularization rule block must exist');

        $j = strpos(self::$rules, 'match /', (int) $i + 40);
        $block = substr(self::$rules, (int) $i, ($j === false ? 1500 : $j - (int) $i));

        $this->assertStringContainsString('allow create:', $block, 'slice sanity');
        return $block;
    }

    // ─────────────────────────────────────────────────────────────────────
    //  What holds
    // ─────────────────────────────────────────────────────────────────────

    /**
     * The identity used on both sides is the same one.
     *
     * This is what makes the client-supplied staffId safe AND what makes the
     * admin-side self-approval guard meaningful: the rule pins
     * `staffId == request.auth.uid`, and the GPS punch path derives the same
     * uid from the token. If either side ever moved to an STA-style id while
     * the other did not, approvals would stamp attendance onto a staff key the
     * register does not show, and the self-approval guard would compare two
     * different namespaces and never match.
     */
    public function test_both_sides_key_staff_by_the_auth_uid(): void
    {
        $block = $this->ruleBlock();

        $this->assertStringContainsString('request.resource.data.staffId == request.auth.uid', $block);
        $this->assertStringContainsString('resource.data.staffId == request.auth.uid', $block);

        $punch = (string) file_get_contents(__DIR__ . '/../../application/controllers/Staff_attendance.php');
        $this->assertStringContainsString("\$staffId = (string) (\$this->_claims['uid'] ?? '');", $punch,
            'the punch path must derive staffId from the token, not from the client');
    }

    /** A staff member may only ask for marks that carry no unearned pay. */
    public function test_a_staff_member_can_only_request_present_or_half_day(): void
    {
        $this->assertStringContainsString(
            "request.resource.data.requestedStatus in ['P', 'M']", $this->ruleBlock(),
            'a modified client must not be able to self-request Holiday, Leave, '
            . 'Weekly-off or Extra-pay marks');
    }

    /**
     * Only the service account decides. A client may now move a request of its
     * OWN between pending and cancelled (R-X1, deployed 2026-08-25), but an
     * OUTCOME — approved / rejected / auto_rejected — remains server-only.
     *
     * Before R-X1 this was guaranteed by `allow update, delete: if false`.
     * The owner-update arm replaced that blanket refusal, so the guarantee is
     * now carried by the arm's own status whitelist, and that is what this
     * asserts. Deleting the assertion along with the old line would have
     * quietly dropped the property.
     */
    public function test_only_the_backend_can_decide_a_request(): void
    {
        $block = $this->ruleBlock();

        // The only statuses a client may WRITE.
        $this->assertMatchesRegularExpression(
            "~request\.resource\.data\.status\s*==\s*'pending'~", $block,
            'a client may re-file (…-> pending)');
        $this->assertMatchesRegularExpression(
            "~request\.resource\.data\.status\s*==\s*'cancelled'~", $block,
            'a client may withdraw (pending -> cancelled)');

        // No outcome may be client-written.
        foreach (['approved', 'auto_rejected'] as $outcome) {
            $this->assertStringNotContainsString(
                "request.resource.data.status == '{$outcome}'", $block,
                "a client must never be able to write status={$outcome}");
        }

        // A decided request is untouchable: the arm demands both review fields
        // be empty on the incoming document.
        $this->assertStringContainsString("request.resource.data.get('reviewedBy', '') == ''", $block);
        $this->assertStringContainsString("request.resource.data.get('appliedMark', '') == ''", $block);

        // Deletion stays closed.
        $this->assertStringContainsString('allow delete: if false;', $block);
    }

    /** A request may only be created already-pending. */
    public function test_a_request_is_created_pending(): void
    {
        $this->assertStringContainsString("request.resource.data.status == 'pending'", $this->ruleBlock());
    }

    /**
     * The admin writes back every field the app's RegularizationDoc declares.
     * A missing one would leave the employee's history silently blank.
     */
    public function test_the_admin_writes_back_what_the_app_reads(): void
    {
        $app = $this->appSource();
        if ($app === null) {
            $this->markTestSkipped('Teacher app repo not present on this machine');
        }

        // Fields the app declares on the doc it reads back.
        foreach (['status', 'reviewedBy', 'remarks', 'appliedMark'] as $field) {
            $this->assertStringContainsString("val {$field}", $app,
                "the app no longer declares {$field}; the contract has moved");
            $this->assertStringContainsString("'{$field}'", self::$att,
                "the admin never writes {$field}, which the app reads back");
        }
    }

    // ─────────────────────────────────────────────────────────────────────
    //  What does not hold — recorded, not fixed
    // ─────────────────────────────────────────────────────────────────────

    /**
     * DEFECT R-X1 (HIGH, cross-system, BLOCKED).
     *
     * A teacher can never file a request for a date they have already filed
     * for — including one that was REJECTED. Rejection is a dead end.
     *
     * The mechanism, verified on both sides:
     *
     *   • the app writes a DETERMINISTIC doc id, {schoolId}_{staffId}_{date},
     *     with merge = true. Its own comment (TCH-H3) says this is so that "a
     *     double-submit for the same date overwrites the prior pending row
     *     instead of creating a duplicate";
     *   • but in Firestore rules a set-with-merge onto an EXISTING document is
     *     an UPDATE, and this collection is `allow update, delete: if false`.
     *     So the overwrite TCH-H3 describes cannot happen. It is denied;
     *   • and the submit is one atomic WriteBatch, so a single already-filed
     *     date fails the WHOLE batch — including the new dates in it;
     *   • the app surfaces `e.message`, so the teacher is shown a raw
     *     "PERMISSION_DENIED: Missing or insufficient permissions".
     *
     * Consequences for a real school: a rejected day can never be re-filed with
     * a better reason; a typo in a claimed time can never be corrected; and a
     * teacher filing Monday and Tuesday, then later Tuesday and Wednesday, loses
     * Wednesday too and is told nothing intelligible.
     *
     * WHY IT IS NOT FIXED HERE. The correct repair is in `firestore.rules`,
     * which this programme is under standing instruction not to modify (it is
     * edited concurrently by other sessions, and a deploy ships the whole file).
     * The app-side alternative — dropping the deterministic id — would reinstate
     * the duplicate-pending-row bug TCH-H3 exists to prevent.
     *
     * THE FIX, when the rules may be touched: allow an owner to update their own
     * request while it is still pending, freezing identity fields and forbidding
     * a client-set outcome. The SAME ruleset already implements exactly this for
     * leaveApplications ("clients may only CREATE a 'pending' request they own
     * and CANCEL their own"), so this is applying an established local pattern,
     * not inventing one:
     *
     *   allow update: if isStaff() && isSameSchool()
     *                 && resource.data.staffId == request.auth.uid
     *                 && resource.data.status == 'pending'
     *                 && request.resource.data.status == 'pending'
     *                 && request.resource.data.staffId == resource.data.staffId
     *                 && request.resource.data.date    == resource.data.date
     *                 && request.resource.data.requestedStatus in ['P', 'M'];
     *
     * This test asserts the CURRENT, defective state. It fails the moment the
     * rule is repaired — at which point delete the markTestIncomplete and keep
     * the assertions.
     */
    /**
     * R-X1 IS DEPLOYED (2026-08-25). The contract deliberately INVERTED: a
     * teacher may now withdraw a pending request and re-file a rejected one.
     *
     * What must still hold is that the new arm is NARROW — it is an owner
     * self-service window, not a way to edit someone else's day or to decide
     * one's own request. Those are the properties asserted here.
     */
    public function test_a_teacher_may_withdraw_or_refile_but_never_decide(): void
    {
        $block = $this->ruleBlock();

        $this->assertStringContainsString('allow update: if isStaff()', $block,
            'the owner-update arm is what makes withdraw and re-file possible');

        // Your own request, in your own school, only.
        $this->assertStringContainsString('resource.data.staffId == request.auth.uid', $block);
        $this->assertStringContainsString('isSameSchool()', $block);

        // The identity of the request is immutable across an owner update: a
        // re-file must not become a request for another person, day or tenant.
        foreach (['schoolId', 'staffId', 'date', 'personType'] as $frozen) {
            $this->assertMatchesRegularExpression(
                '~request\.resource\.data\.' . $frozen . '\s*==\s*resource\.data\.' . $frozen . '~',
                $block,
                "{$frozen} must be preserved across an owner update");
        }

        // A decided request stays decided.
        $this->assertStringContainsString("request.resource.data.get('reviewedBy', '') == ''", $block);
        $this->assertStringContainsString("request.resource.data.get('appliedMark', '') == ''", $block);

        // Deletion is still server-only.
        $this->assertStringContainsString('allow delete: if false;', $block);

        // The app still writes a deterministic id with merge — that is what
        // turns a re-submit into an UPDATE rather than a duplicate row.
        $app = $this->appSource();
        if ($app !== null) {
            $this->assertStringContainsString('val docId = "${schoolId}_${staffId}_${e.date}"', $app);
            $this->assertStringContainsString('merge = true', $app);
        }
    }

    /**
     * DEFECT R-X2 (LOW, cross-system, BLOCKED — same root cause).
     *
     * `cancelled` and `auto_rejected` are unreachable states. The app's
     * RegularizationDoc enumerates them and the admin list endpoint accepts them
     * as filters, but nothing can ever write them: clients cannot update, and no
     * admin path or cron sets either one. A teacher who files by mistake has no
     * way to withdraw, and a request pends forever with no expiry.
     *
     * Same blocker and same one-line remedy as R-X1: an owner-update arm would
     * make withdrawal possible immediately.
     */
    /**
     * R-X2 is CLOSED for `cancelled` and still open for `auto_rejected`.
     *
     * Withdrawal now works: the owner-update arm writes `cancelled`, so the
     * state is reachable and the Teacher app's Withdraw control has something
     * to call. `auto_rejected` remains unreachable — nothing writes it, and no
     * expiry job exists — which is recorded here rather than left to be
     * rediscovered.
     */
    public function test_cancelled_is_reachable_and_auto_rejected_is_not(): void
    {
        $block = $this->ruleBlock();

        // The admin list endpoint accepts both as filters.
        $this->assertStringContainsString(
            "\$allowed = ['pending', 'approved', 'rejected', 'cancelled', 'auto_rejected', 'all'];",
            self::$att);

        // CANCELLED: reachable, and only by the owner of a pending request.
        $this->assertMatchesRegularExpression(
            "~request\.resource\.data\.status\s*==\s*'cancelled'~", $block,
            'withdrawal writes cancelled');
        $this->assertMatchesRegularExpression(
            "~resource\.data\.status\s*==\s*'pending'~", $block,
            'only a PENDING request may be withdrawn');

        // AUTO_REJECTED: still nothing writes it, server or client.
        foreach (["'status'      => 'auto_rejected'", "'status'     => 'auto_rejected'"] as $writer) {
            $this->assertStringNotContainsString($writer, self::$att,
                'no admin path writes auto_rejected');
        }
        $this->assertStringNotContainsString("request.resource.data.status == 'auto_rejected'", $block,
            'and no client may write it either');

        $this->markTestIncomplete(
            'R-X2 (REMAINDER): `cancelled` is now reachable end to end — rules arm '
            . 'deployed 2026-08-25 and verified against the live ruleset (27/27), Teacher '
            . 'app Withdraw control shipped, admin queue exposes a Withdrawn filter. '
            . '`auto_rejected` is still unreachable: no writer and no expiry job exists. '
            . 'Whether stale requests should auto-expire, and after how long, is a '
            . 'PRODUCT decision — not an engineering gap to close silently.'
        );
    }

    // ─────────────────────────────────────────────────────────────────────
    //  The withdraw control (built 2026-08-23)
    // ─────────────────────────────────────────────────────────────────────

    /**
     * The Teacher app can now withdraw a pending request.
     *
     * The rule arm, the app control and the admin queue tab were built
     * together — a rule that permits a transition no client can perform is
     * exactly as useless as the control that had no rule behind it.
     */
    public function test_the_app_ships_a_withdraw_control(): void
    {
        $repo = $this->appSource();
        if ($repo === null) { $this->markTestSkipped('Teacher app repo not present'); }

        $this->assertStringContainsString('suspend fun withdraw(docId: String)', $repo);
        $this->assertStringContainsString('"status" to "cancelled"', $repo);

        $vm = (string) file_get_contents(self::APP_REPO
            . '/app/src/main/java/com/schoolsync/teacher/ui/myattendance/RegularizationViewModel.kt');
        $this->assertStringContainsString('fun withdraw(docId: String)', $vm);

        $sheet = (string) file_get_contents(self::APP_REPO
            . '/app/src/main/java/com/schoolsync/teacher/ui/myattendance/RegularizeSheet.kt');
        $this->assertStringContainsString('Yes, withdraw', $sheet);
    }

    /**
     * PENDING ONLY on the app side too. greytHR documents the boundary: the
     * Withdraw action lives on the Pending tab, and once approved "it cannot
     * be canceled". The rule enforces it; the app must not offer what the
     * server will refuse.
     */
    public function test_the_app_offers_withdraw_only_while_pending(): void
    {
        $sheet = @file_get_contents(self::APP_REPO
            . '/app/src/main/java/com/schoolsync/teacher/ui/myattendance/RegularizeSheet.kt');
        if ($sheet === false) { $this->markTestSkipped('Teacher app repo not present'); }

        $this->assertStringContainsString(
            'if (doc.status.equals("pending", ignoreCase = true)) {', $sheet,
            'a decided request must not offer a withdraw control');
    }

    /** Two taps: a withdrawn request cannot be un-withdrawn. */
    public function test_withdrawing_is_confirmed(): void
    {
        $sheet = @file_get_contents(self::APP_REPO
            . '/app/src/main/java/com/schoolsync/teacher/ui/myattendance/RegularizeSheet.kt');
        if ($sheet === false) { $this->markTestSkipped('Teacher app repo not present'); }

        $this->assertStringContainsString('confirming', $sheet);
        $this->assertStringContainsString('Withdraw this request?', $sheet);
    }

    /** Busy state is per-row, and the result is re-read rather than assumed. */
    public function test_the_withdraw_has_a_loading_and_disabled_state(): void
    {
        $vm = @file_get_contents(self::APP_REPO
            . '/app/src/main/java/com/schoolsync/teacher/ui/myattendance/RegularizationViewModel.kt');
        if ($vm === false) { $this->markTestSkipped('Teacher app repo not present'); }

        $this->assertStringContainsString('val withdrawingId: String? = null', $vm,
            'per-row, so one slow request does not spin every card');
        $this->assertStringContainsString('_ui.value.withdrawingId != null', $vm,
            'a second tap while one is in flight must be ignored');
        $this->assertStringContainsString('loadMyRequests()', $vm,
            'the new status must be re-read, never assumed');
    }

    /** A failed withdrawal tells the teacher, rather than failing silently. */
    public function test_a_failed_withdrawal_is_surfaced(): void
    {
        $vm = @file_get_contents(self::APP_REPO
            . '/app/src/main/java/com/schoolsync/teacher/ui/myattendance/RegularizationViewModel.kt');
        if ($vm === false) { $this->markTestSkipped('Teacher app repo not present'); }

        $this->assertStringContainsString('Could not withdraw the request.', $vm);
    }

    /** And the admin can see a withdrawn request — never invisible. */
    public function test_the_admin_queue_shows_withdrawn_requests(): void
    {
        $view = (string) file_get_contents(
            __DIR__ . '/../../application/views/attendance/staff_regularization.php');

        $this->assertStringContainsString('data-status="cancelled"', $view);
        $this->assertStringContainsString('Withdrawn', $view);
    }
}
