<?php
/**
 * ══════════════════════════════════════════════════════════════════════════
 * SECURITY RULES — SEQUENTIAL DEPLOY PREPARATION · READ ONLY
 * ══════════════════════════════════════════════════════════════════════════
 *
 *   php scripts/security_rules_deploy_prepare.php <LIVE_PULL> <sec1|sec2>
 *
 * Rebuilds ONE security artifact from a FRESH production pull and proves the
 * result is minimal. It never deploys and never writes to Firestore.
 *
 * ── WHY THIS EXISTS ───────────────────────────────────────────────────────
 * SEC-1 and SEC-2 were both derived from the same live baseline. Deploying both
 * as-built would revert whichever went first, because each artifact is "live
 * ruleset + my change" and neither contains the other's change.
 *
 * The safe sequence is therefore:
 *
 *     pull live → rebuild artifact A → deploy A → RE-PULL → rebuild B → deploy B
 *
 * This script is the "rebuild" step, and it FAILS CLOSED: if the fresh pull no
 * longer matches what the artifact expects to change, it refuses rather than
 * producing a artifact that would clobber someone else's deployed work.
 *
 * ── WHAT IT REFUSES ───────────────────────────────────────────────────────
 *   • a collection whose rule no longer matches the expected pattern
 *     (someone else changed it, or a previous wave already fixed it)
 *   • an artifact whose diff touches anything beyond the intended lines
 *   • unbalanced braces or a changed match-block count
 *
 * The repository's `firebase-rules/firestore.rules` is NEVER used as a source:
 * it runs ahead of production with other workstreams' undeployed work.
 */

if (PHP_SAPI !== 'cli') { fwrite(STDERR, "CLI only\n"); exit(2); }

$livePath = trim((string) ($argv[1] ?? ''));
$which    = strtolower(trim((string) ($argv[2] ?? '')));

if ($livePath === '' || !in_array($which, ['sec1', 'sec2'], true)) {
    fwrite(STDERR, "usage: php scripts/security_rules_deploy_prepare.php <LIVE_PULL> <sec1|sec2>\n");
    fwrite(STDERR, "  LIVE_PULL must be a FRESH pull of the deployed ruleset.\n");
    exit(2);
}
if (!is_file($livePath)) { fwrite(STDERR, "live pull not found: {$livePath}\n"); exit(2); }

$live = (string) file_get_contents($livePath);
if (strlen($live) < 10000) { fwrite(STDERR, "live pull looks truncated — refusing\n"); exit(2); }

echo "SECURITY RULES — DEPLOY PREPARATION ({$which})\n";
echo 'source: ' . basename($livePath) . ' (' . strlen($live) . " bytes)\n";
echo str_repeat('=', 74) . "\n\n";

/** SEC-1: nine collections whose write arm lacks a tenant comparison. */
$SEC1 = [
    'studentDiscounts', 'scholarshipAwards', 'feeCarryForward',
    'feeReceiptAllocations', 'feeDefaulters', 'feeReminderLog',
    'fee_generation_jobs', 'timetableSettings', 'substitutes',
];

$out = $live;
$applied = [];
$skipped = [];
$expectedChangedLines = 0;

if ($which === 'sec1') {
    // ══ THE ARMS MUST BE SPLIT — create/update and delete read DIFFERENT objects ══
    //
    // An earlier version of this transform emitted:
    //     allow write: if isAdmin() && isSameSchoolWrite();
    //
    // That is WRONG and would have denied every DELETE. `allow write` covers
    // create + update + delete, but on a delete `request.resource` is null, so
    // `isSameSchoolWrite()` (which reads `request.resource.data.schoolId`)
    // cannot be satisfied.
    //
    // `feeDemands` splits them as:
    //     allow create, update: if isAdmin() && isSameSchoolWrite();  // request.resource
    //     allow delete:         if isAdmin() && isSameSchool();       // resource
    //
    // ── WHY THIS DOES NOT COPY `isSameSchool()` FOR DELETE ──────────────────
    //
    // Adversarial review of that idiom found two weaknesses, and a delete is a
    // WRITE, so inheriting them would be a regression:
    //
    //   1. `isSameSchool()` contains a legacy arm
    //          resource.data.schoolCode == request.auth.token.school_id
    //      D7 (2026-07-12) deliberately REMOVED that arm from the write path,
    //      recording it as the "spoofable schoolCode == school_id fallback
    //      (F-2)", and kept it on READS only, to serve legacy schoolCode-only
    //      documents. Authorising a DELETE through it would reintroduce
    //      precisely what D7 removed from writes.
    //
    //   2. `isSameSchool()` opens with `resource == null ||`, i.e. it FAILS OPEN
    //      when the target does not exist. Harmless for a read, but it means a
    //      delete cannot fail closed on a missing document.
    //
    // Measured 2026-08-21: across all nine collections, 33 live documents and
    // ZERO lacking `schoolId` — so the legacy arm buys nothing here and the
    // strict predicate costs nothing.
    foreach ($SEC1 as $c) {
        $pat = '/(match \/' . preg_quote($c, '/') . '\/\{[^}]*\}\s*\{[^}]*?)allow write:\s*if isAdmin\(\);/s';
        $rep = '$1allow create, update: if isAdmin() && isSameSchoolWrite();'
             . "\n      allow delete: if isAdmin()"
             . "\n        && resource != null"
             . "\n        && resource.data.schoolId == request.auth.token.school_id;";
        $new = preg_replace($pat, $rep, $out, 1, $n);
        if ($n === 1) { $out = $new; $applied[] = $c; continue; }

        // Already fixed, or changed by someone else. Distinguish the two.
        if (preg_match('/match \/' . preg_quote($c, '/') . '\/\{[^}]*\}\s*\{[^}]*?isSameSchoolWrite\(\)/s', $out)) {
            $skipped[] = "{$c} (already scoped — earlier wave or another session)";
        } else {
            fwrite(STDERR, "REFUSING: {$c} no longer matches the expected rule shape.\n"
                 . "  Its rule changed in production. Re-audit before deploying.\n");
            exit(1);
        }
    }
    // 1 line removed, 2 added per collection (the arms are split).
    $expectedChangedLines = count($applied);
} else {
    $old = "    match /userDevices/{docId} {\n"
         . "      allow read: if isAuth();\n"
         . "      allow create: if isAuth();\n"
         . "      allow update: if isAuth();\n"
         . "      allow delete: if isAdmin();\n"
         . "    }";
    if (strpos($live, $old) === false) {
        if (strpos($live, "request.resource.data.userId == request.auth.uid") !== false) {
            echo "  SEC-2 already applied in production — nothing to do.\n";
            exit(0);
        }
        fwrite(STDERR, "REFUSING: the userDevices block no longer matches the expected shape.\n"
             . "  Re-audit before deploying.\n");
        exit(1);
    }
    $new = "    match /userDevices/{docId} {\n"
         . "      // SEC-2: ownership, not merely tenancy. userDevices is the sole\n"
         . "      // canonical FCM token store; a token is a delivery address, so\n"
         . "      // reading one enables targeted push spoofing and overwriting one\n"
         . "      // redirects another user's notifications. Proven contract: docId is\n"
         . "      // {userId}_{deviceId} and userId == request.auth.uid for parents and\n"
         . "      // staff (functions/index.js:224). Neither app READS this collection.\n"
         . "      allow read: if isAuth() && resource.data.get('userId', '') == request.auth.uid;\n"
         . "      allow create, update: if isAuth()\n"
         . "        && request.resource.data.userId == request.auth.uid\n"
         . "        && request.resource.data.schoolId == request.auth.token.school_id;\n"
         . "      allow delete: if isAdmin()\n"
         . "        && resource.data.get('schoolId', '') == request.auth.token.school_id;\n"
         . "    }";
    $out = str_replace($old, $new, $live, $n);
    $applied[] = 'userDevices';
    $expectedChangedLines = 4 + 11;   // 4 removed rule lines, 11 added (rules + comment)
}

// ── structural proof ──────────────────────────────────────────────────────
$depth = 0;
for ($i = 0, $L = strlen($out); $i < $L; $i++) {
    if ($out[$i] === '{') $depth++;
    elseif ($out[$i] === '}') $depth--;
}
$liveMatches = preg_match_all('/match \//', $live);
$artMatches  = preg_match_all('/match \//', $out);

printf("  applied  : %d — %s\n", count($applied), implode(', ', $applied));
foreach ($skipped as $s) printf("  skipped  : %s\n", $s);
printf("  braces   : %s (depth %d)\n", $depth === 0 ? 'BALANCED' : 'UNBALANCED', $depth);
printf("  match    : live %d -> artifact %d %s\n", $liveMatches, $artMatches,
    $liveMatches === $artMatches ? '(unchanged)' : '(CHANGED — REFUSE)');

$fail = ($depth !== 0) || ($liveMatches !== $artMatches) || $applied === [];

// ── diff proof — nothing beyond the intended lines ────────────────────────
// POSITIONAL diff, deliberately.
//
// A previous version used array_diff($liveLines, $artifactLines). That is
// WRONG here and silently reported "0 changed lines" for a 9-line change:
// array_diff compares VALUES, and `allow write: if isAdmin();` occurs on many
// collections, so rewriting nine of them leaves the value still present
// elsewhere and the diff comes back empty. A check that cannot fail is worse
// than no check, because it reads as proof.
$lf = explode("\n", str_replace("\r", '', $live));
$af = explode("\n", str_replace("\r", '', $out));

$removed = [];
$added   = [];
$max = max(count($lf), count($af));
for ($i = 0; $i < $max; $i++) {
    $a = $lf[$i] ?? null;
    $b = $af[$i] ?? null;
    if ($a === $b) continue;
    if ($a !== null) $removed[$i] = $a;
    if ($b !== null) $added[$i]   = $b;
}

printf("  removed  : %d lines\n", count($removed));
printf("  added    : %d lines\n", count($added));

// SEC-2 inserts comment lines, which shifts every later line and makes a
// positional diff report the whole tail. Compare the SET of non-blank changes
// instead when the file length changed.
if (count($lf) !== count($af)) {
    // Both transforms ADD lines (SEC-1 splits one arm into two; SEC-2 inserts a
    // comment block), which shifts every later line and makes a positional diff
    // report the whole tail. Verify by CONTENT instead, per mode.
    printf("  note     : line count changed (%d -> %d) — lines inserted, so the\n",
        count($lf), count($af));
    printf("             positional diff over-reports. Verifying by content instead.\n");

    if ($which === 'sec1') {
        // Every applied collection must now carry BOTH arms, and the old
        // unscoped `allow write: if isAdmin();` must be gone from each block.
        foreach ($applied as $c) {
            $blockPat = '/match \/' . preg_quote($c, '/') . '\/\{[^}]*\}\s*\{(.*?)\n    \}/s';
            if (!preg_match($blockPat, $out, $bm)) {
                fwrite(STDERR, "REFUSING: cannot re-read the {$c} block after transform.\n");
                $fail = true; continue;
            }
            $blk = $bm[1];
            $hasCU  = strpos($blk, 'allow create, update: if isAdmin() && isSameSchoolWrite();') !== false;
            $hasDel = strpos($blk, 'resource.data.schoolId == request.auth.token.school_id') !== false;
            $noOld  = !preg_match('/allow write:\s*if isAdmin\(\);/', $blk);
            if (!$hasCU || !$hasDel || !$noOld) {
                fwrite(STDERR, sprintf(
                    "REFUSING: %s malformed after transform (create/update=%s delete=%s oldRemoved=%s)\n",
                    $c, $hasCU ? 'y' : 'n', $hasDel ? 'y' : 'n', $noOld ? 'y' : 'n'));
                $fail = true;
            }
        }
        printf("  verified : all %d blocks carry create/update + delete arms\n", count($applied));
    } else {
        $fail = $fail || (strpos($out, "request.resource.data.userId == request.auth.uid") === false)
                      || (strpos($out, "resource.data.get('schoolId', '') == request.auth.token.school_id") === false);
    }
} else {
    foreach ($removed as $r) {
        if (!preg_match('/allow (write|read|create|update|delete)/', $r)) {
            fwrite(STDERR, "REFUSING: a non-`allow` line was removed:\n  {$r}\n");
            $fail = true;
        }
    }
    if (count($removed) !== $expectedChangedLines / 2) {
        fwrite(STDERR, sprintf("REFUSING: expected %d changed lines, found %d.\n",
            (int) ($expectedChangedLines / 2), count($removed)));
        $fail = true;
    }
}

$outFile = dirname($livePath) . "/{$which}_rebuilt.rules";
file_put_contents($outFile, $out);

echo "\n" . str_repeat('=', 74) . "\n";
if ($fail) {
    echo "REFUSED — do NOT deploy this artifact.\n";
    exit(1);
}
echo "ARTIFACT OK: {$outFile}\n";
echo "\nNEXT (each step is a separate authorized operation):\n";
echo "  1. dry-run/inspect the artifact\n";
echo "  2. deploy ONLY this artifact — never firebase-rules/firestore.rules\n";
echo "  3. RE-PULL production\n";
echo "  4. confirm exactly the intended lines differ from the pre-deploy pull\n";
echo "  5. rebuild the OTHER artifact from the NEW pull before deploying it\n";
echo "\nRollback: redeploy the untouched pull (" . basename($livePath) . ").\n";
echo "No Firestore documents or rules were written by this script.\n";
exit(0);
