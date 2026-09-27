# Calendar Programme — Phase 1 Repository Truth

**Re-verified from source on 2026-09-01. Where this contradicts any earlier
report — including my own — this document wins.**

Branch `integration/phase2`, HEAD `a1801aa`.
Fingerprints at verification time:

| file | md5 |
|---|---|
| `Timetable_plan_store.php` | `c435c8089eeb…` (TT-C8, untouched) |
| `Working_calendar_service.php` | `b9cec156864b…` |
| `Holiday_service.php` | `c9dc217bd633…` |
| `Calendar_service.php` | `2382bcb8b25c…` |
| `Attendance.php` | `1b433e7dc4de…` |
| `attendance_helper.php` | `5f4f07e5cabb…` |
| `firebase-rules/firestore.rules` | `18561da5bbf2…` |

Three stashes exist (`phase2-untracked-docs-noise`, two on `ank-yug_b1`).
**Not touched.**

---

## 1. VERIFIED — the staff authority is live

| claim | verdict | evidence |
|---|---|---|
| `Working_calendar_service` is live, not retired | **TRUE** | `resolveFromVersion()` + `REASON_*` called from `Hr.php`, `Work_schedule_service.php:405`, `Staff_attendance.php` |
| The retired thing is the old controller/storage path | **TRUE** | `Working_week.php` guarded dead at N-36; writes `attendancePolicy.workingWeek`, read by no runtime consumer |
| Live chain | **TRUE** | `Work_schedules` → `Policy_assignment_service` (`staff > department > organisation > BLOCK`) → `Work_schedule_service` → `Working_calendar_service::resolveFromVersion()` |
| Effective dating exists and is shared | **TRUE** | `application/helpers/effective_dating_helper.php` — `ed_select_for()` (latest `effectiveFrom` ≤ date, respecting `effectiveTo`) **and `ed_find_overlaps()`**, already used by compensation *and* workforce |
| nth-weekday patterns exist | **TRUE** | `isNthWeekdayOff()`, `validateVersion()` accepts `nthWeekdayOffs[{day, weeks[]}]`, weeks 1–5 |
| Unconfigured ⇒ blocked | **TRUE** | `resolveFromVersion(null, …)` returns `configured=false, blocked=true`, every day `MASK_OUTSIDE` |

`resolveFromVersion()` is **pure**: `(version, year, month, holidays, inWindow,
swd, halfDays, weight) → mask`. It holds no staff state — the staff *authority*
is `Policy_assignment_service` (whose schedule applies to whom), not this maths.

---

## 2. VERIFIED — the student side, and a correction

### Two hardcoded Sunday sites, not one

| # | site | consumers |
|---|---|---|
| 1 | `attendance_helper.php:38` — `nw_days_from_holidays()` | via `_resolve_non_working_days()`: `Attendance.php` 1219, 1516, 1633, 5528, 7109, 8054 |
| 2 | **`Attendance.php:5673` — `_get_sundays()`** | `Attendance.php` 1094, 1162 — **a second, independent Sunday computation** |

[REPO] My earlier report named only site 1. Site 2 is a separate implementation
feeding a `'sundays'` key into two view payloads. **This is a duplicate
student-side authority** and must collapse into the new resolver, or the UI and
the marking gate can disagree.

### The eligibility gate

`Attendance.php:1516` (`mark_student_day`) and `:1633` (`bulk_mark_student`):

```php
if ($mark !== 'H' && isset($this->_resolve_non_working_days($monthNum, $year)[$day])) {
    return $this->json_error("Day {$day} is a holiday/Sunday. Cannot mark as {$mark}.");
}
```

One boolean. No weekly pattern, no session window, no instructional concept.

---

## 3. CORRECTED — CAL-B1 was overstated

My previous report called this a *"NEW defect (HIGH): the student holiday read
fails OPEN"*. **That is wrong, and the repository already reasoned about it.**

[REPO] What actually exists:

1. `Holiday_service::_query_firestore()` reads via **`schoolWhereOrFail`**
   (ACAD-D17) — the silent-`[]` class was already fixed at source, so the catch
   is reachable.
2. On failure it **deliberately fails open** — *"Fail open for punch flows — a
   calendar-read failure must never break a punch"* — but records
   `_loadedOk = false`.
3. `load_ok()` exposes that, and `Staff_attendance_finaliser:103` **fails
   closed** on it.
4. `Attendance::_holiday_calendar_readable()` (ACAD-D18) exposes it on the
   student side, and `Attendance::save()` uses it at `:8152` — logging loudly
   that *"the holiday gate was NOT evaluated and marking was allowed to
   proceed"*.
5. The source explicitly records the fail-open/fail-closed choice as **"an
   operational policy decision, not a code one… recorded for the owner rather
   than taken here."**

**Restated finding — CAL-B1 (MEDIUM, not HIGH): guard coverage is inconsistent.**
ACAD-D18's readability guard is applied in `Attendance::save()` but **not** in
`mark_student_day()` (`:1516`) or `bulk_mark_student()` (`:1633`). Those two
entry points fail open with **no log at all**, so the same event is loud on one
path and silent on two others. The *policy* is settled; the *coverage* is not.

This is the "unconfigured ≠ broken / study the whole feature" lesson. I reported
a defect where a deliberate, documented decision already existed.

---

## 4. RE-VERIFIED — CAL-D1 stands

Independently re-checked; `firestore.rules` md5 unchanged since the last report.

| collection | LIVE baseline `34e7a058` | working file / HEAD | verdict |
|---|---|---|---|
| `calendarEvents` | `write: if false` | `write: if isAdmin()` | **REGRESSED — unbound** |
| `subjects` | `write: if false` | `write: if isAdmin()` | **REGRESSED — unbound** |
| `exams` | `write: if false` | `write: if isAdmin()` | **REGRESSED — unbound** |
| `examSchedule` | `write: if false` | `write: if isAdmin()` | **REGRESSED — unbound** |
| `appraisals` | `write: if false` | `write: if isAdmin()` | **REGRESSED — unbound** |
| `sections`, `subjectAssignments` | `write: if false` | tenant-bound `isSameSchoolWrite()` | acceptable evolution |
| `timetables` | `write: if false` | `write: if false` | agrees |

`isAdmin()` binds the **caller's** tenant, never the **written document**.
Not remediated — **awaiting separate authorisation** (§9 of the brief).

---

## 5. VERIFIED — precedence, derived from behaviour not assumption

[REPO] Today's actual student precedence, read off `_resolve_non_working_days()`:

```
nonWorking  = Sundays  ∪  holidays(appliesTo: students)
nonWorking −= specialWorkingDays(appliesTo: students)      ← SWD wins over BOTH
```

and `Working_calendar_service` independently agrees for staff:
`REASON_SPECIAL_WORKING_DAY` overrides, and `MASK_HALF` *"counts toward
workingCount exactly like MASK_WORKING"* — **a half day is a working day**, not
a partial closure.

So the proven precedence, consistent across both domains, is:

> `outsideSession` > `specialWorkingDay` > `holiday` > `weeklyOff` > `working`,
> with `halfDay` a **modifier on a working day**, never a closure.

This was derived, not assumed, and it is what the new resolver implements.

---

## 6. VERIFIED — exception ledger and its audience model

- `Calendar_service` is the **only** writer of `calendarEvents`
  (`saveEvent`/`deleteEvent`); `VALID_TYPES` = `holiday, exam, meeting, event,
  activity, special_working_day, half_day`.
- `Holiday_service` is the **only** runtime reader, and reads **only**
  `holiday`, `special_working_day`, `half_day` — so **`exam` events currently
  have zero effect on attendance.** An exam day is a normal working day today.
- Audience filtering is real: `appliesTo`, `departments`, `exceptStaff`;
  the student path passes `'students'` and never passes department/staff.
- `status == 'cancelled'` events are skipped.

**Consequence for design:** wiring `exam` into the resolver would *change
behaviour*. It is therefore modelled but left unwired.

---

## 7. Consumer census — every decider of a student date

| consumer | question it answers | current source |
|---|---|---|
| `Attendance::mark_student_day` `:1516` | may this mark be saved? | `_resolve_non_working_days` |
| `Attendance::bulk_mark_student` `:1633` | may these marks be saved? | `_resolve_non_working_days` |
| `Attendance::save` `:8152` | may today's marks be saved? | `_is_holiday` + readability guard |
| `Attendance` `:1219, :5528, :7109, :8054` | grid/report shading, % denominators | `_resolve_non_working_days` |
| `Attendance` `:1094, :1162` | `'sundays'` view payload | **`_get_sundays()` — duplicate** |
| `Attendance::_staff_grid_schedule` `:2039` | staff expectation | `Policy_assignment_service` — **staff domain, out of scope** |
| `Exam::_holiday_dates` `:498` | avoid scheduling on holidays | `Holiday_service::all_holiday_dates()` (no audience arg ⇒ defaults `'staff'`) |
| `Staff_attendance_finaliser:103` | may auto-absent run? | `load_ok()` — **fails closed** |
| `Timetable_plan_service::coversDate()` | which version covers a date | `timetableVersions` — **date range only, no calendar awareness** |

**[REPO] Note on `Exam::_holiday_dates`:** it calls `all_holiday_dates()` with no
audience, which defaults to `'staff'`. Exam scheduling therefore avoids
*staff* holidays, not *student* holidays. Recorded as **CAL-D9 (MEDIUM)** —
out of scope for this phase, not fixed.

---

## 8. NO AUTHORITATIVE SOURCE EXISTS

Unchanged from discovery, re-verified:

- *Is the school open on date X?* — **NO SOURCE**
- *Is date X an instructional day?* — **NO SOURCE**
- *Is date X a student teaching day?* — **NO SOURCE**

And *"is date X a student attendance day?"* has a source, but it is a private
controller method backed by a hardcoded literal, duplicated in two places.
