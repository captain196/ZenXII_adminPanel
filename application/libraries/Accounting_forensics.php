<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Accounting_forensics — Phase G1 forensic event timeline library.
 *
 * ─────────────────────────────────────────────────────────────────────────
 *  PURPOSE
 * ─────────────────────────────────────────────────────────────────────────
 *  Records append-only lifecycle events per ledger entry into the
 *  `accountingForensics` Firestore collection. Operators trace the full
 *  history of any journal — who created it, every replay, every repair,
 *  every reversal — without touching the immutable ledger doc itself.
 *
 *  This is an ADDITIVE governance layer. It does NOT:
 *    • mutate ledger entries
 *    • mutate closing balances
 *    • mutate idempotency state
 *    • affect reconciler convergence
 *    • change period-lock semantics
 *
 *  Failures to write a forensic event NEVER block the underlying journal
 *  flow — every recordEvent() call is wrapped in try/catch with a
 *  structured log line so operators see the data-loss but the financial
 *  operation completes regardless.
 *
 * ─────────────────────────────────────────────────────────────────────────
 *  DOC SHAPE
 * ─────────────────────────────────────────────────────────────────────────
 *  Collection:  accountingForensics
 *  Doc ID:      {schoolId}_{session}_{entryId}_{seq}
 *               where {seq} is a millisecond-precision timestamp making
 *               the doc id naturally chronological per entry.
 *
 *  Fields:
 *    schoolId       string
 *    session        string
 *    entryId        string                — references the ledger entry
 *    eventType      string                — created|replayed|repaired|
 *                                           reversed|approved|reopened_post
 *    occurredAt     ISO-8601 timestamp
 *    actor          string                — admin_id or 'SYSTEM_RECONCILER'
 *    actor_role     string                — admin_role at time of event
 *    source         string                — 'fee_payment' | 'fee_refund' |
 *                                           'manual' | 'reconciler' | …
 *    metadata       map                   — event-specific payload
 *
 *  The collection is append-only. There is no UPDATE or DELETE operation
 *  exposed by this library. Each event becomes a permanent forensic
 *  record visible via listEventsForEntry() / listRecentEvents().
 *
 * ─────────────────────────────────────────────────────────────────────────
 *  USAGE
 * ─────────────────────────────────────────────────────────────────────────
 *      $CI->load->library('Accounting_forensics', null, 'acctForensics');
 *      $CI->acctForensics->init($firebase, $schoolId, $session);
 *
 *      $CI->acctForensics->recordEvent(
 *          $entryId, 'created',
 *          $actor, $actorRole, $source,
 *          ['voucher_no' => $voucherNo, 'lines_count' => count($lines)]
 *      );
 *
 *  Read accessors:
 *      $events = $CI->acctForensics->listEventsForEntry($entryId);
 *      $recent = $CI->acctForensics->listRecentEvents(50);
 */
final class Accounting_forensics
{
    private const COL          = 'accountingForensics';
    private const MAX_LIST     = 200;

    /** @var object|null */ private $firebase = null;
    private string $schoolId   = '';
    private string $session    = '';
    private bool   $ready      = false;

    public function init($firebase, string $schoolId, string $session): void
    {
        $this->firebase = $firebase;
        $this->schoolId = $schoolId;
        $this->session  = $session;
        $this->ready    = ($firebase !== null && $schoolId !== '' && $session !== '');
    }

    /**
     * Append a forensic event for a journal entry. Best-effort: a write
     * failure is logged but never thrown — the underlying journal flow
     * is never blocked by forensic-side errors.
     *
     * @param string $entryId    Ledger entry id (e.g. JE_FEE_F12345)
     * @param string $eventType  One of: created|replayed|repaired|reversed|
     *                                    approved|reopened_post|other
     * @param string $actor      admin_id or 'SYSTEM_RECONCILER'
     * @param string $actorRole  admin_role string (e.g. 'Accountant') or ''
     * @param string $source     Source module ('fee_payment','fee_refund',
     *                           'manual','reconciler','library', …)
     * @param array  $metadata   Event-specific payload (voucher_no, reason,
     *                           cycle_id, line_count, etc.)
     */
    public function recordEvent(
        string $entryId,
        string $eventType,
        string $actor,
        string $actorRole,
        string $source,
        array  $metadata = []
    ): void {
        if (!$this->ready || $entryId === '' || $eventType === '') {
            return;
        }
        try {
            $now    = date('c');
            $micros = (int) (microtime(true) * 1000);
            $docId  = "{$this->schoolId}_{$this->session}_{$entryId}_{$micros}";

            $this->firebase->firestoreSet(self::COL, $docId, [
                'schoolId'   => $this->schoolId,
                'session'    => $this->session,
                'entryId'    => $entryId,
                'eventType'  => $eventType,
                'occurredAt' => $now,
                'actor'      => $actor !== '' ? $actor : 'SYSTEM',
                'actor_role' => $actorRole,
                'source'     => $source,
                'metadata'   => is_array($metadata) ? $metadata : [],
            ]);
        } catch (\Throwable $e) {
            log_message('error',
                "ACC_FORENSIC_WRITE_FAILED entryId={$entryId} eventType={$eventType} "
                . "schoolId={$this->schoolId} session={$this->session} "
                . "error=" . $e->getMessage());
        }
    }

    /**
     * Convenience writer for a journal-creation event. Captures voucher
     * number, line count, source-ref. Called from Operations_accounting
     * v2 paths after successful commit.
     */
    public function recordCreation(
        string $entryId, string $voucherNo, int $lineCount,
        string $sourceRef, string $actor, string $actorRole, string $source
    ): void {
        $this->recordEvent(
            $entryId, 'created', $actor, $actorRole, $source,
            ['voucher_no' => $voucherNo, 'line_count' => $lineCount, 'source_ref' => $sourceRef]
        );
    }

    /**
     * Convenience writer for a reconciler-driven replay event. Captures
     * the cycle stage that triggered the replay and prior attempt count.
     */
    public function recordReplay(
        string $entryId, string $reason, int $attemptCount, string $cycleStage = ''
    ): void {
        $this->recordEvent(
            $entryId, 'replayed', 'SYSTEM_RECONCILER', '', 'reconciler',
            ['reason' => $reason, 'attempt' => $attemptCount, 'cycle_stage' => $cycleStage]
        );
    }

    /**
     * Convenience writer for a balance/index repair event. Captures the
     * affected accounts (or index keys) and the repair stage.
     */
    public function recordRepair(
        string $entryId, string $stage, array $affected, string $reason = ''
    ): void {
        $this->recordEvent(
            $entryId, 'repaired', 'SYSTEM_RECONCILER', '', 'reconciler',
            ['stage' => $stage, 'affected' => array_values($affected), 'reason' => $reason]
        );
    }

    /**
     * Convenience writer for a soft-delete / reversal event. Captures
     * the reversal reason and the actor who initiated it.
     */
    public function recordReversal(
        string $entryId, string $reversalReason, string $actor, string $actorRole,
        string $source = 'manual', array $metadata = []
    ): void {
        $payload = array_merge(
            ['reason' => $reversalReason],
            $metadata
        );
        $this->recordEvent(
            $entryId, 'reversed', $actor, $actorRole, $source, $payload
        );
    }

    /**
     * Convenience writer for a journal posted during a reopened period.
     * Used by Accounting::reopen_period flow to flag entries that landed
     * within the reopen window for auditor visibility.
     */
    public function recordReopenedPost(
        string $entryId, string $reopenReason, string $actor, string $actorRole,
        array $metadata = []
    ): void {
        $payload = array_merge(
            ['reopen_reason' => $reopenReason],
            $metadata
        );
        $this->recordEvent(
            $entryId, 'reopened_post', $actor, $actorRole, 'manual', $payload
        );
    }

    /**
     * Read all events for one journal entry, ordered chronologically.
     * Returns an array of event docs with `id` populated.
     */
    public function listEventsForEntry(string $entryId): array
    {
        if (!$this->ready || $entryId === '') return [];
        try {
            $rows = (array) $this->firebase->firestoreQuery(self::COL, [
                ['schoolId', '==', $this->schoolId],
                ['session',  '==', $this->session],
                ['entryId',  '==', $entryId],
            ], 'occurredAt', 'ASC', self::MAX_LIST);

            $out = [];
            foreach ($rows as $r) {
                $d = is_array($r['data'] ?? null) ? $r['data'] : [];
                $d['id'] = (string) ($r['id'] ?? '');
                $out[] = $d;
            }
            return $out;
        } catch (\Throwable $e) {
            log_message('error',
                "ACC_FORENSIC_LIST_FAILED entryId={$entryId} error=" . $e->getMessage());
            return [];
        }
    }

    /**
     * Read the most recent N events across all entries (school+session
     * scoped). Used by status CLI / dashboards for "what changed
     * recently?" view. Capped at MAX_LIST.
     */
    public function listRecentEvents(int $limit = 50, ?string $eventType = null): array
    {
        if (!$this->ready) return [];
        $limit = max(1, min(self::MAX_LIST, $limit));
        try {
            $filters = [
                ['schoolId', '==', $this->schoolId],
                ['session',  '==', $this->session],
            ];
            if ($eventType !== null && $eventType !== '') {
                $filters[] = ['eventType', '==', $eventType];
            }
            $rows = (array) $this->firebase->firestoreQuery(self::COL,
                $filters, 'occurredAt', 'DESC', $limit);

            $out = [];
            foreach ($rows as $r) {
                $d = is_array($r['data'] ?? null) ? $r['data'] : [];
                $d['id'] = (string) ($r['id'] ?? '');
                $out[] = $d;
            }
            return $out;
        } catch (\Throwable $e) {
            log_message('error', "ACC_FORENSIC_RECENT_FAILED error=" . $e->getMessage());
            return [];
        }
    }

    /**
     * Count events of a given type within the last N seconds. Used by
     * anomaly detection to flag patterns like "10+ reversals in last
     * hour" or "50+ replays in last cycle".
     */
    public function countRecentEvents(string $eventType, int $secondsBack): int
    {
        if (!$this->ready || $eventType === '' || $secondsBack <= 0) return 0;
        $cutoff = date('c', time() - $secondsBack);
        try {
            $rows = (array) $this->firebase->firestoreQuery(self::COL, [
                ['schoolId',   '==', $this->schoolId],
                ['session',    '==', $this->session],
                ['eventType',  '==', $eventType],
                ['occurredAt', '>=', $cutoff],
            ], 'occurredAt', 'DESC', self::MAX_LIST);
            return count($rows);
        } catch (\Throwable $e) {
            log_message('error',
                "ACC_FORENSIC_COUNT_FAILED eventType={$eventType} error=" . $e->getMessage());
            return 0;
        }
    }
}
