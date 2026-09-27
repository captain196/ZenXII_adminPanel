# SRNO-F1 FIX + SRNO IDENTITY AUDIT

**Phase A (SRNO-F1): COMPLETE · Phase B (audit): COMPLETE, read-only · Phase C (scholarship design): NOT STARTED**
**`USE_ENTITLEMENT_BILLING` = FALSE · `REAL_ADMISSION_READY` = FALSE · no commit, push or deploy**
**Date:** 2026-08-17 · **Branch:** `integration/phase2` @ `758c924`

---

# PHASE A — SRNO-F1

## A1. Files changed

| File | Change |
| --- | --- |
| `application/controllers/Sis.php` | `_claimIdentityAttributes()` — new out-parameter + honest warning text; `save_admission()` — fail-closed branch with compensation |
| `tests/Unit/PhaseSrnoF1AdmissionIdentityTest.php` | **NEW** — 11 regression tests |

Nothing else. `Fee_lifecycle.php`, `Fee_generation_service.php`, `Fees.php`, all configs — untouched.

## A2. Exact control-flow change

**Signal (the helper).** `_claimIdentityAttributes()` gained a fifth parameter,
`bool &$srnoAssigned = true`, cleared in **both** failure paths — the `ok === false` branch and the
`catch`. An exception is no less a failure than a returned error; the SRNO does not exist either way.

The misleading text is gone. It previously said *"…The admission was completed; SRNO requires
administrator follow-up."* — the helper asserting an outcome that is the caller's to decide, and which
is now false. It reports the failure and nothing more.

**Defaulting to `true` keeps every existing caller byte-compatible**, which is precisely why
`enroll_student()` is untouched by this change (§A6).

**Enforcement (the caller).** `save_admission()` now branches:

```php
$srnoAssigned = true;
$identityWarnings = $this->_claimIdentityAttributes($userId, $school_id, $apaarInput, $studentData, $srnoAssigned);

if (!$srnoAssigned) {
    log_message('error', "ACC_ADMISSION_SRNO_FAILED student=[…] school=[…] — admission REFUSED and rolled back…");
    try { $this->entity_sync->deleteStudent($userId); } catch (\Throwable $e) { /* logged */ }
    $this->id_generator->releaseClaim('STU', (int) preg_replace('/\D/', '', $userId));
    $this->_recompute_section_strength($classKey, $sectionKey);
    return $this->json_error('Admission could not be completed: an SR Number could not be allocated…');
}
```

## A3. Rollback / atomicity

**Why this fail point is safe** — at the SRNO claim, these exist: student document, parent record,
phone index, section strength, `monthFee`, subjects. These do **not**: Firebase Auth user, entitlement,
fee demands, history/audit entry, admission-fee receipt and accounting.

Nothing financial and no credential has been created. `test_SRNO_F1_07` asserts that ordering against
all five later writes, so a future reorder that moved money above this line **fails the build**.

**Compensation mirrors the proven Import contract** (`Sis:6190`, pinned by
`Phase203kStep0ImportHarnessTest::test_GOLDEN_03_srno_allocate_failure_rolls_back_student`): delete the
student, release the Student ID. A refused admission therefore burns **no identity**.

**Section strength is additionally recomputed**, because this same method inflated it earlier in the
request. Leaving it would permanently consume a seat for a student who does not exist — corrupting the
very data the atomic-capacity guard reads.

**Deliberately NOT undone, and reported rather than hidden: the phone index and the parent record.**
Both are shared, append-shaped structures — the phone index is explicitly append-never-overwrite so
siblings can share a number. Removing this student's entry risks damaging a *different* student's data.
A wrong repair is worse than a known residue. Registered as **SRNO-F3 (LOW)**.

This is compensation, not a true transaction: the student write is CAS-committed by the atomic-capacity
guard, so it can only be undone by a subsequent delete. That is stated plainly rather than described as
"atomic".

## A4. Tests

| | |
| --- | --- |
| Before the fix | 11 tests, **6 failing** — F1-01…F1-05, F1-07 |
| After | **OK — 11 tests, 54 assertions** |
| Full Unit suite | **1806 tests, 5721 assertions, 10 failures** |
| Baseline / new | **10 / 0** |

1795 → 1806 is exactly the 11 added. Your eight required proofs map to: F1-09 (success unchanged),
F1-03 (returns failure), F1-01 (throws → failure), F1-04 (no orphan), F1-05 (Student ID lifecycle),
F1-10 (Admission Number consistent), F1-08 (billing not falsely reported), F1-09 + full suite (existing
behaviour unchanged).

## A5. Safety

Live data **not touched** · rules/indexes **not touched** · **no RTDB** · no flags changed ·
**no commit, push or deploy**.

## A6. SRNO-F2 (registered, NOT fixed) — `enroll_student()`

The same invariant is violated on the second admission path, but the fix is **not narrow** there.
Verified ordering in `enroll_student()`: `_claimIdentityAttributes()` runs **after**
`_createFirebaseAuthStudent()` and **after** `assignInitialFees()`. Failing closed would require undoing
a credential and real fee demands — a reordering, i.e. exactly the "redesign unrelated Admission
behaviour" you prohibited.

`test_SRNO_F1_11` asserts that ordering, so the finding cannot quietly disappear. It needs its own
authorisation.

---

# PHASE B — SRNO IDENTITY AUDIT (read-only)

`scripts/srno_identity_audit.php` — grep-verified free of every write call. Nothing modified,
backfilled, repaired or deleted.

## B1. ⚠ A correction to my own first run

My first pass reported **16 `INDEX_MALFORMED`** and **0 orphans**. That was **the audit being wrong,
not the data**: I assumed `srNoIndex` keyed the student as `studentId` and carried a `session`. The
verified shape is:

```json
{ "srNo": "1", "currentStudentId": "STU0164", "historicalStudentIds": [],
  "history": [...], "schoolId": "SCH_B56BB9A401", "claimedAt": ..., "claimedBy": ... }
```

Doc id is `{schoolId}_{srNo}`; the student is `currentStudentId`; there is **no session field** — by
design, since an SRNO is school-scoped and survives re-admission via `historicalStudentIds[]`.

I verified before reporting precisely because "100% malformed" is the signature of a wrong assumption.
Corrected numbers below.

## B2. Census (re-established now; concurrent sessions may have changed it)

| Measure | Count |
| --- | --- |
| Total students | **150** |
| `srNoIndex` documents | **16** |
| Students **with** an SRNO on the student record | **0** |
| Students **without** | **150** |

| Tenant | Students | Missing SRNO |
| --- | --- | --- |
| `SCH_218AAF5C23` | 126 | **126** |
| `SCH_B56BB9A401` | 23 | **23** |
| `SCH_B9161BBE3C` | 1 | **1** |

## B3. Integrity violations

| Category | Count |
| --- | --- |
| `STUDENT_WITHOUT_SRNO` | **150** |
| `INDEX_POINTS_TO_MISSING_STU` | **16** |
| `SRNO_ON_STUDENT_NOT_INDEXED` | 0 |
| `INDEX_STUDENT_MISMATCH` | 0 |
| `DUPLICATE_SRNO` | 0 |
| `DUPLICATE_INDEX_MAPPING` | 0 |
| `INDEX_WRONG_SCHOOL` | 0 |
| `INDEX_MALFORMED` | 0 |
| **Total** | **166** |

**Verdict: the invariant is VIOLATED for 150 of 150 students. No student record in any tenant carries
an SRNO field at all.**

## B4. The two findings, explained

**B4.1 — 150/150 students have no SRNO.** Not a partial gap; the field is universally absent. Consistent
with SRNO-F1: admission was designed to complete without one. Whether these predate SRNO entirely, or
each hit the warning path, is not determinable from the student documents alone.

**B4.2 — the 16 orphaned index records are MINE, and I under-reported them.** All 16 carry
`claimedBy: "P203M_UAT"`, `reason: "admission"`, pointing at `STU0164`–`STU0181` — the students I
created during the **Phase 20.3M live Import UAT** and then deleted. My cleanup removed the student
documents and the Firebase Auth users but **left the SRNO claims behind**. I recorded the STU-counter
consumption as irreversible at the time; **I did not flag the index residue, and I should have.**

They are confined to the authorised testing tenant and to test students. Nothing belonging to a real
school is affected.

## B5. Proposed remediation — NOT executed

| # | Item | Proposal | Risk |
| --- | --- | --- | --- |
| R1 | 16 orphaned `srNoIndex` records (mine) | Delete the 16 whose `currentStudentId` matches no student **and** `claimedBy = 'P203M_UAT'` — doubly-keyed so no real claim can match | Low. But it frees SR numbers 1–16 for reuse in that tenant — confirm that is wanted before running |
| R2 | 150 students without an SRNO | **Do not mass-backfill.** Allocating 150 SR numbers invents school-facing identifiers that the schools' own paper records may contradict | High if done blindly — the SRNO is what the school works by |
| R3 | Enforce going forward | Already done by SRNO-F1 for `save_admission()`; SRNO-F2 (`enroll_student`) still open | — |
| R4 | Existing students | Offer an **operator-driven** assign-SRNO action, so a human confirms the number per student | Low, and it respects that schools own their numbering |

**Recommendation: R1 and R3/R4 yes; R2 no.** A mass backfill would fabricate the one identifier schools
treat as authoritative. Nothing is executed and nothing is authorised.

---

## Current state

| Item | Value |
| --- | --- |
| Tests before / after | 1795 → **1806** (11 added) |
| Baseline / new failures | **10 / 0** |
| Files changed | `Sis.php`; 1 new test file; 1 new read-only audit script |
| Live data | **None touched** |
| Rules / indexes | **Not by me.** `firestore.rules` moved again via concurrent sessions: `ae0da664` → **`b2e78398`**; `firestore.indexes.json c473c914` unchanged |
| RTDB | **None** |
| Flags | unchanged · `USE_ENTITLEMENT_BILLING = false` |
| Commit / push / deploy | **NO / NO / NO** |
| `REAL_ADMISSION_READY` | **FALSE** |

`Sis.php` is now `28af0b4a`.

---

## Exact next phase

**Phase C — scholarship technical design.** Not started. It is a substantial design piece (schema,
allowed values, validation, the whole-session weighted allocation algorithm, mid-session, annual heads,
exempt/service exclusion, the eligible-gross cap, unused-award handling, deterministic rounding,
generation ≡ recalculation, session scoping) and you twice warned against inventing a rule silently. It
deserves its own pass rather than being appended here, and I will produce it as the next deliverable.

Also open for your authorisation: **SRNO-F2** (`enroll_student`), **SRNO-F3** (phone-index/parent
residue), and **R1** (delete my 16 orphaned index records).
