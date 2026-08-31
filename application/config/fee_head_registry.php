<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * ══════════════════════════════════════════════════════════════════════════
 * FEE HEAD REGISTRY — M5 CUTOVER FLAG
 * ══════════════════════════════════════════════════════════════════════════
 *
 * FALSE (default): every fee-generation path behaves exactly as before —
 *   the admission spec emits no `category`, module fees stamp
 *   `strtolower($moduleType)`, and the monthly path uses the legacy
 *   name-keyword heuristic `Fees::_resolveFeeCategory()`.
 *
 * TRUE: the three live new-demand paths resolve their head through
 *   `Fee_head_resolver` against the school-scoped `feeHeadRegistry`, and stamp
 *   `feeHeadRef` + registry `category` + `categorySource = 'registry'` onto
 *   NEW demands only. An unresolvable head FAILS CLOSED — generation aborts
 *   rather than guessing.
 *
 * ── WHAT THE FLAG DOES NOT DO ──────────────────────────────────────────────
 *  • It NEVER changes demand-id composition. `feeHeadRef` is classification
 *    identity, never demand identity — feeding it into a demand id would
 *    re-identify future Transport/Hostel demands against unchanged historical
 *    ones and duplicate live charges.
 *  • It NEVER touches an existing demand. No backfill of `feeHeadRef`,
 *    `category` or `categorySource`; no legacy `category=''` is promoted to
 *    'academic'; no balance, payment state or due date is recomputed.
 *  • It is ALL-OR-NOTHING across the three generation paths, so the system is
 *    never half-registry / half-heuristic.
 *
 * Flip only after the flag-off soak and explicit authorisation.
 */
// ── DEFAULT ON (2026-08-29) ──────────────────────────────────────────────
// A school sold tomorrow must bill correctly without anyone remembering a
// switch, so this is the default rather than a per-tenant grant.
//
// Safe as a default ONLY because Fee_lifecycle::_registryModeOn() now verifies
// the tenant's registry actually covers its fee chart before honouring it. A
// school that has not registered its heads yet falls back to legacy pricing and
// keeps billing, instead of failing head resolution closed and generating ZERO
// demands — which is what this flag did on its own (measured: demands=0,
// ACC_FEEHEAD_UNRESOLVED, admission still reporting success).
$config['USE_FEE_HEAD_REGISTRY'] = true;
