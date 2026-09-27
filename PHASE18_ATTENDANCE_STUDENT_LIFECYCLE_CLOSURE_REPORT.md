# Phase 18 — Attendance: LC-1 Closure + Module Audit

**Date:** 2026-08-15 · **Branch:** `integration/phase2` @ `758c924`

**IMPLEMENTED. NOT COMMITTED / PUSHED / DEPLOYED. UAT NOT EXECUTED.**
No production data touched. Rules/indexes **NOT modified**. No Fees code touched. No RTDB. No AI placement.

---

## 1. MODULE VERDICT (stated before anything else)

**ATTENDANCE — LC-1 DEFECT FIXED · MODULE TRACE INCOMPLETE**

I am **not** marking Attendance `CLOSED — UAT REQUIRED`, because your own closure rule requires
(B) UI inspection and (C) Teacher/Parent App cross-system verification, and **I did neither**. The
backend defect is genuinely fixed and tested; the module is not closed.

---

## 2. THE DEFECT — worse than the single predicate I was sent to fix

The brief named one predicate: `Attendance.php:5476`. Reading the **entire** function showed the real
problem is structural, and it exists in **two** roster methods.

`_get_section_students()` ran a four-strategy cascade, each returning **immediately on a non-empty
result**, and the strategies queried **different field shapes**:

| Strategy | Source | Shape |
|---|---|---|
| 0 | `Roster_helper::for_class` | **camelCase** — `className` / `section` / `status` |
| 1 | `schoolWhere` + `orderBy('Name')` | **PascalCase** — `Class` / `Section` / `Status` |
| 2 | `schoolWhere` + `orderBy('Name')` | **PascalCase** — `Class` / `Section` |
| 3 | `attendanceSummary` derivation | — |

The `students` collection holds **both** shapes. So for a class of 20 camelCase and 5 PascalCase
records, **Strategy 0 returned the 20 and stopped.** Those 5 children were **absent from the
attendance register entirely** — not marked absent, *not present on the list at all*. They could be
neither marked present nor absent.

**The cascade looked robust — four fallbacks — but every fallback fires only on a *completely* empty
result, never on a *partial* one.** That is the same non-empty short-circuit flaw I found in
Red_flags.

**Blast radius:** `_get_section_students()` feeds **eight call sites** in this controller (marking,
summaries, reports, exports).

**Strategy 2 could not rescue anyone**, contrary to its comment: it drops the `Status` predicate but
keeps PascalCase `Class`/`Section`, so a camelCase student is invisible to Strategies 1 *and* 2.

**ATT-1b — the same defect in `_get_active_roster()`** (line 7063). Its per-row status handling was
already both-shape tolerant, but **retrieval was shape-split with the same short-circuit** at 7089.
This roster carries `admissionDate`, which drives mid-session admission handling — so a missing
student there doesn't just shorten a list, it makes their **attendance wrong** (a child admitted on
the 20th would otherwise be shown absent for the 1st–19th).

**Severity: 🔴 HIGH.** This is the incident already recorded in-file at ~line 1218.

## 3. RESEARCH

| Question | Finding | Label |
|---|---|---|
| Do ERPs mark attendance against an *active* roster? | Attendance is taken against the current class roster; inactive students are excluded | **INDUSTRY PRACTICE** — universal across the vendors reviewed in this programme |
| Should a withdrawn student be markable? | **NOT ESTABLISHED BY SOURCES** as an explicit rule, but no vendor exposes withdrawn students on a marking register | INFERENCE |
| Should attendance rosters be session-scoped? | **NOT ESTABLISHED BY SOURCES** | — |
| Mid-session admission handling | **NOT ESTABLISHED** as documented vendor behaviour; ZenX's `admissionDate` guard exceeds it | **ZENX RECOMMENDATION** |

**Honest note:** attendance roster mechanics are not something vendors document at this depth. The fix
here is driven by **ZenX source truth**, not borrowed authority.

## 4. THE FIX

Both roster methods now make **one** pass over the shared request-memoised student snapshot, filtered
by the canonical `_filterSectionRoster()`, which reads **both shapes** for class, section and status.

**Status semantics preserved exactly:** `Active` ✅ · `active`/`ACTIVE` ✅ (case-insensitive) ·
**blank → Active** ✅ (this was Strategy 2's entire purpose, now done correctly) · `Withdrawn` excluded ✅.

**`orderBy('Name')` hazard removed.** A Firestore `orderBy` **excludes documents lacking that exact
field**, so camelCase-only records would have been dropped by the *sort* even after the predicate was
fixed. Sorting moved to PHP, reading both `Name` and `name`.

**Session semantics deliberately unchanged.** The old strategies applied **no** session predicate, so
`''` is passed to preserve behaviour identically. Whether attendance *should* be session-scoped is
registered as **ATT-2**, not silently changed.

**Legacy fallback retained** (line ~7120) for the case where the snapshot is unavailable — reached
only when the primary path yields nothing.

## 5. PERFORMANCE — strictly better

| | Before | After |
|---|---|---|
| Per call | up to **3** Firestore queries | **0** additional — shared snapshot |
| 8 call sites in one request | up to 24 queries | **1** read total (memoised) |

The snapshot is memoised per request, file-cached for 90 s, and write-invalidated. **No N+1
introduced; several removed.**

## 6. SECURITY / FIRESTORE

RBAC unchanged. **RTDB scan of the touched regions: 0.** No fallback introduced. No rules or indexes
touched.

## 7. TESTS

```
PRE-PHASE BASELINE:  1065 tests, 3069 assertions, 10 failures
AFTER PHASE 18:      1079 tests, 3096 assertions, 10 failures
NEW TESTS: 14         NEW FAILURES: 0
LINT: clean · RTDB: 0 · RULES/INDEXES: untouched
```
Pre-existing failures unchanged and **not mine**: Backup ×1, Homework ×5, ParentHomeworkRepo ×3,
TeacherDeleteCascade ×2. **This is not "all tests pass".**

Coverage includes a test that **reproduces the old cascade and asserts it dropped students**, so the
regression is pinned from both directions: mixed shapes returned together · pure-Pascal ·
pure-camel · withdrawn excluded in both shapes · blank status = Active · lowercase `active` ·
section/class scoping in both shapes · session filtering off by design (ATT-2) · **both-shape
sorting** · **`admissionDate` preserved in both shapes** · one shared read for eight call sites ·
roster keys on Student ID never Roll/SRNO.

## 8. WHAT I DID *NOT* DO — required before Attendance can be CLOSED

| Closure requirement | Status |
|---|---|
| **B. UI trace** — attendance views, JS/AJAX, loading/empty/error states, duplicate submit | ❌ **NOT DONE** |
| **C. Cross-system** — Teacher App marking, Parent App attendance display | ❌ **NOT DONE** |
| Attendance edit/regularisation paths | ❌ not traced |
| Attendance reports/exports | ❌ not traced |
| Withdrawn-student historical attendance retention | ❌ not traced |

**Attendance is therefore TRACE INCOMPLETE.**

## 9. NEW FINDING — the head of the next module

**EXAM-LC1 🔴 HIGH — Examination and Results have the same defect, with no fallback at all.**

`Roster_helper::for_class()` queries **camelCase only** — `className`, `section`, `status == 'Active'`
— with **no PascalCase handling and no fallback whatsoever** (verified: the full function body
contains no second query).

Direct consumers:

| Consumer | Consequence for a PascalCase student |
|---|---|
| `Examination.php:956` | **absent from the exam roster — receives no marks** |
| `Result.php:221` | **absent from results — no report card** |
| `Health_check.php:304, 392` | diagnostics under-report |

**This is arguably more severe than the attendance case**, because Attendance at least had Strategies
1–3 as partial fallbacks; Examination and Result have none.

**ZENX RECOMMENDATION for Module 5:** fix `Roster_helper::for_class()` itself — one change would
correct Examination, Results and Health_check together. I did **not** do it here because those three
modules have not been traced, and changing a shared helper beneath untraced consumers is how the
Phase 16.6 half-fix happened.

## 10. DEFERRED / REGISTERED

| ID | Item | Sev | Next step |
|---|---|---|---|
| **ATT-2** | Should attendance rosters be session-scoped? Currently not, preserved deliberately | 🟡 | **Business decision** |
| **ATT-UI** | Attendance UI + Teacher/Parent App verification | 🟡 | Required for module closure |
| **EXAM-LC1** | `Roster_helper::for_class` camelCase-only | 🔴 | **Module 5 entry point** |
| **LC-1 remaining** | ~10 predicates: Admin:487 · Communication:1625 · School_config:1043/1749 · Sis:1980/4423 · Red_flags:1357/1363 (flawed fallback) · 2 verifier scripts | 🔴/🟡 | Per-module as each is opened |
| **LC-2** | Withdrawal clearance (transport/hostel/library) | 🟡 | **Business decision** — research not yet done |

**Fees register carried forward untouched:** FEES-D7 · FEES-D9 · FEES-D13 · FEES-D14 · FEES-D15 ·
FEES-FROZEN-1 · FEES-UAT · **ID-card replacement** (charge + reason codes + **old signed
campus-access credential invalidation** + new credential + audit — blocked on the frozen entitlements
surface, not abandoned).

## 11. UAT MATRIX — ALL PENDING

| # | Test | Expected | Status |
|---|---|---|---|
| 1 | Class with **mixed** record shapes | **Every** student appears on the register | **UAT PENDING** |
| 2 | Count register vs Student List for that class | Identical | **UAT PENDING** |
| 3 | Mark attendance for a previously-missing student | Saves and persists | **UAT PENDING** |
| 4 | Withdrawn student | Not on the register | **UAT PENDING** |
| 5 | Student with blank status | On the register | **UAT PENDING** |
| 6 | Roster sort order | Alphabetical across both shapes | **UAT PENDING** |
| 7 | Mid-session admission | Not absent before their admission date | **UAT PENDING** |
| 8 | Teacher App marking | Same roster as Admin | **UAT PENDING** |
| 9 | Parent App attendance | Correct child, correct days | **UAT PENDING** |
| 10 | Attendance after section transfer | Follows the new section | **UAT PENDING** |
| 11 | Historical attendance after promotion | Preserved | **UAT PENDING** |

**Row 1 is the critical one. No browser or device UAT has been executed at any point in this programme.**

---

## 12. WHAT THIS MEANS FOR A REAL SCHOOL

**Some children were missing from the attendance register — and not in a way anyone would notice.**

ZenX stores student records in two slightly different formats, depending on when and how they were
saved. The attendance screen looked them up using four different methods, one after another, stopping
at the first one that returned anybody. The trouble is the first method only understands one of the
two formats. So in a class where most records are the newer format and a few are older, it found the
newer ones, stopped, and **the older children simply weren't on the list.**

Not marked absent — **not there at all.** A teacher couldn't mark them present even if the child was
sitting in front of them. And because the class list still looked full and plausible, it reads as
"that child was never enrolled" rather than "the software lost them".

It's now fixed: one lookup that understands both formats, so every child appears. It's also faster —
where a single page could previously fire two dozen database queries, it now uses one shared lookup.

**Two things I want to be honest about.**

First, **I have not clicked through the attendance screens, and I have not checked the Teacher or
Parent apps.** The backend is fixed and tested, but by your own rule that means Attendance is *not*
closed. I'm not going to mark it closed to look tidy.

Second, and more serious: **the same problem almost certainly affects exams and results, and there it
has no safety net at all.** The shared lookup that exams and report cards use only understands the
newer format, with no fallback whatsoever. A child stored in the older format would be missing from
the exam list and would receive **no marks and no report card**. I stopped short of changing it here
because that shared piece of code sits underneath three modules I haven't examined yet, and changing
shared code beneath untraced consumers is exactly the mistake I made earlier in this programme.

**That is where the next module should start**, and it's one change that would likely fix exams,
results and the health-check screen together.

**Nothing has been committed or deployed.**
