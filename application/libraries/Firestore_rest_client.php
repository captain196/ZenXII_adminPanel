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

    /** @var bool When true, all WRITE operations return false without hitting Firestore */
    private bool $simulateFailure = false;

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
        if (preg_match('/^[a-zA-Z_][a-zA-Z_0-9]*$/', $name)) {
            return $name;
        }
        $escaped = str_replace(['\\', '`'], ['\\\\', '\\`'], $name);
        return '`' . $escaped . '`';
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

    public function setDocument(string $collection, string $docId, array $data, bool $merge = false): bool
    {
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
        if ($this->_blockWrite('UPDATE', "$collection/$docId")) return false;
        $safeDocId = rawurlencode($docId);
        $fields = [];
        foreach ($data as $k => $v) $fields[$k] = $this->encode($v);
        $maskParams = $this->buildUpdateMask($data);
        $url = $this->baseUrl() . "/$collection/$safeDocId?$maskParams";
        $r = $this->request('PATCH', $url, ['fields' => $fields]);
        if ($r['code'] >= 200 && $r['code'] < 300) return true;
        if (function_exists('log_message')) log_message('error', "FirestoreREST::update $collection/$docId HTTP {$r['code']}: " . json_encode($r['body']));
        return false;
    }

    public function deleteDocument(string $collection, string $docId): bool
    {
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
        $r = $this->request('POST', $url, ['structuredQuery' => $structuredQuery]);

        // If query fails with index error and we have orderBy, retry without orderBy (client-side sort)
        if ($r['code'] !== 200 && $orderBy !== null) {
            unset($structuredQuery['orderBy']);
            $r = $this->request('POST', $url, ['structuredQuery' => $structuredQuery]);
        }

        if ($r['code'] !== 200) {
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
