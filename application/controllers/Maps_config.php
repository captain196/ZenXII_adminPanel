<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Maps_config — System Settings → Maps & Location.
 *
 * The single admin surface for the ERP's map providers. Every module that
 * renders a map — Transport live tracking today, fleet dashboards, staff
 * attendance, field-staff and asset tracking later — resolves its provider
 * from here rather than carrying its own setting.
 *
 * ─── AUTHORIZATION ───────────────────────────────────────────────────────
 * Gated on the EXISTING `Configuration` RBAC module, graded:
 *   view   — see the page and the current state
 *   edit   — enter credentials, run a connection test
 *   manage — activate/disable a provider, change mode, change defaults
 *
 * No new RBAC module is introduced. `functions/rbac_modules.json` is shared
 * byte-for-byte with the `staffCapabilities` Cloud Function, so adding an
 * entry is a cross-system change — the same reason F12.1 reused `Transport`
 * instead of minting `Compliance`.
 *
 * The split is deliberate: entering a key and testing it is reversible and
 * affects nobody until activation, whereas activating a provider or switching
 * mode changes what every parent's phone renders. Those belong at `manage`.
 */
class Maps_config extends MY_Controller
{
    public function __construct()
    {
        parent::__construct();
        require_permission('Configuration');
        $this->load->library('maps_config_service', null, 'maps');
        $this->maps->init($this->fs, $this->school_name, $this->admin_id ?? 'system');
    }

    // ── Page ─────────────────────────────────────────────────────────────

    /** GET /maps_config — System Settings → Maps & Location. */
    public function index(): void
    {
        require_permission('Configuration', 'maps_config_index', 'view');
        $cfg = $this->maps->getConfig();

        // 5D — the school's EFFECTIVE map source. Resolved through the same
        // engine the Admin fleet map and the Parent app use, so the page can
        // never disagree with what actually renders. `source`, `state` and
        // `warning` come straight from resolveCapability(); the page invents
        // no state of its own.
        $eff = $this->maps->getClientConfig(Maps_config_service::PLATFORM_ADMIN);
        $data = [
            'config'    => $cfg,
            'providers' => Maps_config_service::PROVIDERS,
            'platforms' => Maps_config_service::PLATFORMS,
            'modes'     => Maps_config_service::MODES,
            // Registry-driven: never a hardcoded provider list.
            'render_providers' => $this->maps->providersForCapability(
                Maps_config_service::CAP_RENDER, Maps_config_service::PLATFORM_ADMIN),
            'source'    => $this->maps->tenantCapabilitySource(Maps_config_service::CAP_RENDER, $cfg),
            'effective' => [
                'provider' => (string) $eff['provider'],
                'label'    => (string) (Maps_config_service::PROVIDERS[$eff['provider']]['label'] ?? ''),
                'state'    => (string) $eff['state'],
                'from'     => (string) $eff['source'],
                'warning'  => (string) $eff['warning'],
            ],
            // UI gating only — the server re-checks on every endpoint.
            'can_edit'   => function_exists('has_permission') ? has_permission('Configuration', 'edit')   : true,
            'can_manage' => function_exists('has_permission') ? has_permission('Configuration', 'manage') : true,
        ];
        // Same header/footer wrap as School_config::index.
        $this->load->view('include/header');
        $this->load->view('school_config/maps_location', $data);
        $this->load->view('include/footer');
    }

    // ── Read ─────────────────────────────────────────────────────────────

    /** GET /maps_config/get_config — current state for the page. */
    public function get_config(): void
    {
        require_permission('Configuration', 'maps_config_get', 'view');
        $cfg = $this->maps->getConfig();

        // Resolved provider per platform, so the UI shows the EFFECTIVE answer
        // rather than making an administrator work out what global-vs-platform
        // mode implies for each surface.
        $resolved = [];
        foreach (Maps_config_service::PLATFORMS as $p) {
            $resolved[$p] = $this->maps->resolveProvider($p);
        }
        // Renderer availability per platform, so the page can disable choices
        // that cannot work instead of letting an administrator save an
        // unusable configuration and discover it from a parent complaint.
        $availability = [];
        foreach (array_keys(Maps_config_service::PROVIDERS) as $pid) {
            $availability[$pid] = [
                'platforms'      => $this->maps->supportedPlatforms($pid),
                'global_capable' => $this->maps->isGlobalCapable($pid),
            ];
        }

        $this->json_success([
            'config'       => $cfg,
            'resolved'     => $resolved,
            'registry'     => Maps_config_service::PROVIDERS,
            'availability' => $availability,
            'surfaces'     => Maps_config_service::PLATFORMS_WITH_SURFACE,
        ]);
    }

    /**
     * GET /maps_config/client_config?platform=parent
     *
     * What a single surface needs to render — the resolved provider and its
     * PUBLIC credentials only. Other providers' settings and every secret are
     * excluded by the service.
     */
    public function client_config(): void
    {
        require_permission('Configuration', 'maps_config_client', 'view');
        $platform = trim((string) $this->input->get('platform'));
        if (!in_array($platform, Maps_config_service::PLATFORMS, true)) {
            $this->json_error('Unknown platform.', 400);
        }
        $this->json_success(['config' => $this->maps->getClientConfig($platform)]);
    }

    /** GET /maps_config/audit_history */
    public function audit_history(): void
    {
        require_permission('Configuration', 'maps_config_audit', 'view');
        $this->json_success(['rows' => $this->maps->auditHistory(25)]);
    }

    // ── Write ────────────────────────────────────────────────────────────

    /** POST /maps_config/save_provider — credentials. Resets validation. */
    public function save_provider(): void
    {
        require_permission('Configuration', 'maps_config_save_provider', 'edit');
        $provider = trim((string) $this->input->post('provider'));
        $spec = Maps_config_service::PROVIDERS[$provider] ?? null;
        if ($spec === null) { $this->json_error('Unknown provider.', 400); }

        $values = [];
        foreach (array_keys($spec['credentials']) as $key) {
            $values[$key] = (string) ($this->input->post($key) ?? '');
        }
        $res = $this->maps->saveProvider($provider, $values);
        if (empty($res['ok'])) { $this->json_error(implode(' ', $res['errors']), 422); }
        $this->json_success(['message' => 'Saved. Run a connection test before activating.']);
    }

    /** POST /maps_config/test_connection — a real outbound request. */
    public function test_connection(): void
    {
        require_permission('Configuration', 'maps_config_test', 'edit');
        $provider = trim((string) $this->input->post('provider'));
        if (!isset(Maps_config_service::PROVIDERS[$provider])) { $this->json_error('Unknown provider.', 400); }
        $res = $this->maps->validateProvider($provider);
        // A failed test is a RESULT, not a server error — the page must render
        // the provider's own message so the administrator can act on it.
        $this->json_success(['ok' => $res['ok'], 'status' => $res['status'], 'message' => $res['message']]);
    }

    /** POST /maps_config/activate — refused unless validation currently passes. */
    public function activate(): void
    {
        require_permission('Configuration', 'maps_config_activate', 'manage');
        $provider = trim((string) $this->input->post('provider'));
        $res = $this->maps->activateProvider($provider);
        if (empty($res['ok'])) { $this->json_error($res['message'], 422); }
        $this->json_success(['message' => $res['message']]);
    }

    /** POST /maps_config/deactivate */
    public function deactivate(): void
    {
        require_permission('Configuration', 'maps_config_deactivate', 'manage');
        $provider = trim((string) $this->input->post('provider'));
        $res = $this->maps->deactivateProvider($provider);
        if (empty($res['ok'])) { $this->json_error($res['message'], 422); }
        $this->json_success(['message' => $res['message']]);
    }

    /** POST /maps_config/save_mode — global vs per-platform assignment. */
    public function save_mode(): void
    {
        require_permission('Configuration', 'maps_config_save_mode', 'manage');
        $mode     = trim((string) $this->input->post('mode'));
        $global   = trim((string) ($this->input->post('global_provider') ?? ''));
        $platform = [];
        foreach (Maps_config_service::PLATFORMS as $p) {
            $platform[$p] = trim((string) ($this->input->post('platform_' . $p) ?? ''));
        }
        $res = $this->maps->saveMode($mode, $global, $platform);
        if (empty($res['ok'])) { $this->json_error(implode(' ', $res['errors']), 422); }
        $this->json_success(['message' => 'Provider assignment saved.']);
    }

    /**
     * POST /maps_config/set_source — 5D: inherit the ZenX platform default, or
     * override it with this school's own provider.
     *
     * `manage` because it changes which provider the WHOLE school renders with;
     * `edit` is for credential upkeep on an already-chosen provider.
     *
     * The service writes only `capability_source` — it cannot reach
     * platformConfig/platformSecrets, and returning to inherit keeps the
     * school's stored provider configuration for later reuse.
     */
    public function set_source(): void
    {
        require_permission('Configuration', 'maps_config_set_source', 'manage');
        $source = trim((string) $this->input->post('source'));
        $res = $this->maps->setTenantCapabilitySource(Maps_config_service::CAP_RENDER, $source);
        if (empty($res['ok'])) { $this->json_error(implode(' ', $res['errors']), 422); }
        $this->json_success([
            'message' => $source === Maps_config_service::SOURCE_INHERIT
                ? 'This school now uses ZenX Platform Maps.'
                : 'This school now uses its own map provider.',
        ]);
    }

    /** POST /maps_config/save_defaults — default centre + zoom. */
    public function save_defaults(): void
    {
        require_permission('Configuration', 'maps_config_save_defaults', 'manage');
        $latRaw = trim((string) ($this->input->post('default_lat') ?? ''));
        $lngRaw = trim((string) ($this->input->post('default_lng') ?? ''));
        $lat = $latRaw === '' ? null : (float) $latRaw;
        $lng = $lngRaw === '' ? null : (float) $lngRaw;
        $zoom = (float) ($this->input->post('default_zoom') ?? Maps_config_service::DEFAULT_ZOOM);

        $res = $this->maps->saveDefaults($lat, $lng, $zoom);
        if (empty($res['ok'])) { $this->json_error(implode(' ', $res['errors']), 422); }
        $this->json_success(['message' => 'Defaults saved.']);
    }
}
