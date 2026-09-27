# Phase 16.3 — LIFE-1: Firestore Class/Section Enumeration Closure

**Date:** 2026-08-15 · **Branch:** `integration/phase2` @ `758c924`

**IMPLEMENTED. NOT COMMITTED / PUSHED / MERGED / REBASED / DEPLOYED. UAT NOT EXECUTED.**
No production data touched. `firestore.rules` / `firestore.indexes.json` **NOT modified**.
**No RTDB fallback introduced — the runtime RTDB dependency on this path is removed.**

---

## 1. EXECUTIVE SUMMARY

LIFE-1 is closed. The shared class/section enumerator now reads Firestore, and the section-format
question that blocked Phase 16.2 is **resolved by evidence, not judgement**.

| ID | Item | Status |
|---|---|---|
| **LIFE-1** 🔴 HIGH | `_get_session_classes()` read retired RTDB → silently empty | ✅ **FIXED** (Firestore, no fallback) |
| **LIFE-2** 🔴 HIGH | `Health_check` carried its **own duplicate RTDB copy** — fixing the parent would not have fixed it | ✅ **FIXED** (override removed) |
| **LIFE-3** 🟡 MED | Stale docblock describing the removed RTDB implementation | ✅ **FIXED** |
| **LIFE-4** 🟡 MED | Section values stored as bare `"Section"` produced a garbage `"Section Section"` entry | ✅ **FIXED** (caught by my own test) |

**The blocking question from 16.2 — `"A"` vs `"Section A"` — is answered: BARE LETTER.**
Not chosen; **proven** at three independent consumers that re-prefix it themselves:

| Evidence | Code |
|---|---|
| `Timetable_service.php:690` | `$sectionKeyForSas = 'Section ' . $section;` |
| `Health_check.php:309` | `$this->roster->for_class($first['class_key'], 'Section ' . $first['section'])` |
| `Red_flags.php:383` | strips to bare *"to match the legacy `_get_session_classes` contract"* |

Firestore stores the **prefixed** form (`Classes.php:181-183` forces it), so the enumerator strips it.
Returning the stored value unchanged would have produced **`"Section Section A"`** downstream —
i.e. the naive fix would have broken timetables. **16.2's caution was justified.**

**Tests: 946 run, 10 failures — identical to the 798/10 baseline. Zero new failures. +16 new tests.**

---

## 2. THE ORIGINAL DEFECT

`MY_Controller::_get_session_classes()` walked
`Schools/{school}/{session}/Class X/Section Y` via `firebase->shallow_get()` — **RTDB**. For
Firestore-onboarded schools that tree is empty, so the method returned `[]` **with no error and no
log line**. Every dropdown fed by it rendered blank, and nothing anywhere reported a fault.

Red_flags hit this in production on **2026-07-03** and recorded the root cause in-file, then fixed
**only its own screen**. Academic, Certificates, LMS and Health_check were left on the broken path.

## 3. CONSUMER AUDIT (execution paths read, not grepped)

| Consumer | Call site | What it does with the result | Section format required |
|---|---|---|---|
| **Academic** | `:184` `get_academic_data` | returns array **verbatim as JSON** → `views/academic/index.php` | JS reads `c.class_key`, `c.section`, `c.label`, `c.class_section`; POSTs `section` back to `save_period` → `Timetable_service::savePeriod` → **`'Section ' . $section`** ⇒ **BARE** |
| **Academic** | `:293`, `:1425`, `:1638` | substitution / matrix / free-teacher lookups | same array, same contract |
| **Certificates** | `:297` `get_classes` | returns verbatim as JSON → `views/certificates/index.php` | consumed as `class_key` + `section`; `get_students` takes `classKey`/`sectionKey` from the client ⇒ **BARE** |
| **LMS** | `:74` `lms_data` | returns verbatim as JSON → `views/lms/index.php` | same contract |
| **Health_check** | `:306`, `:675`, `:834`, `:991` | `'Section ' . $first['section']` | **BARE** (explicit) |
| **Red_flags** | `:406` | fallback only; its own Firestore reader runs first | **BARE** (normalises itself) |

**Every consumer requires the bare letter. No consumer wants the prefixed form.** No consumer
transforms the result server-side — all four pass it straight to the browser, which is why the
contract had to be traced into the views and back to the write endpoints.

**Ordering:** no consumer depended on the old order (RTDB key order was arbitrary). Deterministic
numeric-then-section ordering is a strict improvement.
**Session scoping:** required by the method's own name and by `sections.session`.
**Empty sections / inactive:** `sections` has no status field — nothing to filter.

## 4. SECTION-FORMAT COMPATIBILITY

| Question | Answer | Evidence |
|---|---|---|
| Stored in Firestore? | **`"Section A"`** (prefixed) | `Classes.php:181-183` forces the prefix on write |
| Stored in legacy RTDB? | `"Section A"` node names | old implementation stripped with `str_replace('Section ', '', $sk)` |
| Red_flags normalises to? | **bare `"A"`** | `Red_flags.php:383` |
| Academic expects? | **bare** | round-trips into `Timetable_service:690` |
| Certificates expects? | **bare** | client-supplied `sectionKey` |
| LMS expects? | **bare** | same shared contract |
| Health_check expects? | **bare** | `'Section ' . $first['section']` |

**Decision:** canonical internal value is the **bare letter**; the prefixed form is a *presentation/
storage* detail, converted at the boundary. **No stored value was renamed** — the strip happens on
read only, exactly as the RTDB implementation did.

## 5. FIRESTORE ARCHITECTURE

`sections` documents (written by `Classes.php:195-202`):
`schoolId` · `className` (classKey, e.g. `Class 5th`) · `section` (**prefixed**) · `maxStrength` ·
`session` · `updatedAt`. Read via `Firestore_service::schoolWhere('sections', [])` — tenant-scoped
by construction.

⚠ **Observed inconsistency, NOT changed:** `Classes.php:186` builds the section doc-id as
`{schoolId}_{classKey}_{section}` — **without session** — while `Firestore_service::sectionDocId()`
embeds session. Out of scope here; recorded in the register as **SEC-2**.

## 6. EXTERNAL RESEARCH

| Question | Finding | Label |
|---|---|---|
| Are Class/Section session-scoped in school ERPs? | Rosters are session/batch-scoped; class-section definitions are commonly reused across sessions | **INDUSTRY PRACTICE** (from the batch/roster model documented by Fedena/AMS in Phase 16.0) |
| Are section labels display values or stable IDs? | **NOT ESTABLISHED BY SOURCES** — no vendor documents an internal section identifier distinct from its label |
| Should a shared enumerator be session-filtered? | **NOT ESTABLISHED** | — |

**Consequence:** I did not restructure the model on speculation. The implementation keeps ZenX's
existing shape and only fixes the data source — **ZENX RECOMMENDATION**, evidence-bounded.

## 7. CANONICAL CONTRACT (unchanged from legacy — deliberately)

```php
[
  'class_key'     => 'Class 5th',            // prefixed
  'section'       => 'A',                    // BARE — load-bearing
  'label'         => 'Class 5th / Section A',
  'class_section' => "Class 5th 'A'",
]
```
Firestore-backed · session-scoped · deduped · deterministically ordered · memoised · **no RTDB**.

## 8. IMPLEMENTATION

**`MY_Controller::_get_session_classes()`** — rewritten:
reads `sections` via `schoolWhere`; **session filter that keeps blank-session legacy rows** (excluding
them would recreate the empty-dropdown defect for exactly the older schools this rescues); strips the
`Section` prefix; dedupes on `classKey|section`; sorts numerically by class then section; memoises
per request. **No RTDB, no fallback.**

**`Health_check::_get_session_classes()`** — **override deleted** so the controller inherits the
fixed parent (LIFE-2).

## 9. DIRECTLY-CONNECTED DEFECTS FOUND AND FIXED IN-PHASE

**LIFE-2 🔴 HIGH — Health_check's duplicate RTDB copy.** A byte-for-byte clone of the broken
enumeration. **Fixing the parent alone would have left Health_check broken** — and worse, its checks
returned `_p('No classes to check')`, a **PASS**. A diagnostic tool that passes because it can see
nothing is worse than one that fails. It also contradicted its own comment: *"Firestore-only post-R1
migration … exercises the compound index + the Firestore students collection end-to-end"* while being
driven by an RTDB list. **Fixed by removing the override.**

**LIFE-3 🟡 — stale docblock** still describing `shallow_get`. Corrected; a comment that describes
removed behaviour is the same defect class corrected in Phase 15.5.

**LIFE-4 🟡 — `"Section"` with no letter. Found by my own test, not by inspection.**
`trim("Section ")` → `"Section"`, and `/^Section\s+/i` requires trailing whitespace, so it **did not
match** — the word `"Section"` was emitted *as the section letter*, yielding
`label: "Class 5th / Section Section"`. Fixed with `/^Section\b\s*/i` plus the existing empty check.
The `\b` also protects a legitimate `"SectionA"`-style label from being mangled.

## 10. NOT FIXED — with exact reasons

**HC-RTDB 🟡 MEDIUM — the rest of Health_check is RTDB-based.** ~22 further `shallow_get` calls
(Accounts, Ledger, Results, Curriculum, HR/Payroll, Parents…). **Not touched:** it is a *diagnostic*
module whose checks may legitimately target legacy data, it spans Accounting/HR/Exam/Communication,
and it belongs to the RTDB-elimination workstream beside FEES-D15. **Only the class enumerator was in
LIFE-1's path.** Registered as **HC-RTDB**.

**LMS-RTDB 🟡 MEDIUM — `Lms.php:76` reads `Schools/{school}/Subject_list` from RTDB** in the same
method as the class list. **Directly adjacent but not the same data** — it is the *subject*
catalogue, a different subsystem with its own migration question. Fixing classes does not fix
subjects, and guessing at a Firestore subject source would be invention. Registered as **LMS-RTDB**.
*Consequence: LMS class dropdowns are now populated; its subject list may still be empty.*

**SEC-2 🟢 LOW — section doc-id omits session** (`Classes.php:186`) while `sectionDocId()` includes
it. Two sections with the same class+section in different sessions would collide on one document.
Not touched: changing a doc-id shape is a data-migration decision. Registered.

**No RTDB fallback was added anywhere**, per the phase's non-negotiable rule.

## 11. PERFORMANCE

| Scale | Reads | Notes |
|---|---|---|
| 1 school, 10 classes | **1** | one `schoolWhere('sections')` |
| 50 sections | **1** | same single collection read |
| 100 sections | **1** | transform is in-memory |
| 20 consumers in one request | **1** | static memo |

**No N+1** — the old implementation issued **1 + N shallow_get calls** (one per class); this issues
**one** collection read. **Strictly fewer round-trips than before.** No writes. **No index required** —
`schoolWhere` uses the existing tenant predicate with no additional `where`/`orderBy`.

**Memo/staleness:** memoised **per request only** (`static` inside the method), so a section created
in one request is visible on the next. No cross-request cache, therefore no stale-session risk.

## 12. RTDB VERIFICATION (only what was actually checked)

| Path | Result |
|---|---|
| `MY_Controller::_get_session_classes()` | ✅ **no `shallow_get`, no `getReference`** — only the explanatory comment |
| `Health_check::_get_session_classes()` | ✅ removed entirely |
| Rest of `Health_check.php` | ⚠ ~22 RTDB reads remain — **out of scope**, registered (HC-RTDB) |
| `Lms.php` subject list | ⚠ RTDB — registered (LMS-RTDB) |

**No repo-wide RTDB eradication is claimed.** Only the LIFE-1 runtime path was verified.

## 13. CROSS-MODULE VERIFICATION

**Contract preserved byte-for-byte**, so Academic, Certificates, LMS, Health_check and Red_flags
consume an identical shape — now populated rather than empty. Red_flags' behaviour is unchanged: its
own Firestore reader still runs first; its fallback is simply no longer RTDB.

**Part 5 sweep — competing implementations:** `sections` is read directly by `Classes.php` (×7),
`Admin.php`, `Attendance.php`, `Academic.php:612`, `Communication.php`. **All are already
Firestore-based.** `_get_session_classes()` (+ the Health_check clone) was the **last RTDB
straggler**; this change aligns it with what the rest of the codebase already does. Those direct
readers are duplication, not defects — **not refactored**, as instructed.

**Identity unchanged:** `studentId` canonical · SR Number display/identity only · Roll Number
class+section scoped. The enumerator carries **no student identity at all** (asserted by test).
SIS, Attendance, Timetable, Examination, Transport, Hostel, Library: **untouched**.

## 14. TESTS

```
PRE-PHASE BASELINE:  930 tests, 2774 assertions, 10 failures
AFTER 16.3:          946 tests, 2799 assertions, 10 failures
NEW TESTS: 16        NEW FAILURES: 0
LINT: clean · RTDB (this path): removed · RULES/INDEXES: untouched
```
Pre-existing failures unchanged, **not mine**: Backup ×1, Homework ×5, ParentHomeworkRepo ×3,
TeacherDeleteCascade ×2. **This is not "all tests pass".**

Coverage: prefixed→bare · **no double-prefix when consumers re-prefix** · already-bare input ·
case-insensitive strip · full legacy shape · session exclusion · **blank-session legacy rows kept** ·
dedupe across sessions/formats · **numeric class ordering (10th after 2nd)** · section ordering ·
malformed rows skipped · genuinely-empty vs read-failure · memoisation (20 consumers → 1 read) ·
100 sections in one pass · no student identity · identity invariants.

## 15. UAT MATRIX — ALL PENDING

| # | Test | Expected | Status |
|---|---|---|---|
| 1 | Firestore-onboarded school | Classes appear everywhere below | **UAT PENDING** |
| 2 | Academic class dropdown | Populated | **UAT PENDING** |
| 3 | Certificates class dropdown | Populated | **UAT PENDING** |
| 4 | LMS class dropdown | Populated (subjects may still be empty — LMS-RTDB) | **UAT PENDING** |
| 5 | Health Check class checks | Runs against real classes, no false "No classes" pass | **UAT PENDING** |
| 6 | Red Flags dropdown | **Unchanged** | **UAT PENDING** |
| 7 | Multiple sections per class | All listed, ordered A,B,C | **UAT PENDING** |
| 8 | Multiple sessions | Only the current session's sections | **UAT PENDING** |
| 9 | No sections configured | Empty list, no error | **UAT PENDING** |
| 10 | Firestore read failure | Empty list **plus a loud error log** | **UAT PENDING** |
| 11 | **Save a timetable period** | Section saved as `Section A`, **not** `Section Section A` | **UAT PENDING** |
| 12 | Existing student placement | Unaffected | **UAT PENDING** |
| 13 | Legacy school, sections without `session` | Still listed | **UAT PENDING** |

**Row 11 is the critical one** — it proves the bare-letter contract end-to-end.
**No browser or device UAT has been executed at any point in this programme.**

## 16. FEES DEFERRED WORK REGISTER (unchanged — no Fees code reopened)

| ID | Sev | Location | Dependency | Acceptance condition |
|---|---|---|---|---|
| FEES-D7 | 🟡 MED | `planDueDateBackfill` | Authorization + credential | Dry-run reviewed; ambiguous never guessed |
| FEES-D9 | ⚪ | absent | Design phase | Own phase |
| FEES-D13 | 🟡 MED | frozen surface | Decision | Per-module opt-in, default OFF |
| FEES-D14 | 🟡 MED | `send_reminder` | Provider choice | Real delivery; no speculative abstraction |
| FEES-D15 | 🟡 MED | `Fee_management:4913` | Decision | No false success |
| FEES-FROZEN-1 | 🟡 MED | `Fee_lifecycle:1043` | Unfreeze | `balance == net + fine − paid` |
| FEES-UAT | 🔴 | — | Test env | 31-row matrix executed |

## 17. CLASS/SECTION/ROLL DEFERRED REGISTER

| ID | Sev | Location | Dependency | Acceptance condition |
|---|---|---|---|---|
| CAP-2 | 🟡 MED | capacity + roll guards | Design | One atomic claim primitive shared across capacity+roll+promotion+transfer |
| ROLL-1/2 | 🟡 MED | SIS | — | Bulk assign screen; CRM students receive rolls |
| ROLL-3 | 🟡 MED | — | **D5** | Read-only survey run; no auto-renumber |
| ROLL-4 | 🟡 MED | promotion | **D4** | Carry-forward vs clear decided |
| SEC-1 | 🟡 MED | — | **D3** | Override authority + audit defined |
| **SEC-2** | 🟢 LOW | `Classes.php:186` | Migration decision | Section doc-id includes session, or documented as intentional |
| EXAM-1 | 🟢 LOW | Exam | — | — |
| **HC-RTDB** | 🟡 MED | `Health_check.php` (~22 sites) | RTDB workstream | Health checks read Firestore or are retired |
| **LMS-RTDB** | 🟡 MED | `Lms.php:76` | Subject-catalogue migration | LMS subjects read Firestore |

## 18. NEXT RECOMMENDED PHASE

**R3 — Bulk Roll Assignment** (closes ROLL-1 and ROLL-2), now unblocked: class/section enumeration
is reliable, which that screen depends on.
`Class → Section → roster → ordering (Manual | Name A-Z | Admission No | Existing Roll) → preview →
duplicate validation → batched save → audit`, `mb_`-safe stable sorting, existing atomic
`rollNoClaims`.

**Before that, consider LMS-RTDB** — LMS class dropdowns now work but its subject list may still be
empty, so that screen is only half-repaired.

## 19. STATUS

**READY WITH CONDITIONS** — implemented, tested, baseline-clean, RTDB removed from this path.
Conditions: **UAT NOT EXECUTED** (especially row 11), and LMS remains partially RTDB-dependent.

---

## 20. WHAT THIS MEANS FOR A REAL SCHOOL

**Your class and section dropdowns will actually have classes in them again.**

Several screens — the academic/timetable module, certificates, and the learning module — get their
list of classes from one shared piece of code. That code was still reading the old database ZenX
stopped using. For any school set up on the current system it found nothing, and reported nothing,
so those dropdowns simply came up empty. No error, no warning — just an empty box.

It now reads the current database, where your classes and sections actually live.

**Two things I found while fixing it that mattered more than expected.**

First, the health-check tool had its **own private copy** of the same broken code. Fixing the shared
version alone would have left it broken — and worse, its checks were reporting "no classes to check"
as a **pass**. A tool that reports everything is fine because it can't see anything is more dangerous
than one that plainly fails.

Second, and this is the one I want to be honest about: **my own test caught a bug in my fix.** Section
names are stored as "Section A", and the screens need just "A". If a section had somehow been saved
as the bare word "Section", my first version would have passed the word "Section" through as the
section name, giving you "Class 5th / Section Section". The test found it before you would have.

**Two things are still not right, and I've listed rather than quietly patched them.** The learning
module's *subject* list still reads from the old database, so that screen is only half-repaired — its
classes will show, its subjects may not. And the health-check tool has around twenty other checks
still pointing at the old database. Both belong with the wider cleanup of that legacy system, not
bolted onto this fix.

**Nothing has been committed or deployed, and none of this has been clicked through in a browser.**
The most important thing to try is saving a timetable period after this change — that's what proves
the section names are being handled correctly from end to end.
