<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Inventory Management Controller
 *
 * Sub-modules: Items, Categories, Vendors, Purchases, Stock Issues
 *
 * Firebase paths:
 *   Schools/{school}/Operations/Inventory/Items/{ITM0001}
 *   Schools/{school}/Operations/Inventory/Categories/{ICAT0001}
 *   Schools/{school}/Operations/Inventory/Vendors/{VND0001}
 *   Schools/{school}/Operations/Inventory/Purchases/{PO0001}
 *   Schools/{school}/Operations/Inventory/Issues/{ISI0001}
 *   Schools/{school}/Operations/Inventory/Counters/{type}
 *
 * Accounting integration:
 *   Purchase → journal (Dr 1060 Inventory, Cr 1010/1020 Cash/Bank)
 */
class Inventory extends MY_Controller
{
    private const MANAGE_ROLES = ['Super Admin', 'School Super Admin', 'Admin', 'Principal', 'Operations Manager', 'Store Manager'];
    private const VIEW_ROLES   = ['Super Admin', 'School Super Admin', 'Admin', 'Principal', 'Operations Manager', 'Store Manager', 'Accountant', 'Teacher'];

    public function __construct()
    {
        parent::__construct();
        require_permission('Operations');
        $this->load->library('operations_accounting');
        $this->operations_accounting->init(
            $this->firebase, $this->school_name, $this->session_year, $this->admin_id, $this, $this->parent_db_key
        );
    }

    // ── Path Helpers ────────────────────────────────────────────────────
    private function _inv(string $sub = ''): string
    {
        $b = "Schools/{$this->school_name}/Operations/Inventory";
        return $sub !== '' ? "{$b}/{$sub}" : $b;
    }
    private function _items(string $id = ''): string
    {
        return $id !== '' ? $this->_inv("Items/{$id}") : $this->_inv('Items');
    }
    private function _cats(string $id = ''): string
    {
        return $id !== '' ? $this->_inv("Categories/{$id}") : $this->_inv('Categories');
    }
    private function _vendors(string $id = ''): string
    {
        return $id !== '' ? $this->_inv("Vendors/{$id}") : $this->_inv('Vendors');
    }
    private function _purchases(string $id = ''): string
    {
        return $id !== '' ? $this->_inv("Purchases/{$id}") : $this->_inv('Purchases');
    }
    private function _issues(string $id = ''): string
    {
        return $id !== '' ? $this->_inv("Issues/{$id}") : $this->_inv('Issues');
    }
    private function _counters(string $type): string
    {
        return $this->_inv("Counters/{$type}");
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
        $cats = $this->firebase->get($this->_cats());
        $list = [];
        if (is_array($cats)) {
            foreach ($cats as $id => $c) { $c['id'] = $id; $list[] = $c; }
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
        else { $id = $this->safe_path_segment($id, 'category_id'); }

        $data = ['name' => $name, 'description' => $desc, 'status' => 'Active', 'updated_at' => date('c')];
        if ($isNew) $data['created_at'] = date('c');

        $this->firebase->set($this->_cats($id), $data);
        $this->json_success(['id' => $id, 'message' => 'Category saved.']);
    }

    public function delete_category()
    {
        $this->_require_role(self::MANAGE_ROLES);
        $id = $this->safe_path_segment(trim($this->input->post('id') ?? ''), 'category_id');
        $items = $this->firebase->get($this->_items());
        if (is_array($items)) {
            foreach ($items as $it) {
                if (($it['category_id'] ?? '') === $id) {
                    $this->json_error('Cannot delete: items use this category.');
                }
            }
        }
        $this->firebase->delete($this->_cats(), $id);
        $this->json_success(['message' => 'Category deleted.']);
    }

    // ====================================================================
    //  ITEMS
    // ====================================================================

    /** GET — List items. Supports ?page=N&limit=N for pagination. */
    public function get_items()
    {
        $this->_require_role(self::VIEW_ROLES);
        $items = $this->firebase->get($this->_items());
        $list = [];
        if (is_array($items)) {
            foreach ($items as $id => $it) { $it['id'] = $id; $list[] = $it; }
        }
        $this->json_success($this->operations_accounting->paginate(
            $list, 'items', $this->input->get('page'), (int) ($this->input->get('limit') ?? 50)
        ));
    }

    public function save_item()
    {
        $this->_require_role(self::MANAGE_ROLES);
        $id           = trim($this->input->post('id') ?? '');
        $name         = trim($this->input->post('name') ?? '');
        $categoryId   = trim($this->input->post('category_id') ?? '');
        $unit         = trim($this->input->post('unit') ?? 'Pcs');
        $minStock     = max(0, (int) ($this->input->post('min_stock') ?? 0));
        $location     = trim($this->input->post('location') ?? '');
        $description  = trim($this->input->post('description') ?? '');

        if ($name === '') $this->json_error('Item name is required.');

        $isNew = ($id === '');
        if ($isNew) {
            $id = $this->operations_accounting->next_id($this->_counters('Item'), 'ITM');
            $currentStock = 0;
        } else {
            $id = $this->safe_path_segment($id, 'item_id');
            $existing = $this->firebase->get($this->_items($id));
            $currentStock = is_array($existing) ? (int) ($existing['current_stock'] ?? 0) : 0;
        }

        $data = [
            'name'          => $name,
            'category_id'   => $categoryId,
            'unit'          => $unit,
            'min_stock'     => $minStock,
            'current_stock' => $currentStock,
            'location'      => $location,
            'description'   => $description,
            'status'        => 'Active',
            'updated_at'    => date('c'),
        ];
        if ($isNew) $data['created_at'] = date('c');

        $this->firebase->set($this->_items($id), $data);
        $this->json_success(['id' => $id, 'message' => 'Item saved.']);
    }

    public function delete_item()
    {
        $this->_require_role(self::MANAGE_ROLES);
        $id = $this->safe_path_segment(trim($this->input->post('id') ?? ''), 'item_id');
        $item = $this->firebase->get($this->_items($id));
        if (is_array($item) && (int) ($item['current_stock'] ?? 0) > 0) {
            $this->json_error('Cannot delete: item has stock. Clear stock first.');
        }
        $this->firebase->delete($this->_items(), $id);
        $this->json_success(['message' => 'Item deleted.']);
    }

    // ====================================================================
    //  VENDORS
    // ====================================================================

    public function get_vendors()
    {
        $this->_require_role(self::VIEW_ROLES);
        $vendors = $this->firebase->get($this->_vendors());
        $list = [];
        if (is_array($vendors)) {
            foreach ($vendors as $id => $v) { $v['id'] = $id; $list[] = $v; }
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
        else { $id = $this->safe_path_segment($id, 'vendor_id'); }

        $data = [
            'name' => $name, 'contact' => $contact, 'email' => $email,
            'address' => $address, 'gst' => $gst,
            'status' => 'Active', 'updated_at' => date('c'),
        ];
        if ($isNew) $data['created_at'] = date('c');

        $this->firebase->set($this->_vendors($id), $data);
        $this->json_success(['id' => $id, 'message' => 'Vendor saved.']);
    }

    public function delete_vendor()
    {
        $this->_require_role(self::MANAGE_ROLES);
        $id = $this->safe_path_segment(trim($this->input->post('id') ?? ''), 'vendor_id');

        // OPS-4 FIX: Check for purchases linked to this vendor
        $purchases = $this->firebase->get($this->_purchases());
        if (is_array($purchases)) {
            foreach ($purchases as $po) {
                if (($po['vendor_id'] ?? '') === $id) {
                    return $this->json_error('Cannot delete: vendor has purchase records. Remove or reassign purchases first.');
                }
            }
        }

        $this->firebase->delete($this->_vendors(), $id);
        $this->json_success(['message' => 'Vendor deleted.']);
    }

    // ====================================================================
    //  PURCHASES
    // ====================================================================

    /** GET — List purchases. ?page=1&limit=50 for pagination */
    public function get_purchases()
    {
        $this->_require_role(self::VIEW_ROLES);
        $purchases = $this->firebase->get($this->_purchases());
        $list = [];
        if (is_array($purchases)) {
            foreach ($purchases as $id => $p) { $p['id'] = $id; $list[] = $p; }
        }
        usort($list, function ($a, $b) {
            return strcmp($b['date'] ?? '', $a['date'] ?? '');
        });
        $this->json_success($this->operations_accounting->paginate(
            $list, 'purchases',
            $this->input->get('page'),
            (int) ($this->input->get('limit') ?? 50)
        ));
    }

    /** POST — Record a purchase. Updates stock and creates accounting journal. */
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

        // Verify item
        $item = $this->firebase->get($this->_items($itemId));
        if (!is_array($item)) $this->json_error('Item not found.');

        $total = round($qty * $unitPrice, 2);
        $poId = $this->operations_accounting->next_id($this->_counters('Purchase'), 'PO');

        // Validate accounting accounts before journal
        $cashAcct = ($paymentMode === 'Bank') ? '1020' : '1010';
        $this->operations_accounting->validate_accounts(['1060', $cashAcct]);

        // M-02 FIX: Wrap multi-write operation in try/catch with defined order:
        // 1. Purchase record (least impact if orphaned)
        // 2. Journal entry (financial — only after purchase exists)
        // 3. Stock update (last — only if journal succeeds)
        try {
            // Get vendor name
            $vendorName = '';
            if ($vendorId !== '') {
                $vendor = $this->firebase->get($this->_vendors($vendorId));
                $vendorName = is_array($vendor) ? ($vendor['name'] ?? '') : '';
            }

            // Step 1: Write purchase record FIRST (references journal_id later)
            $purchaseData = [
                'item_id'      => $itemId,
                'item_name'    => $item['name'] ?? '',
                'vendor_id'    => $vendorId,
                'vendor_name'  => $vendorName,
                'qty'          => $qty,
                'unit_price'   => $unitPrice,
                'total'        => $total,
                'date'         => $date,
                'invoice_no'   => $invoiceNo,
                'payment_mode' => $paymentMode,
                'journal_id'   => '',   // placeholder — updated after journal
                'notes'        => $notes,
                'status'       => 'Pending',  // not Completed until stock is updated
                'created_by'   => $this->admin_name,
                'created_at'   => date('c'),
            ];
            $this->firebase->set($this->_purchases($poId), $purchaseData);

            // Step 2: Create journal entry
            $narration = "Inventory purchase: {$item['name']} x{$qty} - Invoice: {$invoiceNo}";
            $journalId = $this->operations_accounting->create_journal($narration, [
                ['account_code' => '1060',    'dr' => $total, 'cr' => 0],
                ['account_code' => $cashAcct, 'dr' => 0,      'cr' => $total],
            ], 'Inventory', $poId);

            // Link journal to purchase record
            $this->firebase->update($this->_purchases($poId), ['journal_id' => $journalId]);

            // Step 3: Update stock and mark purchase complete
            // OPS-3 FIX: Re-read current stock just before update to minimize race window
            $freshStock = (int) ($this->firebase->get($this->_items($itemId) . '/current_stock') ?? 0);
            $newStock = $freshStock + $qty;
            $this->firebase->set($this->_items($itemId) . '/current_stock', $newStock);

            // Verify the write
            $verified = (int) ($this->firebase->get($this->_items($itemId) . '/current_stock') ?? 0);
            if ($verified !== $newStock) {
                // Another writer conflicted — check if our write already applied
                $retryStock = (int) ($this->firebase->get($this->_items($itemId) . '/current_stock') ?? 0);
                if ($retryStock < $freshStock + $qty) {
                    // Our addition was lost or partially applied — re-apply on fresh value
                    $newStock = $retryStock + $qty;
                    $this->firebase->set($this->_items($itemId) . '/current_stock', $newStock);
                }
                // else: fresh stock already includes our addition — no action needed
            }

            $this->firebase->update($this->_purchases($poId), ['status' => 'Completed']);

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

    /** GET — List stock issues. ?page=1&limit=50 for pagination */
    public function get_issues()
    {
        $this->_require_role(self::VIEW_ROLES);
        $issues = $this->firebase->get($this->_issues());
        $list = [];
        if (is_array($issues)) {
            foreach ($issues as $id => $iss) { $iss['id'] = $id; $list[] = $iss; }
        }
        usort($list, function ($a, $b) {
            return strcmp($b['date'] ?? '', $a['date'] ?? '');
        });
        $this->json_success($this->operations_accounting->paginate(
            $list, 'issues',
            $this->input->get('page'),
            (int) ($this->input->get('limit') ?? 50)
        ));
    }

    /** POST — Issue stock to staff/department. */
    public function save_issue()
    {
        $this->_require_role(self::MANAGE_ROLES);
        $itemId   = $this->safe_path_segment(trim($this->input->post('item_id') ?? ''), 'item_id');
        $issuedTo = trim($this->input->post('issued_to') ?? '');
        $qty      = max(1, (int) ($this->input->post('qty') ?? 1));
        $purpose  = trim($this->input->post('purpose') ?? '');

        if ($issuedTo === '') $this->json_error('Issued-to (person/department) is required.');

        $item = $this->firebase->get($this->_items($itemId));
        if (!is_array($item)) $this->json_error('Item not found.');
        if ((int) ($item['current_stock'] ?? 0) < $qty) {
            $this->json_error("Insufficient stock. Available: {$item['current_stock']}.");
        }

        $issId = $this->operations_accounting->next_id($this->_counters('StockIssue'), 'ISI');

        $issueData = [
            'item_id'     => $itemId,
            'item_name'   => $item['name'] ?? '',
            'issued_to'   => $issuedTo,
            'issued_by'   => $this->admin_name,
            'qty'         => $qty,
            'purpose'     => $purpose,
            'date'        => date('Y-m-d'),
            'return_date' => '',
            'return_qty'  => 0,
            'status'      => 'Issued',
            'created_at'  => date('c'),
        ];

        // M-02 FIX: Wrap issue + stock decrement in try/catch
        try {
            $this->firebase->set($this->_issues($issId), $issueData);

            // OPS-3 FIX: Re-read current stock to minimize race window
            $freshStock = (int) ($this->firebase->get($this->_items($itemId) . '/current_stock') ?? 0);
            if ($freshStock < $qty) {
                $this->json_error("Insufficient stock. Available: {$freshStock}.");
            }
            $newStock = $freshStock - $qty;
            $this->firebase->set($this->_items($itemId) . '/current_stock', $newStock);

            // Verify the write
            $verified = (int) ($this->firebase->get($this->_items($itemId) . '/current_stock') ?? 0);
            if ($verified !== $newStock) {
                // Another writer conflicted — check if our write already applied
                $retryStock = (int) ($this->firebase->get($this->_items($itemId) . '/current_stock') ?? 0);
                if ($retryStock > $freshStock - $qty) {
                    // Our subtraction was lost or partially applied — re-apply on fresh value
                    if ($retryStock < $qty) {
                        $this->json_error("Insufficient stock after conflict. Available: {$retryStock}.");
                    }
                    $newStock = $retryStock - $qty;
                    $this->firebase->set($this->_items($itemId) . '/current_stock', $newStock);
                }
                // else: fresh stock already reflects our subtraction — no action needed
            }

            $this->json_success(['id' => $issId, 'message' => "Issued {$qty} {$item['unit']} of {$item['name']}."]);
        } catch (Exception $e) {
            log_message('error', "Inventory::save_issue — partial write failure: {$e->getMessage()} [ISI={$issId}]");
            $this->json_error('Stock issue recording failed. Check issue ' . $issId . ' for partial data.');
        }
    }

    /** POST — Return issued stock. */
    public function return_issue()
    {
        $this->_require_role(self::MANAGE_ROLES);
        $issueId  = $this->safe_path_segment(trim($this->input->post('issue_id') ?? ''), 'issue_id');
        $returnQty = max(1, (int) ($this->input->post('return_qty') ?? 0));

        $issue = $this->firebase->get($this->_issues($issueId));
        if (!is_array($issue)) $this->json_error('Issue record not found.');
        if (($issue['status'] ?? '') !== 'Issued') $this->json_error('Stock is not currently issued.');

        $issuedQty    = (int) ($issue['qty'] ?? 0);
        $alreadyReturned = (int) ($issue['return_qty'] ?? 0);
        if ($returnQty > ($issuedQty - $alreadyReturned)) {
            $this->json_error('Return quantity exceeds issued amount.');
        }

        $totalReturned = $alreadyReturned + $returnQty;
        $newStatus = ($totalReturned >= $issuedQty) ? 'Returned' : 'Issued';

        // M-02 FIX: Wrap return + stock increment in try/catch
        try {
            $this->firebase->update($this->_issues($issueId), [
                'return_qty'  => $totalReturned,
                'return_date' => date('Y-m-d'),
                'status'      => $newStatus,
                'updated_at'  => date('c'),
            ]);

            // Increment stock
            $itemId = $issue['item_id'] ?? '';
            if ($itemId !== '') {
                $item = $this->firebase->get($this->_items($itemId));
                if (is_array($item)) {
                    $this->firebase->set($this->_items($itemId) . '/current_stock', (int) ($item['current_stock'] ?? 0) + $returnQty);
                }
            }

            $this->json_success(['message' => "{$returnQty} returned. Status: {$newStatus}."]);
        } catch (Exception $e) {
            log_message('error', "Inventory::return_issue — partial write failure: {$e->getMessage()} [ISI={$issueId}]");
            $this->json_error('Return recording failed. Check issue ' . $issueId . ' for partial data.');
        }
    }

    /** GET — Stock report: items with stock levels, low-stock alerts. */
    public function get_stock_report()
    {
        $this->_require_role(self::VIEW_ROLES);
        $items = $this->firebase->get($this->_items()) ?? [];
        if (!is_array($items)) $items = [];

        $report = [];
        foreach ($items as $id => $it) {
            $stock = (int) ($it['current_stock'] ?? 0);
            $min   = (int) ($it['min_stock'] ?? 0);
            $report[] = [
                'id'       => $id,
                'name'     => $it['name'] ?? '',
                'category' => $it['category_id'] ?? '',
                'unit'     => $it['unit'] ?? '',
                'stock'    => $stock,
                'min'      => $min,
                'low'      => $stock <= $min,
                'status'   => $it['status'] ?? '',
            ];
        }

        $this->json_success(['stock_report' => $report]);
    }
}
