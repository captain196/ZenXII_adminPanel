<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Fee_management Controller
 *
 * Advanced fee management module: categories, discount policies, scholarships,
 * refunds, fee reminders, payment gateway config, and online payments.
 *
 * Extends MY_Controller which provides:
 *   $this->school_name, $this->school_id, $this->session_year,
 *   $this->firebase, $this->CM, json_success(), json_error()
 */
class Fee_management extends MY_Controller
{
    /** Roles for admin-level config (gateway, refund approval) */
    private const ADMIN_ROLES   = ['Super Admin', 'School Super Admin', 'Admin', 'Principal'];

    /** Roles for financial operations (categories, discounts, scholarships) */
    private const FINANCE_ROLES = ['Super Admin', 'School Super Admin', 'Admin', 'Principal', 'Accountant'];

    /** Roles that may view fee management data */
    private const VIEW_ROLES    = ['Super Admin', 'School Super Admin', 'Admin', 'Principal', 'Accountant', 'Teacher'];

    /** @var string Base Firebase path for fees */
    private $feesBase;

    /** @var string Session root path */
    private $sessionRoot;

    /** @var array|null Firebase ID-token claims for parent-app API calls */
    private $_parent_claims = null;

    /** @var string|null Cached raw body for webhook — avoids a second
     *  file_get_contents('php://input') call later in payment_webhook(),
     *  since the stream is not guaranteed to be rewindable on every PHP
     *  SAPI configuration. Populated by the constructor when the request
     *  is a webhook; null otherwise. */
    private $_webhookRawBody = null;

    public function __construct()
    {
        $uri = $_SERVER['REQUEST_URI'] ?? '';

        // Webhook is a server-to-server call — no session required.
        // It authenticates via HMAC signature instead.
        $isWebhook = (strpos($uri, 'payment_webhook') !== false);

        // Parent-app endpoints authenticate via Firebase ID token (Bearer).
        // School context is resolved from the token claims.
        $isParentApi = (
            strpos($uri, 'fee_management/parent_create_order')   !== false ||
            strpos($uri, 'fee_management/parent_verify_payment') !== false ||
            strpos($uri, 'fee_management/parent_pay_from_wallet') !== false
        );

        if ($isWebhook) {
            // Skip MY_Controller auth — use CI_Controller directly.
            // Razorpay webhooks are stateless (no session cookie), so we
            // CANNOT rely on $this->session->userdata() to resolve the
            // school — the earlier implementation did, and it would reject
            // every real Razorpay delivery with HTTP 400. Instead we peek
            // at the incoming payload for the order_id and look it up in
            // feeOnlineOrders (whose docs carry schoolId + session as
            // top-level fields — see Payment_service::create_order).
            CI_Controller::__construct();
            $this->load->library('firebase');

            // Cache the raw body here so payment_webhook() does not have
            // to re-read a potentially-consumed php://input stream.
            $this->_webhookRawBody = (string) file_get_contents('php://input');
            $body = json_decode($this->_webhookRawBody, true);
            if (!is_array($body)) {
                $body = $this->input->post() ?: [];
            }
            $orderId = trim((string) (
                $body['razorpay_order_id']
                ?? $body['order_id']
                ?? $body['gateway_order_id']
                ?? ''
            ));

            $ctx = $orderId !== '' ? $this->resolveSchoolFromOrder($orderId) : null;

            if (!is_array($ctx) || ($ctx['school'] ?? '') === '' || ($ctx['session'] ?? '') === '') {
                log_message('error', "[WEBHOOK CONTEXT FAILED] order=" . ($orderId !== '' ? $orderId : '(missing)'));
                header('Content-Type: application/json');
                http_response_code(400);
                echo json_encode(['status' => 'error', 'message' => 'Invalid order context']);
                exit;
            }

            $this->school_name  = $ctx['school'];
            $this->session_year = $ctx['session'];
            log_message('debug', "[WEBHOOK CONTEXT RESOLVED] school={$this->school_name} session={$this->session_year} order={$orderId}");
        } else if ($isParentApi) {
            // Parent-app API — verify Firebase ID token, derive school context
            CI_Controller::__construct();
            $this->load->library('firebase');
            $this->load->library('api_auth');

            $claims = $this->api_auth->require_auth();
            $role   = strtolower(trim((string)($claims['role'] ?? '')));
            if (!in_array($role, ['parent', 'student'], true)) {
                header('Content-Type: application/json');
                http_response_code(403);
                echo json_encode(['success' => false, 'error' => 'Parent/student role required.']);
                exit;
            }

            $this->school_name  = (string) ($claims['school_id'] ?? '');
            $this->session_year = $this->_resolve_active_session($this->school_name);
            $this->admin_id     = 'parent_app:' . ($claims['uid'] ?? 'unknown');
            // Save claims for handler methods
            $this->_parent_claims = $claims;

            if ($this->school_name === '' || $this->session_year === '') {
                header('Content-Type: application/json');
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'Unable to resolve school/session from token.']);
                exit;
            }
        } else {
            parent::__construct();
            require_permission('Fees');
        }

        $sn = $this->school_name;
        $sy = $this->session_year;
        $this->feesBase    = "Schools/$sn/$sy/Accounts/Fees";
        $this->sessionRoot = "Schools/$sn/$sy";

        $this->load->library('Fee_integrity_checker', null, 'feeIntegrity');
        $this->load->library('Fee_defaulter_check', null, 'feeDefaulter');
        $this->feeIntegrity->init($this->firebase, $this->school_name, $this->session_year);
        $this->feeDefaulter->init($this->firebase, $this->school_name, $this->session_year);

        // Firestore dual-write sync (best-effort)
        $this->load->library('Fee_firestore_sync', null, 'fsSync');
        $this->fsSync->init($this->firebase, $this->school_name, $this->session_year);
    }

    // ══════════════════════════════════════════════════════════════════
    //  PRIVATE HELPERS

    /** Safe trim — never passes null to trim() (PHP 8.1+ compat) */
    private function _post(string $key): string
    {
        return trim((string)($this->input->post($key) ?? ''));
    }

    /** Strip the `{schoolId}_` prefix from a Firestore doc ID to get the entity ID. */
    private function _stripSchoolPrefix(string $docId): string
    {
        $prefix = $this->school_name . '_';
        if (strpos($docId, $prefix) === 0) {
            return substr($docId, strlen($prefix));
        }
        return $docId;
    }

    /**
     * Convert a demand `period` string to the canonical `monthFee` map
     * key — strip trailing year/session tokens but PRESERVE multi-word
     * labels like "Yearly Fees".
     *
     * Examples:
     *   "April 2026"              -> "April"
     *   "Yearly Fees 2026-27"     -> "Yearly Fees"
     *   "Apr-2026"                -> "Apr-2026"  (no trailing year token to strip)
     *
     * Without this helper the previous `explode(' ', $period)[0]` logic
     * dropped the "Fees" suffix on annual demands, producing a "Yearly"
     * key that conflicted with the existing "Yearly Fees" key in
     * students.monthFee — leaving two contradictory entries for the
     * same period.
     */
    private function _periodToMonthFeeKey(string $period): string
    {
        $period = trim($period);
        if ($period === '') return '';
        // Strip trailing 4-digit year (e.g. "April 2026") OR
        // YYYY-YY session range (e.g. "Yearly Fees 2026-27").
        $stripped = preg_replace('/\s+\d{4}(-\d{2,4})?$/', '', $period);
        return is_string($stripped) ? trim($stripped) : $period;
    }
    // ══════════════════════════════════════════════════════════════════

    /**
     * Get existing fee structure (Monthly + Yearly titles).
     * Firestore: aggregate from feeStructures collection, grouped by type.
     */
    private function _getFeesStructure()
    {
        $result = ['Monthly' => [], 'Yearly' => []];
        try {
            $rows = $this->firebase->firestoreQuery('feeStructures', [
                ['schoolId', '==', $this->school_name],
                ['session',  '==', $this->session_year],
            ]);
            foreach ((array) $rows as $row) {
                $d = $row['data'] ?? $row;
                $heads = is_array($d['feeHeads'] ?? null) ? $d['feeHeads'] : [];
                foreach ($heads as $h) {
                    $name = trim((string) ($h['name'] ?? ''));
                    $type = ($h['type'] ?? 'Monthly') === 'Yearly' ? 'Yearly' : 'Monthly';
                    if ($name !== '' && !isset($result[$type][$name])) {
                        $result[$type][$name] = '';
                    }
                }
            }
        } catch (\Exception $e) {
            log_message('error', 'Fee_management::_getFeesStructure Firestore query failed: ' . $e->getMessage());
        }
        return $result;
    }

    /**
     * Get all distinct fee title names as a flat array.
     *
     * Pure Firestore: aggregate from feeStructures collection (Session A
     * source of truth). Falls back to the legacy RTDB Fees Structure
     * node only if the Firestore collection hasn't been seeded yet, so
     * existing admin screens keep working during the migration window.
     */
    private function _getAllFeeTitles()
    {
        $titles = [];
        $seen   = [];
        try {
            $rows = $this->firebase->firestoreQuery('feeStructures', [
                ['schoolId', '==', $this->school_name],
                ['session',  '==', $this->session_year],
            ]);
            foreach ((array) $rows as $row) {
                $d = $row['data'] ?? $row;
                $heads = is_array($d['feeHeads'] ?? null) ? $d['feeHeads'] : [];
                foreach ($heads as $h) {
                    $name = trim((string) ($h['name'] ?? ''));
                    if ($name === '' || isset($seen[$name])) continue;
                    $seen[$name] = true;
                    $titles[] = $name;
                }
            }
        } catch (\Exception $e) {
            log_message('error', 'Fee_management::_getAllFeeTitles Firestore query failed: ' . $e->getMessage());
        }
        return $titles;
    }

    /**
     * Parse class string into [classNode, sectionNode].
     * Input: "Class 9th" + "Section A" or "9th" + "A"
     */
    private function _normalizeClassSection($class, $section)
    {
        $class = trim($class);
        if (stripos($class, 'Class ') !== 0) {
            $class = 'Class ' . $class;
        }

        $section = trim($section);
        if (stripos($section, 'Section ') !== 0) {
            $section = 'Section ' . strtoupper($section);
        }

        // Reject path-injection characters (., /, #, $, [, ])
        if (preg_match('/[.\/\#\$\[\]]/', $class . $section)) {
            $this->json_error('Invalid class/section value.');
        }

        return [$class, $section];
    }

    /**
     * Get all classes and sections from Firestore sections collection.
     * Returns: [['class' => 'Class 9th', 'section' => 'Section A'], ...]
     */
    private function _getAllClassSections()
    {
        $result = [];
        try {
            $rows = $this->firebase->firestoreQuery('sections', [
                ['schoolId', '==', $this->school_name],
                ['session',  '==', $this->session_year],
            ]);
            foreach ((array) $rows as $row) {
                $d = $row['data'] ?? $row;
                $cls = trim((string) ($d['className'] ?? ''));
                $sec = trim((string) ($d['section']   ?? ''));
                if ($cls === '' || $sec === '') continue;
                // Ensure prefixed format
                if (stripos($cls, 'Class ') !== 0) $cls = 'Class ' . $cls;
                if (stripos($sec, 'Section ') !== 0) $sec = 'Section ' . $sec;
                $result[] = [
                    'class'   => $cls,
                    'section' => $sec,
                ];
            }
        } catch (\Exception $e) {
            log_message('error', 'Fee_management::_getAllClassSections Firestore query failed: ' . $e->getMessage());
        }
        return $result;
    }

    /**
     * Atomically increment the receipt counter with retry logic.
     * Firestore: uses feeCounters collection with optimistic check.
     * Returns the new receipt number and key string.
     */
    private function _nextReceiptNo()
    {
        $schoolFs = $this->school_name;
        $docId    = "{$schoolFs}_receiptNo";
        $maxRetries = 5;

        for ($i = 0; $i < $maxRetries; $i++) {
            $doc = $this->firebase->firestoreGet('feeCounters', $docId);
            $current = is_array($doc) ? (int) ($doc['value'] ?? 0) : 0;
            $next = $current + 1;
            $this->firebase->firestoreSet('feeCounters', $docId, [
                'schoolId' => $schoolFs,
                'kind'     => 'receiptNo',
                'value'    => $next,
                'updatedAt' => date('c'),
            ]);
            // Verify it was set correctly (optimistic check)
            $verify = $this->firebase->firestoreGet('feeCounters', $docId);
            if (is_array($verify) && (int) ($verify['value'] ?? 0) === $next) {
                return [
                    'number' => $next,
                    'key'    => 'F' . str_pad($next, 6, '0', STR_PAD_LEFT),
                ];
            }
            usleep(50000 * ($i + 1));
        }
        // Fallback: use timestamp-based unique key
        $fallback = (int)(microtime(true) * 1000) % 999999;
        $this->firebase->firestoreSet('feeCounters', $docId, [
            'schoolId' => $schoolFs,
            'kind'     => 'receiptNo',
            'value'    => $fallback,
            'updatedAt' => date('c'),
        ]);
        return [
            'number' => $fallback,
            'key'    => 'F' . str_pad($fallback, 6, '0', STR_PAD_LEFT),
        ];
    }

    // ══════════════════════════════════════════════════════════════════
    //  PAGE LOADERS (GET)
    // ══════════════════════════════════════════════════════════════════

    /**
     * Fee Categories page.
     */
    public function categories()
    {
        $this->_require_role(self::VIEW_ROLES, 'fee_mgmt_view');
        $data = [];
        $data['feesStructure'] = $this->_getFeesStructure();
        $data['page_title']    = 'Fee Titles & Categories';

        $this->load->view('include/header', $data);
        $this->load->view('fee_management/categories', $data);
        $this->load->view('include/footer');
    }

    /**
     * Discount Policies page.
     */
    public function discounts()
    {
        $this->_require_role(self::VIEW_ROLES, 'fee_mgmt_view');
        $data = [];
        $catRows = $this->firebase->firestoreQuery('feeCategories', [
            ['schoolId', '==', $this->school_name],
        ]);
        $cats = [];
        foreach ((array) $catRows as $row) {
            $d = $row['data'] ?? $row;
            $id = $this->_stripSchoolPrefix($row['id'] ?? '');
            $d['id'] = $id;
            $cats[$id] = $d;
        }
        $data['categories']    = $cats;
        $data['feesStructure'] = $this->_getFeesStructure();
        $data['page_title']    = 'Discount Policies';

        $this->load->view('include/header', $data);
        $this->load->view('fee_management/discounts', $data);
        $this->load->view('include/footer');
    }

    /**
     * Scholarships page.
     */
    public function scholarships()
    {
        $this->_require_role(self::VIEW_ROLES, 'fee_mgmt_view');
        $data = [];
        $data['page_title'] = 'Scholarships';

        $this->load->view('include/header', $data);
        $this->load->view('fee_management/scholarships', $data);
        $this->load->view('include/footer');
    }

    /**
     * Refunds page.
     */
    public function refunds()
    {
        $this->_require_role(self::VIEW_ROLES, 'fee_mgmt_view');
        $data = [];
        $data['fee_titles']  = $this->_getAllFeeTitles();
        $data['page_title']  = 'Fee Refunds';

        $this->load->view('include/header', $data);
        $this->load->view('fee_management/refunds', $data);
        $this->load->view('include/footer');
    }

    /**
     * Fee Reminders page.
     */
    public function reminders()
    {
        $this->_require_role(self::VIEW_ROLES, 'fee_mgmt_view');
        $data = [];
        $settingsDoc = $this->firebase->firestoreGet('feeSettings', "{$this->school_name}_{$this->session_year}_reminders");
        $data['settings']   = is_array($settingsDoc) ? $settingsDoc : [];
        $data['page_title'] = 'Fee Reminders';

        $this->load->view('include/header', $data);
        $this->load->view('fee_management/reminders', $data);
        $this->load->view('include/footer');
    }

    /**
     * Payment Gateway Configuration page.
     */
    public function gateway()
    {
        $this->_require_role(self::VIEW_ROLES, 'fee_mgmt_view');
        $data = [];
        $config = $this->firebase->firestoreGet('feeSettings', "{$this->school_name}_{$this->session_year}_gateway");
        if (is_array($config)) {
            // Mask secrets for display
            if (!empty($config['api_secret'])) {
                $config['api_secret_masked'] = str_repeat('*', max(0, strlen($config['api_secret']) - 4))
                    . substr($config['api_secret'], -4);
            }
            if (!empty($config['webhook_secret'])) {
                $config['webhook_secret_masked'] = str_repeat('*', max(0, strlen($config['webhook_secret']) - 4))
                    . substr($config['webhook_secret'], -4);
            }
        }
        $data['config']     = is_array($config) ? $config : [];
        $data['page_title'] = 'Payment Gateway';

        $this->load->view('include/header', $data);
        $this->load->view('fee_management/gateway', $data);
        $this->load->view('include/footer');
    }

    /**
     * Online Payments listing page.
     */
    public function online_payments()
    {
        $this->_require_role(self::VIEW_ROLES, 'fee_mgmt_view');
        $data = [];
        $config = $this->firebase->firestoreGet('feeSettings', "{$this->school_name}_{$this->session_year}_gateway");
        $data['gateway_mode'] = is_array($config) && isset($config['mode']) ? $config['mode'] : '';
        $data['page_title']   = 'Online Payments';

        $this->load->view('include/header', $data);
        $this->load->view('fee_management/online_payments', $data);
        $this->load->view('include/footer');
    }

    // ══════════════════════════════════════════════════════════════════
    //  FEE TITLES (AJAX) — manage Fees Structure/{Monthly|Yearly}
    // ══════════════════════════════════════════════════════════════════

    /**
     * GET — Fetch all fee titles (Monthly + Yearly).
     */
    public function fetch_fee_titles()
    {
        $this->_require_role(self::VIEW_ROLES, 'fetch_fee_titles');
        $structure = $this->_getFeesStructure();
        $titles = [];
        foreach (['Monthly', 'Yearly'] as $type) {
            if (!empty($structure[$type]) && is_array($structure[$type])) {
                foreach (array_keys($structure[$type]) as $title) {
                    $titles[] = ['title' => $title, 'type' => $type];
                }
            }
        }
        $this->json_success(['titles' => $titles]);
    }

    /**
     * POST — Add a new fee title.
     * Params: fee_title, fee_type (Monthly|Yearly)
     */
    public function save_fee_title()
    {
        $this->_require_role(self::FINANCE_ROLES, 'save_fee_title');
        $feeTitle = trim(ucwords(strtolower($this->_post('fee_title'))));
        $feeType  = $this->_post('fee_type');

        if ($feeTitle === '') {
            $this->json_error('Fee title is required.');
        }
        if (!in_array($feeType, ['Monthly', 'Yearly'], true)) {
            $this->json_error('Fee type must be Monthly or Yearly.');
        }

        // Check duplicate via Firestore
        $structure = $this->_getFeesStructure();
        if (isset($structure[$feeType][$feeTitle])) {
            $this->json_error("Fee title \"{$feeTitle}\" already exists under {$feeType}.");
        }

        // Write a feeStructures doc to register this title globally
        $docId = "{$this->school_name}_{$this->session_year}_titles";
        $existing = $this->firebase->firestoreGet('feeStructures', $docId);
        $heads = is_array($existing) ? ($existing['feeHeads'] ?? []) : [];
        if (!is_array($heads)) $heads = [];
        $heads[] = ['name' => $feeTitle, 'type' => $feeType];
        $this->firebase->firestoreSet('feeStructures', $docId, [
            'schoolId' => $this->school_name,
            'session'  => $this->session_year,
            'feeHeads' => $heads,
            'updatedAt' => date('c'),
        ]);
        $this->json_success(['message' => "Fee title \"{$feeTitle}\" added as {$feeType}."]);
    }

    /**
     * POST — Delete a fee title.
     * Params: fee_title, fee_type (Monthly|Yearly)
     */
    public function delete_fee_title()
    {
        $this->_require_role(self::FINANCE_ROLES, 'delete_fee_title');
        $feeTitle = $this->_post('fee_title');
        $feeType  = $this->_post('fee_type');

        if ($feeTitle === '' || $feeType === '') {
            $this->json_error('Fee title and type are required.');
        }
        if (!in_array($feeType, ['Monthly', 'Yearly'], true)) {
            $this->json_error('Fee type must be Monthly or Yearly.');
        }

        // Remove from Firestore feeStructures titles doc
        $docId = "{$this->school_name}_{$this->session_year}_titles";
        $existing = $this->firebase->firestoreGet('feeStructures', $docId);
        $heads = is_array($existing) ? ($existing['feeHeads'] ?? []) : [];
        if (!is_array($heads)) $heads = [];
        $heads = array_values(array_filter($heads, function ($h) use ($feeTitle, $feeType) {
            return !(($h['name'] ?? '') === $feeTitle && ($h['type'] ?? '') === $feeType);
        }));
        $this->firebase->firestoreSet('feeStructures', $docId, [
            'schoolId' => $this->school_name,
            'session'  => $this->session_year,
            'feeHeads' => $heads,
            'updatedAt' => date('c'),
        ]);
        $this->json_success(['message' => "Fee title \"{$feeTitle}\" deleted."]);
    }

    // ══════════════════════════════════════════════════════════════════
    //  CATEGORY MANAGEMENT (AJAX)
    // ══════════════════════════════════════════════════════════════════

    /**
     * GET — Fetch all fee categories.
     */
    public function fetch_categories()
    {
        $this->_require_role(self::VIEW_ROLES, 'fetch_categories');
        $rows = $this->firebase->firestoreQuery('feeCategories', [
            ['schoolId', '==', $this->school_name],
        ]);
        $categories = [];
        foreach ((array) $rows as $row) {
            $cat = $row['data'] ?? $row;
            if (!is_array($cat)) continue;
            $cat['id'] = $this->_stripSchoolPrefix($row['id'] ?? '');
            $categories[] = $cat;
        }
        // Sort by sort_order
        usort($categories, function ($a, $b) {
            return ((int)($a['sort_order'] ?? 999)) - ((int)($b['sort_order'] ?? 999));
        });
        $this->json_success(['categories' => $categories]);
    }

    /**
     * POST — Create or update a fee category.
     * Params: id?, name, description, type, fee_titles (comma-sep), sort_order
     */
    public function save_category()
    {
        $this->_require_role(self::FINANCE_ROLES, 'save_category');
        // Accept both naming conventions (category_name OR name, etc.)
        $name        = $this->_post('category_name') ?: $this->_post('name');
        $description = $this->_post('description');
        $type        = $this->_post('category_type') ?: $this->_post('type');
        $sortOrder   = (int)$this->input->post('sort_order');
        $catId       = $this->_post('category_id') ?: $this->_post('id');

        if ($name === '') {
            $this->json_error('Category name is required.');
        }

        $validTypes = ['Academic', 'Transport', 'Extra-curricular', 'Other'];
        if (!in_array($type, $validTypes, true)) {
            $this->json_error('Invalid category type.');
        }

        // Accept fee_titles as array (from FormData) or comma-sep string
        $feeTitles = $this->input->post('fee_titles[]') ?: $this->input->post('fee_titles');
        $titlesArray = [];
        if (is_array($feeTitles)) {
            $titlesArray = array_values(array_filter(array_map('trim', $feeTitles)));
        } elseif (is_string($feeTitles) && trim($feeTitles) !== '') {
            $titlesArray = array_values(array_filter(array_map('trim', explode(',', $feeTitles))));
        }

        $now = date('Y-m-d H:i:s');

        $data = [
            'category_name' => $name,
            'description'   => $description,
            'category_type' => $type,
            'fee_titles'    => $titlesArray,
            'sort_order'    => $sortOrder,
            'status'        => 'active',
        ];

        $data['schoolId'] = $this->school_name;

        if (!empty($catId)) {
            // Update existing
            $catId = $this->safe_path_segment($catId, 'category_id');
            $data['updated_at'] = $now;
            $this->firebase->firestoreSet('feeCategories', "{$this->school_name}_{$catId}", $data, true);
            $this->json_success(['message' => 'Category updated successfully.', 'id' => $catId]);
        } else {
            // Create new
            $data['created_at'] = $now;
            $newId = uniqid('cat_');
            $this->firebase->firestoreSet('feeCategories', "{$this->school_name}_{$newId}", $data);
            $this->json_success(['message' => 'Category created successfully.', 'id' => $newId]);
        }
    }

    /**
     * POST — Delete a fee category.
     * Params: category_id
     */
    public function delete_category()
    {
        $this->_require_role(self::FINANCE_ROLES, 'delete_category');
        $catId = $this->safe_path_segment(trim($this->input->post('category_id') ?? ''), 'category_id');

        // Verify it exists
        $existing = $this->firebase->firestoreGet('feeCategories', "{$this->school_name}_{$catId}");
        if (empty($existing)) {
            $this->json_error('Category not found.');
        }

        $this->firebase->firestoreDelete('feeCategories', "{$this->school_name}_{$catId}");
        $this->json_success(['message' => 'Category deleted successfully.']);
    }

    // ══════════════════════════════════════════════════════════════════
    //  DISCOUNT POLICIES (AJAX)
    // ══════════════════════════════════════════════════════════════════

    /**
     * GET — Fetch all discount policies.
     */
    public function fetch_discounts()
    {
        $this->_require_role(self::VIEW_ROLES, 'fetch_discounts');
        $rows = $this->firebase->firestoreQuery('feeDiscountPolicies', [
            ['schoolId', '==', $this->school_name],
        ]);
        $discounts = [];
        foreach ((array) $rows as $row) {
            $disc = $row['data'] ?? $row;
            if (!is_array($disc)) continue;
            $disc['id'] = $this->_stripSchoolPrefix($row['id'] ?? '');
            $discounts[] = $disc;
        }
        $this->json_success(['discounts' => $discounts]);
    }

    /**
     * POST — Create or update a discount policy.
     * Params: id?, name, type, value, criteria, applicable_categories, applicable_titles, max_discount, active
     */
    public function save_discount()
    {
        $this->_require_role(self::FINANCE_ROLES, 'save_discount');

        // SCHEMA NOTE — the JS form posts these field names:
        //   policy_name, discount_type, value, criteria, max_cap, is_active,
        //   categories[], fee_titles[]
        // Older callers (legacy) post:
        //   name, type, value, criteria, max_discount, active,
        //   applicable_categories, applicable_titles
        // We accept BOTH so neither caller breaks.
        $name       = $this->_post('policy_name');
        if ($name === '') $name = $this->_post('name');

        $type       = $this->_post('discount_type');
        if ($type === '') $type = $this->_post('type');

        $value      = floatval($this->input->post('value'));
        $criteria   = $this->_post('criteria');

        $maxRaw     = $this->input->post('max_cap');
        if ($maxRaw === null || $maxRaw === '') $maxRaw = $this->input->post('max_discount');
        $maxDisc    = floatval($maxRaw);

        $activeRaw  = $this->input->post('is_active');
        if ($activeRaw === null) $activeRaw = $this->input->post('active');
        $active     = !($activeRaw === 'false' || $activeRaw === '0' || $activeRaw === 0 || $activeRaw === false);

        $discId     = $this->_post('id');

        if ($name === '') {
            $this->json_error('Discount policy name is required.');
        }
        if (!in_array($type, ['percentage', 'fixed'], true)) {
            $this->json_error('Discount type must be "percentage" or "fixed".');
        }
        if ($value <= 0) {
            $this->json_error('Discount value must be greater than zero.');
        }

        $validCriteria = ['sibling', 'early_bird', 'merit', 'staff_ward', 'custom'];
        if (!in_array($criteria, $validCriteria, true)) {
            $this->json_error('Invalid criteria. Must be one of: ' . implode(', ', $validCriteria));
        }

        // Categories + fee_titles can arrive as either:
        //   - array (jQuery $.ajax with traditional:true sends categories[])
        //   - comma-separated string (legacy)
        $appCats = $this->input->post('categories');
        if ($appCats === null) $appCats = $this->input->post('applicable_categories');
        $catsArray = [];
        if (is_array($appCats)) {
            $catsArray = array_values(array_filter(array_map('trim', $appCats)));
        } elseif (is_string($appCats) && $appCats !== '') {
            $catsArray = array_values(array_filter(array_map('trim', explode(',', $appCats))));
        }

        $appTitles = $this->input->post('fee_titles');
        if ($appTitles === null) $appTitles = $this->input->post('applicable_titles');
        $titlesArray = [];
        if (is_array($appTitles)) {
            $titlesArray = array_values(array_filter(array_map('trim', $appTitles)));
        } elseif (is_string($appTitles) && $appTitles !== '') {
            $titlesArray = array_values(array_filter(array_map('trim', explode(',', $appTitles))));
        }

        $now = date('Y-m-d H:i:s');

        // Dual-emit: write BOTH naming schemes so old AND new readers
        // (list renderer, edit prefill, apply_discount, scholarship audit)
        // all see the same data without per-reader migration.
        $data = [
            // New schema (matches JS list renderer + edit form)
            'policy_name'           => $name,
            'discount_type'         => $type,
            'max_cap'               => $maxDisc,
            'is_active'             => $active,
            'categories'            => $catsArray,
            'fee_titles'            => $titlesArray,
            // Legacy schema (older readers / apply_discount + reports)
            'name'                  => $name,
            'type'                  => $type,
            'max_discount'          => $maxDisc,
            'active'                => $active,
            'applicable_categories' => $catsArray,
            'applicable_titles'     => $titlesArray,
            // Common
            'value'                 => $value,
            'criteria'              => $criteria,
        ];

        $data['schoolId'] = $this->school_name;

        if (!empty($discId)) {
            $discId = $this->safe_path_segment($discId, 'discount_id');
            $data['updated_at'] = $now;
            $this->firebase->firestoreSet('feeDiscountPolicies', "{$this->school_name}_{$discId}", $data, true);
            $this->json_success(['message' => 'Discount policy updated successfully.', 'id' => $discId]);
        } else {
            $data['created_at'] = $now;
            $newId = uniqid('disc_');
            $this->firebase->firestoreSet('feeDiscountPolicies', "{$this->school_name}_{$newId}", $data);
            $this->json_success(['message' => 'Discount policy created successfully.', 'id' => $newId]);
        }
    }

    /**
     * POST — Delete a discount policy.
     * Params: discount_id
     */
    public function delete_discount()
    {
        $this->_require_role(self::FINANCE_ROLES, 'delete_discount');
        $discId = $this->safe_path_segment(trim($this->input->post('discount_id') ?? ''), 'discount_id');

        $existing = $this->firebase->firestoreGet('feeDiscountPolicies', "{$this->school_name}_{$discId}");
        if (empty($existing)) {
            $this->json_error('Discount policy not found.');
        }

        $this->firebase->firestoreDelete('feeDiscountPolicies', "{$this->school_name}_{$discId}");
        $this->json_success(['message' => 'Discount policy deleted successfully.']);
    }

    /**
     * POST — Fetch students eligible for a specific discount based on criteria.
     * Params: discount_id
     *
     * Criteria logic:
     *   sibling    — students sharing the same parent/guardian (same parent key in Users/Parents)
     *   early_bird — all students (manual selection expected)
     *   merit      — all students (manual selection expected)
     *   staff_ward — students whose parent is in the Teachers node
     *   custom     — all students (manual selection expected)
     */
    public function fetch_eligible_students()
    {
        $this->_require_role(self::FINANCE_ROLES, 'fetch_eligible');
        $discId = $this->safe_path_segment(trim($this->input->post('discount_id') ?? ''), 'discount_id');

        $policy = $this->firebase->firestoreGet('feeDiscountPolicies', "{$this->school_name}_{$discId}");
        if (!is_array($policy)) {
            $this->json_error('Discount policy not found.');
        }

        $criteria = isset($policy['criteria']) ? $policy['criteria'] : 'custom';
        $schoolFs = $this->school_name;

        // Load all students from Firestore
        $studentRows = $this->firebase->firestoreQuery('students', [
            ['schoolId', '==', $schoolFs],
        ]);
        $students = [];
        foreach ((array) $studentRows as $row) {
            $d = $row['data'] ?? $row;
            if (!is_array($d)) continue;
            $cls = trim((string) ($d['className'] ?? ''));
            $sec = trim((string) ($d['section'] ?? ''));
            if (stripos($cls, 'Class ') !== 0) $cls = 'Class ' . $cls;
            if (stripos($sec, 'Section ') !== 0) $sec = 'Section ' . $sec;
            $students[] = [
                'user_id' => (string) ($d['studentId'] ?? $this->_stripSchoolPrefix($row['id'] ?? '')),
                'name'    => (string) ($d['name'] ?? $d['studentName'] ?? ''),
                'class'   => $cls,
                'section' => $sec,
            ];
        }

        // For sibling criteria, group by parent and only include those with 2+ children
        if ($criteria === 'sibling') {
            // Build father name map from Firestore students
            $fatherMap = [];
            foreach ((array) $studentRows as $row) {
                $d = $row['data'] ?? $row;
                if (!is_array($d)) continue;
                $uid = (string) ($d['studentId'] ?? $this->_stripSchoolPrefix($row['id'] ?? ''));
                $fatherName = trim((string) ($d['fatherName'] ?? $d['Father_name'] ?? ''));
                if ($fatherName !== '') {
                    $fatherMap[$fatherName][] = $uid;
                }
            }

            // Keep only siblings (2+ children with same father)
            $siblingIds = [];
            foreach ($fatherMap as $father => $uids) {
                if (count($uids) >= 2) {
                    foreach ($uids as $uid) {
                        $siblingIds[$uid] = true;
                    }
                }
            }

            $students = array_filter($students, function ($s) use ($siblingIds) {
                return isset($siblingIds[$s['user_id']]);
            });
            $students = array_values($students);
        } elseif ($criteria === 'staff_ward') {
            // Filter to students whose parent phone matches a staff member
            $staffRows = $this->firebase->firestoreQuery('staff', [
                ['schoolId', '==', $schoolFs],
            ]);
            $staffPhones = [];
            foreach ((array) $staffRows as $row) {
                $d = $row['data'] ?? $row;
                $phone = trim((string) ($d['phone'] ?? $d['Phone'] ?? ''));
                if ($phone !== '') $staffPhones[$phone] = true;
            }

            if (!empty($staffPhones)) {
                $staffWardIds = [];
                foreach ((array) $studentRows as $row) {
                    $d = $row['data'] ?? $row;
                    if (!is_array($d)) continue;
                    $uid = (string) ($d['studentId'] ?? $this->_stripSchoolPrefix($row['id'] ?? ''));
                    $fatherPhone = trim((string) ($d['fatherPhone'] ?? $d['Father_phone'] ?? ''));
                    $motherPhone = trim((string) ($d['motherPhone'] ?? $d['Mother_phone'] ?? ''));
                    if (($fatherPhone !== '' && isset($staffPhones[$fatherPhone])) ||
                        ($motherPhone !== '' && isset($staffPhones[$motherPhone]))) {
                        $staffWardIds[$uid] = true;
                    }
                }

                $students = array_filter($students, function ($s) use ($staffWardIds) {
                    return isset($staffWardIds[$s['user_id']]);
                });
                $students = array_values($students);
            } else {
                $students = [];
            }
        }
        // For early_bird, merit, custom — return all students for manual selection

        $this->json_success([
            'students' => $students,
            'criteria' => $criteria,
            'policy'   => [
                'name'  => isset($policy['name']) ? $policy['name'] : '',
                'type'  => isset($policy['type']) ? $policy['type'] : '',
                'value' => isset($policy['value']) ? $policy['value'] : 0,
            ],
        ]);
    }

    /**
     * POST — Apply a discount to selected students.
     * Params: discount_id, student_ids[] (array of user IDs with class/section info as JSON)
     *
     * Each student_ids[] element is JSON: {"user_id":"...","class":"Class 9th","section":"Section A"}
     */
    public function apply_discount()
    {
        $this->_require_role(self::FINANCE_ROLES, 'apply_discount');
        $discId     = $this->safe_path_segment(trim($this->input->post('discount_id') ?? ''), 'discount_id');
        $studentRaw = $this->input->post('student_ids');
        // Phase 17: optional expiry. If supplied, fetch_fee_details will
        // treat the discount as 0 once today > validUntil. Empty string
        // means "no expiry" (legacy behaviour: persists forever).
        $validUntil = trim($this->input->post('valid_until') ?? '');
        if ($validUntil !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $validUntil)) {
            $this->json_error('valid_until must be YYYY-MM-DD or empty.');
        }

        if (empty($studentRaw) || !is_array($studentRaw)) {
            $this->json_error('No students selected.');
        }

        $policy = $this->firebase->firestoreGet('feeDiscountPolicies', "{$this->school_name}_{$discId}");
        if (!is_array($policy)) {
            $this->json_error('Discount policy not found.');
        }

        $discType  = isset($policy['type']) ? $policy['type'] : 'fixed';
        $discValue = isset($policy['value']) ? floatval($policy['value']) : 0;
        $discName  = isset($policy['name']) ? $policy['name'] : 'Discount';
        $maxDisc   = isset($policy['max_discount']) ? floatval($policy['max_discount']) : 0;
        $applied   = 0;
        $errors    = [];

        foreach ($studentRaw as $entry) {
            $student = is_string($entry) ? json_decode($entry, true) : $entry;
            if (!is_array($student) || empty($student['user_id'])) {
                continue;
            }

            $userId  = $student['user_id'];
            $class   = isset($student['class']) ? $student['class'] : '';
            $section = isset($student['section']) ? $student['section'] : '';

            if ($class === '' || $section === '') {
                $errors[] = "Missing class/section for student {$userId}";
                continue;
            }

            // Sanitize path segments from user-posted JSON
            list($class, $section) = $this->_normalizeClassSection($class, $section);
            $safeUserId = $this->safe_path_segment($userId, 'student_id');

            // Read existing discount data for this student from Firestore
            $discDoc = $this->firebase->firestoreGet('studentDiscounts', "{$this->school_name}_{$safeUserId}");
            $existing = is_array($discDoc) ? $discDoc : [];
            $existingAmt = floatval($existing['totalDiscount'] ?? 0);

            // Calculate discount amount
            $discountAmount = $discValue;
            if ($discType === 'percentage') {
                // For percentage, read total fee from Firestore feeStructures
                $csKey = str_ireplace(['Class ', 'Section '], '', $class) . '_' . str_ireplace('Section ', '', $section);
                $feeStructDoc = $this->firebase->firestoreGet('feeStructures', "{$this->school_name}_{$this->session_year}_{$csKey}");
                $totalFee = 0;
                if (is_array($feeStructDoc)) {
                    $heads = $feeStructDoc['feeHeads'] ?? [];
                    foreach ((array) $heads as $h) {
                        $totalFee += floatval($h['amount'] ?? 0);
                    }
                }
                $discountAmount = round(($totalFee * $discValue) / 100, 2);
            }

            // Apply max_discount cap if set
            if ($maxDisc > 0 && $discountAmount > $maxDisc) {
                $discountAmount = $maxDisc;
            }

            $newTotal = $existingAmt + $discountAmount;

            // Write to student's Discount node - maintain history
            $historyKey = $discId . '_' . date('Ymd_His');
            $updateData = [
                'OnDemandDiscount' => $discountAmount,
                'totalDiscount'    => $newTotal,
                'last_policy_id'   => $discId,
                'last_policy_name' => $discName,
                'applied_at'       => date('Y-m-d H:i:s'),
                'valid_until'      => $validUntil,   // '' = no expiry
                'validUntil'       => $validUntil,   // dual-emit camelCase
            ];

            // Store in Applied sub-node for audit trail
            $appliedData = [
                'policy_id'   => $discId,
                'policy_name' => $discName,
                'amount'      => $discountAmount,
                'type'        => $discType,
                'applied_at'  => date('Y-m-d H:i:s'),
                'applied_by'  => $this->admin_name,
            ];

            // Write discount summary to Firestore studentDiscounts
            $discountDoc = array_merge($updateData, [
                'schoolId'  => $this->school_name,
                'studentId' => $safeUserId,
                'applied'   => array_merge(
                    (array) ($existing['applied'] ?? []),
                    [$historyKey => $appliedData]
                ),
            ]);
            $this->firebase->firestoreSet('studentDiscounts', "{$this->school_name}_{$safeUserId}", $discountDoc, true);
            $applied++;

            // Mirror discount summary + history to Firestore `studentDiscounts`.
            try {
                $scholAmt = is_array($existing) ? (float) ($existing['ScholarshipDiscount'] ?? 0) : 0;
                $dOk = $this->fsSync->syncDiscount($safeUserId, [
                    'onDemandDiscount'    => $discountAmount,
                    'scholarshipDiscount' => $scholAmt,
                    'totalDiscount'       => $newTotal,
                    'lastPolicyId'        => $discId,
                    'lastPolicyName'      => $discName,
                    'appliedAt'           => $updateData['applied_at'],
                    'validUntil'          => $validUntil,   // Phase 17 — '' = no expiry
                    'applied'             => [
                        'policy_id'   => $discId,
                        'policy_name' => $discName,
                        'amount'      => $discountAmount,
                        'type'        => $discType,
                        'applied_at'  => $appliedData['applied_at'],
                        'applied_by'  => $appliedData['applied_by'],
                        'history_key' => $historyKey,
                        'valid_until' => $validUntil,
                    ],
                ], [
                    'studentName' => $student['name'] ?? $student['Name'] ?? '',
                    'className'   => $class,
                    'section'     => $section,
                ]);
                if (!$dOk) {
                    $this->fsSync->queueForRetry('studentDiscount', [
                        'studentId' => $safeUserId,
                        'summary'   => [
                            'onDemandDiscount' => $discountAmount,
                            'totalDiscount'    => $newTotal,
                            'lastPolicyId'     => $discId,
                        ],
                        'context'   => ['className' => $class, 'section' => $section],
                    ]);
                }
            } catch (Exception $e) {
                log_message('error', "apply_discount: studentDiscount sync failed for {$safeUserId}: " . $e->getMessage());
            }

            // Mirror defaulter status to Firestore per-student so the parent
            // app's balance view stays in sync with each discount application.
            try {
                $defaulterStatus = $this->feeDefaulter->updateDefaulterStatus($safeUserId);
                $studentName = $student['name'] ?? $student['Name'] ?? '';
                $defOk = $this->fsSync->syncDefaulterStatus(
                    $safeUserId, $defaulterStatus, $studentName, $class, $section
                );
                if (!$defOk) {
                    $this->fsSync->queueForRetry('feeDefaulter', [
                        'studentId'   => $safeUserId,
                        'status'      => $defaulterStatus,
                        'studentName' => $studentName,
                        'className'   => $class,
                        'section'     => $section,
                    ]);
                }
            } catch (Exception $e) {
                log_message('error', "apply_discount: defaulter sync failed for {$safeUserId}: " . $e->getMessage());
            }
        }

        $msg = "Discount applied to {$applied} student(s).";
        if (!empty($errors)) {
            $msg .= ' Errors: ' . implode('; ', $errors);
        }

        $this->json_success(['message' => $msg, 'applied' => $applied]);
    }

    // ══════════════════════════════════════════════════════════════════
    //  SCHOLARSHIP MANAGEMENT (AJAX)
    // ══════════════════════════════════════════════════════════════════

    /**
     * GET — Fetch all scholarships.
     */
    public function fetch_scholarships()
    {
        $this->_require_role(self::VIEW_ROLES, 'fetch_scholarships');
        $scholRows = $this->firebase->firestoreQuery('feeScholarships', [
            ['schoolId', '==', $this->school_name],
        ]);
        $awardRows = $this->firebase->firestoreQuery('scholarshipAwards', [
            ['schoolId', '==', $this->school_name],
            ['status',   '==', 'active'],
        ]);

        // Pre-compute award counts per scholarship
        $awardCounts = [];
        foreach ((array) $awardRows as $row) {
            $d = $row['data'] ?? $row;
            $sid = (string) ($d['scholarshipId'] ?? $d['scholarship_id'] ?? '');
            if ($sid !== '') {
                $awardCounts[$sid] = ($awardCounts[$sid] ?? 0) + 1;
            }
        }

        $scholarships = [];
        foreach ((array) $scholRows as $row) {
            $schol = $row['data'] ?? $row;
            if (!is_array($schol)) continue;
            $id = $this->_stripSchoolPrefix($row['id'] ?? '');
            $schol['id'] = $id;
            $schol['current_awards'] = $awardCounts[$id] ?? 0;
            $scholarships[] = $schol;
        }
        $this->json_success(['scholarships' => $scholarships]);
    }

    /**
     * POST — Create or update a scholarship.
     * Params: id?, name, type, value, criteria, max_beneficiaries, active
     */
    public function save_scholarship()
    {
        $this->_require_role(self::FINANCE_ROLES, 'save_scholarship');
        $name    = trim($this->input->post('name'));
        $type    = trim($this->input->post('type'));
        $value   = floatval($this->input->post('value'));
        $criteria       = trim($this->input->post('criteria'));
        $maxBeneficiary = (int)$this->input->post('max_beneficiaries');
        $active  = ($this->input->post('active') === 'false' || $this->input->post('active') === '0')
                   ? false : true;
        $scholId = trim($this->input->post('id'));

        if ($name === '') {
            $this->json_error('Scholarship name is required.');
        }
        if (!in_array($type, ['percentage', 'fixed'], true)) {
            $this->json_error('Scholarship type must be "percentage" or "fixed".');
        }
        if ($value <= 0) {
            $this->json_error('Scholarship value must be greater than zero.');
        }

        $now = date('Y-m-d H:i:s');

        $data = [
            'name'              => $name,
            'type'              => $type,
            'value'             => $value,
            'criteria'          => $criteria,
            'max_beneficiaries' => $maxBeneficiary,
            'academic_year'     => $this->session_year,
            'active'            => $active,
        ];

        $data['schoolId'] = $this->school_name;

        if (!empty($scholId)) {
            $scholId = $this->safe_path_segment($scholId, 'scholarship_id');
            $data['updated_at'] = $now;
            $this->firebase->firestoreSet('feeScholarships', "{$this->school_name}_{$scholId}", $data, true);
            $this->json_success(['message' => 'Scholarship updated successfully.', 'id' => $scholId]);
        } else {
            $data['created_at'] = $now;
            $newId = uniqid('schol_');
            $this->firebase->firestoreSet('feeScholarships', "{$this->school_name}_{$newId}", $data);
            $this->json_success(['message' => 'Scholarship created successfully.', 'id' => $newId]);
        }
    }

    /**
     * POST — Delete a scholarship.
     * Params: scholarship_id
     */
    public function delete_scholarship()
    {
        $this->_require_role(self::FINANCE_ROLES, 'delete_scholarship');
        $scholId = $this->safe_path_segment(trim($this->input->post('scholarship_id') ?? ''), 'scholarship_id');

        $existing = $this->firebase->firestoreGet('feeScholarships', "{$this->school_name}_{$scholId}");
        if (empty($existing)) {
            $this->json_error('Scholarship not found.');
        }

        // Check for active awards
        $activeAwards = $this->firebase->firestoreQuery('scholarshipAwards', [
            ['schoolId',       '==', $this->school_name],
            ['scholarshipId',  '==', $scholId],
            ['status',         '==', 'active'],
        ], null, 'ASC', 1);
        if (!empty($activeAwards)) {
            $this->json_error('Cannot delete scholarship with active awards. Revoke all awards first.');
        }

        $this->firebase->firestoreDelete('feeScholarships', "{$this->school_name}_{$scholId}");
        $this->json_success(['message' => 'Scholarship deleted successfully.']);
    }

    /**
     * GET — Fetch scholarship awards, optionally filtered by scholarship_id.
     * Query param: scholarship_id (optional)
     */
    public function fetch_awards()
    {
        $this->_require_role(self::VIEW_ROLES, 'fetch_awards');
        $scholId = trim($this->input->get('scholarship_id'));

        $conditions = [['schoolId', '==', $this->school_name]];
        if ($scholId !== '') {
            $conditions[] = ['scholarshipId', '==', $scholId];
        }
        $rows = $this->firebase->firestoreQuery('scholarshipAwards', $conditions);
        $awards = [];
        foreach ((array) $rows as $row) {
            $award = $row['data'] ?? $row;
            if (!is_array($award)) continue;
            $award['id'] = $this->_stripSchoolPrefix($row['id'] ?? '');
            // Normalize for view compatibility
            $award['scholarship_id'] = (string) ($award['scholarshipId'] ?? $award['scholarship_id'] ?? '');
            $award['student_id']     = (string) ($award['studentId']     ?? $award['student_id']     ?? '');
            $award['student_name']   = (string) ($award['studentName']   ?? $award['student_name']   ?? '');
            $awards[] = $award;
        }

        $this->json_success(['awards' => $awards]);
    }

    /**
     * POST — Award a scholarship to a student.
     * Params: scholarship_id, student_id, student_name, class, section, amount
     */
    public function award_scholarship()
    {
        $this->_require_role(self::FINANCE_ROLES, 'award_scholarship');
        $scholId     = $this->safe_path_segment(trim($this->input->post('scholarship_id') ?? ''), 'scholarship_id');
        $studentId   = $this->safe_path_segment(trim($this->input->post('student_id') ?? ''), 'student_id');
        $studentName = trim($this->input->post('student_name'));
        $class       = trim($this->input->post('class'));
        $section     = trim($this->input->post('section'));
        $amount      = floatval($this->input->post('amount'));

        // Validate scholarship exists and is active
        $scholarship = $this->firebase->firestoreGet('feeScholarships', "{$this->school_name}_{$scholId}");
        if (!is_array($scholarship)) {
            $this->json_error('Scholarship not found.');
        }
        if (isset($scholarship['active']) && $scholarship['active'] === false) {
            $this->json_error('This scholarship is not active.');
        }

        // Check max beneficiaries
        $maxBen = isset($scholarship['max_beneficiaries']) ? (int)$scholarship['max_beneficiaries'] : 0;
        if ($maxBen > 0) {
            $activeAwards = $this->firebase->firestoreQuery('scholarshipAwards', [
                ['schoolId',      '==', $this->school_name],
                ['scholarshipId', '==', $scholId],
                ['status',        '==', 'active'],
            ]);
            $currentCount = count((array) $activeAwards);
            if ($currentCount >= $maxBen) {
                $this->json_error("Maximum beneficiaries ({$maxBen}) reached for this scholarship.");
            }
        }

        // Calculate amount if not provided (use scholarship value)
        if ($amount <= 0) {
            $scholType  = isset($scholarship['type']) ? $scholarship['type'] : 'fixed';
            $scholValue = isset($scholarship['value']) ? floatval($scholarship['value']) : 0;

            if ($scholType === 'percentage') {
                // Get total fees from Firestore feeStructures
                list($classNode, $sectionNode) = $this->_normalizeClassSection($class, $section);
                $csKey = str_ireplace('Class ', '', $classNode) . '_' . str_ireplace('Section ', '', $sectionNode);
                $feeStructDoc = $this->firebase->firestoreGet('feeStructures', "{$this->school_name}_{$this->session_year}_{$csKey}");
                $totalFee = 0;
                if (is_array($feeStructDoc)) {
                    foreach ((array) ($feeStructDoc['feeHeads'] ?? []) as $h) {
                        $totalFee += floatval($h['amount'] ?? 0);
                    }
                }
                $amount = round(($totalFee * $scholValue) / 100, 2);
            } else {
                $amount = $scholValue;
            }
        }

        $scholName = isset($scholarship['name']) ? $scholarship['name'] : 'Scholarship';
        $now       = date('Y-m-d H:i:s');

        // Create award record
        $awardData = [
            'scholarship_id'   => $scholId,
            'scholarship_name' => $scholName,
            'student_id'       => $studentId,
            'student_name'     => $studentName,
            'class'            => $class,
            'section'          => $section,
            'amount'           => $amount,
            'awarded_date'     => $now,
            'status'           => 'active',
            'awarded_by'       => $this->admin_name,
        ];

        $awardId = uniqid('award_');
        $awardData['schoolId']      = $this->school_name;
        $awardData['scholarshipId'] = $scholId;
        $awardData['studentId']     = $studentId;
        $awardData['studentName']   = $studentName;
        $this->firebase->firestoreSet('scholarshipAwards', "{$this->school_name}_{$awardId}", $awardData);

        // Update student's Discount in Firestore
        list($classNode, $sectionNode) = $this->_normalizeClassSection($class, $section);

        $discDoc = $this->firebase->firestoreGet('studentDiscounts', "{$this->school_name}_{$studentId}");
        $existing = is_array($discDoc) ? $discDoc : [];
        $existingTotal = floatval($existing['totalDiscount'] ?? 0);
        $existingSchol = floatval($existing['ScholarshipDiscount'] ?? $existing['scholarshipDiscount'] ?? 0);

        $scholarshipUpdate = [
            'schoolId'            => $this->school_name,
            'studentId'           => $studentId,
            'ScholarshipDiscount' => $existingSchol + $amount,
            'totalDiscount'       => $existingTotal + $amount,
            'scholarships'        => array_merge(
                (array) ($existing['scholarships'] ?? []),
                [$awardId => [
                    'scholarship_id'   => $scholId,
                    'scholarship_name' => $scholName,
                    'amount'           => $amount,
                    'awarded_date'     => $now,
                ]]
            ),
        ];
        $this->firebase->firestoreSet('studentDiscounts', "{$this->school_name}_{$studentId}", $scholarshipUpdate, true);

        // ── Firestore: canonical scholarship award record + student summary ──
        try {
            $awOk = $this->fsSync->syncScholarshipAward($awardId, [
                'scholarshipId'   => $scholId,
                'scholarshipName' => $scholName,
                'studentId'       => $studentId,
                'studentName'     => $studentName,
                'className'       => $classNode,
                'section'         => $sectionNode,
                'amount'          => $amount,
                'awardedDate'     => $now,
                'awardedBy'       => $this->admin_name,
            ]);
            if (!$awOk) {
                $this->fsSync->queueForRetry('scholarshipAward', [
                    'awardId' => $awardId,
                    'award'   => [
                        'scholarshipId'   => $scholId,
                        'studentId'       => $studentId,
                        'amount'          => $amount,
                    ],
                ]);
            }

            $dOk = $this->fsSync->syncDiscount($studentId, [
                'scholarshipDiscount' => $existingSchol + $amount,
                'totalDiscount'       => $existingTotal + $amount,
                'appliedAt'           => $now,
                'scholarships'        => [
                    $awardId => [
                        'scholarship_id'   => $scholId,
                        'scholarship_name' => $scholName,
                        'amount'           => $amount,
                        'awarded_date'     => $now,
                    ],
                ],
            ], [
                'studentName' => $studentName,
                'className'   => $classNode,
                'section'     => $sectionNode,
            ]);
            if (!$dOk) {
                $this->fsSync->queueForRetry('studentDiscount', [
                    'studentId' => $studentId,
                    'summary'   => [
                        'scholarshipDiscount' => $existingSchol + $amount,
                        'totalDiscount'       => $existingTotal + $amount,
                    ],
                    'context'   => ['className' => $classNode, 'section' => $sectionNode],
                ]);
            }
        } catch (Exception $e) {
            log_message('error', "award_scholarship: Firestore sync failed for {$studentId}/{$awardId}: " . $e->getMessage());
        }

        // Mirror defaulter status to Firestore so parent/teacher apps see the
        // scholarship-reduced balance without refetching RTDB.
        try {
            $defaulterStatus = $this->feeDefaulter->updateDefaulterStatus($studentId);
            $defOk = $this->fsSync->syncDefaulterStatus(
                $studentId, $defaulterStatus, $studentName, $classNode, $sectionNode
            );
            if (!$defOk) {
                $this->fsSync->queueForRetry('feeDefaulter', [
                    'studentId'   => $studentId,
                    'status'      => $defaulterStatus,
                    'studentName' => $studentName,
                    'className'   => $classNode,
                    'section'     => $sectionNode,
                ]);
            }
        } catch (Exception $e) {
            log_message('error', "award_scholarship: defaulter sync failed for {$studentId}: " . $e->getMessage());
        }

        $this->json_success([
            'message'  => "Scholarship awarded to {$studentName}.",
            'award_id' => $awardId,
            'amount'   => $amount,
        ]);
    }

    /**
     * POST — Revoke a scholarship award.
     * Params: award_id
     */
    public function revoke_scholarship()
    {
        $this->_require_role(self::FINANCE_ROLES, 'revoke_scholarship');
        $awardId = $this->safe_path_segment(trim($this->input->post('award_id') ?? ''), 'award_id');

        $award = $this->firebase->firestoreGet('scholarshipAwards', "{$this->school_name}_{$awardId}");
        if (!is_array($award)) {
            $this->json_error('Award not found.');
        }
        if (isset($award['status']) && $award['status'] === 'revoked') {
            $this->json_error('This award has already been revoked.');
        }

        $now = date('Y-m-d H:i:s');

        // Update award status in Firestore
        $this->firebase->firestoreSet('scholarshipAwards', "{$this->school_name}_{$awardId}", [
            'status'       => 'revoked',
            'revoked_date' => $now,
            'revokedDate'  => $now,
            'revoked_by'   => $this->admin_name,
            'revokedBy'    => $this->admin_name,
        ], true);

        // Remove scholarship discount from student's Discount
        $class   = (string) ($award['class']      ?? $award['className'] ?? '');
        $section = (string) ($award['section']     ?? '');
        $userId  = (string) ($award['student_id']  ?? $award['studentId'] ?? '');
        $amount  = floatval($award['amount'] ?? 0);

        if ($class !== '' && $section !== '' && $userId !== '') {
            list($classNode, $sectionNode) = $this->_normalizeClassSection($class, $section);

            $discDoc = $this->firebase->firestoreGet('studentDiscounts', "{$this->school_name}_{$userId}");
            $existing = is_array($discDoc) ? $discDoc : [];

            if (!empty($existing)) {
                $totalDisc = floatval($existing['totalDiscount'] ?? 0);
                $scholDisc = floatval($existing['ScholarshipDiscount'] ?? $existing['scholarshipDiscount'] ?? 0);

                $newScholDisc = max(0, $scholDisc - $amount);
                $newTotal     = max(0, $totalDisc - $amount);

                // Remove this award from scholarships map
                $scholarships = (array) ($existing['scholarships'] ?? []);
                unset($scholarships[$awardId]);

                $this->firebase->firestoreSet('studentDiscounts', "{$this->school_name}_{$userId}", [
                    'ScholarshipDiscount' => $newScholDisc,
                    'totalDiscount'       => $newTotal,
                    'scholarships'        => $scholarships,
                ], true);

                // ── Firestore: mark award revoked + update student summary ──
                try {
                    $rOk = $this->fsSync->syncScholarshipRevoke($awardId, $this->admin_name);
                    if (!$rOk) {
                        $this->fsSync->queueForRetry('scholarshipAward', [
                            'awardId'   => $awardId,
                            'revokedBy' => $this->admin_name,
                            'action'    => 'revoke',
                        ]);
                    }

                    $dOk = $this->fsSync->syncDiscount($userId, [
                        'scholarshipDiscount' => $newScholDisc,
                        'totalDiscount'       => $newTotal,
                        'appliedAt'           => $now,
                        'scholarshipsRemove'  => [$awardId],
                    ], [
                        'studentName' => $award['student_name'] ?? '',
                        'className'   => $classNode,
                        'section'     => $sectionNode,
                    ]);
                    if (!$dOk) {
                        $this->fsSync->queueForRetry('studentDiscount', [
                            'studentId' => $userId,
                            'summary'   => [
                                'scholarshipDiscount' => $newScholDisc,
                                'totalDiscount'       => $newTotal,
                                'scholarshipsRemove'  => [$awardId],
                            ],
                            'context'   => ['className' => $classNode, 'section' => $sectionNode],
                        ]);
                    }
                } catch (Exception $e) {
                    log_message('error', "revoke_scholarship: Firestore sync failed for {$userId}/{$awardId}: " . $e->getMessage());
                }
            }

            // Mirror defaulter status to Firestore — revoking a scholarship
            // increases the student's outstanding balance, which the parent
            // app must see.
            try {
                $defaulterStatus = $this->feeDefaulter->updateDefaulterStatus($userId);
                $studentName = $award['student_name'] ?? '';
                $defOk = $this->fsSync->syncDefaulterStatus(
                    $userId, $defaulterStatus, $studentName, $classNode, $sectionNode
                );
                if (!$defOk) {
                    $this->fsSync->queueForRetry('feeDefaulter', [
                        'studentId'   => $userId,
                        'status'      => $defaulterStatus,
                        'studentName' => $studentName,
                        'className'   => $classNode,
                        'section'     => $sectionNode,
                    ]);
                }
            } catch (Exception $e) {
                log_message('error', "revoke_scholarship: defaulter sync failed for {$userId}: " . $e->getMessage());
            }
        }

        $this->json_success(['message' => 'Scholarship award revoked successfully.']);
    }

    // ══════════════════════════════════════════════════════════════════
    //  REFUND SYSTEM (AJAX)
    // ══════════════════════════════════════════════════════════════════

    /**
     * GET — Fetch refunds, optionally filtered by status.
     * Query param: status (optional — pending|approved|processed|rejected)
     */
    public function fetch_refunds()
    {
        $this->_require_role(self::VIEW_ROLES, 'fetch_refunds');
        $this->_bootFsTxn();
        $filterStatus = trim((string) $this->input->get('status'));

        $rows = $this->fsTxn->listRefunds($filterStatus);
        $refunds = [];
        foreach ((array) $rows as $row) {
            $ref = $row['data'] ?? $row;
            if (!is_array($ref)) continue;
            // The UI and action endpoints expect the short refund id
            // (`ref_xxxxxx`), NOT the prefixed Firestore doc id
            // (`{schoolId}_ref_xxxxxx`). Prefer the field value; only fall
            // back to stripping the prefix from the doc id.
            $rid = (string) ($ref['refundId'] ?? '');
            if ($rid === '') {
                $docId  = (string) ($row['id'] ?? '');
                $prefix = $this->fs->schoolId() . '_';
                $rid    = (strpos($docId, $prefix) === 0) ? substr($docId, strlen($prefix)) : $docId;
            }
            // Normalise snake_case + camelCase for view compatibility.
            $reqDate = (string) ($ref['requestedDate'] ?? $ref['requested_date'] ?? '');
            $class   = (string) ($ref['className']     ?? $ref['class']          ?? '');
            $section = (string) ($ref['section']       ?? '');
            $ref['id']             = $rid;
            $ref['refund_id']      = $rid;
            $ref['class']          = $class;
            $ref['section']        = $section;
            $ref['class_section']  = trim($class . ' / ' . $section);
            $ref['date']           = $reqDate;
            $ref['requested_date'] = $reqDate;
            $ref['student_id']     = (string) ($ref['studentId']   ?? $ref['student_id']   ?? '');
            $ref['student_name']   = (string) ($ref['studentName'] ?? $ref['student_name'] ?? '');
            $ref['receipt_no']     = (string) ($ref['receiptNo']   ?? $ref['receipt_no']   ?? '');
            $ref['fee_title']      = (string) ($ref['feeTitle']    ?? $ref['fee_title']    ?? '');
            $ref['refund_mode']    = (string) ($ref['refundMode']  ?? $ref['refund_mode']  ?? '');
            $ref['processed_date'] = (string) ($ref['processedDate'] ?? $ref['processed_date'] ?? '');
            $refunds[] = $ref;
        }

        // Compute stats
        $stats = ['total' => count($refunds), 'pending' => 0, 'approved' => 0, 'processed' => 0, 'rejected' => 0];
        foreach ($refunds as $ref) {
            $s = (string) ($ref['status'] ?? '');
            if (isset($stats[$s])) $stats[$s]++;
        }

        $this->json_success(['refunds' => $refunds, 'stats' => $stats, 'success' => true]);
    }

    /** Lazy-load the Fee_firestore_txn helper. */
    private function _bootFsTxn(): void
    {
        if (!isset($this->fsTxn)) {
            $this->load->library('Fee_firestore_txn', null, 'fsTxn');
            $this->fsTxn->init($this->firebase, $this->fs, $this->fs->schoolId(), $this->session_year);
        }
    }

    /**
     * POST — Create a refund request.
     * Params: student_id, student_name, class, section, amount, fee_title, receipt_no, reason
     */
    public function create_refund()
    {
        $this->_require_role(self::ADMIN_ROLES, 'create_refund');
        $this->_bootFsTxn();

        $studentId   = trim((string) $this->input->post('student_id'));
        $studentName = trim((string) $this->input->post('student_name'));
        $class       = trim((string) $this->input->post('class'));
        $section     = trim((string) $this->input->post('section'));
        $amount      = floatval($this->input->post('amount'));
        $feeTitle    = trim((string) $this->input->post('fee_title'));
        $receiptNo   = trim((string) $this->input->post('receipt_no'));
        $reason      = trim((string) $this->input->post('reason'));

        if ($studentId === '' || $studentName === '') $this->json_error('Student information is required.');
        $studentId = $this->safe_path_segment($studentId, 'student_id');

        // Receipt-number normalisation: users see different formats at
        // different places — the success modal shows "Receipt #1", the
        // internal key is "F1", and printed receipts show "R000001".
        // Strip any prefix so all three forms resolve to the same receipt.
        if ($receiptNo !== '') {
            $receiptNo = preg_replace('/^R0*/i', '', $receiptNo);   // R000001 → 1
            $receiptNo = preg_replace('/^F/i',  '', $receiptNo);    // F1      → 1
            $receiptNo = preg_replace('/\D/',   '', $receiptNo);    // keep digits only
            if ($receiptNo === '') {
                $this->json_error('Could not recognise the receipt number. Enter a value like "1" or "R000001".');
            }
            $receiptNo = $this->safe_path_segment($receiptNo, 'receipt_no');
        }
        if ($amount <= 0)    $this->json_error('Refund amount must be greater than zero.');
        if ($feeTitle === '') $this->json_error('Fee title is required.');
        if ($reason === '')  $this->json_error('Refund reason is required.');

        // Handle combined class_section field from view
        $classSection = trim((string) $this->input->post('class_section'));
        if ($classSection !== '' && $class === '') {
            $parts = preg_split('/[\/,]/', $classSection, 2);
            $class = trim($parts[0]);
            $section = isset($parts[1]) ? trim($parts[1]) : '';
        }

        // Verify the receipt exists in Firestore (Session A canonical).
        if ($receiptNo !== '') {
            $rcptKey = (strpos($receiptNo, 'F') === 0) ? $receiptNo : 'F' . $receiptNo;
            $record  = $this->fsTxn->getFeeReceipt($rcptKey);
            if (!is_array($record)) {
                $this->json_error("Receipt '{$receiptNo}' not found for this student.");
            }
            $originalAmount = (float) ($record['amount'] ?? 0);
            if ($originalAmount > 0 && $amount > $originalAmount) {
                $this->json_error("Refund amount ({$amount}) exceeds original payment amount ({$originalAmount}).");
            }
        }

        $now   = date('Y-m-d H:i:s');
        $refId = uniqid('ref_');

        $refundDoc = [
            'refundId'        => $refId,
            'studentId'       => $studentId,
            'studentName'     => $studentName,
            'className'       => $class,
            'section'         => $section,
            'amount'          => $amount,
            'feeTitle'        => $feeTitle,
            'receiptNo'       => $receiptNo,
            'reason'          => $reason,
            'status'          => 'pending',
            'requestedDate'   => $now,
            'reviewedDate'    => '',
            'processedDate'   => '',
            'reviewedBy'      => '',
            'processedBy'     => '',
            'refundMode'      => '',
            'remarks'         => '',
        ];

        if (!$this->fsTxn->writeRefund($refId, $refundDoc)) {
            $this->json_error('Failed to record refund. Please retry.');
        }

        $this->json_success([
            'message'   => 'Refund request created successfully.',
            'refund_id' => $refId,
            'success'   => true,
        ]);
    }

    /**
     * POST — Update refund status (approve/reject).
     * Params: refund_id, status (approved|rejected), remarks?
     */
    public function update_refund_status()
    {
        $this->_require_role(self::ADMIN_ROLES, 'update_refund_status');
        $this->_bootFsTxn();

        $refId   = $this->safe_path_segment(trim((string) $this->input->post('refund_id')), 'refund_id');
        $status  = trim((string) $this->input->post('status'));
        $remarks = trim((string) $this->input->post('remarks'));

        if (!in_array($status, ['approved', 'rejected'], true)) {
            $this->json_error('Status must be "approved" or "rejected".');
        }

        $existing = $this->fsTxn->getRefund($refId);
        if (!is_array($existing)) $this->json_error('Refund not found.');

        $currentStatus = (string) ($existing['status'] ?? '');
        if ($currentStatus !== 'pending') {
            $this->json_error("Cannot change status. Current status is '{$currentStatus}'.");
        }

        $updateData = [
            'status'       => $status,
            'reviewedDate' => date('Y-m-d H:i:s'),
            'reviewedBy'   => $this->admin_name ?? '',
        ];
        if ($remarks !== '') $updateData['remarks'] = $remarks;

        $this->fsTxn->writeRefund($refId, $updateData);

        $this->json_success(['message' => "Refund {$status} successfully.", 'success' => true]);
    }

    /**
     * POST — Approve a refund (convenience wrapper).
     */
    public function approve_refund()
    {
        $this->_require_role(self::ADMIN_ROLES, 'approve_refund');
        $_POST['status'] = 'approved';
        $this->update_refund_status();
    }

    /**
     * POST — Reject a refund (convenience wrapper).
     */
    public function reject_refund()
    {
        $this->_require_role(self::ADMIN_ROLES, 'reject_refund');
        $_POST['status'] = 'rejected';
        $this->update_refund_status();
    }

    /**
     * POST — Process an approved refund.
     * Params: refund_id (required), refund_mode (cash|bank_transfer|cheque|online)
     *
     * Thin controller: validates input, delegates to Fee_refund_service.
     */
    public function process_refund()
    {
        $this->_require_role(self::ADMIN_ROLES, 'process_refund');
        $this->_bootFsTxn();

        $refId      = trim((string) $this->_post('refund_id'));
        $refundMode = trim((string) $this->_post('refund_mode'));
        if ($refId === '') $this->json_error('Refund ID is required.');
        $refId = $this->safe_path_segment($refId, 'refund_id');

        $validModes = ['cash', 'bank_transfer', 'cheque', 'online'];
        if (!in_array($refundMode, $validModes, true)) {
            $this->json_error('Invalid refund mode. Must be one of: ' . implode(', ', $validModes));
        }

        // Operations_accounting still posts the reversal ledger to RTDB +
        // Firestore mirror. Session C will make it Firestore-only.
        $this->load->library('Operations_accounting', null, 'ops_acct');
        $this->ops_acct->init(
            $this->firebase, $this->school_name, $this->session_year,
            $this->admin_id ?? 'system', $this
        );

        // Service is now Firestore-native via Fee_firestore_txn.
        $this->load->library('Fee_refund_service', null, 'refund_svc');
        $this->refund_svc->init(
            $this->fsTxn,
            $this->admin_name ?? 'System',
            $this->admin_id ?? 'system',
            $this->ops_acct
        );

        // R.6: admin may pass acknowledge_stale=1 to force-refund a receipt
        // whose demands are now owned by a newer receipt. Without this flag
        // the service returns STALE_ALLOCATION; with it, the amount is
        // routed entirely to the student's wallet so the newer receipt's
        // state stays intact.
        $ackStale = (bool) $this->_post('acknowledge_stale')
                 || ($this->_post('acknowledge_stale') === '1');

        $result = $this->refund_svc->process($refId, $refundMode, [
            'acknowledge_stale' => $ackStale,
        ]);

        if (!$result['ok']) {
            log_message('error', "process_refund failed for {$refId}: " . ($result['error'] ?? ''));
            // R.6: surface structured detail for STALE_ALLOCATION so the
            // UI can show a "Process anyway (to wallet)" override prompt.
            if (($result['code'] ?? '') === 'STALE_ALLOCATION') {
                $this->output->set_content_type('application/json');
                $this->output->set_output(json_encode([
                    'status'        => 'error',
                    'code'          => 'STALE_ALLOCATION',
                    'message'       => $result['error'],
                    'conflicts'     => $result['conflicts']     ?? [],
                    'superseded_by' => $result['superseded_by'] ?? [],
                ]));
                return;
            }
            $this->json_error($result['error'] ?? 'Refund processing failed.');
        }

        // Best-effort audit log (Fee_audit still RTDB — will be migrated
        // alongside the accounting journal in Session C).
        try {
            $this->load->library('Fee_audit', null, 'fee_audit');
            $this->fee_audit->init(
                $this->firebase, "{$this->sessionRoot}/Fees",
                $this->admin_id ?? 'system', $this->admin_name ?? 'System',
                $this->school_name
            );
            $r = $this->fsTxn->getRefund($refId);
            $this->fee_audit->log('refund_processed', [
                'refund_id'  => $refId,
                'student_id' => is_array($r) ? (string) ($r['studentId'] ?? $r['student_id'] ?? '') : '',
                'amount'     => is_array($r) ? (float)  ($r['amount']    ?? 0) : 0,
                'receipt_no' => is_array($r) ? (string) ($r['receiptNo'] ?? $r['receipt_no'] ?? '') : '',
                'mode'       => $refundMode,
            ]);
        } catch (\Exception $_) { /* audit is non-critical */ }

        $this->json_success($result['data']);
    }

    /**
     * POST — Unstick a refund left stranded in 'processing' status.
     * Params: refund_id (required)
     *
     * R.7 rescue endpoint. When a crash / timeout interrupts
     * Fee_refund_service::process() after it flips the refund doc to
     * 'processing' but before it marks 'processed', the doc stays stuck
     * and cannot be re-tried because both the `processLock` timestamp
     * and the per-student `feeLocks` doc may outlive the 5-minute TTL
     * without being released. This endpoint:
     *   1. Verifies the stored `processLock` is actually stale
     *      (older than LOCK_TTL = 300s) — refuses otherwise, so we
     *      never steal from an in-flight process().
     *   2. Rolls the refund doc back to 'approved' + clears processLock.
     *   3. Force-releases the per-student feeLocks entry (token-blind).
     *   4. Deletes the feeIdempotency hash so a retry can proceed.
     * Admin then re-clicks "Process" on the refund row.
     */
    public function unstick_refund()
    {
        $this->_require_role(self::ADMIN_ROLES, 'unstick_refund');
        $this->_bootFsTxn();

        $refId = trim((string) $this->_post('refund_id'));
        if ($refId === '') $this->json_error('Refund ID is required.');
        $refId = $this->safe_path_segment($refId, 'refund_id');

        $refund = $this->fsTxn->getRefund($refId);
        if (!is_array($refund)) {
            $this->json_error('Refund not found.');
        }

        $status = (string) ($refund['status'] ?? '');
        if ($status !== 'processing') {
            $this->json_error("Only 'processing' refunds can be unstuck. Current status: '{$status}'.");
        }

        // Fee_refund_service uses LOCK_TTL = 300. Match it here so an
        // admin can't race an in-flight process() that's still inside
        // its own lock window.
        $lockTsRaw = (string) ($refund['processLock'] ?? $refund['process_lock'] ?? '');
        $lockTs    = $lockTsRaw !== '' ? strtotime($lockTsRaw) : 0;
        $age       = $lockTs ? (time() - $lockTs) : PHP_INT_MAX;
        if ($lockTs && $age < 300) {
            $wait = 300 - $age;
            $this->json_error("Refund is still within its processing window. Wait {$wait}s before unsticking.");
        }

        $studentId     = (string) ($refund['studentId']  ?? $refund['student_id']  ?? '');
        $origReceiptNo = (string) ($refund['receiptNo']  ?? $refund['receipt_no']  ?? '');
        $amount        = (float)  ($refund['amount']     ?? 0);

        // 1. Roll the refund doc back to 'approved' so the admin can retry.
        $this->fsTxn->writeRefund($refId, [
            'status'      => 'approved',
            'processLock' => '',
        ]);

        // 2. Force-release the per-student lock (token-blind) so the
        //    next process() attempt can reacquire it.
        $lockReleased = false;
        if ($studentId !== '') {
            $lockReleased = $this->fsTxn->forceReleaseLock($studentId);
        }

        // 3. Clear the idempotency marker. Rebuild the exact hash
        //    Fee_refund_service used when it entered the processing
        //    window, so the deletion lands on the same doc.
        $idempCleared = false;
        if ($studentId !== '' && $amount > 0) {
            $idempHash    = $this->fsTxn->idempKey($studentId, $refId, [$origReceiptNo], $amount);
            $idempCleared = $this->fsTxn->deleteIdempotency($idempHash);
        }

        log_message('info', "Fee_management::unstick_refund rescued {$refId} (student={$studentId} age={$age}s)");

        // Best-effort audit.
        try {
            $this->load->library('Fee_audit', null, 'fee_audit');
            $this->fee_audit->init(
                $this->firebase, "{$this->sessionRoot}/Fees",
                $this->admin_id ?? 'system', $this->admin_name ?? 'System',
                $this->school_name
            );
            $this->fee_audit->log('refund_unstuck', [
                'refund_id'     => $refId,
                'student_id'    => $studentId,
                'prior_age_s'   => $age,
                'lock_released' => $lockReleased,
                'idemp_cleared' => $idempCleared,
            ]);
        } catch (\Exception $_) { /* audit is non-critical */ }

        $this->json_success([
            'message'        => 'Refund reset to approved. Click Process to retry.',
            'refund_id'      => $refId,
            'prior_age_s'    => $age,
            'lock_released'  => $lockReleased,
            'idemp_cleared'  => $idempCleared,
        ]);
    }

    /**
     * POST — Retry the accounting journal post for a refund that was
     * processed but whose journal write failed (R.5).
     *
     * Params: refund_id (required)
     *
     * The refund itself is already complete — demands reversed, voucher
     * written, student notified. Only the ledger entry is missing. This
     * endpoint hits the idempotent journal poster: if a journal for this
     * refund already exists it returns that one; otherwise it posts a
     * fresh entry. Either way, the refund doc's journalPosted flag gets
     * set so the UI warning clears.
     */
    public function retry_refund_journal()
    {
        $this->_require_role(self::ADMIN_ROLES, 'retry_refund_journal');
        $this->_bootFsTxn();

        $refId = trim((string) $this->_post('refund_id'));
        if ($refId === '') $this->json_error('Refund ID is required.');
        $refId = $this->safe_path_segment($refId, 'refund_id');

        // Operations_accounting + Fee_refund_service boot is identical to
        // process_refund — keep parity so both endpoints share CoA state.
        $this->load->library('Operations_accounting', null, 'ops_acct');
        $this->ops_acct->init(
            $this->firebase, $this->school_name, $this->session_year,
            $this->admin_id ?? 'system', $this
        );

        $this->load->library('Fee_refund_service', null, 'refund_svc');
        $this->refund_svc->init(
            $this->fsTxn,
            $this->admin_name ?? 'System',
            $this->admin_id ?? 'system',
            $this->ops_acct
        );

        $result = $this->refund_svc->retryJournal($refId);

        if (!$result['ok']) {
            log_message('error', "retry_refund_journal failed for {$refId}: " . ($result['error'] ?? ''));
            $this->json_error($result['error'] ?? 'Journal retry failed.');
        }

        $msg = !empty($result['already'])
            ? 'Journal was already posted — nothing to retry.'
            : 'Journal posted successfully.';

        $this->json_success([
            'message'          => $msg,
            'refund_id'        => $refId,
            'journal_entry_id' => $result['entryId'],
            'already_posted'   => !empty($result['already']),
        ]);
    }

    // ══════════════════════════════════════════════════════════════════
    //  FEE REMINDERS (AJAX)
    // ══════════════════════════════════════════════════════════════════

    /**
     * GET — Fetch reminder settings.
     */
    public function get_reminder_settings()
    {
        $this->_require_role(self::VIEW_ROLES, 'get_reminder_settings');
        $settings = $this->firebase->firestoreGet('feeSettings', "{$this->school_name}_{$this->session_year}_reminders");
        if (!is_array($settings)) {
            $settings = [
                'auto_remind'        => false,
                'days_before_due'    => [7, 3, 1],
                'reminder_message'   => 'Dear Parent, this is a reminder that fees for {month} amounting to Rs. {amount} are due. Please pay by {due_date}.',
                'due_day_of_month'   => 10,
                'late_fee_enabled'   => false,
                'late_fee_type'      => 'fixed',
                'late_fee_value'     => 0,
            ];
        }
        $this->json_success(['settings' => $settings]);
    }

    /**
     * POST — Save reminder settings.
     * Params: auto_remind, days_before_due, reminder_message, due_day_of_month,
     *         late_fee_enabled, late_fee_type, late_fee_value
     */
    public function save_reminder_settings()
    {
        $this->_require_role(self::FINANCE_ROLES, 'save_reminder_settings');
        $autoRemind     = ($this->input->post('auto_remind') === '1'
                          || $this->input->post('auto_remind') === 'true') ? true : false;
        $daysBeforeDue  = $this->input->post('days_before_due');
        $message        = trim($this->input->post('reminder_message'));
        $dueDay         = (int)$this->input->post('due_day_of_month');
        $lateFeeEnabled = ($this->input->post('late_fee_enabled') === '1'
                          || $this->input->post('late_fee_enabled') === 'true') ? true : false;
        $lateFeeType    = trim($this->input->post('late_fee_type'));
        $lateFeeValue   = floatval($this->input->post('late_fee_value'));

        if ($dueDay < 1 || $dueDay > 28) {
            $this->json_error('Due day of month must be between 1 and 28.');
        }

        if ($lateFeeEnabled && !in_array($lateFeeType, ['percentage', 'fixed'], true)) {
            $this->json_error('Late fee type must be "percentage" or "fixed".');
        }

        // Parse days_before_due
        $daysArray = [];
        if (is_array($daysBeforeDue)) {
            $daysArray = array_map('intval', $daysBeforeDue);
        } elseif (is_string($daysBeforeDue) && $daysBeforeDue !== '') {
            $daysArray = array_map('intval', array_filter(explode(',', $daysBeforeDue)));
        }
        $daysArray = array_values(array_filter($daysArray, function ($d) {
            return $d > 0;
        }));
        rsort($daysArray);

        $settings = [
            'auto_remind'      => $autoRemind,
            'days_before_due'  => $daysArray,
            'reminder_message' => $message,
            'due_day_of_month' => $dueDay,
            'late_fee_enabled' => $lateFeeEnabled,
            'late_fee_type'    => $lateFeeType,
            'late_fee_value'   => $lateFeeValue,
            'updated_at'       => date('Y-m-d H:i:s'),
        ];

        $settings['schoolId'] = $this->school_name;
        $settings['session']  = $this->session_year;
        $settings['type']     = 'reminders';
        $this->firebase->firestoreSet('feeSettings', "{$this->school_name}_{$this->session_year}_reminders", $settings);

        $this->json_success(['message' => 'Reminder settings saved successfully.']);
    }

    /**
     * GET — Scan all classes to find students with unpaid fee months.
     * Returns list of students with due amounts.
     */
    public function fetch_due_students()
    {
        $this->_require_role(self::VIEW_ROLES, 'fetch_due_students');
        $classSections = $this->_getAllClassSections();
        $dueStudents   = [];

        // Get current month names for reference
        $months = [
            'April', 'May', 'June', 'July', 'August', 'September',
            'October', 'November', 'December', 'January', 'February', 'March'
        ];

        // Determine months up to current month
        $currentMonth  = date('n'); // 1-12
        $monthIndex    = ($currentMonth >= 4) ? ($currentMonth - 4) : ($currentMonth + 8);
        $monthsToCheck = array_slice($months, 0, $monthIndex + 1);

        // Load all students from Firestore
        $studentRows = $this->firebase->firestoreQuery('students', [
            ['schoolId', '==', $this->school_name],
        ]);

        // Load all fee demands to determine unpaid months
        $demandRows = $this->firebase->firestoreQuery('feeDemands', [
            ['schoolId', '==', $this->school_name],
            ['session',  '==', $this->session_year],
        ]);

        // Build demand lookup keyed by studentId + month-LABEL (canonical
        // periodToMonth, NOT full period string). Aggregates per-month
        // balance + status across all heads. Phase 15 dropped the legacy
        // `monthFee` cache read — feeDemands is the only source of truth.
        $demandsByStudentMonth = []; // sid -> month -> ['balance', 'allPaid']
        foreach ((array) $demandRows as $row) {
            $dd = $row['data'] ?? $row;
            $sid    = (string) ($dd['studentId'] ?? '');
            $period = (string) ($dd['period']    ?? '');
            $month  = Fee_firestore_txn::periodToMonth($period);
            if ($sid === '' || $month === '') continue;
            $bal    = (float) ($dd['balance']     ?? 0);
            $status = (string) ($dd['status']     ?? 'unpaid');
            if (!isset($demandsByStudentMonth[$sid][$month])) {
                $demandsByStudentMonth[$sid][$month] = ['balance' => 0.0, 'allPaid' => true, 'has' => false];
            }
            $demandsByStudentMonth[$sid][$month]['balance'] += $bal;
            $demandsByStudentMonth[$sid][$month]['has']      = true;
            if ($status !== 'paid' || $bal > 0.005) {
                $demandsByStudentMonth[$sid][$month]['allPaid'] = false;
            }
        }

        foreach ((array) $studentRows as $row) {
            $d = $row['data'] ?? $row;
            if (!is_array($d)) continue;
            $userId = (string) ($d['studentId'] ?? $this->_stripSchoolPrefix($row['id'] ?? ''));
            $studentName = (string) ($d['name'] ?? $d['studentName'] ?? '');
            $cls = trim((string) ($d['className'] ?? ''));
            $sec = trim((string) ($d['section'] ?? ''));
            if (stripos($cls, 'Class ') !== 0) $cls = 'Class ' . $cls;
            if (stripos($sec, 'Section ') !== 0) $sec = 'Section ' . $sec;

            $studentMonthMap = $demandsByStudentMonth[$userId] ?? [];

            $unpaidMonths = [];
            $totalDue     = 0.0;

            foreach ($monthsToCheck as $month) {
                $entry = $studentMonthMap[$month] ?? null;
                // No demand for this month → treat as unpaid (legacy cache
                // would have done the same via missing key fallback).
                if ($entry === null || !$entry['allPaid']) {
                    $unpaidMonths[] = $month;
                    if ($entry !== null) $totalDue += $entry['balance'];
                }
            }

            if (!empty($unpaidMonths)) {
                $dueStudents[] = [
                    'user_id'       => $userId,
                    'name'          => $studentName !== '' ? $studentName : $userId,
                    'class'         => $cls,
                    'section'       => $sec,
                    'unpaid_months' => $unpaidMonths,
                    'total_due'     => round($totalDue, 2),
                ];
            }
        }

        // Sort by total_due descending
        usort($dueStudents, function ($a, $b) {
            return $b['total_due'] - $a['total_due'];
        });

        $this->json_success([
            'students'     => $dueStudents,
            'total_count'  => count($dueStudents),
            'months_checked' => $monthsToCheck,
        ]);
    }

    /**
     * POST — Send reminder to selected students.
     * Params: student_ids[] (JSON objects), month
     *
     * Since SMS/email gateway is not yet integrated, this logs the reminder
     * for future integration.
     */
    public function send_reminder()
    {
        $this->_require_role(self::FINANCE_ROLES, 'send_reminder');
        $studentRaw = $this->input->post('student_ids');
        $month      = trim((string) $this->input->post('month'));
        // New (2026-04-17): accept channel so Defaulter Report can send
        // bulk WhatsApp reminders. Defaults to 'log' for legacy callers.
        $channel    = strtolower(trim((string) $this->input->post('channel')));
        $template   = trim((string) $this->input->post('template'));
        if ($channel === '') $channel = 'log';
        if ($month === '')   $month   = date('F'); // current month name

        if (empty($studentRaw) || !is_array($studentRaw)) {
            $this->json_error('No students selected.');
        }

        // Build a one-shot defaulter lookup so callers that pass bare
        // student_ids (like the Defaulter Report bulk-reminder button)
        // don't have to also send name/class/due info.
        $defaulterMap = [];
        try {
            $rows = $this->firebase->firestoreQuery('feeDefaulters', [
                ['schoolId', '==', $this->school_name],
                ['session',  '==', $this->session_year],
            ]);
            foreach ((array) $rows as $r) {
                $d = $r['data'] ?? $r;
                $sid = (string) ($d['studentId'] ?? $d['student_id'] ?? '');
                if ($sid !== '') $defaulterMap[$sid] = $d;
            }
        } catch (\Exception $_) { /* non-fatal */ }

        $now    = date('Y-m-d H:i:s');
        $logged = 0;
        $batchData = [];

        foreach ($studentRaw as $entry) {
            // Accept three shapes: plain student_id string, JSON
            // string, or already-decoded array. Normalises to an
            // associative array with at least user_id.
            $student = null;
            if (is_array($entry)) {
                $student = $entry;
            } elseif (is_string($entry)) {
                $decoded = json_decode($entry, true);
                if (is_array($decoded)) {
                    $student = $decoded;
                } else {
                    $sid = trim($entry);
                    if ($sid !== '') $student = ['user_id' => $sid];
                }
            }
            if (!is_array($student) || empty($student['user_id'])) continue;

            $sid = (string) $student['user_id'];
            // Enrich from defaulter doc if the caller didn't supply details.
            $def = $defaulterMap[$sid] ?? [];
            $name    = $student['name']      ?? ($def['studentName'] ?? '');
            $class   = $student['class']     ?? ($def['className']   ?? '');
            $section = $student['section']   ?? ($def['section']     ?? '');
            $due     = isset($student['total_due']) ? (float) $student['total_due']
                       : (float) ($def['totalBalance'] ?? $def['balance'] ?? 0);

            $logId = uniqid('rem_');
            $batchData[$logId] = [
                'student_id'   => $sid,
                'student_name' => $name,
                'class'        => $class,
                'section'      => $section,
                'month'        => $month,
                'amount_due'   => $due,
                'sent_date'    => $now,
                'channel'      => $channel,                 // 'whatsapp' | 'sms' | 'email' | 'log'
                'template'     => $template,
                'type'         => 'manual',
                'status'       => $channel === 'log' ? 'logged' : 'queued',
            ];
            $logged++;
        }

        if (!empty($batchData)) {
            foreach ($batchData as $logId => $entry) {
                $entry['schoolId'] = $this->school_name;
                $entry['session']  = $this->session_year;
                $this->firebase->firestoreSet('feeReminderLog', "{$this->school_name}_{$logId}", $entry);
            }
        }

        // TODO: Actual WhatsApp / SMS delivery is handled by a separate
        // dispatcher (Gupshup / MSG91 / Twilio). This endpoint only
        // records the intent and leaves the delivery worker to pick it
        // up from feeReminderLog where status='queued'.
        $msg = $channel === 'log'
            ? "Reminder logged for {$logged} student(s)."
            : ucfirst($channel) . " reminder queued for {$logged} student(s). Delivery by the messaging worker.";

        $this->json_success([
            'message' => $msg,
            'logged'  => $logged,
            'channel' => $channel,
        ]);
    }

    /**
     * GET — Fetch all reminder log entries.
     */
    public function fetch_reminder_log()
    {
        $this->_require_role(self::VIEW_ROLES, 'fetch_reminder_log');
        $rows = $this->firebase->firestoreQuery('feeReminderLog', [
            ['schoolId', '==', $this->school_name],
        ], 'sent_date', 'DESC');
        $logs = [];
        foreach ((array) $rows as $row) {
            $entry = $row['data'] ?? $row;
            if (!is_array($entry)) continue;
            $entry['id'] = $this->_stripSchoolPrefix($row['id'] ?? '');
            $logs[] = $entry;
        }

        $this->json_success(['logs' => $logs, 'total' => count($logs)]);
    }

    // ══════════════════════════════════════════════════════════════════
    //  PAYMENT GATEWAY (AJAX)
    // ══════════════════════════════════════════════════════════════════

    /**
     * GET — Fetch gateway configuration (secrets masked).
     */
    public function get_gateway_config()
    {
        $this->_require_role(self::ADMIN_ROLES, 'get_gateway_config');
        $config = $this->firebase->firestoreGet('feeSettings', "{$this->school_name}_{$this->session_year}_gateway");
        if (!is_array($config)) {
            $config = [
                'provider'       => '',
                'mode'           => 'test',
                'api_key'        => '',
                'api_secret'     => '',
                'active'         => false,
                'webhook_secret' => '',
            ];
        }

        // Mask secrets
        $masked = $config;
        if (!empty($masked['api_secret'])) {
            $len = strlen($masked['api_secret']);
            $masked['api_secret'] = $len > 4
                ? str_repeat('*', $len - 4) . substr($masked['api_secret'], -4)
                : str_repeat('*', $len);
        }
        if (!empty($masked['webhook_secret'])) {
            $len = strlen($masked['webhook_secret']);
            $masked['webhook_secret'] = $len > 4
                ? str_repeat('*', $len - 4) . substr($masked['webhook_secret'], -4)
                : str_repeat('*', $len);
        }

        $this->json_success(['config' => $masked]);
    }

    /**
     * POST — Save gateway configuration.
     * Params: provider, api_key, api_secret, mode, active, webhook_secret
     */
    public function save_gateway_config()
    {
        $this->_require_role(self::ADMIN_ROLES, 'save_gateway_config');
        $provider      = trim($this->input->post('provider'));
        $apiKey        = trim($this->input->post('api_key'));
        $apiSecret     = trim($this->input->post('api_secret'));
        $mode          = trim($this->input->post('mode'));
        $active        = ($this->input->post('active') === '1'
                         || $this->input->post('active') === 'true') ? true : false;
        $webhookSecret = trim($this->input->post('webhook_secret'));

        $validProviders = ['razorpay', 'stripe', 'paytm'];
        if ($provider !== '' && !in_array($provider, $validProviders, true)) {
            $this->json_error('Invalid provider. Must be one of: ' . implode(', ', $validProviders));
        }

        if (!in_array($mode, ['test', 'live'], true)) {
            $this->json_error('Mode must be "test" or "live".');
        }

        // If secret contains only asterisks, preserve existing value
        $existingConfig = $this->firebase->firestoreGet('feeSettings', "{$this->school_name}_{$this->session_year}_gateway");
        if (preg_match('/^\*+/', $apiSecret) && is_array($existingConfig) && !empty($existingConfig['api_secret'])) {
            $apiSecret = $existingConfig['api_secret'];
        }
        if (preg_match('/^\*+/', $webhookSecret) && is_array($existingConfig) && !empty($existingConfig['webhook_secret'])) {
            $webhookSecret = $existingConfig['webhook_secret'];
        }

        $now = date('Y-m-d H:i:s');

        $configData = [
            'provider'       => $provider,
            'mode'           => $mode,
            'api_key'        => $apiKey,
            'api_secret'     => $apiSecret,
            'active'         => $active,
            'webhook_secret' => $webhookSecret,
            'updated_at'     => $now,
        ];

        // Preserve created_at if updating
        if (is_array($existingConfig) && !empty($existingConfig['created_at'])) {
            $configData['created_at'] = $existingConfig['created_at'];
        } else {
            $configData['created_at'] = $now;
        }

        $configData['schoolId'] = $this->school_name;
        $configData['session']  = $this->session_year;
        $configData['type']     = 'gateway';
        $this->firebase->firestoreSet('feeSettings', "{$this->school_name}_{$this->session_year}_gateway", $configData);

        $this->json_success(['message' => 'Gateway configuration saved successfully.']);
    }

    /**
     * GET — Fetch all online payment records.
     */
    public function fetch_online_payments()
    {
        $this->_require_role(self::VIEW_ROLES, 'fetch_online_payments');
        $rows = $this->firebase->firestoreQuery('feeOnlinePayments', [
            ['schoolId', '==', $this->school_name],
        ], 'created_at', 'DESC');
        $payments = [];
        foreach ((array) $rows as $row) {
            $pay = $row['data'] ?? $row;
            if (!is_array($pay)) continue;
            $pay['id'] = $this->_stripSchoolPrefix($row['id'] ?? '');
            $payments[] = $pay;
        }

        $this->json_success(['payments' => $payments, 'total' => count($payments)]);
    }

    /**
     * Initialize the Payment Service with the appropriate gateway adapter.
     * Uses mock gateway for test mode, real gateway for live mode.
     */
    private function _init_payment_service(): void
    {
        if (isset($this->paymentService)) return; // already initialized

        $gwConfig = $this->firebase->firestoreGet('feeSettings', "{$this->school_name}_{$this->session_year}_gateway");

        // Load gateway adapter based on config
        $mode     = is_array($gwConfig) ? ($gwConfig['mode'] ?? 'test') : 'test';
        $provider = is_array($gwConfig) ? ($gwConfig['provider'] ?? 'mock') : 'mock';

        if ($provider === 'razorpay') {
            // Razorpay adapter — test or live mode driven by the key prefix
            // (rzp_test_* vs rzp_live_*). Credentials are injected here.
            $this->load->library('Payment_gateway_razorpay', [
                'api_key'    => (string) ($gwConfig['api_key']    ?? ''),
                'api_secret' => (string) ($gwConfig['api_secret'] ?? ''),
            ]);
            $adapter = $this->payment_gateway_razorpay;
        } else {
            // Default: mock adapter (dev/test with no real gateway).
            $this->load->library('Payment_gateway_mock');
            $adapter = $this->payment_gateway_mock;
        }

        $this->load->library('Payment_service');
        $this->payment_service->init(
            $this->firebase,
            $this->feesBase,
            $adapter,
            $this->admin_id ?? '',
            $this->school_name,
            $this->session_year
        );
        $this->paymentService = $this->payment_service;
    }


    /**
     * Resolve {school, session} context for a webhook by looking up the
     * order doc via its gateway_order_id. Called from the controller
     * constructor BEFORE $this->school_name / $this->session_year are
     * known, so this method must avoid any lookup that requires school
     * context (uses firestoreQuery without a schoolId filter).
     *
     * Returns ['school' => ..., 'session' => ...] on success, or null if
     * the order doc is missing or malformed. Logs errors; caller decides
     * the HTTP response shape.
     */
    private function resolveSchoolFromOrder(string $orderId): ?array
    {
        if ($orderId === '') {
            return null;
        }
        try {
            $rows = $this->firebase->firestoreQuery(
                'feeOnlineOrders',
                [['gateway_order_id', '==', $orderId]],
                null, 'ASC', 1
            );
        } catch (\Exception $e) {
            log_message('error', "resolveSchoolFromOrder: query threw for order={$orderId}: " . $e->getMessage());
            return null;
        }

        $orderDoc = null;
        foreach ((array) $rows as $row) {
            $orderDoc = is_array($row['data'] ?? null) ? $row['data'] : $row;
            break;
        }
        if (!is_array($orderDoc)) {
            return null;
        }

        $schoolName = (string) ($orderDoc['schoolId'] ?? '');
        $session    = (string) ($orderDoc['session']  ?? '');
        if ($schoolName === '') {
            return null;
        }
        // If session isn't on the order doc (old data / mock gateway), fall
        // back to the school's currently-active session.
        if ($session === '') {
            $session = $this->_resolve_active_session($schoolName);
        }
        return ['school' => $schoolName, 'session' => $session];
    }

    /**
     * Resolve the active session for a school (used by parent-app API
     * endpoints + the webhook, which have no CI session). Reads
     * schoolConfig/{schoolName}_activeSession from Firestore. Returns
     * '' if the doc is missing or malformed — callers treat '' as a
     * hard failure and reject the request.
     */
    private function _resolve_active_session(string $schoolName): string
    {
        if ($schoolName === '') return '';
        try {
            $cfg = $this->firebase->firestoreGet('schoolConfig', "{$schoolName}_activeSession");
            if (is_array($cfg) && !empty($cfg['session'])) return (string) $cfg['session'];
        } catch (\Exception $e) {
            log_message('error', "_resolve_active_session: Firestore read failed for {$schoolName}: " . $e->getMessage());
        }
        return '';
    }

    /**
     * POST — Parent-facing: create a Razorpay order for the authenticated
     * student. Authenticates via Firebase ID token (Bearer header). The
     * student_id is taken from the token's uid claim; callers do NOT
     * pass student_id, preventing cross-student access.
     *
     * Body: amount, fee_months[] (JSON or form-encoded)
     * Returns: {success, gateway_order_id, amount, api_key, provider, mode, currency, student_id}
     */
    public function parent_create_order()
    {
        header('Content-Type: application/json; charset=utf-8');

        $claims = $this->_parent_claims ?: [];
        $studentId   = (string) ($claims['uid'] ?? '');
        $studentName = '';

        // Parse body — accept JSON or form-encoded
        $raw = file_get_contents('php://input');
        $body = [];
        if ($raw !== '') {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) $body = $decoded;
        }
        if (empty($body)) $body = $this->input->post() ?: [];

        $amount    = floatval($body['amount'] ?? 0);
        $feeMonths = $body['fee_months'] ?? [];
        if (!is_array($feeMonths)) $feeMonths = [];

        if ($studentId === '') {
            http_response_code(401);
            echo json_encode(['success' => false, 'error' => 'Invalid auth token.']);
            return;
        }
        if ($amount <= 0) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Amount must be greater than zero.']);
            return;
        }
        if (empty($feeMonths)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'At least one fee month must be selected.']);
            return;
        }

        // Look up student profile to get name + class/section for the order record
        $class = '';
        $section = '';
        try {
            $studentDoc = $this->firebase->firestoreGet('students', "{$this->school_name}_{$studentId}");
            if (is_array($studentDoc)) {
                $studentName = (string) ($studentDoc['name']     ?? $studentDoc['studentName'] ?? '');
                $class       = (string) ($studentDoc['class']    ?? $studentDoc['className']   ?? '');
                $section     = (string) ($studentDoc['section']  ?? '');
            }
        } catch (\Exception $e) { /* non-fatal — order still gets created */ }

        // ── Sequencing guard: reject the order if any earlier month is
        // still unpaid/partial. Saves the parent a trip through Razorpay
        // just to hit the same block at verify time.
        if (!isset($this->fs)) {
            $this->load->library('Firestore_service', null, 'fs');
            $this->fs->init($this->school_name, $this->session_year);
        }
        $this->load->library('Fee_firestore_txn', null, 'fsTxn');
        $this->fsTxn->init($this->firebase, $this->fs, $this->school_name, $this->session_year);

        $allDemands = $this->fsTxn->demandsForStudent($studentId);
        $selectedKeys = [];
        foreach ($allDemands as $d) {
            $pk = (string) ($d['period_key'] ?? '');
            $period = Fee_firestore_txn::periodToMonth((string) ($d['period'] ?? ''));
            if ($pk !== '' && in_array($period, $feeMonths, true)) {
                $selectedKeys[] = $pk;
            }
        }
        if (!empty($selectedKeys)) {
            sort($selectedKeys);
            $earliestSelected = $selectedKeys[0];
            foreach ($allDemands as $d) {
                $pk = (string) ($d['period_key'] ?? '');
                $status = (string) ($d['status'] ?? 'unpaid');
                $bal = (float) ($d['balance'] ?? 0);
                if ($pk !== '' && $pk < $earliestSelected && $status !== 'paid' && $bal > 0.005) {
                    $olderPeriod = (string) ($d['period'] ?? $pk);
                    http_response_code(409);
                    echo json_encode([
                        'success' => false,
                        'error'   => "Please clear the earlier pending fees for {$olderPeriod} (Rs. " . number_format($bal, 2) . ") before paying this month.",
                    ]);
                    return;
                }
            }
        }

        $this->_init_payment_service();

        try {
            $result = $this->paymentService->create_order([
                'student_id'   => $studentId,
                'student_name' => $studentName,
                'class'        => $class,
                'section'      => $section,
                'amount'       => $amount,
                'fee_months'   => $feeMonths,
            ]);

            $gwConfig = $this->firebase->firestoreGet('feeSettings', "{$this->school_name}_{$this->session_year}_gateway");

            echo json_encode([
                'success'          => true,
                'existing'         => !empty($result['existing']),
                'payment_id'       => $result['payment_record_id'],
                'gateway_order_id' => $result['order_id'],
                'amount'           => $result['amount'],
                'amount_paise'     => (int) round($result['amount'] * 100),
                'currency'         => 'INR',
                'gateway'          => $result['gateway'],
                'provider'         => is_array($gwConfig) ? ($gwConfig['provider'] ?? 'mock') : 'mock',
                'api_key'          => is_array($gwConfig) ? ($gwConfig['api_key']  ?? '') : '',
                'mode'             => is_array($gwConfig) ? ($gwConfig['mode']     ?? 'test') : 'test',
                'student_id'       => $studentId,
                'student_name'     => $studentName,
                'school_id'        => $this->school_name,
            ]);
        } catch (\Exception $e) {
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
    }

    /**
     * POST — Parent-facing: verify a Razorpay payment and record fees.
     *
     * Runs the FULL receipt + demand-allocation pipeline (same shape as
     * the admin counter's submit_fees). The legacy _verify_and_process
     * writes only a bare receipt and skips demand updates, so we do our
     * own flow here.
     *
     * Body: razorpay_order_id, razorpay_payment_id, razorpay_signature
     */
    public function parent_verify_payment()
    {
        header('Content-Type: application/json; charset=utf-8');

        $raw = file_get_contents('php://input');
        $body = [];
        if ($raw !== '') {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) $body = $decoded;
        }
        if (empty($body)) $body = $this->input->post() ?: [];

        $gwOrderId   = trim((string) ($body['razorpay_order_id']   ?? $body['gateway_order_id']   ?? ''));
        $gwPaymentId = trim((string) ($body['razorpay_payment_id'] ?? $body['gateway_payment_id'] ?? ''));
        $signature   = trim((string) ($body['razorpay_signature']  ?? $body['signature']          ?? ''));

        if ($gwOrderId === '' || $gwPaymentId === '') {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Missing order_id or payment_id.']);
            return;
        }

        $claims    = $this->_parent_claims ?: [];
        $studentId = (string) ($claims['uid'] ?? '');
        if ($studentId === '') {
            http_response_code(401);
            echo json_encode(['success' => false, 'error' => 'Invalid auth token.']);
            return;
        }

        log_message('debug', "[VERIFY START] order={$gwOrderId} payment={$gwPaymentId} student={$studentId}");
        $_verifyT0 = microtime(true);

        // ── Idempotency check FIRST: have we already processed this exact
        // razorpay_payment_id? Razorpay can send the same payment_id on
        // network retries; without this lookup we'd re-run the receipt
        // pipeline and double-credit the demands. The previous version
        // checked feeOnlineOrders.status='paid' which left a window where
        // the order was marked paid but Firestore receipt/defaulter sync
        // had failed — the retry would early-return success but leave
        // stale dues forever. The payment-id lookup catches both cases:
        // (a) genuine retry of a fully-completed payment → return cached
        //     receipt info; (b) retry of a partially-completed payment →
        //     marker is missing, fall through and re-process.
        if (!isset($this->fs)) {
            $this->load->library('Firestore_service', null, 'fs');
            $this->fs->init($this->school_name, $this->session_year);
        }
        $this->load->library('Fee_firestore_txn', null, 'fsTxn');
        $this->fsTxn->init($this->firebase, $this->fs, $this->school_name, $this->session_year);

        $alreadyProcessed = $this->fsTxn->getProcessedPayment($gwPaymentId);
        if (is_array($alreadyProcessed)) {
            // Cross-student guard on the cached marker too — a leaked
            // payment_id from one parent shouldn't return another's
            // receipt.
            if (($alreadyProcessed['studentId'] ?? '') !== $studentId) {
                http_response_code(403);
                echo json_encode(['success' => false, 'error' => 'Payment does not belong to this student.']);
                return;
            }
            log_message('debug', "[VERIFY IDEMP HIT] payment={$gwPaymentId} cached_receipt=" . ($alreadyProcessed['receiptNo'] ?? ''));
            echo json_encode([
                'success'     => true,
                'already_paid'=> true,
                'message'     => 'Payment already verified.',
                'receipt_no'  => (string) ($alreadyProcessed['receiptNo'] ?? ''),
                'receipt_key' => (string) ($alreadyProcessed['receiptKey'] ?? ''),
            ]);
            return;
        }

        // ── Look up our order record (Firestore feeOnlineOrders) ────────
        $orderDocId = "{$this->school_name}_{$gwOrderId}";
        try {
            $order = $this->firebase->firestoreGet('feeOnlineOrders', $orderDocId);
        } catch (\Exception $e) {
            $order = null;
        }
        if (!is_array($order)) {
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => 'Order not found.']);
            return;
        }

        // Cross-student guard
        if (($order['student_id'] ?? '') !== $studentId) {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'Order does not belong to this student.']);
            return;
        }

        // ── Verify Razorpay signature ────────────────────────────────
        $this->_init_payment_service();
        $verifyResult = $this->paymentService->verify_payment($gwOrderId, $gwPaymentId, $signature);
        if (empty($verifyResult['verified'])) {
            log_message('error', "[VERIFY SIG FAIL] payment={$gwPaymentId} reason=" . ($verifyResult['error'] ?? 'unknown'));
            http_response_code(422);
            echo json_encode(['success' => false, 'error' => $verifyResult['error'] ?? 'Signature verification failed.']);
            return;
        }
        log_message('debug', "[VERIFY SIG OK] payment={$gwPaymentId} elapsed=" . round((microtime(true) - $_verifyT0) * 1000) . "ms");

        // Signature is valid — now do the full fee allocation pipeline.
        $feeMonths = is_array($order['fee_months'] ?? null) ? $order['fee_months'] : [];
        $paidAmount = round((float) ($order['amount'] ?? 0), 2);

        // ── Delegate receipt pipeline to FeeCollectionService (P3) ────
        // Upstream of this point we've already done auth, dedup,
        // signature verify, amount re-derivation from the stored order,
        // cross-student guard. The service runs the (now-shared)
        // reserveReceipt → PAY-OLDER-FIRST → allocate → batch-commit →
        // wallet-credit → release-lock sequence. Razorpay-specific
        // post-processing (markPaymentProcessed, order.paid, pending
        // fallback) stays here so the service never has to know about
        // feeProcessedPayments or feeOnlineOrders.
        require_once APPPATH . 'services/FeeCollectionService.php';
        $service = new FeeCollectionService();
        $result = $service->submit($this, [
            'source'                     => 'parent-razorpay',
            'admin_id'                   => 'parent_app',
            'admin_name'                 => 'parent_app',
            'school_name'                => $this->school_name,
            'session_year'               => $this->session_year,
            'safe_user_id'               => $studentId,
            'amount'                     => $paidAmount,
            'discount'                   => 0.0,
            'fine'                       => 0.0,
            'submit_amount'              => 0.0,
            'selected_months'            => $feeMonths,
            'payment_mode'               => 'Online - Razorpay',
            'remarks'                    => "Razorpay {$gwPaymentId}",
            'collected_by'               => 'parent_app',
            'created_by'                 => 'parent_app',
            // Preserve Razorpay's 6-hex txn_id format byte-for-byte.
            'txn_id'                     => 'RZP_' . date('YmdHis') . '_' . substr(bin2hex(random_bytes(3)), 0, 6),
            'batch_mode'                 => true,
            'wallet_mode'                => 'credit_overflow',
            // Razorpay's parent_create_order enforces PAY-OLDER-FIRST
            // upstream, and the service re-runs it (belt-and-braces,
            // matching _record_parent_payment_receipt L2417-2442). So
            // leave skip_pay_older_first_guard at its default (false).
            // Pre-P3, _record_parent_payment_receipt did NOT run a
            // duplicate-month guard — the allocation loop would just
            // skip paid demands and push the overflow to wallet. Keep
            // that behaviour.
            'skip_duplicate_month_guard' => true,
            'defer_defaulter'            => true,
            'defer_accounting'           => false,
            'write_response_to_output'   => false,
        ]);

        // Service contract: returns an array. ok=true + data{} on success,
        // ok=false + message + http_code on any failure past the shim's
        // upstream validation. A null return indicates an internal bug.
        if (!is_array($result) || !($result['ok'] ?? false)) {
            $errMsg = is_array($result) ? (string) ($result['message'] ?? 'Payment processing failed.') : 'Internal error (null service result).';
            // Razorpay has already captured the money but our receipt
            // pipeline failed (Firestore quota, transient outage, etc.).
            // Drop a feePendingWrites marker so the admin reconciliation
            // cron picks it up and replays the receipt write later, and
            // tell the parent app to render a soft "syncing" banner
            // instead of a red error — the payment is safe.
            try {
                $this->firebase->firestoreSet('feePendingWrites',
                    "{$this->school_name}_pay_{$gwPaymentId}",
                    [
                        'schoolId'    => $this->school_name,
                        'session'     => $this->session_year,
                        'studentId'   => $studentId,
                        'paymentId'   => $gwPaymentId,
                        'orderId'     => $gwOrderId,
                        'amount'      => $paidAmount,
                        'feeMonths'   => $feeMonths,
                        'status'      => 'pending',
                        'lastError'   => $errMsg,
                        'failedAt'    => date('c'),
                        'updatedAt'   => date('c'),
                    ]
                );
            } catch (\Exception $e) {
                log_message('error', "parent_verify_payment: failed to mark pending [{$gwPaymentId}]: " . $e->getMessage());
            }
            http_response_code(202); // Accepted — being processed
            echo json_encode([
                'success' => false,
                'pending' => true,
                'error'   => 'Payment received and being processed. Please refresh in a minute.',
                'details' => $errMsg,
            ]);
            return;
        }

        $resultData  = $result['data'] ?? [];
        $receiptNo   = (string) ($resultData['receipt_no']  ?? '');
        $receiptKey  = (string) ($resultData['receipt_key'] ?? '');
        $allocMonths = $resultData['allocated_months'] ?? [];

        // Mark the gateway payment_id as processed so any retry from
        // Razorpay (network blip, app reinstall, etc.) short-circuits at
        // the top of this function instead of re-running the receipt
        // pipeline. Tied to the receipt the processing produced so the
        // retry response matches the original.
        try {
            $this->fsTxn->markPaymentProcessed(
                $gwPaymentId,
                $receiptNo,
                $receiptKey,
                $studentId
            );
        } catch (\Exception $e) {
            log_message('error', "parent_verify_payment: markPaymentProcessed failed [{$gwPaymentId}]: " . $e->getMessage());
        }

        // Mark the order as paid in Firestore for human-readable order
        // history. The idempotency check above no longer relies on this
        // field, but admin reports still use it.
        try {
            $patched = array_merge($order, [
                'status'             => 'paid',
                'paid_at'            => date('c'),
                'gateway_payment_id' => $gwPaymentId,
                'signature'          => $signature,
                'receipt_key'        => $receiptKey,
                'receipt_no'         => $receiptNo,
            ]);
            $this->firebase->firestoreSet('feeOnlineOrders', $orderDocId, $patched);
        } catch (\Exception $e) {
            log_message('error', "parent_verify_payment: order status update failed [{$orderDocId}]: " . $e->getMessage());
        }

        echo json_encode([
            'success'          => true,
            'message'          => 'Payment verified and fees recorded.',
            'receipt_no'       => $receiptNo,
            'receipt_key'      => $receiptKey,
            'amount_paid'      => $paidAmount,
            'allocated_months' => $allocMonths,
        ]);
    }

    /**
     * POST — Parent-facing: pay fees from the student's advance wallet
     * balance (no gateway). Requires wallet >= total_due for the
     * selected months. Mirrors the allocation logic of submit_fees()
     * but drives the whole receipt from wallet, not new cash.
     *
     * Body: fee_months[] (JSON)
     * Returns: {success, receipt_no, wallet_before, wallet_after, allocated_months[]}
     */
    public function parent_pay_from_wallet()
    {
        header('Content-Type: application/json; charset=utf-8');

        $claims    = $this->_parent_claims ?: [];
        $studentId = (string) ($claims['uid'] ?? '');
        if ($studentId === '') {
            http_response_code(401);
            echo json_encode(['success' => false, 'error' => 'Invalid auth token.']);
            return;
        }

        $raw = file_get_contents('php://input');
        $body = [];
        if ($raw !== '') {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) $body = $decoded;
        }
        if (empty($body)) $body = $this->input->post() ?: [];

        $feeMonths = $body['fee_months'] ?? [];
        if (!is_array($feeMonths)) $feeMonths = [];
        $feeMonths = array_values(array_filter(array_map('trim', $feeMonths)));
        if (empty($feeMonths)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'At least one fee month must be selected.']);
            return;
        }

        // ── Initialise Firestore_service + Fee_firestore_txn helper ───
        // MY_Controller normally wires $this->fs; parent endpoints
        // bypass MY_Controller so we load Firestore_service manually.
        if (!isset($this->fs)) {
            $this->load->library('Firestore_service', null, 'fs');
            $this->fs->init($this->school_name, $this->session_year);
        }
        $this->load->library('Fee_firestore_txn', null, 'fsTxn');
        $this->fsTxn->init($this->firebase, $this->fs, $this->school_name, $this->session_year);

        // ── Pre-service: load student, compute totalDue, validate wallet ─
        // These checks stay in the controller because they determine
        // whether the wallet path should even reach the service. The
        // service will re-load demands itself (cost is ~1 extra read
        // per request; acceptable vs the complexity of passing demands
        // in via $data).
        $student = $this->fsTxn->getStudent($studentId);
        if (!$student) {
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => 'Student not found.']);
            return;
        }

        $demands     = $this->fsTxn->demandsForStudent($studentId);
        $selectedSet = array_flip($feeMonths);
        $totalDue    = 0.0;
        foreach ($demands as $d) {
            $status = (string) ($d['status'] ?? 'unpaid');
            $period = Fee_firestore_txn::periodToMonth((string) ($d['period'] ?? ''));
            if ($period === '') continue;
            if ($status === 'paid') continue;
            if (!isset($selectedSet[$period])) continue;
            $totalDue += (float) ($d['balance'] ?? 0);
        }
        $totalDue = round($totalDue, 2);
        if ($totalDue <= 0.005) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Nothing is due for the selected months.']);
            return;
        }

        $walletBefore = (float) $this->fsTxn->getAdvanceBalance($studentId);
        if ($walletBefore + 0.005 < $totalDue) {
            http_response_code(402);
            echo json_encode([
                'success'        => false,
                'error'          => 'Insufficient wallet balance.',
                'wallet_balance' => $walletBefore,
                'total_due'      => $totalDue,
                'short_by'       => round($totalDue - $walletBefore, 2),
            ]);
            return;
        }

        // ── Delegate to FeeCollectionService ───────────────────────────
        require_once APPPATH . 'services/FeeCollectionService.php';
        $service = new FeeCollectionService();
        $result  = $service->submit($this, [
            'source'                     => 'parent-wallet',
            'admin_id'                   => 'parent_app',
            'admin_name'                 => 'parent_app',
            'school_name'                => $this->school_name,
            'session_year'               => $this->session_year,
            'safe_user_id'               => $studentId,
            'amount'                     => $totalDue,
            'discount'                   => 0.0,
            'fine'                       => 0.0,
            'submit_amount'              => 0.0,
            'selected_months'            => $feeMonths,
            'payment_mode'               => 'Wallet',
            'remarks'                    => 'Paid from advance balance (parent app)',
            'collected_by'               => 'parent_app',
            'created_by'                 => 'parent_app',
            'txn_prefix'                 => 'WAL_',
            'wallet_mode'                => 'debit_total',
            'wallet_before'              => $walletBefore,
            // Wallet now enforces PAY-OLDER-FIRST like the counter and
            // Razorpay paths — a parent can no longer pay June from the
            // wallet while April still has an unpaid balance. The service
            // will return HTTP 409 with a message naming the oldest
            // outstanding period so the app can surface it cleanly.
            'skip_pay_older_first_guard' => false,
            'defer_defaulter'            => false,
            'defer_accounting'           => false,
            'write_response_to_output'   => false,
        ]);

        // ── Translate service result into wallet response shape ───────
        if (!is_array($result)) {
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => 'Internal error (null result from service).']);
            return;
        }
        if (!($result['ok'] ?? false)) {
            http_response_code((int) ($result['http_code'] ?? 500));
            echo json_encode(['success' => false, 'error' => (string) ($result['message'] ?? 'Payment failed.')]);
            return;
        }

        $d = $result['data'] ?? [];

        // ── Synchronous defaulter sync (preserves wallet's original timing) ─
        // Recompute defaulter status from Firestore demands and sync to
        // feeDefaulters so the parent app's "Outstanding ₹X" banner clears
        // as soon as the wallet payment lands.
        try {
            $this->feeDefaulter->updateDefaulterStatus($studentId);
        } catch (\Exception $e) {
            log_message('error', "parent_pay_from_wallet: defaulter sync failed [{$studentId}]: " . $e->getMessage());
        }

        echo json_encode([
            'success'          => true,
            'message'          => 'Fees paid from wallet.',
            'receipt_no'       => (string) ($d['receipt_no']  ?? ''),
            'receipt_key'      => (string) ($d['receipt_key'] ?? ''),
            'wallet_before'    => round($walletBefore, 2),
            'wallet_after'     => isset($d['wallet_after']) ? (float) $d['wallet_after'] : null,
            'amount_paid'      => isset($d['amount_paid']) ? (float) $d['amount_paid'] : $totalDue,
            'allocated_months' => $d['allocated_months'] ?? [],
        ]);
    }

    // ══════════════════════════════════════════════════════════════════
    //  DUES-BASED BLOCKING POLICY (result / TC / hall-ticket / library)
    // ══════════════════════════════════════════════════════════════════

    /**
     * GET — current blocking policy for the school+session.
     * Used by the Defaulter Report's "Blocking" settings card.
     */
    public function get_blocking_policy()
    {
        $this->_require_role(self::ADMIN_ROLES, 'get_blocking_policy');
        $this->load->library('Fee_dues_check', null, 'duesCheck');
        $this->duesCheck->init($this->firebase, $this->school_name, $this->session_year);
        $this->json_success(['policy' => $this->duesCheck->getPolicy()]);
    }

    /**
     * POST — save blocking policy. Params: block_result, block_tc,
     * block_hall_ticket, block_library, threshold_amount,
     * admin_override_allowed (all optional; missing = keep false).
     */
    public function save_blocking_policy()
    {
        $this->_require_role(self::ADMIN_ROLES, 'save_blocking_policy');
        $this->load->library('Fee_dues_check', null, 'duesCheck');
        $this->duesCheck->init($this->firebase, $this->school_name, $this->session_year);

        $payload = [
            'block_result'           => $this->input->post('block_result')           === '1',
            'block_tc'               => $this->input->post('block_tc')               === '1',
            'block_hall_ticket'      => $this->input->post('block_hall_ticket')      === '1',
            'block_library'          => $this->input->post('block_library')          === '1',
            'threshold_amount'       => (float) $this->input->post('threshold_amount'),
            'admin_override_allowed' => $this->input->post('admin_override_allowed') === '1',
        ];

        $ok = $this->duesCheck->savePolicy($payload);
        if (!$ok) {
            $this->json_error('Failed to save blocking policy.');
            return;
        }
        log_audit('Fees', 'save_blocking_policy', $this->school_name,
            "Updated dues-blocking policy: " . json_encode($payload));
        $this->json_success([
            'message' => 'Blocking policy saved.',
            'policy'  => $payload,
        ]);
    }

    /**
     * POST — Create a payment order.
     * Uses the Payment Service (gateway-agnostic).
     */
    public function create_payment_order()
    {
        $this->_require_role(self::FINANCE_ROLES, 'create_payment_order');

        $this->_init_payment_service();

        try {
            $result = $this->paymentService->create_order([
                'student_id'   => trim($this->input->post('student_id') ?? ''),
                'student_name' => trim($this->input->post('student_name') ?? ''),
                'class'        => trim($this->input->post('class') ?? ''),
                'section'      => trim($this->input->post('section') ?? ''),
                'amount'       => floatval($this->input->post('amount') ?? 0),
                'fee_months'   => $this->input->post('fee_months') ?? [],
            ]);

            $gwConfig = $this->firebase->firestoreGet('feeSettings', "{$this->school_name}_{$this->session_year}_gateway");

            $this->json_success([
                'message'          => $result['existing'] ? 'Existing order returned.' : 'Payment order created.',
                'payment_id'       => $result['payment_record_id'],
                'gateway_order_id' => $result['order_id'],
                'amount'           => $result['amount'],
                'gateway'          => $result['gateway'],
                'provider'         => is_array($gwConfig) ? ($gwConfig['provider'] ?? 'mock') : 'mock',
                'api_key'          => is_array($gwConfig) ? ($gwConfig['api_key'] ?? '') : '',
                'mode'             => is_array($gwConfig) ? ($gwConfig['mode'] ?? 'test') : 'test',
            ]);
        } catch (\Exception $e) {
            $this->json_error($e->getMessage());
        }
    }

    /**
     * POST — Simulate a payment (DEV/TEST mode only).
     * Calls the mock gateway to simulate success/failure.
     */
    public function simulate_payment()
    {
        $this->_require_role(self::FINANCE_ROLES, 'simulate_payment');

        $gwConfig = $this->firebase->firestoreGet('feeSettings', "{$this->school_name}_{$this->session_year}_gateway");
        $mode = is_array($gwConfig) ? ($gwConfig['mode'] ?? 'test') : 'test';
        if ($mode === 'live') {
            $this->json_error('Simulation is not available in live mode.');
        }

        $this->_init_payment_service();

        $orderId = trim($this->input->post('order_id') ?? '');
        if ($orderId === '') {
            $this->json_error('Order ID is required.');
        }

        try {
            $result = $this->paymentService->simulate($orderId);
            $this->json_success($result);
        } catch (\Exception $e) {
            $this->json_error($e->getMessage());
        }
    }

    /**
     * POST — Verify payment and process fee collection atomically.
     *
     * Status lifecycle: created → processing → paid | fees_failed
     *
     * The order is NOT marked "paid" until all fee writes succeed.
     * If fee processing fails, the order stays in "fees_failed" state
     * and can be retried via retry_payment_processing().
     */
    public function verify_payment()
    {
        $this->_require_role(self::FINANCE_ROLES, 'verify_payment');

        $gwOrderId   = trim($this->input->post('gateway_order_id') ?? '');
        $gwPaymentId = trim($this->input->post('gateway_payment_id') ?? '');
        $signature   = trim($this->input->post('signature') ?? '');

        if ($gwOrderId === '') $this->json_error('Gateway order ID is required.');
        if ($gwPaymentId === '') $this->json_error('Gateway payment ID is required.');

        $result = $this->_verify_and_process($gwOrderId, $gwPaymentId, $signature, 'frontend');

        if (!$result['ok']) {
            $this->json_error($result['error']);
        }
        if (!empty($result['already_paid'])) {
            $this->json_success(['message' => 'Payment already verified.', 'already_paid' => true]);
            return;
        }
        if ($result['fee_success']) {
            $this->json_success([
                'message'    => 'Payment verified and fees recorded.',
                'receipt_no' => str_replace('F', '', $result['receipt_key'] ?? ''),
            ]);
        } else {
            $this->json_error($result['fee_error'] ?? 'Fee processing failed. Retry from Transaction Audit.');
        }
    }

    /**
     * POST — Server-to-server webhook for payment gateway callbacks.
     *
     * Does NOT require admin session (gateway calls this directly).
     * Verifies authenticity using HMAC signature from the webhook secret.
     *
     * Razorpay sends: razorpay_order_id, razorpay_payment_id, razorpay_signature
     * Mock sends: order_id, payment_id, signature
     */
    public function payment_webhook()
    {
        header('Content-Type: application/json');
        $ip = $_SERVER['REMOTE_ADDR'] ?? '';

        // ── 0. IP Whitelist ──
        // Razorpay webhook IPs + localhost for dev. Configurable via Gateway Config.
        $gwConfig      = $this->firebase->firestoreGet('feeSettings', "{$this->school_name}_{$this->session_year}_gateway");
        $allowedIPs    = is_array($gwConfig) ? ($gwConfig['webhook_allowed_ips'] ?? []) : [];
        $defaultIPs    = ['52.66.166.11', '52.66.171.94', '3.7.116.0/24', '127.0.0.1', '::1'];
        $allAllowed    = array_merge($defaultIPs, is_array($allowedIPs) ? $allowedIPs : []);

        $ipAllowed = false;
        foreach ($allAllowed as $allowed) {
            if ($allowed === $ip) { $ipAllowed = true; break; }
            // CIDR check
            if (strpos($allowed, '/') !== false && $this->_ipInCidr($ip, $allowed)) { $ipAllowed = true; break; }
        }

        $gwMode = is_array($gwConfig) ? ($gwConfig['mode'] ?? '') : '';
        if (!$ipAllowed && $gwMode !== 'mock') {
            log_message('error', "payment_webhook: IP BLOCKED ip={$ip}");
            http_response_code(403);
            echo json_encode(['status' => 'error', 'message' => 'IP not whitelisted.']);
            return;
        }

        // Prefer the body the constructor already cached — php://input is
        // not reliably rewindable across every PHP SAPI configuration, and
        // the constructor had to read it first to resolve school context.
        $rawBody = $this->_webhookRawBody ?? (string) file_get_contents('php://input');
        $payload = json_decode($rawBody, true);
        if (!is_array($payload)) $payload = $this->input->post() ?: [];

        // ── 1. Timestamp replay protection (5 min window) ──
        $webhookTs = $_SERVER['HTTP_X_WEBHOOK_TIMESTAMP']
            ?? $_SERVER['HTTP_X_RAZORPAY_TIMESTAMP']
            ?? ($payload['timestamp'] ?? '');
        if ($webhookTs !== '') {
            $tsAge = abs(time() - (int) $webhookTs);
            if ($tsAge > 300) {
                log_message('error', "payment_webhook: REPLAY REJECTED age={$tsAge}s ip={$ip}");
                http_response_code(401);
                echo json_encode(['status' => 'error', 'message' => 'Webhook expired (replay protection).']);
                return;
            }
        }

        // ── 2. HMAC signature verification ──
        //
        // Policy:
        //   • live mode  — webhook_secret MUST be configured AND the incoming
        //                  request MUST carry a signature that matches. Anything
        //                  else rejects. This is the hardening pass.
        //   • test/mock  — HMAC is enforced when the secret is configured, but
        //                  a missing secret or missing signature is allowed so
        //                  developer environments without a real Razorpay
        //                  webhook secret can still simulate webhooks.
        $webhookSecret = is_array($gwConfig) ? ($gwConfig['webhook_secret'] ?? '') : '';
        $webhookSig = $_SERVER['HTTP_X_RAZORPAY_SIGNATURE']
            ?? $_SERVER['HTTP_X_WEBHOOK_SIGNATURE']
            ?? ($payload['webhook_signature'] ?? '');

        if ($gwMode === 'live' && $webhookSecret === '') {
            log_message('error', "payment_webhook: LIVE mode but webhook_secret is not configured (school={$this->school_name}, session={$this->session_year}). Reconfigure gateway settings with the Razorpay webhook secret.");
            http_response_code(500);
            echo json_encode(['status' => 'error', 'message' => 'Webhook secret not configured for this school.']);
            return;
        }
        if ($gwMode === 'live' && $webhookSig === '') {
            log_message('error', "payment_webhook: LIVE mode but incoming request has no signature ip={$ip}");
            http_response_code(401);
            echo json_encode(['status' => 'error', 'message' => 'Missing webhook signature.']);
            return;
        }

        if ($webhookSecret !== '' && $webhookSig !== '') {
            $expectedSig = hash_hmac('sha256', $rawBody, $webhookSecret);
            if (!hash_equals($expectedSig, $webhookSig)) {
                log_message('error', "payment_webhook: HMAC FAILED ip={$ip}");
                http_response_code(401);
                echo json_encode(['status' => 'error', 'message' => 'Invalid webhook signature.']);
                return;
            }
        } elseif ($webhookSecret === '') {
            // Logged once per request so misconfiguration in test mode is
            // visible without rejecting the request outright.
            log_message('warning', "payment_webhook: webhook_secret empty — HMAC check skipped (mode={$gwMode}, school={$this->school_name})");
        }

        // ── 3. Event-ID dedup (replayed webhook protection) ──
        $eventId = $_SERVER['HTTP_X_RAZORPAY_EVENT_ID']
            ?? ($payload['event_id'] ?? '');
        $payloadHash = $eventId !== '' ? md5($eventId) : md5($rawBody);
        $dedupDocId  = "{$this->school_name}_{$payloadHash}";
        $existing    = $this->firebase->firestoreGet('feeIdempotency', $dedupDocId);
        if (is_array($existing) && !empty($existing['processed_at'])) {
            // Already processed this exact payload — return 200 (idempotent)
            http_response_code(200);
            echo json_encode(['status' => 'success', 'message' => 'Already processed (duplicate webhook).']);
            return;
        }

        // Normalize field names
        $gwOrderId   = $payload['razorpay_order_id']   ?? $payload['order_id']   ?? '';
        $gwPaymentId = $payload['razorpay_payment_id'] ?? $payload['payment_id'] ?? '';
        $signature   = $payload['razorpay_signature']  ?? $payload['signature']  ?? '';

        if ($gwOrderId === '' || $gwPaymentId === '') {
            http_response_code(400);
            echo json_encode(['status' => 'error', 'message' => 'Missing order_id or payment_id.']);
            return;
        }

        // ── 4. Log webhook receipt ──
        $webhookLogId = "{$this->school_name}_" . date('YmdHis') . '_' . substr($payloadHash, 0, 8);
        $this->firebase->firestoreSet('feeOnlinePayments', $webhookLogId, [
            'schoolId'      => $this->school_name,
            'docType'       => 'webhook_log',
            'order_id'      => $gwOrderId,
            'payment_id'    => $gwPaymentId,
            'payload_hash'  => $payloadHash,
            'ip'            => $_SERVER['REMOTE_ADDR'] ?? '',
            'received_at'   => date('c'),
            'webhook_sig'   => substr($webhookSig, 0, 16) . '...',
        ]);

        // ── 5. Process using unified flow ──
        $result = $this->_verify_and_process($gwOrderId, $gwPaymentId, $signature, 'webhook');

        // ── 6. Mark webhook as processed (dedup for future replays) ──
        $this->firebase->firestoreSet('feeIdempotency', $dedupDocId, [
            'schoolId'     => $this->school_name,
            'order_id'     => $gwOrderId,
            'payment_id'   => $gwPaymentId,
            'processed_at' => date('c'),
            'result'       => $result['fee_success'] ?? false ? 'success' : ($result['already_paid'] ?? false ? 'already_paid' : 'failed'),
        ]);

        // ── 7. Update payment intent status if this was a mobile-initiated payment ──
        if ($result['ok'] && !empty($result['fee_success'])) {
            try {
                if ($gwOrderId !== '') {
                    // Query Firestore for matching payment intent
                    $intents = $this->firebase->firestoreQuery('feeOnlineOrders', [
                        ['schoolId',         '==', $this->school_name],
                        ['gateway_order_id', '==', $gwOrderId],
                        ['docType',          '==', 'payment_intent'],
                    ], null, 'ASC', 1);
                    foreach ((array) $intents as $row) {
                        $intentDocId = $row['id'] ?? '';
                        if ($intentDocId !== '') {
                            $this->firebase->firestoreSet('feeOnlineOrders', $intentDocId, [
                                'status'       => 'completed',
                                'completed_at' => date('c'),
                                'receipt_no'   => $result['receipt_key'] ?? '',
                            ], true);
                        }
                        break;
                    }
                }
            } catch (Exception $e) {
                log_message('error', "Payment intent update in webhook failed: " . $e->getMessage());
            }

            // ── 8. Update defaulter status after payment ──
            try {
                // Look up the order to get student_id
                $orderRows = $this->firebase->firestoreQuery('feeOnlineOrders', [
                    ['schoolId',         '==', $this->school_name],
                    ['gateway_order_id', '==', $gwOrderId],
                ], null, 'ASC', 1);
                foreach ((array) $orderRows as $row) {
                    $ordData = $row['data'] ?? $row;
                    $studentId = (string) ($ordData['student_id'] ?? $ordData['studentId'] ?? '');
                    if ($studentId !== '') {
                        $this->feeDefaulter->updateDefaulterStatus($studentId);
                    }
                    break;
                }
            } catch (Exception $e) {
                log_message('error', "Defaulter status update in webhook failed: " . $e->getMessage());
            }
        }

        if (!$result['ok'] && empty($result['already_paid'])) {
            http_response_code(422);
            echo json_encode(['status' => 'error', 'message' => $result['error'] ?? 'Processing failed.']);
            return;
        }

        http_response_code(200);
        echo json_encode([
            'status'      => 'success',
            'message'     => !empty($result['already_paid']) ? 'Already processed.' : 'Payment processed.',
            'receipt_key' => $result['receipt_key'] ?? '',
        ]);
    }

    /**
     * UNIFIED: Verify gateway signature → process fees → mark paid.
     *
     * Called by BOTH verify_payment (frontend) AND payment_webhook (server).
     * Single source of truth for the payment → fees flow.
     *
     * @param  string $gwOrderId    Gateway order ID
     * @param  string $gwPaymentId  Gateway payment ID
     * @param  string $signature    Payment signature
     * @param  string $source       'frontend' or 'webhook'
     * @return array  {ok, already_paid, fee_success, receipt_key, error, fee_error}
     */
    private function _verify_and_process(string $gwOrderId, string $gwPaymentId, string $signature, string $source): array
    {
        $this->_init_payment_service();
        $lockToken = bin2hex(random_bytes(8));
        $lockDocId = "{$this->school_name}_{$gwOrderId}";

        // ── A. Order-level lock (prevents webhook + frontend double-execute) ──
        $existingLock = $this->firebase->firestoreGet('feeLocks', $lockDocId);
        if (is_array($existingLock) && !empty($existingLock['locked'])) {
            $lockAge = time() - strtotime($existingLock['locked_at'] ?? '2000-01-01');
            if ($lockAge < 120) {
                return ['ok' => false, 'error' => 'This payment is currently being processed. Please wait.'];
            }
        }
        $this->firebase->firestoreSet('feeLocks', $lockDocId, [
            'schoolId' => $this->school_name, 'locked' => true, 'locked_at' => date('c'), 'token' => $lockToken, 'source' => $source,
        ]);

        // Helper: release lock only if we own it
        $releaseLock = function () use ($lockDocId, $lockToken) {
            try {
                $l = $this->firebase->firestoreGet('feeLocks', $lockDocId);
                if (is_array($l) && ($l['token'] ?? '') === $lockToken) {
                    $this->firebase->firestoreDelete('feeLocks', $lockDocId);
                }
            } catch (\Exception $e) { /* best effort */ }
        };

        // ── B. Verify signature via Payment Service ──
        $verifyResult = $this->paymentService->verify_payment($gwOrderId, $gwPaymentId, $signature);

        if (!$verifyResult['verified']) {
            $releaseLock();
            return ['ok' => false, 'error' => $verifyResult['error'] ?? 'Verification failed.'];
        }
        if (!empty($verifyResult['already_paid'])) {
            $releaseLock();
            return ['ok' => true, 'already_paid' => true];
        }

        $recordId  = $verifyResult['record_id'];
        $order     = $verifyResult['order'];
        $orderDocId = "{$this->school_name}_{$recordId}";

        // ── C. Strict amount validation ──
        // Prevents tampered callbacks claiming different amounts.
        $orderAmount = floatval($order['amount'] ?? 0);
        $gwReportedAmount = floatval($verifyResult['gateway_amount'] ?? $orderAmount);
        if (abs($orderAmount - $gwReportedAmount) > 0.50) {
            log_message('error', "verify_and_process: AMOUNT MISMATCH order={$gwOrderId} expected={$orderAmount} got={$gwReportedAmount}");
            $releaseLock();
            $this->firebase->firestoreSet('feeOnlineOrders', $orderDocId, [
                'status' => 'amount_mismatch',
                'expected_amount' => $orderAmount,
                'gateway_amount' => $gwReportedAmount,
                'flagged_at' => date('c'),
            ], true);
            return ['ok' => false, 'error' => 'Payment amount does not match order amount.'];
        }

        // ── D. Transition: verified → processing ──
        $now = date('c');
        $this->firebase->firestoreSet('feeOnlineOrders', $orderDocId, [
            'status'              => 'processing',
            'processing_started'  => $now,
            'gateway_payment_id'  => $gwPaymentId,
            'process_source'      => $source,
            'process_lock_token'  => $lockToken,
            'webhook_received_at' => ($source === 'webhook') ? $now : null,
        ], true);

        // ── E. Process fee collection via FeeCollectionService (P3.C) ──
        // Same receipt/allocation/monthFee/wallet pipeline as
        // parent_verify_payment (P3.B), just with the webhook's auth
        // chain upstream. Razorpay-specific post-processing
        // (markPaymentProcessed, order status, feeOnlinePayments) stays
        // here — the service never has to know about gateway state.
        require_once APPPATH . 'services/FeeCollectionService.php';
        $service   = new FeeCollectionService();
        $feeMonths = is_array($order['fee_months'] ?? null) ? $order['fee_months'] : [];
        $studentId = (string) ($order['student_id'] ?? '');

        $feeSuccess = false;
        $receiptKey = '';
        $receiptNo  = '';
        $feeError   = '';

        try {
            $serviceResult = $service->submit($this, [
                'source'                     => 'parent-razorpay-' . $source,  // 'parent-razorpay-frontend' or 'parent-razorpay-webhook'
                'admin_id'                   => 'parent_app',
                'admin_name'                 => 'parent_app',
                'school_name'                => $this->school_name,
                'session_year'               => $this->session_year,
                'safe_user_id'               => $studentId,
                'amount'                     => (float) ($order['amount'] ?? 0),
                'discount'                   => 0.0,
                'fine'                       => 0.0,
                'submit_amount'              => 0.0,
                'selected_months'            => $feeMonths,
                'payment_mode'               => 'Online - Razorpay',
                'remarks'                    => "Razorpay {$gwPaymentId}",
                'collected_by'               => 'parent_app',
                'created_by'                 => 'parent_app',
                // Preserve Razorpay's 6-hex txn_id format byte-exact with P3.B.
                'txn_id'                     => 'RZP_' . date('YmdHis') . '_' . substr(bin2hex(random_bytes(3)), 0, 6),
                'batch_mode'                 => true,
                'wallet_mode'                => 'credit_overflow',
                'skip_duplicate_month_guard' => true,
                // Keep PAY-OLDER-FIRST active (was enforced by
                // _record_parent_payment_receipt pre-P3.B; service re-runs it).
                'defer_defaulter'            => true,
                'defer_accounting'           => true,  // Q4(i): accounting now runs via service's deferred handler (was inline in _process_online_fee_collection).
                'write_response_to_output'   => false,
                // Preserve the webhook-era receipt fields the parent app
                // relies on (Q3-A); these flat fields only appear on
                // Razorpay-recorded receipts.
                'extra_receipt_fields'       => [
                    'orderId'   => $gwOrderId,
                    'paymentId' => $gwPaymentId,
                ],
            ]);

            if (is_array($serviceResult) && ($serviceResult['ok'] ?? false)) {
                $feeSuccess = true;
                $receiptKey = (string) ($serviceResult['data']['receipt_key'] ?? '');
                $receiptNo  = (string) ($serviceResult['data']['receipt_no']  ?? '');
            } else {
                $feeError = is_array($serviceResult) ? (string) ($serviceResult['message'] ?? 'Service returned failure.') : 'Null service result.';
                log_message('error', "verify_and_process({$source}): service FAILED order={$gwOrderId}: {$feeError}");
            }
        } catch (\Exception $e) {
            $feeError = $e->getMessage();
            log_message('error', "verify_and_process({$source}): fees threw order={$gwOrderId}: {$feeError}");
        }

        if ($feeSuccess) {
            // ── F. Success → paid + payment record ──
            $this->firebase->firestoreSet('feeOnlineOrders', $orderDocId, [
                'status'         => 'paid',
                'paid_at'        => date('c'),
                'receipt_key'    => $receiptKey,
                'receipt_no'     => $receiptNo,
                'payment_status' => 'captured',
            ], true);

            // Q5(a): the feeProcessedPayments marker is now written for
            // BOTH frontend and webhook paths. Any future verify retry
            // short-circuits at parent_verify_payment L3051 regardless
            // of which path originally processed the payment.
            try {
                $this->fsTxn->markPaymentProcessed(
                    $gwPaymentId,
                    $receiptNo,
                    $receiptKey,
                    $studentId
                );
            } catch (\Exception $e) {
                log_message('error', "verify_and_process({$source}): markPaymentProcessed failed [{$gwPaymentId}]: " . $e->getMessage());
            }

            $payRecId = 'PAY_' . date('YmdHis') . '_' . substr(bin2hex(random_bytes(3)), 0, 6);
            $this->firebase->firestoreSet('feeOnlinePayments', "{$this->school_name}_{$payRecId}", [
                'schoolId'           => $this->school_name,
                'order_id'           => $recordId,
                'gateway_order_id'   => $gwOrderId,
                'gateway_payment_id' => $gwPaymentId,
                'student_id'         => $studentId,
                'student_name'       => $order['student_name'] ?? '',
                'amount'             => (float) ($order['amount'] ?? 0),
                'receipt_key'        => $receiptKey,
                'gateway'            => $order['gateway'] ?? 'mock',
                'payment_status'     => 'captured',
                'source'             => $source,
                'signature'          => substr($signature, 0, 16) . '...',
                'created_at'         => date('c'),
            ]);

            log_message('info', "verify_and_process({$source}): SUCCESS order={$gwOrderId} receipt={$receiptKey}");
            $releaseLock();

            return ['ok' => true, 'fee_success' => true, 'receipt_key' => $receiptKey];
        } else {
            // ── G. Fee writes failed → retryable ──
            $this->firebase->firestoreSet('feeOnlineOrders', $orderDocId, [
                'status'         => 'fees_failed',
                'payment_status' => 'captured',
                'failed_at'      => date('c'),
                'failure_reason' => $feeError,
                'signature'      => $signature,
            ], true);

            $releaseLock();

            return [
                'ok'          => true,
                'fee_success' => false,
                'fee_error'   => "Gateway confirmed but fee recording failed: {$feeError}",
                'error'       => "Gateway confirmed but fee recording failed: {$feeError}",
            ];
        }
    }

    /**
     * POST — Retry fee processing for a failed online payment.
     * Only works for orders in "fees_failed" status.
     * Params: order_record_id
     */
    public function retry_payment_processing()
    {
        $this->_require_role(['Admin'], 'retry_payment_processing');

        $recordId = trim($this->input->post('order_record_id') ?? '');
        if ($recordId === '') $this->json_error('Order record ID is required.');

        $orderDocId = "{$this->school_name}_{$recordId}";
        $order = $this->firebase->firestoreGet('feeOnlineOrders', $orderDocId);

        if (!is_array($order)) $this->json_error('Order not found.');
        if (($order['status'] ?? '') === 'paid') {
            $this->json_success(['message' => 'Order already paid. No retry needed.', 'already_paid' => true]);
            return;
        }
        if (($order['status'] ?? '') !== 'fees_failed') {
            $this->json_error('Only orders with status "fees_failed" can be retried. Current: ' . ($order['status'] ?? 'unknown'));
        }

        // Transition to processing
        $this->firebase->firestoreSet('feeOnlineOrders', $orderDocId, [
            'status'         => 'processing',
            'retry_at'       => date('c'),
            'retry_by'       => $this->admin_name ?? '',
        ], true);

        $gwPaymentId = $order['gateway_payment_id'] ?? '';
        $gwOrderId   = $order['gateway_order_id'] ?? '';
        $studentId   = (string) ($order['student_id'] ?? '');
        $feeMonths   = is_array($order['fee_months'] ?? null) ? $order['fee_months'] : [];

        // Ensure fs/fsTxn loaded (needed for markPaymentProcessed post-success).
        if (!isset($this->fs)) {
            $this->load->library('Firestore_service', null, 'fs');
            $this->fs->init($this->school_name, $this->session_year);
        }
        $this->load->library('Fee_firestore_txn', null, 'fsTxn');
        $this->fsTxn->init($this->firebase, $this->fs, $this->school_name, $this->session_year);

        // P3.C Q6(c): retry uses the same service-based pipeline as
        // _verify_and_process instead of the legacy
        // _process_online_fee_collection. Same flag set for consistency;
        // the only difference vs webhook is the txn_id prefix, which we
        // keep as 'RZP_' because the underlying payment WAS Razorpay.
        require_once APPPATH . 'services/FeeCollectionService.php';
        $service = new FeeCollectionService();

        try {
            $serviceResult = $service->submit($this, [
                'source'                     => 'parent-razorpay-retry',
                'admin_id'                   => $this->admin_id ?? 'admin',
                'admin_name'                 => $this->admin_name ?? 'admin',
                'school_name'                => $this->school_name,
                'session_year'               => $this->session_year,
                'safe_user_id'               => $studentId,
                'amount'                     => (float) ($order['amount'] ?? 0),
                'discount'                   => 0.0,
                'fine'                       => 0.0,
                'submit_amount'              => 0.0,
                'selected_months'            => $feeMonths,
                'payment_mode'               => 'Online - Razorpay',
                'remarks'                    => "Razorpay {$gwPaymentId} (retry)",
                'collected_by'               => 'parent_app',
                'created_by'                 => $this->admin_id ?? 'admin',
                'txn_id'                     => 'RZP_' . date('YmdHis') . '_' . substr(bin2hex(random_bytes(3)), 0, 6),
                'batch_mode'                 => true,
                'wallet_mode'                => 'credit_overflow',
                'skip_duplicate_month_guard' => true,
                'defer_defaulter'            => true,
                'defer_accounting'           => true,
                'write_response_to_output'   => false,
                'extra_receipt_fields'       => [
                    'orderId'   => $gwOrderId,
                    'paymentId' => $gwPaymentId,
                ],
            ]);

            if (!is_array($serviceResult) || !($serviceResult['ok'] ?? false)) {
                $msg = is_array($serviceResult) ? (string) ($serviceResult['message'] ?? 'Service returned failure.') : 'Null service result.';
                throw new \RuntimeException($msg);
            }

            $receiptKey = (string) ($serviceResult['data']['receipt_key'] ?? '');
            $receiptNo  = (string) ($serviceResult['data']['receipt_no']  ?? '');

            $this->firebase->firestoreSet('feeOnlineOrders', $orderDocId, [
                'status'      => 'paid',
                'paid_at'     => date('c'),
                'receipt_key' => $receiptKey,
                'receipt_no'  => $receiptNo,
            ], true);

            // Q5(a): mark payment-id so future frontend verify retries
            // short-circuit via parent_verify_payment L3051.
            try {
                $this->fsTxn->markPaymentProcessed($gwPaymentId, $receiptNo, $receiptKey, $studentId);
            } catch (\Exception $e) {
                log_message('error', "retry_payment_processing: markPaymentProcessed failed [{$gwPaymentId}]: " . $e->getMessage());
            }

            // Write payment record
            $payRecId = 'PAY_' . date('YmdHis') . '_' . substr(bin2hex(random_bytes(3)), 0, 6);
            $this->firebase->firestoreSet('feeOnlinePayments', "{$this->school_name}_{$payRecId}", [
                'schoolId'           => $this->school_name,
                'order_id'           => $recordId,
                'gateway_order_id'   => $gwOrderId,
                'gateway_payment_id' => $gwPaymentId,
                'student_id'         => $studentId,
                'amount'             => (float) ($order['amount'] ?? 0),
                'receipt_key'        => $receiptKey,
                'gateway'            => $order['gateway'] ?? 'mock',
                'status'             => 'success',
                'created_at'         => date('c'),
                'retried'            => true,
            ]);

            log_message('info', "retry_payment_processing: SUCCESS order={$recordId} receipt={$receiptKey}");

            $this->json_success([
                'message'    => 'Fee recording completed successfully on retry.',
                'receipt_no' => str_replace('F', '', $receiptKey),
            ]);
        } catch (\Exception $e) {
            $this->firebase->firestoreSet('feeOnlineOrders', $orderDocId, [
                'status'         => 'fees_failed',
                'failed_at'      => date('c'),
                'failure_reason'  => $e->getMessage(),
                'retry_count'    => ((int) ($order['retry_count'] ?? 0)) + 1,
            ], true);
            $this->json_error('Retry failed: ' . $e->getMessage());
        }
    }


    // ══════════════════════════════════════════════════════════════════
    //  DASHBOARD / ANALYTICS HELPERS
    // ══════════════════════════════════════════════════════════════════

    /**
     * GET — Fee summary for dashboard stats.
     * Returns: total_collected, total_due, total_discounts, total_scholarships, total_refunds
     */
    public function get_fee_summary()
    {
        $this->_require_role(self::VIEW_ROLES, 'get_fee_summary');
        $schoolFs = $this->school_name;
        $sy       = $this->session_year;

        // Check for cached summary (valid for 5 minutes)
        $cacheDocId = "{$schoolFs}_{$sy}_summary";
        $cached = $this->firebase->firestoreGet('feeSettings', $cacheDocId);
        if (is_array($cached) && !empty($cached['as_of'])) {
            $cacheAge = time() - strtotime($cached['as_of']);
            if ($cacheAge < 300) { // 5 minutes
                $this->json_success($cached);
            }
        }

        $totalCollected    = 0;
        $totalDue          = 0;
        $totalDiscounts    = 0;
        $totalScholarships = 0;
        $totalRefunds      = 0;

        // --- Total collected: sum from fee receipts ---
        $receipts = $this->firebase->firestoreQuery('feeReceipts', [
            ['schoolId', '==', $schoolFs],
            ['session',  '==', $sy],
        ]);
        foreach ((array) $receipts as $row) {
            $d = $row['data'] ?? $row;
            // Phase 16: net_receivable math uses allocated_amount because
            // wallet-overflow shouldn't be counted as money collected against
            // demands (it's still owed back to the parent as wallet credit).
            $amt = floatval($d['allocated_amount']
                          ?? $d['allocatedAmount']
                          ?? $d['amount']
                          ?? 0);
            if ($amt > 0) $totalCollected += $amt;
        }

        // --- Total due: from fee demands ---
        $demands = $this->firebase->firestoreQuery('feeDemands', [
            ['schoolId', '==', $schoolFs],
            ['session',  '==', $sy],
        ]);
        foreach ((array) $demands as $row) {
            $d = $row['data'] ?? $row;
            $balance = floatval($d['balance'] ?? 0);
            if ($balance > 0) $totalDue += $balance;
        }

        // --- Total discounts: from studentDiscounts ---
        $discRows = $this->firebase->firestoreQuery('studentDiscounts', [
            ['schoolId', '==', $schoolFs],
        ]);
        foreach ((array) $discRows as $row) {
            $d = $row['data'] ?? $row;
            $totalDiscounts += floatval($d['totalDiscount'] ?? 0);
        }

        // --- Total scholarships ---
        $awardRows = $this->firebase->firestoreQuery('scholarshipAwards', [
            ['schoolId', '==', $schoolFs],
            ['status',   '==', 'active'],
        ]);
        foreach ((array) $awardRows as $row) {
            $d = $row['data'] ?? $row;
            $totalScholarships += floatval($d['amount'] ?? 0);
        }

        // --- Total refunds ---
        $this->_bootFsTxn();
        $refundRows = $this->fsTxn->listRefunds('processed');
        foreach ((array) $refundRows as $row) {
            $d = $row['data'] ?? $row;
            $totalRefunds += floatval($d['amount'] ?? 0);
        }

        $result = [
            'total_collected'    => round($totalCollected, 2),
            'total_due'          => round($totalDue, 2),
            'total_discounts'    => round($totalDiscounts, 2),
            'total_scholarships' => round($totalScholarships, 2),
            'total_refunds'      => round($totalRefunds, 2),
            'net_receivable'     => round($totalDue - $totalDiscounts - $totalScholarships, 2),
            'as_of'              => date('Y-m-d H:i:s'),
        ];

        // Cache the result
        $result['schoolId'] = $schoolFs;
        $result['session']  = $sy;
        $result['type']     = 'summary_cache';
        $this->firebase->firestoreSet('feeSettings', $cacheDocId, $result);

        $this->json_success($result);
    }

    // ══════════════════════════════════════════════════════════════════
    //  FEE CARRY-FORWARD (F-15)
    // ══════════════════════════════════════════════════════════════════

    /**
     * POST — Carry forward unpaid fees from previous session.
     * Params: from_session (e.g. "2025-26"), to_session (e.g. "2026-27")
     *
     * DEPRECATED 2026-04-17 — Carry-forward is now folded into
     * Year Rollover (`Fees::year_rollover_execute`). New code paths
     * should call that. This endpoint remains as a standalone tool
     * only for recovery / re-run scenarios.
     */
    public function carry_forward_fees()
    {
        $this->_require_role(self::ADMIN_ROLES, 'carry_forward_fees');

        $fromSession = trim($this->input->post('from_session') ?? '');
        $toSession   = trim($this->input->post('to_session') ?? '');
        $sn          = $this->school_name;

        // Log that the deprecated endpoint was hit so we can eventually
        // retire it once usage goes to zero.
        log_message('warning', "DEPRECATED carry_forward_fees called by admin_id={$this->admin_id} from={$fromSession} to={$toSession}. Prefer year_rollover_execute.");

        if (empty($fromSession) || empty($toSession)) {
            $this->json_error('Both from_session and to_session are required.');
        }
        if ($fromSession === $toSession) {
            $this->json_error('Source and target sessions must be different.');
        }

        $schoolFs = $sn;

        // Read fee demands from old session to find unpaid
        $oldDemands = $this->firebase->firestoreQuery('feeDemands', [
            ['schoolId', '==', $schoolFs],
            ['session',  '==', $fromSession],
        ]);

        // Read students from old session
        $oldStudents = $this->firebase->firestoreQuery('students', [
            ['schoolId', '==', $schoolFs],
        ]);
        $studentMap = [];
        foreach ((array) $oldStudents as $row) {
            $d = $row['data'] ?? $row;
            $sid = (string) ($d['studentId'] ?? $this->_stripSchoolPrefix($row['id'] ?? ''));
            $studentMap[$sid] = $d;
        }

        $months = ['April','May','June','July','August','September','October','November','December','January','February','March'];
        $carriedForward = [];
        $totalStudents = 0;
        $totalAmount = 0;

        // Group demands by student and find unpaid (balance > 0)
        $studentDemands = [];
        foreach ((array) $oldDemands as $row) {
            $d = $row['data'] ?? $row;
            $sid = (string) ($d['studentId'] ?? '');
            $balance = floatval($d['balance'] ?? 0);
            $period  = (string) ($d['period'] ?? '');
            if ($sid === '' || $balance <= 0 || $period === '') continue;
            if (!isset($studentDemands[$sid])) $studentDemands[$sid] = [];
            $studentDemands[$sid][] = ['period' => $period, 'balance' => $balance];
        }

        foreach ($studentDemands as $userId => $demands) {
            $unpaidMonths = [];
            $unpaidAmount = 0;
            foreach ($demands as $dem) {
                $unpaidMonths[] = $dem['period'];
                $unpaidAmount += $dem['balance'];
            }
            if ($unpaidAmount > 0) {
                $stuData = $studentMap[$userId] ?? [];
                $carriedForward[$userId] = [
                    'student_name'  => (string) ($stuData['name'] ?? $stuData['studentName'] ?? $userId),
                    'class'         => (string) ($stuData['className'] ?? ''),
                    'section'       => (string) ($stuData['section'] ?? ''),
                    'unpaid_months' => $unpaidMonths,
                    'amount'        => round($unpaidAmount, 2),
                    'from_session'  => $fromSession,
                ];
                $totalStudents++;
                $totalAmount += $unpaidAmount;
            }
        }

        if (empty($carriedForward)) {
            $this->json_success(['message' => 'No unpaid fees found to carry forward.', 'count' => 0]);
            return;
        }

        // Write carry-forward records to Firestore
        foreach ($carriedForward as $userId => $cfData) {
            $this->firebase->firestoreSet('feeCarryForward', "{$schoolFs}_{$toSession}_{$userId}", array_merge($cfData, [
                'schoolId'    => $schoolFs,
                'session'     => $toSession,
                'studentId'   => $userId,
                'created_at'  => date('c'),
                'created_by'  => $this->session->userdata('admin_name') ?? 'Admin',
            ]));
        }

        $this->json_success([
            'message' => "Carried forward unpaid fees for {$totalStudents} student(s). Total: Rs. " . number_format($totalAmount, 2),
            'count'   => $totalStudents,
            'total'   => round($totalAmount, 2),
        ]);
    }

    // ====================================================================
    //  PAYMENT RECONCILIATION
    // ====================================================================

    /** GET — Payment Reconciliation page */
    public function payment_reconciliation()
    {
        $this->_require_role(self::ADMIN_ROLES);
        $this->load->view('include/header');
        $this->load->view('fee_management/payment_reconciliation');
        $this->load->view('include/footer');
    }

    /** POST — Get reconciliation data */
    public function get_reconciliation_data()
    {
        $this->_require_role(self::ADMIN_ROLES, 'get_reconciliation_data');

        $dateFrom  = trim($this->input->post('date_from') ?? '');
        $dateTo    = trim($this->input->post('date_to') ?? '');
        $studentId = trim($this->input->post('student_id') ?? '');
        $status    = trim($this->input->post('status') ?? '');

        $schoolFs = $this->school_name;
        $sy       = $this->session_year;

        // Load all orders from Firestore
        $orderRows = $this->firebase->firestoreQuery('feeOnlineOrders', [
            ['schoolId', '==', $schoolFs],
        ]);
        $allOrders = [];
        foreach ((array) $orderRows as $row) {
            $d = $row['data'] ?? $row;
            $rid = $this->_stripSchoolPrefix($row['id'] ?? '');
            $allOrders[$rid] = $d;
        }

        // Load all payment records
        $payRows = $this->firebase->firestoreQuery('feeOnlinePayments', [
            ['schoolId', '==', $schoolFs],
        ]);
        $allPayments = [];
        foreach ((array) $payRows as $row) {
            $d = $row['data'] ?? $row;
            $pid = $this->_stripSchoolPrefix($row['id'] ?? '');
            $allPayments[$pid] = $d;
        }

        // Load receipt index for cross-check
        $receiptRows = $this->firebase->firestoreQuery('feeReceiptIndex', [
            ['schoolId', '==', $schoolFs],
            ['session',  '==', $sy],
        ]);
        $receiptIdx = [];
        foreach ((array) $receiptRows as $row) {
            $d = $row['data'] ?? $row;
            $rn = (string) ($d['receiptNo'] ?? '');
            if ($rn !== '') $receiptIdx[$rn] = $d;
        }

        // Build receipt lookup by order_id for orphan detection
        $receiptsByOrder = [];
        foreach ($receiptIdx as $rn => $ri) {
            if (!is_array($ri)) continue;
            $oid = $ri['order_id'] ?? '';
            if ($oid) $receiptsByOrder[$oid] = $rn;
        }

        $successful   = [];
        $feesFailed   = [];
        $orphans      = [];
        $duplicates   = [];
        $stats        = ['total' => 0, 'paid' => 0, 'failed' => 0, 'orphan' => 0, 'duplicate' => 0, 'total_amount' => 0, 'failed_amount' => 0];

        // Track order_ids seen in payments for orphan detection
        $orderIdsInPayments = [];
        foreach ($allPayments as $pid => $pay) {
            if (!is_array($pay)) continue;
            $oid = $pay['gateway_order_id'] ?? '';
            if ($oid) $orderIdsInPayments[$oid] = ($orderIdsInPayments[$oid] ?? 0) + 1;
        }

        foreach ($allOrders as $rid => $order) {
            if (!is_array($order)) continue;

            $oStatus = $order['status'] ?? '';
            $oDate   = substr($order['created_at'] ?? '', 0, 10);
            $oStudent = $order['student_id'] ?? '';
            $oAmount  = (float) ($order['amount'] ?? 0);
            $gwOrder  = $order['gateway_order_id'] ?? '';

            // Date filter
            if ($dateFrom && $oDate < $dateFrom) continue;
            if ($dateTo && $oDate > $dateTo) continue;
            if ($studentId && $oStudent !== $studentId) continue;
            if ($status && $oStatus !== $status) continue;

            $stats['total']++;
            $stats['total_amount'] += $oAmount;

            $item = [
                'record_id'    => $rid,
                'order_id'     => $gwOrder,
                'student_id'   => $oStudent,
                'student_name' => $order['student_name'] ?? '',
                'amount'       => $oAmount,
                'status'       => $oStatus,
                'receipt_key'  => $order['receipt_key'] ?? '',
                'gateway'      => $order['gateway'] ?? 'mock',
                'created_at'   => $order['created_at'] ?? '',
                'paid_at'      => $order['paid_at'] ?? '',
                'source'       => $order['process_source'] ?? '',
            ];

            if ($oStatus === 'paid') {
                $stats['paid']++;
                // Check if receipt actually exists
                $rk = $order['receipt_key'] ?? '';
                $rn = str_replace('F', '', $rk);
                if ($rk && !isset($receiptIdx[$rn])) {
                    // Paid in order but no receipt in index — orphan
                    $item['issue'] = 'Receipt missing from index';
                    $orphans[] = $item;
                    $stats['orphan']++;
                } else {
                    $successful[] = $item;
                }
            } elseif ($oStatus === 'fees_failed') {
                $stats['failed']++;
                $stats['failed_amount'] += $oAmount;
                $item['failure_reason'] = $order['failure_reason'] ?? '';
                $item['retry_count']    = (int) ($order['retry_count'] ?? 0);
                $feesFailed[] = $item;
            } elseif ($oStatus === 'created' || $oStatus === 'verified') {
                // Check if order is stale (created > 30 min ago, never paid)
                $age = time() - strtotime($order['created_at'] ?? '2000-01-01');
                if ($age > 1800) { // 30 min
                    $item['issue'] = "Order created {$age}s ago but never completed";
                    $orphans[] = $item;
                    $stats['orphan']++;
                }
            }

            // Check for duplicates (same order processed multiple times)
            $hitCount = $orderIdsInPayments[$gwOrder] ?? 0;
            if ($hitCount > 1) {
                $item['hit_count'] = $hitCount;
                $duplicates[] = $item;
                $stats['duplicate']++;
            }
        }

        // Sort each by date descending
        $sortDesc = function (&$arr) {
            usort($arr, function ($a, $b) {
                return strcmp($b['created_at'] ?? '', $a['created_at'] ?? '');
            });
        };
        $sortDesc($successful);
        $sortDesc($feesFailed);
        $sortDesc($orphans);

        $this->json_success([
            'successful' => array_slice($successful, 0, 100),
            'fees_failed' => $feesFailed,
            'orphans'     => $orphans,
            'duplicates'  => $duplicates,
            'stats'       => $stats,
        ]);
    }

    /**
     * POST — Run legacy Month Fee → Demand migration for all students.
     * Admin-only. Safe to run multiple times (idempotent).
     */
    public function migrate_to_demands()
    {
        $this->_require_role(self::ADMIN_ROLES, 'migrate_demands');

        $this->load->library('Fee_migration', null, 'migration');
        $this->load->library('Fee_audit', null, 'fee_audit');
        $this->fee_audit->init(
            $this->firebase, "{$this->sessionRoot}/Fees",
            $this->admin_id ?? 'system', $this->admin_name ?? 'System',
            $this->school_name
        );
        $this->migration->init(
            $this->firebase, $this->school_name, $this->session_year,
            $this->parent_db_key ?? $this->school_name, $this->fee_audit
        );

        $result = $this->migration->migrateAll();
        $this->json_success([
            'message'  => "Migration complete: {$result['migrated']} migrated, {$result['skipped']} skipped, {$result['errors']} errors.",
            'migrated' => $result['migrated'],
            'skipped'  => $result['skipped'],
            'errors'   => $result['errors'],
            'details'  => array_slice($result['details'], 0, 50),
        ]);
    }

    /**
     * Check if an IP address falls within a CIDR range.
     */
    private function _ipInCidr(string $ip, string $cidr): bool
    {
        $parts = explode('/', $cidr, 2);
        if (count($parts) !== 2) return $ip === $cidr;
        list($subnet, $bits) = $parts;
        $bits = (int)$bits;
        if ($bits < 0 || $bits > 32) return false;
        $ipLong     = ip2long($ip);
        $subnetLong = ip2long($subnet);
        if ($ipLong === false || $subnetLong === false) return false;
        $mask = -1 << (32 - $bits);
        return ($ipLong & $mask) === ($subnetLong & $mask);
    }

    // ====================================================================
    //  DATABASE INTEGRITY CHECKS
    // ====================================================================

    /**
     * Run all 10 database integrity checks. AJAX GET.
     */
    public function integrity_report()
    {
        // Only admin roles
        $this->_require_role(['Super Admin', 'School Super Admin', 'Admin']);

        try {
            $report = $this->feeIntegrity->runAll();
            $this->json_success([
                'report'       => $report,
                'school'       => $this->school_name,
                'session'      => $this->session_year,
                'generated_at' => date('c')
            ]);
        } catch (Exception $e) {
            log_message('error', "integrity_report failed: " . $e->getMessage());
            $this->json_error($e->getMessage());
        }
    }

    /**
     * Auto-fix safe integrity issues. POST only.
     * @param string $checkId  e.g., "DB-008", "DB-009"
     */
    public function integrity_fix()
    {
        $this->_require_role(['Super Admin', 'School Super Admin']);

        $checkId = trim($this->input->post('check_id') ?? '');
        if ($checkId === '') {
            $this->json_error('check_id is required');
            return;
        }

        try {
            $result = $this->feeIntegrity->autoFix($checkId);
            $this->json_success([
                'check_id'    => $checkId,
                'fixed_count' => $result['fixed'] ?? 0,
                'details'     => $result['details'] ?? []
            ]);
        } catch (Exception $e) {
            log_message('error', "integrity_fix({$checkId}) failed: " . $e->getMessage());
            $this->json_error($e->getMessage());
        }
    }

    // ══════════════════════════════════════════════════════════════════
    //  FIRESTORE BULK SYNC
    // ══════════════════════════════════════════════════════════════════

    /**
     * POST — Trigger a full sync of all fee data from RTDB to Firestore.
     *
     * Syncs: fee structures, defaulters for all students.
     * Useful for initial migration or reconciliation.
     */
    public function firestore_bulk_sync()
    {
        $this->_require_role(self::ADMIN_ROLES, 'firestore_bulk_sync');

        // One-off admin operation: Firestore REST client makes synchronous
        // HTTP calls (15s cURL timeout each), and the full walk across
        // fee-structures, demands, receipts, ledger, CoA, etc. routinely
        // exceeds the default 120s web-request limit. Raise both PHP and
        // server-side timers so it can complete in one request.
        @set_time_limit(0);
        @ini_set('max_execution_time', '0');
        ignore_user_abort(true);

        try {
            // Homework sync
            $this->load->library('Homework_firestore_sync', null, 'hwSync');
            $this->hwSync->init($this->firebase, $this->school_name, $this->session_year);

            // Accounting sync (Phase 4.1) — chart of accounts, ledger,
            // closing balances, income/expense, voucher counters.
            $this->load->library('Accounting_firestore_sync', null, 'acctFsSync');
            $this->acctFsSync->init($this->firebase, $this->school_name, $this->session_year);

            // Phase 1 collections (discounts, scholarships, refunds, advance
            // balance, carry-forward, month-fee flags, receipt index, counter)
            // — walk existing RTDB data and populate Firestore so the Phase 2
            // admin read-swap doesn't find empty collections.
            [$discountsSynced, $monthFeesSynced] = $this->fsSync->syncAllDiscountsAndMonthFees();

            $results = [
                'fee_structures'      => $this->fsSync->syncAllFeeStructures(),
                'fee_demands'         => $this->fsSync->syncAllDemandsForAllStudents(),
                'fee_receipts'        => $this->fsSync->syncAllReceipts(),
                'defaulters'          => $this->fsSync->syncAllDefaulters(),
                'scholarship_awards'  => $this->fsSync->syncAllScholarshipAwards(),
                'refunds'             => $this->fsSync->syncAllRefunds(),
                'advance_balances'    => $this->fsSync->syncAllAdvanceBalances(),
                'carry_forward'       => $this->fsSync->syncAllCarryForward(),
                'receipt_index'       => $this->fsSync->syncAllReceiptIndex(),
                'receipt_counter'     => $this->fsSync->syncReceiptCounterFromRTDB() ? 1 : 0,
                'student_discounts'   => $discountsSynced,
                'student_month_fees'  => $monthFeesSynced,
                'homework'            => $this->hwSync->syncAllHomework(),
                // Accounting backfill (Phase 4.1)
                'chart_of_accounts'   => $this->acctFsSync->syncAllChartOfAccounts(),
                'accounting_ledger'   => $this->acctFsSync->syncAllLedgerEntries(),
                'closing_balances'    => $this->acctFsSync->syncAllClosingBalances(),
                'income_expense'      => $this->acctFsSync->syncAllIncomeExpense(),
                'voucher_counters'    => $this->acctFsSync->syncAllVoucherCounters(),
                'retry_queue'         => $this->fsSync->drainRetryQueue(),
            ];

            $this->json_success([
                'message' => 'Firestore bulk sync completed.',
                'synced'  => $results,
            ]);
        } catch (Exception $e) {
            log_message('error', "firestore_bulk_sync failed: " . $e->getMessage());
            $this->json_error('Firestore sync failed: ' . $e->getMessage());
        }
    }
}
