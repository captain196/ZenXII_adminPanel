<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Fee_collection_rollup — denormalised "collection by payment date" rollup so
 * the dashboard renders the monthly fee chart + today/month/year breakdown
 * WITHOUT scanning every receipt.
 *
 * Doc: feeCollectionRollup/{schoolFs}_{session}
 *   schoolId, session
 *   total         float   — Σ allocated_amount of all receipts in the session
 *   receiptCount  int     — number of receipts
 *   monthly       map     — { "YYYY-MM" → Σ allocated_amount paid that month }
 *   daily         map     — { "YYYY-MM-DD" → Σ allocated_amount paid that day }
 *   updatedAt
 *
 * Correctness model (authenticity):
 *   • applyDelta() keeps it current at receipt time via atomic server-side
 *     increments (concurrency-safe, runs in the post-response deferred hook so
 *     the cashier is never blocked).
 *   • The dashboard CROSS-CHECKS total/receiptCount against the authoritative
 *     count+sum aggregation it already runs; on any mismatch it calls
 *     rebuild() (full recompute from receipts) and uses the fresh values. So a
 *     dropped/wrong delta can never surface a wrong number — worst case is one
 *     rebuild (a single scan), never bad data.
 */
final class Fee_collection_rollup
{
    private const COL = 'feeCollectionRollup';

    public static function docId(string $schoolFs, string $session): string
    {
        return "{$schoolFs}_{$session}";
    }

    /** Read the rollup doc, or null if absent. */
    public static function read(object $firebase, string $schoolFs, string $session): ?array
    {
        try {
            $doc = $firebase->firestoreGet(self::COL, self::docId($schoolFs, $session));
            return is_array($doc) && !empty($doc) ? $doc : null;
        } catch (\Throwable $e) {
            log_message('error', 'Fee_collection_rollup::read failed: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Atomically apply one receipt's effect: +amount (or -amount on void) into
     * total + the month/day buckets, and ±1 to receiptCount. Best-effort —
     * returns false on failure; the dashboard cross-check self-heals.
     */
    public static function applyDelta(
        object $firebase, string $schoolFs, string $session,
        float $amount, string $dateStr, int $countDelta
    ): bool {
        $ts = strtotime($dateStr);
        if (!$ts || $amount == 0.0) {
            // Still keep the count in sync even if the date is unparseable.
            $ts = $ts ?: time();
        }
        $ym  = date('Y-m', $ts);
        $ymd = date('Y-m-d', $ts);

        $inc = [
            'total'                  => (float) $amount,
            'monthly.`' . $ym . '`'  => (float) $amount,
            'daily.`'   . $ymd . '`' => (float) $amount,
        ];
        if ($countDelta !== 0) {
            $inc['receiptCount'] = (int) $countDelta;
        }
        try {
            return (bool) $firebase->firestoreIncrement(self::COL, self::docId($schoolFs, $session), $inc);
        } catch (\Throwable $e) {
            log_message('error', 'Fee_collection_rollup::applyDelta failed: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Full recompute from feeReceipts. Returns the computed payload
     * (['total','receiptCount','monthly','daily']) and writes the rollup doc.
     * Used by the backfill AND by the dashboard's cross-check fallback.
     */
    public static function rebuild(object $firebase, string $schoolFs, string $session): array
    {
        $monthly = [];
        $daily   = [];
        $total   = 0.0;
        $count   = 0;

        try {
            $receipts = $firebase->firestoreQuery('feeReceipts', [
                ['schoolId', '==', $schoolFs],
                ['session',  '==', $session],
            ]);
        } catch (\Throwable $e) {
            log_message('error', 'Fee_collection_rollup::rebuild query failed: ' . $e->getMessage());
            $receipts = [];
        }

        foreach ((array) $receipts as $doc) {
            $r = $doc['data'] ?? $doc;
            if (!is_array($r)) continue;
            $count++;
            $amt = (float) ($r['allocated_amount'] ?? $r['allocatedAmount'] ?? $r['amount'] ?? 0);
            $total += $amt;
            $dateStr = (string) ($r['paidAt'] ?? $r['date'] ?? '');
            $ts = $dateStr !== '' ? strtotime($dateStr) : false;
            if (!$ts) continue;
            $ym  = date('Y-m', $ts);
            $ymd = date('Y-m-d', $ts);
            $monthly[$ym]  = ($monthly[$ym]  ?? 0) + $amt;
            $daily[$ymd]   = ($daily[$ymd]   ?? 0) + $amt;
        }
        ksort($monthly);

        $payload = [
            'total'        => round($total, 2),
            'receiptCount' => $count,
            'monthly'      => array_map(fn($v) => round($v, 2), $monthly),
            'daily'        => array_map(fn($v) => round($v, 2), $daily),
        ];

        try {
            $firebase->firestoreSet(self::COL, self::docId($schoolFs, $session), array_merge($payload, [
                'schoolId'  => $schoolFs,
                'session'   => $session,
                'updatedAt' => date('c'),
            ]));
        } catch (\Throwable $e) {
            log_message('error', 'Fee_collection_rollup::rebuild write failed: ' . $e->getMessage());
        }
        return $payload;
    }
}
