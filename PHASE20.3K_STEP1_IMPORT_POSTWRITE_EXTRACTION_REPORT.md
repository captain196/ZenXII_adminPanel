# PHASE 20.3K-STEP1 — Import Golden Master + Post-Write Extraction

**Status:** **COMPLETE — extraction done, equivalence PROVEN by identical traces**
**flag FALSE · Import still NOT wired · P20.2-RACE-1 REMAINS OPEN**
**NOT COMMITTED · NOT PUSHED · NOT DEPLOYED**
**Date:** 2026-08-16 · **Branch:** `integration/phase2` @ `758c924`

---

## A. Baseline

| | Start | End |
| --- | ---: | ---: |
| Tests | **1643** | **1651** |
| Assertions | 5146 | 5169 |
| **Failures** | **10** | **10** |

Staged 4 · stashes 3 · modified 120 — unchanged. Protected files byte-identical throughout
(`daf5f2f1` `c9eff0ca` `73652264` `b597cee2` `14190666`). `atomic_capacity_enabled = false`.

**New tests: 8. New failures: 0.** The same 10 pre-existing failures remain — **not green, not claimed.**

---

## B. Golden master — 15 scenarios, all executing the real method

| Scenario | Branch | Golden observation |
| --- | --- | --- |
| 00 / 00b | seam proof + negative control | doubles are called *by production*; breaking one changes the outcome |
| 01 | happy path | full ordered trace |
| 02 | save failure `:5850` | `saveStudent → releaseClaim`, no delete |
| **03** | **SRNO allocateNext `:5902`** | `deleteStudent` **+** `releaseClaim` |
| **03b** | **SRNO claim `:5874`** (historical) | `srno.resolve → srno.claim → deleteStudent → releaseClaim` |
| **04** | Admission No | **tolerated — row still succeeds, no rollback** |
| **05** | **APAAR claim `:5938`** | `apaar.claim → deleteStudent → releaseClaim` |
| **06** | **monthFee `:6014`** | row skipped, **student NOT deleted** |
| 07 | fail → succeed | row 2 completes fully |
| 08 | succeed → fail | no cross-row contamination |
| **11** | inner subjects loop | row still imports — inner continues stay inner |
| 12 | 3 independent rows | three complete sequences |
| 14 | fail → succeed → fail | exactly **2** `releaseClaim` |
| 15 | mixed | every row accounted once |

All **five** outer-continue branches covered, plus the inner loop.

### Two corrections to the brief's premises

**1. `_allocateAdmissionNumber()` failure is NOT a continue branch.** `:5888` / `:5911` guard with
`if ($importAdmNo !== '')` — the field is simply omitted and the row still succeeds. GOLDEN-04 pins
this. The brief listed it as one of the five; the real fifth is **APAAR claim `:5938`**.

**2. `:6014` does not delete the student** — unlike every other rollback branch. Its own message says
so (*"student doc remains without fee tracking"*). GOLDEN-06 pins that asymmetry.

---

## C. Extraction

**File changed: `application/controllers/Sis.php` only.**

| Item | Value |
| --- | --- |
| Block moved | **`:5853` → `:6131`** (279 lines) |
| Closure | defined above the row loop, per-row values via `compact()`/`extract()` |
| Captures by reference (10) | `$success` `$error` `$skipped` `$feesPending` `$credentials` `$seenPhones` `$seenRolls` `$seenNameDob` `$seatsUsedThisRun` `$subjectCache` |
| Captures by value (4) | `$school_id` `$school_name` `$session_year` `$importMode` |
| Per-row via `$ctx` (16) | `studentId` `studentData` `rowNo` `studentName` `className` `section` `classNumber` `dobKey` `nameKey` `nameNorm` `password` `phone10` `rowRoll` `srNoRaw` `hasFeeChart` `rowData` |
| **`continue` → `return 'SKIP'`** | **4** — `:5874` `:5902` `:5938` `:6014` |
| **Inner continues preserved** | **2** — now `:5741` `:5743`, still `continue;` |

### A THIRD correction — found by a failed first attempt

My first attempt used the block boundary `:5846` and converted **five** continues. It produced a
**parse error**, because `:5846` sits *inside* the `if (!$studentSaved) {` guard — splitting a
statement.

**The save-failure guard (`:5844-5851`) belongs to `saveStudent()`, not the post-write block.** It
stays in the row loop with the caller, so its `continue` at `:5850` is **not** converted. The true
block starts at `:5853` and only **four** continues convert.

The attempt was restored byte-exactly from backup (`b726c08e1670c875`) before retrying — the tree was
never left broken. **This is precisely why the golden master and a backup were built first.**

**No 16-parameter signature and no 30-key object:** `compact()` at the call site and `extract()` in
the closure make the per-row values ordinary locals, exactly as before.

---

## D. Equivalence — the gate

Ordered side-effect traces captured **before** the extraction, then again **after**, and diffed:

```
$ diff before.txt after.txt
  *** IDENTICAL — BEFORE == AFTER ***
```

**14 recorded traces, byte-identical.** Not counters — full ordered sequences of writes, deletes,
identity operations, SRNO calls, fee calls, sync and history, across every branch and multi-row
combination.

```
CURRENT IMPORT BEHAVIOR == EXTRACTED IMPORT BEHAVIOR
```

No difference required rationalising, and no test was adjusted to fit the new implementation.

---

## E. Regression

| Check | Result |
| --- | --- |
| Full suite | **1651 tests, 5169 assertions, 10 failures** |
| Baseline / new / total failures | **10 / 0 / 10** |
| Import golden master | **15/15 pass** |
| `php -l` | clean |

---

## F. Safety

| Item | State |
| --- | --- |
| Protected files | **unchanged** — all five byte-identical |
| `firestore.rules` / `indexes.json` | **untouched** |
| RTDB | none introduced |
| `Section_seat_guard` | **not touched** — 6 refs, unchanged |
| `atomic_capacity_enabled` | **FALSE** |
| `$seatsUsedThisRun` | **semantics unchanged** — by-reference capture, same meaning; NOT reconciled with the seat guard (that is Step 2's job) |
| Live tenant modified | **NO** |
| Identities consumed | **0** |
| Import wired to capacity | **NO** |
| P20.2-RACE-1 | **OPEN** |
| Commit / Push / Deploy | **NO / NO / NO** |

Admission **WIRED** · Enroll **WIRED** · Promotion **WIRED** · Transfer **WIRED** · Import **NOT WIRED**

SRNO audit: **PASS** — ordering unchanged and now *observed* (`saveStudent` → `srno.allocateNext` in
every success trace).

---

## G. Next phase

**PHASE 20.3K-STEP2 — Import destination grouping + atomic capacity integration.** The post-write
routine is now a callable unit, so Step 2 can:

1. Pass 1 — validate + mint + buffer by destination (no write).
2. Pass 2 — one atomic `placeStudentGroup()` per destination; 499 cap; whole-group refusal mapped to
   every affected row.
3. Pass 3 — replay `$postWrite(...)` per committed student.
4. Re-run this same golden master: rows that still import must produce identical traces.
5. Reconcile `$seatsUsedThisRun` with the group-level decision so the two never become competing
   capacity authorities.

**Not started automatically**, per Part 10.

---

```
PHASE 20.3K-STEP1 COMPLETE
GOLDEN MASTER 15/15 · EXTRACTION DONE · TRACES IDENTICAL BEFORE==AFTER
1651 TESTS · 10 BASELINE FAILURES · 0 NEW
IMPORT STILL NOT WIRED · FLAG FALSE · P20.2-RACE-1 OPEN
```
