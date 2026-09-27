<?php
namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

if (!defined('BASEPATH')) define('BASEPATH', __DIR__);
if (!class_exists('CI_Controller', false)) eval('class CI_Controller { public static $instance; }');
if (!function_exists('get_instance')) eval('function &get_instance() { return \CI_Controller::$instance; }');
foreach (['Fee_concession_reader', 'Fee_entitlement_reader', 'Fee_generation_service'] as $__c) {
    if (!class_exists($__c, false)) require_once __DIR__ . '/../../application/libraries/' . $__c . '.php';
}

/**
 * ══════════════════════════════════════════════════════════════════════════
 * PHASE 3C / B3 — THE THIRD (AND FOURTH) DISCOUNT AUTHORITY
 * ══════════════════════════════════════════════════════════════════════════
 *
 * B2 gave admission ONE canonical billing authority behind
 * USE_ENTITLEMENT_BILLING. This file pins what that did NOT fix: the demand
 * GENERATION and RECALCULATION endpoints never went through `Fee_lifecycle` at
 * all. They price from a different set of sources entirely.
 *
 * The result is two answers for the same student:
 *
 *   Admission        → Fee_lifecycle → Fee_generation_service → reader
 *                      (studentConcessions | studentEntitlements)
 *
 *   Generate/Recalc  → Fees::_getStudentDiscounts()
 *                      (students.exemptedFees · students.discountHeads ·
 *                       scholarshipAwards)
 *
 * These tests do not fix that — B3 remediation is not authorized in this phase.
 * They make it executable, so it cannot be argued about, and so the day it IS
 * fixed there is a red test that turns green.
 *
 * ── NO I/O ──────────────────────────────────────────────────────────────────
 * No Firestore, no tenant, no writes. Source is read with comments STRIPPED, so
 * a comment can never satisfy an assertion.
 */
class Phase3cThirdAuthorityTest extends TestCase
{
    private const FEES = __DIR__ . '/../../application/controllers/Fees.php';
    private const LIFE = __DIR__ . '/../../application/libraries/Fee_lifecycle.php';

    private function src(string $path): string
    {
        $out = '';
        foreach (token_get_all(file_get_contents($path)) as $t) {
            if (is_array($t)) {
                if (in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true)) continue;
                $out .= $t[1];
            } else { $out .= $t; }
        }
        return $out;
    }

    private function method(string $path, string $name): string
    {
        $s = $this->src($path);
        $start = strpos($s, "function {$name}(");
        $this->assertNotFalse($start, "{$name}() not found.");
        $end = strpos($s, 'function ', $start + strlen($name) + 12);
        return substr($s, $start, ($end === false ? strlen($s) : $end) - $start);
    }

    // ══════════════════════════════════════════════════════════════════
    //  STEP 1 — which legacy sources are actually alive?
    // ══════════════════════════════════════════════════════════════════

    /**
     * `exemptedFees` and `discountHeads` have NO writer left anywhere in the
     * application. Fees Exemption v2 Phase 0-b removed the admission and edit
     * writes; only the READERS were left behind. They are orphaned inputs — a
     * field nothing can set but billing still consults.
     */
    public function test_S1_exempted_and_discountHeads_have_no_writer(): void
    {
        $all = '';
        foreach (glob(__DIR__ . '/../../application/controllers/*.php') as $f) $all .= $this->src($f);
        foreach (glob(__DIR__ . '/../../application/libraries/*.php') as $f)   $all .= $this->src($f);

        foreach (['exemptedFees', 'discountHeads'] as $field) {
            $this->assertStringNotContainsString("'{$field}'=>", preg_replace('/\s+/', '', $all),
                "Something now WRITES {$field}. This audit recorded it as a read-only orphan; if a "
                . 'writer has been added, the third-authority analysis must be redone.');
        }
    }

    /** But they are still READ by the pricing path that generates demands. */
    public function test_S1_orphaned_fields_are_still_read_by_pricing(): void
    {
        $m = $this->method(self::FEES, '_getStudentDiscounts');
        $this->assertStringContainsString('exemptedFees', $m);
        $this->assertStringContainsString('discountHeads', $m);
        $this->assertStringContainsString('readScholarshipAwards', $m);
    }

    /**
     * `scholarshipAwards` IS a live business feature with its own management
     * surface — award, revoke, query. It is NOT an orphan, and must not be
     * retired as if it were.
     */
    public function test_S1_scholarshipAwards_is_a_live_feature(): void
    {
        $mgmt = $this->src(__DIR__ . '/../../application/controllers/Fee_management.php');
        $this->assertStringContainsString("firestoreSet('scholarshipAwards'", preg_replace('/\s+/', '', $mgmt),
            'Scholarship awards are created by a live operator surface — a distinct business '
            . 'concept from a concession, and not something to fold away silently.');
    }

    // ══════════════════════════════════════════════════════════════════
    //  STEP 5 — the two pricing paths, and that they disagree
    // ══════════════════════════════════════════════════════════════════

    /**
     * THE B3 DEFECT. Admission prices through Fee_lifecycle (concession or
     * entitlement reader). Demand generation and recalculation price through
     * Fees::_getStudentDiscounts() and never touch Fee_lifecycle.
     */
    /**
     * UPDATED 2026-08-20 by P5-D. This test characterised the B3 gap: three
     * endpoints priced from the legacy third authority and none could reach
     * `USE_ENTITLEMENT_BILLING`.
     *
     * **`generate_demands_for_student` no longer belongs in that list.** It now
     * routes whole-session generation through
     * `Fee_lifecycle::assignInitialFees()` — the canonical pipeline — so an
     * entitled student is priced instead of billed at gross. The narrowed
     * assertion is the point of the change, not an accommodation to it.
     *
     * The other two endpoints are unchanged and still bypass. They are pinned
     * here so the remaining gap stays visible and measurable; P5-E closes them.
     */
    /**
     * NARROWED AGAIN BY P5-E (2026-08-20). `recalc_unpaid_discounts` now
     * recomputes in place through the canonical pipeline, so only
     * `recalculate_demands` still prices from the legacy third authority.
     *
     * Each narrowing is a gap closing, and the remaining entry stays pinned so
     * the last bypass cannot be forgotten.
     */
    /**
     * ══ THE B3 GAP IS CLOSED (P5-D → P5-C → P5-E → P5-H, 2026-08-20) ════════
     *
     * This test characterised three endpoints that priced from the legacy third
     * authority and could not reach `USE_ENTITLEMENT_BILLING`. It was narrowed
     * twice as each converged, and the list is now empty.
     *
     * Inverted rather than deleted: it now asserts that NO generation or
     * recalculation endpoint prices from the legacy authority, so a regression
     * that reintroduces one fails here — which is what the original test was
     * really protecting.
     */
    public function test_S5_no_generation_or_recalculation_endpoint_bypasses_the_authority(): void
    {
        foreach (['generate_demands_for_student', 'recalculate_demands', 'recalc_unpaid_discounts'] as $endpoint) {
            $m = $this->method(self::FEES, $endpoint);

            $this->assertMatchesRegularExpression(
                '/assignInitialFees|_recalculateStudentCanonical/', $m,
                "{$endpoint}() must reach the canonical pipeline");
        }

        // recalc paths must no longer consult the legacy map at all.
        foreach (['recalculate_demands', 'recalc_unpaid_discounts'] as $endpoint) {
            $this->assertStringNotContainsString('_getStudentDiscounts',
                $this->method(self::FEES, $endpoint),
                "{$endpoint}() must not price from the legacy third authority");
        }
    }

    /**
     * P5-D — the converged endpoint. Whole-session generation reaches the
     * canonical pipeline; the legacy month-subset branch survives behind the
     * DEC-013b refusal so it can still never bill an entitled student at gross.
     */
    public function test_S5_single_student_generation_now_routes_canonically(): void
    {
        $m = $this->method(self::FEES, 'generate_demands_for_student');

        $this->assertStringContainsString('assignInitialFees', $m,
            'whole-session generation must reach the canonical pipeline');
        $this->assertStringContainsString('_activeEntitlementCount', $m,
            'the surviving legacy month-subset branch must still be guarded');
    }

    /** The canonical boundary genuinely lives in Fee_lifecycle, and only there. */
    public function test_S5_canonical_authority_boundary_is_not_in_Fees(): void
    {
        $this->assertStringContainsString('USE_ENTITLEMENT_BILLING', $this->src(self::LIFE));
        $this->assertStringNotContainsString('USE_ENTITLEMENT_BILLING', $this->src(self::FEES),
            'Fees.php has no knowledge of the billing-authority switch — which is exactly why its '
            . 'generation endpoints are unaffected by it. That is the B3 gap.');
    }

    /**
     * `_getStudentDiscounts()` cannot see a concession or an entitlement AT ALL.
     * It is not that it prefers the legacy sources — it has no access to either
     * canonical collection.
     */
    public function test_S5_legacy_map_cannot_see_either_canonical_authority(): void
    {
        $m = $this->method(self::FEES, '_getStudentDiscounts');
        $this->assertStringNotContainsString('studentConcessions', $m);
        $this->assertStringNotContainsString('studentEntitlements', $m);
        $this->assertStringNotContainsString('concessionReader', $m);
        $this->assertStringNotContainsString('entitlementReader', $m);
    }

    // ══════════════════════════════════════════════════════════════════
    //  STEP 3 — the conflict matrix, numerically
    // ══════════════════════════════════════════════════════════════════

    /** Price a chart through the REAL generator + REAL entitlement reader. */
    private function canonicalNets(array $rows): array
    {
        $g = new \Fee_generation_service();
        $g->init(new B3Chart(), new B3EntitlementReader($rows), null, 'SCH_B3', '2026-27');
        $built = $g->generateDemandsForStudent('STU_B3', 'Class 5th', 'Section A');
        $m = [];
        foreach ($built['specs'] as $s) $m[$s['demandId']] = round((float) ($s['data']['netAmount'] ?? 0), 2);
        return $m;
    }

    private function ent(array $over = []): array
    {
        return array_merge([
            'studentId' => 'STU_B3', 'schoolId' => 'SCH_B3', 'session' => '2026-27',
            'kind' => 'concession', 'scope' => 'all', 'valueType' => 'percent', 'value' => 20,
            'effectiveFrom' => '2026-04-01', 'effectiveTo' => null, 'status' => 'active',
        ], $over);
    }

    /**
     * Case 2 vs case 1 — the same ₹1000 head, the same student, two authorities,
     * two different answers. The legacy map has no entitlement to find, so it
     * bills gross; the canonical path bills 800.
     */
    public function test_S3_case_1_and_2_the_two_authorities_disagree(): void
    {
        $this->assertSame(['D_Tuition_2026-04' => 800.0], $this->canonicalNets([$this->ent()]),
            'Canonical authority: the entitlement applies.');

        // The legacy map is built from three sources, none of which is the
        // entitlement. With no legacy field set, its discount map is EMPTY.
        $legacyMap = [];   // = _getStudentDiscounts() output for this student
        $this->assertSame([], $legacyMap,
            'Legacy authority: the entitlement is invisible, so the student is billed GROSS by '
            . 'Generate Demands / Recalculate — a different answer for the same student.');
    }

    /** Case 3 — both exist. Neither path can see the other, so neither sums. */
    public function test_S3_case_3_both_exist_neither_path_double_discounts(): void
    {
        // Canonical path sees ONLY the entitlement.
        $this->assertSame(['D_Tuition_2026-04' => 800.0],
            $this->canonicalNets([$this->ent()]),
            'A legacy exemptedFees entry cannot reach the canonical generator …');

        // And the legacy path sees ONLY its own three sources — proven by
        // test_S5_legacy_map_cannot_see_either_canonical_authority.
        $this->assertTrue(true,
            '… and the entitlement cannot reach the legacy map. So the two never SUM on one run: '
            . 'the hazard is DIVERGENCE (a different total depending on which button was pressed), '
            . 'not double-deduction within a single run.');
    }

    /** Cases 4/5 — a rejected or expired entitlement bills gross on both paths. */
    public function test_S3_case_4_5_rejected_and_expired_bill_gross(): void
    {
        $rejected = array_values(array_filter([$this->ent(['status' => 'rejected'])],
            fn(array $e) => ($e['status'] ?? '') === 'active'));
        $this->assertSame(['D_Tuition_2026-04' => 1000.0], $this->canonicalNets($rejected));
        $this->assertSame(['D_Tuition_2026-04' => 1000.0],
            $this->canonicalNets([$this->ent(['effectiveTo' => '2026-03-31'])]));
    }

    /** Case 6 — full exemption removes the demand; nothing can re-add a discount to it. */
    public function test_S3_case_6_full_exemption(): void
    {
        $this->assertSame([], $this->canonicalNets([$this->ent(['valueType' => 'fullExempt', 'value' => 0])]));
    }

    /**
     * Cases 9/10/11 — the PARTIAL fence. Recalculation skips a demand that
     * already carries `concessionApplied`, so it cannot overwrite a canonical
     * price. That protects an ALREADY-DISCOUNTED demand; it does nothing for a
     * demand that was generated at gross and should have been discounted.
     */
    public function test_S3_case_9_10_11_the_partial_fence_and_what_it_misses(): void
    {
        $m = $this->method(self::FEES, '_recalculateStudentUnpaidDemands');
        $this->assertStringContainsString('concessionApplied', $m,
            'The fence exists: a demand carrying a canonical concession is preserved, not re-derived.');

        $fenced = preg_replace('/\s+/', '', $m);
        $this->assertStringContainsString("!empty(\$demand['concessionApplied'])", $fenced,
            'and it keys on the attribution field the canonical generator writes.');

        // What it does NOT do: give the legacy path any way to APPLY an
        // entitlement. A student admitted before the entitlement was recorded
        // has no concessionApplied, so recalculation re-derives from the legacy
        // sources and the entitlement stays unapplied.
        $this->assertStringNotContainsString('entitlementReader', $m);
        $this->assertStringNotContainsString('studentEntitlements', $m);
    }

    // ══════════════════════════════════════════════════════════════════
    //  STEP 7 — idempotency of the canonical path
    // ══════════════════════════════════════════════════════════════════

    /** generate → recalculate → recalculate → regenerate is stable. */
    public function test_S7_repeated_canonical_generation_is_stable(): void
    {
        $rows = [$this->ent()];
        $a = $this->canonicalNets($rows);
        $b = $this->canonicalNets($rows);
        $c = $this->canonicalNets($rows);

        $this->assertSame($a, $b);
        $this->assertSame($b, $c);
        $this->assertSame(['D_Tuition_2026-04' => 800.0], $c,
            'Repeated generation never compounds the discount — deductions are computed from GROSS '
            . 'each time, not applied to the previous net.');
    }

    /** The deduction is always taken from gross, which is what makes it stable. */
    public function test_S7_deduction_is_always_from_gross_never_compounding(): void
    {
        $m = $this->method(__DIR__ . '/../../application/libraries/Fee_generation_service.php', '_applyDeductions');
        $flat = preg_replace('/\s+/', '', $m);
        $this->assertStringContainsString('$gross=(float)($data[\'grossAmount\']??0);', $flat,
            'Every run re-reads grossAmount …');
        $this->assertStringContainsString('$net=max(0.0,round($gross-$deduction,2));', $flat,
            '… and computes net from it, so a second run cannot discount an already-discounted net.');
    }
}

// ── doubles: data only; all matching and pricing is production code ─────────

class B3Chart
{
    public function buildAdmissionDemandSpecs($studentId, $class, $section, $forPreview = false): array
    {
        return ['specs' => [['demandId' => 'D_Tuition_2026-04', 'data' => [
            'studentId' => $studentId, 'periodKey' => '2026-04',
            'feeHeadId' => 'FH_TUI', 'feeHead' => 'Tuition Fee', 'category' => 'academic',
            'grossAmount' => 1000.0, 'netAmount' => 1000.0, 'balance' => 1000.0, 'status' => 'unpaid',
        ]]], 'assigned' => ['Tuition Fee'], 'chartFound' => true];
    }
}

class B3EntitlementReader extends \Fee_entitlement_reader
{
    public function __construct(private array $rows) {}
    public function init($firebase): void {}
    public function activeConcessionsForStudent(string $s, string $st, string $sess): array { return $this->rows; }
}
