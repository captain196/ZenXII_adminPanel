# Calendar ↔ Mobile Apps — Integration Contract

**Handoff to the Teacher and Parent app releases. Nothing in this repository can
implement it.**

---

## Why this is a handoff and not a task

Both apps read the timetable **directly from Firestore with the client SDK**:

```kotlin
// SchoolSyncTeacher/…/repository/firestore/TimetableFirestoreRepository.kt
firestoreService.queryDocumentsAs<TimetableDoc>(Constants.Firestore.TIMETABLES) { ref ->
    ref.whereEqualTo("schoolId", schoolCode)
       .whereEqualTo("sectionKey", sectionKey)
}
```

There is **no endpoint in this repository** between the app and the data, so no
server-side change can alter what they display. The same is true of the Parent
app. Closing this needs an app release.

---

## The contract

```
timetables/{school}_{session}_{sectionKey}_{WEEKDAY}   ← the NORMAL schedule
                    +
student calendar resolution for the DISPLAYED DATE     ← whether it operates
                    =
        what the user should be shown for that date
```

`timetables` is keyed by **weekday**, not by date. There is no per-date document
and there must not be one: deleting or altering a document to "suppress a
holiday" would remove that subject from *every* Monday. The apps must therefore
compose, never mutate.

### What the apps need for a date

| field | meaning | app behaviour |
|---|---|---|
| `instruction` | regular teaching runs | `true` → show the timetable normally |
| | | `false` → still show the schedule if useful, but clearly labelled *not operating today* |
| | | `null` → unknown; show the timetable **as today**, no claim either way |
| `reason` | `holiday` / `weeklyOff` / `nthWeekdayOff` / `specialWorkingDay` / `examOnly` / `outsideSession` / `working` | drives the label |
| `halfDay` | modifier, **not** a closure | show the schedule; annotate as a half day |
| `source` | `configured` / `legacy` / `unreadable` | `unreadable` must never render as "no classes" |

**Do not invent attendance rules in the app.** `attendance` is a separate
dimension from `instruction` — on an exam-only day `instruction` is false while
`attendance` is true.

### Two ways to deliver it

**Option 1 — server endpoint (recommended).** A read-only endpoint in this
repository returning the resolution for a date range, backed by the existing
`Student_calendar_reader::resolveDate()`. One authority, no duplicated logic,
and the apps cannot drift from the admin panel.

Suggested shape:

```
POST /student_calendar/operational_dates   { from: ISO, to: ISO }
→ { status: 'success',
    dates: { '2026-10-20': { instruction: false, reason: 'holiday',
                             halfDay: false, label: 'Diwali' }, … },
    source: 'configured' }
```

This does not exist yet. It is ~40 lines against the existing reader and is the
smallest safe piece of work if the apps are to be fixed.

**Option 2 — client-side resolution.** The apps read
`attendanceSettings/{schoolId}.studentCalendar.versions[]` plus `calendarEvents`
and resolve locally. **Not recommended**: it recreates the resolver in Kotlin
twice, and this programme has already found six independent week authorities in
one codebase. A seventh and eighth in two app repositories would be worse.

Note also that `attendanceSettings` currently has **no `match` block** in
`firestore.rules`, so it falls through to the terminal `allow read, write: if
false`. Option 2 would additionally require a new read rule — i.e. it widens the
security surface, where Option 1 does not.

---

## Repositories and files

| repo | file |
|---|---|
| `SchoolSyncTeacher` | `app/src/main/java/com/schoolsync/teacher/data/repository/firestore/TimetableFirestoreRepository.kt` |
| | `app/src/main/java/com/schoolsync/teacher/util/Constants.kt` (`TIMETABLES`) |
| `SchoolSyncParent` | `app/src/main/java/com/schoolsync/parent/data/repository/firestore/TimetableFirestoreRepository.kt` |
| | `app/src/main/java/com/schoolsync/parent/util/Constants.kt` (`TIMETABLES`) |
| this repo | `application/libraries/Student_calendar_reader.php` (`resolveDate()`) — the authority to call |

---

## What must not happen

- **Do not** write per-date timetable documents.
- **Do not** delete or edit `timetables` documents to represent a closure.
- **Do not** reimplement the weekly-pattern or nth-weekday maths in Kotlin
  without an agreement test against the PHP resolver.
- **Do not** render `source: 'unreadable'` as "no classes today". A calendar that
  could not be read is not a holiday.
