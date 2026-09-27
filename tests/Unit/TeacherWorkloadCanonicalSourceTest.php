<?php

use PHPUnit\Framework\TestCase;

/**
 * Teacher Workload must answer from the CANONICAL timetable, never the legacy
 * `timetables` projection.
 *
 * Provenance: UAT 2026-09-12. A timetable was published, correct, and visible
 * on the Master Timetable screen with Sunita Raghav carrying 18 periods — and
 * Teacher Workload did not list her at all. It was reading `timetables`, whose
 * only writer is projectPublishedVersion(apply:true): that runs when a version
 * is in force on its publish day, or from a CLI-only cron that nothing
 * schedules while the canonical switchover sits behind its owner gates. So the
 * screen reported a stale copy as fact.
 *
 * Operator instruction: "when working for the teacher workload dont use the
 * legacy old timetable, it should worked according to the new canonical
 * timetable."
 *
 * SCOPE GUARD: this is an admin-panel READ only. The Teacher and Parent apps
 * stay on the projection, and nothing here writes to `timetables`, so neither
 * switchover gate (A rollover, B TT-D1 rules) is touched. The test asserts
 * that boundary too, so a later change cannot quietly widen it.
 */
final class TeacherWorkloadCanonicalSourceTest extends TestCase
{
    private static string $view = '';
    private static string $ctrl = '';
    private static string $wl   = '';

    public static function setUpBeforeClass(): void
    {
        $root = __DIR__ . '/../..';
        self::$view = (string) file_get_contents($root . '/application/views/academic/index.php');
        self::$ctrl = (string) file_get_contents($root . '/application/controllers/Academic.php');

        // Isolate the AC.wl (Teacher Workload) block: the legacy idiom
        // `AC.tt.timetables[label]` appears seven times across this view, so a
        // whole-file assertion would pass or fail for unrelated features.
        $start = strpos(self::$view, 'AC.wl = {');
        self::assertNotFalse($start, 'the AC.wl block is gone');
        $end = strlen(self::$view);
        if (preg_match('/^AC\.[a-z]+ *= *\{/m', self::$view, $m, PREG_OFFSET_CAPTURE, $start + 10)) {
            $end = $m[0][1];
        }
        self::$wl = substr(self::$view, $start, $end - $start);
    }

    /** Strip comments so prose about the legacy source is not mistaken for code. */
    private function code(string $s): string
    {
        $s = (string) preg_replace('#/\*.*?\*/#s', '', $s);
        return (string) preg_replace('#//.*#', '', $s);
    }

    /**
     * The endpoint's OWN body, bounded by the next method.
     *
     * A fixed-size window overran into save_period(), where 'timetables' is a
     * legitimate reference — the assertion then failed for a line belonging to
     * another method. Bounding beats widening.
     */
    private function endpointBody(): string
    {
        $i = strpos(self::$ctrl, 'function get_canonical_timetable');
        $this->assertNotFalse($i, 'the canonical endpoint is gone');
        $next = strpos(self::$ctrl, '    public function ', $i + 10);
        return substr(self::$ctrl, $i, ($next !== false ? $next - $i : 6000));
    }

    public function test_workload_requests_the_canonical_endpoint(): void
    {
        $this->assertStringContainsString(
            "post('academic/get_canonical_timetable'", $this->code(self::$wl),
            'Teacher Workload no longer asks for the canonical timetable');
    }

    /**
     * The defect itself: any live read of the legacy grid inside this block
     * puts the stale mirror back behind the numbers.
     */
    public function test_workload_reads_no_legacy_timetable_state(): void
    {
        $code = $this->code(self::$wl);

        $this->assertStringNotContainsString('AC.tt', $code,
            'Teacher Workload reads AC.tt, the legacy projection state — the numbers '
            . 'would silently follow whichever source another tab loaded first');

        $this->assertStringNotContainsString("academic/get_master_timetable", $code,
            'Teacher Workload still calls the legacy master-timetable endpoint');
    }

    /** It must hold its own copy, so another tab's load cannot redirect it. */
    public function test_workload_keeps_its_own_source(): void
    {
        $code = $this->code(self::$wl);
        $this->assertStringContainsString('AC.wl._src', $code,
            'Teacher Workload has no source of its own');
        $this->assertStringContainsString('_sourceNote', $code,
            'nothing tells the operator which timetable produced these numbers');
    }

    /**
     * The endpoint must resolve through the shared resolver, not re-derive
     * "which timetable applies" — that question has exactly one authority.
     */
    public function test_the_endpoint_uses_the_canonical_resolver(): void
    {
        $body = $this->endpointBody();

        $this->assertStringContainsString('resolveForDate(', $body,
            'the endpoint does not use the shared resolver');
        $this->assertStringContainsString('listCandidateDays(', $body,
            'the endpoint does not read the canonical version days');
        // Forbid READING the legacy collection — not the word itself. The
        // response key is also `timetables` (it is the shape consumers expect),
        // so a bare substring check failed on the endpoint's own output. Match
        // collection ACCESS instead.
        foreach ([
            "schoolWhere('timetables'",
            "firestoreQuery('timetables'",
            "firestoreQueryOrFail('timetables'",
            'COLLECTION_TT',
            'getMasterTimetable(',
        ] as $legacyRead) {
            $this->assertStringNotContainsString($legacyRead, $body,
                "the endpoint reads the legacy projection via {$legacyRead}");
        }
    }

    /**
     * "Could not tell" must never render as "nobody teaches anything". An
     * unresolved read returning an empty grid would put every teacher at zero
     * and look like a genuinely quiet school.
     */
    public function test_an_unresolved_date_is_not_reported_as_an_empty_school(): void
    {
        $body = $this->endpointBody();

        $this->assertStringContainsString('Timetable_plan_service::RESOLVED', $body,
            'the endpoint does not distinguish a resolved timetable from any other outcome');
        $this->assertStringContainsString('could not be read', $body,
            'a failed read does not report itself as a failure');

        // And the screen must say so rather than drawing a silent zero table.
        $note = $this->code(self::$wl);
        $this->assertStringContainsString('could not be determined', $note,
            'the screen does not warn when the timetable for that date is unknown');
    }

    /**
     * SCOPE: admin read only. Widening this into the projection or the apps is
     * an owner decision behind gates A and B, not a side effect of a workload fix.
     */
    public function test_the_change_stays_an_admin_read_and_writes_nothing(): void
    {
        $body = $this->endpointBody();

        foreach (['projectPublishedVersion', 'setDocument', 'commitBatch', '->set(', 'publishCandidate'] as $writer) {
            $this->assertStringNotContainsString($writer, $body,
                "the workload endpoint calls {$writer} — it must read only");
        }
    }
}
