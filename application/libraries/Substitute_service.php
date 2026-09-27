<?php
defined('BASEPATH') OR exit('No direct script access allowed');

require_once APPPATH . 'libraries/Service_exception.php';
require_once APPPATH . 'libraries/Entity_firestore_sync.php';
require_once APPPATH . 'libraries/Teaching_staff.php';

/**
 * Substitute_service — owns substitute teacher record CRUD.
 *
 * Phase 5: extracted from Academic.php with ZERO behavior change.
 * Phase 0 invariants preserved:
 *   - Pure-read get_substitutes (no read-side mutation)
 *   - Canonical schoolId (not school_name) in conflict-check query
 *   - camelCase canonical fields with snake_case mirrors in API response
 *   - Class/section normalization via Entity_firestore_sync
 *   - id-derivation from docId for legacy docs
 * Phase 4 audit logging on every mutation.
 */
class Substitute_service
{
    /** @var object */ private $firebase;
    /** @var object */ private $fs;
    /** @var string */ private $schoolId  = '';
    /** @var string */ private $session   = '';
    /** @var string */ private $adminId   = '';
    /** @var string */ private $adminName = '';
    /** @var object|null */ private $audit = null;
    /** @var bool   */ private $ready = false;

    const COLLECTION = 'substitutes';

    /**
     * Page size for the own-timetable busy-map scan. One doc per class-section
     * for the day, so this is a ceiling on class-sections, not on periods.
     * Reaching it is treated as "cannot evaluate" and denies — see the scan.
     */
    const BUSY_SCAN_LIMIT = 100;

    /**
     * Page size for the already-arranged-cover scan on a single date.
     * Saturation denies, for the same reason BUSY_SCAN_LIMIT does.
     */
    const EXISTING_SUB_SCAN_LIMIT = 200;

    /**
     * Page size for the list the screen shows.
     *
     * Saturation does NOT refuse here, unlike the two scans above. Those are
     * deciding whether a cover may be arranged and must see everything; this
     * one is only showing what is arranged, so it shows the most recent page
     * and says out loud that the list is not all of it.
     */
    const LIST_SCAN_LIMIT = 200;

    /**
     * One document per (school, session, date), holding nothing anybody reads.
     *
     * It exists so that two saves for the SAME DATE cannot both commit against
     * the same snapshot of the covers already arranged. It is a serialisation
     * point, not a record of the event: the substitute documents remain the only
     * account of who is covering what.
     */
    const COLLECTION_COVER_DAY = 'substituteCoverDays';

    /**
     * The school's own cover policy, one document per (school, session).
     *
     * There is no universal ceiling on how much cover one teacher may be
     * given. England's STPCD requires that teachers cover "only rarely" and
     * deliberately sets NO number, leaving each school to write its policy and
     * keep a cover log so the burden is shared fairly over the year. CBSE's
     * bye-laws cap total teaching load at three quarters of the weekly periods
     * — a weekly limit on teaching, not a daily one on cover. So ZenX holds
     * the numbers the school chose and enforces nothing where nothing is set:
     * a default ceiling would impose one school's policy on all the others.
     */
    const COLLECTION_POLICY = 'substitutePolicy';

    /**
     * Page size for the week's covers, read only when a weekly cap is set.
     * A week of cover for a large school stays well inside this; saturation
     * refuses, because a half-read week cannot prove a cap is respected.
     */
    const WEEK_SCAN_LIMIT = 400;

    /**
     * Page size for the cover log. Larger than a week's scan because a log is
     * usually asked for over a term; a shortened log says so rather than
     * refusing, because it is being read, not enforced.
     */
    const LOG_SCAN_LIMIT = 1000;

    /**
     * The platform's Academic audit trail — written by Audit_log_service,
     * which every Academic surface already uses. Read here for one cover's
     * history; the (schoolId, entityId, ts DESC) index it needs already
     * exists, because the collection was always meant to be readable.
     */
    const COLLECTION_AUDIT = 'academicAuditLog';

    /** Changes shown for one cover. More than this is not a cover, it is a saga. */
    const HISTORY_SCAN_LIMIT = 100;

    /**
     * Page sizes for working out who is away. All three refuse on saturation
     * rather than shortening: a teacher missing from the absent list is a
     * class nobody arranges cover for.
     */
    const STAFF_SCAN_LIMIT = 500;
    const LEAVE_SCAN_LIMIT = 500;
    const MARK_SCAN_LIMIT  = 500;

    public function init(
        $firebase, $fs,
        string $schoolId, string $session,
        string $adminId, string $adminName,
        $auditLogService = null
    ): self {
        $this->firebase  = $firebase;
        $this->fs        = $fs;
        $this->schoolId  = $schoolId;
        $this->session   = $session;
        $this->adminId   = $adminId;
        $this->adminName = $adminName;
        $this->audit     = $auditLogService;
        $this->ready     = ($firebase !== null && $schoolId !== '');
        return $this;
    }

    public function isReady(): bool { return $this->ready; }

    // ══════════════════════════════════════════════════════════════════

    public function getSubstitutes(string $date = '', string $dateFrom = '', string $dateTo = ''): array
    {
        // ── AN EMPTY DAY AND AN UNREADABLE ONE ARE NOT THE SAME ──────────
        //
        // This list is what an administrator looks at before deciding who
        // covers the next absence, so "nothing is arranged" is an answer they
        // act on. The read used to swallow every failure — including the
        // helper simply not being there — and return an empty list, which
        // reads on screen as a day with no cover on it.
        //
        // The date range is also asked of the database rather than filtered
        // out of whatever page came back first: filtering afterwards meant a
        // school with more covers than one page got its report taken over the
        // wrong page, and a week that certainly had cover could come back
        // empty.
        $conditions = [
            ['schoolId', '==', $this->schoolId],
            ['session',  '==', $this->session],
        ];
        if ($date !== '') {
            $conditions[] = ['date', '==', $date];
        } else {
            if ($dateFrom !== '') { $conditions[] = ['date', '>=', $dateFrom]; }
            if ($dateTo   !== '') { $conditions[] = ['date', '<=', $dateTo];   }
        }

        try {
            $fsDocs = $this->firebase->firestoreQueryOrFail(
                self::COLLECTION, $conditions, 'date', 'DESC', self::LIST_SCAN_LIMIT + 1);
        } catch (\Throwable $e) {
            log_message('error', 'Substitute_service: cover list query failed (school='
                . $this->schoolId . ' session=' . $this->session . ' date=' . $date
                . '): ' . $e->getMessage());
            throw new Service_exception(
                'The cover arranged for these dates could not be read, so none of '
                . 'it is shown. Please try again in a moment — do not arrange cover '
                . 'until you can see what is already in place.',
                'read'
            );
        }

        $truncated = count($fsDocs) > self::LIST_SCAN_LIMIT;
        if ($truncated) { $fsDocs = array_slice($fsDocs, 0, self::LIST_SCAN_LIMIT); }

        $records = [];
        $prefix  = $this->schoolId . '_';
        foreach ($fsDocs as $doc) {
            $d = is_array($doc['data'] ?? null) ? $doc['data'] : $doc;
            $docKey = $doc['id'] ?? '';
            if ($docKey !== '' && strpos($docKey, $prefix) === 0) {
                $d['id'] = substr($docKey, strlen($prefix));
            } elseif (empty($d['id'])) {
                $d['id'] = $docKey;
            }
            $records[] = $this->_responseShape($d);
        }

        usort($records, fn($a, $b) => strcmp($b['date'] ?? '', $a['date'] ?? ''));

        // ── A COVER IS AGREED AGAINST A PARTICULAR TIMETABLE ─────────────
        //
        // Publishing a new timetable rewrites the day, and nothing in that
        // path looks at cover already arranged for the dates it affects. The
        // cover keeps the period, class and subject it was agreed on, and
        // says nothing — so the screen goes on presenting it as though the
        // timetable still agreed.
        //
        // What to DO about such a cover is the school's decision: cancel it,
        // re-arrange it, or leave it. This only points at it. Re-pointing
        // somebody's cover at a period they never agreed to cover would be
        // worse than leaving it visibly wrong.
        //
        // Only a single day can be judged: the check needs that day's
        // timetable, and a range spans weekdays it has not read.
        $timetableChecked = false;
        if ($date !== '' && !empty($records)) {
            $grid = $this->_dayGridFor($date);
            if ($grid !== null) {
                $timetableChecked = true;
                foreach ($records as &$rec) {
                    $rec = $this->_markDrift($rec, $grid);
                }
                unset($rec);
            }
        }

        return [
            'substitutes' => $records,
            // False means NOT CHECKED, never "nothing changed": a timetable
            // nobody could read is not evidence that everything still agrees.
            'timetableChecked' => $timetableChecked,
            'truncated'   => $truncated,
            'notice'      => $truncated
                ? 'Showing the ' . self::LIST_SCAN_LIMIT . ' most recent covers. '
                . 'There are more than this — narrow the dates to see the rest.'
                : '',
        ];
    }

    public function saveSubstitute(
        string $id, string $dateStr,
        string $absentId, string $absentName,
        $assignmentsRaw,
        string $reason,
        // Legacy flat-format fallback fields:
        string $substituteId = '', string $substituteName = '',
        string $classSectionLegacy = '', string $subjectLegacy = '',
        $periodsRawLegacy = null
    ): array {
        if ($dateStr === '' || $absentId === '') {
            throw new Service_exception('Date and absent teacher are required', 'validation');
        }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateStr) || !strtotime($dateStr)) {
            throw new Service_exception('Invalid date format', 'validation');
        }

        $assignments = is_string($assignmentsRaw) ? json_decode($assignmentsRaw, true) : $assignmentsRaw;

        if (!is_array($assignments) || empty($assignments)) {
            $periods = is_string($periodsRawLegacy) ? json_decode($periodsRawLegacy, true) : $periodsRawLegacy;
            if (!is_array($periods)) $periods = [];
            $periods = array_values(array_unique(array_filter(array_map('intval', $periods), fn($p) => $p >= 1)));

            if ($substituteId === '' || $classSectionLegacy === '' || empty($periods)) {
                throw new Service_exception('Assignments data or legacy fields (substitute, class, periods) required', 'validation');
            }
            if ($absentId === $substituteId) {
                throw new Service_exception('Absent teacher and substitute cannot be the same person', 'validation');
            }

            $assignments = [];
            foreach ($periods as $pn) {
                $assignments[] = [
                    'periodNumber'          => $pn,
                    'subject'               => $subjectLegacy,
                    'className'             => $classSectionLegacy,
                    'section'               => '',
                    'substituteTeacherId'   => $substituteId,
                    'substituteTeacherName' => $substituteName,
                ];
            }
        }

        $dt = new \DateTime($dateStr);
        $dayOfWeek = $dt->format('l');

        // FIX B-8: query timetables by canonical school_id.
        // ACAD-D12: $busyMap is the ONLY guard stopping a substitute being booked
        // into a period where they already teach their own class. It is read
        // NEGATIVELY below — absence from the map means "free" — so an EMPTY map
        // approves every assignment.
        //
        // firestoreQuery() returns [] for a missing composite index, a network
        // failure, 403 and 429 alike; none of those throw, so the old empty catch
        // never fired and the map was indistinguishable from "nobody is busy".
        // A guard that cannot evaluate must DENY — same rule as ACAD-D5.
        // ── WHY THIS SAVE IS SERIALISED PER DATE ─────────────────────────
        //
        // Every clash check below runs against a snapshot read now, and the
        // write that follows used to be unconditional. Two administrators
        // arranging cover for the same date at the same time therefore both
        // saw the teacher as free, both passed every check, and both wrote —
        // one teacher booked into two classrooms, and the class that lost was
        // simply unattended. Nobody was told.
        //
        // The cover-day document is read here and committed WITH the record
        // below, conditional on not having moved. A concurrent save for the
        // same date moves it, so the loser is refused instead of writing on
        // top of a world that changed underneath it. Different dates touch
        // different documents and never block each other.
        [$coverDayId, $coverDayAt] = $this->_coverDayToken($dateStr);

        $busyMap = $this->_busyMapFor($dayOfWeek);

        // ACAD-D12 (second arm) — $existingSubs is the guard against booking the
        // SAME substitute into two covers in one period. It is read negatively,
        // exactly like $busyMap above: a teacher absent from this list is treated
        // as free. sessionWhere() routes to firestoreQuery(), which returns [] on
        // a failed read, so the old empty catch let every clash through.
        //
        // Scoped explicitly to school + session here because the fail-loud reader
        // sits below the sessionWhere() helper; the filters must therefore be
        // spelled out to keep tenant isolation identical.
        $existingSubs = $this->_activeCoversOn($dateStr, $id);

        $cleanAssignments = [];
        foreach ($assignments as $a) {
            $pn    = (int) ($a['periodNumber'] ?? 0);
            $subId = trim($a['substituteTeacherId']   ?? $a['substitute_teacher_id']   ?? '');
            $subNm = trim($a['substituteTeacherName'] ?? $a['substitute_teacher_name'] ?? '');
            if ($pn < 1 || $subId === '') continue;
            if ($subId === $absentId) {
                throw new Service_exception("Cannot assign absent teacher as substitute for Period {$pn}", 'validation');
            }
            if (isset($busyMap[$subId][$pn])) {
                throw new Service_exception("{$subNm} already teaches their own class during Period {$pn} on {$dayOfWeek}", 'conflict');
            }
            foreach ($existingSubs as $ex) {
                $exAssigns = $ex['assignments'] ?? [];
                if (!empty($exAssigns)) {
                    foreach ($exAssigns as $ea) {
                        $eaSubId = $ea['substituteTeacherId'] ?? $ea['substitute_teacher_id'] ?? '';
                        if ((int)($ea['periodNumber'] ?? 0) === $pn && $eaSubId === $subId) {
                            throw new Service_exception("{$subNm} is already covering another substitution at Period {$pn} on this date", 'conflict');
                        }
                    }
                }
                $exFlatSubId = $ex['substituteTeacherId'] ?? $ex['substitute_teacher_id'] ?? '';
                if ($exFlatSubId === $subId) {
                    $exPeriods = $ex['periods'] ?? [];
                    if (is_array($exPeriods) && in_array($pn, $exPeriods)) {
                        throw new Service_exception("{$subNm} is already covering another substitution at Period {$pn} on this date", 'conflict');
                    }
                }
            }
            foreach ($existingSubs as $ex) {
                $exAbsent = $ex['absentTeacherId'] ?? $ex['absent_teacher_id'] ?? '';
                if ($exAbsent !== $absentId) continue;
                $exAssigns = $ex['assignments'] ?? [];
                if (!empty($exAssigns)) {
                    foreach ($exAssigns as $ea) {
                        if ((int)($ea['periodNumber'] ?? 0) === $pn) {
                            throw new Service_exception("Period {$pn} for this teacher is already covered by another substitute record", 'conflict');
                        }
                    }
                }
                $exPeriods = $ex['periods'] ?? [];
                if (is_array($exPeriods) && in_array($pn, $exPeriods)) {
                    throw new Service_exception("Period {$pn} for this teacher is already covered by another substitute record", 'conflict');
                }
            }

            $rawClass = trim($a['className'] ?? '');
            $rawSec   = trim($a['section']   ?? '');
            if ($rawSec === '' && strpos($rawClass, '/') !== false) {
                $slash = strrpos($rawClass, '/');
                $rawSec   = trim(substr($rawClass, $slash + 1));
                $rawClass = trim(substr($rawClass, 0, $slash));
            }
            $cs = \Entity_firestore_sync::normalizeClassSection($rawClass, $rawSec);
            $cleanClass   = $cs['className']  !== '' ? $cs['className']  : $rawClass;
            $cleanSection = $cs['section']    !== '' ? $cs['section']    : $rawSec;
            $sectionKey   = ($cleanClass !== '' && $cleanSection !== '')
                ? "{$cleanClass}/{$cleanSection}" : '';

            $cleanAssignments[] = [
                'periodNumber'          => $pn,
                'subject'               => trim($a['subject'] ?? ''),
                'className'             => $cleanClass,
                'section'               => $cleanSection,
                'classOrder'            => $cs['classOrder'],
                'sectionCode'           => $cs['sectionCode'],
                'sectionKey'            => $sectionKey,
                'substituteTeacherId'   => $subId,
                'substituteTeacherName' => $subNm,
            ];
        }

        if (empty($cleanAssignments)) {
            throw new Service_exception('No valid period assignments found', 'validation');
        }
        usort($cleanAssignments, fn($a, $b) => $a['periodNumber'] - $b['periodNumber']);

        // ── WHO THESE PEOPLE ACTUALLY ARE ────────────────────────────────
        //
        // Every identifier and every name here arrived from the browser. The
        // clash checks reason about the ids exhaustively but never established
        // that they belong to anybody, so a crafted request could book
        // "STA_NOBODY" under any name it liked: stored, shown on the day,
        // counted in the cover log, pushed at a device that does not exist —
        // a class with nobody in front of it. The panel only ever sends real
        // staff; the panel is not the security boundary.
        //
        // Existence, not employment: somebody who has since left is still who
        // they were, and a cover arranged before they left has to stay
        // correctable.
        $known = $this->_staffRecords(array_merge(
            [$absentId],
            array_map(static fn($a) => (string) $a['substituteTeacherId'], $cleanAssignments)
        ));

        if (!isset($known[$absentId])) {
            throw new Service_exception(
                'That absent teacher has no staff record at this school, so no '
                . 'cover was arranged.',
                'validation'
            );
        }

        foreach ($cleanAssignments as $k => $a) {
            $sid = (string) $a['substituteTeacherId'];
            if (!isset($known[$sid])) {
                throw new Service_exception(
                    trim((string) $a['substituteTeacherName']) !== ''
                        ? $a['substituteTeacherName'] . ' has no staff record at this '
                          . 'school, so no cover was arranged.'
                        : 'One of the chosen teachers has no staff record at this '
                          . 'school, so no cover was arranged.',
                    'validation'
                );
            }

            // The name is what everybody sees — on the day, in the cover log,
            // in the notification. It comes from the staff record, not from
            // whatever the request claimed. A record carrying no name at all
            // leaves the submitted one alone rather than blanking the row.
            $real = \Teaching_staff::name($known[$sid]);
            if ($real !== '') { $cleanAssignments[$k]['substituteTeacherName'] = $real; }
        }

        $realAbsent = \Teaching_staff::name($known[$absentId]);
        if ($realAbsent !== '') { $absentName = $realAbsent; }

        // The clash checks above answer "can this person physically be here?".
        // This answers "does this school allow it?" — a different question, and
        // one only the school can answer. It runs last because it is the most
        // expensive and the least likely to fail.
        $this->_enforcePolicy($cleanAssignments, $existingSubs, $dateStr, $id, $known);

        $now = date('c');
        $data = [
            'date'              => $dateStr,
            'dayOfWeek'         => $dayOfWeek,
            'absentTeacherId'   => $absentId,
            'absentTeacherName' => $absentName,
            'assignments'       => $cleanAssignments,
            'reason'            => $reason,
            'updatedAt'         => $now,
            'updatedByUid'      => $this->adminId,
            'updatedByName'     => $this->adminName,
        ];

        $current = null;
        if ($id !== '') {
            $fsDocId = "{$this->schoolId}_{$id}";
            // ACAD-D17: this null decides CREATE vs UPDATE below. firestoreGet()
            // returns null for a FAILED read exactly as it does for a genuine 404,
            // so a transient failure made an existing record look new: version reset
            // to 1, createdAt/createdBy overwritten, and an audit entry written that
            // says 'create' for what was an update. Fail-loud: null now means ABSENT.
            try { $current = $this->firebase->firestoreGetOrFail(self::COLLECTION, $fsDocId); }
            catch (\Throwable $e) {
                log_message('error', 'Substitute_service: existing-record read failed (doc='
                    . $fsDocId . '): ' . $e->getMessage());
                throw new Service_exception(
                    'Could not read the cover you are editing, so nothing was saved. '
                    . 'Please try again in a moment.',
                    'read'
                );
            }
        } else {
            $id      = 'SUB_' . uniqid();
            $fsDocId = "{$this->schoolId}_{$id}";
        }

        if (is_array($current)) {
            $data['status']        = $current['status']        ?? 'assigned';
            $data['createdAt']     = $current['createdAt']     ?? $current['created_at'] ?? $now;
            $data['createdByUid']  = $current['createdByUid']  ?? $this->adminId;
            $data['createdByName'] = $current['createdByName'] ?? $current['created_by'] ?? $this->adminName;
            $data['version']       = ((int)($current['version'] ?? 0)) + 1;
        } else {
            $data['status']        = 'assigned';
            $data['createdAt']     = $now;
            $data['createdByUid']  = $this->adminId;
            $data['createdByName'] = $this->adminName;
            $data['version']       = 1;
        }

        $fsData = array_merge($data, [
            'id'       => $id,
            'schoolId' => $this->schoolId,
            'session'  => $this->session,
        ]);
        // The record and the serialisation point commit together. If another
        // save for this date landed while the checks above were running, the
        // cover-day document has moved and this whole commit is refused —
        // nothing is half-written.
        $commit = $this->firebase->firestoreCommitBatchStatus([
            [
                'collection' => self::COLLECTION,
                'docId'      => $fsDocId,
                'data'       => $fsData,
                'merge'      => true,
            ],
            $this->_coverDayOp($coverDayId, $coverDayAt, $dateStr, $now),
        ]);

        if ((string) ($commit['status'] ?? '') === 'PRECONDITION_CONFLICT') {
            log_message('error', 'Substitute_service: cover-day CAS conflict (doc='
                . $coverDayId . ' admin=' . $this->adminId . ')');
            throw new Service_exception(
                'The cover for this date changed while you were arranging it, so '
                . 'nothing was saved — saving it could have booked the same '
                . 'teacher twice. Reload the day and try again.',
                'conflict'
            );
        }

        $this->_audit(
            is_array($current) ? 'update' : 'create', 'substitute', $id,
            is_array($current) ? [
                'status'           => (string)($current['status'] ?? ''),
                'assignmentsCount' => is_array($current['assignments'] ?? null) ? count($current['assignments']) : 0,
            ] : null,
            ['status' => $data['status'], 'date' => $dateStr, 'absentTeacherId' => $absentId, 'assignmentsCount' => count($cleanAssignments)],
            []
        );

        // WHO HAS TO BE TOLD. Taken from what was PERSISTED, never from what
        // was submitted: an assignment the save dropped must not produce a
        // notification telling somebody they are covering a lesson that was
        // never recorded. `removed` is the other half nobody used to send —
        // an edit that moves a period to a different teacher left the first
        // one believing they were still covering it.
        $nowCovering = $this->_coveringIds($cleanAssignments);
        $wasCovering = is_array($current)
            ? $this->_coveringIds($current['assignments'] ?? [])
            : [];

        return [
            'id'                   => $id,
            'assignments_count'    => count($cleanAssignments),
            'version'              => $data['version'],
            'date'                 => $dateStr,
            'substituteTeacherIds' => $nowCovering,
            'removedTeacherIds'    => array_values(array_diff($wasCovering, $nowCovering)),
        ];
    }

    public function updateSubstituteStatus(string $id, string $status): array
    {
        if ($id === '') throw new Service_exception('Substitute ID required', 'validation');
        if (!in_array($status, ['assigned','completed','cancelled'])) {
            throw new Service_exception('Invalid status', 'validation');
        }

        $now = date('c');
        $fsDocId = "{$this->schoolId}_{$id}";

        // Reads the record first, so a cover belonging to another session or
        // to nothing at all is refused instead of being merge-written into
        // existence — a merge on an unknown id used to create a document
        // holding a status and no cover, which then showed on the day as an
        // empty row nobody could explain.
        $current = $this->_loadOwnedCover($id);
        $was     = (string) ($current['status'] ?? '');
        $newVersion = (int) ($current['version'] ?? 0) + 1;

        // ── REVIVING A CANCELLED COVER TAKES THE PERIOD BACK ─────────────
        //
        // Cancelling releases the period: the clash checks skip cancelled
        // records, which is exactly what allows a replacement to be arranged.
        // Putting the cancelled cover back therefore ADDS occupancy, and can
        // put the same teacher in two rooms at once — so it is held to the
        // same checks, and the same per-date serialisation, as arranging the
        // cover in the first place. Cancelling or completing only ever
        // releases a period and needs neither.
        $reviving = ($status === 'assigned' && $was === 'cancelled');

        $ops = [[
            'collection' => self::COLLECTION,
            'docId'      => $fsDocId,
            'data'       => [
                'status'        => $status,
                'updatedAt'     => $now,
                'updatedByUid'  => $this->adminId,
                'updatedByName' => $this->adminName,
                'version'       => $newVersion,
            ],
            'merge'      => true,
        ]];

        if ($reviving) {
            $dateStr = (string) ($current['date'] ?? '');
            if ($dateStr === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateStr)) {
                throw new Service_exception(
                    'This cover has no usable date, so it cannot be restored. '
                    . 'Arrange the cover again instead.',
                    'validation'
                );
            }

            $clash = $this->_revivalClash($current, $dateStr, $id);
            if ($clash !== null) { throw new Service_exception($clash, 'conflict'); }

            [$coverDayId, $coverDayAt] = $this->_coverDayToken($dateStr);
            $ops[] = $this->_coverDayOp($coverDayId, $coverDayAt, $dateStr, $now);
        }

        $commit = $this->firebase->firestoreCommitBatchStatus($ops);
        if ((string) ($commit['status'] ?? '') === 'PRECONDITION_CONFLICT') {
            throw new Service_exception(
                'The cover for this date changed while you were working on it, so '
                . 'nothing was changed. Reload the day and try again.',
                'conflict'
            );
        }

        $this->_audit(
            'status_change', 'substitute', $id,
            ['status' => $was],
            ['status' => $status],
            []
        );

        // Cancelling takes the duty away; reviving gives it back; completing
        // changes nothing about who is expected where.
        return [
            'id'                 => $id,
            'newStatus'          => $status,
            'previousStatus'     => $was,
            'version'            => $newVersion,
            'date'               => (string) ($current['date'] ?? ''),
            'affectedTeacherIds' => $this->_coveringIds($current['assignments'] ?? []),
            'coverWithdrawn'     => ($status === 'cancelled' && $was !== 'cancelled'),
            'coverRestored'      => $reviving,
        ];
    }

    public function deleteSubstitute(string $id): array
    {
        if ($id === '') throw new Service_exception('Substitute ID required', 'validation');

        $fsDocId = "{$this->schoolId}_{$id}";

        // The document id carries the school but not the session, so the
        // record is read and checked before it is removed: last year's cover
        // used to be deletable from this year's screen by its id alone, and a
        // failed read left $before empty so the audit entry could not say what
        // had been removed.
        $existing = $this->_loadOwnedCover($id);
        $before = [
            'date'             => (string)($existing['date'] ?? ''),
            'status'           => (string)($existing['status'] ?? ''),
            'absentTeacherId'  => (string)($existing['absentTeacherId'] ?? $existing['absent_teacher_id'] ?? ''),
            'assignmentsCount' => is_array($existing['assignments'] ?? null) ? count($existing['assignments']) : 0,
        ];

        // ACAD-D8: the delete used to be swallowed while _audit() and the success
        // return ran regardless — so a failed removal told the operator the cover
        // was cancelled, wrote an audit entry recording a deletion that never
        // happened, and left the substitute live in the Teacher app. The audit
        // log exists to answer "who removed this cover"; it must never record an
        // action that did not occur.
        try { $this->firebase->firestoreDelete(self::COLLECTION, $fsDocId); }
        catch (\Throwable $e) {
            log_message('error', 'Substitute_service::deleteSubstitute failed (doc='
                . $fsDocId . '): ' . $e->getMessage());
            throw new Service_exception(
                'The substitute could not be removed. Nothing was changed — '
                . 'please try again in a moment.',
                'write'
            );
        }

        $this->_audit('delete', 'substitute', $id, $before, null, []);

        return [
            'id'                 => $id,
            'date'               => (string) ($existing['date'] ?? ''),
            'affectedTeacherIds' => ($existing['status'] ?? '') === 'cancelled'
                ? []                       // nobody was expecting to cover it
                : $this->_coveringIds($existing['assignments'] ?? []),
            'coverWithdrawn'     => ($existing['status'] ?? '') !== 'cancelled',
        ];
    }

    /**
     * Which teachers are already teaching their own classes, by period.
     *
     * Read NEGATIVELY by every caller — absence from this map means "free" —
     * so it fails loud and refuses a truncated scan rather than handing back a
     * short map that reads as "nobody is busy".
     */
    private function _busyMapFor(string $dayOfWeek): array
    {
            $busyMap = [];
            try {
                $ttDocs = $this->firebase->firestoreQueryOrFail('timetables', [
                    ['schoolId', '==', $this->schoolId],
                    ['session',  '==', $this->session],
                    ['day',      '==', $dayOfWeek],
                ], null, 'ASC', self::BUSY_SCAN_LIMIT);
            } catch (\Throwable $e) {
                log_message('error', 'Substitute_service: busy-map query failed (school='
                    . $this->schoolId . ' session=' . $this->session . ' day=' . $dayOfWeek
                    . '): ' . $e->getMessage());
                throw new Service_exception(
                    'Could not verify substitute availability just now, so nothing was '
                    . 'saved. Please try again in a moment.',
                    'conflict'
                );
            }

            // A full page means the clash could be in the rows we did not see.
            // Truncation is "cannot evaluate", not "no clash".
            if (count($ttDocs) >= self::BUSY_SCAN_LIMIT) {
                log_message('error', 'Substitute_service: busy-map scan hit its limit of '
                    . self::BUSY_SCAN_LIMIT . ' (school=' . $this->schoolId
                    . ' day=' . $dayOfWeek . ') — cannot prove the absence of a clash.');
                throw new Service_exception(
                    'Too many timetable entries to check for clashes on this day, so '
                    . 'nothing was saved. Please contact support.',
                    'conflict'
                );
            }

            foreach ($ttDocs as $doc) {
                $d = is_array($doc['data'] ?? null) ? $doc['data'] : $doc;
                foreach (($d['periods'] ?? []) as $p) {
                    $tid = $p['teacherId'] ?? '';
                    $pn  = (int) ($p['periodNumber'] ?? 0);
                    if ($tid !== '' && ($p['subject'] ?? '') !== '') $busyMap[$tid][$pn] = true;
                }
            }

        return $busyMap;
    }

    /**
     * The covers that actually hold a period on this date.
     *
     * Cancelled covers are left out: cancelling releases the period, which is
     * what lets a replacement be arranged at all. $excludeId drops the record
     * being edited, so it does not clash with itself.
     *
     * Read negatively, like the busy map, and fails loud for the same reason.
     */
    private function _activeCoversOn(string $dateStr, string $excludeId = ''): array
    {
            $existingSubs = [];
            try {
                $fsDocs = $this->firebase->firestoreQueryOrFail(self::COLLECTION, [
                    ['schoolId', '==', $this->schoolId],
                    ['session',  '==', $this->session],
                    ['date',     '==', $dateStr],
                ], null, 'ASC', self::EXISTING_SUB_SCAN_LIMIT + 1);

                if (count($fsDocs) > self::EXISTING_SUB_SCAN_LIMIT) {
                    log_message('error', 'Substitute_service: existing-cover scan hit its '
                        . 'limit of ' . self::EXISTING_SUB_SCAN_LIMIT . ' (school='
                        . $this->schoolId . ' date=' . $dateStr . ') — cannot prove the '
                        . 'absence of a clash.');
                    throw new Service_exception(
                        'Too many covers already recorded for this date to check for '
                        . 'clashes, so nothing was saved. Please contact support.',
                        'conflict'
                    );
                }

                foreach ($fsDocs as $doc) {
                    $d = is_array($doc['data'] ?? null) ? $doc['data'] : $doc;
                    $d['id'] = $d['id'] ?? '';
                    if (($d['status'] ?? '') === 'cancelled') continue;
                    if ($excludeId !== '' && ($d['id'] ?? '') === $excludeId) continue;
                    $existingSubs[] = $d;
                }
            } catch (Service_exception $e) {
                throw $e;
            } catch (\Throwable $e) {
                log_message('error', 'Substitute_service: existing-cover query failed (school='
                    . $this->schoolId . ' date=' . $dateStr . '): ' . $e->getMessage());
                throw new Service_exception(
                    'Could not check the covers already arranged for this date, so '
                    . 'nothing was saved. Please try again in a moment.',
                    'conflict'
                );
            }

        return $existingSubs;
    }

    /**
     * Load a cover this session is entitled to touch.
     *
     * The document id carries the school but NOT the session, so without this
     * check a cover recorded last year is reachable from this year's screen by
     * its identifier alone. A cover belonging to another session is reported as
     * absent rather than as forbidden: which covers another session holds is
     * not this one's business either.
     *
     * A failed read is NOT absence. Deciding from it would either refuse a
     * legitimate change or treat a live record as new.
     */
    private function _loadOwnedCover(string $id): array
    {
        $fsDocId = "{$this->schoolId}_{$id}";
        try {
            $doc = $this->firebase->firestoreGetOrFail(self::COLLECTION, $fsDocId);
        } catch (\Throwable $e) {
            log_message('error', 'Substitute_service: cover read failed (doc='
                . $fsDocId . '): ' . $e->getMessage());
            throw new Service_exception(
                'That cover could not be read, so nothing was changed. Please try '
                . 'again in a moment.',
                'read'
            );
        }

        if (!is_array($doc)
            || (string) ($doc['session'] ?? '') !== (string) $this->session) {
            throw new Service_exception(
                'That cover was not found. It may already have been removed — '
                . 'reload the day to see what is currently arranged.',
                'not_found'
            );
        }

        return $doc;
    }

    /**
     * The serialisation point for one date, and the token proving it has not
     * moved since it was read. Every write that ADDS occupancy to a date takes
     * one, so two of them cannot commit against the same snapshot.
     */
    private function _coverDayToken(string $dateStr): array
    {
        $coverDayId = "{$this->schoolId}_{$this->session}_{$dateStr}";
        try {
            $cd = $this->firebase->firestoreGetOrFail(
                self::COLLECTION_COVER_DAY, $coverDayId);
        } catch (\Throwable $e) {
            // Without the token the write cannot be made conditional, and an
            // unconditional one is exactly the double-booking this prevents.
            log_message('error', 'Substitute_service: cover-day read failed (doc='
                . $coverDayId . '): ' . $e->getMessage());
            throw new Service_exception(
                'Could not check the cover already arranged for this date, so '
                . 'nothing was saved. Please try again in a moment.',
                'conflict'
            );
        }

        return [$coverDayId, is_array($cd) ? (string) ($cd['__updateTime'] ?? '') : ''];
    }

    /** The cover-day write that accompanies every occupancy-adding commit. */
    private function _coverDayOp(string $coverDayId, string $token, string $dateStr,
                                 string $now): array
    {
        return [
            'collection' => self::COLLECTION_COVER_DAY,
            'docId'      => $coverDayId,
            'data'       => [
                'schoolId'  => $this->schoolId,
                'session'   => $this->session,
                'date'      => $dateStr,
                'updatedAt' => $now,
            ],
            'merge'      => true,
            'precondition' => $token !== ''
                ? ['updateTime' => $token]
                : ['exists' => false],
        ];
    }

    /**
     * Why this cancelled cover cannot be put back, or null if it can.
     *
     * Both things that could have changed since it was cancelled are checked:
     * the teacher may have been given this period in a republished timetable,
     * and somebody else may have been arranged to cover it. The message names
     * the teacher and the period, because that is what the person on the
     * screen has to go and look at.
     */
    private function _revivalClash(array $cover, string $dateStr, string $id): ?string
    {
        $assignments = is_array($cover['assignments'] ?? null) ? $cover['assignments'] : [];
        if (empty($assignments)) { return null; }

        $dayOfWeek = (new \DateTime($dateStr))->format('l');
        $busyMap   = $this->_busyMapFor($dayOfWeek);
        $active    = $this->_activeCoversOn($dateStr, $id);

        foreach ($assignments as $a) {
            $pn    = (int) ($a['periodNumber'] ?? 0);
            $subId = (string) ($a['substituteTeacherId'] ?? $a['substitute_teacher_id'] ?? '');
            $subNm = (string) ($a['substituteTeacherName'] ?? $a['substitute_teacher_name'] ?? '');
            if ($pn < 1 || $subId === '') { continue; }
            if ($subNm === '') { $subNm = 'That teacher'; }

            if (isset($busyMap[$subId][$pn])) {
                return "{$subNm} is already covering their own class during Period "
                     . "{$pn} on {$dayOfWeek}, so this cover cannot be restored.";
            }

            foreach ($active as $ex) {
                foreach (($ex['assignments'] ?? []) as $ea) {
                    $eaSubId = $ea['substituteTeacherId'] ?? $ea['substitute_teacher_id'] ?? '';
                    if ((int) ($ea['periodNumber'] ?? 0) === $pn && $eaSubId === $subId) {
                        return "{$subNm} is already covering another class at Period "
                             . "{$pn} on this date, so this cover cannot be restored.";
                    }
                }
                $exFlatSubId = $ex['substituteTeacherId'] ?? $ex['substitute_teacher_id'] ?? '';
                $exPeriods   = $ex['periods'] ?? [];
                if ($exFlatSubId === $subId && is_array($exPeriods) && in_array($pn, $exPeriods)) {
                    return "{$subNm} is already covering another class at Period "
                         . "{$pn} on this date, so this cover cannot be restored.";
                }
            }
        }

        return null;
    }

    /**
     * What the timetable says about each period of one day, by class-section.
     *
     * Returns null when it CANNOT SAY — a failed read, or a scan that hit its
     * bound. Null is not "nothing changed": every cover would look as though
     * its period had vanished, and an administrator would be sent to
     * re-arrange cover that was perfectly correct.
     */
    private function _dayGridFor(string $dateStr): ?array
    {
        $dayOfWeek = (new \DateTime($dateStr))->format('l');

        try {
            $ttDocs = $this->firebase->firestoreQueryOrFail('timetables', [
                ['schoolId', '==', $this->schoolId],
                ['session',  '==', $this->session],
                ['day',      '==', $dayOfWeek],
            ], null, 'ASC', self::BUSY_SCAN_LIMIT + 1);
        } catch (\Throwable $e) {
            log_message('error', 'Substitute_service: day-grid read failed (school='
                . $this->schoolId . ' day=' . $dayOfWeek . '): ' . $e->getMessage());
            return null;
        }

        if (count($ttDocs) > self::BUSY_SCAN_LIMIT) {
            log_message('error', 'Substitute_service: day-grid scan hit its limit '
                . '(school=' . $this->schoolId . ' day=' . $dayOfWeek . ')');
            return null;
        }

        $grid = [];
        foreach ($ttDocs as $doc) {
            $d = is_array($doc['data'] ?? null) ? $doc['data'] : $doc;
            $key = $this->_classKey((string) ($d['className'] ?? ''),
                                     (string) ($d['section']   ?? ''));
            foreach (($d['periods'] ?? []) as $p) {
                $pn = (int) ($p['periodNumber'] ?? 0);
                if ($pn < 1) { continue; }
                $grid[$key][$pn] = [
                    'teacherId' => (string) ($p['teacherId'] ?? ''),
                    'subject'   => (string) ($p['subject']   ?? ''),
                ];
            }
        }

        return $grid;
    }

    /**
     * What the timetable says about the periods THIS cover occupies.
     *
     * Deliberately narrow: only the periods the cover actually holds. A
     * change elsewhere in the day is not a change to this cover, and a
     * signature over the whole day would re-flag every cover in the school
     * whenever any lesson moved.
     */
    private function _driftSignature(array $rec, array $grid): string
    {
        $parts = [];
        foreach (($rec['assignments'] ?? []) as $a) {
            $pn  = (int) ($a['periodNumber'] ?? 0);
            $key = $this->_classKey((string) ($a['className'] ?? ''),
                                     (string) ($a['section']   ?? ''));
            $now = $grid[$key][$pn] ?? null;
            $parts[] = $key . '|' . $pn . '|' . ($now === null
                ? 'gone'
                : $now['teacherId'] . '|' . strtolower($now['subject']));
        }
        sort($parts);
        return md5(implode(';', $parts));
    }

    /**
     * KEEP THIS COVER AS IT IS.
     *
     * One of the three things an administrator can do about a cover the
     * timetable has moved under; the other two — cancel it, re-arrange it —
     * already exist. Which is right depends on the class, the teacher and the
     * day, so nothing here decides on their behalf. This only records that a
     * named person looked at a named state and chose to leave it alone.
     *
     * Changes nothing about the cover itself.
     */
    public function acknowledgeDrift(string $id): array
    {
        $id = trim($id);
        if ($id === '') {
            throw new Service_exception('Which cover?', 'validation');
        }

        $cover  = $this->_loadOwnedCover($id);
        $dateStr = (string) ($cover['date'] ?? '');
        if ($dateStr === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateStr)) {
            throw new Service_exception(
                'This cover has no usable date, so it cannot be checked against '
                . 'the timetable.', 'validation');
        }

        $grid = $this->_dayGridFor($dateStr);
        if ($grid === null) {
            // No established state means no decision to record. Writing one
            // anyway would file an acknowledgement nobody actually made.
            throw new Service_exception(
                'The timetable for this day could not be read, so nothing was '
                . 'changed. Please try again in a moment.',
                'read'
            );
        }

        // _markDrift() returns the whole record with its assignments marked,
        // not a bare list of assignments.
        $marked  = $this->_markDrift($cover, $grid);
        $drifted = false;
        foreach (($marked['assignments'] ?? []) as $a) {
            if (!empty($a['timetableChanged'])) { $drifted = true; break; }
        }
        if (!$drifted) {
            throw new Service_exception(
                'The timetable still agrees with this cover, so there is nothing '
                . 'to keep. Reload the day to see the current state.',
                'validation'
            );
        }

        $now = date('c');
        $ack = [
            'at'        => $now,
            'byUid'     => $this->adminId,
            'byName'    => $this->adminName,
            'signature' => $this->_driftSignature($cover, $grid),
        ];

        $this->firebase->firestoreSet(self::COLLECTION,
            "{$this->schoolId}_{$id}", ['driftAcknowledged' => $ack], true);

        $this->_audit('update', 'substitute', $id,
            ['status' => (string) ($cover['status'] ?? ''),
             'assignmentsCount' => is_array($cover['assignments'] ?? null)
                 ? count($cover['assignments']) : 0],
            ['status' => (string) ($cover['status'] ?? ''),
             'assignmentsCount' => is_array($cover['assignments'] ?? null)
                 ? count($cover['assignments']) : 0,
             'driftKept' => true],
            ['decision' => 'kept despite a timetable change']);

        return ['id' => $id, 'acknowledgedAt' => $now, 'acknowledgedBy' => $this->adminName];
    }

    /** One key for a class-section, however either side spelled it. */
    private function _classKey(string $class, string $section): string
    {
        $cs = \Entity_firestore_sync::normalizeClassSection(trim($class), trim($section));
        return strtolower((string) ($cs['className'] ?? $class)) . '|'
             . strtolower((string) ($cs['section']   ?? $section));
    }

    /**
     * Say, per assignment, whether the timetable still agrees with it — in
     * words an administrator can act on, and never quoting an identifier at
     * somebody who has no way to look one up.
     */
    private function _markDrift(array $rec, array $grid): array
    {
        $absentId = (string) ($rec['absentTeacherId'] ?? $rec['absent_teacher_id'] ?? '');
        $out = [];

        // A cover somebody has already looked at and decided to keep. The
        // decision is recorded against the STATE it was made about, so a
        // further change to the timetable is a different fact and flags the
        // cover again — an acknowledgement is not a permanent blindfold.
        $ack     = is_array($rec['driftAcknowledged'] ?? null)
            ? $rec['driftAcknowledged'] : [];
        $ackSig  = (string) ($ack['signature'] ?? '');
        $nowSig  = $this->_driftSignature($rec, $grid);
        $kept    = ($ackSig !== '' && $ackSig === $nowSig);

        foreach (($rec['assignments'] ?? []) as $a) {
            $pn      = (int) ($a['periodNumber'] ?? 0);
            $key     = $this->_classKey((string) ($a['className'] ?? ''),
                                         (string) ($a['section']   ?? ''));
            $now     = $grid[$key][$pn] ?? null;
            $subject = trim((string) ($a['subject'] ?? ''));

            $note = '';
            if ($now === null) {
                $note = 'The timetable no longer has a Period ' . $pn
                      . ' for this class, so this cover may no longer be needed.';
            } elseif ($absentId !== '' && $now['teacherId'] !== $absentId) {
                $note = 'The timetable now gives Period ' . $pn . ' to a different '
                      . 'teacher, so the absent teacher was not due in this class.';
            } elseif ($subject !== '' && $now['subject'] !== ''
                      && strcasecmp($now['subject'], $subject) !== 0) {
                $note = 'The timetable now has ' . $now['subject'] . ' in Period '
                      . $pn . ', not ' . $subject . '.';
            }

            // Both halves of the comparison, so the person deciding can see
            // what was agreed and what the timetable says now, side by side,
            // rather than being told only that something moved.
            $a['agreedSubject']  = $subject;
            $a['currentSubject'] = $now === null ? '' : (string) $now['subject'];
            $a['currentlyTheAbsentTeacher'] =
                ($now !== null && $absentId !== '' && $now['teacherId'] === $absentId);

            $a['timetableChanged'] = ($note !== '' && !$kept);
            $a['timetableNote']    = $kept ? '' : $note;
            if ($kept) {
                $a['keptBy'] = (string) ($ack['byName'] ?? '');
                $a['keptAt'] = (string) ($ack['at'] ?? '');
            }
            $out[] = $a;
        }

        $rec['assignments'] = $out;
        return $rec;
    }

    /**
     * The cover policy this school set, or the unset defaults.
     *
     * `configured` is what separates "this school has not written a policy"
     * from "this school chose not to limit anything" — the screen has to be
     * able to say which, and so does anybody reading the numbers back.
     *
     * Fails loud. The limits are read NEGATIVELY at the point of saving —
     * no limit found means nobody is limited — so a failed read would
     * quietly suspend the school's own policy at exactly the moment
     * somebody is arranging cover against it.
     */
    public function getPolicy(): array
    {
        $docId = "{$this->schoolId}_{$this->session}";
        try {
            $doc = $this->firebase->firestoreGetOrFail(self::COLLECTION_POLICY, $docId);
        } catch (\Throwable $e) {
            log_message('error', 'Substitute_service: policy read failed (doc='
                . $docId . '): ' . $e->getMessage());
            throw new Service_exception(
                "The school's cover policy could not be read, so nothing was "
                . 'changed. Please try again in a moment.',
                'read'
            );
        }

        $inheritedFrom = '';
        if (!is_array($doc)) {
            // A NEW SESSION IS NOT A WITHDRAWN POLICY.
            //
            // The document is keyed by session, like every setting in this
            // module, so a new session begins with none — and no document
            // means nothing is limited. A school that set a ceiling in July
            // would silently lose it in April, without anybody deciding to.
            // The most recent earlier policy is carried forward until
            // somebody writes one for this session, and the screen says
            // where it came from so the school can see what it is held to.
            $doc = $this->_previousPolicy();
            if (is_array($doc)) {
                $inheritedFrom = (string) ($doc['session'] ?? '');
            }
        }

        if (!is_array($doc)) {
            return [
                'configured'             => false,
                'inheritedFrom'          => '',
                'maxCoverPeriodsPerDay'  => 0,
                'maxCoverPeriodsPerWeek' => 0,
                'requireSubjectMatch'    => false,
                'teachingStaffOnly'      => false,
                'updatedByName'          => '',
                'updatedAt'              => '',
            ];
        }

        return [
            'configured'             => true,
            'inheritedFrom'          => $inheritedFrom,
            'maxCoverPeriodsPerDay'  => max(0, (int) ($doc['maxCoverPeriodsPerDay']  ?? 0)),
            'maxCoverPeriodsPerWeek' => max(0, (int) ($doc['maxCoverPeriodsPerWeek'] ?? 0)),
            'requireSubjectMatch'    => !empty($doc['requireSubjectMatch']),
            'teachingStaffOnly'      => !empty($doc['teachingStaffOnly']),
            'updatedByName'          => (string) ($doc['updatedByName'] ?? ''),
            'updatedAt'              => (string) ($doc['updatedAt'] ?? ''),
        ];
    }

    /**
     * Write the school's cover policy.
     *
     * Zero means "not limited", which is why it is the default: a school that
     * has never opened this screen is not a school that has chosen zero.
     */
    public function savePolicy(array $in): array
    {
        $perDay  = (int) ($in['maxCoverPeriodsPerDay']  ?? 0);
        $perWeek = (int) ($in['maxCoverPeriodsPerWeek'] ?? 0);

        if ($perDay < 0 || $perWeek < 0) {
            throw new Service_exception(
                'A cover limit cannot be negative. Use 0 for no limit.',
                'validation'
            );
        }
        if ($perDay > 0 && $perWeek > 0 && $perWeek < $perDay) {
            throw new Service_exception(
                'The weekly limit (' . $perWeek . ') is lower than the daily limit ('
                . $perDay . '), so a single day of cover would already break the '
                . 'week. Raise the weekly limit or lower the daily one.',
                'validation'
            );
        }

        $now   = date('c');
        $docId = "{$this->schoolId}_{$this->session}";
        $data  = [
            'schoolId'               => $this->schoolId,
            'session'                => $this->session,
            'maxCoverPeriodsPerDay'  => $perDay,
            'maxCoverPeriodsPerWeek' => $perWeek,
            'requireSubjectMatch'    => !empty($in['requireSubjectMatch']),
            'teachingStaffOnly'      => !empty($in['teachingStaffOnly']),
            'updatedAt'              => $now,
            'updatedByUid'           => $this->adminId,
            'updatedByName'          => $this->adminName,
        ];

        $before = null;
        try { $before = $this->getPolicy(); } catch (\Throwable $e) { $before = null; }

        $this->firebase->firestoreSet(self::COLLECTION_POLICY, $docId, $data, true);

        $this->_audit('update', 'substitutePolicy', $docId, $before, $data, []);

        return $this->getPolicy();
    }

    /**
     * Refuse cover the school's own policy does not allow.
     *
     * Nothing here is ZenX's opinion. Every limit is a number the school
     * entered, and a school that has entered none is refused nothing.
     *
     * ONE KNOWN LIMIT, stated rather than hidden: the DAILY cap is exact,
     * because saves for a date are serialised on that date's cover-day
     * document, so two of them cannot both count the same snapshot. The
     * WEEKLY cap is not serialised across dates — two administrators
     * arranging cover on different days of the same week can each see the
     * week as having room, and the week can finish one period over. The
     * alternative is to serialise every cover arrangement in the school
     * behind every other for a whole week, which is far more disruptive than
     * the thing it prevents. A weekly ceiling is a workload guideline; a
     * double-booking is a class with nobody in it.
     */
    private function _enforcePolicy(array $assignments, array $existingSubs,
                                    string $dateStr, string $id, array $known): void
    {
        $policy = $this->getPolicy();

        $perDay  = (int) $policy['maxCoverPeriodsPerDay'];
        $perWeek = (int) $policy['maxCoverPeriodsPerWeek'];
        $needSubject = !empty($policy['requireSubjectMatch']);
        $teachingOnly = !empty($policy['teachingStaffOnly']);

        // Nothing set, nothing to enforce, and nothing read.
        if ($perDay === 0 && $perWeek === 0 && !$needSubject && !$teachingOnly) {
            return;
        }

        // What this save asks of each teacher.
        $asked = [];
        $names = [];
        foreach ($assignments as $a) {
            $sid = (string) $a['substituteTeacherId'];
            $asked[$sid] = ($asked[$sid] ?? 0) + 1;
            $names[$sid] = (string) ($a['substituteTeacherName'] ?? '');
            if ($names[$sid] === '') { $names[$sid] = 'That teacher'; }
        }

        // ── the daily ceiling ────────────────────────────────────────────
        if ($perDay > 0) {
            $already = $this->_countCover($existingSubs);
            foreach ($asked as $sid => $n) {
                $have = (int) ($already[$sid] ?? 0);
                if ($have + $n <= $perDay) { continue; }

                throw new Service_exception(
                    $names[$sid] . ' would be covering ' . ($have + $n) . ' period(s) '
                    . 'on this date. This school allows ' . $perDay . ' per day'
                    . ($have > 0 ? ', and ' . $have . ' is already arranged' : '')
                    . '. Choose somebody else for the remaining period(s), or change '
                    . 'the limit in Cover Policy.',
                    'conflict'
                );
            }
        }

        // ── the weekly ceiling ───────────────────────────────────────────
        if ($perWeek > 0) {
            $week = $this->_weekCoverCounts($dateStr, $id);
            foreach ($asked as $sid => $n) {
                $have = (int) ($week[$sid] ?? 0);
                if ($have + $n <= $perWeek) { continue; }

                throw new Service_exception(
                    $names[$sid] . ' would be covering ' . ($have + $n) . ' period(s) '
                    . 'in the week beginning ' . $this->_weekBounds($dateStr)[0] . '. '
                    . 'This school allows ' . $perWeek . ' per week'
                    . ($have > 0 ? ', and ' . $have . ' is already arranged' : '')
                    . '. Choose somebody else, or change the limit in Cover Policy.',
                    'conflict'
                );
            }
        }

        // ── who the school allows to cover, and in which subject ─────────
        if (!$needSubject && !$teachingOnly) { return; }

        // The records were established above, where every id named in this save
        // was checked against the staff list. Reading them again here would be a
        // second round of Firestore gets for an answer already in hand — and a
        // second place deciding whether somebody exists, which is how two checks
        // drift apart. The identity check guarantees a record for every id here.
        foreach ($assignments as $a) {
            $sid = (string) $a['substituteTeacherId'];
            $rec = $known[$sid];
            $who = $names[$sid];

            if ($teachingOnly && !\Teaching_staff::isTeaching($rec)) {
                throw new Service_exception(
                    $who . ' is not recorded as teaching staff, and this school '
                    . 'only allows teaching staff to cover lessons.',
                    'validation'
                );
            }

            if (!$needSubject) { continue; }

            $subject = trim((string) ($a['subject'] ?? ''));
            if ($subject === '') { continue; }        // nothing to match against

            $teaches = \Teaching_staff::subjects($rec);
            $match = false;
            foreach ($teaches as $t) {
                if (strcasecmp($t, $subject) === 0) { $match = true; break; }
            }
            if (!$match) {
                throw new Service_exception(
                    $who . ' does not teach ' . $subject . ', and this school '
                    . 'requires cover to be taken by a teacher of the subject. '
                    . (empty($teaches)
                        ? 'Their staff record lists no subjects at all.'
                        : 'They teach ' . implode(', ', $teaches) . '.'),
                    'validation'
                );
            }
        }
    }

    /**
     * The distinct teachers named across a set of assignments.
     *
     * Both shapes, because a legacy record carries one teacher at the top
     * level rather than one per period, and a person owed a message about
     * their day should not miss it over a schema change.
     *
     * @return string[]
     */
    private function _coveringIds($assignments): array
    {
        $out = [];
        if (is_array($assignments)) {
            foreach ($assignments as $a) {
                if (!is_array($a)) { continue; }
                $sid = trim((string) ($a['substituteTeacherId']
                    ?? $a['substitute_teacher_id'] ?? ''));
                if ($sid !== '') { $out[$sid] = true; }
            }
        }
        return array_keys($out);
    }

    /** How many periods each teacher is covering, across these records. */
    private function _countCover(array $covers): array
    {
        $out = [];
        foreach ($covers as $ex) {
            foreach (($ex['assignments'] ?? []) as $ea) {
                $sid = (string) ($ea['substituteTeacherId']
                    ?? $ea['substitute_teacher_id'] ?? '');
                if ($sid !== '') { $out[$sid] = ($out[$sid] ?? 0) + 1; }
            }
            // Legacy shape: one teacher, a list of periods.
            $flat = (string) ($ex['substituteTeacherId']
                ?? $ex['substitute_teacher_id'] ?? '');
            $periods = $ex['periods'] ?? [];
            if ($flat !== '' && is_array($periods)) {
                $out[$flat] = ($out[$flat] ?? 0) + count($periods);
            }
        }
        return $out;
    }

    /**
     * Cover already arranged across the whole week the date falls in.
     * Read only when a weekly cap is set, and never trusted if truncated.
     */
    private function _weekCoverCounts(string $dateStr, string $excludeId): array
    {
        [$from, $to] = $this->_weekBounds($dateStr);

        try {
            $docs = $this->firebase->firestoreQueryOrFail(self::COLLECTION, [
                ['schoolId', '==', $this->schoolId],
                ['session',  '==', $this->session],
                ['date',     '>=', $from],
                ['date',     '<=', $to],
            ], 'date', 'ASC', self::WEEK_SCAN_LIMIT + 1);
        } catch (\Throwable $e) {
            log_message('error', 'Substitute_service: week-cover query failed (school='
                . $this->schoolId . ' week=' . $from . '): ' . $e->getMessage());
            throw new Service_exception(
                'The cover already arranged this week could not be read, so nothing '
                . 'was saved. Please try again in a moment.',
                'conflict'
            );
        }

        if (count($docs) > self::WEEK_SCAN_LIMIT) {
            log_message('error', 'Substitute_service: week-cover scan hit its limit of '
                . self::WEEK_SCAN_LIMIT . ' (school=' . $this->schoolId
                . ' week=' . $from . ')');
            throw new Service_exception(
                'There is too much cover in this week to check it against the '
                . "school's weekly limit, so nothing was saved. Please contact "
                . 'support.',
                'conflict'
            );
        }

        $rows = [];
        foreach ($docs as $doc) {
            $d = is_array($doc['data'] ?? null) ? $doc['data'] : $doc;
            if (($d['status'] ?? '') === 'cancelled') { continue; }
            if ($excludeId !== '' && ($d['id'] ?? '') === $excludeId) { continue; }
            $rows[] = $d;
        }

        return $this->_countCover($rows);
    }

    /**
     * Staff records for the teachers named in this save.
     *
     * Fails loud: a record that could not be READ is not a teacher who lacks
     * the subject, and refusing cover on that basis would block the school
     * during an outage while telling the operator something untrue about a
     * colleague.
     */
    private function _staffRecords(array $staffIds): array
    {
        $out = [];
        foreach (array_unique($staffIds) as $sid) {
            if ($sid === '') { continue; }
            try {
                $doc = $this->firebase->firestoreGetOrFail(
                    'staff', "{$this->schoolId}_{$sid}");
            } catch (\Throwable $e) {
                log_message('error', 'Substitute_service: staff read failed (staff='
                    . $sid . '): ' . $e->getMessage());
                throw new Service_exception(
                    "This school's cover rules could not be checked just now, so "
                    . 'nothing was saved. Please try again in a moment.',
                    'read'
                );
            }
            if (is_array($doc)) { $out[$sid] = $doc; }
        }
        return $out;
    }

    /**
     * WHO IS AWAY ON A DATE, AND WHO COULD BE NAMED INSTEAD.
     *
     * The first question of the whole workflow, and the only one where a
     * wrong answer stays silent all the way to the classroom: a teacher
     * missing from this list is a teacher nobody arranges cover for.
     *
     * Two sources, deliberately distinguished. Approved LEAVE is foreseeable
     * absence, known days ahead and plannable. An attendance mark of A or L
     * on the day is UNFORESEEABLE — the kind of absence cover policies exist
     * for. Callers are told which, because every real cover policy treats
     * them differently.
     *
     * All three reads deny on failure. Each used to sit in an empty catch
     * over a helper that returns [] when it fails, so the catch could never
     * run and a failed read arrived as a shorter list of absent teachers —
     * indistinguishable from a day when fewer people were away.
     */
    public function getAbsentTeachers(string $dateStr): array
    {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateStr) || !strtotime($dateStr)) {
            throw new Service_exception('A valid date is required.', 'validation');
        }

        $staff   = $this->_activeStaff();
        $absent  = [];

        // ── foreseeable: approved leave spanning the date ────────────────
        $leaves = $this->_readOrDeny('leaveApplications', [
            ['schoolId',      '==', $this->schoolId],
            ['status',        '==', 'approved'],
            ['applicantType', '==', 'staff'],
        ], self::LEAVE_SCAN_LIMIT, 'approved leave');

        foreach ($leaves as $d) {
            $from = (string) ($d['fromDate'] ?? $d['from_date'] ?? '');
            $to   = (string) ($d['toDate']   ?? $d['to_date']   ?? $from);
            $sid  = (string) ($d['staffId']  ?? $d['applicantId'] ?? '');
            if ($sid === '' || $from === '') { continue; }
            if ($dateStr < $from || $dateStr > $to) { continue; }

            $absent[$sid] = [
                'id'        => $sid,
                'name'      => $this->_staffName($staff, $sid,
                                   (string) ($d['applicantName'] ?? '')),
                'staffCode' => $sid,
                'reason'    => 'Leave: ' . ($d['leaveType'] ?? $d['leave_type'] ?? 'General'),
                'leaveType' => (string) ($d['leaveType'] ?? $d['leave_type'] ?? ''),
                'source'    => 'leave',
            ];
        }

        // ── unforeseeable: an attendance mark on the day itself ──────────
        $marks = $this->_readOrDeny('staffAttendance', [
            ['schoolId', '==', $this->schoolId],
            ['date',     '==', $dateStr],
        ], self::MARK_SCAN_LIMIT, "the day's staff attendance");

        foreach ($marks as $d) {
            $sid  = (string) ($d['staffId'] ?? '');
            $mark = strtoupper((string) ($d['status'] ?? ''));
            if ($sid === '' || ($mark !== 'A' && $mark !== 'L')) { continue; }
            if (isset($absent[$sid])) { continue; }        // leave already said so

            $absent[$sid] = [
                'id'        => $sid,
                'name'      => $this->_staffName($staff, $sid,
                                   (string) ($d['staffName'] ?? '')),
                'staffCode' => $sid,
                'reason'    => $mark === 'L' ? 'On Leave' : 'Absent',
                'leaveType' => $mark === 'L' ? 'leave' : 'absent',
                'source'    => 'attendance',
            ];
        }

        $all = [];
        foreach ($staff as $sid => $rec) {
            $all[] = [
                'id'        => $sid,
                'name'      => $this->_staffName($staff, $sid, ''),
                'staffCode' => $sid,
                'teaching'  => \Teaching_staff::isTeaching($rec),
            ];
        }
        usort($all, fn($a, $b) => strcasecmp($a['name'], $b['name']));

        $absentList = array_values($absent);
        usort($absentList, fn($a, $b) => strcasecmp($a['name'], $b['name']));

        return [
            'date'           => $dateStr,
            'absentTeachers' => $absentList,
            'allTeachers'    => $all,
        ];
    }

    /**
     * A bounded, fail-loud read whose emptiness would be acted on.
     *
     * Saturation denies for the same reason failure does: the teacher whose
     * row fell off the end is away, and nobody will be asked to cover them.
     */
    private function _readOrDeny(string $collection, array $conditions,
                                 int $limit, string $what): array
    {
        try {
            $docs = $this->firebase->firestoreQueryOrFail(
                $collection, $conditions, null, 'ASC', $limit + 1);
        } catch (\Throwable $e) {
            log_message('error', 'Substitute_service: ' . $collection
                . ' read failed (school=' . $this->schoolId . '): ' . $e->getMessage());
            throw new Service_exception(
                ucfirst($what) . ' could not be read, so who is away cannot be '
                . 'shown. Please try again in a moment — do not arrange cover from '
                . 'an incomplete list.',
                'read'
            );
        }

        if (count($docs) > $limit) {
            log_message('error', 'Substitute_service: ' . $collection
                . ' scan hit its limit of ' . $limit . ' (school=' . $this->schoolId . ')');
            throw new Service_exception(
                'There are too many records to work out ' . $what . ' reliably, so '
                . 'nothing is shown. Please contact support.',
                'read'
            );
        }

        $out = [];
        foreach ($docs as $doc) {
            $out[] = is_array($doc['data'] ?? null) ? $doc['data'] : $doc;
        }
        return $out;
    }

    /** Active staff for this school, keyed by staff id. */
    private function _activeStaff(): array
    {
        $docs = $this->_readOrDeny('staff', [
            ['schoolId', '==', $this->schoolId],
            ['status',   '==', 'Active'],
        ], self::STAFF_SCAN_LIMIT, 'the staff list');

        $out = [];
        foreach ($docs as $d) {
            $sid = (string) ($d['staffId'] ?? $d['userId'] ?? '');
            if ($sid !== '') { $out[$sid] = $d; }
        }
        return $out;
    }

    /**
     * A person's name, never their identifier.
     *
     * A staff code is not a name and must not be printed as one at somebody
     * who has no way to look it up. Where nothing is recorded the row says so
     * and carries the code separately, labelled as what it is.
     */
    private function _staffName(array $staff, string $sid, string $fallback): string
    {
        $rec = $staff[$sid] ?? null;
        if (is_array($rec)) {
            $n = \Teaching_staff::name($rec);
            if ($n !== '') { return $n; }
        }
        $fallback = trim($fallback);
        if ($fallback !== '' && $fallback !== $sid) { return $fallback; }
        return 'Unnamed staff record';
    }

    /**
     * THE HISTORY OF ONE COVER.
     *
     * Answers the question an administrator is actually asked: who arranged
     * this, who changed it, who cancelled it, and when.
     *
     * Reads the platform's existing academicAuditLog — the same entries this
     * service has always written, through the same Audit_log_service every
     * other Academic surface uses. No second history collection: the entries
     * were never missing, only unreadable, because nothing in the repository
     * read that collection at all.
     *
     * Deliberately does NOT require the cover to still exist. A removed cover
     * is the one people ask about.
     */
    public function coverHistory(string $id): array
    {
        $id = trim($id);
        if ($id === '') {
            throw new Service_exception('Which cover?', 'validation');
        }

        try {
            $docs = $this->firebase->firestoreQueryOrFail(
                self::COLLECTION_AUDIT,
                [
                    ['schoolId', '==', $this->schoolId],
                    ['entityId', '==', $id],
                ], 'ts', 'DESC', self::HISTORY_SCAN_LIMIT + 1);
        } catch (\Throwable $e) {
            log_message('error', 'Substitute_service: history read failed (cover='
                . $id . '): ' . $e->getMessage());
            throw new Service_exception(
                'The history of this cover could not be read. Please try again in '
                . 'a moment — this is not the same as nothing having happened.',
                'read'
            );
        }

        $truncated = count($docs) > self::HISTORY_SCAN_LIMIT;
        if ($truncated) { $docs = array_slice($docs, 0, self::HISTORY_SCAN_LIMIT); }

        $rows = [];
        foreach ($docs as $doc) {
            $d = is_array($doc['data'] ?? null) ? $doc['data'] : $doc;

            // The query is school-scoped; the session is not a query field on
            // this collection, so it is filtered here. Last year's cover is
            // not this year's business even when the id repeats.
            if ((string) ($d['session'] ?? '') !== (string) $this->session) { continue; }
            if ((string) ($d['entityType'] ?? '') !== 'substitute') { continue; }

            $actor = is_array($d['actor'] ?? null) ? $d['actor'] : [];
            $by    = trim((string) ($actor['name'] ?? ''));

            $rows[] = [
                'at'     => (string) ($d['ts'] ?? ''),
                // A user id is not a name. Where none was recorded the row
                // says so rather than printing an identifier at somebody.
                'by'     => $by !== '' ? $by : 'Someone whose name was not recorded',
                'role'   => (string) ($actor['role'] ?? ''),
                'action' => (string) ($d['action'] ?? ''),
                'what'   => $this->_describeChange(
                    (string) ($d['action'] ?? ''),
                    is_array($d['before'] ?? null) ? $d['before'] : null,
                    is_array($d['after']  ?? null) ? $d['after']  : null
                ),
            ];
        }

        return [
            'id'        => $id,
            'entries'   => $rows,
            'truncated' => $truncated,
            'notice'    => $truncated
                ? 'Showing the ' . self::HISTORY_SCAN_LIMIT . ' most recent changes.'
                : '',
        ];
    }

    /**
     * One line saying what actually changed, for somebody who was not there.
     *
     * "Set to cancelled" answers nothing on its own; "assigned to cancelled"
     * is the fact being asked for. Identifiers never appear: the person
     * reading this has no way to look one up.
     */
    private function _describeChange(string $action, ?array $before, ?array $after): string
    {
        $n = static function (?array $m): string {
            $c = (int) ($m['assignmentsCount'] ?? 0);
            return $c === 1 ? '1 period' : $c . ' periods';
        };

        switch ($action) {
            case 'create':
                $on = trim((string) ($after['date'] ?? ''));
                return 'Cover arranged' . ($on !== '' ? ' for ' . $on : '')
                     . ' — ' . $n($after) . '.';

            case 'update':
                $was = $n($before);
                $now = $n($after);
                return $was === $now
                    ? 'Cover changed — still ' . $now . '.'
                    : 'Cover changed — ' . $was . ' became ' . $now . '.';

            case 'status_change':
                $from = trim((string) ($before['status'] ?? ''));
                $to   = trim((string) ($after['status']  ?? ''));
                if ($to === '') { return 'Status changed.'; }
                return $from === ''
                    ? 'Marked ' . $to . '.'
                    : 'Changed from ' . $from . ' to ' . $to . '.';

            case 'delete':
                $on = trim((string) ($before['date'] ?? ''));
                return 'Cover removed' . ($on !== '' ? ' for ' . $on : '') . '.';
        }

        return 'Changed.';
    }

    /**
     * WHO HAS BEEN CARRYING THE COVER.
     *
     * The STPCD sets no ceiling on cover but expects the burden to be shared
     * equitably over the year, and names the cover log as what makes that
     * possible. This is that log: periods and days per teacher over a range,
     * heaviest first, so the question "who have we been leaning on?" can be
     * answered before the next absence rather than after the complaint.
     */
    public function coverLog(string $from, string $to): array
    {
        $from = trim($from); $to = trim($to);
        foreach ([$from, $to] as $d) {
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) || !strtotime($d)) {
                throw new Service_exception(
                    'Give a start and end date for the cover log.', 'validation');
            }
        }
        if ($from > $to) {
            throw new Service_exception(
                'The end date is before the start date.', 'validation');
        }

        try {
            $docs = $this->firebase->firestoreQueryOrFail(self::COLLECTION, [
                ['schoolId', '==', $this->schoolId],
                ['session',  '==', $this->session],
                ['date',     '>=', $from],
                ['date',     '<=', $to],
            ], 'date', 'DESC', self::LOG_SCAN_LIMIT + 1);
        } catch (\Throwable $e) {
            log_message('error', 'Substitute_service: cover-log query failed (school='
                . $this->schoolId . ' ' . $from . '..' . $to . '): ' . $e->getMessage());
            throw new Service_exception(
                'The cover log could not be read, so none of it is shown. Please '
                . 'try again in a moment — an empty log and an unreadable one are '
                . 'not the same thing.',
                'read'
            );
        }

        $truncated = count($docs) > self::LOG_SCAN_LIMIT;
        if ($truncated) { $docs = array_slice($docs, 0, self::LOG_SCAN_LIMIT); }

        $by = [];        // teacherId => ['name','periods','days'=>[date=>true]]
        $total = 0;
        foreach ($docs as $doc) {
            $d = is_array($doc['data'] ?? null) ? $doc['data'] : $doc;
            if (($d['status'] ?? '') === 'cancelled') { continue; }
            $date = (string) ($d['date'] ?? '');

            foreach (($d['assignments'] ?? []) as $a) {
                $sid = (string) ($a['substituteTeacherId']
                    ?? $a['substitute_teacher_id'] ?? '');
                if ($sid === '') { continue; }
                $nm = trim((string) ($a['substituteTeacherName']
                    ?? $a['substitute_teacher_name'] ?? ''));
                $this->_tally($by, $sid, $nm, $date, 1);
                $total++;
            }

            // Legacy shape: one teacher, a list of periods.
            $flat = (string) ($d['substituteTeacherId']
                ?? $d['substitute_teacher_id'] ?? '');
            $periods = $d['periods'] ?? [];
            if ($flat !== '' && is_array($periods) && !empty($periods)) {
                $nm = trim((string) ($d['substituteTeacherName']
                    ?? $d['substitute_teacher_name'] ?? ''));
                $this->_tally($by, $flat, $nm, $date, count($periods));
                $total += count($periods);
            }
        }

        $rows = [];
        foreach ($by as $sid => $r) {
            $rows[] = [
                'teacherId' => $sid,
                // A staff identifier is not a name. Where the records carry no
                // name at all, say so plainly rather than printing the id at
                // somebody who has no way to look one up.
                'name'      => $r['name'] !== '' ? $r['name'] : 'Unnamed staff record',
                'periods'   => $r['periods'],
                'days'      => count($r['days']),
            ];
        }
        usort($rows, static function ($x, $y) {
            if ($x['periods'] !== $y['periods']) { return $y['periods'] - $x['periods']; }
            return strcasecmp($x['name'], $y['name']);
        });

        return [
            'from'         => $from,
            'to'           => $to,
            'teachers'     => $rows,
            'totalPeriods' => $total,
            'truncated'    => $truncated,
            'notice'       => $truncated
                ? 'This log covers the ' . self::LOG_SCAN_LIMIT . ' most recent '
                . 'records in the range and is not the whole picture. Narrow the '
                . 'dates for a complete count.'
                : '',
        ];
    }

    /** Add one teacher's periods on one date into the running tally. */
    private function _tally(array &$by, string $sid, string $name,
                            string $date, int $n): void
    {
        if (!isset($by[$sid])) {
            $by[$sid] = ['name' => $name, 'periods' => 0, 'days' => []];
        }
        if ($by[$sid]['name'] === '' && $name !== '') { $by[$sid]['name'] = $name; }
        $by[$sid]['periods'] += $n;
        if ($date !== '') { $by[$sid]['days'][$date] = true; }
    }

    /**
     * What each teacher is already carrying on a date, and in its week.
     *
     * Untis shows this count beside each candidate so the person choosing can
     * spread the load; that is the mechanism the research actually supports,
     * and it works whether or not the school has set a ceiling. Returns only
     * teachers who are carrying something.
     *
     * @return array<string,array{day:int,week:int}>
     */
    public function coverLoadOn(string $dateStr): array
    {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateStr) || !strtotime($dateStr)) {
            throw new Service_exception('Invalid date format', 'validation');
        }

        [$from, $to] = $this->_weekBounds($dateStr);
        $log  = $this->coverLog($from, $to);
        $day  = $this->_countCover($this->_activeCoversOn($dateStr));

        $out = [];
        foreach ($log['teachers'] as $t) {
            $out[$t['teacherId']] = [
                'day'  => (int) ($day[$t['teacherId']] ?? 0),
                'week' => (int) $t['periods'],
            ];
        }
        return $out;
    }

    /**
     * The most recent cover policy this school set in an earlier session.
     *
     * Fails loud for the same reason the policy read does: the limits are
     * read negatively at the point of saving, so a failed search here would
     * suspend the school's rule at exactly the rollover this exists to
     * protect.
     */
    private function _previousPolicy(): ?array
    {
        try {
            $docs = $this->firebase->firestoreQueryOrFail(
                self::COLLECTION_POLICY,
                [['schoolId', '==', $this->schoolId]],
                'session', 'DESC', 20);
        } catch (\Throwable $e) {
            log_message('error', 'Substitute_service: previous-policy read failed '
                . '(school=' . $this->schoolId . '): ' . $e->getMessage());
            throw new Service_exception(
                "The school's cover policy could not be read, so nothing was "
                . 'changed. Please try again in a moment.',
                'read'
            );
        }

        $best = null;
        foreach ($docs as $doc) {
            $d = is_array($doc['data'] ?? null) ? $doc['data'] : $doc;
            $sess = (string) ($d['session'] ?? '');
            // Only sessions that came BEFORE this one. A policy written ahead
            // for next year is not in force now.
            if ($sess === '' || $sess >= $this->session) { continue; }
            if ($best === null || $sess > (string) ($best['session'] ?? '')) {
                $best = $d;
            }
        }
        return $best;
    }

    /** Monday and Sunday of the week the date falls in. */
    private function _weekBounds(string $dateStr): array
    {
        $d   = new \DateTime($dateStr);
        $dow = (int) $d->format('N');            // 1 = Monday
        $mon = (clone $d)->modify('-' . ($dow - 1) . ' days');
        $sun = (clone $mon)->modify('+6 days');
        return [$mon->format('Y-m-d'), $sun->format('Y-m-d')];
    }

    private function _responseShape(array $d): array
    {
        $absentId   = $d['absentTeacherId']   ?? $d['absent_teacher_id']   ?? '';
        $absentName = $d['absentTeacherName'] ?? $d['absent_teacher_name'] ?? '';
        $createdBy  = $d['createdByName']     ?? $d['created_by']          ?? '';
        $updatedBy  = $d['updatedByName']     ?? $d['updated_by']          ?? '';

        $d['absentTeacherId']     = $absentId;
        $d['absent_teacher_id']   = $absentId;
        $d['absentTeacherName']   = $absentName;
        $d['absent_teacher_name'] = $absentName;
        $d['createdByName']       = $createdBy;
        $d['created_by']          = $createdBy;
        $d['updatedByName']       = $updatedBy;
        $d['updated_by']          = $updatedBy;

        if (isset($d['assignments']) && is_array($d['assignments'])) {
            $d['assignments'] = array_map(function ($a) {
                if (!is_array($a)) return $a;
                $sid = $a['substituteTeacherId']   ?? $a['substitute_teacher_id']   ?? '';
                $snm = $a['substituteTeacherName'] ?? $a['substitute_teacher_name'] ?? '';
                $a['substituteTeacherId']     = $sid;
                $a['substitute_teacher_id']   = $sid;
                $a['substituteTeacherName']   = $snm;
                $a['substitute_teacher_name'] = $snm;
                return $a;
            }, $d['assignments']);
        }
        return $d;
    }

    private function _audit(string $action, string $entityType, string $entityId, ?array $before, ?array $after, array $metadata): void
    {
        if (!$this->audit) return;
        try {
            // log() returns FALSE rather than throwing when it refuses an
            // entry — an action or entityType outside its allowlist, or an
            // empty id. Discarding that return is how a whole class of
            // changes came to be unrecorded without anybody noticing.
            $ok = $this->audit->log($action, $entityType, $entityId,
                $before, $after, $metadata);
            if ($ok === false) {
                log_message('error', 'Substitute_service: audit entry REFUSED ('
                    . $action . '/' . $entityType . '/' . $entityId . ') — the '
                    . 'change happened and nothing recorded it.');
            }
        } catch (\Throwable $e) {
            log_message('error', 'substitute audit failed: ' . $e->getMessage());
        }
    }
}
