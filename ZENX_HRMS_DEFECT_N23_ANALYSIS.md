# ZENX HRMS — DEFECT ANALYSIS — N-23

## NON-WORKING DAYS COUNTED AS ABSENCE → PAID-LEAVE UNDERPAYMENT

**Date:** 2026-08-09 · **Severity:** **PRODUCTION-CRITICAL — underpays employees**
**Status:** **ANALYSIS ONLY — NO CODE CHANGED** (per defect-gate §9)
**Evidence:** PRESERVED. Run `PR0003` untouched. No UAT mutation performed.

---

## 1. Root cause — a per-day working-day map is computed, then discarded

`Hr::_staff_working_days()` walks every day of the month and *already knows* which days are working:

```php
if ($d < $startDay || $d > $endDay) continue;
$inWindow[$d] = true;                                    // ← set BEFORE the tests below

if ($dow === 0) continue;                                        // Sunday
if ($dow === 6 && ($satCount === 2 || $satCount === 4)) continue; // 2nd/4th Saturday
if (isset($holidays[$d])) continue;                              // holiday
$working++;                                              // ← only a COUNT survives
```

It returns `['working' => int, 'inWindow' => map, 'startDay', 'endDay']`.

**`inWindow` contains every day in the employment window — including Sundays, off-Saturdays and
holidays.** The per-day working/non-working distinction is computed and then thrown away; **no
per-day working map exists anywhere in the codebase** (verified: 0 occurrences).

Both attendance parse loops therefore gate on the wrong thing:

| Site | Line | Gate |
|---|---|---|
| `generate_payroll()` | **6486** | `if (!isset($staffInWindow[$dayNum])) continue;` |
| `regenerate_staff_payroll()` | **9683** | `if (!isset($swd['inWindow'][$dayNum])) continue;` |

An `A` mark on a Sunday passes that gate and is charged as an absence.

The clamp `if ($daysAbsent > $staffWorkingDays) $daysAbsent = $staffWorkingDays;` then **hides the
overflow**, so the inflated count silently saturates at 100% absence instead of erroring.

## 2. Mathematical proof — from the frozen `_attendance_snapshot`, not live data

**Employee STA0001 (Vipul TIwari), run PR0003, May 2026.** Frozen evidence:
`day_wise = "LLLAAAAAAAAAAAAAAAAAAAAAAAAAAAA"` · `window_mask` all-1 · `working_days: 24` ·
`days_in_month: 31` · `holiday_days: []`.

May 2026 non-working days (independently recomputed): **7** —
day 3 (Sun), 9 (2nd Sat), 10 (Sun), 17 (Sun), 23 (4th Sat), 24 (Sun), 31 (Sun).
`31 − 7 = 24` working days — matches the stored `working_days`, and matches the run's
`sundays: 5, off_saturdays: 2`.

| Quantity | Value |
|---|---|
| Total `A` marks | 28 |
| **`A` on WORKING days** | **22** ← the only chargeable absences |
| **`A` on NON-WORKING days** | **6** ← wrongly charged |
| `L` marks | 3 (of which **2** fall on working days) |

| | Absent | Retention | Gross (contracted ₹15,000) |
|---|---|---|---|
| **Current (defective)** | `min(28, 24) = 24` | **0** | **₹0.00** |
| **Correct** | **22** | **2/24 = 0.0833** | **₹1,250.00** |

**Underpayment: ₹1,250.00 for one employee in one period.**

**The corrected figures self-validate:** `22 absent + 2 paid-leave = 24 working days`, and retention
`2/24` equals exactly the paid-leave days falling on working days. The arithmetic only closes under
the corrected rule — which is independent confirmation, not a fitted answer.

## 3. Expected behaviour — derived independently

An employee on **fully-paid** leave must be paid for those days. The frozen `leave_details` says:

```
PATERNITY · paid: true · paid_days: 3 · lwp_days: 0 · pay_label: "Fully Paid" · 2026-05-01→03
```

Two of those three days are working days (1 May Fri, 2 May = **1st** Saturday, which is a working
day; 3 May is a Sunday). So the employee is owed **2 working days of pay = ₹1,250 gross**, and
received ₹0.

## 4. Blast radius — which marks are affected

Every branch inside the mis-gated loop:

| Mark | Effect | Affected? | Consequence when it lands on a non-working day |
|---|---|---|---|
| **`A`** absent | `daysAbsent += 1.0` | **YES** | **Underpayment** — the defect proven above |
| **`M`** half-day | `halfDays++`, `daysAbsent += 0.5` | **YES** | Underpayment (half rate) + inflated half-day count |
| **`L`** leave | `paidLeaveDays++` / `unpaidLeaveDays++` | **YES** | Inflated leave counts; **`unpaidLeaveDays` inflation risks over-deducting LOP** |
| **`V`** vacant | `vacantDays++`, and `+= 1.0` absent under `vacant_treatment: 'absent'` | **YES** | **Payroll wrongly BLOCKED** — inflated `vacant_days` triggers the `block` policy and refuses to generate a slip for an employee who is actually complete |
| **`W`** worked-on-off | `extraWorkedDays += 1.0` | **NO — correct as-is** | `W` *means* "worked on a scheduled off-day"; counting it on a non-working day is the entire point. **Any fix must preserve this.** |
| `P`, `H`, `T`, `O` | no pay effect | n/a | — |

**`V` deserves emphasis:** the same defect can *block* payroll, not only underpay it — the opposite
failure mode, from the same line.

## 5. Which periods and tenants are affected

**The defect is latent and only manifests when attendance carries `A`/`M`/`L`/`V` on a non-working
day.** It depends entirely on how attendance was marked.

| Period | Marking style | Affected? |
|---|---|---|
| **April 2026** (`PR0001`, 7 slips, Paid) | `len=30`, `P=21 A=3` for STA0003 → 24 marks on 24 working days; the other 6 days carry non-pay markers (`O`/`H`) | **NOT AFFECTED** — non-working days were never marked `A` |
| **May 2026** (`PR0003`) | `A` written across every day including Sundays | **AFFECTED — proven** |
| June / July 2026 | STA0001, `A=29` / `A=30` of 30/31 days | **Almost certainly affected** (same shape) — not yet generated |

So historical April payslips appear safe, but **only because that month's attendance happened to use
proper non-working markers**. Any bulk-marking, import, or device sweep that writes `A` across a full
calendar month reproduces the defect immediately. **This is a live production risk, not a test-data
artefact.**

## 6. Relationship to P-021 — the dependency that must be settled first

`P-021` (**NEEDS REFACTORING**, matrix row 21) records that ZenX has **two definitions of a
weekly-off**:

1. **Configurable** — `attendancePolicy.weeklyOffs` on the school document
2. **Hardcoded** — payroll's `_staff_working_days()` assumes *Sunday + 2nd/4th Saturday*

**The fix for N-23 must consume the canonical definition, not entrench the hardcoded one.** Correcting
absence counting against payroll's private assumption would make the wrong calendar authoritative and
make `P-021` harder to resolve later.

**Canonical source of truth (proposed, for your ratification):** `_staff_working_days()` is the single
place that decides working vs non-working, and it must (a) return a per-day working map, and (b) draw
its weekly-off rule from `attendancePolicy.weeklyOffs` with the current hardcoded rule as fallback.
**No second calendar is to be created.**

## 7. Proposed canonical fix — NOT IMPLEMENTED, awaiting approval

1. **`_staff_working_days()` returns one extra key: `isWorking[dayNum] => true`** — built from the
   loop that already makes this decision. Purely additive; existing callers unaffected.
2. **Both parse loops gate on it**, at both sites (6486, 9683):
   ```php
   if (!isset($staffInWindow[$dayNum])) continue;       // outside employment window
   if (!isset($isWorking[$dayNum]) && $ch !== 'W') continue;   // non-working day
   ```
   The `W` exemption is mandatory — see §4.
3. **Freeze the mask** into `_attendance_snapshot` as `working_mask`, so a slip records which calendar
   it was priced against (closing the same reproducibility gap for calendars that W3.2.1 closed for
   attendance).
4. **The clamp stays** as a safety net, but should no longer be reachable in normal operation. If it
   ever fires, that now indicates a genuine data fault and should warn.
5. **Do NOT** touch `P-021`'s configurable-vs-hardcoded question in this fix — record it as the
   follow-on, or the fix scope becomes unbounded.

## 8. Regression cases required before the fix can be accepted

| # | Case | Expected |
|---|---|---|
| 1 | `A` on Sunday / 2nd / 4th Saturday | **not** counted as absence |
| 2 | `A` on a holiday | not counted |
| 3 | `A` on a working day | counted (unchanged) |
| 4 | **`W` on a non-working day** | **still credited** — the exemption |
| 5 | `M` on a non-working day | not counted; half-day count not inflated |
| 6 | `L` on a non-working day | leave counts not inflated |
| 7 | `V` on a non-working day | `vacant_days` not inflated → `block` not falsely triggered |
| 8 | Fully-absent month **containing paid leave** | retention = paid-working-days / working-days; **the PR0003 case, expecting ₹1,250** |
| 9 | Full-attendance month | byte-identical to today (no regression) |
| 10 | Mid-month join / exit | window ∩ working-days both respected |
| 11 | Rehire (non-contiguous window) | gap days neither charged nor paid |
| 12 | `gross == net + deductions` | holds in every case above |
| 13 | Statutory bases | PF on earned basic, ESI on earned gross — recomputed against corrected retention |
| 14 | Accounting | `Dr == Cr` after recomputation |

## 9. Are historical payslips affected?

**Potentially, wherever attendance marked non-working days.** April's 7 paid slips appear safe (§5),
but this cannot be assumed for other tenants or periods.

**Do NOT reconstruct historical attendance from live data** to answer this. The correct method is to
read each slip's frozen `_attendance_snapshot` and recount against the calendar — which is only
possible for slips generated **after W3.2.1**. Pre-W3.2.1 slips (including all of April) have no
snapshot and **cannot be audited**; they can only be flagged as unauditable.

**Proposed:** a read-only detection script that reports affected slips without altering them. Whether
to re-issue any corrected payment is a business decision, not mine.

## 10. Register entries

| ID | Status | Note |
|---|---|---|
| **N-23** | **PARTIAL → production-critical** | This analysis; fix not implemented |
| **P-021** | NEEDS REFACTORING | **Now a blocking dependency of N-23** |
| **N-26** *(new)* | **MISSING** | **Historical payslip audit for N-23 exposure** — read-only detection over frozen snapshots; pre-W3.2.1 slips unauditable. Owner: W4 |

---

## What I am NOT doing

- **Not fixing it in this turn** — §9 requires the analysis first, and the `P-021` canonical-calendar
  decision is genuinely yours.
- **Not touching PR0003** — evidence preserved.
- **Not creating the Test 5 bonus.**
- **Not inferring the contract from one employee** — the calendar was recomputed independently and
  cross-checked against the run's own `sundays: 5` / `off_saturdays: 2`.

## What I need from you

1. **Ratify the canonical source of truth** — is `_staff_working_days()` the single authority, and
   should it read `attendancePolicy.weeklyOffs` (P-021) as part of this fix, or strictly after?
2. **Approve the proposed fix shape** (§7), or direct otherwise.
3. **Decide on the historical audit** (`N-26`) — detection only, or detection plus a correction path?
