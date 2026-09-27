# Phase 16.2 — Import Capacity Preview (CAP-1) + Lifecycle Integrity Audit

**Date:** 2026-08-15 · **Branch:** `integration/phase2` @ `758c924`

**IMPLEMENTED. NOT COMMITTED / PUSHED / MERGED / REBASED / DEPLOYED. UAT NOT EXECUTED.**
No production data touched. `firestore.rules` / `firestore.indexes.json` **NOT modified**.
No RTDB introduced. No AI placement, no automatic class/section assignment, no balancing.

---

## 1. EXECUTIVE SUMMARY

CAP-1 is closed, and the lifecycle sweep required by Part 9 surfaced **a HIGH cross-module defect
that is already documented inside the repo and was never fixed beyond one module**.

**Implemented:**

| ID | Item | Status |
|---|---|---|
| **CAP-1** 🟢→ | Import preview now projects section capacity per class+section | ✅ **FIXED** |
| **CAP-1b** 🟡 | Preview and commit share ONE placement-key derivation | ✅ **FIXED** (extracted) |
| **IMP-1** 🟡 | In-file duplicate Roll Numbers now surfaced at validate, not only at commit | ✅ **FIXED** (found this phase) |

**Found, fully traced, deliberately NOT fixed:**

**LIFE-1 🔴 HIGH — `MY_Controller::_get_session_classes()` reads retired RTDB and silently returns
zero classes.** Six controllers depend on it: **Academic (×4), Certificates, LMS, Health_check (×4),
Red_flags**. `Red_flags.php:350-364` already documents the exact root cause from **July 2026**:

> *"the create-flag modal was showing an empty class list. Root cause … `_get_session_classes()`
> reads the LEGACY RTDB tree `Schools/{school}/{session}/Class X/Section Y`, which is empty/stale for
> Firestore-onboarded schools — so it returned zero classes with no error."*

Red_flags built a Firestore replacement. **The other consumers were left on the broken function.**
This is not speculation — it is a confirmed, silently-failing defect with an in-repo post-mortem.
§7 explains precisely why I did not fix it here and what the complete fix is.

**Tests: 930 run, 10 failures — identical to the 798/10 baseline. Zero new failures. +17 new tests.**

---

## 2. WHAT WAS RESEARCHED

External import-preview behaviour across school ERP/SIS products, plus a full re-trace of every
ZenX path that can create or move a student. Previous reports were re-verified against source; §5
records where source contradicted an assumption.

## 3. EXTERNAL ERP EVIDENCE

| # | Question | Finding | Label | Source |
|---|---|---|---|---|
| A/B | Pre-import display | A distinct **validate/preview step** before commit is standard | **SOURCE-VERIFIED** | [Vedmarg](https://vedmarg.com/import-students-from-udise-student-module-to-vedmarg/), [Lexplore](https://info.lexplore.com/en/support/pupil-import-validation-errors-and-warnings) |
| — | Presentation | Valid rows green, invalid rows **marked red in place** | SOURCE-VERIFIED | Vedmarg |
| — | Workflow | Validate → **download error report → fix in Excel → re-upload** | SOURCE-VERIFIED | [Codepex](https://codepex.com/codepex-campus/knowledge-base/bulk-data-migration-importing-student-records-excel) |
| E | Reject scope | Validation errors **prevent those rows** uploading | SOURCE-VERIFIED | [PTD guide](https://cteiskb.ptdtechnology.com/Portals/3/Importing%20Students%20Guide.pdf) |
| I | Duplicate students | Detected as a validation error (e.g. pupil would appear in two classes) | SOURCE-VERIFIED | Lexplore |
| — | Large files | Split 10,000+ into 2,000–3,000 chunks; test-import first | SOURCE-VERIFIED | Codepex |
| **C/D** | **Capacity projection in preview** | **No vendor documents projected section strength or capacity overflow in an import preview** | **NOT ESTABLISHED BY SOURCES** | — |
| F | Correct class/section inside preview | Not documented; the documented remedy is fix-and-re-upload | NOT ESTABLISHED | — |
| G/H | Existing vs projected strength | **Not documented** | NOT ESTABLISHED | — |
| J | Duplicate Roll handling in import | **Not documented** | NOT ESTABLISHED | — |
| K/L | Identity preservation / roll assignment on import | Not documented | NOT ESTABLISHED | — |

**Honest consequence:** the capacity table you sketched is **NOT established industry practice** —
no vendor documents it. It is a **ZENX RECOMMENDATION**, justified not by imitation but because ZenX
*already enforces* capacity at commit (since Phase 15) and it is indefensible to enforce a rule at
commit while hiding it at preview. The general shape I did follow — validate-before-commit with
per-row markings — **is** SOURCE-VERIFIED.

I did **not** implement in-preview class/section correction (F): the documented remedy is
fix-and-re-upload, and inventing an editor would be building on no evidence.

## 4. ZENX CURRENT-STATE MATRIX (re-traced from source)

| Path | Creates? | Class | Section | Capacity | Roll | SRNO | StudentID | History | RBAC | Preview |
|---|---|---|---|---|---|---|---|---|---|---|
| Direct admission | ✅ | form | form | ✅ `:653` | ✅ `:665` | allocated | new | ✅ | SIS | live headroom |
| CRM enrolment | ✅ | application | picker | ✅ `:8928` | ✖ none set | allocated | new | ✅ | SIS | — |
| Bulk import | ✅ | file | file | ✅ commit + **✅ preview (NEW)** | ✅ commit + **✅ in-file at preview (NEW)** | allocated | new | ✅ | SIS | ✅ **now capacity-aware** |
| Re-admission | ✅ | new | new | ✅ inherited | ✅ inherited | **preserved/relinked** | **new** | ✅ | SIS | — |
| Promotion | ✖ | changes | changes | ✅ `:1512` | ✅ `$rollOwners` | unchanged | unchanged | ✅ | SIS | `promote_preview` |
| Section transfer | ✖ | ✖ | changes | ✅ | ✅ **(16.1)** | unchanged | unchanged | ✅ **(16.1)** | **SIS (16.1)** | — |
| Student edit | ✖ | form | form | n/a | ✅ `:6625` | unchanged | unchanged | ✅ | SIS | — |
| Withdrawal / TC / delete | ✖ | preserved | preserved | seat freed | preserved | preserved | preserved | ✅ | SIS | — |
| Admission backfill | ✖ | history only | — | n/a | n/a | — | — | ✅ | SIS | — |

**No path can bypass capacity or roll uniqueness.** Every student-creating or student-moving path is
guarded, and preview now agrees with commit on the import path.

## 5. FINDINGS

### CAP-1 ✅ FIXED — import preview projects capacity
`import_validate()` performed schema validation plus in-file SR/APAAR duplicate detection and
**nothing about capacity** — ZENX-VERIFIED from source. It now groups valid rows by class+section
and applies the **canonical** `_sectionCapacityExceeded()` with the identical running-seat
projection (`$seatsUsed + 1`) the commit uses, returning a per-section table
(`current / capacity / incoming / projected / accept / reject / unlimited`) plus a
`capacity_overflow` summary count. Overflow rows are downgraded to **warning**, never error.

### CAP-1b ✅ FIXED — one placement-key derivation
The preview would otherwise have re-derived class/section keys independently. If its ordinal suffix
or normaliser drifted even slightly, it would project capacity for a section the commit never writes
to — showing a seat count that does not correspond to reality. Extracted
`Sis::_importPlacementKeys()`; **both preview and commit now call it.**

### IMP-1 ✅ FIXED — in-file duplicate Roll Numbers at preview *(found this phase)*
The commit rejects them (`$seenRolls`), but the operator learned only afterwards. This is the **same
pure, no-Firestore-read class** of check as the SR Number and APAAR duplicates the method already
performs — by its own stated principle it belonged there. Scoped class+section (the same roll in 5-A
and 5-B stays legal), case-insensitive, `12A ≠ 12`.

### LIFE-1 🔴 HIGH — RTDB class enumerator returns zero classes *(found this phase, NOT fixed)*
See §1 and §7.

### CAP-2 🟡 MEDIUM — capacity remains detection-grade *(unchanged, honestly restated)*
Read-then-write. Two concurrent imports/admissions into the final seat can both pass. **The preview
does not change this and does not pretend to.**

## 6. SEVERITY MATRIX

| ID | Severity | Module | Reproducible | Fixed |
|---|---|---|---|---|
| LIFE-1 | 🔴 **HIGH** | MY_Controller → Academic / Certificates / LMS / Health_check | ✅ (documented in-repo) | ❌ §7 |
| CAP-1 | 🟢 LOW→ | SIS import | ✅ | ✅ |
| CAP-1b | 🟡 MED (latent) | SIS import | — | ✅ |
| IMP-1 | 🟡 MED | SIS import | ✅ | ✅ |
| CAP-2 | 🟡 MED | Shared | rare | ❌ future primitive |

## 7. WHAT WAS DELIBERATELY NOT FIXED — LIFE-1

**Exact location:** `MY_Controller::_get_session_classes()` (~line 2064) —
`firebase->shallow_get("Schools/{$school}/{$session}")`, i.e. **RTDB**.

**Consumers:** `Academic.php:184, 293, 1425, 1638` · `Certificates.php:297` · `Lms.php:74` ·
`Health_check.php:306, 675, 834, 991` · `Red_flags.php:406` (fallback only).

**Consequence:** for Firestore-onboarded schools the call returns an empty array **with no error**,
so class/section dropdowns render empty. Red_flags hit this in production in July 2026 and wrote the
post-mortem quoted in §1.

**Why not fixed here — three concrete reasons, not reluctance:**

1. **It is not import-preview work.** The user instruction is explicit: *"Do NOT silently implement
   unrelated changes."* This touches Academic, Certificates, LMS and Health_check — four modules
   outside this phase's scope.
2. **A real compatibility risk exists between consumers.** Red_flags' own comment records that the
   stored `section` field is **inconsistent across writers** — some store `"A"`, the live data stores
   `"Section A"` — and it deliberately normalises to the **bare letter** to match the legacy
   contract. Promoting its implementation into the spine would impose that normalisation on four
   consumers whose expectations I have **not** audited. A wrong guess would break timetables,
   certificates and LMS rather than fix them.
3. **It belongs to the RTDB-elimination workstream** alongside FEES-D15, not to Class/Section.

**The complete fix (specified, not half-done):** promote the proven Firestore implementation from
`Red_flags::_get_class_sections()` into `_get_session_classes()`, reading the `sections` collection,
**retaining the RTDB path as the legacy fallback exactly as Red_flags does** — one change repairs all
six consumers with no per-module edits. **Prerequisite:** audit the section-format expectation
(`"A"` vs `"Section A"`) of each of the four unaudited consumers first.

**Acceptance condition:** for a Firestore-onboarded school, Academic, Certificates and LMS class
dropdowns list every configured class+section, and Red_flags' behaviour is unchanged.

## 8. PERFORMANCE / N+1

| Rows | Capacity reads | Notes |
|---|---|---|
| 100 | **O(1)** | one memoised student snapshot + one `sections` read per distinct class+section |
| 500 | **O(1)** | identical |
| 1,000 | **O(1)** | identical |
| 5,000 | **O(1)** | identical |

`_sectionCapacityExceeded()` reads the **per-request memoised** student snapshot; `_sectionCapacity()`
memoises per `session|class|section`. Cost therefore scales with the number of **distinct
class+section groups in the file** (typically 2–10), **never with row count**. A test asserts a
5,000-row file resolves capacity exactly once. **Writes added: zero — preview writes nothing.**
Sequential RTTs: unchanged.

**Refactor note:** extracting `_sectionActiveCount()` did not change the read pattern — it is the
same loop over the same cached snapshot, now callable by both the guard and the preview instead of
being duplicated.

## 9. CLASS / SECTION / ROLL CROSS-CHECK

Import cannot bypass: capacity ✅ · roll uniqueness ✅ (in-file **and** DB) · Student ID canonical ✅ ·
SR Number identity ✅ · session scoping ✅ · Active/withdrawn ✅ · duplicate-student detection ✅.
Roll uniqueness remains **School+Session+Class+Section** — **not global**; tests assert the same roll
in 5-A and 5-B, and in Class 5 vs Class 6, is legal. Alphanumeric (`12`, `12A`, `11A`) compared as
strings, case-insensitive, with `12A ≠ 12` explicitly asserted. **No roll is inferred from Student ID
or SR Number** — crossed fixtures `STU0012→SRNO 47→Roll 12` and `STU0047→SRNO 12→Roll 47`.

## 10. BULK ROLL ASSIGNMENT — R3 RECOMMENDATION (researched, not built)

Re-confirmed **SOURCE-VERIFIED** across NextERP, Fedena and AMS ERP: a dedicated generate/assign
operation with ordering by **name A-Z/Z-A, admission number, date of joining, age**, plus manual
override, and **batch-scoped uniqueness** (*"two students of a batch cannot be assigned the same roll
number"*). **Not implemented this phase** — it was not required to close CAP-1 safely, and the
instruction is to produce the recommendation for R3, which §16 does.

## 11. TESTS

```
BASELINE:      798 tests, 2507 assertions, 10 failures
BEFORE 16.2:   913 tests, 2724 assertions, 10 failures
AFTER  16.2:   930 tests, 2774 assertions, 10 failures
NEW TESTS:     17        NEW FAILURES: 0
LINT: clean · RTDB: none introduced · RULES/INDEXES: untouched
```
Pre-existing failures unchanged and **not mine**: Backup ×1, Homework ×5, ParentHomeworkRepo ×3,
TeacherDeleteCascade ×2. **This is not "all tests pass".**

Coverage: the worked 5-A/5-B example · full section does not block another section · exact-fill ·
last seat · unlimited (`capacity <= 0`) · independent groups · **preview == commit** ·
**seats taken between preview and commit are re-rejected** · ordinal suffixes incl. 11th/12th/13th ·
identical key derivation · in-file roll duplicates (same/different section, different class, blank,
alphanumeric, case) · **O(1) reads at 5,000 rows** · identity invariants.

## 12. UAT MATRIX — ALL PENDING

| # | Test | Expected | Status |
|---|---|---|---|
| 1 | Upload file, click Validate | Capacity table rendered per class+section | **UAT PENDING** |
| 2 | 5-A 38/40, 5 rows | 2 accept, 3 reject, projected 43/40 | **UAT PENDING** |
| 3 | 5-B 31/40, 4 rows in same file | All 4 accept — unaffected by 5-A | **UAT PENDING** |
| 4 | Overflow rows | Marked **warning**, not error | **UAT PENDING** |
| 5 | Section with no capacity set | Shown unlimited, nothing rejected | **UAT PENDING** |
| 6 | Two rows, same roll, same section | Flagged at validate | **UAT PENDING** |
| 7 | Two rows, same roll, different sections | Allowed | **UAT PENDING** |
| 8 | Roll `12A` vs `12` | Not treated as duplicates | **UAT PENDING** |
| 9 | **Seats taken between preview and commit** | Commit rejects; preview was only a forecast | **UAT PENDING** |
| 10 | Commit after clean preview | Imports as forecast | **UAT PENDING** |
| 11 | 1,000-row file | Preview responsive; no per-row query | **UAT PENDING** |
| 12 | Admission / promotion / transfer | Unchanged | **UAT PENDING** |
| 13 | Parent / Teacher app | Unaffected | **UAT PENDING** |

**No browser or device UAT has been executed at any point in this programme.**

## 13. CROSS-MODULE VERIFICATION

Preview is **read-only** and writes nothing, so no consumer can be affected by CAP-1 itself.
`_sectionActiveCount()` was extracted from existing code with identical semantics — every existing
capacity call site (6 in `Sis.php`, plus promotion and transfer) is unchanged in behaviour.
`studentId` remains the sole canonical FK; SR Number is **not** persisted as an FK; Roll Number
remains register/display data. Attendance, Exam/Report Card, Homework, Fees, Communication,
Transport, Hostel, Library, Certificates, Parent App, Teacher App: **untouched**.
⚠ **LIFE-1 affects Academic, Certificates and LMS today** — pre-existing, not caused by this phase.

## 14. DEFERRED WORK REGISTER

| ID | Sev | Module | Location | Dependency | Why deferred | Acceptance condition |
|---|---|---|---|---|---|---|
| **LIFE-1** | 🔴 HIGH | Core → Academic/Certificates/LMS | `MY_Controller::_get_session_classes()` ~2064 | Audit section-format expectations of 4 consumers | Cross-module; RTDB workstream | Firestore-onboarded school lists all classes in Academic, Certificates, LMS; Red_flags unchanged |
| CAP-2 | 🟡 MED | Shared | capacity + roll guards | Design | One shared primitive, not per-path | Atomic claim across capacity+roll+promotion+transfer |
| ROLL-1/2 | 🟡 MED | SIS | — | — | R3 scope | Bulk assign screen; CRM students get rolls |
| ROLL-3 | 🟡 MED | SIS | — | **D5** | Production data | Read-only survey run; no auto-renumber |
| ROLL-4 | 🟡 MED | SIS | promotion | **D4** | Business policy | Carry-forward vs clear decided |
| SEC-1 | 🟡 MED | SIS | — | **D3** | Business policy | Override authority + audit defined |
| EXAM-1 | 🟢 LOW | Exam | — | — | No consumer | — |
| **FEES-D7** | 🟡 MED | Fees | `planDueDateBackfill` | **Authorization + credential** | Production data | Dry-run reviewed; ambiguous never guessed |
| **FEES-D9** | ⚪ | Fees | absent | Design phase | Greenfield | Own phase |
| **FEES-D13** | 🟡 MED | Fees | frozen | **Decision** | Business policy | Per-module opt-in, default OFF |
| **FEES-D14** | 🟡 MED | Fees | `send_reminder` | **Provider** | External | Real delivery; no speculative abstraction |
| **FEES-D15** | 🟡 MED | Fees/HW/Acct | `Fee_management:4913` | **Decision** | RTDB workstream | No false success |
| **FEES-FROZEN-1** | 🟡 MED | Fees | `Fee_lifecycle:1043` | Unfreeze | Frozen | `balance == net + fine − paid` |
| **FEES-UAT** | 🔴 | Fees | — | Test env | Never executed | 31-row matrix executed |

**All seven Fees items re-checked and carried forward intact. No Fees code was reopened.**

## 15. REMAINING ISSUES
**Class/Section/Roll:** LIFE-1 · CAP-2 · ROLL-1/2/3/4 · SEC-1 · EXAM-1.
**Fees:** unchanged — the seven register entries above.

## 16. RECOMMENDED R3

**R3 = Bulk Roll Assignment** (closes ROLL-1 and ROLL-2):
`Class → Section → roster → ordering (Manual | Name A-Z | Admission No | Existing Roll) → preview →
duplicate validation → batched save → audit`, with `mb_`-safe stable sorting (a naive `sort()` on
multi-byte names renumbers a class differently on each run) and the existing atomic `rollNoClaims`
primitive. Cost: 1 roster read + batched writes.

**However — I recommend LIFE-1 ahead of R3.** It is 🔴 HIGH, confirmed, silently failing in three
modules today, and has a known one-change fix. R3 is a convenience feature; LIFE-1 is broken screens.

## 17. STATUS

**READY WITH CONDITIONS.**
CAP-1, CAP-1b and IMP-1 are implemented, tested and baseline-clean. The conditions are:
(a) **UAT NOT EXECUTED** — the 13-row matrix must run, especially row 9 (preview/commit divergence);
(b) **LIFE-1 remains open** and needs its own authorization.

---

## WHAT THIS MEANS FOR A REAL SCHOOL

**Importing students now tells you what will happen before it happens.**

Previously you could upload sixty students for a class with forty seats, wait for the import, and
only then find that twenty were rejected. The system knew — it just never said. Now the preview shows
each class and section: how many students are in it, how many seats exist, how many of your rows will
fit, and how many won't. One full section no longer affects the others — if 5-A is full but 5-B has
room, the 5-B students still come in.

**One important honesty point:** the preview is a **forecast, not a promise**. If another member of
staff admits two students while you're looking at the screen, those seats are gone, and the import
will correctly refuse rows the preview said would fit. That's deliberate — the real check happens at
the moment of import, where it belongs.

**We also caught duplicate roll numbers earlier.** If your spreadsheet has two children with Roll 18
in the same class and section, you're told at the checking stage instead of after importing. The same
roll in different sections is still fine, as it should be.

**And we found something bigger while looking around — which I have not fixed, on purpose.**

There's a shared piece of code that lists your classes and sections for several screens: the academic
module, certificates, and the learning module. It reads from the old database ZenX no longer uses, so
for schools set up on the current system **it returns nothing, silently** — those dropdowns come up
empty with no error message.

This isn't a guess. Someone hit exactly this in July, wrote down the cause, and fixed it for the
red-flags screen only. The other screens were left as they were.

I didn't fix it here for a specific reason: the working version stores section names in a slightly
different format, and pushing it into shared code would affect four screens I haven't examined. Fixing
it blind could break timetables and certificates rather than repair them. **It needs its own short
piece of work — and I'd do it before the roll-number tool**, because empty dropdowns are a real
problem today, while bulk roll assignment is a convenience.

**Nothing has been committed or deployed, and none of this has been clicked through in a browser yet.**
