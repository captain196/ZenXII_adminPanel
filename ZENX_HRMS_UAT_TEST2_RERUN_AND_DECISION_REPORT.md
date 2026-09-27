# ZENX HRMS — TEST 2 RE-RUN VERIFICATION + UAT CONTINUATION DECISION

**Date:** 2026-08-09 · **Type:** verification + read-only investigation
**Writes performed:** **ZERO.** No configuration changed. No UAT operation executed.

---

## 0. UAT REVERSIBILITY PROTOCOL — adopted, and currently unused

Per the mid-turn safety directive, this protocol is now mandatory before **any** UAT operation:

| Step | Requirement |
|---|---|
| 1 | Record pre-test state (exact documents + values) |
| 2 | Identify exactly what data/configuration the operation changes |
| 3 | Confirm the reversal method **before** executing |
| 4 | Reversal must use the **application's supported lifecycle**, never direct DB manipulation |
| 5 | Verify state **after** cleanup — never claim cleanup succeeded unverified |

**Current rollback debt: NONE.** Every action I have taken this entire gate is read-only:
`scripts/uat_preconditions.js`, `scripts/uat_inspect_run.js`, and one inline read query. **No
document was created, modified or deleted. No tenant configuration was touched.**

The one write that exists — payroll run **PR0002** — **you** created in Test 2, through the
application. It is `Draft`, and reversible via the supported `delete_payroll_run` workflow.

**Legitimate bug fixes are NOT rollback candidates.** The three `log_message` corrections are
production fixes and stay.

---

## 1. Test 2 re-run — **I CANNOT PERFORM IT**

§1 asks me to re-run Test 2 and verify items 1–6 (valid JSON, no HTML prefix, `r.json()` succeeds,
no undefined-key error, no write failures, same controller path).

**All six require an authenticated browser session. I have none.** Re-running Test 2 is your action.
I will not mark it PASS from a source change — that is precisely what §1 forbids.

**What I verified instead (items 7–8):**

### Item 7 — are the three replacements actually the responsible path? **YES, traced**

The stack you pasted names it exactly:

```
Hr.php:9278  log_message()      ← _process_leave_for_payroll (CR-3 leave-type fallback)
Hr.php:6251  _process_leave_for_payroll()   ← inside generate_payroll's leave loop
```

`system/core/Log.php:106` defines `$_levels = ERROR|DEBUG|INFO|ALL`. `'warning'` is absent, so
line 181's `$this->_levels[$level]` lookup throws under PHP 8.1, and CI emits the HTML block into
the response body **before** the JSON.

Line 9278 is now `'error'`. Verified in situ, along with 5226 and 9203. `grep -c` in `Hr.php` = **0**
remaining. **136 remain repo-wide and were deliberately left alone** (§2).

**Why this fires at all:** it only triggers when a leave record references a **deleted leave type**.
Your May data has 3 `L` days for STA0001 — so a leave type is missing in that tenant. Expect it to
recur on any run touching those leave records, which is why the fix matters beyond cosmetics.

### Item 8 — regression: **461 tests / 1,493 assertions / 10 failures** — documented baseline, no new failures.

---

## 2. N-21 — untouched repo-wide, as instructed

3 payroll-path sites fixed · **136 left in place** · status remains **PARTIAL / W5** · no guard
weakened, deleted or reclassified.

---

## 3. The 38 seconds — partial profile, NOT yet evidenced

**I have not profiled it, and I am not claiming a cause.** What I can state from the code and the
run data, without instrumentation:

**Fixed per-run cost (independent of headcount):** roster query · staff PII mirror merge · salary
structures (via the W3.1 seam) · attendance summaries · leave requests · leave types · holiday
service · overrides · existing-run check · account validation (`_validate_accounts` × 8 codes) ·
run doc write · accrual preflight · journal post.

**Per-employee cost:** `_compute_payslip` (0.008 ms — negligible) + **one batched slip write for the
whole run**.

**What the run actually shows:** 8 employees → **1 slip written** → the batch was a single write of
one document. So **~38 s was almost entirely fixed cost, not write cost.**

**That is a real correction to my W3.2.5 conclusion.** I measured the *per-employee* term (round
trips) and reported a 385× reduction, but the *per-run* term was never measured — and at this tenant
size it dominates completely. Batching is still correct; it simply does not address what is slow here.

**`N-22` stays PARTIAL.** Closing it requires timing instrumentation around each stage above. I have
**not** claimed async is required, and I have **not** claimed batching solved scalability.

---

## 4. The 1-slip / `total_net: 0` case — **arithmetically correct, with a finding**

Stored evidence for **Vipul TIwari (STA0001)**, run PR0002:

| Field | Value |
|---|---|
| `_salary_snapshot` | basic 14000 · hra 600 · da 300 · ta 30 · medical 25 · other 45 · pf 12% · esi 0.75% · pt 200 |
| `day_wise` | `LLLAAAAAAAAAAAAAAAAAAAAAAAAAAAA` (3 L, then 28 A) |
| `derived` | days_absent **24** · paid_leave_days **3** · vacant_days 0 |
| `working_days` | 24 · `days_in_month` 31 · holidays `[]` |
| `bases` | `absent_fraction: 1` · `retention_ratio: 0` · all bases 0 |
| Result | gross **0** · deductions **0** · net **0** |

**The arithmetic is internally consistent.** 24 absent of 24 working days → retention 0 → every
earning × 0 → gross 0 → all percentage deductions 0 → net 0. `gross == net + deductions` holds.
The trace reconciles. **This is not a calculation bug.**

**But two things in the derivation deserve your attention — I am reporting, not concluding:**

1. **28 `A` marks were counted against 24 working days, then capped.** The month has 31 days of
   which 24 are working; the other 7 are Sundays/off-days. The parse counts **every** `A` in the
   employment window regardless of whether that day was a working day, then clamps to
   `working_days`. So absences marked on non-working days contribute to the count before the clamp
   hides the overflow.

2. **3 paid-leave days produced no pay.** `paid_leave_days: 3` is recorded, yet retention is 0. The
   clamp to 24 consumed them: with 3 paid-leave days, the maximum chargeable absence should arguably
   be 21, giving retention 3/24 rather than 0.

**Whether that is wrong depends on a business rule I will not invent.** For a 100%-absent month ₹0
is defensible; the *mechanism* reaching it is imprecise. Registered as **`N-23`** rather than
silently fixed — it is pre-existing engine behaviour, not something W3 introduced, and changing
absence semantics mid-UAT would violate §6.

---

## 5. UAT CONTINUATION DECISION (6A–6D)

### A. Can Tests 3–8 run on the current May run?

**Partially YES — more than I expected, and with no configuration change.**

| Test | On May (PR0002, Draft, 1 slip) | Verdict |
|---|---|---|
| **Test 3** grid | 1 row only — column alignment still checkable, but thin | **WEAK** |
| **Test 4** trace | Slip **has** `_attendance_snapshot` + `_calculation_trace` → expect **green** banner, full chain | **RUNNABLE** |
| **Test 5** override | Run is **Draft** → form available | **RUNNABLE** |
| **Test 6** regeneration | Draft → regenerate allowed | **RUNNABLE — and unusually clean** |
| **Test 7** LOP | 3 paid-leave days, **0 unpaid** → no LOP panel expected | **NOT TESTABLE** (valid result) |
| **Test 8** finalize/unlock | Draft → finalize, then unlock | **RUNNABLE** |

**Test 6 is actually *better* from a zero base**: original gross 0 → +₹5,000 bonus → revised gross
5,000. Every figure is trivially checkable by eye, and it directly exercises §6c (does net rise by
*less* than 5,000, because ESI/TDS bases move).

### B. Can April, June or July do better?

| Period | Data | Verdict |
|---|---|---|
| **2026-04** | **7 slips, real varied numbers** (net ₹11,757 – ₹38,277), 6 employees with clean attendance | **Best for Test 3** — but status is **Paid**, and its slips have **no** `_attendance_snapshot` / `_calculation_trace` (they predate W3.2.1/W3.2.4) |
| 2026-06 | Only STA0001, 29 `A` of 30 | Same zero-pay shape as May |
| 2026-07 | Only STA0001, 30 `A` of 31 | Same |

**April is genuinely useful for Test 3** (7 rows, real money, proves column alignment and the
Adjustments column against realistic data) — **read-only, no unlock required.**

**April also gives a free bonus test for Test 4:** running the trace on an April slip should show
the **amber "no trace recorded"** banner, exercising the missing-evidence path I built. Confirming
that would verify the graceful-degradation branch that no test has touched.

### C. What legitimate test-data preparation would be required?

**None, for Tests 3–6 and 8.** They can proceed on the existing May Draft run plus a read-only look
at April.

Only **Test 7 (LOP)** genuinely lacks data — it needs **approved unpaid leave**, which no period has.
Creating that is real HR data, and I am not proposing it.

### D. Reversible vs persistent

| Operation | Persists? | Supported reversal |
|---|---|---|
| Viewing April grid / trace | No — read-only | n/a |
| Test 4 trace on May | No — read-only | n/a |
| Test 5 create override | **Yes** — a `payrollOverrides` doc | Void via UI (never deleted — audit by design) |
| Test 6 regenerate | **Yes** — slip rewritten, old archived to `slip_archive`, delta journal posted | Regenerate again after voiding; journals reverse by delta, never by deletion |
| Test 8 finalize | Yes — run status | `unlock_payroll` (audited) |
| Delete PR0002 entirely | — | `delete_payroll_run` (supported lifecycle) |
| **Changing `vacant_treatment`** | **Yes — tenant configuration** | Reversible, but it is a **policy change**, not test data |

### E. Least destructive, most production-representative

**Recommendation: do NOT change `vacant_treatment`.**

It is currently **unset**, defaulting to `block` — the payroll-safe behaviour. Changing it to
`present` would make six employees payable who were deliberately skipped for having unmarked
attendance, which is exactly the "convert skipped employees into payable employees merely for
testing" that §6 prohibits. It would also make the UAT unrepresentative of production.

**Proceed instead in this order, with zero configuration change:**

1. **Test 3 on the April run** (read-only) — 7 real rows, proves the grid and column alignment
2. **Test 4 on the April run** (read-only) — expect **amber** "no trace recorded"
3. **Test 4 on the May slip** — expect **green** reconciliation banner
4. **Tests 5, 6, 8 on the May Draft run** — override → regenerate → finalize → unlock
5. **Test 7** — record **NOT TESTABLE** (no approved unpaid leave in any period)

That exercises every W3 capability except LOP, uses only supported workflows, and changes no policy.

---

## 6–12. Required answers

| # | Item | Answer |
|---|---|---|
| 1 | Test 2 re-run | **NOT PERFORMED — requires your browser.** Items 7–8 verified by me |
| 2 | HTTP/JSON result | **Yours to produce.** Expect clean JSON, no HTML prefix |
| 3 | Console result | **Yours to produce** |
| 4 | Regression | **461 / 1,493 / 10** — baseline, no new failures |
| 5 | 38 s breakdown | **Partial** — shown to be fixed cost, not write cost. Not yet instrumented. `N-22` PARTIAL |
| 6 | 1-slip / net 0 | **Arithmetically correct**; two derivation concerns registered as `N-23` |
| 7 | May viable? | **YES for Tests 4, 5, 6, 8** |
| 8 | June/July viable? | **No better than May.** April best for Test 3, read-only |
| 9 | Blocker? | **Only Test 7 (LOP)** — no approved unpaid leave exists |
| 10 | Recommendation | **No configuration change.** Use April read-only + the existing May Draft run |
| 11 | `N-21` / `N-22` | `N-21` **PARTIAL / W5** (3 fixed, 136 untouched) · `N-22` **PARTIAL** (profiling still required) |
| 12 | Nothing done without approval | **Confirmed** — nothing committed, pushed, deployed, migrated, seeded, or configuration-changed |

---

## New capability registered

| ID | Capability | Status | Production-critical? | Wave |
|---|---|---|---|---|
| **N-23** | **Absence counting vs working days and paid leave** | **PARTIAL** | **Possibly** — a paid-leave entitlement can be consumed by the absence clamp | **W4** |

**Evidence:** PR0002/STA0001 — 28 `A` marks counted against 24 working days then clamped; 3
`paid_leave_days` recorded but retention resolved to 0.
**Acceptance:** absences counted only against working days; paid-leave days excluded from chargeable
absence before clamping; a test covering "heavily absent month containing paid leave".
**Dependency:** attendance semantics (`P-021` weekly-off double definition) — must not be changed
mid-UAT.

**Canonical count 164 → 165.** COMPLETE 50 · PARTIAL **42** · MISSING 62 · ENH 1 · REF 1 ·
BLOCKED 8 · OP-DEC 1 → `50+42+62+1+1+8+1 = 165` ✓

---

**STOPPED. No Test 3, no W3.2.6, no P-074. Awaiting your review.**
