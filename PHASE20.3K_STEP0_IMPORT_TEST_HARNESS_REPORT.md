# PHASE 20.3K-STEP0 — Executable Import Test Harness + Golden Master

**Status:** **COMPLETE — harness built, `_runStudentImport()` genuinely executes**
**NO PRODUCTION CODE CHANGED · flag FALSE · P20.2-RACE-1 REMAINS OPEN**
**NOT COMMITTED · NOT PUSHED · NOT DEPLOYED**
**Date:** 2026-08-16 · **Branch:** `integration/phase2` @ `758c924`

---

## 1. Executive summary

The blocker that stopped Phase 20.3K-Step1 is **removed**. `Sis::_runStudentImport()` — 600 lines
that no test in the repository could reach — now executes under PHPUnit, deterministically, with
programmable dependencies and recorded golden masters.

**The harness proved itself on first contact by finding a real production bug** (§5) and by
**observationally confirming** the identity ordering that Phase 20.3e-1 had only inferred from
reading source: `fs.saveStudent` runs **before** `srno.allocateNext`.

**`Sis.php` is byte-identical to baseline (`b726c08e…`). No production file was modified.**

---

## 2. Baseline and final

| | Baseline (start) | Final |
| --- | ---: | ---: |
| Tests | **1630** | **1643** |
| Assertions | 5117 | 5146 |
| **Failures** | **10** | **10** |

**New tests: 13** (6 probe + 7 harness). **New failures: 0.** The same 10 pre-existing failures
remain — **the suite is not green and is not claimed to be.**

> **Baseline drift is not mine.** The brief expected ~1615; measured 1630 at start. Other sessions
> added tests during and between phases (`MapsPlatformInheritanceTest.php` appeared at 17:23 in the
> previous run). Recorded, not treated as my change — same class of event as **P20-F14**.

---

## 3. The seam

| Step | Mechanism | Why |
| --- | --- | --- |
| 1 | Declare minimal `CI_Controller` stub | resolves the parent chain without CI3 core |
| 2 | Declare `Firebase` stub | `Sis:4537` calls `Firebase::authEmail()` **statically** |
| 3 | `require` MY_Controller.php + Sis.php | **READ-ONLY** — neither modified; MY_Controller stays protected |
| 4 | `newInstanceWithoutConstructor()` | bypasses MY_Controller's constructor (loads firebase/session/helpers, emits HTTP headers, can `exit`) |
| 5 | `ReflectionMethod::setAccessible(true)` | **production visibility unchanged — still `private`** |
| 6 | Inject doubles via reflection | `fs`, `firebase`, `id_generator`, `srnoSvc`, `entity_sync`, `feeLifecycle`, `load`, `session`, `studentHistorySvc`, `capability` + scalars |

### What is deliberately NOT faked

Only **services** are doubled. The controller's own helpers — `_nextStudentId()`,
`_allocateAdmissionNumber()`, `_fee_structure_missing()`, `_createFirebaseAuthStudent()`,
`_sectionCapacityExceeded()`, `_rollNoConflict()` — are **private and therefore cannot be stubbed by
subclassing**; they execute **for real** against the doubles. The golden master reflects true control
flow, not a mocked approximation.

**No network. No Firestore. No tenant. `SCH_B56BB9A401` untouched. 0 identities consumed.**

---

## 4. Golden masters — recorded from the CURRENT implementation

Traces are **ordered side-effect sequences**, not just counts (Phase 4 requirement).

**HARNESS-01 — one valid row → `success=1 error=0`**
```
id_generator.generate → fs.saveStudent → srno.allocateNext → fs.update →
fs.updateEntity → fees.assignInitialFees → fs.schoolWhere →
firebase.createFirebaseUser → firebase.setCanonicalClaims → fs.set →
entity_sync.syncStudent → entity_sync.syncParent → history.init → history.log
```

**HARNESS-02 — save fails → `success=0 error=1`**
```
id_generator.generate → fs.saveStudent → id_generator.releaseClaim
```
Confirms `Sis:5847`: a failed save **releases the claimed Student ID** and performs nothing further.

**HARNESS-07 — row 1 fails, row 2 succeeds** *(the critical one)*
```
… fs.saveStudent → id_generator.releaseClaim →        ← row 1 rolled back
  id_generator.generate → fs.saveStudent → srno.allocateNext → … → history.log   ← row 2 completes
```
**Proves a failing row does not abort the loop** — the property the upcoming `continue` → outcome
conversion must preserve.

**HARNESS-08 — row 1 succeeds, row 2 fails.** Full success sequence, then
`fs.saveStudent → releaseClaim`. No cross-row contamination.

**HARNESS-12 — three rows.** Three complete independent sequences.

### Identity ordering — now observed, not inferred

Every successful trace shows **`fs.saveStudent` → `srno.allocateNext`**. Phase 20.3e-1 established
this by reading source; it is now demonstrated by execution. That is the property the atomic-capacity
work depends on: a refusal before the write cannot orphan an SRNO.

---

## 5. NEW FINDING — P20.3K-F1 (LOW) — found by the harness on first run

`Sis:5467`:

```php
$dupPolicy = in_array(($opts['dupPolicy'] ?? 'skip'), ['create','skip','update'], true)
    ? $opts['dupPolicy'] : 'skip';
```

When `dupPolicy` is absent the `??` makes the **condition** true, so the true-branch reads the
**undefined key** — emitting `Undefined array key "dupPolicy"` and yielding `null`, **not the
intended `'skip'`**. The comment above it explicitly states the default was changed to `'skip'`
because defaulting to `'create'` once *"silently duplicated the whole roster"* — that intent is not
achieved on this path.

**Severity LOW, and honestly so:**

| | |
| --- | --- |
| Live path `import_commit()` (`:6854`) | passes `dupPolicy` explicitly — **unaffected** |
| Only opts-less caller `import_students()` (`:5431`) | documented in-code as *"confirmed dead/unreachable via any current UI"* |
| Behavioural impact when it does fire | **none** — `null` satisfies both `!== 'create'` (`:5576`) and `!== 'update'` (`:5608`) exactly as `'skip'` does |

So: a PHP warning on a dead route, not a data defect. **NOT FIXED** — Phase 7 forbids modifying
Import production code. Registered for a future scoped cleanup.

The harness passes `dupPolicy` explicitly, mirroring the live caller.

---

## 6. Harness self-verification (Phase 5 requirement)

| Requirement | Evidence |
| --- | --- |
| Method genuinely entered | `HARNESS-00` asserts the real result contract keys and non-empty call log |
| Doubles called **by production**, not the test | `id_generator.generate` appears in the trace — emitted from `_nextStudentId()` |
| Tests fail when a double changes | `HARNESS-00b` negative control: breaking `id_generator` changes the outcome |
| Not asserting test-generated values | outcomes derive from production branching |
| No source-grep tests | every harness test executes the method |
| No browser / no live Firestore / no tenant writes | ✅ |
| Deterministic | fixed IDs, no randomness, no clock dependence |

---

## 7. `$seatsUsedThisRun` — documented, unchanged

Read/incremented at `Sis:6111` inside the post-write block as the **per-import running seat
projection**, feeding `_sectionCapacityExceeded(..., $seatsUsed + 1)` at `:5657`.

**Not modified, not reconciled with `Section_seat_guard` in this phase** — that reconciliation is the
grouping phase's job, and is the one place the two could become competing capacity authorities.

---

## 8. Required declarations

| Question | Answer |
| --- | --- |
| Tests genuinely invoking `_runStudentImport()` | **7** |
| New tests / assertions | **13** / 29 |
| Baseline → final tests | **1630 → 1643** |
| Baseline / new / final failures | **10 / 0 / 10** |
| Production code changed | **NO** — `Sis.php` `b726c08e…` identical |
| Protected files changed | **NO** — `daf5f2f1` `c9eff0ca` `73652264` `b597cee2` `14190666` |
| rules / indexes changed | **NO** |
| Live tenant modified | **NO** |
| Identities consumed | **0** |
| `atomic_capacity_enabled` | **FALSE** |
| P20.2-RACE-1 | **OPEN** |
| Import wired | **NO** |
| Commit / Push / Deploy | **NO / NO / NO** |

Admission **WIRED** · Enroll **WIRED** · Promotion **WIRED** · Transfer **WIRED** · Import **NOT WIRED**

SRNO audit: **PASS** — no identity code touched; ordering observed and unchanged.

---

## 9. Coverage: delivered vs outstanding

**Delivered (7 executing):** 00 seam proof · 00b negative control · 01 valid row · 02 save-failure
rollback · 07 fail-then-succeed · 08 succeed-then-fail · 12 multi-row independence.

**Outstanding** — reachable with this harness, not yet written: 03 SRNO-claim failure · 04
Admission-No failure · 05 rollback branch · 06 fee/validation branch (`:6014`) · 09 duplicate
policy · 10 supplied-SRNO · 11 inner subjects-loop `continue` · 13/14/15 combinations.

Three of the five outer-loop `continue` branches are covered (`:5850` via 02/07/08; loop-continuation
via 07/08/12). The remaining branches need doubles programmed to fail at `srno.claim`,
`_allocateAdmissionNumber` and `fs.updateEntity(monthFee)` — mechanical now that the seam exists.

**I am not claiming all 15 scenarios.** The seam — the actual blocker — is done and proven.

---

## 10. Next phase

**Phase 20.3K-Step1 (retry) — extraction, now verifiable:**

1. Add the remaining branch tests (03–06, 09–11, 13–15) against the **current** implementation to
   complete the golden master.
2. Extract the post-write block into the closure, converting the **five** outer-loop `continue`s at
   `:5850 / :5874 / :5902 / :5938 / :6014` into returned outcomes, leaving the **two** inner
   `$rawList` continues at `:6040 / :6042` untouched.
3. Re-run the golden master. **Identical traces = proven equivalence**, not asserted.
4. Then grouping (Step 2), then five-path live UAT.

---

```
PHASE 20.3K-STEP0 COMPLETE — HARNESS BUILT AND PROVEN
_runStudentImport() EXECUTES UNDER PHPUNIT · GOLDEN MASTER RECORDED
NO PRODUCTION CODE CHANGED · 1643 TESTS · 10 BASELINE FAILURES · 0 NEW
P20.2-RACE-1 REMAINS OPEN
```
