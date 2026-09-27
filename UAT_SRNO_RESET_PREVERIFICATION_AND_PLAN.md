# UAT SRNO RESET — PRE-WRITE VERIFICATION + PROPOSED SEQUENCE

**STATUS: VERIFIED AND DESIGNED. NOT EXECUTED. STOPPED FOR YOUR REVIEW.**
Tenant `SCH_B56BB9A401` (UAT) only · 2026-08-17 · `scripts/uat_srno_reset_preverify.php` (read-only)

---

## 0. Correction accepted — legacy students

Students admitted before SRNO enforcement may legitimately hold a `studentId` and no SRNO. That is a
**legacy population, not a defect and not a failed admission**, and it is not a backfill trigger. My
earlier "register begins at 17" note was strictly about this UAT tenant's *allocator* state; it was
not an argument for assigning SRNOs to anyone. Nothing in this plan touches the 23 UAT students, the
150 platform students, or any real school.

The model I am working to: **legacy → may remain without SRNO · new admissions → SRNO mandatory
(SRNO-F1 fail-closed) · legacy assignment → a separate, operator-driven future design.**
And the identity rule stays: **SRNO is school-scoped, `school + SRNO → student`, never keyed by
academic session** — a student keeps one SRNO across 2025-26, 2026-27, 2027-28.

---

## 1. Final read-only verification — ALL 12 CHECKS PASS

| # | Check | Result |
| --- | --- | --- |
| 1 | `SCH_B56BB9A401` is the authorised UAT tenant | **PASS** — all 16 claims carry `claimedBy=P203M_UAT` |
| 2 | no live student holds SRNO 1–16 | **PASS** — 23 live, **0 with any `srNo`** |
| 3 | no live student references them indirectly | **PASS** — 0 live docs mention a deleted UAT student id |
| 4 | no `feeDemands` reference them | **PASS** — 0 |
| 5 | no `feeReceipts` reference them | **PASS** — 0 |
| 6 | no `scholarshipAwards` reference them | **PASS** — 0 |
| 7 | no `studentEntitlements` reference them | **PASS** — 0 |
| 8 | no `admissions` reference them | **PASS** — 0 |
| 9 | `historicalStudentIds[]` empty | **PASS** — 16/16 |
| 10 | all 16 `claimedBy = P203M_UAT` | **PASS** — 16/16 |
| 11 | all 16 point only to deleted students | **PASS** — 16/16 |
| 12 | no real-school data depends on these SRNOs | **PASS** — see below |

**Blast radius is structurally zero.** Platform-wide, **no tenant other than `SCH_B56BB9A401` holds a
single `srNoIndex` document, SRNO counter, or SRNO claim** — 0 other-tenant `srNoIndex` docs, 0
other-tenant SRNO `feeCounters` docs. SRNO state exists *only* in the UAT tenant today, so no real
school's SRNO history can be affected by any operation scoped to this tenant. Every doc id in the
sequence below is literally prefixed `SCH_B56BB9A401`.

**Current allocator state:**

```
1. srNoIndex/SCH_B56BB9A401_{N}                    16 docs, N = 1…16
2. feeCounters/_sys_SRNO_SCH_B56BB9A401            value = 16
3. feeCounters/_sys_SRNO_SCH_B56BB9A401_claim_{N}  present for N = 1…16
```

---

## 2. The proposed sequence — **claims → index → pointer**

Derived from the actual `Sr_no_service::allocateNext()` implementation, not from assumption.

| Step | Records | Operation |
| --- | --- | --- |
| **1** | `feeCounters/_sys_SRNO_SCH_B56BB9A401_claim_1` … `_claim_16` | delete (16) |
| **2** | `srNoIndex/SCH_B56BB9A401_1` … `_16` | delete (16) |
| **3** | `feeCounters/_sys_SRNO_SCH_B56BB9A401` | delete (1) |

**Total: 33 deletions, zero writes, zero updates.** No student document, no other collection, no other
tenant, no rules, no indexes.

**The pointer is deleted LAST. That ordering is the whole safety argument.**

## 3. Why the sequence is safe — proof from the implementation

The allocator is:

```php
$pointer   = $this->_readSrNoCounterPointer($schoolId);   // pointer doc, else lazy-scan srNoIndex max
$candidate = $pointer + 1;
$reserved  = firestoreCreate('feeCounters', "_sys_SRNO_{$school}_claim_{$candidate}", …);
if (!$reserved) continue;                                  // probe again — pointer NOT advanced
$this->_writeSrNoCounterPointer($schoolId, $candidate);    // only after a successful reservation
$claimRes = $this->claim((string) $candidate, $studentId); // writes srNoIndex + student srNo
```

Walking every intermediate state — the test is whether an allocation running *at that instant* still
succeeds:

| State | pointer | claims | srNoIndex | allocation outcome |
| --- | --- | --- | --- | --- |
| **S0** now | 16 | 1–16 | 1–16 | candidate 17 → reserve OK → **SRNO 17** ✓ |
| **S1** after step 1 | 16 | — | 1–16 | candidate 17 → reserve OK → **SRNO 17** ✓ |
| **S2** after step 2 | 16 | — | — | candidate 17 → reserve OK → **SRNO 17** ✓ |
| **S3** after step 3 | absent | — | — | lazy scan → max 0 → candidate 1 → reserve OK → **SRNO 1** ✓ |

**Every state, including every interrupted state, allocates successfully.** The sequence is safe to
stop after any step. Step 3 is what actually releases the numbers; steps 1–2 are what make step 3 safe.

**Why the reverse order is dangerous — the failure this ordering exists to prevent.** If the pointer
were deleted while the claim docs still existed: `_readSrNoCounterPointer` lazy-scans an empty
`srNoIndex` → 0 → `candidate = 1` → `claim_1` **already exists** → `firestoreCreate` fails →
`continue` **without advancing the pointer** → the next attempt recomputes 0 and probes candidate 1
again. All `COUNTER_MAX_ATTEMPTS = 15` attempts retry the identical candidate, returning
`contention_exhausted`. With SRNO-F1 fail-closed, **the tenant could not admit anyone**. Deleting the
pointer first is also simply ineffective: the lazy scan would find max 16 in `srNoIndex` and
immediately rewrite the pointer back to 16.

Hence the invariant, which I would encode as a hard guard in the script: **the pointer may only be
deleted once both the claim docs and the `srNoIndex` records are confirmed gone.**

## 4. Expected post-reset state

```
srNoIndex/SCH_B56BB9A401_*                 : 0 documents
feeCounters/_sys_SRNO_SCH_B56BB9A401       : ABSENT
feeCounters/_sys_SRNO_..._claim_1..16      : ABSENT
students in tenant                         : 23, UNCHANGED, still 0 with srNo
```

This is byte-for-byte the state of a **brand-new school that has never allocated an SRNO** — a fresh
school also has no pointer document, and `_readSrNoCounterPointer` returns 0 without writing one when
the scan finds nothing. The allocator will behave identically for both.

## 5. Expected first allocation

**SRNO 1**, then 2, 3, … The 23 existing UAT students remain without SRNO, as intended.

## 6. Post-reset verification plan

Fresh process, fresh client, a separate read-only script — the R5 lesson stands: a tool's own
post-check is not evidence.

1. `srNoIndex` — 0 docs for the tenant, and the platform-wide count drops 16 → 0
2. `claim_1` … `claim_16` — all absent (probe 1–25)
3. pointer doc — absent
4. all **23** students unchanged: same ids, still 0 with `srNo` (compare against a pre-reset snapshot)
5. no other tenant changed — platform census of `srNoIndex` and SRNO `feeCounters` still 0 elsewhere
6–10. `feeDemands` still **26** (all STU0012), `feeReceipts` / `scholarshipAwards` /
   `studentEntitlements` / `admissions` counts unchanged versus the pre-reset snapshot
11. no real-school SRNO data changed — necessarily true, since none exists
12. reconciliation: exactly 33 deletions, 16 + 16 + 1, no more

**On the "safe allocation test" (§13) — one thing needs your decision.** There are only two ways to
demonstrate the next candidate is SRNO 1, and they are not equivalent:

- **(a) Read-only state proof (recommended).** With the pointer absent, `srNoIndex` empty and
  `claim_1` absent, `_readSrNoCounterPointer` returns 0 by the code path above, so
  `candidate = 0 + 1 = 1` and `claim_1` is free. This is deterministic and provable without a write.
- **(b) A live `allocateNext()` call.** This is **not** a dry run — it writes `claim_1`, writes
  `srNoIndex/..._1`, and writes an `srNo` onto a student document. It would **consume SRNO 1 and
  recreate exactly the residue we are removing**, unless followed by another cleanup.

I recommend **(a)**, and then letting the first genuine UAT admission consume SRNO 1 naturally —
which is the intended outcome anyway. I will not run (b) unless you ask for it.

---

## 7. Scope held

Not touched by this plan: the 23 UAT students · the 150 platform students · any real school's SRNO
state (none exists) · `feeDemands` (26 remain) · receipts · scholarships · entitlements · admissions ·
`feeCategories` · rules/indexes (`f58499f8`, concurrent Staff-Lifecycle/HRMS work untouched) · RTDB.

**SRNO-F2 untouched** — `enroll_student()` keeps its current order (Auth → fee generation → SRNO
allocation); the correct architecture for moving allocation earlier is a separate future design.
**SRNO-F3 untouched.** No B3, no N+1 work, no scholarship changes, no mass backfill.
`USE_ENTITLEMENT_BILLING = false` · `REAL_ADMISSION_READY = FALSE` · no commit, push, or deploy.

**The original R1 ("delete 16 `srNoIndex` only") is superseded and should not be run** — it would
leave the pointer at 16 and fail to release SRNO 1–16.

---

## Awaiting your authorisation

Nothing has been deleted. To proceed I need explicit approval of **the three-step sequence in §2**,
and your choice on the §6 allocation test — **(a)** read-only proof, or **(b)** a live call that
consumes SRNO 1.
