# Payroll Dependency — Minimal Extraction Patch Plan (decision-support only)

**Prepared:** 2026-07-22 · **Status:** PLANNING ONLY — no code applied, no branch, no cherry-pick, no commit. This documents *exactly* what "extract only the Transport dependency" (release Option B) would require, so the intact-vs-extract decision can be made with complete information. **The decision itself is reserved for the operator.**

## Context

Commit `1b9ed32` (Payroll Engine cutover) is interleaved between TMS Phase 0 (`53768b6`) and F1 (`cd4d5b2`). Transport F5 depends on **exactly one** behavior it introduced. Everything else in `1b9ed32` is unrelated Payroll/HR.

- **Option A (ship intact):** branch the release at `6f91923`; `1b9ed32` is an ancestor and rides along. Zero extraction work, but the Transport release then also carries the full, separately-unreleased Payroll cutover (`Hr.php` 985 LoC, `Payroll.php`, `PayrollReconciler.php`, `views/payroll/index.php`, `scripts/cleanup_pr0001.js`, `AccountingSimulator.php`, `config.php` flag removal).
- **Option B (extract only):** cherry-pick the Transport stack onto the origin base **excluding** `1b9ed32`, then re-apply the one required hunk below. This plan specifies Option B.

## The single required change

F5's `postExpense()` (added by F5 itself, `f25aae9`) opens with `if (!$this->_assertReady('postExpense')) return null;`. In the **pre-`1b9ed32`** file, `_assertReady()` additionally gates on the feature flag, which defaults OFF:

**Pre-`1b9ed32` `_assertReady()` (what the extracted tree would contain):**
```php
private function _assertReady(string $method): bool
{
    if (!$this->ready) {
        $this->_log("PAYROLL_NOT_READY method={$method}");
        return false;
    }
    if (!$this->isEnabled()) {                                              // ← gate to remove
        $this->_log("PAYROLL_FLAG_DISABLED method={$method} flag=" . self::FLAG);
        return false;
    }
    return true;
}
```
with `isEnabled()` = `return $this->ready && (bool) $this->CI->config->item('payroll_engine_integration');` and `payroll_engine_integration` **default OFF** (`config.php:116`, needs env `PAYROLL_ENGINE_INTEGRATION=1`).

**Effect if unpatched:** every Transport expense post → `_assertReady` false → `postExpense` returns `null` → expense stuck `pending` → reconciler burns 5 retries → `failed`. F5's accounting seam is silently dead.

**THE PATCH — delete these 4 lines only** from `_assertReady()` in `application/services/PayrollAccountingService.php`:
```php
    if (!$this->isEnabled()) {
        $this->_log("PAYROLL_FLAG_DISABLED method={$method} flag=" . self::FLAG);
        return false;
    }
```
This makes the extracted `_assertReady()` **byte-identical to the HEAD version** (the exact form F5 was authored against). Nothing else changes.

## What to deliberately LEAVE untouched (so Payroll stays held back)

| Keep as-is | Why |
|---|---|
| `isEnabled()` still reads the flag | Payroll's own `PayrollReconciler` / `AccountingSimulator` keep flag-awareness; only `_assertReady` stops consulting it, and only `postExpense`/Transport call `_assertReady`. |
| `private const FLAG = 'payroll_engine_integration'` | Still referenced by `isEnabled()`. |
| `config.php` flag definition (OFF) | **Do NOT** take `1b9ed32`'s flag-removal hunk. Leaving the flag defined+OFF keeps pre-`1b9ed32` `Hr.php` on its **legacy** payroll path — exactly the "Payroll held back" intent. |
| `Hr.php`, `Payroll.php`, `PayrollReconciler.php`, `views/payroll/index.php`, `cleanup_pr0001.js`, `AccountingSimulator.php` | All pure Payroll/HR; zero Transport linkage. |
| `1b9ed32`'s additive `accrualJournalIdFor()` / `postRunAdjustment()` | Payroll-only; Transport never calls them. Harmless to include or exclude; exclude for a truly minimal extraction. |

## Mechanics & risk surface of Option B (beyond the one hunk)

Extraction is a cherry-pick of the 12 Transport commits (`53768b6`, `cd4d5b2`…`6f91923`) onto the origin base, so these are the real work items and conflict risks to weigh:

1. **F5's diff (`f25aae9`) was authored against the post-`1b9ed32` file.** Cherry-picking it onto the pre-`1b9ed32` base applies F5's additions (`postExpense`, expense→ledger map, counter-account map) onto the pre-`1b9ed32` `PayrollAccountingService.php`. F5's additions live in a different region than the `isEnabled`/`_assertReady`/`FLAG` edits, so a clean apply is *likely* — **but must be confirmed by a dry-run**, not assumed. If it conflicts, resolve by keeping F5's additions and then applying THE PATCH above.
2. **Shared-infra files edited by multiple commits** — `application/config/routes.php`, `config.php`, `config/numbering.php` are touched by Transport commits *and* by the excluded `1b9ed32` / `dfe3487` WIP / admission. Cherry-picking Transport-only may conflict on these; each needs a manual resolution that takes only the Transport hunks.
3. **Post-extraction verification** — after the cherry-pick + THE PATCH: `php -l` the service; run `transport_probe_f5` (expects `passed=5 failed=0`) to prove `postExpense` actually posts (not `pending`/`failed`); grep-confirm `config.php` still defines the flag OFF and `Hr.php` still has its legacy path.

## Summary for the decision

- **Extraction is feasible and the Payroll-specific requirement is trivially small** (delete 4 lines; result identical to HEAD's `_assertReady`).
- **The cost of Option B is not the hunk — it's the cherry-pick** of the whole Transport stack onto the origin base and resolving shared-infra conflicts, versus Option A's zero-extraction "branch at `6f91923`."
- **Open question still with the operator:** is the `1b9ed32` Payroll cutover itself cleared to ship? If YES → Option A is clean. If NO → Option B (this plan) is required.

No decision made here. No repository mutation performed.
