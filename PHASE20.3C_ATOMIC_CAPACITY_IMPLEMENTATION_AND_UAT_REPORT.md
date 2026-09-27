# PHASE 20.3c — Atomic Section Capacity: Implementation Attempt + Blocker

**Status:** **STOPPED AT §1 — infrastructure built, NO path wired, behaviour unchanged**
**P20.2-RACE-1 REMAINS OPEN ON ALL FIVE PATHS**
**NOT COMMITTED · NOT PUSHED · NOT DEPLOYED · UAT NOT EXECUTED**
**Date:** 2026-08-16 · **Branch:** `integration/phase2` @ `758c924`

---

## 1. Executive summary

I built the Option-B mechanism, wired admission with it, discovered a blocker that makes the same
approach unusable on the three bulk paths, and **reverted the admission wiring** rather than ship a
partial solution.

**The blocker (§6):** admission and enroll place ONE student. Promotion, import-execute and transfer
place students **in loops**. Per-student CAS inside a loop is *functionally correct* — the read memo
flushes on every write, so each iteration re-reads a fresh `updateTime` — but it costs **one extra
section round-trip per student**, at the ~1–3 s/op this codebase documents. A 60-student promotion
would gain 60 sequential section GETs. That is the N+1 §24 forbids, and it would make promotion and
import unusable.

The correct design is **group-by-destination-section, one atomic batch per section**. That requires
restructuring `Student_write_service::batchMoveStudents()` and the import row loop — including their
per-row error reporting — which I could not complete to the required standard in this run.

Per **§1** (*"Either all five … participate, or STOP"*) and the standing property **NO PLACEMENT
BYPASS**, wiring one path was the wrong outcome, so it was reverted.

| | State |
| --- | --- |
| `Section_seat_guard` library | ✅ built, **inert** (no callers) |
| `Entity_firestore_sync::buildStudentDoc()` | ✅ built, **inert** |
| `_placeStudentGuarded()` in `Sis.php` | ✅ built, **inert** |
| Paths wired | **0 of 5** — admission wired then reverted |
| Behaviour change | **none** |
| **P20.2-RACE-1** | **OPEN** |

---

## 2. Worktree safety

| Item | Start | End |
| --- | --- | --- |
| Branch / HEAD | `integration/phase2` @ `758c924` | unchanged |
| Staged / stashes | 4 / 3 | **4 / 3** |

No reset, clean, checkout, stash, rebase or revert of unrelated work. Surgical edits only.

**Protected files — all five byte-identical:**
`daf5f2f1` MY_Controller · `c9eff0ca` Firestore_service · `73652264` rbac_helper ·
`b597cee2` firestore.rules (still the P20-F14 hash) · `14190666` firestore.indexes.json

`firestore.rules` / `indexes.json` **not opened**. P20-F14 still open.

---

## 3. Identity-timing audit (§9) — the one clean result

Traced in `save_admission()`:

| Step | Line | What |
| --- | ---: | --- |
| Capacity check | `:667` | `_sectionCapacityExceeded()` |
| **Student ID minted** | `:760` | `_nextStudentId()` |
| **Student write** | `:938` | `syncStudent()` ← the batch would go here |
| **SRNO + APAAR claimed** | `:1025` | `_claimIdentityAttributes()` |

**Retry is provably identity-safe by construction**, because the guard's scope excludes all identity
minting:

- Student ID is minted **once**, before the guard would be entered.
- SRNO / APAAR are claimed **after** it — a refusal returns before `:1025`, so no SRNO is ever
  claimed for a placement that did not happen.
- The student document id is deterministic from `$studentId`, and the write is a merge-set, so
  re-submitting the identical payload is idempotent.

**No duplicate SRNO, Student ID or Admission No is possible through this mechanism.**

*(Pre-existing, unchanged: a Student ID minted at `:760` is consumed even if the write later fails.
That is current behaviour, not a regression.)*

---

## 4. What was built (all inert)

### `application/libraries/Section_seat_guard.php` — new

- `placeStudent()` / `commitPlacement()` — commits the student write **and** a CAS marker on the
  destination section in **one** `commitBatch`, guarded by `currentDocument.updateTime`.
- Marker is **`seatCasAt`, an ISO timestamp** — deliberately not a counter, so it cannot be mistaken
  for occupancy and become the second source of truth SEC-11 closed.
- `merge => true`, so it self-creates on sections that never carried it: **no backfill, no
  migration, no initialisation race** (the precondition is on `updateTime`, which every document
  already has).
- **Missing `__updateTime` → refuse.** An unguarded write that merely looks atomic is worse than a
  refusal.
- `MAX_ATTEMPTS = 1` — see §5.
- `FAILURE` (unknown outcome) is **never** retried: the student write may already have reached
  Firestore.

### `Entity_firestore_sync::buildStudentDoc()` — new

Returns the **exact** document `syncStudent()` would write, without writing it, by re-entering the
real method and intercepting its single write point. A separate builder would have created a second
student-document contract that could silently drift — the SEC-11 failure mode. Disarmed in a
`finally`, so the normal write path is untouched.

### `Sis::_placeStudentGuarded()` — new, no callers

---

## 5. Second blocker found: server-side retry is impossible without a protected file

§7 STEP 8–9 require re-reading and recomputing after a conflict. Both canonical helpers are
per-request memoised with function-level `static` and expose no reset:

```
MY_Controller:1330   _sectionCapacity()               static $memo = [];
MY_Controller:1390   _cachedStudentDocsForRollCheck() static $memo = null;
```

A same-request retry would therefore re-derive the **identical stale answer** — a provably useless
loop. Clearing them requires modifying `MY_Controller` (**PROTECTED**, §28 STOP).

**Safe degradation, already implemented:** a conflict **refuses** with an actionable message and the
operator resubmits — a fresh request with fresh memos. The guarantee is unaffected (the loser never
writes, so capacity cannot be exceeded); only availability under rare contention is. This is a
**deviation from §17's retry requirement** and needs your decision.

---

## 6. THE BLOCKER — bulk placement paths (§1, §24, §28)

| Path | Shape | Write | Per-student CAS viable? |
| --- | --- | --- | :-: |
| Admission | single | `syncStudent` `:938` | ✅ |
| Enroll | single | `syncStudent` `:10036` | ✅ |
| **Promotion** | **bulk** | `dw->batchMoveStudents()` → per-student `syncStudent` | ❌ |
| **Import execute** | **bulk, per-row loop** | `syncStudent` `:6064` | ❌ |
| **Transfer** | **bulk, per-student loop** | `fs->updateEntity` `Classes:852` | ❌ |

Per-student CAS in a loop is **correct but not viable**: `getDocument`'s read memo is flushed on
every write, so each iteration would re-read a fresh `updateTime` and succeed — at the cost of **one
additional section round-trip per student**. At ~1–3 s/op, a 60-student promotion gains 60 of them.

There is a second problem in the same place: for a bulk operation the canonical capacity gate is
memoised, so iterations 2..N see **stale occupancy**. Import already compensates with its own
`$seatsUsed + 1` running tally; `batchMoveStudents` does not.

### The correct design (NOT implemented)

**Group by destination section; one atomic batch per section; one CAS precondition per batch.**
Firestore permits 500 writes per commit, so a 60-student promotion into one section becomes 61
writes in a single atomic commit — strictly better than today, and fully atomic.

**Why I did not build it:** it restructures `Student_write_service::batchMoveStudents()` and
`_runStudentImport()`'s write loop, both of which carry per-row/per-student error reporting that
callers and the import preview depend on. Doing that badly on the paths that move dozens of students
at once would be far worse than the race it fixes.

---

## 7. Why admission was wired and then reverted

Wiring admission alone would have protected the highest-volume path and, reported honestly, would
have been a strict improvement. I reverted it because **§1 is unambiguous** and because the standing
property list includes **NO PLACEMENT BYPASS** — with 1 of 5 wired, three seat-consuming bypasses
remain while the code reads as guarded. A future maintainer seeing `_placeStudentGuarded` at the
admission site would reasonably assume placement is protected.

The revert is annotated in place at the admission write site, pointing here.

**Net behaviour change this phase: none.**

---

## 8. Verification

| Check | Result |
| --- | --- |
| `php -l` — Sis, Entity_firestore_sync, Section_seat_guard | ✅ clean |
| Full suite | **1584 tests, 4970 assertions, 10 failures — identical to the 20.3b baseline, failure-for-failure** |
| New tests | **0** — the mechanism has no callers; tests asserting an inert path would imply protection that does not exist |
| Protected files | ✅ all five byte-identical |
| Rules / indexes | ✅ not opened |
| RTDB | ✅ none introduced |
| Staged / stashes | ✅ 4 / 3 unchanged |

**Not claiming green — the same 10 pre-existing failures remain.**

> ## SRNO AUDIT: PASS
> No identity code was modified. `Section_seat_guard` mints nothing, writes nothing on refusal, and
> contains no `srNo` / `rollNo` / `admissionNo` / `studentId` reference as a key. `buildStudentDoc()`
> is a pure capture of existing behaviour.

> ## NO MOBILE CHANGE REQUIRED.
> No document schema changed — the `seatCasAt` marker is never written, because the guard has no
> callers. Neither app reads the `sections` collection.

**Cross-module:** no downstream record changed. Fees, ID Cards, Transport, Attendance untouched.

---

## 9. Closure criteria (§29)

```
[ ] all five seat-consuming paths participate      ← 0 of 5
[x] occupancy remains student-derived
[x] section updateTime CAS protects the write      ← implemented, unwired
[x] student + marker atomically committed          ← implemented, unwired
[~] retry is bounded                               ← bound = 1, no server-side retry (§5)
[x] unknown failures are not treated as contention
[x] identity retry is proven safe                  ← §3
[x] no duplicate SRNO / Student ID / Admission No
[x] unit tests pass without new failures
[x] static audits pass
[ ] real Firestore UAT passes                      ← UAT NOT EXECUTED
[ ] simultaneous placement cannot exceed capacity  ← NOT TRUE TODAY
[ ] final occupancy verified from student records
```

> ## P20.2-RACE-1 = OPEN

---

## 10. Files changed / untouched

**Changed:** `application/libraries/Entity_firestore_sync.php` (capture hook + `buildStudentDoc()`;
`syncStudent()` behaviour unchanged when disarmed) · `application/controllers/Sis.php`
(`_placeStudentGuarded()` helper, no callers; admission site annotated and restored).

**New:** `application/libraries/Section_seat_guard.php` · this report.

**Untouched:** all five protected files · `Student_write_service.php` · `Classes.php` ·
`School_config.php` · the capacity UI · Fees · ID Cards · Transport · Attendance · Examination ·
Library · Parent/Teacher apps.

---

## 11. Remaining risks

| Risk | Rank | State |
| --- | --- | --- |
| **P20.2-RACE-1 — capacity can be exceeded on all 5 paths** | **HIGH** | **OPEN — unchanged** |
| `FAILED_PRECONDITION` body shape unverified against live Firestore | MEDIUM | needs UAT; degrades to refuse-not-retry |
| No server-side retry without touching `MY_Controller` | MEDIUM | decision needed (§5) |
| P20-F14 — concurrent `firestore.rules` change | MEDIUM | OPEN, blocked on you |

---

## 12. Decisions required

1. **Authorise the group-by-destination batch design** (§6) for promotion, import and transfer —
   this is the real remaining work and it is a bulk-write-path restructuring.
2. **Accept conflict→refuse** (no server-side retry), or authorise a minimal `MY_Controller` change
   to allow memo refresh so §17's bounded retry can exist.
3. **P20-F14** — reconcile `firestore.rules`.

---

## 13. Exact next phase

**Phase 20.3d — bulk placement batching.** Restructure `batchMoveStudents()` and `_runStudentImport()`
to group writes by destination section and commit one atomic batch per section with a single CAS
precondition; extend `transfer_students()` the same way; then wire all five paths together and add
tests T1–T30; then **live Firestore UAT** cases A–G on a verified-clean `SCH_D94FE8F7AD`.

**Do not start** Phase 20.4, 20.5, legacy retirement, or any other module.

---

```
PHASE 20.3C — STOPPED AT §1
INFRASTRUCTURE BUILT AND INERT · 0/5 PATHS WIRED · BEHAVIOUR UNCHANGED
P20.2-RACE-1 REMAINS OPEN
NOT COMMITTED · NOT PUSHED · NOT DEPLOYED · UAT NOT EXECUTED
```
