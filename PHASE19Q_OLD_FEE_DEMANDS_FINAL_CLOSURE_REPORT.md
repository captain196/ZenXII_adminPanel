# PHASE 19Q — TARGET #1 FINAL CLOSURE: EXISTING OLD FEE DEMANDS

**Date:** 2026-08-16 · **Branch:** `integration/phase2` (canonical)
**Status: READY WITH CONDITIONS — engineering complete; production execution blocked on credentials**

Not committed · not pushed · not deployed · **no rules/indexes changed** · **no production write** ·
Target #2 not reopened · Targets #3–#6 not started · no RTDB.

---

## 1. EXECUTIVE SUMMARY

**Option C is implemented, and the Part 2 gate was satisfied without inventing a single money-policy
setting.**

The forward due date is **the next future occurrence of the school's own configured due day**
(`getDueDay()`) — the same value the live generator already uses. I deliberately did **not** reuse
`late_fee_grace_days`: it means *"days after due before a fine"*, and overloading it would give one
setting two unrelated meanings.

| Delivered | |
|---|---|
| `nextDueDate()` | strictly forward; landing on the due day rolls to next month |
| `forwardDueDateRepair()` | dry-run by default; writes **only `dueDate`**, via the canonical `updateDemand()` |
| `redate_outstanding_due_dates` | CLI, `--commit` must be typed |
| Eligibility | `unpaid`/`partial` **and** outstanding **and** undated. Everything else refused with a reason |
| `REQUIRES_REVIEW` | self-contradicting records left strictly alone |

**The central safety property, proven across a six-point timeline: repairing a demand never creates a
penalty.** A demand re-dated today is not finable today, tomorrow, or on its new due date — only after
it passes, at which point ordinary rules resume.

**Regression: 1469 tests / 4057 assertions / 10 failures — the identical baseline, 0 new.**
42 new tests. Lint clean. RTDB clean.

---

## 2. CORRECTIONS TO PHASE 19P

I re-verified 19P against live source. **Its findings hold** — DB-011 present and registered in
`runAll()`, `aggregateDemands()` exposed with one implementation retained, the repair route present, the
planner still write-free.

One refinement, not a correction: 19P said the due-date question was "the only substantive business
decision". More precisely, it was **two** questions — *which* demands should be re-dated (an engineering
eligibility rule, now implemented) and *what date* they should get (the policy call, now answered by
reusing the school's own due day). Separating them is what let Option C be built without a new setting.

## 3. OLD DEMAND TAXONOMY — re-run (Part 11)

| | Category | Verdict this phase |
|---|---|---|
| A | Structurally valid | untouched |
| **B** | **Missing `dueDate`** | **NOW REPAIRABLE** — forward re-date, unpaid/partial + outstanding only |
| C | Missing/corrupt `fineAmount` | intentionally untouched — absent means none |
| D | Incorrect balance (pre-FROZEN-1) | self-heals on next regeneration; not force-rewritten |
| E | Incorrect status | derived from D |
| F | **Summary understated** | repairable — DB-011 detects, `backfill_fee_summaries` repairs |
| G | Invalid fee-head linkage | untouched |
| **H** | **Already paid** | **UNTOUCHABLE** — refused, reason logged |
| I | Partially paid | **eligible for re-date only**; `paidAmount` never altered |
| **J** | **Reversed/cancelled** | **UNTOUCHABLE** — refused even when carrying a stale balance |
| K | Concession/exemption | untouched — `netAmount` is already post-discount |
| **L** | **Has a receipt** | **UNTOUCHABLE** |
| M | Already reconciled | no-op — a dated demand is ineligible |
| N | Never had a summary | repairable via the canonical writer |

**Repairable: B, F, I (date only), N. Proposal-only: none remaining. Untouchable: H, J, L.**

## 4. FEES-D7-DUE — DECISION IMPLEMENTED (Parts 2, 4, 5)

### The Part 2 gate

| Candidate setting | Meaning | Reused? |
|---|---|---|
| `late_fee_grace_days` | days **after** due before a fine | ❌ — overloading it would give one setting two meanings |
| **`getDueDay()`** (1–28, default 10) | the school's **billing due day** | ✅ **reused** |
| `days_before_due` / `afterDays` | reminder stages | ❌ not a due-date source |

**No new money-policy setting was created**, and no hard-coded day offset exists (test-asserted).

### Eligibility (Part 4)

Every candidate is reported with `demandId`, `studentId`, session, fee head, `netAmount`, `fineAmount`,
`paidAmount`, `balance`, `status`, current `dueDate`, `periodKey`, eligibility and reason.

**Eligible:** status ∈ {`unpaid`, `partial`} **and** balance > 0.005 **and** no existing `dueDate`.
**Refused:** already dated · `paid` · `archived` · `reversed` · `cancelled` · any unrecognised status ·
nothing outstanding.
**`REQUIRES_REVIEW`, never auto-modified:** missing `studentId`/`demandId`, or a record whose
`status = unpaid` contradicts a non-zero `paidAmount`. **Never guess at money.**

### Forward date (Part 5)

Strictly forward by construction. Landing exactly on the due day **rolls to next month**, so a demand
repaired on the 10th cannot be due that same evening. Year boundaries handled. `$today` is injectable so
tests are not clock-dependent.

## 5. SUMMARY RECONCILIATION (Part 7) — unchanged and canonical

DB-011 still compares stored `studentFeeSummary` against `Fee_summary_writer::aggregateDemands()`, still
registered in `runAll()`, still read-only. **Exactly one `_aggregateStudentDemands()` implementation**
remains (test-asserted). The repair route is intact and resumable.

## 6. FINANCIAL SAFETY (Part 3)

**Only `dueDate` is ever written.** Proven by asserting against the **write call**, not the method body:
exactly one `updateDemand()`, payload `['due_date' => $newDue]`, and no `firestoreSet`/`firestoreCreate`/
`firestoreDelete`/`writeDemand`/`commitBatch`/`feeReceipts`/`accountingLedger` anywhere in the method.

> A note on that test. My first version banned the strings `'status' =>` and `'balance' =>` from the whole
> method and **failed against correct code** — the method legitimately *reports* those fields in its
> dry-run rows. A blanket scan cannot tell reporting from writing. Targeting the write call is both
> correct and stricter.

Never touched: gross · net · concession · exemption · `paidAmount` · receipts · allocations · journals ·
postings · payment dates · transaction dates.

## 7. DUE-DATE / LATE-FEE SAFETY (Part 6) — the six-point timeline

Repair day `2026-04-01`, due day 10 → new due date **`2026-04-10`**:

| When | Finable? | |
|---|---|---|
| Repair day (2026-04-01) | **No** | repairing never creates a penalty |
| TODAY + 1 (04-02) | **No** | |
| Day before due (04-09) | **No** | |
| **ON the due date (04-10)** | **No** | `fineFor` requires `dueDate < cutoff` |
| **DUE + 1 (04-11)** | **Yes** | ordinary rules resume |
| Well past (05-01) | **Yes** | |

**Contrast, test-asserted:** the reconstructed date option A would have produced (`2025-04-10`) **is
instantly finable** on 2026-04-01. That is exactly why A was rejected.

`late_fee_grace_days` still delays the fine afterwards (verified at grace 5: not finable on 04-12,
finable on 04-16). Reminders cannot fire an overdue notice before the new due date — `stageFor()` derives
every stage from `dueDate`. A dateless demand remains un-finable, so the pre-repair state was stranded
but never penalised.

**The repair is naturally idempotent:** after the first run the demand *has* a `dueDate` and is therefore
ineligible on every subsequent run.

## 8. FEES-D7-UI (Part 8) — **deliberately not built**

The brief allows *"do not create a UI if the existing architecture has a better established operational
pattern."* It does, and I chose it:

- **Detection** is already operator-facing — DB-011 appears in `Fee_management::integrity_report()`.
- **Repair** follows this repo's established pattern for bulk data operations: a **CLI-only cron**
  (`Fees_late_fee_cron`, matching `Transport_expiry_cron`), where `--commit` must be typed and cannot be
  mis-clicked.

Adding a one-click production write beside a report would be a *worse* pattern for an operation that
rewrites derived data across a whole school. **FEES-D7-UI is closed as WON'T-DO with that rationale**,
not left dangling. The summary repair route remains available for a scoped class/section run.

## 9. PRODUCTION MIGRATION (Parts 9–10) — **ENGINEERING READY · EXECUTION BLOCKED**

**I have no production credential. Nothing was executed and no counts are manufactured.**

### Procedure

```bash
# 1. BASELINE — read-only
#    Admin → Fee Management → integrity report → record DB-011:
#      affected students · missing summaries · balance mismatches
#      · status mismatches · HIGH-risk count

# 2. DUE-DATE DRY RUN  (per school, per session)
SCHOOL_ID=SCH_XXXXXX SESSION_YEAR=2025-26 \
  php index.php fees_late_fee_cron redate_outstanding_due_dates

# 3. REVIEW  — confirm eligible rows are genuinely outstanding;
#    resolve every REQUIRES_REVIEW row by hand. Do not proceed otherwise.

# 4. COMMIT  — only with explicit authorization
SCHOOL_ID=SCH_XXXXXX SESSION_YEAR=2025-26 \
  php index.php fees_late_fee_cron redate_outstanding_due_dates --commit

# 5. SUMMARY REPAIR (optionally class/section-scoped)
POST fees/backfill_fee_summaries        # Fees:manage

# 6. POST-RUN VERIFICATION — re-run the integrity report.
#    Expected: DB-011 reports zero unexplained divergences.
```

**Scope controls:** school + session are **mandatory** env vars; summary repair additionally accepts
class/section and `students_only`.
**Resume:** the summary repair carries a soft deadline and returns `resumeHint`/`lastProcessedSid`.
**Failure:** the re-date exits **3** if any write failed and names each; re-running is safe because
completed rows are now ineligible.
**Rollback:** `dueDate` is the only field written — it can be cleared, and the summary is derived, so
re-running the repair restores it from demands.

**If DB-011 still reports divergences after step 6, Target #1 is NOT closed** and every remaining
divergence must be classified.

## 10–12. ADMIN / PARENT / TEACHER (Part 14)

**Admin Panel** — student fee view, demand list, outstanding balance and defaulter report all read
`balance`/`totalBalance` and correct themselves once the summary repair runs. DB-011 is visible in the
integrity report. The re-date is CLI by design (§8).

**Parent App** — reads `feeDemands` (authoritative) plus the summary for totals. **A re-dated demand now
shows a due date where it previously showed none**, and becomes eligible for reminders. `FeeDemandDoc`
already reads `dueDate` — **no app change required or made.**

**Teacher App** — **NOT APPLICABLE.** Re-verified: no fee balance, demand or summary surface exists.

## 13. ID CARD REGRESSION (Part 12) — **PASS**

Target #2 not reopened. Verified intact: version-scoped fee head · `4140` keyword mapping · `due_date` on
the replacement payload · duplicate-replacement protection.

**An ID-card replacement demand is inherently ineligible for this repair** — it already carries a
`dueDate` (ID-DUE-1), so it is refused as "already has a due date". Test-asserted.

## 14. SRNO AUDIT (Part 13) — **PASS**

| Check | Result |
|---|---|
| SR No. replaced by Roll No. | **PASS** — never; the repair contains no `rollNo` |
| SR No. used as a foreign key | **PASS** — never |
| Student ID canonical identity | **PASS** — repair keys on `studentId`/`demandId` |
| Adm No. / SR No. / Roll No. distinct | **PASS** |
| Receipt/document/report substitution | **PASS** — none touched |

**SRNO AUDIT: PASS.** No SRNO defect found or introduced.

## 15. TESTS (Part 15)

| Metric | Value |
|---|---|
| TOTAL TESTS | **1469** |
| TOTAL ASSERTIONS | **4057** |
| BASELINE FAILURES | **10** (Backup ×1, Homework ×5, ParentHomeworkRepo ×3, TeacherDeleteCascade ×2) |
| **NEW FAILURES** | **0** |
| NEW TESTS | **42** |
| LINT | clean |
| RTDB SCAN | clean |
| RULES / INDEXES | **unchanged** |

**Not "all tests pass"** — the 10 pre-existing failures remain, unrelated and untouched.

All 20 required cases covered: old unpaid · old paid · partially paid · reversed · cancelled/archived ·
concession · missing summary · stale summary · **fine-only outstanding** · forward assignment ·
**no immediate late fee** · reminder timing · duplicate execution (idempotent) · resumable repair ·
ambiguous → `REQUIRES_REVIEW` · session isolation · class/section scope · **ID-card regression** · SRNO ·
read/write safety.

## 16. PRODUCTION / UAT STATUS

**Production reconciliation: NOT EXECUTED — no credential.**
**UAT: NOT EXECUTED — no browser or live tenant.**
Both are stated plainly; neither is claimed, and no counts, financial outcomes or run results are
manufactured anywhere in this report.

## 17. SIX-ITEM QUEUE

| # | Item | Status |
|---|---|---|
| 1 | **Existing old fee demands** | **READY WITH CONDITIONS** — engineering complete; production execution blocked on credential + authorization |
| 2 | ID Card regeneration charges | **READY FOR UAT** — ID-D7 amounts open; ships at ₹0 |
| 3 | Transport/Hostel/module late fees | **OPEN** — FEES-D16, product decision |
| 4 | Fees UAT | **BLOCKED** — live tenant + operator |
| 5 | Bulk Roll Assignment + existing-roll policy | **OPEN** — D19, product decisions |
| 6 | Atomic capacity / roll concurrency | **OPEN** — CAP-2, needs design |

## 18. FINAL CLOSURE DECISION (Part 16)

**TARGET #1 — READY WITH CONDITIONS. Not CLOSED.**

✅ FEES-D7 fully traced · ✅ taxonomy verified · ✅ stale-summary detection works · ✅ canonical repair
route works · ✅ **due-date policy safely implemented (Option C)** · ✅ **no retroactive fine created** ·
✅ no historical financial truth changed · ✅ summary reconciles via one calculation · ✅ paid/unpaid/
partial/reversed/concession verified · ✅ Admin verified · ✅ Parent verified · ✅ Teacher N/A ·
✅ **SRNO PASS** · ✅ **ID-card regression PASS** · ✅ 0 new test failures · ✅ FEES-D7-UI resolved
(WON'T-DO, reasoned)
❌ **production reconciliation not executed** · ❌ **post-reconciliation DB-011 not verified** ·
❌ **UAT not executed**

**Three closure gates remain, and all three are external to engineering:** a production credential,
authorization to run the commit, and the post-run DB-011 verification that follows it.

**This is as far as engineering can safely take Target #1.** Everything buildable is built, tested and
documented; what remains is an authorized production run.

**Stopping here. Not starting Target #3 or any other module. Awaiting explicit authorization.**
