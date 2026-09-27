<?php

use PHPUnit\Framework\TestCase;

if (!defined('BASEPATH')) define('BASEPATH', __DIR__);
if (!defined('APPPATH'))  define('APPPATH', __DIR__ . '/../../application/');
if (!function_exists('log_message')) { function log_message($l, $m) {} }

require_once __DIR__ . '/../../application/libraries/Fee_head_resolver.php';

/**
 * DEC-010 — the fee-head PICKER returned an empty list on every tenant whose
 * `school_code` differs from its `school_id`.
 *
 * WHAT THE OPERATOR SAW. "No fee heads registered", and a head-scoped
 * entitlement could not be created through the UI at all — on a tenant whose
 * registry held six active heads.
 *
 * TWO STACKED DEFECTS, both in `listForSchool()`, both fail-closed and silent:
 *
 *   1. The tenant guard compared the caller's canonical `schoolId` against
 *      `$this->fs->schoolCode()`. `$this->fs` is **Firestore_service**, whose
 *      `schoolCode()` returns the LOGIN CODE ("10012") — only `schoolId()` is
 *      canonical. The guard therefore tripped on itself and returned [].
 *      Production proof: `listForSchool tenant mismatch: asked=SCH_B56BB9A401
 *      helper=10012`.
 *
 *   2. It then called `$this->fs->querySchool()` — a method Firestore_service
 *      does NOT define. Fixing (1) alone only moved the failure: the undefined
 *      call throws, the fail-closed catch swallows it, and the picker is empty
 *      again by a quieter route. BOTH had to be fixed for one head to appear.
 *
 * WHY THE EXISTING TESTS PASSED THROUGHOUT. `P6CaptureAndApprovalTest` used a
 * double exposing `schoolCode()` returning the CANONICAL id and a
 * `querySchool()` method — i.e. it modelled Firestore_helper, not the
 * Firestore_service that production actually injects. A double that cannot
 * express the production shape cannot fail on a production defect. This is the
 * same class of miss as the rollout-grant incident, where a recording double
 * could not express Firestore's real updateMask semantics.
 *
 * So the double below is FAITHFUL TO Firestore_service: canonical `schoolId()`,
 * legacy `schoolCode()`, and `schoolWhere()` as the only school-scoped query
 * verb. Run this file against the pre-fix resolver and every listing test fails.
 */
class Dec010FeeHeadPickerTenantTest extends TestCase
{
    private const CANONICAL = 'SCH_B56BB9A401';
    private const LEGACY    = '10012';            // the real school_code
    private const OTHER     = 'SCH_218AAF5C23';   // the real second tenant

    /** The six live rows, plus this tenant's name-claim token and a foreign tenant's head. */
    private function rows(): array
    {
        $mk = function ($id, $sch, $name, $cat, $status = 'active') {
            return ['data' => ['feeHeadId' => $id, 'schoolId' => $sch, 'name' => $name,
                               'category' => $cat, 'status' => $status]];
        };
        return [
            $mk('FH_1DDD704466C5', self::CANONICAL, 'Admission Fee',       'academic'),
            $mk('FH_180EDF7FC11D', self::CANONICAL, 'Hostel Fee',          'hostel'),
            $mk('FH_2102C0AF6BE4', self::CANONICAL, 'ID Card Replacement', 'administrative'),
            $mk('FH_088BB5D74C9F', self::CANONICAL, 'Library Fee',         'academic'),
            $mk('FH_E038A430DF0D', self::CANONICAL, 'Transport Fee',       'transport'),
            $mk('FH_DF3C200439E2', self::CANONICAL, 'Tuition Fee',         'academic'),
            ['data' => ['feeHeadId' => 'FH_DF3C200439E2', 'schoolId' => self::CANONICAL,
                        'kind' => 'nameClaim']],
            $mk('FH_ARCHIVED0001', self::CANONICAL, 'Old Fee',             'academic', 'archived'),
            $mk('FH_69F749D2E973', self::OTHER,     'Tuition Fee',         'academic'),
        ];
    }

    /**
     * FAITHFUL Firestore_service double.
     *   schoolId()    -> canonical      (what the registry is keyed by)
     *   schoolCode()  -> the login code (what the OLD guard wrongly compared)
     *   schoolWhere() -> the ONLY school-scoped query verb; NO querySchool().
     */
    private function service(string $canonical = self::CANONICAL, string $code = self::LEGACY)
    {
        return new class($canonical, $code, $this->rows()) {
            public array $queried = [];
            private string $sid;
            private string $code;
            private array $rows;
            public function __construct(string $sid, string $code, array $rows)
            {
                $this->sid = $sid; $this->code = $code; $this->rows = $rows;
            }
            public function schoolId(): string   { return $this->sid; }
            public function schoolCode(): string { return $this->code; }
            public function schoolWhere(string $col, array $extra = []): array
            {
                $this->queried[] = $col;
                $sid = $this->sid;
                return array_values(array_filter($this->rows, function ($r) use ($sid) {
                    return ($r['data']['schoolId'] ?? '') === $sid;
                }));
            }
        };
    }

    private function names(array $heads): array
    {
        $n = array_column($heads, 'name'); sort($n); return $n;
    }

    // == THE DEFECT ======================================================

    /** The exact production case: school_code "10012" != school_id. */
    public function test_the_picker_lists_heads_when_school_code_diverges(): void
    {
        $fs    = $this->service();
        $heads = (new Fee_head_resolver())->init($fs)->listForSchool(self::CANONICAL);

        $this->assertNotSame([], $heads,
            'THE DEFECT: a registry with six active heads rendered "No fee heads registered"');
        $this->assertSame(['Admission Fee', 'Hostel Fee', 'ID Card Replacement',
                           'Library Fee', 'Transport Fee', 'Tuition Fee'], $this->names($heads));
        $this->assertSame(['feeHeadRegistry'], $fs->queried,
            'it must reach the query at all - the old guard returned before this');
    }

    /** The UAT head specifically, since the whole SE-V2 scenario depends on it. */
    public function test_the_UAT_tuition_head_is_selectable(): void
    {
        $heads = (new Fee_head_resolver())->init($this->service())->listForSchool(self::CANONICAL);
        $row   = array_values(array_filter($heads, function ($h) {
            return $h['feeHeadRef'] === 'FH_DF3C200439E2';
        }));
        $this->assertCount(1, $row, 'exactly one Tuition Fee option');
        $this->assertSame('Tuition Fee', $row[0]['name']);
        $this->assertSame('academic',    $row[0]['category']);
    }

    /** A tenant whose code EQUALS its id must not regress. */
    public function test_a_tenant_without_divergence_still_lists(): void
    {
        $fs = $this->service(self::CANONICAL, self::CANONICAL);
        $this->assertCount(6, (new Fee_head_resolver())->init($fs)->listForSchool(self::CANONICAL));
    }

    // == THE SECOND DEFECT - THE MISSING QUERY VERB ======================

    /**
     * Firestore_service has no querySchool(). A resolver that calls it
     * unconditionally throws into the fail-closed catch and returns [] - the
     * same empty picker, a quieter cause. Pinned so it cannot come back.
     */
    public function test_it_uses_schoolWhere_when_querySchool_is_absent(): void
    {
        $fs = $this->service();
        $this->assertFalse(method_exists($fs, 'querySchool'),
            'the double must model Firestore_service, which lacks querySchool');
        $this->assertCount(6, (new Fee_head_resolver())->init($fs)->listForSchool(self::CANONICAL));
    }

    /** Helper-shaped injections (querySchool only) must keep working. */
    public function test_a_querySchool_only_helper_is_still_supported(): void
    {
        $fs = new class($this->rows()) {
            private array $rows;
            public function __construct(array $rows) { $this->rows = $rows; }
            public function schoolCode(): string { return 'SCH_B56BB9A401'; }
            public function querySchool(string $col, array $extra = []): array
            {
                return array_values(array_filter($this->rows, function ($r) {
                    return ($r['data']['schoolId'] ?? '') === 'SCH_B56BB9A401';
                }));
            }
        };
        $this->assertCount(6, (new Fee_head_resolver())->init($fs)->listForSchool(self::CANONICAL));
    }

    /** No query verb at all -> fail CLOSED, never a fatal escaping the resolver. */
    public function test_an_unknown_helper_shape_fails_closed(): void
    {
        $fs = new class { public function schoolId(): string { return 'SCH_B56BB9A401'; } };
        $this->assertSame([], (new Fee_head_resolver())->init($fs)->listForSchool(self::CANONICAL));
    }

    // == THE GUARD STILL GUARDS ==========================================

    /** Asking for a tenant the helper is not bound to must still refuse. */
    public function test_a_cross_tenant_request_is_still_refused(): void
    {
        $fs = $this->service();
        $this->assertSame([], (new Fee_head_resolver())->init($fs)->listForSchool(self::OTHER),
            'the fix must not soften tenant isolation into a pass-through');
        $this->assertSame([], $fs->queried, 'and it must refuse BEFORE querying');
    }

    /** The other tenant's rows never leak, even when bound correctly. */
    public function test_no_foreign_row_ever_appears(): void
    {
        $fs    = $this->service(self::OTHER, self::OTHER);
        $heads = (new Fee_head_resolver())->init($fs)->listForSchool(self::OTHER);
        $this->assertSame(['Tuition Fee'], $this->names($heads));
        $this->assertSame('FH_69F749D2E973', $heads[0]['feeHeadRef'],
            'the OTHER tenant Tuition head - ids must not cross');
    }

    // == ROW FILTERING UNCHANGED =========================================

    public function test_name_claims_and_archived_heads_are_still_excluded(): void
    {
        $heads = (new Fee_head_resolver())->init($this->service())->listForSchool(self::CANONICAL);
        $this->assertNotContains('Old Fee', $this->names($heads),
            'archived head must not be offered');
        $dup = array_filter($heads, function ($h) { return $h['feeHeadRef'] === 'FH_DF3C200439E2'; });
        $this->assertCount(1, $dup, 'the nameClaim token must not double the Tuition row');
        foreach ($heads as $h) {
            $this->assertNotSame('', $h['name']);
            $this->assertTrue(Fee_head_resolver::isValidCategory($h['category']));
        }
    }

    // == THE ACCESSOR RULE ===============================================

    /** schoolId() is canonical and must be preferred wherever it exists. */
    public function test_canonical_schoolId_is_preferred_over_the_login_code(): void
    {
        $code = '';
        foreach (token_get_all(file_get_contents(APPPATH . 'libraries/Fee_head_resolver.php')) as $t) {
            $code .= is_array($t) ? (in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true) ? ' ' : $t[1]) : $t;
        }
        $needleId   = "method_exists(\$this->fs, 'schoolId')";
        $needleCode = "method_exists(\$this->fs, 'schoolCode')";
        $this->assertStringContainsString($needleId, $code);
        $this->assertStringContainsString($needleCode, $code);
        $this->assertLessThan(strpos($code, $needleCode), strpos($code, $needleId),
            'schoolId must be consulted BEFORE schoolCode, never after');
    }

    /** DEC-008 stays true: single-head resolution is keyed by the canonical id. */
    public function test_DEC008_single_head_resolution_is_unaffected(): void
    {
        $fs = new class {
            public function schoolId(): string { return 'SCH_B56BB9A401'; }
            public function get(string $col, string $docId): ?array
            {
                return $docId === 'SCH_B56BB9A401_FH_DF3C200439E2'
                    ? ['feeHeadId' => 'FH_DF3C200439E2', 'schoolId' => 'SCH_B56BB9A401',
                       'name' => 'Tuition Fee', 'category' => 'academic', 'status' => 'active']
                    : null;
            }
        };
        $r = (new Fee_head_resolver())->init($fs)->resolveForNewUse(self::CANONICAL, 'FH_DF3C200439E2');
        $this->assertTrue($r['ok']);
        $this->assertFalse((new Fee_head_resolver())->init($fs)
            ->resolveForNewUse(self::LEGACY, 'FH_DF3C200439E2')['ok']);
    }
}
