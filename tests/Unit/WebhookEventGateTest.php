<?php

use PHPUnit\Framework\TestCase;

if (!defined('BASEPATH')) define('BASEPATH', 'x');
if (!defined('APPPATH')) define('APPPATH', __DIR__ . '/../../application/');

require_once APPPATH . 'libraries/PaymentProviderInterface.php';
require_once APPPATH . 'libraries/Payment_gateway_razorpay.php';
require_once APPPATH . 'libraries/Payment_gateway_cashfree.php';
require_once APPPATH . 'libraries/Payment_gateway_mock.php';
require_once APPPATH . 'libraries/Payment_gateway_unavailable.php';
require_once APPPATH . 'libraries/Payment_gateway_selector.php';

/**
 * ══════════════════════════════════════════════════════════════════════════
 *  PAY-OPS-4 · THE WEBHOOK EVENT GATE
 * ══════════════════════════════════════════════════════════════════════════
 *
 * ── THE DEFECT ───────────────────────────────────────────────────────────
 * `Fee_management::payment_webhook()` read NO event type. There was no branch
 * on `$payload['event']` anywhere between the HMAC check and the settlement
 * pipeline, so `payment.failed` took the identical path to `payment.captured`.
 *
 * The only thing standing between a failure notification and a settled fee
 * book was a signature check that a webhook could never satisfy — the pipeline
 * was protected by a *different bug*. Fixing that bug alone (so webhooks could
 * finally verify) would have made failure notifications settle fees. That is
 * why the gate and the source-specific verification shipped as one change.
 *
 * ── WHY CLASSIFICATION LIVES ON THE ADAPTER ──────────────────────────────
 * The vocabulary is the provider's. Razorpay names the field `event` and has
 * no cancellation event at all; Cashfree names it `type` and calls a
 * cancellation `PAYMENT_USER_DROPPED_WEBHOOK`. A single shared table would
 * have to invent a vocabulary neither provider speaks — the same reasoning
 * that already put `parse_callback()` on the adapter.
 *
 * ── THE PROVIDER-INDEPENDENCE CLAIM THIS FILE SUPPORTS ───────────────────
 * Every case below runs against MORE THAN ONE adapter. ZenX must not depend
 * on Razorpay alone, and "provider-agnostic" is a claim that needs a second
 * provider exercised through the same call sites to mean anything.
 */
final class WebhookEventGateTest extends TestCase
{
    private Payment_gateway_razorpay $rzp;
    private Payment_gateway_cashfree $cf;
    private Payment_gateway_mock $mock;
    private Payment_gateway_unavailable $none;

    protected function setUp(): void
    {
        // classify_event() is pure and needs no credentials, so the adapters
        // are built without constructors — the same technique
        // RazorpayWebhookPayloadShapeTest uses for parse_callback().
        $this->rzp  = (new ReflectionClass(Payment_gateway_razorpay::class))->newInstanceWithoutConstructor();
        $this->cf   = (new ReflectionClass(Payment_gateway_cashfree::class))->newInstanceWithoutConstructor();
        $this->mock = (new ReflectionClass(Payment_gateway_mock::class))->newInstanceWithoutConstructor();
        $this->none = (new ReflectionClass(Payment_gateway_unavailable::class))->newInstanceWithoutConstructor();
    }

    // ══ RAZORPAY — THE REAL CONTRACT ════════════════════════════════════════

    /** Case 3 · `payment.captured` is the event that reconciles a payment. */
    public function test_razorpay_payment_captured_is_success(): void
    {
        $v = $this->rzp->classify_event(['event' => 'payment.captured']);
        $this->assertSame('success', $v['class'],
            'payment.captured no longer classifies as success — the webhook can no '
            . 'longer reconcile a payment whose client never returned, which is the '
            . 'only reason the webhook exists.');
    }

    /** Case 4 · `order.paid` also settles — asserted, not assumed. */
    public function test_razorpay_order_paid_is_success(): void
    {
        $v = $this->rzp->classify_event(['event' => 'order.paid']);
        $this->assertSame('success', $v['class']);
    }

    /** Case 5 · A failed payment must never reach settlement. */
    public function test_razorpay_payment_failed_is_failure_not_success(): void
    {
        $v = $this->rzp->classify_event(['event' => 'payment.failed']);
        $this->assertSame('failure', $v['class'],
            'payment.failed is not classified as a failure. Before this gate existed it '
            . 'entered the same settlement pipeline as a capture.');
        $this->assertNotSame('success', $v['class']);
    }

    /**
     * THE TRAP IN RAZORPAY'S CATALOGUE.
     *
     * `payment.authorized` means the money is HELD, not captured. An
     * authorization can still expire or be voided. Treating it as success
     * issues a receipt for funds the school may never receive — and a denylist
     * ("anything that isn't failed") would do exactly that, which is why the
     * classifier is written as an allowlist.
     */
    public function test_razorpay_payment_authorized_does_not_settle(): void
    {
        $v = $this->rzp->classify_event(['event' => 'payment.authorized']);
        $this->assertSame('ignore', $v['class'],
            'payment.authorized is being treated as settleable. The funds are only HELD '
            . 'at that point; the capture event is what reconciles.');
    }

    public function test_razorpay_payment_pending_does_not_settle(): void
    {
        $this->assertSame('ignore', $this->rzp->classify_event(['event' => 'payment.pending'])['class']);
    }

    /**
     * Case 7 · Unknown events produce no financial side effect.
     *
     * Razorpay adds events over time. A new one must never fall through into
     * settlement just because nothing recognised it.
     */
    public function test_razorpay_unknown_events_are_ignored(): void
    {
        foreach ([
            'refund.processed', 'payment.dispute.created', 'settlement.processed',
            'subscription.charged', 'invoice.paid', 'virtual_account.credited',
            'some.event.invented.next.year',
        ] as $event) {
            $this->assertSame('ignore', $this->rzp->classify_event(['event' => $event])['class'],
                "'{$event}' is not classified as ignore — an unrecognised event must never "
                . 'be able to move money.');
        }
    }

    /**
     * A body with no `event` key is a browser return, not a webhook. It must
     * not classify as success: the frontend path authenticates with its own
     * signature and never consults this method, so arriving here without an
     * event means a malformed delivery.
     */
    public function test_razorpay_a_body_with_no_event_is_ignored(): void
    {
        $this->assertSame('ignore', $this->rzp->classify_event([])['class']);
        $this->assertSame('ignore', $this->rzp->classify_event(
            ['razorpay_order_id' => 'order_X', 'razorpay_payment_id' => 'pay_Y']
        )['class'], 'a browser-return shape classified as a settleable webhook event');
    }

    /**
     * RAZORPAY HAS NO CANCELLATION EVENT.
     *
     * The instruction to handle "payment.cancelled if actually supported" is
     * answered here: it is not. Handling an event a provider never sends is
     * dead code that reads like coverage, so it is deliberately absent — and
     * pinned as absent so nobody adds it back believing it does something.
     */
    public function test_razorpay_has_no_cancellation_event_and_does_not_pretend_to(): void
    {
        $v = $this->rzp->classify_event(['event' => 'payment.cancelled']);
        $this->assertSame('ignore', $v['class'],
            'a `payment.cancelled` handler has appeared for Razorpay. Razorpay does not '
            . 'emit that event; special-casing it implies a contract that does not exist.');

        $src = (string) file_get_contents(APPPATH . 'libraries/Payment_gateway_razorpay.php');
        $this->assertStringNotContainsString("=== 'payment.cancelled'", $src,
            'Payment_gateway_razorpay now branches on payment.cancelled — an event '
            . "Razorpay's webhook catalogue does not contain.");
    }

    // ══ CASHFREE — A DIFFERENT VOCABULARY, THE SAME THREE ANSWERS ═══════════

    public function test_cashfree_success_webhook_is_success(): void
    {
        $v = $this->cf->classify_event([
            'type' => 'PAYMENT_SUCCESS_WEBHOOK',
            'data' => ['payment' => ['payment_status' => 'SUCCESS']],
        ]);
        $this->assertSame('success', $v['class']);
    }

    public function test_cashfree_failed_webhook_is_failure(): void
    {
        $this->assertSame('failure',
            $this->cf->classify_event(['type' => 'PAYMENT_FAILED_WEBHOOK'])['class']);
    }

    /**
     * Case 6 · Cashfree DOES have a genuine cancellation — the payer opened
     * checkout and abandoned it. Handled because the provider actually sends
     * it, not for symmetry with Razorpay, which does not.
     */
    public function test_cashfree_user_dropped_is_a_cancellation_and_never_settles(): void
    {
        $v = $this->cf->classify_event(['type' => 'PAYMENT_USER_DROPPED_WEBHOOK']);
        $this->assertSame('failure', $v['class'],
            'an abandoned Cashfree checkout is not classified as unsuccessful. Cashfree '
            . 'genuinely emits this event; treating it as anything settleable would issue '
            . 'a receipt for a payment the parent never made.');
    }

    /**
     * A SUCCESS envelope wrapping a payment row that says otherwise is
     * contradictory. The safe reading of a contradiction is "not a success".
     */
    public function test_cashfree_refuses_a_success_envelope_that_contradicts_its_payment_row(): void
    {
        $v = $this->cf->classify_event([
            'type' => 'PAYMENT_SUCCESS_WEBHOOK',
            'data' => ['payment' => ['payment_status' => 'PENDING']],
        ]);
        $this->assertSame('ignore', $v['class'],
            'an envelope typed SUCCESS whose payment row says PENDING was accepted as a '
            . 'success. Two provider fields disagreeing is not a reason to pick the '
            . 'settling one.');
    }

    public function test_cashfree_unknown_and_empty_types_are_ignored(): void
    {
        $this->assertSame('ignore', $this->cf->classify_event(['type' => 'REFUND_STATUS_WEBHOOK'])['class']);
        $this->assertSame('ignore', $this->cf->classify_event([])['class']);
    }

    // ══ THE SIMULATOR AND THE REFUSING ADAPTER ══════════════════════════════

    public function test_the_mock_mirrors_the_real_vocabulary_so_the_pipeline_is_testable(): void
    {
        $this->assertSame('success', $this->mock->classify_event(['event' => 'payment.captured'])['class']);
        $this->assertSame('failure', $this->mock->classify_event(['event' => 'payment.failed'])['class']);
        $this->assertSame('ignore',  $this->mock->classify_event(['event' => 'nonsense'])['class']);
    }

    /** A school with no usable gateway has nothing to reconcile against. */
    public function test_the_unavailable_adapter_classifies_everything_as_ignore(): void
    {
        foreach (['payment.captured', 'order.paid', 'payment.failed', ''] as $event) {
            $this->assertSame('ignore', $this->none->classify_event(['event' => $event])['class'],
                'the refusing adapter classified an event as actionable. It is selected '
                . 'precisely when no provider is configured.');
        }
    }

    // ══ THE SELECTOR — ROUTING, AND THE FAIL-SAFE FALLBACK ══════════════════

    public function test_the_selector_routes_to_the_right_adapter(): void
    {
        $v = Payment_gateway_selector::classify_provider_event(
            ['provider' => 'razorpay'], ['event' => 'payment.captured']);
        $this->assertSame('success', $v['class']);
        $this->assertSame('razorpay', $v['provider']);

        $v = Payment_gateway_selector::classify_provider_event(
            ['provider' => 'razorpay'], ['event' => 'payment.failed']);
        $this->assertSame('failure', $v['class']);
    }

    /**
     * THE FALLBACK ASYMMETRY, MADE EXPLICIT.
     *
     * `parse_provider_callback()` falls back to a generic top-level parse when
     * the provider is unresolvable, because guessing an ID is harmless — a
     * wrong ID simply finds no order.
     *
     * Guessing an event CLASS is not harmless. If this returned success, an
     * unresolvable provider would settle fees. Every failure mode must land on
     * IGNORE.
     */
    public function test_an_unresolvable_provider_can_never_produce_a_success(): void
    {
        $cases = [
            'unknown provider'      => ['provider' => 'stripe'],
            'empty provider'        => ['provider' => ''],
            'missing provider key'  => ['mode' => 'live'],
            'config is not an array'=> null,
            'config is a string'    => 'razorpay',
            'provider is an array'  => ['provider' => ['razorpay']],
        ];

        foreach ($cases as $label => $config) {
            $v = Payment_gateway_selector::classify_provider_event($config, ['event' => 'payment.captured']);
            $this->assertSame('ignore', $v['class'],
                "[{$label}] classified a capture as settleable without being able to "
                . 'resolve a provider. An unresolvable provider must never move money.');
        }
    }

    /**
     * Cashfree is STAGE_IMPLEMENTED, so `adapter_class()` returns null for it
     * by design — an implemented-but-uncertified adapter must be unreachable
     * from every RUNTIME path, not merely hidden in the UI.
     *
     * That means its events classify as IGNORE at the selector even though the
     * adapter itself classifies them correctly (proven above). Both facts are
     * pinned: the adapter is ready, and the runtime gate is still closed. This
     * is the exact boundary that has to move — deliberately, with sandbox
     * evidence — before a school can collect through Cashfree.
     */
    public function test_cashfree_is_implemented_but_still_gated_at_the_runtime_boundary(): void
    {
        $this->assertSame('success',
            $this->cf->classify_event(['type' => 'PAYMENT_SUCCESS_WEBHOOK'])['class'],
            'the Cashfree adapter itself must be correct and ready');

        $this->assertNull(Payment_gateway_selector::adapter_class('cashfree'),
            'Cashfree became runtime-reachable. That is a release decision requiring '
            . 'sandbox certification against the real API and a Parent App that ships a '
            . 'webview checkout — not a side effect of a webhook change.');

        $v = Payment_gateway_selector::classify_provider_event(
            ['provider' => 'cashfree'], ['type' => 'PAYMENT_SUCCESS_WEBHOOK']);
        $this->assertSame('ignore', $v['class'],
            'a Cashfree webhook is settleable at runtime while the provider is still '
            . 'STAGE_IMPLEMENTED. The gate must fail closed until it is certified.');
    }

    /** An adapter that throws has told us nothing, and must not thereby win. */
    public function test_an_adapter_that_misbehaves_degrades_to_ignore(): void
    {
        $v = Payment_gateway_selector::classify_provider_event(
            ['provider' => 'razorpay'],
            // A payload shaped so `$body['event']` is an array: (string) cast of
            // an array raises, which is the realistic way an adapter throws on
            // a malformed delivery.
            ['event' => ['payment.captured']]
        );
        $this->assertSame('ignore', $v['class'],
            'a malformed payload that made the adapter throw was still classified as '
            . 'settleable.');
    }

    /**
     * Case 16 · A failure followed by a genuine capture.
     *
     * Both deliveries classify independently and correctly. Nothing about
     * seeing the failure first can prevent the later capture from settling —
     * they are different events and, in the handler, different dedup documents.
     */
    public function test_a_failure_does_not_poison_a_later_genuine_capture(): void
    {
        $failed   = Payment_gateway_selector::classify_provider_event(
            ['provider' => 'razorpay'], ['event' => 'payment.failed']);
        $captured = Payment_gateway_selector::classify_provider_event(
            ['provider' => 'razorpay'], ['event' => 'payment.captured']);

        $this->assertSame('failure', $failed['class']);
        $this->assertSame('success', $captured['class'],
            'a genuine capture arriving after a failed attempt on the same order no '
            . 'longer classifies as success — a parent whose first card is declined and '
            . 'whose second succeeds could never be reconciled.');
    }

    /** Whatever an adapter returns, only three classes may ever escape. */
    public function test_only_the_three_known_classes_can_be_returned(): void
    {
        $allowed = [
            Payment_gateway_selector::EVENT_SUCCESS,
            Payment_gateway_selector::EVENT_FAILURE,
            Payment_gateway_selector::EVENT_IGNORE,
        ];
        foreach (['payment.captured', 'payment.failed', 'refund.processed', ''] as $event) {
            foreach ([['provider' => 'razorpay'], ['provider' => 'mock'], ['provider' => 'nope'], null] as $cfg) {
                $v = Payment_gateway_selector::classify_provider_event($cfg, ['event' => $event]);
                $this->assertContains($v['class'], $allowed,
                    'the selector emitted a class the handler does not understand; an '
                    . 'unrecognised class must be normalised to ignore, not passed through.');
            }
        }
    }
}
