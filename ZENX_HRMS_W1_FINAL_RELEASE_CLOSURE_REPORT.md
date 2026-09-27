# ZENX HRMS — W1 FINAL RELEASE / SECURITY CLOSURE REPORT

**Date:** 2026-08-09
**Scope:** Close the final release dependency created by the shared `firestore.rules`. No engineering, no W2.
**Classification:** **SECURITY_RELEASE_GATE: OPERATOR_ACTION_REMAINING**
**Rules deployment:** **READY FOR COORDINATED FIRESTORE RULE DEPLOYMENT** — scope reported below, **NOT deployed**
**Repos:** all **UNCOMMITTED, UNPUSHED, NOT DEPLOYED**

---

## 0. The headline finding

Task 1 was answerable, and the answer overturns the central premise of my two previous reports.

I read the **live deployed ruleset** (read-only, via the Firebase Rules API):

```
release : projects/graderadmin/releases/cloud.firestore
ruleset : projects/graderadmin/rulesets/67efdcfd-f2c6-4b49-8b32-851036e653f0
source  : 111,525 bytes / 2,007 lines   (working tree: 2,557 lines)
```

Two facts follow, both verified by direct inspection of the deployed source:

1. **`tripLogs` is ABSENT from the deployed ruleset**, and the file ends with an explicit catch-all `match /{document=**} { allow read, write: if false; }`. The shipped Teacher app's trip writes are therefore **already denied in production** — they have been all along.

2. **`hrJobs` and `hrApplicants` ARE deployed, with `allow write: if isAdmin();`** — the unbound write arm. **The W1.3 cross-tenant exposure on candidate PII is CONFIRMED LIVE**, no longer inferred from HEAD.

### Correction to my previous two reports

Both stated that deploying the D-8 ruleset **would break** trip start and completion for drivers on the shipped app. **That was wrong.** There is no `tripLogs` block deployed to break — the catch-all already denies those writes. Deploying *adds* a `tripLogs` block that permits allowlisted checkpoint writes, so it can only ever **improve** the driver path, never regress it.

The blocker that dominated the last two gates **does not exist in production.** What remains is real but different: the driver app needs its new build to function at all, and the candidate-PII exposure is live and argues *for* deploying promptly rather than against it.

---

## 1–5. Workstream Status

| # | Item | Status |
|---|---|---|
| 1 | **W1.1** — HRMS Entitlement Reachability | **COMPLETE** — verifier green |
| 2 | **W1.2** — Money Precision | **COMPLETE** — verifier green; payroll arithmetic untouched since |
| 3 | **W1.3** — ATS / Recruitment RTDB → Firestore | **COMPLETE** — 0 RTDB calls, 13 endpoints preserved, rules fix written |
| 4 | **Attendance** device double-prefix correction | **COMPLETE** — 2 lines, 24/24 runtime verification |
| 5 | **F13 Teacher** trip-lifecycle compatibility | **COMPLETE** — `TEACHER_CLIENT_READY_FOR_D8` |

---

## 6. Rules Certification Result

**352 / 352 PASS · 15 / 15 suites.** Coverage went from 247/11 to 352/15 across the certification gates — 105 tests added for blocks that previously had none (F13 GPS, maps/secrets, transport compliance, student entitlements, plus the two client-payload tests).

No production rule was modified to make any test pass. `firestore.rules` is byte-identical to its state before the last two tasks.

---

## 7. Live Deployed-Ruleset Result

**ANSWERED — no operator action needed for this item.**

| Collection | Deployed? | Consequence |
|---|---|---|
| `hrJobs` | **PRESENT** — `allow write: if isAdmin()` | **cross-tenant write exposure LIVE** |
| `hrApplicants` | **PRESENT** — `allow write: if isAdmin()` | **candidate-PII exposure LIVE** |
| `tripLogs` | **ABSENT** | client access denied by catch-all |
| `transportAttendance` | **ABSENT** | boarding events denied |
| `locations` | ABSENT | Phase-1 collection unreachable to clients |
| `studentEntitlements` / `studentFeeCredits` | ABSENT | already effectively deny-all |
| `vehicleLivePositions` / `vehicleBreadcrumbs` | ABSENT | F13 GPS non-functional |
| `mapsConfig` / `mapsSecrets` / `mapsConfigAudit` | ABSENT | maps config unreachable |
| F12.1 compliance ×3 | ABSENT | dashboard reads denied |

`affectedKeys().hasOnly` occurrences in the deployed ruleset: **0**.

**A significant Transport finding, reported and NOT acted on:** the deployed rules contain only three transport collections (`routes`, `studentRoutes`, `vehicles`) against **32** in the working tree. **29 are undeployed**, including `tripLogs`, `transportAttendance`, `sosAlerts` and `fuelLogs`. That means the TMS F9 Teacher-app driver experience — committed back at `9cc9c58` — has **never functioned in production**, because its rules were never shipped. This is a Transport matter, outside this task's scope; it is documented here and left alone.

---

## 8. Real-Device Result

> **OPERATOR VERIFICATION REQUIRED**

A real driver lifecycle needs an authenticated device and a real trip. Performing it would require credentials I do not have, and would write irreversible trip and attendance records. I stopped at that boundary. **No credentials were forged, no test driver/trip/account was created, and nothing is claimed to have passed.**

What *is* verified without a device: the API endpoints return **401, not 404** (routes resolve, Bearer gate fires); the client's only `tripLogs` write is allowlist-exact; and both the accepted and rejected payload shapes are pinned by passing rules tests.

---

## 9. ATS Browser Result

> **OPERATOR VERIFICATION REQUIRED**

Unchanged from the previous gate. `/ats` correctly returns 307 → `admin_login` (auth fails closed, no content leaked, no 500). Completing the flow needs an operator session, and Convert-to-Staff would create a **real employee plus a Firebase Auth account** — production-like data the instructions forbid manufacturing. Stopped at that boundary.

---

## 10. Rules Deployment Result

**NOT DEPLOYED.** No `firebase deploy` was run. No TTL command was run.

### Exact deployment scope, reported before any action

```
firebase deploy --only firestore:rules --project graderadmin
```

publishes the complete working-tree ruleset (2,557 lines), replacing the deployed 2,007-line ruleset. It would:

**Close a live security hole**
- `hrJobs` / `hrApplicants` — bind writes to the caller's tenant (`isSameSchoolWrite`). **This is the reason to deploy.**

**Add ~29 currently-denied collections**, all of which are denied by the catch-all today, so each is a strict improvement or neutral:
- `locations`, `studentEntitlements`, `studentFeeCredits` (deny-all), the three F12.1 compliance collections, `mapsConfig` / `mapsSecrets` (deny-all) / `mapsConfigAudit`, `vehicleLivePositions`, `vehicleBreadcrumbs`, `tripLogs`, `transportAttendance`, and the remaining transport set.

**Break nothing.** Verified, not assumed: every collection whose rule changes semantics is either currently absent (denied by catch-all) or has its read arm unchanged. The two recruitment blocks keep their read arms and only gain a tenant binding on writes, which the PHP layer already satisfies.

**Deployment authority is not mine.** Per instruction, I am reporting the scope and stopping.

---

## 11. Post-Deployment Result

**Not applicable — nothing was deployed.** The post-deployment verification steps (recruitment tenant isolation, tripLogs driver access, GPS access, `mapsSecrets` protection, entitlement protection, and a Teacher lifecycle re-check) remain queued for the authorized window. The rules-suite equivalents of all of these already pass locally (352/352).

---

## 12. Remaining Operator Actions

| # | Action | Why it needs you | Severity |
|---|---|---|---|
| 1 | **Authorize the rules deployment** | Production deploy authority | **HIGH** — closes a live PII exposure |
| 2 | Enable `vehicleBreadcrumbs` TTL (`DEPLOY_RUNBOOK` Phase 2b) | gcloud/console step; `firebase deploy` never creates it | MEDIUM |
| 3 | Approve + commit + release the **Teacher** build | Release management | MEDIUM |
| 4 | Real driver device lifecycle test | Device + credentials | MEDIUM |
| 5 | ATS browser smoke test (esp. one Convert-to-Staff) | Operator session; creates a real employee | MEDIUM |
| 6 | Decide on the 29 undeployed Transport collections | Transport owner's call, not HRMS | Informational |

---

## 13. Security Status

| Item | Status |
|---|---|
| `hrJobs` / `hrApplicants` cross-tenant write | **LIVE EXPOSURE — CONFIRMED against production.** Fix written, tested (23/23), **not deployed** |
| Privilege escalation via role-name bypass (ATS) | **FIXED** in code |
| ATS staff double-prefix / IDOR-adjacent lookups | **FIXED** |
| Attendance device-punch tenant check | **FIXED** |
| `mapsSecrets` | deny-all, 8 assertions; **no secret value asserted, logged or printed** |
| Child location data | live position restricted to authorized viewers; breadcrumbs staff-only |
| Entitlement ledgers | deny-all incl. admin update |
| D-8 one-lifecycle guarantee | intact, now test-pinned |

---

## 14. Tenant-Isolation Status

Every changed collection has an explicit cross-tenant assertion (read and write), plus forged-`schoolId` and missing-`schoolId` denials, and spoofed-driver-attribution denial for GPS. Verified across **5 tenants / 103 staff documents** at runtime. No tenant id is hardcoded anywhere in the changed surface; all fixtures are synthetic `SCH_TEST_*`.

---

## 15. Exact Files Changed (this task)

**None.** This was a read-only release-closure task.

The only artifacts are this report and a read-only scratchpad probe (outside the repo). Cumulative changes from the preceding gates remain exactly as previously reported and are unchanged here.

---

## 16. Exact Files NOT Changed

- `firebase-rules/firestore.rules` — **not modified, not deployed**; D-8 not reverted; `hrJobs`/`hrApplicants`/`tripLogs`/`vehicleLivePositions`/`vehicleBreadcrumbs` not weakened
- `Attendance.php`, `Ats.php`, `Hr.php`, `Payroll.php` — untouched
- Teacher and Parent repositories — untouched this task (read-only inspection)
- Any other session's work (F12.1, F13, Student Entitlements) — untouched, unreverted
- No W2 code of any kind

---

## 17. Confirmation — Transport was NOT incorporated into HRMS

**Confirmed.** Transport remains a separate ZenX ERP module. It appears here only because Firebase deploys `firestore.rules` as one file. No Transport feature was added to the HRMS architecture, no Transport development was performed, and the Transport findings in §7 are **documented and left alone**. F13 is not an HRMS roadmap item and was not treated as one.

---

## 18. Confirmation — No HRMS architecture was restarted

**Confirmed.** No Asanify research, no coverage matrix, no redesign of HRMS, Attendance, RBAC, ATS, Transport or F13. No second trip lifecycle, no second employee master, no duplicate ATS. The established roadmap and prior decisions were treated as settled.

---

## 19. Confirmation — W2 was NOT started

**Confirmed.** No Compensation Core work: no salary component master, no salary structures, no pay bases, no statutory engine, no payroll redesign, no shift system, no ESS, no Corporate/Retail packs.

---

## 20. Final Recommendation

### Classification

> **SECURITY_RELEASE_GATE: OPERATOR_ACTION_REMAINING**

All engineering is complete and verified. Everything outstanding requires production authority, an operator session, or a physical device.

### The recommendation itself

**Deploy the rules sooner rather than later, and decouple that decision from the Teacher release.**

The previous two gates held deployment back to protect drivers from a regression. That reason has now been disproven: `tripLogs` is not deployed, so the driver path is already denied and cannot regress. Meanwhile the candidate-PII exposure is live and confirmed. The risk balance has inverted — **waiting now costs more than deploying.**

Suggested order:
1. **Authorize the rules deploy** (with the TTL step in the same window) — closes the live exposure and simultaneously makes 29 transport collections reachable for the first time.
2. Run the post-deployment verification in §11.
3. Approve/commit/release the Teacher build; then the device test.
4. ATS browser smoke test.
5. Separately, decide what the 29 newly-reachable Transport collections mean for the Transport owner — that is their call, not an HRMS task.

Steps 3–5 no longer gate step 1.

> ### NEXT ENGINEERING PHASE: **HRMS W2 — COMPENSATION CORE**

Not started, and not to be started until you approve.

---

## Commit Policy

**No commits created.** Proposed scopes, for approval only:

| Repo | Scope |
|---|---|
| **Admin** | W1.3 ATS migration + helpers + Attendance fix + verifiers + rules tests + reports |
| **Teacher** | F13 API-based trip lifecycle + GPS + maps + the checkpoint metadata fix (10 files) |
| **Parent** | F13 read-only live-map client (7 files) — **separate commit**, independent |

Nothing committed, pushed, merged, or deployed.
