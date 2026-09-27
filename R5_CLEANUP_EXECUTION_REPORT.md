# R5 — ORPHANED feeDemands CLEANUP: EXECUTION REPORT

**STATUS: PARTIAL — 227 of 481 deleted, 254 remain. STOPPED, awaiting your decision.**
**No live student's data was deleted. No other tenant touched. R1 remains BLOCKED.**
**Date:** 2026-08-17 · Tenant `SCH_B56BB9A401`

---

## A. Pre-delete verification — all guards passed

| Guard | Result |
| --- | --- |
| G1 `schoolId === SCH_B56BB9A401` | applied to every candidate |
| G2 `studentId` ∈ the exact 13-id allow-list | applied; no wildcards |
| G3 student document does not exist | re-checked during the run, per student, after the candidate list was built |
| **G4 pre-delete count === 481** | **PASS — actual 481, exact match** |

Live students in tenant: 23. `feeDemands` in tenant: 507. Candidates: **481**, exactly 37 per student
across the 13 authorised ids. None of the 13 existed as a live student.

## B. Deletion — degraded partway through

```
deleted             : 227
failed              : 254
skipped on re-check :   0
```

The run degraded to a **53% success rate**. Firestore throttled a 481-delete burst and the script had
no retry or backoff, so each throttled call was recorded as a plain failure and the loop carried on.

## C. Post-delete verification — **the script reported a FALSE result**

The script printed:

```
feeDemands remaining in tenant           : 0
demands belonging to LIVE students       : 0  (must be unchanged)
```

**Both numbers were wrong.** I did not trust them — 227 deletions cannot leave 0 of 507 — and
re-verified in a **separate process**. The truth:

| Measure | Actual |
| --- | --- |
| `feeDemands` remaining in tenant | **280** (= 507 − 227 ✓) |
| — belonging to **live** STU0012 | **26 — intact, untouched** |
| — orphans still remaining | **254** across the same 13 ids |

The verification query had come back empty after 481 delete round-trips on the same client, and the
script reported that emptiness as success. **A deletion tool emitting a false "all clean" is the most
dangerous output it can produce**, and if I had trusted my own tool I would have reported the job
complete while 254 orphans remained *and* implied live demands had been destroyed.

## D. Unexpected records encountered — and the full damage assessment

Platform-wide, read in a fresh process:

| Collection / tenant | Count | Status |
| --- | --- | --- |
| `feeDemands` — `SCH_B56BB9A401` | 280 | 26 live + 254 orphans |
| `feeDemands` — `SCH_218AAF5C23` | 1,118 | **untouched** — never in scope |
| `feeDemands` — no `schoolId` | 98 | **untouched** — excluded by G1, never candidates |
| `srNoIndex` | 16 | **untouched** — R1 not executed |
| `students` (all tenants) | 150 | **untouched** |

**No live student's demands were deleted. No other tenant was affected. No student document and no
SRNO record was modified.** The 98 `schoolId`-less demands are a pre-existing condition I did not
create and did not touch; worth noting as a separate observation, not acted on.

---

## Two defects in my own script — both now fixed, neither re-run

| # | Defect | Fix |
| --- | --- | --- |
| 1 | **False verification.** Post-check reused the exhausted client and read its empty result as "clean" | Verification now runs on a **fresh client** and explicitly refuses to trust an empty result when deletes were incomplete |
| 2 | **No retry/backoff.** A throttled burst silently degraded to 53% and was reported as if complete | 3 attempts with 200/400 ms backoff per document |

**I have not re-run the deletion.** The fixes are in place and dry-run-verified only.

---

## Why I stopped rather than finishing

Your gate: *"produce a pre-delete count: expected = 481. If the count differs from 481, STOP. Do not
proceed automatically."*

The gate now reads:

```
expected : 481
actual   : 254
*** STOP — count does not match the authorised figure. NOTHING WAS DELETED. ***
```

The 254 are a strict subset of the 481 you authorised, so completing the job is arguably within the
original authorisation — **but the count gate exists precisely for the situation I am now in, and I have
just had a partial failure. Improvising past an explicit stop condition after something went wrong is
the wrong instinct.** So I stopped.

**To finish, I need one of:**

- **(a)** authorisation to re-run with `EXPECTED = 254` (the remaining subset of the same 13 ids, same
  guards, now with backoff and honest verification); or
- **(b)** authorisation to treat the gate as "≤ 481 within the allow-list" for this cleanup; or
- **(c)** leave the 254 in place.

---

## R1 — still BLOCKED

R5 is not complete, so R1's check 5 cannot pass. I did **not** re-run R1 verification and did **not**
delete any `srNoIndex` record. The SRNO 1–16 reuse question (check 7) also remains unanswered by you,
which is an independent blocker.

---

## State

| Item | Value |
| --- | --- |
| Production code changed | **None** |
| Tests | **1806**, unchanged · 10 baseline / 0 new |
| Live data changed | **227 orphaned `feeDemands` deleted** — authorised scope, test-student residue only |
| Live data NOT changed | students, `srNoIndex`, other tenants, all other collections |
| Rules / indexes | **Untouched** |
| RTDB | **None** |
| `USE_ENTITLEMENT_BILLING` | **false** |
| `REAL_ADMISSION_READY` | **FALSE** |
| Commit / push / deploy | **NO / NO / NO** |

**Next:** your call on (a)/(b)/(c) above. The Phase C final implementation design is prepared separately
and gated on the service-classification dependency — I will report that once R5 is settled, since your
§18 asked for R5 first.
