<?php

use PHPUnit\Framework\TestCase;

if (!defined('BASEPATH')) define('BASEPATH', 'x');
if (!defined('APPPATH')) define('APPPATH', __DIR__ . '/../../application/');

/**
 * ══════════════════════════════════════════════════════════════════════════
 *  DAY-ONE-2 · THE REGISTRY GRANT MUST NOT OUTRUN THE REGISTRY
 * ══════════════════════════════════════════════════════════════════════════
 *
 * ── THE FAILURE THIS PREVENTS ────────────────────────────────────────────
 * Granting USE_FEE_HEAD_REGISTRY switches fee generation to resolving every
 * chart head against feeHeadRegistry, and resolution fails CLOSED: one
 * unresolvable head aborts that student's entire billing.
 *
 * Measured on a clean two-tenant fixture with the flag granted and the
 * registry empty:
 *     ACC_FEEHEAD_UNRESOLVED head=[Tuition Fee] reason=[not_found]
 *     demands=0
 * Students admitted, nobody billable, and the admission still reporting
 * success. A new tenant's registry is EMPTY by construction, so that is the
 * default result of enabling the flag early — not an edge case.
 *
 * The ordering used to live in someone's memory. It is now a precondition:
 * the grant is refused, by name, until the foundation exists.
 *
 * ── WHY THE THREE-VALUED ANSWER MATTERS ──────────────────────────────────
 * Fee_head_readiness returns [] (complete), a list (incomplete), or NULL
 * (could not ask). Collapsing NULL into either of the others is how a guard
 * becomes a lie — it would either refuse every grant forever, or wave through
 * the exact state that stops a school billing.
 */
final class FeeHeadReadinessGateTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once APPPATH . 'libraries/Fee_head_resolver.php';
        require_once APPPATH . 'libraries/Fee_head_readiness.php';
    }

    /** A queryable Firestore double: registry rows + chart rows. */
    private function fs(array $registry, array $chart)
    {
        return new class($registry, $chart) {
            public function __construct(private array $reg, private array $chart) {}
            public function firestoreQuery($col, $where, $o = null, $d = 'ASC', $l = 500): array
            {
                if ($col === Fee_head_resolver::COL) return $this->reg;
                if ($col === 'feeStructures')        return $this->chart;
                return [];
            }
        };
    }

    private function head(string $school, string $name, string $status = 'active'): array
    {
        return ['data' => ['schoolId' => $school, 'name' => $name,
                           'category' => 'academic', 'status' => $status,
                           'feeHeadId' => 'FH_' . strtoupper(substr(sha1($name), 0, 12))]];
    }

    private function chart(string $school, array $names): array
    {
        return [['data' => ['schoolId' => $school,
            'feeHeads' => array_map(static fn($n) => ['name' => $n], $names)]]];
    }

    // ══ THE ANSWER ══════════════════════════════════════════════════════════

    public function test_a_fully_registered_school_is_complete(): void
    {
        $fs = $this->fs(
            [$this->head('SCH_A', 'Tuition Fee'), $this->head('SCH_A', 'Transport Fee')],
            $this->chart('SCH_A', ['Tuition Fee', 'Transport Fee'])
        );
        $this->assertSame([], (new Fee_head_readiness())->missingForSchool($fs, 'SCH_A'));
    }

    public function test_an_unregistered_charted_head_is_reported_by_name(): void
    {
        $fs = $this->fs(
            [$this->head('SCH_A', 'Tuition Fee')],
            $this->chart('SCH_A', ['Tuition Fee', 'Examination Fee'])
        );
        $this->assertSame(['Examination Fee'],
            (new Fee_head_readiness())->missingForSchool($fs, 'SCH_A'),
            'the operator must be told WHICH head is missing; "not ready" alone is unactionable');
    }

    /**
     * Comparison must use the resolver's normalisation. Raw string comparison
     * would pass "Tuition  Fee" against "Tuition Fee" and certify a gap that
     * still breaks billing.
     */
    public function test_whitespace_and_case_do_not_create_a_false_gap(): void
    {
        $fs = $this->fs(
            [$this->head('SCH_A', 'tuition   fee')],
            $this->chart('SCH_A', ['Tuition Fee'])
        );
        $this->assertSame([], (new Fee_head_readiness())->missingForSchool($fs, 'SCH_A'));
    }

    /** An ARCHIVED head must not satisfy a new billing run. */
    public function test_an_archived_head_does_not_count_as_registered(): void
    {
        $fs = $this->fs(
            [$this->head('SCH_A', 'Tuition Fee', 'archived')],
            $this->chart('SCH_A', ['Tuition Fee'])
        );
        $this->assertSame(['Tuition Fee'],
            (new Fee_head_readiness())->missingForSchool($fs, 'SCH_A'),
            'an archived head satisfied the check — resolveForNewUse() will refuse it at '
            . 'generation time, so the school would be certified ready and then bill nobody');
    }

    /** Name-claim tokens share the collection but are not heads. */
    public function test_a_name_claim_does_not_count_as_a_registered_head(): void
    {
        $claim = ['data' => ['schoolId' => 'SCH_A', 'kind' => Fee_head_resolver::KIND_NAME_CLAIM,
                             'nameNorm' => 'tuition fee', 'name' => 'Tuition Fee']];
        $fs = $this->fs([$claim], $this->chart('SCH_A', ['Tuition Fee']));
        $this->assertSame(['Tuition Fee'],
            (new Fee_head_readiness())->missingForSchool($fs, 'SCH_A'));
    }

    /** TENANT ISOLATION: another school's registration must not satisfy this one. */
    public function test_another_tenants_head_does_not_satisfy_this_tenant(): void
    {
        // The query double is already school-filtered in production; this pins
        // that the comparison itself never reaches across tenants.
        $fs = $this->fs([$this->head('SCH_B', 'Tuition Fee')], $this->chart('SCH_A', ['Tuition Fee']));
        $missing = (new Fee_head_readiness())->missingForSchool($fs, 'SCH_A');
        $this->assertSame(['Tuition Fee'], $missing,
            "school B's registration satisfied school A — one tenant's configuration would "
            . 'certify another as ready to bill');
    }

    // ══ "COULD NOT ASK" IS ITS OWN ANSWER ═══════════════════════════════════

    public function test_an_unqueryable_handle_reports_unknown_not_ready(): void
    {
        $this->assertNull((new Fee_head_readiness())->missingForSchool(new stdClass(), 'SCH_A'),
            'a handle that cannot be queried reported READY. That is a clean bill of health '
            . 'the check never established.');
        $this->assertNull((new Fee_head_readiness())->missingForSchool(null, 'SCH_A'));
    }

    public function test_a_read_failure_reports_unknown_not_ready(): void
    {
        $fs = new class {
            public function firestoreQuery($c, $w, $o = null, $d = 'ASC', $l = 500): array
            { throw new \RuntimeException('transient'); }
        };
        $this->assertNull((new Fee_head_readiness())->missingForSchool($fs, 'SCH_A'),
            'a failed read was reported as "no heads missing" — the silent-empty class that '
            . 'this whole programme keeps closing');
    }

    // ══ THE GRANT SURFACE CONSUMES IT ═══════════════════════════════════════

    public function test_the_grant_service_gates_the_registry_flag_on_readiness(): void
    {
        $src = (string) file_get_contents(APPPATH . 'libraries/Rollout_grant_service.php');

        $this->assertStringContainsString("\$flag === 'USE_FEE_HEAD_REGISTRY' && \$enabled", $src,
            'the registry grant is no longer gated on the fee head foundation existing. '
            . 'Granting it against an empty registry makes every admission produce zero '
            . 'demands while the school looks correctly configured.');
        $this->assertStringContainsString('_unregisteredChartHeads', $src);
    }

    /**
     * The grant surface must stay free of iteration. T3 forbids loops there so
     * that no bulk or wildcard grant path can hide in it — which is why the
     * readiness comparison lives in its own class rather than inline.
     */
    public function test_the_grant_service_stays_loop_free(): void
    {
        $src = (string) file_get_contents(APPPATH . 'libraries/Rollout_grant_service.php');
        $this->assertStringNotContainsString('foreach', $src,
            'a loop reappeared in the grant service. T3 forbids it precisely so that '
            . 'estate-wide activation is never one call away.');
    }
}
