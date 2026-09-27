# TT-D1 — deny client writes to `timetables`

**Deployment authorisation required. Nothing here has been deployed.**
Prepared 2026-09-12 against HEAD `5136f0d`.

## What changes, and why

`timetables` is no longer a collection anyone authors. It is the compatibility
**projection** of the published canonical timetable version, written by
`Timetable_plan_store::projectPublishedVersion` through the service account —
which bypasses these rules entirely, so the one sanctioned writer is unaffected
by denying every client.

| | |
|---|---|
| deployed today (HEAD) | `allow write: if isStaff();` |
| intended | `allow write: if false;` |
| read arm | unchanged — `allow read: if isSameSchool();` |

What the current arm admits is exactly the illegitimate case: **any** staff
account — driver, peon, security — creating, rewriting or DELETING a school's
timetable from a client, and after the switchover silently forking the derived
copy away from the canonical version it is supposed to mirror.

## Why this is a BLOCK REPLACEMENT and not a patch file

`firestore.rules` is **+1350/−59 versus HEAD and edited concurrently by several
programmes**. Two things were proven on 2026-09-12:

1. The patch prepared on 2026-08-31 **no longer applies** — the file drifted
   underneath it. A deploy using it would have failed, or mangled the file if
   forced.
2. Regenerating it with ordinary diff context **absorbed neighbouring arms**
   belonging to other programmes (`substituteCoverDays`, `substitutePolicy`, …)
   — 142 added lines for a one-arm change. Deploying that would have shipped
   another programme's unreviewed rules.

So: **never deploy the repository file wholesale, and never apply a stored
diff.** Pull the live ruleset, replace exactly one block, deploy that.

## Procedure

```bash
# 1. Pull the LIVE ruleset and record what is actually deployed.
firebase firestore:rules:releases:list --project graderadmin   # note the ruleset id
#    save the live rules to live.rules, then:
sha256sum live.rules

# 2. In live.rules, replace the WHOLE `match /timetables/{docId} { … }` block
#    with the block in the next section. Change nothing else — diff to confirm
#    exactly one block moved:
diff <(git show HEAD:firebase-rules/firestore.rules) live.rules   # expect other
#    programmes' drift here; your edit must add only the timetables block change

# 3. Deploy ONLY that file.
firebase deploy --only firestore:rules --project graderadmin

# 4. Re-pull and byte-compare, then re-run the rules suite against the DEPLOYED
#    ruleset (not the repo copy).
```

**Rollback:** the ruleset id recorded in step 1. Firebase retains releases; the
failure mode of rolling back is returning to the current exposure, not data loss.

## The exact block to install

```
match /timetables/{docId} {
      allow read: if isSameSchool();
      // TT-D1 — CLOSED 2026-08-31 (Master Timetable switchover, gate B).
      // Authorised deliberately, not edited silently: the note this replaces
      // said resolving the conflict was the P0 #4 owner's call, and this
      // change is that call being made rather than assumed.
      //
      // `timetables` is no longer a collection anyone authors. It is the
      // compatibility PROJECTION of the published canonical timetable version,
      // written by Timetable_plan_store::projectPublishedVersion through the
      // service account — which bypasses these rules entirely, so the one
      // sanctioned writer is unaffected by denying every client.
      //
      // Nothing legitimate is lost. Both apps are read-only here (re-verified
      // across both repositories), the legacy admin editors refuse in PHP
      // before they reach a write (Timetable_service::_refuseLegacyWrite), and
      // the rollover tool now refuses projected documents
      // (Rollover_projection_guard). What the old arms admitted was exactly
      // the illegitimate case: any staff account — driver, peon, security —
      // creating, rewriting or DELETING a school's timetable from a client,
      // and after the switchover silently forking the derived copy away from
      // the canonical version it mirrors.
      //
      // P0 #4's tenant binding is PRESERVED, not undone: `false` denies a
      // strict superset of what isSameSchoolWrite()/isSameSchool() denied, so
      // every cross-tenant write those arms blocked is still blocked. The
      // five P0 #4 assertions that asserted same-school staff CAN write are
      // updated in the same change (tests/timetable_sec.test.js x3 —
      // create, update, delete; tests/homework_authorship.test.js x2); every
      // cross-tenant assertion they own passes unchanged.
      //
      // Read stays isSameSchool(): the Teacher and Parent apps depend on it.
      allow write: if false;
    }
```

## Acceptance

- A staff client can still **read** its own school's timetable.
- A staff client can no longer create, update or delete any `timetables` document.
- The projection still writes (service account bypasses rules) — verify with
  Health Check → Academic → *Teachers See The Published Timetable*.
- Cross-tenant reads still fail.
