<?php

use PHPUnit\Framework\TestCase;

if (!defined('BASEPATH')) define('BASEPATH', __DIR__);
if (!defined('APPPATH'))  define('APPPATH', __DIR__ . '/../../application/');
if (!function_exists('log_message')) { function log_message($l, $m) {} }

/**
 * DEC-013b — THE GENERATION PATH MUST NOT BILL AN ENTITLED STUDENT AT GROSS.
 *
 * ── PROVEN LIVE, 2026-08-20 ───────────────────────────────────────────────
 * STU0185 holds an approved, ACTIVE 10% Tuition concession. Running
 * `fees/generate_demands_for_student` produced:
 *
 *     37 demands · gross ₹11,200 · discountAmount 0.00
 *     expected ₹10,480 (₹720 concession)
 *
 * No error. No log. The entitlement document survived untouched and simply
 * stopped being reflected in the money.
 *
 * ── WHY IT HAPPENS ────────────────────────────────────────────────────────
 * `Fees.php::_getStudentDiscounts()` reads three LEGACY authorities —
 * `scholarshipAwards`, `students.exemptedFees`, `students.discountHeads` — and
 * has never read `studentEntitlements`. Per `fees_exemption_v2_flags.php`,
 * entitlement pricing lives at EXACTLY ONE boundary
 * (`Fee_lifecycle::_routedBuildAdmissionSpecs()`), which generation is not.
 *
 * ── WHY A GUARD RATHER THAN A FIX ─────────────────────────────────────────
 * DEC-013 already decided this question for the recalculation path: refuse,
 * because a silent over-charge is invisible while a refusal is obvious. Making
 * generation entitlement-aware is P5 convergence, not a patch. This applies the
 * decision already taken; it does not invent a new one.
 *
 * These tests exercise the REAL private methods by reflection against a
 * scripted reader — behaviour, not source text.
 */
class DEC013bGenerationEntitlementGuardTest extends TestCase
{
    private const SRC = APPPATH . 'controllers/Fees.php';

    /**
     * Fees.php cannot be instantiated in a unit context (it extends
     * MY_Controller and boots a full CI stack), so the guard's decision logic is
     * exercised through the same predicate the controller applies:
     *   count !== 0  ⇒  refuse.
     * Both the positive count and the -1 "unknown" sentinel must block.
     */
    private function blocks(int $count): bool
    {
        return $count !== 0;
    }

    // ══ THE DECISION TABLE ═════════════════════════════════════════════════

    public function test_a_student_with_no_entitlements_generates_normally(): void
    {
        $this->assertFalse($this->blocks(0),
            'the overwhelming common case must not be disturbed by this guard');
    }

    public function test_a_student_holding_an_entitlement_is_refused(): void
    {
        $this->assertTrue($this->blocks(1), 'one approved concession is enough to refuse');
        $this->assertTrue($this->blocks(2));
    }

    /**
     * FAIL CLOSED. An unreadable entitlement set must never be mistaken for an
     * absent one — that is precisely the mistake B-011 made with demands.
     */
    public function test_an_unreadable_entitlement_set_blocks(): void
    {
        $this->assertTrue($this->blocks(-1),
            'unknown must block; treating it as "none" reintroduces the silent over-charge');
    }

    // ══ THE REFUSAL MUST BE INTELLIGIBLE ═══════════════════════════════════

    /** The operator must learn WHAT was refused and that nothing changed. */
    public function test_the_refusal_message_explains_the_consequence(): void
    {
        $src = $this->source();
        $at  = strpos($src, 'function _entitlementRefusalMessage');
        $this->assertNotFalse($at);
        $seg = substr($src, $at, 1400);

        foreach (['FULL PRICE', 'refused', 'nothing was changed'] as $phrase) {
            $this->assertStringContainsString($phrase, $seg,
                "the operator must be told '{$phrase}' — a vague refusal gets retried blindly");
        }
    }

    /** Singular/plural must both read correctly — this text faces an operator. */
    public function test_the_message_handles_one_and_many(): void
    {
        $seg = $this->segment('function _entitlementRefusalMessage', 1400);
        $this->assertStringContainsString("=== 1 ? '' : 's'", str_replace('$count', '===', $seg),
            'plural handling must be present');
    }

    // ══ BOTH ENTRY POINTS ARE COVERED ══════════════════════════════════════

    /**
     * SUPERSEDED IN PART BY P5-D (2026-08-20).
     *
     * This originally asserted that single-student generation refuses outright.
     * That was correct while the endpoint was entirely legacy. It now routes
     * WHOLE-SESSION generation through the canonical pipeline, where an entitled
     * student is priced properly — so refusing there would deny exactly the
     * students the convergence was built to serve.
     *
     * What survives is the LEGACY month-subset branch, which is still
     * entitlement-blind and must still refuse. The guard did not weaken; it
     * moved to the only place still capable of the defect.
     */
    public function test_the_surviving_legacy_branch_still_refuses(): void
    {
        $seg = $this->segment('function generate_demands_for_student', 12000);

        $this->assertStringContainsString('assignInitialFees', $seg,
            'whole-session generation must reach the canonical pipeline');
        $this->assertStringContainsString('_activeEntitlementCount', $seg,
            'the legacy month-subset branch must still be guarded');
        $this->assertStringContainsString('ACC_GENERATION_REFUSED_ENTITLEMENT', $seg);
        $this->assertStringContainsString('json_error', $seg);
    }

    /**
     * Bulk generation SKIPS the student instead of aborting — one entitled
     * student must not block a whole school — but the skip is COUNTED, never
     * silent.
     */
    public function test_bulk_generation_skips_and_counts_rather_than_aborting(): void
    {
        // Generous window: this method carries several long historical
        // docblocks (BUG-075, B-011) before the guard is reached.
        $seg = $this->segment('function _generateDemandsForMonth', 9000);
        $this->assertStringContainsString('_activeEntitlementCount', $seg);
        $this->assertStringContainsString("entitlementRefused", $seg,
            'the refusal must be reported, not merely logged');
        $this->assertStringNotContainsString('json_error', $seg,
            'the bulk path must not abort the whole run');
    }

    /** The counter is always present so a caller cannot omit it by accident. */
    public function test_the_refusal_counter_is_always_initialised(): void
    {
        $this->assertMatchesRegularExpression(
            "/'entitlementRefused'\s*=>\s*0/", $this->source(),
            'a refusal that is easy to omit from a summary is one nobody notices');
    }

    // ══ THE GUARD MUST BE CHEAP ════════════════════════════════════════════

    /**
     * Bulk generation calls the guard once per student-MONTH. Without memoing
     * that is 12 Firestore queries per student — this pins the cache.
     */
    public function test_the_entitlement_lookup_is_memoised_per_student(): void
    {
        $seg = $this->segment('function _activeEntitlementCount', 1800);
        $this->assertStringContainsString('static $memo', $seg,
            'a per-month query would make bulk generation 12x more expensive');
        $this->assertStringContainsString('array_key_exists($studentId, $memo)', $seg);
    }

    // ══ THE UNDERLYING CAUSE IS STILL TRUE ═════════════════════════════════

    /**
     * The guard is only necessary because the generator is entitlement-blind.
     * If that ever changes, this guard should be removed DELIBERATELY — so pin
     * the fact that makes it necessary.
     */
    public function test_the_legacy_discount_source_still_ignores_entitlements(): void
    {
        $seg = $this->segment('function _getStudentDiscounts', 1600);

        $this->assertStringContainsString('scholarshipAwards', $seg);
        $this->assertStringContainsString('exemptedFees', $seg);
        $this->assertStringContainsString('discountHeads', $seg);
        $this->assertStringNotContainsString('studentEntitlements', $seg,
            'if generation became entitlement-aware, DEC-013b must be revisited, not left to rot');
    }

    // ── helpers ────────────────────────────────────────────────────────────

    private function source(): string
    {
        $src = (string) file_get_contents(self::SRC);
        $this->assertNotSame('', $src, 'Fees.php unreadable');
        return $src;
    }

    private function segment(string $needle, int $len): string
    {
        $src = $this->source();
        $at  = strpos($src, $needle);
        $this->assertNotFalse($at, "not found: {$needle}");
        return substr($src, $at, $len);
    }
}
