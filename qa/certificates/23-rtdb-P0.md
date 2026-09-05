# P0 · RTDB certificate data is cross-tenant readable and writable IN PRODUCTION

**Evidence level E3 — the LIVE deployed ruleset, fetched 2026-09-04 from
`https://graderadmin-default-rtdb.firebaseio.com/.settings/rules.json` using the panel's own
service-account credential. Not a git reading.**

L2 found this in the repo. Because it is a P0 I fetched what is actually deployed, since
this codebase has a catalogued history (`_known-risks.md` · CARRY-013) of production RTDB
rules differing from the repo and being wide open. The deployed ruleset is **byte-identical
to the committed one**:

```
{ "rules": {
    ".read":  "auth != null",
    ".write": "auth != null",
    "Indexes":   { "School_codes": {…}, "School_names": {…} },
    "School_ids": { ".read": true, ".write": "auth != null" }
} }
```

## What this means

**There is no `Schools` block.** The entire tree —

```
Schools/{schoolId}/{session}/Certificates/{Templates | Issued | Counters}
```

— inherits the root rule. The only condition on reading or writing **every school's issued
certificates** is that the caller is *any authenticated user of the `graderadmin` project*.

ZenXii is multi-tenant on **one** Firebase project. Every parent and every staff member of
every school authenticates against it. So the rule `auth != null` is satisfied by a parent
in one school holding a valid token, and it grants them read **and write** on another
school's certificate records.

An `Issued` record carries student PII: full name, date of birth, both parents' names,
religion, caste and admission number (`Certificates.php:452-495`, the 20 placeholders).

**Write access is the sharper half.** A hostile authenticated client could alter or delete
another school's issued-certificate records — and the legacy system has no durable artefact
to reconcile against, because it never produced one (`22-three-systems.md` · L-11).

## What is NOT true — stated so the risk is not overstated

- **The fully-public `.rollback` file is NOT deployed.** `firebase-rules/database.rules.json.rollback`
  contains `".read": "true", ".write": "true"` with no auth at all. The live rules **do**
  require authentication. That artefact should be deleted from the repo regardless — it is
  one careless `firebase deploy` from being an unauthenticated data breach.
- **The panel is not the exposure path.** It uses the Admin SDK, which bypasses rules entirely.
- **Neither Android app currently touches these paths.** Independently confirmed by two
  agents: zero references to `Schools/*/Certificates/*` in either repo. The exposure is
  reachable by a *hand-crafted request* bearing any valid project token — not by the shipped
  apps doing it accidentally today.

So this is **not** an active data leak with a known exploiter. It is an open door in
production with nothing behind it but the fact that nobody has walked through.

## Why it was not found earlier

`firestore.rules` is 47 match blocks and has a dedicated Sentinel (`aegis rules status`) that
compares live against git. **RTDB has no equivalent** — no sentinel, no drift detection, and
22 lines of rules for the whole database. The tooling investment went to Firestore, and RTDB
kept the data nobody re-examined.

## Recommendation

1. Add an explicit school-scoped rule for `Schools`, or at minimum deny-by-default with a
   narrow allowance — **before** anything client-side is pointed at these paths.
2. Delete `database.rules.json.rollback` from the repo.
3. Build the RTDB equivalent of the Firestore Rules Sentinel, or migrate the remaining RTDB
   data to Firestore where the tooling already exists.

**Not fixed in this pass.** Editing and deploying database rules changes production access
control for every tenant simultaneously; that is the human's call, and deploying rules is
explicitly out of scope without permission.
