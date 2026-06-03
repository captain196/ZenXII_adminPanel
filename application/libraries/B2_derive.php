<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Wave B2.2-F F1 — derive-function library.
 *
 * Pure, deterministic, I/O-free functions that turn raw subscription / plan /
 * override / billing inputs into the derived fields the schoolControl +
 * tenantPublic projection carries.
 *
 *   computeLifecycle           — time + billing state machine (axis 2)
 *   computeEntitlements        — plan.modules ∪ addOns − revoke-overrides   (axis 4)
 *   computeBillingSummary      — invoice/payment projection
 *   computeEffectiveAccess     — operational ∧ lifecycle composition
 *   moduleVisible              — entitlement ∧ globalFlag ∧ perSchoolFlag (gate-not-grant)
 *
 * Authored against application/config/b2_collections.php field skeleton.
 * F1-lockin (2026-05-30) FROZE the return-key names against the manifest:
 *   computeLifecycle      → {state, computedAt, reason, subscriptionPeriodEnd}
 *   computeEntitlements   → {<moduleKey>:true}  (writer stamps computedAt)
 *   computeBillingSummary → {lastInvoiceId, lastPaymentDate, lastPaymentAmount,
 *                            outstandingBalance, nextDueDate, computedAt, sourceInvoiceId}
 *   computeEffectiveAccess→ {allowed, blockedBy}
 *
 * No Firestore, no RTDB, no clock — every "now" is supplied by the caller
 * so the functions are testable without injecting a fake clock.
 *
 * @schema-locked  2026-05-30 (F1-lockin)
 */
class B2_derive
{
    // ── Lifecycle state enum (mirrors b2_collections.b2_lifecycle_states) ──
    const STATE_TRIALING       = 'trialing';
    const STATE_ACTIVE         = 'active';
    const STATE_EXPIRING_SOON  = 'expiring_soon';
    const STATE_GRACE          = 'grace';
    const STATE_PAST_DUE       = 'past_due';
    const STATE_SUSPENDED      = 'suspended';
    const STATE_EXPIRED        = 'expired';

    // Default "expiring soon" window (days before periodEnd). The lifecycle
    // engine's only hard-coded constant; surfaced for tests + future config.
    const EXPIRING_SOON_DAYS = 30;

    // Lifecycle states that PERMIT access (composed with !adminDisabled by
    // computeEffectiveAccess). Mirror of b2_access_lifecycle_states.
    const ACCESS_STATES = [
        self::STATE_TRIALING, self::STATE_ACTIVE,
        self::STATE_EXPIRING_SOON, self::STATE_GRACE,
    ];

    // ─────────────────────────────────────────────────────────────────────
    /**
     * Compute lifecycle.state from (now, subscription, optional billing flags).
     * Precedence (highest first):
     *   billingSuspended -> suspended
     *   trialEnd present and now <= trialEnd -> trialing
     *   now > graceEnd -> expired
     *   now > periodEnd (and within graceEnd) -> grace
     *   billingOverdue (within period) -> past_due
     *   within EXPIRING_SOON window -> expiring_soon
     *   else -> active
     *
     * @param int   $nowTs unix seconds
     * @param array $sub   {periodStart?, periodEnd, graceEnd?, trialEnd?, ...}
     * @param array $opts  {billingOverdue?:bool, billingSuspended?:bool,
     *                      expiringSoonDays?:int}
     * @return array {state, computedAt, reason, subscriptionPeriodEnd}
     */
    public static function computeLifecycle(int $nowTs, array $sub, array $opts = []): array
    {
        $periodEnd = isset($sub['periodEnd']) ? (int) $sub['periodEnd'] : 0;
        $graceEnd  = isset($sub['graceEnd'])  ? (int) $sub['graceEnd']  : $periodEnd;
        $trialEnd  = array_key_exists('trialEnd', $sub) && $sub['trialEnd'] !== null
                       ? (int) $sub['trialEnd'] : null;
        $billingOverdue   = !empty($opts['billingOverdue']);
        $billingSuspended = !empty($opts['billingSuspended']);
        $expiringDays     = isset($opts['expiringSoonDays'])
                              ? (int) $opts['expiringSoonDays']
                              : self::EXPIRING_SOON_DAYS;

        if ($billingSuspended) {
            $state = self::STATE_SUSPENDED;
            $reason = 'billing_suspend';
        } elseif ($trialEnd !== null && $nowTs <= $trialEnd) {
            $state = self::STATE_TRIALING;
            $reason = 'within_trial';
        } elseif ($graceEnd > 0 && $nowTs > $graceEnd) {
            $state = self::STATE_EXPIRED;
            $reason = 'past_grace_end';
        } elseif ($periodEnd > 0 && $nowTs > $periodEnd) {
            $state = self::STATE_GRACE;
            $reason = 'past_period_end_within_grace';
        } elseif ($billingOverdue) {
            $state = self::STATE_PAST_DUE;
            $reason = 'invoice_overdue';
        } elseif ($periodEnd > 0 && ($periodEnd - $nowTs) <= ($expiringDays * 86400)) {
            $state = self::STATE_EXPIRING_SOON;
            $reason = 'within_expiring_window';
        } else {
            $state = self::STATE_ACTIVE;
            $reason = 'active_period';
        }

        return [
            'state'                 => $state,
            'computedAt'            => $nowTs,
            'reason'                => $reason,
            'subscriptionPeriodEnd' => $periodEnd,
        ];
    }

    // ─────────────────────────────────────────────────────────────────────
    /**
     * Effective entitlements = plan.modules ∪ addOns − revoke-overrides.
     * Honors override.expiresAt and addOn.effectiveUntil (active iff null or > now).
     * Override mode='grant' (still time-bounded) wins where present and active.
     *
     * @param array $planVersion {modules: {<key>:bool}}
     * @param array $addOns      [{module, kind, price?, effectiveUntil?}]
     * @param array $overrides   [{module, mode:'grant'|'revoke', expiresAt?}]
     * @param int   $nowTs
     * @return array {<moduleKey>:true}   (false-valued keys are omitted)
     */
    public static function computeEntitlements(array $planVersion, array $addOns, array $overrides, int $nowTs): array
    {
        $out = [];

        // Plan base (only true-valued modules carry through).
        $modules = isset($planVersion['modules']) && is_array($planVersion['modules'])
                     ? $planVersion['modules'] : [];
        foreach ($modules as $k => $v) {
            if ($v) $out[$k] = true;
        }

        // Add-ons union (active ones only).
        foreach ($addOns as $a) {
            if (!is_array($a)) continue;
            $m  = $a['module'] ?? null;
            $eu = array_key_exists('effectiveUntil', $a) && $a['effectiveUntil'] !== null
                    ? (int) $a['effectiveUntil'] : null;
            if ($m && ($eu === null || $eu > $nowTs)) {
                $out[$m] = true;
            }
        }

        // Overrides (honor expiresAt). Active revoke wins over grant.
        foreach ($overrides as $o) {
            if (!is_array($o)) continue;
            $m    = $o['module'] ?? null;
            $mode = $o['mode']   ?? null;
            $eu   = array_key_exists('expiresAt', $o) && $o['expiresAt'] !== null
                      ? (int) $o['expiresAt'] : null;
            $active = ($eu === null || $eu > $nowTs);
            if (!$m || !$active) continue;
            if ($mode === 'grant') {
                $out[$m] = true;
            } elseif ($mode === 'revoke') {
                unset($out[$m]);
            }
        }

        return $out;
    }

    // ─────────────────────────────────────────────────────────────────────
    /**
     * Billing summary projection over invoices + payments.
     * outstandingBalance sums OPEN invoices (pending/partial/overdue with balance > 0).
     * nextDueDate = min(dueDate) across open invoices.
     * sourceInvoiceId = the latest paid invoice's id (drives periodEnd advance).
     *
     * @param array $invoices [{invoiceId, status, amount, amountPaid, dueDate?, periodEnd?, ...}]
     * @param array $payments [{date, amount, ...}]
     * @return array {lastInvoiceId, lastPaymentDate, lastPaymentAmount,
     *                outstandingBalance, nextDueDate, computedAt, sourceInvoiceId}
     */
    public static function computeBillingSummary(array $invoices, array $payments, int $nowTs): array
    {
        $latestPaid       = null;
        $latestPaymentTs  = null;
        $latestPaymentAmt = null;
        $outstanding      = 0.0;
        $nextDueDate      = null;
        $sourceInvoiceId  = null;

        foreach ($invoices as $inv) {
            if (!is_array($inv)) continue;
            $status = (string) ($inv['status'] ?? '');
            $amt    = (float)  ($inv['amount'] ?? 0);
            $paid   = (float)  ($inv['amountPaid'] ?? 0);
            $bal    = $amt - $paid;

            if (in_array($status, ['pending', 'partial', 'overdue'], true) && $bal > 0) {
                $outstanding += $bal;
                $due = array_key_exists('dueDate', $inv) && $inv['dueDate'] !== null
                         ? (int) $inv['dueDate'] : null;
                if ($due !== null && ($nextDueDate === null || $due < $nextDueDate)) {
                    $nextDueDate = $due;
                }
            }
            if ($status === 'paid') {
                $endTs = (int) ($inv['periodEnd'] ?? 0);
                $prev  = $latestPaid !== null ? (int) ($latestPaid['periodEnd'] ?? 0) : -1;
                if ($endTs > $prev) {
                    $latestPaid      = $inv;
                    $sourceInvoiceId = $inv['invoiceId'] ?? null;
                }
            }
        }

        foreach ($payments as $p) {
            if (!is_array($p)) continue;
            $d = (int) ($p['date'] ?? 0);
            if ($latestPaymentTs === null || $d > $latestPaymentTs) {
                $latestPaymentTs  = $d;
                $latestPaymentAmt = (float) ($p['amount'] ?? 0);
            }
        }

        return [
            'lastInvoiceId'       => $latestPaid !== null ? ($latestPaid['invoiceId'] ?? null) : null,
            'lastPaymentDate'     => $latestPaymentTs,
            'lastPaymentAmount'   => $latestPaymentAmt,
            'outstandingBalance'  => round($outstanding, 2),
            'nextDueDate'         => $nextDueDate,
            'computedAt'          => $nowTs,
            'sourceInvoiceId'     => $sourceInvoiceId,
        ];
    }

    // ─────────────────────────────────────────────────────────────────────
    /**
     * Compose operational (axis 1) with lifecycle (axis 2) into a single
     * effective access decision. The runtime gate reads this.
     *
     * effectiveAccess = !adminDisabled && lifecycle.state in {trialing, active,
     *                   expiring_soon, grace}.
     *
     * @param array $control schoolControl doc (or subset) with .adminDisabled + .lifecycle
     * @return array {allowed:bool, blockedBy:string}
     */
    public static function computeEffectiveAccess(array $control): array
    {
        $adminDisabled = !empty($control['adminDisabled']['value']);
        $lifecycleState = (string) ($control['lifecycle']['state'] ?? '');

        if ($adminDisabled) {
            return ['allowed' => false, 'blockedBy' => 'admin_disabled'];
        }
        if (!in_array($lifecycleState, self::ACCESS_STATES, true)) {
            return ['allowed' => false, 'blockedBy' => 'lifecycle_' . $lifecycleState];
        }
        return ['allowed' => true, 'blockedBy' => ''];
    }

    // ─────────────────────────────────────────────────────────────────────
    /**
     * Module visibility — entitlement is the GATE, flags only GATE (never grant).
     * A global or per-school flag explicitly set to FALSE hides an otherwise-entitled
     * module; anything else (true / unset) does not gate.
     *
     * @param string $module
     * @param array  $entitlements   {<moduleKey>:true}
     * @param array  $globalFlags    {<moduleKey>:bool}
     * @param array  $perSchoolFlags {<moduleKey>:bool}
     */
    public static function moduleVisible(
        string $module,
        array  $entitlements,
        array  $globalFlags    = [],
        array  $perSchoolFlags = []
    ): bool {
        if (empty($entitlements[$module])) {
            return false; // not entitled -> never visible
        }
        if (array_key_exists($module, $globalFlags) && $globalFlags[$module] === false) {
            return false;
        }
        if (array_key_exists($module, $perSchoolFlags) && $perSchoolFlags[$module] === false) {
            return false;
        }
        return true;
    }
}
