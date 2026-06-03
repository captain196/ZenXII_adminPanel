# Fees Exemption v2 — Phase 2 release note (Option A)

> **Audience:** School Super Admins, School Admins, Accountants who manage fee concessions.
> **Status when this note ships:** Phase 2 deployed; **all feature flags OFF** until post-deployment verification completes. Once the cutover flag flip happens, the behavior described below goes live.

---

## What changed
A new **Concessions & Services** management screen (Fees → Concessions & Services) lets you record per-student concessions with effective dates, reasons, and approver tracking. Concessions are typed:

- **Percent** — e.g. 25% off Tuition Fee
- **Fixed** — e.g. ₹500 off per month off Library Fee
- **Full exempt** — head not billed at all

Concessions can be scoped to a specific fee head, a category (e.g. Transport / Hostel), or all heads. They carry `effectiveFrom` and an optional `effectiveTo`.

> Service Enrollments (transport/hostel/meals) UI is **hidden** in Phase 2 — converges in Phase 3.

## Which paths apply concessions (Phase 2, Option A)

| Path | Applies concessions? |
|---|---|
| **Admission** (`save_admission`) | ✅ Yes |
| **Promotion** (re-promote a student) | ✅ Yes |
| Chart-Save (recompute demands after editing the fee chart) | ❌ No — Phase 2.5 (chart-save shape convergence) |
| Manual Generate Demands (per student) | ❌ No — Phase 3 |
| Bulk Generate Demands (whole class) | ❌ No — Phase 3 |
| Recalc Unpaid Discounts | ❌ No — Phase 3 |

**Why the gap?** Chart-Save and the manual/bulk/recalc paths write demands using a slightly different document shape than admission/promotion. Routing them through the new concession-aware generator without an adapter would violate the "byte-identical for existing students without concessions" guarantee that was the gate for Phase 2. Phase 2.5 will deliver the adapter for chart-save; Phase 3 will converge the manual/bulk/recalc paths.

## Practical workflow during Phase 2

To apply a new concession to a student today:

1. **Create the concession** in Fees → Concessions & Services (set scope, type, value, effective dates, reason, approver).
2. To make it take effect on existing demands: **re-promote the student** (forward into the same class+section, then reverse back — or forward into the next class if doing a real promotion). The re-promotion re-runs the concession-aware generator on the destination class and writes the concession-reduced demands. Existing payments, receipts, allocations are unchanged.
3. For a brand-new admission, the concession applies automatically — just record the concession first, then admit the student.

## What does NOT change

- Existing demands for students with no concessions: **byte-identical** to pre-Phase-2.
- Existing payments, receipts, payment allocations: **unchanged**.
- Parent app, Teacher app: **no update required**. Both already read the same `feeDemands` shape; the optional new `concessionApplied` field is additive and silently ignored.
- Manual generation remains **fully available** for legitimate recovery workflows (regenerate missing demands after a chart fix, etc.). Just be aware it does not yet apply new concessions.

## Signals you'll see in the admin UI

After the cutover flag flip:

- A **green "Concession-aware"** badge appears next to the Save buttons on Admission and Promotion controls.
- An **amber "Phase 2 — legacy gen"** badge appears next to the Save buttons on Chart-Save, Manual Generate, Bulk Generate, and Recalc Unpaid Discounts.
- A blue info banner at the top of the Concessions & Services screen recaps which paths apply concessions.
- When you trigger Manual / Bulk / Recalc on a student/class that has active concessions on file, a smart-confirm modal warns you that those concessions will NOT be applied — you can cancel or proceed knowing the gap exists.

(These per-control badges and the smart-confirm modal are landing as a small follow-up commit. The release-note disclaimer above is in effect immediately.)

## Rollback

If a problem appears after the flag flip:

1. **Instant (≤30s):** flip `USE_UNIFIED_FEE_GEN=false` + `CONCESSION_UI_ENABLED=false` in `application/config/fees_exemption_v2_flags.php`; Apache restart. Behavior reverts to legacy.
2. **Code:** `git revert <p2-commit>` if a structural bug is found in the flag-gated delegate.
3. **Data:** if a concession was actually applied during the live window, revoke it in the UI and re-run legacy generation for the affected student.

## Roadmap

- **Phase 2.5** (next window): chart-save shape convergence + chart-save routing through the unified generator, with a dedicated A/B verification.
- **Phase 3**: converge manual, bulk, and recalc paths; light up the Service-Enrollment UI (transport/hostel/meals); replace `createModuleFee` with enrollment-driven generation; remove the amber legacy-gen badges + smart-confirm modal (one-line constant flip).
- **Phase 4**: mid-session reconcile (void unpaid future on discontinue / concession revoke; prorate boundary; paid-credit waits for accounting convergence debt).
- **Phase 5**: retire legacy `exemptedFees` / `discountHeads` after soak; optional parent/teacher app enhancements showing gross-vs-net and service status.

---

*Document version: 2026-05-30. Owner: school ERP team. Update on each phase cutover.*
