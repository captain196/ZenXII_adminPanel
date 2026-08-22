<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Maps_api — resolved map configuration for the mobile apps.
 *
 * ─── WHY THIS CONTROLLER EXISTS ──────────────────────────────────────────
 * The Parent app used to resolve its own map provider from `mapsConfig`,
 * reading `mode` / `global_provider` / `platform_provider` — a hand-ported copy
 * of an algorithm that has since grown a platform layer, a capability layer,
 * surface-awareness and eight canonical states. That copy drifted: it knows
 * nothing of `capability_source` or `platformConfig`, so an inheriting school
 * resolved to nothing and the bus map went blank.
 *
 * The fix is not to teach the app the new algorithm. It is to stop the app
 * having an algorithm at all. There is ONE resolver — resolveCapability(), via
 * getClientConfig() — and this endpoint is simply the app's door to it. The
 * admin panel calls the same function; a divergence between what an
 * administrator sees and what a parent's phone renders is therefore not a bug
 * that can be introduced, because there is only one answer to disagree with.
 *
 * ─── WHY NOT Maps_config ─────────────────────────────────────────────────
 * `Maps_config` is the school-settings surface and its constructor calls
 * require_permission('Configuration'). A parent has no admin session and no
 * Configuration grant, so that controller refuses them by design. Adding a
 * bearer branch there would mean editing the admin authorization path to serve
 * a mobile client, which is the opposite of keeping the two apart.
 *
 * ─── AUTHENTICATION ──────────────────────────────────────────────────────
 * The established ZenX app-API bootstrap, identical in shape to
 * Transport_driver_api and Staff_attendance: CI_Controller directly (there is
 * no PHP session to bootstrap), then Api_auth::require_auth() to verify the
 * Firebase ID token, which dies 401 on failure.
 *
 * THE SCHOOL COMES FROM THE TOKEN, never from the request. There is
 * deliberately no schoolId parameter anywhere in this controller: a parameter
 * is a thing a caller can change, and cross-tenant access is then one forged
 * query string away.
 */
class Maps_api extends CI_Controller
{
    private string $schoolId = '';

    public function __construct()
    {
        parent::__construct();
        $this->load->library('firebase');
        $this->load->library('api_auth');
        $this->load->library('Firestore_service', null, 'fs');

        $claims = $this->api_auth->require_auth();       // dies 401 if invalid
        $this->schoolId = (string) ($claims['school_id'] ?? $claims['schoolId'] ?? '');
        if ($this->schoolId === '') {
            $this->_json(409, ['status' => 'error', 'message' => 'Unable to resolve school from token.']);
        }

        // Read-only: the session is passed for consistency with the rest of the
        // codebase, but nothing here writes, so an unresolved session is not
        // fatal the way it is for Transport_driver_api's lifecycle writes.
        $this->fs->init($this->schoolId, $this->_resolveSession($this->schoolId));
    }

    /**
     * GET /maps_api/client_config
     *
     * The resolved provider and its PUBLIC credentials for the Parent surface.
     *
     * The surface is FIXED to 'parent'. Accepting it as a parameter would let a
     * parent request the admin surface, which resolves against different
     * renderer availability — TomTom, for instance, draws on admin and has no
     * Android renderer. Fixing it here means the answer always matches the
     * surface the caller can actually draw on.
     */
    public function client_config(): void
    {
        $this->load->library('maps_config_service', null, 'maps');
        $this->maps->init($this->fs, $this->schoolId, 'parent_app');

        // The SAME call the admin panel makes. No second resolver, no second
        // configuration format, no projection: whatever getClientConfig()
        // decides is what the phone renders.
        $cfg = $this->maps->getClientConfig(Maps_config_service::PLATFORM_PARENT);

        // The service already emits public credentials only — secrets live in
        // mapsSecrets/platformSecrets and are never loaded on this path. The
        // response is narrowed further to the four fields a renderer needs, so
        // a future addition to the client payload cannot silently widen what
        // mobile receives.
        $this->_json(200, [
            'status' => 'success',
            'config' => [
                'provider'    => (string) $cfg['provider'],
                'credentials' => (object) $cfg['credentials'],
                // False when nothing resolved, or when the resolved provider
                // has no Android renderer. The app must render an honest
                // "map unavailable" rather than an empty grey box.
                'available'   => (bool) ($cfg['enabled'] && $cfg['supported']),
            ],
        ]);
    }

    // ── helpers ──────────────────────────────────────────────────────────

    /** Mirrors Transport_driver_api::_resolveSession. */
    private function _resolveSession(string $schoolId): string
    {
        if ($schoolId === '') { return ''; }
        try {
            $doc = $this->firebase->firestoreGet('schools', $schoolId);
            if (is_array($doc)) {
                return (string) ($doc['schoolControl']['currentSession'] ?? $doc['currentSession'] ?? '');
            }
        } catch (\Throwable $e) {
            log_message('error', 'Maps_api::_resolveSession failed: ' . $e->getMessage());
        }
        return '';
    }

    private function _json(int $code, array $payload): void
    {
        header('Content-Type: application/json');
        http_response_code($code);
        echo json_encode($payload);
        exit;
    }
}
