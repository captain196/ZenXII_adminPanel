<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Fees Exemption v2 feature flags.
 *
 * Phase 0 ships with all three OFF. Phase 2 cutover flips USE_UNIFIED_FEE_GEN
 * + CONCESSION_UI_ENABLED together (capture and billing go live together,
 * never apart — the operator's explicit requirement). SERVICE_ENROLLMENT_UI
 * stays OFF until Phase 3 converges service generation off createModuleFee.
 *
 * Loaded via $this->config->load('fees_exemption_v2_flags', true) and read
 * with $this->config->item('USE_UNIFIED_FEE_GEN', 'fees_exemption_v2_flags').
 */

$config['USE_UNIFIED_FEE_GEN']         = true; // Phase 2 ACTIVATED (lockstep) — operator authorization
$config['CONCESSION_UI_ENABLED']        = true; // Phase 2 ACTIVATED (lockstep with USE_UNIFIED_FEE_GEN) — operator authorization
$config['SERVICE_ENROLLMENT_UI_ENABLED'] = false; // Phase 3 cutover: true

// ENTITLEMENT_SHADOW_COMPARE (Phase C, 2026-08-08) — read-only A/B shadow.
// When TRUE, Fee_generation_service ALSO computes the concession deductions
// from the new `studentEntitlements` log and logs any divergence from the
// authoritative legacy `studentConcessions` result
// (grep ENTITLEMENT_AB_DIVERGENCE). It NEVER changes a returned spec, never
// writes a demand/journal/receipt, and swallows its own failures.
//
// DEFAULT OFF, deliberately: enabling it costs exactly ONE extra Firestore
// query per student per generation run. That is the price of the A/B
// verification cycle the baseline mandates, and it must not be paid outside
// that cycle (Release Gate 8 — no unnecessary reads). Turn ON for the
// verification cycle, read the divergence log, then turn OFF again.
//
// Prerequisite: scripts/migrate_student_entitlements.js must have been run
// with --commit, or the shadow trivially reports every concession missing.
$config['ENTITLEMENT_SHADOW_COMPARE'] = false; // Phase C verification cycle: true

// USE_ENTITLEMENT_SERVICE_PRICING (Phase D / G4, 2026-08-08) — makes
// Fee_lifecycle::createModuleFee() (Transport + Hostel) entitlement-aware.
// Baseline §0A.4 lists that generator's discount source as literally "None":
// a student holding a transport-scoped concession is billed the full fare.
// With this ON, the net is resolved through Service_entitlement_pricing
// against the same studentEntitlements log the tuition path uses.
//
// Invariant I3 still applies: ONLY a deliberately service-scoped entitlement
// ('category:transport', 'head:<bus fee>') can discount a service fee — a
// blanket scope:'all' concession cannot touch the fare.
//
// DEFAULT OFF. With the flag off, net === gross and every field written by
// createModuleFee is byte-identical to the pre-Phase-D document, so service
// billing is unchanged until this is deliberately flipped at certification.
// Fail-safe in both directions: a flag-read error, a missing library, or a
// pricing exception all bill the FULL amount — never silently under-bill.
//
// Prerequisite: the migration must have populated studentEntitlements, and
// the A/B cycle must have passed, before this is switched on in production.
// ── DEFAULT ON (2026-08-30) ──────────────────────────────────────────────
// Enabled after SVC-1 was reproduced and closed on evidence, not on argument.
//
// THE GAP THIS CLOSES. With the flag off, a school could approve a transport or
// hostel concession — Entitlement_service records it ACTIVE regardless of this
// flag — and every fare was still billed in full, with nothing reporting it.
// Measured on a clean tenant: an ACTIVE 25% `category:transport` entitlement,
// a ₹1000 fare, and a demand written at net=1000 discount=0. For a school that
// never had legacy data that is not a migration posture, it is a correctness
// defect: the admin approves a discount and the family is charged anyway.
//
// WHY IT IS SAFE TO DEFAULT ON.
//   • The implementation is complete and wired (Service_entitlement_pricing,
//     reached via Fee_lifecycle::_priceServiceFee at createModuleFee) with 43
//     passing tests across three suites.
//   • It fails SAFE in every direction: a flag-read error, a missing library or
//     a pricing exception all bill the FULL amount. It can never under-bill.
//   • Invariant I3 still holds with it ON — verified, not assumed: a blanket
//     scope:'all' concession left the same ₹1000 fare at net=1000 discount=0,
//     while a deliberately service-scoped one deducted exactly ₹250.
//
// The prerequisite it used to carry — "the migration must have populated
// studentEntitlements" — is a LEGACY prerequisite. A new school writes that
// collection natively, so there is no migration to wait for.
//
// Proof: scripts/service_entitlement_proof.js (16/16, deterministic).
$config['USE_ENTITLEMENT_SERVICE_PRICING'] = true;

// PHASE_3_CONVERGED: drives the communication-layer badges + warning banners
// on the legacy manual/bulk/recalc paths. While false, those paths show the
// amber "Phase 2 — legacy gen" badge + warning banner and the smart-confirm
// modal fires on per-student concession matches. At Phase 3 cutover this
// single flip silences all amber UI in one place — no per-view rework.
$config['PHASE_3_CONVERGED'] = false; // Phase 3 cutover: true

// ── PHASE 3B / B2 — BILLING AUTHORITY CUTOVER (2026-08-16) ──────────────────
// USE_ENTITLEMENT_BILLING selects WHICH collection prices a fee demand:
//
//   false → studentConcessions   via Fee_concession_reader   (legacy authority)
//   true  → studentEntitlements  via Fee_entitlement_reader  (canonical)
//
// EXACTLY ONE boundary reads this flag — Fee_lifecycle::_routedBuildAdmissionSpecs().
// It chooses the reader that Fee_generation_service is initialised with, and
// nothing else changes: the generator obtains deduction semantics through the
// reader's accessors, so it never learns which collection or field name is
// behind them.
//
// NEVER BOTH. The two authorities are alternatives, not layers. Reading both
// and summing them would discount the same demand twice, which is why the
// generator is given ONE reader and has no way to ask for another.
//
// DEFAULT FALSE. With it off, billing is byte-identical to before this phase —
// the same reader, the same rows, the same arithmetic.
//
// PREREQUISITES, all satisfied before this flag may be turned on:
//   • the accessor boundary (Part 1) — otherwise every entitlement deducts ZERO;
//   • F1/F2/F3 (Phase 2) — head-name mapping, the approved service rule, and
//     the removal of the accepted-but-unimplemented `amountDue`;
//   • B1 admission ordering — otherwise the entitlement does not exist yet when
//     the demand is priced, and the flag changes nothing at admission time.
//
// The estate is empty platform-wide (0 records in every entitlement collection),
// so flipping this migrates nothing and reconciles nothing. It changes only how
// FUTURE concessions are priced.
// ── DEFAULT ON (2026-08-29) ──────────────────────────────────────────────
// The entitlement authority is the supported pricing model, and a NEW tenant
// has no legacy concessions to strand — so a school must get it by default,
// not by someone remembering to grant it.
//
// Safe as a default ONLY because the authority gate keeps a tenant holding
// ACTIVE legacy studentConcessions on the legacy reader. Switching those
// silently would stop applying discounts that are currently reducing real
// bills — a silent price rise for those families.
$config['USE_ENTITLEMENT_BILLING'] = true;

// ── USE_ALLOCATION_LEDGER (P5 step 1, 2026-08-18) ───────────────────────────
//
// Makes the generator EMIT allocation rows alongside the discount it already
// computes: one row per (demand, entitlement) recording what that grant
// contributed and the gross it was computed against. It is the durable "why"
// behind `discountAmount`, which today is an unexplained number.
//
// WITH IT OFF (the default) BILLING IS BYTE-IDENTICAL. The generator performs
// the identical arithmetic and writes the identical demand; the only difference
// is that the per-entitlement contributions are not assembled into rows. No
// document is written, and `entitlementAllocations` is never touched.
//
// WHY IT NEEDS ITS OWN FLAG rather than riding USE_ENTITLEMENT_BILLING:
// billing is ALREADY TRUE for the UAT tenant, so folding this in would make it
// live there the moment the code shipped, with no separate decision and no way
// to roll the ledger back without also disabling billing.
//
// PREREQUISITES before it may be turned on:
//   • `entitlementAllocations` rules DEPLOYED (deny-all, server-only) — the
//     collection must be closed before its first row exists, not after;
//   • its 3 composite indexes BUILT;
//   • the P5 write path that persists rows atomically with the demand.
// The first two are prepared but NOT deployed (blocked on the Teacher-app
// transportAttendance migration), so this flag cannot legitimately be enabled
// yet even though the code is ready.
// ── DEFAULT ON (2026-08-29) ──────────────────────────────────────────────
// Already self-gating: _ledgerBackedByAuthority() refuses to emit unless the
// pricing authority in force carries a grant identity, so this is INERT rather
// than harmful wherever it cannot apply. Default-on means a new school's
// discounts are explained by an allocation row from its very first bill.
$config['USE_ALLOCATION_LEDGER'] = true;

// ── SCHOLARSHIP_AWARDS_RETIRED (P9 writer closure, 2026-08-19) ──────────────
//
// Closes the `scholarshipAwards` CREATION path. With it ON, `award_scholarship`
// refuses and directs the operator to record the concession as an entitlement
// instead. Revocation and read paths stay OPEN by design: retiring a writer must
// never TRAP existing data — a school must still be able to close an award it
// already granted, and both apps must still be able to display one.
//
// DEFAULT FALSE. The legacy path is untouched until retirement is authorized.
//
// PREREQUISITES before it may be turned on — all currently UNMET:
//   • the entitlement representation of scholarships is live (needs P5
//     convergence for the whole-session apportionment, design §8);
//   • **the Parent app has migrated** — it reads `scholarshipAwards` DIRECTLY
//     (`FeeFirestoreRepository.kt:306` <- `FeesViewModel.kt:599,:925`), so
//     closing the writer alone would leave it querying a collection that can
//     never gain rows, degrading silently to ₹0 rather than failing loudly;
//   • operators are told where scholarships now live.
//
// The estate is EMPTY on every tenant (0 awards, measured), so flipping this
// migrates nothing — it only prevents NEW legacy rows from appearing while the
// replacement is being finished. That is its whole purpose: stop the surface
// growing under a retirement that is already in progress.
$config['SCHOLARSHIP_AWARDS_RETIRED'] = false; // P9 retirement step 3
