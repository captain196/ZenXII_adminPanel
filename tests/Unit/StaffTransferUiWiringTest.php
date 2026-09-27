<?php
namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Trail 2 (Work Schedule → Calendar → Attendance → Leave/LOP → Payroll),
 * Phase I — Staff Transfer UI, 2026-08-18.
 *
 * Source-regression tests (the view is HTML+JS — comment-stripping
 * convention matches every other view-touching test in this suite).
 */
class StaffTransferUiWiringTest extends TestCase
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
        $modalPos = strpos($this->viewSrc, 'id="transferModalOverlay"');
        $this->assertNotFalse($formClosePos);
        $this->assertNotFalse($modalPos);
        $this->assertLessThan($modalPos, $formClosePos);
    }

    public function test_open_button_is_type_button(): void
    {
        $btnPos = strpos($this->viewSrc, 'id="openTransferModalBtn"');
        $this->assertNotFalse($btnPos);
        $tagStart = strrpos(substr($this->viewSrc, 0, $btnPos), '<button');
        $tag = substr($this->viewSrc, $tagStart, $btnPos - $tagStart + 40);
        $this->assertStringContainsString('type="button"', $tag);
    }

    public function test_modal_inputs_carry_no_name_attribute(): void
    {
        // Same safety pattern as every other modal in this file — prevents
        // FormData(edit_staff_form) from sweeping a transfer field into an
        // unrelated "Save Changes" submit.
        //
        // Trail 2 Priority 3/4 (2026-08-19): the Work Schedule decision
        // point's "Keep current / Change" choice is a genuine HTML radio
        // group, which REQUIRES a shared `name="transferScheduleChoice"` to
        // behave as mutually-exclusive at all — that `name` is exempted
        // below, still safely outside #edit_staff_form (confirmed by
        // test_modal_exists_outside_the_main_edit_form above). Every other
        // input in the modal — the actual fields whose values get POSTed —
        // must still carry no `name` at all.
        $start = strpos($this->viewSrc, 'id="transferModalOverlay"');
        $end = strpos($this->viewSrc, 'id="noticeModalOverlay"');
        $this->assertNotFalse($start);
        $this->assertNotFalse($end);
        $chunk = substr($this->viewSrc, $start, $end - $start);
        $chunk = str_replace('name="transferScheduleChoice"', '', $chunk);
        $this->assertStringContainsString('id="transferNewSchedule"', $chunk,
            'sanity check: the exemption above must not have swallowed real modal content.');
        $this->assertStringNotContainsString('name="transfer', $chunk);
    }

    public function test_effective_date_field_is_marked_required(): void
    {
        $start = strpos($this->viewSrc, 'id="transferModalOverlay"');
        $end = strpos($this->viewSrc, 'id="noticeModalOverlay"');
        $chunk = substr($this->viewSrc, $start, $end - $start);
        $this->assertStringContainsString('id="transferEffectiveFrom" class="nsa-input" required', $chunk);
    }

    public function test_submit_calls_the_correct_backend_endpoint(): void
    {
        $this->assertStringContainsString('base_url("staff/transfer_staff")', $this->viewSrc);
    }

    public function test_submit_blocks_client_side_without_an_effective_date(): void
    {
        $start = strpos($this->viewSrc, 'function submitTransfer()');
        $this->assertNotFalse($start);
        $end = strpos($this->viewSrc, 'function ', $start + 30);
        $chunk = substr($this->viewSrc, $start, ($end !== false ? $end : $start + 2000) - $start);
        $this->assertStringContainsString('Effective date is required for a transfer.', $chunk);
    }

    public function test_submit_requires_at_least_one_new_value(): void
    {
        $start = strpos($this->viewSrc, 'function submitTransfer()');
        $end = strpos($this->viewSrc, 'NOTICE PERIOD MODAL', $start);
        $chunk = substr($this->viewSrc, $start, ($end !== false ? $end : $start + 2000) - $start);
        $this->assertStringContainsString('Set at least one new value (department or reporting manager).', $chunk);
    }

    public function test_empty_manager_selection_is_never_submitted_as_a_clear(): void
    {
        $start = strpos($this->viewSrc, 'function submitTransfer()');
        $end = strpos($this->viewSrc, 'NOTICE PERIOD MODAL', $start);
        $chunk = substr($this->viewSrc, $start, ($end !== false ? $end : $start + 2000) - $start);
        $this->assertStringContainsString('if (newManager) fd.append(\'new_reporting_manager_id\', newManager);', $chunk);
    }

    public function test_success_reloads_the_page_to_reflect_the_change(): void
    {
        $start = strpos($this->viewSrc, 'function submitTransfer()');
        $end = strpos($this->viewSrc, 'NOTICE PERIOD MODAL', $start);
        $chunk = substr($this->viewSrc, $start, ($end !== false ? $end : $start + 2000) - $start);
        $this->assertStringContainsString('window.location.reload();', $chunk);
    }

    public function test_reuses_the_same_department_and_manager_dropdown_endpoints_as_promotion(): void
    {
        $start = strpos($this->viewSrc, 'function openTransferModal()');
        $this->assertNotFalse($start);
        $end = strpos($this->viewSrc, 'function closeTransferModal()');
        $chunk = substr($this->viewSrc, $start, $end - $start);
        $this->assertStringContainsString('base_url("hr/get_departments")', $chunk);
        $this->assertStringContainsString('base_url("hr/get_staff_list")', $chunk);
    }

    public function test_excludes_self_from_the_reporting_manager_options(): void
    {
        $start = strpos($this->viewSrc, 'function openTransferModal()');
        $end = strpos($this->viewSrc, 'function closeTransferModal()');
        $chunk = substr($this->viewSrc, $start, $end - $start);
        $this->assertStringContainsString('if (s.id === PROMO_STAFF_ID) return;', $chunk);
    }

    public function test_no_element_id_shadows_a_global_function_name(): void
    {
        // Same DOM-clobbering guard established after the new_staff.php
        // photo-upload regression this session — check the ids added by
        // this feature specifically don't collide with any top-level
        // function name in the same file.
        preg_match_all('/^function ([a-zA-Z0-9_]+)/m', $this->viewSrc, $fnMatches);
        $transferIds = ['transferModalOverlay', 'openTransferModalBtn', 'transferCurrentDepartment',
            'transferNewDepartment', 'transferCurrentManager', 'transferNewManager',
            'transferEffectiveFrom', 'transferReason', 'submitTransferBtn'];
        $collisions = array_intersect($fnMatches[1], $transferIds);
        $this->assertEmpty($collisions, 'Transfer element id(s) collide with a global function name: ' . implode(', ', $collisions));
    }
}
