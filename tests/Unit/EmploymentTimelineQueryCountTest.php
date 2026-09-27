<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Measured query counts for effective-date resolution — the lifecycle's N+1 risk.
 *
 * `resolveAttributeAsOf()` is called per staff member, and in the leave and
 * attendance engines per DAY. Each cold call issues its own Firestore query:
 *
 *     $this->fs->schoolWhere(TIMELINE_COL, [
 *         ['staffId', '==', $staffId], ['attribute', '==', $attribute],
 *     ]);
 *
 * For a payroll run over a 200-staff roster that is 200 round trips before any
 * money is computed. `warmTimeline()` exists to collapse that into ONE query
 * whose result is indexed by `staffId|attribute`.
 *
 * This suite COUNTS the queries the real service actually issues, rather than
 * asserting that a warm call appears somewhere in the source. It drives
 * `Staff_employment_history_service` through a recording double that logs every
 * `schoolWhere()`, so the numbers below are measurements.
 *
 * The correctness half matters more than the count: an optimisation that
 * changes an answer is a defect, not a speed-up. Every scenario therefore
 * asserts that the warm path returns EXACTLY what the cold path returns.
 */
final class EmploymentTimelineQueryCountTest extends TestCase
{
    private const N_STAFF = 50;

    private $svc;
    private $fs;

    protected function setUp(): void
    {
        $path = __DIR__ . '/../../application/libraries/Staff_employment_history_service.php';
        $this->assertFileExists($path);
        require_once $path;

        $this->fs  = new TimelineRecordingFs($this->rows());
        $this->svc = (new Staff_employment_history_service())
            ->init($this->fs, 'SCH_TEST0001', 'STA0001');
    }

    /** One department window per staff member, opened 2026-01-01. */
    private function rows(): array
    {
        $rows = [];
        for ($i = 1; $i <= self::N_STAFF; $i++) {
            $sid = sprintf('STA%04d', $i);
            $rows[] = ['data' => [
                'staffId'       => $sid,
                'attribute'     => 'department',
                'value'         => ($i % 2 === 0) ? 'Science' : 'Maths',
                'effectiveFrom' => '2026-01-01',
                'effectiveTo'   => null,
            ]];
        }
        return $rows;
    }

    private function staffIds(): array
    {
        $out = [];
        for ($i = 1; $i <= self::N_STAFF; $i++) $out[] = sprintf('STA%04d', $i);
        return $out;
    }

    /** Resolve department for the whole roster, returning the answers. */
    private function resolveRoster(string $date = '2026-06-01'): array
    {
        $out = [];
        foreach ($this->staffIds() as $sid) {
            $out[$sid] = $this->svc->resolveAttributeAsOf($sid, 'department', $date);
        }
        return $out;
    }

    // ── The cold path is genuinely N+1 ───────────────────────────────────

    public function test_cold_resolution_issues_one_query_per_staff_member(): void
    {
        $this->resolveRoster();

        $this->assertSame(
            self::N_STAFF,
            $this->fs->count('schoolWhere'),
            'without warming, every staff member costs its own round trip'
        );
    }

    /** The per-(staff,attribute) cache means a repeat costs nothing. */
    public function test_resolving_the_same_staff_twice_does_not_re_query(): void
    {
        $this->svc->resolveAttributeAsOf('STA0001', 'department', '2026-06-01');
        $before = $this->fs->count('schoolWhere');

        $this->svc->resolveAttributeAsOf('STA0001', 'department', '2026-07-01');
        $this->svc->resolveAttributeAsOf('STA0001', 'department', '2026-08-01');

        $this->assertSame($before, $this->fs->count('schoolWhere'),
            'the row cache must absorb repeat lookups for the same staff+attribute');
    }

    // ── Warming collapses it to one ──────────────────────────────────────

    public function test_warming_collapses_the_whole_roster_to_a_single_query(): void
    {
        $this->assertTrue($this->svc->warmTimeline());
        $this->resolveRoster();

        $this->assertSame(1, $this->fs->count('schoolWhere'),
            'one query must serve the entire roster');
    }

    public function test_warming_is_idempotent(): void
    {
        $this->svc->warmTimeline();
        $this->svc->warmTimeline();
        $this->svc->warmTimeline();

        $this->assertSame(1, $this->fs->count('schoolWhere'),
            'a second warm must not re-read the collection');
    }

    /** The measured saving, stated as the ratio it actually is. */
    public function test_the_saving_scales_with_roster_size(): void
    {
        $cold = new Staff_employment_history_service();
        $coldFs = new TimelineRecordingFs($this->rows());
        $cold->init($coldFs, 'SCH_TEST0001', 'STA0001');
        foreach ($this->staffIds() as $sid) $cold->resolveAttributeAsOf($sid, 'department', '2026-06-01');

        $this->svc->warmTimeline();
        $this->resolveRoster();

        $this->assertSame(self::N_STAFF, $coldFs->count('schoolWhere'));
        $this->assertSame(1, $this->fs->count('schoolWhere'));
        $this->assertGreaterThanOrEqual(
            self::N_STAFF,
            (int) ($coldFs->count('schoolWhere') / max(1, $this->fs->count('schoolWhere'))),
            'the whole point is that the ratio IS the roster size'
        );
    }

    // ── Correctness is preserved — the half that matters ─────────────────

    /**
     * An optimisation that changes an answer is a defect. Cold and warm must
     * agree on every staff member.
     */
    public function test_warm_and_cold_return_identical_answers(): void
    {
        $cold = new Staff_employment_history_service();
        $cold->init(new TimelineRecordingFs($this->rows()), 'SCH_TEST0001', 'STA0001');

        $coldAnswers = [];
        foreach ($this->staffIds() as $sid) {
            $coldAnswers[$sid] = $cold->resolveAttributeAsOf($sid, 'department', '2026-06-01');
        }

        $this->svc->warmTimeline();
        $warmAnswers = $this->resolveRoster();

        $this->assertSame($coldAnswers, $warmAnswers,
            'warming must be invisible to the caller except in query count');
        $this->assertSame('Maths', $warmAnswers['STA0001']);
        $this->assertSame('Science', $warmAnswers['STA0002']);
    }

    /** Effective-date boundaries must behave the same warm as cold. */
    public function test_the_effective_date_boundary_is_unaffected_by_warming(): void
    {
        $this->svc->warmTimeline();

        $this->assertNull($this->svc->resolveAttributeAsOf('STA0001', 'department', '2025-12-31'),
            'a date before the window must resolve to null, warm or cold');
        $this->assertSame('Maths', $this->svc->resolveAttributeAsOf('STA0001', 'department', '2026-01-01'),
            'the start date is inclusive, warm or cold');
    }

    /** A staff member with no timeline resolves to null from the warm index. */
    public function test_an_unknown_staff_member_resolves_to_null_when_warm(): void
    {
        $this->svc->warmTimeline();

        $this->assertNull($this->svc->resolveAttributeAsOf('STA9999', 'department', '2026-06-01'));
        $this->assertSame(1, $this->fs->count('schoolWhere'),
            'a warm miss must NOT fall back to a per-staff query — that would reintroduce N+1');
    }

    // ── Cooling restores correctness after a write ───────────────────────

    /**
     * A writer that changes the timeline mid-request must be able to invalidate
     * the index, or subsequent reads serve stale data.
     */
    public function test_cooling_restores_per_staff_reads(): void
    {
        $this->svc->warmTimeline();
        $this->svc->resolveAttributeAsOf('STA0001', 'department', '2026-06-01');
        $this->assertSame(1, $this->fs->count('schoolWhere'));

        $this->svc->coolTimeline();
        $this->svc->resolveAttributeAsOf('STA0001', 'department', '2026-06-01');

        $this->assertGreaterThan(1, $this->fs->count('schoolWhere'),
            'after cooling, the service must go back to the source');
    }

    public function test_cooling_then_rewarming_costs_one_more_query(): void
    {
        $this->svc->warmTimeline();
        $this->svc->coolTimeline();
        $this->svc->warmTimeline();

        $this->assertSame(2, $this->fs->count('schoolWhere'));
    }

    // ── Failure is a fallback, not a crash ───────────────────────────────

    /**
     * If the warm query fails the service must fall back to per-staff reads and
     * still return correct answers — degraded speed, never wrong data.
     */
    public function test_a_failed_warm_falls_back_to_per_staff_reads(): void
    {
        $fs  = new TimelineRecordingFs($this->rows(), true);   // warm query throws
        $svc = (new Staff_employment_history_service())->init($fs, 'SCH_TEST0001', 'STA0001');

        $this->assertFalse($svc->warmTimeline(), 'a failed warm must report failure');

        // Per-staff reads still work (the double only fails the unfiltered query).
        $this->assertSame('Maths', $svc->resolveAttributeAsOf('STA0001', 'department', '2026-06-01'));
    }

    // ── Payroll actually warms ───────────────────────────────────────────

    /**
     * Measuring is pointless if the production paths never call it.
     *
     * THREE code paths resolve effective-dated attributes across a whole
     * roster, and every one of them must warm first:
     *
     *   Hr.php                          payroll roster loop
     *   Staff_attendance_finaliser.php  nightly auto-absent finalisation
     *   Attendance.php                  the staff attendance GRID (user-facing)
     *
     * The latter two were added 2026-08-21 after this suite measured the cost.
     * A fourth consumer added without warming reintroduces N round trips, so
     * this asserts all three rather than "warmTimeline appears somewhere".
     */
    public function test_every_roster_wide_consumer_warms_the_timeline(): void
    {
        foreach ([
            'controllers/Hr.php'                      => 'payroll roster loop',
            'libraries/Staff_attendance_finaliser.php' => 'nightly finaliser',
            'controllers/Attendance.php'               => 'staff attendance grid',
        ] as $file => $why) {
            $src = file_get_contents(__DIR__ . '/../../application/' . $file);
            $this->assertNotFalse($src, "{$file} not found");

            $this->assertStringContainsString(
                'warmTimeline()',
                $src,
                "the {$why} resolves per staff member — without warming it pays N round trips"
            );
        }
    }

    /**
     * Each warm must be fail-open. A history lookup is a convenience; it must
     * never be able to block a payroll run, a nightly job or a page render.
     */
    public function test_each_warm_is_wrapped_so_a_failure_cannot_block_the_caller(): void
    {
        foreach ([
            'libraries/Staff_attendance_finaliser.php',
            'controllers/Attendance.php',
        ] as $file) {
            $src = file_get_contents(__DIR__ . '/../../application/' . $file);
            $this->assertNotFalse($src);

            $i = strpos($src, 'warmTimeline()');
            $this->assertNotFalse($i, "{$file} must warm");

            // 2026-09-14 — was substr($src, $i - 400, 800). The needles sat 164-211
            // characters from the edge, so a few added lines would have failed this
            // test while the warm stayed correctly wrapped. Bounded by the enclosing
            // method instead: the region the claim is actually about, however it grows.
            $mStart = strrpos(substr($src, 0, $i), '    private function ');
            $this->assertNotFalse($mStart, "{$file}: the warm is not inside a method");
            $mEnd = preg_match('/\n    (?:public|private|protected) function /', $src, $mm,
                               PREG_OFFSET_CAPTURE, $i) ? $mm[0][1] : strlen($src);
            $window = substr($src, $mStart, $mEnd - $mStart);
            $this->assertStringContainsString('try {', $window,
                "{$file}: the warm must be inside a try, or a timeline outage breaks the caller");
            $this->assertStringContainsString('falling back to per-staff reads', $window,
                "{$file}: the fallback must be logged, not silent");
        }
    }
}

/**
 * Recording double for the Firestore helper.
 *
 * Serves timeline rows and counts every call, so query volume is measured
 * rather than assumed. Only the methods the service actually reaches are
 * implemented.
 */
final class TimelineRecordingFs
{
    private array $calls = [];

    public function __construct(private array $rows, private bool $failUnfiltered = false) {}

    public function schoolWhere(string $collection, array $conditions = []): array
    {
        $this->calls[] = 'schoolWhere';

        // No conditions == the warm, whole-collection read.
        if ($conditions === []) {
            if ($this->failUnfiltered) throw new \RuntimeException('simulated warm failure');
            return $this->rows;
        }

        $staffId = null;
        $attr    = null;
        foreach ($conditions as $c) {
            if (($c[0] ?? '') === 'staffId')   $staffId = $c[2] ?? null;
            if (($c[0] ?? '') === 'attribute') $attr    = $c[2] ?? null;
        }

        return array_values(array_filter($this->rows, static function ($r) use ($staffId, $attr) {
            $d = $r['data'] ?? [];
            return ($staffId === null || ($d['staffId'] ?? null) === $staffId)
                && ($attr === null || ($d['attribute'] ?? null) === $attr);
        }));
    }

    public function docId(string $id): string
    {
        return 'SCH_TEST0001_' . $id;
    }

    public function count(string $method): int
    {
        return count(array_filter($this->calls, static fn($c) => $c === $method));
    }
}
