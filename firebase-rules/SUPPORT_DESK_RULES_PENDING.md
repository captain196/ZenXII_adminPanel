# Support Desk — rules block, STAGED NOT INTEGRATED

**Do not paste this into `firestore.rules` until §2 below is resolved.**
Drafted 2026-08-25 (P0.5 correctness gate). Deliberately kept out of the shared file.

---

## 1. Why this is staged rather than written into `firestore.rules`

The plan was to claim the block and write it locally. `node aegis/cli.js rules status` was run
first, as the protocol requires, and it says the file is **not in a state to receive a new block**:

```
live ruleset c5b6c619-83ab-4944-8d11-7cc620a69406  deployed 10h ago
blocks 175   clean 121   mine 0   undeployed 0   theirs 53   live-only 0   conflict 1

Shared helpers changed (these re-point every block that calls them)
  tenantActive()            live≠head  →  2 calling blocks
  isSameSchoolWrite()       live≠head  → 51 calling blocks
  isStaffOrOwnStudent()     live≠head  → 13 calling blocks
  institutionAllowsWrite()  live≠head  →  0 calling blocks

✖ CONFLICT  staff  L374-402 — you edited this block AND it drifted in production.
             Deploying now reverts their change.
```

Three consequences, in order of severity:

**a. The file cannot be deployed as it stands.** 53 blocks in production are newer than this
branch, and `staff` is in genuine conflict. A whole-file deploy — the only kind Firestore does —
would revert all of it. Adding a Support block would mean adding correct rules to a file that
cannot ship.

**b. `tenantActive()` in production is stricter than this branch's copy.** Production gained a
third gate on 2026-08-21 (an Institution Lifecycle axis denying `ARCHIVED`, `PROSPECT` and
`ONBOARDING`); `yug_testing` does not have it. The Support block calls `tenantActive()`, so it
would be authored against semantics production does not run.

**c. `git fetch` timed out during the check**, so "behind" counts are not trustworthy. Re-run
after `git -C ~/Desktop/Zennxii_adminPanel fetch`.

### Sequence to integrate

1. `git fetch` and re-run `node aegis/cli.js rules status`
2. Reconcile the 53 `theirs` blocks — `aegis rules pull` gives the live ruleset to diff against
3. Resolve the `staff` conflict at L374-402 with whoever deployed it
4. `node aegis/cli.js rules claim supportTickets --note "Support Desk P0.5"`
5. Paste §3 below at the end of the `documents` match block
6. Diff before deploying. **A deploy ships the whole file, including other people's work.**

Steps 2 and 3 are not this module's work and carry their own risk. They should not be done
silently as a side effect of shipping Support.

---

## 2. What changed from the Build Book §09 draft

This is **revision 2**, after the correctness gate found twelve defects. The parent now has
**no update path at all**:

| Defect | Was | Now |
|---|---|---|
| C-01 | `hasOnly()` allowlisted `status` without constraining its value — a parent could write `'closed'` or junk, making the ticket invisible to staff but live to them | `allow update: if false`; reopen is a server transition in `onSupportMessageCreated` |
| C-02 | `lastMessageAt` was parent-writable **and** is the queue's sort key — write `2099`, pin to the top of every triager's queue forever | No client-written timestamps; `createdAt == request.time` |
| C-03 | `attachments` count validated, contents never — a foreign-school path was writable, and the panel signs URLs via Admin SDK, outside rules | Ticket stores **filenames only**; the panel derives the path. A cross-tenant path is inexpressible, not merely rejected |
| C-04 | `attachments.size()` throws when the field is absent, denying the write — presents as "some parents can't raise tickets" | Explicit `!('attachments' in ...)` guard |
| C-05 | A `get()` per message read — 50 extra reads on a 50-message thread, and 50 chances to hit the Stories missing-doc failure | `reporterId` denormalised onto messages; `get()` kept on **create only**, where it prevents forging into another family's thread |
| C-06 | Open-ticket cap enforced post-write in the CF — a scripted burst of 500 commits in full | `underCap()` reads a counter in rules, before the write lands |

---

## 3. The block

```
    // ── Support Desk (v1: parent-raised only) ────────────────────────
    // Staff NEVER read this from a client. The panel uses the Admin SDK,
    // which bypasses rules by design, so these rules describe the parent
    // and only the parent. That is also why the confidential lane cannot
    // leak through a rules mistake: there is no client path to leak through.
    match /supportTickets/{docId} {
      // Three fallbacks for studentId ownership, in preferred order --
      // COPIED FROM the live studentFlags block rather than invented, because
      // arm 3 is the one THIS install actually uses:
      //   1. `student_ids` array claim  (canonical multi-child setup)
      //   2. `student_id` single claim  (legacy)
      //   3. `request.auth.uid`         (this install logs students in with
      //      their studentId as the Auth UID, so the doc's studentId equals
      //      auth.uid and no extra claim is minted)
      //
      // Omitting arm 3 denies EVERY create on this deployment. The Parent
      // app's User model carries no children list at all -- userId IS the
      // student id -- so arms 1 and 2 are both absent from the token here.
      function ownsStudent(sid) {
        return sid in request.auth.token.student_ids
            || sid == request.auth.token.student_id
            || sid == request.auth.uid;
      }
      // C-06: a real gate, evaluated BEFORE the write lands. Cap is 5 open
      // per parent across all their children; counter maintained by the CF.
      function underCap() {
        let k = /databases/$(database)/documents/supportCounters/$(request.resource.data.schoolId + '_' + request.auth.uid);
        return !exists(k) || get(k).data.openCount < 5;
      }

      allow read: if isAuth()
        && tenantActive(request.auth.token.school_id)
        && resource != null
        && resource.data.schoolId   == request.auth.token.school_id
        && resource.data.reporterId == request.auth.uid;

      allow create: if isAuth()
        && tenantActive(request.auth.token.school_id)
        && request.resource.data.schoolId   == request.auth.token.school_id
        && request.resource.data.reporterId == request.auth.uid
        && ownsStudent(request.resource.data.studentId)
        && request.resource.data.status == 'open'
        && request.resource.data.lane   == 'normal'
        && !('assignedTo' in request.resource.data)
        && !('ticketNo'   in request.resource.data)
        // C-02: no client-chosen timestamps, anywhere, ever.
        && request.resource.data.createdAt     == request.time
        && request.resource.data.lastMessageAt == request.time
        // C-04: guard the missing field, or the rule denies a photo-less ticket.
        // C-03: entries are FILENAMES; the full path is derived server-side.
        && (!('attachments' in request.resource.data)
            || request.resource.data.attachments.size() <= 3)
        && underCap();

      // C-01 + C-02: the parent has no update path. Reopen happens in
      // onSupportMessageCreated, which already reads the ticket and already
      // emits the push, so this costs nothing and removes a whole class of bug.
      allow update, delete: if false;
    }

    match /supportMessages/{docId} {
      // C-05: reporterId is denormalised onto every message, so the READ arm
      // needs no get(). Zero extra reads, zero exposure to the Stories bug.
      allow read: if isAuth()
        && resource != null
        && resource.data.schoolId   == request.auth.token.school_id
        && resource.data.reporterId == request.auth.uid;

      // The get() STAYS on create -- one per message and load-bearing: without
      // it a parent forges a message into another family's thread, invisible in
      // the victim's app and fully visible in the panel.
      allow create: if isAuth()
        && request.resource.data.schoolId   == request.auth.token.school_id
        && request.resource.data.senderType == 'parent'
        && request.resource.data.senderId   == request.auth.uid
        && request.resource.data.reporterId == request.auth.uid
        && request.resource.data.createdAt  == request.time
        && exists(/databases/$(database)/documents/supportTickets/$(request.resource.data.schoolId + '_' + request.resource.data.ticketId))
        && get(/databases/$(database)/documents/supportTickets/$(request.resource.data.schoolId + '_' + request.resource.data.ticketId)).data.reporterId == request.auth.uid;

      allow update, delete: if false;   // messages are immutable
    }

    // Server-only. Named so nobody re-invents them as ad-hoc collections.
    match /supportCounters/{docId}         { allow read, write: if false; }
    match /supportNotes/{docId}            { allow read, write: if false; }
    match /supportReporterIdentity/{docId} { allow read, write: if false; }
```

---

## 4. Tests to write before this ships

Emulator cases, from the §12 UAT matrix — the rules half of the correctness gate:

| ID | Case | Expected |
|---|---|---|
| S013 | Read another school's ticket | Denied |
| S014 | Create with a foreign `studentId` | Denied |
| S027 | PATCH `status: "closed"` inside the reopen window | Denied — no update path exists |
| S028 | PATCH `lastMessageAt` to a future timestamp | Denied |
| S029 | Create referencing an attachment under another school | Inexpressible — filenames only |
| S030 | Create with no `attachments` field | **Accepted** |
| S031 | Read a thread whose ticket document was deleted | Denies cleanly, no rules error |
| S032 | Burst of 50 creates from one parent | 5 accepted, rest denied at the rules layer |

Run against the emulator: `cd firebase-rules/tests && npm test`.

---

*Staged, not integrated. UNCOMMITTED, DEPLOY-PENDING. Branch `yug_testing`, 2026-08-25.*
