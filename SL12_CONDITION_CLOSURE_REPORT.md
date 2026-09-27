# SL-12 Condition Closure Report

**Date:** 2026-08-13 · **Branch:** `integration/phase2` · **Type:** Investigation only — zero code
changes, zero data changes, zero commits, zero pushes.

This closes out the three deferred conditions from
`STUDENT_LIFECYCLE_SL12_CANONICAL_CLASS_SECTION_IMPLEMENTATION_REPORT.md` §27/§34-35. It does not
redo the earlier audit or the earlier implementation.

---

## Condition 1 — Historical Class/Section Drift

**Method:** read-only Firebase Admin SDK scan against the live production Firestore project
(same in-repo service-account credential used in earlier phases, `graderadmin-firebase-adminsdk-…`,
invoked from the session scratchpad, zero writes — `.set()`/`.update()`/`.delete()` never called).

### 1. Exact affected collections
`sections` and `examSchedule` were scanned in full (every document, not a sample). No other
collection embeds a class/section-derived docId or `className` field that this fix touches
(`students`/`parents` were already always-canonical — confirmed separately, see below).

### 2/3. Exact affected schools/tenants and document counts

| Collection | Total docs (all tenants) | Non-canonical `className` found |
|---|---|---|
| `sections` | 126 | **0** |
| `examSchedule` | 18 | **0** |
| `students` (`className` field only, all 14 schools, 161 students) | 161 | **0** |

Distinct schools with `sections` documents: `SCH_218AAF5C23`, `SCH_B56BB9A401`, `SCH_B9161BBE3C`,
`SCH_D94FE8F7AD` (4 of the platform's 14 `schools` docs — the other 10 tenants have zero `sections`
documents, i.e. nothing to drift). Sample real values, unedited: `"Class 10th"`, `"Class 1st"`,
`"Class 2nd"`, `"Class 3rd"` … `"Class 9th"` — every ordinal is already correctly suffixed.

### 4/5. Current vs expected representation
**Identical.** Every live `className` value already matches
`Entity_firestore_sync::normalizeClassSection()`'s canonical output exactly (verified by running the
same ordinal-suffix algorithm against every scanned value and comparing byte-for-byte).

### 6. Actively consumed?
Yes — these are the live `sections`/`examSchedule` documents for the platform's actual active
tenants, read by `Classes.php`, `Fees.php`'s section-existence check, and exam-schedule views.

### 7. Can the current SL-12 implementation safely read them?
Yes — trivially, since they were already in the exact form the fixed `classKey()`/`sectionKey()`
now also produce. No compatibility gap exists.

### 8. Is a backfill actually necessary?
**No.** The theoretical drift scenario described in the prior report's §27 (a school that created a
class by typing a bare number like `"9"` with no ordinal) does not exist anywhere in the current
live dataset. In practice, every observed class was created via the admin UI's dropdown, which
always supplies an already-ordinal-suffixed string (`"9th"`, not `"9"`) — so the pre-fix
`classKey()`'s naive prefix-add and the canonical ordinal algorithm happened to always agree in
every real write that has ever occurred on this platform.

### 9. Should any historical records intentionally remain unchanged?
N/A — there is nothing to change; all records already match canonical form.

### 10/11. Backfill risk / idempotency
N/A — no backfill is warranted. (Had drift existed, a `className`-field-only rewrite — not a docId
migration — would have been the safe, idempotent approach: re-run `classKey()` against the stored
value and skip any doc where the result is unchanged, so re-running the script twice is a no-op.
This is documented for completeness, not as a plan to execute.)

### 12. Recommended repair strategy
**None required.** Close this condition as-is.

**Condition 1 verdict: CLOSED. NO ACTION REQUIRED. NO DATA BACKFILL NEEDED.**

---

## Condition 2 — Parent App `classKey()`

### Implementation found
`Constants.Firebase.classKey()`/`sectionKey()` — `SchoolSyncParent/app/src/main/java/com/schoolsync/parent/util/Constants.kt:198-203`:
```kotlin
fun classKey(className: String): String =
    if (className.startsWith("Class ", ignoreCase = true)) className else "Class $className"
fun sectionKey(section: String): String =
    if (section.startsWith("Section ", ignoreCase = true)) section else "Section $section"
```
Naive prefix-add — the same algorithm the PHP side had **before** the SL-12 fix. Does **not**
apply ordinal-suffix normalization.

### Every caller (traced by grep across the full Parent App source tree)

| Caller | File | Input source | Output use |
|---|---|---|---|
| `getTimetable()` | `TimetableFirestoreRepository.kt:47` | `TimetableViewModel.kt:177` passes `user.className`/`user.section` from `tokenManager.user` | Firestore **query** filter |
| `HomeworkFirestoreRepository` (2 sites) | `HomeworkFirestoreRepository.kt:80,323` | traced to `tokenManager.user.className`/`section` (student's own cached profile) | Firestore query/docId |
| `submitLeave()` | `LeaveFirestoreRepository.kt:67-69` | `LeaveViewModel.kt:115,253` — `state.className` set from `tokenManager.user.className` (`loadStudentInfo()`, line 111) | Firestore **write** (`leaveApplications` doc) |
| `getSection()`/docId builders | `SectionFirestoreRepository.kt:88,107,128` | not traced further this pass — same repository-layer pattern as the above (all take `className`/`section` params, never raw free-text) | Firestore query/docId |
| `getExamSchedule()` | `ExamFirestoreRepository.kt:71` | same pattern | docId |
| `getMyTeachers()` | `MyTeachersFirestoreRepository.kt:77` | same pattern | query |
| `getFeeStructure()` | `FeeFirestoreRepository.kt:59` | same pattern | docId |
| `getLessonPlans()` | `LessonPlanParentRepository.kt:135` | same pattern | query |
| `getStudentsByClass()` | `StudentFirestoreRepository.kt:60,69` | **zero callers anywhere in the app** (confirmed by grep — only its own definition matches) | **dead code** |
| `HomeworkViewModel.kt:312` | direct call, not via a repository wrapper | `user.className`/`user.section` (`tokenManager.user`) | builds a `sectionKey` string for a homework filter |

### Input source — the decisive fact
Every live call site's `className`/`section` argument traces back to `tokenManager.user` — the
locally cached copy of the logged-in student's own Firestore `students` document, populated at
login by `AuthRepository`. That document's `className`/`section` fields were written by
`Entity_firestore_sync::syncStudent()` on the PHP side, which — **independent of and unaffected by
the SL-12 fix, both before and after it** — has *always* forced every student write through
`normalizeClassSection()` (confirmed in the original SL-12 report §1/§9). So the string this
function receives is never free-typed, never a raw dropdown value, and never anything other than
an already-canonical, already-ordinal-suffixed round-trip of what the PHP side wrote.

Given `classKey("Class 9th")` → already starts with `"Class "` → **returned unchanged** (the naive
algorithm's guard only fires when the prefix is *absent* — it never re-parses or re-suffixes an
already-prefixed string), the algorithmic difference from the canonical PHP version is **never
actually exercised** by any live input in this codebase.

### Can the helper actually receive a non-canonical value?
Not through any currently-reachable code path. The only way to reach it would be a future feature
that passes a free-typed or dropdown-raw class string directly into one of these repositories
without going through `tokenManager.user` first — no such caller exists today.

### Is output used for Firestore queries or only UI/display?
**Both** — `getTimetable`, `getStudentsByClass` (dead), `MyTeachersFirestoreRepository`,
`LessonPlanParentRepository` build **query filters**; `LeaveFirestoreRepository.submitLeave()`
performs a **write**; several build **docIds** (`SectionFirestoreRepository`,
`ExamFirestoreRepository`, `FeeFirestoreRepository`). None are purely cosmetic/UI-only.

### Comparison with `Entity_firestore_sync::normalizeClassSection()`
Algorithmically different (no ordinal-suffix handling, no `classOrder`/`sectionCode` output) —
but functionally equivalent **for every input this function is ever actually called with today**,
because that input is always already-canonical.

### Classification: **C — INERT / NO ACTION REQUIRED** (today)

This is the same "wired but inert" pattern already established in this program's Phase 8.4 finding
for the Parent App school-code bootstrap edge case — a real algorithmic divergence exists, but the
live call graph never feeds it an input where the divergence would produce a different output.

It is **not** classified B (future risk) as a *current* live concern requiring urgent scheduling,
but it is worth naming precisely: if a future Parent App feature ever passes a raw/free-typed class
string (bypassing `tokenManager.user`) into any of these repositories, the divergence would become
live at that moment. This is a **latent inconsistency**, already partially known within the
codebase itself — `StorySharedConfig.kt` (both Parent and Teacher repos, lines 26-32) explicitly
documents "the two apps' Constants.classKey differ (teacher adds an ordinal … parent does not)"
and works around it with an independent reducer for the Stories feature specifically. That existing
comment is the strongest evidence this is a known, previously-scoped-around condition, not a new
discovery created by SL-12.

### Identity invariants in the Parent App
- **Student ID:** `tokenManager.user.userId` — untouched by `classKey()`/`sectionKey()`, which take
  no identity parameter and are never used to construct a student-identity key (only class/section
  composite keys and docIds for class-scoped collections). Confirmed by reading every call site's
  full argument list.
- **SRNO:** not referenced anywhere in `Constants.Firebase` or any of the traced call sites —
  SRNO is not a Parent App concept surfaced through this code path.
- **Roll Number semantics:** `rollNo` is a separate field, never passed into `classKey()`/
  `sectionKey()`; unaffected.
- **School/tenant isolation:** every traced call site separately supplies `schoolCode`/`schoolId`
  as an independent Firestore filter (e.g. `StudentFirestoreRepository.kt:68`,
  `TimetableFirestoreRepository.kt` requires `user.schoolCode`) — `classKey()`/`sectionKey()`
  themselves carry no school-scoping and don't need to; isolation is enforced by the separate
  `schoolId`/`schoolCode` filter present in every one of these queries.

**No Parent App file was modified in this investigation.**

**If a future change is authorized:** the smallest fix would be porting the Teacher App's own
`classKey()` (`SchoolSyncTeacher/.../util/Constants.kt:133-141`, which already implements the
ordinal-suffix algorithm — Teacher's own code comment states it exists specifically "to match
Firestore docs written by the admin panel") into the Parent App's `Constants.kt:198-203`, replacing
the naive version. That would also let `StorySharedConfig.kt`'s explicit divergence-workaround
comment be revisited (out of scope for that decision here — flagged only).

**Condition 2 verdict: NO ACTION REQUIRED (today). FUTURE RISK noted and documented, not urgent.**

---

## Condition 3 — `Fee_management.php::_normalizeClassSection()`

### Line-by-line comparison

```php
// Fee_management.php:262-280
private function _normalizeClassSection($class, $section)
{
    $class = trim($class);
    if (stripos($class, 'Class ') !== 0) {
        $class = 'Class ' . $class;                          // (A) no ordinal handling
    }
    $section = trim($section);
    if (stripos($section, 'Section ') !== 0) {
        $section = 'Section ' . strtoupper($section);         // (B) forces uppercase on add
    }
    if (preg_match('/[.\/\#\$\[\]]/', $class . $section)) {
        $this->json_error('Invalid class/section value.');    // (C) side-effecting validation
    }
    return [$class, $section];
}
```
vs. `Entity_firestore_sync::normalizeClassSection()` (Entity_firestore_sync.php:164-210): strips
ordinal suffixes and *re-derives* a canonical one via `ordinalSuffix()`/`CLASS_ORDER_MAP`, never
uppercases section input, is a pure function (no I/O, no error-raising side effect), and returns 4
keys (`className`, `section`, `classOrder`, `sectionCode`) rather than a 2-element array.

### 1. Semantically identical?
**No.** Three concrete differences:
- **(A)** No ordinal-suffix normalization — same class of gap the pre-fix `Firestore_service::classKey()` had.
- **(B)** Force-uppercases the section token *only* when the "Section " prefix was absent
  (`strtoupper($section)`), which the canonical function never does — a caller passing lowercase
  `"a"` gets `"Section A"` here but would get `"Section a"` (raw casing preserved) from the
  canonical function.
- **(C)** Embeds request-terminating validation (`json_error()` — writes an HTTP response and,
  per this codebase's established `json_error` semantics, halts execution) directly inside what is
  otherwise a pure formatter — a side effect the canonical function never has.

### 2. How they differ
Covered above (A/B/C).

### 3. Why does Fee_management have its own implementation?
Not documented in-file beyond a two-line docblock ("Parse class string into [classNode,
sectionNode]"). No comment references `Firestore_service::classKey()` or
`Entity_firestore_sync::normalizeClassSection()`, suggesting it was written independently rather
than as a deliberate divergence. No evidence of an intentional reason for the difference.

### 4. Every caller (4, all inside `Fee_management.php`)
| Line | Context | Use of the [class, section] output |
|---|---|---|
| 1018 | `apply_discount`-style flow (bulk student discount from posted JSON) | builds `$csKey` (see below) to look up `feeStructures` for the percentage-discount base amount |
| 1352 | scholarship-award amount calc (percentage type) | same `$csKey`-lookup pattern |
| 1392 | scholarship-award write (`studentDiscounts` update) | `$classNode`/`$sectionNode` computed but **not written to any doc field** in the code read — used only transiently |
| 1533 | scholarship-award **removal** | same transient-only pattern |

### 5. What data it reads/writes
It does not itself perform any Firestore I/O — it's a pure string transform (plus the
validation side effect). Its *callers* subsequently read `feeStructures` (via a derived `$csKey`)
and read/write `studentDiscounts`/`scholarshipAwards` (using `$userId`/`$awardId`, not the
class/section value, as the doc key) — the normalized class/section is not itself embedded in
those docIds.

### 6. Does it participate in Firestore class/section *queries*?
Indirectly: all 4 callers immediately strip the very prefixes this function just added
(`str_ireplace(['Class ', 'Section '], '', $class)`) to build `$csKey = "{bareClass}_{bareSection}"`
for a `feeStructures` docId lookup (`Fee_management.php:1030-1031`, `1353`). **Net effect on the
actual lookup key: the ordinal-suffix question is moot** — because the prefix is added and then
immediately stripped back off, the resulting `$csKey` is determined entirely by whatever raw
ordinal/non-ordinal form the *caller's own input* already had, not by this function's algorithm.
The uppercasing in (B) is the only difference that survives into `$csKey` (since `str_ireplace`
strips "Section " but not the casing change already applied to the remainder).

### 7. Can it currently produce non-canonical data?
Not written to `students`/`sections`/`examSchedule` — so it cannot desync those collections. It
*can* theoretically produce a `feeStructures` lookup key with different section-casing than
`Fee_firestore_sync::syncFeeStructure()` (the actual canonical writer of per-class-section fee
charts, `Fees.php:1442`) would produce for the same logical section, **if** a caller ever passes a
lowercase, unprefixed section letter through this specific Fee_management path while the original
chart was saved through the Fees.php UI (which typically sends already-uppercase section letters
from a dropdown). Not confirmed as a live bug this pass — flagged as a narrow, low-probability,
pre-existing edge case, unrelated to and not introduced by SL-12.

**Directly relevant corroborating finding (not itself part of Condition 3, disclosed for
completeness):** `Fee_firestore_sync::_ensureClassPrefix()`/`_ensureSectionPrefix()`
(`Fee_firestore_sync.php:64-83`) — the actual writer behind `Fees.php`'s per-class-section fee
chart save — implements the **same naive, non-ordinal algorithm** as Fee_management's private
helper. This is a **fifth** parallel implementation in the codebase (alongside the now-fixed
`Firestore_service`, the dead `Firestore_helper`, `Fee_management`'s own, and canonical
`Entity_firestore_sync`). Because `Fee_management` and `Fee_firestore_sync` happen to use the
*same* naive algorithm as each other, fee-structure lookups between them stay mutually consistent
even though both diverge from the ordinal-canonical form used by `students`/`sections`/SL-12-fixed
`Firestore_service::sectionDocId()`. This is a real, separate, out-of-scope finding — not
something Condition 3 asked to be fixed, flagged for a future pass.

### 8. Would removing/consolidating it be safe?
Not "safely, today, without further work" — because of difference (B) (section uppercasing) and
(C) (the embedded `json_error()` validation side effect), a naive swap to
`Entity_firestore_sync::normalizeClassSection()` would (i) change behavior for lowercase section
input (a real behavior change, not just an internal refactor) and (ii) drop the path-injection
character validation unless it were preserved separately at each call site. Consolidation is
*feasible* but is not a zero-risk one-line change the way the original SL-12
`Firestore_service::classKey()` fix was.

### 9. Is it intentionally different for a legitimate reason?
No evidence of intent found — reads as an independently-written duplicate, not a deliberate
divergence for a documented business reason.

**Condition 3 verdict: NEEDS IMPLEMENTATION (if consolidated) — but classified NO ACTION REQUIRED
for this phase**, because (a) it is not proven to be a live bug (its output's ordinal-suffix
question is moot for its actual `feeStructures` lookup use, per §6), and (b) consolidating it
safely requires more than a delegation one-liner (the uppercasing behavior change + the embedded
validation must be explicitly preserved). Recommended smallest safe plan, **not executed**:
1. Keep `_normalizeClassSection()`'s signature and its `json_error()` validation exactly as-is.
2. Internally, replace only the prefix-add lines (A)/(B) with a call to
   `Entity_firestore_sync::normalizeClassSection($class, $section)`, then explicitly re-apply
   `strtoupper()` to the section result **only if** preserving that behavior is confirmed desired
   (needs a decision, not assumed here) — or drop the uppercasing deliberately if it's judged to be
   an unintentional inconsistency.
3. Re-run `Fee_management.php`'s callers manually (no automated test currently covers this file)
   before considering it closed.

---

## Cross-Module Safety Check (regression check only — not a re-audit)

Verified via source re-read that none of the three conditions' *investigation* introduced any
change (no file under `application/`, `SchoolSyncParent/`, or `SchoolSyncTeacher/` was edited this
pass — confirmed by `git status` showing no new modifications beyond this report and the original
SL-12 report/test file from the prior phase).

| Surface | Regression risk from Conditions 1-3 | Basis |
|---|---|---|
| SIS / Attendance / Classes / Homework / Timetable / Subject Assignment / Lesson Plan / Substitute / Gate Pass / Student 360 / Transport | None — no code changed this pass | `git status` |
| Fees | None — `Fee_management.php` was read, not edited | `git status` |
| Parent App (profile, current class/section, student association) | None — no file edited; Condition 2 confirms existing behavior is inert, not broken | source read |
| Teacher App (roster, attendance, marks, homework, timetable, Roll Number) | None — not touched this pass; Teacher App's own `classKey()` was read only for comparison | source read |

---

## Identity Invariants — Reconfirmed

- **Student ID:** not referenced by any code inspected in Conditions 1-3.
- **SRNO:** not referenced by any code inspected in Conditions 1-3.
- **Roll Number:** not referenced by any code inspected in Conditions 1-3; remains class/section
  scoped, never global, never conflated with Student ID or SRNO.
- School A `STU0011`/SRNO `1033` and School B `STU0021`/SRNO `1033` scenario: unaffected — none of
  the three conditions' code paths perform any cross-school read/write or SRNO resolution.

---

## Summary Table

| Condition | Classification | Data change needed? | Code change needed? |
|---|---|---|---|
| 1 — Historical drift | **CLOSED / NO ACTION REQUIRED** | No — 0 drifted documents found across 126 `sections` + 18 `examSchedule` + 161 `students.className` (all 14 schools) | No |
| 2 — Parent App `classKey()` | **NO ACTION REQUIRED (today) / FUTURE RISK (documented)** | No | Not required now; a defined, small future change exists if ever authorized |
| 3 — `Fee_management` private normalizer | **NO ACTION REQUIRED (this phase)** — consolidation is a defined future NEEDS IMPLEMENTATION item, not urgent | No | Not this phase; smallest-safe plan documented above if later authorized |

---

## Final Verdict

# READY FOR NEXT PHASE

All three conditions resolve to **safe/inert** on current evidence, not real live defects:

- Condition 1's theoretical drift does not exist in production — confirmed by a full, not
  sampled, read-only scan of every `sections` and `examSchedule` document plus every student's
  `className` across all 14 tenants.
- Condition 2's algorithmic divergence is real but never reachable by any live Parent App input
  today — every call site's class/section value is sourced from an already-canonical Firestore
  round-trip, not free text.
- Condition 3's private normalizer differs from canonical, but its own downstream use (a
  `feeStructures` lookup key that strips the very prefix it added) makes the ordinal-suffix
  question moot in practice; the one behavior difference that does survive (section casing) is a
  narrow, unconfirmed, pre-existing edge case, not caused by SL-12.

No condition requires a backfill, a Parent App change, or a Fee_management change to be made
*right now*. SL-12 itself (the original `Firestore_service::classKey()`/`sectionKey()` fix) can be
considered **closed** on its own merits — nothing discovered in this investigation weakens that
verdict, and nothing here blocks moving to the next phase.

**Nothing was committed, pushed, or modified in this investigation beyond this report and the
two read-only, zero-write scratchpad scripts used for Condition 1 (outside the repo, in the
session temp directory — not part of the codebase).**
