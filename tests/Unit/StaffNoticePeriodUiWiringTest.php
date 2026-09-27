<?php
namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Staff HR Advanced Capability Programme — Phase 2 (Notice Period UI),
 * 2026-08-18.
 *
 * The critical safety property this UI must preserve: "Complete Separation"
 * chains the EXISTING, unmodified staff/set_status endpoint FIRST, and only
 * calls complete_notice_period() on that call's success — set_status()'s
 * own logic is never reimplemented client-side.
 *
 * Source-regression tests (the view is HTML+JS — comment-stripping
 * convention matches every other view-touching test in this suite).
 */
class StaffNoticePeriodUiWiringTest extends TestCase
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
        $modalPos = strpos($this->viewSrc, 'id="noticeModalOverlay"');
        $this->assertNotFalse($formClosePos);
        $this->assertNotFalse($modalPos);
        $this->assertLessThan($modalPos, $formClosePos);
    }

    public function test_open_button_is_type_button_not_submit(): void
    {
        $btnPos = strpos($this->viewSrc, 'id="openNoticeModalBtn"');
        $this->assertNotFalse($btnPos);
        $tagStart = strrpos(substr($this->viewSrc, 0, $btnPos), '<button');
        $tag = substr($this->viewSrc, $tagStart, $btnPos - $tagStart + 40);
        $this->assertStringContainsString('type="button"', $tag);
    }

    public function test_complete_separation_calls_set_status_before_complete_notice_period(): void
    {
        $code = $this->viewCode();
        $fnStart = strpos($code, 'function noticeCompleteSeparation()');
        $this->assertNotFalse($fnStart);
        $fnBody = substr($code, $fnStart, 1600);
        $setStatusPos = strpos($fnBody, 'staff/set_status/');
        $completePos = strpos($fnBody, 'staff/complete_notice_period');
        $this->assertNotFalse($setStatusPos);
        $this->assertNotFalse($completePos);
        $this->assertLessThan($completePos, $setStatusPos,
            'set_status (existing, unmodified separation endpoint) must be called BEFORE complete_notice_period — never the other way round, and never skipped.');
    }

    public function test_complete_notice_period_only_called_inside_set_status_success_callback(): void
    {
        $code = $this->viewCode();
        $fnStart = strpos($code, 'function noticeCompleteSeparation()');
        $fnBody = substr($code, $fnStart, 1600);
        $successPos = strpos($fnBody, 'success: function (res) {');
        $completePos = strpos($fnBody, 'staff/complete_notice_period');
        $this->assertNotFalse($successPos);
        $this->assertLessThan($completePos, $successPos);
    }

    public function test_uses_the_exact_same_status_and_reason_shape_as_the_existing_separation_flow(): void
    {
        $code = $this->viewCode();
        $fnStart = strpos($code, 'function noticeCompleteSeparation()');
        $fnBody = substr($code, $fnStart, 500);
        $this->assertStringContainsString("fd.append('status', 'Inactive');", $fnBody);
    }

    public function test_all_five_backend_endpoints_referenced(): void
    {
        foreach ([
            'staff/get_notice_period',
            'staff/submit_resignation',
            'staff/decide_notice_period',
            'staff/withdraw_resignation',
            'staff/complete_notice_period',
        ] as $endpoint) {
            $this->assertStringContainsString($endpoint, $this->viewSrc);
        }
    }

    public function test_waive_and_reduce_require_a_client_side_date_before_submit(): void
    {
        $code = $this->viewCode();
        $fnStart = strpos($code, 'function noticeDecide(decision)');
        $fnBody = substr($code, $fnStart, 500);
        $this->assertStringContainsString("if ((decision === 'waive' || decision === 'reduce') && !actualLwd) {", $fnBody);
    }

    public function test_state_rendering_covers_all_backend_status_values(): void
    {
        $code = $this->viewCode();
        $fnStart = strpos($code, 'function noticeRenderState(notice)');
        $this->assertNotFalse($fnStart);
        $fnBody = substr($code, $fnStart, 3500);
        foreach (["'pending'", "'approved'", "'waived'", "'reduced'", "'withdrawn'", "'rejected'", "'completed'"] as $status) {
            $this->assertStringContainsString($status, $fnBody, "state renderer must handle status {$status}");
        }
    }
}
