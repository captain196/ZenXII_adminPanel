# R5 COMPLETION + R1 RE-VERIFICATION REPORT

**R5: COMPLETE AND INDEPENDENTLY VERIFIED — 481 of 481 orphans deleted, 0 remain.**
**R1: re-verified, checks 1–6 PASS. Check 7 (reuse) — STOPPED for your decision, and the**
**answer is NOT the one R1 assumes.** Date 2026-08-17 · Tenant `SCH_B56BB9A401`

---

## A. Pre-delete verification — fresh client, fresh process

| Guard | Result |
| --- | --- |
| G1 `schoolId === SCH_B56BB9A401` | applied to every candidate |
| G2 `studentId` ∈ the exact 13-id allow-list | applied |
| G3 student document does not exist | re-checked per student during the run |
| **G4 count === 254** | **PASS — expected 254, actual 254** |

Tenant read fresh: 23 live students, **280** `feeDemands`, **254** candidates. 280 − 254 = 26,
which is exactly STU0012's live demand count — the two numbers agreed before a single delete ran.

## B–E. Execution

| | Count |
| --- | --- |
| **B. candidates** | **254** |
| **C. deleted** | **254** |
| **D. skipped** | **0** |
| **E. failed** | **0** |

The repaired retry/backoff held: 254/254, zero throttling losses, versus 227/481 on the first run.

## F. Independent post-verification

Run from a **separate script and a separate process** (`scripts/r5_r1_independent_verify.php`), not
the deletion tool's own post-check:

```
feeDemands PLATFORM-WIDE : 1242
   (no schoolId)              98
   SCH_218AAF5C23             1118
   SCH_B56BB9A401              26
      STU0012   26  LIVE
```

| Check | Result |
| --- | --- |
| 1. zero orphan `feeDemands` for the 13 ids | **PASS** — 0 |
| 2. live students' demands intact | **PASS** — 26, expected 26 |
| 3. other tenant untouched | **PASS** — 1,118 |
| 4. `schoolId`-less demands untouched | **PASS** — 98 |
| 5. no unexpected student ids affected | **PASS** — 0 |
| 6. tenant total | **PASS** — 26 |

## G. Reconciliation arithmetic

```
original authorised candidates ....... 481
deleted run 1 ........................ 227
deleted run 2 ........................ 254
total deleted ........................ 481   = 481 ✓
authorised orphans remaining ..........  0   ✓
507 original tenant demands − 481 ..... 26 live   observed 26 ✓
candidate accounting : 254 + 0 + 0 = 254 = candidates   RECONCILES
survivor accounting  : failed + skipped = 0 = observed  RECONCILES
```

Both identities hold, computed twice by two independent programs. **R5 is complete.**

## H. Live-student demands intact

**26/26 confirmed** — STU0012's demands were never candidates (G3), and the fresh census shows the
tenant holding exactly 26 documents, all his.

## I. No other tenant touched

`SCH_218AAF5C23` = 1,118 (unchanged), 98 `schoolId`-less demands (unchanged), 150 students
(unchanged), 16 `srNoIndex` (unchanged). Zero foreign-tenant documents were seen at any point.

---

# J. R1 RE-VERIFICATION — read-only, nothing deleted

All 16 `srNoIndex` documents, tenant-scoped:

| Check | Result |
| --- | --- |
| 1. all point to nonexistent students | **PASS** 16/16 |
| 2. all `claimedBy = P203M_UAT` | **PASS** 16/16 |
| 3. all originated from authorised testing | **PASS** — via `claimedBy` provenance |
| 4. no live student references SRNO 1–16 | **PASS** — 0 |
| 5. no business record depends on the claims | **PASS** — 0 across `feeDemands`, `feeReceipts`, `scholarshipAwards`, `studentEntitlements`, `admissions` |
| 6. `historicalStudentIds[]` empty | **PASS** 16/16 |
| 7. **can SRNO 1–16 be safely reused?** | **NO — and deleting them would not achieve it. See below.** |

SRNO 1–16 map to STU0164, 0166, 0167, 0168–0179, 0181. Note three of those (0164, 0166, 0167) took
an SRNO but never got fee demands — consistent with UAT rows that failed after identity allocation.

---

## Check 7 — the finding that changes R1

**R1 assumes deleting the 16 `srNoIndex` records releases SRNO 1–16. It does not.** SRNO allocation
is governed by **three** structures, and `srNoIndex` is only one:

| # | Structure | State | R1 scope? |
| --- | --- | --- | --- |
| 1 | `srNoIndex/{school}_{N}` × 16 | present | **yes** |
| 2 | `feeCounters/_sys_SRNO_SCH_B56BB9A401` | **`value: 16`** | no |
| 3 | `feeCounters/_sys_SRNO_{school}_claim_{N}` | **present for N = 1…16** | no |

`Sr_no_service::allocateNext()` computes `candidate = pointer + 1` and, on collision, **probes
upward only — it never reuses a gap.** So:

- **If R1 runs as scoped** (delete the 16 `srNoIndex` only): the pointer stays 16, the next real
  admission gets **SRNO 17**, and 1–16 become permanently unusable — reserved by the claim docs, owned
  by nobody. R1 does not release them; it only removes the ownership records.
- **A "reset to 1" that deletes the pointer but leaves the 16 claim docs would BRICK ADMISSIONS.**
  The lazy scan would return 0, `candidate` would be 1, `claim_1` already exists so the reservation
  fails, and the pointer is only written *after* a successful reservation — so every one of the
  `COUNTER_MAX_ATTEMPTS = 15` attempts retries **the same candidate 1** and returns
  `contention_exhausted`. Combined with the SRNO-F1 fail-closed fix, **the tenant could not admit
  anyone at all.** Genuine reuse requires deleting all three structures together, in that order.

**Operational consequence of doing nothing:** this tenant's 23 live students **all have no SRNO**
(`with srNo: 0, without: 23`), so SRNO has never been issued to a real student here. The school's
first real admission would be **SR No 17**, with 1–16 permanently absent from the register. That is
not a data-integrity fault — resolution, uniqueness and fail-closed allocation all work correctly —
but a physical admission register that begins at 17 with no explanation is the kind of gap an auditor
asks about, and it is unfixable later without the same three-structure cleanup.

**No historical evidence exists that 1–16 were ever issued to real students** — all 16 carry
`claimedBy = P203M_UAT`, all point to deleted UAT students, all have empty history, and no live
student or business record references them.

**Reuse safety cannot be established as a simple `srNoIndex` deletion, so I stopped.** Your options:

- **(i)** Run R1 as scoped — remove the 16 orphan ownership records only. Correct hygiene; SRNO 1–16
  stay burned; first real admission is **17**.
- **(ii)** Full three-structure reset — 16 `srNoIndex` + 16 `claim_N` + the pointer, in that order,
  so the first real admission is **SRNO 1**. Larger scope than R1 as written and needs explicit
  authorisation, including the `feeCounters` writes.
- **(iii)** Do nothing.

**I did not delete any `srNoIndex` record, any counter claim, or the pointer.** R1 deletion remains
unexecuted and separately gated.

---

## State

| Item | Value |
| --- | --- |
| Production code changed | **None** — only `scripts/` (cleanup + a new read-only verifier) |
| Live data changed | **254 orphaned `feeDemands` deleted** — authorised scope exactly |
| Untouched | students, `srNoIndex`, `feeCounters`, other tenants, receipts, accounting, entitlements, scholarships, fee charts, `feeCategories` |
| Rules / indexes | **Untouched** — baseline `f58499f8`, concurrent Staff-Lifecycle/HRMS work untouched |
| RTDB | **None** |
| `USE_ENTITLEMENT_BILLING` | **false** · `REAL_ADMISSION_READY` **FALSE** |
| SRNO backfill / F2 / F3 / B3 / N+1 / scholarship | **Not implemented** |
| Commit / push / deploy | **NO / NO / NO** |

**Awaiting:** your decision on (i) / (ii) / (iii) for SRNO 1–16.
