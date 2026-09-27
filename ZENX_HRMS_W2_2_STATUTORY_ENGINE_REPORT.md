# ZENX HRMS — W2.2 STATUTORY ENGINE — VERIFICATION REPORT

**Date:** 2026-08-09
**Gate:** W2.2 — statutory configuration + effective-dated resolver
**Result:** **IMPLEMENTED AND VERIFIED — ZERO PAYROLL BEHAVIOUR CHANGE, NO TENANT DATA WRITTEN**
**Status:** UNCOMMITTED, UNPUSHED, rules **NOT DEPLOYED**
**Next gate:** W2.3 — effective-dated salary assignments

---

## Summary

PF / ESI / PT / TDS policy is now a modelled, effective-dated, tenant-scoped configuration with a pure resolver, replacing four operator-typed percentages that carried no ceiling, no eligibility threshold, no slab and no history.

**No tenant configuration was written.** Discovery (§1) found that 7 of 8 tenants have no statutory data at all, so seeding them would be inventing policy — a stop condition. §17 carries the one decision that needs you.

The payroll path is untouched: `Hr.php` and `Payroll.php` contain no reference to `statutoryConfig` or the resolver, enforced by both a unit test and a runtime check.

**The load-bearing verification** is that the resolver's arithmetic is *numerically identical* to the W1.2 money engine — 168/168 matching results across a grid of amounts and ratios, proven by invoking both `Hr::_applyRatio()` and `Statutory_config_service::applyRatio()`. Without that, statutory amounts and payslip amounts would drift a paisa apart and `Dr == Cr` would eventually fail.

---

## 1. Existing statutory-data distribution across tenants

Read-only probe, tenants discovered dynamically, nothing modified.

**Salary structures exist in exactly ONE tenant of eight.**

| Tenant | Structures |
|---|---|
| `SCH_D94FE8F7AD` | **8** |
| the other 7 tenants | **0 each** |

**Within that tenant, every statutory value is UNIFORM — not varying per employee:**

| Field | Distribution | Reading |
|---|---|---|
| `pf_employee` | `12 × 8` | uniform |
| `pf_employer` | `12 × 8` | uniform |
| `esi_employee` | `0.75 × 8` | uniform |
| `esi_employer` | `3.25 × 8` | uniform |
| `tds` | `0 × 8` | uniform (TDS unused) |
| `professional_tax` | `200 × 8` | uniform (₹200 flat) |
| `other_deductions` | `0 × 8` | uniform |

**This resolves the plan's open question (§9.1) rather than confirming it.** The plan anticipated that percentages "vary between employees" and that a canonical value would have to be chosen. They do not vary. There is no material conflict and no arbitrary choice to make *within that tenant*.

**Tenant state — present for only half the tenants, and inconsistently cased:**

| Tenant | State | City |
|---|---|---|
| `SCH_218AAF5C23` | `UTTAR PRADESH` | Etawah |
| `SCH_B9161BBE3C` | `UTTAR PRADESH` | Etawah |
| `SCH_D94FE8F7AD` | `Uttar Pradesh` | Etawah |
| `SCH_B56BB9A401` | `madhya pradesh` | — |
| `SCH_54D0424022` | **(none)** | Noida |
| `SCH_ACF67B0CD4` | **(none)** | Bhopal |
| `SCH_B76CFFF3C3` | **(none)** | Bhopal |
| `SCH_E9E683B586` | **(none)** | Bhopal |

**No statutory configuration exists anywhere else:** `statutoryConfig` 0 docs · `payrollSettings` 0 · `hrSettings` 0 · `salaryComponents` 0 (the W2.1 seed is operator-triggered and has not been run) · no statutory-ish keys on any `schools` document.

**Staff statutory identifiers** (`pfNumber`, `esiNumber`, `panNumber`, `uan`) show 0 on the main staff documents across all tenants — but these are PII-mirrored server-side, so this is **inconclusive**, not evidence of absence. Recorded as such rather than overstated.

---

## 2. Data sources inspected

`salarySlips` (type=`salary_structure`) · `schools` (all fields scanned for state/location and statutory-ish keys) · `staff` (statutory identifier presence) · `statutoryConfig` · `payrollSettings` · `hrSettings` · `salaryComponents` · `Hr::_compute_payslip()` and `Hr::save_salary_structure()` source.

---

## 3. Final `statutoryConfig` schema

`statutoryConfig/{schoolId}_{effectiveFrom}` — the effective-from date is the document-id suffix, so two configurations cannot share a start date by construction.

```
effectiveFrom  YYYY-MM-DD, calendar-validated (not just regex)
effectiveTo    YYYY-MM-DD | null   (null = open-ended)

pf   employeeRate, employerRate          decimal percent 0–100
     wageCeilingPaise                    integer paise
     applyCeiling                        bool — cap the base at the ceiling
     eligibleAboveCeiling                bool — is someone ABOVE the ceiling covered at all

esi  employeeRate, employerRate          decimal percent 0–100
     wageThresholdPaise                  integer paise
     applicable                          bool

pt   state                               operator-set string, NEVER inferred
     slabs[]  { fromPaise, toPaise|null, amountPaise }   integer paise

tds  mode                                FLAT_PERCENT | SLAB
     rate                                decimal percent (FLAT_PERCENT only)

createdAt/By · updatedAt/By
```

Unchanged from the approved W2 model; nothing invented. `tds.rate` is the one addition — `FLAT_PERCENT` is meaningless without it.

---

## 4. Resolver behaviour

Two separable responsibilities, kept apart:

- **`selectFor(configs, date)`** — which configuration is in force. Both bounds inclusive; `effectiveTo = null` is open-ended; when several match (which validation prevents) the latest `effectiveFrom` wins, so the answer never depends on read order.
- **`computePf` / `computeEsi` / `computePt` / `computeTds`** — given a configuration and a wage in paise, what does it produce. All pure and static.

Each returns `covered` / `matched` / `supported` plus a human-readable `reason`, so a later calculation trace (W2.6) can explain *why* an amount is what it is rather than only what it is.

**Responsibility split with W2.1 is preserved:** the component master says what a component *is* (`PF_EMPLOYEE.basis = BASIC`); the statutory engine says what the *policy* is. The resolver never decides a base — it is handed one.

---

## 5. PF handling

Two switches, deliberately **not** conflated:

| Switch | Meaning |
|---|---|
| `applyCeiling` | cap the contribution base at `wageCeilingPaise` |
| `eligibleAboveCeiling` | whether someone earning **above** the ceiling is covered **at all** |

`eligibleAboveCeiling = false` models the *excluded employee*: **zero contribution**, not a capped one. Collapsing these two into one flag is the classic PF bug, and both branches are tested separately.

Verified: below ceiling → 12% of full wage · above ceiling with cap → 12% of the ceiling · exactly at ceiling → capped value · ceiling off → uncapped · excluded above ceiling → not covered · separate employee/employer rates · zero wage.

**No rate is hardcoded in PHP.** The 12/12 figures appear only in test fixtures, taken from the observed distribution.

---

## 6. ESI handling

ESI is a **coverage test, not a cap** — the mirror image of PF, and the easiest thing to get backwards:

- wage **≤** threshold → covered on the **full wage**
- wage **>** threshold → **not covered at all**

Capping at the threshold (the intuitive-but-wrong reading) would silently under-deduct instead of exempting. Tested at, below and above the boundary, plus a tenant-level `applicable = false` switch.

---

## 7. PT handling

Slab-based flat amounts, not a rate. Band matching is inclusive on both edges; the top band may be open-ended (`toPaise = null`).

**Validation rejects ambiguity outright:** overlapping bands, more than one open-ended band, an open-ended band that isn't the highest, and inverted bands. Bands are sorted by start so evaluation can never depend on array order.

**State is never inferred.** Discovery found it on only 4 of 8 tenants and inconsistently cased. `pt.state` is stored verbatim as operator-set, defaults to empty, and no tenant's state was guessed from its city or address. **This is a dependency, not a solved problem — see §17.2.**

---

## 8. TDS boundary — stated plainly

`FLAT_PERCENT` reproduces exactly what ZenX does today (a flat percent of a supplied base), now under an explicit configuration instead of a per-employee typed field.

`SLAB` is **in the model but refused**. A real Indian TDS engine needs regime selection, investment declarations, exemptions and annual projection — none of which exist and none of which belong to W2.2. `computeTds()` returns `supported = false` and **zero** for any unsupported mode rather than producing a confident-looking wrong number, and `validate()` rejects configuring it.

**This is a documented boundary, not an implementation.** A slab/regime TDS engine is a later phase.

---

## 9. Effective dating

`findOverlaps()` rejects: identical `effectiveFrom` · intersecting windows · an open-ended predecessor swallowing a later window. `save()` runs it against the existing set before writing, so an ambiguous history cannot be created.

Tested: before any config (→ null, *not* the future config) · inclusive lower bound · inclusive upper bound · next window start · open-ended continuation · mid-history change · **past periods remain reconstructable after a later config is added** · deterministic selection under (prevented) ambiguity · missing configuration.

---

## 10. Security rules

```
match /statutoryConfig/{docId} {
  allow read: if isAdmin() && isSameSchool();
  allow write: if false;
}
```

Server-only writes, same reasoning as `salaryComponents` in W2.1 but with more at stake: a client able to write here could change **every employee's statutory deduction at once**, and because the documents are effective-dated, a forged window could rewrite what a **past, closed** payroll period resolves to — destroying the reconstructability that is the entire point of dating them.

The validation that prevents this (bounded rates, integer-paise ceilings, non-overlapping slab bands, non-overlapping effective windows) cannot be expressed in rules, so no client writes at all. The panel writes through PHP on the service account, which bypasses rules.

**19 rules tests, all passing**, including the specific attacks: lower a PF rate · raise the ESI threshold · rewrite the PT slab table · **forge an effective window over a past period** · cross-tenant read/write/delete · forged `schoolId` · missing `schoolId` · staff / parent / anon.

**No existing rule modified.** Not deployed.

---

## 11. RBAC

Existing graded `HR` module only — reads `HR:view`, writes `HR:manage`, via the same `Compensation` controller established in W2.1. **No statutory RBAC module, no payroll RBAC module, no role-name checks.** The Payroll/HR split remains W5 / G19.

## 12. Entitlements

Rides existing `hrms_core` / `hrms_payroll`. **No new entitlement key** (`hrms_statutory` etc.). **No tenant entitlement data modified.**

## 13. Tenant isolation

All access via `schoolWhere()` / `getEntity()` / `setEntity()` (which apply `docId()` = `{schoolId}_{effectiveFrom}`). No bare `query()`/`get()`. No hardcoded tenant — asserted by a unit test regex over the service source. Cross-tenant read, write and delete all denied (rules tests); each tenant reads only its own policy, asserted both ways.

---

## 14. Tests and assertions

| Suite | Count |
|---|---|
| `StatutoryEngineTest` (unit, behavioural) | **37 tests / 112 assertions** |
| `statutory_config_sec.test.js` (rules) | **19 tests** |
| `Hrms_w2_verify` (runtime, read-only) | **24 checks** |

Coverage against the brief's 30 requirements: PF normal/below-ceiling/above-ceiling/eligibility/rates (1–5) · ESI below/above/applicability (6–8) · PT lower boundary/upper boundary/multiple slabs/state (9–12) · TDS modes (13) · effective-date selection/future/historical/overlap-rejection/missing (14–18) · invalid rates (19) · negative money (20) · **decimal rupees rejected where paise required (21)** · tenant isolation (22) · unauthorized access (23) · forged `schoolId` (24) · W2.1 metadata unchanged (25) · payroll unchanged (26) · W1 money invariants (27) · Attendance/Leave contracts (28) · no RTDB (29) · no hardcoded tenant (30).

**Tests execute real production code**, not source text — the one exception is the `Hr::_applyRatio` *formula* check in the unit suite, because `Hr` extends `MY_Controller` which the PHPUnit bootstrap cannot load. The *numeric* equivalence is therefore proven at runtime instead:

```
A1 applyRatio is byte-identical to Hr::_applyRatio   (168/168)
A2 exact .5 products round identically
```

---

## 15. Regression results

| Suite | Before W2.2 | After W2.2 | New failures |
|---|---|---|---|
| PHP unit | 218 / 726 / **10 failures** | **255 / 838 / 10 failures** | **0** |
| Firestore rules | 370 / 16 suites | **389 / 17 suites, all passing** | **0** |
| `hrms_p0_verify` (W1 money · attendance · entitlement · isolation) | 125 / 0 / 1 | **125 / 0 / 1** | 0 |
| `hrms_w13_verify` | 44 / 0 / 1 | **44 / 0 / 1** | 0 |
| `hrms_closure_verify` | 24 / 0 / 0 | **24 / 0 / 0** | 0 |
| `hrms_w2_verify` (new) | — | **24 / 0 / 0** | — |
| `php -l` | clean | clean | — |

The 10 PHP failures are unchanged and pre-existing (1 `BackupTest` format drift, 4 `Homework*` source regressions, 5 cross-repo Kotlin tests).

**W1 invariants confirmed still green:** `gross == net + deductions` · `Dr == Cr` · Attendance `M` half-day · Attendance `W` worked-on-off · Leave/LOP double-count protection · employment-window proration.

**One transient worth recording:** an interim run reported the W1.3 verifier at 42 PASS instead of 44. Cause was mine — I invoked it without `SCHOOL_A`/`SCHOOL_B`, so its cross-tenant probe contributed 0 PASS instead of 2. Re-run with the env vars: 44/0/1. Not a regression.

---

## 16. Was any tenant data modified?

> **No. Nothing was written to any tenant.**

- No `statutoryConfig` document created — runtime-asserted (`D1`: 0 docs).
- No `salaryComponents` seeded — still operator-triggered (`D2`: 0 docs).
- No legacy `salarySlips` structure touched.
- Discovery was strictly read-only.

---

## 17. Policy decisions requiring your approval

**Two stop conditions from the brief are live. I have not chosen values for either.**

### 17.1 — Which tenants get a statutory configuration, and with what values

- **One tenant** (`SCH_D94FE8F7AD`) has 8 uniform structures: PF 12/12, ESI 0.75/3.25, TDS 0, PT ₹200 flat. Uniform ⇒ no *intra-tenant* conflict. But writing a tenant-level config still **asserts that these are that tenant's policy**, which is your call, not an inference from 8 rows.
- **Seven tenants have no statutory data at all.** Any value written for them would be invented. The brief's stop condition — *"migration would require choosing an arbitrary value"* — applies directly.

Also unresolved by the data: the observed values are rates only. **A PF wage ceiling and an ESI threshold do not exist anywhere in the current system**, so those cannot be migrated — they must be stated.

**Options** (I recommend the first): (a) configure only the tenant with data, values confirmed by you, leaving the rest unconfigured until they have payroll; (b) configure all tenants from a stated default; (c) defer all configuration to W2.3.

### 17.2 — Professional Tax state

PT slabs are state-specific. State is present for 4 of 8 tenants and inconsistently cased; 4 have only a city. **I did not infer any state**, including the apparently obvious cases (Bhopal → Madhya Pradesh), because an inferred state produces a legally wrong deduction with no visible error.

**Dependency:** a reliable tenant `state` field. It exists on `schools` for half the tenants and would need normalising and completing. Until then, `pt.slabs` stays empty and PT resolves to zero — which is visible and safe, rather than wrong and silent.

---

## 18. Known limitations

1. **`SLAB` TDS refused** — modelled, not implemented (§8). Deliberate.
2. **No statutory configuration exists for any tenant** — pending §17.
3. **PT is inert** until slabs and state are supplied.
4. **PF ceiling / ESI threshold have no legacy source** — must be stated, not migrated.
5. **Resolver not wired to payroll** — that is W2.4, by design.
6. **`prorated` interaction unresolved.** W2.1 flagged that percent components inherit proration from their base. The resolver takes a wage and does not know whether it was already prorated. W2.4 must pass an already-prorated base and must not re-apply the ratio.
7. **Staff statutory-identifier coverage is unknown** — the PII mirror makes the 0 counts inconclusive. Matters for W2.5's pre-run checks (cap 41).
8. **No admin UI** — endpoints only; UI is W2.5.
9. **Gap 92 not closed** — still `HR` RBAC.

---

## 19. Confirmation — payroll execution unchanged

- `Hr.php` and `Payroll.php` contain **no** `statutoryConfig`, **no** `Statutory_config_service`, **no** `salaryComponents` — verified by unit test *and* runtime check (group C).
- `_compute_payslip()`, `generate_payroll()`, `salarySlips` — untouched.
- No flag, no reader, no shadow compare, no cutover.
- W2.1's 13-component catalogue is unchanged (runtime-asserted).

## 20. Next gate: W2.3 — effective-dated salary assignments

`staffSalaryAssignments/{schoolId}_{staffId}_{effectiveFrom}` plus a **read-only dry-run** migration that maps each legacy structure into one assignment. Legacy remains untouched; `--commit` is a separate, separately-approved step. Still no payroll path change.

---

## Files changed

**New (4):** `application/libraries/Statutory_config_service.php` · `application/controllers/Hrms_w2_verify.php` · `tests/Unit/StatutoryEngineTest.php` · `firebase-rules/tests/statutory_config_sec.test.js`

**Modified (3):** `application/controllers/Compensation.php` (+2 endpoints) · `application/config/routes.php` (+2 routes) · `firebase-rules/firestore.rules` (+1 block, **not deployed**)

**Not changed:** `Hr.php` · `Payroll.php` · `Staff.php` · `Attendance.php` · `salarySlips` · any existing rule · any view · any entitlement.

**Nothing committed, pushed, merged or deployed.** Awaiting review — and the §17 decisions — before W2.3.
