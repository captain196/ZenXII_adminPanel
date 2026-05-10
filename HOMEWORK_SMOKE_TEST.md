# Homework module — manual smoke test

Walk through this top-to-bottom on a real device + admin browser. Expected results are shown after each step. Tick the box when the result matches; if it doesn't, jot the symptom on the adjacent line.

---

## 0. Pre-flight checklist

Tick before starting — anything missing here will give false negatives later.

- [ ] **Firestore rules + indexes deployed**
  ```powershell
  cd C:\xampp\htdocs\Grader\school\firebase-rules
  firebase deploy --only firestore:rules,firestore:indexes
  ```
  Wait for "Deploy complete!" and (separately) the email confirming each index has finished building. If any index is still in `Building` state, queries that need it fall back to client-side sort — slower but functional. Don't fail tests during this window.

- [ ] **Teacher app** installed on the device (this build, not the Play Store one).
  ```powershell
  & "$env:LOCALAPPDATA\Android\Sdk\platform-tools\adb.exe" shell pm path com.schoolsync.teacher
  ```
  Output should point at `/data/app/.../base.apk`.

- [ ] **Parent app** installed on the same device (or a second device).

- [ ] **Admin browser** logged in as a Super Admin / School Super Admin / Principal in `/Grader/school`.

- [ ] **Test data** in place:
  - One real student in **Class 8th / Section A** (or whatever section your test teacher teaches). Note the student's UID.
  - One real teacher account assigned to that class via `subjectAssignments`.
  - You have parent-app credentials for the student in step 3.

- [ ] **logcat open** in a second terminal (filtered for our tags), so you can spot silent failures:
  ```powershell
  & "$env:LOCALAPPDATA\Android\Sdk\platform-tools\adb.exe" logcat -s HomeworkRepo HomeworkVM HomeworkTeacherVM
  ```

---

## A. Admin → mobile sync (covers Phase 1 admin fixes)

These exercise the canonical class/section normalizer, totalStudents at write time, the no-RTDB-fallback rule, and the teacherMarks merge.

### A1 — Admin creates homework, both mobile apps see it
- [ ] Admin **Homework → Create**. Pick **Class 8th / Section A**. Title: `"Smoke A1"`. Subject: any. Due: tomorrow.
- [ ] **Expected — Teacher app**: open the class's homework list, pull-to-refresh. `Smoke A1` appears at the top with the correct due date.
- [ ] **Expected — Parent app** (logged in as a student in that section): homework list shows `Smoke A1` with status `Pending`.

> **If only one of the two apps sees it** → most likely cause: the `sectionKey` written by admin isn't `"Class 8th/Section A"`. Verify in Firestore Console: `homework/{newDocId}.sectionKey`.

### A2 — Admin's submission rate is non-zero from the start
- [ ] Open Admin's Homework dashboard. Find `Smoke A1` in the list.
- [ ] **Expected**: the row's submission rate displays a percentage based on the actual roster (e.g. `0% · 0/12`). Before Phase 1, `totalStudents` was always written as `0`, so the rate showed 0% with no denominator.

### A3 — "Due Today" / "Overdue" counters compare on date, not ISO string
- [ ] Admin **Homework → Create** another assignment. Title: `"Smoke A3"`. Due date: **today**.
- [ ] **Expected**: Admin Homework dashboard's *Due Today* counter increments by 1.
- [ ] Admin **Homework → Create** another, due **yesterday**.
- [ ] **Expected**: *Overdue* counter increments by 1, *Due Today* unchanged.

> Before Phase 2, both counters were 0 forever because `dueDate` is stored as ISO-with-TZ (`"2026-05-06T23:59:59+05:30"`) but compared against `date('Y-m-d')` strings, never matching.

### A4 — Submissions tab merges `teacherMarks` (students who didn't submit but were graded)
- [ ] Use the Teacher app to mark a non-submitter for `Smoke A1` (see B5 below; come back to this step after).
- [ ] In Admin, open `Smoke A1` → Submissions tab.
- [ ] **Expected**: the marked student appears with status `reviewed` and the score the teacher entered. Before Phase 2, they appeared as `pending`.

---

## B. Teacher app — create / review / delete

### B1 — Empty due date is rejected
- [ ] Teacher app → **Create homework**. Fill title + subject + description. Leave due date untouched. Tap Save.
- [ ] **Expected**: Toast/snackbar `"Due date is required"`. Form does **not** submit.

> Before Phase 2, an unset due date silently fell back to today.

### B2 — Score 250 is rejected by the form
- [ ] Open any submission's review dialog. In the Score field, type `250`.
- [ ] **Expected**: red helper text `"Score must be 0–200"`. The Submit Review button is dimmed and not clickable.
- [ ] Clear the field → text stays empty.
- [ ] **Expected**: Submit button becomes active again (empty = "ungraded" sentinel `-1`).
- [ ] Type `0`.
- [ ] **Expected**: Submit button is active. Zero is a valid score.

### B3 — Race condition: parent submits while teacher is grading a non-submitter
This one needs two devices (or two app instances). Pick a student in the section who hasn't submitted yet for `Smoke A1`.

- [ ] Teacher app → open `Smoke A1` → submissions list → tap **Review** on the no-submission student.
- [ ] **Before tapping Submit Review**, on the Parent app, sign in as that same student → submit homework for `Smoke A1` (any short text).
- [ ] Now in the Teacher app, enter a score + remark and tap Submit Review.
- [ ] **Expected**: Teacher's submissions list refreshes and shows **one** entry for that student — the submission, with the teacher's score/remark merged in. **No duplicate** teacherMark + submission for the same student.

> Before Phase 2's transaction, this race produced two records (one in `submissions`, one in `teacherMarks`), and both showed up confusingly.

### B4 — Reviewing a closed homework is rejected
- [ ] Admin (or another teacher) → close `Smoke A1` from the Admin dashboard.
- [ ] Teacher app → tap Review on any submission for `Smoke A1`. Enter a score → Submit Review.
- [ ] **Expected**: error toast `"Homework is closed — reviews are not accepted"`. The submission's score is NOT updated.

### B5 — Live submission listener: parent submits, teacher sees it without refresh
- [ ] Re-open (or create new) homework for testing. Teacher app → open the homework's detail sheet (the submissions list).
- [ ] **Without leaving the screen**, on the Parent app, submit homework for that assignment as a different student.
- [ ] **Expected**: within 1–2 seconds, the new submission appears in the Teacher's list with status `submitted`. No pull-to-refresh needed.

### B6 — Remark length cap visible
- [ ] Open any review dialog. In the Remark field, paste 1000 characters of lorem ipsum.
- [ ] **Expected**: only 500 chars accepted. The supporting-text counter shows `500 / 500`.

### B7 — Delete cascades to both `submissions` AND `teacherMarks`
- [ ] Pick a homework with at least one submission and one teacherMark (use B3 above to seed both).
- [ ] Teacher app → swipe / tap the homework's delete action → confirm.
- [ ] **Expected**: success toast says something like `"Homework deleted (1 submission(s), 1 mark(s))"`.
- [ ] In Firestore Console, verify the homework doc and all `submissions/{hwId}_*` and `teacherMarks/{hwId}_*` docs are gone.

### B8 — Roster size is recomputed at write time
- [ ] In Admin, add a new student to the section.
- [ ] **Without** closing/reopening the Teacher's create-homework form, submit the form.
- [ ] **Expected**: the new homework's `totalStudents` field in Firestore matches the **current** roster (including the just-added student). Before, it used the snapshot from when the form was opened.

---

## C. Parent app — submit / display / listener

### C1 — Empty / whitespace text disables Submit
- [ ] Parent app → open any pending homework → tap "Mark as done".
- [ ] Leave the text field empty.
- [ ] **Expected**: Submit button is disabled / dimmed.
- [ ] Type only spaces (`"   "`).
- [ ] **Expected**: still disabled.
- [ ] Type a real word.
- [ ] **Expected**: button becomes active.

### C2 — Listener-error banner shows at the TOP of the screen
This needs an offline state to trigger.

- [ ] Open Parent → Homework list (load it once with the network on).
- [ ] Turn off Wi-Fi + mobile data.
- [ ] Pull to refresh.
- [ ] **Expected**: the red banner `"⚠ <error> — data shown may be out of date."` appears **at the top, just below the subject filter chips**, not at the bottom hidden behind the list.

### C3 — Trimmed teacher-mark remark renders cleanly
- [ ] Use the Teacher app to record a teacherMark for a student who didn't submit. Set the remark to `"   "` (only spaces).
- [ ] On the Parent app for that student, open the homework's card.
- [ ] **Expected**: card reads `"Evaluated (no submission) — score: <N>"` with **no trailing `· `** (dot + space). Before, the dot rendered with no body.

### C4 — Attachment with no path shows a sensible label
This is harder to provoke without crafting a homework doc by hand. Skip unless you can write a homework doc to Firestore directly with `attachments: ["https://example.com"]`. The label should be `"Attachment"` (or `homework.attachmentName`) — never `"com"` or `"example.com"`.

### C5 — Listener cleanup on screen exit
- [ ] Parent app → open Homework list. Wait until it fully loads.
- [ ] Press the back button to leave the screen, then immediately re-enter.
- [ ] Repeat 5 times rapidly.
- [ ] **Expected**: list still loads correctly. In logcat, you should see `Firestore listener cancelled (normal)` lines, **not** an exploding count of active listeners. (No hard pass/fail — this is a "doesn't get worse" check.)

---

## D. End-to-end cross-system sync

The big one. All three systems on at once.

### D1 — Full lifecycle
- [ ] **Admin** creates a homework `"Smoke D1"` for Class 8th / Section A, due tomorrow.
- [ ] **Teacher app** (logged in as that section's teacher): homework appears within the next pull-to-refresh cycle. `totalStudents` reflects the actual roster.
- [ ] **Parent app** (student in that section): homework appears with status `Pending`.
- [ ] Parent submits with text `"All done"`.
- [ ] **Teacher app**, with detail sheet for `Smoke D1` open: submission appears live (within ~2s).
- [ ] Teacher reviews: score `8`, remark `"Good work"`.
- [ ] **Parent app** (without manual refresh): the homework's status flips to `Reviewed`, score `8`, feedback `"Good work"` shows.
- [ ] **Admin** dashboard: `Smoke D1`'s submission rate moves to non-zero. Open submissions → see the parent's submission with score `8`.
- [ ] **Admin** closes the homework.
- [ ] Parent app: status updates to `Closed`. (The submit button on a fresh student should be unavailable / hidden.)
- [ ] **Admin** deletes the homework. Confirm. **Expected**: success toast notes both submissions and teacherMarks deleted, and the homework disappears from both mobile apps within the next sync.

If every box in D1 ticks, the cross-system contract is intact.

---

## E. Security rules (optional, post-deploy verification)

These need either the Firebase Console's Rules Playground or a separate script — they can't be exercised through the apps because the apps never try to do these things.

### E1 — Wrong schoolId on submission create is rejected
In Rules Playground:
- Auth: simulate a parent with `school_id` claim = `SCH_AAA`.
- Operation: `create` on `submissions/anything`
- Document data: `{schoolId: "SCH_BBB", studentId: "<that parent's UID>", homeworkId: "x"}`
- **Expected**: Rule denies. Phase 1 R1 fix makes this fail; before it would succeed.

### E2 — submissionCount tampering rejected
- Auth: parent
- Operation: `update` on `homework/somehwid` where existing `submissionCount = 2`
- Update payload: `{submissionCount: 99}`
- **Expected**: Rule denies. Only `+1` is allowed.

### E3 — pushRequests read by parent rejected
- Auth: parent (role is `parent` or unset)
- Operation: `get` on `pushRequests/anyid`
- **Expected**: Rule denies. Only staff can read.

---

## When something fails

For each failure note:
1. Which step (e.g. `D1 step 4`)
2. What you saw vs. expected
3. Whether the device shows the new build (re-confirm with `adb shell dumpsys package com.schoolsync.teacher | findstr versionName`)
4. Whether `logcat -s HomeworkRepo HomeworkVM HomeworkTeacherVM` showed an error around the same timestamp

Send the four bullets and I'll dig in.
