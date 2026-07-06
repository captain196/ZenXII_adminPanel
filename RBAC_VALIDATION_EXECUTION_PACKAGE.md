# RBAC Validation — EXECUTION PACKAGE (runbook) — ZenX `ank-yug_b1`

**Status:** EXECUTION PLAN ONLY — no code changes, no commits, no test users created yet
**Date:** 2026-06-05 · **HEAD:** `5e2499f` · branch `ank-yug_b1`
**Pairs with:** `RBAC_LIVE_VALIDATION_PACKAGE.md` (the 49-condition matrix). This document is the *how/when/who* — exact user-creation plan + ordered execution.

**Invariants enforced throughout:** Firestore is the only RBAC authority · no RTDB role reads · no RTDB role writes · no dual-read/dual-write · fail-closed mandatory.

---

## A. PRECONDITIONS (verify BEFORE creating any user)

| # | Precondition | How to confirm | If false |
|---|---|---|---|
| P1 | Test tenant `schools/{id}.roles` contains the 13 system roles | already verified live | seed via AdminUsers → Roles (auto-seed on first open) |
| P2 | `school_features` includes all 17 modules | check tenant plan/features | record excluded modules; subtract them from "expected visible/denied-by-role" so plan-gating isn't mistaken for RBAC |
| P3 | A bypass admin (Admin / School Super Admin) is available = **U6** | log in, confirm AdminUsers reachable | cannot run package without it |
| P4 | Refresh window = **300 s**; session key = **`rbac_permissions`** | code-confirmed | n/a (tester expectation only) |
| P5 | Log access for `RBAC denied …` lines + absence of `fs library not loaded` | confirm log path writable/readable | evidence capture degraded |

---

## B. TEST-USER CREATION PLAN

All five created by **U6 (bypass)** via **AdminUsers → Create Admin**. `create_admin` writes Firestore `admins/{ADM}` (Role + Profile) **and** a Firebase Auth account, and returns the generated **ADM id + password once** — record them immediately. No RTDB role write occurs.

**Field rules (from `create_admin`):** name, email (must be unique across admins), role (must exist in `schools/{id}.roles`), password 8–72 chars; phone optional.

| # | Name | Email (unique) | Role | Password | Capture after create |
|---|---|---|---|---|---|
| U1 | RBAC Test Principal | rbac.principal@<tenant-domain> | **Principal** | set ≥8 chars | ADM id + pwd |
| U2 | RBAC Test Accountant | rbac.accountant@<tenant-domain> | **Accountant** | set ≥8 chars | ADM id + pwd |
| U3 | RBAC Test Teacher | rbac.teacher@<tenant-domain> | **Teacher** | set ≥8 chars | ADM id + pwd |
| U4 | RBAC Test HR | rbac.hr@<tenant-domain> | **HR Manager** | set ≥8 chars | ADM id + pwd |
| U5 | RBAC Test Librarian | rbac.librarian@<tenant-domain> | **Librarian** | set ≥8 chars | ADM id + pwd |

> Use a recognizable email prefix (`rbac.*`) so test users are easy to identify and remove at teardown. Do NOT reuse a real staff email.

---

## C. EXECUTION ORDER (master sequence)

```
Phase 0  Preconditions P1–P5
Phase 1  As U6: create U1–U5; record ADM ids + passwords
Phase 2  Per-role runtime matrix (Checks 1–7) in order: U1 → U2 → U3 → U4 → U5
Phase 3  Cross-cutting controls X1–X4
Phase 4  Check 8 — role-edit propagation (one role, then REVERT)
Phase 5  Check 9 — fail-closed (temp bad role on one user, then RESTORE)
Phase 6  Teardown — disable/delete U1–U5; confirm all edits reverted
```

Run each test user in a **separate browser/incognito profile** (session isolation; also enables X4). Capture evidence per §G of the validation package (screenshots, HTTP status, log lines, latency).

---

## D. PER-ROLE EXECUTION DETAIL

Legend: **Authorized URLs** must return **200 / page loads**. **Denied URLs** must **redirect to `/admin/index`** (non-AJAX) or **403 JSON** (AJAX), and emit `RBAC denied: module=[…] role=[…]`. "Expected sidebar" = modules with a sidebar entry that are in the role set ∩ `school_features` (Dashboard always visible). Modules `Reports` has no controller gate → **sidebar-only** (cannot be route-tested).

---

### U1 — Principal
- **Login account:** ADM id from creation · role **Principal**
- **Expected modules (12):** SIS, Attendance, Examinations, Results, LMS, Certificates, Academic, Reports, Events, Communication, Stories, Configuration
- **Expected denied modules (5):** Fees, Accounting, HR, Operations, Admin Users *(+ bypass-only: Homework, Device Management, Message Monitor, Red Flags)*
- **Authorized URLs to test:** `/sis`, `/staff`, `/attendance`, `/examination`, `/exam`, `/result`, `/lms`, `/certificates`, `/academic`, `/events`, `/ptm`, `/communication`, `/noticeannouncement`, `/stories`, `/school_config`, `/classes`
- **Denied URLs to test:** `/fees`, `/fee_management`, `/accounting`, `/account`, `/hr`, `/ats`, `/library`, `/transport`, `/operations`, `/adminusers`, `/auditlogs`, `/homework`, `/red_flags`
- **Expected sidebar state:** School Setup (Configuration) + SIS/Students + Attendance + Examinations + Results + LMS + Certificates + Academic + Reports + Events + Communication + Stories visible; **no** Fees, Accounting, HR/Payroll, Operations/Library/Transport, Admin Users.

---

### U2 — Accountant
- **Login account:** ADM id · role **Accountant**
- **Expected modules (3):** Fees, Accounting, Reports
- **Expected denied modules (14):** SIS, Attendance, Examinations, Results, LMS, Certificates, HR, Events, Communication, Operations, Academic, Configuration, Admin Users, Stories *(+ bypass-only four)*
- **Authorized URLs to test:** `/fees`, `/fee_management`, `/accounting`, `/account`
- **Denied URLs to test:** `/sis`, `/staff`, `/attendance`, `/examination`, `/result`, `/lms`, `/certificates`, `/hr`, `/ats`, `/events`, `/communication`, `/operations`, `/library`, `/academic`, `/school_config`, `/classes`, `/adminusers`, `/stories`
- **Expected sidebar state:** Fees + Accounting (+ Reports) visible; everything else hidden (no SIS, Attendance, Academic, HR, Operations, Config, Admin Users, Communication, Exams/Results/LMS/Certificates/Events/Stories).

---

### U3 — Teacher
- **Login account:** ADM id · role **Teacher**
- **Expected modules (6):** Attendance, Examinations, Results, LMS, Stories, Communication
- **Expected denied modules (11):** SIS, Fees, Accounting, Certificates, HR, Events, Operations, Academic, Reports, Configuration, Admin Users *(+ bypass-only four)*
- **Authorized URLs to test:** `/attendance`, `/examination`, `/exam`, `/result`, `/lms`, `/stories`, `/communication`, `/noticeannouncement`
- **Denied URLs to test:** `/sis`, `/staff`, `/fees`, `/fee_management`, `/accounting`, `/certificates`, `/hr`, `/ats`, `/events`, `/ptm`, `/operations`, `/library`, `/academic`, `/school_config`, `/classes`, `/adminusers`, `/homework`
- **Expected sidebar state:** Attendance + Examinations + Results + LMS + Stories + Communication visible; **no** SIS/Students, Fees, Accounting, HR, Academic, Configuration/School Setup, Certificates, Events, Operations, Admin Users.
  - *Note:* Teacher denied `/homework` is expected (Homework is a non-RBAC_MODULES, bypass-only gate). Flag separately if business expects teacher homework access.

---

### U4 — HR Manager
- **Login account:** ADM id · role **HR Manager**
- **Expected modules (3):** HR, Attendance, Reports
- **Expected denied modules (14):** SIS, Fees, Accounting, Examinations, Results, LMS, Certificates, Events, Communication, Operations, Academic, Configuration, Admin Users, Stories *(+ bypass-only four)*
- **Authorized URLs to test:** `/hr`, `/ats`, `/attendance`
- **Denied URLs to test:** `/sis`, `/staff`, `/fees`, `/accounting`, `/examination`, `/result`, `/lms`, `/certificates`, `/events`, `/communication`, `/operations`, `/library`, `/academic`, `/school_config`, `/classes`, `/adminusers`, `/stories`
- **Expected sidebar state:** HR & Payroll + Attendance (+ Reports) visible; **no** SIS/Students, Fees, Accounting, Academic, Configuration, Exams/Results/LMS/Certificates/Events/Communication/Stories/Operations/Admin Users.
  - *Note:* HR Manager does **not** have SIS, so `/staff` is denied. Confirm this matches intended HR access to staff records; if HR is expected to manage staff (SIS-gated), that's a taxonomy decision, not an RBAC defect.

---

### U5 — Librarian
- **Login account:** ADM id · role **Librarian**
- **Expected modules (1):** Operations
- **Expected denied modules (16):** SIS, Fees, Accounting, Attendance, Examinations, Results, LMS, Certificates, HR, Events, Communication, Academic, Reports, Configuration, Admin Users, Stories *(+ bypass-only four)*
- **Authorized URLs to test:** `/library`, `/operations`, `/transport`, `/inventory`, `/assets` (all gate on Operations → all should load)
- **Denied URLs to test:** `/sis`, `/staff`, `/fees`, `/accounting`, `/attendance`, `/examination`, `/result`, `/lms`, `/certificates`, `/hr`, `/events`, `/communication`, `/academic`, `/school_config`, `/classes`, `/adminusers`, `/stories`
- **Expected sidebar state:** Operations-family only (Library/Transport/Inventory/Assets per plan); **everything else hidden**. This is the strongest single-module isolation test.
  - *Note:* `Operations` is coarse — Librarian also gains Transport/Inventory/Assets. Expected per current taxonomy (documented coarse-grain; not a defect of this validation).

---

## E. PHASE 4 — Role-edit propagation (Check 8), run once

1. As **U6**, AdminUsers → Roles → edit **Teacher**: add `Certificates`, save.
2. **Re-login** as U3 → expect `Certificates` now present (sidebar + `/certificates` loads) — immediate.
3. On an **already-open** U3 session opened before the edit → expect change to appear within **≤300 s** (next sub-check) or on next request after 300 s. Record observed latency.
4. **REVERT:** As U6, remove `Certificates` from Teacher, save. Confirm U3 reverts on re-login.

PASS: change applies on re-login immediately AND on live session within ≤300 s; no RTDB write occurs during the edit (X2 monitoring).

---

## F. PHASE 5 — Fail-closed (Check 9), run once

1. As **U6**, AdminUsers → Edit Admin U5 → set role to a **non-existent** role string (e.g. `QA_NoSuchRole`). (This is a role-assignment edit, not a roles-map write; Firestore stays the only authority.)
2. Log in as U5 → expect `load_role_permissions` returns `[]` → sidebar shows **only Dashboard** → every module route redirects/403 → no fallback grant.
3. **RESTORE:** As U6, set U5's role back to **Librarian**. Confirm Operations access returns.

PASS: unknown role ⇒ zero modules, deny-all, no silent default.

---

## G. PHASE 6 — Teardown

1. As U6, **disable then delete** U1–U5 (AdminUsers → Delete) — removes Firestore `admins/{id}`, staff mirror, and Firebase Auth account.
2. Confirm Teacher role reverted (Phase 4) and U5 role restored then user deleted (Phase 5).
3. Confirm `schools/{id}.roles` is byte-identical to pre-test (no role definition was altered except the temporary Teacher edit, which was reverted).

---

## H. RESULT LEDGER (fill during execution)

49 cells = (5 roles × 9 checks) + 4 cross-cutting. Record PASS/FAIL + evidence ref per cell.

| Role | C1 login | C2 hydrate | C3 perms exact | C4 sidebar | C5 unauth hidden | C6 URL denied | C7 URL allowed | C8 propagate | C9 fail-closed |
|---|---|---|---|---|---|---|---|---|---|
| Principal | | | | | | | | (shared) | (shared) |
| Accountant | | | | | | | | | |
| Teacher | | | | | | | | ← run here | |
| HR Manager | | | | | | | | | |
| Librarian | | | | | | | | | ← run here |

Cross-cutting: **X1** bypass full-access ☐ · **X2** zero RTDB role reads ☐ · **X3** junk-module filtered ☐ · **X4** session isolation ☐

**Package verdict:** PRODUCTION-HARDENED only if all 49 cells PASS. Any FAIL → record role + check + route + expected-vs-actual; halt before SC-Step10.

---

*Execution planning only. No users created, no code changed, no commits. Awaiting review of this package, then we execute the runtime matrix.*
