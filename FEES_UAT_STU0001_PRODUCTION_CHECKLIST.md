# FEES PRODUCTION UAT — STU0001 (Hanuman Ji) End-to-End Certification

> **STATUS: PREPARED — DO NOT EXECUTE YET.**
> STU0001 must remain at **zero demands** until *every* Fees hardening issue is fixed.
> This is the FINAL certification run. No production writes until authorized.

- **Tenant:** `SCH_D94FE8F7AD`
- **Student:** `STU0001` — Hanuman Ji — **Class 8th / Section A**
- **Current state (verified read-only):** 0 non-archived `feeDemands`. Classmates (STU0004/5/6) each have 37. Class 8th-A **has** a fee structure.
- **Why this student:** he is a *real* production "no-bills" case in a *billed* class — the cleanest possible full-lifecycle test (see "Why STU0001").

---

## Pre-UAT baseline (capture BEFORE generating anything)
Record the current values so post-UAT deltas are provable:

- [ ] `feeDemands` count for STU0001 = **0** (expected)
- [ ] `feeReceipts` count for STU0001 = 0
- [ ] `feeDefaulters/{schoolId}_STU0001` = absent OR isDefaulter state noted
- [ ] `studentFeeSummary/{schoolId}_STU0001` = absent OR values noted
- [ ] Counter opens STU0001 → **"No fees generated" banner shows, no ₹0 tiles** (permanent fix — verify this FIRST)
- [ ] Bank ledger balances noted: `1010` Cash, `1020/1021/1022/1023` bank ledgers
- [ ] Class 8th-A fee structure snapshot (heads + amounts) captured

---

## 1. Generate Demands → demands created correctly
- [ ] Run Generate Demands for STU0001 (single-student) OR re-save Class 8th-A structure
- [ ] `feeDemands` created for STU0001: one per (month × head), matching classmates' 37
- [ ] Each demand has: `billingType`, `canonicalFeeHeadId` (non-empty), `period`, `period_key`, `status`, `net_amount`, `balance`
- [ ] **No duplicates** (dedup key studentId|periodKey|canonicalFeeHeadId honored)
- [ ] Amounts equal the Class 8th-A structure exactly
- [ ] `fee_generation_jobs` job record shows success, 0 failures

## 2. Fee Counter → months visible, amounts correct
- [ ] Re-open STU0001 → **banner is GONE**, month tiles now render
- [ ] All 12 months + "Yearly Fees" tile show with correct dues
- [ ] `totalGross` > 0; per-month `totalDue` matches structure
- [ ] Select months → totals + discount + payable compute correctly
- [ ] Collect a payment (Cash) → receipt prints, months flip to Paid/Partial
- [ ] No "Overpayment not allowed" on a legitimate amount

## 3. Parent App → pending → payment → receipt
- [ ] Parent app shows STU0001 pending dues (reads same `feeDemands`)
- [ ] "Yearly Fees" / annual head renders correctly (billingType-aware)
- [ ] Online payment → amount server-recomputed (PAY-1 must be closed first)
- [ ] Razorpay success → `feeReceipts` + `feeReceiptAllocations` written
- [ ] Receipt visible in parent history; demand balances reduce correctly
- [ ] No demand hidden due to missing `status` field (status always set)

## 4. Teacher App → outstanding dues → collection status
- [ ] Teacher (Class 8th-A) dues dashboard lists STU0001 with correct outstanding
- [ ] After the Step 2/3 payments, "collected" reflects the new receipts
- [ ] Cancelled/archived receipts do NOT inflate the collected figure
- [ ] Read authorization scoped to assigned class (Firestore rules verified)

## 5. Accounting → correct journal, bank ledger, balanced entry
- [ ] Each collection posts one `accounting` voucher (double-entry)
- [ ] Debit = selected bank ledger (`1020/1021/1022/1023`) or `1010` Cash; Credit = fee income
- [ ] Dr total == Cr total (balanced; unbalanced rejected)
- [ ] `bankLedgerCode` / `bankLedgerName` stored on the receipt (CORE-1)
- [ ] Bank ledger balance increases by exactly the collected amount

## 6. Reports
- [ ] **Fee Collection report** — STU0001's payments appear, totals correct
- [ ] **Defaulter Report** — STU0001 present when unpaid, removed when cleared
- [ ] **Student Summary** — `studentFeeSummary` matches actual demand balances
- [ ] **Fees Dashboard** — Total Collected / Due / Today move by the right deltas
- [ ] **Bank-wise Collection** — the collected amount appears under the chosen bank

## 7. Promotion → student remains correct
- [ ] Promote STU0001 (or dry-run) → old demands `archived`, new-class demands generated
- [ ] Unpaid balances carry forward correctly; no duplicate/lost demands
- [ ] Defaulter projection refreshed post-promotion (updateDefaulterStatus)

## 8. Refund → still works
- [ ] Issue a refund against a STU0001 receipt
- [ ] Refund verifies `receipt.studentId === STU0001` (guard)
- [ ] Accounting reversal posts, balanced; receipt/refund records consistent

## 9. Reconciliation → AccountingReconciler → same ledger
- [ ] Run AccountingReconciler for STU0001's receipts
- [ ] Repaired/replayed vouchers use the **same** bank ledger code as the receipt
- [ ] No double-posting; balances unchanged after a clean reconcile

## 10. Aggregate verification
- [ ] `feeDefaulters/{schoolId}_STU0001` correct after each state change
- [ ] `studentFeeSummary/{schoolId}_STU0001` matches live demand math
- [ ] Dashboard aggregates consistent with per-student truth (no DR-1 drift)
- [ ] Re-run generation → no false "not a defaulter" overwrite (bulk-gen bug closed)

---

## Firestore collections that WILL change during UAT
(reads are excluded; only writes listed)

| Step | Collection(s) written |
|------|----------------------|
| 1 Generate | `feeDemands`, `fee_generation_jobs`, `feeDefaulters`, `studentFeeSummary` |
| 2 Counter pay | `feeReceipts`, `feeReceiptAllocations`, `feeDemands` (balances), `feeIdempotency`, `feeLocks`, `accounting`, `feeDefaulters`, `studentFeeSummary` |
| 3 Parent pay | same as Step 2 (via parent flow) |
| 5 Accounting | `accounting` (journal vouchers); `chartOfAccounts` balances |
| 7 Promotion | `feeDemands` (archive + regenerate), `students`, `feeDefaulters`, `studentFeeSummary` |
| 8 Refund | `feeReceipts`/refund records, `accounting` (reversal), `feeDefaulters`, `studentFeeSummary` |
| 9 Reconcile | `accounting` (repair only if drift) |
| audit (all) | audit/security logs (log_audit) |

**Untouched by design:** Teacher/Parent apps are read-only for fees (no writes from those surfaces).

---

## Exit criteria
- [ ] All 10 sections pass on STU0001
- [ ] Web ↔ Parent ↔ Teacher show identical numbers at every step
- [ ] Accounting balanced and bank-ledger-correct end to end
- [ ] Aggregates (`feeDefaulters` / `studentFeeSummary` / dashboards) match per-student truth
- [ ] → **CERTIFY Fees V7 for production**
