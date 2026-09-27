<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * ══════════════════════════════════════════════════════════════════════════
 *  GW-SEC4 — WEBHOOK SIGNATURE VERIFICATION WITH A BOUNDED ROTATION WINDOW
 * ══════════════════════════════════════════════════════════════════════════
 *
 * ── THE PROBLEM ROTATION CREATES ──────────────────────────────────────────
 * A tenant's webhook secret is a single stored value. The moment an operator
 * rotates it, every webhook already in flight — and every gateway retry of an
 * earlier delivery — is still signed with the OLD secret. Those deliveries fail
 * HMAC, return 401, and the gateway eventually stops retrying.
 *
 * The payment itself succeeded at the gateway. What is lost is the *notification*
 * that it succeeded. If the parent's client callback also did not complete (they
 * closed the app, lost signal, the tab was backgrounded), the order never reaches
 * _verify_and_process at all.
 *
 * ── WHY THE OBVIOUS RECOVERY IS NOT AVAILABLE ─────────────────────────────
 * Such an order sits in `created` / `attempted` — NOT `fees_failed` — and
 * `retry_payment_processing()` refuses anything that is not `fees_failed`.
 *
 * That restriction must NOT be relaxed to cover this case. The retry path does
 * not call verify_payment(): it goes straight to FeeCollectionService, trusting
 * that the order's stored gateway_payment_id represents a genuinely captured
 * payment. `fees_failed` is precisely the state that guarantees signature
 * verification already succeeded once. Widening it would let an administrator
 * force-process an order that was never paid — trading a rare stranded payment
 * for a permanent hole in payment verification.
 *
 * So rotation is handled by PREVENTION here, not by recovery there.
 *
 * ── THE WINDOW, AND ITS LIMITS ────────────────────────────────────────────
 * During a rotation the previous secret is also accepted — but only:
 *   • when a previous secret was explicitly recorded, AND
 *   • when the rotation timestamp exists and parses, AND
 *   • while that timestamp is inside the grace window.
 *
 * Every one of those failing means "reject". Accepting a second valid signature
 * widens what the endpoint trusts, so the widening must expire on its own rather
 * than depend on anyone remembering to clear it.
 *
 * Comparisons use hash_equals() throughout — a timing-safe comparison against a
 * value an attacker supplies.
 */

if (!function_exists('webhook_signature_valid')) {
    /**
     * @param string $rawBody   exact bytes the signature was computed over
     * @param string $signature signature presented by the caller
     * @param array  $gwConfig  the tenant's gateway configuration document
     * @param int    $graceSeconds how long a rotated-out secret stays acceptable
     * @return bool
     */
    function webhook_signature_valid(
        string $rawBody,
        string $signature,
        array $gwConfig,
        int $graceSeconds = 86400
    ): bool {
        if ($signature === '') return false;

        $current = (string) ($gwConfig['webhook_secret'] ?? '');
        if ($current !== ''
            && hash_equals(hash_hmac('sha256', $rawBody, $current), $signature)) {
            return true;
        }

        // ── Rotation grace — every branch below fails CLOSED ──
        $previous = (string) ($gwConfig['webhook_secret_previous'] ?? '');
        if ($previous === '') return false;

        $rotatedAt = (string) ($gwConfig['webhook_secret_rotated_at'] ?? '');
        if ($rotatedAt === '') {
            // A previous secret with no rotation timestamp cannot be bounded, so
            // it would be accepted forever. Refuse rather than trust it.
            log_message('error',
                'webhook_signature_valid: a previous webhook secret is present with no '
                . 'rotation timestamp — refusing to honour it (unbounded window).');
            return false;
        }

        $ts = strtotime($rotatedAt);
        if ($ts === false) {
            log_message('error',
                'webhook_signature_valid: unparseable webhook_secret_rotated_at ['
                . $rotatedAt . '] — refusing the previous secret.');
            return false;
        }

        if ((time() - $ts) > $graceSeconds) {
            // Expired. Not an error — this is the window closing as designed.
            return false;
        }

        if (hash_equals(hash_hmac('sha256', $rawBody, $previous), $signature)) {
            log_message('info',
                'webhook_signature_valid: accepted a webhook signed with the PREVIOUS '
                . 'secret, inside the rotation grace window.');
            return true;
        }

        return false;
    }
}
