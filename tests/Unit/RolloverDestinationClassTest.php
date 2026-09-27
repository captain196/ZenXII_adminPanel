<?php

use PHPUnit\Framework\TestCase;

if (!defined('APPPATH')) define('APPPATH', __DIR__ . '/../../application/');
if (!defined('BASEPATH')) define('BASEPATH', 'x');

require_once APPPATH . 'libraries/Entity_firestore_sync.php';

/**
 * ══════════════════════════════════════════════════════════════════════════
 *  A ROLLOVER MUST NEVER MOVE A STUDENT INTO A CLASS THAT DOES NOT EXIST
 * ══════════════════════════════════════════════════════════════════════════
 *
 * ── LC-7 · THE DEFECT (found 2026-08-26) ─────────────────────────────────
 * `School_config::_rolloverNextClass()` sends classOrder 12 to Alumni and
 * every other numeric class to classOrder + 1. The graduation arm is
 * hardcoded to 12 — but classes are CONFIGURED, in `schools.classes`, and a
 * school ending at Class 10 is an ordinary tenant.
 *
 * For such a school the terminal cohort never graduates. It falls into the +1
 * arm and is promoted into the class above the school's last one — a class
 * the school does not configure.
 *
 * The all-or-nothing pre-flight (D21) could not see it. It validates exactly
 * two things:
 *   • capacity — reads a `sections` record that does not exist for an
 *     unconfigured class, so the 40-seat default applies and nothing objects;
 *   • roll collisions — an empty destination has no rolls to collide with.
 * Neither guard asks whether the destination is a real class.
 *
 * MEASURED, NOT ASSUMED: `scripts/graduation_policy_readonly.js` against live
 * data found 2 of 3 tenanted schools terminate below Class 12, one with 12
 * active students in its terminal class.
 *
 * ── WHAT IS ASSERTED HERE, AND WHAT DELIBERATELY IS NOT ──────────────────
 * This does NOT assert that the terminal cohort should graduate. Whether a
 * school ending at Class 10 wants those students graduated, or is opening a
 * Class 11 and wants them promoted, is a school decision that cannot be
 * derived from anything ZenX stores. It is the ONE product decision this
 * work leaves open.
 *
 * What is asserted is the part that IS derivable: whichever the school
 * chooses, moving a student into an unconfigured class is not it.
 *
 * The comparison itself is executable and is tested behaviourally, because
 * the two sides are NOT written the same way — `classes` holds "Nursery" and
 * "Class 10th" while _rolloverNextClass() emits "Class Nursery" and
 * "Class 11th". A raw string comparison would have reported every
 * foundational class as unconfigured and refused every rollover, which is why
 * that specific case is pinned below. The controller wiring is asserted
 * structurally, comment-stripped, with a control.
 */
final class RolloverDestinationClassTest extends TestCase
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
        self::$src = $out;
    }

    /** The comparison the guard performs, isolated. */
    private function isConfigured(array $configuredLabels, string $destinationLabel): bool
    {
        $set = [];
        foreach ($configuredLabels as $c) {
            $n = Entity_firestore_sync::normalizeClassSection((string) $c, '');
            if (($n['className'] ?? '') !== '') $set[mb_strtolower($n['className'])] = true;
        }
        $d = Entity_firestore_sync::normalizeClassSection($destinationLabel, '');
        return isset($set[mb_strtolower((string) ($d['className'] ?? $destinationLabel))]);
    }

    // ── THE COMPARISON ───────────────────────────────────────────────────

    public function test_a_configured_numeric_destination_is_recognised(): void
    {
        $this->assertTrue($this->isConfigured(
            ['Class 9th', 'Class 10th'], 'Class 10th'
        ));
    }

    public function test_the_class_above_the_terminal_class_is_refused(): void
    {
        // The live case: a school ending at Class 10th, its terminal cohort
        // promoted to Class 11th.
        $this->assertFalse($this->isConfigured(
            ['Class 8th', 'Class 9th', 'Class 10th'], 'Class 11th'
        ), 'a school ending at Class 10th accepts Class 11th as a destination — '
         . 'its terminal cohort is moved into a class it does not have');
    }

    public function test_a_foundational_class_survives_the_label_mismatch(): void
    {
        // THE trap. `classes` stores "Nursery"; _rolloverNextClass() emits
        // "Class Nursery". Comparing raw strings would refuse every rollover
        // at every school — a far worse failure than the one being fixed.
        foreach ([['Playgroup', 'Class Playgroup'], ['Nursery', 'Class Nursery'],
                  ['LKG', 'Class LKG'], ['UKG', 'Class UKG']] as [$stored, $emitted]) {
            $this->assertTrue($this->isConfigured([$stored], $emitted),
                "configured '{$stored}' does not match destination '{$emitted}' — "
                . 'the guard would refuse a legitimate rollover');
        }
    }

    public function test_the_comparison_ignores_case_and_ordinal_spelling(): void
    {
        $this->assertTrue($this->isConfigured(['class 10'],   'Class 10th'));
        $this->assertTrue($this->isConfigured(['Class 10th'], 'class 10'));
    }

    public function test_an_unknown_class_name_is_not_silently_accepted(): void
    {
        // "Montessori" has no classOrder. It must not match a configured
        // class merely because both normalise to something non-empty.
        $this->assertFalse($this->isConfigured(['Class 1st'], 'Class Montessori'));
    }

    // ── THE WIRING ───────────────────────────────────────────────────────

    public function test_the_comment_stripper_actually_strips(): void
    {
        // Control: this fix's own comments name everything asserted below.
        $this->assertStringNotContainsString('THE DESTINATION CLASS MUST ACTUALLY EXIST', self::$src);
        $this->assertStringNotContainsString('LC-7', self::$src);
    }

    public function test_the_guard_is_wired_into_the_refusal(): void
    {
        $this->assertStringContainsString('$unconfiguredIssues', self::$src,
            'the destination-class guard is not present');
        $this->assertMatchesRegularExpression(
            '/if\s*\(\s*!empty\(\$capacityIssues\)\s*\|\|\s*!empty\(\$rollIssues\)\s*\|\|\s*!empty\(\$unconfiguredIssues\)\s*\)/',
            self::$src,
            'the guard collects issues but the refusal does not consider them — '
            . 'the rollover would proceed anyway'
        );
    }

    public function test_the_guard_runs_before_any_write(): void
    {
        // A check after the first write is not a guard; earlier students are
        // already moved by then. This is D21's whole premise.
        $guardAt = strpos(self::$src, '$unconfiguredIssues');
        $this->assertNotFalse($guardAt);
        // Anchored on `graduatedFrom`, which is written ONLY by the graduation
        // write itself. `students_graduated` looked like the write loop but is
        // also a key in the summary array declared far earlier — the anchor
        // must be unique to the write, or this test passes on position alone.
        $writeAt = strpos(self::$src, "'graduatedFrom'");
        $this->assertNotFalse($writeAt, 'the rollover graduation write moved');
        $this->assertLessThan($writeAt, $guardAt,
            'the destination-class guard runs after students are already being moved');
    }

    public function test_the_library_is_required_before_its_statics_are_used(): void
    {
        // Entity_firestore_sync is not autoloaded. Academic.php require_once's
        // it before every static call for exactly this reason; without it the
        // guard is a fatal Error that only appears on a real rollover.
        $reqAt   = strpos(self::$src, "libraries/Entity_firestore_sync.php");
        $useAt   = strpos(self::$src, 'Entity_firestore_sync::normalizeClassSection');
        $this->assertNotFalse($reqAt, 'the library is never required — the guard is a fatal Error');
        $this->assertNotFalse($useAt);
        $this->assertLessThan($useAt, $reqAt, 'the library is required after it is used');
    }

    public function test_an_unconfigured_class_list_fails_open(): void
    {
        // A school that has never configured `classes` must not become newly
        // unable to roll over, and an unreadable school doc must not look like
        // a policy refusal. Mirrors this file's fail-open-on-absence idiom.
        $this->assertMatchesRegularExpression(
            '/if\s*\(\s*!empty\(\$cfgClassesRaw\)\s*\)/', self::$src,
            'the guard runs even with no configured classes — every destination '
            . 'would be unconfigured and every rollover refused'
        );
    }

    public function test_the_refusal_is_audited_as_its_own_cause(): void
    {
        // A refusal recorded as a capacity problem sends the operator to the
        // wrong screen.
        $this->assertStringContainsString("'unconfiguredIssues'", self::$src,
            'the audit record does not distinguish this refusal from a capacity one');
        $this->assertStringContainsString('unconfigured_issues=%d', self::$src,
            'the blocked-rollover log line does not count this issue class');
    }

    public function test_the_operator_is_told_what_to_do(): void
    {
        // A refusal with no remedy is just a wall. Both branches are named
        // because the guard deliberately does not choose between them.
        $this->assertStringContainsString('Unconfigured destination:', self::$src);
        $this->assertStringContainsString('Classes & Sections', self::$src,
            'the refusal does not tell the operator where to add the class');
    }
}
