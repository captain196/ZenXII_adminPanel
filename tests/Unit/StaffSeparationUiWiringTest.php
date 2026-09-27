<?php
namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Staff Separation UI wiring — closes a gap found during the Staff Lifecycle
 * completeness audit: Staff::set_status() has always accepted
 * separation_type/exit_date/last_working_date on an Inactive transition, but
 * the only admin-panel control (all_staff.php) was a bare window.prompt()
 * that submitted none of them — so separationType always defaulted to
 * 'Other' in practice. Source-regression tests only (view/JS files, no
 * Firestore-backed logic to behaviorally test).
 */
class StaffSeparationUiWiringTest extends TestCase
{
    private string $allStaffSrc;
    private string $modalSrc;

    protected function setUp(): void
    {
        $this->allStaffSrc = file_get_contents(__DIR__ . '/../../application/views/all_staff.php');
        $this->modalSrc    = file_get_contents(__DIR__ . '/../../application/views/partials/staff_separation_modal.php');
        $this->assertNotFalse($this->allStaffSrc);
        $this->assertNotFalse($this->modalSrc);
    }

    public function test_all_staff_loads_the_separation_modal_partial(): void
    {
        $this->assertStringContainsString("\$this->load->view('partials/staff_separation_modal'", $this->allStaffSrc);
    }

    public function test_deactivate_no_longer_uses_bare_window_prompt(): void
    {
        // Reactivate (Active) legitimately keeps its own simple prompt —
        // only Inactive must route through the modal.
        $this->assertStringContainsString("window.__staffSeparationModal.open(userId, staffName", $this->allStaffSrc);
        $this->assertStringContainsString("if (targetStatus === 'Inactive') {", $this->allStaffSrc);
    }

    public function test_modal_submits_all_three_previously_missing_fields(): void
    {
        foreach (["body.set('separation_type'", "body.set('last_working_date'", "body.set('exit_date'"] as $needle) {
            $this->assertStringContainsString($needle, $this->modalSrc);
        }
    }

    public function test_modal_always_forces_inactive_status(): void
    {
        $this->assertStringContainsString("body.set('status', 'Inactive');", $this->modalSrc);
    }

    public function test_modal_posts_to_existing_set_status_endpoint_not_a_new_one(): void
    {
        $this->assertStringContainsString("staff/set_status/", $this->modalSrc);
        $this->assertStringNotContainsString('staff/separate', $this->modalSrc);
        $this->assertStringNotContainsString('staff/deactivate', $this->modalSrc);
    }

    public function test_modal_defaults_dates_to_today_not_required(): void
    {
        $this->assertStringContainsString('function todayISO()', $this->modalSrc);
        $this->assertStringContainsString("lastWork.value = todayISO();", $this->modalSrc);
        $this->assertStringContainsString("exitDate.value = todayISO();", $this->modalSrc);
    }

    public function test_separation_type_offers_the_documented_enum_values(): void
    {
        foreach (['Resignation', 'Termination', 'Retirement', 'Contract Expiry', 'Other'] as $val) {
            $this->assertStringContainsString('>' . $val . '<', $this->modalSrc);
        }
    }
}
