# PHASE 2 (N-35) — CONSUMER TRACE

**Date:** 2026-08-11 · **Type:** pre-implementation trace · **NO CODE WRITTEN**
**Satisfies the D2 STOP CONDITION:** how each of the three consumers will obtain and use the
employee-specific schedule, documented before implementation begins.

---

## 0. The correction this trace encodes

My earlier framing — *"leave the `A` visible and let payroll reinterpret via `working_mask`"* —
conflated two separate claims:

| Claim | Verdict |
|---|---|
| Do not rewrite genuine historical attendance | **Correct, retained** |
| Payroll repairs a wrong classification afterwards | **WRONG — rejected by D2** |

The operative requirement is upstream: **attendance must resolve the schedule *before* it
classifies**, so an employee who genuinely works a scheduled off-day is recorded `W` at source.
Payroll then *interprets* `W`; it never *repairs* an `A` that should have been `W`.

A `W` that was never stamped cannot be recovered later — the fact that the day was a rest day is
gone from the record. That is why the schedule must sit upstream of the evaluator, not beside payroll.

---

## 1. What every consumer needs

All three need the same thing for a given `(staffId, date)`:

```
staff.department  +  assignments  +  catalogue
        |
        v
Policy_assignment_service::resolve(...)   ->  schedule  |  block code
        |
        v
is this DATE a working day for THIS employee?      (+ the block reason if not resolvable)
```

The resolver already exists (Phase 0) and the data already exists (Phase 1). Phase 2 is entirely
about **who calls it, when, and how the answer is loaded efficiently.**

### 1.1 Loading shape differs per consumer — this is the whole engineering problem

| Consumer | Employees | Dates | Load shape |
|---|---|---|---|
| Finaliser | **many** | whole month | **bulk once**, index by staffId |
| Punch path | **one** | **one** (today) | single lookup, must be cheap — it is on the click path |
| Grid | **many** | whole month | **bulk once**, returned per-staff to the browser |

A naive per-employee resolve inside a loop would be N round-trips. The finaliser and grid must load
the catalogue and **all** assignments once, then resolve in memory — `Policy_assignment_service` is
pure and does no I/O, which is what makes this possible.

---

## 2. CONSUMER 1 — `Staff_attendance_finaliser`

### 2.1 Current

| Line | Code | Problem |
|---|---|---|
| `:109` | `$weeklyOffs = (array) ($policy['weeklyOffs'] ?? [])` | one tenant-wide list |
| `:151` | `_overlay($freshDayWise, $monthKey, $weeklyOffs, $todayISO)` | same list for every employee |
| `:245` | `_overlay(..., array $weeklyOffs, ...)` | signature carries the tenant list |
| `:264` | `in_array(date('D', ...), $weeklyOffs, true)` → `O` | the actual decision |

`run()` iterates employees; `$weeklyOffs` is hoisted **outside** that loop. Every employee gets the
same calendar — the competing-authority defect.

### 2.2 After

- Resolve **per employee**, inside the loop, from a bulk-loaded index.
- `_overlay()` takes a **per-employee non-working day-number set** instead of `$weeklyOffs`.
  Weekday names disappear from this file entirely — the set is already date-resolved.
- Signature becomes `_overlay(string $dayWise, string $monthKey, array $nonWorkingDays, string $todayISO)`.

### 2.3 Behaviour — exactly as D2 specifies

| Day state | Current char | Action |
|---|---|---|
| Scheduled OFF | `V` (unmarked) | → **`O`** |
| Scheduled WORKING | `V` | → **`A`** (unchanged) |
| Scheduled OFF | **`A`** (genuine mark) | **LEAVE AS `A`** — never auto-corrected |
| Scheduled OFF | `W` | **LEAVE AS `W`** — the employee worked |
| Any | `P T M L H` | untouched |

Line `:249`'s existing `if ($ch !== 'V' && $ch !== 'A') continue;` currently *admits* `A` into the
overlay. The `A`-on-a-rest-day branch must be **removed**, not extended — D2 forbids the rewrite,
and a separate explicit correction workflow is the only sanctioned path.

### 2.4 Fail-closed

If an employee cannot be resolved, the finaliser **skips that employee entirely** and records the
block reason. It must not fall back to the tenant list, and it must not mark them `A` on days whose
working status is unknown — marking `A` on an unknown day is exactly the N-23 harm.

---

## 3. CONSUMER 2 — `Staff_attendance.php` (the live punch path)

**Highest risk in Phase 2.** This runs on a real click, from a real device, with a person waiting.

### 3.1 Current

| Line | Code | Role |
|---|---|---|
| `:206` | `$isWeeklyOff = in_array($now->format('D'), $policy['weeklyOffs'])` | **feeds Gate 6b — decides `W`** |
| `:329` | `$todayIsWeeklyOff = ...` | second copy, same tenant list |
| `:646`, `:669` | `$weeklyOffs` loop | a third reader |

`:206` is the critical one. It feeds `Attendance_policy::evaluate()` Gate 6b:

```php
$isWeeklyOff = !empty($context['isWeeklyOff']) || $currentMark === STATUS_WEEKLYOFF;
if ($isHoliday || $isWeeklyOff) {
    if (empty($policy['allowWorkOnOff'])) return $this->_reject(...);
    return $this->_allow($base, STATUS_EXTRA, true);   // stamps W
}
```

**If `isWeeklyOff` is false, the punch falls through to Gate 7 and is recorded `P`/`T`.** The
rest-day fact is lost at source and no later process can recover it — precisely what D2 forbids.

### 3.2 After

Resolve **before** building `$context`, so the evaluator receives a schedule-aware `isWeeklyOff`:

```
punch received
  -> resolve THIS employee's calendar for TODAY        <- NEW, must precede evaluate()
  -> $context['isWeeklyOff'] = resolved non-working
  -> Attendance_policy::evaluate()
  -> Gate 6b: scheduled off + worked  -> W
     Gate 7 : scheduled working       -> P / T / M
```

All three sites (`:206`, `:329`, `:646`) consume one resolved value. `:329` and `:646` become
readers of the same result rather than independent lookups.

### 3.3 Fail-closed — the sensitive judgement

If the employee's schedule cannot be resolved, the punch **must not** be classified `P`/`T` by
default. D2: *"Do NOT allow the punch path to fall through to normal P/T/M/A classification simply
because the employee-specific schedule was not resolved."*

**Refuse the punch with the named blocker**, surfacing the same reason text the Work Schedules panel
shows. The operator-visible consequence is real and must be stated plainly: **an unconfigured
employee cannot punch.** That is the correct trade — a punch recorded against an unknown calendar is
a wrong record that silently becomes wrong pay.

**This is a behaviour change requiring explicit sign-off before implementation**, because it can stop
a working employee from marking attendance. Mitigation: Phase 1's blocked panel already names every
such employee **before** they hit it, so the condition is visible and fixable in advance.

---

## 4. CONSUMER 3 — the Attendance grid (largest change)

### 4.1 Current contract

`Attendance.php:1020, 1087, 1871` each send:

```php
'sundays' => $this->_get_sundays($year, $monthNum)   // e.g. [3,10,17,24]
```

The browser applies that **one array to every row**:

```js
// views/attendance/staff.php:618
if (state.sundays && state.sundays.indexOf(day) !== -1) return 'sunday';
```

and `dateLockReason()` **locks the cell** — `cursor:not-allowed`, toast *"Sundays cannot be marked."*
It is a functional edit gate, not shading (my legacy audit wrongly classified it RETAIN; corrected
in the contract Addendum A).

### 4.2 Why this cannot be a like-for-like swap

Your own tenant already breaks the single-array assumption:

| Department | Non-working |
|---|---|
| Academic | Sun |
| Administration | Sat, Sun |
| Support | *(none)* |

One array cannot express three different answers on the same date. **The contract must become
per-staff:**

```php
'nonWorkingDays' => [ 'STA0003' => [2,9,16,23,30], 'SSA0001' => [1,2,8,9,...], ... ]
'scheduleBlocked' => [ 'STA0099' => 'no_assignment', ... ]
```

### 4.3 Browser changes

- `dateLockReason(day)` → `dateLockReason(staffId, day)`
- Lock/shade resolved **per row**, not per column — the current per-column CSS
  (`td.att-col-sunday`) cannot express it and must move to the cell
- A staff member whose schedule cannot be resolved: **whole row locked**, with the reason on hover —
  not silently editable

### 4.4 Cost

Three endpoints, one view, and a column→cell CSS change on the screen your staff use daily.
Sequenced last for that reason.

---

## 5. Test plan — the 15 mandated acceptance tests

| # | Test | Where |
|---|---|---|
| 1 | Scheduled off + no work → `O` | finaliser |
| 2 | Scheduled off + punch → `W` | punch |
| 3 | Working day + punch → `P`/`T`/`M` | punch |
| 4 | Working day + no attendance → `A` | finaliser |
| 5 | Two employees, same Sunday, different results | both |
| 6 | Employee A: Sunday off + works → `W` | punch |
| 7 | Employee B: Sunday working + works → normal, **not** `W` | punch |
| 8 | **Resolution precedes classification** | source-order guard on the punch path |
| 9 | No hardcoded Sun/Sat/2nd-4th-Sat logic | source guard, comment-stripped |
| 10 | No tenant-wide fallback | source guard — 0 × `policy['weeklyOffs']` in migrated paths |
| 11 | No silent default schedule | block-code tests |
| 12 | Payroll + Attendance resolve through the same authority | single-call-site guard |
| 13 | Historical records not rewritten | `_overlay` leaves `A` untouched on rest days |
| 14 | `W` semantics intact | `O`/`H`/`W` remain distinct |
| 15 | Grid returns per-staff non-working days | shape assertion on the endpoint |

Test 8 is the structural one: it asserts the resolve call appears **before** `evaluate()` in source
order — the same ordering discipline used for `findOverlaps()` before the write in Phase 1.

---

## 6. Implementation order — unchanged

1. **Finaliser** — batch, no user waiting, safest to verify
2. **Punch path** — single employee, bounded, **needs sign-off on §3.3 first**
3. **Grid** — contract change, most exposure

---

## 7. Blocking questions before code

1. **§3.3 — refusing punches for unconfigured employees.** Confirmed? It can stop a real employee
   marking attendance. The alternative (allow the punch, flag it) reintroduces classification against
   an unknown calendar, which D2 forbids — so I am not proposing it, only naming the consequence.
2. **Support's 7-day schedule.** If it stays, `O` will never be produced for Vipul Tiwari and every
   day is a working day. Correct only if genuinely continuous duty.
3. **Assignment dates start 2026-08-11.** The finaliser processes historical months; those months
   resolve to a block, not a calendar. Confirm whether assignments should be back-dated to
   `2026-04-01` — and only if that pattern was truly in force then.

---

**NOTHING IMPLEMENTED. No code, config, tenant data, seed, migration, commit, push or deploy.
No schedules created, no Test-School configuration altered. PR0003 and April evidence untouched.**
