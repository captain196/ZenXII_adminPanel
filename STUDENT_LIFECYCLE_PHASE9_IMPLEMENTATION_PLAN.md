# Student Lifecycle Phase 9 — Implementation Plan

**Date:** 2026-08-13 · **Type:** PLAN ONLY. No code changed. Not authorized for implementation.

---

## 0. Important correction to the roadmap this plan is built on

Before selecting a target, the existing `SRNO_STUDENT_ID_RE_ADMISSION_MULTI_TENANT_FINAL_AUDIT.md`
was re-checked against **current** source, per this program's standing rule not to trust a prior
report without re-verification. **That report is stale on one material point.** It lists as
Remaining Gap / HIGH blocker #1: *"Ordinary-admission SRNO claiming not wired into
`Sis::enroll_student()`."*

**This is no longer true.** `Sis.php:7899-7951` (inside `enroll_student()`) already reads an
optional `sr_no` POST field, calls `Sr_no_service::claim()`, and — on success — writes `srNo`,
`srNoNorm`, `srNoSource: 'admission'` onto the new student doc, best-effort (a failed claim
degrades to a warning, never blocks the admission), explicitly labeled in its own comment as
*"SRNO_STUDENT_ID_RE_ADMISSION_MULTI_TENANT_FINAL_AUDIT.md Phase 2"*. This was evidently
implemented in an uncommitted pass after that audit report was written, and the report was never
updated to reflect it. **This plan corrects the record rather than re-doing already-done work or
silently trusting the stale report.**

What genuinely remains, re-verified against current source (not carried over from memory):

- **No admission-form UI field exists** for `sr_no` anywhere — confirmed by grep, zero matches for
  `sr_no`/`srNo`/`SRNO` in any admission-related view. The backend accepts the POST field; nothing
  in the Admin Panel ever sends it. The claim code path is currently **dead from the UI's
  perspective** — it can only be exercised today via a raw API call, not through any screen.
- **Correction to this plan's own earlier §4 (superseded below):** the first version of this plan
  named `application/views/studentAdmission.php` as the file to add the SR Number field to. That was
  **wrong**, caught only during the APAAR investigation pass below and corrected here rather than
  left standing. `studentAdmission.php` posts to `Sis::save_admission()` (`Sis.php:607`, routed via
  `sis/save_admission`) — the **direct walk-in admission** path — which has **zero** `sr_no`
  handling anywhere in its body (confirmed by grep across the full method). The `sr_no` claim code
  found at `Sis.php:7899-7951` lives inside a **different** method, `Sis::enroll_student()`
  (`Sis.php:7515`), reached via `sis/enroll_student` or `admission_crm/enroll_student` — the
  **Admission CRM → enrol-from-application** path, invoked from
  `application/views/admission_crm/applications.php` (a one-click "confirm section, enroll" action
  that today posts only `id` + `section`, with no other operator-entered fields at all). **The two
  admission entry points in this codebase are genuinely different code paths, and only one of them
  has any SRNO wiring.** §4/§22 below are corrected to target the right one. This is exactly the
  class of mistake this program's standing rule ("study the whole feature before advising") exists
  to catch — flagged and fixed here rather than carried forward silently.
- **No Readmission UI exists** — confirmed, zero matches in `application/views/` for "readmission";
  `readmission/confirm` and `readmission/resolve` are registered routes (`routes.php:510-511`) with
  no menu entry and no calling page anywhere in the codebase (grep across `application/views/`).
- **No display of `srNo`/`previousStudentId` on the student profile page** — confirmed, zero
  matches in `application/views/student_profile.php`. The controller (`Sis::student_profile()`,
  `Sis.php:5909-5967`) already passes the **entire raw student document** — including `srNo`,
  `srNoNorm`, `srNoSource`, and `previousStudentId` whenever those fields exist — to the view as
  `$data['student']`; the view template simply never reads them. **No controller change is needed
  to make this data available; only the view needs to render it.**
- **Parent App / Teacher App:** confirmed by grep, zero references to `srNo` anywhere in either
  repo's Kotlin source. SRNO is currently an Admin-Panel-only concept, not synced to or displayed
  in either mobile app.

**Conclusion:** the SRNO/re-admission backend (`Sr_no_service.php`, `Readmission.php`, the
`enroll_student()` claim wiring) is complete, tested, and RBAC-gated, per the Multi-Tenant Final
Audit's own findings (re-confirmed unaffected by this plan). What is missing is **exclusively UI** —
this matches Phase 8.5's original recommendation ("2. SRNO operator UI," the item immediately after
SL-12) and the Final Audit's own two named HIGH blockers, one of which (enroll wiring) is now done,
leaving UI as the one that remains.

---

## 1. Current State

- Backend: `Sr_no_service.php` (claim/resolve/relink, atomic, CAS-guarded, cycle-safe,
  tenant-isolated) — implemented, unit-tested (`SrNoServiceTest.php`, `SrNoServiceMultiTenantTest.php`),
  audited three times across this program.
- `Readmission.php`: `confirm()` (Principal/SA/SSA-gated, atomic relink) and `resolve()`
  (SRNO → currentStudentId lookup, `SIS:view`-gated) — implemented, routed, RBAC-gated.
- `Sis::enroll_student()`: optionally claims an SRNO if `sr_no` is present in the POST body —
  implemented, best-effort, non-blocking.
- **UI: none.** No admission-form field, no re-admission screen, no profile display, no menu entry.
- Firestore: `srNoIndex` collection exists, rules deny all direct client access
  (`firestore.rules:1062-1069` per the Final Audit — will be re-read fresh, not assumed, before any
  implementation touches this area — see §15).

## 2. Target State

An operator can, entirely through the Admin Panel UI, without touching the API directly:

1. **Enter an SRNO at admission time** (optional field on the admission form) — exercises the
   already-implemented `enroll_student()` claim path for the first time from a real screen.
2. **See a student's current SRNO** (and, if present, their `previousStudentId` link) on the
   student profile page.
3. **Confirm a re-admission** — a Principal/SA/SSA-only screen (or modal reachable only from a
   context those roles see) that collects `previous_student_id` for an already-created new
   admission record and calls the existing `Readmission::confirm()` endpoint, surfacing its
   existing success/error messages verbatim (the endpoint already returns operator-legible error
   strings — no new error-message design needed).

No change to `Sr_no_service.php`, `Readmission.php`, or the `enroll_student()` claim block's own
logic is in scope — they are consumed as-is.

## 3. Exact Feature

**"SRNO Operator UI"** — three additive UI surfaces described in §2, wired to existing, unmodified
backend endpoints. No new backend endpoint is required for items 1-2 (both are existing writes/reads
against the `students` doc, already returned/accepted by existing controller actions). Item 3 calls
the existing `readmission/confirm` route as-is.

## 4. Files Involved (planned, not yet touched) — corrected per §0

| File | Change |
|---|---|
| `application/views/admission_crm/applications.php` | Add an optional "SR Number" input (and, if authorized per §23 below, "APAAR ID") to the enrol-from-application flow, posted alongside the existing `id`/`section` fields to `admission_crm/enroll_student` as `sr_no` (`apaar_id`) — the exact field names `Sis::enroll_student()` already reads/would read |
| `application/views/student_profile.php` | Display `$student['srNo']` (if present) and, if `$student['previousStudentId']` is present, a small "Re-admitted — previously {id}" indicator |
| A new, small view (name TBD at implementation time, e.g. `application/views/sis/readmission_confirm.php` or a modal partial included from the student profile page) | Principal-only re-admission confirm form: new Student ID (pre-filled from context), previous Student ID (operator-entered), reason (optional), submits to `readmission/confirm` |
| `application/controllers/Sis.php` | **Possibly** a thin new action to *render* the re-admission screen/modal (routing + RBAC gate only — no new business logic; `confirm()`'s logic stays in `Readmission.php` untouched) — exact necessity to be confirmed at implementation time depending on whether a modal-on-existing-page or a dedicated route is chosen |
| Menu/nav config (wherever the SIS section's sidebar links are defined) | One new Principal-visible entry point to reach the re-admission screen — exact file to be located at implementation time (not yet inspected this pass, since it's a small, low-risk lookup deferred to implementation to avoid scope-creeping this planning pass) |

**Explicitly NOT touched:** `Sr_no_service.php`, `Readmission.php`, `Sis::enroll_student()`'s claim
block, `firestore.rules`, `firestore.indexes.json`, any Parent/Teacher App file.

## 5. Cross-Module Dependencies

| Module | Status |
|---|---|
| SIS (admission form, student profile) | **CONNECTED** — the two UI surfaces this phase adds |
| Readmission (backend) | **CONNECTED** — consumed as-is, unmodified |
| Student 360 | **NOT CONNECTED** — confirmed unintegrated by the Final Audit (§O) and unchanged by this plan; NOT a goal of this phase (see §21) |
| Student Trail | **NOT CONNECTED** — does not exist as a feature anywhere in this codebase (Final Audit §P, re-confirmed not built); NOT a goal of this phase |
| Fees / Attendance / Examination / Report Cards / Certificates-TC / Documents / Communication / Transport / Hostel / Discipline | **NOT CONNECTED** — `Sr_no_service.php`/`Readmission.php` reference none of these collections (re-confirmed, Final Audit §J); this UI phase adds no new writes to any of them either |
| Promotion (`Sis::execute_promotion`) | **NOT CONNECTED** — SRNO is not referenced anywhere in the promotion path (confirmed in the SL-12 report, §9.5) |
| Accounting | **NOT CONNECTED** — no student-linkage code path touches SRNO |
| Firebase Auth | **NOT CONNECTED** — SRNO is a Firestore-only display/lookup attribute, never an Auth claim or UID |
| APIs | **CONNECTED, unchanged** — `readmission/confirm`/`resolve` are consumed as-is; no new API surface for items 1-2 (they ride the existing `enroll_student`/`student_profile` actions) |

## 6. Admin Panel Impact

New: one form field (admission), one display block (profile), one new screen/modal (re-admission
confirm), one menu entry. RBAC: the new screen must enforce the SAME Principal/SA/SSA gate
client-side as `Readmission::confirm()` already enforces server-side (defense-in-depth, not a
substitute — the server-side gate is authoritative and already correct; a client-side gate here is
purely so an unauthorized user doesn't even see a form that will 403 anyway).

## 7. Parent App Impact

**NOT APPLICABLE** — evidence: zero references to `srNo` anywhere in the Parent App's Kotlin
source (confirmed by grep across the full source tree). This phase is Admin-Panel-UI-only and adds
no new Firestore field, no new write shape, and no new read the Parent App would need to consume.
No Parent App file is touched.

## 8. Teacher App Impact

**NOT APPLICABLE** — same evidence and reasoning as §7; zero `srNo` references in the Teacher App.

## 9. Student ID Impact

**NOT APPLICABLE — none.** This phase adds no code that creates, modifies, or reads Student ID
generation. The admission form change adds a field posted alongside the existing form; it does not
touch `Id_generator.php` or `enroll_student()`'s Student ID creation logic. The re-admission screen
collects a `previous_student_id` value as a **string the operator types**, exactly as
`Readmission::confirm()` already expects — it does not generate or alter any Student ID.

## 10. SRNO Impact

**This is the feature.** No change to how SRNO is claimed, resolved, or relinked — this phase only
adds UI to invoke those three already-implemented, already-tested operations. SRNO remains
school-scoped by construction (§D of the Final Audit, unaffected).

## 11. Roll Number Impact

**NOT APPLICABLE — none.** Roll Number is not referenced anywhere in `Sr_no_service.php` or
`Readmission.php` (Final Audit §T, re-confirmed), and this UI phase adds no Roll Number field,
display, or logic. The admission form's existing Roll Number field (if any) and this phase's new
SRNO field are entirely independent inputs.

## 12. Re-admission Impact

**This is the third UI surface being added.** The re-admission confirm screen makes
`Readmission::confirm()` reachable for the first time through the UI. No change to `confirm()`'s
own logic, validation, or error messages — the screen's job is purely to collect the two required
inputs (`student_id`, `previous_student_id`) and an optional `reason`, submit them, and render
whatever success/error response the endpoint already returns.

## 13. Student Trail Impact

**NOT APPLICABLE** — Student Trail does not exist as a feature in this codebase (confirmed, Final
Audit §P). This phase does not build it, does not integrate with it, and does not create any
groundwork specifically for it beyond what already exists (`Sr_no_service::getFullRecord()`, unused,
untouched).

## 14. Firestore Impact

- New reads: none beyond what `student_profile()` already fetches (the whole student doc is already
  read; the view will simply render fields already present in that payload).
- New writes: none beyond what `enroll_student()`'s existing claim block and `Readmission::confirm()`
  already perform — this phase adds no new write path, only a UI trigger for existing ones.
- No new collection, no new document shape.

## 15. Rules/Index Impact

**Expected: NONE**, but per the explicit instruction to check before assuming, this will be
re-verified at implementation time by:
1. Pulling the latest `integration/phase2` remote state of `firebase-rules/firestore.rules` first
   (per this program's standing pull-first rule for this file) and reconciling before any read.
2. Confirming the `srNoIndex` deny-all rule (`firestore.rules:1062-1069` per the Final Audit) is
   still exactly deny-all and unrelated to this phase — this phase never reads/writes `srNoIndex`
   directly from a client; every SRNO operation still goes through the existing PHP-side
   `Sr_no_service`/`Readmission` server endpoints, which already have their own service-account-level
   Firestore access, not subject to client rules.
3. Confirming `students` collection's existing rules already permit the fields this phase's UI
   causes to be written (`srNo`, `srNoNorm`, `srNoSource`, `previousStudentId`) — these are written
   **server-side by PHP** (via the Firebase Admin SDK / REST client, not the client Firestore SDK),
   so client security rules do not gate them at all; this is expected to require **zero rule
   changes**, to be confirmed, not assumed, before implementation is called complete.

**No rule or index file will be modified as part of this phase unless step 1-3 above finds concrete
evidence they are insufficient** — none is expected.

## 16. RBAC Impact

No new RBAC capability is introduced. The re-admission screen enforces the same
Principal/SA/SSA hard-role check the backend already enforces (§6). The admission-form SRNO field
and profile-page SRNO display use the same existing `SIS:edit`/`SIS:view` gates that already guard
those pages — no new permission is needed because these are just new fields on existing,
already-gated pages.

## 17. Test Plan

- Manual UI walkthrough on `localhost:8080` (per this repo's standing rule that UI changes must be
  exercised in a browser, not just type-checked) for all three surfaces:
  1. Admit a student with an SR Number → confirm it lands on the student's Firestore doc with the
     same field shape `enroll_student()`'s existing code already produces.
  2. Admit a student leaving SR Number blank → confirm no `srNo*` fields are written (the existing
     `if ($srNoInput !== '')` guard) and no warning is shown for what is legitimately optional input.
  3. Enter a duplicate SR Number within the same school → confirm the existing "already assigned to
     {other student}" warning surfaces in the UI exactly as `enroll_student()` already returns it.
  4. View a student profile with an SRNO set → confirm it displays. View one without → confirm no
     broken/empty UI element appears.
  5. As Principal: confirm a re-admission with a valid, eligible prior Student ID → confirm success
     message, then verify (read-only) the new student's doc now carries `previousStudentId`/`srNo`.
  6. As Principal: attempt with an ineligible prior record (still Active, no SRNO, already-linked,
     self-reference, cross-school) → confirm each of `Readmission::confirm()`'s existing error
     messages surfaces legibly in the new UI, not as a raw JSON blob.
  7. As a non-Principal role: confirm the re-admission screen is not reachable (menu entry hidden)
     and, if the route is hit directly, the existing 403 is shown reasonably (not a blank page —
     per this repo's "phantom success"/fail-closed UI trap).
- No new PHPUnit tests are anticipated for pure view/template changes (no new PHP logic is being
  added — existing controller actions are reused as-is). If a new thin controller action is needed
  for the re-admission screen's render step (§4), a minimal test may be added at implementation time
  to assert its RBAC gate — to be decided once that file is written, not pre-committed here.

## 18. Regression Plan

- `vendor/bin/phpunit --testsuite Unit` — full run, compared against the current confirmed baseline
  (643 tests, the same 10 pre-existing failures documented in the SL-12 report) before and after.
- `php -l` on every touched PHP file.
- Manual smoke test of the existing admission flow WITHOUT using the new SRNO field, to confirm
  zero behavior change for schools that don't adopt it (the existing code already guards this via
  `if ($srNoInput !== '')`, but the UI-level walkthrough re-confirms nothing regresses for the
  common case).
- Manual smoke test of the existing student profile page for a student with no `srNo`/
  `previousStudentId` fields, to confirm the new display block degrades gracefully (no PHP notice
  on an undefined array key, no broken layout).

## 19. Tenant-Isolation Plan

No new tenant-isolation surface is introduced — every write/read this phase's UI triggers already
goes through `Readmission::_student()` (school-scoped by construction, per the Final Audit §F) or
`enroll_student()`'s existing school-bound `Sr_no_service` instance. The plan's only obligation is
to **not** introduce a new, UI-level way to bypass this — e.g., the re-admission screen must never
accept or display a cross-school `previous_student_id` as a valid option; since `_student()` already
structurally cannot resolve a cross-school id (§F.6, re-confirmed in the Final Audit), the UI simply
needs to render whatever "not found" response that produces, not attempt its own tenant check.

## 20. Risks

- **Low:** a new admission-form field is misnamed and doesn't match the `sr_no` POST key the backend
  expects — mitigated by using the documented field name verbatim and testing end-to-end (§17.1).
- **Low:** the re-admission screen could be built in a way that makes previously-manual-only
  API misuse easier for an unauthorized user if the client-side RBAC gate is relied upon instead of
  trusting the server gate — mitigated by treating the client-side gate as UX-only, never as the
  authorization boundary (the server-side hard-role check remains authoritative, unchanged).
- **Low:** displaying `previousStudentId` on the profile page without a link/lookup to the actual
  prior record could read as a dead-end to the operator — acceptable for this phase (Student 360/
  Trail integration is explicitly out of scope, §21) but worth a one-line UX note ("historical
  record — search by Student ID in SIS to view") rather than a broken/fake link.
- **Medium:** none identified — this phase's backend surface is entirely pre-existing and
  independently audited three times; the risk profile is dominated by ordinary UI-implementation
  risk (form wiring, RBAC-gate placement), not by any new data-model or concurrency risk.

## 21. Explicit Non-Goals

- Student 360 / Student Trail integration — not built, not started, remains future work per the
  Final Audit's own §O/§P findings.
- `correct_sr_number` (typo-correction, distinct from relink) — not implemented; Final Audit §X
  item 3, still open, not this phase's scope.
- A dedicated graded RBAC capability replacing the hard-role check — not implemented; Final Audit
  §M/§X item 2, still open, not this phase's scope.
- Closing §H.4 (double-spent predecessor) or §E (orphan-index on hard-delete) from the Final Audit —
  both are backend-logic gaps, not UI gaps; explicitly out of scope for a UI-only phase.
- Auto-suggestion of the next-in-series SRNO (mentioned as a future follow-up in the
  `enroll_student()` comment itself) — not built; this phase's admission-form field is manual-entry
  only, matching what the backend already supports.
- Any Parent App or Teacher App change — confirmed not needed, §7/§8.
- Any Firestore rules/indexes change — expected not needed, to be confirmed (not assumed) per §15
  before this phase is called complete.
- Wiring SRNO into any other module (Fees/Attendance/Certificates/Transport/etc.) — none of those
  are connected today (§5) and this phase does not connect them.

## 22. Implementation Sequence (proposed, for when/if authorized)

1. Re-pull/re-read `firestore.rules` fresh (per §15) — confirm no rule change is actually needed
   before writing any UI code, so the plan isn't invalidated mid-implementation.
2. Locate the exact menu/nav config file (deferred from §4) and confirm the smallest safe way to add
   a Principal-visible entry point.
3. Add the SR Number field (and APAAR ID field, if §23 is authorized) to the Admission CRM
   enrol-from-application flow (`admission_crm/applications.php`, corrected per §0/§4) — smallest,
   most isolated change; test end-to-end per §17.1-17.3 before proceeding.
4. Add the student-profile SRNO/previousStudentId display block (`student_profile.php`) — test per
   §17.4.
5. Build the re-admission confirm screen/modal and its thin render action (if needed) — test per
   §17.5-17.7.
6. Full regression pass (§18).
7. Produce a closing implementation report (mirroring the SL-12 report's structure) before any
   commit is considered.

---

## 23. APAAR ID — Investigation

### 23.1 Existing APAAR implementation

**Effectively none, live.** A repo-wide, case-insensitive search for `apaar` across the Admin
Panel, both mobile apps, `firebase-rules/`, and `functions/` returns matches in exactly **4 Admin
Panel files**, zero in either mobile app, zero in `firebase-rules/`, zero in `functions/`:

| File | What it actually contains |
|---|---|
| `application/controllers/Sis.php` | One comment referencing a design-doc filename — not a field, not logic |
| `application/libraries/Sr_no_service.php` | One comment explicitly **disclaiming** APAAR matching: *"It does not implement any name/phone/DOB/APAAR matching. Candidate search is a human process outside this class entirely."* |
| `application/libraries/Student_context.php` | **The only real code.** `'apaarId' => $pick($stu, ['apaarId', 'apaar_id', 'APAAR ID'])` (line 463) — a defensive multi-alias read, feeding Student 360, with its own comment: *"Present only where the tenant has adopted them; blank is a legitimate answer, not a bug."* |
| `application/views/student360/workspace.php` | Renders `'APAAR ID' => $acad['apaarId'] ?? ''` (line 877) as one row in an academic-detail table, only shown if non-empty |

**There is no writer anywhere.** No admission form, no student-edit form, no controller action, no
import/migration script writes `apaarId`/`apaar_id`/`APAAR ID` to any Firestore document, anywhere
in this codebase. Student 360's display will show this row for every student, forever, because the
field can never be populated by any currently-reachable code path. This is a read path with no
matching write path — a genuinely inert, dormant piece of defensive coding, not a partially-built
feature.

### 23.2 Canonical field

**`apaarId`** (camelCase, on the `students` Firestore document) — confirmed by two independent
sources agreeing: (a) `Student_context.php`'s own alias-preference list puts `apaarId` first, and
(b) `SR_NUMBER_AND_APAAR_ID_DESIGN.md` §6.2 explicitly specifies `apaarId` as the `students`-doc
field (type: string, "12 digits, optional, mutable, masked in UI"). The POST/form field name per
that same design (§5.1-5.3) is `apaar_id` (snake_case), matching this codebase's established
convention (e.g. `sr_no` POST → `srNo` doc field). **No competing field name was found — nothing to
disambiguate.**

### 23.3 Current storage

Nowhere. Zero documents in the live `students` collection carry a non-empty `apaarId` (this follows
directly from §23.1's finding that no writer exists anywhere — there is no code path in the
codebase's history capable of having ever written it). Not independently re-scanned against live
Firestore this pass, since the "zero possible writer" finding from source alone is conclusive
without needing a live read to confirm an absence that has no mechanism to be anything else.

### 23.4 Current writer

**None exists.** See §23.1.

### 23.5 Current readers

**One:** `Student_context.php:463` → `student360/workspace.php:877`. Nothing else in the Admin
Panel, and nothing in either mobile app, reads this field.

### 23.6 Current validation

**None exists** — there is no writer to validate anything at. A **ratified design** exists
(`SR_NUMBER_AND_APAAR_ID_DESIGN.md` §4, dated as part of this program's own admission-identity work)
specifying: exactly 12 digits after stripping spaces/hyphens, no checksum ("do not invent a
check-digit rule" — the design's own words), optional at every stage, never blocking submission.
This is a **designed-but-never-implemented** format rule, not an invented one — if APAAR format
validation is added, this is the rule to use, sourced from the design doc rather than guessed.

### 23.7 Current uniqueness behavior

**None exists, and none is enforced by any code today.** The design specifies **school-scoped**
uniqueness (explicitly *not* global — "National uniqueness is not ours to check and must not be
claimed"), to be enforced via a claim-doc pattern mirroring SRNO's: `apaarIndex/{schoolId}_{apaarId}`.
**This claim service does not exist.** There is no `Apaar_service`, no `apaarIndex` collection
reference anywhere in the codebase (confirmed by grep — zero matches), and no code capable of
detecting or blocking a duplicate APAAR within a school today. Building this would be genuine new
backend logic — a service class analogous to (but separate from, per the design) `Sr_no_service`.

### 23.8 Re-admission behavior — genuinely undetermined, reported as ambiguous per instruction

**The existing architecture provides no answer, and this plan does not invent one.**
`SR_NUMBER_AND_APAAR_ID_DESIGN.md` contains zero mentions of re-admission anywhere in its full text
(confirmed by grep for "re-admission"/"readmission"/"previousStudentId" — no matches) — it was
written before, and never reconciled with, the separate re-admission architecture
(`SRNO_RE_ADMISSION_ARCHITECTURE_AUDIT.md` and its implementation). `Sr_no_service::relink()` (the
only code that runs during a re-admission) touches exactly two documents — the `srNoIndex` entry
and the new student's own record — and never references `apaarId` in any form (confirmed by
re-reading the full file). Two materially different real-world policies are both plausible and
**neither is established by current source**:

- **(a) APAAR follows the person, not the Student ID** — a re-admitted child's APAAR (a
  government-issued lifetime identifier, per the design's own description) logically belongs to
  the human being, so `STU0047` (the new admission) should end up with the *same* `apaarId` that
  `STU0001` (the old record) had — analogous to how SRNO is preserved.
- **(b) APAAR is captured independently per admission event** — since APAAR is "collectible later,"
  optional, and operator-entered (never auto-populated), a re-admission could simply be treated as
  a fresh capture opportunity, with no automatic carry-forward, leaving the old record's `apaarId`
  where it is and letting the operator re-enter it (or look it up via `previousStudentId` →
  `STU0001`) on the new record if/when the parent provides it again.

**This plan does not choose between (a) and (b).** Per the explicit instruction, this is reported
as an open ambiguity requiring an explicit decision before any re-admission-time APAAR carry-forward
logic is built — not something to silently implement either way. **Note that this ambiguity does
not block Phase 9 as currently scoped**, because Phase 9's re-admission UI (§2 item 3) only concerns
the SRNO relink workflow, which has no APAAR dependency at all — this open question only matters if
and when APAAR carry-forward-on-readmission is separately proposed as a feature.

### 23.9 Parent App status

**NOT APPLICABLE today; a specific future intent exists but was never built.** Confirmed by grep:
zero `apaar` references anywhere in the Parent App's Kotlin source. The design doc (§6.2/§4.1) does
describe an intended future integration — `apaarId` on `StudentDoc.kt` and the profile screen,
masked with an audited reveal, shown only to the owning parent — but this was **never implemented**;
it remains a documented future intent, not a current gap this phase creates or must close.

### 23.10 Teacher App status

**NOT APPLICABLE, by design, permanently.** Confirmed by grep: zero `apaar` references. The design
doc is explicit and unambiguous that this is intentional, not an oversight: *"Never sent. Not in any
payload, not in any listing. A teacher has no reason to hold a child's government ID."* This phase
must not add APAAR to any Teacher App payload, model, or screen under any circumstance — the
strongest possible instruction in the source material available.

### 23.11 Cross-module status

| Module | Status | Evidence |
|---|---|---|
| SIS / Admission | **CONNECTED** (if authorized) — the one place a field would be added | this investigation |
| Student Profile | **CONNECTED** (if authorized) — display only, masked per PII rules | design §4.1 |
| Student 360 | **CONNECTED, already partially built** — `Student_context.php`/`workspace.php` already defensively read/display it; currently always blank | §23.1, §23.5 |
| Student Trail | **NOT CONNECTED** — Student Trail does not exist as a feature anywhere in this codebase (re-confirmed, same finding as the SRNO investigation) | prior Phase 9 findings, unchanged |
| Fees / Attendance / Examination / Results / Report Cards | **NOT CONNECTED** — zero references in any of these modules' controllers/views (confirmed by the repo-wide grep in §23.1 returning only 4 files, none in these modules) | grep |
| Certificates / TC | **NOT CONNECTED** — zero references; the design doc explicitly separates APAAR from `Admission Number`/`srNo` (the field that actually feeds `Certificates.php`'s existing print logic per the design's dual-emit note, §6.2) | grep, design §6.2 |
| Documents | **NOT CONNECTED** | grep |
| Communication | **NOT CONNECTED** | grep |
| Transport / Hostel | **NOT CONNECTED** | grep |
| Promotion | **NOT CONNECTED** — `Sis::execute_promotion()` never references APAAR (consistent with the SL-12 finding that promotion touches no identity field beyond className/section) | grep, prior SL-12 report |
| Firebase Auth | **NOT CONNECTED** — the design is explicit APAAR is "not an auth key" | design §4 |
| Firestore | **CONNECTED (design only)** — one additive field (`students.apaarId`) and one not-yet-built claim collection (`apaarIndex`) are specified but do not exist in the live database or the deployed rules | §23.3, §23.7, §23.12 |
| Accounting | **NOT CONNECTED** | grep |
| APIs | **NOT CONNECTED** beyond the one read path in §23.5; no dedicated APAAR endpoint exists (unlike SRNO's `readmission/resolve`) | grep |

### 23.12 Firestore rules/index status

**Design specifies rules that are not deployed.** `SR_NUMBER_AND_APAAR_ID_DESIGN.md` §6.4 specifies
`match /apaarIndex/{docId} { allow read, write: if false; }` as a deny-all rule alongside the
already-deployed `srNoIndex` equivalent. **Confirmed by grep of the actual live
`firebase-rules/firestore.rules`: zero matches for "apaar."** The rule was designed but never
written into the real rules file — consistent with the `apaarIndex` collection itself not existing.
§6.3 explicitly states no index is needed on `apaarId` itself ("never queried, only claimed by
deterministic doc id") — so even a full implementation of §23.7's uniqueness enforcement would need
a **new rules-file addition** (the deny-all block), but **no `firestore.indexes.json` change** —
consistent with this phase's general finding pattern (rules sometimes needed, indexes essentially
never for this class of feature).

### 23.13 Does the admission UI need APAAR?

**Only if the operator/school wants to start collecting it — nothing in the current architecture
requires it.** It is legitimately optional at every stage per the ratified design, and per this
program's standing "do not implement backend logic during a UI/investigation pass" discipline, a
**full**, design-compliant APAAR feature (format validation + school-scoped uniqueness enforcement +
PII masking/reveal-audit) is **not** achievable as a pure UI addition — §23.7 established that
uniqueness enforcement requires a new backend claim service that does not exist today. Two honest
options exist for the eventual decision (not made here):

- **Option A — full design compliance, larger scope:** build the `apaarIndex` claim service first
  (a real, separately-scoped backend change, structurally analogous to but not overlapping with
  `Sr_no_service.php`), *then* add the admission-form/profile field on top of it. This is the only
  way to ship APAAR exactly as the ratified design specifies, including duplicate-typo protection.
- **Option B — format-only field now, uniqueness deferred:** add the `apaar_id` field to the
  admission UI and profile display with **format validation only** (12 digits, optional — no
  backend logic beyond what a form already does), explicitly **without** duplicate-detection, and
  disclose that limitation to whoever uses the field (e.g., a UI note or an internal note in the
  handoff) until the claim service is separately built. This keeps Phase 9 UI-only as originally
  scoped, at the cost of temporarily shipping a feature narrower than its own ratified design.

**This plan does not choose between A and B — that choice belongs to whoever authorizes
implementation**, consistent with the instruction not to expand scope on assumption.

### 23.14 Does the profile UI need APAAR?

If APAAR is added at all (either option in §23.13), yes — the design is specific that it must be
**masked by default** (`XXXX XXXX 9012`) with an **audited reveal**, full value restricted to
Admin/Principal/School Super Admin (Front Office sees masked only). This is a real, non-trivial
UI/RBAC requirement beyond "print the field" — implementing the masking is straightforward
(display-time string transform), but the **audited reveal** (logging who revealed a specific
student's APAAR and when) is itself a small piece of new logic, not pure template work. This must
be scoped explicitly if Option A or B in §23.13 is chosen, not silently done as "just a display."

### 23.15 Exact files that would change (either option, if authorized — not touched this pass)

| File | Change | Applies to |
|---|---|---|
| `application/views/admission_crm/applications.php` | Add optional `apaar_id` input (12-digit, `inputmode="numeric"`) alongside the SRNO field from §4 | A, B |
| `application/controllers/Sis.php` (`enroll_student()`) | Read `apaar_id`, format-validate (strip spaces/hyphens, 12-digit check), write `apaarId` to the student doc — mirroring the existing `sr_no` block's best-effort/warning pattern | A, B |
| `application/views/student_profile.php` | Masked APAAR display + role-gated reveal control | A, B |
| A new audit-log call at the reveal action (exact mechanism TBD — this codebase already has `log_audit()`, used elsewhere including `Readmission.php`) | Record who revealed which student's APAAR, when | A, B |
| **New:** an `Apaar_service.php`-equivalent (or an addition to an existing library) implementing atomic claim-if-not-exists against a new `apaarIndex/{schoolId}_{apaarId}` collection | Uniqueness enforcement | **A only** |
| `firebase-rules/firestore.rules` | Add the deny-all `apaarIndex` rule (§23.12), following this program's standing pull-first-then-edit-one-block discipline for this file | **A only** |
| `SR_NUMBER_AND_APAAR_ID_DESIGN.md`'s consent-copy note (§4.1) | **NOT triggered by this phase** — that requirement applies specifically to the *public* admission form (`Admission_public.php`), which neither this plan nor its APAAR extension touches; the Admission CRM flow is staff-operated, not the public-facing form the consent gate concerns | N/A — explicitly out of scope either way |

### 23.16 Test cases (if authorized)

Mapped to the user's required regression list (A-J):

- **A/B/C** — admission with every SRNO/APAAR blank/populated combination: straightforward form
  tests once built.
- **D (duplicate SRNO)** — already covered by the existing Phase 9 plan (§17.3); unaffected by
  APAAR.
- **E (duplicate APAAR)** — **only applicable under Option A** (§23.13); under Option B there is no
  uniqueness behavior to test, and the plan must not claim to test something that isn't built.
- **F (invalid APAAR)** — format validation test (not-12-digits, letters, too short/long) —
  applicable under either option once the field exists.
- **G (re-admission + APAAR)** — **cannot be meaningfully tested until §23.8's open policy question
  is resolved**; testing "correct APAAR behavior" on re-admission is undefined until a policy is
  chosen. This test case is **BLOCKED, not skippable, not assumable** — named explicitly rather than
  silently omitted.
- **H (profile: SRNO, APAAR, previousStudentId)** — covered once the display work (§23.14/§23.15) is
  built, including the masked/reveal behavior specifically (not just "the field appears").
- **I (cross-school isolation)** — under Option A, mirrors the existing SRNO cross-tenant test
  pattern (`SrNoServiceMultiTenantTest.php`) — a new `apaarIndex` claim service would need its own
  equivalent test, not an assumption that it inherits SRNO's. Under Option B there is no claim
  service to isolate.
- **J (admission without SRNO or APAAR continues working)** — already covered by the existing Phase
  9 plan for SRNO; extends trivially to APAAR under either option (both are `if ($input !== '')`-
  guarded, optional, non-blocking, matching the existing `sr_no` block's pattern).

### 23.17 Risks (APAAR-specific, additive to §20)

- **Compliance/PII risk if masking or the audited reveal is skipped** — APAAR is explicitly
  documented as sensitive, Aadhaar-derived PII (DPDP-relevant) in the ratified design. Shipping it
  as a plain, unmasked field would be a real regression against an explicit, already-written
  privacy requirement, not a neutral simplification — this must not be treated as an optional nicety
  if APAAR ships at all.
- **Scope-creep risk if Option A is chosen without separate authorization** — building a new claim
  service is backend logic, explicitly out of bounds for this investigation-and-planning pass per
  the user's own instruction ("Do not modify backend logic"); it would need its own plan/report
  cycle, not be folded silently into "the UI phase."
- **False-completeness risk if Option B ships silently** — a field that looks complete (accepts
  input, saves it) but has no duplicate protection could be mistaken for the fully-designed feature
  by an operator unless the limitation is disclosed at the point of use.
- **Re-admission ambiguity risk (§23.8)** — if a re-admission workflow is later extended to "carry
  forward" APAAR without first resolving the (a)/(b) policy question, it risks silently establishing
  a policy nobody explicitly chose.

### 23.18 Explicit non-goals (APAAR-specific, additive to §21)

- Building the `apaarIndex` claim service (Option A) — not started, not authorized here; a future,
  separately-scoped decision.
- Resolving the re-admission APAAR carry-forward policy (§23.8) — explicitly left open, not decided
  here.
- Any Public Admission Form (`Admission_public.php`) change, or its required consent-copy amendment
  — out of scope; that form is not touched by this phase under either option.
- Any Parent App change to surface APAAR — the design describes a future intent (§23.9) but building
  it is not part of this phase.
- Any Teacher App change involving APAAR — never in scope, by design, permanently (§23.10).
- CSV export / audited-export tooling for APAAR (mentioned in the design's §4.1 "Export" row) — not
  built, not part of this phase.

---

## Final Status

# READY WITH CONDITIONS

**SRNO (original Phase 9 scope):** unchanged from before this addendum — READY WITH CONDITIONS on
its own merits (§ the two deferred lookups below), now with one additional correction folded in:
the admission-form file target was wrong in the first draft of this plan and has been corrected
(§0/§4) before any code was written, not after.

**APAAR (this addendum):** **READY WITH CONDITIONS, narrower than SRNO** — implementable this phase
**only** as Option B (§23.13: optional, format-validated-only field, PII masking + audited reveal on
display, explicitly no uniqueness enforcement, explicitly disclosed as such), because Option A
requires new backend logic (a claim service) that is out of bounds for this pass. Test case G
(re-admission + APAAR) is **BLOCKED** on an explicit, undecided policy question (§23.8) that this
plan deliberately does not resolve. If the eventual decision is Option A, or if re-admission
carry-forward is wanted, this plan does not cover that work and a follow-up plan would be needed.

**Consolidated conditions before implementation of either SRNO or APAAR:**
1. Confirm the exact menu/nav config file for the SRNO re-admission entry point (§22.2) — deferred
   lookup, not a design question.
2. Re-pull and re-read `firestore.rules` fresh before touching it, per this program's standing rule
   — expected to require zero changes for SRNO, and (only if Option A is separately authorized) one
   small deny-all addition for `apaarIndex` (§23.12/§23.15).
3. An explicit choice between Option A and Option B for APAAR (§23.13) — or a decision to defer
   APAAR out of this phase entirely and ship SRNO alone — must be made before implementation starts;
   this plan presents the choice rather than making it.
4. If APAAR ships under Option B, the masking + audited-reveal requirement (§23.14) must be treated
   as in-scope, not optional — shipping an unmasked APAAR field would violate the ratified design's
   explicit PII handling rules.

**Why not BLOCKED overall:** SRNO's backend remains fully built, tested, and independently audited;
APAAR's Option B is a bounded, well-evidenced, UI-plus-thin-validation addition with no open
technical question standing in its way — only an open **choice** (A vs. B), and one explicitly
disclosed, not-required-to-resolve-today ambiguity (§23.8) that is scoped out of this phase's actual
deliverables.

**No code has been changed. No file has been modified in this pass beyond this plan document and
the earlier SL-12/condition-closure reports. Nothing staged, committed, or pushed. No production
data, Firestore rules, Firestore indexes, Parent App file, or Teacher App file was touched.**
