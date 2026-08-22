<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Transport_probe_httpauth — Transport-wide HTTP authorization probe.
 *
 * Proves that EVERY authorization-bearing Transport endpoint enforces the
 * canonical graded RBAC matrix over REAL HTTP — not by calling controller
 * methods in-process. That distinction matters: the RC-5 / SEC-4 incident was
 * a session-key drift that CLI probes walked straight past because they never
 * went through MY_Controller's session-backed auth path. Every assertion here
 * is a curl request with a session cookie, an AJAX header and a CSRF token.
 *
 * Originally written for the eight F12.1 compliance endpoints (M4); widened on
 * 2026-08-07 to the whole module when the legacy role-name fallback was retired
 * and `require_permission('Transport', <action>, <level>)` became the single
 * authorization mechanism for Transport.
 *
 * ─── COVERAGE IS DERIVED, NOT HAND-LISTED ────────────────────────────────
 * The endpoint table is built by SCANNING the Transport controllers for their
 * gates, so a newly added endpoint is probed automatically and a hand-written
 * list can never drift out of date. For each public method the required level
 * is the stronger of its own gate and its controller's constructor gate.
 *
 * ─── HOW THE SESSIONS ARE MADE (no test accounts created) ────────────────
 * CI3's `files` session driver stores one PHP-serialized file per session at
 * application/sessions/grader_session{id}. The probe writes such a file
 * directly for each persona and sends the matching cookie. NOTHING is written
 * to Firestore, Firebase Auth, or the staff collection — no login account is
 * created, which honours the standing "don't create demo/test accounts" rule.
 * Every seeded session file is deleted in teardown.
 *
 * ─── WHY THE REQUESTS ARE AJAX ───────────────────────────────────────────
 * MY_Controller re-resolves RBAC from Firestore on every NON-AJAX request
 * (rbacStale) and would overwrite the seeded permission map. On an AJAX
 * request within 5s of `rbac_ts` it honours the session map — and AJAX is
 * precisely how the Transport tabs call these endpoints in production, so this
 * is the real code path, not a shortcut.
 *
 * ─── WHY THE PAYLOADS ARE INVALID ────────────────────────────────────────
 * The probe asserts AUTHORIZATION, not business logic (that is covered by the
 * transport_probe_* suite). Writes therefore carry deliberately invalid input,
 * so an AUTHORIZED caller lands on 400/422 (validation) and an UNAUTHORIZED
 * caller on 403 (denied). The two are unambiguous and no data is mutated.
 *
 * ─── ENDPOINTS WHOSE ALLOW-PATH IS NOT EXERCISED ─────────────────────────
 * Two endpoints take no input and mutate other modules on entry — there is no
 * invalid payload that reaches the gate but not the side effect:
 *     transport_ops/reconcile_pending_posts   → posts expenses to Accounting
 *     transport/reconcile_pending_fees        → creates fee demands
 * For these the DENY path is asserted across every under-privileged persona
 * (the security-critical half) and the allow path is skipped. The skip is
 * counted and printed in the summary — never silently dropped.
 *
 * CLI-only:
 *   SCHOOL_ID=SCH_D94FE8F7AD SESSION_YEAR=2026-27 \
 *     [BASE_URL=http://localhost/ZenX/school/] php index.php transport_probe_httpauth run
 */
class Transport_probe_httpauth extends CI_Controller
{
    /** Controllers that carry NO per-request RBAC gate (CLI cron jobs, the
     *  verifier, and the probe suite itself) — nothing to assert over HTTP. */
    private const NOT_HTTP_GATED = [
        'Transport_verifier',
        'Transport_activity_cron', 'Transport_expiry_cron',
        'Transport_analytics_rollup_job', 'Transport_fleet_rollup_job',
        'Transport_expense_reconciler',
    ];

    /**
     * Transport_analytics is deliberately excluded: it gates on the
     * `Operations` module plus the F11 per-role WIDGET VISIBILITY matrix, a
     * presentation-scoping concern pinned by CH-TRN-SEC3-1 / CH-TRN-F11-4. It
     * is not part of the Transport graded surface this probe covers.
     */
    private const EXCLUDED_CONTROLLERS = ['Transport_analytics'];

    /** Allow-path not exercised — see the class docblock. */
    private const NO_EXECUTE = [
        'transport_ops/reconcile_pending_posts',
        'transport/reconcile_pending_fees',
    ];

    /**
     * Endpoints whose effective required level is NOT what the source scan
     * infers, plus the path segment / body they need.
     *
     *   level  — overrides the scanned level
     *   seg    — extra URI segment appended to the path
     *   body   — POST fields (presence of `body` forces POST)
     *   get    — force GET even though the level is above view
     */
    private const OVERRIDES = [
        // Delegates straight to start(), which re-gates at manage.
        'transport_rollover/continue_rollover' => ['level' => 'manage', 'body' => []],
        // GET reads that sit above view.
        'transport_compliance/rules'           => ['get' => true],
        // Path-segment endpoints.
        'transport_compliance/vehicle'         => ['seg' => 'PROBE_HTTP_NONE'],
        'transport_compliance/evaluate'        => ['seg' => 'PROBE_HTTP_NONE', 'body' => []],
        // Idempotent recomputes that take no input: sent as-is (safe).
        'transport_compliance/rebuild'         => ['body' => ['evaluate' => '0']],
    ];

    /** @var array<int,string> absolute paths of seeded session files */
    private array $seeded = [];

    private string $base = '';

    public function __construct()
    {
        parent::__construct();
        if (!$this->input->is_cli_request()) { show_error('CLI-only.', 403); }
    }

    public function run(): void
    {
        $sid  = (string) (getenv('SCHOOL_ID')    ?: '');
        $sess = (string) (getenv('SESSION_YEAR') ?: '');
        if ($sid === '' || $sess === '') { fwrite(STDERR, "ERROR: SCHOOL_ID + SESSION_YEAR required.\n"); exit(1); }
        if (!function_exists('curl_init')) { fwrite(STDERR, "ERROR: ext-curl required.\n"); exit(1); }

        $this->base = rtrim((string) (getenv('BASE_URL') ?: 'http://localhost/ZenX/school/'), '/') . '/';

        $passed = 0; $failed = 0; $skipped = 0;
        $pass = function (string $m) use (&$passed) { $passed++; };
        $fail = function (string $m) use (&$failed) { echo "  FAIL: {$m}\n"; $failed++; };

        // ─── WHY THE ROLE LABELS ARE SYNTHETIC ───────────────────────────
        // MY_Controller re-resolves RBAC from schools.staffRoles whenever
        // `rbacStale` is true and OVERWRITES the session map — but only when
        // the resolve returns something:
        //     if (!empty($freshModules) || !is_array($rbac_permissions)) { overwrite }
        // A label that exists in the catalogue therefore wins over anything
        // seeded here. An earlier revision of this probe used real labels and
        // was measuring the tenant's live role config, not the graded matrix:
        // 'Librarian' in this tenant grants Operations:manage, which grants
        // Transport:manage through RBAC_MODULE_CHILDREN — so every endpoint
        // correctly returned 200 and the probe wrongly called it a failure.
        //
        // Using a label that exists in NEITHER schools.staffRoles NOR the
        // legacy schools.roles map makes the resolve return empty, the
        // overwrite is skipped, and the seeded map is exactly what
        // require_permission() evaluates.
        $personas = [
            'manage'   => ['role' => 'ZZ_PROBE_ROLE', 'perms' => ['Transport'],  'levels' => ['Transport'  => 'manage'], 'synthetic' => true],
            'edit'     => ['role' => 'ZZ_PROBE_ROLE', 'perms' => ['Transport'],  'levels' => ['Transport'  => 'edit'],   'synthetic' => true],
            'view'     => ['role' => 'ZZ_PROBE_ROLE', 'perms' => ['Transport'],  'levels' => ['Transport'  => 'view'],   'synthetic' => true],
            // Umbrella inheritance: Operations is the parent of Transport, so
            // Operations:edit must satisfy Transport:edit and be denied manage.
            'umbrella' => ['role' => 'ZZ_PROBE_ROLE', 'perms' => ['Operations'], 'levels' => ['Operations' => 'edit'],   'synthetic' => true],
            // Holds nothing at all → every endpoint must fail closed.
            'none'     => ['role' => 'ZZ_PROBE_ROLE', 'perms' => [],             'levels' => [],                         'synthetic' => true],
            // ── The retirement regression guard ──────────────────────────
            // 'Principal' is a REAL catalogue label that sat in the MANAGE_ROLES
            // allow-list of every Transport controller, yet holds neither the
            // Transport nor the Operations module in this tenant. Under the old
            // fallback it reached every endpoint by NAME; under graded RBAC it
            // must be denied everywhere. Deliberately NOT synthetic: the seeded
            // map is left empty so MY_Controller resolves Principal's REAL
            // grants from Firestore — this exercises the live resolver
            // end-to-end rather than a hand-written map.
            //
            // Do NOT use a label that holds Transport/Operations here (an
            // earlier revision used 'Transport Manager', which genuinely holds
            // Operations:manage in this tenant — the resolve overwrote the
            // seeded map and the probe reported 125 false failures).
            'legacy'   => ['role' => 'Principal', 'perms' => [], 'levels' => [], 'synthetic' => false],
            // Hard bypass (RBAC_BYPASS_ROLES) — must pass everything.
            'ssa'      => ['role' => 'School Super Admin', 'perms' => [], 'levels' => [], 'synthetic' => true],
        ];

        $endpoints = $this->discoverEndpoints();
        $rank = ['view' => 1, 'edit' => 2, 'manage' => 3];

        echo "==================================================\n";
        echo " Transport — HTTP authorization probe (graded RBAC)\n";
        echo " base={$this->base}  tenant={$sid}\n";
        echo ' ' . count($endpoints) . ' endpoints x ' . count($personas) . " personas\n";
        echo "==================================================\n";

        try {
            foreach ($personas as $pname => $p) {
                $cookie = $this->seedSession($sid, $sess, $pname, $p);
                $held = (string) ($p['levels']['Transport'] ?? ($p['levels']['Operations'] ?? ''));
                $isBypass = in_array($p['role'], ['Super Admin', 'School Super Admin'], true);
                $pp = 0; $pf = 0; $ps = 0;

                foreach ($endpoints as $key => $e) {
                    $need    = $e['level'];
                    $allowed = $isBypass || ($held !== '' && $rank[$held] >= $rank[$need]);

                    // A NO_EXECUTE endpoint is only ever sent when the request
                    // is GUARANTEED to stop at the gate: the persona must be
                    // synthetic (so MY_Controller cannot re-resolve and widen
                    // it) AND expected-denied. Anything else is skipped.
                    //
                    // The guarantee matters: an earlier revision skipped on
                    // `$allowed` alone, and a catalogue-real persona that the
                    // resolver silently widened executed
                    // reconcile_pending_posts for real (retried 3 pending
                    // accounting posts).
                    if (in_array($key, self::NO_EXECUTE, true)
                        && !($p['synthetic'] && !$allowed)) { $ps++; $skipped++; continue; }

                    $res  = $this->httpCall($e['method'], $e['path'], $cookie, $e['body']);
                    $code = (int) $res['code'];

                    // A 401 means the seeded session was not read at all — the
                    // harness is broken, NOT the authorization. Treat it as a
                    // hard failure for BOTH branches: an earlier revision of
                    // this probe accepted "anything but 403" as an allow, and a
                    // wholly non-functioning harness reported 23 false passes.
                    if ($code === 401) {
                        $fail(sprintf('[%s] %-46s HARNESS BROKEN → 401 (session not read)', $pname, $key));
                        $pf++; continue;
                    }

                    if ($allowed) {
                        // Authorized → a POSITIVE signal from the business
                        // layer: 200 success, or 400/404/422 validation on the
                        // invalid payloads we deliberately send. Never 403/401.
                        if (in_array($code, [200, 400, 404, 422], true)) { $pass(''); $pp++; }
                        else { $fail(sprintf('[%s] %-46s %-6s allowed → HTTP %d %s', $pname, $key, $need, $code, substr((string) $res['body'], 0, 120))); $pf++; }
                    } else {
                        // Unauthorized → MUST be exactly 403, fail-closed.
                        if ($code === 403) { $pass(''); $pp++; }
                        else { $fail(sprintf('[%s] %-46s %-6s expected 403 → HTTP %d %s', $pname, $key, $need, $code, substr((string) $res['body'], 0, 120))); $pf++; }
                    }
                }
                printf("  [%-8s] role=%-20s held=%-7s pass=%-4d fail=%-3d skipped=%d\n",
                    $pname, $p['role'], $held !== '' ? $held : '-', $pp, $pf, $ps);
            }

            // Guard: an unauthenticated request must never reach an endpoint.
            $anon   = $this->httpCall('GET', 'transport_compliance/dashboard', null, []);
            $anonOk = in_array((int) $anon['code'], [401, 403], true);
            $anonOk ? $pass('') : $fail('anon dashboard → HTTP ' . $anon['code'] . ' (expected 401/403)');
            printf("  [%-8s] %-27s → HTTP %d %s\n", 'anon', 'no session cookie', (int) $anon['code'],
                $anonOk ? '(auth guard holds)' : '(UNEXPECTED)');
        } catch (\Throwable $e) {
            $fail('exception: ' . $e->getMessage());
        } finally {
            foreach ($this->seeded as $f) { @unlink($f); }
            echo "\n[TEARDOWN] removed " . count($this->seeded) . " seeded session files\n";
        }

        echo "\n==================================================\n";
        echo " RESULT: passed={$passed} failed={$failed} skipped={$skipped}\n";
        if ($skipped > 0) {
            echo ' SKIPPED allow-path (side-effecting, no validation barrier): '
               . implode(', ', self::NO_EXECUTE) . "\n";
        }
        echo "==================================================\n";
        exit($failed === 0 ? 0 : 2);
    }

    // ─────────────────────────────────────────────────────────────────────

    /**
     * Build the endpoint table by scanning the Transport controllers.
     *
     * For each `public function` the required level is the stronger of the
     * method's own `require_permission('Transport', …, <level>)` and its
     * controller's constructor gate. A controller with no gate at all is
     * skipped — and reported, so an ungated HTTP endpoint can never hide.
     *
     * @return array<string,array{path:string,method:string,level:string,body:array}>
     */
    private function discoverEndpoints(): array
    {
        $rank = ['view' => 1, 'edit' => 2, 'manage' => 3];
        $out  = [];

        foreach ((array) glob(APPPATH . 'controllers/Transport*.php') as $path) {
            $cls = basename($path, '.php');
            if (in_array($cls, self::NOT_HTTP_GATED, true)
                || in_array($cls, self::EXCLUDED_CONTROLLERS, true)
                || strpos($cls, 'Transport_probe') === 0) {
                continue;
            }
            $src = (string) file_get_contents($path);

            $ctorLevel = '';
            if (preg_match("/function __construct.*?require_permission\(\s*'Transport'\s*(?:,\s*'[a-z_]+'\s*,\s*'(view|edit|manage)'\s*)?\)/s", $src, $cm)) {
                $ctorLevel = $cm[1] ?? 'view';
            }

            preg_match_all('/public function ([a-z_][a-z0-9_]*)\s*\(/i', $src, $m, PREG_OFFSET_CAPTURE);
            foreach ($m[1] as $i => $hit) {
                $method = $hit[0];
                if ($method === '__construct') { continue; }

                $start = $m[0][$i][1];
                $end   = $m[0][$i + 1][1] ?? strlen($src);
                $body  = substr($src, $start, $end - $start);

                $level = $ctorLevel;
                if (preg_match("/require_permission\(\s*'Transport'\s*,\s*'[a-z_]+'\s*,\s*'(view|edit|manage)'\s*\)/", $body, $g)) {
                    if ($level === '' || $rank[$g[1]] > $rank[$level]) { $level = $g[1]; }
                }
                if ($level === '') {
                    echo "  !! UNGATED HTTP endpoint: " . strtolower($cls) . "/{$method}\n";
                    continue;
                }

                $key = strtolower($cls) . '/' . $method;
                $ov  = self::OVERRIDES[$key] ?? [];
                $lvl = $ov['level'] ?? $level;
                $uri = $key . (isset($ov['seg']) ? '/' . $ov['seg'] : '');

                // view-level endpoints are reads (GET); anything that mutates
                // is POSTed with a deliberately invalid/empty body.
                $isGet = ($ov['get'] ?? false) || ($lvl === 'view' && !isset($ov['body']));

                $out[$key] = [
                    'path'   => $uri,
                    'method' => $isGet ? 'GET' : 'POST',
                    'level'  => $lvl,
                    'body'   => $ov['body'] ?? [],
                ];
            }
        }
        ksort($out);
        return $out;
    }

    /**
     * Write a CI3 `files`-driver session and return its cookie id.
     * Format is PHP's native `php` serialize_handler: key|serialize(value)…
     */
    private function seedSession(string $schoolId, string $sessionYear, string $tag, array $p): string
    {
        $id  = $this->makeSessionId();
        $now = time();
        $data = [
            'admin_id'         => 'HTTPPROBE_' . strtoupper($tag),
            'user_id'          => 'HTTPPROBE_' . strtoupper($tag),
            'school_id'        => $schoolId,
            'schoolName'       => $schoolId,
            // school_code is REQUIRED: MY_Controller:310 passes it to
            // Firestore_service::init() as a non-nullable string, so omitting it
            // is a fatal TypeError (500) long before authorization is reached.
            // Legacy tenants without a login code fall back to school_id, which
            // is exactly what parent_db_key does at MY_Controller:306.
            'school_code'      => $schoolId,
            'admin_name'       => 'HTTP Auth Probe',
            'admin_role'       => $p['role'],
            // MY_Controller reads 'session'; Transport_trip/event/sos read
            // 'session_year' (both are written at login, Admin_login:668-676).
            // Seed BOTH or those three controllers exit before authorization
            // with "Session missing school/session context." and the probe
            // measures nothing.
            'session'          => $sessionYear,
            'current_session'  => $sessionYear,
            'session_year'     => $sessionYear,
            'rbac_permissions' => $p['perms'],
            'rbac_levels'      => $p['levels'],
            // Fresh timestamp keeps rbacStale FALSE on an AJAX request, so the
            // seeded map is what require_permission() evaluates.
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
     * A session id the WEB SERVER's PHP will accept.
     *
     * Deliberately does NOT use this CLI process's ini: XAMPP's CLI reports
     * session.sid_length=26 while Apache generates 32-character ids, and PHP
     * rejects an id of the wrong length outright — which silently produced a
     * brand-new empty session and a 401 on every request. The length is
     * therefore learned from the ids Apache itself has already written, and
     * only falls back to ini/32 when the directory is empty.
     */
    private function makeSessionId(): string
    {
        $len   = 0;
        $dir   = APPPATH . 'sessions';
        foreach ((array) @glob($dir . DIRECTORY_SEPARATOR . 'grader_session*') as $f) {
            $cand = strlen(substr(basename($f), strlen('grader_session')));
            if ($cand >= 22) { $len = $cand; break; }   // ignore lock/temp stubs
        }
        if ($len === 0) { $len = (int) (ini_get('session.sid_length') ?: 32); }

        $bits  = (int) (ini_get('session.sid_bits_per_character') ?: 5);
        $chars = $bits === 4 ? '0123456789abcdef'
               : ($bits === 6 ? '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ,-'
                              : '0123456789abcdefghijklmnopqrstuv');   // bits=5 default
        $out = '';
        for ($i = 0; $i < $len; $i++) { $out .= $chars[random_int(0, strlen($chars) - 1)]; }
        return $out;
    }

    /** One real HTTP request. @return array{code:int, body:string} */
    private function httpCall(string $method, string $path, ?string $sessionId, array $body): array
    {
        $csrf = 'probe' . bin2hex(random_bytes(8));
        $cookies = [];
        if ($sessionId !== null) { $cookies[] = 'grader_session=' . $sessionId; }
        $cookies[] = 'csrf_token=' . $csrf;

        $ch = curl_init($this->base . $path);
        $headers = ['X-Requested-With: XMLHttpRequest', 'Accept: application/json'];
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 60,
            CURLOPT_FOLLOWLOCATION => false,   // a redirect IS the signal for a non-AJAX denial
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
}
