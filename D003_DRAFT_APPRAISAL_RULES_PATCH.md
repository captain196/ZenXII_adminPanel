# D-003 — Draft appraisal visibility: prepared rules patch

**Status: PREPARED, NOT APPLIED. Needs pull-first + deploy authorization.**

`firebase-rules/firestore.rules` is **deliberately not edited** by this programme.
It is developed concurrently and a deploy ships the whole file, so an uncommitted
edit sitting in the working tree is itself a hazard. Apply this only inside the
pull-first flow.

## The defect

`match /appraisals/{docId}` allows a staff member to read their own appraisal at
**any** status, including `Draft`. The Teacher app hides drafts with a client-side
filter only:

```kotlin
// HRFirestoreRepository.getMyAppraisals()
val visible = all.filter { it.status != "Draft" }
```

A raw Firestore read with that employee's own token returns the draft in full —
the reviewer's unfinished `areasOfImprovement` notes and a provisional
`overallRating`.

The intended contract is stated twice in the app source:
- `AppraisalDoc.kt`: "Teachers only see Submitted/Reviewed"
- `HRFirestoreRepository`: "Drafts are filtered out — teachers shouldn't see in-progress reviews"

This is **not** the leak the rule's own comment records. That earlier fix
(`Was isSameSchool()`) stopped colleagues reading each other's appraisals. The
remaining gap is self-only: the subject of a review can read it before it is
finished.

## The patch — one line, one arm

```diff
     match /appraisals/{docId} {
       // Own appraisal only (app getMyAppraisals filters staffId==uid; uid==staffId).
       // Admins read all via the panel (Admin SDK, bypasses rules).
       // Was isSameSchool() — leaked every staff appraisal to any same-school token.
-      allow read: if resource.data.staffId == request.auth.uid || (isAdmin() && isSameSchool());
+      // D-003: a Draft is an in-progress review — the subject must not read it
+      // before submission. The client-side filter is UX, not enforcement.
+      allow read: if (resource.data.staffId == request.auth.uid
+                      && resource.data.status != 'Draft')
+                  || (isAdmin() && isSameSchool());
       allow write: if isAdmin();
     }
```

## Why this exact form

- **Only the `staffId` arm is narrowed.** The `isAdmin()` arm is untouched, so
  reviewers and HR keep full Draft access — which they require, since the admin
  panel is where drafts are authored.
- **The write arm is not touched.** `allow write: if isAdmin()` already refuses
  employee writes, and because `isAdmin()` never dereferences `request.resource`
  it stays correct on delete. Per the standing rule, create/update/delete were
  considered separately; no arm needs to change.
- **`!=` on a missing field.** In Firestore rules, `resource.data.status` on a
  document with no `status` field is an error, not `null`. Every appraisal
  written by `Hr::save_appraisal` sets `status` unconditionally, so this cannot
  arise from the shipped writer. If a hand-written document could lack the field,
  harden to `resource.data.get('status', '') != 'Draft'`.

## Verification after applying

1. `cd firebase-rules/tests && npm test` — the Jest suite must stay green.
2. `vendor/bin/phpunit --testsuite Unit --filter DraftAppraisalVisibilityTest`
   — `test_rules_should_refuse_a_draft_appraisal` flips from **incomplete** to
   **passing** on its own, with no test edit. If it does not, the patch did not
   land as written.
3. Confirm the admin panel still lists drafts (it uses the Admin SDK and bypasses
   rules, so this should be unaffected).

## Exposure

Read-only census across all tenants, 2026-08-21: **0 appraisals exist**, of which
**0 are Draft**. Nothing is exposed today and no data remediation is required.
The defect becomes live the first time a reviewer saves a draft.
