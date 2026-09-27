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
    /**
     * Who may edit this subject's curriculum is decided from the subject
     * assignments. That service was reached through get_instance() — the CI
     * singleton — even though this class already holds the two dependencies
     * it needs to build one. A service that reaches into a global to find its
     * collaborator cannot be exercised outside a request, which is why the
     * authority gate here had never been run by a test.
     *
     * Injected when given; the singleton remains the fallback so every
     * existing caller keeps working untouched.
     */
    /** @var object|null */ private $sas = null;
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

    /**
     * Supply the subject-assignment service directly instead of letting this
     * class fetch one from the CI singleton.
     */
    public function withAssignmentService($sas): self
    {
        $this->sas = $sas;
        return $this;
    }

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
        // Everything below is derived from this document: the topic ids, the
        // counters, the version the next write will be checked against. Read
        // silently, a failure came back as null and the whole screen rendered
        // as "No topics yet" — a brand-new class and a broken read looked
        // exactly alike, and only one of them invites somebody to start typing
        // a syllabus that already exists.
        $parent = null;
        try {
            $parent = $this->firebase->firestoreGetOrFail(self::COLLECTION, $fsDocId);
        } catch (\Throwable $e) {
            log_message('error', 'getCurriculum read failed: ' . $e->getMessage());
            throw new Service_exception(
                'The curriculum for this class and subject could not be read. '
                . 'Try again in a moment.',
                'unavailable'
            );
        }

        $expected = 0; $loaded = 0;
        $topics   = $this->_loadTopics($fsDocId, $parent, $expected, $loaded);
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
            // A topic that failed to load is a lesson missing from the course
            // the teacher is looking at. Saying so is the difference between a
            // short syllabus and a syllabus that is short TODAY.
            'expectedTopics'  => $expected,
            'incomplete'      => ($loaded < $expected),
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
                // The id the screen was given for this topic. It is what makes
                // an edit an edit: without it the save matched topics by their
                // position in the list, so removing one shifted every later
                // topic's content into somebody else's document — and a lesson
                // plan written for that document is then attached to the wrong
                // lesson, with its snapshotted title still reading correctly.
                'topicId'        => trim((string)($t['topicId'] ?? $t['topic_id'] ?? '')),
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
            // ACAD-D17: this null decides CREATE vs UPDATE below. firestoreGet()
        // returns null for a FAILED read exactly as it does for a genuine 404,
        // so a transient failure made an existing record look new: version reset
        // to 1, createdAt/createdBy overwritten, and an audit entry written that
        // says 'create' for what was an update. Fail-loud: null now means ABSENT.
        try { $existing = $this->firebase->firestoreGetOrFail(self::COLLECTION, $fsDocId); }
        catch (\Throwable $e) {
            log_message('error', 'Curriculum_service: existing-curriculum read failed (doc='
                . $fsDocId . '): ' . $e->getMessage());
            throw new Service_exception(
                'Could not read the existing curriculum, so nothing was saved. '
                . 'Please try again in a moment.',
                'read'
            );
        }

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

            // Ids this curriculum has actually issued. A request may only
            // claim one of these: an id from anywhere else is not an edit of
            // anything, and must not be allowed to name a document of its own
            // choosing inside the subcollection.
            $knownIds = array_flip($existingTopicIds);
            $usedIds  = [];

            $newTopicIds = [];
            $ops = [];

            foreach ($clean as $i => $t) {
                $claimed = (string) ($t['topicId'] ?? '');
                $isExisting = ($claimed !== ''
                    && isset($knownIds[$claimed])
                    && !isset($usedIds[$claimed]));
                $tid = $isExisting ? $claimed : $this->_uuid();
                $usedIds[$tid] = true;
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

            // A topic the request does not mention is NOT a deletion. In this
            // product a topic is removed through delete_topic, which confirms
            // with the user and records what it took out; saving is only ever
            // used to add and to edit. So an omitted topic means the list was
            // built without it — which is exactly what happens when a topic
            // document fails to load, and what used to make the next save wipe
            // every topic that had not loaded, taking the lesson plans' only
            // link to them with it.
            $omitted = [];
            foreach ($existingTopicIds as $oldId) {
                if (!isset($usedIds[$oldId])) { $omitted[] = $oldId; }
            }
            if ($omitted) {
                $n = count($omitted);
                log_message('error', 'saveCurriculum omitted ' . $n . ' stored topic(s) '
                    . '— refused: ' . implode(',', $omitted));
                throw new Service_exception(
                    'This save left out ' . $n . ' topic' . ($n === 1 ? '' : 's') . ' that '
                    . 'the syllabus still has, so it was not applied. Reload the '
                    . 'curriculum and try again. To remove a topic, use its delete '
                    . 'button.',
                    'validation'
                );
            }

            $newCounters = $this->_computeCounters($clean);
            $parentOp = [
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

            $pre = $this->_parentPrecondition($existing, $fsDocId);
            if ($pre !== null) { $parentOp['precondition'] = $pre; }
            $ops[] = $parentOp;

            $this->_commitGuarded($ops, 'saveCurriculum');

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

        // Legacy array path. Topics live inline here and are addressed by
        // POSITION, so the id carried through the cleaning step means nothing
        // in this model — it is dropped rather than stored, to leave the shape
        // of these documents exactly as it was.
        $legacyTopics = [];
        foreach ($clean as $t) { unset($t['topicId']); $legacyTopics[] = $t; }

        $this->firebase->firestoreSet(self::COLLECTION, $fsDocId, [
            'schoolId'      => $this->schoolId,
            'session'       => $this->session,
            'classSection'  => $classSection,
            'subject'       => $subject,
            'topics'        => $legacyTopics,
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
        // What was stored, not what was submitted.
        return ['topics' => $legacyTopics, 'version' => $newVersion];
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
        // Fail-loud: the counters below are adjusted by delta from this
        // document, and read silently a failure came back as "Curriculum not
        // found for this class+subject" — which is a statement about the
        // school's syllabus, not about the read.
        $parent = null;
        try { $parent = $this->firebase->firestoreGetOrFail(self::COLLECTION, $fsDocId); }
        catch (\Throwable $e) {
            log_message('error', 'updateTopicStatus parent read failed: ' . $e->getMessage());
            throw new Service_exception(
                'The curriculum could not be read, so nothing was changed. '
                . 'Try again in a moment.', 'unavailable');
        }
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
            // The old status is what the completed-topics delta is computed
            // from, so this read has to be believed or refused — never guessed.
            $topicDoc = null;
            try { $topicDoc = $this->firebase->firestoreGetOrFail($topicColl, $topicId); }
            catch (\Throwable $e) {
                log_message('error', 'updateTopicStatus topic read failed: ' . $e->getMessage());
                throw new Service_exception(
                    'This topic could not be read, so its progress was not changed. '
                    . 'Try again in a moment.', 'unavailable');
            }
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
                array_filter([
                    'op' => 'set', 'collection' => self::COLLECTION, 'docId' => $fsDocId, 'merge' => true,
                    'data' => [
                        'completedTopics' => $newCompleted,
                        'percentComplete' => $newPct,
                        'updatedAt'       => $now,
                        'updatedByUid'    => $this->adminId,
                        'updatedByName'   => $this->adminName,
                        'version'         => $newVersion,
                    ],
                    'precondition' => $this->_parentPrecondition($parent, $fsDocId),
                ], fn($v) => $v !== null),
            ];
            $this->_commitGuarded($ops, 'updateTopicStatus');

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
        // Fail-loud, for the same reason as the progress path: this document
        // supplies the topic list, the counters and the guard token the write
        // is conditioned on. "Curriculum not found" is only true when it is.
        $parent = null;
        try { $parent = $this->firebase->firestoreGetOrFail(self::COLLECTION, $fsDocId); }
        catch (\Throwable $e) {
            log_message('error', 'deleteTopic parent read failed: ' . $e->getMessage());
            throw new Service_exception(
                'The curriculum could not be read, so nothing was deleted. '
                . 'Try again in a moment.', 'unavailable');
        }
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
            // Whether this topic was FINISHED decides the completed count that
            // gets written below. A silent read made a failure look like "not
            // finished", so deleting a finished topic left the count one too
            // high — permanently, and compounding with every further deletion.
            // null still means genuinely absent, which is a no-op deletion.
            $topicDoc = null;
            try { $topicDoc = $this->firebase->firestoreGetOrFail($topicColl, $topicId); }
            catch (\Throwable $e) {
                log_message('error', 'deleteTopic: topic read failed (' . $topicId
                    . '): ' . $e->getMessage());
                throw new Service_exception(
                    'This topic could not be read, so the syllabus progress could '
                    . 'not be adjusted and nothing was deleted. Please try again in '
                    . 'a moment.',
                    'read'
                );
            }
            $wasCompleted = is_array($topicDoc) && (($topicDoc['status'] ?? '') === 'completed');

            $newTopicIds = array_values(array_filter(
                $existingTopicIds, fn($t) => $t !== $topicId
            ));
            $newTotal     = count($newTopicIds);
            $newCompleted = max(0, (int)($parent['completedTopics'] ?? 0) - ($wasCompleted ? 1 : 0));
            $newPct       = $newTotal > 0 ? round(($newCompleted / $newTotal) * 1000) / 10 : 0;

            $ops = [
                ['op' => 'delete', 'collection' => $topicColl, 'docId' => $topicId, 'data' => []],
                array_filter([
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
                    'precondition' => $this->_parentPrecondition($parent, $fsDocId),
                ], fn($v) => $v !== null),
            ];
            $this->_commitGuarded($ops, 'deleteTopic');

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
            $sas = $this->sas;
            if ($sas === null) {
                $ci = function_exists('get_instance') ? get_instance() : null;
                if (!$ci) { return false; }
                $ci->load->library('subject_assignment_service', null, 'sas');
                if (!$ci->sas->isReady()) {
                    $ci->sas->init($this->fs, $this->firebase, $this->schoolId, $this->schoolName, $this->session);
                }
                $sas = $ci->sas;
            }
            $assignments = $sas->getAssignmentsForClass($className, $section);
        } catch (\Throwable $e) {
            // A lookup that FAILED is not a teacher without an assignment. Both
            // refuse — which is the safe direction — but the caller's message
            // says "no matching subject assignment", which is a statement about
            // this person's timetable and is simply untrue during an outage.
            log_message('error', 'curriculum _canEdit SAS lookup failed: ' . $e->getMessage());
            throw new Service_exception(
                'Your subject assignments could not be read, so it could not be '
                . 'checked whether you teach this subject. Nothing was changed — '
                . 'please try again in a moment.',
                'read'
            );
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

    /**
     * The topics of one curriculum.
     *
     * $expected and $loaded are reported back, because one topic document
     * failing to read used to drop that topic out of the list with nothing to
     * show for it: a shorter course than the one the teacher is meant to
     * teach, and no sign on the screen that anything was missing.
     */
    private function _loadTopics(string $parentDocId, ?array $parent,
                                 ?int &$expected = null, ?int &$loaded = null): array
    {
        $expected = 0; $loaded = 0;
        if (!is_array($parent)) return [];
        $mode = $parent['topicsModel'] ?? '';

        if ($mode === 'subcollection' && is_array($parent['topicIds'] ?? null)) {
            $topicIds = array_values($parent['topicIds']);
            $expected = count($topicIds);
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
                // $expected stays at the parent's own count, so the caller can
                // tell "none loaded" from "there are none".
                log_message('error', '_loadTopics subcoll fetch failed: ' . $e->getMessage());
                return [];
            }
            $topics = [];
            foreach ($topicIds as $tid) {
                $d = $results[$tid] ?? null;
                if (!is_array($d)) { continue; }   // counted by $loaded below
                $topics[] = $this->_topicToLegacy($d, $tid);
                $loaded++;
            }
            return $topics;
        }

        if (is_array($parent['topics'] ?? null)) {
            // The legacy model keeps its topics inside the parent, so if the
            // parent read at all, the list is whole.
            $topics = [];
            foreach ($parent['topics'] as $i => $t) {
                if (!is_array($t)) continue;
                $t['sort_order'] = $t['sort_order'] ?? $i;
                $topics[] = $this->_topicToLegacy($t);
            }
            $expected = count($topics);
            $loaded   = count($topics);
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

    /**
     * The condition under which this write is still the write we decided on.
     *
     * Comparing expected_version against the stored version is a check made
     * in PHP, and everything between that comparison and the commit is a
     * window another editor's save fits through: both requests read version
     * 1, both pass, both write version 2, and the second silently replaces
     * work the first person was just told had been saved. Carrying the
     * condition WITH the write closes the window — the server refuses a
     * batch built from a document that has since moved.
     *
     * A parent with no update token cannot be guarded this way. That should
     * not happen through the live client, so it is logged rather than
     * ignored, and the write proceeds as it always did.
     */
    private function _parentPrecondition(?array $parent, string $fsDocId): ?array
    {
        if (!is_array($parent)) { return ['exists' => false]; }
        $ut = (string) ($parent['__updateTime'] ?? '');
        if ($ut === '') {
            log_message('error', 'Curriculum_service: no __updateTime on ' . $fsDocId
                . ' — writing without a compare-and-set guard');
            return null;
        }
        return ['updateTime' => $ut];
    }

    /**
     * Commit a batch whose parent op carries the precondition above, turning
     * a server refusal into the same conflict the version check reports — the
     * person is asked to reload either way, and nothing was written either way.
     */
    private function _commitGuarded(array $ops, string $what): void
    {
        $res = null;
        try { $res = $this->firebase->firestoreCommitBatchStatus($ops); }
        catch (\Throwable $e) {
            log_message('error', $what . ' batch failed: ' . $e->getMessage());
            throw new Service_exception(
                'Failed to save the curriculum. No changes were applied.', 'internal');
        }
        $status = (string) ($res['status'] ?? '');
        if ($status === 'PRECONDITION_CONFLICT') {
            log_message('error', $what . ' refused: the curriculum moved between the '
                . 'read and the write (actor=' . $this->adminId . ')');
            throw new Service_exception(
                'Conflict: this curriculum was changed by another user while you were '
                . 'working on it, so nothing was changed. Reload and try again.',
                'conflict'
            );
        }
        if ($status !== 'SUCCESS') {
            log_message('error', $what . ' commit returned ' . $status . ': '
                . (string) ($res['message'] ?? ''));
            throw new Service_exception(
                'Failed to save the curriculum. No changes were applied.', 'internal');
        }
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
