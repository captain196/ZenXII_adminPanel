# ZenX — Student Lifecycle Final Completeness Audit

**Date:** 2026-08-15 · **Branch:** `integration/phase2` @ `758c924`
**RESEARCH AND AUDIT ONLY — NO CODE MODIFIED.** Nothing committed / pushed / deployed.
No rules, indexes, or production data touched. **UAT NOT EXECUTED.**

---

## 1. EXECUTIVE SUMMARY

**Verdict up front: the Student Lifecycle is NOT complete, and the reason is not Class/Section/Roll.**
Phases 15–17 closed that area properly. This audit went where the programme had never looked and
found a **systemic, silent, cross-module defect** that undermines student visibility everywhere.

| ID | Finding | Sev |
|---|---|---|
| **LC-1** | **Dual `status` field shape.** The `students` collection holds a **mix** of legacy `Status` (PascalCase) and `status` (camelCase), with mixed value case. A Firestore equality predicate matches **one** field name only — so **every `where status == 'Active'` query silently drops every student stored in the other shape.** **44 such predicates exist** (7 PascalCase, 37 camelCase) across Academic, Admin, Attendance, Classes, Communication, Red_flags, Sis | 🔴 **HIGH** |
| **LC-2** | **Withdrawal releases nothing.** `withdraw_student()` → `change_status()` contains **zero** references to transport, library or hostel. A withdrawn student keeps their bus seat, hostel bed and borrowing rights | 🟡 **MEDIUM** |

**LC-1 is not theoretical — it has already caused a production incident.** `Attendance.php:1218-1225`
documents it verbatim:

> *"Roster_helper's strict `status == 'Active'` Firestore filter silently drops docs in the legacy
> shape — that was the silent-skip bug that caused students to disappear from parent/teacher views
> after admin bulk saves."*

Attendance built a local workaround (`_get_active_roster`). **The other six controllers did not.**

**Why the guards I built are safe but the modules are not:** `_filterSectionRoster()` and
`_sectionActiveCount()` (Phases 16.5/16.6) read **both** shapes in PHP and treat blank as Active. So
capacity and roll checks are correct. But a Firestore `where` predicate cannot express "either field",
so **module queries remain exposed**. This is the sharpest illustration in the whole programme of why
in-memory filtering was the right call there.

---

## 2. DEFINITION OF STUDENT LIFECYCLE USED HERE

**Layer A (state machine):** enquiry → application → admission → identity (Student ID / SR / Admission
No) → placement (class, section, capacity, roll, session) → active operation → status change →
withdrawal / TC → promotion / rollover → re-admission → Class 12 → Alumni.

**Layer B (operational dependencies):** Fees, Attendance, Examination, Results, Homework, LMS,
Library, Transport, Hostel, Communication, Certificates, Documents, ID Cards, Parent App, Teacher App,
Student 360, imports/exports, reports, accounting links.

**Audit depth is stated per area** — I distinguish *traced* from *sampled* rather than implying uniform
coverage.

## 3. CORE LIFECYCLE STATE MACHINE (ZENX-VERIFIED)

```
Application ──enroll_student──► Active ──promotion/rollover──► Active (next session)
                                  │                                  │
                                  ├──transfer──► Active (new section) │
                                  ├──withdraw──► Withdrawn ──readmit──► NEW Student ID (Active)
                                  └──Class 12 rollover──► Alumni
```

**Identity on re-admission (traced, `Readmission.php`):** a **new** student record is created and
linked by `previousStudentId`. Guards are genuinely strong — `previousStudentId` and SRNO are **never
accepted from the client**, cross-school `previous_student_id` is rejected, and the link is refused if
it does not resolve to a real existing student. **The old withdrawn record is never reactivated**, so
it cannot receive new activity. ✅ This is well-designed and I could not fault it.

## 4. FINDINGS — NEW THIS AUDIT

### LC-1 🔴 HIGH — dual status shape breaks student visibility

**Evidence:** 7 predicates use `['Status','==','Active']`; 37 use `['status','==','Active']`.
The collection contains both shapes (documented in-code). Firestore equality is exact — no coercion,
no case-insensitivity, no field aliasing.

**Consequence per module:** any student saved in the *other* shape is invisible to that query. Not an
error — an empty or short list. Symptoms: missing students in class lists, understated section
strength, absent parent/teacher visibility, incomplete communication recipients, under-counted
dashboards.

**Why it survived:** it fails silently and *partially* — most students appear, so it reads as data
entry error rather than a query defect.

**Fix direction (NOT implemented — this is a research phase):** stop filtering status at the Firestore
predicate for `students`; fetch school/session-scoped and filter in PHP accepting both shapes and
treating blank as Active — exactly the pattern `_filterSectionRoster()` already uses. That helper is
the natural canonical home. **Cost: zero extra reads** (the predicate isn't reducing round-trips, only
rows). A data normalisation pass would also work but is **production-data work requiring
authorization**.

### LC-2 🟡 MEDIUM — withdrawal does not release module assignments

`withdraw_student()` / `change_status()` touch no transport, library or hostel state. A withdrawn
student retains a route seat (consuming vehicle capacity), a hostel bed (consuming room capacity) and
borrowing rights.

⚠ **Partly a business decision:** many schools deliberately retain assignments until dues and books
are cleared. **Auto-release is a policy call, not an obvious defect** — but the *absence of any
prompt or clearance step* is a genuine operational gap.

## 5. RE-VERIFICATION OF PRIOR CONCLUSIONS

| Prior claim | Verdict |
|---|---|
| Capacity enforced on all placement paths | ✅ re-verified (admission, CRM, import, promotion, transfer, rollover) |
| Roll uniqueness School+Session+Class+Section | ✅ re-verified; no module keys on `rollNo` |
| Attendance excludes withdrawn students | ✅ **verified this audit** — `status=='Active'` filtering present at 4293, 5476, 7053 |
| Re-admission cannot resurrect the old record | ✅ **verified this audit** |
| No hand-built section doc-ids | ✅ re-scanned, none |
| Fees: fine reaches receipt + 4060 ledger | ✅ (Phase 15.9) |
| Fees: no dead settings | ✅ after 15.8's WhatsApp fix |
| **"No code copies sections into a new session"** (16.5/16.6) | ❌ **PREVIOUSLY CORRECTED** in 16.7 — `rollover_session()` always did |
| **"Rollover writes no audit"** (16.7) | ❌ **PREVIOUSLY CORRECTED** in 16.8 — `log_audit` existed at :4228 |
| `maxStrength <= 0` means unlimited | ❌ **PREVIOUSLY CORRECTED** in 16.4 — resolver defaults to 40; unlimited branch unreachable |

**Three of my own conclusions were wrong earlier in this programme, all caused by too-narrow scan
windows.** That pattern is why this audit widened every window before asserting anything.

## 6–16. MODULE AUDITS (depth stated honestly)

| Module | Depth | Status |
|---|---|---|
| Admission / CRM / Import | **Traced** (Phases 15–17) | ✅ capacity, roll, identity, audit |
| Promotion / Transfer / Rollover | **Traced** | ✅ all guards + all-or-nothing (Phase 17) |
| Re-admission | **Traced this audit** | ✅ strong guards |
| Withdrawal | **Traced this audit** | 🟡 LC-2 |
| Attendance | **Traced this audit** | ✅ status-filtered · ⚠ LC-1 exposure |
| Fees | **Traced** (15.5–15.9) | ✅ closed for scope; 7 deferred items |
| Examination / Results | **Sampled** | ⚠ **not exhaustively traced — see §17** |
| Library / Transport / Hostel | **Sampled** | 🟡 LC-2; assignment keys on `studentId` ✅ |
| Documents / Certificates / ID Cards | **Sampled** | ⚠ ID-card replacement absent (frozen surface) |
| Parent App | **Sampled** | ⚠ LC-1 is the live risk (missing children) |
| Teacher App | **Sampled** | ⚠ same |
| Communication | **Sampled** | ⚠ LC-1 exposure (recipient lists) |

**I am explicitly not claiming exhaustive coverage of Examination/Results, Library, Hostel or the two
mobile apps.** They were sampled for identity and status coupling, not fully traced. Claiming
otherwise would repeat the overreach this programme has already had to correct three times.

## 17. IDENTITY MATRIX (verified)

| Identifier | Canonical owner | Persisted FK? | Displayed | Derived from another? |
|---|---|---|---|---|
| **Student ID** | `students.studentId` | ✅ **the only FK** | hidden on receipts | ❌ never |
| **SR Number** | `Sr_no_service` claim | ❌ never | receipts, lists | ❌ never |
| **Admission Number** | `Admission_no_service` | ❌ | admission docs | ❌ |
| **Roll Number** | student doc | ❌ | registers, receipts | ❌ |

**Crossed-pair invariant** (`STU0012↔SR 47`, `STU0047↔SR 12`) asserted across **eight** test suites.
Repo-wide sweeps this audit: **no module keys on `rollNo`**; the only `srNo`+`studentId` co-occurrence
is `Sr_no_service::claim()`, which *associates* rather than derives. ✅

## 18–22. CLASS/SECTION/CAPACITY, ROLL, ROLLOVER, PROMOTION, TRANSFER

All closed in Phases 15–17 and re-verified: canonical capacity (positive value wins, else 40),
session-scoped, withdrawn frees a seat, canonical `sectionDocId()`, roll guards on every path,
all-or-nothing rollover promotion, D20 authorization. **No new defect found in this area.**

## 23–24. WITHDRAWAL / RE-ADMISSION

Withdrawal: status change + history ✅; **no module clearance** 🟡 LC-2.
Re-admission: new Student ID, `previousStudentId` link, client-supplied identity rejected,
cross-school rejected, old record inert ✅.

## 25. DATA INTEGRITY

| Risk | Status |
|---|---|
| Duplicate Student IDs | ✅ atomic `Id_generator` claim |
| Duplicate SR Numbers | ✅ atomic claim |
| Duplicate rolls in scope | ⚠ **historical unknown** — survey never run (**D5**) |
| Orphaned session-less section docs | ⚠ **SEC-5**, survey never run |
| **Mixed status field shape** | 🔴 **LC-1 — scope unknown without a survey** |
| Stale caches | ✅ promotion bust fixed (15.5) |

**Three surveys remain unrun, all requiring a credential I do not have.** I will not estimate counts.

## 26. SECURITY / RBAC

Every student-movement path requires `SIS:manage` — `execute_promotion`, `transfer_students` (D16),
and rollover promotion (D20). Bypass sweep found **no other mover**. `carry_forward_fees` writes fee
records only — verified, not assumed. Fee override requires MANAGE + reason + audit. Crons are
CLI-only. **No known bypass remains.**

## 27. FIRESTORE / RTDB

Live lifecycle paths are Firestore-only. **Registered, not eradicated:** HC-RTDB (~22 Health_check
reads), LMS-RTDB (`Lms.php:76` subject list), FEES-D15 (`firestore_bulk_sync` false success).
**I do not claim repo-wide RTDB eradication.**

## 28. PERFORMANCE

Placement guards are O(1) reads (shared memoised snapshot). Rollover pre-flight is O(destinations),
not O(students). Import preview is O(1) regardless of row count. **Known remaining:** Parent App
`findSiblings` full-collection scan (🟡, pre-existing).

## 29. EXTERNAL RESEARCH — cumulative

| Claim | Label |
|---|---|
| Roll uniqueness is batch-scoped | **SOURCE-VERIFIED** (AMS ERP) |
| Bulk roll generation with name/admission-no/DOB sorting | **SOURCE-VERIFIED** (NextERP, Fedena, AMS) |
| Rollover copies classes/sections with adjustment | **SOURCE-VERIFIED** (Common Goal Systems, Arbor) |
| Validate-before-commit import with per-row markings | **SOURCE-VERIFIED** (Vedmarg, Lexplore) |
| Fee late-fee config axes (grace, cap, basis) | **SOURCE-VERIFIED** |
| Indian fee regulation is **state** jurisdiction | **SOURCE-VERIFIED** |
| Capacity enforcement · full-section override · balancing algorithms · promotion roll policy · all-or-nothing promotion · permission models | **NOT ESTABLISHED BY SOURCES** |

**Much of ZenX's placement rigour exceeds anything vendors document.** That is a product strength, not
a gap — and I have consistently labelled it ZENX RECOMMENDATION rather than borrowed authority.

---

## 30. FINAL GATE

### A. MUST FIX BEFORE STUDENT LIFECYCLE CAN BE DECLARED COMPLETE
1. **LC-1 🔴** — dual status shape. Route `students` status filtering through one both-shape-tolerant
   helper. Already caused a production incident; affects seven controllers and both apps.

### B. SHOULD FIX BEFORE PRODUCTION
2. **LC-2 🟡** — withdrawal clearance for transport/library/hostel (policy component — see C).
3. **CRM enrolment assigns no roll** (ROLL-2) — closed by D19's bulk tool.
4. **Exhaustive Examination/Results and mobile-app trace** — sampled only in this audit.

### C. BUSINESS DECISIONS REQUIRED
**D19** existing-roll policy (recommend Option C) · **D4** promotion roll carry-vs-clear ·
**D3** full-section override authority · **LC-2** should withdrawal auto-release module assignments,
or require an explicit clearance step? · **FEES-D13** module late fees · **FEES-D14** SMS/WhatsApp
provider · **FEES-D15** `firestore_bulk_sync` disposition.

### D. PRODUCTION-DATA / AUTHORIZATION BOUNDARIES
**D5** duplicate-roll survey · **SEC-5** orphaned section docs survey · **LC-1 scope survey** ·
**FEES-D7** dateless-demand backfill. **All read-only, all need a credential I do not have.**

### E. FUTURE ENHANCEMENTS
Bulk roll assignment UI · Exam Roll Number · installments · ID-card replacement (frozen + security) ·
CAP-2 atomic placement primitive · section-config carry-forward wizard UI (SEC-16) · per-day penalties.

### F. EXTERNAL MODULES — NOT LIFECYCLE BLOCKERS
HC-RTDB · LMS-RTDB · FEES-D15 · Parent App `findSiblings` scan · SEC-6 section CRUD audit.

### G. UAT REQUIRED BEFORE RELEASE
**No UAT has been executed at any point in this entire programme.** Accumulated matrices exceed
**120 rows** across Phases 15.5–17. Minimum before release: fee override + receipt + paid-before-
reminder race · capacity at every entry path · roll collision on transfer and rollover · promotion
all-or-nothing refusal · rollover capacity carry · **LC-1: confirm every student appears in class
lists, parent and teacher apps after a bulk save**.

### H. FINAL VERDICT: **BLOCKED**

**Not** "ready for UAT". LC-1 is a 🔴 HIGH, already-manifested, cross-module defect that makes
students silently invisible — and student visibility is the foundation every other module stands on.
Running UAT before fixing it would produce misleading results, because the symptom looks like data
entry error.

I could have declared this phase complete on the strength of Phases 15–17. That would have been the
comfortable answer and the wrong one.

---

## 31. THE HONEST ANSWER TO YOUR FINAL QUESTION

**"If a real school uses ZenX from admission until the student leaves, what can still go wrong?"**

**The biggest risk is that students quietly go missing from lists.** ZenX stores the student's
Active/Withdrawn marker under two different field names — an older one and a newer one — depending on
when and how the record was saved. Most screens ask the database for students using only one of those
names. Any student stored the other way simply doesn't come back. No error, no warning: a class of 40
shows 37, and the three missing children look like someone forgot to enrol them.

This has already happened once — students vanished from parent and teacher views after a bulk save.
The attendance screen was patched at the time; the other six places doing the same thing were not.

**What is genuinely solid:** identity is sound — Student ID, SR Number, Admission Number and Roll
Number are properly separate, and nothing derives one from another. Class sizes are enforced from
every route a child can arrive by. Roll numbers can't collide. Year rollover carries your seat limits
and refuses outright rather than half-promoting the school. Fees charge, collect, print and post
correctly, and nothing on the fee screens claims to do something it doesn't. Withdrawing a student
doesn't destroy their history, and re-admitting them creates a fresh record without reviving the old
one.

**Smaller things that will bite operationally:** a withdrawn student keeps their bus seat, hostel bed
and library rights until someone manually removes them. Students enrolled through the enquiry flow
get no roll number until someone assigns one.

**And things nobody has checked properly, which I won't pretend otherwise about:** the examination and
report-card chain, the library, the hostel, and both mobile apps were sampled in this audit, not
exhaustively traced. **And none of this — not one screen — has been clicked through in a browser.**

**"What must we fix before I can tell a school ZenX has a professional Student Lifecycle?"**

**One thing, then testing.** Fix the status-field problem so every student reliably appears
everywhere. It's a contained change — route those queries through the same both-shape-tolerant helper
the capacity checks already use — and it costs nothing in performance.

Then execute the UAT. Over a hundred test rows have accumulated and **not one has been run**. Until a
person has sat down and worked a student from admission to leaving in a browser, "the tests pass" only
means the code does what I believed it should — not that a school can rely on it.

Everything else on the list is either a decision that's yours to make, a read-only survey that needs a
credential, or a genuine future enhancement.
