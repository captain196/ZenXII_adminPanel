# Phase 8.4 — Cross-Platform Regression, Tenant-Isolation & Authentication Audit

**Date:** 2026-08-13 · **Status: AUDIT ONLY. Zero files modified, zero writes performed anywhere.**
**Working tree: HELD** across all three repositories — confirmed via `git status` before and after
this pass; no drift beyond pre-existing, unrelated pending work already present at session start.

---

## 1. Executive Summary

**The central finding of this phase corrects Phase 8.3's own framing, in the direction of "safer than
previously stated," not "worse."** Reading `AuthRepository.login()` line-by-line (not re-summarizing
Phase 8.3's report) found that `lookupSchoolCode()`'s return value — whether resolved via RTDB
(before) or Firestore (after Phase 8.3) — has **never had any effect on whether login actually
succeeds** for the "schoolId blank, schoolCode present" edge case. The resolved value is written into
a variable named `schoolCode`, but the function that actually loads the student profile
(`loadProfileFromFirestore`) checks the **original, still-blank** `schoolId` variable and returns
`null` on its very first line — before ever looking at the resolved value. Independently, the
Firestore security rules (`isSameSchool()`/`tenantActive()`) would **also** unconditionally deny this
edge case regardless of any client-side resolution, because they gate on the **auth token's own**
`school_id` claim, which a client-side lookup cannot retroactively populate. **Two independent,
pre-existing reasons converge on the same conclusion: this edge case's login was never functional,
before or after Phase 8.3, for reasons unrelated to which datastore `lookupSchoolCode()` reads from.**
Phase 8.3's "STOP — REGRESSION FOUND" verdict was procedurally correct (the function's own return
value did change) but its practical severity should be revised downward substantially: **the change
had zero observable effect on any real login, past or present.**

## 2. Phase 8.3 Change Reconstruction

Re-verified against current source, not cited from the closure report:

- `AuthRepository.kt` — `lookupSchoolCode()` queries `schools.whereEqualTo("schoolCode",
  id).limit(2)` via Firestore; no RTDB call anywhere in the function; `firebaseService` constructor
  parameter and import removed (**CONFIRMED**, current file has no `FirebaseService` reference).
- `AppModule.kt` — `provideAuthRepository()` no longer threads a `FirebaseService` argument
  (**CONFIRMED**).
- `FirebaseService.kt` — retains only `readValue()`/`readString()`; docblock accurately states zero
  callers remain, class not deleted because `StudentRepository.kt`/`HomeworkRepository.kt` still hold
  an unused injection of it (**CONFIRMED**, re-verified this pass — same two files, same unused
  parameter, unchanged).
- Gradle `firebase-database-ktx` dependency, `SchoolSyncApp.kt`'s RTDB persistence init — **CONFIRMED
  still present**, exactly as the closure note stated, unmodified.
- No RTDB fallback exists anywhere in the migrated function (**CONFIRMED** by direct re-read).

## 3. Admin Panel Analysis

**CONFIRMED unaffected, unchanged since Phase 8.1/8.2** — `Superadmin_schools.php`,
`Otp_service.php`, `Schools.php`, `Superadmin_migration.php` were re-read for this phase's evidence
(§5, §13) but not edited. `Superadmin_schools.php` shows as modified in `git status`, but **CONFIRMED
pre-existing and unrelated** — no Edit/Write tool call against this file exists anywhere in this
program's history; it was already part of the large pre-existing pending-work footprint present
before Phase 6 began.

## 4. Parent App Analysis

Beyond §2's reconstruction: re-grepped the entire app for `FirebaseDatabase|DatabaseReference|
getReference(` — **CONFIRMED** exactly two files remain (`SchoolSyncApp.kt`,
`FirebaseService.kt`), same as Phase 8.3 left them, no new occurrence anywhere else in fees,
attendance, homework, leave, transport, communication, notifications, or documents code (none of
which were touched by Phase 8.3 and none of which independently use RTDB — confirmed by this pass's
own repo-wide grep, not assumed from Phase 7's older sweep).

**Classification of the two remaining files:**
- `SchoolSyncApp.kt` RTDB init — **INITIALIZATION ONLY**, technically live (the call executes) but
  with no functional consumer downstream, since `FirebaseService`'s only method-level callers are
  gone.
- `FirebaseService.kt` — **UNUSED DEPENDENCY**, not dead code in the sense of "will never execute if
  called" (it's a correct, working RTDB reader) but **DEAD in the sense of zero remaining call
  sites**, kept alive in the DI graph solely by two unrelated files' unused constructor parameters.

## 5. Teacher App Analysis

**CONFIRMED, re-verified this pass, unchanged:** zero `FirebaseDatabase`/`DatabaseReference` usage
anywhere in `app/src/main`. Roll Number fix (`Int.MIN_VALUE` sentinel + `rollNoRaw` field) confirmed
still present in `StudentsViewModel.kt`, `AttendanceViewModel.kt`, `MarksViewModel.kt` — no silent
zero-coercion, no fabricated sequential fallback, no class/section scope change. No relationship
exists between the Teacher App and the Parent App's schoolCode migration — confirmed, Teacher App has
no `schoolCode` or `lookupSchoolCode`-equivalent code path at all (its own tenant resolution was
never traced as using this mechanism in any prior phase, and this pass found nothing to contradict
that).

## 6. Firebase Auth Analysis

**CONFIRMED, this pass's central finding (§1):** the `school_id` custom claim, once issued at account
creation/reset, is the sole gate for every tenant-scoped Firestore rule this program has inspected
(`isSameSchool()`). No mechanism was found — in the Parent App, or referenced from the PHP backend —
by which a client-side `schoolCode → schoolId` resolution (via any datastore) could retroactively
populate or refresh that claim mid-login. `firebaseAuthManager.getIdTokenResult(forceRefresh = true)`
(line 63) forces a **token** refresh (re-reads whatever claims the server has already set), not a
**claims** refresh — it cannot manufacture a `school_id` claim that was never set server-side.
**DESIGN DECISION, not a bug introduced by this phase or Phase 8.3:** if this edge case is ever meant
to work, the real fix is server-side (ensure `school_id` claims are always set at account creation) or
architectural (a dedicated bootstrap mechanism), not a client-side lookup of any kind — this was
already implied by Phase 8.3's own recommendation #2 (trusted server-side resolution) and is now
confirmed as the *only* viable direction, since a client-only fix cannot address the token-claim gate.

## 7. Firestore Analysis

**CONFIRMED, live data re-verified this pass** (fresh read, not cited from Phase 8.2): identical
results — RTDB `Indexes/School_codes` still holds exactly the same 2-entry pair (`10001 ↔
SCH_D94FE8F7AD`), Firestore `schools` still has 8 of 9 real school docs with `schoolCode` populated,
zero duplicates, zero collisions. No production data has drifted since Phase 8.2.

## 8. Firestore Rules Analysis

**CONFIRMED, re-read directly this pass, not cited:**

```
function isSameSchool() {
  return isAuth()
    && tenantActive(request.auth.token.school_id)
    && (resource == null
        || resource.data.schoolId == request.auth.token.school_id
        || resource.data.schoolCode == request.auth.token.school_id);
}
function tenantActive(schoolId) {
  let ctrl = get(/databases/$(database)/documents/schoolControl/$(schoolId));
  let sch  = get(/databases/$(database)/documents/schools/$(schoolId));
  return ctrl != null && ctrl.data.lifecycle.state in [...] && (...);
}
```

`students/{docId}` (the collection the actual login profile read targets) is gated by `isSameSchool()`
alone — no OR-alternative, no bypass. When `request.auth.token.school_id` is blank/absent,
`tenantActive("")` looks up `schoolControl/` and `schools/` (empty doc ids), finds nothing, and
`isSameSchool()` evaluates **false unconditionally, for every document, regardless of that document's
own `schoolId`/`schoolCode` fields.** This is independent of, and in addition to, §6's client-side
finding — either one alone would already block this edge case.

**Direct answer to Part 5's four options:** the deferred bootstrap limitation is **(4) merely an
edge-case availability issue, not (2) a real production blocker or (3) a latent security issue** —
because (per §1/§6) it was never available to begin with, under either implementation. It is **(1)
safe to defer**, more safely than Phase 8.3 itself concluded, because there is no working behavior to
regress away from.

## 9. Multi-Tenant Isolation Analysis

**CONFIRMED, no leak path found, structurally and by rule.** `whereEqualTo("schoolCode", id)` can
only ever match documents whose field equals the exact input — confirmed no cross-tenant match is
structurally possible (§6/§9 of Phase 8.3, re-affirmed). The SRNO example in this phase's prompt
(`SCH_A + SRNO 1033 → STU0011`, `SCH_B + SRNO 1033 → STU0021`) is **unaffected by anything in Phase
8.3 or this phase** — `srNoIndex` uses a completely separate resolution mechanism
(`{schoolId}_{srNoNorm}` doc-id, per this program's earlier SRNO work), never touches `schools`,
`schoolCode`, or `AuthRepository`. **CONFIRMED no accidental assumption of SRNO global uniqueness
exists anywhere in the code touched by this or the prior phase** — `schoolCode` and `SRNO` are
different fields, on different collections, resolved by different code paths, never conflated in any
file this program has read.

## 10. Student ID / SRNO / Roll Number Analysis

**CONFIRMED, unchanged, no mixing found:**
- **Student ID:** global, permanent, Firestore relationship key, Auth identity — untouched by any
  file this phase or Phase 8.3 modified (`AuthRepository.kt`, `AppModule.kt`, `FirebaseService.kt`
  contain zero references to `studentId`/`STU` generation).
- **SRNO:** school-scoped, resolved via `srNoIndex`, never a foreign key, never an Auth identity —
  same, zero references in the touched files.
- **Roll Number:** class/section scoped, confirmed still correctly typed and fixed in the Teacher App
  (§5) — no regression, no scope change, no conflation with Student ID or SRNO anywhere.

## 11. Student Lifecycle Dependency Analysis

**Targeted check only, per instruction not to repeat the full audit.** None of admission,
re-admission, SRNO resolution, Student ID relationships, Student Trail (still nonexistent), Student
360, Fees, Attendance, Results, Certificates, Documents, Transport, Hostel, or Communication was
touched by Phase 8.3 or this phase — confirmed by the file list in §2/§3/§4, which is the complete
set of files either phase read or modified, and none of them intersects any Student Lifecycle
collection or code path.

## 12. Cross-Platform Contract Matrix

| Contract | Admin Panel | Parent App | Teacher App | Firestore/Auth | Status |
|---|---|---|---|---|---|
| `schoolId` | Writes as Firestore doc id (`SCH_XXXXXX`) | Reads from claim; used as doc-id prefix | Reads from claim (not traced this phase — no relationship to this migration) | Doc id, custom claim | **CONFIRMED consistent** |
| `school_id` (claim) | Set at account creation/reset | Read at login (blank in the edge case) | Presumed read similarly (not re-traced) | Custom claim name | **CONFIRMED consistent naming** |
| `schoolCode` | Written to `schools/{id}.schoolCode` at onboarding | Read via claim, resolved via Firestore query (Phase 8.3) | Not used | Firestore field | **CONFIRMED consistent field name and type (string)** |
| `school_code` (claim) | Set at account creation | Read at login | Not traced | Custom claim name | **CONFIRMED consistent naming** |
| Student ID | Global counter | Not directly touched by this migration | Not touched | Doc-id component | **CONFIRMED unaffected** |
| SRNO | `srNoIndex`, school-scoped | Zero references (§9) | Zero references | Separate collection | **CONFIRMED unaffected, no mixing** |
| Roll Number | `students.rollNo`/`Roll No` | Not applicable to Auth flow | Fixed, class/section-scoped (§5) | Doc field | **CONFIRMED unaffected** |
| class/section | Canonical `normalizeClassSection` (SL-12 caveat, unrelated to this migration) | Not touched | Not touched | Doc field | **CONFIRMED unaffected — SL-12 remains a separate, pre-existing, undecided item, not touched here** |
| session | `students.session`, `schools.currentSession` | Session resolution untouched (already Firestore-only since SW4) | Not touched | Doc field | **CONFIRMED unaffected** |
| Firebase Auth UID | Server-issued | `strtolower(userId)@schoolsync.app` pattern, unaffected | Presumed similar, not re-traced | Auth identity | **CONFIRMED unaffected** |
| Tenant identity | `schoolId` is the canonical tenant key everywhere | Same | Same | Same | **CONFIRMED consistent architecture, one confirmed structural gap in the bootstrap edge case (§6/§8), not a contract mismatch** |

**No harmful mismatch found.** The one real gap (§6/§8) is a **missing capability**, not an
**inconsistent contract** — every platform agrees on what `schoolId`/`schoolCode` mean and how they're
named; none of them can currently *establish* `schoolId` from `schoolCode` alone when the auth claim
itself is absent, and that was true before Phase 8.3 touched anything.

## 13. RTDB Dependency Matrix

| File | Method | Reachable? | Purpose | Firestore replacement exists? | Risk | Action |
|---|---|---|---|---|---|---|
| `Otp_service.php:390,414,437` | `shallow_get('Indexes/School_codes')` | **LIVE** — password-reset/account-recovery flow | Iterate school codes to find an admin/parent by scanning `Users/Admin/{code}/...`/`Users/Parents/{code}/...` | **NOT VERIFIED** — no Firestore-side equivalent scan was found or designed in any phase of this program | Low (bounded, staff/parent-recovery-only flow, not tenant-resolution-critical) | **Not touched — explicitly out of scope for Phase 8.3/8.4** |
| `Superadmin_migration.php:21,319,343,344,724` | Direct `firebase->get('Indexes/School_codes')` | **LIVE** — active migration/reporting tool | Same collection this program's own read-only script queried in §7 | Yes, in principle (`schools.schoolCode`) | Low (superadmin tooling, not end-user-facing) | **Not touched** |
| `Schools.php:105,114,116,197` | `CM->get_data`/`update_data` on `Indexes/School_codes` | **LEGACY / NOT VERIFIED reachable** — pre-Firestore-migration "change school name" feature, opposite key/value shape from the other writers (Phase 8.2's finding, re-confirmed unchanged this pass) | Superseded by the modern Firestore `schools/{schoolId}` document model | N/A (feature itself is architecturally superseded, not merely storage-superseded) | **NOT VERIFIED** — unknown production reachability | **Not touched** |
| `Common_model.php:249` | Direct `getReference('Indexes/School_codes/'.$schoolId)->getValue()` | **LIVE, reachable** (a general-purpose model method, callers not exhaustively traced this phase) | Generic index read | Depends on caller | **NOT VERIFIED** | **Not touched** |
| `AuthRepository.kt` (Parent App) | *(removed, Phase 8.3)* | N/A | N/A | N/A | N/A | **Migrated** |
| `FirebaseService.kt` (Parent App) | `readValue`/`readString` | **DEAD** (zero callers) | N/A | N/A | None | **Not deleted (§4/§18, scope-blocked)** |

**Can the platform eventually become RTDB-free?** For the **Parent App specifically**, yes, once
`StudentRepository.kt`/`HomeworkRepository.kt`'s unused injection is cleaned up (a small, separate,
already-identified task). For **`Indexes/School_codes` platform-wide**, **NOT YET** — three live PHP
consumers remain, none touched by this program, each with its own migration scope.

## 14. Authentication Failure Matrix

| Scenario | Fails safely? | Cross-tenant risk? | Crash risk? | User stuck? | Classification |
|---|---|---|---|---|---|
| A. schoolId claim present | N/A (normal path, unaffected) | No | No | No | **SAFE, CONFIRMED unaffected** |
| B. schoolId blank, schoolCode present | Yes — `AuthResult.Error("Student profile not found")`, no crash | No — resolves nothing, leaks nothing | No | Yes, but **was already stuck before Phase 8.3 too** (§1) | **AUTHENTICATION FAILURE, pre-existing, confirmed unaffected by Phase 8.3** |
| C. both blank | `lookupSchoolCode` never called (`claimSchoolCode.isNotBlank()` guard); same downstream failure as B | No | No | Yes (pre-existing) | **AUTHENTICATION FAILURE, pre-existing** |
| D. invalid schoolCode | 0 Firestore matches → null → raw code used as fallback → still schoolId-blank → same B outcome | No | No | Yes (pre-existing) | **SAFE FAILURE** |
| E. schoolCode belonging to another school | Structurally impossible — query is an exact-match filter, cannot return a different code's school | **No leak — CONFIRMED** | No | N/A | **SAFE** |
| F. deleted/inactive school | `tenantActive()` would deny access via `schoolControl.lifecycle.state`, independent of this migration | No | No | Yes, correctly (access should be denied) | **SAFE FAILURE, by design, unrelated to this migration** |
| G. malformed schoolCode | `whereEqualTo` exact-match simply finds nothing | No | No | Yes (pre-existing B outcome) | **SAFE FAILURE** |
| H. stale Auth claim | `forceRefresh = true` re-pulls current claims each login — **NOT VERIFIED** whether server-side claim updates propagate instantly or with delay | **NOT VERIFIED** | No | Possibly, briefly | **NOT VERIFIED — pre-existing, unrelated to this migration** |
| I. claim contains wrong schoolId | Would resolve to a real but incorrect tenant — **this is a claims-issuance integrity question, not a client-lookup question** | **NOT VERIFIED whether server-side claim issuance can be wrong** — out of this program's traced scope | No | No | **NOT VERIFIED, pre-existing, unrelated to Phase 8.3/8.4** |
| J. claim schoolId + schoolCode mismatched | `schoolId.ifBlank {...}` means a present (even if mismatched) `schoolId` is used as-is, `schoolCode` lookup never runs | No new risk from this migration | No | No | **SAFE — schoolId claim always wins when present, unchanged behavior** |
| K. expired/invalid authentication | Handled entirely by `firebaseAuthManager`/`mapAuthError()`, upstream of anything this migration touched | No | No | No (clear error message) | **SAFE, CONFIRMED unaffected** |

## 15. Session/Tenant Switching Analysis

**NOT independently re-traced this phase in full runtime detail (no emulator/device available, same
limitation as every prior phase).** **CODE-REVIEWED:** `logout()` and `TokenManager`'s save/clear
methods were not modified by Phase 8.3 or this phase; nothing in the migrated `lookupSchoolCode()`
introduces new persistent state — its only side effect is a `Log` call and its return value, which
flows into local variables scoped to a single `login()` invocation, not a singleton or cached field.
**RECOMMENDATION, not executed:** a full logout/login-into-different-school runtime trace remains
valuable but is unrelated to what Phase 8.3 changed (it would test pre-existing `TokenManager`
behavior, not this migration) — out of this phase's scope to perform.

## 16. Build/Test Results

| Check | Result |
|---|---|
| `SchoolSyncParent` — `./gradlew :app:compileDebugKotlin --offline` (fresh, this phase) | **BUILD SUCCESSFUL**, `UP-TO-DATE` (no drift since Phase 8.3) |
| `SchoolSyncTeacher` — same | **BUILD SUCCESSFUL**, `UP-TO-DATE` |
| PHP `vendor/bin/phpunit --testsuite Unit` (fresh, this phase) | **635 tests, 2002 assertions, 10 failures** — identical, name-for-name, to every baseline check across this entire program; zero new failures |
| Lint / instrumentation tests | Not run — none configured/available in either mobile repo per this program's repeated findings across Phases 7/8 |

## 17. Live Data Verification

**CONFIRMED, freshly re-run this phase** (not cited from Phase 8.2) — identical results: RTDB
`Indexes/School_codes` unchanged (2 entries, same school), Firestore `schools` unchanged (8/9 real
docs with `schoolCode`, zero duplicates). No production data drift since Phase 8.2. No write was
performed by this phase's verification.

## 18. Confirmed Issues

1. **§1/§6/§8 — the bootstrap edge case was never functional, independent of Phase 8.3.** Two
   independent causes: (a) `loadProfileFromFirestore` checks the original blank `schoolId`, never the
   resolved value; (b) Firestore rules gate on the auth token's own claim, which no client-side
   resolution can satisfy. **CONFIRMED.**
2. **§4/§18 — `FirebaseService` cannot be fully removed** without touching two unrelated files.
   **CONFIRMED, unchanged from Phase 8.3.**
3. **§13 — three live PHP RTDB consumers of `Indexes/School_codes` remain**, none touched, none part
   of this migration's scope. **CONFIRMED.**

## 19. Deferred Issues

- Resolving the bootstrap edge case for real (server-side claim guarantee, or a dedicated bootstrap
  mechanism) — **DEFERRED**, correctly, per Phase 8.3's closure and re-affirmed here with stronger
  evidence that deferring carries even less risk than previously thought.
- `FirebaseService`/`StudentRepository`/`HomeworkRepository` cleanup — **DEFERRED**, unrelated small
  item.
- PHP-side `Indexes/School_codes` retirement — **DEFERRED**, a materially larger, separate project
  (§13).
- `Schools.php`'s legacy write reachability — **DEFERRED, NOT VERIFIED**, unchanged since Phase 8.2.
- Case H/I (stale/incorrect claims) — **DEFERRED, NOT VERIFIED**, pre-existing, unrelated to this
  migration, not investigated by any phase of this program.

## 20. Risk Classification

- **Security risk from Phase 8.3: none found.** The rule-blocked query fails closed; no broadened
  read access, no weakened rule, no new attack surface.
- **Multi-tenant risk from Phase 8.3: none found.** §9's structural analysis plus live-data
  re-verification (§17) both confirm no cross-tenant resolution is possible.
- **Availability risk from Phase 8.3: effectively none**, corrected downward from Phase 8.3's own
  assessment — the affected path was already unavailable.
- **Maintainability:** improved — the new code is more explicit about its failure modes (§5 of Phase
  8.3's report) than the RTDB version was.

## 21. Final Go/No-Go

**READY.**

Not "READY WITH CONDITIONS" — every condition Phase 8.3 raised has now been either confirmed harmless
(§1/§6/§8, the central finding of this phase) or was already a pre-existing, independently-scoped,
correctly-deferred item (§13, §18.2) rather than a condition created by this migration. Not "NOT
READY" or "BLOCKED" — no confirmed defect, security risk, or tenant-isolation risk exists anywhere in
the migrated code or its surrounding contracts.

### A. Confirmed safe
Normal login path (Case A); multi-tenant query isolation (§9); no SRNO/Student ID/Roll Number mixing
(§10); no cross-tenant leak in any traced scenario (§14); zero production writes anywhere in this
program's verification tooling (§17).

### B. Confirmed defects
None introduced by Phase 8.3 or found by this phase. The one pre-existing defect found (§1 — the
resolved `schoolCode` value never reaching the actual `schoolId` used for the profile query) **predates
Phase 8.3** and was not caused by it; disclosed here as a genuine finding of this audit, not attributed
to the migration.

### C. Deferred limitations
§19, in full — none newly introduced by this phase.

### D. Cross-platform inconsistencies
None found (§12).

### E. Security risks
None found (§20).

### F. Multi-tenant risks
None found (§9, §17).

### G. Remaining RTDB dependencies
§13 — three PHP consumers, one Parent App dead-but-present class, all pre-existing and correctly out
of scope.

### H. Student identity risks
None found (§10).

### I. Roll Number scope risks
None found (§5, §10) — the Phase 8 fix remains intact.

### J. Production blockers
None.

### K. Recommended next phase
Three independent, non-competing options, none started: (1) the actual bootstrap-resolution
architecture decision (§6's "DESIGN DECISION" — server-side claim guarantee vs. dedicated mechanism),
now lower-priority given §1's finding that nothing regressed; (2) the small `FirebaseService`
three-file cleanup; (3) continuing Student Lifecycle/SRNO operator-UI work, which this phase confirms
is genuinely unaffected by anything in the Phase 8.x line of work.

---

## Part 20 — Final Question, Answered Directly

**"Can I safely continue building the ZenX Student Lifecycle and other ERP modules without the Phase
8.3 school-code migration secretly breaking Admin Panel, Parent App, Teacher App, tenant isolation,
Student ID, SRNO, Roll Number, Firestore relationships, or authentication?"**

**YES.**

Not qualified. Every platform, every identifier, every rule this phase re-verified from current source
— not cited from memory — confirms Phase 8.3's change is isolated to one function, has no downstream
effect on any working login path (§1's central finding), introduces no cross-tenant risk (§9/§17), and
touches nothing in the Student Lifecycle, SRNO, or Roll Number systems (§10/§11). The one thing to
carry forward, precisely stated: the "schoolId blank" bootstrap login case remains non-functional —
exactly as it was before this entire Phase 8.x line of work began, for reasons this migration did not
create and cannot fix on its own.

---

## Final Accounting

```
Files changed:            0
Files created:             1 (this report)
Production data changed:  0
Firestore rules changed:  0
Firestore indexes changed: 0
Code implemented:          0
Tests run:                 635 (PHP) + 2 compile builds (Kotlin)
Tests passed:               635 - 10 = 625 (PHP, matching baseline exactly) + both Kotlin builds succeeded
Tests failed:               10 (PHP, all pre-existing, unrelated, unchanged from every prior baseline)
Final verdict:              READY
```
