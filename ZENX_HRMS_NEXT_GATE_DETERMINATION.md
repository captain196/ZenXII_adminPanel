# ZENX HRMS — POST-W3.2.5 RECONCILIATION + NEXT-GATE DETERMINATION

**Date:** 2026-08-09 · **Type:** reconciliation + planning only — no feature implementation
**Canonical count:** **164** (unchanged) · **Regression:** 461 tests / 10 failures (baseline) · all verifiers PASS

---

## 1. Register reconciliation — the seven confirmations

| # | Required | Register says | Verified |
|---|---|---|---|
| 1 | `N-15` COMPLETE | **COMPLETE** — shared predicate, bank=blocker/PAN=warning, PII mirror, 13 tests | ✓ |
| 2 | `N-17` COMPLETE | **COMPLETE** — one doc builder, `batchWrite` @450, 385× fewer trips, failures named | ✓ |
| 3 | `N-18` MISSING | **MISSING** — full acceptance criteria retained, `FeeWorker` named as reuse pattern | ✓ |
| 4 | `N-19` MISSING | **MISSING** — see §2 | ✓ |
| 5 | `T-14` COMPLETE | **CORRECTED** — see §1.1 | ✓ *(after fix)* |
| 6 | `T-15` PARTIAL | **CORRECTED** — see §1.1 | ✓ *(after fix)* |
| 7 | `P-047` MISSING | **MISSING** — async generation not built | ✓ |

### 1.1 A register defect found and corrected — reported per instruction #7

**`T-14` and `T-15` still read MISSING in the register body** (§6 testing table) while the W3.2.5
delta and the family count table had already credited them as COMPLETE / PARTIAL.

**Cause:** in W3.2.5 I updated the *count* tables and the *delta* narrative but never edited the two
capability rows themselves. The register therefore contradicted its own totals — exactly the
inconsistency class AA.1 was run to eliminate.

**Corrected:** `T-14` → **COMPLETE** (with its evidence), `T-15` → **PARTIAL** (scale measured, async
paths untestable while unbuilt). **The canonical count and status totals were already correct at
164 / 50-41-62-1-1-8-1-0; no number was changed to accommodate the fix.**

### 1.2 Nothing disappeared · every incomplete item has an owner wave

164 capabilities present; 161 pre-W3.2.5 retained, 3 added. Spot-checked across families: `RB-01`
(reimbursement engine) MISSING/W4 · `ST-14` (statutory snapshot) MISSING/W3.2.6 · `ST-01…10` BLOCKED
on operator policy · `R-01…R-09` MISSING/W5 · `P-074` (HR documents) MISSING/W5 · `T-07`/`T-11`
(browser + Test-School UAT) MISSING/UAT. **Zero incomplete capabilities without an owner wave.**

---

## 2. The latency issue — addressed, not closed

`Hrms_w325_latency` is **built and dry-run verified**. It measures real per-round-trip cost by
writing **20 throwaway documents** to `_benchmarkScratch` — a collection nothing reads — measuring
sequential `set()` against one `batchWrite()`, then **deleting them in the same run**. It never
touches `salarySlips`, `staff`, `payrollOverrides`, or anything payroll reads, and it fabricates no
employees, structures or payslips.

It then projects the measured per-trip cost onto the **exact** round-trip counts already proven
(5,001 → 13 at 5,000 employees), which converts the W3.2.5 wall-clock figures from *assumption* into
*measurement*.

**`N-19` remains MISSING, deliberately.** A real measurement writes to production Firestore, and
rule 18 says not to without explicit authorization. Rather than take that decision myself, the
harness is one command from closing the capability:

```
php index.php hrms_w325_latency run --commit
```

**Residual risk if authorized:** a crash mid-run could leave up to 40 scratch documents sharing an
identifiable prefix in a collection no code queries. Cleanup runs in the same invocation.

---

## 3. Next-gate determination — **W3.2.6 is NOT the correct next gate**

Checked against the dependency graph rather than gate numbering.

### Why W3.2.6 (statutory snapshot) cannot go next

`ST-14` — the statutory snapshot — is the whole of W3.2.6, and it **cannot be completed by
engineering effort**. Its inputs are `ST-01…ST-12`, of which **eight are BLOCKED on a decision only
you can make**: PF rates, PF ceiling, above-ceiling eligibility, ESI rates, ESI threshold, PT state,
PT slabs, TDS mode, and every effective date.

Building a snapshot mechanism with no policy to snapshot would produce a feature that freezes an
empty object and a test suite that proves nothing. Worse, the temptation at that point is to seed a
"reasonable default" — which is precisely the inference the programme forbids.

**W3.2.6 is blocked on an operator decision, not on engineering capacity.**

### Two candidates that are genuinely ready

Both sit on independent streams with **zero blocked dependencies**:

| Candidate | Capabilities closed | Why it is ready | Why it matters |
|---|---|---|---|
| **A. UAT gate** (`T-07`, `T-11`, `T-12`, plus verification of `P-043`, `P-045`, `N-01`, `P-063`) | 4 MISSING → COMPLETE; **4 PARTIAL → COMPLETE** | Needs no new engineering — the code exists and is unverified | **Eight capabilities are PARTIAL *solely* for want of browser evidence.** Nothing else in the register converts as many items per unit of work, and every one of them is currently a claim rather than a fact |
| **B. HR document adoption** (`P-074` → `P-046`, `P-075`) | 1 MISSING → COMPLETE, unblocks 2 | `Document_service` is proven with 3 live callers; HR simply isn't one | Unblocks professional payslips and HR letters — a whole commercial branch |

### Recommendation — **A. the UAT gate**

Four waves have now shipped implementations that are individually tested and **collectively
unverified in a browser**. `P-043` (editable grid), `P-045` (calculation trace), `N-01` (override
model) and `P-063` (ATS convert-to-staff) are all PARTIAL for exactly one reason: no one has run
them. `P-063` is the sharpest case — W1.3 found a tenant-prefix defect meaning **hire finalization
had never once succeeded in production**, and the fix is still unexercised.

Continuing to build on four unverified layers increases the cost of whatever is wrong down there.
The UAT gate is also the only gate that can close `N-19` in passing, since it is already a
Test-School session.

**It requires you**: a browser, a Test-School login, and authorization for the reversible writes a
real payroll run implies. I cannot perform it, and I will not report it as done.

**If you would rather I keep building while UAT waits**, candidate **B** is the correct engineering
gate — it is unblocked, reuses proven infrastructure, and opens the document branch.

---

## 4. Status counts (unchanged by this reconciliation)

**COMPLETE 50 · PARTIAL 41 · MISSING 62 · ENHANCEMENT 1 · REFACTOR 1 · BLOCKED 8 · OPERATOR 1 · N/A 0**

`50 + 41 + 62 + 1 + 1 + 8 + 1 + 0 = 164` ✓ · **Completeness 50/164 = 30.5%**

## 5. Awaiting your decision

1. **Authorize the latency measurement?** (`--commit`, self-cleaning, closes `N-19`)
2. **Next gate: A (UAT) or B (HR documents)?**
3. **Statutory policy** — still the single blocker on 8 capabilities and all of W3.2.6.

**Nothing implemented this turn beyond the latency harness and the register correction. Nothing
committed, pushed, deployed, migrated or seeded.**
