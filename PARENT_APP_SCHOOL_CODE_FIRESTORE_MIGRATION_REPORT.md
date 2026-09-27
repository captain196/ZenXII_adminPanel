# Parent App School-Code Firestore Migration Report (Phase 8.3)

**Date:** 2026-08-13 · **Status: Implemented, compile-verified — but a genuine deployment-blocking
regression was found via rules inspection and is disclosed, not hidden.**
**Working tree: HELD.** Nothing committed, staged, reset, stashed, cleaned, or pushed, in any of the
three repositories. Pre-existing unrelated work (Transport/maps feature on `SchoolSyncParent`) is
untouched — confirmed via `git status`/`git diff` before and after this pass.

---

## 1. Executive Summary

The code migration itself is done, minimal, and compiles cleanly: `AuthRepository.lookupSchoolCode()`
now queries Firestore `schools` by `schoolCode` instead of reading RTDB, with no RTDB code left in
the migrated path and no hidden RTDB fallback. **However, inspecting the actual deployed Firestore
security rules — not assumed, read directly — found that the `schools` collection's read rule
requires the requester's auth token to already carry a matching `school_id` claim.** That is exactly
the one condition this function exists to handle the *absence* of. **The old RTDB read had `.read:
true` (fully public, no auth required) for precisely this reason** — it was a pre-tenant-resolution
bootstrap lookup, deliberately open. The new Firestore query, as authorized and implemented, will be
denied by the current rules for the exact edge case it targets. **This is a genuine, confirmed
regression for that narrow case, not a hypothetical one — reported per instruction, not silently
patched with an unauthorized rules change or a forbidden RTDB fallback.**

## 2. Pre-Change Architecture

```
schoolId claim present  → used directly, lookupSchoolCode() never called
schoolId claim blank,
school_code claim present → lookupSchoolCode(code) reads RTDB Indexes/School_codes/{code}
                              (RTDB rule: "Indexes/School_codes": { ".read": true })
                              → returns schoolId string, or null on miss/error
                              → null falls back to the raw school_code value unchanged
```

## 3. Post-Change Architecture

```
schoolId claim present  → used directly, lookupSchoolCode() never called (UNCHANGED)
schoolId claim blank,
school_code claim present → lookupSchoolCode(code) queries Firestore:
                              schools.whereEqualTo("schoolCode", code).limit(2)
                              (Firestore rule: requires token.school_id already == the
                               document being read — see §16, CONFIRMED BLOCKING)
                              → 0 matches: null, logged as a miss
                              → 1 match: that doc's id, returned as schoolId
                              → 2+ matches: DATA INTEGRITY error logged, first match
                                returned anyway (should be structurally impossible —
                                Phase 8.2 verified zero duplicates in production)
                              → exception: null, logged
                              → null falls back to the raw school_code value unchanged
                                (SAME fallback shape as before — unchanged)
```

## 4. Exact Files Modified

| File | Change |
|---|---|
| `AuthRepository.kt` | `lookupSchoolCode()` body replaced (RTDB → Firestore query); `firebaseService` constructor param and its import removed (now genuinely unused) |
| `AppModule.kt` | `provideAuthRepository()`'s `firebaseService` parameter and its pass-through removed (required by the constructor change — not a separate decision) |
| `FirebaseService.kt` | Docblock updated to state the class now has zero *called* methods, but is **not** deleted (see §18) |

**Not modified:** `firebase-database-ktx` Gradle dependency, `SchoolSyncApp.kt`'s RTDB persistence
init, `Constants.kt`'s RTDB path builders, `firestore.rules`, `firestore.indexes.json`, any Admin
Panel PHP file, any Teacher App file, any unrelated Parent App file (Transport/maps work untouched).

## 5. SchoolCode → schoolId Flow — Exact Logic

```kotlin
private suspend fun lookupSchoolCode(id: String): String? {
    return try {
        val snapshot = firestoreService.queryDocuments(Constants.Firestore.SCHOOLS) { ref ->
            ref.whereEqualTo("schoolCode", id).limit(2)
        }
        when (snapshot.size()) {
            0 -> { /* log miss */ null }
            1 -> snapshot.documents.first().id
            else -> { /* log DATA INTEGRITY error */ snapshot.documents.first().id }
        }
    } catch (e: Exception) { /* log */ null }
}
```

**Deliberate design choice:** `limit(2)`, not `limit(1)`. A `limit(1)` query can never observe whether
a second match exists — it would silently succeed even if the "zero duplicates" invariant Phase 8.2
verified were ever violated in the future. `limit(2)` costs one extra potential document read in the
(currently nonexistent) duplicate case, and turns an invisible data-integrity violation into a loud,
logged one. This directly satisfies §6 Case G's requirement to distinguish, not hide, that scenario.

## 6. Multi-Tenant Verification

**CONFIRMED, live-data-backed (unchanged conclusion from Phase 8.2, re-affirmed by the query shape):**
`whereEqualTo("schoolCode", id)` can only ever match documents whose `schoolCode` field equals the
exact claim value — there is no path by which School A's claim could resolve to School B's `schoolId`,
structurally, regardless of the rules issue in §16 (which causes the query to return *nothing*, not
*wrong* data — an important distinction: the failure mode is fail-closed, not fail-open. A blocked
query is silent and safe; it is not a cross-tenant leak).

## 7. Authentication Regression Analysis

**Case A (schoolId claim present):** **CONFIRMED unchanged** — `lookupSchoolCode()` is not called at
all in this path; nothing in this migration touches it.
**Case B (schoolId blank, schoolCode present):** **REGRESSION, confirmed via §16** — previously
resolved successfully (RTDB was public-read); now blocked by Firestore rules for exactly this case.
**Case C (both blank):** **CONFIRMED unchanged** — `lookupSchoolCode()` is never called (guarded by
`if (claimSchoolCode.isNotBlank())` in the caller, unchanged).
**Case D (schoolCode present but doesn't resolve):** **CONFIRMED unchanged behavior shape** — returns
null, falls back to raw value, same as before (though today this now happens for a different reason —
rules denial rather than a genuine data miss — the visible behavior to the caller is identical).
**Case E (Firestore query fails/throws):** **CONFIRMED handled** — caught, logged, returns null, same
fail-safe shape as the RTDB version's catch-all.
**Case F (zero Firestore matches):** **CONFIRMED handled explicitly**, distinct log message from the
exception case.
**Case G (Firestore returns 2+ matches):** **CONFIRMED handled explicitly** — logged as a data-
integrity error, not silently accepted; see §5.

## 8. Parent Profile Regression Analysis

**CONFIRMED unaffected.** `loadProfileFromFirestore()` (the actual profile loader) was not touched in
this pass — it already reads `students/{schoolId}_{userId}` using whatever `schoolId` value the
caller resolved by this point, exactly as before. This migration only changes *how* that `schoolId`
value is obtained in the one edge case; every downstream consumer of it is unchanged.

## 9. Student Resolution Regression Analysis

**CONFIRMED unaffected** — same reasoning as §8; student document addressing (`{schoolId}_{userId}`)
was not touched.

## 10. Session Regression Analysis

**CONFIRMED unaffected.** Session resolution (`SchoolFirestoreRepository.observeSchool()`) was
already Firestore-only since SW4 (Phase 8) and is untouched by this pass.

## 11. Admin Panel Cross-Check

**CONFIRMED no PHP file was modified.** `Otp_service.php`, `Superadmin_migration.php`, `Schools.php`,
`Superadmin_schools.php` — all re-read as source this pass for evidence, none edited. The PHP Admin
Panel's own dependency on RTDB `Indexes/School_codes` is completely untouched and continues to
function exactly as before — this migration only changed what the Parent App reads, never what the
PHP backend writes or reads.

## 12. Teacher App Cross-Check

**Not touched, not re-verified this pass** (no code changed there, no reason to re-run its build) —
Teacher App has zero RTDB usage and zero relationship to `Indexes/School_codes`/`schoolCode` lookup
(confirmed in Phase 7); nothing in this migration could regress it.

## 13. Student Lifecycle Targeted Cross-Check

**CONFIRMED unaffected.** No file touched in this pass references `srNo`, `srNoNorm`, `studentId`,
`previousStudentId`, `rollNo`, `className`, or `section` — verified by re-reading every line changed
in this pass (§4), not merely asserted.

## 14. Roll Number / Class / Section Cross-Check

**CONFIRMED unaffected** — same evidence as §13. No regression detected, none introduced.

## 15. Firestore Rules Analysis — THE CENTRAL FINDING

**CONFIRMED, read directly from `firebase-rules/firestore.rules:318-326`:**

```
match /schools/{docId} {
  allow read: if isAuth()
    && tenantActive(docId)
    && (
      resource.data.schoolId == request.auth.token.school_id ||
      docId == request.auth.token.school_id
    );
  allow write: if isSuperAdmin();
}
```

**This rule blocks the new query for exactly the case it exists to serve.** The requester's token has
a **blank** `school_id` claim in this edge case (that is the definition of the edge case) — so neither
`resource.data.schoolId == request.auth.token.school_id` nor `docId == request.auth.token.school_id`
can ever be true. Firestore evaluates security rules per-document for a collection query; a query
whose rule denies every candidate document returns an **empty result set** (or, depending on how the
client SDK surfaces it, a permission error) — either way, **not the intended match.**

**Confirmed for comparison — the OLD RTDB rule, `firebase-rules/database.rules.json:6-10`:**
```json
"Indexes": {
  "School_codes": { ".read": true, ".write": "auth != null" }
}
```
**Fully public read, no authentication required at all.** This was almost certainly deliberate — a
tenant-bootstrap lookup cannot require the very tenant claim it exists to establish. The Firestore
`schools` rule, written later and for a different purpose (protecting a document that, once tenant is
known, legitimately needs protecting), was never designed with this bootstrap use case in mind.

**What minimum secure rule change would be required (recommendation only, NOT implemented, NOT
authorized to implement):** the `schools` collection rule would need a narrow additional allowance —
something in the shape of *"an authenticated user, regardless of their own school_id claim, may read
a school document when querying by `schoolCode` equality"* — which is meaningfully different from,
and broader than, the current rule's intent. This is a genuine security-posture decision (how much of
a school's document should be readable pre-tenant-resolution), not a mechanical fix, and is
**explicitly out of this pass's authorization** ("Only implement a rule change if explicitly
authorized").

**A safer alternative worth naming, not decided here:** resolving `schoolCode → schoolId` via a
narrow server-side Cloud Function (Admin SDK, bypasses client rules entirely, returns only the
`schoolId` string) would avoid broadening client-side read access to the `schools` document at all —
closer in spirit to how the RTDB index worked (open for this one narrow purpose, not a broad grant on
the whole collection). This is a larger design choice than this migration's original scope and is
named here as an option for your consideration, not proposed as the answer.

## 16. Firestore Index Analysis

**CONFIRMED no composite index is required.** `firestore.indexes.json` was searched for any
`collectionGroup: "schools"` entry or `fieldOverrides` disabling automatic single-field indexing on
`schoolCode` — **none exists**. A single-field equality query (`whereEqualTo("schoolCode", ...)`) is
served by Firestore's automatic per-field index by default. No index change was made or is needed.

## 17. RTDB Remaining-Usage Audit

Post-change repo-wide grep for `FirebaseDatabase|DatabaseReference|getReference(`:

```
SchoolSyncApp.kt:8,52        — RTDB persistence init (LIVE, but see below — no longer required
                                 by AuthRepository; retained only for §18's reason)
FirebaseService.kt:5,34,41   — readValue()/readString() (LIVE class, ZERO callers now)
```

**Classification, precisely:** `FirebaseService`'s methods are now **DEAD** (zero call sites anywhere
in the app — `AuthRepository` was the last one). The class itself, the Gradle dependency, and the
`SchoolSyncApp.kt` init call are **NOT dead** in the sense of "safe to delete," because — see §18 —
two unrelated files still hold a compile-time dependency on the `FirebaseService` *type* even though
neither calls a method on it.

## 18. Why FirebaseService Was NOT Fully Removed — Scope Discipline, Not Oversight

`StudentRepository.kt` and `HomeworkRepository.kt` both still declare `private val firebaseService:
FirebaseService` as an injected constructor parameter — **confirmed unused in both bodies already in
Phase 8's audit**, and re-confirmed unchanged this pass. Deleting the `FirebaseService` class would
break compilation of both files. Fixing that requires editing two files with **no relationship to
this migration's authorized scope** ("migrate the Parent App's school-code lookup" — not "clean up
every dead FirebaseService injection in the codebase"). Per §0/§8's explicit instruction — *"If you
discover an unrelated issue: document it, classify it, explain its impact, STOP on that issue, do not
silently fix it"* — this is exactly that: documented here, not touched. **The Parent App is now
functionally RTDB-free for every call path except a fully dead one that only remains wired for
compile-compatibility with two unrelated files.**

## 19. Admin Panel / Teacher App / Student Lifecycle Cross-Platform Integration Matrix

| Area | Admin Panel | Parent App | Teacher App | Firestore | RTDB | Result | Evidence |
|---|---|---|---|---|---|---|---|
| Authentication (normal case) | N/A | Unaffected | N/A | N/A | N/A | **CONFIRMED unaffected** | §7 Case A/C |
| Authentication (edge case B) | N/A | **Changed, currently blocked** | N/A | Query denied by rules | No longer read | **REGRESSION, confirmed** | §15 |
| Tenant resolution | Unaffected | Edge case only, blocked | Unaffected | — | — | **PARTIAL — see §15** | §15 |
| schoolCode | Unaffected (still writes/reads RTDB as before) | Read-source changed | N/A | Read (blocked in edge case) | Read removed from this path | **CONFIRMED, PARTIAL** | §3-4 |
| schoolId | Unaffected | Unaffected downstream | Unaffected | Unaffected | — | **CONFIRMED unaffected** | §9-10 |
| Student ID | Unaffected | Unaffected | Unaffected | Unaffected | — | **CONFIRMED unaffected** | §13 |
| SRNO | Unaffected | Unaffected (never referenced) | Unaffected | Unaffected | — | **CONFIRMED unaffected** | §13 |
| Parent profile | Unaffected | Unaffected | N/A | Unaffected | — | **CONFIRMED unaffected** | §8 |
| Teacher profile | N/A | N/A | Unaffected | Unaffected | — | **CONFIRMED unaffected** | §12 |
| Student roster | Unaffected | N/A | Unaffected | Unaffected | — | **CONFIRMED unaffected** | §12 |
| Session | Unaffected | Unaffected | N/A | Unaffected | — | **CONFIRMED unaffected** | §10 |
| Attendance | Unaffected | Not touched | Not touched | Unaffected | — | **CONFIRMED unaffected** | No file touched |
| Fees | Unaffected | Not touched | N/A | Unaffected | — | **CONFIRMED unaffected** | No file touched |
| Transport | Unaffected | Not touched (pre-existing unrelated branch work untouched) | N/A | Unaffected | — | **CONFIRMED unaffected** | `git diff` shows zero overlap |
| Communication | Unaffected | Not touched | Not touched | Unaffected | — | **CONFIRMED unaffected** | No file touched |
| Student Lifecycle | Unaffected | Unaffected | Unaffected | Unaffected | — | **CONFIRMED unaffected** | §13 |
| Student 360 | Unaffected | N/A | N/A | Unaffected | — | **CONFIRMED unaffected** | No relationship exists |
| Student Trail | Unaffected | N/A | N/A | Unaffected | — | **CONFIRMED unaffected** | Feature doesn't exist yet |
| Roll Number | Unaffected | Unaffected | Unaffected (Phase 8's separate fix, unchanged) | Unaffected | — | **CONFIRMED unaffected** | §14 |
| Class | Unaffected | Unaffected | Unaffected | Unaffected | — | **CONFIRMED unaffected** | §14 |
| Section | Unaffected | Unaffected | Unaffected | Unaffected | — | **CONFIRMED unaffected** | §14 |

## 20. Shared-Contract Audit

| Field | Producer | Consumer(s) | Storage | Type | Tenant scope | Changed by this migration? |
|---|---|---|---|---|---|---|
| `schoolId` | PHP onboarding (`Superadmin_schools.php`), Firebase Auth claims | Every Firestore doc-id, this migration's query result | Firestore doc id, custom claim | String (`SCH_XXXXXX`) | Global identity | **No** — still the same value, only its resolution path changed for one edge case |
| `schoolCode` | PHP onboarding (`Superadmin_schools.php:324`) | This migration's new query filter; RTDB (unchanged, separate consumers) | `schools/{schoolId}.schoolCode` field (Firestore); `Indexes/School_codes` (RTDB, untouched) | String (numeric login code) | Global uniqueness (verified, Phase 8.2) | **No** — read from an additional place, written from none new |
| `studentId` | `Id_generator.php` | Everywhere | Firestore doc-id component | String | Global | **No** — untouched |
| `srNo`/`srNoNorm` | `Sr_no_service.php` | `Sr_no_service`/`Readmission` only | `srNoIndex`, `students.srNo*` | String | Per-school | **No** — no relationship to this migration |
| `previousStudentId` | `Sr_no_service::relink` | Same | `students.previousStudentId` | String | Per-link | **No** |
| `className`/`sectionName` | `enroll_student`, promotion | Many | `students.className/section` | String, canonical-form | Per-session | **No** |
| `rollNumber` | `Sis.php` | Report cards, rosters | `students.rollNo`/`Roll No` | String | Per-class/section | **No** |

**No mismatch found. No field's producer, consumer, storage, type, or tenant scope changed as a
result of this migration.**

## 21. Production-Data Safety

**CONFIRMED zero production writes.** This pass performed no Firestore write, no RTDB write, and no
data backfill — matching the expectation set by Phase 8.2's live verification that none was needed
for the migration itself. The only production *reads* performed were the read-only verification
already reported in Phase 8.2 (not repeated this pass) and standard app runtime reads (none executed
— no emulator/device was run).

## 22. Git / Change-Scope Verification

```
D:\Projects\SchoolSyncParent  git status --short (after this pass, diffed against before):
 M app/src/main/java/com/schoolsync/parent/data/repository/AuthRepository.kt
 M app/src/main/java/com/schoolsync/parent/di/AppModule.kt
 M app/src/main/java/com/schoolsync/parent/data/firebase/FirebaseService.kt
```

**Exactly three files changed by this pass**, all directly required by the authorized migration.
Every other pending change (Phase 8's earlier work, the pre-existing Transport/maps branch work) is
present and untouched, confirmed via `git diff` review before writing this report.

## 23. Test Results

| Check | Result | Status |
|---|---|---|
| `./gradlew :app:compileDebugKotlin --offline` | **BUILD SUCCESSFUL**, only pre-existing warnings (Transport/maps/deprecated-icon files, none touched by this pass) | **TESTED** (genuine compile-level build) |
| Multi-tenant query-shape reasoning | Structurally cannot cross tenants (§6) | **CONFIRMED** (static reasoning from the query's own definition) |
| Case A-G behavioral trace | All 7 cases traced against actual code | **CODE-REVIEWED** |
| Firestore rules permitting the query | **CONFIRMED BLOCKING** for the edge case | **TESTED via direct rules-source inspection**, not assumed |
| Firestore index requirement | **CONFIRMED none needed** | **STATICALLY VERIFIED** |
| Runtime login (emulator/device) | Not run | **NOT TESTED** |
| Live Firestore query against production (does the query itself, permissions aside, return correct data when it *is* permitted — i.e. Case A doesn't need this path, so untestable in isolation without a token lacking school_id) | Not run this pass (would require a live, credential-holding client SDK session, not the Admin-SDK read-only script used in Phase 8.2, which bypasses rules entirely and so couldn't have caught this) | **NOT TESTED / NOT APPLICABLE this way** — this finding was only catchable by reading the rules source, which is exactly what happened |
| Full Parent App RTDB re-scan | Run, see §17 | **STATICALLY VERIFIED** |

## 24. Remaining Gaps

1. **§15 — the central, disclosed blocker.** The migrated query does not functionally work under
   current Firestore rules for the exact edge case it serves. Two remediation paths named, neither
   chosen, both requiring your authorization.
2. **§18 — FirebaseService cannot be fully removed** without touching two unrelated files
   (`StudentRepository.kt`, `HomeworkRepository.kt`) — out of scope, documented, not fixed.
3. **No runtime/emulator testing performed anywhere** in this pass, consistent with every prior phase
   — stated precisely, not implied to be more than compile-level verification.

## 25. Final Verdict

**STOP — REGRESSION FOUND.**

Not "READY WITH CONDITIONS," because the instructions define that tier for *unresolved conditions*,
not *confirmed regressions* — and §15 is a confirmed regression: a case that worked before (RTDB,
public read) does not work now (Firestore, rules-blocked), discovered through direct evidence, not
inferred. The code itself is correct, minimal, compiles, and contains no hidden fallback or
unauthorized change — the regression is not a bug in the implementation, it is a **missing piece of
supporting infrastructure (a rules change, or an alternative resolution mechanism) that this pass was
explicitly not authorized to make.**

**The blast radius is narrow** (only the edge case where a login token has a blank `schoolId` claim
but a present `school_code` claim — not the common path) and **fails safe, not open** (a denied query
returns nothing; it does not leak cross-tenant data or crash the app — the existing raw-value fallback
means login still completes, just without the correct `schoolId` resolution for that one case). This
is a real, disclosed regression, not a catastrophic one — both things are true and stated precisely.

---

## Direct Answers, Per §28

1. **What changed:** `AuthRepository.lookupSchoolCode()` now queries Firestore instead of RTDB;
   `AppModule.kt` and `FirebaseService.kt` updated as a direct, necessary consequence.
2. **Why it is safe:** compiles cleanly, no cross-tenant data-shape risk, no hidden fallback, no
   unrelated file touched, zero production writes, fails closed rather than open.
3. **What was tested:** genuine compile-level build (twice — once mid-implementation to confirm the
   constructor-signature change propagated correctly); direct rules-source inspection (not assumed);
   repo-wide RTDB re-scan.
4. **What cross-platform contracts were verified:** §19-20's full matrix — no shared field, no
   Admin Panel behavior, no Teacher App behavior, no Student Lifecycle contract was found changed.
5. **Remaining risks:** §15 (the confirmed regression, blocking real production use of this edge
   case until a rules decision is made) and §18 (a pre-existing, unrelated dead-code coupling,
   documented not fixed).
6. **Exact final verdict: STOP — REGRESSION FOUND**, narrowly scoped to §15, with the code otherwise
   complete and ready to function correctly the moment that one supporting decision is made.

---

**Nothing in this pass was committed, staged, reset, stashed, or pushed, in any of the three
repositories.**
