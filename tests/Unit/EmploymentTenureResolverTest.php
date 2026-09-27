<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Service duration as an INPUT to Full & Final — never as a verdict.
 *
 * Gratuity turns on how long somebody worked here, so that number needs one
 * derivation, not two. `Staff::get_employment_history()` already built
 * employment episodes inline; on 2026-08-22 that derivation was EXTRACTED to
 * `Staff_employment_history_service::episodesFromEvents()` so the settlement
 * and the history screen cannot drift. This suite executes the extracted
 * methods directly — both are pure and static, needing no Firestore.
 *
 * `serviceDaysFromEpisodes()` deliberately returns BOTH readings:
 *
 *     totalDays       every stint added together
 *     continuousDays  the current unbroken stint only
 *
 * Which one a gratuity calculation should use is unresolved policy (D-002 Q1),
 * and this code must not pre-empt it. For someone who never left the two are
 * identical — the ordinary case. They diverge only after a rehire, and that
 * divergence is exactly what an owner has to rule on, so `hasBreak` surfaces it
 * rather than quietly picking a side.
 */
final class EmploymentTenureResolverTest extends TestCase
{
    protected function setUp(): void
    {
        require_once __DIR__ . '/../../application/libraries/Staff_employment_history_service.php';
    }

    private function evt(string $type, string $from): array
    {
        return ['eventType' => $type, 'effectiveFrom' => $from];
    }

    private function episodes(array $events): array
    {
        return Staff_employment_history_service::episodesFromEvents($events);
    }

    private function service(array $episodes, string $asOf): array
    {
        return Staff_employment_history_service::serviceDaysFromEpisodes($episodes, $asOf);
    }

    // ── Episode derivation ───────────────────────────────────────────────

    public function test_joining_opens_an_episode_that_stays_open(): void
    {
        $e = $this->episodes([$this->evt('JOINED', '2020-06-01')]);

        $this->assertCount(1, $e);
        $this->assertSame('JOINED', $e[0]['startEvent']);
        $this->assertSame('2020-06-01', $e[0]['start']);
        $this->assertNull($e[0]['end'], 'a serving employee has no end date');
    }

    public function test_separation_closes_the_episode(): void
    {
        $e = $this->episodes([
            $this->evt('JOINED', '2020-06-01'),
            $this->evt('SEPARATED', '2023-05-31'),
        ]);

        $this->assertCount(1, $e);
        $this->assertSame('2023-05-31', $e[0]['end']);
    }

    public function test_a_rehire_opens_a_second_episode(): void
    {
        $e = $this->episodes([
            $this->evt('JOINED', '2018-04-01'),
            $this->evt('SEPARATED', '2020-03-31'),
            $this->evt('REHIRED', '2022-07-01'),
        ]);

        $this->assertCount(2, $e);
        $this->assertSame('REHIRED', $e[1]['startEvent']);
        $this->assertNull($e[1]['end']);
    }

    /** Events that are neither a start nor an end continue the open episode. */
    public function test_other_events_do_not_split_an_episode(): void
    {
        $e = $this->episodes([
            $this->evt('JOINED', '2020-06-01'),
            $this->evt('REACTIVATED', '2021-01-01'),
            $this->evt('DEPARTMENT_CHANGED', '2021-06-01'),
            $this->evt('POSITION_CHANGED', '2022-01-01'),
        ]);

        $this->assertCount(1, $e, 'a transfer or promotion is not a break in service');
    }

    /** A dangling episode is closed defensively, never dropped. */
    public function test_a_second_join_without_a_separation_does_not_lose_the_first(): void
    {
        $e = $this->episodes([
            $this->evt('JOINED', '2018-04-01'),
            $this->evt('JOINED', '2021-04-01'),   // corrupt: no SEPARATED between
        ]);

        $this->assertCount(2, $e, 'losing an episode would understate service');
    }

    public function test_a_separation_with_no_open_episode_is_ignored(): void
    {
        $this->assertSame([], $this->episodes([$this->evt('SEPARATED', '2020-01-01')]));
    }

    public function test_no_events_yields_no_episodes(): void
    {
        $this->assertSame([], $this->episodes([]));
    }

    // ── Service duration ─────────────────────────────────────────────────

    /** Both endpoints count — joining and leaving the same day is one day. */
    public function test_service_is_inclusive_of_both_endpoints(): void
    {
        $s = $this->service([['startEvent' => 'JOINED', 'start' => '2026-03-01', 'end' => '2026-03-01']], '2026-08-01');

        $this->assertSame(1, $s['totalDays'], 'a single day of service is one day, not zero');
    }

    public function test_a_closed_episode_counts_its_own_span(): void
    {
        $s = $this->service([['startEvent' => 'JOINED', 'start' => '2026-01-01', 'end' => '2026-01-31']], '2026-08-01');

        $this->assertSame(31, $s['totalDays']);
    }

    /** An open episode is closed at the as-of date — normally the last working day. */
    public function test_an_open_episode_is_measured_to_the_as_of_date(): void
    {
        $s = $this->service([['startEvent' => 'JOINED', 'start' => '2026-01-01', 'end' => null]], '2026-01-31');

        $this->assertSame(31, $s['totalDays']);
    }

    public function test_an_unbroken_record_reports_both_readings_identically(): void
    {
        $s = $this->service([['startEvent' => 'JOINED', 'start' => '2020-06-01', 'end' => null]], '2026-05-31');

        $this->assertSame($s['totalDays'], $s['continuousDays'],
            'with no break there is nothing for an owner to decide');
        $this->assertFalse($s['hasBreak']);
        $this->assertSame(1, $s['episodeCount']);
    }

    // ── The rehire case — where the policy question lives ────────────────

    /**
     * THE ONE THAT MATTERS FOR D-002 Q1. After a rehire the two readings
     * diverge, and the resolver surfaces both rather than choosing.
     */
    public function test_a_rehire_makes_the_two_readings_diverge(): void
    {
        $s = $this->service([
            ['startEvent' => 'JOINED',  'start' => '2018-01-01', 'end' => '2018-12-31'],  // 365
            ['startEvent' => 'REHIRED', 'start' => '2024-01-01', 'end' => null],
        ], '2024-12-31');                                                                  // 366 (leap)

        $this->assertSame(731, $s['totalDays'], 'both stints added together');
        $this->assertSame(366, $s['continuousDays'], 'the current stint alone');
        $this->assertTrue($s['hasBreak'], 'the divergence must be visible, not resolved silently');
        $this->assertSame(2, $s['episodeCount']);
    }

    public function test_the_gap_between_stints_is_never_counted(): void
    {
        $s = $this->service([
            ['startEvent' => 'JOINED',  'start' => '2020-01-01', 'end' => '2020-01-10'],  // 10
            ['startEvent' => 'REHIRED', 'start' => '2025-01-01', 'end' => '2025-01-10'],  // 10
        ], '2026-01-01');

        $this->assertSame(20, $s['totalDays'], 'the five-year gap is not service');
    }

    // ── Degenerate and hostile input ─────────────────────────────────────

    public function test_a_reversed_window_is_ignored_rather_than_counted_negative(): void
    {
        $s = $this->service([['startEvent' => 'JOINED', 'start' => '2026-06-01', 'end' => '2026-01-01']], '2026-08-01');

        $this->assertSame(0, $s['totalDays'], 'corrupt data must never produce negative service');
    }

    public function test_service_is_never_counted_beyond_the_as_of_date(): void
    {
        $s = $this->service([['startEvent' => 'JOINED', 'start' => '2026-01-01', 'end' => '2026-12-31']], '2026-01-31');

        $this->assertSame(31, $s['totalDays'], 'an as-of date caps the measurement');
    }

    public function test_a_future_dated_stint_has_not_started(): void
    {
        $s = $this->service([['startEvent' => 'JOINED', 'start' => '2027-01-01', 'end' => null]], '2026-08-01');

        $this->assertSame(0, $s['totalDays']);
        $this->assertSame(0, $s['episodeCount']);
    }

    public function test_a_malformed_as_of_date_yields_zero_not_an_error(): void
    {
        foreach (['', '2026-8-1', 'today', '01-08-2026'] as $bad) {
            $s = $this->service([['startEvent' => 'JOINED', 'start' => '2020-01-01', 'end' => null]], $bad);
            $this->assertSame(0, $s['totalDays'], "'{$bad}' must be rejected, not coerced");
        }
    }

    public function test_a_malformed_start_is_skipped(): void
    {
        $s = $this->service([
            ['startEvent' => 'JOINED',  'start' => 'not-a-date', 'end' => null],
            ['startEvent' => 'REHIRED', 'start' => '2026-01-01', 'end' => '2026-01-10'],
        ], '2026-08-01');

        $this->assertSame(10, $s['totalDays'], 'the usable episode still counts');
        $this->assertSame(1, $s['episodeCount']);
    }

    public function test_no_episodes_yields_zero_service(): void
    {
        $s = $this->service([], '2026-08-01');

        $this->assertSame(0, $s['totalDays']);
        $this->assertSame(0, $s['continuousDays']);
        $this->assertFalse($s['hasBreak']);
    }

    // ── End to end ───────────────────────────────────────────────────────

    /** Events in, service out — the whole path F&F will use. */
    public function test_events_resolve_to_service_end_to_end(): void
    {
        $s = $this->service($this->episodes([
            $this->evt('JOINED', '2019-04-01'),
            $this->evt('DEPARTMENT_CHANGED', '2020-06-01'),
            $this->evt('SEPARATED', '2021-03-31'),
            $this->evt('REHIRED', '2023-04-01'),
            $this->evt('POSITION_CHANGED', '2024-01-01'),
        ]), '2026-03-31');

        $this->assertSame(2, $s['episodeCount']);
        $this->assertTrue($s['hasBreak']);
        $this->assertGreaterThan($s['continuousDays'], $s['totalDays']);
    }

    /** The resolver must state no opinion about gratuity. */
    public function test_the_resolver_contains_no_gratuity_rule(): void
    {
        $src = file_get_contents(
            __DIR__ . '/../../application/libraries/Staff_employment_history_service.php'
        );
        $this->assertNotFalse($src);

        $i = strpos($src, 'function serviceDaysFromEpisodes');
        $this->assertNotFalse($i);
        $body = substr($src, (int) $i, 2600);

        foreach (['gratuity', '/ 26', '* 15', 'eligib'] as $needle) {
            $this->assertStringNotContainsStringIgnoringCase($needle, $body,
                'D-002 policy must not leak into an input resolver');
        }
    }
}
