<?php
namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Staff HR Advanced Capability Programme — Phase 3 (Clearance UI),
 * 2026-08-18.
 *
 * Source-regression tests (the view is HTML+JS — comment-stripping
 * convention matches every other view-touching test in this suite).
 */
class StaffClearanceUiWiringTest extends TestCase
{
    private string $viewSrc;

    protected function setUp(): void
    {
        $this->viewSrc = file_get_contents(__DIR__ . '/../../application/views/edit_staff.php');
        $this->assertNotFalse($this->viewSrc);
    }

    private function viewCode(): string
    {
        $v = $this->viewSrc;
        $v = preg_replace('/<!--.*?-->/s', '', $v);
        $v = preg_replace('~/\*.*?\*/~s', '', $v);
        $v = preg_replace('~^\s*//.*$~m', '', $v);
        return (string) $v;
    }

    public function test_modal_exists_outside_the_main_edit_form(): void
    {
        $formClosePos = strpos($this->viewSrc, '</form>');
        $modalPos = strpos($this->viewSrc, 'id="clearanceModalOverlay"');
        $this->assertNotFalse($formClosePos);
        $this->assertNotFalse($modalPos);
        $this->assertLessThan($modalPos, $formClosePos);
    }

    public function test_open_button_is_type_button(): void
    {
        $btnPos = strpos($this->viewSrc, 'id="openClearanceModalBtn"');
        $this->assertNotFalse($btnPos);
        $tagStart = strrpos(substr($this->viewSrc, 0, $btnPos), '<button');
        $tag = substr($this->viewSrc, $tagStart, $btnPos - $tagStart + 40);
        $this->assertStringContainsString('type="button"', $tag);
    }

    public function test_all_three_backend_endpoints_referenced(): void
    {
        foreach (['staff/get_clearance', 'staff/initiate_clearance', 'staff/update_clearance_department'] as $endpoint) {
            $this->assertStringContainsString($endpoint, $this->viewSrc);
        }
    }

    public function test_department_list_rendered_dynamically_not_hardcoded(): void
    {
        // The UI must iterate whatever the backend returns (Object.keys),
        // never assume a fixed department set client-side either.
        $code = $this->viewCode();
        $this->assertStringContainsString('Object.keys(depts).forEach(function (dept) {', $code);
    }

    public function test_only_cleared_and_flagged_actions_offered_no_direct_completion_button(): void
    {
        // Completion is a SERVER-computed side effect of every department
        // being cleared — the UI must not offer a manual "mark complete"
        // shortcut that could bypass that invariant.
        $code = $this->viewCode();
        $this->assertStringContainsString("clearanceUpdateDept(", $code);
        $this->assertStringNotContainsString('mark_complete', $code);
        $this->assertStringNotContainsString('forceComplete', $code);
    }

    public function test_no_payroll_or_salary_wording_anywhere_in_clearance_ui(): void
    {
        $start = strpos($this->viewSrc, 'CLEARANCE MODAL');
        $end = strpos($this->viewSrc, 'function clearanceUpdateDept');
        $slice = strtolower(substr($this->viewSrc, $start, $end - $start));
        $this->assertStringNotContainsString('payroll', $slice);
        $this->assertStringNotContainsString('deduct', $slice);
    }
}
