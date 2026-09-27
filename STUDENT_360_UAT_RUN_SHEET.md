# Student 360 — UAT Run Sheet (your tenant, your students, real numbers)

**Prepared 2026-08-11 from a read-only probe of `SCH_D94FE8F7AD`.**
Companion to `STUDENT_360_UAT_EXECUTION_GUIDE.md` — that one explains *why*; this one tells you
*exactly which student to open and what number should be on the screen*.

> **What I could and could not do.** I ran the entire **data layer** of this UAT with read-only
> Firestore probes — every cross-module number comparison, panel availability, and the no-write
> proof. I **cannot** run anything that needs a browser: clicking, role-switching with re-login,
> DevTools Network, screenshots, or judging layout at three viewports. Those are marked
> **BLOCKED — needs you**, never PASS.

---

## 0 · YOUR TEST STUDENTS (probe-selected, not guessed)

All 11 students in the tenant are **Active**. Open with `⋮ → Student 360` on the SIS list, or go
direct to `http://localhost/ZenX/school/student360/view/{ID}`.

| Slot | Student | Why this one |
|---|---|---|
| **A — the rich one** | **STU0001 · Hanuman Ji** · Class 8th/A | The only student with **discipline flags (4 active, 1 resolved)**, **20 documents**, **3 attendance months**, and **₹6,600 of receipts**. Most panels have real content. **No photo** → tests the initials fallback. |
| **B — services + entitlements** | **STU0159 · Konda Reddy** · Class 8th/A | The only student with **2 active entitlements** *and* **transport (South Circle, ₹1,400/mo)**. **Has a photo.** |
| **C — the D-02 case** | **STU0004 · Vikrant yadav** · Class 8th/A | **Largest archived-demand gap: ₹84,200.** Use this one for test 3.1.3. |
| **D — results** | **STU0006 · ygant** · Class 8th/A | 2 published results, 1 attendance month, has a photo. |
| **E — the odd one** | **STU0011 · ankittt** · Class 9th/A | **The only student NOT result-withheld**, despite the **highest** outstanding (₹47,000). See **F-3**. |

### ⚠️ Slots that CANNOT be filled — record as BLOCKED, do not manufacture data

| Needed | Reality | Consequence |
|---|---|---|
| An **inactive / TC'd** student | **0 exist** — all 11 Active | Tests 4.9, 5.10, 7.14 (inactive states, blocked Issue TC) **cannot run** |
| A **pending entitlement** | **0 exist** (0/0/0 for all 11) | Approvals block renders empty — tests 6.2–6.4 **cannot run** |
| A **parent request** | **0 exist** | Parent Requests panel + amber dot + R-5 approve/reject **cannot run** |
| A **hosteller** | none found | Hostel panel shows the empty state only |
| A **`studentDiscounts` record** | **0 in this tenant** | SD-1 **not reproducible** — expected and already agreed |

---

## 1 · EXPECTED NUMBERS — verified against Firestore this morning

Money zone opens by default. **Outstanding shown = Amount Due = Demand Outstanding** for every
student here (no discounts exist in this tenant).

| Student | Outstanding | Receipts Σ | Paid | Entitlements | Transport | Flags a/r | Results | Attend | Docs | Withheld | Photo |
|---|---|---|---|---|---|---|---|---|---|---|---|
| **STU0001** | **₹28,000.00** | ₹6,600.00 | ₹6,600.00 | 0 | none | **4/1** | 1 | 3 | **20** | YES | — |
| STU0004 | ₹34,600.00 | ₹0.00 | ₹0.00 | 0 | none | 0/0 | 2 | 2 | 0 | YES | — |
| STU0005 | ₹37,200.00 | ₹0.00 | ₹0.00 | 0 | none | 0/0 | 2 | 3 | 0 | YES | — |
| **STU0006** | ₹34,600.00 | ₹0.00 | ₹0.00 | 0 | none | 0/0 | **2** | 1 | 0 | YES | yes |
| STU0007 | ₹34,600.00 | ₹0.00 | ₹0.00 | 0 | none | 0/0 | 2 | 1 | 0 | YES | yes |
| STU0008/9/10 | ₹34,600.00 | ₹0.00 | ₹0.00 | 0 | none | 0/0 | 2 | 2 | 0 | YES | yes |
| **STU0011** | **₹47,000.00** | ₹0.00 | ₹0.00 | 0 | none | 0/0 | 0 | 1 | 0 | **—** | yes |
| **STU0159** | **₹35,500.00** | ₹500.00 | ₹500.00 | **2** | **South Circle ₹1,400** | 0/0 | 0 | 0 | 0 | YES | yes |
| STU0160 | ₹37,200.00 | ₹0.00 | ₹0.00 | 1 | none | 0/0 | 0 | 0 | 0 | YES | yes |

**If a screen shows a number not in this table, that is a defect — record both values.**

---

## 2 · STEP-BY-STEP — the tests you can actually run

### ▶ TEST 1 · Zone structure (5 min) — STU0001

1. Go to `http://localhost/ZenX/school/sis`
2. Find **Hanuman Ji (STU0001)** → click the **⋮** at the right of the row → **Student 360**
3. **Expect:** five zone bars, top to bottom — **Money · Academics · Student & Family · Services · Records**
4. **Expect:** **Money is already open**, showing the Fees panel
5. **Expect:** the other four are collapsed bars, each with a headline on the right:
   - Academics → `Result withheld`
   - Student & Family → `4 active flags`
   - Services → `No transport`
   - Records → `Class 8th` (admission no. is blank for this tenant)
6. ❌ **Fail if** a collapsed bar is empty, or fewer than 5 zones appear

### ▶ TEST 2 · Money numbers (5 min) — STU0001

1. Money zone is open on the **Fees** tab
2. **Expect exactly:** Active demands, Total billed, Collected **₹6,600.00**, Outstanding **₹28,000.00**
3. Click the **Receipts** tab → **Expect total ₹6,600.00**, newest first
4. ✅ **These two must match.** They do in the data — a mismatch on screen is a rendering defect
5. Click **Entitlements** → empty state (this student has none)

### ▶ TEST 3 · The D-02 evidence (5 min) — STU0004

1. Open `student360/view/STU0004`
2. **Expect Outstanding ₹34,600.00**
3. Open the Fees module ledger for the same student
4. ⚠️ **The ledger may show ₹1,18,800** — a **₹84,200** gap, because Student 360 excludes
   archived/cancelled demands and the ledger does not
5. **Record both numbers.** This is **D-02, already known** — you are confirming its size, not
   discovering it

### ▶ TEST 4 · Discipline (5 min) — STU0001 *(the only student with flags)*

1. On STU0001, click the **Student & Family** zone header
2. **Expect:** zone expands, **Discipline** tab selected automatically
3. **Expect:** Active **4**, Resolved **1**, plus a Pattern line
4. **Expect:** an **amber dot** on the Discipline tab
5. **Expect:** the alert strip at the top says **"4 active discipline flags"**
6. 🔍 Open DevTools → Network. **Expect NO request when you expanded the zone** — discipline is eager
7. ❌ **Fail if** the counts differ from 4/1, or a deleted flag appears

### ▶ TEST 5 · Lazy loading — the architecture test (5 min) — STU0001

1. Reload the page with **DevTools → Network** open, filtered to `student360`
2. **Expect on load:** only the page request. **No `panel/` request at all**
3. Click the **Records** zone header → **Expect still no `panel/` request** (Profile is server-rendered)
4. Now click the **Documents** tab inside Records → **Expect exactly one** `panel/documents/STU0001`
5. Click away to Profile, then back to Documents → **Expect NO second request**
6. ❌ **Fail if** expanding a zone triggers any fetch — that breaks the whole read-budget design

### ▶ TEST 6 · Documents & Attendance (5 min) — STU0001

1. Records → **Documents** → **Expect 20 documents** listed
2. Academics zone → **Attendance** tab → **Expect 3 month rows**, newest first
3. **Expect the session total row shows `—` in the % column** (by design — see ATT-001)
4. Cross-check one month's % against the Attendance module → **must match exactly**

### ▶ TEST 7 · Results (5 min) — STU0006

1. Open `student360/view/STU0006`
2. Academics zone → **Results** is the primary tab, already selected
3. **Expect 2 exams**
4. ⚠️ **Expect a red banner above the panel: "Result withheld for fee dues"**
5. Cross-check % and grade against `result/student_result/STU0006` → **must match exactly**
6. ❌ **Fail if** a percentage shows `0.00%` where nothing was computed — it must show `—`

### ▶ TEST 8 · Transport + Entitlements + photo (5 min) — STU0159

1. Open `student360/view/STU0159`
2. **Expect a photograph** in the header (not initials)
3. Services zone → **Expect South Circle, ₹1,400.00/month, Active**
4. ⚠️ Compare ₹1,400 against the billed transport demand → **record both** (this is **D-07**)
5. Money → **Entitlements** → **Expect 2 active entitlements**
6. ⚠️ **Expect concession ₹0 effect on the fees** — correct, not a bug: entitlements are shadow-only
   while the flags are off

### ▶ TEST 9 · Photo fallback (2 min)

1. **STU0001** → **Expect a coloured circle with "H"** (no photo on file)
2. **STU0159** → **Expect the photograph**
3. Both correct = B-1 passes

### ▶ TEST 10 · The withheld anomaly (3 min) — STU0011

1. Open `student360/view/STU0011`
2. **Expect Outstanding ₹47,000.00** — the highest of any student
3. **Expect NO "Result withheld" alert**, and the Academics headline shows `—`
4. ⚠️ Every other student — **including those owing less** — **is** withheld
5. **Record this.** See **F-3** below

### ▶ TEST 11 · Viewports (10 min)

1. **1366×768** — the school-laptop case. Expect 5 zone bars + identity + alerts to leave a usable
   working area. Record whether you must scroll to read the open zone's table
2. **1920×1080** — expect content capped, not stretched edge-to-edge
3. **375px** — resize narrow. **Expect all five zones CLOSED**, inner tabs scrolling sideways inside
   their row, and **no page-wide horizontal scroll**
4. ❌ **Fail if** the whole page scrolls sideways at any width

### ▶ TEST 12 · Security — the two that stop the run (10 min)

1. Address bar: `student360/view/STU9999999` → **Expect 404**
2. Address bar: `student360/view/STU0032` *(a real student in another school)* → **Expect the same
   404**, not a different message
3. 🔴 **STOP THE UAT** if any student data appears
4. Address bar: `student360/panel/results/STU0032` → **Expect a JSON error, never marks**
5. 🔴 **STOP THE UAT** if marks appear

---

## 3 · DATA-LAYER RESULTS — what I already verified (no browser needed)

| Test | Result |
|---|---|
| Receipts Σ vs demands `paid` — all 11 students | ✅ **PASS** — exact match every time |
| Receipt rows rendering ₹0.00 | ✅ **PASS** — none |
| Amount Due vs Demand Outstanding | ✅ **PASS** — equal for all 11 (no discounts in tenant) |
| Zone mapping 13/13 panels, 16/16 actions | ✅ **PASS** |
| Primary panel per zone | ✅ **PASS** — exactly one visible panel per zone |
| Zone expand triggers a fetch | ✅ **PASS** — 0 `lazyLoad` calls inside `openZone` |
| Write primitives / RTDB in Student 360 | ✅ **PASS** — 0 across 5 files |
| New Firestore reads from the zone layer | ✅ **PASS** — 0 |
| RBAC module keys valid | ✅ **PASS** — 16/16 actions, 6/6 panels |

---

## 4 · FINDINGS FROM THE DATA LAYER

### F-1 · D-02 confirmed at scale — **9 of 11 students affected** · pre-existing, Fees

| Student | S360 | Incl. archived | Gap |
|---|---|---|---|
| STU0004 | ₹34,600 | ₹1,18,800 | **₹84,200** |
| STU0005 | ₹37,200 | ₹1,09,000 | ₹71,800 |
| STU0011 | ₹47,000 | ₹1,18,800 | ₹71,800 |
| STU0006-0010 | ₹34,600 | ₹71,800 | ₹37,200 each |
| STU0160 | ₹37,200 | ₹71,800 | ₹34,600 |
| STU0001, STU0159 | — | — | **none** |

Student 360 excludes archived/cancelled demands; the Fees ledger does not. **Student 360 is the more
correct of the two** — but the two surfaces disagree by up to ₹84,200 for the same student.

### F-2 · Entitlements have zero billing effect — **expected, not a defect**

STU0159 holds 2 active entitlements and STU0160 holds 1, yet neither reduces any demand. Correct:
`ENTITLEMENT_SHADOW_COMPARE` and `USE_ENTITLEMENT_SERVICE_PRICING` are both `false`, so entitlements
are shadow-only until Gate 8. **A tester will find this alarming — it is the designed state.**

### F-3 · Result-withholding is inconsistent — **NEW, pre-existing, Fees module**

**10 of 11 students are result-withheld. STU0011 is not — despite owing ₹47,000, the highest in the
tenant.** Students owing ₹34,600 *are* withheld.

The most likely explanation is that `updateDefaulterStatus()` never ran for STU0011, so no
`feeDefaulters` document exists — withholding is read from a **stored** flag, not computed live.
Whatever the cause, the rule is not applied uniformly.

**Not a Student 360 defect** — Student 360 faithfully displays the stored flag. It is a Fees-module
finding that Student 360 surfaced.

### F-4 · UAT coverage is data-limited — **process finding**

Five test areas cannot run in this tenant: inactive-student states, approvals, parent requests,
hostel, and SD-1. That is **~15 of the guide's tests**, and per your instruction **no data will be
manufactured** to cover them.

---

## 5 · WHAT REMAINS FOR YOU

Everything in §2 above (Tests 1–12, ~60 minutes). Record for each: PASS / FAIL / BLOCKED, plus the
actual numbers where the test asks for them.

**Stop immediately if** Test 12 shows cross-tenant data, or if any Block 0 baseline number changes
after viewing.

---

## 6 · RECOMMENDATION SO FAR

**NOT READY FOR STABILIZATION — because the UAT is incomplete, not because it failed.**

The data layer passed everything testable. No new Student 360 defect was found in it. But the
interaction, visual and security layers are **unverified**, and a stabilization phase entered on a
half-run UAT would fix a list that is missing whatever Tests 1–12 turn up.

Run §2, send me the results, and I will produce the consolidated report with the final
READY / NOT READY call.
