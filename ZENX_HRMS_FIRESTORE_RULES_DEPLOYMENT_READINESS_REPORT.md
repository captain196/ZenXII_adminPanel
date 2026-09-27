# ZENX HRMS — FIRESTORE RULES DEPLOYMENT READINESS REPORT

**Date:** 2026-08-09
**Scope:** Certify the combined working-tree ruleset sufficiently to authorize a coordinated deployment.
**Classification:** **NOT_SAFE_FOR_COORDINATED_DEPLOYMENT** — one blocker, and it is not the recruitment work.
**Branch:** `integration/phase2` — **UNCOMMITTED, UNPUSHED, NOT DEPLOYED**

---

## 1. Executive Summary

The task was to make the combined ruleset certifiable. Rules test coverage went from **247 tests across 11 suites** to **350 tests across 15 suites** — 103 new tests covering every previously untested block. All 350 pass. No production rule was modified, and no application code was changed.

Certification succeeded for four of the five workstreams. It **failed for F13**, and the reason was not a missing test — it was a client-compatibility fact that only surfaced by reading the other two repositories:

> **The `tripLogs` allowlist narrowing (D-8) is a RESTRICTIVE change to a rule that a SHIPPED client depends on.** The committed Teacher app's `startMyTrip()` and `completeMyTrip()` write `status`, `actual_start_at`, `actual_end_at`, `endTime`, `endOdometer`, `distance`, `fuelUsed` and `notes` directly to `tripLogs`. Every one of those fields is now outside the permitted allowlist. **Deploying today would break trip start and trip completion for every driver currently running the installed app.**

The compensating client change exists — the working-tree `startMyTrip()` calls `apiService.startTrip()` instead — but it is **uncommitted** in `SchoolSyncTeacher` (10 dirty files), as is the Parent viewer (7 dirty files).

This was invisible to the test suite in both directions: `transport_triplogs_sec2.test.js` never exercised the removed fields, so the suite stayed green before *and* after the narrowing. My new test now asserts the D-8 guarantee explicitly — and that same passing assertion is precisely what would break the live app.

Meanwhile, **the W1.3 recruitment exposure remains live in production** (§2). The correction is written and tested but cannot ship alone, because `firestore.rules` deploys as one file.

---

## 2. Current Production Security Exposure

```
CURRENT EXPOSURE:   HIGH
CORRECTION:         PRESENT IN WORKING TREE
TESTED:             YES — 23/23 recruitment security tests
DEPLOYED:           NO
```

Until the corrected ruleset is deployed, the **deployed** rules for `hrJobs` and `hrApplicants` remain:

```
allow write: if isAdmin();
```

`isAdmin()` proves the caller is an admin of *some* active tenant; it does not bind the write to the target document's school. Any tenant admin holding a valid token can therefore **create, modify and delete another school's job postings and candidate PII** through a direct Firestore client SDK call.

Passing local tests do not protect production. The server-side PHP layer scopes every write correctly, so the admin panel itself cannot perform this — the exposure is specifically to direct client-SDK access with a valid admin token.

---

## 3. Current `firestore.rules` Inventory

From the current working tree (`git diff -- firebase-rules/firestore.rules`: **218 insertions, 8 deletions**). **Six** workstream areas, **14** blocks — one more than the previous report recorded, because `tripLogs` is a *modification* rather than an addition and did not appear in an added-block scan.

| # | Collection | Workstream | Purpose | Current rule behaviour | Type | Sensitive | Client dep. |
|---|---|---|---|---|---|---|---|
| 1 | `hrJobs` | W1.3 ATS | Job postings | read same-school; C/U admin+`isSameSchoolWrite`; D admin+`isSameSchool` | **modifying** | Med | Teacher app reads |
| 2 | `hrApplicants` | W1.3 ATS | Candidate records | read admin+same-school; C/U/D as above | **modifying** | **High (PII)** | none |
| 3 | `locations` | Phase-1 HRMS | Campus/office/store | read same-school; C/U/D admin-bound | additive | Low | none |
| 4 | `studentEntitlements` | Student Entitlements | Effective-dated entitlement ledger | deny-all | additive | **High (money)** | none |
| 5 | `studentFeeCredits` | Student Entitlements | Undrawn credit balance | deny-all | additive | **High (money)** | none |
| 6 | **`tripLogs` (update arm)** | **F13 / D-8** | **Trip lifecycle** | **allowlist narrowed to checkpoints/progress/metadata; status transition removed** | **MODIFYING — RESTRICTIVE** | **High** | **SHIPPED driver app** |
| 7 | `transportComplianceRules` | F12.1 | CBSE compliance policy | read same-school+staff; write false | additive | Med | server-only |
| 8 | `vehicleCompliance` | F12.1 | Per-vehicle compliance | same | additive | Med | server-only |
| 9 | `vehicleCompliancePosture` | F12.1 | Fleet posture rollup | same | additive | Med | server-only |
| 10 | `mapsConfig` | F13 Maps | Public map config | read same-school+auth; write false | additive | Med | apps (uncommitted) |
| 11 | `mapsSecrets` | F13 Maps | Server-side keys | **deny-all** | additive | **HIGH (secrets)** | none |
| 12 | `mapsConfigAudit` | F13 Maps | Config change history | read same-school+staff; write false | additive | Med | none |
| 13 | `vehicleLivePositions` | F13.1 GPS | Live vehicle position | read staff **or** authorized viewer; C/D false; U driver+monotonic+kill-switch | additive | **HIGH (child location)** | **UNCOMMITTED** |
| 14 | `vehicleBreadcrumbs` | F13.1 GPS | Movement trail | read same-school+staff; create driver-attributed; U/D false | additive | **HIGH (location history)** | **UNCOMMITTED** + TTL |

All eight helper functions referenced by the new blocks (`isAuth`, `isStaff`, `isSameSchool`, `isSameSchoolWrite`, `isAdmin`, `isDriverOfTrip`, `driverIs`, `hasClientMetadata`) exist and are defined exactly once. No other session's work was reverted, cherry-picked or altered.

---

## 4. W1.3 Recruitment Rule Certification

**Unchanged this task** — not redesigned, not weakened, `isSameSchoolWrite()` intact, tenant binding intact.

`recruitment_sec.test.js` — **23/23 PASS**:

| Case | Result |
|---|---|
| Tenant A admin → Tenant A job/applicant | ALLOWED ✅ |
| Tenant A admin → Tenant B job/applicant (update/create/delete) | DENIED ✅ |
| Forged `schoolId` field | DENIED ✅ |
| Missing `schoolId` field | DENIED ✅ |
| Unauthorized caller (staff / anon) | DENIED ✅ |
| Same-tenant valid operations | ALLOWED ✅ |
| Candidate PII unreadable by non-admin staff | DENIED ✅ |

Previously proven load-bearing: against the pre-fix rule, exactly 8 of these fail.

**Classification: READY.**

---

## 5. Phase-1 Locations Rule Certification

`locations_sec.test.js` — **13/13 PASS**, pre-existing. Same house idiom as recruitment (`isAdmin() && isSameSchoolWrite()` on create/update, `isAdmin() && isSameSchool()` on delete). Additive collection, no client reads it today.

**Classification: READY.**

---

## 6. Student Entitlements Rule Analysis

Required determinations:

1. **Why deny-all?** Written and read exclusively by the PHP backend through the service account, which bypasses rules. Phase B is additive and parallel — nothing generates from these yet.
2. **Is deny-all intentional?** Yes, and it is the established house pattern, not a one-off. Twelve-plus predecessors use the identical shape: `feeIdempotency`, `feeLocks`, `feeCounters`, `feeReceiptIndex`, `feeDemandLocks`, `feePendingWrites`, `superAdmins`, `marksAudit`, `resultsAudit`, `resultsStaging`, `examResultMeta`.
3. **Does any application depend on them?** **No — verified independently, not assumed.** `grep` across the full Parent and Teacher repositories returns **zero** references to either collection. Clients see the effect via additive `feeDemands` fields, which already grant own-student read. Only PHP touches them (8 files).
4. **Could deploying break a live workflow?** **No.** There is no client workflow to break, and the PHP service account is unaffected by rules.

**Tests added: 18** (`student_entitlements_sec.test.js`) — read/create/update/delete denied for admin, staff, the student's own parent, anonymous, and cross-school callers. The update-denial test matters most: a writable entitlement ledger would be a direct route to free tuition.

**Classification: READY.**

---

## 7. F12.1 Transport Rule Analysis

Three collections, one documented model: *server-only writes via `Transport_compliance_service`; staff-read for the compliance dashboard.*

**Tests added: 27** (`transport_compliance_sec.test.js`, 9 × 3 collections): same-school staff read ✅, admin read ✅, parent denied, anonymous denied, cross-school staff denied, and create/update/delete denied for **every** client including admins.

**One design observation, asserted as-designed rather than "fixed":** the rules comment states plainly that *"Authorization grading (view/edit/manage) is a panel-layer concern … the rules layer intentionally stays a simple staff-read / server-write boundary and does not mirror RBAC levels."* The consequence is that a staff member with **no transport RBAC grant** can still read compliance documents through a direct client SDK call. That is the stated design decision (spec §5 amendment), the data is operational rather than personal, and the write path is fully closed — so it is recorded here for the owner rather than changed by me. Changing it would be redesigning Transport.

**Classification: READY.**

---

## 8. F13 Live GPS Rule Analysis

The rules themselves are **well constructed** — genuinely the strongest new blocks in the file — and my tests confirm every documented control:

| Control | Verified |
|---|---|
| D-1 server-created / driver-updated (no client create or delete) | ✅ |
| Parent read restricted to `authorized_viewer_uids` | ✅ authorized parent reads; unauthorized parent denied |
| Cross-tenant read denied | ✅ |
| Only the **assigned** driver may post a fix | ✅ other driver, teacher, parent all denied |
| D-2 kill switch — no writes once `live_tracking_enabled` false | ✅ |
| Monotonic ordering — stale offline flush cannot rewind the map | ✅ |
| Future-clock bound | ✅ |
| Ancient-fix bound | ✅ |
| Field allowlist enforced | ✅ |
| Driver cannot re-enable their own kill switch | ✅ |
| Breadcrumbs staff-only (parents denied movement history) | ✅ |
| Breadcrumb driver attribution cannot be spoofed | ✅ |
| Breadcrumbs immutable (no update, no delete) | ✅ |

**Tests added: 35** (`f13_gps_sec.test.js`), including 4 for the D-8 `tripLogs` narrowing.

### One documented weakness (LOW)

`hasClientMetadata()` inspects `request.resource.data`, which on an **update** is the *resulting* document. Once one good write has stamped `app_version` / `client_platform` / `client_timestamp`, later fixes may omit them and the stale values keep satisfying the guard. My test asserts this as `assertSucceeds` because it is the real behaviour, and a test that lies about it is worse than no test.

Severity is low **by the rule's own design** — the comment states these fields are *"NOT used for authz — they're operational telemetry."* The consequence is stale diagnostics, not weakened authorization. Reported, not silently patched: changing a production rule to satisfy a test is out of scope for a certification pass.

**Classification: BLOCKED** — see §14/§15, and the blocker is client compatibility, not rule quality.

---

## 9. Rule Test Coverage BEFORE This Task

| Collection | Tests before |
|---|---|
| `hrJobs`, `hrApplicants` | 23 (added in W1.3) |
| `locations` | 13 |
| `tripLogs` | 5 — **but none exercised the narrowed fields** |
| `studentEntitlements`, `studentFeeCredits` | **0** |
| `transportComplianceRules`, `vehicleCompliance`, `vehicleCompliancePosture` | **0** |
| `mapsConfig`, `mapsSecrets`, `mapsConfigAudit` | **0** |
| `vehicleLivePositions`, `vehicleBreadcrumbs` | **0** |

The 247/247 green suite was consistent with all ten of those zeros. That is exactly why a green suite could not have been used as deployment evidence.

---

## 10. Rules Tests Added

| File | Tests | Covers |
|---|---|---|
| `f13_gps_sec.test.js` | **35** | `vehicleLivePositions`, `vehicleBreadcrumbs`, `tripLogs` D-8 |
| `transport_compliance_sec.test.js` | **27** | the three F12.1 collections |
| `maps_config_sec.test.js` | **23** | `mapsConfig`, `mapsSecrets`, `mapsConfigAudit` |
| `student_entitlements_sec.test.js` | **18** | `studentEntitlements`, `studentFeeCredits` |
| **Total** | **103** | |

Every assertion derives from the access model documented inline in `firestore.rules` itself. No business permission was invented, and no production rule was changed to make a test pass.

---

## 11. Complete Rules Test Results

| | Before | After |
|---|---|---|
| Suites | 11 | **15** |
| Tests | 247 | **350** |
| Failures | 0 | **0** |
| Runtime | 48.6 s | 48.7 s |

```
PASS h_lifecycle · f13_gps_sec · maps_config_sec · h_lifecycle_l2 · exam_visibility
PASS student_flags · recruitment_sec · circular_reads · student_entitlements_sec
PASS transport_compliance_sec · locations_sec · fold_isadmin · transport_triplogs_sec2
PASS staff_attendance_summary_sec · transport_attendance_sec1
Test Suites: 15 passed, 15 total   Tests: 350 passed, 350 total
```

The known `h_lifecycle` flake did not occur (15.6 s).

---

## 12. Cross-Tenant Security Results

Every changed collection now has an explicit cross-tenant assertion:

| Collection | Cross-tenant read | Cross-tenant write |
|---|---|---|
| `hrJobs` / `hrApplicants` | DENIED ✅ | DENIED ✅ (create, update, delete) |
| `locations` | DENIED ✅ | DENIED ✅ |
| `studentEntitlements` / `studentFeeCredits` | DENIED ✅ | DENIED ✅ |
| `transportComplianceRules` / `vehicleCompliance` / `vehicleCompliancePosture` | DENIED ✅ | DENIED ✅ |
| `mapsConfig` / `mapsConfigAudit` | DENIED ✅ | DENIED ✅ |
| `mapsSecrets` | DENIED ✅ (all callers) | DENIED ✅ |
| `vehicleLivePositions` | DENIED ✅ | DENIED ✅ (cross-school driver) |
| `vehicleBreadcrumbs` | DENIED ✅ | DENIED ✅ (cross-tenant append) |

Also asserted: forged `schoolId`, missing `schoolId`, and — for GPS — spoofed driver attribution.

No tenant id is hardcoded in any test; all use synthetic `SCH_TEST_*` fixtures. The rules and application remain fully tenant-agnostic.

---

## 13. Sensitive-Data Security Results

**`mapsSecrets` — closed to every client, in every direction** (8 assertions): admin, staff, parent, anonymous and cross-school callers are all denied read, create, update and delete. The seeded value is an obvious non-credential placeholder, and **no test asserts on, logs, or prints any secret value** — every secret assertion is a denial.

**Child location data:**
- A parent may read the single current position of their own child's bus **only** while that child is on the frozen trip roster.
- **Parents are denied breadcrumb access entirely** — a movement trail is materially more sensitive than one current position, and the rule reflects that.
- A driver can only ever write breadcrumbs attributed to themselves.

**Money ledgers** (`studentEntitlements`, `studentFeeCredits`): fully closed, including admin update.

---

## 14. Client Dependency Analysis

| Workstream | Client dependency | Present? | Committed? | Safe to deploy rules first? |
|---|---|---|---|---|
| W1.3 ATS | Teacher reads `hrJobs` (read arm **unchanged**) | Yes | Yes | ✅ Yes |
| Phase-1 Locations | none | — | — | ✅ Yes |
| Student Entitlements | **none in either app** (verified) | — | — | ✅ Yes |
| F12.1 Transport | server-only | — | — | ✅ Yes |
| F13 Maps | both apps read `mapsConfig` | Yes | **No** | ✅ Yes (additive; absent client simply doesn't read) |
| F13 GPS collections | Teacher writes, Parent reads | Yes | **No** | ✅ Additive — nothing writes until the client ships |
| **F13 / D-8 `tripLogs`** | **shipped driver app writes `status`** | **Yes** | **fix NOT committed** | ❌ **NO — breaks trip start/complete** |

### The blocking detail

**Committed** (`git show HEAD:…/TransportFirestoreRepository.kt`) — what drivers are running now:
```kotlin
suspend fun startMyTrip(tripId: String): Result<Unit> {
    val update = mapOf(
        "status" to "InProgress",
        "actual_start_at" to nowIso(),
        "updated_at" to nowIso()
    ) + ClientMetadata.asMap()
    firestoreService.updateDocument(TRIP_LOGS, tripId, update)   // ← direct write
}
```
`completeMyTrip()` likewise writes `status`, `actual_end_at`, `endTime`, `endOdometer`, `distance`, `fuelUsed`, `notes`.

**Working tree (uncommitted)** — the compensating fix:
```kotlin
suspend fun startMyTrip(tripId: String): Result<Unit> = runCatching {
    val res = apiService.startTrip(tripId)                        // ← routed through PHP
    ...
}
```

**New allowlist:** `['checkpoints', 'route_progress_pct', 'next_checkpoint_id', 'updated_at', 'app_version', 'client_platform', 'client_timestamp']`.

Every field the shipped client writes for start and complete is now rejected. My test `THE D-8 GUARANTEE: the driver CANNOT write trip status directly` passes — and that same rejection is the outage.

---

## 15. F13 Three-Repository Dependency

| Repo | Branch | Dirty files | F13 client code |
|---|---|---|---|
| Admin (this) | `integration/phase2` | many | rules + PHP services |
| **Teacher** | `ankit/my_teacherFeature` | **10 uncommitted** | `TripTrackingService.kt`, `TripTrackingController.kt`, `data/maps/` (all untracked); modified repository, screen, DI, manifest, gradle |
| **Parent** | `ankit/my_parentFeature` | **7 uncommitted** | `VehicleLivePositionDoc.kt`, `LiveBusMap.kt`, `ui/maps/`, `data/maps/` (untracked); modified repository, `MyBusScreen.kt`, gradle |

F13.1 is genuinely a three-repository feature and **none of the client work is committed**, let alone built, signed, released and installed on driver devices. Rules cannot lead the driver app here, because the `tripLogs` change is restrictive.

---

## 16. `vehicleBreadcrumbs` TTL Readiness

**Documented and correct, but NOT executed.**

`DEPLOY_RUNBOOK.md` Phase 2b — flagged *"⚠️ MANUAL, EASY TO FORGET"* — carries the exact command:
```
gcloud firestore fields ttls update expires_at \
  --collection-group=vehicleBreadcrumbs --enable-ttl --project=graderadmin
```
plus the verification step, the critical trap (*TTL only acts on a field of Firestore type `Timestamp`; an ISO-8601 string is silently ignored*), and a documented fallback (D-3 option B, bounded cron sweeper — the required index already ships).

The writer is correct: `Transport_gps_service` defines `TTL_FIELD = 'expires_at'` and emits it as a **Timestamp sentinel, not a string**.

**Status:** ready to run, not yet run. It is a console/gcloud action outside the repository and `firebase deploy` will never create it. If breadcrumbs begin accumulating without it, the collection grows without bound.

---

## 17. Deployment Risks

| # | Risk | Severity |
|---|---|---|
| 1 | **`tripLogs` D-8 breaks trip start/complete for every shipped driver app** | **HIGH — blocking** |
| 2 | W1.3 cross-tenant write on candidate PII stays live until deploy | **HIGH — the cost of *not* deploying** |
| 3 | Breadcrumbs accumulate unbounded if TTL is not enabled at deploy time | MEDIUM |
| 4 | F13 GPS rules go live before their clients (additive — dormant, not harmful) | LOW |
| 5 | `hasClientMetadata()` not re-validated on later fixes | LOW (telemetry only) |
| 6 | Compliance collections readable by any staff regardless of transport RBAC | LOW (by documented design) |

Risks 1 and 2 point in **opposite directions**, which is the real tension: deploying fixes a live HIGH security hole and simultaneously causes a live HIGH functional outage. That is precisely why this needs a coordinated window rather than a judgement call.

---

## 18. Exact Deployment Scope

```
firebase deploy --only firestore:rules --project graderadmin
```

publishes the **entire** working-tree ruleset — all 14 blocks in §3, across all six workstream areas. There is no mechanism to ship a subset. Indexes, functions and storage are separate targets and are **not** in scope here.

---

## 19. What Deployment Would Change

**Would fix immediately**
- Cross-tenant create/update/delete on `hrJobs` and `hrApplicants` (candidate PII) — closed.
- Cross-tenant write on `locations` — closed.

**Would activate, dormant until clients ship**
- `mapsConfig` / `mapsSecrets` / `mapsConfigAudit`, `vehicleLivePositions`, `vehicleBreadcrumbs`, the three F12.1 collections, and the two deny-all entitlement blocks. All additive; nothing reads or writes them today.

**Would break immediately**
- **Driver trip start** — `status` + `actual_start_at` rejected.
- **Driver trip completion** — `status`, `actual_end_at`, `endTime`, `endOdometer`, `distance`, `fuelUsed`, `notes` rejected.
- Drivers would still be able to mark checkpoints, so the failure is partial and may present as "the app won't let me start/end my trip."

---

## 20. What Remains Unverified

- **Runtime behaviour of the deployed ruleset** — everything here is emulator-verified against the working-tree file.
- **F13 clients against these rules** — the Kotlin code is uncommitted and was not built or run; compatibility is inferred from reading it, not from executing it.
- **TTL policy** — documented, never executed; no verification that `expires_at` is accepted as a Timestamp in the live project.
- **ATS browser/session/CSRF layer** — still operator-owned (§21), unchanged from the previous gate.
- **Whether any tenant currently has drivers actively using the shipped app** — this determines whether risk 1 is a live outage or a theoretical one, and I cannot see device install data.

---

## 21. Operator Actions Required

1. **ATS browser smoke test** — `http://localhost/ZenX/school/ats`. Load the board, advance one candidate, and run **one** Convert to Staff. This remains the highest-value single check available: because of the double-prefix defect fixed in the previous gate, hire finalization has **never once succeeded in production**.
   Status: **OPERATOR VERIFICATION REQUIRED** — not faked, not simulated, not claimed.
2. **Confirm whether drivers are actively using the shipped Teacher app**, which decides the severity of risk 1 in practice.

---

## 22. Owner / Coordinated-Window Actions Required

1. **F13 owner:** commit, build, release and roll out the Teacher app (API-based trip lifecycle) to driver devices **before** the rules deploy. This is a hard ordering constraint, not a preference.
2. **F13 owner:** commit and release the Parent app live-map client.
3. **Deployer:** run the `vehicleBreadcrumbs` TTL command (DEPLOY_RUNBOOK Phase 2b) as part of the same window, and verify with `gcloud firestore fields ttls list`.
4. **F12.1 owner:** acknowledge the documented staff-read-without-RBAC posture (§7) — accept or schedule.
5. **F13 owner:** decide whether to tighten `hasClientMetadata()` on update (§8) — low priority.

---

## 23. Final Classification

### Per workstream

| Workstream | Classification |
|---|---|
| **W1.3 ATS / Recruitment** | **READY** |
| **Phase-1 Locations** | **READY** |
| **Student Entitlements** | **READY** |
| **F12.1 Transport Compliance** | **READY** |
| **F13 Live GPS / Maps / D-8** | **BLOCKED** |

### Whole ruleset

> ## **NOT_SAFE_FOR_COORDINATED_DEPLOYMENT**

**The one blocker:**

| Field | Detail |
|---|---|
| **Rule block** | `tripLogs` — update allowlist (D-8 narrowing) |
| **Reason** | Restrictive change to a rule a **shipped** client depends on; removes `status`, `actual_start_at`, `actual_end_at`, `endTime`, `endOdometer`, `distance`, `fuelUsed`, `notes` from the driver-writable set |
| **Missing test** | None any more — added and passing (4 tests). The gap was never the test; it was client compatibility |
| **Missing client dependency** | Teacher app API-based lifecycle: **written but uncommitted**, unbuilt, unreleased, not installed on driver devices |
| **Missing owner approval** | F13 owner sign-off that the driver rollout precedes the rules deploy |
| **Missing deployment prerequisite** | `vehicleBreadcrumbs` TTL policy not yet enabled |

Everything else in the file is certified and safe. Four of five workstreams are READY; the ruleset is blocked solely because deployment is all-or-nothing and one block would cause a live outage.

---

## 24. Exact Smallest Next Action

**Commit and release the Teacher app's API-based trip lifecycle to driver devices.** That single action converts the only blocker into a READY, after which the combined deployment can be authorized in one window (rules deploy + TTL command together).

If that rollout cannot happen soon and the live candidate-PII exposure (§2) is judged the greater risk, the alternative — **which I am flagging, not recommending, because it is the F13 owner's call** — is to deploy with the `tripLogs` block temporarily reverted to its pre-D-8 allowlist, shipping every other fix including the recruitment binding, then restore D-8 in a second deploy once the driver app lands. That trades one HIGH risk for the other and needs explicit owner authorization; **I have not made that change.**

---

## Test / Regression Summary

| Suite | Baseline | Current | New failures | Pre-existing failures |
|---|---|---|---|---|
| Firestore rules | 247/247 (11 suites) | **350/350 (15 suites)** | **0** | 0 |
| `recruitment_sec` | 23/23 | **23/23** | 0 | 0 |
| PHP unit suite | 192 / 549 assertions / 10 failures | **192 / 549 / 10** | **0** | 10 |
| `hrms_p0_verify` (W1.1 + W1.2) | 125 PASS / 0 FAIL / 1 SKIP | **125 / 0 / 1** | 0 | 0 |
| `hrms_w13_verify` | 44 / 0 / 1 | **44 / 0 / 1** | 0 | 0 |
| `hrms_closure_verify` | 24 / 0 / 0 | **24 / 0 / 0** | 0 | 0 |
| `php -l` | clean | **clean** | — | — |

The 10 PHP failures are unchanged and pre-existing: 1 `BackupTest` format drift, 4 `Homework*` source regressions, 5 cross-repo Kotlin tests. None hidden, none opportunistically "fixed".

**No PHP file was modified during this task.** Only rules tests were added.

---

## Change Control

**New (5):**
- `firebase-rules/tests/f13_gps_sec.test.js`
- `firebase-rules/tests/maps_config_sec.test.js`
- `firebase-rules/tests/transport_compliance_sec.test.js`
- `firebase-rules/tests/student_entitlements_sec.test.js`
- `ZENX_HRMS_FIRESTORE_RULES_DEPLOYMENT_READINESS_REPORT.md`

**Modified:** none.

**Explicitly NOT changed:** `firestore.rules` (no production rule modified), `Attendance.php`, `Ats.php`, `Hr.php`, `Payroll.php`, any application logic, any other session's work, and both app repositories (read-only inspection only).

**Not done:** no commit, no push, no merge, **no deploy**, no W2 work, no unrelated fixes.
