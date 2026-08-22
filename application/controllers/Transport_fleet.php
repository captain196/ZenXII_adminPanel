<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Transport_fleet — F13.2 Admin live fleet map.
 *
 * ─── AUTHORIZATION ───────────────────────────────────────────────────────
 * Existing `Transport` module, graded at **view** — watching a fleet is a read.
 * No new RBAC module: `functions/rbac_modules.json` is shared byte-for-byte
 * with the `staffCapabilities` Cloud Function, so adding an entry is a
 * cross-system change. Same reasoning that kept F12.1 on `Transport`.
 *
 * ─── WHY THE BROWSER POLLS INSTEAD OF SUBSCRIBING ────────────────────────
 * The admin panel has no client-side Firebase SDK and no client Firebase
 * config (audited — see TRANSPORT_F13_2_ARCHITECTURE_AUDIT §1), so a browser
 * snapshot listener is impossible without introducing a whole new client-auth
 * surface. The map therefore polls this endpoint.
 *
 * That is not a compromise on freshness: F13.1 writes a position at most once
 * per 15 s per vehicle, so a 15 s poll is matched to the source. Polling faster
 * could not surface data that does not exist yet.
 *
 * ─── THE CACHE IS THE SCALABILITY MECHANISM ──────────────────────────────
 * Each uncached snapshot costs 2 queries plus a handful of reference reads. At
 * 100 buses that is ~200 documents per poll — fine once, ruinous multiplied by
 * every administrator with the page open. A short per-school file cache makes
 * the cost **per school rather than per tab**: ten people watching one fleet
 * cost what one person does.
 *
 * TTL is deliberately just under the GPS write interval, so a cached response
 * can never hide a fix that has actually landed.
 */
class Transport_fleet extends MY_Controller
{
    /** Seconds a fleet snapshot may be served from cache. */
    private const CACHE_TTL_S = 12;

    public function __construct()
    {
        parent::__construct();
        require_permission('Transport');
        $this->load->library('transport_fleet_service', null, 'fleet');
        $this->fleet->init($this->fs, $this->school_name);
    }

    // ── Page ─────────────────────────────────────────────────────────────

    /** GET /transport_fleet — the live fleet map. */
    public function index(): void
    {
        require_permission('Transport', 'fleet_live_map', 'view');
        $this->load->view('include/header');
        $this->load->view('transport/fleet_live', []);
        $this->load->view('include/footer');
    }

    // ── Live data ────────────────────────────────────────────────────────

    /**
     * GET /transport_fleet/live — one fleet snapshot.
     *
     * Always 200 with a payload the client can render, including when there is
     * nothing running. "No active trip" is the normal state for most of the
     * school day and must never surface as an error.
     */
    public function live(): void
    {
        require_permission('Transport', 'fleet_live', 'view');

        $fresh = $this->input->get('fresh') === '1';
        $cacheKey = 'transport_fleet_live_' . md5($this->school_name);
        $path = APPPATH . 'cache/' . $cacheKey . '.json';

        if (!$fresh && is_readable($path) && (time() - (int) filemtime($path)) < self::CACHE_TTL_S) {
            $cached = json_decode((string) file_get_contents($path), true);
            if (is_array($cached)) {
                $cached['cached'] = true;
                $cached['cache_age_s'] = time() - (int) filemtime($path);
                $this->json_success($cached);
            }
        }

        try {
            $snap = $this->fleet->snapshot();
        } catch (\Throwable $e) {
            log_message('error', 'Transport_fleet::live failed: ' . $e->getMessage());
            $this->json_error('Could not load the live fleet. Please retry.', 500);
        }

        $snap['cached'] = false;
        @file_put_contents($path, json_encode($snap));
        $this->json_success($snap);
    }

    /**
     * GET /transport_fleet/map_config — the resolved renderer for this surface.
     *
     * Proxies the centralized Maps platform rather than duplicating it, so the
     * fleet map honours System Settings → Maps & Location exactly like every
     * other surface. There is deliberately no "Transport map provider" setting.
     */
    public function map_config(): void
    {
        require_permission('Transport', 'fleet_map_config', 'view');
        $this->load->library('maps_config_service', null, 'maps');
        $this->maps->init($this->fs, $this->school_name, $this->admin_id ?? 'system');
        // getClientConfig returns the resolved provider's PUBLIC credentials
        // only — server secrets never reach a browser.
        $this->json_success(['config' => $this->maps->getClientConfig('admin')]);
    }
}
