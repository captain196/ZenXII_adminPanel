# ZENX HRMS — IMPLEMENTATION-ALIGNMENT REPORT

**Objective:** evolve the existing Staff + HR + Payroll foundation so it correctly consumes the current Attendance and current RBAC systems.

**Status: AUDIT COMPLETE — NO CODE CHANGED.** This is the worklist. Nothing in it has been implemented.

**Method:** direct code verification against the current working tree (`integration/phase2` @ `758c924`). Every claim carries file:line. Historical reports were used as context only, and **two of my own earlier conclusions are corrected below** (§6, §7).

---

## 0. Repository-state gate (§J) — result

| Check | Result |
|---|---|
| `yug_testing` pull present? | **YES.** `origin/yug_testing` (2026-08-05) has **0** commits missing from local HEAD. Folded in via merge `5291d63`. Local HEAD `758c924` (2026-08-07) is newer than every remote branch. |
| RBAC = new/upgraded? | **CONFIRMED.** Commit `7ccebc0` (2026-07-23) rewired ~50 controllers; added `Admins_fold.php` (+505), `Admin_role_backfill.php` (+149); `staffCapabilities` Cloud Function mirrors the PHP resolver. `_require_role()` is a **graded-authoritative bridge**, not a legacy gate (`MY_Controller.php:1190-1240`). |
| Attendance = authoritative implementation? | **YES — treated as authoritative and integrated against as-is.** Recorded for accuracy: the current implementation has GPS/geofence + biometric/RFID/face device capture + raw-punch/derived-status separation + regularisation with a self-approval guard + CAS-protected writes, but **no per-staff shift assignment, no roster, no overtime, no midnight-crossing** (§2). I integrated against what exists and did not invent those. |

---

## 1. Attendance → Payroll (§F) — **CONTRACT BROKEN. Two money defects.**

The single most important finding. Legacy Payroll is consuming an **older, narrower** Attendance vocabulary than the current writer emits.

**Producer** — `Staff_attendance_writer.php:69` emits **9** status chars, with its own comment at `:60-63` noting `M`/`W`/`O` were *"added in the company-schedule build — hours-based half-day, auto-absent finaliser, and work-on-off extra pay."*

```
P present · A absent · L leave · H holiday · T late
V vacant · M half-day · W worked-on-off · O weekly-off
```

**Consumer** — `Hr.php::generate_payroll()` dayWise parser (`Hr.php:5836-5868`) branches on **3**: `'A'`, `'L'`, `'V'`. Its inline comment (`:5816`) documents only six and never mentions `M`/`W`/`O`.

| Char | Producer meaning | Payroll behaviour | Impact |
|---|---|---|---|
| `M` | Half-day | falls through every branch → **paid as a full day** | 🔴 **Overpayment.** The writer's own comment (`:204`) says *"payroll weights halfDay as 0.5 later"* — **it does not.** Verified: every `half_day` hit in `Hr.php` (`:3699-3870`, `:4230`, `:8144-8160`) belongs to the **Leave** module; none parse the attendance char. |
| `W` | Worked on off-day (extra pay) | ignored entirely — **0** references to `extraWorked` in `Hr.php` | 🔴 **Underpayment.** The status exists specifically to trigger extra pay. |
| `O` | Weekly-off | never read. Payroll independently derives `$workingDays = $daysInMonth - $nonWorkingDays` (`Hr.php:5740`) | 🟠 Two independent definitions of non-working days that can disagree. |
| `P`,`H`,`T` | Present / holiday / late | fall through → treated as paid | 🟢 Correct outcome, but by omission not by design. |

Also: the summary's `workingDays` field (`Staff_attendance_writer.php:204`) is **never consumed** by payroll — `Hr.php:5740` recomputes it. Dead producer field.

**Required change — adapt Payroll to current Attendance (never the reverse):**

| # | Change | Location | Severity |
|---|---|---|---|
| A1 | Handle `'M'` → `$daysAbsent += 0.5` (respecting `$staffLwpSet` so attendance half-day and leave half-day never double-count) | `Hr.php:5836-5868` | 🔴 Critical |
| A2 | Handle `'W'` → accumulate `extraWorkedDays`, surface as a payslip earning line | `Hr.php` parser + `_compute_payslip()` | 🔴 Critical |
| A3 | Make `'O'` authoritative for non-working days, or explicitly document `_staff_working_days()` as the single source and stop emitting a competing signal | `Hr.php:5740` vs writer `:204` | 🟠 High |
| A4 | Add a contract test asserting the parser handles every char in `Staff_attendance_writer::ALLOWED_STATUS` — so the next status char added cannot silently regress payroll | `tests/Unit/` | 🟠 High |

A4 is the durable fix: this class of break recurs every time Attendance evolves.

---

## 2. Staff → Attendance (§G) — thin coupling, one security gap

Attendance reads far less off `staff` than expected — which is good for decoupling, but leaves gaps.

- `Attendance_policy.php`, `Staff_attendance_finaliser.php`, `Staff_attendance_writer.php` read **zero** staff documents. `Attendance_policy.php:20-26` declares this boundary deliberately ("NO Firestore… every input is PASSED IN"). Clean, reusable — keep as-is.
- GPS punch (`Staff_attendance.php:500-512`) reads exactly **one** field: `status`/`Status` must be `active`.
- Roster reads (`Attendance.php:1765-1774`) use `status`, `staffId`, `Name`, and `Department`/`designation` **for display only** — no rule consumes them.

**Gaps:**

| # | Finding | Evidence | Severity |
|---|---|---|---|
| B1 | **Device-punch path checks staff existence but NOT `status`.** An **inactive/terminated** employee can still punch via a biometric/RFID/face device. The GPS path correctly rejects them. | `Attendance.php:3037` (existence only) vs `Staff_attendance.php:512` | 🔴 Security |
| B2 | **No per-staff shift assignment exists.** `shiftId` is hardcoded to the literal `'default'` at the only call site; the engine defaults it again and resolves against school-global `policy.shifts`. `policy.shifts` can hold many named shifts but **no code path can select one.** | `Staff_attendance.php:222`, `Attendance_policy.php:92`, `:355-359` | 🟠 High (blocks Retail/roster) |
| B3 | No per-staff attendance policy, weekly-off pattern, or attendance exemption. All school-global. | grep: 0 hits for `attendancePolicyId`, `attendanceExempt` | 🟠 High |
| B4 | `locationId` (Phase 1) is written but **no attendance consumer** — no geofence-per-location, no location-scoped register. | read only by `terminology_helper.php:74`, `Workforce_locations.php:153` | 🟡 Medium |
| B5 | `employmentType` is written and dual-cased but **read by nothing** outside views. | `Staff.php:2203,2263` | 🟡 Low |

**Required:** B1 is a one-line parity fix and should ship first. B2/B3 are the real HRMS Core build-out (shift assignment on the staff record + policy resolution by staff → shift), and they are the prerequisite for the Retail pack.

---

## 3. Staff → Payroll — **CRITICAL: employment-window data does not exist**

Payroll pro-rates pay using an employment window, reading a `??` chain of aliases. **Most of that chain is never written by anything.**

| Field read | Read at | Written? |
|---|---|---|
| `joinDate` (**1st** in chain) | `Hr.php:5802, 8173, 8460` | ∅ **never written anywhere** |
| `Joining Date` (2nd) | same | ∅ **never written anywhere** |
| `Date Of Joining` (3rd) | same | ✅ `Staff.php:2198` (format `d-m-Y`) |
| `exitDate` (1st) | `Hr.php:5803, 8174, 8461` | ∅ **never written anywhere** |
| `Resignation Date` (2nd) | same | ∅ **never written anywhere** |
| `leaveDate` (3rd) | same | ∅ **never written anywhere** |

**Consequence — there is no exit date on the employee record at all.** Verified by whole-tree grep: all three exit aliases appear only as reads in `Hr.php`. Therefore `_staff_working_days()` (`Hr.php:8243`) always clamps the window to month-end, and `_in_employment_window()` (`Hr.php:8081`) never excludes a post-exit day. The two available outcomes are **both wrong**:

- Leave a terminated employee `Active` → **paid a full month** after they left.
- Flip to `Inactive` → they vanish from the payroll roster query (`Hr.php:5569`) **retroactively and entirely** → their final partial month is **never paid**.

There is no correct way to terminate an employee mid-month today.

**Second gap:** `Firestore_service::saveStaff()` callers — `AdminUsers.php:625` and `Staff_access.php:630` (`onboard_staff`) — supply **no** `Date Of Joining`. Staff created through those paths have no DOJ, so `_staff_working_days()` treats them as full-month with no proration.

**Third gap:** `sessions` is written once at creation (`Staff.php:2250`) and `edit_staff()` **never re-adds** the current session; only `saveStaff()` merges (`Firestore_service.php:1056-1060`). A staff member created in session A is invisible to `bulk_mark_staff` in session B (`Attendance.php:2241`).

| # | Required change | Location | Severity |
|---|---|---|---|
| C1 | Add `exitDate` (+ reason, last-working-day) to the staff record and to the exit workflow; make `set_status('Inactive')` capture it | `Staff.php::set_status()` `:2444`, staff schema | 🔴 Critical |
| C2 | Stop payroll silently dropping exited staff — select the roster by employment window, not by `status=='Active'`, so a final partial month is paid | `Hr.php:5569` | 🔴 Critical |
| C3 | Write `Date Of Joining` in `saveStaff()` paths, or make DOJ mandatory at creation | `AdminUsers.php:625`, `Staff_access.php:630` | 🟠 High |
| C4 | Collapse the dead `joinDate`/`Joining Date`/`exitDate`/`Resignation Date`/`leaveDate` alias chains to one canonical field each | `Hr.php:5802-5803`, `:8173-8174`, `:8460-8461` | 🟡 Medium (hygiene, prevents false confidence) |
| C5 | Re-add current session to `sessions[]` on `edit_staff()` | `Staff.php:3205` | 🟠 High |

---

## 4. HR / Payroll → current RBAC (§H, §I) — **mostly migrated; 6 real bypasses**

**Correcting the working premise:** the legacy controllers are *already* on the graded RBAC. **108 of 110** `_require_role()` calls supply `module`+`level`. Only **2** omit the module (`Hr::payroll_health` `:6862`, `payroll_health_page` `:6846`). Both `Hr.php:59` and `Staff.php:128` also carry a constructor-level `require_permission()` floor.

The real defects are a different class: **inline `in_array($this->admin_role, …)` checks that re-grant access after the graded gate has already passed at a lower level.** The new `_require_role` bridge was written specifically to stop name-based re-granting (`MY_Controller.php:1204-1212`) — but it only guards `_require_role`, not these hand-rolled checks.

| # | Defect | Evidence | Severity |
|---|---|---|---|
| D1 | **`download_payslip` — cross-staff payslip PDF.** Clamp to self fires only if *both* `!has_permission('HR','manage')` **and** role-name ∉ `ADMIN_ROLES`. A user whose role string is `Principal`/`HR Manager` but graded **down** to `HR:view` keeps the attacker-supplied `?staff_id=` and downloads **any** employee's payslip — salary, bank details, PAN/PF/ESI. | `Hr.php:6338` gate + `:6344` bypass | 🔴 Critical |
| D2 | Same idiom re-grants cross-staff **leave balances** | `Hr.php:3363` | 🔴 Critical |
| D3 | Same idiom re-grants cross-staff **leave requests** | `Hr.php:3569` | 🔴 Critical |
| D4 | Same idiom permits **applying for leave on behalf of** another employee | `Hr.php:3677` | 🔴 Critical |
| D5 | **`Payroll::get_employee_history` — IDOR.** Gated `HR:view`; `employee_id` read straight off the query string, no ownership check. Any `HR:view` holder enumerates anyone's accrual/payout history. | `Payroll.php:290-291` | 🔴 Critical |
| D6 | `get_report` — allow-list/level mismatch (`VIEW_ROLES` + `manage`) guarding all-staff `gross_salary` (`:7812-7832`). Currently unreachable via fallback thanks to the constructor floor, but one constructor change from leaking. | `Hr.php:7733` | 🟠 High (latent) |
| D7 | `get_leave_audit_log` (`:3311`) and `get_appraisals` (`:7247`) expose **all-staff** data at `HR:view` with no row scoping. | as cited | 🟠 High |
| D8 | Two module-less gates → add `'HR','manage'` | `Hr.php:6846`, `:6862` | 🟡 Medium |
| D9 | No `Payroll` RBAC module — payroll rides entirely on `'HR'`, so "can administer HR" ⇒ "may see everyone's pay". | `functions/rbac_modules.json`; `Payroll.php:84+` | 🟠 High (design) |

**Correct fix for D1–D4:** delete the `in_array($this->admin_role, …)` disjunct and scope on `has_permission('HR','manage')` alone. That is exactly "adapt legacy HR/Payroll to the current RBAC" — the graded map becomes the sole authority, as the upgraded design intends.

🟢 **`my_payslips` is correct** — scoped from `$this->admin_id` (session identity), never from a request param; only `run_id` is accepted, and slips are filtered `['staffId','==',$staffId]` (`Hr.php:7040-7089`). No IDOR.

🟡 `Hr.php:27` `ADMIN_ROLES` and `:30` `HR_ROLES` are byte-identical — the only real HR-ops/payroll separation is the `$level` argument. `Staff.php:31 VIEW_ROLES` (contains `Teacher`, `Class Teacher`, `Academic Coordinator`) is the widest school-specific list.

---

## 5. Leave → Payroll — 🟢 **SOLID, no change required**

One shared `_process_leave_for_payroll()` (`Hr.php:8108`) is used by **both** `generate_payroll` and `regenerate_staff_payroll` — its docblock states this is deliberate, *"to guarantee identical outputs."* Handles half-day leave at 0.5, honours CR-1 (a paid type with `lwp_days>0` still deducts when balance was exhausted at decide time), and CR-4 employment-window filtering. Returns an `lwpDaySet` the attendance parser consumes to avoid double-counting.

Note the contrast that sharpens §1: **half-days are handled correctly in the Leave path and not at all in the Attendance path.**

---

## 6. Payroll → Accounting — 🟢 **SOLID. Correcting my earlier report.**

My Phase-0 audit characterised `Hr.php` as the attendance-aware engine and `Payroll.php` as the only accounting integration. **That was wrong.** `Hr.php` posts to accounting directly and does so well:

- Canonical journal id via `PayrollAccountingService::journalIdFor($runId,$seq)` (`Hr.php:1349`) — identical to the engine's `entryId`, so the recorded id always matches the ledger.
- **Transactional outbox**: the payment record is committed as durable intent at `journal_status='pending'` **before** the journal posts, flipped to `'posted'` only on confirm (`Hr.php:1364-1373`); `accrual_journal_status` follows the same pattern (`:6108`, `:6165`).
- `PayrollReconciler` idempotently re-posts anything left `pending`.
- Accounting-period-open guards (`Hr.php:1237-1252`) and chart-of-accounts validation (`:1156-1198`) run **before** payroll generates.

**Required: verification, not construction.** The one open item is the pre-existing `PayrollAccountingService` chart-of-accounts reconciliation already scoped as the Phase-2 prerequisite.

---

## 7. Corrections to my own earlier reports

Recorded explicitly so they don't propagate:

1. **Payroll→Accounting is integrated in the canonical engine** (§6). My Phase-0 gap analysis implied otherwise.
2. **`_require_role()` is not a legacy name-gate.** It is a graded-authoritative bridge that consults `has_permission()` first and lets the graded map **deny** authoritatively. My Phase-0 report (echoing CLAUDE.md) framed it as legacy shadowing RBAC. The genuine bypasses are the separate inline `in_array()` checks (§4).
3. **`hrms_core` is currently unreachable** — not in `Superadmin_plans::AVAILABLE_MODULES`, so Phase 1 shipped an enforced gate with no supported way to turn it on (full detail in `ZENX_MULTI_TENANT_HRMS_ENTITLEMENT_ANALYSIS.md`).

---

## 8. Prioritised worklist

**P0 — money and data-exposure defects (ship first, small, independently testable)**

| ID | Change | File |
|---|---|---|
| D1–D4 | Remove `in_array($this->admin_role,…)` bypasses; scope on `has_permission('HR','manage')` alone | `Hr.php:3363, 3569, 3677, 6344` |
| D5 | Scope `get_employee_history` to the caller, or raise to `HR:manage` | `Payroll.php:290` |
| A1 | Attendance `'M'` half-day → 0.5 LOP | `Hr.php:5836-5868` |
| A2 | Attendance `'W'` worked-on-off → extra-pay earning line | `Hr.php` parser + `_compute_payslip()` |
| B1 | Device-punch path must check `status=='Active'` | `Attendance.php:3037` |
| C1+C2 | Capture `exitDate`; select payroll roster by employment window, not `status=='Active'` | `Staff.php:2444`, `Hr.php:5569` |

**P1 — correctness / integrity**

| ID | Change |
|---|---|
| A3 | Single authoritative definition of non-working days |
| A4 | Contract test over `ALLOWED_STATUS` (prevents recurrence of §1) |
| C3 | `Date Of Joining` on `saveStaff()` paths |
| C5 | Re-add session to `sessions[]` on edit |
| D6, D7, D8 | Level/allow-list mismatches, row scoping, module-less gates |

**P2 — HRMS Core build-out (the genuinely missing engine)**

| ID | Change |
|---|---|
| B2, B3 | Per-staff shift assignment + policy resolution — prerequisite for Retail roster |
| C4 | Collapse dead alias chains |
| D9 | Decide whether `Payroll` becomes its own RBAC module (cross-system: PHP + CF + rules) |
| B4 | Give `locationId` a real consumer (geofence-per-location, location cost-centre) |

---

## 9. Version / generation matrix (§K) — as verified

| Component | Current Generation | Source/Baseline | Rebuild? | Required Action | Integration Target |
|---|---|---|---|---|---|
| Staff | Legacy | Existing ZenX | NO | Enhance — exit date, DOJ on all paths, session upkeep, shift assignment | HRMS Core |
| HR | Legacy | Existing ZenX | NO | Refactor — remove RBAC bypasses, row-scope reads | HRMS Core |
| Payroll | Legacy | Existing ZenX | NO | Refactor — consume all 9 attendance statuses; employment-window roster | HRMS Payroll |
| Attendance | Current/authoritative | ZenX (GPS/device/regularisation) | NO | Integrate only. **Deviation from stated baseline:** no shift assignment, roster, overtime, or midnight-crossing present — recorded, not invented | HRMS Core |
| RBAC | New/upgraded | Upgraded ZenX (`7ccebc0`) | NO | Use as-is; migrate legacy call sites onto it | All HRMS |
| Leave | Existing | ZenX | NO | No change required (§5) | HRMS Core |
| Accounting | Existing | ZenX | NO | Verify only (§6); CoA reconciliation remains the Phase-2 prerequisite | HRMS Payroll |

---

## 10. Confirmations

- No Attendance rebuild, no second attendance system, no `hrms_attendance`.
- No RBAC rebuild, no second permission system. Legacy authorization is adapted **to** the current RBAC.
- No second Employee Master — `staff` remains canonical and is extended in place.
- No RTDB. No accounting duplication. No destructive migration proposed.
- **Nothing implemented.** No file modified by this report. Awaiting approval of the P0 worklist.
