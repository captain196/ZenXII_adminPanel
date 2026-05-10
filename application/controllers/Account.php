<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Account — DEPRECATED legacy controller (retired 2026-05-09).
 *
 * Phase 1 Tier-0 hardening: this controller previously implemented an
 * RTDB-era account book and voucher subsystem (Schools/{s}/{y}/Accounts/
 * Account_book / Vouchers). The 21–26 April rebuild moved the accounting
 * stack to a Firestore-only engine in `application/controllers/Accounting.php`
 * with seven tabs (Chart of Accounts / Journal Entries / Income & Expense /
 * Cash Book / Bank Reconciliation / Reports / Settings).
 *
 * This file remains in place to preserve URL space — admin laptop bookmarks
 * to `<base>/account/account_book`, `<base>/account/cash_book`, etc. would
 * 404 if the file were deleted. Every method has been gutted to:
 *   • emit a deprecation log entry (so we can see if anyone still hits it)
 *   • either redirect to the equivalent new-module page (HTML pageviews)
 *     OR return JSON 410 Gone (AJAX endpoints)
 *
 * No method in this file performs any RTDB read or write, satisfying the
 * absolute "NO RTDB EVER" policy.
 *
 * The original implementation is preserved in git history. To inspect:
 *   git log -- application/controllers/Account.php
 *
 * Removal plan (deferred to Phase 2 hardening, after observability proves
 * traffic is zero):
 *   1. Add log-line scraping for ACC_LEGACY_HIT in error logs.
 *   2. After 30 days of zero hits, delete this file.
 *   3. Delete the orphan views (account_book.php, manage_voucher.php,
 *      view_voucher.php, view_accounts.php, day_book.php, cash_book.php).
 *
 * URL → new-module redirect map (HTML pageviews only):
 *   account/account_book    → accounting/chart
 *   account/vouchers        → accounting/ledger
 *   account/view_voucher    → accounting/ledger
 *   account/day_book        → accounting/reports?type=day_book
 *   account/view_accounts   → accounting/reports
 *   account/cash_book       → accounting/cash-book
 */
class Account extends MY_Controller
{
    public function __construct()
    {
        parent::__construct();
        require_permission('Accounting');
    }

    // ── Deprecation helpers ──────────────────────────────────────────────────

    /**
     * For AJAX endpoints: emit a structured JSON 410 with a clear message
     * pointing the caller at the replacement module. The caller's UI will
     * see a JSON error and surface it to the operator.
     */
    private function _ajax_gone(string $endpoint): void
    {
        log_message('error', "ACC_LEGACY_HIT endpoint=account/{$endpoint} ip="
            . ($this->input->ip_address() ?: '-') . " ua="
            . ($this->input->user_agent() ?: '-'));

        // 410 Gone — preferred over 404 because it tells clients the
        // resource is intentionally retired, not missing.
        $this->output->set_status_header(410, 'Gone');
        $this->output->set_content_type('application/json');
        $this->output->set_output(json_encode([
            'status'  => 'error',
            'code'    => 'ENDPOINT_RETIRED',
            'message' => 'This endpoint was retired on 2026-05-09 along with the legacy RTDB account book. Use the new Accounting module under <base>/accounting/.',
            'replacement' => 'accounting',
        ]));
    }

    /**
     * For HTML pageviews: log the deprecation hit and redirect to the
     * equivalent page in the new accounting module. Bookmark-friendly —
     * a stale bookmark lands on the right page in the new UI without
     * the operator needing to know the URL changed.
     */
    private function _redirect_to(string $accountingPath, string $endpoint): void
    {
        log_message('error', "ACC_LEGACY_HIT endpoint=account/{$endpoint} ip="
            . ($this->input->ip_address() ?: '-') . " ua="
            . ($this->input->user_agent() ?: '-')
            . " redirected_to=accounting/{$accountingPath}");
        redirect(base_url("accounting/{$accountingPath}"));
    }

    // ── HTML pageview methods (redirect to new module) ───────────────────────

    public function account_book()
    {
        // AJAX subpath of the legacy account_book served account-detail
        // lookups; surface the deprecation as a JSON 410. Plain pageview
        // redirects to the new Chart of Accounts.
        if ($this->input->is_ajax_request()) {
            $this->_ajax_gone('account_book');
            return;
        }
        $this->_redirect_to('chart', 'account_book');
    }

    public function vouchers()       { $this->_redirect_to('ledger',     'vouchers'); }
    public function view_voucher()   { $this->_redirect_to('ledger',     'view_voucher'); }
    public function day_book()       { $this->_redirect_to('reports',    'day_book'); }
    public function cash_book()      { $this->_redirect_to('cash-book',  'cash_book'); }

    public function view_accounts()
    {
        if ($this->input->is_ajax_request()) {
            $this->_ajax_gone('view_accounts');
            return;
        }
        $this->_redirect_to('reports', 'view_accounts');
    }

    // ── AJAX methods (JSON 410 Gone) ─────────────────────────────────────────

    public function populateTable()      { $this->_ajax_gone('populateTable'); }
    public function create_account()     { $this->_ajax_gone('create_account'); }
    public function check_account()      { $this->_ajax_gone('check_account'); }
    public function update_account()     { $this->_ajax_gone('update_account'); }
    public function delete_account()     { $this->_ajax_gone('delete_account'); }
    public function save_voucher()       { $this->_ajax_gone('save_voucher'); }
    public function show_vouchers()      { $this->_ajax_gone('show_vouchers'); }
    public function get_server_date()    { $this->_ajax_gone('get_server_date'); }
    public function cash_book_month()    { $this->_ajax_gone('cash_book_month'); }
    public function cash_book_dates()    { $this->_ajax_gone('cash_book_dates'); }
    public function cash_book_details()  { $this->_ajax_gone('cash_book_details'); }
}
