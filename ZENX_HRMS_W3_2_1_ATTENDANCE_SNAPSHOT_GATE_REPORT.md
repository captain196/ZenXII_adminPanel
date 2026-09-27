# ZENX HRMS — W3.2.1 — ATTENDANCE DAY-WISE PAYROLL SNAPSHOT — GATE REPORT

**Date:** 2026-08-09
**Gate:** W3.2.1 — first of six W3.2 sub-gates
**Result:** **DELIVERED** — plus one pre-existing production defect found and fixed
**Status:** UNCOMMITTED · flags **OFF** · mode **LEGACY** · nothing deployed, migrated or seeded

---

## 1. Inspection first — what was already frozen

Per §"do not implement blindly", I traced the whole attendance→payroll path before writing anything.
The slip freezes **more than the W3.1 report implied**:

Already frozen per slip: `working_days` · `days_worked` · `days_absent` · `half_days` ·
`extra_worked_days` · `paid_leave_days` · `leave_days` · `lwp_days` · `lwp_deduction` ·
`vacant_days` · `vacant_treatment` · `deduction_days` · `deduction_reason` · `leave_details` ·
`staff_window` · `att_warning`. The run additionally freezes the calendar basis.

**So the derived RESULT was already thoroughly frozen. What was missing was the EVIDENCE.**

The attendance side of the calculation is determined by five inputs. Three were not recoverable:

| Input | Frozen before? |
|---|---|
| `vacant_treatment` policy | ✅ yes |
| `working_days` (calendar basis) | ✅ yes |
| **`dayWise` attendance string** | ❌ **no** |
| **LWP day map** (which days, full vs half) | ❌ only the total count |
| **employment window per day** | ⚠️ only as `startDay`/`endDay` |

Concretely: you could read `days_absent: 3` and never learn *which* three days — and a later
attendance correction changed what a regeneration produced, with no record of the original inputs.

## 2. Design — and the three decisions worth defending

**(a) Positional strings, not a new structure.** `window_mask` (`1`/`0`) and `lwp_mask`
(`-`/`F`/`H`) are the same length as `day_wise`, indexed day-1 = offset-0 — the convention the
attendance system already uses. **No second encoding, and no second attendance system.** Payroll
consumes Attendance and freezes the payroll-relevant result; the authoritative Attendance
architecture is untouched.

**(b) A per-day window mask, not the `startDay`/`endDay` pair already on the slip.** That pair
describes exactly one contiguous window, and **a rehire inside a month is not contiguous**.
Reproducing from it would treat the unemployed gap as employed and pay it. This is directly
test-asserted (`test_window_mask_handles_rehire_within_the_same_month`).

**(c) Half vs full LWP kept distinct.** The engine treats a full-day LWP as fully covering an `A`
mark (adds 0 absent) but a half-day as covering only half (adds 0.5). A boolean would leave the
snapshot unable to reproduce the absent count stored beside it.

The `derived` block duplicates the counts **on purpose**: an auditor can re-derive them from the
masks and confirm the two agree. A divergence means snapshot and payslip disagree — exactly the
condition worth being able to detect.

## 3. When the snapshot is taken — and why

**At calculation**, in the same request that computes the slip.

- "At draft creation" and "at calculation" are **the same moment** in ZenX: `generate_payroll()`
  creates the run in `Draft` *and* computes every slip in one request. There is no earlier point.
- **At finalization would be wrong.** Inputs can drift between generation and finalization, so a
  snapshot taken then would describe values the stored numbers did not come from. A snapshot must be
  taken from what the calculation actually consumed, or it is not a snapshot of that calculation.
- Immutability belongs at finalization, and **already exists** — see §5.

## 4. The pre-existing defect this uncovered

`regenerate_staff_payroll()` builds its slip as `array_merge($oldSlip, [...])`. Any key it does not
restate **survives from the old slip**. It never restated `_salary_snapshot`.

> **Today, in production, regenerating a slip leaves `_salary_snapshot` describing the
> pre-regeneration salary structure while the money beside it is recomputed from the current one.**

A snapshot that describes inputs its own numbers did not come from is worse than no snapshot — it
gives false confidence to exactly the auditor it exists to serve. And regeneration is the one path
where the inputs are *known* to have changed, so it is where a stale snapshot is certainly wrong.

**Fixed:** both `_salary_snapshot` and `_attendance_snapshot` are now written from the values that
recomputation actually used, with `regenerated: true` so an auditor can tell which computation
produced it. Test-guarded: both slip writers must emit both snapshots.

This was found by checking *all* writers rather than only the one I was adding to.

## 5. Immutability after finalization — already enforced, not rebuilt

`regenerate_staff_payroll()` already refuses any run that is not `Draft`:

```
Run is Finalized. Call /hr/unlock_payroll first.
Run status '…' does not support regeneration. Must be Draft.
```

and `unlock_payroll` records `unlocked_from_status` for audit. **The snapshot inherits this for
free** — a finalized run's snapshot cannot change without an explicit, audited unlock. No new guard
was added, per §"reuse and improve".

## 6. Testability — why the logic moved to a helper

`Hr.php` extends `MY_Controller` and cannot load in the PHPUnit bootstrap, so logic living only
inside it is reachable by source-literal assertions and nothing stronger. Since these two functions
decide whether a finalized payslip can be reproduced, they were extracted to
`application/helpers/payroll_snapshot_helper.php` — the same move W1.3 made for `hr_next_id()`.
`Hr.php`'s static methods delegate, so there is **one implementation with two callers**.

## 7. Tests — 24 new, every §18 case covered

| §18 required case | Test |
|---|---|
| normal employee | `test_reproduces_a_normal_fully_present_employee` |
| half-day | `test_reproduces_half_day_M` + double-count guard |
| worked-on-off | `test_reproduces_worked_on_off_day_W` (asserts `W` does **not** offset `A`) |
| LOP | full and half variants, separately |
| leave | `test_reproduces_paid_versus_unpaid_leave` |
| join mid-month | `test_window_mask_handles_join_mid_month` |
| exit mid-month | `test_window_mask_handles_exit_mid_month` |
| **rehire** | `test_rehire_gap_is_not_charged` + the mask-justification test |
| holiday | `test_holiday_and_weekly_off_have_no_pay_effect` |
| weekly off | same |

Beyond encoding, twelve tests **re-derive the counts from the masks** and assert they match what
payroll recorded — including a combined month exercising `M`, `A`, `W`, `L`, `V` and both LWP
depths at once (`days_absent == 3.5`).

**Honest limit:** the reproducer in the test mirrors the engine's branch logic; it does not *call*
the engine. So it proves **the masks carry enough information to reproduce the result** — which is
what the snapshot is for. It does not independently prove the engine correct; that is `hrms_p0_verify`'s
job and it still passes.

## 8. Regression — baseline held exactly

| Suite | Before W3.2.1 | After | New failures |
|---|---|---|---|
| PHP unit | 343 / 1133 / **10** | **367 / 1184 / 10** (+24 tests) | **0** |
| `hrms_w2_parity` | 14 / 0 | **14 / 0 PASS** | 0 |
| `hrms_w2_verify` | 45 / 0 | **45 / 0 PASS** | 0 |
| `hrms_p0_verify` | PASS | **PASS** | 0 |
| `hrms_closure_verify` | 24 / 0 | **24 / 0 PASS** | 0 |

The 10 failures are the documented pre-existing set, unchanged in identity and count. `php -l` clean
on every touched file.

## 9. Constraints honoured

- **No second attendance system**; the authoritative Attendance architecture, its 9-status
  vocabulary, `M`/`W` semantics, window, holiday and weekly-off rules are unmodified.
- **No second payroll system**; one engine, one money path. No `round()` introduced — the snapshot
  stores day counts and strings, and touches no money arithmetic, so W1.2 paise precision and
  `gross == net + deductions` are untouched by construction.
- **Compensation seam intact** — W3.1 boundary unchanged, verified 45/0 and 14/0.
- **Tenant-scoped**: the snapshot rides the slip document, which is already `docId()`-scoped and
  `schoolWhere()`-read. No tenant id introduced; helper asserted free of tenant literals and RTDB.
- **RBAC unchanged** — graded `HR:manage`.
- Not deployed · not migrated · not seeded · no statutory policy · flags not flipped · **not
  committed, not pushed**.

## 10. Gate status

| Question | Answer |
|---|---|
| Can attendance and LOP inputs be reproduced after finalization? | **YES** — for runs generated from now on |
| Does finalization freeze the complete payroll input set? | **PARTIALLY** — attendance and salary yes; statutory pending W2 (W3.2.6) |
| Immutable after finalization? | **YES** — via the existing Draft-only guard |
| Auditable? | **YES** — masks + derived counts + `regenerated` marker |

**Not retroactive.** Slips generated before this wave have no attendance snapshot, and one cannot be
manufactured for them — the inputs are gone. They are identifiable by the absence of the key. I did
not backfill a synthetic snapshot from current attendance, because that would assert as historical
fact something reconstructed from data that may since have changed.

## 11. Next gate

**W3.2.2 — payroll-period override model + editable review grid.** The largest remaining W3 gap:
no override path exists at all today. It needs an override document model, deterministic
calculation semantics (original → adjustment → final), an audit trail, RBAC enforced independently
per endpoint, accounting flow-through with `Dr == Cr`, and the review grid — a full gate of its own.

**OPERATOR VERIFICATION REQUIRED** (not claimed): no browser run was performed this gate. Worth
checking on the test tenant that a freshly generated run's slips carry `_attendance_snapshot` with
masks whose lengths equal the month length.
