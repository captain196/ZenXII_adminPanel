<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Inventory Management Controller
 *
 * Sub-modules: Items, Categories, Vendors, Purchases, Stock Issues
 *
 * Phase 5 — fully migrated to Firestore. Collections:
 *   inventory/{schoolId}_{ITM0001}           (items — already read by apps)
 *   inventoryCategories/{schoolId}_{ICAT0001}
 *   vendors/{schoolId}_{VND0001}             (already read by apps)
 *   purchaseOrders/{schoolId}_{PO0001}       (already read by apps)
 *   inventoryIssues/{schoolId}_{ISI0001}
 *
 * The Parent + Teacher apps' InventoryFirestoreRepository already
 * subscribes to inventory / purchaseOrders / vendors, so cutting the
 * admin writes over makes three surfaces consistent in real time.
 *
 * Accounting integration unchanged: purchases still emit a journal
 * (Dr 1060 Inventory, Cr 1010/1020 Cash/Bank) via Operations_accounting.
 */
class Inventory extends MY_Controller
{
    private const MANAGE_ROLES = ['Super Admin', 'School Super Admin', 'Admin', 'Principal', 'Operations Manager', 'Store Manager'];
    private const VIEW_ROLES   = ['Super Admin', 'School Super Admin', 'Admin', 'Principal', 'Operations Manager', 'Store Manager', 'Accountant', 'Teacher'];

    private const COL_ITEMS      = 'inventory';
    private const COL_CATEGORIES = 'inventoryCategories';
    private const COL_VENDORS    = 'vendors';
    private const COL_PURCHASES  = 'purchaseOrders';
    private const COL_ISSUES     = 'inventoryIssues';

    public function __construct()
    {
        parent::__construct();
        require_permission('Operations');
        $this->load->library('operations_accounting');
        $this->operations_accounting->init(
            $this->firebase, $this->school_name, $this->session_year, $this->admin_id, $this, $this->parent_db_key
        );
    }

    // ── Counter path (for Operations_accounting::next_id — still scoped per school) ──
    private function _counters(string $type): string
    {
        return "Schools/{$this->school_name}/Operations/Inventory/Counters/{$type}";
    }

    // ====================================================================
    //  PAGE LOAD
    // ====================================================================
    public function index()
    {
        $this->_require_role(self::VIEW_ROLES);
        $tab = $this->uri->segment(2, 'items');
        $data = ['active_tab' => $tab];
        $this->load->view('include/header', $data);
        $this->load->view('inventory/index', $data);
        $this->load->view('include/footer');
    }

    // ====================================================================
    //  CATEGORIES
    // ====================================================================
    public function get_categories()
    {
        $this->_require_role(self::VIEW_ROLES);
        $rows = $this->firebase->firestoreQuery(self::COL_CATEGORIES,
            [['schoolId', '==', $this->school_name]], 'name', 'ASC');
        $list = [];
        foreach ((array) $rows as $r) {
            $d = $r['data'] ?? $r; if (!is_array($d)) continue;
            $d['id'] = $d['categoryId'] ?? ($r['id'] ?? '');
            $list[] = $d;
        }
        $this->json_success(['categories' => $list]);
    }

    public function save_category()
    {
        $this->_require_role(self::MANAGE_ROLES);
        $id   = trim($this->input->post('id') ?? '');
        $name = trim($this->input->post('name') ?? '');
        $desc = trim($this->input->post('description') ?? '');
        if ($name === '') $this->json_error('Category name is required.');

        $isNew = ($id === '');
        if ($isNew) { $id = $this->operations_accounting->next_id($this->_counters('Category'), 'ICAT'); }
        else        { $id = $this->safe_path_segment($id, 'category_id'); }

        $data = [
            'categoryId'  => $id,
            'name'        => $name,
            'description' => $desc,
            'status'      => 'Active',
            'updatedAt'   => date('c'),
        ];
        if ($isNew) $data['createdAt'] = date('c');

        $this->fs->setEntity(self::COL_CATEGORIES, $id, $data, /* merge */ true);
        $this->json_success(['id' => $id, 'message' => 'Category saved.']);
    }

    public function delete_category()
    {
        $this->_require_role(self::MANAGE_ROLES);
        $id = $this->safe_path_segment(trim($this->input->post('id') ?? ''), 'category_id');
        // Block if any item uses this category.
        $using = $this->firebase->firestoreQuery(self::COL_ITEMS, [
            ['schoolId',    '==', $this->school_name],
            ['category_id', '==', $id],
        ], null, 'ASC', 1);
        if (!empty($using)) $this->json_error('Cannot delete: items use this category.');
        $this->fs->remove(self::COL_CATEGORIES, $this->fs->docId($id));
        $this->json_success(['message' => 'Category deleted.']);
    }

    // ====================================================================
    //  ITEMS
    // ====================================================================
    public function get_items()
    {
        $this->_require_role(self::VIEW_ROLES);
        $rows = $this->firebase->firestoreQuery(self::COL_ITEMS,
            [['schoolId', '==', $this->school_name]], 'name', 'ASC');
        $list = [];
        foreach ((array) $rows as $r) {
            $d = $r['data'] ?? $r; if (!is_array($d)) continue;
            $d['id'] = $d['itemId'] ?? ($r['id'] ?? '');
            $list[] = $d;
        }
        $this->json_success($this->operations_accounting->paginate(
            $list, 'items', $this->input->get('page'), (int) ($this->input->get('limit') ?? 50)
        ));
    }

    public function save_item()
    {
        $this->_require_role(self::MANAGE_ROLES);
        $id          = trim($this->input->post('id') ?? '');
        $name        = trim($this->input->post('name') ?? '');
        $categoryId  = trim($this->input->post('category_id') ?? '');
        $unit        = trim($this->input->post('unit') ?? 'Pcs');
        $minStock    = max(0, (int) ($this->input->post('min_stock') ?? 0));
        $location    = trim($this->input->post('location') ?? '');
        $description = trim($this->input->post('description') ?? '');
        if ($name === '') $this->json_error('Item name is required.');

        $isNew = ($id === '');
        if ($isNew) {
            $id = $this->operations_accounting->next_id($this->_counters('Item'), 'ITM');
            $currentStock = 0;
        } else {
            $id = $this->safe_path_segment($id, 'item_id');
            $existing = $this->fs->getEntity(self::COL_ITEMS, $id);
            $currentStock = is_array($existing) ? (int) ($existing['current_stock'] ?? 0) : 0;
        }

        $data = [
            'itemId'        => $id,
            'name'          => $name,
            'category_id'   => $categoryId,
            'unit'          => $unit,
            'min_stock'     => $minStock,
            'current_stock' => $currentStock,
            'location'      => $location,
            'description'   => $description,
            'status'        => 'Active',
            'updatedAt'     => date('c'),
        ];
        if ($isNew) $data['createdAt'] = date('c');

        $this->fs->setEntity(self::COL_ITEMS, $id, $data, /* merge */ true);
        $this->json_success(['id' => $id, 'message' => 'Item saved.']);
    }

    public function delete_item()
    {
        $this->_require_role(self::MANAGE_ROLES);
        $id   = $this->safe_path_segment(trim($this->input->post('id') ?? ''), 'item_id');
        $item = $this->fs->getEntity(self::COL_ITEMS, $id);
        if (is_array($item) && (int) ($item['current_stock'] ?? 0) > 0) {
            $this->json_error('Cannot delete: item has stock. Clear stock first.');
        }
        $this->fs->remove(self::COL_ITEMS, $this->fs->docId($id));
        $this->json_success(['message' => 'Item deleted.']);
    }

    // ====================================================================
    //  VENDORS
    // ====================================================================
    public function get_vendors()
    {
        $this->_require_role(self::VIEW_ROLES);
        $rows = $this->firebase->firestoreQuery(self::COL_VENDORS,
            [['schoolId', '==', $this->school_name]], 'name', 'ASC');
        $list = [];
        foreach ((array) $rows as $r) {
            $d = $r['data'] ?? $r; if (!is_array($d)) continue;
            $d['id'] = $d['vendorId'] ?? ($r['id'] ?? '');
            $list[] = $d;
        }
        $this->json_success(['vendors' => $list]);
    }

    public function save_vendor()
    {
        $this->_require_role(self::MANAGE_ROLES);
        $id      = trim($this->input->post('id') ?? '');
        $name    = trim($this->input->post('name') ?? '');
        $contact = trim($this->input->post('contact') ?? '');
        $email   = trim($this->input->post('email') ?? '');
        $address = trim($this->input->post('address') ?? '');
        $gst     = trim($this->input->post('gst') ?? '');
        if ($name === '') $this->json_error('Vendor name is required.');

        $isNew = ($id === '');
        if ($isNew) { $id = $this->operations_accounting->next_id($this->_counters('Vendor'), 'VND'); }
        else        { $id = $this->safe_path_segment($id, 'vendor_id'); }

        $data = [
            'vendorId'  => $id,
            'name'      => $name,
            'contact'   => $contact,
            'email'     => $email,
            'address'   => $address,
            'gst'       => $gst,
            'status'    => 'Active',
            'updatedAt' => date('c'),
        ];
        if ($isNew) $data['createdAt'] = date('c');

        $this->fs->setEntity(self::COL_VENDORS, $id, $data, /* merge */ true);
        $this->json_success(['id' => $id, 'message' => 'Vendor saved.']);
    }

    public function delete_vendor()
    {
        $this->_require_role(self::MANAGE_ROLES);
        $id = $this->safe_path_segment(trim($this->input->post('id') ?? ''), 'vendor_id');
        // Block if any purchase references this vendor.
        $using = $this->firebase->firestoreQuery(self::COL_PURCHASES, [
            ['schoolId',  '==', $this->school_name],
            ['vendor_id', '==', $id],
        ], null, 'ASC', 1);
        if (!empty($using)) return $this->json_error('Cannot delete: vendor has purchase records. Remove or reassign purchases first.');
        $this->fs->remove(self::COL_VENDORS, $this->fs->docId($id));
        $this->json_success(['message' => 'Vendor deleted.']);
    }

    // ====================================================================
    //  PURCHASES
    // ====================================================================
    public function get_purchases()
    {
        $this->_require_role(self::VIEW_ROLES);
        $rows = $this->firebase->firestoreQuery(self::COL_PURCHASES,
            [['schoolId', '==', $this->school_name]], 'date', 'DESC');
        $list = [];
        foreach ((array) $rows as $r) {
            $d = $r['data'] ?? $r; if (!is_array($d)) continue;
            $d['id'] = $d['purchaseId'] ?? ($r['id'] ?? '');
            $list[] = $d;
        }
        $this->json_success($this->operations_accounting->paginate(
            $list, 'purchases',
            $this->input->get('page'),
            (int) ($this->input->get('limit') ?? 50)
        ));
    }

    public function save_purchase()
    {
        $this->_require_role(self::MANAGE_ROLES);
        $itemId      = $this->safe_path_segment(trim($this->input->post('item_id') ?? ''), 'item_id');
        $vendorId    = trim($this->input->post('vendor_id') ?? '');
        if ($vendorId !== '') $vendorId = $this->safe_path_segment($vendorId, 'vendor_id');
        $qty         = max(1, (int) ($this->input->post('qty') ?? 1));
        $unitPrice   = max(0, (float) ($this->input->post('unit_price') ?? 0));
        $date        = trim($this->input->post('date') ?? date('Y-m-d'));
        $invoiceNo   = trim($this->input->post('invoice_no') ?? '');
        $paymentMode = trim($this->input->post('payment_mode') ?? 'Cash');
        $notes       = trim($this->input->post('notes') ?? '');

        if ($unitPrice <= 0) $this->json_error('Unit price must be greater than 0.');

        $item = $this->fs->getEntity(self::COL_ITEMS, $itemId);
        if (!is_array($item)) $this->json_error('Item not found.');

        $total = round($qty * $unitPrice, 2);
        $poId  = $this->operations_accounting->next_id($this->_counters('Purchase'), 'PO');

        // Validate accounting accounts before journal.
        $cashAcct = ($paymentMode === 'Bank') ? '1020' : '1010';
        $this->operations_accounting->validate_accounts(['1060', $cashAcct]);

        // Multi-write sequence preserved:
        //  1. Purchase record (Pending status)
        //  2. Journal entry
        //  3. Stock update + status Completed
        try {
            $vendorName = '';
            if ($vendorId !== '') {
                $vendor     = $this->fs->getEntity(self::COL_VENDORS, $vendorId);
                $vendorName = is_array($vendor) ? (string) ($vendor['name'] ?? '') : '';
            }

            $purchaseData = [
                'purchaseId'   => $poId,
                'item_id'      => $itemId,
                'item_name'    => (string) ($item['name'] ?? ''),
                'vendor_id'    => $vendorId,
                'vendor_name'  => $vendorName,
                'qty'          => $qty,
                'unit_price'   => $unitPrice,
                'total'        => $total,
                'date'         => $date,
                'invoice_no'   => $invoiceNo,
                'payment_mode' => $paymentMode,
                'journal_id'   => '',
                'notes'        => $notes,
                'status'       => 'Pending',
                'created_by'   => $this->admin_name,
                'createdAt'    => date('c'),
            ];
            $this->fs->setEntity(self::COL_PURCHASES, $poId, $purchaseData, /* merge */ true);

            $narration = "Inventory purchase: {$item['name']} x{$qty} - Invoice: {$invoiceNo}";
            $journalId = $this->operations_accounting->create_journal($narration, [
                ['account_code' => '1060',    'dr' => $total, 'cr' => 0],
                ['account_code' => $cashAcct, 'dr' => 0,      'cr' => $total],
            ], 'Inventory', $poId);

            $this->fs->setEntity(self::COL_PURCHASES, $poId, [
                'journal_id' => $journalId,
                'updatedAt'  => date('c'),
            ], /* merge */ true);

            // Stock update with race-window retry, mirroring legacy semantics.
            $freshItem   = $this->fs->getEntity(self::COL_ITEMS, $itemId);
            $freshStock  = is_array($freshItem) ? (int) ($freshItem['current_stock'] ?? 0) : 0;
            $newStock    = $freshStock + $qty;
            $this->fs->setEntity(self::COL_ITEMS, $itemId, [
                'current_stock' => $newStock,
                'updatedAt'     => date('c'),
            ], /* merge */ true);

            // Verify (re-read). If an intervening writer pushed current_stock
            // elsewhere we re-apply our +qty on top of the fresh value.
            $verifyItem = $this->fs->getEntity(self::COL_ITEMS, $itemId);
            $verified   = is_array($verifyItem) ? (int) ($verifyItem['current_stock'] ?? 0) : 0;
            if ($verified !== $newStock) {
                $retryStock = $verified;
                if ($retryStock < $freshStock + $qty) {
                    $newStock = $retryStock + $qty;
                    $this->fs->setEntity(self::COL_ITEMS, $itemId, [
                        'current_stock' => $newStock,
                        'updatedAt'     => date('c'),
                    ], /* merge */ true);
                }
            }

            $this->fs->setEntity(self::COL_PURCHASES, $poId, [
                'status'    => 'Completed',
                'updatedAt' => date('c'),
            ], /* merge */ true);

            $this->json_success([
                'id'         => $poId,
                'message'    => "Purchase recorded. Stock updated to {$newStock}. Journal: {$journalId}.",
                'journal_id' => $journalId,
            ]);
        } catch (Exception $e) {
            log_message('error', "Inventory::save_purchase — partial write failure: {$e->getMessage()} [PO={$poId}]");
            $this->json_error('Purchase recording failed. Check purchase ' . $poId . ' for partial data.');
        }
    }

    // ====================================================================
    //  STOCK ISSUES
    // ====================================================================
    public function get_issues()
    {
        $this->_require_role(self::VIEW_ROLES);
        $rows = $this->firebase->firestoreQuery(self::COL_ISSUES,
            [['schoolId', '==', $this->school_name]], 'date', 'DESC');
        $list = [];
        foreach ((array) $rows as $r) {
            $d = $r['data'] ?? $r; if (!is_array($d)) continue;
            $d['id'] = $d['issueId'] ?? ($r['id'] ?? '');
            $list[] = $d;
        }
        $this->json_success($this->operations_accounting->paginate(
            $list, 'issues',
            $this->input->get('page'),
            (int) ($this->input->get('limit') ?? 50)
        ));
    }

    public function save_issue()
    {
        $this->_require_role(self::MANAGE_ROLES);
        $itemId   = $this->safe_path_segment(trim($this->input->post('item_id') ?? ''), 'item_id');
        $issuedTo = trim($this->input->post('issued_to') ?? '');
        $qty      = max(1, (int) ($this->input->post('qty') ?? 1));
        $purpose  = trim($this->input->post('purpose') ?? '');

        if ($issuedTo === '') $this->json_error('Issued-to (person/department) is required.');

        $item = $this->fs->getEntity(self::COL_ITEMS, $itemId);
        if (!is_array($item)) $this->json_error('Item not found.');
        if ((int) ($item['current_stock'] ?? 0) < $qty) {
            $this->json_error("Insufficient stock. Available: {$item['current_stock']}.");
        }

        $issId = $this->operations_accounting->next_id($this->_counters('StockIssue'), 'ISI');

        $issueData = [
            'issueId'     => $issId,
            'item_id'     => $itemId,
            'item_name'   => (string) ($item['name'] ?? ''),
            'issued_to'   => $issuedTo,
            'issued_by'   => $this->admin_name,
            'qty'         => $qty,
            'purpose'     => $purpose,
            'date'        => date('Y-m-d'),
            'return_date' => '',
            'return_qty'  => 0,
            'status'      => 'Issued',
            'createdAt'   => date('c'),
        ];

        try {
            $this->fs->setEntity(self::COL_ISSUES, $issId, $issueData, /* merge */ true);

            // Stock decrement + retry on concurrent writer (same semantics
            // as the legacy RTDB path).
            $freshItem = $this->fs->getEntity(self::COL_ITEMS, $itemId);
            $freshStock = is_array($freshItem) ? (int) ($freshItem['current_stock'] ?? 0) : 0;
            if ($freshStock < $qty) $this->json_error("Insufficient stock. Available: {$freshStock}.");
            $newStock = $freshStock - $qty;
            $this->fs->setEntity(self::COL_ITEMS, $itemId, [
                'current_stock' => $newStock,
                'updatedAt'     => date('c'),
            ], /* merge */ true);

            $verifyItem = $this->fs->getEntity(self::COL_ITEMS, $itemId);
            $verified   = is_array($verifyItem) ? (int) ($verifyItem['current_stock'] ?? 0) : 0;
            if ($verified !== $newStock) {
                $retryStock = $verified;
                if ($retryStock > $freshStock - $qty) {
                    if ($retryStock < $qty) {
                        $this->json_error("Insufficient stock after conflict. Available: {$retryStock}.");
                    }
                    $newStock = $retryStock - $qty;
                    $this->fs->setEntity(self::COL_ITEMS, $itemId, [
                        'current_stock' => $newStock,
                        'updatedAt'     => date('c'),
                    ], /* merge */ true);
                }
            }

            $this->json_success(['id' => $issId, 'message' => "Issued {$qty} {$item['unit']} of {$item['name']}."]);
        } catch (Exception $e) {
            log_message('error', "Inventory::save_issue — partial write failure: {$e->getMessage()} [ISI={$issId}]");
            $this->json_error('Stock issue recording failed. Check issue ' . $issId . ' for partial data.');
        }
    }

    public function return_issue()
    {
        $this->_require_role(self::MANAGE_ROLES);
        $issueId   = $this->safe_path_segment(trim($this->input->post('issue_id') ?? ''), 'issue_id');
        $returnQty = max(1, (int) ($this->input->post('return_qty') ?? 0));

        $issue = $this->fs->getEntity(self::COL_ISSUES, $issueId);
        if (!is_array($issue))                      $this->json_error('Issue record not found.');
        if (($issue['status'] ?? '') !== 'Issued')  $this->json_error('Stock is not currently issued.');

        $issuedQty      = (int) ($issue['qty'] ?? 0);
        $alreadyReturned = (int) ($issue['return_qty'] ?? 0);
        if ($returnQty > ($issuedQty - $alreadyReturned)) {
            $this->json_error('Return quantity exceeds issued amount.');
        }

        $totalReturned = $alreadyReturned + $returnQty;
        $newStatus     = ($totalReturned >= $issuedQty) ? 'Returned' : 'Issued';

        try {
            $this->fs->setEntity(self::COL_ISSUES, $issueId, [
                'return_qty'  => $totalReturned,
                'return_date' => date('Y-m-d'),
                'status'      => $newStatus,
                'updatedAt'   => date('c'),
            ], /* merge */ true);

            $itemId = (string) ($issue['item_id'] ?? '');
            if ($itemId !== '') {
                $item = $this->fs->getEntity(self::COL_ITEMS, $itemId);
                if (is_array($item)) {
                    $this->fs->setEntity(self::COL_ITEMS, $itemId, [
                        'current_stock' => (int) ($item['current_stock'] ?? 0) + $returnQty,
                        'updatedAt'     => date('c'),
                    ], /* merge */ true);
                }
            }

            $this->json_success(['message' => "{$returnQty} returned. Status: {$newStatus}."]);
        } catch (Exception $e) {
            log_message('error', "Inventory::return_issue — partial write failure: {$e->getMessage()} [ISI={$issueId}]");
            $this->json_error('Return recording failed. Check issue ' . $issueId . ' for partial data.');
        }
    }

    // ====================================================================
    //  STOCK REPORT
    // ====================================================================
    public function get_stock_report()
    {
        $this->_require_role(self::VIEW_ROLES);
        $rows = $this->firebase->firestoreQuery(self::COL_ITEMS,
            [['schoolId', '==', $this->school_name]]);
        $report = [];
        foreach ((array) $rows as $r) {
            $it = $r['data'] ?? $r; if (!is_array($it)) continue;
            $stock = (int) ($it['current_stock'] ?? 0);
            $min   = (int) ($it['min_stock']     ?? 0);
            $report[] = [
                'id'       => (string) ($it['itemId'] ?? ($r['id'] ?? '')),
                'name'     => (string) ($it['name']        ?? ''),
                'category' => (string) ($it['category_id'] ?? ''),
                'unit'     => (string) ($it['unit']        ?? ''),
                'stock'    => $stock,
                'min'      => $min,
                'low'      => $stock <= $min,
                'status'   => (string) ($it['status'] ?? ''),
            ];
        }
        $this->json_success(['stock_report' => $report]);
    }
}
