<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Transport Ops — F1 Fleet Management sub-controller (2026-07-07).
 *
 * Maintenance + Fuel + Expiry-dashboard endpoints. Delegates all business
 * logic to Transport_service (DOMAIN_FLEET methods introduced in F1 LC-14).
 *
 * Endpoints:
 *   GET  /transport_ops/get_maintenance_logs?vehicle_id=VH0007
 *   POST /transport_ops/save_maintenance_log
 *   GET  /transport_ops/get_fuel_logs?vehicle_id=VH0007
 *   POST /transport_ops/save_fuel_log
 *   GET  /transport_ops/get_expiry_dashboard              — reads cache
 *   POST /transport_ops/recompute_expiry_dashboard        — admin trigger
 */
class Transport_ops extends MY_Controller
{
    private const MANAGE_ROLES = [
        'Super Admin', 'School Super Admin', 'Admin', 'Principal', 'Vice Principal',
        'Operations Manager', 'Transport Manager',
    ];
    private const VIEW_ROLES = [
        'Super Admin', 'School Super Admin', 'Admin', 'Principal', 'Vice Principal',
        'Operations Manager', 'Transport Manager', 'Accountant', 'Teacher',
    ];

    public function __construct()
    {
        parent::__construct();
        require_permission('Operations');
        $this->load->library('numbering_service', null, 'numbering');
        $this->numbering->init($this->fs, $this->school_name, $this->session_year);
        $this->load->library('transport_service', null, 'transport');
        $this->transport->init(
            $this->fs, $this->numbering,
            $this->school_name, $this->session_year,
            $this->admin_id ?? 'system'
        );
    }

    private function _require_manage(): void
    {
        if (!in_array($this->admin_role, self::MANAGE_ROLES, true)) {
            $this->json_error('Access denied.', 403);
        }
    }
    private function _require_view(): void
    {
        if (!in_array($this->admin_role, self::VIEW_ROLES, true)) {
            $this->json_error('Access denied.', 403);
        }
    }

    // ═══════════════════════════════════════════════════════════════════
    //  Maintenance
    // ═══════════════════════════════════════════════════════════════════

    public function get_maintenance_logs()
    {
        $this->_require_role(self::VIEW_ROLES, 'transport_view');
        $this->_require_view();
        $vid = $this->safe_path_segment(trim($this->input->get('vehicle_id') ?? ''), 'vehicle_id');
        $this->json_success(['logs' => $this->transport->listMaintenanceLogs($vid)]);
    }

    public function save_maintenance_log()
    {
        $this->_require_role(self::MANAGE_ROLES, 'save_maintenance_log');
        $this->_require_manage();
        $parts = $this->input->post('parts_replaced');
        $parts = is_array($parts) ? $parts : (is_string($parts) && $parts !== '' ? explode(',', $parts) : []);
        try {
            $result = $this->transport->saveMaintenanceLog([
                'vehicle_id'                  => $this->safe_path_segment(trim($this->input->post('vehicle_id') ?? ''), 'vehicle_id'),
                'service_type'                => trim($this->input->post('service_type') ?? 'Routine'),
                'service_date'                => trim($this->input->post('service_date') ?? date('Y-m-d')),
                'service_km'                  => (int) ($this->input->post('service_km') ?? 0),
                'workshop'                    => trim($this->input->post('workshop') ?? ''),
                'cost'                        => (float) ($this->input->post('cost') ?? 0),
                'parts_replaced'              => array_map('trim', $parts),
                'next_service_date_projected' => trim($this->input->post('next_service_date_projected') ?? ''),
                'notes'                       => trim($this->input->post('notes') ?? ''),
                'receipt_path'                => trim($this->input->post('receipt_path') ?? ''),
            ]);
        } catch (\InvalidArgumentException $e) {
            $this->json_error($e->getMessage());
        } catch (\Throwable $e) {
            log_message('error', 'Transport_ops::save_maintenance_log failed: ' . $e->getMessage());
            $this->json_error('Failed to save maintenance log.');
        }
        $this->json_success(['id' => $result['id'], 'message' => 'Maintenance recorded.']);
    }

    // ═══════════════════════════════════════════════════════════════════
    //  Fuel
    // ═══════════════════════════════════════════════════════════════════

    public function get_fuel_logs()
    {
        $this->_require_role(self::VIEW_ROLES, 'transport_view');
        $this->_require_view();
        $vid = $this->safe_path_segment(trim($this->input->get('vehicle_id') ?? ''), 'vehicle_id');
        $this->json_success(['logs' => $this->transport->listFuelLogs($vid)]);
    }

    public function save_fuel_log()
    {
        $this->_require_role(self::MANAGE_ROLES, 'save_fuel_log');
        $this->_require_manage();
        try {
            $result = $this->transport->saveFuelLog([
                'vehicle_id'      => $this->safe_path_segment(trim($this->input->post('vehicle_id') ?? ''), 'vehicle_id'),
                'fuel_date'       => trim($this->input->post('fuel_date') ?? date('Y-m-d')),
                'litres'          => (float) ($this->input->post('litres') ?? 0),
                'price_per_litre' => (float) ($this->input->post('price_per_litre') ?? 0),
                'total_amount'    => (float) ($this->input->post('total_amount') ?? 0),
                'station'         => trim($this->input->post('station') ?? ''),
                'driver_staff_id' => trim($this->input->post('driver_staff_id') ?? ''),
                'odometer_km'     => (int) ($this->input->post('odometer_km') ?? 0),
                'notes'           => trim($this->input->post('notes') ?? ''),
                'receipt_path'    => trim($this->input->post('receipt_path') ?? ''),
            ]);
        } catch (\InvalidArgumentException $e) {
            $this->json_error($e->getMessage());
        } catch (\Throwable $e) {
            log_message('error', 'Transport_ops::save_fuel_log failed: ' . $e->getMessage());
            $this->json_error('Failed to save fuel log.');
        }
        $this->json_success([
            'id'            => $result['id'],
            'computed_kmpl' => $result['computed_kmpl'] ?? 0,
            'message'       => 'Fuel entry recorded.',
        ]);
    }

    // ═══════════════════════════════════════════════════════════════════
    //  Expiry dashboard
    // ═══════════════════════════════════════════════════════════════════

    /**
     * Read the tenant's cached expiry dashboard summary. Cache doc:
     *   vehicleExpiryDashboardCache/{schoolId}_VEXPCACHE
     * If missing (never computed), returns an empty envelope so the UI
     * can display a "run now" prompt without crashing.
     */
    public function get_expiry_dashboard()
    {
        $this->_require_role(self::VIEW_ROLES, 'transport_view');
        $this->_require_view();
        // RC-3: bare entity id (getEntity adds the single {schoolId} prefix) so
        // this reads the same canonical {schoolId}_VEXPCACHE the writer now emits.
        $cacheDocId = 'VEXPCACHE';
        $doc = $this->fs->getEntity('vehicleExpiryDashboardCache', $cacheDocId);
        if (!is_array($doc)) {
            $doc = [
                'schoolId'       => $this->school_name,
                'computed_at'    => '',
                'count_7_days'   => 0,
                'count_15_days'  => 0,
                'count_30_days'  => 0,
                'items'          => [],
                'items_truncated'=> false,
            ];
        }
        $this->json_success(['dashboard' => $doc]);
    }

    /**
     * Admin-triggered recompute. Normal path is the nightly Transport_expiry_cron;
     * this endpoint is available for on-demand refresh (e.g. after a batch
     * document upload).
     */
    public function recompute_expiry_dashboard()
    {
        $this->_require_role(self::MANAGE_ROLES, 'recompute_expiry_dashboard');
        $this->_require_manage();
        try {
            $summary = $this->transport->computeFleetExpiryDashboard();
        } catch (\Throwable $e) {
            log_message('error', 'Transport_ops::recompute_expiry_dashboard failed: ' . $e->getMessage());
            $this->json_error('Failed to recompute dashboard.');
        }
        $this->json_success([
            'count_7_days'  => $summary['count_7_days'],
            'count_15_days' => $summary['count_15_days'],
            'count_30_days' => $summary['count_30_days'],
            'message'       => 'Dashboard recomputed.',
        ]);
    }

    // ═══════════════════════════════════════════════════════════════════
    //  F5 Transport Expenses + Accounting (2026-07-07)
    // ═══════════════════════════════════════════════════════════════════

    /** GET /transport_ops/get_expenses[?filters] */
    public function get_expenses()
    {
        $this->_require_role(self::VIEW_ROLES, 'transport_view');
        $this->_require_view();
        $filters = [
            'expense_type' => trim($this->input->get('expense_type') ?? ''),
            'vehicle_id'   => trim($this->input->get('vehicle_id') ?? ''),
            'status'       => trim($this->input->get('status') ?? ''),
            'date_from'    => trim($this->input->get('date_from') ?? ''),
            'date_to'      => trim($this->input->get('date_to') ?? ''),
        ];
        $this->json_success(['expenses' => $this->transport->listExpenses($filters)]);
    }

    /** GET /transport_ops/get_expense?id=X */
    public function get_expense()
    {
        $this->_require_role(self::VIEW_ROLES, 'transport_view');
        $this->_require_view();
        $id = $this->safe_path_segment(trim($this->input->get('id') ?? ''), 'expense_id');
        $this->json_success(['expense' => $this->transport->getExpense($id)]);
    }

    /** POST /transport_ops/save_expense */
    public function save_expense()
    {
        $this->_require_role(self::MANAGE_ROLES, 'save_expense');
        $this->_require_manage();
        $attachments = $this->input->post('attachments_json');
        $attachments = is_string($attachments) && $attachments !== '' ? json_decode($attachments, true) : [];
        if (!is_array($attachments)) { $attachments = []; }
        $input = [
            'id'                     => trim($this->input->post('id') ?? ''),
            'expense_type'           => trim($this->input->post('expense_type') ?? ''),
            'expense_type_custom'    => trim($this->input->post('expense_type_custom') ?? ''),
            'expense_date'           => trim($this->input->post('expense_date') ?? date('Y-m-d')),
            'amount'                 => (float) ($this->input->post('amount') ?? 0),
            'tax_amount'             => (float) ($this->input->post('tax_amount') ?? 0),
            'tax_rate'               => (float) ($this->input->post('tax_rate') ?? 0),
            'payee_type'             => trim($this->input->post('payee_type') ?? 'External'),
            'payee_id'               => trim($this->input->post('payee_id') ?? ''),
            'payee_name'             => trim($this->input->post('payee_name') ?? ''),
            'vehicle_id'             => trim($this->input->post('vehicle_id') ?? ''),
            'related_maintenance_id' => trim($this->input->post('related_maintenance_id') ?? ''),
            'related_fuel_id'        => trim($this->input->post('related_fuel_id') ?? ''),
            'related_incident_id'    => trim($this->input->post('related_incident_id') ?? ''),
            'payment_method'         => trim($this->input->post('payment_method') ?? 'Cash'),
            'payment_reference'      => trim($this->input->post('payment_reference') ?? ''),
            'attachments'            => $attachments,
            'notes'                  => trim($this->input->post('notes') ?? ''),
            'force_post'             => ((string) ($this->input->post('force_post') ?? '')) === '1',
        ];
        try {
            $result = $this->transport->saveExpense($input);
        } catch (\InvalidArgumentException $e) {
            $this->json_error($e->getMessage());
        } catch (\Throwable $e) {
            log_message('error', 'Transport_ops::save_expense failed: ' . $e->getMessage());
            $this->json_error('Failed to save expense: ' . $e->getMessage());
        }
        $verb = $result['isNew'] ? 'created' : 'updated';
        $this->json_success([
            'id'       => $result['id'],
            'posting'  => $result['posting'],
            'message'  => "Expense {$verb}." . ($result['posting']['status'] === 'posted' ? ' Posted to Accounting.' : ($result['posting']['status'] === 'pending' ? ' Pending post (will retry).' : '')),
        ]);
    }

    /** POST /transport_ops/void_expense */
    public function void_expense()
    {
        $this->_require_role(self::MANAGE_ROLES, 'void_expense');
        $this->_require_manage();
        try {
            $this->transport->voidExpense(
                $this->safe_path_segment(trim($this->input->post('id') ?? ''), 'expense_id'),
                trim($this->input->post('reason') ?? '')
            );
        } catch (\InvalidArgumentException $e) {
            $this->json_error($e->getMessage());
        } catch (\Throwable $e) {
            log_message('error', 'Transport_ops::void_expense failed: ' . $e->getMessage());
            $this->json_error('Failed to void expense.');
        }
        $this->json_success(['message' => 'Expense voided.']);
    }

    /** POST /transport_ops/create_expense_from_maintenance */
    public function create_expense_from_maintenance()
    {
        $this->_require_role(self::MANAGE_ROLES, 'create_expense_from_maintenance');
        $this->_require_manage();
        try {
            $result = $this->transport->createExpenseFromMaintenanceLog(
                $this->safe_path_segment(trim($this->input->post('maintenance_id') ?? ''), 'maintenance_id'),
                []
            );
        } catch (\InvalidArgumentException $e) {
            $this->json_error($e->getMessage());
        } catch (\RuntimeException $e) {
            $this->json_error($e->getMessage());
        } catch (\Throwable $e) {
            log_message('error', 'Transport_ops::create_expense_from_maintenance failed: ' . $e->getMessage());
            $this->json_error('Failed to create expense.');
        }
        $this->json_success(['id' => $result['id'], 'posting' => $result['posting'], 'message' => 'Expense created from maintenance log.']);
    }

    /** POST /transport_ops/create_expense_from_fuel */
    public function create_expense_from_fuel()
    {
        $this->_require_role(self::MANAGE_ROLES, 'create_expense_from_fuel');
        $this->_require_manage();
        try {
            $result = $this->transport->createExpenseFromFuelLog(
                $this->safe_path_segment(trim($this->input->post('fuel_id') ?? ''), 'fuel_id'),
                []
            );
        } catch (\InvalidArgumentException $e) {
            $this->json_error($e->getMessage());
        } catch (\RuntimeException $e) {
            $this->json_error($e->getMessage());
        } catch (\Throwable $e) {
            log_message('error', 'Transport_ops::create_expense_from_fuel failed: ' . $e->getMessage());
            $this->json_error('Failed to create expense.');
        }
        $this->json_success(['id' => $result['id'], 'posting' => $result['posting'], 'message' => 'Expense created from fuel log.']);
    }

    /** POST /transport_ops/reconcile_pending_posts */
    public function reconcile_pending_posts()
    {
        $this->_require_role(self::MANAGE_ROLES, 'reconcile_pending_posts');
        $this->_require_manage();
        try {
            $r = $this->transport->reconcilePendingAccountingPosts(50);
        } catch (\Throwable $e) {
            log_message('error', 'Transport_ops::reconcile_pending_posts failed: ' . $e->getMessage());
            $this->json_error('Reconciliation failed.');
        }
        $this->json_success([
            'retried' => $r['retried'], 'posted' => $r['posted'], 'failed' => $r['failed'],
            'message' => "Reconciled: {$r['retried']} retried, {$r['posted']} posted, {$r['failed']} failed",
        ]);
    }

    /** POST /transport_ops/retry_expense_post */
    public function retry_expense_post()
    {
        $this->_require_role(self::MANAGE_ROLES, 'retry_expense_post');
        $this->_require_manage();
        try {
            $r = $this->transport->retryExpensePost(
                $this->safe_path_segment(trim($this->input->post('id') ?? ''), 'expense_id')
            );
        } catch (\InvalidArgumentException $e) {
            $this->json_error($e->getMessage());
        } catch (\Throwable $e) {
            log_message('error', 'Transport_ops::retry_expense_post failed: ' . $e->getMessage());
            $this->json_error('Retry failed.');
        }
        $this->json_success(['posting' => $r, 'message' => "Retry status: {$r['status']}"]);
    }

    /** POST /transport_ops/post_expense_now — for manual-mode expenses */
    public function post_expense_now()
    {
        $this->_require_role(self::MANAGE_ROLES, 'post_expense_now');
        $this->_require_manage();
        try {
            $r = $this->transport->postExpenseNow(
                $this->safe_path_segment(trim($this->input->post('id') ?? ''), 'expense_id')
            );
        } catch (\InvalidArgumentException $e) {
            $this->json_error($e->getMessage());
        } catch (\Throwable $e) {
            log_message('error', 'Transport_ops::post_expense_now failed: ' . $e->getMessage());
            $this->json_error('Post failed.');
        }
        $this->json_success(['posting' => $r, 'message' => "Post status: {$r['status']}"]);
    }

    /** GET /transport_ops/get_expense_categories */
    public function get_expense_categories()
    {
        $this->_require_role(self::VIEW_ROLES, 'transport_view');
        $this->_require_view();
        $this->json_success($this->transport->getExpenseCategories());
    }

    /** GET /transport_ops/get_expense_summary?year_month=YYYY-MM */
    public function get_expense_summary()
    {
        $this->_require_role(self::VIEW_ROLES, 'transport_view');
        $this->_require_view();
        $ym = trim($this->input->get('year_month') ?? date('Y-m'));
        // RC-3: bare entity id (getEntity adds the single {schoolId} prefix) so
        // this reads the same canonical {schoolId}_FMES__{ym} the writer now emits.
        $docId = 'FMES__' . $ym;
        $doc = $this->fs->getEntity('fleetMonthlyExpenseSummary', $docId);
        if (!is_array($doc)) {
            $doc = ['summaryId' => $docId, 'period_year_month' => $ym, 'total_amount' => 0, 'expenses_count' => 0];
        }
        $this->json_success(['summary' => $doc]);
    }

    /** POST /transport_ops/rebuild_expense_summary */
    public function rebuild_expense_summary()
    {
        $this->_require_role(self::MANAGE_ROLES, 'rebuild_expense_summary');
        $this->_require_manage();
        $ym = trim($this->input->post('year_month') ?? '');
        try {
            $r = $this->transport->rebuildMonthlyExpenseSummary($ym);
        } catch (\Throwable $e) {
            log_message('error', 'Transport_ops::rebuild_expense_summary failed: ' . $e->getMessage());
            $this->json_error('Rebuild failed.');
        }
        $this->json_success([
            'total_amount'    => $r['total_amount'],
            'expenses_count'  => $r['expenses_count'],
            'message'         => "Expense summary rebuilt for {$r['period_year_month']}: {$r['expenses_count']} expenses, INR {$r['total_amount']}",
        ]);
    }
}
