# N-23 / P-021 — INSPECTION COMPLETE · DESIGN DECISION REQUIRED

**Date:** 2026-08-09 · **Status:** **STOPPED at §18 (material scope change)**
**Code changed:** **NONE.** PR0003 preserved. No policy, no tenant data touched.

---

## Why I stopped instead of implementing

§18: *"stop at the appropriate gate if it materially changes scope."* Two inspection findings make
the approved fix ("payroll consumes the configured policy") **unsafe to implement as specified**.

### FINDING 1 — No tenant has ever configured a weekly-off policy

| Tenant | `attendancePolicy.weeklyOffs` |
|---|---|
| SCH_218AAF5C23 | **UNSET** |
| SCH_54D0424022 | `[]` (empty) |
| SCH_ACF67B0CD4 | **UNSET** |
| SCH_B56BB9A401 | `[]` (empty) |
| SCH_B76CFFF3C3 | **UNSET** |
| SCH_B9161BBE3C | **UNSET** |
| **SCH_D94FE8F7AD** (test school) | **UNSET** |
| SCH_E9E683B586 | **UNSET** |

**8 of 8 tenants have no configured weekly offs.**

If payroll simply consumes `weeklyOffs`, an unset/empty value means **zero non-working days**:

| | Working days, May 2026 | Vipul's gross |
|---|---|---|
| Today (hardcoded Sun + 2nd/4th Sat) | 24 | ₹0 *(the N-23 defect)* |
| Naive "consume the policy" | **31** | different again |
| Corrected calendar + today's effective rule | 24 | **₹1,250** *(the correct answer)* |

**Naively consuming the policy would reprice every payroll in every tenant and break historical
reproducibility — a far worse defect than N-23.** April's 7 paid slips would no longer reproduce.

### FINDING 2 — The canonical model cannot express what payroll already does

`attendancePolicy.weeklyOffs` is a **flat list of weekday names**, validated against
`['Mon','Tue','Wed','Thu','Fri','Sat','Sun']` (`Attendance.php:2620`), matched via `date('D')`.

It **cannot represent "2nd and 4th Saturday off"** — the exact pattern payroll hardcodes, and a
standard Indian school/company work week.

So the canonical model is **less expressive** than the hardcoded rule it must replace. Consuming it
as-is would *remove* a real capability. Per §4, the canonical Attendance model must be **extended**,
not worked around in payroll.

---

## The decision I need — what does an unconfigured tenant do?

This is a money decision affecting all 8 tenants, so it is yours, not mine.

| Option | Behaviour when `weeklyOffs` is unset | Consequence |
|---|---|---|
| **A. Documented default (recommended)** | Resolver applies **Sun + 2nd/4th Sat** and flags the result as `source: 'default'` | Preserves every existing calculation exactly; N-23 fixed; tenants prompted to configure. The rule lives in the **policy layer**, not payroll — so §1 is still satisfied (payroll holds no calendar logic) |
| **B. Strict policy-only** | Unset ⇒ no weekly offs ⇒ all days working | **Reprices every tenant's payroll.** April slips stop reproducing. Not acceptable without a migration |
| **C. Block until configured** | Payroll refuses to run for unconfigured tenants | Safest correctness-wise, but halts payroll for all 8 tenants until each configures a policy |

**My recommendation: A**, with the default explicitly labelled in the frozen `working_mask` evidence
so a payslip records whether it was priced by a *configured* policy or by the *default*. That keeps
payroll free of hardcoded rules (§1), preserves history (§6), and makes the gap visible so tenants
migrate deliberately rather than silently.

**A also needs your ratification of the default itself** — Sun + 2nd/4th Sat is what the code does
today, but I am not willing to enshrine it as "the ZenX default" without you saying so.

---

## Proposed architecture (for approval, not yet built)

```
schools/{id}.attendancePolicy
   ├── weeklyOffs: ['Sun']                     ← EXISTING (fixed weekdays)
   └── weeklyOffPattern: [                     ← NEW capability (N-27)
         { day: 'Sat', weeks: [2, 4] }         ← nth-weekday offs
       ]
        │
        ▼
  Working_calendar_service        ← NEW, canonical, single authority
   resolve(schoolId, year, month, joinDate, exitDate) →
     [ isWorking[day], reason[day] ('working'|'weeklyOff'|'holiday'|'outsideWindow'),
       workingCount, policySource ('configured'|'default'), policyVersion ]
        │
        ├──► generate_payroll()            ← consumes; no calendar logic of its own
        ├──► regenerate_staff_payroll()    ← same resolver, same result
        └──► _attendance_snapshot.working_mask + policy_source + policy_version  (frozen)
```

- **One resolver.** `_staff_working_days()` delegates to it; no second calendar.
- **Holidays stay distinct** from weekly offs in `reason[]` (§9).
- **`W` exemption preserved** — worked-on-off still credited (§8).
- **Frozen evidence** records which policy priced the slip (§6, §10).

### New capabilities this exposes (registered, not silently absorbed)

| ID | Capability | Why |
|---|---|---|
| **N-27** | **nth-weekday weekly-off patterns in the canonical policy** | Model cannot express 2nd/4th Saturday; required for N-23 to be correct, not future work |
| **N-28** | **Weekly-off policy configured for existing tenants** | 8/8 unconfigured — an operator/rollout task, not code |
| **N-29** | **Attendance-policy effective dating / versioning** | Policy carries `version: 1` but there is **no history**; §6 needs it for historical reproducibility. Not required to fix N-23 if `working_mask` is frozen per slip |

**Also confirmed:** per-employee policy assignment (§5) **does not exist** in ZenX today — matrix
`P-017` is MISSING, policy is school-global. So the resolver takes `schoolId`, and per-employee
policy stays `P-017`'s scope. I am not inventing it here.

---

## What is NOT blocked

Everything else in the approved gate is unambiguous and I can proceed the moment you rule on the
default:

- returning a per-day `isWorking[]` map and gating both parse loops on it
- the `W` exemption
- freezing `working_mask` into `_attendance_snapshot`
- all 26 regression cases
- N-26 detection-only historical audit

---

## Answer these three and I will implement

1. **Unconfigured-tenant behaviour — A, B, or C?**
2. **If A: do you ratify Sun + 2nd/4th Saturday as the documented ZenX default?**
3. **Extend the canonical policy with `weeklyOffPattern` (N-27) now**, since without it a
   2nd/4th-Saturday school cannot be represented at all — or fix N-23 against fixed weekdays only and
   take N-27 as a follow-on?

**Nothing implemented. Nothing committed, pushed, deployed, migrated or seeded. UAT still paused at
Test 4. PR0003 evidence intact.**
