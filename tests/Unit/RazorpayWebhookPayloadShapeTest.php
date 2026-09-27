<?php

use PHPUnit\Framework\TestCase;

if (!defined('BASEPATH')) define('BASEPATH', 'x');
if (!defined('APPPATH')) define('APPPATH', __DIR__ . '/../../application/');

require_once APPPATH . 'libraries/PaymentProviderInterface.php';
require_once APPPATH . 'libraries/Payment_gateway_razorpay.php';

/**
 * ══════════════════════════════════════════════════════════════════════════
 *  A WEBHOOK IS NOT SHAPED LIKE A BROWSER RETURN
 * ══════════════════════════════════════════════════════════════════════════
 *
 * ── PAY-OPS-3 · THE DEFECT ───────────────────────────────────────────────
 * `Payment_gateway_razorpay::parse_callback()` read only TOP-LEVEL keys
 * (`razorpay_order_id`, `order_id`, `payment_id`, …) and its own docblock
 * claimed the webhook was "the same shape either way".
 *
 * It is not. Razorpay posts the entity nested:
 *
 *     { "event": "payment.captured",
 *       "payload": { "payment": { "entity": {
 *           "id": "pay_xxx", "order_id": "order_yyy" } } } }
 *
 * so every lookup fell through to '' and the webhook could not identify the
 * payment it had just been told about.
 *
 * ── WHY THIS WAS INVISIBLE ───────────────────────────────────────────────
 * The failure is silent and late. HMAC verification passes (it signs the raw
 * body, which is perfectly valid), the handler then finds no ids and does
 * nothing, and returns 200. Razorpay records a successful delivery. Nothing
 * anywhere says "I could not read this".
 *
 * It could also only be found by looking at a REAL provider payload — no unit
 * test of the old code would fail, because the tests fed it browser-return
 * shapes, which it handled correctly. The sibling
 * `Payment_gateway_cashfree::parse_callback()` had always unwrapped its own
 * nesting; this adapter simply never did.
 *
 * ── WHY IT MATTERS OPERATIONALLY ─────────────────────────────────────────
 * The webhook is the ONLY path that reconciles a payment when the client
 * never returns — the parent closes the app, or loses signal, after paying.
 * With this defect the gateway holds the money and ZenX issues no receipt,
 * which is precisely the case the webhook exists to cover.
 */
final class RazorpayWebhookPayloadShapeTest extends TestCase
{
    private Payment_gateway_razorpay $gw;

    protected function setUp(): void
    {
        // No constructor: parse_callback is pure and needs no credentials.
        $this->gw = (new ReflectionClass(Payment_gateway_razorpay::class))
            ->newInstanceWithoutConstructor();
    }

    /** A real `payment.captured` webhook body, trimmed to the parsed fields. */
    private function capturedWebhook(string $pay, string $order): array
    {
        return [
            'event'   => 'payment.captured',
            'payload' => ['payment' => ['entity' => [
                'id' => $pay, 'order_id' => $order, 'status' => 'captured',
            ]]],
        ];
    }

    // ── THE WEBHOOK SHAPE ────────────────────────────────────────────────

    public function test_a_captured_webhook_yields_both_ids(): void
    {
        $out = $this->gw->parse_callback($this->capturedWebhook('pay_ABC', 'order_XYZ'));
        $this->assertSame('pay_ABC',  $out['payment_id'],
            'the webhook payment id was not found — the handler cannot identify the '
            . 'payment and silently does nothing');
        $this->assertSame('order_XYZ', $out['order_id']);
    }

    public function test_a_failed_payment_webhook_yields_both_ids(): void
    {
        $out = $this->gw->parse_callback([
            'event'   => 'payment.failed',
            'payload' => ['payment' => ['entity' => ['id' => 'pay_F', 'order_id' => 'order_F']]],
        ]);
        $this->assertSame('pay_F', $out['payment_id']);
        $this->assertSame('order_F', $out['order_id']);
    }

    public function test_order_paid_prefers_the_payment_entity(): void
    {
        // `order.paid` carries BOTH entities. The payment entity is the one
        // that identifies the money; order.entity.id is only an order id.
        $out = $this->gw->parse_callback([
            'event'   => 'order.paid',
            'payload' => [
                'order'   => ['entity' => ['id' => 'order_D']],
                'payment' => ['entity' => ['id' => 'pay_D', 'order_id' => 'order_D']],
            ],
        ]);
        $this->assertSame('pay_D',   $out['payment_id']);
        $this->assertSame('order_D', $out['order_id']);
    }

    public function test_order_entity_is_the_fallback_for_the_order_id_only(): void
    {
        // If a variant ever arrives without a payment entity, the order id is
        // still recoverable — but no payment id may be invented.
        $out = $this->gw->parse_callback([
            'event'   => 'order.paid',
            'payload' => ['order' => ['entity' => ['id' => 'order_E']]],
        ]);
        $this->assertSame('order_E', $out['order_id']);
        $this->assertSame('', $out['payment_id'],
            'a payment id was fabricated from an order entity');
    }

    // ── THE BROWSER RETURN MUST STILL WORK ───────────────────────────────

    public function test_the_browser_return_shape_is_unchanged(): void
    {
        // The control. The fix must not "fix" the webhook by breaking the
        // path that was already correct and is used by every payment today.
        $out = $this->gw->parse_callback([
            'razorpay_order_id'   => 'order_A',
            'razorpay_payment_id' => 'pay_A',
            'razorpay_signature'  => 'sig_A',
        ]);
        $this->assertSame('order_A', $out['order_id']);
        $this->assertSame('pay_A',   $out['payment_id']);
        $this->assertSame('sig_A',   $out['signature']);
    }

    public function test_the_generic_provider_neutral_keys_still_work(): void
    {
        // P8 hypothetical-provider proof: a normalized event from another
        // provider reaches the same call sites.
        $out = $this->gw->parse_callback([
            'gateway_order_id' => 'order_G', 'gateway_payment_id' => 'pay_G',
        ]);
        $this->assertSame('order_G', $out['order_id']);
        $this->assertSame('pay_G',   $out['payment_id']);
    }

    public function test_top_level_keys_win_over_nested_ones(): void
    {
        // A browser return never carries `payload`, so this only arises if a
        // caller merges shapes. The explicit callback fields are the more
        // specific statement and must not be overridden by an envelope.
        $out = $this->gw->parse_callback(array_merge(
            $this->capturedWebhook('pay_NESTED', 'order_NESTED'),
            ['razorpay_payment_id' => 'pay_TOP', 'razorpay_order_id' => 'order_TOP']
        ));
        $this->assertSame('pay_TOP',   $out['payment_id']);
        $this->assertSame('order_TOP', $out['order_id']);
    }

    // ── SIGNATURE IS DELIBERATELY ABSENT FOR WEBHOOKS ────────────────────

    public function test_a_webhook_body_yields_no_signature(): void
    {
        // A webhook is signed in the X-Razorpay-Signature HEADER against the
        // raw body. Returning a signature from the body would let a caller
        // believe the payload was self-verifying.
        $out = $this->gw->parse_callback($this->capturedWebhook('pay_S', 'order_S'));
        $this->assertSame('', $out['signature'],
            'a signature was produced from a webhook body — webhooks are signed '
            . 'in the header, and this would misrepresent the payload as verified');
    }

    // ── MALFORMED INPUT MUST NOT EXPLODE ─────────────────────────────────

    /**
     * @dataProvider malformedBodies
     */
    public function test_a_malformed_body_yields_empty_strings(array $body, string $why): void
    {
        $out = $this->gw->parse_callback($body);
        $this->assertSame('', $out['order_id'], $why);
        $this->assertSame('', $out['payment_id'], $why);
    }

    public static function malformedBodies(): array
    {
        return [
            'empty'                  => [[], 'an empty body produced an id'],
            'payload not an array'   => [['payload' => 'nope'], 'a scalar payload was indexed'],
            'payment not an array'   => [['payload' => ['payment' => 'nope']], 'a scalar payment was indexed'],
            'entity not an array'    => [['payload' => ['payment' => ['entity' => 7]]], 'a scalar entity was indexed'],
            'entity empty'           => [['payload' => ['payment' => ['entity' => []]]], 'an empty entity produced an id'],
            'unrelated event'        => [['event' => 'refund.created'], 'an unrelated event produced an id'],
        ];
    }

    public function test_whitespace_is_still_trimmed_on_the_nested_path(): void
    {
        // The trimming strengthening documented on the original method must
        // apply to the webhook path too, or a padded id silently mismatches.
        $out = $this->gw->parse_callback([
            'payload' => ['payment' => ['entity' => [
                'id' => "  pay_W\n", 'order_id' => " order_W ",
            ]]],
        ]);
        $this->assertSame('pay_W',   $out['payment_id']);
        $this->assertSame('order_W', $out['order_id']);
    }
}
