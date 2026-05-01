<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Accounting_health — Phase 8D operational snapshot provider.
 *
 * Single source of truth for "is accounting healthy?". Used by:
 *   • /accounting/health.json      (HTTP endpoint, admin UI polling)
 *   • /accounting/health_dashboard (HTML dashboard view)
 *   • AccountingWatchdog CLI       (alert evaluator)
 *
 * Never mutates state. Read-only aggregation of:
 *   • feeWorkerHeartbeat / accountingReconHeartbeat ages
 *   • accountingIdempotency status counts + oldest processing age
 *   • accountingConfig periodLock doc
 *   • accounting collection 24h post count (cheap — bounded limit=200)
 *   • accountingAlerts open rows (from watchdog output)
 *
 * Alert thresholds are hardcoded here (single source) rather than
 * spread across controllers. Tune them in one place.
 *
 * Cheap enough to poll every 10 s from a dashboard: four small reads
 * + four small queries (capped limits). Worst case ~5 Firestore RTTs.
 */
final class Accounting_health
{
    // Thresholds — keep in sync with Phase 8C monitoring section
    public const WORKER_DOWN_SEC         = 180;   // 3 min
    public const RECONCILER_DOWN_SEC     = 1800;  // 30 min
    public const STUCK_IDEMP_SEC         = 300;   // 5 min
    public const OLDEST_PROCESSING_WARN  = 600;   // 10 min
    public const QUERY_CAP               = 200;

    /** @var object|null */  private $firebase = null;
    private string $schoolFs   = '';
    private string $session    = '';
    private string $schoolName = '';
    private bool   $ready      = false;

    public function init($firebase, string $schoolFs, string $session, string $schoolName = ''): void
    {
        $this->firebase   = $firebase;
        $this->schoolFs   = $schoolFs;
        $this->session    = $session;
        $this->schoolName = $schoolName !== '' ? $schoolName : $schoolFs;
        $this->ready      = ($firebase !== null && $schoolFs !== '' && $session !== '');
    }

    /**
     * Build the full snapshot. Returns a structure safe to JSON-encode
     * directly. `error` key is present only on partial failure.
     */
    public function snapshot(): array
    {
        if (!$this->ready) {
            return ['error' => 'Accounting_health not initialised'];
        }
        $out = [
            'collected_at' => date('c'),
            'schoolId'     => $this->schoolFs,
            'session'      => $this->session,
            'worker'       => $this->_workerState(),
            'reconciler'   => $this->_reconcilerState(),
            'idempotency'  => $this->_idempotencyState(),
            'period_lock'  => $this->_periodLockState(),
            'metrics_24h'  => $this->_metrics24h(),
            'open_alerts'  => $this->_openAlertsState(),
        ];
        // Compute synthetic alerts from the snapshot (for UIs that don't
        // want to fetch the alerts collection separately).
        $out['alerts'] = $this->_deriveAlerts($out);
        // Traffic light.
        $out['status'] = $this->_trafficLight($out['alerts']);
        return $out;
    }

    // ─────────────────────────────────────────────────────────────────
    //  Section collectors — each returns a small struct, never throws
    // ─────────────────────────────────────────────────────────────────

    private function _workerState(): array
    {
        $hb = $this->_safeGet('feeWorkerHeartbeat', "{$this->schoolFs}_{$this->session}");
        $lastRun = is_array($hb) ? (string) ($hb['lastRunAt'] ?? '') : '';
        $age     = $lastRun !== '' ? max(0, time() - strtotime($lastRun)) : -1;
        return [
            'last_run_at' => $lastRun,
            'age_seconds' => $age,
            'host'        => is_array($hb) ? (string) ($hb['host']     ?? '') : '',
            'last_stage'  => is_array($hb) ? (string) ($hb['lastStage'] ?? '') : '',
            'down'        => ($age < 0 || $age > self::WORKER_DOWN_SEC),
        ];
    }

    private function _reconcilerState(): array
    {
        $hb = $this->_safeGet('accountingReconHeartbeat', "{$this->schoolFs}_{$this->session}");
        $lastRun = is_array($hb) ? (string) ($hb['lastRunAt'] ?? '') : '';
        $age     = $lastRun !== '' ? max(0, time() - strtotime($lastRun)) : -1;
        return [
            'last_run_at' => $lastRun,
            'age_seconds' => $age,
            'host'        => is_array($hb) ? (string) ($hb['host']     ?? '') : '',
            'last_stage'  => is_array($hb) ? (string) ($hb['lastStage'] ?? '') : '',
            'down'        => ($age < 0 || $age > self::RECONCILER_DOWN_SEC),
            'last_stats'  => is_array($hb) ? [
                'idempRecovered' => (int) ($hb['idempRecovered'] ?? 0),
                'idempOrphaned'  => (int) ($hb['idempOrphaned']  ?? 0),
                'driftFound'     => (int) ($hb['driftFound']     ?? 0),
                'driftRepaired'  => (int) ($hb['driftRepaired']  ?? 0),
                'driftFailed'    => (int) ($hb['driftFailed']    ?? 0),
            ] : null,
        ];
    }

    private function _idempotencyState(): array
    {
        $state = [
            'processing_count'      => 0,
            'stuck_count'           => 0,
            'failed_count'          => 0,
            'oldest_processing_sec' => 0,
            'oldest_processing_key' => '',
        ];
        $stuckCutoff = date('c', time() - self::STUCK_IDEMP_SEC);
        try {
            $processing = (array) $this->firebase->firestoreQuery('accountingIdempotency', [
                ['schoolId', '==', $this->schoolFs],
                ['session',  '==', $this->session],
                ['status',   '==', 'processing'],
            ], 'startedAt', 'ASC', self::QUERY_CAP);
            $state['processing_count'] = count($processing);
            foreach ($processing as $p) {
                $st = (string) (($p['data']['startedAt'] ?? ''));
                if ($st !== '' && $st < $stuckCutoff) $state['stuck_count']++;
            }
            if (!empty($processing)) {
                $first = $processing[0];
                $ts = (string) ($first['data']['startedAt'] ?? '');
                if ($ts !== '') {
                    $state['oldest_processing_sec'] = max(0, time() - strtotime($ts));
                    $state['oldest_processing_key'] = (string) (($first['data']['idempKey'] ?? ''));
                }
            }
            $failed = (array) $this->firebase->firestoreQuery('accountingIdempotency', [
                ['schoolId', '==', $this->schoolFs],
                ['session',  '==', $this->session],
                ['status',   '==', 'failed'],
            ], null, 'ASC', self::QUERY_CAP);
            $state['failed_count'] = count($failed);
        } catch (\Throwable $e) {
            $state['error'] = $e->getMessage();
        }
        return $state;
    }

    private function _periodLockState(): array
    {
        $lock = $this->_safeGet('accountingConfig', "{$this->schoolFs}_{$this->session}_periodLock");
        if (!is_array($lock) || empty($lock['lockedUntil'])) {
            return ['locked_until' => '', 'open' => true];
        }
        return [
            'locked_until' => (string) ($lock['lockedUntil'] ?? ''),
            'locked_by'    => (string) ($lock['lockedBy']    ?? ''),
            'locked_at'    => (string) ($lock['lockedAt']    ?? ''),
            'reason'       => (string) ($lock['reason']      ?? ''),
            'open'         => false,
        ];
    }

    private function _metrics24h(): array
    {
        // Count active accounting docs created in the last 24h.
        // Bounded at QUERY_CAP to keep the call cheap — schools posting
        // > 200 journals/day will see "200+" in the dashboard.
        $cutoff = date('c', time() - 86400);
        try {
            $rows = (array) $this->firebase->firestoreQuery('accounting', [
                ['schoolId', '==', $this->schoolName !== '' ? $this->schoolName : $this->schoolFs],
                ['session',  '==', $this->session],
                ['status',   '==', 'active'],
            ], 'created_at', 'DESC', self::QUERY_CAP);
            $recent = 0;
            foreach ($rows as $r) {
                $ts = (string) ($r['data']['created_at'] ?? '');
                if ($ts !== '' && $ts >= $cutoff) $recent++;
            }
            return [
                'journals_24h'        => $recent,
                'journals_truncated'  => (count($rows) >= self::QUERY_CAP),
            ];
        } catch (\Throwable $e) {
            return ['journals_24h' => -1, 'error' => $e->getMessage()];
        }
    }

    private function _openAlertsState(): array
    {
        // Rows emitted by AccountingWatchdog that haven't been
        // acknowledged yet. `createdAt` index lets us fetch newest first.
        try {
            $rows = (array) $this->firebase->firestoreQuery('accountingAlerts', [
                ['schoolId',       '==', $this->schoolFs],
                ['session',        '==', $this->session],
                ['acknowledged',   '==', false],
            ], 'createdAt', 'DESC', 20);
            $out = [];
            foreach ($rows as $r) {
                $d = is_array($r['data'] ?? null) ? $r['data'] : [];
                $out[] = [
                    'alertId'   => (string) ($r['id'] ?? ''),
                    'severity'  => (string) ($d['severity'] ?? 'warning'),
                    'rule'      => (string) ($d['rule']     ?? ''),
                    'message'   => (string) ($d['message']  ?? ''),
                    'createdAt' => (string) ($d['createdAt'] ?? ''),
                ];
            }
            return ['open_count' => count($out), 'latest' => $out];
        } catch (\Throwable $e) {
            return ['open_count' => 0, 'latest' => [], 'error' => $e->getMessage()];
        }
    }

    /**
     * Pure function — takes the already-collected state and derives the
     * human-readable alert list for the dashboard banner. Kept separate
     * from the watchdog's persistent-alert emission so the dashboard
     * can surface conditions instantly without waiting for a watchdog
     * tick.
     */
    private function _deriveAlerts(array $state): array
    {
        $alerts = [];
        if ($state['worker']['down']) {
            $alerts[] = [
                'severity' => 'critical', 'rule' => 'worker_down',
                'message'  => 'FeeWorker has not checked in in ' .
                              ($state['worker']['age_seconds'] >= 0 ?
                              $state['worker']['age_seconds'] . 's' : 'ever') .
                              '. Payments are being recorded but not posted. Verify the scheduled task is running.',
            ];
        }
        if ($state['reconciler']['down']) {
            $alerts[] = [
                'severity' => 'critical', 'rule' => 'reconciler_down',
                'message'  => 'AccountingReconciler has not run in ' .
                              ($state['reconciler']['age_seconds'] >= 0 ?
                              $state['reconciler']['age_seconds'] . 's' : 'ever') .
                              '. Drift will accumulate silently. Verify the scheduled task.',
            ];
        }
        if (!empty($state['idempotency']['failed_count'])) {
            $alerts[] = [
                'severity' => 'error', 'rule' => 'idempotency_failed',
                'message'  => $state['idempotency']['failed_count'] . ' failed journal posting(s) need operator inspection.',
            ];
        }
        if (!empty($state['idempotency']['stuck_count'])) {
            $alerts[] = [
                'severity' => 'warning', 'rule' => 'idempotency_stuck',
                'message'  => $state['idempotency']['stuck_count'] . ' journal(s) stuck in processing > 5 min. Reconciler should clear on next run.',
            ];
        }
        if (!empty($state['idempotency']['oldest_processing_sec'])
            && $state['idempotency']['oldest_processing_sec'] > self::OLDEST_PROCESSING_WARN) {
            $alerts[] = [
                'severity' => 'warning', 'rule' => 'long_processing',
                'message'  => 'Oldest processing journal is ' .
                              $state['idempotency']['oldest_processing_sec'] .
                              's old (key: ' . $state['idempotency']['oldest_processing_key'] . ').',
            ];
        }
        return $alerts;
    }

    private function _trafficLight(array $alerts): string
    {
        foreach ($alerts as $a) {
            if (($a['severity'] ?? '') === 'critical') return 'red';
        }
        foreach ($alerts as $a) {
            if (($a['severity'] ?? '') === 'error') return 'red';
        }
        foreach ($alerts as $a) {
            if (($a['severity'] ?? '') === 'warning') return 'amber';
        }
        return 'green';
    }

    private function _safeGet(string $collection, string $docId): ?array
    {
        try {
            $d = $this->firebase->firestoreGet($collection, $docId);
            return is_array($d) ? $d : null;
        } catch (\Throwable $e) {
            return null;
        }
    }
}
