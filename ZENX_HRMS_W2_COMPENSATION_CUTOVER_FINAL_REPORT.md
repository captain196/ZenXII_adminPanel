# ZENX HRMS — W2 COMPENSATION CUTOVER — FINAL GATE REPORT

**Date:** 2026-08-09
**Task:** Execute the remaining cutover sequence (steps 1–9)
**Result:** **SEQUENCE HALTED AT STEP 2 — STATUTORY CONFIGURATION REQUIRED**
**Flag:** **OFF** · reader **NOT WIRED** · **nothing written to any tenant**
**Cutover:** **NOT COMPLETE** — and correctly so

---

## Executive summary

The sequence ran as far as it legitimately can. Steps 1 and 2 produced a decisive result:

```
tenants: 8    ready: 0    blocked: 1    no payroll data: 7
```

**Seven of eight tenants have no payroll data at all** — no salary structures, and in four cases no staff. There is nothing to migrate and no statutory decision to make for them yet.

**One tenant has payroll data and is BLOCKED**, on two gates:
1. the component catalogue is not seeded (0/13), and
2. **no statutory policy is configured** — which your own instruction says must halt that tenant's migration rather than be inferred.

Steps 3–9 (migration, parity-after-migration, reader wiring, shadow, payroll comparison, accounting parity, flag) are **all downstream of step 2** and were not attempted. Attempting them would mean inventing statutory values.

---

## A. Component seed status — **DRY RUN ONLY, NOT WRITTEN**

`scripts/w2_seed_salary_components.js`, dry-run by default:

```
tenants: 8
would create: 104   skipped (already present): 0   failed: 0
payroll impact: NONE — no payroll code reads this collection
```

The thirteen definitions mirror `_compute_payslip()` exactly — which numbers are percentages, which are amounts, what base each rate applies to, whether attendance prorates it. Every value is a rule read out of the calculator, not a guess. `systemLocked = true` on all thirteen.

**I did not commit it.** Your closing instruction is *"Nothing committed, pushed, deployed, migrated, or enabled without the appropriate approval,"* and this is a production write of 104 documents across 8 tenants. Two things make waiting the right call rather than timidity:

- **The sequence halts at step 2 anyway**, so seeding now unblocks nothing.
- **Seven of those tenants run no payroll.** Whether they should carry a catalogue at all is a decision for you — the script takes `--school=` precisely so you can seed only the tenant that needs it.

## B. Statutory configuration status — **BLOCKED, and this is the gate**

| Tenant | Statutory policies |
|---|---|
| all 8 | **0** |

`statutoryConfig` is empty system-wide. For the one tenant with payroll data this is a hard stop, reported exactly as your instruction specifies:

> **STATUTORY CONFIGURATION REQUIRED — no policy configured**

**Nothing was inferred.** Not from school name, not from city, not from address, not from the test-school identity — even though W2.2 discovery showed that tenant's structures carry uniform PF 12/12, ESI 0.75/3.25 and PT ₹200. Those are *observed employee values*, not a declared tenant policy, and the two are not the same thing. Turning one into the other is exactly the inference you forbade.

Still missing entirely from any data source, and therefore un-migratable — they must be **stated**:
- PF **wage ceiling** and eligibility-above-ceiling behaviour
- ESI **wage threshold**
- PT **state** and **slab table**
- TDS effective dating

The UI to enter all of these shipped in W2.5 (Compensation → Statutory).

## C. Migration dry-run results

For the one tenant with data (`scripts/w2_cutover_readiness.js`, read-only):

| Diagnostic | Result |
|---|---|
| legacy salary structures | **8** |
| staff on record | 11 |
| structures mappable | **8 / 8** |
| missing staff record | **0** |
| zero / invalid basic | **0** |
| unmapped legacy fields | **0** |
| duplicate structures | **0** |
| overlap conflicts | **0** (no assignments exist yet) |
| effective date present in legacy | **0 — none exists** |
| records needing a manual decision | **none** |

The data is clean. The only obstacle is policy, not quality.

*(One correction made during this run: the diagnostic initially flagged `staffId` as an "unmapped field" on all 8 rows. That was my exclusion list being wrong — `staffId` is record metadata, not a pay element. Fixed; a diagnostic that cries wolf on every row trains an operator to ignore it.)*

## D. Migration commit status — **NOT RUN**

Blocked by step 2. The `--commit` guard still requires an explicit `--effective-from`, unweakened: the legacy record has `created_at`/`updated_at`, but neither is a compensation effective date, and deriving one would fabricate the very history this programme exists to make trustworthy.

## E. Exact parity results

The **offline** parity gate still passes: **8/8 real structures round-trip with no divergence**, and 15/15 paise round-trips are lossless at the calculator boundary.

Parity **against migrated data** (step 5) cannot run — there is no migrated data. This is unchanged from W2.4 §2 and is stated rather than papered over: comparing against empty collections would report a green that means nothing.

Wording remains **"no divergence detected"**, never "equivalence proven" — the gate exercises 8 structures in 1 tenant.

## F. Compensation_reader wiring status — **NOT WIRED**

Verified by test and at runtime. `Hr.php` may use `Statutory_config_service` (the W2.7 ceiling-awareness, deliberate) but does **not** query `statutoryConfig`, and references neither `Compensation_reader`, `staffSalaryAssignments` nor `salaryComponents`. `Payroll.php` references none of the five.

## G. Shadow payroll results — **NOT RUN**

Downstream of steps 2–6. Running it now would compare legacy payroll against a compensation path resolving to nothing.

## H. Legacy vs new payroll comparison — **NOT RUN** (same reason)

What *is* proven: the legacy path is **byte-identical** after the W2.7 calculator extension — `W1 defaults are byte-identical to the legacy call (net=3349057)`, plus `hrms_p0_verify` money-precision at 125/0 and the unit suite at its exact baseline.

## I. Accounting parity — **NOT RUN**

No new posting path was created; the existing canonical accounting integration is untouched. `Dr == Cr` remains green in the W1 verifier. Full legacy-vs-new posting comparison belongs with the shadow cycle.

## J. RBAC verification — **PASS**

Graded `HR` only. Page `HR:view`; every mutating compensation endpoint `HR:manage`; forms hidden when `can_manage` is false **and** the endpoints enforce it independently. No new module; role names never override graded permissions. Gap 92 remains W5.

## K. Tenant isolation — **PASS**

442/442 rules assertions across 20 suites, including cross-tenant read/write/delete denial, forged and missing `schoolId`, for every compensation collection. The readiness script discovered tenants at runtime; **no tenant id is hardcoded anywhere** in the compensation layer (test-asserted by regex).

## L. Payroll readiness — **PASS**

Named, per-employee blockers: no effective assignment · draft assignment · no components · missing bank/PAN/PF/ESI (opt-in). Warnings that do **not** block: band breach · reference to a deleted grade. **Missing configuration is never zero pay.**

## M. Feature flag status — **OFF**

`USE_COMPONENT_COMPENSATION = false` · `COMPENSATION_SHADOW_COMPARE = false`. Both verified false at runtime.

## N. Legacy path status — **INTACT AND AUTHORITATIVE**

Untouched and still the only path payroll uses. It will not be retired in the same change as any future cutover.

---

## O. Remaining deferred capabilities

Unchanged and **none dropped**: FORMULA components · DAILY / HOURLY pay bases · bulk assignment · tenant state master · assignment approval workflow · shifts · roster · overtime · ESS · employee salary self-access · payslip experience · payroll approvals · lifecycle · payroll RBAC separation · arrears/retro · Corporate Pack · Retail Pack · and every other capability in the locked 95-capability matrix.

## P. Exact W3 prerequisites

W3 (payroll run experience) does **not** depend on the compensation cutover. It builds the run workflow around whichever compensation path is authoritative, and the reader seam means that can be legacy today and components later without W3 changing.

Genuinely required before W3:
1. Nothing blocking — W3 can start on the legacy path.
2. Recommended first: the readiness surface (built, W2.5) becomes the run's preflight step.

## Q. Operator actions required

| # | Action | Why only you can do it |
|---|---|---|
| 1 | **Decide the statutory policy** for the tenant with payroll data — PF rates + ceiling + eligibility-above-ceiling, ESI rates + threshold + applicability, PT state + slabs, TDS mode/rate, and the effective date for each | It is legal/organisational policy. Observed employee percentages are not a declared tenant policy, and I must not convert one into the other |
| 2 | **Authorise the component seed**, and say whether to seed all 8 tenants or only the one that runs payroll (`--school=`) | Production write, 104 documents |
| 3 | **Supply the migration effective date** (`--effective-from`) | The legacy records carry no compensation effective date; inventing one fabricates history |
| 4 | Confirm whether the 7 payroll-less tenants should be configured now or when they start using payroll | Product/rollout decision |

## R. Blockers

| Blocker | Severity | Owner |
|---|---|---|
| **No statutory policy configured** — halts the cutover at step 2 | **BLOCKING** | you (§Q.1) |
| Component catalogue unseeded | Blocking, but only after Q.1 | you (§Q.2) |
| No migration effective date | Blocking, after Q.1–Q.2 | you (§Q.3) |

No technical blockers. Everything outstanding is a decision, not an implementation.

## S. Confirmation — no HRMS capability was dropped

**Confirmed.** Nothing was removed, reinterpreted, downgraded or narrowed. The locked 95-capability matrix stands in full; §O lists every deferred item, each carrying a wave. This task performed **no writes of any kind** and therefore could not have narrowed anything.

**Built on existing ZenX throughout:** one payroll engine (`_compute_payslip()`, extended not replaced) · one money policy · one statutory resolver shared by payslip and CTC · one effective-dating implementation · one employee master · one attendance system · one leave system · one accounting path · one RBAC · no new entitlement key.

---

## Why this is the correct stopping point

The cutover is **not** complete, and per your instruction it is not being declared complete merely because the code could technically be enabled. It halted where your own rule says it must:

> *"If required statutory information is missing for a tenant: STOP that tenant's migration and report it as STATUTORY CONFIGURATION REQUIRED."*

Every gate that could be evaluated without inventing data has been, and passes: legacy byte-identity, tenant isolation, RBAC, readiness, offline parity, migration data quality. The single thing standing between here and a safe cutover is a policy decision that is genuinely yours.

**Nothing committed, pushed, deployed, migrated, seeded or enabled.**
