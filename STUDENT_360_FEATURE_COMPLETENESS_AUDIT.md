# Student 360 — Feature Completeness Audit

**Date:** 2026-08-10 · **Branch:** `integration/phase2` · **Nothing implemented — audit only.**
**Benchmark:** PowerSchool, Fedena, MyClassCampus, Teachmint, Entab CampusCare, Skolaro.
**Excluded by instruction:** D-02, D-03, D-04, D-07, D-08, D-09, Gate 6, Gate 8, CERT-001, ATT-001,
SE-003. This document covers **missing capabilities and workflow gaps only.**

---

## METHOD

I did not score Student 360 against a generic vendor checklist — that would produce recommendations
for data this ERP does not hold. Instead I enumerated the **26 modules in the RBAC catalogue**,
established which actually store per-student data in *this* codebase, and checked whether Student 360
surfaces it. Every finding below names the real collection and the real query shape, so each one is
costed rather than aspirational.

**Coverage today: 6 of 26 modules** — SIS, Fees, Attendance, Transport, Hostel, Certificates.

---

## EXECUTIVE ANSWER TO YOUR 7 QUESTIONS

| # | Question | Answer |
|---|---|---|
| 1 | Important operational panel still missing? | **Yes — 3 critical.** Examinations/Results, Discipline, Fee Receipts |
| 2 | Information an admin expects but is absent? | **Yes.** Student photo, sibling linkage, parent-app/login status, guardian contact block |
| 3 | Workflows that force leaving Student 360 unnecessarily? | **Yes — 4.** Every action is a hand-off; nothing is completed in place except entitlements |
| 4 | Duplicate navigation that should be inline? | **Yes — 3.** Fee collection, TC issuance, document upload |
| 5 | Missing cards/widgets/alerts/badges/quick actions? | **Yes.** No alert strip, no risk indicators, no quick-search, no print/export |
| 6 | Cross-module integrations absent? | **Yes — 8 modules** hold per-student data and are entirely unsurfaced |
| 7 | **Single source of operational truth?** | **NO — not yet.** See the verdict |

---

## PART A — MISSING PANELS (cross-module integration)

Every row below was verified to hold per-student data in this codebase.

### 🔴 CRITICAL

**A-1 · Examinations & Results**
*The single largest gap.* An academic ERP's student page without marks is not a 360 view.
Commercial baseline: every benchmark product leads with academic performance.

* **Collections:** `results`, `marks` (`Firestore_helper::RESULTS` / `::MARKS`)
* **Verified shape:** result docs carry `schoolId`, `session`, `studentId`, `examName`, `subjects{}`,
  `totalMarks`, `maxMarks`, `percentage`, `grade`, `rank` as **fields** — not only in the composite
  docId. So this is a **cheap equality query**, identical in shape to every existing panel.
* **Operator need:** "how is this child doing?" — currently unanswerable from Student 360.
* **Note:** `Result.php` already computes a `result_withheld` / `withheld_dues` flag tying results to
  fee dues. Surfacing that next to the dues card is genuinely valuable and unique to this ERP.

**A-2 · Discipline / Red Flags**
* **Endpoint already exists:** `Red_flags::get_student_flags(string $studentId)` — a ready-made
  per-student accessor. It also already computes a **pattern analysis** (type breakdown
  Homework/Behavior/Performance, severity Low/Medium/High, active vs resolved counts).
* **Why critical:** behaviour history is a front-office and counselling need, and it is exactly the
  kind of fact that must not be discovered by opening a different module during a parent meeting.
* **Cost:** among the cheapest wins here — the aggregation is already written.

**A-3 · Fee Receipts / Payment History**
* **Collection:** `feeReceipts`, queried by `studentId` (confirmed in `Fee_summary_writer`).
* **Gap:** the Fees panel shows *balances* but never *what was paid, when, by which mode, and under
  which receipt number*. "When did we last take money from this family, and what was the receipt
  no.?" is one of the most frequent counter questions in any school office, and Student 360 cannot
  answer it.

### 🟡 RECOMMENDED

**A-4 · Library** — `libraryIssues` (filtered `borrowerId == studentId`) + `libraryFines`.
Issued books, overdue status and outstanding fines. Standard in every benchmark product; also a
**TC-clearance dependency** — no-dues normally means library dues too, and Student 360's TC action
currently checks only fee dues.

**A-5 · Homework & Submissions** — `homework` / `submissions`. Per-student submission and pending
counts. Directly complements Discipline, whose largest flag category is "Homework".

**A-6 · PTM History** — `ptmEvents` / `ptmRsvps`. Which meetings the parent attended or skipped.
Standard "parent engagement" widget commercially.

**A-7 · Communication Log** — `messages` / `notices` / `circulars` / `deliveryLogs`.
"What have we actually told this family, and did it deliver?" Every benchmark ERP has a per-student
communication trail. ⚠️ Needs a delivery-target check first: these look broadcast-shaped, so
per-student filtering may not be a clean equality query. **Scope before committing.**

**A-8 · Guardian / Parent-app status** — currently invisible: is the parent app linked, which uid,
last login, notifications reaching them? Today an operator cannot tell whether a family can even
receive what the school sends.

### 🟢 NICE-TO-HAVE

**A-9 · LMS activity** (`Lms.php`, 40 per-student references) — content consumption.
**A-10 · Events participation** — verified **not** per-student today (`Events.php` keys participants by event); would need a schema change first, so it is genuinely future work.
**A-11 · Inventory / Assets issued** — uniforms, tablets, books. **Verified absent:** `Inventory.php` has *zero* per-student references, so this needs a new issue-to-student concept, not a panel.
**A-12 · Medical** — **no module exists.** Health data lives only as a `medical_certificate` document type. A real medical panel (allergies, conditions, emergency contact, nurse visits) is a **new module**, not a Student 360 gap. Flagging because most benchmark ERPs have one and yours does not.

---

## PART B — MISSING INFORMATION ON THE PAGE

### 🔴 CRITICAL

**B-1 · Student photo.** The header shows a coloured initial. `profilePic` exists on the student
record (`Sis.php:3360`) and is simply not rendered. Every benchmark product shows the face — it is
the fastest identity check at a counter, and the cheapest fix in this document.

**B-2 · Emergency contact, prominently.** Guardian phone is buried in the Academic tab as one row
among eighteen. In an emergency an operator should not be opening a tab and scanning a table.
Commercial products pin this to the header.

### 🟡 RECOMMENDED

**B-3 · Sibling linkage.** Verified: **no sibling concept exists anywhere in SIS.** Yet the
entitlement module has a `sibling` concession type — so the school grants sibling discounts with no
system record of who the sibling *is*. Commercially standard, and here it also closes a real audit
hole: you cannot verify a sibling concession against anything.

**B-4 · Session / academic-year switcher.** Student 360 shows only the current session. History,
fees and results are all session-scoped, so past years are unreachable. Benchmark products let you
switch year on the student page.

**B-5 · Admission-pathway provenance.** Which of the three pathways admitted this student, and when.
Cheap (already on the record) and useful for audit.

### 🟢 NICE-TO-HAVE

**B-6 · Age, derived from DOB.** Shown as a date only.
**B-7 · Class-rank / cohort position** — depends on A-1.
**B-8 · Login/device audit** — last parent-app device and login time.

---

## PART C — WORKFLOWS THAT STILL FORCE AN EXIT

Student 360 currently completes **exactly one** workflow in place: entitlement capture and approval.
Every other action is a hand-off. That is defensible for genuinely complex screens; it is not
defensible for the three below, which are single-field operations.

### 🔴 CRITICAL

**C-1 · Fee collection requires leaving.** The most frequent counter workflow — open student, see
₹X due, take payment — currently means navigating to the Fees counter and re-establishing context.
Every benchmark product collects payment from the student page. This is the number-one workflow
argument for a 360 screen existing at all.

### 🟡 RECOMMENDED

**C-2 · Document upload requires leaving.** Uploading one missing Aadhaar means going to
`sis/documents/{id}`. The Documents panel is read-only.

**C-3 · Transport suspend/resume requires leaving.** These are *reason + date-window* operations —
genuinely small. The rail currently sends the operator to the assignments screen to find the student
again. (Deliberate and correct as a first cut; worth revisiting now the panel exists.)

**C-4 · No inline note / remark.** No way to record "spoke to father about dues on 10 Aug".
Universally present commercially, and its absence pushes staff to WhatsApp and paper.

### 🟢 NICE-TO-HAVE

**C-5 · Attendance correction** for a single day. **C-6 · Hostel room change** inline.

---

## PART D — DUPLICATE NAVIGATION THAT SHOULD BE INLINE

| ID | Today | Should be | Class |
|---|---|---|---|
| **D-1** | `fees/fees_counter?uid=` — full page | Inline collection drawer | 🔴 Critical (same as C-1) |
| **D-2** | `certificates/generate` — operator re-selects the student | Pre-scoped, or inline for TC | 🟡 Recommended |
| **D-3** | `sis/documents/{id}` — separate page | Upload inside the Documents panel | 🟡 Recommended |
| **D-4** | `transport/assignments` — operator re-finds the student | Pre-scoped to this student | 🟡 Recommended |

**The common defect in all four: the student context is dropped at the boundary.** Only the two Fees
links carry `?uid=`; certificates, documents and transport hand off to a screen where the operator
must find the same student again. Even where the full workflow stays external, **passing the student
through** is a small, high-value fix.

---

## PART E — MISSING WIDGETS, ALERTS, BADGES, QUICK ACTIONS

### 🔴 CRITICAL

**E-1 · No alert strip.** There is no single place that says *"this student needs attention."*
Today an operator must open tabs to discover an overdue fee, an active discipline flag, a suspended
transport assignment, a pending parent request, or a withheld result. Every benchmark product opens
with an exception banner. **This is the difference between a workspace and a set of tables** — and
the data for most of these is already in the eager context.

### 🟡 RECOMMENDED

**E-2 · No print / export.** No printable student profile, no PDF, no CSV. Standard everywhere;
schools need hard copy for meetings and inspections.
**E-3 · No student switcher.** Handling a queue means going back to the SIS list every time. A
type-ahead in the header is standard.
**E-4 · No attendance sparkline.** The panel is a table; a trend line is how attendance is read.
**E-5 · Badge coverage is uneven.** Parent Requests gets a dot; overdue fees, discipline flags and
suspended transport get nothing. Either badge the exceptions consistently or drop the pattern.

### 🟢 NICE-TO-HAVE

**E-6 · Fee trend chart.** **E-7 · Quick "call parent" / `tel:` link.** **E-8 · Pin/recent students.**

---

## PART F — VERDICT ON QUESTION 7

> **Does Student 360 genuinely qualify as the single source of operational truth for one student?**

**Not yet. It is a strong operational workspace covering the administrative half of the student
record, and it is missing the academic half.**

Honest reading:

* **What it already does well.** Fees, entitlements, transport, hostel, documents, attendance and
  history in one place, with a registry-driven action rail, correct per-panel RBAC, and a read
  budget that does not grow as panels are added. The orchestration architecture is sound — every
  finding above is *content*, not structure, and **each one is a registry row plus a panel, not a
  redesign.** That is the payoff of the architecture holding.
* **Why it falls short of the claim.** A user cannot currently answer *"how is this child doing
  academically?"*, *"has there been a behaviour problem?"*, or *"when did they last pay and what was
  the receipt number?"* Those are not edge cases — they are three of the most common questions asked
  about a student. While they require opening other modules, the "single source of truth" claim is
  not yet true.

**Minimum bar for the claim: A-1 (Results), A-2 (Discipline), A-3 (Receipts), B-1 (photo),
E-1 (alert strip), C-1 (inline collection).** Six items. Four are cheap equality queries against
collections that already carry `studentId`; one is rendering a field that already exists; one is a
real piece of UI work.

---

## SUMMARY

| Class | Count | Items |
|---|---|---|
| 🔴 **Critical** | **9** | A-1 Results · A-2 Discipline · A-3 Receipts · B-1 Photo · B-2 Emergency contact · C-1 Inline fee collection · D-1 (=C-1) · E-1 Alert strip |
| 🟡 **Recommended** | **17** | A-4…A-8 · B-3…B-5 · C-2…C-4 · D-2…D-4 · E-2…E-5 |
| 🟢 **Nice-to-have** | **11** | A-9…A-12 · B-6…B-8 · C-5…C-6 · E-6…E-8 |

**Cheapest high-value items** (existing accessor or existing field — hours, not days):
`B-1 photo` · `A-2 discipline` (endpoint + aggregation already written) · `E-1 alert strip`
(data already in the eager context) · `A-3 receipts` (equality query) · `A-1 results` (equality query).

**Genuinely large:** C-1 inline collection (payment is a real workflow with real money), A-12
medical (a new module, not a panel), B-3 siblings (needs a schema concept that does not exist).

---

## RECOMMENDATION ON SEQUENCING

Student 360's architecture absorbed six panels without structural change, and would absorb these the
same way. But **each new panel is new untested surface**, and the UAT plan is already written against
the current ten.

My recommendation, for your decision — not something I will act on:

1. **Add the six minimum-bar items**, then run the comprehensive UAT once against the complete
   surface. Costs a short build; avoids UAT-ing twice.
2. Or **UAT the current ten now**, treat Part A/B/E as a Student 360 Phase 2, and accept that the
   "single source of truth" claim waits.

I would take option 1 for **A-2, A-3, B-1 and E-1** specifically — they are hours of work against
accessors and fields that already exist, and E-1 in particular changes how the page *reads* rather
than adding another table. A-1 (Results) is the one that most deserves its own careful pass.

**Nothing in this document has been implemented.** Working tree unchanged, flags `false`, Gates 6/8
not begun.
