<?php
/**
 * run_scheduled.php — CLI-safe wrapper for cron-triggered maintenance.
 *
 * Wraps one of the scheduled fee-module scripts, captures stdout/stderr,
 * logs success/failure + exit code to scheduled_job_runs/{runId}, and
 * raises a fee_alerts record on any non-zero exit. Cron invokes THIS
 * wrapper, never the underlying scripts directly, so every run is
 * observable on the admin dashboard.
 *
 * Supported jobs:
 *   reconcile        — reconcile_fees.php         (daily)
 *   integrity        — fee_integrity_check.php    (weekly)
 *   reap             — generate_fees.php --reap-dead-workers (hourly)
 *
 * Usage:
 *   php scripts/run_scheduled.php --job=reconcile --schoolId=SCH_… --session=YYYY-YY
 *   php scripts/run_scheduled.php --job=integrity --schoolId=SCH_… --session=YYYY-YY
 *   php scripts/run_scheduled.php --job=reap      --jobId=JOB_… [--thresholdSec=60]
 *
 * Exit codes: 0 on success, non-zero mirrors the child's exit so cron
 * MTA can still distinguish failures in mail output.
 */

if (php_sapi_name() !== 'cli') { fwrite(STDERR, "CLI only.\n"); exit(2); }

$opts = [];
foreach (array_slice($argv, 1) as $a) {
    if (strncmp($a, '--', 2) !== 0) continue;
    $a = substr($a, 2);
    $eq = strpos($a, '=');
    $opts[$eq === false ? $a : substr($a, 0, $eq)] = $eq === false ? true : substr($a, $eq + 1);
}
$job = (string) ($opts['job'] ?? '');
if ($job === '') { fwrite(STDERR, "Usage: --job={reconcile|integrity|reap} …\n"); exit(2); }

$root = realpath(__DIR__ . '/..');
$credPath = $root . '/application/config/graderadmin-firebase-adminsdk-a1sml-2b5f1862a7.json';
$cred = @json_decode((string) file_get_contents($credPath), true) ?: [];
$projectId = (string) ($cred['project_id'] ?? '');
if ($projectId === '') { fwrite(STDERR, "project_id missing\n"); exit(2); }

require_once $root . '/application/libraries/Firestore_rest_client.php';
require_once $root . '/application/libraries/Fee_alerts.php';

$fs = new FirestoreRestClient($credPath, $projectId, '(default)');
$fbShim = new class($fs) {
    private $fs;
    public function __construct($fs) { $this->fs = $fs; }
    public function firestoreSet($c, $id, $d, $merge = false)     { return $this->fs->setDocument($c, $id, $d, $merge); }
    public function firestoreGet($c, $id)                         { return $this->fs->getDocument($c, $id); }
    public function firestoreQuery($c, $w=[], $ob=null, $dir='ASC', $l=null) { return $this->fs->query($c, $w, $ob, $dir, $l); }
};
$alerts = new Fee_alerts($fbShim,
    (string) ($opts['schoolId'] ?? 'SCHEDULED'),
    (string) ($opts['session']  ?? '')
);

// Resolve the child command line from the job alias.
$childCmd = null;
switch ($job) {
    case 'reconcile':
        $childCmd = [
            'php', escapeshellarg("{$root}/scripts/reconcile_fees.php"),
            '--schoolId=' . escapeshellarg((string) ($opts['schoolId'] ?? '')),
            '--session='  . escapeshellarg((string) ($opts['session']  ?? '')),
        ];
        break;
    case 'integrity':
        $childCmd = [
            'php', escapeshellarg("{$root}/scripts/fee_integrity_check.php"),
            '--schoolId=' . escapeshellarg((string) ($opts['schoolId'] ?? '')),
            '--session='  . escapeshellarg((string) ($opts['session']  ?? '')),
        ];
        break;
    case 'reap':
        $childCmd = [
            'php', escapeshellarg("{$root}/scripts/generate_fees.php"),
            '--reap-dead-workers',
            '--jobId=' . escapeshellarg((string) ($opts['jobId'] ?? '')),
            '--thresholdSec=' . (int) ($opts['thresholdSec'] ?? 60),
        ];
        break;
    default:
        fwrite(STDERR, "unknown --job: {$job}\n"); exit(2);
}

$runId = 'RUN_' . date('YmdHis') . '_' . substr(bin2hex(random_bytes(3)), 0, 6);
$startTs = time();
$cmdStr  = implode(' ', $childCmd) . ' 2>&1';

// Record 'running' state so dashboards can spot hung jobs.
try {
    $fs->setDocument('scheduled_job_runs', $runId, [
        'runId'     => $runId,
        'job'       => $job,
        'schoolId'  => (string) ($opts['schoolId'] ?? ''),
        'session'   => (string) ($opts['session']  ?? ''),
        'command'   => $cmdStr,
        'status'    => 'running',
        'startedAt' => date('c'),
        'updatedAt' => date('c'),
    ]);
} catch (\Throwable $_) {}

$output = []; $exitCode = 0;
@exec($cmdStr, $output, $exitCode);
$durSec = time() - $startTs;
$status = $exitCode === 0 ? 'completed' : 'failed';
$tail   = array_slice($output, -50);

try {
    $fs->setDocument('scheduled_job_runs', $runId, [
        'status'      => $status,
        'exitCode'    => (int) $exitCode,
        'durationSec' => $durSec,
        'outputTail'  => $tail,
        'finishedAt'  => date('c'),
        'updatedAt'   => date('c'),
    ], /* merge */ true);
} catch (\Throwable $_) {}

fwrite(STDOUT, sprintf("[%s] job=%s status=%s exit=%d dur=%ds runId=%s\n",
    date('c'), $job, $status, $exitCode, $durSec, $runId));
foreach ($tail as $line) fwrite(STDOUT, $line . "\n");

// Alert on failure so the operator is paged (via webhook / email when
// feeSettings/{schoolId}_alerts is configured).
if ($exitCode !== 0) {
    $alerts->raise(
        Fee_alerts::SEV_ERROR,
        'scheduled_job_failure',
        "Scheduled job '{$job}' FAILED (exit={$exitCode})",
        "Run {$runId} exited non-zero after {$durSec}s. Tail:\n" . implode("\n", array_slice($tail, -10)),
        [
            'dedupKey' => "sched:{$job}:" . date('Y-m-d'),
            'payload'  => ['runId' => $runId, 'exitCode' => $exitCode, 'durationSec' => $durSec],
        ]
    );
}
exit($exitCode);
