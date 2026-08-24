<?php
defined('BASEPATH') or exit('No direct script access allowed');

// HARD DEPENDENCY, LOADED EAGERLY.
//
// The class constants below (TRANSITIONS, and every state reference) are
// evaluated at CLASS-DEFINITION time and resolve Lifecycle_resolver::INST_*.
// CodeIgniter's loader does not resolve that for us, so
// `$this->load->library('institution_lifecycle')` without a prior
// `load->library('lifecycle_resolver')` throws
// `Class "Lifecycle_resolver" not found` before a single method runs.
//
// Found by real CLI execution during E2E — the unit tests missed it because
// they require_once both files in dependency order, and a compile check
// cannot see it either. Requiring it here makes the library self-sufficient
// for every caller, in any load order.
if (!class_exists('Lifecycle_resolver', false)) {
    require_once APPPATH . 'libraries/Lifecycle_resolver.php';
}

/**
 * Institution_lifecycle — the institution axis: "what is the real-world
 * operational state of this institution?"
 *
 * ──────────────────────────────────────────────────────────────────────
 *  SCOPE — what this library owns, and the much larger set it does not
 * ──────────────────────────────────────────────────────────────────────
 * OWNS   schools/{schoolId}.institution.state  (6 states)
 *        schoolLifecycleEvents/{eventId}       (append-only history)
 *
 * READS  schoolControl/{schoolId}.lifecycle.state — via Lifecycle_resolver,
 *        never written here. The commercial axis belongs to B2.
 *
 * NEVER TOUCHES students, staff, fee demands, receipts, journals, routes,
 * trips, documents, sessions, RBAC or claims. Closing a school produces a
 * WORK-LIST for humans; it never mutates a domain record. This is the
 * central safety property of the whole design and every method here is
 * written to preserve it — grep this file for a write to any collection
 * other than `schools`, `tenantPublic` and `schoolLifecycleEvents` and you
 * will find none.
 *
 * ──────────────────────────────────────────────────────────────────────
 *  STATE MACHINE (6 states, 9 legal transitions)
 * ──────────────────────────────────────────────────────────────────────
 *   PROSPECT ──▶ ONBOARDING ──▶ ACTIVE ──▶ CLOSING ──▶ CLOSED ──▶ ARCHIVED
 *      │             │                        │          │           │
 *      └──▶ CLOSED ◀─┘                        └──▶ ACTIVE│           │
 *                                              (cancel)  └──▶ ACTIVE ◀┘
 *                                                          (reopen, 2-person)
 *
 * There is deliberately NO institution SUSPENDED state: suspension is a
 * commercial fact and schoolControl.lifecycle.state already owns it. Two
 * suspension truths would guarantee divergence.
 *
 * ──────────────────────────────────────────────────────────────────────
 *  CONCURRENCY, IDEMPOTENCY, AUDIT ORDERING
 * ──────────────────────────────────────────────────────────────────────
 *  • CAS — the state write carries a Firestore `updateTime` precondition
 *    taken from the same read that validated the transition, using the
 *    proven firestoreCommitBatchStatus() contract. Two simultaneous
 *    Superadmins therefore produce exactly one winner and one explicit
 *    PRECONDITION_CONFLICT, never a lost update.
 *  • IDEMPOTENT — a transition whose target equals the current state is a
 *    no-op that writes nothing and emits no second event.
 *  • AUDIT-FIRST — the event row is created BEFORE the state write and
 *    finalized after it, so a crash mid-transition leaves a `pending` row
 *    (visible, investigable) rather than a silent state change. The row's
 *    identity and decision fields are never rewritten; only `outcome` is
 *    finalized, exactly once. Nothing ever deletes an event.
 *  • REPLAY-SAFE — the event id is derived from
 *    {schoolId}:{toState}:{effectiveDate}, so a retried request reuses the
 *    same document instead of creating a second one.
 *
 * @see Lifecycle_resolver  — composes this axis with the commercial one
 * @see B2_registry_service — owns the commercial axis
 */
class Institution_lifecycle
{
    /** Collection holding the append-only lifecycle history. Server-only. */
    const EVENTS_COLLECTION = 'schoolLifecycleEvents';

    /**
     * Legal transitions: from => [to, ...].
     * Anything absent is illegal; there is no wildcard and no escape hatch.
     */
    const TRANSITIONS = [
        Lifecycle_resolver::INST_PROSPECT   => [Lifecycle_resolver::INST_ONBOARDING, Lifecycle_resolver::INST_CLOSED],
        Lifecycle_resolver::INST_ONBOARDING => [Lifecycle_resolver::INST_ACTIVE,     Lifecycle_resolver::INST_CLOSED],
        Lifecycle_resolver::INST_ACTIVE     => [Lifecycle_resolver::INST_CLOSING],
        // CLOSING → ACTIVE cancels an announced closure. Real schools call
        // closures off; without this the only way back would be a reopen
        // from CLOSED, which would falsely record the school as having shut.
        Lifecycle_resolver::INST_CLOSING    => [Lifecycle_resolver::INST_CLOSED,     Lifecycle_resolver::INST_ACTIVE],
        Lifecycle_resolver::INST_CLOSED     => [Lifecycle_resolver::INST_ARCHIVED,   Lifecycle_resolver::INST_ACTIVE],
        Lifecycle_resolver::INST_ARCHIVED   => [Lifecycle_resolver::INST_ACTIVE],
    ];

    /**
     * Transitions requiring two-person control, as "FROM>TO".
     * Destructive or trust-critical only — no ceremony for ordinary work.
     */
    const TWO_PERSON = [
        'CLOSING>CLOSED',
        'CLOSED>ARCHIVED',
        'CLOSED>ACTIVE',
        'ARCHIVED>ACTIVE',
        'PROSPECT>CLOSED',
        'ONBOARDING>CLOSED',
    ];

    /** @var object|null */ private $firebase = null;
    /** @var bool */        private $ready    = false;

    public function init($firebase): self
    {
        $this->firebase = $firebase;
        $this->ready    = ($firebase !== null);
        return $this;
    }

    public function is_ready(): bool { return $this->ready; }

    // ═════════════════════════════════════════════════════════════════
    //  PURE CORE — no I/O
    // ═════════════════════════════════════════════════════════════════

    /**
     * Is from → to legal, and what does it require?
     *
     * @return array{allowed:bool, idempotent:bool, twoPerson:bool, error:string}
     */
    public static function can_transition($from, $to): array
    {
        $f = Lifecycle_resolver::normalize_institution($from);
        $t = strtoupper(trim((string) $to));

        if (!in_array($t, Lifecycle_resolver::INSTITUTION_STATES, true)) {
            return ['allowed' => false, 'idempotent' => false, 'twoPerson' => false, 'error' => 'unknown_target_state'];
        }
        if ($f === $t) {
            // Not an error: re-issuing the same transition is a no-op.
            return ['allowed' => false, 'idempotent' => true, 'twoPerson' => false, 'error' => ''];
        }
        $legal = self::TRANSITIONS[$f] ?? [];
        if (!in_array($t, $legal, true)) {
            return ['allowed' => false, 'idempotent' => false, 'twoPerson' => false, 'error' => 'illegal_transition'];
        }
        return [
            'allowed'    => true,
            'idempotent' => false,
            'twoPerson'  => self::requires_two_person($f, $t),
            'error'      => '',
        ];
    }

    /** Does this transition need a second authorizer? */
    public static function requires_two_person($from, $to): bool
    {
        $key = Lifecycle_resolver::normalize_institution($from) . '>' . strtoupper(trim((string) $to));
        return in_array($key, self::TWO_PERSON, true);
    }

    /** Legal next states from $from (for the console's "next permitted transitions"). */
    public static function next_states($from): array
    {
        return self::TRANSITIONS[Lifecycle_resolver::normalize_institution($from)] ?? [];
    }

    /**
     * Deterministic event id — the replay-protection key.
     * Stable for a given (school, target state, effective date).
     */
    public static function event_id(string $schoolId, string $toState, string $effectiveDate): string
    {
        $key = $schoolId . ':' . strtoupper($toState) . ':' . $effectiveDate;
        return 'LC_' . $schoolId . '_' . strtoupper($toState) . '_' . substr(sha1($key), 0, 12);
    }

    /** Is this a well-formed YYYY-MM-DD date? */
    public static function valid_effective_date($date): bool
    {
        $d = trim((string) $date);
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) return false;
        [$y, $m, $dd] = array_map('intval', explode('-', $d));
        return checkdate($m, $dd, $y);
    }

    // ═════════════════════════════════════════════════════════════════
    //  I/O
    // ═════════════════════════════════════════════════════════════════

    /**
     * Current institution state for a tenant.
     *
     * A tenant with no `institution` block resolves to ACTIVE and reports
     * explicit=false, so callers (and the console) can tell "never set" from
     * "deliberately set to ACTIVE".
     *
     * @return array{state:string, explicit:bool, since:string, effectiveDate:string,
     *               reason:string, updateTime:string, known:bool}
     */
    public function current_state(string $schoolId): array
    {
        $empty = [
            'state' => Lifecycle_resolver::INST_ACTIVE, 'explicit' => false, 'since' => '',
            'effectiveDate' => '', 'reason' => '', 'updateTime' => '', 'known' => false,
        ];
        if (!$this->ready || $schoolId === '') return $empty;

        try {
            $doc = $this->firebase->firestoreGet('schools', $schoolId);
        } catch (\Throwable $e) {
            log_message('error', 'Institution_lifecycle::current_state read failed for '
                . $schoolId . ': ' . $e->getMessage());
            return $empty;
        }
        if (!is_array($doc)) return $empty;

        $inst = is_array($doc['institution'] ?? null) ? $doc['institution'] : [];
        $raw  = (string) ($inst['state'] ?? '');

        return [
            'state'         => Lifecycle_resolver::normalize_institution($raw),
            'explicit'      => ($raw !== ''),
            'since'         => (string) ($inst['since'] ?? ''),
            'effectiveDate' => (string) ($inst['effectiveDate'] ?? ''),
            'reason'        => (string) ($inst['reason'] ?? ''),
            'updateTime'    => (string) ($doc['__updateTime'] ?? ''),
            'known'         => true,
        ];
    }

    /**
     * Perform a transition.
     *
     * @param array $opts {
     *   @type string reason         REQUIRED, free text, recorded forever.
     *   @type string actorId        REQUIRED, the real human (never 'service').
     *   @type string actorRole      e.g. 'superadmin'
     *   @type string actorSurface   e.g. 'sa_console' | 'cli'
     *   @type string effectiveDate  YYYY-MM-DD; defaults to today.
     *   @type string approverId     REQUIRED for two-person transitions, and
     *                               must differ from actorId.
     *   @type array  evidence       readiness/closure snapshot at decision time.
     *   @type string closureReason  optional, e.g. MERGED
     *   @type string mergedIntoSchoolId optional pointer (never a data move).
     * }
     * @return array{success:bool, idempotent:bool, from:string, to:string,
     *               eventId:string, error:string, message:string}
     */
    public function transition(string $schoolId, string $toState, array $opts = []): array
    {
        $fail = function (string $err, string $msg = '', string $from = '', string $to = '') {
            return ['success' => false, 'idempotent' => false, 'from' => $from, 'to' => $to,
                    'eventId' => '', 'error' => $err, 'message' => $msg !== '' ? $msg : $err];
        };

        if (!$this->ready)      return $fail('service_not_ready');
        if ($schoolId === '')   return $fail('missing_school_id');

        $to     = strtoupper(trim($toState));
        $reason = trim((string) ($opts['reason'] ?? ''));
        $actor  = trim((string) ($opts['actorId'] ?? ''));

        // Reason and a real actor are mandatory on EVERY transition. An
        // institutional act that cannot be attributed to a person, with a
        // stated motive, is not auditable — which is the defect this
        // library exists to close (see N2 in the pre-implementation analysis).
        if ($reason === '') return $fail('reason_required', 'A reason is required for every lifecycle transition.');
        if ($actor  === '') return $fail('actor_required',  'The acting administrator could not be identified.');

        $effectiveDate = trim((string) ($opts['effectiveDate'] ?? ''));
        if ($effectiveDate === '') $effectiveDate = date('Y-m-d');
        if (!self::valid_effective_date($effectiveDate)) {
            return $fail('invalid_effective_date', 'Effective date must be a valid YYYY-MM-DD date.');
        }

        // ── Read current state + CAS token ──────────────────────────────
        $cur = $this->current_state($schoolId);
        if (!$cur['known']) {
            return $fail('school_not_found', 'School record could not be read.', '', $to);
        }
        $from = $cur['state'];

        $check = self::can_transition($from, $to);
        if (!empty($check['idempotent'])) {
            return ['success' => true, 'idempotent' => true, 'from' => $from, 'to' => $to,
                    'eventId' => '', 'error' => '', 'message' => "Already {$to}; no change made."];
        }
        if (empty($check['allowed'])) {
            return $fail($check['error'] ?: 'illegal_transition',
                "Cannot move a school from {$from} to {$to}.", $from, $to);
        }

        // ── Two-person control ──────────────────────────────────────────
        $approver = trim((string) ($opts['approverId'] ?? ''));
        if (!empty($check['twoPerson'])) {
            if ($approver === '') {
                return $fail('approval_required',
                    "Moving a school from {$from} to {$to} requires a second authorizer.", $from, $to);
            }
            if (strcasecmp($approver, $actor) === 0) {
                return $fail('approver_must_differ',
                    'The approving administrator must be a different person from the requester.', $from, $to);
            }
        }

        $eventId = self::event_id($schoolId, $to, $effectiveDate);
        $nowIso  = date('c');

        // ── 1. AUDIT FIRST — claim the event row (also replay protection) ─
        $eventDoc = [
            'eventId'       => $eventId,
            'schoolId'      => $schoolId,
            'axis'          => 'institution',
            'fromState'     => $from,
            'toState'       => $to,
            'actorId'       => $actor,
            'actorRole'     => (string) ($opts['actorRole']    ?? ''),
            'actorSurface'  => (string) ($opts['actorSurface'] ?? ''),
            'approverId'    => $approver,
            'reason'        => $reason,
            'effectiveDate' => $effectiveDate,
            'evidence'      => is_array($opts['evidence'] ?? null) ? $opts['evidence'] : [],
            'ts'            => $nowIso,
            'outcome'       => 'pending',
        ];
        if (!empty($opts['closureReason']))      $eventDoc['closureReason']      = (string) $opts['closureReason'];
        if (!empty($opts['mergedIntoSchoolId'])) $eventDoc['mergedIntoSchoolId'] = (string) $opts['mergedIntoSchoolId'];

        $claim = ['status' => 'FAILURE'];
        try {
            $claim = $this->firebase->firestoreCreateStatus(self::EVENTS_COLLECTION, $eventId, $eventDoc);
        } catch (\Throwable $e) {
            log_message('error', "LC_EVENT_CLAIM_ERROR school={$schoolId} event={$eventId} err=" . $e->getMessage());
        }
        if (($claim['status'] ?? '') === 'COLLISION') {
            // A row for this exact (school, target, effective date) already
            // exists → this is a replay. Do not transition twice.
            log_message('error', "LC_TRANSITION_REPLAY school={$schoolId} event={$eventId} from={$from} to={$to} actor={$actor}");
            return ['success' => true, 'idempotent' => true, 'from' => $from, 'to' => $to,
                    'eventId' => $eventId, 'error' => '', 'message' => 'This transition was already recorded.'];
        }
        if (($claim['status'] ?? '') !== 'SUCCESS') {
            return $fail('audit_write_failed',
                'The lifecycle change was not applied because it could not be recorded in the audit log.', $from, $to);
        }

        // ── 2. STATE WRITE via the canonical CAS primitive ──────────────
        // Uses Firestore_rest_client::casUpdateEntity rather than a hand-rolled
        // read-then-precondition loop, for two reasons that are not cosmetic:
        //
        //  1. STALE-CACHE HAZARD. firestoreGet() serves `schools` from a
        //     300-second cross-request file cache AND a request-scoped memo,
        //     and the cached payload includes `__updateTime`. A precondition
        //     built from that value can be stale, which this codebase has
        //     already been bitten by once (see the commitBatch comment about
        //     the false "Another administrator updated…" 409). casUpdateEntity
        //     reads through _getDocumentUncached, bypassing both caches.
        //
        //  2. TOCTOU. The mutator below re-validates the transition against
        //     the FRESH document on every attempt, so a state that changed
        //     between our pre-check and the commit aborts cleanly instead of
        //     overwriting someone else's transition.
        //
        // It also fails closed: on exhaustion it performs NO unguarded write.
        $mutator = function (array $fresh) use ($to, $reason, $actor, $eventId, $effectiveDate, $nowIso, $opts) {
            $freshState = Lifecycle_resolver::normalize_institution(
                $fresh['institution']['state'] ?? ''
            );
            if ($freshState === $to) {
                return [];   // someone already applied it → idempotent no-op
            }
            $recheck = self::can_transition($freshState, $to);
            if (empty($recheck['allowed'])) {
                return null; // fresh state no longer permits this move → abort
            }

            $inst = [
                'state'         => $to,
                'since'         => $nowIso,
                'effectiveDate' => $effectiveDate,
                'reason'        => $reason,
                'actorId'       => $actor,
                'lastEventId'   => $eventId,
            ];
            if (!empty($opts['closureReason']))      $inst['closureReason']      = (string) $opts['closureReason'];
            if (!empty($opts['mergedIntoSchoolId'])) $inst['mergedIntoSchoolId'] = (string) $opts['mergedIntoSchoolId'];

            return ['institution' => $inst, 'updatedAt' => $nowIso];
        };

        $res = ['ok' => false, 'reason' => 'error'];
        try {
            $client = method_exists($this->firebase, 'getFirestoreDb') ? $this->firebase->getFirestoreDb() : null;
            if ($client !== null && method_exists($client, 'casUpdateEntity')) {
                $res = $client->casUpdateEntity('schools', $schoolId, $mutator);
            } else {
                // Test doubles and any client without the primitive fall back to
                // the same contract expressed through commitBatchStatus.
                $res = $this->_cas_fallback($schoolId, $mutator, $cur['updateTime']);
            }
        } catch (\Throwable $e) {
            log_message('error', "LC_TRANSITION_COMMIT_ERROR school={$schoolId} event={$eventId} err=" . $e->getMessage());
        }

        $why = (string) ($res['reason'] ?? 'error');

        if (empty($res['ok'])) {
            $this->_finalize_event($eventId, $why === 'contention' ? 'rejected_conflict' : 'failed');
            if ($why === 'contention') {
                log_message('error', "LC_CONCURRENT_REJECTED school={$schoolId} from={$from} to={$to} actor={$actor} reason=cas_contention");
                return $fail('cas_failed',
                    'Another administrator changed this school at the same time. Please reload and try again.', $from, $to);
            }
            if ($why === 'aborted') {
                log_message('error', "LC_TRANSITION_ABORTED school={$schoolId} from={$from} to={$to} actor={$actor} reason=state_changed");
                return $fail('state_changed',
                    'This school is no longer in a state that allows that change. Please reload.', $from, $to);
            }
            if ($why === 'missing') {
                return $fail('school_not_found', 'School record could not be read.', $from, $to);
            }
            return $fail('state_write_failed', 'The lifecycle change could not be applied.', $from, $to);
        }

        if (empty($res['applied'])) {
            // Clean no-op: the target state was already present on fresh read.
            $this->_finalize_event($eventId, 'applied');
            return ['success' => true, 'idempotent' => true, 'from' => $from, 'to' => $to,
                    'eventId' => $eventId, 'error' => '', 'message' => "Already {$to}; no change made."];
        }

        // ── 3. FINALIZE the audit row, then mirror (both non-blocking) ──
        $this->_finalize_event($eventId, 'applied');
        $this->_mirror($schoolId, $to, in_array($to, [Lifecycle_resolver::INST_CLOSING, Lifecycle_resolver::INST_CLOSED], true) ? $effectiveDate : '');

        log_message('error', "LC_TRANSITION_APPLIED school={$schoolId} from={$from} to={$to} "
            . "actor={$actor} approver={$approver} effective={$effectiveDate} event={$eventId}");

        return ['success' => true, 'idempotent' => false, 'from' => $from, 'to' => $to,
                'eventId' => $eventId, 'error' => '',
                'message' => "School moved from {$from} to {$to}, effective {$effectiveDate}."];
    }

    /**
     * Lifecycle history for a school, newest first.
     * Requires the composite index (schoolId ASC, ts DESC).
     */
    public function history(string $schoolId, int $limit = 50): array
    {
        if (!$this->ready || $schoolId === '') return [];
        try {
            $rows = $this->firebase->firestoreQuery(self::EVENTS_COLLECTION, [
                ['schoolId', '=', $schoolId],
            ]);
        } catch (\Throwable $e) {
            log_message('error', 'Institution_lifecycle::history query failed for '
                . $schoolId . ': ' . $e->getMessage());
            return [];
        }
        if (!is_array($rows)) return [];

        // firestoreQuery() returns rows as ['id' => …, 'data' => [...]], NOT
        // as flat documents. Treating them as flat yields rows whose every
        // field is missing, so the console's lifecycle timeline renders a
        // line of '?' with no actor, reason or date — an audit trail that
        // silently shows nothing. Unwrapped here exactly as
        // B2_registry_service::_unwrap_query_rows() does, keeping one shape
        // convention across the codebase. Found by reading a real event back.
        $out = [];
        foreach ($rows as $r) {
            if (!is_array($r)) continue;
            if (array_key_exists('id', $r) && array_key_exists('data', $r) && is_array($r['data'])) {
                $row = $r['data'];
                $row['__firestoreId'] = (string) $r['id'];
                $out[] = $row;
            } else {
                $out[] = $r;
            }
        }
        usort($out, function ($a, $b) {
            return strcmp((string) ($b['ts'] ?? ''), (string) ($a['ts'] ?? ''));
        });
        return $limit > 0 ? array_slice($out, 0, $limit) : $out;
    }

    /**
     * casUpdateEntity's contract expressed over the plain Firebase surface.
     *
     * Used only when the injected client does not expose the canonical
     * primitive — in practice, unit-test doubles. Production goes through
     * Firestore_rest_client::casUpdateEntity, which additionally bypasses the
     * read caches. Kept deliberately small and contract-identical so the
     * tests exercise the same decision tree production takes.
     *
     * @return array{ok:bool, applied:bool, reason:string, retries:int}
     */
    private function _cas_fallback(string $schoolId, callable $mutator, string $updateTime): array
    {
        for ($attempt = 0; $attempt < 3; $attempt++) {
            $doc = null;
            try {
                $doc = $this->firebase->firestoreGet('schools', $schoolId);
            } catch (\Throwable $e) {
                return ['ok' => false, 'applied' => false, 'reason' => 'error', 'retries' => $attempt];
            }
            if (!is_array($doc)) {
                return ['ok' => false, 'applied' => false, 'reason' => 'missing', 'retries' => $attempt];
            }

            $fields = $mutator($doc);
            if ($fields === null || $fields === false) {
                return ['ok' => false, 'applied' => false, 'reason' => 'aborted', 'retries' => $attempt];
            }
            if (!is_array($fields) || $fields === []) {
                return ['ok' => true, 'applied' => false, 'reason' => 'noop', 'retries' => $attempt];
            }

            $op = ['op' => 'update', 'collection' => 'schools', 'docId' => $schoolId,
                   'merge' => true, 'data' => $fields];
            $ut = (string) ($doc['__updateTime'] ?? $updateTime);
            if ($ut !== '') $op['precondition'] = ['updateTime' => $ut];

            $res = $this->firebase->firestoreCommitBatchStatus([$op]);
            $st  = (string) ($res['status'] ?? 'FAILURE');
            if ($st === 'SUCCESS') {
                return ['ok' => true, 'applied' => true, 'reason' => 'applied', 'retries' => $attempt];
            }
            if ($st !== 'PRECONDITION_CONFLICT') {
                return ['ok' => false, 'applied' => false, 'reason' => 'error', 'retries' => $attempt];
            }
            // conflict → loop, re-read, re-run the mutator against fresh state
        }
        return ['ok' => false, 'applied' => false, 'reason' => 'contention', 'retries' => 3];
    }

    /**
     * Finalize an event's outcome. The ONLY field ever rewritten on an
     * event row; identity, actor, states and reason are immutable.
     */
    private function _finalize_event(string $eventId, string $outcome): void
    {
        try {
            $this->firebase->firestoreUpdate(self::EVENTS_COLLECTION, $eventId, [
                'outcome'     => $outcome,
                'finalizedAt' => date('c'),
            ]);
        } catch (\Throwable $e) {
            // Non-fatal: the row survives as `pending`, which is visible and
            // investigable — strictly better than losing the record.
            log_message('error', "LC_EVENT_FINALIZE_FAILED event={$eventId} outcome={$outcome} err=" . $e->getMessage());
        }
    }

    /**
     * Fan out to the existing client-readable mirror so the apps can react.
     * Reuses tenantPublic — the same surface B2 already mirrors
     * lifecycleState/adminDisabled into. No second mirror is created.
     */
    private function _mirror(string $schoolId, string $state, string $effectiveDate = ''): void
    {
        try {
            $patch = [
                'institutionState' => $state,
                'mirroredAt'       => date('c'),
            ];
            // The effective date is mirrored so the apps can say "closing on
            // 31-03-2027" instead of a vague "this school is closing" — the
            // apps read this exact field.
            //
            // It is written on EVERY transition, explicitly NULL when the new
            // state carries no meaningful date. Writing it only when present
            // left the previous state's date behind: after CLOSED(2027-03-31)
            // → ARCHIVED, the mirror still advertised 2027-03-31, so a client
            // could render a closure date for an archived school. Observed on
            // a real tenant during E2E. Always writing the field keeps the
            // mirror coherent with the state it accompanies.
            $patch['institutionEffectiveDate'] = ($effectiveDate !== '') ? $effectiveDate : null;
            $this->firebase->firestoreUpdate('tenantPublic', $schoolId, $patch);
        } catch (\Throwable $e) {
            log_message('error', "LC_MIRROR_FAILED school={$schoolId} state={$state} err=" . $e->getMessage());
        }
    }
}
