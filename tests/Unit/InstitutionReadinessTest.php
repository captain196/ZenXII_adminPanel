<?php
namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Tests\Support\FakeLifecycleFirestore;

require_once __DIR__ . '/../../application/libraries/Institution_readiness.php';

/**
 * Readiness scorer.
 *
 * The properties under test are the ones that decide whether an operator can
 * trust the number:
 *
 *  • it NEVER writes (a scorer that mutates domain data is a workflow engine);
 *  • a module the tenant does not have is NOT_APPLICABLE, never BLOCKING;
 *  • an UNREADABLE collection is reported as unavailable, never as zero —
 *    otherwise a Firestore blip manufactures a false BLOCKING verdict;
 *  • every finding carries what / why / how-to-fix, so the panel can be
 *    actionable rather than decorative;
 *  • reads stay bounded and flat (no N+1).
 */
class InstitutionReadinessTest extends TestCase
{
    private const SID = 'SCH_RD_001';
    private const SESSION = '2026-27';

    private function svc(FakeLifecycleFirestore $f): \Institution_readiness
    {
        $svc = new \Institution_readiness();
        return $svc->init($f);
    }

    /** A tenant with everything a school needs. */
    private function fullyConfigured(): FakeLifecycleFirestore
    {
        $f = new class extends FakeLifecycleFirestore {
            public $counts = [];
            public $countCalls = 0;
            public function firestoreCount(string $collection, array $conditions = []): int
            {
                $this->countCalls++;
                return $this->counts[$collection] ?? 0;
            }
        };
        $f->counts = ['sections' => 12, 'students' => 400, 'staff' => 30,
                      'feeStructures' => 5, 'accountingLedger' => 120, 'routes' => 4];
        $f->seed('schools', self::SID, [
            'schoolId'         => self::SID,
            'name'             => 'Vikrant Public School',
            'phone'            => '9876543210',
            'email'            => 'office@example.edu',
            'address'          => '12 School Road',
            'state'            => 'Uttar Pradesh',
            'affiliationBoard' => 'CBSE',
            'logoUrl'          => 'https://example/logo.png',
            'udiseCode'        => '09123456789',
            'currentSession'   => self::SESSION,
            'sessions'         => [self::SESSION, '2027-28'],
            'archivedSessions' => [],
            'classes'          => ['1', '2', '3'],
        ]);
        $f->seed('tenantPublic', self::SID, [
            'activeModules' => ['fees', 'accounts', 'attendance', 'transport'],
        ]);
        $f->seed('feeSettings', self::SID . '_' . self::SESSION, ['currency' => 'INR']);
        $f->seed('attendanceSettings', self::SID, ['lockAt' => '10:00']);
        return $f;
    }

    private function statusOf(array $result, string $key): string
    {
        foreach ($result['checks'] as $c) {
            if ($c['key'] === $key) return $c['status'];
        }
        return 'MISSING';
    }

    // ── The happy path ────────────────────────────────────────────────

    public function test_a_fully_configured_school_is_go_live_ready(): void
    {
        $r = $this->svc($this->fullyConfigured())->evaluate(self::SID);
        $this->assertSame(0, $r['blocking'], 'a fully configured school must have no blockers');
        $this->assertTrue($r['goLiveAllowed']);
        $this->assertSame(100, $r['score']);
    }

    public function test_scorer_never_writes_anything(): void
    {
        $f = $this->fullyConfigured();
        $this->svc($f)->evaluate(self::SID);
        $this->assertSame([], $f->writtenCollections(),
            'readiness must be a pure read — a scorer that writes is a workflow engine');
    }

    // ── The empty school ──────────────────────────────────────────────

    public function test_a_brand_new_empty_school_reports_actionable_blockers(): void
    {
        $f = new FakeLifecycleFirestore();
        $f->seed('schools', self::SID, ['schoolId' => self::SID]);

        $r = $this->svc($f)->evaluate(self::SID);

        $this->assertGreaterThan(0, $r['blocking']);
        $this->assertFalse($r['goLiveAllowed'], 'an unconfigured school must not be allowed to go live');
        $this->assertSame(self::BLOCKING_OR('identity.name', $r), 'BLOCKING');
        $this->assertSame('BLOCKING', $this->statusOf($r, 'academic.session'));
        $this->assertSame('BLOCKING', $this->statusOf($r, 'academic.classes'));
    }

    private static function BLOCKING_OR(string $key, array $r): string
    {
        foreach ($r['checks'] as $c) if ($c['key'] === $key) return $c['status'];
        return 'MISSING';
    }

    public function test_every_finding_explains_what_why_and_how_to_fix(): void
    {
        $f = new FakeLifecycleFirestore();
        $f->seed('schools', self::SID, ['schoolId' => self::SID]);
        $r = $this->svc($f)->evaluate(self::SID);

        foreach ($r['checks'] as $c) {
            foreach (['key', 'domain', 'label', 'status', 'what', 'why', 'fix'] as $field) {
                $this->assertArrayHasKey($field, $c);
                $this->assertNotSame('', trim((string) $c[$field]),
                    "check {$c['key']} has an empty {$field} — a readiness panel that "
                    . 'cannot say what to do is decoration');
            }
        }
    }

    // ── Module awareness ──────────────────────────────────────────────

    public function test_modules_the_tenant_lacks_are_not_applicable_not_blocking(): void
    {
        $f = $this->fullyConfigured();
        $f->seed('tenantPublic', self::SID, ['activeModules' => ['attendance']]);

        $r = $this->svc($f)->evaluate(self::SID);

        $this->assertSame('NOT_APPLICABLE', $this->statusOf($r, 'finance.fees'));
        $this->assertSame('NOT_APPLICABLE', $this->statusOf($r, 'finance.accounting'));
        $this->assertSame('NOT_APPLICABLE', $this->statusOf($r, 'ops.transport'));
        $this->assertSame(0, $r['blocking'],
            'a school without Transport is not "unready" for having no bus routes');
    }

    public function test_not_applicable_items_are_excluded_from_the_score(): void
    {
        $f = $this->fullyConfigured();
        $f->seed('tenantPublic', self::SID, ['activeModules' => ['attendance']]);
        $r = $this->svc($f)->evaluate(self::SID);

        $na = 0;
        foreach ($r['checks'] as $c) if ($c['status'] === 'NOT_APPLICABLE') $na++;
        $this->assertGreaterThan(0, $na);
        $this->assertSame(count($r['checks']) - $na, $r['applicable'],
            'the denominator must exclude skipped modules');
    }

    public function test_absent_entitlement_mirror_evaluates_rather_than_skips(): void
    {
        // A tenant whose tenantPublic has never been written must not score
        // 100% by having every module declared NOT_APPLICABLE.
        $f = new FakeLifecycleFirestore();
        $f->seed('schools', self::SID, ['schoolId' => self::SID]);
        $r = $this->svc($f)->evaluate(self::SID);

        $this->assertNotSame('NOT_APPLICABLE', $this->statusOf($r, 'finance.fee_structures'),
            'an unknown entitlement list must be evaluated, not skipped');
        $this->assertGreaterThan(0, $r['blocking']);
    }

    // ── Failure modes must not manufacture verdicts ───────────────────

    public function test_unreadable_counts_are_reported_as_unavailable_not_zero(): void
    {
        $f = new class extends FakeLifecycleFirestore {
            public function firestoreCount(string $collection, array $conditions = []): int
            {
                throw new \RuntimeException('aggregation unavailable');
            }
        };
        $f->seed('schools', self::SID, [
            'schoolId' => self::SID, 'name' => 'X', 'phone' => '1',
            'currentSession' => self::SESSION, 'classes' => ['1'],
        ]);

        $r = $this->svc($f)->evaluate(self::SID);

        $staff = null;
        foreach ($r['checks'] as $c) if ($c['key'] === 'people.staff') $staff = $c;
        $this->assertNotNull($staff);
        $this->assertStringContainsString('unavailable', strtolower($staff['what']),
            'an unreadable count must say so rather than silently reading as zero');
    }

    public function test_missing_school_document_does_not_throw(): void
    {
        $f = new FakeLifecycleFirestore();
        $r = $this->svc($f)->evaluate('SCH_DOES_NOT_EXIST');
        $this->assertIsArray($r['checks']);
        $this->assertGreaterThan(0, $r['blocking']);
    }

    public function test_uninitialised_engine_reports_blocking_not_a_fake_pass(): void
    {
        $svc = new \Institution_readiness();
        $r = $svc->evaluate(self::SID);
        $this->assertFalse($r['goLiveAllowed']);
        $this->assertSame(1, $r['blocking']);
    }

    // ── Specific domain rules ─────────────────────────────────────────

    public function test_an_archived_active_session_is_blocking(): void
    {
        $f = $this->fullyConfigured();
        $doc = $f->docs['schools'][self::SID];
        $doc['archivedSessions'] = [self::SESSION];
        $f->seed('schools', self::SID, $doc);

        $r = $this->svc($f)->evaluate(self::SID);
        $this->assertSame('BLOCKING', $this->statusOf($r, 'academic.session_not_archived'),
            'operating inside an archived session strands records');
        $this->assertFalse($r['goLiveAllowed']);
    }

    public function test_a_fees_enabled_school_with_no_structures_is_blocking(): void
    {
        // `feeStructures` is the real "can this school bill?" signal.
        //
        // The earlier check looked for `feeSettings/{schoolId}_{session}`.
        // That document exists for NO tenant and never has — feeSettings is
        // only ever keyed with a purpose suffix (`_gateway`, `_counters`,
        // `_rollover`, `_reminders`) — so the check emitted BLOCKING for
        // every school on the platform. Caught by running readiness against a
        // real tenant that bills 74 demands quite happily.
        $f = $this->fullyConfigured();
        $f->counts['feeStructures'] = 0;
        $r = $this->svc($f)->evaluate(self::SID);
        $this->assertSame('BLOCKING', $this->statusOf($r, 'finance.fee_structures'));
        $this->assertFalse($r['goLiveAllowed']);
    }

    public function test_entitlement_keys_are_the_plan_vocabulary_not_rbac_names(): void
    {
        // activeModules carries PLAN keys (lowercase snake_case), not RBAC
        // display names. Keying on 'Fees'/'Accounting' made every such
        // criterion NOT_APPLICABLE on live tenants and inflated the score.
        $this->assertSame('fees',       \Institution_readiness::MODULE_FEES);
        $this->assertSame('accounts',   \Institution_readiness::MODULE_ACCOUNTING);
        $this->assertSame('attendance', \Institution_readiness::MODULE_ATTENDANCE);
        $this->assertSame('transport',  \Institution_readiness::MODULE_TRANSPORT);

        // And the engine must actually honour them.
        $f = $this->fullyConfigured();
        $f->seed('tenantPublic', self::SID, ['activeModules' => ['fees']]);
        $r = $this->svc($f)->evaluate(self::SID);
        $this->assertNotSame('NOT_APPLICABLE', $this->statusOf($r, 'finance.fee_structures'),
            'a tenant entitled to `fees` must have its fee criteria evaluated');
        $this->assertSame('NOT_APPLICABLE', $this->statusOf($r, 'finance.accounting'));
    }

    public function test_a_school_with_no_students_warns_but_does_not_block(): void
    {
        $f = $this->fullyConfigured();
        $f->counts['students'] = 0;
        $r = $this->svc($f)->evaluate(self::SID);
        $this->assertSame('WARNING', $this->statusOf($r, 'people.students'),
            'a brand-new school legitimately starts empty');
        $this->assertTrue($r['goLiveAllowed']);
    }

    public function test_a_school_with_no_staff_is_blocking(): void
    {
        $f = $this->fullyConfigured();
        $f->counts['staff'] = 0;
        $r = $this->svc($f)->evaluate(self::SID);
        $this->assertSame('BLOCKING', $this->statusOf($r, 'people.staff'),
            'with no staff record nobody can log in at all');
    }

    // ── Read budget ───────────────────────────────────────────────────

    public function test_reads_are_bounded_and_flat_with_no_n_plus_one(): void
    {
        $f = $this->fullyConfigured();
        $this->svc($f)->evaluate(self::SID);

        $gets = 0;
        foreach ($f->ops as $op) if (strpos($op, 'get:') === 0) $gets++;

        $this->assertLessThanOrEqual(4, $gets,
            'readiness must read a fixed handful of documents, never a collection');
        $this->assertLessThanOrEqual(6, $f->countCalls,
            'counts must be server-side aggregations, one per collection, never per row');
    }

    public function test_read_cost_does_not_grow_with_school_size(): void
    {
        $small = $this->fullyConfigured();
        $small->counts = ['sections' => 1, 'students' => 5, 'staff' => 2,
                          'feeStructures' => 1, 'accountingLedger' => 1, 'routes' => 1];
        $this->svc($small)->evaluate(self::SID);

        $big = $this->fullyConfigured();
        $big->counts = ['sections' => 300, 'students' => 50000, 'staff' => 4000,
                        'feeStructures' => 90, 'accountingLedger' => 900000, 'routes' => 250];
        $this->svc($big)->evaluate(self::SID);

        $this->assertSame(count($small->ops), count($big->ops),
            'a 50,000-student school must cost exactly what a 5-student school costs');
    }
}
