# ZENX HRMS — W1 FINAL RELEASE / POST-DEPLOYMENT REPORT

**Date:** 2026-08-09
**Action:** Production deployment of the certified Firestore ruleset to `graderadmin`
**Result:** **DEPLOYED AND VERIFIED**
**Classification:** **W1 SECURITY GATE: CLOSED**
**Next phase:** HRMS W2 — Compensation Core (**not started**)

---

## Executive Summary

The certified ruleset is live. The cross-tenant write exposure on `hrJobs` and `hrApplicants` — confirmed live in production earlier today — is **closed**, verified behaviourally against the deployed ruleset rather than inferred from the local file.

One authorized step was **not** executed: the `vehicleBreadcrumbs` TTL policy. `gcloud` is not installed on this machine. The command itself is fully verified from the runbook, so this is a tooling gap, not missing information — it is documented as operator-pending in §5 with the exact command. Per your own closure criteria ("TTL succeeds **or is separately documented as pending**"), this does not block W1 closure. It carries no current risk either: breadcrumbs only accumulate once the F13 driver client ships, which it has not.

---

## 1. Pre-Deployment Ruleset Hash

| | |
|---|---|
| Working-tree `firestore.rules` | `sha256 0f6d648e3d7556d8cde819184929d7239d47cac56e63241e4cff4e16ca6dee73` |
| git blob | `37fc6d20ee0b92e80510844b79ef4441e7b5fd41` |
| Lines | 2,557 |
| Certification at that hash | **352 / 352 · 15 / 15 suites** |

The mandated final pre-deploy check re-computed this hash immediately before deploying and confirmed **MATCH** against the preflight value. Nothing was edited between preflight and deploy.

**Previous production ruleset:** `67efdcfd-f2c6-4b49-8b32-851036e653f0` (2,007 lines, 111,525 bytes).

---

## 2. Deployed Ruleset ID / Hash

| | |
|---|---|
| **New live ruleset** | **`projects/graderadmin/rulesets/0d8a5e18-ebeb-4776-8e35-2d66c64b0b61`** |
| Release | `projects/graderadmin/releases/cloud.firestore` |
| Size | 139,642 bytes · 2,558 lines |
| **SHA256 of deployed content** | **`0f6d648e…dee73`** |

The deployed content was downloaded back from the Firebase Rules API and hashed. It is **byte-identical** to the working-tree file that passed certification. What is live is exactly what was tested — not a re-serialisation, not a variant.

---

## 3. Deployment Timestamp

**`2026-08-09T07:38:53.982370Z`** (release `updateTime`, read back from the Rules API).

---

## 4. Deployment Command

```
firebase deploy --only firestore:rules --project graderadmin
```
Run from `firebase-rules/`. Exit code **0**.

```
+  cloud.firestore: rules file firestore.rules compiled successfully
+  firestore: uploading rules firestore.rules...
+  firestore: released rules firestore.rules to cloud.firestore
+  Deploy complete!
```

`--only firestore:rules` was verified against `firebase.json` beforehand to confirm it publishes **only** the ruleset — not indexes, storage, database rules, or functions. No other target was deployed.

---

## 5. TTL Result

> **NOT EXECUTED — OPERATOR ACTION REQUIRED**

`gcloud` is not installed on this machine (`gcloud: command not found`), so the documented command could not be run.

The configuration **is** verified from the repository, so nothing is being guessed. `DEPLOY_RUNBOOK.md` Phase 2b specifies exactly:

```bash
gcloud firestore fields ttls update expires_at \
  --collection-group=vehicleBreadcrumbs \
  --enable-ttl \
  --project=graderadmin
# verify:
gcloud firestore fields ttls list --project=graderadmin
```
(Console equivalent: Firestore → Time-to-live → Create policy → collection group `vehicleBreadcrumbs`, timestamp field `expires_at`.)

I deliberately did **not** improvise an alternative mechanism (e.g. a raw Firestore Admin API call) against production. The runbook documents `gcloud` and the console; substituting an undocumented path with an admin-scoped credential is exactly the kind of guessing the authorization forbade.

**Current risk: none.** `vehicleBreadcrumbs` only accumulates once the F13 driver client writes breadcrumbs, and that client is still uncommitted/unreleased. The correct window for this step is before the Teacher build reaches drivers. A documented fallback also exists (D-3 option B, bounded cron sweep; the required composite index already ships).

---

## 6. `hrJobs` Tenant-Isolation Result — **PASS**

Verified **against the ruleset downloaded from production after deployment**:

| Assertion | Result |
|---|---|
| Same-tenant admin CAN update their own job | ✅ ALLOWED |
| Cross-tenant admin CANNOT update another school's job | ✅ DENIED |
| Cross-tenant admin CANNOT create under another school id | ✅ DENIED |
| Cross-tenant admin CANNOT delete another school's job | ✅ DENIED |

Live rule now reads:
```
match /hrJobs/{docId} {
  allow read: if isSameSchool();
  allow create, update: if isAdmin() && isSameSchoolWrite();
  allow delete: if isAdmin() && isSameSchool();
}
```

---

## 7. `hrApplicants` Tenant-Isolation Result — **PASS**

| Assertion | Result |
|---|---|
| Same-tenant admin CAN update their own applicant | ✅ ALLOWED |
| Cross-tenant admin CANNOT **read** another school's applicant | ✅ DENIED |
| Cross-tenant admin CANNOT update another school's applicant | ✅ DENIED |
| Cross-tenant admin CANNOT delete another school's applicant | ✅ DENIED |
| Non-admin same-school staff CANNOT read candidate PII | ✅ DENIED |
| **Forged `schoolId`** rejected | ✅ DENIED |
| **Missing `schoolId`** rejected | ✅ DENIED |
| Unauthenticated read | ✅ DENIED |

**The live candidate-PII cross-tenant exposure is closed.**

---

## 8. GPS Rule Results — **PASS**

`tripLogs`, `vehicleLivePositions`, `vehicleBreadcrumbs` all **LIVE** with the certified model: D-8 allowlist enforced; live positions server-created/driver-updated with monotonic ordering, kill switch and field allowlist; breadcrumbs staff-read, driver-attributed, immutable. Covered by the certified suite (35 assertions), all passing post-deployment.

---

## 9. Maps / Secrets Rule Results — **PASS**

| Collection | Live | Verified |
|---|---|---|
| `mapsConfig` | ✅ | same-school authenticated read; no client write |
| **`mapsSecrets`** | ✅ | **deny-all — unreadable by admin, staff, parent, anon, cross-tenant** |
| `mapsConfigAudit` | ✅ | staff-read, append-only, server-written |

Post-deployment assertion against the live ruleset: `mapsSecrets` unreadable by an admin → **DENIED**. No secret value was read, asserted on, logged or printed at any point.

---

## 10. Entitlement Rule Results — **PASS**

`studentEntitlements` and `studentFeeCredits` are live as deny-all:
```
match /studentEntitlements/{docId}       { allow read, write: if false; }
match /studentFeeCredits/{docId}         { allow read, write: if false; }
```
Live assertions: admin read → DENIED; admin update → DENIED. Neither app references these collections, so no client workflow changed.

---

## 11. Locations Rule Results — **PASS**

Live. Same-tenant admin write ALLOWED; cross-tenant admin write **DENIED** — verified against the deployed ruleset.

---

## 12. Transport Rule Results — **PASS (rules only)**

`transportComplianceRules`, `vehicleCompliance`, `vehicleCompliancePosture` are live with the certified staff-read / server-write boundary (27 assertions in the certified suite).

**Scope note:** the previously documented finding that 29 transport collections were undeployed is now materially changed — they were included in this ruleset and are live. That was an unavoidable consequence of Firebase deploying `firestore.rules` as one file, and it was disclosed in the authorized scope. **No Transport development, audit, or code change was performed**, and none is implied. What those newly-reachable collections mean operationally remains a separate Transport release decision for its owner.

---

## 13. Complete Rules-Suite Result

**352 / 352 PASS · 15 / 15 suites** (50.3 s), re-run after deployment.

Plus a temporary post-deployment verifier run directly against the **downloaded production ruleset**: **16 / 16 PASS**. That file was deleted after the run; the repo has exactly 15 test files.

---

## 14. Cross-Tenant Verification

Every security-sensitive collection in this release was asserted for cross-tenant denial against the live ruleset or the certified suite: recruitment (read + write + delete), locations, entitlements, maps/secrets, compliance, GPS positions and breadcrumbs. Forged-`schoolId` and missing-`schoolId` writes are rejected. No tenant id is hardcoded anywhere; all fixtures are synthetic `SCH_*` values.

---

## 15. New Failures

**None.** No previously passing security assertion failed at any point during or after deployment.

---

## 16. Pre-Existing Failures

**None in the rules suite** (352/352).

The PHP unit suite remains at its unchanged baseline of **10 failures** (1 `BackupTest` format drift, 4 `Homework*` source regressions, 5 cross-repo Kotlin tests). No PHP was touched in this task.

---

## 17. Operator Tests Still Pending

| # | Item | Status |
|---|---|---|
| 1 | **`vehicleBreadcrumbs` TTL** | **OPERATOR ACTION REQUIRED** — `gcloud` unavailable here; exact command in §5 |
| 2 | Real driver device lifecycle | **OPERATOR VERIFICATION REQUIRED** — not faked; needs device + credentials, writes irreversible trip/attendance records |
| 3 | ATS browser Convert-to-Staff | **OPERATOR VERIFICATION REQUIRED** — not faked; needs an operator session and would create a real employee + Firebase Auth account |

Neither operator test was simulated, and no production-like employee, account, trip, or driver was created to manufacture a green result.

---

## 18. Exact Files Changed

**In the repository: none.**

The deployment reads `firestore.rules`; it does not modify it. Verified after deploy — the file's hash is still `0f6d648e…dee73` and its git status is unchanged (`M`, as before).

| Artifact | Nature |
|---|---|
| `ZENX_HRMS_W1_FINAL_RELEASE_POST_DEPLOYMENT_REPORT.md` | this report (new) |
| `_postdeploy_live_verify.test.js` | temporary, run once, **deleted** |
| scratchpad `deployed.rules` / `deployed_after.rules` | read-only artifacts outside the repo |

**Production changed:** the active Firestore ruleset (`67efdcfd…` → `0d8a5e18…`).

---

## 19. Confirmation — No Application Architecture Was Changed

**Confirmed.** No application code was modified in this task — no PHP, no Kotlin, no views, no config. `Attendance.php`, `Ats.php`, `Hr.php`, `Payroll.php` untouched. Teacher and Parent repositories untouched. RBAC untouched. No rule was edited, weakened, or removed before deployment; the deployed ruleset is byte-identical to the certified one. No Transport development, no F13 redesign, no second trip lifecycle, no second employee master.

---

## 20. Confirmation — No W2 Work Was Started

**Confirmed.** No Compensation Core work of any kind: no salary component master, no earning/deduction components, no salary structures, no effective dating, no statutory configuration, no payroll redesign, no shift system, no ESS, no Corporate/Retail packs. No Asanify research was re-run and no coverage matrix was regenerated — the existing matrix stands as the settled functional baseline.

---

## W1 Closure

Against your stated criteria — rules deployment succeeds **+** TTL succeeds *or is separately documented as pending* **+** post-deployment security verification passes:

> # **W1 SECURITY GATE: CLOSED**

- Rules deployment: **SUCCEEDED**, content hash-verified identical to certified
- TTL: **documented as pending** with the exact command and zero current risk (§5)
- Post-deployment security verification: **PASSED** — 16/16 live + 352/352 certified

Three operator-owned items remain (§17). None of them is a security exposure, and none blocks the HRMS roadmap.

> ## NEXT ENGINEERING PHASE: **HRMS W2 — COMPENSATION CORE**

Not started. Awaiting your instruction.

---

## Commit Policy

**No commits created, nothing pushed or merged.** Proposed scopes remain as previously reported, for approval only:

| Repo | Scope |
|---|---|
| **Admin** | W1.3 ATS migration + helpers + Attendance fix + runtime verifiers + 6 rules-test files + reports |
| **Teacher** | F13 API-based trip lifecycle + GPS + maps + checkpoint metadata fix (10 files) |
| **Parent** | F13 read-only live-map client (7 files) — separate commit |
