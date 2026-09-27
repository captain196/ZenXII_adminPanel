# D-007 — `staffSettlements` Firestore rules: prepared patch

**Status: PREPARED, NOT APPLIED. The collection does not exist yet.**

Same handling as D-003 and D-004: `firebase-rules/firestore.rules` is
concurrently edited by another programme and a deploy ships the whole file, so
nothing here is written to it. Apply inside the pull-first flow, **at the same
time as the collection lands** — not afterwards.

That timing is the entire point of writing this now. D-003 exists because a rule
was written for `appraisals` without asking what statuses an employee should see,
and the gap was found months later by audit. The same question is being asked
here *before* there is anything to leak.

---

## Stage 1 — ship with the collection: server-only

```
    // ── Staff Full & Final settlements ──────────────────────────────
    match /staffSettlements/{docId} { allow read, write: if false; }
```

**Not a placeholder — the correct rule for stage 1.**

Teacher and Parent have **no settlement surface** (design §4.9), so no client
needs to read this collection. Granting an app read "ready for later" would
expose settlement figures — gross, deductions, net, recovery amounts — to every
staff token for a feature nobody can open yet.

This is the posture the Canonical Document Service already takes:

```
    match /documents/{docId}        { allow read, write: if false; }
    match /documentLinks/{linkId}   { allow read, write: if false; }
```

The admin panel is unaffected: it uses the Admin SDK, which bypasses rules
entirely. Every settlement read and write in stage 1 goes through the panel.

**Do not skip this line on the theory that an unmatched collection is denied by
the catch-all.** It is — `match /{document=**} { allow read, write: if false; }`
— but an explicit block states the intent, and the next person to add an ESS
surface finds a rule to amend rather than an absence to interpret.

---

## Stage 2 — only if an employee-facing surface is authorized

Adding a Teacher-app settlement screen is a separate product decision (design
§4.9). If it is taken, the rule becomes:

```
    match /staffSettlements/{docId} {
      // An employee may read their OWN settlement, but only once the figure is
      // FINAL. `calculated` and `inReview` are working numbers: a settlement
      // under review can still be rejected back to `calculated` and recomputed,
      // so showing it would tell someone they are owed an amount that may
      // change before anyone has approved it.
      //
      // This is the D-003 lesson applied at design time. There, an employee
      // could read their own DRAFT appraisal because the rule conditioned on
      // ownership alone and the app hid drafts client-side. Client-side
      // filtering is not access control.
      allow read: if (resource.data.staffId == request.auth.uid
                      && resource.data.status in ['approved', 'paid', 'closed'])
                  || (isAdmin() && isSameSchool());

      // Server-only. A settlement is created, computed, approved and paid
      // through the panel; no client may write one at any status.
      allow write: if false;
    }
```

### Why these three statuses

From `Fnf_state_machine`:

| Status | Employee may read | Why |
|---|---|---|
| `initiated` | no | nothing computed yet |
| `calculated` | no | a working figure; recompute is legal from here |
| `inReview` | no | can be rejected back to `calculated` and changed |
| `approved` | **yes** | the figure is final and authorised |
| `paid` | **yes** | money has moved |
| `closed` | **yes** | terminal |
| `cancelled` | no | never took effect; showing an amount would mislead |

`approved` is the first status at which the number is committed — that is
exactly what approval means, and `Fnf_state_machine` refuses to re-approve or to
recompute past `inReview`, so the figure cannot move underneath the employee
after they have seen it.

### Why the write arm stays `if false`

Unlike `leaveApplications`, where an employee legitimately creates their own
request, there is no client-side settlement action at all. `allow write: if
false` needs no create/update/delete split precisely because it grants nothing —
and, unlike a predicate mentioning `request.resource`, it behaves identically on
delete.

---

## Indexes

Stage 1 needs none: the panel queries by school through the Admin SDK, which
composite-index rules still apply to but which the existing single-field indexes
serve for equality-only filters.

Stage 2, if an ESS screen lists a person's settlements newest-first, would need:

```json
{ "collectionGroup": "staffSettlements", "queryScope": "COLLECTION",
  "fields": [
    { "fieldPath": "schoolId", "order": "ASCENDING" },
    { "fieldPath": "staffId",  "order": "ASCENDING" },
    { "fieldPath": "updatedAt", "order": "DESCENDING" }
  ] }
```

That is the same shape as the `appraisals` composite, and for the same reason: an
`orderBy` on a field outside the equality filters cannot be served by index
merging. Add it **with** the screen, never after — a missing composite makes the
query fail outright with `FAILED_PRECONDITION`, not merely run slowly.

---

## Verification when applied

1. `cd firebase-rules/tests && npm test` — **requires JDK 21+**; this machine has
   17, so the suite cannot run here (`firebase-tools no longer supports Java
   version before 21`). Whoever applies this needs a suitable JDK.
2. Confirm the admin panel still reads and writes settlements — it uses the
   Admin SDK and should be entirely unaffected.
3. Stage 2 only: confirm an employee sees an `approved` settlement and does
   **not** see one at `calculated` or `inReview` — tested with a direct
   Firestore read, not through the app, since client-side filtering proves
   nothing about access control.
