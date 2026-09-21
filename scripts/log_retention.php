<?php
/**
 * log_retention.php — enforce a rolling retention window on application logs.
 *
 * WHY THIS EXISTS
 * ---------------
 * CERT-In's Directions of 28.04.2022, ¶(iv), bind every "body corporate" to
 *
 *   "mandatorily enable logs of all their ICT systems and maintain them
 *    securely for a rolling period of 180 days"
 *
 * and to produce them to CERT-In on request. Backed by s.70B(7) IT Act —
 * imprisonment up to one year or a fine up to one lakh rupees, or both. This
 * has been in force since 2022, which makes it the ONLY cyber-security
 * obligation on our list that binds today; DPDP's equivalent (Rule 6, access
 * logs, one-year retention) does not commence until 13 May 2027.
 *
 * Nothing in this repository rotated or retained anything. Logs accumulated
 * until someone deleted them by hand, which satisfies neither limb: there was
 * no guaranteed window, and no way to say what the window was.
 *
 * WHAT IT DOES NOT DO
 * -------------------
 * It does not make the logs adequate. `log_threshold` is 1 in production —
 * errors only — so what accumulates is a record of failures, not a record of
 * the system's activity. Retaining an errors-only log for 180 days satisfies
 * the retention limb and not the "logs of all their ICT systems" limb. That is
 * an operational decision (the threshold is already env-configurable via
 * LOG_THRESHOLD) and it is deliberately not made here.
 *
 * SAFETY
 * ------
 * Dry-run by default: it prints what it would remove and removes nothing.
 * Pass --apply to act. It will not touch a file whose date it cannot read from
 * the name, so an unexpected file is kept rather than guessed at.
 *
 * USAGE
 *   php scripts/log_retention.php                 # report only
 *   php scripts/log_retention.php --apply         # enforce
 *   php scripts/log_retention.php --days=365      # DPDP Rule 6 window
 *   php scripts/log_retention.php --dir=/path     # non-default log directory
 */

$opts    = getopt('', ['apply', 'days::', 'dir::', 'quiet']);
$apply   = isset($opts['apply']);
$days    = isset($opts['days']) ? max(1, (int) $opts['days']) : 180;
$dir     = $opts['dir'] ?? __DIR__ . '/../application/logs';
$quiet   = isset($opts['quiet']);

if (!is_dir($dir)) {
    fwrite(STDERR, "log_retention: not a directory: $dir\n");
    exit(2);
}

$cutoff = strtotime("-{$days} days");
if ($cutoff === false) { fwrite(STDERR, "log_retention: bad window\n"); exit(2); }

/* A date in the filename is the only thing trusted. mtime moves when a file is
   copied or restored, so it is not evidence of when the events happened. */
$dated = static function (string $name): ?int {
    if (preg_match('/(\d{4})[-_]?(\d{2})[-_]?(\d{2})/', $name, $m)) {
        $ts = strtotime("{$m[1]}-{$m[2]}-{$m[3]}");
        return $ts === false ? null : $ts;
    }
    return null;
};

$kept = $removed = $skipped = 0;
$freed = 0;
$rows  = [];

foreach (scandir($dir) ?: [] as $f) {
    if ($f === '.' || $f === '..') { continue; }
    $path = $dir . '/' . $f;
    if (!is_file($path)) { continue; }

    $when = $dated($f);
    if ($when === null) {
        $skipped++;
        $rows[] = ['SKIP  (no date in name)', $f, ''];
        continue;
    }
    if ($when >= $cutoff) {
        $kept++;
        continue;
    }
    $size = (int) filesize($path);
    $rows[] = [$apply ? 'REMOVED' : 'would remove', $f, date('Y-m-d', $when)];
    if ($apply) {
        if (@unlink($path)) { $removed++; $freed += $size; }
        else { fwrite(STDERR, "log_retention: could not remove $f\n"); }
    } else {
        $removed++; $freed += $size;
    }
}

if (!$quiet) {
    echo "log_retention — window {$days} days, cutoff " . date('Y-m-d', $cutoff)
       . ($apply ? " [APPLY]\n" : " [dry run — nothing removed]\n");
    echo str_repeat('-', 64), "\n";
    foreach ($rows as [$what, $name, $when]) {
        printf("  %-22s %-30s %s\n", $what, $name, $when);
    }
    if (!$rows) { echo "  nothing outside the window\n"; }
    echo str_repeat('-', 64), "\n";
    printf("  kept %d · %s %d · skipped %d · %s %s\n",
        $kept, $apply ? 'removed' : 'would remove', $removed, $skipped,
        $apply ? 'freed' : 'would free',
        $freed > 1048576 ? round($freed / 1048576, 1) . ' MB' : round($freed / 1024) . ' KB');
    if ($skipped) {
        echo "  note: skipped files have no readable date in the name and were left alone.\n";
    }
    if (!$apply && $removed) {
        echo "  re-run with --apply to enforce.\n";
    }
}
exit(0);
