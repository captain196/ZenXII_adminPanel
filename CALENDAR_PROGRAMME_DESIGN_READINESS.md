# ZenX Calendar Programme — Discovery, Authority Map & Design Readiness

**Phase: DISCOVERY + DESIGN READINESS. No production code written. Nothing
staged, committed, or deployed.**

Branch `integration/phase2`. HEAD at start and end: `a1801aa`.
TT-C8 fingerprint `Timetable_plan_store.php` = `c435c8089eeb…` — **unchanged**;
TT-C8 was not opened, modified, or committed.

Labels: **[REPO]** traced here · **[MARKET]** external documentation ·
**[INFERENCE]** reasoning · **[DESIGN]** recommendation.

---

## 0. THREE CORRECTIONS TO MY OWN PRIOR REPORTS

The brief required me to challenge previous reports. Three were wrong.

### C-1. "`Working_calendar_service` is RETIRED (N-36), no runtime consumer" — **WRONG**

[REPO] The **controller** `Working_week.php` is retired (N-36); the **library**
is not. `Working_calendar_service.php` is 20 KB, self-described as *"THE
canonical working-week authority (N-23/P-021)"*, and its `resolveFromVersion()`
plus `REASON_*` constants are called at runtime from `Hr.php` (payroll, LOP,
leave-balance), `Work_schedule_service.php:405`, `Staff_attendance.php` and
`Attendance.php`. What retired is the *storage location*
(`attendancePolicy.workingWeek`), superseded by `Work_schedules.php` →
`Policy_assignment_service`. **The calendar maths is live and canonical.**

### C-2. "ZenX has no weekly pattern" — **WRONG at system level**

[REPO] ZenX has a weekly-pattern engine *more expressive than ERPNext or
PowerSchool*: `weeklyOffs`, **nth-weekday patterns** ("2nd and 4th Saturday
off" — a normal Indian school week), a 7-value day-reason vocabulary
(`working`, `weeklyOff`, `nthWeekdayOff`, `holiday`, `outsideWindow`,
`specialWorkingDay`, `halfDay`), a positional `working_mask`, and a deliberate
**no-default / fail-closed** stance: *"A tenant with no configured policy
resolves to `configured => false`, and payroll must BLOCK… the legacy engine
happened to assume Sunday + 2nd/4th Saturday, but that was an implementation
accident, not a product decision."*

The correct statement is narrower and sharper:

> **ZenX has a mature weekly-pattern authority. It serves STAFF only. The
> student side has none, and hardcodes `date('w') === 0`.**

### C-3. "ZenX has no day-type vocabulary" — **WRONG**

[REPO] `Calendar_service::VALID_TYPES` is already
`holiday | exam | meeting | event | activity | special_working_day | half_day`,
and `Working_calendar_service` already keeps weekly-off and holiday distinct
deliberately (*"Weekly-off and holiday stay DISTINCT (§9)"*).

**Net effect:** this is not a build-from-scratch. It is **extending a proven
in-repo pattern to a second, separate domain** — far smaller and far safer than
the comparison study implied.

---

## DELIVERABLE A — Current Calendar Authority Map

| Question | Current authority | Readers | Writers | Data source | Historical safety | Conflict risk |
|---|---|---|---|---|---|---|
| Is staff expected to work on date X? | `Policy_assignment_service` → `Work_schedule_service` → `Working_calendar_service::resolveFromVersion()` | Hr (payroll/LOP/leave), Staff_attendance (punch, finaliser, grid), Attendance staff grid | `Work_schedules.php` | `workSchedules` + assignments | **GOOD** — effective dating on the assignment layer; fail-closed BLOCK | LOW — `staff > department > organisation > BLOCK`; ties structurally impossible |
| Is date X a holiday? | `Holiday_service` | Attendance, Hr, Staff_attendance, Exam, Leave, payroll | `Calendar_service` only | `calendarEvents` type=holiday | ADEQUATE | LOW — one reader, one writer |
| Special working day / half day? | `Holiday_service::is_special_working_day()` / `is_half_day()` | staff grid + student register | `Calendar_service` | `calendarEvents` | ADEQUATE | LOW |
| **Is date X a student attendance day?** | **`Attendance::_resolve_non_working_days()`** — a *private controller method* | Attendance only | — | hardcoded `date('w')===0` + `calendarEvents` | **POOR** — no pattern, no versioning | **MEDIUM** |
| **Is date X a student TEACHING day?** | **NO AUTHORITATIVE SOURCE EXISTS** | — | — | — | — | — |
| **Is the school OPEN on date X?** | **NO AUTHORITATIVE SOURCE EXISTS** | — | — | — | — | — |
| **Is date X an instructional day?** | **NO AUTHORITATIVE SOURCE EXISTS** | — | — | — | — | — |
| Which timetable version covers date X? | `Timetable_plan_service::coversDate()` (TT-C8) | resolver, projection, Teacher/Parent apps | `Timetable_plan_store` | `timetableVersions` | **EXCELLENT** — proven historical invariance | NONE — one-in-force proven |
| Academic session dates? | `schools.sessions[]` + `schoolControl.currentSession` | session convergence | School config | `schools` | ADEQUATE | LOW |

**Three explicit NO-AUTHORITY findings. They are the programme.**

---

## DELIVERABLE B — Student Attendance Deep Audit

### The complete resolution path [REPO]

```
Attendance::_resolve_non_working_days(monthNum, year)
  ├─ Holiday_service::holidays_in_month(y, m, 'students')             → $holidayDayMap
  ├─ Holiday_service::special_working_days_in_month(y, m, 'students') → $swdDayMap
  ├─ nw_days_from_holidays($holidayDayMap, m, y)     [attendance_helper.php]
  │     └─ for each day: if ((int) date('w', …) === 0) → 'Sunday'     ← THE HARDCODE
  │        then overlay holidays
  └─ foreach ($swdDayMap as $day) unset($nonWorking[$day])            ← SWD wins
```

Call sites: `Attendance.php` lines 1219, 1516, 1633, 5528, 7109, 8054.

### The eligibility gate [REPO] — `Attendance.php:1516` and `:1633`

```php
if ($mark !== 'H' && isset($this->_resolve_non_working_days($monthNum, $year)[$day])) {
    return $this->json_error("Day {$day} is a holiday/Sunday. Cannot mark as {$mark}.");
}
```

"Can attendance be taken?" **is** "is the day in the non-working map?" — one
boolean. PowerSchool's In-Session/Membership separation has no equivalent.

### Hidden assumptions, stated

1. **Sunday is the only weekly off, for every tenant, for ever.** A 5-day school
   cannot be expressed; neither can 2nd/4th-Saturday-off — *even though the
   staff engine models exactly that pattern.*
2. **No effective dating.** The rule is a *constant*, which is currently the only
   thing saving it from the retroactive-rewrite hazard. The moment it becomes
   configurable and mutable, every historical attendance percentage destabilises.
3. **Server-local time** — the same assumption TT-C8 documented.
4. **No "outside the session window" concept.** `Working_calendar_service` has
   `REASON_OUTSIDE`; the student side does not.

### Does it accidentally resemble staff attendance? **NO — this is done well** [REPO]

The student path passes an explicit `'students'` audience, and the source states
its own boundary:

> *"Student Attendance must never inherit a staff-only calendar event (e.g. a
> Teacher Training Day, `appliesTo=['staff']`) as a student holiday, and
> department/exceptStaff scoping is a staff-only concept the student side must
> never apply."*

The staff grid uses a separate `_staff_grid_schedule()`. Only the *precedence
idea* (SWD overrides) is shared, and that is deliberate and documented.

### NEW DEFECT — **CAL-B1 (HIGH): the student holiday read fails OPEN**

[REPO] `Attendance.php:5708-5710`:

```php
} catch (\Throwable $e) {
    log_message('error', '…HC-3 failed: ' . $e->getMessage());
    $holidayDayMap = [];          // ← every holiday silently disappears
}
```

On any Firestore failure the non-working map degrades to **Sundays only**:
staff can mark P/A on a declared holiday and the register renders it as a normal
working day. This is the *opposite* stance to the staff engine, which BLOCKS
when unconfigured, and it is the silent-`[]` Firestore read class already
recorded as ACAD-D1..D12. **Not fixed — reported only**, per the standing "do
not silently fix unrelated findings" instruction.

---

## DELIVERABLE C — Staff vs Student Domain Boundary

```
        STAFF WORK SCHEDULE AUTHORITY        ≠     STUDENT SCHOOL/TEACHING DAY AUTHORITY
        ─────────────────────────────              ──────────────────────────────────────
Q       "Is THIS EMPLOYEE expected to work?"       "Is the SCHOOL operating for students?"
Grain   per employee                               per school (per session)
Owner   Work_schedules → Policy_assignment_service ** DOES NOT EXIST **
Maths   Working_calendar_service::resolveFromVersion()
Effect  effective-dated on the assignment          n/a
Unconf. BLOCK (fail-closed)                        silently "Sundays only" (fail-OPEN)
Scoping department, exceptStaff, per-staff         MUST NOT apply any of these
```

### Every actual interaction point [REPO]

| # | Point | Nature | Verdict |
|---|---|---|---|
| 1 | `calendarEvents` read by both | shared DATA, separated by `appliesTo` | CORRECT — one ledger, audience-filtered |
| 2 | `Holiday_service` serves both | shared READER, audience parameter mandatory | CORRECT |
| 3 | `Attendance.php` hosts both grids | same controller, **different private methods** | ACCEPTABLE — no logic shared |
| 4 | SWD-overrides-holiday precedence | same *rule*, independently implemented | ACCEPTABLE, documented |
| 5 | Staff schedules → student attendance | **none** | **BOUNDARY INTACT** |

**STOP 3 NOT TRIGGERED.** The separation is real, deliberate and commented in
the source. The recommended design deliberately gives students their *own*
authority rather than widening the staff one.

---

## DELIVERABLE D — Calendar Gap Analysis

| id | severity | finding | evidence | real consequence |
|---|---|---|---|---|
| **CAL-D1** | **CRITICAL** | working `firestore.rules` **regresses 5 deployed SEC-3 Wave 4 tenant denials** | Deliverable E | deploying it re-opens cross-tenant writes on `calendarEvents`, `subjects`, `exams`, `examSchedule`, `appraisals` |
| **CAL-B1** | **HIGH** | student holiday read fails OPEN | `Attendance.php:5708` | attendance markable on a declared holiday during any Firestore blip |
| **CAL-D2** | **HIGH** | no student weekly-pattern authority | `attendance_helper.php:38` | a 5-day school is unrepresentable; every Saturday declared by hand for ever |
| **CAL-D3** | **HIGH** | no "school open vs teaching day" distinction | eligibility gate is one boolean | exam-only, training and event days cannot be modelled |
| **CAL-D4** | MEDIUM | no calendar rollover path | `rollover_academic_session.php:20-21` | a new session starts with **Sundays only**; every holiday re-entered manually |
| **CAL-D5** | MEDIUM | `Calendar_events_verify` type enum is **stale** (missing `special_working_day`, `half_day`) | controller vs `Calendar_service::VALID_TYPES` | the verifier reports valid events as schema violations — a "verify the verifier" instance |
| **CAL-D6** | MEDIUM | no student `REASON_OUTSIDE` equivalent | — | dates outside the session window treated as ordinary working days |
| **CAL-D7** | LOW | `_resolve_non_working_days()` re-invoked inline per mark | `:1516`, `:1633` | redundant work; mitigated by the 300 s cache |
| **CAL-D8** | LOW | no public-holiday seeding | — | every tenant starts from an empty list |
| OOS | — | instructional-minutes / membership-value compliance | [MARKET] US-specific | **OUT OF SCOPE** — fails the configurability test; no evidenced Indian requirement |
| OOS | — | multi-track / multi-campus calendars | — | **OUT OF SCOPE** — ZenX is single-campus per tenant |

---

## DELIVERABLE E — CAL-D1 Security Verdict: **CONFIRMED, AND INVERTED**

The original finding was *"`calendarEvents` write lacks tenant binding"*. True of
the working file — but the important discovery is **which file is wrong**.

### Evidence [REPO]

| source | `calendarEvents` write arm |
|---|---|
| `firestore.rules` (working copy, deploy candidate) | `allow write: if isAdmin();` |
| `firestore.rules` @ git HEAD | `allow write: if isAdmin();` |
| `firestore.rules.PREDEPLOY_BACKUP` | `allow write: if isAdmin();` |
| **`firestore.rules.LIVE_BASELINE_34e7a058`** | **`allow write: if false;`** + the SEC-3 wave4 comment |

`isAdmin()` verifies the **caller's own** tenant is active and that they hold an
admin label/capability. It never constrains the **written document**. The correct
primitive sits 20 lines away — `isSameSchoolWrite()` requires
`request.resource.data.schoolId == request.auth.token.school_id`.

### The full regression surface (precise write-arm diff)

| collection | LIVE baseline | working file | verdict |
|---|---|---|---|
| `calendarEvents` | `write: if false` | `write: if isAdmin()` | **REGRESSED — unbound** |
| `subjects` | `write: if false` | `write: if isAdmin()` | **REGRESSED — unbound** |
| `exams` | `write: if false` | `write: if isAdmin()` | **REGRESSED — unbound** |
| `examSchedule` | `write: if false` | `write: if isAdmin()` | **REGRESSED — unbound** |
| `appraisals` | `write: if false` | `write: if isAdmin()` | **REGRESSED — unbound** |
| `sections` | `write: if false` | `create,update: isAdmin() && isSameSchoolWrite()`; `delete: isAdmin() && isSameSchool()` | changed but **tenant-bound** — a later, defensible evolution |
| `subjectAssignments` | `write: if false` | same tenant-bound shape | **tenant-bound** |
| `timetables` | `write: if false` | `write: if false` | SAME (TT-D1 agrees with live) |

### Verdict

- **Real?** In the working file, **yes**. In production, **apparently not** — the
  live baseline carries the fix.
- **Exploitability:** an authenticated user holding an admin label/capability in
  school A can create, overwrite **or delete** a `calendarEvents` document
  carrying `schoolId: SCH_B`. Neither the body nor the `{schoolId}_{eventId}`
  doc-id is checked. Reachable from any Firebase client SDK or the REST API with
  that token — it does not require the admin panel.
- **Blast radius:** `calendarEvents` → `Holiday_service` → **student attendance**
  (mark eligibility), **staff attendance** (punch/finaliser/grid), **HR/Payroll**
  (`REASON_HOLIDAY` feeds working-day counts, therefore salary and LOP),
  **Exam**, **Leave**. Injecting or deleting a holiday in another tenant is a
  money-affecting cross-tenant write.
- **The operative risk today is the reverse of the original framing:**
  **deploying the current working `firestore.rules` would REVERT five deployed
  security denials.** TT-C8's handoff step 3 is *"Deploy `firestore.rules`"* —
  executing it from this working tree would ship the regression.

### Smallest correct fix

Restore `allow write: if false;` (plus the SEC-3 wave4 comment) on
`calendarEvents`, `subjects`, `exams`, `examSchedule`, `appraisals`. Five
one-line reverts, matching the ruleset already in production. **Not applied** —
this file is concurrently edited by other programmes and CLAUDE.md requires a
pull-first reconcile.

### Test strategy — already written

`firebase-rules/tests/sec3_wave4_academic_writes.test.js` **already exists** and
already asserts the correct boundary for all eight collections, including
cross-tenant and unauthenticated cases. It is differential by design (*"only
meaningful if it FAILS against the unfixed live ruleset"*). **No new test is
needed — the existing suite must be run against the working file, where it
should currently fail.**

---

## DELIVERABLE F — ERP Comparison Addendum (only what changed)

| capability | prior claim | corrected [REPO] |
|---|---|---|
| School week configurable | "NO — Sunday hardcoded" | **staff: YES, richer than ERPNext (nth-weekday). students: NO.** |
| Day-type vocabulary | "one flag" | **staff: 7 reasons. `calendarEvents`: 7 types. students: one boolean.** |

[MARKET] The nth-weekday capability is decisive: ERPNext's Weekly Off is a flat
weekday list and **cannot** express "2nd and 4th Saturday off", which
`Working_calendar_service::isNthWeekdayOff()` handles natively. For the Indian
market this puts ZenX's staff engine ahead of the open-source comparator and
level with the US SIS cycle-day model. **The student design should reuse this
engine's shape, not invent a weaker one.**

---

## DELIVERABLE G — Candidate Domain Model

**Canonical answer to "what does date X mean for students in school Y?":**

> A new **`Student_calendar_service`** — a single, pure resolver returning one
> classification per date, composed from (1) an effective-dated school-week
> pattern and (2) the existing `calendarEvents` ledger filtered to
> `appliesTo: students`.

```
resolveForDate(schoolId, session, date) → {
    open:        bool      // is the school operating at all
    instruction: bool      // are normal classes running
    attendance:  bool      // may student attendance be marked
    halfDay:     bool
    reason:      enum      // working | weeklyOff | nthWeekdayOff | holiday
                           // | specialWorkingDay | halfDay | outsideSession
                           // | examOnly | eventNoClasses
    versionId:   string    // WHICH policy version decided this
    configured:  bool      // false ⇒ caller must decide, never guess
}
```

**Three flags, not fifty.** Not copied from Infinite Campus — they are the three
the repository already needs and cannot express: `attendance` is today's
boolean; `open` is what an exam-only day requires; `instruction` is what the
timetable will need. Everything else is `reason` metadata, and `reason`
deliberately **reuses `Working_calendar_service`'s existing token names** so both
domains stay legible to one operator without sharing an authority.

| concern | decision | why |
|---|---|---|
| weekly pattern | new `schools.studentCalendar.versions[]`, **structurally identical** to the staff version shape | reuses a proven model; separate document ⇒ separate authority |
| effective dating | `ed_select_for()` — **the same helper** | one effective-dating implementation, not two |
| pattern maths | call `Working_calendar_service::resolveFromVersion()` as a **pure function** | it takes a version and returns a mask; it holds no staff state. Reusing pure maths is not a domain merge |
| exceptions | **existing `calendarEvents`, unchanged** | already audience-scoped, one writer, one reader |
| day types | extend `Calendar_service::VALID_TYPES` only if a need survives the configurability test | `exam` and `event` already exist |
| unconfigured tenant | **`configured => false`**, caller decides | mirrors the staff engine's honesty; open decision #1 |
| history | past dates resolve against the version in force then; versions **append-only, never edited** | the TT-C8 lesson, and the `Working_week` precedent |

### Why not the alternatives

- **A. Materialised calendar days** — REJECTED. One doc per date per tenant per
  session is ~250 writes/tenant/year plus a generation step, a rollover tool and
  a rule-vs-rows drift class. ZenX has no such infrastructure and its own
  precedent (staff) is rule-based.
- **B. Mutable rule evaluated at read** — REJECTED. The retroactive
  history-rewrite hazard: editing the pattern in March silently changes what
  January's attendance percentages meant.
- **C. Effective-dated rule + exception ledger** — **RECOMMENDED.** History safe
  because versions are append-only and selection is by date; storage O(versions)
  not O(days); matches the staff engine, so ZenX has one architecture explained
  twice rather than two competing ones.

---

## DELIVERABLE H — Integration Map

| consumer | owns the decision? | consumes | must never duplicate |
|---|---|---|---|
| **Student attendance** | NO | `attendance` + `halfDay` + `reason` | the Sunday computation — `nw_days_from_holidays()` becomes a thin adapter or is retired |
| **Timetable (TT-C8)** | NO — **must stay separate** | `instruction` | `coversDate()` answers *"which version covers this date"* and must keep answering only that. The runtime question is `coversDate(...) && instruction`, evaluated **by the caller**, not inside either authority |
| **Exams** | NO | `open` | its own holiday query |
| **Academic sessions** | supplies the window | session start/end → `outsideSession` | its own date maths |
| **Reporting / % calculations** | NO | `attendance` + `versionId` | the denominator must come from the resolver and record which version produced it |
| **HR / Payroll** | **NO INTEGRATION** | — | must continue through `Policy_assignment_service`. **This is the boundary; nothing here changes it.** |
| **`Holiday_service`** | remains the canonical exception reader | — | the new service *calls* it; it does not re-query `calendarEvents` |

---

## DELIVERABLE I — UI/UX Blueprint

1. **Initial setup** — a "School Week" step alongside session dates: pick working
   weekdays, optionally mark nth-weekday exceptions ("2nd & 4th Saturday off").
   `Work_schedules` is the visual precedent; **reuse the interaction pattern,
   not the screen.**
2. **Calendar view** — a month grid, each day carrying its `reason` colour and a
   plain-language label. The grid must show *why*: "Weekly off",
   "Holiday — Diwali", "Special working day", "Half day".
3. **Changing the pattern** — the effective date is mandatory and **cannot be
   back-dated past the last finalised attendance month**. Show an explicit impact
   line: *"Applies from 1 Jan 2027. 143 past dates are unaffected."* A past-dated
   attempt is refused, not warned.
4. **Rollover** — carry the **pattern** forward; do **not** carry dated holidays;
   present last year's holidays as an unchecked review list the admin confirms.
   This preserves the existing deliberate rollover decision while closing CAL-D4.
5. **Attendance UX** — the register states the resolver's answer, never asks the
   user to infer it: *"Attendance unavailable — school holiday (Diwali)"*,
   *"Half-day schedule"*, *"Special working day — attendance required"*.
6. **Unconfigured tenant** — a banner naming the exact screen to fix it, per the
   staff engine's *"the block is a prompt rather than a dead end"*.
7. **Government holidays** — at most a *suggestion* list the admin ticks into
   `calendarEvents`. Never authoritative, never automatic, never a background
   import. [MARKET] *"There is no single official CBSE holiday list; schools
   customise it as per local needs."*

---

## DELIVERABLE J — Implementation Plan (NOT AUTHORISED)

| phase | scope | files | risk | tests | stop condition |
|---|---|---|---|---|---|
| **0. SECURITY** | restore 5 SEC-3 write denials | `firebase-rules/firestore.rules` | **shared file — pull-first mandatory** | run the existing `sec3_wave4` suite | if another programme's in-flight hunks cannot be separated → STOP |
| **1. Fail-closed read** | CAL-B1 — student holiday read fails loud | `Attendance.php` | MEDIUM — could block marking during an outage | unit: read failure ⇒ refusal, not open | if any tenant depends on degraded operation → STOP |
| **2. Resolver, dark** | `Student_calendar_service` + version storage; **zero consumers** | new library + tests | LOW — nothing reads it | exhaustive consecutive-date property proofs, per TT-C8 discipline | — |
| **3. Admin surface** | school-week configuration + calendar view | new controller/views + routes | LOW | reachability verified in the browser | — |
| **4. Attendance cutover** | `_resolve_non_working_days()` delegates to the resolver | `Attendance.php`, `attendance_helper.php` | **HIGH — changes marking eligibility** | differential: unconfigured tenant resolves **identically to today** | if any historical percentage moves → STOP |
| **5. Rollover** | carry pattern, review holidays | `rollover_academic_session.php` | MEDIUM | prove the previous session is never rewritten | — |
| **6. Timetable awareness** | callers combine `coversDate()` && `instruction` | callers only — **TT-C8 untouched** | MEDIUM | — | if it requires editing `Timetable_plan_service` → STOP |

Migration: phase 4's contract is that a tenant with **no** configured pattern
resolves exactly as today (Sundays + holidays), so no tenant changes behaviour
until an administrator states a policy. That is what makes this shippable
without a data migration — and it is open decision #1.

---

## FINAL DECISION GATE

**A. What ZenX has today** — a mature, effective-dated, fail-closed,
nth-weekday-capable working-week engine for **staff**; one canonical
audience-scoped exception ledger (`calendarEvents`) with a single writer
(`Calendar_service`) and a single reader (`Holiday_service`); a proven
one-version-in-force timetable authority (TT-C8); and clean, deliberate,
source-documented staff/student domain separation.

**B. What ZenX is missing** — any authority at all for "is date X a school /
teaching / attendance day for **students**". Three questions have **no source**.

**C. What must be preserved** — the staff/student boundary; `Holiday_service` as
sole exception reader; `Calendar_service` as sole writer; TT-C8's `coversDate()`
semantics; tenant isolation; append-only history.

**D. Recommended architecture** — **Option C**: effective-dated student week
pattern + existing exception ledger, exposed through one new
`Student_calendar_service` returning `open` / `instruction` / `attendance`.
It reuses a proven in-repo model, keeps history safe by construction, costs
O(versions) not O(days), and gives ZenX one architecture explained twice instead
of two competing ones.

**E. Rejected** — materialised days (cost + generation + drift, no precedent);
mutable read-time rule (retroactive rewrite); extending the staff authority to
students (STOP 3); copying Infinite Campus's flag set wholesale (fails the
configurability test).

**F. Migration** — none required; unconfigured tenants resolve exactly as today.

**G. Security** — **CAL-D1 CONFIRMED and inverted**: the deployed ruleset is
correct; the *working file* regresses five denials. Deploying it as-is is the
live risk, and it sits directly in TT-C8's deployment path.

**H. Phases** — 0 → 6 above, security first.

**I. Decisions required from Vikrant**

1. **Unconfigured tenant:** resolve as today (Sundays + holidays — safe
   rollout), or fail-closed like payroll (honest, but blocks marking until
   configured)? *Recommendation: as-today at phase 4, with a persistent prompt.*
2. **Retroactive edits:** hard-refuse any effective date on or before the last
   finalised attendance month, or allow with an audit trail?
   *Recommendation: hard-refuse.*
3. **Day-type scope:** ship `open`/`instruction`/`attendance` now, or start with
   `attendance` only? *Recommendation: build all three in the resolver, consume
   only `attendance` in phase 4 — the cost is in the model, not the flags.*
