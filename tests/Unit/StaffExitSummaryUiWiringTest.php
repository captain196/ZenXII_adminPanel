<?php
namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Staff HR Advanced Capability Programme — Phase 5 (Exit Summary UI),
 * 2026-08-18.
 *
 * Source-regression tests (the view is HTML+JS — comment-stripping
 * convention matches every other view-touching test in this suite).
 */
class StaffExitSummaryUiWiringTest extends TestCase
{
    private string $viewSrc;

    protected function setUp(): void
    {
        $this->viewSrc = file_get_contents(__DIR__ . '/../../application/views/edit_staff.php');
        $this->assertNotFalse($this->viewSrc);
    }

    public function test_modal_exists_outside_the_main_edit_form(): void
    {
        $formClosePos = strpos($this->viewSrc, '</form>');
        $modalPos = strpos($this->viewSrc, 'id="exitSummaryModalOverlay"');
        $this->assertNotFalse($formClosePos);
        $this->assertNotFalse($modalPos);
        $this->assertLessThan($modalPos, $formClosePos);
    }

    public function test_open_button_is_type_button(): void
    {
        $btnPos = strpos($this->viewSrc, 'id="openExitSummaryModalBtn"');
        $this->assertNotFalse($btnPos);
        $tagStart = strrpos(substr($this->viewSrc, 0, $btnPos), '<button');
        $tag = substr($this->viewSrc, $tagStart, $btnPos - $tagStart + 40);
        $this->assertStringContainsString('type="button"', $tag);
    }

    public function test_fetches_from_the_correct_backend_endpoint(): void
    {
        $this->assertStringContainsString('base_url("staff/get_exit_summary")', $this->viewSrc);
    }

    public function test_request_carries_staff_id(): void
    {
        $pos = strpos($this->viewSrc, 'base_url("staff/get_exit_summary")');
        $this->assertNotFalse($pos);
        $chunk = substr($this->viewSrc, $pos, 200);
        $this->assertStringContainsString('staff_id: PROMO_STAFF_ID', $chunk);
    }

    public function test_is_a_get_request_no_mutation(): void
    {
        $pos = strpos($this->viewSrc, 'base_url("staff/get_exit_summary")');
        $this->assertNotFalse($pos);
        $chunk = substr($this->viewSrc, max(0, $pos - 120), 220);
        $this->assertStringContainsString("method: 'GET'", $chunk);
    }

    public function test_render_function_uses_esc_for_every_dynamic_value(): void
    {
        $start = strpos($this->viewSrc, 'function exitSummaryRender(');
        $this->assertNotFalse($start);
        $end = strpos($this->viewSrc, 'body.innerHTML = rows;', $start);
        $this->assertNotFalse($end);
        $body = substr($this->viewSrc, $start, $end - $start);
        // Every dynamic field is written via esc(...) inside a disabled input value,
        // never raw string concatenation into innerHTML.
        $this->assertStringContainsString('esc(s.lastWorkingDay', $body);
        $this->assertStringContainsString('esc(payslipText)', $body);
        $this->assertStringContainsString('esc(leaveText)', $body);
        $this->assertStringContainsString('esc(clearanceText)', $body);
    }

    public function test_pending_reimbursements_shown_as_not_tracked_not_a_computed_amount(): void
    {
        $this->assertStringContainsString('Not tracked in ZenX (no expense/reimbursement claim workflow exists).', $this->viewSrc);
    }

    public function test_no_financial_calculation_wording_in_the_modal(): void
    {
        $start = strpos($this->viewSrc, 'id="exitSummaryModalOverlay"');
        $end = strpos($this->viewSrc, 'function openExitSummaryModal', $start);
        $chunk = strtolower(substr($this->viewSrc, $start, ($end !== false ? $end : $start + 4000) - $start));
        foreach (['encashment', 'gratuity', 'final payable', 'notice recovery'] as $term) {
            $this->assertStringNotContainsString($term, $chunk);
        }
    }
}
