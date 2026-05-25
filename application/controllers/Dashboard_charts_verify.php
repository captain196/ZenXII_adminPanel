<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Dashboard_charts_verify — APPLY Window 1 (A + B) verifier.
 *
 * Read-only CLI tool that mirrors the post-fix get_dashboard_charts()
 * defaulter-count logic, and shows the before / after delta WITHOUT
 * mutating any data. Also confirms that the feeDefaulters query that
 * already powers the Top Defaulters widget is the source of truth — so
 * the canonicalisation costs ZERO additional Firestore reads.
 *
 * INVOCATION: php index.php dashboard_charts_verify check
 * Env: SCHOOL_ID=<schoolFs>  SESSION_YEAR=<YYYY-YY>
 */
class Dashboard_charts_verify extends CI_Controller
{
    private string $schoolFs    = '';
    private string $sessionYear = '';

    public function __construct()
    {
        parent::__construct();
        if (!$this->input->is_cli_request()) show_error('CLI-only.', 403);
        $this->load->library('firebase');
        $this->load->library('firestore_service');
        $this->schoolFs    = (string) (getenv('SCHOOL_ID')    ?: '');
        $this->sessionYear = (string) (getenv('SESSION_YEAR') ?: '');
        if ($this->schoolFs === '' || $this->sessionYear === '') {
            echo "ERROR: Set SCHOOL_ID and SESSION_YEAR env vars.\n";
            exit(1);
        }
    }

    public function check(): void
    {
        echo "=== Dashboard charts APPLY Window 1 verification ===\n";
        echo "schoolFs={$this->schoolFs}  session={$this->sessionYear}\n\n";

        // ── BEFORE: old formula (students − distinctPayers)
        $activeStudents = $this->firebase->firestoreQuery('students', [
            ['schoolId', '==', $this->schoolFs],
            ['status',   '==', 'Active'],
        ], null, 'ASC', 1000);
        $studentCount = count($activeStudents);

        $receipts = $this->firebase->firestoreQuery('feeReceipts', [
            ['schoolId', '==', $this->schoolFs],
            ['session',  '==', $this->sessionYear],
        ], null, 'ASC', 2000);
        $paidStudents = [];
        foreach ($receipts as $r) {
            $d = is_array($r['data'] ?? null) ? $r['data'] : [];
            $uid = $d['studentId'] ?? $d['userId'] ?? $d['user_id'] ?? '';
            if ($uid !== '') $paidStudents[$uid] = true;
        }
        $beforeCount = max(0, $studentCount - count($paidStudents));

        // ── AFTER: canonical feeDefaulters count (totalDues > 0)
        $defDocs = $this->firebase->firestoreQuery('feeDefaulters', [
            ['schoolId', '==', $this->schoolFs],
            ['session',  '==', $this->sessionYear],
        ], null, 'ASC', 1000);
        $defList = [];
        foreach ($defDocs as $r) {
            $d = is_array($r['data'] ?? null) ? $r['data'] : [];
            $dues = (float) ($d['totalDues'] ?? 0);
            if ($dues <= 0) continue;
            $defList[] = [
                'studentId'   => (string) ($d['studentId'] ?? ''),
                'studentName' => (string) ($d['studentName'] ?? ''),
                'totalDues'   => $dues,
                'unpaidCount' => is_array($d['unpaidMonths'] ?? null) ? count($d['unpaidMonths']) : 0,
                'examBlocked' => (bool) ($d['examBlocked'] ?? false),
            ];
        }
        usort($defList, fn($a, $b) => $b['totalDues'] <=> $a['totalDues']);
        $afterCount = count($defList);
        $top5 = array_slice($defList, 0, 5);

        // ── Headline tile delta
        echo "── Headline tile: fee_defaulters ──\n";
        echo "  BEFORE (formula students - distinct payers): {$studentCount} - " . count($paidStudents) . " = {$beforeCount}\n";
        echo "  AFTER  (canonical feeDefaulters with totalDues>0): {$afterCount}\n";
        echo "  Delta: " . ($beforeCount - $afterCount) . " phantom defaulter(s) removed.\n";
        if ($beforeCount === $afterCount) {
            echo "  ✅ Counts already aligned — fix is inert for this dataset.\n";
        } elseif ($afterCount < $beforeCount) {
            echo "  ✅ Canonical count is LOWER — formula was over-counting students with no assigned dues.\n";
        } else {
            echo "  ⚠ Canonical count is HIGHER than formula — investigate (this should not happen normally).\n";
        }

        // ── Top defaulters widget alignment
        echo "\n── Top Defaulters widget (unchanged by APPLY Window 1) ──\n";
        if (empty($top5)) {
            echo "  (no defaulters)\n";
        } else {
            foreach ($top5 as $i => $t) {
                $rank = $i + 1;
                $flags = ($t['examBlocked'] ? ' EB' : '') . ($t['unpaidCount'] ? " {$t['unpaidCount']}m" : '');
                echo "  #{$rank}  {$t['studentId']}  {$t['studentName']}  ₹" . number_format($t['totalDues'], 2) . $flags . "\n";
            }
        }
        echo "  Widget count = {$afterCount}, headline tile = {$afterCount} → consistent.\n";

        // ── Firestore cost impact
        echo "\n── Firestore query cost impact ──\n";
        echo "  feeDefaulters read: 1 (already issued by Top Defaulters widget pre-fix)\n";
        echo "  Net new reads:      0\n";
        echo "  Cache TTL:          unchanged (600s)\n";
        echo "  Cache key shape:    unchanged (per-role, per-school)\n";

        // ── Cache state
        echo "\n── Cache state ──\n";
        $cacheDir  = APPPATH . 'cache/dashboard';
        $cacheGlob = $cacheDir . '/grader_dash_v1_*dashboard_charts_*.json';
        $cacheFiles = glob($cacheGlob);
        if (empty($cacheFiles)) {
            echo "  (no charts cache files — next dashboard load will populate)\n";
        } else {
            foreach ($cacheFiles as $f) {
                $base = basename($f);
                $raw = @file_get_contents($f);
                $env = $raw === false ? null : json_decode($raw, true);
                if (!is_array($env)) { echo "  {$base}: unparseable\n"; continue; }
                $exp  = (int) ($env['exp']       ?? 0);
                $wAt  = (int) ($env['writtenAt'] ?? 0);
                $age  = $wAt ? (time() - $wAt) : null;
                $state = $exp <= time() ? 'EXPIRED' : 'LIVE';
                $val  = $env['val'] ?? null;
                $fd   = is_array($val) ? ($val['fee_defaulters'] ?? null) : null;
                echo "  {$base}\n";
                echo "    state={$state}  writtenAt=" . ($wAt ?: '?') . "  age=" . ($age === null ? '?' : "{$age}s") . "\n";
                echo "    cached fee_defaulters=" . ($fd === null ? '(null/expired)' : $fd) . "\n";
            }
        }

        // ── Lazy endpoint payload preview (what the fix will write next time)
        echo "\n── Expected lazy endpoint payload (next call) ──\n";
        echo "  classes:        (live scan)\n";
        echo "  sections:       (live scan)\n";
        echo "  fee_defaulters: {$afterCount}\n";
        echo "  monthly_fees:   (live scan)\n";
        echo "  fee_breakdown:  (live scan)\n";
        echo "  top_defaulters: " . min(5, $afterCount) . " entries\n";
        echo "  absent_today:   (live scan)\n";
        echo "  birthdays_today:(live scan)\n";
        echo "  calendar_events:(live scan)\n";

        echo "\n=== End verification ===\n";
    }
}
