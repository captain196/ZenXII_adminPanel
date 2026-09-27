# ZENX HRMS — UAT GATE REPORT

**Date:** 2026-08-09 · **Gate:** UAT verification gate
**Result:** **PARTIAL** — automated runtime verification performed; **browser UAT NOT performed**
**Canonical count:** 164 · **Regression:** 461 tests / 10 baseline failures · all verifiers PASS

---

## 0. The constraint that shapes this entire report

**I have no browser.** I cannot log in, click, render JavaScript, or drive a workflow through a UI.

§8 of the authorization is explicit: *"Do not convert 'not tested' into PASS."* So this gate reports
what was **actually exercised at runtime**, and everything else as **NOT TESTABLE (by me)** with the
exact operation you would need to perform.

I did more than source inspection: the application is running at
`http://localhost/ZenX/school/`, and I exercised real HTTP requests against it. That produced
genuine evidence for one capability that has been PARTIAL since W3.1 — but it does not, and cannot,
substitute for workflow UAT.

---

## A / B / C. Capabilities tested, classification, workflows

### PASS — verified at runtime this gate

| Capability | Workflow | Evidence |
|---|---|---|
| **T-04** (partial) RBAC — **unauthenticated denial** | `GET hr/preflight_payroll` · `hr/lop_review` · `hr/payslip_trace` · `payroll_overrides/list_for_run` · `payroll_overrides/types` | All return **HTTP 307 → `/admin_login`**. Grepped each raw response for `"status"`, `"net_pay"`, `"gross"`, `"blockers"`, `"steps"`, `"types"` — **no payroll JSON leaked on any endpoint** |
| **Route registration** (supports P-043/P-045/N-01) | All five W3 endpoints | Resolve to the CI front controller and redirect, rather than 404 — routing is correct end-to-end |

**This is the first time endpoint authorization has been *exercised* rather than asserted from
source.** It is real, and it is narrow: it proves the *unauthenticated* boundary only.

### NOT TESTABLE (by me) — requires a browser and an authenticated session

| Capability | Why | What you must do |
|---|---|---|
| **P-043** Editable payroll grid | Needs login + a Draft run + rendering | Open a run, confirm the Adjustments column and inline form render |
| **P-045** Calculation trace | Needs drill-down + JS | Click "Why is this net pay?", confirm the 12-step chain and the paise-reconciliation banner |
| **N-01** Override model/workflow | Needs a real create → regenerate → accounting cycle | Create a ₹5,000 bonus, regenerate, confirm original→adjustment→final and the delta journal |
| **P-063** ATS convert-to-staff | Needs the applicant UI | **See §D — the sharpest item in this gate** |
| **T-07** Admin browser UAT | Definitionally | — |
| **T-11** Test-School integration UAT | Needs authorized writes | — |
| **T-12** End-to-end payroll test | Needs a full run | — |
| **T-04** RBAC — *authenticated* denial | Needs two logins at different grades | Confirm an `HR:view` user cannot create an override (endpoint, not just hidden button) |
| **T-05** Tenant isolation — endpoint level | Needs two tenant sessions | Confirm a Tenant-B session cannot read a Tenant-A run |
| **N-19** Latency benchmark | **Explicitly NOT authorized** (§3) | Harness is ready; unrun |

**Nothing above is marked PASS. None of it was tested.**

### BLOCKED

| Capability | Blocker | Owner |
|---|---|---|
| `ST-01…ST-10` (8) | Tenant statutory policy undeclared | **Operator decision** |
| `ST-14` / W3.2.6 | Depends on the above | Operator, then engineering |
| `N-18` / `P-047` async | Deferred on measured evidence; criteria retained in full | Engineering (W5) |
| `N-19` | Authorization withheld per §3 | Operator |

---

## D. The P-063 regression concern — stated plainly

W1.3 found that `Ats::finalize_hire()` looked up staff with a **double tenant prefix**
(`getEntity('staff', "{$school_id}_{$staffId}")` — `getEntity()` already applies `docId()`). It
fails closed, so **hire finalization had never once succeeded in production.** Verified at the time:
103 staff documents, zero double-prefixed.

**The one-word fix is still unexercised.** Its UAT is the highest-value item in this gate:

1. Open an applicant at stage **Selected**
2. Convert to Staff → confirm the form pre-fills from the applicant
3. Save → **confirm a staff record is actually created**
4. Reopen the applicant → confirm `staff_id`, `hired_at`, `ats_status: hired`, and that
   `positions_open` decremented
5. Attempt the conversion a second time → **must refuse** (idempotency now keys on the staff *link*,
   not just the flag)

Step 3 is the assertion that matters. Everything before it passed in production too — and still
produced no staff record.

---

## E. Fixes made — **none**

No defect was found, because no workflow was executed. No code changed this gate.

## F. Regression

**461 tests / 1,493 assertions / 10 failures** — the documented pre-existing baseline, unchanged.
`hrms_w2_parity` · `hrms_w2_verify` · `hrms_p0_verify` · `hrms_closure_verify` · `hrms_w13_verify`
all **PASS**.

## G / H. Master-register changes and counts

| Capability | Before | After | Reason |
|---|---|---|---|
| `T-04` RBAC tests | PARTIAL | **PARTIAL** *(evidence strengthened)* | Unauthenticated denial now exercised at runtime; authenticated grade-level denial still untested — **not upgraded** |

**No status changed.** Counts unchanged:

**COMPLETE 50 · PARTIAL 41 · MISSING 62 · ENHANCEMENT 1 · REFACTOR 1 · BLOCKED 8 · OPERATOR 1 ·
N/A 0** → `50+41+62+1+1+8+1+0 = 164` ✓ · **Completeness 30.5%**

I did not upgrade `T-04`, `T-07` or anything else on the strength of partial evidence. §1 forbids
upgrading to improve a percentage, and unauthenticated denial is not RBAC verification.

## I. Dependencies · J. Remaining gaps · K. Owner waves

Unchanged from the master register. Every incomplete capability retains its owner wave. Zero
unexplained items.

## L. Statutory — **BLOCKED, not cancelled**

Eight capabilities plus all of W3.2.6 await one operator decision. The full policy surface (PF
rates/ceiling/eligibility/effective dating, ESI rates/threshold/coverage, PT state/slabs/applicability,
TDS modes/declarations/snapshot/compliance outputs) is retained verbatim in the register. **Nothing
was inferred, and no empty snapshot was built to close the gate.**

## M. Async — **MISSING**

Batching is not async and is not reported as such. `N-18`/`P-047` retain all sixteen acceptance
criteria; `FeeWorker` remains the named reuse pattern.

## N. N-19 — **MISSING**

Harness built and dry-run verified. **Not executed** — §3 withheld authorization. No result was
manufactured from the extrapolation.

## O. Professional documents — **MISSING**

`P-074` (HR is not a `Document_service` caller) blocks `P-046` tenant-branded payslips and `P-075`
HR letters. Untouched this gate.

## P. RBAC — **PARTIAL, evidence improved**

Graded permissions throughout; no role-name gates in any W3 surface. Unauthenticated denial now
proven. **Authenticated grade-level denial and cross-tenant denial remain unexercised.**

## Q. Accounting — unchanged

`Dr == Cr`, transactional outbox, reconciler, idempotent posting — all as certified. No accounting
code touched this gate.

## R. Cross-system — untested

Teacher/Parent app payslip surfaces (`P-053`, `P-078`) unverified. `T-08`/`T-09`/`T-10` MISSING.

## S. Browser — **NOT PERFORMED** · ## T. Test-School — **NOT PERFORMED**

No Test-School data was read destructively, written, or manufactured. No operation requiring
authorization was performed.

---

## The exact operations requiring your authorization (§7)

To complete this gate, these writes are needed on the designated Test School:

| # | Operation | Reversible? |
|---|---|---|
| 1 | Generate a payroll run for a test month | Yes — `delete_payroll_run` |
| 2 | Create a payroll-period override (e.g. ₹5,000 bonus) | Yes — void, then regenerate |
| 3 | Regenerate one employee's slip to apply it | Yes — archived to `slip_archive`, delta journal reverses |
| 4 | Confirm LOP on the run | Yes — Draft-only, cleared on delete |
| 5 | Finalize, then unlock the run | Yes — `unlock_payroll`, audited |
| 6 | ATS convert-to-staff for one applicant | **Creates a real staff record** — reversible by deactivation, not deletion |
| 7 | `hrms_w325_latency run --commit` | Yes — self-cleaning scratch collection |

**Operation 6 is the one to think about**: it creates a staff record that should be deactivated
rather than deleted afterwards. It is also the only way to verify the P-063 defect fix.

---

## U. Recommended next gate

**The UAT gate stays OPEN.** It cannot close without a browser session, and I will not report it
otherwise.

Two paths, your choice:

1. **You run the UAT** using the checklist above; I triage every FAIL against its capability ID.
2. **I proceed with `P-074` HR document adoption** — unblocked, reuses `Document_service` (proven
   with 3 live callers), and opens `P-046` professional payslips and `P-075` HR letters. UAT remains
   open in parallel.

**HRMS is not complete. The backlog is not finished.** 114 of 164 capabilities remain incomplete,
each with an owner wave.

**Nothing implemented, committed, pushed, deployed, migrated or seeded this gate.**
