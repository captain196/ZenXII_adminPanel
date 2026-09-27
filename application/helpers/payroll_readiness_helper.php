<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Payroll payout-readiness predicates (ZenX HRMS W3.2.5 / N-15).
 *
 * WHY THESE MOVED OUT OF `Salary_assignment_service`
 * --------------------------------------------------
 * `preflight_payroll()` needs to know whether an employee can actually be PAID.
 * That question is about the staff document alone — it has nothing to do with
 * the W2 component model, salary assignments or the compensation cutover.
 *
 * Leaving the rule inside `Salary_assignment_service` forced `Hr.php` to name
 * that class, which trips the W2 source guards that keep the compensation
 * cutover un-wired (`hrms_w2_verify` C, `CompensationReaderTest`,
 * `SalaryAssignmentTest`). Those guards are correct and worth keeping, so the
 * predicate moved to where it always belonged rather than the guard being
 * relaxed to accommodate it.
 *
 * ONE IMPLEMENTATION: `Salary_assignment_service::hasBankDetails()` now
 * delegates here, so the compensation readiness surface and payroll preflight
 * can never disagree about whether an employee has bank details.
 */

if (!function_exists('payroll_has_bank_details')) {
    /**
     * Does this staff record carry a usable bank account?
     *
     * Four field shapes are accepted because all four exist in live data —
     * nested under `bankDetails` in two spellings, and flat on the document in
     * two more. A stricter reader would report healthy employees as unpayable,
     * which is why the shapes are matched rather than normalised.
     *
     * Whitespace-only values count as absent: a record containing `"   "` is
     * not a bank account.
     *
     * @param array $s Staff document, ideally merged with the server-only PII
     *                 mirror — bank details live there, not on the main doc.
     */
    function payroll_has_bank_details(array $s): bool
    {
        $bd = $s['bankDetails'] ?? null;
        if (is_array($bd)) {
            foreach (['accountNumber', 'account_number', 'accountNo', 'acNumber'] as $k) {
                if (trim((string) ($bd[$k] ?? '')) !== '') return true;
            }
        }
        foreach (['bankAccountNumber', 'bank_account_number', 'accountNumber', 'account_number'] as $k) {
            if (trim((string) ($s[$k] ?? '')) !== '') return true;
        }
        return false;
    }
}

if (!function_exists('payroll_pan_on_record')) {
    /**
     * Is a PAN recorded for this employee?
     *
     * A WARNING-level check, not a blocker, until TDS is actually live for the
     * tenant: refusing to pay someone over a document that nothing currently
     * consumes would stop payroll for the wrong reason. It becomes a blocker
     * when TDS deduction is enabled.
     */
    function payroll_pan_on_record(array $s): bool
    {
        foreach (['pan', 'PAN', 'panNumber', 'pan_number'] as $k) {
            if (trim((string) ($s[$k] ?? '')) !== '') return true;
        }
        return false;
    }
}
