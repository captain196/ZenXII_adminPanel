<?php
namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/Phase203kStep0ImportHarnessTest.php';

/**
 * ══════════════════════════════════════════════════════════════════════════
 * Phase 20.3K-Step2 — IMPORT DESTINATION GROUPING, FLAG **ON**
 * ══════════════════════════════════════════════════════════════════════════
 *
 * The Step 0/1 golden master proves the import behaves identically with
 * `atomic_capacity_enabled = FALSE`. That is the safety half of the contract.
 * This file proves the other half: that with the flag ON the import actually
 * groups by destination section, makes ONE capacity decision per group, and
 * is all-or-nothing per group.
 *
 * Without this file the flag would be untested — the "code reads as protected
 * but isn't" failure this programme exists to prevent.
 *
 * ── HOW THE FLAG IS TURNED ON (test-only, never in production config) ───────
 * `Section_seat_guard::enabled()` reads `$CI->config` through `get_instance()`.
 * The harness assigns a config double to that object in setUp and RESETS IT TO
 * NULL in tearDown, so no other test in the shared PHPUnit process can observe
 * the flag as enabled. `application/config/capacity_cas.php` is NOT modified
 * and remains FALSE — `Phase203hAtomicCapacityWiringTest` asserts that.
 *
 * ── WHAT IS REAL AND WHAT IS DOUBLED ────────────────────────────────────────
 * REAL (executed, not mocked): the whole Pass 1 row loop, the grouping, the
 * 499 cap, `_sectionCapacityExceeded()` / `_sectionCapacity()` /
 * `_sectionActiveCount()` / `_filterSectionRoster()` from MY_Controller, the
 * refusal messages, and the Pass 3 `$postWrite` replay.
 *
 * DOUBLED: `Section_seat_guard::placeStudentGroup()` — its own atomicity is a
 * Firestore property, proven against LIVE Firestore in Phase 20.3g
 * (winners=1 / losers=1) and constrained by `Phase203hAtomicCapacityWiringTest`.
 * What is under test HERE is Sis's orchestration around it: that a refusal
 * marks every row, that no row is written twice, and that nothing
 * identity-bearing runs before a commit.
 *
 * ── ONE MEMOISATION CONSTRAINT, MADE EXPLICIT ───────────────────────────────
 * `MY_Controller::_sectionCapacity()` memoises on a function-level `static`
 * keyed by `session|class|section`, and PHPUnit shares one process. Each test
 * therefore uses a DISTINCT destination so it gets its own capacity read —
 * see `dest()`. Reusing a destination across tests would silently serve the
 * previous test's capacity.
 *
 * ── NO LIVE DATA ────────────────────────────────────────────────────────────
 * No Firestore, no tenant, no SCH_B56BB9A401. No SRNO, Student ID or
 * Admission Number is consumed.
 */
class Phase203kStep2ImportGroupingTest extends TestCase
{
    /** @var object|null */
    private $sis;
    /** @var HarnessRecorder */
    private $rec;
    /** @var GroupGuard */
    private $guard;
    /** @var GroupFs */
    private $fs;

    public static function setUpBeforeClass(): void
    {
        // Identical seam construction to Step 0 — reusing that class's own
        // bootstrap keeps the two files from drifting apart.
        Phase203kStep0ImportHarnessTest::setUpBeforeClass();
    }

    protected function setUp(): void
    {
        $this->rec   = new HarnessRecorder();
        $this->fs    = new GroupFs($this->rec);
        $this->guard = new GroupGuard();

        $rc = new \ReflectionClass('Sis');
        $this->sis = $rc->newInstanceWithoutConstructor();
        \CI_Controller::$instance = $this->sis;

        $this->set('load',         new HarnessLoader($this->rec));
        $this->set('fs',           $this->fs);
        $this->set('firebase',     new HarnessFirebase($this->rec));
        $this->set('id_generator', new HarnessIdGen($this->rec));
        $this->set('srnoSvc',      new HarnessSrno($this->rec));
        $this->set('entity_sync',  new HarnessEntitySync($this->rec));
        $this->set('feeLifecycle', new HarnessFees($this->rec));
        $this->set('session',      new HarnessSession($this->rec));
        $this->set('apaarSvc',     new HarnessApaar($this->rec));
        $this->set('studentHistorySvc', new HarnessGeneric($this->rec, 'history'));
        $this->set('capability',   new HarnessGeneric($this->rec, 'capability'));
        // `load->library('section_seat_guard', null, 'seatGuard')` is a no-op in
        // the harness, so the alias must be pre-set exactly as CI3 would set it.
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
        // CRITICAL: leaving the flag on would silently change every other test
        // in this process. Reset unconditionally, even if the test failed.
        $this->flag(null, 0);
        unset($GLOBALS['__test_log_capture']);
    }

    /** Turn the atomic-capacity flag on/off for this test only. */
    private function flag(?bool $on, int $max): void
    {
        $ci = get_instance();
        if (is_object($ci)) {
            $ci->config = ($on === null) ? null : new GroupConfig($on, $max);
        }
    }

    private function set(string $prop, $value): void
    {
        $rc = new \ReflectionClass($this->sis);
        while ($rc && !$rc->hasProperty($prop)) $rc = $rc->getParentClass();
        if ($rc) {
            $p = $rc->getProperty($prop);
            $p->setAccessible(true);
            $p->setValue($this->sis, $value);
        } else {
            $this->sis->{$prop} = $value;
        }
    }

    private function runImport(array $rows, array $opts = []): array
    {
        $opts = array_merge(['dupPolicy' => 'skip', 'importMode' => 'new'], $opts);
        $rm = new \ReflectionMethod('Sis', '_runStudentImport');
        $rm->setAccessible(true);
        return $rm->invoke($this->sis, $rows, $opts);
    }

    /**
     * N rows for one destination, each with a unique name and phone so no
     * duplicate/roll guard rejects them before they reach the grouping.
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

    // ── NEGATIVE CONTROL ─────────────────────────────────────────────────

    /**
     * If this fails, every other test in this file is meaningless: they would
     * all be re-running the flag-OFF path under a flag-ON name.
     */
    public function test_FLAGON_00_the_flag_is_genuinely_on_in_this_harness(): void
    {
        $this->assertTrue(\Section_seat_guard::enabled(),
            'The harness must actually observe the flag as enabled.');
        $this->assertSame(499, \Section_seat_guard::groupMaxStudents());

        $this->flag(null, 0);
        $this->assertFalse(\Section_seat_guard::enabled(),
            'And it must go back off — otherwise tearDown cannot protect the rest of the suite.');
    }

    // ── GROUPING ─────────────────────────────────────────────────────────

    /** One destination, three rows → exactly ONE atomic group of three. */
    public function test_FLAGON_01_one_destination_is_one_atomic_group(): void
    {
        $this->fs->sectionMax = 40;
        $res = $this->runImport($this->rows('5th', 'A', 3));

        $this->assertCount(1, $this->guard->calls,
            'Three rows for one section must be ONE commit, not three — the N+1 the design forbids.');
        $this->assertSame(3, $this->guard->calls[0]['count']);
        $this->assertSame(3, $res['success']);
        $this->assertSame(0, $res['error']);
        $this->assertFalse($this->rec->called('fs.saveStudent'),
            'Under the flag the per-row write is REPLACED by the group commit; '
            . 'a surviving saveStudent would be a second, unguarded write path.');
    }

    /** Two destinations → two independent groups, each with its own decision. */
    public function test_FLAGON_02_two_destinations_are_two_groups(): void
    {
        $this->fs->sectionMax = 40;
        $res = $this->runImport(array_merge(
            $this->rows('5th', 'B', 2, 100),
            $this->rows('6th', 'B', 3, 200)
        ));

        $this->assertCount(2, $this->guard->calls);
        $this->assertSame([2, 3], array_column($this->guard->calls, 'count'));
        $this->assertSame(5, $res['success']);
    }

    /**
     * Exact capacity must PASS.
     *
     * This is also the `$seatsUsedThisRun` reconciliation proof. The residual
     * per-row check inside postWrite projects `$capUsed + 1`, which under
     * grouping runs AFTER the group of N was already approved against the same
     * capacity — so `capUsed + 1 <= N <= capacity` always holds and it can
     * never fire. If `$seatsUsedThisRun` were acting as a second occupancy
     * authority, this test would reject rows 2 and 3.
     */
    public function test_FLAGON_03_exact_capacity_is_admitted_and_seatsUsedThisRun_never_double_counts(): void
    {
        $this->fs->sectionMax = 3;
        $res = $this->runImport($this->rows('7th', 'A', 3, 300));

        $this->assertCount(1, $this->guard->calls);
        $this->assertSame(3, $res['success']);
        $this->assertSame(0, $res['error'],
            'Filling a section exactly to capacity is legal; no row may be rejected.');
    }

    // ── REFUSALS ─────────────────────────────────────────────────────────

    /** Over capacity → the WHOLE group is refused; nothing is committed. */
    public function test_FLAGON_04_over_capacity_refuses_the_entire_group(): void
    {
        $this->fs->sectionMax = 2;
        $res = $this->runImport($this->rows('8th', 'A', 3, 400));

        $this->assertCount(0, $this->guard->calls,
            'Capacity is decided BEFORE the commit — an over-capacity group must never reach Firestore.');
        $this->assertSame(0, $res['success']);
        $this->assertSame(3, $res['error'], 'Every row of a refused group must be reported.');
        $this->assertCount(3, $res['skipped']);
        $this->assertStringContainsString('No student was imported for this section', $res['skipped'][0]);
        $this->assertSame(0, $this->rec->countOf('srno.allocateNext'),
            'No SRNO may be consumed for a placement that did not happen.');
    }

    /**
     * A group larger than the configured maximum is REFUSED, never chunked —
     * a first chunk would move the section updateTime and the second would no
     * longer be the same atomic capacity decision.
     */
    public function test_FLAGON_05_group_over_max_is_refused_and_never_chunked(): void
    {
        $this->flag(true, 3);                 // small max keeps the test fast
        $this->fs->sectionMax = 500;
        $res = $this->runImport($this->rows('9th', 'C', 4, 500));

        $this->assertCount(0, $this->guard->calls,
            'Refused outright — and NOT split into a 3 + 1 pair.');
        $this->assertSame(4, $res['error']);
        $this->assertStringContainsString('the safe maximum', $res['skipped'][0]);
        $this->assertStringContainsString('split the file by section', $res['skipped'][0]);
    }

    /**
     * The shipped 499 boundary, exercised for real: 500 rows for one section
     * must be refused as a group. Slower than the other tests by design —
     * asserting the real configured limit is worth one full-size run.
     */
    public function test_FLAGON_06_five_hundred_rows_for_one_section_is_refused(): void
    {
        $this->fs->sectionMax = 1000;
        $res = $this->runImport($this->rows('10th', 'A', 500, 10000));

        $this->assertCount(0, $this->guard->calls);
        $this->assertSame(500, $res['error']);
        $this->assertSame(0, $res['success']);
        $this->assertStringContainsString('safe maximum per section is 499', $res['skipped'][0]);
    }

    /** CAS conflict → nothing written, every row reported, no identity spent. */
    public function test_FLAGON_07_cas_conflict_refuses_every_row_in_the_group(): void
    {
        $this->fs->sectionMax = 40;
        $this->guard->status = 'CONFLICT';
        $res = $this->runImport($this->rows('11th', 'A', 3, 600));

        $this->assertCount(1, $this->guard->calls, 'The commit was attempted once — and lost.');
        $this->assertSame(0, $res['success']);
        $this->assertSame(3, $res['error']);
        $this->assertStringContainsString('changed while the import was in progress', $res['skipped'][0]);
        $this->assertSame(0, $this->rec->countOf('srno.allocateNext'),
            'A lost CAS race must consume no SR Number.');
        $this->assertTrue($this->logged('ACC_IMPORT_GROUP_REFUSED'),
            'A refused group must be traceable in the log.');
    }

    /** Unknown outcome → refused and reported as unconfirmed, never retried. */
    public function test_FLAGON_08_unknown_outcome_is_reported_not_retried(): void
    {
        $this->fs->sectionMax = 40;
        $this->guard->status = 'FAILURE';
        $res = $this->runImport($this->rows('12th', 'A', 2, 700));

        $this->assertCount(1, $this->guard->calls,
            'An UNKNOWN outcome must NEVER be re-committed — that is how duplicates are made.');
        $this->assertSame(2, $res['error']);
        $this->assertStringContainsString('could not be confirmed', $res['skipped'][0]);
        $this->assertTrue($this->logged('ACC_IMPORT_GROUP_REFUSED'));
    }

    // ── ISOLATION AND ORDERING ───────────────────────────────────────────

    /**
     * A refused destination must not damage a healthy one. This is the
     * documented partial-commit semantics of import preserved at GROUP
     * granularity instead of row granularity.
     */
    public function test_FLAGON_09_one_refused_group_does_not_block_another(): void
    {
        $this->fs->sectionMax = 40;
        $this->guard->statusSeq = ['CONFLICT', 'PLACED'];
        $res = $this->runImport(array_merge(
            $this->rows('5th', 'D', 2, 800),
            $this->rows('6th', 'D', 3, 900)
        ));

        $this->assertCount(2, $this->guard->calls);
        $this->assertSame(3, $res['success'], 'Only the committed group counts as imported.');
        $this->assertSame(2, $res['error'],   'Only the refused group counts as errors.');
    }

    /**
     * Pass 3 runs ONLY for committed groups. SRNO allocation lives inside
     * postWrite, so its count is a direct, behavioural measure of how many
     * students the import believes it placed.
     */
    public function test_FLAGON_10_postWrite_runs_once_per_committed_student_only(): void
    {
        $this->fs->sectionMax = 40;
        $this->guard->statusSeq = ['PLACED', 'FAILURE'];
        $res = $this->runImport(array_merge(
            $this->rows('7th', 'E', 4, 1000),
            $this->rows('8th', 'E', 2, 1100)
        ));

        $this->assertSame(4, $this->rec->countOf('srno.allocateNext'),
            'Exactly one SRNO per COMMITTED student — none for the refused group.');
        $this->assertSame(4, $res['success']);
        $this->assertSame(2, $res['error']);
    }

    /**
     * Nothing identity-bearing may run during Pass 1. If SRNO or the fee
     * lifecycle ran before the commit, a refusal would leave consumed numbers
     * and orphaned fee state behind.
     */
    public function test_FLAGON_11_no_identity_or_fee_work_happens_before_the_commit(): void
    {
        $this->fs->sectionMax = 40;
        $this->guard->status = 'CONFLICT';
        $this->runImport($this->rows('9th', 'E', 3, 1200));

        $this->assertSame(0, $this->rec->countOf('srno.allocateNext'));
        $this->assertSame(0, $this->rec->countOf('fees.assignInitialFees'));
        $this->assertSame(0, $this->rec->countOf('entity_sync.syncParent'));
    }

    /**
     * The result contract is unchanged by the flag: the caller
     * (`import_commit()`) reads these keys and must keep working.
     */
    public function test_FLAGON_12_result_contract_is_unchanged(): void
    {
        $this->fs->sectionMax = 40;
        $res = $this->runImport($this->rows('10th', 'F', 2, 1300));

        foreach (['success', 'error', 'skipped', 'feesPending', 'duplicates', 'updated', 'credentials'] as $k) {
            $this->assertArrayHasKey($k, $res, "Result key '{$k}' is part of the existing contract.");
        }
    }

    /**
     * Every student buffered in Pass 1 must appear in exactly one group — no
     * duplication across groups, no silent drop.
     */
    public function test_FLAGON_13_every_student_is_committed_exactly_once(): void
    {
        $this->fs->sectionMax = 40;
        $this->runImport(array_merge(
            $this->rows('11th', 'F', 3, 1400),
            $this->rows('12th', 'F', 2, 1500)
        ));

        $ids = [];
        foreach ($this->guard->calls as $c) $ids = array_merge($ids, $c['ids']);
        $this->assertCount(5, $ids, 'All five students reached a commit.');
        $this->assertSame(count($ids), count(array_unique($ids)),
            'A student appearing in two groups would be written twice.');
    }
}

// ══════════════════════════════════════════════════════════════════════════
//  Flag-ON doubles. The Step 0 doubles are reused unchanged; only the two
//  collaborators the grouping path adds are specialised here.
// ══════════════════════════════════════════════════════════════════════════

/** CI3 config double — the only thing `Section_seat_guard::enabled()` reads. */
class GroupConfig
{
    public function __construct(private bool $on, private int $max) {}
    public function load($file, $section = false, $failGracefully = true) { return true; }
    public function item($key)
    {
        if ($key === 'atomic_capacity_enabled')      return $this->on;
        if ($key === 'capacity_group_max_students')  return $this->max;
        return null;
    }
}

/**
 * Adds section documents so `_sectionCapacity()` — which runs FOR REAL — has a
 * configured `maxStrength` to read. Everything else defers to the Step 0 double.
 */
class GroupFs extends HarnessFs
{
    /** Configured capacity served for every section doc in the current test. */
    public $sectionMax = 40;

    private HarnessRecorder $r2;

    public function __construct(HarnessRecorder $r)
    {
        parent::__construct($r);
        $this->r2 = $r;
    }

    public function get($col, $id)
    {
        if ($col === 'sections') {
            $this->r2->log('fs.get', ['sections']);
            return [
                'maxStrength'  => $this->sectionMax,
                // Present so the real guard would have a precondition; the
                // guard itself is doubled here, but omitting it would model a
                // section the guard is required to REFUSE.
                '__updateTime' => '2026-01-01T00:00:00.000000Z',
            ];
        }
        return parent::get($col, $id);
    }
}

/**
 * Stands in for `Section_seat_guard`. Its atomicity is a Firestore property
 * proven live in Phase 20.3g; what matters here is recording exactly how Sis
 * calls it — how many groups, of what size, containing which students.
 */
class GroupGuard
{
    public array  $calls     = [];
    public string $status    = 'PLACED';
    /** Per-call statuses, consumed in group order. Falls back to $status. */
    public array  $statusSeq = [];

    public function init(...$a) { return $this; }

    public function placeStudentGroup($entitySync, array $students, $classNode, $sectionNode, $session): array
    {
        $st = array_shift($this->statusSeq) ?? $this->status;
        $this->calls[] = [
            'dest'    => "{$classNode} / {$sectionNode}",
            'session' => $session,
            'count'   => count($students),
            'ids'     => array_map(static fn($s) => $s['studentId'], $students),
        ];
        return [
            'status'   => $st,
            'message'  => '',
            'attempts' => 1,
            'count'    => $st === 'PLACED' ? count($students) : 0,
        ];
    }
}
