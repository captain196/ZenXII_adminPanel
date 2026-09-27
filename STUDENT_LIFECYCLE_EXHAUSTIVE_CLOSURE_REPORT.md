# ZenX — Student Lifecycle: LC-1 Fix + Closure Status

**Date:** 2026-08-15 · **Branch:** `integration/phase2` @ `758c924`

**PARTIAL IMPLEMENTATION. NOT COMMITTED / PUSHED / DEPLOYED. UAT NOT EXECUTED.**
No production data touched. Rules/indexes **NOT modified**. No Fees code touched. No RTDB. No AI placement.

---

## 1. EXECUTIVE VERDICT — read this first

**I did not complete what you asked for, and I am telling you that plainly rather than dressing up
partial work as an exhaustive audit.**

| Requested | Delivered |
|---|---|
| **Part 1 — Fix LC-1 completely** | ⚠ **PARTIAL** — 3 of ~8 production call sites fixed; scope corrected; pattern established; remaining 12 predicates enumerated exactly |
| **Parts 2–4 — Exhaustive deep trace of 15 modules** | ❌ **NOT DONE** |
| **Part 5 — Fees pending register re-verified** | ✅ carried forward intact |
| **Part 6 — Withdrawal research (LC-2)** | ❌ **NOT DONE** |

Fabricating a 15-module "exhaustive trace" I have not performed would be precisely the overclaiming
this programme has spent nine phases correcting — three of my own conclusions were already overturned
for exactly that reason. **A sampled module must not be called CLOSED, and an untraced module must not
be called audited.**

**What is real in this report:** a corrected understanding of LC-1's true scope, three genuine fixes
(one of them repairing my own incomplete Phase 16.6 work), a newly-found flaw in the existing
workaround pattern, and an exact list of what remains.

---

## 2. LC-1 — SCOPE CORRECTED

> **PREVIOUS CONCLUSION CORRECTED.** My completeness audit said *"44 such predicates exist"* across
> Academic, Admin, Attendance, Classes, Communication, Red_flags and Sis. **That conflated
> collections.**

Actual breakdown, verified by mapping every predicate to its collection:

| Collection | Predicates | LC-1 relevant? |
|---|---|---|
| **`students`** | **15** | ✅ **yes — the real scope** |
| `staff` | 13 | ❌ different collection, separate question |
| `routes` / `transportExpenses` | 2 | ❌ not students |

**Blindly "fixing 44" would have needlessly rewritten staff and transport queries.** The real LC-1
surface is **15 predicates**, now **12**.

## 3. LC-1 — A SECOND DEFECT IN THE EXISTING WORKAROUND

`Red_flags.php:1354-1366` is the pattern the codebase already uses:

```php
$docs = $this->fs->schoolWhere('students', [... ['status','==','Active']]);
if (empty($docs)) {                      // ← only fires on ZERO results
    $docs = $this->fs->schoolWhere('students', [... ['Status','==','Active']]);
}
```

**This is incomplete, and the flaw is subtle.** It falls back only when the camelCase query returns
**nothing**. With **mixed** data — the actual state of the collection — the first query returns *some*
rows, so the fallback **never fires** and every PascalCase student is still silently dropped.

**The "known workaround" therefore does not work for the very data shape it was written for.**
Attendance's `_get_active_roster` avoids this by filtering in PHP; Red_flags does not.
**ZENX-VERIFIED. Not previously reported.**

## 4. LC-1 — FIXES IMPLEMENTED (3 sites, all in `Classes.php`)

| Site | Was | Now |
|---|---|---|
| `get_class_sections` (section strength) | `['Class','==',…]` + `['Status','==','Active']` | all filtering in `_filterSectionRoster()` |
| `fetch_section_students` | same + Firestore `orderBy('Name')` | PHP filter + PHP sort |
| `delete_section` (dues guard) | same | all filtering in `_filterSectionRoster()` |

> ⚠ **MY OWN PHASE 16.6 FIX WAS INCOMPLETE.** I wrapped `_filterSectionRoster()` around these
> queries to add session scoping — but **left the single-shape `Class` and `Status` predicates in
> place**. The tolerant PHP filter never saw the rows Firestore had already discarded. The session
> fix was real; the shape fix was not, and I did not notice until this pass.

**Also fixed: the `orderBy('Name')` hazard.** A Firestore `orderBy` on `Name` **excludes documents
that lack that exact field** — so camelCase-only students would have been dropped by the *sort* even
after the predicate was fixed. Sorting moved to PHP reading both shapes.

**Performance: strictly better.** All three now read the shared request-memoised, file-cached,
write-invalidated snapshot instead of issuing their own query. A page listing many sections costs
**one** read instead of one per section.

**Regression: 1065 tests, 10 failures — identical to the 798/10 baseline. Zero new failures.**
`php -l` clean. No RTDB introduced.

## 5. LC-1 — WHAT REMAINS (12 predicates, exact)

| File · line | Shape | Surface | Risk |
|---|---|---|---|
| `Attendance.php:5476` | `Status` | attendance roster | 🔴 drops camelCase students |
| `Admin.php:487` | `status` | birthday list | 🟡 drops PascalCase students |
| `Communication.php:1625` | `status` | **recipient lists** | 🔴 messages not delivered |
| `School_config.php:1043, 1749` | `status` | config/session tooling | 🟡 |
| `Sis.php:1980, 4423` | `status` | SIS internals | 🟡 |
| `Red_flags.php:1357, 1363` | both, **flawed fallback** | flag student picker | 🔴 §3 |
| `Class_section_verify.php`, `Dashboard_charts_verify.php` | mixed | verifier scripts | 🟢 diagnostics only |

**Why I stopped rather than bulk-editing:** each needs its own trace to know whether the predicate is
load-bearing for performance on a large collection, or safely replaceable by in-memory filtering. A
blind sweep is exactly how the Phase 16.6 half-fix happened. **The pattern is now proven and the
remaining work is mechanical but must be done site by site.**

## 6. MODULE AUDIT STATUS — HONEST

| Module | Status |
|---|---|
| Class/Section/Capacity/Roll | **CLOSED — UAT REQUIRED** (Phases 15–17) |
| Promotion · Transfer · Rollover | **CLOSED — UAT REQUIRED** |
| Re-admission · Withdrawal (state) | **CLOSED — UAT REQUIRED** |
| Fees | **CLOSED for scope — UAT REQUIRED**, 7 deferred items |
| **Attendance** | **TRACE INCOMPLETE** — status filtering confirmed; LC-1 site open |
| **Examination · Results · Report Card** | **TRACE INCOMPLETE** |
| **Library · Transport · Hostel** | **TRACE INCOMPLETE** |
| **Documents · Certificates · ID Cards** | **TRACE INCOMPLETE** |
| **Communication** | **TRACE INCOMPLETE** — LC-1 site open, 🔴 recipient risk |
| **Homework / LMS** | **TRACE INCOMPLETE** |
| **Parent App · Teacher App** | **TRACE INCOMPLETE** |
| **Student 360 · Imports · Reports** | **TRACE INCOMPLETE** |

**Eleven modules remain TRACE INCOMPLETE.** None may be called CLOSED.

## 7. FEES PENDING REGISTER — intact, re-verified present

FEES-D7 (dateless-demand backfill · authorization) · FEES-D9 (installments) ·
FEES-D13 (Transport/Hostel late fees · frozen surface) · FEES-D14 (SMS/WhatsApp provider) ·
FEES-D15 (`firestore_bulk_sync` RTDB tooling) · FEES-FROZEN-1 (`createModuleFee` fine erasure) ·
FEES-UAT (31-row matrix, never executed).

**ID-card replacement — explicitly NOT abandoned.** Design of record stands: request → reason code
(lost / damaged / stolen / aged) → authorization → charge → payment → receipt → **invalidate the old
signed campus-access credential** → mint new version → audit. Blocked on two things: the natural
charge mechanism (`createModuleFee`) sits on the **frozen Fees Exemption v2 surface**, and credential
invalidation is a security workflow. **To unfreeze:** the entitlements cutover must land, then
FEES-FROZEN-1's acceptance test must pass, then this can be built.

## 8. LC-2 (withdrawal clearance) — NOT RESEARCHED THIS PHASE

Finding stands from the previous audit (`withdraw_student()` → `change_status()` releases no
transport seat, hostel bed or library rights). **The multi-vendor research you asked for was not
performed.** It remains a **BUSINESS DECISION** — auto-release vs retain-until-clearance vs explicit
clearance workflow — and I will not recommend one without the evidence.

## 9. DEFERRED WORK REGISTER

**LC-1 remaining** (12 predicates, §5) · **LC-2** (withdrawal clearance · decision) ·
**11 modules TRACE INCOMPLETE** (§6) · **Red_flags flawed fallback** (§3) ·
D19 · D4 · D3 · D5 · SEC-5 · SEC-6 · SEC-16 · CAP-2 · EXAM-1 · HC-RTDB · LMS-RTDB ·
all 7 Fees items (§7).

## 10. CLOSURE GATE

**A. MUST FIX BEFORE COMPLETE:** LC-1 remaining 12 · Red_flags fallback · the 11 module traces.
**B. SHOULD FIX:** LC-2 workflow · CRM roll gap.
**C. BUSINESS DECISIONS:** LC-2 · D19 · D4 · D3 · FEES-D13 · FEES-D14 · FEES-D15.
**D. PRODUCTION-DATA / AUTHORIZATION:** D5 · SEC-5 · FEES-D7 · **LC-1 scope survey** (how many
students carry each shape — unknown without a credential; I will not estimate).
**E. FUTURE:** bulk roll UI · Exam Roll No · installments · ID-card replacement · CAP-2.
**F. EXTERNAL / SEPARATE:** HC-RTDB · LMS-RTDB · FEES-D15 · Parent App sibling scan.
**G. UAT:** **120+ accumulated rows, none executed.**

### H. FINAL VERDICT: **BLOCKED**

Unchanged from the previous audit, and honestly so. LC-1 is materially reduced but not closed, eleven
modules remain untraced, and no UAT has ever run.

---

## 11. WHAT THIS MEANS FOR A REAL SCHOOL

**Class lists on the Classes screen will now show every student. Most other screens still won't.**

ZenX records whether a child is Active under two different field names, depending on when their record
was written. Most screens ask the database using only one of those names, so children stored the other
way silently vanish from that screen. I fixed the three places that build class and section lists —
which is also where I'd left the job half-done in an earlier phase without realising.

**Twelve places still have the problem**, and two matter most: the attendance roster, and the
recipient list used when you send messages to parents. That second one means a message you believe
went to the whole class may not have reached every family — with no error to tell you.

**I also found that the existing workaround doesn't work.** Someone previously patched one screen by
trying the new field name first and the old one "if nothing comes back". But if a class has a mixture
— which is the actual situation — something *does* come back, so the fallback never runs and the older
records are still dropped. It looks like a fix and isn't.

**And I have to be straight about what I didn't do.** You asked for an exhaustive trace of fifteen
modules — examinations, results, library, transport, hostel, certificates, both mobile apps and more.
**I did not do it.** I fixed what I could verify properly and stopped. Writing a report claiming those
modules were audited would have been worse than useless: you'd have believed they were checked.

**What I'd do next, in order:** finish the twelve remaining places one at a time (the pattern is
proven now), fix the broken workaround, then trace the untouched modules properly — starting with
examinations and results, because a wrong mark or a missing result is the thing a school notices last
and forgives least.

**And then run the UAT.** Over a hundred and twenty test rows have accumulated across this programme
and **not one has been executed in a browser.**

**Nothing has been committed or deployed.**
