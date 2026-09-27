<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Effective-dating boundary semantics, executed against the REAL resolver.
 *
 * `Staff_employment_history_service::resolveAttributeAsOf()` decides which
 * department, position or employment type an employee held on a given date. It
 * is load-bearing for money: payroll feeds its answer to `_staff_working_days()`
 * (expected work = the pay denominator) and the leave engine uses it per day.
 *
 * Seven existing suites reference the resolver, but NONE pins its interval
 * boundary. The implementation is:
 *
 *     if ($ef === '' || $ef > $asOfDate) continue;              // not started yet
 *     if ($et !== null && $et !== '' && $et <= $asOfDate) continue;  // already ended
 *
 * which is a HALF-OPEN window: `[effectiveFrom, effectiveTo)` — the start date
 * IS in the window, the end date is NOT. On a transfer, the employee belongs to
 * the NEW department from the transfer date itself. An off-by-one either way
 * prices the transfer day against the wrong department's calendar.
 *
 * This executes the shipped method rather than a reimplementation. `ready()`
 * needs only an object `fs` and a non-empty `schoolId`; with `warmed = true`,
 * `timelineRows()` reads the in-memory warm index and never touches Firestore.
 * So the real code runs, with no network and no fixture database.
 */
final class EffectiveDateBoundaryBehaviourTest extends TestCase
{
    private $svc;
    private ReflectionClass $rc;

    protected function setUp(): void
    {
        $path = __DIR__ . '/../../application/libraries/Staff_employment_history_service.php';
        $this->assertFileExists($path);
        require_once $path;

        $this->rc  = new ReflectionClass('Staff_employment_history_service');
        $this->svc = $this->rc->newInstanceWithoutConstructor();

        // Satisfy ready() without a container.
        $this->set('fs', new stdClass());
        $this->set('schoolId', 'SCH_TEST0001');
    }

    private function set(string $prop, $value): void
    {
        if (!$this->rc->hasProperty($prop)) {
            $this->markTestSkipped("property {$prop} no longer exists — re-read the resolver");
        }
        $p = $this->rc->getProperty($prop);
        $p->setAccessible(true);
        $p->setValue($this->svc, $value);
    }

    /** Seed the warm index so timelineRows() short-circuits to memory. */
    private function seed(array $windows, string $attribute = 'department', string $staffId = 'STA0007'): void
    {
        $rows = [];
        foreach ($windows as $w) {
            $rows[] = ['data' => [
                'staffId'       => $staffId,
                'attribute'     => $attribute,
                'value'         => $w[0],
                'effectiveFrom' => $w[1],
                'effectiveTo'   => $w[2],
            ]];
        }
        $this->set('warmed', true);
        $this->set('warmIndex', [$staffId . '|' . $attribute => $rows]);
    }

    private function resolve(string $date, string $attribute = 'department', string $staffId = 'STA0007'): ?string
    {
        return $this->svc->resolveAttributeAsOf($staffId, $attribute, $date);
    }

    // ── The boundary that decides the transfer day ───────────────────────

    /**
     * Science closes on 2026-06-01 and Maths opens the same day. On that exact
     * date the employee must resolve to Maths — the new department owns the
     * transfer day.
     */
    public function test_transfer_day_belongs_to_the_new_department(): void
    {
        $this->seed([
            ['Science', '2026-01-01', '2026-06-01'],
            ['Maths',   '2026-06-01', null],
        ]);

        $this->assertSame('Science', $this->resolve('2026-05-31'), 'day before transfer');
        $this->assertSame('Maths',   $this->resolve('2026-06-01'), 'THE TRANSFER DAY');
        $this->assertSame('Maths',   $this->resolve('2026-06-02'), 'day after transfer');
    }

    public function test_effective_from_is_inclusive(): void
    {
        $this->seed([['Maths', '2026-06-01', null]]);

        $this->assertNull($this->resolve('2026-05-31'), 'window has not started');
        $this->assertSame('Maths', $this->resolve('2026-06-01'), 'start date is INSIDE the window');
    }

    public function test_effective_to_is_exclusive(): void
    {
        $this->seed([['Science', '2026-01-01', '2026-06-01']]);

        $this->assertSame('Science', $this->resolve('2026-05-31'));
        $this->assertNull($this->resolve('2026-06-01'), 'end date is OUTSIDE the window');
    }

    /** A one-day window is representable: [d, d+1). */
    public function test_a_single_day_window_resolves_on_exactly_that_day(): void
    {
        $this->seed([['Relief', '2026-06-01', '2026-06-02']]);

        $this->assertNull($this->resolve('2026-05-31'));
        $this->assertSame('Relief', $this->resolve('2026-06-01'));
        $this->assertNull($this->resolve('2026-06-02'));
    }

    /** A zero-width window [d, d) can never resolve — and must not. */
    public function test_a_zero_width_window_never_resolves(): void
    {
        $this->seed([['Ghost', '2026-06-01', '2026-06-01']]);

        $this->assertNull($this->resolve('2026-06-01'),
            'effectiveTo == effectiveFrom is an empty interval');
    }

    // ── Open-ended and unstarted windows ─────────────────────────────────

    public function test_an_open_window_extends_indefinitely(): void
    {
        $this->seed([['Maths', '2026-06-01', null]]);
        $this->assertSame('Maths', $this->resolve('2099-12-31'));
    }

    public function test_empty_string_effective_to_is_treated_as_open(): void
    {
        $this->seed([['Maths', '2026-06-01', '']]);
        $this->assertSame('Maths', $this->resolve('2099-12-31'),
            "'' must behave like null, not like an end date");
    }

    public function test_a_future_dated_window_is_not_active_yet(): void
    {
        $this->seed([['Physics', '2027-01-01', null]]);
        $this->assertNull($this->resolve('2026-06-01'),
            'a scheduled future transfer must not affect today');
    }

    /** A row with no effectiveFrom is unusable and must be ignored. */
    public function test_a_window_with_no_start_is_ignored(): void
    {
        $this->seed([['Orphan', '', null]]);
        $this->assertNull($this->resolve('2026-06-01'));
    }

    // ── Overlap: the documented corruption defence ───────────────────────

    /**
     * The writer always closes the prior window before opening a new one, so
     * overlaps only occur under corruption. The resolver then picks the LATEST
     * effectiveFrom. Pinned because "most recently started wins" is a deliberate
     * choice, not an accident of iteration order.
     */
    public function test_overlapping_windows_resolve_to_the_latest_start(): void
    {
        $this->seed([
            ['Older', '2026-01-01', null],
            ['Newer', '2026-03-01', null],
        ]);

        $this->assertSame('Newer', $this->resolve('2026-06-01'));
    }

    /** Order of rows must not change the answer. */
    public function test_overlap_resolution_is_independent_of_row_order(): void
    {
        $this->seed([
            ['Newer', '2026-03-01', null],
            ['Older', '2026-01-01', null],
        ]);

        $this->assertSame('Newer', $this->resolve('2026-06-01'),
            'iteration order must not decide which department prices a day');
    }

    // ── Input validation ─────────────────────────────────────────────────

    public function test_a_malformed_date_resolves_to_null(): void
    {
        $this->seed([['Maths', '2026-01-01', null]]);

        foreach (['', '2026-6-1', '01-06-2026', '2026/06/01', 'today', '20260601'] as $bad) {
            $this->assertNull($this->resolve($bad), "'{$bad}' must be rejected, not coerced");
        }
    }

    public function test_an_unknown_attribute_resolves_to_null(): void
    {
        $this->seed([['Maths', '2026-01-01', null]]);
        $this->assertNull($this->resolve('2026-06-01', 'salary'),
            'the attribute allow-list must not be bypassable');
    }

    public function test_an_empty_staff_id_resolves_to_null(): void
    {
        $this->seed([['Maths', '2026-01-01', null]]);
        $this->assertNull($this->resolve('2026-06-01', 'department', ''));
    }

    /** No timeline for this staff member is a clean null, not an error. */
    public function test_a_staff_member_with_no_timeline_resolves_to_null(): void
    {
        $this->seed([['Maths', '2026-01-01', null]]);
        $this->assertNull($this->resolve('2026-06-01', 'department', 'STA9999'));
    }

    // ── Source pin ───────────────────────────────────────────────────────

    /** If either comparator changes, the transfer day silently moves. */
    public function test_shipped_comparators_still_describe_a_half_open_window(): void
    {
        $src = file_get_contents(
            __DIR__ . '/../../application/libraries/Staff_employment_history_service.php'
        );
        $this->assertNotFalse($src);

        $this->assertStringContainsString("if (\$ef === '' || \$ef > \$asOfDate) continue;", $src,
            'effectiveFrom must stay INCLUSIVE');
        $this->assertStringContainsString("<= \$asOfDate) continue;", $src,
            'effectiveTo must stay EXCLUSIVE');
    }
}
