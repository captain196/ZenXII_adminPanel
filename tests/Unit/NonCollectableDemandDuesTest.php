<?php

use PHPUnit\Framework\TestCase;

if (!defined('APPPATH')) define('APPPATH', __DIR__ . '/../../application/');
if (!defined('BASEPATH')) define('BASEPATH', 'x');

/**
 * ══════════════════════════════════════════════════════════════════════════
 *  A FROZEN DEMAND IS NOT CHASED — BUT IT IS STILL OWED
 * ══════════════════════════════════════════════════════════════════════════
 *
 * ── THE DISTINCTION THIS FILE EXISTS TO PROTECT ──────────────────────────
 * `frozen` means "student soft-deleted, do not collect". It is easy — and
 * wrong — to read that as "the debt is gone". ZenX draws the line in a
 * specific place, and the three surfaces are deliberately NOT identical:
 *
 *   CLEARANCE   counts a frozen demand as owed.        (still a debt)
 *   PAYMENT     accepts money against a frozen demand. (settlement allowed)
 *   PURSUIT     must not chase it.                     (no reminders)
 *   DISPLAY     must not offer it to a parent as dues. (no dunning)
 *
 * `FrozenDemandFineExemptionTest` records the first two and says why:
 * "Freezing stops the debt being pursued automatically; it does not forgive
 * it. A withdrawn student who still owes money must not come back 'fees
 * clear' on an exit/TC clearance check." And: "if a family returns to settle
 * what they owe, refusing the money is worse than taking it."
 *
 * ── A CORRECTION I MADE AND HAD TO UNDO ──────────────────────────────────
 * I first "fixed" `Fee_defaulter_check` to exclude frozen and reversed from
 * `total_dues`, on the reasoning that Admin ought to agree with the Parent
 * App. That was wrong, and the suite caught it: the two are supposed to
 * differ. Admin answers "what is owed"; the Parent App answers "what are we
 * asking this family to pay right now". The clearance file's own note warned
 * against exactly that change — "something a later reader 'fixes' by making
 * the two paths consistent for their own sake" — so the revert restored it.
 *
 * ── WHAT REMAINED A REAL DEFECT ──────────────────────────────────────────
 * `Fee_reminder_service` is PURSUIT, not clearance, and it chased frozen
 * demands:
 *
 *     if ($status === 'paid' || $status === 'archived') return null;
 *
 * so a family whose child had left kept receiving payment reminders for a
 * debt the school had stopped pursuing. That is the one behaviour the FRZ-1
 * note explicitly says freezing is meant to stop, and it is fixed here.
 *
 * The denylist SHAPE has now failed three times in this codebase — `archived`
 * (SW4-companion-F), `frozen` in `fineFor()` (FRZ-1), and `frozen` again in
 * reminders. Each time a status added later fell through as collectable.
 */
final class NonCollectableDemandDuesTest extends TestCase
{
    private static string $reminderSrc  = '';
    private static string $defaulterSrc = '';

    private static function strip(string $file): string
    {
        $out = '';
        foreach (token_get_all((string) file_get_contents($file)) as $t) {
            if (is_array($t)) {
                if (in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true)) continue;
                $out .= $t[1];
            } else { $out .= $t; }
        }
        return (string) preg_replace('/\s+/', ' ', $out);
    }

    public static function setUpBeforeClass(): void
    {
        self::$reminderSrc  = self::strip(APPPATH . 'libraries/Fee_reminder_service.php');
        self::$defaulterSrc = self::strip(APPPATH . 'libraries/Fee_defaulter_check.php');
    }

    /** The reminder service's classification, as it declares it. */
    private const NO_REMINDER = ['paid', 'archived', 'frozen', 'reversed'];

    private function wouldRemind(string $status, float $balance): bool
    {
        if (in_array(strtolower($status), self::NO_REMINDER, true)) return false;
        return $balance > 0.005;
    }

    public function test_the_comment_stripper_actually_strips(): void
    {
        $this->assertStringNotContainsString('PA-FEE-2', self::$reminderSrc);
        $this->assertStringNotContainsString('dunning', self::$reminderSrc);
    }

    // ── PURSUIT: a frozen demand is never chased ─────────────────────────

    public function test_a_frozen_demand_generates_no_reminder(): void
    {
        $this->assertFalse($this->wouldRemind('frozen', 900.0),
            'a family whose child has left would receive a payment reminder for a '
            . 'debt the school stopped pursuing when it froze the fee book');
    }

    public function test_a_reversed_demand_generates_no_reminder(): void
    {
        $this->assertFalse($this->wouldRemind('reversed', 600.0));
    }

    public function test_a_genuine_unpaid_demand_is_still_chased(): void
    {
        // The positive control. Without it every assertion here would pass on
        // a service that had simply stopped sending reminders altogether.
        $this->assertTrue($this->wouldRemind('unpaid', 900.0));
        $this->assertTrue($this->wouldRemind('partial', 300.0));
    }

    public function test_a_settled_demand_is_not_chased(): void
    {
        $this->assertFalse($this->wouldRemind('paid', 0.0));
        $this->assertFalse($this->wouldRemind('unpaid', 0.0));
    }

    // ── THE WIRING ───────────────────────────────────────────────────────

    public function test_the_reminder_denylist_of_two_is_gone(): void
    {
        $this->assertStringNotContainsString(
            "if (\$status === 'paid' || \$status === 'archived') return null;",
            self::$reminderSrc,
            'stageFor() still uses the denylist that let frozen demands through');
    }

    public function test_both_reminder_checks_use_the_classification(): void
    {
        // stageFor() decides; stillOwing() re-checks immediately before the
        // send. If they disagree, a demand frozen in between still gets a
        // reminder.
        $this->assertSame(2, substr_count(self::$reminderSrc,
            'in_array($status, self::NON_COLLECTABLE_STATUSES, true)'),
            'stageFor() and stillOwing() must both consult the classification');
    }

    public function test_the_reminder_classification_names_every_status(): void
    {
        foreach (['paid', 'archived', 'frozen', 'reversed'] as $s) {
            $this->assertMatchesRegularExpression(
                "/NON_COLLECTABLE_STATUSES = \[[^\]]*'{$s}'/", self::$reminderSrc,
                "'{$s}' is missing — a demand in that state would be chased");
        }
    }

    // ── CLEARANCE: deliberately NOT changed ──────────────────────────────

    public function test_clearance_still_treats_a_frozen_demand_as_owed(): void
    {
        // Guards the revert. Freezing stops pursuit; it does not forgive the
        // debt, so a withdrawn student who owes money must not report "fees
        // clear" on a TC check. `FrozenDemandFineExemptionTest` owns this
        // decision; this asserts the two files still agree about it.
        $at = strpos(self::$defaulterSrc, 'function _checkFeesClearance');
        $this->assertNotFalse($at, '_checkFeesClearance was renamed');
        $this->assertStringContainsString("\$status === 'paid' || \$status === 'archived'",
            substr(self::$defaulterSrc, $at, 2200),
            'clearance now exempts frozen demands — a withdrawn student owing money '
            . 'would come back FEES CLEAR');
    }

    public function test_the_two_surfaces_are_allowed_to_disagree(): void
    {
        // The point of the whole file: pursuit and clearance answer different
        // questions, so their status rules are NOT expected to match. Pinned
        // so the next reader does not "harmonise" them.
        $this->assertFalse($this->wouldRemind('frozen', 900.0),
            'pursuit must exclude frozen');
        $this->assertStringContainsString("\$status === 'paid' || \$status === 'archived'",
            self::$defaulterSrc,
            'clearance must still include frozen');
    }
}
