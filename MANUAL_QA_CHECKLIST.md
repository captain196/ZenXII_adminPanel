# GraderIQ Super Admin — Manual QA Checklist
**Project:** GraderIQ SaaS ERP
**Stack:** CodeIgniter 3 · Firebase · AdminLTE
**Tester Name:** ___________________
**Test Date:** ___________________
**Build / Commit:** ___________________
**Environment:** ☐ Local  ☐ Staging  ☐ Production

---

## How to Use This Checklist

- Work through each module top to bottom.
- For every step mark: **✅ Pass** · **❌ Fail** · **⚠️ Partial** · **⏭ Skipped**
- If a step fails, note the actual result in the **Notes** column.
- Open **Firebase Console → Realtime Database** in a side tab so you can verify data live.
- Open **Browser DevTools → Console + Network** tabs throughout.

### Status Key
| Symbol | Meaning |
|--------|---------|
| ✅ | Works as expected |
| ❌ | Broken / wrong result |
| ⚠️ | Works but with issues |
| ⏭ | Skipped (reason in notes) |

---

## Test Accounts Required

| Role | Username | Password | School Code | Notes |
|------|----------|----------|-------------|-------|
| Developer SA | `devadmin` | `••••••` | `Our Panel` | Full access |
| School Super Admin | `schoolsa` | `••••••` | `TST001` | SA role |
| Regular Admin | `regularadmin` | `••••••` | `TST001` | Should be blocked |
| Test School A | — | — | `SCH001` | Active subscription |
| Test School B | — | — | `SCH002` | Expired subscription |
| Test School C | — | — | `SCH003` | Suspended |

---

---

# MODULE 1 — SUPER ADMIN AUTHENTICATION

## 1.1 Login Page

| # | Step | What to Do | Expected Result | Status | Notes |
|---|------|-----------|-----------------|--------|-------|
| 1.1.1 | Open login page | Navigate to `/superadmin/login` | Login form renders with username, password, submit button. No console errors | | |
| 1.1.2 | Check page title | Look at browser tab | Shows "GraderIQ SA" or similar | | |
| 1.1.3 | Night/Day theme | Click theme toggle button | Page switches between dark and light mode smoothly | | |
| 1.1.4 | Theme persists | Refresh the page | Same theme loads (stored in localStorage) | | |
| 1.1.5 | Empty submit | Click Login without entering anything | Inline validation prevents submit OR shows "required" messages | | |

## 1.2 Authentication

| # | Step | What to Do | Expected Result | Status | Notes |
|---|------|-----------|-----------------|--------|-------|
| 1.2.1 | Valid SA login | Enter correct devadmin credentials → Submit | Redirected to `/superadmin/dashboard`. No error shown | | |
| 1.2.2 | Session check | Open DevTools → Application → Cookies | `ci_session` cookie present | | |
| 1.2.3 | Wrong password | Enter correct username + wrong password | Stay on login page. Error message shown. Counter in Firebase `RateLimit/SA/{ip}` incremented | | |
| 1.2.4 | Wrong username | Enter non-existent username | Error message shown. No crash | | |
| 1.2.5 | Regular admin blocked | Enter `regularadmin` credentials | Login rejected. Error: role not allowed | | |
| 1.2.6 | Rate limit test | Enter wrong password 10 times | On 11th attempt: lockout message shown. Cannot login for 30 min | | |
| 1.2.7 | School SA login | Enter `schoolsa` credentials (Role=Super Admin) | Login succeeds. Redirects to dashboard | | |

## 1.3 Session Security

| # | Step | What to Do | Expected Result | Status | Notes |
|---|------|-----------|-----------------|--------|-------|
| 1.3.1 | Direct URL without login | Open new incognito window → Go to `/superadmin/dashboard` | Redirected to `/superadmin/login` | | |
| 1.3.2 | Test all protected routes | Without session, try: `/superadmin/schools`, `/superadmin/plans`, `/superadmin/monitor`, `/superadmin/backups` | All redirect to login | | |
| 1.3.3 | Logout | Click Logout button or go to `/superadmin/logout` | Session destroyed. Redirected to login | | |
| 1.3.4 | Back button after logout | After logout, press browser Back button | Login page shown again (not dashboard) | | |
| 1.3.5 | Session expiry | Login → Wait for CI session timeout → Try to navigate | Redirected to login | | |

**Module 1 Summary**
Total Steps: 17 · Pass: ___ · Fail: ___ · Notes: ___________________

---

---

# MODULE 2 — SCHOOL ONBOARDING

> **Before starting:** Login as devadmin. Keep Firebase Console open at `Users/Schools/`

## 2.1 Schools List Page

| # | Step | What to Do | Expected Result | Status | Notes |
|---|------|-----------|-----------------|--------|-------|
| 2.1.1 | Open schools page | Navigate to `/superadmin/schools` | Table renders with all schools. Columns: Name, Code, Plan, Status, Students, Staff, Actions | | |
| 2.1.2 | Table search | Type partial school name in search box | Table filters live as you type | | |
| 2.1.3 | Table sort | Click column headers | Rows sort ascending/descending | | |
| 2.1.4 | Status badges | Look at Status column | Correct badges: Active (green), Inactive (grey), Suspended (red), Expired (orange) | | |
| 2.1.5 | No schools state | (If fresh DB) Clear all schools and reload | "No data" message shown, not a broken layout | | |

## 2.2 Onboard a New School

| # | Step | What to Do | Expected Result | Status | Notes |
|---|------|-----------|-----------------|--------|-------|
| 2.2.1 | Open onboard form | Click "Onboard New School" button | Form page loads at `/superadmin/schools/create` | | |
| 2.2.2 | Check availability | Type school name "QA Test School" → click Check | Green tick: name available | | |
| 2.2.3 | Duplicate name check | Type an existing school name → click Check | Red message: name already taken | | |
| 2.2.4 | School code check | Type code "QAT001" → click Check | Green tick: code available | | |
| 2.2.5 | Duplicate code check | Type an existing school code | Red message: code already taken | | |
| 2.2.6 | Submit incomplete form | Leave required fields blank → Submit | Form validation blocks submission. Fields highlighted | | |
| 2.2.7 | Full onboard | Fill: Name="QA Test School", Code="QAT001", Admin Email, select plan, session year → Submit | Success message. Redirected to school detail page | | |

## 2.3 Verify Firebase Data After Onboard

> Open Firebase Console and check each path manually

| # | Path to Check | What to Verify | Status | Notes |
|---|--------------|----------------|--------|-------|
| 2.3.1 | `Users/Schools/QA Test School/` | Node exists with `name`, `school_id`, `status="active"`, `created_at` | | |
| 2.3.2 | `Users/Schools/QA Test School/subscription` | Contains `plan_id`, `plan_name`, `status`, `end_date` | | |
| 2.3.3 | `System/Schools/QA Test School/` | SA metadata node exists | | |
| 2.3.4 | `School_ids/QAT001` | Value = "QA Test School" | | |
| 2.3.5 | `Users/Admin/QAT001/{adminId}` | Admin node with `Role`, `email`, `password` (bcrypt hash — starts with `$2y$`) | | |
| 2.3.6 | `Schools/QA Test School/{session}/Accounts/` | Default account heads created | | |

## 2.4 School Detail Page

| # | Step | What to Do | Expected Result | Status | Notes |
|---|------|-----------|-----------------|--------|-------|
| 2.4.1 | Open school detail | Click school name or View button | Detail page loads: profile card, subscription info, stats, actions | | |
| 2.4.2 | Edit profile | Click Edit Profile → Change address → Save | Success toast. Check `System/Schools/{name}` in Firebase updated | | |
| 2.4.3 | Toggle to Inactive | Click status toggle → Select "Inactive" → Confirm | Status badge changes to grey. Firebase `Users/Schools/{name}/status = "inactive"` | | |
| 2.4.4 | Toggle to Suspended | Change status to "Suspended" | Badge changes to red. Firebase updated | | |
| 2.4.5 | Toggle back to Active | Change status to "Active" | Badge returns to green | | |
| 2.4.6 | Assign new plan | Click "Assign Plan" → Select different plan → Set end date 1 year ahead → Save | Subscription updated in Firebase. New plan name shown on page | | |
| 2.4.7 | Refresh school stats | Click "Refresh Stats" | AJAX loads. Student/staff counts updated based on Firebase school data | | |

## 2.5 Multi-School Isolation Check

| # | Step | What to Do | Expected Result | Status | Notes |
|---|------|-----------|-----------------|--------|-------|
| 2.5.1 | Open School A detail | View school SCH001 | Shows only SCH001 data | | |
| 2.5.2 | Check data separation | Manually verify subscription, stats, admin | No data from SCH002 or SCH003 visible | | |
| 2.5.3 | School B has no cross-data | View school SCH002 | Completely separate subscription, stats | | |

**Module 2 Summary**
Total Steps: 21 · Pass: ___ · Fail: ___ · Notes: ___________________

---

---

# MODULE 3 — SUBSCRIPTION PLAN MANAGEMENT

> Navigate to `/superadmin/plans`

## 3.1 Plan Catalogue

| # | Step | What to Do | Expected Result | Status | Notes |
|---|------|-----------|-----------------|--------|-------|
| 3.1.1 | Load plans page | Navigate to `/superadmin/plans` | Plans listed in cards/table. Each shows: name, price, billing cycle, student limit | | |
| 3.1.2 | Seed defaults | Click "Seed Default Plans" (if no plans exist) | Basic, Standard, Premium plans created. Success toast | | |
| 3.1.3 | Seed idempotency | Click "Seed Default Plans" again when plans exist | No duplicates created. Message: "Plans already exist" | | |

## 3.2 Create a Plan

| # | Step | What to Do | Expected Result | Status | Notes |
|---|------|-----------|-----------------|--------|-------|
| 3.2.1 | Open create modal | Click "New Plan" | Modal opens with blank form | | |
| 3.2.2 | Fill plan details | Name="QA Plan", Price=999, Billing=monthly, Max Students=200 | Fields accept valid input | | |
| 3.2.3 | Toggle modules | Enable 5 modules, disable 10 | Toggles respond; enabled count shown | | |
| 3.2.4 | Save plan | Click Save | Modal closes. New plan appears in list. Check `System/Plans/{id}` in Firebase: all 15 module keys present | | |
| 3.2.5 | Empty name | Open modal → leave name blank → Save | Validation error: name required | | |
| 3.2.6 | Negative price | Enter price = -100 | Rejected or shows validation error | | |

## 3.3 Edit a Plan

| # | Step | What to Do | Expected Result | Status | Notes |
|---|------|-----------|-----------------|--------|-------|
| 3.3.1 | Open edit modal | Click Edit on "QA Plan" | Modal pre-populated with existing values | | |
| 3.3.2 | Change price | Change price to 1299 → Save | Modal closes. List shows updated price. Firebase `System/Plans/{id}/price = 1299` | | |
| 3.3.3 | Toggle module change | Disable a module that was enabled → Save | Firebase `modules/{moduleName} = false` | | |

## 3.4 Delete a Plan

| # | Step | What to Do | Expected Result | Status | Notes |
|---|------|-----------|-----------------|--------|-------|
| 3.4.1 | Delete plan with schools | Assign "Standard" to at least one school → Try to delete "Standard" | Blocked: "X school(s) are on this plan. Reassign them first." | | |
| 3.4.2 | Delete unused plan | Delete "QA Plan" (no schools assigned) | Confirmation prompt → Confirm → Plan removed from list and Firebase | | |

## 3.5 Subscriptions View

> Navigate to `/superadmin/plans/subscriptions`

| # | Step | What to Do | Expected Result | Status | Notes |
|---|------|-----------|-----------------|--------|-------|
| 3.5.1 | Load page | Open subscriptions page | 4 buckets shown: Active, Expiring Soon (≤30d), Grace Period, Expired/Suspended | | |
| 3.5.2 | Active school | TestSchool_Alpha (end date 6 months away) | Appears in Active bucket | | |
| 3.5.3 | Expiring soon | Set a school end date to today + 15 days | Appears in "Expiring Soon" bucket | | |
| 3.5.4 | Grace period school | Set end date to 7 days ago | Appears in "Grace Period" (not suspended yet) | | |
| 3.5.5 | Expired school | Set end date to 20 days ago | Appears in "Expired" bucket | | |

## 3.6 Subscription Enforcement

| # | Step | What to Do | Expected Result | Status | Notes |
|---|------|-----------|-----------------|--------|-------|
| 3.6.1 | Auto-suspend | Click "Run Expire Check" button | Schools past grace period get suspended. Count shown in response | | |
| 3.6.2 | Firebase verify | Check `Users/Schools/{expired_school}/status` | Value = "suspended" | | |
| 3.6.3 | Active schools safe | Run expire check with active schools | Active schools NOT touched | | |

## 3.7 Payments

> Navigate to `/superadmin/plans/payments`

| # | Step | What to Do | Expected Result | Status | Notes |
|---|------|-----------|-----------------|--------|-------|
| 3.7.1 | Load payments page | Open payments page | Table of all payment records. Columns: School, Amount, Plan, Date, Status, Mode | | |
| 3.7.2 | Add payment | Click "Add Payment" → Select school, enter ₹5000, cash, today → Save | New row in table. Check `System/Payments/{id}` in Firebase | | |
| 3.7.3 | Edit payment | Click Edit on new payment → Change to ₹6000 → Save | Row updates. Firebase amount = 6000 | | |
| 3.7.4 | Delete payment | Click Delete → Confirm | Row removed. Firebase node deleted | | |
| 3.7.5 | Filter by school | Use school filter dropdown | Only that school's payments shown | | |

**Module 3 Summary**
Total Steps: 26 · Pass: ___ · Fail: ___ · Notes: ___________________

---

---

# MODULE 4 — GLOBAL SAAS DASHBOARD

> Navigate to `/superadmin/dashboard`

## 4.1 KPI Cards

| # | Step | What to Do | Expected Result | Status | Notes |
|---|------|-----------|-----------------|--------|-------|
| 4.1.1 | Page load | Navigate to dashboard | Page loads without errors. 6 KPI cards visible | | |
| 4.1.2 | Total Schools card | Count schools in Firebase `Users/Schools/` | KPI card number matches | | |
| 4.1.3 | Active Schools card | Count schools with `status="active"` | Matches KPI + shows % of total | | |
| 4.1.4 | Total Students card | Should reflect cached count | Number shown (not blank/null) | | |
| 4.1.5 | Total Staff card | Same | Number shown | | |
| 4.1.6 | Total Revenue card | Sum from `System/Payments` (paid status) | Shows ₹ amount | | |
| 4.1.7 | New Schools (30d) card | Count schools created in last 30 days | Matches recent onboardings | | |
| 4.1.8 | No console errors | Check DevTools Console | Zero JS errors on load | | |

## 4.2 Charts

| # | Step | What to Do | Expected Result | Status | Notes |
|---|------|-----------|-----------------|--------|-------|
| 4.2.1 | Charts AJAX | Open Network tab → Load page → Find `dashboard/charts` request | Status 200. Response has `status_counts`, `plan_dist`, `revenue_trend`, `top_schools`, `recent_regs` | | |
| 4.2.2 | Status doughnut | Look at first chart | Doughnut renders with Active/Inactive/Suspended segments. Centre shows total | | |
| 4.2.3 | Revenue bar chart | Look at second chart | 6 monthly bars rendered. Y-axis shows ₹ values | | |
| 4.2.4 | Plan distribution | Look at third chart | One segment per plan. Legend matches plan names | | |
| 4.2.5 | Top schools chart | Look at fourth chart | Horizontal bars, one per school. Sorted by students | | |
| 4.2.6 | Empty chart state | Remove all data → Load dashboard | Charts show "no data" gracefully, not a JS crash | | |

## 4.3 Theme Interaction

| # | Step | What to Do | Expected Result | Status | Notes |
|---|------|-----------|-----------------|--------|-------|
| 4.3.1 | Toggle to day mode | Click theme toggle | Charts re-render with light axis text. No white-on-white text | | |
| 4.3.2 | Toggle back to night | Click toggle again | Charts switch back. No residual artefacts | | |
| 4.3.3 | Chart axis colors | Inspect chart axis labels after theme switch | Labels visible in both modes (dark text in light mode, light text in dark mode) | | |

## 4.4 Refresh and Expiry Alerts

| # | Step | What to Do | Expected Result | Status | Notes |
|---|------|-----------|-----------------|--------|-------|
| 4.4.1 | Refresh stats | Onboard a new school → Come back to dashboard → Click "Refresh Stats" | Total Schools count increases by 1. All 6 cards update. `System/Stats/Summary/updated_at` changes in Firebase | | |
| 4.4.2 | Refresh spinner | Watch Refresh Stats button during AJAX | Button disabled + icon spins during call. Re-enables after | | |
| 4.4.3 | Expiry alert panel | Set a school's end date to today + 8 days | Alert panel shows school name + "8 days remaining" | | |
| 4.4.4 | No expiry alerts | Ensure all schools have end date > 15 days | Alert panel hidden or shows "No upcoming expirations" | | |

## 4.5 Recent Registrations Table

| # | Step | What to Do | Expected Result | Status | Notes |
|---|------|-----------|-----------------|--------|-------|
| 4.5.1 | Table renders | Load dashboard and wait for AJAX | Recent registrations table populated. Columns: School Name, Date, Plan, Status | | |
| 4.5.2 | Latest first | Check row order | Newest school at top | | |

**Module 4 Summary**
Total Steps: 21 · Pass: ___ · Fail: ___ · Notes: ___________________

---

---

# MODULE 5 — SYSTEM MONITORING

> Navigate to `/superadmin/monitor`

## 5.1 Overview Tab

| # | Step | What to Do | Expected Result | Status | Notes |
|---|------|-----------|-----------------|--------|-------|
| 5.1.1 | Page load | Navigate to `/superadmin/monitor` | Page loads. 6 KPI cards at top. Overview tab active | | |
| 5.1.2 | SA Actions KPI | Perform any SA action (e.g., toggle school status) → Reload monitor | Today's SA action count increments | | |
| 5.1.3 | Errors KPI | Check Errors card colour | Red border if errors > 0, normal otherwise | | |
| 5.1.4 | Firebase status | Look at Firebase status card | Shows "Connected" or response time in ms | | |
| 5.1.5 | 7-day chart | Check stacked bar chart on Overview tab | 7 date labels on X-axis. 4 stacked series (Activity, School Logins, API Calls, Errors) | | |
| 5.1.6 | Today summary row | Check today's row below chart | Shows today's date with 4 counts | | |
| 5.1.7 | Latest activity table | Scroll down on Overview | Table of today's recent SA activities (action, user, IP, time) | | |

## 5.2 Login Logs Tab

| # | Step | What to Do | Expected Result | Status | Notes |
|---|------|-----------|-----------------|--------|-------|
| 5.2.1 | Switch to Login Logs | Click "Login Logs" tab | Tab content loads. Date picker set to today | | |
| 5.2.2 | SA Activity logs | Select type = "SA Activity" | Table shows SA login/action events for today | | |
| 5.2.3 | School Admin Logins | Select type = "School Admin Logins" | Table shows school admin login events | | |
| 5.2.4 | Change date | Change date to yesterday | AJAX fires. Logs for yesterday loaded (may be empty) | | |
| 5.2.5 | Live search | Type in the search box | Table filters by user/school/IP instantly (no AJAX) | | |
| 5.2.6 | Empty date | Select a date with no logs | Table shows "No entries found" message | | |

## 5.3 Error Logs Tab

| # | Step | What to Do | Expected Result | Status | Notes |
|---|------|-----------|-----------------|--------|-------|
| 5.3.1 | Switch to Error Logs | Click "Error Logs" tab | Table loads with today's error entries | | |
| 5.3.2 | Error severity filter | Click "Error" pill filter | Only Error-severity rows shown. Count badge updates | | |
| 5.3.3 | Warning filter | Click "Warning" pill | Only Warning rows shown | | |
| 5.3.4 | All filter | Click "All" pill | All rows return | | |
| 5.3.5 | Row colours | Check table row background colours | Error=red tint, Warning=amber tint, Info=neutral | | |

## 5.4 API Usage Tab

| # | Step | What to Do | Expected Result | Status | Notes |
|---|------|-----------|-----------------|--------|-------|
| 5.4.1 | Switch to API Usage | Click "API Usage" tab | Tab shows 7-day line chart + API log table | | |
| 5.4.2 | Line chart | Check the trend chart | One line per day for 7 days. Y-axis = call count | | |
| 5.4.3 | API log table | Check table | Columns: Endpoint, Method (colour badge), Status, Duration, Timestamp | | |
| 5.4.4 | Slow calls highlighted | Look for calls > 1000ms | Duration cell shown in red | | |
| 5.4.5 | Method badges | Check GET, POST, DELETE badge colors | GET=green, POST=blue, DELETE=red | | |

## 5.5 Server Health Tab

| # | Step | What to Do | Expected Result | Status | Notes |
|---|------|-----------|-----------------|--------|-------|
| 5.5.1 | Switch to Server Health | Click "Server Health" tab | Gauge bars shown for Memory, Disk. Service badges visible | | |
| 5.5.2 | Check health button | Click "Check System Health" | AJAX fires. Gauges animate to actual values. No spinner stuck | | |
| 5.5.3 | Memory gauge | Check memory % gauge | Bar fills proportionally. Shows "X MB / Y MB (Z%)" | | |
| 5.5.4 | Disk gauge | Check disk % gauge | Fills proportionally. Shows GB values | | |
| 5.5.5 | Firebase ping | Look at Firebase response time | Shows ms value. Green if <300ms, amber if <1000ms, red if >1000ms | | |
| 5.5.6 | Environment table | Check bottom table | Shows PHP version, CI version, server time, peak memory | | |
| 5.5.7 | Auto-refresh ON | Enable auto-refresh toggle | Gauges refresh every 30 seconds automatically | | |
| 5.5.8 | Auto-refresh OFF | Disable toggle | Refreshing stops. No infinite loop in background | | |
| 5.5.9 | Service badges | Check Firebase, MySQL, OPcache badges | Each shows Online/Offline/Disabled with colour | | |

## 5.6 Clear Logs

| # | Step | What to Do | Expected Result | Status | Notes |
|---|------|-----------|-----------------|--------|-------|
| 5.6.1 | Open clear modal | Click "Clear Logs" button | Modal opens with type and date selectors | | |
| 5.6.2 | Clear activity logs | Select type=Activity, date=today → Confirm | Success toast. Activity table now empty. `System/Logs/Activity/{today}` deleted in Firebase | | |
| 5.6.3 | Verify deletion | Check Firebase Console | Node `System/Logs/Activity/{today}` no longer exists | | |
| 5.6.4 | Cancel clear | Open clear modal → Click Cancel | No data deleted. Modal closes | | |

**Module 5 Summary**
Total Steps: 29 · Pass: ___ · Fail: ___ · Notes: ___________________

---

---

# MODULE 6 — BACKUP MANAGEMENT

> Navigate to `/superadmin/backups`

## 6.1 Overview Tab

| # | Step | What to Do | Expected Result | Status | Notes |
|---|------|-----------|-----------------|--------|-------|
| 6.1.1 | Page load | Navigate to `/superadmin/backups` | Page loads. 6 KPI cards at top. Overview tab active | | |
| 6.1.2 | KPI — Total Backups | Check "Total Backups" card | Shows count of all backup entries across all schools | | |
| 6.1.3 | KPI — Total Size | Check "Total Size" card | Shows human-readable size (e.g., "12.4 MB") | | |
| 6.1.4 | Schedule status | Check "Auto-Schedule" KPI card | Shows ON/OFF with correct colour | | |
| 6.1.5 | Global backup table | Scroll down to recent backups table | Shows last 30 backups across all schools. Columns: School, Backup ID, Type, Size, Created By, Date | | |
| 6.1.6 | Run All Now button | Click "Run All Schools Now" | Confirmation prompt → Confirm → Progress shown → Results table with per-school status | | |

## 6.2 Manual Backup Tab

| # | Step | What to Do | Expected Result | Status | Notes |
|---|------|-----------|-----------------|--------|-------|
| 6.2.1 | Switch to Manual | Click "Manual Backup" tab | School selector + backup type selector shown | | |
| 6.2.2 | Select school | Choose "QA Test School" from dropdown | School selected. Stats panel updates (current backup count, total size) | | |
| 6.2.3 | Firebase backup | Select Type = "Firebase Only" → Click Create Backup | Loading spinner → Success toast with backup ID. New row appears in backup history table | | |
| 6.2.4 | Verify file created | Check server path `application/backups/QA_Test_School/` | JSON file `BKP_*.json` exists with today's date in name | | |
| 6.2.5 | Verify Firebase metadata | Check `System/Backups/QA_Test_School/{backup_id}` | Contains: `backup_id`, `filename`, `backup_type="firebase"`, `size_bytes`, `status="completed"`, `created_at` | | |
| 6.2.6 | Full backup | Select Type = "Full (Firebase + Files)" → Create | Backup created (ZIP if ZipArchive available, else JSON fallback). Type shown correctly | | |
| 6.2.7 | Download backup | Click Download on any backup row | File downloads. `.json` has Content-Type `application/json`. `.zip` has `application/zip` | | |
| 6.2.8 | Open downloaded file | Open the downloaded JSON | Valid JSON. Contains keys: `backup_format`, `backed_up_at`, `firebase_key`, `Schools` | | |
| 6.2.9 | Schools key present | Check `Schools` key in downloaded JSON | Contains actual school data (not null/empty) | | |
| 6.2.10 | Delete backup | Click Delete on a backup row → Confirm | Row removed from table. File deleted from disk. Firebase metadata node deleted | | |
| 6.2.11 | No school selected | Click Create Backup without selecting school | Error: "Please select a school" | | |

## 6.3 Schedule Tab

| # | Step | What to Do | Expected Result | Status | Notes |
|---|------|-----------|-----------------|--------|-------|
| 6.3.1 | Switch to Schedule | Click "Schedule" tab | Config form shown: enable toggle, frequency, day, time, type, retention | | |
| 6.3.2 | Enable schedule | Toggle "Enable Auto-Backup" ON | Form fields become active | | |
| 6.3.3 | Set daily schedule | Frequency=Daily, Time=02:00, Retention=7, Type=Firebase → Save | Success toast. Check `System/BackupSchedule` in Firebase: `enabled=true`, `frequency="daily"`, `cron_key` present | | |
| 6.3.4 | Cron key generated | In Firebase, check `System/BackupSchedule/cron_key` | Non-empty string (UUID-like). Must not be blank | | |
| 6.3.5 | Cron command shown | Look at cron command display area | Linux crontab command shown with actual cron_key embedded. Windows PowerShell alternative shown | | |
| 6.3.6 | Copy cron command | Click copy button next to cron command | Clipboard updated. Copy feedback shown (button text changes briefly) | | |
| 6.3.7 | Save again | Click Save Schedule a second time | Same cron_key preserved (not regenerated). Success toast | | |
| 6.3.8 | Weekly schedule | Change frequency to Weekly → Select day (e.g., Monday) → Save | `System/BackupSchedule/frequency="weekly"`, `day_of_week` = correct int | | |
| 6.3.9 | Disable schedule | Toggle enable OFF → Save | `System/BackupSchedule/enabled=false`. KPI card shows "OFF" | | |
| 6.3.10 | Last run info | After running backup, check last run panel | Shows: date/time of last run, count of schools backed up | | |

## 6.4 Cron Endpoint Test

| # | Step | What to Do | Expected Result | Status | Notes |
|---|------|-----------|-----------------|--------|-------|
| 6.4.1 | Valid cron key | Copy cron_key from Firebase → Open in browser: `/backup_cron/{cron_key}` | JSON: `{"status":"success","succeeded":N,"failed":0,"results":[...]}` | | |
| 6.4.2 | Wrong cron key | Open `/backup_cron/wrongkey123` | JSON: `{"status":"error","message":"Invalid or expired cron key."}` HTTP 403 | | |
| 6.4.3 | Disabled schedule | Disable schedule in Firebase → Run cron | JSON: `{"status":"skipped","message":"Scheduled backups are disabled."}` | | |
| 6.4.4 | Double-run prevention | Run cron twice within same hour | Second run: `{"status":"skipped","message":"Already ran this hour."}` | | |
| 6.4.5 | Last run updated | After successful cron run, check Firebase | `System/BackupSchedule/last_run` = current datetime. `last_run_by = "cron"` | | |

## 6.5 Restore from Existing Backup

| # | Step | What to Do | Expected Result | Status | Notes |
|---|------|-----------|-----------------|--------|-------|
| 6.5.1 | Switch to Restore tab | Click "Restore" tab → "From Existing" sub-tab | School selector shown | | |
| 6.5.2 | Select school | Choose QA Test School | Backup list for that school loads in table | | |
| 6.5.3 | Click Restore | Click Restore button on a backup row | Restore modal opens showing: Backup ID, School Name, backed_up_at | | |
| 6.5.4 | Cancel restore | Click Cancel | Modal closes. No data changed | | |
| 6.5.5 | Wrong token | Type "restore" (lowercase) in confirmation box → Submit | Error: token must be exactly "RESTORE" (uppercase) | | |
| 6.5.6 | Empty token | Leave confirmation box blank → Submit | Error or button disabled | | |
| 6.5.7 | Correct restore | Type "RESTORE" → Submit | Success toast. Check Firebase — `Schools/{name}` data matches backup. Safety backup automatically created before restore | | |
| 6.5.8 | Safety backup created | Check `System/Backups/{school}` in Firebase | New entry with `type="safety"` or `backup_id` containing "SAFETY" exists, created just before restore | | |

## 6.6 Upload and Restore

| # | Step | What to Do | Expected Result | Status | Notes |
|---|------|-----------|-----------------|--------|-------|
| 6.6.1 | Open Upload Restore | Click "Upload & Restore" sub-tab | Drag-and-drop zone visible | | |
| 6.6.2 | Drag valid file | Drag a previously downloaded `.json` backup file | File info shown: filename, size. Preview populated: school name, firebase_key, backed_up_at, Schools: ✅ Present | | |
| 6.6.3 | Click to browse | Click the drop zone | OS file picker opens. Select .json file | | |
| 6.6.4 | Wrong file type | Drag a `.txt` or `.zip` onto drop zone | Error shown: "Only .json files accepted" | | |
| 6.6.5 | Invalid JSON | Upload a .json file with invalid structure (no `Schools` key) | Error: "Invalid backup format" | | |
| 6.6.6 | Complete upload restore | Upload valid file → Type "RESTORE" → Submit | Success. Firebase `Schools/{firebase_key}` updated. Safety backup created | | |
| 6.6.7 | Verify restore | Check Firebase `Schools/{name}` | Data matches the uploaded backup file | | |

## 6.7 Backup Restore Accuracy

| # | Step | What to Do | Expected Result | Status | Notes |
|---|------|-----------|-----------------|--------|-------|
| 6.7.1 | Data accuracy | After restore: open school admin panel → check student list | Students match what was in backup | | |
| 6.7.2 | No cross-school data | After restoring School A, check School B | School B data unchanged | | |
| 6.7.3 | Backup integrity | Open backup JSON in text editor | Valid JSON. `Schools` key contains full Firebase subtree. `backup_format = "1.2"` | | |

## 6.8 Retention Policy

| # | Step | What to Do | Expected Result | Status | Notes |
|---|------|-----------|-----------------|--------|-------|
| 6.8.1 | Set retention = 3 | Save schedule with retention=3 | Firebase updated | | |
| 6.8.2 | Create 5 backups | Create 5 manual backups for same school | 5 entries in backup list | | |
| 6.8.3 | Trigger retention | Run cron or "Run Now" | Oldest 2 backups auto-deleted. 3 remain | | |
| 6.8.4 | SAFETY backups preserved | Ensure one SAFETY backup exists before triggering retention | SAFETY backup NOT deleted by retention policy | | |
| 6.8.5 | File deleted from disk | Check `application/backups/{school}/` folder | Deleted backup files no longer exist on disk | | |
| 6.8.6 | Firebase metadata deleted | Check `System/Backups/{school}/` | Deleted backup IDs no longer exist in Firebase | | |

**Module 6 Summary**
Total Steps: 42 · Pass: ___ · Fail: ___ · Notes: ___________________

---

---

# CROSS-CUTTING TESTS

## CC-1 — Role-Based Access Control

| # | Step | What to Do | Expected Result | Status | Notes |
|---|------|-----------|-----------------|--------|-------|
| CC1.1 | Non-SA blocked | Login as `regularadmin` → Try `/superadmin/dashboard` | Redirected to SA login | | |
| CC1.2 | No session blocked | Without login → all SA routes redirect to login | Every route tested returns login redirect | | |
| CC1.3 | School SA access | Login as `schoolsa` (Role=Super Admin) | Access granted. Dashboard loads | | |
| CC1.4 | Destructive actions | As `schoolsa`, try restore and delete operations | Check if role scoping restricts destructive actions (restore/delete require higher role if implemented) | | |

## CC-2 — Multi-School Isolation

| # | Step | What to Do | Expected Result | Status | Notes |
|---|------|-----------|-----------------|--------|-------|
| CC2.1 | Create backup for School A | Create backup for SCH001 | Backup file in `application/backups/SCH001/` — contains only SCH001 data | | |
| CC2.2 | Restore School A | Restore SCH001 from backup | Only `Schools/SCH001` overwritten. `Schools/SCH002` unchanged | | |
| CC2.3 | Monitor logs — school A logins | Filter school login logs by SCH001 | Only SCH001 admin logins shown | | |
| CC2.4 | Delete School A backup | Delete a SCH001 backup | SCH002 backups unaffected | | |
| CC2.5 | Stats isolation | Refresh stats for SCH001 | Stats for SCH002 and SCH003 unchanged | | |

## CC-3 — Subscription Enforcement

| # | Step | What to Do | Expected Result | Status | Notes |
|---|------|-----------|-----------------|--------|-------|
| CC3.1 | Active school admin login | Login to school admin panel with active subscription | Access granted to all subscribed modules | | |
| CC3.2 | Suspended school | Set school to Suspended → Try school admin login | Login blocked or severely limited (depending on implementation) | | |
| CC3.3 | Grace period school | School 7 days past end date → Login | Login may succeed but limited features (grace period) | | |

## CC-4 — UI Consistency

| # | Step | What to Do | Expected Result | Status | Notes |
|---|------|-----------|-----------------|--------|-------|
| CC4.1 | Theme across pages | Toggle theme → Navigate between all 6 SA pages | Theme consistent on all pages. No page reverts to wrong theme | | |
| CC4.2 | Active sidebar link | On each SA page, check sidebar | Correct link highlighted as active | | |
| CC4.3 | Breadcrumb | Check top of each page | Breadcrumb reflects current location | | |
| CC4.4 | Toast notifications | Trigger success and error on each module | Toast appears top-right, auto-dismisses after 4s | | |
| CC4.5 | No console errors | Open DevTools → Console on all 6 modules | Zero red console errors across all pages | | |
| CC4.6 | CSRF on all POSTs | Open Network tab → Check POST request headers | Every POST includes `ci_csrf_token` in payload | | |

## CC-5 — Firebase Data Integrity Final Check

> Run this at the end of all testing

| # | Path | Check | Status | Notes |
|---|------|-------|--------|-------|
| CC5.1 | `Users/Schools/` | All test schools exist with correct structure | | |
| CC5.2 | `System/Schools/` | SA metadata nodes match `Users/Schools/` | | |
| CC5.3 | `School_ids/` | All school codes map to correct school names | | |
| CC5.4 | `System/Plans/` | All plan nodes have 15-module `modules` object | | |
| CC5.5 | `System/Payments/` | Payment records have `school_name`, `amount`, `status`, `invoice_date` | | |
| CC5.6 | `System/Backups/` | Each backup entry has `filename`, `backup_type`, `size_bytes`, `created_at` | | |
| CC5.7 | `System/BackupSchedule/` | If enabled=true, `cron_key` is present and non-empty | | |
| CC5.8 | `System/Stats/Summary/` | `updated_at` reflects last refresh. All count fields present | | |
| CC5.9 | `RateLimit/SA/` | No stale lockout entries blocking legitimate access after testing | | |

---

---

# FINAL SIGN-OFF

## Overall Summary

| Module | Total Steps | Pass | Fail | Partial | Skipped | Pass Rate |
|--------|------------|------|------|---------|---------|-----------|
| 1. Super Admin Auth | 17 | | | | | |
| 2. School Onboarding | 21 | | | | | |
| 3. Subscription Plans | 26 | | | | | |
| 4. Global Dashboard | 21 | | | | | |
| 5. System Monitoring | 29 | | | | | |
| 6. Backup Management | 42 | | | | | |
| Cross-Cutting | 25 | | | | | |
| **TOTAL** | **181** | | | | | |

## Issues Found

| Issue # | Module | Step # | Description | Severity (Critical/High/Medium/Low) | Status |
|---------|--------|--------|-------------|-------------------------------------|--------|
| | | | | | |
| | | | | | |
| | | | | | |
| | | | | | |

## Severity Definitions
| Level | Meaning |
|-------|---------|
| **Critical** | System crash, data loss, security breach, cannot proceed |
| **High** | Core feature broken, data incorrect, major UX blocker |
| **Medium** | Feature partially broken, workaround exists |
| **Low** | Cosmetic, minor text, non-blocking UX issue |

## Sign-Off

| | Name | Signature | Date |
|---|------|-----------|------|
| **Tester** | | | |
| **Reviewer** | | | |
| **Approved By** | | | |

**Overall QA Result:** ☐ PASS &nbsp;&nbsp; ☐ PASS WITH CONDITIONS &nbsp;&nbsp; ☐ FAIL

**Conditions / Notes:**

_______________________________________________________________________________

_______________________________________________________________________________

_______________________________________________________________________________
