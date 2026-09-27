# R1 — ORPHAN srNoIndex CLEANUP: VERIFICATION

**VERDICT: R1 is BLOCKED. Check 5 FAILED. Nothing was deleted.**
**And the failure exposed a larger residue from my own Phase 20.3M cleanup — reported in §3.**
**Read-only · no live data modified · no commit, push or deploy**
**Date:** 2026-08-17

`scripts/r1_orphan_srno_verify.php` — grep-verified free of every delete/write call.

---

## 1. The seven checks

| # | Check | Result |
| --- | --- | --- |
| 1 | All 16 point to a nonexistent student | **PASS** — 16 of 16 candidates, no live `studentId` matches |
| 2 | All 16 have `claimedBy = P203M_UAT` | **PASS** — distribution is `{"P203M_UAT": 16}` |
| 3 | All originate from the authorised testing activity | **PASS** — every history entry `actor = P203M_UAT`, `reason = admission`, confined to `SCH_B56BB9A401` |
| 4 | No real student references those SRNOs | **PASS** — SR 1–16; no live student record carries any of them |
| 5 | **No other business record depends on those claims** | **FAIL** — see §2 |
| 6 | Deleting will not corrupt SRNO history | **PASS** — every candidate's `historicalStudentIds[]` is empty; no live re-admission lineage would be lost |
| 7 | Freeing SR 1–16 is acceptable | **OPERATOR DECISION** — not decidable from data; only you know whether those numbers were ever issued on paper |

**Checks 1–6 are the data-verifiable set, and they do not all pass. Per your rule — *"ONLY if all checks
pass may those exact 16 orphan records be deleted"* — I deleted nothing.**

---

## 2. Why check 5 failed

The 13 UAT students whose SRNO claims are orphaned still have **fee demands** in Firestore:

```
feeDemands in tenant SCH_B56BB9A401 : 507
  belonging to LIVE students        :  26
  ORPHANED (no student document)    : 481   across 13 student ids
```

Exactly **37 demands each** for `STU0168`–`STU0179` and `STU0181` — the 13 students the Phase 20.3M
live Import UAT committed and I then deleted.

So the SRNO claims are not free-standing: deleting them while 481 demands still reference the same
students would remove the identity record and leave the financial records pointing at nothing. That is
the wrong order, and check 5 caught it.

---

## 3. ⚠ A correction to my Phase 20.3M report

My Phase 20.3M cleanup deleted the student documents and the Firebase Auth users. It did **not** delete:

- **16** `srNoIndex` claims (reported in the Phase B audit)
- **481** `feeDemands` (found now)

My 20.3M report said *"the tenant reconciles exactly against the pre-UAT snapshot."* That was true for
the dimensions I actually measured — 35 sections, 23 students, per-section occupancy — but **I never
measured `feeDemands`, and the sentence read as broader than the evidence supported.** That is my error,
and the residue is real.

Everything involved is confined to the authorised testing tenant and to test students. No real school's
data is affected. But the tenant is not as clean as I reported.

---

## 4. Proposed remediation — NOT executed, needs authorisation

Correct order matters: financial records first, then identity.

| # | Step | Scope | Note |
| --- | --- | --- | --- |
| **R5** | Delete the **481 orphaned `feeDemands`** | exactly the 13 ids `STU0168`–`STU0179`, `STU0181`, doubly-keyed on *no student document exists* **and** `schoolId = SCH_B56BB9A401` | Must precede R1, or R1 orphans them further |
| **R1** | Delete the **16 orphaned `srNoIndex`** claims | the exact doc ids listed below | Only after R5, and only with your answer to check 7 |

Deletion candidates for R1, exact doc ids:

```
SCH_B56BB9A401_1   SCH_B56BB9A401_2   SCH_B56BB9A401_3   SCH_B56BB9A401_4
SCH_B56BB9A401_5   SCH_B56BB9A401_6   SCH_B56BB9A401_7   SCH_B56BB9A401_8
SCH_B56BB9A401_9   SCH_B56BB9A401_10  SCH_B56BB9A401_11  SCH_B56BB9A401_12
SCH_B56BB9A401_13  SCH_B56BB9A401_14  SCH_B56BB9A401_15  SCH_B56BB9A401_16
```

I also recommend a **standing rule** for future UAT: the cleanup must enumerate *every* collection a
run can write — student, auth, demands, SRNO claims, entitlements, receipts — rather than only the ones
the run's headline was about. That is the lesson from this miss, and I will apply it to any future UAT.

**Nothing above is executed.** The 150 legacy students were not touched, `SRNO-F3` remains untouched,
and no `srNoIndex` record outside the 16 was examined for deletion.
