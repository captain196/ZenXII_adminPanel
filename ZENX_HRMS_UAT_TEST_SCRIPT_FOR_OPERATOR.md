# ZENX HRMS — UAT TEST SCRIPT (OPERATOR-EXECUTED)

**For:** you, in a browser · **Prepared by:** Claude, from the implemented code
**Test School:** `SCH_D94FE8F7AD` — **the only tenant that can run payroll** (the other two have zero salary structures)
**Base URL:** `http://localhost/ZenX/school/index.php/`

---

## READ THIS FIRST — three rules

1. **Period: use 2026-05, 2026-06 or 2026-07.** **Never April 2026** — that run is already `Paid`.
2. **Record what you actually see**, including things I did not predict. A step that "looks fine but
   the number is odd" is a finding.
3. **If something fails, stop that test and move to the next one.** Don't work around it — the
   workaround hides the defect.

**Copy the "ANSWER" block under each test and fill it in.** Short answers are fine; a screenshot or
the exact error text is better than a paraphrase.

**Open the browser console (F12 → Console) before you start** and leave it open. Red errors matter.

---

## TEST 1 — Preflight blockers (capability **N-15**, **P-041**, **LC-02**)

**This is the test most likely to find something**, because I know the data: **8 of 11 staff have no
bank details** and only 7 of 11 have a PAN.

### Steps

1. Log in as an HR admin for `SCH_D94FE8F7AD`.
2. Go to **`hr/payroll`**.
3. Click the **Payroll Runs** tab.
4. Click **Generate** (opens the *Generate Payroll* dialog).
5. Set **Month = May**, **Year = 2026**.
6. Click **Generate**. *(The button first runs a pre-flight check — it does NOT generate yet.)*

### What should happen

A panel appears titled **"Pre-flight Check Passed"** with:

- `Staff covered: N / 11`
- A **Warnings** list (prose)
- **A new section: "Affected employees"** with a count like *"· 8 blocked · 4 warning"*
- A scrollable table, **blockers first**, each row showing:
  - employee **name** and department
  - a red line: **"Bank details missing — this employee cannot be paid"**
  - a grey line: **"→ Complete bank/payment details before payroll generation"**
- Amber rows: **"PAN not on record — required once TDS deduction is enabled"**

### The specific things I need confirmed

| # | Question |
|---|---|
| 1a | Does the **"Affected employees"** section appear at all? |
| 1b | How many rows are **red (blocker)** and how many **amber (warning)**? |
| 1c | Are employees named, or do you only see counts? |
| 1d | Do the red rows say **bank details**, and roughly 8 of them? |
| 1e | Any red errors in the browser console? |

> **Why this matters:** the bank-detail check existed since W2.3 and the named blockers since W3.1,
> but they were **never connected** until now. If 1a fails, my wiring is wrong.

```
ANSWER — TEST 1
1a: 
1b: blockers = ___   warnings = ___
1c: 
1d: 
1e: 
Anything unexpected:
```

---

## TEST 2 — Generate the run (**LC-01**, **N-17**, **SN-01/02/03**)

### Steps

1. Continue from Test 1. Click **Confirm & Generate**.
2. Wait for it to finish. **Note roughly how long it takes.**

### What should happen

Either a success toast, or a **warnings panel** listing skipped employees by name.

### Confirm

| # | Question |
|---|---|
| 2a | Did it succeed? Exact message? |
| 2b | **How many seconds** did it take? *(This is the only real-world timing evidence available — it feeds capability `N-19`.)* |
| 2c | How many staff got slips vs the 11 total? |
| 2d | Any warning naming a specific employee? Paste them. |
| 2e | Any message containing **"Payslip write failed for"**? *(That would mean the new batched write partly failed — important.)* |
| 2f | Console errors? |

```
ANSWER — TEST 2
2a: 
2b: ____ seconds
2c: 
2d: 
2e: 
2f: 
```

---

## TEST 3 — The review grid (**P-043**)

### Steps

1. Payroll Runs tab → click the run you just created → it opens the **Payslips** list.

### What should happen

The table header should read, left to right:

`# · Staff · Basic · Allowances · **Adjustments** · Gross · Deductions · Net · Days Worked · Absent · Paid Leave · LWP · Status`

**Adjustments** is new. With no overrides yet, every row should show a grey **`—`**.

### Confirm

| # | Question |
|---|---|
| 3a | Is there an **Adjustments** column, between *Allowances* and *Gross*? |
| 3b | Does every row show `—` in it? |
| 3c | **Do the columns line up with the headers?** *(A colspan mistake would shift every cell — I changed the column count.)* |
| 3d | Does clicking a row expand a detail panel? |

```
ANSWER — TEST 3
3a: 
3b: 
3c: 
3d: 
```

---

## TEST 4 — Calculation trace (**P-045**) — *the one I most want verified*

### Steps

1. Click any employee row to expand it.
2. Click **"Why is this net pay?"**.

### What should happen

A bordered panel with a **green banner**: *"Trace reconciles with the stored payslip to the paisa."*
Then ~10–12 numbered steps: Employment window → Contracted compensation → Attendance and loss of pay
→ Proration → Earnings after proration → Gross → Statutory deductions → Other deductions → Net pay →
Employer cost.

### Confirm — please be precise here

| # | Question |
|---|---|
| 4a | Is the banner **green** ("reconciles… to the paisa"), **red** ("does not reconcile"), or **amber** ("no trace recorded")? |
| 4b | In **Contracted compensation** vs **Earnings after proration** — are the Basic figures **different**? *(They should be, if the employee had any absence. Same value with absences present = a bug.)* |
| 4c | In **Statutory deductions**: is **"PF base (earned basic)"** a *different* number from **"ESI base (earned gross)"**? *(They must differ — PF is on basic, ESI on gross. Identical values = a real bug.)* |
| 4d | Does **PF base × PF rate %** equal the **PF (employee)** figure shown? |
| 4e | Does **Gross − Total deductions = Net pay** exactly? |
| 4f | Under **Statutory**, is there a note saying *"No tenant statutory policy is configured…"*? *(Expected — statutory is unconfigured.)* |
| 4g | At the bottom, a grey line about the **statutory snapshot** not being configured? |
| 4h | Console errors? |

> **4a is the headline.** An amber banner on a newly generated run would mean the trace isn't being
> written. A **red** banner means the trace and the payslip disagree — report that immediately.

```
ANSWER — TEST 4
4a: 
4b: contracted basic = ______   earned basic = ______
4c: PF base = ______   ESI base = ______
4d: 
4e: gross ____ − deductions ____ = net ____ ?
4f: 
4g: 
4h: 
```

---

## TEST 5 — Create an override (**N-01**, **P-044**)

### Steps

1. In the same expanded row, find **"Add payroll-period adjustment"**.
2. **Type** = `Bonus`, **Amount** = `5000`, **Reason** = `UAT test bonus for verification` (≥10 chars).
3. Click **Save**.

### What should happen

Green message: *"Adjustment saved. Recalculate the employee to apply it."*

**The payslip should NOT change yet** — saving records it; regeneration applies it.

### Also test the validation (each should be **refused**)

| Input | Expected refusal |
|---|---|
| Amount `0` or `-5000` | *"Amount must be greater than zero…"* |
| Reason `test` (too short) | *"A reason of at least 10 characters…"* |
| No type selected | *"Choose an adjustment type."* |

### Confirm

| # | Question |
|---|---|
| 5a | Does the type dropdown populate? **List the options you see.** |
| 5b | Is **Reimbursement** absent from the list? *(It must be — the engine can't price it correctly yet.)* |
| 5c | Did the valid save succeed? |
| 5d | Were all three invalid inputs refused, with those messages? |
| 5e | Did Net Pay stay **unchanged** after saving? *(It should.)* |
| 5f | Console errors? |

```
ANSWER — TEST 5
5a: options = 
5b: 
5c: 
5d: zero/negative ___ · short reason ___ · no type ___
5e: 
5f: 
```

---

## TEST 6 — Apply it via regeneration (**N-01**, **P-055**, **SN-01/02**)

### Steps

1. **Write down the employee's current Net Pay first: ______**
2. Find **Regenerate** for that employee (in the row detail or the row actions).
3. Provide a reason ≥10 characters, e.g. `UAT applying test bonus adjustment`.
4. Confirm.

### What should happen

The slip recomputes. Reopen the row — the **adjustments panel** should now appear **first**, showing:

```
Original gross      (before adjustments)   ₹ ______
+ Bonus                                    +₹5,000.00
Revised gross                              ₹ ______
Original net                               ₹ ______
Revised net                                ₹ ______
```

### Confirm

| # | Question |
|---|---|
| 6a | Did regeneration succeed? |
| 6b | Does the **Adjustments** column now show **+5,000.00** instead of `—`? |
| 6c | **Is "Revised net" LESS than "Original net + 5,000"?** *(It should be — the bonus raises gross, which raises the ESI/TDS base. If net rose by exactly 5,000, statutory is not being recalculated — that's a bug.)* |
| 6d | Are **both** original and revised values shown, not just the final? |
| 6e | Re-run the trace (Test 4) — is the banner still **green**? |
| 6f | Does the trace now include an **adjustments** step? |

```
ANSWER — TEST 6
Net before: ______
6a: 
6b: 
6c: original net ______ → revised net ______   (difference = ______)
6d: 
6e: 
6f: 
```

---

## TEST 7 — LOP review (**P-042**, **A**) — *may be NOT TESTABLE*

**Read this before testing.** LOP requires **approved unpaid leave**, not just absence marks. May 2026
shows 0 absence marks in my inspection, so the LOP panel may correctly **not appear**.

### Steps

1. On the run's Payslips view, look **above** the table for an amber panel: **"Loss of pay — review
   before finalizing"**.

### Confirm

| # | Question |
|---|---|
| 7a | Does the LOP panel appear? **If no — that is a valid result**, record "not present". |
| 7b | If yes: does it name employees, show **specific day numbers**, and full-vs-half? |
| 7c | If yes: is there a **"Review & confirm"** button? |
| 7d | If you clicked confirm — does it then show *"Confirmed by <your name>"* with a timestamp? |

```
ANSWER — TEST 7
7a: 
7b: 
7c: 
7d: 
```

---

## TEST 8 — Finalization (**LC-05**, **LC-07**, **P-042** gate)

### Steps

1. Finalize the run.
2. Then try to **Regenerate** an employee in the finalized run.

### Confirm

| # | Question |
|---|---|
| 8a | Did finalization succeed? **If it was refused, paste the exact message** — if it mentions LOP confirmation, that is the new gate working. |
| 8b | After finalizing, is **Regenerate refused** with *"Run is Finalized. Call /hr/unlock_payroll first."*? |
| 8c | Is the **"Add payroll-period adjustment"** form now hidden or refusing? |
| 8d | Does **Unlock** work, and does the run return to Draft? |

```
ANSWER — TEST 8
8a: 
8b: 
8c: 
8d: 
```

---

## TEST 9 — RBAC (**T-04**) — *only if you have a second login*

If you have (or can safely make) an **HR:view-only** user:

| # | Question |
|---|---|
| 9a | Can they open the payroll run and see payslips? *(should be yes)* |
| 9b | Is the **"Add payroll-period adjustment"** form **hidden** for them? |
| 9c | **The real test:** with that user logged in, open the browser console and run:<br>`fetch('/ZenX/school/index.php/payroll_overrides/types').then(r=>r.json()).then(console.log)`<br>then the same for a **create** attempt. Does the server **refuse**, or does hiding the button turn out to be the only protection? |

> 9c is the one that matters. A hidden button is not authorization.

```
ANSWER — TEST 9
9a: 
9b: 
9c: 
(or: "no second login available")
```

---

## TEST 10 — ATS convert-to-staff (**P-063**) — **BLOCKED: no applicants exist**

I found **zero HR applicants** in the Test School, so this cannot run as-is.

**This is the highest-value unverified item in the whole programme**: `finalize_hire()` had a
double-tenant-prefix bug meaning hire finalization **never once succeeded in production**. The fix is
still unproven.

**If you authorize it**, the test is:

1. `hr/recruitment` → create one job, then one applicant
2. Move the applicant to **Selected**
3. **Convert to Staff** → confirm the form pre-fills from the applicant
4. Save
5. **Go to the Staff module and confirm the staff record actually exists** ← *the assertion that matters*
6. Reopen the applicant → confirm `staff_id`, `hired_at`, status `hired`, `positions_open` decremented
7. Try converting **again** → must be **refused**

> Step 5 is everything. Steps 1–4 succeeded in production before too — and still produced no staff
> record.

**Afterwards:** deactivate the test staff member, don't delete (preserves audit evidence).

```
ANSWER — TEST 10
Authorized? yes / no
If run — step 5 result (staff record created?): 
step 6: 
step 7: 
```

---

## WHAT TO SEND BACK

Just paste the filled ANSWER blocks. For anything that failed, include the **exact error text** and
whether the browser console showed anything red.

**Most important, in order:**

1. **4a** — trace banner colour (green / red / amber)
2. **6c** — did net rise by *less* than ₹5,000?
3. **1a/1d** — did named bank-detail blockers appear?
4. **2e** — any "Payslip write failed for" message?
5. **9c** — did the server refuse, or only the UI?

I'll triage every failure against its capability ID, fix what belongs to this gate, and update the
master register. **Nothing gets marked COMPLETE without your answer.**
