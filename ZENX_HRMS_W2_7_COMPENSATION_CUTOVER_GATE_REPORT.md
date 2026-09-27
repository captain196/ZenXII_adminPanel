# ZENX HRMS — W2.7 COMPENSATION CUTOVER GATE — VERIFICATION REPORT

**Date:** 2026-08-09
**Gate:** W2.7 — classify remaining gaps, then close the ones that block cutover
**Result:** **TWO CUTOVER BLOCKERS CLOSED — LEGACY PAYROLL BYTE-IDENTICAL**
**Status:** UNCOMMITTED, UNPUSHED, flags **OFF**, reader **NOT WIRED**, rules **NOT DEPLOYED**, no tenant data written
**Cutover:** **NOT DONE** — still gated on your statutory decision (W2.2 §17)

---

## A / B / C. Every W2.6 gap — classified, with evidence

The classification question was *"can ZenX safely calculate real production payroll using the new compensation model if this capability is not yet present?"*

### REQUIRED BEFORE CUTOVER — implemented in W2.7

| # | Gap | Why it blocks | Evidence |
|---|---|---|---|
| **3** | **Ceiling-aware statutory** | W2.5 shipped a UI where an admin enters a **PF ceiling** and **ESI threshold**. The calculator ignored both — its only reference to "ceiling" was a *comment* saying they were out of scope. A configured ceiling that silently does nothing is a broken promise, and it made CTC and the payslip disagree by construction. | grep: 1 hit for `ceiling` in `_compute_payslip`, and it is a comment |
| **4** | **Tenant components reaching the payslip** | The reader mapped only the 13 legacy codes and merely *logged* anything else. Flip the flag with a tenant component like "Conveyance ₹2,500" configured, and it would be **silently dropped** — a money error, not a missing feature. | `Compensation_reader::resolveFromComponents()` `log_message('debug', … will not reach the calculator)` |

### SAFELY DEFERRED — with the reason each is safe

| # | Gap | Why deferral is safe | Wave |
|---|---|---|---|
| 1 | FORMULA evaluator | The catalogue **refuses to create** a FORMULA component, so no assignment can reference one. Payroll cannot encounter it. | W2.8 |
| 2 | DAILY / HOURLY | The assignment validator **refuses both**, so no employee can be on them. Monthly employees are unaffected. Wiring them means changing W1.2 proration and needs the attendance-hours contract. | W4 |
| 10 | Tenant `state` field | Without state+slabs PT resolves to **0** and the statutory table visibly shows "0 slabs". No wrong number is produced. | W2.8 |
| 11 | Bulk assignment | Operationally expensive, never incorrect. Every assignment it would create must pass the same validation. | W2.8 |
| 12 | Assignment approval | Readiness already blocks `draft` assignments, so the protective effect exists. Approval adds workflow, not correctness. | W5 |
| 5–9 | Reader wiring · seed · migration · shadow · flag | The cutover sequence itself — gated on your statutory decision. | cutover gate |

**Nothing was pushed to a later wave without a reason that survives the classification question.**

---

## D. Compensation calculation status

`_compute_payslip()` gained **two optional parameters, both defaulting to today's behaviour**:

```php
_compute_payslip($sal, $workingDays, $absent, $lwp,
                 $extraWorkedDays = 0.0, $extraWorkedRate = 1.0,
                 ?array $statutory = null,      // null → legacy flat percentages
                 array  $extraComponents = [])  // []   → nothing changes
```

That shape is the whole safety argument: **every one of the 5 existing call sites omits both**, so the certified engine is untouched in production while the capability exists for the cutover.

**Proven, not asserted** — `W1 defaults are byte-identical to the legacy call (net=3349057)`, comparing the implicit call against an explicit `null, []` call on the real engine.

## E. Statutory calculation status

With a policy supplied, PF/ESI/TDS/PT resolve through `Statutory_config_service` — **the same resolver the CTC preview uses**, which is what makes CTC and the payslip agree. The W2.6 inconsistency is closed.

Verified on the real engine:

| Check | Result |
|---|---|
| No config → PF ignores the ceiling (legacy) | `ee = 360000` (12% of ₹30,000) |
| With config → PF **caps at the ceiling** | `ee = 180000` (12% of ₹15,000) |
| ESI above threshold → **not covered**, not capped | `ee = 0` |
| Payslip explains why ESI was skipped | warning emitted |
| No slab table → structure PT stands | `pt = 20000` |
| Configured slabs supersede flat PT | `pt = 25000` |
| Payslip records which policy priced it | `statutory_source` |

**Two deliberate conservative choices:**
- **PT slabs supersede the flat amount only when a slab table exists.** Otherwise configuring statutory policy would silently zero an existing professional-tax deduction.
- **An unsupported TDS mode never guesses.** It falls back to the structure's flat rate and says so on the payslip.

## F. Tenant component → payslip status

**CLOSED.** Tenant earnings join gross **before** the statutory block — so ESI/TDS see them, consistent with the engine's "statutory is a percent of earned wages" contract — and tenant deductions join the deduction pool. Both appear in a new `extra_components` breakdown (positive earnings, negative deductions).

| Check | Result |
|---|---|
| Tenant earning raises gross | 4,200,000 → 4,450,000 |
| Tenant deduction reduces net by exactly its amount | 3,788,500 → 3,738,500 |
| Components appear in the breakdown | ✅ |
| **`gross == net + deductions` still holds** | `4450000 == 3986625 + 463375` |
| Prorated extra scales with attendance | half a month → 125,000 |
| Non-prorated extra does not scale | −50,000 unchanged |

**No second calculator was created.** One engine, extended.

## G. Formula status — DEFERRED (W2.8)

Safe because creation is refused, so no assignment can carry one. When built it will use a controlled operand/operator model — never arbitrary code execution.

## H. DAILY / HOURLY status — DEFERRED (W4)

Safe because the validator refuses both. Not rejected permanently: it lands with shifts/roster/overtime, where the attendance-hours contract it depends on is built. Monthly stays untouched.

## I. Bulk assignment — DEFERRED (W2.8) · ## J. Approval — DEFERRED (W5)

Neither affects calculation correctness. Approval already has its protective half: `draft` blocks payroll.

## K. Migration status

**Unchanged.** Dry-run only, `--commit` still requires `--effective-from`, legacy untouched, 0 documents written.

## L. Reader wiring status

**NOT WIRED** — and the boundary was **re-stated, not relaxed**:

| Assertion | Result |
|---|---|
| `Hr.php` **may** use `Statutory_config_service` (W2.7, deliberate) | ✅ |
| `Hr.php` does **not** query `statutoryConfig` itself — policy arrives as a parameter | ✅ |
| `Hr.php` does not read `salaryComponents` | ✅ |
| `Hr.php` does not read `staffSalaryAssignments` | ✅ |
| `Hr.php` does not use `Compensation_reader` | ✅ |
| `Payroll.php` references none of the five | ✅ |

The calculator **receives** policy and components; it never fetches them. That is what keeps the cutover un-flipped while the capability exists.

## M. Shadow comparison

Not run — unchanged from W2.4 §2: the collections are still empty, so a shadow would compare against nothing. The offline parity gate still reports **8/8 structures, no divergence**.

## N. Cutover status — **NOT DONE**

Both flags false. Steps 1–4 of the W2.4 §9 sequence remain, gated on your statutory decision.

## O. Legacy compatibility

**Byte-identical**, proven three ways: the runtime equality check (W1); `hrms_p0_verify` money-precision + `M`/`W` attendance groups at **125 PASS / 0 FAIL**; and the full unit suite back at its exact 10-failure baseline.

## P. Multi-tenant isolation · ## Q. RBAC

Unchanged and re-verified. No tenant hardcoded; statutory policy and components are passed in by a caller that resolved them tenant-scoped. Graded `HR` only; no new module.

---

## R. Regression

| Suite | Before W2.7 | After W2.7 | New failures |
|---|---|---|---|
| PHP unit | 343 / 1125 / **10** | **343 / 1130 / 10** | **0** |
| Firestore rules | 442 / 20 suites | **442 / 20 suites** (no rules change) | **0** |
| `hrms_p0_verify` (W1 money + attendance) | 125 / 0 / 1 | **125 / 0 / 1** | 0 |
| `hrms_w13_verify` · `hrms_closure_verify` | PASS | **PASS** | 0 |
| `hrms_w2_verify` | 24 / 0 | **44 / 0** (+20 W2.7 checks) | 0 |
| `hrms_w2_parity` | PASS | **PASS** | 0 |

**Two failures I introduced, and how they were resolved — honestly:**

1. **`W12 a tenant deduction reduces net`** failed. **My test was wrong**, not the code: the fixture carried a ₹2,500 earning *and* a ₹500 deduction, so net legitimately rose. Rewritten to isolate the deduction — it now asserts net falls by exactly ₹500.
2. **`StatutoryEngineTest::test_payroll_path_is_untouched_by_w2_2`** failed, and the unit suite went 10 → **11**. That assertion encoded the W2.2 boundary, which W2.7 deliberately moved. It was **re-stated rather than deleted**: the calculator may now be statutory-aware, but must not fetch policy, and must still know nothing of the reader or assignments. Suite is back at 10.

Neither was a case of changing production code to satisfy a test, and neither was silently dropped.

## S / T. Remaining capabilities → waves

| Remaining | Wave |
|---|---|
| Reader wiring · seed · migration `--commit` · shadow · flag cutover | **cutover gate** (W2.4 §9) |
| FORMULA evaluator · bulk assignment · tenant `state` field | **W2.8** |
| Payroll run experience · snapshot · LOP confirm · per-employee warnings · trace · editable grid · async payslips | **W3** |
| Shifts · roster · overtime · DAILY/HOURLY | **W4** |
| ESS · own-salary access · payslip experience · approvals (incl. assignment approval) · lifecycle · payroll RBAC split (gap 92) · arrears/retro | **W5** |
| Corporate / Retail packs | **W6** |

## U. Confirmation — nothing removed from the 95-capability scope

**Confirmed.** No capability was removed, reinterpreted, downgraded or moved out of scope. Every deferral in §B carries an explicit reason that answers the classification question, and every item carries a wave.

**Built on existing ZenX:** one payroll engine — `_compute_payslip()` **extended, not replaced or duplicated** · one money policy · one statutory resolver, now shared by the payslip *and* the CTC preview · one effective-dating implementation · one employee master · no new entitlement key · no new RBAC module.

---

## Why W2.7 is not "complete" in the sense of cutover

Per your instruction, this is **not** declared complete because a flag could be flipped. It is complete in the sense that **the two capabilities whose absence would have made the new compensation model produce incorrect payroll are now closed**, and the legacy path is provably unchanged.

What remains before the flag may move is operational, in this order: seed the catalogue → **your statutory decision** → migrate with an explicit effective date → re-run parity against migrated data → wire the reader → shadow a full cycle → then flip.

**Nothing committed, pushed, merged or deployed. No tenant data written. No flag flipped. No migration run.**
