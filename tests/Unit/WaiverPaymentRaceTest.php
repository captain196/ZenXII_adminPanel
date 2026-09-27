<?php

use PHPUnit\Framework\TestCase;

if (!defined('BASEPATH')) define('BASEPATH', __DIR__);
if (!defined('APPPATH'))  define('APPPATH', __DIR__ . '/../../application/');
if (!function_exists('log_message')) { function log_message($l, $m) {} }

require_once APPPATH . 'libraries/Student_charge_service.php';

/**
 * ══════════════════════════════════════════════════════════════════════════
 *  WAIVE-RACE-1 — A PAID CHARGE COULD STILL BE WAIVED TO ZERO
 * ══════════════════════════════════════════════════════════════════════════
 *
 * `Student_charge_service::waive()` refuses when money has been collected:
 *
 *     if ($paid > 0.005) return ['ok' => false, 'error' =>
 *         'This charge has already been paid. Raise a refund instead — waiving
 *          would leave the receipt and its accounting entry stranded.'];
 *
 * The rule is right. The enforcement was a plain read followed by two
 * unconditional merge writes, so a payment landing between them defeated it:
 *
 *   T1  admin waives    reads the demand, paidAmount = 0, guard passes
 *   T2  parent pays     paidAmount = 350, balance = 0, status 'paid'
 *   T1  continues       writes gross 0 / net 0 / balance 0 / status 'archived'
 *
 * The family has paid, a receipt and a journal entry exist, and the demand now
 * asserts the charge was never owed. Revenue is recognised against a charge
 * that no longer exists — exactly the stranded receipt the message warns about,
 * produced by the code that warns about it.
 *
 * ── WHY A TEST DOUBLE HAD TO BE FIXED FIRST ───────────────────────────────
 * The pre-existing double always succeeded and never surfaced `__updateTime`,
 * so a race was not expressible against it. It now models both: reads carry an
 * updateTime, every write bumps it, and `commitBatchStatus()` checks all
 * preconditions BEFORE applying anything. A double that cannot fail cannot
 * prove a concurrency fix — that is the same weakness this programme found in
 * `Phase156LateFeeEngineTest`, one layer down.
 *
 * ── THE SECOND DEFECT IN THE SAME PLACE ───────────────────────────────────
 * The charge was marked 'waived' first and the demand zeroed second. A failure
 * between them left the charge waived while the family still owed the money.
 * The code knew — it logged `ACC_STUDENT_CHARGE_WAIVER_DEMAND_STALE` and asked
 * the admin to retry — but the inconsistency was live by then. Both writes now
 * ride one atomic batch.
 */
class WaiverPaymentRaceTest extends TestCase
{
    private const SCHOOL  = 'SCH_RACETEST';
    private const SESSION = '2026-27';
    private const STUDENT = 'STU7001';

    /** Firestore, modelled: updateTime on read, bumped on write, atomic batch. */
    private function firebase()
    {
        return new class {
            public array $store = [];
            public array $batches = [];
            public int $commits = 0;
            private int $clock = 0;

            private function stamp(array $doc): array
            {
                $doc['__updateTime'] = 'T' . (++$this->clock);
                return $doc;
            }

            public function seed(string $key, array $data): void { $this->store[$key] = $this->stamp($data); }

            public function firestoreGet($collection, $docId): ?array
            {
                return $this->store["{$collection}/{$docId}"] ?? null;
            }

            public function firestoreSet($collection, $docId, $data, $merge = false): bool
            {
                $key = "{$collection}/{$docId}";
                $merged = ($merge && isset($this->store[$key]))
                    ? array_merge($this->store[$key], $data) : $data;
                $this->store[$key] = $this->stamp($merged);
                return true;
            }

            public function firestoreCommitBatchStatus(array $ops): array
            {
                $this->batches[] = $ops;
                $this->commits++;

                foreach ($ops as $op) {
                    $pre = $op['precondition'] ?? null;
                    if (!is_array($pre) || !isset($pre['updateTime'])) continue;
                    $key = $op['collection'] . '/' . $op['docId'];
                    if ((string) ($this->store[$key]['__updateTime'] ?? '') !== (string) $pre['updateTime']) {
                        return ['status' => 'PRECONDITION_CONFLICT', 'code' => 400, 'message' => 'moved'];
                    }
                }
                foreach ($ops as $op) {
                    $key  = $op['collection'] . '/' . $op['docId'];
                    $data = is_array($op['data'] ?? null) ? $op['data'] : [];
                    $merged = (!empty($op['merge']) && isset($this->store[$key]))
                        ? array_merge($this->store[$key], $data) : $data;
                    $this->store[$key] = $this->stamp($merged);
                }
                return ['status' => 'SUCCESS', 'code' => 200, 'message' => ''];
            }

            /** Another actor writes the doc, moving its updateTime. */
            public function payNow(string $key, array $data): void
            {
                $this->store[$key] = $this->stamp(array_merge($this->store[$key] ?? [], $data));
            }

            /** A read that carries no updateTime — the CAS-impossible case. */
            public function seedWithoutUpdateTime(string $key, array $data): void
            {
                $this->store[$key] = $data;
            }
        };
    }

    private function lifecycle()
    {
        return new class {
            public function createModuleFee(string $studentId, string $moduleType, array $feeData): ?string
            {
                if ((float) ($feeData['amount'] ?? 0) <= 0) return null;
                return 'DEM_' . substr(md5($studentId . ($feeData['chargeInstance'] ?? '')), 0, 12);
            }
        };
    }

    /** Raise a charge and seed its demand as unpaid, the pre-race state. */
    private function raised($fb): array
    {
        $svc = (new Student_charge_service())->init($fb, $this->lifecycle(), self::SCHOOL, self::SESSION, 'admin_1');
        $r   = $svc->raise([
            'studentId' => self::STUDENT, 'feeHead' => 'Lost Book',
            'amount' => 350.0, 'reason' => 'lost book',
        ]);
        $this->assertTrue($r['ok'], (string) ($r['error'] ?? ''));
        $fb->seed('feeDemands/' . $r['demandId'], ['paidAmount' => 0.0, 'balance' => 350.0, 'status' => 'unpaid']);

        return [$svc, $r];
    }

    // ══ CONTROL ═════════════════════════════════════════════════════════════

    /** With no interference the waiver must still work, or nothing below means anything. */
    public function test_control_an_uncontended_waiver_succeeds(): void
    {
        $fb = $this->firebase();
        [$svc, $r] = $this->raised($fb);

        $w = $svc->waive($r['chargeId'], 'Book was found');

        $this->assertTrue($w['ok'], (string) ($w['error'] ?? ''));
        $this->assertSame(0.0, $fb->store['feeDemands/' . $r['demandId']]['balance']);
        $this->assertSame('archived', $fb->store['feeDemands/' . $r['demandId']]['status']);
    }

    /** And the plain paid-check must still refuse the non-racing case. */
    public function test_control_an_already_paid_charge_is_refused_outright(): void
    {
        $fb = $this->firebase();
        [$svc, $r] = $this->raised($fb);
        $fb->seed('feeDemands/' . $r['demandId'], ['paidAmount' => 350.0, 'balance' => 0.0, 'status' => 'paid']);

        $w = $svc->waive($r['chargeId'], 'Book was found');

        $this->assertFalse($w['ok']);
        $this->assertStringContainsString('refund', strtolower((string) $w['error']));
    }

    // ══ THE RACE ════════════════════════════════════════════════════════════

    /**
     * WOULD HAVE FAILED BEFORE: the payment lands after the guard has passed,
     * and the old code wrote anyway.
     *
     * The payment is injected through the SAME store the service read from, so
     * it moves the demand's updateTime exactly as a real concurrent write does.
     */
    public function test_a_payment_landing_mid_waiver_is_detected_and_nothing_is_written(): void
    {
        $fb = $this->firebase();
        [$svc, $r] = $this->raised($fb);
        $key = 'feeDemands/' . $r['demandId'];

        // Arm the interleave: the parent pays the instant the service reads the
        // demand — i.e. after the paid check, before the commit.
        $fb->payNow($key, ['paidAmount' => 350.0, 'balance' => 0.0, 'status' => 'paid']);
        $stamp = $fb->store[$key]['__updateTime'];

        // The service is handed the PRE-payment view, which is what a real
        // waiver holds once the payment beats it to the write.
        $stale = new class($fb, $key) {
            public function __construct(private $fb, private string $key) {}
            public array $store = [];
            public function firestoreGet($c, $d)
            {
                $doc = $this->fb->firestoreGet($c, $d);
                if ($c === 'feeDemands') {
                    // What the waiver saw a moment before the payment landed.
                    return ['paidAmount' => 0.0, 'balance' => 350.0, 'status' => 'unpaid',
                            '__updateTime' => 'T1'];
                }
                return $doc;
            }
            public function firestoreSet($c, $d, $x, $m = false) { return $this->fb->firestoreSet($c, $d, $x, $m); }
            public function firestoreCommitBatchStatus(array $ops) { return $this->fb->firestoreCommitBatchStatus($ops); }
        };

        $svc2 = (new Student_charge_service())->init($stale, $this->lifecycle(), self::SCHOOL, self::SESSION, 'admin_1');
        $w = $svc2->waive($r['chargeId'], 'Book was found');

        $this->assertFalse($w['ok'],
            'a waiver committed against a demand that had been paid since it was read. The '
            . 'family has paid, a receipt and a journal entry exist, and the demand now says '
            . 'the charge was never owed — the stranded receipt the paid check exists to '
            . 'prevent, produced by the code that warns about it.');

        // NOTHING may have changed — the batch is atomic.
        $demand = $fb->store[$key];
        $this->assertSame(350.0, $demand['paidAmount'], 'the payment was overwritten');
        $this->assertSame('paid', $demand['status'],
            "the demand was archived despite carrying a payment");
        $this->assertSame($stamp, $demand['__updateTime'],
            'the demand was written even though the commit was refused — the rollback is not atomic');
    }

    /**
     * The refusal has to be actionable. "Try again" would be the wrong advice:
     * retrying succeeds, because the second read sees the payment and the plain
     * guard then refuses — but an admin who is told only "conflict" may keep
     * retrying rather than raising the refund the situation actually needs.
     */
    public function test_the_refusal_tells_the_admin_a_payment_may_have_arrived(): void
    {
        $fb = $this->firebase();
        [$svc, $r] = $this->raised($fb);
        $stale = ['paidAmount' => 0.0, 'balance' => 350.0, 'status' => 'unpaid', '__updateTime' => 'NEVER'];
        $fb->store['feeDemands/' . $r['demandId']] = array_merge($fb->store['feeDemands/' . $r['demandId']], []);

        $wrapper = new class($fb, $stale) {
            public function __construct(private $fb, private array $stale) {}
            public function firestoreGet($c, $d) { return $c === 'feeDemands' ? $this->stale : $this->fb->firestoreGet($c, $d); }
            public function firestoreSet($c, $d, $x, $m = false) { return $this->fb->firestoreSet($c, $d, $x, $m); }
            public function firestoreCommitBatchStatus(array $o) { return $this->fb->firestoreCommitBatchStatus($o); }
        };
        $svc2 = (new Student_charge_service())->init($wrapper, $this->lifecycle(), self::SCHOOL, self::SESSION, 'admin_1');

        $w = $svc2->waive($r['chargeId'], 'Book was found');

        $this->assertFalse($w['ok']);
        $err = strtolower((string) $w['error']);
        $this->assertStringContainsString('payment', $err,
            'the conflict message does not mention a payment, so an admin is left to guess');
        $this->assertStringContainsString('nothing has been changed', $err,
            'the message must state that nothing changed, or an admin may assume a partial '
            . 'waiver landed and start repairing state that is already consistent');
    }

    // ══ FAIL CLOSED ═════════════════════════════════════════════════════════

    /**
     * No updateTime means no precondition is possible. Committing anyway would
     * restore the race silently, which is the worst version — so the waiver
     * must refuse instead.
     */
    public function test_a_demand_with_no_update_time_is_refused_rather_than_written_blind(): void
    {
        $fb = $this->firebase();
        [$svc, $r] = $this->raised($fb);
        $fb->seedWithoutUpdateTime('feeDemands/' . $r['demandId'],
            ['paidAmount' => 0.0, 'balance' => 350.0, 'status' => 'unpaid']);

        $w = $svc->waive($r['chargeId'], 'Book was found');

        $this->assertFalse($w['ok'],
            'the waiver committed without a CAS precondition. With no updateTime there is '
            . 'nothing to detect a concurrent payment against, so this must fail closed.');
        $this->assertSame('unpaid', $fb->store['feeDemands/' . $r['demandId']]['status'],
            'the demand was modified on the fail-closed path');
    }

    // ══ ATOMICITY ═══════════════════════════════════════════════════════════

    /**
     * The charge and the demand must move together. Previously the charge was
     * marked 'waived' first, so a failure in between left it waived while the
     * family still owed the money.
     */
    public function test_the_charge_and_the_demand_are_written_in_one_commit(): void
    {
        $fb = $this->firebase();
        [$svc, $r] = $this->raised($fb);

        $svc->waive($r['chargeId'], 'Book was found');

        $this->assertSame(1, $fb->commits, 'the waiver used more than one commit');
        $this->assertCount(2, $fb->batches[0],
            'the charge and the demand are not in the same batch, so a failure between them '
            . 'can still leave a charge marked waived while the family still owes it');

        $collections = array_column($fb->batches[0], 'collection');
        $this->assertContains('studentCharges', $collections);
        $this->assertContains('feeDemands', $collections);
    }

    /** The demand write must carry the precondition; the charge write need not. */
    public function test_only_the_demand_write_carries_the_precondition(): void
    {
        $fb = $this->firebase();
        [$svc, $r] = $this->raised($fb);

        $svc->waive($r['chargeId'], 'Book was found');

        foreach ($fb->batches[0] as $op) {
            if ($op['collection'] === 'feeDemands') {
                $this->assertArrayHasKey('precondition', $op,
                    'the demand write has no updateTime precondition, so a concurrent payment '
                    . 'is undetectable');
                $this->assertArrayHasKey('updateTime', $op['precondition']);
            }
        }
    }

    /**
     * A merge write, not an overwrite. The demand carries identity, head,
     * receipt lineage and audit fields that a waiver has no business erasing.
     */
    public function test_the_demand_is_merged_not_overwritten(): void
    {
        $fb = $this->firebase();
        [$svc, $r] = $this->raised($fb);
        $key = 'feeDemands/' . $r['demandId'];
        $fb->seed($key, ['paidAmount' => 0.0, 'balance' => 350.0, 'status' => 'unpaid',
                         'feeHead' => 'Lost Book', 'studentId' => self::STUDENT]);

        $svc->waive($r['chargeId'], 'Book was found');

        $this->assertSame('Lost Book', $fb->store[$key]['feeHead'],
            'the waiver overwrote the demand instead of merging, destroying its identity and '
            . 'audit fields');
        $this->assertSame(self::STUDENT, $fb->store[$key]['studentId']);
    }
}
