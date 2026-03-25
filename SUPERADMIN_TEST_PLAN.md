# GraderIQ Super Admin — Comprehensive Testing Plan
**Stack:** CodeIgniter 3 · Firebase Realtime Database · AdminLTE + jQuery AJAX
**Scope:** Super Admin Auth, School Onboarding, Plan Management, Dashboard, Monitor, Backups
**Date:** 2026-03-06

---

## Table of Contents
1. [Test Environment Setup](#1-test-environment-setup)
2. [Module 1 — Super Admin Authentication](#2-module-1--super-admin-authentication)
3. [Module 2 — School Onboarding](#3-module-2--school-onboarding)
4. [Module 3 — Subscription Plan Management](#4-module-3--subscription-plan-management)
5. [Module 4 — Global SaaS Dashboard](#5-module-4--global-saas-dashboard)
6. [Module 5 — System Monitoring](#6-module-5--system-monitoring)
7. [Module 6 — Backup Management](#7-module-6--backup-management)
8. [Security Testing Checks](#8-security-testing-checks)
9. [Firebase Data Validation Tests](#9-firebase-data-validation-tests)
10. [API Endpoint Tests](#10-api-endpoint-tests)
11. [UI Interaction Tests](#11-ui-interaction-tests)
12. [Performance Testing Suggestions](#12-performance-testing-suggestions)
13. [Architecture Weaknesses at 500+ Schools](#13-architecture-weaknesses-at-500-schools)

---

## 1. Test Environment Setup

### Prerequisites
| Item | Requirement |
|------|-------------|
| PHP | 7.4+ (test on 8.1 also) |
| XAMPP | Apache 2.4+ |
| Firebase | Test project separate from production |
| Browser | Chrome 120+, Firefox 122+, Edge 120+ |
| Tools | Postman, browser DevTools, Xdebug |

### Test Data Setup
```
Firebase test paths:
  Users/Schools/TestSchool_Alpha/  → active school
  Users/Schools/TestSchool_Beta/   → inactive school
  Users/Schools/TestSchool_Gamma/  → subscription expired
  System/Plans/plan_basic          → seeded Basic plan
  System/Plans/plan_standard       → seeded Standard plan
  System/Plans/plan_premium        → seeded Premium plan
  Users/Admin/TST001/{adminId}     → Role="Super Admin"
```

### Test Accounts
| Role | Username | School Code |
|------|----------|-------------|
| Super Admin (developer) | devadmin | Our Panel |
| Super Admin (school SA) | schoolsa | TST001 |
| Regular Admin (should fail SA login) | regularadmin | TST001 |

---

## 2. Module 1 — Super Admin Authentication

### SA-AUTH-001
| Field | Value |
|-------|-------|
| **Module** | Super Admin Auth |
| **Test ID** | SA-AUTH-001 |
| **Description** | Valid Super Admin login with correct credentials |
| **Steps** | 1. Navigate to `/superadmin/login` · 2. Enter valid SA username + password · 3. Click Login |
| **Expected** | Redirect to `/superadmin/dashboard`; session contains `sa_id`, `sa_name`, `sa_role` |
| **Failure Cases** | Session not set; redirect to wrong URL; 500 error |

### SA-AUTH-002
| Field | Value |
|-------|-------|
| **Module** | Super Admin Auth |
| **Test ID** | SA-AUTH-002 |
| **Description** | Login with wrong password |
| **Steps** | 1. Navigate to `/superadmin/login` · 2. Enter valid username, wrong password · 3. Submit |
| **Expected** | Stay on login page; error message shown; fail counter incremented in `RateLimit/SA/{ip}` |
| **Failure Cases** | Login succeeds; no error shown; counter not incremented |

### SA-AUTH-003
| Field | Value |
|-------|-------|
| **Module** | Super Admin Auth |
| **Test ID** | SA-AUTH-003 |
| **Description** | Rate limiting — 10 failed attempts triggers lockout |
| **Steps** | 1. Submit wrong password 10 times from the same IP · 2. Attempt an 11th login |
| **Expected** | 11th attempt blocked with lockout message; lockout persists for 30 minutes |
| **Failure Cases** | Lockout not triggered; lockout applied too early; lockout persists indefinitely |

### SA-AUTH-004
| Field | Value |
|-------|-------|
| **Module** | Super Admin Auth |
| **Test ID** | SA-AUTH-004 |
| **Description** | Regular admin (non-SA role) cannot login to SA panel |
| **Steps** | 1. Enter credentials of a school admin with Role ≠ "Super Admin" |
| **Expected** | Login rejected; error message; no session created |
| **Failure Cases** | Non-SA admin gains SA access |

### SA-AUTH-005
| Field | Value |
|-------|-------|
| **Module** | Super Admin Auth |
| **Test ID** | SA-AUTH-005 |
| **Description** | Session expiry — accessing SA page after session destroyed |
| **Steps** | 1. Login successfully · 2. Manually delete session cookie · 3. Try to access `/superadmin/dashboard` |
| **Expected** | Redirect to `/superadmin/login` |
| **Failure Cases** | SA panel accessible without session |

### SA-AUTH-006
| Field | Value |
|-------|-------|
| **Module** | Super Admin Auth |
| **Test ID** | SA-AUTH-006 |
| **Description** | Logout clears session and Firebase rate-limit keys |
| **Steps** | 1. Login · 2. Navigate to `/superadmin/logout` |
| **Expected** | Session destroyed; redirect to `/superadmin/login`; cannot navigate back using browser Back button |
| **Failure Cases** | Session persists; back-navigation restores SA access |

### SA-AUTH-007
| Field | Value |
|-------|-------|
| **Module** | Super Admin Auth |
| **Test ID** | SA-AUTH-007 |
| **Description** | Plain-text password auto-upgrade to bcrypt on first login |
| **Steps** | 1. Set a plain-text password in Firebase · 2. Login with that plain-text password |
| **Expected** | Login succeeds; Firebase password field now contains bcrypt hash |
| **Failure Cases** | Password not upgraded; login fails for plain-text passwords |

### SA-AUTH-008
| Field | Value |
|-------|-------|
| **Module** | Super Admin Auth |
| **Test ID** | SA-AUTH-008 |
| **Description** | Direct URL access to all SA routes without session |
| **Steps** | 1. Without logging in, attempt to GET `/superadmin/dashboard`, `/superadmin/schools`, `/superadmin/plans`, `/superadmin/monitor`, `/superadmin/backups` |
| **Expected** | All routes redirect to `/superadmin/login` |
| **Failure Cases** | Any route renders content without a valid SA session |

---

## 3. Module 2 — School Onboarding

### ONB-001
| Field | Value |
|-------|-------|
| **Module** | School Onboarding |
| **Test ID** | ONB-001 |
| **Description** | Onboard a new school with all required fields |
| **Steps** | 1. Go to `/superadmin/schools/create` · 2. Fill all required fields (name, code, admin email, plan, session year) · 3. Submit |
| **Expected** | School created in `Users/Schools/{name}` and `System/Schools/{name}`; admin created in `Users/Admin/{code}/{adminId}`; school code registered in `School_ids/{code}`; redirect to school detail page |
| **Failure Cases** | Partial data saved; no admin created; no redirect; Firebase path missing |

### ONB-002
| Field | Value |
|-------|-------|
| **Module** | School Onboarding |
| **Test ID** | ONB-002 |
| **Description** | Duplicate school name rejected |
| **Steps** | 1. Onboard "School Alpha" · 2. Try to onboard another school named "School Alpha" |
| **Expected** | `check_availability` returns `taken=true`; form blocks submission with inline error |
| **Failure Cases** | Duplicate school created; no validation message |

### ONB-003
| Field | Value |
|-------|-------|
| **Module** | School Onboarding |
| **Test ID** | ONB-003 |
| **Description** | Duplicate school code rejected |
| **Steps** | 1. Onboard school with code "TST001" · 2. Try to onboard another school with same code "TST001" |
| **Expected** | `check_availability` returns code conflict; form blocks submission |
| **Failure Cases** | Two schools share same code |

### ONB-004
| Field | Value |
|-------|-------|
| **Module** | School Onboarding |
| **Test ID** | ONB-004 |
| **Description** | Toggle school status: Active → Inactive → Suspended |
| **Steps** | 1. Open school detail page · 2. Set status to "Inactive" via toggle · 3. Verify · 4. Set to "Suspended" |
| **Expected** | `Users/Schools/{name}/status` and `System/Schools/{name}/status` both update correctly for each toggle |
| **Failure Cases** | Only one Firebase path updated; no confirmation; wrong status saved |

### ONB-005
| Field | Value |
|-------|-------|
| **Module** | School Onboarding |
| **Test ID** | ONB-005 |
| **Description** | Update school profile (name, address, contact, logo URL) |
| **Steps** | 1. Open school detail · 2. Edit profile fields · 3. Save |
| **Expected** | Changes persisted in `System/Schools/{name}`; page reflects new values |
| **Failure Cases** | Silent save failure; partial update; old values shown after refresh |

### ONB-006
| Field | Value |
|-------|-------|
| **Module** | School Onboarding |
| **Test ID** | ONB-006 |
| **Description** | Assign a different subscription plan to an existing school |
| **Steps** | 1. Open school detail · 2. Click Assign Plan · 3. Select "Premium" plan, set end date 1 year ahead · 4. Save |
| **Expected** | `Users/Schools/{name}/subscription` updated; plan name, end date, status='active' reflected immediately |
| **Failure Cases** | Old plan persists; end date not saved; status not updated |

### ONB-007
| Field | Value |
|-------|-------|
| **Module** | School Onboarding |
| **Test ID** | ONB-007 |
| **Description** | Refresh school stats (student/staff count per session) |
| **Steps** | 1. Open school detail · 2. Click "Refresh Stats" |
| **Expected** | AJAX returns current student/staff counts by scanning `Schools/{name}/{session}/Class */Section */Students/List` |
| **Failure Cases** | Counts not updated; error if school has 0 students; wrong count displayed |

### ONB-008
| Field | Value |
|-------|-------|
| **Module** | School Onboarding |
| **Test ID** | ONB-008 |
| **Description** | Onboard school with special characters in name |
| **Steps** | 1. Try school name "St. Mary's & Co." |
| **Expected** | Either accepted with sanitised Firebase key, or rejected with clear validation error |
| **Failure Cases** | Firebase write fails silently; special chars break routing |

### ONB-009
| Field | Value |
|-------|-------|
| **Module** | School Onboarding |
| **Test ID** | ONB-009 |
| **Description** | School list pagination and search filter |
| **Steps** | 1. Create 20+ schools · 2. Go to `/superadmin/schools` · 3. Search for partial name |
| **Expected** | DataTables renders all entries; search filters rows client-side correctly |
| **Failure Cases** | Search missing entries; table not initialised; DataTables JS error |

---

## 4. Module 3 — Subscription Plan Management

### PLN-001
| Field | Value |
|-------|-------|
| **Module** | Plan Management |
| **Test ID** | PLN-001 |
| **Description** | Create a new plan with all 15 module toggles |
| **Steps** | 1. Go to `/superadmin/plans` · 2. Click New Plan · 3. Fill name, price, duration, toggle all 15 modules · 4. Save |
| **Expected** | Plan saved to `System/Plans/{id}` with `modules` object reflecting all 15 toggles |
| **Failure Cases** | Module array missing; plan saved without modules; ID collision |

### PLN-002
| Field | Value |
|-------|-------|
| **Module** | Plan Management |
| **Test ID** | PLN-002 |
| **Description** | Edit an existing plan |
| **Steps** | 1. Click edit on "Basic" plan · 2. Change price and disable 2 modules · 3. Save |
| **Expected** | `System/Plans/plan_basic` updated; existing school subscriptions remain unchanged (no cascade) |
| **Failure Cases** | Plan not saved; assigned schools lose their subscription data |

### PLN-003
| Field | Value |
|-------|-------|
| **Module** | Plan Management |
| **Test ID** | PLN-003 |
| **Description** | Delete a plan that has active schools assigned — should be blocked |
| **Steps** | 1. Assign "Standard" plan to at least one school · 2. Try to delete "Standard" plan |
| **Expected** | Deletion blocked with error message: "X schools are on this plan" |
| **Failure Cases** | Plan deleted while schools still reference it; orphaned subscription data |

### PLN-004
| Field | Value |
|-------|-------|
| **Module** | Plan Management |
| **Test ID** | PLN-004 |
| **Description** | Delete a plan with no assigned schools |
| **Steps** | 1. Create a temporary plan with no schools · 2. Delete it |
| **Expected** | `System/Plans/{id}` node removed; plan no longer appears in dropdown |
| **Failure Cases** | Plan persists in Firebase; still appears in dropdowns |

### PLN-005
| Field | Value |
|-------|-------|
| **Module** | Plan Management |
| **Test ID** | PLN-005 |
| **Description** | Seed default plans when none exist |
| **Steps** | 1. Delete all plans · 2. Call `POST /superadmin/plans/seed_defaults` |
| **Expected** | Basic / Standard / Premium plans created with sensible defaults |
| **Failure Cases** | Duplicate plans created if run twice; plans created with wrong module config |

### PLN-006
| Field | Value |
|-------|-------|
| **Module** | Plan Management |
| **Test ID** | PLN-006 |
| **Description** | Subscription expiry tracking — expired schools listed correctly |
| **Steps** | 1. Set school end date to yesterday · 2. Open `/superadmin/plans/subscriptions` |
| **Expected** | School appears in "Expired" bucket; not in "Active" |
| **Failure Cases** | Expired school still listed as active; wrong bucket shown |

### PLN-007
| Field | Value |
|-------|-------|
| **Module** | Plan Management |
| **Test ID** | PLN-007 |
| **Description** | Grace period calculation — 15-day buffer |
| **Steps** | 1. Set school end date to 7 days ago |
| **Expected** | School appears in "Grace Period" bucket (expired but within 15 days); NOT suspended yet |
| **Failure Cases** | School immediately suspended; not shown in grace bucket |

### PLN-008
| Field | Value |
|-------|-------|
| **Module** | Plan Management |
| **Test ID** | PLN-008 |
| **Description** | Auto-suspend schools past grace period via `expire_check` |
| **Steps** | 1. Set school end date to 20 days ago · 2. Call `POST /superadmin/plans/expire_check` |
| **Expected** | School status changes to "Suspended" in Firebase; count of suspended schools returned |
| **Failure Cases** | School not suspended; active schools accidentally suspended |

### PLN-009
| Field | Value |
|-------|-------|
| **Module** | Plan Management |
| **Test ID** | PLN-009 |
| **Description** | Add, edit, and delete a payment record |
| **Steps** | 1. Add payment for TestSchool_Alpha: ₹5000, cash, today · 2. Edit amount to ₹6000 · 3. Delete the record |
| **Expected** | Each step reflected in `System/Payments/{id}`; table updates without page reload |
| **Failure Cases** | Payment not saved; edit overwrites wrong record; delete fails silently |

### PLN-010
| Field | Value |
|-------|-------|
| **Module** | Plan Management |
| **Test ID** | PLN-010 |
| **Description** | Plan fetch endpoint returns correct data for modal |
| **Steps** | 1. Call `POST /superadmin/plans/fetch` with a valid plan_id |
| **Expected** | JSON with all plan fields including modules object |
| **Failure Cases** | Missing modules key; wrong plan returned; 500 error |

---

## 5. Module 4 — Global SaaS Dashboard

### DASH-001
| Field | Value |
|-------|-------|
| **Module** | Dashboard |
| **Test ID** | DASH-001 |
| **Description** | Dashboard loads with correct KPI values |
| **Steps** | 1. Login · 2. Navigate to `/superadmin/dashboard` |
| **Expected** | 6 KPI cards display: Total Schools, Active Schools, Total Students, Total Staff, Total Revenue, New Schools (30d). Values match Firebase data |
| **Failure Cases** | Cards show 0 or N/A incorrectly; PHP error in page |

### DASH-002
| Field | Value |
|-------|-------|
| **Module** | Dashboard |
| **Test ID** | DASH-002 |
| **Description** | Refresh Stats button recomputes and updates all KPIs |
| **Steps** | 1. Onboard a new school · 2. Click "Refresh Stats" on dashboard |
| **Expected** | Total Schools count increases by 1; `System/Stats/Summary` updated in Firebase |
| **Failure Cases** | Count not updated; button spinner stuck; old values remain |

### DASH-003
| Field | Value |
|-------|-------|
| **Module** | Dashboard |
| **Test ID** | DASH-003 |
| **Description** | Charts render correctly via AJAX call to `dashboard_charts` |
| **Steps** | 1. Load dashboard · 2. Open Network tab, find `/superadmin/dashboard/charts` call |
| **Expected** | Response contains: `status_counts`, `plan_dist`, `revenue_trend`, `top_schools`, `recent_regs` arrays. All 4 Chart.js canvases render |
| **Failure Cases** | AJAX fails; empty chart data; Chart.js error in console |

### DASH-004
| Field | Value |
|-------|-------|
| **Module** | Dashboard |
| **Test ID** | DASH-004 |
| **Description** | Expiry alerts show schools expiring within 15 days |
| **Steps** | 1. Set TestSchool_Alpha end date to today + 10 days · 2. Load dashboard |
| **Expected** | Expiry alert panel shows TestSchool_Alpha with days remaining |
| **Failure Cases** | School not shown; wrong day count; alert shown for non-expiring schools |

### DASH-005
| Field | Value |
|-------|-------|
| **Module** | Dashboard |
| **Test ID** | DASH-005 |
| **Description** | Theme switch (night/day) re-renders Chart.js axes correctly |
| **Steps** | 1. Load dashboard · 2. Toggle theme · 3. Inspect chart axis label colors |
| **Expected** | Axis text and grid colors update to match new theme (MutationObserver fires) |
| **Failure Cases** | Charts keep old colors after theme toggle; MutationObserver not firing |

### DASH-006
| Field | Value |
|-------|-------|
| **Module** | Dashboard |
| **Test ID** | DASH-006 |
| **Description** | Recent registrations table loads in dashboard |
| **Steps** | 1. Load dashboard and wait for AJAX |
| **Expected** | Table populated with last N school registrations; columns: School Name, Date, Plan, Status |
| **Failure Cases** | Table empty despite data; wrong column data; JS error |

### DASH-007
| Field | Value |
|-------|-------|
| **Module** | Dashboard |
| **Test ID** | DASH-007 |
| **Description** | Dashboard with zero schools (fresh install) |
| **Steps** | 1. Clear all schools from Firebase · 2. Load dashboard |
| **Expected** | All KPIs show 0; charts render empty state gracefully (no JS errors) |
| **Failure Cases** | Division-by-zero PHP error; Chart.js crashes on empty data |

---

## 6. Module 5 — System Monitoring

### MON-001
| Field | Value |
|-------|-------|
| **Module** | System Monitoring |
| **Test ID** | MON-001 |
| **Description** | Overview tab loads with correct KPI cards |
| **Steps** | 1. Navigate to `/superadmin/monitor` |
| **Expected** | 6 KPIs: System Health, SA Actions (today), School Logins (today), API Calls (today), Errors (today), Firebase status. Values sourced from `System/Logs/*/today` |
| **Failure Cases** | Cards show null; wrong date used; Firebase error |

### MON-002
| Field | Value |
|-------|-------|
| **Module** | System Monitoring |
| **Test ID** | MON-002 |
| **Description** | Login Logs tab — filter by date and type |
| **Steps** | 1. Open Login Logs tab · 2. Change date to yesterday · 3. Switch log type between SA Activity, School Admin Logins, SA Logins |
| **Expected** | AJAX fires for each type change; correct logs returned for selected date; live search filters rows |
| **Failure Cases** | No data for yesterday even if it exists; search filter not working; AJAX not fired on date change |

### MON-003
| Field | Value |
|-------|-------|
| **Module** | System Monitoring |
| **Test ID** | MON-003 |
| **Description** | Error Logs tab — severity filter pills |
| **Steps** | 1. Open Error Logs tab · 2. Filter by "Error" only · 3. Filter by "Warning" |
| **Expected** | Table rows update to match severity; count badges update |
| **Failure Cases** | All rows shown regardless of filter; count badge not updated |

### MON-004
| Field | Value |
|-------|-------|
| **Module** | System Monitoring |
| **Test ID** | MON-004 |
| **Description** | System Health — server resource data |
| **Steps** | 1. Click "Check System Health" button |
| **Expected** | AJAX returns memory %, disk %, load avg (if Linux), opcache status, Firebase ping time; gauges animate to correct values |
| **Failure Cases** | 500 error; null values for disk/memory; Firebase ping timeout causing hang |

### MON-005
| Field | Value |
|-------|-------|
| **Module** | System Monitoring |
| **Test ID** | MON-005 |
| **Description** | Auto-refresh toggle in Server Health tab |
| **Steps** | 1. Enable auto-refresh toggle · 2. Wait 30 seconds |
| **Expected** | System health re-fetched every 30s; gauge bars update; toggle can be turned off |
| **Failure Cases** | Interval not cleared when toggled off; multiple parallel intervals running |

### MON-006
| Field | Value |
|-------|-------|
| **Module** | System Monitoring |
| **Test ID** | MON-006 |
| **Description** | Clear logs — removes log data for specified type and date |
| **Steps** | 1. Generate some activity logs · 2. Use Clear Logs (type=Activity, date=today) |
| **Expected** | `System/Logs/Activity/{today}` node deleted; log count drops to 0 |
| **Failure Cases** | Wrong log type cleared; node not deleted; success message shown even on failure |

### MON-007
| Field | Value |
|-------|-------|
| **Module** | System Monitoring |
| **Test ID** | MON-007 |
| **Description** | Firebase usage chart — 7-day aggregated data |
| **Steps** | 1. Open Overview tab · 2. Inspect `firebase_usage` AJAX response |
| **Expected** | 7 data points; each with activity/school_logins/errors/api_calls counts; stacked bar chart renders |
| **Failure Cases** | Less than 7 days; all zeros even with real data; chart renders without legend |

### MON-008
| Field | Value |
|-------|-------|
| **Module** | System Monitoring |
| **Test ID** | MON-008 |
| **Description** | API Usage tab — log_api_call records correctly |
| **Steps** | 1. Make AJAX call that triggers `log_api_call` · 2. Check API Usage tab |
| **Expected** | New entry in `System/Logs/ApiUsage/{today}` with correct endpoint, method, status, duration |
| **Failure Cases** | Log not written; wrong method or status recorded; timestamp off |

---

## 7. Module 6 — Backup Management

### BCK-001
| Field | Value |
|-------|-------|
| **Module** | Backup Management |
| **Test ID** | BCK-001 |
| **Description** | Manual Firebase-only backup for a school |
| **Steps** | 1. Go to `/superadmin/backups` → Manual Backup tab · 2. Select TestSchool_Alpha · 3. Choose type=Firebase · 4. Click Create Backup |
| **Expected** | JSON file created in `application/backups/TestSchool_Alpha/BKP_*.json`; metadata saved in `System/Backups/TestSchool_Alpha/{backup_id}`; success toast shown |
| **Failure Cases** | File not created; Firebase metadata not written; 500 error |

### BCK-002
| Field | Value |
|-------|-------|
| **Module** | Backup Management |
| **Test ID** | BCK-002 |
| **Description** | Manual Full backup (ZIP) for a school |
| **Steps** | 1. Select TestSchool_Alpha · 2. Choose type=Full · 3. Click Create Backup |
| **Expected** | If ZipArchive available: .zip created containing `firebase_data.json` + uploaded files (within 50MB cap). If not available: falls back to .json. Metadata shows correct type. |
| **Failure Cases** | Corrupt ZIP; ZIP with missing firebase_data.json; no fallback when ZipArchive absent |

### BCK-003
| Field | Value |
|-------|-------|
| **Module** | Backup Management |
| **Test ID** | BCK-003 |
| **Description** | Download a backup file |
| **Steps** | 1. Create a backup · 2. Click Download on the entry |
| **Expected** | Browser downloads file with correct Content-Type (`application/json` or `application/zip`); file is not corrupted |
| **Failure Cases** | 404 error; wrong Content-Type; truncated file download |

### BCK-004
| Field | Value |
|-------|-------|
| **Module** | Backup Management |
| **Test ID** | BCK-004 |
| **Description** | Restore from existing backup |
| **Steps** | 1. Modify a school's Firebase data slightly · 2. Restore a previous backup · 3. Enter "RESTORE" in confirmation field · 4. Confirm |
| **Expected** | Safety backup automatically created before restore; `Schools/{firebase_key}` overwritten with backup data; success message |
| **Failure Cases** | No safety backup created; restore fails silently; wrong school's data restored |

### BCK-005
| Field | Value |
|-------|-------|
| **Module** | Backup Management |
| **Test ID** | BCK-005 |
| **Description** | Restore rejected without "RESTORE" confirmation token |
| **Steps** | 1. Try to restore with confirmation field = "restore" (lowercase) or empty |
| **Expected** | Request rejected; error: "Type RESTORE to confirm" |
| **Failure Cases** | Case-insensitive match allows wrong input; restore proceeds without confirmation |

### BCK-006
| Field | Value |
|-------|-------|
| **Module** | Backup Management |
| **Test ID** | BCK-006 |
| **Description** | Upload-and-restore from a valid JSON backup file |
| **Steps** | 1. Download an existing backup · 2. Go to Restore → Upload & Restore · 3. Drag-drop the file · 4. Verify preview (school name, firebase_key, backed_up_at) · 5. Enter "RESTORE" · 6. Upload |
| **Expected** | File parsed; preview shown; `Schools/{firebase_key}` overwritten; safety backup created |
| **Failure Cases** | Preview not shown; wrong school data shown; restore fails on valid file |

### BCK-007
| Field | Value |
|-------|-------|
| **Module** | Backup Management |
| **Test ID** | BCK-007 |
| **Description** | Upload-restore rejects invalid/malformed JSON |
| **Steps** | 1. Upload a .json file that is not a valid GraderIQ backup (missing `Schools`/`firebase_key`) |
| **Expected** | Error: "Invalid backup format" or similar; no Firebase write occurs |
| **Failure Cases** | Corrupt backup data written to Firebase; silent failure |

### BCK-008
| Field | Value |
|-------|-------|
| **Module** | Backup Management |
| **Test ID** | BCK-008 |
| **Description** | Upload-restore rejects non-JSON file types |
| **Steps** | 1. Try to upload a .zip or .txt file via upload restore |
| **Expected** | File rejected at PHP validation level; error shown |
| **Failure Cases** | Wrong file type accepted; PHP error reading non-JSON |

### BCK-009
| Field | Value |
|-------|-------|
| **Module** | Backup Management |
| **Test ID** | BCK-009 |
| **Description** | Save backup schedule and verify cron key generated |
| **Steps** | 1. Open Schedule tab · 2. Enable schedule · 3. Set frequency=daily, time=02:00, retention=7 · 4. Save |
| **Expected** | `System/BackupSchedule` written with all fields; `cron_key` present (UUID-like string); cron command shown in UI |
| **Failure Cases** | No cron_key generated; schedule not saved; duplicate cron_key generated on re-save |

### BCK-010
| Field | Value |
|-------|-------|
| **Module** | Backup Management |
| **Test ID** | BCK-010 |
| **Description** | Cron endpoint validates correct key |
| **Steps** | 1. Save schedule to get cron_key · 2. GET `/backup_cron/{correct_cron_key}` |
| **Expected** | All schools backed up; JSON response with succeeded/failed counts; `last_run` updated in Firebase |
| **Failure Cases** | Wrong schools backed up; `last_run` not updated; cron runs even when disabled |

### BCK-011
| Field | Value |
|-------|-------|
| **Module** | Backup Management |
| **Test ID** | BCK-011 |
| **Description** | Cron endpoint rejects wrong key |
| **Steps** | 1. GET `/backup_cron/wrongkey123` |
| **Expected** | HTTP 403 JSON response: `{"status":"error","message":"Invalid or expired cron key."}` |
| **Failure Cases** | Backup runs with wrong key; 500 error instead of 403 |

### BCK-012
| Field | Value |
|-------|-------|
| **Module** | Backup Management |
| **Test ID** | BCK-012 |
| **Description** | Retention policy deletes oldest backups |
| **Steps** | 1. Set retention=3 · 2. Create 5 backups for the same school |
| **Expected** | After 5th backup, oldest 2 auto-deleted (both file and Firebase metadata); SAFETY_ backups preserved |
| **Failure Cases** | All 5 kept; SAFETY backups deleted; wrong backups deleted |

### BCK-013
| Field | Value |
|-------|-------|
| **Module** | Backup Management |
| **Test ID** | BCK-013 |
| **Description** | Delete a backup manually |
| **Steps** | 1. Create a backup · 2. Click Delete in backup list |
| **Expected** | File removed from disk; `System/Backups/{uid}/{id}` deleted from Firebase; row removed from table |
| **Failure Cases** | File remains on disk; Firebase metadata not deleted; row stays in table |

### BCK-014
| Field | Value |
|-------|-------|
| **Module** | Backup Management |
| **Test ID** | BCK-014 |
| **Description** | Run All Schools Now from Schedule tab |
| **Steps** | 1. Click "Run Now" button in Schedule tab |
| **Expected** | All schools in `Users/Schools` get backed up; results table shows per-school status; `last_run` updated |
| **Failure Cases** | Only some schools backed up; results table not populated; timeout with no feedback |

### BCK-015
| Field | Value |
|-------|-------|
| **Module** | Backup Management |
| **Test ID** | BCK-015 |
| **Description** | Double-run prevention for cron within same hour |
| **Steps** | 1. Run cron successfully · 2. Run cron again within the same hour |
| **Expected** | Second run returns `{"status":"skipped","message":"Already ran this hour."}` |
| **Failure Cases** | Duplicate backups created; `last_run` check ignored |

---

## 8. Security Testing Checks

### SEC-001 — CSRF Protection on All POST Endpoints
| Check | Detail |
|-------|--------|
| **What to test** | POST to all SA endpoints without CSRF token |
| **Method** | Use Postman without sending `ci_csrf_token` header; also try replaying a captured token |
| **Expected** | 403 or redirect to login; no data changed |
| **Applies to** | All `superadmin/*` POST routes |

### SEC-002 — IDOR (Insecure Direct Object Reference)
| Check | Detail |
|-------|--------|
| **What to test** | School admin logged in as their own SA can they access another school's backup/data |
| **Method** | Login as SchoolA's super admin; try to call `/superadmin/backups/create_backup` with `school_uid=SchoolB` |
| **Expected** | MY_Superadmin_Controller checks `sa_role`; school SA sees only their own data |
| **Risk** | If `school_uid` param is trusted without role check, cross-school data access possible |

### SEC-003 — Path Traversal in Backup Download
| Check | Detail |
|-------|--------|
| **What to test** | GET `/superadmin/backups/download/../../config/database/config` |
| **Method** | Manipulate the `uid` and `id` URL segments with `../` sequences |
| **Expected** | Filename validated with `preg_match('/^[A-Za-z0-9_\-\.]+$/', $filename)` — traversal blocked |
| **Risk** | Without the regex check, arbitrary server files could be downloaded |

### SEC-004 — XSS in School Name / Admin Name
| Check | Detail |
|-------|--------|
| **What to test** | Onboard school with name `<script>alert(1)</script>` |
| **Expected** | Name stored as-is in Firebase but always rendered via `htmlspecialchars()` in views |
| **Risk** | If any view uses raw `echo $school_name` without escaping, stored XSS |

### SEC-005 — SQL Injection (Limited Exposure)
| Check | Detail |
|-------|--------|
| **What to test** | Inputs to any MySQL-backed endpoints |
| **Method** | Inject `' OR 1=1 --` in any input fields |
| **Expected** | CI's Query Builder auto-escapes; no SQL error or unexpected data returned |
| **Risk** | Low — most data is Firebase; confirm any `$this->db->query()` raw calls are absent |

### SEC-006 — Cron Key Brute Force
| Check | Detail |
|-------|--------|
| **What to test** | Repeated calls to `/backup_cron/{random}` |
| **Expected** | No rate limiting currently on `Backup_cron.php` (it extends `CI_Controller`, not SA) |
| **Risk** | **HIGH** — cron endpoint has no IP rate limit. An attacker could enumerate cron keys. Recommendation: add a 5-req/min rate limit |

### SEC-007 — Session Fixation
| Check | Detail |
|-------|--------|
| **What to test** | Obtain session ID before login; check if same session ID is used after successful login |
| **Expected** | `session_regenerate_id(true)` called on login; session ID changes post-auth |
| **Risk** | If session ID not rotated, fixation attack possible |

### SEC-008 — Firebase Rules
| Check | Detail |
|-------|--------|
| **What to test** | Access Firebase REST API directly without server-side auth token |
| **Expected** | Firebase Security Rules block direct unauthenticated reads to `System/` and `Schools/` paths |
| **Risk** | If Firebase rules are `.read: true`, all school data is publicly readable via REST |

### SEC-009 — Backup File Exposure
| Check | Detail |
|-------|--------|
| **What to test** | Navigate directly to `http://localhost/school/application/backups/SchoolName/BKP_*.json` |
| **Expected** | Apache/Nginx blocks access to `application/` directory (`.htaccess` deny rule) |
| **Risk** | If no `.htaccess` in `application/backups/`, raw JSON with all school data is publicly accessible |

### SEC-010 — RESTORE Token Strength
| Check | Detail |
|-------|--------|
| **What to test** | Is the "RESTORE" confirmation token truly protective? |
| **Note** | The token is a hardcoded string "RESTORE" — it prevents accidental clicks but not a determined attacker who has SA access. This is acceptable for the current threat model but worth documenting. |

---

## 9. Firebase Data Validation Tests

### FDB-001
| Check | Firebase Path | Test |
|-------|--------------|------|
| School record completeness | `Users/Schools/{name}` | Must contain: `name`, `school_id`, `status`, `subscription`, `created_at` |

### FDB-002
| Check | Firebase Path | Test |
|-------|--------------|------|
| Admin record completeness | `Users/Admin/{code}/{adminId}` | Must contain: `name`, `email`, `Role="Super Admin"`, `password` (bcrypt), `school_name` |

### FDB-003
| Check | Firebase Path | Test |
|-------|--------------|------|
| School ID uniqueness | `School_ids/{code}` | Value must be the school name; no two codes pointing to same school or null |

### FDB-004
| Check | Firebase Path | Test |
|-------|--------------|------|
| Subscription structure | `Users/Schools/{name}/subscription` | Must contain: `plan_id`, `plan_name`, `status` (active/grace/expired/suspended), `end_date` (YYYY-MM-DD) |

### FDB-005
| Check | Firebase Path | Test |
|-------|--------------|------|
| Plan record structure | `System/Plans/{id}` | Must contain: `name`, `price`, `duration`, `modules` object with all 15 module keys |

### FDB-006
| Check | Firebase Path | Test |
|-------|--------------|------|
| Backup metadata completeness | `System/Backups/{safe_uid}/{backup_id}` | Must contain: `backup_id`, `school_name`, `filename`, `backup_type`, `size_bytes`, `status`, `created_at`, `created_by` |

### FDB-007
| Check | Firebase Path | Test |
|-------|--------------|------|
| Schedule config | `System/BackupSchedule` | Must contain: `enabled`, `frequency`, `backup_time`, `retention`, `cron_key`. Verify `cron_key` is non-empty when enabled |

### FDB-008
| Check | Firebase Path | Test |
|-------|--------------|------|
| Log entry structure | `System/Logs/Activity/{date}/{key}` | Must contain: `action`, `sa_name`, `ip`, `timestamp` (YYYY-MM-DD HH:ii:ss) |

### FDB-009
| Check | Firebase Path | Test |
|-------|--------------|------|
| Rate limit records | `RateLimit/SA/{ip}` | Must contain: `count`, `first_fail`. Verify auto-expiry logic (count resets after 30 min) |

### FDB-010
| Check | Firebase Path | Test |
|-------|--------------|------|
| Stats Summary cache | `System/Stats/Summary` | Must contain: `total_schools`, `active_schools`, `total_students`, `total_staff`, `total_revenue`, `updated_at`. Verify `updated_at` changes on Refresh Stats |

### FDB-011 — Dangling Reference Test
| Check | Test |
|-------|------|
| After deleting a school, confirm | `Users/Schools/{name}` deleted · `System/Schools/{name}` deleted · `School_ids/{code}` deleted · `System/Backups/{safe_uid}` remains (intentional archive) |

### FDB-012 — Concurrent Write Conflict
| Check | Test |
|-------|------|
| Two SA sessions run `refresh_stats` simultaneously | Last write wins in Firebase (no transactions); verify no corrupted partial data. Consider documenting this as a known limitation. |

---

## 10. API Endpoint Tests

Use Postman or curl for all tests below. All POST requests must include:
```
Content-Type: application/x-www-form-urlencoded
ci_csrf_token: {valid_token_from_session}
Cookie: ci_session={valid_sa_session}
```

### Endpoint Matrix

| Test ID | Endpoint | Method | Auth Required | Payload | Expected Response |
|---------|----------|--------|--------------|---------|-------------------|
| API-001 | `/superadmin/login/authenticate` | POST | None | `username, password` | `{redirect: "/superadmin/dashboard"}` or error |
| API-002 | `/superadmin/dashboard/refresh_stats` | POST | SA Session | none | `{status:"success", total_schools, active_schools, ...}` |
| API-003 | `/superadmin/dashboard/charts` | POST | SA Session | none | `{status_counts, plan_dist, revenue_trend, top_schools, recent_regs}` |
| API-004 | `/superadmin/schools/check_availability` | POST | SA Session | `school_name, school_code` | `{name_taken: bool, code_taken: bool}` |
| API-005 | `/superadmin/schools/onboard` | POST | SA Session | Full onboard payload | `{status:"success", school_name}` |
| API-006 | `/superadmin/schools/toggle_status` | POST | SA Session | `school_name, status` | `{status:"success"}` |
| API-007 | `/superadmin/schools/assign_plan` | POST | SA Session | `school_name, plan_id, end_date` | `{status:"success"}` |
| API-008 | `/superadmin/plans/create` | POST | SA Session | `name, price, duration, modules[]` | `{status:"success", plan_id}` |
| API-009 | `/superadmin/plans/fetch` | POST | SA Session | `plan_id` | Full plan object |
| API-010 | `/superadmin/plans/delete` | POST | SA Session | `plan_id` | `{status:"success"}` or error if schools assigned |
| API-011 | `/superadmin/plans/fetch_subscriptions` | POST | SA Session | none | `{active:[], grace:[], expired:[], suspended:[]}` |
| API-012 | `/superadmin/plans/expire_check` | POST | SA Session | none | `{status:"success", suspended_count}` |
| API-013 | `/superadmin/plans/add_payment` | POST | SA Session | `school_name, amount, mode, date, plan_name` | `{status:"success", payment_id}` |
| API-014 | `/superadmin/monitor/system_health` | POST | SA Session | none | `{memory_used_pct, disk_used_pct, firebase_ms, php_version, ...}` |
| API-015 | `/superadmin/monitor/firebase_usage` | POST | SA Session | none | `{days: [{date, activity, school_logins, errors, api_calls}]}` — 7 items |
| API-016 | `/superadmin/monitor/clear_logs` | POST | SA Session | `log_type, log_date` | `{status:"success"}` |
| API-017 | `/superadmin/monitor/log_api_call` | POST | SA Session | `endpoint, method, status_code, duration_ms` | `{status:"success"}` |
| API-018 | `/superadmin/backups/create_backup` | POST | SA Session | `school_uid, backup_type` | `{status:"success", backup_id, size_human}` |
| API-019 | `/superadmin/backups/restore_backup` | POST | SA Session | `school_uid, backup_id, confirmation_token` | `{status:"success"}` |
| API-020 | `/superadmin/backups/backup_stats` | POST | SA Session | none | `{total_backups, total_size, schools_backed, ...}` |
| API-021 | `/superadmin/backups/save_schedule` | POST | SA Session | `enabled, frequency, retention, ...` | `{status:"success", cron_key}` |
| API-022 | `/superadmin/backups/run_scheduled_now` | POST | SA Session | none | `{status:"success", succeeded, failed, results[]}` |
| API-023 | `/backup_cron/{key}` | GET | Cron Key | none | `{status:"success", succeeded, failed}` or skip/error |

### Error Response Tests

| Test ID | Scenario | Expected |
|---------|----------|----------|
| API-E01 | POST with missing required field | `{status:"error", message:"Field X required"}` — never a PHP fatal |
| API-E02 | Invalid school_uid in backup call | `{status:"error", message:"No data found..."}` |
| API-E03 | Restore with wrong token | `{status:"error", message:"Type RESTORE to confirm"}` |
| API-E04 | Delete plan with schools assigned | `{status:"error", message:"X school(s) are on this plan"}` |
| API-E05 | Firebase connection failure | Graceful JSON error, not HTML 500 page |

---

## 11. UI Interaction Tests

### UI-001 — Navigation and Active States
| Test | Expected |
|------|----------|
| Click each sidebar nav item | Active class applied; correct page loads; no 404 |
| Breadcrumb on each SA page | Correct trail shown (e.g., Super Admin > Schools > TestSchool) |

### UI-002 — Modal Behaviour
| Test | Expected |
|------|----------|
| Open/close Create Plan modal | Modal opens and closes cleanly; form resets on close |
| Open Edit modal for plan | Fields pre-populated correctly from `fetch` AJAX call |
| Rapid open/close | No duplicate event handlers; no stale data in modal |

### UI-003 — Toast Notifications
| Test | Expected |
|------|----------|
| Successful create/edit/delete | Green toast with success message appears and auto-dismisses |
| Failed action | Red toast with error message |
| `saToast()` called before DOM ready | No JS error; toast queued or silently skipped |

### UI-004 — DataTables Initialisation
| Test | Expected |
|------|----------|
| Schools list table | Sortable, searchable, paginated |
| Plans list table | Same |
| Backup history table | Correct columns; Download/Restore/Delete buttons trigger correct actions |
| Table with 0 rows | "No data" message, not blank or broken layout |

### UI-005 — Form Validation
| Test | Expected |
|------|----------|
| Submit onboard form with empty required fields | HTML5 validation or JS inline errors; form not submitted |
| Price field — enter letters | Rejected or ignored; no NaN sent to server |
| End date — set in past | Warning shown (plan already expired) |

### UI-006 — Tab Navigation
| Test | Expected |
|------|----------|
| Click each tab in Monitor, Backup views | Content switches; AJAX fires for tab that needs data |
| Refresh page on non-default tab | Default tab shown (no URL hash persistence needed) |
| Tab switch during pending AJAX | Previous AJAX not cancelled causing stale data in wrong tab |

### UI-007 — Theme (Night/Day) Toggle
| Test | Expected |
|------|----------|
| Toggle night/day from any SA page | `data-theme` attribute changes; CSS vars update; charts re-render; no layout break |
| Theme persists across page navigation | LocalStorage or cookie used; correct theme on next page load |

### UI-008 — Responsive Layout
| Test | Expected |
|------|----------|
| Resize to 768px width | Sidebar collapses; KPI cards stack vertically; tables horizontally scrollable |
| Resize to 1024px | Two-column card layout; no overflow |

### UI-009 — Drag-and-Drop File Upload (Backup Restore)
| Test | Expected |
|------|----------|
| Drag valid .json onto drop zone | File info shown (name, size); preview populated via FileReader |
| Drag non-json file | Drop rejected or warning shown |
| Click to browse | File picker opens; selection triggers same preview logic |

### UI-010 — Cron Command Copy
| Test | Expected |
|------|----------|
| Click "Copy" next to cron command in Schedule tab | Correct cron command copied to clipboard; copy feedback shown |

---

## 12. Performance Testing Suggestions

### PERF-001 — Dashboard Load Time (Baseline)
| Metric | Target |
|--------|--------|
| Initial page render (HTML + CSS) | < 1.5s |
| `dashboard/charts` AJAX | < 2s (with 50 schools) |
| `refresh_stats` POST | < 3s (with 50 schools) |
**Tool:** Chrome DevTools Network tab; Lighthouse audit

### PERF-002 — Firebase Read Volume Per Dashboard Load
**Current behaviour:** `dashboard_charts()` reads `Users/Schools` (full tree) + `System/Payments` + `System/Logs/Activity/{today}`.
**Test:** Time each Firebase call with 100 schools having 500 students each.
**Expected:** Total Firebase read time < 3s.
**Red flag:** > 5s or reads > 1MB per call.

### PERF-003 — Backup Performance
| Test | Metric |
|------|--------|
| Create Firebase backup for school with 10,000 students | < 15s; file < 5MB |
| Create Full (ZIP) backup with uploaded files | < 60s with `set_time_limit(300)` |
| Run All Schools Now with 50 schools | < 5 minutes; no PHP timeout |
**Tool:** `microtime()` logging; `set_time_limit` adequacy check.

### PERF-004 — Monitor Log Loading
**Test:** Load Login Logs tab with 500 log entries for a single day.
**Expected:** AJAX returns in < 2s; DataTables renders without freeze.
**Risk:** If Firebase returns 10,000 log entries, PHP `json_encode` and transfer time could exceed 30s.

### PERF-005 — Concurrent SA Sessions
**Test:** Open 3 SA sessions simultaneously; each triggers `refresh_stats`.
**Expected:** No corrupted `System/Stats/Summary`; last write wins but no partial state.

### PERF-006 — Backup Retention Scan
**Test:** School with 100 backup entries; set retention=5; trigger cron.
**Expected:** 95 oldest entries deleted in < 10s (95 Firebase deletes + 95 file unlinks).
**Risk:** N+1 Firebase deletes in a loop — each is a separate HTTP call to Firebase REST API.

---

## 13. Architecture Weaknesses at 500+ Schools

### ARCH-01 — N+1 Firebase Reads in Dashboard
| Risk | HIGH |
|------|------|
| **Description** | `dashboard_charts()` fetches `Users/Schools` as a single read but then `refresh_stats()` may iterate per school. At 500 schools, a full tree read of `Users/Schools` could be 100MB+ if each school has deep nested data. |
| **Symptom** | Dashboard timeout, Firebase read quota exhaustion, slow page load |
| **Fix** | Use Firebase shallow_get for `Users/Schools` (returns keys only); keep a pre-aggregated `System/Stats/Summary` node and update it incrementally on school create/update rather than recomputing from scratch. |

### ARCH-02 — No Pagination for Schools List
| Risk | HIGH |
|------|------|
| **Description** | `index()` in `Superadmin_schools` reads the entire `Users/Schools` tree to render the table. With 500 schools × full profile data, this single read could be 50MB+. |
| **Symptom** | Schools page times out or returns 502 |
| **Fix** | Server-side pagination using Firebase's `limitToFirst` + `startAfter` (cursor-based); load school summaries from `System/Schools` (lighter node) not `Users/Schools`. |

### ARCH-03 — Backup `run_scheduled_now` is Synchronous
| Risk | HIGH |
|------|------|
| **Description** | `run_scheduled_now()` runs all 500 schools in a single PHP request: sequential Firebase reads + file writes. At 500 schools × avg 2s per backup = 1000s (16 min). PHP `set_time_limit(300)` = 5 min → timeout guaranteed. |
| **Symptom** | 504 Gateway Timeout; partial backups; no feedback to UI |
| **Fix** | Use a job queue (Redis/DB-backed); process in batches of 20; return job_id immediately and poll for status. Or use true server-side cron (Backup_cron) and remove the synchronous "Run Now" for large installs. |

### ARCH-04 — Firebase REST API Latency (No SDK Caching)
| Risk | MEDIUM |
|------|--------|
| **Description** | Every Firebase read is a synchronous HTTP call to the Firebase REST API. With no connection pooling or caching, 10 Firebase reads on a single page request = 10 × ~100ms = 1s just in network time. Dashboard, monitor, and backups each make 5–15 reads. |
| **Symptom** | Slow page loads even with small data; latency proportional to number of reads |
| **Fix** | Implement a request-scoped cache (array in CI library); cache `System/Plans`, `Users/Schools` keys for 60s; use Firebase `.json?shallow=true` for key-only reads. |

### ARCH-05 — Log Accumulation Without TTL
| Risk | MEDIUM |
|------|--------|
| **Description** | Activity, login, error, and API logs are written to `System/Logs/{type}/{date}` with no automatic expiry. After 1 year × 500 schools × 100 daily logins = ~18M log nodes. Firebase query latency grows with node count. |
| **Symptom** | Monitor log tab slow; Firebase free tier quota exhausted |
| **Fix** | Nightly log archival/deletion cron; keep only last 90 days; export old logs to Firebase Storage as gzipped JSON before deleting. |

### ARCH-06 — No Background Job Queue for AJAX
| Risk | MEDIUM |
|------|--------|
| **Description** | Long-running operations (full backup with files, run all schools now, expire_check for 500 schools) execute synchronously during AJAX calls. If PHP times out, the client gets a timeout error with no way to resume or check partial progress. |
| **Fix** | Background job pattern: store job in Firebase `System/Jobs/{job_id}`, return job_id to client, run operation in background (via `exec("php index.php ...")` or a worker), poll `/jobs/status/{id}`. |

### ARCH-07 — Backup File Storage on Web Server
| Risk | MEDIUM |
|------|--------|
| **Description** | Backups stored in `application/backups/` on the web server. At 500 schools × 1 backup/day × 1MB avg = 500MB/day. A month of retention = 15GB. Most shared/VPS hosts will hit disk limits. |
| **Fix** | Stream backups to Firebase Storage or an S3-compatible store; keep only last 2 backups locally; store metadata in Firebase but files in cloud storage. |

### ARCH-08 — Single Superadmin Session (No Role Granularity)
| Risk | LOW-MEDIUM |
|------|------------|
| **Description** | All SA users have identical capabilities. No read-only, support, or billing-only SA roles. A support SA could accidentally run a restore operation. |
| **Fix** | Add `sa_permissions` field to SA user node; check permissions per sensitive action (restore, delete, clear_logs, expire_check). |

### ARCH-09 — CSRF Tokens Not Rotated Per-Request
| Risk | LOW |
|------|-----|
| **Description** | CodeIgniter 3 by default regenerates CSRF token per session (not per request unless `csrf_regenerate = TRUE`). With AJAX-heavy SA panel, the token needs to be refreshed in response headers/meta tag after each mutating call. |
| **Fix** | Confirm `csrf_regenerate` setting in `config.php`; ensure all AJAX success handlers update the meta CSRF token if the server rotates it. |

### ARCH-10 — Firebase Security Rules Not Application-Enforced
| Risk | HIGH |
|------|------|
| **Description** | All Firebase writes are done server-side with a service account key — Firebase security rules may be set to `.write: true` for the service account path. If the Firebase credentials are leaked, an attacker can read/write all school data directly via REST API, bypassing the CI application entirely. |
| **Fix** | Review Firebase Security Rules to scope service account to only necessary paths; rotate service account key periodically; store `firebase_credentials.json` outside webroot; verify it is excluded from git. |

---

## Summary Checklist

| Category | Total Tests | Priority |
|----------|-------------|----------|
| Auth | 8 | Critical |
| Onboarding | 9 | High |
| Plans | 10 | High |
| Dashboard | 7 | Medium |
| Monitor | 8 | Medium |
| Backups | 15 | High |
| Security | 10 | Critical |
| Firebase Validation | 12 | High |
| API Endpoints | 28 | High |
| UI Interaction | 10 | Medium |
| Performance | 6 | Medium |
| **Total** | **123** | — |

**Architecture risks to address before 500-school scale:** ARCH-01, ARCH-02, ARCH-03, ARCH-10.
