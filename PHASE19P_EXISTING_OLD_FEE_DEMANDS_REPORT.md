# PHASE 19P — TARGET #1: EXISTING OLD FEE DEMANDS

**Date:** 2026-08-16 · **Branch:** `integration/phase2` (canonical)
**Status: READY WITH CONDITIONS — production reconciliation not executed**

Not committed · not pushed · not deployed · **no rules/indexes changed** · no production write ·
Target #2 not reopened · Attendance/Examination/Library/Transport/Hostel untouched · no RTDB.

---

## 1. EXECUTIVE SUMMARY

**Two of my own earlier conclusions were wrong, and correcting them is most of this phase's value.**

| Prior claim (19B/19I/19K/19L) | Reality |
|---|---|
| "Historical `studentFeeSummary` reconciliation — **BLOCKED, needs a production credential**" | **Wrong.** A complete, canonical, resumable repair — `Fees::backfill_fee_summaries()` — **already existed**. It was never credential-blocked; it had **no route**, so no operator could invoke it. |
| "The due-date planner should be executed once authorized" | **Incomplete.** The planner is correct and deliberately write-free, but nothing verified whether the *summary* cache agreed with the demands — and **no integrity check compared them**. |

**The real Target #1 defect:** `studentFeeSummary` is a **derived cache** rewritten only when a fee event
touches a student. **Nothing re-derives it when the derivation rule changes.** FEES-FROZEN-2 changed that
rule (fines now count), so every summary written earlier is stale — silently, indefinitely, with no check
able to see it.

| ID | Sev | Finding | State |
|---|---|---|---|
| **FEES-D7-A** | **HIGH** | No integrity check ever compared `studentFeeSummary` to `feeDemands`. Ten checks compared demands/payments/receipts to each other; the cache was unverified. | **FIXED — DB-011** |
| **FEES-D7-B** | **MED** | `Fees::backfill_fee_summaries()` — the repair — had **no route**. Unreachable. | **FIXED** |
| **FEES-D7-C** | — | Due-date backfill is correctly a **proposal with no write path**. Re-verified and **deliberately left that way**. | **upheld** |

**Regression: 1427 tests / 3979 assertions / 10 failures — the identical baseline, 0 new.**
23 new tests. Lint clean. RTDB clean.

---

## 2. WORKTREE (Part 0)

Branch `integration/phase2`. 525 dirty/untracked paths from Phases 19B–19N — **none disturbed**.
`firestore.rules` / `firestore.indexes.json` **not touched and not required**.
Edited: `Fee_integrity_checker.php` (was clean), `Fee_summary_writer.php` (my own 19B work),
`routes.php`, plus one new test file.

## 3. SOURCE TRACE (Part 1)

Traced creation → demand doc → head → net → fine → dueDate → balance → paid → status → allocation →
receipt → accounting → summary → defaulter → reminder → concession → reversal → parent/admin display.

**What actually makes a demand "old":** not its age, but whether it was written under a **superseded
derivation rule**. Three concrete generations exist in `feeDemands`:

1. **Pre-AB (Phase 15.5)** — no `dueDate`. Invisible to the reminder service and the late-fee engine.
2. **Pre-FEES-FROZEN-1** — module demands whose `balance` was re-derived without the fine.
3. **Pre-FEES-FROZEN-2** — *summaries* aggregated without the fine. **The demands are correct; the cache
   is not.**

Generation 3 is the one nothing could detect.

## 4. HISTORICAL DEMAND TAXONOMY (Part 1)

| | Category | Actually a defect? | Safe to modify? | Never change | Correct value derived from |
|---|---|---|---|---|---|
| A | Structurally valid | No | n/a | — | — |
| B | **Missing `dueDate`** | Only if unpaid | **Proposal only** | paid/archived demands | `periodKey` + current due day — **ambiguous if no canonical periodKey** |
| C | Missing/corrupt `fineAmount` | No — absent means none | No | the value itself | — |
| D | **Incorrect `balance`** | Yes, pre-FROZEN-1 | Yes, recompute | `paidAmount` | `max(0, net + fine − paid)` |
| E | Incorrect `status` | Derived from D | Yes | — | balance ≤ 0.005 → paid |
| F | **Summary understated** | **Yes — the target** | **Yes, cache only** | **every demand field** | canonical aggregation |
| G | Invalid fee-head linkage | Resolver defaults to 4010 | No | — | — |
| H | Already paid | **No** | **NO** | everything | — |
| I | Partially paid | Only if D applies | Balance only | `paidAmount`, receipts | — |
| J | Reversed/cancelled | **No** — `balance 0 / status reversed` is deliberate | **NO** | everything | — |
| K | Concession/exemption | No — `netAmount` is already post-discount | No | discount fields | — |
| L | Has a receipt | **No** | **NO** | receipt, allocation, journal | — |
| M | Already reconciled | No | n/a | — | — |
| N | **Never had a summary** | Yes | Yes, create | demands | canonical aggregation |

**Only D, F and N are repairable. B is a proposal. H, J, L are untouchable.**

## 5. FEES-D7 FINDINGS (Part 2)

**FEES-D7 is not one problem — it is three, and only one is a field backfill.**

| Sub-issue | Type | Collection | Blocked by |
|---|---|---|---|
| Missing `dueDate` | field backfill | `feeDemands` | **product decision** — see §7 |
| Stale summary | **derived-cache reconciliation** | `studentFeeSummary` | **nothing** — repair exists, now routed |
| Pre-FROZEN-1 module balance | financial recompute | `feeDemands` | self-heals on the next regeneration |

- **Scope:** session-scoped. Both the audit and the planner filter `session == $this->session`, and the
  CLI takes a required `SESSION_YEAR` env var — so an operator targets **one session at a time**. I
  initially read this as a blind spot (old demands live in prior sessions) and **checked before
  reporting it**: it is a deliberate per-session operating model, not a defect.
- **Paid vs unpaid differ materially:** `fineFor()` skips `paid` and `archived`, so a dateless *paid*
  demand is inert. Only **unpaid** dateless demands matter.
- **Receipts:** untouched by any repair here.
- **Parent App / defaulters:** both read the demand's `balance` and the summary's `totalBalance`, so a
  stale summary is visible to families and collections alike.

## 6. studentFeeSummary FINDINGS (Part 4)

`Fee_summary_writer::updateStudentSummary()` recomputes from demands — **the summary is fully
reconstructible**. The gap was never the ability to rebuild; it was:

1. **no way to know a rebuild was needed** → fixed by DB-011;
2. **no way to invoke the rebuild** → fixed by the route.

**Comparison demanded by Part 4:**

| Source | Fine-aware? |
|---|---|
| `feeDemands.balance` | ✅ (post-FROZEN-1) |
| `Fee_summary_writer` | ✅ (post-FROZEN-2) |
| `Fee_summary_reader` fallback | ✅ (post-FROZEN-2) |
| `Fee_integrity_checker:322` | ✅ always was |
| `Fee_defaulter_check` | ✅ sums `balance` |
| **Stored summaries written before FROZEN-2** | **❌ stale** |

**Chosen strategy: (C) use the existing `Fee_summary_writer`, invoked via the existing
`backfill_fee_summaries()`.** No second summary calculation was created — a hard constraint of the brief
and the exact drift that caused this defect in the first place.

To honour it I made the writer's private aggregation reachable as `aggregateDemands()` and had DB-011
call **that**, rather than reimplementing the arithmetic inside the checker.

## 7. DUE-DATE FINDINGS (Parts 6 & 14) — **STOP condition: product decision**

The planner is correct and **deliberately has no `$commit`**. I re-verified that and **upheld it rather
than adding one.** Reasons:

1. **Reconstruction uses the school's CURRENT due day.** If a school changed its due day mid-year, a
   rebuilt date differs from what was operationally true. ZenX stores no history of that setting, so
   this is undetectable from data.
2. **Retroactive fining.** Stamping a `dueDate` on an old **unpaid** demand makes it immediately eligible
   for a late fee. Mitigating factor, verified: `computeForSchool()` is also session-scoped, so a
   *prior-session* demand will not be fined by the current cron. But within the same session, backfilling
   a months-overdue demand and running the cron would generate a penalty the family never had an
   opportunity to avoid.

> **This is a STOP, per Part 14.** Whether a historical demand should acquire a due date — and therefore
> become finable — is a **business decision about money**, not an engineering one. `FEES-D7-DUE` below.

## 8. FINANCIAL SAFETY (Part 3)

**Nothing in this phase modifies financial history.** DB-011 is read-only — test-asserted against
`firestoreSet`, `firestoreCreate`, `->set(`, `->update(`. The repair rebuilds a **derived cache** from
demands; it never rewrites `paidAmount`, receipts, allocations, journals, original amounts, concessions
or payment dates.

**The canonical equation was reused, not invented** — `Fee_summary_writer::aggregateDemands()`, itself
`max(0, net + fine − paid)` per demand, the same relation proven across five independent sites in 19B.

**A divergence is a stale cache, never a financial error.** The demands are authoritative.

## 9. INDUSTRY RESEARCH (Part 10)

| Finding | Class |
|---|---|
| Derived aggregates are rebuilt from source-of-truth transactions, never hand-corrected | `SOURCE-VERIFIED` (standard double-entry practice) |
| Historical postings are corrected by adjusting entries, never by mutating the original | `SOURCE-VERIFIED` |
| ZenX already models the summary as a cache and demands as the source of truth | **`ZENX SOURCE-VERIFIED`** |
| Rebuild the cache; never touch the ledger | `ZENX RECOMMENDATION` |
| Whether a historical demand should become finable | **`USER DECISION REQUIRED`** |
| A binding Indian K-12 rule on retroactive due dates | **`NOT ESTABLISHED`** |

**No conflict with ZenX's accounting model** — the chosen approach is the one it already implies.

## 10–11. ARCHITECTURE + IMPLEMENTATION (Part 11)

**Defect:** stale summary caches, undetectable and un-repairable in practice.
**Correction:** a read-only divergence check (dry run) + a route for the existing canonical repair.
**Why safe:** read-only detection; repair rebuilds a derived cache using the one canonical writer.
**Changes:** `studentFeeSummary` only, and only when the operator runs the repair.
**Never changes:** demands, receipts, journals, paidAmount, concessions.
**Cost:** DB-011 = **2 queries per school/session**, then in-memory — never per student (test-asserted).
**Migration:** none — no schema change, no index, no rules.
**Rollback:** the summary is derived; re-running the repair restores it from demands.

**Delivered**

1. `Fee_summary_writer::aggregateDemands()` — exposes the existing private aggregation. **One
   implementation retained** (test-asserted).
2. `Fee_integrity_checker::checkSummaryDivergence()` — **DB-011**, read-only, registered in `runAll()`
   so it runs with every sweep. Reports per student: stored vs expected balance, delta, stored vs
   expected status, demand count, and a **risk grade** — `HIGH` when the cache *understates* what is
   owed, because that under-bills the family and can hide them from the defaulters list entirely.
   Distinguishes `missing_summary` from `balance_mismatch`/`status_mismatch`.
3. Route `fees/backfill_fee_summaries` → the existing repair (gated `Fees:manage`, resumable, soft
   deadline, uses the canonical writer).

## 12. MIGRATION / DRY RUN (Parts 5 & 12)

**Dry run = DB-011**, already surfaced to operators through
`Fee_management::integrity_report()` → `runAll()`. No new UI was needed.

It reports school, session, student, current vs proposed state, the exact changing fields, delta,
whether a summary exists, and risk. **It writes nothing.** Ambiguous cases are not auto-modified — the
due-date planner marks non-canonical `periodKey` rows `ambiguous` and leaves them alone.

**Status: ENGINEERING READY — PRODUCTION RECONCILIATION NOT EXECUTED.** I have no production credential
and did not run it. A local dry run is **not** a production migration and is not claimed as one.

**Operator procedure:** run the integrity report → read DB-011 → if divergences are listed, `POST
fees/backfill_fee_summaries` (optionally class/section-scoped) → re-run the report → DB-011 should pass.

## 13–15. ADMIN / PARENT / TEACHER (Part 8)

**Admin Panel** — DB-011 appears automatically in the integrity report; the repair is now invocable.
**One gap registered, not half-built:** there is no *button* beside the integrity report to trigger the
repair — it is reachable by route but not by click (**FEES-D7-UI**, LOW, §18).

**Parent App** — reads `feeDemands` (authoritative, correct) and the summary for outstanding totals. A
stale summary **is** visible to families. The repair corrects it with **no app change** — `FeeDemandDoc`
and the fee list already read the right fields.

**Teacher App** — **not applicable.** Verified: it surfaces no fee balance or summary view.

## 16. SRNO AUDIT (Part 9) — **PASS**

| Check | Result |
|---|---|
| SR No. replaced by Roll No. anywhere in this work | **PASS** — never; DB-011 contains no `rollNo` |
| SR No. used as a foreign key | **PASS** — never; aggregation keys on `studentId` |
| Student ID remains system identity | **PASS** |
| Adm No. / SR No. / Roll No. remain distinct | **PASS** |
| Receipts/documents touched by this work | **PASS** — none touched |

**SRNO AUDIT: PASS.** No SRNO issue found or introduced.

## 17. TESTS (Part 13)

| Metric | Value |
|---|---|
| TOTAL TESTS | **1427** |
| TOTAL ASSERTIONS | **3979** |
| BASELINE FAILURES | **10** (Backup ×1, Homework ×5, ParentHomeworkRepo ×3, TeacherDeleteCascade ×2) |
| **NEW FAILURES** | **0** |
| NEW TESTS | **23** |
| LINT | clean |
| RTDB SCAN | clean |
| RULES / INDEXES | unchanged |

**Not "all tests pass"** — the 10 pre-existing failures remain, unrelated and untouched.

Covered: fine-carrying summaries · the fine-only debtor reading as `paid` · fine-free history **not**
flagged (a check that flags everything is noise) · penny rounding · missing summary · one-calculation
enforcement · read-only assertions · route + RBAC · planner write-free · ambiguity refusal · session
scoping · SRNO · query cost.

**Part 7 re-verified, no regression:** FEES-FROZEN-1 · FEES-FROZEN-2 · AB · F-1 · G-3 · ID-D2 ·
ID-RACE-1 all present and passing. Nothing here can erase a fine or payment, reset a balance, flip paid →
unpaid, or duplicate a demand/receipt/late fee — the repair only ever rewrites a derived aggregate.

## 18. REMAINING ISSUES — TARGET #1

| ID | Sev | Issue | Blocked by |
|---|---|---|---|
| **FEES-D7-DUE** | MED | Should historical demands acquire a due date, and thereby become finable? | **PRODUCT DECISION** (§7) |
| **FEES-D7-PROD** | — | Production reconciliation not executed | **production credential + authorization** |
| **FEES-D7-UI** | LOW | No button beside the integrity report to run the repair | — (registered, not half-built) |

### The decision I need from you — FEES-D7-DUE

Three options, and I recommend the third:

| | Option | Consequence |
|---|---|---|
| A | Backfill all reconstructable due dates | Same-session overdue demands become finable — families penalised for a window they never had |
| B | Never backfill | Old unpaid demands stay unremindable forever |
| **C** | **Backfill, but only where the demand is unpaid AND set the date forward** (e.g. today + grace) | Reminders start working; **no retroactive penalty**. Requires a small planner change |

**C needs your approval before I build it** — it changes what a family owes, which is precisely the
boundary Part 14 says to stop at.

## 19. SIX-ITEM PRIORITY STATUS

| # | Item | Status | Note |
|---|---|---|---|
| 1 | **Existing old fee demands** | **READY WITH CONDITIONS** | Detection + repair engineering-complete and reachable. **Production run not executed**; **FEES-D7-DUE** decision open |
| 2 | ID Card regeneration charges | **READY FOR UAT** | ID-D7 amounts open; ships at ₹0. Not reopened |
| 3 | Transport/Hostel/module late fees | **OPEN** | FEES-D16 — product decision |
| 4 | Fees UAT | **BLOCKED** | live tenant + operator |
| 5 | Bulk Roll Assignment + existing-roll policy | **OPEN** | D19 — product decisions |
| 6 | Atomic capacity / roll concurrency | **OPEN** | CAP-2 — needs design |

## 20. CLOSURE DECISION (Part 15)

**TARGET #1 — READY WITH CONDITIONS. Not CLOSED.**

✅ FEES-D7 fully traced · ✅ categories identified · ✅ safe repair proven · ✅ dry run exists (DB-011) ·
✅ no historical truth corrupted · ✅ one authoritative calculation · ✅ paid/unpaid/partial/concession/
reversed cases classified · ✅ accounting integrity (nothing touched) · ✅ Admin verified · ✅ Parent
verified · ✅ Teacher N/A · ✅ **SRNO PASS** · ✅ 0 new regression failures
❌ **production reconciliation not executed** · ⏸ **FEES-D7-DUE decision** · ❌ **UAT not executed**

**I have no production credential and no browser. Neither a production migration nor UAT is claimed.**

**Two things needed from you:** authorize the production reconciliation run, and decide **FEES-D7-DUE**
(I recommend option C).

**Awaiting your next authorization. Not starting any other module.**
