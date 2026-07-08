<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Transport_analytics — F11 (2026-07-08) school-admin analytics
 * endpoints (LC-24 role-scoped dashboards).
 *
 * Thin controller — all logic in Transport_analytics_service. Every
 * endpoint applies role-based visibility filtering per Refinement 3;
 * requests from roles without scope receive an empty bundle and a
 * 403-ish payload rather than a hard rejection (avoids leaking the
 * existence of a role hierarchy through error codes).
 *
 * Endpoints:
 *   GET /transport_analytics/bundle           — the render-once payload
 *   GET /transport_analytics/kpi_tiles        — 6 fleet KPI tiles only
 *   GET /transport_analytics/live_ops         — live operations snapshot
 *   GET /transport_analytics/health_score     — composite fleet health
 *   GET /transport_analytics/expense_trend    — 24-month expense trend
 *   GET /transport_analytics/top_drivers      — top drivers by trips
 *   GET /transport_analytics/per_route        — routeFleetSummary rows
 *   POST /transport_analytics/rebuild_summaries — power action
 */
class Transport_analytics extends MY_Controller
{
    public function __construct()
    {
        parent::__construct();
        $this->load->library('firestore_service',            null, 'fs');
        $this->load->library('numbering_service',            null, 'numbering');
        $this->load->library('transport_service',            null, 'transport');
        $this->load->library('transport_analytics_service',  null, 'trnAnalytics');

        $sid  = (string) ($this->session->userdata('school_id')    ?? '');
        $sess = (string) ($this->session->userdata('session_year') ?? '');
        $uid  = (string) ($this->session->userdata('user_id')      ?? 'admin');
        if ($sid === '' || $sess === '') {
            $this->_json(['status' => 'error', 'message' => 'Session missing school/session context.']);
            exit;
        }
        $this->fs->init($sid, $sess);
        $this->numbering->init($this->fs, $sid, $sess);
        $this->transport->init($this->fs, $this->numbering, $sid, $sess, $uid);
        $this->trnAnalytics->init($this->fs, $this->transport, $sid, $sess);
    }

    /** Full render-once bundle scoped by caller role. */
    public function bundle(): void
    {
        $role = (string) $this->session->userdata('role');
        if (!Transport_analytics_service::hasAnyVisibility($role)) {
            $this->_json(['status' => 'success', 'visible_widgets' => [], 'reason' => 'no_analytics_scope_for_role']);
            return;
        }
        try {
            $bundle = $this->trnAnalytics->bundleForRole($role);
            $this->_json(['status' => 'success'] + $bundle);
        } catch (\Throwable $e) {
            $this->_json(['status' => 'error', 'message' => $e->getMessage()]);
        }
    }

    public function kpi_tiles(): void   { $this->_widget('fleet_kpi_tiles',   fn() => $this->trnAnalytics->getFleetKpiTiles()); }
    public function live_ops(): void    { $this->_widget('live_ops_snapshot', fn() => $this->trnAnalytics->getLiveOperationsSnapshot()); }
    public function health_score(): void { $this->_widget('fleet_health_score', fn() => $this->trnAnalytics->getFleetHealthScore()); }
    public function expense_trend(): void { $this->_widget('monthly_expense_trend', fn() => $this->trnAnalytics->getFleetExpenseTrend()); }
    public function top_drivers(): void { $this->_widget('top_drivers_by_trips', fn() => $this->trnAnalytics->getTopDrivers(5, 1)); }
    public function per_route(): void   { $this->_widget('per_route_fleet_summary', fn() => $this->trnAnalytics->getPerRouteFleet()); }

    /**
     * POST endpoint — rebuild the denormalised caches on demand.
     * Restricted to Admin/Transport Manager. Used by ops when a cache
     * appears stale between nightly rollups.
     */
    public function rebuild_summaries(): void
    {
        $role = strtolower((string) $this->session->userdata('role'));
        if (!in_array($role, ['admin','school_super_admin','school super admin','super_admin','super admin','transport manager','principal'], true)) {
            $this->_json(['status' => 'error', 'message' => 'Access denied for this role.']);
            return;
        }
        try {
            $r1 = $this->transport->rebuildMonthlyExpenseSummary();
            $r2 = $this->transport->rebuildRouteFleetSummary();
            $this->_json([
                'status' => 'success',
                'monthly_expense_summary' => $r1,
                'route_fleet_summary' => $r2,
            ]);
        } catch (\Throwable $e) {
            $this->_json(['status' => 'error', 'message' => $e->getMessage()]);
        }
    }

    /** Enforce widget-level scope match on every widget endpoint. */
    private function _widget(string $widgetKey, callable $producer): void
    {
        $role = (string) $this->session->userdata('role');
        $visible = Transport_analytics_service::visibleWidgetsForRole($role);
        if (!in_array($widgetKey, $visible, true)) {
            $this->_json(['status' => 'error', 'message' => 'Widget not visible to this role.', 'code' => 403]);
            return;
        }
        try {
            $this->_json(['status' => 'success', 'data' => $producer()]);
        } catch (\Throwable $e) {
            $this->_json(['status' => 'error', 'message' => $e->getMessage()]);
        }
    }

    private function _json(array $payload): void
    {
        $this->output->set_content_type('application/json')->set_output(json_encode($payload));
    }
}
