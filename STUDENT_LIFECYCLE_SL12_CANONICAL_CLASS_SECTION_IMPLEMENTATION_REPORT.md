# SL-12 — Canonical Class/Section Normalization: Implementation Report

**Date:** 2026-08-13 · **Branch:** `integration/phase2` · **Scope:** SL-12 only, per explicit authorization.

---

## 1. Executive Summary

SL-12 was: two competing class/section normalizers existed in the codebase —
`Entity_firestore_sync::normalizeClassSection()` (ordinal-suffixed: "9" → "Class 9th") and
`Firestore_service::classKey()`/`sectionKey()` (naive prefix-add: "9" → "Class 9", no ordinal) —
used inconsistently across ~20 files and 100+ call sites, risking a promoted/queried student
becoming invisible to modules that used the "wrong" one.

**Root cause, confirmed from current source, is narrower than the two-competing-writers framing
suggested:** every student document is *already* forced through `normalizeClassSection()` inside
`Entity_firestore_sync::syncStudent()` (Entity_firestore_sync.php:316), regardless of what format
the caller passed in — including the promotion path, which routes through
`Student_write_service::batchMoveStudents()` → `writeToFirestore('syncStudent', …)`. So
**`students.className`/`section` was never actually at risk of landing in non-canonical form.**
The real defect is on the **query/lookup and other-collection side**: ~20 files build a filter
value or a Firestore docId with `Firestore_service::classKey()`/`sectionKey()`, which — pre-fix —
did *not* apply the ordinal suffix, so a bare-number input ("9") produced "Class 9" while the
student row it was trying to match carried "Class 9th". Confirmed concretely in
`Classes.php::fetch_class_sections()` (joins `sections.className` against `students.Class` using
the non-ordinal key) and in `Sis.php::execute_promotion()`'s destination-capacity check (counts
existing students at the non-ordinal key while `syncStudent()` writes the ordinal one for every
student it moves).

**Fix implemented:** `Firestore_service::classKey()`/`sectionKey()` now delegate to
`Entity_firestore_sync::normalizeClassSection()` — the single documented canonical algorithm
(Entity_firestore_sync.php:116-121: *"Single source of truth for the canonical Firestore
class/section shape. Every writer that touches a student doc must funnel through here."*). This
is a **two-function, ~20-line change** in one file. Every one of the 100+ existing call sites
across Attendance/Classes/Sis/Fee_management/Staff/Transport/Roster_helper/Student_write_service
now automatically converges on the same value, with **zero call-site edits** — satisfying the
"smallest safe change" requirement without a system-wide refactor.

**Verdict: COMPLETE WITH CONDITIONS.** See §37.

---

## 2. Pre-Implementation Baseline

```
git status   → integration/phase2, large pre-existing uncommitted tree (unrelated work from
               prior phases — HRMS/Transport/Fees/Campus Access modules; untouched by this pass)
git branch   → integration/phase2 (unchanged)
```

PHPUnit baseline (`vendor/bin/phpunit --testsuite Unit`), run **before any edit**, and reconfirmed
**after** the edit by temporarily `git stash`-ing just `Firestore_service.php` and re-running the
10 failing tests in isolation — identical failures both with and without the fix:

```
Tests: 643, Assertions: 2033, Failures: 10.
1) BackupTest::size_human_formats_bytes_correctly
2) HomeworkInputLengthValidationTest::test_bug013_update_homework_length_caps_present
3) HomeworkRosterFailClosedTest::test_bug003_catch_fails_closed_with_503
4) HomeworkSchoolIdPredicateTest::test_bug011_submissions_queries_carry_school_id
5) HomeworkSilentCatchTest::test_bug002_all_six_firestore_catches_now_log
6) ParentHomeworkRepoSilentCatchTest::test_bug022_three_structured_debug_log_emits
7) ParentHomeworkRepoSilentCatchTest::test_bug022_discarded_exception_pattern_reduced
8) ParentHomeworkRepoSilentCatchTest::test_bug022_caller_contracts_preserved
9) TeacherExecuteDeleteCascadeTest::test_bug017_cascade_catches_emit_structured_log
10) TeacherExecuteDeleteCascadeTest::test_bug017_failure_counters_present_and_incremented
```

None reference `classKey`/`sectionKey`/`normalizeClassSection`/`Firestore_service`/
`Entity_firestore_sync` (confirmed by grep). These are string-match tests against Kotlin/PHP
source snapshots embedded in the test files themselves (cross-repo source-drift checks per
CLAUDE.md's documented baseline) — pre-existing and unrelated to SL-12. **Baseline unaffected: 10
pre-existing failures, before and after, byte-identical list.** (Current count differs from the
635/155 figures in older memory/CLAUDE.md notes — this repo has grown substantially since those
were last measured; the important fact is the *failure list*, not the total, and that list did
not change.)

---

## 3. Current Canonical Normalization Architecture (confirmed from source)

- **`Entity_firestore_sync::normalizeClassSection(string $rawClass, string $rawSection): array`**
  (Entity_firestore_sync.php:164-210) — explicitly documented as the single source of truth
  (§116-121 docblock). Strips "Class "/"Section " prefixes and ordinal suffixes, then re-applies
  a canonical ordinal suffix for numeric classes via `ordinalSuffix()` and a `CLASS_ORDER_MAP`
  lookup for sortable `classOrder`. Output: `className` ("Class 10th"), `section` ("Section A"),
  `classOrder` (int|null), `sectionCode` ("A", unprefixed).
- **Called unconditionally inside `syncStudent()`** (line 316) and `syncParent()` (line 478) —
  i.e. every student/parent Firestore write has *always* gone through this, independent of what
  the calling controller/service passed in.
- **`Firestore_service::classKey()`/`sectionKey()`/`buildSectionKey()`** (Firestore_service.php,
  pre-fix lines 247-268) — a *different*, older algorithm: prepend "Class "/"Section " only if not
  already present; **no ordinal-suffix normalization**. Used by ~20 files to build query filter
  values and Firestore docIds for `sections`, `examSchedule`, `attendanceLocks`, promotion
  capacity checks, fee-structure lookups, roster queries, and more.
- **A third, dead duplicate**: `Firestore_helper::classKey()`/`sectionKey()`/`buildSectionKey()`
  (Firestore_helper.php:80-101) — same naive-prefix algorithm as pre-fix `Firestore_service`, but
  **zero live callers** anywhere in the codebase (confirmed by grep — the only 3 files matching
  `Firestore_helper::` reference an unrelated constant in a docblock comment, not these methods).
  Left untouched — dead code is out of SL-12's scope, and modifying/deleting unrelated dead code
  was explicitly disallowed ("do not touch unrelated modules").

**Confirmed canonical implementation: `Entity_firestore_sync::normalizeClassSection()`.** Per
§3 of the task instructions ("use it rather than recreating its logic elsewhere"), the fix makes
`classKey()`/`sectionKey()` thin wrappers around it rather than inventing a second helper.

---

## 4/5. Affected Write Paths / Read Paths

Every one of the following call sites is affected identically (they call the now-fixed static
method; no call site itself was edited):

| File | Role | Pre-fix risk |
|---|---|---|
| `Classes.php` (`add_section`, `fetch_class_sections`, `fetch_sections_list`, move/transfer) | Writes `sections.className`; joins `sections`↔`students` | **Confirmed live bug** — join used non-ordinal key against ordinal-suffixed `students.className` |
| `Sis.php` (`execute_promotion` capacity/fee-structure guard, class/section filters throughout) | Reads target-section student count before promoting | **Confirmed live bug** — capacity check counted students at non-ordinal key; actual promoted rows land at ordinal key (via `syncStudent`), so the check could under-count indefinitely |
| `Attendance.php` (~25 call sites: roster queries, lock docIds, session summaries) | Query filters + `attendanceLocks` docId construction | Divergence risk wherever raw class input wasn't already ordinal-suffixed |
| `Student_write_service.php` (`validateClass`/`validateSection`, `moveStudent`, `batchMoveStudents`) | Validates + builds `sectionKey` composite field before calling `syncStudent` | The *validation* value was non-canonical, but **the actual `students.className` written was always correct** (syncStudent re-normalizes) — see §1 |
| `Roster_helper.php` | Builds query keys for roster reads | Same class of risk as Attendance |
| `Transport_service.php`, `Fee_management.php`, `Staff.php`, `Staff_role_check.php`, `Red_flags.php`, `Entitlements.php` | Query filters against `students`/related collections | Same class of risk |
| `Entity_firestore_sync.php` itself (`syncSection`, `syncExamSchedule`, legacy path of `syncExamScheduleFull`) | Writes `sections`/`examSchedule` docs | Wrote the *non*-canonical form even though the file's own `normalizeClassSection()` sits a few hundred lines above — now unified |

`Timetable_service.php`, `Substitute_service.php`, `Subject_assignment_service.php`,
`Lesson_plan_service.php`, `Gate_pass_service.php`, `Homework.php`, `Academic.php` already called
`normalizeClassSection()` directly — unaffected by this change (they were already canonical).

---

## 6/7. Exact Files Changed / Exact Logic Changed

**One file, two functions, plus one new test file:**

`application/libraries/Firestore_service.php` (lines 244-269):

```php
public static function classKey(string $val): string
{
    $s = trim($val);
    if ($s === '') return '';
    return Entity_firestore_sync::normalizeClassSection($s, '')['className'];
}

public static function sectionKey(string $val): string
{
    $s = trim($val);
    if ($s === '') return '';
    return Entity_firestore_sync::normalizeClassSection('', $s)['section'];
}
```

`buildSectionKey()` was **not** edited — it already just concatenates `classKey()`/`sectionKey()`,
so it inherits the fix automatically.

No circular-dependency issue: `Firestore_service.php` already `require_once`s
`Entity_firestore_sync.php` at its own top (line 4), and `normalizeClassSection()` is a pure
static method that never calls back into `Firestore_service` — confirmed by reading its full body
(Entity_firestore_sync.php:164-210).

`tests/Unit/ClassSectionCanonicalizationTest.php` — new, 8 tests / 31 assertions, all passing.

---

## 8. Why the Change Is Minimal and Safe

- **Single point of change.** Every one of the 100+ call sites gets the fix without being touched.
- **Idempotent for every input already in canonical form.** `normalizeClassSection("Class 9th")`
  round-trips to `"Class 9th"` — confirmed by test
  `test_classKey_matches_normalizeClassSection_for_every_supported_class_format`. Any caller that
  was already passing an ordinal-suffixed string (the majority — most call sites read a student's
  own `className` field back and re-key it) sees **zero behavior change**.
  the caller was passing already-canonical input, this is a pure no-op.
- **Only bare-number/non-ordinal inputs change output** — exactly the cases that were the bug.
- **No call-site code was rewritten**, so no risk of a copy-paste/edit mistake in 20 files.
- **No new competing helper was introduced** — `Firestore_helper`'s dead duplicate was left alone,
  per scope discipline.
- Empty-string, whitespace-only, non-numeric (LKG/Nursery/etc.) inputs behave identically to
  before at the boundary (`'' → ''`), confirmed by
  `test_classKey_and_sectionKey_preserve_empty_string`.

---

## 9. Promotion Behavior

Traced `Sis::execute_promotion()` (Sis.php:1345) end-to-end:

1. `to_class`/`to_section` come from the operator's POST form (dropdown-driven).
2. Capacity + fee-structure guards now use the **fixed** `classKey()`/`sectionKey()` — so the
   pre-existing capacity-undercounting bug (§4/5) is closed as a side effect of this fix, though
   it was never explicitly named SL-12's target.
3. Actual student writes go through `Student_write_service::batchMoveStudents()` →
   `Entity_firestore_sync::syncStudent()`, which — **unchanged by this fix, and never broken** —
   always applied `normalizeClassSection()` to the final `className`/`section` written.
4. `studentId` is never touched by any code in this path — confirmed by reading
   `batchMoveStudents()` in full (Student_write_service.php:363-425): the `$fsData` array written
   per student contains `Name`, `name`, `className`, `section`, `sectionKey`, `status`, `session`
   — no `studentId`/`userId` key, so the merge-write cannot alter the document's identity fields.
5. SRNO is never referenced anywhere in the promotion path (`Sis::execute_promotion`,
   `batchMoveStudents`, `syncStudent`) — confirmed by grep; promotion has no code path that could
   touch `srNoIndex` or a student's `srNo` field.

---

## 10. Student ID Preservation — CODE-REVIEWED, VERIFIED

Confirmed by direct source read of `batchMoveStudents()` (§9.4): the write payload has no
`studentId`/`userId` key. Firestore `_write()` in this codebase is merge-semantics (established in
earlier phases of this program), so omitted keys are never cleared. Student ID is architecturally
incapable of changing via this code path, with or without SL-12's fix — SL-12 did not touch this
guarantee, it only changed what value lands in `className`/`section`, and only for the
already-buggy non-ordinal case.

## 11. SRNO Preservation — CODE-REVIEWED, VERIFIED

No SRNO code path exists in promotion at all (§9.5) — SRNO is scoped to admission/re-admission
(`Sr_no_service`, `Readmission.php`), never touched by `Sis::execute_promotion` or
`batchMoveStudents`. Unaffected by SL-12, confirmed unchanged.

## 12. Roll Number Class/Section Scope — VERIFIED (unit-tested + code-reviewed)

`classKey()`/`sectionKey()` each accept exactly one scalar string argument and have no `rollNo`
parameter or field access anywhere in their bodies (Firestore_service.php:244-269) — Roll Number
cannot enter this function by construction. Unit test
`test_same_roll_number_is_valid_across_distinct_class_section_keys` proves the same roll number
string produces three distinct composite keys across 5-A/5-B/6-A. Roll Number's own read/write
sites (`rollNo`/`rollNoRaw` fields on student docs and the three Teacher-App ViewModels touched in
Phase 8's `Int.MIN_VALUE` fix) were **not modified** in this pass — out of scope, confirmed
unchanged by `git diff`.

---

## 13-24. Cross-Module / Cross-Platform Verification

| Module | Verdict | Basis |
|---|---|---|
| **SIS** (Sis.php) | VERIFIED | Capacity/fee-structure checks now query the same key `syncStudent()` writes — closes a real pre-existing undercounting bug (§4/5) |
| **Attendance** | VERIFIED (code-reviewed) | All ~25 `classKey()`/`sectionKey()` call sites inherit the fix; roster reads already derive from `students.className`/`section` (ordinal, unchanged); `_lock_doc_id()` docId format changes for classes previously keyed as bare numbers — see §27 data-drift note |
| **Academic / Homework / Timetable / Subject Assignment / Lesson Plan / Substitute / Gate Pass** | NO CHANGE — already used `normalizeClassSection()` directly (§5), confirmed by grep; SL-12 fix is a no-op for these files | Source read |
| **Student 360** | NOT independently re-verified this pass — reads student docs by ID, not by class/section key; no `classKey`/`sectionKey` call sites in `Student_context.php`/`Student_timeline_reader.php`/`Student_panel_reader.php` (confirmed by grep) — unaffected | grep |
| **Transport** (`Transport_service.php`) | VERIFIED (code-reviewed) — 2 call sites, both query filters against `students`, benefit from the same convergence | Source read |
| **Fee_management.php** | Uses its **own** private `_normalizeClassSection()` (line 262) — a fourth, separate implementation, **not** touched by this fix (out of scope — it doesn't call `Firestore_service::classKey`/`sectionKey`). Flagged as a **new, out-of-scope finding**, not fixed here — see §35 | grep + read |
| **Rules / Indexes** | VERIFIED — NO RULE CHANGE REQUIRED, VERIFIED — NO INDEX CHANGE REQUIRED | §25/26 below |

## 25. Firestore Rules Verification

Read `firebase-rules/firestore.rules`. No rule inspects the *value* of `className`/`section` — the
one place `className`/`section` appear as a whitelisted field name (PTM RSVP writable-fields list,
line 1289) is a field-presence/name check, not a format/regex check. **VERIFIED — NO RULE CHANGE
REQUIRED.**

## 26. Firestore Index Verification

29 composite indexes in `firebase-rules/firestore.indexes.json` reference `className` as a field.
Indexes are structural (field + sort order), never content-aware — a different string value in
that field requires no index change. **VERIFIED — NO INDEX CHANGE REQUIRED.**

## 27. Existing Data Drift — REQUIRES DISCLOSURE, NOT BACKFILLED

This is the one place the fix has a real, non-hypothetical consequence, and it is **not** silently
patched here:

- **`students`/`parents` collections: NO drift.** These were always written via
  `normalizeClassSection()` inside `syncStudent()`/`syncParent()`, independent of this fix. Every
  existing student document already carries the ordinal-suffixed canonical form.
- **`sections` collection: LIKELY drift for any school whose classes were originally created as
  bare numbers.** `Classes.php::add_section()` builds the docId as
  `{schoolId}_{classKey}_{section_name}` and writes `className: classKey($class_name)`. Pre-fix,
  a class created by typing "9" produced `className: "Class 9"`; post-fix, the exact same input
  produces `"Class 9th"`. **Existing `sections` documents keep their old, non-ordinal `className`
  value — this fix does not touch already-written Firestore data.** A *new* `add_section()` call
  for the same class today will now compute a *different* docId than the school's existing
  section documents, and `fetch_class_sections()`/`fetch_sections_list()` queries built with the
  new key will not find the old rows.
  - **This is not a new problem** — it is the exact pre-existing SL-12 bug (`sections` vs
    `students` already diverged before this fix, since `students` was always ordinal and
    `sections` was always non-ordinal). The fix *improves* alignment going forward for any newly
    created section, but does not retroactively repair already-drifted `sections` documents.
  - **Detection:** for each school, compare `sections.className` values against the set of
    `students.className` values in the same school+session; any `sections` doc whose `className`
    lacks the ordinal suffix while a `students` doc for the "same" class has it is drifted.
  - **Correction (NOT performed, NOT authorized):** a targeted backfill script would read each
    `sections` doc, recompute `className` via the now-fixed `classKey()`, and either update the
    doc in place or migrate to a new docId (deleting the old one) — the latter is safer for
    `sections` because the class/section string is embedded in the docId itself, so "in place"
    isn't possible without a doc move.
  - **Reversibility:** a backfill that only rewrites `className` field values (not docIds) is
    trivially reversible (previous value is known/loggable). A docId migration is reversible only
    if the old doc is soft-preserved rather than hard-deleted.
  - **Affected modules if left un-backfilled:** section lists / capacity checks / student counts
    surfaced through `Classes.php` for any school with legacy bare-number class names. This is a
    **pre-existing, disclosed condition** — not introduced by this fix, but this fix is the first
    point at which it becomes newly *visible* (new section-adds will silently create a
    parallel/duplicate section row rather than updating the existing one).
- **`attendanceLocks` docIds** (`Attendance.php::_lock_doc_id`): same mechanism, much lower
  severity — these are short-lived, date-scoped operational locks (one document per class+section
  **+date**), not permanent records. A lock created for a past date under the old key format will
  not be found by a post-fix lookup for that same historical date; this cannot affect *today's*
  attendance locking since new locks and their checks now use the same (new) key consistently. No
  backfill recommended — self-healing by nature (a stale lock doc for an old date is inert).
- **`examSchedule`** (`Entity_firestore_sync::syncExamSchedule`, the legacy single-subject path):
  same class of risk as `sections`, lower likely impact since `syncExamScheduleFull` (the
  "Phase 6 canonical" writer, already preferring `normalizeClassSection()` with a classKey
  fallback only on empty) is the documented current writer.

**No backfill was run.** Per explicit instruction, this is reported, not executed.

## 28. Cross-Tenant Verification

`classKey()`/`sectionKey()` are pure string functions — no `schoolId`, no Firestore call, no
static/global state (confirmed by reading the full 26-line diff: neither function touches
anything outside its own single string argument). Tenant isolation is enforced entirely by
callers via `schoolWhere()`/`docId()`/`querySchool()`, none of which this fix touches. Unit test
`test_classKey_is_a_pure_function_with_no_school_scoping` confirms determinism/no hidden state.
**VERIFIED — no cross-tenant path exists in the changed code.**

---

## 29/30. Tests Added / Executed

`tests/Unit/ClassSectionCanonicalizationTest.php` — 8 tests, 31 assertions, all passing:

| # | Test | Maps to task's TEST # |
|---|---|---|
| 1 | `classKey` == `normalizeClassSection` for 5 numeric-class formats | TEST 1, 3 |
| 2 | `classKey` handles non-numeric classes (LKG/Nursery/…) | TEST 1 |
| 3 | `sectionKey` == `normalizeClassSection` for 5 section formats | TEST 2, 4 |
| 4 | idempotency (re-applying `classKey`/`sectionKey` to their own output) | — |
| 5 | empty/whitespace input preserved as `''` | — |
| 6 | `buildSectionKey` inherits the fix | — |
| 7 | same Roll Number valid across 3 distinct class/section keys | TEST 7, 8 |
| 8 | `classKey` is a pure function (no tenant/global state) | TEST 9, 10 (partial) |

**Full baseline:** `vendor/bin/phpunit --testsuite Unit` — 643 tests, 2033 assertions, 10
failures, all 10 pre-existing and confirmed identical with the fix stashed out (§2).

## 31. Baseline Comparison

Identical failing-test list with and without the fix (§2). **Zero new failures.**

## 32. New Failures

None.

## 33. Pre-Existing Failures

The same 10 listed in §2 — all pre-date this change and are unrelated to class/section code
(Backup byte-formatting, and cross-repo Kotlin/PHP source-string assertions in
Homework/Teacher/Parent test files).

## 34-35. Remaining Gaps / Deferred Issues (discovered, NOT fixed — explicitly out of SL-12 scope)

1. **`sections`/`examSchedule` existing-document drift** — §27. Requires a separately-authorized
   backfill decision.
2. **`Fee_management.php::_normalizeClassSection()`** — a fourth, independent implementation of
   the same concept, not wired to either `Firestore_service::classKey()` or
   `Entity_firestore_sync::normalizeClassSection()`. Not touched (would expand SL-12's scope into
   a different module's private method); flagged for a future, separately-scoped pass.
3. **Parent App's `Constants.Firebase.classKey()`** (Parent repo,
   `util/Constants.kt:198-203`) implements the **old naive-prefix algorithm** — it does not match
   either the now-fixed PHP `classKey()` or the Teacher App's own `Constants.classKey()`
   (`util/Constants.kt:133-141 in the Teacher repo), which **already** independently implements
   the ordinal-suffix canonical form specifically because "the Teacher app needs the label form to
   match Firestore docs written by the admin panel" (Teacher app's own code comment). This is a
   **pre-existing, already-partially-documented cross-platform divergence** — the Parent/Teacher
   Stories feature (`StorySharedConfig.kt:26-32`, both repos) explicitly calls out that "the two
   apps' Constants.classKey differ" and works around it with its own independent reducer. **Not
   caused by this PHP fix** (Parent App reads `className` values that were already
   ordinal-canonical via `syncStudent()`, before and after this change) and **not a concrete
   regression this implementation introduces** — per the explicit instruction not to touch the
   Parent App absent a concrete regression, **NO CHANGE MADE.** Documented here as a discovered
   gap for a future, separately-scoped cross-platform consistency pass.
4. Existing pre-existing defects named in Phase 8.5 (SL-06, SL-07, SL-09, SL-13, RES-001,
   CERT-001, RF-001, report-card/TC Roll-Number-label issues) — unchanged, unaffected, out of
   scope.

## 36. Recommended Next Phase

A separately-authorized, explicitly-scoped pass to (a) decide whether/how to backfill `sections`/
`examSchedule` drift (§27), and (b) reconcile Parent App's `classKey()` with the Teacher App's
already-canonical version (§35.3) — likely by porting the same ordinal-suffix algorithm, mirroring
what the Teacher App already did independently.

## 37. Final Verdict

**COMPLETE WITH CONDITIONS.**

Demonstrated: canonical write (unchanged, always-correct — §1/§9), promotion preserving Student ID
(§10) and SRNO (§11) by direct code inspection, Roll Number remaining class/section-scoped and
never touched (§12, unit-tested), cross-module convergence for every `classKey()`/`sectionKey()`
caller (§4/5/13-24), Firestore rules/indexes requiring no change (§25/26), tenant isolation
architecturally unaffected (§28, unit-tested), and a full regression run showing zero new PHPUnit
failures (§29-33).

**Conditions (why not unconditional COMPLETE):**
- Existing `sections`/`examSchedule` Firestore documents for schools with legacy bare-number class
  names carry stale, non-canonical `className` values that this fix does not retroactively correct
  (§27) — a real, disclosed, non-blocking data-drift condition requiring a separate authorization
  to backfill.
- Parent App's own `classKey()` still diverges from the now-canonical form (§35.3) — inert today
  (its inputs are already-canonical Firestore reads), not a regression, but not reconciled either.
- Attendance/Timetable/Homework/etc. cross-module verification for §13-24 was performed by source
  inspection and grep, not by running the live application against a real Firestore project or the
  Android apps (no service-account credentials / emulator harness available in this environment,
  consistent with this program's established testing constraints — see `SrNoServiceTest.php`'s own
  documented limitation).

## Git / Worktree Safety

- Files modified: `application/libraries/Firestore_service.php` (2 functions).
- Files created: `tests/Unit/ClassSectionCanonicalizationTest.php`,
  `STUDENT_LIFECYCLE_SL12_CANONICAL_CLASS_SECTION_IMPLEMENTATION_REPORT.md`.
- Files deleted: none.
- Staged: none (repo already had unrelated staged files from prior work — untouched).
- Current branch: `integration/phase2` (unchanged).
- Commits made: **none.**
- Pushes made: **none.**
- All other pre-existing working-tree modifications (from prior, unrelated phases) preserved
  exactly as found — not staged, not committed, not reverted.

**Nothing was committed or pushed. Nothing outside `Firestore_service.php` and the two new files
was modified.**
