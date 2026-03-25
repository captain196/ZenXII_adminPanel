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

    public function __construct()
    {
        $serviceAccountPath = __DIR__ . '/../config/graders-1c047-firebase-adminsdk-z1a10-ca28a54060.json';
        $databaseUri        = 'https://graders-1c047-default-rtdb.asia-southeast1.firebasedatabase.app/';

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
        // Named database 'schoolsync' on project graders-1c047.
        try {
            $this->firestoreDb = new FirestoreRestClient($serviceAccountPath, 'graders-1c047', 'schoolsync');
        } catch (\Exception $e) {
            log_message('error', 'Firebase::__construct() Firestore REST init failed: ' . $e->getMessage());
            $this->firestoreDb = null;
        }

        // Firebase Storage — same pattern as Common_model
        $storageClient       = new StorageClient(['keyFilePath' => $serviceAccountPath]);
        $this->storageBucket = $storageClient->bucket('graders-1c047.appspot.com');
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

    // ── [FIX-1] All reads now go through the authenticated Admin SDK ──────────

    /**
     * Read data from a Firebase path.
     * Returns the value (array/scalar/null) or null on failure.
     */
    public function get(string $path)
    {
        $t = microtime(true);
        try {
            $result = $this->database->getReference($path)->getValue();
            if (defined('GRADER_DEBUG') && GRADER_DEBUG) {
                $this->_dbg('READ', $path, $t, $result);
            }
            return $result;
        } catch (\Exception $e) {
            if (defined('GRADER_DEBUG') && GRADER_DEBUG) {
                $this->_dbg('READ', $path, $t, null, $e->getMessage());
            }
            log_message('error', 'Firebase::get() failed for path [' . $path . ']: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Set (create or overwrite) data at a path.
     */
    public function set(string $path, $data)
    {
        $t = microtime(true);
        try {
            $this->database->getReference($path)->set($data);
            if (defined('GRADER_DEBUG') && GRADER_DEBUG) {
                $this->_dbg('WRITE', $path, $t, $data);
            }
            return true;
        } catch (\Exception $e) {
            if (defined('GRADER_DEBUG') && GRADER_DEBUG) {
                $this->_dbg('WRITE', $path, $t, null, $e->getMessage());
            }
            log_message('error', 'Firebase::set() failed for path [' . $path . ']: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Update (merge) data at a path.
     */
    public function update(string $path, array $data)
    {
        $t = microtime(true);
        try {
            $this->database->getReference($path)->update($data);
            if (defined('GRADER_DEBUG') && GRADER_DEBUG) {
                $this->_dbg('WRITE', $path, $t, $data);
            }
            return true;
        } catch (\Exception $e) {
            if (defined('GRADER_DEBUG') && GRADER_DEBUG) {
                $this->_dbg('WRITE', $path, $t, null, $e->getMessage());
            }
            log_message('error', 'Firebase::update() failed for path [' . $path . ']: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Delete a node at a path.
     *
     * Two calling conventions are supported:
     *   delete("Full/Path/To/Node")           — deletes that exact node
     *   delete("Parent/Path", "child_key")    — deletes Parent/Path/child_key
     *
     * The two-argument form exists because many SA controllers pass the parent
     * path and child key separately.  PHP silently ignores extra arguments to
     * single-parameter functions, so without the $key parameter those callers
     * were incorrectly deleting the entire parent node instead of the child.
     */
    public function delete(string $path, string $key = '')
    {
        $fullPath = ($key !== '') ? "{$path}/{$key}" : $path;
        $t = microtime(true);
        try {
            $this->database->getReference($fullPath)->remove();
            if (defined('GRADER_DEBUG') && GRADER_DEBUG) {
                $this->_dbg('DELETE', $fullPath, $t, null);
            }
            return true;
        } catch (\Exception $e) {
            if (defined('GRADER_DEBUG') && GRADER_DEBUG) {
                $this->_dbg('DELETE', $fullPath, $t, null, $e->getMessage());
            }
            log_message('error', 'Firebase::delete() failed for path [' . $fullPath . ']: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Push data, generating a unique key. Returns the new key or null.
     */
    public function push(string $path, $data): ?string
    {
        $t = microtime(true);
        try {
            $key = $this->database->getReference($path)->push($data)->getKey();
            if (defined('GRADER_DEBUG') && GRADER_DEBUG) {
                $this->_dbg('PUSH', $path, $t, $data);
            }
            return $key;
        } catch (\Exception $e) {
            if (defined('GRADER_DEBUG') && GRADER_DEBUG) {
                $this->_dbg('PUSH', $path, $t, null, $e->getMessage());
            }
            log_message('error', 'Firebase::push() failed for path [' . $path . ']: ' . $e->getMessage());
            return null;
        }
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
        $t = microtime(true);
        try {
            $value = $this->database->getReference($path)->shallow()->getSnapshot()->getValue();
            $result = is_array($value) ? array_keys($value) : [];
            if (defined('GRADER_DEBUG') && GRADER_DEBUG) {
                $this->_dbg('SHALLOW', $path, $t, $result);
            }
            return $result;
        } catch (\Exception $e) {
            if (defined('GRADER_DEBUG') && GRADER_DEBUG) {
                $this->_dbg('SHALLOW', $path, $t, null, $e->getMessage());
            }
            log_message('error', 'Firebase::shallow_get() failed for path [' . $path . ']: ' . $e->getMessage());
            return [];
        }
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

