<?php
/**
 * Firestore REST API client — no gRPC extension required.
 * Uses Google OAuth2 service account JWT -> access token flow + Firestore v1 REST API.
 */

if (!function_exists('base64url_encode')) {
    function base64url_encode(string $data): string {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
}

class FirestoreRestClient
{

    /**
     * Did the most recent query() fail?
     *
     * query() returns [] both when a collection is empty and when the request
     * failed, so the two are indistinguishable to a caller. This records which it
     * was. Read it immediately after the query you care about — it is reset at the
     * start of every query() call.
     */
    private $lastQueryFailed = false;

    /**
     * Pull something actionable out of a failed query response — Firestore's
     * FAILED_PRECONDITION carries the exact index-creation URL, which is the one
     * thing an operator actually needs and the old code discarded.
     */
    private function _indexHint(array $r): string
    {
        $b = $r['body'] ?? null;
        $msg = '';
        if (is_array($b)) {
            $msg = (string) ($b['error']['message'] ?? ($b[0]['error']['message'] ?? ''));
        } elseif (is_string($b)) {
            $msg = $b;
        }
        return $msg === '' ? '' : substr(preg_replace('/\s+/', ' ', $msg), 0, 400);
    }

    /** True if the last query() call failed rather than legitimately returned no rows. */
    public function lastQueryFailed(): bool
    {
        return $this->lastQueryFailed;
    }

    // Feature flag: flip to false to disable cURL reuse and fall back to per-call curl_init.
    // Kept as an explicit knob so we can instantly roll back without redeploying code.
    private const USE_PERSISTENT_CURL = true;

    private string $projectId;
    private string $databaseId;
    private array  $serviceAccount;
    private string $accessToken = '';
    private int    $tokenExpiry = 0;

    /** @var \CurlHandle|null Reused across all Firestore REST calls in a single request. */
    private $sharedCh = null;

    /** @var array<string,?array> request-scoped single-doc read memo; flushed on any write. */
    private $_readCache = [];

    /** @var bool When true, all WRITE operations return false without hitting Firestore */
    private bool $simulateFailure = false;

    // ── LOCAL-ONLY PERF PROBE (temporary diagnostic). Logs one line per request to
    //    application/logs/perf_probe.log, but ONLY on localhost — never CLI, never
    //    production. Remove this block + _registerProbe() + the 2 probe hooks when done.
    public static int   $probeHttpCalls  = 0;   // Firebase HTTP round-trips this request
    public static float $probeHttpMs     = 0.0; // wall-time spent in those round-trips
    public static int   $probeCacheHits  = 0;   // reads served from the memo (round-trips avoided)
    public static array $probeTargets    = [];   // what each round-trip read (collection/doc or query:col)
    private static bool $probeRegistered = false;

    // ── Cross-request cache: STABLE CONFIG docs ONLY ─────────────────────────
    // These collections change rarely and are read on nearly every page. The DB
    // lives in nam5 (US), so each read is a ~1.7s cross-region round-trip; caching
    // them locally for a few seconds removes that from most navigations. Business/
    // dynamic collections (students, fees, attendance, notices-as-data, …) are NEVER
    // listed here and are always read live. Writes bust the entry (see updateDocument
    // etc.), short TTL bounds any staleness, reads fail OPEN (cache error → live read),
    // and env DISABLE_DOC_CACHE=1 turns the whole thing off instantly.
    private const CONFIG_DOC_TTL = [
        'schools'           => 300,   // 5 min — changes rarely; writes bust it immediately
        'schoolControl'     => 300,
        'timetableSettings' => 300,
        'systemPlans'       => 900,   // 15 min — platform plans almost never change
        'tenantPublic'      => 900,
    ];

    public function __construct(string $serviceAccountPath, string $projectId, string $databaseId)
    {
        $json = file_get_contents($serviceAccountPath);
        if ($json === false) throw new \RuntimeException("Cannot read service account file: $serviceAccountPath");
        $this->serviceAccount = json_decode($json, true);
        if (!$this->serviceAccount) throw new \RuntimeException("Invalid service account JSON");
        $this->projectId  = $projectId;
        $this->databaseId = $databaseId;

        // Auto-detect simulation mode from CI config
        if (function_exists('get_instance')) {
            try {
                $CI =& get_instance();
                if (isset($CI->config) && $CI->config->item('simulate_firestore_failure')) {
                    $this->simulateFailure = true;
                    log_message('info', 'FirestoreRestClient: SIMULATION MODE ACTIVE — all writes will fail');
                }
            } catch (\Exception $e) { /* not in CI context */ }
        }

        $this->_registerProbe();
    }

    /** LOCAL-ONLY perf probe: register a one-line shutdown logger (localhost only). */
    private function _registerProbe(): void
    {
        if (self::$probeRegistered) return;
        $host = $_SERVER['HTTP_HOST'] ?? '';
        if (PHP_SAPI === 'cli'
            || (strpos($host, 'localhost') === false && strpos($host, '127.0.0.1') === false)) {
            return; // never in CLI or production
        }
        self::$probeRegistered = true;
        register_shutdown_function(function () {
            $start   = $_SERVER['REQUEST_TIME_FLOAT'] ?? microtime(true);
            $totalMs = (microtime(true) - $start) * 1000;
            $uri     = $_SERVER['REQUEST_URI'] ?? '?';
            $line = sprintf(
                "[%s] %-40s wall=%6.0fms  calls=%-2d  fb_ms=%6.0f  memo=%-2d  reads=[%s]\n",
                date('H:i:s'), substr($uri, 0, 40), $totalMs,
                self::$probeHttpCalls, self::$probeHttpMs, self::$probeCacheHits,
                implode(', ', self::$probeTargets)
            );
            @file_put_contents(APPPATH . 'logs/perf_probe.log', $line, FILE_APPEND | LOCK_EX);
        });
    }

    /**
     * Enable/disable Firestore write failure simulation.
     * When enabled: reads work normally, writes return false with logged error.
     */
    public function setSimulateFailure(bool $enabled): void
    {
        $this->simulateFailure = $enabled;
        if (function_exists('log_message')) {
            log_message('info', 'FirestoreRestClient: simulation mode ' . ($enabled ? 'ON' : 'OFF'));
        }
    }

    public function isSimulating(): bool
    {
        return $this->simulateFailure;
    }

    /**
     * Check simulation mode before a write. Returns true if the write should be blocked.
     */
    private function _blockWrite(string $op, string $path): bool
    {
        if (!$this->simulateFailure) return false;
        if (function_exists('log_message')) {
            log_message('error', "SIMULATED FIRESTORE FAILURE: {$op} {$path} — write blocked, RTDB should still work");
        }
        return true;
    }

    private function baseUrl(): string
    {
        return "https://firestore.googleapis.com/v1/projects/{$this->projectId}/databases/{$this->databaseId}/documents";
    }

    /** Cross-request persisted token cache key — shared across PHP processes. */
    private const TOKEN_CACHE_KEY     = 'grader_firebase_oauth_token_v1';
    /** Refresh a little before Google-reported expiry to avoid using near-expired tokens. */
    private const TOKEN_EXPIRY_BUFFER = 60;
    /** Stampede lock lifetime — auto-released if a fetch hangs or crashes. */
    private const TOKEN_LOCK_TTL_SEC  = 10;
    /** Retry-read loop: attempts × sleep. 3 × ~150ms ≈ 450ms worst case wait. */
    private const TOKEN_WAIT_ATTEMPTS = 3;
    private const TOKEN_WAIT_SLEEP_MS = 150;

    /**
     * Read the cross-request OAuth token cache. Returns
     * ['access_token' => ..., 'expires_at' => ...] or null on miss.
     *
     * A corrupted / malformed cache file is treated as a miss and deleted
     * so the next writer starts clean.
     */
    private function loadCachedToken(): ?array
    {
        if (function_exists('apcu_fetch') && ini_get('apc.enabled')) {
            $ok = false;
            $env = apcu_fetch(self::TOKEN_CACHE_KEY, $ok);
            if ($ok && is_array($env) && isset($env['access_token'], $env['expires_at'])) {
                return $env;
            }
        }
        $f = $this->tokenCacheFile();
        if ($f !== null && is_file($f)) {
            $raw = @file_get_contents($f);
            if ($raw === false || $raw === '') {
                @unlink($f);
                return null;
            }
            $env = json_decode($raw, true);
            if (!is_array($env)
                || !isset($env['access_token'], $env['expires_at'])
                || !is_string($env['access_token'])
                || !is_numeric($env['expires_at'])
            ) {
                if (function_exists('log_message')) {
                    log_message('error', 'FIREBASE_TOKEN_CACHE corrupted file at ' . $f . ' — deleting');
                }
                @unlink($f);
                return null;
            }
            return $env;
        }
        return null;
    }

    /**
     * Persist a fresh token to the cross-request cache. APCu when available,
     * file fallback otherwise. File write is atomic (temp file + rename)
     * with 0600 perms. Failures are silent — the caller continues with
     * the in-memory copy and we'll just refetch next request.
     */
    private function saveCachedToken(string $token, int $expiresAt): void
    {
        $env = ['access_token' => $token, 'expires_at' => $expiresAt];
        if (function_exists('apcu_store') && ini_get('apc.enabled')) {
            @apcu_store(self::TOKEN_CACHE_KEY, $env, max(60, $expiresAt - time()));
        }
        $f = $this->tokenCacheFile();
        if ($f === null) return;

        $json = json_encode($env);
        if ($json === false) return;

        // Atomic write: write to a process-unique temp file in the same
        // directory, then rename over the target. Rename is atomic on
        // POSIX and best-effort on Windows (PHP 7.3+ replaces existing).
        $tmp = $f . '.tmp.' . getmypid() . '.' . bin2hex(random_bytes(4));
        if (@file_put_contents($tmp, $json, LOCK_EX) === false) {
            return;
        }
        @chmod($tmp, 0600);
        if (!@rename($tmp, $f)) {
            // Windows corner case: rename failed because target is held open
            // by a concurrent reader. Fall back to in-place write — tokens
            // are idempotent so "last wins" is still correct.
            @file_put_contents($f, $json, LOCK_EX);
            @chmod($f, 0600);
            @unlink($tmp);
        }
    }

    private function tokenCacheFile(): ?string
    {
        if (!defined('APPPATH')) return null;
        $dir = APPPATH . 'cache/firebase_auth';
        if (!is_dir($dir)) {
            // 0700 — owner-only. OAuth tokens are sensitive and must not be
            // readable by other system users even if APPPATH/cache is world-
            // readable for other CI cache entries.
            @mkdir($dir, 0700, true);
            @chmod($dir, 0700);
        }
        if (!is_dir($dir)) return null;
        return $dir . '/oauth_token.json';
    }

    private function tokenLockFile(): ?string
    {
        if (!defined('APPPATH')) return null;
        $dir = APPPATH . 'cache/firebase_auth';
        if (!is_dir($dir)) { @mkdir($dir, 0700, true); }
        if (!is_dir($dir)) return null;
        return $dir . '/oauth_token.lock';
    }

    /**
     * Acquire a short-lived lock so only one PHP process fetches a fresh
     * token while others wait on the cache. Returns an opaque lock handle
     * or false if the lock is already held.
     *
     * APCu path uses apcu_add (atomic compare-and-set with TTL). File
     * fallback uses flock(LOCK_EX | LOCK_NB) on a dedicated lock file.
     */
    private function acquireTokenLock()
    {
        $lockKey = self::TOKEN_CACHE_KEY . '_lock';
        if (function_exists('apcu_add') && ini_get('apc.enabled')) {
            if (@apcu_add($lockKey, getmypid(), self::TOKEN_LOCK_TTL_SEC)) {
                return ['type' => 'apcu', 'key' => $lockKey];
            }
            return false;
        }
        $lockFile = $this->tokenLockFile();
        if ($lockFile === null) return false;
        $fp = @fopen($lockFile, 'c');
        if ($fp === false) return false;
        if (!@flock($fp, LOCK_EX | LOCK_NB)) {
            @fclose($fp);
            return false;
        }
        return ['type' => 'file', 'handle' => $fp];
    }

    private function releaseTokenLock($lock): void
    {
        if (!is_array($lock)) return;
        if ($lock['type'] === 'apcu') {
            @apcu_delete($lock['key']);
        } elseif ($lock['type'] === 'file' && isset($lock['handle'])) {
            @flock($lock['handle'], LOCK_UN);
            @fclose($lock['handle']);
        }
    }

    private function getAccessToken(): string
    {
        // Per-request memo — fastest path, no I/O.
        if ($this->accessToken !== '' && time() < $this->tokenExpiry - self::TOKEN_EXPIRY_BUFFER) {
            return $this->accessToken;
        }

        // Cross-request cache (APCu → file) — eliminates the OAuth2 round-trip
        // to oauth2.googleapis.com (which takes 1–3s on slow networks) on
        // every PHP request by reusing the last valid token.
        $cached = $this->loadCachedToken();
        if (is_array($cached) && (int) $cached['expires_at'] > time() + self::TOKEN_EXPIRY_BUFFER) {
            $this->accessToken = (string) $cached['access_token'];
            $this->tokenExpiry = (int) $cached['expires_at'];
            if (function_exists('log_message')) {
                log_message('debug', 'FIREBASE_TOKEN_CACHE HIT expires_in=' . ($this->tokenExpiry - time()) . 's');
            }
            return $this->accessToken;
        }

        // Cache MISS — stampede protection. Only one PHP process fetches
        // a fresh token; others wait briefly and then re-read the cache
        // which the lock-holder just populated.
        $lock = $this->acquireTokenLock();
        if ($lock === false) {
            if (function_exists('log_message')) {
                log_message('debug', 'FIREBASE_TOKEN_REFRESH_LOCK_WAIT — another process is fetching');
            }
            for ($i = 0; $i < self::TOKEN_WAIT_ATTEMPTS; $i++) {
                usleep(self::TOKEN_WAIT_SLEEP_MS * 1000);
                $cached = $this->loadCachedToken();
                if (is_array($cached) && (int) $cached['expires_at'] > time() + self::TOKEN_EXPIRY_BUFFER) {
                    $this->accessToken = (string) $cached['access_token'];
                    $this->tokenExpiry = (int) $cached['expires_at'];
                    if (function_exists('log_message')) {
                        log_message('debug', 'FIREBASE_TOKEN_CACHE HIT after wait expires_in=' . ($this->tokenExpiry - time()) . 's');
                    }
                    return $this->accessToken;
                }
            }
            // Lock holder hung or crashed — fall through and fetch directly.
            // Worst case: two processes fetch concurrently; both get valid
            // tokens; last write wins. Safe.
            if (function_exists('log_message')) {
                log_message('error', 'FIREBASE_TOKEN_REFRESH_LOCK_WAIT exhausted — fetching without lock');
            }
        } else {
            if (function_exists('log_message')) {
                log_message('debug', 'FIREBASE_TOKEN_REFRESH_LOCK_ACQUIRED — fetching fresh token from Google');
            }
        }
        if (function_exists('log_message')) {
            log_message('debug', 'FIREBASE_TOKEN_CACHE MISS — fetching fresh token from Google');
        }

        $now = time();
        $header  = base64url_encode(json_encode(['alg' => 'RS256', 'typ' => 'JWT']));
        $payload = base64url_encode(json_encode([
            'iss'   => $this->serviceAccount['client_email'],
            'scope' => 'https://www.googleapis.com/auth/datastore',
            'aud'   => 'https://oauth2.googleapis.com/token',
            'iat'   => $now,
            'exp'   => $now + 3600,
        ]));
        $signInput = "$header.$payload";
        $pk = openssl_pkey_get_private($this->serviceAccount['private_key']);
        if (!$pk) throw new \RuntimeException('Invalid private key in service account');
        openssl_sign($signInput, $signature, $pk, OPENSSL_ALGO_SHA256);
        $jwt = $signInput . '.' . base64url_encode($signature);

        $ch = curl_init('https://oauth2.googleapis.com/token');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => http_build_query([
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion'  => $jwt,
            ]),
            CURLOPT_TIMEOUT        => 10,
        ]);
        $resp = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($code !== 200) {
            // Release lock before throwing so waiting siblings don't stall.
            if (isset($lock) && $lock !== false) $this->releaseTokenLock($lock);
            throw new \RuntimeException("OAuth token request failed ($code): $resp");
        }
        $data = json_decode($resp, true);
        $this->accessToken = $data['access_token'];
        $this->tokenExpiry = $now + ($data['expires_in'] ?? 3600);
        // Persist across requests so the next PHP process doesn't re-run
        // the JWT sign + HTTPS POST to Google.
        try {
            $this->saveCachedToken($this->accessToken, $this->tokenExpiry);
        } catch (\Exception $e) {
            if (function_exists('log_message')) {
                log_message('error', 'FIREBASE_TOKEN_CACHE save failed: ' . $e->getMessage());
            }
        }
        // Release the stampede lock after cache is populated so waiting
        // siblings find the fresh token on their next retry.
        if (isset($lock) && $lock !== false) $this->releaseTokenLock($lock);
        return $this->accessToken;
    }

    /**
     * Lazily create (or return) a reusable cURL handle with TCP keep-alive,
     * so successive Firestore calls reuse the same TCP+TLS connection
     * instead of paying a fresh handshake (~500ms–12s first-connect on slow
     * networks). MUST be paired with curl_reset() on every call to avoid
     * option bleed between requests.
     */
    private function sharedHandle()
    {
        if ($this->sharedCh === null) {
            $this->sharedCh = curl_init();
        }
        return $this->sharedCh;
    }

    public function __destruct()
    {
        if ($this->sharedCh !== null) {
            @curl_close($this->sharedCh);
            $this->sharedCh = null;
        }
    }

    private function request(string $method, string $url, ?array $body = null): array
    {
        self::$probeHttpCalls++;
        // probe: record WHAT this round-trip reads (GET path, or query collection)
        if (preg_match('#/documents/([^:?]+)#', $url, $__m)) {
            self::$probeTargets[] = $__m[1];
        } elseif (strpos($url, ':runQuery') !== false && is_array($body)) {
            self::$probeTargets[] = 'query:' . ($body['structuredQuery']['from'][0]['collectionId'] ?? '?');
        } elseif (strpos($url, ':runAggregation') !== false) {
            self::$probeTargets[] = 'aggregate';
        } else {
            self::$probeTargets[] = strtolower($method);
        }
        $__t0 = microtime(true);
        $__r  = $this->_requestImpl($method, $url, $body);
        self::$probeHttpMs += (microtime(true) - $__t0) * 1000;
        return $__r;
    }

    private function _requestImpl(string $method, string $url, ?array $body = null): array
    {
        $token = $this->getAccessToken();

        if (self::USE_PERSISTENT_CURL) {
            $ch = $this->sharedHandle();
            // curl_reset wipes all options but PRESERVES the underlying
            // connection pool, so keep-alive still applies on the next call.
            curl_reset($ch);
            $owned = false;
        } else {
            $ch = curl_init();
            $owned = true;
        }

        $headers = [
            'Authorization: Bearer ' . $token,
            'Content-Type: application/json',
        ];
        $opts = [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_TCP_KEEPALIVE  => 1,
            CURLOPT_TCP_KEEPIDLE   => 60,
            CURLOPT_FORBID_REUSE   => 0,
            CURLOPT_FRESH_CONNECT  => 0,
        ];
        if ($method === 'GET') {
            $opts[CURLOPT_HTTPGET] = true;
        } elseif ($method === 'DELETE') {
            $opts[CURLOPT_CUSTOMREQUEST] = 'DELETE';
        } elseif ($method === 'PATCH') {
            $opts[CURLOPT_CUSTOMREQUEST] = 'PATCH';
            if ($body !== null) $opts[CURLOPT_POSTFIELDS] = json_encode($body);
        } elseif ($method === 'POST') {
            $opts[CURLOPT_POST] = true;
            if ($body !== null) $opts[CURLOPT_POSTFIELDS] = json_encode($body);
        }
        curl_setopt_array($ch, $opts);
        $resp = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        if ($owned) curl_close($ch);
        return ['code' => $code, 'body' => json_decode($resp, true) ?? []];
    }

    private function encode($value): array
    {
        if ($value === null)         return ['nullValue' => null];
        if (is_bool($value))         return ['booleanValue' => $value];
        if (is_int($value))          return ['integerValue' => (string)$value];
        if (is_float($value))        return ['doubleValue' => $value];
        if (is_string($value))       return ['stringValue' => $value];
        // DateTimeInterface → Firestore Timestamp (enables Firestore
        // TTL policies on admin-written fields).
        if ($value instanceof \DateTimeInterface) {
            return ['timestampValue' => $value->format('Y-m-d\TH:i:s.u\Z')];
        }
        if (is_array($value)) {
            // Sentinel: ['__firestore_type' => 'timestamp', 'value' => 'ISO 8601 str']
            // Lets PHP callers opt in to Timestamp without importing
            // the heavy google/cloud-firestore class tree everywhere.
            if (isset($value['__firestore_type'])) {
                switch ($value['__firestore_type']) {
                    case 'timestamp':
                        return ['timestampValue' => (string)($value['value'] ?? '')];
                }
            }
            if (empty($value))       return ['arrayValue' => ['values' => []]];
            if (array_is_list($value)) {
                return ['arrayValue' => ['values' => array_map([$this, 'encode'], $value)]];
            }
            $fields = [];
            foreach ($value as $k => $v) $fields[$k] = $this->encode($v);
            return ['mapValue' => ['fields' => $fields]];
        }
        // stdClass → mapValue. Callers use `new \stdClass()` (or cast an
        // array to object) to force map type when the natural shape would
        // otherwise serialise as an array — most importantly, for empty
        // maps. Without this branch the empty case fell through to
        // `(string) $value` which fatals on objects and 500s the request.
        //
        // The inner `fields` wrapper is itself a stdClass so PHP's
        // json_encode emits `{}` for the empty case (an empty PHP array
        // would encode as `[]` and Firestore's REST API rejects
        // `{"mapValue":{"fields":[]}}` as a malformed mapValue).
        if ($value instanceof \stdClass) {
            $fields = new \stdClass();
            foreach (get_object_vars($value) as $k => $v) {
                $fields->{(string) $k} = $this->encode($v);
            }
            return ['mapValue' => ['fields' => $fields]];
        }
        return ['stringValue' => (string)$value];
    }

    /**
     * Helper for callers: wrap an epoch-millis or DateTimeInterface into
     * a sentinel the encoder will serialise as a Firestore Timestamp
     * (not a String). Required for fields targeted by Firestore TTL.
     *
     * Usage:
     *   'expiresAtTs' => Firestore_rest_client::timestamp($millis)
     */
    public static function timestamp($millisOrDt): array
    {
        if ($millisOrDt instanceof \DateTimeInterface) {
            $iso = $millisOrDt->format('Y-m-d\TH:i:s.u\Z');
        } else {
            $ms = (int) $millisOrDt;
            $iso = gmdate('Y-m-d\TH:i:s', (int)($ms / 1000))
                 . '.' . str_pad((string)(($ms % 1000) * 1000), 6, '0', STR_PAD_LEFT) . 'Z';
        }
        return ['__firestore_type' => 'timestamp', 'value' => $iso];
    }

    private function decode(array $val)
    {
        if (array_key_exists('nullValue', $val))     return null;
        if (isset($val['booleanValue']))             return $val['booleanValue'];
        if (isset($val['integerValue']))             return (int)$val['integerValue'];
        if (isset($val['doubleValue']))              return (float)$val['doubleValue'];
        if (isset($val['stringValue']))              return $val['stringValue'];
        if (isset($val['timestampValue']))           return $val['timestampValue'];
        if (isset($val['arrayValue'])) {
            return array_map([$this, 'decode'], $val['arrayValue']['values'] ?? []);
        }
        if (isset($val['mapValue'])) {
            $result = [];
            foreach (($val['mapValue']['fields'] ?? []) as $k => $v) $result[$k] = $this->decode($v);
            return $result;
        }
        if (isset($val['geoPointValue']))            return $val['geoPointValue'];
        if (isset($val['referenceValue']))           return $val['referenceValue'];
        if (isset($val['bytesValue']))               return $val['bytesValue'];
        return null;
    }

    private function decodeDocument(array $doc): array
    {
        $fields = $doc['fields'] ?? [];
        $result = [];
        foreach ($fields as $k => $v) $result[$k] = $this->decode($v);
        // Phase 8A — surface Firestore's server-assigned updateTime so
        // callers can use it as a currentDocument.updateTime precondition
        // on the next write (CAS / optimistic concurrency). Reserved key
        // `__updateTime` — collides with no domain field because the
        // encoder would never write a key starting with `__`.
        if (isset($doc['updateTime']) && is_string($doc['updateTime'])) {
            $result['__updateTime'] = $doc['updateTime'];
        }
        return $result;
    }

    private function docIdFromName(string $name): string
    {
        $parts = explode('/', $name);
        return end($parts);
    }

    public function getDocument(string $collection, string $docId): ?array
    {
        // PERF: request-scoped read memo. The same document (especially
        // schools/{id}, staff/{id}, feeSettings) is read many times across
        // MY_Controller + the page controller within a single request, and each
        // read is a cross-region REST round-trip. Serve repeat reads of the same
        // doc from memory. The memo is FLUSHED on every write (see the write
        // methods below), so a read after your OWN write is always fresh — no
        // staleness is possible within a request. Null results are cached too.
        $ck = $collection . "\0" . $docId;
        if (array_key_exists($ck, $this->_readCache)) {
            self::$probeCacheHits++;
            return $this->_readCache[$ck];
        }
        // Cross-request cache — whitelisted STABLE config docs only (see const above).
        $cfgFile = $this->_configCacheFile($collection, $docId);
        if ($cfgFile !== null) {
            $rc = $this->_configCacheRead($cfgFile);
            if ($rc['hit']) {
                self::$probeCacheHits++;
                return $this->_readCache[$ck] = $rc['val'];
            }
        }
        // Bound memory for long-running CLI/batch processes that sequentially read
        // many UNIQUE docs in one PHP process (a normal web request never gets near
        // this cap — it re-reads a handful of context docs).
        if (count($this->_readCache) >= 1000) {
            $this->_readCache = [];
        }
        $val = $this->_getDocumentUncached($collection, $docId);
        $this->_readCache[$ck] = $val;
        if ($cfgFile !== null) {
            $this->_configCacheWrite($cfgFile, $val, self::CONFIG_DOC_TTL[$collection]);
        }
        return $val;
    }

    private function _getDocumentUncached(string $collection, string $docId): ?array
    {
        $safeDocId = rawurlencode($docId);
        $url = $this->baseUrl() . "/$collection/$safeDocId";
        $r = $this->request('GET', $url);
        if ($r['code'] === 404) return null;
        if ($r['code'] !== 200) {
            if (function_exists('log_message')) log_message('error', "FirestoreREST::get $collection/$docId HTTP {$r['code']}: " . json_encode($r['body']));
            return null;
        }
        return $this->decodeDocument($r['body']);
    }

    // ── Cross-request config-doc cache helpers (file-based, fail-open) ────────
    private function _configCacheFile(string $collection, string $docId): ?string
    {
        if (getenv('DISABLE_DOC_CACHE') || !isset(self::CONFIG_DOC_TTL[$collection])) {
            return null;
        }
        $dir = APPPATH . 'cache/fsdoc/';
        if (!is_dir($dir)) { @mkdir($dir, 0755, true); }
        if (!is_dir($dir) || !is_writable($dir)) return null; // fail open — no cache
        return $dir . md5($this->projectId . ':' . $collection . ':' . $docId) . '.cache';
    }

    /** @return array{hit:bool,val:?array} */
    private function _configCacheRead(string $file): array
    {
        if (!is_file($file)) return ['hit' => false, 'val' => null];
        $raw = @file_get_contents($file);
        if ($raw === false) return ['hit' => false, 'val' => null];
        $rec = @unserialize($raw);
        if (!is_array($rec) || !isset($rec['exp']) || $rec['exp'] < time()) {
            return ['hit' => false, 'val' => null];
        }
        return ['hit' => true, 'val' => is_array($rec['val']) ? $rec['val'] : null];
    }

    private function _configCacheWrite(string $file, ?array $val, int $ttl): void
    {
        @file_put_contents($file, serialize(['exp' => time() + $ttl, 'val' => $val]), LOCK_EX);
    }

    private function _bustConfigCache(string $collection, string $docId): void
    {
        $f = $this->_configCacheFile($collection, $docId);
        if ($f !== null && is_file($f)) { @unlink($f); }
    }

    /**
     * List every document in a SUBcollection under a specific parent doc,
     * e.g. `stories/{docId}/viewers`. Read-only, paginated. Returns
     * [['id' => <docId>, 'data' => <decoded>], ...] (same shape as query()).
     * The top-level query() can't reach subcollections (it runs against the
     * DB root), so this uses the REST list-documents GET on the parent path.
     */
    public function listSubcollection(string $collection, string $docId, string $subcollection, int $pageSize = 300): array
    {
        $base = $this->baseUrl() . "/$collection/" . rawurlencode($docId) . "/$subcollection";
        $out = [];
        $pageToken = null;
        do {
            $url = $base . '?pageSize=' . (int) $pageSize;
            if ($pageToken) $url .= '&pageToken=' . rawurlencode($pageToken);
            $r = $this->request('GET', $url);
            if ($r['code'] !== 200) {
                if ($r['code'] !== 404 && function_exists('log_message')) {
                    log_message('error', "FirestoreREST::listSubcollection $collection/$docId/$subcollection HTTP {$r['code']}: " . json_encode($r['body']));
                }
                break;
            }
            foreach (($r['body']['documents'] ?? []) as $doc) {
                if (!isset($doc['name'])) continue;
                $out[] = ['id' => $this->docIdFromName($doc['name']), 'data' => $this->decodeDocument($doc)];
            }
            $pageToken = $r['body']['nextPageToken'] ?? null;
        } while ($pageToken);
        return $out;
    }

    /**
     * Diagnostic-only — fetch a document and return the *raw* REST body
     * with the per-field protobuf type tags intact (`mapValue`,
     * `arrayValue`, `stringValue`, …). Lets debug callers tell apart an
     * empty map from an empty array, which `getDocument()` collapses to
     * the same `[]` after decoding.
     */
    public function getRawDocument(string $collection, string $docId): ?array
    {
        $safeDocId = rawurlencode($docId);
        $url = $this->baseUrl() . "/$collection/$safeDocId";
        $r = $this->request('GET', $url);
        if ($r['code'] !== 200) return null;
        return is_array($r['body']) ? $r['body'] : null;
    }

    /**
     * Phase 7A — fire N independent HTTPS requests concurrently via
     * curl_multi and return their parsed responses keyed by the caller's
     * tag. Used by the fee-submit hot path to overlap multiple Firestore
     * REST round-trips whose results are all needed before the first
     * write (student + idempotency + demands + feeStructures).
     *
     * Input shape:
     *   [
     *     'tagA' => ['method'=>'GET',  'url'=>'…', 'body'=>null],
     *     'tagB' => ['method'=>'POST', 'url'=>'…', 'body'=>[...]],
     *     ...
     *   ]
     *
     * Output shape (same keys):
     *   [ 'tagA' => ['code'=>int, 'body'=>array], ... ]
     *
     * Shares one access token across all requests (no token fetch per
     * handle), uses CURLM for true concurrency. Network time collapses
     * from sum(per-call) to max(per-call) — typically 300 ms instead of
     * 4 × 300 ms. Falls back silently to sequential if curl_multi is
     * unavailable (shouldn't happen on any supported PHP build).
     */
    public function parallelFetch(array $requests): array
    {
        if (empty($requests)) return [];

        // Sequential fallback (defensive — curl_multi is core since PHP 5).
        if (!function_exists('curl_multi_init')) {
            $out = [];
            foreach ($requests as $tag => $req) {
                $out[$tag] = $this->request(
                    (string) ($req['method'] ?? 'GET'),
                    (string) ($req['url']    ?? ''),
                    $req['body'] ?? null
                );
            }
            return $out;
        }

        $token   = $this->getAccessToken();
        $headers = [
            'Authorization: Bearer ' . $token,
            'Content-Type: application/json',
        ];

        $mh      = curl_multi_init();
        $handles = []; // tag => curl handle

        foreach ($requests as $tag => $req) {
            $method = strtoupper((string) ($req['method'] ?? 'GET'));
            $url    = (string) ($req['url'] ?? '');
            $body   = $req['body'] ?? null;
            if ($url === '') continue;

            $ch = curl_init();
            $opts = [
                CURLOPT_URL            => $url,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER     => $headers,
                CURLOPT_TIMEOUT        => 15,
                CURLOPT_TCP_KEEPALIVE  => 1,
                CURLOPT_TCP_KEEPIDLE   => 60,
            ];
            if ($method === 'GET') {
                $opts[CURLOPT_HTTPGET] = true;
            } elseif ($method === 'POST') {
                $opts[CURLOPT_POST] = true;
                if ($body !== null) $opts[CURLOPT_POSTFIELDS] = json_encode($body);
            } elseif ($method === 'PATCH' || $method === 'DELETE') {
                $opts[CURLOPT_CUSTOMREQUEST] = $method;
                if ($body !== null) $opts[CURLOPT_POSTFIELDS] = json_encode($body);
            }
            curl_setopt_array($ch, $opts);
            curl_multi_add_handle($mh, $ch);
            $handles[$tag] = $ch;
        }

        // Drive the multi-stack until every request finishes. curl_multi_exec
        // is non-blocking; curl_multi_select blocks until activity or 1s.
        $active = null;
        do {
            $status = curl_multi_exec($mh, $active);
            if ($active) curl_multi_select($mh, 1.0);
        } while ($active && $status === CURLM_OK);

        $out = [];
        foreach ($handles as $tag => $ch) {
            $resp = curl_multi_getcontent($ch);
            $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_multi_remove_handle($mh, $ch);
            curl_close($ch);
            $out[$tag] = [
                'code' => $code,
                'body' => is_string($resp) ? (json_decode($resp, true) ?? []) : [],
            ];
        }
        curl_multi_close($mh);
        return $out;
    }

    /**
     * Convenience wrapper over parallelFetch() for the common case of
     * issuing N concurrent single-document GETs. Input:
     *   [ 'tagA' => ['collection'=>'students', 'docId'=>'X_Y'], ... ]
     * Output (same keys): the decoded doc (array) or null on 404 / error.
     */
    public function getDocumentsParallel(array $requests): array
    {
        if (empty($requests)) return [];
        $prepared = [];
        foreach ($requests as $tag => $spec) {
            $coll = (string) ($spec['collection'] ?? '');
            $id   = (string) ($spec['docId'] ?? '');
            if ($coll === '' || $id === '') { $prepared[$tag] = null; continue; }
            $prepared[$tag] = [
                'method' => 'GET',
                'url'    => $this->baseUrl() . "/$coll/" . rawurlencode($id),
                'body'   => null,
            ];
        }
        $toFire = array_filter($prepared);
        $raw    = $this->parallelFetch($toFire);

        $out = [];
        foreach ($requests as $tag => $spec) {
            $r = $raw[$tag] ?? null;
            if (!is_array($r))                         { $out[$tag] = null; continue; }
            if ($r['code'] === 404)                    { $out[$tag] = null; continue; }
            if ($r['code'] < 200 || $r['code'] >= 300) { $out[$tag] = null; continue; }
            $out[$tag] = $this->decodeDocument(is_array($r['body']) ? $r['body'] : []);
        }
        return $out;
    }

    /**
     * Quote a top-level field name for use in a Firestore updateMask.fieldPaths
     * query parameter. Per the Firestore REST API:
     *   - Unquoted paths must match [a-zA-Z_][a-zA-Z_0-9]*
     *   - Otherwise the path must be wrapped in backticks, with `\` and `` ` ``
     *     inside the name escaped as `\\` and `` \` ``.
     * Sending a name like "User ID" without quoting yields HTTP 400
     * "Invalid property path".
     */
    private function encodeFieldPath(string $name): string
    {
        // B2.3.2-FIX R5 — treat dots as nested-path separators (Firestore
        // FieldPath semantics). Each segment is encoded independently:
        //   - simple identifier → unquoted
        //   - anything else      → backtick-quoted with `\` and `` ` `` escaped
        // The whole-string backtick-wrap (pre-R5) made Firestore treat
        // `lifecycle.reason` as ONE literal field name with a dot in it,
        // which silently created top-level junk fields instead of updating
        // the nested map.
        if (strpos($name, '.') === false) {
            // Single-segment fast path.
            if (preg_match('/^[a-zA-Z_][a-zA-Z_0-9]*$/', $name)) {
                return $name;
            }
            $escaped = str_replace(['\\', '`'], ['\\\\', '\\`'], $name);
            return '`' . $escaped . '`';
        }
        // Multi-segment: nested path.
        $parts = explode('.', $name);
        $encoded = [];
        foreach ($parts as $part) {
            if (preg_match('/^[a-zA-Z_][a-zA-Z_0-9]*$/', $part)) {
                $encoded[] = $part;
            } else {
                $escaped = str_replace(['\\', '`'], ['\\\\', '\\`'], $part);
                $encoded[] = '`' . $escaped . '`';
            }
        }
        return implode('.', $encoded);
    }

    private function buildUpdateMask(array $data): string
    {
        return implode('&', array_map(
            fn($m) => 'updateMask.fieldPaths=' . urlencode($this->encodeFieldPath((string) $m)),
            array_keys($data)
        ));
    }

    /**
     * Create a document only if it does not exist. Returns true on create,
     * false on 409 (already exists) or any other error.
     *
     * Used by counter / reservation flows that need create-if-not-exists
     * semantics (Firestore's built-in atomic operation) — the regular
     * setDocument() falls back to PATCH on 409 which would silently
     * overwrite a concurrent writer's reservation.
     */
    public function createDocument(string $collection, string $docId, array $data): bool
    {
        $this->_readCache = []; $this->_bustConfigCache($collection, $docId); // PERF: invalidate memo + config cache
        if ($this->_blockWrite('CREATE', "$collection/$docId")) return false;
        $fields = [];
        foreach ($data as $k => $v) $fields[$k] = $this->encode($v);
        $url = $this->baseUrl() . "/$collection?documentId=" . urlencode($docId);
        $r = $this->request('POST', $url, ['fields' => $fields]);
        if ($r['code'] >= 200 && $r['code'] < 300) return true;
        if ($r['code'] === 409) return false; // doc exists — caller must handle
        if (function_exists('log_message')) log_message('error', "FirestoreREST::create $collection/$docId HTTP {$r['code']}: " . json_encode($r['body']));
        return false;
    }

    /**
     * Commit a batch of writes atomically via Firestore's `:commit` REST
     * endpoint. Accepts a list of operations where each item is:
     *
     *   ['op' => 'set'|'update'|'delete', 'collection' => 'x', 'docId' => 'y', 'data' => [...]]
     *
     * All writes succeed together or fail together (atomic from the
     * caller's perspective — Firestore guarantees single-transaction
     * semantics inside `commit` with a single `writes` array).
     *
     * Returns true on HTTP 2xx, false otherwise. The caller is
     * responsible for falling back to single-doc writes if batch fails.
     *
     * Up to 500 operations per commit (Firestore hard limit).
     */
    public function commitBatch(array $ops): bool
    {
        $this->_readCache = []; // PERF: write invalidates the request read memo
        if (empty($ops)) return true;
        if ($this->_blockWrite('BATCH', 'commit:' . count($ops))) return false;

        $dbPrefix = "projects/{$this->projectId}/databases/{$this->databaseId}/documents";
        $writes = [];
        foreach ($ops as $op) {
            $opType = (string) ($op['op'] ?? 'set');
            $coll = (string) ($op['collection'] ?? '');
            $id   = (string) ($op['docId'] ?? '');
            if ($coll === '' || $id === '') continue;
            $path = "$dbPrefix/$coll/$id";

            if ($opType === 'delete') {
                $deleteEntry = ['delete' => $path];
                if (isset($op['precondition']) && is_array($op['precondition'])) {
                    $deleteEntry['currentDocument'] = $op['precondition'];
                }
                $writes[] = $deleteEntry;
                continue;
            }

            $data = is_array($op['data'] ?? null) ? $op['data'] : [];
            $fields = [];
            foreach ($data as $k => $v) $fields[$k] = $this->encode($v);
            $writeEntry = [
                'update' => [
                    'name'   => $path,
                    'fields' => $fields,
                ],
            ];
            // Merge behaviour: Firestore's REST commit supports an
            // `updateMask` that limits the fields touched — with it we
            // patch only the provided fields; without it we overwrite.
            if (!empty($op['merge'])) {
                $writeEntry['updateMask'] = ['fieldPaths' => array_keys($data)];
            }
            // Phase 7B / 8A — optional precondition (currentDocument):
            //   ['exists'     => false]   create-if-not-exists semantics
            //   ['exists'     => true ]   update-only (fails if absent)
            //   ['updateTime' => 'ISO']   CAS — the whole commit fails if
            //                              the doc's updateTime has moved.
            //                              Caller must have read the doc
            //                              and captured its `__updateTime`
            //                              (surfaced by decodeDocument).
            // A batch with any failing precondition rolls back atomically
            // (HTTP 400 / FAILED_PRECONDITION). Used by the Phase 8 accounting
            // CAS loop + the Phase 7B claim-batch.
            if (isset($op['precondition']) && is_array($op['precondition'])) {
                $writeEntry['currentDocument'] = $op['precondition'];
            }
            $writes[] = $writeEntry;
        }

        if (empty($writes)) return true;

        $url = "https://firestore.googleapis.com/v1/projects/{$this->projectId}/databases/{$this->databaseId}/documents:commit";
        $r = $this->request('POST', $url, ['writes' => $writes]);
        if ($r['code'] >= 200 && $r['code'] < 300) return true;
        if (function_exists('log_message')) log_message('error', "FirestoreREST::commitBatch HTTP {$r['code']} ops=" . count($writes) . " body=" . json_encode($r['body']));
        return false;
    }

    /**
     * NON-ATOMIC batch write via Firestore's `:batchWrite` REST endpoint.
     *
     * Unlike commitBatch() (`:commit`, all-or-nothing), each write here
     * succeeds or fails INDEPENDENTLY — Firestore returns one `status` entry
     * per write — which preserves per-item partial-success semantics.
     *
     * $ops: same shape as commitBatch (set only):
     *   ['collection'=>'x', 'docId'=>'y', 'data'=>[...], 'merge'=>bool]
     *
     * Returns a list<bool> aligned 1:1 with $ops (true = that write succeeded).
     * On transport/HTTP failure the whole list is false. Values are encoded
     * with the SAME encode() used by setDocument()/commitBatch(), so document
     * bytes — including stdClass → mapValue for maps like `lateTimes` — are
     * byte-identical to a single set(). Does NOT touch set()/commitBatch().
     *
     * Firestore hard limit: 500 writes per request (chunk above this layer).
     */
    public function batchWrite(array $ops): array
    {
        $this->_readCache = []; // PERF: write invalidates the request read memo
        $n = count($ops);
        if ($n === 0) return [];
        if ($this->_blockWrite('BATCHWRITE', 'batchWrite:' . $n)) return array_fill(0, $n, false);

        $dbPrefix = "projects/{$this->projectId}/databases/{$this->databaseId}/documents";
        $writes = [];
        foreach ($ops as $op) {
            $coll = (string) ($op['collection'] ?? '');
            $id   = (string) ($op['docId'] ?? '');
            $data = is_array($op['data'] ?? null) ? $op['data'] : [];
            $path = "$dbPrefix/$coll/$id";
            $fields = [];
            foreach ($data as $k => $v) $fields[$k] = $this->encode($v);  // same encoder → identical bytes
            $entry = ['update' => ['name' => $path, 'fields' => $fields]];
            if (!empty($op['merge'])) {
                $entry['updateMask'] = ['fieldPaths' => array_keys($data)];
            }
            $writes[] = $entry;
        }

        $url = "https://firestore.googleapis.com/v1/{$dbPrefix}:batchWrite";
        $r = $this->request('POST', $url, ['writes' => $writes]);

        if ($r['code'] < 200 || $r['code'] >= 300) {
            if (function_exists('log_message')) {
                log_message('error', "FirestoreREST::batchWrite HTTP {$r['code']} ops={$n} body=" . json_encode($r['body']));
            }
            return array_fill(0, $n, false);   // transport failure → all failed (caller surfaces skipped[])
        }

        // Per-write `status[]`: an empty {} (or code 0 / absent) means OK.
        $statuses = is_array($r['body']['status'] ?? null) ? $r['body']['status'] : [];
        $results = [];
        for ($i = 0; $i < $n; $i++) {
            $st   = $statuses[$i] ?? [];
            $code = (is_array($st) && isset($st['code'])) ? (int) $st['code'] : 0;
            $results[] = ($code === 0);
        }
        return $results;
    }

    /**
     * Atomically increment numeric fields on a single document, creating the
     * doc if it does not exist. Uses Firestore server-side `increment` field
     * transforms (no read-modify-write → concurrency-safe). $increments maps
     * a (pre-escaped) field path to a delta: int → integerValue, float →
     * doubleValue. Map-key paths must be backtick-escaped by the caller,
     * e.g. "monthly.`2026-06`".
     */
    public function incrementDoc(string $collection, string $docId, array $increments): bool
    {
        $this->_readCache = []; $this->_bustConfigCache($collection, $docId); // PERF: invalidate memo + config cache
        if (empty($increments)) return true;
        if ($this->_blockWrite('INCREMENT', "$collection/$docId")) return false;

        $dbPrefix = "projects/{$this->projectId}/databases/{$this->databaseId}/documents";
        $path = "$dbPrefix/$collection/$docId";

        $transforms = [];
        foreach ($increments as $fieldPath => $delta) {
            $val = is_int($delta)
                ? ['integerValue' => (string) $delta]
                : ['doubleValue' => (float) $delta];
            $transforms[] = ['fieldPath' => (string) $fieldPath, 'increment' => $val];
        }

        // update with an empty field-set + empty mask touches no existing
        // fields (so it never wipes the doc) but upserts it; updateTransforms
        // then apply atomically, creating missing fields from 0.
        $writes = [[
            'update'           => ['name' => $path, 'fields' => new \stdClass()],
            'updateMask'       => ['fieldPaths' => []],
            'updateTransforms' => $transforms,
        ]];

        $url = "https://firestore.googleapis.com/v1/{$dbPrefix}:commit";
        $r = $this->request('POST', $url, ['writes' => $writes]);
        if ($r['code'] >= 200 && $r['code'] < 300) return true;
        if (function_exists('log_message')) {
            log_message('error', "FirestoreREST::incrementDoc HTTP {$r['code']} {$collection}/{$docId} body=" . json_encode($r['body']));
        }
        return false;
    }

    public function setDocument(string $collection, string $docId, array $data, bool $merge = false): bool
    {
        $this->_readCache = []; $this->_bustConfigCache($collection, $docId); // PERF: invalidate memo + config cache
        if ($this->_blockWrite('SET', "$collection/$docId")) return false;
        $safeDocId = rawurlencode($docId);
        $fields = [];
        foreach ($data as $k => $v) $fields[$k] = $this->encode($v);

        if ($merge) {
            $maskParams = $this->buildUpdateMask($data);
            $url = $this->baseUrl() . "/$collection/$safeDocId?$maskParams";
            $r = $this->request('PATCH', $url, ['fields' => $fields]);
        } else {
            $url = $this->baseUrl() . "/$collection?documentId=" . urlencode($docId);
            $r = $this->request('POST', $url, ['fields' => $fields]);
            if ($r['code'] === 409) {
                $url = $this->baseUrl() . "/$collection/$safeDocId";
                $r = $this->request('PATCH', $url, ['fields' => $fields]);
            }
        }
        if ($r['code'] >= 200 && $r['code'] < 300) return true;
        if (function_exists('log_message')) log_message('error', "FirestoreREST::set $collection/$docId HTTP {$r['code']}: " . json_encode($r['body']));
        return false;
    }

    /**
     * Merge-write a document while ALSO deleting a list of legacy fields
     * in the same PATCH request. Used by data-shape migrations that need
     * to atomically replace `Status` (capital) with `status` (camelCase),
     * etc.
     *
     * Implementation: Firestore PATCH supports `updateMask.fieldPaths`
     * pointing at fields that are NOT present in the request body — those
     * fields get deleted on the server. We build a single mask covering
     * both `setData` keys (which are written) and `deleteFields` keys
     * (which are not in the body, so they get cleared).
     *
     * Both arrays may be empty; if both are empty this is a no-op.
     */
    public function setDocumentWithDeletes(
        string $collection,
        string $docId,
        array  $setData,
        array  $deleteFields
    ): bool {
        if (empty($setData) && empty($deleteFields)) return true;
        $this->_readCache = []; $this->_bustConfigCache($collection, $docId); // PERF: invalidate memo + config cache
        if ($this->_blockWrite('SET+DEL', "$collection/$docId")) return false;

        $safeDocId = rawurlencode($docId);
        $fields = [];
        foreach ($setData as $k => $v) $fields[$k] = $this->encode($v);

        // Mask covers BOTH sets AND deletes — fields in the mask but missing
        // from the body get cleared on the server.
        $maskFields = array_unique(array_merge(array_keys($setData), $deleteFields));
        $maskParams = implode('&', array_map(
            fn($m) => 'updateMask.fieldPaths=' . urlencode($this->encodeFieldPath((string) $m)),
            $maskFields
        ));

        $url = $this->baseUrl() . "/$collection/$safeDocId?$maskParams";
        $r = $this->request('PATCH', $url, ['fields' => $fields]);
        if ($r['code'] >= 200 && $r['code'] < 300) return true;
        if (function_exists('log_message')) {
            log_message('error', "FirestoreREST::setWithDeletes $collection/$docId HTTP {$r['code']}: " . json_encode($r['body']));
        }
        return false;
    }

    public function updateDocument(string $collection, string $docId, array $data): bool
    {
        $this->_readCache = []; $this->_bustConfigCache($collection, $docId); // PERF: invalidate memo + config cache
        if ($this->_blockWrite('UPDATE', "$collection/$docId")) return false;
        $safeDocId = rawurlencode($docId);

        // B2.3.2-FIX R5 — nested-update support. Firestore REST PATCH expects
        // the BODY shaped as nested maps and the updateMask field paths
        // dotted (e.g. mask 'lifecycle.state' targets the nested state field
        // inside the lifecycle map). The legacy code path keyed the body by
        // the dotted form too, which Firestore stored as LITERAL top-level
        // field names (e.g. a field called 'lifecycle.state' with a dot in
        // its name) while leaving the nested 'lifecycle' map untouched.
        // The mask paths stay dotted; the body is now reshaped from flat
        // dotted-key input to a nested map structure. Non-dotted keys are
        // unchanged. Backward-compatible with existing non-dotted callers.
        $bodyFields = [];
        foreach ($data as $k => $v) {
            $key = (string) $k;
            if (strpos($key, '.') === false) {
                $bodyFields[$key] = $v;
                continue;
            }
            $parts = explode('.', $key);
            $ref = &$bodyFields;
            foreach ($parts as $i => $part) {
                if ($i === count($parts) - 1) {
                    $ref[$part] = $v;
                } else {
                    if (!isset($ref[$part]) || !is_array($ref[$part])) {
                        $ref[$part] = [];
                    }
                    $ref = &$ref[$part];
                }
            }
            unset($ref);
        }

        $fields = [];
        foreach ($bodyFields as $k => $v) $fields[$k] = $this->encode($v);
        // Mask continues to use the ORIGINAL dotted keys — Firestore PATCH
        // applies the update only to the masked nested paths, leaving sibling
        // fields in the nested map intact.
        $maskParams = $this->buildUpdateMask($data);
        $url = $this->baseUrl() . "/$collection/$safeDocId?$maskParams";
        $r = $this->request('PATCH', $url, ['fields' => $fields]);
        if ($r['code'] >= 200 && $r['code'] < 300) return true;
        if (function_exists('log_message')) log_message('error', "FirestoreREST::update $collection/$docId HTTP {$r['code']}: " . json_encode($r['body']));
        return false;
    }

    public function deleteDocument(string $collection, string $docId): bool
    {
        $this->_readCache = []; $this->_bustConfigCache($collection, $docId); // PERF: invalidate memo + config cache
        if ($this->_blockWrite('DELETE', "$collection/$docId")) return false;
        $safeDocId = rawurlencode($docId);
        $url = $this->baseUrl() . "/$collection/$safeDocId";
        $r = $this->request('DELETE', $url);
        if ($r['code'] >= 200 && $r['code'] < 300) return true;
        if (function_exists('log_message')) log_message('error', "FirestoreREST::delete $collection/$docId HTTP {$r['code']}: " . json_encode($r['body']));
        return false;
    }

    /**
     * Phase 3 cursor-paginated query. Same semantics as query() but
     * accepts a `startAfter` scalar (the orderBy-field value of the
     * last doc returned by the previous page). Returns rows AFTER
     * that value, in the same order. Pass '' / null on the first call.
     *
     * Caller must track the last row's orderBy field value themselves
     * and pass it back on the next call. Stable ordering requires the
     * orderBy field to be unique-ish (studentId, createdAt, etc.).
     */
    public function queryPaginated(
        string $collection,
        array $conditions,
        string $orderBy,
        string $direction,
        int $limit,
        $startAfter = null
    ): array {
        return $this->query($collection, $conditions, $orderBy, $direction, $limit, $startAfter);
    }

    public function query(
        string $collection,
        array $conditions = [],
        ?string $orderBy = null,
        string $direction = 'ASC',
        ?int $limit = null,
        $startAfter = null
    ): array {
        $opMap = ['=' => 'EQUAL', '==' => 'EQUAL', '<' => 'LESS_THAN', '<=' => 'LESS_THAN_OR_EQUAL',
                  '>' => 'GREATER_THAN', '>=' => 'GREATER_THAN_OR_EQUAL', '!=' => 'NOT_EQUAL',
                  'in' => 'IN', 'not-in' => 'NOT_IN', 'array-contains' => 'ARRAY_CONTAINS',
                  'array-contains-any' => 'ARRAY_CONTAINS_ANY'];

        $structuredQuery = [
            'from' => [['collectionId' => $collection]],
        ];

        if (!empty($conditions)) {
            $filters = [];
            foreach ($conditions as [$field, $op, $value]) {
                $firestoreOp = $opMap[$op] ?? 'EQUAL';
                $filters[] = [
                    'fieldFilter' => [
                        'field'  => ['fieldPath' => $field],
                        'op'     => $firestoreOp,
                        'value'  => $this->encode($value),
                    ]
                ];
            }
            if (count($filters) === 1) {
                $structuredQuery['where'] = $filters[0];
            } else {
                $structuredQuery['where'] = [
                    'compositeFilter' => ['op' => 'AND', 'filters' => $filters]
                ];
            }
        }

        if ($orderBy !== null) {
            $structuredQuery['orderBy'] = [[
                'field'     => ['fieldPath' => $orderBy],
                'direction' => strtoupper($direction) === 'DESC' ? 'DESCENDING' : 'ASCENDING',
            ]];
        }

        // Phase 3 — cursor pagination. `startAfter` is the value of the
        // orderBy field from the LAST row of the previous page. Firestore
        // will skip every doc whose orderBy value is <= (ASC) or >= (DESC)
        // this scalar and return the next page. `before=false` = startAfter
        // semantics (exclusive), `before=true` = startAt (inclusive).
        if ($startAfter !== null && $startAfter !== '' && $orderBy !== null) {
            $structuredQuery['startAt'] = [
                'values' => [$this->encode($startAfter)],
                'before' => false, // exclusive — skip the cursor row itself
            ];
        }

        if ($limit !== null) {
            $structuredQuery['limit'] = $limit;
        }

        $url = $this->baseUrl() . ':runQuery';
        // Reset per call: a caller asks about the query it just ran.
        $this->lastQueryFailed = false;
        $r = $this->request('POST', $url, ['structuredQuery' => $structuredQuery]);

        // ── The orderBy fallback (B1) ────────────────────────────────────────
        //
        // When the ordered query fails — almost always a missing composite index —
        // this retries WITHOUT the sort and re-sorts in PHP further down. That is
        // a genuine safety net, and for a result set that fits inside $limit it
        // produces exactly the right answer: every matching document came back,
        // and sorting them here is equivalent to sorting them there.
        //
        // It is CORRECT ONLY WHEN NOTHING WAS TRUNCATED. If the unordered query
        // returned a full page, the server applied $limit to an ARBITRARY subset
        // (Firestore falls back to __name__ order), and the PHP sort then arranges
        // that arbitrary subset beautifully. The caller receives a plausible,
        // confidently-ordered, WRONG page — and until now not one line was logged,
        // because the only log statement fires when the RETRY fails.
        //
        // Consequences seen in this codebase: supportNotes had no declared index
        // at all and rendered fine for months on this path; the queue could show
        // 25 tickets sorted newest-first that were an arbitrary sample with the
        // morning's tickets simply absent; and it produced a live query result
        // that contradicted the panel, which I initially mis-attributed to my own
        // query construction rather than to this fallback.
        //
        // So: keep the net, but only where it tells the truth. Below, a fallback
        // page that hit the limit is treated as a failure rather than served.
        $usedFallback = false;
        if ($r['code'] !== 200 && $orderBy !== null) {
            $firstError = $this->_indexHint($r);
            unset($structuredQuery['orderBy']);
            $r = $this->request('POST', $url, ['structuredQuery' => $structuredQuery]);
            $usedFallback = ($r['code'] === 200);
            if ($usedFallback && function_exists('log_message')) {
                log_message('error',
                    "FirestoreREST::query {$collection} — ORDERED QUERY FAILED, served from the "
                    . "unordered fallback and sorted in PHP. This is a MISSING INDEX. "
                    . "orderBy={$orderBy} {$direction}" . ($firstError ? " :: {$firstError}" : ''));
            }
        }

        if ($r['code'] !== 200) {
            // ADDITIVE ONLY — this records what already happened. The return value,
            // the orderBy-drop retry above, and every existing caller's behaviour
            // are unchanged. It exists because query() returns [] on failure, so a
            // failed read and an empty collection are indistinguishable at the call
            // site — and several endpoints were reporting "no results" for a query
            // that never ran. Callers that care can now ask; callers that do not
            // are unaffected.
            $this->lastQueryFailed = true;
            if (function_exists('log_message')) log_message('error', "FirestoreREST::query $collection HTTP {$r['code']}: " . json_encode($r['body']));
            return [];
        }

        $results = [];
        $docs = $r['body'];
        if (!is_array($docs)) return [];
        foreach ($docs as $item) {
            if (isset($item['document'])) {
                $docName = $item['document']['name'];
                $docId   = $this->docIdFromName($docName);
                $data    = $this->decodeDocument($item['document']);
                $results[] = ['id' => $docId, 'data' => $data];
            }
        }
        // A fallback page that hit the limit is an arbitrary subset, not a page.
        // Serving it would be the silent-wrong-data case above; refuse instead,
        // and let the caller's own fail-closed handling report it.
        if ($usedFallback && $limit !== null && count($results) >= $limit) {
            $this->lastQueryFailed = true;
            if (function_exists('log_message')) {
                log_message('error',
                    "FirestoreREST::query {$collection} — fallback page hit the limit ({$limit}); "
                    . "the result would be an ARBITRARY subset in a convincing order. Refusing it. "
                    . "Declare the composite index for orderBy={$orderBy}.");
            }
            return [];
        }

        // Client-side sort if orderBy was requested but couldn't be done server-side
        if ($orderBy !== null && !empty($results)) {
            usort($results, function ($a, $b) use ($orderBy, $direction) {
                $va = $a['data'][$orderBy] ?? '';
                $vb = $b['data'][$orderBy] ?? '';
                $cmp = $va <=> $vb;
                return strtoupper($direction) === 'DESC' ? -$cmp : $cmp;
            });
        }

        if ($limit !== null && count($results) > $limit) {
            $results = array_slice($results, 0, $limit);
        }

        return $results;
    }

    /**
     * Server-side aggregation via Firestore runAggregationQuery.
     *
     * Returns the aggregated scalar values WITHOUT fetching any documents —
     * Firestore computes counts/sums on the server side. Orders of magnitude
     * faster than fetch-all-then-count for large collections.
     *
     * $aggregations is a list of ['op' => 'count'|'sum'|'avg', 'field' => '…', 'alias' => '…'].
     * For 'count', 'field' is ignored. Returns a map keyed by alias.
     *
     * Example:
     *   $r = $fs->runAggregation('students', [['schoolId','==','SCH_X']], [
     *       ['op' => 'count', 'alias' => 'n'],
     *   ]);
     *   $count = (int) ($r['n'] ?? 0);
     */
    public function runAggregation(string $collection, array $conditions = [], array $aggregations = []): array
    {
        if (empty($aggregations)) {
            $aggregations = [['op' => 'count', 'alias' => 'n']];
        }

        $opMap = ['=' => 'EQUAL', '==' => 'EQUAL', '<' => 'LESS_THAN', '<=' => 'LESS_THAN_OR_EQUAL',
                  '>' => 'GREATER_THAN', '>=' => 'GREATER_THAN_OR_EQUAL', '!=' => 'NOT_EQUAL',
                  'in' => 'IN', 'not-in' => 'NOT_IN', 'array-contains' => 'ARRAY_CONTAINS',
                  'array-contains-any' => 'ARRAY_CONTAINS_ANY'];

        $structuredQuery = ['from' => [['collectionId' => $collection]]];

        if (!empty($conditions)) {
            $filters = [];
            foreach ($conditions as [$field, $op, $value]) {
                $firestoreOp = $opMap[$op] ?? 'EQUAL';
                $filters[] = [
                    'fieldFilter' => [
                        'field' => ['fieldPath' => $field],
                        'op'    => $firestoreOp,
                        'value' => $this->encode($value),
                    ]
                ];
            }
            $structuredQuery['where'] = count($filters) === 1
                ? $filters[0]
                : ['compositeFilter' => ['op' => 'AND', 'filters' => $filters]];
        }

        $aggs = [];
        foreach ($aggregations as $a) {
            $op    = strtolower((string) ($a['op'] ?? 'count'));
            $alias = (string) ($a['alias'] ?? $op);
            $field = (string) ($a['field'] ?? '');
            $entry = ['alias' => $alias];
            if ($op === 'count') {
                $entry['count'] = new \stdClass();
            } elseif ($op === 'sum' && $field !== '') {
                $entry['sum'] = ['field' => ['fieldPath' => $field]];
            } elseif ($op === 'avg' && $field !== '') {
                $entry['avg'] = ['field' => ['fieldPath' => $field]];
            } else {
                continue;
            }
            $aggs[] = $entry;
        }
        if (empty($aggs)) return [];

        $url = $this->baseUrl() . ':runAggregationQuery';
        $body = ['structuredAggregationQuery' => [
            'structuredQuery' => $structuredQuery,
            'aggregations'    => $aggs,
        ]];
        $r = $this->request('POST', $url, $body);
        if ($r['code'] !== 200) {
            if (function_exists('log_message')) {
                log_message('error', "FirestoreREST::runAggregation $collection HTTP {$r['code']}: " . json_encode($r['body']));
            }
            return [];
        }

        $out = [];
        foreach ((array) $r['body'] as $row) {
            $fields = $row['result']['aggregateFields'] ?? [];
            foreach ($fields as $alias => $val) {
                $out[$alias] = $this->decode($val);
            }
        }
        return $out;
    }
}
