<?php

use PHPUnit\Framework\TestCase;

if (!defined('BASEPATH')) define('BASEPATH', __DIR__);
if (!defined('APPPATH'))  define('APPPATH', __DIR__ . '/../../application/');
if (!function_exists('log_message')) { function log_message($l, $m) {} }

require_once APPPATH . 'libraries/Fee_payment_claim.php';

/**
 * ══════════════════════════════════════════════════════════════════════════
 *  B-025 — ADVERSARIAL TESTS FOR THE EXCLUSIVE PAYMENT CLAIM
 * ══════════════════════════════════════════════════════════════════════════
 *
 * The guard this replaces read a lock, judged its age, then blindly SET it.
 * Two callers that both read before either wrote BOTH entered the money
 * pipeline — and Razorpay fires the webhook while the client callback runs,
 * so that interleaving is the normal case.
 *
 * These tests drive the REAL Fee_payment_claim against a Firestore double that
 * enforces the two semantics the fix depends on:
 *
 *   1. `create` on an existing document is a COLLISION, never an overwrite.
 *   2. an `update` carrying a `currentDocument.updateTime` precondition commits
 *      ONLY if the document has not moved since that version was read.
 *
 * The double therefore models document VERSIONS, not just contents — a
 * precondition test against a store with no versions would pass vacuously.
 *
 * What is NOT proven here: that one payment yields one receipt. That is a joint
 * property of this exclusion AND per-payment idempotency in FeeCollectionService,
 * and it has never been exercised against real money.
 */
class FeePaymentClaimTest extends TestCase
{
    private const SCHOOL = 'SCH_CLAIMTEST';
    private const ORDER  = 'order_ABC123';

    /**
     * A versioned Firestore double.
     *
     * Every document carries a monotonically increasing `__updateTime`, which is
     * what makes the CAS meaningful: a precondition can only be satisfied by the
     * exact version the caller read.
     */
    private function firebase(string $forceCreateStatus = '', bool $noCas = false)
    {
        if ($noCas) {
            // Models a client without the CAS primitive at all.
            return new class {
                public array $store = [], $creates = [];
                public function firestoreCreateStatus($c, $d, $data): array
                {
                    $this->creates[] = $d;
                    $k = "{$c}/{$d}";
                    if (isset($this->store[$k])) return ['status' => 'COLLISION', 'code' => 409, 'message' => ''];
                    $this->store[$k] = $data;
                    return ['status' => 'SUCCESS', 'code' => 200, 'message' => ''];
                }
                public function firestoreGet($c, $d): ?array { return $this->store["{$c}/{$d}"] ?? null; }
                public function firestoreDelete($c, $d): bool { unset($this->store["{$c}/{$d}"]); return true; }
            };
        }

        return new class($forceCreateStatus) {
            public array $store = [];
            public array $creates = [];
            public array $casAttempts = [];
            private int $clock = 1000;

            public function __construct(private string $force) {}

            /** Stamp a fresh server version onto a document. */
            private function version(): string
            {
                return '2026-08-22T00:00:' . str_pad((string) (++$this->clock), 6, '0', STR_PAD_LEFT) . 'Z';
            }

            public function seed(string $key, array $data): void
            {
                $this->store[$key] = $data + ['__updateTime' => $this->version()];
            }

            public function firestoreCreateStatus($collection, $docId, $data): array
            {
                $this->creates[] = $docId;
                if ($this->force !== '') {
                    return ['status' => $this->force, 'code' => 0, 'message' => 'forced'];
                }
                $key = "{$collection}/{$docId}";
                // THE semantic the fix rests on: create never overwrites.
                if (isset($this->store[$key])) {
                    return ['status' => 'COLLISION', 'code' => 409, 'message' => 'ALREADY_EXISTS'];
                }
                $this->store[$key] = $data + ['__updateTime' => $this->version()];
                return ['status' => 'SUCCESS', 'code' => 200, 'message' => ''];
            }

            public function firestoreGet($collection, $docId): ?array
            {
                return $this->store["{$collection}/{$docId}"] ?? null;
            }

            public function firestoreDelete($collection, $docId): bool
            {
                unset($this->store["{$collection}/{$docId}"]);
                return true;
            }

            /** Atomic commit with an optional currentDocument.updateTime CAS. */
            public function firestoreCommitBatchStatus(array $ops): array
            {
                foreach ($ops as $op) {
                    $key = "{$op['collection']}/{$op['docId']}";
                    $this->casAttempts[] = $key;

                    if (!isset($this->store[$key])) {
                        return ['status' => 'FAILURE', 'code' => 404, 'message' => 'missing'];
                    }
                    $want = (string) ($op['precondition']['updateTime'] ?? '');
                    $have = (string) ($this->store[$key]['__updateTime'] ?? '');

                    // No precondition = an unguarded write. The claim must never
                    // issue one; if it does, this surfaces as a distinct status
                    // rather than silently succeeding.
                    if ($want === '') {
                        return ['status' => 'UNGUARDED_WRITE', 'code' => 0, 'message' => 'no precondition supplied'];
                    }
                    if ($want !== $have) {
                        return ['status' => 'PRECONDITION_CONFLICT', 'code' => 400, 'message' => 'stored version mismatch'];
                    }
                    $this->store[$key] = array_merge($this->store[$key], $op['data']);
                    $this->store[$key]['__updateTime'] = $this->version();
                }
                return ['status' => 'SUCCESS', 'code' => 200, 'message' => ''];
            }
        };
    }

    private function claim($firebase, string $school = self::SCHOOL): Fee_payment_claim
    {
        $c = new Fee_payment_claim();
        $c->init($firebase, $school);
        return $c;
    }

    private function key(string $school = self::SCHOOL, string $order = self::ORDER): string
    {
        return "feeLocks/{$school}_{$order}";
    }

    private function seed($firebase, array $overrides): void
    {
        $firebase->seed($this->key(), array_merge([
            'schoolId' => self::SCHOOL,
            'orderId'  => self::ORDER,
            'locked'   => true,
            'token'    => 'PRIOR_TOKEN',
            'source'   => 'frontend',
        ], $overrides));
    }

    // ══ CONTROL — THE HARNESS CAN ACTUALLY SHOW THE DEFECT ══════════════════

    /**
     * A suite that only ever passes proves nothing. This drives the ALGORITHM
     * BEING REPLACED — read the lock, judge its age, then blindly SET it —
     * against the SAME double the real tests use, and requires it to fail in
     * the documented way.
     *
     * Writing it exposed something worth stating: run strictly SEQUENTIALLY the
     * previous guard DOES exclude, which is exactly why the defect survived
     * review. It only admits two winners when both callers read before either
     * writes — and that is the real ordering, because Razorpay fires the webhook
     * while the callback is still in flight.
     */
    public function test_control_the_previous_guards_algorithm_admits_two_winners(): void
    {
        $fb  = $this->firebase();
        $key = $this->key();

        $judgeFromRead = function (?array $existing): bool {
            if (is_array($existing) && !empty($existing['locked'])) {
                $age = time() - strtotime($existing['locked_at'] ?? '2000-01-01');
                if ($age < 120) return false;
            }
            return true;
        };
        $blindSet = function (string $source) use ($fb, $key): void {
            $fb->store[$key] = ['locked' => true, 'locked_at' => date('c'), 'source' => $source];
        };

        $frontendSaw = $fb->store[$key] ?? null;   // READ (frontend)
        $webhookSaw  = $fb->store[$key] ?? null;   // READ (webhook) ← before either write

        $frontendProceeds = $judgeFromRead($frontendSaw);
        $webhookProceeds  = $judgeFromRead($webhookSaw);
        if ($frontendProceeds) $blindSet('frontend');
        if ($webhookProceeds)  $blindSet('webhook');

        $this->assertTrue($frontendProceeds);
        $this->assertTrue($webhookProceeds,
            'the harness can no longer reproduce B-025 — if the previous guard now '
            . 'admits only one caller under an interleaved read/write, the double has '
            . 'stopped modelling a blind SET and the tests below prove nothing');
    }

    // ══ THE DEFECT ITSELF ═══════════════════════════════════════════════════

    public function test_only_one_of_two_simultaneous_claimants_acquires(): void
    {
        $fb = $this->firebase();

        $frontend = $this->claim($fb)->acquire(self::ORDER, 'frontend');
        $webhook  = $this->claim($fb)->acquire(self::ORDER, 'webhook');

        $acquired = array_filter(
            [$frontend, $webhook],
            fn($r) => $r['status'] === Fee_payment_claim::ACQUIRED
        );

        $this->assertCount(1, $acquired,
            'BOTH the frontend callback and the webhook entered the payment pipeline. '
            . 'This is B-025.');
        $this->assertSame(Fee_payment_claim::ACQUIRED, $frontend['status']);
        $this->assertSame(Fee_payment_claim::IN_PROGRESS, $webhook['status'],
            'the loser must be told the payment is in progress, not handed ownership');
        $this->assertSame('', $webhook['token'],
            'a loser must never receive a token — a token is the right to release');

        // WHY THIS IS ORDERING-INDEPENDENT, unlike the guard it replaces.
        // Both callers reached the atomic create: neither decided from a prior
        // read, so there is no read-then-write window to interleave on.
        $this->assertCount(2, $fb->creates,
            'a claimant skipped the atomic create and decided from a read — that '
            . 'reintroduces exactly the interleaving window B-025 is about');
    }

    public function test_a_large_burst_still_yields_exactly_one_owner(): void
    {
        $fb = $this->firebase();
        $winners = 0;
        for ($i = 0; $i < 40; $i++) {
            if ($this->claim($fb)->acquire(self::ORDER, "attempt{$i}")['status'] === Fee_payment_claim::ACQUIRED) {
                $winners++;
            }
        }
        $this->assertSame(1, $winners, '40 claimants produced more than one owner');
    }

    public function test_distinct_orders_never_contend(): void
    {
        $fb = $this->firebase();
        $a = $this->claim($fb)->acquire('order_AAA', 'frontend');
        $b = $this->claim($fb)->acquire('order_BBB', 'frontend');

        $this->assertSame(Fee_payment_claim::ACQUIRED, $a['status']);
        $this->assertSame(Fee_payment_claim::ACQUIRED, $b['status'],
            'two unrelated payments must not block each other');
        $this->assertNotSame($a['token'], $b['token']);
    }

    // ══ UNKNOWN IS NOT COLLISION ════════════════════════════════════════════

    public function test_a_transport_failure_is_UNKNOWN_and_never_grants_ownership(): void
    {
        $r = $this->claim($this->firebase('FAILURE'))->acquire(self::ORDER, 'webhook');

        $this->assertSame(Fee_payment_claim::UNKNOWN, $r['status']);
        $this->assertNotSame(Fee_payment_claim::ACQUIRED, $r['status'],
            'an undetermined write must NEVER be read as ownership');
        $this->assertNotSame(Fee_payment_claim::IN_PROGRESS, $r['status'],
            'collapsing FAILURE into IN_PROGRESS would silently drop a payment nobody owns');
        $this->assertSame('', $r['token']);
    }

    public function test_a_collision_whose_claim_cannot_be_read_is_UNKNOWN(): void
    {
        $r = $this->claim($this->firebase('COLLISION'))->acquire(self::ORDER, 'webhook');

        $this->assertSame(Fee_payment_claim::UNKNOWN, $r['status']);
        $this->assertSame('', $r['token']);
    }

    public function test_an_uninitialised_claim_service_refuses_rather_than_proceeding(): void
    {
        $r = (new Fee_payment_claim())->acquire(self::ORDER, 'frontend');
        $this->assertSame(Fee_payment_claim::UNKNOWN, $r['status']);
    }

    // ══ THE LEASE — RECOVERY WITHOUT A SECOND BLIND WRITE ═══════════════════

    public function test_a_live_lease_is_refused(): void
    {
        $fb = $this->firebase();
        $this->seed($fb, ['locked_at' => date('c'), 'expires_at' => date('c', time() + 90)]);

        $r = $this->claim($fb)->acquire(self::ORDER, 'webhook');

        $this->assertSame(Fee_payment_claim::IN_PROGRESS, $r['status'],
            'a process holding a live lease must not be displaced');
        $this->assertSame('PRIOR_TOKEN', $fb->store[$this->key()]['token'],
            "the live owner's token was overwritten — exclusivity was broken");
        $this->assertSame([], $fb->casAttempts,
            'no CAS should even be attempted against a live lease');
    }

    public function test_an_expired_lease_is_recovered_by_a_later_attempt(): void
    {
        $fb = $this->firebase();
        $this->seed($fb, [
            'locked_at'  => date('c', time() - 600),
            'expires_at' => date('c', time() - 480),
        ]);

        $r = $this->claim($fb)->acquire(self::ORDER, 'webhook');

        $this->assertSame(Fee_payment_claim::ACQUIRED, $r['status'],
            'an expired lease must be recoverable, or a crash wedges the order permanently');
        $this->assertNotSame('', $r['token']);

        $doc = $fb->store[$this->key()];
        $this->assertSame($r['token'], $doc['token']);
        $this->assertSame('PRIOR_TOKEN', $doc['taken_over_from'],
            'a takeover must record whose lease it displaced');
    }

    /**
     * Two processes both observe the SAME expired lease. The CAS precondition
     * carries the version each of them read, so the second finds the document
     * has moved — recovery must not reintroduce the read-then-blind-write defect
     * it exists to fix.
     */
    public function test_two_processes_recovering_the_same_expired_lease_yield_one_owner(): void
    {
        $fb = $this->firebase();
        $this->seed($fb, [
            'locked_at'  => date('c', time() - 600),
            'expires_at' => date('c', time() - 480),
        ]);

        $first  = $this->claim($fb)->acquire(self::ORDER, 'frontend');
        $second = $this->claim($fb)->acquire(self::ORDER, 'webhook');

        $this->assertSame(Fee_payment_claim::ACQUIRED, $first['status']);
        $this->assertSame(Fee_payment_claim::IN_PROGRESS, $second['status'],
            'the second recoverer stole a lease the first had already taken');
        $this->assertSame($first['token'], $fb->store[$this->key()]['token'],
            "the loser's takeover still landed on the document");
    }

    /**
     * THE CAS MUST BE REAL. If the claim ever issued the takeover WITHOUT a
     * precondition, the double reports UNGUARDED_WRITE — which the library maps
     * to UNKNOWN rather than success. This pins the precondition itself, not
     * merely the outcome.
     */
    public function test_the_takeover_always_carries_a_precondition(): void
    {
        $fb = $this->firebase();
        $this->seed($fb, ['expires_at' => date('c', time() - 480)]);

        $r = $this->claim($fb)->acquire(self::ORDER, 'webhook');

        $this->assertSame(Fee_payment_claim::ACQUIRED, $r['status'],
            'the takeover was issued without a currentDocument.updateTime precondition, '
            . 'i.e. as an unguarded write — that is the defect being fixed');
        $this->assertSame([$this->key()], $fb->casAttempts);
    }

    /**
     * A document with no version cannot be compare-and-swapped. Writing anyway
     * would clobber a concurrent takeover, so the claim must fail closed.
     */
    public function test_a_claim_without_a_version_fails_closed_rather_than_writing_unguarded(): void
    {
        $fb = $this->firebase();
        // Bypass seed() so no __updateTime is stamped.
        $fb->store[$this->key()] = [
            'schoolId' => self::SCHOOL, 'locked' => true, 'token' => 'PRIOR_TOKEN',
            'expires_at' => date('c', time() - 480),
        ];

        $r = $this->claim($fb)->acquire(self::ORDER, 'webhook');

        $this->assertSame(Fee_payment_claim::UNKNOWN, $r['status'],
            'without a version the takeover must refuse, never write unguarded');
        $this->assertSame([], $fb->casAttempts, 'an unguarded write was issued');
        $this->assertSame('PRIOR_TOKEN', $fb->store[$this->key()]['token']);
    }

    public function test_recovery_is_refused_when_the_CAS_primitive_is_unavailable(): void
    {
        $fb = $this->firebase('', true);   // client without firestoreCommitBatchStatus
        $fb->store[$this->key()] = [
            'locked' => true, 'token' => 'PRIOR_TOKEN',
            'expires_at' => date('c', time() - 480),
        ];

        $r = $this->claim($fb)->acquire(self::ORDER, 'webhook');

        $this->assertSame(Fee_payment_claim::UNKNOWN, $r['status'],
            'without a CAS primitive the takeover must fail closed, never fall back '
            . 'to an unguarded write');
    }

    // ══ LEGACY AND MALFORMED CLAIMS ═════════════════════════════════════════

    public function test_a_claim_written_by_the_previous_guard_still_expires(): void
    {
        $fb = $this->firebase();
        // The old guard wrote locked/locked_at/token and NO expires_at.
        $this->seed($fb, ['locked_at' => date('c', time() - 600)]);

        $r = $this->claim($fb)->acquire(self::ORDER, 'webhook');

        $this->assertSame(Fee_payment_claim::ACQUIRED, $r['status'],
            'a lease-less legacy claim must not be immortal — a rollback or an '
            . 'in-flight deploy would otherwise wedge every order it touched');
    }

    public function test_a_legacy_claim_inside_the_window_is_still_refused(): void
    {
        $fb = $this->firebase();
        $this->seed($fb, ['locked_at' => date('c', time() - 5)]);

        $this->assertSame(
            Fee_payment_claim::IN_PROGRESS,
            $this->claim($fb)->acquire(self::ORDER, 'webhook')['status']
        );
    }

    public function test_an_unparseable_timestamp_is_treated_as_expired(): void
    {
        $fb = $this->firebase();
        $this->seed($fb, ['expires_at' => 'not-a-date']);

        $this->assertSame(
            Fee_payment_claim::ACQUIRED,
            $this->claim($fb)->acquire(self::ORDER, 'webhook')['status'],
            'an unreadable timestamp must not make a claim immortal; the CAS '
            . 'takeover still enforces exclusivity'
        );
    }

    public function test_a_claim_with_no_timestamps_at_all_is_treated_as_expired(): void
    {
        $fb = $this->firebase();
        $this->seed($fb, []);   // locked=true, no locked_at, no expires_at

        $this->assertSame(
            Fee_payment_claim::ACQUIRED,
            $this->claim($fb)->acquire(self::ORDER, 'webhook')['status'],
            'a malformed claim must not wedge the order forever'
        );
    }

    // ══ TENANT SCOPING ══════════════════════════════════════════════════════

    public function test_the_claim_is_tenant_scoped(): void
    {
        $fb = $this->firebase();

        $a = $this->claim($fb, 'SCH_AAA')->acquire(self::ORDER, 'frontend');
        $b = $this->claim($fb, 'SCH_BBB')->acquire(self::ORDER, 'frontend');

        $this->assertSame(Fee_payment_claim::ACQUIRED, $a['status']);
        $this->assertSame(Fee_payment_claim::ACQUIRED, $b['status'],
            'the same gateway order id in a DIFFERENT tenant must not contend — '
            . 'claims are per-tenant documents');
        $this->assertArrayHasKey($this->key('SCH_AAA'), $fb->store);
        $this->assertArrayHasKey($this->key('SCH_BBB'), $fb->store);
    }

    /**
     * School A must not be able to release, take over, or otherwise disturb
     * School B's claim on the same gateway order id.
     */
    public function test_one_tenant_cannot_disturb_another_tenants_claim(): void
    {
        $fb = $this->firebase();
        $a  = $this->claim($fb, 'SCH_AAA');
        $b  = $this->claim($fb, 'SCH_BBB');

        $ownerA = $a->acquire(self::ORDER, 'frontend');
        $b->acquire(self::ORDER, 'frontend');

        // B releases with A's token — different document, must not match.
        $this->assertFalse($b->release(self::ORDER, $ownerA['token']));
        $this->assertArrayHasKey($this->key('SCH_AAA'), $fb->store,
            "another tenant's release deleted School A's live claim");
        $this->assertArrayHasKey($this->key('SCH_BBB'), $fb->store);
    }

    // ══ RELEASE ═════════════════════════════════════════════════════════════

    public function test_release_frees_the_order_for_a_subsequent_payment(): void
    {
        $fb = $this->firebase();
        $claim = $this->claim($fb);

        $first = $claim->acquire(self::ORDER, 'frontend');
        $this->assertTrue($claim->release(self::ORDER, $first['token']));

        // Asserted at the point the deletion happens — a later re-acquire
        // legitimately recreates the document and would mask this.
        $this->assertArrayNotHasKey($this->key(), $fb->store,
            'release must delete the claim, not merely unmark it');

        $second = $this->claim($fb)->acquire(self::ORDER, 'webhook');
        $this->assertSame(Fee_payment_claim::ACQUIRED, $second['status'],
            'after release the order must be claimable again — the claim is '
            . 'mutual exclusion only, never a terminal record');
        $this->assertNotSame($first['token'], $second['token'],
            'a fresh acquire must mint a new token, never inherit the released one');
    }

    public function test_release_with_a_stale_token_does_not_free_the_new_owner(): void
    {
        $fb = $this->firebase();
        $claim = $this->claim($fb);
        $claim->acquire(self::ORDER, 'frontend');

        $this->assertFalse($claim->release(self::ORDER, 'SOMEONE_ELSES_TOKEN'));
        $this->assertArrayHasKey($this->key(), $fb->store,
            'a non-owner deleted a live claim, breaking exclusivity for the real owner');
    }

    public function test_release_without_a_token_is_refused(): void
    {
        $fb = $this->firebase();
        $this->claim($fb)->acquire(self::ORDER, 'frontend');

        $this->assertFalse($this->claim($fb)->release(self::ORDER, ''),
            'an empty token must never match an owner');
        $this->assertArrayHasKey($this->key(), $fb->store);
    }

    /**
     * After being displaced by a legitimate takeover, the original owner must
     * NOT be able to release — doing so would strip the new owner's exclusivity
     * and let a third process in alongside it. This is the crash-recovery
     * sequence end to end.
     */
    public function test_a_displaced_owner_cannot_release_the_takeover_owners_claim(): void
    {
        $fb = $this->firebase();
        $original = $this->claim($fb);

        $stale = $original->acquire(self::ORDER, 'frontend');

        // Simulate the original process hanging until its lease expires.
        $fb->store[$this->key()]['expires_at'] = date('c', time() - 10);

        $recovered = $this->claim($fb)->acquire(self::ORDER, 'webhook');
        $this->assertSame(Fee_payment_claim::ACQUIRED, $recovered['status']);

        // The original now wakes up and tries to clean up after itself.
        $this->assertFalse($original->release(self::ORDER, $stale['token']),
            'a displaced owner released a claim it no longer holds');
        $this->assertSame($recovered['token'], $fb->store[$this->key()]['token'],
            "the recovered owner's exclusivity was destroyed by the process it replaced");
    }

    // ══ NO TERMINAL STATE — THE CORRECTED DESIGN ════════════════════════════

    /**
     * An earlier draft retained the claim as state:'done' carrying the receipt.
     * Because the claim is keyed by ORDER and an order may carry more than one
     * payment, that would have answered a SECOND, GENUINE payment with the
     * FIRST payment's receipt: money received, nothing applied.
     *
     * Per-payment idempotency belongs to processedPayments/{paymentId}, which
     * is keyed correctly. This pins the claim to exclusion only.
     */
    public function test_the_claim_never_records_a_terminal_outcome(): void
    {
        $fb = $this->firebase();
        $claim = $this->claim($fb);
        $r = $claim->acquire(self::ORDER, 'frontend');

        $doc = $fb->store[$this->key()];
        foreach (['state', 'receiptNo', 'receipt_key', 'receiptKey', 'paymentId'] as $forbidden) {
            $this->assertArrayNotHasKey($forbidden, $doc,
                "the claim carries '{$forbidden}'. Keyed by ORDER, a retained outcome "
                . 'would answer a second genuine payment on the same order with the '
                . "first payment's receipt.");
        }

        $claim->release(self::ORDER, $r['token']);
        $this->assertSame([], $fb->store, 'the claim must be deleted on release, not retained');
    }
}
