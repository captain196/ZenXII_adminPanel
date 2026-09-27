<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

if (!defined('BASEPATH')) define('BASEPATH', __DIR__);
require_once __DIR__ . '/../../application/helpers/payroll_snapshot_helper.php';

/**
 * ZenX HRMS W3.2.3 — loss-of-pay review and confirmation.
 *
 * The two risks this gate addresses are (a) pay being deducted without anyone
 * having looked at it, and (b) an operator "fixing" wrong LOP with a payroll
 * adjustment, which would leave payroll and attendance permanently disagreeing.
 *
 * The decode is behaviourally tested. The endpoint behaviour (RBAC, the
 * finalization gate, staleness) needs a CodeIgniter request context that the
 * unit bootstrap cannot provide, so it is asserted at source level here and
 * listed as OPERATOR VERIFICATION in the gate report rather than claimed.
 */
class PayrollLopReviewTest extends TestCase
{
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

    // ── Decode: mask → the days an operator can actually check ───────────

    public function test_decodes_full_and_half_lop_days(): void
    {
        //                                    1234567890
        $d = payroll_lwp_days_from_mask('--F---H---');
        $this->assertSame([3], $d['full']);
        $this->assertSame([7], $d['half']);
    }

    public function test_decode_is_the_exact_inverse_of_the_encoder(): void
    {
        $set  = [3 => true, 7 => 0.5, 19 => true];
        $mask = payroll_lwp_mask($set, 30);
        $d    = payroll_lwp_days_from_mask($mask);

        $this->assertSame([3, 19], $d['full']);
        $this->assertSame([7], $d['half']);
    }

    public function test_decode_positions_are_one_indexed_like_daywise(): void
    {
        // An off-by-one here would tell an operator the wrong calendar day was
        // unpaid — the single most checkable claim on the whole screen.
        $this->assertSame([1], payroll_lwp_days_from_mask('F----')['full']);
        $this->assertSame([5], payroll_lwp_days_from_mask('----F')['full']);
        $this->assertSame([31], payroll_lwp_days_from_mask(str_repeat('-', 30) . 'F')['full']);
    }

    public function test_decode_handles_a_month_with_no_lop(): void
    {
        $d = payroll_lwp_days_from_mask(str_repeat('-', 31));
        $this->assertSame([], $d['full']);
        $this->assertSame([], $d['half']);
    }

    /**
     * A slip generated before W3.2.1 has no mask. The review must say so
     * rather than produce a day list — reconstructing one from today's
     * attendance would be invented history presented as record.
     */
    public function test_decode_returns_nothing_for_a_missing_mask(): void
    {
        foreach ([null, ''] as $absent) {
            $d = payroll_lwp_days_from_mask($absent);
            $this->assertSame([], $d['full']);
            $this->assertSame([], $d['half']);
        }
    }

    public function test_decode_ignores_unknown_symbols_rather_than_guessing(): void
    {
        $d = payroll_lwp_days_from_mask('-X?F');
        $this->assertSame([4], $d['full']);
        $this->assertSame([], $d['half']);
    }

    public function test_decode_handles_a_fully_unpaid_month(): void
    {
        $d = payroll_lwp_days_from_mask(str_repeat('F', 30));
        $this->assertCount(30, $d['full']);
        $this->assertSame(1, $d['full'][0]);
        $this->assertSame(30, $d['full'][29]);
    }

    // ── No second leave engine ───────────────────────────────────────────

    /**
     * `_process_leave_for_payroll()` is the single LOP resolver, shared by
     * generate and regenerate specifically "to guarantee identical outputs".
     * The review must REPORT what it decided, never recompute.
     */
    public function test_the_review_does_not_recompute_lop(): void
    {
        $hr = $this->codeOnly('controllers/Hr.php');

        // Still exactly one resolver, and exactly two callers of it.
        $this->assertSame(1, substr_count($hr, 'private function _process_leave_for_payroll'),
            'there must remain exactly one LOP resolver');
        $this->assertSame(2, substr_count($hr, '$this->_process_leave_for_payroll('),
            'generate and regenerate are its only callers');

        // The review reads stored slip values, and never calls the resolver.
        $review = $this->lopReviewBody($hr);
        $this->assertStringNotContainsString('_process_leave_for_payroll', $review,
            'lop_review() must report stored LOP, not recompute it');
    }

    /**
     * The review must read the FROZEN slip evidence, not live attendance. A
     * review sourced from live data would stop matching the payslip the moment
     * anyone corrected an attendance record — which is the drift the W3.2.1
     * snapshot exists to prevent.
     */
    public function test_the_review_reads_frozen_evidence_not_live_attendance(): void
    {
        $review = $this->lopReviewBody($this->codeOnly('controllers/Hr.php'));

        $this->assertStringContainsString('_attendance_snapshot', $review,
            'day-level detail must come from the frozen snapshot');
        $this->assertStringNotContainsString('staffAttendanceSummary', $review,
            'lop_review() must not query live attendance');
    }

    /** Extract the body of lop_review() so assertions are scoped to it. */
    private function lopReviewBody(string $code): string
    {
        $start = strpos($code, 'public function lop_review');
        $this->assertNotFalse($start, 'lop_review() must exist');
        $end = strpos($code, 'public function confirm_lop', $start);
        $this->assertNotFalse($end, 'confirm_lop() must exist');
        return substr($code, $start, $end - $start);
    }

    // ── Finalization gate ────────────────────────────────────────────────

    /**
     * A run that deducts pay must not be frozen until the deduction has been
     * signed off. Before this gate the deduction was approved implicitly by
     * whoever clicked Finalize, with nothing recorded about whether anyone had
     * looked at it.
     */
    public function test_finalization_requires_lop_confirmation(): void
    {
        $code = $this->codeOnly('controllers/Hr.php');
        $start = strpos($code, 'public function finalize_payroll');
        $this->assertNotFalse($start);
        $body = substr($code, $start, 4000);

        $this->assertStringContainsString('_lop_totals(', $body,
            'finalize must consult the run LOP totals');
        $this->assertStringContainsString('lop_confirmation', $body,
            'finalize must check for a confirmation record');
    }

    /**
     * The gate must apply ONLY when the run actually deducts pay — a run with
     * no LOP has to finalize exactly as it did before, or this becomes a
     * breaking change for every tenant that never had LOP.
     */
    public function test_the_gate_only_applies_when_the_run_deducts_pay(): void
    {
        $code = $this->codeOnly('controllers/Hr.php');
        $start = strpos($code, 'public function finalize_payroll');
        $body  = substr($code, $start, 4000);

        $this->assertMatchesRegularExpression('/if\s*\(\s*\$lopDays\s*>\s*0\s*\)/', $body,
            'the confirmation requirement must be conditional on LOP existing');
    }

    /**
     * A sign-off is bound to the figures it covered. If a regeneration changed
     * the LOP afterwards, confirming ₹2,000 must not silently authorise
     * ₹40,000.
     */
    public function test_a_confirmation_goes_stale_when_the_figures_change(): void
    {
        $code = $this->codeOnly('controllers/Hr.php');
        $start = strpos($code, 'public function finalize_payroll');
        $body  = substr($code, $start, 4000);

        $this->assertStringContainsString('$sameDays', $body);
        $this->assertStringContainsString('$sameDed', $body);
        $this->assertMatchesRegularExpression('/!\$sameDays\s*\|\|\s*!\$sameDed/', $body,
            'either figure changing must invalidate the confirmation');
    }

    /** The confirmation must record who and when, plus the figures covered. */
    public function test_the_confirmation_record_is_auditable(): void
    {
        $code  = $this->codeOnly('controllers/Hr.php');
        $start = strpos($code, 'public function confirm_lop');
        $body  = substr($code, $start, 2500);

        foreach (['confirmed_by', 'confirmed_at', 'lwp_days', 'lwp_deduction', 'affected_staff'] as $field) {
            $this->assertStringContainsString($field, $body,
                "the confirmation must record {$field}");
        }
    }

    public function test_confirmation_is_refused_once_the_run_leaves_draft(): void
    {
        $code  = $this->codeOnly('controllers/Hr.php');
        $start = strpos($code, 'public function confirm_lop');
        $body  = substr($code, $start, 2500);

        $this->assertStringContainsString("'Draft'", $body,
            'a finalized run is already frozen and must not accept a new sign-off');
    }

    // ── RBAC ─────────────────────────────────────────────────────────────

    /**
     * Reading the review is `view`; signing off is `manage`, because a sign-off
     * is what permits pay to be deducted.
     */
    public function test_review_is_view_and_confirmation_is_manage(): void
    {
        $code = $this->codeOnly('controllers/Hr.php');

        $this->assertMatchesRegularExpression(
            "/'lop_review',\s*'HR',\s*'view'/", $code,
            'reading the review requires only view');
        $this->assertMatchesRegularExpression(
            "/'confirm_lop',\s*'HR',\s*'manage'/", $code,
            'confirming requires manage');
    }

    // ── Correction authority ─────────────────────────────────────────────

    /**
     * The most important guard in this file.
     *
     * W3.2.2 shipped a payroll adjustment tool. LOP is derived from Attendance
     * and Leave, so "fixing" it with a deduction would make payroll a second
     * attendance system and leave the two disagreeing forever. The review must
     * actively steer the operator back to the authoritative correction path,
     * and that guidance must come from the server rather than being invented in
     * the browser.
     */
    public function test_the_review_directs_corrections_back_to_attendance_and_leave(): void
    {
        $review = $this->lopReviewBody($this->codeOnly('controllers/Hr.php'));
        $this->assertStringContainsString('correction_guidance', $review);

        $view = (string) file_get_contents(__DIR__ . '/../../application/views/hr/index.php');
        $this->assertStringContainsString('r.correction_guidance', $view,
            'the UI must render the SERVER guidance, not compose its own');
    }

    /** LOP adjustment stays refused as an override type. */
    public function test_lop_remains_unavailable_as_a_payroll_adjustment(): void
    {
        require_once __DIR__ . '/../../application/libraries/Payroll_override_service.php';

        $this->assertFalse(\Payroll_override_service::isSupportedType('LOP_ADJUSTMENT'));
        $this->assertArrayHasKey('LOP_ADJUSTMENT', \Payroll_override_service::unsupportedTypes());
    }

    // ── The browser does no payroll arithmetic ───────────────────────────

    public function test_the_lop_panel_renders_server_values_only(): void
    {
        $view = (string) file_get_contents(__DIR__ . '/../../application/views/hr/index.php');
        $start = strpos($view, 'function loadLopReview');
        $this->assertNotFalse($start);
        $body = substr($view, $start, 5000);

        // No deduction, daily-rate or day-count arithmetic in the browser.
        $this->assertStringNotContainsString('daily_salary *', $body);
        $this->assertStringNotContainsString('* 0.5', $body);
        $this->assertStringNotContainsString('lwp_days *', $body);
    }
}
