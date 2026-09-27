<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

if (!defined('BASEPATH')) define('BASEPATH', __DIR__ . '/../../system/');
if (!defined('APPPATH'))  define('APPPATH',  __DIR__ . '/../../application/');
if (!function_exists('log_message')) { function log_message($l, $m) {} }
require_once __DIR__ . '/../../application/libraries/Timetable_plan_store.php';

/**
 * THE ROW SHAPE IS A CONTRACT, AND A DOUBLE THAT GETS IT WRONG HIDES BUGS.
 *
 * Firestore_rest_client::query() returns every row as ['id' => …, 'data' => …].
 * Timetable_plan_store has three readers of that shape. Two unwrapped it and
 * one did not, and because the unit double for the projection returned rows
 * FLAT, the one that did not was invisible: `_existingLegacyDocs()` rejected
 * every row it was given and always returned an empty array, so the projection
 * rewrote every document on every run and could never report an orphan. Twenty-
 * six green tests, including one named "projecting twice writes nothing the
 * second time", said otherwise.
 *
 * These tests exist so the shape can never drift back silently. They drive the
 * store with the ENVELOPE shape the real client produces, and separately with
 * the bare shape a caller that already unwrapped would pass, and require the
 * same answer from both.
 *
 * They are deliberately about the row shape and nothing else — the behaviour
 * this shape controls is covered by TimetableProjectionTest and by
 * scripts/timetable_projection_runtime_proof.js, which proves the same property
 * against a real Firestore emulator rather than against any double at all.
 */
final class FirestoreRowEnvelopeContractTest extends TestCase
{
    private const SCHOOL = 'SCH_A';
    private const SESS   = '2026-27';

    /** The one legacy document under test, as it is stored. */
    private function doc(array $over = []): array
    {
        return array_merge([
            'schoolId' => self::SCHOOL, 'session' => self::SESS,
            'className' => 'Class 9th', 'section' => 'Section A',
            'classOrder' => 9, 'sectionCode' => 'A',
            'sectionKey' => 'Class 9th/Section A', 'day' => 'Monday',
            'periods' => [], 'manuallyEdited' => false, 'version' => 3,
        ], $over);
    }

    private function docId(string $day = 'Monday'): string
    {
        return self::SCHOOL . '_' . self::SESS . '_Class 9th_Section A_' . $day;
    }

    /**
     * Run _existingLegacyDocs() over rows in whichever shape is given.
     * Reflection is used because the method is private by design — the point
     * of the test is the shape it consumes, not its visibility.
     */
    private function readBack(array $rows): array
    {
        $fb = new class($rows) {
            public function __construct(private array $rows) {}
            public function firestoreQueryOrFail($c, $cond = [], $o = null, $d = 'ASC', $l = null)
            { return $this->rows; }
        };

        $store = (new Timetable_plan_store())->init($fb, self::SCHOOL, self::SESS, 'STA0001');
        $m = new ReflectionMethod(Timetable_plan_store::class, '_existingLegacyDocs');
        $m->setAccessible(true);
        return $m->invoke($store);
    }

    // ── the shape the real client actually returns ───────────────────────

    public function test_an_envelope_row_is_seen(): void
    {
        $out = $this->readBack([['id' => $this->docId(), 'data' => $this->doc()]]);

        $this->assertArrayHasKey($this->docId(), $out,
            'the row the REST client returns must be visible to the projection; '
            . 'when it was not, every document was rewritten on every run');
        $this->assertSame(3, $out[$this->docId()]['version']);
    }

    public function test_an_already_unwrapped_row_is_also_seen(): void
    {
        $out = $this->readBack([$this->doc()]);

        $this->assertArrayHasKey($this->docId(), $out,
            'a caller that already unwrapped is still understood, the same way '
            . 'listVersions() and listCandidateDays() accept both shapes');
    }

    public function test_both_shapes_produce_the_same_map(): void
    {
        $this->assertSame(
            $this->readBack([$this->doc()]),
            $this->readBack([['id' => $this->docId(), 'data' => $this->doc()]]),
            'the shape a row arrives in must not change what the projection sees');
    }

    // ── the key is the document's real id ────────────────────────────────

    /**
     * The map used to be keyed by an id RECONSTRUCTED from sectionKey + day.
     * When the real document lives somewhere else, that invents an entry at an
     * address where nothing exists — and the projection then reports that
     * address as "already there" and skips writing it. A class with no
     * timetable at all is the worst outcome this collection can produce, so
     * the key must be the id Firestore reported.
     */
    public function test_the_real_document_id_is_the_key_not_a_reconstructed_one(): void
    {
        $out = $this->readBack([[
            'id'   => 'SOME_LEGACY_ID_FROM_BEFORE_THE_CONVENTION',
            'data' => $this->doc(),
        ]]);

        $this->assertSame(['SOME_LEGACY_ID_FROM_BEFORE_THE_CONVENTION'], array_keys($out),
            'a document is keyed where it actually lives');
        $this->assertArrayNotHasKey($this->docId(), $out,
            'and never at an address invented from its fields, which the '
            . 'projection would read as "already written" and skip');
    }

    // ── tenant and session re-checks still apply to the unwrapped row ────

    public function test_a_foreign_tenant_row_is_rejected_through_the_envelope(): void
    {
        $out = $this->readBack([
            ['id' => $this->docId(), 'data' => $this->doc()],
            ['id' => 'SCH_B_' . self::SESS . '_Class 9th_Section A_Monday',
             'data' => $this->doc(['schoolId' => 'SCH_B'])],
        ]);

        $this->assertSame([$this->docId()], array_keys($out),
            'defence in depth has to read the document, not the envelope');
    }

    public function test_a_foreign_session_row_is_rejected_through_the_envelope(): void
    {
        $out = $this->readBack([
            ['id' => $this->docId(), 'data' => $this->doc()],
            ['id' => self::SCHOOL . '_2025-26_Class 9th_Section A_Monday',
             'data' => $this->doc(['session' => '2025-26'])],
        ]);

        $this->assertSame([$this->docId()], array_keys($out));
    }

    // ── a row that identifies nothing is dropped, not invented ───────────

    public function test_an_unwrapped_row_without_sectionKey_or_day_is_dropped(): void
    {
        $bare = $this->doc();
        unset($bare['sectionKey']);

        $this->assertSame([], $this->readBack([$bare]),
            'with no id and nothing to build one from, there is no address to '
            . 'compare against — and an invented key would make the projection '
            . 'skip a write for a document that is not there');
    }

    public function test_a_row_that_is_not_an_array_is_dropped(): void
    {
        $this->assertSame([], $this->readBack(['not a row', 42, null]));
    }
}
