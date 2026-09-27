# HR & Payroll Multi-Vertical Pivot — Phase 0 Gap Analysis

**Status:** Phase 0 only (audit + documentation). No code, schema, or config changed by this report.
**Scope:** `application/controllers/{Hr,Payroll,PayrollReconciler,Attendance,Staff,Staff_attendance}.php` and related libraries, views, helpers, and `firebase-rules/firestore.rules`.
**Datastore:** Firestore throughout, via `$this->fs` (`Firestore_service.php`), loaded in `MY_Controller.php:280`. **No MySQL table is used anywhere in HR, Payroll, or Attendance** — every "table" in this report is a Firestore collection.
**Method:** three independent read-only research passes (Attendance contract, HR & Payroll inventory, vertical-coupling grep), cross-checked against each other and against a direct controller-directory listing. Where the sub-audits disagreed, the discrepancy is called out explicitly rather than silently resolved (see §0).

---

## 0. Headline findings

1. **The brief's central premise — "payroll does not consume attendance" — is false for this codebase.** `Hr.php::generate_payroll()` reads `staffAttendanceSummary`, gates on a finalisation step, computes days-absent/LWP from the day-wise string, and feeds it directly into `_compute_payslip()`, which reduces gross pay proportionally. This is a real, working, attendance-driven payroll engine. See §2.4f and §3.
2. **There are two disconnected payroll subsystems**, not one incomplete one:
   - `Hr.php` — a real, attendance-aware salary-structure → payslip engine (`salarySlips` collection), with a genuine lock/approve/paid lifecycle.
   - `Payroll.php` + `PayrollAccountingService.php` — a separate accounting-journal poster (`payrollAccruals`/`payrollPayouts`/`payrollStatutoryDeposits`) where every amount is typed in by an operator, with **zero** attendance or leave awareness anywhere in that code path.
   They share only the downstream `accounting` ledger conceptually — even the chart-of-accounts is stored in two different places (`accounting/type=chartOfAccounts` in `Hr.php` vs. a dedicated `chartOfAccounts` collection in `PayrollAccountingService.php`). Any rebuild has to decide whether to consolidate these or formally keep them separate with reconciliation.
   *(Correction to the record: one sub-audit initially reported "no `Payroll.php` controller exists" — this is wrong. `application/controllers/Payroll.php` (571 lines) and `PayrollReconciler.php` both exist and were audited in depth by the HR & Payroll pass; see §2.4.)*
3. **Every money value in HR/Payroll is a PHP float**, with `round(x, 2)` as the only precision guard — no BCMath, no integer minor-units, no decimal value object anywhere in the codebase. This is the single highest-severity defect found. See §2.4e and the companion gaps/risk report.
4. **The "Staff" entity is already unified and already vertical-agnostic in structure** — one Firestore collection, one schema, teaching/non-teaching expressed as a role-category tag, not a schema fork. The RBAC role catalogue is already data-driven per school (`schools.staffRoles`). The parts of the codebase that are genuinely school-specific are narrower than the brief assumes: mostly literal role-name strings in a handful of legacy `_require_role()` allow-lists, the `session_year` (academic-year) period concept used throughout HR/payroll instead of a generic pay period, and the complete absence of any i18n/label layer. See §2.3.
5. **No canonical org model above `school_id` exists at all** — no Legal Entity, no Location entity, no capability-flag system, no org_profile/industry_type concept. Multi-campus support exists only as "multiple GPS geofences per school," not as a staff→location assignment. This is the largest true gap relative to the brief, and it is a precondition for almost everything else (§4 of the brief), so it is the correct place to start if this pivot proceeds.

---

## 1. Inventory

### 1.1 Attendance — Firestore collections

**Staff attendance (primary, since this is what would feed payroll):**

| Collection | Doc ID | Purpose | Evidence |
|---|---|---|---|
| `staffAttendance` | `{schoolId}_{dateISO}_{staffId}` | Canonical per-day record | `Staff_attendance_writer.php:31,159,227` |
| `staffAttendanceSummary` | `{schoolId}_{staffId}_{monthKey}` | Canonical per-month aggregate, CAS-protected | `Staff_attendance_writer.php:36,158,228` |
| `attendancePunches` | server-generated | Raw punch audit trail (GPS/device) — kept separate from derived status by design | `Attendance.php:3175`; rule comment `firestore.rules:1491-1502` |
| `attendanceRegularizations` | one per staff+date | Staff-filed missed-punch correction requests | `Attendance.php:8022,8091-8186`; `firestore.rules:427-451` |
| `staffAttendanceLocks` | per school/date-month | Lock state | `Attendance.php:5718,5766` |
| `attendanceDevices` / `attendanceDeviceKeys` | per device | Registered biometric/RFID/face devices + API keys | `Attendance.php:2728-3049` |
| `attendanceProcessedEvents` / `attendanceEventsFired` | dedup id | Idempotency ledgers for device pushes / notifications | `Attendance.php:682,3116,3288,5826,5926` |
| `attendanceAuditLog` | append-only | Cross-cutting mutation audit (student + staff) | `Attendance.php:756,6947,7291,7552,7703` |
| `attendanceSettings` | `{schoolId}` | Per-school biometric/RFID/face toggles, thresholds | `Attendance.php:2432,5615` |

**Student attendance (for contrast only — out of scope for payroll):** `attendance`, `attendanceSummary`, `attendanceCorrectionRequests`, `attendanceLocks` — structurally parallel but independently coded. `transportAttendance` and `hostelAttendance` are separate, unrelated collections.

### 1.2 Attendance — document shapes

`staffAttendance` (per day): `schoolId, session, date, staffId, status, markedBy, markedAt, source, lateMinutes, previousStatus, _updatedAt` + optional `leaveType, leaveAppId, punchEventId, capturedById, correctionId` (`Staff_attendance_writer.php:207-223`).

`staffAttendanceSummary` (per month): `schoolId, session, staffId, month, year, monthNumber, dayWise (1 char/day), totalDays, present, absent, leave, holiday, tardy, void, halfDay, extraWorked, weeklyOff, workingDays, _updatedAt` (`Staff_attendance_writer.php:188-204`).

Status enum — one canonical source, a PHP class constant: `P A L H T V M W O` = Present / Absent / Leave / Holiday / Tardy(late) / Vacant(unmarked) / Half-day / Worked-on-off / Weekly-Off (`Staff_attendance_writer.php:69`, mirrored in `Attendance_policy.php:58-66`).

### 1.3 Attendance — controller endpoints

All gated by `require_permission('Attendance', level)` (`Attendance.php:80`) except `api_punch`/`cron_auto_lock`, which self-authenticate via API key / cron token. Full route map: `application/config/routes.php:660-749`. Key staff-relevant endpoints: `fetch_staff_attendance`, `save_staff_attendance`, `mark_staff_day`, `bulk_mark_staff`, `autofill_staff_today`, `lock_staff_attendance`/`unlock_staff_attendance`, `staff_regularization` (list/decide), device CRUD, `api_punch` (hardware ingest), and — in the separate `Staff_attendance.php` controller — `punch()` and `me()`, both Firebase-Bearer-token authenticated for the mobile app.

### 1.4 Attendance — background jobs

**No wired cron exists.** `Attendance::cron_auto_lock()` is a token-gated HTTP endpoint whose own docblock says it "should be wired to a 6 PM scheduled job per school" — nothing in the repo actually schedules it (grepped `functions/`, `firebase-rules/`, and root config for the cron token; no matches). The only real automatic finalisation is a **request-piggybacked poller**, `MY_Controller::_auto_finalise_staff_attendance()`, throttled to once/day/school and triggered by ordinary admin traffic — not a guaranteed daily job. It's also invoked synchronously as a pre-payroll gate inside `Hr::generate_payroll()`.

### 1.5 Attendance — business-rule locations

- `Attendance_policy.php` — pure, side-effect-free GPS punch classification (geofence/mock-location/accuracy/window). Genuinely well-isolated already — a good template for the brief's `AttendancePolicyStrategy` extension point.
- `Staff_attendance_writer.php` — sole canonical persistence path, with CAS retry.
- `Staff_attendance_finaliser.php` — persists the "no-vacant" overlay so stored counts feed payroll correctly.
- `Holiday_service.php` — single canonical reader of the holiday calendar.
- Everything else (locks, corrections, audit stamping for the **student** side) lives inline in the 8,500-line `Attendance.php` controller, not factored into libraries.

### 1.6 HR & Payroll — Firestore collections

| Collection | Purpose | Evidence |
|---|---|---|
| `staff` | Unified staff/employee master record | `Hr.php:401,1616,7561`; `Staff.php:1447,2267` |
| `staffPrivate` | Server-only PII/salary mirror (PAN, Aadhaar, PF/ESI numbers, bank details) split off `staff` | `Staff.php:40,3804-3820` |
| `salarySlips` | **Overloaded**: holds salary structures (`SAL_`), payroll runs (`RUN_`), and payslips (`SLIP_`) via a `type` field | `Hr.php:117,129,142,164,715,785,837` |
| `leaveApplications` | Leave requests and leave balances (via `type`) | `Hr.php:323,341,618,689,911,3863` |
| `hrJobs` / `hrApplicants` | Recruitment | `Hr.php:2168-2931` |
| `appraisals` | Performance appraisals | `Hr.php:7251-7454` |
| `schools` | School profile — holds `payrollLocks.{year}_{month}`, `staffRoles`, `departments`, `leaveTypes`, `attendancePolicy`, `attendanceRules` | `Hr.php:996,1492,1537,1650` |
| `accounting` | Canonical GL journals (shared with Accounting module); also reused by `Hr.php` for its own chart-of-accounts view | `Hr.php:1166,5202` |
| `payrollAccruals` / `payrollPayouts` / `payrollStatutoryDeposits` | **Separate** operational metadata used only by `Payroll.php`/`PayrollAccountingService` | `PayrollAccountingService.php:69-71` |
| `chartOfAccounts` | A second, distinct CoA store used only by `PayrollAccountingService` | `PayrollAccountingService.php:832` |
| `payrollReconcilerStatus` | Health doc written by the (unwired) reconciler | `PayrollReconciler.php:299-300` |

### 1.7 HR & Payroll — document shapes

`staff`: `staffId, schoolId, session, sessions[], name, phone, email, status, role, roleId, position, department, employmentType, dateOfJoining, ..., teaching_subjects[], staff_roles[], primary_role` — PII fields nulled and mirrored to `staffPrivate`.

`salarySlips` (`type=salary_structure`, `SAL_{staffId}`): `basic, hra, da, ta, medical, other_allowances` (floats) + `pf_employee, pf_employer, esi_employee, esi_employer, tds` (float **percentages**) + `professional_tax, other_deductions` (float flat amounts) + `_version, _prev{}` (version history).

`salarySlips` (`type=run`, `RUN_{runId}`): `monthKey, totalNet, totalPaid, status ('Draft'|'Finalized'|'Approved'|'Paid'|'Partially Paid'), payments{}, accrual_journal_status`.

`salarySlips` (`type=payslip`, `SLIP_{runId}_{staffId}`): dual snake+camelCase — `netPayable/net_pay, grossEarnings/gross, workingDays, presentDays/days_worked, daysAbsent/days_absent, lwpDays/lwp_days, earnings{}, deductions{}, status`.

### 1.8 HR & Payroll — controller endpoints

`Hr.php` (8,834 lines) — 60+ endpoints under module `'HR'`, all through `_require_role()` → `has_permission('HR', level)`. Covers dashboard/departments, recruitment, leave (types/balances/apply/decide/cancel), salary structures, the full payroll-run lifecycle (`preflight_payroll`, `generate_payroll`, `finalize_payroll`, `approve_payroll`, `download_payslip`, `export_payroll_report`, `mark_payroll_paid`, `payroll_health`, `my_payslips`, `unlock_payroll`, `regenerate_staff_payroll`), month-level `lock_payroll_month`, and appraisals.

`Payroll.php` (571 lines) — the separate accounting-posting UI, gated by `FINANCE_ROLES` (which itself hardcodes `'Principal'`/`'Vice Principal'`): `preview_accrual/payout/statutory` (read-only) and `post_accrual/payout/statutory_deposit/reversal` (all behind a `payroll_engine_integration` config flag), plus reporting endpoints. **Notably, `Payroll.php` reuses the `'HR'` RBAC module rather than having its own** — payroll access cannot currently be granted separately from general HR access.

`Staff.php` (3,850 lines) — staff CRUD/onboarding, import, credential reset, role CRUD.

### 1.9 HR & Payroll — background jobs

`PayrollReconciler.php` is CLI-only, a transactional-outbox recovery sweep for stuck accrual/payout journals. **It is not wired into `scripts/cron.txt`** — the only two scheduled jobs configured there are `reconcile` and `integrity`, both fee-domain. PayrollReconciler must be invoked manually. Everything else (`Hr_canonical_verify.php`, `Staff_lifecycle_verify.php`, `Staff_pii_migrate.php`, etc.) is a CLI-only diagnostic/migration tool, not a runtime job.

### 1.10 HR & Payroll — business-rule locations

- `Hr.php::_compute_payslip()` (`Hr.php:5369-5507`) — the single, pure, shared payslip calculator, deliberately used by both `generate_payroll()` and `regenerate_staff_payroll()` "so both paths run the identical calculation and cannot drift."
- `Hr.php::_process_leave_for_payroll()` — leave→LWP resolution.
- `application/services/PayrollAccountingService.php` (1,015 lines) — journal shaping for the separate Stage-3 posting flow, with logic **manually mirrored** (per its own comment) in `Payroll.php::_buildAccrualPreviewLines()` for client preview — a duplication risk in itself.
- `application/libraries/Pdf_generator.php` — payslip PDF rendering.
- No client-side JS reimplements payroll math anywhere.

---

## 2. Attendance contract (brief §2.2)

**a. Atomic unit:** per calendar day, for both staff and students. Individual punches are a separate, finer-grained event log (`attendancePunches`), not the atomic unit of the canonical record. No per-period/session attendance concept exists for staff.

**b. Status enum:** `P T M A L H O W V` (Present / Tardy / Half-day / Absent / Leave / Holiday / Weekly-Off / Worked-on-off / Vacant), one canonical PHP constant (`Staff_attendance_writer::ALLOWED_STATUS`). **On Duty and Comp Off are not attendance statuses** — both exist only as Leave Types, not as day-marks. There is no distinct "Missing Punch" status either — an unmarked day is `V` (vacant) until finalised.

**c. Capture modes:** GPS self-punch (mobile, Firebase-Bearer auth, uid-locked so a driver/staff member can never punch as someone else), hardware biometric/RFID/face-recognition devices (API-key auth), and manual admin marking. **No selfie capture and no staff QR path exist** (QR exists only for students). Raw punch data is deliberately retained separately from the derived daily status — the Firestore rules comment states outright that "the attendance METHOD recorded here is audit metadata only — it never influences payroll."

**d. Shift/roster:** a shift *schema* exists (per-shift grace period, early/late windows, half-day-hours threshold) in `Attendance_policy.php`, but **only a single hardcoded `'default'` shift is ever assigned** — there is no staff→shift field, no rotation, no roster. **Night shifts crossing midnight are not handled**: the time math works entirely in 0–1439 minutes-since-midnight with no day-rollover logic, so an overnight shift would misclassify. No overtime-hours capture exists anywhere.

**e. Leave module:** lives inside `Hr.php` (not a separate controller). Types, per-year balances, carry-forward with a max-carry cap, paid/unpaid flag, and an approval workflow all exist and are real, not stubs. Approving a leave writes `'L'` directly into the staff's attendance day-wise string; cancelling reverts it. This is a genuinely closed loop.

**f. Holiday calendar / weekly-off:** per-tenant holiday calendar via a single canonical `Holiday_service`, reading a `calendarEvents` collection (two legacy alternate stores are explicitly retired). Weekly-off is nominally configurable per school (`attendancePolicy.weeklyOffs`), **but `generate_payroll()` separately hardcodes "2nd & 4th Saturday + Sunday"** as its own non-working-day fallback — two inconsistent definitions of the same concept live in the codebase simultaneously (see risk register).

**g. Editing past attendance:** not a silent overwrite on either side. Staff use a formal `attendanceRegularizations` self-file → admin-decide flow, with an explicit **self-approval fraud guard** (a reviewer cannot approve their own request) and CAS-protected writes. Every privileged mutation is logged to `attendanceAuditLog`. This is a genuine strength, reusable as-is.

**h. Multi-location:** GPS geofencing supports multiple physical points *per school* (any fence within the school is valid), but there is **no staff→campus/branch assignment field** anywhere — the effective model is multi-tenant-but-single-branch-per-school, not true multi-location.

---

## 3. Does attendance feed payroll today?

**Yes — directly, and by explicit design, in `Hr.php`.** `generate_payroll()`:
1. Refuses to run for the current/future month; for a past month, force-runs attendance finalisation first, with the code comment "Payroll MUST compute on FINALISED attendance."
2. Reads `staffAttendanceSummary` for the target month.
3. Classifies non-working days (with the hardcoded Saturday/Sunday pattern noted above).
4. Parses each staff member's day-wise string into `daysAbsent`, `paidLeaveDays`, `unpaidLeaveDays`, cross-referenced against approved-leave LWP to avoid double-counting.
5. Can **block** slip generation entirely if unmarked ("vacant") days exist and school policy is set to `block` (the default).
6. Feeds all of the above into `_compute_payslip()`, which reduces every earning component proportionally.

**This attendance-awareness exists only inside `Hr.php`'s engine.** `Payroll.php` / `PayrollAccountingService.php` — the separate accounting-posting subsystem — has **zero** reference to attendance or leave anywhere in its code; every gross/net/deduction figure there is typed in by an operator. See headline finding §0.2.

---

## 4. Vertical-coupling audit (brief §2.3)

Full occurrence table, direct answers to the brief's five questions, and effort estimates are reproduced in full in the companion report: **[`hr-payroll-gaps-and-risks.md`](hr-payroll-gaps-and-risks.md) §A**. Summary of the direct answers:

- **Staff entity:** already unified — one `staff` collection, no separate `teachers` table. Category is a field, not a schema fork.
- **Role enums:** split picture. The RBAC catalogue mechanism is already data-driven per `schools.staffRoles` (as CLAUDE.md claims), but a **legacy `_require_role()` name-gate still hardcodes literal role strings** (`'Teacher'`, `'Principal'`, `'Vice Principal'`, `'Class Teacher'`, `'Academic Coordinator'`) in `Hr.php`, `Attendance.php`, `Payroll.php`, and `Staff_attendance.php`, running *in addition to* the graded RBAC check. This is the same shadow-RBAC issue CLAUDE.md already flags project-wide.
- **Org hierarchy:** already generic (`school_id → Department`, free-text). Staff are explicitly **not** tied to class/section — `Staff.php` actively strips a legacy `assigned_classes` field on every save, with a comment noting real class/section assignment lives in a separate Academic Planner collection.
- **i18n/labels:** none. Confirmed no working language layer exists anywhere in the admin panel; all UI strings are hardcoded English literals.
- **Academic year vs. pay period:** pervasively assumed. `session_year` (format `YYYY-YY`) is the scoping key for leave, payroll runs, appraisals, and accounting locks throughout an 8,834-line controller — dozens of call sites would need to be repointed to a generic pay-period/fiscal-year concept. This is the single largest (`L`-effort) item in the vertical-coupling table.

---

## 5. Payroll inventory (brief §2.4)

**a. Salary/compensation structure:** fully built, not stubbed, but **fixed-shape, not a configurable component catalogue**. One structure per staff member with a hardcoded component set (`basic/hra/da/ta/medical/other_allowances` + fixed statutory fields). No CTC-breakdown templates, no formula builder, no per-school component configurability.

**b. Payslip generation:** implemented, including PDF (`Pdf_generator.php`) and Excel export, gated to only download once a run is `Finalized`/`Approved`/`Paid`.

**c. Statutory deductions:** PF/ESI/PT/TDS exist but are **flat operator-configured percentages with no ceilings, eligibility thresholds, slabs, or per-employee applicability logic** — the code's own comment states this outright ("No statutory ceilings, eligibility thresholds, slabs, tax engines... are applied — those are out of scope here"). In the separate `Payroll.php` posting flow, these figures are entered manually with zero computation at all.

**d. Payroll-run lock/finalize:** yes — a real one. `Draft → Finalized → Approved → Paid/Partially Paid`, plus a *separate* month-level hard lock (`payrollLocks.{year}_{month}`) independent of run status, plus an `unlock_payroll()` escape hatch, plus archive-before-overwrite semantics on regeneration. This is a genuine strength and a solid foundation for the brief's fuller lifecycle (§6.4).

**e. Money precision — critical defect, confirmed:** every money field in both payroll subsystems is a PHP float, `round(x, 2)` is the only precision guard, and Dr/Cr balance checks use an epsilon comparison (`abs(diff) > 0.01`) rather than exact arithmetic. No BCMath, no integer minor-units, no decimal value object exists anywhere in the codebase. Full defect list in the companion risk register.

**f. Attendance→payroll linkage:** yes, extensively, in `Hr.php` only — see §3 above.

**g. Leave-balance→payroll linkage:** yes, real. Leave approval computes paid-vs-LWP days against balance at approval time; `generate_payroll()` re-derives per-period LWP attribution and feeds it into the same calculator.

**h. Multi-tenant/multi-branch above school:** **none found.** Every HR/payroll document carries exactly one `schoolId`; there is no group/trust/franchise/district field anywhere in the data model. `scripts/cron.txt` documents that multi-school operation today means "one cron line per school" — external orchestration, not a data-model concept. A staff member belongs to exactly one school, flat tenancy, full stop.

**Staff entity check (brief §2.4-adjacent):** confirmed unified — see §4 above. `Staff.php:42-43` states the unification intent explicitly in a comment: one role entity serves both HR categorization and RBAC access.

---

## 6. Gap table and risk register

Moved to the companion report for length: **[`hr-payroll-gaps-and-risks.md`](hr-payroll-gaps-and-risks.md)**. It covers every capability named in brief §§4–11 (org model, terminology layer, capability flags, statutory engine, extension points, the attendance_summary contract, the LOP engine, pay-basis models, payroll-run lifecycle, money handling, outputs/reports, self-service, and the Labour Codes wage-base add-back) plus a dedicated risk register (money precision, the two-engine split, the weekly-off definition conflict, unwired cron jobs, midnight-crossing shifts, and more).

---

## 7. What Phase 1 would actually need to touch

If this pivot is approved to proceed past Phase 0, the highest-leverage starting points based on what this audit found are, in order:

1. **The canonical org model** (§4 of the brief) — nothing else can be built cleanly until Legal Entity / Location exist above `school_id`. This is also the one change that risks breaking existing school tenants if done carelessly, so it needs the migration+backfill discipline the brief already insists on.
2. **Consolidating (or formally reconciling) the two payroll engines** — building new capability on top of a fork that already exists is worse than resolving the fork first.
3. **Money representation** — every subsequent payroll feature (LOP, mixed pay models, statutory engine) will compound the existing float-money defect if built before it's fixed.
4. Everything else in the gap table can reasonably follow the brief's own phase ordering (§12) once 1–3 are decided.

None of this is authorized to begin — this document is the Phase 0 deliverable only, per the brief's own gate: *"Stop after Phase 0. Wait for my review."*
