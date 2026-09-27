# Phase 16.8 — Class/Section Rollover Professional Closure

**Date:** 2026-08-15 · **Branch:** `integration/phase2` @ `758c924`

**IMPLEMENTED. NOT COMMITTED / PUSHED / MERGED / REBASED / DEPLOYED. UAT NOT EXECUTED.**
No production data touched. `firestore.rules` / `firestore.indexes.json` **NOT modified**.
**No Fees code touched.** No RTDB. No bulk roll assignment. No full-section override. No AI placement.

---

## 1. EXECUTIVE SUMMARY

Searching before building — as Parts 1 and 4 required — **withdrew one of my own findings and caught
a bug that would have silently stored nothing.**

| ID | Outcome |
|---|---|
| **SEC-14** | ⚠ **WITHDRAWN — FALSE FINDING.** Rollover **has always been audited**. `log_audit('Configuration','rollover_session',…)` sits at `School_config.php:4228`. My Phase 16.7 grep window stopped at ~3960 and missed it. **Second time a too-narrow scan window produced a false finding in this programme.** |
| **SEC-14b** | ✅ **FIXED** — the real weakness: the summary was JSON-stuffed into the free-text *description*, readable by a human but not queryable. Now structured. |
| **SEC-16** | ✅ **IMPLEMENTED** — the operator can edit target capacity during rollover, applied to **newly created sections only**. |
| **D20** | ⛔ **RESEARCHED — recommendation given, NOT implemented** (§9). |

**And a verification catch worth stating plainly:** `log_audit()` persists **only** `appId`,
`entityType` and `meta` from its `$context` argument — every other top-level key is **silently
discarded**. My first version passed a flat payload. It would have looked entirely correct, passed
lint, passed tests, and **stored nothing.** I checked the helper before trusting it, nested the
payload under `meta`, and wrote a regression test that asserts a flat payload persists nothing.

**Tests: 1027 run, 10 failures — identical to the 798/10 baseline. Zero new failures. +18 new tests.**

---

## 2. PHASE 16.7 FINDINGS RE-VERIFIED

| 16.7 claim | Verdict |
|---|---|
| `rollover_session()` / `preview_rollover()` are canonical and pre-existing | ✅ confirmed |
| Capacity now carried (SEC-13) | ✅ confirmed in source |
| Existing target sections skipped, never overwritten | ✅ confirmed |
| Preview exposes capacity + target state | ✅ confirmed |
| No hand-built section doc-ids remain | ✅ re-scanned — none |
| Idempotent via `rolloverIntents` | ✅ confirmed |
| **"Rollover writes no audit record"** | ❌ **WRONG — see §7** |

## 3. EXTERNAL ERP RESEARCH

Carried forward from Phase 16.6, re-stated with labels; no new sources were needed because the
implementation question was answered by ZenX source.

| # | Question | Finding | Label |
|---|---|---|---|
| 1–2 | Rollover copies classes and sections | *"copy classes and sections over from one year to the next"* | **SOURCE-VERIFIED** ([Common Goal Systems](https://commongoalsystems.zendesk.com/hc/en-us/articles/360004135072-How-do-I-roll-over-to-the-next-academic-year)) |
| 3 | Admin may modify before confirming | *"and make adjustments as needed"* | **SOURCE-VERIFIED** |
| 9 | Preview / guided multi-step setup | numbered "New School Year Setup" steps | **SOURCE-VERIFIED** ([Arbor](https://support.arbor-education.com/hc/en-us/articles/360007719277-New-School-Year-Setup-Step-6-Registers-for-Secondaries)) |
| — | Structure before promotion | copy structure, then promote | **SOURCE-VERIFIED** |
| **4–6** | **Capacity editable / auto-copied during rollover** | **NOT ESTABLISHED BY SOURCES** — no vendor documents section capacity in a rollover flow at all | — |
| 7–8 | Behaviour when the target section already exists | **NOT ESTABLISHED** | — |
| 10–12 | Add/remove sections during rollover; per-section capacities; preserve source | **NOT ESTABLISHED** | — |
| 13 | Who may perform rollover | **NOT ESTABLISHED** | — |
| 14 | Reversible? | **NOT ESTABLISHED** | — |
| 15 | Audit trail for rollover | **NOT ESTABLISHED** externally; **ZENX-VERIFIED present** | — |

**Honest consequence:** the "adjust before confirming" principle behind SEC-16 **is** source-verified;
applying it *specifically to capacity* is a **ZENX RECOMMENDATION**, not an industry standard, and is
labelled as such in the code.

## 4. ZENX ARCHITECTURE (Part 1 — searched before building)

| Concern | Canonical owner | New architecture created? |
|---|---|---|
| Rollover / preview | `School_config::rollover_session` / `preview_rollover` | **No** — extended in place |
| Session list / active | `schools.sessions[]` / `currentSession` | No |
| Section identity | `Firestore_service::sectionDocId()` | No |
| Capacity resolution | `MY_Controller::_sectionCapacity()` (D17) | No |
| **Administrative audit** | **`helpers/audit_helper.php::log_audit()` → `auditLogs`** + `Audit_log_service` | **No — it already existed** |
| Student history | `Student_history_service` | **Deliberately NOT used** — rollover is an admin event with no single student |

**No new service, collection, controller, permission or audit system was created.**

## 5. SEC-16 — RESEARCH AND DECISION (Part 3)

Answering your explicit questions:

| Q | Decision |
|---|---|
| **A** New sections receive the edited capacity? | **Yes** |
| **B** Target exists with different capacity? | **Skip and report.** Preview already labels it `EXISTS_DIFFERENT_CAPACITY`; rollover does not touch it |
| **C** Should rollover update an existing target? | **No** — that is Section Settings' job. Rollover must never overwrite a value the operator already set for next year |
| **D** Valid values | non-negative **integers**. `0` = unconfigured (resolver applies 40). Negative, decimal, blank and text are **rejected with an error** |
| **E** Min / max | **No arbitrary bounds invented.** No source establishes a maximum section size; imposing one would be a fabricated business rule. `500` is accepted |

**Nothing destructive was designed.** The edit reaches only sections being *created*.

## 6. SEC-16 — IMPLEMENTATION

Input `section_capacities[<className>|<section>] = <int>`, keyed on the same canonical strings the
preview returns, so an operator can only address sections the preview actually offered — an unknown
key annotates nothing and **cannot create a section**. Matching is case-insensitive. Applied at the
copy site, after the source capacity is read, **before** the document is written. `capacities_edited`
is counted into the summary and surfaced in the audit.

## 7. SEC-14 — RESEARCH AND DECISION

**Part 4 asked me to search the repository before inventing anything. I did, and the facility exists:**
`log_audit($module, $action, $entityId, $description, $context)` writing to `auditLogs`, already used
**30 times in this same controller**. It supplies actor id, actor name, actor role, tenant, IP and
timestamp automatically.

**`rollover_session()` was already calling it** (line 4228) with status and the full summary.
**My Phase 16.7 report was wrong to say otherwise**, and the cause was a grep bounded to lines
3610–3960 while the call sits at 4228.

**Measured against your minimum-useful-record list:** actor ✅ · actor name ✅ · tenant ✅ ·
timestamp ✅ · action type ✅ · source/target session ✅ · result ✅ · sections created ✅ ·
sections skipped ✅ · **capacity changes ✅ (new)** · promotion requested ✅ · students affected ✅ ·
success/failure ✅. No sensitive data stored.

## 8. SEC-14b — IMPLEMENTATION (the genuine weakness)

The summary was `json_encode`'d into the description — human-readable, not queryable. It now goes
into the structured `context`, **nested under `meta`** because that is the only place `log_audit()
persists arbitrary keys.

> **This is the catch worth repeating:** my first version used flat top-level keys. `log_audit()`
> would have discarded every one of them. Lint, tests and review would all have passed while the
> audit stored nothing — the exact "looks correct, does nothing" defect class this programme has
> spent phases unwinding. Verified against the helper, corrected, and pinned by a test that asserts a
> flat payload persists **nothing**.

## 9. D20 — RESEARCH AND RECOMMENDATION (NOT IMPLEMENTED)

**External: NOT ESTABLISHED BY SOURCES** — no vendor documents how academic-year setup, class/section
configuration, student promotion and bulk movement are split across permissions.

**ZenX today (ZENX-VERIFIED):**
`rollover_session` → `Configuration:manage` · `preview_rollover` → `Configuration:view` ·
section transfer → **`SIS:manage`** (moved there deliberately in Phase 16.1, D16).

| Option | Consequence |
|---|---|
| **A** Structure under Configuration; promotion requires `SIS:manage` | Matches D16's reasoning: *moving students* is a student-lifecycle act. **Requires splitting one endpoint across two permissions** — a caller holding only Configuration would lose an ability it has today |
| **B** Keep both under Configuration | No change, no regression risk; leaves the inconsistency with D16 |
| **C** A dedicated "Session Rollover / Promote Students" permission | Cleanest conceptually; **invents a new permission**, which Part 8 tells me not to do without proving necessity |
| **D** — | no evidence-supported alternative found |

**ZENX RECOMMENDATION: Option A** — but **not implemented**, deliberately. It is an authorization
contract change: any role that currently performs rollover-with-promotion but lacks `SIS:manage`
would start failing, and I cannot enumerate your live role assignments from here. **That is an
authorization regression risk I will not take unilaterally.** One word from you and it is a two-line
change.

**Interim mitigation already in place:** the audit now records `promotionRequested` and
`studentsPromoted`, so a Configuration-only actor promoting students is at least **visible**.

## 10. CONNECTED DEFECTS — FOUND / FIXED / STOPPED

**Fixed:** SEC-14b (audit not queryable) · SEC-16 (capacity not editable) · the flat-context bug I
introduced and caught before it shipped.
**Withdrawn:** SEC-14 (false finding — audit exists).
**Stopped at boundary:** D20 (authorization contract change).
**Re-verified clean:** hand-built section doc-ids ✅ none · wrong-session reads/writes ✅ ·
cross-school leakage ✅ · overwrite protection ✅ · idempotency ✅ · RTDB ✅ none in touched regions.

## 11. LIFECYCLE / CAPACITY MATRIX (Part 7 re-verified)

| Path | Class | Section | Session | Capacity source | Withdrawn frees seat | Old session counted |
|---|---|---|---|---|---|---|
| Direct admission | ✅ | ✅ | ✅ | `_sectionCapacity()` | ✅ | ✅ no |
| CRM enrolment | ✅ | ✅ | ✅ | `_sectionCapacity()` | ✅ | ✅ no |
| Bulk import | ✅ | ✅ | ✅ | `_sectionCapacity()` | ✅ | ✅ no |
| Re-admission | ✅ | ✅ | ✅ | inherited | ✅ | ✅ no |
| Promotion | ✅ | ✅ | **target session** | `_sectionCapacity()` | ✅ | ✅ no (16.5) |
| Section transfer | ✅ | ✅ | ✅ | `_sectionCapacity()` | ✅ | ✅ no (16.5) |
| **Session rollover** | structure only | structure only | **target session** | **carried + editable** | n/a | n/a |

**No path bypasses the canonical validator.**

## 12. ROLL NUMBER / IDENTITY (Parts 8, 9)

Rollover copies **structure only** — asserted by test that no copied document contains `studentId`,
`rollNo` or `srNo`, and `studentCount` starts at 0. **Rollover cannot create a duplicate roll, and
cannot change any existing student's roll.** Uniqueness remains School+Session+Class+Section;
Suggest remains advisory with its 30-minute TTL; Student ID remains the canonical persisted identity;
SR Number is never derived from Student ID nor Roll from SR Number. **No automatic roll numbering
introduced. No student silently renumbered.**

**Tenancy:** source and target both validated against `schools.sessions[]` for the current school;
every doc-id carries the `{schoolId}_` prefix; `from === to` rejected; format validated
`^\d{4}-\d{2}$`. Cross-school collision asserted impossible by test.

## 13. PERFORMANCE (Part 10)

| Operation | Reads | Writes |
|---|---|---|
| Preview | 3 bulk (source sections, source students, target sections) | 0 |
| Rollover copy | 2 bulk (source + target sections) | 1 per **new** section |
| **Capacity editing** | **0 extra** — parsed from POST, applied in memory | 0 extra |
| **Audit** | **0 extra reads** | **1 write, once per rollover** |

**No N+1.** Target sections are indexed from one bulk query. The audit is a **single** write for the
whole operation — deliberately not per section or per student. **No new index required.**

## 14. TESTS

```
PRE-PHASE BASELINE:  1009 tests, 2941 assertions, 10 failures
AFTER 16.8:          1027 tests, 2996 assertions, 10 failures
NEW TESTS: 18         NEW FAILURES: 0
LINT: clean · RTDB (touched regions): 0 · RULES/INDEXES: untouched
```
Pre-existing failures unchanged and **not mine**: Backup ×1, Homework ×5, ParentHomeworkRepo ×3,
TeacherDeleteCascade ×2. **This is not "all tests pass".**

Coverage: edited capacity applied · unedited keep source · case-insensitive keys · resolver reports
the edited value · `0` accepted as unconfigured · negative/decimal/text rejected · **no invented upper
bound** · unknown key cannot create a section · **existing target never edited or overwritten** ·
second rollover idempotent with overrides · source session never written · schools cannot collide ·
**flat audit context persists nothing** · nested `meta` survives · status recorded so a partial run
isn't reported as success · structure-only vs promotion distinguished · no student identity copied ·
identity invariants.

## 15. UAT MATRIX — ALL PENDING

| # | Test | Expected | Status |
|---|---|---|---|
| 1 | 5-A=30, 5-B=40, 5-C=50 in 2025-26 | Saved | **UAT PENDING** |
| 2 | Preview → 2026-27 | Capacities + `NEW` shown | **UAT PENDING** |
| 3 | **Edit 5-C to 45, confirm** | Target 5-C = **45**, others 30/40 | **UAT PENDING** |
| 4 | Section Settings in 2026-27 | Shows 30 / 40 / 45 | **UAT PENDING** |
| 5 | Admit into 2026-27 5-C | **Blocks at 45** | **UAT PENDING** |
| 6 | **2025-26 unchanged** | Still 30 / 40 / **50** | **UAT PENDING** |
| 7 | Enter `-5` / `30.5` / `abc` | Rejected with a clear message | **UAT PENDING** |
| 8 | Enter `0` | Stored unconfigured; resolver gives 40 | **UAT PENDING** |
| 9 | Target 5-A already exists; edit it to 99 | **Skipped, NOT overwritten** | **UAT PENDING** |
| 10 | Run rollover twice | Second copies 0 | **UAT PENDING** |
| 11 | **Check `auditLogs`** | One entry, actor, sessions, `meta.capacitiesEdited` | **UAT PENDING** |
| 12 | Failed/partial rollover | Audit status `partial`/`failed`, not success | **UAT PENDING** |
| 13 | Structure-only rollover | `promotionRequested: 0`, roster unchanged | **UAT PENDING** |
| 14 | Non-Configuration user | Refused | **UAT PENDING** |
| 15 | **Confirm no roll numbers changed** | Rolls untouched | **UAT PENDING** |

**Rows 3, 6, 9 and 11 are the critical ones. No browser or device UAT has been executed at any point.**

## 16. FILES CHANGED · GIT STATUS

```
Branch: integration/phase2      HEAD: 758c924   (unchanged — no commit)

Modified this phase:  application/controllers/School_config.php
New this phase:       tests/Unit/Phase168RolloverCapacityEditorAndAuditTest.php

firebase-rules/*  .... dirty BEFORE this programme — NOT touched
Fees files ........... dirty from Phases 15.5–15.9 — NOT touched this phase
~450 other entries ... pre-existing work — untouched

COMMIT: none   PUSH: none   MERGE/REBASE: none   DEPLOY: none
```

## 17. DEFERRED WORK REGISTER

**Fees (verified intact, untouched):** FEES-D7 · FEES-D9 · FEES-D13 · FEES-D14 · FEES-D15 ·
FEES-FROZEN-1 · FEES-UAT.

**Class/Section/Roll:** **D20** (rollover promotion RBAC — recommendation ready) · SEC-5 (orphaned
session-less section docs — survey) · SEC-6 (section CRUD audit — note `log_audit` exists, so this is
now small) · ROLL-1/2 (**D19**) · ROLL-3 (**D5**) · ROLL-4 (**D4**) · SEC-1 (**D3**) · ROLL-6 (closed
by analysis) · CAP-2 (atomic primitive) · EXAM-1 · HC-RTDB · LMS-RTDB.

## 18. FINAL STOP CONDITION — YOUR TEN QUESTIONS

1. **Carry 30/40/50 safely into the next session?** ✅ Yes (16.7 + this phase). *UAT pending.*
2. **Adjust next year's capacity during rollover without touching the source?** ✅ Yes — writes are
   session-keyed to the target; asserted by test.
3. **Existing target protected from silent overwrite?** ✅ Yes — skipped and reported, never edited.
4. **Trustworthy administrative record of who rolled over?** ✅ Yes — and it **already existed**;
   it is now queryable.
5. **Can a Configuration admin promote students without student-lifecycle permission?** ⚠ **Yes,
   still.** Recommendation is Option A; **not implemented** because it is an authorization contract
   change (§9). It is now at least **audited**.
6. **Can any rollover path create duplicate rolls?** ✅ No — structure only, no student data written.
7. **Can any rollover path mix students or capacity across sessions?** ✅ No — session-keyed
   doc-ids, session-filtered reads, `from === to` rejected.
8. **Is Student ID still canonical?** ✅ Yes.
9. **Is SR Number still independent?** ✅ Yes.
10. **Any new HIGH/CRITICAL lifecycle defect blocking bulk roll assignment?** ✅ **None.** The
    remaining items are decisions (D19, D4, D3, D20) and surveys (D5, SEC-5), not correctness defects.

---

## 19. WHAT THIS MEANS FOR A REAL SCHOOL

**You can now change next year's class sizes while setting up the new session, instead of afterwards.**

Previously the rollover carried last year's seat limits forward exactly as they were. If 5-C had 50
seats but you wanted 45 next year, you had to roll over first and then go back and edit it — and in
between, the wrong limit was live and being enforced on admissions. Now you can adjust it in the
preview before confirming.

Two safety rules are built in. Your **current** year is never touched — the edit only ever writes next
year's records. And if next year's section **already exists** because you set it up earlier, rollover
leaves it completely alone and tells you it differs, rather than quietly replacing what you chose.

**I have to correct something I told you last time.** I reported that rollover kept no record of who
ran it. That was wrong — it has always written an audit entry, and I simply searched the wrong stretch
of the file. What *was* genuinely weak is that the details were bundled into one long text line, so
you could read them but not search or count them. That's now stored properly, including how many
capacities you edited.

**And one thing I nearly got wrong myself.** When improving that audit record, my first version passed
the details in a way the audit system quietly ignores — it would have looked perfectly correct, passed
every test, and saved nothing. I checked how the audit helper actually works before trusting it,
fixed it, and added a test that fails if anyone reintroduces that mistake. That pattern — code that
looks right and does nothing — is the same one we found in fees reminders and the WhatsApp button, so
it seemed worth guarding against permanently.

**One thing still needs your decision.** The rollover button can optionally promote every student, and
it currently only requires general "Configuration" permission — while moving students between sections
requires student-records permission. I'd recommend aligning them, but changing it means someone who
can do this today might suddenly be blocked, and I can't see your staff's actual roles from here. In
the meantime, the audit now records whether students were promoted and how many, so it's at least
visible.

**Nothing has been committed or deployed, and none of this has been clicked through in a browser.**
The most important thing to try is editing a capacity in the preview, confirming, and then checking
that last year's figure is untouched.
