<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Leave_accrual_service — the single authority for monthly leave accrual.
 * Extracted from Hr.php (2026-09-20) so the session-less platform cron
 * (Leave_accrual_cron) and the HR web path run the EXACT same logic.
 * init() with an explicit school context; nothing here reads a session.
 */
class Leave_accrual_service
{
    protected $firebase; protected $fs; protected $schoolId = ''; protected $session = '';
    protected $_lpCache = null; // per-instance cache of this school's leavePolicy map

    public function init($firebase, $fs, string $schoolId, string $session = '')
    {
        $this->firebase = $firebase; $this->fs = $fs;
        $this->schoolId = $schoolId; $this->session = $session;
        return $this;
    }

    /** Per-school master switch: schools.leavePolicy.accrualEnabled (default false, fail-closed). */
    public function schoolAccrualEnabled(): bool
    {
        $enabled = false;
        try {
            $doc = $this->fs->get('schools', $this->schoolId);
            $lp  = (is_array($doc) && is_array($doc['leavePolicy'] ?? null)) ? $doc['leavePolicy'] : [];
            $v   = $lp['accrualEnabled'] ?? false;
            $enabled = ($v === true || $v === 1 || $v === '1' || $v === 'yes' || $v === 'true');
        } catch (\Throwable $e) {
            log_message('error', 'Leave_accrual_service::schoolAccrualEnabled read failed: ' . $e->getMessage());
            $enabled = false;
        }
        return $enabled;
    }

    /** Credit accrual for every active staff x every accrual_mode=monthly type, through $throughMonth (YYYY-MM). Idempotent. Returns stats. */
    public function accrueSchool(string $throughMonth): array
    {
        $year = substr($throughMonth, 0, 4);
        $monthlyTypeIds = $this->activeMonthlyTypes($throughMonth);
        if (empty($monthlyTypeIds)) {
            return ['throughMonth'=>$throughMonth,'staffProcessed'=>0,'typesAccrued'=>0,'creditsIssued'=>0,'note'=>'No accrual_mode=monthly types configured.'];
        }
        $roster = $this->activeRoster();
        $staffProcessed = 0; $creditsIssued = 0;
        foreach (array_keys($roster) as $staffId) {
            $staffProcessed++;
            foreach ($monthlyTypeIds as $tid => $lt) {
                $r = $this->accrueOneType($staffId, $year, $tid, $lt, $throughMonth);
                if ($r['action'] === 'credited') $creditsIssued++;
            }
        }
        return ['throughMonth'=>$throughMonth,'staffProcessed'=>$staffProcessed,'typesAccrued'=>count($monthlyTypeIds),'creditsIssued'=>$creditsIssued];
    }

    /** The per-(staff,type) engine. Idempotent via lastAccrualMonth. */
    public function accrueOneType(string $staffId, string $year, string $typeId, array $type, string $throughMonth): array
    {
        $none = ['action' => 'skipped_flag_off', 'credited' => 0.0, 'months' => []];
        // Per-school authority (2026-09-19): accrual runs only for a school that
        // opted in via schools.leavePolicy.accrualEnabled. Default false → this
        // engine is a no-op for every existing tenant and every new school, so
        // enabling it is the school's own decision, not a fleet-wide flag.
        if (!$this->schoolAccrualEnabled()) return $none;

        require_once APPPATH . 'libraries/Leave_policy_service.php';
        $ver = \Leave_policy_service::effectiveVersion($type, "{$throughMonth}-28");
        if ($ver === null) {
            return ['action' => 'skipped_no_version', 'credited' => 0.0, 'months' => []];
        }
        if ((string) ($ver['accrual_mode'] ?? 'lump_sum') !== 'monthly') {
            return ['action' => 'skipped_not_monthly', 'credited' => 0.0, 'months' => []];
        }

        $daysPerYear = (float) ($ver['days_per_year'] ?? 0);
        $maxBalance  = $this->leaveFlag('LEAVE_MAX_BALANCE_ENABLED') ? ($ver['max_balance'] ?? null) : null;
        $maxBalance  = ($maxBalance !== null && $maxBalance !== '') ? (float) $maxBalance : null;

        // Earliest creditable month: the version's own effectiveFrom month,
        // never earlier (a type can't accrue before its policy existed) —
        // Phase 4's join-date pro-rata boundary composes with this later
        // without needing to change this earliest-month logic.
        $versionMonth = substr((string) ($ver['effectiveFrom'] ?? "{$year}-01-01"), 0, 7);
        $yearStart    = "{$year}-01";
        $earliest     = ($versionMonth > $yearStart) ? $versionMonth : $yearStart;

        // Distribute EXACTLY $daysPerYear across the accrual window
        // [earliest .. December of $year] via installment(), so the year
        // sums to the school's configured annual instead of the old fixed
        // round($daysPerYear/12) that diverged (20/yr → 18, 15/yr → 18).
        $winStartIdx    = ((int) substr($earliest, 0, 4)) * 12 + (int) substr($earliest, 5, 2);
        $monthsInWindow = max(1, (((int) $year) * 12 + 12) - $winStartIdx + 1);

        $creditedMonths = [];
        $totalCredited  = 0.0;
        $writeOk = $this->casBalances($staffId, $year, function (array $balances) use (
            $typeId, $earliest, $throughMonth, $daysPerYear, $winStartIdx, $monthsInWindow,
            $maxBalance, &$creditedMonths, &$totalCredited
        ) {
            $entry = is_array($balances[$typeId] ?? null) ? $balances[$typeId] : [];
            $accruedToDate   = (float) ($entry['accruedToDate'] ?? 0);
            $used            = (float) ($entry['used'] ?? 0);
            $lastMonth       = (string) ($entry['lastAccrualMonth'] ?? '');
            $startMonth      = ($lastMonth !== '' && $lastMonth >= $earliest)
                ? $this->nextMonth($lastMonth) : $earliest;

            $cursor = $startMonth;
            $guard  = 0;
            while ($cursor <= $throughMonth && $guard < 12) {
                $guard++;
                $curIdx      = ((int) substr($cursor, 0, 4)) * 12 + (int) substr($cursor, 5, 2);
                $installment = self::installment(
                    $daysPerYear, $curIdx - $winStartIdx + 1, $monthsInWindow);
                $next = $accruedToDate + $installment;
                // Phase 6 (2026-08-18) — the ceiling caps the CONSUMABLE
                // balance (accrued - used), not raw accruedToDate: "if
                // balance is already 30, next accrual is blocked; if the
                // employee consumes 5, balance = 25, future accrual can
                // resume" (authorization's own worked example). Checking
                // accruedToDate alone would permanently stop accrual the
                // first time the ceiling is touched, even after usage frees
                // up room — wrong per that example.
                if ($maxBalance !== null && ($next - $used) > $maxBalance) {
                    // Ceiling reached — pause crediting for this and every
                    // later month in this run; resumes automatically on a
                    // LATER run once usage drops the balance below the
                    // ceiling (re-checked fresh every run, never clawed
                    // back here).
                    break;
                }
                $accruedToDate = $next;
                $creditedMonths[] = $cursor;
                $totalCredited += $installment;
                $lastMonth = $cursor;
                $cursor = $this->nextMonth($cursor);
            }

            $entry['accruedToDate']    = $accruedToDate;
            $entry['lastAccrualMonth'] = $lastMonth !== '' ? $lastMonth : ($entry['lastAccrualMonth'] ?? '');
            $balances[$typeId] = $entry;
            return $balances;
        });

        if (!$writeOk) {
            return ['action' => 'skipped_write_failed', 'credited' => 0.0, 'months' => []];
        }
        if (empty($creditedMonths)) {
            return ['action' => 'skipped_up_to_date', 'credited' => 0.0, 'months' => []];
        }

        $this->logAudit([
            'action'         => 'leave_accrual_credited',
            'staff_id'       => $staffId,
            'leave_type_id'  => $typeId,
            'year'           => $year,
            'months'         => implode(',', $creditedMonths),
            'amount_credited'=> $totalCredited,
        ]);

        return ['action' => 'credited', 'credited' => $totalCredited, 'months' => $creditedMonths];
    }

    /** Per-month installment distributing EXACTLY days_per_year across the window (pure). */
    public static function installment(float $daysPerYear, int $ordinal, int $monthsInWindow): float
    {
        if ($monthsInWindow < 1) $monthsInWindow = 1;
        if ($ordinal < 1 || $ordinal > $monthsInWindow) return 0.0;
        $cum = static function (int $k) use ($daysPerYear, $monthsInWindow): float {
            if ($k <= 0)               return 0.0;
            if ($k >= $monthsInWindow) return $daysPerYear;
            return round($daysPerYear * $k / $monthsInWindow * 2) / 2;
        };
        return $cum($ordinal) - $cum($ordinal - 1);
    }

    protected function nextMonth(string $yyyyMm): string
    {
        $ts = mktime(0, 0, 0, (int) substr($yyyyMm, 5, 2) + 1, 1, (int) substr($yyyyMm, 0, 4));
        return date('Y-m', $ts);
    }

    protected function activeMonthlyTypes(string $throughMonth): array
    {
        $types = [];
        try {
            $doc = $this->fs->get('schools', $this->schoolId);
            $all = (is_array($doc) && is_array($doc['leaveTypes'] ?? null)) ? $doc['leaveTypes'] : [];
        } catch (\Throwable $e) { $all = []; }
        require_once APPPATH . 'libraries/Leave_policy_service.php';
        foreach ((array) $all as $tid => $lt) {
            if (!is_array($lt)) continue;
            if (strtolower(trim((string) ($lt['status'] ?? 'Active'))) !== 'active') continue;
            $ver = \Leave_policy_service::effectiveVersion($lt, "{$throughMonth}-28");
            if ($ver !== null && (string) ($ver['accrual_mode'] ?? 'lump_sum') === 'monthly') {
                $types[$tid] = $lt;
            }
        }
        return $types;
    }

    protected function activeRoster(): array
    {
        $roster = [];
        try {
            $fsDocs = $this->fs->schoolWhere('staff', [['status', '==', 'Active']]);
            if (!empty($fsDocs)) {
                foreach ($fsDocs as $doc) {
                    $d   = is_array($doc['data'] ?? null) ? $doc['data'] : $doc;
                    $sid = $d['staffId'] ?? $d['userId'] ?? '';
                    if ($sid !== '') $roster[$sid] = $d;
                }
            }
        } catch (\Exception $e) {
            log_message('error', 'HR _activeStaffRoster: Firestore failed: ' . $e->getMessage());
        }
        return $roster;
    }

    protected function leaveFlag(string $name): bool
    {
        // PURE per-school (schools.leavePolicy[$name]) — the SAME resolution
        // Hr::_leave_flag() uses, so what the engine credits and what the panel
        // shows can never disagree. The legacy fleet-wide platform-flag fallback
        // has been CUT: absent ⇒ OFF. Cached per instance (a fresh service is
        // built per tenant), so no cross-tenant bleed.
        if ($this->_lpCache === null) {
            try {
                $doc = $this->fs->get('schools', $this->schoolId);
                $this->_lpCache = (is_array($doc) && is_array($doc['leavePolicy'] ?? null)) ? $doc['leavePolicy'] : [];
            } catch (\Throwable $e) {
                $this->_lpCache = [];
            }
        }
        $v = $this->_lpCache[$name] ?? false;
        return ($v === true || $v === 1 || $v === '1' || $v === 'yes' || $v === 'true');
    }

    protected function casBalances(string $staffId, string $year, callable $mutate, int $maxAttempts = 5): bool
    {
        $docId  = $this->fs->docId("BAL_{$staffId}_{$year}");
        $client = $this->fs->raw_client();

        for ($attempt = 0; $attempt < max(1, $maxAttempts); $attempt++) {
            $doc      = $this->fs->get('leaveApplications', $docId);
            $exists   = is_array($doc) && !empty($doc);
            $balances = ($exists && is_array($doc['balances'] ?? null)) ? $doc['balances'] : [];

            $newBalances = $mutate($balances);
            if (!is_array($newBalances)) return false;

            $data = [
                'schoolId'  => $this->schoolId,
                'staffId'   => $staffId,
                'year'      => $year,
                'balances'  => $newBalances,
                'type'      => 'balance',
                'updatedAt' => date('c'),
            ];

            // No CAS token available (e.g. non-REST client) → best-effort merge write.
            if ($client === null || !method_exists($client, 'commitBatch')
                || ($exists && empty($doc['__updateTime']))) {
                return (bool) $this->fs->set('leaveApplications', $docId, $data, true);
            }

            $precondition = $exists
                ? ['updateTime' => (string) $doc['__updateTime']]  // CAS
                : ['exists' => false];                             // create-or-collide

            $ok = $client->commitBatch([[
                'op'           => 'set',
                'collection'   => 'leaveApplications',
                'docId'        => $docId,
                'data'         => $data,
                'merge'        => true,
                'precondition' => $precondition,
            ]]);
            if ($ok) return true;

            // Precondition failed → a concurrent writer won; back off and retry
            // against a fresh read (commitBatch already flushed the read memo).
            usleep(40000 * ($attempt + 1));
        }
        log_message('error', "HR _casLeaveBalance: CAS exhausted {$maxAttempts} attempts for {$staffId}/{$year}");
        return false;
    }

    protected function logAudit(array $data): void
    {
        try {
            $pushId  = 'AUDIT_' . uniqid('', true);
            $fsEntry = array_merge([
                'schoolId'  => $this->schoolId,
                'type'      => 'audit',
                'source'    => 'accrual_service',
                'timestamp' => date('c'),
                'admin_id'  => 'system:accrual_cron',
            ], $data);
            $this->fs->set('leaveApplications', $this->fs->docId($pushId), $fsEntry, true);
        } catch (\Throwable $e) {
            log_message('error', 'Leave_accrual_service::logAudit → Firestore FAILED: ' . $e->getMessage());
        }
    }
}
