<?php

use PHPUnit\Framework\TestCase;

if (!defined('BASEPATH')) define('BASEPATH', __DIR__);
if (!function_exists('log_message')) { function log_message($l, $m) {} }
require_once __DIR__ . '/../../application/libraries/Fee_head_resolver.php';

/** Minimal fs double — get() only, which is all the resolver uses. */
class FhrFsDouble
{
    public array $docs = [];
    public int $reads = 0;
    public bool $throwOnRead = false;

    public function get(string $col, string $id)
    {
        $this->reads++;
        if ($this->throwOnRead) throw new \RuntimeException('simulated firestore failure');
        return $this->docs[$col . '/' . $id] ?? null;
    }
}

/**
 * Fee Head Registry — resolver + closed vocabulary.
 *
 * Covers the design's test matrix items that are provable without wiring:
 * T1/T2 school-scoped + session-independent identity, T6 archive, T9 category
 * validation, T10/T11 administrative, T12/T13 meals reserved-and-hidden,
 * T14 unknown fails closed, T23 cross-school isolation, T36 unknown never
 * assignable, plus the fail-closed paths (§19 failure modes).
 */
class FeeHeadResolverTest extends TestCase
{
    private FhrFsDouble $fs;
    private Fee_head_resolver $r;

    protected function setUp(): void
    {
        $this->fs = new FhrFsDouble();
        $this->r  = (new Fee_head_resolver())->init($this->fs);
    }

    private function seed(string $school, string $id, string $category, array $over = []): void
    {
        $this->fs->docs['feeHeadRegistry/' . $school . '_' . $id] = array_merge([
            'feeHeadId' => $id, 'schoolId' => $school, 'name' => 'Tuition Fee',
            'nameNorm' => 'tuition fee', 'category' => $category, 'status' => 'active',
        ], $over);
    }

    // ── vocabulary ─────────────────────────────────────────────────────────

    public function test_closed_vocabulary_is_exactly_the_approved_six(): void
    {
        $this->assertSame(
            ['academic', 'transport', 'hostel', 'meals', 'administrative', 'unknown'],
            Fee_head_resolver::CATEGORIES
        );
    }

    public function test_display_groups_are_not_financial_categories(): void
    {
        foreach (['extra_curricular', 'other', 'General', 'Extra-curricular', ''] as $c) {
            $this->assertFalse(Fee_head_resolver::isValidCategory($c), "'{$c}' must not be a financial category");
        }
    }

    /** T36 — 'unknown' is a read-time interpretation of legacy '', never a choice. */
    public function test_unknown_is_never_operator_assignable(): void
    {
        $this->assertTrue(Fee_head_resolver::isValidCategory('unknown'));
        $this->assertFalse(Fee_head_resolver::isAssignableCategory('unknown'));
    }

    /** T12 + T13 — meals stays in the vocabulary but is withheld from operators. */
    public function test_meals_is_reserved_but_not_assignable(): void
    {
        $this->assertTrue(Fee_head_resolver::isValidCategory('meals'), 'meals must stay reserved');
        $this->assertFalse(Fee_head_resolver::isAssignableCategory('meals'), 'meals must be hidden today');
        $this->assertTrue(Fee_head_resolver::isServiceCategory('meals'));
    }

    public function test_assignable_set_is_exactly_the_four_usable_categories(): void
    {
        $this->assertSame(['academic', 'transport', 'hostel', 'administrative'], Fee_head_resolver::ASSIGNABLE);
    }

    /** T11 + T15 + T16 — only academic is eligible by default. */
    public function test_only_academic_is_default_eligible(): void
    {
        $this->assertTrue(Fee_head_resolver::isDefaultEligible('academic'));
        foreach (['transport', 'hostel', 'meals', 'administrative', 'unknown'] as $c) {
            $this->assertFalse(Fee_head_resolver::isDefaultEligible($c), "{$c} must be excluded by default");
        }
    }

    /** T10 — administrative is excluded, but is NOT a service category. */
    public function test_administrative_is_excluded_but_not_a_service(): void
    {
        $this->assertFalse(Fee_head_resolver::isDefaultEligible('administrative'));
        $this->assertFalse(Fee_head_resolver::isServiceCategory('administrative'));
        $this->assertTrue(Fee_head_resolver::isAssignableCategory('administrative'));
    }

    public function test_service_categories_match_the_entitlement_reader_set(): void
    {
        $this->assertSame(['transport', 'hostel', 'meals'], Fee_head_resolver::SERVICE_CATEGORIES);
    }

    // ── identity + doc-id shape ────────────────────────────────────────────

    /**
     * T2 / T25 — the identity takes school + head ONLY. There is no parameter
     * through which a session could enter, and one head resolves identically
     * in every session because the session is not part of the lookup.
     */
    public function test_identity_is_school_scoped_with_no_session_component(): void
    {
        $this->assertSame('SCH_B56BB9A401_FH_ABC123',
            Fee_head_resolver::docId('SCH_B56BB9A401', 'FH_ABC123'));

        // docId() accepts exactly two arguments — there is nowhere to put a session.
        $this->assertSame(2, (new ReflectionMethod(Fee_head_resolver::class, 'docId'))->getNumberOfParameters());
        $this->assertSame(2, (new ReflectionMethod(Fee_head_resolver::class, 'resolve'))->getNumberOfParameters());

        // The same head resolves to one category across sessions, because no
        // session is ever supplied — 2026-27 and 2027-28 are the same lookup.
        $this->seed('SCH_B56BB9A401', 'FH_ABC123', 'academic');
        $a = $this->r->resolve('SCH_B56BB9A401', 'FH_ABC123');
        $b = $this->r->resolve('SCH_B56BB9A401', 'FH_ABC123');
        $this->assertSame($a['category'], $b['category']);
        $this->assertSame('FH_ABC123', $a['feeHeadId'], 'identity survives independent of any session');
    }

    public function test_name_normalisation_collapses_case_and_whitespace(): void
    {
        $this->assertSame('tuition fee', Fee_head_resolver::nameNorm('  Tuition   Fee '));
        $this->assertSame('id card replacement', Fee_head_resolver::nameNorm('ID Card Replacement'));
        $this->assertSame(
            Fee_head_resolver::claimDocId('SCH_X', 'Tuition Fee'),
            Fee_head_resolver::claimDocId('SCH_X', '  tuition   FEE  ')
        );
    }

    public function test_claim_doc_id_is_school_scoped(): void
    {
        $this->assertSame('SCH_X_name_hostel fee', Fee_head_resolver::claimDocId('SCH_X', 'Hostel Fee'));
        $this->assertNotSame(
            Fee_head_resolver::claimDocId('SCH_A', 'Tuition Fee'),
            Fee_head_resolver::claimDocId('SCH_B', 'Tuition Fee')
        );
    }

    // ── resolution ─────────────────────────────────────────────────────────

    public function test_resolves_an_active_head_to_its_category(): void
    {
        $this->seed('SCH_A', 'FH_1', 'transport', ['name' => 'Transport Fee']);
        $r = $this->r->resolve('SCH_A', 'FH_1');
        $this->assertTrue($r['ok']);
        $this->assertSame('transport', $r['category']);
        $this->assertSame('Transport Fee', $r['name']);
    }

    /** T1 + T23 — school A can never resolve school B's head. */
    public function test_school_a_cannot_resolve_school_b_head(): void
    {
        $this->seed('SCH_B', 'FH_9', 'academic');
        $this->assertFalse($this->r->resolve('SCH_A', 'FH_9')['ok']);
        $this->assertSame('not_found', $this->r->resolve('SCH_A', 'FH_9')['reason']);
    }

    public function test_stored_school_mismatch_is_refused(): void
    {
        $this->seed('SCH_A', 'FH_2', 'academic', ['schoolId' => 'SCH_OTHER']);
        $r = $this->r->resolve('SCH_A', 'FH_2');
        $this->assertFalse($r['ok']);
        $this->assertSame('cross_school_mismatch', $r['reason']);
    }

    /** T14 + §19 — every unresolved path fails closed, never 'academic'. */
    public function test_every_failure_path_fails_closed(): void
    {
        $cases = [
            'missing_school_id'    => ['', 'FH_1'],
            'missing_fee_head_ref' => ['SCH_A', ''],
            'not_found'            => ['SCH_A', 'FH_NOPE'],
        ];
        foreach ($cases as $reason => [$school, $ref]) {
            $r = $this->r->resolve($school, $ref);
            $this->assertFalse($r['ok'], $reason);
            $this->assertSame($reason, $r['reason']);
            $this->assertArrayNotHasKey('category', $r, 'a failed resolve must never carry a category');
        }
    }

    public function test_read_failure_does_not_fall_back_to_a_guess(): void
    {
        $this->seed('SCH_A', 'FH_1', 'academic');
        $this->fs->throwOnRead = true;
        $r = $this->r->resolve('SCH_A', 'FH_1');
        $this->assertFalse($r['ok']);
        $this->assertSame('read_failed', $r['reason']);
    }

    /** T9 — an unrecognised stored category is refused, not coerced. */
    public function test_invalid_stored_category_is_refused(): void
    {
        foreach (['General', 'Extra-curricular', 'Academic', '', 'unknown'] as $bad) {
            $this->fs->docs = [];
            $this->seed('SCH_A', 'FH_3', $bad);
            $r = $this->r->resolve('SCH_A', 'FH_3');
            $this->assertFalse($r['ok'], "stored category '{$bad}' must be refused");
            $this->assertSame('invalid_category', $r['reason']);
        }
    }

    /** T6 — archived resolves for history, but never for new use. */
    public function test_archived_head_resolves_for_history_but_not_for_new_use(): void
    {
        $this->seed('SCH_A', 'FH_4', 'academic', ['status' => 'archived']);
        $this->assertTrue($this->r->resolve('SCH_A', 'FH_4')['ok'], 'history must still explain itself');
        $new = $this->r->resolveForNewUse('SCH_A', 'FH_4');
        $this->assertFalse($new['ok']);
        $this->assertSame('archived', $new['reason']);
    }

    /** Never classify by name — the resolver must not even consult it. */
    public function test_resolution_ignores_the_display_name_entirely(): void
    {
        // A head NAMED like transport but CLASSIFIED academic must resolve academic.
        $this->seed('SCH_A', 'FH_5', 'academic', ['name' => 'Transport Fee', 'nameNorm' => 'transport fee']);
        $this->assertSame('academic', $this->r->resolve('SCH_A', 'FH_5')['category']);

        // And the reverse: named innocuously, classified transport.
        $this->seed('SCH_A', 'FH_6', 'transport', ['name' => 'Conveyance', 'nameNorm' => 'conveyance']);
        $this->assertSame('transport', $this->r->resolve('SCH_A', 'FH_6')['category']);
    }

    public function test_resolution_is_memoised_per_request(): void
    {
        $this->seed('SCH_A', 'FH_7', 'academic');
        $this->r->resolve('SCH_A', 'FH_7');
        $this->r->resolve('SCH_A', 'FH_7');
        $this->r->resolve('SCH_A', 'FH_7');
        $this->assertSame(1, $this->fs->reads, 'the registry must not be re-read per demand');
    }

    public function test_memo_does_not_leak_across_schools(): void
    {
        $this->seed('SCH_A', 'FH_8', 'academic');
        $this->seed('SCH_B', 'FH_8', 'transport');
        $this->assertSame('academic',  $this->r->resolve('SCH_A', 'FH_8')['category']);
        $this->assertSame('transport', $this->r->resolve('SCH_B', 'FH_8')['category']);
    }

    public function test_uninitialised_resolver_fails_closed(): void
    {
        $bare = new Fee_head_resolver();
        $r = $bare->resolve('SCH_A', 'FH_1');
        $this->assertFalse($r['ok']);
        $this->assertSame('not_initialized', $r['reason']);
    }

    /** The resolver must expose classification ONLY — never a demand-id input. */
    public function test_resolver_returns_no_demand_identity_material(): void
    {
        $this->seed('SCH_A', 'FH_1', 'academic');
        $r = $this->r->resolve('SCH_A', 'FH_1');
        foreach (['demandId', 'periodKey', 'instanceKey', 'instance'] as $forbidden) {
            $this->assertArrayNotHasKey($forbidden, $r);
        }
    }
}
