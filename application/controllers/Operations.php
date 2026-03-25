<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Operations Dashboard Controller
 *
 * Central hub for all Operations Management sub-modules:
 *   - Library, Transport, Hostel, Inventory, Assets
 *
 * Firebase paths:
 *   Schools/{school}/Operations/Library/   (year-independent)
 *   Schools/{school}/Operations/Transport/ (year-independent)
 *   Schools/{school}/Operations/Hostel/    (year-independent)
 *   Schools/{school}/Operations/Inventory/ (year-independent)
 *   Schools/{school}/Operations/Assets/    (year-independent)
 *
 * Extends MY_Controller which provides:
 *   $this->school_name, $this->school_id, $this->session_year,
 *   $this->admin_id, $this->admin_name, $this->admin_role,
 *   $this->firebase, safe_path_segment(), json_success(), json_error()
 */
class Operations extends MY_Controller
{
    /** Roles for operations overview */
    private const VIEW_ROLES = ['Super Admin', 'School Super Admin', 'Admin', 'Principal', 'Vice Principal', 'Operations Manager', 'Librarian', 'Hostel Warden', 'Warden', 'Transport Manager', 'Store Manager'];

    // ── Role Constants (shared across all Operations sub-modules) ────
    const OPS_ADMIN_ROLES = ['Super Admin', 'School Super Admin', 'Admin', 'Principal', 'Vice Principal'];
    const OPS_MANAGE_ROLES = ['Super Admin', 'School Super Admin', 'Admin', 'Principal', 'Vice Principal', 'Operations Manager', 'Librarian', 'Hostel Warden', 'Warden', 'Transport Manager', 'Store Manager'];
    const OPS_VIEW_ROLES = ['Super Admin', 'School Super Admin', 'Admin', 'Principal', 'Vice Principal', 'Operations Manager', 'Librarian', 'Hostel Warden', 'Warden', 'Transport Manager', 'Store Manager', 'Academic Coordinator', 'Accountant', 'Class Teacher', 'Teacher'];

    public function __construct()
    {
        parent::__construct();
        require_permission('Operations');
    }

    // ====================================================================
    //  ROLE HELPERS
    // ====================================================================

    private function _require_ops_admin()
    {
        if (!in_array($this->admin_role, self::OPS_ADMIN_ROLES, true)) {
            $this->json_error('Access denied.', 403);
        }
    }

    private function _require_ops_view()
    {
        if (!in_array($this->admin_role, self::OPS_VIEW_ROLES, true)) {
            $this->json_error('Access denied.', 403);
        }
    }

    // ====================================================================
    //  PATH HELPERS
    // ====================================================================

    private function _ops(string $sub = ''): string
    {
        $base = "Schools/{$this->school_name}/Operations";
        return $sub !== '' ? "{$base}/{$sub}" : $base;
    }

    // ====================================================================
    //  PAGE LOADS
    // ====================================================================

    /**
     * Operations Dashboard — overview of all sub-modules.
     */
    public function index()
    {
        $this->_require_role(self::VIEW_ROLES, 'ops_view');
        $data = ['active_tab' => 'dashboard'];
        $this->load->view('include/header', $data);
        $this->load->view('operations/index', $data);
        $this->load->view('include/footer');
    }

    // ====================================================================
    //  AJAX — DASHBOARD
    // ====================================================================

    /**
     * GET — Aggregated summary stats for all Operations sub-modules.
     *
     * Performance: uses shallow_get() for count-only nodes to avoid
     * downloading full records. Only fetches full data when field-level
     * filtering is required (status checks, date comparisons, sums).
     */
    public function get_summary()
    {
        $this->_require_role(self::VIEW_ROLES, 'ops_summary');
        $this->_require_ops_view();

        $stats = [
            'library'   => ['books' => 0, 'issued' => 0, 'overdue' => 0],
            'transport' => ['vehicles' => 0, 'routes' => 0, 'students' => 0],
            'hostel'    => ['buildings' => 0, 'rooms' => 0, 'occupants' => 0],
            'inventory' => ['items' => 0, 'low_stock' => 0, 'vendors' => 0],
            'assets'    => ['total' => 0, 'assigned' => 0, 'maintenance_due' => 0],
        ];

        // ── Library stats ──
        // Books: count only → shallow_get (keys only, no record data)
        $bookKeys = $this->firebase->shallow_get($this->_ops('Library/Books'));
        $stats['library']['books'] = count($bookKeys);

        // Issues: need status + due_date fields → full get required
        $issues = $this->firebase->get($this->_ops('Library/Issues'));
        if (is_array($issues)) {
            $today = date('Y-m-d');
            foreach ($issues as $iss) {
                if (($iss['status'] ?? '') === 'Issued') {
                    $stats['library']['issued']++;
                    if (($iss['due_date'] ?? '') < $today) {
                        $stats['library']['overdue']++;
                    }
                }
            }
        }

        // ── Transport stats ──
        // Vehicles & Routes: need status filter → full get
        $vehicles = $this->firebase->get($this->_ops('Transport/Vehicles'));
        if (is_array($vehicles)) {
            foreach ($vehicles as $v) {
                if (($v['status'] ?? '') === 'Active') $stats['transport']['vehicles']++;
            }
        }
        $routes = $this->firebase->get($this->_ops('Transport/Routes'));
        if (is_array($routes)) {
            foreach ($routes as $r) {
                if (($r['status'] ?? '') === 'Active') $stats['transport']['routes']++;
            }
        }
        // Assignments: count only → shallow_get
        $trnAsnKeys = $this->firebase->shallow_get($this->_ops('Transport/Assignments'));
        $stats['transport']['students'] = count($trnAsnKeys);

        // ── Hostel stats ──
        // Buildings: need status filter → full get
        $buildings = $this->firebase->get($this->_ops('Hostel/Buildings'));
        if (is_array($buildings)) {
            foreach ($buildings as $b) {
                if (($b['status'] ?? '') === 'Active') $stats['hostel']['buildings']++;
            }
        }
        // Rooms: need occupied sum → full get
        $rooms = $this->firebase->get($this->_ops('Hostel/Rooms'));
        if (is_array($rooms)) {
            $stats['hostel']['rooms'] = count($rooms);
            foreach ($rooms as $rm) {
                $stats['hostel']['occupants'] += (int) ($rm['occupied'] ?? 0);
            }
        }

        // ── Inventory stats ──
        // Items: need status + stock level checks → full get
        $items = $this->firebase->get($this->_ops('Inventory/Items'));
        if (is_array($items)) {
            foreach ($items as $it) {
                if (($it['status'] ?? '') !== 'Inactive') {
                    $stats['inventory']['items']++;
                    if ((int) ($it['current_stock'] ?? 0) <= (int) ($it['min_stock'] ?? 0)) {
                        $stats['inventory']['low_stock']++;
                    }
                }
            }
        }
        // Vendors: need status filter → full get
        $vendors = $this->firebase->get($this->_ops('Inventory/Vendors'));
        if (is_array($vendors)) {
            foreach ($vendors as $vn) {
                if (($vn['status'] ?? '') === 'Active') $stats['inventory']['vendors']++;
            }
        }

        // ── Assets stats ──
        // Assets: count only → shallow_get
        $assetKeys = $this->firebase->shallow_get($this->_ops('Assets/Assets'));
        $stats['assets']['total'] = count($assetKeys);

        // Assignments: need status + return_date check → full get
        $assetAssign = $this->firebase->get($this->_ops('Assets/Assignments'));
        if (is_array($assetAssign)) {
            foreach ($assetAssign as $aa) {
                if (($aa['status'] ?? '') === 'Active' && empty($aa['return_date'])) {
                    $stats['assets']['assigned']++;
                }
            }
        }
        // Maintenance: need next_due + status check → full get
        $maint = $this->firebase->get($this->_ops('Assets/Maintenance'));
        if (is_array($maint)) {
            $today = date('Y-m-d');
            foreach ($maint as $m) {
                if (!empty($m['next_due']) && $m['next_due'] <= $today && ($m['status'] ?? '') === 'Scheduled') {
                    $stats['assets']['maintenance_due']++;
                }
            }
        }

        $this->json_success(['stats' => $stats]);
    }
}
