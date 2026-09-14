<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../application/libraries/Timetable_projection_service.php';

/**
 * WHAT HAPPENS WHEN THE SESSION ROLLS OVER AND A TIMETABLE IS PUBLISHED.
 *
 * Two things write the collection the apps read: the projection, which
 * materialises the published canonical version, and the session-rollover tool,
 * which carries a school's timetable into the new session. They are not
 * coordinated, and the order they run in changes the outcome.
 *
 * This file is the reconciliation matrix, proven by running the real projection
 * planner for each combination rather than reasoned about on paper — plus a
 * structural pin on the one ordering that is destructive.
 *
 * It decides nothing about policy. Whether a school carries last year's
 * timetable forward or starts blank is already the school's choice through the
 * rollover tool's own strategy flag, and this file asserts the consequences of
 * both without preferring either.
 */
final class TimetableRolloverReconciliationTest extends TestCase
{
    private const SCHOOL = 'SCH_TT01';
    private const NEW    = '2026-27';
    private const OLD    = '2025-26';

    private function docId(string $sectionKey, string $day = 'Monday'): string
    {
        return self::SCHOOL . '_' . self::NEW . '_'
             . str_replace('/', '_', $sectionKey) . '_' . $day;
    }

    /** A document the rollover carried forward (strategy: copy). */
    private function carried(string $sectionKey): array
    {
        return [$this->docId($sectionKey) => [
            'schoolId' => self::SCHOOL, 'session' => self::NEW,
            'sectionKey' => $sectionKey, 'day' => 'Monday',
            'className' => explode('/', $sectionKey)[0],
            'section'   => explode('/', $sectionKey)[1],
            'periods' => [
                ['periodNumber' => 1, 'subject' => "Last year's subject",
                 'teacherId' => 'STA_OLD', 'teacher' => 'A Former Teacher',
                 'startTime' => '09:00', 'endTime' => '09:40', 'type' => 'class'],
            ],
            'version' => 1, 'manuallyEdited' => false,
            'carriedFromSession' => self::OLD,
            'phase3RolloverRev'  => 1,
        ]];
    }

    /** A document the rollover carried with the "blank" strategy. */
    private function carriedBlank(string $sectionKey): array
    {
        $d = $this->carried($sectionKey);
        $d[$this->docId($sectionKey)]['periods'] = [];
        return $d;
    }

    /** What the projection produces for a published version. */
    private function projectionFor(array $sectionKeys, string $versionId = 'V1'): array
    {
        $days = [];
        foreach ($sectionKeys as $sk) {
            $days[] = ['classSection' => $sk, 'day' => 'Monday', 'periods' => [
                ['periodNumber' => 1, 'subject' => 'Mathematics',
                 'teacherId' => 'STA0001', 'teacher' => 'A Teacher',
                 'startTime' => '09:00', 'endTime' => '09:40', 'type' => 'class'],
            ]];
        }
        return Timetable_projection_service::project($days, [
            'schoolId' => self::SCHOOL, 'session' => self::NEW,
            'planId' => 'PLAN1', 'versionId' => $versionId,
            'at' => '2026-09-01T00:00:00+00:00', 'byUid' => 'STA_HEAD',
        ]);
    }

    // ══ THE MATRIX ═════════════════════════════════════════════════════
    //
    //  carried?  published version covers it?   outcome
    //  ────────  ───────────────────────────    ───────────────────────────
    //  no        yes                            created — clean
    //  copy      yes                            overwritten — reconciled
    //  blank     yes                            overwritten — reconciled
    //  copy      no                             ORPHAN — still visible
    //  blank     no                             ORPHAN — an empty day, visible
    //  projected same version again             unchanged — safe retry
    //  projected newer version covers it        rewritten — replaced
    //  projected newer version drops it         ORPHAN — still visible

    public function test_nothing_carried_and_a_version_covers_it_creates_cleanly(): void
    {
        $projected = $this->projectionFor(['Class 9th/Section A']);

        $plan = Timetable_projection_service::plan($projected, []);

        $this->assertCount(1, $plan['writes']);
        $this->assertSame([], $plan['orphans']);
        $this->assertSame(1, $plan['writes'][$this->docId('Class 9th/Section A')]['version'],
            'a fresh session starts the legacy counter at 1');
    }

    public function test_a_carried_copy_the_version_covers_is_reconciled(): void
    {
        $projected = $this->projectionFor(['Class 9th/Section A']);
        $existing  = $this->carried('Class 9th/Section A');

        $plan = Timetable_projection_service::plan($projected, $existing);

        $id = $this->docId('Class 9th/Section A');
        $this->assertArrayHasKey($id, $plan['writes']);
        $this->assertSame('Mathematics', $plan['writes'][$id]['periods'][0]['subject'],
            "last year's lesson is replaced by the published one");
        $this->assertSame([], $plan['orphans']);
    }

    public function test_a_blank_carried_day_the_version_covers_is_reconciled(): void
    {
        $projected = $this->projectionFor(['Class 9th/Section A']);
        $existing  = $this->carriedBlank('Class 9th/Section A');

        $plan = Timetable_projection_service::plan($projected, $existing);

        $id = $this->docId('Class 9th/Section A');
        $this->assertArrayHasKey($id, $plan['writes']);
        $this->assertCount(1, $plan['writes'][$id]['periods']);
    }

    /**
     * The orphan case, and the reason this matrix exists. A section the school
     * carried but this year's published version does not define is left exactly
     * where it is — so teachers and parents keep seeing it.
     */
    public function test_a_carried_section_the_version_drops_stays_visible(): void
    {
        $projected = $this->projectionFor(['Class 9th/Section A']);
        $existing  = $projected + $this->carried('Class 8th/Section C');

        $plan = Timetable_projection_service::plan($projected, $existing);

        $orphan = $this->docId('Class 8th/Section C');
        $this->assertSame([$orphan], $plan['orphans']);
        $this->assertArrayNotHasKey($orphan, $plan['writes'],
            'reported for a person to decide about, never silently deleted');
    }

    public function test_a_blank_carried_section_the_version_drops_is_also_an_orphan(): void
    {
        $projected = $this->projectionFor(['Class 9th/Section A']);
        $existing  = $projected + $this->carriedBlank('Class 8th/Section C');

        $plan = Timetable_projection_service::plan($projected, $existing);

        $this->assertSame([$this->docId('Class 8th/Section C')], $plan['orphans'],
            'an empty day left behind is still a document the apps read');
    }

    public function test_projecting_the_same_version_again_is_a_safe_retry(): void
    {
        $projected = $this->projectionFor(['Class 9th/Section A']);

        $plan = Timetable_projection_service::plan($projected, $projected);

        $this->assertSame([], $plan['writes'], 'a retry after a partial run is safe');
        $this->assertCount(1, $plan['unchanged']);
        $this->assertSame([], $plan['orphans']);
    }

    public function test_a_newer_version_replaces_what_the_older_one_projected(): void
    {
        $older = $this->projectionFor(['Class 9th/Section A'], 'V1');
        $older[$this->docId('Class 9th/Section A')]['version'] = 3;

        $newer = $this->projectionFor(['Class 9th/Section A'], 'V2');
        $newer[$this->docId('Class 9th/Section A')]['periods'][0]['subject'] = 'Science';

        $plan = Timetable_projection_service::plan($newer, $older);

        $id = $this->docId('Class 9th/Section A');
        $this->assertSame('Science', $plan['writes'][$id]['periods'][0]['subject']);
        $this->assertSame(4, $plan['writes'][$id]['version']);
        $this->assertSame('V2', $plan['writes'][$id]['projectedFromVersion'],
            'provenance follows the version that is now in force');
    }

    public function test_a_section_the_newer_version_drops_becomes_an_orphan(): void
    {
        $older = $this->projectionFor(['Class 9th/Section A', 'Class 9th/Section B']);
        $newer = $this->projectionFor(['Class 9th/Section A'], 'V2');

        $plan = Timetable_projection_service::plan($newer, $older);

        $this->assertSame([$this->docId('Class 9th/Section B')], $plan['orphans']);
    }

    // ══ THE ORDERING, NOW GUARDED ══════════════════════════════════════

    /**
     * ROLLOVER RUN AFTER A PROJECTION NO LONGER REPLACES THE PUBLISHED
     * TIMETABLE.
     *
     * This pair used to pin the hazard: the rollover tool skipped a
     * destination only on its own revision marker, a projected document does
     * not carry one, and the write was a full replace — so running the
     * rollover after a projection put last year's timetable back on every
     * teacher and parent screen.
     *
     * The decision now lives in Rollover_projection_guard, where it can be
     * executed. Its behaviour is proven case by case in
     * RolloverProjectionGuardTest; what these two assert is that the timetable
     * carry actually routes through it and keeps no second copy of the old
     * rule beside it.
     *
     * A note on how this file got here, because it is the interesting part:
     * the original hazard tests were written to FAIL when the fix landed, and
     * they did not. They asserted the absence of a string in the SCRIPT, and
     * the fix moved that string into a library — so they kept passing while
     * testing nothing. A test that pins a hazard has to pin the behaviour, not
     * the place the behaviour happens to live.
     */
    public function test_the_timetable_carry_routes_its_decision_through_the_guard(): void
    {
        $src  = (string) file_get_contents(
            __DIR__ . '/../../scripts/rollover_academic_session.php');
        $code = (string) preg_replace('~^\s*//.*$~m', '', $src);

        $this->assertStringContainsString(
            "require_once APPPATH . 'libraries/Rollover_projection_guard.php';", $code);
        $this->assertMatchesRegularExpression(
            '~Rollover_projection_guard::decide\(~', $code,
            'the carry asks the guard');

        // And does not keep deciding for itself beside it: the timetable block
        // must no longer contain the bare revision comparison it used to use.
        $block = substr($code, (int) strpos($code, "\$dstId = \"{\$SCHOOL}_{\$TO}_"), 1400);
        $this->assertStringNotContainsString('phase3RolloverRev\'] ?? 0) >=', $block,
            'a second copy of the rule beside the guard is a second rule');
    }

    /** And the guard is told when the destination could not be read. */
    public function test_an_unreadable_destination_is_reported_to_the_guard(): void
    {
        $src = (string) file_get_contents(
            __DIR__ . '/../../scripts/rollover_academic_session.php');

        $this->assertMatchesRegularExpression(
            '~catch \(\\\\Throwable \$e\) \{ \$existing = null; \$readFailed = true; \}~',
            $src,
            'a swallowed read used to mean "not yet rolled, therefore write"');

        // The behaviour that follows from it, executed.
        require_once __DIR__ . '/../../application/libraries/Rollover_projection_guard.php';
        $this->assertSame(
            Rollover_projection_guard::SKIP_UNREADABLE,
            Rollover_projection_guard::decide(null, 1, true));
        $this->assertSame(
            Rollover_projection_guard::SKIP_PROJECTED,
            Rollover_projection_guard::decide(['projectedFromVersion' => 'V1'], 1));
    }
}
