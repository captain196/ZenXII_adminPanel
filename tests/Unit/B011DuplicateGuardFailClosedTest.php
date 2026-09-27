<?php

use PHPUnit\Framework\TestCase;

if (!defined('BASEPATH')) define('BASEPATH', __DIR__);
if (!defined('APPPATH'))  define('APPPATH', __DIR__ . '/../../application/');
if (!function_exists('log_message')) { function log_message($l, $m) {} }

require_once __DIR__ . '/../../application/libraries/Fee_firestore_txn.php';

/**
 * B-011 — THE DUPLICATE GUARD MUST FAIL CLOSED.
 *
 * ── THE DEFECT ────────────────────────────────────────────────────────────
 * Demand generation deduplicates against the student's existing demands. The
 * reader it used, `demandsForStudent()`, ended in
 * `catch (\Exception $_) { return []; }` — so a Firestore read failure was
 * indistinguishable from "this student has no demands yet".
 *
 * That would be survivable if demand ids were deterministic: re-writing a
 * charge would land on the same document. **They are not.** The monthly
 * generator mints `bin2hex(random_bytes(10))` via `newDemandId()`, so a
 * regenerated charge takes a brand-new document id and CANNOT collide with the
 * demand it duplicates. There is no structural protection at all — the
 * application-level guard is the only thing between a transient error and a
 * family being billed twice.
 *
 * Live evidence of exactly this shape: STU0032 held 26 duplicate pairs,
 * ₹11,800 of phantom debt.
 *
 * ── WHAT IS PINNED HERE ───────────────────────────────────────────────────
 * The fix is a fail-CLOSED reader used only by the guard. These tests drive the
 * real `Fee_firestore_txn` against a Firestore double that fails the way
 * production fails, and assert on behaviour — an exception raised, an empty set
 * NOT returned — rather than on source text.
 */
class B011DuplicateGuardFailClosedTest extends TestCase
{
    private const SCHOOL  = 'SCH_TESTTENANT1';
    private const SESSION = '2026-27';

    /** Build a real Fee_firestore_txn bound to a scriptable `fs` double. */
    private function txn($fs): Fee_firestore_txn
    {
        $t = (new ReflectionClass('Fee_firestore_txn'))->newInstanceWithoutConstructor();
        (function () use ($fs) {
            $this->ready    = true;
            $this->fs       = $fs;
            $this->session  = '2026-27';
            $this->schoolId = 'SCH_TESTTENANT1';
        })->call($t);
        return $t;
    }

    /** A working store returning one demand. */
    private function workingFs()
    {
        return new class {
            public array $conds = [];
            public function schoolWhere(string $col, array $conds): array
            {
                $this->conds = $conds;
                return [['id' => 'DEM_X', 'data' => [
                    'demandId' => 'DEM_X', 'studentId' => 'STU9001',
                    'feeHead' => 'Tuition Fee', 'periodKey' => '2026-04',
                    'grossAmount' => 600, 'discountAmount' => 0, 'netAmount' => 600,
                ]]];
            }
        };
    }

    /** A store that fails the way Firestore actually fails. */
    private function failingFs(string $type = 'exception')
    {
        return new class($type) {
            private string $type;
            public function __construct(string $t) { $this->type = $t; }
            public function schoolWhere(string $col, array $conds): array
            {
                if ($this->type === 'error') throw new \Error('index CREATING');
                throw new \Exception('DEADLINE_EXCEEDED');
            }
        };
    }

    // ══ THE HEADLINE — a read failure must not read as "no demands" ════════

    public function test_a_read_failure_throws_instead_of_returning_an_empty_set(): void
    {
        $t = $this->txn($this->failingFs());

        $this->expectException(\RuntimeException::class);
        $t->demandsForStudentStrict('STU9001');
    }

    /**
     * The distinction that matters: an EMPTY result and a FAILED result must
     * not look alike. If they do, the guard generates blind.
     */
    public function test_an_empty_set_and_a_failed_read_are_distinguishable(): void
    {
        $emptyFs = new class {
            public function schoolWhere(string $col, array $conds): array { return []; }
        };

        // A genuinely empty student: returns [], no exception.
        $this->assertSame([], $this->txn($emptyFs)->demandsForStudentStrict('STU9001'),
            'a student with no demands must return an empty set, not throw');

        // A failed read: throws.
        $threw = false;
        try { $this->txn($this->failingFs())->demandsForStudentStrict('STU9001'); }
        catch (\RuntimeException $e) { $threw = true; }
        $this->assertTrue($threw, 'a failed read must be distinguishable from an empty one');
    }

    /** Throwable, not just Exception — an Error must not slip through. */
    public function test_a_php_error_is_also_caught_and_reraised(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->txn($this->failingFs('error'))->demandsForStudentStrict('STU9001');
    }

    /** The failure message must name the student, or triage is guesswork. */
    public function test_the_failure_identifies_the_student(): void
    {
        try {
            $this->txn($this->failingFs())->demandsForStudentStrict('STU9001');
            $this->fail('expected a RuntimeException');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('STU9001', $e->getMessage());
        }
    }

    // ══ THE HAPPY PATH IS UNCHANGED ════════════════════════════════════════

    public function test_a_successful_read_returns_demands_keyed_by_document_id(): void
    {
        $rows = $this->txn($this->workingFs())->demandsForStudentStrict('STU9001');
        $this->assertArrayHasKey('DEM_X', $rows);
        $this->assertSame('Tuition Fee', $rows['DEM_X']['feeHead']);
    }

    /** Tenant and session scoping must be identical to the lenient reader. */
    public function test_the_query_is_tenant_and_session_scoped(): void
    {
        $fs = $this->workingFs();
        $this->txn($fs)->demandsForStudentStrict('STU9001');

        $seen = [];
        foreach ($fs->conds as $c) $seen[(string) $c[0]] = (string) $c[2];

        $this->assertSame(self::SESSION, $seen['session'] ?? null,
            'session scoping must not be lost — cross-session dedup would be wrong');
        $this->assertSame('STU9001', $seen['studentId'] ?? null);
        // Tenant scoping comes from schoolWhere() itself, which is why the guard
        // must use it rather than a raw query.
    }

    /** An empty student id is a programming error, not an empty result. */
    public function test_an_empty_student_id_is_refused(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->txn($this->workingFs())->demandsForStudentStrict('');
    }

    /** An unready transaction must refuse rather than report "no demands". */
    public function test_an_unready_transaction_refuses(): void
    {
        $t = (new ReflectionClass('Fee_firestore_txn'))->newInstanceWithoutConstructor();
        (function () { $this->ready = false; })->call($t);

        $this->expectException(\RuntimeException::class);
        $t->demandsForStudentStrict('STU9001');
    }

    // ══ THE LENIENT READER IS DELIBERATELY UNCHANGED ═══════════════════════

    /**
     * Ten read-only callers legitimately prefer degradation to an exception.
     * Changing them would be a far larger blast radius than the defect.
     */
    public function test_the_lenient_reader_still_degrades_for_its_read_only_callers(): void
    {
        $this->assertSame([], $this->txn($this->failingFs())->demandsForStudent('STU9001'),
            'demandsForStudent() must keep its documented degrade-to-empty behaviour');
    }

    // ══ WHY THE GUARD IS LOAD-BEARING AT ALL ═══════════════════════════════

    /**
     * Demand ids on the monthly path are RANDOM, so create-if-not-exists offers
     * zero duplicate protection. This pins the fact that makes the fail-closed
     * guard necessary — if ids ever become deterministic, this test should be
     * revisited deliberately rather than silently.
     */
    public function test_generated_demand_ids_are_random_which_is_why_the_guard_matters(): void
    {
        $t = $this->txn($this->workingFs());
        $a = $t->newDemandId();
        $b = $t->newDemandId();

        $this->assertNotSame($a, $b, 'ids are random — two calls never agree');
        $this->assertMatchesRegularExpression('/^[0-9a-f]{20}$/', $a);
        $this->assertStringStartsNotWith('DEM_', $a,
            'the deterministic DEM_ scheme is NOT what the monthly generator emits');
    }
}
