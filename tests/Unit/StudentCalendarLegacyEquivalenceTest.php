<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once APPPATH . 'libraries/Student_calendar_service.php';
require_once APPPATH . 'libraries/Student_calendar_reader.php';
require_once APPPATH . 'helpers/attendance_helper.php';

/**
 * A minimal Firestore_service stand-in. Only `get()` is exercised.
 */
final class FakeCalendarFs
{
    public array $docs = [];
    public bool  $throw = false;
    public int   $reads = 0;

    public function get(string $collection, string $id)
    {
        $this->reads++;
        if ($this->throw) throw new RuntimeException('simulated Firestore failure');
        return $this->docs["{$collection}/{$id}"] ?? [];
    }
}

/**
 * THE C-PRIME CONTRACT: DEPLOYING THIS CHANGES NOBODY'S BEHAVIOUR.
 *
 * The migration is only safe if a tenant that has configured nothing resolves
 * EXACTLY as the legacy pipeline did. Not approximately, not on the cases
 * somebody thought to check — identically, day for day.
 *
 * The legacy pipeline is:
 *
 *     nw_days_from_holidays($holidayMap, $month, $year)   // Sundays + holidays
 *     then: unset() every special working day
 *
 * This file runs both pipelines over every day of 36 consecutive months and
 * asserts the maps are identical, including the LABELS, because those labels
 * are rendered in the register UI.
 */
final class StudentCalendarLegacyEquivalenceTest extends TestCase
{
    private function reader(array $doc = [], bool $throw = false): array
    {
        $fs = new FakeCalendarFs();
        $fs->docs['attendanceSettings/SCH_A'] = $doc;
        $fs->throw = $throw;
        $r = (new Student_calendar_reader())->init($fs, 'SCH_A', '2026-2027');
        return [$r, $fs];
    }

    /** The legacy pipeline, reproduced exactly as production runs it. */
    private function legacy(array $holidayMap, array $swdMap, int $m, int $y): array
    {
        $nw = nw_days_from_holidays($holidayMap, $m, $y);
        foreach ($swdMap as $day => $_) unset($nw[(int) $day]);
        return $nw;
    }

    // ── THE HEADLINE PROOF ───────────────────────────────────────────────

    public function test_unconfigured_tenant_is_byte_identical_to_the_legacy_pipeline(): void
    {
        [$reader] = $this->reader([]);              // nothing configured at all

        $mismatches = [];
        $y = 2025; $m = 1;

        for ($i = 0; $i < 36; $i++) {
            // A deterministic but non-trivial exception set that varies per month:
            // a mid-month holiday, a month-end holiday, and a special working day
            // that sometimes lands on a Sunday and sometimes does not.
            $holidays = [12 => 'Founders Day', 26 => 'Republic Day'];
            $swd      = [(($i % 4) + 1) * 7 => 'Makeup class'];

            $expected = $this->legacy($holidays, $swd, $m, $y);
            $actual   = $reader->resolveMonth($y, $m, $holidays, $swd)['nonWorking'];

            ksort($expected); ksort($actual);
            if ($expected !== $actual) {
                $mismatches[] = sprintf('%04d-%02d expected=%s actual=%s', $y, $m,
                    json_encode($expected), json_encode($actual));
            }

            if (++$m > 12) { $m = 1; $y++; }
        }

        $this->assertSame([], $mismatches,
            "An unconfigured tenant does NOT reproduce legacy behaviour. C-prime "
            . "requires that deploying this changes nothing until a school opts in:\n"
            . implode("\n", array_slice($mismatches, 0, 6)));
    }

    public function test_labels_match_too_because_the_register_renders_them(): void
    {
        [$reader] = $this->reader([]);
        $holidays = [15 => 'Diwali'];
        $actual   = $reader->resolveMonth(2026, 6, $holidays, [])['nonWorking'];

        $this->assertSame('Diwali', $actual[15] ?? null, 'holiday keeps its own name');
        $this->assertSame('Sunday', $actual[7] ?? null,
            "a weekly closure must still read 'Sunday' — existing UI copy shows this label");
    }

    public function test_special_working_day_still_beats_a_sunday_when_unconfigured(): void
    {
        [$reader] = $this->reader([]);
        // 2026-06-07 is a Sunday.
        $r = $reader->resolveMonth(2026, 6, [], [7 => 'Makeup'])['nonWorking'];
        $this->assertArrayNotHasKey(7, $r, 'a declared makeup Sunday must be markable');
    }

    public function test_source_reports_legacy_rather_than_pretending_to_be_configured(): void
    {
        [$reader] = $this->reader([]);
        $out = $reader->resolveMonth(2026, 6, [], []);
        $this->assertSame(Student_calendar_reader::SOURCE_LEGACY, $out['source']);
        $this->assertFalse($out['configured']);
    }

    // ── ONCE CONFIGURED, THE SCHOOL'S POLICY ACTUALLY APPLIES ────────────

    public function test_a_configured_five_day_week_closes_saturdays(): void
    {
        [$reader] = $this->reader(['studentCalendar' => ['versions' => [
            ['effectiveFrom' => '2026-04-01', 'weeklyOffs' => ['Sat', 'Sun']],
        ]]]);

        $nw = $reader->resolveMonth(2026, 6, [], [])['nonWorking'];
        $this->assertArrayHasKey(13, $nw, '2026-06-13 is a Saturday and must now close');
        $this->assertArrayHasKey(7,  $nw, 'Sunday still closes');
        $this->assertArrayNotHasKey(10, $nw, 'Wednesday still teaches');
    }

    public function test_second_and_fourth_saturday_configuration_applies(): void
    {
        [$reader] = $this->reader(['studentCalendar' => ['versions' => [
            ['effectiveFrom' => '2026-04-01', 'weeklyOffs' => ['Sun'],
             'nthWeekdayOffs' => [['day' => 'Sat', 'weeks' => [2, 4]]]],
        ]]]);

        $nw = $reader->resolveMonth(2026, 6, [], [])['nonWorking'];
        $this->assertArrayNotHasKey(6,  $nw, '1st Saturday works');
        $this->assertArrayHasKey(13, $nw, '2nd Saturday off');
        $this->assertArrayNotHasKey(20, $nw, '3rd Saturday works');
        $this->assertArrayHasKey(27, $nw, '4th Saturday off');
    }

    // ── PER-DAY EFFECTIVE DATING — THE HISTORICAL GUARANTEE ──────────────

    /**
     * A month that SPANS a policy change must resolve each half against the
     * policy that was live then. Resolving the whole month against one version
     * is precisely the retroactive-rewrite defect.
     */
    public function test_a_month_spanning_a_policy_change_resolves_per_day(): void
    {
        [$reader] = $this->reader(['studentCalendar' => ['versions' => [
            ['effectiveFrom' => '2026-01-01', 'weeklyOffs' => ['Sun']],
            ['effectiveFrom' => '2026-06-15', 'weeklyOffs' => ['Sat', 'Sun']],
        ]]]);

        $nw = $reader->resolveMonth(2026, 6, [], [])['nonWorking'];

        // Saturdays in June 2026: 6, 13 (before the change), 20, 27 (after).
        $this->assertArrayNotHasKey(6,  $nw, 'Sat 6 June predates the 5-day week');
        $this->assertArrayNotHasKey(13, $nw, 'Sat 13 June still predates it');
        $this->assertArrayHasKey(20, $nw, 'Sat 20 June is after the change');
        $this->assertArrayHasKey(27, $nw, 'Sat 27 June is after the change');
    }

    public function test_a_future_policy_change_does_not_touch_a_past_month(): void
    {
        $before = ['studentCalendar' => ['versions' => [
            ['effectiveFrom' => '2026-01-01', 'weeklyOffs' => ['Sun']],
        ]]];
        $after = ['studentCalendar' => ['versions' => [
            ['effectiveFrom' => '2026-01-01', 'weeklyOffs' => ['Sun']],
            ['effectiveFrom' => '2027-01-01', 'weeklyOffs' => ['Sat', 'Sun']],
        ]]];

        [$r1] = $this->reader($before);
        [$r2] = $this->reader($after);

        $changed = [];
        for ($m = 1; $m <= 12; $m++) {
            $a = $r1->resolveMonth(2026, $m, [], [])['nonWorking'];
            $b = $r2->resolveMonth(2026, $m, [], [])['nonWorking'];
            ksort($a); ksort($b);
            if ($a !== $b) $changed[] = "2026-{$m}";
        }

        $this->assertSame([], $changed,
            'adding a 2027 policy reinterpreted these 2026 months: ' . implode(', ', $changed));
    }

    // ── FAILURE BEHAVIOUR ────────────────────────────────────────────────

    public function test_a_settings_read_failure_is_reported_not_hidden(): void
    {
        [$reader] = $this->reader([], true);

        $this->assertFalse($reader->load_ok(),
            'a caller that needs to fail closed must be able to tell');

        $out = $reader->resolveMonth(2026, 6, [], []);
        $this->assertSame(Student_calendar_reader::SOURCE_UNREADABLE, $out['source']);
        $this->assertSame($this->legacy([], [], 6, 2026), $out['nonWorking'],
            'but the school day keeps running on the legacy rule, exactly as before');
    }

    public function test_malformed_stored_versions_fall_back_safely(): void
    {
        foreach ([
            ['studentCalendar' => ['versions' => 'not-an-array']],
            ['studentCalendar' => ['versions' => [['effectiveFrom' => '', 'weeklyOffs' => []]]]],
            ['studentCalendar' => 'garbage'],
        ] as $i => $doc) {
            [$reader] = $this->reader($doc);
            $out = $reader->resolveMonth(2026, 6, [], []);
            $this->assertSame($this->legacy([], [], 6, 2026), $out['nonWorking'],
                "malformed shape #{$i} must degrade to legacy, never to an invented calendar");
        }
    }

    public function test_the_settings_document_is_read_once_per_month_resolution(): void
    {
        [$reader, $fs] = $this->reader([]);
        $reader->resolveMonth(2026, 6, [], []);
        $reader->resolveMonth(2026, 7, [], []);
        $this->assertSame(1, $fs->reads,
            'per-day resolution must not mean per-day Firestore reads');
    }

    // ── CAL-D10 DIVERGENCE — EVIDENCE, NEVER AUTOMATIC TRUTH ─────────────

    public function test_a_stale_five_day_working_days_setting_is_surfaced_not_applied(): void
    {
        [$reader] = $this->reader(['working_days' => ['Mon','Tue','Wed','Thu','Fri']]);

        $d = $reader->divergence();
        $this->assertNotNull($d, 'the discrepancy must be reported to the school');
        $this->assertSame(['Sat','Sun'], $d['savedClosedDays']);
        $this->assertSame(['Sun'], $d['activeClosedDays']);
        $this->assertTrue($d['requiresDecision']);

        // AND CRUCIALLY: nothing changed.
        $nw = $reader->resolveMonth(2026, 6, [], [])['nonWorking'];
        $this->assertArrayNotHasKey(13, $nw,
            'a saved-but-never-enforced setting must NOT retroactively close Saturdays. '
            . 'It is evidence for the administrator, not truth.');
    }

    public function test_no_divergence_is_reported_when_the_setting_agrees(): void
    {
        [$reader] = $this->reader(['working_days' => ['Mon','Tue','Wed','Thu','Fri','Sat']]);
        $this->assertNull($reader->divergence(),
            'a six-day setting agrees with the legacy Sunday-only rule');
    }

    public function test_no_divergence_is_reported_when_nothing_was_ever_saved(): void
    {
        [$reader] = $this->reader([]);
        $this->assertNull($reader->divergence());
    }

    public function test_divergence_clears_once_the_school_configures_to_match(): void
    {
        [$reader] = $this->reader([
            'working_days' => ['Mon','Tue','Wed','Thu','Fri'],
            'studentCalendar' => ['versions' => [
                ['effectiveFrom' => '2026-04-01', 'weeklyOffs' => ['Sat','Sun']],
            ]],
        ]);
        $this->assertNull($reader->divergence(),
            'once the school actually applies the pattern there is nothing to report');
    }

    public function test_long_day_names_are_accepted_in_the_stale_setting(): void
    {
        [$reader] = $this->reader([
            'working_days' => ['Monday','Tuesday','Wednesday','Thursday','Friday'],
        ]);
        $d = $reader->divergence();
        $this->assertNotNull($d, 'both "Mon" and "Monday" shapes exist in the wild');
        $this->assertSame(['Sat','Sun'], $d['savedClosedDays']);
    }

    // ── TENANT ISOLATION ─────────────────────────────────────────────────

    public function test_one_school_cannot_read_another_schools_calendar(): void
    {
        $fs = new FakeCalendarFs();
        $fs->docs['attendanceSettings/SCH_A'] = ['studentCalendar' => ['versions' => [
            ['effectiveFrom' => '2026-04-01', 'weeklyOffs' => ['Sat','Sun']],
        ]]];
        $fs->docs['attendanceSettings/SCH_B'] = [];

        $b = (new Student_calendar_reader())->init($fs, 'SCH_B', '2026-2027');

        $this->assertFalse($b->isConfigured(),
            "School B must not inherit School A's calendar");
        $nw = $b->resolveMonth(2026, 6, [], [])['nonWorking'];
        $this->assertArrayNotHasKey(13, $nw, 'B still runs a six-day week');
    }
}
