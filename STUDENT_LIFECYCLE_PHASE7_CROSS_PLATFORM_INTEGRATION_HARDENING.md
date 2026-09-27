# Student Lifecycle Phase 7 — Cross-Platform Integration Hardening

**Date:** 2026-08-13 · **Status: AUDIT ONLY. Zero files modified in this pass.**
**Working tree: HELD.** No commits, staging, push, reset, revert, stash, or clean — this pass added
no new code, so there is nothing new to hold beyond what earlier passes already left uncommitted.

**Baseline, not repeated:** `STUDENT_LIFECYCLE_FORENSIC_AUDIT_PHASE0.md` (SL-01→SL-38, RES-001,
CERT-001, RF-001), `STUDENT_LIFECYCLE_PHASE1_IDENTITY_DECISION.md`,
`SRNO_RE_ADMISSION_ARCHITECTURE_AUDIT.md`, `SRNO_STUDENT_ID_RE_ADMISSION_MULTI_TENANT_FINAL_AUDIT.md`,
`SRNO_STUDENT_ID_RE_ADMISSION_GAP_CLOSURE_REPORT.md`,
`STUDENT_LIFECYCLE_PHASE6_CROSS_MODULE_INTEGRATION_AUDIT.md`. Every finding from those documents is
cited by ID, not re-derived.

**What is genuinely new in this pass:** direct, first-time forensic reads of the two mobile
codebases — `D:\Projects\SchoolSyncParent` (Kotlin, Parent App) and `D:\Projects\SchoolSyncTeacher`
(Kotlin, Teacher App) — both confirmed accessible and real repositories, not assumed. This is the
first pass in this entire conversation to actually read either app's source rather than reason about
it from the PHP side alone. It found one genuinely new, significant defect (§17) and several smaller
ones, alongside confirming most of the backend-side baseline holds up unchanged.

---

## 1. Executive Summary

The SRNO/Student ID/re-admission architecture remains sound and, confirmed fresh this pass, **has
zero footprint on either mobile app** — neither references `srNo`, `previousStudentId`, or any
re-admission concept anywhere (verified by direct grep of both codebases, not inferred). That is
expected and correct for backend-only work not yet integrated.

The most important finding this pass is **not** about SRNO. It is that **the Parent App still has
live, reachable Firebase Realtime Database code** — auth/session lookups and a leave-application
write fallback — directly contradicting the "Firestore-only, zero RTDB" policy this entire
conversation has treated as settled and enforced (§17). This was not previously verified because no
prior pass in this conversation had actually read the Parent App's source. The Teacher App, by
contrast, is confirmed genuinely RTDB-free.

Two further genuine findings: the Teacher App silently coerces a non-numeric Roll Number to `0`
(§6C), and its student-roster status filter is an exclude-list rather than an include-list, meaning
any *new* status value the backend introduces would default to "shown as active" (§6D) — a real
forward-compatibility risk given this conversation's own re-admission work will eventually need new
student states.

## 2. Student Lifecycle Baseline

Unchanged. Not re-audited. See the six baseline documents listed above.

## 3. Student ID Verification

**CONFIRMED, cross-platform, this pass.** Backend: global counter, `Id_generator.php`, no schoolId
(prior passes). **Parent App:** `StudentDoc.kt` treats `studentId` as a flat field, and — genuinely
new information — the app's own Firestore access pattern is `{schoolCode}_{studentId}`
(`StudentFirestoreRepository.kt:36-38`), i.e. the CLIENT treats it as school-scoped for doc-id
purposes even though the backend's underlying counter is global. This is not a contradiction (the
compound doc-id is a different concern from the counter's allocation scope — Phase 1's identity
decision document already established this exact doc-id shape), but it does mean the Parent App's
own code has **no built-in assumption that Student ID is globally unique** — it never needs to reason
about that fact, since it always operates within one school's prefix. **Teacher App:** confirmed
`studentId` is resolved carefully (a 4-step fallback chain, `StudentRepository.kt:96-102`) and used
as the stable key for every marks/attendance write — never re-derived from name or roll number.

## 4. SRNO Verification

**NOT IMPLEMENTED on either mobile platform — confirmed by direct grep, not assumed.** Zero matches
for `srNo`/`SRNO`/`srNumber`/"register number" in either `SchoolSyncParent` or `SchoolSyncTeacher`.
This is the correct, expected state given no prior pass built any client integration — stated here as
CONFIRMED NOT IMPLEMENTED rather than left ambiguous.

## 5. Re-admission Verification

**NOT IMPLEMENTED on either mobile platform — confirmed by direct grep.** Zero matches for
`previousStudentId`/`linkedStudent`/`reAdmission` in either app. **Determination on expected mobile
behavior** (per Section 6's instruction not to invent it): given re-admission produces a **new**
Student ID with its own fresh login/Auth account, and neither app has any concept of "this student
has a predecessor," the correct current-state expectation is: **a re-admitted student's Parent/
Teacher App experience is simply a brand-new account** — no special handling is needed *for
correctness* today, because nothing in the current logic could conflate the new and old identities
(no shared cache key, no name-based matching found in either app's identity resolution path). Whether
either app should *eventually* surface historical information (e.g., a parent seeing "this child's
prior enrollment") is a **product decision**, not inferred here — flagged as Category C (§29).

## 6. Roll Number / Class / Section Verification

**A. Parent App class/section — CONFIRMED, own independent normalization.** `Constants.kt:198-203`
implements its own `classKey()`/`sectionKey()` prefix heuristic (`"Class $className"` if not already
prefixed), applied before every Firestore query. This is **not** a pass-through of a backend-computed
canonical value — it's a second, independent implementation of the same idea. **NOT VERIFIED**
whether it produces byte-identical output to the backend's canonicalization in every case; both
happen to use the same "Class "/"Section " prefix convention, so they likely agree in the common case,
but this was not proven equivalent field-by-field.

**B. Teacher App class/section — CONFIRMED, matches backend format, but no live listener.**
`Constants.kt:133-154` produces the identical `"Class 8th"`/`"Section A"` shape. The roster fetch
(`StudentRepository.kt:74-81`) is a **one-shot query, not a live snapshot listener** — confirmed by
grep (zero `addSnapshotListener` hits in the students package). **After a server-side promotion, a
teacher's already-open roster screen goes stale until manual pull-to-refresh or re-navigation** — a
newly confirmed, concrete instance of the general staleness risk this conversation's audits have
repeatedly flagged in the abstract.

**C. Teacher App Roll Number type coercion — NEW DEFECT, confirmed this pass.**
`AttendanceViewModel.kt:128`, `MarksViewModel.kt:39`, `StudentsViewModel.kt:27` parse the Firestore
`String` roll-number field into a Kotlin `Int` via `.toIntOrNull() ?: 0`. **Any non-numeric roll
number (which the Admin Panel's own field allows — Phase 0 confirmed Roll Number has no digits-only
constraint) silently becomes `0` in the Teacher App**, visually indistinguishable from a genuinely
unassigned roll number. **Severity: MEDIUM** — display/sort corruption, not identity corruption, but
real and live.

**D. Teacher App student-status filter — NEW OBSERVATION, confirmed this pass.**
`StudentRepository.kt:53-55,84-86`: the roster fetch excludes only `{tc, withdrawn, inactive,
suspended}` (case-insensitive), and lets everything else — including blank, unrecognized, or a future
new status string — through as visible. This is **exclude-list, not include-list** design. It is
documented as a deliberate prior fix for casing inconsistency (Phase 2A), so it is not a regression —
but it means **any future student-status value this conversation's work might eventually introduce
(e.g., a re-admission-specific transitional state) will render as visible-by-default in the Teacher
App unless explicitly added to that exclusion set.** Flagged as a forward-compatibility risk to track
if/when new statuses are introduced, not a current defect.

**E. SL-12 (promotion canonicalization bypass) — re-confirmed still live** (Phase 6 §C.3, unchanged).
Combined with finding A above: the Parent App's own independent class/section normalization means a
non-canonical value written by the backend's promotion bug could produce **two different kinds of
client-side mismatch** — the Teacher App's exact-string Firestore query would simply return no
results (silent empty roster), while the Parent App's own prefix-heuristic might partially "paper
over" a minor format difference or might not, depending on the exact drift shape. **This raises SL-
12's practical severity**: it is not just a backend data-quality issue, it has a concrete, differently-
shaped failure mode on each of the two mobile clients. This strengthens, rather than changes, Phase
6's existing HIGH severity classification.

## 7. Admission Number Verification

Unchanged from Phase 0/Phase 6 (SL-24) — no writer exists, unaffected by anything found this pass.
Neither mobile app references Admission Number at all (not searched for directly this pass, but no
incidental hits surfaced in either agent's broader grep sweep).

## 8. SL-12 Investigation

Re-confirmed still present (Phase 6 §C.3) and, per §6E above, now understood to have a concrete
cross-platform failure shape rather than being purely a backend concern. Not fixed this pass — no
new authorization was given, and the backfill-scope decision Phase 6 raised remains open.

## 9. SL-13 Financial Investigation

**Backend: unchanged, still live** (Phase 0/6). **Cross-platform check, new this pass:** the Parent
App's fee-related code (`FeeFirestoreRepository.kt`, `ReceiptDetailScreen.kt`,
`TransportFeesRepository.kt`) was seen only incidentally via the "historical" grep sweep (§Q6 of the
Parent App audit) — comments there reference "carry forward" logic, but this pass did **not**
independently trace whether the Parent App reads `feeDemands` directly (inheriting SL-13's
overbilling) or an already-aggregated/corrected view. **NOT VERIFIED** — flagged honestly rather than
assumed either way; a dedicated fee-specific mobile trace would be needed to close this.

## 10. Fees → Accounting Verification

Unchanged, backend confirmed idempotent (Phase 0/6). Mobile-side verification: **NOT VERIFIED this
pass** — out of the scope actually investigated by this pass's two agent audits, which were scoped to
identity fields, not the full fee display chain. Not asserted as either healthy or broken.

## 11. Defaulter → Results Verification

Unchanged, RES-001 (RTDB read in `Result.php`) re-confirmed still live in the PHP backend (Phase 6).
**Mobile-side: NOT VERIFIED this pass** — neither agent audit traced result-withholding display
logic; scope was identity fields only. Flagged for a dedicated follow-up, not assumed.

## 12. Withdrawal → Transport → Hostel Verification

**Backend: still NOT VERIFIED** (Phase 6 §L — genuinely unknown, not newly resolved this pass).
**Parent App status handling — genuinely new evidence this pass:** `AuthRepository.kt:110-118`
blocks login entirely if `student.status != "Active"` (case-insensitive), force-signs-out, and shows
the actual status to the parent. This is a **login-time gate, not a per-record filter** — confirmed
**NOT VERIFIED** whether an already-logged-in session continues showing data after a mid-session
status change (no periodic re-check found; only re-validated on next login). **Teacher App:**
confirmed exclude-list filtering (§6D) is the only status enforcement found — no login-level gate
equivalent to the Parent App's was found in the Teacher App audit (not searched for as directly,
flagged as **NOT VERIFIED** for Teacher App login-gating specifically, distinct from the roster-filter
finding which WAS confirmed).

## 13. Admin Panel Audit

Unchanged from every prior pass in this conversation — `Readmission.php`/`Sr_no_service.php` exist
with no UI (Phase 6 §E, unchanged, not re-verified this pass since no PHP file was touched).

## 14. Parent App Audit

Full findings in §17 (RTDB) and throughout §3-6. Additional finding, new this pass: **sibling-
matching implementation diverges from its own docstring.** `StudentFirestoreRepository.kt:129`'s
comment claims `parentDbKey` is the strictest match tier, but the actual code at `:189-213` never
references `parentDbKey` — matching is phone-number + parent-name only. **NOT VERIFIED** whether this
is a stale comment or a real implementation gap (i.e., whether `parentDbKey`-based matching was
removed, never finished, or the comment was simply never accurate) — flagged as worth a direct
follow-up question to whoever last touched that file, not resolved here. **Severity: MEDIUM** — a
phone+name-only sibling match has a real, if likely low-probability, risk of matching or missing
siblings incorrectly in a large enough school (e.g., two unrelated families sharing a father's name
and an old/reused phone number).

## 15. Teacher App Audit

Full findings in §6B-D. Confirmed genuinely RTDB-free (§17). Confirmed identity handling is careful
and correct for its own scope (studentId resolution priority chain, §3).

## 16. Firestore Canonical Data Audit

Unchanged — Firestore remains the canonical store for everything this conversation's work touches.
No new canonical-data defect found this pass beyond what's already catalogued.

## 17. Legacy RTDB Audit — the significant finding this pass

**Parent App: VIOLATION, confirmed live, not dead code.**

| Location | What | Live or dead? |
|---|---|---|
| `app/build.gradle.kts:190` | `firebase-database-ktx` dependency | Present |
| `SchoolSyncApp.kt:8,46` | `FirebaseDatabase.getInstance().setPersistenceEnabled(true)` at app startup | **LIVE** |
| `data/firebase/FirebaseService.kt` | Full RTDB read/write wrapper, DI-injected (`AppModule.kt:143-144`) | **LIVE** |
| `AuthRepository.kt:79` | Firestore→RTDB fallback on login profile load, path `Users/Parents/{parentDbKey}/{userId}` | **LIVE fallback** |
| `AuthRepository.kt:445,451` | schoolCode and active-session lookup via RTDB — **not a fallback, the primary path** | **LIVE, primary** |
| `LeaveFirestoreRepository.kt:90,135` | RTDB write fallback on Firestore submit failure; RTDB read fallback for leave history | **LIVE fallback** |
| `DataRepository.kt:55,94,134,156` | Generic RTDB CRUD helper | **LIVE, retained** |
| Chat presence, transport live-tracking | Previously on RTDB | **Confirmed removed, dead, zero callers** (`AppModule.kt:304-306,317-321`) |

**This is a real, reportable contradiction of the "Firestore-only, zero RTDB, no fallback/mirror/
bridge" policy this entire conversation has operated under as settled fact.** It was not previously
known to this conversation because no prior pass had read the Parent App's actual source — every
earlier "RTDB retired" claim in this conversation was about the PHP backend (`Dual_write`/
`Student_write_service`, confirmed genuinely clean) and, separately, about `Result.php`/
`Certificates.php` (confirmed still RTDB-dependent — RES-001/CERT-001). The Parent App is a **third,
previously-unaudited surface** with its own live RTDB usage, most notably that **schoolCode/session
resolution is not even a fallback — it's the primary lookup mechanism**, meaning this isn't legacy
dead weight, it's load-bearing.

**Teacher App: CONFIRMED CLEAN.** Zero `FirebaseDatabase`/`DatabaseReference` API usage anywhere in
`app/src/main`. `StudentRepository.kt:20-25` documents the RTDB fallback's deliberate removal ("P0c
migration"). One stale doc-comment (`StudentInfo.kt:5-7`) still describes an RTDB path in prose but
invokes no RTDB API — a documentation defect, not a functional one.

**This finding is Category A (§29)** — it's exactly the class of issue ("security, tenant isolation,
cross-platform data corruption... contradicts an established architectural policy") that instruction
says must stop and be reported, not silently fixed. **No code was changed in the Parent App this
pass.**

## 18. Field Authority Matrix

| Field | Canonical Writer | Firestore Path | Admin Panel Reader | Parent App Reader | Teacher App Reader | Scope | Status |
|---|---|---|---|---|---|---|---|
| Student ID | `Id_generator.php` (global counter) | `students/{schoolId}_{studentId}` | Yes, everywhere | `StudentFirestoreRepository.kt` | `StudentRepository.kt` (careful 4-step resolution) | Global | **HEALTHY** |
| SRNO | `Sr_no_service::claim/relink` (this conversation) | `srNoIndex/{schoolId}_{srNoNorm}`, `students.srNo*` | `Readmission.php` API only, no UI | **None** | **None** | School | **PARTIAL** (backend-only) |
| Roll Number | `Sis.php` admission/edit | `students.rollNo`/`Roll No` (dual-keyed) | Yes | `StudentDoc.kt` (String) | `StudentDoc.kt` (String → **coerced to Int**, §6C) | Class+Section | **PARTIAL** (Teacher App coercion defect) |
| Admission Number | **None** | `students['Admission Number']` (never written) | `Certificates.php` reads only | Not searched | Not searched | Admission event | **MISSING WRITER**, unchanged |
| previousStudentId | `Sr_no_service::relink` (this conversation) | `students.previousStudentId` | `Readmission.php` API only | **None** | **None** | Cross-admission | **PARTIAL** (backend-only, by design) |
| Class/Section | `enroll_student` (canonical), `Dual_write`/`Student_write_service::validateClass/Section` (**non-canonical**, SL-12) | `students.className/section` | Yes | Own independent normalizer (§6A) | Matches backend format, one-shot fetch (§6B) | Session | **PARTIAL** (SL-12) |
| Session | `enroll_student`, promotion | `students.session` | Yes | Not independently re-verified this pass | Not independently re-verified this pass | — | **HEALTHY** (Phase 0) |
| Status | Multiple writers, dual-keyed (SL-06) | `students.status/Status` | Yes | Login-time gate only (§12) | Roster exclude-list filter (§6D) | — | **PARTIAL** (SL-06, plus two different, weaker-than-ideal client enforcement shapes) |
| Fee status | `Fee_lifecycle`/`FeeCollectionService` | `feeDemands`/`feeReceipts` | Yes | **NOT VERIFIED this pass** | Not investigated this pass | Student | **PARTIAL** (SL-13 backend; mobile NOT VERIFIED) |
| Attendance status | `Attendance.php` | `attendanceSummary` | Yes | Not investigated this pass | Confirmed studentId-keyed writes (§6, item 7 of Teacher audit) | Student+Month | **HEALTHY** (Teacher App write path); Parent read NOT VERIFIED |
| Result status | `Result.php` | `results`, `feeDefaulters` (RTDB read, RES-001) | Yes | Not investigated this pass | Not investigated this pass | Student+Exam | **PARTIAL** (RES-001, backend only re-confirmed) |
| Transport status | `Transport_service.php` | `studentRoutes` | Yes | Referenced in Parent App (`TransportHomeScreen.kt`, `TransportFeesRepository.kt`) but not deeply traced this pass | Not investigated | Student | **NOT VERIFIED** (mobile depth) |
| Hostel status | `Hostel.php` | `hostelAllocations` | Yes | Not investigated this pass | Not investigated this pass | Student | **NOT VERIFIED** (mobile) |

## 19. Event → Module Matrix

Unchanged from Phase 6 §K — not re-derived. Add one column-level correction: event #6 ("SRNO
assigned") dependents now confirmed to explicitly exclude both mobile apps (was already stated as
"NOT CONNECTED yet — by design" in Phase 6; this pass converts that from inference to **confirmed by
direct grep**).

## 20. Event → Platform Matrix

| Event | Backend | Admin Panel | Parent App | Teacher App |
|---|---|---|---|---|
| Student created | CONNECTED (Phase 0) | CONNECTED | Login/profile provisioned via existing Auth flow — **NOT independently re-verified this pass** | Roster picks up via next fetch (one-shot, §6B) |
| SRNO assigned | CONNECTED (this conversation) | **NOT CONNECTED** (no UI) | **NOT CONNECTED** (confirmed, zero references) | **NOT CONNECTED** (confirmed, zero references) |
| Promotion | PARTIAL (SL-12) | CONNECTED | Independent class/section normalizer — **risk of silent mismatch**, §6A/E | **Stale until manual refresh**, confirmed §6B |
| Re-admission | CONNECTED (this conversation) | API-only, no UI | **NOT CONNECTED** (correct — new account, §5) | **NOT CONNECTED** (correct — new account, §5) |
| Withdrawal | PARTIAL (transport/hostel NOT VERIFIED) | CONNECTED | Login-gated (§12) | Roster-filtered (§6D) |
| Result withheld | PARTIAL (RES-001) | CONNECTED | **NOT VERIFIED this pass** | **NOT VERIFIED this pass** |

## 21. Cross-Platform Identity Matrix

For the worked example (`STU0047`/`SRNO 1025`/`Roll 17`/`Class 8`/`Section A`):
- **Admin Panel:** would resolve correctly today for Student ID, Roll Number, Class/Section (SL-12
  permitting); SRNO has no UI to check against.
- **Parent App:** Student ID resolves correctly (own compound doc-id scheme). Roll Number displays
  correctly (no coercion defect found in this app). Class/Section uses its own normalizer — **not
  proven byte-identical** to backend output. SRNO: not present, not checked, not expected to be.
- **Teacher App:** Student ID resolves correctly. Roll Number displays correctly **only if numeric**
  — an alphanumeric roll number silently renders as `0` (§6C). Class/Section matches backend format
  but via stale one-shot fetch.
- **No situation was found** where Admin Panel and either app disagree on *which* Student ID a
  screen refers to — the specific failure modes found are about *staleness* and *field-format/type*
  fidelity, not identity confusion. This is a meaningfully positive finding, stated plainly.

## 22. Multi-Tenant Verification

**Backend: unchanged, TESTED** (prior passes, 20/20 SRNO tests). **Parent App: CONFIRMED tenant-
scoped by construction** — every list/search query found (`StudentFirestoreRepository.kt:60-219`)
filters `schoolId`/`schoolCode`, and the compound doc-id itself (`{schoolCode}_{studentId}`) makes a
cross-tenant document fetch structurally require knowing the *other* school's code, which nothing in
the observed login/session flow exposes to a parent. **Teacher App: NOT INDEPENDENTLY VERIFIED for
tenant isolation this pass** — the roster query was read for class/section/status filtering, not
specifically checked for a `schoolId` predicate; flagged as **NOT VERIFIED** rather than assumed safe.

## 23. Historical/Re-admission Verification

Backend: TESTED (prior passes). Mobile: CONFIRMED NOT IMPLEMENTED on both platforms (§5), which is
the correct current state, not a gap, since no integration was ever attempted.

## 24. Failure Scenario Audit

Walking the prompt's specific list against actual evidence gathered:

- **Promotion completed but Teacher roster still shows old class:** **CONFIRMED, real** — §6B, no
  live listener, manual refresh required.
- **Promotion completed but Parent App shows old class:** **PLAUSIBLE, not proven either way** — the
  Parent App's query mechanism (whether one-shot or listener) was not specifically traced this pass;
  flagged NOT VERIFIED, not asserted.
- **Student withdrawn but mobile apps show Active:** Parent App — **prevented for new logins** (§12
  login gate), **NOT VERIFIED for already-open sessions**. Teacher App — **plausible for any status
  string outside the 4-item exclude-list** (§6D).
- **Roll Number interpreted as SRNO / Student ID interpreted as Roll Number:** **CONFIRMED NOT
  HAPPENING** in either app — every model keeps these as distinct, never-aliased fields (§3/§6).
- **Same SRNO in School A/B causes cross-tenant leakage:** **N/A for mobile** — SRNO isn't present on
  either app to leak.
- **Old previousStudentId accidentally becomes active:** **N/A** — concept doesn't exist on either
  client.

## 25. Implemented Changes

**None this pass.** This is an audit-only deliverable, consistent with Section 23's instruction to
classify before implementing, and given the most significant finding (§17) is explicitly Category A
— stop and report, not silently fix.

## 26. Tests and Results

**No new tests this pass** (no code changed). Prior passes' test suite status is unchanged and was
not re-run since nothing in this pass could have affected it (verified — zero PHP files touched).
Explicit status per finding, per Section 24's instruction:
- §17 (Parent App RTDB) — **CODE-REVIEWED / STATICALLY VERIFIED** via direct source grep across the
  actual repository, not a runtime test (no Android emulator/build was run).
- §6C (Roll Number coercion) — **CODE-REVIEWED / STATICALLY VERIFIED**, same method.
- §6D (status exclude-list) — **CODE-REVIEWED / STATICALLY VERIFIED**.
- §6B (stale roster) — **CODE-REVIEWED / STATICALLY VERIFIED** (confirmed absence of
  `addSnapshotListener` by grep, not a runtime reproduction).
- Everything marked **NOT VERIFIED** throughout this document genuinely was not checked — not a
  euphemism for "probably fine."

## 27. Existing Unrelated Failures

Unchanged — 10 pre-existing PHP unit-test failures, same as every prior pass in this conversation, not
re-run this pass since no PHP code changed.

## 28. Remaining Gaps

Everything in Phase 6's gap list (unchanged, not repeated) **plus, new this pass:**
- Parent App RTDB usage (§17) — the most significant new gap.
- Teacher App Roll Number Int-coercion (§6C).
- Teacher App status exclude-list forward-compatibility risk (§6D).
- Parent App sibling-matching docstring/implementation mismatch (§14).
- Parent App class/section normalizer not proven equivalent to backend (§6A).
- Fee/Result/Transport/Hostel mobile-side display chains — **NOT VERIFIED**, out of this pass's
  actual investigated scope, not falsely marked healthy.

## 29. Category A/B/C Classification

**Category A (stop, report, do not silently fix):**
- **§17 — Parent App live RTDB usage**, most notably the schoolCode/session lookup being a *primary*
  path, not a fallback. This contradicts an established, repeatedly-invoked architectural policy this
  entire conversation has treated as settled. **This needs your decision**: is Parent App RTDB
  removal in scope for this workstream, a separate pre-existing project, or a policy exception you're
  already aware of that this conversation simply hadn't encountered yet? I am not assuming an answer.

**Category B (bounded, safe fixes, not yet authorized):**
- Teacher App Roll Number Int-coercion fix (change the parse to tolerate non-numeric values instead
  of defaulting to `0`) — small, isolated, Kotlin-side only.
- Documentation-only fix for the stale `StudentInfo.kt:5-7` RTDB-path comment in the Teacher App.

**Category C (business/product decisions, not implementable without your input):**
- Whether Parent/Teacher Apps should eventually surface SRNO or re-admission history at all (§5).
- Teacher App status-filter design (exclude-list vs. include-list) — changing this is a real behavior
  change affecting who's visible in every teacher's roster, not a bugfix.
- Parent App sibling-matching logic — whether the `parentDbKey` claim in the docstring should become
  real, or the docstring should be corrected to match current behavior, is a product/data-model
  question, not obviously "the code is wrong."

## 30. Production Readiness

**READY WITH CONDITIONS**, unchanged top-level verdict from Phase 6, with one new, higher-priority
condition added:

**In simple language, the new finding (§17):**
- **What is wrong?** The Parent App still talks to the old Realtime Database for logging in,
  figuring out which school a parent belongs to, and submitting leave requests — not just Firestore.
- **Why does it matter?** This conversation's entire body of work has assumed Firestore is the single
  source of truth everywhere. If the Parent App is quietly still relying on RTDB for something as
  fundamental as "which school and session am I in," any future data-migration or RTDB-decommission
  effort would break parent login without anyone realizing why.
- **Which modules are affected?** Auth/session resolution, leave applications — not the SRNO/
  re-admission work itself, which is confirmed unaffected.
- **Which platform(s)?** Parent App only. Teacher App and the PHP backend's SRNO work are confirmed
  clean.
- **What will a real school experience?** Nothing different today — this is a live, working path, not
  a broken one. The risk is future, not present: an RTDB outage or planned decommission would silently
  take down parent login.
- **What is the canonical fix?** Migrate the three live RTDB paths (session/schoolCode lookup, login
  profile fallback, leave-application fallback) to Firestore equivalents, matching the pattern already
  proven in the PHP backend and the Teacher App.
- **Does historical data need migration?** Likely not for session/schoolCode (that's config, not
  historical data) — **NOT VERIFIED** for leave-application history specifically.
- **Should we implement now, or stop for a decision?** **Stop for your decision** — per Category A.

## 31. Exact Next-Step Recommendation

Do not start another implementation phase automatically. Three independent options, none started,
awaiting your choice:
1. **Authorize a dedicated Parent App RTDB removal pass** (§17/§29A) — the highest-value, highest-
   urgency item this document surfaces.
2. **Authorize the two small Category B Teacher App fixes** (§29B) — low-risk, quick.
3. **Continue toward SRNO operator UI** (carried forward from Phase 6) — still the largest piece of
   work making this conversation's backend usable.

---

**Nothing in this pass was committed, staged, reset, stashed, or pushed. No file was modified.**
