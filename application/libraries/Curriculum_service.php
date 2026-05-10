<?php
defined('BASEPATH') OR exit('No direct script access allowed');

require_once APPPATH . 'libraries/Service_exception.php';
require_once APPPATH . 'libraries/Entity_firestore_sync.php';

/**
 * Curriculum_service — owns curriculum + topic CRUD.
 *
 * Phase 5: extracted from Academic.php with ZERO behavior change.
 * Phases 0-4 invariants preserved:
 *   - Per-assignment authorization (Phase 0)
 *   - Optimistic concurrency via expected_version (Phase 2)
 *   - Subcollection mode with stable UUIDs (Phase 1)
 *   - Audit logging on every mutation (Phase 4)
 *
 * USAGE
 *   $svc = $this->load->library('curriculum_service', null, 'curr');
 *   $svc->init($firebase, $fs, $schoolId, $schoolName, $session,
 *              $adminId, $adminName, $adminRole, $auditLogService);
 *   $svc->getCurriculum($classSection, $subject);
 */
class Curriculum_service
{
    /** @var object */ private $firebase;
    /** @var object */ private $fs;
    /** @var string */ private $schoolId   = '';
    /** @var string */ private $schoolName = '';
    /** @var string */ private $session    = '';
    /** @var string */ private $adminId    = '';
    /** @var string */ private $adminName  = '';
    /** @var string */ private $adminRole  = '';
    /** @var object|null */ private $audit = null;
    /** @var bool   */ private $ready = false;

    const COLLECTION = 'curriculum';

    public function init(
        $firebase, $fs,
        string $schoolId, string $schoolName, string $session,
        string $adminId, string $adminName, string $adminRole,
        $auditLogService = null
    ): self {
        $this->firebase   = $firebase;
        $this->fs         = $fs;
        $this->schoolId   = $schoolId;
        $this->schoolName = $schoolName;
        $this->session    = $session;
        $this->adminId    = $adminId;
        $this->adminName  = $adminName;
        $this->adminRole  = $adminRole;
        $this->audit      = $auditLogService;
        $this->ready      = ($firebase !== null && $schoolId !== '');
        return $this;
    }

    public function isReady(): bool { return $this->ready; }

    // ══════════════════════════════════════════════════════════════════
    //  PUBLIC API — these are called by Academic.php controller methods.
    //  Same logic that used to live inline; only difference is it now
    //  takes parameters instead of $this->input->post().
    // ══════════════════════════════════════════════════════════════════

    public function getCurriculum(string $classSectionRaw, string $subjectRaw): array
    {
        if ($classSectionRaw === '' || $subjectRaw === '') {
            throw new Service_exception('Class and subject required', 'validation');
        }
        $classSection = $this->_safePathSegment($classSectionRaw);
        $subject      = $this->_safePathSegment($subjectRaw);

        $fsDocId = $this->_currDocId($classSection, $subject);
        $parent  = null;
        try { $parent = $this->firebase->firestoreGet(self::COLLECTION, $fsDocId); }
        catch (\Throwable $e) { log_message('error', 'getCurriculum read failed: ' . $e->getMessage()); }

        $topics      = $this->_loadTopics($fsDocId, $parent);
        $version     = is_array($parent) ? (int)($parent['version'] ?? 0) : 0;
        $topicsModel = is_array($parent) ? (string)($parent['topicsModel'] ?? 'array') : 'array';

        if (is_array($parent) && isset($parent['totalTopics'])) {
            $counters = [
                'totalTopics'     => (int)  ($parent['totalTopics']     ?? 0),
                'completedTopics' => (int)  ($parent['completedTopics'] ?? 0),
                'percentComplete' => (float)($parent['percentComplete'] ?? 0),
            ];
        } else {
            $counters = $this->_computeCounters($topics);
        }

        return [
            'topics'          => $topics,
            'class_section'   => $classSection,
            'subject'         => $subject,
            'version'         => $version,
            'topicsModel'     => $topicsModel,
            'totalTopics'     => $counters['totalTopics'],
            'completedTopics' => $counters['completedTopics'],
            'percentComplete' => $counters['percentComplete'],
        ];
    }

    public function saveCurriculum(
        string $classSectionRaw, string $subjectRaw, $topicsRaw, $expectedVersion
    ): array {
        if ($classSectionRaw === '' || $subjectRaw === '') {
            throw new Service_exception('Class and subject required', 'validation');
        }
        if (!$this->_canEdit($classSectionRaw, $subjectRaw)) {
            log_message('error', "saveCurriculum DENIED — actor={$this->adminId} role={$this->adminRole} cs={$classSectionRaw} subject={$subjectRaw}");
            throw new Service_exception(
                "Not authorized to edit this subject's curriculum: no matching subject assignment.",
                'auth'
            );
        }

        $classSection = $this->_safePathSegment($classSectionRaw);
        $subject      = $this->_safePathSegment($subjectRaw);

        $topics = is_string($topicsRaw) ? json_decode($topicsRaw, true) : $topicsRaw;
        if (!is_array($topics)) $topics = [];

        $clean = [];
        foreach ($topics as $i => $t) {
            if (!is_array($t) || empty(trim($t['title'] ?? ''))) continue;
            $clean[] = [
                'title'          => trim($t['title']),
                'chapter'        => trim($t['chapter'] ?? ''),
                'est_periods'    => max(0, (int)($t['est_periods'] ?? 0)),
                'status'         => in_array($t['status'] ?? '', ['not_started','in_progress','completed'])
                                        ? $t['status'] : 'not_started',
                'completed_date' => ($t['status'] ?? '') === 'completed' ? ($t['completed_date'] ?? date('Y-m-d')) : '',
                'sort_order'     => $i,
            ];
        }

        $fsDocId  = $this->_currDocId($classSection, $subject);
        $existing = null;
        try { $existing = $this->firebase->firestoreGet(self::COLLECTION, $fsDocId); }
        catch (\Throwable $e) {}

        $currVersion = is_array($existing) ? (int)($existing['version'] ?? 0) : 0;
        if ($expectedVersion !== null && $expectedVersion !== '') {
            if ((int)$expectedVersion !== $currVersion) {
                throw new Service_exception(
                    "Conflict: this curriculum was modified by another user (server v{$currVersion}, you had v{$expectedVersion}). Reload and retry.",
                    'conflict'
                );
            }
        } else {
            log_message('error', "saveCurriculum without expected_version (race possible) — actor={$this->adminId} cs={$classSectionRaw} subject={$subjectRaw}");
        }

        $newVersion = $currVersion + 1;
        $now        = date('c');
        $mode       = is_array($existing) ? (string)($existing['topicsModel'] ?? 'array') : 'subcollection';

        if ($mode === 'subcollection') {
            $existingTopicIds = (is_array($existing) && is_array($existing['topicIds'] ?? null))
                ? array_values($existing['topicIds']) : [];
            $topicColl = $this->_subcollPath($fsDocId);

            $newTopicIds = [];
            $ops = [];

            foreach ($clean as $i => $t) {
                $isExisting = isset($existingTopicIds[$i]);
                $tid = $isExisting ? $existingTopicIds[$i] : $this->_uuid();
                $newTopicIds[] = $tid;

                $topicData = [
                    'topicId'       => $tid,
                    'parentDocId'   => $fsDocId,
                    'schoolId'      => $this->schoolId,
                    'title'         => $t['title'],
                    'chapter'       => $t['chapter'],
                    'estPeriods'    => (int)$t['est_periods'],
                    'status'        => $t['status'],
                    'completedDate' => $t['completed_date'],
                    'sortOrder'     => $i,
                    'updatedAt'     => $now,
                    'updatedByUid'  => $this->adminId,
                    'updatedByName' => $this->adminName,
                ];
                if (!$isExisting) {
                    $topicData['createdAt']     = $now;
                    $topicData['createdByUid']  = $this->adminId;
                    $topicData['createdByName'] = $this->adminName;
                    $topicData['version']       = 1;
                }
                $ops[] = ['op' => 'set', 'collection' => $topicColl, 'docId' => $tid, 'merge' => true, 'data' => $topicData];
            }

            for ($i = count($clean); $i < count($existingTopicIds); $i++) {
                $ops[] = ['op' => 'delete', 'collection' => $topicColl, 'docId' => $existingTopicIds[$i], 'data' => []];
            }

            $newCounters = $this->_computeCounters($clean);
            $ops[] = [
                'op' => 'set', 'collection' => self::COLLECTION, 'docId' => $fsDocId, 'merge' => true,
                'data' => [
                    'schoolId'        => $this->schoolId,
                    'session'         => $this->session,
                    'classSection'    => $classSection,
                    'subject'         => $subject,
                    'topicsModel'     => 'subcollection',
                    'topicIds'        => $newTopicIds,
                    'totalTopics'     => $newCounters['totalTopics'],
                    'completedTopics' => $newCounters['completedTopics'],
                    'percentComplete' => $newCounters['percentComplete'],
                    'updatedAt'       => $now,
                    'updatedByUid'    => $this->adminId,
                    'updatedByName'   => $this->adminName,
                    'version'         => $newVersion,
                ],
            ];

            $ok = false;
            try { $ok = $this->firebase->firestoreCommitBatch($ops); }
            catch (\Throwable $e) { log_message('error', 'saveCurriculum batch failed: ' . $e->getMessage()); }
            if (!$ok) {
                throw new Service_exception('Failed to save curriculum (batch commit failed). No changes applied.', 'internal');
            }

            $resp = [];
            foreach ($newTopicIds as $i => $tid) {
                $resp[] = $this->_topicToLegacy($clean[$i], $tid);
            }

            $this->_audit(
                is_array($existing) ? 'update' : 'create', 'curriculum', $fsDocId,
                is_array($existing) ? [
                    'version'         => (int)($existing['version']         ?? 0),
                    'totalTopics'     => (int)($existing['totalTopics']     ?? 0),
                    'completedTopics' => (int)($existing['completedTopics'] ?? 0),
                ] : null,
                [
                    'version'         => $newVersion,
                    'totalTopics'     => $newCounters['totalTopics'],
                    'completedTopics' => $newCounters['completedTopics'],
                ],
                ['classSection' => $classSection, 'subject' => $subject, 'mode' => 'subcollection']
            );

            return [
                'topics'          => $resp,
                'version'         => $newVersion,
                'topicsModel'     => 'subcollection',
                'totalTopics'     => $newCounters['totalTopics'],
                'completedTopics' => $newCounters['completedTopics'],
                'percentComplete' => $newCounters['percentComplete'],
            ];
        }

        // Legacy array path
        $this->firebase->firestoreSet(self::COLLECTION, $fsDocId, [
            'schoolId'      => $this->schoolId,
            'session'       => $this->session,
            'classSection'  => $classSection,
            'subject'       => $subject,
            'topics'        => $clean,
            'updatedAt'     => $now,
            'updatedByUid'  => $this->adminId,
            'updatedByName' => $this->adminName,
            'version'       => $newVersion,
        ]);
        $this->_audit(
            is_array($existing) ? 'update' : 'create', 'curriculum', $fsDocId,
            is_array($existing) ? [
                'version'      => (int)($existing['version'] ?? 0),
                'topics_count' => is_array($existing['topics'] ?? null) ? count($existing['topics']) : 0,
            ] : null,
            ['version' => $newVersion, 'topics_count' => count($clean)],
            ['classSection' => $classSection, 'subject' => $subject, 'mode' => 'array']
        );
        return ['topics' => $clean, 'version' => $newVersion];
    }

    public function updateTopicStatus(
        string $classSectionRaw, string $subjectRaw,
        $topicIdInput, $indexInput, string $status, $expectedVersion
    ): array {
        $index = (int)($indexInput ?? -1);
        if ($classSectionRaw === '' || $subjectRaw === '' || $index < 0) {
            throw new Service_exception('Invalid parameters', 'validation');
        }
        if (!in_array($status, ['not_started','in_progress','completed'])) {
            throw new Service_exception('Invalid status', 'validation');
        }
        if (!$this->_canEdit($classSectionRaw, $subjectRaw)) {
            log_message('error', "updateTopicStatus DENIED — actor={$this->adminId} cs={$classSectionRaw} subject={$subjectRaw}");
            throw new Service_exception(
                "Not authorized to edit this subject's curriculum: no matching subject assignment.",
                'auth'
            );
        }

        $classSection = $this->_safePathSegment($classSectionRaw);
        $subject      = $this->_safePathSegment($subjectRaw);

        $fsDocId = $this->_currDocId($classSection, $subject);
        $parent  = null;
        try { $parent = $this->firebase->firestoreGet(self::COLLECTION, $fsDocId); }
        catch (\Throwable $e) { log_message('error', 'updateTopicStatus parent read failed: ' . $e->getMessage()); }
        if (!is_array($parent)) {
            throw new Service_exception('Curriculum not found for this class+subject', 'not_found');
        }
        $currVersion = (int)($parent['version'] ?? 0);

        if ($expectedVersion !== null && $expectedVersion !== '') {
            if ((int)$expectedVersion !== $currVersion) {
                throw new Service_exception(
                    "Conflict: curriculum was modified (server v{$currVersion}, you had v{$expectedVersion}). Reload and retry.",
                    'conflict'
                );
            }
        } else {
            log_message('error', "updateTopicStatus without expected_version (race possible) — actor={$this->adminId} cs={$classSectionRaw} subject={$subjectRaw}");
        }

        $newVersion = $currVersion + 1;
        $now        = date('c');
        $mode       = (string)($parent['topicsModel'] ?? 'array');

        if ($mode === 'subcollection') {
            $topicId = trim((string)($topicIdInput ?? ''));
            if ($topicId === '') {
                $topicIds = is_array($parent['topicIds'] ?? null) ? array_values($parent['topicIds']) : [];
                $topicId  = (string)($topicIds[$index] ?? '');
            }
            if ($topicId === '') {
                throw new Service_exception('Topic not found (could not resolve topicId)', 'not_found');
            }
            $topicColl = $this->_subcollPath($fsDocId);
            $topicDoc  = null;
            try { $topicDoc = $this->firebase->firestoreGet($topicColl, $topicId); }
            catch (\Throwable $e) { log_message('error', 'updateTopicStatus topic read failed: ' . $e->getMessage()); }
            if (!is_array($topicDoc)) {
                throw new Service_exception('Topic not found in subcollection', 'not_found');
            }
            $oldStatus = (string)($topicDoc['status'] ?? 'not_started');

            $completedDelta = 0;
            if ($oldStatus !== 'completed' && $status === 'completed')      $completedDelta = 1;
            elseif ($oldStatus === 'completed' && $status !== 'completed')  $completedDelta = -1;

            $newCompleted = max(0, (int)($parent['completedTopics'] ?? 0) + $completedDelta);
            $newTotal     = (int)($parent['totalTopics']     ?? 0);
            $newPct       = $newTotal > 0 ? round(($newCompleted / $newTotal) * 1000) / 10 : 0;
            $newCompletedDate = ($status === 'completed') ? date('Y-m-d') : '';
            $topicVer = (int)($topicDoc['version'] ?? 0) + 1;

            $ops = [
                [
                    'op' => 'set', 'collection' => $topicColl, 'docId' => $topicId, 'merge' => true,
                    'data' => [
                        'status'        => $status,
                        'completedDate' => $newCompletedDate,
                        'updatedAt'     => $now,
                        'updatedByUid'  => $this->adminId,
                        'updatedByName' => $this->adminName,
                        'version'       => $topicVer,
                    ],
                ],
                [
                    'op' => 'set', 'collection' => self::COLLECTION, 'docId' => $fsDocId, 'merge' => true,
                    'data' => [
                        'completedTopics' => $newCompleted,
                        'percentComplete' => $newPct,
                        'updatedAt'       => $now,
                        'updatedByUid'    => $this->adminId,
                        'updatedByName'   => $this->adminName,
                        'version'         => $newVersion,
                    ],
                ],
            ];
            $ok = false;
            try { $ok = $this->firebase->firestoreCommitBatch($ops); }
            catch (\Throwable $e) { log_message('error', 'updateTopicStatus batch failed: ' . $e->getMessage()); }
            if (!$ok) {
                throw new Service_exception('Failed to update topic status (batch commit failed)', 'internal');
            }

            $this->_audit(
                'status_change', 'curriculumTopic', $topicId,
                ['status' => $oldStatus],
                ['status' => $status, 'completedDate' => $newCompletedDate],
                ['parentDocId' => $fsDocId, 'classSection' => $classSection, 'subject' => $subject]
            );

            return [
                'index'           => $index,
                'topicId'         => $topicId,
                'newStatus'       => $status,
                'version'         => $newVersion,
                'completedTopics' => $newCompleted,
                'percentComplete' => $newPct,
            ];
        }

        // Legacy array path
        $topics = is_array($parent['topics'] ?? null) ? array_values($parent['topics']) : [];
        if (!isset($topics[$index])) {
            throw new Service_exception('Topic not found', 'not_found');
        }
        $oldLegacyStatus = (string)($topics[$index]['status'] ?? '');
        $topics[$index]['status']         = $status;
        $topics[$index]['completed_date'] = ($status === 'completed') ? date('Y-m-d') : '';

        $this->firebase->firestoreSet(self::COLLECTION, $fsDocId, [
            'schoolId'      => $this->schoolId,
            'session'       => $this->session,
            'classSection'  => $classSection,
            'subject'       => $subject,
            'topics'        => $topics,
            'updatedAt'     => $now,
            'updatedByUid'  => $this->adminId,
            'updatedByName' => $this->adminName,
            'version'       => $newVersion,
        ]);

        $this->_audit(
            'status_change', 'curriculumTopic', "{$fsDocId}#index{$index}",
            ['status' => $oldLegacyStatus],
            ['status' => $status],
            ['parentDocId' => $fsDocId, 'index' => $index, 'classSection' => $classSection, 'subject' => $subject, 'mode' => 'array']
        );
        return ['index' => $index, 'newStatus' => $status, 'version' => $newVersion];
    }

    public function deleteTopic(
        string $classSectionRaw, string $subjectRaw,
        $topicIdInput, $indexInput, $expectedVersion
    ): array {
        $index = (int)($indexInput ?? -1);
        if ($classSectionRaw === '' || $subjectRaw === '' || $index < 0) {
            throw new Service_exception('Invalid parameters', 'validation');
        }
        if (!$this->_canEdit($classSectionRaw, $subjectRaw)) {
            log_message('error', "deleteTopic DENIED — actor={$this->adminId} cs={$classSectionRaw} subject={$subjectRaw}");
            throw new Service_exception(
                "Not authorized to edit this subject's curriculum: no matching subject assignment.",
                'auth'
            );
        }

        $classSection = $this->_safePathSegment($classSectionRaw);
        $subject      = $this->_safePathSegment($subjectRaw);

        $fsDocId = $this->_currDocId($classSection, $subject);
        $parent  = null;
        try { $parent = $this->firebase->firestoreGet(self::COLLECTION, $fsDocId); }
        catch (\Throwable $e) { log_message('error', 'deleteTopic parent read failed: ' . $e->getMessage()); }
        if (!is_array($parent)) {
            throw new Service_exception('Curriculum not found for this class+subject', 'not_found');
        }
        $currVersion = (int)($parent['version'] ?? 0);

        if ($expectedVersion !== null && $expectedVersion !== '') {
            if ((int)$expectedVersion !== $currVersion) {
                throw new Service_exception(
                    "Conflict: curriculum was modified (server v{$currVersion}, you had v{$expectedVersion}). Reload and retry.",
                    'conflict'
                );
            }
        } else {
            log_message('error', "deleteTopic without expected_version (race possible) — actor={$this->adminId} cs={$classSectionRaw} subject={$subjectRaw}");
        }

        $newVersion = $currVersion + 1;
        $now        = date('c');
        $mode       = (string)($parent['topicsModel'] ?? 'array');

        if ($mode === 'subcollection') {
            $existingTopicIds = is_array($parent['topicIds'] ?? null) ? array_values($parent['topicIds']) : [];
            $topicId = trim((string)($topicIdInput ?? ''));
            if ($topicId === '' && isset($existingTopicIds[$index])) {
                $topicId = (string)$existingTopicIds[$index];
            }
            if ($topicId === '') {
                throw new Service_exception('Topic not found (could not resolve topicId)', 'not_found');
            }
            $topicColl = $this->_subcollPath($fsDocId);
            $topicDoc  = null;
            try { $topicDoc = $this->firebase->firestoreGet($topicColl, $topicId); }
            catch (\Throwable $e) {}
            $wasCompleted = is_array($topicDoc) && (($topicDoc['status'] ?? '') === 'completed');

            $newTopicIds = array_values(array_filter(
                $existingTopicIds, fn($t) => $t !== $topicId
            ));
            $newTotal     = count($newTopicIds);
            $newCompleted = max(0, (int)($parent['completedTopics'] ?? 0) - ($wasCompleted ? 1 : 0));
            $newPct       = $newTotal > 0 ? round(($newCompleted / $newTotal) * 1000) / 10 : 0;

            $ops = [
                ['op' => 'delete', 'collection' => $topicColl, 'docId' => $topicId, 'data' => []],
                [
                    'op' => 'set', 'collection' => self::COLLECTION, 'docId' => $fsDocId, 'merge' => true,
                    'data' => [
                        'topicIds'        => $newTopicIds,
                        'totalTopics'     => $newTotal,
                        'completedTopics' => $newCompleted,
                        'percentComplete' => $newPct,
                        'updatedAt'       => $now,
                        'updatedByUid'    => $this->adminId,
                        'updatedByName'   => $this->adminName,
                        'version'         => $newVersion,
                    ],
                ],
            ];
            $ok = false;
            try { $ok = $this->firebase->firestoreCommitBatch($ops); }
            catch (\Throwable $e) { log_message('error', 'deleteTopic batch failed: ' . $e->getMessage()); }
            if (!$ok) {
                throw new Service_exception('Failed to delete topic (batch commit failed). No changes applied.', 'internal');
            }

            $mockParent = $parent;
            $mockParent['topicIds'] = $newTopicIds;
            $remaining = $this->_loadTopics($fsDocId, $mockParent);

            $this->_audit(
                'delete', 'curriculumTopic', $topicId,
                [
                    'status'    => (string)($topicDoc['status']    ?? ''),
                    'title'     => (string)($topicDoc['title']     ?? ''),
                    'sortOrder' => (int)   ($topicDoc['sortOrder'] ?? 0),
                ],
                null,
                ['parentDocId' => $fsDocId, 'classSection' => $classSection, 'subject' => $subject]
            );

            return [
                'topics'          => $remaining,
                'version'         => $newVersion,
                'topicsModel'     => 'subcollection',
                'totalTopics'     => $newTotal,
                'completedTopics' => $newCompleted,
                'percentComplete' => $newPct,
            ];
        }

        // Legacy array path
        $topics = is_array($parent['topics'] ?? null) ? array_values($parent['topics']) : [];
        if (!isset($topics[$index])) {
            throw new Service_exception('Topic not found', 'not_found');
        }
        $deletedLegacy = $topics[$index];
        array_splice($topics, $index, 1);
        foreach ($topics as $i => &$t) { $t['sort_order'] = $i; }
        unset($t);

        $this->firebase->firestoreSet(self::COLLECTION, $fsDocId, [
            'schoolId'      => $this->schoolId,
            'session'       => $this->session,
            'classSection'  => $classSection,
            'subject'       => $subject,
            'topics'        => $topics,
            'updatedAt'     => $now,
            'updatedByUid'  => $this->adminId,
            'updatedByName' => $this->adminName,
            'version'       => $newVersion,
        ]);
        $this->_audit(
            'delete', 'curriculumTopic', "{$fsDocId}#index{$index}",
            ['title' => (string)($deletedLegacy['title'] ?? ''), 'status' => (string)($deletedLegacy['status'] ?? '')],
            null,
            ['parentDocId' => $fsDocId, 'index' => $index, 'mode' => 'array']
        );
        return ['topics' => $topics, 'version' => $newVersion];
    }

    // ══════════════════════════════════════════════════════════════════
    //  PRIVATE HELPERS — ported verbatim from Academic.php (Phase 0/1).
    // ══════════════════════════════════════════════════════════════════

    private function _currDocId(string $classSection, string $subject): string
    {
        $cs  = str_replace([' ', '/'], '_', $classSection);
        $sub = str_replace([' ', '/'], '_', $subject);
        return "{$this->schoolId}_{$this->session}_{$cs}_{$sub}";
    }

    private function _safePathSegment(string $v): string
    {
        // Mirrors Academic::safe_path_segment — strip path-unsafe chars and
        // collapse spaces/slashes to underscores. Length-bounded.
        $v = preg_replace('/[^\w\s\'\-\/.]/u', '', $v) ?? '';
        $v = trim($v);
        if (strlen($v) > 200) $v = substr($v, 0, 200);
        return $v;
    }

    private function _canEdit(string $classSectionRaw, string $subjectRaw): bool
    {
        $bypass = ['Super Admin','School Super Admin','Admin','Principal','Academic Coordinator'];
        if (in_array($this->adminRole, $bypass, true)) return true;

        $className = $classSectionRaw;
        $section   = '';
        $slash = strrpos($classSectionRaw, '/');
        if ($slash !== false) {
            $className = trim(substr($classSectionRaw, 0, $slash));
            $section   = trim(substr($classSectionRaw, $slash + 1));
        }

        try {
            $ci = function_exists('get_instance') ? get_instance() : null;
            if (!$ci) return false;
            $ci->load->library('subject_assignment_service', null, 'sas');
            if (!$ci->sas->isReady()) {
                $ci->sas->init($this->fs, $this->firebase, $this->schoolId, $this->schoolName, $this->session);
            }
            $assignments = $ci->sas->getAssignmentsForClass($className, $section);
        } catch (\Throwable $e) {
            log_message('error', 'curriculum _canEdit SAS lookup failed: ' . $e->getMessage());
            return false;
        }

        if ($this->adminId === '') return false;
        foreach ($assignments as $a) {
            if (($a['teacherId'] ?? '') !== $this->adminId) continue;
            $aName = $a['subjectName'] ?? '';
            $aCode = $a['subjectCode'] ?? '';
            if (strcasecmp($aName, $subjectRaw) === 0 || $aCode === $subjectRaw) {
                return true;
            }
        }
        return false;
    }

    private function _uuid(): string
    {
        if (function_exists('random_bytes')) {
            $b = random_bytes(16);
            $b[6] = chr((ord($b[6]) & 0x0f) | 0x40);
            $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);
            $h = bin2hex($b);
            return substr($h,0,8).'-'.substr($h,8,4).'-'.substr($h,12,4).'-'.substr($h,16,4).'-'.substr($h,20);
        }
        return 'topic_' . uniqid('', true);
    }

    private function _subcollPath(string $parentDocId): string
    {
        return self::COLLECTION . "/{$parentDocId}/topics";
    }

    private function _topicToLegacy(array $d, string $topicId = ''): array
    {
        return [
            'topicId'        => $topicId !== '' ? $topicId : ($d['topicId'] ?? ''),
            'title'          => $d['title']         ?? '',
            'chapter'        => $d['chapter']       ?? '',
            'est_periods'    => (int)  ($d['estPeriods']    ?? $d['est_periods']    ?? 0),
            'status'         => in_array(($d['status'] ?? ''), ['not_started','in_progress','completed'], true)
                                    ? $d['status'] : 'not_started',
            'completed_date' => (string)($d['completedDate'] ?? $d['completed_date'] ?? ''),
            'sort_order'     => (int)  ($d['sortOrder']     ?? $d['sort_order']     ?? 0),
        ];
    }

    private function _loadTopics(string $parentDocId, ?array $parent): array
    {
        if (!is_array($parent)) return [];
        $mode = $parent['topicsModel'] ?? '';

        if ($mode === 'subcollection' && is_array($parent['topicIds'] ?? null)) {
            $topicIds = array_values($parent['topicIds']);
            if (empty($topicIds)) return [];
            $coll = $this->_subcollPath($parentDocId);
            $reqs = [];
            foreach ($topicIds as $tid) {
                if (!is_string($tid) || $tid === '') continue;
                $reqs[$tid] = ['collection' => $coll, 'docId' => $tid];
            }
            $results = [];
            try {
                $rest = $this->firebase->getFirestoreDb();
                if ($rest && method_exists($rest, 'getDocumentsParallel')) {
                    $results = $rest->getDocumentsParallel($reqs);
                } else {
                    foreach ($reqs as $tag => $r) {
                        $results[$tag] = $this->firebase->firestoreGet($r['collection'], $r['docId']);
                    }
                }
            } catch (\Throwable $e) {
                log_message('error', '_loadTopics subcoll fetch failed: ' . $e->getMessage());
                return [];
            }
            $topics = [];
            foreach ($topicIds as $tid) {
                $d = $results[$tid] ?? null;
                if (!is_array($d)) continue;
                $topics[] = $this->_topicToLegacy($d, $tid);
            }
            return $topics;
        }

        if (is_array($parent['topics'] ?? null)) {
            $topics = [];
            foreach ($parent['topics'] as $i => $t) {
                if (!is_array($t)) continue;
                $t['sort_order'] = $t['sort_order'] ?? $i;
                $topics[] = $this->_topicToLegacy($t);
            }
            return $topics;
        }
        return [];
    }

    private function _computeCounters(array $topics): array
    {
        $total = count($topics);
        $completed = 0;
        foreach ($topics as $t) {
            if (($t['status'] ?? '') === 'completed') $completed++;
        }
        $pct = $total > 0 ? round(($completed / $total) * 1000) / 10 : 0;
        return ['totalTopics' => $total, 'completedTopics' => $completed, 'percentComplete' => $pct];
    }

    private function _audit(string $action, string $entityType, string $entityId, ?array $before, ?array $after, array $metadata): void
    {
        if (!$this->audit) return;
        try {
            $this->audit->log($action, $entityType, $entityId, $before, $after, $metadata);
        } catch (\Throwable $e) {
            log_message('error', 'curriculum audit failed: ' . $e->getMessage());
        }
    }
}
