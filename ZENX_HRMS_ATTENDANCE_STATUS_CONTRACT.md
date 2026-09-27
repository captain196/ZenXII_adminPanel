# ZENX HRMS — ATTENDANCE STATUS CONTRACT & PAYROLL INTERPRETATION

**Date:** 2026-08-10 · **Type:** definitive data-flow proof · **No code modified**
Every claim is traced to file:line. Assumptions are listed in §7 (there is one).

---

## 1. Origin of every status — proven, not inferred

| Status | Meaning | Written by | Proof |
|---|---|---|---|
| **`P`** present | on-time punch on a scheduled working day | `Attendance_policy::evaluate()` Gate 7+ | `:299 _allow($base, STATUS_PRESENT, true)` |
| **`T`** late | punch past threshold + grace | same | `:301 _allow($base, STATUS_LATE, true)` |
| **`M`** half-day | worked ≥ halfDayHours, < fullDayHours | same, on check-**out** | `:333,346 _allow($base, STATUS_HALF, true)` |
| **`A`** absent | (a) check-out below half-day; (b) finaliser auto-absent; (c) manual mark | `:337` · `_overlay():267` · `Attendance.php` mark endpoints | three distinct origins |
| **`L`** leave | **leave approval writes back into attendance** | `Hr.php:4696-4712` `_apply_leave_to_attendance` — sets `dayWise[idx]='L'` **and** writes `staffAttendance` with `markedBy: 'leave_approval'` | not produced by a punch |
| **`H`** holiday | finaliser, from `Holiday_service` | `_overlay():262` `V→H`, incl. retroactive `A→H` | calendar-derived |
| **`O`** weekly-off | finaliser **only**, and **only from `V`** | `_overlay():265-268` | see §2 |
| **`W`** worked-on-rest-day | punch on a day already known to be a rest day | `Attendance_policy:231` Gate 6b | see §3 — the critical dependency |
| **`V`** unmarked | initial fill / no record | `Hr.php:4690`, writer defaults | absence of data, not a decision |

**Three independent producers** write into `dayWise`: the punch engine (P/T/M/A/W), the leave module (L), and the finaliser (V→O/A/H).

## 2. `O` has exactly one producer, with two preconditions

```php
// Staff_attendance_finaliser::run():94
if (!$autoAbsent) return $out;          // ← nothing written at all

// _overlay():249
if ($ch !== 'V' && $ch !== 'A') continue;
...
} elseif ($ch === 'V') {                // ← ONLY V becomes O
    if (in_array(date('D',…), $weeklyOffs, true)) $dayWise[$d-1] = 'O';
```

Therefore `O` requires: `autoAbsent` ON **and** non-empty root `weeklyOffs` **and** the day currently `V` **and** the date strictly before today.

**`A` on a scheduled rest day is never converted to `O`** — line 249 admits `A`, but the `O` branch is reached only for `V`. A device punch, bulk mark or admin mark that wrote `A` on a rest day is **permanently indistinguishable** from a genuine absence.

## 3. THE DECISIVE PROOF — Attendance already requires the schedule

`Attendance_policy::evaluate()` Gate 6b:

```php
$isWeeklyOff = !empty($context['isWeeklyOff']) || $currentMark === self::STATUS_WEEKLYOFF;
if ($isHoliday || $isWeeklyOff) {
    if (empty($policy['allowWorkOnOff'])) return $this->_reject(...);
    ...
    return $this->_allow($base, self::STATUS_EXTRA, true);   // stamps W
}
```

and the caller, `Staff_attendance.php:206`:

```php
$isWeeklyOff = in_array($now->format('D'), (array) ($policy['weeklyOffs'] ?? []), true);
```

**`W` can only be produced when the schedule is already known at punch time.** If the schedule is unknown, the punch falls through to Gate 7 and is recorded as `P`/`T` — the rest-day-working fact is lost at source and no later process can recover it.

> **This settles the central question.** The employee/date schedule is **not** a payroll-only concern — it is a **required input to attendance classification itself**. `O` and `W` cannot exist without it. So the schedule authority must sit *upstream of both*, and Payroll must consume the same one Attendance consumes — not maintain a second.

## 4. What `dayWise` actually is — **a combination, and that is the problem**

Evidence:

| Character | Dimension it records |
|---|---|
| `P` `T` `M` `A` `L` `W` | **attendance result** — what happened |
| `O` `H` | **calendar fact** — what was scheduled |
| `V` | **absence of a record** — neither |

So `dayWise` is **not** a pure attendance record, **not** a pure calendar record — it is **one character per day carrying whichever dimension was written last.**

The consequences follow directly:

1. Where a day holds `A`, the calendar dimension is **overwritten and unrecoverable** (§2).
2. `O`/`H` occupy the same slot as outcomes, so a rest day on which nothing happened and a working day on which nothing happened are distinguishable **only if** the finaliser ran with a configured policy.
3. `W` is the only character encoding **both** dimensions at once (scheduled off **and** worked) — which is why it must never be collapsed.

**`dayWise` cannot be repaired by redefining characters.** The two dimensions need two positional strings. This is exactly what `working_mask` (W3.2.5/N-23) provides — `dayWise` keeps recording *what happened*, `working_mask` records *what was scheduled*.

## 5. Payroll's treatment of every status — as currently coded

`Hr::generate_payroll` parse loop:

| Status | Payroll effect | Notes |
|---|---|---|
| `P` | **none** | presence is the default; pay comes from retention |
| `T` | **none** | lateness is not a pay event |
| `M` | `halfDays++`, `daysAbsent += 0.5` | **suppressed if leave covers the day** (double-count guard) |
| `A` | `daysAbsent += 1.0` | `0` if full-day LWP covers it; `+0.5` if half-day LWP |
| `L` | `paidLeaveDays++` **or** `unpaidLeaveDays++` | decided by the LWP day-map from `_process_leave_for_payroll()`, not by the character |
| `H` | **ignored** | explicit comment: *"informational… must NOT be double-counted"* |
| `O` | **ignored** | same comment — payroll derives non-working from the calendar |
| `W` | `extraWorkedDays += 1.0` | priced at `extra_worked_rate`; **does not reduce absence** |
| `V` | `vacantDays++`, `+1.0` absent only under `vacant_treatment: 'absent'` | `block` (default) refuses the slip |

**Payroll ignoring `O`/`H` is correct under the contract below** — but only because it obtains the calendar elsewhere. It is not a defect to fix; it is the half of the design that must now point at the shared authority.

## 6. THE DEFINITIVE CONTRACT

```
        ORGANISATION
             │  named work schedules (weekly offs, nth-weekday, timings, versions)
             ▼
        EMPLOYEE ASSIGNMENT  (effective-dated)
             ▼
   ┌── EMPLOYEE/DATE CALENDAR ──┐     ← ONE authority, upstream of both
   │  scheduled_working          │
   │  scheduled_off              │
   │  holiday                    │
   │  outside_employment_window  │
   └──────────┬─────────┬────────┘
              │         │
   ATTENDANCE │         │ PAYROLL
   classifies │         │ denominator = calendar
   P T M A L  │         │ numerator   = dayWise
   O H W V    │         │
              └────┬────┘
                   ▼
         FROZEN PAYROLL EVIDENCE
   dayWise (what happened) + working_mask (what was scheduled)
   + schedule_id + policy_version + assignment_effective_from
```

**Rules, each backed by evidence:**

1. **The calendar is upstream of Attendance, not downstream.** Proven in §3 — `O` and `W` cannot be produced without it.
2. **Payroll must not own a second calendar.** It must read the same authority. *(Already implemented — `Hr.php` contains 0 occurrences of `satCount`, `date('w'`, or `resolveForMonth`.)*
3. **Payroll cannot take its denominator from `dayWise`.** An employee with no attendance document has no `dayWise`, yet still needs a working-day count — `Hr.php` `else` branch: *"No attendance doc at all — every in-window day is vacant"*.
4. **`O`, `H`, `W` must never be collapsed.** `O` = scheduled off · `H` = holiday · `W` = scheduled off **and worked**. Collapsing any pair destroys extra-worked pay or the holiday/weekly-off audit distinction.
5. **`dayWise` remains the attendance-result record**; `working_mask` carries the calendar dimension alongside it. Neither replaces the other.

## 7. Assumptions — **ONE**

`M` is stated as being set on check-**out** (`:333,346`). I read the two `_allow(STATUS_HALF)` sites and the surrounding hours comparison, but did not trace the full check-out request path end-to-end. Everything else in §1–§6 is read directly from the cited lines.

**Not assumed:** every status origin in §1, the `O` preconditions in §2, the `W`→schedule dependency in §3, and payroll's per-status treatment in §5 — all quoted from source.

## 8. What this changes about the plan

| Item | Before this report | Now |
|---|---|---|
| Schedule authority position | "payroll needs it" | **Attendance needs it too — it is upstream of both** |
| N-35 scope | "migrate the finaliser's `weeklyOffs` read" | **also `Staff_attendance.php:206`, or `W` can never be stamped correctly** |
| `A` on a rest day | not identified | **new: `_overlay` cannot correct it — §2** |
| `dayWise` role | ambiguous | **settled: attendance-result record, with `O`/`H` as legacy calendar intrusions** |

## 9. Unresolved decisions — yours

1. **Should `_overlay()` convert a *marked* `A` on a scheduled rest day to `O`?** It repairs the data but overwrites a mark a human or device made. The alternative is to leave attendance untouched and let payroll disregard it via `working_mask` — reinterpreting rather than rewriting. **I recommend reinterpreting**, because rewriting attendance history to fix payroll is the wrong direction of causality; but this is a data-integrity policy call, not mine.
2. **Should `O`/`H` eventually leave `dayWise`** once `working_mask` carries the calendar, or remain as a human-readable convenience? Removing them is a breaking change for every reader; keeping them means two representations of one fact.
3. **Test School schedules, assignments and effective dates** — still required, still never to be inferred.

---

**Nothing modified. No seed, migration, commit, push or deploy. PR0003 and all April evidence untouched. UAT paused at Test 4.**

---

# ADDENDUM A — UI evidence, and a THIRD calendar authority

**Date:** 2026-08-10 · Added after examining the Staff Attendance grid itself
(`application/views/attendance/staff.php`), which §1–§9 had not inspected.

## A.1 The UI confirms the vocabulary is first-class — premise upheld

```js
// staff.php:582
var CYCLE = ['V','P','A','L','T','H','M','W','O'];
```

and the rendered legend (`:452-460`) labels each one:
`P` Present · `A` Absent · `L` Leave · `T` Late · `H` Holiday · `V` Vacant ·
`M` Half-day · **`W` Work-off** · **`O` Weekly-off**

Each has its own CSS colour token (`--att-o`, `--att-w`, `--att-h` …) and its own
cell rule (`:228 .att-cell[data-v="O"]`). **`O`, `W` and `H` are distinct, named,
separately-styled, human-cyclable states in the shipped product.** They are not
internal encodings that may be collapsed — the contract in §6 rule 4 is confirmed
by the UI, not merely asserted from the engine.

## A.2 THE NEW FINDING — the grid has its own calendar, and it is hardcoded

```js
// staff.php:616-622
function dateLockReason(day) {
    if (dayState(day) === 'future') return 'future';
    if (state.sundays && state.sundays.indexOf(day) !== -1) return 'sunday';
    if (state.holidays && state.holidays[day] !== undefined) return 'holiday';
    return null;
}
// :645  'Sundays cannot be marked.'
// :791  state.sundays = res.sundays || [];   ← from Attendance::_get_sundays()
```

`state.sundays` comes from `Attendance::_get_sundays()` — **a hardcoded
`date('w') == 0` scan.** It consults neither `attendancePolicy.weeklyOffs` nor any
work schedule.

**This is not shading.** `dateLockReason()` gates marking:

```css
/* :198-201 — Sundays and Holidays are also locked */
.att-mgrid td.att-col-sunday .att-cell { cursor: not-allowed; opacity: .55; }
```

So there are **three** calendar authorities, not two:

| # | Authority | Consumer | Basis |
|---|---|---|---|
| 1 | `Work_schedule_service::resolveEmployeeCalendar()` | **Payroll** | employee schedule ✅ |
| 2 | `attendancePolicy.weeklyOffs` (tenant root) | finaliser `O` overlay, punch-time `W` | tenant-wide |
| 3 | **`Attendance::_get_sundays()`** | **the marking grid's edit lock** | **hardcoded Sunday** |

### Consequences, stated concretely

- **A Mon–Fri school:** Saturday stays **markable** in the grid (authority 3 sees no
  Sunday) while the finaliser may stamp `O` there (authority 2). The grid invites a
  mark the calendar says shouldn't exist.
- **A school that works Sundays:** Sunday is **hard-locked** — an administrator
  *cannot mark real attendance on a day their staff actually worked*, and no setting
  unlocks it. `W` can never be recorded there by hand.
- This sits **upstream of §2's finding**: authority 3 governs whether an `A` can be
  placed on a rest day at all, and §2 proves `_overlay()` can never repair one once placed.

## A.3 Correction to my own earlier audit

`ZENX_HRMS_LEGACY_CALENDAR_RETIREMENT_AUDIT.md` §2.1 classified `_get_sundays()` as
**RETAIN** — *"a UI helper, not a working-day authority… never decides pay or
attendance status."*

**That classification is wrong.** It does not decide *status*, but it decides
**whether a status may be entered**, which is a functional gate on the attendance
record itself. Reclassified:

| Item | Was | Now |
|---|---|---|
| `Attendance::_get_sundays()` — grid lock (`staff.php:618`) | RETAIN (cosmetic) | **MIGRATE — N-35** |
| `_get_sundays()` — pure column shading, other 2 call sites | RETAIN | RETAIN (unchanged) |

The three callers must be separated: the **lock** consumer moves to the employee
calendar; the **shading** consumers may stay.

## A.4 What this adds to the contract — no change to §6, one addition

§6's diagram is unchanged and confirmed. One arrow was missing:

```
   EMPLOYEE/DATE CALENDAR
        │
        ├──► ATTENDANCE ENGINE   (classifies P T M A W; finaliser stamps O H)
        ├──► ATTENDANCE GRID UI  (decides which cells are markable)   ← NEW
        └──► PAYROLL             (denominator = calendar, numerator = dayWise)
```

**All three consume the one authority. None owns a calendar.** N-35's scope grows
accordingly — it is not only the finaliser and the punch path, it is the grid's lock too.

## A.5 Register update

| ID | Change |
|---|---|
| **N-35** | Scope **widened** — now also `views/attendance/staff.php:618` lock + `Attendance::_get_sundays()` lock caller. Still **MISSING**, still blocks COMPLETE. |
| **N-38** *(new)* | **MISSING** — the grid must be able to *display* `O`/`H` from the employee calendar rather than inferring Sundays client-side, so a Mon–Fri or Sunday-working tenant renders correctly. |

---

**Nothing modified. No code, config, tenant data, seed, migration, commit, push or
deploy. PR0003 and all April evidence untouched. UAT remains paused at Test 4.**
