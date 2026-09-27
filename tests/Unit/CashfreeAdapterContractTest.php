<?php

use PHPUnit\Framework\TestCase;

if (!defined('APPPATH')) define('APPPATH', __DIR__ . '/../../application/');
if (!function_exists('log_message')) { function log_message($level, $msg) {} }

require_once APPPATH . 'libraries/PaymentProviderInterface.php';
require_once APPPATH . 'libraries/Payment_gateway_cashfree.php';
require_once APPPATH . 'libraries/Payment_gateway_selector.php';

/**
 * ══════════════════════════════════════════════════════════════════════════
 *  P17 — CASHFREE-SPECIFIC PROTOCOL TESTS
 * ══════════════════════════════════════════════════════════════════════════
 *
 * The SHARED contract suite (PaymentProviderContractTest) already proves
 * Cashfree satisfies every property Billing V2 depends on. This file adds only
 * what is genuinely provider-specific — the parts of Cashfree's protocol that
 * differ from Razorpay's and therefore cannot be covered generically.
 *
 * ── ⚠ IMPLEMENTED, NOT SUPPORTED ─────────────────────────────────────────
 * No live Cashfree API call is made here, and none has ever been made: ZenX
 * has no Cashfree merchant credentials. Everything below exercises pure,
 * local logic (HMAC, field normalization, fail-closed branches) against
 * Cashfree's published specification. That is genuinely strong evidence for
 * the signature algorithm — which is pure arithmetic — and NO evidence at all
 * about live API behaviour. The adapter is deliberately unregistered for
 * exactly that reason; see `test_cashfree_is_not_yet_selectable`.
 */
class CashfreeAdapterContractTest extends TestCase
{
    private function adapter(array $params = []): Payment_gateway_cashfree
    {
        return new Payment_gateway_cashfree(array_merge(
            ['api_key' => 'cf_client_id', 'api_secret' => 'cf_client_secret', 'mode' => 'test'],
            $params
        ));
    }

    // ══ WEBHOOK SIGNATURE — Base64(HMAC-SHA256(timestamp + rawBody)) ═══════

    /**
     * The documented algorithm, computed independently in the test rather
     * than by calling the same helper the implementation uses — otherwise a
     * wrong algorithm would agree with itself and pass.
     *
     * Source: https://www.cashfree.com/docs/payments/online/webhooks/overview
     */
    public function test_a_correctly_signed_webhook_is_accepted(): void
    {
        $secret    = 'cf_webhook_secret';
        $timestamp = '1756000000';
        $rawBody   = '{"type":"PAYMENT_SUCCESS_WEBHOOK","data":{"order":{"order_id":"FEE_STU1"}}}';

        $signature = base64_encode(hash_hmac('sha256', $timestamp . $rawBody, $secret, true));

        $this->assertTrue(
            $this->adapter()->verify_webhook_signature(
                ['x-webhook-signature' => $signature, 'x-webhook-timestamp' => $timestamp],
                $rawBody,
                ['webhook_secret' => $secret]
            ),
            'a webhook signed exactly as Cashfree documents was rejected'
        );
    }

    /** THE critical negative: a tampered body must invalidate the signature. */
    public function test_a_tampered_body_is_rejected(): void
    {
        $secret    = 'cf_webhook_secret';
        $timestamp = '1756000000';
        $rawBody   = '{"amount":700}';
        $signature = base64_encode(hash_hmac('sha256', $timestamp . $rawBody, $secret, true));

        $this->assertFalse(
            $this->adapter()->verify_webhook_signature(
                ['x-webhook-signature' => $signature, 'x-webhook-timestamp' => $timestamp],
                '{"amount":7}',   // tampered — amount reduced
                ['webhook_secret' => $secret]
            ),
            'a webhook whose body was altered after signing was accepted'
        );
    }

    /**
     * The timestamp is part of the signed string, so replaying a body under a
     * different timestamp must also fail.
     */
    public function test_a_replayed_timestamp_invalidates_the_signature(): void
    {
        $secret  = 'cf_webhook_secret';
        $rawBody = '{"x":1}';
        $signature = base64_encode(hash_hmac('sha256', '1756000000' . $rawBody, $secret, true));

        $this->assertFalse(
            $this->adapter()->verify_webhook_signature(
                ['x-webhook-signature' => $signature, 'x-webhook-timestamp' => '1756009999'],
                $rawBody,
                ['webhook_secret' => $secret]
            )
        );
    }

    /** Header names are case-insensitive in HTTP; the check must be too. */
    public function test_header_lookup_is_case_insensitive(): void
    {
        $secret = 's'; $ts = '1'; $body = '{}';
        $sig = base64_encode(hash_hmac('sha256', $ts . $body, $secret, true));

        $this->assertTrue(
            $this->adapter()->verify_webhook_signature(
                ['X-Webhook-Signature' => $sig, 'X-Webhook-Timestamp' => $ts],
                $body, ['webhook_secret' => $secret]
            ),
            'differently-cased headers were not recognised — a real gateway may send any casing'
        );
    }

    /** Every missing input fails CLOSED. Absence must never mean "trusted". */
    public function test_missing_signature_timestamp_or_secret_all_fail_closed(): void
    {
        $a = $this->adapter();
        $body = '{}';

        $this->assertFalse($a->verify_webhook_signature(
            ['x-webhook-timestamp' => '1'], $body, ['webhook_secret' => 's']), 'missing signature was accepted');

        $this->assertFalse($a->verify_webhook_signature(
            ['x-webhook-signature' => 'x'], $body, ['webhook_secret' => 's']), 'missing timestamp was accepted');

        $this->assertFalse($a->verify_webhook_signature(
            ['x-webhook-signature' => 'x', 'x-webhook-timestamp' => '1'], $body, []), 'missing secret was accepted');

        $this->assertFalse($a->verify_webhook_signature([], $body, []), 'a bare webhook was accepted');
    }

    /**
     * ── THE EMPTY-SECRET FORGERY. FOUND BY MUTATION TESTING. ──────────────
     *
     * The test above is NOT sufficient, and mutation testing proved it:
     * removing the explicit empty-input guard from the adapter left that test
     * still passing, because `hash_equals` rejects the ARBITRARY signature
     * ('x') it supplies regardless.
     *
     * But an attacker does not supply an arbitrary signature. When no webhook
     * secret is configured, the HMAC key is the EMPTY STRING — a value the
     * attacker knows exactly as well as we do. They can therefore compute a
     * genuinely valid HMAC under that empty key, and without the guard
     * `hash_equals` would return TRUE and settle a forged payment.
     *
     * This test performs that actual forgery. It is the only assertion in the
     * file that the guard is load-bearing rather than decorative.
     */
    public function test_an_unconfigured_secret_cannot_be_exploited_by_signing_with_the_empty_key(): void
    {
        $timestamp = '1756000000';
        $rawBody   = '{"type":"PAYMENT_SUCCESS_WEBHOOK","data":{"order":{"order_id":"FEE_FORGED"}}}';

        // The attacker signs with the empty string — the key in force when no
        // webhook secret is configured. This is a VALID HMAC under that key.
        $forgedSignature = base64_encode(hash_hmac('sha256', $timestamp . $rawBody, '', true));

        $this->assertFalse(
            $this->adapter()->verify_webhook_signature(
                ['x-webhook-signature' => $forgedSignature, 'x-webhook-timestamp' => $timestamp],
                $rawBody,
                ['webhook_secret' => '']        // not configured
            ),
            'a webhook forged by signing with the EMPTY key was accepted while no webhook '
            . 'secret is configured. An unconfigured secret must refuse outright — it must '
            . 'never degrade into "anyone who knows the key is empty can sign".'
        );
    }

    /** Same forgery, with the secret key absent from the array entirely. */
    public function test_the_empty_key_forgery_also_fails_when_the_secret_key_is_absent(): void
    {
        $timestamp = '1756000000';
        $rawBody   = '{"x":1}';
        $forged = base64_encode(hash_hmac('sha256', $timestamp . $rawBody, '', true));

        $this->assertFalse(
            $this->adapter()->verify_webhook_signature(
                ['x-webhook-signature' => $forged, 'x-webhook-timestamp' => $timestamp],
                $rawBody,
                []
            ),
            'the empty-key forgery succeeded when no secret field was present at all'
        );
    }

    // ══ THE CLIENT-CALLBACK SIGNATURE MUST REFUSE ═════════════════════════

    /**
     * Cashfree has no client-callback body signature of Razorpay's kind.
     * Refusing unconditionally is correct and deliberate: returning true
     * would give a forged client callback the same authority as a verified
     * webhook. This pins that decision so it is never "helpfully" relaxed.
     */
    public function test_client_callback_signature_verification_always_refuses(): void
    {
        $this->assertFalse(
            $this->adapter()->verify_signature('order_1', 'pay_1', 'anything'),
            'Cashfree\'s verify_signature() returned true. It has no client-callback body '
            . 'signature — trusting one would let a forged callback settle a payment.'
        );
    }

    // ══ FIELD NORMALIZATION ═══════════════════════════════════════════════

    /** Webhook payloads nest under data.order / data.payment. */
    public function test_it_normalizes_a_nested_webhook_payload(): void
    {
        $parsed = $this->adapter()->parse_callback([
            'type' => 'PAYMENT_SUCCESS_WEBHOOK',
            'data' => [
                'order'   => ['order_id' => 'FEE_STU0012_20260824'],
                'payment' => ['cf_payment_id' => '5114910', 'payment_status' => 'SUCCESS'],
            ],
        ]);

        $this->assertSame('FEE_STU0012_20260824', $parsed['order_id']);
        $this->assertSame('5114910', $parsed['payment_id']);
    }

    /** A flat payload (non-webhook shape) must normalize too. */
    public function test_it_normalizes_a_flat_payload(): void
    {
        $parsed = $this->adapter()->parse_callback([
            'order_id' => 'FEE_FLAT', 'cf_payment_id' => '99',
        ]);
        $this->assertSame('FEE_FLAT', $parsed['order_id']);
        $this->assertSame('99', $parsed['payment_id']);
    }

    public function test_missing_fields_normalize_to_empty_strings(): void
    {
        $this->assertSame(
            ['order_id' => '', 'payment_id' => '', 'signature' => ''],
            $this->adapter()->parse_callback([])
        );
    }

    // ══ NO FABRICATED IDENTITY, NO FABRICATED CAPTURE ═════════════════════

    /** Credentials missing → refuse, never attempt or fake an order. */
    public function test_create_order_refuses_without_credentials(): void
    {
        $this->expectException(\RuntimeException::class);
        (new Payment_gateway_cashfree(['api_key' => '', 'api_secret' => '']))
            ->create_order(700.0, 'FEE_X', ['student_id' => 'STU0012']);
    }

    /**
     * A customer_id too short for Cashfree's rules must throw rather than be
     * padded into something acceptable — a fabricated customer identity is
     * the same defect class as a fabricated provider identity.
     */
    public function test_create_order_refuses_an_invalid_customer_id_rather_than_inventing_one(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->adapter()->create_order(700.0, 'FEE_X', ['student_id' => 'X']);
    }

    public function test_create_order_refuses_a_non_positive_amount(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->adapter()->create_order(0.0, 'FEE_X', ['student_id' => 'STU0012']);
    }

    // ══ THE HONESTY GATE ══════════════════════════════════════════════════

    /**
     * THE most important test in this file. Cashfree is implemented against
     * documentation but has never touched the live API. It must therefore be
     * UNREACHABLE: unregistered in the registry, and rejected by the config
     * screen's allowlist. Shipping it as selectable on documentation alone
     * would recreate PA-UI-1 — a UI offering a provider the system cannot
     * actually honour — which this programme opened by fixing.
     */
    public function test_cashfree_is_not_yet_selectable(): void
    {
        $this->assertNull(Payment_gateway_selector::adapter_class('cashfree'),
            'Cashfree is registered in the provider registry, but it has never been tested '
            . 'against the live Cashfree API (no merchant credentials exist). Registering it '
            . 'makes it selectable by a real tenant for real money on documentation alone.');

        // Behavioural: the config screen's allowlist is DERIVED from the
        // catalogue now, so ask it directly rather than scraping a literal.
        $this->assertNotContains('cashfree',
            Payment_gateway_selector::selectable_provider_ids('production'),
            'the gateway config screen accepts cashfree before it has been live-verified');
        $this->assertNotContains('cashfree',
            Payment_gateway_selector::selectable_provider_ids('development'),
            'cashfree is selectable in development — an uncertified adapter must be '
            . 'unreachable in every environment, not merely in production');

        // And its lifecycle stage must say so explicitly, not by omission.
        $this->assertSame(Payment_gateway_selector::STAGE_IMPLEMENTED,
            Payment_gateway_selector::provider_stage('cashfree'),
            'cashfree was promoted past IMPLEMENTED without live API evidence');
    }
}
