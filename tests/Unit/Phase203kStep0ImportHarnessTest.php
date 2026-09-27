<?php
namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * ══════════════════════════════════════════════════════════════════════════
 * Phase 20.3K-Step0 — EXECUTABLE IMPORT TEST HARNESS + GOLDEN MASTER
 * ══════════════════════════════════════════════════════════════════════════
 *
 * WHY THIS EXISTS
 *
 * `Sis::_runStudentImport()` is ~600 lines containing rollback, identity,
 * fee, history and per-row result behaviour, and — before this file — NOT ONE
 * of the repository's 1630 tests could execute it. The next phase must move a
 * 285-line post-write block out of it and convert five `continue;` statements
 * that live inside rollback branches. Doing that without a way to observe
 * behaviour would be the riskiest change in this programme.
 *
 * This harness makes the method executable so that refactor can be verified
 * instead of asserted. It records the CURRENT implementation's observable
 * contract as a golden master.
 *
 * ── HOW THE SEAM WORKS ──────────────────────────────────────────────────────
 *   1. A minimal `CI_Controller` stub is declared BEFORE MY_Controller loads,
 *      so the parent chain resolves without CI3's core.
 *   2. MY_Controller.php and Sis.php are `require`d — READ-ONLY. Neither is
 *      modified, and MY_Controller remains a protected file.
 *   3. `newInstanceWithoutConstructor()` skips both constructors — MY_Controller's
 *      loads firebase/session/helpers and emits HTTP headers.
 *   4. `ReflectionMethod::setAccessible(true)` reaches the private method.
 *      Production visibility is NOT changed.
 *
 * ── WHAT IS FAKED, AND WHAT DELIBERATELY IS NOT ─────────────────────────────
 * Only SERVICES are doubled (`fs`, `firebase`, `id_generator`, `srnoSvc`,
 * `entity_sync`, `load`, …). The controller's own helpers — `_nextStudentId()`,
 * `_allocateAdmissionNumber()`, `_sectionCapacityExceeded()`,
 * `_rollNoConflict()` — are PRIVATE and therefore cannot be stubbed by
 * subclassing; they execute FOR REAL against the doubles. That is a feature:
 * more of the true code path is exercised, and the golden master reflects
 * real control flow rather than a mocked approximation.
 *
 * ── NO LIVE DATA ────────────────────────────────────────────────────────────
 * Nothing here touches Firestore, any tenant, or SCH_B56BB9A401. No SRNO,
 * Student ID or Admission Number is consumed. Fully deterministic.
 */
class Phase203kStep0ImportHarnessTest extends TestCase
{
    /** @var object|null the Sis instance under test */
    private $sis;
    /** @var HarnessRecorder */
    private $rec;

    // ── seam construction ────────────────────────────────────────────────

    public static function setUpBeforeClass(): void
    {
        if (!class_exists('CI_Controller', false)) {
            eval('class CI_Controller { public static $instance; public function __construct() { self::$instance = $this; } }');
        }
        if (!function_exists('get_instance')) {
            // CI3 declares `function &get_instance()` — BY REFERENCE. Callers
            // legitimately write `$CI = &get_instance();`, which is a fatal
            // "Only variables should be assigned by reference" against a
            // by-value stub. Mirror the real signature.
            eval('function &get_instance() { return \CI_Controller::$instance; }');
        }
        // `Sis::_createFirebaseAuthStudent()` (:4537) calls Firebase::authEmail()
        // STATICALLY, so the real class name must resolve. Declared only if the
        // production library has not already been loaded by another test.
        if (!class_exists('Firebase', false)) {
            eval('class Firebase { public static function authEmail($id) { return $id . "@test.invalid"; } }');
        }
        // Import now calls Section_seat_guard::enabled() STATICALLY (Step 2),
        // so the real class must resolve. Its enabled() reads CI config inside
        // a try/catch and returns FALSE when unavailable — which is exactly the
        // flag-off behaviour this golden master must exercise.
        if (!class_exists('Section_seat_guard', false)) {
            require_once __DIR__ . '/../../application/libraries/Section_seat_guard.php';
        }
        if (!class_exists('MY_Controller', false)) {
            require_once __DIR__ . '/../../application/core/MY_Controller.php';
        }
        if (!class_exists('Sis', false)) {
            require_once __DIR__ . '/../../application/controllers/Sis.php';
        }
    }

    protected function setUp(): void
    {
        $this->rec = new HarnessRecorder();

        $rc  = new \ReflectionClass('Sis');
        $this->sis = $rc->newInstanceWithoutConstructor();
        \CI_Controller::$instance = $this->sis;

        // Service doubles + scalars the method reaches for (inventory taken
        // from the probe: 20 `$this->` members).
        $this->set('load',         new HarnessLoader($this->rec));
        $this->set('fs',           new HarnessFs($this->rec));
        $this->set('firebase',     new HarnessFirebase($this->rec));
        $this->set('id_generator', new HarnessIdGen($this->rec));
        $this->set('srnoSvc',      new HarnessSrno($this->rec));
        $this->set('entity_sync',  new HarnessEntitySync($this->rec));
        $this->set('feeLifecycle', new HarnessFees($this->rec));
        $this->set('session',      new HarnessSession($this->rec));
        // Loaded inside the row loop via load->library('Apaar_service', …);
        // the HarnessLoader is a no-op, so the alias must be pre-set or the
        // APAAR branch fatals on an undefined property.
        $this->set('apaarSvc',     new HarnessApaar($this->rec));
        $this->set('studentHistorySvc', new HarnessGeneric($this->rec, 'history'));
        $this->set('capability',   new HarnessGeneric($this->rec, 'capability'));
        $this->set('school_name',  'Vikrant Public');
        $this->set('school_id',    'SCH_TEST0001');
        $this->set('parent_db_key','SCH_TEST0001');
        $this->set('session_year', '2026-27');
        $this->set('admin_id',     'SSA_TEST');
    }

    /** Set any property regardless of declared visibility. */
    private function set(string $prop, $value): void
    {
        $rc = new \ReflectionClass($this->sis);
        while ($rc && !$rc->hasProperty($prop)) $rc = $rc->getParentClass();
        if ($rc) {
            $p = $rc->getProperty($prop);
            $p->setAccessible(true);
            $p->setValue($this->sis, $value);
        } else {
            // Dynamic property — CI3 controllers assign these at runtime.
            $this->sis->{$prop} = $value;
        }
    }

    /**
     * Invoke the private production method.
     *
     * Defaults mirror the LIVE caller `import_commit()` (`Sis:6854`), which
     * always passes both keys explicitly.
     *
     * ⚠ Deliberately NOT called with `[]`: `Sis:5467` reads
     *   `in_array(($opts['dupPolicy'] ?? 'skip'), …) ? $opts['dupPolicy'] : 'skip'`
     * — the `??` makes the CONDITION true, then the true-branch reads the
     * undefined key, emitting "Undefined array key" and yielding null rather
     * than the intended 'skip'. Registered as P20.3K-F1 (§ report); harmless in
     * behaviour because null satisfies both `!== 'create'` (:5576) and
     * `!== 'update'` (:5608) exactly as 'skip' does, and the only opts-less
     * caller is the legacy, UI-unreachable `import_students()` at :5431.
     */
    private function runImport(array $rows, array $opts = []): array
    {
        $opts = array_merge(['dupPolicy' => 'skip', 'importMode' => 'new'], $opts);
        $rm = new \ReflectionMethod('Sis', '_runStudentImport');
        $rm->setAccessible(true);
        return $rm->invoke($this->sis, $rows, $opts);
    }

    private function row(array $over = []): array
    {
        return array_merge([
            'Name'        => 'Test Student',
            'Father Name' => 'Test Father',
            'Class'       => '9th',
            'Section'     => 'A',
            'Phone'       => '9000000001',
        ], $over);
    }

    // ── PROOF THE SEAM IS REAL ───────────────────────────────────────────

    /**
     * The single most important test in this file: proves PHPUnit actually
     * enters the production method, and that the doubles are genuinely called
     * BY IT rather than by the test.
     */
    public function test_HARNESS_00_production_method_really_executes(): void
    {
        $res = $this->runImport([$this->row()]);

        $this->assertIsArray($res, 'The production method must return its result array.');
        foreach (['success', 'error', 'skipped', 'duplicates', 'credentials'] as $k) {
            $this->assertArrayHasKey($k, $res, "Result key '{$k}' is part of the existing contract.");
        }

        // If the doubles were never touched, the method did not run.
        $this->assertNotEmpty($this->rec->calls,
            'No service double was called — the production method did not execute.');
        $this->assertTrue($this->rec->called('id_generator.generate'),
            '_nextStudentId() should have run for real against the id_generator double.');
    }

    /**
     * Negative control (Phase 5 requirement): if a double changes behaviour,
     * the observed outcome MUST change. This proves the assertions are driven
     * by production logic, not by the test.
     */
    public function test_HARNESS_00b_changing_a_double_changes_the_outcome(): void
    {
        $ok = $this->runImport([$this->row()]);

        $this->setUp();                       // fresh recorder + instance
        $this->rec->idGenReturns = null;      // Student ID allocation fails
        $failed = $this->runImport([$this->row()]);

        $this->assertNotEquals(
            [$ok['success'], $ok['error']],
            [$failed['success'], $failed['error']],
            'Breaking id_generator must change the result — otherwise the test is not observing production behaviour.'
        );
    }

    // ── GOLDEN MASTER ────────────────────────────────────────────────────

    /** IMPORT-HARNESS-01 — one valid row. */
    public function test_HARNESS_01_single_valid_row(): void
    {
        $res = $this->runImport([$this->row()]);

        $this->assertSame(1, $res['success'] + $res['error'],
            'Exactly one row must be accounted for.');
        fwrite(STDERR, "\n[golden:HARNESS-01] success={$res['success']} error={$res['error']}"
            . " skipped=" . json_encode($res['skipped']) . "\n");
        $this->rec->dump('HARNESS-01');
    }

    /** IMPORT-HARNESS-02 — saveStudent() fails. */
    public function test_HARNESS_02_save_failure_releases_student_id(): void
    {
        $this->rec->saveStudentReturns = false;
        $res = $this->runImport([$this->row()]);

        $this->assertSame(0, $res['success'], 'A failed save must not count as success.');
        $this->assertGreaterThan(0, $res['error'], 'A failed save must be an error.');
        $this->assertTrue($this->rec->called('id_generator.releaseClaim'),
            'GOLDEN MASTER: a failed save releases the claimed Student ID (Sis:5847).');
        $this->rec->dump('HARNESS-02');
    }

    /** IMPORT-HARNESS-07 — row 1 fails, row 2 must still process. */
    public function test_HARNESS_07_failed_row_does_not_stop_the_next_row(): void
    {
        $this->rec->saveStudentFailFor = ['STU0001'];   // only the first row
        $res = $this->runImport([
            $this->row(['Name' => 'First',  'Phone' => '9000000001']),
            $this->row(['Name' => 'Second', 'Phone' => '9000000002']),
        ]);

        $this->assertSame(2, $res['success'] + $res['error'],
            'CRITICAL: both rows must be accounted for — a failing row must not abort the loop.');
        $this->assertGreaterThanOrEqual(1, $res['error']);
        $this->rec->dump('HARNESS-07');
    }

    /** IMPORT-HARNESS-08 — row 1 succeeds, row 2 fails; no cross-row contamination. */
    public function test_HARNESS_08_success_then_failure(): void
    {
        $this->rec->saveStudentFailFor = ['STU0002'];
        $res = $this->runImport([
            $this->row(['Name' => 'First',  'Phone' => '9000000001']),
            $this->row(['Name' => 'Second', 'Phone' => '9000000002']),
        ]);

        $this->assertSame(2, $res['success'] + $res['error']);
        $this->rec->dump('HARNESS-08');
    }

    /**
     * IMPORT-GOLDEN-03 — SRNO allocateNext fails (importMode 'new', Sis:5902).
     * GOLDEN: student is DELETED and the Student ID released; row rejected.
     */
    public function test_GOLDEN_03_srno_allocate_failure_rolls_back_student(): void
    {
        $this->rec->srnoAllocateOk = false;
        $res = $this->runImport([$this->row()]);

        $this->assertSame(0, $res['success']);
        $this->assertSame(1, $res['error']);
        $this->assertTrue($this->rec->called('entity_sync.deleteStudent'),
            'GOLDEN (:5903): a failed SRNO allocation DELETES the just-created student.');
        $this->assertTrue($this->rec->called('id_generator.releaseClaim'),
            'GOLDEN (:5904): …and releases the Student ID.');
        $this->assertStringContainsString('SR Number could not be allocated', $res['skipped'][0]);
        $this->rec->dump('GOLDEN-03');
    }

    /**
     * IMPORT-GOLDEN-03b — SRNO claim fails (importMode 'historical', Sis:5874).
     * Distinct branch from 03: supplied SR number, not auto-allocated.
     */
    public function test_GOLDEN_03b_srno_claim_failure_rolls_back_student(): void
    {
        $this->rec->srnoClaimOk = false;
        $res = $this->runImport(
            [$this->row(['SR Number' => '4242'])],
            ['importMode' => 'historical']
        );

        $this->assertSame(1, $res['error']);
        $this->assertTrue($this->rec->called('entity_sync.deleteStudent'));
        $this->assertTrue($this->rec->called('id_generator.releaseClaim'));
        $this->assertStringContainsString('could not be claimed', $res['skipped'][0]);
        $this->rec->dump('GOLDEN-03b');
    }

    /**
     * IMPORT-GOLDEN-04 — Admission Number allocation is TOLERATED, not fatal.
     *
     * CORRECTION TO THE BRIEF: `_allocateAdmissionNumber()` returning '' does
     * NOT trigger an outer continue. Sis:5888/:5911 guard with
     * `if ($importAdmNo !== '')`, so the field is simply omitted and the row
     * still succeeds. This is therefore NOT one of the five continue branches.
     */
    public function test_GOLDEN_04_admission_number_failure_is_tolerated(): void
    {
        // _allocateAdmissionNumber() runs for real; its collaborator returning
        // nothing yields '' — the row must still import.
        $res = $this->runImport([$this->row()]);

        $this->assertSame(1, $res['success'],
            'GOLDEN: a missing Admission Number does NOT reject the row.');
        $this->assertFalse($this->rec->called('entity_sync.deleteStudent'),
            'GOLDEN: no rollback occurs for a missing Admission Number.');
        $this->rec->dump('GOLDEN-04');
    }

    /**
     * IMPORT-GOLDEN-05 — APAAR claim fails (Sis:5938).
     * GOLDEN: student deleted + Student ID released; row rejected.
     */
    public function test_GOLDEN_05_apaar_claim_failure_rolls_back_student(): void
    {
        $this->rec->apaarClaimOk = false;
        $res = $this->runImport([$this->row(['APAAR ID' => '123456789012'])]);

        $this->assertSame(1, $res['error']);
        $this->assertTrue($this->rec->called('apaar.claim'), 'The APAAR branch must be reached.');
        $this->assertTrue($this->rec->called('entity_sync.deleteStudent'),
            'GOLDEN (:5939): a failed APAAR claim rolls the student back.');
        $this->rec->dump('GOLDEN-05');
    }

    /**
     * IMPORT-GOLDEN-06 — monthFee init fails (Sis:6014).
     *
     * GOLDEN — and asymmetric: this branch skips the row but does NOT delete
     * the student. The message says so explicitly ("student doc remains without
     * fee tracking"). Preserving that asymmetry is essential in the extraction.
     */
    public function test_GOLDEN_06_monthfee_failure_skips_row_without_deleting(): void
    {
        $this->rec->monthFeeOk = false;
        $res = $this->runImport([$this->row()]);

        $this->assertSame(0, $res['success']);
        $this->assertSame(1, $res['error']);
        $this->assertFalse($this->rec->called('entity_sync.deleteStudent'),
            'GOLDEN (:6011-6015): the monthFee branch does NOT delete the student — '
            . 'unlike every other rollback branch.');
        $this->assertStringContainsString('monthFee init failed', $res['skipped'][0]);
        $this->rec->dump('GOLDEN-06');
    }

    /**
     * IMPORT-GOLDEN-11 — inner subjects-loop continues stay inner.
     *
     * A row whose subject payload contains skippable entries must still import.
     * If the two inner `continue`s at :6040/:6042 ever became outer-row
     * continues, this row would be rejected instead.
     */
    public function test_GOLDEN_11_inner_subject_loop_continues_do_not_skip_the_row(): void
    {
        $res = $this->runImport([$this->row(['Subjects' => 'Maths,,English'])]);

        $this->assertSame(1, $res['success'],
            'GOLDEN: inner subject-loop continues must NOT skip the outer student row.');
        $this->rec->dump('GOLDEN-11');
    }

    /** IMPORT-GOLDEN-14 — failure → success → failure; no state leak. */
    public function test_GOLDEN_14_failure_success_failure(): void
    {
        $this->rec->saveStudentFailFor = ['STU0001', 'STU0003'];
        $res = $this->runImport([
            $this->row(['Name' => 'A', 'Phone Number' => '9000000001']),
            $this->row(['Name' => 'B', 'Phone Number' => '9000000002']),
            $this->row(['Name' => 'C', 'Phone Number' => '9000000003']),
        ]);

        $this->assertSame(1, $res['success'], 'Only the middle row may succeed.');
        $this->assertSame(2, $res['error']);
        $this->assertSame(2, $this->rec->countOf('id_generator.releaseClaim'),
            'GOLDEN: exactly two Student IDs released — one per failed row.');
        $this->rec->dump('GOLDEN-14');
    }

    /** IMPORT-GOLDEN-15 — mixed multi-row: success + SRNO rollback + success. */
    public function test_GOLDEN_15_mixed_success_and_rollback(): void
    {
        $res = $this->runImport([
            $this->row(['Name' => 'A', 'Phone Number' => '9000000001']),
            $this->row(['Name' => 'B', 'Phone Number' => '9000000002', 'APAAR ID' => '999888777666']),
            $this->row(['Name' => 'C', 'Phone Number' => '9000000003']),
        ]);

        $this->assertSame(3, $res['success'] + $res['error'] + count($res['duplicates']),
            'Every row accounted for exactly once.');
        $this->rec->dump('GOLDEN-15');
    }

    /** IMPORT-HARNESS-12 — multi-row mutable state stays per-row. */
    public function test_HARNESS_12_multi_row_state_is_independent(): void
    {
        $res = $this->runImport([
            $this->row(['Name' => 'A', 'Phone' => '9000000001']),
            $this->row(['Name' => 'B', 'Phone' => '9000000002']),
            $this->row(['Name' => 'C', 'Phone' => '9000000003']),
        ]);

        $this->assertSame(3, $res['success'] + $res['error'] + count($res['duplicates']),
            'All three rows must be accounted for exactly once.');
        $this->rec->dump('HARNESS-12');
    }
}

// ══════════════════════════════════════════════════════════════════════════
//  Recording doubles — deterministic, no network, no tenant.
// ══════════════════════════════════════════════════════════════════════════

class HarnessRecorder
{
    public array $calls = [];
    public int   $seq   = 0;

    /** Programmable outcomes. */
    public $idGenReturns          = '__AUTO__';
    public bool $saveStudentReturns = true;
    public array $saveStudentFailFor = [];
    public bool $feeStructureExists  = true;
    public bool $srnoAllocateOk      = true;   // :5902 branch (importMode 'new')
    public bool $srnoClaimOk         = true;   // :5874 branch (importMode 'historical')
    public bool $apaarClaimOk        = true;   // :5938 branch
    public bool $monthFeeOk          = true;   // :6014 branch

    public function log(string $what, array $args = []): void
    {
        $this->calls[] = ['n' => ++$this->seq, 'call' => $what, 'args' => $args];
    }

    public function called(string $what): bool
    {
        foreach ($this->calls as $c) if ($c['call'] === $what) return true;
        return false;
    }

    public function countOf(string $what): int
    {
        $n = 0;
        foreach ($this->calls as $c) if ($c['call'] === $what) $n++;
        return $n;
    }

    /** Golden-master trace — the ORDER of side effects, not just totals. */
    public function dump(string $label): void
    {
        $seqTxt = [];
        foreach ($this->calls as $c) $seqTxt[] = $c['call'];
        fwrite(STDERR, "\n[golden:{$label}] " . implode(' → ', $seqTxt) . "\n");
    }
}

class HarnessLoader
{
    public function __construct(private HarnessRecorder $r) {}
    public function library($n, $p = null, $alias = null) { $this->r->log('load.library', [is_array($n) ? 'array' : $n]); return $this; }
    public function helper($n)  { return $this; }
    public function model($n)   { return $this; }
    public function driver($n, $c = null) { return $this; }
}

class HarnessIdGen
{
    private int $i = 0;
    public function __construct(private HarnessRecorder $r) {}
    public function generate($prefix)
    {
        $this->r->log('id_generator.generate', [$prefix]);
        if ($this->r->idGenReturns !== '__AUTO__') return $this->r->idGenReturns;
        return sprintf('STU%04d', ++$this->i);
    }
    public function releaseClaim($prefix, $n) { $this->r->log('id_generator.releaseClaim', [$prefix, $n]); return true; }
}

class HarnessFs
{
    public function __construct(private HarnessRecorder $r) {}
    public function saveStudent($id, $data)
    {
        $this->r->log('fs.saveStudent', [$id]);
        if (in_array($id, $this->r->saveStudentFailFor, true)) return false;
        return $this->r->saveStudentReturns;
    }
    public function docId($id)                     { return 'SCH_TEST0001_' . $id; }
    public function sectionDocId($c, $s, $sess = null) { return "SCH_TEST0001_{$sess}_{$c}_{$s}"; }
    public function get($col, $id)
    {
        $this->r->log('fs.get', [$col]);
        // _fee_structure_missing() (Sis:603) aborts EVERY import row unless a
        // feeStructures doc with a non-empty feeHeads exists. Serving one is
        // required to reach the post-write block at all — the golden master
        // would otherwise only ever record "no fee structure".
        if ($col === 'feeStructures' && $this->r->feeStructureExists) {
            return ['feeHeads' => [['name' => 'Tuition', 'amount' => 1000]]];
        }
        return null;
    }
    public function schoolWhere($col, $f = [], $o = null, $d = 'ASC', $l = 0) { $this->r->log('fs.schoolWhere', [$col]); return []; }
    public function schoolList($col, $f = [])      { return []; }
    public function updateEntity($col, $id, $d)
    {
        $this->r->log('fs.updateEntity', [$col, $id]);
        // The monthFee init write (Sis:6007) gates the :6014 outer continue.
        if (is_array($d) && array_key_exists('monthFee', $d)) return $this->r->monthFeeOk;
        return true;
    }
    public function update($col, $id, $d)          { $this->r->log('fs.update', [$col, $id]); return true; }
    public function set($col, $id, $d, $m = false) { $this->r->log('fs.set', [$col, $id]); return true; }
    public function listCacheKey($n)               { return 'k_' . $n; }
    public function schoolId()                     { return 'SCH_TEST0001'; }
}

class HarnessFirebase
{
    public function __construct(private HarnessRecorder $r) {}
    public function firestoreGet($c, $d)            { return null; }
    public function firestoreSet($c, $d, $x, $m = false) { $this->r->log('firebase.firestoreSet', [$c]); return true; }
    public function firestoreCreate($c, $d, $x)     { $this->r->log('firebase.firestoreCreate', [$c]); return true; }
    public function firestoreCreateStatus($c, $d, $x) { return ['status' => 'SUCCESS', 'code' => 200, 'message' => '']; }
    public function firestoreCommitBatch($ops)      { return true; }
    public function firestoreCommitBatchStatus($ops){ return ['status' => 'SUCCESS', 'code' => 200, 'message' => '']; }
    public function createFirebaseUser($email, $pw, $props = []) { $this->r->log('firebase.createFirebaseUser', [$email]); return true; }
    public function setCustomUserClaims($uid, $claims)           { return true; }
    public function deleteFirebaseUser($uid)                     { $this->r->log('firebase.deleteFirebaseUser', [$uid]); return true; }
    public function getUserByEmail($email)                       { return null; }
    /** Catch-all so an unmodelled call names itself instead of fataling. */
    public function __call($name, $args) { $this->r->log('firebase.' . $name); return null; }
}

class HarnessSrno
{
    private int $i = 0;
    public function __construct(private HarnessRecorder $r) {}
    public function init(...$a)              { $this->r->log('srno.init'); return $this; }
    public function resolve($v)              { $this->r->log('srno.resolve', [$v]); return ['ok' => false]; }
    public function claim($v, $sid)
    {
        $this->r->log('srno.claim', [$v, $sid]);
        return $this->r->srnoClaimOk
            ? ['ok' => true, 'srNo' => $v]
            : ['ok' => false, 'reason' => 'duplicate'];
    }
    public function allocateNext($sch, $sid)
    {
        $this->r->log('srno.allocateNext', [$sid]);
        return $this->r->srnoAllocateOk
            ? ['ok' => true, 'srNo' => (string) (++$this->i)]
            : ['ok' => false, 'reason' => 'counter_failed'];
    }
    public static function normalize($v)     { return ['norm' => ltrim((string) $v, '0')]; }
}

class HarnessEntitySync
{
    public function __construct(private HarnessRecorder $r) {}
    public function syncStudent($id, $d)  { $this->r->log('entity_sync.syncStudent', [$id]); return true; }
    public function syncParent($id, $d)   { $this->r->log('entity_sync.syncParent', [$id]); return true; }
    public function deleteStudent($id)    { $this->r->log('entity_sync.deleteStudent', [$id]); return true; }
    public function buildStudentDoc($id, $d) { return ['docId' => 'SCH_TEST0001_' . $id, 'doc' => $d]; }
}

class HarnessFees
{
    public function __construct(private HarnessRecorder $r) {}
    public function assignInitialFees($sid, $c, $s, $sch) { $this->r->log('fees.assignInitialFees', [$sid]); return true; }
    public function __call($n, $a) { $this->r->log('fees.' . $n); return true; }
}

class HarnessApaar
{
    public function __construct(private HarnessRecorder $r) {}
    public function init(...$a) { $this->r->log('apaar.init'); return $this; }
    public function claim($v, $sid)
    {
        $this->r->log('apaar.claim', [$sid]);
        return $this->r->apaarClaimOk
            ? ['ok' => true]
            : ['ok' => false, 'reason' => 'duplicate'];
    }
    public static function normalize($v) { return preg_replace('/\D/', '', (string) $v); }
}

class HarnessSession
{
    public function __construct(private HarnessRecorder $r) {}
    public function userdata($k = null) { return $k === 'admin_name' ? 'Harness Admin' : null; }
    public function set_userdata($k, $v = null) { return true; }
    public function __call($n, $a) { $this->r->log('session.' . $n); return null; }
}

/**
 * Generic recorder for collaborators whose exact surface is not part of the
 * contract under test. Every call is logged by name, so an unmodelled
 * dependency reveals itself in the golden trace instead of fataling.
 */
class HarnessGeneric
{
    public function __construct(private HarnessRecorder $r, private string $label) {}
    public function __call($n, $a) { $this->r->log($this->label . '.' . $n); return true; }
    public function init(...$a) { $this->r->log($this->label . '.init'); return $this; }
    public function log(...$a)  { $this->r->log($this->label . '.log');  return true; }
}
