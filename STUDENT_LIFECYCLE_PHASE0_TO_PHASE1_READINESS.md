# Phase 0 → Phase 1 Readiness — Consolidated Findings & Remediation Roadmap

**Date:** 2026-08-13 · **Status: PLANNING / DISCOVERY ONLY. Nothing implemented.**
**Source of truth:** `STUDENT_LIFECYCLE_FORENSIC_AUDIT_PHASE0.md` + one additional verification
performed in this pass (`functions/index.js:575` — confirmed the `fee_generation_worker` require is
commented out; G12 retirement is now VERIFIED, not merely claimed).

**Working tree: HELD.** `git status` shows a large pre-existing set of unrelated modified files from
other concurrent workstreams (Transport probes, HR, Payroll, config, etc.) — none of these were
touched, read for content, or staged. Nothing in this document changed code, data, rules, indexes,
RBAC, or committed/staged/pushed anything.

---

## A. Executive Decision

**GO WITH CONDITIONS.** ZenX should not begin Student Trail implementation today, but the blockers
are narrow and mostly independent of each other — not a systemic rebuild. Three findings are true
blockers (SL-13, SL-36, and the canonicalization bypass SL-12); everything else can either be fixed
in parallel with early Student Trail scaffolding or deliberately deferred without corrupting what
Student Trail would show. See Part X (§27) for the full reasoning.

---

## B. Master Findings Register

ID scheme: existing IDs from the Phase 0 audit are preserved verbatim (`RES-001`, `CERT-001`,
`RF-001`). `ATT-001` was **referenced but never independently investigated** by this audit's own
agents — it originated in prior-session narrative pasted into this conversation, not in repo
evidence gathered here. It is carried forward as **NOT VERIFIED**, not asserted. All new findings
use a fresh `SL-##` (Student Lifecycle) series to avoid colliding with any ID scheme a different
workstream may already be using.

| ID | Finding | Domain | Severity (see Part D) | Type | Affected Modules | Affected Identifiers | Evidence | Financial | Academic | Security | Trail Impact | Dependency | Phase |
|---|---|---|---|---|---|---|---|---|---|---|---|---|---|
| SL-01 | Enquiry stage bypassed by primary public intake channel | Admission | LOW | G. Cross-module consistency | Sis.php, Admission_public.php | Application Number (indirect) | `Admission_public.php:279-570` never writes `crmInquiries` | No | No | No | Understates funnel history | Independent | 6 |
| SL-02 | Two divergent Application-id generators | Admission | MEDIUM | G / B. Data integrity | Sis.php:6256-6284, Admission_public.php:400-401 | Application Number | Confirmed dual scheme | Low (collision risk) | No | No | Application-id shape inconsistent in Trail events | Independent | 2 |
| SL-03 | Two divergent duplicate-detection policies for `crmApplications` | Admission | LOW-MEDIUM | G | Sis.php:8375-8386, Admission_public.php:335-393 | Application Number, phone | Confirmed | Low | No | No | No | Independent | 6 |
| SL-04 | No retention/purge for rejected/waitlisted applications (PII incl. Aadhaar scans) | Admission | MEDIUM-HIGH | C. Security / compliance | Sis.php, crmApplications | Application Number | Confirmed, no TTL logic found | No | No | Yes (PII retention) | No | Independent | 5 |
| SL-05 | Firebase Auth creation is non-fail-loud in `enroll_student` | Student Creation | INFORMATIONAL | N (accepted design, not a defect) | Sis.php:7817-7918 | Student ID | Deliberate, has `repair_student_auth` | No | No | No | No | — | — |
| SL-06 | Dual status field names (`status`/`Status`), fail-open default to Active | Active Student | MEDIUM | B. Data integrity | Sis.php, Attendance.php | Student ID | Confirmed pattern across read sites | No | Possible (roster leak, see SL-07) | Possible | Trail must resolve one canonical status field | Blocks Trail identity-state read | 1 |
| SL-07 | Attendance roster Strategy-2 fallback has no status filter (confirmed leak) | Active Student / Attendance | MEDIUM-HIGH | A. Production defect | Attendance.php:5430-5439 | Student ID | Confirmed inconsistency vs sibling routine at 7017-7039 | No | Yes (wrong roster) | No | Corrupts attendance events Trail would read | Independent | 2 |
| SL-08 | Promotion audit trail is a generic history-log line, no dedicated collection | Promotion | LOW-MEDIUM | F. Historical traceability gap | Sis.php:1525 | Student ID, session | Confirmed, no `promotionHistory` collection exists | No | Low | No | Trail needs a structured promotion event, not free text | Hard dependency for Trail | 7 |
| SL-09 | Promotion fee regeneration deferred post-response; failure invisible to operator | Promotion / Fees | HIGH | D. Financial correctness | Sis.php:1644-1700 | Student ID, session | Confirmed `register_shutdown_function` pattern | Yes | No | No | Trail would show "promoted" before fees actually regenerated | Independent | 3 |
| SL-10 | No dedicated detention code path or audit trail | Promotion | LOW | I. Missing feature | Sis.php (absence) | Student ID | Zero grep matches for "detain" | No | Low | No | Trail cannot distinguish detention from other non-promotion | Independent | 7 |
| SL-11 | No standalone mid-session class/section change; `edit_student` silently discards submitted Class/Section | Class/Section | MEDIUM | A. Production defect (silent discard) | Sis.php:5574 | Student ID | Confirmed | No | Medium (misroutes to wrong roster if operator believed it worked) | No | No | Independent | 4 |
| SL-12 | Promotion/`batchMoveStudents` bypass `normalizeClassSection` — canonical shape drift | Class/Section | **HIGH** | G. Cross-module consistency / B | Sis.php, Dual_write.php, Firestore_service.php | Student ID, class/section key | Confirmed live (not hypothetical) | Indirect (fee-structure lookups keyed by class) | Yes (student invisible to canonical-key queries) | No | Directly corrupts what Trail/other modules can find for the student | **Blocker for reliable cross-module Trail reads** | 1 |
| SL-13 | Mid-year admission billed for months before join date | Fees | **CRITICAL** | D. Financial correctness | Fee_lifecycle.php:220-350, Fee_generation_service.php | Student ID | Confirmed, zero date logic in function | **Yes — active overbilling** | No | No | Every affected student's financial Trail is wrong from day one | **Blocker for trustworthy financial Trail** | 3 |
| SL-14 | Transport-suspension proration wrapped in try/catch, failure silently swallowed | Fees / Transport | MEDIUM | A. Production defect (residual) | Transport_service.php:2763-2809 | Student ID | Confirmed (fix present, failure path unguarded) | Yes (residual) | No | No | No | Independent | 3 |
| SL-15 | G12 Cloud Function race (fee_generation_worker.js) | Fees | ~~MEDIUM (pending)~~ **CLOSED — VERIFIED** | K. Dead code (confirmed) | functions/index.js:575 | — | **Verified this pass**: require line commented out | No (confirmed closed) | No | No | No | — | — |
| SL-16 | Three non-unified discount/concession/entitlement mechanisms | Fees | HIGH | H. Architectural gap | Fee_generation_service.php, Fees.php, Entitlement_service.php | Student ID | Confirmed, generation-time vs receipt-time divergence | Yes | No | No | Trail cannot show one authoritative "why was this discounted" | Hard dependency for financial Trail | 3 |
| SL-17 | RES-001 — `Result.php` reads `result_withheld` from RTDB (3 sites) | Results | HIGH | J. Legacy dependency | Result.php:314,451,1697 | Student ID | Confirmed, not touched by 758c924 | No | Yes (dual source of truth vs feeDefaulters) | No | Trail results panel would need to pick a side | Independent, but must resolve before Results enters Trail | 4 |
| SL-18 | Report cards source "Roll No" from `User Id`, not `RollNo` | Results | MEDIUM | A. Production defect | `_report_card_data.php:26`, 7 templates | Roll Number | Confirmed | No | Yes (wrong data on official document) | No | No | Independent | 4 |
| SL-19 | `result_withheld` has zero downstream enforcement (promotion/TC don't check it) | Results | LOW-MEDIUM | N. Design decision required | Sis.php (absence) | Student ID | Confirmed, zero references | No | Possible (business intent unclear) | No | Design question, not a bug | Independent | — (decision first) |
| SL-20 | Hostel fee linkage best-effort, can fail silently | Hostel | MEDIUM | D. Financial correctness | Hostel.php:359-372 | Student ID | Confirmed | Yes | No | No | No | Independent | 3 |
| SL-21 | TC issuance does not release hostel bed | Hostel / Exit | MEDIUM | G. Cross-module consistency | Sis.php:1931, Hostel.php | Student ID | Confirmed, no call found | No | No | No | Trail would show TC issued while hostel shows occupied | Independent | 5 |
| SL-22 | Hostel absent from `functions/rbac_modules.json` mirror | Hostel / RBAC | LOW | O. Not verified (drift or intentional) | functions/rbac_modules.json | — | Grep returned no match; cause not determined | No | No | Possible (module gate mismatch) | No | Independent | 6 |
| RF-001 | `Red_flags::get_student_flags` performs whole-school scan instead of studentId-scoped query | Discipline | MEDIUM | L. Performance issue | Red_flags.php:143-292,619-699 | Student ID | Confirmed, ~1000-doc worst case | No | No | No | Trail's discipline panel inherits this cost per lookup | Independent | 6 |
| SL-23 | Discipline flags shown to parents with no field-level redaction | Discipline | LOW-MEDIUM | N. Design decision required | firestore.rules:2640-2691 | Student ID | Confirmed as-designed; intent not documented | No | No | Possible | No | Independent (decision first) | — |
| CERT-001 | Certificate storage is 100% RTDB | Certificates | HIGH | J. Legacy dependency | Certificates.php:519 | Student ID | Confirmed | No | No | No | **Certificates cannot enter Trail until this moves to Firestore** | Hard dependency for Trail's certificate panel | 4 |
| SL-24 | Admission Number has no writer; Certificates print fabricated/blank value | Certificates / Identity | HIGH | A. Production defect | Certificates.php:384,460 | Admission Number, SRNO (future) | Confirmed, no writer anywhere | No (reputational/legal) | No | No | Every historical TC's printed register number is unreliable | Independent, but SRNO design (SL-37) is the correct long-term fix | 2 |
| SL-25 | No audit log on certificate generation; no reprint-vs-original distinction | Certificates | MEDIUM | F. Historical traceability gap | Certificates.php:402-520 | Student ID | Confirmed | No | No | No | Trail cannot show accurate certificate history | Independent (blocked by CERT-001 for full fix) | 4 |
| SL-26 | Parent/child linkage is per-student only; `childrenIds[]` multi-child model is aspirational-only in code comments | Parent/Family | LOW-MEDIUM | M. UX/presentation (misleading docblock) + H | Entity_firestore_sync.php:400-465 | Student ID | Confirmed | No | No | No | No | Independent | 6 |
| SL-27 | Sibling relationship not structurally represented — free-text `concessionType` only | Parent/Family / Fees | **HIGH** | C. Security risk (fraud vector) | Entitlement_service.php:69,132 | Student ID | Confirmed, zero relational field anywhere | Yes (unverifiable discount basis) | No | Yes | Trail cannot show a verifiable "why" for sibling concessions | Independent, but should land with SL-16 | 3 |
| SL-28 | SMS/email delivery status fabricated (marked sent without gateway call) | Communication | MEDIUM-HIGH | A. Production defect | Communication.php:1391-1404 | Student ID (indirect, recipient) | Confirmed, explicit placeholder comment | No | No | No | Trail would show false "notified" events | Independent | 6 |
| SL-29 | Per-student communication not retrievable from any per-student UI | Communication | LOW | I. Missing feature | Communication_helper.php | Student ID | Confirmed | No | No | No | Trail needs this; currently would require a new read path | Independent | 7 |
| SL-30 | No `Student_lifecycle_service`; withdraw/delete/change_status are 3 unconsolidated methods | Exit | LOW | H. Architectural gap | Sis.php (multiple) | Student ID | Confirmed, zero grep hits for the proposed service | No | No | No | No (refactor-only) | Independent | 7 |
| SL-31 | TC issuance does not freeze fees (only withdraw/delete do) — **CONFLICTING EVIDENCE**, see note below | Exit | LOW | N. Design decision required (resolved in favor of repo evidence) | Sis.php:1931-2075 | Student ID | Confirmed via repo read; contradicts a prior-session claim | Low | No | No | No | Independent | — |
| SL-32 | No Alumni/Graduated status — top class has no distinct exit path | Exit | MEDIUM | I. Missing feature | Sis.php (absence) | Student ID | Confirmed, zero matches beyond a comment | No | Low | No | Trail cannot represent "graduated" as distinct from "left" | Independent | 7 |
| SL-33 | `recoverStudent()` implemented, zero callers — restore unreachable in practice | Archive/Restore | MEDIUM-HIGH | K. Dead/unreachable code | Dual_write.php:470-492 | Student ID | Confirmed, no route/view surfaces it | No | No | No | Every soft-delete is de facto permanent from an operator's standpoint | Independent | 5 |
| SL-36 | Re-admission creates new Student ID with no lookup/link to prior record | Re-admission / Identity | **CRITICAL** | F. Historical traceability gap | Sis.php:7486 (`_nextStudentId`) | Student ID | Confirmed, zero dedup logic in enrollment path | No (direct) | No (direct) | No | **The single largest blocker to a trustworthy Trail** | **Blocker** | 2 |
| SL-37 | SRNO not implemented (design-complete, code absent) | Identity | MEDIUM | I. Missing feature (planned capability) | — | SRNO | Confirmed design-only | No | No | No | Trail's human-facing lookup key doesn't exist yet | Independent of Trail's data model, but operator-facing UX depends on it | 2 |
| SL-38 | Admission Number / SRNO conflation risk — Admission Number currently stands in for the missing SRNO with no writer | Identity | (folded into SL-24/SL-37) | H | Certificates.php | Admission Number, SRNO | Cross-ref | — | — | — | — | — | 2 |

---

## C. Critical Findings — MUST ADDRESS BEFORE STUDENT TRAIL

### SL-13 — Mid-year admission tuition proration is completely absent

1. **Exact problem:** `buildAdmissionDemandSpecs()` bills every month in the fee chart for every
   admitted student, with no reference to when that student actually joined.
2. **Evidence:** `Fee_lifecycle.php:220-350` — full-function grep for `admissionDate|admission_date|
   joinDate|dateOfAdmission|DOJ` returns zero hits. Caller signatures (`Sis.php:988,4760,7863`,
   `Fee_lifecycle.php:587`) pass only `studentId, class, section` — there is no date parameter to
   filter on even if the function wanted to.
3. **Affected modules:** Fees (Sis.php enrollment path, Fee_generation_service.php unified path —
   both call the same unfiltered function).
4. **Affected collections:** `feeDemands`.
5. **Affected identifiers:** Student ID (the demand is correctly attributed to the right student —
   the defect is *when*, not *who*).
6. **Why Critical:** This is not a one-time historical defect — it is a live code path that fires on
   every mid-year admission today. It directly produces incorrect financial demands, which then
   propagate into receipts, accounting journals, defaulter status, and (per SL-17/SL-19) potentially
   result-withholding.
7. **What could happen in production:** A student admitted in November is billed for April–October
   tuition they were never enrolled for. Left unresolved, this either overcharges a family or forces
   staff to manually correct every mid-year admission's fee chart by hand — a workaround that itself
   creates undocumented, untraceable manual edits.
8. **Is existing data already affected?** Highly likely for any tenant with mid-year admissions
   historically, but **NOT VERIFIED** — no production data was queried in this audit (read-only,
   code-only). This must be confirmed with a read-only data probe before deciding remediation scope.
9. **Reversible?** The demand documents themselves can be corrected or reissued going forward
   (forward-fix). Whether *already-collected* payments against wrong demands need reconciliation is
   a separate, harder question — see Part U.
10. **What must be decided before fixing it:** What "join date" field is authoritative
    (`admissionDate`? `enrolled_at`? the CRM `application_id`'s creation date?) — multiple candidate
    fields exist across the admission pipeline and none is designated as canonical for this purpose.
11. **Dependencies:** None architecturally — this can be fixed independently of identity work. It
    does **not** depend on SL-36 or SL-12.
12. **Recommended remediation direction (not implemented):** Add join-date filtering to
    `buildAdmissionDemandSpecs`, sourced from whichever field is decided in (10), with an explicit
    decision on how to treat demands already generated incorrectly (regenerate vs. leave and credit).
13. **What must be tested after remediation:** every fee-head type generated at admission, promotion,
    and rollover; a full-year admission (should be unaffected); a last-month admission (should bill
    ~1 month); interaction with SL-16's discount mechanisms; interaction with SL-14 (Transport
    proration uses the same underlying proration primitive).

### SL-36 — Re-admission has no link to a student's prior identity

1. **Exact problem:** `enroll_student()` always calls `_nextStudentId()`, a pure monotonic counter,
   with no search against previously withdrawn/TC'd/deleted students before assigning a new ID.
2. **Evidence:** `Sis.php:7486` onward — no phone/name/DOB/old-STU-id matching found anywhere in the
   enrollment path (confirmed by targeted search in the audit's re-admission-focused pass).
3. **Affected modules:** SIS (enrollment), and by extension every module that keys history off
   Student ID: Fees, Attendance, Results, Discipline, Certificates, Communication.
4. **Affected collections:** `students`, plus every collection above keyed by the old `studentId`.
5. **Affected identifiers:** Student ID — this is the identity architecture's one true gap, not a
   surface defect.
6. **Why Critical:** This is the exact failure mode a "Student Trail" is built to prevent. Without a
   link, Student Trail becomes two disconnected trails for one real person, which is worse than no
   Trail at all — it actively misleads whoever reads it into thinking the student has no history.
7. **What could happen in production:** A family re-enrolling a child (common in real schools —
   moved away and came back, or a TC error corrected by re-admission) gets a record showing zero
   history: no discipline pattern, no prior fee-default history, no academic record. Both academically
   and financially, that is a silent loss of institutional memory, and in a discipline/safety context
   specifically, a real risk (a school losing visibility into a past behavioral pattern).
8. **Is existing data already affected?** **NOT VERIFIED** — would require a data probe to check
   whether any duplicate-person records already exist by name/phone/DOB match. Recommend this probe
   before deciding remediation shape.
9. **Reversible?** Not without a deliberate linking mechanism. This is not something a "forward fix"
   alone resolves — retroactive candidates would need a manual/assisted matching pass (see Part K/§14).
10. **What must be decided before fixing it:** the core architectural question in Part K below — does
    ZenX move to "one permanent identity, multiple admission records" or keep "one identity per
    admission, explicitly linked"? This is a genuine design decision, not an implementation detail.
11. **Dependencies:** Soft-depends on SL-37 (SRNO) only insofar as a good matching UX benefits from a
    human-facing lookup; does **not** hard-depend on it. Hard-depends on nothing else — but Student
    Trail's event model (Part 22) should be designed with this in mind from day one, since retrofitting
    a link-model after Trail exists is more expensive than designing for it up front.
12. **Recommended remediation direction (not implemented):** see Part K, §12 for the architectural
    options; no specific option is asserted here as decided.
13. **What must be tested after remediation:** a returning student search hits the same record; a
    genuinely new student with a similar name/phone is *not* incorrectly merged; historical
    Fees/Attendance/Results correctly resolve under the (possibly linked) identity.

### SL-12 — Promotion bypasses canonical class/section normalization

1. **Exact problem:** `execute_promotion`/`batchMoveStudents` write raw class/section keys via a bare
   prefix helper, not `Entity_firestore_sync::normalizeClassSection()` — the function every other
   writer (Attendance, Academic, Homework, Timetable, Subject_assignment, Lesson_plan, Gate_pass,
   Substitute) uses.
2. **Evidence:** `Sis.php:1451-1452`, `Firestore_service.php:247-260` vs. `Entity_firestore_sync.php:
   164-193`.
3. **Affected modules:** every module that queries students by canonical class/section key.
4. **Affected collections:** `students` (className/section fields).
5. **Affected identifiers:** Student ID is unaffected; the *class/section key attached to* the
   student is what drifts.
6. **Why Critical (for Trail specifically, not necessarily for the school generally):** Student
   Trail's cross-module reads (Part T — what Trail should consume, not own) depend on every module
   resolving the same student under the same canonical key. If promotion writes a non-canonical
   shape, Trail's fan-in queries against Attendance/Academic/etc. can silently miss records for that
   student in that session — not an error, a quiet gap.
7. **What could happen in production:** An admin promoting into raw `"10"` produces `className=
   "Class 10"` where every other module expects `"Class 10th"`. That student can become invisible to
   canonical-key queries in unrelated modules until manually corrected.
8. **Is existing data already affected?** **NOT VERIFIED** — plausible for any tenant that has run a
   promotion cycle; needs a data probe (count of `students` docs whose `className` doesn't match the
   canonical class-order map).
9. **Reversible?** Yes — a normalization pass over existing docs is a forward-compatible, low-risk
   correction (not a Student-ID-affecting migration).
10. **What must be decided before fixing it:** whether to backfill existing drifted records or only
    fix new writes going forward (data-repair scope decision).
11. **Dependencies:** Independent of SL-13 and SL-36. Should land before Student Trail's cross-module
    fan-in logic is built, since designing that fan-in against inconsistent keys would just bake the
    bug into Trail's own queries.
12. **Recommended remediation direction (not implemented):** route promotion's class/section writes
    through the same `normalizeClassSection()` call every other writer uses.
13. **What must be tested after remediation:** promotion into every class-name format currently in
    use in production data; existing Attendance/Academic/Homework queries against a freshly promoted
    student.

---

## D. High Findings — MUST ADDRESS BEFORE PRODUCTION STUDENT TRAIL

Using the same evidence discipline as Part C, condensed to essentials.

| ID | Problem | Why High (not Critical) | Key dependency |
|---|---|---|---|
| SL-09 | Promotion fee regeneration deferred + failure invisible to operator | Financial, but only affects the subset of promotions where fee-structure lookup fails mid-flight — narrower blast radius than SL-13 | Independent |
| SL-16 | Three non-unified discount/concession/entitlement mechanisms | Creates real billed-vs-collected divergence, but each mechanism individually functions correctly — the gap is *reconciliation*, not raw correctness | Should land with SL-27 (sibling) |
| SL-17 (RES-001) | Live RTDB read for result-withholding, violating the platform's hard no-RTDB rule | Architectural/platform-integrity violation with a real dual-source-of-truth risk (RTDB vs. `feeDefaulters` Firestore doc can diverge) | Independent, but must close before Results enters Trail |
| CERT-001 | Certificate storage is 100% RTDB | Same platform-rule category as RES-001; additionally blocks Trail's certificate panel entirely until resolved | Hard dependency for Trail's certificate coverage |
| SL-24 | Admission Number has no writer; every certificate prints a fabricated/blank register number | Live, ongoing document-correctness defect on an official school document | Correct long-term fix is SL-37 (SRNO), which was explicitly designed to solve this via dual-emit with zero change to Certificates.php |
| SL-27 | Sibling relationship not structurally represented | Real fraud/audit-risk vector on money (a discount granted with no verifiable basis) | Should land with SL-16 |
| SL-33 | `recoverStudent()` unreachable — restore effectively doesn't exist operationally | Every soft-delete today is de facto permanent from an operator's standpoint, pushing staff toward risky hard-deletes or manual data edits | Independent |

Re-admission identity (SL-36), mid-session fee generation (SL-13), Student ID integrity, SRNO
architecture, and Admission/Application Number are covered in Part C and Part K/L/M — flagged as
required attention per your instruction, not re-duplicated here.

---

## E. Medium Findings

SL-02, SL-03, SL-06, SL-07, SL-08, SL-14, SL-18, SL-20, SL-21, SL-23, RF-001, SL-25, SL-28, SL-32,
SL-37 (see Part B for full detail on each). None of these individually threaten identity continuity
or financial correctness at the scale of Part C/D — they degrade specific surfaces (reports, UI
labels, performance, one module's coordination with another) without corrupting the underlying data
Student Trail would need to read.

## F. Low / Informational Findings

SL-01, SL-05 (informational — confirmed-good design, not a gap), SL-10, SL-11, SL-19 (design
decision, not severity-ranked as a defect), SL-22 (pending verification), SL-26, SL-29, SL-30, SL-31.
**Safe to defer without exception** — none touch identity, money, or academic correctness.

---

## G. Do Not Touch Yet — requires a business/architectural decision first

| Topic | Why implementation must wait |
|---|---|
| **SRNO semantics** (SL-37) | Design is complete (`SR_NUMBER_AND_APAAR_ID_DESIGN.md`) but implementing it now, before SL-36's re-admission model is decided, risks building the allocation/claim logic around the wrong assumption about whether a re-admitted student keeps or re-claims an SR number. |
| **Admission Number semantics** (SL-24) | The dual-emit-from-SRNO fix is already spec'd, but Admission Number's exact meaning ("this admission event" vs. "this school register entry") needs to be pinned down alongside SRNO — fixing the writer without fixing the semantics just formalizes an ambiguity. |
| **Application Number normalization** (SL-02) | Unifying the two generators is a decision about which guarantee (atomic-counter vs. probabilistic) becomes canonical, and whether historical `APP_hex_random` values need backfilling into the counter series — not a pure mechanical fix. |
| **Re-admission identity policy** (SL-36) | This is the central open decision of this entire report — see Part K. Nothing about re-admission should be coded until this is resolved, because the fix shape differs completely depending on the answer (metadata link vs. structural multi-admission model). |
| **Session identity policy** | Confirmed sound today (in-place `session` singular update) — no open decision, listed here only because Part 10/13 of your prompt asked for it explicitly. No action needed. |
| **Historical student identity model** | Downstream of the re-admission decision — cannot be designed independently of it. |
| **Sibling relationship model** (SL-27) | Whether siblings become a structured relationship (a `siblings` collection, or a field linking to another `studentId`) is a data-model decision with privacy/consent implications (linking two children's records), not a pure bug fix. |
| **Student Trail event model** | Part 22 gives a recommendation, but committing to it before SL-36 is decided risks designing an event schema that can't represent multi-admission history correctly. |
| **Document identifier policy** | CERT-001's RTDB migration needs a decision on whether certificate IDs get a new Firestore-native scheme or preserve the existing RTDB-era numbering — a numbering-continuity question, not just "move the storage." |

---

## H. Safe Independent Fixes

Findings that can be corrected **without** first resolving the identity architecture (Part G) — not
implemented here, listed for sequencing only.

| Finding | File/module | Why isolated | Dependencies | Regression risk | Required verification |
|---|---|---|---|---|---|
| SL-07 (Attendance roster leak) | `Attendance.php:5430-5439` | Pure filter-logic gap, no schema/identity change | None | Low — adding a filter clause narrows results, doesn't change shape | Confirm Strategy-2 fallback path is actually exercised in a test tenant; confirm no currently-passing test relies on the unfiltered behavior |
| SL-11 (silent Class/Section discard in edit_student) | `Sis.php:5574` | Isolated to one method's field-whitelist | None (though should route through same normalization as SL-12 if fixed together) | Medium — changes what `edit_student` accepts; needs RBAC/validation review | Confirm no caller depends on the current silent-discard behavior as an (accidental) safety net |
| SL-14 (Transport proration failure swallowed) | `Transport_service.php:2763-2809` | Isolated try/catch behavior | None | Low — surfacing the failure (vs. fixing it) is a logging/visibility change | Confirm operators have a place to see the surfaced failure |
| SL-18 (Report card wrong roll number) | `_report_card_data.php:26` + 7 templates | Pure data-source swap | None | Low-Medium — needs regression against every template | Diff every template's rendered output before/after |
| SL-20 (Hostel fee-creation best-effort) | `Hostel.php:359-372` | Isolated to one write path | None | Low | Confirm allocation-without-fee is actually rare/rejectable |
| SL-21 (TC doesn't release hostel bed) | `Sis.php:1931`, `Hostel.php` | Cross-module call addition, no schema change | Soft dependency on Hostel's own state model being correct | Medium — must not silently evict an occupant if TC is later cancelled | Confirm `cancel_tc()` correctly reverses any hostel-release |
| RF-001 (whole-school scan) | `Red_flags.php:143-292` | Pure query-shape optimization | None | Low — narrowing a query to `studentId==` doesn't change results, only cost | Confirm the narrower query returns identical rows to the current full-scan-then-filter |
| SL-28 (fabricated SMS/email delivery status) | `Communication.php:1391-1404` | Isolated to the queue processor's placeholder branch | Soft dependency on an actual SMS/email gateway being wired (this is a "stop lying," not "start sending" fix — the two are separable) | Low if scoped to status-honesty only | Confirm downstream consumers of "delivered" status don't assume success incorrectly today |
| SL-33 (restore unreachable) | `Dual_write.php:470-492`, missing UI/route | Pure UI/route addition to already-implemented logic | None | Low — the underlying function is already implemented and presumably tested at the library level | Confirm `recoverStudent()`'s existing behavior still matches current soft-delete field shape (`Sis.php:5850-5861`) before exposing it |

---

## I. Dependency Graph

Built from actual repository relationships found in this audit, not assumed.

```
[Student ID — permanent, do not touch]
        │
        ├── BLOCKER ──► SL-36 Re-admission identity decision
        │                       │
        │                       ├── HARD DEP ──► Student Trail event model (Part 22)
        │                       └── SOFT DEP ──► SRNO design refinement (SL-37)
        │
        ├── BLOCKER ──► SL-12 Class/section canonicalization bypass
        │                       │
        │                       └── HARD DEP ──► Any Trail cross-module fan-in query
        │
        └── INDEPENDENT ──► SL-06 dual status field cleanup
                                    │
                                    └── SOFT DEP ──► SL-07 attendance roster leak fix
                                                            (fixing SL-07 alone closes the
                                                             observed leak; SL-06 is the
                                                             deeper root cause worth fixing
                                                             for its own sake)

[SRNO / Admission Number identity work]
        │
        ├── SOFT DEP on SL-36 decision (both touch "what does an admission record mean")
        └── PARALLELIZABLE with all Fees/Results/Certificates work below

[SL-13 Mid-year proration]  ─── INDEPENDENT ─── can start immediately, no identity dependency
        │
        └── SOFT DEP ──► SL-16 (discount unification) — both touch demand generation,
                          sequencing them together avoids re-testing the same code path twice

[SL-16 Discount unification] ── HARD DEP ──► SL-27 (sibling structural model) must be decided
                                              first, or the unified generator inherits the same
                                              unverifiable-sibling-discount problem

[CERT-001 RTDB certificates] ── BLOCKER for ──► Trail's certificate panel only (not Trail overall)
        │
        └── PARALLELIZABLE with RES-001 (same RTDB-retirement workstream, different files)

[RES-001 RTDB result-withheld] ── BLOCKER for ──► Trail's results panel only (not Trail overall)

[SL-33 Restore unreachable] ── INDEPENDENT ─── no dependency on identity work; can ship any time

[SL-09 Promotion fee-regen visibility] ── INDEPENDENT of SL-13, though both are Fees-domain and
                                           can be scheduled in the same workstream for efficiency
```

**Blocking dependencies:** SL-36 (re-admission), SL-12 (canonicalization).
**Hard dependencies:** Trail event model on SL-36's outcome; discount unification (SL-16) on
sibling-model decision (SL-27); Trail certificate/results panels on CERT-001/RES-001 respectively
(scoped blockers, not whole-Trail blockers).
**Soft dependencies:** SRNO refinement on SL-36; SL-07 fix on SL-06's root cause.
**Independent / parallelizable:** SL-13, SL-33, SL-09, SL-18, SL-20, SL-21, RF-001, SL-28, and
essentially everything in Part E/F.

---

## J. What Must Be Fixed Before Student Trail

**Criterion used, per your instruction:** can Student Trail reliably reconstruct the student's
identity, state, history, and events?

| Category | Findings |
|---|---|
| **MUST FIX BEFORE BUILDING** | SL-36 (re-admission identity decision — the event model literally cannot be designed correctly without this answer), SL-12 (canonicalization — Trail's cross-module reads would silently miss data otherwise) |
| **SHOULD FIX BEFORE BUILDING** | SL-06 (dual status field — Trail needs one canonical status read, not a coalesce-and-hope pattern copied into a new module), SL-13 (mid-year proration — Trail's financial panel would otherwise faithfully display known-wrong numbers, which is worse than not having the panel) |
| **CAN FIX DURING STUDENT TRAIL** | SL-09, SL-16, SL-27, RES-001, CERT-001, SL-33 — each affects one panel/domain of Trail and can be resolved as that panel is built, without blocking the foundation |
| **CAN SAFELY DEFER** | Everything in Part E/F — cosmetic, performance, or narrow-scope findings that don't corrupt what Trail would show, only how efficiently or completely it shows one specific thing |

---

## K. What Must Be Fixed Before Student Trail Can Be Trusted

Different bar than "can be built" — this is "can be *believed*."

**Minimum trust gate:**
1. **Identity continuity (SL-36) resolved** — otherwise Trail's core promise ("here is everything
   that ever happened to this student") is false for every re-admitted student, silently.
2. **Class/section canonicalization (SL-12) fixed or backfilled** — otherwise Trail can present a
   confidently-wrong "this student has no Attendance/Academic records for this session" when records
   actually exist under a different key shape.
3. **Financial history correctness (SL-13, and ideally SL-16/SL-27)** — a Trail that shows fee
   history is worse than no Trail if that history is known to be systematically wrong for mid-year
   admissions.
4. **One canonical status source (SL-06)** — Trail needs to state "this student is currently X" with
   confidence; today two field names can disagree with no resolution rule beyond an accidental
   fallback order.
5. **RTDB dependencies resolved for whichever panels are in scope (RES-001 for Results, CERT-001 for
   Certificates)** — not a whole-Trail blocker, but each affected panel is untrustworthy until its
   specific RTDB dependency closes.

**Not required for trust, but required for completeness (can ship as "known gap" panels):**
promotion/detention audit trail (SL-08/SL-10), sibling structural model (SL-27), restore reachability
(SL-33), communication retrievability (SL-29).

---

## L. Student Identity Roadmap

| | **Application Number** | **Admission Number** | **SRNO** | **Student ID** | **Roll Number** |
|---|---|---|---|---|---|
| **Current state** | Exists, dual-generator (counter + probabilistic), back-links to student post-enrollment | Read-only, no writer, fabricated fallback to Student ID | Design-complete, zero code | Canonical, fully implemented, correct | Fully implemented, editable, persistent across sessions |
| **Target state** | Single generator (the atomic-counter scheme), retained as internal audit trail, never operator-facing | Dual-emit of SRNO (per existing design), fixes Certificates.php with zero edits to that file | Implemented per `SR_NUMBER_AND_APAAR_ID_DESIGN.md`, atomic claim, school-scoped series | **Unchanged** | Unchanged |
| **Missing pieces** | Unification of the two generators; decision on backfilling historical `APP_hex_random` values | The writer itself (blocked on SRNO landing first, since Admission Number = dual-emit of SRNO by design) | Everything — collections, claim docs, index, UI, search path | None | None |
| **Risks** | Low — internal-only field, no operator-facing risk from the fork itself | Currently actively misleading on every printed certificate | Standard new-feature risk (concurrency on claim, backfill correctness) — already addressed in the existing design's edge-case table | None if left untouched | None |
| **Dependencies** | None | SRNO | Re-admission decision (SL-36) should land first — see below | None | None |
| **Migration concerns** | None (internal field, no display dependency) | None once SRNO lands (dual-emit is additive) | Backfill is explicitly "import-first, never invent" per the existing design — no auto-numbering by admission date | **None — must never be migrated** | None |
| **Immutability requirement** | Not required to be immutable (internal trace field) | Immutable once set (per design) | Immutable once set, Principal-only correction (per design) | **Absolute — permanent, never reassigned** | Mutable (class/section-level, expected to change) |
| **Session behavior** | Set once at application, never changes | Set once at admission | Lifetime by default (`resetPerSession:false` per design) | Permanent across all sessions | Can change every session/section |
| **Human-facing role** | None (internal) | Printed on certificates (currently broken) | **The primary human-facing operational number** (once built) | None (should never be shown to staff as "the" number to use operationally) | Class-list/roll-call only |
| **Internal role** | Admission-pipeline traceability | Historically meant to be the register number; now superseded in design by SRNO's dual-emit | Resolves to Student ID via `srNoIndex` claim lookup | **The sole system-of-record primary key everywhere** | None (pure display/sort field) |

**Why re-admission (SL-36) should be decided before SRNO implementation, even though they're not a
hard dependency:** SRNO's design already assumes one SR number per school-register entry. If ZenX
adopts a "one permanent identity, multiple admission records" model for re-admission, SRNO's claim
scheme needs to know whether a re-admitted student gets a *new* SR number (a new register entry) or
reuses their old one — a decision the current SRNO design document does not address, because
re-admission wasn't in scope when it was written. Deciding SL-36 first means SRNO gets designed
against the right assumption on the first pass.

---

## M. Re-admission Deep Analysis

1. **How a student is currently re-admitted:** Identically to a brand-new student — there is no
   distinct "re-admission" flow. The same `enroll_student()` path runs regardless of whether this
   person has a prior record.
2. **How student identity is generated:** `_nextStudentId()` — a pure monotonic global counter, no
   awareness of prior records.
3. **Whether previous identity is searched:** No. Confirmed — no phone/name/DOB/old-id matching
   exists anywhere in the enrollment path.
4. **Fields that could safely identify a returning student:** None **safely, alone**. Every candidate
   field has a real collision risk in a school population:
   - Phone number — often shared across siblings or changes over time; a decent *candidate* signal,
     not a safe sole match.
   - DOB — high collision rate in any school (many students share a birthday); unsafe alone.
   - Name — unsafe alone (common names, transliteration variance already a known issue elsewhere in
     this codebase per the admission duplicate-detection logic in SL-03).
5. **Matching by name — unsafe alone:** Yes, confirmed unsafe as a sole signal, consistent with why
   the *existing* application duplicate-detection logic (SL-03) already requires phone+class+name
   combined, not name alone.
6. **Matching by mobile — unsafe alone:** Unsafe as a sole signal (sibling/family-shared numbers are
   explicitly tolerated elsewhere in this codebase, per the public-form duplicate logic's deliberate
   allowance for siblings/twins on the same phone).
7. **Matching by DOB — unsafe alone:** Unsafe as a sole signal (class-cohort collision).
8. **Could Admission Number be used?** No — it has no writer today (SL-24) and even once built (as
   SRNO's dual-emit), it would be *new* for a new admission event, not a lookup key back to an old
   person.
9. **Could SRNO be used?** Only as a **search aid**, not an automatic match — a staff member searching
   "was this person's old SR number 1025?" is a reasonable manual-confirmation UX, but SRNO is
   school-register-scoped and a withdrawn student's number may have already been reassigned in some
   configurations (gap-not-reuse per the design, so actually safer than feared — but this still
   requires human confirmation, not automatic matching).
10. **Can a previous Student ID be linked?** Yes — this is architecturally the cleanest answer: an
    explicit, staff-confirmed link field (e.g., `previousStudentId` on the new record, or a
    `linkedStudentIds[]` array) set only after a human operator confirms a search match. Never
    automatic.
11. **Should multiple historical admissions exist under one identity, or should one identity always
    equal one admission?** This is the actual open decision (Part G). Two coherent models exist:
    - **Model A — Linked records:** keep "one Student ID per admission event" (today's model
      unchanged structurally), but add an explicit `previousStudentId`/`nextStudentId` link chain a
      staff member sets during re-admission after a confirmed manual match. Lower implementation
      risk (additive field, no schema restructure), but Trail needs to *walk the chain* to
      reconstruct full history, and every consumer (Fees, Attendance, Results) still queries by a
      single Student ID unless explicitly told to follow the chain.
    - **Model B — One permanent identity, multiple admission sub-records:** restructure so a single
      `studentId` persists across re-admissions, with a subordinate `admissions[]`/`admissionEvents`
      collection representing each enrollment period. Cleaner conceptually and closer to what "Student
      Trail" implies, but is a genuine schema/architecture change touching every collection that
      currently assumes one Student ID = one continuous enrollment, and interacts with the Firebase
      Auth email-per-Student-ID coupling (`identity_architecture_decision.md`) that is explicitly
      flagged elsewhere as load-bearing and not to be redesigned casually.
    **This document does not choose between them — that is exactly the kind of decision Part G says
    must be made deliberately, with operator sign-off, before any code is written.** Model A is lower-
    risk and compatible with everything found in this audit; Model B is architecturally cleaner but
    has a materially larger blast radius given the Auth-email coupling constraint already on record.
12. **What historical records must remain attached regardless of model:** fee history, attendance,
    exam results, discipline flags, certificates/TCs, and application/admission records — all of
    these are the actual content of a "Student Trail" and are the reason this decision matters.
13. **What must be session-specific:** class/section, roll number, transport/hostel assignment, fee
    structure applicability — these correctly reset or reassign per session already and should not be
    carried across a re-admission automatically.
14. **What must be admission-specific:** the admission date itself, the application record, the SR
    number issued for that admission event (if Model A) or that admission sub-record (if Model B).

---

## N. Mid-Session Admission / Fee Deep Analysis

- **Exact cause:** `buildAdmissionDemandSpecs()` iterates the full fee chart unconditionally — no
  join-date input exists in the function or its callers.
- **Affected code:** `Fee_lifecycle.php:220-350` (legacy path) and `Fee_generation_service.php:73-92`
  (unified/flag-gated path) — both call the same unfiltered function, so **this is not fixed by the
  newer architecture either**.
- **Affected fee demand types:** every recurring (monthly) fee head in the chart; annual/one-time
  heads are less affected in principle but were not independently re-verified in this pass — mark
  **NOT VERIFIED** for annual-head behavior specifically.
- **Whether all fee heads are affected:** **NOT VERIFIED** for every head type — the audit confirmed
  the absence of date logic at the function level, which affects the *loop that emits demands per
  month*; annual/yearly heads may be a separate code branch not independently traced in this pass.
- **Whether admission date is available:** Yes — the student record captures an admission-related
  timestamp somewhere in the enrollment flow (confirmed data exists in the CRM `crmApplications`
  pipeline), but which field is authoritative for *this specific purpose* was not resolved — see
  Part G.
- **Whether joining date is available:** Same caveat — likely available, canonical field **NOT
  VERIFIED**.
- **Whether effective date is available:** No dedicated "fee-effective-from" field was found separate
  from admission/join date candidates.
- **Whether existing demands are already affected:** **NOT VERIFIED** — requires a read-only
  production data probe (count demands with a month prior to the student's admission date), not
  performed in this audit per the no-data-access constraint implied by "read-only, code-only."
- **Whether accounting is affected:** Indirectly — journals are created from receipts
  (`create_fee_journal`), so a wrong demand only becomes a wrong journal once paid. Unpaid wrong
  demands can be corrected without touching accounting; paid wrong demands would need the harder
  reconciliation path.
- **Whether receipts are affected:** Only for demands that were actually paid against — same
  distinction as above.
- **Whether defaulter status is affected:** Yes, indirectly — `feeDefaulters`/`updateDefaulterStatus`
  presumably reads outstanding-balance state, so an inflated demand set could incorrectly flag a
  mid-year admission as a defaulter for months they were never enrolled.
- **Whether result withholding can be affected:** Yes, given SL-17/SL-19's confirmed linkage between
  defaulter status and result-withheld — an inflated defaulter status from this bug could
  incorrectly withhold a legitimately-paid-up mid-year admission's results. **This is a real,
  confirmed downstream chain, not speculative** — every link in the chain (proration gap → inflated
  balance → defaulter flag → withheld flag) is independently evidenced elsewhere in this audit.
- **Whether refunds/credits could be required:** Possibly, only for the subset of mid-year admissions
  who already paid against incorrect pre-join demands — cannot be quantified without the data probe
  above.
- **Classification: DESIGN GAP, not a pure bug.** The function was never built with join-date
  awareness — there is no evidence it regressed from previously-correct behavior. This is a missing
  capability in the original implementation, compounded by a genuine **DATA GAP** (no clearly
  designated authoritative join-date field) that must be resolved as part of the fix, not just a
  one-line code change.

---

## O. RTDB / Legacy Dependency Register

| Module | File | Function | Data | Firestore equivalent | Current source of truth | Risk | Student Trail dependency |
|---|---|---|---|---|---|---|---|
| Results | `Result.php:314,451,1697` | withheld-flag read | `result_withheld` | `feeDefaulters` (Firestore) already exists and is stated elsewhere as the sole source of truth for defaulter status | **Conflicting** — RTDB path read here, Firestore doc exists elsewhere; the two can diverge | High (dual source of truth, silent divergence) | Blocks Results panel only |
| Certificates | `Certificates.php:519` | issued-certificate write/read | full certificate record | None currently built | RTDB (sole store) | High (platform-rule violation, no Firestore equivalent exists yet to migrate to) | Blocks Certificates panel entirely |
| Attendance | referenced as `ATT-001` in prior-session narrative | Not independently investigated by this audit | Unknown | Unknown | Unknown | **NOT VERIFIED** | Unknown — must be independently audited before assuming Attendance is clean |

Only two of the three RTDB dependencies in scope were independently confirmed by this audit's own
evidence-gathering (RES-001, CERT-001). `ATT-001` is carried forward as a label only, from prior
pasted narrative outside this audit's own investigation — **do not treat it as confirmed** until a
dedicated pass reads the actual Attendance code for RTDB calls the way this audit did for
Results/Certificates.

---

## P. Exit / Restore / Re-admission — Connected Lifecycle Map

```
ACTIVE ──► WITHDRAWAL ──► ARCHIVE (soft-delete) ──► RESTORE ──► (back to ACTIVE)
   │                                                     │
   └──► TC / EXIT ──────────────────────────────────────┘
                                                          │
                                                          ▼
                                                   RE-ADMISSION
                                                   (currently: brand-new
                                                    identity, no link)
```

| Transition | Identity preserved? | History preserved? | Fees | Attendance | Academic | Documents | Parent relationship | Transport/Hostel |
|---|---|---|---|---|---|---|---|---|
| Active → Withdrawal | Yes (status flip only) | Yes | Frozen via `freezeFeesOnSoftDelete` | Preserved, untouched | Preserved | Preserved | Preserved | **Not addressed by withdrawal itself** — same gap as TC (SL-21) |
| Active → TC/Exit | Yes | Yes | **Not frozen** (relies on dues-clearance gate instead — SL-31, resolved-contradiction) | Preserved | Preserved | Preserved | Preserved | **Hostel bed not released (SL-21, confirmed)** |
| Withdrawal/soft-delete → Archive | Yes | Yes (retained, not purged) | Frozen | Preserved | Preserved | Preserved | Preserved | Unknown — not independently traced for soft-delete specifically |
| Archive → Restore | Would be Yes | Would be Yes | Would be Yes | Would be Yes | Would be Yes | Would be Yes | Would be Yes | Would be Yes | **but the transition itself is unreachable (SL-33) — the library function exists, no UI/route calls it** |
| Archive/TC/Withdrawal → Re-admission | **No — breaks here** | **No — breaks here** | Orphaned under old ID | Orphaned under old ID | Orphaned under old ID | Orphaned under old ID | Orphaned under old ID (new parent doc created per SL-26) | Orphaned under old ID |

**Where identity breaks:** only at Re-admission (SL-36). Every other transition in this chain
preserves identity correctly.

**Where history breaks:** same point — Re-admission. Everything upstream of it (Withdrawal, TC,
Archive) correctly preserves history; the chain only actually breaks at the one edge where a person
re-enters through the front door as if new.

---

## Q. Financial Dependency Order

```
Admission
   │
   ▼
Fee setup (fee chart/structure — confirmed correct, out of scope of findings)
   │
   ▼
Demand generation ── SL-13 (mid-year proration) MUST be fixed here — everything downstream
   │                  inherits whatever demand generation produces
   ▼
Discount / Concession / Entitlement ── SL-16 (three non-unified mechanisms), SL-27 (sibling
   │                                    structural gap) — must be resolved before demand+discount
   │                                    together can be called "correct"
   ▼
Collection / Receipt ── confirmed correct (idempotent journal posting)
   │
   ▼
Accounting ── confirmed correct given correct inputs; inherits any upstream error
   │
   ▼
Defaulter status ── inherits SL-13's inflated-balance error downstream
   │
   ▼
Result withholding ── inherits defaulter-status error, and independently has its own RTDB
                       defect (RES-001) on top
```

**Must be fixed before Student Trail can display financial history reliably:** SL-13 first (it is the
root of the chain), then SL-16/SL-27 together (the discount layer), then RES-001 (result-withholding's
own separate defect, not caused by the chain above but compounding it for any student who is both
mid-year-admitted and has a withheld result).

---

## R. Student Trail Readiness Gate

| Domain | Status | Reason |
|---|---|---|
| Identity continuity | **RED** | SL-36 — re-admission has no link; this alone fails the gate for the whole Trail concept |
| Admission lifecycle | YELLOW | Functionally solid (SL-02/03 are consistency gaps, not correctness failures) |
| Re-admission | **RED** | SL-36 |
| Session transition | GREEN | Confirmed correct, in-place update, matches canonical-session memory |
| Fees | **RED** | SL-13 (active overbilling) + SL-16 (fragmented discount truth) |
| Attendance | YELLOW | SL-07 (confirmed roster leak) + SL-06 (root cause); core recording/audit trail is otherwise solid |
| Results | YELLOW | RES-001 (RTDB) + SL-18 (wrong roll number on report cards); core computation/audit trail otherwise solid |
| Documents | GREEN | Canonical Document Service confirmed sound, delegation confirmed |
| Certificates | **RED** | CERT-001 (100% RTDB) + SL-24 (fabricated Admission Number) |
| Transport | GREEN | Assignment, suspension-billing, rollover, and safety-critical boarding roster all confirmed sound |
| Hostel | YELLOW | Real module, correctly built core, but SL-20 (fee best-effort) + SL-21 (TC doesn't release bed) |
| Discipline | YELLOW | Correct data model and RBAC; RF-001 performance defect, SL-23 redaction question unresolved |
| Parent | YELLOW | Per-student linkage works; multi-child/sibling model is aspirational-only (SL-26/SL-27) |
| Communication | YELLOW | Broadcast and RBAC solid; SL-28 (fabricated delivery status) is a trust issue for whatever Trail shows about "was this sent" |
| Exit | GREEN | Withdrawal/TC/cancel all confirmed correct and reversible |
| Restore | **RED** | SL-33 — implemented but entirely unreachable; the capability effectively does not exist operationally |
| Auditability | YELLOW | Strong in Marks/Documents; weak/absent in Promotion (SL-08), Certificates (SL-25), Detention (SL-10) |
| Security | YELLOW | Sound RBAC almost everywhere audited; SL-04 (PII retention), SL-27 (sibling fraud vector), SL-23 (redaction) are open questions, not confirmed breaches |
| Cross-system consistency | **RED** | SL-12 — confirmed live canonicalization drift breaks cross-module lookups |
| RTDB dependencies | **RED** | RES-001 + CERT-001 confirmed live; ATT-001 unverified |

**Overall: RED.** Four confirmed-RED domains (Identity/Re-admission, Fees, Certificates, Restore) plus
one structural RED (RTDB, cross-system consistency) is enough to block a "trustworthy, complete"
Trail today — consistent with the GO WITH CONDITIONS decision in Part A, not a full NO-GO, because
every RED item here has an independently scoped, non-architecture-wide fix except SL-36.

---

## S. Recommended Implementation Sequence

Adjusted from your suggested phase list based on actual dependency evidence — SRNO is pushed later
than "Phase 1" because it soft-depends on the re-admission decision (Part L), and RTDB retirement is
pulled earlier because it's fully independent and currently blocking two Trail panels for no
architectural reason.

**PHASE 1 — Identity foundations (decisions, not code)**
Objective: resolve the two blocking decisions before any Trail-adjacent code is written.
Findings addressed: SL-36 (re-admission model decision), SL-12 (canonicalization fix — this one *is*
code, low-risk, and should ship in this phase since every later phase benefits from it).
Prerequisites: none.
Affected modules: Sis.php, Dual_write.php, Firestore_service.php.
Risk: Low for SL-12 (mechanical fix); the SL-36 *decision* itself carries no code risk, only
organizational risk (the wrong decision is expensive to reverse later).
Verification: SL-12 — promotion into every existing class-name format in production data.
Must NOT be changed: Student ID generation itself; Firebase Auth email scheme.

**PHASE 2 — Admission / re-admission integrity**
Objective: implement whatever SL-36 decided in Phase 1, and land Application Number unification
(SL-02).
Findings addressed: SL-36 (implementation), SL-02.
Prerequisites: Phase 1 decision.
Affected modules: Sis.php (enrollment), Id_generator.php.
Risk: Medium — this is the highest-blast-radius code change in the whole roadmap if Model B (Part L
§11) is chosen; Low if Model A is chosen.
Verification: re-admission search/link tests per Part M §13 test plan.
Must NOT be changed: existing Student IDs already issued; Firebase Auth coupling.

**PHASE 3 — Financial correctness**
Objective: SL-13 (mid-year proration), SL-16 (discount unification), SL-27 (sibling model
implementation), SL-09 (promotion fee-regen visibility), SL-14 (transport proration failure
visibility), SL-20 (hostel fee best-effort).
Prerequisites: Phase 1's join-date-field decision (part of SL-13's own §10).
Affected modules: Fee_lifecycle.php, Fee_generation_service.php, Entitlement_service.php,
Transport_service.php, Hostel.php.
Risk: Medium-High for SL-13 specifically if a data-repair pass on existing demands is decided (Part
U); Low for the visibility-only fixes (SL-09, SL-14).
Must NOT be changed: the already-correct idempotent journal-posting mechanism.

**PHASE 4 — Academic / legacy consistency**
Objective: RES-001 (RTDB retirement for results), SL-18 (report-card roll number), SL-19 (decide
withheld-enforcement policy).
Prerequisites: none blocking, but benefits from Phase 1's canonicalization fix being in place.
Affected modules: Result.php, report-card templates.
Risk: Low-Medium.
Must NOT be changed: the already-correct marks-entry audit trail.

**PHASE 5 — Exit / restore integrity**
Objective: SL-33 (make restore reachable), SL-04 (retention policy for rejected applications),
SL-21 (TC/hostel coordination).
Prerequisites: none.
Affected modules: Sis.php, Dual_write.php, Hostel.php.
Risk: Low.

**PHASE 6 — Cross-module lifecycle consistency**
Objective: SL-01 (enquiry funnel), SL-03 (duplicate-detection unification), SL-22 (RBAC catalogue
mirror), SL-23 (discipline redaction decision), SL-26 (multi-child docblock correction), RF-001
(discipline query performance), SL-28 (communication delivery-status honesty).
Prerequisites: none.
Risk: Low across the board — this phase is pure cleanup, appropriate to run fully in parallel with
Phases 3-5.

**PHASE 7 — Student Trail foundation**
Objective: build the minimum event/data architecture from Part 22, informed by whatever Phase 1-2
decided about re-admission.
Findings addressed: SL-08, SL-10, SL-29, SL-30 become natural byproducts of building Trail's event
model correctly the first time.
Prerequisites: **Phase 1 and Phase 2 complete** (the true hard gate). Phases 3-6 strongly
recommended complete first per the Readiness Gate (Part R), but not strictly blocking Trail's
*foundation* — only its *trustworthiness* (Part K).
Risk: Medium — this is new architecture, not a fix to existing code.

**PHASE 8 — Student Trail UI / timeline**
Objective: the reader/timeline surface itself.
Prerequisites: Phase 7. Each panel (Fees, Results, Certificates, etc.) should individually gate on
its own domain's Readiness color from Part R — e.g., the Certificates panel should not ship until
CERT-001 closes, even if the rest of Trail is ready.

**PHASE 9 — Student 360 integration**
Objective: wire Trail into the existing Student 360 workspace (already built per prior session
context — this audit did not re-verify Student 360's current implementation state, since your
instruction was explicit that this is not a Student 360 audit).
Prerequisites: Phase 8.

---

## T. Parallel Workstreams

| Workstream | Contents | Conflicts with |
|---|---|---|
| **A — Identity decisions** | SL-36 decision, SL-12 fix, SL-02 unification | None — should run first and alone, since B/C/D all benefit from its outcome |
| **B — Fees** | SL-13, SL-16, SL-27, SL-09, SL-14, SL-20 | Soft conflict with A only insofar as SL-13's join-date field decision is technically part of Phase 1 — otherwise fully parallelizable once that one field decision lands |
| **C — RTDB retirement** | RES-001, CERT-001, and an independent audit of the unverified ATT-001 | None — fully independent of A and B |
| **D — Documents/Certificates** | SL-24, SL-25 (both depend on CERT-001 landing first, so sequence after C, not parallel with C) | Hard dependency on C (CERT-001) |
| **E — Hostel/Transport** | SL-21, SL-22 (Transport itself has no open findings requiring work) | None |
| **F — Audit/Event architecture (Trail foundation)** | Phase 7 work | **Hard dependency on A** — cannot start meaningfully until SL-36 is decided, per Part I's dependency graph |

**Explicit conflict:** Workstream D should not start before Workstream C reaches CERT-001's
resolution — building the "reprint vs. original" audit trail (SL-25) on top of an RTDB store that's
about to be replaced would be wasted or conflicting work.

---

## U. Student Trail Minimum Foundation

Not implemented — design recommendation only, derived from what this audit found modules already do
well (Document_service's audit pattern, marks-entry's audit pattern) rather than invented from
scratch.

**Minimum event shape**, modeled on the two audit patterns already proven correct in this codebase
(`Document_service::audit()` and `Result.php`'s `marksAudit` batching):

- **Canonical student reference** — must resolve through whatever Phase 1/2 decides for SL-36 (a
  single `studentId`, or a `studentId` + `admissionEventId` compound reference).
- **Event identity** — a unique, stable event id (not derivable/re-computable, per the same principle
  already used for `marksAudit` correlation ids).
- **Event timestamp** — server timestamp, not client-supplied (consistent with existing audit
  patterns in this codebase).
- **Actor** — the staff/system identity that caused the event (already a required field in every
  audit pattern found — Document_service, marksAudit, Sis.php's `_log_history`).
- **Module** — which subsystem originated the event (Fees, Attendance, Results, etc.).
- **Platform** — Admin/Parent/Teacher, since parent-initiated actions (SL entitlement requests) are
  already a real, distinct event source found in this audit.
- **Before state / after state** — proven pattern from `marksAudit`'s before/after `componentMarks`
  capture; should generalize the same way.
- **Reason** — free text, already present in withdrawal/TC/entitlement flows; should carry through.
- **Session** — required given how session-scoped most of this data already is.
- **Admission event reference** — required if Model B (Part L §11) is chosen; optional under Model A.
- **Identifier references** — whichever of Application/Admission/SR/Student ID are relevant to that
  specific event, since multiple identifiers can be simultaneously true of one event (e.g., a TC
  event references both Student ID and, once built, SRNO).
- **Historical linkage** — an explicit `previousStudentId`/chain reference if Model A is chosen.

**Current state vs. Historical event — the distinction that matters:** every "current state" read
(is this student Active? what class are they in? what's their balance?) should keep querying the
authoritative live collection directly (`students`, `feeDemands`, etc.) — Trail should **never**
become the system of record for current state. Trail's job is the **event log**: what happened, when,
by whom, why — assembled by reading each module's own existing audit trail where one already exists
(Document_service, marksAudit, `_log_history`, `auditLogs`) and by adding new event-emission only
where audit trails are currently absent (SL-08 promotion, SL-10 detention, SL-25 certificates).

---

## V. What Student Trail Should NOT Own

Based on what this audit confirmed already works correctly as an authoritative source:

- **Student Trail should not become a second Fees ledger.** `feeDemands`/`feeReceipts`/
  `Operations_accounting`'s idempotent journal system is confirmed correct and remains authoritative;
  Trail reads it, never recomputes or duplicates balances.
- **Student Trail should not become a second Attendance database.** `attendanceSummary` (keyed
  `{schoolId}_{studentId}_{monthKey}`) with its existing audit trail remains authoritative.
- **Student Trail should not become a second Results system.** `Result.php`'s computation and
  `marksAudit` trail remain authoritative; Trail surfaces them, doesn't recompute grades/ranks.
- **Student Trail should not become a second Document store.** `Document_service.php` is explicitly
  the frozen canonical platform baseline per CLAUDE.md — Trail consumes its `audit()` output, never
  reimplements upload/verify/storage logic.
- **Student Trail should not become a second RBAC engine.** Every panel Trail shows must check the
  exact same graded capability (`has_permission($module,$level)`) the owning module already checks —
  consistent with the "one domain, one action provider" principle already established in prior
  Student 360 design work referenced in this conversation's history (not re-verified in this audit,
  since Student 360 itself was explicitly out of scope).
- **Student Trail should not become a second identity resolver.** Whatever SL-36 decides for
  re-admission linkage lives in the identity/enrollment layer (Sis.php), not duplicated inside Trail's
  own code.

**The boundary, stated plainly:** Trail consumes authoritative lifecycle events and current-state
reads from each owning module. It adds exactly one new thing the codebase doesn't currently have
anywhere: a unified, cross-module, chronological *view* — not a unified *store*.

---

## W. Existing Data / Migration Risk

| Finding | Data already affected? | Repair type | Duplicate-record risk | Financial-history-altering? | Academic-history-altering? | Student-ID-relationship-altering? | Migration required? |
|---|---|---|---|---|---|---|---|
| SL-13 (mid-year proration) | **NOT VERIFIED** — needs a data probe | Forward-fix for unpaid demands; **targeted repair** for paid-against wrong demands (harder, needs Part G decision on approach) | No | Yes, for the paid subset | No | No | Targeted data repair likely required once the probe confirms scope |
| SL-36 (re-admission) | **NOT VERIFIED** — needs a name/phone/DOB fuzzy-match probe against withdrawn/TC'd records | Manual/assisted linking pass, never automatic | **Yes — real risk** if any automated matching were used; this is exactly why Part M §10 insists on human-confirmed linking only | No (link is additive metadata, doesn't move fee history) | No (same reasoning) | **Yes — this is precisely the relationship being added** | Migration required (backfill of `previousStudentId` links for any historical re-admissions), but additive/forward-compatible, not destructive |
| SL-12 (canonicalization drift) | **NOT VERIFIED** — needs a count of non-canonical `className` values in production | Targeted backfill (normalize existing values) | No | No | Possible, indirectly, if fee-structure lookups keyed by class silently mismatch today | No | Targeted data repair, low risk (pure string normalization, reversible) |
| RES-001 / CERT-001 (RTDB) | Data exists in RTDB, not "affected" so much as **not yet migrated** | Migration required (RTDB → Firestore) | No | No | No | No | **Migration required** — this is a genuine data-store move, not a logic fix |
| SL-06 (dual status field) | Existing docs likely already have inconsistent `status`/`Status` values | Targeted repair (canonicalize to one field) | No | No | No | No | Targeted data repair, low risk |
| SL-33 (restore unreachable) | No — the underlying data is fine, only the access path is missing | No repair, UI/route addition only | No | No | No | No | **No data repair required** |

**No finding in this audit requires a migration that alters existing financial totals, academic
records, or breaks an existing Student ID relationship** — the highest-risk item (SL-36) is
explicitly additive-link-only by design recommendation, never a rewrite of history.

---

## X. Decision Register

| Decision | Current State | Decision Needed | Blocking? | Owner | Before Implementation? |
|---|---|---|---|---|---|
| Student ID immutability | Immutable, confirmed sound | None — no change proposed anywhere in this audit | No | OWNER NOT ASSIGNED | N/A — already correct |
| SRNO semantics | Design-complete, code absent | Confirm design still holds once SL-36 is decided (Part L) | Soft-blocking on SL-36 | OWNER NOT ASSIGNED | Yes |
| Admission Number semantics | No writer, fabricated fallback | Confirm dual-emit-from-SRNO approach (already spec'd) | Blocking on SRNO | OWNER NOT ASSIGNED | Yes |
| Application Number semantics | Dual-generator fork | Which generator becomes canonical; backfill historical values or not | No (independent) | OWNER NOT ASSIGNED | Recommended before Phase 2 |
| **Re-admission identity** | No link, new ID every time | **Model A (linked records) vs. Model B (permanent identity + admission sub-records)** — Part L §11 | **Yes — the central blocking decision of this entire report** | OWNER NOT ASSIGNED | **Yes, before Phase 2** |
| Session identity | In-place update, confirmed correct | None | No | OWNER NOT ASSIGNED | N/A — already correct |
| Historical student identity model | Downstream of re-admission decision | Same decision as above | Yes (same blocker) | OWNER NOT ASSIGNED | Yes |
| Student Trail event model | Not yet designed | Adopt Part U's recommendation or an alternative | Yes, on re-admission decision | OWNER NOT ASSIGNED | Yes, before Phase 7 |
| Financial history model | Fragmented (SL-16) | Unify discount/concession/entitlement reads for demand generation | No (independent) | OWNER NOT ASSIGNED | Recommended before Phase 3 completes |
| Exit/restore model | Functionally correct but restore unreachable | Only a UI/route decision — no architectural question | No | OWNER NOT ASSIGNED | No — mechanical fix |
| RTDB retirement (Results, Certificates) | Two confirmed live dependencies | Migration approach and target Firestore shape for Certificates (none exists yet) | No (independent), but scoped-blocking for two Trail panels | OWNER NOT ASSIGNED | Yes, before those two Trail panels ship |
| Sibling relationship model | Free-text only, no structure | Structured relationship field vs. continue free-text with stronger audit | No (independent), recommended alongside SL-16 | OWNER NOT ASSIGNED | Recommended before Phase 3 |

---

## Y. Top 10 Critical/High Priorities

| # | ID | Problem | Why it matters | Dependency | Recommended action | Parallelizable? | Before Trail? | Before production? | Verification required |
|---|---|---|---|---|---|---|---|---|---|
| 1 | SL-36 | Re-admission creates a disconnected new identity | Directly defeats Student Trail's core purpose | None (root decision) | Decide Model A vs. B (Part L §11); do not implement until decided | No — must be first | **Yes — hard blocker** | Yes | Re-admission search/link test suite |
| 2 | SL-13 | Mid-year admission billed for pre-join months | Live, ongoing financial defect affecting every mid-year admission | Join-date-field decision | Add date filtering to `buildAdmissionDemandSpecs`; decide historical-data repair scope | Yes, alongside SL-36 decision | Yes | Yes | Every fee-head type, every admission timing |
| 3 | SL-12 | Promotion bypasses canonical class/section normalization | Breaks cross-module lookups silently | None | Route promotion writes through `normalizeClassSection()` | Yes | Yes | Yes | Promotion into every class-name format in prod data |
| 4 | SL-16 | Three non-unified discount/concession/entitlement mechanisms | Billed vs. collected divergence | Sibling model decision (SL-27) | Unify the generation-time read path | Yes, with SL-27 | Can fix during Trail build | Yes | Full concession-type regression |
| 5 | SL-27 | Sibling discounts have no structural verification | Fraud/audit-risk vector on money | None | Decide structured-relationship vs. stronger free-text audit | Yes, with SL-16 | Can fix during Trail build | Yes | Concession-grant audit review |
| 6 | RES-001 | Live RTDB read for result-withheld | Platform-rule violation, dual source of truth vs. `feeDefaulters` | None | Migrate to Firestore read | Yes | Scoped to Results panel only | Yes | Withheld-flag consistency test across both stores during migration |
| 7 | CERT-001 | Certificate storage 100% RTDB | Platform-rule violation, blocks Trail's certificate panel | None | Design Firestore-native certificate store, migrate | Yes | Scoped to Certificates panel only | Yes | Certificate issuance/reprint regression |
| 8 | SL-24 | Admission Number has no writer, fabricated on every certificate | Live document-correctness defect on an official record | SRNO (dual-emit design already solves this) | Implement SRNO per existing design, dual-emit into Admission Number | Yes, after SRNO/SL-36 sequencing | Recommended before Trail's certificate panel | Yes | TC print regression, zero change to Certificates.php confirmed |
| 9 | SL-33 | Restore is implemented but unreachable | Every soft-delete is de facto permanent operationally | None | Add UI/route surfacing `recoverStudent()` | Yes | No (not identity-critical) | Recommended | Restore/re-archive round-trip test |
| 10 | SL-09 | Promotion fee regeneration deferred, failure invisible to operator | Silent financial-process failure, operator sees false success | None | Surface deferred-fee outcome to the operator-facing response or a visible status | Yes | No | Recommended | Promotion with a deliberately-broken fee structure mid-flight |

---

## Z. Final GO / NO-GO Decision

1. **Is ZenX ready to begin implementing Student Trail? GO WITH CONDITIONS.**
2. **Exact blockers that must be resolved first:**
   - SL-36 (re-admission identity model) — decision, then implementation.
   - SL-12 (canonicalization bypass) — mechanical fix, low risk, should ship alongside the SL-36
     decision window since it's cheap and every later phase benefits.
3. **Minimum safeguards once GO conditions are met:** each Trail panel (Fees, Results, Certificates,
   Discipline, etc.) should individually gate on its own domain's Readiness color from Part R — don't
   let a RED-domain panel ship just because the Trail foundation itself is ready. Also: any re-
   admission linking must be human-confirmed, never automatic (Part M §10) — this is a safeguard on
   the *implementation* of the Phase 1 decision, not a separate blocker.
4. **Is the current identity architecture safe enough to proceed? Mostly yes** — Student ID itself is
   solid and should not be touched. The gap is narrowly scoped to the re-admission edge case, not the
   identity architecture broadly.
5. **Is re-admission a blocker? Yes — the primary one.**
6. **Is the fee-demand issue (SL-13) a blocker? Yes, for a *trustworthy* Trail specifically** — Trail
   can be technically built without it fixed, but its financial panel would confidently display known-
   wrong numbers, which fails the trust bar in Part K even though it doesn't fail the "can it be
   built" bar in Part J.
7. **Are RTDB dependencies a blocker? Only scoped-blocking** — they block two specific panels
   (Results, Certificates), not the Trail foundation itself. Not a whole-project blocker.
8. **Is SRNO implementation a blocker for Trail? No** — Trail's foundation doesn't require SRNO to
   exist; SRNO is a human-facing operational-number project that can proceed on its own track once
   SL-36 is decided, in parallel with Trail's foundation work.
9. **Is Student 360 currently safe to build on top of this data?** **NOT VERIFIED by this audit** —
   Student 360's current implementation state was explicitly out of scope per your instruction ("this
   is NOT a Student 360 audit"). This question cannot be answered from this document alone; it would
   require re-reading Student 360's actual current code against these same findings, which was not
   performed here.
10. **Smallest safe next engineering phase:** Phase 1 as scoped in Part S — resolve the SL-36
    decision (organizational, not code) and ship the SL-12 canonicalization fix (small, isolated,
    immediately valuable regardless of which re-admission model is eventually chosen).

---

**Nothing in this document has been implemented. Working tree HELD. No code, data, Firestore, rules,
indexes, RBAC, or test-data changes were made. No files staged, committed, reset, stashed, or
pushed.**
