# RBAC Live Multi-Role Validation Package — ZenX `ank-yug_b1`

**Status:** VALIDATION PLAN ONLY — no code changes
**Date:** 2026-06-05
**HEAD:** `5e2499f` · branch `ank-yug_b1`
**Goal:** Prove the (already-converged) Firestore RBAC is production-hardened via live, role-by-role runtime verification.

**Roles under test:** Principal · Accountant · Teacher · HR Manager · Librarian

---

## 0. GROUND TRUTH (verified against code — read these first)

| Fact | Value | Source |
|---|---|---|
| Canonical authority | `schools/{schoolId}.roles[role].permissions` (Firestore) | rbac_helper.php:137-145 |
| Session key holding perms | **`rbac_permissions`** (snake_case array) — *not* `rbacPermissions` | Admin_login / MY_Controller `set_userdata('rbac_permissions', …)` |
| Bypass roles (always full access) | `Super Admin`, `School Super Admin`, `Admin` | rbac_helper.php:32 |
| Permission refresh window | **300 s (5 min)** — piggybacks subscription sub-check; also fires on **first** authenticated request after login (`sub_check_ts=0` at login) | MY_Controller.php:251; Admin_login.php:323 |
| Fail-closed | Helper returns `[]` when role/doc unavailable → all non-bypass modules denied | rbac_helper.php:126-151 |
| Enforcement (server) | `require_permission(module)` in each controller constructor → 403 JSON (AJAX) or redirect `admin/index` (non-AJAX) | rbac_helper.php:71-101 |
| Sidebar gate | `$can(module)` **AND** `school_features` (two-layer: plan feature AND role permission) | views/include/header.php |

> **Two-layer caveat:** a module hidden in the sidebar may be hidden because the *plan* (`school_features`) excludes it, not the role. For a clean RBAC test, the test tenant's `school_features` MUST include every module being tested, so the only variable is the role permission.

---

## 1. EXPECTED PERMISSION SETS (from live `schools/{id}.roles`, verified)

| Role | Expected `rbac_permissions` (modules) | Count |
|---|---|---|
| **Principal** | SIS, Attendance, Examinations, Results, LMS, Certificates, Academic, Reports, Events, Communication, Stories, Configuration | 12 |
| **Accountant** | Fees, Accounting, Reports | 3 |
| **Teacher** | Attendance, Examinations, Results, LMS, Stories, Communication | 6 |
| **HR Manager** | HR, Attendance, Reports | 3 |
| **Librarian** | Operations | 1 |

These are the **exact** arrays `load_role_permissions` must return (after `∩ RBAC_MODULES`).

---

## 2. CONTROLLER → MODULE ENFORCEMENT MAP (for direct-URL tests)

| Module | Routes that enforce it (`require_permission`) |
|---|---|
| SIS | `staff`, `sis` |
| Fees | `fees`, `fee_management` |
| Accounting | `accounting`, `account` |
| Attendance | `attendance` |
| Examinations | `examination`, `exam` |
| Results | `result` |
| LMS | `lms` |
| HR | `hr`, `ats` |
| Certificates | `certificates` |
| Communication | `communication`, `noticeannouncement` |
| Events | `events`, `ptm` |
| Stories | `stories` |
| Academic | `academic` |
| Operations | `operations`, `library`, `transport`, `inventory`, `assets` |
| Configuration | `school_config`, `classes`, `schools`, `school_backup`, `health_check`, `fee_simulation` |
| Admin Users | `adminusers`, `auditlogs` |

**Observation (pre-existing, out of RBAC-cleanup scope):** four controllers enforce module strings that are **not** in `RBAC_MODULES` and not granted by any role → reachable by **bypass roles only**, denied for all five test roles by design:
`homework` (`Homework`), `device_management` (`Device Management`), `message_monitor` (`Message Monitor`), `red_flags` (`Red Flags`). Treat these as **expected-denied** for every test role; flag separately if business expects otherwise.

---

## 3. REQUIRED TEST USERS

Create (via AdminUsers → Create Admin, by a bypass-role admin) **five** dedicated test admins on the test tenant, one per role. Each must exist in Firestore `admins/{id}` with the matching `Role` AND have a Firebase Auth account (create_admin does both).

| # | Name | Role | Login (ADM id) | Password | Purpose |
|---|---|---|---|---|---|
| U1 | RBAC Test Principal | Principal | (generated ADMxxxx) | set at create | Tier-2 broad access |
| U2 | RBAC Test Accountant | Accountant | (generated) | set at create | Finance-only |
| U3 | RBAC Test Teacher | Teacher | (generated) | set at create | Academic subset |
| U4 | RBAC Test HR | HR Manager | (generated) | set at create | HR-only |
| U5 | RBAC Test Librarian | Librarian | (generated) | set at create | Single-module (Operations) |
| U6 | (existing) Admin/SSA | Admin or School Super Admin | existing | existing | Bypass control + to perform role edits |

**Preconditions:**
- Test tenant `school_features` includes all 17 modules (so plan-gating never masks a role result). If not, record which modules are plan-excluded and exclude them from "expected visible."
- `schools/{id}.roles` contains the 13 system roles (confirmed present on live tenant).
- Record each test user's ADM id + password at creation (create_admin returns them once).

---

## 4. PER-ROLE TEST MATRIX (the 9 checks)

Run the following for **each** of U1–U5. "Expected modules" = the role's set from §1.

### Check 1 — Login succeeds
- **Step:** Log in at `/admin_login` with the role's ADM id + password.
- **Expected:** Authenticated; lands on dashboard; no login error.
- **Pass/Fail:** PASS if session established and dashboard renders.

### Check 2 — Firestore role permissions hydrate correctly
- **Step:** Immediately after login, load any authenticated page (dashboard). This triggers MY_Controller's first-request refresh (sub_check_ts=0).
- **Expected:** Server log shows no `"RBAC load_role_permissions: fs library not loaded"` error from MY_Controller path; perms resolve from `schools/{id}.roles[role]`.
- **Pass/Fail:** PASS if no fs-missing error AND check 3 passes.

### Check 3 — `rbac_permissions` contains expected modules
- **Step (no-code options):**
  (a) Inspect the CI session store for key `rbac_permissions`; OR
  (b) Use the sidebar as a faithful proxy (it renders directly from `rbac_permissions`); OR
  (c) Authorize a one-off **read-only** probe to dump `load_role_permissions(role)` (separate approval).
- **Expected:** Array equals the role's set in §1 exactly (order-independent), after `∩ RBAC_MODULES`.
- **Pass/Fail:** PASS only if the set matches exactly — no missing, no extra modules.

### Check 4 — Sidebar visibility matches the assigned role
- **Step:** Visually inspect the sidebar.
- **Expected:** Exactly the sections whose module ∈ role set AND ∈ `school_features` are visible; Dashboard/Overview always visible.
- **Pass/Fail:** PASS if visible set == (role set ∩ school_features). Note any divergence and whether plan-gating (not role) caused it.

### Check 5 — Unauthorized modules are hidden
- **Step:** Confirm modules NOT in the role set are absent from the sidebar.
- **Expected:** e.g., Accountant sees no SIS/Attendance/HR/Academic links; Librarian sees only Operations-family links.
- **Pass/Fail:** PASS if zero unauthorized sidebar links are present.

### Check 6 — Direct URL access to unauthorized modules is denied
- **Step:** While logged in as the role, manually navigate to a route for a module NOT in the role set (use §2 map). Test ≥3 unauthorized routes per role.
  - Principal → `/accounting`, `/fees`, `/hr` (all denied)
  - Accountant → `/sis`, `/attendance`, `/academic`, `/examination` (denied)
  - Teacher → `/fees`, `/accounting`, `/hr`, `/school_config` (denied)
  - HR Manager → `/fees`, `/sis`, `/examination`, `/academic` (denied)
  - Librarian → `/fees`, `/sis`, `/attendance`, `/examination`, `/hr` (denied)
- **Expected:** Non-AJAX → redirect to `/admin/index` with flash "You do not have access to that module."; AJAX → HTTP 403 JSON `{status:error,…}`. Server log records `RBAC denied: module=[X] role=[Y]…`.
- **Pass/Fail:** PASS if every unauthorized route is blocked (redirect/403) AND logged.

### Check 7 — Controller-level permission enforcement works (authorized side)
- **Step:** Navigate to ≥3 **authorized** routes for the role (module ∈ set).
  - Principal → `/sis`, `/examination`, `/result`
  - Accountant → `/fees`, `/accounting`
  - Teacher → `/attendance`, `/result`, `/lms`
  - HR Manager → `/hr`, `/attendance`
  - Librarian → `/library` (Operations)
- **Expected:** Page loads (HTTP 200), no redirect, no 403.
- **Pass/Fail:** PASS if all authorized routes load AND all unauthorized routes (Check 6) are denied — i.e., enforcement is correct in both directions.

### Check 8 — Role edits propagate within the refresh window
- **Step:** As U6 (bypass), open AdminUsers → Roles, edit the role under test (e.g., add one module to Teacher, or remove one from Principal), save.
- **Expected:**
  - On the test user's **next login** → new perms apply immediately.
  - On an **already-open** session → new perms apply within **≤300 s** (next sub-check) or on the next request after 300 s have elapsed; sidebar/enforcement reflect the change. (If the edited role == the editor's own role, AdminUsers updates the editor's session immediately — not applicable to cross-user test.)
- **Pass/Fail:** PASS if the change is reflected for the test user within ≤300 s (or on re-login). Record observed latency. **Revert the edit after the test.**

### Check 9 — Fail-closed behavior remains intact
- **Step (choose non-destructive option):**
  (a) Log in as a role whose name has **no** entry in `schools/{id}.roles` (e.g., temporarily assign a test user a non-existent role string via update_admin) → OR (b) authorize a read-only probe that calls `load_role_permissions('NoSuchRole')`.
- **Expected:** Returns `[]` → every non-bypass module denied → sidebar shows only Dashboard → all module routes redirect/403. **No** silent grant, **no** fallback to a default permission set.
- **Pass/Fail:** PASS if access is fully denied (deny-by-default) with no module reachable. **Restore the user's real role after the test.**

---

## 5. CROSS-CUTTING / CONTROL TESTS

| ID | Test | Expected | Pass/Fail |
|---|---|---|---|
| X1 | Bypass control — log in as U6 (Admin/SSA) | All 17 sidebar modules visible; every route loads | PASS if full access |
| X2 | No RTDB read — monitor logs during all logins | No `firebase->get("Schools/…/Roles")` / RTDB role read occurs | PASS if zero RTDB role reads |
| X3 | Whitelist of permissions — if a role's Firestore array contains a junk module | Junk module is dropped (`∩ RBAC_MODULES`); never appears in sidebar | PASS if junk filtered |
| X4 | Session isolation — log in as two roles in separate browsers | Each sees only its own permission set; no bleed | PASS if isolated |

---

## 6. PASS / FAIL CRITERIA (package-level)

**The RBAC module is PRODUCTION-HARDENED only if ALL of the following hold:**

1. All five roles log in successfully (Check 1 ×5).
2. Each role's `rbac_permissions` exactly equals its §1 set — no missing, no extra (Check 3 ×5).
3. Sidebar visible set == (role set ∩ school_features) for every role (Checks 4–5 ×5).
4. Every unauthorized route is denied (redirect/403 + logged) for every role (Check 6 ×5).
5. Every authorized route loads for every role (Check 7 ×5).
6. Role edits propagate on re-login and within ≤300 s on live sessions (Check 8).
7. Fail-closed: unknown/empty role → zero modules, deny-all, no fallback (Check 9).
8. Bypass roles retain full access (X1); no RTDB role read occurs (X2); junk modules filtered (X3); sessions isolated (X4).

**Any single failure → module is NOT hardened; file the specific failing Check + role + route + observed vs expected, and stop before SC-Step10.**

---

## 7. EVIDENCE TO CAPTURE PER ROLE

- Screenshot of the sidebar (Checks 4–5).
- HTTP status + final URL for each tested route (Checks 6–7).
- Relevant `log/` lines: `RBAC denied: module=[…] role=[…]` for denials; absence of `fs library not loaded` (Check 2).
- For Check 8: timestamp of edit vs timestamp the test user observed the change (latency).
- Pass/Fail per Check in a matrix (5 roles × 9 checks + 4 cross-cutting = 49 cells).

---

## 8. NOTES & CAVEATS

- **Refresh latency is 300 s, not 60 s** (corrected from earlier Grader-branch notes). Set tester expectation accordingly for Check 8.
- **Login-time hydration** was made a no-op by the RBAC cleanup (Admin_login no longer calls the helper); perms hydrate on the **first authenticated request** via MY_Controller (which loads `fs`). Check 2 verifies this works (no fs-missing error from the runtime path). This is expected behavior, not a defect.
- **`school_features` must include tested modules**, or sidebar/route results reflect plan-gating, not RBAC. Confirm this in setup.
- The four non-`RBAC_MODULES` controller gates (`Homework`, `Device Management`, `Message Monitor`, `Red Flags`) are **expected-denied** for all five roles; not a failure of this package.
- No code changes are required to execute this package. Checks 3 and 9 may optionally use a one-off **read-only** probe (the project's sanctioned diagnostic mechanism); that would require separate authorization — otherwise use the sidebar proxy + logs.

---

*Validation package only. No files modified except this document. RBAC cleanup package and the Session Convergence audit remain untouched. On a clean pass, proceed to SC-Step10.*
