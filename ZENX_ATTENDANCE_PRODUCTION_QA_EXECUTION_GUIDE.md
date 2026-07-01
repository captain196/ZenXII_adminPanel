# ZenX ERP — Attendance Production QA Execution Guide
## Firestore-only validation gate before RTDB removal
### For a tester with NO prior project knowledge. Follow top to bottom. Record PASS / FAIL / Requires Investigation for every step.

> **Golden rule:** Do NOT change code, do NOT remove RTDB, do NOT run any backfill. This is validation only. If anything fails, capture evidence and mark FAIL — do not "fix" it.

---

## 0. Reference facts (constants)
| Item | Value |
|---|---|
| Firebase project | `graderadmin` |
| Test school | `SCH_D94FE8F7AD` (school code `10001`) |
| Backend (PC/local) | `http://localhost/ZenX/school/` |
| Backend (from USB-tethered phone) | `http://localhost:8080/ZenX/school/` (needs `adb reverse tcp:8080 tcp:80`) |
| Teacher account | `STA0001` — role **Teacher**, status **Active** |
| Inactive staff account | any staff with status ≠ Active (for rejection test) |
| Admin account | School Super Admin (e.g. "Shivam Rathore") |
| Test students (have data) | `STU0001`, `STU0004`, `STU0005` |
| Teacher APK package | `com.schoolsync.teacher` |
| Parent APK package | `com.schoolsync.parent` |
| Firestore collections | `attendance` (`{schoolId}_{date}_{studentId}`), `attendanceSummary` (`{schoolId}_{studentId}_{YYYY-MM}`, field `dayWise`), `staffAttendance`, `staffAttendanceSummary`, `attendancePunches` |
| dayWise codes | P=Present, T=Late, A=Absent, L=Leave, H=Holiday, V=Vacant |

**Firestore evidence tool (run in a terminal at the backend folder):**
```
php index.php staff_role_check coverage   <schoolId>            # attendance vs attendanceSummary consistency
php index.php staff_role_check attendance <staffId> <schoolId> <YYYY-MM-DD>   # staff day + summary + punches
php index.php staff_role_check report     <staffId> <schoolId>  # staff monthly summaries
```
Capture the console output as evidence.

---

## 1. Environment preparation
1.1 On the PC: confirm XAMPP/Apache is running → open `http://localhost/ZenX/school/` in a browser; you should get the login page (**PASS** if it loads).
1.2 Confirm Firebase console access to project **graderadmin** → Firestore Database visible.
1.3 Confirm test users exist:
   - Firebase Auth → `STA0001` has custom claims `role=Teacher`, `school_id=SCH_D94FE8F7AD`. **(PASS/FAIL)**
   - One staff with status ≠ Active exists.
1.4 Android device: USB-connect, enable USB debugging. On PC run:
   ```
   adb devices                       # device must be listed
   adb reverse tcp:8080 tcp:80       # tunnel (re-run after any unplug/reboot)
   adb reverse --list                # confirm "tcp:8080 tcp:80"
   ```
1.5 Record: backend URL used, device model, APK versions (Teacher + Parent), tester name, date.

**Evidence:** screenshot of login page, `adb devices`/`adb reverse --list` output, Auth claims screenshot.

---

## 2. Parent App deployment & verification
2.1 **Install the NEW Firestore build:** if an older build exists, uninstall first:
   ```
   adb uninstall com.schoolsync.parent        # if signature mismatch
   ```
   Install the new APK (or `gradlew -p <ParentAppDir> installDebug`). Confirm `Installed on 1 device`.
2.2 **Login** as a parent of a test student (e.g., parent of `STU0001`). **(PASS/FAIL: login succeeds)**
2.3 **Dashboard attendance:** open the dashboard → attendance tile shows a % and today's status. **(PASS/FAIL)**
2.4 **Attendance calendar:** open the Attendance screen → the month calendar shows per-day marks matching Firestore `attendanceSummary.dayWise`. **(PASS/FAIL)**
2.5 **Current month:** verify the current month's marks render.
2.6 **Previous month:** switch to previous month → marks render (sourced from Firestore).
2.7 **Attendance percentage:** the % shown matches `attendanceSummary.percentage` for the month.

**Evidence:** app screenshots of dashboard + calendar (current + previous month) + the matching Firestore `attendanceSummary` doc for that student/month.

---

## 3. Teacher App verification
3.1 **Login** as `STA0001`. **(PASS/FAIL)**
3.2 **GPS Check-In (My Attendance):**
   - Admin first configures GPS geofence (see 4.0). Stand inside the geofence.
   - My Attendance → refresh GPS → "Distance from school" shows metres → tap **Check In**.
   - Expect **"Checked in." → status P** (or **T** if past late threshold). **(PASS/FAIL)**
   - Verify server-side: `php index.php staff_role_check attendance STA0001 SCH_D94FE8F7AD <today>` → shows `staffAttendanceSummary` day=P, a `staffAttendance` doc, and an `attendancePunches` row (outcome=accepted, distance, accuracy). **(PASS/FAIL)**
3.3 **GPS Check-Out:** tap **Check Out** → "Attendance completed" → re-run the probe → a second `attendancePunches` row `dir=out`, status still **P**. **(PASS/FAIL)**
3.4 **Attendance History / status:** My Attendance shows today + 30-day history + month counts (from Firestore). **(PASS/FAIL)**
3.5 **GPS rejection scenarios** (each should give a clear message, no crash):
   - Outside geofence → `outside_geofence`.
   - Mock-location app on → `mock_location`.
   - Poor accuracy (obstructed sky) → `poor_accuracy`.
   - Outside check-in window (adjust window) → `window_closed`.
   - Each rejection writes an `attendancePunches` row `outcome=rejected` with the reason. **(PASS/FAIL each)**
3.6 **Lock behaviour:** admin locks the month (Attendance → Control → Locks) → Teacher app marking / punch for that month is refused (`month_locked`). **(PASS/FAIL)**

**Evidence:** app screenshots per scenario + the matching `attendancePunches`/probe output.

---

## 4. Admin Panel verification (browser)
**4.0 GPS policy setup (prerequisite for §3):** Admin → **Attendance → GPS Attendance** tab → Enable GPS ON, set Campus lat/lng (= tester's location), Radius 200, Max Accuracy 100, Mock OFF, windows wide for testing → **Save GPS Policy**. **(PASS/FAIL saved)**

4.1 **Single-day attendance:** Attendance → Student → pick class/section/month → set one student's day (e.g. `STU0001`, a working day) to **A** → Save.
   - Verify Firestore: `attendance` doc `{SCH_D94FE8F7AD}_{date}_{STU0001}` status=A **and** `attendanceSummary` `{...}_{STU0001}_{YYYY-MM}`.dayWise[day]=A. **(PASS/FAIL)**
4.2 **Bulk attendance:** mark a whole section Present for a day → verify several students' `attendanceSummary` updated. **(PASS/FAIL)**
4.3 **Attendance correction:** submit a backdated correction (past day) → it enters pending. **(PASS/FAIL)**
4.4 **Attendance approval:** Attendance → Control → Corrections → approve it → verify the target day updates in `attendanceSummary`. **(PASS/FAIL)**
4.5 **Leave:** apply + approve a student leave for a day → `attendanceSummary`.dayWise[day]=**L**. **(PASS/FAIL)**
4.6 **Dashboard:** Attendance dashboard → present/absent/late counts reflect today's marks; loads within a few seconds. **(PASS/FAIL)**
4.7 **Analytics:** Attendance → Analytics → load month → class-wise + Individual Report (enter `STU0001`) show correct figures from `attendanceSummary`. **(PASS/FAIL)**
4.8 **Punch Log:** Attendance → Punch Log → load today's date → GPS staff punches show Name, ID, Direction, Outcome, Accuracy, Distance, Mock, Location, Device. **(PASS/FAIL)**
4.9 **Report Card:** generate a class result / report card for the section → attendance % appears per student (sourced from Firestore `attendanceSummary`). **(PASS/FAIL)**

**Evidence:** screenshot of each admin screen + the matching Firestore doc (Firebase console) for at least one student.

---

## 5. Cross-system propagation (THE key test)
**Make ONE change, then verify it appears in all 8 surfaces.**
5.1 As Admin, mark `STU0001` on a specific working day (e.g. today) as **A** (single-day).
5.2 Verify propagation, recording PASS/FAIL for each:
   1. **`attendance`** — Firebase console: doc `{SCH_D94FE8F7AD}_{date}_STU0001` status=A.
   2. **`attendanceSummary`** — doc `{...}_STU0001_{YYYY-MM}`.dayWise[day]=A, counts updated.
   3. **Teacher App** — teacher's student view of STU0001 shows A for that day.
   4. **Parent App** — STU0001's parent dashboard/calendar shows A (re-open app to refresh).
   5. **Dashboard** — absent count reflects it.
   6. **Analytics** — Individual Report for STU0001 shows the A.
   7. **Reports** — class result attendance % for STU0001 reflects it.
   8. **Report Card** — rendered report card % reflects it.
5.3 (Optional) Run `php index.php staff_role_check coverage SCH_D94FE8F7AD` → still 0 mismatch.

**PASS only if all 8 reflect the single change.** Capture a screenshot/doc for each of the 8.

---

## 6. Coverage verification (per active school)
For **every** active school, run:
```
php index.php staff_role_check coverage <schoolId>
```
Record for each school: `checked / match / mismatch / missingSummaryDoc / dayWiseTooShort` and **PASS** (0 gaps) or **FAIL** (any gap).
- If **FAIL:** note the printed MISMATCH lines (studentId + date) and the affected months. **Do NOT run any backfill.** Just record.

| School ID | checked | match | mismatch | missingSummary | shortDayWise | Result |
|---|---|---|---|---|---|---|
| SCH_D94FE8F7AD | | | | | | |
| … (each active school) | | | | | | |

---

## 7. Performance verification
Measure and record (use a stopwatch or dev tools Network tab):
| Metric | How | Target | Actual | PASS? |
|---|---|---|---|---|
| Check-In latency | tap → result banner | < 3 s | | |
| Check-Out latency | tap → result | < 3 s | | |
| Dashboard load | open Attendance dashboard → cards filled | < 4 s | | |
| Parent App load | cold open → dashboard attendance visible | < 4 s | | |
| Teacher App load | cold open → My Attendance visible | < 4 s | | |
| Bulk mark (full section) | Save → success | < 8 s | | |

---

## 8. Security verification
| # | Test | Steps | Expected | PASS? |
|---|---|---|---|---|
| 8.1 | Cross-school isolation | As School A admin/token, attempt to read School B `attendanceSummary`/`attendancePunches` (Firebase rules simulator or a B doc) | **Denied** | |
| 8.2 | Disabled-staff rejection | Punch as the Inactive staff account (valid token) | **403** `staff_inactive`, rejected audit row | |
| 8.3 | Invalid token rejection | Call `POST /staff_attendance/punch` with a bad/expired Bearer token | **401** | |
| 8.4 | Wrong-school rejection | Use a token whose `school_id` ≠ the data's school | Denied / no cross-tenant data | |
| 8.5 | Mock-GPS rejection | Enable a mock-location app, Check In | **Rejected** `mock_location`, audit row `mock=yes` | |

**Evidence:** response codes/bodies + the rejected `attendancePunches` rows.

---

## 9. Final QA Sign-Off Sheet
Mark each: **PASS / FAIL / Requires Investigation**. Attach evidence reference per row.

| Area | Test | Result | Evidence ref |
|---|---|---|---|
| Env | Backend reachable; claims; tunnel | | |
| Parent | Install / login | | |
| Parent | Dashboard attendance | | |
| Parent | Calendar (current) | | |
| Parent | Calendar (previous month) | | |
| Parent | Percentage matches Firestore | | |
| Teacher | Login | | |
| Teacher | GPS Check-In → P/T | | |
| Teacher | GPS Check-Out | | |
| Teacher | History / status | | |
| Teacher | Rejections (geofence/mock/accuracy/window) | | |
| Teacher | Lock behaviour | | |
| Admin | Single-day | | |
| Admin | Bulk | | |
| Admin | Correction submit | | |
| Admin | Correction approval | | |
| Admin | Leave → L | | |
| Admin | Dashboard | | |
| Admin | Analytics / Individual Report | | |
| Admin | Punch Log (GPS evidence) | | |
| Admin | Report Card % | | |
| Cross-system | 1 mark → all 8 surfaces | | |
| Coverage | Every active school 0 gaps | | |
| Performance | All metrics within target | | |
| Security | 8.1–8.5 all pass | | |

**QA Sign-off:**
- Tester: __________ Date: ______
- Result: ALL PASS / FAILURES FOUND (list): ______________________
- Recommendation to release owner: proceed to RTDB removal decision **only if ALL mandatory rows = PASS**, coverage = 0 gaps for every active school, and the Parent app new build is confirmed on the test devices.

---

*This is a validation guide only. No code was changed, no RTDB removed, no data migrated. Return the completed sheet + evidence to the release owner for the RTDB removal decision.*
