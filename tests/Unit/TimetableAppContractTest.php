<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../application/libraries/Timetable_projection_service.php';

/**
 * The shape the Teacher and Parent apps read.
 *
 * ── WHY THIS IS PINNED HERE ─────────────────────────────────────────────
 *
 * The apps live in separate repositories (SchoolSyncTeacher, SchoolSyncParent)
 * and are released on their own schedule. They read the legacy `timetables`
 * collection directly, so this repository cannot change that document shape
 * and see the breakage: a dropped field shows up as a blank row on a phone,
 * days later, to someone who cannot report it usefully.
 *
 * The risk is specific and easy to miss. The projection writes with merge, so
 * unknown TOP-LEVEL fields survive — but `periods` is a single field, so the
 * projection REPLACES the whole array. Any period attribute the canonical
 * version does not carry is therefore deleted from what the apps read, with no
 * error anywhere.
 *
 * Verified against the live tenant on 2026-09-12: the canonical version days
 * and the legacy documents carry byte-identical period shapes, so materialising
 * the published version preserves the contract exactly. These tests keep that
 * true.
 */
final class TimetableAppContractTest extends TestCase
{
    /**
     * Every attribute of a period the apps can read.
     *
     * `startTime` / `endTime` are the ones to watch: they belong to the SLOT,
     * not the lesson, and an editor that rebuilt a day without carrying them
     * over would erase every time from every phone in the school while leaving
     * the subjects correct — which looks like a cosmetic glitch and is not.
     */
    private const PERIOD_FIELDS = [
        'periodNumber', 'subject', 'teacher', 'teacherId',
        'startTime', 'endTime', 'room', 'type',
    ];

    /** Top-level document fields the apps query on or display. */
    private const DOCUMENT_FIELDS = [
        'schoolId', 'session', 'className', 'section',
        'classOrder', 'sectionCode', 'sectionKey', 'day', 'periods',
    ];

    private function service(): string
    {
        return (string) file_get_contents(
            __DIR__ . '/../../application/libraries/Timetable_projection_service.php');
    }

    /** The projected document carries every field the apps query and display. */
    public function test_the_projection_writes_every_document_field_the_apps_read(): void
    {
        $src = $this->service();

        foreach (self::DOCUMENT_FIELDS as $f) {
            $this->assertMatchesRegularExpression("~'{$f}'\s*=>~", $src,
                "the projection no longer writes '{$f}', which the Teacher and "
                . 'Parent apps read. They are separate repositories on their own '
                . 'release schedule, so this breaks them silently');
        }
    }

    /**
     * The period array is passed through WHOLE, never rebuilt field by field.
     *
     * If this ever became a field-by-field copy, every attribute not listed in
     * that copy would be dropped from the apps' view on the next projection.
     */
    public function test_the_period_array_is_passed_through_not_rebuilt(): void
    {
        $src = $this->service();

        $this->assertMatchesRegularExpression(
            "~'periods'\s*=>\s*is_array\(\\\$d\['periods'\] \?\? null\) \? \\\$d\['periods'\] : \[\]~",
            $src,
            'the projection now rebuilds the periods array instead of copying it. '
            . 'Every period attribute not named in that rebuild is deleted from '
            . 'what the apps read, silently, on the next run');
    }

    /**
     * And the canonical side supplies the whole shape, so the pass-through has
     * something complete to pass. The day editor is where a period is rebuilt.
     */
    public function test_the_canonical_day_editor_writes_the_whole_period_shape(): void
    {
        $store = (string) file_get_contents(
            __DIR__ . '/../../application/libraries/Timetable_plan_store.php');

        foreach (self::PERIOD_FIELDS as $f) {
            $this->assertMatchesRegularExpression("~'{$f}'\s*=>~", $store,
                "the canonical day editor no longer writes '{$f}'. The projection "
                . 'copies periods verbatim, so whatever it omits disappears from '
                . 'the apps');
        }

        // Times specifically must be CARRIED OVER on an edit, not blanked:
        // they belong to the slot, and an edited lesson keeps its slot.
        $this->assertMatchesRegularExpression(
            "~'startTime'\s*=>\s*\(string\) \(\\\$existing\[\\\$p\]\['startTime'\]~", $store,
            'editing a cell no longer preserves the slot times, so a single edit '
            . 'would blank the times for that period on every phone');
    }

    /**
     * An empty period must stay an empty SLOT, never vanish.
     *
     * The apps render a week grid by position. A free period that disappeared
     * from the array rather than staying as a hole would shift every later
     * lesson one period earlier — a timetable that is wrong in the most
     * confusing possible way, because every entry in it is individually real.
     */
    public function test_a_free_period_is_a_hole_not_a_removal(): void
    {
        $store = (string) file_get_contents(
            __DIR__ . '/../../application/libraries/Timetable_plan_store.php');

        $this->assertMatchesRegularExpression(
            "~'periodNumber' => \\\$p \+ 1, 'subject' => '', 'teacher' => ''~", $store,
            'a free period is no longer written as an empty slot, so the array '
            . 'would compact and shift every later lesson earlier');
    }

    /**
     * Nothing this repository writes may REMOVE a top-level field the apps
     * might read, because the projection merges rather than replaces.
     */
    public function test_the_projection_merges_so_unknown_app_fields_survive(): void
    {
        $store = (string) file_get_contents(
            __DIR__ . '/../../application/libraries/Timetable_plan_store.php');

        $this->assertMatchesRegularExpression(
            '~firestoreSet\(self::COLLECTION_LEGACY_TT, \$docId, \$data, true\)~', $store,
            'the projection writes without merge, so every field it does not '
            . 'author — including any the apps rely on and this repository does '
            . 'not know about — is deleted on the next run');
    }

    /**
     * End to end on the real shape: project a version day and confirm the
     * document that reaches the apps is complete.
     */
    public function test_a_projected_document_matches_the_live_app_shape(): void
    {
        if (!class_exists('Entity_firestore_sync')) {
            $this->markTestSkipped('Entity_firestore_sync is not loadable in isolation');
        }

        $day = [
            'classSection' => 'Class 8th/Section A',
            'day'          => 'Monday',
            'periods'      => [[
                'periodNumber' => 1, 'subject' => 'Mathematics',
                'teacher' => 'Sunita Raghav', 'teacherId' => 'STA0112',
                'startTime' => '8:00AM', 'endTime' => '8:45AM',
                'room' => '', 'type' => 'class',
            ]],
        ];

        $out = Timetable_projection_service::project([$day], [
            'schoolId' => 'SCH_X', 'session' => '2026-27', 'planId' => 'REGULAR',
            'versionId' => 'V1', 'at' => '2026-09-13T00:20:00+00:00', 'byUid' => 'cron',
        ]);

        $this->assertCount(1, $out);
        $doc = reset($out);

        foreach (self::DOCUMENT_FIELDS as $f) {
            $this->assertArrayHasKey($f, $doc, "the projected document has no '{$f}'");
        }
        foreach (self::PERIOD_FIELDS as $f) {
            $this->assertArrayHasKey($f, $doc['periods'][0],
                "the projected period has no '{$f}', so the apps lose it");
        }
    }

    /**
     * THE QUERY PREDICATE. Both apps fetch a week with exactly two server-side
     * filters — `schoolId` and `sectionKey` — and `sectionKey` is a SLASH form:
     * "Class 8th/Section A". (The Teacher app's separate lesson-plan lookup
     * builds a document ID with UNDERSCORES instead, so the two forms must not
     * be conflated.)
     *
     * Get this wrong and both apps show an empty timetable with NO error: the
     * query simply matches nothing. There is no failure to notice.
     */
    public function test_the_section_key_keeps_the_slash_form_the_apps_query_on(): void
    {
        if (!class_exists('Entity_firestore_sync')) {
            $this->markTestSkipped('Entity_firestore_sync is not loadable in isolation');
        }

        $out = Timetable_projection_service::project([[
            'classSection' => 'Class 8th/Section A', 'day' => 'Monday', 'periods' => [],
        ]], ['schoolId' => 'SCH_X', 'session' => '2026-27', 'planId' => 'REGULAR',
             'versionId' => 'V1', 'at' => 'now', 'byUid' => 'cron']);

        $doc = reset($out);
        $this->assertSame('Class 8th/Section A', $doc['sectionKey'],
            'sectionKey is no longer the slash form both apps filter on, so every '
            . 'timetable query returns nothing — and an empty result is not an '
            . 'error in either app, so it renders as "no timetable yet"');

        // The document ID is the underscore form, and is a separate contract.
        $this->assertArrayHasKey('SCH_X_2026-27_Class 8th_Section A_Monday', $out,
            'the document id changed shape; the Teacher app reads a timetable cell '
            . 'by this exact id when validating a lesson-plan save, and a "/" in '
            . 'an id makes that read THROW rather than return not-found');
    }

    /**
     * `day` is a map key in the Parent app and is matched case-sensitively
     * there, so the capitalised English name is part of the contract. The
     * projection copies it through from the version day rather than
     * normalising, which is correct — but means the canonical side must never
     * store "monday".
     */
    public function test_the_day_name_is_passed_through_unchanged(): void
    {
        if (!class_exists('Entity_firestore_sync')) {
            $this->markTestSkipped('Entity_firestore_sync is not loadable in isolation');
        }

        $out = Timetable_projection_service::project([[
            'classSection' => 'Class 8th/Section A', 'day' => 'Monday', 'periods' => [],
        ]], ['schoolId' => 'SCH_X', 'session' => '2026-27', 'planId' => 'REGULAR',
             'versionId' => 'V1', 'at' => 'now', 'byUid' => 'cron']);

        $this->assertSame('Monday', reset($out)['day'],
            'the weekday name is being rewritten. The Parent app looks the day up '
            . 'as an exact map key, so any change of case makes that day blank');
    }

    /**
     * A break must be lowercase. The Parent app detects one with an exact
     * `type == "break" || type == "lunch"` comparison, so "Break" renders as a
     * lesson with no subject — which it then drops from the day entirely.
     *
     * The live tenant has no breaks configured yet (every period is `class`),
     * so this is latent rather than broken. It is pinned now because the day it
     * stops being latent, nothing would report it.
     */
    public function test_break_and_lunch_types_are_written_lowercase(): void
    {
        $store = (string) file_get_contents(
            __DIR__ . '/../../application/libraries/Timetable_plan_store.php');
        $svc   = (string) file_get_contents(
            __DIR__ . '/../../application/libraries/Timetable_service.php');

        foreach (["'Break'", "'Lunch'", "'BREAK'", "'LUNCH'"] as $bad) {
            $this->assertStringNotContainsString("'type' => {$bad}", $store,
                "a period type is written as {$bad}. The Parent app compares the "
                . 'type case-sensitively against "break"/"lunch", so this renders '
                . 'as a subject-less lesson and is then dropped from the day');
            $this->assertStringNotContainsString("'type' => {$bad}", $svc);
        }
    }
}
