# Student Documents — Manual UAT Test Script (step-by-step)

Follow each test in order. Tick **PASS** only if the *Expected result* matches exactly. If anything differs, write it under *Notes* and mark **FAIL** — do not "fix in your head."

---

## 0. Preparation (do this once)

**0.1 — Test files.** Put these on your desktop before starting:
- `photo1.jpg` and `photo2.jpg` — small images, **under 2 MB** each.
- `doc1.pdf` and `doc2.pdf` — PDFs, **under 5 MB** each.
- (Optional) `bad.txt` — a plain text file, to test rejection.

**0.2 — Log in.** Open the Admin Panel in a browser and log in as a user whose role is **Admin / Principal / Vice Principal / Front Office** (a role allowed to manage students). Keep this tab open.

**0.3 — Pick a test student.** Go to **SIS → Students**. Choose ONE existing test student (note their name + ID). On that student's row, open the **⋮ (menu)** and click **Documents**.
> You should land on the page titled **"Documents — <Student Name>"** with an **"Add / Update Document"** panel at the top. This is the page under test. (Direct URL form: `…/sis/documents/<studentId>`.)

**0.4 — Result table (fill in as you go).**

| Test | PASS/FAIL | Notes |
|---|---|---|
| A1 upload predefined ×5 | | |
| A2 upload additional ×2 | | |
| A3 preview | | |
| A4 download | | |
| A5 verify | | |
| A6 reject | | |
| A7 replace + version | | |
| A8 delete managed | | |
| A9 audit history | | |
| A10 invalid file rejected | | |
| B1 legacy-delete safety | | |
| C1 shared Admission→Student | | |
| D1 Parent app | | |
| E1 Teacher app | | |
| F1 Student Profile | | |
| G1–G3 security | | |

---

## A. Core document lifecycle (on the Documents page)

### A1 — Upload every predefined type
1. In **Document type**, select **Aadhaar Card**. Choose **photo1.jpg** in *File*. Click **Upload**.
2. Wait for the green "Uploaded." message; the page reloads.
3. Repeat for **Birth Certificate**, **Transfer Certificate**, **Marksheet**, **Medical Certificate** (use photo1.jpg or doc1.pdf for each).

**Expected:** After each upload, a card appears under **"Documents"** with the correct label, a preview (image thumbnail, or a red PDF icon for PDFs), and an amber **Pending** badge. After all five, you have 5 cards.
**PASS if:** all 5 predefined cards show with correct labels + **Pending** badges.

### A2 — Upload multiple "Additional Documents"
1. Select **Other document…** in *Document type* → a **Document name** box appears.
2. Type `Caste Certificate`, choose doc1.pdf, click **Upload**.
3. Repeat with name `Income Certificate` and photo2.jpg.

**Expected:** Two cards appear under a separate **"Additional Documents"** section, named "Caste Certificate" and "Income Certificate", each with a **Pending** badge.
**PASS if:** both appear under "Additional Documents" (not under the main "Documents" section).

### A3 — Preview every document
1. For each card, look at the thumbnail area.

**Expected:** Image docs show a picture thumbnail; PDF docs show a red PDF icon.
**PASS if:** every card shows a sensible preview/icon (no broken image).

### A4 — Download every document
1. On each card, click the **download** button (down-arrow icon).

**Expected:** The file opens in a new tab (image displays / PDF opens). It's the file you uploaded.
**PASS if:** every document opens and is the correct file.

### A5 — Verify a document
1. On the **Aadhaar Card** card, click the green **✓ (check)** button.
2. Confirm any prompt; the page reloads.

**Expected:** The Aadhaar card's badge turns green **Verified**.
**PASS if:** badge shows **Verified** (green).

### A6 — Reject a document
1. On the **Birth Certificate** card, click the red **✗ (cross)** button. Confirm the prompt.

**Expected:** The Birth Certificate badge turns red **Rejected**.
**PASS if:** badge shows **Rejected** (red).

### A7 — Replace a predefined document (version handling)
1. On the **Marksheet** card, click the **Replace** button (circular-arrows icon).
2. In the file picker, choose a *different* file (photo2.jpg or doc2.pdf).

**Expected:** Page reloads; the Marksheet card now shows **"Version 2"**, its badge is back to **Pending**, the preview is the new file, and downloading it gives the *new* file. There is still only ONE Marksheet card (not two).
**PASS if:** shows **Version 2**, new file, single card, badge **Pending**.

### A8 — Delete a managed document
1. On the **Income Certificate** (Additional) card, click the red **🗑 (trash)** button. Read the confirm dialog, click OK.

**Expected:** Page reloads; the Income Certificate card is gone. (The other documents remain.)
**PASS if:** the card disappears and nothing else is affected.

### A9 — Audit / document history
1. Click **Document History** (top-right of the page).

**Expected:** A panel lists recent events for THIS student — you should see entries like *upload*, *verify*, *reject*, *replace*, *delete*, each with who + when.
**PASS if:** the upload/verify/reject/replace/delete you just did all appear.

### A10 — Invalid file rejected (safety)
1. Select any type, choose **bad.txt** (or a >5 MB file), click **Upload**.

**Expected:** A red error ("unsupported file type" / size error). No card is created.
**PASS if:** upload is refused with a clear message.

---

## B. Legacy-delete safety (managed doc cannot bypass the service)

> This proves a managed document can never be raw-deleted through the old endpoint.

### B1 — (Optional, technical) Direct legacy-delete of a managed doc
1. Note the label of one managed document still on the page (e.g. "Transfer Certificate").
2. In the browser console on the Documents page, run:
   ```js
   fetch(BASE + 'sis/delete_document', {method:'POST',
     headers:{'Content-Type':'application/x-www-form-urlencoded','X-Requested-With':'XMLHttpRequest'},
     body: new URLSearchParams({user_id: STU_ID, doc_label: 'Transfer Certificate',
        [csrfName]: csrfToken}).toString()}).then(r=>r.json()).then(console.log)
   ```
3. Reload the page.

**Expected:** The response is `{status:"success"}`; the Transfer Certificate is removed; **Document History shows a normal delete event** (it went through the service, not a raw wipe). If that document were shared with an admission record, that admission copy would remain intact.
**PASS if:** it deletes cleanly via the service and history records it.
> If you're not comfortable with the console, skip B1 — it's already proven by the automated live test.

---

## C. Shared Admission → Student (highest priority)

> Goal: a document uploaded during Admission is *referenced* by the student after enrollment; deleting it from the student must NOT break the admission copy.

### C1 — Full flow
1. **Create the admission doc:** open the **public admission form** (`…/admission/<school_id>`) OR **Admissions → Applications** and create/open an application. Upload an **Aadhaar** (and/or other) document on it, and submit.
2. In **Admissions → Applications**, open that application → confirm the document **previews** in the detail panel. **Approve** it, then **Enroll** the student (assign class/section; a fee structure must exist for that class/section).
3. Go to **SIS → Students → (the new student) → Documents**.
   > The carried-over admission document appears under **"Legacy Documents"** (view/download). *This is expected* — the admission-side document is referenced, not re-uploaded, so it shows as a legacy card on the student page.
4. Click **View** on it → confirm it opens (same file).
5. Delete it from the student: click the red **🗑** on that legacy card → confirm.
6. Reload the student Documents page.
7. Go back to **Admissions → Applications → that application** and open its detail panel.

**Expected:**
- After step 6: the document is removed from the **student's** page.
- After step 7: the **admission application still shows the document, and it still previews/downloads** (the shared file was NOT deleted).
**PASS if:** student reference removed **AND** admission copy still previews/downloads.

---

## D. Parent App compatibility

### D1 — Parent app still shows documents
1. Log into the **Parent App** as the parent of the test student (the one you uploaded docs for in section A).
2. Open the student's profile / documents area.

**Expected:** The documents you uploaded in section A appear; tapping one previews/opens it.
**PASS if:** documents list + preview/download work, with no change needed to the app.

---

## E. Teacher App compatibility

### E1 — Teacher app still shows documents
1. Log into the **Teacher App** as a teacher who can view that student.
2. Open the student's document view.

**Expected:** The same documents appear and open.
**PASS if:** documents list + preview/download work in the Teacher app.

---

## F. Student Profile compatibility

### F1 — Profile Documents tab
1. In the Admin Panel go to **SIS → Students → (test student) → Profile** (the profile page, not the Documents page).
2. Click the **Documents** tab.

**Expected:** The documents list appears (from the synced data); layout looks normal; no errors.
**PASS if:** the profile Documents tab shows the documents with no visual/UI regression.

---

## G. Security spot-checks

### G1 — Ownership (no cross-student)
1. On Student A's Documents page, open the browser console and try to delete using **another student's** id:
   ```js
   fetch(BASE + 'sis/delete_student_document', {method:'POST',
     headers:{'Content-Type':'application/x-www-form-urlencoded','X-Requested-With':'XMLHttpRequest'},
     body: new URLSearchParams({user_id: '<OTHER_STUDENT_ID>', document_id: '<A_DOC_ID_FROM_STUDENT_A>',
        [csrfName]: csrfToken}).toString()}).then(r=>r.json()).then(console.log)
   ```

**Expected:** `{status:"error", message:"Document does not belong to this student."}` — nothing is deleted.
**PASS if:** rejected.

### G2 — Authorization (role)
1. Log in as a **low-privilege** user (e.g. a plain Teacher role, if you have one) and try to open `…/sis/documents/<id>` or upload.

**Expected:** Access denied / not permitted (the manage actions require Admin/Principal/Front-Office).
**PASS if:** a non-manager cannot upload/verify/delete.

### G3 — Tenant isolation (no cross-school)
> Already proven by the automated live test (cross-school read returns nothing). If you have two schools, confirm School A's admin cannot open School B's student documents URL.

**PASS if:** cross-school access is blocked.

---

## Sign-off

- If **every** test is PASS → the module is confirmed working end-to-end; reply "UAT passed" and I'll prepare the commit (I will not commit until you say so).
- If any test is **FAIL** → send me the test ID + what you saw; I'll investigate before any commit.
