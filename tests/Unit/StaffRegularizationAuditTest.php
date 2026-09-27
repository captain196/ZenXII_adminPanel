<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Staff GPS-attendance regularization, audited end to end 2026-08-23.
 *
 * THE WORKFLOW, and why each link matters:
 *
 *   a teacher's GPS punch fails or is missed
 *     → Teacher app writes attendanceRegularizations/{school}_{staff}_{date}
 *       (status=pending, requestedStatus ∈ {P, M}, one doc per date, one batchId)
 *     → admin lists the queue          Attendance::staff_regularization_list()
 *     → admin decides                  Attendance::staff_regularization_decide()
 *     → approval stamps the day        Staff_attendance_writer::markSingleDay()
 *     → the day feeds the月 summary and therefore PAYROLL
 *
 * Because the last step is money, the guards on the middle steps are not
 * cosmetic. These tests pin the ones this audit added or repaired.
 */
final class StaffRegularizationAuditTest extends TestCase
{
    private static string $att  = '';
    private static string $view = '';

    public static function setUpBeforeClass(): void
    {
        self::$att  = (string) file_get_contents(__DIR__ . '/../../application/controllers/Attendance.php');
        self::$view = (string) file_get_contents(__DIR__ . '/../../application/views/attendance/staff_regularization.php');
    }

    public static function tearDownAfterClass(): void
    {
        self::$att = '';
        self::$view = '';
    }

    /**
     * The body of staff_regularization_decide(), bounded at the next method.
     *
     * Never a fixed character count: a magic window shrinks its own coverage
     * every time the method grows, then fails on code that never changed.
     */
    private function decideBody(): string
    {
        $i = strpos(self::$att, 'function staff_regularization_decide');
        $this->assertNotFalse($i, 'the decide endpoint must still exist');

        $next = [];
        foreach (['    private function ', '    public function ', '    protected function '] as $m) {
            $at = strpos(self::$att, $m, (int) $i + 40);
            if ($at !== false) { $next[] = $at; }
        }
        $this->assertNotEmpty($next);

        $body = substr(self::$att, (int) $i, min($next) - (int) $i);
        $this->assertStringContainsString('$decision', $body, 'slice sanity');
        return $body;
    }

    // ─────────────────────────────────────────────────────────────────────
    //  CONCURRENCY — two reviewers, one queue
    // ─────────────────────────────────────────────────────────────────────

    /**
     * DEFECT FIXED. The endpoint read the request, tested `status == pending`,
     * wrote the attendance day, and only then marked the request decided — with
     * an unguarded merge. Two reviewers working the queue at once (a Principal
     * and an HR Manager is the ordinary case, not an exotic one) both passed the
     * pending test and both proceeded: the day was stamped twice, two audit rows
     * were written, and if they picked different marks the request recorded one
     * mark while the register showed the other.
     *
     * Now the request is CLAIMED by compare-and-swap first, against the version
     * observed by the same read that judged it pending.
     */
    public function test_a_decision_is_claimed_by_compare_and_swap(): void
    {
        $body = $this->decideBody();

        $this->assertStringContainsString('$version = (string) ($fresh[\'__updateTime\'] ?? \'\');', $body,
            'the CAS version must come from the read that judged the request pending');
        $this->assertStringContainsString('$this->_reg_claim($rid,', $body);

        // And the claim helper really uses a precondition, not a blind merge.
        $this->assertStringContainsString("'precondition' => ['updateTime' => \$updateTime],", self::$att);
        $this->assertStringContainsString('PRECONDITION_CONFLICT', self::$att);
    }

    /**
     * Ordering is the whole point. Claiming AFTER the register write would leave
     * the race exactly as it was — both reviewers would already have written.
     */
    public function test_the_claim_happens_before_the_register_is_written(): void
    {
        $body = $this->decideBody();

        $approveClaim = strpos($body, "'status'      => 'approved',");
        $write        = strpos($body, 'markSingleDay(');

        $this->assertNotFalse($approveClaim);
        $this->assertNotFalse($write);
        $this->assertLessThan($write, $approveClaim,
            'the request must be claimed before the attendance day is stamped');
    }

    /**
     * A claim that is not followed by a successful write must be RELEASED.
     *
     * Otherwise claiming-first trades one bug for a worse one: the request would
     * read "approved" over a day that was never corrected — the reviewer sees a
     * decided request, the employee sees an uncorrected register, and nothing
     * reports the divergence.
     */
    public function test_a_failed_register_write_releases_the_claim(): void
    {
        $body = $this->decideBody();

        $this->assertStringContainsString('$this->_reg_release($rid, \'month_locked\');', $body,
            'a locked month must put the request back in the queue');
        $this->assertStringContainsString('$this->_reg_release($rid, \'write_failed\');', $body,
            'a failed write must put the request back in the queue');

        // The release restores a genuinely actionable state, not just the status.
        $release = strpos(self::$att, 'private function _reg_release');
        $this->assertNotFalse($release);
        $chunk = substr(self::$att, (int) $release, 1400);
        foreach (["'status'      => 'pending'", "'appliedMark' => ''", "'reviewedBy'  => ''"] as $field) {
            $this->assertStringContainsString($field, $chunk);
        }
    }

    /** A conflict is reported as its own reason, not lumped in with failures. */
    public function test_a_lost_race_is_reported_distinctly(): void
    {
        $body = $this->decideBody();

        $this->assertStringContainsString("'concurrently_decided'", $body,
            'losing the race is a different event from a failed write and the '
            . 'reviewer needs to be told which happened');
    }

    // ─────────────────────────────────────────────────────────────────────
    //  SELF-APPROVAL
    // ─────────────────────────────────────────────────────────────────────

    /**
     * A reviewer must not approve their own missed-punch request: that is
     * self-service payroll fraud with a clean audit naming them as both subject
     * and approver.
     *
     * The comparison is uid to uid, and that is only sound because BOTH sides
     * are the Firebase uid — the Firestore create rule pins
     * `staffId == request.auth.uid`, and the GPS punch path derives the same
     * identity from the token (`staff/{schoolId}_{uid}`). Verified, not assumed.
     */
    public function test_self_approval_is_refused(): void
    {
        $body = $this->decideBody();

        $this->assertStringContainsString('if ($staffId !== \'\' && $staffId === $userId) {', $body);
        $this->assertStringContainsString("'self_approval_forbidden'", $body);

        $guard = strpos($body, '$staffId === $userId');
        $write = strpos($body, 'markSingleDay(');
        $this->assertLessThan($write, $guard, 'the guard must precede the write it guards');
    }

    /**
     * DEFECT FIXED. The refusal was silent — no audit row, only a skip code on
     * the response. The single event a reviewer would most want to hide left no
     * trace anywhere. A blocked attempt is exactly what an audit trail is for.
     */
    public function test_a_refused_self_approval_is_audited(): void
    {
        $body = $this->decideBody();

        $this->assertStringContainsString('STAFF_REG_SELF_APPROVAL_BLOCKED', $body);

        $audit = strpos($body, 'STAFF_REG_SELF_APPROVAL_BLOCKED');
        $skip  = strpos($body, "'self_approval_forbidden'");
        $this->assertNotFalse($audit);
        $this->assertLessThan($skip, $audit, 'the attempt is recorded before it is skipped');
    }

    /** Rejecting your own request is harmless and stays allowed. */
    public function test_self_rejection_is_still_permitted(): void
    {
        $body = $this->decideBody();

        $reject = strpos($body, "if (\$decision === 'reject') {");
        $guard  = strpos($body, '$staffId === $userId');
        $this->assertNotFalse($reject);
        $this->assertLessThan($guard, $reject,
            'the reject branch returns before the self-approval guard is reached');
    }

    // ─────────────────────────────────────────────────────────────────────
    //  THE ADMIN CAN TELL WHAT HAPPENED
    // ─────────────────────────────────────────────────────────────────────

    /**
     * DEFECT FIXED, and the one that decides whether this screen is usable
     * without a developer.
     *
     * The endpoint answers in codes — month_locked, self_approval_forbidden,
     * write_failed. The page threw them away and toasted "Approved 0 day(s),
     * 1 skipped". An administrator could not tell a locked month from a blocked
     * self-approval, and had no next move except to ask someone.
     *
     * This is a CONTRACT test: every reason the server can emit must have an
     * explanation in the page. Add a reason without a sentence and this fails.
     */
    public function test_every_skip_reason_is_explained_to_the_administrator(): void
    {
        $emitted = $this->emittedReasons();

        $this->assertNotEmpty($emitted, 'the endpoint must still emit skip reasons');

        // Spot-checks that this scan really reaches all THREE shapes a reason can
        // take. An earlier version matched only `'reason' => '<literal>'`, which
        // silently missed both the ternaries and the payroll guard's returns —
        // it certified a vocabulary it could not see.
        foreach (['month_locked', 'concurrently_decided', 'payroll_locked'] as $shape) {
            $this->assertContains($shape, $emitted,
                "the scan no longer reaches the '{$shape}' emission shape");
        }

        foreach ($emitted as $code) {
            $this->assertMatchesRegularExpression(
                '~\b' . preg_quote($code, '~') . '\s*:~',
                self::$view,
                "the reason '{$code}' reaches the administrator with no explanation — "
                . 'add it to SKIP_REASONS in staff_regularization.php'
            );
        }
    }

    /** An unknown code still surfaces rather than vanishing. */
    public function test_an_unrecognised_reason_is_not_swallowed(): void
    {
        $this->assertStringContainsString("SKIP_REASONS[code] || (", self::$view,
            'a reason with no sentence must fall back to showing the raw code');
    }

    /**
     * DEFECT FIXED. When nothing was applied, the page reported success. A
     * refusal is a failure however the transport dressed it up, and the
     * explanation must persist rather than ride away on a toast.
     */
    public function test_a_wholly_refused_decision_does_not_report_success(): void
    {
        $this->assertStringContainsString('if (applied === 0 && reasons.length){', self::$view);

        $i = strpos(self::$view, 'if (applied === 0 && reasons.length){');
        $this->assertNotFalse($i);
        $branch = substr(self::$view, (int) $i, 420);

        $this->assertStringContainsString('showNotice(reasons, false)', $branch,
            'the reasons must be shown in a persistent notice');
        $this->assertStringNotContainsString('toast(', $branch,
            'a refusal must not be announced as a success toast');
        $this->assertStringContainsString('restoreBtns();', $branch,
            'the buttons must come back so the reviewer can act on the advice');
    }

    /** The reject path reports its real outcome too, not a fixed sentence. */
    public function test_rejection_reports_its_actual_outcome(): void
    {
        $i = strpos(self::$view, "post(DECIDE_URL");
        $this->assertNotFalse($i);
        $handler = substr(self::$view, (int) $i, 1800);

        $this->assertStringContainsString('reasons.length', $handler,
            'reject must consult the skipped list rather than always claiming success');
    }

    /**
     * DEFECT FIXED. The "Apply as" default read "Present" but sends an empty
     * value, which makes the server apply what the EMPLOYEE asked for. The
     * Firestore rule permits P or M, so a half-day request would be stamped as a
     * half-day while the reviewer read the control as saying Present — a
     * mislabel with pay attached.
     */
    public function test_the_mark_selector_does_not_mislabel_its_default(): void
    {
        $this->assertStringNotContainsString('<option value="">Present</option>', self::$view,
            'the empty value applies the requested mark, which may be a half-day');
        $this->assertStringContainsString('<option value="">As requested</option>', self::$view);
        $this->assertStringContainsString('<option value="P">Present (P)</option>', self::$view,
            'a reviewer who wants Present specifically must be able to say so');
    }

    // ─────────────────────────────────────────────────────────────────────
    //  THE PAYROLL PERIOD
    // ─────────────────────────────────────────────────────────────────────

    /**
     * DEFECT FIXED. Approving a regularization could rewrite an attendance
     * month whose PAYROLL was already finalised and locked.
     *
     * There are two independent locks. Staff_attendance_writer checks
     * `staffAttendanceLocks`. Payroll writes `schools.payrollLocks.{YYYY}_{Month}`
     * from Hr::lock_payroll_month(). Nothing connected them, so locking August's
     * payroll did not stop August's register from moving — and the documented
     * hazard (payslip disagreeing with the attendance it was computed from, with
     * nothing downstream detecting it) was prevented only if an administrator
     * remembered to throw a second lock by hand.
     */
    public function test_a_locked_payroll_month_cannot_be_rewritten(): void
    {
        $body = $this->decideBody();

        $this->assertStringContainsString('$payLock = $this->_payroll_month_locked($date);', $body);
        $this->assertStringContainsString('private function _payroll_month_locked', self::$att);

        // It reads the lock payroll actually writes.
        $i = strpos(self::$att, 'private function _payroll_month_locked');
        $guard = substr(self::$att, (int) $i, 2600);
        $this->assertStringContainsString("\$doc['payrollLocks']", $guard);
        $this->assertStringContainsString("date('Y', \$ts) . '_' . date('F', \$ts)", $guard,
            'the key must match the {YYYY}_{MonthName} form Hr::lock_payroll_month writes');
    }

    /** The guard refuses BEFORE claiming, so a refusal costs no claim churn. */
    public function test_the_payroll_guard_runs_before_the_claim(): void
    {
        $body = $this->decideBody();

        $guard = strpos($body, '$payLock = $this->_payroll_month_locked');
        $claim = strpos($body, "'status'      => 'approved',");

        $this->assertNotFalse($guard);
        $this->assertNotFalse($claim);
        $this->assertLessThan($claim, $guard);
    }

    /**
     * An unreadable payroll lock REFUSES the write.
     *
     * This matches the deliberate stance already taken for the attendance month
     * lock, where Lock_cache rethrows rather than returning an empty array so an
     * infrastructure hiccup can never be read as "unlocked". Fail-open here
     * would turn a transient Firestore error into a silent payroll discrepancy.
     */
    public function test_an_unreadable_payroll_lock_fails_closed(): void
    {
        $i = strpos(self::$att, 'private function _payroll_month_locked');
        $guard = substr(self::$att, (int) $i, 2600);

        $this->assertStringContainsString("return 'payroll_lock_unreadable';", $guard);
        $this->assertStringContainsString('$this->_payrollLocks = false;', $guard,
            'an unreadable lock must be distinguishable from "no locks set"');
    }

    // ─────────────────────────────────────────────────────────────────────
    //  THE QUEUE THE REVIEWER SEES
    // ─────────────────────────────────────────────────────────────────────

    /**
     * DEFECT FIXED. The Approvals badge counted DAYS while the screen behind it
     * counted REQUESTS, so the badge said 3 where the reviewer then found a
     * single three-day request.
     */
    public function test_the_approvals_badge_counts_what_the_screen_shows(): void
    {
        $i = strpos(self::$att, "setBadge('regularization'");
        $this->assertNotFalse($i, 'the regularization badge must still be wired');

        $chunk = substr(self::$att, max(0, (int) $i - 600), 700);
        $this->assertStringContainsString('r.batches ? Object.keys(r.batches).length', $chunk,
            'the badge must count requests (batches), matching the cards on the page');
    }

    /** Review remarks are bounded — they are stored and echoed to the employee. */
    public function test_remarks_are_length_capped(): void
    {
        $body = $this->decideBody();

        $this->assertStringContainsString(
            "mb_substr(trim((string) \$this->input->post('remarks')), 0, 500)", $body,
            'unbounded free text on a stored document is a write-failure waiting to happen');

        $this->assertStringContainsString('maxlength="500"', self::$view,
            'the field must stop the reviewer at the same limit the server enforces');
    }

    // ─────────────────────────────────────────────────────────────────────
    //  TENANT ISOLATION
    // ─────────────────────────────────────────────────────────────────────

    /** Every read is school-scoped, and each row re-checks its own tenant. */
    public function test_tenant_isolation_holds_on_both_paths(): void
    {
        $body = $this->decideBody();

        $this->assertStringContainsString(
            "(string) (\$d['schoolId'] ?? '') !== \$this->school_id", $body,
            'a row from another tenant must be refused even if its id was guessed');

        $list = strpos(self::$att, 'function staff_regularization_list');
        $this->assertNotFalse($list);
        $listBody = substr(self::$att, (int) $list, 2000);
        $this->assertStringContainsString("schoolWhere('attendanceRegularizations'", $listBody,
            'the queue must be read through the tenant-scoping helper, never a raw query');

        $this->assertStringContainsString("schoolWhere('attendanceRegularizations', [", $body,
            'the batch lookup must be tenant-scoped too');
    }

    /** A cross-tenant row is reported as not_found, never as "denied". */
    public function test_a_foreign_row_is_indistinguishable_from_a_missing_one(): void
    {
        $body = $this->decideBody();

        $i = strpos($body, "(string) (\$d['schoolId'] ?? '') !== \$this->school_id");
        $this->assertNotFalse($i);
        $this->assertStringContainsString("'not_found'", substr($body, (int) $i, 200),
            'confirming that a foreign id EXISTS is itself a leak');
    }

    // ─────────────────────────────────────────────────────────────────────
    //  STATUS VOCABULARY AND THE APP CONTRACT
    // ─────────────────────────────────────────────────────────────────────

    /**
     * The mark written to the register must stay inside the vocabulary payroll
     * has a disposition for — a regularization must never invent a mark.
     */
    public function test_the_applied_mark_stays_in_the_staff_vocabulary(): void
    {
        $body = $this->decideBody();

        $this->assertStringContainsString('in_array($reqMark, $staffStatus, true)', $body);
        $this->assertStringContainsString('$overrideOk', $body);
        $this->assertStringContainsString(
            "\$staffStatus = ['P', 'A', 'L', 'H', 'T', 'V', 'M', 'W', 'O'];", self::$att);
    }

    /**
     * The fields the Teacher app reads back must all be written. The app's
     * RegularizationDoc declares status, reviewedBy, remarks and appliedMark;
     * a decision that omitted one would leave the employee's history blank with
     * no error anywhere.
     */
    public function test_the_decision_writes_every_field_the_app_reads_back(): void
    {
        $body = $this->decideBody();

        foreach (["'status'", "'reviewedBy'", "'reviewedAt'", "'remarks'", "'appliedMark'"] as $field) {
            $this->assertStringContainsString($field, $body,
                "the Teacher app reads {$field} back from the request document");
        }
    }

    /** The write records where the correction came from, for the audit trail. */
    public function test_the_register_write_records_its_provenance(): void
    {
        $body = $this->decideBody();

        $this->assertStringContainsString("'source'       => 'correction',", $body);
        $this->assertStringContainsString("'correctionId' => \$rid,", $body);
        $this->assertStringContainsString("'markedBy'     => 'admin:' . \$userId,", $body);
    }

    /** Both outcomes are audited, with the day and the mark that landed. */
    public function test_both_decisions_are_audited(): void
    {
        $body = $this->decideBody();

        $this->assertStringContainsString('STAFF_REG_APPROVE', $body);
        $this->assertStringContainsString('STAFF_REG_REJECT', $body);
        $this->assertStringContainsString("'newValue' => \$finalMark", $body,
            'the audit must record the mark that was actually applied');
    }

    /**
     * Every reason code the decide endpoint can put in front of an administrator.
     *
     * A reason reaches `skipped[]` in three different shapes, and a scan that
     * handles only one certifies a vocabulary it cannot see:
     *
     *   1. inline          ['reason' => 'month_locked']
     *   2. a ternary       ['reason' => $claim === 'CONFLICT' ? 'a' : 'b']
     *   3. from a helper   ['reason' => $payLock]   ← _payroll_month_locked()
     *
     * So: pull every `$skipped[] = [...]` expression and take all the quoted
     * codes inside it (shapes 1 and 2), then add the guard's returns (shape 3).
     */
    private function emittedReasons(): array
    {
        $body = $this->decideBody();

        preg_match_all('~\$skipped\[\] = \[.*?\];~s', $body, $rows);
        $this->assertNotEmpty($rows[0], 'the endpoint must still emit skip reasons');

        // Read ONLY the value of `reason`, not every literal in the row. A skip
        // row may carry other fields (a school-supplied message, an array
        // lookup), and harvesting those as reason codes made this test demand
        // an explanation for 'limitMessage'.
        $codes = [];
        foreach ($rows[0] as $row) {
            if (!preg_match("~'reason'\s*=>(.*?)(?:,\s*'|\s*\];)~s", $row, $m)) {
                continue;
            }
            preg_match_all("~'([a-z_]+)'~", $m[1], $lits);
            foreach ($lits[1] as $lit) {
                $codes[] = $lit;
            }
        }

        return array_values(array_unique(array_merge(
            $codes,
            $this->helperReasons('_payroll_month_locked'),
            $this->helperReasons('_regularization_over_limit')
        )));
    }

    /** Reason codes a guard RETURNS rather than writing inline at the skip site. */
    private function helperReasons(string $guard): array
    {
        $i = strpos(self::$att, 'private function ' . $guard);
        $this->assertNotFalse($i, "the guard {$guard} must still exist");

        $j = strpos(self::$att, '    /**', (int) $i);
        $body = substr(self::$att, (int) $i, ($j === false ? 2600 : $j - (int) $i));

        // Every quoted code in a return statement — the guard returns some
        // plainly and one through a ternary, and matching only `return 'x';`
        // missed exactly the code that matters most (payroll_locked).
        preg_match_all('~return\s+[^;]*;~', $body, $returns);
        $this->assertNotEmpty($returns[0], 'the guard must return reason codes');

        $codes = [];
        foreach ($returns[0] as $r) {
            preg_match_all("~'([a-z_]+)'~", $r, $lits);
            foreach ($lits[1] as $lit) {
                if ($lit === 'locked') { continue; }   // an array key it reads, not a code
                $codes[] = $lit;
            }
        }

        $this->assertNotEmpty($codes);
        return array_values(array_unique($codes));
    }

    /**
     * Every skip reason must come from somewhere this test can SEE — either
     * an inline literal or the payroll guard. A third source would silently
     * escape the vocabulary contract above, which is exactly how the payroll
     * codes slipped through the first time.
     */
    public function test_no_skip_reason_escapes_the_vocabulary_check(): void
    {
        $body = $this->decideBody();

        // Which variables carry a reason into skipped[]? Only $payLock may,
        // because only its source is scanned. $claim is fine — it selects
        // between two literals that the scan reads directly.
        preg_match_all('~\$skipped\[\] = \[.*?\];~s', $body, $rows);

        $carriers = [];
        foreach ($rows[0] as $row) {
            if (preg_match('~\'reason\'\s*=> (\$[A-Za-z_]+)\s*[,\]]~', $row, $m)) {
                $carriers[] = $m[1];
            }
        }
        $carriers = array_values(array_unique($carriers));

        sort($carriers);
        $this->assertSame(['$overLimit', '$payLock'], $carriers,
            'a skip reason is reaching the administrator from a source this suite '
            . 'does not scan — extend emittedReasons() before adding it');
    }
}
