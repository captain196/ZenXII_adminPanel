<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * ZenX Leave Policy Programme — 7-phase feature flags.
 *
 * All flags default OFF. Each gates one phase's live behavior change so the
 * corresponding service/schema can be built, tested, and even loaded in
 * production code paths WITHOUT changing what any existing school
 * experiences — the "additive, flag-gated cutover" discipline used
 * throughout this codebase (see fees_exemption_v2_flags.php,
 * atomic_capacity_enabled). No flag here is flipped without explicit
 * operator authorization, and never mid-way through a school's open
 * Leave Year.
 *
 * Loaded via $this->config->load('leave_policy_v2_flags', true) and read
 * with $this->config->item('LEAVE_SESSION_YEAR_ENABLED', 'leave_policy_v2_flags').
 */

// Phase 1 — Leave-Year/session foundation. When true, leave-balance docs
// key off the resolved session label (Leave_year_service) instead of the
// raw calendar year. OFF = zero behavior change (current date('Y') keying).
$config['LEAVE_SESSION_YEAR_ENABLED'] = false;

// Phase 2 — effective-dated leave-type policy versions. When true,
// Leave_policy_service::effectiveVersion() resolves the governing version
// instead of the flat, live-edited leaveTypes[typeId] fields.
$config['LEAVE_EFFECTIVE_DATING_ENABLED'] = false;

// Phase 3 — per-type accrual (lump_sum vs monthly). When true, accrual-mode
// types derive balance from accruedToDate; OFF = every type stays lump-sum
// (current behavior), regardless of any accrual_mode value stored.
$config['LEAVE_ACCRUAL_ENABLED'] = false;

// Phase 4 — pro-rata entitlement for mid-Leave-Year join/separation. When
// true, allocation is scaled by employment-window overlap with the Leave
// Year; OFF = full days_per_year allocated regardless of join/exit date
// (current behavior).
$config['LEAVE_PRORATA_ENABLED'] = false;

// Phase 5 — per-type waiting period. When true, apply_leave() refuses a
// request filed before joinDate + waiting_period_days; OFF = no eligibility
// delay (current behavior), regardless of any waiting_period_days stored.
$config['LEAVE_WAITING_PERIOD_ENABLED'] = false;

// Phase 6 — per-type maximum balance ceiling on accrual. Only meaningful
// when Phase 3 accrual is also enabled; OFF = no ceiling (current
// behavior).
$config['LEAVE_MAX_BALANCE_ENABLED'] = false;

// Phase 7 — HR-triggered Leave-Year closure (carry-forward preview/
// confirm). This flag gates only the UI/endpoint surface; the underlying
// leaveYearClosures write path is idempotent regardless, but stays
// unreachable until a school is explicitly ready to close a year.
$config['LEAVE_YEAR_CLOSURE_ENABLED'] = false;
