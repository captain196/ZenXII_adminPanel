<?php

use PHPUnit\Framework\TestCase;

if (!defined('BASEPATH')) define('BASEPATH', __DIR__);
if (!defined('APPPATH'))  define('APPPATH', __DIR__ . '/../../application/');
if (!function_exists('log_message')) { function log_message($l, $m) {} }

require_once __DIR__ . '/../../application/libraries/Fee_entitlement_reader.php';

/**
 * DEC-013 — the legacy recalculation guard.
 *
 * `Fees::recalc_unpaid_discounts()` deletes a student's untouched demands and
 * regenerates them through the LEGACY generator, whose discount sources are
 * `scholarshipAwards` + legacy concessions ONLY. `Fees.php` contains ZERO
 * entitlement references, so an entitlement-priced demand comes back at GROSS —
 * silently voiding an approved, active concession.
 *
 * VERIFIED BLAST RADIUS (2026-08-18): only `status === 'unpaid'` AND
 * `paid <= 0.005` demands are deleted, so no payment, receipt or journal can be
 * harmed. It is a silent OVER-CHARGE, not corruption of settled money.
 *
 * CORRECTION TO AN EARLIER REPORT: the monthly generator is NOT affected. It
 * suppresses on `periodKey|canonicalFeeHeadId` (`matchCount === 1 → continue`)
 * and can only ADD missing demands. Only the DELETE in this one method is
 * dangerous, because deleting first destroys the suppression evidence. This
 * test pins that distinction so nobody "fixes" the wrong method.
 *
 * The guard is DELIBERATE, SELF-DELETING DEBT: P5 replaces delete-and-regenerate
 * with in-place recomputation and this guard is then removed.
 */
class Dec013RecalcEntitlementGuardTest extends TestCase
{
    private const SCHOOL = 'SCH_B56BB9A401';

    private function code(string $rel): string
    {
        $out = '';
        foreach (token_get_all(file_get_contents(APPPATH . $rel)) as $t) {
            $out .= is_array($t) ? (in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true) ? ' ' : $t[1]) : $t;
        }
        return $out;
    }

    private function ent(string $scope = 'head:FH_DF3C200439E2', string $status = 'active'): array
    {
        return [
            'entitlementId' => 'SCH_B56BB9A401_STU0185_Etest',
            'schoolId'      => self::SCHOOL, 'studentId' => 'STU0185',
            'kind'          => 'concession', 'concessionType' => 'other',
            'scope'         => $scope, 'valueType' => 'percent', 'value' => 10,
            'status'        => $status, 'effectiveFrom' => '2026-04-01',
            'effectiveTo'   => '', 'session' => '2026-27',
        ];
    }

    /** Firestore double honouring the reader's real query predicates. */
    private function firebase(array $docs)
    {
        return new class($docs) {
            private array $docs;
            public function __construct(array $docs) { $this->docs = $docs; }
            public function firestoreQuery(string $col, array $conds, $order = null,
                                           string $dir = 'ASC', int $limit = 200): array
            {
                $out = [];
                foreach ($this->docs as $d) {
                    $ok = true;
                    foreach ($conds as $c) {
                        [$f, $op, $v] = $c;
                        if ($op === '==' && ($d[$f] ?? null) !== $v) { $ok = false; break; }
                    }
                    if ($ok) $out[] = ['data' => $d];
                }
                return $out;
            }
        };
    }

    private function reader(array $docs): Fee_entitlement_reader
    {
        // init() returns void on this reader, so it cannot be chained.
        $r = new Fee_entitlement_reader();
        $r->init($this->firebase($docs));
        return $r;
    }

    // ══ THE PREDICATE THE GUARD RESTS ON ════════════════════════════════

    /** An active concession must be detected — this is what triggers refusal. */
    public function test_an_active_entitlement_is_detected(): void
    {
        $held = $this->reader([$this->ent()])
            ->activeConcessionsForStudent(self::SCHOOL, 'STU0185', '2026-27');
        $this->assertCount(1, $held, 'the guard must see this and refuse');
    }

    /** A student with nothing must NOT be blocked — the guard must not over-refuse. */
    public function test_a_student_with_no_entitlement_is_not_blocked(): void
    {
        $held = $this->reader([])
            ->activeConcessionsForStudent(self::SCHOOL, 'STU0001', '2026-27');
        $this->assertSame([], $held, 'legacy tenants must keep working exactly as before');
    }

    /** Pending, rejected and discontinued grants must not block recalculation. */
    public function test_non_active_states_do_not_block(): void
    {
        foreach (['pending', 'rejected', 'discontinued', 'expired'] as $state) {
            $held = $this->reader([$this->ent('head:FH_DF3C200439E2', $state)])
                ->activeConcessionsForStudent(self::SCHOOL, 'STU0185', '2026-27');
            $this->assertSame([], $held, "state '{$state}' must not block");
        }
    }

    /** Another student's entitlement must never block this student. */
    public function test_another_students_entitlement_does_not_block(): void
    {
        $other = $this->ent();
        $other['studentId'] = 'STU0184';
        $held = $this->reader([$other])
            ->activeConcessionsForStudent(self::SCHOOL, 'STU0185', '2026-27');
        $this->assertSame([], $held);
    }

    /** Another tenant's entitlement must never block. */
    public function test_another_tenants_entitlement_does_not_block(): void
    {
        $foreign = $this->ent();
        $foreign['schoolId'] = 'SCH_218AAF5C23';
        $held = $this->reader([$foreign])
            ->activeConcessionsForStudent(self::SCHOOL, 'STU0185', '2026-27');
        $this->assertSame([], $held, 'cross-tenant leakage would block an unrelated school');
    }

    /** A prior-session grant must not block the current session. */
    public function test_a_different_session_does_not_block(): void
    {
        $old = $this->ent();
        $old['session'] = '2025-26';
        $held = $this->reader([$old])
            ->activeConcessionsForStudent(self::SCHOOL, 'STU0185', '2026-27');
        $this->assertSame([], $held);
    }

    /** Every scope shape blocks — the hazard is not scope-specific. */
    public function test_every_scope_shape_blocks(): void
    {
        foreach (['all', 'category:academic', 'head:FH_DF3C200439E2', 'head:Tuition Fee'] as $scope) {
            $held = $this->reader([$this->ent($scope)])
                ->activeConcessionsForStudent(self::SCHOOL, 'STU0185', '2026-27');
            $this->assertCount(1, $held, "scope '{$scope}' must block");
        }
    }

    /** Multiple grants are all reported, so the message can count them. */
    public function test_multiple_entitlements_are_all_returned(): void
    {
        $a = $this->ent('head:FH_DF3C200439E2');
        $b = $this->ent('category:transport');
        $b['entitlementId'] = 'SCH_B56BB9A401_STU0185_Eother';
        $held = $this->reader([$a, $b])
            ->activeConcessionsForStudent(self::SCHOOL, 'STU0185', '2026-27');
        $this->assertCount(2, $held);
    }

    // ══ THE GUARD IS WIRED, AND WIRED BEFORE THE DELETE ═════════════════

    /**
     * ══ SUPERSEDED BY P5-E (2026-08-20) ═════════════════════════════════════
     *
     * These three tests asserted that a refusal guard existed, ran before the
     * deletion loop, and failed closed. All three described a world in which
     * `recalc_unpaid_discounts()` DELETED unpaid demands and regenerated them
     * through the entitlement-blind legacy generator.
     *
     * P5-E removed that world. The method now recomputes IN PLACE through the
     * canonical pipeline and deletes nothing, so DEC-013's own stated removal
     * condition is met and the guard is retired.
     *
     * The replacement assertions are STRONGER, not weaker: previously the guard
     * had to exist because a destructive path existed; now the destructive path
     * itself is gone, which is the outcome the guard was protecting against.
     */
    public function test_the_recalculation_path_no_longer_deletes_demands(): void
    {
        $code = $this->code('controllers/Fees.php');

        $this->assertStringNotContainsString("firestoreDelete('feeDemands'", $code,
            'the delete-and-regenerate path is gone — that is what retired the guard');
        $this->assertStringNotContainsString('ACC_RECALC_REFUSED_ENTITLEMENT', $code,
            'the refusal is retired because its cause was removed, not bypassed');
    }

    /** Recalculation now prices through the canonical pipeline. */
    public function test_recalculation_prices_through_the_canonical_pipeline(): void
    {
        $code = $this->code('controllers/Fees.php');

        $this->assertStringContainsString('_recalculateStudentCanonical', $code,
            'the canonical in-place recompute must exist');
        $this->assertStringContainsString('previewDemandSpecs', $code,
            'target amounts must come from the canonically-priced chart');
        $this->assertStringContainsString('recalcPlanner', $code,
            'the RC-1 planner supplies the safe outcome set (no delete verb)');
    }

    /**
     * FAIL CLOSED SURVIVES THE CONVERGENCE. An unreadable existing-demand set
     * must refuse, never be mistaken for "this student has no charges" — which
     * would make the planner propose recreating everything (the B-011 shape).
     */
    public function test_the_canonical_recalculation_fails_closed(): void
    {
        $code = $this->code('controllers/Fees.php');

        $this->assertStringContainsString('demandsForStudentStrict', $code,
            'the recalculation must use the fail-closed reader');
        $this->assertStringContainsString('existing_demand_read_failed', $code,
            'a failed read must be reported as a refusal, not silently treated as empty');
    }

    /**
     * Settled money is never rewritten, and a paid demand whose charge moved is
     * escalated rather than corrected silently.
     */
    public function test_settled_money_is_escalated_not_rewritten(): void
    {
        $code = $this->code('controllers/Fees.php');
        $this->assertStringContainsString('ACC_RECALC_NEEDS_REVIEW', $code,
            'ADJUST/OBSOLETE outcomes must be surfaced for a human, never auto-applied');
    }

    /** No override switch may exist — an override would be clicked. */
    public function test_no_override_parameter_exists(): void
    {
        $code = $this->code('controllers/Fees.php');
        foreach (['force_recalc', 'ignore_entitlement', 'override_entitlement', 'skip_guard'] as $bad) {
            $this->assertStringNotContainsString($bad, $code,
                "an override ('{$bad}') must not exist");
        }
    }

    // ══ THE CORRECTION: THE MONTHLY GENERATOR IS NOT THE PROBLEM ════════

    /**
     * `_generateDemandsForMonth` suppresses on an existing period+head and can
     * only ADD. Pinned so a future "fix" does not turn it into an overwriter,
     * and so the DEC-013 story stays accurate.
     */
    public function test_the_monthly_generator_still_skips_existing_demands(): void
    {
        $code = $this->code('controllers/Fees.php');
        $this->assertMatchesRegularExpression(
            '/\$matchCount\s*===?\s*1\s*\)\s*\{\s*\$result\[.skipped.\]\+\+;\s*continue;/',
            $code,
            'the monthly generator must keep skipping an existing period+head — '
            . 'it must never overwrite an entitlement-priced demand');
    }

    /**
     * P5-E (2026-08-20): this asserted exactly ONE production deleter existed
     * (`Fees.php`). There are now ZERO — the delete-and-regenerate path was
     * replaced by in-place recomputation, so no production code deletes a fee
     * demand at all. Strengthened, not relaxed.
     */
    public function test_no_production_path_deletes_demands(): void
    {
        $files = array_merge(
            glob(APPPATH . 'controllers/*.php'),
            glob(APPPATH . 'libraries/*.php'),
            glob(APPPATH . 'services/*.php')
        );
        $deleters = [];
        foreach ($files as $f) {
            $base = basename($f);
            if (strpos($base, 'probe') !== false || strpos($base, 'Test_') === 0) continue;
            if (preg_match('/firestoreDelete\(\s*[\'"]feeDemands[\'"]/', file_get_contents($f))) {
                $deleters[] = $base;
            }
        }
        $this->assertSame([], $deleters,
            'no production path may delete a fee demand — recalculation now recomputes in '
            . 'place, and any new deleter would need its own entitlement guard and would '
            . 'reintroduce the orphaned-receipt risk this convergence removed');
    }
}
