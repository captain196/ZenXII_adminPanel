# ZenX — Firebase Deployment Verification (Attendance infra)

**Deployment window:** 2026-07-01 · **~17:0x IST** (verify step stamped `2026-07-01 17:07:24 IST`).
**Firebase project:** `graderadmin` (project number `726043246907`, current default in `firebase-rules/.firebaserc`).
**CLI:** firebase-tools 15.12.0 · authenticated. **Scope:** `--only firestore:indexes` then `--only firestore:rules` (storage/database/functions intentionally untouched).
**Nothing pushed to git remote.** Local commits only.

---

## 1. Deployed rules version
- **Source of truth (git):** `firestore.rules` @ commit **`414b635`** (`chore(firebase): firestore.rules — attendance staff-attendance hardening + exam/result deny-all`).
- **Deployed-artifact fingerprint:** `sha256(firestore.rules) = 912eee5e3e3ca1dbe70409a85ee0a0ecc25f6665f5a1ec5cd9ee2e9c7154cd67`.
- **Release confirmation (CLI):** `+ cloud.firestore: rules file firestore.rules compiled successfully` → `+ firestore: released rules firestore.rules to cloud.firestore` → `+ Deploy complete!`
- *(The Firebase-assigned release name/version is visible in the console; the CLI does not print it. The git SHA + sha256 uniquely identify the deployed ruleset.)*

## 2. Deployed indexes
- **Source of truth (git):** `firestore.indexes.json` @ commit **`4043af6`**.
- **Deployed-artifact fingerprint:** `sha256(firestore.indexes.json) = e50c844d1caead4d536376f788388db7bff101c57bba95b80a2f308db27eb75c`.
- **New index added:** `attendancePunches` composite `(schoolId ASC, staffId ASC, date ASC)`, `COLLECTION` scope. Confirmed present in the live project index config.
- **Index state: ENABLED (verified by live query)** — the `attendancePunches` composite query (`schoolId== & staffId== & date==`) executed successfully via the diagnostic probe, returning 2 punch rows with **no "index required/building" error** (it previously failed "index likely undeployed"). A 3-equality composite builds effectively immediately.
- **Non-destructive note:** the CLI reported *"3 indexes defined in your project that are not present in your firestore indexes file"* and did **NOT** delete them (no `--force`). Those are pre-existing, non-attendance indexes — left intact.

## 3. Deployment timestamps
- Indexes deploy: 2026-07-01 (immediately before the rules deploy).
- Rules deploy: 2026-07-01.
- Verification stamp: **2026-07-01 17:07:24 IST**.

## 4. Firebase project
`graderadmin` — `https://console.firebase.google.com/project/graderadmin/overview`.

## 5. Deployment success logs
**Indexes:**
```
=== Deploying to 'graderadmin'...
i  firestore: reading indexes from firestore.indexes.json...
+  cloud.firestore: rules file firestore.rules compiled successfully
i  firestore: deploying indexes...
i  firestore: there are 3 indexes defined in your project that are not present in your firestore indexes file. To delete them, run this command with the --force flag.
+  firestore: deployed indexes in firestore.indexes.json successfully for (default) database
+  Deploy complete!
```
**Rules:**
```
=== Deploying to 'graderadmin'...
i  cloud.firestore: checking firestore.rules for compilation errors...
+  cloud.firestore: rules file firestore.rules compiled successfully
i  firestore: uploading rules firestore.rules...
+  firestore: released rules firestore.rules to cloud.firestore
+  Deploy complete!
```

## 6. Differences between deployed rules and git — **ZERO**
- The deploy read **directly** from `firebase-rules/firestore.rules` and `firebase-rules/firestore.indexes.json`.
- Those working-tree files are **committed** (`414b635` / `4043af6`) and `git status --porcelain firebase-rules/` returns **empty (clean)** → the deployed artifacts are byte-identical to git HEAD.
- Therefore deployed == git **by construction**; fingerprints in §1–§2 are the deployed artifacts.
- *(Limitation: the firebase CLI cannot download the live-compiled ruleset for a byte-level re-diff; the zero-diff guarantee rests on "deployed the committed file + clean tree" + the sha256 fingerprints + the console version history.)*

## 7. Confirmation — production Firebase config now matches the repository
✅ **Confirmed.** `firebase-rules/` is fully committed and clean; the exact committed `firestore.rules` (`414b635`) and `firestore.indexes.json` (`4043af6`) are the deployed artifacts. The live `graderadmin` Firestore rules + indexes now match the repository.

### Runtime effect now live
- **Indexes:** `attendancePunches` composite available (GPS staff punch queries no longer error).
- **Rules:** `staffAttendance` / `staffAttendanceSummary` → client writes denied (Admin-SDK/PHP only), reads = own-record or same-school admin; `attendancePunches` → same read model, writes denied; Exam/Result deny-all intent-locks active. Admin-SDK backend unaffected (bypasses rules). Client-side enforcement = the deployed ruleset (on-device client behavior = Requires Live Validation, but no schools are onboarded).

---

## Status
Steps 1–6 complete: `firestore.rules` committed (`414b635`), `firestore.indexes.json` committed (`4043af6`), indexes deployed + **ENABLED (verified)**, rules deployed + released, deployed == git (zero diff), live config matches repo.

**Code NOT pushed.** Stopping here for your approval before pushing the commits.
