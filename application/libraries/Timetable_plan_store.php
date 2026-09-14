<?php
defined('BASEPATH') OR exit('No direct script access allowed');

require_once APPPATH . 'libraries/Service_exception.php';
require_once APPPATH . 'libraries/Timetable_plan_service.php';
require_once APPPATH . 'libraries/Timetable_validation_service.php';
require_once APPPATH . 'libraries/Timetable_retention.php';
require_once APPPATH . 'libraries/Timetable_projection_service.php';
require_once APPPATH . 'libraries/Entity_firestore_sync.php';

/**
 * Timetable_plan_store — persistence for the authoritative timetable model.
 *
 * PHASE 2. Additive: two NEW collections, and not one byte of the live
 * `timetables` collection is read or written here. The Teacher app is
 * untouched. Deleting this file and its test reverts phase 2 entirely.
 *
 * Split deliberately from Timetable_plan_service, which stays PURE — its own
 * test greps it for any Firestore call and fails if one appears. Resolution
 * semantics are decided there and proven without a database; this file only
 * fetches rows and hands them over.
 *
 * ── THE PROPERTY THIS FILE EXISTS TO GUARANTEE ───────────────────────────
 *
 * A failed read must NEVER look like "this school has no timetable".
 *
 * That distinction is the whole reason this programme exists. `firestoreQuery()`
 * returns [] for a missing composite index, a 403, a 429, a network blip and an
 * uninitialised client alike — identical to a school that genuinely has nothing
 * scheduled. Resolution is the worst possible place for that ambiguity: the
 * caller would conclude no timetable applies today and act on it.
 *
 * So every read here is fail-loud, and resolveForDate() below distinguishes
 * three outcomes that the old architecture collapsed into one empty array:
 *
 *     resolved     a published version covers this date
 *     none         nothing is scheduled — and we PROVED it
 *     unreadable   we could not look; do not conclude anything
 *
 * ── SCOPE HELD BACK ──────────────────────────────────────────────────────
 *
 * publish() is deliberately absent. Whether a published version is immutable is
 * an owner decision (phase 4), and writing a publish path before that is
 * settled would bake in the answer. Only DRAFT records can be created here.
 */
class Timetable_plan_store
{
    /** @var object */ private $firebase;
    /** @var string */ private $schoolId  = '';
    /** @var string */ private $session   = '';
    /** @var string */ private $adminId   = '';
    /** @var object|null */ private $audit = null;
    /** @var bool   */ private $ready = false;

    const COLLECTION_PLANS    = 'timetablePlans';

    /**
     * The one plan id the compatibility contract permits. Named so a READ can
     * scope its queries without calling ensureRegularPlan(), which creates the
     * plan document as a side effect.
     */
    const PLAN_REGULAR = 'REGULAR';

    const COLLECTION_VERSIONS = 'timetableVersions';

    /**
     * PHASE 3 — the isolated candidate workspace.
     *
     * A day belonging to a VERSION, not to the school's live timetable. That
     * separation is the structural fix for ACAD-D26: generation writing here
     * cannot overwrite an administrator's live edit, because it never touches
     * the artifact the administrator is editing.
     *
     * The `periods` payload keeps the LIVE shape byte for byte
     * (periodNumber / subject / teacher / teacherId / startTime / endTime /
     * room / type) so the phase-5 projection is a re-parenting of documents
     * rather than a reshape — which is what lets the Teacher app keep working
     * without a coordinated release.
     */
    const COLLECTION_VERSION_DAYS = 'timetableVersionDays';

    /**
     * The LEGACY consumer collection. Not canonical, and not editable by
     * this layer: it is written only as a derived projection of a published
     * version, because the Teacher app reads it directly and cannot be
     * repointed without an app release.
     */
    const COLLECTION_LEGACY_TT = 'timetables';

    /** One doc per (class-section, day) within a single version. */
    const DAY_SCAN_LIMIT = 1000;

    /**
     * A school will hold a handful of versions per session, not hundreds — a
     * version is per PLAN, not per edit. The cap exists so saturation is
     * detectable at all; reaching it means the model is being used in a way
     * nobody designed for, and resolution must not quietly answer from a
     * truncated set.
     */
    const VERSION_SCAN_LIMIT = 500;

    public function init(
        $firebase,
        string $schoolId, string $session,
        string $adminId = '',
        $auditLogService = null
    ): self {
        $this->firebase = $firebase;
        $this->schoolId = $schoolId;
        $this->session  = $session;
        $this->adminId  = $adminId;
        $this->audit    = $auditLogService;
        $this->ready    = ($firebase !== null && $schoolId !== '' && $session !== '');
        return $this;
    }

    public function isReady(): bool { return $this->ready; }

    // ─────────────────────────────────────────────────────────────────────
    //  Reads
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Every version for this school + session.
     *
     * @throws Service_exception if the set cannot be read or proven complete
     */
    public function listVersions(): array
    {
        $this->assertReady();

        try {
            $rows = $this->firebase->firestoreQueryOrFail(self::COLLECTION_VERSIONS, [
                ['schoolId', '==', $this->schoolId],
                ['session',  '==', $this->session],
            ], null, 'ASC', self::VERSION_SCAN_LIMIT + 1);
        } catch (\Throwable $e) {
            log_message('error', 'Timetable_plan_store: version read failed (school='
                . $this->schoolId . ' session=' . $this->session . '): ' . $e->getMessage());
            throw new Service_exception(
                'The timetable versions for this session could not be read, so no '
                . 'timetable can be resolved right now. Please try again in a moment.',
                'read'
            );
        }

        // Saturation is "cannot prove", not "here is the list". Answering
        // resolution from a truncated set could silently return the wrong
        // timetable, or none at all.
        if (count($rows) > self::VERSION_SCAN_LIMIT) {
            log_message('error', 'Timetable_plan_store: version scan saturated at '
                . self::VERSION_SCAN_LIMIT . ' (school=' . $this->schoolId
                . ' session=' . $this->session . ')');
            throw new Service_exception(
                'This session has more timetable versions than can be checked in one '
                . 'pass, so no timetable can be resolved safely. Please contact support.',
                'read'
            );
        }

        $out = [];
        foreach ($rows as $r) {
            $d = is_array($r['data'] ?? null) ? $r['data'] : $r;
            if (!is_array($d)) continue;

            // Defence in depth. The query is already scoped, but a mis-scoped
            // row must never reach resolution — a timetable from another school
            // or session resolving as live is the worst outcome available here.
            if ((string) ($d['schoolId'] ?? '') !== $this->schoolId) continue;
            if ((string) ($d['session']  ?? '') !== $this->session)  continue;

            $out[] = $d;
        }
        return $out;
    }

    /**
     * Which timetable applies on $date, with the reason.
     *
     * Returns the pure resolver's outcome, plus an `explain` line. An
     * unreadable store is reported as its own status — never as "none".
     */
    public function resolveForDate(string $date): array
    {
        try {
            $versions = $this->listVersions();
        } catch (Service_exception $e) {
            // NOT 'none'. We did not prove the absence of a timetable; we failed
            // to look. Collapsing these two is the defect class this programme
            // was created to remove.
            return [
                'status'     => 'unreadable',
                'version'    => null,
                'candidates' => [],
                'reason'     => $e->getMessage(),
                'explain'    => "Timetable on {$date}: could not be determined — "
                                . $e->getMessage(),
            ];
        }

        $result = Timetable_plan_service::resolveForDate($versions, $date);
        $result['explain'] = Timetable_plan_service::explain($result, $date);
        return $result;
    }

    // ─────────────────────────────────────────────────────────────────────
    //  Writes — DRAFT ONLY
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Create a draft version. Never publishes: status is forced to draft
     * regardless of what the caller passes, because the publish lifecycle is a
     * phase-4 owner decision and must not be reachable by accident.
     *
     * @throws Service_exception on invalid input or a failed write
     */
    public function createDraftVersion(array $version): array
    {
        $this->assertReady();

        $version['schoolId'] = $this->schoolId;   // tenant is never caller-supplied
        $version['session']  = $this->session;
        $version['status']   = Timetable_plan_service::STATUS_DRAFT;

        $errors = Timetable_plan_service::validateVersion($version);
        if (!empty($errors)) {
            throw new Service_exception(implode('; ', $errors), 'validation');
        }

        $planId    = (string) $version['planId'];
        $versionId = (string) $version['versionId'];
        $docId     = "{$this->schoolId}_{$this->session}_{$planId}_{$versionId}";

        $version['createdAt']    = date('c');
        $version['createdByUid'] = $this->adminId;

        try {
            $ok = $this->firebase->firestoreSet(
                self::COLLECTION_VERSIONS, $docId, $version, false);
        } catch (\Throwable $e) {
            log_message('error', 'Timetable_plan_store: draft write failed (doc='
                . $docId . '): ' . $e->getMessage());
            throw new Service_exception(
                'The draft timetable could not be saved. Nothing was changed — '
                . 'please try again in a moment.',
                'write'
            );
        }

        // firestoreSet returns a bool and does not throw. An ignored false here
        // would report a draft that does not exist — the false-success shape
        // this codebase has been cleared of elsewhere.
        if ($ok === false) {
            log_message('error', 'Timetable_plan_store: draft write refused (doc=' . $docId . ')');
            throw new Service_exception(
                'The draft timetable could not be saved. Nothing was changed — '
                . 'please try again in a moment.',
                'write'
            );
        }

        $this->_audit('create', 'timetableVersion', $docId, null, [
            'planId' => $planId, 'versionId' => $versionId,
            'status' => Timetable_plan_service::STATUS_DRAFT,
            'effectiveFrom' => (string) ($version['effectiveFrom'] ?? ''),
            'effectiveTo'   => (string) ($version['effectiveTo']   ?? ''),
        ]);

        return ['docId' => $docId, 'version' => $version];
    }

    // ─────────────────────────────────────────────────────────────────────
    //  Phase 3 — candidate workspace
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Read one version, fail-loud.
     *
     * null means the version is genuinely ABSENT and nothing else. A failed
     * read throws, so no caller can mistake "I could not look" for "there is no
     * such version" and create a duplicate on top of a real one.
     */
    public function getVersion(string $planId, string $versionId): ?array
    {
        $this->assertReady();
        $docId = "{$this->schoolId}_{$this->session}_{$planId}_{$versionId}";

        try {
            $doc = $this->firebase->firestoreGetOrFail(self::COLLECTION_VERSIONS, $docId);
        } catch (\Throwable $e) {
            log_message('error', 'Timetable_plan_store: version read failed (doc='
                . $docId . '): ' . $e->getMessage());
            throw new Service_exception(
                'That timetable version could not be read. Nothing was changed — '
                . 'please try again in a moment.',
                'read'
            );
        }

        if (!is_array($doc)) return null;

        // Defence in depth: a version belonging to another tenant must never be
        // returned, even if a docId collision or a mis-scoped write occurred.
        if ((string) ($doc['schoolId'] ?? '') !== $this->schoolId) return null;
        if ((string) ($doc['session']  ?? '') !== $this->session)  return null;

        return $doc;
    }

    /**
     * Write one day into a candidate version.
     *
     * Refuses unless the version is still editable. A published version is never
     * mutated here: whether publishing is immutable is a phase-4 owner decision,
     * and refusing is the only behaviour that is correct under EITHER answer.
     *
     * A manual edit also invalidates the generator verdict. Untis built its
     * diagnosis tool for precisely this reason — discrepancies arise "in
     * particular after manual corrections". A score is only true for the
     * arrangement that produced it, so an edit clears it and marks validation
     * stale. That is not a policy choice: a stale score presented as current is
     * simply wrong.
     */
    public function saveCandidateDay(
        string $planId, string $versionId,
        string $classSection, string $day,
        array $periods, bool $isManualEdit = true,
        ?string $generationRunId = null,
        ?string $casUpdateTime = null
    ): array {
        $this->assertReady();

        $version = $this->getVersion($planId, $versionId);
        if ($version === null) {
            throw new Service_exception(
                'That timetable version does not exist, so nothing was saved.',
                'not_found'
            );
        }

        $status = (string) ($version['status'] ?? '');
        if ($status !== Timetable_plan_service::STATUS_DRAFT
            && $status !== Timetable_plan_service::STATUS_CANDIDATE) {
            throw new Service_exception(
                'This timetable has already been published, so it cannot be edited '
                . 'directly. Create a new version from it and edit that instead.',
                'immutable'
            );
        }

        $safe  = str_replace('/', '_', $classSection);
        $docId = "{$this->schoolId}_{$this->session}_{$planId}_{$versionId}_{$safe}_{$day}";

        $data = [
            'schoolId'       => $this->schoolId,
            'session'        => $this->session,
            'planId'         => $planId,
            'versionId'      => $versionId,
            'classSection'   => $classSection,
            'day'            => $day,
            'periods'        => $periods,
            'manuallyEdited' => $isManualEdit,
            // Which generation run produced this day. NULL for a hand edit.
            //
            // Needed under EVERY candidate-preservation policy, so recording it
            // decides nothing: after a failed or repeated run, days from different
            // runs otherwise sit side by side and cannot be told apart. That makes
            // the ACAD-D24 partial-generation state undiagnosable — the version
            // knows it is incomplete, but nothing knows WHICH days are suspect.
            //
            // It deliberately does NOT express whether a day survives the next
            // run. That is the open preservation decision and is not encoded here.
            'generationRunId' => $generationRunId,
            'updatedAt'      => date('c'),
            'updatedByUid'   => $this->adminId,
        ];

        // -- CONCURRENT EDITS: WHY THIS WRITE IS CONDITIONAL --------------
        //
        // A day document is written whole. Two administrators editing DIFFERENT
        // periods of the SAME day therefore race: each reads the day, replaces
        // its own cell, and writes the lot back -- so whichever saves second
        // silently reverts the other's change. Nobody is told, and the lost
        // period simply is not taught.
        //
        // Read-modify-write inside one request does not help, because the two
        // requests interleave. What closes it is a precondition: the caller
        // captures the document's server-assigned updateTime when it reads, and
        // this commit refuses if the stored document has moved since. It is the
        // same mechanism the retired savePeriod() used, brought to the path that
        // is actually reachable.
        //
        // Only the interactive editor passes a token. Generation does not: it
        // writes into a version created moments earlier that nothing else can be
        // editing, and a precondition there would refuse legitimate writes for a
        // race that cannot happen.
        try {
            if ($casUpdateTime !== null) {
                $commit = $this->firebase->firestoreCommitBatchStatus([[
                    'collection' => self::COLLECTION_VERSION_DAYS,
                    'docId'      => $docId,
                    'data'       => $data,
                    'merge'      => false,
                    // An existing day must not have moved; a day that did not
                    // exist must not have appeared, which is the same race
                    // between two people creating it at once.
                    'precondition' => $casUpdateTime !== ''
                        ? ['updateTime' => $casUpdateTime]
                        : ['exists' => false],
                ]]);
                $status = (string) ($commit['status'] ?? '');

                if ($status === 'PRECONDITION_CONFLICT') {
                    log_message('error', 'Timetable_plan_store: day CAS conflict (doc='
                        . $docId . ' admin=' . $this->adminId . ')');
                    throw new Service_exception(
                        'Someone else changed this day while you were editing it, so '
                        . 'your change was not saved -- saving it would have undone '
                        . 'theirs. Refresh to see the current timetable, then make '
                        . 'your change again.',
                        'conflict'
                    );
                }
                // Firestore_rest_client::COMMIT_SUCCESS is the string
                // 'SUCCESS'. This accepted 'OK', which no client returns — so
                // against the real client EVERY compare-and-set day save would
                // have reported "could not be saved" while the write had in
                // fact landed, and the editor would have told an administrator
                // to try again over content that was already stored.
                //
                // It survived because the test doubles returned 'OK' too, so
                // the double and the code agreed with each other and neither
                // agreed with Firestore. Found when the doubles were made to
                // return the real literal. Same check as saveLessonPlan().
                $ok = ($status === 'SUCCESS');
            } else {
                $ok = $this->firebase->firestoreSet(self::COLLECTION_VERSION_DAYS, $docId, $data, false);
            }
        } catch (Service_exception $e) {
            throw $e;              // a refused conflict is not a write failure
        } catch (\Throwable $e) {
            log_message('error', 'Timetable_plan_store: candidate day write failed (doc='
                . $docId . '): ' . $e->getMessage());
            throw new Service_exception(
                'That timetable day could not be saved. Nothing was changed — '
                . 'please try again in a moment.',
                'write'
            );
        }
        if ($ok === false) {
            log_message('error', 'Timetable_plan_store: candidate day write refused (doc=' . $docId . ')');
            throw new Service_exception(
                'That timetable day could not be saved. Nothing was changed — '
                . 'please try again in a moment.',
                'write'
            );
        }

        if ($isManualEdit) $this->invalidateVerdict($planId, $versionId);

        return ['docId' => $docId, 'day' => $data];
    }

    /**
     * Clear a generator verdict that a manual edit has just made untrue.
     *
     * Best-effort by design: the day itself is already stored safely, and
     * failing the whole edit because a flag could not be written would be worse
     * for the operator. But the failure is LOGGED loudly, because a version
     * whose score outlived its content is exactly the stale-verdict trap this
     * exists to prevent.
     */
    private function invalidateVerdict(string $planId, string $versionId, string $reason = ''): void
    {
        $docId = "{$this->schoolId}_{$this->session}_{$planId}_{$versionId}";
        try {
            $this->firebase->firestoreSet(self::COLLECTION_VERSIONS, $docId, [
                'validationStale' => true,
                // The MEASUREMENTS go stale for the same reason the verdict does:
                // curriculum shortfall, teaching load and distance from the other
                // candidates all describe the timetable as it was before this edit.
                // Comparing a school's options on numbers that describe a timetable
                // which no longer exists is worse than showing nothing.
                //
                // A TOP-LEVEL flag, deliberately: a merge write carrying
                // ['facts' => ['stale' => true]] would replace the whole facts map
                // and destroy the measurements it was meant to annotate.
                'factsStale'      => true,
                'score'           => null,
                'scoreStaleAt'    => date('c'),
                // Explainability: "needs another check" is far less useful to an
                // administrator than "needs another check BECAUSE the subject
                // assignments for Class 8 changed".
                'staleReason'     => $reason !== '' ? $reason : 'This timetable was edited by hand.',
            ], true);
        } catch (\Throwable $e) {
            log_message('error', 'Timetable_plan_store: could not mark verdict stale after '
                . 'a manual edit (doc=' . $docId . '): ' . $e->getMessage()
                . ' — this version may still advertise a score that no longer describes it.');
        }
    }

    /**
     * Every day of one candidate version. Fail-loud and saturation-checked for
     * the same reason as listVersions(): a partial candidate must never be
     * validated, scored or projected as though it were whole.
     */
    public function listCandidateDays(string $planId, string $versionId): array
    {
        $this->assertReady();

        try {
            $rows = $this->firebase->firestoreQueryOrFail(self::COLLECTION_VERSION_DAYS, [
                ['schoolId',  '==', $this->schoolId],
                ['session',   '==', $this->session],
                ['planId',    '==', $planId],
                ['versionId', '==', $versionId],
            ], null, 'ASC', self::DAY_SCAN_LIMIT + 1);
        } catch (\Throwable $e) {
            log_message('error', 'Timetable_plan_store: candidate day read failed (plan='
                . $planId . ' version=' . $versionId . '): ' . $e->getMessage());
            throw new Service_exception(
                'This timetable candidate could not be read, so it cannot be checked '
                . 'or compared. Please try again in a moment.',
                'read'
            );
        }

        if (count($rows) > self::DAY_SCAN_LIMIT) {
            log_message('error', 'Timetable_plan_store: candidate day scan saturated at '
                . self::DAY_SCAN_LIMIT . ' (plan=' . $planId . ' version=' . $versionId . ')');
            throw new Service_exception(
                'This timetable candidate is larger than can be checked in one pass, so '
                . 'it cannot be validated safely. Please contact support.',
                'read'
            );
        }

        $out = [];
        foreach ($rows as $r) {
            $d = is_array($r['data'] ?? null) ? $r['data'] : $r;
            if (!is_array($d)) continue;
            if ((string) ($d['schoolId'] ?? '') !== $this->schoolId) continue;
            if ((string) ($d['session']  ?? '') !== $this->session)  continue;
            $out[] = $d;
        }
        return $out;
    }

    /**
     * The settings collection. Configuration, not timetable content — reading it
     * does not make anything a second source of truth.
     */
    const COLLECTION_SETTINGS = 'timetableSettings';

    /**
     * Edit ONE period inside a draft or candidate version.
     *
     * ── WHY A SERVER-SIDE READ-MODIFY-WRITE ─────────────────────────────
     *
     * saveCandidateDay() replaces a whole day document. If the browser sent the
     * whole day, two administrators editing different periods of the same day
     * would silently overwrite each other, and the loser would never know. So the
     * day is re-read HERE and only the addressed cell is replaced: the window
     * between read and write is the few milliseconds inside one request, which is
     * the same shape the legacy editor settled on and not a human-scale risk.
     *
     * ── WHAT IS REPRODUCED FROM THE LEGACY EDITOR, AND WHAT IS NOT ──────
     *
     * REPRODUCED, because they are user-facing correctness:
     *   - the other periods of the day survive the edit (ACAD-D6/D19). The read
     *     is fail-loud; if we cannot see what is stored we must not overwrite it.
     *   - the day is sized from configured periods-per-day and REFUSES when that
     *     is unset (ACAD-D7), rather than defaulting to a literal and silently
     *     truncating a school that runs more periods than the default.
     *   - the edited cell keeps its existing start and end times.
     *
     * NOT reproduced, because it belongs to the legacy direct-write architecture:
     *   - the teacher double-booking scan across the LIVE `timetables`
     *     collection. A candidate is a self-contained timetable, and it may
     *     legitimately move a teacher that the live timetable has elsewhere;
     *     checking a candidate against live data would refuse correct edits and
     *     accept wrong ones. Clashes inside the candidate are already detected by
     *     Timetable_validation_service (H_TEACHER_DOUBLE_BOOKED, hard) — which is
     *     mutation-tested and is the single authority on what is wrong with a
     *     timetable. A second conflict engine here would be a second place where
     *     "is this timetable valid" is decided.
     *   - the live document's version counter and CAS token, which describe a
     *     collection this layer never writes.
     *
     * Because validation is that authority, this edit marks the verdict STALE
     * (saveCandidateDay does it) and publishing refuses an unchecked or
     * hard-failing candidate. The clash is caught before anyone sees it, at the
     * step whose job that is.
     *
     * @param array $cell subject, teacherId, teacher, room, type
     * @throws Service_exception if the version is not editable, unreadable, or
     *                           periods-per-day is not configured
     */
    public function saveCandidatePeriod(
        string $planId, string $versionId, string $classSection,
        string $day, int $periodIndex, array $cell
    ): array {
        $this->assertReady();

        if ($classSection === '' || $day === '' || $periodIndex < 0) {
            throw new Service_exception(
                'The class, day and period are all required.', 'validation');
        }

        return $this->saveCandidateDayCells(
            $planId, $versionId, $classSection, $day, [$periodIndex => $cell]);
    }

    /**
     * Edit ONE OR MORE periods of a day in a single request.
     *
     * ── WHY THIS IS NOT THE LEGACY BULK SAVE ────────────────────────────
     *
     * The legacy saveSectionTimetable() took a whole week from the browser and
     * wrote it over whatever was stored. That is ACAD-D21: the browser's copy
     * may be minutes old, so saving one changed cell silently reverted every
     * other change made meanwhile, and reported success.
     *
     * This takes only the cells the operator actually changed, addressed by
     * index. The day is re-read HERE and the addressed indices replaced; every
     * period the operator did not touch is preserved from the SERVER's current
     * copy, never from the browser's. Editing more cells at once therefore adds
     * no overwrite risk at all — a bulk save and a single-cell save carry
     * exactly the same guarantee, which is the point.
     *
     * @param array $cells periodIndex => [subject, teacherId, teacher, room, type]
     * @throws Service_exception if the version is not editable, unreadable, or
     *                           periods-per-day is not configured
     */
    public function saveCandidateDayCells(
        string $planId, string $versionId, string $classSection,
        string $day, array $cells
    ): array {
        $this->assertReady();

        if ($classSection === '' || $day === '') {
            throw new Service_exception(
                'The class, day and period are all required.', 'validation');
        }
        if (empty($cells)) {
            throw new Service_exception('No changes were supplied.', 'validation');
        }

        $maxIndex = 0;
        foreach ($cells as $i => $c) {
            if (!is_int($i) && !ctype_digit((string) $i)) {
                throw new Service_exception(
                    'The class, day and period are all required.', 'validation');
            }
            $i = (int) $i;
            if ($i < 0) {
                throw new Service_exception(
                    'The class, day and period are all required.', 'validation');
            }
            if (!is_array($c)) {
                throw new Service_exception(
                    'One of the periods could not be read.', 'validation');
            }
            if ($i > $maxIndex) { $maxIndex = $i; }
        }

        // Editability is checked BEFORE anything is read, so a published version
        // is refused without a pointless round trip.
        $this->requireEditableVersion($planId, $versionId);

        // How long is a day here? Refuse rather than guess: a literal fallback
        // would truncate a school running more periods than the default.
        $periodsPerDay = $this->_periodsPerDay();

        // The token this day carried when we read it. The write refuses if it
        // has moved since, which is what stops a second administrator's save
        // from quietly reverting a first.
        $safeDoc  = str_replace('/', '_', $classSection);
        $dayDocId = "{$this->schoolId}_{$this->session}_{$planId}_{$versionId}_{$safeDoc}_{$day}";
        try {
            $snapshot = $this->firebase->firestoreGetOrFail(
                self::COLLECTION_VERSION_DAYS, $dayDocId);
            $casUpdateTime = is_array($snapshot)
                ? (string) ($snapshot['__updateTime'] ?? '') : '';
        } catch (\Throwable $e) {
            // Fail loud: without the token the write cannot be conditional, and
            // an unconditional one is the silent overwrite this prevents.
            log_message('error', 'Timetable_plan_store: could not read the day for a '
                . 'conditional save (doc=' . $dayDocId . '): ' . $e->getMessage());
            throw new Service_exception(
                'That timetable day could not be read, so it was not saved. Nothing '
                . 'was changed — please try again in a moment.',
                'read'
            );
        }

        // What is already stored for this day. Fail-loud: this read decides what
        // is PRESERVED, and overwriting content we could not read is the data
        // loss ACAD-D6 was raised for.
        $existing = [];
        foreach ($this->listCandidateDays($planId, $versionId) as $d) {
            if (!is_array($d)) continue;
            if ((string) ($d['classSection'] ?? '') !== $classSection) continue;
            if ((string) ($d['day'] ?? '') !== $day) continue;
            foreach (is_array($d['periods'] ?? null) ? $d['periods'] : [] as $i => $p) {
                if (!is_array($p)) continue;
                $existing[((int) ($p['periodNumber'] ?? ($i + 1))) - 1] = $p;
            }
            break;
        }

        // Rebuild the whole day, changing ONLY the addressed cells.
        $byIndex = [];
        foreach ($cells as $i => $c) { $byIndex[(int) $i] = $c; }

        $periods = [];
        $len = max($periodsPerDay, $maxIndex + 1);
        for ($p = 0; $p < $len; $p++) {
            if (array_key_exists($p, $byIndex)) {
                $cell = $byIndex[$p];
                $periods[] = [
                    'periodNumber' => $p + 1,
                    'subject'      => trim((string) ($cell['subject'] ?? '')),
                    'teacher'      => trim((string) ($cell['teacher'] ?? '')),
                    'teacherId'    => trim((string) ($cell['teacherId'] ?? '')),
                    // Times belong to the slot, not the lesson, so an edit keeps them.
                    'startTime'    => (string) ($existing[$p]['startTime'] ?? ''),
                    'endTime'      => (string) ($existing[$p]['endTime']   ?? ''),
                    'room'         => trim((string) ($cell['room'] ?? '')),
                    'type'         => trim((string) ($cell['type'] ?? '')) ?: 'class',
                ];
            } elseif (isset($existing[$p])) {
                $periods[] = $existing[$p];
            } else {
                $periods[] = [
                    'periodNumber' => $p + 1, 'subject' => '', 'teacher' => '',
                    'teacherId' => '', 'startTime' => '', 'endTime' => '',
                    'room' => '', 'type' => 'class',
                ];
            }
        }

        // isManualEdit = true: this is a human decision, and it makes the
        // recorded verdict stale.
        $saved = $this->saveCandidateDay(
            $planId, $versionId, $classSection, $day, $periods, true,
            null, $casUpdateTime);

        $saved['periodsChanged'] = array_values(array_map('intval', array_keys($byIndex)));
        return $saved;
    }

    /**
     * Configured periods per day, fail-loud.
     *
     * @throws Service_exception if unreadable or unconfigured
     */
    private function _periodsPerDay(): int
    {
        $docId = "{$this->schoolId}_{$this->session}";
        try {
            $settings = $this->firebase->firestoreGetOrFail(self::COLLECTION_SETTINGS, $docId);
        } catch (\Throwable $e) {
            log_message('error', 'Timetable_plan_store: settings read failed (doc='
                . $docId . '): ' . $e->getMessage());
            throw new Service_exception(
                'Could not read the period settings, so nothing was saved. Please '
                . 'try again in a moment.',
                'read'
            );
        }

        $settings = is_array($settings) ? $settings : [];
        $n = (int) ($settings['periodsPerDay'] ?? $settings['No_of_periods'] ?? 0);
        if ($n <= 0) {
            throw new Service_exception(
                'Period Scheduling is not configured, so the timetable could not be '
                . 'saved. Set periods per day first.',
                'validation'
            );
        }
        return $n;
    }

    /**
     * A version and its days, for editing.
     *
     * Deliberately NOT readCompleteCandidate(): that refuses anything without a
     * completed generation run, which is exactly what a hand-built draft looks
     * like. Editing must work on a draft nobody generated. What it does keep is
     * the fail-loud read — an unreadable day set must never render as an empty
     * timetable that the administrator then fills in over the top of real data.
     *
     * @throws Service_exception if the version or its days cannot be read
     */
    public function readVersionForEditing(string $planId, string $versionId): array
    {
        $this->assertReady();

        $version = $this->getVersion($planId, $versionId);
        if ($version === null) {
            throw new Service_exception(
                'That timetable version does not exist.', 'not_found');
        }

        $status = (string) ($version['status'] ?? '');

        return [
            'version'  => $version,
            'days'     => $this->listCandidateDays($planId, $versionId),
            // The screen must be able to say WHICH timetable is being edited.
            'editable' => ($status === Timetable_plan_service::STATUS_DRAFT
                        || $status === Timetable_plan_service::STATUS_CANDIDATE),
            'status'   => $status,
        ];
    }
    /**
     * The session's regular timetable plan, created on first use.
     *
     * ── WHY THERE IS EXACTLY ONE, AND WHY THAT IS NOT A PREFERENCE ──────
     *
     * The architecture anticipates several kinds of plan (regular, temporary,
     * exam), but while the legacy compatibility projection is how timetables
     * reach teachers, only ONE plan can be live at a time. That is forced, not
     * chosen: the legacy collection holds a single document per section per day
     * and cannot represent two concurrent timetables, and resolveForDate()
     * already reports two published versions covering one date as AMBIGUOUS —
     * which the projection cron refuses rather than guessing at.
     *
     * So a second live plan is undeliverable today no matter what anyone
     * decides. Additional plans become possible once the Teacher app reads the
     * canonical architecture directly (T3) and the projection is retired; until
     * then this returns the one plan the compatibility contract permits.
     *
     * Idempotent: the id is derived, so calling it repeatedly returns the same
     * plan and creates nothing after the first time.
     *
     * @throws Service_exception if the plan cannot be read or created
     */
    public function ensureRegularPlan(): array
    {
        $this->assertReady();

        $planId = self::PLAN_REGULAR;
        $docId  = "{$this->schoolId}_{$this->session}_{$planId}";

        try {
            $existing = $this->firebase->firestoreGetOrFail(self::COLLECTION_PLANS, $docId);
        } catch (\Throwable $e) {
            log_message('error', 'Timetable_plan_store: plan read failed (doc='
                . $docId . '): ' . $e->getMessage());
            throw new Service_exception(
                'The timetable could not be opened. Please try again in a moment.',
                'read'
            );
        }

        if (is_array($existing)) {
            // Tenant and session are re-checked on the document itself, never
            // inferred from the id alone.
            if ((string) ($existing['schoolId'] ?? '') === $this->schoolId
                && (string) ($existing['session'] ?? '') === $this->session) {
                return $existing;
            }
            log_message('error', 'Timetable_plan_store: plan doc ' . $docId
                . ' does not belong to this tenant/session');
            throw new Service_exception(
                'The timetable could not be opened. Please contact support.',
                'tenant_mismatch'
            );
        }

        $plan = [
            'schoolId'  => $this->schoolId,
            'session'   => $this->session,
            'planId'    => $planId,
            'kind'      => Timetable_plan_service::KIND_REGULAR,
            'name'      => 'Regular timetable',
            'createdAt' => date('c'),
            'createdByUid' => $this->adminId,
        ];

        try {
            $ok = $this->firebase->firestoreSet(self::COLLECTION_PLANS, $docId, $plan, false);
        } catch (\Throwable $e) {
            log_message('error', 'Timetable_plan_store: plan write failed (doc='
                . $docId . '): ' . $e->getMessage());
            $ok = false;
        }
        if ($ok === false) {
            throw new Service_exception(
                'The timetable could not be set up. Nothing was changed — please '
                . 'try again in a moment.',
                'write'
            );
        }

        $this->_audit('create', 'timetablePlan', $docId, null,
            ['planId' => $planId, 'kind' => Timetable_plan_service::KIND_REGULAR]);

        return $plan;
    }
    /**
     * OPTION B — subject assignments changed, so affected work needs re-checking.
     *
     * The approved policy, and the reason the legacy coupling could be retired:
     * a change to who teaches what must NEVER rewrite a timetable. It marks the
     * affected draft and candidate versions as needing another check, and stops.
     *
     *   published versions  — untouched, always. They are immutable, and a
     *                         timetable that was in force stays exactly what was
     *                         published. Their verdicts are not disturbed either.
     *   draft / candidate   — verdict marked stale, with the reason recorded, so
     *                         publishing refuses until someone re-checks.
     *   content             — never regenerated, never rewritten, never deleted.
     *
     * ── WHICH VERSIONS ARE "AFFECTED" ───────────────────────────────────
     *
     * Those that actually contain a day for the class (and section, when one is
     * given). That is read from the versions' own days rather than guessed, so a
     * candidate for a class nobody touched is not disturbed.
     *
     * ── AND WHY AN UNREADABLE CHECK MARKS STALE ANYWAY ──────────────────
     *
     * Everywhere else in this architecture an unreadable input means REFUSE.
     * Here the conservative direction is the opposite: marking a verdict stale
     * costs a re-check, while skipping one leaves a verdict looking fresh that
     * may no longer be true — and publishing trusts that flag. So a version we
     * could not inspect is marked stale, not skipped.
     *
     * @return array marked, checked, skippedPublished, unreadable, reason
     */
    /**
     * The school changed its Period Scheduling, so every unpublished proposal
     * now describes a week the school no longer runs.
     *
     * ── WHY THIS IS THE SAME DECISION AS OPTION B ───────────────────────
     *
     * When subject assignments change, the approved policy is to mark affected
     * proposals stale and never rewrite a published timetable. A change to
     * periods per day, working days, period length or break positions is the
     * same kind of event, one level up: it does not alter which teacher takes a
     * subject, it alters the SHAPE OF THE WEEK every proposal was built into.
     *
     * A proposal generated for six periods a day keeps a "no problems" verdict
     * after the school moves to eight. Publishing it would hand teachers a week
     * that does not match the school's own timetable, and nothing would have
     * warned anybody. So the verdict is invalidated: the timetable is untouched,
     * and the operator is told it needs looking at again.
     *
     * Unlike the assignment case this is not scoped to a class. A schedule
     * change affects every section at once, so every proposal is marked.
     *
     * Published and superseded versions are never touched. They record what was
     * in force under the old schedule, which stays true.
     *
     * @throws Service_exception if the versions cannot be read — claiming to
     *         have marked them all while unable to see them would be a lie.
     */
    public function markAllVersionsStaleForSchedule(string $reason = ''): array
    {
        $this->assertReady();

        if ($reason === '') {
            $reason = 'Period Scheduling changed, so this timetable was built for a '
                    . 'different week. Check it again before publishing.';
        }

        $versions = $this->listVersions();

        $out = ['marked' => [], 'checked' => 0, 'skippedPublished' => 0, 'reason' => $reason];

        foreach ($versions as $v) {
            if (!is_array($v)) { continue; }
            $out['checked']++;

            $status = (string) ($v['status'] ?? '');
            if ($status !== Timetable_plan_service::STATUS_DRAFT
                && $status !== Timetable_plan_service::STATUS_CANDIDATE) {
                $out['skippedPublished']++;
                continue;
            }

            $planId    = (string) ($v['planId'] ?? '');
            $versionId = (string) ($v['versionId'] ?? '');
            if ($planId === '' || $versionId === '') { continue; }

            $this->invalidateVerdict($planId, $versionId, $reason);
            $out['marked'][] = $versionId;
        }

        return $out;
    }
    public function markVersionsStaleForClass(
        string $className, string $sectionKey = '', string $reason = ''
    ): array {
        $this->assertReady();

        $canon      = Entity_firestore_sync::normalizeClassSection($className, $sectionKey);
        $wantClass  = (string) ($canon['className'] ?? '');
        $wantSection = $sectionKey !== '' ? (string) ($canon['section'] ?? '') : '';

        if ($reason === '') {
            $reason = 'The subject assignments for ' . ($wantClass ?: $className)
                . ($wantSection !== '' ? ' ' . $wantSection : '') . ' changed.';
        }

        $out = ['marked' => [], 'checked' => 0, 'skippedPublished' => 0,
                'unreadable' => [], 'reason' => $reason];

        // A failure here is a genuine failure: we cannot tell which versions
        // exist, so we cannot claim to have marked the right ones.
        $versions = $this->listVersions();

        foreach ($versions as $v) {
            if (!is_array($v)) continue;

            $status = (string) ($v['status'] ?? '');
            if ($status !== Timetable_plan_service::STATUS_DRAFT
                && $status !== Timetable_plan_service::STATUS_CANDIDATE) {
                // Published and superseded versions are immutable records of what
                // was decided. An assignment change does not reach back into them.
                $out['skippedPublished']++;
                continue;
            }

            $planId    = (string) ($v['planId'] ?? '');
            $versionId = (string) ($v['versionId'] ?? '');
            if ($planId === '' || $versionId === '') continue;

            $out['checked']++;

            try {
                $days = $this->listCandidateDays($planId, $versionId);
            } catch (\Throwable $e) {
                log_message('error', 'Timetable_plan_store: could not inspect version '
                    . $versionId . ' for assignment staleness: ' . $e->getMessage());
                $this->invalidateVerdict($planId, $versionId, $reason);
                $out['unreadable'][] = $versionId;
                $out['marked'][]     = $versionId;
                continue;
            }

            $touches = false;
            foreach ($days as $d) {
                if (!is_array($d)) continue;
                $parts = explode('/', (string) ($d['classSection'] ?? ''), 2);
                if (trim($parts[0] ?? '') !== $wantClass) continue;
                if ($wantSection !== '' && trim($parts[1] ?? '') !== $wantSection) continue;
                $touches = true;
                break;
            }

            if ($touches) {
                $this->invalidateVerdict($planId, $versionId, $reason);
                $out['marked'][] = $versionId;
            }
        }

        if (!empty($out['marked'])) {
            $this->_audit('invalidate', 'timetableVersion', '', null, [
                'reason' => $reason, 'versions' => $out['marked'],
                'className' => $wantClass, 'section' => $wantSection,
            ]);
        }

        return $out;
    }
    /** A name a school will recognise is short; a paragraph is not a name. */
    const LABEL_MAX = 60;

    /**
     * Give a timetable a name a school actually uses -- "Winter", "Exam weeks",
     * "Summer half-day".
     *
     * ── WHY THIS IS ALLOWED ON A PUBLISHED TIMETABLE ────────────────────
     *
     * Published timetable DATA is immutable: a correction is a new version,
     * never an edit in place. A name is not that data. It changes nothing a
     * teacher, a parent or attendance ever reads, and resolution is strictly by
     * effective date -- the label is never consulted to decide which timetable
     * is in force, so renaming one cannot change what is being taught.
     *
     * Naming the PUBLISHED timetable is in fact the whole point: a school wants
     * to see "Winter Timetable" against the one that is actually running.
     * Refusing here would leave the feature working only on drafts, which is
     * not what was asked for. The change is audited like any other.
     *
     * @throws Service_exception if the name is empty, too long, or unwritable
     */
    public function renameVersion(string $planId, string $versionId, string $label): array
    {
        $this->assertReady();

        $label = trim((string) preg_replace('~\s+~u', ' ', $label));
        if ($label === '') {
            throw new Service_exception('Please enter a name for this timetable.', 'validation');
        }
        if (mb_strlen($label) > self::LABEL_MAX) {
            throw new Service_exception(
                'That name is too long. Please keep it to ' . self::LABEL_MAX
                . ' characters or fewer.', 'validation');
        }

        $version = $this->getVersion($planId, $versionId);
        if ($version === null) {
            throw new Service_exception(
                'That timetable does not exist, so it was not renamed.', 'not_found');
        }

        $docId = "{$this->schoolId}_{$this->session}_{$planId}_{$versionId}";
        try {
            $this->firebase->firestoreSet(self::COLLECTION_VERSIONS, $docId, [
                'label'     => $label,
                'updatedAt' => date('c'),
            ], true);
        } catch (\Throwable $e) {
            log_message('error', 'Timetable_plan_store: rename failed (doc=' . $docId
                . '): ' . $e->getMessage());
            throw new Service_exception(
                'The name could not be saved. Please try again in a moment.', 'write');
        }

        $this->_audit('update', 'timetableVersion', $docId,
            ['label' => (string) ($version['label'] ?? '')], ['label' => $label]);

        return ['versionId' => $versionId, 'label' => $label];
    }
    /**
     * Record what a generated candidate is LIKE, alongside what is wrong with
     * it.
     *
     * The verdict (hard and soft violations) is written by validateCandidate().
     * This holds the measurements from Timetable_metrics — curriculum
     * shortfall, teaching load, free periods — plus how this candidate was
     * produced and how far it sits from the others in its batch.
     *
     * Deliberately a separate map from `verdict`: a verdict goes stale the
     * moment a day is edited, whereas these measurements simply become out of
     * date and are recomputed on demand. Storing them together would let a
     * stale-verdict rule silently discard measurements that were never part of
     * the publishing decision.
     *
     * Merge-write only. Nothing here can change a timetable, a status or a
     * publishing outcome.
     */
    public function recordCandidateFacts(string $planId, string $versionId, array $facts): void
    {
        $this->assertReady();

        $docId = "{$this->schoolId}_{$this->session}_{$planId}_{$versionId}";
        try {
            $this->firebase->firestoreSet(self::COLLECTION_VERSIONS, $docId, [
                'facts'     => $facts,
                'updatedAt' => date('c'),
            ], true);
        } catch (\Throwable $e) {
            // NOT fatal to the candidate. The timetable itself is written and
            // validated; losing its summary makes comparison poorer, not the
            // timetable wrong. It is logged so the gap is visible.
            log_message('error', 'Timetable_plan_store: could not record candidate facts for '
                . $docId . ': ' . $e->getMessage());
        }
    }
    /**
     * Give up on a generation run that never finished.
     *
     * ── THE STATE THIS EXISTS TO ESCAPE ─────────────────────────────────
     *
     * `running` is only ever left by the request that set it: it calls
     * completeGenerationRun() or failGenerationRun() before returning. If that
     * request dies — a fatal error, a worker recycle, an execution limit — the
     * version stays `running` for ever.
     *
     * Nothing then rescues it. It cannot be validated or published, because
     * readCompleteCandidate() refuses an unfinished run. It cannot be reclaimed,
     * because retention protects a run that is still being written. So it sits
     * in the version count permanently, and enough of them make timetable
     * resolution impossible for a school that has done nothing wrong.
     *
     * ── WHY THIS IS AN OPERATOR ACTION AND NOT A TIMEOUT ────────────────
     *
     * The obvious alternative is "a run older than N seconds is dead". There is
     * no honest N. Generation is bounded by set_time_limit(300), but that
     * counts EXECUTION time, and this work is almost entirely Firestore round
     * trips — time spent in I/O, which on most SAPIs does not count towards it.
     * A run's wall-clock age therefore proves nothing, and any threshold would
     * be a number chosen to look reasonable.
     *
     * What a person can judge, and the code cannot, is "this has been building
     * since Tuesday". So the age is reported and the decision is theirs — the
     * same shape as retention itself.
     *
     * If the run turns out to be alive after all, nothing is corrupted: it owns
     * the same runId, so its own completion still closes it normally.
     *
     * @throws Service_exception if the version is missing or was not running
     */
    public function abandonStuckRun(string $planId, string $versionId, string $reason = ''): array
    {
        $this->assertReady();

        $version = $this->getVersion($planId, $versionId);
        if ($version === null) {
            throw new Service_exception('That timetable does not exist.', 'not_found');
        }

        $gen   = is_array($version['generation'] ?? null) ? $version['generation'] : [];
        $state = (string) ($gen['state'] ?? '');
        if ($state !== Timetable_plan_service::GEN_RUNNING) {
            throw new Service_exception(
                'That timetable is not being built, so there is nothing to give up on.',
                'validation'
            );
        }

        $runId = (string) ($gen['runId'] ?? '');
        if ($runId === '') {
            throw new Service_exception(
                'That build cannot be identified, so it was left alone.', 'validation');
        }

        $this->failGenerationRun($planId, $versionId, $runId,
            (int) ($gen['daysWritten'] ?? 0),
            $reason !== '' ? $reason
                : 'Marked as not finished by an administrator: the build never completed.');

        $this->_audit('update', 'timetableVersion',
            "{$this->schoolId}_{$this->session}_{$planId}_{$versionId}",
            ['state' => Timetable_plan_service::GEN_RUNNING],
            ['state' => Timetable_plan_service::GEN_FAILED, 'by' => 'operator']);

        return ['versionId' => $versionId, 'state' => Timetable_plan_service::GEN_FAILED];
    }
    /**
     * What this school calls each of its breaks, keyed by the period it follows.
     *
     * ── WHY THE NAME LIVES IN SETTINGS AND NOT ON THE VERSION ───────────
     *
     * The break itself IS versioned: a day document carries each period's start
     * and end, so the gap — the thing that affects teaching — is part of the
     * timetable and cannot drift. Only the WORD is looked up here.
     *
     * A label is presentation, not schedule, and keeping it in settings avoids
     * writing a snapshot into all four version-creation paths and the projection
     * contract for something that changes no lesson.
     *
     * The lookup is by the period the break follows, so a school that later
     * MOVES its break finds no match against an older timetable's gap and falls
     * back to the generic word. A historical timetable can therefore be labelled
     * generically, never wrongly.
     *
     * Never throws: a missing name is cosmetic, and failing to draw a week
     * because a label could not be read would be far worse than drawing it with
     * the generic word.
     *
     * @return array<int,string> afterPeriod => name
     */
    public function breakLabels(): array
    {
        $this->assertReady();

        try {
            $settings = $this->firebase->firestoreGetOrFail(
                self::COLLECTION_SETTINGS, "{$this->schoolId}_{$this->session}");
        } catch (\Throwable $e) {
            log_message('error', 'Timetable_plan_store: break labels unreadable: '
                . $e->getMessage());
            return [];
        }
        if (!is_array($settings)) { return []; }

        $src = $settings['recesses'] ?? $settings['Recesses'] ?? null;
        if (!is_array($src)) { return []; }

        $out = [];
        foreach ($src as $r) {
            if (!is_array($r)) { continue; }
            $after = $r['afterPeriod'] ?? $r['after_period'] ?? null;
            $name  = trim((string) ($r['name'] ?? $r['label'] ?? ''));
            if ($after === null || $name === '') { continue; }
            $out[(int) $after] = $name;
        }
        return $out;
    }
    /**
     * Which versions could be removed to recover capacity, and which never can.
     *
     * Read-only. Nothing here removes anything; it exists so an operator can see
     * the answer before deciding, and so the decision is made against reasons
     * rather than a list of identifiers.
     *
     * The projection references are read from the compatibility collection
     * rather than inferred: a version is protected if a legacy document names it
     * as its source, whatever anybody believes about which versions get
     * projected.
     *
     * @throws Service_exception if the versions cannot be read — reporting
     *         "nothing to reclaim" because the list failed to load would be the
     *         silent-empty-read failure at its most expensive.
     */
    public function retentionReport(string $today = ''): array
    {
        $this->assertReady();

        $versions = $this->listVersions();

        // ── DERIVED FROM CANONICAL STATE ONLY ────────────────────────────
        //
        // An earlier draft of this read the compatibility collection to find
        // which versions teachers are currently reading a copy of. That was
        // wrong twice over.
        //
        // Architecturally, it is the forbidden direction: canonical behaviour
        // would depend on legacy data, and the boundary tests that exist to
        // prevent exactly that correctly failed.
        //
        // Practically, it added nothing. Only a PUBLISHED version is ever
        // projected — projectPublishedVersion() is the sole writer of the legacy
        // collection and refuses anything else — and every published version is
        // already protected by its status. A projection reference can therefore
        // never protect a version that status does not already protect.
        $rows = Timetable_retention::classify($versions, $today);

        return [
            'rows'     => $rows,
            'capacity' => Timetable_retention::capacity(
                $rows, self::VERSION_SCAN_LIMIT, Timetable_service::MAX_CANDIDATES),
        ];
    }

    /**
     * Remove proposals an operator has chosen to let go of.
     *
     * ── WHY THE CALLER'S LIST IS NOT TRUSTED ────────────────────────────
     *
     * The classification the operator saw was computed when the screen loaded.
     * A version can have been published in the meantime, or picked up by the
     * projection. Every identifier is therefore re-classified here, against
     * state read now, and one that is no longer reclaimable is refused
     * individually rather than failing the whole request — an operator tidying
     * twenty proposals should not lose the other nineteen because one was
     * published while they were looking at it.
     *
     * Removal itself goes through discardVersion(), which is the single removal
     * path in this class and independently refuses anything published. This
     * adds a reason and a bulk shape; it does not add a second way to delete.
     *
     * @param string[] $versionIds
     * @param bool     $dryRun report what WOULD happen and change nothing
     * @throws Service_exception if the current state cannot be read
     */
    public function reclaimVersions(string $planId, array $versionIds, bool $dryRun = true): array
    {
        $this->assertReady();

        $report = $this->retentionReport();
        $byId   = [];
        foreach ($report['rows'] as $r) { $byId[$r['versionId']] = $r; }

        $out = [
            'dryRun'   => $dryRun,
            'removed'  => [],
            'refused'  => [],
            'failed'   => [],
            'daysFreed' => 0,
        ];

        foreach ($versionIds as $vid) {
            $vid = (string) $vid;
            if ($vid === '') { continue; }

            $row = $byId[$vid] ?? null;
            if ($row === null) {
                $out['refused'][] = ['versionId' => $vid,
                    'reason' => 'That timetable no longer exists.'];
                continue;
            }
            if (($row['class'] ?? '') !== Timetable_retention::RECLAIMABLE) {
                $out['refused'][] = ['versionId' => $vid, 'reason' => $row['why']];
                continue;
            }

            if ($dryRun) {
                $out['removed'][] = ['versionId' => $vid, 'reason' => $row['reason']];
                continue;
            }

            try {
                $res = $this->discardVersion($planId, $vid);
                $out['removed'][]  = ['versionId' => $vid, 'reason' => $row['reason']];
                $out['daysFreed'] += (int) ($res['daysRemoved'] ?? 0);
            } catch (Service_exception $e) {
                // One failure does not stop the rest: the others are unrelated
                // documents and removing them is still correct.
                $out['failed'][] = ['versionId' => $vid, 'reason' => $e->getMessage()];
            }
        }

        if (!$dryRun && !empty($out['removed'])) {
            $this->_audit('delete', 'timetableVersionBatch', $planId,
                ['requested' => count($versionIds)],
                ['removed' => count($out['removed']), 'daysFreed' => $out['daysFreed']]);
        }

        return $out;
    }
    /**
     * Discard a proposal that was never published.
     *
     * ── WHY THIS IS ALLOWED, AND EXACTLY HOW FAR ────────────────────────
     *
     * Proposals accumulate. A school that regenerates three times to get the
     * timetable right is left with two dead ones, and nothing else removes
     * them — so an operator needs a way to tidy their own working area.
     *
     * A PUBLISHED version is a different thing entirely. It is the record of
     * what was actually in force, and attendance and substitution are audited
     * against it: "what was the timetable on the 12th" must stay answerable.
     * Deleting one would destroy that answer, so this refuses — as does a
     * superseded version, which is a published one that stopped applying.
     *
     * The version's own day documents go with it. They belong to nothing else,
     * and leaving them would orphan the largest part of the data.
     *
     * @throws Service_exception if the version is published, superseded,
     *                           missing, or cannot be read
     */
    public function discardVersion(string $planId, string $versionId): array
    {
        $this->assertReady();

        $version = $this->getVersion($planId, $versionId);
        if ($version === null) {
            throw new Service_exception(
                'That timetable does not exist, so nothing was discarded.', 'not_found');
        }

        $status = (string) ($version['status'] ?? '');
        if ($status !== Timetable_plan_service::STATUS_DRAFT
            && $status !== Timetable_plan_service::STATUS_CANDIDATE) {
            throw new Service_exception(
                'This timetable has been published, so it cannot be discarded. It '
                . 'is the record of what was in force, and attendance is checked '
                . 'against it. To stop it applying, publish a new version from a '
                . 'later date.',
                'immutable'
            );
        }

        // Fail-loud: deleting a version whose days we could not read would leave
        // the days behind with nothing pointing at them.
        $days = $this->listCandidateDays($planId, $versionId);

        $removed = 0;
        foreach ($days as $d) {
            if (!is_array($d)) continue;
            $safe  = str_replace('/', '_', (string) ($d['classSection'] ?? ''));
            $docId = "{$this->schoolId}_{$this->session}_{$planId}_{$versionId}_{$safe}_"
                   . (string) ($d['day'] ?? '');
            try {
                $this->firebase->firestoreDelete(self::COLLECTION_VERSION_DAYS, $docId);
                $removed++;
            } catch (\Throwable $e) {
                log_message('error', 'Timetable_plan_store: could not remove day '
                    . $docId . ' while discarding: ' . $e->getMessage());
                throw new Service_exception(
                    'The timetable could not be fully discarded, so it has been left '
                    . 'in place. Please try again in a moment.',
                    'write'
                );
            }
        }

        $docId = "{$this->schoolId}_{$this->session}_{$planId}_{$versionId}";
        try {
            $this->firebase->firestoreDelete(self::COLLECTION_VERSIONS, $docId);
        } catch (\Throwable $e) {
            log_message('error', 'Timetable_plan_store: could not remove version '
                . $docId . ': ' . $e->getMessage());
            throw new Service_exception(
                'The timetable could not be fully discarded. Please try again in a '
                . 'moment.',
                'write'
            );
        }

        $this->_audit('delete', 'timetableVersion', $docId,
            ['status' => $status, 'versionId' => $versionId], null);

        return ['versionId' => $versionId, 'daysRemoved' => $removed];
    }
    /**
     * PHASE C — PROJECT A PUBLISHED VERSION INTO THE LEGACY COLLECTION.
     *
     * The Teacher app reads `timetables` directly through the client SDK, so
     * that collection has to keep existing and keep its shape until the app is
     * released against the version architecture. This makes it a DERIVED copy of
     * a published version instead of a place anyone edits.
     *
     * ── DRY RUN BY DEFAULT ──────────────────────────────────────────────
     *
     * $apply defaults to false and the method returns the plan without writing,
     * matching how every backfill in scripts/ behaves. Writing to the collection
     * teachers are reading from is not something to do as a side effect of
     * asking what would happen.
     *
     * ── IT REFUSES TO PROJECT ANYTHING BUT A PUBLISHED VERSION ──────────
     *
     * Projecting a draft or candidate would put content in front of teachers
     * that nobody approved, and would make the legacy collection disagree with
     * what resolveForDate() says is in force.
     *
     * ── IT NEVER DELETES ────────────────────────────────────────────────
     *
     * Legacy documents this version does not account for are RETURNED as
     * orphans, not removed. They are live teacher-visible documents; deciding
     * they should disappear is a separate authorised act, not a silent
     * consequence of projecting.
     *
     * ── WHO CALLS THIS, AS OF 2026-08-31 ────────────────────────────────
     *
     * This comment used to say "nothing calls this", and that has not been true
     * for some time. Leaving it said the projection was inert, which is the
     * most misleading thing a comment on a writer can say. There are exactly
     * three callers, and they are the complete write census for `timetables`:
     *
     *   1. publishCandidate(), with $apply = TRUE, when the version being
     *      published is in force today. Reachable over HTTP as
     *      POST /timetable_lifecycle/publish (Academic: manage). This is the
     *      live path — a school that publishes a timetable for today reaches
     *      teachers immediately without knowing a cron exists.
     *   2. Timetable_projection_cron (CLI, APPLY=1), which does the one job
     *      publishing cannot: materialising a FUTURE-dated version on the day
     *      it becomes effective.
     *   3. Timetable_lifecycle::projection_preview(), which never passes
     *      $apply, so no HTTP request can change a teacher-visible document
     *      through it.
     *
     * The old direct-write paths it warned about (savePeriod,
     * saveSectionTimetable, reconcileClassFromMatrix, autoGenerate) have since
     * been retired — Timetable_service::_refuseLegacyWrite() refuses them
     * unconditionally — so the second competing authority that comment was
     * guarding against no longer exists. What remains beside this method is one
     * CLI repair tool (Timetables_reconcile) and the session rollover, and both
     * refuse any document that carries `projectedFromVersion`.
     *
     * @throws Service_exception if the version is not published, not complete,
     *                           unreadable, or a write fails
     */
    public function projectPublishedVersion(string $planId, string $versionId, bool $apply = false): array
    {
        $this->assertReady();

        $version = $this->getVersion($planId, $versionId);
        if ($version === null) {
            throw new Service_exception(
                'That timetable version does not exist, so nothing was projected.',
                'not_found'
            );
        }
        if ((string) ($version['status'] ?? '') !== Timetable_plan_service::STATUS_PUBLISHED) {
            throw new Service_exception(
                'Only a published timetable can be made visible to teachers. This '
                . 'one has not been published.',
                'not_published'
            );
        }

        // Complete, or it is not a faithful representation of anything.
        $read = $this->readCompleteCandidate($planId, $versionId);

        $projected = Timetable_projection_service::project($read['days'], [
            'schoolId'  => $this->schoolId,
            'session'   => $this->session,
            'planId'    => $planId,
            'versionId' => $versionId,
            'at'        => date('c'),
            'byUid'     => $this->adminId,
        ]);

        $existing = $this->_existingLegacyDocs();
        $plan     = Timetable_projection_service::plan($projected, $existing);

        // What KIND of difference, not just how much of it. A pending count
        // reads the same whether the apps are one lesson behind or holding a
        // timetable from a version that no longer exists — and "the projection
        // ran" says nothing at all, because a run from the wrong version
        // succeeds just as loudly as one from the right version.
        $plan['diagnosis'] = Timetable_projection_service::diagnose(
            $projected, $existing, $plan);

        $plan['applied']   = false;
        $plan['planId']    = $planId;
        $plan['versionId'] = $versionId;

        if (!$apply) {
            return $plan;
        }


        $written = 0;
        foreach ($plan['writes'] as $docId => $data) {
            try {
                // merge = true: every field the version owns is overwritten, and
                // legacy metadata this layer did not author is left intact so the
                // migration stays reversible.
                $ok = $this->firebase->firestoreSet(self::COLLECTION_LEGACY_TT, $docId, $data, true);
            } catch (\Throwable $e) {
                log_message('error', 'Timetable_plan_store: projection write failed (doc='
                    . $docId . '): ' . $e->getMessage());
                $ok = false;
            }
            if ($ok === false) {
                log_message('error', 'Timetable_plan_store: projection refused (doc=' . $docId . ')');
                throw new Service_exception(
                    'The timetable could not be fully published to teachers: it stopped '
                    . 'after ' . $written . ' of ' . count($plan['writes']) . ' updates. '
                    . 'Some classes may still be showing the previous timetable. '
                    . 'Run it again to finish.',
                    'projection_incomplete'
                );
            }
            $written++;
        }

        $plan['applied'] = true;
        $plan['written'] = $written;

        $this->_audit('project', 'timetableVersion',
            "{$this->schoolId}_{$this->session}_{$planId}_{$versionId}", null, [
                'planId' => $planId, 'versionId' => $versionId,
                'written' => $written, 'unchanged' => count($plan['unchanged']),
                'orphans' => $plan['orphans'],
            ]);

        return $plan;
    }

    /**
     * Everything currently in the legacy collection for this tenant and session,
     * keyed by its REAL document id.
     *
     * Fail-loud: projecting while unable to see what is already there would
     * write blind and report orphans that may not exist.
     *
     * ── TT-C2: THE ROW SHAPE IS AN ENVELOPE, NOT THE DOCUMENT ───────────
     *
     * Firestore_rest_client::query() returns rows as ['id' => …, 'data' => …].
     * This method used to read `$r['schoolId']` straight off the row, so the
     * tenant re-check below rejected EVERY row and the method always returned
     * an empty array. Nothing failed loudly; the consequences were all silent
     * and all in the projection's answer:
     *
     *   • `unchanged` was always empty, so the projection rewrote every
     *     document on every run and was never idempotent — a nightly cron
     *     would have rewritten the whole school's timetable each night;
     *   • `version` was computed as (0 + 1), so the legacy monotonic counter
     *     was reset to 1 on a document that had reached 7;
     *   • `orphans` was always empty. That is the only warning an operator
     *     gets before deleting live teacher-visible documents, and it was
     *     structurally incapable of reporting anything.
     *
     * It survived review because the unit double for this path returns rows
     * FLAT. Every other reader here (listVersions, listCandidateDays) already
     * accepts both shapes; this one is brought in line rather than made to
     * depend on the double's shape. Proven at the persistence boundary by
     * scripts/timetable_projection_runtime_proof.js against a real emulator.
     *
     * ── AND THE KEY IS THE REAL ID ──────────────────────────────────────
     *
     * The map used to be keyed by an id RECONSTRUCTED from sectionKey + day.
     * That is a guess about where the document lives, and when it guessed
     * wrong in either direction the result was wrong in a dangerous way: a
     * reconstructed key that collided with a projected id made the projection
     * believe a document existed where none did, so it recorded "unchanged"
     * and skipped the write — leaving a class with no timetable at all. The
     * id Firestore reports is the id the write and the delete will address,
     * so it is the only key that can be compared against.
     *
     * @throws Service_exception if the collection cannot be read
     */
    private function _existingLegacyDocs(): array
    {
        try {
            $rows = $this->firebase->firestoreQueryOrFail(self::COLLECTION_LEGACY_TT, [
                ['schoolId', '==', $this->schoolId],
                ['session',  '==', $this->session],
            ], null, 'ASC', self::DAY_SCAN_LIMIT + 1);
        } catch (\Throwable $e) {
            log_message('error', 'Timetable_plan_store: could not read the legacy '
                . 'collection: ' . $e->getMessage());
            throw new Service_exception(
                'The timetable teachers currently see could not be read, so nothing '
                . 'was changed. Please try again in a moment.',
                'read'
            );
        }

        if (count($rows) > self::DAY_SCAN_LIMIT) {
            throw new Service_exception(
                'This session has more timetable documents than can be projected '
                . 'safely in one pass. Please contact support.',
                'too_large'
            );
        }

        $out = [];
        foreach ($rows as $r) {
            if (!is_array($r)) continue;

            // {id, data} from the REST client; a bare document from a double
            // or any caller that already unwrapped. Both are accepted, the
            // same way listVersions() and listCandidateDays() accept them.
            $d  = is_array($r['data'] ?? null) ? $r['data'] : $r;
            $id = (string) ($r['id'] ?? '');
            if (!is_array($d)) continue;

            // Tenant and session are re-checked on the rows themselves, never
            // trusted from the query alone.
            if ((string) ($d['schoolId'] ?? '') !== $this->schoolId) continue;
            if ((string) ($d['session']  ?? '') !== $this->session)  continue;

            if ($id === '') {
                // No id to compare against. Fall back to the conventional id
                // so an already-unwrapped caller still gets a usable map, but
                // only when the fields that build it are actually present —
                // an invented key is worse than a missing one, because the
                // projection would skip a write for a document that is not
                // there.
                $sk  = (string) ($d['sectionKey'] ?? '');
                $day = (string) ($d['day'] ?? '');
                if ($sk === '' || $day === '') continue;
                $id = "{$this->schoolId}_{$this->session}_"
                    . str_replace('/', '_', $sk) . "_{$day}";
            }

            $out[$id] = $d;
        }
        return $out;
    }
    /**
     * PHASE B — PUBLISH A CANDIDATE.
     *
     * This is the only transition in the system that makes a timetable live, and
     * it is one-way. After it, the version is immutable: saveCandidateDay() and
     * beginGenerationRun() both refuse any status other than draft or candidate,
     * so a published version cannot be edited or regenerated into. A correction
     * is made with createChangeVersion() and published as a NEW version.
     *
     * There is deliberately no unpublish and no edit-in-place. Adding one would
     * be the mutable backdoor the whole design exists to avoid: the published
     * record must stay reconstructable as what was actually published.
     *
     * ── WHAT IT REFUSES ─────────────────────────────────────────────────
     *
     *   - a version that is already published (publishing is not idempotent;
     *     silently re-publishing would re-date a live timetable)
     *   - a version left incomplete by a failed generation run
     *   - a version with no trustworthy verdict, or one with HARD violations
     *   - an effectiveFrom that is not a real calendar date
     *
     * Soft violations never block. They are the school's preferences, and this
     * layer does not rank them.
     *
     * ── OVERLAPS ────────────────────────────────────────────────────────
     *
     * Overlaps are DETECTED, REPORTED and returned — never silently resolved and
     * never forbidden. That is the established house convention (findOverlaps
     * reports rather than refuses), and it is safe here because resolveForDate()
     * returns AMBIGUOUS rather than guessing when two published versions claim
     * the same date. The administrator is told, at the moment it happens, which
     * dates became ambiguous and which versions are competing.
     *
     * This does NOT auto-close the previous version's effectiveTo. Editing a
     * published version's dating — even only its dating — is a mutation of
     * published data, and no owner decision authorises it.
     *
     * @throws Service_exception on any refusal; the version is left untouched
     */
    public function publishCandidate(string $planId, string $versionId, string $effectiveFrom,
                                     string $effectiveTo = ''): array
    {
        $this->assertReady();

        // ── the date must be real before anything else happens ───────────
        $probe = ['schoolId' => $this->schoolId, 'session' => $this->session,
                  'planId' => $planId, 'versionId' => $versionId,
                  'status' => Timetable_plan_service::STATUS_PUBLISHED,
                  'effectiveFrom' => $effectiveFrom, 'effectiveTo' => $effectiveTo];
        $dateErrors = Timetable_plan_service::validateVersion($probe);
        if (!empty($dateErrors)) {
            throw new Service_exception(implode('; ', $dateErrors), 'validation');
        }

        // THE TOKEN IS READ FIRST — before ANY state is read.
        //
        // It used to be read after the version, the completeness check and the
        // verdict, immediately before the overlap check. That covered the
        // overlap decision but NOT the `already_published` one: two
        // administrators publishing the SAME version could each read it as a
        // candidate, and whichever committed second re-published it with its
        // own dates over the other's. The overlap check cannot catch that,
        // because publishedOverlaps() correctly excludes the version being
        // published from its own comparison. Proven by execution in
        // TimetableSupersedeOrderingTest before this line moved.
        $guardToken = $this->_readPublishGuard();

        $version = $this->getVersion($planId, $versionId);
        if ($version === null) {
            throw new Service_exception(
                'That timetable version does not exist, so nothing was published.',
                'not_found'
            );
        }

        $status = (string) ($version['status'] ?? '');
        if ($status === Timetable_plan_service::STATUS_PUBLISHED) {
            throw new Service_exception(
                'This timetable is already published. To change when it applies or '
                . 'what it contains, create a new version from it and publish that.',
                'already_published'
            );
        }
        if ($status !== Timetable_plan_service::STATUS_DRAFT
            && $status !== Timetable_plan_service::STATUS_CANDIDATE) {
            throw new Service_exception(
                'Only a draft or candidate timetable can be published.',
                'immutable'
            );
        }

        // ── it must be whole (ACAD-D24) ──────────────────────────────────
        // readCompleteCandidate() throws for a run that failed or never finished.
        $read = $this->readCompleteCandidate($planId, $versionId);

        // ── it must be checked, and the check must still describe it ─────
        $current = $this->currentVerdict($planId, $versionId);
        if (!$current['trusted']) {
            throw new Service_exception(
                'This timetable cannot be published yet: ' . $current['reason']
                . ' Please check it again first.',
                'not_validated'
            );
        }
        $hardCount = (int) ($current['verdict']['hardCount'] ?? 0);
        if ($hardCount > 0) {
            $first = $current['verdict']['hard'][0]['message'] ?? '';
            throw new Service_exception(
                'This timetable has ' . $hardCount . ' problem'
                . ($hardCount === 1 ? '' : 's') . ' that must be fixed before it can '
                . 'be published' . ($first !== '' ? ': ' . $first : '.'),
                'has_hard_violations'
            );
        }

        // ── who else already claims these dates ──────────────────────────
        $overlaps = $this->_publishedOverlaps($planId, $versionId, $effectiveFrom, $effectiveTo);

        // ── TT-C8 OPTION 4 — ONE TIMETABLE IN FORCE, AND THAT IS A RULE ──
        //
        // This used to publish anyway and report the overlap. That was the
        // house convention for overlaps generally, and it is still right for
        // drafts and candidates — but it cannot hold for PUBLISHED versions,
        // because an overlap between two of them has no valid meaning anywhere
        // in the system:
        //
        //   • resolveForDate() refuses to choose and returns AMBIGUOUS;
        //   • the projection holds ONE document per section per day and
        //     physically cannot represent two timetables;
        //   • the cron then refuses too, so nothing repairs it;
        //   • and there is no unpublish, so the school cannot get out of it.
        //
        // Reporting a state that breaks the product is not a warning, it is a
        // trap. Owner-authorised 2026-08-31: for a given school, session and
        // date, exactly one published version may be in force.
        if (!empty($overlaps)) {
            $names = [];
            foreach ($overlaps as $o) {
                $label = trim((string) ($o['planName'] ?? '')) !== ''
                    ? $o['planName'] . ' ' . $o['versionId']
                    : (string) $o['versionId'];
                $period = ((string) $o['effectiveTo'] === '')
                    ? 'from ' . $o['effectiveFrom'] . ' onwards'
                    : 'from ' . $o['effectiveFrom'] . ' to ' . $o['effectiveTo'];
                $names[] = $label . ' (' . $period . ')';
            }
            throw new Service_exception(
                'A timetable is already published for '
                . (count($overlaps) === 1 ? 'some of those dates' : 'some of those dates')
                . ': ' . implode('; ', $names) . '. A school can have only one '
                . 'timetable in force on any day, so this one was not published. '
                . 'Change the dates so they do not overlap, or end the existing '
                . 'timetable first.',
                'overlap'
            );
        }

        $docId  = "{$this->schoolId}_{$this->session}_{$planId}_{$versionId}";
        $update = [
            'status'        => Timetable_plan_service::STATUS_PUBLISHED,
            'effectiveFrom' => $effectiveFrom,
            'effectiveTo'   => $effectiveTo,
            'publishedAt'   => date('c'),
            'publishedBy'   => $this->adminId,
            // What was actually published, so the record stays reconstructable
            // even after the verdict shape changes.
            'publishedFingerprint' => (string) ($current['verdict']['fingerprint'] ?? ''),
            'publishedDayCount'    => count($read['days']),
        ];

        // ── TT-C8 OPTION 3 — PUBLISH AS ONE ATOMIC STEP ─────────────────
        //
        // The version write and the guard-token write go in ONE batch, and the
        // guard write carries the precondition. Firestore rolls a batch back
        // whole when any precondition fails, so there is no state in which the
        // version was published but the token was not — which is what makes
        // "exactly one publish wins" true rather than likely.
        //
        // `exists: false` when the guard has never been written, `updateTime`
        // otherwise. The first form is what makes two publishes racing to
        // create the FIRST guard as safe as two racing to update it; without it
        // the very first publish of a school's life would be unguarded.
        $guardWrite = [
            'collection'   => self::COLLECTION_PUBLISH_GUARD,
            'docId'        => $this->_publishGuardId(),
            'data'         => [
                'schoolId'            => $this->schoolId,
                'session'             => $this->session,
                'lastPublishedPlan'   => $planId,
                'lastPublishedVersion'=> $versionId,
                'lastPublishedAt'     => date('c'),
                'lastPublishedBy'     => $this->adminId,
            ],
            'merge'        => true,
            'precondition' => $guardToken !== ''
                ? ['updateTime' => $guardToken]
                : ['exists' => false],
        ];

        try {
            $commit = $this->firebase->firestoreCommitBatchStatus([
                ['collection' => self::COLLECTION_VERSIONS, 'docId' => $docId,
                 'data' => $update, 'merge' => true],
                $guardWrite,
            ]);
        } catch (\Throwable $e) {
            log_message('error', 'Timetable_plan_store: publish commit threw (doc='
                . $docId . '): ' . $e->getMessage());
            throw new Service_exception(
                'The timetable could not be published. Nothing was changed — please '
                . 'try again in a moment.',
                'write'
            );
        }

        $commitStatus = (string) ($commit['status'] ?? '');

        // Someone else published for this school and session between our check
        // and our write. Nothing of ours landed.
        if ($commitStatus === 'PRECONDITION_CONFLICT') {
            log_message('error', 'Timetable_plan_store: publish CAS conflict (doc='
                . $docId . ' admin=' . $this->adminId . ')');
            throw new Service_exception(
                'Someone else published a timetable for this session while you were '
                . 'publishing this one, so yours was not published — publishing both '
                . 'would have left two timetables in force. Refresh to see what is '
                . 'live now, then publish again if it is still what you want.',
                'conflict'
            );
        }

        // Anything that is not SUCCESS is a failure we cannot classify, and an
        // unclassified write is never treated as a publish.
        //
        // The literal matters: Firestore_rest_client::COMMIT_SUCCESS is
        // 'SUCCESS'. An earlier draft of this check accepted 'OK', which no
        // caller ever returns — so every successful publish was reported to the
        // administrator as a failure while the version was, in fact, published
        // and left unprojected. The unit double returned 'OK' too, so the unit
        // tests agreed with the bug; it was the emulator run against the real
        // client that showed it. Same status literal as saveLessonPlan().
        if ($commitStatus !== 'SUCCESS') {
            log_message('error', 'Timetable_plan_store: publish commit failed (doc='
                . $docId . ' status=' . $commitStatus . ')');
            throw new Service_exception(
                'The timetable could not be published. Nothing was changed — please '
                . 'try again in a moment.',
                'write'
            );
        }

        $this->_audit('publish', 'timetableVersion', $docId,
            ['status' => $status], [
                'status' => Timetable_plan_service::STATUS_PUBLISHED,
                'effectiveFrom' => $effectiveFrom, 'effectiveTo' => $effectiveTo,
                // Always empty now: an overlapping publish is refused above.
                // Kept so the audit shape does not change under readers that
                // already parse it.
                'overlapsWith' => array_column($overlaps, 'versionId'),
            ]);

        // ── reach teachers now, if this timetable is in force now ────────
        //
        // A newly onboarded school publishes and expects teachers to see it. It
        // must not have to know that a cron exists. The cron keeps the one job
        // publishing cannot do — materialising a FUTURE-dated version on the day
        // it becomes effective.
        //
        // TT-C8: there used to be a third branch here, suppressing the
        // projection when the publish overlapped something. It is gone because
        // it can no longer be reached — an overlapping publish is refused
        // outright above, so by this line exactly one published version covers
        // any given date. Leaving a branch that cannot execute would suggest
        // the state it handled is still possible, and it is not.
        $projection = ['attempted' => false, 'applied' => false, 'written' => 0,
                       'reason' => '', 'error' => null];

        // "Is it live today" is the SAME question the resolver asks, so it is
        // asked with the same predicate rather than a third copy of the dating
        // rules. The version is published as of the commit above.
        $inForceNow = Timetable_plan_service::coversDate([
            'status'        => Timetable_plan_service::STATUS_PUBLISHED,
            'effectiveFrom' => $effectiveFrom,
            'effectiveTo'   => $effectiveTo,
        ], date('Y-m-d'));

        if (!$inForceNow) {
            $projection['reason'] = 'This timetable starts later, so teachers will '
                . 'see it on ' . $effectiveFrom . '.';
        } else {
            $projection['attempted'] = true;
            try {
                $applied = $this->projectPublishedVersion($planId, $versionId, true);
                $projection['applied'] = true;
                $projection['written'] = (int) ($applied['written'] ?? 0);
                $projection['orphans'] = $applied['orphans'] ?? [];
            } catch (\Throwable $e) {
                // Publishing SUCCEEDED — the canonical record is made and must
                // not be undone because a downstream copy failed. But a silent
                // failure here means teachers never see it, so it is reported
                // rather than swallowed.
                log_message('error', 'Timetable_plan_store: published ' . $versionId
                    . ' but could not make it visible to teachers: ' . $e->getMessage());
                $projection['error'] = $e->getMessage();
            }
        }

        return [
            'versionId'     => $versionId,
            'docId'         => $docId,
            'effectiveFrom' => $effectiveFrom,
            'effectiveTo'   => $effectiveTo,
            'dayCount'      => count($read['days']),
            'softCount'     => (int) ($current['verdict']['softCount'] ?? 0),
            'shortfall'     => $this->_shortfallAtPublish($version),
            'overlaps'      => $overlaps,
            'projection'    => $projection,
            'explain'       => $this->_explainPublish($versionId, $effectiveFrom, $effectiveTo, $overlaps),
        ];
    }

    /**
     * How much of the curriculum this timetable does NOT deliver, at the moment
     * it is published.
     *
     * ── WHY THIS IS DISCLOSED BUT NOT ENFORCED ──────────────────────────
     *
     * Whether a timetable that teaches a subject fewer times than the school
     * asked for may be published at all is an OWNER DECISION, and it has not
     * been made. Nothing here decides it: publishing behaves exactly as before.
     *
     * What is not a decision is whether the operator should be TOLD. Publishing
     * already discloses an overlap, because a safety state that only the
     * database knows about is a trap. A timetable that is four periods short of
     * the curriculum is the same kind of fact, and the moment of publishing is
     * the last point at which anybody looks.
     *
     * It also puts the number at the site where a future policy would act, so
     * enforcing it later is a decision plus a condition, not an architecture
     * change.
     *
     * Reported as UNKNOWN rather than zero when the measurements are stale: a
     * hand-edited timetable's recorded shortfall describes the version before
     * the edit, and "0" would read as "nothing missing".
     */
    private function _shortfallAtPublish(?array $version): array
    {
        if (!is_array($version)) {
            return ['known' => false, 'total' => 0, 'reason' => 'unreadable'];
        }
        if (!empty($version['factsStale'])) {
            return ['known' => false, 'total' => 0, 'reason' => 'stale'];
        }

        $short = $version['facts']['metrics']['shortfall'] ?? null;
        if (!is_array($short) || empty($short['known'])) {
            return ['known' => false, 'total' => 0, 'reason' => 'not measured'];
        }

        return [
            'known'     => true,
            'total'     => (int) ($short['total'] ?? 0),
            'bySubject' => is_array($short['bySubject'] ?? null)
                ? array_slice($short['bySubject'], 0, 5) : [],
        ];
    }
    /**
     * Published versions of this plan whose effective period intersects the one
     * being claimed. Reported, not prevented.
     *
     * @throws Service_exception if the version list cannot be read — publishing
     *         while unable to see who else claims the dates would be a guess.
     */
    /**
     * Published versions that would share a date with [$from, $to].
     *
     * TT-C8 option 2 — this used to carry its own copy of the dating rules AND
     * filter by `planId`, which made it strictly narrower than the resolver it
     * was supposed to protect. It now delegates to the one predicate, so the
     * write side and the read side cannot disagree about what "in force" means.
     *
     * @throws Service_exception if the version set cannot be read (listVersions
     *         is fail-loud; an overlap check answered from an unreadable or
     *         truncated set would be a guess)
     */
    private function _publishedOverlaps(string $planId, string $versionId,
                                        string $from, string $to): array
    {
        return Timetable_plan_service::publishedOverlaps(
            $this->listVersions(), $planId, $versionId, $from, $to);
    }

    /** Fields SUPERSEDE is permitted to write. Nothing else may be in the mask. */
    const SUPERSEDE_FIELDS = [
        'effectiveTo', 'supersededAt', 'supersededBy', 'supersededReason',
    ];

    /**
     * PHASE D — CLOSE THE FUTURE APPLICABILITY OF A PUBLISHED TIMETABLE.
     *
     * ── WHY THIS EXISTS (TT-C8 option 6) ────────────────────────────────
     *
     * A published version with no end date claims every future date. Since
     * publishing refuses an overlap (option 4), such a version blocks every
     * later publish for its school and session — INCLUDING ITS OWN CORRECTION,
     * because createChangeVersion() → edit → publish goes through the same
     * refusal. An administrator who published without an end date, which is the
     * natural thing to do when the term's end is not yet known, could never
     * publish again. This is the one narrow action that unblocks them.
     *
     * ── WHAT IT IS NOT ──────────────────────────────────────────────────
     *
     * Not unpublish, not delete, not withdraw, not rollback, not reopen, and
     * not a general edit of published records. It shortens the FUTURE. It can
     * never rewrite the past.
     *
     * ── STATUS IS DELIBERATELY NOT TOUCHED ──────────────────────────────
     *
     * An earlier design had this flip `status` to `superseded` once the end
     * date passed. The repository disproves it: coversDate() requires
     * `status === published`, so flipping the status would stop the version
     * covering EVERY date — including the past ones it genuinely applied to —
     * and that is precisely the historical rewrite this action must not
     * perform. Deferring the flip until the date passes would need a scheduled
     * worker, and inventing one is not authorised.
     *
     * So the version stays `published` with a closed range, which is exactly
     * what coversDate() needs to keep answering correctly on both sides of the
     * end date. "Superseded" is a derived reading of (published + effectiveTo
     * in the past), not a stored state. Nothing is lost: Timetable_retention
     * already protects a published version whose range has passed, because
     * "attendance is checked against it".
     *
     * ── THE TWO INVARIANTS ──────────────────────────────────────────────
     *
     * HISTORICAL SAFETY. For every date before today the resolution is
     * unchanged. effectiveFrom and status are untouched, and effectiveTo moves
     * from unbounded to a date that is today or later, which can only stop the
     * version matching dates strictly after that. No past date can move.
     *
     * COVERAGE MONOTONICITY. For every date, the set of versions covering it
     * afterwards is a subset of the set before. This action only ever removes
     * dates from one version's range and touches exactly one version, so a date
     * that resolved to one version cannot come to resolve to two. It is
     * therefore structurally incapable of creating an ambiguity — which is what
     * lets it be the repair for one.
     *
     * @throws Service_exception on every refusal; nothing is written
     */
    public function supersedeVersion(string $planId, string $versionId,
                                     string $lastEffectiveDate, string $reason): array
    {
        $this->assertReady();

        $reason = trim($reason);
        if ($reason === '') {
            throw new Service_exception(
                'Say why this timetable is being ended — the reason is the only '
                . 'record of it afterwards.',
                'validation'
            );
        }
        if (!Timetable_plan_service::isIsoDate($lastEffectiveDate)) {
            throw new Service_exception(
                'The last day this timetable applies must be a real date.',
                'validation'
            );
        }

        // THE TOKEN IS READ FIRST — before ANY state is read.
        //
        // It used to be read just before the commit, after the version had
        // already been examined. That left a window nothing covered: another
        // supersede completing between our version read and our token read was
        // invisible twice over — our snapshot still said open-ended, and the
        // token we picked up afterwards already contained their write, so the
        // precondition passed and the commit RE-DATED a version that was
        // already bounded. Proven by execution in
        // TimetableSupersedeOrderingTest before this line moved.
        //
        // Reading it first is what makes the precondition mean "nothing about
        // this school's timetables has changed since I started looking".
        $guardToken = $this->_readPublishGuard();

        // getVersion() re-checks tenant AND session on the document itself and
        // is fail-loud, so an unreadable version refuses rather than looking
        // like an absent one.
        $version = $this->getVersion($planId, $versionId);
        if ($version === null) {
            throw new Service_exception(
                'That timetable version does not exist, so nothing was changed.',
                'not_found'
            );
        }

        if ((string) ($version['status'] ?? '') !== Timetable_plan_service::STATUS_PUBLISHED) {
            throw new Service_exception(
                'Only a published timetable can be ended. This one has not been '
                . 'published.',
                'not_published'
            );
        }

        // ONE-WAY. An end date is set once and never changed; changing one is
        // the general edit-in-place capability this action exists to avoid.
        if (trim((string) ($version['effectiveTo'] ?? '')) !== '') {
            throw new Service_exception(
                'This timetable already has a last day (' . $version['effectiveTo']
                . '), and that cannot be changed. To use a different timetable from '
                . 'a later date, publish one for those dates.',
                'already_bounded'
            );
        }

        $effectiveFrom = (string) ($version['effectiveFrom'] ?? '');
        if (!Timetable_plan_service::isIsoDate($effectiveFrom)) {
            throw new Service_exception(
                'This timetable has no valid start date, so it cannot be given an '
                . 'end date. Please contact support.',
                'validation'
            );
        }
        if ($lastEffectiveDate < $effectiveFrom) {
            throw new Service_exception(
                'A timetable cannot end before it starts. This one starts on '
                . $effectiveFrom . '.',
                'validation'
            );
        }

        // THE LOAD-BEARING PRECONDITION. Everything the historical-safety
        // invariant rests on is this line: an end date in the past would remove
        // days the timetable genuinely applied to, and attendance and lesson
        // plans were recorded against them.
        $today = date('Y-m-d');
        if ($lastEffectiveDate < $today) {
            throw new Service_exception(
                'The last day must be today or later. Ending it on a past date '
                . 'would change which timetable applied on days that have already '
                . 'happened.',
                'retroactive'
            );
        }

        $now  = date('c');
        $docId = "{$this->schoolId}_{$this->session}_{$planId}_{$versionId}";

        // The write mask, built from the constant so it cannot drift from what
        // is documented as permitted. `status` is absent on purpose (above);
        // every published-content field is absent because this array is the
        // only thing written.
        $update = [
            'effectiveTo'      => $lastEffectiveDate,
            'supersededAt'     => $now,
            'supersededBy'     => $this->adminId,
            'supersededReason' => $reason,
        ];
        $unauthorised = array_diff(array_keys($update), self::SUPERSEDE_FIELDS);
        if (!empty($unauthorised)) {
            // Unreachable by construction; present so that widening the payload
            // without widening the allowlist fails loudly instead of quietly
            // writing a published-content field.
            log_message('error', 'Timetable_plan_store: supersede write mask contains '
                . 'unauthorised fields: ' . implode(',', $unauthorised));
            throw new Service_exception(
                'The timetable could not be ended. Nothing was changed.', 'write');
        }

        try {
            $commit = $this->firebase->firestoreCommitBatchStatus([
                ['collection' => self::COLLECTION_VERSIONS, 'docId' => $docId,
                 'data' => $update, 'merge' => true],
                ['collection'   => self::COLLECTION_PUBLISH_GUARD,
                 'docId'        => $this->_publishGuardId(),
                 'data'         => [
                     'schoolId'         => $this->schoolId,
                     'session'          => $this->session,
                     'lastSupersededPlan'    => $planId,
                     'lastSupersededVersion' => $versionId,
                     'lastSupersededAt'      => $now,
                     'lastSupersededBy'      => $this->adminId,
                 ],
                 'merge'        => true,
                 'precondition' => $guardToken !== ''
                     ? ['updateTime' => $guardToken]
                     : ['exists' => false],
                ],
            ]);
        } catch (\Throwable $e) {
            log_message('error', 'Timetable_plan_store: supersede commit threw (doc='
                . $docId . '): ' . $e->getMessage());
            throw new Service_exception(
                'The timetable could not be ended. Nothing was changed — please try '
                . 'again in a moment.',
                'write'
            );
        }

        $commitStatus = (string) ($commit['status'] ?? '');

        if ($commitStatus === 'PRECONDITION_CONFLICT') {
            log_message('error', 'Timetable_plan_store: supersede CAS conflict (doc='
                . $docId . ' admin=' . $this->adminId . ')');
            throw new Service_exception(
                'Someone else changed the timetables for this session while you were '
                . 'ending this one, so nothing was changed. Refresh to see what is '
                . 'live now, then try again if it is still what you want.',
                'conflict'
            );
        }

        // Firestore_rest_client::COMMIT_SUCCESS is 'SUCCESS'. Anything else —
        // FAILURE, an unclassified outcome, an empty status — is a failure, and
        // an unclassified write is never reported as a completed transition.
        if ($commitStatus !== 'SUCCESS') {
            log_message('error', 'Timetable_plan_store: supersede commit failed (doc='
                . $docId . ' status=' . $commitStatus . ')');
            throw new Service_exception(
                'The timetable could not be ended. Nothing was changed — please try '
                . 'again in a moment.',
                'write'
            );
        }

        // The record of who ended a live timetable and why. `status_change` is
        // used because it is an action the audit service already accepts and it
        // is what this is; inventing a new global action for one caller would
        // widen a shared vocabulary for no gain. The audit result is CHECKED —
        // log() returns false for a refused entry and every other caller in
        // this class discards it, which is how the whole module's audit trail
        // came to be missing.
        $audited = $this->_auditChecked('status_change', 'timetableVersion', $docId,
            ['status' => Timetable_plan_service::STATUS_PUBLISHED, 'effectiveTo' => ''],
            [
                'status'           => Timetable_plan_service::STATUS_PUBLISHED,
                'effectiveTo'      => $lastEffectiveDate,
                'transition'       => 'timetable_supersede',
                'supersededBy'     => $this->adminId,
                'supersededAt'     => $now,
                'supersededReason' => $reason,
            ]);

        return [
            'planId'        => $planId,
            'versionId'     => $versionId,
            'docId'         => $docId,
            'effectiveFrom' => $effectiveFrom,
            'effectiveTo'   => $lastEffectiveDate,
            'reason'        => $reason,
            'audited'       => $audited,
            'explain'       => 'This timetable now applies until ' . $lastEffectiveDate
                . '. Publish a timetable starting the day after to replace it; until '
                . 'you do, teachers and parents keep seeing this one.',
        ];
    }

    /**
     * _audit(), but the caller is told whether the entry was actually written.
     *
     * Audit_log_service::log() returns false — it does not throw — for a
     * refused action or entity type, so the plain _audit() below cannot tell a
     * recorded event from a discarded one. Every timetable audit in this class
     * used that path, and none of them were being written.
     */
    private function _auditChecked(string $action, string $entity, string $id,
                                   ?array $before, ?array $after): bool
    {
        if (!$this->audit) {
            log_message('error', 'Timetable_plan_store: no audit service wired — '
                . $action . '/' . $entity . '/' . $id . ' was NOT recorded');
            return false;
        }
        try {
            $ok = (bool) $this->audit->log($action, $entity, $id, $before, $after, []);
        } catch (\Throwable $e) {
            log_message('error', 'timetable plan audit threw: ' . $e->getMessage());
            return false;
        }
        if (!$ok) {
            log_message('error', 'Timetable_plan_store: audit REFUSED '
                . $action . '/' . $entity . '/' . $id);
        }
        return $ok;
    }

    /**
     * ── TT-C8 OPTION 3 — THE PUBLISH GUARD ──────────────────────────────
     *
     * One document per (school, session), holding no business meaning at all —
     * only a token that changes on every publish. It exists so that the check
     * "is any other timetable already in force over these dates?" and the write
     * that acts on that answer become ONE atomic step.
     *
     * ── WHY A SHARED TOKEN AND NOT A LOCK ───────────────────────────────
     *
     * The invariant spans documents: it is a statement about the whole set of
     * published versions, not about any one of them. Firestore attaches a
     * `currentDocument` precondition to a WRITE, so "prove these other versions
     * did not change" cannot be expressed without also writing them — and
     * writing a published record to prove it was not written is exactly the
     * mutation this architecture refuses.
     *
     * A single token document is therefore the smallest thing that can carry
     * the whole invariant's version. Publish reads it, validates, then commits
     * the version write AND the token write in one batch, with the token write
     * preconditioned on the value it read. Two concurrent publishes both read
     * token T0; the first commits and moves it to T1; the second's precondition
     * fails, the WHOLE batch rolls back, and nothing was published.
     *
     * This is deliberately not a lease: there is no TTL to tune, no expiry to
     * race, and no lock that can be left held by a process that died. The
     * failure mode is a refusal the administrator can retry, which is the same
     * shape the day editor already uses (ACAD-D20).
     */
    const COLLECTION_PUBLISH_GUARD = 'timetablePublishGuards';

    private function _publishGuardId(): string
    {
        return "{$this->schoolId}_{$this->session}";
    }

    /**
     * Read the guard and return its CAS token.
     *
     * Returns the `updateTime` to precondition on, or '' when the guard does
     * not exist yet — in which case the caller preconditions on `exists: false`
     * instead, so two publishes racing to create the FIRST one are just as
     * safe as two racing to update it.
     *
     * Fail-loud: a guard we could not read is not a guard we may proceed
     * without. Publishing on an unreadable token would be publishing with no
     * concurrency control at all, silently.
     *
     * @throws Service_exception if the guard cannot be read
     */
    private function _readPublishGuard(): string
    {
        try {
            $doc = $this->firebase->firestoreGetOrFail(
                self::COLLECTION_PUBLISH_GUARD, $this->_publishGuardId());
        } catch (\Throwable $e) {
            log_message('error', 'Timetable_plan_store: publish guard read failed (doc='
                . $this->_publishGuardId() . '): ' . $e->getMessage());
            throw new Service_exception(
                'The timetable could not be published because it was not possible to '
                . 'check whether someone else is publishing at the same time. Nothing '
                . 'was changed — please try again in a moment.',
                'read'
            );
        }

        if (!is_array($doc)) return '';                 // absent — first publish

        // Defence in depth: a guard belonging to another tenant or session must
        // never be treated as ours, because its token would then serialise the
        // wrong set of publishes.
        if ((string) ($doc['schoolId'] ?? '') !== $this->schoolId
            || (string) ($doc['session'] ?? '') !== $this->session) {
            log_message('error', 'Timetable_plan_store: publish guard '
                . $this->_publishGuardId() . ' does not belong to this tenant/session');
            throw new Service_exception(
                'The timetable could not be published. Please contact support.',
                'tenant_mismatch'
            );
        }

        return (string) ($doc['__updateTime'] ?? '');
    }

    private function _explainPublish(string $versionId, string $from, string $to, array $overlaps): string
    {
        $period = $to === '' ? "from {$from} onwards" : "from {$from} to {$to}";
        $line = "{$versionId} is published and applies {$period}.";
        if (!empty($overlaps)) {
            $names = implode(', ', array_column($overlaps, 'versionId'));
            $line .= ' It overlaps ' . count($overlaps) . ' other published timetable'
                   . (count($overlaps) === 1 ? '' : 's') . " ({$names}), so dates covered "
                   . 'by more than one will report as ambiguous until the overlap is resolved.';
        }
        return $line;
    }
    /**
     * PHASE A — validate a candidate and record the verdict.
     *
     * Reads through readCompleteCandidate(), so a version left half-written by a
     * failed run can never be validated as though it were whole — a clean
     * verdict on a partial timetable would be worse than no verdict at all.
     *
     * The verdict records a FINGERPRINT of exactly what was validated.
     * invalidateVerdict() already marks a verdict stale on manual edit, but that
     * is a best-effort write that logs and continues on failure (it must: it runs
     * after the day has been saved, and throwing there would report a successful
     * save as failed). So staleness alone cannot be trusted as the publish gate.
     * The fingerprint can: it is recomputed at publish time and compared, so a
     * verdict that does not describe the current content is rejected however the
     * stale flag ended up.
     *
     * @throws Service_exception if the candidate cannot be read or the write fails
     */
    public function validateCandidate(string $planId, string $versionId): array
    {
        $this->assertReady();

        $read    = $this->readCompleteCandidate($planId, $versionId);
        $verdict = Timetable_validation_service::validate($read['days']);

        $record = [
            'validation' => [
                'hardCount'   => $verdict['hardCount'],
                'softCount'   => $verdict['softCount'],
                'publishable' => $verdict['publishable'],
                'fingerprint' => $verdict['fingerprint'],
                'score'       => $verdict['score'],
                'scoreReason' => $verdict['scoreReason'],
                'hard'        => $verdict['hard'],
                'soft'        => $verdict['soft'],
                'validatedAt' => date('c'),
                'validatedBy' => $this->adminId,
            ],
            // The verdict just became true of the current content.
            'validationStale' => false,
        ];

        $docId = "{$this->schoolId}_{$this->session}_{$planId}_{$versionId}";
        try {
            $ok = $this->firebase->firestoreSet(self::COLLECTION_VERSIONS, $docId, $record, true);
        } catch (\Throwable $e) {
            log_message('error', 'Timetable_plan_store: verdict write failed (doc='
                . $docId . '): ' . $e->getMessage());
            throw new Service_exception(
                'The timetable was checked but the result could not be saved, so it '
                . 'has not been recorded. Please try again in a moment.',
                'write'
            );
        }
        if ($ok === false) {
            log_message('error', 'Timetable_plan_store: verdict write refused (doc=' . $docId . ')');
            throw new Service_exception(
                'The timetable was checked but the result could not be saved, so it '
                . 'has not been recorded. Please try again in a moment.',
                'write'
            );
        }

        $this->_audit('validate', 'timetableVersion', $docId, null, [
            'planId' => $planId, 'versionId' => $versionId,
            'hardCount' => $verdict['hardCount'], 'softCount' => $verdict['softCount'],
            'publishable' => $verdict['publishable'],
        ]);

        return $verdict;
    }

    /**
     * The verdict currently recorded for a version, and whether it can be TRUSTED.
     *
     * "Trusted" means proven, not asserted: the recorded fingerprint must match a
     * fresh fingerprint of the version's current days. A verdict that merely says
     * it is fresh is not enough.
     *
     * @throws Service_exception if the version or its days cannot be read
     */
    public function currentVerdict(string $planId, string $versionId): array
    {
        $this->assertReady();

        $version = $this->getVersion($planId, $versionId);
        if ($version === null) {
            throw new Service_exception(
                'That timetable version does not exist.', 'not_found');
        }

        $verdict = is_array($version['validation'] ?? null) ? $version['validation'] : null;
        if ($verdict === null) {
            return ['present' => false, 'trusted' => false, 'verdict' => null,
                    'reason' => 'This timetable has not been checked yet.'];
        }

        $days     = $this->listCandidateDays($planId, $versionId);
        $describes = Timetable_validation_service::verdictDescribes($verdict, $days);
        $flagged   = (bool) ($version['validationStale'] ?? false);

        // A recorded reason is carried through BOTH branches. The fingerprint
        // check fires first, and it used to swallow the reason entirely — so a
        // candidate whose subject assignments had changed AND whose content had
        // moved reported only the generic "it changed", losing the one piece of
        // information the operator could act on.
        $why = trim((string) ($version['staleReason'] ?? ''));

        if (!$describes) {
            return ['present' => true, 'trusted' => false, 'verdict' => $verdict,
                    'reason' => 'This timetable has changed since it was last checked, '
                              . 'so the previous result no longer describes it.'
                              . ($why !== '' ? ' ' . $why : '')];
        }
        if ($flagged) {
            // The content matches but something marked it stale. Believe the
            // stricter of the two signals, and say WHY if we recorded a reason.
            return ['present' => true, 'trusted' => false, 'verdict' => $verdict,
                    'reason' => $why !== ''
                        ? $why
                        : 'This timetable was marked as needing a fresh check.'];
        }

        return ['present' => true, 'trusted' => true, 'verdict' => $verdict, 'reason' => ''];
    }
    /**
     * PHASE 8 — CREATE A CHANGE VERSION FROM AN EXISTING VERSION.
     *
     * This is the operation the immutability guard already promises. When an
     * administrator tries to edit a published timetable, saveCandidateDay()
     * refuses with "Create a new version from it and edit that instead" — and
     * until now the repository provided no way to do that. The software told the
     * operator to perform an operation it did not implement.
     *
     * Immutability is only defensible if changing a timetable stays fast. This
     * copies the source's days into a NEW DRAFT so the administrator starts from
     * the timetable they already have and edits the few cells that changed,
     * rather than rebuilding a week from scratch. That is the PowerSchool shape:
     * a change is made as a new effective-dated version while the previous one is
     * retained, not by editing the record that is already in force.
     *
     * WHAT THIS DOES NOT DO — deliberately:
     *   - it does not publish, supersede, invalidate or unpublish the source
     *   - it does not close the source's effective period
     *   - it does not write to the source AT ALL
     *   - it does not decide when the new version takes effect
     * Those belong to the publish transition, which is an open owner decision.
     *
     * $sourceVersionId is recorded in regeneratedFrom as PROVENANCE ONLY:
     * "this version originated from that one". It confers no lifecycle meaning.
     *
     * @throws Service_exception if the source is missing, unreadable, empty, or
     *                           was left incomplete by a failed generation run
     */
    public function createChangeVersion(string $planId, string $sourceVersionId): array
    {
        $this->assertReady();

        // 1. The source must genuinely exist. getVersion() is fail-loud, so an
        //    unreadable source throws rather than looking like an absent one.
        $source = $this->getVersion($planId, $sourceVersionId);
        if ($source === null) {
            throw new Service_exception(
                'The timetable you are trying to change could not be found, so no '
                . 'new version was created.',
                'not_found'
            );
        }

        // 2. Refuse to copy a KNOWN-BROKEN source.
        //
        //    This is deliberately NOT isGenerationComplete(). That helper answers
        //    "is this usable generator output?", and returns false for a version
        //    that was never generated at all — which is exactly what a hand-built
        //    or published timetable looks like. Blocking those would make this
        //    operation useless for its main case.
        //
        //    The distinction that matters here is: was a generation ATTEMPTED and
        //    left unfinished? Never generated is fine. Running, failed, or an
        //    unrecognised state is not — copying a partial week would propagate a
        //    known defect into a fresh version and hide where it came from.
        $gen   = is_array($source['generation'] ?? null) ? $source['generation'] : [];
        $state = (string) ($gen['state'] ?? '');
        if ($state !== '' && $state !== Timetable_plan_service::GEN_COMPLETE) {
            log_message('error', 'Timetable_plan_store: refused to copy an unfinished '
                . 'source (plan=' . $planId . ' version=' . $sourceVersionId
                . ' state=' . $state . ')');
            throw new Service_exception(
                'That timetable was not generated completely, so it cannot be used as '
                . 'the starting point for a new version. Generate it again first.',
                'incomplete'
            );
        }

        // 3. Read the source days FAIL-LOUD. An unreadable day set must never be
        //    mistaken for an empty one — that would silently produce a change
        //    version missing days the administrator believes they copied.
        $days = $this->listCandidateDays($planId, $sourceVersionId);

        // 4. A timetable with no days is nothing to change. Producing an empty
        //    draft and reporting success is the false-success shape this codebase
        //    has been cleared of elsewhere, so this refuses instead.
        if (empty($days)) {
            throw new Service_exception(
                'That timetable has no days in it yet, so there is nothing to copy '
                . 'into a new version.',
                'empty_source'
            );
        }

        // 5. Create the NEW version. createDraftVersion() forces status=draft and
        //    forces the tenant and session, so neither can be inherited from the
        //    source nor supplied by a caller.
        //
        //    It gets a placeholder effective period and NO claim about when it
        //    applies: copying the source's dates would make two versions claim the
        //    same period the moment either is published. A draft never resolves,
        //    so the placeholder is inert until the publish transition decides.
        $versionId = 'CHG_' . date('YmdHis') . '_' . substr(uniqid(), -6);

        $created = $this->createDraftVersion([
            'planId'          => $planId,
            'versionId'       => $versionId,
            'effectiveFrom'   => date('Y-m-d'),
            'effectiveTo'     => '',
            'regeneratedFrom' => $sourceVersionId,
        ]);

        // 6. Copying N days is a multi-write run with exactly the partial-failure
        //    risk ACAD-D24 describes, so it reuses the SAME run lifecycle as
        //    generation rather than growing a second one. A half-copied version is
        //    marked failed and readCompleteCandidate() will refuse it.
        $runId = $this->beginGenerationRun($planId, $versionId, [
            'kind'       => 'change_version_copy',
            'copiedFrom' => $sourceVersionId,
            'dayCount'   => count($days),
        ]);

        $written = 0;
        foreach ($days as $d) {
            try {
                $this->saveCandidateDay(
                    $planId, $versionId,
                    (string) ($d['classSection'] ?? ''),
                    (string) ($d['day'] ?? ''),
                    is_array($d['periods'] ?? null) ? $d['periods'] : [],
                    // Provenance is copied, not fabricated. A day a human tuned in
                    // the source is still a human decision in the copy; flattening
                    // it to false would destroy the very record that stops a later
                    // generation run from silently discarding that work.
                    (bool) ($d['manuallyEdited'] ?? false),
                    $runId
                );
            } catch (\Throwable $e) {
                $this->failGenerationRun($planId, $versionId, $runId, $written,
                    'change-version copy failed: ' . $e->getMessage());

                throw new Service_exception(
                    'Copying the timetable stopped part-way and the incomplete new '
                    . 'version has been marked unusable. The timetable you were '
                    . 'copying from was not changed. Please try again.',
                    'copy_failed'
                );
            }
            $written++;
        }

        $this->completeGenerationRun($planId, $versionId, $runId, $written);

        return [
            'versionId'   => $versionId,
            'docId'       => $created['docId'],
            'version'     => $created['version'],
            'copiedFrom'  => $sourceVersionId,
            'days_copied' => $written,
            'runId'       => $runId,
        ];
    }

    /**
     * Create a NEW candidate version for a generation run — the Option E core.
     *
     * Regeneration never rewrites an edited candidate. It creates a fresh one
     * beside it, so the previous candidate is not merely "protected by a rule"
     * but untouched by construction: generation is pointed at a version that did
     * not exist a moment ago and that nothing else references.
     *
     * That is why ZenX does not need the per-cell locking aSc and Untis rely on.
     * Those products optimise ONE working timetable in place, so a lock is their
     * only way to protect a human decision. ZenX has versions, so the protection
     * comes from the architecture instead of from a flag the operator must
     * remember to set — and aSc warns that over-locking makes generation
     * infeasible.
     *
     * $fromVersionId is recorded ONLY as provenance: "this candidate was produced
     * by regenerating from that one". It confers nothing. It does not supersede,
     * replace, invalidate or inherit from the source, because what a lineage link
     * MEANS beyond provenance is an open product question and is not decided here.
     *
     * @throws Service_exception if the source version cannot be read
     */
    public function createGenerationCandidate(string $planId, ?string $fromVersionId = null): array
    {
        $this->assertReady();

        // Provenance must be real. Recording a source that cannot be read would
        // be a claim we never verified.
        if ($fromVersionId !== null && $fromVersionId !== '') {
            $source = $this->getVersion($planId, $fromVersionId);
            if ($source === null) {
                throw new Service_exception(
                    'The timetable you are regenerating from could not be found, so no '
                    . 'new candidate was created.',
                    'not_found'
                );
            }
        }

        $versionId = 'CAND_' . date('YmdHis') . '_' . substr(uniqid(), -6);
        $docId     = "{$this->schoolId}_{$this->session}_{$planId}_{$versionId}";

        $version = [
            'schoolId'  => $this->schoolId,   // never caller-supplied
            'session'   => $this->session,
            'planId'    => $planId,
            'versionId' => $versionId,
            'status'    => Timetable_plan_service::STATUS_CANDIDATE,
            // Effective dating is a publish-time concern. A candidate is not live,
            // so it carries a placeholder range and NO claim about when it applies.
            'effectiveFrom' => date('Y-m-d'),
            'effectiveTo'   => '',
            'regeneratedFrom' => ($fromVersionId !== null && $fromVersionId !== '')
                ? $fromVersionId : null,
            'createdAt'    => date('c'),
            'createdByUid' => $this->adminId,
        ];

        $errors = Timetable_plan_service::validateVersion($version);
        if (!empty($errors)) {
            throw new Service_exception(implode('; ', $errors), 'validation');
        }

        try {
            $ok = $this->firebase->firestoreSet(self::COLLECTION_VERSIONS, $docId, $version, false);
        } catch (\Throwable $e) {
            log_message('error', 'Timetable_plan_store: candidate creation failed (doc='
                . $docId . '): ' . $e->getMessage());
            throw new Service_exception(
                'A new timetable candidate could not be created, so nothing was '
                . 'generated. Please try again in a moment.',
                'write'
            );
        }
        if ($ok === false) {
            log_message('error', 'Timetable_plan_store: candidate creation refused (doc=' . $docId . ')');
            throw new Service_exception(
                'A new timetable candidate could not be created, so nothing was '
                . 'generated. Please try again in a moment.',
                'write'
            );
        }

        $this->_audit('create', 'timetableVersion', $docId, null, [
            'planId' => $planId, 'versionId' => $versionId,
            'status' => Timetable_plan_service::STATUS_CANDIDATE,
            'regeneratedFrom' => $version['regeneratedFrom'],
        ]);

        return ['versionId' => $versionId, 'docId' => $docId, 'version' => $version];
    }

    // ─────────────────────────────────────────────────────────────────────    //  Phase 6 groundwork — generation-run lifecycle (ACAD-D24 at candidate level)
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Mark a candidate as being generated INTO.
     *
     * From this moment the candidate is explicitly incomplete. If the process
     * dies here — a fatal error, a timeout, a lost connection — the candidate
     * stays incomplete forever rather than reverting to something that looks
     * finished. That is the point: the failure mode is a candidate nobody can
     * use, not a half-timetable somebody publishes.
     */
    public function beginGenerationRun(string $planId, string $versionId, array $context = []): string
    {
        $this->assertReady();
        $version = $this->requireEditableVersion($planId, $versionId);

        $runId = 'GEN_' . date('YmdHis') . '_' . substr(uniqid(), -6);
        $this->stampGeneration($planId, $versionId, [
            'runId'      => $runId,
            'state'      => Timetable_plan_service::GEN_RUNNING,
            'startedAt'  => date('c'),
            'startedBy'  => $this->adminId,
            'context'    => $context,
            'daysWritten'=> 0,
        ]);

        return $runId;
    }

    /**
     * Mark the run finished. Only now may the candidate be read back for
     * validation, comparison or (later) publishing.
     */
    public function completeGenerationRun(string $planId, string $versionId, string $runId, int $daysWritten): void
    {
        $this->assertReady();
        $this->requireEditableVersion($planId, $versionId);

        $this->stampGeneration($planId, $versionId,
            $this->_closeRun($planId, $versionId, $runId, [
                'state'       => Timetable_plan_service::GEN_COMPLETE,
                'completedAt' => date('c'),
                'daysWritten' => $daysWritten,
            ]));
    }

    /**
     * Record that a run stopped part-way.
     *
     * The days already written are LEFT IN PLACE deliberately — they are useful
     * for diagnosis, and deleting them would be a second partial write on top of
     * the first. What matters is that the candidate can never be mistaken for a
     * finished one, which the state field guarantees.
     */
    public function failGenerationRun(string $planId, string $versionId, string $runId, int $daysWritten, string $reason): void
    {
        $this->assertReady();

        $this->stampGeneration($planId, $versionId,
            $this->_closeRun($planId, $versionId, $runId, [
                'state'       => Timetable_plan_service::GEN_FAILED,
                'failedAt'    => date('c'),
                'daysWritten' => $daysWritten,
                'reason'      => $reason,
            ]));
    }

    /**
     * ACAD-D28 / ACAD-D29 — close a run WITHOUT erasing it.
     *
     * stampGeneration() writes the whole `generation` map, so a naive close
     * replaced it and silently discarded startedAt, startedBy and context — the
     * only record of WHO populated a version and WHAT the run was. Nothing read
     * those after completion, so the loss was invisible: a completed version
     * could not be told apart from a copy and carried no operator attribution.
     * beginGenerationRun() still replaces deliberately — a NEW run must not
     * inherit the previous run's completedAt, or a running version would look
     * finished.
     *
     * It also refuses to close a run that is no longer the current one. Stamping
     * COMPLETE from a superseded run would mark a DIFFERENT run's work complete:
     * the same "a late writer overwrites a newer one" shape as ACAD-D26, applied
     * to the status record rather than to the days.
     *
     * @throws Service_exception if the run is no longer the current one
     */
    private function _closeRun(string $planId, string $versionId, string $runId, array $fields): array
    {
        $version = $this->getVersion($planId, $versionId);
        $prior   = (is_array($version) && is_array($version['generation'] ?? null))
            ? $version['generation'] : [];

        $currentRunId = (string) ($prior['runId'] ?? '');
        if ($currentRunId !== '' && $currentRunId !== $runId) {
            log_message('error', 'Timetable_plan_store: refused to close a stale run '
                . '(plan=' . $planId . ' version=' . $versionId
                . ' run=' . $runId . ' current=' . $currentRunId . ')');
            throw new Service_exception(
                'This timetable was started again while the previous attempt was '
                . 'still finishing, so the older attempt was discarded rather than '
                . 'recorded over the newer one.',
                'stale_run'
            );
        }

        // Prior fields first, so startedAt/startedBy/context survive the close
        // while the new state still wins.
        return array_merge($prior, $fields, ['runId' => $runId]);
    }
    /**
     * Read a candidate back ONLY if its generation finished.
     *
     * This is where ACAD-D24 is actually contained. listCandidateDays() returns
     * whatever rows exist and cannot know whether they are all of them; this
     * refuses unless the run said so. Anything downstream that validates,
     * compares or publishes a candidate must come through here.
     *
     * @throws Service_exception when the candidate is incomplete
     */
    public function readCompleteCandidate(string $planId, string $versionId): array
    {
        $this->assertReady();

        $version = $this->getVersion($planId, $versionId);
        if ($version === null) {
            throw new Service_exception(
                'That timetable version does not exist.', 'not_found');
        }

        if (!Timetable_plan_service::isGenerationComplete($version)) {
            $gen   = is_array($version['generation'] ?? null) ? $version['generation'] : [];
            $state = (string) ($gen['state'] ?? 'never generated');
            log_message('error', 'Timetable_plan_store: refused to read an incomplete '
                . 'candidate (plan=' . $planId . ' version=' . $versionId
                . ' state=' . $state . ')');
            throw new Service_exception(
                'This timetable was not generated completely, so it cannot be used. '
                . 'Generate it again — the partial result has been kept only so the '
                . 'failure can be investigated.',
                'incomplete'
            );
        }

        return [
            'version' => $version,
            'days'    => $this->listCandidateDays($planId, $versionId),
        ];
    }

    /** Shared guard: the version must exist and still be editable. */
    private function requireEditableVersion(string $planId, string $versionId): array
    {
        $version = $this->getVersion($planId, $versionId);
        if ($version === null) {
            throw new Service_exception(
                'That timetable version does not exist, so nothing was generated.',
                'not_found'
            );
        }

        $status = (string) ($version['status'] ?? '');
        if ($status !== Timetable_plan_service::STATUS_DRAFT
            && $status !== Timetable_plan_service::STATUS_CANDIDATE) {
            throw new Service_exception(
                'This timetable has already been published, so it cannot be generated '
                . 'into. Create a new version from it instead.',
                'immutable'
            );
        }
        return $version;
    }

    /**
     * Merge-write the generation block. Merge, not replace: the version owns its
     * effective dates, lineage and status, and a run must never overwrite them.
     */
    private function stampGeneration(string $planId, string $versionId, array $generation): void
    {
        $docId = "{$this->schoolId}_{$this->session}_{$planId}_{$versionId}";

        try {
            $ok = $this->firebase->firestoreSet(self::COLLECTION_VERSIONS, $docId, [
                'generation' => $generation,
            ], true);
        } catch (\Throwable $e) {
            log_message('error', 'Timetable_plan_store: generation stamp failed (doc='
                . $docId . '): ' . $e->getMessage());
            throw new Service_exception(
                'The generation status could not be recorded, so generation was not '
                . 'started. Nothing was changed — please try again in a moment.',
                'write'
            );
        }

        // firestoreSet returns bool and never throws. An unrecorded RUNNING stamp
        // would let a failed run leave a candidate that still looks complete.
        if ($ok === false) {
            log_message('error', 'Timetable_plan_store: generation stamp refused (doc=' . $docId . ')');
            throw new Service_exception(
                'The generation status could not be recorded, so generation was not '
                . 'started. Nothing was changed — please try again in a moment.',
                'write'
            );
        }
    }

    // ─────────────────────────────────────────────────────────────────────

    private function assertReady(): void
    {
        if (!$this->ready) {
            throw new Service_exception(
                'Timetable plan store is not initialised for a school and session.',
                'not_ready'
            );
        }
    }

    private function _audit(string $action, string $entity, string $id, ?array $before, ?array $after): void
    {
        if (!$this->audit) return;
        try { $this->audit->log($action, $entity, $id, $before, $after, []); }
        catch (\Throwable $e) {
            log_message('error', 'timetable plan audit failed: ' . $e->getMessage());
        }
    }
}
