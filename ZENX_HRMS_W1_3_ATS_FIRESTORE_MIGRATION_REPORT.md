# ZENX HRMS — W1.3 IMPLEMENTATION REPORT
## ATS / Recruitment — RTDB → Firestore Migration and HRMS Integration

**Date:** 2026-08-09
**Phase:** W1.3 (W1.1 and W1.2 complete)
**Status:** IMPLEMENTED — verified, **UNCOMMITTED, UNPUSHED, UNDEPLOYED**
**Branch:** `integration/phase2`

---

## 1. Executive Summary

`Ats.php` was the last HR-family controller still bound to the Realtime Database: 26 RTDB calls against `Schools/{school_name}/HR/Recruitment/*`, while `Hr.php` had already moved to the Firestore collections `hrApplicants` and `hrJobs`. The controller's own docblock claimed those paths were "shared with HR" — they were not. The two modules operated on **two disjoint stores that merely looked alike**.

W1.3 pointed ATS at the canonical Firestore collections. All 26 RTDB calls are gone; all 13 routed endpoints are preserved; the five-stage pipeline is unchanged.

**A data migration was NOT required and was NOT performed.** A read-only probe across every tenant found the RTDB recruitment nodes completely empty (0 applicants, 0 jobs), while Firestore held the only real records. This is the "dead/unused RTDB data" branch of the instruction: code paths removed, Firestore certified canonical, legacy RTDB data left untouched.

Four defects were found and fixed along the way. Three were live production faults, not migration artifacts:

| # | Defect | Severity | Evidence |
|---|---|---|---|
| D1 | `finalize_hire()` built a **double tenant prefix** (`{school}_{school}_{staffId}`), so its staff-existence check could never succeed. Every hire failed to finalize *after the employee had already been created* — application stuck at Selected, vacancy never decremented. | **HIGH** | Probe: 103 staff docs, **0** double-prefixed. Reproduced at runtime (test J2). |
| D2 | `_require_admin()` / `_require_view()` fell back to `in_array($this->admin_role, ADMIN_ROLES)`. Privilege escalation for six hardcoded job titles; simultaneously a hard block for custom tenant roles. | **HIGH** | Same bypass class removed across `Hr.php` in P0. |
| D3 | `hrJobs` / `hrApplicants` rules allowed `write: if isAdmin()` — proves the caller is *an* admin, never binds them to the target tenant. An admin of school A could create, modify and **delete** school B's job postings and candidate PII. | **HIGH** | 8 rules tests fail against the pre-fix rule, pass after. |
| D4 | ATS minted applicant ids with a non-atomic RTDB read-modify-write, in a *different store* from HR's counter. Once both wrote `hrApplicants`, concurrent creates could issue the same `APP` id and silently overwrite a candidate. | **MEDIUM** | Now routed through the existing atomic `nextSchoolCounter()`. |

One further cross-platform staleness bug was fixed: `finalize_hire()` updated `positions`/`status` but not the `vacancies`/`statusLower` camelCase mirrors the **Teacher app** queries — so after a hire the app kept advertising filled vacancies, and an auto-closed job stayed listed as open.

Two defects were found **outside** W1.3 scope and are documented, not touched (§27).

---

## 2. Previous ATS Architecture

```
Ats.php  ──26 RTDB calls──►  Schools/{school_name}/HR/Recruitment/Applicants/{APP0001}
                             Schools/{school_name}/HR/Recruitment/Jobs/{JOB0001}
                             Schools/{school_name}/HR/Counters/Applicant

Hr.php   ──Firestore──────►  hrApplicants/{schoolId}_{APP0001}
                             hrJobs/{schoolId}_{JOB0001}
                             systemCounters/{schoolId}_hr_Applicant
```

Consequences of the split, all silent:
- A job posted in HR was invisible on the ATS board.
- A candidate advanced in ATS never appeared in HR's applicant list.
- `Notifications.php`'s recruitment task counters query `hrApplicants` — they never saw ATS's pipeline at all.
- The Teacher app reads `hrJobs`; ATS's job mutations never reached it.

RTDB scoping used `$this->school_name` (a display name) as a path segment, where Firestore uses `$this->school_id`.

---

## 3. RTDB Dependency Map (pre-migration)

26 calls at lines 95, 97, 178, 207, 260, 273, 303, 318, 341, 344, 370, 388, 412, 446, 476, 495, 515, 537, 557, 593, 626, 640, 654, 675, 700, 708.

| Endpoint | RTDB calls | Operation |
|---|---|---|
| `_next_id()` | 95, 97 | get + set (non-atomic counter) |
| `get_pipeline()` | 178, 207 | list applicants, list jobs |
| `get_applicant()` | 260, 273 | get applicant, get job |
| `save_applicant()` | 303, 318, 341, 344 | get job, get existing, set/update |
| `move_stage()` | 370, 388 | get, update |
| `reject_applicant()` | 412, 446 | get, update |
| `add_review()` | 476, 495 | get, update |
| `get_reviews()` | 515 | get |
| `get_convert_data()` | 537, 557 | get applicant, get job |
| `finalize_hire()` | 593, 626, 640, 654 | get, update, get job, update job |
| `get_jobs()` | 675 | list |
| `delete_applicant()` | 700, 708 | get, delete |

Plus one Firestore call (the staff check) — which was itself broken (D1).

---

## 4. Existing Firestore Architecture (reused, not replaced)

`hrApplicants` and `hrJobs` already existed with full supporting infrastructure, so **no new collection was created**:

| Asset | Location |
|---|---|
| CRUD writers | `Hr.php` — `save_job`, `save_applicant`, `update_applicant_status`, `delete_*` |
| Cross-module reader | `Notifications.php` — applicant counts by `status` |
| Cross-platform reader | Teacher app — `Constants.RECRUITMENTS = "hrJobs"`, queried on `statusLower == "open"` |
| Security rules | `firestore.rules` — `match /hrJobs`, `match /hrApplicants` |
| Index | `firestore.indexes.json` — one `hrJobs` composite |
| Atomic counter | `Firestore_service::nextSchoolCounter()`, kind `hr_Applicant` |

---

## 5. Canonical Firestore Data Model

```
hrJobs/{schoolId}_{JOBnnnn}
  schoolId, title, department, department_id, positions, total_positions,
  filled_positions, status, deadline, description, qualifications, ...
  camelCase mirrors (Teacher app): departmentId, vacancies, statusLower,
  salaryRange, closingDate, jobDescription, postedAt

hrApplicants/{schoolId}_{APPnnnn}
  ── identity ──   schoolId, job_id, name, email, phone, qualification, experience
  ── HR-owned ──   status, notes, interview_date, interview_notes, rating, resume_notes
  ── ATS-owned ──  stage, ats_status, reviews[], rejection_reason
  ── hire link ──  staff_id, hired_at
  ── audit ──      applied_date, created_at, updated_at, updated_by
```

Both modules write the same document. Ownership is partitioned by field, and `setEntity(..., merge: true)` means neither clobbers the other's columns. `stage` and `status` are two vocabularies for one fact and are kept consistent by dual-emit (§9).

---

## 6. Exact RTDB Paths / Functions Removed

**`Ats.php`** — deleted outright:
```php
private function _hr(string $sub = '')         // Schools/{school_name}/HR
private function _applicants(string $id = '')  // .../Recruitment/Applicants
private function _jobs(string $id = '')        // .../Recruitment/Jobs
private function _counter(string $type)        // .../Counters/{type}
private function _next_id(...)                 // non-atomic RTDB read-modify-write
const ADMIN_ROLES = [...]                      // the authorization bypass list
const HR_STATUS_MAP = [...]                    // moved to the shared helper
```

**`Hr.php`** — dead RTDB path builders removed (no remaining call sites):
```php
private function _jobs($id = '')        // Schools/{name}/HR/Recruitment/Jobs
private function _applicants($id = '')  // Schools/{name}/HR/Recruitment/Applicants
```

**`Health_check.php`** — check #9 "Open Recruitment Positions" read `{$hrBase}/Recruitment/Jobs` from RTDB. Because nothing had written that node since `Hr.php` moved to Firestore, the check returned a cheerful `PASS: "No recruitment jobs posted"` for **every tenant**, regardless of how many jobs were open or overdue. Now reads `hrJobs`.

---

## 7. Firestore Functions Introduced or Reused

Every ATS data access now goes through a tenant-scoped helper — verified by test, no bare `query()`/`get()` remains:

| Purpose | Call |
|---|---|
| Fetch applicant / job | `$this->fs->getEntity('hrApplicants'\|'hrJobs', $id)` |
| Create / update applicant | `$this->fs->setEntity('hrApplicants', $id, $data)` (merge) |
| Partial update | `$this->fs->updateEntity(...)` |
| Delete | `$this->fs->removeEntity('hrApplicants', $id)` |
| List (schoolId predicate injected) | `$this->fs->schoolWhere('hrApplicants'\|'hrJobs', [])` |
| Applicant id | `hr_next_id($this->fs, 'APP', 'Applicant')` |

**New shared helpers (2 files, both consumed by ATS *and* HR):**

- `application/helpers/recruitment_helper.php` — the stage↔status bridge. Before W1.3 this mapping existed in **four private copies** (two in `Ats.php`, one in `Hr.php`'s dashboard, plus HR's `$validStatuses`), with nothing forcing them to agree.
- `application/helpers/hr_id_helper.php` — `hr_next_id()`, extracted verbatim from `Hr::_next_id()` so both modules advance one atomic counter. `Hr::_next_id()` is now a thin delegate; its nine call sites (DEPT, JOB, APP, LT, LR, PR, APR) are behaviourally unchanged.

---

## 8. Applicant Lifecycle Mapping

```
create ─► Applied ─► Shortlisted ─► Interviewed ─► Selected ─► Hired
             │            │              │             │
             └────────────┴──────────────┴─────────────┘
                            ▼
                        Rejected            (ats_status, orthogonal to stage)
```

`ats_status ∈ {active, rejected, hired, withdrawn}` is deliberately **orthogonal** to `stage`, so a rejected candidate still records how far they got. Consumers must consult `ats_status` before trusting the stage of a rejected applicant.

---

## 9. Recruitment Stage Mapping (unchanged semantics)

Actual repository stage names, preserved verbatim:

| ATS `stage` | HR `status` | Notes |
|---|---|---|
| Applied | Applied | |
| Shortlisted | Shortlisted | |
| Interviewed | **Interview** | spelling differs between modules |
| Selected | Selected | |
| Hired | **Joined** | reachable only via convert-to-staff |
| *(no stage)* | Rejected | carried by `ats_status='rejected'` |

Rules enforced by `_validate_transition()` (unchanged, now runtime-tested across the full 5×5 matrix):
1. Forward only — every backward move rejected.
2. Same-stage move rejected.
3. Strict mode forbids skipping; non-strict permits a forward jump.
4. **`Hired` is unreachable via `move_stage()` from every origin, in both modes** — the money gate.

**Convergence fix.** `Hr::update_applicant_status()` wrote `status` without `stage`. On a now-shared document that would leave a stale stage, and ATS reads `stage ?? status` — so an HR-side change would render the candidate in their old column. Both HR writers now dual-emit `stage` and `ats_status`. `Rejected` is deliberately excluded from the `stage` write, since mapping it would destroy the record of how far the candidate reached.

---

## 10. Applicant → Staff Conversion Mapping

```
Selected applicant
   │  get_convert_data()   → prefill only (Name, email, phone, position, department, …)
   ▼
new_staff form  ──► Staff.php creates the employee   ◄── the ONLY employee master
   │
   ▼  finalize_hire(application_id, staff_id)
   ├─ verify the staff record exists            (READ-ONLY — never creates staff)
   ├─ applicant → stage Hired / status Joined / ats_status hired
   ├─ link staff_id + hired_at, append a hire review
   └─ decrement job positions + vacancies, increment filled_positions,
      auto-close (status Closed + statusLower closed) at zero
```

**ATS creates no staff records.** It only verifies and links. The `staff` collection remains the single employee master.

**D1 fix.** The verification read was `getEntity('staff', "{$this->school_id}_{$staffId}")`, but `getEntity()` already applies `docId()` = `{schoolId}_{id}` — producing `{school}_{school}_{staffId}`. Confirmed against production: 103 staff docs, 0 in that form. So the check failed **every time**, and since it is a hard gate, no hire was ever finalized: the employee existed, the application stayed at Selected, and the vacancy was never decremented. Now `getEntity('staff', $staffId)`, matching `Staff.php`'s own convention.

Ordering is deliberate: the applicant update happens **before** the vacancy decrement, so the applicant document is the idempotency key and a retry after a partial failure is a no-op rather than a second decrement.

---

## 11. Duplicate-Hire / Idempotency Strategy

The browser retries `finalize_hire` after staff creation, so the endpoint must be idempotent. No new identifier was invented — the existing `staff_id` link is the key.

```php
$alreadyHired = ($applicant['ats_status'] ?? '') === 'hired';
$linkedStaff  = trim((string) ($applicant['staff_id'] ?? ''));

if ($alreadyHired && $linkedStaff !== '') { /* short-circuit, no writes */ }
```

The guard requires **both**, not just the flag. `Hr::update_applicant_status()` can set status `Joined` by hand — which now derives `ats_status='hired'` — without ever creating a staff record. Keying on the flag alone would make the endpoint report success for such an applicant and silently skip both the staff link and the vacancy decrement. The stage gate admits that case (`stage !== 'Selected' && !$alreadyHired`) so the missing linking can still complete.

Layered protections:
- `move_stage()` cannot reach `Hired` from any origin.
- A hired candidate cannot be deleted, advanced, or rejected.
- A rejected candidate cannot be advanced (**new** — previously unguarded, so a rejected applicant could be moved forward while still flagged rejected) or converted.

Verified at runtime: repeat call short-circuits, vacancy stays 1 → 1 (test L2).

---

## 12. Document / Storage Integration

**No recruitment document or Storage integration exists.** `resume_notes` is a free-text textarea (`views/ats/index.php:128`); there is no file input, no upload endpoint, no Storage object, and no `documentLinks` reference for applicants anywhere in the recruitment path. (`attachment_url` in `Hr.php` belongs to job-circular creation — a Communication artifact, not an applicant document.)

Therefore: nothing to preserve, nothing to migrate, no duplicate Storage objects created. Adding applicant document support would be a feature redesign — explicitly out of W1.3 scope, and it would touch the **frozen** Canonical Document Service. **Not done.**

---

## 13. RBAC Integration

The graded RBAC system is the only authority. `_require_admin()` / `_require_view()` reduced to:

```php
private function _require_admin() { require_permission('HR', 'ats_manage', 'manage'); }
private function _require_view()  { require_permission('HR', 'ats_view',   'view');   }
```

The removed fallback was a genuine privilege escalation: an operator granted HR **view** only, whose job title happened to match one of six hardcoded strings, silently gained the ability to advance stages, reject candidates and finalize hires. It simultaneously made custom tenant-defined roles unable to manage ATS regardless of granted permissions.

`require_permission()` already emits a JSON 403 for AJAX and a proper access-denied page otherwise, so the failure mode is unchanged for legitimate users. `index()` keeps `_require_role(VIEW_ROLES, 'ats_view', 'HR', 'view')` — the **graded-authoritative bridge**, which resolves names through the RBAC catalogue and is not a bypass.

No new recruitment permission system, and no new capability (§ below): ATS continues to ride the existing `HR` module gate established in W1.1.

---

## 14. Tenant-Isolation Model

Two independent layers:

1. **Document id** — `docId()` = `{schoolId}_{entityId}`, built server-side from the session, never from user input. A caller cannot address another tenant's document even with a correct raw id.
2. **Query predicate** — `schoolWhere()` injects `schoolId ==` before any caller condition. No bare `query()`/`get()` remains in `Ats.php` (test-enforced).

`safe_path_segment()` still validates every inbound id, including `staff_id` (**newly added** — an unvalidated slash would have addressed a different Firestore path).

`_strip_prefix()` strips only *this* tenant's prefix; a foreign-tenant doc id is left intact rather than silently made to look local.

Verified live: another tenant cannot fetch our applicant by bare id, and its listing excludes our documents (tests M1/M2, G1–G3).

---

## 15. Firestore Rules Changes

Both blocks carried an unbound write arm. Changed (`firebase-rules/firestore.rules`):

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

House idiom only (`isSameSchoolWrite()` binds `request.resource.data.schoolId`); no alternative security model. Read arms untouched — the asymmetry is intentional: the Teacher app lists openings, but no app client ever reads applicant PII.

**Proven load-bearing.** Against the pre-fix rule the new suite fails exactly 8 tests, all cross-tenant writes: an admin of school A could update/create/delete school B's jobs *and* applicants, and could write documents with a forged or missing `schoolId`. All read tests pass in both versions, confirming only the write path changed.

> **NOT DEPLOYED.** Per repo policy, rules deploy separately and by explicit authorization. `firestore.rules` is edited concurrently by other sessions and currently also carries F12.1/F13 and Student-Entitlements work — a deploy would ship all of it. `git diff` before any deploy.

---

## 16. Firestore Index Changes

**No new indexes required.**

Both migrated queries are single-field equality with no `orderBy`:
```php
$this->fs->schoolWhere('hrApplicants', []);   // WHERE schoolId == X
$this->fs->schoolWhere('hrJobs', []);         // WHERE schoolId == X
```
Firestore's automatic single-field indexes cover these. Sorting is done in PHP on the already-fetched result set (`usort` on `updated_at`), exactly as before, so no composite index is implied. The existing `hrJobs` composite index is untouched. No speculative indexes added.

---

## 17. Migration / Data-State Analysis

Read-only probe (`scratchpad/probe_ats_data.php`), tenants discovered dynamically — no hardcoded ids:

```
Tenants discovered: 8
TOTALS   RTDB applicants=0  RTDB jobs=0
         FS   applicants=0  FS   jobs=1
>> RTDB recruitment data is EMPTY across every tenant.
```

Answers to the seven required questions:

| Question | Answer |
|---|---|
| 1. RTDB recruitment data exists? | **No** — 0 applicants, 0 jobs, every tenant |
| 2. Equivalent Firestore dataset exists? | Yes — `hrApplicants` / `hrJobs`, live and consumed |
| 3. Firestore dataset complete? | Yes — it is the only populated store |
| 4. Stores divergent? | No — one is empty |
| 5. Migration/backfill required? | **No** |
| 6. Reversible? | N/A — nothing moved |
| 7. Existing migration utility? | N/A |

The only RTDB residue found under any tenant's HR node was `Counters/{Department,LeaveType}` — HR counters superseded by the Firestore counter, and **not recruitment data**. Notably it contains no `Applicant` key, so even ATS's own RTDB counter had never been used.

---

## 18. Was a Data Migration Required?

**No.** This is the instruction's explicit "dead/unused RTDB data" branch. Accordingly:

- RTDB code paths **removed**
- Firestore **certified canonical**
- Legacy RTDB data state **documented** (above)
- **No RTDB data deleted** — historical nodes left exactly as found, pending explicit approval
- **No destructive migration executed**; the STOP-and-report condition was never triggered

---

## 19. RTDB Eradication Verification

Searched for every pattern in the brief — `firebase/database`, `FirebaseDatabase`, `DatabaseReference`, `getReference()`, `child()`, listeners, `setValue()`, `updateChildren()`, `removeValue()`, RTDB URLs, REST calls, Kreait usage, and the project's own `$this->firebase->` wrapper.

| Surface | Result |
|---|---|
| `Ats.php` | **0** RTDB calls (was 26). The only `Schools/` string is a docblock explaining the migration. |
| `Hr.php` recruitment | 0 — dead path builders removed |
| `Health_check.php` #9 | 0 — migrated to `hrJobs` |
| `Notifications.php` | 0 — was already Firestore; stale docblock corrected |
| Repo-wide recruitment grep | Only comments remain |

Runtime re-assertion: the read-only verifier checks the RTDB recruitment node is still empty on every run (F3), and the end-to-end run confirmed **nothing was written to RTDB** during a full hire cycle (S1).

ATS/recruitment: **ZERO RTDB runtime dependencies.** No dual-read, no dual-write, no fallback, no mirror.

---

## 20. Tests Executed

**New unit tests — 22 tests, 109 assertions, all passing:**

- `tests/Unit/RecruitmentVocabularyBridgeTest.php` (10 tests) — **behavioural**, executes the real helper functions. Round-trip identity, injectivity, both-vocabulary reads, unknown-value flooring, `Rejected` handling, `ats_status` derivation, stage order, and a cross-check that `Hr.php`'s `$validStatuses` still matches the helper.
- `tests/Unit/AtsFirestoreCanonicalStoreTest.php` (12 tests) — source regression. No RTDB calls, no RTDB path fragments, every Firestore access tenant-scoped, canonical collections used, no role-name bypass, all mutators gated at `manage`, shared counter, idempotency on the staff link, **the double-prefix regression guard**, all 13 endpoints present, review log bounded, write failures surfaced.

Comments are stripped with `token_get_all()` before matching — the subject file documents the removed RTDB paths and bypass in its own docblocks, and an unstripped match would let documentation satisfy the very assertions meant to guard it.

**Coverage against the required matrix A–V:**

| | Item | Where |
|---|---|---|
| A | Applicant creation | E2E seed + `save_applicant` shape |
| B | Applicant retrieval | E2E A1–A5 (real Firestore) |
| C | Applicant update | E2E pipeline writes |
| D | Search / listing | E2E A4 `_list_jobs`, verifier F |
| E | Stage transition | Verifier A1–A7 (full 5×5), E2E pipeline |
| F | Status transition | Bridge tests, E2E dual-emit |
| G | Rejection | Verifier B, guard tests |
| H | Selection | E2E I1–I2 |
| I | `get_convert_data()` | E2E I (gate), source test |
| J | `finalize_hire()` | E2E J1–J2, K1–K7 |
| K | Applicant → Staff | E2E K3 staff link |
| L | Duplicate hire prevention | E2E L1–L4 |
| M | Tenant isolation | E2E M1–M2, verifier G, rules suite |
| N | Unauthorized access | Rules suite (23 tests) |
| O | Graded RBAC | Source tests + `require_permission` delegation |
| P | Document references | **N/A — none exist (§12)** |
| Q | Firestore query behaviour | Verifier F, E2E read path |
| R | Existing-data compatibility | Verifier B (8 document shapes incl. legacy) |
| S | RTDB-free execution | Verifier F3, E2E S1, source tests |
| T | Error handling | Source test: every writer catches and `json_error`s |
| U | Idempotency / repeat request | E2E L1–L2 |
| V | ATS regression scenarios | 13-endpoint preservation test + full pipeline |

**Rules suite:** `firebase-rules/tests/recruitment_sec.test.js` — **23/23 passing** (new; neither collection had any rules test before).

---

## 21. Runtime Tests Executed

**(a) Permanent read-only verifier — `Hrms_w13_verify.php` — 44 PASS / 0 FAIL / 1 SKIP.**
Executes the real private methods via `newInstanceWithoutConstructor()`: the full transition matrix, stage resolution across 8 real document shapes, tenant-prefix handling, review-log bounding, the vocabulary bridge, live Firestore reachability, and a cross-tenant probe. Zero writes; safe against a live tenant. The skip is honest — no tenant currently has an applicant to probe with.

**(b) End-to-end write test — 40 PASS / 0 FAIL**, on the designated test tenant (discovered **by name**, never hardcoded). Seeded one clearly-marked job and applicant (`ZZ_W13_TEST … (delete me)`), drove the full pipeline, and **deleted everything afterwards** (cleanup verified, and it runs in a `finally` block so it fires even on failure).

Highlights:
```
E Applied → Shortlisted → Interviewed → Selected      (each validated, written, re-read)
E Hired refused via move_stage                        ("only via the Convert to Staff flow")
J1 staff check resolves with a SINGLE tenant prefix
J2 the pre-W1.3 double-prefixed form resolves to nothing   ← D1 reproduced live
K5 vacancy decremented 2 → 1
K6 camelCase mirror kept in step for the Teacher app  (vacancies=1)
L2 repeat call does not decrement the vacancy again   (1 → 1)
M1 another tenant cannot fetch our applicant by bare id
S1 nothing written to the RTDB recruitment node
Z  all harness data removed
```

**Honest scope boundary.** The public endpoints cannot be invoked from CLI — each opens with `require_permission()` (redirects/exits without a session) and closes with `json_success()` (echoes and exits). The harness therefore drove the **real private data-access and rule methods against real Firestore documents**, and issued the exact write payloads the endpoints construct. The HTTP + session + CSRF + RBAC middleware layer was **not** exercised here; that layer is covered by the rules suite and the RBAC source assertions. No results are extrapolated beyond what ran.

The write harness has been **moved out of the repository** (kept in the session scratchpad) — a write-capable, routable test controller is a liability in shipped code. The read-only verifier remains.

---

## 22. Test-Tenant Results

Test tenant used for runtime verification, resolved by name lookup at runtime. Policy compliance:

| Rule | Status |
|---|---|
| Never hardcode the tenant id | ✅ discovered by name; grep confirms no `SCH_*` literal in the W1.3 surface |
| Never design for one tenant | ✅ all logic tenant-agnostic |
| Keep reads/writes tenant-scoped | ✅ `docId()` / `schoolWhere()` throughout |
| Clearly identify test data | ✅ `ZZ_W13_TEST … (delete me)` + `_w13_test: true` |
| Clean up temporary data | ✅ deleted and verified absent |
| Don't alter unrelated production-like data | ✅ linked to an **existing** staff record read-only; no staff document mutated |
| No destructive migration | ✅ none performed |

One irreversible side effect, disclosed: the run advanced the shared `hr_Applicant` counter by 0 (the harness used fixed test ids, not minted ones), and created/deleted two documents. No real recruitment data existed to disturb.

---

## 23. Regression Results

| Suite | Result |
|---|---|
| 1. ATS/recruitment tests | 22/22 new tests pass |
| 2. Staff/HR relevant tests | included in the unit suite below |
| 3. HRMS Phase 1 runtime verifier | **125 PASS / 0 FAIL / 1 SKIP** |
| 4. W1.1 entitlement verification | included above (entitlement group) — pass |
| 5. W1.2 money precision verification | included above (money_precision group) — pass |
| 6. Full unit suite | **192 tests, 549 assertions, 10 failures** |
| 7. Firestore rules suite | 23/23 new recruitment tests pass |
| 8. PHP syntax checks | `php -l` clean on every file touched |
| 9. RTDB audit | §19 — zero |
| 10. Cross-repo (Teacher app) | §28 — verified, and a staleness bug fixed |

The Phase-1 verifier previously reported 126 PASS / 0 FAIL; it now reports 125 PASS / 1 SKIP. The difference is **environmental, not a regression**: the isolation probe skips because tenant B currently has no staff records to probe with. No W1.3 change touches staff.

---

## 24. Pre-existing Failures (baseline, not hidden)

The unit suite's **10 failures are all pre-existing** and unrelated to W1.3. Before W1.3 the suite was 170 tests / 10 failures; it is now 192 tests / 10 failures — the 22 added tests all pass and the failure count is unchanged.

| Count | Failure | Cause |
|---|---|---|
| 1 | `BackupTest::size_human_*` | `1 KB` vs `1.0 KB` format drift (documented in CLAUDE.md) |
| 4 | `Homework*` source regressions | read `Homework.php`, untouched by W1.3 |
| 5 | Teacher/Parent cross-repo tests | read Kotlin sources at `D:/Projects/SchoolSync{Parent,Teacher}` |

CLAUDE.md documents the baseline as "4 failures + 27 skipped"; this machine has the Kotlin repos present, so previously-skipped cross-repo tests now execute and fail on source drift rather than skipping.

---

## 25. New Failures

**None.** Failure count held at exactly 10 across the whole implementation. Two failures I introduced *in my own test code* were found and fixed during development (an eagerly-evaluated assertion message reading an undefined array key; a double-quoted string interpolating `$this->fs`). Neither involved production code.

---

## 26. Files Changed

**Modified (5):**

| File | Change |
|---|---|
| `application/controllers/Ats.php` | Full data-layer rewrite: 26 RTDB calls → Firestore; RBAC bypass removed; idempotency hardened; double-prefix fixed; review log bounded; write failures surfaced; camelCase mirrors kept in sync |
| `application/controllers/Hr.php` | Helper loads; `_next_id()` → shared `hr_next_id()`; third stage-map copy converged; **dual-emit** in `save_applicant()` + `update_applicant_status()`; dead RTDB path builders removed |
| `application/controllers/Health_check.php` | Check #9 RTDB → Firestore `hrJobs` |
| `application/controllers/Notifications.php` | Corrected a docblock and a comment that described behaviour the code never had |
| `firebase-rules/firestore.rules` | `hrJobs` + `hrApplicants` write blocks bound to tenant (**not deployed**) |

**New (6):**

| File | Purpose |
|---|---|
| `application/helpers/recruitment_helper.php` | Stage↔status bridge (shared) |
| `application/helpers/hr_id_helper.php` | Atomic HR id minting (shared) |
| `application/controllers/Hrms_w13_verify.php` | Read-only runtime verifier |
| `tests/Unit/RecruitmentVocabularyBridgeTest.php` | Behavioural bridge tests |
| `tests/Unit/AtsFirestoreCanonicalStoreTest.php` | ATS source regression |
| `firebase-rules/tests/recruitment_sec.test.js` | Recruitment rules tests |

**Not shipped:** the end-to-end write harness (moved to scratchpad) and two read-only probes.

---

## 27. Security Findings

**Fixed in W1.3:**

1. **Privilege escalation (HIGH)** — role-name authorization bypass in both ATS access helpers (§13).
2. **Cross-tenant write (HIGH)** — `hrJobs`/`hrApplicants` rules allowed any admin to create, modify and delete another school's job postings and candidate PII. Proven exploitable by 8 failing tests against the pre-fix rule (§15).
3. **Unvalidated path segment (MEDIUM)** — `staff_id` reached `docId()` without `safe_path_segment()`.
4. **Rejected-candidate advancement (LOW)** — a rejected applicant could be moved forward while still flagged rejected; now blocked, as is converting one to staff.

**Found, documented, NOT fixed — outside W1.3 scope:**

5. **`Attendance.php:3037` (HIGH, fails closed).** The device-punch path uses the same double-prefix form as D1: `getEntity('staff', "{$schoolIdForCheck}_{$personId}")`. Because the canonical id is `{schoolId}_{staffId}` (103/103 docs verified), this lookup can never resolve, and the very next line returns **403 "Staff ID does not belong to this school."** That implies every biometric/RFID/face device punch is rejected, and the P0-5/B1 inactive-staff check below it is unreachable. It fails in the safe direction (denial, not leakage), but it is an availability defect worth urgent triage.
6. **`Attendance.php:3627` (LOW).** Same double prefix in `fetch_individual_report`, so the staff name silently renders blank.

Per "if another issue is discovered, DOCUMENT IT — do not silently expand W1.3," these were not touched. **Recommend a small dedicated fix.**

---

## 28. Performance Findings

- **No N+1 introduced.** `get_pipeline()` issues exactly **two** queries (applicants, jobs) regardless of result size — the same shape as the previous RTDB subtree fetches. `_list_jobs()` is shared by `get_pipeline()` and `get_jobs()` so the projection cannot drift.
- **No migration compatibility layer**, therefore no duplicate reads: single store, single read path.
- **Bounded documents.** The review log is capped at `MAX_REVIEWS = 200`, oldest dropped first. Firestore hard-rejects documents over 1 MiB, so an unbounded append would eventually make a busy applicant *permanently unwritable*, taking stage moves and the hire flow down with it.
- **Known, disclosed limit:** `get_pipeline()` fetches all of a tenant's applicants and sorts in PHP. This is pre-existing behaviour (`Hr.php` does the same) and is *not* a silent cap — nothing is truncated. At a few thousand applicants per tenant it is fine; beyond that it warrants server-side ordering plus pagination, which would need a composite index. **Not changed in W1.3** — it is a redesign, not a migration.
- **Cross-platform correctness fix** (§ below) also avoids the Teacher app repeatedly rendering stale vacancies.

**Cross-platform verification (CROSS-PLATFORM RULE).** The Teacher app reads `hrJobs` (`Constants.RECRUITMENTS`), filters `whereEqualTo("statusLower", "open")`, and renders `job.vacancies`. The original `finalize_hire()` updated `positions`/`filled_positions`/`status` but **neither mirror** — so after a hire the app kept advertising a filled vacancy, and an auto-closed job remained listed as open. Now `vacancies` and `statusLower` are written alongside. Parent app: no recruitment surface — unaffected. **No Kotlin changes required.**

---

## 29. Remaining Risks

| Risk | Severity | Mitigation |
|---|---|---|
| Rules fix **not deployed** — the cross-tenant write hole remains live until it is | **HIGH** | Deploy `firestore.rules`; but the file also carries F12.1/F13 and Student-Entitlements work from other sessions — `git diff` first and deploy in a coordinated window |
| `Attendance.php` double-prefix (§27.5) | HIGH | Documented; needs its own fix |
| `get_pipeline()` unbounded fetch | LOW | Disclosed; revisit with pagination if a tenant grows large |
| HR/ATS field ownership relies on merge semantics | LOW | `setEntity(merge:true)`; `save_applicant()` deliberately omits `reviews` on update so a stale form cannot drop a concurrent review |
| Review-log append is read-modify-write | LOW | Two simultaneous reviews on one applicant could lose one. Pre-existing; bounded blast radius (a review comment, not money or state) |
| Panel writes bypass rules (Admin SDK) | Informational | Unchanged architecture; PHP tenant scoping is the enforcing layer server-side |

---

## 30. Confirmation — No Duplicate ATS Was Created

**Confirmed.** `Ats.php` was migrated in place. No second recruitment controller, no second applicant model, no second pipeline, no parallel ATS. All 13 routed endpoints retain their names, routes, parameters and response shapes — `views/ats/index.php` and `views/new_staff.php` required **zero changes**. The five stages are unchanged; stage semantics were modified only where a concrete correctness defect required it (rejected-candidate advancement), as permitted.

**No duplicate recruitment collection was created.** `hrApplicants` and `hrJobs` already existed and were reused, per REUSE FIRST. Rather than adding models, W1.3 **removed** duplication: four private copies of the stage↔status map collapsed into one shared helper, and two independent id counters collapsed into one atomic counter.

---

## 31. Confirmation — No Second Employee Master Was Created

**Confirmed.** `staff` remains the sole employee master. Grep verifies `Ats.php` contains **no** `saveStaff`, no `setEntity('staff', …)`, and no staff-creation path of any kind. `finalize_hire()` performs exactly one staff operation — a **read** to verify existence — then links `staff_id` onto the applicant. Employee creation remains entirely within `Staff.php`, reached through the existing `new_staff` form. The conversion flow is unchanged:

```
Applicant → (prefill only) → Staff.php creates → finalize_hire links → HRMS Core
```

---

## 32. Confirmation — Firestore Is Now the Canonical ATS Source

**Confirmed.**

| Requirement | Status |
|---|---|
| Recruitment reads → Firestore | ✅ |
| Recruitment writes → Firestore | ✅ |
| Recruitment queries → Firestore | ✅ |
| Recruitment updates → Firestore | ✅ |
| Conversion → Firestore + canonical Staff | ✅ |
| Documents → existing service | N/A — none exist (§12) |
| Authorization → current graded RBAC | ✅ |
| No RTDB fallback / mirror / bridge / dual-read / dual-write | ✅ |

Against the 22 success criteria: **all satisfied**, with criterion 19 (“Firestore rules are correct”) correct **in the file but pending deployment**.

---

## 33. Recommendation for W2

**W1.3 is complete and verified. I have not started W2, per instruction.**

Before W2 (Compensation Core) begins, I recommend three items in this order:

1. **Deploy the rules fix** (or explicitly accept the risk). The cross-tenant write hole on candidate PII is live until deployed. Because `firestore.rules` currently also carries F12.1/F13 and Student-Entitlements changes from other sessions, this needs a coordinated window and a `git diff` review — it is not a solo action for this phase.
2. **Fix `Attendance.php:3037`** (§27.5). If device punches are indeed failing tenant-wide, that is a live operational outage and is a ~2-line fix, but it belongs to Attendance and needs its own verification pass.
3. **Operator smoke test** of the ATS board on `localhost:8080` — the one layer runtime testing could not reach is HTTP + session + CSRF + RBAC middleware. Load `/ats`, add a candidate, advance a stage, and run one convert-to-staff. The convert flow is the highest-value check, since D1 means **it has never successfully completed in production** and this is the first build where it can.

W2 itself is unblocked by W1.3 — recruitment now feeds the canonical Staff master cleanly, which is the prerequisite Compensation Core builds on.

---

## Standing Constraints Honoured

- **DO NOT commit** — nothing committed.
- **DO NOT push** — nothing pushed.
- **DO NOT merge** — nothing merged.
- **DO NOT deploy** — nothing deployed, rules included.
- **W2 must NOT start automatically** — not started.
- No unrelated module changed: Attendance, RBAC, Payroll, money precision, Fees, Transport, Accounting and the HRMS entitlement architecture are all untouched. `Health_check.php` and `Notifications.php` were changed only where they read or documented the recruitment store directly.
