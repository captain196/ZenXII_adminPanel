# CAL-D1 — deployment reconciliation procedure

**STATUS: patch implemented and emulator-proven. NOT DEPLOYED.**
This document is the procedure only. Executing it requires explicit
authorisation, and nothing here should be read as permission.

---

## Why a procedure is needed at all

The obvious command is wrong. `firebase-rules/firebase.json` points `firestore.rules`
at the working file, so the documented deploy in CLAUDE.md would ship it — and
that file is **1,367 changed lines versus HEAD, of which 48 are CAL-D1's**. The
rest is another workstream's undeployed work.

The 2026-08-21 security deployment already hit this and solved it by deploying
through an **isolated config** (`_deploy_2026_08_21/`, since removed) precisely
because the repository file was *"~34KB ahead of production with another
workstream's undeployed work"* and was *"structurally incapable of being the
source."* The same constraint applies now.

---

## What CAL-D1 actually changes — 7 arms, 48 lines

| collection | before | after |
|---|---|---|
| `calendarEvents` | `allow write: if isAdmin();` | `allow write: if false;` |
| `subjects` | `allow write: if isAdmin();` | `allow write: if false;` |
| `exams` | `allow write: if isAdmin();` | `allow write: if false;` |
| `examSchedule` | `allow write: if isAdmin();` | `allow write: if false;` |
| `appraisals` | `allow write: if isAdmin();` | `allow write: if false;` |
| `exams` (read) | `isStaff() \|\| (isSameSchool() && …)` | `isSameSchool() && (isStaff() \|\| …)` |
| `examSchedule` (read) | `isStaff() \|\| (isSameSchool() && …)` | `isSameSchool() && (isStaff() \|\| …)` |

Preserved verbatim at `firebase-rules/patches/CAL-D1-tenant-binding.patch`.

---

## The procedure

**1. Establish what production actually runs — do not assume.**

```bash
firebase firestore:rules:releases:list --project graderadmin   # or the console
```

Capture the live ruleset id and its sha256. The chain recorded on 2026-08-21
ended at `e8f2f47a…`, sha `a72b023b…`. **No local file matches that hash** — not
`firestore.rules`, not `LIVE_BASELINE_34e7a058`, not `PREDEPLOY_BACKUP`. Treat
every local file as a candidate, never as the baseline.

**2. Pull the live ruleset into an isolated directory.** Not into
`firebase-rules/`, which is shared and dirty.

**3. Apply the 7 arms to THAT pulled file** — not to the working file. Use the
patch as a reference for intent, and re-anchor by collection block, because the
pulled file's surrounding lines will not match the working copy's.

**4. Diff the result against what you pulled.** It must show **exactly 7 allow
arms and nothing else**. Any other hunk means another workstream's work has been
swept in — stop.

**5. Prove it before shipping.** Both suites, against the candidate file:

```bash
cd firebase-rules/tests
CAL_D1_RULES=/path/to/candidate.rules npx firebase emulators:exec \
  --only firestore --project=zenxii-rules-test "npx jest cal_d1_tenant_binding"
```

Expect **26/26**. The suite is differential: 17 cross-tenant attacks must be
denied *and* 9 legitimate same-tenant paths must still succeed. A run that only
proves denials is proving that everything is broken.

Then the full rules suite against the same candidate — expect **981 passing**.

**6. Deploy from the isolated config only.**

```bash
firebase deploy --only firestore:rules --project graderadmin --config /path/to/isolated/firebase.json
```

**7. Re-pull and compare byte-for-byte** against the artifact you intended to
ship. Record the new ruleset id and sha256, as the 2026-08-21 log did.

**8. Re-run the differential suite against the DEPLOYED ruleset,** not the local
candidate. That is the only step that proves production is actually fixed.

---

## Rollback

The previous ruleset id from step 1 is the rollback target; Firebase keeps
releases, so rollback is a redeploy of that id. The change is a pure tightening
of five write arms and two read arms, so the failure mode of a rollback is
returning to the current cross-tenant exposure — not data loss.

## What would make this unsafe

- Deploying `firebase-rules/firestore.rules` wholesale. **Never do this.**
- Applying the patch to a file whose provenance was not established in step 1.
- Skipping step 5's same-tenant counter-tests. An earlier run of that suite
  passed 22 assertions **for the wrong reason** — `tenantActive()` was false for
  both tenants because only `schools` was seeded and not `schoolControl`. The
  counter-tests are what exposed it, and they are not optional.
