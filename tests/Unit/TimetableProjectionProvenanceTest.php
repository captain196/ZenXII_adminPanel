<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

if (!defined('BASEPATH')) define('BASEPATH', __DIR__ . '/../../system/');
if (!defined('APPPATH'))  define('APPPATH',  __DIR__ . '/../../application/');
if (!function_exists('log_message')) { function log_message($l, $m) {} }
require_once __DIR__ . '/../../application/libraries/Timetable_projection_service.php';
require_once __DIR__ . '/../../application/libraries/Rollover_projection_guard.php';

/**
 * TT-C4 — A DOCUMENT THAT MATCHES BUT IS NOT MARKED IS THE LEAST PROTECTED
 *          DOCUMENT IN THE COLLECTION.
 *
 * `projectedFromVersion` is not metadata. It is the single field the rest of
 * this architecture uses to recognise canonical-derived state:
 *
 *   • Rollover_projection_guard refuses to carry last year's timetable over a
 *     document that has it. Without it, the session rollover replaces a
 *     published timetable with a copy on every teacher and parent screen.
 *   • Timetables_reconcile skips documents that have it. Without it, the
 *     reconciler edits projection state in place — a second authority changing
 *     what teachers see with no version recording that it happened.
 *
 * The projection originally decided "already projected" from CONTENT alone. So
 * a legacy document whose periods happened to equal the published version's was
 * reported unchanged, never written, and therefore never stamped. That is not a
 * rare case: it is the NORMAL case at switchover, and the guaranteed case for a
 * school whose canonical version was built from its existing legacy timetable.
 *
 * These tests pin the consequence, not just the field: they run the real
 * rollover guard against the document the projection would leave behind.
 */
final class TimetableProjectionProvenanceTest extends TestCase
{
    private const PLAN = 'PLAN_A';
    private const VER  = 'V2';

    /** What the projection says this cell must contain. */
    private function want(): array
    {
        return [
            'schoolId' => 'SCH_A', 'session' => '2026-27',
            'className' => 'Class 9th', 'section' => 'Section A',
            'classOrder' => 9, 'sectionCode' => 'A',
            'sectionKey' => 'Class 9th/Section A', 'day' => 'Monday',
            'periods' => [['periodNumber' => 1, 'subject' => 'Mathematics']],
            'manuallyEdited' => false,
            'projectedFromPlan' => self::PLAN, 'projectedFromVersion' => self::VER,
            'projectedAt' => '2026-09-15T00:10:00+00:00', 'projectedByUid' => 'cron',
        ];
    }

    /** A legacy document with the same content and whatever provenance is given. */
    private function have(array $over = []): array
    {
        $d = $this->want();
        unset($d['projectedFromPlan'], $d['projectedFromVersion'],
              $d['projectedAt'], $d['projectedByUid']);
        $d['version'] = 4;
        return array_merge($d, $over);
    }

    private function plan(array $have = null): array
    {
        $id = 'SCH_A_2026-27_Class 9th_Section A_Monday';
        return Timetable_projection_service::plan(
            [$id => $this->want()],
            $have === null ? [] : [$id => $have]);
    }

    // ── the defect ───────────────────────────────────────────────────────

    public function test_a_content_identical_but_unstamped_document_is_written(): void
    {
        $p = $this->plan($this->have());

        $this->assertCount(1, $p['writes'],
            'the document carries no projection provenance, so it is not yet a '
            . 'projected document however similar its content looks');
        $this->assertSame([], $p['unchanged']);
    }

    public function test_the_write_exists_only_to_stamp_it(): void
    {
        $p = $this->plan($this->have());
        $written = array_values($p['writes'])[0];

        $this->assertSame(self::VER, $written['projectedFromVersion']);
        $this->assertSame(self::PLAN, $written['projectedFromPlan']);
        $this->assertSame(5, $written['version'], 'monotonic counter advances by one');
    }

    /**
     * The consequence, executed rather than asserted about.
     *
     * This is the test that says why the field matters: the unstamped document
     * is handed to the real rollover guard, which agrees to overwrite it.
     */
    public function test_an_unstamped_document_is_not_protected_from_the_rollover(): void
    {
        $unstamped = $this->have();

        $this->assertSame(
            Rollover_projection_guard::WRITE,
            Rollover_projection_guard::decide($unstamped, 1, false),
            'an unstamped canonical-derived document is carried over by the '
            . 'session rollover — a published timetable replaced by last '
            . "year's copy on every teacher and parent screen");

        // And once the projection has stamped it, the same guard refuses.
        $stamped = array_values($this->plan($unstamped)['writes'])[0];
        $this->assertSame(
            Rollover_projection_guard::SKIP_PROJECTED,
            Rollover_projection_guard::decide($stamped, 1, false),
            'stamping it is what makes every downstream guard able to see it');
    }

    // ── still idempotent, which is the property the stamp must not break ──

    public function test_a_document_stamped_with_this_version_is_unchanged(): void
    {
        $p = $this->plan($this->have([
            'projectedFromPlan' => self::PLAN, 'projectedFromVersion' => self::VER,
        ]));

        $this->assertSame([], $p['writes']);
        $this->assertCount(1, $p['unchanged']);
    }

    /**
     * `projectedAt` moves on every run and `projectedByUid` changes with the
     * caller. If either were part of the comparison the projection would
     * rewrite every document forever, which is the same defect in a new coat.
     */
    public function test_a_moving_timestamp_does_not_defeat_idempotency(): void
    {
        $p = $this->plan($this->have([
            'projectedFromPlan'    => self::PLAN,
            'projectedFromVersion' => self::VER,
            'projectedAt'          => '1999-01-01T00:00:00+00:00',
            'projectedByUid'       => 'a_different_admin',
        ]));

        $this->assertSame([], $p['writes'],
            'provenance is about WHICH version, not when it was last confirmed');
    }

    public function test_a_document_stamped_with_a_DIFFERENT_version_is_rewritten(): void
    {
        $p = $this->plan($this->have([
            'projectedFromPlan' => self::PLAN, 'projectedFromVersion' => 'V1',
        ]));

        $this->assertCount(1, $p['writes'],
            'it is derived from a version that is no longer the one in force');
    }

    public function test_a_blank_stamp_is_not_a_stamp(): void
    {
        foreach (['', '   '] as $blank) {
            $p = $this->plan($this->have([
                'projectedFromPlan' => self::PLAN, 'projectedFromVersion' => $blank,
            ]));
            $this->assertCount(1, $p['writes'],
                'an empty provenance field must not read as provenance — the '
                . 'rollover guard trims it the same way');
        }
    }

    public function test_a_half_stamped_document_is_rewritten(): void
    {
        $p = $this->plan($this->have(['projectedFromVersion' => self::VER]));

        $this->assertCount(1, $p['writes'],
            'plan and version both identify the source; one without the other '
            . 'does not say which timetable this is a copy of');
    }

    // ── the predicate itself ─────────────────────────────────────────────

    public function test_provenance_matching_refuses_non_arrays(): void
    {
        $this->assertFalse(Timetable_projection_service::provenanceMatches(null, $this->want()));
        $this->assertFalse(Timetable_projection_service::provenanceMatches($this->want(), 'nope'));
    }

    public function test_content_and_provenance_are_separate_questions(): void
    {
        $stamped = $this->have([
            'projectedFromPlan' => self::PLAN, 'projectedFromVersion' => self::VER,
            'periods' => [['periodNumber' => 1, 'subject' => 'SOMETHING ELSE']],
        ]);

        $this->assertTrue(
            Timetable_projection_service::provenanceMatches($stamped, $this->want()),
            'it does claim to come from this version');
        $this->assertFalse(
            Timetable_projection_service::ownedFieldsMatch($stamped, $this->want()),
            'but its content has drifted from it');

        $p = $this->plan($stamped);
        $this->assertCount(1, $p['writes'],
            'drifted content is rewritten even when the stamp is right — the '
            . 'stamp is a claim, the content is the check');
    }
}
