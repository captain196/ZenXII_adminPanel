# PHASE 20.3 — Atomic Section Capacity / Seat Concurrency

**Status:** RESEARCH COMPLETE · **STOP — NO CODE CHANGED** · awaiting authorization
**NOT COMMITTED · NOT PUSHED · NOT DEPLOYED · UAT NOT EXECUTED**
**Date:** 2026-08-16 · **Branch:** `integration/phase2` @ `758c924`

---

## 1. Executive summary

**No code was changed in this phase.** The research produced three findings that together make
implementation unsafe without your authorization, and one of them invalidates the design Phase 20.2
proposed.

| ID | Finding | Impact |
| --- | --- | --- |
| **P20.3-CLAIM-1** | **NEW, HIGH.** `createDocument()` returns `false` for a 409 collision **and** for every other failure (500 / timeout / network). The two are indistinguishable to the caller. | **Kills the seatClaims design** and is a live (minor) defect in the existing roll-number path |
| **P20.3-ARCH-1** | **No common insertion point exists** that is both complete and specific. `syncStudent()` misses transfer and fires on 7 non-placement operations. | Seat coordination cannot be added in one place |
| **P20.3-ARCH-2** | **No Firestore transaction primitive exists** anywhere in the repository. | Option A unavailable without modifying core infrastructure |

**Selected architecture (designed, NOT implemented): Option B — `commitBatch` with an `updateTime`
precondition on the section document.** It achieves correct serialization using primitives that
already exist, creates no second occupancy authority, and needs no rules or index change. It does
require restructuring the canonical student write path across five call sites — which is why this
phase stops here rather than proceeding.

**P20.2-RACE-1 remains OPEN and unfixed.** Nothing in this phase claims otherwise.

---

## 2. Worktree safety

| Item | Start | End |
| --- | --- | --- |
| Branch / HEAD | `integration/phase2` @ `758c924` | unchanged |
| Staged / stashes | 4 / 3 | **4 / 3** |
| Modified / untracked | 118 / 493 | **118 / 493** |

**No file was created, modified or deleted in this phase.** No reset, clean, checkout, stash, rebase
or revert.

### Protected files — all five byte-identical to the Phase 20.2 hashes

```
MY_Controller.php ........... daf5f2f18be05be9…  UNCHANGED
Firestore_service.php ....... c9eff0ca9338a04f…  UNCHANGED
rbac_helper.php ............. 7365226413f193f2…  UNCHANGED
firestore.rules ............. b597cee2a782f450…  UNCHANGED (still the P20-F14 hash)
firestore.indexes.json ...... 14190666743aeed7…  UNCHANGED
```

`firestore.rules` has not moved again. It still carries the other session's HRMS `locations` work and
remains **blocked on your reconciliation (P20-F14)**.

---

## 3. Phase 20.2 re-verification (Part 1)

Re-read from source, not trusted from the report. **P20.2-RACE-1 is confirmed exactly as described:**

`Sis::save_admission()` — capacity checked at `:667` via `_sectionCapacityExceeded()`; the student is
written at `:938` via `syncStudent()`. Between them sit validation, ID generation and the SRNO claim.
**There is no transaction, no CAS, no seat claim and no re-read.** Two requests observing 39/40 both
pass and both write.

Also re-confirmed:
- Import's **intra-request** accounting is correct (`$seatsUsed[$groupId] + 1`, `Sis:6611`) and the
  preview explicitly states *"PREVIEW IS NOT AUTHORIZATION"*. The gap is strictly **cross-request**.
- The 90-second snapshot cache is invalidated on `fs->` student writes, so it neither causes nor
  prevents the race.

---

## 4. Existing atomic primitives (Part 3)

| Primitive | Location | Semantics | Usable for seats? |
| --- | --- | --- | :-: |
| `firestoreCreate()` → `createDocument()` | `Firebase:1243` → `Firestore_rest_client:924` | create-if-not-exists; **`false` on 409 AND on every other error** | ❌ — see P20.3-CLAIM-1 |
| `commitBatch()` | `Firestore_rest_client:953` | **true multi-write atomicity** via Firestore `:commit`; supports per-document `precondition.updateTime` | ✅ |
| `casMutate()` / `updateTime` precondition | `Firestore_service:864` | optimistic CAS on one document | ✅ (building block) |
| `_config_lock_acquire()` | `School_config:369` | **file** lock, single host | ⚠️ single-server only |
| **Firestore transactions** | — | **DO NOT EXIST** in this repository | ❌ |
| `rollNoClaims` | `Sis:4017-4040` | claim + TTL expiry; the proven in-domain pattern | ⚠️ inherits P20.3-CLAIM-1 |

### P20.3-CLAIM-1 — NEW, HIGH — the finding that changes the answer

`Firestore_rest_client::createDocument()` (`:924-936`):

```php
if ($r['code'] >= 200 && $r['code'] < 300) return true;
if ($r['code'] === 409) return false;   // doc exists — caller must handle
if (function_exists('log_message')) log_message('error', "…HTTP {$r['code']}…");
return false;                            // ← EVERY OTHER FAILURE ALSO RETURNS false
```

**`false` conflates "someone else holds this slot" with "the write failed for an unknown reason."**

**Existing impact (minor, real):** `Sis::suggest_roll_no()` treats `false` as *"this number is not
usable right now; try the next one"* (`:4095-4101`). On a transient 500 or timeout it silently skips
a genuinely free roll number, drifting suggestions upward. Not data loss, but it is the same class of
defect ROLL-5 already fixed once for abandoned claims.

**Why it is fatal for seats.** A seat-claim loop must claim slot `occupancy+1`. If `false` means
"taken", a transient error makes the caller advance to slot 41 on a 40/40 section — and that create
would **succeed**. The mechanism intended to prevent over-capacity would itself cause it. A
"fail-closed on false" variant is no better: every transient blip would refuse legitimate admissions.

Distinguishing the two requires changing `createDocument()` (and its callers) to surface the HTTP
status — i.e. modifying `Firestore_rest_client.php` / `Firebase.php` / `Firestore_service.php`.
**`Firestore_service.php` is a protected file → STOP condition (§20).**

---

## 5. All seven placement paths (Part 6)

| # | Path | Capacity check | Student write | Consumes a seat? |
| --: | --- | --- | --- | :-: |
| 1 | Admission | `Sis:667` | `syncStudent` `Sis:938` | ✅ |
| 2 | Promotion | `Sis:1549` | `syncStudent` `Sis:1933` | ✅ |
| 3 | Import validate | `Sis:6610` | **none — preview only** | ❌ |
| 4 | Import execute | `Sis:5552` | `syncStudent` `Sis:5970` | ✅ |
| 5 | Enroll | `Sis:9629` | `syncStudent` `Sis:9942` | ✅ |
| 6 | Rollover | `School_config:4132` | **none — pre-flight forecast; movement happens in `execute_promotion`** | ❌ |
| 7 | **Transfer** | `Classes:750` | **`fs->updateEntity('students')` `Classes:852` — BYPASSES `syncStudent`** | ✅ |

**Five paths actually consume a seat.** Two (import-validate, rollover) only forecast.

### P20.3-ARCH-1 — no common insertion point exists

`syncStudent()` is the closest candidate and fails both tests:

- **Incomplete** — transfer writes directly via `updateEntity()` and never reaches it.
- **Too broad** — it is also called by `update_profile`, `issue_tc`, `cancel_tc`,
  `withdraw_student`, `change_status`, `edit_student` and `_updateExistingStudent`. Hooking seat
  coordination there would attempt a seat claim on every profile edit and every TC.

Per Part 6's instruction — *"If a common insertion point does not exist, stop and document the
smallest safe architecture"* — that is what §6–7 below do.

---

## 6. Options comparison (Part 7)

| | **A — Transaction** | **B — commitBatch + precondition** | **C — seatClaims** | **D — file lock** | **E — other** |
| --- | --- | --- | --- | --- | --- |
| Atomicity | true | **true (single `:commit`)** | advisory only | mutual exclusion | — |
| Available today | ❌ **does not exist** | ✅ `Firestore_rest_client:953` | ⚠️ blocked by CLAIM-1 | ✅ but single-host | ❌ none found |
| Second source of truth | no | **no** — version marker, not a count | risk of drift | no | — |
| Failure recovery | automatic retry | **precondition fails → re-read → retry** | stale claims need TTL + reconciliation | lock leak on crash | — |
| Read/write cost | n/a | **+1 read, +1 write per placement** | +1 create per attempt | +file I/O | — |
| Multi-path | n/a | 5 paths | 5 paths | 5 paths | — |
| Protected files | ✅ **required** | ❌ not required | ✅ **required** (CLAIM-1) | ✅ **required** (helper is in School_config) | — |
| Rules/indexes | — | **none** | none (rollNoClaims precedent) | — | — |

### Why the alternatives were rejected

- **A** — there is no transaction primitive; adding one means modifying core Firestore infrastructure.
- **C (Phase 20.2's own proposal)** — **rejected on the evidence of P20.3-CLAIM-1.** The design
  assumed `firestoreCreate` reliably signals collision. It does not. This is exactly the discipline
  this programme has been applying: the proposed fix would have *looked* atomic and could have caused
  the very overflow it was meant to prevent.
- **D** — works on today's single Lightsail host and silently stops working the day a second app
  server appears. The helper also lives in `School_config`; sharing it means `MY_Controller`.
- **E** — no other primitive exists.

---

## 7. Selected architecture — Option B (designed, NOT implemented)

**Optimistic concurrency on the section document, committed atomically with the student write.**

```
1. read section doc      → capture __updateTime  (T)
2. compute occupancy     → _filterSectionRoster() over the shared snapshot   [UNCHANGED]
3. check capacity        → _sectionCapacityExceeded()                        [UNCHANGED]
4. commitBatch([
       student write,
       section touch { seatVersion: T+1 }        ← version marker ONLY
   ], precondition: section.updateTime == T)
5. precondition fails → another placement landed first → re-read from (1), retry (bounded)
```

**Why this is correct.** Two requests both read occupancy 39 and section updateTime `T`. Both pass the
check. Both commit with `precondition T`. Firestore admits exactly one; the other's precondition
fails, it re-reads, now sees 40, and refuses. **Serialization is enforced by Firestore, not by our
bookkeeping.**

**Why it does not create a second source of truth (Part 4).** The section document gains only an
opaque version marker. It is **never read as an occupancy count**. Occupancy continues to derive
solely from `students` via `_filterSectionRoster()`. If the marker were deleted tomorrow, occupancy
would still be correct — only conflict detection would be lost.

### What implementing it requires

| Change | File | Protected? |
| --- | --- | :-: |
| Route the student write through `commitBatch` with a precondition | `Entity_firestore_sync.php` or a new coordinator library | ❌ no |
| Wire the 5 seat-consuming paths | `Sis.php` (4), `Classes.php` (1) | ❌ no |
| Bounded retry loop | new library | ❌ no |

**No protected file, no rules change, no index change, no migration.**

### Why I did not implement it

1. It restructures the **single most critical write path in the system** — `syncStudent()` carries
   partial-merge semantics with its own test harness (`application/tests/test_sync_student.php`).
2. **I cannot test concurrency locally.** Correctness here is provable only against live Firestore.
   Shipping an untested restructuring of student writes to fix a race would trade a known bounded
   defect for an unknown unbounded one.
3. Part 21 sequences transfer migration into Phase 20.4. Wiring transfer's seat coordination now
   would pre-empt that.

**This is a request for authorization, not a refusal.** The design is complete and buildable.

---

## 8. Failure / recovery matrix (Part 8) — for Option B

| # | Scenario | Resulting state | Recovery |
| --: | --- | --- | --- |
| 1 | commit succeeds | student placed, marker bumped | none needed |
| 2 | precondition fails | **nothing written** (atomic batch) | re-read, retry (bounded) |
| 3 | capacity check fails | nothing written | refuse with the canonical message |
| 4 | commit times out after server-side success | student placed | retry is **idempotent** — same deterministic `studentId` doc id; second write merges |
| 5 | request crashes before commit | **nothing written** | none needed — no orphan state |
| 6 | crash after commit | student placed correctly | none needed |
| 7 | user retries | idempotent by doc id | no duplicate student, **no second SRNO** |
| 8 | 2 simultaneous | one commits, one retries and correctly refuses at 40/40 | automatic |
| 9 | 3 simultaneous | one commits, two retry; bounded attempts then refuse | automatic |
| 10 | admission + transfer | both target the same section doc → serialized | automatic |
| 11 | promotion + admission | serialized on the destination section | automatic |
| 12 | import + admission | serialized per section; import retries per row | automatic |

**No phantom occupancy and no permanently lost seat**, because nothing durable is written except the
student, and occupancy is always re-derived from students. This is the decisive advantage over
Option C, where an abandoned claim consumes a seat until TTL expiry.

---

## 9. Capacity-change interaction (Part 10)

| Scenario | Behaviour under Option B |
| --- | --- |
| Capacity 40→35 while an admission is in flight | The capacity write bumps the section document, so the in-flight commit's precondition fails, it re-reads, sees 35, and re-evaluates. **The admission is judged against the new capacity** — correct. |
| Capacity 40→45 while admissions are in flight | Same mechanism; the retry sees 45 and may now succeed. |
| Reduce below occupancy | **Phase 20.2 policy unchanged** — warn, explicit confirm, never remove/move/renumber, future placement obeys the new value. |

This falls out of the design rather than needing a separate rule — a point in Option B's favour.
**No new policy is invented.**

---

## 10. Transfer / promotion / rollover interaction (Part 11)

**Transfer A → B.** Under Option B there is nothing to "release" from A: occupancy is derived from
student records, so the moment the student's `section` changes, A's occupancy drops and B's rises —
in **one atomic batch**. The §11 concern about releasing A before B is secured **does not arise**,
because there is no per-seat state to release.

> **Honest consistency statement:** the batch is atomic for the *write*. The **read-check** preceding
> it is not part of that transaction — it is guarded by the precondition. This is
> **optimistic concurrency, not serializable isolation.** Under sustained contention a request can
> exhaust its retries and be refused; it can never place a student over capacity. **I am not calling
> this fully atomic movement, and no test will claim it.**

**Promotion** already writes via `syncStudent` per student and would participate per destination
section. **Rollover** performs no student write (§5) and needs no coordination.

---

## 11. Performance (Part 12)

Per placement, versus today:

| | Now | Option B |
| --- | --- | --- |
| Section document read | 1 (inside `_sectionCapacity`) | 1 (reused — captures `updateTime`) |
| Occupancy | 0–1 (shared snapshot) | unchanged |
| Student write | 1 | 1 (inside the batch) |
| Section touch | 0 | **+1 write in the same batch** |
| **Extra round-trips** | — | **0** (the batch is one HTTP call) |

**Contention cost:** each retry repeats one read + one commit. In this codebase's ~1–3 s/op
environment a single retry adds roughly 2–6 s **only when two operators hit the same section
simultaneously** — rare, and it is the case that is currently silently wrong.

**Hotspot caution (registered):** Firestore's soft limit is ~1 sustained write/second per document.
Bumping the section document on every placement makes it a per-section hotspot. Fine for admissions;
worth measuring for a large single-section import. Mitigation if needed: batch a whole import's rows
per section into one commit.

**No N+1, no per-student scan, no unbounded claim scan.**

---

## 12. Tenancy / security (Part 13)

- The section document is already school- and session-scoped by construction:
  `{schoolId}_{session}_{classKey}_{sectionKey}` via `sectionDocId()`. **No new namespace is created.**
- Option B introduces **no new collection**, so **no rules change and no index change is required** —
  verified, not assumed. (Had Option C been chosen, `rollNoClaims` sets the precedent that such
  collections carry no rules block and are client-default-deny; PHP writes via the service account.)
- `firestore.rules` and `firestore.indexes.json` **were not opened for modification** and remain
  byte-identical.

---

## 13. Test evidence (Part 14 / Part 18)

**No tests were added, because no code was changed.**

Suite re-run to confirm the tree is untouched:

| Run | Tests | Failures |
| --- | ---: | ---: |
| Phase 20.2 baseline | 1559 | 10 |
| **Phase 20.3** | **1559** | **10** |

Identical, failure-for-failure. **Not claiming all tests pass — 10 pre-existing failures remain.**

> ### Concurrency safety is NOT implemented, and therefore not tested.
> When Option B is built, the deterministic tests (retry-on-precondition-failure, bounded attempts,
> capacity-change interaction, idempotent retry) are unit-testable, but **true concurrency remains
> provable only against deployed/live Firestore.** The honest statement at that point will be:
> *"Concurrency safety is structurally implemented but requires deployed/live Firestore UAT."*
> It cannot be claimed today.

---

## 14. Browser / live UAT requirements (Part 15)

> **UAT NOT EXECUTED — browser unavailable.** Unchanged from Phase 20.2.

When Option B ships, live UAT must cover: two browsers admitting into a 39/40 section simultaneously;
capacity reduced mid-admission; import + admission into the same section; and retry after a forced
precondition failure. **None of this can be simulated locally.**

---

## 15. SRNO audit (Part 9 / Part 15)

No code changed, so nothing could regress. Audited against the **proposed** design:

| Check | Result |
| --- | --- |
| Mechanism modifies `srNo` | ❌ no — the batch writes the student document and a version marker |
| `srNo` used as a seat key | ❌ no — the coordination key is the section document id |
| `rollNo` used as permanent identity | ❌ no |
| `rollNo` overwritten / assigned to obtain a seat | ❌ no — seat coordination is independent of roll assignment |
| `admissionNo` changed | ❌ no |
| **Retry creates a second SRNO** | ❌ **no** — failure mode 2 writes *nothing*, so the SRNO claim is not re-run; failure mode 4 is idempotent on a deterministic doc id |
| Transfer / promotion / rollover preserve SRNO | ✅ unchanged (re-confirmed) |

Point 4 of §9 — *"retry never creates a second SRNO"* — is the sharpest risk in any retry design, and
Option B satisfies it precisely **because a failed precondition writes nothing at all**. Option C
would not have: a successful claim followed by a failed student write leaves durable state behind.

> ## SRNO AUDIT: PASS

---

## 16. Mobile impact (Part 16)

Option B changes no `sectionKey`, `studentId`, `class`, `section`, `rollNo` or `srNo`. It adds an
opaque marker field to the section document — a collection **neither app reads** (Phase 20.1b,
re-confirmed).

> ## NO MOBILE CHANGE REQUIRED.

No Kotlin file was opened for modification.

---

## 17. Cross-module impact (Part 17)

Placement concurrency alters no downstream record: fee demands, `studentFeeSummary`, fee history, ID
card records, transport and hostel assignments are all keyed on `studentId` and are unaffected by
*when* the student write commits.

**One consequence worth naming:** today an over-capacity race produces a student who *should not
exist in that section*, and every downstream module — fees, ID card, transport — then builds records
around them. Fixing the race prevents that class of downstream contamination at the source. **No
cross-module side effect was discovered, and no other module was touched.**

---

## 18. Files changed / untouched

**Changed: none.** This phase produced only this report.

**Intentionally untouched:** `MY_Controller.php` · `Firestore_service.php` · `Firestore_rest_client.php`
· `Firebase.php` · `rbac_helper.php` · `firestore.rules` (P20-F14) · `firestore.indexes.json` ·
`Entity_firestore_sync.php` · `Sis.php` · `Classes.php` · Parent/Teacher apps · Fees · ID Card ·
Attendance · Examination · Library · Transport · Student Charge Module.

---

## 19. Status ledger

**FIXED** — nothing (no code changed)
**FOUND — NOT FIXED** — **P20.3-CLAIM-1** (409 ambiguity; also affects live `suggest_roll_no`) ·
**P20.3-ARCH-1** (no common insertion point) · **P20.3-ARCH-2** (no transaction primitive) ·
**P20.2-RACE-1** (still open) · P20.2-CAP-4/5 · P20-F13/F15/F16/F11 · P20-F2/F4/F5
**NOT A DEFECT** — P20-F10
**BLOCKED BY DECISION** — Option B authorization · P20-F14 (`firestore.rules`) · P20.2-RBAC-1
**REQUIRES LIVE UAT** — all concurrency behaviour, once built
**OUT OF SCOPE** — bulk roll, transfer migration, legacy retirement, and every module in §18

> ## SRNO AUDIT: PASS

---

## 20. Remaining risks

| Risk | Rank | State |
| --- | --- | --- |
| Over-capacity via concurrent placement (P20.2-RACE-1) | **HIGH** | **OPEN** — unchanged |
| `createDocument` 409 ambiguity misleads any future claim logic | **MEDIUM** | **OPEN** — and already mildly degrading roll suggestions |
| Section-document write hotspot under Option B | LOW | design-time mitigation noted |
| Transfer remains legacy-only | MEDIUM | Phase 20.4 |

---

## 21. Decisions required

1. **Authorize Option B?** — restructuring the student write path through `commitBatch` with a
   section precondition. No protected file, no rules, no migration. *Recommended.*
2. **P20.3-CLAIM-1** — fix `createDocument()` to distinguish 409 from other failures? It touches core
   Firestore infrastructure (`Firestore_service.php` is protected) and would also correct the
   existing roll-suggestion drift. *Recommended as a small, separately-scoped phase.*
3. **Accept the optimistic-concurrency consistency model** (§10) rather than serializable isolation —
   bounded retries, refusal under sustained contention, never over-capacity.
4. **P20-F14** — reconcile `firestore.rules` before any deploy. Still outstanding.

---

## 22. Exact next-phase recommendation

**Phase 20.3b (small, do first):** fix P20.3-CLAIM-1 so claim-style code can tell a collision from a
failure. It is self-contained, improves the existing roll path, and is a prerequisite for trusting
any future claim mechanism.

**Phase 20.3c:** implement Option B behind the existing capacity check, with unit tests for retry and
idempotency, then **live Firestore UAT** — the only place concurrency can actually be proven.

**Then** Phase 20.4 (transfer migration) → 20.5 (bulk roll) → UAT → soak → clean scoped commit →
explicit retirement authorization, exactly as sequenced in Part 21.

**Do not start** bulk roll, transfer migration, legacy retirement or any out-of-scope module.

---

```
PHASE 20.3 — RESEARCH COMPLETE · STOP · NO CODE CHANGED
P20.2-RACE-1 REMAINS OPEN
NOT COMMITTED · NOT PUSHED · NOT DEPLOYED · UAT NOT EXECUTED
```

Awaiting your decision on Option B before any implementation.
