<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

if (!defined('BASEPATH')) define('BASEPATH', __DIR__ . '/../../system/');
if (!defined('APPPATH'))  define('APPPATH',  __DIR__ . '/../../application/');
if (!function_exists('log_message')) { function log_message($l, $m) {} }
require_once __DIR__ . '/../../application/libraries/Timetable_plan_store.php';

/**
 * Firestore double covering versions, version days AND the legacy collection,
 * so a published version can actually be projected and the result inspected.
 */
class FakeProjectionFirestore
{
    public array $versions = [];
    public array $days     = [];
    public array $legacy   = [];
    public array $writes   = [];
    public bool  $legacyUnreadable = false;
    public int   $failLegacyWriteAt = 0;

    /**
     * Return legacy rows WITHOUT applying the query filters.
     *
     * This models a real hazard rather than a hypothetical one: a Firestore
     * query can come back wider than intended — a filter dropped in a refactor,
     * a fallback path, a query served differently than written. The store
     * re-checks schoolId and session on every row it received for exactly that
     * case, and a double that always filters perfectly can never exercise it.
     */
    public bool  $legacyQueryIgnoresFilters = false;

    private int $legacyWrites = 0;

    public function firestoreGetOrFail($collection, $docId)
    {
        return $this->versions[$docId] ?? null;
    }

    public function firestoreQueryOrFail($collection, $conditions = [], $orderBy = null, $dir = 'ASC', $limit = null)
    {
        if ($collection === 'timetables' && $this->legacyUnreadable) {
            throw new \RuntimeException('index missing');
        }
        if ($collection === 'timetables' && $this->legacyQueryIgnoresFilters) {
            return array_values($this->legacy);
        }
        $want = [];
        foreach ($conditions as $c) $want[(string) ($c[0] ?? '')] = $c[2] ?? null;

        $src = ['timetableVersionDays' => $this->days,
                'timetableVersions'    => $this->versions,
                'timetables'           => $this->legacy][$collection] ?? [];

        $out = [];
        foreach ($src as $docId => $row) {
            foreach ($want as $k => $v) {
                if (($row[$k] ?? null) !== $v) continue 2;
            }
            // TT-C2 — THE ROW SHAPE IS THE CONTRACT, AND THIS DOUBLE USED TO
            // BREAK IT. Firestore_rest_client::query() returns rows as
            // ['id' => …, 'data' => …]; this double returned them FLAT. That
            // single difference hid a defect that made the projection rewrite
            // every document on every run and made orphan reporting
            // structurally impossible, while every test here stayed green.
            //
            // A double that is easier to work with than the thing it stands in
            // for is not a simplification, it is a blind spot. It now returns
            // exactly what the client returns.
            $out[] = ['id' => (string) $docId, 'data' => $row];
        }
        return $out;
    }

    public function firestoreSet($collection, $docId, $data, $merge = true)
    {
        if ($collection === 'timetables') {
            $this->legacyWrites++;
            if ($this->failLegacyWriteAt > 0 && $this->legacyWrites === $this->failLegacyWriteAt) {
                return false;
            }
            $this->legacy[$docId] = $merge
                ? array_merge($this->legacy[$docId] ?? [], $data) : $data;
        }
        if ($collection === 'timetableVersions') {
            $this->versions[$docId] = $merge
                ? array_merge($this->versions[$docId] ?? [], $data) : $data;
        }
        if ($collection === 'timetableVersionDays') {
            $this->days[$docId] = $merge
                ? array_merge($this->days[$docId] ?? [], $data) : $data;
        }
        $this->writes[] = ['collection' => $collection, 'docId' => $docId,
                           'data' => $data, 'merge' => $merge];
        return true;
    }
}

/**
 * PHASE C — COMPATIBILITY PROJECTION.
 *
 * The Teacher app reads `timetables` directly through the client SDK, filtering
 * by schoolId + sectionKey, so that collection cannot be retired or reshaped
 * without an app release. What CAN change is its status: it stops being an
 * editable timetable and becomes a derived copy of a published version.
 *
 * These tests defend the three properties that make that safe — the projection
 * is deterministic and idempotent, it flows one way only, and it never destroys
 * teacher-visible content as a side effect.
 */
final class TimetableProjectionTest extends TestCase
{
    private const SCHOOL = 'SCH_A';
    private const SESS   = '2026-2027';
    private const PLAN   = 'PLAN_A';
    private const VID    = 'V1';


    private function store(FakeProjectionFirestore $fb): Timetable_plan_store
    {
        return (new Timetable_plan_store())->init($fb, self::SCHOOL, self::SESS, 'STA0001');
    }

    private function vDoc(string $vid = self::VID): string
    {
        return self::SCHOOL . '_' . self::SESS . '_' . self::PLAN . '_' . $vid;
    }

    private function legacyId(string $sectionKey, string $day): string
    {
        return self::SCHOOL . '_' . self::SESS . '_'
             . str_replace('/', '_', $sectionKey) . '_' . $day;
    }

    /** A published version with one section on Monday. */
    private function fb(string $status = 'published'): FakeProjectionFirestore
    {
        $fb = new FakeProjectionFirestore();
        $fb->versions[$this->vDoc()] = [
            'schoolId' => self::SCHOOL, 'session' => self::SESS,
            'planId' => self::PLAN, 'versionId' => self::VID,
            'status' => $status,
            'effectiveFrom' => '2026-06-01', 'effectiveTo' => '',
            'generation' => ['runId' => 'GEN_1',
                             'state' => Timetable_plan_service::GEN_COMPLETE,
                             'daysWritten' => 1],
        ];
        $fb->days[$this->vDoc() . '_Class 8th_Section A_Monday'] = [
            'schoolId' => self::SCHOOL, 'session' => self::SESS,
            'planId' => self::PLAN, 'versionId' => self::VID,
            'classSection' => 'Class 8th/Section A', 'day' => 'Monday',
            'periods' => [[
                'periodNumber' => 1, 'subject' => 'Mathematics', 'teacher' => 'R Rao',
                'teacherId' => 'STA001', 'startTime' => '09:00AM', 'endTime' => '09:45AM',
                'room' => '', 'type' => 'class',
            ]],
            'manuallyEdited' => false,
        ];
        return $fb;
    }

    // ── the consumer contract ───────────────────────────────────────────

    public function test_the_projected_document_matches_the_teacher_app_contract(): void
    {
        $fb = $this->fb();
        $this->store($fb)->projectPublishedVersion(self::PLAN, self::VID, true);

        $id  = $this->legacyId('Class 8th/Section A', 'Monday');
        $doc = $fb->legacy[$id] ?? null;
        $this->assertNotNull($doc, 'the projection must land on the document id the '
            . 'app already reads, not a new one beside it');

        // The Teacher app queries schoolId + sectionKey and filters session
        // client-side, then reads day, className, section and periods[].
        $this->assertSame(self::SCHOOL, $doc['schoolId']);
        $this->assertSame(self::SESS, $doc['session']);
        $this->assertSame('Class 8th/Section A', $doc['sectionKey']);
        $this->assertSame('Class 8th', $doc['className']);
        $this->assertSame('Section A', $doc['section']);
        $this->assertSame('Monday', $doc['day']);

        foreach (['periodNumber', 'subject', 'teacher', 'teacherId',
                  'startTime', 'endTime', 'room', 'type'] as $field) {
            $this->assertArrayHasKey($field, $doc['periods'][0],
                "the app reads period.{$field}; dropping it would blank the timetable");
        }
        $this->assertSame('Mathematics', $doc['periods'][0]['subject']);
        $this->assertSame('STA001', $doc['periods'][0]['teacherId']);
    }

    public function test_the_canonical_class_normaliser_is_used(): void
    {
        $fb = $this->fb();
        // A raw, un-normalised key as a section might legitimately be stored.
        $k = $this->vDoc() . '_Class 8th_Section A_Monday';
        $fb->days[$k]['classSection'] = 'Class 8/A';

        $this->store($fb)->projectPublishedVersion(self::PLAN, self::VID, true);
        $doc = array_values($fb->legacy)[0];

        $this->assertSame('Class 8th', $doc['className'],
            'the projection must go through the same normaliser as the rest of the '
            . 'codebase, not re-parse class names itself');
        $this->assertSame('Section A', $doc['section']);
        $this->assertSame(8, $doc['classOrder']);
        $this->assertSame('A', $doc['sectionCode']);
    }

    public function test_the_document_is_marked_as_derived(): void
    {
        $fb = $this->fb();
        $this->store($fb)->projectPublishedVersion(self::PLAN, self::VID, true);
        $doc = array_values($fb->legacy)[0];

        $this->assertSame(self::VID, $doc['projectedFromVersion'],
            'a derived document must say what it was derived from, or nobody can '
            . 'tell a projection from a hand edit');
        $this->assertSame(self::PLAN, $doc['projectedFromPlan']);
        $this->assertSame('STA0001', $doc['projectedByUid']);
    }

    // ── dry run by default ──────────────────────────────────────────────

    public function test_projection_writes_nothing_unless_asked(): void
    {
        $fb   = $this->fb();
        $plan = $this->store($fb)->projectPublishedVersion(self::PLAN, self::VID);

        $this->assertFalse($plan['applied']);
        $this->assertCount(1, $plan['writes']);
        $this->assertSame([], $fb->legacy,
            'asking what would happen must never write to the collection teachers '
            . 'are reading from');
    }

    public function test_the_plan_explains_itself(): void
    {
        $fb   = $this->fb();
        $plan = $this->store($fb)->projectPublishedVersion(self::PLAN, self::VID);
        $this->assertMatchesRegularExpression('~would be written~', $plan['explain']);
    }

    // ── idempotence ─────────────────────────────────────────────────────

    public function test_projecting_twice_writes_nothing_the_second_time(): void
    {
        $fb    = $this->fb();
        $store = $this->store($fb);

        $first = $store->projectPublishedVersion(self::PLAN, self::VID, true);
        $this->assertSame(1, $first['written']);

        $second = $store->projectPublishedVersion(self::PLAN, self::VID, true);
        $this->assertSame(0, $second['written'],
            'an idempotent projection is what makes it safe to run repeatedly');
        $this->assertCount(1, $second['unchanged']);
    }

    public function test_the_version_counter_does_not_drift_on_a_no_op(): void
    {
        $fb    = $this->fb();
        $store = $this->store($fb);
        $store->projectPublishedVersion(self::PLAN, self::VID, true);
        $v1 = array_values($fb->legacy)[0]['version'];

        $store->projectPublishedVersion(self::PLAN, self::VID, true);
        $this->assertSame($v1, array_values($fb->legacy)[0]['version'],
            're-projecting identical content must not bump the document version');
    }

    public function test_a_changed_timetable_bumps_the_version(): void
    {
        $fb    = $this->fb();
        $store = $this->store($fb);
        $store->projectPublishedVersion(self::PLAN, self::VID, true);
        $before = array_values($fb->legacy)[0]['version'];

        $k = $this->vDoc() . '_Class 8th_Section A_Monday';
        $fb->days[$k]['periods'][0]['subject'] = 'Physics';

        $store->projectPublishedVersion(self::PLAN, self::VID, true);
        $after = array_values($fb->legacy)[0];

        $this->assertSame($before + 1, $after['version']);
        $this->assertSame('Physics', $after['periods'][0]['subject']);
    }

    // ── one way only ────────────────────────────────────────────────────

    public function test_projection_never_writes_the_canonical_collections(): void
    {
        $fb = $this->fb();
        $this->store($fb)->projectPublishedVersion(self::PLAN, self::VID, true);

        $written = array_values(array_unique(array_column($fb->writes, 'collection')));
        $this->assertSame(['timetables'], $written,
            'a projection flows canonical → legacy only; writing back would make '
            . 'the legacy collection an authority again');
    }

    public function test_a_legacy_edit_is_overwritten_not_read_back(): void
    {
        $fb    = $this->fb();
        $store = $this->store($fb);
        $store->projectPublishedVersion(self::PLAN, self::VID, true);

        // Somebody edits the legacy document directly.
        $id = $this->legacyId('Class 8th/Section A', 'Monday');
        $fb->legacy[$id]['periods'][0]['subject'] = 'Hand edited';

        $store->projectPublishedVersion(self::PLAN, self::VID, true);

        $this->assertSame('Mathematics', $fb->legacy[$id]['periods'][0]['subject'],
            'the canonical version wins; a legacy edit is not a source of truth');

        $days = array_values($fb->days);
        $this->assertSame('Mathematics', $days[0]['periods'][0]['subject'],
            'and it must never propagate back into the canonical version');
    }

    // ── unknown legacy metadata survives ────────────────────────────────

    public function test_legacy_metadata_this_layer_did_not_author_is_preserved(): void
    {
        $fb = $this->fb();
        $id = $this->legacyId('Class 8th/Section A', 'Monday');
        $fb->legacy[$id] = [
            'schoolId' => self::SCHOOL, 'session' => self::SESS,
            'sectionKey' => 'Class 8th/Section A', 'day' => 'Monday',
            'periods' => [], 'version' => 7,
            'generationId' => 'GEN_LEGACY_9', 'generatedByName' => 'Old Admin',
        ];

        $this->store($fb)->projectPublishedVersion(self::PLAN, self::VID, true);

        $this->assertSame('GEN_LEGACY_9', $fb->legacy[$id]['generationId'],
            'evidence about how the current live document got there must survive a '
            . 'migration that is supposed to be reversible');
        $this->assertSame('Old Admin', $fb->legacy[$id]['generatedByName']);
        $this->assertSame(8, $fb->legacy[$id]['version']);
    }

    // ── orphans are reported, never deleted ─────────────────────────────

    public function test_orphans_are_reported_and_left_alone(): void
    {
        $fb  = $this->fb();
        $gone = $this->legacyId('Class 9th/Section B', 'Friday');
        $fb->legacy[$gone] = [
            'schoolId' => self::SCHOOL, 'session' => self::SESS,
            'sectionKey' => 'Class 9th/Section B', 'day' => 'Friday',
            'periods' => [['periodNumber' => 1, 'subject' => 'Stale']],
        ];

        $plan = $this->store($fb)->projectPublishedVersion(self::PLAN, self::VID, true);

        $this->assertSame([$gone], $plan['orphans']);
        $this->assertArrayHasKey($gone, $fb->legacy,
            'a live teacher-visible document must not disappear as a side effect '
            . 'of projecting something else');
        $this->assertMatchesRegularExpression('~rather than deleted~', $plan['explain']);
    }

    public function test_nothing_is_ever_deleted_from_the_legacy_collection(): void
    {
        $src = (string) file_get_contents(
            __DIR__ . '/../../application/libraries/Timetable_plan_store.php');
        foreach (['firestoreDelete', 'firestoreBatchDelete'] as $op) {
            $this->assertStringNotContainsString($op . '(self::COLLECTION_LEGACY_TT', $src);
        }
    }

    // ── the gates ───────────────────────────────────────────────────────

    public function test_only_a_published_version_can_be_projected(): void
    {
        foreach (['draft', 'candidate', 'superseded'] as $status) {
            $fb = $this->fb($status);
            try {
                $this->store($fb)->projectPublishedVersion(self::PLAN, self::VID, true);
                $this->fail("a {$status} version must not reach teachers");
            } catch (Service_exception $e) {
                $this->assertMatchesRegularExpression('~not been published~i', $e->getMessage());
            }
            $this->assertSame([], $fb->legacy);
        }
    }

    public function test_an_incomplete_version_cannot_be_projected(): void
    {
        $fb = $this->fb();
        $fb->versions[$this->vDoc()]['generation']['state'] =
            Timetable_plan_service::GEN_FAILED;

        $this->expectException(Service_exception::class);
        $this->expectExceptionMessageMatches('~not generated completely~i');
        $this->store($fb)->projectPublishedVersion(self::PLAN, self::VID, true);
    }

    public function test_a_missing_version_is_refused(): void
    {
        $fb = new FakeProjectionFirestore();
        $this->expectException(Service_exception::class);
        $this->expectExceptionMessageMatches('~does not exist~i');
        $this->store($fb)->projectPublishedVersion(self::PLAN, 'NOPE', true);
    }

    public function test_an_unreadable_legacy_collection_refuses_rather_than_writing_blind(): void
    {
        $fb = $this->fb();
        $fb->legacyUnreadable = true;

        try {
            $this->store($fb)->projectPublishedVersion(self::PLAN, self::VID, true);
            $this->fail('projecting without seeing what is already there is a guess');
        } catch (Service_exception $e) {
            $this->assertMatchesRegularExpression('~could not be read~i', $e->getMessage());
        }
        $this->assertSame([], $fb->legacy);
    }

    public function test_a_refused_write_reports_a_partial_projection_honestly(): void
    {
        $fb = $this->fb();
        // A second section, so there are two writes and one can fail.
        $fb->days[$this->vDoc() . '_Class 9th_Section A_Monday'] = [
            'schoolId' => self::SCHOOL, 'session' => self::SESS,
            'planId' => self::PLAN, 'versionId' => self::VID,
            'classSection' => 'Class 9th/Section A', 'day' => 'Monday',
            'periods' => [], 'manuallyEdited' => false,
        ];
        $fb->failLegacyWriteAt = 2;

        try {
            $this->store($fb)->projectPublishedVersion(self::PLAN, self::VID, true);
            $this->fail('a refused write must not be reported as a full projection');
        } catch (Service_exception $e) {
            $this->assertMatchesRegularExpression('~stopped after 1 of 2~', $e->getMessage(),
                'the operator must be told exactly how far it got');
            $this->assertMatchesRegularExpression('~previous timetable~i', $e->getMessage(),
                'and what teachers are seeing meanwhile');
        }
    }

    // ── tenant and session isolation ────────────────────────────────────

    public function test_another_tenants_version_cannot_be_projected(): void
    {
        $fb = $this->fb();
        $fb->versions[$this->vDoc()]['schoolId'] = 'SCH_OTHER';

        $this->expectException(Service_exception::class);
        $this->store($fb)->projectPublishedVersion(self::PLAN, self::VID, true);
    }

    public function test_another_tenants_legacy_rows_are_not_treated_as_ours(): void
    {
        $fb = $this->fb();
        $fb->legacy['SCH_OTHER_' . self::SESS . '_Class 8th_Section A_Monday'] = [
            'schoolId' => 'SCH_OTHER', 'session' => self::SESS,
            'sectionKey' => 'Class 8th/Section A', 'day' => 'Monday', 'periods' => [],
        ];

        $plan = $this->store($fb)->projectPublishedVersion(self::PLAN, self::VID);
        $this->assertSame([], $plan['orphans'],
            'another school\'s documents must never be reported as our orphans');
    }

    public function test_another_sessions_legacy_rows_are_not_treated_as_ours(): void
    {
        $fb = $this->fb();
        $fb->legacy[self::SCHOOL . '_2025-2026_Class 8th_Section A_Monday'] = [
            'schoolId' => self::SCHOOL, 'session' => '2025-2026',
            'sectionKey' => 'Class 8th/Section A', 'day' => 'Monday', 'periods' => [],
        ];

        $plan = $this->store($fb)->projectPublishedVersion(self::PLAN, self::VID);
        $this->assertSame([], $plan['orphans'],
            'last year\'s timetable is not an orphan of this year\'s version');
    }

    /**
     * Defence in depth, and it has to be tested as such. When the query comes
     * back wider than it was written, the row-level tenant check is the only
     * thing standing between another school's timetable and this school's
     * projection plan.
     */
    public function test_a_wider_than_expected_query_cannot_leak_another_tenant(): void
    {
        $fb = $this->fb();
        $fb->legacyQueryIgnoresFilters = true;
        $foreign = 'SCH_OTHER_' . self::SESS . '_Class 9th_Section B_Friday';
        $fb->legacy[$foreign] = [
            'schoolId' => 'SCH_OTHER', 'session' => self::SESS,
            'sectionKey' => 'Class 9th/Section B', 'day' => 'Friday', 'periods' => [],
        ];

        $plan = $this->store($fb)->projectPublishedVersion(self::PLAN, self::VID);

        $this->assertSame([], $plan['orphans'],
            'a row from another school must be discarded on arrival, not trusted '
            . 'because the query was supposed to have excluded it');
    }

    public function test_a_wider_than_expected_query_cannot_leak_another_session(): void
    {
        $fb = $this->fb();
        $fb->legacyQueryIgnoresFilters = true;
        $fb->legacy[self::SCHOOL . '_2025-2026_Class 9th_Section B_Friday'] = [
            'schoolId' => self::SCHOOL, 'session' => '2025-2026',
            'sectionKey' => 'Class 9th/Section B', 'day' => 'Friday', 'periods' => [],
        ];

        $plan = $this->store($fb)->projectPublishedVersion(self::PLAN, self::VID);

        $this->assertSame([], $plan['orphans'],
            'last year\'s timetable must not be reported as this year\'s orphan '
            . 'just because the query returned it');
    }

    // ── the pure mapper ─────────────────────────────────────────────────

    public function test_the_mapper_is_deterministic(): void
    {
        $days = [['classSection' => 'Class 8th/Section A', 'day' => 'Monday',
                  'periods' => [], 'manuallyEdited' => false]];
        $ctx  = ['schoolId' => 'S', 'session' => 'X', 'planId' => 'P',
                 'versionId' => 'V', 'at' => 'T', 'byUid' => 'U'];

        $this->assertSame(
            Timetable_projection_service::project($days, $ctx),
            Timetable_projection_service::project($days, $ctx),
            'a projection that varies between runs cannot be verified');
    }

    public function test_the_mapper_refuses_without_a_tenant(): void
    {
        $days = [['classSection' => 'Class 8th/Section A', 'day' => 'Monday', 'periods' => []]];
        $this->assertSame([], Timetable_projection_service::project($days, ['session' => 'X']));
        $this->assertSame([], Timetable_projection_service::project($days, ['schoolId' => 'S']));
    }

    public function test_the_mapper_skips_malformed_rows(): void
    {
        $out = Timetable_projection_service::project([
            'garbage', ['day' => 'Monday'], ['classSection' => 'Class 8th/Section A'],
            ['classSection' => 'Class 8th/Section A', 'day' => 'Monday', 'periods' => []],
        ], ['schoolId' => 'S', 'session' => 'X']);

        $this->assertCount(1, $out, 'a row with no section or no day cannot become a '
            . 'document id, and must be skipped rather than guessed at');
    }


}