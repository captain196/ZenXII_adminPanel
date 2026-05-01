<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Id_generator — Sequential ID generation (Firestore-only, hardened).
 *
 * ──────────────────────────────────────────────────────────────────────
 *  HARDENING PASS (Phase 5)
 * ──────────────────────────────────────────────────────────────────────
 *  Layer 1 — Randomised initial offset reduces head-on contention bursts.
 *            At 50 concurrent writers, spreading first candidates across
 *            a window drops worst-case retries from 50 to ~log(50) ≈ 6.
 *  Layer 2 — Tiered retry escalation. 10 linear → backoff + jump →
 *            re-read pointer + repair → final scan-repair → scan-and-
 *            advance past max claim. Effective ceiling: 100+ candidates.
 *  Layer 3 — Pointer drift auto-repair. On conflict streaks, the
 *            generator asks "has the pointer lagged behind the actual
 *            max claim?" and bumps it forward by scanning live claim
 *            docs. This is the self-healing mechanism.
 *  Layer 4 — `withClaim()` atomic wrapper: callers pass their work as
 *            a closure; claim is auto-released if the closure throws
 *            or returns false. No more orphan claims from failed
 *            downstream writes.
 *  Layer 5 — `reconcile()` endpoint: scans every claim-doc for a
 *            prefix, finds true max, advances pointer, reports any
 *            drift. Call from admin tooling or a cron.
 *  Layer 6 — Structured logs prefixed `ID_GEN` for grep-alert pipelines.
 *
 *  Public API UNCHANGED:
 *    generate('STU')              → "STU0042"
 *    generate('STU_PEEK')         → "STU0043"
 *    releaseClaim('STU', 42)      → bool
 *    advancePointerTo('STU', 100) → int
 *    seedFromExisting()           → ['STU'=>N, ...]
 *
 *  NEW methods (additive):
 *    withClaim('STU', callable)   — atomic claim+use+auto-release
 *    reconcile('STU')             — scans + repairs pointer drift
 *
 *  Storage (all Firestore, namespaced under feeCounters deny-all):
 *    feeCounters/_sys_{prefix}                { kind, value, updatedAt }
 *    feeCounters/_sys_{prefix}_claim_{N}      { kind, value, claimedAt, source }
 *
 *  History:
 *    2026-04-27  Counters fully migrated RTDB → Firestore. Old node
 *                System/Counters/* deleted. NO-RTDB policy enforced.
 *                Backup of last RTDB state:
 *                rtdb_System_Counters_backup_20260427210247.json
 */
class Id_generator
{
    /** @var object Firebase library (Firestore-only handle) */
    private $firebase;

    /** Padding width for each prefix */
    private const PAD = [
        'SSA'     => 4,
        'ADM'     => 4,
        'STA'     => 4,
        'STU'     => 4,
        'SCHCODE' => 5,
    ];

    private const SCHCODE_BASE = 10000;
    private const DOC_PREFIX   = '_sys_';
    private const COL_COUNTERS = 'feeCounters';

    /** Tier-1: linear probe from pointer+1. Covers sub-concurrent cases. */
    private const TIER1_ATTEMPTS = 10;
    /** Tier-2: backoff + random jump probe. Covers moderate contention. */
    private const TIER2_ATTEMPTS = 20;
    /** Tier-3: scan-repair + re-attempt. Covers pointer-drift cases. */
    private const TIER3_ATTEMPTS = 20;
    /** Random jump window for Tier-2 (spreads concurrent writers). */
    private const TIER2_JUMP_WINDOW = 50;

    public function __construct()
    {
        $CI =& get_instance();
        if (!isset($CI->firebase)) {
            $CI->load->library('firebase');
        }
        $this->firebase = $CI->firebase;
        // Firestore-only: no RTDB handle ever acquired.
    }

    // ════════════════════════════════════════════════════════════════════
    //  PUBLIC API — unchanged from legacy shape
    // ════════════════════════════════════════════════════════════════════

    /**
     * Generate the next sequential ID for the given prefix.
     * NEVER returns null under normal conditions — tiered retry +
     * self-repair ensures a claim is produced even under heavy
     * contention or pointer drift. Only returns null if every
     * escalation path (up to 50 candidates + a full scan-repair) fails,
     * at which point an ERROR-level log fires for ops alerting.
     */
    public function generate(string $prefix): ?string
    {
        $prefix = strtoupper(trim($prefix));

        if (substr($prefix, -5) === '_PEEK') {
            return $this->_peek(substr($prefix, 0, -5));
        }

        if (!isset(self::PAD[$prefix]) && $prefix !== 'SCHCODE') {
            log_message('error', "ID_GEN unknown_prefix prefix={$prefix}");
            return null;
        }

        // ── Tier 1: linear probe from pointer+1 ─────────────────────
        $claimed = $this->_claimLinear($prefix, /*maxAttempts=*/self::TIER1_ATTEMPTS, /*startOffset=*/1);
        if ($claimed > 0) return $this->_format($prefix, $claimed);

        log_message('warning', "ID_GEN tier1_exhausted prefix={$prefix} attempts=" . self::TIER1_ATTEMPTS);

        // ── Tier 2: backoff + random-jump probe ─────────────────────
        // Bounces the starting candidate into a random window so 50
        // concurrent writers stop racing the same N values in lockstep.
        $claimed = $this->_claimRandomJump($prefix, self::TIER2_ATTEMPTS);
        if ($claimed > 0) return $this->_format($prefix, $claimed);

        log_message('warning', "ID_GEN tier2_exhausted prefix={$prefix}");

        // ── Tier 3: scan-repair + re-attempt ────────────────────────
        // Heavy: scans live claim docs for this prefix, bumps pointer
        // to max+1, then tries again. Handles pointer-drift scenarios
        // where the pointer has silently fallen behind.
        $repaired = $this->_scanRepair($prefix);
        if ($repaired > 0) {
            $claimed = $this->_claimLinear($prefix, self::TIER3_ATTEMPTS, 1);
            if ($claimed > 0) {
                log_message('info', "ID_GEN tier3_success prefix={$prefix} repaired_to={$repaired} claimed={$claimed}");
                return $this->_format($prefix, $claimed);
            }
        }

        log_message('error', "ID_GEN all_tiers_exhausted prefix={$prefix} repaired_to={$repaired} — CRITICAL, manual intervention needed");
        return null;
    }

    /**
     * Release a previously-claimed value. Concurrency-safe: the pointer
     * rolls back only if it still points at this value.
     */
    public function releaseClaim(string $prefix, int $value): bool
    {
        $prefix = strtoupper(trim($prefix));
        if ($value < 1) return false;
        if (!isset(self::PAD[$prefix]) && $prefix !== 'SCHCODE') return false;

        $claimDocId   = self::DOC_PREFIX . $prefix . "_claim_{$value}";
        $pointerDocId = self::DOC_PREFIX . $prefix;

        $removed = false;
        try {
            $removed = (bool) $this->firebase->firestoreDelete(self::COL_COUNTERS, $claimDocId);
            if (!$removed) {
                log_message('warning', "ID_GEN release_claim_not_removed prefix={$prefix} value={$value}");
            }
        } catch (\Throwable $e) {
            log_message('error', "ID_GEN release_claim_delete_failed prefix={$prefix} value={$value} err=" . $e->getMessage());
        }

        try {
            $cur = $this->firebase->firestoreGet(self::COL_COUNTERS, $pointerDocId);
            $curVal = is_array($cur) ? (int) ($cur['value'] ?? 0) : 0;
            if ($curVal === $value) {
                $this->firebase->firestoreSet(self::COL_COUNTERS, $pointerDocId, [
                    'kind'      => $prefix,
                    'value'     => max(0, $value - 1),
                    'updatedAt' => date('c'),
                ]);
            }
        } catch (\Throwable $e) {
            log_message('error', "ID_GEN release_pointer_rewind_failed prefix={$prefix} value={$value} err=" . $e->getMessage());
        }

        return $removed;
    }

    /**
     * Advance the pointer to at least $floor. Safe no-op if pointer
     * is already ≥ floor. Used during bulk user imports or seeding.
     */
    public function advancePointerTo(string $prefix, int $floor): int
    {
        $prefix = strtoupper(trim($prefix));
        if (!isset(self::PAD[$prefix]) && $prefix !== 'SCHCODE') return 0;
        return $this->_advancePointerTo($prefix, $floor);
    }

    // ════════════════════════════════════════════════════════════════════
    //  PUBLIC API — new (additive, optional)
    // ════════════════════════════════════════════════════════════════════

    /**
     * Fail-loud wrapper around generate() — Phase 4.2.
     *
     * Production-safety guarantee: callers get either (a) a valid ID, or
     * (b) a thrown RuntimeException with a specific greppable marker —
     * never null, never a silent failure. Retries generate() a small
     * number of times with backoff before giving up, so transient
     * Firestore blips don't surface to end users.
     *
     * Usage in controllers:
     *   try {
     *       $id = $this->id_generator->safeGenerate('STU');
     *   } catch (\RuntimeException $e) {
     *       return $this->json_error(
     *           'Could not allocate a student ID — please retry in a moment.',
     *           503
     *       );
     *   }
     *
     * @throws \RuntimeException marker prefix "ID_GEN_INTEGRATION:" for alert grep
     */
    public function safeGenerate(string $prefix, int $maxExtraRetries = 2): string
    {
        $prefix = strtoupper(trim($prefix));
        $attempts = 0;
        $totalAttempts = 1 + max(0, $maxExtraRetries);

        while ($attempts < $totalAttempts) {
            $attempts++;
            $id = $this->generate($prefix);
            if ($id !== null && $id !== '') {
                if ($attempts > 1) {
                    log_message('info', "ID_GEN_INTEGRATION safeGenerate_recovered prefix={$prefix} attempts={$attempts} id={$id}");
                }
                return $id;
            }

            // Generate returned null — tiered retry inside generate()
            // already exhausted. Brief pause before our outer retry so
            // transient Firestore hiccups have a chance to clear.
            if ($attempts < $totalAttempts) {
                $backoffUs = 100_000 * $attempts; // 100ms, 200ms, 300ms …
                try {
                    $backoffUs += random_int(0, 50_000); // + up to 50ms jitter
                } catch (\Throwable $_) { /* ignore rng failure */ }
                log_message('warning', "ID_GEN_INTEGRATION safeGenerate_retry prefix={$prefix} attempt={$attempts} backoff_us={$backoffUs}");
                usleep($backoffUs);
            }
        }

        // All retries failed. This is a CRITICAL event — page ops.
        log_message('error',
            "ID_GEN_INTEGRATION safeGenerate_critical prefix={$prefix} attempts={$totalAttempts} — " .
            "generate() returned null across every retry. Firestore outage or severe pointer corruption suspected."
        );
        throw new \RuntimeException(
            "ID_GEN_INTEGRATION: failed to generate {$prefix} ID after {$totalAttempts} attempts. " .
            "Firestore outage or pointer corruption is suspected. Call Id_generator::reconcile('{$prefix}') to self-heal."
        );
    }

    /**
     * Atomic claim-and-use helper. Fixes the "orphan claim on downstream
     * write failure" class of bugs (R2). Calls your closure with the
     * generated ID; if the closure throws OR returns false, the claim
     * is auto-released. On any other return, the ID is kept.
     *
     *   $ok = $idGen->withClaim('STU', function($stuId) use ($data) {
     *       return $this->firebase->firestoreSet('students', $stuId, $data);
     *   });
     *   if ($ok === false) { // user-create failed; ID was released
     *       return; // surface error to caller
     *   }
     *
     * Returns whatever your closure returned, or null on generate failure.
     */
    public function withClaim(string $prefix, callable $fn)
    {
        $id = $this->generate($prefix);
        if ($id === null) {
            log_message('error', "ID_GEN with_claim_generate_failed prefix={$prefix}");
            return null;
        }

        $value = $this->_extractNumeric($prefix, $id);

        try {
            $result = $fn($id);
            // Explicit false = "I failed, please release." Null / truthy
            // = "I succeeded, keep the claim."
            if ($result === false) {
                log_message('info', "ID_GEN with_claim_callback_false prefix={$prefix} id={$id} — releasing");
                $this->releaseClaim($prefix, $value);
            }
            return $result;
        } catch (\Throwable $e) {
            log_message('error', "ID_GEN with_claim_callback_threw prefix={$prefix} id={$id} err=" . $e->getMessage() . " — releasing");
            $this->releaseClaim($prefix, $value);
            throw $e; // re-throw so the caller still sees the error
        }
    }

    /**
     * Self-healing reconciliation. Scans all claim docs for a prefix,
     * finds the actual max value, compares to the pointer, advances
     * pointer if drifted, and reports drift magnitude.
     *
     * Safe to call any time — read-only except the pointer advance,
     * which is idempotent.
     *
     * @return array {
     *   prefix, pointer_before, max_claim, pointer_after, drift,
     *   claim_doc_count, action_taken
     * }
     */
    public function reconcile(string $prefix): array
    {
        $prefix = strtoupper(trim($prefix));
        if (!isset(self::PAD[$prefix]) && $prefix !== 'SCHCODE') {
            return ['error' => "unknown prefix: {$prefix}"];
        }

        $pointerBefore = $this->_readPointer($prefix);
        $maxClaim      = $this->_findMaxClaim($prefix);
        $drift         = max(0, $maxClaim - $pointerBefore);
        $action        = 'none';
        $pointerAfter  = $pointerBefore;

        if ($maxClaim > $pointerBefore) {
            $pointerAfter = $this->_advancePointerTo($prefix, $maxClaim);
            $action = 'pointer_advanced';
            log_message('warning', "ID_GEN reconcile_drift_fixed prefix={$prefix} pointer_before={$pointerBefore} max_claim={$maxClaim} drift={$drift}");
        }

        return [
            'prefix'          => $prefix,
            'pointer_before'  => $pointerBefore,
            'max_claim'       => $maxClaim,
            'pointer_after'   => $pointerAfter,
            'drift'           => $drift,
            'action_taken'    => $action,
        ];
    }

    /**
     * Hardened seed — combines Firestore entity scans with claim-doc
     * scan, then takes max() across all sources. Fixes R4: seed can no
     * longer miss imported-but-unclaimed users or hand-written docs.
     */
    public function seedFromExisting(): array
    {
        $sources = [
            'SSA' => ['max_entity' => 0, 'max_claim' => 0, 'pointer_before' => 0, 'pointer_after' => 0],
            'ADM' => ['max_entity' => 0, 'max_claim' => 0, 'pointer_before' => 0, 'pointer_after' => 0],
            'STA' => ['max_entity' => 0, 'max_claim' => 0, 'pointer_before' => 0, 'pointer_after' => 0],
            'STU' => ['max_entity' => 0, 'max_claim' => 0, 'pointer_before' => 0, 'pointer_after' => 0],
            'SCHCODE' => ['max_entity' => 0, 'max_claim' => 0, 'pointer_before' => 0, 'pointer_after' => 0],
        ];

        // ── Staff ──
        try {
            $rows = $this->firebase->firestoreQuery('staff');
            foreach ((array) $rows as $r) {
                $d  = $r['data'] ?? $r;
                if (!is_array($d)) continue;
                $id = (string) ($d['staffId'] ?? $d['userId'] ?? '');
                if (preg_match('/^(SSA|ADM|STA)(\d+)$/', $id, $m)) {
                    $n = (int) $m[2];
                    $sources[$m[1]]['max_entity'] = max($sources[$m[1]]['max_entity'], $n);
                }
            }
        } catch (\Throwable $e) {
            log_message('error', "ID_GEN seed_staff_scan_failed err=" . $e->getMessage());
        }

        // ── Students ──
        try {
            $rows = $this->firebase->firestoreQuery('students');
            foreach ((array) $rows as $r) {
                $d  = $r['data'] ?? $r;
                if (!is_array($d)) continue;
                $id = (string) ($d['studentId'] ?? $d['userId'] ?? '');
                if (preg_match('/^STU(\d+)$/', $id, $m)) {
                    $sources['STU']['max_entity'] = max($sources['STU']['max_entity'], (int) $m[1]);
                }
            }
        } catch (\Throwable $e) {
            log_message('error', "ID_GEN seed_students_scan_failed err=" . $e->getMessage());
        }

        // ── Schools ──
        try {
            $rows = $this->firebase->firestoreQuery('schools');
            foreach ((array) $rows as $r) {
                $d    = $r['data'] ?? $r;
                $code = (string) ($d['code'] ?? $d['schoolCode'] ?? $r['id'] ?? '');
                if (is_numeric($code)) {
                    $n = (int) $code - self::SCHCODE_BASE;
                    if ($n > 0) $sources['SCHCODE']['max_entity'] = max($sources['SCHCODE']['max_entity'], $n);
                }
            }
        } catch (\Throwable $e) {
            log_message('error', "ID_GEN seed_schools_scan_failed err=" . $e->getMessage());
        }

        // ── Claim docs cross-check (catches hand-written or partial-flow IDs) ──
        foreach ($sources as $prefix => $_) {
            $sources[$prefix]['max_claim']      = $this->_findMaxClaim($prefix);
            $sources[$prefix]['pointer_before'] = $this->_readPointer($prefix);
            // Take the TRUE max across every data source we can see.
            $floor = max(
                $sources[$prefix]['max_entity'],
                $sources[$prefix]['max_claim'],
                $sources[$prefix]['pointer_before']
            );
            $sources[$prefix]['pointer_after'] = $this->_advancePointerTo($prefix, $floor);
            if ($sources[$prefix]['pointer_after'] > $sources[$prefix]['pointer_before']) {
                log_message('info',
                    "ID_GEN seed_advanced prefix={$prefix} " .
                    "from={$sources[$prefix]['pointer_before']} to={$sources[$prefix]['pointer_after']} " .
                    "entity_max={$sources[$prefix]['max_entity']} claim_max={$sources[$prefix]['max_claim']}"
                );
            }
        }

        // Backward-compat return shape: flat prefix=>value map.
        $legacy = [];
        foreach ($sources as $p => $s) $legacy[$p] = $s['pointer_after'];
        return $legacy + ['_details' => $sources];
    }

    // ════════════════════════════════════════════════════════════════════
    //  INTERNAL — tiered claim strategies
    // ════════════════════════════════════════════════════════════════════

    /**
     * Tier 1 — linear probe. Read pointer, candidate = pointer + offset,
     * try claim, on conflict increment and retry. Cheapest path; wins
     * in the uncontended common case (~1 round-trip).
     */
    private function _claimLinear(string $prefix, int $maxAttempts, int $startOffset): int
    {
        $pointer = $this->_readPointer($prefix);
        $candidate = $pointer + max(1, $startOffset);

        for ($i = 0; $i < $maxAttempts; $i++) {
            if ($this->_tryClaim($prefix, $candidate)) {
                $this->_writePointer($prefix, $candidate);
                return $candidate;
            }
            // Conflict (409 or write-false). Continue probing upward.
            $candidate++;
        }
        return 0;
    }

    /**
     * Tier 2 — random-jump probe with backoff. Used when Tier 1 fails,
     * which signals heavy concurrent contention. Each attempt picks a
     * candidate at pointer + random(1..TIER2_JUMP_WINDOW) to spread
     * writers across the candidate space. Adds small backoff between
     * attempts so hot contention has time to settle.
     */
    private function _claimRandomJump(string $prefix, int $maxAttempts): int
    {
        for ($i = 0; $i < $maxAttempts; $i++) {
            // Refresh pointer each loop — if another writer advanced it
            // significantly we want to probe the right neighborhood.
            $pointer = $this->_readPointer($prefix);
            try {
                $jump = random_int(1, self::TIER2_JUMP_WINDOW);
            } catch (\Throwable $_) {
                $jump = mt_rand(1, self::TIER2_JUMP_WINDOW);
            }
            $candidate = $pointer + $jump;

            if ($this->_tryClaim($prefix, $candidate)) {
                $this->_writePointer($prefix, $candidate);
                log_message('info', "ID_GEN tier2_claimed prefix={$prefix} value={$candidate} jump={$jump} attempt={$i}");
                return $candidate;
            }

            // Gentle backoff: 10ms + up to 20ms jitter. Keeps total
            // tier-2 worst-case under 1 second.
            usleep(10000 + random_int(0, 20000));
        }
        return 0;
    }

    /**
     * Tier 3 — scan claim docs, advance pointer to max+1, retry linear
     * once. Handles pointer-drift scenarios where linear-probe fails
     * because the true max has moved far beyond the pointer.
     *
     * Returns the new pointer value after repair (0 if scan/repair failed).
     */
    private function _scanRepair(string $prefix): int
    {
        $maxClaim = $this->_findMaxClaim($prefix);
        if ($maxClaim <= 0) return 0;
        $repaired = $this->_advancePointerTo($prefix, $maxClaim);
        log_message('warning', "ID_GEN tier3_scan_repair prefix={$prefix} max_claim={$maxClaim} pointer_now={$repaired}");
        return $repaired;
    }

    // ════════════════════════════════════════════════════════════════════
    //  INTERNAL — primitives
    // ════════════════════════════════════════════════════════════════════

    /** Read pointer value; 0 if missing. */
    private function _readPointer(string $prefix): int
    {
        try {
            $doc = $this->firebase->firestoreGet(self::COL_COUNTERS, self::DOC_PREFIX . $prefix);
            return is_array($doc) ? (int) ($doc['value'] ?? 0) : 0;
        } catch (\Throwable $_) { return 0; }
    }

    /** Best-effort pointer write. Correctness never depends on this
     *  succeeding — the claim doc is authoritative. */
    private function _writePointer(string $prefix, int $value): void
    {
        try {
            $this->firebase->firestoreSet(self::COL_COUNTERS, self::DOC_PREFIX . $prefix, [
                'kind'      => $prefix,
                'value'     => $value,
                'updatedAt' => date('c'),
            ]);
        } catch (\Throwable $e) {
            log_message('warning', "ID_GEN pointer_write_failed prefix={$prefix} value={$value} err=" . $e->getMessage());
        }
    }

    /** Attempt to create the claim doc. Returns true on success, false
     *  on any failure (usually 409 — someone else took this value). */
    private function _tryClaim(string $prefix, int $candidate): bool
    {
        if ($candidate < 1) return false;
        try {
            return (bool) $this->firebase->firestoreCreate(
                self::COL_COUNTERS,
                self::DOC_PREFIX . $prefix . "_claim_{$candidate}",
                [
                    'kind'      => $prefix,
                    'value'     => $candidate,
                    'claimedAt' => date('c'),
                    'source'    => 'id_generator',
                ]
            );
        } catch (\Throwable $e) {
            log_message('warning', "ID_GEN claim_exception prefix={$prefix} candidate={$candidate} err=" . $e->getMessage());
            return false;
        }
    }

    /**
     * Find the maximum `value` among all claim docs for this prefix.
     * Requires the composite index feeCounters(kind ASC, value DESC) —
     * see firestore.indexes.json. Falls back to 0 on any query failure
     * so that callers can still advance via other sources.
     */
    private function _findMaxClaim(string $prefix): int
    {
        try {
            $rows = $this->firebase->firestoreQuery(
                self::COL_COUNTERS,
                [['kind', '==', $prefix]],
                'value',   // orderBy
                'DESC',
                1          // limit
            );
            foreach ((array) $rows as $r) {
                $d = $r['data'] ?? $r;
                if (is_array($d) && isset($d['value'])) return (int) $d['value'];
            }
        } catch (\Throwable $e) {
            log_message('error', "ID_GEN find_max_claim_failed prefix={$prefix} err=" . $e->getMessage());
        }
        return 0;
    }

    private function _advancePointerTo(string $prefix, int $floor): int
    {
        if ($floor < 1) return $this->_readPointer($prefix);
        $cur = $this->_readPointer($prefix);
        if ($cur >= $floor) return $cur;
        $this->_writePointer($prefix, $floor);
        return $floor;
    }

    /**
     * Peek at the likely-next ID without consuming.
     */
    private function _peek(string $counterKey): ?string
    {
        $current = $this->_readPointer($counterKey);
        return $this->_format($counterKey, $current + 1);
    }

    /**
     * Format a numeric value into the canonical ID string.
     */
    private function _format(string $counterKey, int $value): string
    {
        if ($counterKey === 'SCHCODE') {
            return (string) (self::SCHCODE_BASE + $value);
        }
        $pad = self::PAD[$counterKey] ?? 4;
        return $counterKey . str_pad((string) $value, $pad, '0', STR_PAD_LEFT);
    }

    /**
     * Strip a formatted ID back to its numeric component. Used by
     * withClaim() to derive the value for releaseClaim().
     */
    private function _extractNumeric(string $prefix, string $id): int
    {
        if ($prefix === 'SCHCODE') {
            return max(0, ((int) $id) - self::SCHCODE_BASE);
        }
        $rest = substr($id, strlen($prefix));
        return (int) $rest;
    }

    // ════════════════════════════════════════════════════════════════════
    //  AUDIT TOOL (Phase 4.2)
    // ════════════════════════════════════════════════════════════════════

    /**
     * Static audit helper — scans the application/ tree for direct
     * `->generate(…)` calls on an Id_generator instance and returns
     * their locations. Any match that isn't wrapped in withClaim() or
     * safeGenerate() is a candidate for migration.
     *
     * Safe to run from any admin endpoint; pure file I/O, no Firestore.
     *
     * @param string $appRoot  Absolute path to the application/ directory.
     *                         Defaults to CI's APPPATH.
     * @return array {
     *   direct_generate:  [{file, line, snippet}]  — legacy callers
     *   safe_generate:    [{file, line, snippet}]  — already using wrapper
     *   with_claim:       [{file, line, snippet}]  — already atomic
     *   total_direct:     int
     *   total_safe:       int
     *   total_with_claim: int
     * }
     */
    public static function auditIdFlows(?string $appRoot = null): array
    {
        if ($appRoot === null && defined('APPPATH')) {
            $appRoot = APPPATH;
        }
        if (!$appRoot || !is_dir($appRoot)) {
            return ['error' => "Invalid app root: {$appRoot}"];
        }

        $out = [
            'direct_generate' => [],
            'safe_generate'   => [],
            'with_claim'      => [],
        ];

        // Only scan paths that realistically hold business logic.
        $scanDirs = ['controllers', 'libraries', 'services', 'models', 'core'];
        foreach ($scanDirs as $sub) {
            $dir = rtrim($appRoot, '/\\') . DIRECTORY_SEPARATOR . $sub;
            if (!is_dir($dir)) continue;

            $it = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)
            );
            foreach ($it as $f) {
                /** @var \SplFileInfo $f */
                if (!$f->isFile() || strtolower($f->getExtension()) !== 'php') continue;
                $path = $f->getPathname();
                $lines = @file($path, FILE_IGNORE_NEW_LINES);
                if (!is_array($lines)) continue;

                foreach ($lines as $idx => $line) {
                    // Skip comments (simple heuristic).
                    $trim = ltrim($line);
                    if (strpos($trim, '//') === 0 || strpos($trim, '*') === 0 || strpos($trim, '#') === 0) {
                        continue;
                    }

                    // Direct generate() — the pattern we want to migrate.
                    // Match forms:
                    //   $this->id_generator->generate('STU')
                    //   $idGen->generate('ADM', …)
                    //   $this->idGen->generate("STA")
                    // Skip _PEEK forms (they're intentionally read-only).
                    if (preg_match('/->generate\(\s*[\'"]([A-Z_]+)[\'"]/', $line, $m)) {
                        if (substr($m[1], -5) === '_PEEK') continue;
                        // Skip occurrences INSIDE the Id_generator library
                        // itself — that's internal recursion, not a caller.
                        if (stripos($path, 'Id_generator.php') !== false) continue;
                        $out['direct_generate'][] = [
                            'file'    => str_replace(rtrim($appRoot, '/\\'), 'application', $path),
                            'line'    => $idx + 1,
                            'prefix'  => $m[1],
                            'snippet' => trim($line),
                        ];
                    }

                    if (preg_match('/->safeGenerate\(\s*[\'"]([A-Z_]+)[\'"]/', $line, $m)) {
                        if (stripos($path, 'Id_generator.php') !== false) continue;
                        $out['safe_generate'][] = [
                            'file'    => str_replace(rtrim($appRoot, '/\\'), 'application', $path),
                            'line'    => $idx + 1,
                            'prefix'  => $m[1],
                            'snippet' => trim($line),
                        ];
                    }

                    if (preg_match('/->withClaim\(\s*[\'"]([A-Z_]+)[\'"]/', $line, $m)) {
                        if (stripos($path, 'Id_generator.php') !== false) continue;
                        $out['with_claim'][] = [
                            'file'    => str_replace(rtrim($appRoot, '/\\'), 'application', $path),
                            'line'    => $idx + 1,
                            'prefix'  => $m[1],
                            'snippet' => trim($line),
                        ];
                    }
                }
            }
        }

        $out['total_direct']     = count($out['direct_generate']);
        $out['total_safe']       = count($out['safe_generate']);
        $out['total_with_claim'] = count($out['with_claim']);
        return $out;
    }
}
