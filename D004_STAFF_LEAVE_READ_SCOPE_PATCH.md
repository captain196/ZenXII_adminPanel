# D-004 — Staff leave read scope: prepared rules patch

**Status: PREPARED, NOT APPLIED. Needs pull-first + deploy authorization.**

Same handling as D-003: `firebase-rules/firestore.rules` is **deliberately not
edited**. It is concurrently developed and currently carries ~958 uncommitted
lines from at least three other programmes, and a deploy ships the whole file.
Apply this only inside the pull-first flow.

## The defect

`match /leaveApplications/{docId}` currently reads:

```
allow read: if isSameSchool()
            && ( isStaff() || resource.data.applicantId == request.auth.uid );
```

`leaveApplications` is a **shared** collection — student leave and staff leave sit
side by side, distinguished by `applicantType`. The `isStaff()` arm therefore
grants **any** staff token read access to **every other staff member's** leave
application, including the free-text `reason`, which routinely carries medical
and personal detail.

A peon or a junior teacher with an app account can read the principal's medical
leave reason with a single direct Firestore query.

## Why the narrowing is not a judgement call

The rule's own comment states its purpose:

> Staff see the whole school (student-leave queue + own balance)

Both stated purposes survive a narrower rule. The cross-staff visibility is
**incidental breadth, not a requirement** — proven against both clients. Every
reference to the collection lives in one repository per app, and the complete set
of query shapes is:

| Client | Query |
|---|---|
| Teacher `getLeaveHistory()` | `schoolId` + `applicantId == self` + `applicantType == 'staff'` |
| Teacher `getStudentLeaveRequests()` | `schoolId` + `applicantType == 'student'` [+ class/section] |
| Teacher `approve/rejectStudentLeave` | student only |
| Parent | own applications only |

**No client queries another staff member's leave.** There is no "who is on leave
today" staff roster in either app — the `onLeave` matches are in
`MyAttendanceScreen`, which is self-scoped and does not read this collection.

This same rules file has already made exactly this narrowing **twice**, and says
so in its own comments:

- `appraisals` — *"Was isSameSchool() — leaked every staff appraisal to any same-school token."*
- `salarySlips` — *"Was isSameSchool() — leaked every staff payslip to any same-school token incl parents/students."*

Staff-personal data is self-only, admin-wide. Leave reason is the same class of
data and is the remaining member of that family.

## The patch — read arm only

```diff
     match /leaveApplications/{docId} {
-      // Staff see the whole school (student-leave queue + own balance);
-      // students/parents see ONLY their own applications.
-      allow read: if isSameSchool()
-                  && ( isStaff() || resource.data.applicantId == request.auth.uid );
+      // Staff see the student-leave QUEUE and their OWN application; students
+      // and parents see only their own. D-004: the previous isStaff() arm let
+      // any staff token read every colleague's leave, including the free-text
+      // `reason` (medical/personal). No client ever queried that — the Teacher
+      // app asks only for applicantId==self+type=='staff', or type=='student'.
+      // Same narrowing already applied to appraisals and salarySlips.
+      // NOTE this collection is POLYMORPHIC: it also stores leave BALANCE docs
+      // (BAL_{staffId}_{year}, type=='balance') which carry staffId but NEITHER
+      // applicantId NOR applicantType — hence the staffId arm and .get()
+      // defaults. Dropping either denies every teacher their own balance.
+      allow read: if isSameSchool()
+                  && ( resource.data.get('applicantType', '') == 'student'
+                       || resource.data.get('applicantId', '') == request.auth.uid
+                       || resource.data.get('staffId', '') == request.auth.uid
+                       || isAdmin() );
```

## The collection is POLYMORPHIC — this is the trap

`leaveApplications` does not hold only leave applications. It also holds **leave
balance documents**, written by `_casLeaveBalance()` as
`BAL_{staffId}_{year}` with `type == 'balance'`, and the Teacher app reads them
directly via `getBalanceDoc()`.

A balance document carries **`staffId`**, and has **neither `applicantId` nor
`applicantType`**.

The first draft of this patch omitted the `staffId` arm. It would have **denied
every teacher their own leave balance**, because in Firestore rules a bare
`resource.data.applicantId` on a document lacking that field is a **missing-field
error**, which denies rather than evaluating to null. Hence two deliberate
choices above:

- the **`staffId` arm**, without which ESS balance breaks; and
- **`.get(field, '')`** everywhere instead of bare field access, so a
  document of either shape evaluates cleanly instead of erroring.

The over-breadth applies to balances as well: today any staff token can read any
colleague's balance document, for the same `isStaff()` reason.

## Why this exact form

- **`applicantType == 'student'` keeps the approval queue working.** Any staff
  member may still see all student leave, which is what the queue needs.
- **`applicantId == request.auth.uid` keeps ESS history working.**
  `getLeaveHistory()` is unaffected.
- **`staffId == request.auth.uid` keeps ESS balance working.** See above.
- **`isAdmin()` preserves HR/principal oversight** from the app. The admin panel
  is unaffected either way, since it uses the Admin SDK and bypasses rules.
- **No write arm is touched.** They are already hardened well beyond the norm and
  must be left exactly as they are (see below).

## What must NOT change — already correct

The write arms are unusually well-built and the patch leaves them untouched:

- `create` forces `status == 'pending'`, forbids `attendanceStamped`, requires
  `applicantId == request.auth.uid`, and requires a real staff claim for
  `applicantType == 'staff'` — blocking a parent from minting paid staff leave.
- `update` freezes `schoolId`, `applicantId`, `applicantType`, `startDate`,
  `endDate`, and permits exactly two transitions:
  - staff decides someone **else's** pending request — no self-approval, no
    pre-stamping, and **a staff member's own payroll-bearing leave requires
    `isAdmin()`**, closing the "any peer approves my paid leave" hole;
  - the owner cancels their own pending/approved request.

## Verification after applying

1. `cd firebase-rules/tests && npm test` — the Jest suite must stay green.
2. `vendor/bin/phpunit --testsuite Unit --filter StaffLeaveReadScopeTest` —
   `test_a_peer_should_not_read_another_staff_members_leave` flips from
   **incomplete** to **passing** on its own, with no test edit.
3. Smoke the Teacher app, ALL FOUR read paths: own leave history lists; own
   leave BALANCE still resolves (the polymorphic trap above); the student-leave
   approval queue lists; approving a student leave still succeeds.

## Exposure

Unlike D-003 this is **not** latent — it applies to every existing staff leave
application in every tenant with app users. Severity is bounded by requiring a
valid staff token for the same school and a deliberate direct Firestore query;
the UI never surfaces the data. No data remediation is possible or needed (the
fix is purely an access-scope change), and no historical record is wrong.
