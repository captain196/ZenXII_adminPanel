<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/*
|--------------------------------------------------------------------------
| Wave B2.2-F — collection-name + field-name constants
|--------------------------------------------------------------------------
| Single naming source for F1/F3/F4/F5/F6. F7-draft authored these first to
| break the F1<->F7 co-design loop; F1-lockin (2026-05-30) FROZE the field
| names against B2_derive's return shapes (lifecycle/billingSummary keys
| match B2_derive::computeLifecycle / computeBillingSummary verbatim;
| entitlements is a {<moduleKey>:bool} map per computeEntitlements; the
| writer stamps `computedAt` when persisting). F7-final (2026-05-30) added
| the firestore.indexes.json composite-index manifest.
|
| All B2 collections are Firestore-only. RTDB carries NO B2 dependency.
| @schema-locked  2026-05-30 (F1-lockin / F7-final)
*/

// ── Permanent Firestore-only end-state collections (survive B2.8) ──
$config['b2_collections'] = [
    'schools'             => 'schools',           // thin client-readable registry
    'schoolControl'       => 'schoolControl',     // server-only composition root
    'tenantPublic'        => 'tenantPublic',      // client mirror (no money)
    'plans'               => 'plans',             // immutable versioned catalog
    'subscriptions'       => 'subscriptions',     // per-change/per-period history
    'invoices'            => 'invoices',          // billing source of truth
    'payments'            => 'payments',          // top-level (queryable)
    'revenueByPeriod'     => 'revenueByPeriod',   // server-maintained rollup
    'schoolSsa'           => 'schoolSsa',         // canonical SSA record
    'tenantAudit'         => 'tenantAudit',       // top-level audit
    'schoolCodeIndex'     => 'schoolCodeIndex',   // txn-created uniqueness
    'schoolNameIndex'     => 'schoolNameIndex',   // txn-created uniqueness
    'billingClaims'       => 'billingClaims',     // Billing_integrity arbiter

    // ── Migration scaffolding (retired at B2.8) ──
    'pendingProjection'   => '_b2_pending',       // Firestore-only bridge marker
];

// Draft field-name skeleton for schoolControl (server-only). F1-lockin freezes
// these against B2_derive::compute*() return shapes in Batch 2.
$config['b2_schoolControl_fields'] = [
    'adminDisabled'  => 'adminDisabled',   // {value, reason, by, at}            axis 1
    'lifecycle'      => 'lifecycle',       // {state, computedAt, reason,
                                           //  subscriptionPeriodEnd}            axis 2 (derived)
    'entitlements'   => 'entitlements',    // {<moduleKey>:bool, computedAt}     axis 4 (derived)
    'overrides'      => 'overrides',       // {<module>:{mode,reason,
                                           //  grantedBy,grantedAt,expiresAt?}}
    'featureFlags'   => 'featureFlags',    // {<key>:bool}                       axis 5 (gate, not grant)
    'subscription'   => 'subscription',    // pinned plan summary + addOns[]
    'billingSummary' => 'billingSummary',  // derived projection over invoices/payments
];

// Draft field-name skeleton for tenantPublic (client-readable mirror, no money).
$config['b2_tenantPublic_fields'] = [
    'name'           => 'name',
    'logoUrl'        => 'logoUrl',
    'activeModules'  => 'activeModules',   // [moduleKey] — moduleVisible-filtered
    'accessAllowed'  => 'accessAllowed',   // = EffectiveAccess
    'computedAt'     => 'computedAt',
];

// Canonical lifecycle state enum (computeLifecycle output).
$config['b2_lifecycle_states'] = [
    'trialing', 'active', 'expiring_soon', 'grace', 'past_due', 'suspended', 'expired',
];

// Canonical entitlement override modes.
$config['b2_override_modes'] = ['grant', 'revoke'];

// Canonical invoice status enum.
$config['b2_invoice_statuses'] = ['pending', 'partial', 'paid', 'overdue', 'failed', 'refunded'];

// Canonical subscription history row statuses.
$config['b2_subscription_statuses'] = ['active', 'scheduled', 'superseded'];

// EffectiveAccess: lifecycle states that PERMIT access (composed with !adminDisabled).
$config['b2_access_lifecycle_states'] = ['trialing', 'active', 'expiring_soon', 'grace'];
