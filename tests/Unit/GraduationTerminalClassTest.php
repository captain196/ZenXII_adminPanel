<?php

use PHPUnit\Framework\TestCase;

if (!defined('APPPATH')) define('APPPATH', __DIR__ . '/../../application/');
if (!defined('BASEPATH')) define('BASEPATH', 'x');

require_once APPPATH . 'libraries/Entity_firestore_sync.php';

/**
 * ══════════════════════════════════════════════════════════════════════════
 *  COMPLETION HAPPENS AT THE SCHOOL'S LAST CLASS, NOT AT CLASS 12
 * ══════════════════════════════════════════════════════════════════════════
 *
 * ── LC-11 · THE DEFECT ───────────────────────────────────────────────────
 * `_rolloverNextClass()` graduated at a hardcoded classOrder 12. Classes are
 * CONFIGURED per school, and on live data 2 of 3 tenanted schools terminate
 * below Class 12 — one with 12 active students in its terminal class. For
 * those schools nobody ever completed schooling; the terminal cohort fell
 * into the +1 arm and was promoted into a class the school does not have.
 *
 * ── WHY THIS IS DERIVED, NOT INVENTED ────────────────────────────────────
 * UDISE+ — the Government of India system these schools must already file
 * into — records three exits ("Left with TC", "Dropout", "Passed Out") and
 * applies "Passed Out" at the school's OWN terminal class, naming Class 10
 * alongside Class 12. It does not hardcode a grade. Mature SIS products draw
 * the same line: graduation is a distinct terminal exit, not a withdrawal.
 *
 * ZenX already had the model — `status: 'Alumni'` plus `graduatedFrom`. Only
 * the constant was wrong.
 *
 * ── LC-10 · THE PRECONDITION ─────────────────────────────────────────────
 * `_rolloverNextClass()`'s docblock says it was extracted so the pre-flight
 * and the write loop resolve a destination through ONE implementation, and
 * warns that otherwise validation would be "worthless while looking correct".
 * The extraction happened; the write loop was never converted and still
 * carried a second copy of the whole table.
 *
 * The copies agreed, so nothing was broken — but LC-11 is exactly the change
 * that would have altered one and not the other, so unifying them had to come
 * first. That ordering is asserted here, because it is the part a future
 * change is most likely to undo.
 */
final class GraduationTerminalClassTest extends TestCase
{
    private static string $src = '';

    public static function setUpBeforeClass(): void
    {
        $out = '';
        foreach (token_get_all((string) file_get_contents(APPPATH . 'controllers/School_config.php')) as $t) {
            if (is_array($t)) {
                if (in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true)) continue;
                $out .= $t[1];
            } else { $out .= $t; }
        }
        // Comment removal leaves the newlines behind; collapse them so byte
        // windows measured from an anchor stay meaningful.
        self::$src = (string) preg_replace('/\s+/', ' ', $out);
    }

    /** The terminal-class derivation, reproduced exactly, so it is executable. */
    private function terminalOrderOf(array $configuredLabels): int
    {
        $max = null;
        foreach ($configuredLabels as $label) {
            if (trim((string) $label) === '') continue;
            $n = Entity_firestore_sync::normalizeClassSection((string) $label, '');
            $ord = $n['classOrder'] ?? null;
            if ($ord === null || !is_numeric($ord)) continue;
            if ($max === null || (int) $ord > $max) $max = (int) $ord;
        }
        return $max === null ? 12 : $max;
    }

    // ── THE DERIVATION ───────────────────────────────────────────────────

    public function test_a_school_ending_at_class_10_terminates_at_10(): void
    {
        // The live case: SCH_B56BB9A401 and SCH_218AAF5C23 both end here.
        $this->assertSame(10, $this->terminalOrderOf([
            'Playgroup', 'Nursery', 'LKG', 'UKG', 'Class 1st', 'Class 5th',
            'Class 9th', 'Class 10th',
        ]));
    }

    public function test_a_school_ending_at_class_12_still_terminates_at_12(): void
    {
        // The pre-existing behaviour must be preserved exactly for the schools
        // the old constant was right about.
        $this->assertSame(12, $this->terminalOrderOf([
            'Nursery', 'Class 1st', 'Class 10th', 'Class 11th', 'Class 12th',
        ]));
    }

    public function test_a_primary_school_terminates_at_its_own_last_class(): void
    {
        $this->assertSame(5, $this->terminalOrderOf(['Nursery', 'Class 1st', 'Class 5th']));
    }

    public function test_order_is_not_taken_from_list_position(): void
    {
        // A configuration listed out of order must not change the answer —
        // this is a max over classOrder, not "the last entry".
        $this->assertSame(10, $this->terminalOrderOf([
            'Class 10th', 'Class 1st', 'Nursery', 'Class 5th',
        ]));
    }

    public function test_an_unorderable_class_cannot_become_the_terminal_class(): void
    {
        // "Montessori" has no classOrder. If it were treated as orderable it
        // could displace the real terminal class and graduate the wrong cohort.
        $this->assertSame(5, $this->terminalOrderOf(['Class 1st', 'Class 5th', 'Class Montessori']));
    }

    public function test_a_foundational_only_school_does_not_fall_back_to_12(): void
    {
        // A pre-primary ending at UKG terminates at UKG (-1), not at 12.
        // Falling back would promote UKG into Class 1st forever and graduate
        // nobody — the very defect, in the smallest school.
        $this->assertSame(-1, $this->terminalOrderOf(['Playgroup', 'Nursery', 'LKG', 'UKG']));
    }

    public function test_no_configured_classes_falls_back_to_the_old_constant(): void
    {
        // Fail-safe. A tenant with no class list must behave exactly as it did
        // before LC-11 — never graduate its whole roll because a read returned
        // nothing.
        $this->assertSame(12, $this->terminalOrderOf([]));
        $this->assertSame(12, $this->terminalOrderOf(['', '   ']));
    }

    // ── THE WIRING ───────────────────────────────────────────────────────

    public function test_the_comment_stripper_actually_strips(): void
    {
        $this->assertStringNotContainsString('COMPLETION HAPPENS AT', self::$src);
        $this->assertStringNotContainsString('LC-11', self::$src);
    }

    public function test_the_graduation_arm_is_no_longer_hardcoded_to_twelve(): void
    {
        $at = strpos(self::$src, 'private function _rolloverNextClass');
        $this->assertNotFalse($at, 'the transition helper moved');
        $window = substr(self::$src, $at, 600);
        $this->assertStringNotContainsString("=== 12", $window,
            'the graduation arm still compares against a hardcoded 12');
        $this->assertStringContainsString('$terminalOrder', $window,
            'the helper does not take the school terminal class');
    }

    public function test_the_helper_defaults_to_twelve(): void
    {
        $this->assertStringContainsString('_rolloverNextClass($order, $terminalOrder = 12)', self::$src,
            'the terminal class no longer defaults to 12 — a caller that cannot '
            . 'resolve the configuration would graduate an unintended cohort');
    }

    public function test_the_terminal_class_is_resolved_from_configuration(): void
    {
        $this->assertStringContainsString('private function _terminalClassOrder', self::$src);
        $at = strpos(self::$src, 'private function _terminalClassOrder');
        $window = substr(self::$src, $at, 900);
        $this->assertStringContainsString("'classes'", $window,
            'the terminal class is not read from the school configuration');
        $this->assertStringContainsString('normalizeClassSection', $window,
            'labels are compared without the canonical normaliser — "Nursery" '
            . 'and "Class Nursery" would not match');
    }

    // ── LC-10: ONE TABLE ─────────────────────────────────────────────────

    public function test_the_write_loop_uses_the_shared_transition_table(): void
    {
        // The whole point. If the write loop resolves its own destination, the
        // pre-flight validates a section the write never touches.
        $this->assertSame(2, substr_count(self::$src, '$this->_rolloverNextClass('),
            'expected exactly two callers of the transition helper — the '
            . 'pre-flight and the write loop. A third caller, or a missing one, '
            . 'means a destination is being resolved somewhere else.');

        // Both callers must PASS the terminal class, not merely call the
        // helper. Found by mutation: dropping the argument at the write loop
        // left it silently defaulting to 12 while the pre-flight used the real
        // terminal class — the pre-flight would then validate a promotion for
        // a cohort the write graduates, which is the precise failure LC-10
        // exists to prevent, and every other assertion here still passed.
        $this->assertSame(2, preg_match_all(
            '/\$this->_rolloverNextClass\([^)]*,\s*\$terminalOrder\s*\)/', self::$src),
            'a caller of the transition helper does not pass $terminalOrder and '
            . 'falls back to the default 12 — the pre-flight and the write loop '
            . 'would disagree about who graduates');
    }

    public function test_the_write_loop_no_longer_carries_its_own_table(): void
    {
        // The helper legitimately owns these maps — that is the surviving
        // single copy. What must not exist is a SECOND definition, which is
        // what the write loop used to carry. So this counts definitions and
        // pins the survivor inside the helper, rather than asserting absence.
        $this->assertSame(1, preg_match_all('/\$foundationalNext\s*=\s*\[/', self::$src),
            'the foundational ladder is defined more than once (or not at all) — '
            . 'the write loop carried a second copy of the transition table');
        $this->assertSame(1, preg_match_all('/\$foundationalLabel\s*=\s*\[/', self::$src),
            'the foundational labels are defined more than once (or not at all)');

        $helperAt = strpos(self::$src, 'private function _rolloverNextClass');
        $mapAt    = strpos(self::$src, '$foundationalNext');
        $nextFn   = strpos(self::$src, 'public function rollover_session');
        $this->assertNotFalse($helperAt);
        $this->assertNotFalse($mapAt);
        $this->assertGreaterThan($helperAt, $mapAt,
            'the foundational ladder sits outside the transition helper');
        $this->assertLessThan($nextFn, $mapAt,
            'the foundational ladder is no longer inside the transition helper');
    }

    public function test_both_callers_share_one_resolved_terminal_class(): void
    {
        // Two resolutions exist by design and are correct: the preview endpoint
        // resolves its own, and rollover_session resolves one that BOTH the
        // pre-flight and the write loop then share. What must never happen is a
        // third — or a second inside rollover_session, which is how the
        // pre-flight and the write loop would come to disagree about who
        // graduates while each looked right on its own.
        $this->assertSame(2, preg_match_all('/\$this->_terminalClassOrder\(\)/', self::$src),
            'the terminal class is resolved somewhere unexpected — expected '
            . 'exactly two call sites (the preview, and once for the rollover)');

        $rollAt = strpos(self::$src, 'public function rollover_session');
        $this->assertNotFalse($rollAt);
        $rolloverBody = substr(self::$src, $rollAt);
        $this->assertSame(1, preg_match_all('/\$this->_terminalClassOrder\(\)/', $rolloverBody),
            'rollover_session resolves the terminal class more than once — the '
            . 'pre-flight and the write loop could then disagree');
    }

    // ── DISCLOSURE ───────────────────────────────────────────────────────

    public function test_the_preview_counts_graduates_by_the_same_rule(): void
    {
        // A preview that disagrees with the action is worse than none: the
        // operator confirms one thing and gets another.
        $at = strpos(self::$src, '$graduating = 0');
        $this->assertNotFalse($at, 'the rollover preview moved');
        $window = substr(self::$src, $at, 500);
        $this->assertStringNotContainsString('$order === 12', $window,
            'the preview still counts graduates as Class 12 while the rollover '
            . 'graduates the terminal class');
        $this->assertStringContainsString('$terminalOrder', $window);
    }

    public function test_the_preview_names_the_graduating_class(): void
    {
        $this->assertStringContainsString("'graduating_class'", self::$src,
            'the preview does not say WHICH class completes, leaving the '
            . 'operator to assume Class 12');
    }

    public function test_the_operator_facing_copy_no_longer_promises_class_12(): void
    {
        $view = (string) file_get_contents(APPPATH . 'views/school_config/index.php');
        $this->assertStringNotContainsString('Class 12 &rarr; Alumni', $view,
            'the rollover checkbox still tells the operator Class 12 graduates');
        $this->assertStringNotContainsString('Class 12 → Alumni', $view,
            'the confirmation dialog still tells the operator Class 12 graduates');
        $this->assertStringNotContainsString('student(s) in Class 12', $view,
            'the preview still labels the graduating cohort as Class 12');
    }
}
