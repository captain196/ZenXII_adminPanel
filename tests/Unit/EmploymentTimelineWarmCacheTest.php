<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Counts the Firestore reads the effective-dating resolver performs.
 *
 * NO-N+1 (2026-08-19). resolveAttributeAsOf() is a real Firestore query with no
 * internal caching, and generate_payroll() calls it TWICE per employee inside
 * the roster loop — department and position. On a 200-staff school that was 400
 * queries for one payroll run: an N+1 introduced by effective dating itself.
 *
 * warmTimeline() preloads the tenant's timeline in one query. These tests
 * EXECUTE the shipped service against a counting stub, so they measure the
 * actual number of reads rather than asserting on source text — and they check
 * the warm and cold paths return the SAME answers, because a cache that changes
 * an answer is worse than the N+1 it replaced.
 */
final class EmploymentTimelineWarmCacheTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once APPPATH . 'libraries/Staff_employment_history_service.php';
    }

    private function rows(): array
    {
        // Two employees, each with a department and a position history.
        $mk = fn(string $sid, string $attr, string $val, string $from, ?string $to) => ['data' => [
            'staffId' => $sid, 'attribute' => $attr, 'value' => $val,
            'effectiveFrom' => $from, 'effectiveTo' => $to,
        ]];

        return [
            $mk('STA001', 'department', 'Junior School', '2020-01-01', '2026-04-01'),
            $mk('STA001', 'department', 'Senior School', '2026-04-01', null),
            $mk('STA001', 'position',   'Teacher',       '2020-01-01', null),
            $mk('STA002', 'department', 'Admin',         '2020-01-01', null),
            $mk('STA002', 'position',   'Clerk',         '2020-01-01', null),
        ];
    }

    /** A Firestore_service stand-in that counts reads and honours the filters. */
    private function stubFs(array $rows, ?object &$handle = null): object
    {
        $handle = new class($rows) {
            public int $queries = 0;
            private array $rows;
            public function __construct(array $rows) { $this->rows = $rows; }
            public function schoolWhere(string $collection, array $conditions)
            {
                $this->queries++;
                $out = [];
                foreach ($this->rows as $r) {
                    $d = $r['data'];
                    $ok = true;
                    foreach ($conditions as [$field, $op, $val]) {
                        if ($op !== '==' || ($d[$field] ?? null) !== $val) { $ok = false; break; }
                    }
                    if ($ok) $out[] = $r;
                }
                return $out;
            }
        };
        return $handle;
    }

    private function service(array $rows, ?object &$fs = null): Staff_employment_history_service
    {
        $stub = $this->stubFs($rows, $fs);
        $svc  = new Staff_employment_history_service();
        $svc->init($stub, 'SCH_TEST', 'ADMIN1');
        return $svc;
    }

    // ── Correctness first: the cache must not change any answer ──────────

    public function test_cold_path_resolves_the_historical_value(): void
    {
        $svc = $this->service($this->rows());

        $this->assertSame('Junior School', $svc->resolveAttributeAsOf('STA001', 'department', '2026-03-01'));
        $this->assertSame('Senior School', $svc->resolveAttributeAsOf('STA001', 'department', '2026-04-01'));
        $this->assertSame('Teacher',       $svc->resolveAttributeAsOf('STA001', 'position',   '2026-04-01'));
        $this->assertSame('Admin',         $svc->resolveAttributeAsOf('STA002', 'department', '2026-04-01'));
    }

    public function test_warm_path_returns_identical_answers(): void
    {
        $cold = $this->service($this->rows());
        $warm = $this->service($this->rows());
        $this->assertTrue($warm->warmTimeline());

        foreach ([
            ['STA001', 'department', '2026-03-01'],
            ['STA001', 'department', '2026-04-01'],
            ['STA001', 'department', '2026-05-15'],
            ['STA001', 'position',   '2026-04-01'],
            ['STA002', 'department', '2026-04-01'],
            ['STA002', 'position',   '2026-04-01'],
            ['STA999', 'department', '2026-04-01'],   // unknown staff
        ] as [$sid, $attr, $date]) {
            $this->assertSame(
                $cold->resolveAttributeAsOf($sid, $attr, $date),
                $warm->resolveAttributeAsOf($sid, $attr, $date),
                "warm and cold disagree for {$sid}/{$attr} at {$date}"
            );
        }
    }

    // ── Then the read count ──────────────────────────────────────────────

    public function test_cold_path_costs_one_query_per_staff_attribute(): void
    {
        $svc = $this->service($this->rows(), $fs);

        $svc->resolveAttributeAsOf('STA001', 'department', '2026-04-01');
        $svc->resolveAttributeAsOf('STA001', 'position',   '2026-04-01');
        $svc->resolveAttributeAsOf('STA002', 'department', '2026-04-01');
        $svc->resolveAttributeAsOf('STA002', 'position',   '2026-04-01');

        $this->assertSame(4, $fs->queries, 'this is the N+1 the warm path removes');
    }

    public function test_warm_path_costs_exactly_one_query_regardless_of_roster_size(): void
    {
        $svc = $this->service($this->rows(), $fs);
        $svc->warmTimeline();

        foreach (['STA001', 'STA002'] as $sid) {
            foreach (['department', 'position'] as $attr) {
                $svc->resolveAttributeAsOf($sid, $attr, '2026-04-01');
            }
        }

        $this->assertSame(1, $fs->queries, 'the whole timeline is read once');
    }

    public function test_warming_twice_does_not_read_twice(): void
    {
        $svc = $this->service($this->rows(), $fs);
        $this->assertTrue($svc->warmTimeline());
        $this->assertTrue($svc->warmTimeline());

        $this->assertSame(1, $fs->queries);
    }

    /** Even without warming, a repeated lookup must not re-query. */
    public function test_cold_path_memoizes_repeated_lookups(): void
    {
        $svc = $this->service($this->rows(), $fs);

        $svc->resolveAttributeAsOf('STA001', 'department', '2026-03-01');
        $svc->resolveAttributeAsOf('STA001', 'department', '2026-04-01');
        $svc->resolveAttributeAsOf('STA001', 'department', '2026-05-01');

        $this->assertSame(1, $fs->queries, 'three dates, one staff/attribute, one read');
    }

    public function test_cooling_forces_a_fresh_read(): void
    {
        $svc = $this->service($this->rows(), $fs);
        $svc->warmTimeline();
        $svc->resolveAttributeAsOf('STA001', 'department', '2026-04-01');
        $this->assertSame(1, $fs->queries);

        $svc->coolTimeline();
        $svc->resolveAttributeAsOf('STA001', 'department', '2026-04-01');
        $this->assertSame(2, $fs->queries, 'after cooling the value is re-read');
    }

    // ── Failure behaviour ────────────────────────────────────────────────

    /**
     * A failed warm must leave the service usable on the cold path — degrading
     * to the previous behaviour, never to a wrong answer.
     */
    public function test_failed_warm_falls_back_to_per_staff_reads(): void
    {
        $throwing = new class {
            public int $calls = 0;
            public function schoolWhere(string $collection, array $conditions)
            {
                $this->calls++;
                if (empty($conditions)) throw new \RuntimeException('warm read failed');
                return [['data' => [
                    'staffId' => 'STA001', 'attribute' => 'department',
                    'value' => 'Senior School', 'effectiveFrom' => '2020-01-01', 'effectiveTo' => null,
                ]]];
            }
        };

        $svc = new Staff_employment_history_service();
        $svc->init($throwing, 'SCH_TEST', 'ADMIN1');

        $this->assertFalse($svc->warmTimeline(), 'a failed warm reports failure');
        $this->assertSame('Senior School', $svc->resolveAttributeAsOf('STA001', 'department', '2026-04-01'));
    }

    /** An uninitialised service cannot warm and cannot resolve. */
    public function test_uninitialised_service_is_inert(): void
    {
        $svc = new Staff_employment_history_service();

        $this->assertFalse($svc->warmTimeline());
        $this->assertNull($svc->resolveAttributeAsOf('STA001', 'department', '2026-04-01'));
    }

    /** Malformed input is rejected before any read happens. */
    public function test_bad_input_never_reaches_firestore(): void
    {
        $svc = $this->service($this->rows(), $fs);

        $this->assertNull($svc->resolveAttributeAsOf('', 'department', '2026-04-01'));
        $this->assertNull($svc->resolveAttributeAsOf('STA001', 'not_an_attribute', '2026-04-01'));
        $this->assertNull($svc->resolveAttributeAsOf('STA001', 'department', '01-04-2026'));

        $this->assertSame(0, $fs->queries);
    }
}
