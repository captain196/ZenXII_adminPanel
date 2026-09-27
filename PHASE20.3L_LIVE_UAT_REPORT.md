# PHASE 20.3L — FIVE-PATH LIVE FIRESTORE UAT

**Verdict: P20.2-RACE-1 = OPEN**
**The CAS mechanism is PROVEN live. Import is NOT certified, and a new defect blocks acceptance.**
**Flag restored: `atomic_capacity_enabled = FALSE` · NOT COMMITTED · NOT PUSHED · NOT DEPLOYED**
**Date:** 2026-08-16 · **Branch:** `integration/phase2` @ `758c924` · **Tenant:** `SCH_B56BB9A401` (Vikrant Public) ONLY

---

## 0. Executive summary

| Acceptance criterion | Result |
| --- | --- |
| 1. PHP REST CAS conflict observed and correctly classified | **PASS** — raw body captured verbatim |
| 2. All five paths operate through the mechanism | **PARTIAL** — wiring locked + unit-proven; live proof covers the shared arbitration point, not each path's HTTP flow |
| 3. Capacity refusal proven safe | **PASS** — live |
| 4. CAS conflict proven safe | **PASS** — live, 3/3 rounds |
| 5. Infrastructure failure proven safe | **NOT TESTED LIVE** — cannot be safely induced against live Firestore |
| 6. No partial grouped commit | **PASS** — live |
| 7. No unwanted identity consumption on refusal | **FAIL** — **P20.3L-F1** |
| 8. Concurrent real placement cannot exceed capacity | **PASS** — live, 3/3 rounds |
| 9. Import grouping proven live | **NOT DONE** — blocked, see §7 |
| 10. Before/after snapshots reconcile | **PASS** — exact |
| 11. No new unexplained test failures | **PASS** |
| 12. Flag restores cleanly | **PASS** — byte-identical file |

Two criteria are unmet, so the phase reports **OPEN**. The mechanism itself did not fail once.

---

## 1. Part 1 — pre-UAT safety check

| Check | Result |
| --- | --- |
| Branch / HEAD | `integration/phase2` @ `758c924` |
| `atomic_capacity_enabled` at start | **false** |
| Protected files vs the Step-2 digests | **all five unchanged** — `MY_Controller.php ecc08e75` · `Firestore_service.php ed0750f0` · `rbac_helper.php d6ca2adf` · `firestore.rules 4f9ed6e1` · `firestore.indexes.json c473c914` |
| Other sessions modifying relevant code | none detected during the run |
| Tenants touched | `SCH_B56BB9A401` only. `SCH_D94FE8F7AD` and every other school: **not read, not written** |

**Pre-UAT snapshot** (`scripts/p203l_snapshot.php`, read-only — grep-verified to contain no write call):

- 35 sections, all session `2026-27`
- 23 student documents (22 active), ids `STU0012`–`STU0031`, `STU0161`–`STU0163`
- **No section in this tenant carries `maxStrength`** → every effective capacity is the D17 default of **40**
- No `seatCasAt` marker anywhere
- Highest studentId suffix: `STU0163`; no `srNo` or admission-number field present on any student document

Occupancy is 0–3 per section. Testing "at" and "beyond" capacity against a default of 40 would have required ~40 real student documents, so the UAT set a small `maxStrength` on **two empty sections** — the same field the Sections & Seats capacity editor writes — and removed it afterwards (§9).

---

## 2. Part 2 — PHP REST CAS gate (MANDATORY) — **PASS**

`scripts/p203l_php_rest_cas_gate.php`, on `sections/SCH_B56BB9A401_2026-27_Class 10th_Section F`.

| Step | Observed |
| --- | --- |
| A. capture U1 | `2026-06-09T17:21:40.027006Z` |
| B. commit with CURRENT U1 | `{"status":"SUCCESS","code":200}` → U2 = `2026-08-16T13:29:14.238883Z` |
| E1. commit with STALE U1, raw REST | **HTTP 400** |
| E2. same commit via `commitBatchStatus()` | `{"status":"PRECONDITION_CONFLICT","code":400,…}` |
| Post-check | U3 == U2 — the stale commit wrote **nothing** |

**Raw response body, verbatim — observed, not inferred from documentation:**

```json
{
  "error": {
    "code": 400,
    "message": "the stored version (1786886954238883) does not match the required base version (1781025700027006)",
    "status": "FAILED_PRECONDITION"
  }
}
```

The full chain is therefore confirmed on the path the application actually uses:

```
Firestore REST 400 + FAILED_PRECONDITION
   → FirestoreRestClient::commitBatchStatus()
   → PRECONDITION_CONFLICT   (NOT FAILURE — the distinction the refusal contract depends on)
   → Section_seat_guard → CONFLICT → safe refusal
```

The `⚠ NOT VERIFIED AGAINST LIVE FIRESTORE` caveat in `Firestore_rest_client.php:1176` is now discharged.

---

## 3. Part 3 — controlled flag enablement

`atomic_capacity_enabled` set to `true` for the UAT only, with the original file preserved byte-for-byte and restored at the end (§10). Every scenario banner printed the flag state it actually observed, so no result was recorded under an assumed flag value.

---

## 4. What the live driver executes — and what it does not

`scripts/p203l_live_uat.php` builds the **real** object graph and runs the **real** code against **live** Firestore:

**Real:** `MY_Controller::_sectionCapacity()` · `_sectionActiveCount()` · `_filterSectionRoster()` · `_sectionCapacityExceeded()` · `_sectionCapacityMessage()` · `Section_seat_guard` · `Entity_firestore_sync::buildStudentDoc()` · `Firebase` · `FirestoreRestClient` · Firestore.

**Not exercised, stated plainly:** HTTP routing, session auth, CSRF and RBAC. Those sit *above* the placement decision and cannot alter it, but this is not a browser-driven UAT and is not claimed to be one.

One scenario per OS process, deliberately: `_sectionCapacity()` and `_cachedStudentDocsForRollCheck()` memoise on function-level statics with no reset, so one process per scenario is both the faithful production model and the only way each scenario gets a fresh read.

**Test identities** use the fixed prefix `STUUAT…`. The Student ID generator, SR-number service and admission-number allocator are **not invoked** by this driver, so no tenant counter was advanced by it and every document it created is exactly enumerable.

---

## 5. Part 4 — capacity behaviour, live

Section `Class 10th / Section F`, `maxStrength` temporarily set to **3**, starting occupancy **0**.

| # | Scenario | Gate | Guard | Documents | Verdict |
| --- | --- | --- | --- | --- | --- |
| A | 1 student, below capacity (cap 3, occ 0) | ALLOW | `PLACED` count=1 | `STUUAT01` present | **PASS** |
| B | exactly at remaining capacity (cap 3, occ 1, incoming 2) | ALLOW | `PLACED` count=2 | `STUUAT10`, `STUUAT11` present | **PASS** |
| C | one beyond capacity (cap 3, occ 3, incoming 1) | **REFUSE** `{capacity:3,current:3,incoming:1,projected:4,over:1}` | not attempted | **none created** | **PASS** |
| — | group of **500** vs max **499** | n/a | `FAILURE` — refused outright | probe ids `STUUAT1000/1001/1498` all absent | **PASS — not chunked** |

Operator message produced by the real `_sectionCapacityMessage()`:

> Class 10th Section F is full. Capacity: 3, Current students: 3. Please select another available section.

Occupancy reached exactly 3/3 and **never exceeded it**.

---

## 6. Part 6 — concurrency, the P20.2-RACE-1 test — **PASS ×3**

Section `Class 10th / Section E`, `maxStrength = 2`, occupancy reset to **0** before every round.
Two OS processes, each placing **2** students, released at a shared wall-clock barrier.

Both writers independently read `occ=0`, both passed the capacity gate (`0 + 2 ≤ 2`), and **both were honest placements** — the gate was enforced, not bypassed. Pre-fix, this is precisely how occupancy became 4/2.

| Round | Writer A | Writer B | Final occupancy | Capacity |
| --- | --- | --- | --- | --- |
| 1 | `PRECONDITION_CONFLICT` | `SUCCESS` | **2** | 2 |
| 2 | `SUCCESS` | `PRECONDITION_CONFLICT` | **2** | 2 |
| 3 | `PRECONDITION_CONFLICT` | `SUCCESS` | **2** | 2 |

Winners = 1, losers = 1, every round. The winner alternated, so there is no systemic ordering bias. The loser's students (`STUUAT50`, `STUUAT51`) were verified **absent** from Firestore — the batch rolled back whole, including the student writes.

Firestore's own words to the loser, from the application log:

```
[error] FirestoreREST::commitBatch HTTP 400 ops=3
body={"error":{"code":400,"message":"the stored version (1786887480837279) does not
match the required base version (1786887317203343)","status":"FAILED_PRECONDITION"}}
```

**Occupancy never exceeded `maxStrength` in any round.** This is the application-path evidence the phase was run for.

*Scope note, stated rather than glossed:* all five placement paths converge on one arbitration point — `Section_seat_guard::commitPlacement()`. The paths differ only in how they assemble the destination group, never in how the race is decided. This test proves the arbitration. It does not separately prove Admission-vs-Enroll, Import-vs-Transfer and so on as distinct HTTP flows, and those are **not** claimed.

---

## 7. Part 5 — Import live UAT: **NOT PERFORMED**

Two independent blockers, both found before anything was run:

**Blocker 1 — the tenant cannot support a multi-destination live import.**
`Sis::_fee_structure_missing()` (`Sis:603`) aborts every import row whose destination has no fee structure. `SCH_B56BB9A401` has exactly **one**: `SCH_B56BB9A401_2026-27_Class 9th_Section A`. A live import can therefore only ever reach one destination, which makes Part 5 items 2, 8 and 9 (multiple destinations, one refused + one healthy group, per-group postWrite) **untestable on this tenant** without first creating fee structures — a data change well outside a UAT's remit.

**Blocker 2 — a real defect, found by tracing the live path.** See below.

Import grouping therefore remains proven only by the 14-test flag-ON suite from Phase 20.3K-Step2. That is unit evidence, not live evidence, and acceptance criterion 9 is **not met**.

### P20.3L-F1 — a refused Import group leaks the Student IDs it minted (**MEDIUM**)

Established by tracing the flag-ON control flow, with exact line numbers:

| Line | What happens |
| --- | --- |
| `Sis:6090` | **Pass 1** calls `_nextStudentId()` → `id_generator->generate('STU')` — the global counter is **claimed** |
| `Sis:6162`–`6179` | the row is buffered into `$importGroups` and the loop `continue`s |
| `Sis:6244` / `6252` / `6267` | **Pass 2** refuses the group (over-max / over-capacity / CAS conflict) and calls `$__refuse()` |
| `Sis:6233`–`6238` | `$__refuse()` appends to `$skipped` and increments `$error` — **and nothing else** |

The only `releaseClaim` on the import path is `Sis:6190`, inside the **flag-OFF** `saveStudent`-failure branch. Under grouping it is unreachable, so every Student ID minted for a refused group is burned and never returned.

**Impact:** the STU counter is global and monotonic, so this produces **gaps, not collisions** — no duplicate identity, no data corruption. But it directly contradicts the Part 7 expectation *"0 Student ID consumed by the refused placement"*, and it trips the stop condition *"any refused placement consumes identity unexpectedly"*.

**Not fixed** — this run is a validation phase, and the fix (release claimed ids in `$__refuse()`, or defer minting until after the group commits) is a design decision that belongs in its own step.

SR numbers, admission numbers and APAAR ids are **not** affected: all three are claimed inside `$postWrite`, which Pass 3 runs only after a committed group.

---

## 8. Parts 7 & 8 — identity accounting and atomicity

**Identity consumed by this UAT: zero.**

| Measure | Before | After |
| --- | --- | --- |
| Student documents in tenant | 23 | 23 |
| Highest studentId suffix | `STU0163` | `STU0163` |
| Distinct `srNo` values | 0 | 0 |
| Distinct admission numbers | 0 | 0 |
| `schoolControl` document | unchanged (identical content; only JSON key order differs between dumps) | — |

The driver never calls the allocators, so no counter moved. Peak transient footprint was 7 `STUUAT…` documents; the tenant read 30 students at that moment (23 pre-existing + 7), confirming nothing pre-existing was disturbed.

**Atomicity — no partial group, in any scenario:**

| Event | Group size | Documents after |
| --- | --- | --- |
| `PLACED` (below capacity) | 1 | 1 of 1 |
| `PLACED` (exact capacity) | 2 | 2 of 2 |
| capacity REFUSE | 1 | **0** |
| over-max REFUSE (500) | 500 | **0** |
| CAS `PRECONDITION_CONFLICT` ×3 | 2 each | **0 each** |

There was no instance of "N of M committed" anywhere in the run.

---

## 9. Part 9 — cleanup and reconciliation

- All 7 `STUUAT…` documents deleted. Cleanup keys on the `STUUAT` prefix, which the Student ID generator can never mint (it emits `STU<digits>`). Pre-existing students were never candidates.
- `maxStrength` and `seatCasAt` **removed** (not zeroed) from `Class 10th / Section E` and `Section F`, restoring the exact pre-UAT field shape. Both sections read back `maxStrength = NULL`, `seatCasAt = absent`, effective capacity **40**.
- Post-UAT snapshot vs pre-UAT snapshot: **35 sections, 23 students, identical per-section occupancy, identical studentId list**. The only textual difference is JSON key ordering inside the `schoolControl` dump — same keys, same values.

Residual delta: the `__updateTime` of the two test sections advanced (unavoidable — they were written to and restored). No other document in the tenant changed.

### P20.3L-F2 — `setDocumentWithDeletes()` cannot express a delete-only patch (**LOW, pre-existing, NOT fixed**)

With `$setData` empty, `Firestore_rest_client.php:1546` encodes `fields` as a JSON **list** and Firestore rejects the request:

```
HTTP 400 {"error":{"status":"INVALID_ARGUMENT",
"message":"Invalid value at 'document' (Map), Cannot bind a list to map for field 'fields'."}}
```

Unrelated to atomic capacity; per Part 11 it is registered, not fixed. The cleanup issued the equivalent PATCH itself, inside the UAT script only.

---

## 10. Part 10 — flag restoration — **PASS**

`application/config/capacity_cas.php` restored from the pre-UAT copy; `git diff` on the file is **empty**, i.e. byte-identical. A fresh process reads `atomic_capacity_enabled = false`. `capacity_group_max_students` remains 499.

---

## 11. Test counts and failures

No production code was modified in this phase, so the Phase 20.3K-Step2 suite result stands unchanged: **1687 tests, 5317 assertions, 10 known baseline failures, 0 new**. The wiring lock still reports **5/5** with the flag FALSE.

No unexplained failure appeared at any point in the run.

---

## 12. Remaining risks

1. **P20.3L-F1** — Student IDs burned by a refused Import group. Must be resolved before Import can be certified.
2. **Import is unproven live.** The tenant's single fee structure blocks a meaningful live import; either the fixture is extended or Import ships on unit evidence alone, which this phase does not endorse.
3. **Infrastructure/unknown-failure behaviour is unproven live.** `MAX_ATTEMPTS = 1` and the no-retry rule are enforced in code and unit-tested; inducing a genuine transport failure against live Firestore was not attempted, deliberately.
4. **Per-path HTTP flows unproven.** Admission, Enroll, Import, Promotion and Transfer were not each driven through the browser stack; the shared arbitration point they all use was proven instead.
5. **No server-side retry on conflict.** A losing operator must resubmit. Safe by construction (the loser writes nothing), but it is an availability cost under contention that operators should be told about before the flag is flipped.

---

## 13. Verdict

```
P20.2-RACE-1 = OPEN

PROVEN LIVE : PHP REST CAS classification · capacity refusal · over-max refusal
              (never chunked) · atomicity · two-writer arbitration (3/3, winners=1)
              · occupancy never exceeds capacity
UNMET       : criterion 7  — P20.3L-F1, Student IDs consumed by a refused group
              criterion 9  — Import grouping not proven live (fee-structure blocker)
              criterion 5  — infrastructure failure not induced live

atomic_capacity_enabled = FALSE
NO COMMIT · NO PUSH · NO DEPLOY · NO PERMANENT FLAG ENABLEMENT
```

### Next, in order

1. Resolve **P20.3L-F1** — release the claimed Student IDs on group refusal, or defer minting until after the commit.
2. Decide how Import gets live coverage: extend the tenant's fee-structure fixture, or accept unit evidence explicitly and record that decision.
3. Re-run Parts 5 and 7 only, then re-assess criteria 7 and 9.

---

## 14. Artifacts

| File | Purpose |
| --- | --- |
| `scripts/p203l_snapshot.php` | read-only pre/post tenant snapshot |
| `scripts/p203l_php_rest_cas_gate.php` | Part 2 mandatory gate, raw REST body capture |
| `scripts/p203l_live_uat.php` | live scenario driver (`state`/`below`/`exact`/`over`/`overmax`/`race`/`list`/`cleanup`/`setcap`/`restore`) |

All three are new, uncommitted, and touch `SCH_B56BB9A401` only.
