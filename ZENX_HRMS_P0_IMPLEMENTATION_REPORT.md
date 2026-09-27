# ZENX HRMS — P0 IMPLEMENTATION REPORT

**Phase:** P0 foundation hardening — evolving the existing Staff + HR + Payroll foundation to work correctly with the current Attendance and current graded RBAC.

**Status: IMPLEMENTATION COMPLETE — STOPPED FOR REVIEW.** Not committed, not pushed, not merged, not deployed.

---

## 1. Files changed

| File | Δ | P0 items |
|---|---|---|
| `application/controllers/Hr.php` | +208 / −10 | D1–D4, A1, A2, C2 |
| `application/controllers/Payroll.php` | +18 / −0 | D5 |
| `application/controllers/Attendance.php` | +11 / −0 | B1 |
| `application/controllers/Staff.php` | +93 / −2 | C1, P0-8 |
| `application/controllers/Staff_access.php` | +7 / −1 | P0-7 |
| `application/controllers/AdminUsers.php` | +8 / −0 | P0-7 |
| **`tests/Unit/AttendancePayrollStatusContractTest.php`** | **NEW** | contract guard |

**333 insertions, 12 deletions.** Every one of the 12 deletions is an intended in-place replacement (4 bypass conditions, 3 stale comments, 1 method signature, 2 call sites, plus 2 lines belonging to pre-existing Phase-1 work already in the tree). No unintended removals — verified by reviewing every `-` line in the diff.

**Not touched:** `_process_leave_for_payroll()`, `PayrollAccountingService`, `PayrollReconciler`, any Attendance writer/policy/finaliser, RBAC helpers, `firestore.rules`, indexes, Cloud Functions.

---

## 2 & 3. Exact changes and why each was required

### P0-1 / D1–D4 — RBAC bypasses removed (`Hr.php`)

Four sites used `has_permission(...) || in_array($this->admin_role, <ROLE_LIST>)`. The second disjunct re-granted access **after** the graded map had already evaluated the caller — defeating the exact protection the upgraded `_require_role()` bridge exists to provide (it deliberately denies when a user holds a module at too low a level, rather than falling through to names).

| Site | Method | Was exposed |
|---|---|---|
| `:3363` | `get_leave_balances` | cross-staff leave balances |
| `:3569` | `get_leave_requests` | cross-staff leave requests |
| `:3677` | `apply_leave` | **filing leave on behalf of another employee** |
| `:6344` | `download_payslip` | **any employee's payslip PDF — gross/net pay, bank account, PAN/PF/ESI** |

Each now scopes on the graded capability alone (`HR:edit`, or `HR:manage` for payslips).

**Why no legitimate access is lost — verified before editing:** `RBAC_BYPASS_ROLES` is only `Super Admin` / `School Super Admin`, and `has_permission()` short-circuits true for both. `ROLE_ADMIN` holds `ALL_MODULES`; `ROLE_HR_MANAGER` holds `['HR','Attendance','Reports']`. The only name that loses anything is **`Principal`, whose default catalogue does not include `HR` at all** — so a default Principal never reached these endpoints anyway (`require_permission('HR')` at `Hr.php:59` blocks them at construction). The bypass was only live for tenants that had granted Principal `HR:view`, which is precisely the escalation this fixes — and the same call the Firestore rules layer already made ("'Principal' REMOVED (audit C3)").

### P0-2 / D5 — Employee-history IDOR (`Payroll.php:290`)

Gated at `HR:view` and took `employee_id` straight from the query string with no ownership check, so any `HR:view` holder could enumerate any employee's accrual/payout history. Cross-employee access now requires `HR:manage`; everyone else is clamped to `$this->admin_id` (the raw uid, which *is* the `employee_id` on accrual/payout docs). Tenant isolation was already correct one layer down — `Payroll_operational_repo::listEmployeeHistory()` filters `schoolId` + `session` — and is unchanged.

### P0-3 / A1 — Attendance `'M'` half-day (money defect)

`'M'` matched no branch in either parser, so a half-day was **paid as a full day**. Now `+= 0.5` to `daysAbsent`, in **both** parsers, guarded by the same leave-overlap test the `'A'` branch uses: if leave already covers that day, the missing half is already deducted by the leave engine and the attendance branch adds nothing. Attendance half-day + leave half-day therefore cannot double-count.

### P0-4 / A2 — Attendance `'W'` worked-on-off (money defect)

`'W'` exists specifically to trigger extra pay and was ignored entirely. Now accumulated as `extraWorkedDays` in both parsers and **priced inside the canonical `_compute_payslip()`** — no second payroll path:

- Paid at the *same* daily rate the engine already uses for the LWP deduction, so a day lost and a day gained are symmetric; no new rate basis introduced.
- Added to `gross` **before** the statutory block, so ESI/TDS treat it as earned wages — consistent with the function's documented "statutory is a % of the EARNED wage" contract.
- Deliberately does **not** inflate `effectiveBasic`, so the PF base stays basic-only.
- Multiplier `extra_worked_rate` reads from the existing `schools.attendanceRules` doc, **default 1.0**. No new config collection. Negative values fall back to 1.0.
- Surfaces as `extra_worked_pay` on the payslip, plus `half_days` / `extra_worked_days` day accounting.

> **Flagged as a policy decision, not an engineering one:** `extra_worked_rate` defaults to **1.0** (pay the extra day at par). It is **not** a statutory overtime multiplier — this engine has no statutory OT concept, consistent with `_compute_payslip()`'s documented "no ceilings/slabs/tax engines" scope. If the business rule is 1.5× or 2×, that is a config value to set per school, and it should be confirmed before any tenant runs live payroll with `W` days.

New params are optional (`= 0.0`, `= 1.0`), so any caller not passing them gets byte-identical output to pre-A2 behaviour.

### P0-5 / B1 — Inactive staff could punch via device (`Attendance.php`)

The device branch verified only that the staff doc **exists**; the GPS path correctly rejected inactive staff. An inactive/terminated employee could still punch from a biometric/RFID/face device. Added the same dual-case (`status`/`Status`) active check. Confirmed the edit sits inside `elseif ($personType === 'staff')` — **the student path is untouched** — and tenant isolation is unchanged (docId still built from the API-key-derived school).

### P0-6 / C1+C2 — Employment exit model and final pay

**C1 (`Staff::set_status`)** — `deactivatedAt` records when an admin flipped the switch, not the employee's last working day, and payroll's three exit aliases (`exitDate` / `Resignation Date` / `leaveDate`) were **never written by any code path**. Deactivation now writes one canonical `exitDate`, plus `lastWorkingDate` (mirrors it unless separately supplied) and `exitReason`. Absent an explicit date it falls back to today — the best available signal, and now *recorded* so payroll can pro-rate instead of silently dropping the person. Reactivation/rehire **nulls all three**, or a stale exit date would keep clamping pay forever.

**C2 (`Hr::generate_payroll`)** — roster selection was `status == 'Active'`, so an employee who left mid-period disappeared retroactively and their final partial month was never paid. A second bounded query now adds `Inactive` staff whose `exitDate >= period start`:

- One extra **equality** query per *run* (not per employee) — same index shape as the existing Active query, so **no new composite index**.
- Only exits on/after period start qualify; an earlier leaver was not employed in this period.
- An `Inactive` record with **no** `exitDate` is skipped — pre-C1 records have an unknowable window, and including them would retroactively pay every historical leaver.
- `_staff_working_days()` then clamps the window, so an exit *after* period end simply pays in full.
- Inclusions are logged (`PAYROLL_FINAL_PAY_ROSTER`).

Verified `exitDate` is **first** in all three consumption chains (`Hr.php:5897` generate, `:8322` leave-window CR-4, `:8609` regenerate) — so C1's field feeds every window calculation.

### P0-7 — Joining-date consistency

`Firestore_service::saveStaff()` only persists raw fields the caller supplies, and neither `AdminUsers.php` nor `Staff_access::onboard_staff()` supplied `Date Of Joining`. Staff created through those paths had no join date and were treated as employed for the **whole month** with no mid-month proration. Both now stamp `Date Of Joining` at creation in the same `d-m-Y` format `Staff::new_staff()` uses, correctable later in Edit Staff. **No mass migration** — existing records untouched; the dead `joinDate` / `Joining Date` aliases were left in place (P1 hygiene).

### P0-8 — Session membership

`sessions[]` was written once at creation and never refreshed, so a staff member created in session A became invisible to session-scoped operations in session B (e.g. `Attendance::bulk_mark_staff` filters `sessions array-contains <current>`). `edit_staff()` now merge-appends the current session — existing entries preserved, array kept unique, only when a session is actually resolved. No duplicate records; tenancy model untouched.

---

## 4 & 5. Tests added and executed

**Added — `tests/Unit/AttendancePayrollStatusContractTest.php` (4 tests, 29 assertions):**

1. **`test_attendance_status_vocabulary_matches_payroll_contract`** — the durable guard. Parses `ALLOWED_STATUS` from the Attendance writer's own source and asserts it matches a contract pinned in the test. **Adding or removing an Attendance status character now fails the build**, forcing an explicit payroll decision. This is the fix for the *class* of defect, not just its two instances: the parser's `if/elseif` chain has no `else`, so an unknown character silently becomes an ordinary paid day. Verified non-vacuous — the regex extracted exactly the 9 real characters.
2. **`test_pay_affecting_statuses_are_handled_in_both_parsers`** — every pay-affecting status must appear in **both** parsers (≥2 branches), because generate and regenerate are contractually required to produce identical numbers.
3. **`test_half_day_contributes_half_and_is_leave_guarded`** — asserts the 0.5 contribution and ≥4 leave-overlap guards (2 per parser).
4. **`test_worked_on_off_feeds_the_canonical_calculator`** — asserts `W` is priced inside `_compute_payslip()` and passed by both call sites, i.e. not via a separate payroll path.

Comments are stripped via `token_get_all()` before matching, so documentation can never satisfy a security/correctness assertion.

**Executed:**

| Suite | Result |
|---|---|
| `php -l` — all 6 changed controllers + 3 new test files (+ every other changed PHP file in the tree) | **clean** |
| `vendor/bin/phpunit --testsuite Unit` | **170 tests, 440 assertions, 10 failures** |
| New contract test in isolation | **OK (4 tests, 29 assertions)** |
| `firebase-rules/tests` — full Jest rules suite | **10 suites, 224/224 passed** |

**All 10 failures are pre-existing and unrelated — zero new failures.** They read only `application/controllers/Homework.php` (untouched — confirmed via `git status`) and Kotlin sources under `D:/Projects/SchoolSync{Parent,Teacher}` (separate repositories), plus the documented `BackupTest::size_human` format drift. Test count moved 166 → 170, exactly the 4 tests added.

> **Environment note:** this machine shows *10 failures / 0 skipped* where CLAUDE.md documents a *4 failures / 27 skipped* baseline. That is because the cross-repo Kotlin paths exist here, so previously-skipped tests now execute against drifted Kotlin source. Pre-existing environment difference, not a regression.

---

## 6. Test results by required category

| Required category | Status |
|---|---|
| **Attendance → Payroll: all 9 statuses** | ✅ Every status now has an intentional interpretation, enforced by the contract test. `A`/`L`/`V`/`M`/`W` branch explicitly in both parsers; `P`/`H`/`T`/`O` are documented no-ops (H/O are non-working days derived by `_staff_working_days()` from the holiday calendar + weekly-off policy — branching would double-count). **No status falls through silently.** |
| **RBAC** | ⚠️ Structurally verified (see §7), not runtime-executed |
| **Employment lifecycle** | ⚠️ Code paths implemented and reviewed; boundary cases not runtime-executed |
| **Device attendance** | ⚠️ Guard implemented; not runtime-executed |
| **Accounting** | ✅ Verified by inspection (see §10) |

---

## 7. Security verification

- **Four privilege-escalation bypasses removed**, including cross-staff payslip PDF access (salary + bank + statutory IDs) and leave-on-behalf-of.
- **One IDOR closed** (`Payroll::get_employee_history`).
- **One authentication-parity gap closed** (inactive staff device punch).
- Graded RBAC is now the sole authority at all five sites. **No role-name authorization was restored anywhere**; no parallel permission system introduced; RBAC helpers untouched.
- Self-service preserved: `my_payslips()` unchanged (already correctly scoped by session identity, no request parameter); callers without the required level are clamped to their own record rather than denied outright, so employees keep access to their own data.

**Limitation, stated plainly:** these are verified by code review and source-level assertions, **not** by executing the runtime matrix (HR:view / HR:manage / self-service / downgraded Principal / cross-employee / cross-tenant). Executing it requires authenticated multi-role browser sessions against a live tenant. The structural argument is strong — the bypass disjunct is deleted, so the graded gate is the only remaining path — but it is not the same as an executed test, and I am not claiming coverage I did not obtain.

---

## 8. Tenant-isolation verification

No isolation boundary was modified. Specifically:

- `Payroll_operational_repo::listEmployeeHistory()` already filters `schoolId` + `session`; the D5 fix narrows *which employee*, not which tenant.
- The B1 device guard reads the same `{$schoolIdForCheck}_{$personId}` doc id, still derived from the API key's school.
- The C2 roster query goes through `schoolWhere()`, which injects the `schoolId` predicate — identical scoping to the Active query beside it.
- All other reads/writes use `docId()` / `updateEntity()` / `schoolWhere()`, which are school-scoped by construction.
- **No hardcoded tenant id anywhere.** Grep-verified: no `SCH_` literal introduced. The implementation is tenant-generic; nothing was designed or tuned around the test tenant.

---

## 9. Attendance → Payroll verification

Producer/consumer contract now aligned:

| Status | Payroll interpretation | Both parsers |
|---|---|---|
| `A` absent | full-day LOP (leave-guarded) | ✅ |
| `L` leave | paid / unpaid split | ✅ |
| `V` vacant | `vacant_treatment` policy | ✅ |
| `M` half-day | **0.5 LOP, leave-guarded** | ✅ NEW |
| `W` worked-on-off | **extra-pay earning via canonical calculator** | ✅ NEW |
| `P` / `H` / `T` / `O` | no pay effect (documented; H/O derived elsewhere) | ✅ |

The Attendance writer, policy engine, finaliser and collections were **not modified** — Payroll was adapted to the current Attendance contract, per the stated rule.

---

## 10. Payroll → Accounting verification

**No accounting code was modified.** Verified by inspection that `W` extra pay flows correctly through the existing architecture: it is folded into `gross` inside `_compute_payslip()` **before** the accrual payload is built, so the existing journal lines pick it up automatically with no new posting path. The transactional outbox, `journalIdFor()` identity, `PayrollReconciler` idempotent re-post, accounting-period-open guards and chart-of-accounts validation are all untouched and continue to apply. `sum(components) == gross` and `gross − deductions == net` invariants are preserved — extra pay is added to gross *before* the deduction waterfall, so the journal still balances by construction.

---

## 11. Firestore-only verification

Grep across every `+` line in all six changed controllers for RTDB primitives (`firebase->get/set/update/delete/push`, `rtdb`, `realtime`): **clean — no RTDB primitive introduced.** All new reads/writes go through `$this->fs` (Firestore_service). No dual-write, no dual-read, no fallback, no bridge.

---

## 12. Remaining P1 / P2 findings (not implemented)

**P1:**
- `Hr::get_report` (`:7733`) — `VIEW_ROLES` + `manage` mismatch guarding all-staff `gross_salary`; latent, currently unreachable via fallback.
- `Hr::get_leave_audit_log` (`:3311`) and `get_appraisals` (`:7247`) — all-staff data at `HR:view` with no row scoping.
- `Hr::payroll_health` / `payroll_health_page` — the only two module-less `_require_role` calls; need `'HR','manage'`.
- **A3** — two independent definitions of non-working days (`_staff_working_days()` vs the writer's `O`/`weeklyOff`). Not a live defect (payroll uses one consistently), but they can disagree.
- `preflight_payroll` still previews on the `status=='Active'` roster, so its warnings won't mention exited staff that `generate_payroll` will now include. Cosmetic inconsistency, no money effect.
- Dead alias chains `joinDate` / `Joining Date` / `Resignation Date` / `leaveDate` left in place.
- All 13 `Payroll.php` `_require_role` calls pass `""` for `$action`, so denial logs carry no action label.

**P2:** per-staff shift assignment + policy resolution (B2/B3, blocks Retail roster); `locationId` has no consumer (B4); whether `Payroll` should become its own RBAC module (D9 — cross-system change).

---

## 13. Migration requirements

**None. No migration was performed and none is required to ship this.**

Every change is additive or behaviour-narrowing:
- New staff fields (`exitDate`, `lastWorkingDate`, `exitReason`) are written going forward only; absent = "never exited", which is the correct reading for existing records.
- New payslip fields (`half_days`, `extra_worked_days`, `extra_worked_pay`) are additive; existing slips are untouched.
- No existing document is rewritten, no field deleted, no collection changed, no index changed, no rules change.
- Rollback = revert the diff; no data cleanup needed.

**One forward-looking note (not a blocker):** existing `Inactive` staff have no `exitDate`, so C2 deliberately skips them. If any *recent* leaver needs a final payment, an operator sets their exit date via the exit workflow — a normal data action, not a migration. Historical leavers correctly stay excluded.

---

## 14. Blockers

**None blocking review.** Two items need your decision before this runs live payroll:

1. **`extra_worked_rate` default = 1.0** (§P0-4). If the business rule is 1.5×/2×, it is a per-school config value — needs confirmation, not code.
2. **Runtime verification not performed** (§7). The RBAC matrix, lifecycle boundary cases (join mid-month, exit mid-month, exit at month-end, rehire) and device-punch cases are implemented and code-reviewed but not executed against a live tenant. They need authenticated multi-role browser sessions. I did not want to claim coverage I do not have.

---

## 15. Ready for commit?

**Code-complete and regression-clean; I recommend runtime verification before commit.**

- ✅ All 8 P0 items implemented
- ✅ `php -l` clean on every changed file
- ✅ 170 unit tests, zero new failures (10 pre-existing, all provably unrelated)
- ✅ Rules suite 224/224
- ✅ Firestore-only confirmed
- ✅ No migration, no destructive change, no index/rules change
- ✅ Full diff reviewed line by line; all 12 deletions intended
- ⚠️ Runtime matrix not executed (§14.2)

---

## Confirmations

- Attendance **not** rebuilt, replaced, or downgraded — Payroll was adapted to it. No `hrms_attendance`.
- RBAC **not** rebuilt; no parallel permission system; no role-name authorization restored.
- No separate HRMS application. No second Employee Master — `staff` extended in place.
- No accounting duplication; `PayrollAccountingService` / `PayrollReconciler` untouched.
- Leave engine untouched — `_process_leave_for_payroll()` unmodified; the `M` guard *consumes* its existing `lwpDaySet` rather than altering it.
- No RTDB. No destructive migration. No live entitlement, plan, subscription or billing data touched.
- No tenant-specific code, no hardcoded test-school id.
- **Not committed. Not pushed. Not merged. Not deployed.**
