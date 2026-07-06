# RBAC Acceptance-Test Checklist (Final 15 Cells: C1, C2, C8)

**Tenant:** SCH_D94FE8F7AD · **Base URL:** `http://localhost/ZenX/school/`
**Scope:** human-executed acceptance tests only. Logic cells (C3–C7, C9, X1–X4) already PASS.
**Roles:** Principal · Accountant · Teacher · HR Manager · Librarian
Mark each box: **[ ] PASS  [ ] FAIL**. On any FAIL, stop and record: role + cell + URL + expected vs actual.

---

## SETUP (do once, before per-role tests)

- [ ] S1. Log in as a **bypass admin** (Admin / School Super Admin) = **U6**.
- [ ] S2. AdminUsers → Create Admin → create the 5 test users below. **Record each ADM id + password** (shown once).

| User | Role | ADM id (fill in) | Password (fill in) |
|---|---|---|---|
| U1 | Principal | ________ | ________ |
| U2 | Accountant | ________ | ________ |
| U3 | Teacher | ________ | ________ |
| U4 | HR Manager | ________ | ________ |
| U5 | Librarian | ________ | ________ |

- [ ] S3. Use a **separate incognito/browser profile** per user (no shared session).
- [ ] S4. Known expected behaviors (do NOT flag as failures): `/homework`, `/device_management`, `/message_monitor`, `/red_flags` are denied to all 5 roles by design; HR Manager cannot open `/staff` (no SIS).

> How to read a result: **Authorized URL** must load the page (HTTP 200, no redirect). **Denied URL** must redirect to `…/admin_login`? No — denied-but-logged-in must redirect to **`…/admin/index`** (dashboard) with a "no access" message, or return 403 for AJAX. (A redirect to `admin_login` instead means you are not logged in — re-login.)

---

## ROLE 1 — PRINCIPAL (U1)

**Login account:** U1 ADM id + password

**Expected sidebar modules (visible):** School Setup (Configuration) · Students (SIS) · Attendance · Academic · LMS · Examinations · Results · Certificates · Communication · Events · Stories
**Must NOT appear:** Fees · Accounting · HR & Payroll · Operations/Library · Admin Users

**C1 — Login**
- [ ] Go to `admin_login`, enter U1 credentials → lands on dashboard, no error. **[ ] PASS [ ] FAIL**

**C2 — First-request hydration**
- [ ] On the dashboard (first page after login), the sidebar shows exactly the expected modules above (proves permissions hydrated on first request). **[ ] PASS [ ] FAIL**

**Authorized URLs — each must load (200):**
- [ ] `sis`  [ ] `attendance`  [ ] `examination`  [ ] `result`  [ ] `lms`  [ ] `certificates`  [ ] `academic`  [ ] `events`  [ ] `communication`  [ ] `stories`  [ ] `school_config`
- **[ ] PASS [ ] FAIL**

**Denied URLs — each must redirect to `admin/index` (or 403):**
- [ ] `fees/dashboard`  [ ] `accounting`  [ ] `hr`  [ ] `library`  [ ] `adminusers`
- **[ ] PASS [ ] FAIL**

---

## ROLE 2 — ACCOUNTANT (U2)

**Login account:** U2 ADM id + password

**Expected sidebar modules (visible):** Fees · Accounting
**Must NOT appear:** SIS · Attendance · Examinations · Results · LMS · Certificates · HR · Events · Communication · Operations · Academic · Configuration · Admin Users · Stories

**C1 — Login**
- [ ] U2 logs in → dashboard, no error. **[ ] PASS [ ] FAIL**

**C2 — First-request hydration**
- [ ] Sidebar on first page shows exactly Fees + Accounting. **[ ] PASS [ ] FAIL**

**Authorized URLs — must load (200):**
- [ ] `fees/dashboard`  [ ] `accounting`
- **[ ] PASS [ ] FAIL**

**Denied URLs — must redirect to `admin/index` (or 403):**
- [ ] `sis`  [ ] `attendance`  [ ] `examination`  [ ] `academic`  [ ] `hr`  [ ] `lms`  [ ] `communication`  [ ] `school_config`  [ ] `adminusers`  [ ] `library`  [ ] `stories`  [ ] `certificates`
- **[ ] PASS [ ] FAIL**

---

## ROLE 3 — TEACHER (U3)

**Login account:** U3 ADM id + password

**Expected sidebar modules (visible):** Attendance · Examinations · Results · LMS · Communication · Stories
**Must NOT appear:** SIS · Fees · Accounting · Certificates · HR · Events · Operations · Academic · Configuration · Admin Users

**C1 — Login**
- [ ] U3 logs in → dashboard, no error. **[ ] PASS [ ] FAIL**

**C2 — First-request hydration**
- [ ] Sidebar on first page shows exactly the 6 expected modules. **[ ] PASS [ ] FAIL**

**Authorized URLs — must load (200):**
- [ ] `attendance`  [ ] `examination`  [ ] `result`  [ ] `lms`  [ ] `stories`  [ ] `communication`
- **[ ] PASS [ ] FAIL**

**Denied URLs — must redirect to `admin/index` (or 403):**
- [ ] `sis`  [ ] `fees/dashboard`  [ ] `accounting`  [ ] `hr`  [ ] `school_config`  [ ] `academic`  [ ] `certificates`  [ ] `events`  [ ] `library`  [ ] `adminusers`
- **[ ] PASS [ ] FAIL**

---

## ROLE 4 — HR MANAGER (U4)

**Login account:** U4 ADM id + password

**Expected sidebar modules (visible):** HR & Payroll · Attendance
**Must NOT appear:** SIS · Fees · Accounting · Examinations · Results · LMS · Certificates · Events · Communication · Operations · Academic · Configuration · Admin Users · Stories

**C1 — Login**
- [ ] U4 logs in → dashboard, no error. **[ ] PASS [ ] FAIL**

**C2 — First-request hydration**
- [ ] Sidebar on first page shows exactly HR & Payroll + Attendance. **[ ] PASS [ ] FAIL**

**Authorized URLs — must load (200):**
- [ ] `hr`  [ ] `attendance`
- **[ ] PASS [ ] FAIL**

**Denied URLs — must redirect to `admin/index` (or 403):**
- [ ] `sis`  [ ] `staff/all_staff`  [ ] `fees/dashboard`  [ ] `accounting`  [ ] `examination`  [ ] `result`  [ ] `lms`  [ ] `academic`  [ ] `communication`  [ ] `school_config`  [ ] `adminusers`  [ ] `library`  [ ] `stories`  [ ] `certificates`  [ ] `events`
- **[ ] PASS [ ] FAIL**

---

## ROLE 5 — LIBRARIAN (U5)

**Login account:** U5 ADM id + password

**Expected sidebar modules (visible):** Operations (Library / Transport / Inventory / Assets)
**Must NOT appear:** everything else (SIS, Fees, Accounting, Attendance, Examinations, Results, LMS, HR, Certificates, Events, Communication, Academic, Configuration, Admin Users, Stories)

**C1 — Login**
- [ ] U5 logs in → dashboard, no error. **[ ] PASS [ ] FAIL**

**C2 — First-request hydration**
- [ ] Sidebar on first page shows only the Operations family. **[ ] PASS [ ] FAIL**

**Authorized URLs — must load (200):**
- [ ] `library`  [ ] `operations`  [ ] `transport`  [ ] `inventory`  [ ] `assets`
- **[ ] PASS [ ] FAIL**

**Denied URLs — must redirect to `admin/index` (or 403):**
- [ ] `sis`  [ ] `fees/dashboard`  [ ] `accounting`  [ ] `attendance`  [ ] `examination`  [ ] `result`  [ ] `lms`  [ ] `hr`  [ ] `academic`  [ ] `communication`  [ ] `school_config`  [ ] `adminusers`  [ ] `stories`  [ ] `certificates`  [ ] `events`
- **[ ] PASS [ ] FAIL**

---

## C8 — ROLE-EDIT PROPAGATION (run once, on Teacher U3)

- [ ] 8.1 As **U6 (bypass)**: AdminUsers → Roles → edit **Teacher** → add module **Certificates** → Save.
- [ ] 8.2 **Re-login** as U3 → sidebar now shows **Certificates**, and `certificates` loads (200). **[ ] PASS [ ] FAIL** (immediate on re-login)
- [ ] 8.3 In a U3 session that was **already open before** the edit: wait ≤5 minutes, then navigate/refresh → **Certificates** now appears. Record latency: ____ sec. **[ ] PASS [ ] FAIL** (≤300 s)
- [ ] 8.4 **REVERT** as U6: edit Teacher → remove **Certificates** → Save. Re-login as U3 → Certificates gone again. **[ ] PASS [ ] FAIL**

---

## TEARDOWN

- [ ] T1. Confirm Teacher role reverted (Certificates removed).
- [ ] T2. AdminUsers → delete U1–U5.
- [ ] T3. Confirm `schools/{id}.roles` unchanged vs pre-test (only the reverted Teacher edit occurred).

---

## RESULTS SUMMARY (15 acceptance cells)

| Role | C1 Login | C2 Hydration | C8 Propagation |
|---|---|---|---|
| Principal | ☐ PASS ☐ FAIL | ☐ PASS ☐ FAIL | (shared below) |
| Accountant | ☐ PASS ☐ FAIL | ☐ PASS ☐ FAIL | (shared below) |
| Teacher | ☐ PASS ☐ FAIL | ☐ PASS ☐ FAIL | ☐ PASS ☐ FAIL |
| HR Manager | ☐ PASS ☐ FAIL | ☐ PASS ☐ FAIL | (shared below) |
| Librarian | ☐ PASS ☐ FAIL | ☐ PASS ☐ FAIL | (shared below) |

> C8 propagation is validated once (on Teacher) as the representative role; the mechanism is identical for all roles. If per-role C8 evidence is required, repeat steps 8.1–8.4 substituting each role.

**Authorized/Denied URL blocks** above also feed the already-automated C6/C7 — re-confirm them live as part of C1/C2 execution.

**Tester:** ____________  **Date:** ____________
**Overall acceptance:** ☐ ALL PASS → proceed to production-hardened review → SC-Step10  ☐ FAIL (attach detail)

---

*Acceptance checklist only. No code changes, no commits, no analysis expansion.*
