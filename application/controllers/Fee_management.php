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

    public function __construct()
    {
        // Webhook is a server-to-server call — no session required.
        // It authenticates via HMAC signature instead.
        $isWebhook = (
            isset($_SERVER['REQUEST_URI']) &&
            strpos($_SERVER['REQUEST_URI'], 'payment_webhook') !== false
        );

        if ($isWebhook) {
            // Skip MY_Controller auth — use CI_Controller directly
            CI_Controller::__construct();
            $this->load->library('firebase');
            // Resolve school from the webhook payload or a header
            // For now, use the first school in the system (single-school mode)
            // In multi-school: pass school_code in webhook URL or headers
            $this->school_name  = $this->session->userdata('school_name') ?? '';
            $this->session_year = $this->session->userdata('session_year') ?? '';
            // If no session, try to resolve from webhook config
            if ($this->school_name === '') {
                // Webhook must include school context — reject if missing
                header('Content-Type: application/json');
                http_response_code(400);
                echo json_encode(['status' => 'error', 'message' => 'Webhook requires school context. Use school-specific webhook URL.']);
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
    // ══════════════════════════════════════════════════════════════════

    /**
     * Get existing fee structure (Monthly + Yearly titles).
     */
    private function _getFeesStructure()
    {
        $raw = $this->firebase->get("{$this->feesBase}/Fees Structure");
        return is_array($raw) ? $raw : [];
    }

    /**
     * Get all fee title names as a flat array.
     */
    private function _getAllFeeTitles()
    {
        $structure = $this->_getFeesStructure();
        $titles = [];
        foreach ($structure as $type => $fees) {
            if (is_array($fees)) {
                foreach (array_keys($fees) as $title) {
                    $titles[] = $title;
                }
            }
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
     * Get all classes and sections from session root using shallow_get.
     * Returns: [['class' => 'Class 9th', 'section' => 'Section A'], ...]
     */
    private function _getAllClassSections()
    {
        $result = [];
        $classKeys = $this->firebase->shallow_get($this->sessionRoot);
        if (!is_array($classKeys)) {
            return $result;
        }

        foreach ($classKeys as $classKey) {
            $classKey = (string)$classKey;
            if (strpos($classKey, 'Class ') !== 0) {
                continue;
            }

            $sectionKeys = $this->firebase->shallow_get("{$this->sessionRoot}/{$classKey}");
            if (!is_array($sectionKeys)) {
                continue;
            }

            foreach ($sectionKeys as $sectionKey) {
                $sectionKey = (string)$sectionKey;
                if (strpos($sectionKey, 'Section ') !== 0) {
                    continue;
                }
                $result[] = [
                    'class'   => $classKey,
                    'section' => $sectionKey,
                ];
            }
        }

        return $result;
    }

    /**
     * Atomically increment the receipt counter with retry logic.
     * Returns the new receipt number and key string.
     */
    private function _nextReceiptNo()
    {
        $path = "{$this->feesBase}/Receipt No";
        $maxRetries = 5;
        for ($i = 0; $i < $maxRetries; $i++) {
            $current = $this->firebase->get($path);
            $current = !empty($current) ? (int)$current : 0;
            $next = $current + 1;
            $this->firebase->set($path, $next);
            // Verify it was set correctly (optimistic check)
            $verify = $this->firebase->get($path);
            if ((int)$verify === $next) {
                return [
                    'number' => $next,
                    'key'    => 'F' . str_pad($next, 6, '0', STR_PAD_LEFT),
                ];
            }
            // Another process changed it, retry with new value
            usleep(50000 * ($i + 1)); // 50ms, 100ms, 150ms...
        }
        // Fallback: use timestamp-based unique key
        $fallback = (int)(microtime(true) * 1000) % 999999;
        $this->firebase->set($path, $fallback);
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
        $cats = $this->firebase->get("{$this->feesBase}/Categories");
        $data['categories']    = is_array($cats) ? $cats : [];
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
        $settings = $this->firebase->get("{$this->feesBase}/Reminder Settings");
        $data['settings']   = is_array($settings) ? $settings : [];
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
        $config = $this->firebase->get("{$this->feesBase}/Gateway Config");
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
        $config = $this->firebase->get("{$this->feesBase}/Gateway Config");
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

        // Check duplicate
        $existing = $this->firebase->get("{$this->feesBase}/Fees Structure/{$feeType}/{$feeTitle}");
        if ($existing !== null) {
            $this->json_error("Fee title \"{$feeTitle}\" already exists under {$feeType}.");
        }

        $this->firebase->set("{$this->feesBase}/Fees Structure/{$feeType}/{$feeTitle}", '');
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

        $this->firebase->delete("{$this->feesBase}/Fees Structure/{$feeType}", $feeTitle);
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
        $cats = $this->firebase->get("{$this->feesBase}/Categories");
        $categories = [];
        if (is_array($cats)) {
            foreach ($cats as $id => $cat) {
                if (!is_array($cat)) {
                    continue;
                }
                $cat['id'] = $id;
                $categories[] = $cat;
            }
            // Sort by sort_order
            usort($categories, function ($a, $b) {
                return ((int)($a['sort_order'] ?? 999)) - ((int)($b['sort_order'] ?? 999));
            });
        }
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

        if (!empty($catId)) {
            // Update existing
            $catId = $this->safe_path_segment($catId, 'category_id');
            $data['updated_at'] = $now;
            $this->firebase->update("{$this->feesBase}/Categories/{$catId}", $data);
            $this->json_success(['message' => 'Category updated successfully.', 'id' => $catId]);
        } else {
            // Create new
            $data['created_at'] = $now;
            $newId = uniqid('cat_');
            $this->firebase->set("{$this->feesBase}/Categories/{$newId}", $data);
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
        $existing = $this->firebase->get("{$this->feesBase}/Categories/{$catId}");
        if (empty($existing)) {
            $this->json_error('Category not found.');
        }

        $this->firebase->delete("{$this->feesBase}/Categories", $catId);
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
        $raw = $this->firebase->get("{$this->feesBase}/Discount Policies");
        $discounts = [];
        if (is_array($raw)) {
            foreach ($raw as $id => $disc) {
                if (!is_array($disc)) {
                    continue;
                }
                $disc['id'] = $id;
                $discounts[] = $disc;
            }
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
        $name       = $this->_post('name');
        $type       = $this->_post('type');
        $value      = floatval($this->input->post('value'));
        $criteria   = $this->_post('criteria');
        $maxDisc    = floatval($this->input->post('max_discount'));
        $active     = ($this->input->post('active') === 'false' || $this->input->post('active') === '0')
                      ? false : true;
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

        // Parse applicable categories and titles (comma-separated)
        $appCats   = $this->_post('applicable_categories');
        $appTitles = $this->_post('applicable_titles');

        $catsArray = [];
        if ($appCats !== '') {
            $catsArray = array_values(array_filter(array_map('trim', explode(',', $appCats))));
        }
        $titlesArray = [];
        if ($appTitles !== '') {
            $titlesArray = array_values(array_filter(array_map('trim', explode(',', $appTitles))));
        }

        $now = date('Y-m-d H:i:s');

        $data = [
            'name'                  => $name,
            'type'                  => $type,
            'value'                 => $value,
            'criteria'              => $criteria,
            'applicable_categories' => $catsArray,
            'applicable_titles'     => $titlesArray,
            'max_discount'          => $maxDisc,
            'active'                => $active,
        ];

        if (!empty($discId)) {
            $discId = $this->safe_path_segment($discId, 'discount_id');
            $data['updated_at'] = $now;
            $this->firebase->update("{$this->feesBase}/Discount Policies/{$discId}", $data);
            $this->json_success(['message' => 'Discount policy updated successfully.', 'id' => $discId]);
        } else {
            $data['created_at'] = $now;
            $newId = uniqid('disc_');
            $this->firebase->set("{$this->feesBase}/Discount Policies/{$newId}", $data);
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

        $existing = $this->firebase->get("{$this->feesBase}/Discount Policies/{$discId}");
        if (empty($existing)) {
            $this->json_error('Discount policy not found.');
        }

        $this->firebase->delete("{$this->feesBase}/Discount Policies", $discId);
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

        $policy = $this->firebase->get("{$this->feesBase}/Discount Policies/{$discId}");
        if (!is_array($policy)) {
            $this->json_error('Discount policy not found.');
        }

        $criteria = isset($policy['criteria']) ? $policy['criteria'] : 'custom';
        $students = [];
        $classSections = $this->_getAllClassSections();

        foreach ($classSections as $cs) {
            $classNode   = $cs['class'];
            $sectionNode = $cs['section'];
            $listPath    = "{$this->sessionRoot}/{$classNode}/{$sectionNode}/Students/List";
            $list        = $this->firebase->get($listPath);

            if (!is_array($list)) {
                continue;
            }

            foreach ($list as $userId => $name) {
                $students[] = [
                    'user_id' => $userId,
                    'name'    => is_string($name) ? $name : (string)$userId,
                    'class'   => $classNode,
                    'section' => $sectionNode,
                ];
            }
        }

        // For sibling criteria, group by parent and only include those with 2+ children
        if ($criteria === 'sibling') {
            $parentStudents = [];
            $schoolId = $this->school_id;
            $parentData = $this->firebase->get("Users/Parents/{$schoolId}");

            if (is_array($parentData)) {
                // Build parent -> children map using Father_name or parent key
                $fatherMap = [];
                foreach ($parentData as $uid => $profile) {
                    if (!is_array($profile)) {
                        continue;
                    }
                    $fatherName = isset($profile['Father_name']) ? trim($profile['Father_name']) : '';
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
            }
        } elseif ($criteria === 'staff_ward') {
            // Filter to students whose parent is a teacher
            $teacherPath = "{$this->sessionRoot}/Teachers";
            $teachers    = $this->firebase->get($teacherPath);
            $teacherIds  = is_array($teachers) ? array_keys($teachers) : [];

            if (!empty($teacherIds)) {
                $schoolId    = $this->school_id;
                $parentData  = $this->firebase->get("Users/Parents/{$schoolId}");
                $staffWardIds = [];

                if (is_array($parentData)) {
                    foreach ($parentData as $uid => $profile) {
                        if (!is_array($profile)) {
                            continue;
                        }
                        // Check if any parent field matches a teacher
                        $parentPhone = isset($profile['Father_phone']) ? trim($profile['Father_phone']) : '';
                        $motherPhone = isset($profile['Mother_phone']) ? trim($profile['Mother_phone']) : '';
                        foreach ($teacherIds as $tid) {
                            $teacher = is_array($teachers[$tid]) ? $teachers[$tid] : [];
                            $tPhone  = isset($teacher['Phone']) ? trim($teacher['Phone']) : '';
                            if ($tPhone !== '' && ($tPhone === $parentPhone || $tPhone === $motherPhone)) {
                                $staffWardIds[$uid] = true;
                            }
                        }
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

        if (empty($studentRaw) || !is_array($studentRaw)) {
            $this->json_error('No students selected.');
        }

        $policy = $this->firebase->get("{$this->feesBase}/Discount Policies/{$discId}");
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

            // Read existing discount data for this student
            $discPath    = "{$this->sessionRoot}/{$class}/{$section}/Students/{$safeUserId}/Discount";
            $existing    = $this->firebase->get($discPath);
            $existingAmt = 0;
            if (is_array($existing)) {
                $existingAmt = isset($existing['totalDiscount']) ? floatval($existing['totalDiscount']) : 0;
            }

            // Calculate discount amount
            $discountAmount = $discValue;
            if ($discType === 'percentage') {
                // For percentage, we need the student's total fee. Read from class fees.
                $feePath   = "{$this->feesBase}/Classes Fees/{$class}/{$section}";
                $classFees = $this->firebase->get($feePath);
                $totalFee  = 0;
                if (is_array($classFees)) {
                    foreach ($classFees as $month => $fees) {
                        if (is_array($fees)) {
                            foreach ($fees as $title => $amt) {
                                $totalFee += floatval($amt);
                            }
                        }
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

            $this->firebase->update($discPath, $updateData);
            $this->firebase->set("{$discPath}/Applied/{$historyKey}", $appliedData);
            $applied++;
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
        $raw = $this->firebase->get("{$this->feesBase}/Scholarships");
        $allAwards = $this->firebase->get("{$this->feesBase}/Scholarship Awards");
        $scholarships = [];

        // Pre-compute award counts per scholarship
        $awardCounts = [];
        if (is_array($allAwards)) {
            foreach ($allAwards as $award) {
                if (is_array($award)
                    && isset($award['scholarship_id'])
                    && isset($award['status'])
                    && $award['status'] === 'active'
                ) {
                    $sid = $award['scholarship_id'];
                    if (!isset($awardCounts[$sid])) $awardCounts[$sid] = 0;
                    $awardCounts[$sid]++;
                }
            }
        }

        if (is_array($raw)) {
            foreach ($raw as $id => $schol) {
                if (!is_array($schol)) {
                    continue;
                }
                $schol['id'] = $id;
                $schol['current_awards'] = isset($awardCounts[$id]) ? $awardCounts[$id] : 0;
                $scholarships[] = $schol;
            }
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

        if (!empty($scholId)) {
            $scholId = $this->safe_path_segment($scholId, 'scholarship_id');
            $data['updated_at'] = $now;
            $this->firebase->update("{$this->feesBase}/Scholarships/{$scholId}", $data);
            $this->json_success(['message' => 'Scholarship updated successfully.', 'id' => $scholId]);
        } else {
            $data['created_at'] = $now;
            $newId = uniqid('schol_');
            $this->firebase->set("{$this->feesBase}/Scholarships/{$newId}", $data);
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

        $existing = $this->firebase->get("{$this->feesBase}/Scholarships/{$scholId}");
        if (empty($existing)) {
            $this->json_error('Scholarship not found.');
        }

        // Check for active awards
        $awards = $this->firebase->get("{$this->feesBase}/Scholarship Awards");
        if (is_array($awards)) {
            foreach ($awards as $award) {
                if (is_array($award)
                    && isset($award['scholarship_id'])
                    && $award['scholarship_id'] === $scholId
                    && isset($award['status'])
                    && $award['status'] === 'active'
                ) {
                    $this->json_error('Cannot delete scholarship with active awards. Revoke all awards first.');
                }
            }
        }

        $this->firebase->delete("{$this->feesBase}/Scholarships", $scholId);
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
        $raw     = $this->firebase->get("{$this->feesBase}/Scholarship Awards");
        $awards  = [];

        if (is_array($raw)) {
            foreach ($raw as $id => $award) {
                if (!is_array($award)) {
                    continue;
                }
                if ($scholId !== '' && (!isset($award['scholarship_id']) || $award['scholarship_id'] !== $scholId)) {
                    continue;
                }
                $award['id'] = $id;
                $awards[] = $award;
            }
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
        $scholarship = $this->firebase->get("{$this->feesBase}/Scholarships/{$scholId}");
        if (!is_array($scholarship)) {
            $this->json_error('Scholarship not found.');
        }
        if (isset($scholarship['active']) && $scholarship['active'] === false) {
            $this->json_error('This scholarship is not active.');
        }

        // Check max beneficiaries
        $maxBen = isset($scholarship['max_beneficiaries']) ? (int)$scholarship['max_beneficiaries'] : 0;
        if ($maxBen > 0) {
            $existingAwards = $this->firebase->get("{$this->feesBase}/Scholarship Awards");
            $currentCount = 0;
            if (is_array($existingAwards)) {
                foreach ($existingAwards as $aw) {
                    if (is_array($aw)
                        && isset($aw['scholarship_id'])
                        && $aw['scholarship_id'] === $scholId
                        && isset($aw['status'])
                        && $aw['status'] === 'active'
                    ) {
                        $currentCount++;
                    }
                }
            }
            if ($currentCount >= $maxBen) {
                $this->json_error("Maximum beneficiaries ({$maxBen}) reached for this scholarship.");
            }
        }

        // Calculate amount if not provided (use scholarship value)
        if ($amount <= 0) {
            $scholType  = isset($scholarship['type']) ? $scholarship['type'] : 'fixed';
            $scholValue = isset($scholarship['value']) ? floatval($scholarship['value']) : 0;

            if ($scholType === 'percentage') {
                // Get total fees for the student's class
                list($classNode, $sectionNode) = $this->_normalizeClassSection($class, $section);
                $feePath   = "{$this->feesBase}/Classes Fees/{$classNode}/{$sectionNode}";
                $classFees = $this->firebase->get($feePath);
                $totalFee  = 0;
                if (is_array($classFees)) {
                    foreach ($classFees as $month => $fees) {
                        if (is_array($fees)) {
                            foreach ($fees as $title => $amt) {
                                $totalFee += floatval($amt);
                            }
                        }
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
        $this->firebase->set("{$this->feesBase}/Scholarship Awards/{$awardId}", $awardData);

        // Update student's Discount node with scholarship info
        list($classNode, $sectionNode) = $this->_normalizeClassSection($class, $section);
        $discPath = "{$this->sessionRoot}/{$classNode}/{$sectionNode}/Students/{$studentId}/Discount";

        $existing = $this->firebase->get($discPath);
        $existingTotal = 0;
        $existingSchol = 0;
        if (is_array($existing)) {
            $existingTotal = isset($existing['totalDiscount']) ? floatval($existing['totalDiscount']) : 0;
            $existingSchol = isset($existing['ScholarshipDiscount']) ? floatval($existing['ScholarshipDiscount']) : 0;
        }

        $scholarshipUpdate = [
            'ScholarshipDiscount' => $existingSchol + $amount,
            'totalDiscount'       => $existingTotal + $amount,
        ];
        $this->firebase->update($discPath, $scholarshipUpdate);

        // Store individual scholarship record under Applied
        $this->firebase->set("{$discPath}/Scholarships/{$awardId}", [
            'scholarship_id'   => $scholId,
            'scholarship_name' => $scholName,
            'amount'           => $amount,
            'awarded_date'     => $now,
        ]);

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

        $award = $this->firebase->get("{$this->feesBase}/Scholarship Awards/{$awardId}");
        if (!is_array($award)) {
            $this->json_error('Award not found.');
        }
        if (isset($award['status']) && $award['status'] === 'revoked') {
            $this->json_error('This award has already been revoked.');
        }

        $now = date('Y-m-d H:i:s');

        // Update award status
        $this->firebase->update("{$this->feesBase}/Scholarship Awards/{$awardId}", [
            'status'      => 'revoked',
            'revoked_date' => $now,
            'revoked_by'   => $this->admin_name,
        ]);

        // Remove scholarship discount from student's Discount node
        $class   = isset($award['class']) ? $award['class'] : '';
        $section = isset($award['section']) ? $award['section'] : '';
        $userId  = isset($award['student_id']) ? $award['student_id'] : '';
        $amount  = isset($award['amount']) ? floatval($award['amount']) : 0;

        if ($class !== '' && $section !== '' && $userId !== '') {
            list($classNode, $sectionNode) = $this->_normalizeClassSection($class, $section);
            $discPath = "{$this->sessionRoot}/{$classNode}/{$sectionNode}/Students/{$userId}/Discount";
            $existing = $this->firebase->get($discPath);

            if (is_array($existing)) {
                $totalDisc = isset($existing['totalDiscount']) ? floatval($existing['totalDiscount']) : 0;
                $scholDisc = isset($existing['ScholarshipDiscount']) ? floatval($existing['ScholarshipDiscount']) : 0;

                $newScholDisc = max(0, $scholDisc - $amount);
                $newTotal     = max(0, $totalDisc - $amount);

                $this->firebase->update($discPath, [
                    'ScholarshipDiscount' => $newScholDisc,
                    'totalDiscount'       => $newTotal,
                ]);

                // Remove individual scholarship record
                $this->firebase->delete("{$discPath}/Scholarships", $awardId);
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
        $filterStatus = trim($this->input->get('status'));
        $raw = $this->firebase->get("{$this->feesBase}/Refunds");
        $refunds = [];

        if (is_array($raw)) {
            foreach ($raw as $id => $ref) {
                if (!is_array($ref)) {
                    continue;
                }
                if ($filterStatus !== '' && (!isset($ref['status']) || $ref['status'] !== $filterStatus)) {
                    continue;
                }
                $ref['id'] = $id;
                $refunds[] = $ref;
            }
        }

        // Sort by requested_date descending
        usort($refunds, function ($a, $b) {
            $da = isset($a['requested_date']) ? $a['requested_date'] : '';
            $db = isset($b['requested_date']) ? $b['requested_date'] : '';
            return strcmp($db, $da);
        });

        // Compute stats
        $stats = ['total' => count($refunds), 'pending' => 0, 'approved' => 0, 'processed' => 0, 'rejected' => 0];
        foreach ($refunds as &$ref) {
            $s = isset($ref['status']) ? $ref['status'] : '';
            if (isset($stats[$s])) $stats[$s]++;
            // Add combined class_section and date alias for view compatibility
            $ref['class_section'] = trim((isset($ref['class']) ? $ref['class'] : '') . ' / ' . (isset($ref['section']) ? $ref['section'] : ''));
            $ref['date'] = isset($ref['requested_date']) ? $ref['requested_date'] : '';
        }
        unset($ref);

        $this->json_success(['refunds' => $refunds, 'stats' => $stats, 'success' => true]);
    }

    /**
     * POST — Create a refund request.
     * Params: student_id, student_name, class, section, amount, fee_title, receipt_no, reason
     */
    public function create_refund()
    {
        $this->_require_role(self::ADMIN_ROLES, 'create_refund');
        $studentId   = trim($this->input->post('student_id'));
        $studentName = trim($this->input->post('student_name'));
        $class       = trim($this->input->post('class'));
        $section     = trim($this->input->post('section'));
        $amount      = floatval($this->input->post('amount'));
        $feeTitle    = trim($this->input->post('fee_title'));
        $receiptNo   = trim($this->input->post('receipt_no'));
        $reason      = trim($this->input->post('reason'));

        if ($studentId === '' || $studentName === '') {
            $this->json_error('Student information is required.');
        }

        // Sanitize path segments
        $studentId = $this->safe_path_segment($studentId, 'student_id');
        if ($receiptNo !== '') {
            $receiptNo = $this->safe_path_segment($receiptNo, 'receipt_no');
        }
        if ($amount <= 0) {
            $this->json_error('Refund amount must be greater than zero.');
        }
        if ($feeTitle === '') {
            $this->json_error('Fee title is required.');
        }
        if ($reason === '') {
            $this->json_error('Refund reason is required.');
        }

        // Handle combined class_section field from view
        $classSection = trim($this->input->post('class_section'));
        if ($classSection !== '' && $class === '') {
            // Parse "Class 9th / Section A" or "Class 9th"
            $parts = preg_split('/[\/,]/', $classSection, 2);
            $class = trim($parts[0]);
            $section = isset($parts[1]) ? trim($parts[1]) : '';
        }

        // Verify the receipt exists if provided
        if ($receiptNo !== '') {
            // Receipt stored in student's Fees Record
            list($classNode, $sectionNode) = $this->_normalizeClassSection($class, $section);
            $recordPath = "{$this->sessionRoot}/{$classNode}/{$sectionNode}/Students/{$studentId}/Fees Record/{$receiptNo}";
            $record     = $this->firebase->get($recordPath);
            if (empty($record)) {
                $this->json_error("Receipt '{$receiptNo}' not found for this student.");
            }
            // Validate refund amount doesn't exceed original payment
            $originalAmount = 0;
            if (is_array($record)) {
                if (isset($record['Amount'])) {
                    $originalAmount = floatval(str_replace(',', '', $record['Amount']));
                } elseif (isset($record['amount'])) {
                    $originalAmount = floatval($record['amount']);
                }
            }
            if ($originalAmount > 0 && $amount > $originalAmount) {
                $this->json_error("Refund amount ({$amount}) exceeds original payment amount ({$originalAmount}).");
            }
        }

        $now = date('Y-m-d H:i:s');

        $refundData = [
            'student_id'     => $studentId,
            'student_name'   => $studentName,
            'class'          => $class,
            'section'        => $section,
            'amount'         => $amount,
            'fee_title'      => $feeTitle,
            'receipt_no'     => $receiptNo,
            'reason'         => $reason,
            'status'         => 'pending',
            'requested_date' => $now,
            'reviewed_date'  => '',
            'processed_date' => '',
            'reviewed_by'    => '',
            'processed_by'   => '',
            'refund_mode'    => '',
            'remarks'        => '',
        ];

        $refId = uniqid('ref_');
        $this->firebase->set("{$this->feesBase}/Refunds/{$refId}", $refundData);

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
        $refId   = $this->safe_path_segment(trim($this->input->post('refund_id') ?? ''), 'refund_id');
        $status  = trim($this->input->post('status'));
        $remarks = trim($this->input->post('remarks'));

        $validStatuses = ['approved', 'rejected'];
        if (!in_array($status, $validStatuses, true)) {
            $this->json_error('Status must be "approved" or "rejected".');
        }

        $existing = $this->firebase->get("{$this->feesBase}/Refunds/{$refId}");
        if (!is_array($existing)) {
            $this->json_error('Refund not found.');
        }

        $currentStatus = isset($existing['status']) ? $existing['status'] : '';
        if ($currentStatus !== 'pending') {
            $this->json_error("Cannot change status. Current status is '{$currentStatus}'.");
        }

        $now = date('Y-m-d H:i:s');

        $updateData = [
            'status'       => $status,
            'reviewed_date' => $now,
            'reviewed_by'   => $this->admin_name,
        ];
        if ($remarks !== '') {
            $updateData['remarks'] = $remarks;
        }

        $this->firebase->update("{$this->feesBase}/Refunds/{$refId}", $updateData);

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

        // ── Validate input ──
        $refId      = $this->_post('refund_id');
        $refundMode = $this->_post('refund_mode');

        if ($refId === '') {
            $this->json_error('Refund ID is required.');
        }
        $refId = $this->safe_path_segment($refId, 'refund_id');

        $validModes = ['cash', 'bank_transfer', 'cheque', 'online'];
        if (!in_array($refundMode, $validModes, true)) {
            $this->json_error('Invalid refund mode. Must be one of: ' . implode(', ', $validModes));
        }

        // ── Initialize service with dependencies ──
        $this->load->library('Fee_refund_service', null, 'refund_svc');
        $this->load->library('Operations_accounting', null, 'ops_acct');
        $this->ops_acct->init(
            $this->firebase, $this->school_name, $this->session_year,
            $this->admin_id ?? 'system', $this
        );

        $this->refund_svc->init(
            $this->firebase,
            $this->sessionRoot,
            $this->feesBase,
            $this->admin_name ?? 'System',
            $this->admin_id ?? 'system',
            $this->ops_acct
        );

        // ── Audit + Delegate to service ──
        $this->load->library('Fee_audit', null, 'fee_audit');
        $this->fee_audit->init(
            $this->firebase, "{$this->sessionRoot}/Fees",
            $this->admin_id ?? 'system', $this->admin_name ?? 'System',
            $this->school_name
        );

        $result = $this->refund_svc->process($refId, $refundMode);

        if (!$result['ok']) {
            $this->fee_audit->log('refund_failed', [
                'refund_id' => $refId, 'reason' => $result['error'],
            ]);
            $this->json_error($result['error']);
        }

        $refund = $this->firebase->get("{$this->feesBase}/Refunds/{$refId}");
        $this->fee_audit->log('refund_processed', [
            'refund_id'  => $refId,
            'student_id' => is_array($refund) ? ($refund['student_id'] ?? '') : '',
            'amount'     => is_array($refund) ? floatval($refund['amount'] ?? 0) : 0,
            'receipt_no' => is_array($refund) ? ($refund['receipt_no'] ?? '') : '',
            'mode'       => $refundMode,
        ]);

        $this->json_success($result['data']);
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
        $settings = $this->firebase->get("{$this->feesBase}/Reminder Settings");
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

        $this->firebase->set("{$this->feesBase}/Reminder Settings", $settings);

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

        foreach ($classSections as $cs) {
            $classNode   = $cs['class'];
            $sectionNode = $cs['section'];

            // Read entire section Students node in ONE call
            $allStudentData = $this->firebase->get("{$this->sessionRoot}/{$classNode}/{$sectionNode}/Students");
            if (!is_array($allStudentData)) continue;

            $list = isset($allStudentData['List']) && is_array($allStudentData['List'])
                ? $allStudentData['List'] : [];

            // Get class fees to calculate due amount
            $feePath   = "{$this->feesBase}/Classes Fees/{$classNode}/{$sectionNode}";
            $classFees = $this->firebase->get($feePath);

            foreach ($list as $userId => $studentName) {
                $studentData = isset($allStudentData[$userId]) && is_array($allStudentData[$userId])
                    ? $allStudentData[$userId] : [];
                $monthFee = isset($studentData['Month Fee']) && is_array($studentData['Month Fee'])
                    ? $studentData['Month Fee'] : [];

                $unpaidMonths = [];
                $totalDue     = 0;

                foreach ($monthsToCheck as $month) {
                    $paid = isset($monthFee[$month]) ? (int)$monthFee[$month] : 0;
                    if ($paid !== 1) {
                        $unpaidMonths[] = $month;

                        // Calculate due for this month
                        if (is_array($classFees) && isset($classFees[$month]) && is_array($classFees[$month])) {
                            foreach ($classFees[$month] as $title => $amt) {
                                $totalDue += floatval($amt);
                            }
                        }
                    }
                }

                if (!empty($unpaidMonths)) {
                    $dueStudents[] = [
                        'user_id'       => $userId,
                        'name'          => is_string($studentName) ? $studentName : (string)$userId,
                        'class'         => $classNode,
                        'section'       => $sectionNode,
                        'unpaid_months' => $unpaidMonths,
                        'total_due'     => $totalDue,
                    ];
                }
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
        $month      = trim($this->input->post('month'));

        if (empty($studentRaw) || !is_array($studentRaw)) {
            $this->json_error('No students selected.');
        }
        if ($month === '') {
            $this->json_error('Month is required.');
        }

        $now     = date('Y-m-d H:i:s');
        $logged  = 0;

        $batchData = [];
        foreach ($studentRaw as $entry) {
            $student = is_string($entry) ? json_decode($entry, true) : $entry;
            if (!is_array($student) || empty($student['user_id'])) {
                continue;
            }

            $logId = uniqid('rem_');
            $batchData[$logId] = [
                'student_id'   => $student['user_id'],
                'student_name' => isset($student['name']) ? $student['name'] : '',
                'class'        => isset($student['class']) ? $student['class'] : '',
                'section'      => isset($student['section']) ? $student['section'] : '',
                'month'        => $month,
                'amount_due'   => isset($student['total_due']) ? floatval($student['total_due']) : 0,
                'sent_date'    => $now,
                'type'         => 'manual',
                'status'       => 'sent',
            ];
            $logged++;
        }

        if (!empty($batchData)) {
            $this->firebase->update("{$this->feesBase}/Reminders Log", $batchData);
        }

        $this->json_success([
            'message' => "Reminder logged for {$logged} student(s). Actual SMS/email delivery will be available once the messaging gateway is integrated.",
            'logged'  => $logged,
        ]);
    }

    /**
     * GET — Fetch all reminder log entries.
     */
    public function fetch_reminder_log()
    {
        $this->_require_role(self::VIEW_ROLES, 'fetch_reminder_log');
        $raw = $this->firebase->get("{$this->feesBase}/Reminders Log");
        $logs = [];

        if (is_array($raw)) {
            foreach ($raw as $id => $entry) {
                if (!is_array($entry)) {
                    continue;
                }
                $entry['id'] = $id;
                $logs[] = $entry;
            }
        }

        // Sort by sent_date descending
        usort($logs, function ($a, $b) {
            $da = isset($a['sent_date']) ? $a['sent_date'] : '';
            $db = isset($b['sent_date']) ? $b['sent_date'] : '';
            return strcmp($db, $da);
        });

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
        $config = $this->firebase->get("{$this->feesBase}/Gateway Config");
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
        $existingConfig = $this->firebase->get("{$this->feesBase}/Gateway Config");
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

        $this->firebase->set("{$this->feesBase}/Gateway Config", $configData);

        $this->json_success(['message' => 'Gateway configuration saved successfully.']);
    }

    /**
     * GET — Fetch all online payment records.
     */
    public function fetch_online_payments()
    {
        $this->_require_role(self::VIEW_ROLES, 'fetch_online_payments');
        $raw = $this->firebase->get("{$this->feesBase}/Online Payments");
        $payments = [];

        if (is_array($raw)) {
            foreach ($raw as $id => $pay) {
                if (!is_array($pay)) {
                    continue;
                }
                $pay['id'] = $id;
                $payments[] = $pay;
            }
        }

        // Sort by created_at descending
        usort($payments, function ($a, $b) {
            $da = isset($a['created_at']) ? $a['created_at'] : '';
            $db = isset($b['created_at']) ? $b['created_at'] : '';
            return strcmp($db, $da);
        });

        $this->json_success(['payments' => $payments, 'total' => count($payments)]);
    }

    /**
     * Initialize the Payment Service with the appropriate gateway adapter.
     * Uses mock gateway for test mode, real gateway for live mode.
     */
    private function _init_payment_service(): void
    {
        if (isset($this->paymentService)) return; // already initialized

        $gwConfig = $this->firebase->get("{$this->feesBase}/Gateway Config");

        // Load gateway adapter based on config
        $mode     = is_array($gwConfig) ? ($gwConfig['mode'] ?? 'test') : 'test';
        $provider = is_array($gwConfig) ? ($gwConfig['provider'] ?? 'mock') : 'mock';

        if ($mode === 'live' && $provider !== 'mock') {
            // Future: load real gateway adapter
            // $this->CI->load->library('Payment_gateway_' . $provider);
            // $adapter = $this->CI->{'payment_gateway_' . $provider};
            // For now, fall back to mock
            $this->load->library('Payment_gateway_mock');
            $adapter = $this->payment_gateway_mock;
        } else {
            $this->load->library('Payment_gateway_mock');
            $adapter = $this->payment_gateway_mock;
        }

        $this->load->library('Payment_service');
        $this->payment_service->init(
            $this->firebase,
            $this->feesBase,
            $adapter,
            $this->admin_id ?? ''
        );
        $this->paymentService = $this->payment_service;
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

            $gwConfig = $this->firebase->get("{$this->feesBase}/Gateway Config");

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

        $gwConfig = $this->firebase->get("{$this->feesBase}/Gateway Config");
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
        $gwConfig      = $this->firebase->get("{$this->feesBase}/Gateway Config");
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

        $rawBody = file_get_contents('php://input');
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
        $webhookSecret = is_array($gwConfig) ? ($gwConfig['webhook_secret'] ?? '') : '';
        $webhookSig = $_SERVER['HTTP_X_RAZORPAY_SIGNATURE']
            ?? $_SERVER['HTTP_X_WEBHOOK_SIGNATURE']
            ?? ($payload['webhook_signature'] ?? '');

        if ($webhookSecret !== '' && $webhookSig !== '') {
            $expectedSig = hash_hmac('sha256', $rawBody, $webhookSecret);
            if (!hash_equals($expectedSig, $webhookSig)) {
                log_message('error', "payment_webhook: HMAC FAILED ip={$ip}");
                http_response_code(401);
                echo json_encode(['status' => 'error', 'message' => 'Invalid webhook signature.']);
                return;
            }
        }

        // ── 3. Event-ID dedup (replayed webhook protection) ──
        $eventId = $_SERVER['HTTP_X_RAZORPAY_EVENT_ID']
            ?? ($payload['event_id'] ?? '');
        $payloadHash = $eventId !== '' ? md5($eventId) : md5($rawBody);
        $dedupPath   = "{$this->feesBase}/Webhook_Processed/{$payloadHash}";
        $existing    = $this->firebase->get($dedupPath);
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
        $this->firebase->push("{$this->feesBase}/Webhook_Log", [
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
        $this->firebase->set($dedupPath, [
            'order_id'     => $gwOrderId,
            'payment_id'   => $gwPaymentId,
            'processed_at' => date('c'),
            'result'       => $result['fee_success'] ?? false ? 'success' : ($result['already_paid'] ?? false ? 'already_paid' : 'failed'),
        ]);

        // ── 7. Update payment intent status if this was a mobile-initiated payment ──
        if ($result['ok'] && !empty($result['fee_success'])) {
            try {
                if ($gwOrderId !== '') {
                    $intentsPath = "Schools/{$this->school_name}/{$this->session_year}/Fees/Payment_Intents";
                    $intents = $this->firebase->get($intentsPath) ?: [];
                    if (is_array($intents)) {
                        foreach ($intents as $intentId => $intent) {
                            if (!is_array($intent)) continue;
                            if (($intent['gateway_order_id'] ?? '') === $gwOrderId) {
                                $this->firebase->update("{$intentsPath}/{$intentId}", [
                                    'status'       => 'completed',
                                    'completed_at' => date('c'),
                                    'receipt_no'   => $result['receipt_key'] ?? ''
                                ]);
                                break;
                            }
                        }
                    }
                }
            } catch (Exception $e) {
                log_message('error', "Payment intent update in webhook failed: " . $e->getMessage());
            }

            // ── 8. Update defaulter status after payment ──
            try {
                // Look up the order to get student_id
                $allOrders = $this->firebase->get("{$this->feesBase}/Online_Orders") ?? [];
                if (is_array($allOrders)) {
                    foreach ($allOrders as $ordKey => $ordData) {
                        if (!is_array($ordData)) continue;
                        if (($ordData['gateway_order_id'] ?? '') === $gwOrderId) {
                            $studentId = $ordData['student_id'] ?? '';
                            if ($studentId !== '') {
                                $this->feeDefaulter->updateDefaulterStatus($studentId);
                            }
                            break;
                        }
                    }
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
        $lockPath  = "{$this->feesBase}/Order_Locks/{$gwOrderId}";

        // ── A. Order-level lock (prevents webhook + frontend double-execute) ──
        $existingLock = $this->firebase->get($lockPath);
        if (is_array($existingLock) && !empty($existingLock['locked'])) {
            $lockAge = time() - strtotime($existingLock['locked_at'] ?? '2000-01-01');
            if ($lockAge < 120) {
                return ['ok' => false, 'error' => 'This payment is currently being processed. Please wait.'];
            }
        }
        $this->firebase->set($lockPath, [
            'locked' => true, 'locked_at' => date('c'), 'token' => $lockToken, 'source' => $source,
        ]);

        // Helper: release lock only if we own it
        $releaseLock = function () use ($lockPath, $lockToken) {
            try {
                $l = $this->firebase->get($lockPath);
                if (is_array($l) && ($l['token'] ?? '') === $lockToken) {
                    $this->firebase->delete($lockPath);
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
        $orderPath = "{$this->feesBase}/Online_Orders/{$recordId}";

        // ── C. Strict amount validation ──
        // Prevents tampered callbacks claiming different amounts.
        $orderAmount = floatval($order['amount'] ?? 0);
        $gwReportedAmount = floatval($verifyResult['gateway_amount'] ?? $orderAmount);
        if (abs($orderAmount - $gwReportedAmount) > 0.50) {
            log_message('error', "verify_and_process: AMOUNT MISMATCH order={$gwOrderId} expected={$orderAmount} got={$gwReportedAmount}");
            $releaseLock();
            $this->firebase->update($orderPath, [
                'status' => 'amount_mismatch',
                'expected_amount' => $orderAmount,
                'gateway_amount' => $gwReportedAmount,
                'flagged_at' => date('c'),
            ]);
            return ['ok' => false, 'error' => 'Payment amount does not match order amount.'];
        }

        // ── D. Transition: verified → processing ──
        $now = date('c');
        $this->firebase->update($orderPath, [
            'status'              => 'processing',
            'processing_started'  => $now,
            'gateway_payment_id'  => $gwPaymentId,
            'process_source'      => $source,
            'process_lock_token'  => $lockToken,
            'webhook_received_at' => ($source === 'webhook') ? $now : null,
        ]);

        // ── E. Process fee collection ──
        $feeSuccess = false;
        $receiptKey = '';
        $feeError   = '';

        try {
            $feeResult  = $this->_process_online_fee_collection($order, $gwPaymentId, $gwOrderId);
            $feeSuccess = true;
            $receiptKey = $feeResult['receipt_key'];
        } catch (\Exception $e) {
            $feeError = $e->getMessage();
            log_message('error', "verify_and_process({$source}): fees FAILED order={$gwOrderId}: {$feeError}");
        }

        if ($feeSuccess) {
            // ── F. Success → paid + payment record ──
            $this->firebase->update($orderPath, [
                'status'         => 'paid',
                'paid_at'        => date('c'),
                'receipt_key'    => $receiptKey,
                'payment_status' => 'captured', // Future: 'authorized' for 2-step capture
            ]);

            $payRecId = 'PAY_' . date('YmdHis') . '_' . substr(bin2hex(random_bytes(3)), 0, 6);
            $this->firebase->set("{$this->feesBase}/Online_Payments/{$payRecId}", [
                'order_id'           => $recordId,
                'gateway_order_id'   => $gwOrderId,
                'gateway_payment_id' => $gwPaymentId,
                'student_id'         => $order['student_id'] ?? '',
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
            $this->firebase->update($orderPath, [
                'status'         => 'fees_failed',
                'payment_status' => 'captured', // Gateway DID capture — fees just failed to record
                'failed_at'      => date('c'),
                'failure_reason' => $feeError,
                'signature'      => $signature,
            ]);

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

        $orderPath = "{$this->feesBase}/Online_Orders/{$recordId}";
        $order = $this->firebase->get($orderPath);

        if (!is_array($order)) $this->json_error('Order not found.');
        if (($order['status'] ?? '') === 'paid') {
            $this->json_success(['message' => 'Order already paid. No retry needed.', 'already_paid' => true]);
            return;
        }
        if (($order['status'] ?? '') !== 'fees_failed') {
            $this->json_error('Only orders with status "fees_failed" can be retried. Current: ' . ($order['status'] ?? 'unknown'));
        }

        // Transition to processing
        $this->firebase->update($orderPath, [
            'status'         => 'processing',
            'retry_at'       => date('c'),
            'retry_by'       => $this->admin_name ?? '',
        ]);

        $gwPaymentId = $order['gateway_payment_id'] ?? '';
        $gwOrderId   = $order['gateway_order_id'] ?? '';

        try {
            $feeResult = $this->_process_online_fee_collection($order, $gwPaymentId, $gwOrderId);

            $receiptKey = $feeResult['receipt_key'];

            $this->firebase->update($orderPath, [
                'status'      => 'paid',
                'paid_at'     => date('c'),
                'receipt_key' => $receiptKey,
            ]);

            // Write payment record
            $payRecId = 'PAY_' . date('YmdHis') . '_' . substr(bin2hex(random_bytes(3)), 0, 6);
            $this->firebase->set("{$this->feesBase}/Online_Payments/{$payRecId}", [
                'order_id'           => $recordId,
                'gateway_order_id'   => $gwOrderId,
                'gateway_payment_id' => $gwPaymentId,
                'student_id'         => $order['student_id'] ?? '',
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
            $this->firebase->update($orderPath, [
                'status'         => 'fees_failed',
                'failed_at'      => date('c'),
                'failure_reason'  => $e->getMessage(),
                'retry_count'    => ((int) ($order['retry_count'] ?? 0)) + 1,
            ]);
            $this->json_error('Retry failed: ' . $e->getMessage());
        }
    }

    /**
     * Process fee collection for an online payment order.
     * Creates: receipt, voucher, account book entry, month fees, accounting journal.
     * Throws on failure (caller decides what to do with order status).
     *
     * @param  array  $payment       Order data from Firebase
     * @param  string $gwPaymentId   Gateway payment ID
     * @param  string $gwOrderId     Gateway order ID
     * @return array  {receipt_key}
     * @throws \RuntimeException on any write failure
     */
    private function _process_online_fee_collection(array $payment, string $gwPaymentId, string $gwOrderId): array
    {
        $studentId = $payment['student_id'] ?? '';
        $class     = $payment['class'] ?? '';
        $section   = $payment['section'] ?? '';
        $amount    = floatval($payment['amount'] ?? 0);
        $feeMonths = $payment['fee_months'] ?? [];
        $gateway   = $payment['gateway'] ?? 'online';

        if ($studentId === '' || $class === '' || $section === '') {
            throw new \RuntimeException('Missing student/class/section in order data.');
        }

        $receipt    = $this->_nextReceiptNo();
        $receiptKey = $receipt['key'];
        $today      = date('d-m-Y');
        $now        = date('Y-m-d H:i:s');
        $payMode    = 'Online - ' . ucfirst($gateway);

        list($classNode, $sectionNode) = $this->_normalizeClassSection($class, $section);
        $studentBase = "{$this->sessionRoot}/{$classNode}/{$sectionNode}/Students/{$studentId}";

        // 1. Fees Record
        $this->firebase->set("{$studentBase}/Fees Record/{$receiptKey}", [
            'Amount'      => number_format($amount, 2, '.', ','),
            'Discount'    => '0.00',
            'Date'        => $today,
            'Fine'        => '0.00',
            'Mode'        => $payMode,
            'Refer'       => "Online Payment #{$gwPaymentId}",
            'order_id'    => $gwOrderId,
            'payment_id'  => $gwPaymentId,
        ]);

        // 2. Voucher
        $this->firebase->set("{$this->sessionRoot}/Accounts/Vouchers/{$today}/{$receiptKey}", [
            'type'               => 'online_payment',
            'student_id'         => $studentId,
            'student_name'       => $payment['student_name'] ?? '',
            'class'              => $class,
            'section'            => $section,
            'Acc'                => 'Fees',
            'Fees Received'      => number_format($amount, 2),
            'Id'                 => $studentId,
            'Mode'               => $payMode,
            'gateway_payment_id' => $gwPaymentId,
            'gateway_order_id'   => $gwOrderId,
            'receipt_no'         => $receiptKey,
            'timestamp'          => $now,
        ]);

        // 3. Account book
        $dateObj   = DateTime::createFromFormat('d-m-Y', $today);
        $bookMonth = $dateObj ? $dateObj->format('F') : date('F');
        $bookDay   = $dateObj ? $dateObj->format('d') : date('d');
        $abPath    = "{$this->sessionRoot}/Accounts/Account_book/Fees/{$bookMonth}/{$bookDay}/R";
        $curBook   = floatval($this->firebase->get($abPath) ?? 0);
        $this->firebase->set($abPath, $curBook + $amount);

        // 4. Receipt Index
        $receiptNo = str_replace('F', '', $receiptKey);
        $this->firebase->set("{$this->sessionRoot}/Accounts/Receipt_Index/{$receiptNo}", [
            'date'       => $today,
            'user_id'    => $studentId,
            'class'      => $class,
            'section'    => $section,
            'amount'     => $amount,
            'order_id'   => $gwOrderId,
            'payment_id' => $gwPaymentId,
        ]);

        // 5. Month Fee flags
        if (!empty($feeMonths)) {
            $monthFeePath = "{$studentBase}/Month Fee";
            $monthUpdate  = [];
            foreach ($feeMonths as $m) {
                $monthUpdate[trim($m)] = 1;
            }
            $this->firebase->update($monthFeePath, $monthUpdate);
        }

        // 6. Accounting journal (non-fatal — queued if fails)
        try {
            $this->load->library('Operations_accounting', null, 'ops_acct');
            $this->ops_acct->init(
                $this->firebase, $this->school_name, $this->session_year, $this->admin_id, $this
            );
            $this->ops_acct->create_fee_journal([
                'school_name'  => $this->school_name,
                'session_year' => $this->session_year,
                'date'         => date('Y-m-d'),
                'amount'       => $amount,
                'payment_mode' => $gateway,
                'bank_code'    => '',
                'receipt_no'   => $receiptNo,
                'student_name' => $payment['student_name'] ?? '',
                'student_id'   => $studentId,
                'class'        => $class,
                'admin_id'     => $this->admin_id,
            ]);
        } catch (\Exception $e) {
            log_message('error', 'Online payment accounting journal failed: ' . $e->getMessage());
            // Queue for reconciliation
            $this->firebase->set(
                "{$this->sessionRoot}/Accounts/Pending_journals/ONLINE_{$receiptNo}",
                ['amount' => $amount, 'student_id' => $studentId, 'queued_at' => date('c'), 'reason' => $e->getMessage()]
            );
        }

        return ['receipt_key' => $receiptKey];
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
        // Check for cached summary (valid for 5 minutes)
        $cachePath = "{$this->feesBase}/Summary Cache";
        $cached = $this->firebase->get($cachePath);
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

        // --- Total collected: sum all voucher amounts ---
        $vouchers = $this->firebase->get("{$this->sessionRoot}/Accounts/Vouchers");
        if (is_array($vouchers)) {
            foreach ($vouchers as $date => $dayVouchers) {
                if (!is_array($dayVouchers)) continue;
                foreach ($dayVouchers as $key => $voucher) {
                    if (!is_array($voucher)) continue;
                    // Handle both formats: 'Fees Received' (old) and 'amount' (new)
                    $amt = 0;
                    if (isset($voucher['Fees Received'])) {
                        $amt = floatval(str_replace(',', '', $voucher['Fees Received']));
                    } elseif (isset($voucher['amount'])) {
                        $amt = floatval($voucher['amount']);
                    }
                    if ($amt > 0) $totalCollected += $amt;
                }
            }
        }

        // --- Scan classes in bulk (read entire section data at once) ---
        $classSections = $this->_getAllClassSections();
        $months = ['April','May','June','July','August','September','October','November','December','January','February','March'];
        $currentMonth = date('n');
        $monthIndex = ($currentMonth >= 4) ? ($currentMonth - 4) : ($currentMonth + 8);
        $monthsToCheck = array_slice($months, 0, $monthIndex + 1);

        foreach ($classSections as $cs) {
            $classNode   = $cs['class'];
            $sectionNode = $cs['section'];

            // Read entire section Students node in ONE call (includes List, each student's data)
            $sectionData = $this->firebase->get("{$this->sessionRoot}/{$classNode}/{$sectionNode}/Students");
            $classFees   = $this->firebase->get("{$this->feesBase}/Classes Fees/{$classNode}/{$sectionNode}");

            if (!is_array($sectionData) || !is_array($classFees)) continue;

            $studentList = isset($sectionData['List']) && is_array($sectionData['List'])
                ? $sectionData['List'] : [];

            foreach ($studentList as $userId => $name) {
                // Student data is already in $sectionData
                $studentData = isset($sectionData[$userId]) && is_array($sectionData[$userId])
                    ? $sectionData[$userId] : [];
                $monthFee = isset($studentData['Month Fee']) && is_array($studentData['Month Fee'])
                    ? $studentData['Month Fee'] : [];
                $discount = isset($studentData['Discount']) && is_array($studentData['Discount'])
                    ? $studentData['Discount'] : [];

                // Due calculation
                foreach ($monthsToCheck as $month) {
                    $paid = isset($monthFee[$month]) ? (int)$monthFee[$month] : 0;
                    if ($paid !== 1 && isset($classFees[$month]) && is_array($classFees[$month])) {
                        foreach ($classFees[$month] as $title => $amt) {
                            $totalDue += floatval($amt);
                        }
                    }
                }

                // Discounts
                if (isset($discount['totalDiscount'])) {
                    $totalDiscounts += floatval($discount['totalDiscount']);
                }
            }
        }

        // --- Total scholarships ---
        $awards = $this->firebase->get("{$this->feesBase}/Scholarship Awards");
        if (is_array($awards)) {
            foreach ($awards as $award) {
                if (is_array($award) && isset($award['status']) && $award['status'] === 'active' && isset($award['amount'])) {
                    $totalScholarships += floatval($award['amount']);
                }
            }
        }

        // --- Total refunds ---
        $refunds = $this->firebase->get("{$this->feesBase}/Refunds");
        if (is_array($refunds)) {
            foreach ($refunds as $ref) {
                if (is_array($ref) && isset($ref['status']) && $ref['status'] === 'processed' && isset($ref['amount'])) {
                    $totalRefunds += floatval($ref['amount']);
                }
            }
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
        $this->firebase->set($cachePath, $result);

        $this->json_success($result);
    }

    // ══════════════════════════════════════════════════════════════════
    //  FEE CARRY-FORWARD (F-15)
    // ══════════════════════════════════════════════════════════════════

    /**
     * POST — Carry forward unpaid fees from previous session.
     * Params: from_session (e.g. "2025-26"), to_session (e.g. "2026-27")
     */
    public function carry_forward_fees()
    {
        $this->_require_role(self::ADMIN_ROLES, 'carry_forward_fees');

        $fromSession = trim($this->input->post('from_session') ?? '');
        $toSession   = trim($this->input->post('to_session') ?? '');
        $sn          = $this->school_name;

        if (empty($fromSession) || empty($toSession)) {
            $this->json_error('Both from_session and to_session are required.');
        }
        if ($fromSession === $toSession) {
            $this->json_error('Source and target sessions must be different.');
        }

        // Read fee structure from old session
        $oldFeesBase = "Schools/{$sn}/{$fromSession}/Accounts/Fees";
        $classFees = $this->firebase->get("{$oldFeesBase}/Classes Fees");
        if (!is_array($classFees)) {
            $this->json_error('No fee structure found in the source session.');
        }

        // Read all class/sections in old session to find students with unpaid months
        $sessionRoot = "Schools/{$sn}/{$fromSession}";
        $classKeys = $this->firebase->shallow_get($sessionRoot);
        if (!is_array($classKeys)) $classKeys = [];

        $months = ['April','May','June','July','August','September','October','November','December','January','February','March'];
        $carriedForward = [];
        $totalStudents = 0;
        $totalAmount = 0;

        foreach ($classKeys as $classKey => $v) {
            if (strpos($classKey, 'Class ') !== 0) continue;

            $sections = $this->firebase->shallow_get("{$sessionRoot}/{$classKey}");
            if (!is_array($sections)) continue;

            foreach ($sections as $sectionKey => $sv) {
                if (strpos($sectionKey, 'Section ') !== 0) continue;

                $studentsPath = "{$sessionRoot}/{$classKey}/{$sectionKey}/Students";
                $studentsNode = $this->firebase->get($studentsPath);
                if (!is_array($studentsNode)) continue;

                $studentList = $studentsNode['List'] ?? [];
                if (!is_array($studentList)) continue;

                foreach ($studentList as $userId => $name) {
                    $monthFee = $studentsNode[$userId]['Month Fee'] ?? null;
                    if (!is_array($monthFee)) continue;

                    $unpaidMonths = [];
                    foreach ($months as $m) {
                        if (isset($monthFee[$m]) && (int)$monthFee[$m] === 0) {
                            $unpaidMonths[] = $m;
                        }
                    }

                    if (!empty($unpaidMonths)) {
                        // Calculate unpaid amount from fee structure
                        // Firebase stores fees at: Classes Fees/{classOrd} '{sectionLtr}'/{month}/{title}
                        // e.g. Classes Fees/9th 'A'/April/Tuition Fee = 5000
                        $classOrd   = trim(str_ireplace('Class', '', $classKey));
                        $sectionLtr = trim(str_ireplace('Section', '', $sectionKey));
                        $feeKey     = "{$classOrd} '{$sectionLtr}'";
                        $feeData    = $classFees[$feeKey] ?? [];

                        // Fee structure is {month: {title: amount}} — sum per unpaid month
                        $unpaidAmount = 0;
                        if (is_array($feeData)) {
                            foreach ($unpaidMonths as $m) {
                                if (!isset($feeData[$m]) || !is_array($feeData[$m])) continue;
                                foreach ($feeData[$m] as $title => $amt) {
                                    if (!is_numeric($amt)) continue;
                                    $unpaidAmount += (float) $amt;
                                }
                            }
                        }
                        if ($unpaidAmount > 0) {
                            $carriedForward[$userId] = [
                                'student_name'  => $name,
                                'class'         => $classKey,
                                'section'       => $sectionKey,
                                'unpaid_months' => $unpaidMonths,
                                'amount'        => round($unpaidAmount, 2),
                                'from_session'  => $fromSession,
                            ];
                            $totalStudents++;
                            $totalAmount += $unpaidAmount;
                        }
                    }
                }
            }
        }

        if (empty($carriedForward)) {
            $this->json_success(['message' => 'No unpaid fees found to carry forward.', 'count' => 0]);
            return;
        }

        // Write carry-forward records to new session
        $cfPath = "Schools/{$sn}/{$toSession}/Accounts/Fees/Carried_Forward";
        $this->firebase->set($cfPath, [
            'from_session'   => $fromSession,
            'created_at'     => date('c'),
            'created_by'     => $this->session->userdata('admin_name') ?? 'Admin',
            'total_students' => $totalStudents,
            'total_amount'   => round($totalAmount, 2),
            'students'       => $carriedForward,
        ]);

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

        // Load all orders
        $allOrders = $this->firebase->get("{$this->feesBase}/Online_Orders") ?? [];
        if (!is_array($allOrders)) $allOrders = [];

        // Load all payment records
        $allPayments = $this->firebase->get("{$this->feesBase}/Online_Payments") ?? [];
        if (!is_array($allPayments)) $allPayments = [];

        // Load receipt index for cross-check
        $receiptIdx = $this->firebase->get("{$this->sessionRoot}/Accounts/Receipt_Index") ?? [];
        if (!is_array($receiptIdx)) $receiptIdx = [];

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

        try {
            // Homework sync
            $this->load->library('Homework_firestore_sync', null, 'hwSync');
            $this->hwSync->init($this->firebase, $this->school_name, $this->session_year);

            $results = [
                'fee_structures' => $this->fsSync->syncAllFeeStructures(),
                'defaulters'     => $this->fsSync->syncAllDefaulters(),
                'homework'       => $this->hwSync->syncAllHomework(),
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
