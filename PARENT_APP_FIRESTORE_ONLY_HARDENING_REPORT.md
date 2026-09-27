# Parent App Firestore-Only Hardening Report (Phase 8)

**Date:** 2026-08-13 · **Status: Implemented, compile-verified. NOT committed, staged, or pushed.**
**Working tree: HELD** in both mobile repos and the PHP repo — nothing reset, reverted, stashed, or
cleaned. Pre-existing unrelated pending work (a Transport/maps feature on
`SchoolSyncParent`'s `ankit/my_parentFeature` branch) was inspected and left untouched — confirmed
via `git diff` before and after this pass that only the files listed in §2 changed.

---

## Simple-language summary (read this first)

**What was wrong:** the Parent App still talked to the old Realtime Database in three places: a
safety-net fallback if Firestore login failed, a safety-net fallback if a leave-application write or
read failed, and one lookup used only when a login token was missing a piece of information.

**Why it mattered:** this entire hardening program has treated "Firestore is the only source of
truth" as a settled fact. Two of the three RTDB uses were quietly working against that — most
concerning, the leave-application write fallback would let a parent's leave request appear
"submitted" successfully while actually landing in a store the Admin Panel never reads, meaning the
school would never see it.

**What was fixed:** both safety-net fallbacks are gone. A Firestore failure now correctly reports as
a failure instead of silently degrading to duplicate, unsynced RTDB data.

**What was NOT fixed, on purpose:** the one remaining RTDB read (resolving a school's identity when
a login token is incomplete) was investigated and found unsafe to simply swap for a Firestore
equivalent — the seemingly-matching Firestore collection on the Admin Panel side has never actually
been written by any code path there. Replacing it would have silently broken login for that edge
case instead of fixing anything. This is flagged for your decision (§19), not guessed at.

**Was it tested?** Both apps were compiled from source after every change (`gradlew
compileDebugKotlin`, genuine builds, not simulated) and both succeeded. No emulator or device was
used, so runtime/UI behavior was not tested — that distinction is kept precise throughout this
report.

---

## 1. Executive Summary

Per Phase 7's discovery, this pass performed a fresh, independent, repository-wide RTDB inventory of
the Parent App (not trusting the prior pass's list as exhaustive), traced the login/school/session
flow in full, and removed every RTDB usage that could be proven safe to remove — while explicitly
stopping on the one that could not be proven safe. A real compile error introduced mid-pass (a
stale DI constructor call) was caught by an actual Gradle build, not just static review, and fixed
before this report was written. The pre-authorized Teacher App Roll Number fix was also completed,
and in the process a *second*, more severe instance of the same defect class was found and fixed
(§14) beyond the three files originally named.

## 2. Complete RTDB Inventory

**Fresh, independent sweep this pass** (not a re-listing of Phase 7's 8 known items) found the same
core set plus several previously-unknown dead-code instances:

| Location | Classification | Action |
|---|---|---|
| `app/build.gradle.kts:190` `firebase-database-ktx` | LIVE+REACHABLE | Kept — one live caller remains (see below) |
| `SchoolSyncApp.kt:52` RTDB persistence init | LIVE+REACHABLE | Kept, comment corrected (§4) |
| `FirebaseService.kt` — `ref()`, `readValueFresh()`, `readChildrenFresh()`, `readChildren()`, `readChildrenLimited()`, `observeValue()`, `observeMap()`, `observeChildren()`, `observeQuery()`, `pushData()`, `updateChildren()` | **LIVE BUT UNUSED — zero callers anywhere** | **REMOVED** (§7) |
| `FirebaseService.kt` — `readMap()`, `writeValue()` | LIVE+REACHABLE (via AuthRepository/Leave/DataRepository) | **REMOVED** once their 3 callers were removed (§7) |
| `FirebaseService.kt` — `readValue()`, `readString()` | LIVE+REACHABLE | **KEPT** — the one surviving call chain (§4/§19) |
| `AuthRepository.kt` — `loadProfileFromRtdb()` + its OR-fallback | LIVE+REACHABLE (fallback path) | **REMOVED** (§4) |
| `AuthRepository.kt` — `lookupActiveSession()` | **DEAD — zero callers, explicitly documented as such in-code** | **REMOVED** (§5) |
| `AuthRepository.kt` — `lookupSchoolCode()` | LIVE+REACHABLE (edge-case path) | **KEPT — STOP item** (§19) |
| `LeaveFirestoreRepository.kt` — write fallback | LIVE+REACHABLE (fallback path) | **REMOVED** (§9) |
| `LeaveFirestoreRepository.kt` — read fallback | LIVE+REACHABLE (fallback path) | **REMOVED** (§10) |
| `DataRepository.kt` (whole class) | **LIVE BUT UNUSED — zero callers anywhere in the app, only DI-provided** | **DELETED** (§8) |
| `Constants.kt` `object Firebase` — 22 of 23 RTDB path builders | DEAD — zero callers | **NOT removed this pass** — disclosed, deferred (§20) |
| `HomeworkRepository.kt` (`@Deprecated` dead RTDB stub, never calls its own injected `firebaseService`) | DEAD, pre-existing, unrelated to this violation | **NOT touched** — out of scope (§20) |
| `StudentRepository.kt` (unused `firebaseService` constructor param) | DEAD, pre-existing | **NOT touched** — out of scope (§20) |
| Comment-only mentions (~16 files) | COMMENT ONLY | Not touched except where directly adjacent to removed code |

## 3. Login Flow Audit

Full trace of `AuthRepository.login()` (unchanged step order, RTDB fallback removed): Firebase Auth
sign-in → custom-claims read → schoolCode resolution (claims-first, RTDB `lookupSchoolCode` only if
claims incomplete) → **Firestore-only** profile read → `must_change_password` claim OR → status gate
→ save to `TokenManager` → session resolution (already Firestore-only, unchanged) → FCM registration.

## 4. School Resolution Audit

`lookupSchoolCode()` reads RTDB path `Indexes/School_codes/{id}`, called only when the `schoolId`
claim is blank but a `school_code` claim is present — an edge case in the normal flow, not the
common path. **Investigated for a Firestore replacement, found unsafe (§19) — kept, not replaced.**

## 5. Session Resolution Audit

`lookupActiveSession()` — **confirmed dead code** before this pass even began: the function's own
surrounding comment (already in the file, not written by this pass) stated it was preserved unused
purely so a 2026-05-26 rollback ("SW4") could be a single atomic revert. That rollback never
happened; the function has had zero callers since. **Removed.** Session resolution has been
Firestore-only (via `StudentDoc.session` + `SchoolFirestoreRepository.observeSchool()`) since SW4
shipped — this pass changed nothing about that, only deleted the now-confirmed-permanently-unused
rollback scaffolding.

## 6. Parent Profile Audit

`loadProfileFromFirestore()` remains the sole profile source. Verified (this pass, by reading both
functions side-by-side before removing the fallback) that every RTDB field
(`Name/Email/Phone/Class/Section/RollNo/FatherName/MotherName/DOB/Gender/AdmissionDate`) has a
same-purpose Firestore sibling on `StudentDoc`, and that Firestore additionally provides
`mustChangePassword` (RTDB defaulted this to `false`, a documented bypass-risk) and a real `session`
value (RTDB hardcoded `""`). The fallback was a **strict subset**, not a distinct data source —
confirmed before deletion, not assumed.

## 7. Leave Application Audit

`submitLeave()`: Firestore `leaveApplications` write is primary. The RTDB write-fallback (fired only
on a Firestore exception) is **removed** — a Firestore write failure now returns `Result.failure`
directly. **Why this is a fix, not just a simplification:** the Admin Panel (`Attendance.php`, 10+
call sites) reads `leaveApplications` from Firestore exclusively — confirmed by grep, no RTDB read
exists on the admin side for this feature. A leave application that fell back to RTDB would have
reported success to the parent while being **permanently invisible to the school**. Removing the
fallback converts a silent, misleading "success" into an honest, visible failure — strictly safer.

## 8. Leave History Audit

`getLeaveHistory()`'s RTDB fallback (a full-school-scan of `Schools/{code}/LeaveApplications`, fired
only on a Firestore query exception, never on an empty-but-successful result) is **removed**. Same
reasoning as §7: the fallback's data was never consumed by the Admin Panel either, and — as a
secondary finding — the fallback's own unfiltered full-tenant read was structurally wider than what
it needed (confirmed in the Phase 7 inventory, re-verified this pass), an additional reason it was
never a sound safety net.

## 9. Firestore Replacement Mapping

| Old RTDB source | Current Firestore source | Status |
|---|---|---|
| `Users/Parents/{parentDbKey}/{userId}` (login fallback) | `students/{schoolId}_{userId}` (already primary) | RTDB removed — Firestore was already doing the real work |
| `Schools/{schoolCode}/Config/ActiveSession` | `schools/{schoolId}.currentSession` via `SchoolFirestoreRepository.observeSchool()` | RTDB removed — Firestore path already live since SW4 |
| `Schools/{schoolCode}/LeaveApplications/{id}` (write fallback) | `leaveApplications/{id}` (already primary) | RTDB removed |
| `Schools/{schoolCode}/LeaveApplications` (read fallback) | `leaveApplications` query (already primary) | RTDB removed |
| `Indexes/School_codes/{id}` | `indexSchoolCodes/{code}` exists as a schema on the Admin Panel (`Firestore_service.php:103,1389-1398`) **but has zero writers anywhere in that codebase** | **NOT replaced — STOP item, §19** |

## 10. Multi-Tenant Verification

Re-confirmed the Phase 7 finding that every live RTDB path embeds `schoolCode`/`parentDbKey` sourced
from server-issued claims, never raw client input. This property is unchanged by this pass (the
paths removed were tenant-scoped the same way; removing them doesn't change the isolation story,
it removes redundant/unsafe stores, not a tenant boundary). The one remaining path
(`lookupSchoolCode`) is keyed by the claim-derived id, same as before.

## 11. Student Identity Verification

Unaffected. Student ID resolution (`{schoolId}_{userId}` doc-id, login-as-student pattern) was not
touched by any edit in this pass — confirmed by diff review of every changed file.

## 12. SRNO/Re-admission Impact

**None.** Zero references to SRNO or `previousStudentId` exist anywhere in either mobile app, before
or after this pass — confirmed by grep after all edits. This pass did not add, and had no reason to
add, any such reference.

## 13. Sibling Matching Findings

**Classification (per your instruction not to change it without proof): DOCUMENTATION ERROR, not a
bug, not touched this pass.** Re-inspected `StudentFirestoreRepository.kt:129` (docstring claims
`parentDbKey` is the strictest match tier) versus the actual implementation at `:189-213` (phone +
parent-name only, no `parentDbKey` reference at all). This pass did not determine definitively
whether `parentDbKey` matching was removed deliberately or simply never finished — that requires
either git history on that specific line (not investigated this pass, out of scope) or your input.
**No code was changed here.** Flagged for your decision alongside §19, not silently resolved.

## 14. Teacher Roll Number Fix

Fixed all three originally-named locations, **plus one additional site found only by reading the
actual code rather than trusting the citation:**

- `StudentsViewModel.kt` — two construction sites (not one), both `?: 0` → `?: Int.MIN_VALUE`, plus
  new `rollNoRaw: String` field added to `StudentInfo`.
- `AttendanceViewModel.kt:505` — `?: 0` → `?: Int.MIN_VALUE`, `rollNoRaw` added to
  `StudentAttendanceRow`.
- `MarksViewModel.kt:310` — **this was not actually `?: 0`** as the citation implied; on inspection
  it was `?: (idx + 1)` — fabricating a plausible-looking sequential roll number for a non-numeric
  value, which is arguably worse than showing 0 (it looks like real data). Fixed the same way:
  `?: Int.MIN_VALUE` + `rollNoRaw` added to `StudentMark`.

**Scope boundary, stated honestly:** this fix stops a non-numeric roll number from being
silently misrepresented as a plausible-but-fake number. It does **not** make the UI actually *display*
an alphanumeric roll number like "7A" — that would require updating every screen that renders
`rollNo` (6+ files per Phase 7's inventory) to read the new `rollNoRaw` field instead, which is a
materially larger, UI-touching change outside this pass's pre-authorized "fix ONLY the type-coercion
problem" scope. The new `rollNoRaw` field exists specifically so that follow-up is a small, additive
change whenever it's authorized — not a re-plumbing.

## 15. Class/Section Dependency

Not touched this pass, per explicit instruction. SL-12 remains open (Phase 6/7, unchanged). No
dependency on it was found for anything done in this pass — the RTDB removals and the Roll Number
fix are both orthogonal to class/section canonicalization.

## 16. Firestore Security Verification

Not independently re-verified this pass (no `firestore.rules` file exists in either mobile repo to
inspect — rules live in the PHP repo's `firebase-rules/`, already covered by prior passes). The
removals in this pass reduce attack surface (fewer write/read paths overall) without adding any new
one.

## 17. Build/Test Results

| Check | Result | Status |
|---|---|---|
| `SchoolSyncParent` — `./gradlew :app:compileDebugKotlin --offline` | **BUILD SUCCESSFUL** (after fixing one real error this pass introduced and caught) | **TESTED** (compile-level, genuine build, not simulated) |
| `SchoolSyncTeacher` — `./gradlew :app:compileDebugKotlin --offline` | **BUILD SUCCESSFUL**, only pre-existing deprecation warnings + one new harmless "unused parameter" warning from this pass's own edit | **TESTED** (compile-level) |
| Login flow (runtime) | Not run | **NOT TESTED** — no emulator/device available in this environment |
| School/session resolution (runtime) | Not run | **NOT TESTED** |
| Leave submission/history (runtime) | Not run | **NOT TESTED** |
| Lint / static analysis beyond the compiler's own warnings | Not run separately | **NOT TESTED** |
| Full repo-wide RTDB re-scan | Run, see §18 | **TESTED** (grep-verified) |

**A real compile error was caught, not just theorized:** the first compile attempt failed with a
genuine type-mismatch — `AppModule.kt`'s `provideLeaveFirestoreRepository()` still called the
3-argument `LeaveFirestoreRepository` constructor after this pass had already reduced it to 2 args.
This is exactly the value of actually running the build rather than only reviewing the diff — fixed
immediately, re-verified with a second successful build.

## 18. Final RTDB Search Results

Post-change repo-wide grep for `FirebaseDatabase|DatabaseReference|getReference(` in
`SchoolSyncParent`:

```
SchoolSyncApp.kt:8,52       — RTDB persistence init (kept, §19)
FirebaseService.kt:5,34,41  — readString()/readValue() only (kept, §19)
```

**Two files, both intentionally retained, both directly explained by the same STOP item.** This is
not "zero live RTDB references" — that goal was explicitly subordinate to "don't trade one problem
for another" (§23 of the original prompt), and this pass holds to that: everything provably safe to
remove was removed; the one thing that wasn't provably safe was left alone and reported, not guessed
at.

## 19. Historical Data Assessment — STOP item, your decision needed

**`lookupSchoolCode()` / the RTDB `Indexes/School_codes/{id}` read — NOT removed. Here is exactly
why, and what would be needed to remove it safely:**

- The seemingly-matching Firestore collection, `indexSchoolCodes`, is a real, defined schema on the
  Admin Panel side (`Firestore_service.php:103`, `getSchoolByCode()`/`setSchoolCode()` helpers at
  lines 1389-1398).
- **`setSchoolCode()` has zero callers anywhere in the entire PHP codebase** — confirmed by
  repo-wide grep, not assumed. No other code path writes to `indexSchoolCodes` either (also
  confirmed by grep for the literal collection name).
- This means the Firestore collection is very likely **empty in production**, or populated only by
  some out-of-band process this session has no visibility into.
- If this pass had swapped the RTDB read for a Firestore read of `indexSchoolCodes`, and the
  collection is indeed empty, **every login that hits this edge case (a token with a blank
  `schoolId` claim) would silently fail** — a real regression, for the exact case this function was
  built to handle.
- Additionally, the Kotlin path builder's own parameter is named `mongoSchoolId`
  (`Constants.kt:20`), while the PHP side's key is named `code` — a naming mismatch suggesting these
  two indexes may not even be keyed in the same direction. This was not resolved; it's exactly the
  kind of ambiguity this report is required to surface, not guess through.

**What data is missing / what would be needed to close this safely (your decision, not this pass's
to make):**
1. Confirm from your side (Firebase console access this session does not have) whether the RTDB
   `Indexes/School_codes` node actually holds meaningful data in production today, or is itself
   already stale/empty.
2. If it holds real data: a one-time backfill of `indexSchoolCodes` in Firestore from that RTDB node
   would be required before the Kotlin call site could be safely switched.
3. If it's already empty/unused: the whole `lookupSchoolCode()` code path (and, with it,
   `FirebaseService.kt`, the Gradle RTDB dependency, and the `SchoolSyncApp.kt` init call) could be
   deleted entirely — the true "zero RTDB" end state — but only once that's confirmed, not assumed.

**Classification: Firestore partial** (the schema exists, the data plausibly doesn't) — not "RTDB-
only historical data" in the sense of unrecoverable data, but a genuine unknown this pass could not
resolve without either backend access or your confirmation.

## 20. Remaining Gaps

- §19's STOP item — the central remaining gap.
- §13's sibling-matching documentation/implementation mismatch — flagged, not resolved.
- 22 dead RTDB path builders in `Constants.kt` — harmless (no execution path reaches them), left
  alone this pass to keep the diff focused on live code; safe, low-value cleanup for a future pass.
- `HomeworkRepository.kt`'s `@Deprecated` dead RTDB stub and `StudentRepository.kt`'s unused
  `firebaseService` param — pre-existing, unrelated to the specific violation this pass closed, not
  touched.
- Teacher App Roll Number: the underlying non-numeric-value problem is now safely contained (§14)
  but not yet **displayed** correctly anywhere — that's a separate, larger, UI-touching follow-up.
- No runtime/emulator testing was performed anywhere in this pass (§17) — everything marked TESTED
  is compile-level or grep-level, stated precisely, not implied to be more than that.

## 21. Production Readiness

**READY WITH CONDITIONS.**

Simple-language recap of what's left, per the required format:

- **What is wrong?** One RTDB read (school-code resolution for an edge-case login) can't yet be
  proven safe to remove.
- **Why does it matter?** Removing it blind could break login for real families in that edge case;
  leaving it means the app isn't fully Firestore-only yet.
- **Which module is affected?** Login/authentication only.
- **Which app?** Parent App only — Teacher App was confirmed RTDB-clean in Phase 7 and unaffected by
  anything in this pass.
- **Can it break real school operations?** Not today — nothing was changed about this specific path;
  it behaves exactly as it did before this pass, which was already working.
- **What is the safe fix?** Either confirm the RTDB data is unused and delete the whole path, or
  backfill `indexSchoolCodes` in Firestore first — your call, per §19.
- **Does historical data need migration?** Possibly, for this one item — genuinely unknown without
  your input, not guessed at.
- **Was it actually tested?** The removal work that *was* done is compile-verified (§17); the
  retained path is unchanged from its already-working prior state, so no new test exposure there.

## 22. Exact Next Steps

1. **Answer §19** — is `Indexes/School_codes` in RTDB meaningfully populated in production, or
   already effectively dead? This single answer determines whether the Parent App can reach true
   zero-RTDB.
2. **Optional, low-priority:** decide whether §13's sibling-matching docstring should be corrected to
   match the real (phone+name) implementation, or whether `parentDbKey`-based matching should
   actually be built.
3. **Optional, low-priority:** authorize the Teacher App Roll Number *display* follow-up (using the
   new `rollNoRaw` field in the UI layer) if alphanumeric roll numbers are something your schools
   actually use today.

---

**Nothing in this pass was committed, staged, reset, stashed, or pushed, in either mobile repository
or the PHP repository.**
