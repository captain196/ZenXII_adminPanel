# ZENX HRMS — W3.2.3 — LOP REVIEW / CONFIRMATION — GATE REPORT

**Date:** 2026-08-09
**Gate:** W3.2.3 — third of six W3.2 sub-gates
**Result:** **DELIVERED** — plus a standing capability register so nothing silently drops
**Status:** UNCOMMITTED · flags **OFF** · mode **LEGACY** · nothing deployed, migrated or seeded

---

## 0. Direction lock — actioned first

Per §2–§3, **`ZENX_HRMS_CAPABILITY_BACKLOG.md`** now exists as a standing register: all 26 §3 items
A–Z with status and gate, the extensibility watch-list (§5), the future RBAC capability model (§14),
and — as a **priority item** — the **NON-TAXABLE PAYROLL EARNING / REIMBURSEMENT ENGINE**.

The correction is taken: *"cannot safely be implemented with the current engine"* is a statement
about the engine, not about the product. Reimbursement remains required.

---

## 1 / 2 / 3. Existing LOP, Leave and Payroll functionality

`_process_leave_for_payroll()` is a **single shared LOP resolver**, called by exactly two callers —
`generate_payroll` and `regenerate_staff_payroll` — with a docblock stating it exists "to guarantee
identical outputs". **There is already one LOP engine.**

Its semantics, as inspected:

| Rule | Behaviour |
|---|---|
| Source | Approved leave requests only (status read case-insensitively — the Teacher app writes lowercase) |
| Paid vs unpaid | Leave-type `paid` flag, with a fallback to the leave doc's stored `type_paid` when the type was deleted |
| LOP quantity | `lwp_days` **decided at approval time**, when the balance was exhausted |
| Attribution | Paid days consumed **first**; LOP days land at the **end** of the range |
| Half-day | Always single-day, contributes exactly **0.5** |
| Employment window | Pre-join / post-exit days excluded |

Already stored per slip: `lwp_days` · `lwp_deduction` · `paid_leave_days` · `daily_salary` ·
`deduction_reason` · `leave_details[]` (request id, type, paid flag, from/to, total/paid/lwp days,
half-day flag and period, pay label) — and, since W3.2.1, `lwp_mask` (**which** days, full or half).

## 4. Reuse decisions

**Nothing was rebuilt.** No second leave engine, no second attendance system, no recomputation.
W3.2.3 is a *reporting and sign-off* layer over data the existing resolver already produced.

Test-asserted: exactly **one** `_process_leave_for_payroll` definition, exactly **two** callers, and
`lop_review()` never calls it.

## 5. Commercial HRMS gap analysis — what was actually missing

| Capability | Before | After |
|---|---|---|
| Per-employee LOP detail | ✅ existed — but only by opening one employee at a time | ✅ |
| **Run-level LOP review** | ❌ **missing** — a 200-person run needed 200 clicks, so in practice the deduction was approved unseen | ✅ |
| **Day-level visibility** | ❌ per leave-request only | ✅ named days, full vs half |
| **Sign-off — who confirmed** | ❌ **nothing existed** (zero hits repo-wide) | ✅ |
| **Finalization gate** | ❌ pay could be deducted and frozen with no record anyone looked | ✅ |
| **Correction guidance** | ❌ none | ✅ server-supplied |

The fourth row was the real defect: **pay was deducted, frozen, and paid with nothing recorded about
whether anyone had reviewed it.**

## 6. LOP review design

`GET hr/lop_review?run_id=…` returns, per affected employee: name · department · working days ·
days worked · LOP days · **`lwp_full_days[]` and `lwp_half_days[]` (actual calendar day numbers)** ·
daily salary · deduction · net pay · the leave requests that caused it · and a `balance_exhausted`
flag.

Sorted **largest loss first**, then by name — an operator checking a payroll starts with the biggest
deduction, not with whoever is alphabetically first.

**Reads frozen evidence, never live attendance.** Everything comes from the slip: the W3.2.1
`_attendance_snapshot` and `leave_details`. A review sourced from live data would stop matching the
payslip the instant anyone corrected an attendance record — the exact drift the snapshot exists to
prevent. Test-asserted: `lop_review()` contains `_attendance_snapshot` and **not**
`staffAttendanceSummary`.

**`balance_exhausted`** is surfaced because a *paid* leave type producing LOP reads as a bug to every
operator who meets it. It isn't — the balance ran out when the leave was approved — so it is labelled
rather than left to be rediscovered.

**Pre-W3.2.1 slips** carry no mask. The review says *"day-level detail not recorded for this run"*
rather than reconstructing a day list from today's attendance, which would be invented history
presented as record.

## 7. Payroll-period review integration

The panel renders **above** the slip grid, because it must be seen before finalization rather than
found by drilling into employees. It is hidden entirely when a run has no LOP.

## 8. Attendance authority boundaries — the most important guard

W3.2.2 shipped a payroll adjustment tool. §9 warns operators must not "fix" LOP with arbitrary
deductions — and that risk is now live.

Three things address it:

1. **`LOP_ADJUSTMENT` remains a refused override type**, with its reason.
2. **The server supplies correction guidance**, rendered by the UI rather than composed in the
   browser: *"LOP is derived from approved leave and attendance. To change it, correct the leave
   request or the attendance record and then regenerate this employee. Do not use a payroll
   adjustment to offset LOP — that would leave payroll and attendance permanently disagreeing."*
3. **The review is read-only.** No endpoint here writes attendance, leave, or LOP.

Test-asserted, including that the UI renders `r.correction_guidance` rather than its own text.

## 9. Override interaction

Adjustments and LOP stay in separate lanes. An adjustment never alters LOP; LOP never alters an
adjustment. Both appear in the same run review — the adjustment panel in the drill-down, LOP above
the grid — so an operator sees both without either being able to silently offset the other.

## 10. Audit

`lop_confirmation` on the run: `confirmed_by` · `confirmed_at` · `note` · **and the figures the
sign-off covered** (`lwp_days`, `lwp_deduction`, `affected_staff`). Plus a structured
`PAYROLL_LOP_CONFIRMED` log with school, run, staff count, days, deduction and actor.

**Confirmations go stale deliberately.** If a regeneration changes the LOP after sign-off, the stored
figures no longer match and finalization refuses with both sets of numbers. Otherwise a sign-off on
₹2,000 of LOP would silently authorise ₹40,000.

## 11. RBAC

`lop_review` → **`HR:view`**. `confirm_lop` → **`HR:manage`**, because a sign-off is what permits pay
to be deducted. Graded permissions throughout; no role-name gates. Test-asserted by regex on the
exact permission triples.

**Recorded, not hidden (§14):** the same `HR:manage` user can confirm *and* finalize, so there is no
maker/checker separation. That is a real commercial gap — registered as backlog **F**/**X** for W5.
The confirmation still records *who*, so the audit exists even where the separation does not.

## 12. Tenant isolation

The run is loaded via `get()` + `docId()`, which prefixes the caller's own school_id — a forged run
id from another tenant resolves to a non-existent document. Slips come from `_fsGetAllSlips()`,
already tenant-scoped. The confirmation writes to the tenant-prefixed run doc. No new collection, no
new tenant surface.

## 13. Accounting impact — **none, by design**

LOP is already priced into the slip by the existing engine and already posted by the existing
journals. Review and sign-off write **no money and no ledger entry** — they record that a human
looked. `Dr == Cr` is untouched; the verifier still passes.

## 14. Performance

| Operation | Reads |
|---|---|
| LOP review (whole run) | **2** — one run doc + one bulk slip query |
| Confirmation | 2 + 1 write |
| Finalization gate | 1 bulk slip query, on a path that already loads slips |

No per-employee reads. No N+1. The compensation seam is untouched.

## 15. Tests — 18 new, all passing first run

Behavioural (the decode): full/half decoding · exact inverse of the encoder · **one-indexed
positions at boundaries 1, 5 and 31** · no-LOP month · **missing mask returns nothing rather than
guessing** · unknown symbols ignored · fully unpaid month.

Structural: **exactly one LOP resolver with exactly two callers** · review never recomputes ·
review reads frozen evidence not live attendance · finalization consults LOP totals and the
confirmation · **the gate is conditional on `$lopDays > 0`** · staleness invalidates on either
figure · confirmation records all five audit fields · confirmation refused once out of Draft ·
`view` vs `manage` triples · corrections directed back to Attendance/Leave · `LOP_ADJUSTMENT` still
refused · **no payroll arithmetic in the browser panel**.

The mask decode went into `payroll_snapshot_helper.php` rather than `Hr.php` for the same reason as
W3.2.1: logic that decides whether a deduction can be checked deserves real tests, and `Hr.php`
cannot load in the PHPUnit bootstrap.

## 16. Browser verification — **NOT PERFORMED**

**OPERATOR VERIFICATION REQUIRED.** Written, lint-clean, routed; no browser test run and none
claimed. Unverified: the panel rendering, the confirm button round-trip, the stale-confirmation
refusal, the finalization block, and that the button is absent for a non-`manage` user.

Endpoint RBAC denial, cross-tenant rejection and the Draft-only refusal are implemented and
source-asserted but not behaviourally exercised — no CI request context in the unit bootstrap.

## 17. Test-School verification — **NOT PERFORMED**

No Test-School data was created, read destructively, or modified. Nothing was manufactured to make a
test pass.

## 18 / 19. Remaining capabilities and benchmark mapping

Full register in `ZENX_HRMS_CAPABILITY_BACKLOG.md`. Immediate:

| Gate | Item |
|---|---|
| **W3.2.4** | Full calculation trace (B) — partial |
| **W3.2.5** | Async / scalable generation (C) — unassessed at 500/1k/5k |
| **W3.2.6** | Statutory snapshot (D) — upstream-blocked, architecture still required |
| **W5** | Maker/checker (F, X) · adjustment approval (G) · bulk import (H) · ESS (Q, R) |
| engine gate | Non-taxable earning / reimbursement (E) |
| UAT | Browser (J) · integration (K) |

Against the benchmark's LOP workflow — **visibility, day-level detail, source traceability,
monetary impact, sign-off with attribution, and a finalization gate** are present. **Absent:**
maker/checker separation and a bulk LOP correction path. Neither is dropped; both are registered.

This does not claim benchmark parity. It claims LOP moved from *invisible in aggregate and
unsigned* to *reviewable, attributable and gated*.

## 20. Confirmation — nothing silently dropped

**Confirmed.** No capability was removed, reinterpreted, downgraded or narrowed. §3's A–Z are all
carried in the register with an explicit status and gate; §5's extensibility list and §14's RBAC
model are recorded; §2's reimbursement item is the register's priority entry.

**One payroll engine · one LOP resolver · one attendance system · one leave system · one accounting
path · one compensation seam · one RBAC.** No second anything was created.

---

## Regression

| Suite | Before W3.2.3 | After | New failures |
|---|---|---|---|
| PHP unit | 402 / 1303 / **10** | **420 / 1355 / 10** (+18) | **0** |
| `hrms_w2_parity` | 14 / 0 | **14 / 0 PASS** | 0 |
| `hrms_w2_verify` | 45 / 0 | **45 / 0 PASS** | 0 |
| `hrms_p0_verify` | PASS | **PASS** | 0 |
| `hrms_closure_verify` | 24 / 0 | **24 / 0 PASS** | 0 |
| `hrms_w13_verify` | 42 / 0 / 1 | **42 / 0 / 1 PASS** | 0 |

The 10 are the documented pre-existing set, unchanged in identity and count. **No baseline failure
was "fixed" in passing.** `php -l` clean on every touched file.

## Final state

- **Implemented:** `lop_review()` · `confirm_lop()` · `_lop_totals()` · the finalization LOP gate ·
  `payroll_lwp_days_from_mask()` · the review panel and confirm flow · 2 routes · 18 tests · the
  standing capability register.
- **Reused:** the single LOP resolver · the W3.2.1 frozen snapshot · `leave_details` · the run
  lifecycle and its locks · `_fsGetAllSlips` · graded RBAC · tenant primitives · the existing grid.
- **Discovered:** LOP sign-off did not exist anywhere in the repo; a *paid* leave type can legitimately
  produce LOP (balance exhausted at approval) and reads as a bug without a label.
- **Fixed:** pay could be deducted, frozen and paid with no record that anyone reviewed it.
- **Deferred / blocked:** per the register, each with a reason and a gate.
- **Behaviour change worth flagging:** finalizing a run **that deducts LOP** now requires a
  confirmation. Runs with no LOP finalize exactly as before — the gate is conditional, and tested as
  such.
- **Production data changed:** **none.** **Deployed:** **nothing.** **Committed/pushed:** **nothing.**
- **Browser verification:** **not performed, not claimed.**
