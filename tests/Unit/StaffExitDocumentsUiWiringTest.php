<?php
namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Staff HR Advanced Capability Programme — Phase 4 (Exit Documents UI),
 * 2026-08-18.
 *
 * Source-regression tests (the view is HTML+JS — comment-stripping
 * convention matches every other view-touching test in this suite).
 */
class StaffExitDocumentsUiWiringTest extends TestCase
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
        $modalPos = strpos($this->viewSrc, 'id="exitDocsModalOverlay"');
        $this->assertNotFalse($formClosePos);
        $this->assertNotFalse($modalPos);
        $this->assertLessThan($modalPos, $formClosePos);
    }

    public function test_open_button_is_type_button(): void
    {
        $btnPos = strpos($this->viewSrc, 'id="openExitDocsModalBtn"');
        $this->assertNotFalse($btnPos);
        $tagStart = strrpos(substr($this->viewSrc, 0, $btnPos), '<button');
        $tag = substr($this->viewSrc, $tagStart, $btnPos - $tagStart + 40);
        $this->assertStringContainsString('type="button"', $tag);
    }

    public function test_all_three_document_types_offered(): void
    {
        $this->assertStringContainsString("printExitDocument('relieving')", $this->viewSrc);
        $this->assertStringContainsString("printExitDocument('experience')", $this->viewSrc);
        $this->assertStringContainsString("printExitDocument('service')", $this->viewSrc);
    }

    public function test_opens_in_a_new_tab_not_the_current_page(): void
    {
        $this->assertStringContainsString("window.open(", $this->viewSrc);
        $this->assertStringContainsString("'_blank'", $this->viewSrc);
    }

    public function test_links_to_the_correct_backend_endpoint(): void
    {
        $this->assertStringContainsString('base_url("staff/print_exit_document/")', $this->viewSrc);
    }
}
