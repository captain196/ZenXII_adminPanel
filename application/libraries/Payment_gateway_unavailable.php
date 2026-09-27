<?php
defined('BASEPATH') OR exit('No direct script access allowed');

require_once APPPATH . 'libraries/PaymentProviderInterface.php';

/**
 * ══════════════════════════════════════════════════════════════════════════
 *  GW-1 — THE REFUSING GATEWAY ADAPTER
 * ══════════════════════════════════════════════════════════════════════════
 *
 * Installed when no usable gateway adapter can be resolved for a tenant:
 * an unsupported provider string, or no gateway configuration at all.
 *
 * ── WHY THIS EXISTS RATHER THAN A FALLBACK ────────────────────────────────
 * `Fee_management::_init_payment_service()` previously resolved
 * "razorpay → real adapter, EVERYTHING ELSE → Payment_gateway_mock". That
 * default was reachable two ways: a provider the config screen accepted but no
 * adapter implements (it accepted 'stripe' and 'paytm'), and — more commonly —
 * a tenant that has simply never configured a gateway.
 *
 * The mock is not a neutral stand-in for a real gateway:
 *
 *   • verify_signature() validates against 'mock_secret_key_for_dev', a
 *     constant in this repository. Anyone who can read the source can compute
 *     a signature that passes.
 *   • fetch_payment() returns `mock_skip_amount_check => true`, which makes
 *     Payment_service skip amount enforcement altogether.
 *   • it always reports status 'captured'.
 *
 * So fee collection on the mock mints receipts, clears demands and posts
 * accounting journals for money that was never paid — and the school's books
 * would show income that never arrived.
 *
 * ── THE CHOICE THIS CLASS ENCODES ─────────────────────────────────────────
 * Refusing to collect is RECOVERABLE: the school configures a supported
 * gateway and collection resumes, with nothing to unwind. Collecting through a
 * forgeable adapter is NOT recoverable: receipts, allocations and journals have
 * to be reversed, and every affected family has to be told.
 *
 * Every method therefore refuses in the safest direction for its own semantics
 * — never "true", never "captured", never a usable order.
 *
 * The admission module reached the same conclusion independently
 * (Admission_public.php:897 / :1126 refuse the mock outside development and
 * testing). This is the fees-side equivalent, made stronger by refusing an
 * unrecognised provider outright rather than relying only on an environment
 * check.
 *
 * P1 (2026-08-24): formally implements PaymentProviderInterface. This is
 * exactly what the class docblock above already argued for by construction —
 * implementing the FULL required surface, not a subset, so a missing method
 * can never raise an uncatchable Error where `catch (\Exception)` would let a
 * refusal fall through as a fatal. The interface now makes that guarantee
 * checkable by the language (`instanceof`), not just by convention.
 */
class Payment_gateway_unavailable implements PaymentProviderInterface
{
    private const REASON = 'No payment gateway is configured for this school. '
        . 'Please contact the school office.';

    /**
     * No order may be created. Returning a well-formed-looking order would let
     * the Parent app open a checkout that can never be honoured.
     */
    public function create_order(float $amount, string $receiptRef = '', array $meta = []): array
    {
        log_message('error', 'Payment_gateway_unavailable::create_order refused — no usable gateway adapter');
        return [
            'success'    => false,
            'order_id'   => '',
            'amount'     => 0.0,
            'currency'   => 'INR',
            'gateway'    => 'unavailable',
            'error_code' => 'GATEWAY_NOT_CONFIGURED',
            'error_msg'  => self::REASON,
        ];
    }

    /**
     * ALWAYS false. This is the single most important method in the class: it
     * is the check that a forged callback has to pass, and the mock's version
     * of it could be satisfied by anyone holding this source.
     */
    public function verify_signature(string $orderId, string $paymentId, string $signature): bool
    {
        log_message('error',
            'Payment_gateway_unavailable::verify_signature refused for order [' . $orderId . ']');
        return false;
    }

    /**
     * Reports a NON-captured payment and — deliberately — does NOT set
     * `mock_skip_amount_check`. Amount enforcement stays switched on, so no
     * caller can be talked out of validating an amount by this adapter.
     */
    public function fetch_payment(string $paymentId): array
    {
        return [
            'payment_id'   => $paymentId,
            'amount_paise' => 0,
            'amount'       => 0.0,
            'status'       => 'unavailable',
            'currency'     => 'INR',
            'gateway'      => 'unavailable',
            'error_code'   => 'GATEWAY_NOT_CONFIGURED',
            'error_msg'    => self::REASON,
            'fetched_at'   => date('c'),
        ];
    }

    /**
     * Interface parity with the mock. A simulated payment must never look
     * successful here, or a test harness pointed at a misconfigured tenant
     * would report a passing payment flow.
     */
    public function simulate_payment(string $orderId, float $amount): array
    {
        return [
            'payment_id' => '',
            'order_id'   => $orderId,
            'status'     => 'failed',
            'amount'     => 0.0,
            'currency'   => 'INR',
            'method'     => '',
            'signature'  => '',
            'error_code' => 'GATEWAY_NOT_CONFIGURED',
            'error_msg'  => self::REASON,
            'gateway'    => 'unavailable',
            'timestamp'  => date('c'),
        ];
    }

    /**
     * Distinct from 'mock'. Anything branching on the gateway name must be able
     * to tell "deliberately simulated" from "not configured" — they mean
     * different things operationally, and conflating them is how the fallback
     * became invisible in the first place.
     */
    public function get_name(): string
    {
        return 'unavailable';
    }

    /**
     * Every capability false, without exception. This is a refusal sentinel,
     * not a degraded provider — a single `true` here would be a promise this
     * class exists specifically to never make.
     */
    public function capabilities(): array
    {
        return [
            'checkout_type' => null,
            'cards'         => false,
            'upi'           => false,
            'net_banking'   => false,
            'wallets'       => false,
            'webhooks'      => false,
            'refunds'       => false,
            'simulatable'   => false,
            'reports_captured_amount' => false,
        ];
    }

    /**
     * ── PAY-OPS-4 · A REFUSING ADAPTER CLASSIFIES NOTHING AS SUCCESS ────
     *
     * This class is selected when the tenant has no usable gateway. There is
     * no provider whose event vocabulary could be interpreted, so every
     * delivery is IGNORE — the class that produces no financial record.
     *
     * Implemented explicitly rather than omitted. `Payment_gateway_selector::
     * classify_provider_event()` falls back to IGNORE for an adapter that
     * lacks the method, so omitting it would behave identically today — but
     * silently, by absence. This class's own convention is to implement the
     * whole surface and refuse in the open (see `capabilities()` above).
     */
    public function classify_event(array $body): array
    {
        return [
            'class'  => 'ignore',
            'event'  => self::scalarEvent($body),
            'reason' => 'no payment gateway is available for this school — nothing to reconcile against',
        ];
    }

    /**
     * Scalar-guarded envelope read. A webhook body is network JSON, so
     * `(string)` on a nested array raises a warning that no try/catch
     * contains and yields the literal "Array". Kept local rather than shared
     * with Payment_gateway_selector, which instantiates this class - see the
     * fuller note in Payment_gateway_razorpay::classify_event().
     */
    private static function scalarEvent(array $body): string
    {
        foreach (['event', 'type'] as $k) {
            $v = $body[$k] ?? null;
            if ($v === null) continue;
            if (!is_scalar($v) || is_bool($v)) return '';
            return strtolower(trim((string) $v));
        }
        return '';
    }
}
