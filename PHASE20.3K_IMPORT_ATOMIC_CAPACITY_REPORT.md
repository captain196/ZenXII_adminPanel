# PHASE 20.3K — Import Atomic Capacity: Safe Extraction + Grouping

**Status:** **STOPPED (Part 21 — budget) · NO CODE CHANGED**
**4/5 paths wired · flag FALSE and LOCKED · P20.2-RACE-1 REMAINS OPEN**
**NOT COMMITTED · NOT PUSHED · NOT DEPLOYED · UAT NOT EXECUTED**
**Date:** 2026-08-16 · **Branch:** `integration/phase2` @ `758c924`

---

## 1. What this run produced

I did the Part 2 variable/control-flow trace before writing code, as instructed. **It invalidated
the extraction shape my own Phase 20.3j/20.3k reports recommended.** That is the substantive output
of this run, and it would have caused a broken import loop if the next session had followed the
earlier design.

Then I stopped under **Part 21** (insufficient budget to complete *and* verify) and, as that clause
requires, **left the repository unchanged**.

---

## 2. THE BLOCKER MY EARLIER DESIGN MISSED

Phase 20.3k proposed:

```php
$postWrite = function (...) use (...) { /* the existing 370 lines, moved verbatim */ };
```

**That cannot work as written.** The post-write block contains **five `continue;` statements that
control the enclosing row loop**:

```
:5850   save failed        → release STU id, skip row
:5874   SRNO claim failed  → deleteStudent + releaseClaim, skip row
:5902   allocateNext fail  → deleteStudent + releaseClaim, skip row
:5938   (rollback branch)  → skip row
:6014   (fee/validation)   → skip row
```

**A closure cannot `continue` the loop it was called from.** Moving the body verbatim into a closure
is a fatal compile/logic error, not a cosmetic issue. Every one of those five must become an explicit
returned outcome, with the call site doing:

```php
$outcome = $postWrite(...);
if ($outcome === self::ROW_SKIPPED) continue;
```

So the body **cannot** be "moved verbatim" — the phrase used in both prior reports and in Part 3.
Five control-flow conversions are mandatory, and each one sits in a rollback branch, which is
precisely where a mistake silently changes whether a student document is deleted.

*(Two further `continue;` at `:6040` and `:6042` belong to an inner `foreach` over subjects and are
unaffected — they must NOT be converted. A blind find-and-replace would corrupt them.)*

---

## 3. Corrected structural map (measured, current source)

| Property | Value |
| --- | --- |
| `_runStudentImport()` | `:5450` |
| Row loop | `:5530` → **`:6132`** (~600 lines) |
| **Post-write block** | `:5846` → **`:6131`** (~**285** lines) |
| Method return | `:6134` |
| Row-loop `continue`s to convert | **5** (`:5850`, `:5874`, `:5902`, `:5938`, `:6014`) |
| Inner-loop `continue`s to leave alone | 2 (`:6040`, `:6042`) |
| Mutable state needing by-reference capture | **9**: `$success`, `$error`, `$skipped`, `$feesPending`, `$credentials`, `$seenPhones`, `$seenRolls`, `$seatsUsedThisRun`, `$seenNameDob` |

Earlier reports said "370 lines / 30+ locals". Measured: **285 lines and 9 genuinely mutable
captures** — smaller than feared, but with a control-flow constraint nobody had identified.

**`$seatsUsedThisRun` deserves attention in the next run:** the post-write block increments it
(`:6111`) as the running seat projection. Under grouping, the group-level capacity decision and this
per-row counter must not both claim authority, or the two disagree.

---

## 4. Verification — repository unchanged

| Check | Result |
| --- | --- |
| Files changed this phase | **none** |
| Full suite | **1605 tests, 5039 assertions, 10 failures** |
| Baseline **10** · New **0** · Total **10** | not green, as expected |
| Flag | `atomic_capacity_enabled = false` |
| Wiring lock | active, names **import** |
| Protected files | `daf5f2f1` · `c9eff0ca` · `73652264` · `b597cee2` · `14190666` — byte-identical |
| Rules / indexes | untouched |
| Staged / stashes | 4 / 3 |
| Live identities consumed | **0** |

*(`Sis.php` shows modified against HEAD — pre-existing work from earlier phases, not this run.)*

---

## 5. Required declarations

Admission **✅** · Enroll **✅** · Promotion **✅** · Transfer **✅** · **Import ❌**
All five detected? **No — 4/5, lock names import** · Flag **FALSE** · **P20.2-RACE-1 OPEN**
Import grouped? **No** · One atomic commit per destination? yes for transfer/promotion, **not import**
499 cap **enforced** · chunking **absent** · occupancy **student documents only** · CAS conflict
**safe** · generic failure **safe** · identity semantics **untouched** · partial commit **impossible**
· import per-row contract **untouched** · fee/history **untouched** · protected files **unchanged** ·
rules/indexes **unchanged** · live UAT **no** · browser UAT **no** · tests **1605** · baseline
failures **10** · new failures **0** · commit **no** · push **no** · deploy **no**.

---

## 6. Corrected plan for the next run

**Step 1 — extraction with explicit outcomes (not verbatim).**

```php
private const ROW_OK = 'ok', ROW_SKIPPED = 'skipped';

$postWrite = function (string $studentId, array $studentData, array $ctx) use (
    $school_id, $importMode, $sessionYear,
    &$success, &$error, &$skipped, &$feesPending, &$credentials,
    &$seenPhones, &$seenRolls, &$seatsUsedThisRun, &$seenNameDob
) {
    // :5846–:6131 moved, with the FIVE row-loop `continue;` converted to
    // `return self::ROW_SKIPPED;` and the two inner-foreach `continue;` LEFT AS-IS.
    return self::ROW_OK;
};
```

Call site, flag off — behaviour identical by construction:

```php
if (!$studentSaved) { /* existing release + skip, unchanged */ continue; }
if ($postWrite($studentId, $studentData, $ctx) === self::ROW_SKIPPED) continue;
```

Land **only this**, with flag off, and prove equivalence behaviourally before touching grouping.

**Step 2 — grouping** (Pass 1 buffer / Pass 2 atomic group commit / Pass 3 replay `$postWrite`),
resolving the `$seatsUsedThisRun` authority question above.

**Step 3 —** detector marker tied to the real guard call (zero pre-existing occurrences), lock
proven to catch an unwired import, then all five detected.

**Step 4 —** tests, then the separate five-path live UAT phase — which must also finally cover the
two things still unproven anywhere: **PHP REST-path CAS conflict observation** and a **flag-off
equivalence regression**.

---

```
PHASE 20.3K — STOPPED (BUDGET) · NO CODE CHANGED
EXTRACTION SHAPE CORRECTED: 5 CONTROL-FLOW CONVERSIONS REQUIRED
4/5 PATHS WIRED · FLAG FALSE AND LOCKED · P20.2-RACE-1 OPEN
```
