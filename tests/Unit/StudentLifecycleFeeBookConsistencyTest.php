<?php

use PHPUnit\Framework\TestCase;

if (!defined('APPPATH')) define('APPPATH', __DIR__ . '/../../application/');
if (!defined('BASEPATH')) define('BASEPATH', 'x');

/**
 * ══════════════════════════════════════════════════════════════════════════
 *  EVERY DOOR TO A STUDENT STATE MUST MOVE THE FEE BOOK WITH IT
 * ══════════════════════════════════════════════════════════════════════════
 *
 * ── LC-1 · THE DEFECT (found 2026-08-26) ─────────────────────────────────
 * `Sis::change_status()` flips a student Active <-> Inactive. It handled the
 * status write, the history log, the Android sync, attendance blanking and
 * section strength — and never touched the fee book.
 *
 * It reaches the SAME two states as withdraw_student and cancel_tc, so both
 * directions were financially wrong:
 *
 *   Active -> Inactive   demands stayed COLLECTABLE. The student kept accruing
 *                        late fees (Fee_late_fee_service exempts only
 *                        'frozen'), kept appearing in defaulter reports, and
 *                        kept showing dues in the Parent App — while
 *                        withdraw_student, reaching the identical state, froze
 *                        them. Two doors, one outcome, different money.
 *
 *   Inactive -> Active   a previously withdrawn student came back with the
 *                        whole fee book still 'frozen': silently exempt from
 *                        fees AND late fees for the rest of the session, with
 *                        nothing reporting it. That is FRZ-4 exactly, reached
 *                        through a different door than cancel_tc.
 *
 * FRZ-4's own docblock says "freezeFeesOnSoftDelete() has three live callers".
 * There are FOUR doors to Inactive — withdraw_student, delete_student, Hostel,
 * and change_status. Counting callers of the freeze function could never have
 * found the missing one; only enumerating the doors could.
 *
 * ── WHAT THIS TEST ASSERTS, AND WHY IT IS STRUCTURAL ─────────────────────
 * A CodeIgniter controller cannot be instantiated without the framework, so
 * the wiring cannot be executed here. The wiring IS the defect class — a
 * transition that forgets the fee book — so it is asserted directly, with
 * comment-stripped source and an ordering check, plus a control proving the
 * stripper works. The money rules these calls invoke are covered
 * behaviourally by the Fee_lifecycle suites.
 */
final class StudentLifecycleFeeBookConsistencyTest extends TestCase
{
    private static string $src = '';

    public static function setUpBeforeClass(): void
    {
        $out = '';
        foreach (token_get_all((string) file_get_contents(APPPATH . 'controllers/Sis.php')) as $t) {
            if (is_array($t)) {
                if (in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true)) continue;
                $out .= $t[1];
            } else { $out .= $t; }
        }
        self::$src = $out;
    }

    /** Executable body of one method, comments already removed. */
    private function body(string $method): string
    {
        $at = strpos(self::$src, "public function {$method}(");
        $this->assertNotFalse($at, "method {$method} not found — the controller shape changed");
        $next = strpos(self::$src, 'public function ', $at + 20);
        return substr(self::$src, $at, ($next === false ? strlen(self::$src) : $next) - $at);
    }

    public function test_the_comment_stripper_actually_strips(): void
    {
        // Control. Without it every assertion below could be satisfied by a
        // comment that merely mentions the function name — and this file's own
        // fix comment does mention both.
        $this->assertStringNotContainsString('THE DEFECT', self::$src);
        $this->assertStringNotContainsString('FRZ-4', self::$src);
    }

    /**
     * Every endpoint that can put a student INTO Inactive must freeze.
     * Enumerated as doors, not as callers of the freeze function — that
     * inversion is what let one door stay open.
     */
    public function test_every_door_to_inactive_freezes_the_fee_book(): void
    {
        foreach (['withdraw_student', 'delete_student', 'change_status'] as $door) {
            $this->assertStringContainsString('freezeFeesOnSoftDelete', $this->body($door),
                "{$door} moves a student to Inactive without freezing the fee book — "
                . 'the student keeps accruing late fees and showing dues in the Parent App');
        }
    }

    /** Every endpoint that can put a student BACK into Active must unfreeze. */
    public function test_every_door_back_to_active_unfreezes_the_fee_book(): void
    {
        foreach (['cancel_tc', 'change_status'] as $door) {
            $this->assertStringContainsString('unfreezeFeesOnRestore', $this->body($door),
                "{$door} reactivates a student without unfreezing the fee book — "
                . 'they return Active but silently exempt from fees for the session');
        }
    }

    public function test_the_status_flip_only_acts_on_a_real_transition(): void
    {
        // Re-saving Active over Active must not unfreeze a book that was frozen
        // for an unrelated reason, and re-saving Inactive must not re-stamp
        // demands that are already frozen.
        $body = $this->body('change_status');
        $this->assertMatchesRegularExpression('/\$priorStatus\s*!==\s*\$newStatus/', $body,
            'the fee-book transition is unconditional — a re-save would move the book');
    }

    public function test_the_prior_status_is_read_before_the_write(): void
    {
        // Ordering is the whole invariant. Reading it after updateEntity()
        // would always compare the new value against itself, so the guard
        // above would never fire and the fee book would never move.
        $body     = $this->body('change_status');
        $readAt   = strpos($body, '$priorStatus');
        $writeAt  = strpos($body, "updateEntity('students'");
        $this->assertNotFalse($readAt, 'prior status is not captured at all');
        $this->assertNotFalse($writeAt, 'the status write moved');
        $this->assertLessThan($writeAt, $readAt,
            'the prior status is read AFTER the write — it would always equal the new status');
    }

    public function test_the_fee_book_transition_cannot_fail_the_status_change(): void
    {
        // Both existing call sites are best-effort. A status change must not
        // fail because one demand could not be re-stamped.
        $body = $this->body('change_status');
        $at   = strpos($body, 'freezeFeesOnSoftDelete');
        $this->assertNotFalse($at);
        $window = substr($body, max(0, $at - 200), 500);
        $this->assertStringContainsString('try', $window, 'the fee-book call is not guarded');
        $this->assertStringContainsString('catch', $window);
        $this->assertStringContainsString('Throwable', $window,
            'catch \Exception misses an Error from a missing collaborator (FEE-W1)');
    }

    public function test_the_transition_is_authorized_and_tenant_scoped(): void
    {
        // A lifecycle transition with financial consequences must not be
        // reachable without the manage right.
        $body = $this->body('change_status');
        $this->assertStringContainsString("'SIS', 'manage'", $body,
            'change_status lost its authorization gate');
        $this->assertStringContainsString('safe_path_segment', $body,
            'the student id is not validated — path traversal into another tenant');
    }

    /**
     * ── LC-6 · THE TC DOOR ───────────────────────────────────────────────
     *
     * The test above enumerates doors to *Inactive*, which is why it could
     * not see this one: `issue_tc()` exits to status 'TC', not 'Inactive'.
     * It is nonetheless the most final exit ZenX has — it also disables the
     * student's Firebase Auth user — and it was the last door that left the
     * fee book collectable.
     *
     * Enumerated here by CONSEQUENCE (the student has left) rather than by
     * destination status, because destination status is exactly the axis that
     * hid it.
     */
    public function test_every_exit_from_the_school_freezes_the_fee_book(): void
    {
        foreach (['withdraw_student', 'delete_student', 'change_status', 'issue_tc'] as $door) {
            $this->assertStringContainsString('freezeFeesOnSoftDelete', $this->body($door),
                "{$door} removes a student from the school without freezing the fee book — "
                . 'they keep accruing late fees, keep appearing in defaulter reports, and '
                . 'keep showing dues in the Parent App for a school they have left');
        }
    }

    /**
     * The reversal already existed. `cancel_tc()` calls unfreezeFeesOnRestore,
     * so the code ALREADY asserts that a TC'd student's book is frozen — it
     * simply never froze it, leaving a reversal that restored a state which
     * had never been set.
     *
     * Asserted as a PAIR so neither half can be removed on its own: an
     * unfreeze with no matching freeze is the bug this closes, and a freeze
     * with no matching unfreeze would strand a re-instated student exactly as
     * FRZ-4 described.
     */
    public function test_the_tc_freeze_and_its_reversal_exist_as_a_pair(): void
    {
        $this->assertStringContainsString('freezeFeesOnSoftDelete', $this->body('issue_tc'),
            'issue_tc does not freeze, but cancel_tc unfreezes — the reversal restores '
            . 'a state that was never set');
        $this->assertStringContainsString('unfreezeFeesOnRestore', $this->body('cancel_tc'),
            'issue_tc freezes with nothing to undo it — a cancelled TC would leave the '
            . 'student Active and silently exempt from fees (FRZ-4)');
    }

    public function test_the_tc_fee_book_call_cannot_fail_the_certificate(): void
    {
        // A Transfer Certificate is a legal document the family leaves with.
        // It must not fail to issue because one demand could not be re-stamped.
        $body = $this->body('issue_tc');
        $at   = strpos($body, 'freezeFeesOnSoftDelete');
        $this->assertNotFalse($at);
        $window = substr($body, max(0, $at - 200), 500);
        $this->assertStringContainsString('try', $window, 'the fee-book call is not guarded');
        $this->assertStringContainsString('Throwable', $window,
            'catch \Exception misses an Error from a missing collaborator (FEE-W1)');
    }

    public function test_the_tc_freeze_happens_after_the_certificate_is_recorded(): void
    {
        // Ordering matches the withdrawal side: the exit is recorded first, so
        // a fee-book problem can never leave a TC half-issued.
        $body    = $this->body('issue_tc');
        $writeAt = strpos($body, "updateEntity('students'");
        $freezeAt = strpos($body, 'freezeFeesOnSoftDelete');
        $this->assertNotFalse($writeAt, 'the TC status write moved');
        $this->assertNotFalse($freezeAt);
        $this->assertGreaterThan($writeAt, $freezeAt,
            'the fee book is frozen before the TC is recorded — a failure between the two '
            . 'would freeze the book of a student whose TC was never issued');
    }

    public function test_only_the_two_known_states_are_accepted(): void
    {
        // An unlisted status would fall into the else branch and UNFREEZE, so
        // the allowlist is load-bearing for the fee book, not just for data
        // hygiene.
        $body = $this->body('change_status');
        $this->assertMatchesRegularExpression(
            "/in_array\(\s*\\\$newStatus\s*,\s*\['Active',\s*'Inactive'\]\s*,\s*true\s*\)/",
            $body,
            'the status allowlist changed — an unknown status now decides a fee-book action'
        );
    }
}
