<?php
namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Tests\Support\FakeLifecycleFirestore;

require_once __DIR__ . '/../../application/libraries/Institution_closure.php';

/**
 * Closure checklist.
 *
 * The two properties that matter most here are negative ones:
 *
 *  • closure NEVER writes — no student is withdrawn, no staff separated,
 *    no demand cancelled, no trip stopped. It produces a work-list;
 *    humans do the work.
 *  • an UNKNOWN count blocks closure exactly as a BLOCKING one does. A
 *    Firestore blip must never be able to certify a school as clear, because
 *    closure is operationally irreversible.
 */
class InstitutionClosureTest extends TestCase
{
    private const SID = 'SCH_CL_001';

    private function fake(array $counts): FakeLifecycleFirestore
    {
        $f = new class extends FakeLifecycleFirestore {
            public $counts = [];
            public $countCalls = 0;
            public $throwOnCount = false;
            public function firestoreCount(string $collection, array $conditions = []): int
            {
                $this->countCalls++;
                if ($this->throwOnCount) throw new \RuntimeException('aggregation down');
                foreach ($conditions as $c) {
                    if ($c[0] === 'status') {
                        return $this->counts[$collection . ':' . $c[2]] ?? 0;
                    }
                }
                return $this->counts[$collection] ?? 0;
            }
        };
        $f->counts = $counts;
        return $f;
    }

    private function svc($f): \Institution_closure
    {
        $svc = new \Institution_closure();
        return $svc->init($f);
    }

    private function item(array $r, string $key): array
    {
        foreach ($r['items'] as $i) if ($i['key'] === $key) return $i;
        $this->fail("checklist item {$key} is missing");
    }

    // ── Clean school ──────────────────────────────────────────────────

    public function test_a_settled_school_may_close(): void
    {
        $r = $this->svc($this->fake([]))->checklist(self::SID);
        $this->assertSame(0, $r['blocking']);
        $this->assertSame(0, $r['unknown']);
        $this->assertTrue($r['closeAllowed']);
        $this->assertFalse($r['requiresOverride']);
    }

    public function test_closure_never_writes_anything(): void
    {
        $f = $this->fake(['feeDemands:unpaid' => 42, 'tripLogs:in_progress' => 2]);
        $this->svc($f)->checklist(self::SID);
        $this->assertSame([], $f->writtenCollections(),
            'a closure checklist must not withdraw students, separate staff, '
            . 'cancel demands or stop trips — it lists work, it does not do it');
    }

    // ── Money ─────────────────────────────────────────────────────────

    public function test_outstanding_fees_block_closure(): void
    {
        $r = $this->svc($this->fake(['feeDemands:unpaid' => 137]))->checklist(self::SID);
        $i = $this->item($r, 'fees.outstanding');
        $this->assertSame('BLOCKING', $i['status']);
        $this->assertSame(137, $i['count']);
        $this->assertFalse($r['closeAllowed']);
        $this->assertNotSame('', $i['fix'], 'a blocker must say how to clear it');
    }

    public function test_pending_refunds_block_closure(): void
    {
        $r = $this->svc($this->fake(['feeRefunds:pending' => 3]))->checklist(self::SID);
        $this->assertSame('BLOCKING', $this->item($r, 'fees.refunds')['status']);
    }

    public function test_unposted_accounting_blocks_closure(): void
    {
        $r = $this->svc($this->fake(['accountingLedger:pending' => 9]))->checklist(self::SID);
        $this->assertSame('BLOCKING', $this->item($r, 'accounting.unposted')['status']);
    }

    // ── People ────────────────────────────────────────────────────────

    public function test_incomplete_staff_separations_block_closure(): void
    {
        $r = $this->svc($this->fake(['staffSeparations:pending' => 4]))->checklist(self::SID);
        $i = $this->item($r, 'staff.separations');
        $this->assertSame('BLOCKING', $i['status']);
        $this->assertStringContainsStringIgnoringCase('settlement', $i['why']);
    }

    // ── Safety ────────────────────────────────────────────────────────

    public function test_a_trip_in_progress_blocks_closure(): void
    {
        $r = $this->svc($this->fake(['tripLogs:in_progress' => 1]))->checklist(self::SID);
        $i = $this->item($r, 'transport.active_trips');
        $this->assertSame('BLOCKING', $i['status']);
        $this->assertStringContainsStringIgnoringCase('children', $i['why'],
            'the reason must make the safety stake explicit to the operator');
    }

    public function test_route_assignments_warn_rather_than_block(): void
    {
        $r = $this->svc($this->fake(['studentRoutes:active' => 200]))->checklist(self::SID);
        $this->assertSame('WARNING', $this->item($r, 'transport.assignments')['status']);
        $this->assertTrue($r['closeAllowed'], 'warnings must not block a legitimate closure');
    }

    public function test_pending_admissions_warn_rather_than_block(): void
    {
        $r = $this->svc($this->fake(['crmApplications:pending' => 12]))->checklist(self::SID);
        $this->assertSame('WARNING', $this->item($r, 'admissions.pending')['status']);
    }

    // ── The critical negative property ────────────────────────────────

    public function test_an_unreadable_count_blocks_closure_and_is_never_clear(): void
    {
        $f = $this->fake([]);
        $f->throwOnCount = true;

        $r = $this->svc($f)->checklist(self::SID);

        $this->assertGreaterThan(0, $r['unknown']);
        $this->assertFalse($r['closeAllowed'],
            'closure is operationally irreversible — "could not check" must never '
            . 'read as "nothing to check"');
        foreach ($r['items'] as $i) {
            $this->assertNotSame('CLEAR', $i['status'],
                "item {$i['key']} was reported CLEAR from an unreadable count");
        }
    }

    public function test_uninitialised_engine_does_not_certify_a_school_as_closeable(): void
    {
        $svc = new \Institution_closure();
        $r = $svc->checklist(self::SID);
        $this->assertFalse($r['closeAllowed']);
    }

    // ── Shape + budget ────────────────────────────────────────────────

    public function test_every_item_explains_what_why_and_how(): void
    {
        $r = $this->svc($this->fake([
            'feeDemands:unpaid' => 1, 'feeRefunds:pending' => 1,
            'accountingLedger:pending' => 1, 'staffSeparations:pending' => 1,
            'tripLogs:in_progress' => 1, 'studentRoutes:active' => 1,
            'crmApplications:pending' => 1,
        ]))->checklist(self::SID);

        foreach ($r['items'] as $i) {
            foreach (['key', 'domain', 'label', 'status', 'count', 'what', 'why', 'fix'] as $f) {
                $this->assertArrayHasKey($f, $i);
            }
            $this->assertNotSame('', trim($i['what']), "item {$i['key']} has no description");
            $this->assertNotSame('', trim($i['why']),  "item {$i['key']} has no rationale");
            $this->assertNotSame('', trim($i['fix']),  "item {$i['key']} has no remedy");
        }
    }

    public function test_cost_is_flat_and_independent_of_school_size(): void
    {
        $small = $this->fake(['feeDemands:unpaid' => 1]);
        $this->svc($small)->checklist(self::SID);

        $big = $this->fake(['feeDemands:unpaid' => 250000, 'studentRoutes:active' => 40000]);
        $this->svc($big)->checklist(self::SID);

        $this->assertSame($small->countCalls, $big->countCalls,
            'the checklist must cost the same for a 200-student school and a 50,000-student one');
        $this->assertSame([], $small->writtenCollections());
    }
}
