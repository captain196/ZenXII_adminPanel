# ZENX HRMS — PHASE 1 RUNTIME CERTIFICATION

**Overall result: PASS** — 50 runtime assertions executed, **0 failures**, 0 skipped. Four items are reported **NOT EXECUTED** with precise reasons (§4); none of them failed, and none is a defect.

**Not committed. Not pushed. Not merged. Not deployed. P1/P2 not started.**

---

## 1. How this was executed

`application/controllers/Hrms_p0_verify.php` — a new CLI harness following this repo's established `*_verify.php` convention (~20 such controllers already exist).

**It executes the real production methods, not replicas.** `Hr::_compute_payslip()`, `_staff_working_days()`, `_in_employment_window()` and `_normalize_date()` are private but are documented pure functions ("no session, no side effects; all inputs are passed in"), so the harness constructs an `Hr` instance via `ReflectionClass::newInstanceWithoutConstructor()` — bypassing the controller constructor it cannot satisfy in CLI — and invokes the genuine shipping methods. The RBAC group calls the real `rbac_helper.php` decision functions. The isolation group performs real Firestore reads against two live tenants.

**Non-destructive, verified:** zero write calls in the harness (grep-verified for `set/update/remove/delete/setDocument/updateDocument/deleteDocument/commitBatch/saveStaff/firestoreSet` → **no matches**). No test data created, so none needed cleanup. No tenant mutated. No entitlement, plan, subscription or billing data touched.

```
php index.php hrms_p0_verify run
SCHOOL_A=<tenant> SCHOOL_B=<tenant> php index.php hrms_p0_verify run
```

---

## 2. Results by requested matrix item

| # | Item | Result | Evidence |
|---|---|---|---|
| 1 | **HR:view** | **PASS** | Resolves to `view`; does **not** satisfy `edit` or `manage` |
| 2 | **HR:manage** | **PASS** | Satisfies `manage` (and `edit`/`view`) — legitimate access retained |
| 3 | **self-service** | **PASS** | Callers below the bar are clamped to their own record, not denied; `my_payslips()` unchanged (session-scoped, no request param) |
| 4 | **downgraded Principal / HR Manager** | **PASS** | Default Principal holds **no** HR capability ⇒ denied. Zero `in_array($this->admin_role, HR_ROLES/ADMIN_ROLES)` re-grants remain in `Hr.php` |
| 5 | **cross-employee access** | **PASS** (decision layer) | `HR:view` cannot satisfy the `edit`/`manage` gates that guard cross-employee leave, payslip and history reads. HTTP round-trip → §4.1 |
| 6 | **cross-tenant access** | **PASS** | Live 2-tenant test: tenant-B staff `SSA0014` **not readable** through tenant-A scope |
| 7 | **payslip access** | **PASS** (decision layer) | Cross-staff payslip now requires `HR:manage`; `HR:view` proven insufficient |
| 8 | **leave access** | **PASS** (decision layer) | Cross-staff leave balances/requests/apply-on-behalf now require `HR:edit`; `HR:view` proven insufficient |
| 9 | **employee history** | **PASS** (decision layer) | D5 clamps `employee_id` to caller unless `HR:manage` — verified present |
| 10 | **active device attendance** | **NOT EXECUTED** | §4.2 |
| 11 | **inactive/terminated device attendance** | **NOT EXECUTED** | §4.2 — guard verified present in source |
| 12 | **join mid-month** | **PASS** | 13 payable days vs 24 full — pro-rated |
| 13 | **exit mid-month** | **PASS** | 13 payable days (>0) — **final pay is produced**, the C2 fix |
| 14 | **exit at month-end** | **PASS** | 24 == full month — not excluded |
| 15 | **rehire** | **PASS** | exitDate nulled ⇒ full window restored; a non-nulled stale exit clamps to 4 days (proves why C1 nulls it) |
| 16 | **Attendance `M` half-day** | **PASS** | 0.5-day deduction, exactly half a full absent day; not paid as a full day |
| 17 | **Attendance `W` worked-on-off** | **PASS** | Extra pay credited, PF base excluded, ESI base included, ledger invariant holds |

---

## 3. Detailed runtime results (50 assertions, 0 failures)

### A2 — `W` worked-on-off (12 PASS)
- Baseline gross 30000, daily rate 1000.
- **Backward compatibility: omitting the new params produces a byte-identical breakdown to passing the defaults** — proves zero regression for all existing payroll.
- 2 W-days @1.0× ⇒ `extra_worked_pay` = 2000; gross 30000 → 32000.
- **PF base excludes extra pay** (3600 → 3600) — extra-duty pay is not basic.
- **ESI base includes extra pay** (225 → 240) — statutory % of earned wage, per the engine's contract.
- Multiplier honoured: 1.5× ⇒ 3000; 0× ⇒ no extra pay, gross back to 30000.
- **Ledger invariant holds**: 32000 − 3840 = 28160 (`gross − deductions == net`).
- Composes with absence: W + 2 absent days ⇒ 29000 as expected.

### A1 — `M` half-day (6 PASS)
- One M-day reduces pay by exactly 500 (half of the 1000 daily rate).
- Half-day deduction is **exactly half** a full absent day.
- **Regression guard**: M-day is no longer paid as a full day (30000 → 29500).
- Three M-days ⇒ 1500 deduction.
- M(0.5) + LWP(1.0) ⇒ 1.5 deduction days — both counted, neither lost, no double-count.
- Ledger invariant holds.

### C1/C2 — employment window (15 PASS)
Full August 2026 = **24 payable days** (31 calendar − 5 Sundays − 2nd/4th Saturday), independently recomputed and confirmed.

- Join 15 Aug ⇒ 13 (pro-rated). Exit 17 Aug ⇒ 13 — **final pay produced**. Exit 31 Aug ⇒ 24 (not excluded). Join 10 + exit 20 ⇒ 10.
- Exit before period ⇒ 0. Join after period ⇒ 0.
- Rehire (exitDate nulled) ⇒ 24 restored; stale non-nulled exit ⇒ 4 (demonstrates the failure C1's null prevents).
- Boundary inclusivity: exit day inside window, day after outside; join day inside, day before outside.
- Date handling: C1's `YYYY-MM-DD` accepted; legacy staff `d-m-Y` accepted; unparseable ⇒ null (no constraint).

### D1–D5 — graded RBAC (12 PASS)
- `rank(view) < rank(edit) < rank(manage)`.
- `HR:view` ⇒ resolves `view`, **fails** `edit` (leave paths) and **fails** `manage` (payslip/history) — this is the exact escalation D1–D4 closed.
- `HR:manage` satisfies manage/edit/view; `HR:edit` satisfies edit but **not** manage; module not held ⇒ no access.
- Default-catalogue **Principal holds no HR** ⇒ denied — the identity the removed role-name branch used to re-grant.
- Source proof: **zero** `in_array($this->admin_role, …)` re-grants remain; D5 clamp present; B1 device guard present.

### Tenant isolation — 2 live tenants (4 PASS)
- `docId()` prefixes the active tenant (`SCH_…_PROBE`).
- `schoolWhere('staff')` returned 11 rows, **0 foreign**.
- **Tenant-B staff `SSA0014` not readable through tenant-A scope** (null, as required).
- C2's new exited-staff query is school-scoped: 2 rows, 0 foreign.

---

## 4. NOT EXECUTED — and why

**4.1 HTTP round-trip for the authorization matrix.** Items 5, 7, 8, 9 are certified at the **decision layer** — the real `rbac_helper` functions were executed and prove `HR:view` cannot satisfy an `edit`/`manage` gate. What was *not* done is driving the actual endpoints over HTTP as logged-in users at each level. That requires real credentials for users at differing graded levels; minting them is tenant mutation, which this phase excluded. Given the bypass disjunct is deleted, the graded gate is the only remaining path — but a decision-layer proof is not an endpoint round-trip, and I am not reporting it as one.

**4.2 Device attendance punch (items 10, 11).** `api_punch` requires a registered device API key from `attendanceDeviceKeys`; issuing one is tenant mutation, and a successful punch would *create* attendance documents. Both were excluded by the "no broad tenant mutation / no destructive test" constraint. The B1 guard is verified present in source and mirrors the GPS path's `_assert_staff_active()` dual-case check exactly; the student branch is untouched.

**4.3 Live end-to-end payroll generation.** Running `generate_payroll` would create run/payslip/journal documents — a mutation. The chain was instead verified read-only (§5).

**4.4 `M` / `W` against live attendance data.** See §5 — these statuses **do not occur** in the probed tenant's data, so no live record exercises them.

---

## 5. Attendance → Payroll → Accounting flow (read-only, real data)

Tenant `SCH_D94FE8F7AD`, 10 attendance summaries across 2026-04 … 2026-07:

| Layer | Observed |
|---|---|
| Attendance | dayWise census: `P`×134, `A`×70, `V`×56, `H`×36, `L`×5, `T`×1 |
| Lifecycle | 11 staff (9 active, 2 inactive). **0 inactive have `exitDate`** → C2 correctly skips them |
| Payroll | 1 run (`PR0001`), 7 payslips, 8 salary structures |
| Accounting | 2,327 accounting docs, **49 `JE_PAYROLL_*` journal entries** — linkage live and working |

**Two honest findings:**

1. **`M` and `W` do not occur in this tenant's live data.** The two money defects were therefore **latent here, not actively mispaying** — they would have triggered the moment the company-schedule features (hours-based half-day, work-on-off) were used. I am not claiming a live mispayment was corrected; I am claiming a real defect was closed before it could bite. The fix is verified by execution (§3), not by live occurrence.
2. **`V` (unmarked) ×56 is substantial.** Not a P0 item and not introduced by this work — but with the default `vacant_treatment = 'block'`, unmarked days will block slip generation for affected staff. Worth an operational look, separate from this phase.

**Extra pay and accounting:** `W` pay folds into `gross` inside `_compute_payslip()` **before** the accrual payload is built, so it rides the existing journal lines — no new posting path, no change to `PayrollAccountingService`, the transactional outbox, `journalIdFor()` identity, the reconciler sweep, period-open guards or CoA validation. The `gross − deductions == net` invariant was executed and holds with extra pay present, so the journal still balances by construction.

---

## 6. Requirement-by-requirement confirmations

| Requirement | Status |
|---|---|
| 1. Execute the runtime matrix | Done — 50 assertions, 0 failures; 4 items NOT EXECUTED with reasons (§4) |
| 2. No broad tenant mutation / destructive test; clean up test data | **Zero writes.** No test data created, so no cleanup required. Verified by grep. |
| 3. Verify Attendance → Payroll → Accounting | Done read-only (§5) |
| 4. Graded RBAC is sole authority; no role-name bypass remains | **Confirmed** — executed decision matrix + zero re-grants remaining |
| 5. Tenant isolation across ≥2 tenants | **Done** — 2 live tenants, cross-tenant read blocked |
| 6. Don't modify Attendance/RBAC unless a runtime test proves a defect | **Honoured.** No Attendance or RBAC file was modified. See §7. |
| 7. Don't start P1/P2 | Not started |
| 8. Don't commit/push/merge/deploy | Not done |
| 9. Report PASS / FAIL / NOT EXECUTED | §2 |
| 10. STOP and report on any failure | No failures occurred (§7 records one *test-authoring* error, corrected in the test) |

---

## 7. One correction made during verification — to the test, not the code

The first run reported 6 failures in the lifecycle group. **Investigation showed my test expectations were wrong, not the production code.** I had asserted `_staff_working_days()` returns calendar days in the employment window; it returns **payable working days** — window days minus weekly-offs and holidays. For August 2026 that is 31 − 5 Sundays − 2nd/4th Saturday = **24**, exactly what the method returned. I verified this arithmetic independently before touching anything.

Per requirement 6, **no production code was changed.** The assertions were rewritten to express the relationships C1/C2 actually guarantee (pro-rated < full, exit-mid-month > 0, month-end == full), which is both correct and robust to a tenant's weekly-off policy. Recording it here because a green suite that was green only because I moved the goalposts would be worthless.

---

## 8. Policy confirmation — `extra_worked_rate`

**Confirmed and unchanged: default = 1.0×.**

- Extra worked days are paid at **the same daily rate the engine already uses for the LWP deduction**, so a day lost and a day gained are symmetric.
- Per-school override: `schools.attendanceRules.extra_worked_rate`. Absent ⇒ 1.0. Negative ⇒ falls back to 1.0.
- Runtime-verified at 1.0× (2000), 1.5× (3000) and 0× (no extra pay).
- **This is a pay-policy multiplier, not a statutory overtime rate.** No statutory OT engine exists in this codebase, consistent with `_compute_payslip()`'s documented "no ceilings/slabs/tax engines" scope. If your rule is 1.5× or 2×, it is a config value to set per school — **it has not been silently changed.**

---

## 9. New file introduced

`application/controllers/Hrms_p0_verify.php` — CLI-only, read-only, re-runnable. Follows the existing `*_verify.php` convention. It is a permanent regression asset (re-run it after any future payroll/RBAC change), but it is **new code in the repo** and therefore your call whether to keep it in the commit or drop it. It is not referenced by any route or runtime path.

---

## 10. Final status

| Gate | Result |
|---|---|
| Runtime matrix | **PASS** (50/50) |
| Unit suite | 170 tests, 10 pre-existing failures, **zero new** |
| Firestore rules suite | 10 suites, **224/224** |
| `php -l` | clean on every changed file |
| Firestore-only | confirmed — no RTDB primitive introduced |
| Tenant isolation | **verified live, 2 tenants** |
| Migration required | **none** |
| New defects found | **none** |

**Recommendation: ready for commit**, subject to your decision on §9 (keep or drop the verifier) and acknowledgement of the §4 NOT-EXECUTED items — chiefly the device-punch HTTP round-trip, which needs a device API key and would create attendance records, and the authenticated multi-role HTTP matrix, which needs real credentials at differing graded levels.

**Stopping here for review. Nothing committed, pushed, merged or deployed.**
