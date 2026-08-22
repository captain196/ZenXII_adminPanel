<?php
defined('BASEPATH') or exit('No direct script access allowed');

// CI3 does not autoload MY_Superadmin_Controller (only MY_Controller is picked
// up as the default subclass prefix), so every superadmin controller requires
// it explicitly — same first line as Superadmin_backups, _migration, _monitor.
require_once APPPATH . 'core/MY_Superadmin_Controller.php';

/**
 * Superadmin_maps — ZenX PLATFORM maps configuration (2026-08-16).
 *
 * WHY THIS LIVES IN SUPERADMIN, not in a school's settings.
 * The platform default is the provider every school inherits when it has not
 * configured its own. It belongs to ZenX, not to any tenant, so it must sit
 * behind MY_Superadmin_Controller (sa_id session) rather than the school-side
 * Configuration RBAC. A school administrator can never reach this controller:
 * there is no route from the school panel to it, and the base controller
 * redirects any non-SA session to superadmin/login.
 *
 * Placed in the sidebar's SYSTEM section beside System Monitor / Backup &
 * Restore / Migration Tool, which is where platform-wide operational
 * configuration already lives.
 *
 * SECURITY POSTURE
 *   • platformConfig / platformSecrets are deny-all in firestore.rules; this
 *     controller writes them through the Admin SDK, which bypasses rules.
 *   • Secrets are write-only from the browser's perspective: they are accepted
 *     on save and NEVER returned. The UI shows "stored" / "not set", never a
 *     value, never a masked prefix that leaks length.
 *   • The audit trail records which credential KEYS changed, never values.
 *
 * CAPABILITIES
 *   Render is implemented. Geocode and Route are registry-level only — the UI
 *   shows them as not implemented and the service refuses to save or activate
 *   them (capabilityImplemented() gate). No fake functionality is offered.
 */
class Superadmin_maps extends MY_Superadmin_Controller
{
    public function __construct()
    {
        parent::__construct();
        $this->load->library('firestore_service', null, 'fs');
        // Platform documents are tenant-less. Firestore_service still needs an
        // init; the schoolId it carries is irrelevant here because every
        // platform read/write uses get()/set() with a FIXED doc id, never
        // getEntity(), which would prefix a schoolId and scope the document.
        $this->fs->init('PLATFORM', '');
        $this->load->library('maps_config_service', null, 'maps');
        $this->maps->init($this->fs, 'PLATFORM', $this->sa_id ?? 'superadmin');
    }

    private function _json(array $p): void
    {
        $this->output->set_content_type('application/json')->set_output(json_encode($p));
    }

    public function index(): void
    {
        $cfg   = $this->maps->getPlatformConfig();
        $caps  = [];
        foreach (Maps_config_service::CAPABILITIES as $c) {
            $entry = $cfg['capabilities'][$c] ?? [];
            $caps[$c] = [
                'key'          => $c,
                'implemented'  => $this->maps->capabilityImplemented($c),
                'provider'     => (string) ($entry['provider'] ?? ''),
                'enabled'      => (bool) ($entry['enabled'] ?? false),
                'validation'   => (string) ($entry['validation_status'] ?? 'untested'),
                'validated_at' => $entry['validated_at'] ?? null,
                'error'        => (string) ($entry['validation_error'] ?? ''),
                // KEY NAMES only — never values. This is what the view renders.
                'public_keys'  => array_keys((array) ($entry['public'] ?? [])),
                'providers'    => $this->maps->providersForCapability(
                    $c, $c === Maps_config_service::CAP_RENDER ? 'admin' : 'server'),
            ];
        }
        $data = [
            'page_title'   => 'Maps Platform',
            'caps'         => $caps,
            'environment'  => $cfg['environment'],
            'commercial'   => $cfg['commercial_status'],
            'note'         => $cfg['commercial_note'],
            'updated_at'   => $cfg['updated_at'],
            'updated_by'   => $cfg['updated_by'],
            'registry'     => Maps_config_service::PROVIDERS,
        ];
        $this->load->view('superadmin/include/sa_header', $data);
        $this->load->view('superadmin/maps/index', $data);
        $this->load->view('superadmin/include/sa_footer');
    }

    public function save_capability(): void
    {
        $cap      = (string) $this->input->post('capability');
        $provider = (string) $this->input->post('provider');
        $public   = (array)  ($this->input->post('public') ?? []);
        $r = $this->maps->savePlatformCapability($cap, $provider, $public);
        $this->_json($r['ok']
            ? ['status' => 'success', 'message' => 'Saved. Test the configuration before activating.']
            : ['status' => 'error', 'message' => implode(' ', $r['errors'] ?? ['Save failed.'])]);
    }

    /**
     * Prove the credential works before it can go live.
     *
     * The probe is the registry's own (style_json / http_get), so adding a
     * provider does not mean adding a bespoke test here. A failure records the
     * reason and forces the capability to stay disabled.
     */
    public function test_capability(): void
    {
        $cap = (string) $this->input->post('capability');
        $cfg = $this->maps->getPlatformConfig();
        $entry = $cfg['capabilities'][$cap] ?? [];
        $provider = (string) ($entry['provider'] ?? '');
        if ($provider === '') {
            $this->_json(['status' => 'error', 'message' => 'Configure a provider first.']); return;
        }
        $probe = Maps_config_service::PROVIDERS[$provider]['probe'] ?? [];
        $url   = '';
        if (($probe['type'] ?? '') === 'style_json') {
            $url = (string) (($entry['public'] ?? [])[$probe['from'] ?? 'style_url'] ?? '');
        } elseif (($probe['type'] ?? '') === 'http_get') {
            $url = (string) ($probe['url'] ?? '');
            foreach ((array) ($entry['public'] ?? []) as $k => $v) { $url = str_replace('{' . $k . '}', $v, $url); }
        }
        if ($url === '' || strpos($url, '{') !== false) {
            $this->maps->setPlatformValidation($cap, false, 'Incomplete configuration for the probe.');
            $this->_json(['status' => 'error', 'message' => 'Incomplete configuration — cannot test.']); return;
        }
        $ok = false; $err = '';
        try {
            $ch = curl_init($url);
            curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15, CURLOPT_SSL_VERIFYPEER => true]);
            $body = curl_exec($ch);
            $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $err  = (string) curl_error($ch);
            curl_close($ch);
            $ok = ($code >= 200 && $code < 300 && $body !== false && $body !== '');
            if (!$ok && $err === '') { $err = 'Provider returned HTTP ' . $code; }
        } catch (\Throwable $e) { $err = $e->getMessage(); }

        $this->maps->setPlatformValidation($cap, $ok, $err);
        // The URL can embed a key — never echo it back in an error message.
        $this->_json($ok
            ? ['status' => 'success', 'message' => 'Connection test succeeded.']
            : ['status' => 'error', 'message' => 'Connection test failed: ' . $err]);
    }

    public function activate_capability(): void
    {
        $r = $this->maps->activatePlatformCapability((string) $this->input->post('capability'));
        $this->_json($r['ok']
            ? ['status' => 'success', 'message' => 'Activated. Schools inheriting the platform now use it.']
            : ['status' => 'error', 'message' => implode(' ', $r['errors'] ?? ['Activation failed.'])]);
    }

    public function deactivate_capability(): void
    {
        $r = $this->maps->deactivatePlatformCapability((string) $this->input->post('capability'));
        $this->_json($r['ok']
            ? ['status' => 'success', 'message' => 'Deactivated.']
            : ['status' => 'error', 'message' => implode(' ', $r['errors'] ?? ['Failed.'])]);
    }

    public function save_meta(): void
    {
        $r = $this->maps->savePlatformMeta(
            (string) $this->input->post('environment'),
            (string) $this->input->post('commercial_status'),
            (string) $this->input->post('commercial_note'));
        $this->_json($r['ok']
            ? ['status' => 'success', 'message' => 'Platform metadata saved.']
            : ['status' => 'error', 'message' => implode(' ', $r['errors'] ?? ['Failed.'])]);
    }
}
