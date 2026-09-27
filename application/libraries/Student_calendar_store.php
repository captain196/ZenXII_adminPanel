<?php
defined('BASEPATH') or exit('No direct script access allowed');

require_once APPPATH . 'libraries/Student_calendar_service.php';

/**
 * Student_calendar_store — the ONLY writer of the student academic calendar.
 *
 * Completes the domain. Until this existed the calendar could be read but never
 * configured, which is the same defect as CAL-D10 inverted: `working_days` was
 * configurable and unread, and the student calendar was read and unconfigurable.
 * Either way the school could not actually state its policy.
 *
 * APPEND-ONLY. THIS IS THE HISTORICAL GUARANTEE.
 * ----------------------------------------------
 * A school week is never edited and never deleted. A change is a NEW version
 * with its own effectiveFrom, and resolution picks the version in force on each
 * date. That is what makes "changing the week in March cannot change what
 * January meant" true by construction rather than by discipline — the January
 * version is still there, unmodified, and still the one selected for January.
 *
 * The same reasoning as TT-C8: published history is immutable, and a correction
 * is a new record rather than an in-place edit.
 *
 * CONCURRENCY — COMPARE-AND-SET, NOT LAST-WRITE-WINS
 * --------------------------------------------------
 * Two administrators saving a version at the same time is an ordinary event, and
 * a plain merge write would silently drop one of them: both read a list of N,
 * both write a list of N+1, and the second overwrites the first's version with
 * its own. The lost version is not a cosmetic bug — the school would believe a
 * policy is in force that is not.
 *
 * So the write carries the document's `updateTime` as a precondition. If
 * anything changed the document between the read and the write, the commit is
 * REFUSED and reported as retryable, never merged blindly.
 *
 * FAILURE SEMANTICS
 * -----------------
 * Only `SUCCESS` is success. `PRECONDITION_CONFLICT` is a retryable refusal.
 * Every other outcome — FAILURE, UNKNOWN, empty — is a failure and is reported
 * as one. An UNKNOWN commit is never assumed to have worked; the caller is told
 * the truth so it can re-read rather than double-write.
 *
 * WHAT THIS CLASS WILL NOT DO
 * ---------------------------
 *   - It never writes a calendar a school did not state. No seeding, no
 *     inference from `working_days`, no legacy pattern materialised behind the
 *     administrator's back.
 *   - It never touches staff work schedules.
 *   - It never edits or removes an existing version.
 */
class Student_calendar_store
{
    const COLLECTION = 'attendanceSettings';
    const CONFIG_KEY = 'studentCalendar';

    /** Outcomes. */
    const OK       = 'ok';
    const INVALID  = 'invalid';
    const CONFLICT = 'conflict';
    const FAILED   = 'failed';

    /** @var object|null Firebase library (REST client wrapper) */
    private $firebase = null;
    private string $schoolId = '';
    private string $session  = '';
    private string $actorId  = '';

    public function init($firebase, string $schoolId, string $session, string $actorId = ''): self
    {
        $this->firebase = $firebase;
        $this->schoolId = $schoolId;
        $this->session  = $session;
        $this->actorId  = $actorId;
        return $this;
    }

    /**
     * Append a new school-week version.
     *
     * @param array  $proposed       effectiveFrom + weeklyOffs / nthWeekdayOffs
     * @param string $settledThrough ISO date through which attendance is treated
     *                               as settled; a version taking effect on or
     *                               before it is REFUSED. The caller supplies
     *                               this — see Student_calendar::_settled_through().
     *
     * @return array{status:string, errors:array, versions:array, message:string}
     */
    public function saveVersion(array $proposed, string $settledThrough): array
    {
        if ($this->firebase === null || $this->schoolId === '') {
            return $this->out(self::FAILED, ['The calendar store is not initialised.']);
        }

        // ── read ────────────────────────────────────────────────────────
        // Fail LOUD. A read that silently returned [] would look like "this
        // school has no versions yet" and the append would then overwrite the
        // real list with a single element — destroying every historical policy
        // and, with it, the meaning of every past date.
        try {
            $doc = $this->firebase->firestoreGetOrFail(self::COLLECTION, $this->schoolId);
        } catch (\Throwable $e) {
            log_message('error', 'Student_calendar_store: settings unreadable for '
                . $this->schoolId . ' — ' . $e->getMessage());
            return $this->out(self::FAILED, [
                'The attendance settings could not be read, so the school week was not saved. '
                . 'Nothing has been changed. Please try again.',
            ]);
        }

        $doc        = is_array($doc) ? $doc : [];
        $updateTime = (string) ($doc['__updateTime'] ?? '');
        $existing   = [];
        $cal        = $doc[self::CONFIG_KEY] ?? null;
        if (is_array($cal) && is_array($cal['versions'] ?? null)) {
            $existing = array_values($cal['versions']);
        }

        // ── validate ────────────────────────────────────────────────────
        $v = Student_calendar_service::validateVersionAgainst(
            $proposed, $existing, $settledThrough);
        if (empty($v['ok'])) {
            return $this->out(self::INVALID, $v['errors'], $existing);
        }

        // ── append ──────────────────────────────────────────────────────
        $record = [
            'effectiveFrom'  => (string) $proposed['effectiveFrom'],
            'weeklyOffs'     => array_values(array_map('strval',
                is_array($proposed['weeklyOffs'] ?? null) ? $proposed['weeklyOffs'] : [])),
            'nthWeekdayOffs' => array_values(
                is_array($proposed['nthWeekdayOffs'] ?? null) ? $proposed['nthWeekdayOffs'] : []),
            'label'          => trim((string) ($proposed['label'] ?? '')),
            'reason'         => trim((string) ($proposed['reason'] ?? '')),
            'session'        => $this->session,
            'createdAt'      => gmdate('c'),
            'createdBy'      => $this->actorId,
        ];

        $merged = $existing;
        $merged[] = $record;
        usort($merged, fn($a, $b) =>
            strcmp((string) ($a['effectiveFrom'] ?? ''), (string) ($b['effectiveFrom'] ?? '')));

        // ── commit, guarded ─────────────────────────────────────────────
        if (!method_exists($this->firebase, 'firestoreCommitBatchStatus')) {
            // Refuse rather than write unguarded. A lost version means the school
            // believes a policy is in force that is not.
            log_message('error', 'Student_calendar_store: no CAS primitive available; '
                . 'refusing to write the school week unguarded');
            return $this->out(self::FAILED, [
                'The school week could not be saved safely. Nothing has been changed.',
            ], $existing);
        }

        $res = $this->firebase->firestoreCommitBatchStatus([[
            'op'           => 'update',
            'collection'   => self::COLLECTION,
            'docId'        => $this->schoolId,
            'merge'        => true,
            'data'         => [
                self::CONFIG_KEY => [
                    'versions'  => $merged,
                    'updatedAt' => gmdate('c'),
                    'updatedBy' => $this->actorId,
                ],
                // Tenant binding written explicitly, so the document can never be
                // mistaken for another school's even if it is copied.
                'schoolId' => $this->schoolId,
            ],
            'precondition' => ($updateTime !== '')
                ? ['updateTime' => $updateTime]
                : ['exists' => false],
        ]]);

        $status = (string) ($res['status'] ?? 'FAILURE');

        if ($status === 'SUCCESS') {
            return $this->out(self::OK, [], $merged,
                'The school week was saved and takes effect from '
                . $record['effectiveFrom'] . '. Past dates are unchanged.');
        }

        if ($status === 'PRECONDITION_CONFLICT') {
            return $this->out(self::CONFLICT, [
                'Someone else changed the attendance settings while this was open. '
                . 'Nothing has been saved. Reload and try again.',
            ], $existing);
        }

        // FAILURE, UNKNOWN, or anything unrecognised. Never reported as success:
        // an UNKNOWN commit may or may not have landed, and telling the caller it
        // worked would invite a double-write.
        log_message('error', 'Student_calendar_store: commit returned ' . $status
            . ' for ' . $this->schoolId . ' — ' . (string) ($res['message'] ?? ''));
        return $this->out(self::FAILED, [
            'The school week could not be saved. Reload to see the current settings '
            . 'before trying again.',
        ], $existing);
    }

    private function out(string $status, array $errors, array $versions = [], string $msg = ''): array
    {
        return [
            'status'   => $status,
            'errors'   => array_values($errors),
            'versions' => $versions,
            'message'  => $msg,
        ];
    }
}
