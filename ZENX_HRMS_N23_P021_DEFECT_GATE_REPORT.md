# ZENX HRMS — N-23 / P-021 DEFECT GATE — DYNAMIC WORKING-WEEK POLICY

**Date:** 2026-08-09 · **Result:** **IMPLEMENTED — awaiting your Test-School policy decision**
**Regression:** 485 tests / 1,576 assertions / **10 failures (documented baseline)** · all verifiers PASS
**PR0003:** **PRESERVED, UNMODIFIED** · Nothing committed, pushed, deployed, migrated or seeded

---

## 1. Root cause (recap)

`_staff_working_days()` computed the working/non-working decision per day and returned **only a
count**. Both attendance parse loops gated on the *employment window* alone, so an `A` on a Sunday
was charged as absence; the `min(absent, workingDays)` clamp then hid the overflow. Live result:
**6 of 28 `A` marks fell on non-working days; an employee on fully-paid paternity leave was paid ₹0
instead of ₹1,250.**

## 2. Canonical policy decision — as you directed

**There is no default calendar.** A tenant with no configured working-week policy is **BLOCKED**,
never guessed.

The legacy "Sunday + 2nd/4th Saturday" rule appears **nowhere as a fallback**. It survives only in
`Working_calendar_service::legacyRuleDescription()` — a documentation-only method, never consulted
during resolution, kept so an auditor can understand how pre-fix payslips were calculated. This is
**test-asserted**: the resolver's executable code contains no `satCount`, no `Sunday`, no `=== 2`,
no `=== 4`.

## 3. Before / after architecture

**Before** — two independent copies of one business rule, both hardcoded:
```
generate_payroll()          → own Sunday/2nd/4th-Sat loop (run-level counts)
_staff_working_days()       → own Sunday/2nd/4th-Sat loop (per-employee)
parse loops                 → gated on employment window only  ← the defect
```

**After** — one authority, consumed everywhere:
```
schools/{id}.attendancePolicy.workingWeek.versions[]   ← effective-dated (N-29)
        │  weeklyOffs: ['Sun']              fixed weekdays
        │  nthWeekdayOffs: [{day:'Sat', weeks:[2,4]}]   ← N-27, new capability
        ▼
Working_calendar_service::resolveForMonth()            ← THE authority
        │  isWorking[] · reason[] · workingMask · policyVersion · blocked
        ├──► _staff_working_days()        (delegates; holds no weekday logic)
        ├──► generate_payroll()           run-level counts + parse gate
        └──► regenerate_staff_payroll()   identical gate, same resolver
```

## 4. Files changed

| File | Change |
|---|---|
| **`libraries/Working_calendar_service.php`** | **NEW** — canonical resolver, nth-weekday support, effective dating, validation, no default |
| `controllers/Hr.php` | `_staff_working_days()` delegates; `_attendance_policy()` added; run-level calendar loop replaced; **both** parse loops gate on `isWorking` with the `W` exemption; generation blocks when unconfigured |
| **`tests/Unit/WorkingCalendarPolicyTest.php`** | **NEW** — 23 tests |

Reused, not duplicated: `ed_select_for()` (W2 effective dating), `Holiday_service`, the existing
`weeklyOffs` vocabulary and `date('D')` tokens.

## 5. Hardcoded calendar logic removed from payroll

`grep -c satCount Hr.php` → **0**. Both loops that encoded Sunday + 2nd/4th Saturday are gone —
including the **run-level** one, which was a *second* independent copy free to drift from the
per-employee one. Payroll now contains zero rules deciding which weekdays are working.

`sundays` / `off_saturdays` remain on the run document for back-compatibility, but are now **derived
from the resolved policy** — a Mon–Fri tenant will correctly report its Saturdays as weekly-offs.

## 6. Dynamic policy — proven, one engine, four calendars

May 2026, identical month, **no code difference**:

| Policy | Configuration | Working days |
|---|---|---|
| A | Sunday off | **26** |
| B | Saturday + Sunday off | **21** |
| C | Sunday + 2nd/4th Saturday | **24** |
| D | Sunday + 1st/3rd/5th Saturday | **23** |

Four distinct answers, asserted together in one test. Adding a new pattern is **configuration, not
code** — which is the point of N-27.

## 7. Effective dating / versioning (N-29)

Policy is a list of effective-dated versions resolved by `ed_select_for()` — the **same** primitive
the compensation layer uses, not a second implementation.

Asserted: May 2026 → V1 while August → V2; **adding a future version does not change a closed
period's `workingCount`, `workingMask` or `policyVersion`**; a period *before* any version is
**blocked**, not back-filled.

## 8. Attendance-mark semantics (§10)

`A`, `M`, `L`, `V` no longer register on non-working days. **`W` remains exempt** — it *means*
"worked on a scheduled non-working day", so suppressing it would destroy extra-worked pay.
Test-asserted at **both** writers (`!== 'W'` appears exactly twice).

Holidays stay a **distinct reason** from weekly-offs (`REASON_HOLIDAY` vs `REASON_WEEKLY_OFF`), and a
holiday landing on a weekly-off is **not double-counted**.

## 9. Unconfigured-tenant behaviour

`generate_payroll()` fails fast with **HTTP 409**:

> *"Working-week policy is not configured for this school. Configure the Attendance Working-Week
> Policy before generating payroll."*

A policy version that defines **no** offs is also treated as unconfigured — otherwise "every calendar
day is payable" would silently pay a full month to an absent employee.

## 10. `working_mask`

The resolver emits a positional mask (`1` working · `0` non-working · `-` outside window), day-1 =
offset-0, matching `day_wise`. Length always equals the month. Returned alongside `policyVersion`,
`policyEffectiveFrom` and `policySource`.

**Not yet wired into `_attendance_snapshot`** — see §13.

## 11. Tests — 23 new, all passing

Unconfigured blocked (4 shapes) · **legacy rule never used as fallback** · empty version = blocked ·
no hardcoded rule in resolver source · policies A/B/C/D · **same month → four different calendars** ·
holiday distinct from weekly-off · holiday on a weekly-off not double-counted · window exclusion ·
**rehire gap** · **nth-weekday counts from the month, not the window** · version selection ·
**future version does not alter a closed period** · pre-policy period blocked · mask positional and
month-length · validation (empty, all-off, bad date, bad day, bad week number, valid) · payroll holds
no weekday rules · both writers gate with the `W` exemption.

**Full suite: 485 / 1,576 / 10** — the documented baseline, no new failures.

*Three of my own assertions were too blunt and were corrected during this gate — most recently one
that banned the literal `'Sat'`, which legitimately appears in the weekday **vocabulary** constant.
The test now asserts the real property: all seven days present, none singled out.*

## 12. PR0003 — preserved, not yet re-verified

**Untouched.** Re-verification requires the Test School to have a configured policy, and **§14
forbids me seeding one**. The arithmetic is already proven independently: with a
Sunday + 2nd/4th-Saturday policy, May 2026 resolves to **24 working days** (test
`test_policy_C_...` asserts exactly this), giving 22 chargeable absences, retention 2/24, and
**₹1,250** — derived from `policy → calendar → attendance → leave → payroll`, with ₹1,250 hardcoded
nowhere in production code.

## 13. Honest status — what is NOT done

| Item | Status | Why |
|---|---|---|
| `working_mask` frozen into `_attendance_snapshot` | **NOT DONE** | Resolver emits it; the snapshot write is not yet extended |
| Admin policy UI (§5) | **NOT DONE** | No configuration surface exists yet — `N-30` |
| Policy write/validation endpoint | **NOT DONE** | Validation logic exists; no endpoint — `N-30` |
| N-26 historical audit | **NOT DONE** | Detection-only script not yet built |
| PR0003 re-verification | **BLOCKED** | Needs your Test-School policy decision |
| Statutory / accounting re-verification | **NOT DONE** | Depends on a real re-run |

**I am not claiming N-23/P-021 COMPLETE.** Against your 22 acceptance criteria, roughly 1–14 are met;
15–20 are not.

## 14. Register changes

| ID | Status |
|---|---|
| **N-23** | PARTIAL → **engine fixed**; verification pending |
| **P-021** | NEEDS REFACTORING → **RESOLVED in payroll** (one authority); Attendance-side consumers unchanged |
| **N-27** nth-weekday patterns | **COMPLETE** (model + resolver + tests) |
| **N-29** policy effective dating | **PARTIAL** — resolution works; no write path or history UI |
| **N-30** *(new)* | **MISSING** — **Working-week policy administration surface**: endpoint + UI to create/version policy, show "not configured", and validate before payroll. **Without it no tenant can ever configure a policy, so payroll is now blocked for all 8 tenants.** Owner: this gate's follow-on |
| **N-28** | **MISSING** — 8/8 tenants unconfigured; rollout task |

## 15. Remaining risks — stated plainly

1. **Payroll is now blocked for all 8 tenants** until each configures a policy. That is the intended
   safety behaviour, but it is a **live operational consequence** and `N-30` must land before any
   tenant can run payroll again.
2. **Historical slips are untouched and unexplained.** April's paid slips were priced by the legacy
   rule; nothing has been changed or re-priced, and `N-26` detection is still needed to identify
   exposure.
3. **The `working_mask` is not yet frozen**, so a slip generated right now would not record which
   calendar priced it.

---

## What I need from you

1. **The Test School's actual working-week policy** — I will not invent it (§14). Sunday +
   2nd/4th Saturday matches its historical behaviour, but that is your decision to state.
2. **Approve `N-30`** (policy admin surface) as the immediate follow-on — without it payroll cannot
   run for any tenant.
3. **Confirm** the remaining items in §13 belong in this gate or a follow-on gate.

**UAT remains paused at Test 4. Test 5 not started. No commit, push, deploy, migration or seed.**
