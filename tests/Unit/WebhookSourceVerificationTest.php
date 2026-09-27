<?php

use PHPUnit\Framework\TestCase;

if (!defined('BASEPATH')) define('BASEPATH', 'x');
if (!defined('APPPATH')) define('APPPATH', __DIR__ . '/../../application/');

require_once APPPATH . 'libraries/Payment_service.php';

/**
 * ══════════════════════════════════════════════════════════════════════════
 *  PAY-OPS-4 · TWO SOURCES, TWO PROOFS, ONE STANDARD
 * ══════════════════════════════════════════════════════════════════════════
 *
 * ── THE DEFECT ───────────────────────────────────────────────────────────
 * `Payment_service::verify_payment()` had exactly one notion of authenticity:
 * the Razorpay BROWSER-RETURN signature, `HMAC(order_id|payment_id,
 * api_secret)`.
 *
 * A webhook never carries that. Razorpay signs a webhook over the RAW BODY
 * with the WEBHOOK secret, in the `X-Razorpay-Signature` header — which is why
 * `Payment_gateway_razorpay::parse_callback()` deliberately returns an EMPTY
 * signature for webhook shapes rather than inventing one.
 *
 * So every genuinely-signed webhook arrived with `signature === ''`,
 * `verify_signature()` refused on its first guard, and the handler answered
 * 422 "Payment signature verification failed" — a message that reads like
 * fraud and is actually a category error. The webhook could not reconcile any
 * payment, ever.
 *
 * Observed on real money: Razorpay captured ₹1,900 (pay_TV7acX8SsAmaf8 /
 * order_TV6eZr9M88GFTI) while the client's token expired mid-verification.
 * The webhook exists for precisely that case and could not settle it.
 *
 * ── WHAT THESE TESTS MUST PROVE ──────────────────────────────────────────
 * Not merely that the webhook now works. That is the easy half, and getting it
 * by weakening verification would be strictly worse than the bug. The load
 * bearing claims are:
 *
 *   1. the FRONTEND path is byte-for-byte unchanged  (cases 1, 2)
 *   2. the WEBHOOK path is not weaker — it requires an AUTHORITATIVE
 *      provider confirmation, on an allowlist                (cases 3, 9)
 *   3. a refusal distinguishes "final" from "try again"      (case 11)
 *
 * ── HOW Payment_service IS DRIVEN DIRECTLY ───────────────────────────────
 * `init()` calls `get_instance()`, which needs the CI framework. Nothing in
 * `verify_payment()` uses `$this->CI`, so the collaborators are injected by
 * reflection instead. This runs the REAL method — not a mirror of it — which
 * matters, because this repository has already been bitten by assertions that
 * passed while testing a copy.
 */
final class WebhookSourceVerificationTest extends TestCase
{
    // ══ TEST DOUBLES ════════════════════════════════════════════════════════

    private function firestore(array $seed = []): object
    {
        return new class($seed) {
            public array $store;
            public array $writes = [];
            public function __construct(array $seed) { $this->store = $seed; }
            public function firestoreGet(string $c, string $id): ?array
            {
                return $this->store["{$c}/{$id}"] ?? null;
            }
            public function firestoreSet(string $c, string $id, array $data, bool $merge = false): bool
            {
                $this->writes[] = ['col' => $c, 'id' => $id, 'data' => $data, 'merge' => $merge];
                $k = "{$c}/{$id}";
                $this->store[$k] = $merge && isset($this->store[$k])
                    ? array_merge($this->store[$k], $data) : $data;
                return true;
            }
            /** Every write made to one document, in order. */
            public function writesTo(string $c, string $id): array
            {
                return array_values(array_filter($this->writes,
                    fn($w) => $w['col'] === $c && $w['id'] === $id));
            }
        };
    }

    /**
     * A provider adapter whose two answers are set independently — which is the
     * whole point: the test must be able to present a VALID signature together
     * with a provider that says "not captured", and vice versa, to prove each
     * source consults only its own proof.
     *
     * @param mixed $fetch array | null | 'throw'
     */
    private function gateway(bool $sigValid, $fetch, string $name = 'razorpay'): object
    {
        return new class($sigValid, $fetch, $name) {
            public int $fetchCalls = 0;
            public int $sigCalls   = 0;
            public function __construct(private bool $sigValid, private $fetch, private string $name) {}
            public function verify_signature(string $o, string $p, string $s): bool
            {
                $this->sigCalls++;
                return $this->sigValid;
            }
            public function fetch_payment(string $paymentId): array
            {
                $this->fetchCalls++;
                if ($this->fetch === 'throw') {
                    throw new \RuntimeException('Razorpay: API unreachable');
                }
                if ($this->fetch === null) return [];
                return $this->fetch;
            }
            public function get_name(): string { return $this->name; }
            public function capabilities(): array { return ['reports_captured_amount' => true]; }
        };
    }

    private function service(object $fb, object $gw): Payment_service
    {
        $svc = new Payment_service();
        $r   = new ReflectionClass($svc);
        foreach ([
            'firebase' => $fb, 'gateway' => $gw,
            'adminId'  => 'parent_app', 'schoolId' => 'SCH_TEST', 'session' => '2026-27',
            'feesBase' => 'Schools/SCH_TEST/2026-27/Accounts/Fees',
        ] as $prop => $val) {
            $p = $r->getProperty($prop);
            $p->setAccessible(true);
            $p->setValue($svc, $val);
        }
        return $svc;
    }

    private const ORDER = 'order_TV6eZr9M88GFTI';
    private const PAY   = 'pay_TV7acX8SsAmaf8';
    private const DOC   = 'feeOnlineOrders/SCH_TEST_order_TV6eZr9M88GFTI';

    /** An unpaid order awaiting confirmation — ₹1,900, the real case. */
    private function pendingOrder(): array
    {
        return [self::DOC => [
            'schoolId' => 'SCH_TEST', 'session' => '2026-27', 'status' => 'created',
            'student_id' => 'STU0001', 'amount' => 1900.00, 'gateway' => 'razorpay',
        ]];
    }

    private function captured(float $rupees = 1900.00, string $status = 'captured', string $order = self::ORDER): array
    {
        return [
            'payment_id' => self::PAY, 'order_id' => $order,
            'amount' => $rupees, 'amount_paise' => (int) round($rupees * 100),
            'currency' => 'INR', 'status' => $status, 'captured' => $status === 'captured',
        ];
    }

    // ══ 1 · THE FRONTEND PATH IS UNCHANGED ══════════════════════════════════

    /** Case 1 · A valid browser-return signature still settles, via the signature. */
    public function test_a_valid_browser_return_signature_still_verifies(): void
    {
        $fb = $this->firestore($this->pendingOrder());
        $gw = $this->gateway(true, $this->captured());

        $r = $this->service($fb, $gw)->verify_payment(self::ORDER, self::PAY, 'good_sig');

        $this->assertTrue($r['verified'], 'the frontend return path no longer verifies');
        $this->assertSame('signature', $r['verified_via'],
            'the browser return is being verified by something other than its signature — '
            . 'the webhook mechanism must never replace the frontend one.');
        $this->assertSame(1, $gw->sigCalls, 'the browser return did not have its signature checked');
    }

    /** Case 2 · An invalid browser-return signature is still refused. */
    public function test_an_invalid_browser_return_signature_is_still_refused(): void
    {
        $fb = $this->firestore($this->pendingOrder());
        // The provider WOULD confirm this payment as captured. The frontend
        // path must not care: a forged callback has no signature, and letting
        // the fetch rescue it would hand a forged return the same authority as
        // a verified one.
        $gw = $this->gateway(false, $this->captured());

        $r = $this->service($fb, $gw)->verify_payment(self::ORDER, self::PAY, 'forged');

        $this->assertFalse($r['verified'],
            'a forged browser return was accepted because the provider happened to '
            . 'confirm the payment. Frontend authenticity is the signature, full stop.');
        $this->assertStringContainsString('signature', strtolower($r['error']));
        $this->assertFalse($r['webhook_retryable'], 'a forged signature is not retryable');
        $this->assertSame('failed', $fb->store[self::DOC]['status']);
    }

    /**
     * An unrecognised source must take the STRICTER branch. A typo in a caller
     * must not be able to open the weaker door.
     */
    public function test_an_unrecognised_source_falls_back_to_signature_verification(): void
    {
        $fb = $this->firestore($this->pendingOrder());
        $gw = $this->gateway(false, $this->captured());

        $r = $this->service($fb, $gw)->verify_payment(self::ORDER, self::PAY, '', 'WEBHOOK');

        $this->assertFalse($r['verified'],
            "the source 'WEBHOOK' (wrong case) was treated as a webhook. Anything that is "
            . 'not exactly SOURCE_WEBHOOK must fall back to signature verification.');
        $this->assertSame(1, $gw->sigCalls);
    }

    // ══ 2 · THE WEBHOOK PATH ════════════════════════════════════════════════

    /**
     * Case 3 · THE HEADLINE. A webhook carries NO signature and must still
     * settle when the provider's own record says the money was captured.
     */
    public function test_a_webhook_settles_on_provider_confirmation_without_any_signature(): void
    {
        $fb = $this->firestore($this->pendingOrder());
        // sigValid FALSE on purpose: a real webhook cannot satisfy it. If this
        // path consulted the signature at all, this test would fail.
        $gw = $this->gateway(false, $this->captured());

        $r = $this->service($fb, $gw)->verify_payment(
            self::ORDER, self::PAY, '', Payment_service::SOURCE_WEBHOOK);

        $this->assertTrue($r['verified'],
            'a genuinely-signed webhook still cannot reconcile a captured payment. This is '
            . 'the ₹1,900 case: the gateway took the money, the client never came back, and '
            . 'the one mechanism built for it refused.');
        $this->assertSame('provider_fetch', $r['verified_via']);
        $this->assertSame(0, $gw->sigCalls,
            'the webhook path consulted verify_signature(). A webhook body carries no '
            . 'order|payment signature, so any reliance on it is a category error.');
    }

    /**
     * Case 9 · The provider says the payment is NOT captured → no settlement,
     * and the refusal is TERMINAL: asking again gets the same answer.
     */
    public function test_a_webhook_refuses_when_the_provider_does_not_report_captured(): void
    {
        foreach (['failed', 'created', 'refunded', ''] as $status) {
            $fb = $this->firestore($this->pendingOrder());
            $gw = $this->gateway(true, $this->captured(1900.00, $status));

            $r = $this->service($fb, $gw)->verify_payment(
                self::ORDER, self::PAY, 'even_a_valid_sig', Payment_service::SOURCE_WEBHOOK);

            $this->assertFalse($r['verified'],
                "provider status '{$status}' was accepted as settleable");
            $this->assertFalse($r['webhook_retryable'],
                "provider status '{$status}' was marked retryable — the provider has "
                . 'answered and the answer will not change on redelivery.');
            $this->assertSame('failed', $fb->store[self::DOC]['status']);
        }
    }

    /**
     * THE ALLOWLIST, AND WHY IT IS NOT A DENYLIST.
     *
     * `authorized` means the funds are HELD. The authorization can still expire
     * or be voided. A denylist ("anything that isn't failed") settles it and
     * issues a receipt for money the school may never receive.
     */
    public function test_an_authorized_but_uncaptured_payment_never_settles(): void
    {
        $fb = $this->firestore($this->pendingOrder());
        $gw = $this->gateway(true, $this->captured(1900.00, 'authorized'));

        $r = $this->service($fb, $gw)->verify_payment(
            self::ORDER, self::PAY, '', Payment_service::SOURCE_WEBHOOK);

        $this->assertFalse($r['verified'],
            'an AUTHORIZED payment settled. The money is only held at that point; a '
            . 'receipt issued now can be for funds that never arrive.');
    }

    /**
     * PROVENANCE. The frontend gets order↔payment binding free — its signature
     * is computed over `order_id|payment_id`. The webhook HMAC covers the body
     * but says nothing about which order a payment belongs to, so the binding
     * is asserted from the provider's own record instead.
     */
    public function test_a_webhook_refuses_a_payment_that_belongs_to_a_different_order(): void
    {
        $fb = $this->firestore($this->pendingOrder());
        $gw = $this->gateway(true, $this->captured(1900.00, 'captured', 'order_SOMEONE_ELSE'));

        $r = $this->service($fb, $gw)->verify_payment(
            self::ORDER, self::PAY, '', Payment_service::SOURCE_WEBHOOK);

        $this->assertFalse($r['verified'],
            'a captured payment minted against a DIFFERENT order was applied to this one.');
        $this->assertFalse($r['webhook_retryable']);
        $this->assertStringContainsString('different order', $r['error']);
    }

    // ══ 3 · RETRYABILITY — THE DISTINCTION THAT SAVES THE MONEY ═════════════

    /**
     * Case 11 (verification half) · The provider is unreachable. Nothing is
     * known, so nothing is concluded: no settlement, no `failed` stamp on the
     * order, and the refusal is RETRYABLE so the gateway's redelivery survives.
     */
    public function test_an_unreachable_provider_is_retryable_and_marks_nothing_failed(): void
    {
        foreach (['throw' => 'API error', null => 'empty result'] as $mode => $label) {
            $fb = $this->firestore($this->pendingOrder());
            $gw = $this->gateway(true, $mode === 'throw' ? 'throw' : null);

            $r = $this->service($fb, $gw)->verify_payment(
                self::ORDER, self::PAY, '', Payment_service::SOURCE_WEBHOOK);

            $this->assertFalse($r['verified'], "[{$label}] settled without confirmation");
            $this->assertTrue($r['webhook_retryable'],
                "[{$label}] an unconfirmable payment was marked FINAL. The gateway may "
                . 'hold real money; discarding its retry is how a payment is lost.');
            $this->assertSame('created', $fb->store[self::DOC]['status'],
                "[{$label}] the order was stamped 'failed' on a provider outage. We do not "
                . 'know that it failed — only that we could not ask.');
        }
    }

    /** An order already paid short-circuits identically on BOTH sources. */
    public function test_an_already_paid_order_short_circuits_on_both_sources(): void
    {
        foreach ([Payment_service::SOURCE_FRONTEND, Payment_service::SOURCE_WEBHOOK] as $src) {
            $seed = $this->pendingOrder();
            $seed[self::DOC]['status'] = 'paid';
            $fb = $this->firestore($seed);
            $gw = $this->gateway(true, $this->captured());

            $r = $this->service($fb, $gw)->verify_payment(self::ORDER, self::PAY, 'x', $src);

            $this->assertTrue($r['verified']);
            $this->assertTrue($r['already_paid'], "[{$src}] a paid order was reprocessed");
            $this->assertSame(0, $gw->fetchCalls, "[{$src}] a settled order still hit the provider");
        }
    }

    /**
     * Case 14 (verification half) · The frontend return and the webhook race.
     * Whichever loses sees `processing` — and for a webhook that is a "come
     * back later", never a rejection, or the loser's retry is thrown away.
     */
    public function test_an_order_being_processed_is_retryable_for_a_webhook(): void
    {
        $seed = $this->pendingOrder();
        $seed[self::DOC]['status'] = 'processing';
        $fb = $this->firestore($seed);
        $gw = $this->gateway(true, $this->captured());

        $r = $this->service($fb, $gw)->verify_payment(
            self::ORDER, self::PAY, '', Payment_service::SOURCE_WEBHOOK);

        $this->assertFalse($r['verified']);
        $this->assertTrue($r['webhook_retryable'],
            'a webhook that lost the race against the browser return was told the payment '
            . 'was rejected. It must be told to come back.');
    }

    // ══ 4 · AMOUNT ENFORCEMENT SURVIVES THE NEW BRANCH ══════════════════════

    /**
     * Case 10 · The authoritative amount must still reach the caller on the
     * webhook path — MONEY-1's exact-paise comparison depends on it. A webhook
     * that settled without an amount to compare would be weaker than the
     * frontend, which is the one thing this change must never be.
     */
    public function test_the_webhook_still_supplies_the_authoritative_amount(): void
    {
        $fb = $this->firestore($this->pendingOrder());
        // Provider captured ₹1,899.99 against a ₹1,900.00 order — one paise
        // short. verify_payment reports; the caller refuses.
        $gw = $this->gateway(true, $this->captured(1899.99));

        $r = $this->service($fb, $gw)->verify_payment(
            self::ORDER, self::PAY, '', Payment_service::SOURCE_WEBHOOK);

        $this->assertTrue($r['verified']);
        $this->assertSame(189999, $r['gateway_amount_minor'],
            'the exact minor-unit amount is not reaching the caller on the webhook path, '
            . 'so MONEY-1 falls back to the ±₹0.50 float tolerance — 50x wider than the '
            . 'error it exists to absorb.');
        $this->assertSame(1899.99, $r['gateway_amount']);
    }

    /**
     * ONE FETCH, NOT TWO.
     *
     * The fetch that AUTHORISED the payment must be the same one the amount is
     * checked against. Two calls could disagree — a capture landing between
     * them — and then the payment would be authorised against one answer and
     * validated against another.
     */
    public function test_the_webhook_asks_the_provider_exactly_once(): void
    {
        $fb = $this->firestore($this->pendingOrder());
        $gw = $this->gateway(true, $this->captured());

        $this->service($fb, $gw)->verify_payment(
            self::ORDER, self::PAY, '', Payment_service::SOURCE_WEBHOOK);

        $this->assertSame(1, $gw->fetchCalls,
            'the webhook path called the provider ' . $gw->fetchCalls . ' times. The fetch '
            . 'that authorised the payment must also be the one the amount is compared '
            . 'against, or the two can disagree.');
    }

    // ══ 5 · PROVENANCE IS RECORDED, NOT INFERRED ════════════════════════════

    public function test_the_order_records_which_proof_was_presented(): void
    {
        $fb = $this->firestore($this->pendingOrder());
        $this->service($fb, $this->gateway(false, $this->captured()))
             ->verify_payment(self::ORDER, self::PAY, '', Payment_service::SOURCE_WEBHOOK);

        $doc = $fb->store[self::DOC];
        $this->assertSame('provider_fetch', $doc['verified_via'],
            'reconciliation cannot tell which proof settled this payment. Inferring it from '
            . 'an empty signature is not safe — "no signature" is also what a bug looks like.');
        $this->assertSame('webhook', $doc['verified_source']);

        $fb2 = $this->firestore($this->pendingOrder());
        $this->service($fb2, $this->gateway(true, $this->captured()))
             ->verify_payment(self::ORDER, self::PAY, 'sig');
        $this->assertSame('signature', $fb2->store[self::DOC]['verified_via']);
        $this->assertSame('frontend',  $fb2->store[self::DOC]['verified_source']);
    }

    /**
     * Every write to the order document must be a MERGE. The snapshot was read
     * before a network call, and a webhook can land during it — a full replace
     * would silently revert whatever the other path wrote, including 'paid'.
     */
    public function test_every_order_write_is_a_merge(): void
    {
        $fb = $this->firestore($this->pendingOrder());
        $this->service($fb, $this->gateway(true, $this->captured(1900.00, 'failed')))
             ->verify_payment(self::ORDER, self::PAY, '', Payment_service::SOURCE_WEBHOOK);

        $writes = $fb->writesTo('feeOnlineOrders', 'SCH_TEST_' . self::ORDER);
        $this->assertNotEmpty($writes, 'the refusal was not recorded at all');
        foreach ($writes as $w) {
            $this->assertTrue($w['merge'],
                'an order write replaced the whole document. A concurrent webhook/return '
                . "can land between the read and this write; a replace reverts its 'paid'.");
        }
    }
}
