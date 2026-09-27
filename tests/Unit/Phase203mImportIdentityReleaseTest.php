<?php
namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/Phase203kStep2ImportGroupingTest.php';

/**
 * ══════════════════════════════════════════════════════════════════════════
 * Phase 20.3M — P20.3L-F1: a refused Import group must not burn Student IDs
 * ══════════════════════════════════════════════════════════════════════════
 *
 * ── THE DEFECT (found live in Phase 20.3L) ──────────────────────────────────
 * Under grouping, `Sis:6090` mints a Student ID in Pass 1, the row is buffered,
 * and Pass 2 may then refuse the whole destination group. `$__refuse()` records
 * the error and nothing else — the claim is never returned, so every refused
 * group leaves permanent gaps in a GLOBAL counter.
 *
 * ── THE IDENTITY CONTRACT THIS FILE PINS ────────────────────────────────────
 * `Id_generator::generate('STU')` claims by CREATING
 * `feeCounters/_sys_STU_claim_{N}` with create-if-not-exists (HTTP 409 on
 * conflict). That claim document — NOT the `_sys_STU` pointer — is the
 * authority; the library says so itself at `Id_generator.php:516`.
 * `releaseClaim()` deletes the claim doc and rewinds the pointer ONLY if the
 * pointer still equals that value.
 *
 * Releasing is therefore safe: allocation is CAS, so a freed number can be
 * re-claimed by exactly one future allocator, and a stale pointer only ever
 * costs extra linear probing (`_claimLinear`), never a duplicate.
 *
 * ── THE ONE DELIBERATE EXCEPTION ────────────────────────────────────────────
 * A `FAILURE` outcome means the commit result is UNKNOWN — the student writes
 * may already have reached Firestore. Releasing the claim there could later
 * hand the same Student ID to a second student while an orphan document holds
 * it, which is a DUPLICATE IDENTITY. Leaking a number is strictly safer than
 * that, so FAILURE deliberately keeps its claims. `test_IMPORT_ID_09` pins it.
 */
class Phase203mImportIdentityReleaseTest extends TestCase
{
    /** @var object|null */ private $sis;
    /** @var IdRecorder */  private $rec;
    /** @var GroupGuard */  private $guard;
    /** @var GroupFs */     private $fs;

    public static function setUpBeforeClass(): void
    {
        Phase203kStep0ImportHarnessTest::setUpBeforeClass();
    }

    protected function setUp(): void
    {
        $this->rec   = new IdRecorder();
        $this->fs    = new GroupFs($this->rec);
        $this->guard = new GroupGuard();

        $rc = new \ReflectionClass('Sis');
        $this->sis = $rc->newInstanceWithoutConstructor();
        \CI_Controller::$instance = $this->sis;

        $this->set('load',         new HarnessLoader($this->rec));
        $this->set('fs',           $this->fs);
        $this->set('firebase',     new HarnessFirebase($this->rec));
        $this->set('id_generator', new IdGenSpy($this->rec));
        $this->set('srnoSvc',      new HarnessSrno($this->rec));
        $this->set('entity_sync',  new HarnessEntitySync($this->rec));
        $this->set('feeLifecycle', new HarnessFees($this->rec));
        $this->set('session',      new HarnessSession($this->rec));
        $this->set('apaarSvc',     new HarnessApaar($this->rec));
        $this->set('studentHistorySvc', new HarnessGeneric($this->rec, 'history'));
        $this->set('capability',   new HarnessGeneric($this->rec, 'capability'));
        $this->set('seatGuard',    $this->guard);
        $this->set('school_name',  'Vikrant Public');
        $this->set('school_id',    'SCH_TEST0001');
        $this->set('parent_db_key','SCH_TEST0001');
        $this->set('session_year', '2026-27');
        $this->set('admin_id',     'SSA_TEST');

        $GLOBALS['__test_log_capture'] = [];
        $this->flag(true, 499);
    }

    protected function tearDown(): void
    {
        $this->flag(null, 0);
        unset($GLOBALS['__test_log_capture']);
    }

    private function flag(?bool $on, int $max): void
    {
        $ci = get_instance();
        if (is_object($ci)) $ci->config = ($on === null) ? null : new GroupConfig($on, $max);
    }

    private function set(string $prop, $value): void
    {
        $rc = new \ReflectionClass($this->sis);
        while ($rc && !$rc->hasProperty($prop)) $rc = $rc->getParentClass();
        if ($rc) { $p = $rc->getProperty($prop); $p->setAccessible(true); $p->setValue($this->sis, $value); }
        else     { $this->sis->{$prop} = $value; }
    }

    private function runImport(array $rows, array $opts = []): array
    {
        $opts = array_merge(['dupPolicy' => 'skip', 'importMode' => 'new'], $opts);
        $rm = new \ReflectionMethod('Sis', '_runStudentImport');
        $rm->setAccessible(true);
        return $rm->invoke($this->sis, $rows, $opts);
    }

    /**
     * ⚠ Sections here are namespaced `Z1`…`Z9`, one per test, and must NOT
     * collide with the destinations used by Phase203kStep2ImportGroupingTest.
     *
     * `MY_Controller::_sectionCapacity()` memoises on a function-level static
     * keyed by `session|class|section`, PHPUnit shares one process, and there is
     * no reset. A shared destination therefore serves whichever capacity the
     * FIRST test to touch it read — which made IMPORT-ID-06 pass alone and fail
     * in the full suite, silently testing capacity 40 instead of 2.
     */
    private function rows(string $class, string $section, int $n, int $seed = 0): array
    {
        $out = [];
        for ($i = 1; $i <= $n; $i++) {
            $k = $seed + $i;
            $out[] = [
                'Name'        => "Student {$class}{$section}{$k}",
                'Father Name' => 'Test Father',
                'Class'       => $class,
                'Section'     => $section,
                'Phone'       => sprintf('9%09d', $k),
            ];
        }
        return $out;
    }

    private function logged(string $needle): bool
    {
        foreach (($GLOBALS['__test_log_capture'] ?? []) as $l) {
            if (strpos($l['msg'], $needle) !== false) return true;
        }
        return false;
    }

    /** Every Student ID minted in this run, as integers. */
    private function minted(): array { return $this->rec->minted; }
    /** Every Student ID released in this run, as integers. */
    private function released(): array { return $this->rec->released; }

    /**
     * Assert exactly this SET of Student IDs came back, and that they came back
     * HIGHEST FIRST.
     *
     * The set is the correctness requirement — no id may be missed and none may
     * be released twice. The descending order is a separate, real property:
     * `Id_generator::releaseClaim()` rewinds the pointer only when it still
     * equals the value being freed, so freeing the top of the run first is what
     * lets the pointer walk back instead of stranding the whole block. Both are
     * asserted rather than folded into one order-sensitive comparison, so a
     * failure names which of the two rules broke.
     */
    private function assertReleasedExactly(array $expected): void
    {
        $got = $this->released();

        $a = $expected; sort($a);
        $b = $got;      sort($b);
        $this->assertSame($a, $b,
            'The set of released Student IDs must equal the set the refused group minted — '
            . 'no id missed, no id released twice.');

        $desc = $got; rsort($desc);
        $this->assertSame($desc, $got,
            'Claims must be released highest-first so releaseClaim() can rewind the pointer.');
    }

    // ── IMPORT-ID-01 ─────────────────────────────────────────────────────

    /** A committed group KEEPS every identity it minted. */
    public function test_IMPORT_ID_01_committed_group_retains_its_student_ids(): void
    {
        $this->fs->sectionMax = 40;
        $res = $this->runImport($this->rows('5th', 'Z1', 3));

        $this->assertCount(3, $this->minted(), 'Three rows mint three Student IDs.');
        $this->assertSame([], $this->released(),
            'A committed group must release NOTHING — releasing a live id would let a '
            . 'second student be given the same number.');
        $this->assertSame(3, $res['success']);
    }

    // ── IMPORT-ID-02 ─────────────────────────────────────────────────────

    /** Capacity refusal → every minted Student ID is returned. */
    public function test_IMPORT_ID_02_capacity_refusal_releases_every_student_id(): void
    {
        $this->fs->sectionMax = 2;
        $res = $this->runImport($this->rows('6th', 'Z2', 3, 100));

        $this->assertCount(3, $this->minted());
        $this->assertReleasedExactly($this->minted());
        $this->assertSame(3, $res['error']);
        $this->assertCount(0, $this->guard->calls, 'Nothing was committed.');
    }

    // ── IMPORT-ID-03 ─────────────────────────────────────────────────────

    /**
     * CAS conflict → every minted Student ID is returned.
     *
     * Safe precisely because a PRECONDITION_CONFLICT is a GUARANTEED full
     * rollback: Firestore wrote no student document, so no document can be
     * holding the number we are freeing.
     */
    public function test_IMPORT_ID_03_cas_conflict_releases_every_student_id(): void
    {
        $this->fs->sectionMax = 40;
        $this->guard->status = 'CONFLICT';
        $res = $this->runImport($this->rows('7th', 'Z3', 3, 200));

        $this->assertCount(3, $this->minted());
        $this->assertReleasedExactly($this->minted());
        $this->assertSame(3, $res['error']);
    }

    // ── IMPORT-ID-04 ─────────────────────────────────────────────────────

    /** Group over the 499 limit → every minted Student ID is returned. */
    public function test_IMPORT_ID_04_over_max_refusal_releases_every_student_id(): void
    {
        $this->flag(true, 3);
        $this->fs->sectionMax = 500;
        $res = $this->runImport($this->rows('8th', 'Z4', 4, 300));

        $this->assertCount(4, $this->minted());
        $this->assertReleasedExactly($this->minted());
        $this->assertSame(4, $res['error']);
        $this->assertCount(0, $this->guard->calls);
    }

    // ── IMPORT-ID-05 ─────────────────────────────────────────────────────

    /** Mixed run: the healthy group keeps its ids, the refused group returns its own. */
    public function test_IMPORT_ID_05_only_the_refused_group_releases(): void
    {
        $this->fs->sectionMax = 40;
        $this->guard->statusSeq = ['PLACED', 'CONFLICT'];
        $res = $this->runImport(array_merge(
            $this->rows('9th', 'Z5', 2, 400),      // committed
            $this->rows('10th', 'Z6', 3, 500)      // refused
        ));

        $minted = $this->minted();
        $this->assertCount(5, $minted);
        $kept     = array_slice($minted, 0, 2);   // group 1, in row order
        $returned = array_slice($minted, 2, 3);   // group 2

        $this->assertReleasedExactly($returned);
        foreach ($kept as $v) {
            $this->assertNotContains($v, $this->released(),
                "Student ID {$v} belongs to a COMMITTED student and must never be released.");
        }
        $this->assertSame(2, $res['success']);
        $this->assertSame(3, $res['error']);
    }

    // ── IMPORT-ID-06 ─────────────────────────────────────────────────────

    /** A failed release is reported, never silently swallowed. */
    public function test_IMPORT_ID_06_release_failure_is_explicit_not_silent(): void
    {
        $this->fs->sectionMax = 2;
        $this->rec->releaseFails = true;
        $res = $this->runImport($this->rows('11th', 'Z7', 3, 600));

        $this->assertCount(3, $this->rec->releaseAttempts,
            'Every claim must still be ATTEMPTED even when the first attempt fails.');
        $this->assertTrue($this->logged('ACC_IMPORT_ID_RELEASE_FAILED'),
            'A leaked Student ID must be greppable — a silent leak is unfindable in production.');
        $this->assertSame(3, $res['error'], 'Row reporting is unaffected by the release outcome.');
    }

    // ── IMPORT-ID-07 ─────────────────────────────────────────────────────

    /**
     * Two concurrent allocators can never receive the same Student ID.
     *
     * This executes the REAL `Id_generator` against a Firestore double whose
     * `firestoreCreate` implements genuine create-if-not-exists, which is the
     * property the whole claim design rests on. Interleaving is forced: both
     * allocators read the same pointer before either claims.
     */
    public function test_IMPORT_ID_07_concurrent_allocators_cannot_collide(): void
    {
        require_once __DIR__ . '/../../application/libraries/Id_generator.php';

        $store = new CasCounterStore();
        $ci = get_instance();
        $prev = $ci->firebase ?? null;
        $ci->firebase = $store;

        $a = new \Id_generator();
        $b = new \Id_generator();

        // Both see pointer=0 and both target candidate 1; only one can create
        // the claim document, and the loser probes upward.
        $idA = $a->generate('STU');
        $store->rewindPointer('STU');          // simulate B having read the stale pointer
        $idB = $b->generate('STU');

        $ci->firebase = $prev;

        $this->assertNotSame($idA, $idB,
            'Create-if-not-exists must make a duplicate Student ID impossible.');
        $this->assertSame(2, count(array_unique([$idA, $idB])));
        $this->assertSame(2, $store->claimCount('STU'), 'Two distinct claim documents exist.');
    }

    // ── IMPORT-ID-08 ─────────────────────────────────────────────────────

    /** With the flag OFF the fix must be completely invisible. */
    public function test_IMPORT_ID_08_flag_off_release_behaviour_is_unchanged(): void
    {
        $this->flag(false, 499);
        $this->fs->sectionMax = 40;
        $res = $this->runImport($this->rows('12th', 'Z8', 2, 700));

        $this->assertCount(0, $this->guard->calls, 'Flag OFF: no grouping.');
        $this->assertSame([], $this->released(),
            'Flag OFF: releases happen only on the pre-existing saveStudent-failure branch, '
            . 'which did not fire here. The fix must add nothing to this path.');
        $this->assertSame(2, $res['success']);
    }

    // ── IMPORT-ID-09 — the deliberate exception ──────────────────────────

    /**
     * An UNKNOWN outcome must KEEP its claims.
     *
     * `FAILURE` means the commit's result could not be determined; the student
     * documents may exist. Freeing those numbers could hand one to a second
     * student while an orphan already holds it — a duplicate identity, which is
     * strictly worse than a gap in a monotonic counter.
     */
    public function test_IMPORT_ID_09_unknown_outcome_deliberately_keeps_its_claims(): void
    {
        $this->fs->sectionMax = 40;
        $this->guard->status = 'FAILURE';
        $res = $this->runImport($this->rows('1st', 'Z9', 2, 800));

        $this->assertCount(2, $this->minted());
        $this->assertSame([], $this->released(),
            'An unknown outcome must NOT release — a possible orphan document may hold the id.');
        $this->assertTrue($this->logged('ACC_IMPORT_ID_RETAINED_UNKNOWN'),
            'The retention must be logged so the leaked numbers can be reconciled later.');
        $this->assertSame(2, $res['error']);
    }
}

// ══════════════════════════════════════════════════════════════════════════
//  Doubles
// ══════════════════════════════════════════════════════════════════════════

/** Adds Student-ID mint/release bookkeeping to the Step 0 recorder. */
class IdRecorder extends HarnessRecorder
{
    public array $minted          = [];   // ints, in mint order
    public array $released        = [];   // ints, in release order
    public array $releaseAttempts = [];
    public bool  $releaseFails    = false;
}

/** Records exactly which numeric Student IDs were claimed and returned. */
class IdGenSpy
{
    private int $i = 0;
    public function __construct(private IdRecorder $r) {}

    public function generate($prefix)
    {
        $this->r->log('id_generator.generate', [$prefix]);
        if ($this->r->idGenReturns !== '__AUTO__') return $this->r->idGenReturns;
        $n = ++$this->i;
        $this->r->minted[] = $n;
        return sprintf('STU%04d', $n);
    }

    public function releaseClaim($prefix, $n)
    {
        $this->r->log('id_generator.releaseClaim', [$prefix, $n]);
        $this->r->releaseAttempts[] = (int) $n;
        if ($this->r->releaseFails) return false;
        $this->r->released[] = (int) $n;
        return true;
    }
}

/**
 * A Firestore double with REAL create-if-not-exists semantics, so the identity
 * allocator's CAS guarantee is exercised rather than assumed.
 */
class CasCounterStore
{
    private array $docs = [];

    public function firestoreCreate($collection, $docId, $data)
    {
        $k = "{$collection}/{$docId}";
        if (isset($this->docs[$k])) return false;      // 409 — someone else has it
        $this->docs[$k] = $data;
        return true;
    }
    public function firestoreGet($collection, $docId)
    {
        return $this->docs["{$collection}/{$docId}"] ?? null;
    }
    public function firestoreSet($collection, $docId, $data, $merge = false)
    {
        $this->docs["{$collection}/{$docId}"] = $data;
        return true;
    }
    public function firestoreDelete($collection, $docId)
    {
        unset($this->docs["{$collection}/{$docId}"]);
        return true;
    }
    public function firestoreQuery($collection, $conditions = [], $orderBy = null, $dir = 'ASC', $limit = null)
    {
        return [];
    }

    /** Put the pointer back so the next allocator starts from a stale value. */
    public function rewindPointer(string $prefix): void
    {
        unset($this->docs['feeCounters/_sys_' . $prefix]);
    }

    public function claimCount(string $prefix): int
    {
        $n = 0;
        foreach ($this->docs as $k => $_) {
            if (strpos($k, 'feeCounters/_sys_' . $prefix . '_claim_') === 0) $n++;
        }
        return $n;
    }

    public function __call($name, $args) { return null; }
}
