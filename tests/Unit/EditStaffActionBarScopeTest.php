<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * IA-D1 — the edit-staff action bar had become a junk drawer.
 *
 * Nine controls sat in one sticky, NON-WRAPPING row:
 *
 *   Cancel · Record Promotion · Transfer · Notice Period · Clearance ·
 *   Onboarding · Exit Documents · Exit Summary · Save Changes
 *
 * Two things were wrong. Seven of those are not form actions at all — each
 * opens its own workflow and writes its own record, independent of "Save
 * Changes" — so putting them in the form's action bar implied they were part
 * of saving. And it pushed the primary action, Save, to the end of seven
 * unrelated controls.
 *
 * The row is `position: sticky`, so overflow there is not cosmetic: anything
 * running past the viewport width becomes unreachable rather than merely ugly.
 *
 * Reported by the operator, not by this suite — every endpoint test passed
 * because the page returns 200 and the handlers were always correctly wired.
 * A screen can be entirely functional and still be unusable.
 *
 * The row was later renamed `nsa-wizard-footer` when Edit Staff became a
 * wizard (IA-D2). Same sticky row, same job — navigation plus the save
 * action — so this test follows it rather than being retired.
 */
final class EditStaffActionBarScopeTest extends TestCase
{
    private static string $view = '';

    /** The workflows that must live OUTSIDE the form action bar. */
    private const WORKFLOWS = [
        'openOnboardingModal',
        'openPromotionModal',
        'openTransferModal',
        'openNoticeModal',
        'openClearanceModal',
        'openExitDocsModal',
        'openExitSummaryModal',
    ];

    public static function setUpBeforeClass(): void
    {
        self::$view = (string) file_get_contents(
            __DIR__ . '/../../application/views/edit_staff.php');
    }

    public static function tearDownAfterClass(): void
    {
        self::$view = '';
    }

    /** The markup of the action bar, and nothing else. */
    private function actionBar(): string
    {
        $i = strpos(self::$view, '<div class="nsa-wizard-footer">');
        $this->assertNotFalse($i, 'the persistent footer has moved or been renamed');

        $j = strpos(self::$view, '</div>', (int) $i);
        // walk to the closing tag of the bar itself, not the first inner one
        $depth = 0;
        $k = (int) $i;
        $len = strlen(self::$view);
        while ($k < $len) {
            $open  = strpos(self::$view, '<div', $k);
            $close = strpos(self::$view, '</div>', $k);
            if ($close === false) break;
            if ($open !== false && $open < $close) { $depth++; $k = $open + 4; continue; }
            $depth--;
            $k = $close + 6;
            if ($depth === 0) return substr(self::$view, (int) $i, $k - (int) $i);
        }
        return substr(self::$view, (int) $i, ($j === false ? 900 : $j - (int) $i));
    }

    /** DEFECT IA-D1: the action bar carries form actions only. */
    public function test_the_action_bar_holds_only_form_actions(): void
    {
        $bar = $this->actionBar();

        $strays = [];
        foreach (self::WORKFLOWS as $w) {
            if (strpos($bar, $w) !== false) $strays[] = $w;
        }

        $this->assertSame([], $strays,
            "these are separate workflows, not form actions, and must not sit in the "
            . "form's action bar:\n  " . implode("\n  ", $strays));

        // The two that DO belong.
        $this->assertStringContainsString('updateStaffBtn', $bar, 'Save Changes must stay');
        $this->assertStringContainsString('editBackBtn', $bar, 'step navigation belongs here');
    }

    /** Save must not be buried: it is the last control, and the only primary one. */
    public function test_save_is_the_single_primary_action(): void
    {
        $bar = $this->actionBar();

        $this->assertSame(1, substr_count($bar, 'nsa-btn-primary'),
            'exactly one primary action belongs in a form action bar');

        // Back, Next, Save. Navigation belongs here; workflows do not.
        $this->assertLessThanOrEqual(3, substr_count($bar, '<button'),
            'the footer is accumulating controls again');
    }

    /** A sticky row that cannot wrap puts controls out of reach. */
    public function test_the_sticky_action_bar_wraps_rather_than_overflows(): void
    {
        $i = strpos(self::$view, '.nsa-wizard-footer {');
        $this->assertNotFalse($i, '.nsa-wizard-footer styles have moved');

        $css = substr(self::$view, (int) $i, 600);

        $this->assertStringContainsString('position: sticky', $css,
            'if it is no longer sticky, re-check whether wrapping is still required');
        $this->assertStringContainsString('flex-wrap: wrap', $css,
            'a sticky non-wrapping flex row can push controls beyond the viewport');
    }

    /** Every workflow is still reachable — moved, not dropped. */
    public function test_every_workflow_is_still_reachable_exactly_once(): void
    {
        foreach (self::WORKFLOWS as $w) {
            $this->assertSame(1, substr_count(self::$view, 'id="' . $w . 'Btn"'),
                "{$w} must have exactly one trigger");
            $this->assertStringContainsString("function {$w}(", self::$view,
                "{$w} has a button but no handler");
        }
    }

    /** They are grouped and labelled, not just relocated into another pile. */
    public function test_the_workflows_are_grouped_and_explained(): void
    {
        $this->assertStringContainsString('id="sec-lifecycle"', self::$view);
        $this->assertStringContainsString('Employee Lifecycle', self::$view);

        // Each carries its own description, so the operator can tell them apart.
        $this->assertSame(count(self::WORKFLOWS),
            substr_count(self::$view, 'class="nsa-lifecycle-sub"'),
            'every lifecycle action needs a one-line description');

        // And the screen says these save independently of the form.
        $this->assertStringContainsString('do not need to press Save Changes', self::$view);
    }
}
