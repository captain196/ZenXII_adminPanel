# PHASE 20.3K-STEP2 — Import Destination Grouping

**Status:** **COMPLETE — Import wired · 5/5 paths detected · flag still FALSE**
**P20.2-RACE-1 remains OPEN — mechanism is implemented and unit-verified, NOT live-certified**
**NOT COMMITTED · NOT PUSHED · NOT DEPLOYED**
**Date:** 2026-08-16 · **Branch:** `integration/phase2` @ `758c924`

---

## 1. Outcome

The four objectives of the continuation brief, in order:

| Objective | Result |
| --- | --- |
| DIAGNOSE HARNESS | Root cause found and fixed — not guessed, not suppressed |
| REAPPLY GROUPING | Pass 1 / Pass 2 / Pass 3 reapplied to `Sis::_runStudentImport()` |
| VERIFY FLAG-OFF EQUIVALENCE | 15 golden-master traces **identical** to the frozen Step 1 master |
| VERIFY FLAG-ON GROUPING | New 14-test flag-ON suite — **all pass** |
| ACHIEVE 5/5 WIRING | Detector reports **Admission · Enroll · Transfer · Promotion · Import** |

The flag `atomic_capacity_enabled` is **still FALSE** and was not enabled.

---

## 2. The harness diagnosis

Run alone, the first error was:

```
Only variables should be assigned by reference
  application/libraries/Section_seat_guard.php:95   ← $CI = &get_instance();
  called from application/controllers/Sis.php:6162
```

Three files in the shared PHPUnit process declare `get_instance()`, guarded by
`function_exists()`. Two had already been corrected to return **by reference** (matching CI3's real
`function &get_instance()`). The third — `tests/Support/audit_helper_stub.php:13` — still declared it
**by value**, loaded first, and therefore won the race and silently defeated both fixes.

Fixed at source: the stub now declares `function &get_instance()` returning a static instance.
Backward-compatible for every existing caller.

**No production behaviour was changed to satisfy the harness.** The three edits were all in
`tests/`.

---

## 3. What is now in `Sis::_runStudentImport()`

Flag-gated throughout; with the flag off, not one of these branches is entered.

**PASS 1** (`Sis:6162`) — validation, supplied-SRNO resolution, Student ID mint and payload build all
run exactly as before, then instead of calling `fs->saveStudent()` the row is buffered into
`$importGroups[class|section]` and the loop `continue`s. Nothing identity-bearing has been claimed at
this point — SRNO, APAAR and Admission Number all live inside `$postWrite`.

**PASS 2** (`Sis:6217`) — per destination section, in insertion order:

1. group size `> groupMaxStudents()` (499) → **refuse**, never chunk;
2. `_sectionCapacityExceeded($cls, $sec, $session, $n)` → **one** capacity decision for the whole group;
3. `Section_seat_guard::placeStudentGroup()` → one section read + one atomic commit of
   *N student writes + 1 CAS marker*.

Any non-`PLACED` outcome marks **every row** of that group failed with a distinct reason
(over-limit / capacity / conflict / unconfirmed) and logs `ACC_IMPORT_GROUP_REFUSED`.

**PASS 3** (`Sis:6282`) — `$postWrite($ctx)` replayed only for students in a **committed** group.

### `$seatsUsedThisRun` — the reconciliation the brief required

It is **not consulted** by the group decision and takes no part in it. It remains the flag-off
running projection, incremented inside `$postWrite`, which under grouping runs only *after* commit.

The residual per-row check at `Sis:5966` (`$capUsed + 1`) is now **provably unreachable** under
grouping: Pass 2 already established `current + N <= capacity`, and in Pass 3 `capUsed + 1 <= N`, so
`current + capUsed + 1 <= capacity` always holds. There is exactly **one** capacity authority
(`_sectionCapacityExceeded`, occupancy derived solely from student documents) and **no second
occupancy source**. Test `FLAGON_03` proves this behaviourally: a section filled to *exactly*
capacity admits every row.

---

## 4. New verification — flag ON

`tests/Unit/Phase203kStep2ImportGroupingTest.php` (14 tests, 56 assertions). The flag is enabled
per-test through a config double on `get_instance()` and **reset in `tearDown`**; the shipped config
file is not touched.

Real, not mocked: the whole Pass 1 loop, the grouping, the 499 cap, `_sectionCapacityExceeded` /
`_sectionCapacity` / `_sectionActiveCount` / `_filterSectionRoster` from `MY_Controller`, the refusal
messages, and the Pass 3 replay. Doubled: `placeStudentGroup()` — its atomicity is a Firestore
property already proven against **live** Firestore in Phase 20.3g (winners=1 / losers=1). What is
tested here is Sis's orchestration around it.

| Test | Proves |
| --- | --- |
| `FLAGON_00` | negative control — the flag is genuinely ON, and goes back OFF |
| `FLAGON_01` | one destination = ONE commit; `saveStudent` no longer called (no second write path) |
| `FLAGON_02` | two destinations = two independent groups of the right sizes |
| `FLAGON_03` | exact capacity admitted; `$seatsUsedThisRun` never double-counts |
| `FLAGON_04` | over capacity refuses the **whole** group before any commit; 0 SRNO consumed |
| `FLAGON_05` | group over max refused outright — **never chunked** into 3 + 1 |
| `FLAGON_06` | real 500-row / 499-limit boundary refused |
| `FLAGON_07` | CAS conflict → every row reported, 0 SRNO, `ACC_IMPORT_GROUP_REFUSED` logged |
| `FLAGON_08` | unknown outcome reported, **never retried** (exactly one commit attempt) |
| `FLAGON_09` | one refused group does not block a healthy one |
| `FLAGON_10` | `$postWrite` runs once per **committed** student only |
| `FLAGON_11` | no SRNO / fee / parent-sync work happens before the commit |
| `FLAGON_12` | the `import_commit()` result contract is unchanged |
| `FLAGON_13` | every student committed exactly once — no duplication, no silent drop |

---

## 5. Verification summary

| Check | Result |
| --- | --- |
| `php -l` on every touched file | clean |
| Golden master (flag OFF) | **15 traces identical** to the frozen Step 1 master |
| Flag-ON grouping suite | **14 / 14 pass, 56 assertions** |
| Wiring lock suite | **14 / 14 pass** — detector reports **5/5** |
| Full Unit suite | **1687 tests, 5317 assertions, 10 failures** |
| Baseline / new / total failures | **10 / 0 / 10** — the same 10 named below |
| `atomic_capacity_enabled` | **FALSE** |
| Live tenant | **not touched** · identities consumed **0** |
| Commit / Push / Deploy | **NO / NO / NO** |

Test count moved 1673 → 1687: exactly the 14 tests added here.

The 10 failures are the documented baseline — nine cross-repo tests that grep Kotlin app source on
another machine, plus `BackupTest::size_human_formats_bytes_correctly` (`1 KB` vs `1.0 KB`):
`BackupTest`, `HomeworkInputLengthValidationTest`, `HomeworkRosterFailClosedTest`,
`HomeworkSchoolIdPredicateTest`, `HomeworkSilentCatchTest`, `ParentHomeworkRepoSilentCatchTest` (×3),
`TeacherExecuteDeleteCascadeTest` (×2).

**Protected files — not edited in this phase.** They are dirty in the working tree from earlier,
unrelated sessions. Current digests, recorded so the next phase can compare:
`MY_Controller.php ecc08e75` · `Firestore_service.php ed0750f0` · `rbac_helper.php d6ca2adf` ·
`firestore.rules 4f9ed6e1` · `firestore.indexes.json c473c914`.

---

## 6. Files changed in this step

| File | Change |
| --- | --- |
| `application/controllers/Sis.php` | Pass 1 buffering, Pass 2 atomic group, Pass 3 replay — all flag-gated |
| `tests/Support/audit_helper_stub.php` | `get_instance()` by reference; `public $config = null` |
| `tests/Unit/Phase203kStep2ImportGroupingTest.php` | **NEW** — flag-ON grouping suite |
| `tests/Unit/Phase203hAtomicCapacityWiringTest.php` | detector marker for Import; 5/5 expectation |

`application/config/capacity_cas.php` — **unchanged**, flag remains `false`.

---

## 7. What this does NOT claim

- **P20.2-RACE-1 is NOT fixed.** All five paths are wired and unit-verified, but the mechanism is
  inert while the flag is FALSE. The race is still live in production behaviour.
- No live UAT was run in this step. The five-path UAT on `SCH_B56BB9A401` — including the first
  PHP REST-path `FAILED_PRECONDITION` observation on the Import path — has not been performed.
- The guard's atomicity is proven at the Firestore layer (20.3g), not end-to-end through Import.

---

## 8. Next

1. Five-path live UAT on `SCH_B56BB9A401` (Vikrant Public) with the flag enabled **for that run only**.
2. Only on a clean UAT: authorise the flag flip as its own decision.
3. Open findings unchanged and still registered: P20-F2/F3/F4/F5, P20-F11, P20-F13, P20-F14,
   P20.2-CAP-4/5, P20.2-RBAC-1, P20.3K-F1.

New observation registered this step, **not fixed**:

- **P20.3K-F2 (LOW, pre-existing).** `Sis:5970` rejects a row on the per-row capacity check *after*
  `saveStudent()` has already created the student document, and unlike every other rollback branch it
  does not delete it. The row is reported as an error while the document survives. Pre-dates this
  phase and is unreachable under grouping (§3), but remains live on the flag-off path.

---

```
PHASE 20.3K-STEP2 — COMPLETE
5/5 WIRED · FLAG FALSE · FLAG-OFF EQUIVALENCE PROVEN · FLAG-ON GROUPING PROVEN
P20.2-RACE-1 STILL OPEN — AWAITING FIVE-PATH LIVE UAT
```
