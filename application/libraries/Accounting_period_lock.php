<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Accounting_period_lock — Phase 8A period-close enforcement.
 *
 * Collection: accountingConfig
 * Doc ID:     {schoolId}_{session}_periodLock
 * Schema:     {
 *               schoolId, session,
 *               lockedUntil: "YYYY-MM-DD",   // inclusive; "" = no lock
 *               lockedBy:    "EMP003",
 *               lockedAt:    ISO-8601,
 *               reopenedBy:  null,
 *               reopenedAt:  null,
 *               reason:      string
 *             }
 *
 * Enforcement rule:
 *   A journal entry whose `date <= lockedUntil` is REJECTED.
 *   A journal entry whose `date >  lockedUntil` or no-lock is accepted.
 *
 * Caching:
 *   Read is routed through Accounting_cache (request + APCU tiers).
 *   TTL 60 s because a period close is an unmissable admin action — a
 *   few seconds of stale-reader window is acceptable.
 *
 * Call sites (MUST add a validatePeriodOpen() call before the write):
 *   • Operations_accounting::create_journal       (manual admin post)
 *   • Operations_accounting::create_fee_journal   (fee submit → worker)
 *   • Operations_accounting::create_refund_journal_granular
 *   • Any future endpoint that writes to `accounting`
 */
final class Accounting_period_lock
{
    private const COL          = 'accountingConfig';
    private const SUFFIX       = '_periodLock';
    private const CACHE_KEY    = 'periodLock';
    private const CACHE_TTL    = 60;   // seconds

    /** @var object|null */  private $firebase = null;
    /** @var Accounting_cache|null */  private $cache = null;
    private string $schoolId = '';
    private string $session  = '';
    private bool   $ready    = false;

    public function init($firebase, $cache, string $schoolId, string $session): void
    {
        $this->firebase = $firebase;
        $this->cache    = $cache;
        $this->schoolId = $schoolId;
        $this->session  = $session;
        $this->ready    = ($firebase !== null && $schoolId !== '' && $session !== '');
    }

    /**
     * Enforcement check. Returns an array describing the result:
     *   ['locked' => false]                         — proceed
     *   ['locked' => true,  'lockedUntil' => 'Y-m-d',
     *    'lockedBy' => 'EMP003', 'message' => '...'] — caller must reject
     *
     * The caller decides the HTTP response / error path. This function
     * never throws so it can be used from worker code where throwing
     * crashes the whole deferred step.
     */
    public function validate(string $entryDate): array
    {
        return $this->_validateInternal($entryDate, /* bypassCache */ false);
    }

    /**
     * Phase 8C (R1) — safety-critical variant that bypasses the cache
     * and reads the lock doc fresh every call. Use this on the HOT
     * write path (journal creation) when APCU is enabled — otherwise
     * an admin's lock action could allow up to 60 s of in-flight
     * journals to silently land in the newly-closed period.
     *
     * One extra Firestore RTT per journal (~1-2 s) is the safety
     * tax — correctness beats 2 s latency when the alternative is
     * ledger rows in a closed period.
     *
     * Callers that don't care about the 60 s cache window (report
     * endpoints, dashboards) should keep using validate().
     */
    public function forceValidate(string $entryDate): array
    {
        return $this->_validateInternal($entryDate, /* bypassCache */ true);
    }

    private function _validateInternal(string $entryDate, bool $bypassCache): array
    {
        if (!$this->ready || $entryDate === '') {
            // If we can't check, default to ALLOW so we don't reject writes
            // on a broken infra — but log a warning for operators.
            log_message('warning', "[ACC_PERIOD_LOCK] skipped — ready={$this->ready} entryDate={$entryDate}");
            return ['locked' => false, 'reason' => 'skipped'];
        }
        $lock = $bypassCache ? $this->_fetchRaw() : $this->_read();
        if (!is_array($lock)) return ['locked' => false];
        $lockedUntil = trim((string) ($lock['lockedUntil'] ?? ''));
        if ($lockedUntil === '') return ['locked' => false];

        // Inclusive compare: lockedUntil = "2026-03-31" means everything
        // up to AND INCLUDING 31 Mar is frozen.
        if ($entryDate <= $lockedUntil) {
            return [
                'locked'      => true,
                'lockedUntil' => $lockedUntil,
                'lockedBy'    => (string) ($lock['lockedBy'] ?? ''),
                'lockedAt'    => (string) ($lock['lockedAt'] ?? ''),
                'reason'      => (string) ($lock['reason']   ?? ''),
                'message'     => "Period is closed up to {$lockedUntil}. Entry dated {$entryDate} rejected.",
            ];
        }
        return ['locked' => false];
    }

    /**
     * Admin helper: get the current lock state (uncached).
     * UI reads use this — we want the dashboard to reflect the truth
     * without waiting for the cache TTL.
     */
    public function read(): ?array
    {
        if (!$this->ready) return null;
        return $this->_fetchRaw();
    }

    /**
     * Invalidate the cache. Call IMMEDIATELY after lock / reopen writes
     * so in-flight requests pick up the new state within one cache
     * lookup (rather than 60 s later).
     */
    public function invalidateCache(): void
    {
        if ($this->cache !== null) $this->cache->forget(self::CACHE_KEY);
    }

    // ─── internals ────────────────────────────────────────────────────

    private function _read(): ?array
    {
        if ($this->cache === null) return $this->_fetchRaw();
        $firebase = $this->firebase;
        $docId    = $this->_docId();
        return $this->cache->remember(self::CACHE_KEY, self::CACHE_TTL,
            function () use ($firebase, $docId) {
                $doc = $firebase->firestoreGet(\Accounting_period_lock::COL, $docId);
                return is_array($doc) ? $doc : null;
            });
    }

    private function _fetchRaw(): ?array
    {
        $doc = $this->firebase->firestoreGet(self::COL, $this->_docId());
        return is_array($doc) ? $doc : null;
    }

    private function _docId(): string
    {
        return "{$this->schoolId}_{$this->session}" . self::SUFFIX;
    }
}
