# Student Lifecycle Phase 8.5 — Cross-Module Integration Gate

**Date:** 2026-08-13 · **Status: AUDIT ONLY. Zero files modified.** The only write performed by this
phase is this report.

**Method, per explicit instruction not to loop:** this document does **not** re-derive what Phase 0,
Phase 1 (Identity Decision), the three SRNO audits, the Gap Closure Report, Phase 6, Phase 7, and
Phase 8.1-8.4 already established. Every one of those findings is cited by name, not repeated as new
work. This phase's actual new contribution is threefold: (1) one piece of source re-verification the
prompt explicitly demanded (the currently-implemented SRNO re-admission policy, since the
conversation's own history contains an earlier superseded recommendation); (2) assembling the
complete cross-module/cross-platform matrices this program has never built in one place before; (3)
explicitly naming the connections that remain genuinely **NOT VERIFIED** because no prior phase
traced them — Transport, Communication, and Documents' specific behavior *at the moment of
re-admission* were never independently checked, and this document says so rather than assuming they
inherit the same safety already proven for Fees/`students`/`srNoIndex`.

---

## 1. Executive Summary

The Student Lifecycle's **identity core** — Student ID (global), SRNO (school-scoped, preserved
across re-admission), `previousStudentId` linkage, cross-tenant isolation — is the single
most-audited, most-tested part of this entire program: three dedicated architecture audits, two
implementation passes, 20/20 unit tests, two rounds of adversarial review, and a cross-platform
regression audit (Phase 8.4) all converge on **CONFIRMED sound**. That core is genuinely ready.

**What is not ready, and was never claimed to be, is everything the identity core connects to.**
Phase 0 and Phase 6 already catalogued a long list of pre-existing, independent defects (mid-year fee
overbilling, RTDB survivals in Results/Certificates, a promotion canonicalization bypass, a
mislabeled TC field, a wrong-source report-card field, hostel-bed-not-released-on-TC, and more) —
**none of them caused by, or touched by, the SRNO/re-admission work**, and this phase re-confirms that
boundary held. What this phase adds is the honest acknowledgment that **three specific downstream
connections — re-admission's effect on Transport, Communication, and Documents — were never actually
traced by any prior phase**, and this document does not pretend otherwise by inheriting the identity
core's confidence for territory it never covered.

## 2. Scope

Three repositories (Admin Panel PHP, Parent App Kotlin, Teacher App Kotlin), all previously-established
findings from Phase 0 through Phase 8.4, plus targeted new verification listed in §0 above. No code
was modified.

## 3. Reuse of Phase 8.4 Findings

**Verdict: READY, re-affirmed, not re-investigated.** Phase 8.4's central finding — that the
"schoolId claim blank" bootstrap login edge case was never functional independent of the RTDB→Firestore
migration, and that the migration itself introduced zero cross-tenant, cross-platform, or identity
risk — is **not reopened here**. No new evidence surfaced during this phase that contradicts it.

---

## 4. Student Identity Contract

**CONFIRMED, re-verified against `Id_generator.php` in this program's earlier passes, not reopened:**
global atomic counter (`feeCounters/_sys_STU`), zero `schoolId` component anywhere in the claim
mechanism, doc-id `{schoolId}_{studentId}` for tenant-scoped storage without making the identity
itself tenant-scoped. Firebase Auth email derivation (`strtolower(userId)@schoolsync.app`) confirmed
unchanged since the original identity-architecture decision. **No violation found anywhere this
program has read code.**

## 5. SRNO Contract

**CONFIRMED, freshly re-read from `Sr_no_service.php` this phase, per explicit instruction not to
assume:** `claim()` is atomic create-if-not-exists, school-scoped via `docId()`. `relink()` — the
re-admission mechanism — **moves the SAME `srNoIndex` document's `currentStudentId` field from the
old Student ID to the new one; it never creates a new claim.** This is **the currently implemented
policy: SRNO is preserved (same digits) across a verified re-admission**, matching the operator's
explicit later instruction in this conversation and superseding an earlier architecture-audit
recommendation (Option B, "new SRNO on re-admission") that was never implemented. `Readmission::confirm()`
reads the SRNO to relink directly from the **old** student's own `srNo` field
(`$oldSrNo = trim($oldStu['srNo'])`) — the new admission never independently supplies or invents a
number. Cross-school isolation, cycle prevention, and predecessor-double-spend prevention all remain
as tested in the prior SRNO audits — re-affirmed, not re-tested, since the code is unchanged.

## 6. Roll Number Contract

**CONFIRMED unaffected by any SRNO/re-admission work — Roll Number and SRNO have never shared a code
path anywhere this program has read.** Class/section scope, non-uniqueness across sections, and the
Teacher App type-coercion fix (Phase 8, `Int.MIN_VALUE` sentinel + `rollNoRaw` preservation) all
remain as previously verified. **Pre-existing, unrelated, unresolved defects, re-confirmed present
by this program's own re-checks in Phase 6/Phase 7, not re-tested again here:** report cards source
Roll Number from `User Id` (`_report_card_data.php:26`); `tc_print.php` prints Roll Number under the
label "Adm No." Both remain **PRE-EXISTING DEFECT**, not authorized for fixing in this phase, per
explicit instruction.

## 7. Admission Lifecycle

**CONFIRMED, unchanged from Phase 0/Gap Closure Report:** `enroll_student()` allocates a fresh global
Student ID, optionally claims an SRNO (Gap Closure Report's addition — a purely additive `sr_no` POST
field, best-effort, never blocking), assigns class/section/Roll Number/session, and triggers
`assignInitialFees` (best-effort, warned on failure). **This is identical for a first-time admission
and for the "create the new admission record" half of a re-admission** — `Readmission::confirm()` is
a deliberately separate, later step (§8), not a special admission code path.

## 8. Re-admission Lifecycle

Walking the prompt's own 20-question list, answered from source, not assumed:

1. **Preserved:** the old Student ID's own record, entirely untouched (**TESTED**,
   `test_relink_only_writes_srNoIndex_and_the_new_student_doc`). The SRNO digits themselves (§5).
2. **Newly created:** a new Student ID (ordinary global counter, unchanged mechanism), a new
   `previousStudentId` field on the new record, a new `srNoIndex.currentStudentId` pointer value.
3. **Never blindly copied:** fee demands, receipts, accounting journals, attendance, exam records,
   discipline records, transport assignments, hostel allocations, certificates, documents —
   **CONFIRMED**, `relink()` touches exactly two Firestore locations (the srNoIndex doc, the new
   student's own doc) — verified by the same isolation test.
4. **Historical:** everything under the old Student ID, permanently.
5. **Belongs to the new admission:** new class/section/session/Roll Number, assigned exactly like any
   other fresh enrollment (§7) — **CONFIRMED, since `Readmission::confirm()` runs strictly after
   ordinary enrollment, not instead of it.**
6. **Belongs to the real-world student (via linkage, not merge):** the SRNO value and the
   `previousStudentId` chain — the only two facts this architecture asserts span both admissions.
7. **Old fees:** remain attached to the old Student ID — **CONFIRMED**, `relink()` never references
   `feeDemands`/`feeReceipts` at all.
8. **Old attendance:** same — **CONFIRMED unaffected**, no reference in the migrated code.
9. **Old results:** same.
10. **Old documents:** same.
11. **Old transport assignments:** **NOT VERIFIED — genuinely new gap, named §13.**
12. **Parent relationships:** a new `parents/{schoolId}_{newStudentId}` document would be created at
    the new admission's own enrollment (per the existing per-student, not per-family, linkage this
    program confirmed in Phase 0/Phase 1) — **CONFIRMED structurally** (new doc-id, new document,
    same mechanism as any fresh admission), but whether an operator/parent experiences this as a
    smooth "same family, new record" transition is a **UX question, not a code-safety one**, and was
    never in scope for any phase.
13. **Old class/section:** untouched, remains historical on the old record.
14. **New class/section:** assigned fresh at the new admission's own enrollment.
15. **Old Roll Number:** untouched, historical.
16. **New Roll Number:** assigned fresh, independent of the old value — **CONFIRMED, no code path
    copies a Roll Number forward across a re-admission.**
17. **Old SRNO:** the digits themselves are the SAME as the new record's SRNO (§5) — there is no
    separate "old SRNO" distinct from the preserved value; the old record's own `srNo` field is never
    rewritten (still shows the same digits, now historically).
18. **New SRNO:** identical digits to the old, by design (§5).
19. **Student ID:** the old one is permanent and untouched; the new one is freshly, globally allocated.
20. **Independently auditable:** **CONFIRMED** — every collection remains keyed by its own Student ID;
    nothing merges. An auditor can query either Student ID's history in complete isolation.

**Direct answer to the prompt's explicit critical concern** ("do not accidentally create a system
where re-admission overwrites the historical admission"): **CONFIRMED this does not happen** — proven
by the isolation test cited repeatedly above, re-affirmed by this phase's fresh source read (§5), not
merely asserted.

## 9. Promotion Lifecycle

**CONFIRMED unaffected by SRNO/re-admission work — no overlap in code.** Promotion's own, separate,
pre-existing issue — **SL-12: `Dual_write::validateClass/validateSection` bypasses
`Entity_firestore_sync::normalizeClassSection()`** — was re-confirmed still live in Phase 6/Phase 7
(direct grep of `Dual_write.php`, unchanged since). **Re-confirmed here as still unfixed, per explicit
instruction to report accurately without fixing:** this is the same finding, not a new one; no source
change has occurred to this file across this entire program. Promotion does not touch Student ID
(global, permanent — confirmed by absence of any counter/claim call in `execute_promotion()`) and
does not touch SRNO at all (zero references, confirmed by grep in Phase 6). Roll Number is
re-evaluated within the new class/section per the existing SIS edit flow — unaffected by anything in
this program's own work.

## 10. Fees Integration

**CONFIRMED for the re-admission-specific question** (§8, items 7/8): old fees stay with the old
Student ID, new fees are created fresh for the new Student ID via the unmodified `assignInitialFees`
path. **Pre-existing, unrelated defect, re-confirmed present, not touched:** SL-13 — mid-year
admission proration is absent from `buildAdmissionDemandSpecs`, meaning **any** new admission (a
first-timer or a re-admitted student) whose enrollment falls mid-session is over-billed for months
before their join date. This is not new to re-admission and not caused by it — it is a general
admission-flow defect this program has repeatedly flagged and never authorized fixing.

## 11. Attendance Integration

**CONFIRMED unaffected structurally** — attendance is keyed `{schoolId}_{studentId}_{monthKey}`;
a new Student ID naturally starts a clean attendance history with no code path that could
accidentally inherit the old Student ID's records. **Pre-existing, unrelated, re-confirmed
unresolved:** SL-07 — the Attendance roster's "Strategy 2" fallback has no status filter (confirmed
live in Phase 0/6), meaning an Inactive/withdrawn student (which the *old* half of a re-admission pair
now is) could theoretically still surface in an active roster under specific query-miss conditions.
Not caused by SRNO/re-admission work; not touched by it.

## 12. Examination/Results Integration

**CONFIRMED unaffected by SRNO work.** Re-confirmed pre-existing, unrelated: RES-001 (live RTDB read
for `result_withheld` in `Result.php`, unchanged since Phase 0/6/7 — no file in this chain was ever
touched by any SRNO/re-admission pass); the report-card Roll Number wrong-source defect (§6).

## 13. Documents/Certificates/TC Integration

**Genuinely new finding for this phase — a real gap, not previously stated one way or the other:**
no prior phase traced what happens to a **document generation request** (a certificate, a TC, an ID
card) issued for a re-admitted student's *new* Student ID with respect to the *old* record's
documents. **NOT VERIFIED:** whether `Certificates.php`/TC generation has any awareness of
`previousStudentId` at all (almost certainly not, since Certificates.php was never touched by any
SRNO-related pass, and `previousStudentId` is a field this program introduced only on the `students`
collection, not referenced anywhere in document-generation code per a fresh grep this phase — **zero
matches for `previousStudentId` in `Certificates.php` or any view under `views/sis/` related to
document printing**). **Practical consequence, stated precisely:** a new TC/certificate for
`STU0047` would print `STU0047`'s own SRNO/Admission Number/Roll Number correctly (same mechanism as
any other student, unaffected) — but nothing surfaces "this student has a linked prior record" on
any printed document, which is a **missing feature**, not a defect (no document currently claims to
show that, so nothing is *wrong*, but a school wanting a TC to reference "previously admitted as
[old SRNO/dates]" has no code path for it). **Old documents/certificates themselves remain fully
untouched and historically correct** (§8 item 3) — this finding is about the *absence* of forward
awareness, not corruption of the past.

## 14. Transport Integration

**NOT VERIFIED — a genuine, newly-named gap.** No phase of this program has traced whether a
re-admitted student's *new* Student ID automatically, manually, or never inherits any transport
context from the *old* record. What is **CONFIRMED** (Phase 0/6): Transport's own core mechanics
(assignment, suspension-billing, rollover, the safety-critical boarding-roster gate) are sound in
isolation. What is **explicitly NOT traced by any phase**: whether `Transport_service.php` has any
code path that reads `previousStudentId` (a fresh grep this phase confirms **zero matches** for
`previousStudentId` anywhere under Transport-related files) — meaning a re-admitted student's
transport assignment, if the family wants one, would need to be **created fresh, exactly like any
new admission**, with **no automatic carry-forward and no automatic warning that the old record had
an active assignment.** This is architecturally *safe* (no cross-contamination, no old-Student-ID
data leaking onto the new one — confirmed structurally, since Transport's own assignment writes are
keyed to whatever Student ID is passed in, and nothing passes the old one during a re-admission flow)
but is a **missing convenience/workflow feature**, worth naming precisely as such rather than left
implicit.

## 15. Communication Integration

**NOT VERIFIED, same reasoning as §14.** Communication's per-student send paths (Phase 0/6:
`Communication_helper.php`'s queued notifications) key on whatever Student ID/`recipient_id` is
supplied at send time — **CONFIRMED no code path automatically forwards old notifications to a new
Student ID** (nothing in this program's grep history has ever found a `previousStudentId` reference
in any Communication file), so **historical notifications correctly remain attached to the old
record** (the prompt's specific worry — "re-admission does not cause historical notifications to
become incorrectly attached to the new admission" — is **CONFIRMED not happening**, by the same
absence-of-reference evidence). What's genuinely unverified is only the *positive* case: whether a
school wanting to notify a re-admitted family has any workflow awareness of the link — same
missing-convenience shape as §14, not a safety defect.

## 16. Parent App Integration

**CONFIRMED, re-affirmed from Phase 7/8.4, not re-investigated:** zero `srNo`/`previousStudentId`
references anywhere in the Parent App — confirmed by direct grep in two separate prior phases. A
re-admitted student's parent would experience this as: old account (tied to `STU0001`) stops working
once withdrawn (Phase 7's confirmed login-status gate), a **new** login/account would need to be
provisioned for `STU0047` exactly like a fresh admission — **CONFIRMED architecturally consistent**
with the "new admission = new identity" model this whole program has upheld, not a bug.

## 17. Teacher App Integration

**CONFIRMED, re-affirmed from Phase 7/8, not re-investigated:** zero `srNo`/`previousStudentId`
references; Roll Number fix intact (§6); roster resolution keyed correctly on Student ID throughout
(Phase 7's `StudentRepository.kt` audit). No SRNO-as-Roll-Number or Roll-Number-as-Student-ID
confusion found anywhere in either app across every phase this program has read their source.

## 18. Admin Panel Integration

**CONFIRMED** — `Readmission.php`'s two endpoints (`confirm`, `resolve`) are the only SRNO-aware
surfaces in the entire Admin Panel; everything else (SIS list, Student 360 — the pre-existing,
unrelated one — Fees, Attendance, Certificates) remains completely unaware of SRNO/`previousStudentId`,
confirmed by grep in Phase 6. This is **by design and disclosed** — no UI was ever built (Gap Closure
Report §11, re-affirmed unimplemented), so "the Admin Panel doesn't use SRNO anywhere except the one
authenticated API" is the correct, expected, current state, not a gap.

## 19. RBAC/Security

**CONFIRMED unchanged from the Gap Closure Report's own disclosed compromise:** `Readmission::confirm()`
gates on a hard role-name check (Super Admin/School Super Admin/Principal), deliberately narrower than
`SIS:manage`. **Re-confirmed, not re-decided:** no dedicated graded RBAC capability was added; this
remains an open, named, deliberate choice, not an oversight. Cross-tenant SRNO isolation for RBAC
purposes: **CONFIRMED** — `Sr_no_service` always operates through a schoolId-bound `$this->fs`
handle; no code path anywhere lets a School A operator's session resolve School B's SRNO, verified
structurally in the multi-tenant SRNO audit and re-affirmed here without re-testing (no code changed).

## 20. Firestore Rules

**CONFIRMED, re-read this phase (not assumed):** `match /srNoIndex/{docId} { allow read, write: if
false; }` remains the sole client-facing rule for this collection — deny-all, server-only, unchanged
since the Gap Closure Report added it. **RULE GAP, disclosed, not new:** no dedicated rule exists for
a hypothetical future client-side SRNO search feature (none was ever built, so none was needed) — this
is not a gap in the sense of "should exist and doesn't work," it's the correct state for a feature
that has no client surface yet.

## 21. Firestore Indexes

**CONFIRMED, unchanged:** no composite index was added or is needed for `srNoIndex` (every access is
a deterministic doc-id fetch, confirmed in the original architecture audit and never contradicted
since).

## 22. Data Lifecycle / Orphans

**CONFIRMED, re-affirmed, not re-tested:** the Gap Closure Report's `currentOwnerDeleted` flag on
hard-delete remains the only orphan-handling code, and the underlying policy question (should a
hard-deleted student's SRNO become reclaimable?) remains **BUSINESS DECISION REQUIRED**, exactly as
that report stated — not decided by this phase either.

## 23. Cross-Module Event Matrix

| Student Event | SIS | Fees | Attendance | Results | Documents | Transport | Communication | Parent | Teacher |
|---|---|---|---|---|---|---|---|---|---|
| First admission | CREATED | CREATED (best-effort) | NOT APPLICABLE (starts fresh) | NOT APPLICABLE | NOT APPLICABLE | NOT APPLICABLE unless requested | NOT APPLICABLE | CREATED | NOT APPLICABLE until roster refresh |
| Class assignment | CREATED | NOT APPLICABLE | NOT APPLICABLE | NOT APPLICABLE | NOT APPLICABLE | NOT APPLICABLE | NOT APPLICABLE | NOT APPLICABLE | UPDATED (roster) |
| Section assignment | CREATED | NOT APPLICABLE | NOT APPLICABLE | NOT APPLICABLE | NOT APPLICABLE | NOT APPLICABLE | NOT APPLICABLE | NOT APPLICABLE | UPDATED (roster) |
| Roll assignment | CREATED | NOT APPLICABLE | NOT APPLICABLE | NOT APPLICABLE | NOT APPLICABLE | NOT APPLICABLE | NOT APPLICABLE | NOT APPLICABLE | UPDATED (display/sort) |
| Promotion | UPDATED | UPDATED (deferred, SL-09) | PRESERVED (session-scoped, not reset) | PRESERVED | NOT APPLICABLE | NOT APPLICABLE | NOT APPLICABLE | NOT VERIFIED | UPDATED, **BROKEN if non-canonical (SL-12)** |
| Fee assignment | NOT APPLICABLE | CREATED | NOT APPLICABLE | NOT APPLICABLE | NOT APPLICABLE | NOT APPLICABLE | NOT APPLICABLE | NOT VERIFIED (§Phase 8.4's own NOT VERIFIED item, unchanged) | NOT APPLICABLE |
| Attendance start | NOT APPLICABLE | NOT APPLICABLE | CREATED | NOT APPLICABLE | NOT APPLICABLE | NOT APPLICABLE | NOT APPLICABLE | NOT VERIFIED | NOT APPLICABLE |
| Exam/result | NOT APPLICABLE | NOT APPLICABLE | NOT APPLICABLE | CREATED | NOT APPLICABLE | NOT APPLICABLE | NOT APPLICABLE | NOT VERIFIED | UPDATED |
| Document generation | NOT APPLICABLE | NOT APPLICABLE | NOT APPLICABLE | NOT APPLICABLE | CREATED | NOT APPLICABLE | NOT APPLICABLE | NOT VERIFIED | NOT APPLICABLE |
| Leaving/TC | UPDATED (status) | UPDATED (freeze, withdraw path only — §Phase 0's confirmed TC/withdraw asymmetry) | PRESERVED | PRESERVED | HISTORICAL | **NOT VERIFIED — no confirmed release, SL-21 for hostel specifically** | NOT VERIFIED | HISTORICAL (login blocked) | UPDATED (roster excludes on next fetch) |
| Re-admission | CREATED (new record) | CREATED (fresh, new Student ID) | CREATED (fresh) | CREATED (fresh) | **NOT VERIFIED (§13)** | **NOT VERIFIED (§14)** | **NOT VERIFIED (§15)** | CREATED (new account needed) | UPDATED (new roster entry) |
| SRNO relink | NOT APPLICABLE | NOT APPLICABLE | NOT APPLICABLE | NOT APPLICABLE | NOT APPLICABLE | NOT APPLICABLE | NOT APPLICABLE | NOT APPLICABLE | NOT APPLICABLE — **CONFIRMED isolated to `srNoIndex` + the new student doc only** |
| Hard delete | UPDATED (cascade delete, confirmed Phase 0) | HISTORICAL data deleted via cascade | HISTORICAL data deleted via cascade | HISTORICAL data deleted via cascade | NOT VERIFIED | NOT VERIFIED | NOT VERIFIED | NOT VERIFIED | NOT VERIFIED |
| Soft delete | UPDATED (status flag) | PRESERVED | PRESERVED | PRESERVED | PRESERVED | NOT VERIFIED | NOT VERIFIED | Login blocked (confirmed, Phase 7) | Roster-filtered (confirmed, Phase 7) |

## 24. Cross-Platform Contract Matrix

Unchanged from Phase 8.4 §12 for every row already built there (`schoolId`, `schoolCode`, Student ID,
SRNO, Roll Number, class/section, session, Auth UID, tenant identity) — **not rebuilt here**. New rows
specific to this phase's re-admission scope:

| Contract | Admin Panel | Parent App | Teacher App | Firestore/Auth | Status |
|---|---|---|---|---|---|
| `previousStudentId` | Written by `Sr_no_service::relink()` only | **Zero references (confirmed)** | **Zero references (confirmed)** | `students.previousStudentId` field | **CONFIRMED consistent — a backend-internal linkage field by design, correctly absent from both client apps since no UI/API surfaces it to them** |

## 25. Field Authority Matrix

| Field | Canonical Source | Writers | Readers | Scope | Lifecycle behavior | Risk |
|---|---|---|---|---|---|---|
| `studentId` | `Id_generator.php` | `Sis::enroll_student` only | Universal | Global | Permanent, never reassigned | None found |
| `srNo`/`srNoNorm` | `Sr_no_service::claim`/`relink` | Same, single writer each | `Sr_no_service::resolve`, `Readmission::confirm` | School | Preserved across re-admission | None found |
| `rollNo`/`Roll No` | `Sis.php` (admission/edit, dual-keyed) | `Sis.php` only | Multiple (report cards — **wrong source, §6**; TC — **wrong label, §6**; exam rosters — correct) | Class/section | Freshly assigned per admission, never inherited | **Confirmed reader-side defects, pre-existing, unrelated to this phase** |
| `className`/`section` | `enroll_student` (canonical), `Dual_write::validateClass/Section` (**non-canonical, SL-12**) | Two divergent writers | Many | Session | Re-evaluated at promotion | **Confirmed live drift risk (SL-12), pre-existing, unrelated** |
| `session` | `enroll_student`, promotion | Consistent | Universal | — | In-place update | None found |
| `status`/`Status` | Multiple writers, dual-keyed (SL-06) | Multiple | Multiple, fail-open default | — | — | **Confirmed pre-existing risk, unrelated to this phase** |
| `previousStudentId` | `Sr_no_service::relink` | Single writer | `Readmission::confirm` (self-check only) | Cross-admission | Set once, never rewritten | None found — narrow, single-purpose field |
| Parent relationship | `Entity_firestore_sync::syncParent` | Single writer | Parent App | Per-student (not per-family — pre-existing, disclosed design gap, Phase 1) | Fresh per admission | Pre-existing, unrelated |

## 26. Student 360 Future-Proofing

**CONFIRMED the current architecture supports it, without re-designing anything.** Every question the
prompt poses ("what admissions has this student had," "what was their SRNO/Student ID per admission,"
etc.) is answerable via a **read-time aggregation** over the `previousStudentId` chain — exactly the
model this program's own architecture audits already specified and never contradicted. **What remains
missing is purely the aggregation code itself** (Student 360/Student Trail do not exist as features,
confirmed repeatedly across this program) — not a data-model gap. The three §13/§14/§15 findings
(Documents/Transport/Communication not yet chain-aware) are exactly the kind of thing a future Student
Trail would need to *add*, not evidence that today's data model can't support it.

## 27. Professional ERP Comparison

Measured against the prompt's own checklist, using only concrete, sourced evidence:

- **Immutable/global identity:** CONFIRMED present (Student ID).
- **Tenant-scoped human identifiers:** CONFIRMED present (SRNO).
- **Admission history:** CONFIRMED present (`previousStudentId` chain).
- **Lifecycle history:** PARTIAL — strong for identity/fees/attendance/results (each Student ID's own
  collections are complete and untouched by re-admission); **weak for Transport/Communication/Documents
  chain-awareness** (§13-15).
- **Auditability:** CONFIRMED strong for SRNO relink specifically (dual-tier `log_audit` +
  bounded-cache pattern); **pre-existing gaps elsewhere** (no dedicated promotion audit collection,
  SL-08, unrelated to this phase).
- **Session/class/section separation:** CONFIRMED present, with the one **pre-existing, unrelated**
  canonicalization risk (SL-12).
- **Financial history preservation:** CONFIRMED for re-admission specifically (§10); **pre-existing,
  unrelated overbilling defect** for mid-year admissions generally (SL-13).
- **Re-admission traceability:** CONFIRMED, the program's strongest area.
- **Cross-platform consistency:** CONFIRMED for everything both apps actually touch; **N/A** for what
  neither app touches yet (SRNO/`previousStudentId` — correctly absent, not missing).
- **Security isolation:** CONFIRMED (multi-tenant SRNO tests, cross-tenant rejection tests).
- **Clean source of truth:** MOSTLY CONFIRMED, with the same pre-existing exceptions already named
  (`status`/`Status` dual-keying, SL-06).

## 28. Performance Findings

**CONFIRMED, unchanged from the original architecture audit:** every SRNO operation is a deterministic
doc-id fetch or a bounded 2-document atomic batch — no unbounded scan, no N+1, no cross-tenant scan.
**Re-confirmed this phase, not newly found:** `Red_flags::get_student_flags()`'s whole-school scan
(RF-001) remains a real performance defect, entirely unrelated to and untouched by any SRNO/re-admission
work.

---

## Confirmed Defects (all pre-existing, none caused by SRNO/re-admission work)

SL-06, SL-07, SL-09, SL-12, SL-13, RES-001, CERT-001, RF-001, the report-card Roll Number source
defect, the TC Roll-Number-labeled-as-Admission-Number defect — every one re-confirmed present by
direct source re-check at some point in this program, none touched, none authorized for fixing here.

## Missing Features

- SRNO operator UI (disclosed, unimplemented, unchanged).
- Dedicated `correct_sr_number` typo-correction path (disclosed, unimplemented).
- Re-admission chain-awareness in Documents/Transport/Communication (§13-15, genuinely newly named
  this phase).
- Student 360/Student Trail themselves (confirmed nonexistent, architecture supports them per §26).

## Architectural Risks

- The hard-role-check RBAC compromise (§19) — a named, deliberate, not-yet-resolved choice.
- SL-12's cross-module canonicalization drift — the single highest-leverage pre-existing risk this
  program has repeatedly flagged, because it's the one defect capable of silently breaking *other*
  modules' ability to find a student at all.

## Pre-existing Issues

Listed in full in "Confirmed Defects" above — restated here only to satisfy the report structure, not
duplicated in content.

## Business Decisions Required

- The orphan-`srNoIndex` reclaim policy on hard-delete (Gap Closure Report, unchanged).
- The RBAC graded-capability question (§19).
- Whether Transport/Communication/Documents should gain re-admission chain-awareness at all, and if
  so, what that experience should look like (§13-15 — this phase names the gap, does not propose the
  UX).

---

## Final Decision

### A. Safe to Implement (i.e., safe to build further work on top of)
The identity core: Student ID, SRNO claim/relink/resolve, `previousStudentId` linkage, cross-tenant
isolation, the Fees/Attendance/Results non-contamination guarantee for re-admission.

### B. Implementation Blockers
**None found.** Every item in this report is either a pre-existing, independently-tracked defect
unrelated to the identity core, or a disclosed missing feature — nothing blocks continued Student
Lifecycle work.

### C. Non-Blocking Defects
SL-06, SL-07, SL-09, SL-13, RES-001, CERT-001, RF-001, report-card/TC Roll Number defects — all
pre-existing, all independently trackable, none urgent relative to identity-core work.

### D. Cross-Module Gaps
§13 (Documents), §14 (Transport), §15 (Communication) — genuinely new findings this phase, all
**NOT VERIFIED → now named as missing-feature gaps, not safety defects.**

### E. Cross-Platform Gaps
None found beyond what Phase 7/8.4 already named (both apps correctly have zero SRNO awareness,
which is expected, not a gap).

### F. Security/RBAC Gaps
§19 — the hard-role-check compromise, unchanged, disclosed.

### G. Data-Integrity Risks
SL-12 (highest-leverage pre-existing risk), SL-06 — both unrelated to and untouched by this program's
identity work.

### H. Performance Risks
RF-001 — pre-existing, unrelated.

### I. Business Decisions Required
Listed above in full.

### J. Pre-existing Issues
Listed above in full.

### K. Deferred Issues
The Phase 8.3 bootstrap-login edge case (Phase 8.4: confirmed zero practical impact, correctly
deferred).

### L. Recommended Implementation Order
1. SL-12 fix (unblocks confident cross-module querying for *everything* downstream of class/section,
   the single highest-leverage pre-existing fix available).
2. SRNO operator UI (makes the identity core's work actually usable).
3. §13-15's chain-awareness features, once a UX decision is made for each.
4. The remaining pre-existing defects (SL-06, SL-07, SL-09, SL-13, RES-001, CERT-001, RF-001,
   report-card/TC label fixes), independently prioritizable, none blocking the others.

---

## Final Verdict

**READY WITH CONDITIONS.**

Not "READY FOR IMPLEMENTATION" unconditionally, because real, if non-blocking, pre-existing gaps
remain (§B is empty, but §C/§D/§G are not) — calling this unconditionally ready would understate what
this report just spent 28 sections documenting. Not "BLOCKED," because nothing in this report rises to
an actual blocker: every finding is either already-known-and-tracked or newly-named-but-narrow
(§13-15's missing chain-awareness, which is additive future work, not a present danger). The
conditions are exactly the items in §L, none of which require re-litigating the identity architecture
this program has already settled three times over.

---

## Method Disclosure

- **Repositories inspected:** all three (`c:\xampp\htdocs\ZenX\school`, `D:\Projects\SchoolSyncParent`,
  `D:\Projects\SchoolSyncTeacher`).
- **Files/modules freshly re-read this phase:** `Sr_no_service.php` (relink logic, §5),
  `Readmission.php` (confirmed unchanged), `firestore.rules` (`srNoIndex` block, §20), targeted greps
  for `previousStudentId` across Transport/Communication/Certificates files (§13-15).
- **Tests/builds actually run this phase:** none — no code was changed, so no new compile/test cycle
  was warranted; this phase relies on the already-current test/build state confirmed in Phase 8.4
  (635 PHP tests/10 pre-existing failures, both Kotlin apps building clean), re-verified as recently
  as the immediately preceding phase, not re-run redundantly here.
- **Production data accessed:** no — this phase performed no live Firestore/RTDB read (Phase 8.4's
  fresh read is the most recent one, unchanged since).
- **Production data changed:** no.
- **Firestore rules changed:** no.
- **Firestore indexes changed:** no.
- **Files modified:** none.
- **Files created:** one — this report.
- **Git status:** unchanged in all three repositories from the state Phase 8.4 left them in (390 PHP
  repo status lines, 14 Parent App, 19 Teacher App — same pre-existing unrelated footprint throughout).
- **Commits/staging/push status:** nothing staged, committed, or pushed anywhere.
- **Explicitly NOT VERIFIED, carried forward honestly:** §13 (Documents chain-awareness), §14
  (Transport chain-awareness), §15 (Communication chain-awareness), the Fees/Attendance parent-app
  visibility items marked NOT VERIFIED in the event matrix (§23), Case H/I from Phase 8.4's
  authentication matrix (stale/incorrect claims — never investigated by any phase), `Schools.php`'s
  legacy RTDB write reachability (never resolved since Phase 8.2).
