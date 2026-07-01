# Examination Module — Backlog

Items belonging to the Examination/Result module. Tracked separately from Fees V7.

---

## EXAM-FEE-ELIGIBILITY-1 — exam-fee gating reads legacy `feeItems` (Medium)

**Discovered:** during the Fees V7 runtime dependency audit (2026-06-29).
**Module:** Examination/Result (integration with the Fees defaulter projection).
**Severity:** Medium — **fail-open**, no money/data impact; affects only exam-fee gating.
**Pre-existing:** introduced by the canonical per-head demand schema (Phase 2.5), **not** by
Fees V7. Fees V7 neither caused nor worsened it.

### Dependency chain (where it bites)
- `Examination.php:860` → `Fee_defaulter_check::checkExamEligibility($uid, $examName)`
  → `_isExamFeePaid($unpaid_months, $examName)`.
- `Fee_defaulter_check::updateDefaulterStatus:246` → `_hasUnpaidExamFee($unpaid_months)`
  → sets the `exam_blocked` flag on the `feeDefaulters` doc.

### Root cause
`Fee_defaulter_check::isDefaulter` (line ~164) builds each `unpaid_months[]` entry's
`fee_heads` from the **legacy** `$d['feeItems']` map and does **not** capture the canonical
`$d['feeHead']`. Canonical per-head demands have no `feeItems` map (each demand is one head),
so `fee_heads` is empty and the head name is lost. `_hasUnpaidExamFee` / `_isExamFeePaid` /
`_matchesExam` then cannot detect an unpaid **Exam Fee** → they **fail open**
(`exam_blocked=false`, student marked eligible) even when the exam fee is unpaid.

### Required canonical migration
1. In `isDefaulter`, capture the canonical head into each `unpaid_months` entry
   (e.g. `'feeHead' => (string)($d['feeHead'] ?? '')`); retain `fee_heads` for back-compat.
2. Update `_hasUnpaidExamFee`, `_isExamFeePaid`, and `_matchesExam` to match on the canonical
   `feeHead` field (not only the month/period label or the legacy `fee_heads` map).
3. Verify: a student with an unpaid canonical "Exam Fee" demand is correctly flagged
   `exam_blocked` and returned ineligible by `checkExamEligibility`.

### Scope / boundary (what is NOT affected)
- General defaulter detection (`is_defaulter` / `total_dues` from `balance`) — correct.
- `Result.php` result-withholding (`:311/:448/:1677`, balance-based) — correct.
- Defaulter report, dashboards, fee collection, accounting, receipts, demand generation,
  promotion, Parent App, Teacher App — all unaffected.

### Runtime-impact gating
No code on/off flag exists; the only external consumer is the Examination result/eligibility
flow (`Examination.php:860`). A school that does **not** gate exams/results by fees experiences
**zero** runtime impact. The failure mode is fail-open (no crash, no corruption).

### Recommendation
Address as part of **Examination module integration work**, only if exam-fee eligibility
gating is a required feature. Not a Fees V7 Release Candidate concern.
