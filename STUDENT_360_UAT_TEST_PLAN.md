# Student 360 — Runtime UAT Test Plan

**Updated:** 2026-08-10 — **Student 360 is FEATURE-COMPLETE (10 panels).**
**Branch:** `integration/phase2` · **Environment:** `http://localhost:8080`
**Read `STUDENT_360_ARCHITECTURAL_AUDIT.md` first.**

**Legend:** ✅ runnable now · 🌱 **[NEEDS SEED]** — cannot pass or fail without test data ·
🚫 **[BLOCKED]** — expected to report unavailable, and that is a PASS · 🔍 needs DevTools open

---

## 0 — BEFORE YOU START

### 0.1 Preconditions (verify, don't assume)

| # | Check | How | Expected |
|---|---|---|---|
| 0.1.1 | Server up | `php -S localhost:8080` from repo root | port **8080** exactly — `.env` `APP_HOST` requires it |
| 0.1.2 | Flags OFF | open `application/config/fees_exemption_v2_flags.php` | `ENTITLEMENT_SHADOW_COMPARE = false`, `USE_ENTITLEMENT_SERVICE_PRICING = false` |
| 0.1.3 | Logged in | any admin | note the role — §7 depends on it |
| 0.1.4 | DevTools open | F12 → Console **and** Network | leave open for the whole run |

### 0.2 Pick your test students — write these down

| Slot | Needs | Find it |
|---|---|---|
| **A** | active, **has outstanding dues**, **has transport** | Fees → Defaulters, cross-check Transport → Assignments |
| **B** | active, **fully paid**, **no transport** | Fees → any paid student |
| **C** | **inactive / TC-issued** | SIS → Students → filter Inactive |
| **D** | **STU0160** (37 demands, ₹37,200 — the SE-001 UAT subject) | known |

### 0.3 Take the "before" fingerprint 🔍

Student 360 must write **nothing**. Capture this now so you can prove it:

```bash
node scripts/inspect_test_student.js STU0160    # or your usual snapshot script
```
Record: demand count, entitlement count, receipt count. **You will re-run this at step 12.1.**

---

## 1 — EXECUTION ORDER (do not reorder)

The order matters: read-only checks first, then permissions, then anything that writes. If you jump
to §8 first, a failure there contaminates every later read.

```
 1. Context loading + tenant isolation   (§2)  ← safest, catches config errors early
 2. Identity + summary                   (§3)
 3. Fees panel                           (§4)
 4. Transport panel                      (§5)
 5. Timeline                             (§6)  ← the D-01 fix lands here
 6. NEW PANELS — Academic, Attendance,
    Hostel, Documents, Certificates,
    Parent Requests                      (§6B) ← lazy loading proven here
 7. Action Registry + preconditions      (§7)
 8. RBAC — incl. per-panel gating        (§8)  ← requires re-login, do it before writes
 9. Entitlements + preview  [READ]       (§9)
10. Approval workflow       [WRITES]     (§10) 🌱
11. Cross-module navigation              (§11)
12. Errors, empty states, mobile         (§12)
13. Performance + read budget + no-write (§13) ← must be LAST
```

---

## 2 — CONTEXT LOADING & TENANT ISOLATION ✅

| # | Action | Expected | P/F |
|---|---|---|---|
| 2.1 | Go to `localhost:8080/sis/students`, open the **⋮** menu on any row | A **"Student 360"** item appears above "Documents" | |
| 2.2 | Click it | Workspace loads. URL = `/student360/view/STU####`. **No PHP notice, no blank area** | |
| 2.3 | Console | **Zero** errors. (A 404 for a missing favicon is fine.) | |
| 2.4 | Visit `/student360/view/STU0000XX` (nonexistent) | **404 page** — not a PHP error, not an empty workspace | |
| 2.5 | Visit `/student360/view/../../etc/passwd` | **404**. Never a stack trace or file content | |
| 2.6 | Visit `/student360/view/` (no id) | **404** | |
| 2.7 | **Tenant isolation:** get a student id from a *different* school, visit `/student360/view/<that id>` | **404** — identical to 2.4. If it renders, **STOP: critical** | |
| 2.8 | Visit `/student360/context/<student A>` directly | JSON `{"status":"success","context":{…},"actions":{…}}` | |
| 2.9 | Visit `/student360/timeline/<student A>` directly | JSON `{"status":"success","entries":[…],"sources":{…}}` | |

> **2.7 is the one test I would not skip.** Everything else is a bug; that one is a data breach.

---

## 3 — IDENTITY HEADER & SUMMARY ✅

| # | Action | Expected | P/F |
|---|---|---|---|
| 3.1 | Look at the header on student A | Avatar (first initial), name, `STU####`, class/section, father, session | |
| 3.2 | Status chip | **green** for Active | |
| 3.3 | Open student C (inactive) | Status chip **grey**, reads the actual status | |
| 3.4 | Summary cards | Five: Outstanding, Billed, Collected, Entitlements, Transport | |
| 3.5 | Outstanding on student A | **red**, non-zero, `₹` + 2 decimals + thousands separators | |
| 3.6 | Outstanding on student B (paid) | **not red**, `₹0.00` | |
| 3.7 | Digits alignment | Numbers use tabular figures — columns line up | |

> ⛔ **Academic information** — no panel exists. Nothing to test.

---

## 4 — FEES PANEL ✅

| # | Action | Expected | P/F |
|---|---|---|---|
| 4.1 | Fees tab (default) | Table: Active demands / Total billed / Collected / Outstanding | |
| 4.2 | Arithmetic | Billed − Collected = Outstanding (±₹0.01) | |
| 4.3 | Note below table | States archived + cancelled are excluded | |
| 4.4 | **D-02 check:** open `/fees/student_ledger?uid=<student A>` in a new tab, compare Outstanding | ⚠️ **They may differ.** Student 360 excludes archived; the ledger does not (FEE-002). **Record both numbers** — this is the evidence for the D-02 decision | |
| 4.5 | Student D (STU0160) | Billed should be near **₹37,200** (the SE-001 baseline) | |
| 4.6 | Student with no demands at all | `0` and `₹0.00` — never blank, never "NaN" | |

---

## 5 — TRANSPORT PANEL ✅

| # | Action | Expected | P/F |
|---|---|---|---|
| 5.1 | Transport tab, student A (has transport) | Route / Stop / Subscription / Monthly fee / Status = green **Active** | |
| 5.2 | Student B (no transport) | *"No transport assigned."* — not an empty table, not an error | |
| 5.3 | Summary card matches the panel | Card shows the route name; panel agrees | |
| 5.4 | 🌱 Suspend a student's transport in the Transport module, reload Student 360 | Status = amber **Suspended**; summary card also says Suspended | |
| 5.5 | **D-07 check:** compare "Monthly fee" against the student's actual transport demand in Fees | ⚠️ May differ — the panel shows **gross** `monthly_fee`. **Record both.** Gate-6-linked | |

---

## 6 — TIMELINE (the D-01 fix) ✅

**This panel was completely non-functional before today's fix — it returned zero rows for every
student. These tests are the proof the fix works.**

| # | Action | Expected | P/F |
|---|---|---|---|
| 6.1 | Pick a student you have **edited recently** (status change, class change) via SIS | | |
| 6.2 | Open History tab | **At least one entry.** If it says "No recorded history" for a student you know you edited, **D-01 is not fixed — stop and report** | |
| 6.3 | Entry format | `YYYY-MM-DD HH:MM` · description · grey source badge · "by <name>" | |
| 6.4 | Source badges | `studentHistory` and/or `feeAuditLogs`. **`auditLogs` must never appear** (deliberately dropped) | |
| 6.5 | **Ordering — the D-06 fix.** With entries from *both* sources on the same day | Strictly newest-first. A 09:00 fee event must sit **below** a 23:00 history event **on the same date** | |
| 6.6 | A `feeAuditLogs` row | Reads like *"Update demand — transport_suspend (netAmount, balance)"* — composed, not blank | |
| 6.7 | Timestamps | Sensible local times. **Flag anything off by hours** — the two writers use different formats | |
| 6.8 | Brand-new student, no history | *"No recorded history for this student."* | |
| 6.9 | If the truncation note appears | *"…reached the per-source read cap — this history is partial."* Only on a very high-volume student | |

---

## 6B — THE SIX NEW PANELS ✅

Ten tabs now: Fees · Entitlements · Parent Requests · Academic · Attendance · Transport · Hostel ·
Documents · Certificates · History.

### 6B.1 Lazy loading — the architecture test 🔍

Four tabs fetch on demand. **Keep the Network tab open and filtered to `student360`.**

| # | Action | Expected | P/F |
|---|---|---|---|
| 6B.1.1 | Load the workspace. Do **not** click any tab. Look at Network | Requests for `view` and `timeline` only. **No `panel/` request yet** — if any appears, lazy loading is broken and the read budget claim is void | |
| 6B.1.2 | Click **Attendance** | Exactly one `student360/panel/attendance/…` → 200 | |
| 6B.1.3 | Click away, then click **Attendance** again | **No second request.** Fetched once per page load, never re-fetched | |
| 6B.1.4 | Double-click **Hostel** fast | **Exactly one** request (the loader claims the panel before firing) | |
| 6B.1.5 | Open all four lazy tabs | Four requests total, one each | |

### 6B.2 Academic ✅ (0 extra reads — projected off the student doc)

| # | Action | Expected | P/F |
|---|---|---|---|
| 6B.2.1 | Open **Academic** | Renders instantly. 🔍 **No network request at all** — it is server-rendered | |
| 6B.2.2 | Content | Admission No, Admission Date, Class/Section, DOB, Father, Mother, Phone, Address… | |
| 6B.2.3 | A student with sparse data | Blank fields are **hidden**, with a note *"N fields not recorded"*. No empty rows | |
| 6B.2.4 | Cross-check 3 values against SIS → Edit Student | Identical | |
| 6B.2.5 | If your tenant uses SR/APAAR | Those rows appear; otherwise correctly absent | |

### 6B.3 Attendance ✅

| # | Action | Expected | P/F |
|---|---|---|---|
| 6B.3.1 | Open **Attendance** on a student with marked attendance | Month rows: Present / Absent / Leave / Late / Working days / % | |
| 6B.3.2 | Ordering | **Newest month first** | |
| 6B.3.3 | **Cross-check** a month's % against the Attendance module for the same student/month | ⚠️ **Must match exactly.** Student 360 shows the *stored* value and never recalculates — a mismatch means the module and its own summary disagree, which is an Attendance defect worth reporting | |
| 6B.3.4 | Session total row | Counts summed; **`%` column shows `—`** | |
| 6B.3.5 | Why no session % | By design (ATT-001): summing counts is arithmetic, choosing a % denominator is policy owned by Attendance. **Its absence is correct** | |
| 6B.3.6 | Student with no attendance | *"No attendance recorded for this session."* | |

### 6B.4 Hostel ✅

| # | Action | Expected | P/F |
|---|---|---|---|
| 6B.4.1 | 🌱 A hosteller | Building / Room / Bed / Monthly fee / Checked in + green **Active** | |
| 6B.4.2 | A day scholar | *"No hostel allocation."* | |
| 6B.4.3 | 🌱 A checked-out student | Grey status chip, check-out date shown — **not** presented as a current allocation | |
| 6B.4.4 | Cross-check against the Hostel module | Same room and building | |

### 6B.5 Documents ✅

| # | Action | Expected | P/F |
|---|---|---|---|
| 6B.5.1 | A student with uploads | Document / Role / Uploaded / Status | |
| 6B.5.2 | Verified vs unverified | Green chip vs amber *"not verified"* | |
| 6B.5.3 | Cross-check against `sis/documents/{id}` | Same documents | |
| 6B.5.4 | Student with none | *"No documents on file."* | |
| 6B.5.5 | If a note says *"N document record(s) could not be read"* | **Report it** — a link exists whose document metadata is missing (a Document Service integrity issue, not a UI bug) | |

### 6B.6 Certificates 🚫 [BLOCKED — CERT-001]

| # | Action | Expected | P/F |
|---|---|---|---|
| 6B.6.1 | Open **Certificates** | ⚠️ **A written explanation**, saying the issued-certificate store is RTDB-only and cannot be read here | |
| 6B.6.2 | What it must NOT say | It must **not** show an empty table or *"no certificates"*. Those assert a different fact | |
| 6B.6.3 | Verdict | **Reporting unavailable is a PASS.** Data would mean someone read RTDB | |

### 6B.7 Parent Requests ✅ 🌱 (0 extra reads)

| # | Action | Expected | P/F |
|---|---|---|---|
| 6B.7.1 | Open **Parent Requests** with none on file | *"This family has not raised any requests."* | |
| 6B.7.2 | 🔍 Network while opening it | **No request** — it filters data already loaded | |
| 6B.7.3 | 🌱 Seed one via the Parent app (or `entitlement_requests/submit`), reload | Row appears: Raised / Request / Reason / amber `pending approval` | |
| 6B.7.4 | Tab label | Small **amber dot** next to "Parent Requests" | |
| 6B.7.5 | Summary cards | A **"N awaiting decision"** card appears | |
| 6B.7.6 | Approve it in **Entitlements → Approvals**, reload | Parent Requests row flips to green `active` | |
| 6B.7.7 | Note at the bottom | Explains requests are actioned from the Entitlements tab | |
| 6B.7.8 | ⚠️ **Known gap (SE-003)** | There is **no cross-student queue**. You can only see requests by opening each student. Confirm you agree this is a gap before production | |

---

## 6C — THE FIVE COMMERCIAL-PARITY CAPABILITIES ✅

Added 2026-08-10: A-1 Results · A-2 Discipline · A-3 Receipts · B-1 Photo · E-1 Alert strip.
**13 tabs now:** Fees · Entitlements · Parent Requests · Receipts · Academic · Results · Discipline ·
Attendance · Transport · Hostel · Documents · Certificates · History.

### 6C.1 B-1 — Student photo ✅

| # | Action | Expected | P/F |
|---|---|---|---|
| 6C.1.1 | Open a student who **has** a profile photo | The photograph fills the circular avatar, not a letter | |
| 6C.1.2 | Open a student with **no** photo | Coloured circle with the first initial — exactly as before | |
| 6C.1.3 | 🔍 Edit the `<img src>` in DevTools to a bad URL, then reload the element | The image **removes itself and the initial shows through.** No broken-image icon, no layout shift | |
| 6C.1.4 | Verify no duplicate storage | Only `profilePic` is read; Student 360 uploads/copies nothing | |

### 6C.2 E-1 — Operational alert strip ✅ **(the headline feature)**

| # | Action | Expected | P/F |
|---|---|---|---|
| 6C.2.1 | Open a student with dues, and confirm the strip sits **above** the summary cards | Red pill: *"Outstanding fees ₹X"* — the amount must match the Outstanding card exactly | |
| 6C.2.2 | A student with **nothing** wrong | Green *"No operational alerts for this student."* ⚠️ **Not a blank space** — silence and "all clear" must not look identical | |
| 6C.2.3 | Ordering with several alerts | **Red (danger) first, then amber (warn), then grey (info).** Worst thing leftmost | |
| 6C.2.4 | **Click** an alert | Jumps to the tab that explains it and scrolls it into view | |
| 6C.2.5 | Click the alert for a **lazy** tab (Results) | Switches tab **and** triggers the fetch — one click from noticing to inspecting | |
| 6C.2.6 | 🌱 A student with a pending parent request | Amber *"N parent request(s) awaiting a decision"* | |
| 6C.2.7 | Singular vs plural | "1 active discipline flag" / "2 active discipline flags" — never "1 flags" | |
| 6C.2.8 | An inactive student | Grey *"Student is inactive"* | |
| 6C.2.9 | 🔍 Confirm zero extra cost | Opening the page issues **no additional request** for alerts — pure aggregation | |
| 6C.2.10 | ⚠️ **Known omission** | There is **no Hostel alert.** Deliberate — see §14. Confirm you accept it | |

### 6C.3 A-1 — Results panel ✅

| # | Action | Expected | P/F |
|---|---|---|---|
| 6C.3.1 | Open **Results** for a student with published results | Cards: Latest exam · Percentage · Grade · Rank (if any) · Pass/Fail | |
| 6C.3.2 | Subject table | Subject / Marks / % / Grade / Result for the latest exam | |
| 6C.3.3 | An absent subject | Grey **absent** chip — not 0 marks | |
| 6C.3.4 | More than one exam | An **All examinations** table below, newest first | |
| 6C.3.5 | ⚠️ **Cross-check** % and grade against `result/student_result/{id}` | **Must match exactly.** Student 360 displays stored values and never recomputes — a mismatch means the Result module disagrees with its own published record | |
| 6C.3.6 | A student with results **not yet computed** | Percentage shows **—**, not `0.00%`. ⚠️ If you see 0.00% where nothing was computed, **report it** — "not computed" and "scored zero" are different facts | |
| 6C.3.7 | 🌱 A student whose results are **withheld for dues** | A red banner sits **above** the panel: *"Result withheld for fee dues — ₹X outstanding"*, and the same alert appears in the strip | |
| 6C.3.8 | Draft/staged results | ⚠️ Must **not** appear. Only published `results` are shown, never `resultsStaging` | |
| 6C.3.9 | Rail → **Open Full Result** | Lands on `result/student_result/{id}` with the **student already selected** — no re-searching | |

### 6C.4 A-2 — Discipline panel ✅

| # | Action | Expected | P/F |
|---|---|---|---|
| 6C.4.1 | 🌱 A student with flags | Cards: Active · Resolved · High-severity-active | |
| 6C.4.2 | Pattern line | `Homework 2 · Behavior 1 | High 1 · Low 2` style summary | |
| 6C.4.3 | Flag table | Raised / Type / Severity / Detail / Status, **newest first** | |
| 6C.4.4 | Tab label | **Amber dot** when any flag is active | |
| 6C.4.5 | Alert strip | *"N active discipline flag(s)"* | |
| 6C.4.6 | 🔍 Network on opening the tab | **No request** — discipline is eager (it drives the alert and the dot) | |
| 6C.4.7 | Cross-check against the Red Flags module for the same student | Same flags, same severities | |
| 6C.4.8 | A deleted/soft-deleted flag | ⚠️ Must **not** appear | |
| 6C.4.9 | A student with none | *"No discipline flags recorded for this student."* | |

### 6C.5 A-3 — Receipts panel ✅

| # | Action | Expected | P/F |
|---|---|---|---|
| 6C.5.1 | Open **Receipts** for a student who has paid | Receipt No / Date / Amount / Mode / Collected by / Status | |
| 6C.5.2 | Ordering | **Newest first** | |
| 6C.5.3 | Summary cards | Receipt count + **Total collected** | |
| 6C.5.4 | ⚠️ **Cross-check** Total collected against the Fees panel's *Collected* card | Should agree. **If they differ, record both numbers** — they come from different collections (`feeReceipts` vs `feeDemands.paidAmount`) and a divergence is a real Fees finding | |
| 6C.5.5 | ⚠️ Any receipt showing **₹0.00** | **Report it.** Amounts are read across documented aliases; a zero suggests an unknown field spelling | |
| 6C.5.6 | Cross-check one receipt number against the Fees module | Same amount, date and mode | |
| 6C.5.7 | A student with no payments | *"No payments recorded for this student."* | |
| 6C.5.8 | ⚠️ If the panel errors while others load | Suspect the `feeReceipts` composite index (schoolId + studentId + createdAt) — this reader orders by `createdAt` | |

### 6C.6 Per-panel RBAC for the new panels 🔴

| # | Role setup | Action | Expected | P/F |
|---|---|---|---|---|
| 6C.6.1 | **SIS:view, NO Results** | Results tab | ⚠️ **Refused.** If marks appear, STOP: critical | |
| 6C.6.2 | Same role 🔍 | `GET /student360/panel/results/<id>` | JSON error — never a marks payload | |
| 6C.6.3 | **SIS:view, NO Fees** | Receipts tab | Refused | |
| 6C.6.4 | **SIS:view, NO Red Flags** | Look at the tab row | **No Discipline tab at all.** Discipline is eager, so it is redacted server-side rather than merely hidden | |
| 6C.6.5 | Same role 🔍 | `GET /student360/context/<id>` and search the JSON for `flags` | ⚠️ **Must be empty and `activeCount` must be 0.** This is the test that matters: hiding a tab leaves the payload in the JSON | |
| 6C.6.6 | Same role — alert strip | ⚠️ **No discipline alert.** "3 active discipline flags" discloses the count even without the detail | |
| 6C.6.7 | **SIS:view, NO Fees** | Alert strip | No "Result withheld" and no "Outstanding fees" alert — those are fee facts | |

---

## 7 — ACTION REGISTRY & PRECONDITIONS ✅

The rail is generated from declared descriptors. These tests verify preconditions **and** that
blocked actions explain themselves.

| # | Student state | Expected in the rail | P/F |
|---|---|---|---|
| 7.1 | A: has dues | **Collect Payment** enabled | |
| 7.2 | B: no dues | **Collect Payment** greyed, reason *"Nothing outstanding"* | |
| 7.3 | B: no dues, active | **Issue Transfer Certificate** enabled | |
| 7.4 | A: has dues | **Issue TC** greyed, *"Outstanding dues must be cleared"* | |
| 7.5 | C: inactive | **Issue TC** greyed, *"Student is not active"*; **Add Concession** greyed, same reason | |
| 7.6 | A: transport active | **Suspend Transport** enabled; **Resume Transport** greyed *"Not suspended"* | |
| 7.7 | 5.4 suspended student | **Resume** enabled; **Suspend** greyed *"Already suspended"* | |
| 7.8 | B: no transport | All three transport actions greyed, *"No transport assigned"* | |
| 7.9 | Any student, 0 pending entitlements | **Approve Entitlement** greyed *"No pending requests"* | |
| 7.10 | Any student, 0 active entitlements | **Discontinue** greyed *"No active entitlements"* | |
| 7.11 | Grouping | Headers: **Fees**, **Transport**, **Hostel**, **Lifecycle**, **Records** | |
| 7.12 | Greyed actions | Cannot be clicked or focused. **A greyed action must not navigate** | |
| 7.13 | **NEW** — full-access role, active student | 13 actions resolve. New ones: **Manage Hostel Allocation**, **Open Attendance**, **Manage Documents** | |
| 7.14 | Student C (inactive) | **Manage Hostel Allocation** greyed *"Student is not active"* | |
| 7.15 | A day scholar (no hostel) | ⚠️ **Manage Hostel Allocation stays ENABLED.** That is intentional (D-09): hostel state is lazy and preconditions may not read Firestore, so it cannot gate on allocation. **Not a defect** | |

---

## 8 — RBAC ENFORCEMENT ✅

> Claim changes require **re-login**. `Super Admin` and `School Super Admin` **bypass all checks** —
> testing only as those proves nothing.

| # | Role setup | Expected | P/F |
|---|---|---|---|
| 8.1 | Role with **SIS:view** only | Page loads. Rail shows **only** *Open Fee Ledger* if Fees:view is held, else the *"N further actions require permissions…"* note | |
| 8.2 | Role **without SIS** | `/student360/view/<id>` is **refused** (redirect or error) — never renders | |
| 8.3 | **Fees:edit, not manage** | *Add Concession*, *Preview Fees*, *Collect Payment* visible; **Approve** and **Discontinue absent entirely** (not greyed) | |
| 8.4 | 8.3 role: in the Approvals block, click **Approve** on a 🌱 seeded pending item | ⚠️ **Server must refuse.** Red message. **If it succeeds, STOP: maker-checker is broken** | |
| 8.5 | **Transport:edit, not manage** | *Suspend*/*Resume* visible; **Change Route absent** | |
| 8.6 | Hidden-count note | Number matches what is actually missing | |
| 8.7 | 🔍 `/student360/context/<id>` as a role without SIS | JSON error / refusal — **never** a context payload | |

### 8B — PER-PANEL RBAC (new, and the most important addition)

**`SIS:view` opens the workspace. It must NOT unlock every module's data through it.** Each lazy
panel carries its own capability, so Student 360 cannot become a way *around* the permission model.

| # | Role setup | Action | Expected | P/F |
|---|---|---|---|---|
| 8B.1 | **SIS:view, NO Attendance** | Click the Attendance tab | ⚠️ **Refused.** Panel shows an error, not attendance data. **If data appears, STOP: critical** | |
| 8B.2 | Same role 🔍 | `GET /student360/panel/attendance/<id>` directly | JSON error — **never** an attendance payload | |
| 8B.3 | **SIS:view, NO Hostel** | Hostel tab | Refused | |
| 8B.4 | **SIS:view, NO Certificates** | Certificates tab | Refused **before** the CERT-001 message — permission is checked first | |
| 8B.5 | Grant Attendance:view, re-login | Attendance tab | Now loads | |
| 8B.6 | 🔍 `GET /student360/panel/nonsense/<id>` | *"Unknown panel."* — no stack trace, no fallback | |
| 8B.7 | 🔍 `GET /student360/panel/attendance/<other school's id>` | **Refused** — tenant check runs before any panel read | |

> **8B.1 and 8B.7 are the two tests I would not skip in this section.** One proves Student 360 is a
> view onto the permission model rather than a hole through it; the other proves cross-tenant reads
> stay impossible on the new endpoints too.

> **8.4 is the security test that matters most overall.** The rail hiding a button is a courtesy;
> the server refusing the action is the boundary.

---

## 9 — ENTITLEMENTS & FEE PREVIEW [READ-ONLY] ✅

| # | Action | Expected | P/F |
|---|---|---|---|
| 9.1 | Entitlements tab | Shared capture form renders (Kind / Type / Scope / Value) | |
| 9.2 | 🔍 Confirm it is the **shared** component | Element ids start `ec…` (`ecKind`, `ecConcType`) — same as the Admission screen | |
| 9.3 | Change Kind → Concession | Concession Type appears, Service Type hides | |
| 9.4 | Value Type → Full exemption | Value field hides | |
| 9.5 | Concession Type → RTE/EWS | Govt receivable field appears (**invariant I4**) | |
| 9.6 | Click **Preview** on student D (STU0160) | ✅ **Non-zero total, ~₹37,200 across 37 lines.** ₹0 here means SE-001 has regressed | |
| 9.7 | 🔍 Network tab during 9.6 | POST `entitlements/preview` → **200**, `status:"success"`. **No 403** | |
| 9.8 | **D-03 check:** a student with an active entitlement | ⚠️ Panel says active; the Fees summary shows **no discount**. **Both are correct today** (legacy is the billing authority). **Record it** — evidence for the D-03 decision | |
| 9.9 | **CSRF proof:** 🔍 View Source, search the csrf token name | A hidden input **must** exist. Missing ⇒ every POST 403s blank | |

⛔ **Parent Requests** — no admin surface exists (SE-003). Nothing to test.

---

## 10 — APPROVAL WORKFLOW [WRITES] 🌱

> **Production currently holds ONE `studentEntitlements` doc, `status=discontinued`, inert.**
> **Without seeding, every test below can only render an empty state.**
> **Seed:** use §9's form to propose one concession on a **test student, never a real one.**

| # | Action | Expected | P/F |
|---|---|---|---|
| 10.1 | Before seeding, open Approvals | *"Nothing awaiting approval, and no active entitlement to discontinue."* | |
| 10.2 | 🌱 Propose a concession via §9 | Green success message | |
| 10.3 | Reload. Approvals block | One row: type · value · `pending approval` + **Approve** and **Reject** | |
| 10.4 | Summary card | Entitlements shows an amber **"1 pending"** chip | |
| 10.5 | Rail | **Approve Entitlement** now **enabled** | |
| 10.6 | Click **Approve** | Green *"Entitlement approved."* Row re-renders as `active` with **Discontinue** | |
| 10.7 | **Self-approval block** — approve an item **you proposed yourself** | ⚠️ Depends on the I5 rule. If refused, that is **correct** — record the message | |
| 10.8 | Click **Discontinue** → the reason prompt → press **Cancel** | **Nothing happens. No request fires.** 🔍 confirm in Network | |
| 10.9 | **Discontinue** → enter a reason → OK | Green message; row disappears from the block | |
| 10.10 | **Reject** with an empty reason (space only) | Aborts client-side, or the server refuses. **Never records an unexplained decision** | |
| 10.11 | 🔍 Network on any of the above | POST to `entitlements/approve\|reject\|discontinue` → **200**. **No direct Firestore write from this page** | |
| 10.12 | After any action | Message ends *"Reload to refresh available actions."* (**D-08**, known) | |

---

## 11 — CROSS-MODULE NAVIGATION ✅

| # | Click in the rail | Expected | P/F |
|---|---|---|---|
| 11.1 | **Collect Payment** | Fees counter, **student pre-selected** via `?uid=` | |
| 11.2 | **Open Fee Ledger** | That student's ledger | |
| 11.3 | **Suspend / Resume / Change Route** | Transport **assignments page** (not a JSON error) | |
| 11.4 | **Issue Transfer Certificate** | Certificates → generate | |
| 11.5 | **Add Concession / Approve / Discontinue / Preview** | ⚠️ **Must NOT navigate.** Switches to the Entitlements tab and scrolls | |
| 11.6 | Every link | **No page shows raw JSON.** Raw JSON ⇒ a page/panel target is mis-declared | |

---

## 12 — EMPTY STATES, ERRORS, MOBILE ✅

| # | Action | Expected | P/F |
|---|---|---|---|
| 12.1 | Brand-new student: every tab | Fees `₹0.00` · Transport "No transport assigned" · Entitlements empty form · History "No recorded history" — **no blank panels, no errors** | |
| 12.2 | 🔍 Stop the network (DevTools → Offline), click **Approve** | Red message. **Never a silent success** | |
| 12.3 | 🔍 DevTools → Network → throttle to Slow 3G, reload | Page still renders; no half-drawn layout | |
| 12.4 | Resize to **375px** (iPhone SE) | Columns stack; action rail below panels; **no horizontal page scroll** | |
| 12.5 | At 375px, the Fees table | Scrolls **inside its own container**, not the page | |
| 12.6 | Keyboard: Tab to the tabs, press **←/→** | Tabs change and follow focus | |
| 12.7 | Tab through the rail | Enabled actions focusable with a visible ring; **greyed actions skipped** | |
| 12.8 | Dark mode / theme toggle if your build has one | Text stays legible — no white-on-white | |

---

## 13 — PERFORMANCE, READ BUDGET & THE NO-WRITE PROOF ✅

**Run this last.**

| # | Action | Expected | P/F |
|---|---|---|---|
| 13.1 | 🔍 Network → reload the workspace, read the HTML doc timing | Record it. **> 3 s on a normal student is a finding** | |
| 13.2 | Compare a student with **many** demands vs **few** | The heavy student will be slower — quantify it (**D-04**: the demands query fetches up to 500 docs) | |
| 13.3 | 🔍 Confirm the timeline is **not** blocking the page | `student360/timeline/...` is a **separate** request; the summary paints first | |
| 13.4 | Open 5 students in a row | No cumulative slowdown | |
| 13.5 | **THE NO-WRITE PROOF.** Re-run the §0.3 snapshot | **Demand / entitlement / receipt counts identical to §0.3** — except any 🌱 seeds you created in §10 | |
| 13.6 | 🔍 Whole session, Network tab: filter for the write endpoints | Only §10 actions appear. **Merely viewing must produce zero writes** | |

---

## 14 — WHAT THIS PLAN STILL CANNOT COVER

Every panel is now built; these are the honest remaining limits:

| Area | Why |
|---|---|
| **Certificate history** 🚫 | CERT-001 — the store is RTDB-only. The panel is built and correctly reports unavailable. Testable only after the Certificates module moves to Firestore |
| **Cross-student request queue** | SE-003 remainder — no such screen exists anywhere. Student 360 is per-student by definition and cannot supply it |
| **Approval workflow / Parent Requests without seeding** 🌱 | production holds one inert `studentEntitlements` doc |
| **Hostel states without seeding** 🌱 | needs a real hosteller and a checked-out student |
| **Parent / Teacher app integration** | Student 360 is admin-only and read-only — **verified zero impact**, nothing to exercise |
| **Attendance % policy variance** | ATT-001 — schools with `include_leave_in_percent` enabled cannot have that verified here; Student 360 shows the module's own stored value, so any mismatch is an Attendance-module issue |

---

## 15 — SIGN-OFF

```
Tester: ______________________  Date: __________  Role(s) used: ______________________

Sections passed: ____ / 14          Tests blocked on seed data: ____

CRITICAL — all five must pass:
  2.7   cross-tenant student  → 404          ☐
  8.4   Fees:edit cannot approve             ☐
  8B.1  no-Attendance role cannot read it    ☐
  8B.7  cross-tenant panel read refused      ☐
  13.5  no writes from viewing               ☐

Evidence to record (numbers, not ticks):
  D-02  S360 outstanding ₹________ vs ledger ₹________
  D-03  active entitlements shown ____  discount actually billed ₹________
  D-07  panel monthly fee ₹________ vs billed transport demand ₹________
  6B.3.3 attendance % — S360 ______%  vs Attendance module ______%
  6B.1  lazy panel requests before any tab click: ____ (must be 0)

Verdict:   ☐ PASS   ☐ PASS WITH DEFECTS   ☐ FAIL
```

**On completion**, report back with the five CRITICAL results, the evidence numbers, and any new
defect. Per your 2026-08-10 direction these all go into **one stabilization phase** — I will fold
them into `STUDENT_360_ARCHITECTURAL_AUDIT.md`, fix the verified set together, and only then do we
discuss freezing the module and returning to Gate 6 / Gate 8.
