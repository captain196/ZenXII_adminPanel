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
        // Unified RBAC capability path (Operations manage). Legacy name-gate kept as fallback.
        // NOTE: DEAD CODE — this helper is never called anywhere in the controller.
        if (has_permission('Operations', 'manage')) return;
        if (!in_array($this->admin_role, self::OPS_ADMIN_ROLES, true)) {
            $this->json_error('Access denied.', 403);
        }
    }

    private function _require_ops_view()
    {
        // Unified RBAC capability path (Operations view). Legacy name-gate kept as fallback.
        if (has_permission('Operations', 'view')) return;
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
        $this->_require_role(self::VIEW_ROLES, 'ops_view', 'Operations', 'view');
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
        $this->_require_role(self::VIEW_ROLES, 'ops_summary', 'Operations', 'view');
        $this->_require_ops_view();

        $stats = [
            'library'   => ['books' => 0, 'issued' => 0, 'overdue' => 0],
            'transport' => ['vehicles' => 0, 'routes' => 0, 'students' => 0],
            'hostel'    => ['buildings' => 0, 'rooms' => 0, 'occupants' => 0],
            'inventory' => ['items' => 0, 'low_stock' => 0, 'vendors' => 0],
            'assets'    => ['total' => 0, 'assigned' => 0, 'maintenance_due' => 0],
        ];

        // ── ZERO-RTDB MIGRATION (2026-09-12) ─────────────────────────────────
        // These summaries previously read RTDB nodes ($this->firebase->get/
        // shallow_get on "Schools/{school}/Operations/..."), which the ZERO-RTDB
        // migration emptied — so every tile rendered 0 while the modules
        // themselves are Firestore-native. Re-pointed at each module's own
        // canonical Firestore collection (Library.php/Transport.php/Hostel.php/
        // Inventory.php COL_* constants) via the tenant-scoped $this->fs->
        // schoolWhere(). Status filters read defensively (case-insensitive,
        // snake+camel keys) so a field-name drift degrades a sub-count to 0
        // rather than to a wrong non-zero. Assets is still RTDB-native (never
        // migrated) and is handled separately below.
        $rowData = function ($r) { return is_array($r) ? ($r['data'] ?? $r) : []; };
        $lc      = function ($v) { return strtolower(trim((string) $v)); };

        // Canonical Firestore collection names (from each module's COL_* consts;
        // referenced as string literals so Operations needn't load those classes).
        // ── Library stats (libraryBooks / libraryIssues) ──
        if (has_permission('Library', 'view')) {
        try {
            $stats['library']['books'] = count((array) $this->fs->schoolWhere('libraryBooks'));
            $today = date('Y-m-d');
            foreach ((array) $this->fs->schoolWhere('libraryIssues') as $r) {
                $d = $rowData($r);
                if ($lc($d['status'] ?? '') === 'issued') {
                    $stats['library']['issued']++;
                    $due = (string) ($d['dueDate'] ?? $d['due_date'] ?? '');
                    if ($due !== '' && $due < $today) $stats['library']['overdue']++;
                }
            }
        } catch (\Throwable $e) { log_message('error', 'ops library summary: ' . $e->getMessage()); }
                }

        // ── Transport stats (vehicles / routes / studentRoutes) ──
        if (has_permission('Transport', 'view')) {
        try {
            foreach ((array) $this->fs->schoolWhere('vehicles') as $r) {
                if ($lc($rowData($r)['status'] ?? 'active') === 'active') $stats['transport']['vehicles']++;
            }
            foreach ((array) $this->fs->schoolWhere('routes') as $r) {
                if ($lc($rowData($r)['status'] ?? 'active') === 'active') $stats['transport']['routes']++;
            }
            $stats['transport']['students'] = count((array) $this->fs->schoolWhere('studentRoutes'));
        } catch (\Throwable $e) { log_message('error', 'ops transport summary: ' . $e->getMessage()); }
                }

        // ── Hostel stats (hostelBuildings / hostelRooms) ──
        if (has_permission('Hostel', 'view')) {
        try {
            foreach ((array) $this->fs->schoolWhere('hostelBuildings') as $r) {
                if ($lc($rowData($r)['status'] ?? 'active') === 'active') $stats['hostel']['buildings']++;
            }
            $rooms = (array) $this->fs->schoolWhere('hostelRooms');
            $stats['hostel']['rooms'] = count($rooms);
            foreach ($rooms as $r) {
                $d = $rowData($r);
                $stats['hostel']['occupants'] += (int) ($d['occupied'] ?? $d['occupiedCount'] ?? 0);
            }
        } catch (\Throwable $e) { log_message('error', 'ops hostel summary: ' . $e->getMessage()); }
                }

        // ── Inventory stats (inventory / vendors) ──
        if (has_permission('Inventory', 'view')) {
        try {
            foreach ((array) $this->fs->schoolWhere('inventory') as $r) {
                $d = $rowData($r);
                if ($lc($d['status'] ?? '') !== 'inactive') {
                    $stats['inventory']['items']++;
                    $cur = (int) ($d['currentStock'] ?? $d['current_stock'] ?? 0);
                    $min = (int) ($d['minStock'] ?? $d['min_stock'] ?? 0);
                    if ($cur <= $min) $stats['inventory']['low_stock']++;
                }
            }
            foreach ((array) $this->fs->schoolWhere('vendors') as $r) {
                if ($lc($rowData($r)['status'] ?? 'active') === 'active') $stats['inventory']['vendors']++;
            }
        } catch (\Throwable $e) { log_message('error', 'ops inventory summary: ' . $e->getMessage()); }
                }

        // ── Assets stats ──
        // NOTE: Assets.php is the ONE Operations module still RTDB-native (never
        // migrated to Firestore), so there is no Firestore source to count yet.
        // These RTDB reads are dead post-migration (return 0 in prod) and throw
        // outright where no RTDB binding exists (emulator). Wrapped so the Assets
        // tiles degrade to 0 without taking down the whole summary endpoint. When
        // Assets is migrated to Firestore, re-point these three like the modules
        // above.
        if (has_permission('Assets', 'view')) {
        try {
            $assetKeys = $this->firebase->shallow_get($this->_ops('Assets/Assets'));
            $stats['assets']['total'] = count($assetKeys);

            $assetAssign = $this->firebase->get($this->_ops('Assets/Assignments'));
            if (is_array($assetAssign)) {
                foreach ($assetAssign as $aa) {
                    if (($aa['status'] ?? '') === 'Active' && empty($aa['return_date'])) {
                        $stats['assets']['assigned']++;
                    }
                }
            }
            $maint = $this->firebase->get($this->_ops('Assets/Maintenance'));
            if (is_array($maint)) {
                $today = date('Y-m-d');
                foreach ($maint as $m) {
                    if (!empty($m['next_due']) && $m['next_due'] <= $today && ($m['status'] ?? '') === 'Scheduled') {
                        $stats['assets']['maintenance_due']++;
                    }
                }
            }
        } catch (\Throwable $e) { log_message('error', 'ops assets summary (RTDB, pending migration): ' . $e->getMessage()); }
                }

        $this->json_success(['stats' => $stats]);
    }
}
