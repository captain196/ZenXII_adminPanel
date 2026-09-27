# ATOMIC CAPACITY — ENABLEMENT READINESS REPORT

**Status: READY FOR AUTHORIZATION — flag NOT enabled, awaiting explicit instruction**
**`atomic_capacity_enabled = FALSE` · NOT COMMITTED · NOT PUSHED · NOT DEPLOYED**
**Date:** 2026-08-16 · **Branch:** `integration/phase2` @ `758c924` · **Test tenant:** `SCH_B56BB9A401` only
**P20.2-RACE-1:** CLOSED (engineering/design), unknown-outcome retention policy **APPROVED** by the operator

---

## 1. The five protected placement paths

Every path that can consume a seat in a section:

| # | Path | Where the guard is invoked | Group shape |
| --- | --- | --- | --- |
| 1 | **Admission** | `Sis.php` — `save_admission` site | single student |
| 2 | **Enroll** | `Sis.php` — `enroll_student` / `saveStudent()` boundary | single student |
| 3 | **Import** | `Sis.php:6217` — Pass 2 of `_runStudentImport()` | one group per destination section |
| 4 | **Promotion** | `Student_write_service::batchMoveStudents()` | one group per destination section |
| 5 | **Transfer** | `Classes.php` — bulk transfer | one group per destination section |

All five converge on one arbitration point: `Section_seat_guard::commitPlacement()`, which commits the
student write(s) and the section CAS marker in a single Firestore batch guarded by
`currentDocument.updateTime`.

---

## 2. Flag gating and the partial-enablement lock

- Single switch: `application/config/capacity_cas.php` → `atomic_capacity_enabled` (currently **false**).
  There is deliberately **no per-path flag**.
- Every one of the five call sites is gated on `Section_seat_guard::enabled()`.
- `capacity_group_max_students = 499` (499 students + 1 marker = Firestore's 500-write limit).
- **The lock:** `Phase203hAtomicCapacityWiringTest::test_FLAG_cannot_be_enabled_until_all_five_paths_are_wired`
  fails the build if the flag is ever set true while any path is unwired. Detection is by
  per-path source markers chosen to have zero pre-existing occurrences, so a generic word cannot
  false-positive an unwired path as protected.

Current lock state, verified this session:

```
Admission WIRED · Enroll WIRED · Transfer WIRED · Promotion WIRED · Import WIRED   →  5/5
vendor/bin/phpunit --filter "Phase203h…|Phase203kStep2…|Phase203m…"  →  OK (37 tests, 137 assertions)
atomic_capacity_enabled = false        capacity_group_max_students = 499
```

Protected files unchanged throughout Phases 20.3K–20.3M:
`MY_Controller.php ecc08e75` · `Firestore_service.php ed0750f0` · `rbac_helper.php d6ca2adf` ·
`firestore.rules 4f9ed6e1` · `firestore.indexes.json c473c914`.

---

## 3. Final live evidence

All against `SCH_B56BB9A401`, real code, live Firestore.

| # | Evidence | Result |
| --- | --- | --- |
| 1 | PHP REST `FAILED_PRECONDITION` observed | **PASS** — HTTP 400, `"status":"FAILED_PRECONDITION"`, *"the stored version … does not match the required base version"*, classified `PRECONDITION_CONFLICT` |
| 2 | Concurrent race passed | **PASS** — 6 rounds total (3 in 20.3L, 3 re-run in 20.3M) |
| 3 | Exactly one winner | **PASS** — every round; winner alternated, so no ordering bias |
| 4 | Capacity never exceeded | **PASS** — final occupancy equalled capacity in every round, never above |
| 5 | No partial atomic commits | **PASS** — loser's documents verified absent; no "N of M" in any scenario |
| 6 | Import CAS conflict | **PASS** — two concurrent live imports; B logged `ACC_SEAT_CAS_CONFLICT` → `ACC_IMPORT_GROUP_REFUSED status=CONFLICT`, wrote nothing |
| 7 | Import multi-destination | **PASS** — 2→Section F, 3→Section E in one file, split into two groups |
| 8 | Over-capacity | **PASS** — whole group refused before any commit; 0 documents |
| 9 | Over-maximum without chunking | **PASS** — refused outright; guard-level 500-group (20.3L) and Import-level over-max (20.3M) both left zero documents |
| 10 | Student ID release on deterministic refusal | **PASS** — 20 minted, 20 returned, pointer rewound 190 → 170 |
| 11 | SRNO / Admission Number / APAAR refusal accounting | **PASS — measured**: refused group of 5 left `srNoIndex` 16→16, `admissionNoIndex` 16→16, `apaarIndex` 0→0, students 23→23, STU pointer 181→181 |
| 12 | Flag-OFF golden master identical | **PASS** — 15/15 traces identical to the frozen Step 1 master |
| 13 | Full suite, 0 new failures | **PASS** — 1696 tests, 5354 assertions, 10 failures, all pre-existing baseline names |

Healthy-vs-refused isolation also passed live: 3 committed to one destination while 8 were refused at
another, in the same import, with exactly 3 identities consumed.

---

## 4. Status classification

### PROVEN (live, measured)

- PHP REST CAS conflict classification end to end.
- Two-writer arbitration: exactly one winner, 6/6 rounds, occupancy never above capacity.
- Full-batch rollback — the loser writes nothing, including its student documents.
- Import: below capacity, exactly at capacity, over capacity, over maximum, multi-destination,
  healthy-plus-refused, and CAS conflict.
- Zero Student ID / SRNO / Admission Number / APAAR consumed on a deterministic refusal.
- Group refusal reports **every** row of the group; no silent success.
- Flag-OFF behaviour byte-for-byte unchanged (golden master).

### REGISTERED BUT NOT FIXED

| ID | Summary |
| --- | --- |
| **P20.3L-F2** | `Firestore_rest_client::setDocumentWithDeletes()` encodes `fields` as a JSON list when `$setData` is empty, so a delete-only PATCH fails `INVALID_ARGUMENT`. Unrelated to atomic capacity. Not touched, per instruction. |
| **P20.3K-F1** | `Sis:5467` `dupPolicy` undefined-key notice. LOW; `null` behaves identically to `'skip'`. |
| **P20-F2 / F3 / F4 / F5** | Capacity-semantics findings from Phase 20 (incl. the unreachable "unlimited" branch). |
| **P20-F11**, **P20-F13**, **P20-F14** | Phase 20 findings; F14 is a concurrent-session edit to `firestore.rules`. |
| **P20.2-CAP-4 / CAP-5** | Phase 20.2 capacity-surface findings. |
| **P20.2-RBAC-1** | Phase 20.2 RBAC finding. |

None of these are blockers for enablement; none were modified in this phase.

### NOT TESTED

1. **Browser-level UAT for Admission, Enroll, Promotion and Transfer.** See §5.
2. **Infrastructure / unknown-outcome failure, live.** A genuine transport failure was not induced
   against live Firestore — deliberately. `MAX_ATTEMPTS = 1` and the no-retry rule are enforced in code
   and covered by unit tests (`FLAGON_08`, `IMPORT-ID-09`).
3. **The literal 499/500 boundary through a live Import.** Proven at the guard level with a real
   500-student group (20.3L) and through the Import code path with the configured maximum lowered to 4
   (20.3M). The branch is the same `$__n > $__max` comparison; a 500-row live import was not run.
4. **Sustained/production-scale contention.** Races were two writers, six rounds — enough to prove the
   arbitration, not to characterise behaviour under heavy load.

### ACCEPTED RISKS

1. **Unknown-outcome identity retention — APPROVED.** A `FAILURE` refusal deliberately **retains** its
   Student IDs and logs `ACC_IMPORT_ID_RETAINED_UNKNOWN` with reconciliation context. A non-gapless
   Student ID sequence is acceptable; a duplicate Student identity is not. Pinned by
   `test_IMPORT_ID_09` so it cannot be "fixed" by accident.
2. **No server-side retry on conflict.** The losing operator must resubmit. Safe by construction — the
   loser writes nothing — but an availability cost under contention. Operators should be told that
   *"another change to this section completed while your request was in progress"* means **retry**, not
   **failure**. Server-side retry remains impossible while `_sectionCapacity()` and
   `_cachedStudentDocsForRollCheck()` memoise on unresettable statics in the protected `MY_Controller`.
3. **Student IDs are never freed by deletion.** Pre-existing lifecycle behaviour, unchanged by this
   work. The UAT's own consumption (pointer 163 → 181, 17 orphan claim documents for deleted test
   students) is recorded as irreversible and has been left exactly as it is, per instruction.
4. **Group-granular partial commit remains the Import contract.** With the flag on, a file spanning
   several sections commits per destination: one section can succeed while another is refused. This is
   the documented behaviour, tested live, and is a change in *granularity* from the flag-off row-level
   behaviour that operators should be aware of.

---

## 5. Scope limitation — stated explicitly

**Browser-level UAT for Admission, Enroll, Promotion and Transfer was NOT performed.**

What was live-tested is the **shared arbitration/placement layer**: `_sectionCapacityExceeded()` /
`_sectionCapacity()` / `_filterSectionRoster()` from `MY_Controller`, `Section_seat_guard`,
`Entity_firestore_sync::buildStudentDoc()`, `Firebase`, `FirestoreRestClient` and Firestore itself.
Import alone was additionally driven end to end through its complete real service graph, including the
real `Id_generator`, `Sr_no_service`, `Apaar_service` and `Fee_lifecycle`.

**Not exercised for any path:** HTTP routing, session authentication, CSRF, and RBAC. These sit above
the placement decision and cannot change its outcome, but no claim is made about them.

The four non-Import paths differ from Import only in **how the destination group is assembled**, never
in how contention is arbitrated — that is one shared function. The residual risk is therefore in each
path's own request handling and group assembly, not in the capacity or CAS mechanism.

---

## 6. Recommended enablement sequence — for authorization, not execution

1. Operator authorizes the flag flip explicitly, as its own change.
2. Set `atomic_capacity_enabled = true`; confirm the wiring lock still passes at 5/5.
3. Communicate to operators: a *"section changed while your request was in progress"* message means
   **retry**; and an Import file spanning multiple sections now succeeds or fails **per section**.
4. Soak on the testing tenant, then a single commit covering Phases 20.3K–20.3M.
5. Optional follow-ups, none blocking: browser-level UAT for the four non-Import paths; a 500-row live
   Import; a reconciliation job for `ACC_IMPORT_ID_RETAINED_UNKNOWN` events; deferring the Student-ID
   mint from Pass 1 to Pass 2 so deterministic refusals consume nothing even transiently.

---

## 7. Current state

```
atomic_capacity_enabled = FALSE          capacity_group_max_students = 499
5/5 paths wired · wiring lock ACTIVE · 37/37 gate tests pass
Full suite 1696 / 5354 assertions / 10 pre-existing failures / 0 new
Protected files unchanged · rules and indexes untouched
Testing tenant reconciled · flag never left enabled outside a controlled run

NO COMMIT · NO PUSH · NO DEPLOY · FLAG NOT ENABLED
```

**Awaiting explicit authorization to set `atomic_capacity_enabled = true`. It will not be changed
autonomously.**
