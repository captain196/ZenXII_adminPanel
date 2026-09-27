# ZenX Calendar — ERP Comparison Study

Companion to the Calendar Discovery report. Research only; no code, no design
commitment. Every row is labelled **MARKET** (external documentation),
**REPO** (traced in this repository) or **INFERENCE**.

Systems studied, chosen to span three different architectural families:

| system | family | why included |
|---|---|---|
| **PowerSchool SIS** | US district SIS | the most explicit published calendar-day model |
| **Infinite Campus** | US district SIS | the only one that separates school / instructional / attendance day |
| **ERPNext / Frappe** | open-source ERP (HR-derived) | closest structural analogue to ZenX's problem: weekly-off + exception list |
| **Indian school ERPs** (edumerge, e-Saraswati, Fedena class) | regional school ERP | the actual market ZenX competes in |
| **ZenX** | — | subject |

---

## 1. The single most important difference

Every mature system models **the shape of a school week as data**. ZenX models
it as a literal.

```php
// ZenX — application/helpers/attendance_helper.php            [REPO]
if ((int) date('w', mktime(0,0,0,$monthNum,$d,$year)) === 0) {
    $nonWorking[$d] = 'Sunday';
}
```

- **PowerSchool** [MARKET]: every in-session day carries a **Cycle Day**, a
  **Bell Schedule**, a **School In-Session** flag and a **Membership Value**.
  A holiday is *In Session unchecked + Membership 0*. Crucially: *"If you have
  a day marked In Session and the Membership Value set to 0, you will not be
  able to take attendance for the student."* Attendance-takeability is
  **derived from the calendar**, not from a weekday number.
- **Infinite Campus** [MARKET]: the Days tab defines *"whether a day counts as
  a school, instructional or attendance day"* — **three independent flags**.
- **ERPNext** [MARKET]: a *Holiday List* has From/To dates and a **Weekly Off**
  selector; pressing *Add to Holidays* **materialises** every matching date as
  a holiday row. *"Several weekly offs can also be defined (e.g. both Saturday
  and Sunday)."* Assignable at company level with per-employee override.
- **Indian school ERPs** [MARKET]: school week is a configurable **4, 5 or 6
  days**, with *"weekend days automatically calculated based on the school week
  selected"*.
- **ZenX** [REPO]: no weekly pattern exists anywhere for students. A five-day
  school must declare every Saturday individually, for ever.

---

## 2. Feature-by-feature comparison

| capability | PowerSchool | Infinite Campus | ERPNext | Indian ERPs | **ZenX** |
|---|---|---|---|---|---|
| School week configurable | yes (cycle days) | yes | yes (weekly off, multi) | yes (4/5/6-day) | **NO — Sunday hardcoded** |
| Per-date calendar record | yes | yes | yes (materialised) | yes | **NO — computed per read** |
| "School open" vs "instructional" vs "attendance" separated | 2 flags (In Session + Membership) | **3 flags** | 1 | 1 | **1** |
| Holiday exception ledger | yes | yes (Day Events) | yes | yes | **YES — `calendarEvents`** |
| Exception applicability scoping | tracks | calendar/track | company/employee | limited | **YES — `appliesTo`, `departments`, `exceptStaff`** |
| Special / compensatory working day | via day type | via day event | via comp-off | yes | **YES — `special_working_day`, overrides Sunday** |
| Half day | limited | yes | yes (issue-tracked) | yes | **YES — `half_day`** |
| Closure reasons (weather/emergency/remote) | day type | **yes, typed** | no | yes | partial (free-text title) |
| Instructional minutes / compliance counting | yes | **yes** | n/a | CBSE 220-day tracking | **NO** |
| Multi-track / per-grade calendars | **yes (Tracks)** | yes | n/a | rare | **NO** (single-campus tenant) |
| Year rollover / copy calendar | **Calendar Wizard** | **Copy function** | copy list | yes | **NO — deliberately not carried** |
| Public-holiday import/suggestion | district-seeded | district-seeded | manual | some | **NO** |
| Calendar feeds timetable | bell schedule ↔ calendar | period schedule per day | n/a | *"timetable uses the holiday calendar"* | **NO — timetable is holiday-blind** |
| Calendar feeds attendance | yes | yes | yes | *"skip non-working days"* | **YES** |
| Calendar feeds payroll | n/a | n/a | **yes** | yes | **YES** |
| Staff vs student calendars separated | same calendar | same calendar | HR-only | usually merged | **YES — genuinely separate** |

---

## 3. Where ZenX is *ahead* of the field

Worth stating plainly, because the discovery brief warns against copying
another product's architecture.

1. **Staff and students already resolve through different authorities**
   [REPO]. Staff go through `Policy_assignment_service` + work-schedule
   catalogue (`staff > department > organisation > BLOCK`), effective-dated,
   timezone-aware, with a leave overlay. Students go through
   `Attendance::_resolve_non_working_days()`. PowerSchool, Infinite Campus and
   the Indian ERPs generally run **one** calendar for the institution and bolt
   staff attendance onto it. ZenX's separation is the *better* model and the
   discovery brief is right to protect it.
2. **Exception applicability is richer than the SIS norm** [REPO].
   `appliesTo` (students/staff), `departments`, `exceptStaff` means ZenX can
   express *"Teacher Training Day — staff only, Science dept, except these two
   people"*. PowerSchool expresses roughly *"this track, this day"*.
3. **One canonical reader** [REPO]. `Holiday_service` is *"the single,
   reusable Firestore abstraction… One place — and only one — answers 'is date
   X a holiday'"*, consumed by Attendance, Exam, HR/Payroll, Staff attendance,
   its finaliser and overlay. Many ERPs re-implement holiday checks per module.

**So the correct framing is not "ZenX is missing a Calendar".** ZenX has a good
exception ledger and a good audience model, and is missing the *other half* of
every mature design: the recurring weekly pattern, and the day-type
vocabulary that sits on top of it.

---

## 4. Where ZenX is behind, ranked by real consequence

| # | gap | market evidence | ZenX consequence [REPO] |
|---|---|---|---|
| 1 | **No weekly pattern** | all four families model it | a 5-day school cannot be represented; Saturday is always a teaching day |
| 2 | **No year-rollover path** | PowerSchool Calendar Wizard; IC Copy; ERPNext weekly-off generator | `rollover_academic_session.php` explicitly *"Does NOT carry: calendarEvents (holidays change year-over-year)"*. Combined with gap 1, **a new session begins with Sundays off and nothing else** — every holiday re-entered by hand before attendance is correct |
| 3 | **One flag, not three** | IC separates school / instructional / attendance day; PowerSchool separates In Session from Membership | ZenX cannot express "school open, no classes" (exam-only day, teacher training with students absent) |
| 4 | **No instructional-day counting** | IC instructional minutes; CBSE 220-day rule | ZenX cannot answer "are we compliant on teaching days?" |
| 5 | **Timetable is holiday-blind** | edumerge: *"the timetable module uses the holiday calendar"* | `coversDate()` answers date-range only; a published timetable "applies" on a declared holiday |
| 6 | **No public-holiday seeding** | district-seeded in US SIS | every school starts from an empty list |

---

## 5. What the market says about configurability — the decisive quote

> *"There is no single official CBSE holiday list; schools customise it as per
> local needs."* [MARKET]

Restricted/optional holidays are *"observed by some schools depending on
region"*; state festivals differ materially (Pongal draws 3–4 days in Tamil
Nadu and Andhra Pradesh).

**Therefore** holiday observance is genuinely a per-school decision, and ZenX's
"the school enters its own events" model is **correct** — it should not be
replaced by an authoritative government feed. A public-holiday list belongs in
ZenX only as an **import suggestion**, never as automatic truth.

The same test applied to the weekly pattern: 4/5/6-day school weeks are a
standard configurable field in this market [MARKET], and CBSE-affiliated
schools genuinely differ. So the weekly pattern **passes** the ZenX
configuration principle — it is real operational variation, not flexibility for
its own sake.

By contrast, *instructional minutes per period* is a US-compliance concept with
no evidenced Indian requirement — it would fail the test today.

---

## 6. Two architectures the market actually uses

**Pattern A — materialised calendar days** (PowerSchool, Infinite Campus,
ERPNext). Generate one row per date per calendar, then read it. Strengths:
every day is inspectable and auditable; a per-day override is trivial;
historical days are frozen by construction. Costs: generation step, rollover
tooling, storage per tenant per year.

**Pattern B — rule + exception, evaluated at read** (ZenX today, minus the
rule). Strengths: no generation, no storage, no drift between rule and rows.
Costs: history is only as stable as the rule — **changing the weekly pattern
retroactively changes what past dates meant**, which is precisely the class of
hazard TT-C8 spent its life eliminating for timetables.

[INFERENCE] That last point is the strongest architectural argument in this
study: ZenX has just finished proving, at length, that *derived-at-read-time
history is dangerous when the inputs are mutable*. If a weekly pattern is
added as a plain mutable setting, editing it in March silently rewrites what
January's attendance days were. The mature systems avoid this either by
materialising days (A) or — as ZenX's own workforce layer already does — by
**effective-dating the rule** so past periods resolve against the version that
was in force.

---

## 7. What this study changes about the recommendation

The Discovery report recommended *"weekly pattern + existing exception ledger"*.
This study **sharpens it in three ways**:

1. The weekly pattern must be **effective-dated**, not a plain setting —
   otherwise it reintroduces the retroactive-history defect class by design.
   ZenX already has this pattern in `Policy_assignment_service`; the student
   side should mirror the *shape* without sharing the *authority*.
2. A **day-type vocabulary** is worth more than it first appears. Infinite
   Campus's three flags are what let a school say "open but not teaching".
   ZenX's single non-working flag cannot, and exam days and training days are
   exactly where that bites.
3. **Rollover tooling is not optional.** Every comparable system ships it.
   ZenX's deliberate decision not to carry `calendarEvents` is defensible only
   if something else fills the gap; today nothing does.

None of this is authorised for implementation. It is the evidence a design
decision should rest on.
