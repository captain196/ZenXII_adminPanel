# ZENX HRMS — PAYROLL ↔ ATTENDANCE CANONICAL CALENDAR DECISION

**Date:** 2026-08-10 · **Type:** architecture investigation · **Nothing implemented or changed**
**Method:** every claim below is traced to a file, function and line. Assumptions are listed in §14.

---

## 1. Actual current data flow — one employee, one month

| # | Step | File · function | Collection · field | Calculated or persisted | Authority |
|---|---|---|---|---|---|
| 1 | Weekly-off config | `Attendance.php:2621,2653` `save_settings` | `schools/{id}.attendancePolicy.weeklyOffs` — flat `['Mon'…'Sun']` | persisted | **AUTHORITATIVE (attendance side)** |
| 2 | Holidays | `Holiday_service::holidays_in_month()` | `calendarEvents` | calculated from persisted | **AUTHORITATIVE** |
| 3 | Employee schedule assignment | — | — | **DOES NOT EXIST** | — |
| 4 | Punch → decision | `Attendance_policy::evaluate()` | none (pure) | calculated | **AUTHORITATIVE (status rules)** |
| 5 | Per-day record | `Attendance.php` `mark_staff_day` / `bulk_mark_staff` / `autofill_staff_today` / `save_staff_attendance` | `staffAttendance/{school}_{date}_{staff}` · `status` | persisted | **AUTHORITATIVE (what happened)** |
| 6 | Rest-day / auto-absent overlay | `Staff_attendance_finaliser::run()` → `_overlay()` :245 | rewrites `dayWise` | calculated → persisted | **DERIVED** |
| 7 | Month aggregate | same, CAS-protected | `staffAttendanceSummary/{school}_{staff}_{monthKey}` · `dayWise` | persisted | **DERIVED from 5+6** |
| 8 | Payroll reads attendance | `Hr::generate_payroll` :~6100 | `staffAttendanceSummary.dayWise` | read | consumed |
| 9 | Payroll computes the calendar | `Hr::_staff_working_days()` | none — in memory | **calculated independently** | **SECOND AUTHORITY ← the fault** |
| 10 | Parse loop | `Hr::generate_payroll` :~6486 | — | calculated | — |
| 11 | Frozen evidence | both slip writers | `_attendance_snapshot` (`day_wise`, `window_mask`, `lwp_mask`, `working_mask`, `derived`) | persisted | **AUTHORITATIVE (historical)** |
| 12 | Money | `Hr::_compute_payslip()` | `_calculation_trace.paise` | calculated → frozen | **AUTHORITATIVE** |

**Two authorities answer "was this a working day?"** — step 6 (root `weeklyOffs`) and step 9 (payroll's own calendar). They are not connected.

## 2. What Payroll actually needs — **answer: C, both, with a precise split**

Proven, not asserted. Payroll's own parse loop says:

```php
// 'P' present, 'H' holiday, 'T' late, 'O' weekly-off → no pay effect.
// Non-working days are derived by _staff_working_days() from the holiday
// calendar + weekly-off policy, so 'H'/'O' marks are informational here
// and must NOT be double-counted.
```

**Payroll deliberately ignores `O` and `H` in `dayWise`** and derives non-working days itself. So today it needs the calendar for the **denominator** and attendance for the **numerator**.

**Why it cannot get the denominator from `dayWise` alone** — decisive, from code:

```php
} else {
    // No attendance doc at all — every in-window day is vacant
    $vacantDays = $staffWorkingDays;
}
```

An employee with **no `staffAttendanceSummary` document at all** still needs a working-day count. There is no `dayWise` to read. **A schedule authority is structurally required**, independent of attendance.

Your two examples resolve as:

| Case | Schedule | Attendance | Correct payroll semantics |
|---|---|---|---|
| Sunday, schedule says **working**, absent | working day | `A` | counts toward absence; denominator +1 |
| Sunday, schedule says **off**, employee worked | non-working | `W` | **not** absence; denominator +0; **extra-worked pay credited** |

## 3. Canonical day model — the concepts that must stay separate

Two **independent dimensions**:

```
DIMENSION 1 — SCHEDULE:   scheduled_working | scheduled_off | holiday | outside_employment_window
DIMENSION 2 — ACTUAL:     P T M | A | L(paid) | L(unpaid→LOP) | W | V(unmarked)
```

`dayWise` is **one character per day** — it can hold only one dimension at a time. Where a day carries `A`, the schedule dimension is **lost**.

Verified status semantics against `Staff_attendance_finaliser::STATUS_TO_FIELD` and the payroll parse loop:

| Char | Meaning | Schedule implied? | Payroll effect today |
|---|---|---|---|
| `P` | present | working | none (informational) |
| `T` | late | working | none |
| `M` | half-day | working | +0.5 absence (unless leave covers it) |
| `A` | absent | **ambiguous — see §4** | +1.0 absence |
| `L` | leave | working | paid or unpaid per leave record |
| `H` | holiday | non-working | none (informational) |
| `O` | weekly-off | non-working | none (informational) |
| `W` | worked on rest day | **non-working** | extra-worked pay; **must not reduce absence** |
| `V` | unmarked | unknown | per `vacant_treatment` (block/absent/present/ignore) |

## 4. Is `dayWise` sufficient? — **NO. Three proven gaps.**

**Gap 1 — `A` on a rest day is never corrected.** `_overlay()` line 249:

```php
if ($ch !== 'V' && $ch !== 'A') continue;   // only unmarked / auto-absent
...
} elseif ($ch === 'V') {                     // ← ONLY V becomes O
    if (in_array(dow, $weeklyOffs)) $dayWise[$d-1] = 'O';
```

`V` on a weekly-off → `O`. **`A` on a weekly-off stays `A`.** A device punch, bulk mark or admin mark that wrote `A` on a rest day is permanently indistinguishable from a genuine absence.

**Gap 2 — `O` requires two preconditions.** `run()` line 94: `if (!$autoAbsent) return $out;` — nothing is written at all, including rest-day labelling. And `_overlay` needs a non-empty `weeklyOffs`. Either unset ⇒ **no `O` ever produced**.

**Gap 3 — no document, no data.** Unmarked employees have no `dayWise` (§2).

**Minimum additive information required:** a **per-day schedule dimension** — one positional mask per employee-month recording scheduled-working vs scheduled-off vs holiday vs outside-window. `dayWise` stays exactly as it is; the schedule dimension travels alongside it. **No redesign of Attendance is warranted by this evidence.**

## 5. N-23 root cause, from the real data model

`_staff_working_days()` builds `inWindow` **before** the non-working tests and returns only a *count*:

```php
$inWindow[$d] = true;          // ← every day in the employment window
if ($dow === 0) continue;      // Sunday        ← decision made…
$working++;                    //               …and discarded
```

The parse loop then gated on `inWindow` alone, so a mark on a rest day was charged. The clamp `min($daysAbsent, $staffWorkingDays)` hid the overflow instead of raising it.

## 6. Date-by-date proof — PR0003 · STA0001 · May 2026

Frozen evidence: `day_wise = "LLLAAAAAAAAAAAAAAAAAAAAAAAAAAAA"` · `working_days: 24` · `days_in_month: 31` · `holiday_days: []` · leave = PATERNITY, `paid: true`, `paid_days: 3`, `lwp_days: 0`.

| Day | DOW | Schedule* | dayWise | Counted absent (before) | Counted absent (correct) | Leave | LOP |
|---|---|---|---|---|---|---|---|
| 1 | Fri | working | `L` | — | — | paid +1 | — |
| 2 | Sat (1st) | working | `L` | — | — | paid +1 | — |
| 3 | **Sun** | **off** | `L` | — | — | **paid +1 ← wrongly counted** | — |
| 4–8 | Mon–Fri | working | `A` ×5 | +5 | +5 | — | — |
| **9** | **Sat (2nd)** | **off** | `A` | **+1 ✗** | — | — | — |
| **10** | **Sun** | **off** | `A` | **+1 ✗** | — | — | — |
| 11–16 | Mon–Sat | working | `A` ×6 | +6 | +6 | — | — |
| **17** | **Sun** | **off** | `A` | **+1 ✗** | — | — | — |
| 18–22 | Mon–Fri | working | `A` ×5 | +5 | +5 | — | — |
| **23** | **Sat (4th)** | **off** | `A` | **+1 ✗** | — | — | — |
| **24** | **Sun** | **off** | `A` | **+1 ✗** | — | — | — |
| 25–30 | Mon–Sat | working | `A` ×6 | +6 | +6 | — | — |
| **31** | **Sun** | **off** | `A` | **+1 ✗** | — | — | — |

\* per the calendar the run itself recorded (`sundays: 5`, `off_saturdays: 2`).

| | Before | Correct |
|---|---|---|
| Absence marks counted | 28 → clamped **24** | **22** |
| Working-day denominator | 24 | 24 |
| Paid leave on **working** days | 3 (incl. a Sunday) | **2** |
| Retention ratio | `1 − 24/24` = **0** | `1 − 22/24` = **2/24** |
| Contracted gross | ₹15,000 | ₹15,000 |
| **Gross paid** | **₹0.00** | **₹1,250.00** |

**Six absence marks on non-working days, plus one paid-leave day on a Sunday.** The corrected figures self-validate: `22 absent + 2 paid-leave working days = 24`, and retention `2/24` equals exactly the paid-leave working days. **Underpayment ₹1,250.**

## 7. THE DECISION — Model A, with attendance as sole producer of `dayWise`

**Model B (dayWise is the only payroll input) is DISPROVEN** by three code facts:
1. unmarked employees have no `dayWise` at all (§2);
2. `O` is not reliably produced (§4 gaps 1–2);
3. an `A` on a rest day is never corrected, so the schedule dimension is unrecoverable.

**Model A is what the code already implements, and it is correct** — but it is currently implemented **twice**. The fix is not a new model; it is **one schedule authority consumed by both**:

```
              ONE SCHEDULE AUTHORITY
     (organisation → named schedules → employee assignment → per-day calendar)
                          │
          ┌───────────────┴───────────────┐
          ▼                               ▼
    ATTENDANCE                        PAYROLL
  produces dayWise                  denominator = schedule
  (what happened)                   numerator   = dayWise
          └───────────────┬───────────────┘
                          ▼
                 FROZEN PAYROLL EVIDENCE
              dayWise + working_mask + schedule identity
```

**Your expectation was that payroll should not recalculate the calendar. The evidence partly supports it and partly refutes it:** payroll must not own a *second* calendar — but it cannot take the denominator from `dayWise`, because for an unmarked employee there is no `dayWise`. It must **ask the same authority Attendance asks**.

## 8 / 9. Minimum changes

| # | Change | Status |
|---|---|---|
| 1 | Named schedules + effective-dated employee assignment | **BUILT** (`Work_schedule_service`) |
| 2 | Payroll resolves per employee | **BUILT** |
| 3 | Freeze `working_mask` + schedule identity | **BUILT** |
| 4 | Admin surface for schedules + assignments | **NOT BUILT — blocks everything** |
| 5 | `Staff_attendance_finaliser` reads the employee schedule, not root `weeklyOffs` | **NOT BUILT (N-35)** |
| 6 | `_overlay()` must correct **`A` on a scheduled rest day → `O`** | **NOT BUILT — new, from §4 gap 1** |
| 7 | Retire root `weeklyOffs` writers/readers | after 5–6 |

**Item 6 was not previously identified.** Without it, migrating the finaliser fixes only *unmarked* rest days; marked ones stay wrong.

## 10. Legacy classification

| Path | Class |
|---|---|
| `Hr::_staff_working_days()` | **AUTHORITATIVE (migrated)** — now delegates |
| `Hr` parse loops `A/M/L/V/W` | **AUTHORITATIVE** |
| Payroll ignoring `O`/`H` in dayWise | **CORRECT under Model A** — keep |
| `Staff_attendance_finaliser::_overlay()` root `weeklyOffs` | **MIGRATION REQUIRED (N-35)** |
| `Staff_attendance.php` weekly-off checks ×4 | **MIGRATION REQUIRED** |
| `Attendance.php` `weeklyOffs` writer + settings UI | **MIGRATION REQUIRED** |
| `attendancePolicy.workingWeek` + `Working_week` controller/UI/routes | **LEGACY (mine, N-36)** — NOT SAFE TO REMOVE until item 4 exists |
| `Working_calendar_service::resolveForMonth/effectiveVersion` | **DEPRECATED** — 1 caller |
| `Attendance::_get_sundays()` | **UI ONLY** — grid shading |
| `legacyRuleDescription()` | **HISTORICAL** — documentation |
| Run-doc `sundays`/`off_saturdays` | **HISTORICAL** (existing) / **DERIVED** (new = 0) |
| `Staff_attendance_writer` | **NOT WIRED** — scaffolding, stubs throw |
| Frozen snapshots, journals, audit | **HISTORICAL — NOT SAFE TO REMOVE** |

## 11. Historical reproducibility

Freeze at generation: `day_wise` · `window_mask` · `lwp_mask` · **`working_mask`** · `schedule_id` · `schedule_name` · `policy_version` · `policy_effective_from` · `assignment_effective_from` · `derived` counts · `_calculation_trace.paise`.

All are already written by both slip writers. **April's slips predate this and cannot be audited** — they must be flagged unauditable, never reconstructed (N-26). **No already-paid evidence is to be modified.**

## 12. Migration order — dependency-driven

1. **Schedules + assignments admin surface** ← everything blocks on this
2. Configure the Test School (**operator-declared**, never inferred)
3. Re-verify PR0003 → expect **₹1,250**
4. **N-35** finaliser migration **+ item 6** (`A` on rest day → `O`)
5. Verify Attendance and Payroll agree per employee/date
6. **N-36** retire `workingWeek`
7. Retire root `weeklyOffs`

## 13. Test plan

Convergence: same employee/date resolves identically in both systems · `A` on a scheduled rest day corrected to `O` · unmarked employee still gets a denominator · two employees, one month, different denominators · schedule change mid-year · closed period unaffected by later changes · `W` still payable on rest days · **PR0003 → ₹1,250** · frozen evidence reproduces a historical payslip.

## 14. Assumptions — **ONE**

Everything above is traced to code except: **§6 assumes the frozen `day_wise` for PR0003 is the string payroll actually consumed.** It is read directly from the stored `_attendance_snapshot`, which the engine wrote in the same request that computed the slip — but I did not observe that write happen.

**Not assumed:** the calendar (independently recomputed and cross-checked against the run's own `sundays: 5`/`off_saturdays: 2`), the paid-leave status (read from `leave_details`), the money (read from `_calculation_trace.paise`).

## 15. Unresolved decisions — yours

1. **Item 6 semantics:** should the finaliser rewrite a *marked* `A` on a scheduled rest day to `O`? It corrects the data — but overwrites a mark a human or device made. Alternative: leave it and have payroll ignore it via `working_mask`. **This changes whether attendance data is corrected or merely reinterpreted.**
2. **Attendance history:** existing summaries were produced under the old rules. Recompute, or leave and rely on `working_mask`? (I recommend leaving them — recomputation rewrites attendance history.)
3. **Test School schedules, assignments and effective dates** — required before PR0003 can be re-verified, and never to be inferred.

---

**Nothing implemented. No seed, migration, commit, push or deploy. PR0003 and all April evidence untouched. UAT paused at Test 4.**
