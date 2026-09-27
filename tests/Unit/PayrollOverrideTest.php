<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

if (!defined('BASEPATH')) define('BASEPATH', __DIR__);
require_once __DIR__ . '/../../application/libraries/Payroll_override_service.php';

/**
 * ZenX HRMS W3.2.2 — payroll-period overrides.
 *
 * WHAT THESE PROTECT
 * ------------------
 * An override moves money. The two ways that goes wrong quietly are (a) a
 * malformed adjustment reaching the engine, and (b) an adjustment being priced
 * by arithmetic other than the certified engine. These tests cover (a)
 * directly, and cover (b) structurally: the service is asserted to contain no
 * money arithmetic at all, so the engine remains the only thing that prices a
 * payslip.
 *
 * Endpoint-level cases (RBAC denial, cross-tenant rejection, finalized-run
 * immutability) need a CodeIgniter request context, which the unit bootstrap
 * cannot provide — `Payroll_overrides.php` extends MY_Controller. Those are
 * asserted at source level here and are listed as OPERATOR VERIFICATION in the
 * gate report rather than claimed as passing behavioural tests.
 */
class PayrollOverrideTest extends TestCase
{
    private function svc(): \Payroll_override_service
    {
        return new \Payroll_override_service();
    }

    /**
     * Source with all comments stripped.
     *
     * The source-level guards below assert that a file does not REFERENCE some
     * class or collection. A plain `strpos` over the raw file cannot tell a
     * real reference from the docblock explaining why the reference is absent —
     * so documenting the boundary breaks the test that protects it, and the
     * pressure becomes to write worse comments. That happened once already in
     * W3.2.1 and was fixed by deleting the prose; fixing the guard instead
     * fixes the whole class of it.
     *
     * Tokenising also makes the guards STRICTER: a name can no longer hide in a
     * comment in either direction.
     */
    private function codeOnly(string $relPath): string
    {
        $src = (string) file_get_contents(__DIR__ . '/../../application/' . $relPath);
        $out = '';
        foreach (token_get_all($src) as $tok) {
            if (is_array($tok)) {
                if ($tok[0] === T_COMMENT || $tok[0] === T_DOC_COMMENT) continue;
                $out .= $tok[1];
            } else {
                $out .= $tok;
            }
        }
        return $out;
    }

    private function valid(array $over = []): array
    {
        return array_merge([
            'run_id'   => 'PR0001',
            'staff_id' => 'STA0001',
            'type'     => 'BONUS',
            'amount'   => 5000,
            'reason'   => 'Q3 performance bonus approved by the principal',
        ], $over);
    }

    // ── A/B. Valid earning and deduction ─────────────────────────────────

    public function test_A_creates_a_valid_earning_override(): void
    {
        $v = $this->svc()->validate($this->valid());
        $this->assertTrue($v['ok'], implode(' ', $v['errors']));
        $this->assertSame('earning', $v['clean']['kind']);
        $this->assertSame(500000, $v['clean']['amount_paise'], '₹5,000 → paise, converted once');
        $this->assertTrue($v['clean']['taxable']);
    }

    public function test_B_creates_a_valid_deduction_override(): void
    {
        $v = $this->svc()->validate($this->valid(['type' => 'RECOVERY', 'amount' => 1200.50]));
        $this->assertTrue($v['ok']);
        $this->assertSame('deduction', $v['clean']['kind']);
        $this->assertSame(120050, $v['clean']['amount_paise'], 'paise precision on a decimal amount');
    }

    // ── C. Invalid amounts ───────────────────────────────────────────────

    public function test_C_rejects_zero_negative_and_non_numeric_amounts(): void
    {
        foreach ([0, -5000, '', null, 'abc'] as $bad) {
            $v = $this->svc()->validate($this->valid(['amount' => $bad]));
            $this->assertFalse($v['ok'], 'amount ' . var_export($bad, true) . ' must be rejected');
        }
    }

    /**
     * Direction is carried by the TYPE, never by the sign of the amount. If a
     * negative amount were accepted, "-5000 BONUS" would reach the engine as an
     * earning of -5000 paise — which the engine skips (`$amtP <= 0`), silently
     * producing an adjustment that appears in the audit trail but pays nothing.
     */
    public function test_C_negative_amount_cannot_smuggle_a_deduction_through_an_earning_type(): void
    {
        $v = $this->svc()->validate($this->valid(['type' => 'BONUS', 'amount' => -5000]));
        $this->assertFalse($v['ok']);
        $this->assertStringContainsString('greater than zero', implode(' ', $v['errors']));
    }

    public function test_C_rejects_an_absurd_amount_that_looks_like_a_misplaced_decimal(): void
    {
        $v = $this->svc()->validate($this->valid(['amount' => 5000000]));
        $this->assertFalse($v['ok'], '₹50,00,000 exceeds the sanity ceiling');
    }

    public function test_C_accepts_the_smallest_representable_amount(): void
    {
        $v = $this->svc()->validate($this->valid(['amount' => 0.01]));
        $this->assertTrue($v['ok']);
        $this->assertSame(1, $v['clean']['amount_paise']);
    }

    // ── D. Type validation ───────────────────────────────────────────────

    public function test_D_rejects_an_unknown_type(): void
    {
        $v = $this->svc()->validate($this->valid(['type' => 'FREE_MONEY']));
        $this->assertFalse($v['ok']);
        $this->assertStringContainsString('Unknown adjustment type', implode(' ', $v['errors']));
    }

    /**
     * Reimbursement is refused with an EXPLANATION rather than as "unknown".
     * Every earning in the engine joins gross and is therefore exposed to ESI
     * and TDS; there is no non-taxable earning class. Booking a reimbursement
     * as a bonus would tax an expense repayment — a money error. Refusing it
     * loudly is the honest behaviour.
     */
    public function test_D_refuses_reimbursement_and_says_why(): void
    {
        $v = $this->svc()->validate($this->valid(['type' => 'REIMBURSEMENT']));
        $this->assertFalse($v['ok']);
        $msg = implode(' ', $v['errors']);
        $this->assertStringContainsString('not supported yet', $msg);
        $this->assertStringContainsString('NON-TAXABLE', $msg);
    }

    public function test_D_unsupported_types_are_documented_not_hidden(): void
    {
        $un = \Payroll_override_service::unsupportedTypes();
        $this->assertArrayHasKey('REIMBURSEMENT', $un);
        $this->assertArrayHasKey('PF_BASE_ADJUSTMENT', $un);
        $this->assertArrayHasKey('LOP_ADJUSTMENT', $un);
        foreach ($un as $code => $why) {
            $this->assertGreaterThan(60, strlen($why), "{$code} must explain itself, not just be absent");
        }
    }

    // ── E/F/G. Required fields ───────────────────────────────────────────

    public function test_E_rejects_a_missing_or_too_short_reason(): void
    {
        foreach (['', 'bonus', 'too short'] as $bad) {
            $v = $this->svc()->validate($this->valid(['reason' => $bad]));
            $this->assertFalse($v['ok'], "reason '{$bad}' must be rejected");
        }
    }

    public function test_E_rejects_an_over_long_reason(): void
    {
        $v = $this->svc()->validate($this->valid(['reason' => str_repeat('x', 501)]));
        $this->assertFalse($v['ok']);
    }

    public function test_F_rejects_a_missing_employee(): void
    {
        $v = $this->svc()->validate($this->valid(['staff_id' => '']));
        $this->assertFalse($v['ok']);
    }

    public function test_G_rejects_a_missing_payroll_run(): void
    {
        $v = $this->svc()->validate($this->valid(['run_id' => '']));
        $this->assertFalse($v['ok']);
    }

    public function test_collects_every_error_rather_than_only_the_first(): void
    {
        $v = $this->svc()->validate(['run_id' => '', 'staff_id' => '', 'type' => '', 'amount' => 0, 'reason' => '']);
        $this->assertFalse($v['ok']);
        $this->assertGreaterThanOrEqual(4, count($v['errors']), 'an operator should see all problems at once');
    }

    // ── L/M. Run editability ─────────────────────────────────────────────

    public function test_M_draft_run_is_editable(): void
    {
        $this->assertTrue(\Payroll_override_service::isRunEditable(['status' => 'Draft']));
    }

    public function test_L_finalized_approved_and_paid_runs_are_frozen(): void
    {
        foreach (['Finalized', 'Approved', 'Paid', 'Processing', ''] as $status) {
            $this->assertFalse(\Payroll_override_service::isRunEditable(['status' => $status]),
                "a '{$status}' run must not accept adjustments");
        }
        $this->assertFalse(\Payroll_override_service::isRunEditable(null));
    }

    // ── N/O/P/Q. Engine hand-off ─────────────────────────────────────────

    public function test_N_maps_overrides_into_the_engine_extra_component_shape(): void
    {
        $xc = \Payroll_override_service::toExtraComponents([
            ['override_id' => 'OVR_1', 'type' => 'BONUS', 'amount_paise' => 500000, 'status' => 'active'],
            ['override_id' => 'OVR_2', 'type' => 'RECOVERY', 'amount_paise' => 120000, 'status' => 'active'],
        ]);

        $this->assertCount(2, $xc);
        // Exactly the keys Hr::_compute_payslip() reads.
        foreach ($xc as $line) {
            $this->assertArrayHasKey('code', $line);
            $this->assertArrayHasKey('kind', $line);
            $this->assertArrayHasKey('amountPaise', $line);
            $this->assertIsInt($line['amountPaise'], 'the engine expects integer paise');
        }
        $this->assertSame('earning', $xc[0]['kind']);
        $this->assertSame('deduction', $xc[1]['kind']);
    }

    /**
     * Two bonuses in one period must survive as two lines. Keying the engine
     * `code` by TYPE would collapse them and pay one.
     */
    public function test_N_two_adjustments_of_the_same_type_do_not_collapse(): void
    {
        $xc = \Payroll_override_service::toExtraComponents([
            ['override_id' => 'OVR_BONUS_0', 'type' => 'BONUS', 'amount_paise' => 500000, 'status' => 'active'],
            ['override_id' => 'OVR_BONUS_1', 'type' => 'BONUS', 'amount_paise' => 250000, 'status' => 'active'],
        ]);
        $this->assertCount(2, $xc);
        $this->assertNotSame($xc[0]['code'], $xc[1]['code']);
        $this->assertSame(750000, $xc[0]['amountPaise'] + $xc[1]['amountPaise']);
    }

    public function test_voided_overrides_never_reach_the_engine(): void
    {
        $xc = \Payroll_override_service::toExtraComponents([
            ['override_id' => 'OVR_1', 'type' => 'BONUS', 'amount_paise' => 500000, 'status' => 'void'],
        ]);
        $this->assertSame([], $xc);
    }

    public function test_unsupported_or_malformed_rows_fail_closed(): void
    {
        $xc = \Payroll_override_service::toExtraComponents([
            ['override_id' => 'X', 'type' => 'REIMBURSEMENT', 'amount_paise' => 500000, 'status' => 'active'],
            ['override_id' => 'Y', 'type' => 'BONUS', 'amount_paise' => 0, 'status' => 'active'],
            ['override_id' => 'Z', 'type' => '', 'amount_paise' => 100, 'status' => 'active'],
            'not-an-array',
        ]);
        $this->assertSame([], $xc, 'anything the engine cannot price correctly is dropped, never guessed');
    }

    public function test_O_P_Q_summary_reports_earnings_deductions_and_net_effect(): void
    {
        $s = \Payroll_override_service::summarise([
            ['override_id' => 'A', 'type' => 'BONUS', 'amount_paise' => 500000, 'status' => 'active'],
            ['override_id' => 'B', 'type' => 'INCENTIVE', 'amount_paise' => 200000, 'status' => 'active'],
            ['override_id' => 'C', 'type' => 'RECOVERY', 'amount_paise' => 150000, 'status' => 'active'],
            ['override_id' => 'D', 'type' => 'BONUS', 'amount_paise' => 999999, 'status' => 'void'],
        ]);

        $this->assertSame(3, $s['count'], 'the voided row is excluded');
        $this->assertSame(700000, $s['earnings_paise']);
        $this->assertSame(150000, $s['deductions_paise']);
        $this->assertSame(550000, $s['net_effect_paise']);
    }

    public function test_summary_of_nothing_is_zero_not_an_error(): void
    {
        $s = \Payroll_override_service::summarise([]);
        $this->assertSame(0, $s['count']);
        $this->assertSame(0, $s['net_effect_paise']);
    }

    // ── V. Deterministic ids / duplicate protection ──────────────────────

    public function test_V_the_same_override_produces_the_same_id(): void
    {
        $a = \Payroll_override_service::buildOverrideId('PR0001', 'STA0001', 'BONUS', 0);
        $b = \Payroll_override_service::buildOverrideId('PR0001', 'STA0001', 'BONUS', 0);
        $this->assertSame($a, $b, 'a retried create must dedup, not create a second bonus');
    }

    public function test_V_a_genuine_second_adjustment_gets_its_own_id(): void
    {
        $this->assertNotSame(
            \Payroll_override_service::buildOverrideId('PR0001', 'STA0001', 'BONUS', 0),
            \Payroll_override_service::buildOverrideId('PR0001', 'STA0001', 'BONUS', 1)
        );
    }

    public function test_ids_are_scoped_by_run_and_employee(): void
    {
        $base = \Payroll_override_service::buildOverrideId('PR0001', 'STA0001', 'BONUS', 0);
        $this->assertNotSame($base, \Payroll_override_service::buildOverrideId('PR0002', 'STA0001', 'BONUS', 0));
        $this->assertNotSame($base, \Payroll_override_service::buildOverrideId('PR0001', 'STA0002', 'BONUS', 0));
    }

    // ── W. Determinism ───────────────────────────────────────────────────

    public function test_W_mapping_is_deterministic_across_repeated_calls(): void
    {
        $rows = [
            ['override_id' => 'A', 'type' => 'BONUS', 'amount_paise' => 500000, 'status' => 'active'],
            ['override_id' => 'B', 'type' => 'RECOVERY', 'amount_paise' => 150000, 'status' => 'active'],
        ];
        $this->assertSame(
            \Payroll_override_service::toExtraComponents($rows),
            \Payroll_override_service::toExtraComponents($rows)
        );
        $this->assertSame(
            \Payroll_override_service::summarise($rows),
            \Payroll_override_service::summarise($rows)
        );
    }

    // ── Money-path and architecture guards ───────────────────────────────

    /**
     * The single most important assertion here.
     *
     * If this service ever computed a gross, a net or a statutory deduction, it
     * would be a second payroll engine — and the W1.2 guarantee that
     * `gross == net + deductions` would depend on two implementations agreeing.
     * It converts rupees to paise once at the input boundary and does nothing
     * else with money.
     */
    public function test_the_service_contains_no_payroll_arithmetic(): void
    {
        $src = $this->codeOnly('libraries/Payroll_override_service.php');

        foreach (['_compute_payslip', 'pf_employee', 'esi_employee', 'professional_tax',
                  'net_pay', 'total_deductions'] as $engineConcern) {
            $this->assertStringNotContainsString($engineConcern, $src,
                "the override service must not know about {$engineConcern} — that is the engine's job");
        }

        // Exactly one rounding, at the rupee→paise input boundary.
        $this->assertSame(1, substr_count($src, 'round('),
            'one conversion at the boundary; no scattered rounding');
    }

    public function test_the_service_holds_no_tenant_literal_and_no_rtdb(): void
    {
        $src = (string) file_get_contents(
            __DIR__ . '/../../application/libraries/Payroll_override_service.php');
        $this->assertStringNotContainsString('firebase->', $src);
        $this->assertDoesNotMatchRegularExpression('/SCH_[A-Z0-9]{6,}/', $src);
    }

    /**
     * X. Tenant isolation, at source level. Every read and write goes through
     * the tenant-scoping primitives; a raw `query()` or an unprefixed `set()`
     * would cross tenants.
     */
    public function test_X_every_persistence_call_is_tenant_scoped(): void
    {
        $src = (string) file_get_contents(
            __DIR__ . '/../../application/libraries/Payroll_override_service.php');

        $this->assertStringNotContainsString('->query(', $src,
            'raw query() is unscoped — schoolWhere() is required');
        // Every write targets docId(), which prefixes the tenant.
        $this->assertSame(substr_count($src, '->set(self::COLLECTION'),
                          substr_count($src, '$this->fs->docId('),
            'every write must be addressed through docId()');
        $this->assertStringContainsString('schoolWhere(self::COLLECTION', $src);
    }

    /**
     * I/J/K. Authorization is enforced per endpoint, using graded RBAC, and
     * `view` is never sufficient to move money.
     */
    public function test_I_J_K_endpoints_enforce_graded_rbac_independently(): void
    {
        $src = $this->codeOnly('controllers/Payroll_overrides.php');

        // No role-name gates anywhere.
        foreach (['Principal', 'HR Manager', 'ADMIN_ROLES', '_require_role'] as $banned) {
            $this->assertStringNotContainsString($banned, $src,
                "graded RBAC only — {$banned} is a role-name gate");
        }

        // Both money-moving endpoints require manage; neither settles for view.
        // Call sites only — `$this->` excludes the method definition itself.
        $this->assertSame(2, substr_count($src, '$this->_require_manage()'),
            'create and void must both require manage');
        $this->assertStringContainsString("require_permission('HR', 'payroll_override_manage', 'manage')", $src);

        // Every public endpoint opens with an authorization call of its own.
        preg_match_all('/public function (\w+)\(\): void\s*\{(.*?)\n    \}/s', $src, $m);
        $this->assertNotEmpty($m[1]);
        foreach ($m[1] as $i => $method) {
            if ($method === '__construct') continue;
            $this->assertMatchesRegularExpression('/_require_(view|manage)\(\)/', $m[2][$i],
                "{$method}() must enforce authorization itself, not rely on the UI");
        }
    }

    /** Y. The compensation seam is not bypassed by any of this. */
    public function test_Y_overrides_do_not_touch_the_compensation_model(): void
    {
        foreach (['libraries/Payroll_override_service.php', 'controllers/Payroll_overrides.php'] as $f) {
            $src = $this->codeOnly($f);
            foreach (['staffSalaryAssignments', 'salaryComponents', 'Salary_assignment_service'] as $behindSeam) {
                $this->assertStringNotContainsString($behindSeam, $src,
                    "{$f} must not reach past the Compensation_reader seam to {$behindSeam}");
            }
        }
    }

    /** Z/AA. Overrides must not rewrite the frozen input snapshots. */
    public function test_Z_AA_overrides_never_write_the_input_snapshots(): void
    {
        foreach (['libraries/Payroll_override_service.php', 'controllers/Payroll_overrides.php'] as $f) {
            $src = (string) file_get_contents(__DIR__ . '/../../application/' . $f);
            $this->assertStringNotContainsString('_attendance_snapshot', $src);
            $this->assertStringNotContainsString('_salary_snapshot', $src);
        }
    }

    /**
     * The override path must reuse the existing recalculation and its existing
     * delta journal, not introduce a second accounting posting.
     */
    public function test_overrides_do_not_post_their_own_accounting(): void
    {
        foreach (['libraries/Payroll_override_service.php', 'controllers/Payroll_overrides.php'] as $f) {
            $src = $this->codeOnly($f);
            foreach (['postRunAdjustment', 'postRunAccrual', 'PayrollAccountingService',
                      "'5010'", "'2020'"] as $accounting) {
                $this->assertStringNotContainsString($accounting, $src,
                    'accounting is posted by the existing recalculation, not by the override path');
            }
        }
    }

    /** The engine receives overrides at BOTH call sites, or generate and regenerate diverge. */
    public function test_both_engine_call_sites_receive_overrides(): void
    {
        $hr = (string) file_get_contents(__DIR__ . '/../../application/controllers/Hr.php');
        $this->assertSame(2, substr_count($hr, 'Payroll_override_service::toExtraComponents('),
            'generate_payroll and regenerate_staff_payroll must both apply overrides');
    }

    /**
     * H. Original and final must both come from the engine. Deriving the
     * baseline by subtracting the adjustment would be a second arithmetic path
     * AND would be wrong whenever an added earning moves the ESI/TDS base.
     */
    public function test_H_the_baseline_is_computed_by_the_engine_not_subtracted(): void
    {
        $hr = (string) file_get_contents(__DIR__ . '/../../application/controllers/Hr.php');
        $this->assertStringContainsString('$baselineCalc', $hr);
        $this->assertStringContainsString("'original_net'     => \$baselineCalc['net_pay'] ?? null", $hr);
        $this->assertStringContainsString("'final_net'        => \$calc['net_pay']", $hr);
    }
}
