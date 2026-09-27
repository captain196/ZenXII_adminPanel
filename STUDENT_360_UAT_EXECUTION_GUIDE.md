# Student 360 — UAT Execution Guide

**Runbook for the operator.** Companion to `STUDENT_360_UAT_TEST_PLAN.md` (the full checklist).
This document is the one to keep open while testing: it says where to click, what to expect, what to
write down, and what counts as a failure.

**Status: VERIFICATION MODE.** Feature development is frozen — no new panels, actions, or UI.
**Environment:** `http://localhost:8080` · **Branch:** `integration/phase2` · working tree held.

---

## HOW THIS IS ORDERED, AND WHY

Not by feature — by **what invalidates the most work if it fails.**

| Block | Why here | Time |
|---|---|---|
| **0 · Prepare** | Pick students and record a baseline. Without this, later cross-checks have nothing to compare against | 15 min |
| **1 · Smoke** | If the page doesn't load, nothing else is worth running. Fail fast | 10 min |
| **2 · Security** 🔴 | If data leaks across tenants or roles, correctness is irrelevant. **Stop on failure** | 25 min |
| **3 · Numbers** | Money and marks, cross-checked against source modules. The heart of the UAT | 45 min |
| **4 · Panels** | Content and empty states, once the numbers are trusted | 30 min |
| **5 · Alerts & rail** | Aggregation is only meaningful once its inputs are verified | 20 min |
| **6 · Writes** 🌱 | Deferred deliberately — these change state, so everything read-only runs first against a clean baseline | 30 min |
| **7 · Cost & no-write proof** | **Must be last.** Compares against the Block 0 fingerprint | 15 min |

**Total ≈ 3 hours.** Blocks 0–3 alone (~1.5 h) give the highest confidence per minute; if you have
only one sitting, do those.

### Rules for the whole run

1. **Record every defect. Fix nothing** — unless it stops you continuing. Then note the workaround
   and carry on.
2. **A blocked test is not a failed test.** Mark 🌱 tests as `BLOCKED — no data` and move on.
3. **Both feature flags must stay `false`.** If anything asks you to flip one, stop and tell me.
4. Keep **DevTools → Network** open, filtered to `student360`, for every block.

---

## BLOCK 0 · PREPARE (15 min)

### 0.1 Clear the error log

**Navigate:** the file system → `c:\xampp\htdocs\ZenX\school\application\logs\`
**Do:** rename today's `log-2026-08-10.php` to `log-2026-08-10.BEFORE-UAT.php` (do not delete).
**Why:** a fresh file means every line in it afterwards was produced by your testing.

### 0.2 Choose five test students

**Navigate:** `http://localhost:8080/sis` → the student list.
**Do:** find and write down IDs for:

| Slot | Needs | How to spot it |
|---|---|---|
| **A — rich** | dues, transport, some history | any long-enrolled student |
| **B — clean** | no dues, ideally no transport | recently paid in full |
| **C — inactive** | status ≠ Active | the **Status** column shows Inactive |
| **D — hosteller** 🌱 | a hostel allocation | check the Hostel module for a name |
| **E — with results** 🌱 | published exam results | check `result/class_result` for a class that has them |

```
A = ____________   B = ____________   C = ____________
D = ____________   E = ____________
```

> If D or E don't exist, that is itself a finding — record *"no hosteller in this tenant"* and skip
> those tests. Do not invent data.

### 0.3 Record the baseline fingerprint

**Navigate:** `sis/profile/{A}` and the Fees module for student A.
**Capture, before touching Student 360:**

```
A: outstanding ₹________  collected ₹________  demand count ______
A: transport route ____________  suspended? Y/N
A: active entitlements ______   receipts on file ______
```

**Why:** Block 7 proves Student 360 wrote nothing. That proof needs a *before*.

---

## BLOCK 1 · SMOKE (10 min)

| # | Navigate | Do | Expect | Capture | ❌ Fail if |
|---|---|---|---|---|---|
### 1.0 Entry points — there are now **four** (added 2026-08-10)

| # | Navigate | Do | Expect | Capture | ❌ Fail if |
|---|---|---|---|---|---|
| 1.0.1 | any page | Press **Ctrl-K**, type part of student A's name, press **Enter** | Opens **Student 360** for A | 📸 `00a-search.png` | Lands on `sis/profile` instead |
| 1.0.2 | same | Check the permission is unchanged | A role without `SIS:view` still cannot reach it | — | Search grants access it did not before |
| 1.0.3 | Dashboard | In **Top Fee Defaulters**, click a student's row | Opens **Student 360** for them | 📸 `00b-defaulter.png` | Goes to the fee counter, or nowhere |
| 1.0.4 | Dashboard | In **Absent Today**, click a student's row | Opens **Student 360** | 📸 `00c-absent.png` | Row is not clickable — ⚠️ means the payload lacks `studentId`; **record it** |
| 1.0.5 | Dashboard | Hover an Absent row | Cursor shows it is a link | — | No affordance |

> **1.0.3 is a deliberate behaviour change.** That row previously went straight to the fee counter.
> It now opens Student 360, where **Collect Payment** sits in the action rail with the dues already on
> screen — one extra click to payment, in exchange for seeing first whether the family has an
> unanswered concession request, suspended transport, or a withheld result. **If you would rather have
> the direct-to-counter path back, say so — it is a one-line revert.**

### 1.1 The workspace loads

| # | Navigate | Do | Expect | Capture | ❌ Fail if |
|---|---|---|---|---|---|
| 1.1 | `sis` | Click the **⋮** on student **A**'s row | A menu opens with **Student 360** at the top | — | No such item |
| 1.2 | same | Click **Student 360** | Workspace loads at `/student360/view/{A}` | 📸 `01-workspace.png` (full page) | Blank page, 404, or PHP error |
| 1.3 | the page | Look at the header | Name, ID, class/section, father, session, status chip | — | ⚠️ Any field blank that SIS shows populated — this is **S360-U2**, fixed 2026-08-10; a blank here means it regressed |
| 1.3a | **Academic** tab | Read the **Address** row | A real address — e.g. `12 MG Road, Kanpur, UP, 208001` | 📸 `01a-address.png` | ⚠️ The literal word **`Array`**, or a PHP warning on the page. That is **S360-U1**, fixed 2026-08-10 |
| 1.3b | the page | Open **two** students admitted at different times (an old one and a recent one) | Both headers fully populated | — | One is blank → the capitalised-schema fix regressed |
| 1.4 | the page | Count the tabs | **13**: Fees · Entitlements · Parent Requests · Receipts · Academic · Results · Discipline · Attendance · Transport · Hostel · Documents · Certificates · History | 📸 `02-tabs.png` | Fewer than 13 (unless a role gate — note which) |
| 1.5 | DevTools → Console | Reload | **No red errors** | 📸 if any | Any uncaught JS error |
| 1.6 | DevTools → Network | Reload, filter `student360` | `view` and `timeline` only — **no `panel/` requests** | 📸 `03-network-initial.png` | Any `panel/` request before you click a tab |

> **1.6 is the lazy-loading contract.** If panels load eagerly, the read-budget claim in the audit is
> void and I need to know before you go further.

---

## BLOCK 2 · SECURITY 🔴 (25 min) — STOP ON FAILURE

These five are the CRITICAL set. **If any fails, stop the UAT and report immediately.**

### 2.1 Tenant isolation

| # | Navigate | Do | Expect | Capture | ❌ Fail if |
|---|---|---|---|---|---|
| 2.1.1 | address bar | `/student360/view/STU9999999` | **404 page** | 📸 | Any student data, or a different error revealing existence |
| 2.1.2 | address bar | `/student360/view/` + a **real ID from another school** | **404** — identical to 2.1.1 | 📸 `04-tenant-404.png` | Data appears, **or** a *different* message than 2.1.1 (that difference alone is an enumeration oracle) |
| 2.1.3 | address bar | `/student360/context/{other school's ID}` | JSON error | 📋 paste the JSON | Any `context` payload |
| 2.1.4 | address bar | `/student360/panel/attendance/{other school's ID}` | JSON error | 📋 | Any attendance data |
| 2.1.5 | address bar | `/student360/view/../../etc/passwd` | 404 | — | Anything else |

### 2.2 Role gating — needs role changes + re-login

**Navigate:** `http://localhost:8080/staff_access` → pick a test staff member → adjust modules.
**⚠️ After every role change you must log out and back in** — permissions are cached in the session.

| # | Role setup | Do | Expect | Capture | ❌ Fail if |
|---|---|---|---|---|---|
| 2.2.1 | `SIS:view` **only** (no Fees, Attendance, Results, Red Flags, Hostel) | Open student A's workspace | Page loads; most tabs **absent** | 📸 `05-minimal-role.png` | A tab appears for a module the role lacks |
| 2.2.2 | same | Click **Attendance** if present | Refused — an error, not data | 📋 | ⚠️ **Attendance data appears → CRITICAL** |
| 2.2.3 | same | `/student360/panel/results/{A}` in the address bar | JSON error | 📋 paste | ⚠️ **Any marks payload → CRITICAL** |
| 2.2.4 | same | `/student360/context/{A}` — search the JSON for `"flags"` | `flags` empty **and** `activeCount: 0` | 📋 paste the `discipline` block | ⚠️ **Any flag detail or a non-zero count → CRITICAL.** Hiding a tab is not enough; the payload must be empty |
| 2.2.5 | same | Look at the alert strip | **No** discipline alert, **no** fee alert | 📸 | *"N active discipline flags"* appears — that discloses the count |
| 2.2.6 | `Fees:edit` (not `manage`) | Open the Entitlements tab | **No Approve/Reject buttons** | 📸 `06-fees-edit.png` | Approve is clickable |
| 2.2.7 | same 🔴 | POST to `entitlements/approve` directly (DevTools console or curl) | **Server refuses** | 📋 paste response | ⚠️ **Approval succeeds → CRITICAL.** The hidden button is a courtesy; this is the boundary |

**Restore a full-access role before Block 3.**

---

## BLOCK 3 · THE NUMBERS (45 min) — the heart of the UAT

Every test here compares Student 360 against the module that owns the data. **Write the actual
numbers down, not a tick** — a mismatch is the finding, and I need both values.

### 3.1 Fees

| # | Navigate | Do | Expect | Capture | ❌ Fail if |
|---|---|---|---|---|---|
> **Money semantics — locked 2026-08-10, not yet implemented.** Student 360's primary figure will
> mean **Amount Due** (collectable) = *Demand Outstanding* − *applicable On-demand Discount*.
> **The UAT tenant holds no discount record, so the two are equal for every student you test** —
> expected values below are unaffected. **Do not create a discount to test this.** Full decision in
> `STUDENT_DISCOUNTS_FORENSIC_AUDIT.md` §V.

| 3.1.1 | student A workspace | Read the **Outstanding** card | Matches your Block 0 baseline | `S360 ₹____ / baseline ₹____` | Any difference |
| 3.1.2 | Fees tab | Compare Billed / Collected / Outstanding | Outstanding = Billed − Collected | 📸 `07-fees.png` | Arithmetic doesn't hold |
| 3.1.3 | `fees/student_ledger?uid={A}` | Compare the ledger total | ⚠️ **Record both even if they match** | `S360 ₹____ / ledger ₹____` | — (a mismatch is **D-02**, already known — confirm the size) |

### 3.2 Receipts — new (A-3)

| # | Navigate | Do | Expect | Capture | ❌ Fail if |
|---|---|---|---|---|---|
| 3.2.1 | **Receipts** tab | Open it | Table: Receipt No / Date / Amount / Mode / Collected by / Status | 📸 `08-receipts.png` | Empty for a student you know has paid |
| 3.2.2 | same | Check ordering | **Newest first** | — | Any other order |
| 3.2.3 | same | Read **Total collected** vs the Fees tab's **Collected** | ⚠️ Record **both numbers** | `receipts ₹____ / fees ₹____` | They differ → **new Fees finding** (different collections) |
| 3.2.4 | same | Scan the Amount column | No `₹0.00` rows | 📸 if any | ⚠️ **Any ₹0.00 → report.** Suggests an unknown field alias |
| 3.2.5 | Fees module | Pick one receipt no. and compare amount/date/mode | Identical | `receipt #______` | Any field differs |
| 3.2.6 | Network tab | Watch the request | One `panel/receipts/` → **200** | — | **500** → suspect the `feeReceipts` composite index; note it and continue |

### 3.3 Results — new (A-1)

| # | Navigate | Do | Expect | Capture | ❌ Fail if |
|---|---|---|---|---|---|
| 3.3.1 | student **E** → **Results** | Open it | Cards: Latest exam · % · Grade · Rank · Result | 📸 `09-results.png` | Empty for a student with published results |
| 3.3.2 | `result/student_result/{E}` | Compare **percentage and grade** | ⚠️ **Must match exactly** | `S360 ____% ____ / module ____% ____` | ⚠️ **Any mismatch → HIGH.** S360 displays stored values and never recomputes; a difference means the Result module disagrees with its own published record |
| 3.3.3 | Results tab | Find a student whose results are **not computed** | Percentage shows **—** | 📸 | ⚠️ **`0.00%` shown → report.** "Not computed" ≠ "scored zero" |
| 3.3.4 | Results tab | Look for an absent subject | Grey **absent** chip | — | `0` marks shown instead |
| 3.3.5 | Exam module | Check whether a **draft/staged** result exists for E | ⚠️ It must **NOT** appear in Student 360 | — | ⚠️ **A draft appears → HIGH.** Only published results may show |
| 3.3.6 | rail | Click **Open Full Result** | Lands on the Result page **with E already selected** | 📸 | You have to search for the student again |

### 3.4 Attendance

| # | Navigate | Do | Expect | Capture | ❌ Fail if |
|---|---|---|---|---|---|
| 3.4.1 | **Attendance** tab | Open | Month rows, newest first | 📸 `10-attendance.png` | Wrong order |
| 3.4.2 | Attendance module, same student+month | Compare one month's **%** | ⚠️ **Must match** | `S360 ____% / module ____%` | ⚠️ Mismatch → Attendance-module finding (S360 shows the stored value) |
| 3.4.3 | Attendance tab | Look at the session total row | Counts summed; **% column shows `—`** | — | A session % appears — that would mean policy was invented |

### 3.5 Transport & Discipline

| # | Navigate | Do | Expect | Capture | ❌ Fail if |
|---|---|---|---|---|---|
| 3.5.1 | **Transport** tab | Compare route/stop/fee against the Transport module | Identical | `S360 ₹____ / module ₹____` | Any difference |
| 3.5.2 | same | Compare **monthly fee** with the actual billed transport demand | ⚠️ Record both — this is **D-07** | `panel ₹____ / billed ₹____` | — (known; confirm the size) |
| 3.5.3 | **Discipline** tab 🌱 | Compare flags against the Red Flags module | Same flags, same severities | 📸 `11-discipline.png` | Missing or extra flags |
| 3.5.4 | same | Check for a deleted flag | ⚠️ Must **not** appear | — | A soft-deleted flag is listed |

---

## BLOCK 4 · PANELS & EMPTY STATES (30 min)

| # | Navigate | Do | Expect | Capture | ❌ Fail if |
|---|---|---|---|---|---|
| 4.1 | any tab | Click each of the 13 in turn | Each renders; no console error | 📸 `12-panels-*.png` for any that look wrong | Any blank panel |
| 4.2 | Network | Click **Attendance**, then away, then back | **One** request only — never re-fetched | 📸 `13-network-lazy.png` | A second request |
| 4.3 | any lazy tab | Double-click it fast | **Exactly one** request | — | Two requests |
| 4.4 | **Academic** | Open it | Renders **with no network request** | — | Any request fires |
| 4.5 | **Certificates** | Open it | ⚠️ **A written explanation** that the store is RTDB-only | 📸 `14-certificates.png` | An empty table or *"no certificates"* — those assert a different fact. **Reporting unavailable is a PASS** |
| 4.6 | **Documents** | Open it | Document / Role / Uploaded / Status | 📸 | Empty for a student with uploads |
| 4.7 | same | Look for *"N document record(s) could not be read"* | Ideally absent | 📋 if present | Present → Document Service integrity finding |
| 4.8 | student **B** (clean) | Open every tab | Sensible empty states, **never** ₹0-as-data or a blank box | 📸 `15-empty-states.png` | A blank panel with no message |
| 4.9 | student **C** (inactive) | Open the workspace | Status chip shows inactive; grey info alert | 📸 | Presented as active |
| 4.10 | browser | Narrow the window to ~400px | Layout stacks; **no horizontal page scroll** | 📸 `16-mobile.png` | The page scrolls sideways |
| 4.11 | keyboard | `Tab` to the tab row, then ← → | Arrows move between tabs | — | Keyboard trapped |

---

## BLOCK 5 · ALERT STRIP & ACTION RAIL (20 min)

| # | Navigate | Do | Expect | Capture | ❌ Fail if |
|---|---|---|---|---|---|
| 5.1 | student A | Look **above** the summary cards | Alert strip present | 📸 `17-alerts.png` | Missing, or below the cards |
| 5.2 | same | Compare the dues alert to the Outstanding card | **Identical amount** | — | Any difference |
| 5.3 | same | Check ordering | Red → amber → grey | — | An amber alert above a red one |
| 5.4 | same | **Click** an alert | Jumps to that tab and scrolls to it | 📸 | Nothing happens |
| 5.5 | same | Click an alert for a **lazy** tab (Results) | Switches tab **and** triggers the fetch | — | Tab opens still showing "Loading…" |
| 5.6 | student **B** (clean) | Look at the strip | Green *"No operational alerts for this student."* | 📸 `18-allclear.png` | ⚠️ **Blank space.** Silence and "all clear" must not look identical |
| 5.7 | any student with exactly 1 flag | Read the wording | *"1 active discipline flag"* — singular | — | "1 flags" |
| 5.8 | rail | Count the actions | Up to **16**, grouped: Fees · Transport · Hostel · Academic · Lifecycle · Records | 📸 `19-rail.png` | Ungrouped or duplicated |
| 5.9 | student **B** | Look at **Collect Payment** | Greyed, *"Nothing outstanding"* | 📸 | Enabled |
| 5.10 | student **C** | Look at **Issue TC** | Greyed with a reason | — | Enabled for an inactive student |
| 5.11 | any greyed action | Try to click it, then Tab to it | Cannot activate | — | It navigates |
| 5.12 | a day scholar | Look at **Manage Hostel Allocation** | ⚠️ **Enabled** — intentional (D-09) | — | — (not a defect; confirm you accept it) |

---

## BLOCK 6 · WRITE WORKFLOWS 🌱 (30 min) — needs seeding

> **Production holds one inert entitlement doc.** Without seeding, 6.2–6.5 can only render empty
> states. Seed via the Parent app or by POSTing to `entitlement_requests/submit`.

| # | Navigate | Do | Expect | Capture | ❌ Fail if |
|---|---|---|---|---|---|
| 6.1 | **Entitlements** tab | Add a concession via the capture form | Saves; appears in the list | 📸 `20-capture.png` | Silent failure — ⚠️ check it reports errors, never a phantom success |
| 6.2 | 🌱 same | With a pending item, look at the **Approvals** block | Approve / Reject buttons | 📸 | Missing for a `Fees:manage` role |
| 6.3 | 🌱 same | Click **Approve** | Succeeds; status → active; button disables while working | 📸 `21-approve.png` | Double-submit possible |
| 6.4 | 🌱 same | Reload | Change persisted | — | Reverts |
| 6.5 | 🌱 **Parent Requests** | Seed one, reload | Row appears; **amber dot** on the tab; alert in the strip | 📸 `22-parent-req.png` | No dot or no alert |
| 6.6 | Fees preview | Use **Preview Fees** | A non-zero figure for an admitted student | 📋 the numbers | ₹0 — that is SE-001 regressing |
| 6.7 | any action | After approving, check the rail | ⚠️ It may be stale until reload (**D-08**, known) | — | — (confirm it is only stale, not *wrong*) |

---

## BLOCK 7 · COST & NO-WRITE PROOF (15 min) — MUST BE LAST

| # | Navigate | Do | Expect | Capture | ❌ Fail if |
|---|---|---|---|---|---|
| 7.1 | DevTools → Network | Reload the workspace, note **total time** | Under ~3 s locally | 📸 `23-timing.png` + the number | Over 5 s — record it |
| 7.2 | same | Count requests on initial load | `view` + `timeline` only | — | More |
| 7.3 | same | Open all 6 lazy tabs, count | **6** panel requests, one each | 📸 | Any duplicate |
| 7.4 | Fees / SIS / Transport modules | Re-read every Block 0 baseline number | ⚠️ **Every number identical** | fill in the table below | ⚠️ **Any change → CRITICAL.** Student 360 is read-only; viewing must never write |
| 7.5 | `application/logs/log-2026-08-10.php` | Open it | Only errors you deliberately caused | 📋 paste anything unexpected | Unexplained `Student_context` / `Student_panel_reader` / `Action_registry` errors |
| 7.6 | `application/config/fees_exemption_v2_flags.php` | Confirm | Both flags still `false` | — | Either is `true` |

```
7.4 AFTER:  outstanding ₹________  collected ₹________  demands ______
            entitlements ______   receipts ______   route ____________
            IDENTICAL TO BLOCK 0?   ☐ YES   ☐ NO ← if NO, stop and report
```

---

## RECORDING DEFECTS

Use one block per defect. **Do not fix anything.**

```
── DEFECT ───────────────────────────────────────────────
ID (yours):        UAT-__
Test:              e.g. 3.3.2
What I did:
What I expected:
What happened:
Numbers:           S360 ______  vs  source module ______
Screenshot:        ____.png
Console/log:
Blocks further testing?   ☐ Yes  ☐ No
─────────────────────────────────────────────────────────
```

**Only stop the run for:** a Block 2 security failure, a 7.4 baseline change, or a page that will not
load at all. Everything else: record and continue.

---

## SIGN-OFF

```
Tester: ______________  Date: __________  Roles used: ______________________
Students:  A ______  B ______  C ______  D ______  E ______

Blocks completed: ____ / 8        Tests blocked on seed data: ____

THE FIVE CRITICAL TESTS
  2.1.2  cross-tenant student → 404, identical to unknown-id   ☐
  2.2.3  role without Results cannot read the panel            ☐
  2.2.4  discipline absent from the JSON, not merely hidden    ☐
  2.2.7  Fees:edit cannot approve server-side                  ☐
  7.4    zero writes from viewing                              ☐

KEY NUMBERS
  D-02  S360 outstanding ₹______  vs ledger ₹______
  D-07  transport panel ₹______  vs billed ₹______
  3.2.3 receipts total ₹______  vs fees collected ₹______
  3.3.2 result % S360 ______  vs module ______
  3.4.2 attendance % S360 ______  vs module ______
  1.6   panel requests before any tab click: ____ (must be 0)

Defects found:  Critical ____  High ____  Medium ____  Low ____

Verdict:   ☐ PASS   ☐ PASS WITH DEFECTS   ☐ FAIL
```

**When you finish**, send me the sign-off block, the defect blocks, and any screenshots that show a
mismatch. I will produce the consolidated verified-defects report — categorised Critical/High/Medium/
Low with root cause, business impact, affected files, production-blocking status, recommended fix,
and whether each belongs to Student 360 or another module.

Then, and only then, we begin the single stabilization phase.
