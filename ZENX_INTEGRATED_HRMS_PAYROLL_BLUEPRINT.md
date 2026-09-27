# ZENX INTEGRATED HRMS + PAYROLL — PRODUCTION ARCHITECTURE BLUEPRINT

**Status: BLUEPRINT ONLY — Implementation Gate per §40 is in force.** No code, Firestore rule, index, dependency, collection, commit, or push has been made as part of producing this document. This is research and design synthesis, nothing else.

## How to read this document — evidence typing

Per the brief's own §3 requirement, every substantive claim below is tagged with where it came from:

- **[REPO]** — directly observed in this codebase, with file:line citations available (drawn from this session's Phase-0 audit plus five new research passes run for this blueprint).
- **[ASANIFY — USER-DESCRIBED]** — no video file or "video observation report" was accessible in this session; nothing was uploaded to this conversation. The Asanify behavioral benchmark below is transcribed from the workflow description the user has now provided in writing, twice, in near-identical form. Treat it as a second-hand description of the video, not as independently verified video evidence.
- **[RECOMMENDATION]** — an architectural decision this blueprint is proposing, grounded in the REPO evidence but not itself a fact about the current system.
- **[INFERENCE]** — a reasoned conclusion drawn from incomplete evidence, flagged explicitly rather than stated as fact.

---

## 1. Current ZenX architecture — [REPO]

**Platform.** CodeIgniter 3.1.13, PHP, Firestore-only (no MySQL anywhere in HR/Payroll/Attendance — confirmed by repo-wide search). 140 controllers, `Common_model` shared by most, `MY_Controller` sets `$this->school_id`/`$this->session_year` from the CI session and initializes `$this->fs` (`Firestore_service.php`) once per request. RBAC via `has_permission($module, $level)`/`require_permission()`. Cloud Functions in `functions/` (`MARK_REGISTRY` push dispatcher). `firebase-rules/` holds rules + indexes, deployed separately from PHP code.

**Existing HR/Attendance/Payroll capability** (full detail in `docs/audit/hr-payroll-gap-analysis.md` and `docs/audit/hr-payroll-gaps-and-risks.md`, produced this session):
- `staff` is already one unified Firestore collection (`{schoolId}_{staffId}`) — no separate Teacher/Employee tables. Teaching vs. non-teaching is a role-category tag, not a schema fork.
- Attendance is real and working: GPS self-punch + biometric/RFID/face-recognition device ingest, raw punch log kept separate from derived daily status, a genuine regularization workflow with a self-approval-fraud guard, CAS-protected writes. Gaps: only a hardcoded `'default'` shift is ever assigned (no real roster), no midnight-crossing handling, no overtime capture, `cron_auto_lock()` is unwired to any scheduler.
- **Payroll is two disconnected systems, not one incomplete one**: `Hr.php` has a real, attendance-aware salary-structure → payslip engine (`salarySlips` collection) with a genuine `Draft→Finalized→Approved→Paid` lifecycle plus a separate month-level hard lock. `Payroll.php`/`PayrollAccountingService.php` is a manual-entry accounting-journal poster (`payrollAccruals`/`payrollPayouts`/`payrollStatutoryDeposits`) with **zero** attendance/leave awareness anywhere in that code path — an operator types the numbers in.
- **Every money value in both systems is a PHP float** with `round(x,2)` as the only precision guard. No BCMath, no integer minor-units, no decimal value object anywhere.
- No canonical org model above `school_id` exists. `Org.php` (an *existing, unrelated* controller) already owns Department + Staff Role CRUD (`schools/{id}.departments`, `schools/{id}.staffRoles`) — any new "Organisation" work must not collide with that name.
- Leave is real: types/balances/carry-forward/paid-unpaid/approval all exist inside `Hr.php`, and approving a leave writes directly into the staff member's attendance day-wise string — a genuinely closed loop already.

**Newly established this round (five additional research passes for this blueprint):**
- `application/libraries/Document_service.php` is a **generic, CAS-protected, refcounted document store**, explicitly designed by its own authors for reuse ("Student/HR/Hostel/… can adopt it later WITHOUT redesign") and already proven generic by three live, independently-scoped callers (`admission`, `student`, `transport_compliance` modules). Onboarding HR needs only additive config (new `types` entries in `application/config/document_service.php`) and a new `module` string — zero change to the library itself. No in-repo text ties a "FROZEN" governance clause to this file; that constraint is this project's own external directive, not something recorded in the code — and conveniently, the additive-config-only integration path satisfies that constraint by construction.
- There **is already a real per-tenant, Firestore-backed, plan-driven capability/entitlement system**: `tenantPublic/{schoolId}.activeModules`, populated by `B2_registry_service::get_features()` from a school's subscription plan's `modules` map. Its only current consumer, the `school_features` session variable, is a lossy, display-name-only projection used exclusively for sidebar-menu visibility (`application/views/include/header.php`) — **no controller anywhere gates request handling on it today.** Separately, `application/config/fees_exemption_v2_flags.php` is a *global* (not per-tenant), non-Firestore, committed-PHP-file flag set used for internal release-cutover switches in the fees domain — unrelated to `tenantPublic`, and not a model to copy for tenant capability gating.
- **No shared approval/request-workflow engine exists.** Five approval-shaped flows were found (HR leave, staff attendance regularization, admission CRM verification, transport SOS acknowledgement, fee concession) and every one is an independently hand-coded state machine with its own Firestore collection, its own status vocabulary, and its own field names for who-decided/when (`decided_by` vs `reviewedBy` vs `approved_by`/`rejected_by` vs `respondedBy`/`resolvedBy`). None share a library.
- `emit_push()` (`MY_Controller.php`) writes to a `pushRequests` collection that a Cloud Function (`onDocumentCreated` trigger) dispatches via `MARK_REGISTRY`, whose recipient resolution is a configurable-by-mark field lookup (not hardcoded identities) — real, working infrastructure. The repo's own code comments flag a live migration hazard: a legacy PHP poller and the new CF dispatcher are both active for different marks, and "a mark handled in two places double-sends."
- The Firestore rules' owner-only self-access idiom (`resource.data.staffId == request.auth.uid || (isAdmin() && isSameSchool())`) is **already the established pattern for payroll and attendance data specifically** — `salarySlips`, `appraisals`, `staffAttendance`, `staffAttendanceSummary`, `attendancePunches` all use it. This is the template any new employee-self-access collection should follow.
- **No employee lifecycle exists beyond hire and a status flip.** Confirmed: no code path converts an accepted `hrApplicants` record into a `staff` record (a human must manually re-key everything); no exit/offboarding data model beyond `Active`/`Inactive` + free-text reason (`exitDate` exists as a field but is *only ever read*, never written, by payroll's proration math — nothing sets it); no tax/investment-declaration flow of any kind; no onboarding-checklist/task-tracking concept.
- **No expense-claim-with-approval workflow exists anywhere** — confirmed by two explicit in-repo "out of scope" notes in `Payroll.php` and `PayrollAccountingService.php` naming reimbursement/advance flows as a future stage. What exists under the name "expense" is Transport's fleet/vehicle cost-tracking (admin-only create/void, no self-service submission) and Accounting's generic bookkeeping Income/Expense tracker (manual ledger entries, not tied to an individual employee).
- **Only `Staff_attendance::punch()`/`me()` exist as staff self-service Bearer-token mobile endpoints today.** Leave apply/balance, document view/upload, payslip view, expense submission, and profile view/edit all return "NOT FOUND" — every one of those flows currently lives exclusively behind the PHP admin-session + RBAC path, reachable only from the browser Admin Panel. A proven Bearer-token bootstrap pattern (`Api_auth::require_auth()`) already exists and is demonstrated by two controllers (`Staff_attendance.php`, `Transport_driver_api.php`).
- The current Firestore composite-index footprint is 260 entries; roughly 19-24 of those already serve HR/attendance/payroll collections (~7%) — a moderate existing footprint to extend carefully, not a blank slate.

---

## 2. Asanify observed behavior — [ASANIFY — USER-DESCRIBED]

No video or observation-report file was available to read in this session. The following is the workflow as the user has described it in writing (consistent across two separate prompts in this conversation), used here purely as a **behavioral benchmark** — per the brief's own instruction, it governs what the system should *do*, not what it should look like or how it should be coded.

1. Select a pay period → land on a Run Payroll screen.
2. A **blocking** pre-run warning banner names specific employees with specific problems (missing bank detail, missing statutory ID, missing salary component, zero attendance data) — each clickable straight to the fix, never a bare "3 errors found."
3. Warnings carry severity: BLOCKING / WARNING / INFORMATION.
4. An explicit **"Sync LOP Days to Payroll"** step — a reviewed, confirmed action, not silent absorption of attendance into payroll.
5. An editable payroll grid, every manual override logged with old/new value, who, when, and (where required) an approval.
6. A **per-employee calculation drill-down** — a real, inspectable, step-by-step trace: recurring components → proration → earnings → deductions (PF/ESI/PT/TDS/LOP/overtime/bonus/reimbursements/adjustments) → gross → total deductions → employer cost → net pay.
7. Review totals → lock/finalize.
8. Payslips generate **asynchronously** as a background job (never block the browser request on PDF generation for hundreds/thousands of employees) — generation status shown, payslip becomes available once ready.
9. The employee can raise a payroll query directly from their payslip.

**[RECOMMENDATION]** This reconciles cleanly with the earlier "Unified Prototype Build" doc's own 7-step Run Payroll sequence already discussed in this conversation, and with the mock prototype already built — they describe the same underlying flow. Treat this list as the canonical behavioral target for §14-16 below.

---

## 3. Combined target architecture — [RECOMMENDATION]

```
ZenX Platform  (existing, unchanged tenancy: school_id, existing RBAC, existing Auth)
│
├── Existing School ERP          (SIS, Admission, Fees, Examination, Transport, Communication, ...)
├── Existing Accounting          (chartOfAccounts, `accounting` ledger — reused, not duplicated)
├── Existing RBAC                (has_permission()/require_permission() — reused, not duplicated)
├── Existing Auth / Tenancy      (school_id, dual-emit claims — reused, not duplicated)
├── Existing Document Service    (Document_service.php — reused via config, not duplicated)
├── Existing Push Pipeline       (emit_push()/MARK_REGISTRY — reused, extended with new marks)
│
└── Integrated HRMS   (NEW capability layer, native to the existing admin panel)
    ├── Organization       (new: Location; reused: Department via Org.php, school_id as tenant)
    ├── Employee            (reused: `staff` collection, extended with new optional fields)
    ├── Employment/Lifecycle(new)
    ├── Attendance          (reused + extended: real shifts, roster, midnight-crossing)
    ├── Leave               (reused + extended: policy engine sophistication)
    ├── Salary Components   (new engine, same underlying `salarySlips` doc family)
    ├── Payroll             (CONSOLIDATED — see §14 — one canonical engine, not two)
    ├── Compliance          (new: statutory engine, versioned config)
    ├── Documents           (reused: Document_service.php, module='hr')
    ├── Requests            (new shared library, modeled on best existing shape)
    ├── Expenses            (new — no reusable pattern exists)
    ├── Notifications       (reused: MARK_REGISTRY, new marks added)
    └── Analytics           (new, reads from the above — no new write paths)
```

The three "vertical packs" (Education/Corporate/Retail) are **not** separate boxes in this diagram — per the brief's own governing principle and per §31 below, they are capability-flag configurations of the tenant (via `tenantPublic.activeModules`, extended) plus label-dictionary entries, never a fork of any box above.

---

## 4. Core HRMS vs. vertical-specific — classification

| Layer | Item | Classification | Why |
|---|---|---|---|
| Org | Organization/Legal Entity/Location/Department/Designation | **CORE** | `school_id` is already the Legal-Entity-equivalent tenant key; Department already exists generically via `Org.php`. Location is new but generic (campus/office/store are the same entity). |
| People | Employee, Employment, Reporting structure | **CORE** | `staff` is already unified and vertical-agnostic in structure — confirmed no schema fork exists today. |
| Time | Attendance, Shift, Attendance Policy | **CORE** | `Attendance_policy.php` is already a clean, side-effect-free, pluggable-style library — reusable across verticals as-is; only the roster/shift-assignment layer is new. |
| Time | Leave, Leave Policy | **CORE** | The leave engine (`Hr.php`) is generic already — nothing school-specific in its data model. |
| Money | Salary Components, Salary Structures, Payroll, Payroll Inputs, Payroll Snapshot, Payslip | **CORE** | Confirmed generic today (no `if (school)` branch anywhere in `_compute_payslip()`); the fixed 6-component shape is the only vertical-*unfriendly* part, and that's an engineering gap, not an intentional coupling. |
| Money | Compliance (PF/ESI/PT/TDS) | **CORE** | Statute applicability depends on `establishment_type` + state, not on education/corporate/retail — genuinely generic once built. |
| People ops | Documents, Requests, Expenses, Lifecycle, Notifications, Analytics | **CORE** | None of these are vertical-bound by nature; they're currently just unbuilt or fragmented, not education-coupled. |
| Education | Teacher role, Academic role, Class Teacher, Subject relationships, Academic department, Academic session, School calendar, Teacher App integration, school-specific HR workflows | **EDUCATION-SPECIFIC** | `teaching_subjects`, `session_year` (academic-year-shaped period), and subject/class-teacher relationships are genuinely school-bound — confirmed in the Phase-0 vertical-coupling audit. These stay as an extension layer on top of the core Employee/Attendance entities, never inside them. |
| Corporate | Timesheet, Projects, Utilization, corporate KPIs/reporting | **CORPORATE-SPECIFIC** | None of this exists in the repo today; nothing generic depends on it; safe to gate entirely behind a capability flag (§28). |
| Retail | Store, store assignment, store-manager hierarchy, shift roster, rotating weekly-offs, retail workforce analytics | **RETAIL-SPECIFIC (mostly CORE in disguise)** | "Store" is just Location with a different label; "shift roster/rotating weekly-off" is a CORE Attendance-engine capability (§10-11) that retail merely turns on first. Only the retail-flavored *analytics/reporting* is genuinely vertical-specific. |

---

## 5-7. Education / Corporate / Retail integration — [RECOMMENDATION]

**Education (existing tenant, must not regress).** The existing school tenant gains HRMS capability by having its `tenantPublic.activeModules` list extended with new HR module keys (§31), not by migrating to anything new. `staff` remains the employee master; `Teacher`/academic fields remain an extension, resolved the same way `teaching_subjects` already is today (an optional field, shown conditionally by role — confirmed existing pattern in `application/views/new_staff.php`). SIS/Admission/Fees/Examination/Transport/Communication/Accounting are untouched; HRMS integrates *into* them only where a real link exists today (Staff↔Employee is the same record already; Staff Attendance↔HRMS Attendance is the same engine already).

**Corporate.** A new tenant of `org_type=CORPORATE` (per the already-approved Phase-1 plan's `orgType` field on `schools/{id}`) gets Timesheet/Projects/corporate KPI capability flags available to enable — none of that code exists yet and is out of scope until a corporate tenant is actually signed, per the earlier brief's own "build Education + Corporate + Retail packs only when a tenant is signed" guidance.

**Retail.** Same mechanism — `org_type=RETAIL` unlocks Store/shift-roster/rotating-weekly-off capability flags. The *engine* underneath (Attendance's shift/roster layer, §10-11) is CORE work that benefits every vertical, not retail-only work with a flag slapped on it.

---

## 8. Organization model — [RECOMMENDATION, building on the already-approved Phase 1 design]

```
Organization  →  Legal Entity  →  Location        →  Department       →  Team/Designation  →  Reporting Manager → Employee
(schools/{id}    (= schools/{id}    (NEW: locations/    (EXISTING:           (EXISTING: role/       (EXISTING: staff
 today, unchanged) today, unchanged)  {schoolId}_{locId}) schools.departments) designation field on   .reportingManagerId,
                                                                                 staff doc)             new optional field)
```

This is the same additive design already worked out and approved for Phase 1 (`C:\Users\91865\.claude\plans\compressed-wishing-brook.md`): no new `organisations` collection (fold `orgType`+`labels` onto the existing `schools` doc, since `orgId == schoolId` for every tenant today), a genuinely new `locations` collection, Department reused as-is from `Org.php`. This blueprint extends that plan with one addition: a `reportingManagerId` optional field on `staff` (new, additive, no backfill — same null-coalescing convention as everything else) to represent the Reporting Manager layer, since nothing today captures an employee's manager.

Maps to the three verticals without any per-vertical branch: School→Campus→Departments→Teachers/Staff, Company→Offices→Departments→Employees, Retail org→Stores→Store departments→Employees are all literally the same five-node chain with only the terminology-layer labels differing.

---

## 9. Employee model — [RECOMMENDATION]

`staff` **remains the canonical employee master** — confirmed safe: it's already unified, already has no location/campus/branch field to collide with, and every reader already null-coalesces missing fields.

| CORE (never school-specific) | EDUCATION EXTENSION (optional, conditional fields) |
|---|---|
| identity (name, phone, email, DOB) | teacher role / academic role |
| employment (designation, department, dateOfJoining, employmentType) | subject specialization (`teaching_subjects`) |
| organization (school_id / future org_id) | class teacher assignment |
| location (new `locationId`) | academic-session scoping (`sessions[]`) — **stays education-specific**, see §31 |
| department (existing) | |
| reporting manager (new `reportingManagerId`) | |
| salary (existing `staffPrivate`/`salarySlips` link) | |
| documents (new, via Document_service) | |
| lifecycle (new fields, §18) | |

**[RECOMMENDATION]** `session_year`/`sessions[]` must **not** migrate onto the core Employee entity's period concept — Payroll needs a generic "pay period," and Education needs "academic session"; these are different axes that happen to share a value format (`YYYY-YY`) today only because nothing has separated them yet. A new `payPeriod` concept (monthly, per the user's own confirmed preference) is introduced for Payroll/Attendance-summary purposes; `session_year` stays exactly what it is for academic-scoping purposes. This directly satisfies the brief's own rule 21/22 ("Academic Session must remain Education-specific. Payroll Period must be generic HRMS functionality").

---

## 10-11. Attendance / Shift / Roster — [RECOMMENDATION]

Preserve everything that works: `Attendance_policy.php` (GPS/geofence/device classification), `Staff_attendance_writer.php` (canonical persistence, CAS-protected), `Staff_attendance_finaliser.php`, `Holiday_service.php`, the regularization workflow. **Do not rewrite the attendance module** — extend it.

New, additive:
- A real `shifts` sub-structure on `schools/{id}.attendancePolicy` (schema already exists per-shift; only the *assignment* is hardcoded to `'default'` today) plus a `shiftId` field on `staff` (or on the new Location, for retail-style store shifts) so a real roster becomes possible.
- Midnight-crossing fix to `Attendance_policy.php`'s minutes-since-midnight time math (currently 0–1439 with no day-rollover — confirmed defect, dormant only because no current vertical needs overnight shifts).
- Overtime-hours capture (confirmed absent today).
- Weekly-off definition unified: `generate_payroll()`'s hardcoded "2nd/4th Saturday + Sunday" fallback must be replaced with a read of the same configurable `attendancePolicy.weeklyOffs` field the rest of attendance already uses — this is a **bug fix**, not new scope, and is already flagged in the existing risk register as a live inconsistency.

---

## 12. Leave — [RECOMMENDATION]

The existing engine (types, balances, carry-forward + max-carry, paid/unpaid, approval, direct attendance-day-wise write on approval) is genuinely solid — extend it, don't replace it. Additions: half/quarter-day granularity (today's model is whole-day + a separate half-day *attendance* status, not a leave-application granularity), negative-balance support, encashment, mutual-exclusivity rules between leave types, and a real Comp-Off *ledger* (today Comp Off exists only as a Leave Type label with no earn/consume tracking — confirmed gap). All additive to the existing `leaveApplications` collection shape.

---

## 13. Salary Components — [RECOMMENDATION]

Replace the fixed 6-field shape (`basic/hra/da/ta/medical/other_allowances`) with a **Salary Component master** — `code, name, type(earning|deduction|employer_contribution|reimbursement|variable), calc_type(fixed|%basic|%CTC|formula|slab|attendance-linked), taxable, pf_applicable, esi_applicable, pt_applicable, tds_applicable, prorate_on_lop, display_order` — stored as a per-school catalogue (same pattern as `staffRoles`/`departments`: a map on the `schools` doc, or a small dedicated collection if the catalogue grows large). The per-employee `salarySlips` (`type=salary_structure`) doc gains a `components[]` array referencing the catalogue instead of the current hardcoded field list — additive, existing docs keep working via a default-mapping shim (old field names map 1:1 to default component codes on first read).

Flow: Annual CTC entry → template-suggested component breakup (seeded per `org_type`, editable) → HR review/edit → final structure. A whitelisted formula evaluator (no `eval`) is required per the earlier brief's own hard rule.

---

## 14. Payroll engine consolidation — [RECOMMENDATION — the single most consequential decision in this blueprint]

**Audit summary of the four files named in the brief:**

| File | What it actually does today |
|---|---|
| `Hr.php` | The real engine. Salary structures, `_compute_payslip()` (attendance+leave-aware), payroll run lifecycle (Draft→Finalized→Approved→Paid), month-level lock, payslip PDF, Excel export. |
| `Payroll.php` | A *separate*, attendance-blind accounting-journal poster. Operator types in gross/net/deductions manually; posts to `payrollAccruals`/`payrollPayouts`/`payrollStatutoryDeposits`. Its own code explicitly scopes out "reimbursement/advance flows" and "governance approval workflows" as future stages. |
| `PayrollAccountingService.php` | Journal-shaping logic for `Payroll.php`'s posting flow; maintains its *own* `chartOfAccounts` collection, separate from `Hr.php`'s `accounting/type=chartOfAccounts` view. |
| `PayrollReconciler.php` | CLI-only outbox-recovery sweep for stuck accrual/payout journals from the `Payroll.php` path. Not wired to any cron. |

**Decision: `Hr.php`'s engine becomes the one canonical payroll engine.** It is materially further along (attendance-aware, has a real lifecycle, already produces payslips) than `Payroll.php`, which is fundamentally an accounting-posting UI wearing "payroll" in its name. `Payroll.php`'s **genuinely unique, keep-worthy** capability — structured journal posting to `payrollAccruals`/`payrollPayouts`/`payrollStatutoryDeposits` with a chart-of-accounts mapping — is absorbed as the **final stage** of the canonical engine's run lifecycle (§16: Accounting), not kept as a second, independently-triggered system an operator can run out of step with the real payroll numbers. `PayrollAccountingService.php`'s journal-shaping logic is reused as-is (it's decoupled from where its inputs come from); only its *caller* changes, from "whatever an operator typed into `Payroll.php`" to "the canonical engine's locked run totals." `PayrollReconciler.php` is kept and finally wired to a scheduler (confirmed currently unwired — a real, standing gap independent of this consolidation).

Target pipeline (this **is** §10 of the brief, confirmed to already exist in skeletal form and needing consolidation, not invention from zero):

```
Employee → Employment → Salary Structure → Attendance → Leave → LOP → Overtime → Bonus →
Commission → Reimbursement → Loans/Advances → Adjustments → Statutory →
Payroll Input Snapshot → Payroll Calculation Engine → Payroll Run → Payslip → Accounting → Payout
```

---

## 15. Payroll snapshot — [RECOMMENDATION]

`staffAttendanceSummary` is a real, CAS-protected per-month aggregate — a good foundation — but it's a raw day-count string, not a frozen, traceable payroll-input snapshot. New: an explicit **Payroll Input Snapshot** stage, written once when a run advances past "Sync LOP Days to Payroll" (Asanify step 4), that freezes attendance-derived days, leave-derived LOP, and every other input *as consumed by that specific run* — separate from the live `staffAttendanceSummary`, which keeps evolving. Post-lock attendance corrections never rewrite a locked run's snapshot; they become a next-period arrear (this exact rule already exists conceptually in the codebase's `regenerate_staff_payroll()` archive-before-overwrite pattern — extend it, don't reinvent it).

```
Attendance Finalization → Leave Finalization → LOP Calculation → LOP Sync Confirmation →
Payroll Input Snapshot → Payroll Calculation → Lock → Approval → Payslip → Payout
```

---

## 16. Payroll calculation trace — [RECOMMENDATION]

A `computation_trace` array (JSON) stored on the payslip doc, one entry per rule that fired: recurring components, proration factor + formula, LOP days + which policy rule produced them, each deduction (PF/ESI/PT/TDS) with its input wage base and rate, overtime/bonus/reimbursements/adjustments, then gross → total deductions → employer cost → net. The Admin Panel drill-down (Asanify step 6) renders this directly — no separate recompute. The Employee/Staff App gets a simplified, non-technical rendering of the same trace data (not a second calculation).

---

## 17. Compliance — [RECOMMENDATION, this section is a design placeholder — never invent real rates]

PF/ESI/PT/TDS today are flat, operator-typed percentages with no ceilings/eligibility/slabs — confirmed defect. Target: a versioned config table (`effective_from`/`effective_to`) per statute, evaluated per Legal Entity/Location/state, exactly as the earlier full brief specified in its §4.5/§6.2. **Every rate is a placeholder with a `TODO: verify with CA` marker until externally verified** — this blueprint does not, and must not, supply real statutory numbers.

---

## 18. Employee lifecycle — [RECOMMENDATION]

```
Candidate (hrApplicants — EXISTS) → Offer (NEW) → Offer Acceptance (NEW) →
Onboarding (NEW: checklist/tasks, due-dates relative to DOJ, owners) →
Employee (staff — EXISTS, becomes the SAME record, not a new one) →
Probation (NEW field) → Confirmation (NEW field) →
Promotion / Transfer / Salary Revision (extends the EXISTING `_version`/`_prev` versioning
   already used on salary structures — reuse that pattern for role/location changes too) →
Exit (NEW: structured notice_period/exit_date/relieving_date/reason, replacing today's
   free-text-only status flip) → Offboarding (NEW: checklist mirroring onboarding's shape,
   wired to attendance closure / leave / payroll / loans / documents / access revocation) →
Full & Final (NEW: an off-cycle payroll run type, §14) → Archive
```

The confirmed gap that most needs closing first: **candidate-to-employee conversion.** Today a "Joined" status on `hrApplicants` is purely cosmetic; a human re-keys everything into `Staff::new_staff()` from scratch, with no back-reference. Fix: `new_staff()` gains an optional `sourceApplicantId` parameter that pre-fills the form from the applicant record and writes the back-reference — additive, no change to the manual-entry path for staff added without a recruitment trail.

---

## 19. Documents — [RECOMMENDATION]

Reuse `Document_service.php` exactly as its own authors intended. Additive-only integration: new `types` entries in `application/config/document_service.php` (identity proof, education certificate, employment letter, bank proof, payslip PDF, compliance certificate), and a new `module='hr'` (or `'staff'`) value passed at HR call sites. **Zero changes to `Document_service.php` itself** — its CAS/refcount/retention/audit-log machinery is reused verbatim, which is both the technically correct choice (confirmed generic-by-design, proven by three other callers) and the one that respects this project's standing "frozen platform baseline" rule on that file.

---

## 20. Requests — [RECOMMENDATION]

No shared engine exists; five approval flows currently diverge on every axis (status vocabulary, decided-by/at field names, concurrency strategy). **Do not retrofit the five existing ones** (that's out of scope and risks regressing working flows). Build one new, small shared library — `Request_service.php` — modeled on the two most mature existing shapes: `leaveApplications`'s CAS-protected transition + `attendanceRegularizations`'s self-approval-fraud guard. Every *new* HR request type (Payroll Query, Salary Advance, Document Request, Profile Change, Bank Change) is built on this shared library going forward; Leave itself can optionally be migrated onto it in a later phase once the library is proven, but that migration is not required for this blueprint's scope.

---

## 21. Expenses — [RECOMMENDATION]

No reusable pattern exists anywhere in the repo (confirmed — Transport's "expense" work is fleet-cost tracking, not personal reimbursement). Build new, on top of the new `Request_service.php` (§20): Employee → Expense → Category → Receipt (via Document Service, §19) → Approval (via Request_service) → Reimbursement → Payroll and/or Accounting posting (reuses the consolidated engine's accounting-integration stage, §23). This is real, from-nothing work — flagged accordingly in §34's phasing.

---

## 22. Notifications — [RECOMMENDATION]

Reuse `emit_push()`/`MARK_REGISTRY` as-is — it already generalizes recipient resolution via a per-mark field lookup, which is sufficient for HR's needs (a `STAFF_LEAVE_DECIDED` mark already exists as a direct analog to a future `LEAVE_REQUEST_CREATED`/`PAYROLL_FINALIZED`/`ATTENDANCE_ABSENCE` set). New marks are added as new registry rows per the existing pattern, never a second sender. **Do not build a new capability/RBAC-based recipient-routing layer** — recipients stay resolved from explicit doc fields, matching every existing mark; routing "by policy" would be new, unproven machinery this blueprint doesn't need. One pre-existing hazard to be aware of (not this blueprint's to fix): the repo's own code comments already flag that a legacy PHP poller and the newer Cloud Function dispatcher are both active for different marks during an in-flight migration — new HR marks should register with the CF dispatcher, not the legacy poller.

---

## 23. Accounting integration — [RECOMMENDATION]

Use the existing `accounting`/`chartOfAccounts` as the single financial source of truth — this is the direct consequence of §14's consolidation decision. The canonical payroll engine's Lock/Approve/Paid stages post salary expense, employer contributions, statutory liabilities, salary payable, deductions, and payouts through `PayrollAccountingService.php`'s existing journal-shaping logic, now fed by the canonical engine's real numbers instead of an operator's manual entry. No second chart of accounts, no second ledger.

---

## 24. Admin Panel — [RECOMMENDATION]

Native to the existing AdminLTE/Bootstrap 3 + RBAC navigation, not a bolted-on product. Proposed nav group ("HR / Workforce"): Dashboard, Employees, Hiring, Onboarding, Attendance, Shifts, Leave, Payroll, Salary Components, Compliance, Documents, Requests, Expenses, Lifecycle, Reports, Analytics, Settings — matching the brief's own §34 list. Existing screens (`Hr.php`'s tabbed dashboard, `Org.php`'s Departments & Roles, `School_config.php`'s foundational config) are extended, not replaced — the new Location/capability-flag settings already designed for Phase 1 slot into `School_config.php`; new Salary Component/Payroll-run/Requests/Expenses screens become new tabs inside `Hr.php`'s existing SPA-style tab-router pattern (`hr/departments`, `hr/leaves`, etc. already alias to one controller action) or small new sibling controllers where `Hr.php` (already 8,834 lines) shouldn't grow further — following the same "don't keep piling into the god-controller" judgment already applied when `Org.php` was carved out for Departments & Roles.

---

## 25. Employee / Staff App — [RECOMMENDATION]

The Teacher/Parent/Staff Kotlin apps are separate repositories not present in this working directory — this blueprint only designs the PHP-side API surface they'd consume. Today only `Staff_attendance::punch()`/`me()` exist as staff self-service Bearer endpoints; leave, documents, payslip, expenses, and profile all need new controllers/methods following the already-proven `Api_auth::require_auth()` bootstrap pattern (demonstrated twice already, in `Staff_attendance.php` and `Transport_driver_api.php`). Do not build a separate HR mobile app — extend the existing app's backend surface, consistent with the brief's own §35 instruction.

---

## 26-27. RBAC vs. Capability — [RECOMMENDATION]

Keep strictly separate, per the brief's own §31, and — critically — **do not build a new capability mechanism.** One already exists and is real: `tenantPublic/{schoolId}.activeModules`, populated per-tenant from a subscription plan by `B2_registry_service`. Today it's consumed only for sidebar-visibility (`school_features`); this blueprint's recommendation is to make it an **enforced** gate by having new HR controllers check `$this->fs->get('tenantPublic', $this->school_id)['activeModules']` (or an equivalent helper) directly, rather than reusing the lossy `school_features` session projection or inventing a new per-tenant flag store. RBAC (`has_permission('HR', $level)`) stays exactly as it is — unchanged, and answers a different question ("can *this user*") from capability ("does *this tenant have* it"). Example: tenant capability `timesheet=enabled` (from `tenantPublic.activeModules`) is necessary but not sufficient; `has_permission('Timesheet', 'edit')` is the separate, second gate for who may submit vs. approve.

---

## 28. Firestore schema — new/modified collections summary

| Collection | Status | Notes |
|---|---|---|
| `schools/{id}` | Modified (additive) | `+orgType`, `+labels` (Phase 1, already planned), `+salaryComponentCatalogue` (§13) |
| `locations` | New | Phase 1, already planned |
| `staff` | Modified (additive) | `+locationId`, `+reportingManagerId`, `+lifecycle{...}` fields (§18) |
| `salarySlips` (`type=salary_structure`) | Modified (additive) | `+components[]` (§13), default-mapping shim for old docs |
| `payrollSnapshots` | New | §15 |
| `requests` (or a per-type set, TBD in detailed design) | New | §20, via `Request_service.php` |
| `expenses` | New | §21 |
| `documents` / `documentLinks` | Reused, unmodified | `module='hr'`, new `types` config only |
| `hrApplicants` | Modified (additive) | `+convertedStaffId` (or `staff.sourceApplicantId`) for §18's conversion link |
| `pushRequests` | Reused, unmodified | New `MARK_REGISTRY` rows only, in `functions/index.js` |
| `payrollAccruals`/`payrollPayouts`/`payrollStatutoryDeposits`/`chartOfAccounts` (Payroll.php's) | Retired as a parallel system, absorbed | §14 — inputs change from manual entry to the canonical engine's output; the collections themselves may be kept as the accounting-posting target or consolidated further — a detailed-design decision, not this blueprint's |

---

## 29. Security rules impact — [RECOMMENDATION]

New `match` blocks needed for `locations` (Phase 1, already planned — copies the `staff` block idiom), `payrollSnapshots`, `requests`/`expenses` (follow the already-established owner-only Pattern B used by `salarySlips`/`appraisals`: `allow read: if resource.data.staffId == request.auth.uid || (isAdmin() && isSameSchool()); allow write: if isAdmin();` or server-only `false` where appropriate). `documents`/`documentLinks` need no rule changes (already generic). None of this is deployed as part of this blueprint — per §40, rules are written and reviewed, deploy stays a separate, explicitly-authorized step, and the file is re-read immediately before any real edit since it's concurrently edited by other sessions.

---

## 30. Index requirements — [RECOMMENDATION]

New composite indexes needed, scoped tightly to avoid unnecessary growth of the existing 260-entry footprint: `payrollSnapshots(schoolId, staffId, payPeriod)`, `locations(schoolId)` (single-field, likely auto-indexed, no composite needed — confirmed by the same reasoning already used in the approved Phase 1 plan), `requests(schoolId, type, status, createdAt)`, `expenses(schoolId, staffId, status, submittedAt)`. Every new query should be checked against this list before assuming a new index is needed — several will piggyback on patterns Firestore already auto-indexes.

---

## 31. Migration strategy — [RECOMMENDATION]

Everything above is additive by design, consistent with this codebase's existing schemaless, null-coalescing convention (confirmed: no JSON-schema validator exists anywhere; every reader already defaults missing fields). No destructive migration, no mass deletion, no silent rewriting anywhere in this plan. Where defaults need seeding (org profile, salary component catalogue, a staff member's `locationId`), use the same idempotent `ensure_*()` self-healing pattern already proven in this exact codebase (`ensure_unified_roles()` in `deptrole_helper.php`, and the already-approved `ensure_org_profile()` for Phase 1) — called lazily from whichever endpoint first needs the data, not a one-time destructive migration script. Rollback is trivial by construction: every new field/collection can be ignored or deleted without touching anything pre-existing.

---

## 32. Performance strategy — [RECOMMENDATION]

Payroll-run generation must batch by collection query, not per-employee Firestore round-trip — `Hr.php::generate_payroll()` already does this correctly today (one `staffAttendanceSummary` query for the whole month, not one per staff member); the consolidated engine must preserve that discipline as new stages (LOP, snapshot, statutory) are added. Async payslip generation (§16 of the brief, Asanify step 8) is non-negotiable at any real scale — never synchronous PDF generation in the request thread. New list/report screens (Requests, Expenses, Lifecycle) must follow the same schoolWhere-scoped, indexed-query pattern already used throughout, never an unindexed full-collection scan filtered in PHP.

---

## 33. Backward compatibility — [RECOMMENDATION]

Every existing school tenant keeps working, unchanged, with zero visible difference, until an operator explicitly enables new capability flags on that tenant via `tenantPublic.activeModules`. This is the same guarantee already built into the approved Phase 1 plan and extends unchanged to every phase below.

---

## 34. Implementation phases

| Phase | Deliverable | Depends on |
|---|---|---|
| **1** | Organization/Location model, capability-flag enforcement wired to `tenantPublic.activeModules`, terminology layer, isolation test — **already fully designed and approved**, see `C:\Users\91865\.claude\plans\compressed-wishing-brook.md` | — |
| **2** | Payroll engine consolidation (§14): retire `Payroll.php`'s manual-entry path as the primary flow, canonical `Hr.php` engine becomes the sole source posting to Accounting | Phase 1 |
| **3** | Payroll Input Snapshot + LOP rules engine + computation trace (§15-16) | Phase 2 |
| **4** | Salary Component engine + mixed pay models (§13) | Phase 2 |
| **5** | Compliance/statutory engine, versioned config, real rates externally verified before any live use (§17) | Phase 3-4 |
| **6** | Employee lifecycle: candidate→staff conversion link, onboarding checklist, structured exit/offboarding, Full & Final off-cycle run (§18) | Phase 2 |
| **7** | Requests shared library + Expenses module (§20-21) | Phase 1 |
| **8** | Documents (HR config-only onboarding onto Document_service) (§19) | Phase 1 |
| **9** | Employee/Staff App Bearer endpoints: leave, payslip, documents, expenses, profile (§25) | Phases 3-4, 6-8 |
| **10** | Vertical packs: Corporate (Timesheet/Projects) and Retail (Store/Shift-roster) capability + UI, built only when a tenant is signed | Phase 1, 3 |
| **11** | Analytics/Reports across the above | All prior |

Each phase ends with a report per this repo's own convention (what was built, what deviated and why, what's open) — matching the discipline already used for the Attendance/HR/Payroll Phase-0 audit this session.

---

## 35. Dependency graph

```
Phase 1 (Org/Location/Capability) ─┬─→ Phase 2 (Payroll consolidation) ─┬─→ Phase 3 (Snapshot/LOP/Trace)
                                    │                                     ├─→ Phase 4 (Salary Components)
                                    │                                     └─→ Phase 6 (Lifecycle)
                                    ├─→ Phase 7 (Requests/Expenses)
                                    ├─→ Phase 8 (Documents)
                                    └─→ Phase 10 (Vertical packs)
Phase 3+4 ──────────────────────────────→ Phase 5 (Compliance)
Phase 3+4+6+7+8 ─────────────────────────→ Phase 9 (Employee/Staff App)
All ─────────────────────────────────────→ Phase 11 (Analytics)
```

Phase 1 is the true bottleneck — nothing else can safely start until the Organization/Location model and capability-flag enforcement exist, which is exactly why it was scoped first and already carries an approved implementation plan.

---

## 36. Risks

Carried forward from the existing risk register (`docs/audit/hr-payroll-gaps-and-risks.md`), plus new risks surfaced this round:

| Risk | Severity | Source |
|---|---|---|
| Float money throughout both payroll subsystems | Critical | Existing audit |
| Two payroll engines can silently disagree until consolidated (§14) | High | Existing audit; consolidation is this blueprint's answer |
| No real statutory compliance logic | High (compliance) | Existing audit |
| Weekly-off has two disagreeing definitions | Medium | Existing audit; flagged as a bug fix in §10-11 |
| Night shifts crossing midnight unhandled | Medium (dormant) | Existing audit |
| `cron_auto_lock`/`PayrollReconciler` unwired to any scheduler | Low-Medium / Medium | Existing audit |
| **Five divergent approval-shape flows** — building a sixth without a shared library compounds the drift | Medium | New this round — §20's `Request_service.php` is the mitigation |
| **Push pipeline has two active senders during an in-flight migration** — new HR marks must register with the CF dispatcher only | Medium | New this round, already flagged in-repo |
| **`school_features` is UI-only today** — if a new HR controller is accidentally gated on it instead of `tenantPublic.activeModules` directly, capability enforcement silently does nothing server-side | Medium | New this round — call this out explicitly in code review for every new HR endpoint |
| **No reusable expense pattern** — Expenses module is real, from-scratch work; underestimating this in scheduling is a real risk | Low (planning risk) | New this round |
| Candidate-to-employee re-keying is fully manual today — data entry errors on hire are a standing, if minor, operational risk until §18's conversion link ships | Low | New this round |

---

## 37. Acceptance criteria

- Every existing school tenant's HR/Attendance/Payroll screens work identically, byte-for-byte in behavior, before and after each phase ships, with zero opt-in capability flags flipped.
- `php -l` clean on every touched file, every phase.
- `vendor/bin/phpunit --testsuite Unit` stays at the established baseline (currently 155 tests, 4 failures + 27 skipped) plus new tests passing, every phase.
- Phase 2 (payroll consolidation): a single payroll run posts exactly one set of accounting journals — never two, never zero, and `Payroll.php`'s old manual-entry path either redirects to the canonical engine or is explicitly retired, not left running in parallel.
- Phase 3: `sum(components) == gross` exactly, to the smallest currency unit, across a property-style test of randomized structures (per the earlier brief's own test 15) — this is the concrete proof the float-money defect is actually fixed, not just documented.
- Phase 3: the payslip computation trace reproduces the exact same numbers on a second run against identical inputs (idempotency, per the earlier brief's own test 14).
- Phase 6: creating a staff member from a `hrApplicants` record with `sourceApplicantId` set pre-fills the form and the resulting `staff` doc carries the back-reference; creating a staff member without one behaves exactly as today.
- Phase 10 (**the acceptance test for the whole architecture**, per the earlier brief): a seeded school, a seeded corporate tenant, and a seeded retail tenant each run a full payroll period end-to-end through the same engine, with only configuration differing — no code path taken by one and not the others.
- Every new Firestore collection has a `match` block in `firebase-rules/firestore.rules` before any client (mobile app) traffic depends on it, verified via the existing 9-suite/211-test Jest rules test convention.
- No commit, push, or deploy occurs at any phase without a separate, explicit go-ahead for that specific change, per this project's standing rule.

---

*This blueprint synthesizes: this session's Phase-0 audit (`docs/audit/hr-payroll-gap-analysis.md`, `docs/audit/hr-payroll-gaps-and-risks.md`), five additional research passes run specifically for this document (Document Service + capability-flag precedent; approval-workflow + notification-routing patterns; employee-lifecycle gaps; Firestore indexes + self-access security + expense precedent; mobile Bearer-token API surface), and the already-approved Phase 1 implementation plan (`C:\Users\91865\.claude\plans\compressed-wishing-brook.md`). Per §40: implementation does not begin from this document alone — it stops here for explicit approval, phase by phase.*
