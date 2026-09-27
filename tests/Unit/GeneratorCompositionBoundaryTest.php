<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

if (!defined('BASEPATH')) define('BASEPATH', __DIR__ . '/../../system/');
if (!defined('APPPATH'))  define('APPPATH',  __DIR__ . '/../../application/');
if (!function_exists('log_message')) { function log_message($l, $m) {} }
require_once __DIR__ . '/../../application/libraries/Timetable_service.php';

/**
 * PHASE 6 STEP 2 — composition / destination separation, proven behaviourally.
 *
 * The generator now has three responsibilities in three places:
 *
 *     _placeGrid()            WHERE each subject goes          (no I/O)
 *     _composeGeneratedDays() WHAT each day contains, and
 *                             whether it may be written at all (no destination)
 *     _liveBatchOpsFrom()     HOW the live destination stores it
 *
 * The point is that a candidate destination becomes a sibling of the third
 * method, with the first two shared. Two placement algorithms, or two
 * compositions, would drift — one would enforce a constraint the other did not.
 *
 * ── THE INTERESTING PART: WHAT COMPOSITION NEEDS FROM A DESTINATION ──────
 *
 * Composition has to make the ACAD-D23 decision — preserve a manually edited day
 * unless force-overwrite is set — and that needs to know what is already stored.
 * That is the ONE destination-shaped question it asks, and it arrives as a lookup
 * closure rather than a map keyed by live document ids.
 *
 * The live semantics are unchanged. The closure rebuilds the same live document
 * id from the same canonical section key and reads the same preload map. It has
 * NOT become "preserve because the destination contains something" — for a
 * candidate that is a different rule, and choosing it belongs to the phase that
 * adds candidate generation, not to this extraction.
 *
 * These tests call the real methods with real inputs and assert real output,
 * including the exact live document ids and document fields.
 */
final class GeneratorCompositionBoundaryTest extends TestCase
{
    private function svc(): Timetable_service
    {
        $svc = (new ReflectionClass('Timetable_service'))->newInstanceWithoutConstructor();
        foreach (['schoolId' => 'SCH_A', 'adminId' => 'STA001', 'adminName' => 'R Rao'] as $p => $v) {
            $r = new ReflectionProperty('Timetable_service', $p);
            $r->setAccessible(true);
            $r->setValue($svc, $v);
        }
        return $svc;
    }

    private function call(Timetable_service $svc, string $method, array $args)
    {
        $m = new ReflectionMethod('Timetable_service', $method);
        $m->setAccessible(true);
        return $m->invokeArgs($svc, $args);
    }

    /** Compose needs $result by reference, so this wrapper keeps that explicit. */
    private function compose(Timetable_service $svc, array $grid, array $times, callable $lookup, bool $force, array &$result): array
    {
        $m = new ReflectionMethod('Timetable_service', '_composeGeneratedDays');
        $m->setAccessible(true);
        $args = [$grid, $times, $lookup, $force, &$result];
        return $m->invokeArgs($svc, $args);
    }

    private function emptyResult(): array
    {
        return ['sections_generated' => 0, 'unallocated' => 0, 'manual_skipped' => 0,
                'manual_overwritten' => 0, 'manual_cells' => [], 'timetable' => []];
    }

    private function grid(): array
    {
        return ['Class 8/Section A' => ['Monday' => [
            ['subject' => 'Mathematics', 'teacherId' => 'STA001',
             'teacherName' => 'R Rao', 'type' => 'class'],
            null,
        ]]];
    }

    private function times(): array
    {
        return [['start' => '09:00AM', 'end' => '09:45AM'],
                ['start' => '09:45AM', 'end' => '10:30AM']];
    }

    private function noExisting(): callable
    {
        return fn(string $sk, string $day) => null;
    }

    // ── composition output ───────────────────────────────────────────────

    public function test_it_composes_periods_including_empty_slots(): void
    {
        $svc = $this->svc();
        $result = $this->emptyResult();

        $composed = $this->compose($svc, $this->grid(), $this->times(),
            $this->noExisting(), false, $result);

        $this->assertCount(1, $composed);
        $p = $composed[0]['periods'];
        $this->assertCount(2, $p, 'every period slot must be emitted, filled or not');

        $this->assertSame(1, $p[0]['periodNumber']);
        $this->assertSame('Mathematics', $p[0]['subject']);
        $this->assertSame('R Rao', $p[0]['teacher']);
        $this->assertSame('STA001', $p[0]['teacherId']);
        $this->assertSame('09:00AM', $p[0]['startTime']);

        // An unplaced slot becomes an EMPTY period, not a missing one. That is
        // what the unallocated counter measures and what the grid renders.
        $this->assertSame(2, $p[1]['periodNumber']);
        $this->assertSame('', $p[1]['subject']);
        $this->assertSame('', $p[1]['teacherId']);
        $this->assertSame('09:45AM', $p[1]['startTime']);

        $this->assertSame(1, $result['unallocated'], 'the free slot must be counted');
        $this->assertSame(1, $result['sections_generated']);
    }

    /** Composition must never build an id or name a collection. */
    public function test_composition_emits_no_destination_information(): void
    {
        $svc = $this->svc();
        $result = $this->emptyResult();

        $composed = $this->compose($svc, $this->grid(), $this->times(),
            $this->noExisting(), false, $result);

        foreach (['docId', 'collection', 'op'] as $k) {
            $this->assertArrayNotHasKey($k, $composed[0],
                "composition leaked '{$k}' — a destination-shaped field here is what "
                . 'forces a second composition for the candidate path');
        }
        // normalizeClassSection canonicalises 'Class 8' to 'Class 8th'. Composition
        // emits the CANONICAL identity, which is what the live document id and
        // every downstream consumer are built from.
        $this->assertSame('Class 8th/Section A', $composed[0]['sectionKey']);
        $this->assertSame('Monday', $composed[0]['day']);
    }

    // ── ACAD-D23 preservation, at the boundary ───────────────────────────

    public function test_a_manually_edited_day_is_preserved_and_not_written(): void
    {
        $svc = $this->svc();
        $result = $this->emptyResult();

        $existing = fn($sk, $day) => [
            'manuallyEdited' => true, 'version' => 7,
            'periods' => [['periodNumber' => 1, 'subject' => 'HAND BUILT']],
        ];

        $composed = $this->compose($svc, $this->grid(), $this->times(),
            $existing, false, $result);

        $this->assertSame([], $composed,
            'a preserved day must produce NO writable day — if it did, the adapter '
            . 'would overwrite hand-built work the operator did not authorise');
        $this->assertSame(1, $result['manual_skipped']);
        $this->assertSame(0, $result['manual_overwritten']);
        $this->assertSame(['Class 8th/Section A/Monday (preserved)'], $result['manual_cells']);

        // The preserved content must be what the preview shows back.
        $this->assertSame('HAND BUILT',
            $result['timetable']['Class 8/Section A']['Monday'][0]['subject'],
            'the preview must show the PRESERVED day, not the generated one');
    }

    public function test_force_overwrite_writes_the_day_and_counts_it(): void
    {
        $svc = $this->svc();
        $result = $this->emptyResult();

        $existing = fn($sk, $day) => ['manuallyEdited' => true, 'version' => 7,
                                      'periods' => [['subject' => 'HAND BUILT']]];

        $composed = $this->compose($svc, $this->grid(), $this->times(),
            $existing, true, $result);

        $this->assertCount(1, $composed, 'force overwrite must produce a writable day');
        $this->assertSame(1, $result['manual_overwritten']);
        $this->assertSame(0, $result['manual_skipped']);
        $this->assertSame(['Class 8th/Section A/Monday (overwritten)'], $result['manual_cells'],
            'ACAD-D27: the operator is warned from these counters BEFORE confirming');
        $this->assertSame(7, $composed[0]['prevVersion'],
            'the superseded version must be carried through for the adapter');
    }

    /**
     * A day that exists but was NOT hand-edited is regenerated normally. This is
     * the ordinary case and must not be swept up by the preservation rule.
     */
    public function test_a_generated_day_is_not_treated_as_manually_edited(): void
    {
        $svc = $this->svc();
        $result = $this->emptyResult();

        $existing = fn($sk, $day) => ['manuallyEdited' => false, 'version' => 3,
                                      'periods' => [['subject' => 'OLD GEN']]];

        $composed = $this->compose($svc, $this->grid(), $this->times(),
            $existing, false, $result);

        $this->assertCount(1, $composed);
        $this->assertSame(0, $result['manual_skipped']);
        $this->assertSame(3, $composed[0]['prevVersion']);
    }

    /**
     * The lookup is asked using the CANONICAL section key and day — the same
     * identity the live document id is built from. If composition asked with
     * something else, a destination could not answer correctly.
     */
    public function test_the_lookup_is_asked_with_the_canonical_identity(): void
    {
        $svc = $this->svc();
        $result = $this->emptyResult();
        $asked = [];

        $lookup = function (string $sk, string $day) use (&$asked) {
            $asked[] = [$sk, $day];
            return null;
        };

        $this->compose($svc, $this->grid(), $this->times(), $lookup, false, $result);

        $this->assertSame([['Class 8th/Section A', 'Monday']], $asked,
            'the lookup must be asked with the CANONICAL identity — the same one the '
            . 'live document id is built from, or a destination cannot answer correctly');
    }

    // ── live destination adapter: exact ids and fields ───────────────────

    public function test_the_live_adapter_builds_the_exact_document_id(): void
    {
        $svc = $this->svc();
        $composed = [[
            'sectionKey' => 'Class 8/Section A', 'canonClass' => 'Class 8',
            'canonSection' => 'Section A', 'classOrder' => 8, 'sectionCode' => 'A',
            'day' => 'Monday', 'periods' => [], 'prevVersion' => 4,
        ]];

        $ops = $this->call($svc, '_liveBatchOpsFrom',
            [$composed, '2026-2027', 'GEN_X', '2026-08-28T10:00:00+00:00']);

        $this->assertCount(1, $ops);
        $this->assertSame('SCH_A_2026-2027_Class 8_Section A_Monday', $ops[0]['docId'],
            'the live document id format is a production contract — the Teacher app '
            . 'reads these documents directly');
        $this->assertSame('timetables', $ops[0]['collection']);
        $this->assertSame('set', $ops[0]['op']);
        $this->assertFalse($ops[0]['merge'],
            'whole-document replacement is the existing live semantics and must not '
            . 'change during an extraction');
    }

    public function test_the_live_adapter_writes_the_exact_document_fields(): void
    {
        $svc = $this->svc();
        $composed = [[
            'sectionKey' => 'Class 8/Section A', 'canonClass' => 'Class 8',
            'canonSection' => 'Section A', 'classOrder' => 8, 'sectionCode' => 'A',
            'day' => 'Monday', 'periods' => [['periodNumber' => 1]], 'prevVersion' => 4,
        ]];

        $d = $this->call($svc, '_liveBatchOpsFrom',
            [$composed, '2026-2027', 'GEN_X', 'NOW'])[0]['data'];

        $this->assertSame([
            'schoolId', 'session', 'className', 'section', 'classOrder', 'sectionCode',
            'sectionKey', 'day', 'periods', 'manuallyEdited', 'generationId',
            'generatedAt', 'generatedByUid', 'generatedByName', 'updatedAt',
            'updatedByUid', 'version',
        ], array_keys($d), 'the live document shape is a mobile contract; the field '
            . 'set and its order must survive the extraction unchanged');

        $this->assertSame('SCH_A', $d['schoolId']);
        $this->assertSame('2026-2027', $d['session']);
        $this->assertFalse($d['manuallyEdited'],
            'a generated day is by definition not hand-edited');
        $this->assertSame(5, $d['version'], 'version must supersede prevVersion');
        $this->assertSame('GEN_X', $d['generationId']);
        $this->assertSame('STA001', $d['generatedByUid']);
    }

    /** Tenant and session must come from the service and the caller, never a row. */
    public function test_the_adapter_cannot_be_told_a_different_tenant_by_a_row(): void
    {
        $svc = $this->svc();
        $composed = [[
            'sectionKey' => 'C/S', 'canonClass' => 'C', 'canonSection' => 'S',
            'classOrder' => 1, 'sectionCode' => 'S', 'day' => 'Monday',
            'periods' => [], 'prevVersion' => 0,
            'schoolId' => 'SCH_EVIL', 'session' => '1999-2000',   // ignored
        ]];

        $d = $this->call($svc, '_liveBatchOpsFrom',
            [$composed, '2026-2027', 'GEN_X', 'NOW'])[0];

        $this->assertStringStartsWith('SCH_A_2026-2027_', $d['docId'],
            'tenant and session identity must come from the service and the caller — '
            . 'a composed row must never be able to redirect a write');
        $this->assertSame('SCH_A', $d['data']['schoolId']);
        $this->assertSame('2026-2027', $d['data']['session']);
    }

    // ── one algorithm, one composition ───────────────────────────────────

    public function test_there_is_one_placement_and_one_composition(): void
    {
        $src = (string) file_get_contents(
            __DIR__ . '/../../application/libraries/Timetable_service.php');
        $code = (string) preg_replace('~/\*.*?\*/~s', '', $src);
        $code = (string) preg_replace('~^\s*//.*$~m', '', $code);

        $this->assertSame(1, preg_match_all('~private function _placeGrid\(~', $code));
        $this->assertSame(1, preg_match_all('~private function _composeGeneratedDays\(~', $code));

        // The document-building expression must exist in exactly ONE place.
        $this->assertSame(1, preg_match_all("~'generatedByName' =>~", $code),
            'the live document is assembled in more than one place — a second '
            . 'destination writer has appeared instead of a sibling adapter');
    }

    public function test_autogenerate_delegates_composition_and_destination(): void
    {
        $src = (string) file_get_contents(
            __DIR__ . '/../../application/libraries/Timetable_service.php');
        $i = strpos($src, 'public function autoGenerate(');
        $e = strpos($src, "\n    private function _composeGeneratedDays(", $i);
        $body = substr($src, $i, $e - $i);

        $this->assertStringContainsString('$this->_composeGeneratedDays(', $body);
        $this->assertStringContainsString('$this->_liveBatchOpsFrom(', $body);
        $this->assertStringNotContainsString("'generatedByName' =>", $body,
            'the live document must be assembled in the adapter, not back inline');
    }
}
