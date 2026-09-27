# Student Entitlements — Legacy Retirement Plan (Certification Gate 8)

**Status:** PLAN ONLY. Nothing retired. Not authorized to execute.
**Date:** 2026-08-09
**Precondition:** every gate 1–7 closed. This document is the audit of *what* retirement means and
*what must be true first* — not permission to do it.

> **Operator directive, 2026-08-09:** legacy retirement must NOT rest on a green shadow cycle alone.
> The production dataset holds **1** concession record, already revoked — so a passing shadow proves
> almost nothing. Retirement rests on architectural correctness, regression, and end-to-end
> validation. That constraint is binding on everything below.

---

## 1. What "legacy" actually is (audited, not assumed)

Verified by grep across `application/`. Exactly **four** production files carry the legacy path:

| File | Role | Retirement action |
|---|---|---|
| `libraries/Fee_concession_reader.php` | Reads `studentConcessions`. **Currently the billing authority** — `Fee_generation_service:73` calls it. | Delete, after the reader swap |
| `libraries/Fee_generation_service.php` | Holds `$concessionReader` + the shadow comparison | Swap reader; delete shadow code |
| `controllers/Fee_concessions.php` | The legacy capture UI (create/revoke/enroll/discontinue) | Retire routes + file |
| `controllers/Fee_generation_ab_verify.php` | Phase-1 A/B probe against legacy | Delete — its subject no longer exists |

`Fee_entitlement_reader.php` and `Service_entitlement_pricing.php` mention `studentConcessions` in
**docblock comments only** — no code dependency. Confirmed.

The Firestore collection `studentConcessions` itself is **data**, retired separately from code (§4).

## 2. The retirement sequence (dependency-ordered)

Each step is independently reversible. Do not batch them.

| # | Step | Reversible by |
|---|---|---|
| R1 | Swap `Fee_generation_service`'s injected reader from `Fee_concession_reader` to `Fee_entitlement_reader` at the **one** injection point (`Fee_lifecycle::_routedBuildAdmissionSpecs`) | Swap back — one line |
| R2 | Soak. Verify generated demands unchanged against known students. | R1 |
| R3 | Delete the shadow comparison (`_shadowCompare`, `_diffSpecs`, `firebaseHandle`) and the `ENTITLEMENT_SHADOW_COMPARE` flag | git |
| R4 | Converge the duplicated deduction rule — `_applyDeductions` and `Service_entitlement_pricing`'s accumulation — into ONE implementation. **Operator-approved cleanup item from Phase D.** Behaviour-preserving and re-verified, or not done at all. | git |
| R5 | Retire `Fee_concessions.php` + its 9 routes; delete `Fee_generation_ab_verify.php` | git |
| R6 | Delete `Fee_concession_reader.php` | git |
| R7 | Change `studentConcessions` rules from deny-all to a tombstone comment; leave data in place | rules re-deploy |
| R8 | (Deferred, separate decision) Delete `studentConcessions` documents | **IRREVERSIBLE** — see §4 |

**R1 is the true cutover.** Everything before it is additive; everything after is cleanup. If only one
step is authorized at a time, R1 is the one that needs the most evidence behind it.

## 3. Preconditions — every one must hold before R1

| # | Precondition | Current status |
|---|---|---|
| P1 | Rules + indexes deployed | ✅ Gate 2 |
| P2 | Migration `--commit` executed and verified | ⛔ **pending operator** |
| P3 | Shadow cycle run, divergences resolved | ⏸ blocked on P2 — and see the directive above: **necessary, not sufficient** |
| P4 | `SERVICE_PRORATION_ENTITLEMENT_GATE.md` closed | ⏸ **open** |
| P5 | Unit regression at baseline | ✅ 192/549/10 — matches baseline exactly, no new failures |
| P6 | Firestore rules suite green | ✅ 247/247 |
| P7 | End-to-end cross-module regression | ⏸ Gate 7, partial |
| P8 | `USE_ENTITLEMENT_SERVICE_PRICING` certified separately | ⏸ gated by P4 |

**P4 is the hard one.** `proRateFees()` is not entitlement-aware; enabling service pricing before it is
fixed silently erases discounts on every prorated transport fee. R1 concerns *tuition* concessions and
is technically independent of it — but shipping the reader swap while the service-pricing gate is open
leaves the module half-cut-over, which is worse operationally than either end state.

## 4. The data question — `studentConcessions` documents

**Recommendation: never delete them.** Retire the *code*, keep the *data*.

The collection holds 1 document. Deleting it recovers nothing measurable and destroys the only
independent record of what the legacy system believed. Every migrated entitlement carries
`migratedFrom: studentConcessions/{id}`, which becomes a dangling reference the moment the source is
deleted — and that provenance is the audit trail proving the migration was faithful.

If deletion is ever demanded, it must be a separate authorized action with its own export-first backup.
It is the only irreversible step in this plan.

## 5. Why a green shadow cycle will not be sufficient

Stated explicitly so nobody later mistakes Gate 5 for proof:

With 1 revoked concession and **zero active** ones across 4 tenants, both the legacy and entitlement
paths compute *no deduction for every student*. The shadow will report zero divergence **because there
is nothing to compare**, not because equivalence was demonstrated.

What must carry the weight instead:

1. **Unit-level equivalence, already proven** — 5/5 legacy-vs-entitlement `appliesTo` cases decide
   identically (all/percent, head/fixed, category/percent, expired window, future window), plus I3
   enforcement, stacking, floor-at-zero, and fail-closed on malformed scope.
2. **Shared arithmetic** — Phase C deliberately routes both paths through the *same*
   `_applyDeductions()`, so the two sides cannot diverge on maths, only on data.
3. **Seeded-tenant validation** — three non-production tenants exist. Representative concessions
   seeded there would give the shadow something real to compare. **This is the single highest-value
   action available to strengthen Gate 5**, and it requires operator authorization to create data.
4. **End-to-end regression** across the 10 integration points (Gate 7).

## 6. Integration points to re-verify after R1

Audited: only **two** production files touch `studentEntitlements` (`Entitlement_service`,
`Fee_entitlement_reader`), so the blast radius of R1 is narrow and knowable.

| Module | What to check after R1 |
|---|---|
| Fees | Admission + promotion demand generation unchanged for a known student |
| Admission | All 3 pathways still generate correctly |
| Promotion | `reassignFeesOnPromotion` → `assignInitialFees` inherits the new reader |
| Accounting | Unaffected — journals post at collection against receipts, not `netAmount` |
| Transport / Hostel | Only affected once `USE_ENTITLEMENT_SERVICE_PRICING` is on (P4) |
| Parent / Teacher apps | No schema change; `studentEntitlements` stays deny-all — apps see effects via `feeDemands` |
| Student 360 / Action Registry | Not built; `Entitlement_service` is already the facade shape they expect |
| Reports | `gross − discount == net` must continue to hold on every demand |

## 7. Rollback

R1–R7 are all git-revertible; R1 additionally reverts by swapping one injected reader. The only
unrecoverable action is R8, which this plan recommends against. No step requires a data migration to
undo, because the migration is additive and the legacy collection is never mutated.

## 8. Verdict

**Not ready.** Two hard blockers: P2 (migration, operator-run) and P4 (proration gate, open). Gate 5
cannot be treated as sufficient on the current dataset.

The plan itself is sound and the blast radius is small — one injection point, four files, one
collection. But retirement should follow the full evidence chain, and two links in it are still open.
