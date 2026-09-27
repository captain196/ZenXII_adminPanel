# Phase 15 — Class/Section Capacity Enforcement & Admission-Path Convergence

**Date:** 2026-08-15 · **Branch:** `integration/phase2` @ `758c924` · **Type: IMPLEMENTATION**

**Nothing committed, staged, pushed, merged, rebased or deployed. No Firestore rules or indexes
touched. No schema created. No production data read or written. No RTDB introduced.**

**Evidence labels:** **ZENX-VERIFIED** (read from this repository's source this phase) ·
**SOURCE-VERIFIED** (external documentation) · **INFERENCE** · **ZENX RECOMMENDATION** ·
**NOT ESTABLISHED BY SOURCES**.

---

## 1. Executive Summary

Both HIGH-severity gaps are closed — **and the research premise behind them was wrong in a way that
made the problem bigger, not smaller.**

**CORRECTION TO THE PRIOR RESEARCH REPORT (§30 discipline).**

| What the prior report said | What the source actually says | Why it matters |
|---|---|---|
| "The CRM admission path **already enforces** section capacity; the direct path is the gap." | **`Sis::enroll_student()` contains NO capacity check whatsoever** — grep for `maxStrength\|capacity\|currentCount` inside it returns nothing. `get_class_sections()` computes headroom for the **picker UI only** and is never consulted at commit. | The gap was **three** unguarded creation paths, not two. Had I implemented on the stated premise, CRM would have been left silently overfillable — the exact defect this phase exists to remove. |

**What was actually built:** one canonical validator, three call sites, zero new schema, zero new
endpoints.

| Path | Before | After |
|---|---|---|
| Direct admission (`save_admission`) | no capacity awareness at all | **server-validated at commit** |
| CRM enrollment (`enroll_student`) | UI suggestion only, **no server guard** | **server-validated at commit** |
| Bulk import (`_runStudentImport`) | none | **projected per class+section group, per row** |
| Promotion / Section transfer | already guarded (ZENX-VERIFIED) | **untouched** — §17 said leave it alone if correct; it is |

**One new lifecycle defect found and fixed in passing:** the direct-admission path also lacked
capacity checking on the **re-admission** flow, because re-admission creates its student through
`enroll_student()` (ZENX-VERIFIED — `Readmission::confirm()` explicitly *never* creates a student;
it links an already-created one). Fixing `enroll_student()` therefore closed re-admission's capacity
gap in the same change, with no re-admission code touched.

**Verdict: READY WITH CONDITIONS** (§21) — STATIC VERIFIED, UNIT TEST VERIFIED, **UAT NOT EXECUTED**.

## 2. Files Changed (3 + 1 test)

| File | Change |
|---|---|
| `application/core/MY_Controller.php` | **NEW** `_sectionCapacityExceeded()`, `_sectionCapacity()`, `_sectionCapacityMessage()` — the single canonical rule |
| `application/controllers/Sis.php` | 3 call sites: `save_admission()`, `enroll_student()`, `_runStudentImport()` (+ running seat counter) |
| `application/views/studentAdmission.php` | Section dropdown shows live `current/capacity`, disables FULL, pre-selects the suggestion, all-sections-full warning |
| `tests/Unit/Phase15SectionCapacityTest.php` | **NEW** — 16 tests |

**No other file touched.** Promotion, transfer, re-admission, SRNO, Student ID, Admission Number,
APAAR, Roll Number, fees, sibling logic: all unmodified.

## 3. Admission-Path Matrix (ZENX-VERIFIED this phase)

| Path | Creates student? | Assigns Class? | Assigns Section? | Capacity checked? | Roll checked? | SRNO? | Student ID? | Withdrawn behaviour |
|---|---|---|---|---|---|---|---|---|
| **Direct admission** `save_admission()` | ✔ | operator | operator | **✔ NEW** | ✔ (14.1) | ✔ allocated | ✔ created | n/a |
| **CRM enrollment** `enroll_student()` | ✔ | from application | operator (picker) | **✔ NEW** | ✖ (sets no roll — ZENX-VERIFIED) | ✔ | ✔ | n/a |
| **Bulk import** `_runStudentImport()` | ✔ | from file | from file | **✔ NEW** | ✔ (14.1) | ✔ | ✔ | n/a |
| **Re-admission** `Readmission::confirm()` | **✖ — links only** | ✖ | ✖ | **inherited** from `enroll_student()` | inherited | preserved (relink) | new (by enroll) | old record must be non-Active before confirm |
| **Promotion** `execute_promotion()` | ✖ | changes | changes | ✔ pre-existing | ✔ (14.1) | unchanged | unchanged | Active-only counted |
| **Section transfer** `Classes.php:531` | ✖ | ✖ | changes | ✔ pre-existing | ✔ (14.1) | unchanged | unchanged | Active-only counted |

## 4. Existing Capacity Architecture (ZENX-VERIFIED, reused not replaced)

- `sections` collection, doc id `sectionDocId(class, section, session)` = `{schoolId}_{session}_{class}_{section}` — **session is part of the key**.
- `maxStrength` (alias `max_strength`); **default 40** when absent, matching `get_class_sections()`.
- `maxStrength <= 0` = **unlimited**, matching the pre-existing promotion/transfer guards
  (`if ($maxStrength > 0)`). Treating 0 as "zero seats" would retroactively lock every
  never-configured section — deliberately not done.
- Live active count derived from the roster, **not** the drift-prone stored `studentCount`.

**The most-headroom suggestion algorithm is preserved exactly** — no round-robin, alphabetical,
random or balancing algorithm was introduced (§14 of the brief).

## 5. Direct Admission Fix

Placed **before the roll check and before any write**, so a rejection burns no Student ID, claims no
SRNO and creates no document:

```php
$capOver = $this->_sectionCapacityExceeded($classOrd, $section, $this->session_year, 1);
if ($capOver !== null) {
    return $this->json_error($this->_sectionCapacityMessage($capOver, $classOrd, $section));
}
```

> Class 5 Section A is full. Capacity: 40, Current students: 40. Please select another available section.

Validated against the **live roster at commit**, not against whatever the browser saw when the form
opened — a stale tab whose seats were consumed while the operator typed is correctly rejected (§6 of
the brief).

## 6. Bulk Import Fix

**Transaction model preserved, not overridden.** The import is **chunked into 25-row batches**
(ZENX-VERIFIED from its own comment) and already has row-level skip/error semantics. Forcing
whole-file atomicity would contradict the documented architecture, so per §10 the rule applied is
*"preserve row-level semantics while making an over-capacity RESULT impossible."*

A capacity-blocked row is skipped with an explicit reason (`$error++`, `$skipped[]`, `continue`) —
identical to the existing fee-chart and roll guards.

**The running-seat counter is the load-bearing detail.** The shared validator reads a
**request-memoised** snapshot, so students created moments earlier *in the same loop* are invisible
to it. `$seatsUsedThisRun["class|section"]` closes that window:

```php
$capUsed = $seatsUsedThisRun[mb_strtolower("{$className}|{$section}")] ?? 0;
$capOver = $this->_sectionCapacityExceeded($className, $section, $session_year, $capUsed + 1);
```

> Row 41: Priya Sharma — Class 5 Section A is full (capacity 40, already 38 active + 2 from this import); row not imported

Each class+section group is projected **independently** — a full 5-A never blocks a valid 5-B row.

## 7. CRM Behaviour

**Changed** (contrary to the prior report's premise, §1): `enroll_student()` now validates
server-side. The enroll mutex is released on rejection, matching the fee-structure guard directly
above it, so the operator can correct the section and retry immediately.

The UI suggestion behaviour is untouched.

## 8. Promotion Behaviour — **UNCHANGED**

`Sis.php:1512-1521` already guards destination capacity all-or-nothing, and Phase 14.1 added roll
collision detection. §17 said leave working logic alone; **audited and left alone.** Not rewritten
for symmetry.

## 9. Transfer Behaviour — **UNCHANGED**

`Classes.php:531-547` already guards capacity identically. **Audited, left alone.**

## 10. Re-admission Behaviour

**No re-admission code was modified.** ZENX-VERIFIED: `Readmission::confirm()` never creates a
student — its own docblock states the new record is created through `Sis::enroll_student()` first,
and `confirm()` only links it and relinks the SRNO. Therefore fixing `enroll_student()` closed
re-admission's capacity gap automatically.

Verified intact: preserved SRNO (relink), new Student ID, new Admission Number, and the guard
requiring the prior record to be non-Active before confirming — which is also what stops a withdrawn
record from double-consuming a seat.

## 11. Roll Number Interaction

**Unchanged.** Capacity is checked *before* the roll check in `save_admission()`, so a full section
fails fast on the more fundamental constraint. Both validators share the same snapshot, the same
Active-only filter and the same session semantics, so they **cannot disagree about who occupies a
section**. The atomic "Suggest Roll Number" behaviour is untouched.

## 12–13. SRNO / Student ID Interaction

**Zero interaction — by construction.** The capacity validator reads only `className`, `section`,
`session`, `status` and `studentId` (for self-exclusion). It never reads, derives or writes SRNO,
Admission Number or Roll Number. Asserted by test: `STU0012 ↔ SRNO 47`, `STU0047 ↔ SRNO 12`.

Student ID remains the canonical persisted foreign key everywhere — nothing in this phase writes a
student reference at all.

## 14. Session Scoping

**Enforced in both directions** (ZENX-VERIFIED):
- The **capacity number** comes from `sectionDocId(class, section, session)`, which embeds the session.
- The **occupancy count** skips rows whose `session` differs (when both sides carry one).

> **A bug caught during implementation:** my first `_sectionCapacity()` memo key was
> `class|section`, omitting the session — which would have let a 2025 lookup serve a 2026 capacity
> from cache. Corrected to `session|class|section` before any test ran.

Pre-convergence documents with no `session` are counted (not silently excluded) — the safer reading
for a roster conflict, and identical to `_rollNoConflict()`'s existing behaviour.

## 15. Active/Withdrawn Handling

Only `status == 'Active'` (or blank, for back-compat) consumes a seat. Withdrawn / TC / Inactive do
not — so a withdrawal genuinely frees a seat and a re-admitted student never double-counts against
their own historical record. Tested directly.

## 16. Concurrency Analysis — **stated honestly, not overclaimed**

This validator is **read-then-write / detection-grade**, deliberately mirroring the existing
promotion and transfer guards it sits beside. **Two operators admitting into the final remaining seat
in the same instant can both pass.** That is a real, documented limitation — **not** a claim of
atomicity.

Per §22 of the brief, no distributed seat-reservation system was invented. The exposure is narrow
(same-second admissions into a section with exactly one seat left) and matches the risk tolerance
already accepted for promotion, transfer and Roll Number validation.

**Recommended for a future phase, not this one** (§24).

## 17–19. Firestore Read/Write, Latency & N+1 Analysis

| Operation | Reads before | Reads after | Writes | Seq RTT added | N+1 |
|---|---:|---:|---:|---:|---|
| Direct admission | n | **+0** (cache hit) / **+2** worst case (snapshot + section doc) | 0 | 0–2 | **no** |
| CRM enrollment | n | same | 0 | 0–2 | **no** |
| Import, 500 rows / 1 section | n | **+2 total** (1 snapshot + 1 section doc) | 0 | 2 | **no** |
| Import, 500 rows / 3 sections | n | **+4 total** (1 snapshot + 3 section docs) | 0 | 4 | **no** |
| Promotion / transfer | unchanged | **+0** | 0 | 0 | no |

**The §21 anti-pattern is structurally avoided.** A 500-row import performs **one** roster snapshot
read (request-memoised, shared with the roll validator and the SIS/fee searches) and **one section
document read per distinct section** (memoised on `session|class|section`) — **not 500 capacity
queries.** The per-row cost is a single in-memory integer comparison against the running counter.

The snapshot is the same write-invalidated `students_raw` key used elsewhere, so capacity can never
be authorised against stale data.

## 20. Test Results

`tests/Unit/Phase15SectionCapacityTest.php` — **16 tests, 45 assertions, all pass.**

| §27 ref | Coverage |
|---|---|
| A1–A2 | non-full succeeds; full rejected with concrete numbers |
| A5 | exactly-filling the last seat succeeds |
| A7 | withdrawn students do not consume capacity |
| A6 | session-scoped (last year's roster doesn't block this year) |
| — | section-scoped and class-scoped isolation |
| — | unset capacity = unlimited, not zero |
| — | self-exclusion (editing a student in a full section) |
| B8 | import exactly filling remaining seats succeeds |
| B9/B15 | over-capacity import rejects **only** overflow rows; section never ends over capacity |
| B10 | each class+section group projected independently |
| — | a full group does not block a valid other group |
| B11 | projection needs no per-row capacity read |
| F23–25 | `STU0012 ↔ SRNO 47`, `STU0047 ↔ SRNO 12` |

**Coverage limits, stated plainly:** `_sectionCapacityExceeded()` and the import loop live on
CodeIgniter controllers that cannot be bootstrapped in this suite. The tests model the decision rule
exactly as implemented and are the regression guard for it; controller wiring and the admission form
are covered by the UAT matrix (§21), not these tests.

## 21. Status Summary

| Check | Result |
|---|---|
| **STATIC VERIFIED** | ✔ `php -l` clean on all 3 changed files + test |
| **UNIT TEST VERIFIED** | ✔ 16/16 new pass |
| **BUILD VERIFIED** | n/a — no mobile code changed |
| **Full regression** | **798 tests, 2507 assertions, 10 failures = exact pre-existing baseline. Zero new failures.** (782 → 798 = +16, all mine, all passing) |
| **UAT NOT EXECUTED** | ✔ correctly not claimed |
| Rules / indexes | **untouched** — mtimes 2026-08-13 / 2026-08-12 |
| Schema | **no new collection or field** |

**Required manual UAT (§28) — all NOT RUN:** fill a section to capacity → attempt direct admission →
confirm rejection → free a seat → confirm suggestion → override to another valid section → confirm
save → bulk import into exact remaining seats → confirm success → over-capacity import → confirm
rejection with per-row reasons and no partial over-capacity result → mid-session admission →
promotion → transfer → re-admission.

## 22. Regression Results

Zero new failures. The 10 baseline failures are the long-standing cross-repo source-grep tests
(Backup ×1, Homework ×5, ParentHomeworkRepo ×3, TeacherDeleteCascade ×2) — none touches a file
changed in this phase.

## 23. Remaining Lifecycle Gaps

| Severity | Gap |
|---|---|
| **MEDIUM** | Capacity validation is detection-grade, not atomic (§16). Same class as the existing promotion/transfer/roll gaps. |
| **MEDIUM** | **No full-section override path exists anywhere** — `get_class_sections()`'s own comment says an admin "must create a new section or manually override", but no override mechanism exists in code. The comment describes an intent the implementation never had. Deliberately not invented (§7 of the brief). |
| **LOW** | Import **preview** does not yet surface capacity (only the commit path rejects). The brief's §25 preview table is not implemented — commit-time correctness was prioritised over preview UX. |
| **LOW** | No minimum-strength, section priority, waitlist or section-level capacity audit trail — all absent by design, none evidenced as needed. |
| **FUTURE** | Pre-existing duplicate Roll Numbers still unsurveyed (credential-blocked, carried from Phase 14.1). |

## 24. Recommended Next Phase

1. **Import preview capacity table** (§25 of the brief) — LOW risk, pure UX, closes the one part of
   this phase's brief not implemented.
2. **Full-section override decision** — a genuine business decision (who may exceed capacity, and is
   it audited?), needed before any code.
3. **Atomic seat claims** — only if same-second admission races prove real in practice; would also
   let promotion/transfer/roll share the same primitive.

---

## Git Status

```
Branch: integration/phase2   HEAD: 758c924   (unchanged — nothing committed)

 M application/core/MY_Controller.php
 M application/controllers/Sis.php
 M application/views/studentAdmission.php
?? tests/Unit/Phase15SectionCapacityTest.php
?? PHASE15_CLASS_SECTION_CAPACITY_HARDENING_REPORT.md

Teacher App  31 dirty  (UNCHANGED — not touched)
Parent App   23 dirty  (UNCHANGED — not touched)

firestore.rules        mtime 2026-08-13  — untouched
firestore.indexes.json mtime 2026-08-12  — untouched
```

Lint: clean. Tests: 798/10 baseline. Rules/indexes: untouched. UAT: **NOT EXECUTED**.

---

## WHAT THIS MEANS FOR A REAL SCHOOL

**Before today, ZenX would let you put a 41st student into a 40-seat class and say nothing.** Not on
one screen — on **all three** ways a student can be admitted: the Add Student form, the CRM
enrolment screen, and bulk import from a spreadsheet.

I want to be straight about something: the research I gave you last time said the CRM screen already
protected you. When I opened the actual code to build on that, it didn't. It showed your staff how
full each section was, but nothing stopped them saving anyway — and nothing stopped a stale browser
tab from saving into seats that had been taken minutes earlier. So the problem was bigger than I'd
reported, and all three paths are now protected by the same single rule.

**What you'll see on the Add Student form now:** pick Class 5, and the Section dropdown fills in with
real numbers — `Section A (37/40)`, `Section B (40/40) — FULL`, `Section C (28/40)` — with the
fullest-headroom section pre-selected and full ones greyed out. You can still pick any non-full
section you want; it's a suggestion, not a decision made for you. And if the last seat gets taken
while you're filling in the form, the save is refused with a clear message rather than quietly
overfilling the class.

**For bulk import, the important part is what does *not* happen.** If Section 5-A has 2 seats left
and your spreadsheet has 5 students for it, you don't get 5 imported and an overfull class. You get
the 2 that fit, and 3 clearly-labelled rows telling you exactly why: *"Class 5 Section A is full
(capacity 40, already 38 active + 2 from this import); row not imported."* Students for other
sections in the same file import normally — one full section doesn't sink the whole upload.

**Two things I deliberately did not do.** I did not build a way to override a full section, because
that's a real business decision (who's allowed to, and should it be logged?) that you should make,
not me. And I did not touch promotion or section transfer — I checked them, they already had this
protection, and rewriting working code just to make it look symmetrical would have added risk for
nothing.

**One honest limitation:** if two staff members admit a student into the *same last remaining seat*
at the exact same moment, both could still succeed. Fixing that properly needs a seat-reservation
system, which is a bigger change than this phase allowed. It's the same known limitation your
promotion and transfer screens already have, and I've flagged it rather than pretending it's solved.

**Nobody has clicked through this in a browser yet.** The logic is tested and the code is checked,
but the actual screens haven't been exercised — that's the remaining step before you trust it.

**Nothing has been committed, pushed or deployed.**
