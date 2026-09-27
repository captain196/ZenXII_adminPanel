# ZENX HRMS — UAT EXECUTION REPORT

**Date:** 2026-08-09 · **Gate:** authorized Test-School UAT
**Result:** **NOT EXECUTED** — two independent blockers, one of which is a data blocker you should know about
**Canonical count:** 164 · **Regression:** 461 tests / 10 baseline failures · verifiers PASS

---

## 1. Exact UAT operations executed — **NONE**

**No authorized operation was performed.** No payroll run, no override, no regeneration, no LOP
sign-off, no finalization, no ATS conversion, no latency benchmark.

What I did perform: **read-only precondition inspection** (`scripts/uat_preconditions.js`, writes
nothing) to establish, per §3, which tenant, which employees, which applicant and which period each
authorized operation would touch — and whether it is runnable at all.

### Blocker 1 — I have no browser

Every operation in §4–§10 requires an authenticated admin session behind Firebase Auth. I cannot log
in or drive a UI.

I deliberately did **not** substitute direct Firestore writes for the workflow. Writing a payslip or
a staff document straight to the database would bypass the controller, the RBAC gate, the validation,
the accounting post and the audit trail — i.e. everything UAT exists to prove — while producing a
report that looked like a pass. That would be worse than not testing.

### Blocker 2 — the critical P-063 test has no data to run against

**This one is not about my limitations.** The Test School has **zero HR applicants**, therefore zero
at `Selected`. §9 authorizes converting "an appropriate test applicant" — **there isn't one.**

Creating an applicant to convert would be manufacturing production-like data, which §7 and §12
forbid. So I stopped and am reporting it, per §15.

---

## 2 / 3 / 4. Test School, records, period — established read-only

**Only one tenant can run payroll at all.**

| Tenant | Staff (total/active) | Salary structures | Runs | Applicants | Verdict |
|---|---|---|---|---|---|
| `SCH_C0EA5CBF25` | 54 / 54 | **0** | 0 | 0 | NOT RUNNABLE |
| `SCH_B9161BBE3C` | 14 / 14 | **0** | 0 | 0 | NOT RUNNABLE |
| **`SCH_D94FE8F7AD`** | **11 / 9** | **8** | 1 | **0** | **payroll RUNNABLE** |

**Designated Test School: `SCH_D94FE8F7AD`.**

- **Existing run:** April 2026, status **Paid** → do **not** reuse that period.
- **Free periods with attendance:** 2026-05, 2026-06, 2026-07.
- **PII mirror:** 8 documents.
- **Bank details:** 3 staff have them, **8 do not** → **N-15's blocker is genuinely exercisable.**
- **PAN:** 7 of 11.
- **Statutory policies: 0** → statutory display stays **BLOCKED**, as expected.

---

## 5. PASS / FAIL / BLOCKED / NOT TESTABLE per capability

| Capability | Result | Basis |
|---|---|---|
| **P-043** editable grid | **NOT TESTABLE** (by me) | No browser |
| **P-045** calculation trace | **NOT TESTABLE** (by me) | No browser |
| **N-01** override workflow | **NOT TESTABLE** (by me) | No browser |
| **P-063** ATS convert-to-staff | **BLOCKED** | **Zero applicants exist in the Test School** — see §7 |
| **T-04** authenticated RBAC | **NOT TESTABLE** (by me) | Needs two graded logins |
| **T-05** tenant isolation (endpoint) | **NOT TESTABLE** (by me) | Needs two tenant sessions |
| **T-07** admin browser UAT | **NOT TESTABLE** (by me) | Definitionally |
| **T-11** Test-School integration UAT | **NOT TESTABLE** (by me) | Needs authenticated workflow |
| **T-12** end-to-end payroll | **NOT TESTABLE** (by me) | Needs a full run |
| **Op4 LOP review** | **CONDITIONAL** | Absence marks exist (2026-06: 29, 2026-07: 30) but LOP additionally requires **approved unpaid leave**. Unconfirmed — do **not** invent LOP (§7) |
| Statutory display | **BLOCKED** | 0 policies configured — correct and expected |
| **N-19** latency | **NOT RUN** | §13 withheld authorization |

**Nothing is marked PASS. Nothing untested was converted to PASS.**

---

## 6. Screens/workflows tested — **none**

## 7. P-063 result — **BLOCKED, and this is the headline**

The critical test cannot run: **the Test School has no HR applicants at all** (`hrApplicants` = 0,
`Selected` = 0, hired/linked = 0).

This is itself informative. `Ats::finalize_hire()` carried a double-tenant-prefix defect meaning hire
finalization **had never once succeeded in production** — and the Test School shows **zero applicants
and zero hires**, which is consistent with the recruitment pipeline never having been exercised here.

**To unblock, you must decide one of:**

| Option | Consequence |
|---|---|
| **A.** Authorize creating one test applicant through the ATS UI, then convert it | The only true end-to-end verification. Creates 1 applicant + 1 staff record; staff deactivated afterwards, not deleted |
| **B.** Run the test on a tenant that has real applicants | None currently do |
| **C.** Leave `P-063` unverified | The fix for a defect that broke production for its entire history stays unproven |

I did not choose. §9 authorized converting an applicant, not creating one.

## 8 / 9 / 10. P-043 · P-045 · N-01 — **NOT TESTABLE by me**

All three are implemented, unit-tested and route-verified (307 → `/admin_login`, no data leak). None
has been exercised in a browser. They remain **PARTIAL**.

## 11 / 12. T-04 · T-05 — **NOT TESTABLE by me**

Unauthenticated denial was proven in the previous gate. **Authenticated grade-level denial**
(`HR:view` attempting an override) and **cross-tenant denial** both require sessions I cannot create.

## 13 / 14 / 15. T-07 · T-11 · T-12 — **NOT TESTABLE by me**

## 16 / 17. Payroll accounting · audit — untested this gate

No run was generated, so no journal was posted and no audit row written. `Dr == Cr` remains as
certified by the verifiers, which continue to PASS.

## 18. Data cleanup performed — **none required**

**Nothing was written anywhere.** No cleanup was necessary and none was performed.

## 19 / 20 / 21. Defects · fixes · regression

**No defects found** — no workflow ran. **No fixes made.** **No code changed this gate.**

Regression re-confirmed: **461 tests / 1,493 assertions / 10 failures** (documented baseline);
`hrms_w2_parity`, `hrms_w2_verify`, `hrms_p0_verify`, `hrms_closure_verify`, `hrms_w13_verify` all
PASS.

## 22 / 23. Master-register changes and counts

**No capability changed status.** Nothing was upgraded on the strength of precondition inspection.

**COMPLETE 50 · PARTIAL 41 · MISSING 62 · ENHANCEMENT 1 · REFACTOR 1 · BLOCKED 8 · OPERATOR 1 ·
N/A 0** → `50+41+62+1+1+8+1+0 = 164` ✓ · **Completeness 30.5%**

**One new capability registered** (§17 requires discovered requirements go in the register, not a
report):

| ID | Capability | Status | Evidence | Wave |
|---|---|---|---|---|
| **N-20** | **UAT fixture provisioning for the Test School** | **MISSING** | UAT is blocked by absent test data: 0 applicants blocks `P-063`; unconfirmed approved-unpaid-leave blocks the LOP path. A repeatable, reversible, operator-authorized fixture path is needed or these capabilities can never be verified | **UAT** |

## 24. Remaining incomplete — 114 of 164, each with an owner wave

## 25 / 26 / 27 / 28. Statutory · async · N-19 · documents

- **Statutory: BLOCKED** — 0 policies in every tenant. Nothing inferred; no empty snapshot built.
- **Async: MISSING** — batching is not async and is not reported as such.
- **N-19: MISSING** — not run, per §13. No result manufactured from the extrapolation.
- **Professional documents: MISSING** — `P-074` still blocks `P-046` and `P-075`.

---

## 29. Recommended next gate

**The UAT gate stays OPEN.** Two decisions are yours:

1. **P-063 (§7)** — authorize creating one test applicant, or accept that the fix stays unverified.
2. **Ops 1–5** — these are runnable *by you* today on `SCH_D94FE8F7AD`, period **2026-05, 2026-06 or
   2026-07** (never April — that run is Paid). N-15's blocker will fire for **8 employees without
   bank details**, which is a real assertion, not a contrived one.

**Meanwhile, the correct engineering gate is `P-074` — HR document adoption.** Unblocked, reuses
`Document_service` (proven with 3 live callers), and opens `P-046` professional payslips and `P-075`
HR letters. I have **not** started it, per §20.

**HRMS is not complete. The backlog is not finished.**

**Nothing executed, written, committed, pushed, deployed, migrated or seeded this gate.**
