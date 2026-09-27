<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * IA-D2 — Edit Staff now uses the same wizard shape as Add Staff.
 *
 * The two screens edit the same entity and looked nothing alike: Add Staff was
 * an eight-step wizard, Edit Staff a ten-section scroll with its own sidebar
 * jump-nav. The sidebar had also drifted — it never listed Statutory ID, and
 * could not list Employee Lifecycle.
 *
 * ONE DELIBERATE DIFFERENCE, asserted here so it is not "corrected" later:
 * every step in EDIT mode is unlocked from the start, and Save Changes is
 * reachable on every step. Add Staff gates progress because it is assembling a
 * record that does not exist yet; here the record already exists and is already
 * valid, so gating would stand between an admin and the one field they came to
 * change. A wizard that makes fixing a phone number a seven-click journey is
 * worse than the long form it replaced.
 */
final class EditStaffWizardParityTest extends TestCase
{
    private static string $edit = '';
    private static string $add  = '';

    /** Section id => step index. Two pairs deliberately share a step. */
    private const SECTION_STEPS = [
        'sec-basic'         => 0,
        'sec-guardian'      => 1,
        'sec-statutory'     => 2,
        'sec-address'       => 3,
        'sec-qualification' => 4,
        'sec-bank'          => 5,
        'sec-salary'        => 5,
        'sec-docs'          => 6,
        'sec-hr-docs'       => 6,
        'sec-lifecycle'     => 7,
    ];

    public static function setUpBeforeClass(): void
    {
        $root = __DIR__ . '/../../application/views/';
        self::$edit = (string) file_get_contents($root . 'edit_staff.php');
        self::$add  = (string) file_get_contents($root . 'new_staff.php');
    }

    public static function tearDownAfterClass(): void
    {
        self::$edit = self::$add = '';
    }

    /** Every section is a step panel, and none was dropped in the conversion. */
    public function test_every_section_is_a_step_panel(): void
    {
        foreach (self::SECTION_STEPS as $id => $step) {
            $this->assertMatchesRegularExpression(
                '~class="nsa-section nsa-step-panel" id="' . preg_quote($id, '~') . '" data-step="' . $step . '"~',
                self::$edit,
                "{$id} must be a step panel on step {$step}");
        }

        // No section left behind as a plain scrolling block.
        $this->assertSame(0, preg_match_all('~class="nsa-section" id="sec-~', self::$edit),
            'a section was missed and will never be shown');
    }

    /** The chrome matches Add Staff: stepper, progress bar, sticky footer. */
    public function test_it_uses_the_same_wizard_chrome_as_add_staff(): void
    {
        foreach (['nsa-stepper', 'nsa-stepper-progress-bar', 'nsa-step-panel', 'nsa-wizard-footer'] as $cls) {
            $this->assertStringContainsString($cls, self::$add,  "Add Staff no longer uses {$cls}");
            $this->assertStringContainsString($cls, self::$edit, "Edit Staff is missing {$cls}");
        }

        foreach (['editStepper', 'editProgressBar', 'editBackBtn', 'editNextBtn', 'editFooterStatus'] as $id) {
            $this->assertStringContainsString('id="' . $id . '"', self::$edit);
        }
    }

    /** The duplicate navigator is gone — markup, not merely hidden. */
    public function test_the_sidebar_jump_nav_was_removed(): void
    {
        $this->assertStringNotContainsString('<aside class="nsa-sidebar"', self::$edit,
            'two navigators for the same sections is the duplication this replaced');
        $this->assertStringNotContainsString('class="nsa-nav-item', self::$edit);
    }

    /** THE DELIBERATE DIFFERENCE: edit mode never locks a step. */
    public function test_edit_mode_unlocks_every_step(): void
    {
        // Add Staff gates; Edit Staff must not inherit that gate.
        $this->assertStringContainsString('wizardFurthestUnlocked', self::$add,
            'Add Staff should still gate progress while creating a record');

        $this->assertStringNotContainsString('editFurthestUnlocked', self::$edit,
            'edit mode must not gate: the record already exists and is already valid');

        // Every step registers a click handler unconditionally.
        $i = strpos(self::$edit, 'function renderEditStepper');
        $this->assertNotFalse($i);
        $body = substr(self::$edit, (int) $i, 2200);

        $this->assertStringContainsString("el.addEventListener('click'", $body);
        $this->assertStringNotContainsString('if (i <= ', $body,
            'a conditional click handler would re-introduce step gating');
    }

    /** Save must be reachable from any step, not parked on a final one. */
    public function test_save_is_available_on_every_step(): void
    {
        $i = strpos(self::$edit, 'class="nsa-wizard-footer"');
        $this->assertNotFalse($i, 'the wizard footer is missing');

        $footer = substr(self::$edit, (int) $i, 1400);
        $this->assertStringContainsString('updateStaffBtn', $footer,
            'Save Changes belongs in the persistent footer, not inside a step');

        // And it is not hidden/disabled until some final step.
        $this->assertStringNotContainsString('editCreateBtn', self::$edit,
            'edit mode has no terminal "create" action to wait for');
    }

    /**
     * The failure this conversion could have introduced: validateEditForm()
     * flags a field and returns false, but the field sits on a hidden step, so
     * the operator sees no error and no save — a silent dead end.
     */
    public function test_a_validation_failure_reveals_the_offending_step(): void
    {
        $this->assertStringContainsString(
            'if (!validateEditForm()) { editRevealFirstError(); return; }', self::$edit,
            'a failed save must move the operator to the field that failed');

        $i = strpos(self::$edit, 'function editRevealFirstError');
        $this->assertNotFalse($i);
        $body = substr(self::$edit, (int) $i, 900);

        $this->assertStringContainsString(".querySelector('.has-error')", $body);
        $this->assertStringContainsString('editShowStep(step)', $body,
            'it must switch to the step holding the error, not just scroll');
        $this->assertStringContainsString('scrollIntoView', $body);
    }

    /** Steps carrying an error are marked, so the stepper says where to look. */
    public function test_the_stepper_marks_steps_that_hold_errors(): void
    {
        $this->assertStringContainsString('has-error', self::$edit);

        $i = strpos(self::$edit, 'function renderEditStepper');
        $body = substr(self::$edit, (int) $i, 2200);

        $this->assertStringContainsString("document.querySelectorAll('.has-error')", $body,
            'the stepper must reflect which steps failed validation');
    }

    /** Deep links into a section still work now that sections can be hidden. */
    public function test_a_deep_link_opens_the_step_that_holds_the_section(): void
    {
        $this->assertStringContainsString('window.location.hash', self::$edit);
        $this->assertStringContainsString(
            ".nsa-step-panel' + window.location.hash", self::$edit,
            'an anchor must open its step rather than scroll to a hidden node');
    }
}
