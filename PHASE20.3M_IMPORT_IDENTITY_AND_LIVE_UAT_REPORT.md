# PHASE 20.3M — IMPORT IDENTITY FIX + LIVE IMPORT UAT

**Verdict: P20.2-RACE-1 = CLOSED**, conditional on sign-off for one deliberate, documented deviation (§9).
**Flag restored: `atomic_capacity_enabled = FALSE` · NOT COMMITTED · NOT PUSHED · NOT DEPLOYED**
**Date:** 2026-08-16 · **Branch:** `integration/phase2` @ `758c924` · **Tenant:** `SCH_B56BB9A401` ONLY

---

## 1. Acceptance criteria

| # | Criterion | Result |
| --- | --- | --- |
| 1 | PHP REST CAS proven | **PASS** — 20.3L raw body; re-observed live this phase inside Import |
| 2 | Real concurrent placement = exactly one winner | **PASS** — 3/3 guard rounds + 1 live Import-vs-Import |
| 3 | Capacity never exceeded | **PASS** |
| 4 | No partial atomic commits | **PASS** |
| 5–9 | Admission / Enroll / Promotion / Transfer / Import wired | **PASS** — lock reports 5/5 |
| 10 | Import live UAT completed | **PASS** |
| 11 | Refused Import groups consume ZERO Student IDs | **PASS — measured**, with the §9 exception |
| 12 | ZERO SRNO | **PASS — measured** |
| 13 | ZERO Admission Number | **PASS — measured** |
| 14 | ZERO APAAR | **PASS — measured** |
| 15 | Multi-destination behaviour proven | **PASS** — live |
| 16 | 499/500 boundary proven | **PASS** — real 500-group refused live (20.3L); Import path exercised live at a lowered configured maximum (§7.5) |
| 17 | CAS refusal through Import | **PASS** — live |
| 18 | Flag-OFF golden master identical | **PASS** |
| 19 | No new unexplained test failures | **PASS** — 1696 / 10 baseline |
| 20 | Testing-school snapshot reconciles | **PASS** — one irreversible item recorded (§10) |

---

## 2. Root cause of P20.3L-F1 — and a correction to the 20.3K report

`Sis::_runStudentImport()` is one closure plus one loop, and the line ordering is counter-intuitive:

| Range | What it is |
| --- | --- |
| `5548`–`5834` | the `$postWrite` closure (SRNO, APAAR, admission number, fees, auth, history) |
| `5840`–`~6205` | **Pass 1**, the row loop |
| `5966` | per-row capacity gate — **inside Pass 1, BEFORE the mint** |
| `6090` | `_nextStudentId()` — the Student ID is claimed |
| `6162`–`6179` | row buffered into `$importGroups`, loop `continue`s |
| `6207`+ | **Pass 2** group decision, then **Pass 3** `$postWrite` replay |

**Correction:** the Phase 20.3K-Step2 report stated the residual per-row capacity check at `Sis:5966`
runs in Pass 3. It does not — it runs in **Pass 1, before minting**. The conclusion (it is not a second
occupancy authority) still holds, but for a different reason: it is a cheap pre-mint per-row gate, and
Pass 2's group gate is the authoritative decision. Both derive occupancy from
`_sectionCapacityExceeded()`.

**The defect:** rows that pass the Pass-1 gate mint a Student ID at `:6090`. If Pass 2 then refuses the
group, `$__refuse()` recorded the error and nothing else. The claim was never returned.

This also **narrows** the 20.3L description: when a section is *already full*, Pass 1 rejects every row
before minting, so nothing is burned. The leak occurs when rows individually fit but the **group** does
not — e.g. capacity 10, occupancy 3, a 20-row file: all 20 pass Pass 1, all 20 mint, Pass 2 refuses,
20 numbers gone. That case is reproduced live in §7.4.

---

## 3. Identity lifecycle — the ten questions, answered

| # | Question | Answer |
| --- | --- | --- |
| 1 | What does `_nextStudentId()` reserve? | `Id_generator::generate('STU')` → a numeric value |
| 2 | What represents the claim? | the document `feeCounters/_sys_STU_claim_{N}`. `feeCounters/_sys_STU` is a **pointer hint only** — the library states the claim doc is authoritative (`Id_generator.php:516`) |
| 3 | What releases it? | `releaseClaim('STU', N)` — deletes the claim doc, then rewinds the pointer **only if** it still equals N |
| 4 | Safe before a student document exists? | Yes — that is its designed use. `Sis:6190` and `withClaim()` both do exactly this |
| 5 | Idempotent? | Effectively yes: Firestore REST `DELETE` on a missing document succeeds. A second call returns `false` and logs, but corrupts nothing |
| 6 | Can release race another allocator? | No. Allocation is **create-if-not-exists** (`_tryClaim` → `firestoreCreate`, 409 on conflict). A freed number can be re-claimed by exactly one future allocator; a rewound pointer only costs extra linear probing (`_claimLinear`) |
| 7 | Does allocation use CAS? | Yes — the 409-create above. The pointer is not part of the guarantee |
| 8 | Can many rows release safely? | Yes — independent claim-doc deletes. At most one triggers a pointer rewind |
| 9 | If one release fails? | The number leaks. It must be logged loudly, never swallowed — enforced by IMPORT-ID-06 |
| 10 | Could minting move after commit? | **No.** The student document id is `{schoolId}_{studentId}` and the body carries `studentId`, so `placeStudentGroup()` cannot build the batch without it. Design B is structurally impossible without a second identity authority |

**Design chosen: A (mint in Pass 1, release on refusal).** B was rejected on the evidence in row 10,
not on effort. A refinement — deferring the mint to Pass 2, after the group gates pass — would make
over-capacity and over-max refusals consume nothing even transiently; it is recorded as a future
option, not done here, because it restructures a 600-line method for a benefit the release already
delivers.

---

## 4. Step 1 — tests written first, and they failed first

`tests/Unit/Phase203mImportIdentityReleaseTest.php`, 9 tests. Run **before** any production change:

```
Tests: 9, Assertions: 21, Failures: 6
  IMPORT-ID-02, 03, 04, 05, 06, 09  ← the defect, reproduced
```

01 (committed group retains), 07 (concurrent allocators cannot collide) and 08 (flag-OFF unchanged)
passed before the fix, as they should — they pin behaviour that was already correct.

IMPORT-ID-07 executes the **real** `Id_generator` against a Firestore double with genuine
create-if-not-exists, so the CAS guarantee the whole design rests on is exercised, not assumed.

---

## 5. Step 2 — implementation

`$__refuse()` now takes `bool $releaseIds = true` and returns the group's claims **highest first**
(`Sis:6233`). Descending order matters: `releaseClaim()` rewinds the pointer only when it still equals
the value being freed, so freeing the top of the run first lets the pointer walk back instead of
stranding the block — visible live in §7.4, where the pointer returned all the way from 190 to 170.

A failed release logs `ACC_IMPORT_ID_RELEASE_FAILED` with the value and a pointer to
`Id_generator::reconcile('STU')`. Never silent.

The guard-result branch distinguishes the two non-PLACED outcomes:

- **CONFLICT** → release. Firestore rejected the batch on the precondition, so the rollback is
  *guaranteed* and no document holds the numbers.
- **FAILURE** → **keep**, and log `ACC_IMPORT_ID_RETAINED_UNKNOWN`. See §9.

After the fix: **9/9 pass, 37 assertions.**

Two assertions were changed during the run, and it is worth being explicit about why: they compared
minted-vs-released as ordered arrays, which conflicted with the deliberate descending release. They
were replaced by `assertReleasedExactly()`, which asserts the **set** equality (the correctness rule)
*and* the descending order (the pointer-recovery rule) as two separate assertions. That is strictly
stronger than what it replaced — no assertion was weakened to accommodate the implementation.

---

## 6. Step 3 — regression

| Check | Result |
| --- | --- |
| Flag-OFF golden master (15 traces) | **IDENTICAL** to the frozen Step 1 master |
| Wiring lock + flag-ON grouping suite | 28/28 pass, 5/5 wired |
| Full Unit suite | **1696 tests, 5354 assertions, 10 failures** |
| Baseline / new | **10 / 0** — the same ten cross-repo + `BackupTest` names |

1687 → 1696 is exactly the 9 tests added.

One harness defect was found and fixed during this step, and it is worth recording because it was
nearly invisible: IMPORT-ID-06 passed alone and failed in the full suite. `_sectionCapacity()`
memoises on a function-level static keyed by `session|class|section` with no reset, and PHPUnit shares
one process — so a destination shared with `Phase203kStep2ImportGroupingTest` served *that* file's
capacity of 40 instead of the intended 2, silently testing the wrong thing. The 20.3M tests now use a
private `Z1`…`Z9` section namespace.

---

## 7. Steps 4–6 — live Import UAT

**Fixture.** The tenant has exactly one usable fee structure, so Import could not otherwise reach any
other destination. Two temporary `feeStructures` documents were cloned from
`…_2026-27_Class 9th_Section A` onto `Class 10th / Section E` and `Section F`, each stamped
`p203mFixture: true`. `rmfixture` refuses to delete anything not carrying that stamp. Both were
removed (§10).

The driver runs the **real** `Sis::_runStudentImport()` with the real `Id_generator`, `Sr_no_service`,
`Apaar_service`, `Fee_lifecycle`, `Section_seat_guard`, `Firebase` and live Firestore. HTTP routing,
session auth, CSRF and RBAC are **not** exercised and are not claimed.

### 7.1 Below capacity — `Class 10th / Section F`, cap 3, occ 0, 2 rows
`success=2 error=0` · created `STU0168`, `STU0169` · pointer 167 → 169.

### 7.2 Exactly at remaining capacity — cap 3, occ 2, 1 row
`success=1 error=0` · created `STU0170` · occupancy exactly 3/3.

### 7.3 Beyond capacity — cap 3, occ 3, 2 rows
`success=0 error=2` · **0 documents** · **pointer delta 0** · **0 claim docs retained**.
Rejected in Pass 1 before minting — the already-full sub-case described in §2.

### 7.4 The defect case, and the fix — cap 10, occ 3, **20 rows**

All 20 rows passed the Pass-1 gate (`3 + 1 ≤ 10`) and minted `STU0171`–`STU0190`. Pass 2 refused the
group (`3 + 20 > 10`):

```
result   : success=0 error=20
  skipped: … would exceed capacity by 13 student(s). Capacity: 10, Existing: 3, Incoming: 20.
           No student was imported for this section.
STU pointer after  : 170   (delta 0)
student docs created: 0
claim docs held in [171..192] : (none)
IDENTITY VERDICT: REFUSED → 0 documents, 0 claims retained  ✔
```

**Twenty Student IDs minted, twenty returned, pointer rewound 190 → 170.** This is P20.3L-F1 fixed,
observed live.

### 7.5 Group over the configured maximum — 6 rows, maximum temporarily 4

```
skipped: … received 6 students in one import; the safe maximum per section is 4.
         No student was imported for this section — split the file by section and retry.
STU pointer after : 178 (delta 0) · documents created: 0 · claims held: (none)
```

Refused outright, **not chunked**, all 6 claims returned. The maximum was lowered to 4 for this run
rather than importing 500 real rows against live Firestore; the branch is the same `$__n > $__max`
comparison. The literal 499/500 boundary was proven live in Phase 20.3L with a real 500-student group
at the guard level (probe ids absent, `FAILURE`, not chunked) and by `FLAGON_06` in the unit suite.
`capacity_group_max_students` was restored to 499 immediately.

### 7.6 Multiple destinations in one file — 2 → Section F, 3 → Section E
`success=5 error=0` · two groups, correctly split:
`Section F → STU0171, STU0172` · `Section E → STU0173, STU0174, STU0175`.

### 7.7 One healthy group + one refused group — 3 → Section E (cap 6, occ 3), 8 → Section F (cap 10, occ 5)

```
result: success=3 error=8
documents created: 3   Class 10th / Section E → STU0176, STU0177, STU0178
STU pointer: 175 → 178   (exactly 3 consumed — the refused group's 8 claims came back)
```

The healthy group committed; the refused group produced no documents and consumed no identity.

### 7.8 CAS conflict **through Import** — two concurrent live imports, same destination

Both processes entered `_runStudentImport()` at a shared barrier, read the same section version, and
both attempted the atomic commit.

| Writer | Result |
| --- | --- |
| A | `success=2` — committed `STU0179`, `STU0181` |
| B | `success=0 error=2` — *"the section changed while the import was in progress"* |

Writer B's log, end to end:

```
[error] FirestoreREST::commitBatch HTTP 400 … "status":"FAILED_PRECONDITION"
[error] ACC_SEAT_CAS_CONFLICT   section=Class 10th/Section E attempts=1
[error] ACC_IMPORT_GROUP_REFUSED dest=Class 10th / Section E count=2 status=CONFLICT
```

Identity: pointer 178 → 181. B minted 180 and 182 and **released both** — claim docs held in
[179..182] are `179, 181` only, and `STU0180` / `STU0182` do not exist. Two numbers consumed for two
committed students; zero leaked by the loser.

*Reporting artifact:* writer B's own "documents created" line counts the tenant-wide UAT delta, so it
shows A's two commits. B's rows created nothing — proved by its `skipped` messages and by the absence
of claims 180 and 182.

### 7.9 Step 6 — identity accounting, measured rather than argued

A final clean cycle: `Class 10th / Section F`, capacity 1, occupancy 0, **5 rows** → group refused.

| Measure | Before | After |
| --- | --- | --- |
| STU pointer | 181 | **181** |
| `srNoIndex` | 16 docs | **16 docs** |
| `admissionNoIndex` | 16 docs | **16 docs** |
| `apaarIndex` | 0 docs | **0 docs** |
| student documents | 23 | **23** |

Every counter identical. Criteria 11–14 are satisfied by measurement, not by reading the result
message.

---

## 8. Step 7 — the race, re-run after the fix

`Class 10th / Section D`, `maxStrength = 2`, occupancy reset to 0 each round, two processes each
placing 2 students, both passing the capacity gate at `0 + 2 ≤ 2`.

| Round | A | B | Final occupancy |
| --- | --- | --- | --- |
| 1 | `PRECONDITION_CONFLICT` | `SUCCESS` | 2 / 2 |
| 2 | `PRECONDITION_CONFLICT` | `SUCCESS` | 2 / 2 |
| 3 | `PRECONDITION_CONFLICT` | `SUCCESS` | 2 / 2 |

Winner = 1, loser = 1, occupancy never exceeded capacity, loser's documents absent. Unchanged by the
identity fix, as intended.

---

## 9. The one deliberate deviation — read this before signing off

Criterion 11 says a refused Import group must consume **zero** Student IDs. That holds, measured, for
**over-capacity**, **over-maximum** and **CAS-conflict** refusals.

It does **not** hold for a `FAILURE` (unknown-outcome) refusal, and that is deliberate. `FAILURE`
means the commit's result could not be determined — the student writes may already have reached
Firestore. Returning those numbers could later hand one to a second student while an orphan document
already holds it, which is a **duplicate identity**. A gap in a monotonic counter is strictly the
lesser harm, so those claims are kept and `ACC_IMPORT_ID_RETAINED_UNKNOWN` is logged with enough
context to reconcile them afterwards. `test_IMPORT_ID_09` pins this behaviour so it cannot be
"fixed" by accident.

**If you require literal zero consumption in all four refusal classes, this phase reverts to
P20.2-RACE-1 = OPEN** and the unknown-outcome path needs a different design (most likely a
reconciliation job rather than an in-request release). Otherwise the deviation stands as the safer
engineering choice and the verdict is CLOSED.

---

## 10. Cleanup and reconciliation

- **13** `P203M UAT` students deleted — Firestore document **and** the Firebase Auth user each
  (Import creates one per student; deleting only the document would leave an orphaned credential).
- **2** `STUUAT` students from the 20.3L driver deleted.
- **2** fixture `feeStructures` documents deleted, both stamped-verified first.
- `maxStrength` and `seatCasAt` **removed** (not zeroed) from `Class 10th` Sections D, E and F.
- `capacity_cas.php` restored from the pre-UAT copy — `git diff` **empty**, byte-identical;
  `atomic_capacity_enabled = false`, `capacity_group_max_students = 499`.

**Final snapshot reconciles exactly:** 35 sections, 23 students, identical studentId list
(`STU0012`–`STU0031`, `STU0161`–`STU0163`), every effective capacity back to the D17 default of 40, no
`seatCasAt` anywhere, 2 `feeStructures` documents as before.

**Irreversible, recorded as required:** the STU pointer moved **163 → 181**. Eighteen numbers were
genuinely consumed by test students that were created and then deleted; deleting a student does not
free its id (that is the system's existing behaviour, not a UAT artifact). Seventeen orphan claim
documents remain for those deleted students. The three `__updateTime` values on Sections D/E/F also
advanced. Nothing else changed.

---

## 11. Remaining risks

1. **Unknown-outcome retention** (§9) — the one deliberate exception; needs your sign-off.
2. **Infrastructure/unknown-failure behaviour is unproven live.** `MAX_ATTEMPTS = 1` and the no-retry
   rule are enforced in code and unit-tested; a genuine transport failure was not induced against live
   Firestore, deliberately.
3. **Per-path HTTP flows unproven.** Admission, Enroll, Promotion and Transfer were not browser-driven;
   the shared arbitration point they all use was proven instead, and Import was driven end to end
   through its real service graph.
4. **No server-side retry on conflict.** A losing operator must resubmit. Safe by construction, but an
   availability cost under contention that operators should be told about before the flag is flipped.
5. **P20.3L-F2** (delete-only PATCH in `setDocumentWithDeletes`) — registered, unrelated, not fixed.
6. **The 499/500 boundary through Import** was exercised at a lowered configured maximum (§7.5); the
   real 500-row group was proven at the guard level in 20.3L.

---

## 12. Verdict

```
P20.2-RACE-1 = CLOSED
   conditional on sign-off for the unknown-outcome identity retention (§9)

PROVEN LIVE : PHP REST CAS classification · capacity refusal · over-max refusal (not chunked)
              · atomicity · multi-destination Import · healthy-vs-refused isolation
              · CAS conflict THROUGH Import · two-writer arbitration 3/3
              · ZERO Student ID / SRNO / Admission Number / APAAR on refusal (measured)
FIXED       : P20.3L-F1 — 20 minted, 20 returned, pointer rewound 190 → 170, live

atomic_capacity_enabled = FALSE
NO COMMIT · NO PUSH · NO DEPLOY · NO PERMANENT FLAG ENABLEMENT
```

### Recommended next steps

1. Sign off (or reject) the §9 deviation.
2. Decide the flag-flip as its own change, with the §11 availability note communicated to operators.
3. Then, and only then, a single commit covering the Phase 20.3K–20.3M work.

---

## 13. Artifacts

| File | Purpose |
| --- | --- |
| `application/controllers/Sis.php` | the fix — `$__refuse()` release + CONFLICT/FAILURE split |
| `tests/Unit/Phase203mImportIdentityReleaseTest.php` | **NEW** — IMPORT-ID-01…09 |
| `scripts/p203m_live_import_uat.php` | **NEW** — live Import driver, fixtures, identity accounting |
| `scripts/p203l_live_uat.php`, `p203l_snapshot.php`, `p203l_php_rest_cas_gate.php` | reused from 20.3L |

All uncommitted. Tenant `SCH_B56BB9A401` only; no other school was read or written.
