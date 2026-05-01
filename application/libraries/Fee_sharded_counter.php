<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Fee_sharded_counter — Phase 4 concurrent-counter transport.
 *
 * ──────────────────────────────────────────────────────────────────────
 *  DESIGN — mod-N distribution over a shared claim-doc namespace
 * ──────────────────────────────────────────────────────────────────────
 * The existing `Fee_firestore_txn::nextCounter` already uses atomic
 * `firestoreCreate(feeCounters/{schoolId}_{kind}_claim_{N})` to prevent
 * duplicate receipt numbers. That primitive stays. What sharding adds is
 * parallelism: each of N shards hands out numbers from a DIFFERENT
 * residue class so two shards never race for the same candidate.
 *
 *   shard 0 → 10, 20, 30, …
 *   shard 1 → 1, 11, 21, 31, …
 *   shard 2 → 2, 12, 22, 32, …
 *   …
 *   shard 9 → 9, 19, 29, 39, …
 *
 * Each shard tracks its own "last-handed-out" fast-skip pointer in a
 * separate doc. Claims still go to the global claim collection, so
 * mixing with the legacy single-pointer code path remains correct —
 * just inefficient (legacy collisions force retries). Clean cutover is
 * recommended; mixed mode is a transient safety net.
 *
 * ──────────────────────────────────────────────────────────────────────
 *  CORRECTNESS GUARANTEES
 * ──────────────────────────────────────────────────────────────────────
 *  • Every returned number is globally unique (atomic claim-doc create).
 *  • No number is ever returned twice by ANY shard (distinct residue
 *    classes + global claim collection).
 *  • Mixing with legacy nextCounter is SAFE (both share the claim
 *    namespace). Legacy just takes longer under contention.
 *  • On any error, returns 0 — caller must fall back to legacy path.
 *
 * ──────────────────────────────────────────────────────────────────────
 *  DATA MODEL
 * ──────────────────────────────────────────────────────────────────────
 *  feeCounters/{schoolId}_{kind}                       — legacy global fast-skip (kept)
 *  feeCounters/{schoolId}_{kind}_claim_{N}             — claim docs (shared, unchanged)
 *  feeCounterShards/{schoolId}_{kind}_shard_{NN}       — per-shard fast-skip (NEW)
 *     { shardIdx, lastValue, schoolId, kind, updatedAt }
 *
 *  No new indexes needed: all reads are by-docId.
 */
final class Fee_sharded_counter
{
    /** Number of shards. Do NOT change after deploy without a migration
     *  — existing per-shard `lastValue` pointers assume this modulus. */
    public const NUM_SHARDS = 10;

    /** Max retries within one shard before giving up. Each retry advances
     *  candidate by NUM_SHARDS, so 20 retries covers a 200-number gap. */
    public const MAX_RETRIES = 20;

    private const COL_COUNTERS = 'feeCounters';
    private const COL_SHARDS   = 'feeCounterShards';

    private object $firebase;
    private string $schoolId;
    private string $session;
    private bool   $ready = false;

    public function __construct() {}

    public function init(object $firebase, string $schoolId, string $session): void
    {
        $this->firebase = $firebase;
        $this->schoolId = $schoolId;
        $this->session  = $session;
        $this->ready    = $firebase !== null && $schoolId !== '';
    }

    /**
     * Claim the next unique counter value using mod-N sharding.
     *
     * Algorithm:
     *   1. Pick a random shard index 0..N-1.
     *   2. Read that shard's fast-skip pointer `lastValue`.
     *   3. candidate = lastValue + NUM_SHARDS (preserves mod-N residue class).
     *   4. Attempt to create `feeCounters/{schoolId}_{kind}_claim_{candidate}`.
     *      - Atomic: firestoreCreate returns false on conflict.
     *   5. On success: write shard fast-skip = candidate. Return candidate.
     *   6. On conflict: candidate += NUM_SHARDS, retry (up to MAX_RETRIES).
     *   7. If exhausted: return 0 — caller falls back to legacy nextCounter.
     *
     * @return int  the claimed counter value (>0) or 0 on failure.
     */
    public function nextCounter(string $kind): int
    {
        if (!$this->ready || $kind === '') return 0;

        $shardIdx   = random_int(0, self::NUM_SHARDS - 1);
        $shardDocId = "{$this->schoolId}_{$kind}_shard_" . str_pad((string) $shardIdx, 2, '0', STR_PAD_LEFT);

        // Seed lastValue. If the shard doc is missing (first write), seed
        // with (shardIdx - NUM_SHARDS) so the first candidate = shardIdx.
        // This puts shard 0's first claim at 10 (not 0 — receipt numbers
        // are 1-indexed so 0 would be awkward), shard 1 at 1, etc.
        // Wait — we want shard 0 to start at NUM_SHARDS (10) and shard 1
        // at 1, which requires seed_0 = 0 and seed_1 = -NUM_SHARDS+1 = -9.
        // That's confusing. Simpler: shard_k's first value is k + NUM_SHARDS
        // (so shard 0 → 10, shard 1 → 11, shard 9 → 19), and subsequent
        // increments still add NUM_SHARDS (→ 20, 21, 29). Legacy range
        // [1..9] becomes unreachable by sharded — acceptable since legacy
        // may have already consumed some of it; sharded starts cleanly
        // above the legacy high-water mark anyway (see init + migration).
        $shardDoc = null;
        try {
            $shardDoc = $this->firebase->firestoreGet(self::COL_SHARDS, $shardDocId);
        } catch (\Exception $_) { /* treat as missing */ }

        if (!is_array($shardDoc)) {
            // First-ever use of this shard: look up the migration floor
            // (master doc: feeCounters/{schoolId}_{kind} holds the last
            // legacy value). Seed so we start strictly above legacy.
            $floor = 0;
            try {
                $cur = $this->firebase->firestoreGet(self::COL_COUNTERS, "{$this->schoolId}_{$kind}");
                $floor = is_array($cur) ? (int) ($cur['value'] ?? 0) : 0;
            } catch (\Exception $_) { /* start at 0 */ }
            // Round floor up to the NEXT multiple-of-N that lands in this
            // shard's residue class.
            //   target residue  = shardIdx
            //   lastValue seeds at (floor rounded to smallest L such that
            //     L ≥ floor  and  (L + NUM_SHARDS) % NUM_SHARDS == shardIdx)
            //   simplest: L = floor + ((shardIdx - (floor + NUM_SHARDS) % NUM_SHARDS) + NUM_SHARDS) % NUM_SHARDS
            // Concretely: next candidate = lastValue + NUM_SHARDS, and
            // candidate % NUM_SHARDS must == shardIdx. So lastValue % NUM_SHARDS == shardIdx.
            $rem = $floor % self::NUM_SHARDS;
            $bump = (self::NUM_SHARDS + $shardIdx - $rem) % self::NUM_SHARDS;
            $lastValue = $floor + $bump; // first candidate will be lastValue + NUM_SHARDS
        } else {
            $lastValue = (int) ($shardDoc['lastValue'] ?? 0);
        }

        for ($attempt = 0; $attempt < self::MAX_RETRIES; $attempt++) {
            $candidate = $lastValue + self::NUM_SHARDS;
            $claimId   = "{$this->schoolId}_{$kind}_claim_{$candidate}";

            try {
                $created = $this->firebase->firestoreCreate(self::COL_COUNTERS, $claimId, [
                    'schoolId'  => $this->schoolId,
                    'session'   => $this->session,
                    'kind'      => $kind,
                    'value'     => $candidate,
                    'shardIdx'  => $shardIdx,
                    'claimedAt' => date('c'),
                    'source'    => 'sharded',
                ]);
            } catch (\Exception $e) {
                log_message('error', "Fee_sharded_counter::nextCounter({$kind}) shard={$shardIdx} candidate={$candidate} attempt={$attempt} exception: " . $e->getMessage());
                $created = false;
            }

            if ($created) {
                // Advance the shard's fast-skip pointer (best-effort;
                // correctness is already guaranteed by the claim doc).
                try {
                    $this->firebase->firestoreSet(self::COL_SHARDS, $shardDocId, [
                        'schoolId'  => $this->schoolId,
                        'session'   => $this->session,
                        'kind'      => $kind,
                        'shardIdx'  => $shardIdx,
                        'lastValue' => $candidate,
                        'updatedAt' => date('c'),
                    ]);
                } catch (\Exception $_) { /* non-fatal */ }

                // Also bump the legacy global fast-skip pointer so any
                // leftover legacy reads don't keep suggesting an old value.
                // Best-effort — failures don't break the claim.
                try {
                    $cur = $this->firebase->firestoreGet(self::COL_COUNTERS, "{$this->schoolId}_{$kind}");
                    $curVal = is_array($cur) ? (int) ($cur['value'] ?? 0) : 0;
                    if ($candidate > $curVal) {
                        $this->firebase->firestoreSet(self::COL_COUNTERS, "{$this->schoolId}_{$kind}", [
                            'schoolId'  => $this->schoolId,
                            'session'   => $this->session,
                            'kind'      => $kind,
                            'value'     => $candidate,
                            'updatedAt' => date('c'),
                        ]);
                    }
                } catch (\Exception $_) { /* non-fatal */ }

                return $candidate;
            }

            // Claim conflict — someone else took this exact number
            // (either another shard via unlikely residue-class collision
            // after a migration bump, or legacy nextCounter probing).
            // Skip to the next multiple and retry.
            $lastValue = $candidate;
        }

        log_message('error', "Fee_sharded_counter::nextCounter({$kind}) exhausted after " . self::MAX_RETRIES . " retries in shard={$shardIdx}");
        return 0;
    }

    /**
     * Read the current high-water mark across ALL shards + legacy pointer.
     * Returns the maximum `lastValue` seen. Used by the UI to preview
     * "next receipt will likely be F{max + ~1}" — the actual next one is
     * determined at claim time by which shard the writer lands on.
     */
    public function peek(string $kind): int
    {
        if (!$this->ready) return 0;
        $max = 0;
        // Legacy pointer
        try {
            $cur = $this->firebase->firestoreGet(self::COL_COUNTERS, "{$this->schoolId}_{$kind}");
            if (is_array($cur)) $max = max($max, (int) ($cur['value'] ?? 0));
        } catch (\Exception $_) { /* ignore */ }
        // Each shard's pointer
        for ($i = 0; $i < self::NUM_SHARDS; $i++) {
            $shardDocId = "{$this->schoolId}_{$kind}_shard_" . str_pad((string) $i, 2, '0', STR_PAD_LEFT);
            try {
                $doc = $this->firebase->firestoreGet(self::COL_SHARDS, $shardDocId);
                if (is_array($doc)) $max = max($max, (int) ($doc['lastValue'] ?? 0));
            } catch (\Exception $_) { /* ignore */ }
        }
        return $max;
    }

    /**
     * One-shot migration — initializes every shard doc from the current
     * legacy counter value. Safe to call multiple times (idempotent: if
     * a shard doc already exists, it is left alone).
     *
     * @return array { initialized: int, skipped: int, floor: int }
     */
    public function initializeShards(string $kind): array
    {
        if (!$this->ready) return ['initialized' => 0, 'skipped' => 0, 'floor' => 0];

        $floor = 0;
        try {
            $cur = $this->firebase->firestoreGet(self::COL_COUNTERS, "{$this->schoolId}_{$kind}");
            $floor = is_array($cur) ? (int) ($cur['value'] ?? 0) : 0;
        } catch (\Exception $_) { /* start at 0 */ }

        $initialized = 0;
        $skipped     = 0;
        for ($i = 0; $i < self::NUM_SHARDS; $i++) {
            $shardDocId = "{$this->schoolId}_{$kind}_shard_" . str_pad((string) $i, 2, '0', STR_PAD_LEFT);
            try {
                $existing = $this->firebase->firestoreGet(self::COL_SHARDS, $shardDocId);
                if (is_array($existing)) { $skipped++; continue; }
            } catch (\Exception $_) { /* treat as missing */ }

            // Seed lastValue so first candidate = floor + (shard's offset).
            // Math same as in nextCounter's seed path — must match exactly.
            $rem = $floor % self::NUM_SHARDS;
            $bump = (self::NUM_SHARDS + $i - $rem) % self::NUM_SHARDS;
            $seedLast = $floor + $bump;

            try {
                $this->firebase->firestoreSet(self::COL_SHARDS, $shardDocId, [
                    'schoolId'  => $this->schoolId,
                    'session'   => $this->session,
                    'kind'      => $kind,
                    'shardIdx'  => $i,
                    'lastValue' => $seedLast,
                    'updatedAt' => date('c'),
                    'initializedFrom' => $floor,
                ]);
                $initialized++;
            } catch (\Exception $e) {
                log_message('error', "Fee_sharded_counter::initializeShards({$kind}) shard={$i} failed: " . $e->getMessage());
            }
        }

        return [
            'initialized' => $initialized,
            'skipped'     => $skipped,
            'floor'       => $floor,
            'num_shards'  => self::NUM_SHARDS,
        ];
    }
}
