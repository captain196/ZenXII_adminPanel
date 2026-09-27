<?php
namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * ZenX HRMS P0 — Attendance → Payroll status CONTRACT test.
 *
 * WHY THIS EXISTS
 * ---------------
 * The current Attendance writer emits nine day-status characters. Legacy Payroll
 * parsed only three (A/L/V), so 'M' (half-day) silently fell through and was paid
 * as a FULL day, and 'W' (worked-on-off) — a status that exists specifically to
 * trigger extra pay — was ignored entirely. Both were live money defects, and
 * neither produced any error: the parser's if/elseif chain simply had no branch,
 * so the day was treated as ordinary paid attendance.
 *
 * That failure mode is silent and recurs every time Attendance's vocabulary grows.
 * This test makes it loud: the writer's ALLOWED_STATUS set is pinned here, so
 * ADDING A NEW STATUS CHARACTER TO THE ATTENDANCE WRITER FAILS THIS TEST until
 * someone makes an explicit payroll decision about it and records that decision
 * below.
 *
 * TESTING APPROACH
 * ----------------
 * Source-level assertions, following this repo's established convention for
 * Firestore-backed controllers (see HomeworkSchoolIdPredicateTest). Behavioural
 * execution of Hr::_compute_payslip() / generate_payroll() is not achievable in
 * this suite: they are private/controller methods requiring a full CodeIgniter
 * bootstrap plus a live Firestore_service, and the repo's FirebaseMock models the
 * RTDB-shaped Firebase class, not Firestore_service. Comments are stripped before
 * matching so documentation can never satisfy an assertion about real code.
 */
class AttendancePayrollStatusContractTest extends TestCase
{
    /**
     * The pinned contract. Key = status char emitted by
     * Staff_attendance_writer::ALLOWED_STATUS. Value = whether Payroll must carry
     * an EXPLICIT `$ch === '<char>'` branch for it.
     *
     * false does NOT mean "ignored by accident" — it means the character has been
     * consciously determined to have no pay effect:
     *   P present  → ordinary paid day (the default, no branch needed)
     *   H holiday  → non-working; derived by _staff_working_days() from the holiday
     *                calendar, so branching here would double-count
     *   T late     → paid in full; lateness carries no pay penalty in this engine
     *   O weeklyOff→ non-working; derived from the weekly-off policy, same
     *                double-count reasoning as 'H'
     */
    private const CONTRACT = [
        'P' => false,
        'A' => true,   // absent            → full-day LOP
        'L' => true,   // leave             → paid / unpaid split
        'H' => false,
        'T' => false,
        'V' => true,   // vacant (unmarked) → vacant_treatment policy
        'M' => true,   // half-day          → 0.5 LOP   (P0-3/A1)
        'W' => true,   // worked-on-off     → extra pay (P0-4/A2)
        'O' => false,
    ];

    private string $writerSrc;
    private string $hrSrc;

    protected function setUp(): void
    {
        $this->writerSrc = $this->readCodeOnly(
            __DIR__ . '/../../application/libraries/Staff_attendance_writer.php'
        );
        $this->hrSrc = $this->readCodeOnly(
            __DIR__ . '/../../application/controllers/Hr.php'
        );
    }

    /** Read a PHP file with all comments stripped, so assertions measure real code. */
    private function readCodeOnly(string $path): string
    {
        $raw = file_get_contents($path);
        $this->assertNotFalse($raw, "Could not read {$path}");

        $out = '';
        foreach (token_get_all($raw) as $token) {
            if (is_array($token)) {
                if ($token[0] === T_COMMENT || $token[0] === T_DOC_COMMENT) continue;
                $out .= $token[1];
            } else {
                $out .= $token;
            }
        }
        return $out;
    }

    /** Extract ALLOWED_STATUS from the writer's own source — the authority. */
    private function writerStatuses(): array
    {
        $ok = preg_match(
            '/const\s+ALLOWED_STATUS\s*=\s*\[(.*?)\]\s*;/s',
            $this->writerSrc,
            $m
        );
        $this->assertSame(1, $ok, 'Could not locate ALLOWED_STATUS in Staff_attendance_writer.');

        preg_match_all("/'([A-Z])'/", $m[1], $chars);
        $found = $chars[1];
        $this->assertNotEmpty($found, 'ALLOWED_STATUS parsed as empty.');
        return $found;
    }

    /**
     * THE GUARD. If Attendance adds (or removes) a status character, this fails —
     * forcing an explicit payroll decision instead of a silent mispayment.
     */
    public function test_attendance_status_vocabulary_matches_payroll_contract(): void
    {
        $writer = $this->writerStatuses();
        sort($writer);
        $pinned = array_keys(self::CONTRACT);
        sort($pinned);

        $this->assertSame(
            $pinned,
            $writer,
            "Attendance's ALLOWED_STATUS no longer matches the payroll contract pinned in "
            . self::class . "::CONTRACT.\n"
            . "A status character was added or removed. Payroll's dayWise parsers in "
            . "Hr.php (generate_payroll + regenerate_staff_payroll) do NOT fail on an "
            . "unknown character — the day silently becomes an ordinary PAID day. "
            . "Decide the pay treatment for the changed character, implement it in BOTH "
            . "parsers, then update CONTRACT here."
        );
    }

    /**
     * Every pay-affecting status must be branched on in BOTH parsers.
     * Two parsers exist (generate_payroll and regenerate_staff_payroll) and the
     * codebase contractually requires them to produce identical numbers, so a
     * branch present in only one is drift.
     */
    public function test_pay_affecting_statuses_are_handled_in_both_parsers(): void
    {
        foreach (self::CONTRACT as $char => $needsBranch) {
            if (!$needsBranch) continue;

            $count = substr_count($this->hrSrc, "\$ch === '{$char}'");
            $this->assertGreaterThanOrEqual(
                2,
                $count,
                "Attendance status '{$char}' affects pay but has {$count} branch(es) in Hr.php; "
                . "expected >= 2 (generate_payroll AND regenerate_staff_payroll). "
                . "A status handled in only one parser makes payroll regeneration "
                . "produce different numbers from the original run."
            );
        }
    }

    /**
     * P0-3/A1 regression: 'M' must contribute half a day, and must be guarded
     * against double-counting when leave already covers the same day.
     */
    public function test_half_day_contributes_half_and_is_leave_guarded(): void
    {
        $this->assertStringContainsString(
            // Calendar Half-Day S1 (authorized 2026-08-19): 'M' now charges
            // half of THAT DAY's expected work rather than a hardcoded 0.5, so
            // a half-day mark on a school-declared half-day leaves 0.25
            // uncovered instead of 0.5. With the feature flag OFF $dayExpected
            // is 1.0 and this evaluates to exactly 0.5 — the original
            // behaviour. The contract being protected here ("an attendance
            // half-day must cost half a day, not be paid as a full one") is
            // unchanged. See CalendarHalfDayMatrixTest scenarios B and F.
            // CONSOLIDATED 2026-08-19: the charge and the leave-coverage guard
            // are now one expression sharing _leave_coverage_for_day(). With no
            // leave on the day $dayCoverage is 0.0 and this is exactly
            // $dayExpected * 0.5 — and with the flag OFF $dayExpected is 1.0,
            // giving the original 0.5. The contract protected here ("an
            // attendance half-day must cost half a day, not be paid as a full
            // one") is unchanged.
            '$daysAbsent += max(0.0, ($dayExpected * 0.5) - $dayCoverage);',
            $this->hrSrc,
            "Attendance half-day ('M') must add half a day to daysAbsent — without it a half-day is paid as a full day."
        );

        // Both parsers must gate the 0.5 on "leave does not already cover this day".
        //
        // Half-Day programme (2026-08-19): the 'M' guards were STRENGTHENED to
        // honour APPROVED PAID leave coverage in addition to LWP. Previously
        // they checked only the LWP set, so a paid half-day leave — which
        // produces no LWP and deliberately writes no attendance mark — left
        // the 'M' unguarded and the employee was docked 0.5 day despite
        // approved leave. This assertion therefore counts BOTH guard shapes:
        // the 'A' branches keep the LWP-only form, the 'M' branches now carry
        // the extended form. The original intent — "never charge a half day
        // that leave already covers" — is unchanged and now strictly wider.
        // See HalfDayReconciliationTest for the behavioural proof.
        // CONSOLIDATED 2026-08-19. The per-branch guards became one shared rule,
        // _leave_coverage_for_day(), computed once per day and subtracted by
        // every branch that charges. The intent asserted here — "never charge
        // work that approved leave already covers", symmetrically in both
        // engines — is unchanged and now strictly WIDER: it also covers 'V',
        // the unmarked-day fill, which honoured no coverage at all and was
        // therefore charging approved half-day leave in full and charging a
        // full-day unpaid leave twice.
        $sharedRule = substr_count($this->hrSrc, 'self::_leave_coverage_for_day(');

        $this->assertSame(
            2,
            $sharedRule,
            "Expected the shared leave-coverage rule in BOTH engines (one per parser); found {$sharedRule}."
        );

        // And every charging branch must actually subtract it.
        foreach ([
            '$daysAbsent += max(0.0, $dayExpected - $dayCoverage);'          => "'A' and 'V'",
            '$daysAbsent += max(0.0, ($dayExpected * 0.5) - $dayCoverage);'  => "'M'",
        ] as $expr => $which) {
            $this->assertGreaterThanOrEqual(
                2,
                substr_count($this->hrSrc, $expr),
                "Both parsers must subtract leave coverage on {$which}."
            );
        }
    }

    /**
     * P0-4/A2 regression: 'W' must feed a real earning through the canonical
     * calculator, not merely be counted.
     */
    public function test_worked_on_off_feeds_the_canonical_calculator(): void
    {
        $this->assertStringContainsString(
            '$extraWorkedDays += 1.0;',
            $this->hrSrc,
            "Attendance 'W' must accumulate extraWorkedDays."
        );
        // Assert the BEHAVIOUR (extra pay is priced inside the canonical
        // calculator, from the exact basic-pay base and the configured rate),
        // NOT a literal arithmetic expression. The original version of this
        // assertion pinned the pre-W1.2 float expression
        // `round($dailySalary * $extraWorkedDays * $extraWorkedRate, 2)` and
        // therefore broke when W1.2 legitimately converted the engine to
        // integer paise — a test asserting an implementation detail rather than
        // a contract.
        $this->assertMatchesRegularExpression(
            '/\$extraWorkedPayP?\s*=.*\$extraWorkedDays\s*\*\s*\$extraWorkedRate/s',
            $this->hrSrc,
            "Extra-worked days must be priced inside _compute_payslip() — the canonical calculator — "
            . "from extraWorkedDays × extraWorkedRate, not in a separate payroll path."
        );
        $this->assertStringContainsString(
            "'extra_worked_pay'",
            $this->hrSrc,
            "extra_worked_pay must be returned by the calculator and stored on the payslip."
        );

        // Priced in both callers, so regenerate matches generate.
        $this->assertGreaterThanOrEqual(
            2,
            substr_count($this->hrSrc, '(float) $extraWorkedDays'),
            "Both _compute_payslip() call sites (generate + regenerate) must pass extraWorkedDays."
        );
    }
}
