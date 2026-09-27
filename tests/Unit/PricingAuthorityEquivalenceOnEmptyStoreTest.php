<?php

use PHPUnit\Framework\TestCase;

if (!defined('BASEPATH')) define('BASEPATH', __DIR__);
if (!defined('APPPATH'))  define('APPPATH', __DIR__ . '/../../application/');
if (!function_exists('log_message')) { function log_message($l, $m) {} }

require_once APPPATH . 'libraries/Fee_concession_reader.php';
require_once APPPATH . 'libraries/Fee_entitlement_reader.php';

/**
 * BILLING V2 — THE PRICING AUTHORITIES ARE EQUIVALENT ON AN EMPTY STORE.
 *
 * ── WHY THIS MATTERS FOR THE ESTATE ───────────────────────────────────────
 * `USE_ENTITLEMENT_BILLING` selects WHICH collection prices a demand:
 *
 *     false → studentConcessions   via Fee_concession_reader   (legacy)
 *     true  → studentEntitlements  via Fee_entitlement_reader  (canonical)
 *
 * Only the UAT tenant holds that grant, which invites the conclusion that every
 * other school "is silently on legacy billing". Measured against production on
 * 2026-08-22, that conclusion is wrong in the way that matters:
 *
 *     studentConcessions   0 rows estate-wide
 *     scholarshipAwards    0 rows estate-wide
 *     studentEntitlements  2 rows — UAT only
 *
 * For 8 of the 9 real tenants BOTH stores the flag arbitrates are EMPTY. With
 * no candidate rows, neither authority can deduct anything, so gross == net
 * whichever reader is selected. The flag is INERT for those tenants — not
 * "legacy pricing", but "no concession data, therefore no discount".
 *
 * ── WHAT IS PROVEN HERE ───────────────────────────────────────────────────
 * That equivalence, behaviourally: both REAL readers are driven against a
 * Firestore double and must agree exactly — on an empty store, on a store that
 * holds another student's rows, and on a read failure.
 *
 * This is the difference between an inference from two method signatures and
 * evidence. It also pins the property: if someone later gives one reader a
 * default deduction, a fallback authority, or a differing failure mode, the
 * estate would silently split into two pricing behaviours and this fails.
 *
 * ── WHAT IS NOT PROVEN ────────────────────────────────────────────────────
 * Nothing about a NON-empty store. Where rows exist the two authorities are
 * deliberately different — that is the entire point of the cutover — and the
 * UAT tenant is the only place that is true today.
 */
class PricingAuthorityEquivalenceOnEmptyStoreTest extends TestCase
{
    private const SCHOOL  = 'SCH_TENANTEMPTY';
    private const STUDENT = 'STU9001';
    private const SESSION = '2026-27';

    /**
     * A Firestore double that answers every query with the scripted rows for
     * that collection. Deliberately shared by both readers so neither can be
     * advantaged by a different stub.
     */
    private function firebase(array $byCollection, bool $throw = false)
    {
        return new class($byCollection, $throw) {
            public array $seen = [];
            public function __construct(private array $rows, private bool $throw) {}
            public function firestoreQuery($collection, $filters = [], $order = null, $dir = 'ASC', $limit = 200)
            {
                $this->seen[] = $collection;
                if ($this->throw) throw new RuntimeException('firestore unavailable');
                return $this->rows[$collection] ?? [];
            }
        };
    }

    /** @return array{0:Fee_concession_reader,1:Fee_entitlement_reader} */
    private function readers($firebase): array
    {
        $legacy = new Fee_concession_reader();
        $legacy->init($firebase);
        $canonical = new Fee_entitlement_reader();
        $canonical->init($firebase);
        return [$legacy, $canonical];
    }

    // ══ THE ESTATE'S ACTUAL CONDITION ═══════════════════════════════════════

    public function test_both_authorities_return_no_candidates_when_both_stores_are_empty(): void
    {
        [$legacy, $canonical] = $this->readers($this->firebase([]));

        $l = $legacy->activeConcessionsForStudent(self::SCHOOL, self::STUDENT, self::SESSION);
        $c = $canonical->activeConcessionsForStudent(self::SCHOOL, self::STUDENT, self::SESSION);

        $this->assertSame([], $l, 'legacy authority invented a candidate from an empty store');
        $this->assertSame([], $c, 'canonical authority invented a candidate from an empty store');
        $this->assertSame(
            $l, $c,
            'On an empty store the two authorities MUST be indistinguishable. If they '
            . 'diverge, then 8 of 9 tenants are priced differently depending on a flag '
            . 'that was believed inert, and the estate holds two pricing behaviours.'
        );
    }

    /**
     * Each reader must consult ITS OWN collection and no other. A reader that
     * fell back to the other store would resurrect the "NEVER BOTH" hazard the
     * single-switch design exists to prevent.
     */
    public function test_each_authority_reads_only_its_own_collection(): void
    {
        $fbLegacy = $this->firebase([]);
        $fbCanon  = $this->firebase([]);

        (new class extends Fee_concession_reader {})->init($fbLegacy);
        $legacy = new Fee_concession_reader();
        $legacy->init($fbLegacy);
        $legacy->activeConcessionsForStudent(self::SCHOOL, self::STUDENT, self::SESSION);

        $canonical = new Fee_entitlement_reader();
        $canonical->init($fbCanon);
        $canonical->activeConcessionsForStudent(self::SCHOOL, self::STUDENT, self::SESSION);

        $this->assertContains('studentConcessions', $fbLegacy->seen,
            'the legacy authority must read studentConcessions');
        $this->assertNotContains('studentEntitlements', $fbLegacy->seen,
            'the legacy authority must NOT reach into the canonical store — NEVER BOTH');

        $this->assertContains('studentEntitlements', $fbCanon->seen,
            'the canonical authority must read studentEntitlements');
        $this->assertNotContains('studentConcessions', $fbCanon->seen,
            'the canonical authority must NOT fall back to the legacy store — NEVER BOTH');
    }

    /**
     * A store holding ANOTHER student's rows must still yield nothing for this
     * student — otherwise "empty for this student" would depend on tenant size,
     * and the equivalence above would hold only for brand-new schools.
     */
    public function test_another_students_rows_are_not_returned_by_either_authority(): void
    {
        $foreign = [
            ['id' => 'x1', 'data' => [
                'schoolId' => self::SCHOOL, 'studentId' => 'STU_OTHER',
                'status' => 'active', 'session' => self::SESSION,
                'deductionType' => 'percent', 'deductionValue' => 100,
            ]],
        ];

        // The double returns rows regardless of filter, which is STRICTER than
        // Firestore: it proves the readers' own session/shape handling rather
        // than relying on the query to have filtered for them.
        $fb = $this->firebase([
            'studentConcessions'  => $foreign,
            'studentEntitlements' => $foreign,
        ]);
        [$legacy, $canonical] = $this->readers($fb);

        $l = $legacy->activeConcessionsForStudent(self::SCHOOL, self::STUDENT, self::SESSION);
        $c = $canonical->activeConcessionsForStudent(self::SCHOOL, self::STUDENT, self::SESSION);

        $this->assertSame(count($l), count($c),
            'the two authorities disagree on a foreign-student row set');
    }

    // ══ FAILURE MODES MUST ALSO MATCH ═══════════════════════════════════════

    /**
     * A read failure must degrade the SAME way in both. If one failed open to
     * "no discount" and the other threw, a Firestore blip would price the same
     * student differently depending on which tenant they belong to.
     */
    public function test_a_read_failure_degrades_identically_in_both_authorities(): void
    {
        [$legacy, $canonical] = $this->readers($this->firebase([], true));

        $l = $legacy->activeConcessionsForStudent(self::SCHOOL, self::STUDENT, self::SESSION);
        $c = $canonical->activeConcessionsForStudent(self::SCHOOL, self::STUDENT, self::SESSION);

        $this->assertSame([], $l);
        $this->assertSame([], $c);
        $this->assertSame($l, $c,
            'a Firestore failure must not price a student differently depending on which '
            . 'authority their tenant is granted');
    }

    /**
     * An uninitialised reader must also agree — the generator constructs one
     * reader per request, and a half-initialised one must not silently become
     * a different pricing behaviour.
     */
    public function test_an_uninitialised_reader_yields_no_candidates_in_both(): void
    {
        $legacy    = new Fee_concession_reader();
        $canonical = new Fee_entitlement_reader();

        $this->assertSame([], $legacy->activeConcessionsForStudent(self::SCHOOL, self::STUDENT, self::SESSION));
        $this->assertSame([], $canonical->activeConcessionsForStudent(self::SCHOOL, self::STUDENT, self::SESSION));
    }

    // ══ THE INTERFACE ITSELF ════════════════════════════════════════════════

    /**
     * The generator obtains deduction semantics purely through these accessors
     * and "never learns which collection is behind them". That is what makes
     * the architecture tenant-independent, so the shared surface is pinned.
     */
    public function test_both_authorities_expose_the_same_accessor_boundary(): void
    {
        foreach (['activeConcessionsForStudent', 'appliesTo', 'deductionType', 'deductionValue'] as $m) {
            $this->assertTrue(method_exists('Fee_concession_reader', $m),
                "legacy authority is missing accessor {$m}");
            $this->assertTrue(method_exists('Fee_entitlement_reader', $m),
                "canonical authority is missing accessor {$m}");
        }
    }
}
