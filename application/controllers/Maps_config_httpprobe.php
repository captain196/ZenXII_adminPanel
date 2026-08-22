<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Maps_config_httpprobe — HTTP-level validation of the Maps & Location admin
 * surface.
 *
 * ─── WHAT THIS IS, AND WHAT IT IS NOT ────────────────────────────────────
 * This is NOT the manual UAT. It cannot see the page, so it proves nothing
 * about layout, readability or whether a button looks disabled. What it DOES
 * prove is that every admin endpoint behaves correctly over real HTTP, through
 * the real session, CSRF and graded-RBAC stack — the behaviours behind
 * §3 A–Q of the validation brief.
 *
 * Read the results as "the endpoints are correct"; a human still has to
 * confirm the page renders them.
 *
 * ─── SESSIONS ARE SEEDED, NOT CREATED ────────────────────────────────────
 * Same technique accepted for `Transport_probe_httpauth`: a CI3 `files`-driver
 * session file is written directly for each persona. NOTHING is written to
 * Firestore, Firebase Auth or the staff collection — no account is created,
 * honouring the standing "don't create demo/test accounts" rule. Every seeded
 * file is deleted in teardown.
 *
 * Role labels are SYNTHETIC (`ZZ_MAPS_PROBE`) and absent from the tenant's
 * catalogue, so MY_Controller's RBAC re-resolve returns empty and the seeded
 * permission map is exactly what `require_permission()` evaluates. A real label
 * would be re-resolved from Firestore and the probe would measure the tenant's
 * live role config instead of the graded matrix.
 *
 * The tenant's real map configuration is snapshotted and restored.
 *
 * CLI-only:
 *   SCHOOL_ID=SCH_D94FE8F7AD SESSION_YEAR=2026-27 \
 *     [BASE_URL=http://localhost/ZenX/school/] php index.php maps_config_httpprobe run
 */
class Maps_config_httpprobe extends CI_Controller
{
    private array $seeded = [];
    private string $base = '';
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
        $e === $a ? $this->ok($id, $w) : $this->bad($id, $w, 'expected ' . var_export($e, true) . ', got ' . var_export($a, true));
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
        if (!function_exists('curl_init')) { fwrite(STDERR, "ERROR: ext-curl required.\n"); exit(1); }
        $this->base = rtrim((string) (getenv('BASE_URL') ?: 'http://localhost/ZenX/school/'), '/') . '/';

        echo "==================================================\n";
        echo " Maps & Location — HTTP endpoint validation\n";
        echo " base={$this->base} tenant={$sid}\n";
        echo " (endpoint behaviour only — NOT a visual UAT)\n";
        echo "==================================================\n";

        $this->load->library('firestore_service', null, 'fs');
        $this->fs->init($sid, $sess);
        $before = null; $beforeSecrets = null;
        try { $before        = $this->fs->getEntity('mapsConfig',  $sid); } catch (\Throwable $_) {}
        try { $beforeSecrets = $this->fs->getEntity('mapsSecrets', $sid); } catch (\Throwable $_) {}

        $manage = ['role' => 'ZZ_MAPS_PROBE', 'perms' => ['Configuration'], 'levels' => ['Configuration' => 'manage']];
        $view   = ['role' => 'ZZ_MAPS_PROBE', 'perms' => ['Configuration'], 'levels' => ['Configuration' => 'view']];
        $none   = ['role' => 'ZZ_MAPS_PROBE', 'perms' => [],                'levels' => []];

        try {
            $cManage = $this->seedSession($sid, $sess, 'manage', $manage);
            $cView   = $this->seedSession($sid, $sess, 'view',   $view);
            $cNone   = $this->seedSession($sid, $sess, 'none',   $none);

            $this->a_access($cManage, $cView, $cNone);
            $this->b_lifecycle($cManage);
            $this->c_modes($cManage);
            $this->d_secrets($cManage, $sid);
            $this->e_clientConfig($cManage);
        } catch (\Throwable $e) {
            $this->bad('EXC', 'unhandled exception', $e->getMessage());
        } finally {
            echo "\n[TEARDOWN]\n";
            foreach ($this->seeded as $f) { @unlink($f); }
            echo '  removed ' . count($this->seeded) . " seeded session file(s)\n";
            try {
                if (is_array($before)) { $this->fs->setEntity('mapsConfig', $sid, $before, false); echo "  config restored\n"; }
                else { $this->fs->removeEntity('mapsConfig', $sid); echo "  config removed (was absent)\n"; }
                if (is_array($beforeSecrets)) { $this->fs->setEntity('mapsSecrets', $sid, $beforeSecrets, false); echo "  secrets restored\n"; }
                else { $this->fs->removeEntity('mapsSecrets', $sid); echo "  secrets removed (were absent)\n"; }
            } catch (\Throwable $e) { echo '  !! RESTORE FAILED: ' . $e->getMessage() . "\n"; }
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

    // ── A. page + graded access (§3 A, and the RBAC split) ───────────────

    private function a_access(string $manage, string $view, string $none): void
    {
        echo "\n[A] Page load + graded access\n";
        $p = $this->http('GET', 'maps_config', $manage, [], /* ajax */ false);
        $this->eq('A1', 200, $p['code'], 'the Maps & Location page loads for a Configuration holder');
        $this->truthy('A2', stripos((string) $p['body'], 'Maps &amp; Location') !== false
                          || stripos((string) $p['body'], 'Maps & Location') !== false,
            'page renders its own heading');
        $this->truthy('A3', stripos((string) $p['body'], 'Operating mode') !== false,
            'mode selector present in the markup');
        $this->truthy('A4', stripos((string) $p['body'], 'server-only') !== false,
            'the key-handling warning about server-only credentials is shown');

        $this->eq('A5', 200, $this->http('GET', 'maps_config/get_config', $view)['code'],
            'view level CAN read the configuration');
        $this->eq('A6', 403, $this->http('POST', 'maps_config/activate', $view, ['provider' => 'maplibre'])['code'],
            'view level CANNOT activate (manage-only)');
        $this->eq('A7', 403, $this->http('POST', 'maps_config/save_mode', $view, ['mode' => 'global'])['code'],
            'view level CANNOT change mode');
        $this->eq('A8', 403, $this->http('GET', 'maps_config/get_config', $none)['code'],
            'no Configuration grant → denied');
        $anon = $this->http('GET', 'maps_config/get_config', null);
        $this->truthy('A9', in_array((int) $anon['code'], [401, 403, 302, 307], true),
            'unauthenticated request is refused (HTTP ' . $anon['code'] . ')');
    }

    // ── B. save → test → activate → disable (§3 E–L) ─────────────────────

    private function b_lifecycle(string $c): void
    {
        echo "\n[B] Provider lifecycle over HTTP\n";
        $save = $this->http('POST', 'maps_config/save_provider', $c, [
            'provider' => 'maplibre', 'style_url' => 'https://example.invalid/style.json',
        ]);
        $this->eq('B1', 200, $save['code'], 'save credentials succeeds');

        $cfg = json_decode((string) $this->http('GET', 'maps_config/get_config', $c)['body'], true);
        $entry = $cfg['config']['providers']['maplibre'] ?? [];
        $this->eq('B2', 'untested', $entry['validation_status'] ?? '', 'saving resets validation to untested');
        $this->eq('B3', false, $entry['enabled'] ?? null, 'saving leaves the provider DISABLED');

        $act = $this->http('POST', 'maps_config/activate', $c, ['provider' => 'maplibre']);
        $this->eq('B4', 422, $act['code'], 'activation is REFUSED before a successful test');
        $this->truthy('B5', stripos((string) $act['body'], 'connection test') !== false,
            'the refusal explains why', substr((string) $act['body'], 0, 120));

        // Invalid credentials must be visibly rejected (§3 I, L).
        $test = $this->http('POST', 'maps_config/test_connection', $c, ['provider' => 'maplibre']);
        $this->eq('B6', 200, $test['code'], 'test endpoint responds');
        $tj = json_decode((string) $test['body'], true);
        $this->eq('B7', false, $tj['ok'] ?? null, 'an unreachable style URL FAILS the test');
        $this->truthy('B8', trim((string) ($tj['message'] ?? '')) !== '',
            'a human-readable failure reason is returned: "' . ($tj['message'] ?? '') . '"');

        $cfg2 = json_decode((string) $this->http('GET', 'maps_config/get_config', $c)['body'], true);
        $e2 = $cfg2['config']['providers']['maplibre'] ?? [];
        $this->eq('B9', 'invalid', $e2['validation_status'] ?? '', 'validation status recorded as invalid');
        $this->truthy('B10', trim((string) ($e2['validated_at'] ?? '')) !== '',
            'last-validated timestamp is recorded');
        $this->truthy('B11', trim((string) ($e2['validation_error'] ?? '')) !== '',
            'the error message is persisted for display');
        $this->eq('B12', 422, $this->http('POST', 'maps_config/activate', $c, ['provider' => 'maplibre'])['code'],
            'a FAILED test still blocks activation');

        // Audit visible (§3 M).
        $aud = json_decode((string) $this->http('GET', 'maps_config/audit_history', $c)['body'], true);
        $this->truthy('B13', is_array($aud['rows'] ?? null) && count($aud['rows']) > 0,
            'change history is returned (' . count((array) ($aud['rows'] ?? [])) . ' rows)');
        $this->truthy('B14', strpos(json_encode($aud), 'style.json') === false
                           || strpos(json_encode($aud), 'example.invalid/style.json') === false,
            'audit does not echo credential values');
    }

    // ── C. modes (§3 B, C) ───────────────────────────────────────────────

    private function c_modes(string $c): void
    {
        echo "\n[C] Global / Platform mode\n";
        $bad = $this->http('POST', 'maps_config/save_mode', $c, ['mode' => 'global', 'global_provider' => 'maplibre']);
        $this->eq('C1', 422, $bad['code'], 'global mode refuses a provider that is not activated');

        $this->eq('C2', 422,
            $this->http('POST', 'maps_config/save_mode', $c, ['mode' => 'sideways'])['code'],
            'an unknown mode is rejected');

        $this->eq('C3', 200,
            $this->http('POST', 'maps_config/save_defaults', $c,
                ['default_lat' => '12.9716', 'default_lng' => '77.5946', 'default_zoom' => '14'])['code'],
            'map defaults save');
        $this->eq('C4', 422,
            $this->http('POST', 'maps_config/save_defaults', $c,
                ['default_lat' => '999', 'default_lng' => '77', 'default_zoom' => '14'])['code'],
            'an out-of-range centre is rejected');
    }

    // ── D. secrets never surface over HTTP (§3 N, §8) ────────────────────

    private function d_secrets(string $c, string $sid): void
    {
        echo "\n[D] Secret isolation over the wire\n";
        $this->http('POST', 'maps_config/save_provider', $c, [
            'provider' => 'mapbox',
            'public_token' => 'pk.HTTPPROBE_PUBLIC',
            'style_url'    => 'https://example.invalid/style.json',
            'secret_token' => 'sk.HTTPPROBE_SECRET',
        ]);
        $body = (string) $this->http('GET', 'maps_config/get_config', $c)['body'];
        $this->truthy('D1', strpos($body, 'sk.HTTPPROBE_SECRET') === false,
            'the SECRET never appears in the admin get_config response');
        $this->truthy('D2', strpos($body, 'pk.HTTPPROBE_PUBLIC') !== false,
            'the public token does appear (the map needs it)');

        $client = (string) $this->http('GET', 'maps_config/client_config?platform=parent', $c)['body'];
        $this->truthy('D3', strpos($client, 'sk.HTTPPROBE_SECRET') === false,
            'the SECRET never appears in the client payload');
    }

    // ── E. per-platform client payload (§4) ──────────────────────────────

    private function e_clientConfig(string $c): void
    {
        echo "\n[E] Per-platform client payload\n";
        foreach (['admin', 'parent', 'teacher', 'driver'] as $plat) {
            $r = $this->http('GET', 'maps_config/client_config?platform=' . $plat, $c);
            $this->eq('E-' . $plat, 200, $r['code'], "client_config resolves for '{$plat}'");
        }
        $this->eq('E1', 400, $this->http('GET', 'maps_config/client_config?platform=nope', $c)['code'],
            'an unknown platform is rejected');
    }

    // ─────────────────────────────────────────────────────────────────────

    /** @return array{code:int, body:string} */
    private function http(string $method, string $path, ?string $sessionId, array $body = [], bool $ajax = true): array
    {
        $csrf = 'probe' . bin2hex(random_bytes(8));
        $cookies = [];
        if ($sessionId !== null) { $cookies[] = 'grader_session=' . $sessionId; }
        $cookies[] = 'csrf_token=' . $csrf;

        $ch = curl_init($this->base . $path);
        $headers = ['Accept: application/json'];
        if ($ajax) { $headers[] = 'X-Requested-With: XMLHttpRequest'; }
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 45,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_COOKIE         => implode('; ', $cookies),
        ]);
        if ($method === 'POST') {
            $body['csrf_token'] = $csrf;
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($body));
        }
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        $out  = (string) curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return ['code' => $code, 'body' => $out];
    }

    /** Write a CI3 files-driver session; see the class docblock. */
    private function seedSession(string $schoolId, string $sessionYear, string $tag, array $p): string
    {
        $id  = $this->makeSessionId();
        $now = time();
        $data = [
            'admin_id'         => 'MAPSPROBE_' . strtoupper($tag),
            'user_id'          => 'MAPSPROBE_' . strtoupper($tag),
            'school_id'        => $schoolId,
            'schoolName'       => $schoolId,
            'school_code'      => $schoolId,
            'admin_name'       => 'Maps HTTP Probe',
            'admin_role'       => $p['role'],
            'session'          => $sessionYear,
            'current_session'  => $sessionYear,
            'session_year'     => $sessionYear,
            'rbac_permissions' => $p['perms'],
            'rbac_levels'      => $p['levels'],
            'rbac_ts'          => $now,
            '__ci_last_regenerate' => $now,
        ];
        $blob = '';
        foreach ($data as $k => $v) { $blob .= $k . '|' . serialize($v); }
        $dir  = APPPATH . 'sessions';
        if (!is_dir($dir)) { @mkdir($dir, 0700, true); }
        $file = $dir . DIRECTORY_SEPARATOR . 'grader_session' . $id;
        file_put_contents($file, $blob);
        $this->seeded[] = $file;
        return $id;
    }

    /**
     * A session id the WEB SERVER will accept. XAMPP's CLI reports
     * sid_length=26 while Apache generates 32, and PHP rejects a wrong-length
     * id outright — silently creating an empty session and 401ing everything.
     * So the length is learned from ids Apache itself wrote.
     */
    private function makeSessionId(): string
    {
        $len = 0;
        $dir = APPPATH . 'sessions';
        foreach ((array) @glob($dir . DIRECTORY_SEPARATOR . 'grader_session*') as $f) {
            $cand = strlen(substr(basename($f), strlen('grader_session')));
            if ($cand >= 22) { $len = $cand; break; }
        }
        if ($len === 0) { $len = (int) (ini_get('session.sid_length') ?: 32); }
        $bits  = (int) (ini_get('session.sid_bits_per_character') ?: 5);
        $chars = $bits === 4 ? '0123456789abcdef'
               : ($bits === 6 ? '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ,-'
                              : '0123456789abcdefghijklmnopqrstuv');
        $out = '';
        for ($i = 0; $i < $len; $i++) { $out .= $chars[random_int(0, strlen($chars) - 1)]; }
        return $out;
    }
}
