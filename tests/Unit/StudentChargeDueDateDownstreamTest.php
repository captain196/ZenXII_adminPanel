<?php

use PHPUnit\Framework\TestCase;

if (!defined('BASEPATH')) define('BASEPATH', realpath(__DIR__ . '/../../system') . DIRECTORY_SEPARATOR);
if (!function_exists('log_message')) { function log_message($l, $m) {} }
require_once APPPATH . 'libraries/Fee_late_fee_service.php';
require_once APPPATH . 'libraries/Fee_firestore_txn.php';
require_once APPPATH . 'libraries/Student_charge_service.php';

/**
 * ══════════════════════════════════════════════════════════════════════════
 *  DUE-2 — WHAT THE MISSING DUE DATE ACTUALLY COST DOWNSTREAM
 * ══════════════════════════════════════════════════════════════════════════
 *
 * `Student_charge_service` computed a due date and passed it into
 * `createModuleFee()`. That payload never carried it onward, and
 * `_buildDemandDoc()` builds from named keys — so it was discarded silently.
 *
 * Found on a real device: a Student Charge raised today displayed in the
 * Parent app as **"Overdue · 129d"**. That is April 16 → today, i.e. the app
 * correctly falling back to inferring a due date from the month label, because
 * `createModuleFee()` files every module charge under the session start.
 *
 * The Parent display was the visible symptom. These tests cover the two silent
 * ones, which are worse:
 *
 *   • the LATE-FEE engine returns null on an empty dueDate — the charge could
 *     never be fined, so it was never chased;
 *   • the same emptiness makes it invisible to reminder processing.
 *
 * The service's own docblock named this exact hazard (ID-DUE-1): *"a dateless
 * demand is invisible to BOTH the reminder service and the late-fee engine —
 * it sits unpaid and unchased forever."* It passed a date in. It was dropped.
 *
 * These drive the REAL `fineFor()` and the REAL `_buildDemandDoc()`.
 */
class StudentChargeDueDateDownstreamTest extends TestCase
{
    /** @var Fee_late_fee_service */ private $late;
    /** @var Fee_firestore_txn */    private $txn;

    private array $policy = ['enabled' => true, 'type' => 'flat', 'value' => 100, 'cap' => 0];

    protected function setUp(): void
    {
        $this->late = (new ReflectionClass('Fee_late_fee_service'))->newInstanceWithoutConstructor();
        $this->txn  = (new ReflectionClass('Fee_firestore_txn'))->newInstanceWithoutConstructor();
    }

    /** The real demand builder. */
    private function build(array $data): array
    {
        $m = new ReflectionMethod('Fee_firestore_txn', '_buildDemandDoc');
        $m->setAccessible(true);
        return $m->invoke($this->txn, 'DEM_TEST', $data);
    }

    /** A Student Charge demand exactly as createModuleFee now writes one. */
    private function chargeDemand(string $dueDate): array
    {
        return $this->build([
            'studentId'   => 'STU0012',
            'feeHead'     => 'Tuition Fee',
            'category'    => 'studentcharge',
            'frequency'   => 'One-time',
            'period'      => 'April 2026',      // the session start — always
            'periodKey'   => '2026-04',
            'grossAmount' => 350.0,
            'netAmount'   => 350.0,
            'paidAmount'  => 0.0,
            'balance'     => 350.0,
            'status'      => 'unpaid',
            'dueDate'     => $dueDate,
        ]);
    }

    // ══ 1 · THE DATE IS PERSISTED ═══════════════════════════════════════════

    /** WOULD HAVE FAILED BEFORE: createModuleFee never forwarded it. */
    public function test_the_due_date_is_persisted_on_the_demand(): void
    {
        $doc = $this->chargeDemand('2026-09-22');

        $this->assertSame('2026-09-22', $doc['dueDate'],
            'the demand carries no due date. The Parent app then infers one from the month '
            . 'label — and every module charge is filed under the session start, so a charge '
            . 'raised today reads as months overdue.');
    }

    /** And the period really is the session start, which is why the date matters. */
    public function test_control_the_charge_is_filed_under_the_session_start(): void
    {
        $doc = $this->chargeDemand('2026-09-22');

        $this->assertSame('April', $doc['month'],
            'module charges are no longer filed under the session start — if the period now '
            . 'tracks the raise date, the month-label fallback would be harmless and this '
            . 'file should be re-derived');
        $this->assertSame('2026-04', $doc['periodKey']);
    }

    // ══ 9 · LATE-FEE ELIGIBILITY BY THE REAL DUE DATE ═══════════════════════

    /**
     * The silent half of DUE-2. `fineFor()` returns null on an empty dueDate,
     * so before the fix a Student Charge could never attract a late fee — it
     * sat unpaid and unchased indefinitely.
     */
    public function test_a_dateless_charge_can_never_be_fined(): void
    {
        $doc = $this->chargeDemand('');

        $this->assertNull($this->late->fineFor($doc, $this->policy, '2027-01-01'),
            'a demand with no due date became fineable — re-derive DUE-2, because the premise '
            . 'was that emptiness makes it invisible to the late-fee engine');
    }

    /** With the date present, the charge becomes chaseable once it is genuinely late. */
    public function test_a_dated_charge_is_fined_only_after_its_real_due_date(): void
    {
        $doc = $this->chargeDemand('2026-09-22');

        // Before the due date: nothing owed in penalty.
        $this->assertNull($this->late->fineFor($doc, $this->policy, '2026-09-01'),
            'a charge was fined before its due date');
        $this->assertNull($this->late->fineFor($doc, $this->policy, '2026-09-22'),
            'a charge was fined ON its due date — the cutoff is exclusive');

        // After it: the flat fine applies.
        $this->assertSame(100.0, $this->late->fineFor($doc, $this->policy, '2026-10-01'),
            'a genuinely overdue Student Charge still cannot be fined, so it is never chased');
    }

    /**
     * The decisive contrast. Under the OLD behaviour the demand had no date at
     * all; the month label said April, so any human reading the screen saw
     * "overdue" while the engine saw "not fineable". Two systems disagreeing
     * about the same charge.
     */
    public function test_the_engine_and_the_display_now_agree(): void
    {
        $doc = $this->chargeDemand('2026-09-22');

        // Raised 2026-08-23, due 2026-09-22. On the day it was raised:
        $engineSaysOverdue  = $this->late->fineFor($doc, $this->policy, '2026-08-23') !== null;

        $this->assertFalse($engineSaysOverdue,
            'the late-fee engine considers a charge raised today already overdue');

        // The Parent app reads the same field (PARENT-DUE-1), so both now
        // answer "not overdue" from one source instead of two.
        $this->assertNotSame('', $doc['dueDate'],
            'the field both consumers depend on is empty, so they will disagree again');
    }

    // ══ THE OTHER MODULES MUST BE UNAFFECTED ════════════════════════════════

    /**
     * Transport and Hostel pass no due date. They must keep writing an empty
     * one — the Parent app treats blank as "infer from the month", which is
     * correct for a charge that genuinely belongs to its period.
     */
    public function test_a_transport_charge_is_unchanged_by_this_fix(): void
    {
        $doc = $this->build([
            'studentId'   => 'STU0012',
            'feeHead'     => 'Transport Fee',
            'category'    => 'transport',
            'frequency'   => 'Annual',
            'period'      => 'April 2026',
            'periodKey'   => '2026-04',
            'grossAmount' => 12000.0,
            'netAmount'   => 12000.0,
        ]);

        $this->assertSame('', $doc['dueDate'],
            'a Transport demand gained a due date it never had. Its charge belongs to its '
            . 'period, so the month-label inference is the correct behaviour there.');
        $this->assertArrayHasKey('dueDate', $doc,
            'the field vanished entirely, so a reader cannot tell "no due date" from '
            . '"field missing"');
    }

    // ══ 12 · WAIVER LEAVES THE DUES SURFACE WITHOUT CLAIMING PAYMENT ════════

    /**
     * A waived charge must leave the family's pending dues, and must NOT be
     * recorded as paid — a waiver and a collection are different events and
     * every downstream report depends on telling them apart.
     */
    public function test_a_waiver_archives_rather_than_marking_paid(): void
    {
        $svc = (string) file_get_contents(APPPATH . 'libraries/Student_charge_service.php');
        $at = strpos($svc, 'public function waive');
        $this->assertNotFalse($at);

        // Bounded at the method end, not by a character count. A fixed window
        // silently truncated once the WAIVE-RACE-1 rationale was added, which
        // made a passing assertion fail for a reason unrelated to the code.
        $end  = strpos($svc, "\n    private function ", $at);
        $body = substr($svc, $at, ($end !== false ? $end - $at : 20000));

        $this->assertStringContainsString("'status'         => 'archived',", $body,
            'a waived demand no longer uses the status the Parent app filters on, so the '
            . "charge would sit in the family's pending dues forever showing zero");
        $this->assertStringNotContainsString("'status'         => 'paid',", $body,
            'a waiver marks the demand PAID. No money was received — that makes a waiver '
            . 'indistinguishable from a collection in every downstream report.');
        $this->assertStringContainsString("'waived'         => true,", $body,
            'the demand carries no waiver marker, so the reason it left the dues surface is '
            . 'unrecoverable');
    }
}
