<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

if (!defined('BASEPATH')) define('BASEPATH', __DIR__);
require_once __DIR__ . '/../../application/helpers/payroll_snapshot_helper.php';
require_once __DIR__ . '/../../application/helpers/payroll_trace_helper.php';

/**
 * ZenX HRMS W3.2.4 — payroll calculation trace.
 *
 * THE CENTRAL PROPERTY
 * --------------------
 * A trace that disagrees with the payslip it explains is worse than no trace:
 * it gives an auditor a confident wrong answer. So the assertions here are
 * mostly about AGREEMENT — at exact integer paise, with no tolerance, because a
 * tolerance would hide precisely the one-paisa drift worth catching.
 *
 * The trace is a pure function over a stored payslip, which is what makes this
 * testable at all: no Firestore, no live configuration, no recomputation.
 */
class PayrollCalculationTraceTest extends TestCase
{
    /**
     * A realistic frozen payslip. The paise figures are internally consistent
     * the way the engine produces them: gross == net + deductions, and the
     * deduction components sum to the total.
     *
     * Basic 28,500.00 · HRA 10,000.00 · gross 38,500.00
     * PF 12% of basic = 3,420.00 · ESI 0.75% of gross = 288.75
     * PT 200.00 · total deductions 3,908.75 · net 34,591.25
     */
    private function slip(array $over = []): array
    {
        return array_merge([
            'staff_id'   => 'STA0001',
            'staff_name' => 'Rahul Sharma',
            'department' => 'Science',
            'month'      => 'July',
            'year'       => '2026',
            'status'     => 'Draft',

            'working_days'    => 26,
            'days_worked'     => 24,
            'days_absent'     => 2,
            'half_days'       => 0,
            'paid_leave_days' => 0,
            'lwp_days'        => 2,
            'lwp_deduction'   => 2000.00,
            'daily_salary'    => 1000.00,
            'deduction_days'  => 2,

            'basic'            => 28500.00,
            'hra'              => 10000.00,
            'gross'            => 38500.00,
            'pf_employee'      => 3420.00,
            'esi_employee'     => 288.75,
            'tds'              => 0.00,
            'professional_tax' => 200.00,
            'other_deductions' => 0.00,
            'total_deductions' => 3908.75,
            'net_pay'          => 34591.25,

            'staff_window' => ['startDay' => 1, 'endDay' => 31, 'joinDate' => '2020-04-01', 'exitDate' => ''],

            '_salary_snapshot' => [
                'basic' => 30000.00, 'hra' => 10000.00, 'da' => 0.0, 'ta' => 0.0,
                'medical' => 0.0, 'other_allowances' => 0.0,
                'pf_employee' => 12.0, 'esi_employee' => 0.75,
                'professional_tax' => 200.00, 'tds' => 0.0,
            ],

            '_attendance_snapshot' => [
                'version' => 1, 'month_key' => '2026-07',
                'day_wise'   => 'PPPPPPPPPPPPPPPPPPPPPPPPAAPPPPP',
                'window_mask'=> str_repeat('1', 31),
                'lwp_mask'   => str_repeat('-', 24) . 'FF' . str_repeat('-', 5),
                'vacant_treatment' => 'block',
                'working_days' => 26, 'days_in_month' => 31, 'holiday_days' => [],
                'derived' => ['days_absent' => 2.0, 'half_days' => 0, 'extra_worked_days' => 0.0,
                              'paid_leave_days' => 0, 'unpaid_leave_days' => 2, 'vacant_days' => 0],
            ],

            '_calculation_trace' => [
                'version' => 1, 'engine' => '_compute_payslip',
                'statutory_source' => 'structure',
                'bases' => [
                    'retention_ratio' => 0.95, 'absent_fraction' => 0.05,
                    'pf_base_paise' => 2850000, 'esi_base_paise' => 3850000,
                    'tds_base_paise' => 3850000, 'basic_paise' => 2850000,
                    'pf_employee_pct' => 12.0, 'esi_employee_pct' => 0.75, 'tds_pct' => 0.0,
                    'pf_employer_pct' => 12.0, 'esi_employer_pct' => 3.25,
                ],
                'paise' => [
                    'gross' => 3850000, 'net_pay' => 3459125, 'total_deductions' => 390875,
                    'pf_employee' => 342000, 'pf_employer' => 342000,
                    'esi_employee' => 28875, 'esi_employer' => 125125,
                    'tds' => 0, 'professional_tax' => 20000, 'other_deductions' => 0,
                    'extra_worked_pay' => 0,
                ],
                'deferred' => ['other_deductions' => 0, 'professional_tax' => 0, 'statutory' => 0],
                'warnings' => [],
                'computed_at' => '2026-08-01T10:00:00+05:30',
            ],
        ], $over);
    }

    private function stepByKey(array $trace, string $key): ?array
    {
        foreach ($trace['steps'] as $s) if ($s['key'] === $key) return $s;
        return null;
    }

    // ── 19. Trace consistency — the central property ─────────────────────

    public function test_trace_reconciles_with_the_stored_payslip_at_exact_paise(): void
    {
        $c = payroll_trace_consistency($this->slip());

        $this->assertTrue($c['available']);
        $this->assertTrue($c['consistent'], 'the trace must reconcile with the payslip');
        foreach ($c['checks'] as $chk) {
            $this->assertTrue($chk['passed'], $chk['name'] . ' failed');
            $this->assertSame($chk['expected'], $chk['actual'], $chk['name']);
        }
    }

    public function test_the_w1_2_invariant_holds_on_the_frozen_numbers(): void
    {
        $t = $this->slip()['_calculation_trace']['paise'];
        $this->assertSame($t['gross'], $t['net_pay'] + $t['total_deductions'],
            'gross == net + deductions, at integer paise');
    }

    /**
     * A single paisa of drift MUST be reported. If this passes with a tolerance
     * the whole consistency check is decorative.
     */
    public function test_a_one_paisa_discrepancy_is_detected(): void
    {
        $slip = $this->slip();
        $slip['_calculation_trace']['paise']['net_pay'] += 1;   // one paisa

        $c = payroll_trace_consistency($slip);
        $this->assertFalse($c['consistent'], 'one paisa must break consistency');
    }

    public function test_a_trace_disagreeing_with_the_stored_rupee_figure_is_detected(): void
    {
        $slip = $this->slip(['net_pay' => 34591.26]);   // slip says a paisa more

        $c = payroll_trace_consistency($slip);
        $this->assertFalse($c['consistent'],
            'the trace must not silently explain a different payslip');
    }

    public function test_deduction_components_must_sum_to_the_total(): void
    {
        $slip = $this->slip();
        $slip['_calculation_trace']['paise']['pf_employee'] = 341999;

        $c = payroll_trace_consistency($slip);
        $this->assertFalse($c['consistent']);
    }

    // ── 1–13. The chain ──────────────────────────────────────────────────

    public function test_the_chain_is_ordered_employment_through_employer_cost(): void
    {
        $keys = array_column(payroll_build_trace($this->slip())['steps'], 'key');

        $expected = ['employment', 'compensation', 'attendance', 'proration',
                     'earnings', 'gross', 'statutory', 'other_deductions', 'net', 'employer_cost'];
        foreach ($expected as $k) $this->assertContains($k, $keys, "missing step: {$k}");

        // Order is the explanation. Net must come after the deductions that
        // produced it, and employer cost after net.
        $this->assertGreaterThan(array_search('gross', $keys), array_search('statutory', $keys));
        $this->assertGreaterThan(array_search('statutory', $keys), array_search('net', $keys));
        $this->assertGreaterThan(array_search('net', $keys), array_search('employer_cost', $keys));
    }

    public function test_employment_step_shows_the_window(): void
    {
        $s = $this->stepByKey(payroll_build_trace($this->slip()), 'employment');
        $this->assertSame('Rahul Sharma', $s['facts']['Employee']);
        $this->assertSame('July 2026', $s['facts']['Period']);
    }

    public function test_compensation_step_uses_the_frozen_structure(): void
    {
        $s = $this->stepByKey(payroll_build_trace($this->slip()), 'compensation');
        // Contracted basic is 30,000 — NOT the 28,500 actually earned.
        $this->assertSame(30000.00, $s['facts']['Basic (contracted)']);
        $this->assertSame('frozen salary snapshot', $s['source']);
    }

    public function test_earnings_step_shows_the_earned_not_contracted_amount(): void
    {
        $s = $this->stepByKey(payroll_build_trace($this->slip()), 'earnings');
        $this->assertSame(28500.00, $s['facts']['Basic (earned)'],
            'the earnings step must show what was earned after proration');
    }

    public function test_attendance_step_names_the_lop_days_from_the_frozen_mask(): void
    {
        $s = $this->stepByKey(payroll_build_trace($this->slip()), 'attendance');
        $this->assertSame('25, 26', $s['facts']['LOP days (full)'],
            'day-level LOP must be named, not just counted');
        $this->assertSame('frozen attendance snapshot', $s['source']);
    }

    public function test_proration_step_exposes_the_retention_ratio(): void
    {
        $s = $this->stepByKey(payroll_build_trace($this->slip()), 'proration');
        $this->assertSame(0.95, $s['facts']['Retention ratio']);
        $this->assertSame(0.05, $s['facts']['Absent fraction']);
    }

    /**
     * The asymmetry that is misread most often: PF is a percentage of earned
     * BASIC, ESI and TDS of earned GROSS. Both bases must be stated, or "PF is
     * 12%" is uncheckable.
     */
    public function test_statutory_step_states_both_bases_explicitly(): void
    {
        $s = $this->stepByKey(payroll_build_trace($this->slip()), 'statutory');

        $this->assertSame(28500.00, $s['facts']['PF base (earned basic)']);
        $this->assertSame(38500.00, $s['facts']['ESI base (earned gross)']);
        $this->assertNotSame($s['facts']['PF base (earned basic)'], $s['facts']['ESI base (earned gross)']);

        $this->assertSame(12.0, $s['facts']['PF rate %']);
        $this->assertSame(3420.00, $s['facts']['PF (employee)']);
        // 12% of 28,500 is exactly 3,420 — the stated base and rate reproduce
        // the stated deduction.
        $this->assertSame(round(28500.00 * 12.0 / 100, 2), $s['facts']['PF (employee)']);
    }

    public function test_net_step_matches_the_stored_net(): void
    {
        $s = $this->stepByKey(payroll_build_trace($this->slip()), 'net');
        $this->assertSame(34591.25, $s['facts']['Net pay']);
        $this->assertSame(38500.00, $s['facts']['Gross']);
        $this->assertSame(3908.75, $s['facts']['Total deductions']);
    }

    public function test_employer_cost_does_not_reduce_net(): void
    {
        $t = payroll_build_trace($this->slip());
        $er  = $this->stepByKey($t, 'employer_cost');
        $net = $this->stepByKey($t, 'net');

        $this->assertSame(3420.00, $er['facts']['PF (employer)']);
        $this->assertSame(1251.25, $er['facts']['ESI (employer)']);
        // CTC = gross + employer contributions.
        $this->assertSame(38500.00 + 3420.00 + 1251.25, $er['facts']['Cost to company']);
        // Net is untouched by employer cost.
        $this->assertSame(34591.25, $net['facts']['Net pay']);
    }

    // ── 6. Adjustments ───────────────────────────────────────────────────

    public function test_adjustment_step_shows_original_and_final(): void
    {
        $slip = $this->slip(['_payroll_adjustments' => [
            'version' => 1, 'count' => 1,
            'lines' => [['override_id' => 'OVR_1', 'type' => 'BONUS', 'label' => 'Bonus',
                         'kind' => 'earning', 'amount' => 5000.00, 'prorated' => false]],
            'original_gross' => 38500.00, 'original_net' => 34591.25,
            'final_gross' => 43500.00, 'final_net' => 39216.00,
            'applied_at' => '2026-08-01T11:00:00+05:30', 'applied_by' => 'admin',
        ]]);

        $s = $this->stepByKey(payroll_build_trace($slip), 'adjustments');
        $this->assertNotNull($s);
        $this->assertSame(38500.00, $s['facts']['Gross before adjustments']);
        $this->assertSame(5000.00, $s['facts']['+ Bonus']);
        $this->assertSame(43500.00, $s['facts']['Gross after adjustments']);
        $this->assertStringContainsString('ESI and TDS base', $s['note']);
    }

    public function test_the_adjustment_step_is_absent_when_there_are_none(): void
    {
        $this->assertNull($this->stepByKey(payroll_build_trace($this->slip()), 'adjustments'));
    }

    // ── 15/16. Missing evidence is reported, never invented ──────────────

    public function test_missing_statutory_policy_is_stated_not_faked(): void
    {
        $s = $this->stepByKey(payroll_build_trace($this->slip()), 'statutory');
        $this->assertStringContainsString('No tenant statutory policy is configured', $s['note']);
        $this->assertStringContainsString('no PF ceiling or ESI threshold', $s['note']);
    }

    public function test_statutory_snapshot_is_reported_as_unavailable(): void
    {
        $t = payroll_build_trace($this->slip());
        $this->assertFalse($t['evidence']['statutory_snapshot'],
            'the statutory snapshot dependency must be declared, not implied');
    }

    public function test_a_missing_attendance_snapshot_is_declared_not_reconstructed(): void
    {
        $slip = $this->slip();
        unset($slip['_attendance_snapshot']);

        $t = payroll_build_trace($slip);
        $s = $this->stepByKey($t, 'attendance');

        $this->assertFalse($t['evidence']['attendance_snapshot']);
        $this->assertStringContainsString('not available and is not reconstructed', $s['unavailable']);
        $this->assertArrayNotHasKey('LOP days (full)', $s['facts'],
            'day detail must not be invented when the mask is absent');
    }

    public function test_a_missing_compensation_snapshot_is_declared(): void
    {
        $slip = $this->slip();
        unset($slip['_salary_snapshot']);

        $t = payroll_build_trace($slip);
        $this->assertFalse($t['evidence']['salary_snapshot']);
        $this->assertNotNull($this->stepByKey($t, 'compensation')['unavailable']);
    }

    public function test_a_slip_with_no_trace_reports_unavailable_rather_than_guessing(): void
    {
        $slip = $this->slip();
        unset($slip['_calculation_trace']);

        $c = payroll_trace_consistency($slip);
        $this->assertFalse($c['available']);
        $this->assertNull($c['consistent'], 'unknown is not the same as consistent');

        // Assembly still succeeds and simply omits what it cannot evidence.
        $t = payroll_build_trace($slip);
        $this->assertFalse($t['evidence']['calculation_trace']);
        $this->assertSame([], $this->stepByKey($t, 'gross')['facts']);
    }

    // ── Deferred deductions ──────────────────────────────────────────────

    public function test_amounts_not_deducted_are_explained(): void
    {
        $slip = $this->slip();
        $slip['_calculation_trace']['deferred']['professional_tax'] = 200.00;

        $s = $this->stepByKey(payroll_build_trace($slip), 'deferred');
        $this->assertNotNull($s, 'an employee asking why PT was not deducted deserves the answer');
        $this->assertSame(200.00, $s['facts']['Professional tax not taken']);
    }

    // ── 14. Reproducibility ──────────────────────────────────────────────

    /**
     * The trace must depend on the FROZEN slip alone. Reading a live collection
     * would make a finalized payslip stop explaining itself the moment the
     * tenant changed a rate.
     */
    public function test_the_trace_reads_nothing_live(): void
    {
        $src = (string) file_get_contents(
            __DIR__ . '/../../application/helpers/payroll_trace_helper.php');

        // Asserted on ACCESS PRIMITIVES rather than collection names. The
        // helper legitimately compares against the string 'statutoryConfig' —
        // that is the engine's own `statutory_source` marker recorded on the
        // slip, not a query. Forbidding the bare name would ban reading a
        // frozen value because it happens to share a collection's spelling.
        // What must be absent is any means of reaching Firestore at all.
        foreach (['schoolWhere', 'getEntity', '->query(', '$this->fs', '$fs', 'firebase'] as $access) {
            $this->assertStringNotContainsString($access, $src,
                "the trace must have no way to reach live data — found {$access}");
        }

        // And it must not be handed a data source it could query.
        $this->assertStringNotContainsString('function payroll_build_trace($fs', $src);
    }

    public function test_the_trace_performs_no_payroll_arithmetic(): void
    {
        $src = (string) file_get_contents(
            __DIR__ . '/../../application/helpers/payroll_trace_helper.php');

        foreach (['_applyRatio', '_toPaise', '/ 100)', '* 0.01'] as $engineJob) {
            $this->assertStringNotContainsString($engineJob, $src,
                'the engine prices the payslip; the trace only reads it');
        }
        // CTC is a sum of stored integers, and the paise→rupee presentation
        // boundary is one function — nothing else divides or multiplies money.
        $this->assertSame(1, substr_count($src, '$paise / 100'));
    }

    /** The same frozen slip must always produce the same trace. */
    public function test_the_trace_is_deterministic(): void
    {
        $slip = $this->slip();
        $a = payroll_build_trace($slip);
        $b = payroll_build_trace($slip);

        // computed_at is copied from the slip, not generated, so equality holds.
        $this->assertSame($a['steps'], $b['steps']);
        $this->assertSame($a['consistency'], $b['consistency']);
    }

    // ── 12. Regeneration keeps the trace fresh ───────────────────────────

    public function test_both_slip_writers_freeze_the_trace(): void
    {
        $hr = (string) file_get_contents(__DIR__ . '/../../application/controllers/Hr.php');
        $this->assertSame(2, substr_count($hr, "'_calculation_trace' =>"),
            'generate and regenerate must both record the trace, or a regenerated '
            . 'slip keeps a trace describing the previous computation');
    }

    public function test_the_engine_states_its_bases(): void
    {
        $hr = (string) file_get_contents(__DIR__ . '/../../application/controllers/Hr.php');
        $this->assertStringContainsString("'pf_base_paise'     => \$effectiveBasicP", $hr,
            'PF base is earned basic');
        $this->assertStringContainsString("'esi_base_paise'    => \$grossP", $hr,
            'ESI base is earned gross');
    }

    // ── 17/18. RBAC and tenancy ──────────────────────────────────────────

    public function test_the_trace_endpoint_is_view_gated_and_tenant_scoped(): void
    {
        $hr = (string) file_get_contents(__DIR__ . '/../../application/controllers/Hr.php');
        $start = strpos($hr, 'public function payslip_trace');
        $this->assertNotFalse($start);
        $body = substr($hr, $start, 2600);

        $this->assertMatchesRegularExpression("/'payslip_trace',\s*'HR',\s*'view'/", $body,
            'reading a trace requires HR:view');
        $this->assertStringContainsString('$this->fs->docId(', $body,
            'the payslip must be addressed through the tenant-prefixing docId()');
        $this->assertStringContainsString("!== \$this->school_id", $body,
            'tenant ownership must be re-checked explicitly');
    }
}
