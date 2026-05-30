# Fees Exemption v2 — Phase 2 (Option A) — post-implementation verification report

**Date:** 2026-05-30
**Branch:** `ankit/my-feature`
**Head at report time:** `bd5de645`
**Phase 2 decision:** Option A — admission + promotion routed through unified generator; chart-save remains legacy until Phase 2.5.
**Production cutover status:** **NOT cut over.** All feature flags remain OFF.

---

## 1. Commit chain (this Phase 2 window)

| # | SHA | Purpose | Behavior change at ship? |
|---|---|---|---|
| 1 | `02ff09da` | P0-a: collections + rules + indexes + gated admin UI | No (UI off behind flag) |
| 2 | `12d22647` | P0-b: remove broken legacy exemption capture | Yes — removed dead capture surface |
| 3 | `d8a7d490` | P1: unified generator + readers + A/B verifier | No (library code; no caller wired) |
| 4 | `2aa09e5e` | P1 probe fix: exclude createdAt/updatedAt | No (probe-only) |
| 5 | `51d7a048` | P2 routing: admission+promotion via `_routedBuildAdmissionSpecs` | No (router defaults to legacy when flag OFF) |
| 6 | `bd5de645` | Comms: badge partial + boundary banner + check_active_concessions + release note | No (badge renders nothing pre-cutover) |

**Net behavior change to existing users on `bd5de645` HEAD vs pre-Phase-2 baseline:** **zero**, with one intentional exception — the broken legacy exemption checkboxes on student admission/edit forms (P0-b) are gone, replacing a save-but-ignore footgun with a no-op until the new Concessions screen is enabled.

## 2. Acceptance gate — A/B byte-identical re-verify

```
SCHOOL_ID=SCH_D94FE8F7AD php index.php fee_generation_ab_verify check
```

```
═══════════════════════════════════════════════════════════════
 Phase-1 A/B verification — legacy vs unified demand specs (READ ONLY)
═══════════════════════════════════════════════════════════════
  school:  SCH_D94FE8F7AD
  session: 2026-27

── per-student A/B ──  (9 active students)
  STU0001    Class 8th / Section A     legacy_specs=37  unified_specs=37   ✓ identical
  STU0004    Class 8th / Section A     legacy_specs=37  unified_specs=37   ✓ identical
  STU0005    Class 8th / Section A     legacy_specs=37  unified_specs=37   ✓ identical
  STU0006    Class 8th / Section A     legacy_specs=37  unified_specs=37   ✓ identical
  STU0007    Class 8th / Section A     legacy_specs=37  unified_specs=37   ✓ identical
  STU0008    Class 8th / Section A     legacy_specs=37  unified_specs=37   ✓ identical
  STU0009    Class 8th / Section A     legacy_specs=37  unified_specs=37   ✓ identical
  STU0010    Class 8th / Section A     legacy_specs=37  unified_specs=37   ✓ identical
  STU0011    Class 8th / Section A     legacy_specs=37  unified_specs=37   ✓ identical

── Result ──
  pass:    9 / 9
  fail:    0
  skipped: 0
  GATE:    PASS — safe to authorize P2 flag flip
═══════════════════════════════════════════════════════════════
```

**Result: 9/9 byte-identical.** GATE PASS preserved through five subsequent commits (`2aa09e5e` → `bd5de645`). The Phase-2 acceptance contract holds.

## 3. Feature-flag state (must remain OFF)

`application/config/fees_exemption_v2_flags.php`:

```
USE_UNIFIED_FEE_GEN          = false
CONCESSION_UI_ENABLED        = false
SERVICE_ENROLLMENT_UI_ENABLED= false
PHASE_3_CONVERGED            = false
```

Effective behavior with all flags OFF:

- Admission `save_admission` calls `_routedBuildAdmissionSpecs(...)`, which immediately delegates to `buildAdmissionDemandSpecs(...)` (the legacy code path) because `USE_UNIFIED_FEE_GEN` is false. **Bit-for-bit identical to pre-Phase-2.**
- Promotion: same.
- Chart-save: untouched. Still hits `_auto_generate_student_demands` directly. Unchanged.
- Manual / Bulk Generate / Recalc Unpaid Discounts: untouched. Unchanged.
- Concessions & Services sidebar entry: hidden (gated `CONCESSION_UI_ENABLED || SERVICE_ENROLLMENT_UI_ENABLED`).
- `/fee_concessions/*` POST endpoints: present, but each rejects with "feature disabled" (double-gated: role + per-feature flag).
- `/fee_concessions/check_active_concessions` (READ-only): present and live for any admin role — this is intentional so the upcoming smart-confirm modal can warn admins. It is read-only; safe to expose pre-cutover.
- Badge partial: renders nothing (gated `USE_UNIFIED_FEE_GEN`).
- Concessions screen, if reached via direct URL: renders the "Under construction" placeholder branch (no active form).

## 4. Scope traceability — Option A requirements (operator)

| Requirement | Status | Evidence |
|---|---|---|
| 1. Preserve 9/9 zero-diff guarantee | ✅ MET | §2 re-verify above; result `pass: 9/9, fail: 0` after every subsequent commit |
| 2. Keep all feature flags OFF until verification completes | ✅ MET | `fees_exemption_v2_flags.php` shows all 4 flags = false |
| 3. Clearly document that chart-save remains legacy during P2 | ✅ MET | `docs/FEES_EXEMPTION_V2_PHASE2_RELEASE_NOTE.md` §"Which paths apply concessions" table; concessions.php active-mode info banner |
| 4. Prepare separate Phase 2.5 proposal for chart-save convergence | ✅ MET | `memory/fees_exemption_v2_phase_2_5_chart_save_proposal.md` — Strategy A recommended, D1–D5 operator decisions queued |

## 5. What did NOT change (regression surface)

- `feeDemands` schema: unchanged. Adding `concessionApplied` (additive optional field) requires Phase 2 cutover; not active.
- Parent app fee read path: unchanged. Reads from `feeDemands`; tolerant of additional optional fields.
- Teacher app fee read path: unchanged.
- Razorpay / parent payment flow: unchanged.
- Accounting / reconciler / FeeWorker: unchanged.
- `Fee_management::auto_generate_demands` (manual gen): unchanged — explicit Phase 3 scope.
- `Fees::bulk_generate_demands`: unchanged.
- `Fees::recalc_unpaid_discounts`: unchanged.
- `Fees::_auto_generate_student_demands` (chart-save): unchanged — Phase 2.5 scope.
- BUG-076 session-threading: preserved (router calls legacy when flag off; promotion goes through `assignInitialFees` which has its own session-threading already shipped).
- BUG-075 `preservePayment`: preserved (legacy path is unmodified; unified path mirrors merge-write+preservePayment).
- `Entity_firestore_sync::normalizeClassSection`: not invoked by the new paths (uses pre-normalized class/section from caller). No risk.

## 6. New surface added

- Library: `Fee_concession_reader`, `Fee_service_enrollment_reader`, `Fee_generation_service` (P1).
- Library helper: `Fee_lifecycle::_routedBuildAdmissionSpecs` private (P2) + `Fee_lifecycle::buildAdmissionDemandSpecs` public extraction (P1).
- Controller: `Fee_concessions` (P0-a + comms `check_active_concessions`).
- Views: `fee_management/concessions.php` (P0-a + comms banner), `_concession_awareness_badge.php` (comms).
- Routes: 8 routes total under `fee_concessions/*`.
- Firestore collections: `studentConcessions`, `studentServiceEnrollments` (rules: server-only; indexes deployed).
- Config: `fees_exemption_v2_flags.php` (4 booleans).
- Docs: `FEES_EXEMPTION_V2_PHASE2_RELEASE_NOTE.md`.
- Memory: `fees_exemption_v2_architecture.md` (canonical target), `fees_exemption_v2_phase_2_5_chart_save_proposal.md` (next phase).

## 7. Known limitations / explicit non-goals of this window

- Chart-save shape divergence not addressed — by operator decision (Option A). Filed as Phase 2.5.
- Manual / Bulk / Recalc paths not addressed — Phase 3.
- Service-Enrollment UI hidden — Phase 3.
- `createModuleFee` (transport/hostel/meals one-shot demand creation) untouched — Phase 3.
- Mid-session reconciliation (void unpaid future on discontinue / revoke; prorate) — Phase 4.
- Per-view badges + smart-confirm modal on manual/bulk/recalc/chart-save buttons — intentionally NOT in `bd5de645`; ships as a scoped follow-up commit using the existing `check_active_concessions` endpoint.
- Retiring legacy `exemptedFees` / `discountHeads` fields — Phase 5 after soak.

## 8. Cutover procedure (when authorized — NOT now)

**Authoritative source:** [FEES_EXEMPTION_V2_PHASE2_CUTOVER_RUNBOOK.md](FEES_EXEMPTION_V2_PHASE2_CUTOVER_RUNBOOK.md) (rev 1.1, LOCKSTEP / Option L).

Per-Option-L: `USE_UNIFIED_FEE_GEN` and `CONCESSION_UI_ENABLED` flip together in a single save + single Apache restart. No staged capture-only state (operator's binding Path-B requirement: we do not ship a capture-without-billing window). Verification V1–V11 runs within 30 min; rollback per the runbook's 3-layer §3. See runbook §2 for the sequence.

## 9. Rollback

Instant (≤30s): set both flags back to `false` in `fees_exemption_v2_flags.php`. Apache restart. Behavior reverts. Any concession docs already created remain in Firestore (server-only writable, so no abuse vector). No demand data is altered — the rollback is purely a routing flip.

Code-level: `git revert bd5de645 51d7a048` if a structural problem is found in the delegate (would also rip out the comms layer). The library code from P1 (`d8a7d490`) can stay regardless — it has no callers when the flag is off.

---

## TL;DR

- Phase 2 Option A is shipped and **flag-gated OFF**.
- All 4 operator requirements are met (§4).
- A/B re-verify after the final comms-layer commit: **9/9 byte-identical, GATE PASS**.
- Chart-save deferred to Phase 2.5 with a formal proposal already filed (§7 link).
- No production cutover. Safe to authorize the flag flip on operator review.
