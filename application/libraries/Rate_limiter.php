<?php
/**
 * Rate_limiter — fixed-window rate limiting for parent-facing endpoints.
 *
 * Critical-fix T3: prevents a compromised / misbehaving parent device
 * from hammering parent_create_order or parent_verify_payment. Keys
 * are computed per-request as `userId|ip` so a single attacker is
 * bounded even when they rotate one of the two.
 *
 * Backend: Firestore doc per key at rateLimits/{hashedKey} storing a
 * window-start timestamp + hit count. Fixed-window simplicity beats
 * a full token bucket here because (a) Firestore REST incurs a round-
 * trip per check — reconciling token state on every call is expensive,
 * and (b) 5/min is deliberately well under any legitimate parent flow,
 * so a ±1-second boundary is acceptable.
 *
 * Usage (CI controller):
 *   $this->load->library('Rate_limiter', null, 'rateLimiter');
 *   $verdict = $this->rateLimiter->check(
 *       $this->firebase,
 *       'parent_verify_payment',
 *       $studentId,        // stable user key
 *       $this->input->ip_address(),
 *       5,                 // max
 *       60                 // window seconds
 *   );
 *   if (!$verdict['allowed']) { http_response_code(429); ...exit }
 */
class Rate_limiter
{
    public const COLLECTION = 'rateLimits';

    /**
     * @return array{
     *   allowed: bool,
     *   remaining: int,
     *   retryAfter: int,   // seconds until window resets
     *   key: string,
     *   reason: string,    // human-readable when !allowed
     * }
     */
    public function check(
        $firebase,
        string $bucket,
        string $userKey,
        string $ip,
        int    $maxRequests = 5,
        int    $windowSec   = 60
    ): array {
        $key    = $this->hashKey($bucket, $userKey, $ip);
        $now    = time();
        $winKey = (int) floor($now / max(1, $windowSec));

        // Best-effort read. If Firestore is unreachable the request is
        // ALLOWED — we prefer availability over a hard fail that would
        // break legitimate parent payments during a Firestore outage.
        $hits = 0; $winStart = $winKey;
        try {
            $doc = $firebase->firestoreGet(self::COLLECTION, $key);
            if (is_array($doc)) {
                $hits     = (int) ($doc['hits']        ?? 0);
                $winStart = (int) ($doc['windowStart'] ?? $winKey);
            }
        } catch (\Throwable $_) { /* fail-open */ }

        if ($winStart !== $winKey) { $hits = 0; $winStart = $winKey; }
        $allowed   = $hits < $maxRequests;
        $remaining = max(0, $maxRequests - $hits - ($allowed ? 1 : 0));
        $retryAfter = ($winKey + 1) * $windowSec - $now;

        // Only bump on allowed calls. A blocked caller hitting the same
        // endpoint repeatedly does NOT keep escalating their own hit
        // count — the window-reset alone releases them.
        if ($allowed) {
            try {
                $firebase->firestoreSet(self::COLLECTION, $key, [
                    'bucket'      => $bucket,
                    'userKey'     => $userKey,
                    'ip'          => $ip,
                    'hits'        => $hits + 1,
                    'windowStart' => $winKey,
                    'maxRequests' => $maxRequests,
                    'windowSec'   => $windowSec,
                    'updatedAt'   => date('c'),
                ], /* merge */ true);
            } catch (\Throwable $_) { /* fail-open on write */ }
        }

        return [
            'allowed'    => $allowed,
            'remaining'  => $remaining,
            'retryAfter' => $retryAfter,
            'key'        => $key,
            'reason'     => $allowed ? '' : "Rate limit: max {$maxRequests} requests / {$windowSec}s",
        ];
    }

    private function hashKey(string $bucket, string $userKey, string $ip): string
    {
        // Keep doc IDs Firestore-safe + bounded. The bucket prefix
        // keeps buckets from colliding (create-order vs verify-payment
        // share the same user/ip but have independent counters).
        $u = preg_replace('/[^A-Za-z0-9]+/', '_', $userKey);
        $h = substr(sha1($userKey . '|' . $ip), 0, 12);
        return "{$bucket}__" . substr($u, 0, 40) . "__{$h}";
    }
}
