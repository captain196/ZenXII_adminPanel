<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * ══════════════════════════════════════════════════════════════════════════
 *  GW-ORD-1 — MAY THIS PENDING ORDER BE REUSED ON THE ACTIVE GATEWAY?
 * ══════════════════════════════════════════════════════════════════════════
 *
 * A gateway order id is only meaningful to the gateway that issued it. Handing
 * a checkout an id its provider never issued is refused the instant the sheet
 * renders, and to a paying parent that is indistinguishable from a declined
 * card.
 *
 * Observed live 2026-08-24: a mock-minted `ORD_E5C263912368` was handed to the
 * real Razorpay checkout. Worse, the de-duplication that returned it matched on
 * student + months + amount alone, so it kept returning that same dead id on
 * every retry — fixing the gateway did NOT fix the payment. The combination was
 * permanently unpayable.
 *
 * ── WHY THIS IS SHARED ────────────────────────────────────────────────────
 * Two independent money paths reuse pending orders — `Payment_service`
 * (fees) and `Admission_public::initiate_payment` (admission). They had
 * separately-written predicates, and the same defect existed in both. One rule,
 * defined once, so a future adapter cannot be handled correctly in one path and
 * forgotten in the other.
 *
 * The admission path additionally bounds reuse to a 15-minute window, which
 * limited the blast radius there but did not remove the defect: wrong for at
 * most fifteen minutes is still wrong.
 */
class Payment_order_provenance
{
    /**
     * True only when the stored order can be proven to belong to the gateway
     * that is active right now.
     *
     * Fails closed on every uncertainty:
     *   - an unrecorded gateway cannot be shown to match, so it does not
     *   - a non-scalar stored value is not a gateway name
     *   - an empty active-gateway name proves nothing, so nothing is reusable
     *
     * Comparison is case-insensitive and whitespace-trimmed: adapter names are
     * written by hand in several places, and a casing difference must not
     * strand a genuine in-flight order into a duplicate charge.
     *
     * @param mixed  $recordedGateway the order's stored `gateway` value
     * @param string $activeGateway   name of the adapter now configured
     */
    public static function matchesActiveGateway($recordedGateway, string $activeGateway): bool
    {
        if (!is_scalar($recordedGateway)) {
            return false;
        }

        $recorded = strtolower(trim((string) $recordedGateway));
        $active   = strtolower(trim($activeGateway));

        if ($recorded === '' || $active === '') {
            return false;
        }

        return $recorded === $active;
    }
}
