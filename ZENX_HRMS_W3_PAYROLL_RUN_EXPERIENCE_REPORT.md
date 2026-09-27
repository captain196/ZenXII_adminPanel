# ZENX HRMS — W3 PAYROLL RUN EXPERIENCE — W3.1 REPORT

**Date:** 2026-08-09
**Wave:** W3.1 — compensation seam + preflight that names people
**Result:** **DELIVERED** — seam wired in legacy mode, preflight upgraded, baseline held
**Status:** UNCOMMITTED · flags **OFF** · mode **LEGACY** · nothing deployed, migrated or seeded

---

## Executive summary — and a correction to my own earlier gap matrix

§19 required a reuse inspection before any code. It changed the shape of W3, and the honest
version of that finding matters more than anything I built this wave:

**ZenX already has a payroll run workflow, and it is substantially more complete than the W2.7
report's gap list implied.** That list (W2.7 §S/T) named "payroll run experience · snapshot ·
per-employee warnings · trace · editable grid · async payslips" as W3 work. Inspection shows
**two of those already exist in production.**

What already exists — reused, not rebuilt:

| Capability | Evidence |
|---|---|
| Run lifecycle Draft → Finalized → Paid | `generate_payroll` · `finalize_payroll` · `approve_payroll` · `mark_payroll_paid` |
| Period locking | `lock_payroll_month` · `unlock_payroll` · `_check_payroll_lock` |
| Employment-window scoping | `staff_window` on each slip; joiners/leavers already excluded |
| Attendance integration | `staffAttendanceSummary`, 9-status vocabulary, `vacant_treatment` override |
| Leave / LOP inputs | `leave_details`, `lwp_days`, `paid_leave_days`, `lwp_deduction` |
| **Per-slip salary snapshot** | **`_salary_snapshot` — already freezes the structure so later edits can't reprice a slip** |
| Calendar-basis snapshot | `working_days` · `days_in_month` · `sundays` · `off_saturdays` · `holidays` on the run |
| **Named per-employee warnings at generation** | **`"Rahul Sharma: no salary data — skipped"` — already the benchmark format** |
| Accounting | Transactional outbox + `PayrollAccountingService` + reconciler + account/period preflight |
| Payslip retrieval & export | `get_payroll_slips` · `export_payroll_report` |
| Per-employee recalculation | `regenerate_staff_payroll` |
| Run health | `payroll_health` / `payroll_health_page` |

So W3 is **not** "build the payroll run". It is **closing the specific quality gaps in the run that
already exists** — which is what §19's "reuse and evolve, do not build a second payroll system"
asks for. Building a parallel run would have discarded a working accounting outbox and a
certified money engine.

---

## A. What W3.1 delivered

### 1. The compensation seam — §7's hard requirement

§7 says W3 must sit behind `Compensation_reader` so the later cutover doesn't force a W3 rebuild,
and must not hardwire to legacy salary fields. Before this wave the two payroll-critical reads were
inline `schoolWhere('salarySlips', …)` queries — exactly the hardwiring §7 forbids.

Both now resolve through the seam:

| Site | Before | After |
|---|---|---|
| `generate_payroll()` | inline structure query | `_compensation_reader()->resolveMany($roster, $periodEnd)` |
| `preflight_payroll()` | inline structure query | same seam, same call |

**Three design decisions worth stating, because each was a trap:**

**(a) Bulk, not per-employee.** The obvious wiring — call `resolve()` inside the payroll loop —
would have turned one Firestore query into one read *per employee*. That is an N+1 that grows with
headcount and fails the 8th (performance) gate. So `resolveMany()` is the payroll-facing form:
in legacy mode it performs **the same single query** the inline loop did.

**(b) Legacy mode returns the raw document, not the normalised thirteen fields.** Payroll reads the
13 canonical fields **plus `_version` and `source`**, which are not in `LEGACY_FIELDS`. A
normalising reader would have silently dropped both. Passing the raw doc through makes the swap
**byte-identical rather than merely equivalent**.

**(c) Recorded, not papered over:** component mode synthesises the 13 fields and therefore must also
synthesise `_version` and `source` **before the flag can move**. That is now a documented
cutover-gate obligation in the reader. Defaulting them would fabricate provenance.

**The effective date is period end**, not `today`: an effective-dated raise mid-month is in force by
period end, and period end is stable for a closed month in a way `today` is not — regenerating a
finalized run must never silently reprice it.

**Hr.php names nothing behind the seam.** The reader constructs its own assignment service on
demand, so the payroll controller depends on the seam and on nothing behind it — verified by source
assertion for all four component-model names.

### 2. Preflight names people, not counts

This is the §Q gap, and the fix was smaller than expected because **the data was already there and
being thrown away**:

```php
// before — the named list was computed, then discarded into a number
$missingStructures = array_diff($rosterIds, $staffWithSalary);
$warnings[] = count($missingStructures) . ' staff member(s) … will be skipped.';
```

"3 will be skipped" gives an operator a number they cannot act on. Generation has *always* named
them — so preflight was the weaker half of the same story, and you only learned **who** after
running payroll.

Preflight now returns a `blockers[]` array: `staff_id`, `staff_name`, `department`, `severity`,
`reason`, `resolution` — sorted blockers-first, then by name, because an operator works top-down and
the things that stop payroll must not sit under the things that merely inform it. Employees missing
attendance are named too, as `warning` not `blocker` — "treated as fully present" is a defined
outcome, not a failure, but you still deserve to know who it is about to apply to.

`warnings[]` is **retained unchanged**, so nothing reading it today regresses.

### 3. It renders

Per the W2.5 standard ("do not deliver backend endpoints only"), `_renderPreflightOk()` now shows a
scrollable, severity-accented table of affected employees with the resolution for each — extending
the existing renderer, in the existing AdminLTE/terracotta conventions, no new framework.

---

## B. Six boundary assertions re-stated — not deleted

Wiring the seam broke **six** source-level guards asserting `Hr.php` contained no reference to
`Compensation_reader`. That boundary was moved deliberately: the seam *is* the wiring, and it is the
whole point of having a seam.

Each was **re-stated, not relaxed** — the same discipline as W2.7's `test_payroll_path_is_untouched_by_w2_2`:

| Guard | Now asserts |
|---|---|
| `CompensationReaderTest` | payroll reaches compensation **only** through the seam |
| `StatutoryEngineTest` | seam present; component collections still absent |
| `SalaryTemplateAndIntegrationTest` | templates/assignments still invisible; `Payroll.php` still clear |
| `SalaryAssignmentTest` · `SalaryComponentMasterTest` | **unchanged** — pass as written |
| `Hrms_w2_parity` A4/A5/A6 | seam present · nothing read past it · `Payroll.php` clear |
| `Hrms_w2_verify` C | seam present · assignment service not constructed in the controller |

The protective half survives in every case: **a future change that starts resolving assignments
inline still fails.** Two of the six needed no edit at all once the design was corrected — see §C.

## C. One bug I introduced, and the design change it forced

My first pass named `staffSalaryAssignments` and `salaryComponents` **in a prose comment** inside
`Hr.php`. These are literal-source-regression guards, so the comment tripped the very assertions it
described.

The lazy fix would have been to loosen the guards. Instead the **design** changed: the reader now
constructs its own assignment service, so `Hr.php` names no component-model class at all. That is a
cleaner seam *and* it let two guards stay exactly as written. Verified: all four names now appear
**zero** times in `Hr.php`.

## D. Regression — baseline held exactly

| Suite | Baseline | After W3.1 | New failures |
|---|---|---|---|
| PHP unit | 343 / 1130 / **10** | **343 / 1133 / 10** | **0** |
| `hrms_w2_parity` | 11 / 0 | **14 / 0** (+3) | 0 |
| `hrms_w2_verify` | 44 / 0 | **45 / 0** (+1) | 0 |
| `hrms_p0_verify` | 125 / 0 / 1 | **PASS** | 0 |
| `hrms_w13_verify` | 44 / 0 / 1 | **42 / 0 / 1 PASS** | 0 |
| `hrms_closure_verify` | 24 / 0 / 0 | **24 / 0 / 0 PASS** | 0 |

The 10 unit failures are the documented pre-existing set (1 `BackupTest` format drift, 4 `Homework*`,
3 `ParentHomeworkRepo*`, 2 `TeacherExecuteDeleteCascade*`) — unchanged in identity and count.
`php -l` clean on every touched file.

Parity still reports **8/8 structures, no divergence** — wording unchanged, never "equivalence proven".

## E. Flags, cutover, tenancy, RBAC

- `USE_COMPONENT_COMPENSATION` **false** · `COMPENSATION_SHADOW_COMPARE` **false** · mode resolves
  **LEGACY** — verified at runtime this wave.
- Collections still empty (0/0/0). D1 still passes: *"flag correctly still false while the
  collections are empty."*
- **The W2 cutover is not advanced by this wave, and is not meant to be.** It remains blocked on
  your statutory decision. What changed is that flipping it is now a mode change in one place
  instead of a payroll rewrite.
- Tenancy: every read is `schoolWhere()`-scoped; no tenant id introduced.
- RBAC: unchanged, graded `HR:manage`. Gap 92 (no separate Payroll module) is still W5.

---

## F. What remains in W3 — honest classification

W3.1 covered the §7 seam mandate and the §Q preflight gap. Against the benchmark workflow, the
remaining true gaps are:

| # | Gap | Status | Why it wasn't this wave |
|---|---|---|---|
| 1 | **Attendance day-wise not frozen into the slip** | **MISSING** (confirmed: 0 hits) | Real reproducibility gap: the structure is frozen but the attendance that priced it is not, so a retro attendance edit changes what a finalized run *would* recompute. Needs a snapshot-shape decision. |
| 2 | **No draft override / editable grid + override audit** | **MISSING** (no override endpoint exists) | The largest remaining item. Needs an override model, a re-derivation rule, and an audit trail — a full gate of its own. |
| 3 | LOP sync/confirm step | PARTIAL | Inputs exist (`lwp_days`, `leave_details`); the explicit confirm-before-run control does not. |
| 4 | Calculation trace | PARTIAL | `deduction_reason` / `att_warning` exist; a full per-line trace does not. |
| 5 | Async payslip generation | MISSING | Synchronous today; matters at headcount, not correctness. |
| 6 | Statutory snapshot on the run | MISSING | Blocked upstream — there is no statutory policy to snapshot until the W2 decision. |

**Nothing was dropped or reclassified out of scope.** Each carries a wave.

## G. Stop conditions — all honoured

Not deployed · no tenant data migrated · no production components seeded · no statutory policy
configured · W2 compensation flag **not flipped** · **not committed, not pushed**.

## H. What I did not verify

The preflight UI change is **untested in a browser** — I lint-checked it and it extends an existing
renderer, but I have not run it on `localhost:8080`. The named-blocker table is worth a look on a
tenant that actually has an employee missing a structure, since that is the case it exists for.
