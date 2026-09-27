# HR & Payroll Multi-Vertical Pivot — Gap Table & Risk Register

Companion to [`hr-payroll-gap-analysis.md`](hr-payroll-gap-analysis.md) (read that first for the narrative findings and citations this table draws on). This document is the brief's §2.5 (gap table, covering every capability in §§4–11) and §2.6 (risk register), pulled into their own file per the user's request for a dedicated gaps report.

**Status:** Phase 0 documentation only. Nothing here is authorized for implementation.

---

## A. Vertical-coupling occurrence table (brief §2.3, in full)

Scope: `Hr.php`, `Staff*.php`, `Attendance.php`, `Staff_attendance.php`, `Payroll.php`, `PayrollReconciler.php`, `rbac_helper.php`, `deptrole_helper.php`, HR/staff/attendance views, `functions/rbac_modules.json`, `application/language/`.

| Occurrence | File:line | Core-to-staff-mgmt or school-vertical-specific? | Generic equivalent | Effort |
|---|---|---|---|---|
| `ADMIN_ROLES`/`HR_ROLES` hardcoded `['Super Admin','School Super Admin','Admin','Principal','HR Manager']` | `Hr.php:27,30` | School-vertical-specific (legacy name-gate, bypasses the data-driven catalogue) | `Principal` → generic "Site/Branch Manager" tier | M |
| `VIEW_ROLES` adds `'Teacher'` | `Hr.php:33` | School-vertical-specific | `Teacher` → `Employee` | M |
| `VIEW_ROLES` (staff attendance view) includes `Class Teacher`/`Academic Coordinator`/`Principal`/`Vice Principal` | `Attendance.php:19` | School-vertical-specific | → `Supervisor`/`Employee`/`Site Manager` | M |
| `STAFF_ROLES` self-punch allow-list, same literal names | `Staff_attendance.php:35-39` | School-vertical-specific literal strings gating a generic feature | Should be *derived* from the catalogue's tier, not hardcoded | M |
| `FINANCE_ROLES` includes `'Principal'`/`'Vice Principal'` | `Payroll.php:60-63` | School-vertical-specific | `Principal` → site-manager tier; rest already generic | S |
| `ALLOWED_ROLE_CLASSES = ['teaching','non_teaching','admin','support']` | `Payroll.php:66` | Borderline — education vocabulary, but structurally a role-class enum | `teaching`→`billable`, `non_teaching`→`overhead` | S |
| `DEFAULT_STAFF_ROLES` seed catalogue (`ROLE_PRINCIPAL`, `ROLE_TEACHER`, `ROLE_HOSTEL_WARDEN`, ...) | `Staff.php:61-89` | Mechanism generic (per-school, data-driven); seed *data* is school-flavored | Mechanism reusable as-is; only the seed list needs a per-vertical swap | S (mechanism) / M (new seed catalogues) |
| `POSITION_ROLE_MAP` keyword migration (`'teacher'/'lecturer'/'professor'→ROLE_TEACHER`) | `Staff.php:93-100` | School-vertical-specific one-time migration helper | Needs a vertical-specific keyword table per new vertical | S |
| Staff roster query scoped by `sessions[]` (academic-year strings) | `Staff.php:510` | School-vertical-specific — staff are period-scoped by academic session, not a generic active/inactive flag | `sessions[]` → `employmentPeriods[]` keyed by fiscal year, or a simple `isActive` flag | M |
| Legacy `assigned_classes` explicitly stripped from staff doc every save | `Staff.php:3123-3125` | Already generic — confirms staff are NOT tied to class/section | N/A | — |
| `teaching_subjects` field, "Teacher-specific fields" UI block keyed off role | `new_staff.php:138,174,263,1309-1326` | School-vertical-specific UI branch | `teaching_subjects` → generic `skills[]`; show/hide-by-role pattern itself is reusable | M |
| Hardcoded copy "Teacher App Login" | `new_staff.php:1911,1940,1947` | Literal UI string, not structural | → "Staff App"/"Employee App" | S |
| `$this->session_year` as the scoping key for leave, payroll runs, appraisals, accounting locks | `Hr.php:4457,4626,6117,6773,6851,6859,7196,7601,7931,8734` (representative) | School-vertical-specific — HR/payroll periods are modeled as academic session, not fiscal year/pay period | `session_year` → `pay_period`/`fiscal_year` at every call site | **L** (dozens of sites across an 8,834-line controller) |
| Variable/log-string leakage only (`$allTeachers`, "allTeachers query failed") over an already-generic `staff` query | `Attendance.php:1763-1781` | Vocabulary-only — schema underneath is already generic | Rename only, zero schema change | S |
| `deptrole_helper.php` (department normalization, role-id derivation, role-access setter) | `deptrole_helper.php:13-90` | Already generic — no school-specific vocabulary found | N/A | — |
| RBAC module catalogue — roughly half education-specific (`SIS`,`Examinations`,`Results`,`LMS`,`Academic`,`Homework`,`Hostel`,`Library`), half already vertical-agnostic (`HR`,`Attendance`,`Fees`,`Accounting`,`Inventory`,`Assets`,`Transport`,`Communication`,`Reports`,`Configuration`,`Admin Users`) | `functions/rbac_modules.json:4-9` | Mixed | Prune/rename academic-only modules per vertical; the agnostic half survives untouched | M |
| `school_id` (`SCH_XXXXXX`) as tenant key | `MY_Controller.php` (repo-wide) | Expected to survive as-is; only the `SCH_` prefix is a naming artifact | Optional cosmetic rename to `TENANT_`/`ORG_` | S (cosmetic only) |
| No i18n layer — `application/language/english/` has only the CI3 placeholder `index.html`; zero `lang()` calls found in HR/staff/attendance views | `application/language/english/index.html`; `config.php` | School-vertical-specific by omission | Full i18n retrofit needed before any per-vertical labeling is possible | **L** |

---

## B. Gap table (brief §§4–11, capability by capability)

`Exists` uses Full / Partial / None. `Effort`: S / M / L. `Priority`: P0 (blocks everything downstream) / P1 (needed for the first pack) / P2 (defer until a tenant needs it).

| Capability | Exists | Where | Gap | Effort | Priority |
|---|---|---|---|---|---|
| Canonical org model (Tenant→Legal Entity→Location→Department→Employee) | **None** | Flat `school_id` tenancy only; `Department` is a free-text field; no Location or Legal Entity entity exists | Insert the full 3-layer hierarchy above school; every `school_id`-scoped query needs to keep working through the migration | L | **P0** |
| Employee unification (`Staff` as universal person entity) | **Full** | `staff` collection, `Staff.php` | None structurally — only the default role-catalogue seed is school-flavored | S | P2 |
| Terminology / label layer | **None** | No i18n layer anywhere in the admin panel | Full retrofit: route every UI string through a resolver, then a per-tenant label dictionary | L | P1 |
| Org Profile (`industry_type`, `establishment_type`, capability flags) | **None** | No such concept in `schools` doc today | Net-new schema, admin UI, and wiring into every conditional feature | M–L | P1 |
| Statutory applicability engine (PF/ESI/PT/LWF rules table, `effective_from`) | **None** | PF/ESI/PT/TDS are flat, operator-entered, un-dated percentages | Full engine per brief §4.5/§6.2 — and blocked on CA-verified rate data, not just engineering | L | P1 (P0 for any tenant running real payroll today, independent of the pivot) |
| Extension points (`AttendancePolicyStrategy`, `LopCalculationStrategy`, etc.) | **Partial** | `Attendance_policy.php` is already a clean, side-effect-free, pluggable-style library — a good template. Everything payroll-side is inline in `Hr.php`'s `_compute_payslip()`/`generate_payroll()` | Extract payroll math into strategy interfaces | L | P1 |
| `attendance_summary` frozen-snapshot contract table with `computation_trace` | **Partial** | `staffAttendanceSummary` is a real, CAS-protected per-month aggregate — good foundation — but it's a raw day-count string, not a computed/traceable LOP+payable-days snapshot | Add a real payroll-input snapshot stage with rule-by-rule trace logging, decoupled from the raw attendance aggregate | M | **P0** |
| LOP rules engine (late-mark aggregation, sandwich rule, versioned policy) | **None/Partial** | Attendance only tracks binary day-statuses (incl. `Tardy`); nothing rolls late marks or half-days into a computed LOP figure; no sandwich rule; no policy versioning | Build the engine from brief §5.3 | L | **P0** |
| Mixed `payment_model` support (MONTHLY_FIXED/HOURLY/PER_SHIFT/PER_SESSION/PIECE_RATE/COMMISSION_LINKED) | **None** | One fixed component set, one proportional-retention formula, applied identically to every staff member | Full pay-basis abstraction per brief §5.4 | L | **P0** |
| `days_basis` options (calendar / fixed-30 / working days) | **None** | `generate_payroll()` hardcodes one specific working-days derivation | Expose as a per-entity selectable option | S–M | P2 |
| Payroll run lifecycle (Draft→...→Published, multi-level approval, hold-individual, off-cycle runs) | **Partial** | Real lifecycle exists: `Draft→Finalized→Approved→Paid/Partially Paid` + a separate month-level hard lock + unlock escape hatch + archive-on-regenerate. Solid foundation. | Missing: a "Reviewed" stage, multi-level role-gated approval, pre-lock exception report, per-individual hold, off-cycle run types (F&F/bonus/arrears-only) | M | P1 |
| Money handling (integer/decimal, no floats) | **Critical gap — confirmed defect** | Every money field in both `Hr.php` and `Payroll.php`/`PayrollAccountingService` is a PHP float; `round(x,2)` is the only guard; Dr/Cr balancing uses an epsilon check, not exact arithmetic | Full money-representation migration | L | **P0** |
| Two disconnected payroll subsystems reconciled into one | N/A — structural | `Hr.php` (attendance-aware) vs. `Payroll.php`/`PayrollAccountingService` (manual-entry, attendance-blind) | Architectural decision required: consolidate, or keep separate with formal reconciliation | — (decision, then build) | **P0** |
| RBAC module separation for Payroll vs. HR | **None** | `Payroll.php` reuses the `'HR'` module | Give Payroll its own RBAC module so it can be granted independently | S | P2 |
| Multi-location (staff→branch/store/campus assignment) | **None** | Geofencing supports multiple points per school, but no staff-to-location field exists | Add as part of the org-model build | M | P1 |
| Shift/roster/rotation | **Partial→mostly None** | Shift *schema* exists in `Attendance_policy.php`; only a single hardcoded `'default'` shift is ever assigned; no rotation | Real roster assignment; also fix midnight-crossing (see risk register) | M–L | P1 (P0 once any 24×7/shift vertical is signed) |
| Overtime capture | **None** | No OT hours field or computation found anywhere | Net-new | M | P2 |
| On-Duty / Comp-Off as attendance-linked, non-leave concepts | **Partial** | Both exist only as Leave Types today, not as attendance statuses | Schema addition | S | P2 |
| Missing-punch / regularization workflow | **Full — strength, reuse as-is** | Self-file → admin-decide flow, self-approval-fraud guard, CAS-protected, fully audited | None | — | — |
| Payslip PDF, tenant branding | **Full** | `Pdf_generator.php`, invoked from `Hr::download_payslip()` | Template is fixed, not tenant-brandable — low priority until multi-tenant branding is actually needed | S | P2 |
| Bank/cash disbursement files, statutory outputs (EPF ECR, ESI, PT challan, Form 24Q) | **None** | Only internal Excel export and internal accounting-journal posting exist | Build per brief §8 | L | P2 (needed before any tenant runs live payroll, not before) |
| Accounting export (GL-code mapped, Tally/Zoho/QuickBooks) | **Partial** | Internal `accounting` ledger posting exists; no external-format export | Build export formatters | M | P2 |
| Self-service transparency-before-lock (employee sees LOP + reason before run locks) | **Partial** | `my_payslips` exists but only post-generation; no pre-lock preview/dispute flow | Build — brief calls this "the killer feature" | M | P1 |
| Statutory wage base / Labour Codes deemed-wages add-back (brief §6.3) | **None** | No `statutory_wage_base` concept, no basic-≥50%-of-remuneration validation, no deemed-wages report | Net-new, and time-sensitive — the underlying law has been in effect since Nov 2025 regardless of this pivot | M | **P0 for any tenant running real Indian payroll today** |

---

## C. Risk register (brief §2.6)

| Risk | Severity | Evidence | Note |
|---|---|---|---|
| **Money stored/computed as float throughout HR & Payroll** | **Critical** | Every money field in `Hr.php::save_salary_structure()`, `_compute_payslip()`, run/payslip docs, and every `Payroll.php`/`PayrollAccountingService` amount is a PHP float with `round(x,2)` as the only guard; Dr/Cr balance checks use `abs(diff) > 0.01` epsilon comparison rather than exact arithmetic. No BCMath, no integer minor-units, no decimal value object anywhere in the codebase. | Rounding-error accumulation compounds across a large payroll run; this is the single highest-priority defect in the codebase for this domain, independent of the multi-vertical pivot. |
| **Two disconnected payroll engines can silently disagree** | High | `Hr.php`'s attendance-aware `salarySlips` engine and `Payroll.php`/`PayrollAccountingService`'s manual-entry ledger-poster share no computation path and only nominally converge in the `accounting` collection — which itself has two separate chart-of-accounts stores (`accounting/type=chartOfAccounts` vs. a dedicated `chartOfAccounts` collection). | An operator could post accounting entries through `Payroll.php` that don't match what `Hr.php` actually computed for the same staff/period, with no automated cross-check found. |
| **Statutory deductions have no real compliance logic** | High (compliance) | PF/ESI/PT/TDS are flat, operator-entered percentages with no ceilings, eligibility thresholds, slabs, or effective-dating — the code comment admits this outright. | Live risk today if this system is already used for real payroll, independent of any pivot decision. |
| **Weekly-off has two disagreeing definitions** | Medium | `generate_payroll()` hardcodes "2nd & 4th Saturday + Sunday" as its own non-working-day rule, while a separately configurable `attendancePolicy.weeklyOffs` field exists and is read elsewhere in the attendance system. | A school with a non-standard weekly-off pattern (e.g. all Saturdays off, or alternate-week pattern different from 2nd/4th) would get silently wrong payable-day counts from payroll while attendance itself reports correctly. |
| **Night shifts crossing midnight are not handled** | Medium (dormant — no current vertical needs it) | `Attendance_policy.php`'s time math is entirely 0–1439 minutes-since-midnight with no day-rollover logic. | Would misclassify any overnight shift (22:00–06:00 style) today; load-bearing for Manufacturing/Healthcare/Hospitality packs later. |
| **`cron_auto_lock` is not wired to any scheduler** | Low–Medium | The endpoint's own docblock says it "should be wired to a 6 PM scheduled job per school"; grepped `functions/`, `firebase-rules/`, and root config — no scheduler references it anywhere. Daily finalisation instead depends on a request-piggybacked poller that only fires when an admin happens to load a page. | A school with no admin logins on a given day has that day's attendance finalisation (and therefore payroll accuracy) delayed indefinitely. |
| **`PayrollReconciler` has no wired cron entry** | Medium | `scripts/cron.txt` only schedules `reconcile` and `integrity` (both fee-domain); the payroll reconciler must be invoked manually. | Stuck pending accrual/payout journals in the transactional outbox could go unnoticed for an arbitrary length of time. |
| **`unlock_payroll()` escape hatch on a locked run** | Unverified — flag for follow-up | `Hr.php:8270` exists and is reachable; this audit did not verify whether unlocking itself is fully audited/gated as tightly as the initial lock. | Worth a dedicated look before Phase 1, since it's the one documented path that can reopen otherwise-immutable payroll history. |
| **No Legal-Entity-level tenant isolation, because Legal Entity doesn't exist yet** | Structural, not yet a live risk | See org-model gap above. | Not a current defect — flagged so isolation testing is designed in from day one of the org-model build, per brief §10's explicit requirement for a cross-tenant-read test. |
| **Encryption-at-rest for PAN/Aadhaar/UAN/ESIC/bank details** | Unverified | `staffPrivate` is a good existing *access* control (PII split off the main staff doc), but this audit did not verify field-level or storage-level encryption, which brief §10 explicitly requires. | Needs a dedicated check before any tenant's real PII/bank data is treated as compliant. |
| **N+1 / unindexed-query behavior at scale (2,000 staff × 12 months × 20 locations)** | Unverified | Not evaluated by any of the three Phase-0 audits — no query-plan review was in scope. | Recommend a dedicated performance audit before brief Phase 5 (payroll-run lifecycle at scale), not before. |

---

## D. What this changes about the brief's own assumptions

Two of the brief's stated premises don't hold for this codebase and should inform how Phase 1 is scoped:

- §3/§5.1 assume attendance→payroll is greenfield ("Phase 2/3–4: NEW"). It isn't — `Hr.php` already has a working version of most of that pipeline. The real work is **upgrading** an existing engine (adding LOP-rule sophistication, mixed pay models, a traceable snapshot) and **resolving the fork** with `Payroll.php`, not building from nothing.
- §2.4's implicit assumption that payroll is a blank slate undersells how much salary-structure, lock/approve/paid lifecycle, and leave-integration work already exists and is reusable. The brief's own rule 3 ("do not rewrite the attendance module — consume it") should extend to `Hr.php`'s payroll engine as well: it is further along than "incomplete" suggests, and is a better foundation to extend than to replace.
