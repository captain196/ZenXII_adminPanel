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
 *   timetable        — timetable_projection_cron  (daily, before the school day)
 *                      PLATFORM-WIDE: omit --schoolId and every eligible tenant
 *                      is processed. This is the production shape.
 *   leave_accrual    — leave_accrual_cron run_all  (daily; credits only new months)
 *                      PLATFORM-WIDE always: every tenant that opted into monthly
 *                      accrual (schools.leavePolicy.accrualEnabled). Idempotent
 *                      (lastAccrualMonth), so a daily run is safe and a missed
 *                      day self-heals on the next. No --schoolId needed.
 *
 * Usage:
 *   php scripts/run_scheduled.php --job=reconcile --schoolId=SCH_… --session=YYYY-YY
 *   php scripts/run_scheduled.php --job=integrity --schoolId=SCH_… --session=YYYY-YY
 *
 *   php scripts/run_scheduled.php --job=timetable                 # ALL tenants
 *   php scripts/run_scheduled.php --job=timetable --dry-run       # ALL, no writes
 *   php scripts/run_scheduled.php --job=timetable --schoolId=SCH_… --session=YYYY-YY
 *                                                                 # one tenant
 *
 * Exit codes: 0 on success, non-zero mirrors the child's exit so cron
 * MTA can still distinguish failures in mail output.
 *
 * Child exit codes for --job=timetable (Timetable_projection_cron):
 *   0  projected, OR nothing published covers today (a correct no-op), OR dry run
 *   1  configuration error — SCHOOL_ID / SESSION_YEAR missing, or init failed
 *   2  REFUSED — the timetable in force could not be determined unambiguously,
 *      or the projection threw. Nothing was written and what teachers currently
 *      see is unchanged. This is the one that must page someone.
 */

if (php_sapi_name() !== 'cli') { fwrite(STDERR, "CLI only.\n"); exit(2); }

// ── SAME CLOCK AS THE APPLICATION ───────────────────────────────────────
//
// This wrapper is a standalone script: it requires two libraries directly and
// never bootstraps CodeIgniter, so application/config/config.php — which pins
// date_default_timezone_set('Asia/Kolkata') — does not run here. Its CHILD does
// go through index.php and therefore is pinned.
//
// Left unpinned, parent and child disagree about what day it is for the 5h30m
// between 18:30 UTC and midnight IST — and a job scheduled for 00:20 IST sits
// squarely inside that window. On a UTC host the wrapper would stamp the run
// record, and the runId, with YESTERDAY's date while the child correctly
// projected today's timetable. The run history would then say a school's
// timetable was never materialised on the day it was.
//
// Identical resolution order to config.php (APP_TIMEZONE, else Asia/Kolkata,
// validated) so the two cannot drift; the schools this serves keep IST dates.
$_schedTz = getenv('APP_TIMEZONE') ?: 'Asia/Kolkata';
if (in_array($_schedTz, timezone_identifiers_list(), true)) {
    date_default_timezone_set($_schedTz);
}
unset($_schedTz);

$opts = [];
foreach (array_slice($argv, 1) as $a) {
    if (strncmp($a, '--', 2) !== 0) continue;
    $a = substr($a, 2);
    $eq = strpos($a, '=');
    $opts[$eq === false ? $a : substr($a, 0, $eq)] = $eq === false ? true : substr($a, $eq + 1);
}
$job = (string) ($opts['job'] ?? '');
if ($job === '') { fwrite(STDERR, "Usage: --job={reconcile|integrity|timetable|leave_accrual} …\n"); exit(2); }

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
    case 'timetable':
        // ── materialise today's published timetable for the apps ────────
        //
        // The office reads the canonical timetableVersions; the Teacher and
        // Parent apps read the legacy `timetables` collection, which has to be
        // WRITTEN for them. Publishing does that itself when the version is in
        // force the same day — but a version published to take effect LATER
        // reaches nobody until this runs. On 2026-09-12 a live tenant published
        // on the 12th for the 13th and the apps would never have shown it.
        //
        // Routed through this wrapper rather than invoked directly (which is
        // what DEPLOY_RUNBOOK.md used to prescribe) so a failure is not merely
        // a line in a log nobody reads: every run lands in
        // scheduled_job_runs/{runId} and a non-zero exit raises an alert.
        //
        // Idempotent by construction — it asks "what applies today" and writes
        // only what differs, so a missed night is repaired by the next run and
        // a double run is a no-op. It never deletes.
        // ── PLATFORM BY DEFAULT ─────────────────────────────────────────
        //
        // No --schoolId means EVERY eligible tenant, which is the form a
        // multi-tenant SaaS actually needs: one scheduled entry for the whole
        // platform, and a school onboarded tomorrow is picked up by the next
        // run with no configuration anywhere.
        //
        // --schoolId stays supported for targeted and recovery runs. It is the
        // exception now, not the normal production shape — requiring it was
        // what made automatic delivery a per-customer deployment task.
        $ttSchool  = (string) ($opts['schoolId'] ?? '');
        $ttSession = (string) ($opts['session']  ?? '');
        $ttAction  = 'run_all';

        if ($ttSchool !== '' || $ttSession !== '') {
            if ($ttSchool === '' || $ttSession === '') {
                fwrite(STDERR, "--schoolId and --session must be given together, "
                    . "or neither (which processes every eligible tenant)\n");
                exit(2);
            }
            $ttAction = 'run';
            // The controller reads these from the environment, and a child
            // started by exec() inherits ours. Passing them this way rather
            // than as a `VAR=x cmd` prefix keeps the invocation identical on
            // Windows, where that prefix is a syntax error, not an assignment.
            putenv('SCHOOL_ID='    . $ttSchool);
            putenv('SESSION_YEAR=' . $ttSession);
        }
        // --dry-run reports what WOULD change and writes nothing. Useful for
        // proving a new crontab entry is wired before letting it write.
        putenv('APPLY=' . (isset($opts['dry-run']) ? '0' : '1'));
        // Deliberately NOT passing FOR_DATE: the controller refuses APPLY for
        // any date but today, because the legacy collection holds one document
        // per weekday with no date and cannot represent another day.

        // CodeIgniter's front controller expects to run from the project root.
        // The child inherits our working directory, so change it here rather
        // than prefixing a shell `cd`, which again differs per platform.
        if (!@chdir($root)) {
            fwrite(STDERR, "could not enter project root: {$root}\n");
            exit(2);
        }
        $childCmd = ['php', escapeshellarg("{$root}/index.php"),
                     'timetable_projection_cron', $ttAction];
        break;

    case 'leave_accrual':
        // PLATFORM monthly leave accrual for every opted-in tenant
        // (schools.leavePolicy.accrualEnabled). Idempotent (lastAccrualMonth):
        // safe to run daily; credits only new months. Never per-school here —
        // one entry processes the whole fleet, and a school onboarded tomorrow
        // is picked up by the next run with no configuration.
        if (!@chdir($root)) {
            fwrite(STDERR, "could not enter project root: {$root}\n");
            exit(2);
        }
        // Optional targeted month for backfill/recovery; the controller
        // reads THROUGH_MONTH from the environment (child inherits ours).
        if (!empty($opts['through-month'])) {
            putenv('THROUGH_MONTH=' . (string) $opts['through-month']);
        }
        $childCmd = ['php', escapeshellarg("{$root}/index.php"),
                     'leave_accrual_cron', 'run_all'];
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
