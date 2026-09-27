# PHASE 20.3b — Firestore Create: Collision / Failure Disambiguation

**Status:** COMPLETE · **NOT COMMITTED · NOT PUSHED · NOT DEPLOYED · UAT NOT EXECUTED**
**Date:** 2026-08-16 · **Branch:** `integration/phase2` @ `758c924`
**Scope:** P20.3-CLAIM-1 only. P20.3c (Option B atomic capacity) explicitly **not** started.

---

## 1. Executive summary

`createDocument()` returned `false` for an HTTP **409** (the document already exists — a
business-level contention result) **and** for every infrastructure failure: 500, 401, malformed
request, timeout, DNS failure, connection reset. Callers could not tell *"someone else owns this
key"* from *"the write did not happen and I do not know why."*

**Fixed** by adding a status-aware sibling that classifies the outcome, leaving the boolean API
byte-for-byte unchanged for its **32 other call sites**.

| | Result |
| --- | --- |
| New API | `createDocumentStatus()` → `SUCCESS \| COLLISION \| FAILURE` |
| Existing API | **unchanged signature and behaviour** — 32 callers untouched |
| Callers adapted | **1** — `Sis::suggest_roll_no()` |
| Protected files modified | **none** — `Firestore_service.php` avoided entirely |
| Rules / indexes | **untouched** |
| Tests | +25 (1559 → 1584); **10 failures = unchanged baseline** |

**The invariant now holds: an unknown outcome is never reported as contention.**

---

## 2. The original defect (Part 1 — independently re-verified)

Re-traced from source this phase, not taken from the Phase 20.3 report.

**Call chain:**
```
Sis::suggest_roll_no()  →  Firebase::firestoreCreate()  →  Firestore_rest_client::createDocument()
```

**Pre-change body** (`Firestore_rest_client:924-936`):
```php
if ($r['code'] >= 200 && $r['code'] < 300) return true;
if ($r['code'] === 409) return false;   // doc exists — caller must handle
if (function_exists('log_message')) log_message('error', "…HTTP {$r['code']}…");
return false;                            // ← every other failure ALSO false
```

**How the caller interpreted `false`** (`Sis:4095-4101`, verbatim):

> *"Claim collision (another operator got here first) OR a stale claim from an abandoned earlier
> suggestion — either way this number is not usable right now; try the next one."*

That reading is correct **only** for a genuine 409. On a timeout or 500 it silently skipped a
genuinely free roll number, drifting suggestions upward — the same class of defect ROLL-5 already
fixed once for abandoned claims.

**Why it blocked Phase 20.3c:** a seat-claim loop must claim slot `occupancy + 1`. Reading `false`
as "taken" during a transient outage would advance to slot 41 on a 40/40 section — and that create
would **succeed**, causing the exact overflow the mechanism exists to prevent.

---

## 3. Firestore HTTP / transport semantics (Part 4 / 6)

`Firestore_rest_client::request()` (`:436-480`) discards `curl_errno`/`curl_error` but returns
`CURLINFO_HTTP_CODE`, which is **0** when `curl_exec` received no response at all.

**That is sufficient to classify without touching `request()`** — a shared function used by every
Firestore operation in the system. Per Part 6 (*"do not over-engineer if the repository's HTTP layer
already exposes status information"*), no transport change was made.

| Condition | Code | Status |
| --- | :-: | --- |
| Created | 2xx | **SUCCESS** |
| Document already exists | **409** | **COLLISION** |
| Timeout / DNS / connection reset | **0** | **FAILURE** |
| Malformed request | 400 | FAILURE |
| Auth denied | 401 / 403 | FAILURE |
| Server error | 5xx | FAILURE |
| Anything else | * | FAILURE |
| Write suppressed by `_blockWrite` guard | — | **FAILURE** (a suppressed write proves nothing about ownership) |

**COLLISION is returned for 409 and for nothing else.** Verified exhaustively by test across codes
0–600.

A seven-category taxonomy was considered and rejected as over-engineering: no caller needs to
distinguish 401 from 500 — both mean *"stop, you learned nothing."*

---

## 4. New result contract (Part 4)

```php
Firestore_rest_client::createDocumentStatus(string $collection, string $docId, array $data): array
// → ['status' => 'SUCCESS'|'COLLISION'|'FAILURE', 'code' => int, 'message' => string]

Firebase::firestoreCreateStatus(...): array   // passthrough; uninitialised client → FAILURE
```

Constants: `CREATE_SUCCESS`, `CREATE_COLLISION`, `CREATE_FAILURE`.

A plain array was chosen over an exception hierarchy or a result object because it matches the
repository's established convention (`_dep_count_safe()`, `_stream_active_students()` and
`_section_active_students()` all return structured arrays).

**A 409 is no longer logged as an error** — contention is a normal outcome for every claim-style
caller, and logging it as an error was noise.

---

## 5. Backward-compatibility strategy (Part 5)

**33 call sites were enumerated across the whole application** before any edit:

`Sr_no_service:155,246` (**SRNO**) · `Id_generator:537` (**Student ID**) · `Admission_no_service:166`
· `Apaar_service:102` · `Id_card_service:609` · `Fee_sharded_counter:144` · `Fee_firestore_txn:207` ·
`Accounting_firestore_sync:404` · `Accounting_idempotency:85` · `Billing_integrity:115,224` ·
`B2_registry_service:1361,1699,1713` · `Student_history_service:87` · `FeeWorker:344` · `Ptm:858` ·
`Comm_notice_migration:189` · `Sis_admission_backfill:236` · `Sis_history_backfill:190` ·
`B2_foundation_verify:392` · `B2_lifecycle_job:101` · `Firestore_service:793,831,1016` ·
`Sis:4026` (adapted) · plus doc-comment references.

Changing the boolean return shape would have broken identity generation across nearly every module.

**Chosen approach:** add the status-aware method; make `createDocument()` a thin wrapper over it.

```php
public function createDocument(string $collection, string $docId, array $data): bool
{
    return $this->createDocumentStatus($collection, $docId, $data)['status'] === self::CREATE_SUCCESS;
}
```

Signature identical; `true` on 2xx and `false` otherwise — **byte-for-byte the previous observable
behaviour**. Asserted by test.

> **`Firestore_service.php` — a PROTECTED file — was NOT modified.** Its three call sites use
> `$this->client->createDocument()`, whose contract is unchanged. Verified byte-identical
> (`c9eff0ca9338a04f…`).

---

## 6. Affected callers (Part 13)

| Classification | Count | Callers |
| --- | ---: | --- |
| **SAFE** — boolean semantics unchanged | **32** | all sites listed in §5 except `Sis:4026` |
| **NEEDS ADAPTATION** — makes a safety decision on the result | **1** | `Sis::suggest_roll_no()` |
| **BROKEN** | **0** | — |

Only the one caller was adapted. **No other module was touched**, per Part 13.

**Registered, not fixed:** several SAFE callers are claim-style and would benefit from the status API
later — `Sr_no_service`, `Id_generator`, `Apaar_service`, `Fee_sharded_counter`,
`Accounting_idempotency`, `B2_registry_service`. Their loops are structured differently (most fail
outright rather than walking candidates), so the roll-number failure mode does not apply identically.
**P20.3b-FOLLOWUP-1 — LOW.** Reviewing them is a separate, scoped exercise; expanding into identity
generation now would have crossed into another major module (a Part 13 STOP).

---

## 7. Roll-number behaviour — before / after (Part 7)

| Outcome | Before | After |
| --- | --- | --- |
| Created | claim the number, return it | **unchanged** |
| **409** | "not usable — try next" | **try next** (unchanged, and now correct by construction) |
| **500 / 4xx** | "not usable — try next" ❌ | **STOP** — return an error, candidate not consumed |
| **Timeout** | "not usable — try next" ❌ | **STOP** — return an error |
| **Network failure** | "not usable — try next" ❌ | **STOP** — return an error |
| Exception thrown | logged; `$claimed` stayed false → try next ❌ | classified FAILURE → **STOP** |

Operator-facing message on failure:

> *"Could not reserve a Roll Number right now — the service did not respond. Nothing was assigned.
> Please retry, or enter a Roll Number manually."*

Logged distinctly as `ACC_ROLL_CLAIM_INFRA_FAILURE` with scope, candidate and HTTP code — separable
from contention in log aggregation.

**Bonus correctness:** the ROLL-5 expiry re-read (`if (!$claimed)`) now runs **only** on a definitive
collision. Previously it also fired after infrastructure failures, performing a pointless extra read
against a service that had just failed.

### Roll policy unchanged (Part 8)

Roll numbering, existing-roll preservation, conflict semantics, `ROLL_CLAIM_TTL_SECONDS`, SRNO
behaviour and admission behaviour are **untouched**. Only the interpretation of the create result
changed. `_rollNoConflict()` remains the save-time authority.

---

## 8. Tests (Part 9)

**New:** `tests/Unit/Phase203bCreateErrorSemanticsTest.php` — **25 tests, 652 assertions, passing.**

| ID | Case | Result |
| --- | --- | :-: |
| T1 | 200/201 → SUCCESS | ✅ |
| T2 | 409 → COLLISION | ✅ |
| T3 | 400 → FAILURE | ✅ |
| T4 | 401 / 403 → FAILURE | ✅ |
| T5 | 500 / 503 → FAILURE | ✅ |
| T6 | timeout (code 0) → FAILURE | ✅ |
| T7 | network failure (code 0) → FAILURE | ✅ |
| T8 | malformed / unknown → FAILURE | ✅ |
| **INVARIANT** | **codes 0–600 exhaustively: only 409 is COLLISION** | ✅ |
| T9 | collision → tries next candidate | ✅ |
| T10 | 500 → **does NOT** try next | ✅ |
| T11 | timeout → **does NOT** try next; stops at that candidate | ✅ |
| T12 | network failure → consumes no candidates | ✅ |
| T13 | success → returns the claimed roll | ✅ |
| T14 | legitimate collision run still reaches the first free number | ✅ |
| REGRESSION | the old boolean conflated 409 / 500 / timeout | ✅ |
| BC ×5 | boolean signature, wrapper semantics, constants, Firebase sibling, uninitialised → FAILURE | ✅ |
| CALLER ×2 | roll path uses the status API; **exactly one** caller adapted | ✅ |
| SRNO ×2 | classifier is identity-agnostic; failure assigns nothing | ✅ |

Tests exercise **caller behaviour**, not just the helper: T9–T14 model the actual candidate walk and
assert that a failure stops it.

### Exact baseline comparison (Part 15)

| Run | Tests | Failures |
| --- | ---: | ---: |
| Phase 20.3 baseline | 1559 | **10** |
| New tests added | +25 | — |
| **After Phase 20.3b** | **1584** | **10** |

**Identical failures, failure-for-failure**: `BackupTest::size_human` ×1, `Homework*` ×4,
`ParentHomeworkRepoSilentCatch` ×3, `TeacherExecuteDeleteCascade` ×2.

**No new failure appeared. NOT claiming "all tests pass" — the same 10 pre-existing failures remain.**

---

## 9. Failure-safety evidence (Part 10)

The invariant **UNKNOWN FAILURE ≠ COLLISION** is enforced at three levels:

1. **Transport classification** — only `409` maps to COLLISION; proven exhaustively for codes 0–600.
2. **Wrapper safety** — an uninitialised client returns FAILURE, never COLLISION.
3. **Caller** — the roll walk returns an error on FAILURE and consumes no further candidates.

No path converts a 500, timeout, network error or unknown transport failure into *"slot already
occupied."*

---

## 10. SRNO audit (Part 12)

| Check | Result |
| --- | --- |
| Generates another SRNO | ❌ no |
| Alters SRNO | ❌ no |
| Uses SRNO as a claim key | ❌ no — the key is `md5(session_class_section_candidate)` |
| **Retry issues a second SRNO** | ❌ **no** — the failure path returns before any claim or identity work |
| Modifies `admissionNo` | ❌ no |
| Modifies Student ID | ❌ no |

`Sr_no_service` and `Id_generator` keep the boolean API and were **not touched**; their behaviour is
unchanged. The new classifier is identity-agnostic (asserted by test).

> ## SRNO AUDIT: PASS

---

## 11. RTDB audit (Part 14)

**Clean.** No RTDB reference appears in any line I added — verified by scanning the added lines of
all three modified files.

`Firebase.php` contains pre-existing RTDB code at `:48`, `:99`, `:286`, `:296`, `:307`, and `Sis.php`
carries pre-existing uncommitted comments describing *removed* RTDB writes. **Neither was introduced,
modified or extended by this phase.**

---

## 12. Rules / index status (Part 14)

**`firestore.rules` and `firestore.indexes.json` were NOT opened for modification.** Both verified
byte-identical:

```
firestore.rules ....... b597cee2a782f450…   (still the P20-F14 hash)
firestore.indexes.json  14190666743aeed7…
```

No new collection was created, so no rules or index change is required. **P20-F14 remains open and
blocked on your reconciliation of the concurrent HRMS `locations` work.**

---

## 13. Worktree safety (Part 2)

| Item | Start | End |
| --- | --- | --- |
| Branch / HEAD | `integration/phase2` @ `758c924` | unchanged |
| Staged / stashes | 4 / 3 | **4 / 3** |

No reset, clean, checkout, stash, rebase or revert. Exact-string edits only.

### Protected files — all five byte-identical

```
MY_Controller.php ....... daf5f2f18be05be9…  UNCHANGED
Firestore_service.php ... c9eff0ca9338a04f…  UNCHANGED
rbac_helper.php ......... 7365226413f193f2…  UNCHANGED
firestore.rules ......... b597cee2a782f450…  UNCHANGED
firestore.indexes.json .. 14190666743aeed7…  UNCHANGED
```

**Diff scope.** `Firebase.php` +25/−0 and `Firestore_rest_client.php` +86/−7 are entirely mine.
`Sis.php` shows +2725/−161 because it was **already heavily dirty** with unrelated uncommitted work;
**my contribution there is ~25 lines** around the roll claim, isolated and verified. No unrelated
hunk was touched or normalised.

---

## 14. Files changed / untouched

**Changed:**

| File | Change |
| --- | --- |
| `application/libraries/Firestore_rest_client.php` | `createDocumentStatus()` + 3 constants; `createDocument()` now a thin wrapper |
| `application/libraries/Firebase.php` | `firestoreCreateStatus()` added; existing wrapper untouched |
| `application/controllers/Sis.php` | `suggest_roll_no()` consumes the status API and fails fast |

**New:** `tests/Unit/Phase203bCreateErrorSemanticsTest.php` · this report.

**Untouched:** all four protected files · `Firestore_service.php` · the 32 other `firestoreCreate()`
callers · `syncStudent` · admission · promotion · transfer · rollover · import · enroll · sections ·
capacity · roll policy · SRNO policy · Attendance · Examination · Library · Fees · ID Card ·
Transport · Student Charge Module · Parent/Teacher apps.

---

## 15. Remaining risks

| Risk | Rank | State |
| --- | --- | --- |
| **P20.2-RACE-1** — capacity not atomic | **HIGH** | **OPEN** — Phase 20.3c |
| P20.3b-FOLLOWUP-1 — 6 claim-style callers still on the boolean API | LOW | registered |
| P20-F14 — concurrent `firestore.rules` change | MEDIUM | **OPEN**, blocked on you |
| Transfer legacy-only | MEDIUM | Phase 20.4 |
| No live UAT of the new failure path | MEDIUM | needs a real Firestore outage or fault injection |

**Not verified at runtime:** the FAILURE branch has not been exercised against a real timeout or 500.
It is proven by unit test only. Inducing it safely would need fault injection against a non-production
tenant.

---

## 16. Explicitly deferred — P20.3c (Part 11)

**Not started.** No seatClaims, no `commitBatch` precondition work, no capacity change, no placement
change.

With P20.3-CLAIM-1 fixed, the approved Option B can now be built on a primitive that reports
contention honestly — though Option B's own safety rests on `commitBatch` preconditions rather than
on create-collisions, so this fix is an enabler and a correctness win, **not** a prerequisite that
alters Option B's design.

---

## 17. Status ledger

**FIXED** — P20.3-CLAIM-1 (transport classification + roll-number caller)
**FOUND — NOT FIXED** — P20.3b-FOLLOWUP-1 · P20.2-RACE-1 · P20.3-ARCH-1/2 · P20.2-CAP-4/5 ·
P20-F13/F15/F16/F11 · P20-F2/F4/F5
**NOT A DEFECT** — P20-F10
**BLOCKED BY DECISION** — P20-F14 · P20.2-RBAC-1 · Option B authorization (given, scheduled 20.3c)
**REQUIRES LIVE UAT** — the FAILURE branch; all Phase 20.1–20.2 browser cases
**OUT OF SCOPE** — everything in §14's untouched list

> ## SRNO AUDIT: PASS

---

## 18. Exact next-step recommendation

**Phase 20.3c — implement approved Option B:** route the student write through `commitBatch()` with
an `updateTime` precondition on the section document, plus a bounded retry loop; wire the five
seat-consuming paths; unit-test retry and idempotency; then **live Firestore UAT**, the only place
concurrency can actually be proven.

Then, unchanged from your sequencing: **20.4** transfer migration → **20.5** bulk roll → browser UAT
→ soak → scoped commit → explicit legacy-retirement authorization.

**Do not start** bulk roll, transfer migration, legacy retirement, the Student Charge Module, or any
other module.

---

```
PHASE 20.3B COMPLETE — CREATE COLLISION / FAILURE DISAMBIGUATION
NOT COMMITTED
NOT PUSHED
NOT DEPLOYED
UAT NOT EXECUTED
```

P20.3c not started. Awaiting review.
