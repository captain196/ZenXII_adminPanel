<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Data_service — Unified Data Access Layer (DAL)
 *
 * Single entry point for ALL data operations across the admin panel.
 * Ensures consistent Firestore-first + RTDB-fallback behavior.
 *
 * WRITE FLOW:
 *   1. Firestore (primary) → if fails → 2. RTDB (fallback) → 3. Queue for retry
 *
 * READ FLOW:
 *   1. Firestore (primary) → if empty/fails → 2. RTDB (fallback) → 3. Normalize
 *
 * USAGE:
 *   $this->data->get('students', 'STU0001');
 *   $this->data->getWhere('students', [['status', '==', 'Active']]);
 *   $this->data->set('students', 'STU0001', $data);
 *   $this->data->update('students', 'STU0001', $data);
 *   $this->data->delete('students', 'STU0001');
 *
 * COLLECTIONS & RTDB PATH MAPPING:
 *   'students'     → Users/Parents/{parentDbKey}/{id}
 *   'staff'        → Users/Admin/{parentDbKey}/{id}
 *   'schools'      → System/Schools/{id}
 *   'systemPlans'  → System/Plans/{id}
 *   'sections'     → Schools/{school}/{session}/{class}/{section}
 *   'circulars'    → Schools/{school}/Communication/Notices/{id}
 *   'subjects'     → Schools/{school}/Subject_list/{classKey}/{code}
 */
class Data_service
{
    /** @var object Firebase RTDB library */
    private $firebase;

    /** @var Firestore_service Firestore service */
    private $fs;

    /** @var Firestore_retry_queue */
    private $retryQueue;

    /** @var string */
    private $schoolId    = '';
    private $session     = '';
    private $schoolCode  = '';
    private $parentDbKey = '';

    /** @var bool */
    private $ready = false;
    private $simulateFailure = false;

    /** @var array Operation log for current request */
    private $log = [];

    /** @var array Stats */
    private $stats = [
        'fs_reads' => 0, 'fs_writes' => 0, 'fs_failures' => 0,
        'rtdb_reads' => 0, 'rtdb_writes' => 0, 'rtdb_failures' => 0,
        'fallbacks' => 0,
    ];

    // ── RTDB path mapping: collection → RTDB path pattern ────────
    private const RTDB_PATHS = [
        'students'    => 'Users/Parents/{parentDbKey}/{id}',
        'staff'       => 'Users/Admin/{parentDbKey}/{id}',
        'schools'     => 'System/Schools/{id}',
        'systemPlans' => 'System/Plans/{id}',
        'circulars'   => 'Schools/{schoolId}/Communication/Notices/{id}',
        'sections'    => 'Schools/{schoolId}/{session}/{classKey}/{sectionKey}',
        'subjects'    => 'Schools/{schoolId}/Subject_list/{classKey}/{code}',
    ];

    // ══════════════════════════════════════════════════════════════════
    //  INIT
    // ══════════════════════════════════════════════════════════════════

    public function init(
        $firebase,
        $fs,
        string $schoolId = '',
        string $session = '',
        string $schoolCode = '',
        string $parentDbKey = ''
    ): self {
        $this->firebase    = $firebase;
        $this->fs          = $fs;
        $this->schoolId    = $schoolId;
        $this->session     = $session;
        $this->schoolCode  = $schoolCode ?: $schoolId;
        $this->parentDbKey = $parentDbKey ?: $this->schoolCode;

        $CI =& get_instance();
        if (!isset($CI->firestore_retry_queue)) {
            $CI->load->library('firestore_retry_queue');
        }
        $this->retryQueue = $CI->firestore_retry_queue;

        $this->simulateFailure = ($CI->config->item('simulate_firestore_failure') === true);

        $this->ready = ($firebase !== null && $fs !== null);
        return $this;
    }

    public function isReady(): bool   { return $this->ready; }
    public function getStats(): array { return $this->stats; }
    public function getLog(): array   { return $this->log; }

    // ══════════════════════════════════════════════════════════════════
    //  READ: GET single document
    // ══════════════════════════════════════════════════════════════════

    /**
     * Get a single document by collection + ID.
     * Firestore first → RTDB fallback → null if not found.
     *
     * @return array|null Document data or null
     */
    public function get(string $collection, string $id): ?array
    {
        // 1. Firestore first (skip if simulating failure)
        if (!$this->simulateFailure) {
            try {
                $fsDocId = $this->_fsDocId($collection, $id);
                $doc = $this->fs->get($collection, $fsDocId);
                $this->stats['fs_reads']++;

                if (is_array($doc) && !empty($doc)) {
                    $this->_log('read', $collection, $id, 'firestore', true);
                    return $this->_normalize($collection, $doc, $id);
                }
            } catch (\Exception $e) {
                $this->stats['fs_failures']++;
                $this->_log('read', $collection, $id, 'firestore', false, $e->getMessage());
            }
        }

        // 2. RTDB fallback
        try {
            $rtdbPath = $this->_rtdbPath($collection, $id);
            if ($rtdbPath) {
                $data = $this->firebase->get($rtdbPath);
                $this->stats['rtdb_reads']++;

                if (is_array($data) && !empty($data)) {
                    $this->stats['fallbacks']++;
                    $this->_log('read', $collection, $id, 'rtdb_fallback', true);
                    return $this->_normalize($collection, $data, $id);
                }
            }
        } catch (\Exception $e) {
            $this->stats['rtdb_failures']++;
            $this->_log('read', $collection, $id, 'rtdb', false, $e->getMessage());
        }

        $this->_log('read', $collection, $id, 'not_found', false);
        return null;
    }

    // ══════════════════════════════════════════════════════════════════
    //  READ: QUERY (multiple documents)
    // ══════════════════════════════════════════════════════════════════

    /**
     * Query documents with conditions.
     * Firestore first → RTDB is not queryable the same way, so only FS.
     *
     * @param array $conditions  [['field', 'op', 'value'], ...]
     * @return array  List of normalized documents
     */
    public function getWhere(string $collection, array $conditions = [], ?string $orderBy = null, int $limit = 100): array
    {
        // Firestore query (primary)
        if (!$this->simulateFailure) {
            try {
                $results = $this->fs->schoolWhere($collection, $conditions, $orderBy, 'ASC', $limit);
                $this->stats['fs_reads']++;

                if (!empty($results)) {
                    $this->_log('query', $collection, count($results) . ' docs', 'firestore', true);
                    return array_map(function ($r) use ($collection) {
                        $data = $r['data'] ?? $r;
                        $id   = $data['studentId'] ?? $data['userId'] ?? $data['planId'] ?? $r['id'] ?? '';
                        return $this->_normalize($collection, $data, $id);
                    }, $results);
                }
            } catch (\Exception $e) {
                $this->stats['fs_failures']++;
                $this->_log('query', $collection, 'failed', 'firestore', false, $e->getMessage());
            }
        }

        // RTDB fallback for simple queries (collection-level read + filter)
        try {
            $rtdbBase = $this->_rtdbBasePath($collection);
            if ($rtdbBase) {
                $all = $this->firebase->get($rtdbBase);
                $this->stats['rtdb_reads']++;

                if (is_array($all) && !empty($all)) {
                    $this->stats['fallbacks']++;
                    $filtered = $this->_filterRtdb($all, $conditions);
                    $this->_log('query', $collection, count($filtered) . ' docs', 'rtdb_fallback', true);
                    return array_map(function ($data) use ($collection) {
                        $id = $data['User Id'] ?? $data['studentId'] ?? $data['plan_id'] ?? '';
                        return $this->_normalize($collection, $data, $id);
                    }, array_values($filtered));
                }
            }
        } catch (\Exception $e) {
            $this->stats['rtdb_failures']++;
            $this->_log('query', $collection, 'failed', 'rtdb', false, $e->getMessage());
        }

        return [];
    }

    // ══════════════════════════════════════════════════════════════════
    //  WRITE: SET (create/overwrite)
    // ══════════════════════════════════════════════════════════════════

    /**
     * Write a document. Firestore first → RTDB fallback + mirror.
     *
     * @return bool True if at least one write succeeded
     */
    public function set(string $collection, string $id, array $data, bool $merge = true): bool
    {
        // Enforce status field on all entities
        if (!isset($data['status']) && !isset($data['Status'])) {
            $data['status'] = 'Active';
        }
        $data['updatedAt'] = date('c');
        $fsOk   = false;
        $rtdbOk = false;

        // 1. Firestore (primary)
        if (!$this->simulateFailure) {
            try {
                $fsDocId = $this->_fsDocId($collection, $id);
                $fsOk = $this->fs->set($collection, $fsDocId, $data, $merge);
                $this->stats['fs_writes']++;
                $this->_log('write', $collection, $id, 'firestore', $fsOk);
            } catch (\Exception $e) {
                $this->stats['fs_failures']++;
                $this->_log('write', $collection, $id, 'firestore', false, $e->getMessage());
            }
        } else {
            $this->_log('write', $collection, $id, 'firestore', false, 'SIMULATED FAILURE');
        }

        // 2. RTDB (mirror or fallback)
        try {
            $rtdbPath = $this->_rtdbPath($collection, $id);
            if ($rtdbPath) {
                if ($merge) {
                    $this->firebase->update($rtdbPath, $data);
                } else {
                    $this->firebase->set($rtdbPath, $data);
                }
                $rtdbOk = true;
                $this->stats['rtdb_writes']++;
                $this->_log('write', $collection, $id, 'rtdb', true);
            }
        } catch (\Exception $e) {
            $this->stats['rtdb_failures']++;
            $this->_log('write', $collection, $id, 'rtdb', false, $e->getMessage());
        }

        // 3. Queue for retry if Firestore failed but RTDB succeeded
        if (!$fsOk && $rtdbOk && $this->retryQueue) {
            $this->retryQueue->push(
                '_dalRetry', [$collection, $id, $data, $merge],
                $this->schoolId, $this->schoolCode, $this->session,
                'DAL: Firestore write failed, RTDB succeeded',
                "dal_{$collection}_{$id}"
            );
            $this->_log('queue', $collection, $id, 'retry_queued', true);
        }

        return $fsOk || $rtdbOk;
    }

    // ══════════════════════════════════════════════════════════════════
    //  WRITE: UPDATE (partial)
    // ══════════════════════════════════════════════════════════════════

    public function update(string $collection, string $id, array $data): bool
    {
        return $this->set($collection, $id, $data, true);
    }

    // ══════════════════════════════════════════════════════════════════
    //  WRITE: DELETE
    // ══════════════════════════════════════════════════════════════════

    public function delete(string $collection, string $id): bool
    {
        $fsOk   = false;
        $rtdbOk = false;

        // Firestore
        if (!$this->simulateFailure) {
            try {
                $fsDocId = $this->_fsDocId($collection, $id);
                $fsOk = $this->fs->remove($collection, $fsDocId);
                $this->stats['fs_writes']++;
                $this->_log('delete', $collection, $id, 'firestore', $fsOk);
            } catch (\Exception $e) {
                $this->stats['fs_failures']++;
                $this->_log('delete', $collection, $id, 'firestore', false, $e->getMessage());
            }
        }

        // RTDB
        try {
            $rtdbPath = $this->_rtdbPath($collection, $id);
            if ($rtdbPath) {
                // For RTDB delete, we need parent path + child key
                $lastSlash = strrpos($rtdbPath, '/');
                $parentPath = substr($rtdbPath, 0, $lastSlash);
                $childKey   = substr($rtdbPath, $lastSlash + 1);
                $this->firebase->delete($parentPath, $childKey);
                $rtdbOk = true;
                $this->stats['rtdb_writes']++;
                $this->_log('delete', $collection, $id, 'rtdb', true);
            }
        } catch (\Exception $e) {
            $this->stats['rtdb_failures']++;
            $this->_log('delete', $collection, $id, 'rtdb', false, $e->getMessage());
        }

        return $fsOk || $rtdbOk;
    }

    // ══════════════════════════════════════════════════════════════════
    //  PATH RESOLUTION
    // ══════════════════════════════════════════════════════════════════

    /**
     * Build Firestore document ID from collection + entity ID.
     */
    private function _fsDocId(string $collection, string $id): string
    {
        // Collections that use school-scoped IDs
        $schoolScoped = ['students', 'staff', 'sections', 'subjects', 'circulars',
                         'attendance', 'attendanceSummary', 'homework', 'feeStructures',
                         'feeDemands', 'feeReceipts', 'leaveApplications'];

        if (in_array($collection, $schoolScoped, true)) {
            // Already prefixed?
            if (strpos($id, $this->schoolId . '_') === 0) return $id;
            return "{$this->schoolId}_{$id}";
        }

        // Global collections: schools, systemPlans — use ID directly
        return $id;
    }

    /**
     * Build RTDB path for a collection + entity ID.
     */
    private function _rtdbPath(string $collection, string $id): ?string
    {
        $template = self::RTDB_PATHS[$collection] ?? null;
        if (!$template) return null;

        return str_replace(
            ['{parentDbKey}', '{schoolId}', '{session}', '{id}'],
            [$this->parentDbKey, $this->schoolId, $this->session, $id],
            $template
        );
    }

    /**
     * Build RTDB base path for collection-level reads.
     */
    private function _rtdbBasePath(string $collection): ?string
    {
        $map = [
            'students'    => "Users/Parents/{$this->parentDbKey}",
            'staff'       => "Users/Admin/{$this->parentDbKey}",
            'schools'     => 'System/Schools',
            'systemPlans' => 'System/Plans',
            'circulars'   => "Schools/{$this->schoolId}/Communication/Notices",
        ];
        return $map[$collection] ?? null;
    }

    // ══════════════════════════════════════════════════════════════════
    //  NORMALIZATION (ensure same structure from both sources)
    // ══════════════════════════════════════════════════════════════════

    /**
     * Normalize document data to a consistent format regardless of source.
     */
    private function _normalize(string $collection, array $data, string $id = ''): array
    {
        switch ($collection) {
            case 'students':
                return [
                    'id'            => $id ?: ($data['studentId'] ?? $data['User Id'] ?? ''),
                    'name'          => $data['name'] ?? $data['Name'] ?? '',
                    'phone'         => $data['phone'] ?? $data['phoneNumber'] ?? $data['Phone Number'] ?? '',
                    'email'         => $data['email'] ?? $data['Email'] ?? '',
                    'className'     => $data['className'] ?? $data['Class'] ?? '',
                    'section'       => $data['section'] ?? $data['Section'] ?? '',
                    'rollNo'        => $data['rollNo'] ?? $data['Roll No'] ?? '',
                    'fatherName'    => $data['fatherName'] ?? $data['Father Name'] ?? '',
                    'motherName'    => $data['motherName'] ?? $data['Mother Name'] ?? '',
                    'gender'        => $data['gender'] ?? $data['Gender'] ?? '',
                    'dob'           => $data['dob'] ?? $data['DOB'] ?? '',
                    'status'        => $data['status'] ?? $data['Status'] ?? 'Active',
                    'admissionDate' => $data['admissionDate'] ?? $data['Admission Date'] ?? '',
                    'profilePic'    => $data['profilePic'] ?? $data['Profile Pic'] ?? '',
                    'schoolId'      => $data['schoolId'] ?? $this->schoolId,
                    'session'       => $data['session'] ?? $this->session,
                    '_raw'          => $data, // keep original for write-back
                ];

            case 'systemPlans':
                $modules = $data['modules'] ?? [];
                if (is_array($modules) && !empty($modules) && !array_is_list($modules)) {
                    $modules = $modules; // map format — keep as-is
                } elseif (is_array($modules) && array_is_list($modules)) {
                    $m = [];
                    foreach ($modules as $mod) $m[$mod] = true;
                    $modules = $m;
                }
                return [
                    'id'            => $id ?: ($data['planId'] ?? $data['plan_id'] ?? ''),
                    'name'          => $data['name'] ?? '',
                    'price'         => (float)($data['price'] ?? 0),
                    'billing_cycle' => $data['billing_cycle'] ?? 'annual',
                    'max_students'  => (int)($data['max_students'] ?? $data['maxStudents'] ?? 0),
                    'max_staff'     => (int)($data['max_staff'] ?? $data['maxStaff'] ?? 0),
                    'grace_days'    => (int)($data['grace_days'] ?? $data['graceDays'] ?? 7),
                    'sort_order'    => (int)($data['sort_order'] ?? $data['sortOrder'] ?? 10),
                    'modules'       => $modules,
                    'status'        => $data['status'] ?? 'Active',
                    '_raw'          => $data,
                ];

            case 'circulars':
                return [
                    'id'            => $id ?: '',
                    'title'         => $data['title'] ?? '',
                    'body'          => $data['body'] ?? $data['description'] ?? '',
                    'author'        => $data['author'] ?? $data['created_by_name'] ?? $data['issued_by_name'] ?? '',
                    'authorId'      => $data['authorId'] ?? $data['created_by'] ?? $data['issued_by'] ?? '',
                    'category'      => $data['category'] ?? 'General',
                    'priority'      => $data['priority'] ?? 'Normal',
                    'status'        => $data['status'] ?? 'sent',
                    'attachmentUrl' => $data['attachmentUrl'] ?? $data['attachment_url'] ?? '',
                    '_raw'          => $data,
                ];

            default:
                // Pass through with ID
                $data['id'] = $id ?: ($data['id'] ?? '');
                return $data;
        }
    }

    // ══════════════════════════════════════════════════════════════════
    //  RTDB FILTER (client-side query for fallback)
    // ══════════════════════════════════════════════════════════════════

    private function _filterRtdb(array $all, array $conditions): array
    {
        if (empty($conditions)) return $all;

        return array_filter($all, function ($item) use ($conditions) {
            if (!is_array($item)) return false;
            foreach ($conditions as $cond) {
                if (!is_array($cond) || count($cond) < 3) continue;
                [$field, $op, $value] = $cond;
                $itemVal = $item[$field] ?? null;
                switch ($op) {
                    case '==': if ($itemVal != $value) return false; break;
                    case '!=': if ($itemVal == $value) return false; break;
                    case '>':  if ($itemVal <= $value) return false; break;
                    case '<':  if ($itemVal >= $value) return false; break;
                }
            }
            return true;
        });
    }

    // ══════════════════════════════════════════════════════════════════
    //  LOGGING
    // ══════════════════════════════════════════════════════════════════

    private function _log(string $op, string $collection, string $id, string $source, bool $ok, string $error = ''): void
    {
        $entry = [
            'op'         => $op,
            'collection' => $collection,
            'id'         => $id,
            'source'     => $source,
            'success'    => $ok,
            'error'      => $error,
            'time'       => date('H:i:s.v'),
        ];
        $this->log[] = $entry;

        $level = $ok ? 'debug' : ($source === 'not_found' ? 'debug' : 'error');
        $msg = "DAL: {$op} {$collection}/{$id} source={$source} " . ($ok ? 'OK' : "FAIL: {$error}");
        log_message($level, $msg);
    }
}
