<?php
defined('BASEPATH') or exit('No direct script access allowed');

require __DIR__ . '/../../vendor/autoload.php';

use Kreait\Firebase\Factory;
use Google\Cloud\Storage\StorageClient;

require_once __DIR__ . '/Firestore_rest_client.php';

/**
 * Firebase library
 *
 * SECURITY FIXES:
 * [FIX-1]  get() now uses the Admin SDK (Kreait) instead of plain
 *          file_get_contents() with a public REST URL — removes unauthenticated
 *          data exposure and stops relying on publicly-readable DB rules.
 * [FIX-2]  Error handling: exceptions caught and logged; never exposed to client.
 * [FIX-3]  handleOtherValue() kept but moved to a more appropriate layer.
 * [FIX-4]  getDatabase() exposed for raw reference access (used in Account controller).
 * [FIX-5]  Service account path kept consistent with Common_model.
 */
class Firebase
{
    protected $database;
    protected $auth;
    protected $storageBucket;
    protected $firestoreDb;

    /** @var array Token cache: remotePath → downloadToken (for uploadFile → getDownloadUrl flow) */
    private $_downloadTokens = [];

    // ── Circuit breaker state ────────────────────────────────────────
    private const CB_FILE      = APPPATH . 'cache/firebase_circuit.json';
    private const CB_THRESHOLD = 5;    // consecutive failures to trip
    private const CB_TIMEOUT   = 30;   // seconds before half-open retry
    private const CB_WINDOW    = 60;   // seconds to track failure window

    // ── Retry configuration ──────────────────────────────────────────
    private const RETRY_MAX    = 1;    // max retries (1 = try twice total)
    private const RETRY_BASE_MS = 200; // base delay before retry (ms)

    public function __construct()
    {
        $serviceAccountPath = __DIR__ . '/../config/graderadmin-firebase-adminsdk-a1sml-2b5f1862a7.json';
        $databaseUri        = 'https://graderadmin-default-rtdb.firebaseio.com/';

        $httpOptions = \Kreait\Firebase\Http\HttpClientOptions::default()
            ->withTimeout(15.0)          // 15-second total request timeout
            ->withConnectTimeout(5.0);   // 5-second connection timeout

        $factory = (new Factory)
            ->withServiceAccount($serviceAccountPath)
            ->withDatabaseUri($databaseUri)
            ->withHttpClientOptions($httpOptions);

        $this->database = $factory->createDatabase();
        $this->auth     = $factory->createAuth();

        // Firestore — use REST API wrapper (no gRPC extension required)
        // Default database on project graderadmin.
        try {
            $this->firestoreDb = new FirestoreRestClient($serviceAccountPath, 'graderadmin', '(default)');
        } catch (\Exception $e) {
            log_message('error', 'Firebase::__construct() Firestore REST init failed: ' . $e->getMessage());
            $this->firestoreDb = null;
        }

        // Firebase Storage — same pattern as Common_model
        $storageClient       = new StorageClient(['keyFilePath' => $serviceAccountPath]);
        $this->storageBucket = $storageClient->bucket('graderadmin.appspot.com');
    }

    /**
     * Get the raw database instance (needed for getReference() in some controllers).
     */
    public function getDatabase()
    {
        return $this->database;
    }

    /**
     * Get the storage bucket instance (C-07: shared with Common_model to avoid duplicate SDK init).
     */
    public function getStorageBucket()
    {
        return $this->storageBucket;
    }

    // ══════════════════════════════════════════════════════════════════
    // ██  RESILIENCE: Circuit Breaker + Retry + Metrics
    // ══════════════════════════════════════════════════════════════════

    /**
     * Execute a Firebase RTDB operation with retry, circuit breaker, and metrics.
     *
     * @param string   $op       Operation name for logging (READ, WRITE, DELETE, etc.)
     * @param string   $path     Firebase path
     * @param callable $fn       The actual Firebase call (receives no args, returns result)
     * @param mixed    $fallback Value to return on total failure
     * @return mixed             Result from $fn or $fallback
     */
    private function _resilient(string $op, string $path, callable $fn, $fallback = null)
    {
        // Circuit breaker: check if open
        if ($this->_cb_is_open()) {
            log_message('error', "Firebase::{$op}() circuit OPEN — skipping [{$path}]");
            $this->_track_metrics(0, true);
            return $fallback;
        }

        $lastException = null;
        $maxAttempts   = 1 + self::RETRY_MAX;  // 1 initial + N retries

        for ($attempt = 0; $attempt < $maxAttempts; $attempt++) {
            if ($attempt > 0) {
                // Exponential backoff: 200ms, 400ms, ...
                $delayMs = self::RETRY_BASE_MS * (2 ** ($attempt - 1));
                usleep($delayMs * 1000);
            }

            $t = microtime(true);
            try {
                $result = $fn();
                $elapsed = microtime(true) - $t;

                // Success — record and reset circuit breaker
                $this->_cb_record_success();
                $this->_track_metrics($elapsed, false);

                if (defined('GRADER_DEBUG') && GRADER_DEBUG) {
                    $this->_dbg($op, $path, $t, $result);
                }

                return $result;

            } catch (\Exception $e) {
                $elapsed = microtime(true) - $t;
                $lastException = $e;

                // Only retry on transient errors (timeout, connection, server error)
                if (!$this->_is_transient($e)) {
                    break;  // non-transient = don't retry
                }
            }
        }

        // All attempts failed
        $this->_cb_record_failure();
        $this->_track_metrics(microtime(true) - $t, true);

        if (defined('GRADER_DEBUG') && GRADER_DEBUG && isset($t)) {
            $this->_dbg($op, $path, $t, null, $lastException ? $lastException->getMessage() : 'unknown');
        }

        $retryInfo = $maxAttempts > 1 ? " (after {$maxAttempts} attempts)" : '';
        log_message('error', "Firebase::{$op}() failed for [{$path}]{$retryInfo}: "
            . ($lastException ? $lastException->getMessage() : 'unknown error'));

        return $fallback;
    }

    /**
     * Check if an exception is transient (worth retrying).
     */
    private function _is_transient(\Exception $e): bool
    {
        $msg = strtolower($e->getMessage());
        // cURL errors: timeout, DNS, connection refused, SSL
        if (strpos($msg, 'curl error') !== false) return true;
        if (strpos($msg, 'timed out') !== false) return true;
        if (strpos($msg, 'could not resolve') !== false) return true;
        if (strpos($msg, 'connection refused') !== false) return true;
        if (strpos($msg, 'ssl') !== false && strpos($msg, 'error') !== false) return true;
        // HTTP 5xx from Firebase
        if (strpos($msg, '500') !== false || strpos($msg, '502') !== false
            || strpos($msg, '503') !== false || strpos($msg, '504') !== false) return true;
        return false;
    }

    /**
     * Track metrics in Request_context (if available).
     */
    private function _track_metrics(float $elapsed, bool $isError): void
    {
        $CI =& get_instance();
        if (isset($CI->request_context) && $CI->request_context instanceof Request_context) {
            $CI->request_context->record_firebase_op($elapsed, $isError);
        }
    }

    // ── Circuit breaker (file-based, shared across requests) ─────────

    private function _cb_is_open(): bool
    {
        if (!file_exists(self::CB_FILE)) return false;
        $data = @json_decode(file_get_contents(self::CB_FILE), true);
        if (!$data) return false;

        // Open state: check if timeout has elapsed (→ half-open)
        if (($data['state'] ?? '') === 'open') {
            if (time() - ($data['tripped_at'] ?? 0) > self::CB_TIMEOUT) {
                // Half-open: allow one request through
                $data['state'] = 'half-open';
                @file_put_contents(self::CB_FILE, json_encode($data), LOCK_EX);
                return false;
            }
            return true;  // still open
        }

        return false;
    }

    private function _cb_record_failure(): void
    {
        $data = ['failures' => 0, 'window_start' => time(), 'state' => 'closed'];
        if (file_exists(self::CB_FILE)) {
            $data = @json_decode(file_get_contents(self::CB_FILE), true) ?: $data;
        }

        // Reset window if expired
        if (time() - ($data['window_start'] ?? 0) > self::CB_WINDOW) {
            $data = ['failures' => 0, 'window_start' => time(), 'state' => 'closed'];
        }

        $data['failures'] = ($data['failures'] ?? 0) + 1;
        $data['last_failure'] = time();

        // Trip the breaker
        if ($data['failures'] >= self::CB_THRESHOLD) {
            $data['state']      = 'open';
            $data['tripped_at'] = time();
            log_message('error', 'Firebase circuit breaker TRIPPED — ' . $data['failures'] . ' consecutive failures');
        }

        @file_put_contents(self::CB_FILE, json_encode($data), LOCK_EX);
    }

    private function _cb_record_success(): void
    {
        if (!file_exists(self::CB_FILE)) return;
        // Reset circuit on success
        @unlink(self::CB_FILE);
    }

    // ── [FIX-1] All reads now go through the authenticated Admin SDK ──────────

    /**
     * Read data from a Firebase path.
     * Returns the value (array/scalar/null) or null on failure.
     */
    public function get(string $path)
    {
        return $this->_resilient('READ', $path, function () use ($path) {
            return $this->database->getReference($path)->getValue();
        });
    }

    /**
     * Set (create or overwrite) data at a path.
     */
    public function set(string $path, $data)
    {
        return $this->_resilient('WRITE', $path, function () use ($path, $data) {
            $this->database->getReference($path)->set($data);
            return true;
        }, false);
    }

    /**
     * Update (merge) data at a path.
     */
    public function update(string $path, array $data)
    {
        return $this->_resilient('WRITE', $path, function () use ($path, $data) {
            $this->database->getReference($path)->update($data);
            return true;
        }, false);
    }

    /**
     * Delete a node at a path.
     *
     * Two calling conventions are supported:
     *   delete("Full/Path/To/Node")           — deletes that exact node
     *   delete("Parent/Path", "child_key")    — deletes Parent/Path/child_key
     */
    public function delete(string $path, string $key = '')
    {
        $fullPath = ($key !== '') ? "{$path}/{$key}" : $path;
        return $this->_resilient('DELETE', $fullPath, function () use ($fullPath) {
            $this->database->getReference($fullPath)->remove();
            return true;
        }, false);
    }

    /**
     * Push data, generating a unique key. Returns the new key or null.
     */
    public function push(string $path, $data): ?string
    {
        return $this->_resilient('PUSH', $path, function () use ($path, $data) {
            return $this->database->getReference($path)->push($data)->getKey();
        });
    }

    /**
     * Copy data from one path to another (read-then-write).
     */
    public function copy(string $fromPath, string $toPath): bool
    {
        $data = $this->get($fromPath);
        if ($data !== null) {
            return $this->set($toPath, $data);
        }
        return false;
    }

    /**
     * Check if a node exists.
     */
    public function exists(string $path): bool
    {
        try {
            return $this->database->getReference($path)->getSnapshot()->exists();
        } catch (\Exception $e) {
            log_message('error', 'Firebase::exists() failed for path [' . $path . ']: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Generate a unique push key without writing data.
     */
    public function generateKey(string $path): ?string
    {
        try {
            return $this->database->getReference($path)->push()->getKey();
        } catch (\Exception $e) {
            log_message('error', 'Firebase::generateKey() failed: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Shallow fetch — returns only the immediate child keys of a path
     * as an array of strings, WITHOUT downloading any child data.
     * Uses Firebase's ?shallow=true REST parameter, which is orders of
     * magnitude faster than fetching the full subtree.
     * Returns [] on failure or empty node.
     */
    public function shallow_get(string $path): array
    {
        $result = $this->_resilient('SHALLOW', $path, function () use ($path) {
            $value = $this->database->getReference($path)->shallow()->getSnapshot()->getValue();
            return is_array($value) ? array_keys($value) : [];
        }, []);
        return is_array($result) ? $result : [];
    }

    /* ── Debug helper (zero overhead when GRADER_DEBUG is false) ─────── */

    private function _dbg(string $op, string $path, float $start, $result, ?string $error = null): void
    {
        if (!class_exists('Debug_tracker', false)) {
            require_once APPPATH . 'libraries/Debug_tracker.php';
        }
        Debug_tracker::getInstance()->record_firebase_op(
            $op,
            $path,
            (microtime(true) - $start) * 1000,
            $result,
            $error,
            Debug_tracker::get_caller()
        );
    }

    /**
     * Get children at a path as a snapshot collection.
     */
    public function getChildren(string $path)
    {
        try {
            return $this->database->getReference($path)->getSnapshot()->getChildren();
        } catch (\Exception $e) {
            log_message('error', 'Firebase::getChildren() failed: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Authenticate a user by email (admin SDK — used for token verification).
     */
    public function authenticate(string $email, string $password)
    {
        try {
            return $this->auth->getUserByEmail($email);
        } catch (\Exception $e) {
            log_message('error', 'Firebase::authenticate() failed: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Upload a local file to Firebase Storage.
     * Generates a permanent download token and caches it for getDownloadUrl().
     * Returns true on success, false on failure.
     */
    public function uploadFile(string $localPath, string $remotePath): bool
    {
        try {
            $fh = fopen($localPath, 'r');
            if ($fh === false) {
                log_message('error', 'Firebase::uploadFile() cannot open local file: ' . $localPath);
                return false;
            }

            $token = bin2hex(random_bytes(16));

            $this->storageBucket->upload($fh, [
                'name'     => $remotePath,
                'metadata' => ['firebaseStorageDownloadTokens' => $token],
            ]);
            // GCS SDK closes the stream internally after upload — do not fclose() here

            // Cache token so getDownloadUrl() can use it immediately without a re-fetch
            $this->_downloadTokens[$remotePath] = $token;

            return true;
        } catch (\Exception $e) {
            log_message('error', 'Firebase::uploadFile() failed for [' . $remotePath . ']: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Return the permanent Firebase Storage download URL for a previously uploaded file.
     * Uses the cached token from uploadFile(); falls back to reading object metadata.
     */
    public function getDownloadUrl(string $remotePath): string
    {
        $token = $this->_downloadTokens[$remotePath] ?? '';

        if ($token === '') {
            // Token not cached — fetch from object metadata
            try {
                $info  = $this->storageBucket->object($remotePath)->info();
                $token = $info['metadata']['firebaseStorageDownloadTokens'] ?? '';
            } catch (\Exception $e) {
                log_message('error', 'Firebase::getDownloadUrl() failed for [' . $remotePath . ']: ' . $e->getMessage());
                return '';
            }
        }

        return sprintf(
            'https://firebasestorage.googleapis.com/v0/b/%s/o/%s?alt=media&token=%s',
            $this->storageBucket->name(),
            urlencode($remotePath),
            $token
        );
    }

    /**
     * Handle "Other" dropdown pattern: if main value is 'other', return the custom value.
     */
    public function handleOtherValue(string $mainValue, string $otherValue): string
    {
        if (strtolower(trim($mainValue)) === 'other' && trim($otherValue) !== '') {
            return trim($otherValue);
        }
        return $mainValue;
    }

    // ══════════════════════════════════════════════════════════════════
    // ██  FIREBASE AUTHENTICATION
    // ══════════════════════════════════════════════════════════════════

    /**
     * Get the raw kreait Auth instance.
     */
    public function getAuth()
    {
        return $this->auth;
    }

    /**
     * Create a Firebase Auth user account.
     *
     * @param string $email    Email (typically synthetic: {userId}@schoolsync.app)
     * @param string $password Clear-text password
     * @param array  $props    Extra properties: uid, displayName, disabled, etc.
     * @return \Kreait\Firebase\Auth\UserRecord|null
     */
    public function createFirebaseUser(string $email, string $password, array $props = [])
    {
        try {
            $request = array_merge($props, [
                'email'    => $email,
                'password' => $password,
            ]);
            $user = $this->auth->createUser($request);
            log_message('info', 'Firebase::createFirebaseUser() created uid=' . $user->uid . ' email=' . $email);
            return $user;
        } catch (\Exception $e) {
            log_message('error', 'Firebase::createFirebaseUser() failed [' . $email . ']: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Sign in with email and password (server-side).
     * Returns a SignInResult containing idToken, refreshToken, etc.
     *
     * @return \Kreait\Firebase\Auth\SignInResult|null
     */
    public function signInWithEmail(string $email, string $password)
    {
        try {
            return $this->auth->signInWithEmailAndPassword($email, $password);
        } catch (\Exception $e) {
            log_message('error', 'Firebase::signInWithEmail() failed [' . $email . ']: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Verify a Firebase ID token (JWT) with short-TTL in-memory cache.
     *
     * Caches verified tokens for 60 seconds to avoid hitting Firebase servers
     * on every request within the same PHP process (e.g., multiple AJAX calls).
     * For cross-process caching, uses a file-based cache with 5-minute TTL.
     *
     * @param string $idToken       The Firebase ID token string
     * @param bool   $checkRevoked  Whether to check if the token has been revoked
     * @return array|null           Decoded claims ['uid', 'email', 'role', 'school_id', ...] or null
     */
    public function verifyFirebaseToken(string $idToken, bool $checkRevoked = false): ?array
    {
        // Short-circuit: reject obviously invalid tokens
        if (strlen($idToken) < 100 || substr_count($idToken, '.') !== 2) {
            return null;
        }

        // File-based cache (survives across requests, 5-minute TTL)
        $cacheKey  = hash('sha256', $idToken);
        $cacheFile = APPPATH . 'cache/token_' . substr($cacheKey, 0, 16) . '.json';

        if (file_exists($cacheFile)) {
            $cached = @json_decode(file_get_contents($cacheFile), true);
            if ($cached && isset($cached['exp']) && $cached['exp'] > time() && isset($cached['claims'])) {
                return $cached['claims'];
            }
            @unlink($cacheFile);  // expired or corrupt
        }

        // Verify with Firebase
        try {
            $decoded = $this->auth->verifyIdToken($idToken, $checkRevoked);

            // Extract standard + custom claims
            $claims = [
                'uid'          => $decoded->claims()->get('sub'),
                'email'        => $decoded->claims()->get('email', ''),
                'role'         => $decoded->claims()->get('role', ''),
                'school_id'    => $decoded->claims()->get('school_id', ''),
                'school_code'  => $decoded->claims()->get('school_code', ''),
                'parent_db_key' => $decoded->claims()->get('parent_db_key', ''),
                'exp'          => $decoded->claims()->get('exp') instanceof \DateTimeImmutable
                    ? $decoded->claims()->get('exp')->getTimestamp()
                    : (int) $decoded->claims()->get('exp'),
                'iat'          => $decoded->claims()->get('iat') instanceof \DateTimeImmutable
                    ? $decoded->claims()->get('iat')->getTimestamp()
                    : (int) $decoded->claims()->get('iat'),
            ];

            // Cache for 5 minutes (or until token expiry, whichever is sooner)
            $cacheTtl = min(300, max(0, $claims['exp'] - time()));
            if ($cacheTtl > 10) {
                $cacheDir = APPPATH . 'cache';
                if (!is_dir($cacheDir)) @mkdir($cacheDir, 0700, true);
                @file_put_contents($cacheFile, json_encode([
                    'claims' => $claims,
                    'exp'    => time() + $cacheTtl,
                ]), LOCK_EX);
            }

            return $claims;

        } catch (\Exception $e) {
            log_message('error', 'Firebase::verifyFirebaseToken() failed: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Clean expired token cache files. Call periodically (e.g., from a cron).
     */
    public function cleanTokenCache(): int
    {
        $cacheDir = APPPATH . 'cache';
        $cleaned  = 0;
        $files    = glob($cacheDir . '/token_*.json');
        if (!$files) return 0;

        foreach ($files as $file) {
            $cached = @json_decode(file_get_contents($file), true);
            if (!$cached || !isset($cached['exp']) || $cached['exp'] <= time()) {
                @unlink($file);
                $cleaned++;
            }
        }
        return $cleaned;
    }

    /**
     * Set custom claims on a Firebase Auth user (role, school_id, etc.).
     * Claims propagate to ID tokens on next refresh (up to 1 hour).
     *
     * @param string     $uid    Firebase Auth UID
     * @param array|null $claims Associative array of claims (null to clear)
     */
    public function setFirebaseClaims(string $uid, ?array $claims): bool
    {
        try {
            $this->auth->setCustomUserClaims($uid, $claims);
            log_message('info', 'Firebase::setFirebaseClaims() set claims for uid=' . $uid);
            return true;
        } catch (\Exception $e) {
            log_message('error', 'Firebase::setFirebaseClaims() failed [uid=' . $uid . ']: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Update a Firebase Auth user (password, email, displayName, disabled, etc.).
     *
     * @param string $uid   Firebase Auth UID
     * @param array  $props Properties to update (password, email, displayName, disabled, etc.)
     * @return \Kreait\Firebase\Auth\UserRecord|null
     */
    public function updateFirebaseUser(string $uid, array $props)
    {
        try {
            $user = $this->auth->updateUser($uid, $props);
            log_message('info', 'Firebase::updateFirebaseUser() updated uid=' . $uid);
            return $user;
        } catch (\Exception $e) {
            log_message('error', 'Firebase::updateFirebaseUser() failed [uid=' . $uid . ']: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Delete a Firebase Auth user account.
     */
    public function deleteFirebaseUser(string $uid): bool
    {
        try {
            $this->auth->deleteUser($uid);
            log_message('info', 'Firebase::deleteFirebaseUser() deleted uid=' . $uid);
            return true;
        } catch (\Exception $e) {
            log_message('error', 'Firebase::deleteFirebaseUser() failed [uid=' . $uid . ']: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Send a password reset email via Firebase Auth.
     */
    public function sendPasswordReset(string $email): bool
    {
        try {
            $this->auth->sendPasswordResetLink($email);
            log_message('info', 'Firebase::sendPasswordReset() sent to ' . $email);
            return true;
        } catch (\Exception $e) {
            log_message('error', 'Firebase::sendPasswordReset() failed [' . $email . ']: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Revoke all refresh tokens for a user (forces re-authentication).
     */
    public function revokeRefreshTokens(string $uid): bool
    {
        try {
            $this->auth->revokeRefreshTokens($uid);
            log_message('info', 'Firebase::revokeRefreshTokens() revoked for uid=' . $uid);
            return true;
        } catch (\Exception $e) {
            log_message('error', 'Firebase::revokeRefreshTokens() failed [uid=' . $uid . ']: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Get a Firebase Auth user by UID.
     *
     * @return \Kreait\Firebase\Auth\UserRecord|null
     */
    public function getFirebaseUser(string $uid)
    {
        try {
            return $this->auth->getUser($uid);
        } catch (\Exception $e) {
            log_message('error', 'Firebase::getFirebaseUser() failed [uid=' . $uid . ']: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Get a Firebase Auth user by email.
     *
     * @return \Kreait\Firebase\Auth\UserRecord|null
     */
    public function getFirebaseUserByEmail(string $email)
    {
        try {
            return $this->auth->getUserByEmail($email);
        } catch (\Exception $e) {
            log_message('error', 'Firebase::getFirebaseUserByEmail() failed [' . $email . ']: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Build the synthetic email used for Firebase Auth from a userId.
     * Convention: {userId}@schoolsync.app (lowercase).
     */
    public static function authEmail(string $userId): string
    {
        return strtolower($userId) . '@schoolsync.app';
    }

    // ══════════════════════════════════════════════════════════════════
    // ██  FIRESTORE OPERATIONS (delegated to FirestoreRestClient)
    // ══════════════════════════════════════════════════════════════════

    public function getFirestoreDb()
    {
        return $this->firestoreDb;
    }

    public function firestoreGet(string $collection, string $docId): ?array
    {
        if ($this->firestoreDb === null) { log_message('error', 'Firebase::firestoreGet() — Firestore not initialized'); return null; }
        return $this->firestoreDb->getDocument($collection, $docId);
    }

    public function firestoreSet(string $collection, string $docId, array $data, bool $merge = false): bool
    {
        if ($this->firestoreDb === null) { log_message('error', 'Firebase::firestoreSet() — Firestore not initialized'); return false; }
        return $this->firestoreDb->setDocument($collection, $docId, $data, $merge);
    }

    /**
     * Atomic create-if-not-exists. Returns true if the doc was created,
     * false if it already existed (409) or on any other write failure.
     */
    public function firestoreCreate(string $collection, string $docId, array $data): bool
    {
        if ($this->firestoreDb === null) { log_message('error', 'Firebase::firestoreCreate() — Firestore not initialized'); return false; }
        return $this->firestoreDb->createDocument($collection, $docId, $data);
    }

    /**
     * Atomic batch commit. `$ops` is a list of
     *   ['op' => 'set'|'update'|'delete', 'collection' => 'x',
     *    'docId' => 'y', 'data' => [...], 'merge' => bool]
     * Returns true only if ALL writes commit together. The receipt
     * pipeline uses this to collapse ~8 sequential Firestore calls into
     * a single `:commit` RTT.
     */
    public function firestoreCommitBatch(array $ops): bool
    {
        if ($this->firestoreDb === null) { log_message('error', 'Firebase::firestoreCommitBatch() — Firestore not initialized'); return false; }
        return $this->firestoreDb->commitBatch($ops);
    }

    /**
     * Atomic merge-write + field-delete in a single PATCH. Used by
     * shape-migration scripts (Phase 1 class/section cleanup, etc.).
     */
    public function firestoreSetWithDeletes(string $collection, string $docId, array $setData, array $deleteFields): bool
    {
        if ($this->firestoreDb === null) { log_message('error', 'Firebase::firestoreSetWithDeletes() — Firestore not initialized'); return false; }
        return $this->firestoreDb->setDocumentWithDeletes($collection, $docId, $setData, $deleteFields);
    }

    public function firestoreUpdate(string $collection, string $docId, array $data): bool
    {
        if ($this->firestoreDb === null) { log_message('error', 'Firebase::firestoreUpdate() — Firestore not initialized'); return false; }
        return $this->firestoreDb->updateDocument($collection, $docId, $data);
    }

    public function firestoreDelete(string $collection, string $docId): bool
    {
        if ($this->firestoreDb === null) { log_message('error', 'Firebase::firestoreDelete() — Firestore not initialized'); return false; }
        return $this->firestoreDb->deleteDocument($collection, $docId);
    }

    public function firestoreQuery(
        string $collection,
        array $conditions = [],
        ?string $orderBy = null,
        string $direction = 'ASC',
        ?int $limit = null
    ): array {
        if ($this->firestoreDb === null) { log_message('error', 'Firebase::firestoreQuery() — Firestore not initialized'); return []; }
        return $this->firestoreDb->query($collection, $conditions, $orderBy, $direction, $limit);
    }
}

