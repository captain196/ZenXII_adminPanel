<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Fee_reminder_service — Phase 15.7.
 *
 * Automatic fee reminders. Closes the H-1 defect properly: Phase 15.5 honestly
 * DISABLED the misleading `auto_remind` control because nothing consumed it;
 * this library is the consumer that makes it real.
 *
 * ──────────────────────────────────────────────────────────────────────
 *  DISPATCH — reuses the existing architecture, adds nothing
 * ──────────────────────────────────────────────────────────────────────
 * Enqueues through Push_enqueue_service (the shared path that
 * MY_Controller::emit_push also uses) onto `pushRequests` with the
 * pre-registered `FEE_DUE` mark. The Cloud Function MARK_REGISTRY remains the
 * ONLY dispatcher. No second sender, no second notification architecture.
 *
 * FEE_DUE's registry row (functions/index.js) declares:
 *     audience USERS · idField 'studentId' · dataKeys ['amount','dueDate']
 * so the payload below supplies exactly those.
 *
 * ──────────────────────────────────────────────────────────────────────
 *  SAFETY PROPERTIES
 * ──────────────────────────────────────────────────────────────────────
 *  • DUPLICATE PREVENTION is structural. The dedupe key is
 *    `feedue_{studentId}_{demandId}_{stage}` and Push_enqueue_service writes
 *    to the deterministic doc id `{schoolId}_{dedupeKey}`. Re-running the job
 *    — same night, next night, or after a crash — coalesces onto the same
 *    document instead of notifying a family twice. Each STAGE notifies once.
 *  • PAYMENT-STATE RE-CHECK. Eligibility is computed from a bulk query, but a
 *    parent can pay between that query and the enqueue. Every candidate is
 *    RE-READ immediately before enqueue and dropped if it is now paid or
 *    archived. Dunning a family that has already paid is the single most
 *    damaging failure of a reminder system, so this correctness cost is
 *    accepted deliberately (see the read budget below).
 *  • TENANT ISOLATION is structural — the cron passes one schoolId and every
 *    query goes through Firestore_service::schoolWhere.
 *  • SESSION ISOLATION — the demand query filters on session.
 *  • NEVER notifies for paid, archived, zero-balance or dateless demands.
 *  • DRY RUN reports exactly who would be notified and enqueues nothing.
 *
 * ──────────────────────────────────────────────────────────────────────
 *  READ / WRITE BUDGET
 * ──────────────────────────────────────────────────────────────────────
 *   1 settings read + 1 session-scoped demand query + R re-reads + R enqueues
 * where R = candidates that passed eligibility (NOT the student count). On a
 * quiet night R is 0. This is deliberately not one query per student.
 */
class Fee_reminder_service
{
    /**
     * PA-FEE-2 — demand statuses that must never generate a reminder.
     * `paid` is settled; the rest are money the school has decided not to
     * collect. Mirrors Fee_defaulter_check::NON_COLLECTABLE_STATUSES (plus
     * `paid`, which that class excludes via its balance check instead).
     */
    private const NON_COLLECTABLE_STATUSES = ['paid', 'archived', 'frozen', 'reversed'];

    private const COL_DEMANDS  = 'feeDemands';
    private const COL_SETTINGS = 'feeSettings';
    private const MARK         = 'FEE_DUE';

    /** @var object */ private $firebase;
    /** @var object */ private $fs;
    /** @var object|null */ private $enqueue = null;

    private string $schoolId = '';
    private string $session  = '';
    private bool   $ready    = false;
    private ?array $settingsMemo = null;

    public function init($firebase, $fs, string $schoolId, string $session, $enqueue = null): self
    {
        $this->firebase = $firebase;
        $this->fs       = $fs;
        $this->schoolId = $schoolId;
        $this->session  = $session;
        $this->enqueue  = $enqueue;
        $this->ready    = ($schoolId !== '' && $session !== '');
        return $this;
    }

    // ── Settings ─────────────────────────────────────────────────────

    /**
     * Reminder policy. Memoised — one settings read per run, never per student.
     *
     * Returns ['enabled'=>bool,'daysBefore'=>int[],'onDue'=>bool,
     *          'afterDays'=>int[],'message'=>string,'reason'=>string]
     */
    public function settings(): array
    {
        if ($this->settingsMemo !== null) return $this->settingsMemo;

        $off = ['enabled' => false, 'daysBefore' => [], 'onDue' => false,
                'afterDays' => [], 'message' => '', 'reason' => 'Auto reminders not enabled'];
        if (!$this->ready) return $this->settingsMemo = $off;

        try {
            $s = $this->firebase->firestoreGet(
                self::COL_SETTINGS, "{$this->schoolId}_{$this->session}_reminders"
            );
        } catch (\Throwable $e) {
            log_message('error', 'Fee_reminder_service::settings read failed: ' . $e->getMessage());
            // Fail CLOSED — an unreadable policy must never cause a broadcast.
            return $this->settingsMemo = array_merge($off, ['reason' => 'Reminder settings unreadable']);
        }
        if (!is_array($s) || !($s['auto_remind'] ?? false)) {
            return $this->settingsMemo = $off;
        }

        // days_before_due is the school's own pre-existing setting (e.g.
        // [7,3,1]). It was stored but never consumed before this phase.
        $before = [];
        foreach ((array) ($s['days_before_due'] ?? []) as $d) {
            $d = (int) $d;
            if ($d > 0 && $d <= 365) $before[$d] = $d;
        }
        krsort($before);

        $after = [];
        foreach ((array) ($s['days_after_due'] ?? []) as $d) {
            $d = (int) $d;
            if ($d > 0 && $d <= 365) $after[$d] = $d;
        }
        ksort($after);

        return $this->settingsMemo = [
            'enabled'    => true,
            'daysBefore' => array_values($before),
            'onDue'      => (bool) ($s['remind_on_due'] ?? true),
            'afterDays'  => array_values($after),
            'message'    => trim((string) ($s['reminder_message'] ?? '')),
            'reason'     => '',
        ];
    }

    // ── Eligibility ──────────────────────────────────────────────────

    /**
     * Which reminder stage (if any) a demand qualifies for TODAY.
     *
     * Pure and public so the rule is directly testable and can never be
     * re-implemented elsewhere. Returns e.g. 'before_7', 'due', 'after_3',
     * or null.
     *
     * Stages are evaluated newest-first (on-due beats before, after beats
     * both) and only ONE stage can match on a given day, so a family gets at
     * most one fee reminder per demand per day.
     */
    public function stageFor(array $demand, array $settings, string $today): ?string
    {
        $status = strtolower((string) ($demand['status'] ?? 'unpaid'));

        // ── PA-FEE-2 · NEVER CHASE MONEY THE SCHOOL WROTE OFF ────────────
        // This was `paid || archived`, so a `frozen` demand — the fee book of
        // a student who has been deactivated or issued a TC — still produced
        // reminders. That is the most harmful form of this defect in the
        // codebase: it does not merely misreport a figure internally, it sends
        // a family a dunning message for money the school has already decided
        // not to collect, after their child has left.
        //
        // `reversed` (admission reversed) is the same category.
        // Canonical rationale: Fee_defaulter_check::NON_COLLECTABLE_STATUSES.
        // Not shared from there because the natural common home,
        // Fee_firestore_txn, belongs to the in-flight payment release.
        if (in_array($status, self::NON_COLLECTABLE_STATUSES, true)) return null;

        $balance = (float) ($demand['balance'] ?? 0);
        if ($balance <= 0.005) return null;   // nothing outstanding → nothing to chase

        $due = (string) ($demand['due_date'] ?? $demand['dueDate'] ?? '');
        // Dateless historical demands cannot be scheduled against. They are
        // reported by the due-date audit, never silently guessed at here.
        if ($due === '') return null;

        $dayDiff = (int) floor(
            (strtotime($due) - strtotime($today)) / 86400
        );

        if ($dayDiff === 0) {
            return ($settings['onDue'] ?? false) ? 'due' : null;
        }
        if ($dayDiff > 0) {
            // Upcoming
            return in_array($dayDiff, (array) ($settings['daysBefore'] ?? []), true)
                ? 'before_' . $dayDiff : null;
        }
        // Overdue
        $overdueBy = abs($dayDiff);
        return in_array($overdueBy, (array) ($settings['afterDays'] ?? []), true)
            ? 'after_' . $overdueBy : null;
    }

    /** Stable, collision-free dedupe token. One notification per stage. */
    public function dedupeKey(string $studentId, string $demandId, string $stage): string
    {
        return "feedue_{$studentId}_{$demandId}_{$stage}";
    }

    // ── Execution ────────────────────────────────────────────────────

    /**
     * Scan the session's demands and enqueue one FEE_DUE per eligible
     * (student, demand, stage).
     */
    public function run(bool $dryRun = false, ?string $today = null): array
    {
        if (!$this->ready) {
            return ['enqueued' => 0, 'candidates' => 0, 'message' => 'Not initialised'];
        }
        $settings = $this->settings();
        if (!$settings['enabled']) {
            return ['enqueued' => 0, 'candidates' => 0, 'message' => $settings['reason']];
        }
        $today = $today ?: date('Y-m-d');

        try {
            $rows = $this->fs->schoolWhere(self::COL_DEMANDS, [
                ['session', '==', $this->session],
            ]);
        } catch (\Throwable $e) {
            log_message('error', 'Fee_reminder_service::run demand query failed: ' . $e->getMessage());
            return ['enqueued' => 0, 'candidates' => 0, 'message' => 'Demand query failed'];
        }

        $scanned = 0; $candidates = []; $details = [];
        foreach ((array) $rows as $r) {
            $id = (string) ($r['id'] ?? '');
            $d  = $r['data'] ?? [];
            if ($id === '' || !is_array($d)) continue;
            $scanned++;
            $n = Fee_firestore_txn::normalizeDemandDoc($d);

            $stage = $this->stageFor($n, $settings, $today);
            if ($stage === null) continue;

            $studentId = (string) ($n['student_id'] ?? $n['studentId'] ?? '');
            if ($studentId === '') continue;   // cannot address a notification
            $candidates[] = ['demandId' => $id, 'studentId' => $studentId, 'stage' => $stage, 'demand' => $n];
        }

        $enqueued = 0; $skippedPaid = 0; $failed = 0;
        foreach ($candidates as $c) {
            // ── PAYMENT-STATE RE-CHECK, immediately before enqueue ──────
            // The bulk query above is a snapshot. A parent may have paid in
            // the meantime. Re-read this one demand so we never dun a family
            // that has already settled. Cost is bounded by the number of
            // notifications, not by school size.
            if (!$this->stillOwing($c['demandId'])) { $skippedPaid++; continue; }

            $demand  = $c['demand'];
            $balance = round((float) ($demand['balance'] ?? 0), 2);
            $due     = (string) ($demand['due_date'] ?? '');
            $key     = $this->dedupeKey($c['studentId'], $c['demandId'], $c['stage']);

            $details[] = [
                'studentId' => $c['studentId'], 'demandId' => $c['demandId'],
                'stage' => $c['stage'], 'amount' => $balance, 'dueDate' => $due,
                'dedupeKey' => $key,
            ];

            if ($dryRun) { $enqueued++; continue; }

            $ok = $this->enqueueOne($key, $c['studentId'], $balance, $due, $settings['message']);
            if ($ok) { $enqueued++; } else { $failed++; }
        }

        return [
            'demands_scanned' => $scanned,
            'candidates'      => count($candidates),
            'enqueued'        => $enqueued,
            'skipped_paid'    => $skippedPaid,
            'failed'          => $failed,
            'dry_run'         => $dryRun,
            'details'         => $details,
        ];
    }

    /**
     * Re-read one demand and confirm it is still outstanding.
     * Fails CLOSED — on any read error we do NOT notify, because sending a
     * wrong reminder is worse than sending none.
     */
    public function stillOwing(string $demandId): bool
    {
        try {
            $doc = $this->firebase->firestoreGet(self::COL_DEMANDS, $demandId);
        } catch (\Throwable $e) {
            log_message('error', 'Fee_reminder_service::stillOwing read failed: ' . $e->getMessage());
            return false;
        }
        if (!is_array($doc)) return false;
        $n      = Fee_firestore_txn::normalizeDemandDoc($doc);
        $status = strtolower((string) ($n['status'] ?? 'unpaid'));
        // PA-FEE-2 — the last check before a reminder actually goes out, so it
        // must use the same classification as stageFor(). A demand frozen
        // between scheduling and sending must stop the send.
        if (in_array($status, self::NON_COLLECTABLE_STATUSES, true)) return false;
        return ((float) ($n['balance'] ?? 0)) > 0.005;
    }

    /** Enqueue through the SHARED path — never a private sender. */
    private function enqueueOne(string $dedupeKey, string $studentId, float $amount,
                                string $dueDate, string $message): bool
    {
        if (!is_object($this->enqueue)) {
            log_message('error', 'Fee_reminder_service: no enqueue service injected — not sending.');
            return false;
        }
        $fields = [
            // FEE_DUE declares idField 'studentId' and dataKeys amount/dueDate.
            'studentId' => $studentId,
            'amount'    => $amount,
            'dueDate'   => $dueDate,
        ];
        if ($message !== '') $fields['body'] = $message;

        return (bool) $this->enqueue->enqueue(
            $this->fs, $this->schoolId, self::MARK, $dedupeKey, $fields, 'system'
        );
    }
}
