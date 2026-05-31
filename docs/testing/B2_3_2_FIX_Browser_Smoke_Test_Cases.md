# B2.3.2-FIX R5 — Browser Smoke Test Cases

**Module:** B2.3.2 Super Admin Panel (Firestore-authoritative)
**Build:** B2.3.2-FIX R1-R5 applied + reactivated 2026-05-31
**Flag state:** `b2.registry_firestore = true`, `b2.reports_firestore = false`
**Test tenant:** `ZZ B1 Soak Test` (schoolId=`SCH_9C7986EA3E`, code=`10002`)
**Production tenant (DO NOT MODIFY):** `IIT Kanpur` (schoolId=`SCH_D94FE8F7AD`, code=`10001`)

---

## Pre-test setup (one-time)

| # | Step | Expected |
|---|---|---|
| 0.1 | Open Chrome / Edge. Navigate to `http://localhost/Grader/school/superadmin/login` | Login page renders |
| 0.2 | Log in as `SUP0001` with your SA password | Land on `/superadmin/dashboard` |
| 0.3 | Confirm flag state via dev-CLI: `grep "b2.registry_firestore" application/config/b2_migration_flags.php` should show `=> true,` | Confirms reactivated state |
| 0.4 | Open browser DevTools (F12) → Network tab → "Preserve log" checked | For API response inspection during tests |
| 0.5 | Identify a second browser profile / Incognito for later B13 testing | Needed because SSA login wipes SA session (unrelated bug) |

**Time budget:** ~15 minutes for B2–B10 (skipping B11–B13). ~30 minutes including B11–B13.

---

## B2 — Schools List

**Objective:** Verify the SA Schools list page reads from Firestore canonical and renders both tenants with resolved plan names.

**Pre-condition:** Logged in as SUP0001. On SA dashboard.

**URL:** `http://localhost/Grader/school/superadmin/schools`

### Steps

| # | Action | Expected Result | Pass Criterion |
|---|---|---|---|
| B2.1 | Click **"All Schools"** in left sidebar | Page navigates to `/superadmin/schools` | URL changes; page loads within 3 seconds |
| B2.2 | Wait for page render | Schools table appears with column headers | Table is visible with headers: Name, Code, City, Plan, Status, Expiry, Students, Staff |
| B2.3 | Count rows | Exactly **2 rows** | Two tenant rows present |
| B2.4 | Inspect row 1 | Name: `IIT Kanpur`, Code: `10001`, Plan: `Premium`, Status: badge "active" (green) | All four fields populated correctly |
| B2.5 | Inspect row 2 | Name: `ZZ B1 Soak Test`, Code: `10002`, Plan: `Standard`, Status: badge "active" (green) | All four fields populated correctly |
| B2.6 | Inspect Plan column for both | Shows `"Premium"` and `"Standard"` (NOT raw `"PLAN_7FBE49"` or `"PLAN_2E596A"`) | Plan names resolved, not raw IDs |
| B2.7 | Open DevTools → Network tab; reload page | Should see XHR request(s) returning HTTP 200 | No 5xx errors |
| B2.8 | DevTools → Network → click the schools-list XHR → Response tab | JSON contains a `schools` or `rows` array with 2 elements | Response shape valid |

### What to watch for (FAIL signals)

- ❌ Empty table — "No schools found"
- ❌ Plan column shows raw `PLAN_2E596A` instead of `Standard`
- ❌ Status column blank or showing `lifecycle.state` literal text
- ❌ Browser console errors (red lines in DevTools Console)
- ❌ Status badge color wrong (should be green for "active")

### PASS criteria (all must be true)

- ✅ 2 rows visible
- ✅ All fields populated
- ✅ Plan names resolved (Premium, Standard)
- ✅ Status badges green
- ✅ No console errors

---

## B3 — School Detail Page

**Objective:** Verify the per-school detail view loads composite data (profile + subscription + stats) from Firestore canonical.

**Pre-condition:** B2 passed. On Schools list page.

**URL:** `http://localhost/Grader/school/superadmin/schools/view/SCH_D94FE8F7AD`
(or click into IIT Kanpur row from B2)

### Steps

| # | Action | Expected Result | Pass Criterion |
|---|---|---|---|
| B3.1 | Click into **IIT Kanpur** row from Schools list | Navigates to `/superadmin/schools/view/SCH_D94FE8F7AD` | URL changes |
| B3.2 | Page loads | Detail page renders with multiple sections | Page renders within 3 seconds |
| B3.3 | Check page header | Shows tenant name `IIT Kanpur` + schoolId `SCH_D94FE8F7AD` + status badge | Header populated |
| B3.4 | Check **Profile** section | Name, email, phone, city, street fields visible (may be empty if not set) | Section present, fields rendered |
| B3.5 | Check **Subscription** section | Plan: `Premium`; Expiry: `2027-04-02`; Status: `active` | Subscription data correct |
| B3.6 | Check **Stats Cache** panel | Total Students / Total Staff visible (likely 0/0 if no academic data); Last Updated timestamp present | Stats panel visible |
| B3.7 | Check **Plan Dropdown** (for reassignment) | Dropdown populated with 4 plan names: Basic, Standard, Premium, Sample | Dropdown not empty, names resolved |
| B3.8 | DevTools → Network → check XHR for this page | HTTP 200, JSON contains `school` object | No errors |

### Then test the second tenant

| B3.9 | Navigate back to Schools list, click **ZZ B1 Soak Test** | Detail page loads | URL: `/superadmin/schools/view/SCH_9C7986EA3E` |
| B3.10 | Check subscription | Plan: `Standard`, Expiry: `2027-05-30`, Status: `active` | Different plan from IIT Kanpur |
| B3.11 | Stats panel | Last Updated timestamp visible | Populated |

### What to watch for (FAIL signals)

- ❌ Redirect back to schools list (would indicate "school not found")
- ❌ Plan field shows raw `PLAN_2E596A` instead of `Standard`
- ❌ Subscription section empty
- ❌ Plan dropdown empty or shows raw IDs

### PASS criteria

- ✅ Both detail pages render
- ✅ Plan names resolved
- ✅ Subscription dates correct
- ✅ Plan dropdown populated

---

## B4 — Subscriptions Tab

**Objective:** Verify the subscription tracking view aggregates tenant state correctly and buckets them by lifecycle.

**Pre-condition:** B3 passed. Logged in as SA.

**URL:** `http://localhost/Grader/school/superadmin/plans/subscriptions`

### Steps

| # | Action | Expected Result | Pass Criterion |
|---|---|---|---|
| B4.1 | In left sidebar, expand **Plans & Billing** | Sub-menu opens | Sub-menu visible |
| B4.2 | Click **Subscriptions** | Page navigates | URL: `/superadmin/plans/subscriptions` |
| B4.3 | Wait for page render | Bucket-count cards at top + table below | Page renders within 3 seconds |
| B4.4 | Check bucket counts at top of page | **Active: 2**, Grace: 0, Expired: 0, Suspended: 0, Inactive: 0, Expiring soon: 0 | Active=2; others=0 |
| B4.5 | Scroll to table | Two rows: IIT Kanpur + ZZ B1 Soak Test | Both visible |
| B4.6 | Check each row for "Display" / "Status" classification | Both should show `"active"` badge | Both classified correctly |
| B4.7 | Check "Days Remaining" column | Positive numbers: IIT Kanpur ~306 days, ZZ B1 ~363 days | Days remaining visible and positive |
| B4.8 | Check "Plan" column | Premium + Standard (NOT raw IDs) | Plan names resolved |
| B4.9 | Check "Expiry Date" column | 2027-04-02 (IIT) + 2027-05-30 (ZZ) | Dates visible |

### What to watch for (FAIL signals)

- ❌ All buckets show 0 (Bug E symptom — would mean lifecycle.state isn't being read)
- ❌ Both tenants in "Inactive" bucket instead of "Active"
- ❌ Days remaining negative or "N/A"
- ❌ Plan column raw IDs

### PASS criteria

- ✅ Active bucket = 2
- ✅ All other buckets = 0
- ✅ Days remaining positive
- ✅ Plan names resolved

---

## B5 — Payments Tab

**Objective:** Verify the payments list page loads and the school-name join works (even with zero payments).

**Pre-condition:** B4 passed.

**URL:** `http://localhost/Grader/school/superadmin/plans/payments`

### Steps

| # | Action | Expected Result | Pass Criterion |
|---|---|---|---|
| B5.1 | In left sidebar, **Plans & Billing** → **Payments** | Page navigates | URL: `/superadmin/plans/payments` |
| B5.2 | Wait for page render | Filter bar + empty table | Page renders within 3 seconds |
| B5.3 | Check **School filter dropdown** at top | Populated with 2 tenants by name: `IIT Kanpur`, `ZZ B1 Soak Test` | Dropdown not empty, names not raw IDs |
| B5.4 | Check **Plan filter dropdown** at top | Populated with 4 plans: Basic, Standard, Premium, Sample | Dropdown not empty |
| B5.5 | Check the payments table | Empty (acceptable — no payments collected yet); table headers visible | "No payments" message OR empty table with headers |
| B5.6 | Click into one tenant's row from the Schools list (in a new tab) → "Payments" section | Per-tenant payment list (empty); no errors | No crash |

### What to watch for (FAIL signals)

- ❌ Dropdowns empty
- ❌ Tenants in dropdown show as `SCH_XXXXXXXXXX` raw IDs instead of names
- ❌ Plan dropdown shows raw `PLAN_*` IDs
- ❌ Console errors

### PASS criteria

- ✅ Both filter dropdowns populated with names (not raw IDs)
- ✅ Empty table or empty list with headers (acceptable since no payments)
- ✅ No console errors

---

## B6 — Plans List

**Objective:** Verify the plans list renders and the per-plan school-count is correct (this is the `count_schools_on_plan` test).

**Pre-condition:** B5 passed.

**URL:** `http://localhost/Grader/school/superadmin/plans`

### Steps

| # | Action | Expected Result | Pass Criterion |
|---|---|---|---|
| B6.1 | In left sidebar, **Plans & Billing** → **All Plans** (or **Manage Plans** from dashboard Quick Actions) | Page navigates | URL: `/superadmin/plans` |
| B6.2 | Wait for page render | Plans grid/table with 4 plans | Page renders within 3 seconds |
| B6.3 | Count plan cards/rows | Exactly **4 plans** visible | Count = 4 |
| B6.4 | Identify each plan | Plans visible: Basic, Standard, Premium, Sample | All 4 plan names present |
| B6.5 | Check **"Schools on plan"** column / counter for each | Standard: **1**, Premium: **1**, Basic: 0, Sample: 0 | Standard=1, Premium=1 (NOT all zeros) |
| B6.6 | Check **Price** column | Visible: Basic ₹5,000; Standard ₹12,000; Premium ₹25,000; Sample ₹100 | Prices visible |
| B6.7 | Check **Billing Cycle** column | annual / annual / annual / monthly | Cycles visible |
| B6.8 | Try to click a plan to view its details (if supported) | Either modal or detail page | No errors |

### What to watch for (FAIL signals)

- ❌ "Schools on plan" shows 0 for EVERY plan (the count_schools_on_plan bug surface from earlier B2.3.2 versions)
- ❌ Only 1-2 plans visible instead of 4
- ❌ Plans show raw IDs instead of names

### PASS criteria

- ✅ 4 plans visible
- ✅ Counts: Standard=1, Premium=1 (at minimum these two non-zero)
- ✅ Prices + cycles visible
- ✅ No console errors

---

## B7 — Create School Wizard (FORM ONLY, do NOT submit)

**Objective:** Verify the onboarding wizard loads with a populated plan dropdown. We do NOT submit because B11 covers the full submission flow.

**Pre-condition:** B6 passed.

**URL:** `http://localhost/Grader/school/superadmin/schools/create`

### Steps

| # | Action | Expected Result | Pass Criterion |
|---|---|---|---|
| B7.1 | Click **"Onboard School"** in left sidebar OR **"+ Onboard New School"** button on dashboard | Wizard form opens | URL: `/superadmin/schools/create` |
| B7.2 | Wait for form to render | Multi-step form with Profile / Admin / Plan / Session fields | Form fully rendered |
| B7.3 | Inspect each step field set | Step 1: School Name, Email, City, Street, Phone, Logo. Step 2: Admin Name, Admin Email, Admin Password. Step 3: Plan dropdown, Expiry Date, Session Year | All fields visible |
| B7.4 | Open the **Plan dropdown** | 4 options displayed: Basic, Standard, Premium, Sample (NOT raw `PLAN_2E596A` etc.) | Dropdown populated with names |
| B7.5 | DO NOT submit. Close/cancel the wizard | Returns to previous page or dashboard | Form closes cleanly |

### What to watch for (FAIL signals)

- ❌ Wizard fails to load
- ❌ Plan dropdown empty
- ❌ Plan dropdown shows raw IDs
- ❌ Form fields missing

### PASS criteria

- ✅ Wizard loads
- ✅ Plan dropdown populated with 4 named plans
- ✅ All form fields visible
- ✅ Wizard cancels cleanly

---

## B8 — Profile Edit (round-trip — change then revert)

**Objective:** Verify the profile edit write hits Firestore (`schools/{id}.city` field) and persists across reloads.

**Pre-condition:** B7 passed. On detail page of ZZ B1 Soak Test.

⚠️ **Always use the test tenant `ZZ B1 Soak Test` (SCH_9C7986EA3E). Never IIT Kanpur.**

**URL:** `http://localhost/Grader/school/superadmin/schools/view/SCH_9C7986EA3E`

### Test data

- **Test value:** `"Smoke Test City 2026-05-31"`
- **Original value:** Record what's currently in the City field before changing (likely `""` or `"Test"`)

### Steps

| # | Action | Expected Result | Pass Criterion |
|---|---|---|---|
| B8.1 | Open ZZ B1 Soak Test detail page | Detail page loads | Page renders |
| B8.2 | **RECORD CURRENT CITY VALUE** for reverting later | Note down e.g. "Test" or "" | Documented |
| B8.3 | Click **"Edit Profile"** button | Modal/form opens with current profile values | Form loads |
| B8.4 | Change **City** field to `"Smoke Test City 2026-05-31"` | Field value updates | Input accepted |
| B8.5 | Click **"Save"** | Success toast: "Profile updated" (or similar) | Toast appears |
| B8.6 | Wait 2 seconds, then **refresh the page** (F5) | Page reloads | Reload completes |
| B8.7 | Check City field on detail page | Shows `"Smoke Test City 2026-05-31"` (persisted) | Value persisted (this proves write hit Firestore) |
| B8.8 | Click **"Edit Profile"** again | Modal opens showing new value | Form loads |
| B8.9 | Change **City** back to original value from B8.2 | Field updated | Input accepted |
| B8.10 | Click **"Save"** | Success toast | Toast appears |
| B8.11 | Refresh page (F5) | Page reloads | Reload completes |
| B8.12 | Check City field | Back to original | Revert persisted |

### What to watch for (FAIL signals)

- ❌ Save shows success toast but reload still shows OLD value (Bug E symptom — write didn't hit Firestore nested field)
- ❌ Save fails with error
- ❌ City field empty after save
- ❌ Revert doesn't persist

### PASS criteria

- ✅ B8.7: change persisted after F5
- ✅ B8.12: revert persisted after F5

### Optional advanced check

If you want to be extra rigorous, run via CLI before and after each save:

```bash
node -e "const a=require('./functions/node_modules/firebase-admin');const sa=require('./application/config/graderadmin-firebase-adminsdk-a1sml-2b5f1862a7.json');a.initializeApp({credential:a.credential.cert(sa)});a.firestore().collection('schools').doc('SCH_9C7986EA3E').get().then(d=>{console.log('city:',d.data().city);console.log('updatedAt:',d.data().updatedAt);process.exit(0);});"
```

Should show the city updating after B8.5 and reverting after B8.10.

---

## B9 — Status Toggle (suspend → reactivate)

**Objective:** Verify the suspend operation hits BOTH `schools.adminDisabled` (top-level map) AND `schoolControl.lifecycle.state` (dotted-key nested write — the R5 case). This is the test that exposed Bug E originally.

**Pre-condition:** B8 passed. On ZZ B1 Soak Test detail page.

⚠️ **TEST TENANT ONLY. Never run this on IIT Kanpur.**

### Pre-state confirmation

| # | Action | Expected | Pass Criterion |
|---|---|---|---|
| B9.0 | Confirm current status of ZZ B1 Soak Test | Status badge shows **"Active"** (green) | Starting state confirmed active |

### Test: suspend

| # | Action | Expected Result | Pass Criterion |
|---|---|---|---|
| B9.1 | Click **"Suspend"** button on ZZ B1 Soak Test detail page | Confirmation dialog appears | Dialog visible |
| B9.2 | Confirm suspension (click OK / Yes) | Success toast appears: "School suspended" or similar | Toast appears |
| B9.3 | Page may auto-reload — if not, **press F5** | Page reloads | Reload completes |
| B9.4 | **Check status badge** | Shows **"Suspended"** (red or amber color) — NOT "Active" | Badge persisted as Suspended |
| B9.5 | Navigate to Schools list (`/superadmin/schools`) | List loads | List visible |
| B9.6 | Find ZZ B1 Soak Test row | Status column shows **"Suspended"** | Status persisted across navigation |
| B9.7 | Navigate to Subscriptions tab (`/superadmin/plans/subscriptions`) | Page loads | Page renders |
| B9.8 | Check bucket counts | Now: Active=**1**, Suspended=**1** (NOT Active=2) | Bucket reflects the suspension |

### Test: reactivate

| # | Action | Expected Result | Pass Criterion |
|---|---|---|---|
| B9.9 | Navigate back to ZZ B1 Soak Test detail page | Page loads showing Suspended status | Confirmed suspended |
| B9.10 | Click **"Activate"** button | Confirmation dialog | Dialog visible |
| B9.11 | Confirm activation | Success toast | Toast appears |
| B9.12 | Press F5 | Page reloads | Reload completes |
| B9.13 | Status badge | Shows **"Active"** (green) | Activation persisted |
| B9.14 | Schools list | ZZ B1 Soak Test status column shows **"Active"** | Activation visible in list |
| B9.15 | Subscriptions tab buckets | Back to: Active=**2**, all others=0 | Buckets restored |

### What to watch for (FAIL signals — the Bug E symptoms)

- ❌ **B9.4 critical:** Status flips momentarily to "Suspended" but reverts to "Active" on F5 (would mean lifecycle.state never persisted — Bug E)
- ❌ **B9.6:** Schools list shows tenant as still "Active" after suspension
- ❌ **B9.8:** Bucket counts don't change after suspension (Active still = 2)
- ❌ **B9.13:** Same reversion symptom on reactivate
- ❌ Console errors during the toggle

### PASS criteria (the most important test)

- ✅ B9.4: Status persists as Suspended after F5
- ✅ B9.6: Status visible as Suspended in list view
- ✅ B9.8: Subscriptions bucket count reflects suspension (Active=1, Suspended=1)
- ✅ B9.13: Reactivation persists
- ✅ B9.15: Buckets back to Active=2

### Why this is THE critical test

The B9 case ran on the pre-R5 build and surfaced Bug E. Both `schools.adminDisabled.value` (nested map) AND `schoolControl.lifecycle.state` (dotted nested path) must update together. After R5, the dotted-path write lands correctly. If B9 PASSES here, R5 is working in the live HTTP request flow — strongest validation of the fix.

---

## B10 — Refresh Stats

**Objective:** Verify the stats cache refresh writes `schools.statsCache.{totalStudents, totalStaff, lastUpdated}` to Firestore (nested map write).

**Pre-condition:** B9 passed. On ZZ B1 Soak Test detail page.

### Steps

| # | Action | Expected Result | Pass Criterion |
|---|---|---|---|
| B10.1 | Open ZZ B1 Soak Test detail page | Detail page loads | Page renders |
| B10.2 | Find **Stats Cache** panel | Visible with `Total Students: 0`, `Total Staff: 0`, `Last Updated: <some timestamp>` | Panel visible |
| B10.3 | **Record current "Last Updated" timestamp** | Note it down (e.g. `2026-05-31T05:43:53+02:00`) | Documented |
| B10.4 | Click **"Refresh Stats"** button | Loading spinner → success toast: "Stats refreshed" or similar with student/staff counts | Toast appears |
| B10.5 | Press F5 to reload | Page reloads | Reload completes |
| B10.6 | Check **Last Updated** timestamp | Shows newer timestamp (closer to current time) than what you recorded in B10.3 | Timestamp updated |
| B10.7 | Check Total Students / Total Staff values | Still 0/0 (no academic data) — this is fine | Numbers are 0 (acceptable) |

### What to watch for (FAIL signals)

- ❌ Last Updated timestamp doesn't change after refresh (write didn't persist)
- ❌ Refresh button shows error toast
- ❌ Console error during refresh

### PASS criteria

- ✅ Last Updated timestamp updates to current time
- ✅ Persists across F5 reload
- ✅ No console errors

---

## Final summary table — record results here

Use this as your QA checklist. Fill in PASS/FAIL/Notes per test:

| Test | Result | Notes / Anomalies |
|---|---|---|
| B2 Schools List | ☐ PASS ☐ FAIL | |
| B3 School Detail | ☐ PASS ☐ FAIL | |
| B4 Subscriptions | ☐ PASS ☐ FAIL | |
| B5 Payments | ☐ PASS ☐ FAIL | |
| B6 Plans List | ☐ PASS ☐ FAIL | |
| B7 Create School (form) | ☐ PASS ☐ FAIL | |
| B8 Profile Edit | ☐ PASS ☐ FAIL | |
| B9 Status Toggle | ☐ PASS ☐ FAIL | ⭐ Critical test |
| B10 Refresh Stats | ☐ PASS ☐ FAIL | |

### Decision matrix

| Overall result | Action |
|---|---|
| **All 9 PASS** | Declare B2.3.2-FIX reactivation successful; begin 7-day soak; module-commit becomes eligible after soak passes |
| **B9 FAILS** | Critical Bug E regression — flip flag back to `false` immediately, dig deeper |
| **B8 or B10 FAILS** | Write-path regression — flip flag back, investigate `update_school_profile` / `update_stats_cache` |
| **B2/B3/B4 FAIL (empty data)** | Read-path regression — flip flag back, investigate `list_tenants_summary` |
| **B5/B6/B7 FAIL (empty dropdowns/plan list)** | `list_plans` regression — flip flag back, investigate |

### Single-line rollback (if needed)

File: `application/config/b2_migration_flags.php` line 35

```php
// REVERT:
'b2.registry_firestore'      => false,
```

Save. Next request → legacy RTDB code runs. Operator panels restored.

---

## Notes on what you should NOT see

(These would all be Bug E or earlier-bug symptoms — they SHOULD NOT appear after R5):

- ❌ Empty Schools list (the original B2.3.2 list_tenants_summary bug)
- ❌ Plan dropdown empty or showing raw `PLAN_*` IDs (the list_plans wrapper bug)
- ❌ "Schools on plan: 0" for every plan (the count_schools_on_plan bug)
- ❌ Subscription bucket counts all zero (the lifecycleState read bug)
- ❌ Status toggle that doesn't persist on F5 (Bug E)
- ❌ Profile edit that doesn't persist on F5
- ❌ PHP warnings about "Undefined array key 0/1/2" in the page or logs

If you see any of these → screenshot it, capture the URL, capture the DevTools console + network response, then notify and I'll rollback.

---

## Estimated total time

| Phase | Time |
|---|---|
| Pre-test setup (#0) | 5 min |
| B2 + B3 + B4 + B5 (read-only) | 5 min |
| B6 + B7 (read-only) | 3 min |
| B8 + B9 + B10 (writes + reverts) | 8 min |
| **Total (B2–B10)** | **~21 min** |
| (B11–B13 optional, adds another ~15 min) | (+15 min) |

---

**End of test document.** Save your results and report back. Single failure = rollback. All pass = soak begins.
