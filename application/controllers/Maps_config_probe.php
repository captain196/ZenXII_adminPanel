<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Maps_config_probe — runtime validation of the centralized Maps platform.
 *
 * Exercises the real service against real Firestore, then RESTORES whatever
 * configuration was there before. The tenant's live map settings must survive
 * a probe run untouched — this reads and rewrites the actual config documents,
 * so the snapshot/restore in `finally` is not optional.
 *
 * No outbound provider calls: connection tests would need real third-party
 * credentials and would bill someone's account. The probe covers the routing,
 * gating and isolation logic around them; `_runProbe` itself is exercised only
 * for its refusal paths.
 *
 * CLI-only:
 *   SCHOOL_ID=SCH_D94FE8F7AD SESSION_YEAR=2026-27 php index.php maps_config_probe run
 */
class Maps_config_probe extends CI_Controller
{
    private int $pass = 0;
    private int $fail = 0;
    private array $failures = [];

    public function __construct()
    {
        parent::__construct();
        if (!$this->input->is_cli_request()) { show_error('CLI-only.', 403); }
    }

    private function ok(string $id, string $w): void { $this->pass++; printf("  PASS  %-5s %s\n", $id, $w); }
    private function bad(string $id, string $w, string $d): void
    {
        $this->fail++; $this->failures[] = "{$id} — {$w}: {$d}";
        printf("  FAIL  %-5s %s\n          → %s\n", $id, $w, $d);
    }
    private function eq(string $id, $e, $a, string $w): void
    {
        $e === $a ? $this->ok($id, $w)
            : $this->bad($id, $w, 'expected ' . var_export($e, true) . ', got ' . var_export($a, true));
    }
    private function truthy(string $id, bool $c, string $w, string $d = ''): void
    {
        $c ? $this->ok($id, $w) : $this->bad($id, $w, $d !== '' ? $d : 'condition false');
    }

    public function run(): void
    {
        $sid  = (string) (getenv('SCHOOL_ID')    ?: '');
        $sess = (string) (getenv('SESSION_YEAR') ?: '');
        if ($sid === '' || $sess === '') { fwrite(STDERR, "ERROR: SCHOOL_ID + SESSION_YEAR required.\n"); exit(1); }

        echo "==================================================\n";
        echo " Maps & Location platform — runtime validation\n";
        echo " tenant={$sid}\n";
        echo "==================================================\n";

        $this->load->library('firestore_service', null, 'fs');
        $this->fs->init($sid, $sess);
        $this->load->library('maps_config_service', null, 'maps');
        $this->maps->init($this->fs, $sid, 'PROBE_MAPS');

        // Snapshot BEFORE touching anything.
        $before = null; $beforeSecrets = null;
        try { $before        = $this->fs->getEntity(Maps_config_service::COL_CONFIG, $sid); } catch (\Throwable $_) {}
        try { $beforeSecrets = $this->fs->getEntity(Maps_config_service::COL_SECRETS, $sid); } catch (\Throwable $_) {}
        echo '  (snapshot taken: config=' . (is_array($before) ? 'present' : 'absent')
           . ', secrets=' . (is_array($beforeSecrets) ? 'present' : 'absent') . ")\n";

        try {
            $this->a_registry();
            $this->b_secretSplit($sid);
            $this->c_activationGate();
            $this->d_modeResolution();
            $this->e_clientPayload($sid);
            $this->f_defaults();
        } catch (\Throwable $e) {
            $this->bad('EXC', 'unhandled exception', $e->getMessage());
        } finally {
            echo "\n[RESTORE]\n";
            try {
                if (is_array($before)) {
                    $this->fs->setEntity(Maps_config_service::COL_CONFIG, $sid, $before, false);
                    echo "  config restored\n";
                } else {
                    $this->fs->removeEntity(Maps_config_service::COL_CONFIG, $sid);
                    echo "  config removed (was absent before)\n";
                }
                if (is_array($beforeSecrets)) {
                    $this->fs->setEntity(Maps_config_service::COL_SECRETS, $sid, $beforeSecrets, false);
                    echo "  secrets restored\n";
                } else {
                    $this->fs->removeEntity(Maps_config_service::COL_SECRETS, $sid);
                    echo "  secrets removed (were absent before)\n";
                }
            } catch (\Throwable $e) {
                echo '  !! RESTORE FAILED: ' . $e->getMessage() . "\n";
            }
        }

        echo "\n==================================================\n";
        echo " RESULT: pass={$this->pass} fail={$this->fail}\n";
        if ($this->failures !== []) {
            echo "\n DEFECTS:\n";
            foreach ($this->failures as $f) { echo "   • {$f}\n"; }
        }
        echo "==================================================\n";
        exit($this->fail === 0 ? 0 : 2);
    }

    // ── A. registry integrity ────────────────────────────────────────────

    private function a_registry(): void
    {
        echo "\n[A] Provider registry\n";
        $reg = Maps_config_service::PROVIDERS;
        foreach (['maplibre', 'maptiler', 'google', 'mapbox', 'here', 'tomtom'] as $p) {
            $this->truthy('A-' . $p, isset($reg[$p]), "provider '{$p}' registered");
        }
        $this->truthy('A1', isset($reg['maplibre']),
            'the EXISTING MapLibre implementation is registered as a provider, not rewritten');

        $bad = [];
        foreach ($reg as $id => $spec) {
            if (($spec['renderer'] ?? '') === '') { $bad[] = "{$id}: no renderer"; }
            if (!is_array($spec['credentials'] ?? null) || $spec['credentials'] === []) { $bad[] = "{$id}: no credentials"; }
            foreach ((array) ($spec['credentials'] ?? []) as $k => $m) {
                if (!in_array($m['sensitivity'] ?? '', [Maps_config_service::CRED_PUBLIC, Maps_config_service::CRED_SECRET], true)) {
                    $bad[] = "{$id}.{$k}: credential is not classified public/secret";
                }
            }
            if (($spec['probe']['type'] ?? '') === '') { $bad[] = "{$id}: no connection test defined"; }
        }
        $this->truthy('A2', $bad === [], 'every provider declares renderer, classified credentials and a probe',
            implode('; ', $bad));
    }

    // ── B. the secret split ──────────────────────────────────────────────

    private function b_secretSplit(string $sid): void
    {
        echo "\n[B] Secret split — the security property\n";
        $r = $this->maps->saveProvider('mapbox', [
            'public_token' => 'pk.PROBE_PUBLIC_TOKEN',
            'style_url'    => 'https://example.invalid/style.json',
            'secret_token' => 'sk.PROBE_SECRET_TOKEN',
        ]);
        $this->eq('B1', true, $r['ok'], 'provider credentials saved');

        $pub = $this->fs->getEntity(Maps_config_service::COL_CONFIG, $sid);
        $raw = json_encode($pub);
        $this->truthy('B2', strpos($raw, 'sk.PROBE_SECRET_TOKEN') === false,
            'the SECRET never appears in the client-readable config document');
        $this->truthy('B3', strpos($raw, 'pk.PROBE_PUBLIC_TOKEN') !== false,
            'the public token IS in the client-readable document (the map needs it)');

        $sec = $this->fs->getEntity(Maps_config_service::COL_SECRETS, $sid);
        $this->truthy('B4', is_array($sec) && ($sec['mapbox']['secret_token'] ?? '') === 'sk.PROBE_SECRET_TOKEN',
            'the secret landed in the server-only document');
        $this->truthy('B5', !isset($sec['mapbox']['public_token']),
            'public credentials are not duplicated into the secrets document');

        $miss = $this->maps->saveProvider('mapbox', ['public_token' => '', 'style_url' => '']);
        $this->eq('B6', false, $miss['ok'], 'required credentials are enforced');
    }

    // ── C. activation gate ───────────────────────────────────────────────

    private function c_activationGate(): void
    {
        echo "\n[C] Activation requires a passing test\n";
        $a = $this->maps->activateProvider('mapbox');
        $this->eq('C1', false, $a['ok'], 'an UNTESTED provider cannot be activated');
        $this->truthy('C2', stripos($a['message'], 'connection test') !== false,
            'the refusal explains what to do', $a['message']);

        $a2 = $this->maps->activateProvider('here');
        $this->eq('C3', false, $a2['ok'], 'an unconfigured provider cannot be activated');

        // A failing test must leave the provider inactive.
        $v = $this->maps->validateProvider('mapbox');
        $this->eq('C4', false, $v['ok'], 'connection test against an unreachable host fails');
        $this->eq('C5', Maps_config_service::VALIDATION_INVALID, $v['status'], 'status recorded as invalid');
        $this->eq('C6', false, $this->maps->activateProvider('mapbox')['ok'],
            'a FAILED test still blocks activation');
    }

    // ── D. mode resolution ───────────────────────────────────────────────

    private function d_modeResolution(): void
    {
        echo "\n[D] Global vs platform mode\n";
        // Force a provider live without a network call, so mode logic can be
        // tested independently of any third-party endpoint.
        $cfg = $this->maps->getConfig();
        $providers = (array) (is_array($cfg['providers'] ?? null) ? $cfg['providers'] : []);
        $providers['maplibre'] = [
            'public' => ['style_url' => 'https://example.invalid/style.json'],
            'enabled' => true, 'validation_status' => Maps_config_service::VALIDATION_VALID,
        ];
        // MapTiler, not Google, as the second provider. Both must be genuinely
        // assignable to the platform under test — Google has no renderer on any
        // platform, so assigning it is now correctly REFUSED, and using it here
        // would test the refusal rather than the two-providers-at-once path.
        $providers['maptiler'] = [
            'public' => ['api_key' => 'PROBE_MT_KEY', 'style_url' => 'https://example.invalid/mt.json'],
            'enabled' => true, 'validation_status' => Maps_config_service::VALIDATION_VALID,
        ];
        $this->fs->setEntity(Maps_config_service::COL_CONFIG, (string) getenv('SCHOOL_ID'),
            ['providers' => $providers], true);

        $g = $this->maps->saveMode(Maps_config_service::MODE_GLOBAL, 'maplibre', []);
        $this->eq('D1', true, $g['ok'], 'global mode saved');
        foreach (Maps_config_service::PLATFORMS as $p) {
            $this->eq('D2-' . $p, 'maplibre', $this->maps->resolveProvider($p),
                "global mode: {$p} resolves to the one provider");
        }

        $pm = $this->maps->saveMode(Maps_config_service::MODE_PLATFORM, 'maplibre', [
            'admin' => 'maptiler', 'parent' => 'maplibre', 'teacher' => '', 'driver' => '',
        ]);
        $this->eq('D3', true, $pm['ok'], 'platform mode saved');
        $this->eq('D4', 'maptiler', $this->maps->resolveProvider('admin'),  'admin uses its own provider');
        $this->eq('D5', 'maplibre', $this->maps->resolveProvider('parent'), 'parent uses its own provider');
        $this->eq('D6', 'maplibre', $this->maps->resolveProvider('teacher'),
            'a platform left BLANK falls back to global — switching modes never blanks a live map');

        $bad = $this->maps->saveMode(Maps_config_service::MODE_PLATFORM, 'maplibre', ['admin' => 'here']);
        $this->eq('D7', false, $bad['ok'], 'a platform cannot be pointed at a provider that is not activated');

        $unknown = $this->maps->saveMode('sideways', 'maplibre', []);
        $this->eq('D8', false, $unknown['ok'], 'an unknown mode is rejected');

        // ── platform-aware availability (correction 1, 2026-08-08) ──
        // TomTom is the case that forced this: Leaflet consumes its raster
        // tiles so the admin panel works, but there is no Android renderer.
        // Assigning it to Parent must be REFUSED rather than stored as an
        // unusable configuration that surfaces as a blank map on a phone.
        $this->eq('D9', true,  $this->maps->isSupportedOn('tomtom', 'admin'),
            'TomTom IS supported on admin (Leaflet renders its raster tiles)');
        $this->eq('D10', false, $this->maps->isSupportedOn('tomtom', 'parent'),
            'TomTom is NOT supported on parent (no Android renderer exists)');
        $this->eq('D11', false, $this->maps->isGlobalCapable('tomtom'),
            'TomTom cannot be a GLOBAL provider — global means every surface');

        // Force TomTom live so the refusal is about RENDERER availability,
        // not about activation.
        $cfg2 = $this->maps->getConfig();
        $prov2 = (array) (is_array($cfg2['providers'] ?? null) ? $cfg2['providers'] : []);
        $prov2['tomtom'] = ['public' => ['api_key' => 'PROBE_TT_KEY'], 'enabled' => true,
                            'validation_status' => Maps_config_service::VALIDATION_VALID];
        $this->fs->setEntity(Maps_config_service::COL_CONFIG, (string) getenv('SCHOOL_ID'),
            ['providers' => $prov2], true);

        $ttParent = $this->maps->saveMode(Maps_config_service::MODE_PLATFORM, 'maplibre', ['parent' => 'tomtom']);
        $this->eq('D12', false, $ttParent['ok'],
            'an ACTIVATED provider still cannot be assigned to a platform with no renderer');
        $this->truthy('D13', stripos(implode(' ', $ttParent['errors']), 'renderer') !== false,
            'the refusal names the missing renderer', implode(' ', $ttParent['errors']));

        $ttAdmin = $this->maps->saveMode(Maps_config_service::MODE_PLATFORM, 'maplibre', ['admin' => 'tomtom']);
        $this->eq('D14', true, $ttAdmin['ok'], 'TomTom IS assignable to admin, where a renderer exists');

        $ttGlobal = $this->maps->saveMode(Maps_config_service::MODE_GLOBAL, 'tomtom', []);
        $this->eq('D15', false, $ttGlobal['ok'], 'TomTom is refused as a GLOBAL provider');

        // Teacher/driver have configuration plumbing but no map UI, so an
        // assignment there renders nothing either way — allowed, and labelled
        // in the admin page rather than blocked.
        $tOk = $this->maps->saveMode(Maps_config_service::MODE_PLATFORM, 'maplibre', ['teacher' => 'maplibre']);
        $this->eq('D16', true, $tOk['ok'], 'a no-surface platform may still be assigned (stored for future use)');
    }

    // ── E. client payload isolation ──────────────────────────────────────

    private function e_clientPayload(string $sid): void
    {
        echo "\n[E] Client payload carries only what that surface needs\n";
        $this->maps->saveMode(Maps_config_service::MODE_PLATFORM, 'maplibre', [
            'admin' => 'maptiler', 'parent' => 'maplibre', 'teacher' => '', 'driver' => '',
        ]);

        $parent = $this->maps->getClientConfig('parent');
        $this->eq('E1', 'maplibre', $parent['provider'] ?? '', 'parent gets its resolved provider');
        $this->eq('E2', 'maplibre', $parent['renderer'] ?? '', 'renderer is resolved for the client');
        $raw = json_encode($parent);
        $this->truthy('E3', strpos($raw, 'PROBE_MT_KEY') === false,
            "parent payload excludes ANOTHER provider's credentials");
        $this->truthy('E4', strpos($raw, 'sk.') === false, 'parent payload contains no secret token');

        $admin = $this->maps->getClientConfig('admin');
        $this->eq('E5', 'maptiler', $admin['provider'] ?? '', 'admin gets a different provider in platform mode');
        $this->truthy('E6', strpos(json_encode($admin), 'PROBE_MT_KEY') !== false,
            'admin payload DOES carry its own public key');

        $this->truthy('E7', array_key_exists('default_zoom', $parent) && array_key_exists('default_center', $parent),
            'payload carries the map defaults');

        // The client must be TOLD whether its own platform can draw, so it can
        // say so instead of rendering a blank map.
        $this->eq('E8', true, $parent['supported'] ?? null,
            'payload reports supported=true where a renderer exists');
        $this->maps->saveMode(Maps_config_service::MODE_PLATFORM, 'maplibre', ['admin' => 'tomtom']);
        $adminTT = $this->maps->getClientConfig('admin');
        $this->eq('E9', true, $adminTT['supported'] ?? null, 'admin+TomTom reports supported=true');
    }

    // ── F. defaults validation ───────────────────────────────────────────

    private function f_defaults(): void
    {
        echo "\n[F] Map defaults\n";
        $this->eq('F1', true,  $this->maps->saveDefaults(12.9716, 77.5946, 14.0)['ok'], 'valid centre + zoom accepted');
        $this->eq('F2', false, $this->maps->saveDefaults(999.0, 77.0, 14.0)['ok'], 'out-of-range latitude rejected');
        $this->eq('F3', false, $this->maps->saveDefaults(12.0, 77.0, 40.0)['ok'], 'absurd zoom rejected');
        $this->eq('F4', true,  $this->maps->saveDefaults(null, null, 12.0)['ok'], 'a blank centre is allowed');

        // `auditHistory()` is an ORDERED QUERY, not a point read. A document
        // written moments earlier can be missing from query results until the
        // index catches up, which makes a bare count assertion racy — the most
        // likely cause of the single-assertion flake observed on 2026-08-08.
        //
        // Bounded retry, NOT a weakened assertion: it still requires rows to
        // appear, and fails if they never do. The coverage is identical; only
        // the race is removed.
        $hist = [];
        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $hist = $this->maps->auditHistory(10);
            if (count($hist) > 0) { break; }
            usleep(400_000);
        }
        $this->truthy('F5', count($hist) > 0,
            'audit history records the changes (' . count($hist) . ' rows)',
            'no audit rows visible after 5 attempts over ~2s');
        $this->truthy('F6', strpos(json_encode($hist), 'sk.PROBE_SECRET_TOKEN') === false,
            'the audit trail never stores credential VALUES');
    }
}
