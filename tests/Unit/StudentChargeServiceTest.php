<?php

use PHPUnit\Framework\TestCase;

if (!defined('BASEPATH')) define('BASEPATH', __DIR__);
if (!defined('APPPATH'))  define('APPPATH', __DIR__ . '/../../application/');
if (!function_exists('log_message')) { function log_message($l, $m) {} }

require_once APPPATH . 'libraries/Student_charge_service.php';

/**
 * ══════════════════════════════════════════════════════════════════════════
 *  STUDENT CHARGES — A ONE-OFF CHARGE AGAINST ONE STUDENT
 * ══════════════════════════════════════════════════════════════════════════
 *
 * A lost library book, a broken window, a trip contribution. Previously a
 * school could only bill these by creating a fee head that applies to a whole
 * CLASS — so a charge meant for one child hit thirty, or was collected in cash
 * off the books.
 *
 * This service is a GENERALISATION of the proven ID-card replacement path
 * (`Id_card_fee_policy` -> `Fee_lifecycle::createModuleFee`), not a new engine.
 * These tests drive the REAL service against doubles, so they exercise the
 * actual decisions rather than asserting on source text.
 *
 * The properties that matter are the ones the ID-card implementation had to get
 * right, and which a from-scratch design would plausibly have missed:
 *
 *   ID-D2     two charges on the same head must stay TWO demands
 *   ID-DUE-1  a one-off without a dueDate is invisible to the reminder service
 *             AND the late-fee engine — unpaid and unchased forever
 *   M5        an unregistered head must fail closed, not mint a head at runtime
 */
class StudentChargeServiceTest extends TestCase
{
    private const SCHOOL  = 'SCH_CHARGETEST';
    private const SESSION = '2026-27';
    private const STUDENT = 'STU7001';

    /**
     * A Firestore double that models the two things the waiver now depends on:
     *
     *   `__updateTime`  surfaced on every read, exactly as
     *                   Firestore_rest_client::decodeDocument does, and BUMPED
     *                   on every write — so a concurrent writer really does
     *                   move it.
     *   commitBatchStatus  atomic, with `currentDocument.updateTime`
     *                   preconditions evaluated BEFORE anything is applied, so
     *                   a conflicting batch writes nothing at all.
     *
     * Modelled rather than stubbed because WAIVE-RACE-1 is a concurrency
     * defect: a double that always succeeds would let the race back in while
     * the tests stayed green, which is the failure mode this programme keeps
     * finding in other people's test doubles.
     */
    private function firebase(array $seed = [])
    {
        return new class($seed) {
            public array $store;
            public array $writes = [];
            public array $batches = [];
            private int $clock = 1000;

            public function __construct(array $seed)
            {
                $this->store = [];
                foreach ($seed as $k => $v) $this->store[$k] = $this->stamp($v);
            }

            private function stamp(array $doc): array
            {
                $doc['__updateTime'] = '2026-08-23T00:00:' . sprintf('%02d', $this->clock++ % 60) . '.000000Z';
                return $doc;
            }

            public function firestoreGet($collection, $docId): ?array
            {
                return $this->store["{$collection}/{$docId}"] ?? null;
            }

            public function firestoreSet($collection, $docId, $data, $merge = false): bool
            {
                $key = "{$collection}/{$docId}";
                $this->writes[] = $key;
                $merged = ($merge && isset($this->store[$key]))
                    ? array_merge($this->store[$key], $data) : $data;
                $this->store[$key] = $this->stamp($merged);
                return true;
            }

            /**
             * Atomic. Every precondition is checked first; one failure means
             * NOTHING is written — the property the waiver relies on.
             */
            public function firestoreCommitBatchStatus(array $ops): array
            {
                $this->batches[] = $ops;

                foreach ($ops as $op) {
                    $pre = $op['precondition'] ?? null;
                    if (!is_array($pre) || !isset($pre['updateTime'])) continue;
                    $key = $op['collection'] . '/' . $op['docId'];
                    $cur = (string) ($this->store[$key]['__updateTime'] ?? '');
                    if ($cur !== (string) $pre['updateTime']) {
                        return ['status' => 'PRECONDITION_CONFLICT', 'code' => 400,
                                'message' => 'the document has moved'];
                    }
                }

                foreach ($ops as $op) {
                    $key = $op['collection'] . '/' . $op['docId'];
                    $this->writes[] = $key;
                    $data = is_array($op['data'] ?? null) ? $op['data'] : [];
                    $merged = (!empty($op['merge']) && isset($this->store[$key]))
                        ? array_merge($this->store[$key], $data) : $data;
                    $this->store[$key] = $this->stamp($merged);
                }

                return ['status' => 'SUCCESS', 'code' => 200, 'message' => ''];
            }

            /**
             * Seed a document the way Firestore would hold it — WITH an
             * `__updateTime`. Assigning into $store directly skips the stamp,
             * which models a document Firestore cannot produce.
             */
            public function seed(string $key, array $data): void
            {
                $this->store[$key] = $this->stamp($data);
            }
            /** Simulates another actor writing the document out from under us. */
            public function concurrentlyWrite(string $key, array $data): void
            {
                $this->store[$key] = $this->stamp(array_merge($this->store[$key] ?? [], $data));
            }
        };
    }

    /**
     * A Fee_lifecycle double. `$registered` models the M5 head registry:
     * createModuleFee returns null for an unregistered head (fail closed).
     */
    private function lifecycle(array $registered = ['Lost Book'], bool $throw = false)
    {
        return new class($registered, $throw) {
            public array $calls = [];
            public function __construct(private array $registered, private bool $throw) {}

            public function createModuleFee(string $studentId, string $moduleType, array $feeData): ?string
            {
                if ($this->throw) throw new RuntimeException('generator exploded');
                $this->calls[] = ['student' => $studentId, 'module' => $moduleType, 'data' => $feeData];

                if ((float) ($feeData['amount'] ?? 0) <= 0) return null;   // real behaviour
                if (!in_array($feeData['fee_head'] ?? '', $this->registered, true)) return null; // M5
                return 'DEM_' . substr(md5($studentId . ($feeData['chargeInstance'] ?? '')), 0, 12);
            }
        };
    }

    private function svc($firebase, $lifecycle, string $school = self::SCHOOL): Student_charge_service
    {
        return (new Student_charge_service())->init($firebase, $lifecycle, $school, self::SESSION, 'admin_1');
    }

    // ══ RAISING A CHARGE ════════════════════════════════════════════════════

    public function test_a_charge_creates_a_canonical_demand(): void
    {
        $fb = $this->firebase();
        $lc = $this->lifecycle();

        $r = $this->svc($fb, $lc)->raise([
            'studentId' => self::STUDENT,
            'feeHead'   => 'Lost Book',
            'amount'    => 350.0,
            'reason'    => 'Class 7 Science textbook, issued 12 Aug',
        ]);

        $this->assertTrue($r['ok'], (string) ($r['error'] ?? ''));
        $this->assertNotEmpty($r['demandId']);

        $this->assertCount(1, $lc->calls,
            'the charge must go through the canonical generator exactly once — a second '
            . 'write path would be a second billing engine');
        $this->assertSame(Student_charge_service::MODULE, $lc->calls[0]['module']);
        $this->assertSame(350.0, $lc->calls[0]['data']['amount']);
    }

    /**
     * ID-DUE-1. Without a dueDate the demand is invisible to BOTH the reminder
     * service and the late-fee engine — the family is never told and the charge
     * never ages.
     */
    public function test_a_charge_always_carries_a_due_date(): void
    {
        $lc = $this->lifecycle();
        $this->svc($this->firebase(), $lc)->raise([
            'studentId' => self::STUDENT, 'feeHead' => 'Lost Book',
            'amount' => 350.0, 'reason' => 'lost',
        ]);

        $due = $lc->calls[0]['data']['dueDate'] ?? '';
        $this->assertNotSame('', $due,
            'the demand carries no dueDate. Fee_reminder_service::stageFor() and '
            . 'Fee_late_fee_service::fineFor() both return null without one, so the charge '
            . 'would sit unpaid and unchased forever.');
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}$/', $due);
        $this->assertGreaterThan(date('Y-m-d'), $due, 'a due date must be in the future');
    }

    /**
     * ID-D2 — the defect that would silently lose money. Two "Lost Book"
     * charges in one period must be TWO demands; without a distinct
     * chargeInstance the second REWRITES the first.
     */
    public function test_two_charges_on_the_same_head_remain_two_demands(): void
    {
        $fb = $this->firebase();
        $lc = $this->lifecycle();
        $svc = $this->svc($fb, $lc);

        $a = $svc->raise(['studentId' => self::STUDENT, 'feeHead' => 'Lost Book', 'amount' => 350.0, 'reason' => 'first book']);
        $b = $svc->raise(['studentId' => self::STUDENT, 'feeHead' => 'Lost Book', 'amount' => 420.0, 'reason' => 'second book']);

        $this->assertTrue($a['ok']);
        $this->assertTrue($b['ok']);

        $this->assertNotSame($a['chargeId'], $b['chargeId'], 'each charge needs its own identity');
        $this->assertNotSame(
            $lc->calls[0]['data']['chargeInstance'],
            $lc->calls[1]['data']['chargeInstance'],
            'both charges were given the SAME chargeInstance. Fee_lifecycle derives the '
            . 'demand id from it, so the second charge would overwrite the first and the '
            . 'school would silently lose that money.'
        );
        $this->assertNotSame($a['demandId'], $b['demandId']);
    }

    // ══ REFUSALS ════════════════════════════════════════════════════════════

    /**
     * M5 — an unregistered head fails closed inside the generator. The refusal
     * must be surfaced with a message that points at the actual cause.
     */
    public function test_an_unregistered_fee_head_is_refused(): void
    {
        $r = $this->svc($this->firebase(), $this->lifecycle(['Lost Book']))->raise([
            'studentId' => self::STUDENT,
            'feeHead'   => 'Anonymous Charge',
            'amount'    => 500.0,
            'reason'    => 'something',
        ]);

        $this->assertFalse($r['ok'],
            'an unregistered head was accepted. M5 exists to stop anonymous income heads '
            . 'appearing at runtime with no chart-of-accounts mapping.');
        $this->assertStringContainsString('registered', strtolower($r['error']),
            'the refusal must name the real cause — "it did not work" sends an operator '
            . 'hunting in the wrong place');
    }

    /**
     * A charge against a family must say what for. Research on school fee policy
     * is consistent that the guardian receives written notice of the nature of
     * the damage; an unexplained charge is indistinguishable from a mistake.
     */
    public function test_a_charge_without_a_reason_is_refused(): void
    {
        $lc = $this->lifecycle();
        $r = $this->svc($this->firebase(), $lc)->raise([
            'studentId' => self::STUDENT, 'feeHead' => 'Lost Book', 'amount' => 350.0, 'reason' => '  ',
        ]);

        $this->assertFalse($r['ok']);
        $this->assertSame([], $lc->calls, 'nothing may be billed when the charge is refused');
    }

    public function test_a_zero_or_negative_amount_is_refused(): void
    {
        $lc = $this->lifecycle();
        $svc = $this->svc($this->firebase(), $lc);

        foreach ([0.0, -50.0] as $amount) {
            $r = $svc->raise([
                'studentId' => self::STUDENT, 'feeHead' => 'Lost Book',
                'amount' => $amount, 'reason' => 'x',
            ]);
            $this->assertFalse($r['ok'], "amount {$amount} was accepted");
        }
        $this->assertSame([], $lc->calls,
            'createModuleFee returns null for amount <= 0, so calling it would look like a '
            . 'silent success here — the refusal must happen before the call');
    }

    public function test_a_generator_failure_reports_that_nothing_was_billed(): void
    {
        $r = $this->svc($this->firebase(), $this->lifecycle(['Lost Book'], true))->raise([
            'studentId' => self::STUDENT, 'feeHead' => 'Lost Book', 'amount' => 350.0, 'reason' => 'x',
        ]);

        $this->assertFalse($r['ok']);
        $this->assertStringContainsString('Nothing has been billed', $r['error'],
            'a caller must be told whether money moved — ambiguity here produces a second '
            . 'charge raised "just in case"');
    }

    public function test_an_uninitialised_service_refuses(): void
    {
        $r = (new Student_charge_service())->raise([
            'studentId' => self::STUDENT, 'feeHead' => 'Lost Book', 'amount' => 1.0, 'reason' => 'x',
        ]);
        $this->assertFalse($r['ok']);
    }

    // ══ WAIVER ══════════════════════════════════════════════════════════════

    public function test_an_unpaid_charge_can_be_waived_and_the_demand_is_cleared(): void
    {
        $fb  = $this->firebase();
        $lc  = $this->lifecycle();
        $svc = $this->svc($fb, $lc);

        $raised = $svc->raise([
            'studentId' => self::STUDENT, 'feeHead' => 'Lost Book',
            'amount' => 350.0, 'reason' => 'lost book',
        ]);
        $fb->seed('feeDemands/' . $raised['demandId'], ['paidAmount' => 0.0, 'balance' => 350.0]);

        $w = $svc->waive($raised['chargeId'], 'Book was found');

        $this->assertTrue($w['ok'], (string) ($w['error'] ?? ''));
        $demand = $fb->store['feeDemands/' . $raised['demandId']];
        $this->assertSame(0.0, $demand['balance'], 'a waived charge must stop being owed');

        // PARENT-SC1 — the status must be one the Parent app already
        // understands. Its query excludes `status == "archived"` server-side and
        // then filters `status != "paid"` client-side, so a bespoke 'waived'
        // value passes BOTH and the charge sits in the family's pending dues
        // forever showing zero. 'archived' is the app's own name for "preserved
        // for audit lineage, not part of current dues".
        $this->assertSame('archived', $demand['status'],
            'the waived demand carries a status the Parent app does not filter on, so it '
            . "would remain visible in the family's pending dues at zero");
        $this->assertNotSame('paid', $demand['status'],
            'a waiver must never be recorded as paid — no money was received, and every '
            . 'downstream report would count it as a collection');
        $this->assertTrue($demand['waived'] ?? false,
            'the demand must still say it was WAIVED — "archived" alone loses the reason it left');
    }

    /**
     * THE ONE THAT MATTERS. Money has already moved: there is a receipt, an
     * allocation and a journal entry. Zeroing the demand would strand all three
     * and leave the books claiming income that was reversed with no
     * counter-entry. Returning money is a REFUND — a different operation with
     * its own authorization and accounting reversal.
     */
    public function test_a_paid_charge_cannot_be_waived(): void
    {
        $fb  = $this->firebase();
        $svc = $this->svc($fb, $this->lifecycle());

        $raised = $svc->raise([
            'studentId' => self::STUDENT, 'feeHead' => 'Lost Book',
            'amount' => 350.0, 'reason' => 'lost book',
        ]);
        $fb->seed('feeDemands/' . $raised['demandId'], ['paidAmount' => 350.0, 'balance' => 0.0]);

        $w = $svc->waive($raised['chargeId'], 'parent complained');

        $this->assertFalse($w['ok'],
            'a PAID charge was waived. The receipt, allocation and journal entry are now '
            . 'stranded and the books claim income that was reversed with no counter-entry.');
        $this->assertStringContainsString('refund', strtolower($w['error']),
            'the operator must be pointed at the correct operation, not just refused');

        $charge = $fb->store[self::SCHOOL . '_' . $raised['chargeId']]
            ?? $fb->store['studentCharges/' . self::SCHOOL . '_' . $raised['chargeId']];
        $this->assertSame('raised', $charge['status'], 'the charge must not be marked waived');
    }

    public function test_a_partially_paid_charge_cannot_be_waived(): void
    {
        $fb  = $this->firebase();
        $svc = $this->svc($fb, $this->lifecycle());

        $raised = $svc->raise([
            'studentId' => self::STUDENT, 'feeHead' => 'Lost Book',
            'amount' => 350.0, 'reason' => 'lost book',
        ]);
        $fb->seed('feeDemands/' . $raised['demandId'], ['paidAmount' => 100.0, 'balance' => 250.0]);

        $this->assertFalse($svc->waive($raised['chargeId'], 'partial')['ok'],
            'a partially paid charge was waived — the Rs.100 already collected has a '
            . 'receipt and a journal entry behind it');
    }

    public function test_a_waiver_requires_a_reason(): void
    {
        $fb  = $this->firebase();
        $svc = $this->svc($fb, $this->lifecycle());
        $raised = $svc->raise([
            'studentId' => self::STUDENT, 'feeHead' => 'Lost Book', 'amount' => 350.0, 'reason' => 'x',
        ]);

        $this->assertFalse($svc->waive($raised['chargeId'], '   ')['ok'],
            'a charge reversed against a family must record WHY, and by whom');
    }

    public function test_waiving_twice_is_idempotent(): void
    {
        $fb  = $this->firebase();
        $svc = $this->svc($fb, $this->lifecycle());
        $raised = $svc->raise([
            'studentId' => self::STUDENT, 'feeHead' => 'Lost Book', 'amount' => 350.0, 'reason' => 'x',
        ]);
        $fb->seed('feeDemands/' . $raised['demandId'], ['paidAmount' => 0.0]);

        $this->assertTrue($svc->waive($raised['chargeId'], 'found')['ok']);
        $second = $svc->waive($raised['chargeId'], 'found again');

        $this->assertTrue($second['ok'], 'a repeated waiver must not error');
        $this->assertTrue($second['alreadyWaived'] ?? false);
    }

    // ══ TENANT ISOLATION ════════════════════════════════════════════════════

    public function test_one_school_cannot_waive_another_schools_charge(): void
    {
        $fb = $this->firebase();

        $raised = $this->svc($fb, $this->lifecycle(), 'SCH_AAA')->raise([
            'studentId' => self::STUDENT, 'feeHead' => 'Lost Book', 'amount' => 350.0, 'reason' => 'x',
        ]);
        $this->assertTrue($raised['ok']);

        // School B seeds a same-id document pointing at School A's charge and
        // tries to waive it.
        $fb->store['studentCharges/SCH_BBB_' . $raised['chargeId']] = [
            'chargeId' => $raised['chargeId'],
            'schoolId' => 'SCH_AAA',            // belongs to A
            'demandId' => $raised['demandId'],
            'status'   => 'raised',
        ];

        $w = $this->svc($fb, $this->lifecycle(), 'SCH_BBB')->waive($raised['chargeId'], 'not mine');

        $this->assertFalse($w['ok'],
            "School B waived a charge belonging to School A. schoolId is the tenant "
            . 'boundary and must be verified on the document, not inferred from the id.');
    }

    public function test_a_charge_records_its_tenant_and_session(): void
    {
        $incident = 'Class 7 Science textbook, issued 12 Aug';

        $fb = $this->firebase();
        $r  = $this->svc($fb, $this->lifecycle())->raise([
            'studentId' => self::STUDENT, 'feeHead' => 'Lost Book',
            'amount' => 350.0, 'reason' => $incident,
        ]);

        $doc = $fb->store['studentCharges/' . self::SCHOOL . '_' . $r['chargeId']];
        $this->assertSame(self::SCHOOL, $doc['schoolId']);
        $this->assertSame(self::SESSION, $doc['session']);
        $this->assertSame('admin_1', $doc['raisedBy'], 'a charge must be attributable to who raised it');
        $this->assertSame($incident, $doc['reason'],
            'the reason must be persisted verbatim — it is the fact the family disputes or '
            . 'accepts, and "Lost Book" alone is a head, not an explanation');
    }

    // ══ NO SECOND BALANCE FORMULA ═══════════════════════════════════════════

    /**
     * Payment state lives on the DEMAND, in the canonical pipeline. A charge
     * record that tracked its own paid/balance would be a second billing engine
     * and a second thing to keep correct.
     */
    public function test_the_charge_record_keeps_no_payment_state(): void
    {
        $fb = $this->firebase();
        $r  = $this->svc($fb, $this->lifecycle())->raise([
            'studentId' => self::STUDENT, 'feeHead' => 'Lost Book', 'amount' => 350.0, 'reason' => 'x',
        ]);

        $doc = $fb->store['studentCharges/' . self::SCHOOL . '_' . $r['chargeId']];
        foreach (['paidAmount', 'balance', 'paid_amount', 'netAmount'] as $forbidden) {
            $this->assertArrayNotHasKey($forbidden, $doc,
                "the charge record carries '{$forbidden}'. Payment state belongs to the "
                . 'demand — duplicating it here creates a second balance formula that will '
                . 'drift from the canonical one.');
        }
    }
}
