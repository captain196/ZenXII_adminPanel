# ZENX HRMS — ASANIFY FUNCTIONAL COVERAGE MATRIX

**Purpose:** determine whether the eventual ZenX HRMS will be functionally comparable to the HRMS scope selected from the Asanify analysis — so we don't spend months polishing individual HR/Payroll functions while remaining far behind the scope we chose.

**Status: ANALYSIS ONLY.** No code, rules, indexes, entitlements or data changed. Nothing committed.

**Benchmark source:** the already-completed Asanify analysis and the agreed capability tree (HRMS Core / HRMS Payroll / Corporate Pack / Retail Pack). **Not re-derived.**
**Implementation source of truth:** the current repository at `integration/phase2` @ `758c924`, including the uncommitted Phase-1 and P0 work.

**Two corrections to earlier findings** are recorded in §0 — both discovered by checking whether a capability exists *under a different name*, exactly as required.

---

## 0. Corrections to previous reports

**0.1 — Recruitment→hire conversion EXISTS. My earlier "MISSING" was wrong.**
The earlier audit examined only `Hr.php` and concluded no applicant→staff conversion existed. There is a **dedicated `Ats.php` controller** (13 endpoints, full `Applied → Shortlisted → Interviewed → Selected → Hired` pipeline) with `get_convert_data()` (pre-fills staff creation from the applicant) and `finalize_hire()` (verifies the staff record, writes `staff_id` / `hired_at` / `ats_status:hired` back onto the applicant, decrements `positions_open`, appends a review trail). Conversion is a **linked two-step workflow**, not absent. Reclassified **PARTIALLY COMPLETE**.

**0.2 — But `Ats.php` is the last RTDB holdout in HR, and it forks recruitment data.**
`Ats.php` performs **26 RTDB calls** (`$this->firebase->get/update()` → `database->getReference()->getValue()`) against `Schools/{school_name}/HR/Recruitment/…`, versus **1** Firestore call. Meanwhile `Hr.php` keeps recruitment in Firestore `hrJobs` / `hrApplicants`. Measured across HR-adjacent controllers:

| Controller | RTDB calls |
|---|---|
| `Hr.php`, `Staff.php`, `Payroll.php`, `Attendance.php`, `Staff_attendance.php` | **0** |
| **`Ats.php`** | **26** |

So there are **two parallel recruitment stores**, one of them on RTDB — a direct violation of the project's zero-RTDB rule and an authority ambiguity (which applicant record is canonical?). This is a top-20 gap (§G-3).

---

## 1. Legend

**Status:** COMPLETE · PARTIAL (partially complete) · ENHANCE (needs enhancement) · REFACTOR (needs refactoring) · MISSING · N/A
**Target:** Core (HRMS Core) · Pay (HRMS Payroll) · Corp (Corporate Pack) · Retail (Retail Pack) · ZenX (existing module, stays put)
**Integration flags:** `Adm` admin-panel change · `App` Parent/Teacher/Employee app change · `Acct` accounting integration · `RBAC` RBAC integration · `Ent` tenant-entitlement integration. `Y` = required, `—` = not required, `✓` = already wired.

---

## 2. THE MATRIX

### 2.1 Organization & Employee — HRMS Core

| # | Capability | Asanify benchmark | ZenX today (evidence) | Status | Pri | Target | Adm/App/Acct/RBAC/Ent | Dependencies |
|---|---|---|---|---|---|---|---|---|
| 1 | Employee master | One universal person entity | `staff` collection, single unified schema; teaching/non-teaching is a role-category tag, not a fork | **COMPLETE** | — | Core | —/—/—/✓/— | — |
| 2 | Departments | Org unit under location | `schools.departments` + `Org.php` full CRUD | **COMPLETE** | — | ZenX | —/—/—/✓/— | — |
| 3 | Designations | Job title | `designation`/`Position` on staff; role catalogue `schools.staffRoles` | **COMPLETE** | — | Core | —/—/—/✓/— | — |
| 4 | Locations (campus/office/store) | Site entity carrying shift/holiday/geofence | `locations` collection + CRUD (Phase 1, uncommitted); **no consumer yet** — no geofence-per-location, no cost centre | **PARTIAL** | P1 | Core | Y/—/Y/✓/✓ | 12, 22, 40 |
| 5 | Org profile / vertical type | industry_type seeds defaults, never gates runtime | `schools.orgType` + `labels` (Phase 1, uncommitted); correctly presentation-only | **COMPLETE** | — | Core | ✓/—/—/—/✓ | 55 |
| 6 | Terminology layer | Per-tenant noun dictionary | `terminology_helper.php` (`org_term`); **1 heading wired** as PoC; no i18n layer exists repo-wide | **PARTIAL** | P2 | Core | Y/Y/—/—/✓ | 5 |
| 7 | Employment types | Full-time/contract etc. | `employmentType` written + dual-cased — **read by nothing** outside views | **PARTIAL** | P2 | Core | Y/—/—/—/— | — |
| 8 | Reporting manager | Manager hierarchy, MSS routing | **No field anywhere** — zero hits for `reportingManager` repo-wide | **MISSING** | P1 | Core | Y/Y/—/Y/— | 33, 41 |
| 9 | Employee custom fields | Per-tenant typed fields | Not present; schema is fixed | **MISSING** | P3 | Core | Y/—/—/—/— | — |

### 2.2 Attendance & Time — HRMS Core *(current Attendance is authoritative; integrate, don't rebuild)*

| # | Capability | Asanify benchmark | ZenX today | Status | Pri | Target | Adm/App/Acct/RBAC/Ent | Deps |
|---|---|---|---|---|---|---|---|---|
| 10 | Daily attendance + monthly summary | Per-day status → period aggregate | `staffAttendance` + `staffAttendanceSummary`, CAS-protected, 9-status vocabulary | **COMPLETE** | — | Core | —/—/—/✓/— | — |
| 11 | Multi-mode capture | Mobile, web, biometric, GPS, RFID | GPS self-punch (uid-locked) + biometric/RFID/face device ingest + manual admin marking; raw punches kept separate from derived status | **COMPLETE** | — | Core | —/✓/—/✓/— | — |
| 12 | Shift management & assignment | Per-employee shift | **Schema exists but unusable**: `shiftId` hardcoded to `'default'` at the only call site; `policy.shifts` can hold many, none selectable | **PARTIAL** | **P0** | Core | Y/Y/—/—/✓ | 4, 13, 14, 50 |
| 13 | Roster / rotating shifts | Published roster, swaps | **No roster concept at all** | **MISSING** | P1 | Core | Y/Y/—/Y/✓ | 12, 50 |
| 14 | Overnight / midnight-crossing | Shifts spanning midnight | Time math is 0–1439 min with no day rollover — would misclassify | **MISSING** | P1 | Core | —/—/—/—/— | 12 |
| 15 | Overtime capture | OT hours + multiplier | **No OT hours concept.** `W` (worked-on-off) now pays extra (P0-4), which is adjacent but not OT | **PARTIAL** | P1 | Core | Y/Y/Y/—/✓ | 12, 15a |
| 16 | Attendance policy engine | Grace, half-day threshold, min hours | `Attendance_policy.php` — clean, pure, side-effect-free; grace/half-day-hours/early-out all implemented | **COMPLETE** | — | Core | —/—/—/—/— | — |
| 17 | Per-employee policy assignment | Different policies per employee | School-global only; no per-staff policy field | **MISSING** | P1 | Core | Y/—/—/—/— | 12 |
| 18 | Regularisation / correction | Employee-filed correction + approval | `attendanceRegularizations`, self-approval **fraud guard**, CAS writes, fully audited | **COMPLETE** | — | Core | —/✓/—/✓/— | — |
| 19 | Attendance locks & audit | Period lock, audit trail | `staffAttendanceLocks`, `attendanceAuditLog` | **COMPLETE** | — | Core | —/—/—/✓/— | — |
| 20 | Holiday calendar | Per-tenant calendar | `Holiday_service` + `calendarEvents`, single canonical reader | **COMPLETE** | — | Core | —/—/—/—/— | — |
| 21 | Weekly-off patterns | Fixed/rotational/alternate-Sat | Configurable `attendancePolicy.weeklyOffs` **but** payroll separately hardcodes "2nd/4th Sat + Sunday" — two definitions | **REFACTOR** | P1 | Core | —/—/—/—/— | 20 |
| 22 | Location-scoped attendance | Geofence per site | Multiple geofences per school, but **not tied to `locationId`** | **PARTIAL** | P1 | Core | Y/Y/—/—/✓ | 4 |
| 23 | Device/inactive-staff guard | Terminated staff cannot punch | **Fixed in P0-5** — device path now checks `status==Active`, GPS parity | **COMPLETE** | — | Core | —/—/—/—/— | — |

### 2.3 Leave — HRMS Core

| # | Capability | Asanify benchmark | ZenX today | Status | Pri | Target | Adm/App/Acct/RBAC/Ent | Deps |
|---|---|---|---|---|---|---|---|---|
| 24 | Leave types & config | Configurable types | `schools.leaveTypes`, seeded defaults incl. Comp-Off/On-Duty | **COMPLETE** | — | Core | —/—/—/✓/— | — |
| 25 | Balances & accrual | Per-year balance, accrual | `leaveApplications` `BAL_{staff}_{year}`, `days_per_year` | **COMPLETE** | — | Core | —/—/—/✓/— | — |
| 26 | Carry-forward | With max cap | `carry_forward` + `max_carry` per type | **COMPLETE** | — | Core | —/—/—/—/— | — |
| 27 | Approval workflow | Apply → approve/reject | Full flow with CAS lock + self-approval guard; writes `L` into attendance; cancel reverts | **COMPLETE** | — | Core | —/✓/—/✓/— | — |
| 28 | Half/quarter-day leave | Granular | Half-day (0.5) fully supported incl. AM/PM period; **quarter-day absent** | **PARTIAL** | P3 | Core | Y/Y/—/—/— | — |
| 29 | Leave → payroll LOP | Unpaid converts to LOP | `_process_leave_for_payroll()` — one shared calculator across generate+regenerate, exhausted-balance still deducts (CR-1), employment-window filtered (CR-4). **Verified solid** | **COMPLETE** | — | Pay | —/—/—/—/— | — |
| 30 | Comp-off ledger | Earn from off-day work, consume as leave | Comp-Off exists **only as a leave type** (`days_per_year: 0`); **no earn/consume ledger**, and attendance `W` does not credit it | **PARTIAL** | P1 | Core | Y/Y/—/—/— | 15, 24 |
| 31 | Encashment | Convert balance to cash | **Not present** (zero hits) | **MISSING** | P2 | Pay | Y/—/Y/—/✓ | 25 |
| 32 | Negative balance / LWP conversion | Allow negative, convert | LWP conversion works; negative-balance policy not configurable | **PARTIAL** | P2 | Core | Y/—/—/—/— | 25 |

### 2.4 Payroll & Compensation — HRMS Payroll

| # | Capability | Asanify benchmark | ZenX today | Status | Pri | Target | Adm/App/Acct/RBAC/Ent | Deps |
|---|---|---|---|---|---|---|---|---|
| 33 | Salary structure | CTC → component breakup | Per-staff structure, but **fixed 6-component schema** (basic/hra/da/ta/medical/other); no catalogue, no formulas, no templates | **ENHANCE** | **P0** | Pay | Y/—/Y/✓/✓ | 34 |
| 34 | Salary component master | Configurable components, calc types, flags | **Does not exist** — components are hardcoded fields | **MISSING** | **P0** | Pay | Y/—/Y/—/✓ | 33 |
| 35 | Formula evaluator | Safe whitelisted expressions, no eval | **Not present** | **MISSING** | P1 | Pay | Y/—/—/—/— | 34 |
| 36 | Multiple pay bases | Monthly/daily/hourly/per-shift/piece/commission | **Monthly-fixed only** — one proportional-retention formula for everyone | **MISSING** | P1 | Pay | Y/—/Y/—/✓ | 33, 34 |
| 37 | `days_basis` options | Calendar / fixed-30 / working | Working-days derivation only, not selectable | **PARTIAL** | P2 | Pay | Y/—/—/—/— | — |
| 38 | Attendance → payroll | Attendance drives pay | Real and working: finalise-gate, summary read, LOP math. **P0 closed the `M`/`W` contract break**; contract test now guards the vocabulary | **COMPLETE** | — | Pay | —/—/—/—/— | 10 |
| 39 | Payroll run lifecycle | Draft→locked→approved→paid | `Draft→Finalized→Approved→Paid/Partially Paid` + separate month-level hard lock + unlock + archive-on-regenerate | **COMPLETE** | — | Pay | —/—/✓/✓/— | — |
| 40 | Payroll input snapshot | Frozen per-period inputs, never re-queried | `staffAttendanceSummary` is a live aggregate, **not a frozen payroll-input snapshot**; payroll re-reads it each run | **MISSING** | **P0** | Pay | Y/—/—/—/— | 38, 42 |
| 41 | Pre-run exception report | **Name the person and the field**, clickable | `preflight_payroll` emits **aggregate counts** ("3 staff have no salary structure"); **no per-employee naming**, and **no bank / PAN / statutory-ID checks at all** | **ENHANCE** | **P0** | Pay | Y/—/—/—/— | 33, 47 |
| 42 | LOP sync confirmation step | Explicit review + confirm before run | **No such step** — attendance is absorbed silently inside `generate_payroll` | **MISSING** | **P0** | Pay | Y/—/—/—/— | 40 |
| 43 | Editable earnings grid | Row-per-employee, editable pre-lock | **Not present** — no manual override surface | **MISSING** | P1 | Pay | Y/—/Y/✓/— | 39, 44 |
| 44 | Manual override audit | Old→new, who, when, reason | **Not present** (follows from 43) | **MISSING** | P1 | Pay | Y/—/—/✓/— | 43 |
| 45 | Calculation trace / drill-down | Step-by-step, inspectable | **No `computation_trace`** (0 hits). `_compute_payslip()` returns a rich breakdown and the slip stores components — the *data* largely exists, the *rule-by-rule trace* and the drill-down UI do not | **PARTIAL** | **P0** | Pay | Y/Y/—/—/— | 33 |
| 46 | Payslip generation | PDF, tenant-branded | `Pdf_generator` + `download_payslip`, gated on run status; Excel export too. Template not tenant-brandable | **PARTIAL** | P2 | Pay | Y/—/—/✓/— | — |
| 47 | Async payslip generation | Background job, status, no request blocking | **Synchronous, on demand** — no queue/async (0 hits). Will not scale to bulk generation | **MISSING** | P1 | Pay | Y/—/—/—/— | 46 |
| 48 | Payroll queries | Employee raises query from payslip | **Not present** | **MISSING** | P2 | Pay | Y/Y/—/✓/— | 46, 53 |
| 49 | Arrears / retro revisions | Retro-effective revision → arrears | **Not present**; salary structure has `_version`/`_prev` but no arrears engine | **MISSING** | P1 | Pay | Y/—/Y/—/— | 33, 51 |
| 50 | Off-cycle runs | F&F, bonus, arrears-only | **Not present** — one run per month/year, enforced | **MISSING** | P1 | Pay | Y/—/Y/✓/— | 39, 52 |
| 51 | Salary revision workflow | Effective-dated revisions | **Not present** (0 hits for `salary_revision`) | **MISSING** | P1 | Core | Y/—/—/✓/— | 33 |
| 52 | Loans / advances | Advance + EMI recovery halting on exit | **Only a chart-of-accounts line** (`2110 Loans Payable`) — no module | **MISSING** | P2 | Pay | Y/Y/Y/✓/✓ | 39 |
| 53 | Employee payslip self-service | View own payslips | `my_payslips()` — correctly session-scoped, no IDOR; **web only, no app endpoint** | **PARTIAL** | P1 | Pay | —/Y/—/✓/— | 46, 62 |
| 54 | Money precision | Integer minor units / decimal | **PHP float throughout**, `round(x,2)` as the only guard; Dr/Cr balanced with an epsilon | **REFACTOR** | **P0** | Pay | —/—/Y/—/— | 33, 34 |
| 55 | Payroll → accounting | Journals, idempotent, reconciled | Transactional outbox, canonical `journalIdFor()`, `PayrollReconciler` idempotent sweep, period-open guards, CoA validation. **49 live `JE_PAYROLL_*` entries verified** | **COMPLETE** | — | Pay | —/—/✓/—/— | — |
| 56 | Bank disbursement / payout files | NEFT/RTGS formats, cash/cheque | **Not present** — payment recorded, no bank file export | **MISSING** | P2 | Pay | Y/—/Y/✓/— | 39 |

### 2.5 Compliance / Statutory — HRMS Payroll

| # | Capability | Asanify benchmark | ZenX today | Status | Pri | Target | Adm/App/Acct/RBAC/Ent | Deps |
|---|---|---|---|---|---|---|---|---|
| 57 | PF / ESI / PT / TDS | Ceilings, slabs, eligibility, effective-dating | **Flat operator-typed percentages.** The code says so itself: *"No statutory ceilings, eligibility thresholds, slabs, tax engines… out of scope"* | **ENHANCE** | **P0** | Pay | Y/—/Y/—/✓ | 33, 34, 54 |
| 58 | Statutory applicability engine | Per entity/state/headcount, effective-dated | **Not present** | **MISSING** | P1 | Pay | Y/—/—/—/✓ | 57 |
| 59 | Tax declarations / investments | 80C, proofs, regime election | **Entirely absent** (0 hits for `80C`, `investment`, `tax_regime`) | **MISSING** | P1 | Pay | Y/Y/—/✓/✓ | 57 |
| 60 | Statutory filings/registers | ECR, Form 24Q, Form 16, wage register | **Not present** | **MISSING** | P2 | Pay | Y/—/—/✓/✓ | 57, 58 |
| 61 | Minimum-wage floor check | Flag/block below floor | **Not present** | **MISSING** | P2 | Pay | Y/—/—/—/✓ | 57 |

### 2.6 Employee Lifecycle — HRMS Core

| # | Capability | Asanify benchmark | ZenX today | Status | Pri | Target | Adm/App/Acct/RBAC/Ent | Deps |
|---|---|---|---|---|---|---|---|---|
| 62 | Recruitment (jobs/applicants) | Job postings + pipeline | **Two systems**: `Hr.php` (Firestore `hrJobs`/`hrApplicants`) **and** `Ats.php` (RTDB, 5-stage pipeline, reviews) | **REFACTOR** | **P0** | Core | Y/—/—/✓/✓ | — |
| 63 | Applicant → staff conversion | No duplicate record on hire | **EXISTS** via `Ats::get_convert_data()` + `finalize_hire()` (verifies staff, links `staff_id`/`hired_at`). Two-step, operator-mediated; `Hr.php`'s own applicant path has no such link | **PARTIAL** | P1 | Core | Y/—/—/✓/— | 62 |
| 64 | Offer / offer acceptance | Offer letter + acceptance | **Not present** | **MISSING** | P2 | Core | Y/—/—/✓/— | 62 |
| 65 | Onboarding checklist | Tasks, owners, due dates rel. DOJ | **Not present** (0 hits) | **MISSING** | P1 | Core | Y/Y/—/✓/— | 63 |
| 66 | Joining date | Canonical DOJ on every path | `Date Of Joining` written by `new_staff`; **P0-7 added it to the two `saveStaff` paths**. Dead aliases `joinDate`/`Joining Date` remain | **PARTIAL** | P1 | Core | —/—/—/—/— | — |
| 67 | Probation / confirmation | Probation period → confirm | **"Probation" is only an appraisal-type enum value** — no lifecycle state | **MISSING** | P2 | Core | Y/—/—/✓/— | 51 |
| 68 | Promotion / transfer | Role/location change with history | Role/department editable; **no effective-dated transfer/promotion history** | **PARTIAL** | P2 | Core | Y/—/—/✓/— | 4, 51 |
| 69 | Exit / offboarding | Notice, exit date, LWD, reason | **P0-6 added** canonical `exitDate` + `lastWorkingDate` + `exitReason`, nulled on rehire. **No notice-period tracking, no offboarding checklist** | **PARTIAL** | P1 | Core | Y/—/—/✓/— | 65, 70 |
| 70 | Full & final settlement | F&F as a workflow | **Not present** — needs off-cycle run (50) | **MISSING** | P1 | Pay | Y/—/Y/✓/✓ | 50, 69 |
| 71 | Final-pay roster inclusion | Mid-month leaver still paid | **P0-6/C2 fixed** — roster now includes exits within the period; runtime-verified (exit 17 Aug ⇒ 13 payable days) | **COMPLETE** | — | Pay | —/—/—/—/— | 69 |
| 72 | Rehire | Reactivation clears exit window | **P0-6 handles it** — exit fields nulled on reactivation; runtime-verified | **COMPLETE** | — | Core | —/—/—/—/— | 69 |
| 73 | Appraisals | Performance cycle | `appraisals` collection, save/submit/review/delete, 5 types | **COMPLETE** | — | Core | —/—/—/✓/— | — |

### 2.7 Documents, Requests, Expenses, Self-Service — HRMS Core

| # | Capability | Asanify benchmark | ZenX today | Status | Pri | Target | Adm/App/Acct/RBAC/Ent | Deps |
|---|---|---|---|---|---|---|---|---|
| 74 | Employee documents | Identity/education/letters/payslips | **`Document_service.php` is generic, CAS-protected, refcounted, retention-aware, with 3 live callers** (admission/student/transport_compliance). **HR is simply not yet a caller** — onboarding needs config-only additions | **MISSING (adoption)** | P1 | Core | Y/Y/—/✓/✓ | — |
| 75 | HR letters generation | Offer/experience/relieving letters | **Not present** (Certificates module is student-side) | **MISSING** | P2 | Core | Y/—/—/✓/— | 74 |
| 76 | Requests & approvals framework | One reusable engine | **No shared engine.** Five independently hand-rolled state machines with divergent field names (`decided_by` vs `reviewedBy` vs `approved_by` vs `respondedBy`) | **MISSING** | P1 | Core | Y/Y/—/✓/— | — |
| 77 | Expenses / reimbursement | Claim → approve → reimburse | **Absent.** Transport "expense" is fleet cost-tracking (admin-only, no self-service); Accounting's is bookkeeping. Both `Payroll.php` and `PayrollAccountingService` explicitly scope reimbursement out as future work | **MISSING** | P2 | Core | Y/Y/Y/✓/✓ | 76 |
| 78 | Employee self-service (app) | Attendance, leave, payslip, docs, profile | **Only `Staff_attendance::punch()`/`me()` exist as Bearer endpoints.** Leave, payslip, documents, expenses, profile → all NOT FOUND app-side | **PARTIAL** | P1 | Core | —/Y/—/✓/✓ | 27, 53, 74 |
| 79 | Manager self-service | Approve team leave/regularisation/OT | Approvals are admin-panel only; **no manager scoping** (needs 8) | **MISSING** | P1 | Core | Y/Y/—/Y/— | 8, 76 |
| 80 | Notifications | Event-based routing | `emit_push` + `MARK_REGISTRY` CF dispatcher — real and working. Recipients resolved per-mark from doc fields; **two senders in flight during migration** | **PARTIAL** | P2 | ZenX | Y/✓/—/—/— | — |
| 81 | HR dashboard & reports | Headcount, cost, absence analytics | `Hr::get_dashboard`, `get_report` (staff/payroll/leave/department) | **PARTIAL** | P2 | Core | Y/—/—/✓/— | — |
| 82 | Audit trail | Every HR/payroll mutation | `log_audit()`, `attendanceAuditLog`, `auditLogs`, tenantAudit; payroll writes audit rows | **COMPLETE** | — | ZenX | —/—/—/—/— | — |

### 2.8 Corporate Pack

| # | Capability | Asanify benchmark | ZenX today | Status | Pri | Target | Adm/App/Acct/RBAC/Ent | Deps |
|---|---|---|---|---|---|---|---|---|
| 83 | Timesheets | Project time capture | Not present | **MISSING** | P3 | Corp | Y/Y/—/✓/✓ | 12 |
| 84 | Projects / client assignment | Project master, allocation | Not present | **MISSING** | P3 | Corp | Y/Y/—/✓/✓ | 83 |
| 85 | Billable/non-billable + utilisation | Utilisation reporting | Not present | **MISSING** | P3 | Corp | Y/—/Y/—/✓ | 83, 84 |
| 86 | Project/client-wise workforce cost | Cost attribution | Not present | **MISSING** | P3 | Corp | Y/—/Y/—/✓ | 84, 55 |

### 2.9 Retail Pack

| # | Capability | Asanify benchmark | ZenX today | Status | Pri | Target | Adm/App/Acct/RBAC/Ent | Deps |
|---|---|---|---|---|---|---|---|---|
| 87 | Store assignment / multi-store | Employee→store, deputation | `locations` exists; **no staff→location consumer**, no deputation | **PARTIAL** | P2 | Retail | Y/Y/—/✓/✓ | 4, 8 |
| 88 | Store roster / rotating offs | Weekly roster, rotating weekly-offs | Not present | **MISSING** | P2 | Retail | Y/Y/—/✓/✓ | 12, 13 |
| 89 | Store-wise attendance / manager view | Per-store views | Not present | **MISSING** | P3 | Retail | Y/Y/—/Y/✓ | 22, 87 |
| 90 | Store-wise workforce cost | Cost per store | Not present | **MISSING** | P3 | Retail | Y/—/Y/—/✓ | 87, 55 |

### 2.10 Platform / cross-cutting

| # | Capability | Asanify benchmark | ZenX today | Status | Pri | Target | Adm/App/Acct/RBAC/Ent | Deps |
|---|---|---|---|---|---|---|---|---|
| 91 | RBAC | Graded, least-privilege | Upgraded graded RBAC; **P0 removed 4 name-bypasses + 1 IDOR**; runtime-verified | **COMPLETE** | — | ZenX | —/—/—/✓/— | — |
| 92 | Salary-data least privilege | Payroll separable from HR | **No `Payroll` RBAC module** — payroll rides on `'HR'`, so "can administer HR" ⇒ "sees everyone's pay" | **ENHANCE** | P1 | ZenX | Y/—/—/Y/— | 91 |
| 93 | Tenant entitlement | Per-tenant capability enablement | `Tenant_capability_service` enforced (Phase 1); **but `hrms_core` is not in `AVAILABLE_MODULES`, so it cannot be enabled for anyone**; add-on/override axis is dead code | **PARTIAL** | **P0** | ZenX | Y/—/—/—/✓ | — |
| 94 | Tenant isolation | Cross-tenant impossible | `docId()`/`schoolWhere()` scoping + rules; **runtime-verified across 2 live tenants** | **COMPLETE** | — | ZenX | —/—/—/—/— | — |
| 95 | Firestore-only | No RTDB | True everywhere in HR **except `Ats.php` (26 RTDB calls)** | **REFACTOR** | **P0** | Core | —/—/—/—/— | 62 |

---

## 3. Summary counts

**A. Total capabilities reviewed: 95**

| | Status | Count | Share |
|---|---|---|---|
| **B** | COMPLETE | **24** | 25% |
| **C** | PARTIAL | **21** | 22% |
| **D** | NEEDS ENHANCEMENT | **4** | 4% |
| **E** | NEEDS REFACTORING | **4** | 4% |
| **F** | MISSING | **42** | 44% |
| | N/A | 0 | — |

By target: Core 45 · Payroll 27 · Corporate 4 · Retail 4 · existing ZenX 15.
By priority: **P0 = 12** · P1 = 34 · P2 = 27 · P3 = 8 · none-needed = 14.

---

## G. Top 20 gaps preventing functional comparability

Ordered by blocking power, not by size.

| # | Gap | Cap. | Why it blocks the benchmark |
|---|---|---|---|
| 1 | **Money stored/computed as PHP float** | 54 | Every payroll feature built on top compounds the error. Fix before adding components/statutory. |
| 2 | **No salary component master** (fixed 6 fields) | 34, 33 | Blocks CTC templates, formulas, pay bases, statutory flags, arrears — the whole compensation layer. |
| 3 | **`Ats.php` on RTDB + duplicate recruitment store** | 62, 95 | Violates the hardest platform rule and forks applicant data across two authorities. |
| 4 | **`hrms_core` cannot be enabled for any tenant** | 93 | Phase 1 shipped an *enforced* gate with no supported way to turn it on. HRMS is dark platform-wide. |
| 5 | **No payroll input snapshot** | 40 | Runs are not reproducible; post-lock attendance edits silently change history. |
| 6 | **No LOP sync/confirm step** | 42 | Asanify's defining control — attendance is absorbed silently today. |
| 7 | **Pre-run warnings are counts, not people** | 41 | No bank/PAN/statutory checks at all; operator can't act on "3 staff have no structure". |
| 8 | **No calculation trace / drill-down** | 45 | The single feature that ends payroll disputes. Data largely exists; trace + UI don't. |
| 9 | **Statutory is flat percentages** | 57 | No ceilings/slabs/eligibility ⇒ not legally usable for real payroll. |
| 10 | **Shift assignment impossible** (`'default'` hardcoded) | 12 | Blocks roster, Retail pack, overtime, night shifts, per-employee policy. |
| 11 | **No requests/approvals framework** | 76 | Every new workflow (expense, payroll query, profile change) hand-rolled again. |
| 12 | **Employee self-service is attendance-only** | 78 | No leave/payslip/docs app endpoints ⇒ ESS benchmark unmet. |
| 13 | **HR not a Document Service caller** | 74 | Infrastructure is ready and proven; HR simply hasn't adopted it. Cheap, high value. |
| 14 | **No pay bases beyond monthly-fixed** | 36 | Blocks hourly/per-shift ⇒ Retail and contract workforces. |
| 15 | **No off-cycle runs / F&F** | 50, 70 | Exits cannot be settled; exit data now exists (P0-6) but has no payout path. |
| 16 | **No editable grid + override audit** | 43, 44 | Benchmark expects controlled pre-lock correction. |
| 17 | **No async payslip generation** | 47 | Synchronous PDF will not survive bulk generation. |
| 18 | **No reporting manager** | 8 | Blocks manager self-service and approval routing. |
| 19 | **Payroll not RBAC-separable from HR** | 92 | Salary is the most sensitive data; "administer HR" currently implies "see all pay". |
| 20 | **No onboarding/offboarding checklists** | 65, 69 | Lifecycle exists as data (P0-6) but not as a managed process. |

---

## H. Dependency graph between the top gaps

```
G1 money precision ─┬─► G2 component master ─┬─► G9 statutory engine
                    │                        ├─► G14 pay bases ──► Retail(87,88)
                    │                        └─► G8 calc trace
                    └─► (everything money-touching)

G4 entitlement reachability ──► gates EVERY HRMS surface being usable at all

G5 payroll snapshot ──► G6 LOP sync/confirm ──► G16 editable grid ──► G8 trace
                                                     └─► G17 async payslips

G10 shift assignment ─┬─► roster(13) ─► Retail pack(88)
                      ├─► overnight(14), per-employee policy(17)
                      └─► overtime(15) ─► comp-off ledger(30)

G11 requests framework ─┬─► expenses(77) ─┬─► G12 ESS(78)
                        └─► payroll query(48)
G13 documents adoption ──────────────────┘

G18 reporting manager ──► manager self-service(79) ──► approval routing

G15 off-cycle runs ──► F&F(70)  [exit data from P0-6 already in place]
G19 payroll RBAC split ── independent, cross-system (PHP + CF + rules)
G3 ATS RTDB migration ── independent, self-contained
```

**Three true roots** (nothing else unblocks them): **G1 money precision**, **G4 entitlement reachability**, **G10 shift assignment**. Almost everything else descends from these plus G2.

---

## I. Recommended implementation order

| Wave | Items | Rationale |
|---|---|---|
| **W1 — Unblock & de-risk** | G4 entitlement reachability · G1 money precision · G3 ATS RTDB migration | G4 makes HRMS enablable at all; G1 must precede every money feature; G3 closes the platform-rule violation. All three are independent — parallelisable. |
| **W2 — Compensation core** | G2 component master · G14 pay bases · G9 statutory engine | The compensation layer, in dependency order. Unlocks Retail/contract workforces. |
| **W3 — The Asanify run experience** | G5 snapshot · G6 LOP sync · G7 per-employee warnings · G8 trace · G16 grid+audit · G17 async payslips | This wave *is* the benchmark's payroll workflow. Ship together — piecemeal delivery gives no visible benefit. |
| **W4 — Time foundation** | G10 shift assignment · roster · overnight · per-employee policy · overtime · comp-off ledger | Prerequisite for Retail; also completes Attendance's own gaps. |
| **W5 — Process & ESS** | G11 requests framework · G13 documents adoption · G12 ESS endpoints · G18 reporting manager · G20 checklists · G15 off-cycle/F&F · G19 payroll RBAC | Depends on W1–W3; delivers the employee-facing half of the benchmark. |
| **W6 — Vertical packs** | Retail (87–90) then Corporate (83–86) | Only after W4. Build when a tenant is signed, per the agreed capability tree. |

---

## J. Existing ZenX modules to EXTEND (not replace)

| Module | Why it survives |
|---|---|
| `staff` + `Staff.php` | Already the unified employee master; extend fields only |
| `Attendance.php` / `Staff_attendance.php` / `Attendance_policy.php` / `Staff_attendance_writer.php` | Current, authoritative, cleanly decoupled (libraries read zero staff docs). Add shift/roster **around** them |
| `Hr.php` leave engine (`_process_leave_for_payroll`) | Verified solid; shared calculator prevents generate/regenerate drift |
| `Hr.php` payroll engine + `_compute_payslip()` | Canonical; already attendance- and accounting-aware. Extend, don't fork |
| `PayrollAccountingService` / `PayrollReconciler` / `accounting` | Mature: outbox, idempotency, period guards, CoA validation, 49 live journals |
| `Document_service.php` | Generic, proven by 3 callers — HR adopts via **config only** |
| `emit_push` + `MARK_REGISTRY` | Working dispatcher; add marks, never a second sender |
| `rbac_helper` + `staffCapabilities` CF | Upgraded and now the sole authority after P0 |
| `Org.php` (departments/roles) | Already provides Department; no rebuild |
| `B2_registry_service` + `tenantPublic` | Entitlement backbone; needs *wiring*, not replacement |

## K. Genuinely NEW components required

1. Salary **component master** + structure templates (34, 33)
2. Safe **formula evaluator** (35)
3. **Payroll input snapshot** entity + freeze step (40)
4. **Computation trace** recorder + drill-down UI (45)
5. **Statutory engine** — versioned, effective-dated, per-entity/state (57, 58)
6. **Tax declaration** module (59)
7. **Shift/roster** assignment + scheduler (12, 13)
8. Shared **request/approval** engine (76)
9. **Expense claims** module (77)
10. **Onboarding/offboarding checklist** engine (65, 69)
11. **Off-cycle payroll run** type + **F&F** workflow (50, 70)
12. **Async payslip** job runner (47)
13. **ESS app endpoints** — leave/payslip/documents/profile (78)
14. **Reporting-manager** field + MSS scoping (8, 79)
15. Corporate & Retail pack modules (83–90)

## L. Can the current HR + Payroll foundation become the canonical HRMS without a rewrite?

**Yes — with high confidence, and the evidence is unusually strong for a legacy foundation.**

- The **hard architectural bones are already right**: one unified employee master (no duplicate to reconcile), a real attendance→payroll linkage, a genuine run lifecycle with locking, a mature and idempotent accounting integration, and — after P0 — graded RBAC as the sole authorization authority, runtime-verified.
- The P0 work demonstrated the foundation **absorbs correction cleanly**: two money defects, four privilege escalations, an IDOR, and the termination gap were all fixed inside the existing engine, with 50/50 runtime assertions passing, zero new test failures, and **no migration required**.
- Not a single gap in §G requires *replacing* an existing subsystem. The two `REFACTOR` items are a data-store migration (`Ats.php` RTDB→Firestore) and a duplicated definition (weekly-off), neither structural.
- The one genuinely invasive change is **money precision (G1)** — broad but mechanical, and it is a representation change, not an architectural one.

**Conclusion: evolve, do not rewrite.** A rewrite would discard a working attendance→payroll→accounting chain and re-incur risks this codebase has already retired.

## M. HRMS completeness estimate (evidence-based)

Raw status counts overstate the shortfall, because the missing items are heavily weighted toward *optional vertical packs* and *later-phase* features, while the load-bearing spine is largely present. Two views:

**Unweighted (all 95 capabilities equal):**
`(24 COMPLETE + 0.5×21 PARTIAL + 0.35×4 ENHANCE + 0.6×4 REFACTOR) / 95` ≈ **39%**

**Weighted by benchmark importance** (excluding the 8 Corporate/Retail pack items, which are explicitly deferred until a tenant is signed, and weighting the payroll-run experience and compensation layer highest):

| Domain | Weight | Est. coverage |
|---|---|---|
| Employee & Org | 10% | 80% |
| Attendance & Time | 20% | 70% |
| Leave | 15% | 90% |
| Payroll run & compensation | 30% | 35% |
| Statutory/compliance | 10% | 15% |
| Lifecycle | 8% | 45% |
| Documents/Requests/ESS | 7% | 20% |

**Weighted completeness ≈ 52% of the selected Asanify benchmark.**

**Reading this honestly:** ZenX is roughly **half-way** to the chosen benchmark — but the half it has is the structurally hard half (attendance capture, leave, run lifecycle, accounting integration, RBAC, tenant isolation), and the half it lacks is dominated by two clusters: **the compensation layer** (components, pay bases, statutory) and **the Asanify payroll-run experience** (snapshot, LOP confirm, per-employee warnings, trace, grid, async). Waves W2 and W3 alone would move the weighted figure to roughly **80%**, which is why they are sequenced first after the W1 unblockers.

---

## Confirmations

- Asanify research **not** re-run; the existing analysis was used as the benchmark.
- Attendance and RBAC **not** redesigned; both treated as authoritative.
- No capability marked MISSING without searching for it under other names — this surfaced `Ats.php` (§0.1) and the Document Service's readiness (74).
- No existing implementation marked COMPLETE merely because a page exists — `preflight_payroll` (41), `my_payslips` (53), comp-off (30) and terminology (6) are all downgraded on functional grounds.
- **Nothing implemented. No code, rules, indexes or entitlements changed. Not committed, not pushed.**
