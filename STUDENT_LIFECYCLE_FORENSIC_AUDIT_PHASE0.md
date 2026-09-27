# Student Lifecycle Forensic Audit — Phase 0

**Date:** 2026-08-13 · **Status: READ-ONLY AUDIT. Nothing in this document has been implemented.**
**Scope:** Complete student journey, ENQUIRY → ARCHIVE/RE-ADMISSION, across the ZenX admin panel
(CodeIgniter 3, Firestore-backed). Every claim below carries file:line evidence gathered directly
from the current repository — not from prior narrative, and not assumed from a screen/collection/
controller merely existing.

**Working tree: HELD.** No code, data, Firestore, rules, indexes, RBAC, or test data changed.
No commits, no staging, no push.

---

## Part 1 — The 12 executive identifier questions (plain language)

**1. Is STU0001 currently the true canonical internal student identity?**
Yes, unambiguously. It's the Firestore doc-id component (`{schoolId}_{studentId}`), the Firebase
Auth uid/email local-part, it's embedded in custom claims, and it's the foreign key on every
dependent collection. 63 controller files reference `studentId`.

**2. Can STU0001 safely remain permanent and immutable?**
Yes. Nothing in the live codebase touches, mutates, or proposes replacing it. It should stay
exactly as-is.

**3. Does ZenX currently have a reliable SRNO?**
No. It doesn't exist in code — only in design docs (`SR_NUMBER_AND_APAAR_ID_DESIGN.md`). The two
live references (`Student_context.php:462`, `workspace.php:876`) are read-only pass-throughs of a
field (`srNumber`) that nothing ever writes.

**4. Can SRNO safely become the primary human-facing operational number?**
Yes, architecturally — but it has to be built first. Today the codebase has silently substituted
`Admission Number` for this role (`Certificates.php:384,460` prints it on every TC/certificate),
and that field also has no writer anywhere. TCs are printing a fabricated/blank register number
right now — this is a live, ongoing document-correctness defect, not hypothetical.

**5. Can the architecture use SRNO → Student ID → canonical student, without replacing Student ID?**
Yes. This is exactly what the existing (unimplemented) design specifies: `srNoIndex/{schoolId}_
{srNoNorm} → {studentId}` as an atomic claim-doc lookup layer, with `studentId` remaining the sole
primary key underneath. No architectural conflict with anything found in this audit.

**6. Which modules already support SRNO correctly?** None. Zero implemented.

**7. Which modules still require Student ID?** All of them — SIS, Fees, Attendance, Transport,
Hostel, Communication, Discipline, Documents, Certificates, HR/Staff (parallel STA scheme),
Firebase Auth/claims. Total, not partial.

**8. Which modules incorrectly use Admission Number after admission?**
Only `Certificates.php` (TC/certificate generation) and `Student_context.php`/`workspace.php`
(Student 360 display) *read* it post-admission — and since nothing writes it, they display blank or
the STU-id fallback. One related mislabel: `views/sis/tc_print.php:23,433` prints **Roll No** under
the label **"Adm No."** — a UI mislabel conflating two different identifiers, independent of the
missing-writer issue.

**9. Which modules incorrectly use Application Number after admission?**
None operationally. `application_id`/`app_id` survives onto the enrolled student doc only as a
hidden, unlabeled back-link field (`Sis.php:7633`) for internal traceability — never displayed,
never used as a lookup key post-admission. Not a violation, but there is a real internal
inconsistency worth naming: **two different generators** produce differently-shaped `app_id`
values for the same conceptual field (`APP#####` atomic counter in `Sis.php:6256-6284` vs.
`APP_<hex>_<rand>` ad-hoc scheme in `Admission_public.php:400-401`).

**10. Biggest identity/data-integrity risk today?**
Two, both confirmed live: (a) every certificate/TC prints a register number with no writer behind
it (`Certificates.php`); (b) **re-admission of a former student creates an entirely new STU id with
zero link to their old record** (`Sis.php:7486` — `enroll_student()` always calls `_nextStudentId()`
with no dedup/lookup against prior withdrawn/TC'd/deleted students). Attendance, fees, results,
discipline history, and prior TCs all stay orphaned under the old id forever. This is the single
largest traceability gap found in the entire audit — see Part 2, §20.

**11. Minimum architecture required to make SRNO operational?**
Exactly what's already designed and not yet built: `srNo`/`srNoNorm`/`srNoSeq` fields on `students`,
atomic claim doc `srNoIndex/{schoolId}_{srNoNorm}`, one Firestore index, allocation UI at enrolment,
dual-emit into `Admission Number` (so `Certificates.php` needs zero edits), exact-match search on
`srNoNorm`. Fully spec'd in `SR_NUMBER_AND_APAAR_ID_DESIGN.md`. Not implemented in this audit, per
instruction.

**12. What MUST NOT be changed?**
The STU/STA global atomic counter and its coupling to the flat Firebase Auth email scheme
(`identity_architecture_decision.md`); the STU doc-id/foreign-key scheme across all collections;
`Certificates.php`'s existing read logic (design routes around it via dual-emit, not an edit); the
two unrelated `ADM`-prefixed schemes (staff-account id generator in `Admin.php:1452`, admission-fee
receipt key in `Admission_collection.php:305`) — neither should be conflated with a future student
"Admission Number."

---

## Part 2 — Complete lifecycle audit, stage by stage

Each stage: what exists, what's verified, file:line evidence, classification.

### 1. ENQUIRY — **PARTIAL**
A distinct `crmInquiries` collection exists with its own atomic id (`INQ#####`, `Sis.php:6256-
6284`) and full CRUD (`save_inquiry` `6638`, `convert_to_application` `6696-6733`, guarded against
double-conversion). **But the primary public intake channel bypasses it entirely** —
`Admission_public::submit` (`Admission_public.php:279-570`) writes straight to `crmApplications`
with `status:'pending'`, never touching `crmInquiries`. Enquiry-to-application conversion is
reachable only for enquiries staff typed in by hand. Funnel analytics (`crm_analytics`,
`Sis.php:6521-6580`) therefore undercount real inbound interest for the majority path.

### 2. APPLICATION — **VERIFIED with a confirmed ID-scheme fork**
`crmApplications` state machine: `pending → approved|rejected|waitlisted → enrolled`, enforced ad
hoc per-endpoint (not a central transition table) but every transition uses CAS-guarded appends
(`Sis.php:7250-7253,7275-7278,7789-7792`) — concurrency-safe. **Two divergent id-generation
schemes confirmed** (see Part 1 Q9). **Two divergent duplicate-detection policies confirmed**: the
public form (`Admission_public.php:335-393`) hard-blocks only on phone+class+name+2-min window
(explicitly tuned to allow siblings/twins); the admin proxy path (`Sis.php:8375-8386`) hard-blocks
on phone+session alone, regardless of class/name — a materially different rule for the same
collection. Data captured is rich (personal, guardian, previous school, entitlement declarations,
document refs, DPDP consent) — see `Admission_public.php:441-521`.

### 3. ADMISSION (approval / admission-fee) — **VERIFIED, well-isolated, unusually hardened**
Approval RBAC-gated (`Sis.php:7237,7262`, `MANAGE_ROLES`/`SIS:manage`) with dual audit
(bounded `history[]` + permanent `auditLogs`). Admission-fee collection runs through a **separate**
ledger (`Admission_collection.php`) with its own idempotency claim (`Accounting_idempotency`,
`110-138`), atomic receipt allocator (`286-306`, throws rather than fabricates on exhaustion), and
explicit journal-failure surfacing (no silent loss). **Gap found:** rejected/waitlisted
`crmApplications` — containing full PII including uploaded Aadhaar/photo docs — have **no
retention/TTL/purge logic anywhere**; they persist indefinitely. Not a functional defect, a data-
retention/privacy gap.

### 4. STUDENT CREATION — **VERIFIED, deliberately compensating (not fully atomic)**
`enroll_student()` (`Sis.php:7486-7920`): cross-process mutex lock (`7509-7517`) → STU id claim →
fee-structure pre-flight (`7575-7582`, blocks "Active with zero demands") → idempotency claim
(`7657-7680`) → fail-loud student save (aborts + releases slot on failure, `7684-7692`) →
`application_id` back-link written (`7633`) → CRM flipped to `enrolled` via CAS (`7789-7792`) →
Firebase Auth creation, which is **deliberately non-fail-loud**: on failure the student/CRM state
stays committed and a `repair_student_auth` endpoint (`7965`) exists specifically to retry — this
is documented, intentional design closing a prior orphan-Auth bug, not an oversight. Fee assignment
at enrolment (`Fee_lifecycle::assignInitialFees`, `7863`) is best-effort with operator-visible
`warnings[]` on failure, not silent. **No Cloud Function `onCreate` trigger exists** on
`students`/`crmApplications` (grepped `functions/index.js` and `fee_generation_worker.js` — no
matches) — the whole sequence is synchronous, controller-driven, not event-fanned-out.

### 5. ACTIVE STUDENT (status) — **PARTIAL, one confirmed leak**
Status lives on the student doc under **two competing field names** — camelCase `status`
(canonical) and legacy `Status` — with defensive `?? 'Active'` fallback at every read site
(`Sis.php:141,155,...`; `Attendance.php:7000,7026`), meaning **absence of a status field defaults
to Active (fail-open)**. Most rosters correctly filter Active (`Sis.php:1785,4026,6040,8068`).
**Confirmed leak (UNSAFE):** `Attendance.php` `_get_section_students()` "Strategy 2" fallback
(`5430-5439`) queries by Class/Section with **no status predicate**, feeding results straight into
the attendance roster with zero re-filter — contrast with the sibling routine at `7017-7039` which
runs the identical unfiltered query but does re-filter in PHP, proving the omission is an
inconsistency, not a design choice. Fires only when the primary Active-filtered query returns
empty, but on any transient miss it can surface Inactive/TC students into attendance marking.

### 6. SESSION ROLLOVER — **VERIFIED (in-place update, matches [[student_session_canonical]])**
No standalone student-side rollover batch job exists. Session change happens only as a side effect
of `execute_promotion` via `Dual_write::batchMoveStudents()` → `syncStudent()`, which merges
`'session' => $toSession` onto the **same** doc (`Dual_write.php:400`) — no new doc, no session-
history array. Confirms the canonical-singular-session memory as still accurate.

### 7. PROMOTION / DETENTION — **VERIFIED promotion; MISSING dedicated detention trail**
`execute_promotion()` (`Sis.php:1345-1900+`) writes new class/section/session in place, logs a
`PROMOTION` history entry (`1525`, the only audit trail — no separate `promotionHistory` collection
exists anywhere). **Fee regeneration is deferred past the HTTP response** via
`register_shutdown_function` (`1644`) calling `Fee_lifecycle::reassignFeesOnPromotion()` — success
is reported to the operator *before* fees are actually regenerated; a zero-regenerated outcome is
only visible via a `promote_jobs/{batchId}` status doc or logs, never in the response the operator
already saw. Cross-app propagation failures are log-and-continue, never surfaced. **Detention has
no dedicated code path at all** — grep for `detain|Detain` returns zero matches. It's implicit: a
student simply omitted from `student_ids` stays put, with no "retained" flag and no audit entry
distinguishing detention from withdrawal-in-progress.

### 8. CLASS / SECTION CHANGES (mid-session, non-promotion) — **MISSING / UNSAFE-by-omission**
No standalone "change section" action exists (grep returns zero matches). `edit_student()`
explicitly **pins Class/Section to existing values regardless of POST body**
(`Sis.php:5574`) — any submitted class/section change is silently discarded. The only way to move
one student mid-session is running full promotion machinery with a single-element `student_ids`.
**Confirmed canonical-normalization bypass:** `execute_promotion` and `batchMoveStudents` build
class/section keys via a bare prefix helper (`Firestore_service::classKey/sectionKey`), **not**
`Entity_firestore_sync::normalizeClassSection()` (used correctly by Attendance, Academic, Homework,
Timetable, Subject_assignment, Lesson_plan, Gate_pass, Substitute). An admin promoting into raw
`"10"` writes `className="Class 10"`, diverging from the canonical `"Class 10th"` shape every other
module expects — this is the shape-drift risk from `[[student_class_section_canonical]]`,
**confirmed live, not hypothetical**.

### 9. FEES — **MIXED: one confirmed live financial defect, two prior claims refuted as already fixed**
- **BROKEN, confirmed, HIGH financial risk:** `Fee_lifecycle::buildAdmissionDemandSpecs()`
  (`Fee_lifecycle.php:220-350`) has **zero** join/admission-date logic anywhere in its body —
  verified by full-function grep. It bills every month in the fee chart regardless of when the
  student actually joined. Both the legacy path and the newer flag-gated
  `Fee_generation_service::generateDemandsForStudent` call into this same unfiltered function. **A
  student admitted mid-session is billed for months before they enrolled — live today.**
- **REFUTED (already fixed):** transport-suspension-doesn't-stop-billing. `suspendAssignment()`
  (`Transport_service.php:2763-2809`) now calls `Fee_lifecycle::proRateFees(...)`, tagged "G1 fix
  2026-08-07/08" in-code. Residual risk: the call is try/catch-wrapped, so a proration *failure* is
  swallowed/logged only — suspension still succeeds even if the billing correction silently fails.
- **REFUTED (retired, verify one detail):** the Cloud-Function-races-PHP-generator defect.
  `fee_generation_worker.js` is present but its own header states RETIRED (G12, 2026-08-08),
  unexported. The trigger code is intact and dangerous if ever re-wired — the retirement itself
  (i.e. that `functions/index.js` truly omits the export) was not independently re-confirmed in
  this pass and should be spot-checked before treating this as fully closed.
- **CONFIRMED:** three distinct, non-unified discount mechanisms — `studentConcessions` (read by
  the flag-gated unified generator), `studentEntitlements` (its own service stack, explicitly **not
  consumed** for pricing per the generator's own docblock), and `studentDiscounts` (read directly by
  `Fees.php` inside the **payment/receipt** flow, not demand generation). Demand generation and
  receipt-time discounting are two different reads of two different collections.
- **VERIFIED, solid:** payment/journal posting (`Operations_accounting::create_fee_journal` →
  `_create_fee_journal_v2`) is properly idempotent via a deterministic key + claim-doc dedup.

### 10. ATTENDANCE — **VERIFIED**
Keyed `{schoolId}_{studentId}_{monthKey}` (`Firestore_service.php:186-189`) — calendar-month keyed,
not session-namespaced, and not reset on promotion (no promotion-triggered rewrite found). RBAC
graded, correction/audit trail implemented (`attendanceAuditLog`, `Attendance.php:545-760`). No
cross-link found in either direction between Fees and Attendance — independent subsystems today
(not necessarily wrong, but worth knowing if a "block a defaulter from marking present" rule is
ever wanted).

### 11. EXAMINATION (setup/roster) — **VERIFIED**
Exam definitions in `examSchedule` (Firestore). Roster built by `Roster_helper.php`, keyed by
`studentId`, with `RollNo` as a payload field only — no identity-key confusion at the roster level.

### 12. MARKS ENTRY — **VERIFIED, strong controls**
Max-marks clamped to template (`Result.php:855-857`). Absent-marking preserves the flag rather than
fabricating a zero; downstream computation correctly yields `Percentage=null, Grade='AB'`
(`Exam_engine.php:250-261`). **Full audit trail on every save** — before/after `componentMarks` into
a `marksAudit` collection with actor + correlation id + publication-state, CAS-guarded against
concurrent edits. Post-publication edits are not *blocked*, but are fully *audited* — traceability
exists even without a hard lock.

### 13. RESULTS — **one confirmed live RTDB violation (RES-001), one confirmed cosmetic bug**
- **UNSAFE/LEGACY, confirmed still live:** `Result.php:314,451,1697` (three call sites) call
  `Firebase::get("Schools/{school}/{session}/Fees/Defaulters/{userId}")` — a genuine RTDB path read,
  not Firestore — to fetch `result_withheld`. The `758c924` RTDB-retirement commit did **not** touch
  `Result.php` (confirmed via `git log`/`git show --stat`). This directly violates the project's
  zero-RTDB hard rule and is the third such surviving dependency after CERT-001 and ATT-001.
- **BROKEN, confirmed:** all 7 report-card templates source "Roll No" from `$profile['User Id']`
  (`_report_card_data.php:26`), not from the actual `RollNo` field the roster/marks-entry path uses.
  Every printed report card shows the login identifier where the class roll number belongs.
- **MISSING (display-only, no enforcement):** `result_withheld` gates report-card/PDF rendering
  only. Grep of `Sis.php` for withheld/result terms returns zero matches — neither `execute_
  promotion()` nor `issue_tc()` checks it. A student with withheld results can be promoted or
  issued a TC with no obstruction.

### 14. TRANSPORT — **VERIFIED, one confirmed prior fix, one safety-critical control confirmed sound**
Assignment keyed by `studentId`, atomic capacity reservation on assign (`Transport_service.php:
2523`). `suspendAssignment` now bills correctly (see §9). Session rollover is auto-carryover, not
re-assignment, archiving the prior-session doc and creating a fresh demand — **except** suspended
assignments, which correctly skip re-billing (a documented 2026-08-08 follow-up fix). **Safety-
critical boarding gate verified sound:** `_writeBoardingEvent()` requires the student to be on the
trip's frozen roster snapshot; off-roster boarding is rejected by default. RBAC graded throughout,
with a self-test asserting it.

### 15. HOSTEL — **VERIFIED as a real module (prior "doesn't exist" claim refuted), one financial/operational gap**
`Hostel.php` (560 lines) is a fully built Phase-5 Firestore module — not a placeholder. Capacity
enforcement with post-write rollback on a race (`276-375`). Fee linkage exists (`createModuleFee`)
but is best-effort — a room can be occupied with no fee demand raised if that call fails silently.
**Confirmed gap:** TC issuance (`Sis.php:1931`) never calls into `Hostel::delete_allocation` — a
student can be issued a TC while still occupying a hostel bed, which stays blocked for future
allocation until an operator manually intervenes. Also: `Hostel` module is absent from
`functions/rbac_modules.json`'s mirror (NOT VERIFIED whether this is drift or intentional).

### 16. DISCIPLINE — **VERIFIED with one confirmed performance defect (RF-001)**
`get_student_flags()` performs **zero server-side filtering by studentId** — it pulls up to 500+500
rows school-wide and filters in PHP (`Red_flags.php:143-292,619-699`). Confirmed as previously
logged, not refuted. Parent-facing read access is real and direct via Firestore rules
(`firestore.rules:2640-2691`) with proper tenant/ownership gating, but **no field-level redaction**
— parents see full type/severity/message/teacherName for their own child, which is presumably
intended but worth naming explicitly.

### 17. DOCUMENTS — **VERIFIED**
The canonical `Document_service.php` is real, with a proper API surface (upload/replace/link/
unlink/delete/verify) and a genuine audit trail (`auditLogs`, tenant-isolation-enforced reads).
Spot-checked consumers (Sis, Student360, Admission_public, Campus_access, Visitor_service,
Transport_compliance_service) delegate rather than reimplement.

### 18. CERTIFICATES — **BROKEN on two independent axes**
- Storage is **100% RTDB** (`Certificates.php:519`) — this is CERT-001, confirmed still current,
  the same platform-rule violation category as RES-001 above.
- The Admission Number printed on every certificate has no writer (confirmed, see Part 1 Q4/Q8).
- No audit log call on certificate generation (unlike Document_service), and **no reprint-vs-
  original distinction** — regenerating the same certificate twice produces two indistinguishable
  "original" records with no `sourceCertId`/`isReprint` linkage.

### 19. PARENT / FAMILY — **PARTIAL, one confirmed structural risk**
Parent linkage is genuinely per-student (one `parents/{schoolId}_{studentId}` doc per child), **not**
per-family — the multi-child `childrenIds[]` accumulation described in the code's own docblock does
not actually exist (`Entity_firestore_sync.php:400-465`); a parent with two children at the school
holds **two separate Firebase Auth identities**, one per child. **Confirmed, matches prior claim:
sibling relationships are not structurally recorded anywhere.** `sibling` exists only as a free-text
`concessionType` enum value with no relational field — a staff member can grant a sibling discount
with nothing in the system verifying a second child exists or is enrolled. This is a fraud/audit-
risk finding, not a cosmetic gap. Parent-initiated entitlement requests (transport/hostel/sibling/
etc.) are, by contrast, well-guarded: server-verified ownership, `approvalRequired` hard-forced
true, a parent can never self-grant.

### 20. COMMUNICATION — **PARTIAL, one confirmed fabricated-status defect**
Direct messaging is formally retired (HTTP 410, history frozen not deleted). Broadcast (Notices/
Circulars) is correctly broadcast-only, no per-student retrieval — confirmed, Student 360 has zero
communication references. Module-native per-student sends (e.g. fee-payment confirmations) do
exist but land in a queue keyed by recipient, not indexed for later per-student lookup from any UI
found. **Confirmed defect:** the queue processor genuinely delivers push notifications only — SMS
and email are an explicit code-commented **"placeholder — mark as sent for now,"** fabricating a
`success:true` status with no actual gateway call. Whoever relies on delivery stats for SMS/email
today is reading fiction.

### 21. TRANSFER / WITHDRAWAL — **PARTIAL (functionally complete, architecturally unconsolidated)**
No `Student_lifecycle_service` exists (zero grep hits) — that was a design proposal, never built.
The underlying behavior is real but spread across three separate ad-hoc controller methods:
`withdraw_student()` (sets `Inactive`, freezes fees, blocks on dues unless overridden),
`delete_student()` (soft: `Deleted` + reason + timestamp; hard: cascading delete across 8
collections + Auth, irreversible by design), and `change_status()` (generic Active⇄Inactive
toggle). All three work; none share a common service class.

### 22. TC / EXIT — **VERIFIED, atomicity fix confirmed still in place**
`issue_tc()` sets a distinct `status='TC'`, blocks on outstanding dues, disables Auth. **Note:** TC
issuance does **not** call the fee-freeze path — only `withdraw_student()`/`delete_student()` do; TC
relies on the dues-clearance gate instead. Fully reversible via `cancel_tc()` (re-activates status,
re-enables Auth, re-adds to roster, logged). TC counter atomicity confirmed still fixed
(`Sis.php:4104-4156`, atomic claim-doc CAS, aborts rather than silently falling back).

### 23. GRADUATION / ALUMNI — **MISSING**
No `Alumni`/`Graduated` status exists anywhere in code — only in an aspirational header-comment
pipeline description. No top-class detection in promotion. A student in the final class exits only
through generic TC issuance or withdrawal, with no graduation-specific record or report.

### 24. ARCHIVE / RESTORE / RE-ADMISSION — **the single largest finding of this audit**
- Soft-delete archive works correctly (`status='Deleted'`, record retained).
- **Restore is BROKEN/unreachable:** `Dual_write::recoverStudent()` is fully implemented
  (`470-492`) but has **zero callers anywhere in the codebase** — no route, no button, no admin
  view even lists `Deleted` students. The capability exists only in the library layer; from the
  controller/UI perspective it is dead code. A soft-deleted student cannot be restored through any
  reachable admin action today.
- **Re-admission destroys historical traceability, confirmed:** `enroll_student()` always mints a
  fresh `STU` id with **no lookup/dedup against prior withdrawn, TC'd, or deleted students** —
  no phone/name/old-id matching anywhere in the enrollment path. A returning student's entire prior
  history (attendance, fees, exam results, discipline log, past TCs) stays permanently attached to
  the orphaned old id, invisible from the new enrollment. This is accepted-and-known at the
  identity-architecture level (global STU counter, migration deferred to SaaS scale) but has not
  previously been traced through to this specific consequence for re-admission specifically.

---

## Part 3 — Cross-cutting classification

### 1. What ZenX already does correctly
Student creation sequencing (compensating-transaction design with real repair paths, not naive);
admission-fee ledger (idempotent, fail-loud, separately audited); marks-entry validation and audit
trail; TC counter atomicity; TC cancel/reversal; document service delegation discipline; transport
capacity reservation and boarding-roster safety gate; hostel capacity enforcement with race
rollback; RBAC graded-catalogue usage across nearly every module audited; attendance
correction/audit trail; parent-initiated entitlement requests (properly ownership-verified,
non-self-approving).

### 2. What partially works
Enquiry stage (real, but bypassed by the primary public intake channel); Application id/duplicate-
detection (two divergent schemes, both individually functional); transport/hostel fee linkage
(present but best-effort, can silently fail); TC↔hostel coordination (TC doesn't release a hostel
bed); withdrawal/freeze behavior (functionally complete, spread across three unconsolidated
methods); communication (per-student sends exist but aren't retrievable per-student anywhere).

### 3. What is broken
Mid-year admission tuition proration (bills months before join date — live, financial); report-card
roll number (prints User Id, not RollNo, on every card); certificate generation (100% RTDB storage,
CERT-001); Admission Number (no writer, printed as fabricated/blank on every TC); restore-from-
archive (implemented in the library, unreachable from any controller/UI).

### 4. What is legacy
The RTDB-backed `result_withheld` read in `Result.php` (RES-001) — three call sites, confirmed
still live, not remediated by the recent RTDB-retirement commit which didn't touch this file. Same
category as the already-known CERT-001/ATT-001 RTDB survivors.

### 5. What is missing
Standalone mid-session class/section change (only reachable via full promotion machinery, which
also silently bypasses canonical class/section normalization); dedicated detention audit trail;
graduation/alumni as a distinct status; structured sibling relationship record; re-admission
identity linkage; retention/purge policy for rejected/waitlisted application PII.

### 6. What creates financial risk
Mid-year admission tuition proration gap (HIGH — active overbilling); transport-suspension
proration failure being silently swallowed (LOW residual, was HIGH, now fixed except for this
edge); hostel fee-creation best-effort failure (room occupied, no demand raised); the four
discount/concession/entitlement mechanisms not all read by the same billing path (receipt-time vs.
generation-time can diverge); TC issued while a hostel bed stays occupied (indirect, blocks future
revenue from that bed).

### 7. What creates academic risk
Report cards printing the wrong "roll number" on an official document; marks editable post-
publication without a hard lock (mitigated by full audit, but not prevented); withheld results
having zero enforcement at promotion/TC (a student can be promoted or exited with results
deliberately withheld for a reason — e.g. unresolved academic dispute — and the system never
notices).

### 8. What creates security/privacy risk
Discipline flags readable by parents with no field-level redaction (may be intentional — flag, not
assert, as a defect); rejected/waitlisted applications retaining full PII including Aadhaar/photo
scans indefinitely with no purge path; the Attendance Strategy-2 status-filter leak, which could
surface an Inactive/TC/withdrawn student into an active roster under specific query-miss
conditions.

### 9. What destroys historical traceability
**Re-admission with no link to a prior STU id — the largest single finding of this audit.**
Everything about a returning student's past (fees, attendance, results, discipline, TCs) becomes
permanently unreachable from their new record. Secondary: restore-from-archive being unreachable
means a soft-deleted record's *intended* recovery path also doesn't function in practice, even
though the data itself is retained.

### 10. What must eventually be fixed (not authorized here)
Mid-year admission proration; report-card roll-number source; the RES-001/CERT-001 RTDB reads;
Admission Number writer (the SR number design already solves this via dual-emit); re-admission
identity linkage; restore-from-archive reachability; TC↔hostel release coordination; SMS/email
delivery-status fabrication.

### 11. What must NEVER be changed
The global STU/STA atomic counter and its Firebase-Auth-email coupling; the STU doc-id/foreign-key
scheme across every collection; `Certificates.php`'s existing read logic (route around it, don't
edit it); the two unrelated `ADM`-prefixed schemes (staff-account id, admission-fee receipt key) —
never conflate either with a future student Admission Number; the existing TC atomic-counter
mechanism; the existing marks-entry audit-trail shape.

### 12. What should become part of the future Student Trail
A single point answering "what happened to this student, ever" would need to fan in: promotion
history (currently only a generic history-log entry, no dedicated collection), detention records
(currently nonexistent), withdrawal/TC/cancel events (exist, scattered across three methods),
certificate issuance+reprint history (currently RTDB, no reprint linkage), discipline flags
(exists, but expensive to query per-student today — RF-001), document verification events (exists,
well-audited), fee-entitlement lifecycle (exists, Entitlement_service), and — critically — a
resolved link across re-admission boundaries, which today does not exist and would need to be
designed deliberately rather than assumed.

---

**Nothing in this document has been modified. Working tree HELD. No SRNO implementation, no code
changes, no data changes, no Firestore/rules/index changes, no RBAC changes, no commits.**
