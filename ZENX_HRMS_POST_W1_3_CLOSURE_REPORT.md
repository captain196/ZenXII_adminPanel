# ZENX HRMS — POST-W1.3 CLOSURE GATE REPORT

**Date:** 2026-08-09
**Scope:** Three closure gates before W2. No W2 work started.
**Status:** **BLOCKED_BEFORE_W2** — see §22. Both blockers require operator action, not further engineering.
**Branch:** `integration/phase2` — **UNCOMMITTED, UNPUSHED, UNDEPLOYED**

| Gate | Result |
|---|---|
| 1 — Attendance double-prefix defect | **CONFIRMED and FIXED**, verified at runtime (24/24) |
| 2 — ATS operator/browser smoke test | **BLOCKED** — no operator session; conversion would create a real employee |
| 3 — Firestore rules review / deployment | Reviewed. **NOT DEPLOYED — coordinated window required** |

---

## 1. Gate 1 Findings

Verified against the **current working tree**, not the W1.3 report. All seven Gate-1A checks were performed.

| # | Check | Finding |
|---|---|---|
| 1–2 | Current implementation at ~3037 | Defect **present** |
| 3 | Does `getEntity()` apply the tenant prefix? | **Yes** — `getEntity($c,$id)` → `get($c, docId($id))`, and `docId($id)` → `{schoolId}_{id}` |
| 4 | Canonical Staff document id | `{schoolId}_{staffId}` — **103 staff docs across 5 tenants, 0 double-prefixed** |
| 5 | Genuinely present in current tree? | **Yes**, both sites |
| 6 | Individual-report path ~3627 | Defect **present** |
| 7 | Other occurrences in `Attendance.php` | **None** — exactly two, both fixed |

**The report's line numbers had drifted** (the tree moved to 3037 → 3037 and 3627 → 3638 after the earlier P0 edits). The defect itself was confirmed independently by pattern search rather than by trusting the cited lines.

---

## 2. Attendance Defect Evidence

The decisive evidence is that the **student branch immediately above uses the correct API**:

```php
// line 3015 — student membership check
$stuDoc   = $this->fs->get      ('students', "{$schoolIdForCheck}_{$personId}");   // ✅ get() takes a RAW doc id

// line 3037 — staff membership check
$staffDoc = $this->fs->getEntity('staff',    "{$schoolIdForCheck}_{$personId}");   // ❌ getEntity() prefixes AGAIN
```

Identical intent, identical id construction, two different helpers — a copy-paste divergence between adjacent branches of one `if`. The effective read was `staff/{school}_{school}_{staffId}`.

Because the very next statement is a hard guard:

```php
if (empty($staffDoc) || !is_array($staffDoc)) {
    return $this->json_error('Staff ID does not belong to this school.', 403);
}
```

…the lookup failing meant **every device punch for a staff member returned 403**, and the P0-5/B1 inactive-staff check directly below it was unreachable dead code. It fails in the safe direction (denial, never leakage), but it is a functional outage of the biometric/RFID/face punch path.

**Reproduced against live data** (Gate 1C, test B3):
```
B2 FIXED    get(staff, "SCH_218AAF5C23_SSA0013")                    → resolves
B3 PRE-FIX  getEntity(staff, "SCH_218AAF5C23_SSA0013")
            → probed SCH_218AAF5C23_SCH_218AAF5C23_SSA0013          → resolves NOTHING
B1 103 staff docs, 0 double-prefixed
```

The second site (`fetch_individual_report`) has the same fault with a milder symptom: the staff name renders silently blank on the individual attendance report.

---

## 3. Attendance Fix Details

**Two lines changed. Nothing else.**

```diff
- $staffDoc = $this->fs->getEntity('staff', "{$schoolIdForCheck}_{$personId}");
+ $staffDoc = $this->fs->get      ('staff', "{$schoolIdForCheck}_{$personId}");

- $staffDoc = $this->fs->getEntity('staff', "{$this->school_id}_{$personId}");
+ $staffDoc = $this->fs->get      ('staff', "{$this->school_id}_{$personId}");
```

### Why NOT the fix suggested in the gate brief

The brief proposed `getEntity('staff', $personId)`, conditional on verifying the helper contract first. **Verification showed that form would be wrong at the device-punch site**, and this is the substantive finding of Gate 1B.

`$schoolIdForCheck` is derived from the **API key** (`$auth['school_id']`, with a schoolName fallback), **not** from the session. `api_punch` is a device endpoint that can run with no admin session at all, so `$this->fs`'s own `schoolId` is not a trustworthy source there. Letting `getEntity()` supply the prefix would silently re-bind the membership check from the API-key-derived tenant to whatever the session happens to be — a change in tenant-isolation semantics, not a bug fix.

The existing code comment states this intent explicitly:

> *"Tenant isolation is unchanged — the docId above is still built from the API-key-derived `$schoolIdForCheck`."*

Switching to `get()` preserves that intent exactly, matches the adjacent student branch, and is a one-word change. At the second site either form would be equivalent (`$this->school_id` *is* the session tenant), but `get()` was used for consistency with its own neighbour at line 3630.

**No Attendance redesign, no architecture change, no business-rule change, no new Attendance implementation.**

---

## 4. Attendance Verification (Gate 1C)

New read-only harness `application/controllers/Hrms_closure_verify.php` — **24 PASS / 0 FAIL / 0 SKIP**. Zero writes, zero attendance records created.

| Required check | Result |
|---|---|
| 1. Valid staff id in current tenant resolves | ✅ B2 |
| 2. Invalid staff id fails correctly | ✅ B4 (guard still fails closed) |
| 3. Foreign-tenant staff id cannot resolve | ✅ B5 |
| 4. Inactive-staff handling intact | ✅ A4 present, B6 confirms the `status` field exists so the now-reachable guard evaluates real data |
| 5. Device-punch authorization path intact | ✅ A5 — the 403 membership guard is unchanged |
| 6. Business rules unchanged | ✅ A3 student branches untouched; A7 the 9-status attendance vocabulary untouched |
| 7. No RTDB introduced | ✅ A6, and the diff contains no RTDB call |
| 8. No new architecture | ✅ diff is two lines plus comments |

Test B5 is the strongest isolation evidence, using genuinely colliding real ids:
```
B5 tenant B staff id under tenant A prefix  → probed SCH_218AAF5C23_ADM0003  → nothing
B5 the same id under its OWN tenant         → SCH_54D0424022_ADM0003         → resolves
```
This proves the denial is tenant binding, not a broken lookup.

**Honest limitation.** No device punch was submitted. Doing so would write real attendance records that cannot be cleanly reverted, and the gate brief explicitly forbids manufacturing production-like data for certification. The defect is a document-id construction fault and has been verified at exactly that layer, with both the broken and fixed expressions executed against live documents.

---

## 5. Gate 2 — ATS Browser Smoke Test Results

**Result: BLOCKED** for the authenticated flow. Reported rather than worked around.

### What was verified

The application is served at `http://localhost/ZenX/school/` (`:8080` is XAMPP's own dashboard, not this app — an incidental finding).

| Check | Result |
|---|---|
| `/ats` route resolves | ✅ HTTP 307 (not 404) |
| Auth middleware engages and **fails closed** | ✅ redirects to `admin_login`; no content leaked |
| No fatal error in routing/construction | ✅ no 500 on any ATS route |
| `ats/*` correctly **absent** from `csrf_exclude_uris` | ✅ these are session POSTs and must keep CSRF |
| Controller + helpers load under the CI loader | ✅ `recruitment` / `hr_id` load and execute (exercised by three CLI harnesses) |
| Data + logic layer end-to-end | ✅ W1.3 E2E, 40 PASS / 0 FAIL, full Applied→Hired lifecycle |

### What could NOT be verified, and why

1. **No operator credentials.** An authenticated session is required to pass `MY_Controller`'s auth check, which runs *before* `Ats::__construct()`. I will not forge or synthesise a session — that is precisely the "test-only authentication bypass" the project forbids — and I will not create a login account (standing instruction: the operator adds real staff themselves).

2. **Convert-to-Staff must not be run by me even with credentials.** Completing it makes `Staff.php` create a **real employee** in the canonical staff master, plus an associated Firebase Auth account. That is production-like data that cannot be cleanly reverted. The gate brief is explicit: *"If the conversion workflow cannot safely be tested without creating a real employee, STOP that specific test and report the limitation."* **Stopped and reported.**

Consequently items 1–20 of the ATS smoke-test list remain operator-owned. The un-exercised layer is specifically: HTTP session, CSRF token round-trip, `require_permission()` inside a live request, `json_success()` shape over the wire, and browser rendering.

---

## 6. Actual Applicant → Staff → Hire Result

**Not executed in the browser** (§5). The equivalent flow *was* executed against live Firestore during W1.3 using the real controller methods, and is re-stated here as the current evidence:

```
Applied → Shortlisted → Interviewed → Selected        each validated, written, re-read
Hired refused via move_stage()                        "only via the Convert to Staff flow"
staff existence check resolves (single prefix)        J1
pre-W1.3 double-prefixed form resolves nothing        J2
application → Hired / Joined / ats_status=hired       K1–K4
staff_id linked                                       K3
vacancy 2 → 1, filled_positions 0 → 1                 K5, K7
Teacher-app mirrors vacancies/statusLower in step     K6
repeat call does NOT decrement again                  L2  (1 → 1)
all harness data deleted                              Z
```

**Duplicate-hire behaviour: PASS** at the data/logic layer. The idempotency key is the **staff link** (`ats_status === 'hired' AND staff_id !== ''`), not the status flag alone — so an applicant marked `Joined` by hand in HR, which derives `ats_status='hired'` without ever creating a staff record, still completes its linking instead of falsely reporting success.

**No duplicate Staff record can be created by ATS**: grep confirms `Ats.php` contains no `saveStaff`, no `setEntity('staff', …)`, and no staff-creation path. `finalize_hire()` performs exactly one staff operation — a read.

---

## 7. RBAC Smoke-Test Results

**Result: PARTIAL** — decision layer PASS, browser layer blocked.

Executed the **real `rbac_helper` functions** (Gate 2 group, 8/8 PASS):

| Check | Result |
|---|---|
| HR:view satisfies the ATS **view** gate | ✅ `granted=view` |
| HR:view does **NOT** satisfy the ATS **manage** gate | ✅ — this is exactly the escalation W1.3 removed |
| HR:manage satisfies **manage** | ✅ `granted=manage` |
| HR:manage also satisfies view | ✅ |
| No HR permission denies both gates | ✅ `granted=NULL` |
| `_require_admin()` delegates to `manage` | ✅ |
| `_require_view()` delegates to `view` | ✅ |
| No job-title string used as authorization | ✅ |

No job-title string was used as evidence anywhere, and RBAC was not weakened to make anything pass. The Phase-1 verifier's graded matrix (`rank(view) < rank(edit) < rank(manage)`, downgrade behaviour) also remains green.

What remains unverified is only whether a live browser session for a view-only operator visibly hides/denies management controls — an operator observation, not a logic question.

---

## 8. Gate 3 — Rules Review

`git diff -- firebase-rules/firestore.rules` = **218 insertions, 8 deletions**, spanning **five independent workstreams**:

| Workstream | Collections touched | Rules tests |
|---|---|---|
| **W1.3 (this phase)** | `hrJobs`, `hrApplicants` — write arms only | ✅ 23 tests |
| Phase 1 (earlier, mine) | `locations` | ✅ 13 tests |
| Student Entitlements | `studentEntitlements`, `studentFeeCredits` (deny-all) | ❌ **none** |
| F12.1 Transport compliance | `transportComplianceRules`, `vehicleCompliance`, `vehicleCompliancePosture` | ❌ **none** |
| F13 Live GPS | `vehicleLivePositions`, `vehicleBreadcrumbs`, `mapsConfig`, `mapsSecrets`, `mapsConfigAudit` | ❌ **none** |

The working-tree file **is** a coherent combined state — it parses, loads into the emulator, and the entire repo rules suite passes against it. **No other session's work was reverted, cherry-picked, or altered.**

---

## 9. Exact Rules Diff Relevant to W1.3

```diff
  match /hrJobs/{docId} {
    allow read: if isSameSchool();
-   allow write: if isAdmin();
+   allow create, update: if isAdmin() && isSameSchoolWrite();
+   allow delete: if isAdmin() && isSameSchool();
  }

  match /hrApplicants/{docId} {
    allow read: if isAdmin() && isSameSchool();
-   allow write: if isAdmin();
+   allow create, update: if isAdmin() && isSameSchoolWrite();
+   allow delete: if isAdmin() && isSameSchool();
  }
```

Read arms untouched — the asymmetry is intentional (the Teacher app lists openings; no app client reads applicant PII). House idiom only.

**Proven load-bearing:** against the pre-fix rule the suite fails exactly 8 tests, all cross-tenant writes; all read tests pass in both versions.

---

## 10. Other Concurrent Rules Changes Identified

Eight collections from three other workstreams (§8). Per project memory, F13.1 additionally spans **three dirty repositories** (Admin, Teacher, Parent) that are intended to ship together, and the Firestore **TTL policy on `vehicleBreadcrumbs.expires_at` is a manual deploy step that `firebase deploy` never creates**.

**No attempt was made to isolate, revert, or cherry-pick any of it.**

---

## 11. Rules Test Results

| Suite | Result |
|---|---|
| `recruitment_sec.test.js` (W1.3) | **23 / 23 PASS** |
| **Full rules suite** (11 files) | **247 / 247 PASS**, 11/11 suites, 48.6 s |

Specifically verified for recruitment:

| Case | Result |
|---|---|
| Tenant A admin → Tenant A job/applicant | ALLOWED ✅ |
| Tenant A admin → Tenant B job/applicant | DENIED ✅ (update, create, delete) |
| Forged `schoolId` field | DENIED ✅ |
| Missing `schoolId` field | DENIED ✅ |
| Unauthorized caller (staff / anon) | DENIED ✅ |
| Valid same-tenant operations | ALLOWED ✅ |

The known `h_lifecycle` flake did not occur this run (17.1 s, passed).

---

## 12. Deployment Decision

> ### **NOT DEPLOYED — rules deployment requires a coordinated deployment window.**

This is Gate 3C's stop condition, and it is triggered by evidence rather than caution:

1. **Eight of the ten changed rule blocks have no rules test at all.** The 247/247 result certifies what is tested; it says nothing about `mapsSecrets`, `vehicleLivePositions`, `vehicleBreadcrumbs`, the three compliance collections, or the two entitlement blocks. Deploying would ship eight uncertified security rules — including one governing **secrets** and two governing **live child location data**.
2. **F13.1 is a three-repo feature.** Its rules would go live while the Teacher and Parent apps remain uncommitted, and without the manual `vehicleBreadcrumbs` TTL policy.
3. `firestore.rules` deploys as a **whole file** — there is no mechanism to ship only the recruitment blocks.

I have not deployed anything and have made no unauthorized deployment decision.

**Exact scope, if you later authorize it:** `firebase deploy --only firestore:rules --project graderadmin` would publish the complete working-tree ruleset — W1.3 recruitment **plus** Phase-1 locations **plus** Student Entitlements **plus** F12.1 **plus** F13 Live GPS. That is the full scope; there is no smaller one.

**Consequence of not deploying, stated plainly:** the cross-tenant write hole on `hrJobs`/`hrApplicants` remains **live in production**. Any tenant admin can currently create, modify and delete another school's job postings and candidate PII via a direct Firestore client. Server-side, the PHP layer scopes every write correctly, so the panel itself cannot do this — the exposure is to direct client SDK access with a valid admin token.

---

## 13. W1.1 Regression Result

**PASS** — entitlement group green within `hrms_p0_verify` (125 PASS / 0 FAIL / 1 SKIP).

## 14. W1.2 Regression Result

**PASS** — money-precision group green within the same run. No payroll arithmetic was touched by this phase.

The single SKIP is environmental, not a regression: the cross-tenant staff-read probe skips because tenant B currently holds no staff records. Nothing in this phase touches staff data.

## 15. Full Test Result

| Suite | Result |
|---|---|
| Unit suite | **192 tests, 549 assertions, 10 failures** — unchanged baseline |
| `hrms_p0_verify` (P0 / W1.1 / W1.2) | 125 PASS / 0 FAIL / 1 SKIP |
| `hrms_w13_verify` (W1.3) | 44 PASS / 0 FAIL / 1 SKIP |
| `hrms_closure_verify` (Gates 1 + 2) | **24 PASS / 0 FAIL / 0 SKIP** |
| Firestore rules (full) | 247 PASS / 247 |
| `php -l` | Clean on every file touched |

## 16. New Failures

**None.** The unit-suite failure count held at exactly 10 before and after this phase. One failure was introduced *in my own harness code* during development (`rbac_granted_level()` returns `null` rather than a level when nothing is granted, which I passed into a typed parameter) and was fixed; no production code was involved.

## 17. Pre-existing Failures

All 10 are pre-existing and unrelated:

| Count | Failure | Cause |
|---|---|---|
| 1 | `BackupTest::size_human_*` | `1 KB` vs `1.0 KB` format drift (documented in CLAUDE.md) |
| 4 | `Homework*` source regressions | read `Homework.php`, untouched |
| 5 | Teacher/Parent cross-repo tests | read Kotlin sources under `D:/Projects/SchoolSync{Parent,Teacher}` |

None are hidden, and none were "fixed" opportunistically.

## 18. RTDB Verification

| Surface | Result |
|---|---|
| Attendance fix | **No RTDB introduced** — diff contains no `firebase->`, `getReference`, `setValue`, `updateChildren` |
| `Ats.php` | **0** RTDB calls (W1.3 result holds) |
| Recruitment repo-wide | Comments only |
| Runtime | RTDB recruitment node still empty; W1.3 E2E confirmed nothing written during a full hire cycle |

## 19. Multi-Tenant Verification

| Requirement | Status |
|---|---|
| No tenant id hardcoded | ✅ grep clean across `Attendance.php` and the closure harness |
| Tenant discovered at runtime | ✅ by name / by query, never by literal |
| Not architected around one tenant | ✅ all logic tenant-agnostic; verification ran across **5 tenants, 103 staff docs** |
| Cross-tenant reads denied | ✅ B5 (real colliding ids), M1/M2, plus 23 rules tests |
| No test data left behind | ✅ closure verification is read-only; W1.3 E2E data deleted and confirmed absent |

## 20. Remaining Risks

| Risk | Severity | Status |
|---|---|---|
| Cross-tenant write on candidate PII **live in production** until rules deploy | **HIGH** | Fixed in code, blocked on a coordinated window (§12) |
| Eight concurrent rule blocks have **no rules tests** | **MEDIUM** | Documented; owned by F12.1/F13/SE, not this phase |
| ATS browser/session/CSRF layer unverified | MEDIUM | Operator action required (§5) |
| Convert-to-Staff never verified end-to-end in a browser | MEDIUM | Highest-value operator check — it has **never** succeeded in production due to the double-prefix defect |
| Device-punch path fixed but not exercised with a real device | LOW–MED | Verified at the defect layer; a real punch would create unrevertable records |
| `get_pipeline()` unbounded fetch | LOW | Pre-existing, disclosed, nothing truncated |

## 21. Files Changed

**Modified (1):**

| File | Change |
|---|---|
| `application/controllers/Attendance.php` | Two `getEntity()` → `get()` corrections + explanatory comments. No logic, architecture or business-rule change. |

**New (2):**

| File | Purpose |
|---|---|
| `application/controllers/Hrms_closure_verify.php` | Read-only runtime verifier for Gates 1 and 2 |
| `ZENX_HRMS_POST_W1_3_CLOSURE_REPORT.md` | This report |

### Files intentionally NOT changed

- `firebase-rules/firestore.rules` — **not deployed**; not otherwise re-edited this phase
- All other sessions' rules work (F12.1, F13, Student Entitlements) — untouched, unreverted
- `Ats.php`, `Hr.php`, the recruitment helpers — W1.3 is complete and was not reopened
- Attendance architecture, business rules, status vocabulary, RBAC, Payroll, money precision, Fees, Transport, Accounting, entitlement architecture
- No W2 code of any kind

---

## 22. Final Recommendation

> ## **BLOCKED_BEFORE_W2**

Gate 1 is fully closed and Gate 3 is closed as a *decision* (reviewed; deployment correctly withheld). Two blockers remain, and **both require you rather than further engineering**:

### Blocker 1 — Rules deployment window · Severity **HIGH**
The cross-tenant write hole on job postings and candidate PII is live in production until `firestore.rules` deploys, and that file cannot ship without also shipping F12.1, F13 Live GPS and Student Entitlements.

*Smallest safe next action:* decide between (a) authorizing the combined deployment after the F13/F12.1/SE owners confirm their blocks are ready — noting the manual `vehicleBreadcrumbs` TTL step — or (b) accepting the documented exposure until F13.1 is ready to ship across all three repos. Adding rules tests for the eight untested blocks would materially de-risk option (a).

### Blocker 2 — ATS operator smoke test · Severity **MEDIUM**
The HTTP/session/CSRF layer and the Convert-to-Staff flow remain unverified, and I cannot close them without forging authentication or creating a real employee.

*Smallest safe next action:* a ~10-minute operator pass on `http://localhost/ZenX/school/ats` — load the board, advance one candidate, and run **one** Convert to Staff. That last step is the highest-value check in the entire phase: because of the double-prefix defect, **hire finalization has never once succeeded in production**, and this is the first build in which it can.

### What flips this to READY_FOR_W2
Blocker 2 passing (or being explicitly waived), plus a decision — not necessarily an execution — on Blocker 1. W2 Compensation Core builds on Staff and Payroll rather than on ATS, so neither blocker is a technical dependency of W2; they are correctness and security obligations that should not be left open while new feature work starts.

---

## Standing Constraints Honoured

- **DO NOT commit / push / merge** — none performed.
- **DO NOT deploy** — nothing deployed; the rules decision was reported before any action, and no action was taken.
- **Do not start W2** — not started.
- **Do not expand scope** — Attendance fix is two lines; unrelated findings documented, not fixed.
- **Do not redesign** Attendance, RBAC, Recruitment, or Payroll — none redesigned.
- **No test-only bypasses, no forged authentication, no hardcoded tenant, no permanent test data.**
