<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Section_seat_guard — atomic destination-section placement (Option B).
 *
 * ══════════════════════════════════════════════════════════════════════════
 * WHAT THIS CLOSES — P20.2-RACE-1
 *
 * Every placement path checked capacity, then wrote the student, with nothing
 * in between. Two requests could both read 39/40, both pass, and both write:
 *
 *      final occupancy 41 / 40
 *
 * This guard commits the student write and a marker update to the DESTINATION
 * SECTION document in ONE Firestore `:commit`, guarded by
 * `currentDocument.updateTime` on that section. Firestore admits exactly one
 * of two concurrent commits that read the same updateTime; the loser's batch
 * rolls back ENTIRELY — no partial student write — and is refused.
 * ══════════════════════════════════════════════════════════════════════════
 *
 * ── WHAT THIS IS NOT ────────────────────────────────────────────────────────
 * NOT a second occupancy source. The marker is an opaque timestamp. Occupancy
 * is still derived exclusively from student documents via
 * MY_Controller::_filterSectionRoster(). Deleting the marker would cost
 * conflict detection and nothing else.
 *
 * NOT serializable isolation. This is atomic multi-document write plus
 * optimistic concurrency. Under contention a conflicting placement is REFUSED,
 * never silently admitted.
 *
 * ── WHY THE MARKER IS A TIMESTAMP, NOT A COUNTER ────────────────────────────
 * A numeric `seatVersion` invites a future reader to treat it as a seat count
 * — which would create the second occupancy authority this design exists to
 * avoid (SEC-11). An ISO timestamp cannot plausibly be mistaken for one.
 *
 * ── NO SERVER-SIDE RETRY, AND WHY (deviation — see report §6) ───────────────
 * Re-deciding after a conflict requires FRESH capacity and occupancy. Both
 * canonical helpers are per-request memoised with function-level `static`
 * (MY_Controller:1330 `_sectionCapacity`, :1390
 * `_cachedStudentDocsForRollCheck`) and expose no reset. A same-request retry
 * would therefore re-derive the IDENTICAL stale answer — a provably useless
 * loop that would refuse every contended placement anyway. Clearing those
 * memos requires modifying MY_Controller, a PROTECTED file.
 *
 * So a conflict REFUSES with an actionable message and the operator resubmits
 * — a fresh request with fresh memos. The safety guarantee is unaffected:
 * the loser never writes, so capacity can never be exceeded. Only availability
 * under (rare) contention is affected. MAX_ATTEMPTS documents the bound.
 *
 * ── IDEMPOTENCY ─────────────────────────────────────────────────────────────
 * This guard mints no identity. It commits a caller-supplied payload whose
 * document id is already deterministic. A refused placement writes NOTHING, so
 * no SRNO, Student ID or Admission No can be issued twice by it.
 */
class Section_seat_guard
{
    /**
     * Commit attempts per placement. 1 = the initial attempt, no server-side
     * retry (see the header). Raising this is unsafe until the canonical
     * capacity/roster memos can be refreshed mid-request.
     */
    const MAX_ATTEMPTS = 1;

    /** Opaque CAS marker written on the section document. Never a count. */
    const MARKER_FIELD = 'seatCasAt';

    const PLACED   = 'PLACED';    // committed atomically
    const CONFLICT = 'CONFLICT';  // another writer won; nothing was written
    const FAILURE  = 'FAILURE';   // unknown outcome; nothing is assumed

    private $fs;
    private $firebase;
    private $schoolId = '';
    private $actorId  = '';

    public function init($fs, $firebase, string $schoolId, string $actorId = ''): void
    {
        $this->fs       = $fs;
        $this->firebase = $firebase;
        $this->schoolId = $schoolId;
        $this->actorId  = $actorId;
    }

    /**
     * Is the atomic capacity authority switched on?
     *
     * Single all-or-nothing gate for all five seat-consuming paths — see
     * application/config/capacity_cas.php for why there is no per-path flag.
     * Defaults to FALSE if the config is missing, so a deployment that lacks
     * the file behaves exactly as it did before this feature.
     */
    /**
 * ── A MISSING CONFIG MUST NOT DISABLE A SAFETY GUARD ──────────────────
 *
 * This used to `return false` whenever the config could not be read, and
 * `capacity_cas.php` was never committed. So every clean checkout silently
 * took the UNGUARDED placement branch: five concurrent admissions into a
 * maxStrength=2 section produced six students, with nothing logged. A
 * feature that turns itself off when a file is absent is worse than one
 * that is simply off, because the absence is invisible.
 *
 * Enforcement is now the DEFAULT and the fail-closed direction: only an
 * explicit, readable `atomic_capacity_enabled = false` disables it. A
 * missing or unreadable config enforces anyway and says so, because the
 * guard needs no configuration to work correctly — the config exists to
 * permit a deliberate opt-out, not to grant permission to be safe.
 */
    public static function enabled(): bool
    {
        $CI = &get_instance();
        try {
            $CI->config->load('capacity_cas', false, true);
            $v = $CI->config->item('atomic_capacity_enabled');
        } catch (\Throwable $e) {
            $v = null;
        }
        if ($v === null) {
            log_message('error', 'ACC_SEAT_CONFIG_MISSING — capacity_cas.php absent or '
                . 'unreadable; ENFORCING atomic capacity anyway. A safety guard must not '
                . 'disable itself because a configuration file is missing.');
            return true;
        }
        return (bool) $v;
    }

    /** Max student writes per destination group (499 + 1 marker = 500). */
    public static function groupMaxStudents(): int
    {
        $CI = &get_instance();
        try {
            $CI->config->load('capacity_cas', false, true);
            $n = (int) $CI->config->item('capacity_group_max_students');
            return $n > 0 ? $n : 499;
        } catch (\Throwable $e) {
            return 499;
        }
    }

    /**
     * ── BULK: place MANY students into ONE destination section atomically ────
     *
     * The bulk counterpart to placeStudent(). Import, promotion and transfer
     * move students in loops; issuing one section read + one CAS commit per
     * student would cost an extra round-trip each (~1-3s in this environment)
     * and is the N+1 the design forbids. Instead the caller groups by
     * destination section and calls this ONCE per group:
     *
     *      1 section read  +  1 atomic commit of (N student writes + 1 marker)
     *
     * ATOMIC OR NOTHING: if the section's updateTime has moved, Firestore
     * rejects the whole batch and NOT ONE student in the group is written.
     * There is no partial group.
     *
     * REFUSES a group larger than groupMaxStudents() rather than chunking —
     * chunking would break the guarantee (see the config file).
     *
     * @param array $students [ ['studentId'=>string, 'data'=>array], ... ]
     * @return array{status:string, message:string, attempts:int, count:int}
     */
    public function placeStudentGroup(
        $entitySync,
        array $students,
        string $classNode,
        string $sectionNode,
        string $session,
        string $decisionVersion = ''
    ): array {
        $n = count($students);
        if ($n === 0) {
            return ['status' => self::PLACED, 'message' => '', 'attempts' => 0, 'count' => 0];
        }

        $max = self::groupMaxStudents();
        if ($n > $max) {
            return [
                'status'  => self::FAILURE,
                'message' => "This operation would place {$n} students into {$classNode} / {$sectionNode} "
                           . "in one step. The safe maximum is {$max}. Nothing was changed — "
                           . 'split the operation into smaller batches by section and retry.',
                'attempts' => 0,
                'count'    => 0,
            ];
        }

        $writes = [];
        foreach ($students as $s) {
            $sid  = (string) ($s['studentId'] ?? '');
            $data = is_array($s['data'] ?? null) ? $s['data'] : [];
            if ($sid === '') continue;

            $built = $entitySync->buildStudentDoc($sid, $data);
            if (!is_array($built) || empty($built['docId'])) {
                // One unpreparable row invalidates the group: committing the
                // rest would silently drop a student the caller believes was
                // placed, and the group must be all-or-nothing.
                return [
                    'status'  => self::FAILURE,
                    'message' => "Could not prepare the record for student {$sid}. "
                               . 'Nothing was changed for this section.',
                    'attempts' => 0,
                    'count'    => 0,
                ];
            }
            $writes[] = [
                'collection' => 'students',
                'docId'      => $built['docId'],
                'data'       => $built['doc'],
                'merge'      => true,
            ];
        }

        $res = $this->commitPlacement($classNode, $sectionNode, $session, $writes, $decisionVersion);
        $res['count'] = ($res['status'] === self::PLACED) ? count($writes) : 0;
        return $res;
    }

    /**
     * Place ONE student atomically — the entry point every seat-consuming path
     * uses, so none can diverge or bypass the guard.
     *
     * Builds the student payload with the SAME code syncStudent() uses
     * (Entity_firestore_sync::buildStudentDoc), then commits it with the
     * section CAS marker in one batch.
     *
     * Mints no identity: `$studentId` is already fixed by the caller, so the
     * document id is deterministic and a refusal writes nothing.
     *
     * @return array{status:string, message:string, attempts:int}
     */
    public function placeStudent(
        $entitySync,
        string $studentId,
        array $studentData,
        string $classNode,
        string $sectionNode,
        string $session,
        string $decisionVersion = ''
    ): array {
        $built = $entitySync->buildStudentDoc($studentId, $studentData);
        if (!is_array($built) || empty($built['docId'])) {
            return [
                'status'  => self::FAILURE,
                'message' => 'Could not prepare the student record. Nothing was changed.',
                'attempts' => 0,
            ];
        }

        return $this->commitPlacement($classNode, $sectionNode, $session, [[
            'collection' => 'students',
            'docId'      => $built['docId'],
            'data'       => $built['doc'],
            'merge'      => true,
        ]], $decisionVersion);
    }

    /**
     * Commit one or more student writes atomically against a destination
     * section's CAS marker.
     *
     * The CALLER must already have evaluated capacity with the canonical gate
     * (`_sectionCapacityExceeded`). This guard does NOT re-decide capacity —
     * it makes the decision the caller reached unfalsifiable by a concurrent
     * writer. Two gates, one decision:
     *
     *      caller's capacity check  →  "is there room right now?"
     *      this guard's CAS         →  "has anything changed since I looked?"
     *
     * @param string $classNode   canonical, e.g. "Class 9th"
     * @param string $sectionNode canonical, e.g. "Section A"
     * @param string $session     YYYY-YY
     * @param array  $writes      [['collection'=>..,'docId'=>..,'data'=>..,'merge'=>bool], ...]
     * @return array{status:string, message:string, attempts:int}
     */
    /** Race forensics. Enabled only by ZX_RACE_LOG; writes nothing otherwise. */
    public static function trace(string $event, array $kv = []): void
    {
        $p = getenv('ZX_RACE_LOG'); if (!$p) return;
        $cid = getenv('ZX_CID') ?: '?';
        $line = sprintf('%.6f %-6s %-22s', microtime(true), $cid, $event);
        foreach ($kv as $k => $v) $line .= " {$k}=" . (is_scalar($v) ? $v : json_encode($v));
        @file_put_contents($p, $line . "
", FILE_APPEND | LOCK_EX);
    }

    public function commitPlacement(string $classNode, string $sectionNode, string $session, array $writes, string $decisionVersion = ''): array
    {
        if (empty($writes)) {
            return ['status' => self::FAILURE, 'message' => 'no writes supplied', 'attempts' => 0];
        }
        if ($classNode === '' || $sectionNode === '' || $session === '') {
            return ['status' => self::FAILURE, 'message' => 'incomplete section identity', 'attempts' => 0];
        }

        // Canonical identity — never hand-built (SEC-2).
        $sectionDocId = $this->fs->sectionDocId($classNode, $sectionNode, $session);

        // Fresh-enough read: `sections` is not in the cross-request config
        // cache, and the request-scoped read memo is flushed on every write,
        // so this is the same document the caller's capacity check just read —
        // a memo HIT costing zero extra round-trips (§24), carrying the
        // __updateTime we need.
        $secDoc = $this->fs->get('sections', $sectionDocId);
        if (!is_array($secDoc)) {
            return [
                'status'  => self::FAILURE,
                'message' => "destination section {$classNode}/{$sectionNode} not found in {$session}",
                'attempts' => 0,
            ];
        }

        // ── SEAT-RACE-1 FIX ─────────────────────────────────────────────
        // The precondition must be the version the CAPACITY DECISION was made
        // against, not one re-read here. Reading it here narrowed the guarded
        // interval to the microseconds around the commit and excluded the
        // decision entirely, so concurrent admissions each saw a fresh valid
        // version and all of them committed — 5 admitted into maxStrength=2,
        // with no CAS conflict raised, because none of them ever conflicted.
        //
        // Every successful placement writes the marker below, which bumps the
        // section version, so a competitor still holding the pre-decision
        // version now fails its precondition and is refused instead of
        // consuming a seat that has already gone.
        //
        // Fails CLOSED: without a caller-supplied version there is no way to
        // prove the decision is still valid, and a placement that cannot be
        // proven safe must not be written. The self-read below survives only
        // to prove the section exists and to surface a missing __updateTime.
        $selfRead   = trim((string) ($secDoc['__updateTime'] ?? ''));
        self::trace('guard.read', ['selfRead' => substr($selfRead, 11, 15), 'decision' => substr($decisionVersion, 11, 15), 'sec' => $sectionDocId]);
        $updateTime = trim($decisionVersion);
        if ($updateTime === '') {
            log_message('error',
                "ACC_SEAT_NO_DECISION_VERSION schoolId={$this->schoolId} "
                . "section={$classNode}/{$sectionNode} session={$session} — refusing: the "
                . 'capacity decision carried no section version, so it cannot be proven '
                . 'still valid at commit time.');
            return [
                'status'  => self::FAILURE,
                'message' => 'Could not establish a concurrency guard for this section. '
                           . 'Nothing was changed. Please retry.',
                'attempts' => 0,
            ];
        }
        if ($selfRead === '') {
            // Without a precondition there is NO concurrency guarantee. Refuse
            // rather than commit an unguarded write that merely looks atomic.
            log_message('error',
                "ACC_SEAT_CAS_NO_UPDATETIME schoolId={$this->schoolId} "
                . "section={$classNode}/{$sectionNode} session={$session} — refusing unguarded placement");
            return [
                'status'  => self::FAILURE,
                'message' => 'Could not establish a concurrency guard on the destination section. '
                           . 'Nothing was changed. Please retry.',
                'attempts' => 0,
            ];
        }

        $ops = [];
        foreach ($writes as $w) {
            if (!is_array($w) || empty($w['collection']) || empty($w['docId'])) continue;
            $ops[] = [
                'op'         => 'update',
                'collection' => (string) $w['collection'],
                'docId'      => (string) $w['docId'],
                'data'       => is_array($w['data'] ?? null) ? $w['data'] : [],
                'merge'      => array_key_exists('merge', $w) ? (bool) $w['merge'] : true,
            ];
        }
        if (empty($ops)) {
            return ['status' => self::FAILURE, 'message' => 'no valid writes', 'attempts' => 0];
        }

        // The CAS-guarded marker. merge => true so it creates the field on a
        // section that has never carried it — no backfill, no migration, and
        // no initialisation race (the precondition is on updateTime, which
        // every existing document already has).
        $ops[] = [
            'op'           => 'update',
            'collection'   => 'sections',
            'docId'        => $sectionDocId,
            'merge'        => true,
            // ── THE MARKER MUST ALWAYS CHANGE THE DOCUMENT ────────────────
            // This wrote date('c') — ONE-SECOND resolution. Concurrent
            // placements inside the same second therefore wrote a BYTE-IDENTICAL
            // value, Firestore treated the write as a no-op, and updateTime did
            // not advance. The precondition of the next placement then still
            // matched, so several commits succeeded against the SAME version and
            // each consumed a seat. Captured directly: three commits with
            // precond=08:25:17.769138 all returned SUCCESS, giving occupancy 5 in
            // a maxStrength=2 section.
            //
            // A CAS marker only works if writing it is always a real change, so
            // the value carries microsecond time AND a random nonce. Two writers
            // cannot collide, and the version advances on every seat consumed —
            // which is what makes a successful placement invalidate every
            // competing decision built on the prior state.
            'data'         => [self::MARKER_FIELD => sprintf(
                '%s.%06d#%s', date('c'), (int) ((microtime(true) - (int) microtime(true)) * 1e6),
                bin2hex(random_bytes(6))
            )],
            'precondition' => ['updateTime' => $updateTime],
        ];

        $attempts = 0;
        $last = ['status' => 'FAILURE', 'code' => 0, 'message' => 'not attempted'];

        while ($attempts < self::MAX_ATTEMPTS) {
            $attempts++;
            try {
                $last = $this->firebase->firestoreCommitBatchStatus($ops);
                self::trace('guard.commit', ['precond' => substr($updateTime, 11, 15), 'status' => ($last['status'] ?? '?'), 'ops' => count($ops)]);
            } catch (\Throwable $e) {
                log_message('error', 'ACC_SEAT_CAS_EXCEPTION ' . $e->getMessage());
                $last = ['status' => 'FAILURE', 'code' => 0, 'message' => $e->getMessage()];
            }

            if (($last['status'] ?? '') === 'SUCCESS') {
                return ['status' => self::PLACED, 'message' => '', 'attempts' => $attempts];
            }

            if (($last['status'] ?? '') === 'PRECONDITION_CONFLICT') {
                // Nothing was written — Firestore rolled the whole batch back.
                // We cannot re-decide in-request (see header), so stop here.
                break;
            }

            // FAILURE — the outcome is UNKNOWN. Never retried: the student
            // write may already have reached Firestore, and re-committing an
            // unknown outcome is how duplicates are made.
            break;
        }

        if (($last['status'] ?? '') === 'PRECONDITION_CONFLICT') {
            log_message('error',
                "ACC_SEAT_CAS_CONFLICT schoolId={$this->schoolId} actor={$this->actorId} "
                . "section={$classNode}/{$sectionNode} session={$session} attempts={$attempts}");
            return [
                'status'  => self::CONFLICT,
                'message' => 'Another change to this section completed while your request was in '
                           . 'progress, so nothing was saved. Please review the section and submit again.',
                'attempts' => $attempts,
            ];
        }

        log_message('error',
            "ACC_SEAT_CAS_FAILURE schoolId={$this->schoolId} actor={$this->actorId} "
            . "section={$classNode}/{$sectionNode} session={$session} "
            . "code=" . ($last['code'] ?? 0) . " msg=" . ($last['message'] ?? ''));
        return [
            'status'  => self::FAILURE,
            'message' => 'The placement could not be completed and its outcome is unknown. '
                       . 'Please reload and check whether it was saved before retrying.',
            'attempts' => $attempts,
        ];
    }
}
