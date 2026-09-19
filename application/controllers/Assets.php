<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Asset Tracking Controller
 *
 * Sub-modules: Registry, Categories, Assignments, Maintenance, Depreciation
 *
 * Canonical storage: Firestore (RTDB retired 2026-09-19; census was empty
 * fleet-wide, so this was a clean cutover with no backfill). All docs are
 * school-scoped via Firestore_service::docId() ({schoolId}_{entityId}) and
 * carry an auto-injected schoolId (setEntity), so queries use schoolWhere():
 *   assets/{schoolId}_{AST0001}
 *   assetCategories/{schoolId}_{ACAT0001}
 *   assetAssignments/{schoolId}_{ASN0001}
 *   assetMaintenance/{schoolId}_{MNT0001}
 *   sequential ids via Firestore_service::nextSchoolCounter (canonical counter).
 *
 * Accounting integration:
 *   Purchase → journal (Dr 11xx Fixed Asset, Cr 1010/1020)
 *   Depreciation → journal (Dr 5050 Depreciation Expense, Cr 1150 Accumulated Depreciation)
 */
class Assets extends MY_Controller
{
    private const MANAGE_ROLES = ['Super Admin', 'School Super Admin', 'Admin', 'Principal', 'Operations Manager', 'Store Manager'];
    private const VIEW_ROLES   = ['Super Admin', 'School Super Admin', 'Admin', 'Principal', 'Operations Manager', 'Store Manager', 'Accountant', 'Teacher'];

    public function __construct()
    {
        parent::__construct();
        require_permission('Assets');
        $this->load->library('operations_accounting');
        $this->operations_accounting->init(
            $this->firebase, $this->school_name, $this->session_year, $this->admin_id, $this, $this->parent_db_key
        );
    }

    // ── Firestore collections (canonical storage) ──────────────────────
    private const COL_ASSETS = 'assets';
    private const COL_CATS   = 'assetCategories';
    private const COL_ASN    = 'assetAssignments';
    private const COL_MNT    = 'assetMaintenance';

    /** Entity id from a schoolWhere() row's doc id ({schoolId}_{entityId}). */
    private function _eid(array $row): string
    {
        $docId  = (string) ($row['id'] ?? '');
        $prefix = $this->fs->schoolId() . '_';
        return strpos($docId, $prefix) === 0 ? substr($docId, strlen($prefix)) : $docId;
    }

    /** Fields of a schoolWhere() row (accepts both {id,data} and raw shapes). */
    private function _data(array $row): array
    {
        return (isset($row['data']) && is_array($row['data'])) ? $row['data'] : $row;
    }

    /** Next sequential id via the canonical per-school atomic counter (reused,
     *  no duplicate id authority). Returns '' on failure so the caller fails
     *  closed rather than minting a colliding id. */
    private function _gen_id(string $kind, string $prefix): string
    {
        $n = $this->fs->nextSchoolCounter('asset' . $kind);
        return $n > 0 ? sprintf('%s%04d', $prefix, $n) : '';
    }

    // ====================================================================
    //  PAGE LOAD
    // ====================================================================

    public function index()
    {
        $this->_require_role(self::VIEW_ROLES, '', 'Assets', 'view');
        $tab = $this->uri->segment(2, 'registry');
        $data = ['active_tab' => $tab];
        $this->load->view('include/header', $data);
        $this->load->view('assets/index', $data);
        $this->load->view('include/footer');
    }

    // ====================================================================
    //  CATEGORIES
    // ====================================================================

    public function get_categories()
    {
        $this->_require_role(self::VIEW_ROLES, '', 'Assets', 'view');
        $rows = $this->fs->schoolWhere(self::COL_CATS);
        $list = [];
        foreach ($rows as $row) { $c = $this->_data($row); $c['id'] = $this->_eid($row); $list[] = $c; }
        $this->json_success(['categories' => $list]);
    }

    public function save_category()
    {
        $this->_require_role(self::MANAGE_ROLES, '', 'Assets', 'edit');
        $id     = trim($this->input->post('id') ?? '');
        $name   = trim($this->input->post('name') ?? '');
        $depRate = max(0, (float) ($this->input->post('depreciation_rate') ?? 10));
        $method = trim($this->input->post('method') ?? 'SLM');
        if ($name === '') $this->json_error('Category name is required.');
        if (!in_array($method, ['SLM', 'WDV'], true)) $method = 'SLM';

        $isNew = ($id === '');
        if ($isNew) {
            $id = $this->_gen_id('Category', 'ACAT');
            if ($id === '') $this->json_error('Could not allocate a category id. Please retry.');
        } else { $id = $this->safe_path_segment($id, 'category_id'); }

        $data = [
            'name' => $name,
            'depreciation_rate' => $depRate,
            'method' => $method,
            'status' => 'Active',
            'updated_at' => date('c'),
        ];
        if ($isNew) $data['created_at'] = date('c');

        $this->fs->setEntity(self::COL_CATS, $id, $data);
        $this->json_success(['id' => $id, 'message' => 'Category saved.']);
    }

    public function delete_category()
    {
        $this->_require_role(self::MANAGE_ROLES, '', 'Assets', 'manage');
        $id = $this->safe_path_segment(trim($this->input->post('id') ?? ''), 'category_id');
        // Scan this school's assets (schoolId-only query — no composite index) and
        // refuse deletion while any asset still references the category.
        $rows = $this->fs->schoolWhere(self::COL_ASSETS);
        foreach ($rows as $row) {
            if (($this->_data($row)['category_id'] ?? '') === $id) {
                $this->json_error('Cannot delete: assets use this category.');
            }
        }
        $this->fs->removeEntity(self::COL_CATS, $id);
        $this->json_success(['message' => 'Category deleted.']);
    }

    // ====================================================================
    //  ASSET REGISTRY
    // ====================================================================

    /** GET — List assets. ?page=1&limit=50 for pagination */
    public function get_assets()
    {
        $this->_require_role(self::VIEW_ROLES, '', 'Assets', 'view');
        $rows = $this->fs->schoolWhere(self::COL_ASSETS);
        $list = [];
        foreach ($rows as $row) { $a = $this->_data($row); $a['id'] = $this->_eid($row); $list[] = $a; }
        $this->json_success($this->operations_accounting->paginate(
            $list, 'assets',
            $this->input->get('page'),
            (int) ($this->input->get('limit') ?? 50)
        ));
    }

    /** POST — Register/update an asset. Creates purchase journal for new assets. */
    public function save_asset()
    {
        $this->_require_role(self::MANAGE_ROLES, '', 'Assets', 'edit');
        $id           = trim($this->input->post('id') ?? '');
        $name         = trim($this->input->post('name') ?? '');
        $categoryId   = trim($this->input->post('category_id') ?? '');
        $purchaseDate = trim($this->input->post('purchase_date') ?? date('Y-m-d'));
        $purchaseCost = max(0, (float) ($this->input->post('purchase_cost') ?? 0));
        $serialNo     = trim($this->input->post('serial_no') ?? '');
        $location     = trim($this->input->post('location') ?? '');
        $condition    = trim($this->input->post('condition') ?? 'Good');
        $accountCode  = trim($this->input->post('account_code') ?? '1120');
        $paymentMode  = trim($this->input->post('payment_mode') ?? 'Cash');
        $description  = trim($this->input->post('description') ?? '');

        if ($name === '') $this->json_error('Asset name is required.');
        if ($purchaseCost <= 0) $this->json_error('Purchase cost must be greater than 0.');
        if (!in_array($condition, ['New', 'Good', 'Fair', 'Poor', 'Disposed'], true)) $condition = 'Good';

        $isNew = ($id === '');
        $existingAsset = null;
        if ($isNew) {
            $id = $this->_gen_id('Asset', 'AST');
            if ($id === '') $this->json_error('Could not allocate an asset id. Please retry.');
        } else {
            $id = $this->safe_path_segment($id, 'asset_id');
            $existingAsset = $this->fs->getEntity(self::COL_ASSETS, $id);
            if (!is_array($existingAsset)) $this->json_error('Asset not found.');
        }

        // Get category info for depreciation
        $depRate = 10; $depMethod = 'SLM';
        if ($categoryId !== '') {
            $cat = $this->fs->getEntity(self::COL_CATS, $categoryId);
            if (is_array($cat)) {
                $depRate   = (float) ($cat['depreciation_rate'] ?? 10);
                $depMethod = $cat['method'] ?? 'SLM';
            }
        }

        $data = [
            'name'              => $name,
            'category_id'       => $categoryId,
            'purchase_date'     => $purchaseDate,
            'purchase_cost'     => $purchaseCost,
            'current_value'     => $isNew ? $purchaseCost : (float) ($existingAsset['current_value'] ?? $purchaseCost),
            'accumulated_dep'   => $isNew ? 0 : (float) ($existingAsset['accumulated_dep'] ?? 0),
            'depreciation_rate' => $depRate,
            'depreciation_method' => $depMethod,
            'serial_no'         => $serialNo,
            'account_code'      => $accountCode,
            'location'          => $location,
            'condition'         => $condition,
            'description'       => $description,
            'status'            => 'Active',
            'updated_at'        => date('c'),
        ];
        if ($isNew) {
            $data['created_at'] = date('c');

            // Create purchase journal for new assets
            $cashAcct = ($paymentMode === 'Bank') ? '1020' : '1010';
            $this->operations_accounting->validate_accounts([$accountCode, $cashAcct]);
            $narration = "Asset purchase: {$name} - SN: {$serialNo}";
            $journalId = $this->operations_accounting->create_journal($narration, [
                ['account_code' => $accountCode, 'dr' => $purchaseCost, 'cr' => 0],
                ['account_code' => $cashAcct,    'dr' => 0,             'cr' => $purchaseCost],
            ], 'Assets', $id);
            $data['purchase_journal_id'] = $journalId;
        }

        $this->fs->setEntity(self::COL_ASSETS, $id, $data);
        $this->json_success(['id' => $id, 'message' => 'Asset saved.']);
    }

    public function delete_asset()
    {
        $this->_require_role(self::MANAGE_ROLES, '', 'Assets', 'manage');
        $id = $this->safe_path_segment(trim($this->input->post('id') ?? ''), 'asset_id');

        // Check active assignments
        $asnRows = $this->fs->schoolWhere(self::COL_ASN);
        foreach ($asnRows as $row) {
            $a = $this->_data($row);
            if (($a['asset_id'] ?? '') === $id && ($a['status'] ?? '') === 'Active') {
                return $this->json_error('Cannot delete: asset is currently assigned.');
            }
        }

        // OPS-5 FIX: Cascade delete maintenance records for this asset
        $mntRows = $this->fs->schoolWhere(self::COL_MNT);
        foreach ($mntRows as $row) {
            if (($this->_data($row)['asset_id'] ?? '') === $id) {
                $this->fs->removeEntity(self::COL_MNT, $this->_eid($row));
            }
        }

        // OPS-5 FIX: Reverse purchase journal if asset had one
        // Operations_accounting does not have a reverse_journal() method yet,
        // so we log a warning for manual follow-up.
        $asset = $this->fs->getEntity(self::COL_ASSETS, $id);
        if (is_array($asset) && !empty($asset['purchase_journal_id'])) {
            log_message('info', "Asset {$id} deleted — purchase journal '{$asset['purchase_journal_id']}' may need manual reversal.");
        }

        $this->fs->removeEntity(self::COL_ASSETS, $id);
        $this->json_success(['message' => 'Asset deleted.']);
    }

    // ====================================================================
    //  ASSIGNMENTS
    // ====================================================================

    public function get_assignments()
    {
        $this->_require_role(self::VIEW_ROLES, '', 'Assets', 'view');
        $filterAssetId = trim($this->input->get('asset_id') ?? '');
        $rows = $this->fs->schoolWhere(self::COL_ASN);
        $list = [];
        foreach ($rows as $row) {
            $a = $this->_data($row);
            if ($filterAssetId !== '' && ($a['asset_id'] ?? '') !== $filterAssetId) continue;
            $a['id'] = $this->_eid($row);
            $list[] = $a;
        }
        $this->json_success(['assignments' => $list]);
    }

    public function save_assignment()
    {
        $this->_require_role(self::MANAGE_ROLES, '', 'Assets', 'edit');
        $assetId    = $this->safe_path_segment(trim($this->input->post('asset_id') ?? ''), 'asset_id');
        $assignedTo = trim($this->input->post('assigned_to') ?? '');
        $assignType = trim($this->input->post('assign_type') ?? 'staff');

        if ($assignedTo === '') $this->json_error('Assigned-to is required (staff name, room, or department).');

        // Verify asset
        $asset = $this->fs->getEntity(self::COL_ASSETS, $assetId);
        if (!is_array($asset)) $this->json_error('Asset not found.');

        $asnId = $this->_gen_id('Assignment', 'ASN');
        if ($asnId === '') $this->json_error('Could not allocate an assignment id. Please retry.');

        $data = [
            'asset_id'    => $assetId,
            'asset_name'  => $asset['name'] ?? '',
            'assigned_to' => $assignedTo,
            'assign_type' => $assignType,
            'assigned_by' => $this->admin_name,
            'date'        => date('Y-m-d'),
            'return_date' => '',
            'status'      => 'Active',
            'created_at'  => date('c'),
        ];

        $this->fs->setEntity(self::COL_ASN, $asnId, $data);
        $this->json_success(['id' => $asnId, 'message' => "Asset assigned to {$assignedTo}."]);
    }

    public function return_assignment()
    {
        $this->_require_role(self::MANAGE_ROLES, '', 'Assets', 'edit');
        $asnId = $this->safe_path_segment(trim($this->input->post('assignment_id') ?? ''), 'assignment_id');

        $asn = $this->fs->getEntity(self::COL_ASN, $asnId);
        if (!is_array($asn)) $this->json_error('Assignment not found.');
        if (($asn['status'] ?? '') !== 'Active') $this->json_error('Assignment is not active.');

        $this->fs->updateEntity(self::COL_ASN, $asnId, [
            'return_date' => date('Y-m-d'),
            'status'      => 'Returned',
            'returned_by' => $this->admin_name,
            'updated_at'  => date('c'),
        ]);

        $this->json_success(['message' => 'Asset returned.']);
    }

    // ====================================================================
    //  MAINTENANCE
    // ====================================================================

    /** GET — List maintenance records. ?asset_id=AST0001&page=1&limit=50 */
    public function get_maintenance()
    {
        $this->_require_role(self::VIEW_ROLES, '', 'Assets', 'view');
        $filterAssetId = trim($this->input->get('asset_id') ?? '');
        $rows = $this->fs->schoolWhere(self::COL_MNT);
        $list = [];
        foreach ($rows as $row) {
            $m = $this->_data($row);
            if ($filterAssetId !== '' && ($m['asset_id'] ?? '') !== $filterAssetId) continue;
            $m['id'] = $this->_eid($row);
            $list[] = $m;
        }
        usort($list, function ($a, $b) {
            return strcmp($b['date'] ?? '', $a['date'] ?? '');
        });
        $this->json_success($this->operations_accounting->paginate(
            $list, 'maintenance',
            $this->input->get('page'),
            (int) ($this->input->get('limit') ?? 50)
        ));
    }

    public function save_maintenance()
    {
        $this->_require_role(self::MANAGE_ROLES, '', 'Assets', 'edit');
        $id       = trim($this->input->post('id') ?? '');
        $assetId  = $this->safe_path_segment(trim($this->input->post('asset_id') ?? ''), 'asset_id');
        $type     = trim($this->input->post('type') ?? 'Repair');
        $desc     = trim($this->input->post('description') ?? '');
        $cost     = max(0, (float) ($this->input->post('cost') ?? 0));
        $date     = trim($this->input->post('date') ?? date('Y-m-d'));
        $nextDue  = trim($this->input->post('next_due') ?? '');
        $vendor   = trim($this->input->post('vendor') ?? '');
        $status   = trim($this->input->post('status') ?? 'Completed');

        if ($desc === '') $this->json_error('Description is required.');
        if (!in_array($type, ['Repair', 'Service', 'Inspection', 'Upgrade', 'Other'], true)) $type = 'Repair';
        if (!in_array($status, ['Scheduled', 'InProgress', 'Completed'], true)) $status = 'Completed';

        // Verify asset
        $asset = $this->fs->getEntity(self::COL_ASSETS, $assetId);
        if (!is_array($asset)) $this->json_error('Asset not found.');

        $isNew = ($id === '');
        if ($isNew) {
            $id = $this->_gen_id('Maintenance', 'MNT');
            if ($id === '') $this->json_error('Could not allocate a maintenance id. Please retry.');
        } else { $id = $this->safe_path_segment($id, 'maintenance_id'); }

        $data = [
            'asset_id'   => $assetId,
            'asset_name' => $asset['name'] ?? '',
            'type'       => $type,
            'description'=> $desc,
            'cost'       => $cost,
            'date'       => $date,
            'next_due'   => $nextDue,
            'vendor'     => $vendor,
            'status'     => $status,
            'updated_at' => date('c'),
        ];
        if ($isNew) $data['created_at'] = date('c');

        $this->fs->setEntity(self::COL_MNT, $id, $data);
        $this->json_success(['id' => $id, 'message' => 'Maintenance record saved.']);
    }

    // ====================================================================
    //  DEPRECIATION
    // ====================================================================

    /**
     * POST — Compute depreciation for all active assets.
     * Creates journal: Dr 5050 Depreciation Expense, Cr 1150 Accumulated Depreciation
     */
    public function compute_depreciation()
    {
        $this->_require_role(self::MANAGE_ROLES, '', 'Assets', 'edit');

        $rows = $this->fs->schoolWhere(self::COL_ASSETS);
        if (empty($rows)) $this->json_error('No assets found.');
        $assets = [];
        foreach ($rows as $row) { $assets[$this->_eid($row)] = $this->_data($row); }

        // Validate accounts
        $this->operations_accounting->validate_accounts(['5050', '1150']);

        $currentMonth = date('Y-m');
        $totalDep = 0;
        $updates  = [];

        foreach ($assets as $id => $a) {
            if (($a['status'] ?? '') !== 'Active') continue;

            // Idempotency guard: skip if already depreciated this month
            $lastDep = $a['last_dep_date'] ?? '';
            if ($lastDep !== '' && substr($lastDep, 0, 7) === $currentMonth) continue;
            $cost       = (float) ($a['purchase_cost'] ?? 0);
            $currentVal = (float) ($a['current_value'] ?? 0);
            $rate       = (float) ($a['depreciation_rate'] ?? 10);
            $method     = $a['depreciation_method'] ?? 'SLM';

            if ($currentVal <= 0) continue;

            // Annual depreciation (run monthly = divide by 12)
            if ($method === 'WDV') {
                $annualDep = $currentVal * ($rate / 100);
            } else {
                $annualDep = $cost * ($rate / 100);
            }
            $monthlyDep = round($annualDep / 12, 2);

            // Don't depreciate below 1 (scrap value)
            if ($currentVal - $monthlyDep < 1) {
                $monthlyDep = max(0, $currentVal - 1);
            }
            if ($monthlyDep <= 0) continue;

            $newValue = round($currentVal - $monthlyDep, 2);
            $accDep   = round((float) ($a['accumulated_dep'] ?? 0) + $monthlyDep, 2);

            $updates[$id] = [
                'current_value'   => $newValue,
                'accumulated_dep' => $accDep,
                'last_dep_date'   => date('Y-m-d'),
                'updated_at'      => date('c'),
            ];
            $totalDep += $monthlyDep;
        }

        if ($totalDep <= 0) $this->json_error('No depreciable assets found.');

        $totalDep = round($totalDep, 2);

        // Persist per-asset updates. Firestore has no atomic multi-document PATCH
        // like RTDB's multi-path write, so each asset is updated independently;
        // the last_dep_date month-guard above makes a partial run safely
        // re-runnable (already-depreciated assets are skipped next time).
        foreach ($updates as $id => $upd) {
            $this->fs->updateEntity(self::COL_ASSETS, $id, $upd);
        }

        // Create depreciation journal
        $month = date('F Y');
        $count = count($updates);
        $narration = "Monthly depreciation - {$month} ({$count} assets)";
        $journalId = $this->operations_accounting->create_journal($narration, [
            ['account_code' => '5050', 'dr' => $totalDep, 'cr' => 0],
            ['account_code' => '1150', 'dr' => 0,         'cr' => $totalDep],
        ], 'Assets', "DEP_{$month}");

        $this->json_success([
            'message'     => "Depreciation computed for {$count} assets. Total: Rs {$totalDep}. Journal: {$journalId}.",
            'total'       => $totalDep,
            'assets'      => count($updates),
            'journal_id'  => $journalId,
        ]);
    }

    /** GET — Depreciation report. */
    public function get_depreciation_report()
    {
        $this->_require_role(self::VIEW_ROLES, '', 'Assets', 'view');
        $rows = $this->fs->schoolWhere(self::COL_ASSETS);
        $list = [];
        {
            foreach ($rows as $row) {
                $a = $this->_data($row);
                $list[] = [
                    'id'              => $this->_eid($row),
                    'name'            => $a['name'] ?? '',
                    'purchase_cost'   => (float) ($a['purchase_cost'] ?? 0),
                    'current_value'   => (float) ($a['current_value'] ?? 0),
                    'accumulated_dep' => (float) ($a['accumulated_dep'] ?? 0),
                    'rate'            => (float) ($a['depreciation_rate'] ?? 0),
                    'method'          => $a['depreciation_method'] ?? 'SLM',
                    'purchase_date'   => $a['purchase_date'] ?? '',
                    'last_dep_date'   => $a['last_dep_date'] ?? '',
                    'status'          => $a['status'] ?? '',
                ];
            }
        }
        $this->json_success(['depreciation_report' => $list]);
    }
}
