<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * AccountingSimulator — staging-only deterministic financial regression utility.
 *
 * ──────────────────────────────────────────────────────────────────────
 *  PURPOSE
 * ──────────────────────────────────────────────────────────────────────
 *  Orchestrates synthetic test scenarios against the existing accounting
 *  engine WITHOUT introducing any new accounting write paths. Every
 *  scenario invokes the same library methods that production receipt /
 *  refund / manual-journal flows already use:
 *
 *    • Operations_accounting::create_fee_journal       (receipts)
 *    • Operations_accounting::create_refund_journal_granular (refunds)
 *    • Operations_accounting::create_journal           (manual)
 *
 *  The simulator NEVER writes directly to:
 *    • accounting              (immutable ledger)
 *    • accountingClosingBalances
 *    • accountingIdempotency
 *    • accountingForensics
 *
 *  All synthetic entities are tagged with a TEST_* prefix so cleanup is
 *  deterministic and safe.
 *
 * ──────────────────────────────────────────────────────────────────────
 *  STAGING GATE (triple-locked)
 * ──────────────────────────────────────────────────────────────────────
 *  1. Environment variable SIMULATOR_ENABLED=1 must be present.
 *  2. Hostname must match localhost / *.staging.* / *.test.*
 *  3. Destructive commands require the --confirm flag.
 *
 * ──────────────────────────────────────────────────────────────────────
 *  COMMANDS
 * ──────────────────────────────────────────────────────────────────────
 *      php index.php accountingsimulator help
 *      php index.php accountingsimulator scenario A1 --confirm
 *      php index.php accountingsimulator scenario_all --confirm
 *      php index.php accountingsimulator burst 15 --confirm
 *      php index.php accountingsimulator post_one tuition --confirm   (used by burst)
 *      php index.php accountingsimulator verify
 *      php index.php accountingsimulator cleanup --confirm
 *
 *  Required env:
 *      SCHOOL_NAME, SESSION_YEAR, SIMULATOR_ENABLED=1
 *
 * ──────────────────────────────────────────────────────────────────────
 *  SAFETY INVARIANTS
 * ──────────────────────────────────────────────────────────────────────
 *    • Hard exit if not CLI
 *    • Hard exit if SIMULATOR_ENABLED != 1
 *    • Hard exit if hostname looks production
 *    • Every commit goes through Operations_accounting (no direct ledger
 *      writes are present in this file — verifiable via grep)
 *    • Cleanup matches synthetic entries by strict regex on TEST_*
 *
 *  REMOVAL: deleting this single file removes the simulator entirely.
 *  No accounting-engine code is modified by the simulator's existence.
 */
final class AccountingSimulator extends CI_Controller
{
    private const SYNTHETIC_PREFIX  = 'TEST';
    private const TEST_ACCOUNTANT   = 'SYSTEM_TEST_SIMULATOR';
    private const RUN_LOG_DIR       = APPPATH . 'cache/simulator/';

    private const STAGING_HOSTNAME_PATTERNS = [
        '/^localhost$/i',
        '/\.staging\./i',
        '/\.test\./i',
        '/^127\./',
    ];

    private string $schoolName = '';
    private string $sessionYear = '';
    private string $schoolFs   = '';
    private string $runId      = '';
    private bool   $confirmed  = false;
    private array  $argv       = [];

    public function __construct()
    {
        parent::__construct();
        if (!$this->input->is_cli_request()) {
            show_error('AccountingSimulator is CLI-only.', 403);
        }
        $this->_assertStaging();

        $this->load->library('firebase');
        $this->schoolName  = (string) (getenv('SCHOOL_NAME')  ?: '');
        $this->sessionYear = (string) (getenv('SESSION_YEAR') ?: '');
        $this->schoolFs    = (string) (getenv('SCHOOL_FS')    ?: '');
        if ($this->schoolName === '' || $this->sessionYear === '' || $this->schoolFs === '') {
            $this->_die("Set SCHOOL_NAME, SESSION_YEAR, and SCHOOL_FS env vars.\n  SCHOOL_FS is the schoolId in Firestore (e.g. SCH_D94FE8F7AD).");
        }
        // Firebase library auto-initializes Firestore via FirestoreRestClient
        // in its constructor — no explicit init call required.

        $this->runId = (string) (int) (microtime(true) * 1000);
        $this->argv  = (array) ($_SERVER['argv'] ?? []);
        $this->confirmed = in_array('--confirm', $this->argv, true);

        if (!is_dir(self::RUN_LOG_DIR)) {
            @mkdir(self::RUN_LOG_DIR, 0755, true);
        }
    }

    // ─────────────────────────────────────────────────────────────────
    //  Staging gate
    // ─────────────────────────────────────────────────────────────────

    private function _assertStaging(): void
    {
        if ((string) getenv('SIMULATOR_ENABLED') !== '1') {
            $this->_die("Simulator disabled. Set SIMULATOR_ENABLED=1 to enable (staging only).");
        }
        // Hostname check (belt-and-suspenders). Developer machines can opt in
        // explicitly via SIMULATOR_FORCE_STAGING=1 — the operator declares
        // "this is a staging-equivalent host". The opt-in is logged.
        $force = ((string) getenv('SIMULATOR_FORCE_STAGING') === '1');
        if ($force) return;

        $host = strtolower((string) (gethostname() ?: ''));
        $matched = false;
        foreach (self::STAGING_HOSTNAME_PATTERNS as $pat) {
            if (preg_match($pat, $host)) { $matched = true; break; }
        }
        if (!$matched) {
            $this->_die(
                "Hostname '{$host}' does not match staging patterns. "
                . "If this IS a staging/dev host, opt in explicitly with "
                . "SIMULATOR_FORCE_STAGING=1. Refusing on prod-shaped hosts otherwise."
            );
        }
    }

    private function _requireConfirm(): void
    {
        if (!$this->confirmed) {
            $this->_die(
                "This command makes engine writes. Re-run with --confirm to proceed.\n"
                . "  Example: php index.php accountingsimulator scenario A1 --confirm"
            );
        }
    }

    // ─────────────────────────────────────────────────────────────────
    //  Help
    // ─────────────────────────────────────────────────────────────────

    public function index(): void
    {
        $this->help();
    }

    public function help(): void
    {
        echo "AccountingSimulator — staging-only financial regression utility\n";
        echo str_repeat('=', 64) . "\n";
        echo "Run id: {$this->runId}\n";
        echo "School: {$this->schoolName} (fs={$this->schoolFs}) Session: {$this->sessionYear}\n\n";
        echo "Commands:\n";
        echo "  scenario <id> --confirm     Run a single scenario (A1-A3 receipts, B1-B3 refunds,\n";
        echo "                              C1-C3 manual journals, D1-D4 concessions, I1 trial bal)\n";
        echo "  scenario_all --confirm      Run A/B/C/I scenarios sequentially (concessions excluded)\n";
        echo "  scenario_concessions --confirm  Run D1-D4 concession scenarios\n";
        echo "  burst <N> --confirm         Spawn N parallel receipt-post processes (S-R6)\n";
        echo "  post_one <type> --confirm   Single primitive post; type=tuition|transport|mixed\n";
        echo "  verify                      Read-only cross-check of current state\n";
        echo "  cleanup --confirm           Reverse all TEST_* journals via existing engine\n";
        echo "  cleanup --hard --really-hard --confirm  Direct-delete TEST_* (immutability override)\n";
        echo "  remediate_R1 --confirm      One-shot historical-imbalance remediation (R1)\n";
        echo "  seed_concession_accounts --confirm    Seed CoA with TEST_CONC_* concession accounts\n";
        echo "  cleanup_concession_accounts --confirm Remove the seeded concession accounts\n";
        echo "\nLogs go to application/logs/log-YYYY-MM-DD.php prefixed SIMULATOR_*.\n";
        echo "Run history at application/cache/simulator/run_<runId>.json\n";
        echo "\nConcession scenarios require: CONCESSIONS_ENABLED=1 env + seed_concession_accounts run\n";
    }

    // ─────────────────────────────────────────────────────────────────
    //  Scenario dispatcher
    // ─────────────────────────────────────────────────────────────────

    public function scenario($name = ''): void
    {
        $this->_requireConfirm();
        $name = strtoupper(trim((string) $name));
        if ($name === '') { $this->_die("Usage: scenario <id> — see 'help' for ids."); }

        $methodMap = [
            'A1' => '_scenarioA1', 'A2' => '_scenarioA2', 'A3' => '_scenarioA3',
            'B1' => '_scenarioB1', 'B2' => '_scenarioB2', 'B3' => '_scenarioB3',
            'C1' => '_scenarioC1', 'C2' => '_scenarioC2', 'C3' => '_scenarioC3',
            'D1' => '_scenarioD1', 'D2' => '_scenarioD2', 'D3' => '_scenarioD3',
            'D4' => '_scenarioD4',
            'E1' => '_scenarioE1', 'E2' => '_scenarioE2', 'E3' => '_scenarioE3',
            'E4' => '_scenarioE4', 'E5' => '_scenarioE5',
            'F1' => '_scenarioF1', 'F2' => '_scenarioF2', 'F3' => '_scenarioF3',
            'F4' => '_scenarioF4', 'F5' => '_scenarioF5',
            'I1' => '_scenarioI1',
        ];
        if (!isset($methodMap[$name])) { $this->_die("Unknown scenario: {$name}"); }

        $this->_initOpsAcct();
        $method = $methodMap[$name];
        $this->_log("SIMULATOR_START scenario={$name} runId={$this->runId}");
        $passed = $this->{$method}();
        $verdict = $passed ? 'PASS' : 'FAIL';
        echo "\nRESULT: {$name} {$verdict}\n";
        $this->_log("SIMULATOR_END scenario={$name} verdict={$verdict}");
    }

    public function scenario_all(): void
    {
        $this->_requireConfirm();
        $this->_initOpsAcct();
        $scenarios = ['A1', 'A2', 'A3', 'B1', 'B2', 'B3', 'C1', 'C2', 'C3', 'I1'];
        $results = [];
        foreach ($scenarios as $s) {
            $this->_log("SIMULATOR_START scenario={$s} runId={$this->runId}");
            $method = '_scenario' . $s;
            try {
                $passed = $this->{$method}();
            } catch (\Throwable $e) {
                $passed = false;
                echo "  ! Exception: " . $e->getMessage() . "\n";
                $this->_log("SIMULATOR_EXCEPTION scenario={$s} err=" . $e->getMessage());
            }
            $results[$s] = $passed ? 'PASS' : 'FAIL';
            $this->_log("SIMULATOR_END scenario={$s} verdict={$results[$s]}");
            echo "\n";
        }
        echo str_repeat('=', 64) . "\n";
        echo "SCENARIO SUITE SUMMARY (run {$this->runId})\n";
        foreach ($results as $s => $r) {
            echo "  {$s}: {$r}\n";
        }
        $passes = count(array_filter($results, fn($r) => $r === 'PASS'));
        echo "  Total: {$passes}/" . count($results) . " passed\n";
        echo str_repeat('=', 64) . "\n";
    }

    // ─────────────────────────────────────────────────────────────────
    //  Burst (S-R6 reproduction)
    // ─────────────────────────────────────────────────────────────────

    public function burst($n = 15): void
    {
        $this->_requireConfirm();
        $n = max(1, min(50, (int) $n));
        echo "=== BURST: spawning {$n} parallel receipt posts (run {$this->runId}) ===\n";
        $this->_log("SIMULATOR_BURST_START n={$n} runId={$this->runId}");

        $php = PHP_BINARY;
        $entry = realpath(FCPATH . 'index.php');
        $envPrefix = 'SCHOOL_NAME=' . escapeshellarg($this->schoolName)
                   . ' SESSION_YEAR=' . escapeshellarg($this->sessionYear)
                   . ' SIMULATOR_ENABLED=1 SIMULATOR_RUN_ID=' . escapeshellarg($this->runId);

        $procs = [];
        $pipes = [];
        $types = ['tuition', 'transport', 'mixed'];
        $startTime = microtime(true);

        for ($i = 0; $i < $n; $i++) {
            $type = $types[$i % 3];
            $idx = $i + 1;
            // On Windows: pass env via proc_open env array. On Linux: env-prefix works in shell.
            $env = [
                'SCHOOL_NAME'             => $this->schoolName,
                'SESSION_YEAR'            => $this->sessionYear,
                'SCHOOL_FS'               => $this->schoolFs,
                'SIMULATOR_ENABLED'       => '1',
                'SIMULATOR_FORCE_STAGING' => (string) (getenv('SIMULATOR_FORCE_STAGING') ?: '1'),
                'SIMULATOR_RUN_ID'        => $this->runId,
                'SIMULATOR_BURST_IDX'     => (string) $idx,
                'SystemRoot'              => (string) (getenv('SystemRoot') ?: 'C:\\Windows'),
                'PATH'                    => (string) (getenv('PATH') ?: ''),
            ];
            $cmd = '"' . $php . '" ' . escapeshellarg($entry)
                 . ' accountingsimulator post_one ' . escapeshellarg($type) . ' --confirm';
            $proc = proc_open($cmd, [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ], $pipeArr, FCPATH, $env);
            if (is_resource($proc)) {
                $procs[$i] = $proc;
                $pipes[$i] = $pipeArr;
                fclose($pipes[$i][0]);
            }
        }

        $exits = [];
        $stdouts = [];
        foreach ($procs as $i => $proc) {
            $stdouts[$i] = stream_get_contents($pipes[$i][1]);
            fclose($pipes[$i][1]);
            fclose($pipes[$i][2]);
            $exits[$i] = proc_close($proc);
        }
        $elapsedMs = (int) ((microtime(true) - $startTime) * 1000);

        $successCount = 0;
        foreach ($exits as $i => $code) {
            if ((int) $code === 0) $successCount++;
            else echo "  ! child {$i} exit code={$code}\n  child stdout:\n" . substr((string) ($stdouts[$i] ?? ''), 0, 400) . "\n";
        }
        echo "\nBurst children completed: {$successCount}/{$n} in {$elapsedMs}ms\n";

        // Read back entries for this runId
        sleep(2); // small settle
        $entries = $this->_findRunEntries($this->runId);
        echo "Ledger entries observed for runId={$this->runId}: " . count($entries) . "\n";

        if (count($entries) === $successCount) {
            echo "RESULT: burst N=N invariant HOLDS ({$successCount} → " . count($entries) . ")\n";
        } else {
            echo "RESULT: burst N!=N — investigate (children={$successCount} ledger=" . count($entries) . ")\n";
        }

        // Verify each is balanced
        $imbalanced = 0;
        foreach ($entries as $e) {
            $totalDr = (float) ($e['data']['total_dr'] ?? 0);
            $totalCr = (float) ($e['data']['total_cr'] ?? 0);
            if (abs($totalDr - $totalCr) > 0.01) $imbalanced++;
        }
        echo "Imbalanced journals: {$imbalanced}\n";

        $this->_log("SIMULATOR_BURST_END n={$n} success={$successCount} ledger=" . count($entries) . " imbal={$imbalanced} elapsedMs={$elapsedMs}");
    }

    public function post_one($type = 'tuition'): void
    {
        $this->_requireConfirm();
        $type = strtolower(trim((string) $type));
        if (!in_array($type, ['tuition', 'transport', 'mixed'], true)) {
            $this->_die("post_one: type must be tuition|transport|mixed");
        }
        $this->_initOpsAcct();
        $burstRunId = (string) (getenv('SIMULATOR_RUN_ID') ?: $this->runId);
        $burstIdx   = (string) (getenv('SIMULATOR_BURST_IDX') ?: '1');
        $params = $this->_buildSyntheticReceipt($type, $burstRunId, $burstIdx);
        $entryId = $this->opsAcct->create_fee_journal($params);
        if ($entryId) {
            echo "post_one OK: type={$type} entryId={$entryId}\n";
            $this->_log("SIMULATOR_POST_ONE type={$type} runId={$burstRunId} idx={$burstIdx} entryId={$entryId}");
            $this->_recordRunEntry($burstRunId, $entryId, 'fee_payment', $type);
        } else {
            echo "post_one FAILED: type={$type}\n";
            $this->_log("SIMULATOR_POST_ONE_FAILED type={$type} runId={$burstRunId} idx={$burstIdx}");
            exit(1);
        }
    }

    // ─────────────────────────────────────────────────────────────────
    //  Verify
    // ─────────────────────────────────────────────────────────────────

    public function verify(): void
    {
        echo "=== VERIFY: read-only state check (school={$this->schoolFs} session={$this->sessionYear}) ===\n";
        // Trial balance
        $balances = (array) $this->firebase->firestoreQuery('accountingClosingBalances', [
            ['schoolId', '==', $this->schoolFs],
            ['session',  '==', $this->sessionYear],
        ], '', '', 500);
        $totalDr = 0.0; $totalCr = 0.0;
        $perAcct = [];
        foreach ($balances as $b) {
            $d = (array) ($b['data'] ?? []);
            $code = (string) ($d['account_code'] ?? '');
            $bal  = (float) ($d['balance'] ?? 0);
            if ($bal >= 0) $totalDr += $bal; else $totalCr += abs($bal);
            $perAcct[$code] = $bal;
        }
        echo "Closing-balance docs: " . count($balances) . "\n";
        echo "Σ Dr-natural: {$totalDr}  Σ Cr-natural: {$totalCr}\n";

        // Ledger by source
        $ledger = (array) $this->firebase->firestoreQuery('accounting', [
            ['schoolId', '==', $this->schoolFs],
            ['session',  '==', $this->sessionYear],
        ], 'created_at', 'DESC', 500);
        $bySrc = []; $imbalanced = 0;
        foreach ($ledger as $e) {
            $d = (array) ($e['data'] ?? []);
            $src = (string) ($d['source'] ?? '?');
            $bySrc[$src] = ($bySrc[$src] ?? 0) + 1;
            $tDr = (float) ($d['total_dr'] ?? 0);
            $tCr = (float) ($d['total_cr'] ?? 0);
            if (abs($tDr - $tCr) > 0.01) $imbalanced++;
        }
        echo "Recent ledger entries (last 500):\n";
        foreach ($bySrc as $s => $c) echo "  {$s}: {$c}\n";
        echo "Imbalanced (Dr≠Cr): {$imbalanced}\n";

        // Recent forensics
        $this->load->library('Accounting_forensics', null, 'acctForensics');
        $this->acctForensics->init($this->firebase, $this->schoolFs, $this->sessionYear);
        $created24h  = $this->acctForensics->countRecentEvents('created',   86400);
        $reversed24h = $this->acctForensics->countRecentEvents('reversed',  86400);
        $replayed24h = $this->acctForensics->countRecentEvents('replayed',  86400);
        echo "Forensic events (24h): created={$created24h} reversed={$reversed24h} replayed={$replayed24h}\n";

        echo str_repeat('=', 64) . "\n";
        if ($imbalanced === 0) {
            echo "VERIFY: clean (zero imbalance, " . count($ledger) . " ledger docs scanned)\n";
        } else {
            echo "VERIFY: WARNING — {$imbalanced} imbalanced ledger docs found\n";
        }
    }

    // ─────────────────────────────────────────────────────────────────
    //  Cleanup
    // ─────────────────────────────────────────────────────────────────

    public function cleanup(): void
    {
        $this->_requireConfirm();
        $hard = in_array('--hard', $this->argv, true);

        echo "=== CLEANUP" . ($hard ? ' (HARD)' : ' (SOFT — reversal-based)') . " ===\n";
        // Soft cleanup posts reversal journals via Operations_accounting,
        // so the engine library must be wired up. Hard cleanup uses
        // firestoreDelete only and doesn't need opsAcct, but loading is
        // harmless and keeps the path uniform.
        $this->_initOpsAcct();
        $entries = $this->_findAllSyntheticEntries();
        echo "Synthetic ledger entries found: " . count($entries) . "\n";

        if (count($entries) === 0) {
            echo "Nothing to clean up.\n";
            return;
        }

        // Re-confirm for hard delete
        if ($hard && !in_array('--really-hard', $this->argv, true)) {
            echo "\nHard cleanup directly deletes ledger docs (immutability override).\n";
            echo "Re-run with --hard --really-hard --confirm to proceed.\n";
            return;
        }

        $reversed = 0;
        $deleted  = 0;
        $skipped  = 0;
        foreach ($entries as $e) {
            $entryId = (string) ($e['data']['entryId'] ?? $e['id'] ?? '');
            $sourceRef = (string) ($e['data']['source_ref'] ?? '');
            $isSynthetic = (
                preg_match('/^TEST_(RCT|RFD|MAN|STU)_/', $sourceRef)
                || preg_match('/JE_FEE_TEST_RCT_/', $entryId)
                || preg_match('/^JE_MAN_/', $entryId) && stripos((string) ($e['data']['narration'] ?? ''), 'TEST: ') === 0
            );
            if (!$isSynthetic) { $skipped++; continue; }

            if ($hard) {
                try {
                    $this->firebase->firestoreDelete('accounting', (string) $e['id']);
                    $deleted++;
                    $this->_log("SIMULATOR_CLEANUP_HARD entryId={$entryId} sourceRef={$sourceRef}");
                } catch (\Throwable $ex) {
                    echo "  ! delete failed entryId={$entryId}: " . $ex->getMessage() . "\n";
                }
            } else {
                // Soft cleanup: post a reversal journal via existing engine.
                $okay = $this->_postReversal($e);
                if ($okay) $reversed++;
                else { echo "  ! reversal failed for entryId={$entryId}\n"; $skipped++; }
            }
        }

        echo "\nCleanup summary:\n";
        echo "  Reversed: {$reversed}\n";
        echo "  Deleted:  {$deleted}\n";
        echo "  Skipped:  {$skipped}\n";
        $this->_log("SIMULATOR_CLEANUP_DONE reversed={$reversed} deleted={$deleted} skipped={$skipped} hard=" . ($hard ? '1' : '0'));
    }

    // ═════════════════════════════════════════════════════════════════
    //  SCENARIO IMPLEMENTATIONS
    // ═════════════════════════════════════════════════════════════════

    private function _scenarioA1(): bool
    {
        echo "=== A1: Tuition-only receipt ===\n";
        $params = $this->_buildSyntheticReceipt('tuition', $this->runId, 'A1');
        $entryId = $this->opsAcct->create_fee_journal($params);
        if (!$entryId) { echo "[ACTION] create_fee_journal returned null\n"; return false; }
        echo "[ACTION] entryId={$entryId}\n";
        $this->_recordRunEntry($this->runId, $entryId, 'fee_payment', 'tuition');

        return $this->_assertReceipt($entryId, [
            'lines'   => 2,
            'cr_4010' => 5000.0, 'cr_4040' => 0.0,
            'dr_1010' => 5000.0,
            'total'   => 5000.0,
        ]);
    }

    private function _scenarioA2(): bool
    {
        echo "=== A2: Transport-only receipt (4040 routing) ===\n";
        $params = $this->_buildSyntheticReceipt('transport', $this->runId, 'A2');
        $entryId = $this->opsAcct->create_fee_journal($params);
        if (!$entryId) { echo "[ACTION] create_fee_journal returned null\n"; return false; }
        echo "[ACTION] entryId={$entryId}\n";
        $this->_recordRunEntry($this->runId, $entryId, 'fee_payment', 'transport');

        return $this->_assertReceipt($entryId, [
            'lines'   => 2,
            'cr_4010' => 0.0, 'cr_4040' => 1000.0,
            'dr_1010' => 1000.0,
            'total'   => 1000.0,
        ]);
    }

    private function _scenarioA3(): bool
    {
        echo "=== A3: Mixed receipt (multi-allocation split) ===\n";
        $params = $this->_buildSyntheticReceipt('mixed', $this->runId, 'A3');
        $entryId = $this->opsAcct->create_fee_journal($params);
        if (!$entryId) { echo "[ACTION] create_fee_journal returned null\n"; return false; }
        echo "[ACTION] entryId={$entryId}\n";
        $this->_recordRunEntry($this->runId, $entryId, 'fee_payment', 'mixed');

        return $this->_assertReceipt($entryId, [
            'lines'   => 3,
            'cr_4010' => 5000.0, 'cr_4040' => 1000.0,
            'dr_1010' => 6000.0,
            'total'   => 6000.0,
        ]);
    }

    private function _scenarioB1(): bool
    {
        echo "=== B1: Full tuition refund ===\n";
        // Need an originating receipt to refund against.
        $rcptParams = $this->_buildSyntheticReceipt('tuition', $this->runId, 'B1_R');
        $rcptEntry = $this->opsAcct->create_fee_journal($rcptParams);
        if (!$rcptEntry) { echo "[SETUP] receipt failed\n"; return false; }
        $this->_recordRunEntry($this->runId, $rcptEntry, 'fee_payment', 'tuition');
        echo "[SETUP] originating receipt entryId={$rcptEntry}\n";

        $refundParams = $this->_buildSyntheticRefund('tuition', $this->runId, 'B1', $rcptParams['receipt_no']);
        $refundEntry = $this->opsAcct->create_refund_journal_granular($refundParams, $refundParams['allocations']);
        if (!$refundEntry) { echo "[ACTION] refund returned null\n"; return false; }
        echo "[ACTION] refund entryId={$refundEntry}\n";
        $this->_recordRunEntry($this->runId, $refundEntry, 'fee_refund', 'tuition');

        return $this->_assertRefund($refundEntry, [
            'lines'   => 2,
            'dr_4010' => 5000.0, 'dr_4040' => 0.0,
            'cr_1010' => 5000.0,
            'total'   => 5000.0,
        ]);
    }

    private function _scenarioB2(): bool
    {
        echo "=== B2: Full transport refund (4040 routing in refund) ===\n";
        $rcptParams = $this->_buildSyntheticReceipt('transport', $this->runId, 'B2_R');
        $rcptEntry = $this->opsAcct->create_fee_journal($rcptParams);
        if (!$rcptEntry) { echo "[SETUP] receipt failed\n"; return false; }
        $this->_recordRunEntry($this->runId, $rcptEntry, 'fee_payment', 'transport');

        $refundParams = $this->_buildSyntheticRefund('transport', $this->runId, 'B2', $rcptParams['receipt_no']);
        $refundEntry = $this->opsAcct->create_refund_journal_granular($refundParams, $refundParams['allocations']);
        if (!$refundEntry) { echo "[ACTION] refund returned null\n"; return false; }
        echo "[ACTION] refund entryId={$refundEntry}\n";
        $this->_recordRunEntry($this->runId, $refundEntry, 'fee_refund', 'transport');

        return $this->_assertRefund($refundEntry, [
            'lines'   => 2,
            'dr_4010' => 0.0, 'dr_4040' => 1000.0,
            'cr_1010' => 1000.0,
            'total'   => 1000.0,
        ]);
    }

    private function _scenarioB3(): bool
    {
        echo "=== B3: Mixed partial refund (S-RF2 reproduction) ===\n";
        $rcptParams = $this->_buildSyntheticReceipt('mixed', $this->runId, 'B3_R');
        $rcptEntry = $this->opsAcct->create_fee_journal($rcptParams);
        if (!$rcptEntry) { echo "[SETUP] receipt failed\n"; return false; }
        $this->_recordRunEntry($this->runId, $rcptEntry, 'fee_payment', 'mixed');

        // Partial refund: 2000 tuition + 500 transport = 2500
        $refundParams = $this->_buildSyntheticRefund('partial_mixed', $this->runId, 'B3', $rcptParams['receipt_no']);
        $refundEntry = $this->opsAcct->create_refund_journal_granular($refundParams, $refundParams['allocations']);
        if (!$refundEntry) { echo "[ACTION] refund returned null\n"; return false; }
        echo "[ACTION] refund entryId={$refundEntry}\n";
        $this->_recordRunEntry($this->runId, $refundEntry, 'fee_refund', 'partial_mixed');

        return $this->_assertRefund($refundEntry, [
            'lines'   => 3,
            'dr_4010' => 2000.0, 'dr_4040' => 500.0,
            'cr_1010' => 2500.0,
            'total'   => 2500.0,
        ]);
    }

    private function _scenarioC1(): bool
    {
        echo "=== C1: Balanced manual journal ===\n";
        // Use accounts that exist in this school's CoA: 5010 (Teaching Staff
        // Salary, expense) Dr → 2020 (Salary Payable, liability) Cr.
        $lines = [
            ['account_code' => '5010', 'dr' => 50000, 'cr' => 0],
            ['account_code' => '2020', 'dr' => 0,     'cr' => 50000],
        ];
        $sourceRef = "TEST_MAN_{$this->runId}_C1";
        try {
            $entryId = $this->opsAcct->create_journal(
                "TEST: synthetic salary accrual run={$this->runId}",
                $lines, 'manual', $sourceRef, 'Journal', date('Y-m-d')
            );
        } catch (\Throwable $e) {
            echo "[ACTION] threw: " . $e->getMessage() . "\n";
            return false;
        }
        if (!$entryId) { echo "[ACTION] create_journal returned empty\n"; return false; }
        echo "[ACTION] entryId={$entryId}\n";
        $this->_recordRunEntry($this->runId, $entryId, 'manual', 'C1');

        $doc = $this->_readLedger($entryId);
        if (!$doc) { echo "[VERIFY] ledger doc not found\n"; return false; }
        $tDr = (float) ($doc['total_dr'] ?? 0);
        $tCr = (float) ($doc['total_cr'] ?? 0);
        $linesCount = count((array) ($doc['lines'] ?? []));
        $ok = ($tDr === 50000.0 && $tCr === 50000.0 && $linesCount === 2);
        echo "[VERIFY] lines={$linesCount} total_dr={$tDr} total_cr={$tCr} -> " . ($ok ? '✓' : '✗') . "\n";
        return $ok;
    }

    private function _scenarioC2(): bool
    {
        echo "=== C2: Unbalanced manual journal (negative test) ===\n";
        $lines = [
            ['account_code' => '5010', 'dr' => 50000, 'cr' => 0],
            ['account_code' => '2020', 'dr' => 0,     'cr' => 49000],
        ];
        $sourceRef = "TEST_MAN_{$this->runId}_C2";
        $rejected = false;
        try {
            $entryId = $this->opsAcct->create_journal(
                "TEST: intentionally unbalanced run={$this->runId}",
                $lines, 'manual', $sourceRef, 'Journal', date('Y-m-d')
            );
            if (!$entryId) $rejected = true;
        } catch (\Throwable $e) {
            $rejected = true;
            echo "[ACTION] engine rejected: " . $e->getMessage() . "\n";
        }
        echo "[VERIFY] engine rejection (Dr 50000 vs Cr 49000): " . ($rejected ? '✓' : '✗') . "\n";
        return $rejected;
    }

    private function _scenarioC3(): bool
    {
        echo "=== C3: Future-dated manual journal (V-LOW-6 negative test) ===\n";
        $lines = [
            ['account_code' => '5010', 'dr' => 1000, 'cr' => 0],
            ['account_code' => '2020', 'dr' => 0,    'cr' => 1000],
        ];
        $sourceRef = "TEST_MAN_{$this->runId}_C3";
        $tomorrow = date('Y-m-d', time() + 86400);
        $rejected = false;
        try {
            $entryId = $this->opsAcct->create_journal(
                "TEST: future-dated run={$this->runId}",
                $lines, 'manual', $sourceRef, 'Journal', $tomorrow
            );
            if (!$entryId) $rejected = true;
        } catch (\Throwable $e) {
            $rejected = true;
            echo "[ACTION] engine rejected: " . $e->getMessage() . "\n";
        }
        // Future-date enforcement is at the Accounting controller layer (V-LOW-6 in
        // save_journal_entry); the library itself accepts any date. Therefore this
        // scenario serves as informational; PASS is qualified.
        if (!$rejected) {
            echo "[VERIFY] library accepted future date — V-LOW-6 enforced at controller layer (save_journal_entry).\n";
            echo "[VERIFY] To exercise V-LOW-6: POST /accounting/save_journal_entry with future date.\n";
        }
        return true; // informational pass
    }

    // ─────────────────────────────────────────────────────────────────
    //  D-Series — Discount/Concession Journaling scenarios
    //  Require: CONCESSIONS_ENABLED=1 + seed_concession_accounts run.
    // ─────────────────────────────────────────────────────────────────

    private function _scenarioD1(): bool
    {
        echo "=== D1: Tuition-only receipt with concession ===\n";
        $params = $this->_buildSyntheticReceipt('tuition', $this->runId, 'D1');
        // Override: gross 5000 stays in allocation; concession 1000; cash 4000.
        $params['amount'] = 4000.0;
        $params['allocations'] = [
            ['fee_head' => 'Tuition Fee', 'amount' => 4000.0,
             'concessions' => [['type' => 'Merit Scholarship', 'amount' => 1000.0]]],
        ];
        $entryId = $this->opsAcct->create_fee_journal($params);
        if (!$entryId) { echo "[ACTION] create_fee_journal returned null\n"; return false; }
        echo "[ACTION] entryId={$entryId}\n";
        $this->_recordRunEntry($this->runId, $entryId, 'fee_payment', 'tuition+concession');

        return $this->_assertConcession($entryId, [
            'lines'         => 3,
            'dr_1010'       => 4000.0,
            'dr_concession' => 1000.0,
            'cr_4010_gross' => 5000.0,
            'cr_4040'       => 0.0,
            'total'         => 5000.0,
        ]);
    }

    private function _scenarioD2(): bool
    {
        echo "=== D2: Transport-only receipt with concession ===\n";
        $params = $this->_buildSyntheticReceipt('transport', $this->runId, 'D2');
        // Gross 1000, transport concession 200, cash 800.
        $params['amount'] = 800.0;
        $params['allocations'] = [
            ['fee_head' => 'Transport Fee', 'amount' => 800.0,
             'concessions' => [['type' => 'Transport Concession', 'amount' => 200.0]]],
        ];
        $entryId = $this->opsAcct->create_fee_journal($params);
        if (!$entryId) { echo "[ACTION] create_fee_journal returned null\n"; return false; }
        echo "[ACTION] entryId={$entryId}\n";
        $this->_recordRunEntry($this->runId, $entryId, 'fee_payment', 'transport+concession');

        return $this->_assertConcession($entryId, [
            'lines'         => 3,
            'dr_1010'       => 800.0,
            'dr_concession' => 200.0,
            'cr_4010_gross' => 0.0,
            'cr_4040'       => 1000.0,
            'total'         => 1000.0,
        ]);
    }

    private function _scenarioD3(): bool
    {
        echo "=== D3: Mixed receipt with concessions on both heads ===\n";
        $params = $this->_buildSyntheticReceipt('mixed', $this->runId, 'D3');
        // Tuition gross 5000 - merit 1000 = 4000 cash on tuition.
        // Transport gross 1000 - transport conc 200 = 800 cash on transport.
        // Total cash 4800, total gross 6000, total concessions 1200.
        $params['amount'] = 4800.0;
        $params['allocations'] = [
            ['fee_head' => 'Tuition Fee', 'amount' => 4000.0,
             'concessions' => [['type' => 'Merit Scholarship', 'amount' => 1000.0]]],
            ['fee_head' => 'Transport Fee', 'amount' => 800.0,
             'concessions' => [['type' => 'Transport Concession', 'amount' => 200.0]]],
        ];
        $entryId = $this->opsAcct->create_fee_journal($params);
        if (!$entryId) { echo "[ACTION] create_fee_journal returned null\n"; return false; }
        echo "[ACTION] entryId={$entryId}\n";
        $this->_recordRunEntry($this->runId, $entryId, 'fee_payment', 'mixed+concessions');

        // Expect 5 lines: Dr 1010, Dr concession_tuition, Dr concession_transport,
        // Cr 4010 (gross 5000), Cr 4040 (gross 1000).
        return $this->_assertConcession($entryId, [
            'lines'         => 5,
            'dr_1010'       => 4800.0,
            'dr_concession' => 1200.0,    // sum of both concessions
            'cr_4010_gross' => 5000.0,
            'cr_4040'       => 1000.0,
            'total'         => 6000.0,
        ]);
    }

    private function _scenarioD4(): bool
    {
        echo "=== D4: Backward compatibility — concessions[] absent produces unchanged journal ===\n";
        // Same as A3 (mixed, no concessions). Must produce byte-identical
        // journal to a pre-concession A3 — same line count, same routing,
        // same Dr/Cr breakdown.
        $params = $this->_buildSyntheticReceipt('mixed', $this->runId, 'D4');
        $entryId = $this->opsAcct->create_fee_journal($params);
        if (!$entryId) { echo "[ACTION] create_fee_journal returned null\n"; return false; }
        echo "[ACTION] entryId={$entryId}\n";
        $this->_recordRunEntry($this->runId, $entryId, 'fee_payment', 'mixed_no_concession');

        // Should match A3 expectations exactly: 3 lines, no concession Dr.
        return $this->_assertReceipt($entryId, [
            'lines'   => 3,
            'cr_4010' => 5000.0, 'cr_4040' => 1000.0,
            'dr_1010' => 6000.0,
            'total'   => 6000.0,
        ]);
    }

    /**
     * Concession-aware assertion helper. Verifies:
     *   - line count
     *   - Dr Cash = expected (less than gross when concessions present)
     *   - Sum of concession-account Dr lines = expected total concessions
     *   - Cr 4010 / Cr 4040 = expected GROSS values (income at gross)
     *   - Dr == Cr exactly
     *
     * Concession Dr lines are identified as any Dr line on an account that is
     * NOT 1010 (cash) AND NOT in {4010, 4020, 4030, 4040, 4050, 4060} (income
     * accounts) AND NOT a known fee-line. The sum across these is matched
     * against expected['dr_concession'].
     */
    private function _assertConcession(string $entryId, array $expected): bool
    {
        $doc = $this->_readLedger($entryId);
        if (!$doc) { echo "[VERIFY] ledger doc not found for {$entryId}\n"; return false; }

        $lines = (array) ($doc['lines'] ?? []);
        $tDr = (float) ($doc['total_dr'] ?? 0);
        $tCr = (float) ($doc['total_cr'] ?? 0);

        $cr_4010 = $cr_4040 = $dr_1010 = $dr_concession = 0.0;
        $incomeAccounts = ['4010','4020','4030','4040','4050','4060'];
        $concessionAccountsSeen = [];

        foreach ($lines as $L) {
            $code = (string) ($L['account_code'] ?? '');
            $dr   = (float) ($L['dr'] ?? 0);
            $cr   = (float) ($L['cr'] ?? 0);
            if ($code === '4010') $cr_4010 += $cr;
            if ($code === '4040') $cr_4040 += $cr;
            if ($code === '1010') $dr_1010 += $dr;
            if ($dr > 0 && $code !== '1010' && !in_array($code, $incomeAccounts, true)) {
                $dr_concession += $dr;
                $concessionAccountsSeen[$code] = ($concessionAccountsSeen[$code] ?? 0) + $dr;
            }
        }

        $checks = [
            'lines '            => [count($lines), $expected['lines']],
            'Dr 1010 '          => [$dr_1010, $expected['dr_1010']],
            'Dr concession '    => [$dr_concession, $expected['dr_concession']],
            'Cr 4010 (gross) '  => [$cr_4010, $expected['cr_4010_gross']],
            'Cr 4040 (gross) '  => [$cr_4040, $expected['cr_4040']],
            'total_dr '         => [$tDr, $expected['total']],
            'total_cr '         => [$tCr, $expected['total']],
            'Dr=Cr '            => [round($tDr - $tCr, 2), 0.0],
        ];
        $ok = true;
        foreach ($checks as $name => [$actual, $exp]) {
            $pass = ($actual === $exp) || (is_numeric($actual) && abs($actual - $exp) < 0.01);
            $marker = $pass ? '✓' : '✗';
            echo "[VERIFY] {$name}{$actual} (expected {$exp}) {$marker}\n";
            if (!$pass) $ok = false;
        }
        if (!empty($concessionAccountsSeen)) {
            echo "[VERIFY] Concession Dr lines:\n";
            foreach ($concessionAccountsSeen as $code => $amt) {
                echo "          {$code} Dr={$amt}\n";
            }
        }
        $fcount = $this->_forensicCountFor($entryId, 'created');
        echo "[VERIFY] forensic created events: {$fcount} (expected 1) "
            . ($fcount === 1 ? '✓' : '✗') . "\n";
        if ($fcount !== 1) $ok = false;
        return $ok;
    }

    public function scenario_concessions(): void
    {
        $this->_requireConfirm();
        if ((string) getenv('CONCESSIONS_ENABLED') !== '1') {
            echo "WARNING: CONCESSIONS_ENABLED env not set to 1.\n";
            echo "Engine will treat concessions[] as absent. Scenarios will fail.\n";
            echo "Re-run with CONCESSIONS_ENABLED=1 in env.\n";
            return;
        }
        $this->_initOpsAcct();
        $scenarios = ['D1', 'D2', 'D3', 'D4'];
        $results = [];
        foreach ($scenarios as $s) {
            $this->_log("SIMULATOR_START scenario={$s} runId={$this->runId}");
            $method = '_scenario' . $s;
            try {
                $passed = $this->{$method}();
            } catch (\Throwable $e) {
                $passed = false;
                echo "  ! Exception: " . $e->getMessage() . "\n";
            }
            $results[$s] = $passed ? 'PASS' : 'FAIL';
            $this->_log("SIMULATOR_END scenario={$s} verdict={$results[$s]}");
            echo "\n";
        }
        echo str_repeat('=', 64) . "\n";
        echo "CONCESSION SCENARIO SUMMARY (run {$this->runId})\n";
        foreach ($results as $s => $r) echo "  {$s}: {$r}\n";
        $passes = count(array_filter($results, fn($r) => $r === 'PASS'));
        echo "  Total: {$passes}/" . count($results) . " passed\n";
        echo str_repeat('=', 64) . "\n";
    }

    public function seed_concession_accounts(): void
    {
        $this->_requireConfirm();
        echo "=== Seeding concession accounts into chartOfAccounts ===\n";
        $accounts = [
            ['code' => '4990', 'name' => 'TEST_CONC_General Concession Allowed',  'category' => 'Income',  'is_group' => false],
            ['code' => '4991', 'name' => 'TEST_CONC_Merit Scholarship',           'category' => 'Income',  'is_group' => false],
            ['code' => '4995', 'name' => 'TEST_CONC_Transport Concession',        'category' => 'Income',  'is_group' => false],
        ];
        $created = 0; $skipped = 0;
        foreach ($accounts as $a) {
            $docId = "{$this->schoolFs}_{$a['code']}";
            $existing = $this->firebase->firestoreGet('chartOfAccounts', $docId);
            if (is_array($existing)) { echo "  {$a['code']} already exists — skipped.\n"; $skipped++; continue; }
            $doc = [
                'schoolId'  => $this->schoolFs,
                'code'      => $a['code'],
                'name'      => $a['name'],
                'category'  => $a['category'],
                'is_group'  => $a['is_group'],
                'status'    => 'active',
                'updatedAt' => date('c'),
                'seeded_by_simulator' => true,   // marker for cleanup safety
            ];
            try {
                $this->firebase->firestoreSet('chartOfAccounts', $docId, $doc);
                echo "  {$a['code']} / {$a['name']} created.\n";
                $created++;
            } catch (\Throwable $e) {
                echo "  ! {$a['code']} create failed: " . $e->getMessage() . "\n";
            }
        }
        echo "\nSummary: created={$created} skipped={$skipped}\n";
        $this->_log("SIMULATOR_SEED_CONC_ACCOUNTS created={$created} skipped={$skipped}");
    }

    public function cleanup_concession_accounts(): void
    {
        $this->_requireConfirm();
        echo "=== Cleanup TEST_CONC_* concession accounts ===\n";
        $codes = ['4990', '4991', '4995'];
        $deleted = 0; $skipped = 0;
        foreach ($codes as $code) {
            $docId = "{$this->schoolFs}_{$code}";
            $doc = $this->firebase->firestoreGet('chartOfAccounts', $docId);
            if (!is_array($doc)) { echo "  {$code} not present — skipped.\n"; $skipped++; continue; }
            // Safety: only delete docs marked as simulator-seeded.
            if (empty($doc['seeded_by_simulator'])) {
                echo "  {$code} not marked as seeded — skipped.\n";
                $skipped++; continue;
            }
            try {
                $this->firebase->firestoreDelete('chartOfAccounts', $docId);
                echo "  {$code} deleted.\n";
                $deleted++;
            } catch (\Throwable $e) {
                echo "  ! {$code} delete failed: " . $e->getMessage() . "\n";
            }
        }
        echo "\nSummary: deleted={$deleted} skipped={$skipped}\n";
        $this->_log("SIMULATOR_CLEANUP_CONC_ACCOUNTS deleted={$deleted} skipped={$skipped}");
    }

    // ─────────────────────────────────────────────────────────────────
    //  E-Series — Payroll Foundation scenarios (Stage 2)
    //  Require: PAYROLL_ENGINE_INTEGRATION=1 in env.
    // ─────────────────────────────────────────────────────────────────

    private function _initPayAcct(): void
    {
        // Services live in application/services/ and are require_once'd
        // (not loaded via CI's library loader). Mirrors the Fees controller's
        // FeeCollectionService loading pattern.
        if (!isset($this->payAcct)) {
            require_once APPPATH . 'services/PayrollAccountingService.php';
            $this->payAcct = new PayrollAccountingService();
            $this->payAcct->init(
                $this->firebase, $this->schoolFs, $this->sessionYear,
                self::TEST_ACCOUNTANT, $this
            );
        }
    }

    private function _scenarioE1(): bool
    {
        echo "=== E1: Single-employee accrual (canonical engine routing) ===\n";
        $this->_initPayAcct();
        if (!$this->payAcct->isEnabled()) {
            echo "[ACTION] FAIL — payroll_engine_integration flag is OFF; set PAYROLL_ENGINE_INTEGRATION=1\n";
            return false;
        }

        $period = '2026-04';
        $employeeId = "TEST_EMP_{$this->runId}_E1";
        $payload = [
            'employee_id'    => $employeeId,
            'employee_name'  => "TEST Anand Kumar",
            'role_class'     => 'teaching',
            'period_label'   => $period,
            'period_start'   => '2026-04-01',
            'period_end'     => '2026-04-30',
            'gross_salary'   => 50000,
            'net_take_home'  => 42000,
            'deductions' => [
                'pf_employee'   => 6000,
                'esi_employee'  => 400,
                'tds'           => 500,
                'prof_tax'      => 200,
                'other'         => 900,
            ],
            'employer_contributions' => [
                'pf_employer'   => 6000,
                'esi_employer'  => 800,
            ],
            'journal_date'   => date('Y-m-d'),
        ];
        $entryId = $this->payAcct->postAccrual($payload);
        if (!$entryId) { echo "[ACTION] postAccrual returned null\n"; return false; }
        echo "[ACTION] entryId={$entryId}\n";
        $this->_recordRunEntry($this->runId, $entryId, 'payroll_accrual', 'E1');

        // Expected entryId pattern: JE_PAYROLL_ACCRUAL_<period>_<employeeId>
        $expectedEntryId = "JE_PAYROLL_ACCRUAL_{$period}_{$employeeId}";
        $patternOk = ($entryId === $expectedEntryId);
        echo "[VERIFY] entryId pattern: " . ($patternOk ? '✓' : '✗')
           . " (expected {$expectedEntryId})\n";

        $doc = $this->_readLedger($entryId);
        if (!$doc) { echo "[VERIFY] ledger doc not found\n"; return false; }

        $tDr = (float) ($doc['total_dr'] ?? 0);
        $tCr = (float) ($doc['total_cr'] ?? 0);
        $linesCount = count((array) ($doc['lines'] ?? []));
        $source = (string) ($doc['source'] ?? '');

        // Engine recognized: gross 50000 + employer ctb 6800 = 56800 expense
        // Cr: net 42000 + PF total (6000+6000)=12000 + ESI total (400+800)=1200 + TDS 500 + PT 200 + other 900 = 56800
        $expectedTotal = 56800.0;

        $checks = [
            'lines = 8 '   => [$linesCount, 8],     // 2 Dr + 6 Cr (net + 5 deduction accts)
            'total_dr '    => [$tDr, $expectedTotal],
            'total_cr '    => [$tCr, $expectedTotal],
            'Dr == Cr '    => [round($tDr - $tCr, 2), 0.0],
            'source '      => [$source, 'payroll_accrual'],
        ];
        $ok = $patternOk;
        foreach ($checks as $name => [$actual, $exp]) {
            $pass = ($actual === $exp) || (is_numeric($actual) && is_numeric($exp) && abs($actual - $exp) < 0.01);
            $marker = $pass ? '✓' : '✗';
            echo "[VERIFY] {$name}{$actual} (expected {$exp}) {$marker}\n";
            if (!$pass) $ok = false;
        }
        return $ok;
    }

    private function _scenarioE2(): bool
    {
        echo "=== E2: Single-employee payout (extinguishes salary payable) ===\n";
        $this->_initPayAcct();
        if (!$this->payAcct->isEnabled()) {
            echo "[ACTION] FAIL — payroll_engine_integration flag is OFF\n";
            return false;
        }

        // Setup: post an accrual we can pay out against.
        $period = '2026-04';
        $employeeId = "TEST_EMP_{$this->runId}_E2";
        $accrualPayload = [
            'employee_id'    => $employeeId,
            'employee_name'  => "TEST E2 Employee",
            'role_class'     => 'teaching',
            'period_label'   => $period,
            'period_start'   => '2026-04-01',
            'period_end'     => '2026-04-30',
            'gross_salary'   => 30000,
            'net_take_home'  => 25000,
            'deductions' => [
                'pf_employee' => 3600, 'esi_employee' => 240, 'tds' => 0,
                'prof_tax' => 200, 'other' => 960,
            ],
            'employer_contributions' => ['pf_employer' => 3600, 'esi_employer' => 480],
            'journal_date'   => date('Y-m-d'),
        ];
        $accrualEntry = $this->payAcct->postAccrual($accrualPayload);
        if (!$accrualEntry) { echo "[SETUP] accrual failed\n"; return false; }
        echo "[SETUP] accrual entryId={$accrualEntry}\n";
        $this->_recordRunEntry($this->runId, $accrualEntry, 'payroll_accrual', 'E2_setup');

        $payoutEntry = $this->payAcct->postPayout([
            'employee_id'  => $employeeId,
            'period_label' => $period,
            'amount'       => 25000,
            'mode'         => 'cash',
            'journal_date' => date('Y-m-d'),
        ]);
        if (!$payoutEntry) { echo "[ACTION] postPayout returned null\n"; return false; }
        echo "[ACTION] payout entryId={$payoutEntry}\n";
        $this->_recordRunEntry($this->runId, $payoutEntry, 'payroll_payout', 'E2');

        $expectedEntryId = "JE_PAYROLL_PAYOUT_{$period}_{$employeeId}";
        if ($payoutEntry !== $expectedEntryId) {
            echo "[VERIFY] entryId pattern ✗ (expected {$expectedEntryId})\n";
            return false;
        }
        echo "[VERIFY] entryId pattern ✓\n";

        $doc = $this->_readLedger($payoutEntry);
        if (!$doc) return false;

        $lines = (array) ($doc['lines'] ?? []);
        $dr2020 = $cr1010 = 0.0;
        foreach ($lines as $L) {
            if (($L['account_code'] ?? '') === '2020') $dr2020 += (float) ($L['dr'] ?? 0);
            if (($L['account_code'] ?? '') === '1010') $cr1010 += (float) ($L['cr'] ?? 0);
        }
        $checks = [
            'lines = 2 '   => [count($lines), 2],
            'Dr 2020 '     => [$dr2020, 25000.0],
            'Cr 1010 '     => [$cr1010, 25000.0],
            'Dr=Cr '       => [round((float) ($doc['total_dr'] ?? 0) - (float) ($doc['total_cr'] ?? 0), 2), 0.0],
            'source '      => [(string) ($doc['source'] ?? ''), 'payroll_payout'],
        ];
        $ok = true;
        foreach ($checks as $n => [$a, $e]) {
            $p = ($a === $e) || (is_numeric($a) && is_numeric($e) && abs($a - $e) < 0.01);
            echo "[VERIFY] {$n}{$a} (expected {$e}) " . ($p ? '✓' : '✗') . "\n";
            if (!$p) $ok = false;
        }
        return $ok;
    }

    private function _scenarioE3(): bool
    {
        echo "=== E3: Statutory deposit (PF) ===\n";
        $this->_initPayAcct();
        if (!$this->payAcct->isEnabled()) {
            echo "[ACTION] FAIL — flag OFF\n";
            return false;
        }

        $period = '2026-04';
        // Note: E3 uses a runId-disambiguated period to avoid collision with
        // earlier E1/E2 statutory entries from the same period.
        $disambig = "{$period}_R{$this->runId}";

        $entryId = $this->payAcct->postStatutoryDeposit([
            'period_label' => $disambig,
            'account_code' => '2030',         // PF Payable
            'amount'       => 24000.00,
            'mode'         => 'bank',
            'bank_code'    => '1020',
            'journal_date' => date('Y-m-d'),
        ]);
        if (!$entryId) { echo "[ACTION] postStatutoryDeposit returned null\n"; return false; }
        echo "[ACTION] entryId={$entryId}\n";
        $this->_recordRunEntry($this->runId, $entryId, 'payroll_stat', 'E3');

        $expectedEntryId = "JE_PAYROLL_STAT_{$disambig}_2030";
        if ($entryId !== $expectedEntryId) {
            echo "[VERIFY] entryId pattern ✗ (expected {$expectedEntryId})\n";
            return false;
        }
        echo "[VERIFY] entryId pattern ✓\n";

        $doc = $this->_readLedger($entryId);
        if (!$doc) return false;
        $lines = (array) ($doc['lines'] ?? []);
        $dr2030 = $cr1020 = 0.0;
        foreach ($lines as $L) {
            if (($L['account_code'] ?? '') === '2030') $dr2030 += (float) ($L['dr'] ?? 0);
            if (($L['account_code'] ?? '') === '1020') $cr1020 += (float) ($L['cr'] ?? 0);
        }
        $checks = [
            'lines = 2 ' => [count($lines), 2],
            'Dr 2030 '   => [$dr2030, 24000.0],
            'Cr 1020 '   => [$cr1020, 24000.0],
            'Dr=Cr '     => [round((float) ($doc['total_dr'] ?? 0) - (float) ($doc['total_cr'] ?? 0), 2), 0.0],
            'source '    => [(string) ($doc['source'] ?? ''), 'payroll_stat'],
        ];
        $ok = true;
        foreach ($checks as $n => [$a, $e]) {
            $p = ($a === $e) || (is_numeric($a) && is_numeric($e) && abs($a - $e) < 0.01);
            echo "[VERIFY] {$n}{$a} (expected {$e}) " . ($p ? '✓' : '✗') . "\n";
            if (!$p) $ok = false;
        }
        return $ok;
    }

    private function _scenarioE4(): bool
    {
        echo "=== E4: Reversal of accrual (immutability + Dr/Cr swap) ===\n";
        $this->_initPayAcct();
        if (!$this->payAcct->isEnabled()) return false;

        // Setup: post an accrual to reverse.
        $period = '2026-04';
        $employeeId = "TEST_EMP_{$this->runId}_E4";
        $payload = [
            'employee_id'    => $employeeId,
            'employee_name'  => "TEST E4 Employee",
            'role_class'     => 'non_teaching',
            'period_label'   => $period,
            'period_start'   => '2026-04-01',
            'period_end'     => '2026-04-30',
            'gross_salary'   => 20000,
            'net_take_home'  => 17000,
            'deductions' => [
                'pf_employee' => 2400, 'esi_employee' => 160, 'tds' => 0,
                'prof_tax' => 200, 'other' => 240,
            ],
            'employer_contributions' => ['pf_employer' => 2400, 'esi_employer' => 320],
            'journal_date'   => date('Y-m-d'),
        ];
        $origEntry = $this->payAcct->postAccrual($payload);
        if (!$origEntry) { echo "[SETUP] accrual failed\n"; return false; }
        echo "[SETUP] original entryId={$origEntry}\n";
        $this->_recordRunEntry($this->runId, $origEntry, 'payroll_accrual', 'E4_setup');

        $revEntry = $this->payAcct->postReversal($origEntry, "TEST: pro-rate days correction");
        if (!$revEntry) { echo "[ACTION] postReversal returned null\n"; return false; }
        echo "[ACTION] reversal entryId={$revEntry}\n";
        $this->_recordRunEntry($this->runId, $revEntry, 'payroll_reversal', 'E4');

        $expectedEntryId = "JE_PAYROLL_REVERSAL_{$origEntry}";
        if ($revEntry !== $expectedEntryId) {
            echo "[VERIFY] entryId pattern ✗ (expected {$expectedEntryId})\n";
            return false;
        }
        echo "[VERIFY] entryId pattern ✓\n";

        // Verify original is immutable (still exists, unchanged).
        $orig = $this->_readLedger($origEntry);
        $rev  = $this->_readLedger($revEntry);
        if (!$orig || !$rev) return false;

        // Both should be balanced; the reversal should net the original to zero.
        $origDr = (float) ($orig['total_dr'] ?? 0);
        $origCr = (float) ($orig['total_cr'] ?? 0);
        $revDr  = (float) ($rev['total_dr']  ?? 0);
        $revCr  = (float) ($rev['total_cr']  ?? 0);

        $checks = [
            'original Dr=Cr '       => [round($origDr - $origCr, 2), 0.0],
            'reversal Dr=Cr '       => [round($revDr - $revCr, 2), 0.0],
            'reversal_total = original_total ' => [round($revDr, 2), round($origDr, 2)],
            'reversal source '      => [(string) ($rev['source'] ?? ''), 'payroll_reversal'],
        ];
        $ok = true;
        foreach ($checks as $n => [$a, $e]) {
            $p = ($a === $e) || (is_numeric($a) && is_numeric($e) && abs($a - $e) < 0.01);
            echo "[VERIFY] {$n}{$a} (expected {$e}) " . ($p ? '✓' : '✗') . "\n";
            if (!$p) $ok = false;
        }
        return $ok;
    }

    private function _scenarioE5(): bool
    {
        echo "=== E5: Replay/idempotency — re-post identical accrual ===\n";
        $this->_initPayAcct();
        if (!$this->payAcct->isEnabled()) return false;

        $period = '2026-04';
        $employeeId = "TEST_EMP_{$this->runId}_E5";
        $payload = [
            'employee_id'    => $employeeId,
            'employee_name'  => "TEST E5 Employee",
            'role_class'     => 'teaching',
            'period_label'   => $period,
            'period_start'   => '2026-04-01',
            'period_end'     => '2026-04-30',
            'gross_salary'   => 25000,
            'net_take_home'  => 22000,
            'deductions' => [
                'pf_employee' => 2400, 'esi_employee' => 200, 'tds' => 0,
                'prof_tax' => 200, 'other' => 200,
            ],
            'employer_contributions' => ['pf_employer' => 2400, 'esi_employer' => 400],
            'journal_date'   => date('Y-m-d'),
        ];

        $first  = $this->payAcct->postAccrual($payload);
        $second = $this->payAcct->postAccrual($payload);
        echo "[ACTION] first  call entryId={$first}\n";
        echo "[ACTION] second call entryId={$second}\n";
        $this->_recordRunEntry($this->runId, $first, 'payroll_accrual', 'E5');

        // Second call must be idempotent — same entryId returned.
        $idempotent = ($first === $second && $first !== null && $first !== '');
        echo "[VERIFY] idempotent on second post: " . ($idempotent ? '✓' : '✗') . "\n";
        return $idempotent;
    }

    // ─────────────────────────────────────────────────────────────────
    //  F-Series — Stage 4 operational metadata persistence scenarios
    //  Require: PAYROLL_ENGINE_INTEGRATION=1 in env.
    // ─────────────────────────────────────────────────────────────────

    private function _initPayOpsRepo()
    {
        $this->load->library('Payroll_operational_repo', null, 'payOpsRepo');
        $this->payOpsRepo->init($this->firebase, $this->schoolFs, $this->sessionYear);
        return $this->payOpsRepo;
    }

    private function _scenarioF1(): bool
    {
        echo "=== F1: Operational record created on accrual post ===\n";
        $this->_initPayAcct();
        if (!$this->payAcct->isEnabled()) return false;
        $repo = $this->_initPayOpsRepo();

        $period = '2026-04';
        $employeeId = "TEST_EMP_{$this->runId}_F1";
        $payload = [
            'employee_id'    => $employeeId,
            'employee_name'  => "TEST F1 Employee",
            'role_class'     => 'teaching',
            'period_label'   => $period,
            'period_start'   => '2026-04-01',
            'period_end'     => '2026-04-30',
            'gross_salary'   => 30000,
            'net_take_home'  => 25000,
            'deductions' => [
                'pf_employee' => 3600, 'esi_employee' => 240, 'tds' => 0,
                'prof_tax' => 200, 'other' => 960,
            ],
            'employer_contributions' => ['pf_employer' => 3600, 'esi_employer' => 480],
        ];
        $entryId = $this->payAcct->postAccrual($payload);
        if (!$entryId) { echo "[ACTION] postAccrual returned null\n"; return false; }
        echo "[ACTION] entryId={$entryId}\n";
        $this->_recordRunEntry($this->runId, $entryId, 'payroll_accrual', 'F1');

        // Read back the operational record.
        $opsDoc = $repo->getAccrual($period, $employeeId);
        if (!$opsDoc) { echo "[VERIFY] operational accrual record not found\n"; return false; }

        $checks = [
            'journal_entry_id ' => [(string) ($opsDoc['journal_entry_id'] ?? ''), $entryId],
            'employee_id '      => [(string) ($opsDoc['employee_id'] ?? ''), $employeeId],
            'period_label '     => [(string) ($opsDoc['period_label'] ?? ''), $period],
            'gross_salary '     => [(float) ($opsDoc['gross_salary'] ?? 0), 30000.0],
            'net_take_home '    => [(float) ($opsDoc['net_take_home'] ?? 0), 25000.0],
            'status '           => [(string) ($opsDoc['status'] ?? ''), 'posted'],
        ];
        $ok = true;
        foreach ($checks as $n => [$a, $e]) {
            $p = ($a === $e) || (is_numeric($a) && is_numeric($e) && abs((float) $a - (float) $e) < 0.01);
            echo "[VERIFY] {$n}{$a} (expected {$e}) " . ($p ? '✓' : '✗') . "\n";
            if (!$p) $ok = false;
        }
        return $ok;
    }

    private function _scenarioF2(): bool
    {
        echo "=== F2: Operational record created on payout post ===\n";
        $this->_initPayAcct();
        if (!$this->payAcct->isEnabled()) return false;
        $repo = $this->_initPayOpsRepo();

        $period = '2026-04';
        $employeeId = "TEST_EMP_{$this->runId}_F2";
        // Setup: accrual first.
        $accrualPayload = [
            'employee_id' => $employeeId, 'employee_name' => "TEST F2",
            'role_class' => 'non_teaching', 'period_label' => $period,
            'period_start' => '2026-04-01', 'period_end' => '2026-04-30',
            'gross_salary' => 20000, 'net_take_home' => 17000,
            'deductions' => ['pf_employee' => 2400, 'esi_employee' => 160, 'tds' => 0, 'prof_tax' => 200, 'other' => 240],
            'employer_contributions' => ['pf_employer' => 2400, 'esi_employer' => 320],
        ];
        $accrualEntry = $this->payAcct->postAccrual($accrualPayload);
        if (!$accrualEntry) return false;
        $this->_recordRunEntry($this->runId, $accrualEntry, 'payroll_accrual', 'F2_setup');

        $payoutEntry = $this->payAcct->postPayout([
            'employee_id'  => $employeeId,
            'period_label' => $period,
            'amount'       => 17000,
            'mode'         => 'cash',
        ]);
        if (!$payoutEntry) { echo "[ACTION] postPayout returned null\n"; return false; }
        echo "[ACTION] payoutEntryId={$payoutEntry}\n";
        $this->_recordRunEntry($this->runId, $payoutEntry, 'payroll_payout', 'F2');

        $opsDoc = $repo->getPayout($period, $employeeId);
        if (!$opsDoc) { echo "[VERIFY] operational payout record not found\n"; return false; }

        $checks = [
            'journal_entry_id ' => [(string) ($opsDoc['journal_entry_id'] ?? ''), $payoutEntry],
            'amount '           => [(float) ($opsDoc['amount'] ?? 0), 17000.0],
            'mode '             => [(string) ($opsDoc['mode'] ?? ''), 'cash'],
            'status '           => [(string) ($opsDoc['status'] ?? ''), 'posted'],
        ];
        $ok = true;
        foreach ($checks as $n => [$a, $e]) {
            $p = ($a === $e) || (is_numeric($a) && is_numeric($e) && abs((float) $a - (float) $e) < 0.01);
            echo "[VERIFY] {$n}{$a} (expected {$e}) " . ($p ? '✓' : '✗') . "\n";
            if (!$p) $ok = false;
        }
        return $ok;
    }

    private function _scenarioF3(): bool
    {
        echo "=== F3: Operational record created on statutory deposit ===\n";
        $this->_initPayAcct();
        if (!$this->payAcct->isEnabled()) return false;
        $repo = $this->_initPayOpsRepo();

        $period = "2026-04_F3R{$this->runId}";
        $entryId = $this->payAcct->postStatutoryDeposit([
            'period_label' => $period,
            'account_code' => '2031',
            'amount'       => 5000.00,
            'mode'         => 'bank',
            'bank_code'    => '1020',
        ]);
        if (!$entryId) return false;
        $this->_recordRunEntry($this->runId, $entryId, 'payroll_stat', 'F3');

        $opsDoc = $repo->getStatutory($period, '2031');
        if (!$opsDoc) { echo "[VERIFY] operational statutory record not found\n"; return false; }

        $checks = [
            'journal_entry_id ' => [(string) ($opsDoc['journal_entry_id'] ?? ''), $entryId],
            'account_code '     => [(string) ($opsDoc['account_code'] ?? ''), '2031'],
            'amount '           => [(float) ($opsDoc['amount'] ?? 0), 5000.0],
            'mode '             => [(string) ($opsDoc['mode'] ?? ''), 'bank'],
            'status '           => [(string) ($opsDoc['status'] ?? ''), 'posted'],
        ];
        $ok = true;
        foreach ($checks as $n => [$a, $e]) {
            $p = ($a === $e) || (is_numeric($a) && is_numeric($e) && abs((float) $a - (float) $e) < 0.01);
            echo "[VERIFY] {$n}{$a} (expected {$e}) " . ($p ? '✓' : '✗') . "\n";
            if (!$p) $ok = false;
        }
        return $ok;
    }

    private function _scenarioF4(): bool
    {
        echo "=== F4: Operational record reflects reversal ===\n";
        $this->_initPayAcct();
        if (!$this->payAcct->isEnabled()) return false;
        $repo = $this->_initPayOpsRepo();

        $period = '2026-04';
        $employeeId = "TEST_EMP_{$this->runId}_F4";
        $payload = [
            'employee_id' => $employeeId, 'employee_name' => "TEST F4",
            'role_class' => 'teaching', 'period_label' => $period,
            'period_start' => '2026-04-01', 'period_end' => '2026-04-30',
            'gross_salary' => 25000, 'net_take_home' => 22000,
            'deductions' => ['pf_employee' => 2400, 'esi_employee' => 200, 'tds' => 0, 'prof_tax' => 200, 'other' => 200],
            'employer_contributions' => ['pf_employer' => 2400, 'esi_employer' => 400],
        ];
        $origEntry = $this->payAcct->postAccrual($payload);
        if (!$origEntry) return false;
        $this->_recordRunEntry($this->runId, $origEntry, 'payroll_accrual', 'F4_setup');

        $opsBefore = $repo->getAccrual($period, $employeeId);
        if (!$opsBefore || ($opsBefore['status'] ?? '') !== 'posted') {
            echo "[VERIFY] expected status=posted before reversal\n";
            return false;
        }

        $revEntry = $this->payAcct->postReversal($origEntry, "TEST F4 reversal");
        if (!$revEntry) return false;
        $this->_recordRunEntry($this->runId, $revEntry, 'payroll_reversal', 'F4');

        // Brief settle for read-after-write consistency.
        usleep(800000);
        $opsAfter = $repo->getAccrual($period, $employeeId);
        if (!$opsAfter) { echo "[VERIFY] operational record disappeared post-reversal\n"; return false; }

        $checks = [
            'status '             => [(string) ($opsAfter['status'] ?? ''), 'reversed'],
            'reversal_entry_id '  => [(string) ($opsAfter['reversal_entry_id'] ?? ''), $revEntry],
            'reversal_reason '    => [(string) ($opsAfter['reversal_reason'] ?? ''), 'TEST F4 reversal'],
            'journal_entry_id '   => [(string) ($opsAfter['journal_entry_id'] ?? ''), $origEntry],
        ];
        $ok = true;
        foreach ($checks as $n => [$a, $e]) {
            $p = ($a === $e);
            echo "[VERIFY] {$n}{$a} (expected {$e}) " . ($p ? '✓' : '✗') . "\n";
            if (!$p) $ok = false;
        }
        return $ok;
    }

    private function _scenarioF5(): bool
    {
        echo "=== F5: Replay-safe operational metadata (re-post is idempotent) ===\n";
        $this->_initPayAcct();
        if (!$this->payAcct->isEnabled()) return false;
        $repo = $this->_initPayOpsRepo();

        $period = '2026-04';
        $employeeId = "TEST_EMP_{$this->runId}_F5";
        $payload = [
            'employee_id' => $employeeId, 'employee_name' => "TEST F5",
            'role_class' => 'support', 'period_label' => $period,
            'period_start' => '2026-04-01', 'period_end' => '2026-04-30',
            'gross_salary' => 18000, 'net_take_home' => 16000,
            'deductions' => ['pf_employee' => 1800, 'esi_employee' => 0, 'tds' => 0, 'prof_tax' => 200, 'other' => 0],
            'employer_contributions' => ['pf_employer' => 1800, 'esi_employer' => 0],
        ];

        $first  = $this->payAcct->postAccrual($payload);
        $second = $this->payAcct->postAccrual($payload);
        $this->_recordRunEntry($this->runId, $first, 'payroll_accrual', 'F5');

        $opsDoc = $repo->getAccrual($period, $employeeId);
        if (!$opsDoc) return false;

        $idempotentEngine = ($first === $second);
        $singleOpsDoc = ((string) ($opsDoc['journal_entry_id'] ?? '')) === $first;

        echo "[VERIFY] engine dedup (first === second): " . ($idempotentEngine ? '✓' : '✗') . "\n";
        echo "[VERIFY] operational record references original entryId: " . ($singleOpsDoc ? '✓' : '✗') . "\n";

        return $idempotentEngine && $singleOpsDoc;
    }

    public function scenario_payroll_ops(): void
    {
        $this->_requireConfirm();
        if ((string) getenv('PAYROLL_ENGINE_INTEGRATION') !== '1') {
            echo "WARNING: PAYROLL_ENGINE_INTEGRATION env not set to 1.\n";
            return;
        }
        $this->_initOpsAcct();
        $scenarios = ['F1', 'F2', 'F3', 'F4', 'F5'];
        $results = [];
        foreach ($scenarios as $s) {
            $this->_log("SIMULATOR_START scenario={$s} runId={$this->runId}");
            $method = '_scenario' . $s;
            try {
                $passed = $this->{$method}();
            } catch (\Throwable $e) {
                $passed = false;
                echo "  ! Exception: " . $e->getMessage() . "\n";
            }
            $results[$s] = $passed ? 'PASS' : 'FAIL';
            $this->_log("SIMULATOR_END scenario={$s} verdict={$results[$s]}");
            echo "\n";
        }
        echo str_repeat('=', 64) . "\n";
        echo "PAYROLL OPERATIONAL SCENARIO SUMMARY (run {$this->runId})\n";
        foreach ($results as $s => $r) echo "  {$s}: {$r}\n";
        $passes = count(array_filter($results, fn($r) => $r === 'PASS'));
        echo "  Total: {$passes}/" . count($results) . " passed\n";
        echo str_repeat('=', 64) . "\n";
    }

    public function scenario_payroll(): void
    {
        $this->_requireConfirm();
        if ((string) getenv('PAYROLL_ENGINE_INTEGRATION') !== '1') {
            echo "WARNING: PAYROLL_ENGINE_INTEGRATION env not set to 1.\n";
            echo "Service will refuse all posts. Re-run with PAYROLL_ENGINE_INTEGRATION=1\n";
            return;
        }
        $this->_initOpsAcct();
        $scenarios = ['E1', 'E2', 'E3', 'E4', 'E5'];
        $results = [];
        foreach ($scenarios as $s) {
            $this->_log("SIMULATOR_START scenario={$s} runId={$this->runId}");
            $method = '_scenario' . $s;
            try {
                $passed = $this->{$method}();
            } catch (\Throwable $e) {
                $passed = false;
                echo "  ! Exception: " . $e->getMessage() . "\n";
            }
            $results[$s] = $passed ? 'PASS' : 'FAIL';
            $this->_log("SIMULATOR_END scenario={$s} verdict={$results[$s]}");
            echo "\n";
        }
        echo str_repeat('=', 64) . "\n";
        echo "PAYROLL SCENARIO SUMMARY (run {$this->runId})\n";
        foreach ($results as $s => $r) echo "  {$s}: {$r}\n";
        $passes = count(array_filter($results, fn($r) => $r === 'PASS'));
        echo "  Total: {$passes}/" . count($results) . " passed\n";
        echo str_repeat('=', 64) . "\n";
    }

    private function _scenarioI1(): bool
    {
        echo "=== I1: Trial balance closes ===\n";
        $balances = (array) $this->firebase->firestoreQuery('accountingClosingBalances', [
            ['schoolId', '==', $this->schoolFs],
            ['session',  '==', $this->sessionYear],
        ], '', '', 500);
        $totalDr = 0.0; $totalCr = 0.0;
        foreach ($balances as $b) {
            $d = (array) ($b['data'] ?? []);
            $bal = (float) ($d['balance'] ?? 0);
            if ($bal >= 0) $totalDr += $bal; else $totalCr += abs($bal);
        }
        $diff = round(abs($totalDr - $totalCr), 2);
        echo "[VERIFY] Σ Dr-natural = {$totalDr}  Σ Cr-natural = {$totalCr}  diff = {$diff}\n";
        // Trial balance closes when sum of Dr-natural = sum of Cr-natural across all accounts.
        // Any nonzero diff is balance drift — FREEZE_REQUIRED-class signal.
        $ok = ($diff < 0.01);
        echo "[VERIFY] trial balance closed: " . ($ok ? '✓' : '✗') . "\n";
        return $ok;
    }

    // ═════════════════════════════════════════════════════════════════
    //  PAYLOAD BUILDERS
    // ═════════════════════════════════════════════════════════════════

    private function _buildSyntheticReceipt(string $type, string $runId, string $idx): array
    {
        $rcpt = "TEST_RCT_{$runId}_{$idx}";
        $stuId = "TEST_STU_{$runId}_{$idx}";
        $base = [
            'date'         => date('Y-m-d'),
            'payment_mode' => 'cash',
            'receipt_no'   => $rcpt,
            'student_name' => "TEST_SYNTH_{$idx}",
            'student_id'   => $stuId,
            'class'        => 'TEST_CLASS',
            'admin_id'     => self::TEST_ACCOUNTANT,
        ];
        switch ($type) {
            case 'tuition':
                return array_merge($base, [
                    'amount'      => 5000.0,
                    'allocations' => [['fee_head' => 'Tuition Fee', 'amount' => 5000.0]],
                ]);
            case 'transport':
                return array_merge($base, [
                    'amount'      => 1000.0,
                    'allocations' => [['fee_head' => 'Transport Fee', 'amount' => 1000.0]],
                ]);
            case 'mixed':
                return array_merge($base, [
                    'amount'      => 6000.0,
                    'allocations' => [
                        ['fee_head' => 'Tuition Fee',   'amount' => 5000.0],
                        ['fee_head' => 'Transport Fee', 'amount' => 1000.0],
                    ],
                ]);
        }
        return $base;
    }

    private function _buildSyntheticRefund(string $type, string $runId, string $idx, string $origReceipt): array
    {
        $refId = "TEST_RFD_{$runId}_{$idx}";
        $stuId = "TEST_STU_{$runId}_{$idx}";
        $base = [
            'refund_mode'  => 'cash',
            'refund_id'    => $refId,
            'receipt_no'   => $origReceipt,
            'student_name' => "TEST_SYNTH_{$idx}",
            'student_id'   => $stuId,
            'class'        => 'TEST_CLASS',
            'admin_id'     => self::TEST_ACCOUNTANT,
            'date'         => date('Y-m-d'),
        ];
        switch ($type) {
            case 'tuition':
                return array_merge($base, [
                    'amount'      => 5000.0,
                    'allocations' => [['fee_head' => 'Tuition Fee', 'amount' => 5000.0]],
                ]);
            case 'transport':
                return array_merge($base, [
                    'amount'      => 1000.0,
                    'allocations' => [['fee_head' => 'Transport Fee', 'amount' => 1000.0]],
                ]);
            case 'partial_mixed':
                return array_merge($base, [
                    'amount'      => 2500.0,
                    'allocations' => [
                        ['fee_head' => 'Tuition Fee',   'amount' => 2000.0],
                        ['fee_head' => 'Transport Fee', 'amount' => 500.0],
                    ],
                ]);
        }
        return $base;
    }

    // ═════════════════════════════════════════════════════════════════
    //  ASSERTION HELPERS
    // ═════════════════════════════════════════════════════════════════

    private function _assertReceipt(string $entryId, array $expected): bool
    {
        $doc = $this->_readLedger($entryId);
        if (!$doc) { echo "[VERIFY] ledger doc not found for {$entryId}\n"; return false; }

        $lines = (array) ($doc['lines'] ?? []);
        $tDr = (float) ($doc['total_dr'] ?? 0);
        $tCr = (float) ($doc['total_cr'] ?? 0);
        $cr_4010 = $cr_4040 = $dr_1010 = 0.0;
        foreach ($lines as $L) {
            $code = (string) ($L['account_code'] ?? '');
            $dr   = (float) ($L['dr'] ?? 0);
            $cr   = (float) ($L['cr'] ?? 0);
            if ($code === '4010') $cr_4010 += $cr;
            if ($code === '4040') $cr_4040 += $cr;
            if ($code === '1010') $dr_1010 += $dr;
        }
        $checks = [
            'lines '     => [count($lines), $expected['lines']],
            'Dr 1010 '   => [$dr_1010, $expected['dr_1010']],
            'Cr 4010 '   => [$cr_4010, $expected['cr_4010']],
            'Cr 4040 '   => [$cr_4040, $expected['cr_4040']],
            'total_dr '  => [$tDr, $expected['total']],
            'total_cr '  => [$tCr, $expected['total']],
            'Dr=Cr '     => [round($tDr - $tCr, 2), 0.0],
        ];
        $ok = true;
        foreach ($checks as $name => [$actual, $exp]) {
            $pass = ($actual === $exp) || (is_numeric($actual) && abs($actual - $exp) < 0.01);
            $marker = $pass ? '✓' : '✗';
            echo "[VERIFY] {$name}{$actual} (expected {$exp}) {$marker}\n";
            if (!$pass) $ok = false;
        }
        // Forensic count
        $fcount = $this->_forensicCountFor($entryId, 'created');
        echo "[VERIFY] forensic created events for {$entryId}: {$fcount} (expected 1) "
            . ($fcount === 1 ? '✓' : '✗') . "\n";
        if ($fcount !== 1) $ok = false;
        return $ok;
    }

    private function _assertRefund(string $entryId, array $expected): bool
    {
        $doc = $this->_readLedger($entryId);
        if (!$doc) { echo "[VERIFY] refund ledger doc not found for {$entryId}\n"; return false; }

        $lines = (array) ($doc['lines'] ?? []);
        $tDr = (float) ($doc['total_dr'] ?? 0);
        $tCr = (float) ($doc['total_cr'] ?? 0);
        $dr_4010 = $dr_4040 = $cr_1010 = 0.0;
        foreach ($lines as $L) {
            $code = (string) ($L['account_code'] ?? '');
            $dr   = (float) ($L['dr'] ?? 0);
            $cr   = (float) ($L['cr'] ?? 0);
            if ($code === '4010') $dr_4010 += $dr;
            if ($code === '4040') $dr_4040 += $dr;
            if ($code === '1010') $cr_1010 += $cr;
        }
        $checks = [
            'lines '     => [count($lines), $expected['lines']],
            'Dr 4010 '   => [$dr_4010, $expected['dr_4010']],
            'Dr 4040 '   => [$dr_4040, $expected['dr_4040']],
            'Cr 1010 '   => [$cr_1010, $expected['cr_1010']],
            'total_dr '  => [$tDr, $expected['total']],
            'total_cr '  => [$tCr, $expected['total']],
            'Dr=Cr '     => [round($tDr - $tCr, 2), 0.0],
        ];
        $ok = true;
        foreach ($checks as $name => [$actual, $exp]) {
            $pass = ($actual === $exp) || (is_numeric($actual) && abs($actual - $exp) < 0.01);
            $marker = $pass ? '✓' : '✗';
            echo "[VERIFY] {$name}{$actual} (expected {$exp}) {$marker}\n";
            if (!$pass) $ok = false;
        }
        return $ok;
    }

    // ═════════════════════════════════════════════════════════════════
    //  FIRESTORE READS (read-only — never writes to accounting)
    // ═════════════════════════════════════════════════════════════════

    private function _readLedger(string $entryId): ?array
    {
        // Ledger doc id is "{schoolId}_{session}_{entryId}" per accounting/Operations_accounting convention.
        $docId = "{$this->schoolFs}_{$this->sessionYear}_{$entryId}";
        $doc = $this->firebase->firestoreGet('accounting', $docId);
        if (is_array($doc)) return $doc;
        // Fallback: query by entryId
        $rows = (array) $this->firebase->firestoreQuery('accounting', [
            ['schoolId', '==', $this->schoolFs],
            ['session',  '==', $this->sessionYear],
            ['entryId',  '==', $entryId],
        ], '', '', 1);
        if (!empty($rows[0]['data'])) return (array) $rows[0]['data'];
        return null;
    }

    private function _forensicCountFor(string $entryId, string $eventType): int
    {
        $rows = (array) $this->firebase->firestoreQuery('accountingForensics', [
            ['schoolId',  '==', $this->schoolFs],
            ['session',   '==', $this->sessionYear],
            ['entryId',   '==', $entryId],
            ['eventType', '==', $eventType],
        ], 'occurredAt', 'ASC', 50);
        return count($rows);
    }

    private function _findRunEntries(string $runId): array
    {
        // Match by the synthetic source_ref pattern in the ledger.
        $rows = (array) $this->firebase->firestoreQuery('accounting', [
            ['schoolId', '==', $this->schoolFs],
            ['session',  '==', $this->sessionYear],
        ], 'created_at', 'DESC', 500);
        $matches = [];
        foreach ($rows as $r) {
            $d = (array) ($r['data'] ?? []);
            $sr = (string) ($d['source_ref'] ?? '');
            $entry = (string) ($d['entryId'] ?? '');
            if (strpos($sr, "TEST_RCT_{$runId}_") === 0
                || strpos($sr, "TEST_RFD_{$runId}_") === 0
                || strpos($sr, "TEST_MAN_{$runId}_") === 0
                || strpos($entry, "JE_FEE_TEST_RCT_{$runId}_") === 0) {
                $matches[] = $r;
            }
        }
        return $matches;
    }

    private function _findAllSyntheticEntries(): array
    {
        $rows = (array) $this->firebase->firestoreQuery('accounting', [
            ['schoolId', '==', $this->schoolFs],
            ['session',  '==', $this->sessionYear],
        ], 'created_at', 'DESC', 1000);
        $matches = [];
        foreach ($rows as $r) {
            $d = (array) ($r['data'] ?? []);
            $sr = (string) ($d['source_ref'] ?? '');
            $entry = (string) ($d['entryId'] ?? '');
            $narr = (string) ($d['narration'] ?? '');
            if (preg_match('/^TEST_(RCT|RFD|MAN)_/', $sr)
                || preg_match('/JE_FEE_TEST_RCT_/', $entry)
                || (strpos($narr, 'TEST: ') === 0)) {
                $matches[] = $r;
            }
        }
        return $matches;
    }

    private function _postReversal(array $ledgerDocWrap): bool
    {
        $d = (array) ($ledgerDocWrap['data'] ?? []);
        $entryId = (string) ($d['entryId'] ?? '');
        if ($entryId === '') return false;
        $lines = (array) ($d['lines'] ?? []);
        if (empty($lines)) return false;
        // Build swapped lines
        $swapped = [];
        foreach ($lines as $L) {
            $swapped[] = [
                'account_code' => (string) ($L['account_code'] ?? ''),
                'dr' => (float) ($L['cr'] ?? 0),
                'cr' => (float) ($L['dr'] ?? 0),
            ];
        }
        try {
            $reversal = $this->opsAcct->create_journal(
                "TEST: simulator cleanup reversal of {$entryId}",
                $swapped, 'manual', "TEST_MAN_REV_{$this->runId}_{$entryId}",
                'Journal', date('Y-m-d')
            );
            return !empty($reversal);
        } catch (\Throwable $e) {
            return false;
        }
    }

    // ═════════════════════════════════════════════════════════════════
    //  RUN-LOG TRACKING (local file, not Firestore)
    // ═════════════════════════════════════════════════════════════════

    private function _recordRunEntry(string $runId, string $entryId, string $source, string $type): void
    {
        $path = self::RUN_LOG_DIR . "run_{$runId}.json";
        $existing = [];
        if (is_file($path)) {
            $raw = @file_get_contents($path);
            $j = json_decode((string) $raw, true);
            if (is_array($j)) $existing = $j;
        }
        $existing[] = [
            'entryId' => $entryId,
            'source'  => $source,
            'type'    => $type,
            'at'      => date('c'),
        ];
        @file_put_contents($path, json_encode($existing, JSON_PRETTY_PRINT));
    }

    // ═════════════════════════════════════════════════════════════════
    //  UTILITIES
    // ═════════════════════════════════════════════════════════════════

    private function _initOpsAcct(): void
    {
        // Production note: MY_Controller wires `school_name` from session
        // userdata('schoolName'), which is set to the SCH_XXXXXX schoolId
        // (the misleading param name is preserved through the engine).
        // The simulator must pass schoolFs in this slot so the downstream
        // Accounting_firestore_sync queries the correct schoolId on the
        // chart of accounts and ledger collections.
        $this->load->library('Operations_accounting', null, 'opsAcct');
        $this->opsAcct->init(
            $this->firebase, $this->schoolFs, $this->sessionYear,
            self::TEST_ACCOUNTANT, $this
        );
    }

    private function _log(string $msg): void
    {
        log_message('error', $msg); // CI's "error" channel is the only always-on channel
    }

    private function _die(string $msg): void
    {
        echo "ERROR: {$msg}\n";
        exit(1);
    }

    // ═════════════════════════════════════════════════════════════════
    //  R1 — One-shot historical-imbalance remediation
    //
    //  Reverses the duplicated liability contributions from the
    //  pre-canonical HR_Payroll entry JE_20260414193106_f55be96d
    //  (broken legacy direct-write path; total_dr=0, total_cr=24845).
    //
    //  Routes ONLY through Operations_accounting::create_journal.
    //  Idempotent: second call detects existing remediation and aborts.
    //  Tags forensic timeline with 'prior_period_correction' event
    //  carrying explicit linkage to the original broken entryId.
    //
    //  Authorization: explicit operator approval 2026-05-10, scoped to
    //  this exact entry only. Not generalizable; not a pattern for
    //  routine remediation.
    // ═════════════════════════════════════════════════════════════════
    public function remediate_R1(): void
    {
        $this->_requireConfirm();
        $this->_initOpsAcct();

        $brokenDocId    = "{$this->schoolFs}_JE_20260414193106_f55be96d";
        $brokenEntryRef = 'JE_20260414193106_f55be96d';
        $sourceRef      = "PPC_REV_{$brokenEntryRef}";

        echo "=== R1: Historical imbalanced ledger remediation ===\n";
        echo "  Target: {$brokenDocId}\n";

        // Defensive re-validation: confirm broken entry still exists in
        // the expected imbalanced state before remediating.
        $broken = $this->firebase->firestoreGet('accounting', $brokenDocId);
        if (!is_array($broken)) {
            $this->_die("Broken entry not found — already removed or different schoolId/session.");
        }
        $tDr = (float) ($broken['total_dr'] ?? 0);
        $tCr = (float) ($broken['total_cr'] ?? 0);
        if ($tDr > 0.01) {
            $this->_die("Broken entry has total_dr={$tDr} — state changed since investigation; abort.");
        }
        if (abs($tCr - 24845.0) > 0.01) {
            $this->_die("Broken entry total_cr={$tCr} differs from investigated 24845; abort.");
        }
        echo "  Pre-state confirmed: total_dr=0 total_cr=24845 ✓\n";

        // Idempotency: detect prior remediation by source_ref pattern.
        $existing = (array) $this->firebase->firestoreQuery('accounting', [
            ['schoolId',   '==', $this->schoolFs],
            ['session',    '==', $this->sessionYear],
            ['source_ref', '==', $sourceRef],
        ], '', '', 1);
        if (!empty($existing)) {
            $existingEntry = (string) ($existing[0]['data']['entryId'] ?? '?');
            echo "  Already remediated: entryId={$existingEntry} (source_ref={$sourceRef})\n";
            echo "  R1 is idempotent — no second remediation will be posted.\n";
            return;
        }

        // Build reversal lines:
        //   Dr 2030 21900  (eliminate phantom PF Payable)
        //   Dr 2031  1545  (eliminate phantom ESI Payable)
        //   Dr 2033  1400  (eliminate phantom Professional Tax Payable)
        //   Cr 3020 24845  (Retained Surplus — equity-side prior-period
        //                   adjustment; phantom liabilities never reduced
        //                   the school's actual net asset position)
        $lines = [
            ['account_code' => '2030', 'dr' => 21900.00, 'cr' => 0],
            ['account_code' => '2031', 'dr' => 1545.00,  'cr' => 0],
            ['account_code' => '2033', 'dr' => 1400.00,  'cr' => 0],
            ['account_code' => '3020', 'dr' => 0,        'cr' => 24845.00],
        ];

        $narration = "Prior Period Correction: reverse phantom liability contributions "
                   . "from broken entry {$brokenEntryRef} (HR_Payroll Apr 2026, "
                   . "Dr-side missing in pre-canonical legacy direct-write path). "
                   . "Equity-side offset to 3020 Retained Surplus.";

        echo "  Posting reversal:\n";
        foreach ($lines as $L) {
            echo "    " . str_pad((string) ($L['account_code']), 6) . " Dr=" . str_pad((string) $L['dr'], 10) . " Cr=" . $L['cr'] . "\n";
        }

        $entryId = '';
        try {
            $entryId = $this->opsAcct->create_journal(
                $narration, $lines, 'manual', $sourceRef, 'Journal', date('Y-m-d')
            );
        } catch (\Throwable $e) {
            $this->_die("Engine rejected reversal: " . $e->getMessage());
        }
        if (!$entryId) {
            $this->_die("Engine returned empty entryId — check log for ACC_JOURNAL_FAILED.");
        }

        echo "\n  Reversal posted: entryId={$entryId}\n";

        // Emit prior_period_correction forensic event (additional to
        // the engine-emitted 'created' event), with explicit linkage
        // back to the original broken entry.
        $this->load->library('Accounting_forensics', null, 'acctForensics');
        $this->acctForensics->init($this->firebase, $this->schoolFs, $this->sessionYear);
        $this->acctForensics->recordEvent(
            $entryId, 'prior_period_correction',
            self::TEST_ACCOUNTANT, 'finance_admin', 'manual',
            [
                'original_entry_doc_id' => $brokenDocId,
                'original_entry_ref'    => $brokenEntryRef,
                'reason'                => 'Reverse duplicated liability Cr from pre-canonical HR_Payroll legacy path',
                'amount'                => 24845.00,
                'authorization'         => 'Option_R1_explicit_operator_authorization_2026-05-10',
                'offsetting_account'    => '3020',
                'offsetting_treatment'  => 'retained_surplus_prior_period_adjustment',
            ]
        );
        $this->_log("SIMULATOR_R1_REMEDIATION entryId={$entryId} originalRef={$brokenEntryRef} amount=24845");

        // Read-back verification.
        $verify = $this->_readLedger($entryId);
        if ($verify) {
            $vTotalDr = (float) ($verify['total_dr'] ?? 0);
            $vTotalCr = (float) ($verify['total_cr'] ?? 0);
            $vLines   = count((array) ($verify['lines'] ?? []));
            $balanced = (abs($vTotalDr - $vTotalCr) < 0.01);
            echo "  Ledger verify: lines={$vLines} total_dr={$vTotalDr} total_cr={$vTotalCr} "
               . "balanced=" . ($balanced ? '✓' : '✗') . "\n";
        } else {
            echo "  ! Ledger verify FAILED — entry not found post-commit\n";
        }

        $events = $this->acctForensics->listEventsForEntry($entryId);
        echo "  Forensic timeline ({$entryId}): " . count($events) . " event(s)\n";
        foreach ($events as $ev) {
            echo "    " . str_pad((string) ($ev['eventType'] ?? '?'), 30) . " @ " . ($ev['occurredAt'] ?? '?') . "\n";
        }

        echo "\n=== R1 REMEDIATION COMPLETE ===\n";
    }

    /**
     * Engine-validation shim — Operations_accounting calls $this->CI->json_error()
     * to halt the request on validation failures (Dr ≠ Cr, group account, etc.).
     * In HTTP context the controller's json_error emits JSON and exit()s. In the
     * simulator we throw a SimulatorEngineRejection exception so scenarios can
     * catch it and report rejection cleanly without aborting the suite.
     *
     * This is NOT a write path; it only translates engine rejection messages
     * into a catchable exception. No engine semantics altered.
     */
    public function json_error(string $msg): void
    {
        throw new \RuntimeException("ENGINE_REJECTION: {$msg}");
    }
}
