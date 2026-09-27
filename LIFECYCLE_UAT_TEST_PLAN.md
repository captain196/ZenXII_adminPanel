# CLEAN STUDENT LIFECYCLE — UAT TEST PLAN

**Tenant:** `SCH_B56BB9A401` — VIKRANT PUBLIC SCHOOL · **Session:** `2026-27` · **Login:** `SSA0011`
**Base URL:** `http://localhost:8080/`

---

## BEFORE YOU START — READ THIS

**This is a live working school, not a blank one.** It already holds 23 students, 23 parents, 54 staff,
35 sections, 26 fee demands, 18 timetables and 30 subjects.

**So do not wipe it.** Instead you will admit **two brand-new students** and follow those specific IDs
through every stage. That is a better test than an empty school — it exercises each module against real
existing rolls, sections and fee charts.

### Two facts that make this a genuinely clean run

| | |
|---|---|
| `srNoIndex` = **0** | No student here has an SRNO yet → **your first admission gets SRNO 1** |
| `idCards` = **0** | No card ever issued → the ID-card lifecycle gets its true first run |

### Keep a scratch sheet

Write these down as you go — every later step depends on them:

```
Student A:  STU____  SRNO ____  AdmNo ________  Class ____  Sec ____  Roll ____
Student B:  STU____  SRNO ____  AdmNo ________  Class ____  Sec ____  Roll ____
```

### Rules while testing

- **Never click Print All** on ID Cards — it renders every student in the school.
- Record the **exact wording** of any error. "It failed" cannot be diagnosed.
- If a screen looks wrong, **stop and tell me** rather than clicking through it.

---

# PHASE 0 — PREPARE (10 min)

## 0.1 Log in
`http://localhost:8080/` → `SSA0011` / your password.
✅ **PASS:** header shows *VIKRANT PUBLIC SCHOO… · 2026-27*.

## 0.2 Pick your target section
**Left menu → Student Info System → Student Records**
Choose a class/section with few students — **Class 9th** has only 1, so it is ideal.
Note the existing roll numbers.

📝 Record: `Class ____  Section ____  existing rolls: ____`

## 0.3 Confirm section capacity
**Left menu → School Configuration** → find your class/section → check **Max Strength**.
If unset it defaults to **40**.

⚠️ **Watch for:** capacity showing 0 or blank. Tell me if so.

## 0.4 Confirm a fee chart exists
**Left menu → Fees → Fee Chart** → select your class.
✅ **PASS:** at least one fee head with an amount. Only 2 `feeStructures` exist tenant-wide, so your
class may have none — **if empty, tell me before continuing**; fee steps will produce nothing.

---

# PHASE 1 — ADMISSION + IDENTITY ⭐ *the critical one*

## 1.1 Admit Student A
**Left menu → Student Info System → Admission**

Fill in: Name, DOB, Gender, Father, Mother, Phone, Address, **Class + Section from 0.2**.
Leave SR Number **blank** — the system allocates it.

Click **Save / Submit**.

✅ **PASS — check all four:**
- Success message names a **Student ID** (`STU____`)
- **SR No. = 1** ← the headline check
- An **Admission No.** was allocated
- A **Roll No.** was assigned and does **not** collide with an existing roll in that section

❌ **FAIL / STOP AND TELL ME IF:**
- SR No. is blank, 0, or duplicates another student
- Roll No. collides with an existing student
- A blank page appears (that is a CSRF rejection — no error, no console output)

📝 Record Student A's four identifiers.

## 1.2 Admit Student B — same class and section
Repeat 1.1.

✅ **PASS:** **SR No. = 2**, different Student ID, different Roll No.
❌ **STOP IF:** SR No. is again 1, or the roll collides with Student A.

## 1.3 Verify identity on the record
**Student Info System → Student Records** → search **`1`** (Student A's SR No.).

✅ **PASS:** Student A is found, and **SR No. and Admission No. are shown in separate columns** —
they are different numbers and must never be shown as the same thing.

## 1.4 Upload documents (Student A)
Open Student A → **Documents** tab → upload any small PDF/JPG as **Birth Certificate**.

✅ **PASS:** upload succeeds, thumbnail appears, file is listed.

---

# PHASE 2 — ID CARD ⭐ *first ever run on this tenant*

## 2.1 Open the workspace
**Left menu → Student Info System → ID Cards**

✅ **PASS:** cards render; each shows a status strip. All should read **"No card"** — nothing has been
issued on this tenant.
❌ **STOP IF:** the strip reads *"Card status unavailable"* — the register failed to load.

## 2.2 Search by the school-facing identifiers ⭐
In the search box type **`1`** → then Student A's **Admission No.** → then their **Student ID** → then
part of their **name**.

✅ **PASS:** all four find the student. *(This is the SRNO-4 fix — SR No. search did not work before.)*

## 2.3 Check the printed card hierarchy ⭐
Look at Student A's card.

✅ **PASS — the order and emphasis matter:**
1. **SR No.** first, in the accent colour, **bold** ← primary identity
2. **Adm No.** present
3. **Student ID** below, **faded**
4. Class · Section · Roll on the class line
5. A QR code is rendered

❌ **FAIL IF:** Student ID is the bold/coloured one — that is the old inverted hierarchy (SRNO-5).

## 2.4 Issue the first card
On Student A's strip click **Issue first card**.

✅ **PASS:** no dialog appears *(first issue has only one possible reason, so asking would be friction)*,
a green toast confirms, strip becomes **Active · v1**.

## 2.5 Replace it — lost ⭐
Click **Replace**.

✅ **PASS — the modal must show:**
- Student name, **SR No.**, **Adm No.**, Student ID (faded), **Current card: v1 · active**
- A **Reason dropdown** — *not* a typing box
- An optional **Note** field
- Blue callout: *"current card will be marked replaced and a new card will become active"*

Select **Lost** → confirm.

✅ **PASS:** toast confirms, strip becomes **Active · v2**.

## 2.6 Verify the old card actually died ⭐
Click **History**.

✅ **PASS:** table shows **v2 active** and **v1 replaced**, newest first, with reason `lost` and dates.
This is the whole point of the programme — **v1 is now retired, not merely reprinted.**

## 2.7 Duplicate-click guard ⭐
Click **Replace** → choose **Damaged** → **double-click Confirm fast**.

✅ **PASS:** exactly **one** new version (v3). The second click is refused.
❌ **CRITICAL — TELL ME IF:** you end up at **v4**. That means two cards from one action.

## 2.8 Selective print
Tick **Select** on Students A and B → counter shows **2** → **Print Selected**.

✅ **PASS:** print view opens with **exactly 2 cards**.
Also: with nothing ticked, **Print Selected is disabled**.

⚠️ **Do not click Print All.**

---

# PHASE 3 — FEES

## 3.1 Generate demands
**Left menu → Fees → Generate Demands** → select your class/section → generate.

✅ **PASS:** demands created for A and B.
❌ **STOP IF:** zero created — usually means no fee chart (step 0.4).

## 3.2 Inspect the ledger
**Fees → Student Ledger** → search Student A.

✅ **PASS:** demands listed, each with an **amount, a due date and a balance**.
⚠️ **TELL ME IF the Due Date column is empty** — that is the defect Target #1 addresses.

## 3.3 Take a payment
**Fees → Fee Counter** → Student A → pay **one** demand in full → save.

✅ **PASS:** receipt number issued, balance drops to 0, that demand reads **paid**.

📝 Record the receipt number.

## 3.4 Check the receipt identity ⭐
Print/preview the receipt.

✅ **PASS:** shows **SR No.** and **Roll No.** as separate labelled fields, correct amount and date.
❌ **FAIL IF:** Roll No. appears under an "SR No." or "Admission" label.

## 3.5 Accounting
**Left menu → Accounting** → today's entries.

✅ **PASS:** a journal entry exists for the payment, debit = credit.

---

# PHASE 4 — ATTENDANCE ⚠️ *known defect lives here*

## 4.1 Mark attendance
**Left menu → Attendance → Mark Attendance** → your class/section → today → mark A **Present**,
B **Absent** → save.

✅ **PASS:** saved; percentages update.

## 4.2 The working-day check ⭐
**Attendance → Reports & Logs** → this month → look at the day columns.

⚠️ **This is ATT-WD-1, already known.** Report what you see:
- Are **Saturdays counted as working days**?
- Is your school **Mon–Fri or Mon–Sat**?

If Mon–Fri and Saturdays are counted, **every student's percentage here is understated**. Confirming it
on real data tells us how urgent the fix is.

---

# PHASE 5 — EXAM & RESULT

## 5.1 Create an exam
**Left menu → Examinations → Manage Exams** → create one for your class → add 1–2 subjects.

## 5.2 Enter marks ⭐
**Left menu → Results → Marks Entry** → select exam/class/section.

✅ **PASS:** **Students A and B appear in the list.**
❌ **CRITICAL — TELL ME IF THE LIST IS EMPTY.** That is **EXAM-LC1**, a known HIGH defect where the
roster helper reads only one field shape. An empty list here confirms it on live data.

Enter marks → save.

## 5.3 Result
**Results → Class Results** → generate → open Student A's report card.

✅ **PASS:** marks, total, grade correct; **SR No. shown**, not Roll No. in its place.

---

# PHASE 6 — MOVEMENT ⭐ *SRNO must survive all of it*

## 6.1 Section transfer
**Student Info System → Student Records** → select Student B → **Transfer** to another section in the
same class.

✅ **PASS:**
- Transfer succeeds
- **SR No. is UNCHANGED (still 2)** ← the critical assertion
- Roll No. may change — that is correct and expected
- Student ID unchanged

## 6.2 Promotion
**Student Info System → Promotion** → your class → preview.

✅ **PASS:** preview lists A and B with target class/section, and flags capacity or roll conflicts
**before** committing. Execute if the preview is clean.

✅ **AFTER:** **SR No. unchanged for both.** Class changes; roll may change.
❌ **STOP IF:** SR No. changed, or the run half-completed.

## 6.3 ID card survived movement ⭐
**Student Info System → ID Cards** → find Student A.

✅ **PASS:** card still **Active · v3** (or v2). Promotion and transfer must **not** invalidate a card —
class and section are deliberately not part of the credential.

---

# PHASE 7 — EXIT & RETURN ⭐ *the hardest test*

## 7.1 Issue a TC for Student B
**Student Info System → Transfer Certificates** → select B → issue → print.

✅ **PASS on the printed TC:**
- **"Adm No." shows the Admission Number — NOT the Roll Number** ← this was **SRNO-1**, a real defect
  found and fixed on a legal document
- **SR No.** appears
- "Serial No." is the **TC's own** serial — different from the student's SR No.

## 7.2 Card auto-revoked ⭐
**ID Cards** → find Student B.

✅ **PASS:** status is now **Revoked** — issuing a TC retires the credential automatically.
❌ **FAIL IF:** still Active.

## 7.3 Re-admission ⭐ *the single most important assertion*
**Student Info System → Re-admission** → find Student B by their old Student ID or SR No. → confirm.

✅ **PASS:**
- A **NEW Student ID** is issued (`STU____` — different from before)
- **SR No. is STILL 2** ← the same human being keeps the same school number
- The new record links back via `previousStudentId`

❌ **CRITICAL — STOP IF:** SR No. changed or a new one was allocated. That breaks the identity model.

📝 Record: `B old STU____ → new STU____, SR No. still ____`

---

# PHASE 8 — PERMISSIONS

## 8.1 View-only operator
**Left menu → Admin Users** → create a user with **SIS: view** only (no manage) → log in as them in a
private window → open **ID Cards**.

✅ **PASS:** cards and Print are visible; **Issue / Replace / Revoke buttons are absent**.

## 8.2 Fee-charge separation ⭐
As that same view-only user, look for **Replacement Charges** on the ID Cards toolbar.

✅ **PASS:** **not visible** — it needs `Fees: manage`, deliberately separate from SIS. Setting what a
family is charged is a money decision, not a student-records one.

---

# RESULT SHEET

| # | Test | Pass | Notes |
|---|---|---|---|
| 1.1 | Student A — **SR No. = 1** | ☐ | |
| 1.2 | Student B — SR No. = 2 | ☐ | |
| 1.3 | SR No. ≠ Adm No. in list | ☐ | |
| 1.4 | Document upload | ☐ | |
| 2.2 | Search by SR/Adm/ID/name | ☐ | |
| 2.3 | **SR No. primary on card** | ☐ | |
| 2.4 | First issue → v1 | ☐ | |
| 2.5 | Replace (lost) → v2 | ☐ | |
| 2.6 | History shows v1 replaced | ☐ | |
| 2.7 | **Double-click → one card only** | ☐ | |
| 2.8 | Print Selected = 2 | ☐ | |
| 3.1 | Demands generated | ☐ | |
| 3.2 | **Due dates present** | ☐ | |
| 3.3 | Payment + receipt | ☐ | |
| 3.4 | Receipt identity correct | ☐ | |
| 3.5 | Journal balanced | ☐ | |
| 4.2 | **Saturday handling** | ☐ | |
| 5.2 | **Marks entry lists students** | ☐ | |
| 5.3 | Report card identity | ☐ | |
| 6.1 | Transfer — SR No. survives | ☐ | |
| 6.2 | Promotion — SR No. survives | ☐ | |
| 6.3 | Card survives movement | ☐ | |
| 7.1 | **TC Adm No. ≠ Roll No.** | ☐ | |
| 7.2 | TC revokes card | ☐ | |
| 7.3 | **Re-admission keeps SR No.** | ☐ | |
| 8.1 | View-only sees no controls | ☐ | |
| 8.2 | Charges needs Fees:manage | ☐ | |

---

## THE SIX THAT MATTER MOST

If you only have an hour, run these:

1. **1.1** — SR No. = 1 on first admission
2. **2.7** — double-click Replace produces one card, not two
3. **5.2** — marks entry actually lists your students *(suspected HIGH defect)*
4. **7.1** — TC shows Admission No., not Roll No.
5. **7.3** — re-admission preserves SR No.
6. **4.2** — Saturday handling *(suspected HIGH defect)*

## WHAT I CANNOT DO

I have **no browser and no scan device**, so I cannot click through this or scan a QR. What I *can* do,
if you give me a fresh `grader_session` cookie with the panel running: verify each step **server-side**
immediately after you perform it — confirm the SRNO actually landed, the card version incremented, the
demand carries a due date, the TC recorded the right identifiers.

**Everything I run stays read-only** unless you explicitly ask otherwise.

Report results as `step number → PASS/FAIL → exact wording of any error`.
