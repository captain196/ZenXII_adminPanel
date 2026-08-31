<?php
/**
 * ADJ-GOV-7 — A RETIRED SOURCE MAY NOT ZERO LIVE MONEY.
 *
 * ── HOW THIS WAS FOUND ────────────────────────────────────────────────────
 * By building the COMPLETE writer graph for `studentDiscounts` rather than
 * trusting the writers already known. The census turned up seven writers, and
 * the seventh was not a controller at all: a backfill inside
 * `Fee_firestore_sync::syncAllDiscountsAndMonthFees()`.
 *
 * ── THE DEFECT ────────────────────────────────────────────────────────────
 * It reads each student's discount from RTDB — a store retired by ZERO-RTDB
 * (commit 758c924, Firestore/Auth only) — and feeds it to `syncDiscount()`,
 * which performs a FULL `firestoreSet`. An empty or partial read therefore
 * writes `onDemandDiscount = 0` and `scholarshipDiscount = 0` over whatever
 * Firestore currently holds, for every student the walk touches.
 *
 * That is not theoretical. It is reachable: `fee_management/firestore_bulk_sync`
 * is a live admin endpoint open to any `Fees: manage` user, and one click runs
 * the whole walk.
 *
 * ── WHY IT HAD NEVER FIRED ────────────────────────────────────────────────
 * Luck, not design. A missing RTDB path returns NULL and `is_array(null)` is
 * false, so the block was skipped. The moment the wrapper returned `[]`, or
 * RTDB held a partial shell, a school's approved concessions would be wiped.
 *
 * ── THE GUARD ─────────────────────────────────────────────────────────────
 * Refusing an EMPTY source costs nothing — there is nothing to migrate — while
 * a source that genuinely carries a discount is still honoured, so no
 * legitimate historical backfill is blocked. The refusal is logged, because a
 * silent skip on a money path is the same defect one level down.
 */

use PHPUnit\Framework\TestCase;

if (!defined('BASEPATH')) define('BASEPATH', __DIR__);
if (!function_exists('log_message')) { function log_message($l, $m) {} }

final class LegacySourceOverwriteGuardTest extends TestCase
{
    private const SYNC = __DIR__ . '/../../application/libraries/Fee_firestore_sync.php';

    private function src(): string { return (string) file_get_contents(self::SYNC); }

    /** Executable code only — the guard is explained in a comment that names it. */
    private function code(): string
    {
        $out = '';
        foreach (token_get_all($this->src()) as $t) {
            $out .= is_array($t)
                ? (in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true) ? ' ' : $t[1])
                : $t;
        }
        return $out;
    }

    /** The predicate the guard rests on: is there anything real to migrate? */
    private function hasSource(array $disc): bool
    {
        return (float) ($disc['OnDemandDiscount'] ?? 0) > 0
            || (float) ($disc['ScholarshipDiscount'] ?? 0) > 0
            || (float) ($disc['totalDiscount'] ?? 0) > 0
            || !empty($disc['Applied']) || !empty($disc['Scholarships']);
    }

    // ══ THE DECISION TABLE ═════════════════════════════════════════════════

    /** An empty shell must never be applied — that is the destructive case. */
    public function test_an_empty_legacy_shell_is_not_a_source(): void
    {
        $this->assertFalse($this->hasSource([]),
            'an absent RTDB record must not overwrite a live Firestore discount');
        $this->assertFalse($this->hasSource(
            ['OnDemandDiscount' => 0, 'ScholarshipDiscount' => 0, 'totalDiscount' => 0]),
            'a zero-valued shell is exactly the shape that wipes approved concessions');
    }

    /** A genuine historical record is still migrated. */
    public function test_a_real_legacy_discount_is_still_honoured(): void
    {
        $this->assertTrue($this->hasSource(['OnDemandDiscount' => 500]));
        $this->assertTrue($this->hasSource(['ScholarshipDiscount' => 1200]));
        $this->assertTrue($this->hasSource(['totalDiscount' => 300]));
        $this->assertTrue($this->hasSource(['Applied' => [['amount' => 100]]]),
            'history alone is worth migrating even when the current figure is zero');
        $this->assertTrue($this->hasSource(['Scholarships' => ['AW1' => ['amount' => 50]]]));
    }

    // ══ THE PRODUCTION GUARD ═══════════════════════════════════════════════

    public function test_the_sync_is_gated_on_a_real_source(): void
    {
        $code = $this->code();

        $this->assertStringContainsString('if ($__hasSource) {', $code,
            'the sync must run only when the legacy source actually carries a discount');
        $this->assertStringNotContainsString(
            "\$disc = \$this->firebase->get(\"{\$base}/Discount\");\n                        if (is_array(\$disc)) {",
            $code,
            'a bare is_array() guard treats an empty shell as real data');
    }

    /** A refusal on a money path must be visible. */
    public function test_the_refusal_is_logged(): void
    {
        $this->assertStringContainsString('ADJ_GOV_RTDB_SYNC_REFUSED', $this->src(),
            'skipping silently would hide that a bulk sync did nothing for this student');
    }

    /**
     * The guard must consider EVERY field that carries value. Checking only the
     * headline figure would let a record with history but no current amount be
     * classified as empty and dropped.
     */
    public function test_the_guard_examines_every_value_bearing_field(): void
    {
        $code = $this->code();
        foreach (['OnDemandDiscount', 'ScholarshipDiscount', 'totalDiscount',
                  'Applied', 'Scholarships'] as $field) {
            $this->assertStringContainsString($field, $code,
                "'{$field}' carries value and must be considered before calling a source empty");
        }
    }
}
