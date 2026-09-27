<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once APPPATH . 'libraries/Calendar_service.php';

/** Counts reads so a cache hit is observable, and can be made to fail. */
final class CountingCalendarFs
{
    public int $reads = 0;
    public array $rows = [];
    public bool $throw = false;

    public function sessionWhere(string $collection, array $filters)
    {
        $this->reads++;
        if ($this->throw) throw new RuntimeException('simulated Firestore failure');
        return $this->rows;
    }
}

final class RecordingCalendarFirebase
{
    public array $writes = [];
    public function firestoreSet($c, $id, $d, $merge = false) { $this->writes[] = ['set', $id]; return true; }
    public function firestoreDelete($c, $id) { $this->writes[] = ['delete', $id]; return true; }
    public function firestoreGet($c, $id) { return null; }
    public function firestoreGetOrFail($c, $id) { return null; }
}

/**
 * CAL-PERF-1 — the calendar read cache.
 *
 * The latency was MEASURED, not assumed: a cold `sessionWhere('calendarEvents')`
 * costs ~1.9 s on this deployment returning ZERO documents, so the cost is the
 * round trip per PHP process. The Academic Planner re-reads the whole list on
 * every prev/next month click, so paging a year paid twelve identical reads.
 *
 * A cache is only worth having if it cannot go stale in the one way that
 * matters — an administrator not seeing their own save. These tests exist to
 * prove that, not to prove it is fast.
 */
final class CalendarServiceCacheTest extends TestCase
{
    private const SCHOOL = 'SCH_A';
    private const SESS   = '2026-27';

    protected function setUp(): void
    {
        // The memo is static, so it MUST be cleared between tests or the second
        // test would silently reuse the first's data and prove nothing.
        $ref = new ReflectionClass(Calendar_service::class);
        $prop = $ref->getProperty('memo');
        $prop->setAccessible(true);
        $prop->setValue(null, []);

        foreach (glob(APPPATH . 'cache/calendar_events/*.json') ?: [] as $f) @unlink($f);
    }

    protected function tearDown(): void
    {
        foreach (glob(APPPATH . 'cache/calendar_events/*.json') ?: [] as $f) @unlink($f);
    }

    private function svc(CountingCalendarFs $fs, RecordingCalendarFirebase $fb = null,
                         string $school = self::SCHOOL): Calendar_service
    {
        return (new Calendar_service())->init(
            $fb ?: new RecordingCalendarFirebase(), $fs, $school, self::SESS, 'STA1', 'Probe');
    }

    private function rows(): array
    {
        return [
            ['id' => self::SCHOOL . '_EVT_1', 'data' => [
                'title' => 'Diwali', 'type' => 'holiday',
                'startDate' => '2026-10-20', 'endDate' => '2026-10-20']],
            ['id' => self::SCHOOL . '_EVT_2', 'data' => [
                'title' => 'Sports Day', 'type' => 'event',
                'startDate' => '2026-11-05', 'endDate' => '2026-11-05']],
        ];
    }

    // ── THE CACHE WORKS ──────────────────────────────────────────────────

    public function test_a_second_read_does_not_hit_firestore_again(): void
    {
        $fs = new CountingCalendarFs();
        $fs->rows = $this->rows();

        $a = $this->svc($fs)->getEvents('2026-10');
        $this->assertSame(1, $fs->reads, 'the first call must actually read');

        // A NEW instance — this models the next HTTP request, not the same one.
        $b = $this->svc($fs)->getEvents('2026-11');
        $this->assertSame(1, $fs->reads,
            'the second month load must be served from cache, not a second round trip');

        $this->assertSame('Diwali',     $a['events'][0]['title']);
        $this->assertSame('Sports Day', $b['events'][0]['title']);
    }

    /** Caching must not change what comes back. */
    public function test_cached_results_are_identical_to_uncached(): void
    {
        $fs = new CountingCalendarFs();
        $fs->rows = $this->rows();

        $first = $this->svc($fs)->getEvents('2026-10');
        $again = $this->svc($fs)->getEvents('2026-10');

        $this->assertSame($first, $again,
            'a cache that returns something different is a bug, not an optimisation');
        $this->assertSame('EVT_1', $first['events'][0]['id'],
            'the docId-derived id must survive a round trip through the cache');
    }

    public function test_the_month_filter_still_applies_over_cached_data(): void
    {
        $fs = new CountingCalendarFs();
        $fs->rows = $this->rows();

        $oct = $this->svc($fs)->getEvents('2026-10');
        $nov = $this->svc($fs)->getEvents('2026-11');
        $dec = $this->svc($fs)->getEvents('2026-12');

        $this->assertCount(1, $oct['events']);
        $this->assertCount(1, $nov['events']);
        $this->assertCount(0, $dec['events'], 'an empty month must stay empty');
    }

    // ── THE FAILURE MODE THAT MATTERS ────────────────────────────────────

    /**
     * An administrator must never save an event and then not see it. This is
     * the single reason a cache here could be worse than the latency it removes.
     */
    public function test_saving_an_event_invalidates_the_cache(): void
    {
        $fs = new CountingCalendarFs();
        $fs->rows = $this->rows();
        $fb  = new RecordingCalendarFirebase();
        $svc = $this->svc($fs, $fb);

        $svc->getEvents('2026-10');
        $this->assertSame(1, $fs->reads);

        $svc->saveEvent('', 'Annual Day', 'event', '2026-12-01', '2026-12-01', '', null);

        // The next read must go back to Firestore.
        $svc->getEvents('2026-12');
        $this->assertSame(2, $fs->reads,
            'after a save the list must be re-read — otherwise the administrator '
            . 'does not see their own event');
    }

    public function test_deleting_an_event_invalidates_the_cache(): void
    {
        $fs = new CountingCalendarFs();
        $fs->rows = $this->rows();
        $svc = $this->svc($fs);

        $svc->getEvents('2026-10');
        $this->assertSame(1, $fs->reads);

        try { $svc->deleteEvent('EVT_1'); } catch (\Throwable $e) { /* shape varies */ }

        $svc->getEvents('2026-10');
        $this->assertSame(2, $fs->reads, 'a delete must not leave the event cached');
    }

    /** A transient error must not persist as "this school has no calendar". */
    public function test_a_read_failure_is_never_cached(): void
    {
        $fs = new CountingCalendarFs();
        $fs->throw = true;

        $out = $this->svc($fs)->getEvents('2026-10');
        $this->assertSame([], $out['events'], 'a failed read yields nothing');
        $this->assertSame(1, $fs->reads);

        // Recovery on the very next call, not two minutes later.
        $fs->throw = false;
        $fs->rows  = $this->rows();
        $out2 = $this->svc($fs)->getEvents('2026-10');

        $this->assertSame(2, $fs->reads, 'the failure must not have been cached');
        $this->assertCount(1, $out2['events'], 'and the data must appear immediately');
    }

    // ── TENANT ISOLATION ─────────────────────────────────────────────────

    public function test_one_schools_cache_never_serves_another(): void
    {
        $fsA = new CountingCalendarFs(); $fsA->rows = $this->rows();
        $this->svc($fsA)->getEvents('2026-10');
        $this->assertSame(1, $fsA->reads);

        $fsB = new CountingCalendarFs();
        $fsB->rows = [['id' => 'SCH_B_EVT_9', 'data' => [
            'title' => "B's event", 'type' => 'event',
            'startDate' => '2026-10-20', 'endDate' => '2026-10-20']]];

        $b = $this->svc($fsB, null, 'SCH_B')->getEvents('2026-10');

        $this->assertSame(1, $fsB->reads,
            "school B must perform its OWN read — a shared cache key would be a "
            . 'cross-tenant data leak');
        $this->assertSame("B's event", $b['events'][0]['title']);
    }

    public function test_a_different_session_is_a_different_cache(): void
    {
        $fs = new CountingCalendarFs(); $fs->rows = $this->rows();
        $this->svc($fs)->getEvents('2026-10');

        $other = (new Calendar_service())->init(
            new RecordingCalendarFirebase(), $fs, self::SCHOOL, '2027-28', 'STA1', 'Probe');
        $other->getEvents('2026-10');

        $this->assertSame(2, $fs->reads,
            'sessions must not share a cache entry — the writer scopes by session');
    }
}
