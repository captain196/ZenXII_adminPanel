# ZENX HRMS — W2.4 COMPENSATION READER + PARITY GATE — VERIFICATION REPORT

**Date:** 2026-08-09
**Gate:** W2.4 — `Compensation_reader`, feature flags, parity gate
**Result:** **IMPLEMENTED AND VERIFIED — NO DIVERGENCE DETECTED. ZERO PAYROLL BEHAVIOUR CHANGE.**
**Status:** UNCOMMITTED, UNPUSHED, flags **OFF**, reader **NOT WIRED**, rules **NOT DEPLOYED**
**Next gate:** W2.5 — compensation UI, grade master, statutory configuration surface

---

## 1. What was implemented

**`Compensation_reader`** — the single seam between payroll and where salary comes from. Three modes:

```
Hr::generate_payroll()          ← NOT yet wired (§3)
        │
        ▼
Compensation_reader::resolve(staffId, date)
        ├── legacy     → salarySlips structure              today's behaviour
        ├── shadow     → resolve BOTH, log divergence, RETURN LEGACY
        └── component  → staffSalaryAssignments + salaryComponents
        │
        ▼   the same 13 fields either way
Hr::_compute_payslip()          ← untouched, still the only payroll engine
```

**`hrms_compensation_flags.php`** — `USE_COMPONENT_COMPENSATION` and `COMPENSATION_SHADOW_COMPARE`, both **false**, following the `fees_exemption_v2_flags` precedent.

**`Hrms_w2_parity`** — the parity gate (§2).

The reader returns exactly the thirteen fields the calculator already reads — rupee amounts for fixed components, **percentages** for PF/ESI/TDS — regardless of which store answered. `_compute_payslip()` cannot tell the difference, which is the point: **there is one payroll engine and this is not a second one.**

---

## 2. The parity gate — and why it is not an in-request shadow

The plan assumed a shadow comparison running inside real payroll. **That cannot produce evidence today**, and saying so is the substantive finding of this gate:

```
salaryComponents          0 doc(s)
staffSalaryAssignments    0 doc(s)
statutoryConfig           0 doc(s)
salarySlips (structures)  8
```

A shadow run now would compare each legacy structure against **nothing** and dutifully report "no divergence" — a green that means nothing. The Student Entitlements Gate 5 hit exactly this and was deferred for the same reason.

So parity is proven a different way, needing no migration and touching no production data:

```
real legacy structure ──fromLegacyStructure()──► assignment
                                                     │
                                                     ▼
                                   Compensation_reader (component path)
                                                     │
       legacy 13-field shape ◄──── compare ──────────┘
```

If that round-trip is lossless over every real structure, the migration + reader reproduce today's payroll inputs exactly — which is what the cutover actually needs. **Result:**

```
A. Flags and wiring — the gate must be inert           7/7
B. Money boundary — paise → rupees → paise             2/2   (15/15 values lossless)
C. PARITY — every real legacy structure round-tripped  1/1   (8/8 identical)
D. Data state                                          1/1

RESULT: PASS — NO DIVERGENCE DETECTED over the structures that exist.
```

**Wording is deliberate.** "No divergence detected" — never "equivalence proven". The gate exercises the 8 structures that exist in 1 tenant, not every structure that could exist. Seven tenants have no compensation data at all, so they contribute no evidence.

The gate also validates each mapped assignment through the **real** `Salary_assignment_service::validate()` before comparing, so a record the service would reject can never be counted as parity.

---

## 3. What was verified

| Check | Result |
|---|---|
| Both flags ship **false**; resolve to legacy mode | ✅ |
| `Hr.php` / `Payroll.php` do not call the reader | ✅ unit-tested **and** runtime-checked |
| paise → rupees → `_toPaise()` round-trip lossless | ✅ 15/15, incl. odd paise (`3000001 → ₹30000.01 → 3000001`) |
| Legacy → assignment → reader reproduces legacy | ✅ **8/8 real structures, zero divergence** |
| FIXED lines → rupees; PERCENT lines → rates | ✅ (`basic` ₹30,000; `pf_employee` 12.0, not an amount) |
| BALANCING expanded, not dropped | ✅ ₹30,000 remainder reaches the calculator |
| Missing assignment → `null`, never zero pay | ✅ |
| Draft assignment / no lines → not payable | ✅ |
| `compare()` catches a **one-paisa** difference | ✅ |
| `compare()` does not invent one from float noise | ✅ (`0.1+0.2` vs `0.3` → identical at paise precision) |
| Shadow mode returns the legacy result and swallows its own faults | ✅ by construction |

**Why `compare()` gets this much attention:** the cutover decision rests on it. A tolerance that hides one paisa would be worse than no comparison at all, so amounts are compared at integer-paise precision and rates to four decimals.

---

## 4. What remains incomplete — and which wave owns it

**Nothing has been dropped.** Every item below is scheduled, not abandoned.

| Incomplete | Why | Wave |
|---|---|---|
| Reader **not wired** into `Hr.php` | Wiring is a one-line change, but it should follow a parity run over *migrated* data, not precede it | **W2.5** |
| Component seed not run on any tenant | Operator-triggered by design | W2.5 (UI) |
| W2.3 migration not committed | Needs an operator-supplied effective date | W2.5 |
| Statutory config for any tenant | Your decision (W2.2 §17) — unchanged | W2.5 |
| **Statutory deliberately not in the reader path** | Feeding ceilings/thresholds in changes computed money — a payroll-behaviour change needing its own gate, not a reader whose purpose is to be inert | later gate |
| Compensation / statutory / grade UI | — | **W2.5** |
| Grade master + band validation (`payGrade` stored, unvalidated) | — | **W2.5** |
| Bank / PAN / statutory-ID readiness checks (cap 41) | — | **W2.5** |
| CTC derivation | Needs employer contributions from the statutory engine | W2.5 |
| `DAILY` / `HOURLY` pay bases | Modelled, refused; wiring changes W1.2 proration | W4 / W6 |
| `FORMULA` evaluator | Modelled, refused | W2.5+ |
| Tenant-defined components reaching the payslip | Needs `_compute_payslip()` to iterate components | post-parity gate |
| Payroll run experience, snapshot, LOP confirm, trace, grid, async payslips | — | **W3** |
| Shifts / roster / overtime | — | **W4** |
| ESS, own-salary access, payslip experience, approvals, lifecycle, payroll RBAC split (gap 92) | — | **W5** |
| Corporate / Retail packs | — | W6 |

---

## 5. Confirmation — no HRMS capability was dropped

**Confirmed.** Every capability in the settled 95-item coverage matrix remains on the roadmap. This gate deferred nothing new; the items in §4 carry the wave that owns them, and the three currently-unconfigured areas (components, assignments, statutory) are **setup requirements with a configuration surface scheduled**, not removed features.

The readiness surface built in W2.3 is what makes that safe: an unconfigured tenant reports **named blocked employees**, so payroll stops visibly rather than producing wrong payslips.

## 6. Confirmation — built on existing ZenX, not a parallel HRMS

**Confirmed.**

- **One payroll engine.** `_compute_payslip()` is unmodified; only its future input source changes. The reader returns the shape it already accepts.
- **One money policy.** `splitGross()` uses `Statutory_config_service::applyRatio()`, runtime-proven identical to `Hr::_applyRatio()` (168/168).
- **One effective-dating implementation**, shared by statutory config and assignments.
- **One employee master, one attendance system, one leave system, one accounting path, one RBAC** — none duplicated.
- **No new entitlement key**; existing `hrms_core` / `hrms_payroll`.
- **No new RBAC module**; graded `HR`. Gap 92 stays W5.

---

## 7. Regression

| Suite | Before W2.4 | After W2.4 | New failures |
|---|---|---|---|
| PHP unit | 284 / 922 / **10** | **304 / 991 / 10** | **0** |
| Firestore rules | 408 / 18 suites | **408 / 18 suites** | **0** |
| `hrms_p0_verify` (W1) | PASS | **PASS** | 0 |
| `hrms_w13_verify` | PASS | **PASS** | 0 |
| `hrms_closure_verify` | PASS | **PASS** | 0 |
| `hrms_w2_verify` | 24 / 0 | **24 / 0** | 0 |
| `hrms_w2_parity` (new) | — | **11 / 0** | — |

10 PHP failures unchanged and pre-existing (1 `BackupTest` drift, 4 `Homework*`, 5 cross-repo Kotlin). W1 invariants green: `gross == net + deductions` · `Dr == Cr` · `M` half-day · `W` worked-on-off · LOP double-count · employment-window proration.

**No rules change in W2.4** — the reader reads collections whose blocks already exist; `firestore.rules` is unchanged since W2.3 (still 292+/8−).

---

## 8. Files changed

**New (4):** `application/config/hrms_compensation_flags.php` · `application/libraries/Compensation_reader.php` · `application/controllers/Hrms_w2_parity.php` · `tests/Unit/CompensationReaderTest.php`

**Modified:** none.

**Not changed:** `Hr.php` · `Payroll.php` · `Staff.php` · `Attendance.php` · `salarySlips` · `firestore.rules` · any view · any entitlement.

---

## 9. Exact sequence to flip the flag

Not to be done now — recorded so the order is unambiguous:

1. Seed the component catalogue for the tenant (`compensation/seed_system_components`).
2. Configure statutory settings — **your pending decision**, W2.2 §17.
3. Run the W2.3 migration with `--effective-from=YYYY-MM-DD --commit`.
4. **Re-run `hrms_w2_parity`** — it will then compare against real migrated documents rather than in-memory mappings.
5. Wire the reader into `Hr.php` (one call site, flag-guarded).
6. Enable `COMPENSATION_SHADOW_COMPARE` for a full payroll cycle; grep `COMPENSATION_AB_DIVERGENCE`.
7. Only after a clean cycle, flip `USE_COMPONENT_COMPENSATION`.
8. Retire the legacy path in a later gate — never in the same change as the cutover.

**Nothing committed, pushed, merged or deployed.** Awaiting review.
