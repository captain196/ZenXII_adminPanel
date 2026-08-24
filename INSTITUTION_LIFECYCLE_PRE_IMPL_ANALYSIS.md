# Institution Lifecycle — PART 1/2 Reconnaissance & Pre-Implementation Analysis

**Status:** RECON COMPLETE · NO CODE WRITTEN · awaiting scope confirmation before implementation
**Date:** 2026-08-21 · Branch `integration/phase2` (867 dirty paths, 693 untracked)
**Method:** repository is the source of truth; every research claim re-verified against current code.

---

## 1. Test & tooling baseline (captured BEFORE any change)

| Item | Value |
|---|---|
| PHP | 8.1.25 (CLI) |
| PHPUnit Unit suite | **3752 tests, 15059 assertions, 10 failures, 1 incomplete** |
| CLAUDE.md documented baseline | "4 failures + 27 skipped" — **STALE, corrected here** |
| Firestore rules test suite | 33 `*.test.js` files with `node_modules` present (incl. `h_lifecycle.test.js`, `h_lifecycle_l2.test.js`) |

**Exact baseline failures (must remain exactly these 10 after any change):**
1. `BackupTest::size_human_formats_bytes_correctly`
2. `HomeworkInputLengthValidationTest::test_bug013_update_homework_length_caps_present`
3. `HomeworkRosterFailClosedTest::test_bug003_catch_fails_closed_with_503`
4. `HomeworkSchoolIdPredicateTest::test_bug011_submissions_queries_carry_school_id`
5. `HomeworkSilentCatchTest::test_bug002_all_six_firestore_catches_now_log`
6. `ParentHomeworkRepoSilentCatchTest::test_bug022_three_structured_debug_log_emits`
7. `ParentHomeworkRepoSilentCatchTest::test_bug022_discarded_exception_pattern_reduced`
8. `ParentHomeworkRepoSilentCatchTest::test_bug022_caller_contracts_preserved`
9. `TeacherExecuteDeleteCascadeTest::test_bug017_cascade_catches_emit_structured_log`
10. `TeacherExecuteDeleteCascadeTest::test_bug017_failure_counters_present_and_incremented`

Incomplete (intentional, documents a known payroll/leave divergence):
`DepartmentCalendarDivergenceTest::test_payroll_should_resolve_department_per_day_like_leave`

---

## 2. Verification of the four research findings

### G2 — crons ignore tenant state → **CONFIRMED, with an important correction**

The research said "11 cron/job controllers". The authoritative current list of *operational* scheduled
jobs is **18** (excluding ~120 `*_verify` / `*_probe` CLI diagnostics, which are operator-run and out
of scope).

| Job | Lifecycle refs | Tenant scoping | Class |
|---|---|---|---|
| `Fees_late_fee_cron` | **0** | env `SCHOOL_ID`+`SESSION_YEAR`, CLI-only | **parent-facing money — P0** |
| `FeeWorker` | 0 (the 1 "lifecycle" hit is a comment `// Job lifecycle`) | per-tenant | money |
| `AccountingReconciler` | 0 | per-tenant, Task Scheduler /15 min | money |
| `AccountingWatchdog` | 0 | per-tenant, /5 min | money |
| `PayrollReconciler` | 0 | per-tenant, CLI | money |
| `Backup_cron` | 0 | scans tenants | retention |
| `Birthday_check` | 0 | per-tenant | notification |
| `Campus_access_cron` | 0 | per-tenant | operational |
| `Transport_activity_cron` | 0 | per-tenant | operational |
| `Transport_expiry_cron` | 0 | per-tenant | operational/notification |
| `Transport_gps_cron` | 0 | per-tenant | **safety-adjacent** |
| `Transport_analytics_rollup_job` | 0 | scans tenants | analytics |
| `Transport_fleet_rollup_job` | 0 | scans tenants | analytics |
| `Transport_expense_reconciler` | 0 | per-tenant | money |
| `Staff_role_check` | 0 | per-tenant | integrity |
| `Attendance::cron_auto_lock` (routed `attendance/cron/auto_lock`) | 0 | per-tenant | operational |
| `B2_analytics_rollup_job` | 9 | scans tenants | billing (**already gated**) |
| `B2_lifecycle_job` | 12 | scans tenants | billing (**already gated**) |

**Correction to the research:** crons are **CLI-only, one tenant per invocation, tenant passed by env
var** — isolation is structural, so this is *not* a cross-tenant leak. The defect is narrower and
precise: **no job asks whether the tenant it was handed is entitled to be operated on.** A tenant
suspended for non-payment keeps accruing parent-facing late fees the moment the scheduler fires.
Severity stands: **P0 for `Fees_late_fee_cron`**, P1 for the rest.

### G3 — apps lifecycle-blind → **CONFIRMED (and the substrate for the fix already exists)**

- `tenantPublic` / `lifecycleState` references in app source: **0** across 254 Parent + 249 Teacher `.kt`.
- But the backend **already mirrors both signals**: `write_lifecycle_state()` fans out
  `tenantPublic.lifecycleState`, and `set_admin_disabled()` fans out `tenantPublic.adminDisabled`
  (`B2_registry_service.php:1023-1040`, `:1444-1451`). Rules already allow the client to read
  `tenantPublic` for its own school (`firestore.rules:3144-3152`).
- Apps *do* catch `PERMISSION_DENIED` — but ad hoc, in ~11 repositories/screens each, with no notion
  of tenant state. A suspended school is therefore indistinguishable from a security failure or a bug.
- **The "H2/H3 reactive logout" described in the rules comments was never implemented app-side.**

### G5 — SA audit writes to RTDB → **PARTIALLY CONFIRMED; materially different from the research**

- **Lifecycle transitions ARE already Firestore-audited**: `write_lifecycle_state()` appends to
  `tenantAudit/{B2_LIFECYCLE_…}` (server-only collection, `firestore.rules:3136`).
- **However**: that audit row hard-codes `actor: {role:'sa', uid:'service'}` — **the real actor is not
  recorded.** A school suspension currently cannot be attributed to a person.
- Separately, `MY_Superadmin_Controller::sa_log()` writes to **RTDB** `System/Logs/Activity/{date}` and
  is used by **49 call sites**, including `school_status_changed`, `auto_suspended`, `auto_grace`.
  This is a genuine ZERO-RTDB violation, but its blast radius is far wider than lifecycle
  (backups, payments, migrations, debug) → **P2, not P1**: fix the lifecycle path, do not rewrite
  `sa_log` as part of this feature.

### G8 — suspend/activate lacks reason/approval/impact → **CONFIRMED**

`Superadmin_schools::toggle_status()` (`:597-619`): one POST, `['active','inactive','suspended']`, no
reason field, no confirmation token, no impact preview, no second person, no CAS. It calls
`set_admin_disabled()`, which substitutes a canned reason string (`manual_suspended`) and performs a
**read-free blind update** — two concurrent SA flips race with no lost-update protection.

### New defects found during this pass (not in the research)

| ID | Finding | Severity | Evidence |
|---|---|---|---|
| **N1** | **The admin-panel lifecycle gate only re-checks every 5 minutes.** After a suspension a logged-in admin keeps full write access for up to 300 s; the gate only force-logs-out, it never gates the individual write. | **High** | `MY_Controller.php:553-587` (`sub_check_ts >= 300`) |
| **N2** | Lifecycle audit rows record no real actor (`uid:'service'`) — institutional acts are unattributable. | **High** | `B2_registry_service.php:1046` |
| **N3** | `set_admin_disabled()` has no CAS/lock → concurrent suspend/reactivate can lose an update, and the paired `write_lifecycle_state()` is a *second, non-atomic* write (partial-transition window). | **High** | `B2_registry_service.php:1410-1465` |
| **N4** | Parent app still declares the RTDB dependency `com.google.firebase:firebase-database-ktx`. | Medium (P2) | `SchoolSyncParent/app/build.gradle.kts:190` |
| **N5** | Documented test baseline in `CLAUDE.md` is stale (4 vs actual 10 failures) — any "no regression" claim measured against it would be false. | Medium | this run |
| **N6** | Research claim "`inactive` has no consumer semantics" is **wrong**: `inactive` disables access via `adminDisabled` while deliberately leaving `lifecycle.state` untouched. Retract G11. | Low (correction) | `B2_registry_service.php:1466-1468` |

---

## 3. Corrections to the research report (repository wins)

1. Scheduled-job count is **18 operational jobs**, not 11; all are CLI + env-scoped per tenant.
2. `FeeWorker` has **zero** lifecycle awareness (the research counted a comment).
3. A Firestore lifecycle audit trail (`tenantAudit`) **already exists** — the gap is actor
   attribution and coverage, not the existence of Firestore auditing.
4. `tenantPublic` already carries `lifecycleState`, `adminDisabled`, `activeModules`, `accessAllowed`
   — no new mirror is needed for any app work.
5. `provisioning_incomplete` is an **existing seventh commercial state** (onboarding quarantine) the
   research missed; the institution axis must not collide with it.
6. G11 retracted (see N6).

---

## 4. Pre-implementation analysis (per production-stability rule)

| Field | Answer |
|---|---|
| **1. Classification** | G2 = **bug** (parent-facing financial). N1/N2/N3 = **weakness**. G3 = **weakness (UX/trust)**. G8 = **weakness (governance)**. Institution axis itself = **enhancement**. |
| **2. Root cause** | Tenant state was designed as a *login/rules* gate (B2 + H-LIFECYCLE), never as a *per-operation* gate. Anything that does not go through login or client rules — CLI jobs, service-account writes, in-session admin requests — is ungated by construction. |
| **3. Blast radius** | Adding a gate is *subtractive*: every new check can only turn a currently-succeeding operation into a refusal. All 16 subsystems are therefore at risk of over-blocking, not under-blocking. Mitigation: default every new gate to **permissive for states that exist today** (`active`/`trialing`/`expiring_soon`/`grace` and absent institution state ⇒ behave exactly as now). |
| **4. Backwards compatibility** | Tenants with **no** `schools.institution` field must resolve to `ACTIVE`. This is the single most important compatibility rule; it makes the whole change inert until a state is explicitly written. |
| **5. Rollback** | Library + helper are additive files; the cron guard is one call per job; the rules change is one clause. Rollback = revert the clause and the calls. No data migration, no backfill required for inertness. |
| **6. Cross-module impact** | Admissions, Fees, Accounting, TMS, Documents, Communication, HR, Attendance, Exams read the gate; none is modified. Parent/Teacher apps are read-only consumers of an existing mirror. |
| **7. Verification** | Pure-PHP unit tests with injected fakes (no live Firestore writes — the operator's live tenant must never be write-probed); rules parity test; cron-guard coverage test; full 3752-test regression against the baseline above. |

---

## 5. Constraints that shape (and limit) implementation

| Constraint | Consequence |
|---|---|
| **No write-probes against the live tenant** (SCH_B56BB9A401) | All verification is offline/unit; no live E2E, no live latency-after numbers on write paths. Read-only latency sampling is possible; write-path latency must be modelled, not measured. |
| **`firestore.rules` is edited concurrently; a deploy ships the whole file** | Rules change will be authored locally and left **UNDEPLOYED**; the emulator suite can validate it without deploying. |
| **Two app repos are separately dirty and (per TMS policy) must ship together** | Kotlin changes would drag parked Transport F12/F13 work into this release. |
| **867 dirty paths on `integration/phase2`** | Any commit must use explicit-path staging, and the branch must be confirmed first. |
| **Never commit/push without explicit permission** | Certification will end with a commit *request*, not a commit. |

---

## 6. Proposed implementation slice (awaiting go/no-go)

**In scope (P0/P1, offline-verifiable):**
1. `Institution_lifecycle` library — 6-state machine, transition validation, lock+CAS, idempotency key,
   append-only `schoolLifecycleEvents` with **real actor attribution**.
2. `Lifecycle_resolver` — composes commercial + institution, most-restrictive-wins, emits the
   capability set (`allowRead/Write/Login/Payments/NewIntake/Jobs/Notifications/Documents/
   TransportSafety/HistoricalAccess` + user-facing message).
3. `lifecycle_guard_helper` for CLI jobs + wiring into the 16 ungated operational jobs, **starting with
   `Fees_late_fee_cron` (P0)**, with safety carve-outs (GPS/SOS/in-flight trips never blocked).
4. Reason capture + real actor on `toggle_status` / `set_admin_disabled`, with CAS (fixes G8, N2, N3).
5. Unit tests: 36 transition pairs, resolver truth table, PHP↔rules parity, cron-guard coverage,
   backward-compat inertness.
6. `firestore.rules` clause + rules test (authored, **not deployed**).
7. Documentation.

**Deferred unless explicitly requested:** Parent/Teacher Kotlin changes (G3), readiness engine,
closure/archive console, institution identity fields, `sa_log` RTDB retirement (P2), N4 dependency
removal (P2).
