# PHASE 20.2 — Sections & Seats: Capacity Workflow + Roll Assignment Audit

**Status:** RESEARCH COMPLETE · CAPACITY WORKFLOW IMPLEMENTED · **TWO STOP CONDITIONS DECLARED**
**NOT COMMITTED · NOT PUSHED · NOT DEPLOYED · UAT NOT EXECUTED**
**Date:** 2026-08-16 · **Branch:** `integration/phase2` @ `758c924` · **Tenant:** `SCH_B56BB9A401`, session `2026-27`

---

## 1. Executive summary

| ID | Finding | Status |
| --- | --- | --- |
| **P20.2-RACE-1** | Capacity enforcement is **not atomic** — no transaction, CAS or seat claim between check and write | **STOP — NOT FIXED**, smallest safe design documented |
| **P20.2-ROLL-1** | **Bulk roll assignment does not exist.** Only `suggest_roll_no()` | **STOP — feature absent**, sequencing recommendation given |
| P20.2-CAP-1/2/3 | Capacity display, occupancy, and the capacity-write endpoint | **FIXED / IMPLEMENTED** |
| P20.2-UI-1 | Strength & Occupancy table + Bootstrap 3 editor with warn+confirm | **IMPLEMENTED — REQUIRES UAT** |
| P20.2-RBAC-1 | Capacity write gated `SIS:manage` with the legacy name-gate removed | **IMPLEMENTED — one decision open** |
| P20.2-CAP-4 | Display capacity mirrors D17 rather than calling the canonical accessor | **FOUND — NOT FIXED** (protected file) |
| P20.2-CAP-5 | Section creation still does not accept a capacity in one step | **BY DESIGN — registered** |

### The two things you most need to know

**1. Capacity cannot currently be made race-safe without a new mechanism.** Two operators who both
see 39/40 can both admit. The brief instructed me to STOP rather than invent a second mechanism, and
I have. Nothing in this phase claims to fix it, and no test pretends otherwise.

**2. Bulk roll assignment does not exist.** Part 9 asked what happens on a second run, whether
existing rolls are overwritten, and so on. Those questions describe a feature that is not in the
codebase. Answering them as though it existed would have repeated exactly the Phase 20.1B mistake
(`students.streamKey` — a field that "obviously" existed and was never written).

---

## 2. Worktree / concurrency safety (Part 0)

| Item | Start | End |
| --- | --- | --- |
| Branch / HEAD | `integration/phase2` @ `758c924` | unchanged |
| Staged | 4 | **4** |
| Stashes | 3 | **3** |
| Modified / untracked | 117 / 489 | 117 / 492 (+3 mine) |

No reset, clean, checkout, stash, rebase or revert. Exact-string edits only — no large dirty file
was overwritten.

### Protected files — all five byte-identical, verified before and after

```
application/core/MY_Controller.php ......... daf5f2f1…  UNCHANGED
application/libraries/Firestore_service.php  c9eff0ca…  UNCHANGED
application/helpers/rbac_helper.php ........ 73652264…  UNCHANGED
firebase-rules/firestore.rules ............. b597cee2…  UNCHANGED (still the P20-F14 hash)
firebase-rules/firestore.indexes.json ...... 14190666…  UNCHANGED
```

**P20-F14 status:** `firestore.rules` has not changed again since Phase 20.1c. It still carries the
other session's HRMS `locations` work. **Not modified, not reverted, not normalised, not deployed.**
It remains blocked on your reconciliation.

---

## 3. Phase 20.1c UAT result (Part 2)

> ## UAT NOT EXECUTED — browser unavailable.

I have no browser or interactive session against `localhost:8080`. I did **not** execute UAT case A
(delete a class with enrolled students), B (allowed empty deletion), C (undetermined handling) or D
(stream deletion — and per the brief I did **not** invent a test stream; the tenant has zero).

**I am not claiming the Phase 20.1/20.1b/20.1c deletion-safety work is UAT-verified.** It is
statically verified only. Case A remains the single highest-value manual check available to you, and
it is unchanged from my Phase 20.1c recommendation.

---

## 4. Existing capacity-engine audit (Part 3 / Part A)

Re-verified from source this phase, not carried over.

| Primitive | Location | Role |
| --- | --- | --- |
| `_filterSectionRoster()` | `MY_Controller:1258` | LC-1-tolerant membership; both field shapes; blank session kept |
| `_sectionCapacityExceeded()` | `MY_Controller:1295` | the single enforcement gate |
| `_sectionCapacity()` | `MY_Controller:1328` | D17 resolution, memoised per session\|class\|section |
| `_sectionCapacityMessage()` | `MY_Controller:1358` | operator-facing refusal text |
| `_cachedStudentDocsForRollCheck()` | `MY_Controller:1388` | shared snapshot; per-request memo + 90 s file cache |

**All seven placement flows still route through the one gate** — admission `Sis:667`, promotion
`Sis:1549`, import execute `Sis:5552`, import validate `Sis:6610`, enroll `Sis:9629`, rollover
`School_config:4132`, transfer `Classes:750`.

**No second capacity engine was created.** `_sectionCapacity()` and D17 are untouched
(`MY_Controller` byte-identical). No capacity rule was duplicated in JavaScript — the browser
receives server-computed numbers and formats them.

---

## 5. Section data-contract audit (Part 4 / Part B)

Canonical fields actually written today, by `Firestore_service::saveSection():1267-1277`:

`schoolId` · `className` · `section` · `classOrder` · `sectionCode` · `sectionKey` · `classTeacher`
· `session` · `studentCount` · `updatedAt` — **and NOT `maxStrength`**.

Document id: `{schoolId}_{session}_{classKey}_{sectionKey}`, built only via `sectionDocId():1286`.

### P20-F3 — what this phase did and did not do

`saveSection()` still does not stamp `maxStrength`, and I deliberately did **not** change it: it
lives in `Firestore_service.php`, a protected file, and altering the creation contract was neither
necessary nor authorized.

What changed instead, which is the part that actually mattered:

- capacity is now **settable** from the modern surface (it previously had no path at all);
- the fallback is now **visibly distinguished** — a section that has never been configured shows its
  40 in amber with **“not set”**, and the summary counts how many are in that state.

**No backfill was performed (P20-D3 = NO).** The probe found 35/35 sections without `maxStrength`;
all 35 remain that way until an operator makes a decision. An accidental 40 is never presented as a
choice.

**P20.2-CAP-5 (registered):** creating a section and setting its capacity remain two steps. Making
creation accept a capacity would need either a protected-file change or a second write path, and the
matrix UI collects no capacity at creation time — so a one-step flow would be dead code today.

---

## 6. Capacity semantics (Part 5) — D17 unchanged

Re-verified: `_sectionCapacity()` initialises `$capacity = 40` and only raises it on `$raw > 0`. It
can never return ≤ 0, so `_sectionCapacityExceeded()`'s `if ($capacity <= 0) return null` unlimited
branch remains **unreachable dead code** (P20-F2).

**No “Unlimited” control was added (P20-D2 = NO).** Exposing it would require an engine-level change
to D17, which is explicitly not authorized. The write endpoint therefore **rejects 0** rather than
accepting it — storing 0 would silently resolve to 40, the opposite of an operator's intent.

Accepted range: **1–500**, whole numbers only.

---

## 7. New capacity workflow (Part 6) — implemented

Extended the existing Academic Setup → Sections surface. **No parallel module was created.**

### Backend

| Endpoint | Change |
| --- | --- |
| `school_config/get_all_sections` | now returns a per-section `seats` map: `capacity`, `capacity_configured`, `occupied`, `available`, `over`, `section_node` |
| `school_config/save_section_capacity` | **new** — the single capacity writer on the modern surface |

Occupancy is computed by `_filterSectionRoster()` over the shared snapshot — **the same derivation
the guard enforces with**, so the table and the admission gate cannot disagree (the defect SEC-11
closed).

### UI

A **Section Strength & Occupancy** table beneath the matrix:

| Class | Section | Capacity | Occupied | Available | Status | Action |
| --- | --- | ---: | ---: | ---: | --- | --- |
| Class 1st | A | **40** | 32 | 8 | `Open` | Capacity |
| Class 1st | B | **40** | 40 | 0 | `Full` | Capacity |
| Class 2nd | A | 40 *not set* | 12 | 28 | `Open` | Capacity |
| Class 9th | A | **30** | 38 | 0 | `Over by 8` | Capacity |

Bootstrap 3 / AdminLTE only — verified **0** occurrences of Bootstrap 4/5 markup. No native
`alert`/`prompt`/`confirm` introduced (the file's single `confirm()` is the pre-existing *locations*
one). Unknown occupancy renders `—`, never a fabricated 0.

---

## 8. Capacity-change policy (Part 7) — P20-D5 implemented

| Case | Behaviour |
| --- | --- |
| increase (40 → 45) | saved immediately |
| equal to occupancy (38 → 38) | saved immediately |
| **below occupancy (40 → 30, 38 enrolled)** | **409 `confirm_required`** carrying `occupied`, `requested`, `over_by`; the UI shows the server's message and reveals a confirmation checkbox; a second call with `confirm_over_capacity=1` saves |
| capacity 0 or negative | rejected |
| occupancy unverifiable | **409 `occupancy_undetermined`** — fails closed rather than let the operator confirm a warning built on a number we do not trust |

**Guarantees, asserted by test:** no student is removed, transferred or renumbered; `srNo` and
`rollNo` are never referenced; only `maxStrength` + `updatedAt` are written. Enforcement acts on the
**next** placement, never retroactively.

The warning text is generated **server-side from real numbers** — the UI does not invent it.

---

## 9. STOP CONDITION — P20.2-RACE-1: capacity is not atomic (Part 8)

### What the code actually does

`Sis::save_admission()` reads occupancy via `_sectionCapacityExceeded()` (`:667`), then — after
validation, ID generation and SRNO claim — writes the student document. Between check and write
there is **no transaction, no CAS, no seat claim, and no re-read**.

Two concurrent requests both observing 39/40 both pass and both write → **occupancy 41**.

The 90-second file cache does not cause this (it is invalidated on `fs->` student writes), but it
does not prevent it either: request B may read before request A writes.

**Intra-request accumulation is already correct.** Import tracks a running tally
(`$seatsUsed[$groupId] + 1`, `Sis:6611`), so one import cannot overfill itself, and the preview
explicitly documents *"PREVIEW IS NOT AUTHORIZATION"*. The gap is strictly **cross-request**.

### Existing atomic infrastructure — surveyed, and it does not cover capacity

| Primitive | Location | Used for | Applicable to seats? |
| --- | --- | --- | :-: |
| `firestoreCreate()` — 409 create-if-not-exists CAS | `Firestore_service:778` | Student IDs, SRNO, APAAR, **roll numbers** | not as-is |
| `casMutate` / `updateTime` precondition | `Firestore_service:864` | school doc, streams, sessions | not as-is |
| `_config_lock_acquire()` — file lock | `School_config:369` | session/stream lifecycle | single-host only |

**Roll numbers are already race-safe** — `suggest_roll_no()` claims each candidate atomically into
`rollNoClaims` via `firestoreCreate()` with a TTL for abandoned claims (`Sis:4017-4040`). That is a
proven, in-domain pattern.

**Seats have no equivalent.** Per the brief I am stopping rather than inventing one.

### Smallest safe design (documented, NOT implemented)

Mirror the proven `rollNoClaims` pattern with a **seat claim**:

```
seatClaims/{schoolId}_{md5(session_class_section_slot)}
```

1. Before writing a student, `firestoreCreate()` a claim for slot `occupied+1`. A 409 means another
   request took it — re-read and retry, or refuse.
2. Bind the claim to the created `studentId`; expire unbound claims by TTL, exactly as
   `ROLL_CLAIM_TTL_SECONDS` already does.
3. Release on withdrawal / transfer / TC.

**Why this is a real project, not a patch:** all seven placement paths must participate, or the
counter drifts and becomes a second source of truth for occupancy — precisely what SEC-11 closed and
what Phase 20's design explicitly refused. It also needs a claim-reconciliation job.

**Rejected alternative:** a denormalised `sections.occupiedCount`. It creates a second occupancy
authority that silently drifts whenever any writer forgets to decrement.

**Explicitly not claimed:** no test here demonstrates concurrency safety. Sequential unit tests
cannot, and the test file says so in its header.

---

## 10. STOP CONDITION — P20.2-ROLL-1: bulk roll assignment does not exist (Part 9)

Exhaustive search across controllers, views, JS and routes found **no bulk roll assignment feature**.
The only roll endpoint is `Sis::suggest_roll_no()` (`:3972`) — single-student, `SIS:manage`.

Part 9's questions A–I therefore have no current answer, because the behaviour they describe has no
implementation. **I am not going to invent one.**

### What DOES exist, traced

| Behaviour | Implementation |
| --- | --- |
| Suggest next free roll | `suggest_roll_no():3972` — walks 1…500, **claims atomically** via `rollNoClaims` + `firestoreCreate` |
| Abandoned claim expiry | `ROLL_CLAIM_TTL_SECONDS` (ROLL-5) — a claim left by a closed form is released, not blackholed |
| Duplicate prevention on admission | `_rollNoConflict()` (`MY_Controller:1107`), scoped School+Session+Class+Section; blank roll skips |
| Transfer | `Classes::transfer_students():680` — **blocks** on roll conflicts, never renumbers |
| Promotion / rollover | do not touch `rollNo` |

**The policy the brief proposes is already the de-facto behaviour for every existing path:** existing
rolls are preserved, nothing is renumbered, conflicts block rather than overwrite.

### Recommendation — sequence it after P20.2-RACE-1

Bulk roll assignment must respect section capacity (Part 9 G/H). Building it **now** would inherit
the unresolved capacity race and multiply it across a batch. Roll claiming is already atomic; seats
are not. **Fix seats first, then build bulk roll on a race-safe foundation.**

---

## 11. Section identity / `sectionKey` audit (Part 11)

**No rename capability was added, and none is exposed.** Asserted by test in both PHP and JS.

`sectionKey` (`"Class 9th/Section A"`) remains a cross-platform contract — 8–9 document types in
both Kotlin apps (Phase 20.1b, re-confirmed). Document id, `className`, `section`, `sectionCode`,
`classOrder` and `sectionKey` are all unchanged. The capacity write touches **one field**.

---

## 12. Cross-module audit (Part 12)

| Module | Contract | Impact |
| --- | --- | --- |
| Admission / Promotion / Transfer / Import / Enroll / Rollover | `_sectionCapacityExceeded()` | **none** — gate untouched |
| Attendance, Fees, Timetable, Examination, Communication, Reports | read `sections` fields | **none** — no field added, removed or renamed |
| `subjects` / streams | — | **none** — untouched (P20-F10 decision stands) |

**No field rename · no document id change · no `sectionKey` change · no SRNO change · no Roll No
substitution.** Attendance and Examination were **not modified** (audit only, per Part 12/20).

### Parent / Teacher apps (Part 14)

Re-confirmed from both repos: neither reads the `sections` collection or `maxStrength`; both consume
`sectionKey` on other documents. Capacity is admin-only.

> **NO MOBILE CHANGE REQUIRED.**

---

## 13. Performance (Part 13)

| Operation | Reads |
| --- | --- |
| `get_all_sections` — sections | 1 collection read (**pre-existing**, unchanged) |
| `get_all_sections` — capacity | **0 extra** — resolved from documents already fetched |
| `get_all_sections` — occupancy | **0 when the snapshot is warm**, 1 collection read when cold |
| `save_section_capacity` | 1 section `get` + 0–1 snapshot + 1 write |

**No N+1. No per-section query. No per-student query.**

### The N+1 I avoided, and its cost

Routing display capacity through the canonical `_sectionCapacity()` would issue **one uncached
Firestore round-trip per section** — `Firestore_service::get():310` has no cache — i.e. **35 round
trips** on this tenant, at the ~1–3 s/op this codebase documents. That would have been dramatically
worse than the 2,092 ms screen this replaces.

So capacity is resolved from the already-fetched documents by `_display_capacity()`, a deliberate
mirror of D17. **P20.2-CAP-4 (registered):** this is a second copy of the rule. Three mitigations:

1. It is **display-only** — no placement decision consults it. Drift would show a wrong number, never
   admit or refuse a student wrongly.
2. A test asserts the mirror and the canonical rule agree across the full input space (absent, 0,
   negative, non-numeric, empty, null, string-numeric, legacy snake_case, positive, 500).
3. The proper fix — a bulk capacity accessor on `MY_Controller` — needs a protected-file change and
   separate authorization.

**No index was added. `firestore.indexes.json` byte-identical.** No new query shape was introduced;
the new queries carry no field predicates.

**No performance improvement is claimed** — nothing was measured in a browser.

---

## 14. RBAC (Part 15)

`save_section_capacity()` uses `_require_role([], 'school_config_save_section_capacity', 'SIS', 'manage')`.

The **empty allow-list is deliberate**: `MY_Controller:1699` falls through to the legacy role-name
list only when a caller supplies one. Passing `[]` removes that fallback, so the graded `SIS:manage`
capability is the sole authority and **a user merely named “Principal” cannot bypass it** — exactly
what Part 15 requires. Super Admin / School Super Admin still pass at `:1672-1673`, so the operator
account is unaffected.

**Backend remains authoritative** — the endpoint enforces independently of UI visibility.

### P20.2-RBAC-1 — open decision

Section **create/delete** on this same screen is gated `Configuration:edit`; capacity is now
`SIS:manage`. A user with Configuration but not SIS can create a section and then cannot set its
capacity. That follows the brief's instruction, but the split is worth your explicit confirmation.

---

## 15. SRNO audit (Part 10)

| Check | Result |
| --- | --- |
| SRNO used as class/section foreign key | ❌ none |
| Roll No treated as permanent identity | ❌ none |
| Admission No substituted for SRNO | ❌ none |
| Student ID substituted for SRNO | ❌ none |
| **Capacity change alters SRNO** | ❌ **none** — writes only `maxStrength` |
| Section transfer alters SRNO | ❌ none (re-confirmed) |
| Promotion / rollover alters SRNO | ❌ none |
| Bulk roll overwrites SRNO | n/a — feature does not exist |
| Identity hierarchy on documents/prints | not touched this phase |

Machine-verified: `save_section_capacity`, `_display_capacity`, `_capacity_is_configured` and the UI
capacity editor contain **zero** references to `srNo|sr_no|admissionNo|rollNo`.

> ## SRNO AUDIT: PASS

---

## 16. Tests and exact baseline comparison (Parts 16–17)

**New:** `tests/Unit/Phase202SectionCapacityWorkflowTest.php` — **20 tests, 75 assertions, passing.**

Covers: D17 mirror equivalence across 10 input shapes · configured-vs-fallback flag · available/over
derivation · unknown occupancy stays null · endpoint writes only `maxStrength` · 0 rejected ·
canonical `sectionDocId` · canonical roster for occupancy · fail-closed occupancy · reduction
decision table · RBAC empty allow-list · UI performs no capacity/occupancy arithmetic · Bootstrap 3
only · duplicate-submit protection · unset shown distinctly · no rename capability · SRNO absent in
PHP and JS · RACE-1 documented as unresolved.

| Run | Tests | Failures |
| --- | ---: | ---: |
| Pre-implementation baseline (this phase) | **1539** | **10** |
| **After Phase 20.2** | **1559** | **10** |

**Identical failures, failure-for-failure**: `BackupTest::size_human` ×1, `Homework*` ×4,
`ParentHomeworkRepoSilentCatch` ×3, `TeacherExecuteDeleteCascade` ×2.

**Zero new failures. NOT claiming all tests pass — 10 pre-existing failures remain.**

Also run: `php -l` clean on both modified PHP files · **RTDB scan clean** · protected-file hashes
verified · static SRNO scan.

**A test bug I introduced and fixed:** my view-source stripper used `!` as a regex delimiter on a
pattern containing `<!--`, and its `/* */` pass swallowed the capacity modal (the view has an
unbalanced `/*`). Both corrected; the helper documents why the block-comment pass is omitted.

---

## 17. Runtime UAT matrix (Part 18)

> **UAT NOT EXECUTED — browser unavailable.** No row below has been exercised in a browser.

| Case | Static | Browser | Result |
| --- | :-: | :-: | --- |
| create section with capacity | ✅ | ❌ | two-step by design (P20.2-CAP-5) |
| edit capacity | ✅ | ❌ | REQUIRES UAT |
| reduce below occupancy | ✅ | ❌ | REQUIRES UAT — highest value |
| full section placement | ✅ engine unchanged | ❌ | REQUIRES UAT |
| bulk roll assignment | n/a | n/a | **feature does not exist** |
| repeat bulk roll assignment | n/a | n/a | **feature does not exist** |
| existing roll preservation | ✅ traced | ❌ | existing paths preserve; REQUIRES UAT |
| unauthorized capacity edit | ✅ | ❌ | REQUIRES UAT |
| delete protection | ✅ (20.1/20.1b/20.1c) | ❌ | REQUIRES UAT — carried over |
| **concurrent placement** | ❌ | ❌ | **cannot pass — P20.2-RACE-1 unresolved** |

---

## 18. Files changed / intentionally untouched

**Changed:** `application/controllers/School_config.php` (capacity resolver ×2, `seats` in
`get_all_sections`, `save_section_capacity`) · `application/views/school_config/index.php`
(seats table, capacity modal, render + save handlers).

**New:** `tests/Unit/Phase202SectionCapacityWorkflowTest.php` · this report.

**Intentionally untouched:** `MY_Controller.php` · `Firestore_service.php` · `rbac_helper.php` ·
`firestore.rules` (**P20-F14, blocked**) · `firestore.indexes.json` · `Classes.php` and every legacy
class/section/stream view · `Sis.php` placement paths · Attendance · Examination · Library · HRMS ·
Fees · ID Card · Transport · `subjects` (P20-F10 decision stands) · Student Charge Module (Part 21).

---

## 19. Status ledger

**FIXED / IMPLEMENTED** — P20.2-CAP-1 (display resolution, 0 extra reads) · P20.2-CAP-2 (occupancy
via canonical rule) · P20.2-CAP-3 (capacity writer + warn/confirm + fail-closed occupancy) ·
P20.2-UI-1 (table + Bootstrap 3 editor) · P20.2-RBAC-1 (capability-only gate)

**FOUND — NOT FIXED** — **P20.2-RACE-1** (capacity not atomic — design documented) ·
**P20.2-ROLL-1** (bulk roll absent) · P20.2-CAP-4 (D17 mirror; needs protected-file change) ·
P20.2-CAP-5 (two-step create) · P20-F13/F15/F16/F11 (carried) · P20-F2/F4/F5 (carried)

**NOT A DEFECT** — P20-F10 (`subjects.stream`), decision unchanged

**REQUIRES UAT** — every row in §17

**BLOCKED BY DECISION** — P20-F14 (`firestore.rules`) · P20.2-RBAC-1 split · P20.2-RACE-1 design
approval

**OUT OF SCOPE** — Attendance, Examination, Library, HRMS, Fees, ID Card, Transport, Student Charge
Module, legacy retirement

> ## SRNO AUDIT: PASS

---

## 20. Legacy-retirement readiness — NOT READY

`Classes.php` and its views remain **untouched and functional**. Retirement is still blocked on:

| Gate | State |
| --- | :-: |
| New capacity UI complete | ✅ implemented |
| **Transfer path migrated** | ❌ **still legacy-only** (`Classes::transfer_students`) |
| Bulk roll verified | ❌ feature does not exist |
| **Concurrency verified** | ❌ **P20.2-RACE-1** |
| UAT complete | ❌ not executed |
| Cross-platform audit | ✅ done |
| Soak | ❌ not started |
| Clean scoped commit | ❌ 117 modified / 492 untracked |
| Explicit authorization | ❌ not given |

**Student transfer between sections still exists only in the legacy module.** That alone makes
retirement impossible.

---

## 21. Exact next-phase recommendation

**Phase 20.3 — in this order:**

1. **Browser UAT** of what now exists: capacity edit, reduce-below-occupancy confirm, unauthorized
   edit, and the carried-over delete-protection cases. Cheap, and it validates four phases of work at
   the only layer you can actually see.
2. **Decide P20.2-RACE-1.** Approve the `seatClaims` design (§9), or accept the race explicitly and
   record it. Everything downstream — bulk roll, retirement — depends on this answer.
3. **Then** migrate `transfer_students` to the modern surface under `SIS:manage`.
4. **Then** build bulk roll assignment on the race-safe foundation.
5. **Only then** consider legacy retirement, with a clean scoped commit as the rollback point.

**Do not start** the Student Charge Module (Part 21) or any module in §19's out-of-scope list.

---

```
PHASE 20.2 — CAPACITY WORKFLOW IMPLEMENTED; TWO STOP CONDITIONS DECLARED
NOT COMMITTED
NOT PUSHED
NOT DEPLOYED
UAT NOT EXECUTED
```

Awaiting review. Phase 20.3 not started.
