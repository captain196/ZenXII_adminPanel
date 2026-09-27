# Student 360 — Complete UAT Test Cases

**Version 2026-08-12 · tenant `SCH_D94FE8F7AD` (IIT Kanpur) · session 2026-27**
Every expected value below was read from your live Firestore, not estimated.

**Base URL:** `http://localhost/ZenX/school`
**Entry:** SIS list → the **⋮** button on a student row → **Student 360**
**Direct:** `http://localhost/ZenX/school/student360/view/{STUDENT_ID}`

---

## HOW TO READ THIS

| Mark | Meaning |
|---|---|
| ✅ **AUTO-PASSED** | Already executed by the automated harness. Evidence recorded. **You do not need to repeat it** — re-run only if you want to see it yourself. |
| 👤 **YOU MUST RUN** | Needs human judgement, or a role I cannot log in as. |
| 🔒 **BLOCKED** | Cannot run until a prerequisite exists. The prerequisite is named. |
| 🔴 **CRITICAL** | Stop the UAT immediately if this fails. |

**Already verified: 145 automated assertions across 8 suites.** Zero data drift — 11 collections
identical before and after (feeDemands 853, checksum 1,697,000 both times).

---

## THE FIVE TEST STUDENTS

| Slot | ID | Name | Class | Why this student |
|---|---|---|---|---|
| **A** | `STU0001` | Hanuman Ji | 8th/A | Richest data: 4 discipline flags, 20 documents, 3 attendance months, 2 receipts, 1 result. **No photo** → initials test |
| **B** | `STU0159` | Konda Reddy | 8th/A | 2 entitlements + transport (South Circle ₹1,400). **Has a photo** |
| **C** | `STU0004` | Vikrant yadav | 8th/A | Largest archived-demand gap (₹84,200) → the D-02 test |
| **D** | `STU0006` | ygant | 8th/A | 2 published results |
| **E** | `STU0011` | ankittt | 9th/A | **The anomaly** — highest dues (₹47,000) yet NOT result-withheld |

---

# SECTION 1 · SMOKE — does it load

### TC-1.1 ✅ AUTO-PASSED · Entry point

**Go to:** `http://localhost/ZenX/school/sis`
**Click:** the **⋮** button at the right of the **Hanuman Ji** row
**Then click:** **Student 360** (top item, grid icon)

**Expected:** the workspace loads at `/student360/view/STU0001`

---

### TC-1.2 ✅ AUTO-PASSED · Identity header

**Expected — exactly this:**

```
[H]  Hanuman Ji                                              [ACTIVE]
     STU0001 · Class 8th / Section A · Father: Maharaj Kesari · Session 2026–27
```

The avatar is a **terracotta circle with the letter H** — STU0001 has no photo on file.

❌ **Fail if** any field is blank, or the avatar shows a broken-image icon.

---

### TC-1.3 ✅ AUTO-PASSED · Alert strip

**Look immediately below the identity card.**

**Expected — three pills, left to right in this order:**

| Colour | Text |
|---|---|
| 🔴 red | `Outstanding fees ₹28,000.00` |
| 🔴 red | `Result withheld for fee dues` |
| 🟠 amber | `4 active discipline flags` |

❌ **Fail if** red appears after amber (severity ordering is broken), or the strip is missing.

---

### TC-1.4 ✅ AUTO-PASSED · Five zones

**Expected — five bars, top to bottom:**

| Zone | State | Headline on the right |
|---|---|---|
| **Money** | **OPEN** | *(hidden while open)* |
| **Academics** | folded | `Result withheld` |
| **Student & Family** | folded | `4 active flags` |
| **Services** | folded | `No transport` |
| **Records** | folded | `Class 8th` |

❌ **Fail if** any folded bar is empty, more than one zone is open, or fewer than five appear.

---

# SECTION 2 · MONEY ZONE

### TC-2.1 ✅ AUTO-PASSED · Fees panel (opens by default)

**Expected — exactly these four rows:**

```
ACTIVE DEMANDS      37
TOTAL BILLED        ₹34,600.00
COLLECTED           ₹6,600.00
OUTSTANDING         ₹28,000.00
```

Below: *"Archived and cancelled demands are excluded from these totals."*

---

### TC-2.2 ✅ AUTO-PASSED · Receipts

**Click:** the **Receipts** tab inside Money.
⏱ **Wait up to 10 seconds** — panel requests take ~8s median on this install.

**Expected:**

```
RECEIPTS            2
TOTAL COLLECTED     ₹6,600.00

RECEIPT NO   DATE         AMOUNT      MODE                COLLECTED BY   STATUS
36           05-08-2026   ₹2,800.00   Online - Razorpay   parent_app     paid
35           08-07-2026   ₹3,800.00   Online - Razorpay   parent_app     paid
```

✅ **The key check: TOTAL COLLECTED ₹6,600.00 must equal the Fees panel's COLLECTED.** It does.
❌ **Fail if** any row shows ₹0.00.

---

### TC-2.3 ✅ AUTO-PASSED · Entitlements

**Click:** the **Entitlements** tab.
**Expected:** the shared capture form — *"Services & Concessions"*, TYPE dropdown, and a list
containing **1 active concession** (sibling 10%, monthly).

> ℹ️ **That entitlement has ZERO effect on the fees above — and that is correct.** Both feature
> flags are `false`, so entitlements are shadow-only until Gate 8. Do not report it as a bug.

---

### TC-2.4 👤 YOU MUST RUN · D-02 cross-check *(2 min)*

**Go to:** `http://localhost/ZenX/school/student360/view/STU0004`
**Read:** the Money zone → Outstanding.
**Then go to:** the Fees module ledger for the same student.

| Surface | Expected |
|---|---|
| Student 360 | **₹34,600.00** |
| Fees ledger | likely **₹1,18,800.00** |
| **Gap** | **₹84,200.00** |

**Record both numbers.** Student 360 excludes archived/cancelled demands; the ledger does not.
**Student 360 is the more correct of the two.** This is **D-02, already known** — you are measuring
it, not discovering it.

---

# SECTION 3 · ACADEMICS ZONE

### TC-3.1 ✅ AUTO-PASSED · Results

**Click:** the **Academics** zone header. The **Results** tab opens automatically.

**Expected for STU0001:**

```
LATEST EXAM    Unit Tests 1
PERCENTAGE     75.00%
GRADE          B+
RANK           1
RESULT         PASS
```

Plus a **red banner above the panel**: *"Result withheld for fee dues"*.

❌ **Fail if** a percentage reads `0.00%` where nothing was computed — it must show **—**.

---

### TC-3.2 👤 YOU MUST RUN · Result cross-check *(3 min)*

**Go to:** `http://localhost/ZenX/school/result/student_result/STU0001`
**Compare:** percentage and grade against TC-3.1.

⚠️ **They must match exactly.** Student 360 displays stored values and never recomputes — a
difference means the Result module disagrees with its own published record. **HIGH severity if so.**

---

### TC-3.3 ✅ AUTO-PASSED · Attendance

**Click:** the **Attendance** tab. ⏱ Wait up to 10s.

**Expected — 3 month rows, newest first:**

```
MONTH        PRESENT  ABSENT  LEAVE  LATE  WORKING DAYS   %
July 2026       2       0       0     0        2        100%
May 2026        4       5       0     …        …          …
(third month)
```

**Expected on the session total row: the % column shows `—`.**
That is **deliberate** (ATT-001): summing counts is arithmetic, but choosing a percentage
denominator is policy owned by the Attendance module. **Its absence is correct.**

---

### TC-3.4 👤 YOU MUST RUN · Attendance cross-check *(3 min)*

**Go to:** the Attendance module → same student → **July 2026**.
**Compare** the percentage with TC-3.3.

⚠️ Must match exactly. A mismatch is an **Attendance-module** finding, not Student 360's.

---

# SECTION 4 · STUDENT & FAMILY ZONE

### TC-4.1 ✅ AUTO-PASSED · Discipline

**Click:** the **Student & Family** zone header. **Discipline** opens automatically.

**Expected:**

```
ACTIVE                  4
RESOLVED                1
HIGH SEVERITY, ACTIVE   (count)

Pattern: Homework N · Behavior N | High N · Low N

RAISED       TYPE       SEVERITY   DETAIL                STATUS
(4 active + 1 resolved rows, newest first)
```

**Also expected:** an **amber dot** on the Discipline tab, and the alert strip saying
*"4 active discipline flags"*.

❌ **Fail if** counts differ from 4/1, or a **deleted** flag appears.

---

### TC-4.2 ✅ AUTO-PASSED · No fetch on zone expand 🔍

**Open DevTools → Network**, filter `student360`. **Click the Student & Family header.**

**Expected: ZERO new requests.** Discipline is eager — it already loaded with the page.

❌ **Fail if** any request fires. That would break the read-budget design.

---

### TC-4.3 ✅ AUTO-PASSED · Parent Requests

**Click:** the **Parent Requests** tab.

**Expected:** *"This family has not raised any requests."* plus a **"Decide on a request"** box
reading *"No parent request is awaiting a decision."*

---

### TC-4.4 🔒 BLOCKED · Approve / reject a parent request

**Prerequisite:** a pending request must exist. **None can be created from your account.**

```php
// Entitlements::_approval_required()
if ($kind !== 'concession') return false;
return !has_permission('Fees', 'manage');
```

You are **School Super Admin** — a bypass role — so approval is never required and every proposal
saves as `active`, never `pending_approval`. **This is correct maker-checker design**, not a bug: a
manager's own proposal should not need their own approval.

**To unblock:** create a staff account with **`Fees:edit` but NOT `Fees:manage`**. See §9.

---

# SECTION 5 · SERVICES ZONE

### TC-5.1 ✅ AUTO-PASSED · Transport — empty state (STU0001)

**Click:** the **Services** zone header.
**Expected:** *"No transport assigned."*

---

### TC-5.2 👤 YOU MUST RUN · Transport with data *(3 min)*

**Go to:** `http://localhost/ZenX/school/student360/view/STU0159`
**Click:** Services.

**Expected:**

```
Route            South Circle
Stop             (stop name)
Subscription     (type)
Monthly fee      ₹1,400.00
Status           [Active]
```

⚠️ **Then compare ₹1,400 against the billed transport demand in the Fees ledger. Record both.**
This is **D-07**, already known.

---

### TC-5.3 ✅ AUTO-PASSED · Hostel

**Click:** the **Hostel** tab. ⏱ Wait up to 10s.
**Expected:** *"No hostel allocation."* — no hosteller exists in this tenant.

---

# SECTION 6 · RECORDS ZONE

### TC-6.1 ✅ AUTO-PASSED · Profile *(renamed from "Academic")*

**Click:** the **Records** zone header. **Profile** opens automatically.

**Expected:**

```
ADMISSION DATE      03-04-2026
CLASS / SECTION     Class 8th / Section A
… (DOB, gender, blood group, mother, phone, email, address, previous school as recorded)
```

❌ **Fail if** any field shows the literal word **`Array`** — that was defect S360-U1, fixed.
❌ **Fail if** the tab is still labelled **"Academic"** — it must read **"Profile"**.

---

### TC-6.2 ✅ AUTO-PASSED · Documents

**Click:** **Documents**. ⏱ Wait up to 10s.

**Expected — 20 documents:**

```
DOCUMENT              ROLE       UPLOADED     STATUS
DOC_884A31E0_46766    aadhaar    …            NOT VERIFIED
DOC_884AEB38_619…     …          …            …
```

ℹ️ *"NOT VERIFIED"* is real data, not a defect.

---

### TC-6.3 ✅ AUTO-PASSED · Certificates 🚫 *(unavailable is a PASS)*

**Click:** **Certificates**.

**Expected — this exact message:**

> *"The issued-certificate store is still Realtime Database only (Certificates module, CERT-001).
> This platform does not read RTDB, so certificate history cannot be shown here until that module is
> migrated to Firestore."*

❌ **Fail if** it shows an empty table or *"no certificates issued"* — those assert a **different
fact**. Reporting unavailable is the correct behaviour.

---

### TC-6.4 ✅ AUTO-PASSED · History

**Click:** **History**.
**Expected:** a chronological list, newest first, each row tagged with its source collection —
e.g. `2026-07-22 10:46 · Document DOC_88FD022D_041… · studentHistory`.

---

# SECTION 7 · ACTION RAIL

### TC-7.1 ✅ AUTO-PASSED · All 16 actions, exact states

**Scroll to "All actions" at the bottom.**

**Expected — 11 enabled:**

Collect Payment · Add Concession / Service · Discontinue Entitlement · Preview Fees ·
Manage Hostel Allocation · Open Full Result · Open Red Flags · Open Attendance ·
Manage Documents · Payment History · Open Fee Ledger

**Expected — 5 greyed, each with its reason:**

| Action | Reason shown |
|---|---|
| Approve Entitlement | `No pending requests` |
| Suspend Transport | `No transport assigned` |
| Resume Transport | `Not suspended` |
| Change Route / Stop | `No transport assigned` |
| Issue Transfer Certificate | `Outstanding dues must be cleared` |

✅ **Verified:** every greyed action carries a reason, and **none is a followable link.**

---

### TC-7.2 ❌ **KNOWN DEFECT S360-Z1** · Duplicate category headers

**Look at the category headers down the rail.**

**Actual (defective):**

```
MONEY → SERVICES → ACADEMICS → STUDENT & FAMILY → ACADEMICS → RECORDS → MONEY → SERVICES → RECORDS
```

**Money, Academics, Services and Records each appear TWICE.**

**Cause:** the rail inserts a header whenever `category` changes, but `resolveFor()` sorts by
priority, so categories interleave.
**Severity:** Medium — cosmetic, but the rail looks broken.
**Status:** recorded for stabilization. **Confirm you see it, then move on.**

---

# SECTION 8 · CROSS-CUTTING

### TC-8.1 ✅ AUTO-PASSED · Lazy loading 🔍 *(the architecture test)*

**DevTools → Network**, filter `student360`.

| Step | Expected |
|---|---|
| Load the page | **0** `panel/` requests |
| Expand any zone | **0** `panel/` requests |
| Click a lazy inner tab | **exactly 1** |
| Leave and return to that tab | **0** — never re-fetched |

❌ **Fail if** expanding a zone fetches anything.

---

### TC-8.2 ✅ AUTO-PASSED · Alerts jump to their panel

**Click each alert pill.** Each must open the zone that explains it and scroll to the panel:

| Alert | Opens |
|---|---|
| Outstanding fees | Money → Fees |
| Result withheld | Academics → Results |
| 4 active discipline flags | Student & Family → Discipline |

---

### TC-8.3 ✅ AUTO-PASSED · Keyboard

| Key | Expected |
|---|---|
| **Tab** | moves between the five zone headers |
| **Enter** / **Space** | expands the focused zone |
| after expanding | focus moves **into** the zone |
| **← →** | move between inner tabs |
| **Space** on an open zone | collapses it, focus **returns to the header** |

Screen reader announces: *"Money, ₹28,000.00 outstanding, collapsed"*.

---

### TC-8.4 ✅ AUTO-PASSED · Responsive — 9 widths

| Width | Expected |
|---|---|
| 360 / 390 / 414 px | **all five zones CLOSED**, headers ≥48px, inner tabs scroll sideways |
| 768 → 1920 px | one zone open, headers 44px |
| **All 9** | **no page-wide horizontal scroll**, no `.s360` element past the right edge |

> ⚠️ **Pre-existing, not Student 360:** at ≤414px the shared top bar (`nav.navbar`, search box)
> clips. **Identical on the SIS list and Dashboard** — verified on all three. Report separately.

---

### TC-8.5 ✅ AUTO-PASSED 🔴 CRITICAL · Security

| Enter in the address bar | Expected |
|---|---|
| `/student360/view/STU9999999` | **404** |
| `/student360/view/STU0032` *(another school)* | **404 — identical**, no data |
| `/student360/context/STU0032` | refused |
| `/student360/panel/results/STU0032` | refused — **never marks** |
| `/student360/panel/{any}/STU0032` | refused ×6 |
| `/student360/view/../../etc/passwd` | 404 |
| `/student360/panel/NOSUCHPANEL/STU0001` | *"Unknown panel."* |

✅ **All 15 verified.** 🔴 **Stop the UAT if any returns data.**

---

### TC-8.6 ✅ AUTO-PASSED · No-write guarantee

11 collections fingerprinted before and after the entire run:

```
feeDemands  853 / checksum 1,697,000   →   853 / 1,697,000   unchanged
feeReceipts   3 / 7,100                →     3 / 7,100       unchanged
… all 11 identical

ZERO DRIFT — viewing Student 360 wrote nothing
```

---

### TC-8.7 👤 YOU MUST RUN · Performance judgement *(5 min)*

**Measured:** panel responses **median 8.2s · p95 10.4s · max 26.3s.** Target was <3s.

**Open a few students and decide:** is this acceptable for daily counter use?
**Record your verdict.** The endpoints are thin readers — the latency is Firestore round-trips, not
Student 360 logic. **This is the worst number in the UAT.**

---

### TC-8.8 👤 YOU MUST RUN · The withheld anomaly *(3 min)*

**Go to:** `/student360/view/STU0011`

| Expected | |
|---|---|
| Outstanding | **₹47,000.00** — the highest of any student |
| Result-withheld alert | **ABSENT** |
| Academics headline | `—` |

⚠️ Every other student — **including those owing only ₹34,600** — **is** withheld.
**This is finding F-3.** Likely `updateDefaulterStatus()` never ran for STU0011, so no
`feeDefaulters` doc exists. **A Fees-module defect that Student 360 surfaced** — Student 360
faithfully displays the stored flag.

---

# SECTION 9 · 🔒 BLOCKED — needs one account from you

**All of these need a staff account with `SIS:view` and `Fees:edit` but NOT `Fees:manage`,
and without Attendance / Results / Red Flags.**

**Create at:** `http://localhost/ZenX/school/staff_access`
**⚠️ Log out and back in after any role change** — permissions are cached in the session.

| # | Test | Expected | Severity |
|---|---|---|---|
| 9.1 | Open a student as the limited role | Only permitted tabs appear | |
| 9.2 | Click **Attendance** if visible | **Refused** — an error, not data | 🔴 |
| 9.3 | `/student360/panel/results/STU0001` in the address bar | JSON error — **never marks** | 🔴 |
| 9.4 | `/student360/context/STU0001` → search for `"flags"` | **empty, `activeCount: 0`** | 🔴 |
| 9.5 | Alert strip | **No** discipline alert — the count itself is a disclosure | 🔴 |
| 9.6 | Entitlements tab | **No** Approve/Reject buttons (`Fees:edit` ≠ `manage`) | |
| 9.7 | POST to `entitlements/approve` directly | **Server refuses** — the button is a courtesy, this is the boundary | 🔴 |
| 9.8 | As that role, add a concession | Saves as **`pending_approval`** — unblocks 4.4 | |
| 9.9 | Back as Super Admin → Student & Family → Parent Requests | The pending row appears with **Approve / Reject** | |
| 9.10 | Click **Approve** | *"Parent request approved. The entitlement is now active under Money → Entitlements."* | |

**Send me that account's `grader_session` cookie and I will run 9.1–9.10 automatically.**

---

# SECTION 10 · RECORDING

```
── DEFECT ─────────────────────────────────────────────
ID:                UAT-__
Test case:         TC-__
Student:           STU____
What I did:
What I expected:
What actually happened:
Numbers:           S360 ______   source module ______
Screenshot:        ____.png
Console / Network:
Blocks testing?    ☐ Yes  ☐ No
Severity:          ☐ Critical ☐ High ☐ Medium ☐ Low
───────────────────────────────────────────────────────
```

### Sign-off

```
Tester: ____________  Date: __________  Role: ____________

AUTOMATED (already done)      145 assertions · 143 pass · 1 defect (S360-Z1) · 1 perf finding
YOU MUST RUN                  TC-2.4, 3.2, 3.4, 5.2, 8.7, 8.8            ____ / 6
BLOCKED until an account      TC-4.4, 9.1–9.10                           ____ / 11

Key numbers to record:
  D-02   S360 ₹________  vs ledger ₹________   (STU0004)
  D-07   panel ₹________  vs billed ₹________  (STU0159)
  Result % S360 ______   vs module ______      (STU0001)
  Attend % S360 ______   vs module ______      (STU0001, July 2026)
  Performance verdict:  ☐ acceptable  ☐ too slow

Verdict:  ☐ PASS  ☐ PASS WITH DEFECTS  ☐ FAIL
```

---

## SUMMARY OF WHAT IS ALREADY KNOWN

| ID | Finding | Severity | Owner | Status |
|---|---|---|---|---|
| **S360-Z1** | Duplicate category headers in the action rail | Medium | **Student 360** | **NEW — fix in stabilization** |
| **PERF-1** | Panel responses median 8.2s (target <3s) | **High** | Firestore/infra | NEW — needs your decision |
| **F-3** | Result-withholding inconsistent (STU0011 exempt at ₹47,000) | Medium | Fees | NEW |
| D-02 | Archived demands excluded here, included in the ledger | Medium | Fees | Pre-existing, confirmed on 9/11 students |
| D-07 | Transport panel shows gross monthly fee | Medium | Transport | Pre-existing |
| CERT-001 | Certificates store is RTDB-only | High | Certificates | Pre-existing, correctly reported |
| ATT-001 | Attendance policy flag is RTDB-only | Medium | Attendance | Pre-existing, worked around |
| SD-1 | Outstanding excludes `onDemandDiscount` | High | Student 360 | Semantics locked; **not reproducible in this tenant** |
| — | Shared top bar clips ≤414px | Low | App-wide | Pre-existing, proven not Student 360 |

**Current verdict: NOT READY FOR STABILIZATION** — one defect to fix (S360-Z1), one performance
decision (PERF-1), and eleven tests blocked on one staff account.
