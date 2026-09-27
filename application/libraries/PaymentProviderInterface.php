<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * ══════════════════════════════════════════════════════════════════════════
 *  P1 — THE FORMAL PROVIDER CONTRACT (2026-08-24)
 * ══════════════════════════════════════════════════════════════════════════
 *
 * Until now the adapter "contract" was duck-typed: `Payment_service` called
 * `create_order()` / `verify_signature()` / `fetch_payment()` / `get_name()`
 * on whatever object it was handed, and three classes happened to implement
 * matching signatures. That was never enforced by the language — a fourth
 * adapter with a typo'd method name would fail silently at the call site, not
 * at load time.
 *
 * This interface formalizes EXACTLY the methods already in real, executable
 * use today (verified against `Payment_gateway_razorpay`, `Payment_gateway_mock`
 * and `Payment_gateway_unavailable` — all three already share these five
 * signatures verbatim). It adds exactly one new method, `capabilities()`,
 * needed for future work (Parent App checkout-type discovery, a provider
 * registry) that does not yet consume it — declared now, wired to nothing yet,
 * the same pattern this codebase already uses for `Payment_gateway_unavailable`
 * implementing the full surface deliberately rather than a subset.
 *
 * ── WHAT IS DELIBERATELY NOT HERE ─────────────────────────────────────────
 * Per the P1 gate of the provider-agnostic architecture programme:
 *
 *   - `simulate_payment()` — NOT part of this interface. `Payment_gateway_razorpay`
 *     THROWS on it today; making it a required interface method would force
 *     every future real adapter to implement an operation that only a
 *     simulator can honestly support. It remains a plain method on the
 *     classes that already have it (unchanged, untouched by this file).
 *   - Callback/webhook parsing, credential validation, connection testing,
 *     refunds, error normalization — these still live in the controller
 *     (`Fee_management.php`) because their logic has not moved yet. Declaring
 *     interface methods for them now, with no adapter behind them, would be
 *     exactly the premature abstraction this programme's own operating rule
 *     forbids ("do not invent abstraction merely because it sounds
 *     elegant"). They are P2 (protocol relocation) and P7 (refund capability)
 *     work, not P1.
 *   - Accounting, receipt, allocation, claim/idempotency semantics — never
 *     the adapter's concern, at any phase. `Payment_service`,
 *     `Fee_payment_claim` and `Operations_accounting` own these and remain
 *     untouched by this file.
 *
 * ── `capabilities()` ───────────────────────────────────────────────────────
 * Returns a plain array (matching this codebase's own convention — arrays
 * everywhere in the payment path, not value objects) with, at minimum:
 *
 *   'checkout_type' => 'native_sdk'|'webview'|'hosted_redirect'|'upi_intent'|null
 *   'refunds'        => bool   — MUST be false unless a real refund method
 *                                 exists (PA-CON-2: none does, anywhere, today)
 *   'simulatable'    => bool   — true only for a genuine simulator
 *   'reports_captured_amount' => bool
 *                              — whether fetch_payment() returns the amount the
 *                                provider ACTUALLY captured. The core uses this
 *                                to decide whether defence-in-depth amount
 *                                verification (MONEY-1) is possible at all.
 *                                A simulator or a refusal sentinel cannot, and
 *                                must say so — otherwise the core cannot tell
 *                                "this provider has no amount to compare" from
 *                                "the comparison was skipped", which is exactly
 *                                how an allowlist-of-one silently disabled the
 *                                check for every non-Razorpay provider.
 *
 * Each adapter's `capabilities()` must describe what it ACTUALLY does today,
 * not what the provider's API could theoretically support — a capability
 * flag is a promise the Parent App will eventually act on, and an optimistic
 * flag is a lie the same way a hardcoded 'razorpay' provenance was (PA-CORE-1).
 */
interface PaymentProviderInterface
{
    /** Stable provider identity. Stored verbatim as GW-ORD-1 provenance. */
    public function get_name(): string;

    /** What this adapter can actually do today — see class docblock. */
    public function capabilities(): array;

    /**
     * Create an order with the provider. Returns at minimum
     * ['order_id' => string, ...]; throws on failure.
     */
    public function create_order(float $amount, string $receiptRef = '', array $meta = []): array;

    /** Verify a callback/checkout signature. Never trusts a client claim alone. */
    public function verify_signature(string $orderId, string $paymentId, string $signature): bool;

    /**
     * Fetch the provider's own record of a payment, for defense-in-depth
     * amount verification (BUG-050 / PS-3 / MONEY-1). Returns an empty array
     * on a lookup failure — never null, never throws — so callers can use a
     * uniform `empty()` check; MONEY-1's exact-paise comparison happens in
     * `Payment_service`, not here.
     */
    public function fetch_payment(string $paymentId): array;
}
