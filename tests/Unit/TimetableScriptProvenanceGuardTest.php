<?php

use PHPUnit\Framework\TestCase;

/**
 * Every one-off script that writes `timetables` must refuse the documents the
 * canonical lifecycle owns.
 *
 * ── WHY ─────────────────────────────────────────────────────────────────
 *
 * `timetables` is a PROJECTION of whichever timetableVersion is published and
 * in force. Documents the projection writes carry `projectedFromVersion`.
 *
 * A script that edits one changes what teachers and parents see with no version
 * recording it, and the next projection overwrites the change anyway. A script
 * that DELETES one is worse: the canonical version still says that timetable
 * applies, so the school believes the class is covered while the apps show
 * nothing — and nothing repairs it until a projection runs, which nothing in
 * this repository schedules.
 *
 * Timetables_reconcile.php and Rollover_projection_guard.php already refused on
 * this stamp. A repository-wide writer audit on 2026-09-12 found the Node
 * scripts did not, including a DELETE path (clear_stale_timetables.js) and a
 * full-replace `set(…, {merge:false})` (migrate_timetables_to_firestore.js).
 */
final class TimetableScriptProvenanceGuardTest extends TestCase
{
    private const SCRIPTS = __DIR__ . '/../../scripts/';

    /** Scripts that write the legacy collection and must consult the guard. */
    private const WRITERS = [
        'clear_stale_timetables.js'         => 'deletes day-documents',
        'fix_maths_to_mathematics.js'       => 'rewrites period subjects in place',
        'migrate_timetables_to_firestore.js'=> 'full-replace set() from RTDB',
        'migrate_academic_planner_phase0.js'=> 'backfills schoolId/version',
    ];

    private function read(string $file): string
    {
        $p = self::SCRIPTS . $file;
        $this->assertFileExists($p, "{$file} is gone — if it was deliberately "
            . 'removed, drop it from this test with a note saying why');
        return (string) file_get_contents($p);
    }

    /** The shared guard exists and says what it refuses. */
    public function test_the_shared_guard_exists(): void
    {
        $g = $this->read('lib/projection_guard.js');

        $this->assertStringContainsString('projectedFromVersion', $g,
            'the guard does not key off the projection stamp, so it cannot tell '
            . 'an owned document from a pre-canonical one');
        foreach (['isProjected', 'partition', 'report'] as $fn) {
            $this->assertStringContainsString($fn, $g, "the guard lost {$fn}()");
        }
    }

    /**
     * …and it ANSWERS correctly.
     *
     * Asserting that the scripts call the guard says nothing about what it
     * returns. A guard that loaded cleanly, was called everywhere, and answered
     * false for everything would satisfy every source-level check in this file
     * while protecting nothing — the worst available failure for a safety
     * device, because it looks exactly like success.
     *
     * So the real module is executed. Node is already a dependency of these
     * scripts; if it is not on PATH the check is skipped rather than silently
     * counted as a pass.
     */
    public function test_the_guard_behaves_correctly_when_actually_run(): void
    {
        $self = self::SCRIPTS . 'lib/projection_guard.selftest.js';
        $this->assertFileExists($self, 'the guard self-test is gone');

        $out = []; $rc = 1;
        @exec('node ' . escapeshellarg($self) . ' 2>&1', $out, $rc);

        $joined = implode("\n", $out);
        if ($rc !== 0 && stripos($joined, 'not recognized') !== false) {
            $this->markTestSkipped('node is not on PATH');
        }

        $this->assertSame(0, $rc,
            "the projection guard does not behave correctly:\n" . $joined);
    }

    /** THE FIX: every writer consults it. */
    public function test_every_script_writer_consults_the_guard(): void
    {
        foreach (self::WRITERS as $file => $what) {
            $src = $this->read($file);

            $this->assertStringContainsString('projection_guard', $src,
                "{$file} ({$what}) does not load the projection guard, so it can "
                . 'change or remove what a published timetable put in front of '
                . 'teachers and parents');
            $this->assertMatchesRegularExpression('#guard\.(isProjected|partition)\(#', $src,
                "{$file} loads the guard but never asks it anything");
        }
    }

    /**
     * The guard must run BEFORE the write, not beside it.
     *
     * For the delete path this is the whole point: filtering after the delete
     * list is built still invites a person to delete by hand what the report
     * listed as stale.
     */
    public function test_the_delete_path_filters_before_it_decides_what_is_stale(): void
    {
        $src = $this->read('clear_stale_timetables.js');

        $partition = strpos($src, 'guard.partition(');
        $stale     = strpos($src, 'staleSections.push');
        $delete    = strpos($src, '.ref.delete()');

        $this->assertNotFalse($partition, 'the delete path no longer partitions');
        $this->assertNotFalse($stale);
        $this->assertNotFalse($delete);

        $this->assertLessThan($stale, $partition,
            'projected documents are filtered AFTER staleness is computed, so a '
            . 'section whose every document is owned by a published version is '
            . 'still reported as stale and invites a manual delete');
        $this->assertLessThan($delete, $partition,
            'the guard runs after the delete');

        // Position is not enough — the guard can be called early and its answer
        // thrown away. Follow the DATA: the map that decides what is stale must
        // be built from the guard's output, never from the raw snapshot.
        $this->assertMatchesRegularExpression('#for \(const d of deletable\)#', $src,
            'the stale map is built from the raw query result rather than from '
            . 'the documents the guard allowed, so calling the guard changes '
            . 'nothing about what gets deleted');
        $this->assertSame(1, substr_count($src, 'ttSnap.docs'),
            'ttSnap.docs is used more than once; the only permitted use is '
            . 'handing it to the guard, and a second use is a path around it');
    }

    /**
     * A file named `inspect_*` must not write.
     *
     * inspect_timetables_rtdb.js was a BYTE-IDENTICAL copy of the RTDB → Firestore
     * migration — `--apply` and all — under a name nobody would read before
     * running. A name that hides a write defeats the one habit that protects
     * people: reading what a script does first.
     */
    public function test_no_inspect_script_writes(): void
    {
        // KNOWN, UNFIXED, AND NOT OURS. Found by the same 2026-09-12 sweep:
        // inspect_reminder_full.js deletes documents in batches and wipes a
        // student's `monthFee` map. It is the identical hazard — a write behind
        // an `inspect_` name — but it belongs to the fees programme, whose work
        // is staged and must not be touched from here. Listed rather than
        // excluded silently, so it stays visible to its owner and this test
        // fails the moment someone believes it was cleared.
        $knownUnfixed = ['inspect_reminder_full.js'];

        // Firestore writes specifically. A bare '.set(' also matches
        // URLSearchParams.set() and Map.set(), which are not writes.
        $writes = ['.ref.set(', '.ref.update(', '.ref.delete(',
                   'Ref.set(', 'Ref.update(', 'Ref.delete(',
                   'batch.set(', 'batch.update(', 'batch.delete(',
                   'merge: false'];

        foreach (glob(self::SCRIPTS . 'inspect_*.js') ?: [] as $p) {
            $name = basename($p);
            // Block comments stripped: a retired script's header explains the
            // write it USED to perform, and matching raw text would read that
            // explanation as the write itself — failing on the very disarming
            // it is meant to confirm. Line comments are kept, so a write hidden
            // behind `//` is still caught.
            $src = (string) preg_replace('#/\*[\s\S]*?\*/#', '',
                (string) file_get_contents($p));

            $found = [];
            foreach ($writes as $w) {
                if (strpos($src, $w) !== false) { $found[] = $w; }
            }

            if (in_array($name, $knownUnfixed, true)) {
                $this->assertNotEmpty($found,
                    "{$name} is listed as a known unfixed writer but no longer "
                    . 'writes — remove it from $knownUnfixed');
                continue;
            }

            $this->assertSame([], $found,
                "{$name}: a script named inspect_* performs Firestore writes ("
                . implode(', ', $found) . '). A name that hides a write defeats '
                . 'the one habit that protects people: reading what a script does '
                . 'before running it. Rename it, or make it read-only');
        }
    }

    /** …and the retired trap stays retired. */
    public function test_the_mis_named_migration_duplicate_stays_disarmed(): void
    {
        $src = $this->read('inspect_timetables_rtdb.js');

        $this->assertStringContainsString('process.exit(2)', $src,
            'inspect_timetables_rtdb.js no longer refuses to run — it was a '
            . 'full-replace writer of the timetables collection disguised as an '
            . 'inspection');
        $this->assertStringNotContainsString('firebase-admin', $src,
            'it initialises the Firestore client again, so it can write again');
    }
}
