<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

if (!defined('BASEPATH')) define('BASEPATH', __DIR__ . '/../../system/');
if (!defined('APPPATH'))  define('APPPATH',  __DIR__ . '/../../application/');
if (!function_exists('log_message')) { function log_message($l, $m) {} }
require_once __DIR__ . '/../../application/libraries/Timetable_service.php';

/**
 * PHASE 6 STEP 1 — compute/write separation, proven behaviourally.
 *
 * The extraction moved two blocks out of autoGenerate():
 *
 *     _placeGrid()        the greedy placement — COMPUTE, no I/O
 *     _commitLiveBatch()  the chunked live commit — WRITE
 *
 * Everything else stayed where it was. The point of this step is that ONE
 * placement algorithm can later serve both the live path and the candidate
 * path, without a second copy drifting out of step with the first.
 *
 * ── WHY THESE TESTS LOOK LIKE THIS ───────────────────────────────────────
 *
 * A refactor is only safe if the OUTPUT is unchanged, so the compute tests are
 * characterisation tests: they drive the real method through reflection with
 * fixed inputs and assert the exact placement produced. The algorithm is
 * deterministic — greedy, no randomness, candidates scored dayCount*100+period
 * — so exact assertions are legitimate and will catch any "improvement" made
 * during extraction, which is precisely what must not happen here.
 *
 * ── WHAT THIS STEP DOES NOT DO ───────────────────────────────────────────
 *
 * D24 is NOT fixed. _commitLiveBatch still commits chunks sequentially, so a
 * failure at chunk 3 of 7 still leaves the school partly regenerated, and the
 * message still names a rollback that does not exist (D22). Both are preserved
 * deliberately — changing them here would make this something other than a
 * refactor. They are pinned below so the next phase gets a failing test rather
 * than a surprise.
 */
final class GeneratorComputeWriteSeparationTest extends TestCase
{
    private function svc(): Timetable_service
    {
        return (new ReflectionClass('Timetable_service'))->newInstanceWithoutConstructor();
    }

    private function call(string $method, array $args)
    {
        $m = new ReflectionMethod('Timetable_service', $method);
        $m->setAccessible(true);
        return $m->invokeArgs($this->svc(), $args);
    }

    private function sections(): array
    {
        return [
            ['className' => 'Class 8', 'section' => 'Section A', 'key' => 'Class 8/Section A'],
        ];
    }

    private function assignments(): array
    {
        return ['Class 8/Section A' => [
            ['subject' => 'Mathematics', 'code' => 'MATH', 'teacherId' => 'STA001',
             'teacherName' => 'R Rao', 'periodsPerWeek' => 3],
            ['subject' => 'Art', 'code' => 'ART', 'teacherId' => 'STA002',
             'teacherName' => 'P Singh', 'periodsPerWeek' => 2],
        ]];
    }

    // ── COMPUTE: characterisation ────────────────────────────────────────

    /**
     * The algorithm is deterministic, so the exact grid is assertable. If a
     * future edit "improves" the heuristic, this fails — which is the intent.
     */
    public function test_placement_is_deterministic_and_unchanged(): void
    {
        $days = ['Monday', 'Tuesday', 'Wednesday'];

        $a = $this->call('_placeGrid', [$this->sections(), $this->assignments(), $days, 2]);
        $b = $this->call('_placeGrid', [$this->sections(), $this->assignments(), $days, 2]);

        $this->assertSame($a, $b,
            'placement must be deterministic — the generator has no randomness, and '
            . 'multiple candidates must never come from a random seed');

        $sk = 'Class 8/Section A';
        $this->assertArrayHasKey($sk, $a);
        foreach ($days as $d) {
            $this->assertArrayHasKey($d, $a[$sk], "day {$d} missing from the grid");
            $this->assertCount(2, $a[$sk][$d], 'every day must be sized to periodsPerDay');
        }

        // Hard subjects are placed first, spread across days before doubling up:
        // Mathematics (3/wk) takes period 0 of each day, Art (2/wk) follows.
        $this->assertSame('Mathematics', $a[$sk]['Monday'][0]['subject']);
        $this->assertSame('Mathematics', $a[$sk]['Tuesday'][0]['subject']);
        $this->assertSame('Mathematics', $a[$sk]['Wednesday'][0]['subject']);
        $this->assertSame('Art', $a[$sk]['Monday'][1]['subject']);
        $this->assertSame('Art', $a[$sk]['Tuesday'][1]['subject']);
        $this->assertNull($a[$sk]['Wednesday'][1],
            'an unfilled cell must stay null in the grid — it becomes an empty period '
            . 'later, and that is what the unallocated counter measures');
    }

    /** The placed cell must carry exactly the fields the writer consumes. */
    public function test_a_placed_cell_carries_the_expected_shape(): void
    {
        $grid = $this->call('_placeGrid',
            [$this->sections(), $this->assignments(), ['Monday'], 2]);

        $cell = $grid['Class 8/Section A']['Monday'][0];
        $this->assertSame(
            ['subject', 'teacherId', 'teacherName', 'type'], array_keys($cell),
            'the cell shape feeds the document builder; changing it here silently '
            . 'changes what gets written');
        $this->assertSame('class', $cell['type']);
        $this->assertSame('STA001', $cell['teacherId']);
    }

    /** The teacher double-booking constraint must survive the extraction. */
    public function test_a_teacher_is_never_placed_twice_in_one_period(): void
    {
        $sections = [
            ['className' => 'Class 8', 'section' => 'Section A', 'key' => 'Class 8/Section A'],
            ['className' => 'Class 9', 'section' => 'Section A', 'key' => 'Class 9/Section A'],
        ];
        // The SAME teacher owes periods in both sections.
        $assign = [
            'Class 8/Section A' => [['subject' => 'Mathematics', 'code' => 'M',
                'teacherId' => 'STA001', 'teacherName' => 'R Rao', 'periodsPerWeek' => 2]],
            'Class 9/Section A' => [['subject' => 'Mathematics', 'code' => 'M',
                'teacherId' => 'STA001', 'teacherName' => 'R Rao', 'periodsPerWeek' => 2]],
        ];

        $grid = $this->call('_placeGrid', [$sections, $assign, ['Monday', 'Tuesday'], 2]);

        $seen = [];
        foreach ($grid as $sk => $days) {
            foreach ($days as $day => $periods) {
                foreach ($periods as $p => $cell) {
                    if ($cell === null) continue;
                    $slot = $cell['teacherId'] . '|' . $day . '|' . $p;
                    $this->assertArrayNotHasKey($slot, $seen,
                        "teacher {$cell['teacherId']} was placed twice in {$day} period {$p} "
                        . '— the double-booking constraint did not survive the extraction');
                    $seen[$slot] = $sk;
                }
            }
        }
        $this->assertNotEmpty($seen, 'nothing was placed at all');
    }

    /**
     * The hard-subject-first ordering, isolated.
     *
     * The earlier fixture could not see this rule: Mathematics had BOTH higher
     * priority and more periods per week, so dropping the hard-first comparison
     * produced an identical grid and the mutant survived. Here the two signals
     * DISAGREE — a hard subject with FEWER periods than a soft one — so only the
     * priority rule can explain the outcome.
     */
    public function test_hard_subjects_are_placed_before_softer_ones(): void
    {
        $assign = ['Class 8/Section A' => [
            ['subject' => 'Art', 'code' => 'ART', 'teacherId' => 'STA002',
             'teacherName' => 'P Singh', 'periodsPerWeek' => 3],
            ['subject' => 'Mathematics', 'code' => 'MATH', 'teacherId' => 'STA001',
             'teacherName' => 'R Rao', 'periodsPerWeek' => 1],
        ]];

        $grid = $this->call('_placeGrid', [$this->sections(), $assign, ['Monday'], 3]);

        $this->assertSame('Mathematics', $grid['Class 8/Section A']['Monday'][0]['subject'],
            'Mathematics is a hard subject and must take the first slot even though Art '
            . 'has more periods per week. Without the hard-first comparison the busier '
            . 'subject would win, which is a different algorithm.');
    }

    /** A section with no assignments must be skipped, not filled with blanks. */
    public function test_a_section_without_assignments_is_skipped(): void
    {
        $grid = $this->call('_placeGrid', [$this->sections(), [], ['Monday'], 2]);

        $this->assertSame([], $grid,
            'a section with nothing to place must not appear in the grid at all. If it '
            . 'did, the builder would emit a full day of empty periods for it — the '
            . 'ACAD-D25 blank-timetable shape.');
    }

    /** Compute must remain free of persistence, or the boundary is meaningless. */
    public function test_the_compute_method_performs_no_io(): void
    {
        $src = (string) file_get_contents(
            __DIR__ . '/../../application/libraries/Timetable_service.php');
        $i = strpos($src, 'private function _placeGrid(');
        $this->assertNotFalse($i, '_placeGrid is gone');
        $e = strpos($src, "\n    private function ", $i + 10);
        $body = substr($src, $i, $e - $i);
        $body = (string) preg_replace('~^\s*//.*$~m', '', $body);

        // $this->firebase is the property the whole class writes through, so
        // omitting it left the boundary check trivially bypassable.
        foreach (['firestore', 'commitBatch', '$this->fs', '$this->firebase', '_audit'] as $io) {
            $this->assertStringNotContainsString($io, $body,
                "_placeGrid touches {$io} — compute must not know where the result is "
                . 'stored, or the candidate path cannot reuse it');
        }
    }

    // ── WRITE: the live adapter, behaviour preserved ─────────────────────

    /** Chunk size, ordering and stop-on-failure must be exactly as before. */
    public function test_the_live_adapter_commits_in_chunks_of_450_in_order(): void
    {
        $fb = new class {
            public array $chunkSizes = [];
            public function firestoreCommitBatch($chunk) {
                $this->chunkSizes[] = count($chunk);
                return true;
            }
        };

        $svc = $this->svc();
        $r = new ReflectionProperty('Timetable_service', 'firebase');
        $r->setAccessible(true);
        $r->setValue($svc, $fb);

        $m = new ReflectionMethod('Timetable_service', '_commitLiveBatch');
        $m->setAccessible(true);
        $m->invokeArgs($svc, [array_fill(0, 1000, ['op' => 'set']), 'GEN_X']);

        $this->assertSame([450, 450, 100], $fb->chunkSizes,
            'chunk size and ordering are part of the preserved live behaviour');
    }

    /**
     * ACAD-D24 pinned, NOT fixed. A mid-run failure still stops after the
     * earlier chunks have already committed. This test exists so the next phase
     * gets a failing test rather than a surprise when it changes.
     */
    public function test_a_failed_chunk_still_stops_after_earlier_chunks_committed(): void
    {
        $fb = new class {
            public int $calls = 0;
            public array $committed = [];
            public function firestoreCommitBatch($chunk) {
                $this->calls++;
                if ($this->calls === 2) return false;      // chunk 2 of 3 fails
                $this->committed[] = $this->calls;
                return true;
            }
        };

        $svc = $this->svc();
        $r = new ReflectionProperty('Timetable_service', 'firebase');
        $r->setAccessible(true);
        $r->setValue($svc, $fb);

        $m = new ReflectionMethod('Timetable_service', '_commitLiveBatch');
        $m->setAccessible(true);

        try {
            $m->invokeArgs($svc, [array_fill(0, 1000, ['op' => 'set']), 'GEN_X']);
            $this->fail('a refused chunk must stop the run');
        } catch (Service_exception $e) {
            $this->assertMatchesRegularExpression('~chunk 2 of 3~', $e->getMessage(),
                'the operator message must still name which chunk failed');
            $this->assertMatchesRegularExpression('~groups of changes were already saved~', $e->getMessage());
        }

        $this->assertSame([1], $fb->committed,
            'ACAD-D24 is intentionally PRESERVED by this refactor: chunk 1 is already '
            . 'committed and stays committed, leaving the school partly regenerated. '
            . 'If this assertion ever fails because generation became atomic, delete '
            . 'this test — the architecture decision it records was taken.');
        $this->assertSame(2, $fb->calls, 'no chunk after the failure may be attempted');
    }

    /** A throwing commit must be treated as a failure, not swallowed. */
    public function test_a_throwing_commit_is_treated_as_failure(): void
    {
        $fb = new class {
            public function firestoreCommitBatch($chunk) { throw new \RuntimeException('boom'); }
        };

        $svc = $this->svc();
        $r = new ReflectionProperty('Timetable_service', 'firebase');
        $r->setAccessible(true);
        $r->setValue($svc, $fb);

        $m = new ReflectionMethod('Timetable_service', '_commitLiveBatch');
        $m->setAccessible(true);

        $this->expectException(Service_exception::class);
        $m->invokeArgs($svc, [[['op' => 'set']], 'GEN_X']);
    }

    // ── one algorithm only ───────────────────────────────────────────────

    /**
     * The whole justification for this step. If a second placement loop appears,
     * the two will drift and constraints will be enforced in one but not the
     * other.
     */
    public function test_there_is_exactly_one_placement_algorithm(): void
    {
        $src = (string) file_get_contents(
            __DIR__ . '/../../application/libraries/Timetable_service.php');
        $code = (string) preg_replace('~/\*.*?\*/~s', '', $src);
        $code = (string) preg_replace('~^\s*//.*$~m', '', $code);

        $this->assertSame(1, preg_match_all('~private function _placeGrid\(~', $code),
            'more than one placement method exists');

        // The distinctive scoring expression of the one algorithm.
        $this->assertSame(1, preg_match_all('~\$dayCount \* 100 \+ \$p~', $code),
            'the candidate-scoring expression appears more than once — a second '
            . 'placement implementation has been introduced, which is exactly what '
            . 'this phase exists to prevent');
    }

    /** autoGenerate must delegate, not keep its own copy. */
    public function test_autogenerate_delegates_to_the_extracted_methods(): void
    {
        $src = (string) file_get_contents(
            __DIR__ . '/../../application/libraries/Timetable_service.php');
        $i = strpos($src, 'public function autoGenerate(');
        $this->assertNotFalse($i);
        $e = strpos($src, "\n    private function _placeGrid(", $i);
        $body = substr($src, $i, $e - $i);

        $this->assertStringContainsString('$this->_placeGrid(', $body,
            'autoGenerate must call the extracted compute');
        $this->assertStringContainsString('$this->_commitLiveBatch(', $body,
            'autoGenerate must call the extracted write adapter');
        $this->assertStringNotContainsString('array_chunk($batchOps', $body,
            'the chunking must live in the write adapter, not back inline');
    }

    // ── the guards this refactor must not weaken ─────────────────────────

    public function test_the_d23_d25_d27_guards_survive_the_extraction(): void
    {
        $src = (string) file_get_contents(
            __DIR__ . '/../../application/libraries/Timetable_service.php');
        $i = strpos($src, 'public function autoGenerate(');
        $e = strpos($src, "\n    private function _placeGrid(", $i);
        $body = substr($src, $i, $e - $i);
        $code = (string) preg_replace('~^\s*//.*$~m', '', $body);

        // D23 — manual-edit preservation, fail-loud preload, saturation probe.
        $this->assertStringContainsString('firestoreQueryOrFail(self::COLLECTION_TT', $code);
        $this->assertStringContainsString('self::GENERATE_PRELOAD_LIMIT + 1', $code);
        $this->assertMatchesRegularExpression(
            '~if \(\$manuallyEdited && !\$forceOverwrite\)~', $code,
            'D23: the preserve branch must still be inside autoGenerate');

        // D25 — refuse to generate from nothing.
        $this->assertMatchesRegularExpression('~if \(\$totalAssignments === 0\) \{~', $code,
            'D25: the empty-assignment guard must survive');

        // D27 — the counters the confirmation dialog reads.
        foreach (['manual_skipped', 'manual_overwritten'] as $counter) {
            $this->assertStringContainsString($counter, $code,
                "D27: {$counter} feeds the operator warning before a destructive run");
        }
    }

    /** The guards must still run BEFORE anything is committed. */
    public function test_the_guards_still_precede_the_write(): void
    {
        $src = (string) file_get_contents(
            __DIR__ . '/../../application/libraries/Timetable_service.php');
        $i = strpos($src, 'public function autoGenerate(');
        $e = strpos($src, "\n    private function _placeGrid(", $i);
        $body = substr($src, $i, $e - $i);

        // D25 now lives inside _loadGenerationInputs() so the candidate
        // destination shares it. Reachability is what matters, not inlineness:
        // autoGenerate must LOAD (and therefore guard) before it commits.
        $load  = strpos($body, '$this->_loadGenerationInputs(');
        $d23   = strpos($body, 'self::GENERATE_PRELOAD_LIMIT + 1');
        $write = strpos($body, '$this->_commitLiveBatch(');

        foreach (['input load' => $load, 'D23 preload' => $d23, 'write' => $write] as $k => $v) {
            $this->assertNotFalse($v, "{$k} is gone");
        }
        $this->assertLessThan($write, $load,
            'inputs must be loaded — and therefore guarded — before anything is committed');
        $this->assertLessThan($write, $d23,
            'the D23 preload must still precede the commit');

        // And the D25 guard must genuinely be inside the method that load calls,
        // not merely somewhere in the file.
        $src = (string) file_get_contents(
            __DIR__ . '/../../application/libraries/Timetable_service.php');
        $li = strpos($src, 'function _loadGenerationInputs(');
        $this->assertNotFalse($li, '_loadGenerationInputs is gone');
        $le = strpos($src, "\n    private function ", $li + 10);
        $loadBody = substr($src, $li, $le - $li);

        $this->assertStringContainsString('if ($totalAssignments === 0) {', $loadBody,
            'ACAD-D25 must live inside the shared input loader, so the candidate '
            . 'destination cannot generate from an unreadable assignment set either');
    }
}
