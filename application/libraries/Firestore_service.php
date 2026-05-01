<?php
defined('BASEPATH') or exit('No direct script access allowed');

require_once APPPATH . 'libraries/Entity_firestore_sync.php';

/**
 * Firestore_service — Primary database service for Firestore-only architecture.
 *
 * Replaces Firebase.php RTDB methods with Firestore equivalents.
 * Uses FirestoreRestClient underneath (REST API, no gRPC needed).
 *
 * USAGE:
 *   $this->load->library('firestore_service', null, 'fs');
 *   $this->fs->init($schoolId, $session);
 *
 *   // CRUD
 *   $student = $this->fs->get('students', $studentId);
 *   $this->fs->set('students', $studentId, [...]);
 *   $this->fs->update('students', $studentId, ['name' => 'New Name']);
 *   $this->fs->remove('students', $studentId);
 *
 *   // Queries
 *   $list = $this->fs->where('students', [['className', '==', 'Class 9th']]);
 *   $page = $this->fs->where('students', [...], 'name', 'ASC', 50);
 *
 *   // School-scoped (auto-adds schoolId filter)
 *   $list = $this->fs->schoolWhere('homework', [['status', '==', 'active']]);
 *
 * DOCUMENT ID CONVENTION:
 *   {schoolId}_{entityId}          — e.g., SCH_7DB04256C1_STU0001
 *   {schoolId}_{entityId}_{date}   — e.g., SCH_7DB04256C1_STU0001_2026-03-27
 *
 * COLLECTIONS (32 total — see FIRESTORE_SCHEMA_REVIEW.md):
 *   Core:          schools, students, staff, sections, subjects
 *   Academic:      attendance, attendanceSummary, homework, submissions, exams, marks, results
 *   Communication: chats, chats/{id}/messages, circulars, notifications
 *   Finance:       feeStructures, feeDemands, feeReceipts, ledger
 *   Operations:    timetables, events, galleryAlbums, stories
 *   Transport:     routes, vehicles, studentRoutes, gpsTracking, gpsHistory
 *   Hostel/Lib:    hostelRooms, hostelAllocations, libraryBooks, libraryIssues, libraryFines
 *   HR:            leaveApplications, salarySlips, appraisals
 *   System:        systemCounters, systemPlans, systemPayments, systemLogs,
 *                  indexSchoolCodes, indexAdminIds, indexSchoolNames
 */
class Firestore_service
{
    /** @var FirestoreRestClient */
    private $client;

    /** @var string School ID (SCH_XXXXXX) — set via init() */
    private $schoolId = '';

    /** @var string School login code (e.g., "10001") */
    private $schoolCode = '';

    /** @var string Academic session (e.g., "2025-26") */
    private $session = '';

    /** @var bool Whether init() was called */
    private $ready = false;

    // ── Collection name constants (match Android app constants) ──────
    const SCHOOLS              = 'schools';
    const STUDENTS             = 'students';
    const STAFF                = 'staff';
    const SECTIONS             = 'sections';
    const SUBJECTS             = 'subjects';
    const ATTENDANCE           = 'attendance';
    const ATTENDANCE_SUMMARY   = 'attendanceSummary';
    const HOMEWORK             = 'homework';
    const SUBMISSIONS          = 'submissions';
    const EXAMS                = 'exams';
    const MARKS                = 'marks';
    const RESULTS              = 'results';
    const CHATS                = 'chats';
    const CIRCULARS            = 'circulars';
    const NOTIFICATIONS        = 'notifications';
    const FEE_STRUCTURES       = 'feeStructures';
    const FEE_DEMANDS          = 'feeDemands';
    const FEE_RECEIPTS         = 'feeReceipts';
    const LEDGER               = 'ledger';
    const TIMETABLES           = 'timetables';
    const EVENTS               = 'events';
    const GALLERY_ALBUMS       = 'galleryAlbums';
    const STORIES              = 'stories';
    const ROUTES               = 'routes';
    const VEHICLES             = 'vehicles';
    const STUDENT_ROUTES       = 'studentRoutes';
    const GPS_TRACKING         = 'gpsTracking';
    const GPS_HISTORY          = 'gpsHistory';
    const HOSTEL_ROOMS         = 'hostelRooms';
    const HOSTEL_ALLOCATIONS   = 'hostelAllocations';
    const LIBRARY_BOOKS        = 'libraryBooks';
    const LIBRARY_ISSUES       = 'libraryIssues';
    const LIBRARY_FINES        = 'libraryFines';
    const LEAVE_APPLICATIONS   = 'leaveApplications';
    const SALARY_SLIPS         = 'salarySlips';
    const APPRAISALS           = 'appraisals';
    const SYSTEM_COUNTERS      = 'systemCounters';
    const SYSTEM_PLANS         = 'systemPlans';
    const SYSTEM_PAYMENTS      = 'systemPayments';
    const SYSTEM_LOGS          = 'systemLogs';
    const INDEX_SCHOOL_CODES   = 'indexSchoolCodes';
    const INDEX_ADMIN_IDS      = 'indexAdminIds';
    const INDEX_SCHOOL_NAMES   = 'indexSchoolNames';

    public function __construct()
    {
        $CI =& get_instance();

        // Get the Firestore REST client from the Firebase library
        if (isset($CI->firebase) && method_exists($CI->firebase, 'getFirestoreDb')) {
            $this->client = $CI->firebase->getFirestoreDb();
        }

        // Fallback: initialize directly
        if ($this->client === null) {
            require_once __DIR__ . '/Firestore_rest_client.php';
            $saPath = __DIR__ . '/../config/graderadmin-firebase-adminsdk-a1sml-2b5f1862a7.json';
            try {
                $this->client = new FirestoreRestClient($saPath, 'graderadmin', '(default)');
            } catch (\Exception $e) {
                log_message('error', 'Firestore_service: init failed — ' . $e->getMessage());
            }
        }
    }

    /**
     * Initialize with school context. Call before any school-scoped operations.
     */
    public function init(string $schoolId, string $session = '', string $schoolCode = ''): self
    {
        $this->schoolId   = $schoolId;
        $this->session    = $session;
        $this->schoolCode = $schoolCode ?: $schoolId;
        $this->ready      = ($this->client !== null && $schoolId !== '');
        return $this;
    }

    // Getters
    public function schoolId(): string { return $this->schoolId; }
    public function schoolCode(): string { return $this->schoolCode; }
    public function session(): string { return $this->session; }
    public function isReady(): bool { return $this->ready; }

    // ══════════════════════════════════════════════════════════════════
    //  DOCUMENT ID BUILDERS
    // ══════════════════════════════════════════════════════════════════

    /**
     * Build a school-scoped document ID: {schoolId}_{entityId}
     */
    public function docId(string $entityId): string
    {
        return "{$this->schoolId}_{$entityId}";
    }

    /**
     * Build a compound document ID: {schoolId}_{part1}_{part2}
     */
    public function docId2(string $part1, string $part2): string
    {
        return "{$this->schoolId}_{$part1}_{$part2}";
    }

    /**
     * Build a triple compound ID: {schoolId}_{part1}_{part2}_{part3}
     */
    public function docId3(string $part1, string $part2, string $part3): string
    {
        return "{$this->schoolId}_{$part1}_{$part2}_{$part3}";
    }

    /**
     * Ensure "Class " prefix: "9th" → "Class 9th"
     */
    public static function classKey(string $val): string
    {
        $s = trim($val);
        return ($s !== '' && stripos($s, 'Class ') !== 0) ? "Class {$s}" : $s;
    }

    /**
     * Ensure "Section " prefix: "A" → "Section A"
     */
    public static function sectionKey(string $val): string
    {
        $s = trim($val);
        return ($s !== '' && stripos($s, 'Section ') !== 0) ? "Section {$s}" : $s;
    }

    /**
     * Build combined sectionKey: "Class 8th/Section A"
     */
    public static function buildSectionKey(string $className, string $section): string
    {
        return self::classKey($className) . '/' . self::sectionKey($section);
    }

    // ══════════════════════════════════════════════════════════════════
    //  CRUD OPERATIONS
    // ══════════════════════════════════════════════════════════════════

    /**
     * Get a single document by collection and document ID.
     *
     * @return array|null  Document data or null if not found
     */
    public function get(string $collection, string $docId): ?array
    {
        if ($this->client === null) return null;
        $t = microtime(true);
        try {
            $result = $this->client->getDocument($collection, $docId);
            $this->_track(microtime(true) - $t, false);
            return $result;
        } catch (\Exception $e) {
            $this->_track(microtime(true) - $t, true);
            log_message('error', "Firestore_service::get({$collection}/{$docId}) failed: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Create or overwrite a document.
     *
     * @param bool $merge  If true, merge fields (like update). If false, overwrite entire document.
     */
    public function set(string $collection, string $docId, array $data, bool $merge = false): bool
    {
        if ($this->client === null) return false;
        $t = microtime(true);
        try {
            $result = $this->client->setDocument($collection, $docId, $data, $merge);
            $this->_track(microtime(true) - $t, !$result);
            return $result;
        } catch (\Exception $e) {
            $this->_track(microtime(true) - $t, true);
            log_message('error', "Firestore_service::set({$collection}/{$docId}) failed: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Update specific fields on a document (merge write).
     */
    public function update(string $collection, string $docId, array $data): bool
    {
        if ($this->client === null) return false;
        $t = microtime(true);
        try {
            $result = $this->client->updateDocument($collection, $docId, $data);
            $this->_track(microtime(true) - $t, !$result);
            return $result;
        } catch (\Exception $e) {
            $this->_track(microtime(true) - $t, true);
            log_message('error', "Firestore_service::update({$collection}/{$docId}) failed: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Delete a document.
     */
    public function remove(string $collection, string $docId): bool
    {
        if ($this->client === null) return false;
        $t = microtime(true);
        try {
            $result = $this->client->deleteDocument($collection, $docId);
            $this->_track(microtime(true) - $t, !$result);
            return $result;
        } catch (\Exception $e) {
            $this->_track(microtime(true) - $t, true);
            log_message('error', "Firestore_service::remove({$collection}/{$docId}) failed: " . $e->getMessage());
            return false;
        }
    }

    // ══════════════════════════════════════════════════════════════════
    //  QUERY OPERATIONS
    // ══════════════════════════════════════════════════════════════════

    /**
     * Query documents with conditions.
     *
     * @param string      $collection   Collection name
     * @param array       $conditions   [['field', 'op', 'value'], ...]
     *                                  Ops: ==, <, <=, >, >=, !=, in, not-in, array-contains
     * @param string|null $orderBy      Field to order by
     * @param string      $direction    ASC or DESC
     * @param int|null    $limit        Max results
     * @return array  [['id' => docId, 'data' => [...]], ...]
     */
    public function where(
        string $collection,
        array $conditions = [],
        ?string $orderBy = null,
        string $direction = 'ASC',
        ?int $limit = null
    ): array {
        if ($this->client === null) return [];
        $t = microtime(true);
        try {
            $results = $this->client->query($collection, $conditions, $orderBy, $direction, $limit);
            $this->_track(microtime(true) - $t, false);
            return $results;
        } catch (\Exception $e) {
            $this->_track(microtime(true) - $t, true);
            log_message('error', "Firestore_service::where({$collection}) failed: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Query filtered by current school (auto-adds schoolId condition).
     */
    public function schoolWhere(
        string $collection,
        array $extraConditions = [],
        ?string $orderBy = null,
        string $direction = 'ASC',
        ?int $limit = null
    ): array {
        $conditions = array_merge(
            [['schoolId', '==', $this->schoolId]],
            $extraConditions
        );
        return $this->where($collection, $conditions, $orderBy, $direction, $limit);
    }

    /**
     * Query filtered by current school + session.
     */
    public function sessionWhere(
        string $collection,
        array $extraConditions = [],
        ?string $orderBy = null,
        string $direction = 'ASC',
        ?int $limit = null
    ): array {
        $conditions = array_merge(
            [
                ['schoolId', '==', $this->schoolId],
                ['session', '==', $this->session],
            ],
            $extraConditions
        );
        return $this->where($collection, $conditions, $orderBy, $direction, $limit);
    }

    // ══════════════════════════════════════════════════════════════════
    //  CONVENIENCE: Flatten query results
    // ══════════════════════════════════════════════════════════════════

    /**
     * Query and return just the data arrays (no IDs wrapper).
     * Useful when you don't need document IDs.
     *
     * @return array  [data1, data2, ...] — flat array of document data
     */
    public function list(
        string $collection,
        array $conditions = [],
        ?string $orderBy = null,
        string $direction = 'ASC',
        ?int $limit = null
    ): array {
        $results = $this->where($collection, $conditions, $orderBy, $direction, $limit);
        return array_map(fn($r) => $r['data'], $results);
    }

    /**
     * Query by school and return flat data array.
     */
    public function schoolList(
        string $collection,
        array $extraConditions = [],
        ?string $orderBy = null,
        string $direction = 'ASC',
        ?int $limit = null
    ): array {
        $results = $this->schoolWhere($collection, $extraConditions, $orderBy, $direction, $limit);
        return array_map(fn($r) => $r['data'], $results);
    }

    /**
     * Server-side aggregation passthrough (count/sum/avg).
     *
     * Returns an associative array keyed by the aliases in $aggregations.
     * Returns an empty array on any failure so callers can detect via
     * isset(...) without wrapping in try/catch themselves.
     */
    public function aggregate(string $collection, array $conditions, array $aggregations): array
    {
        // 1) Try native Firestore aggregation (fast, but requires composite index).
        if ($this->client !== null && method_exists($this->client, 'runAggregation')) {
            try {
                $r = $this->client->runAggregation($collection, $conditions, $aggregations);
                if (!empty($r)) return $r;
            } catch (\Exception $e) {
                log_message('error', "Firestore_service::aggregate({$collection}) native failed: " . $e->getMessage());
            }
        }
        // 2) Fallback: fetch matching docs and aggregate in PHP.
        //    Slower but works without composite indexes (fine for ≤10k docs).
        $rows = $this->where($collection, $conditions);
        if (!is_array($rows)) return [];
        $out = [];
        foreach ($aggregations as $a) {
            $op    = strtolower((string)($a['op'] ?? 'count'));
            $alias = (string)($a['alias'] ?? $op);
            $field = (string)($a['field'] ?? '');
            if ($op === 'count') {
                $out[$alias] = count($rows);
            } elseif ($op === 'sum' && $field !== '') {
                $sum = 0.0;
                foreach ($rows as $r) {
                    $d = is_array($r) ? ($r['data'] ?? $r) : [];
                    $sum += (float)($d[$field] ?? 0);
                }
                $out[$alias] = $sum;
            } elseif ($op === 'avg' && $field !== '') {
                $sum = 0.0; $n = 0;
                foreach ($rows as $r) {
                    $d = is_array($r) ? ($r['data'] ?? $r) : [];
                    if (isset($d[$field])) { $sum += (float)$d[$field]; $n++; }
                }
                $out[$alias] = $n > 0 ? $sum / $n : 0.0;
            }
        }
        return $out;
    }

    /**
     * Count documents matching conditions.
     *
     * Uses Firestore runAggregationQuery so no documents are transferred —
     * only the count is returned. Falls back to the legacy fetch-and-count
     * path if the aggregation call fails (e.g., missing index or transient
     * error) so callers never see a hard failure.
     */
    public function count(string $collection, array $conditions = []): int
    {
        if ($this->client !== null && method_exists($this->client, 'runAggregation')) {
            try {
                $res = $this->client->runAggregation($collection, $conditions, [
                    ['op' => 'count', 'alias' => 'n'],
                ]);
                if (isset($res['n'])) return (int) $res['n'];
            } catch (\Exception $e) {
                log_message('error', "Firestore_service::count({$collection}) aggregation failed, falling back: " . $e->getMessage());
            }
        }
        return count($this->where($collection, $conditions));
    }

    /**
     * Check if a document exists.
     */
    public function exists(string $collection, string $docId): bool
    {
        return $this->get($collection, $docId) !== null;
    }

    // ══════════════════════════════════════════════════════════════════
    //  BATCH OPERATIONS
    // ══════════════════════════════════════════════════════════════════

    /**
     * Set multiple documents in the same collection.
     * NOT atomic — each write is independent. But efficient (sequential, no overhead per call).
     *
     * @param string $collection
     * @param array  $documents  ['docId' => data, ...]
     * @return int   Number of successful writes
     */
    public function batchSet(string $collection, array $documents): int
    {
        $success = 0;
        foreach ($documents as $docId => $data) {
            if ($this->set($collection, (string) $docId, $data)) {
                $success++;
            }
        }
        return $success;
    }

    /**
     * Delete multiple documents in the same collection.
     *
     * @return int  Number of successful deletes
     */
    public function batchRemove(string $collection, array $docIds): int
    {
        $success = 0;
        foreach ($docIds as $docId) {
            if ($this->remove($collection, (string) $docId)) {
                $success++;
            }
        }
        return $success;
    }

    // ══════════════════════════════════════════════════════════════════
    //  SCHOOL-SCOPED HELPERS (common patterns)
    // ══════════════════════════════════════════════════════════════════

    /**
     * Get a school-scoped document: collection/{schoolId}_{entityId}
     */
    public function getEntity(string $collection, string $entityId): ?array
    {
        return $this->get($collection, $this->docId($entityId));
    }

    /**
     * Set a school-scoped document with schoolId auto-injected.
     */
    public function setEntity(string $collection, string $entityId, array $data, bool $merge = true): bool
    {
        $data['schoolId'] = $this->schoolId;
        if (!empty($this->session) && !isset($data['session'])) {
            $data['session'] = $this->session;
        }
        $data['updatedAt'] = date('c');
        return $this->set($collection, $this->docId($entityId), $data, $merge);
    }

    /**
     * Update specific fields on a school-scoped document.
     */
    public function updateEntity(string $collection, string $entityId, array $data): bool
    {
        $data['updatedAt'] = date('c');
        return $this->update($collection, $this->docId($entityId), $data);
    }

    /**
     * Delete a school-scoped document.
     */
    public function removeEntity(string $collection, string $entityId): bool
    {
        return $this->remove($collection, $this->docId($entityId));
    }

    // ══════════════════════════════════════════════════════════════════
    //  SPECIFIC ENTITY HELPERS
    // ══════════════════════════════════════════════════════════════════

    /**
     * Save a student profile (includes parent data — per schema review fix #2).
     */
    public function saveStudent(string $studentId, array $data): bool
    {
        // Mapped fields (camelCase for Android app)
        $doc = [
            'schoolId'    => $this->schoolId,
            'studentId'   => $studentId,
            'name'        => $data['Name'] ?? $data['name'] ?? '',
            'fatherName'  => $data['Father Name'] ?? $data['fatherName'] ?? $data['father_name'] ?? '',
            'motherName'  => $data['Mother Name'] ?? $data['motherName'] ?? $data['mother_name'] ?? '',
            'className'   => self::classKey($data['Class'] ?? $data['className'] ?? ''),
            'section'     => self::sectionKey($data['Section'] ?? $data['section'] ?? ''),
            'sectionKey'  => self::classKey($data['Class'] ?? $data['className'] ?? '') . '/' . self::sectionKey($data['Section'] ?? $data['section'] ?? ''),
            'rollNo'      => $data['Roll No'] ?? $data['RollNo'] ?? $data['rollNo'] ?? '',
            'phone'       => $data['Phone'] ?? $data['Phone Number'] ?? $data['phone'] ?? '',
            'email'       => $data['Email'] ?? $data['Parent_email'] ?? $data['email'] ?? '',
            'gender'      => $data['Gender'] ?? $data['gender'] ?? '',
            'dob'         => $data['DOB'] ?? $data['dob'] ?? '',
            'address'     => $data['Address'] ?? $data['address'] ?? '',
            'profilePic'  => $data['Doc']['ProfilePic'] ?? $data['profilePic'] ?? $data['Profile Pic'] ?? $data['profile_pic'] ?? '',
            'status'      => $data['Status'] ?? $data['status'] ?? 'Active',
            'occupation'  => $data['Occupation'] ?? $data['occupation'] ?? '',
            'session'     => $this->session,
            'updatedAt'   => date('c'),
        ];
        // Preserve raw RTDB field names that admin panel views depend on
        // (views read $profile['Name'], $profile['Phone Number'], etc.)
        // `Password` is in the whitelist so the auto-generated password
        // produced at enroll-time is preserved on the doc. Without this
        // the password silently dropped → admin had no record of what
        // was set and couldn't share it with the parent. `application_id`
        // and `mustChangePassword` are kept for the same audit reason.
        $rawFields = [
            'Name', 'Father Name', 'Mother Name', 'Class', 'Section',
            'Roll No', 'Phone Number', 'Phone', 'Email', 'Parent_email',
            'Gender', 'DOB', 'Address', 'Status', 'Occupation',
            'Profile Pic', 'User ID', 'Last_result',
            'Password', 'application_id', 'mustChangePassword',
            'Admission Date', 'Blood Group', 'Category', 'Religion',
            'Nationality', 'Father Occupation', 'Mother Occupation',
            'Guard Contact', 'Guard Relation', 'Pre School', 'Pre Class',
            'Pre Marks', 'Doc',
        ];
        foreach ($rawFields as $rf) {
            if (isset($data[$rf]) && !isset($doc[$rf])) {
                $doc[$rf] = $data[$rf];
            }
        }
        // Ensure User ID is always present (used by views for ID resolution)
        if (empty($doc['User ID'])) $doc['User ID'] = $studentId;

        return $this->set(self::STUDENTS, $this->docId($studentId), $doc, true);
    }

    /**
     * Save a staff/teacher profile.
     */
    public function saveStaff(string $staffId, array $data): bool
    {
        // Build sessions array — merge with existing if present
        $existingSessions = $data['sessions'] ?? [];
        if (!is_array($existingSessions)) $existingSessions = [];
        if (!in_array($this->session, $existingSessions, true) && $this->session !== '') {
            $existingSessions[] = $this->session;
        }

        $doc = [
            'schoolId'    => $this->schoolId,
            'staffId'     => $staffId,
            'name'        => $data['Name'] ?? $data['name'] ?? '',
            'email'       => $data['Email'] ?? $data['email'] ?? '',
            'phone'       => $data['Phone Number'] ?? $data['Phone'] ?? $data['phone'] ?? '',
            'role'        => $data['Role'] ?? $data['role'] ?? '',
            'designation' => $data['Designation'] ?? $data['designation'] ?? '',
            'department'  => $data['Department'] ?? $data['department'] ?? '',
            'profilePic'  => $data['Doc']['ProfilePic'] ?? $data['profilePic'] ?? $data['Photo URL'] ?? $data['profile_pic'] ?? '',
            'status'      => $data['Status'] ?? $data['status'] ?? 'Active',
            'session'     => $this->session,
            'sessions'    => array_values(array_unique($existingSessions)),
            'updatedAt'   => date('c'),
        ];
        // Preserve raw RTDB field names that admin panel views depend on
        $rawFields = [
            'Name', 'Phone Number', 'Email', 'Role', 'Designation',
            'Department', 'Status', 'DOB', 'Date Of Joining', 'Gender',
            'User ID', 'ProfilePic', 'Photo URL', 'Basicsalary', 'Allowances',
        ];
        foreach ($rawFields as $rf) {
            if (isset($data[$rf]) && !isset($doc[$rf])) {
                $doc[$rf] = $data[$rf];
            }
        }
        // Ensure User ID is always present
        if (empty($doc['User ID'])) $doc['User ID'] = $staffId;
        // Never store passwords in Firestore
        unset($doc['Password'], $doc['Credentials']);

        return $this->set(self::STAFF, $this->docId($staffId), $doc, true);
    }

    /**
     * Save school profile.
     */
    public function saveSchool(array $data): bool
    {
        // Map input keys to Firestore camelCase fields (Android SchoolDoc expects these)
        $fieldMap = [
            'name'              => $data['school_name'] ?? $data['display_name'] ?? $data['name'] ?? null,
            'address'           => $data['address'] ?? $data['street'] ?? null,
            'city'              => $data['city'] ?? null,
            'state'             => $data['state'] ?? null,
            'phone'             => $data['phone'] ?? null,
            'email'             => $data['email'] ?? null,
            'logoUrl'           => $data['logo_url'] ?? $data['logoUrl'] ?? null,
            'principal'         => $data['principal_name'] ?? $data['principal'] ?? null,
            'board'             => $data['board'] ?? null,
            'pincode'           => $data['pincode'] ?? null,
            'website'           => $data['website'] ?? null,
            'establishedYear'   => $data['established_year'] ?? $data['establishedYear'] ?? null,
            'affiliationBoard'  => $data['affiliation_board'] ?? $data['affiliationBoard'] ?? null,
            'affiliationNo'     => $data['affiliation_no'] ?? $data['affiliationNo'] ?? null,
        ];

        // Only include non-empty values to avoid overwriting existing data
        $doc = ['schoolId' => $this->schoolId, 'updatedAt' => date('c')];
        foreach ($fieldMap as $key => $val) {
            if ($val !== null && $val !== '') {
                $doc[$key] = $val;
            }
        }

        // Always write these identity/status fields (Android SchoolDoc expects them)
        $doc['schoolCode']      = $this->schoolCode;
        $doc['currentSession']  = $this->session;
        $doc['status']          = $data['status'] ?? 'active';

        return $this->set(self::SCHOOLS, $this->schoolId, $doc, true);
    }

    /**
     * Save section info.
     */
    public function saveSection(string $className, string $sectionName, array $data = []): bool
    {
        $ck = self::classKey($className);
        $sk = self::sectionKey($sectionName);
        // Android expects: {schoolId}_{session}_{className}_{section}
        $docId = "{$this->schoolId}_{$this->session}_{$ck}_{$sk}";

        // Phase 2: stamp canonical classOrder/sectionCode/sectionKey alongside
        // the existing className/section so the sections collection matches
        // the student doc shape.
        $cs = Entity_firestore_sync::normalizeClassSection($ck, $sk);
        $sectionKeyComposite = ($cs['className'] !== '' && $cs['section'] !== '')
            ? "{$cs['className']}/{$cs['section']}"
            : '';

        $doc = array_merge([
            'schoolId'     => $this->schoolId,
            'className'    => $ck,
            'section'      => $sk,
            'classOrder'   => $cs['classOrder'],
            'sectionCode'  => $cs['sectionCode'],
            'sectionKey'   => $sectionKeyComposite,
            'classTeacher' => '',
            'session'      => $this->session,
            'studentCount' => 0,
            'updatedAt'    => date('c'),
        ], $data);
        return $this->set(self::SECTIONS, $docId, $doc, true);
    }

    /**
     * Build the section document ID matching Android convention:
     * {schoolId}_{session}_{Class X}_{Section Y}
     */
    public function sectionDocId(string $className, string $sectionName): string
    {
        $ck = self::classKey($className);
        $sk = self::sectionKey($sectionName);
        return "{$this->schoolId}_{$this->session}_{$ck}_{$sk}";
    }

    /**
     * Save attendance record (unified student + staff — per schema review fix).
     */
    public function saveAttendance(string $personId, string $date, array $data): bool
    {
        $docId = $this->docId2($personId, $date);

        // Phase 4: stamp canonical classOrder/sectionCode/sectionKey alongside
        // the existing className/section so attendance docs match the same
        // shape as students/sections/timetables/subjectAssignments. Teacher
        // app reads `sectionKey` for filtering — keep that as the composite.
        $cs = Entity_firestore_sync::normalizeClassSection(
            (string) ($data['className'] ?? ''),
            (string) ($data['section']   ?? '')
        );
        $sectionKeyComposite = ($cs['className'] !== '' && $cs['section'] !== '')
            ? "{$cs['className']}/{$cs['section']}"
            : '';

        // Merge $data first, then overwrite className/section with prefixed values
        $doc = array_merge($data, [
            'schoolId'    => $this->schoolId,
            'personId'    => $personId,
            'date'        => $date,
            'status'      => $data['status'] ?? 'P',
            'type'        => $data['type'] ?? 'student',  // 'student' or 'staff'
            'className'   => $cs['className'] !== '' ? $cs['className'] : self::classKey($data['className'] ?? ''),
            'section'     => $cs['section']   !== '' ? $cs['section']   : self::sectionKey($data['section'] ?? ''),
            'classOrder'  => $cs['classOrder'],
            'sectionCode' => $cs['sectionCode'],
            'sectionKey'  => $sectionKeyComposite,
            'markedBy'    => $data['markedBy'] ?? '',
            'markedAt'    => $data['markedAt'] ?? date('c'),
            'late'        => $data['late'] ?? false,
            'lateMinutes' => $data['lateMinutes'] ?? 0,
            'session'     => $this->session,
            'expiresAt'   => date('c', strtotime('+5 years')),  // TTL for auto-cleanup
        ]);
        return $this->set(self::ATTENDANCE, $docId, $doc, true);
    }

    /**
     * Save marks — per-student per-subject (per schema review fix #1).
     */
    public function saveMarks(string $examId, string $studentId, string $subject, array $data): bool
    {
        $docId = $this->docId3($examId, $studentId, $subject);
        // Merge $data first, then overwrite className/section with prefixed values
        $doc = array_merge($data, [
            'schoolId'  => $this->schoolId,
            'examId'    => $examId,
            'studentId' => $studentId,
            'subject'   => $subject,
            'className' => self::classKey($data['className'] ?? ''),
            'section'   => self::sectionKey($data['section'] ?? ''),
            'obtained'  => $data['obtained'] ?? 0,
            'total'     => $data['total'] ?? 0,
            'grade'     => $data['grade'] ?? '',
            'rank'      => $data['rank'] ?? 0,
            'session'   => $this->session,
            'updatedAt' => date('c'),
        ]);
        return $this->set(self::MARKS, $docId, $doc, true);
    }

    /**
     * Save exam metadata (includes schedule as nested array — per schema review fix).
     */
    public function saveExam(string $examId, array $data): bool
    {
        $doc = array_merge([
            'schoolId'  => $this->schoolId,
            'examId'    => $examId,
            'name'      => '',
            'type'      => '',
            'startDate' => '',
            'endDate'   => '',
            'maxMarks'  => '',
            'status'    => 'Active',
            'schedule'  => [],  // merged — no separate examSchedule collection
            'session'   => $this->session,
            'updatedAt' => date('c'),
        ], $data);
        return $this->set(self::EXAMS, $this->docId($examId), $doc, true);
    }

    // ══════════════════════════════════════════════════════════════════
    //  SYSTEM OPERATIONS (not school-scoped)
    // ══════════════════════════════════════════════════════════════════

    /**
     * Get/set system counters (for ID generation).
     */
    public function getCounter(string $prefix): int
    {
        $doc = $this->get(self::SYSTEM_COUNTERS, 'global');
        return (int) ($doc[$prefix] ?? 0);
    }

    public function setCounter(string $prefix, int $value): bool
    {
        return $this->update(self::SYSTEM_COUNTERS, 'global', [$prefix => $value]);
    }

    /**
     * Log a system event (flat collection — per schema review fix #3).
     */
    public function logEvent(string $type, array $data): bool
    {
        $logId = date('Ymd_His') . '_' . bin2hex(random_bytes(4));
        $doc = array_merge([
            'type'      => $type,
            'month'     => date('Y-m'),
            'timestamp' => date('c'),
        ], $data);
        return $this->set(self::SYSTEM_LOGS, $logId, $doc);
    }

    /**
     * Get/set index lookups.
     */
    public function getSchoolByCode(string $code): ?string
    {
        $doc = $this->get(self::INDEX_SCHOOL_CODES, $code);
        return $doc['schoolId'] ?? null;
    }

    public function setSchoolCode(string $code, string $schoolId): bool
    {
        return $this->set(self::INDEX_SCHOOL_CODES, $code, ['schoolId' => $schoolId, 'code' => $code]);
    }

    public function getAdminSchool(string $adminId): ?string
    {
        $doc = $this->get(self::INDEX_ADMIN_IDS, $adminId);
        return $doc['schoolCode'] ?? null;
    }

    public function setAdminIndex(string $adminId, string $schoolCode): bool
    {
        return $this->set(self::INDEX_ADMIN_IDS, $adminId, [
            'adminId' => $adminId, 'schoolCode' => $schoolCode,
        ]);
    }

    // ══════════════════════════════════════════════════════════════════
    //  METRICS
    // ══════════════════════════════════════════════════════════════════

    private function _track(float $elapsed, bool $isError): void
    {
        $CI =& get_instance();
        if (isset($CI->request_context) && $CI->request_context instanceof Request_context) {
            $CI->request_context->record_firebase_op($elapsed, $isError);
        }
    }
}
