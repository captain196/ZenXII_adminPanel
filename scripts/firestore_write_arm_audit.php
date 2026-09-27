<?php
/**
 * ══════════════════════════════════════════════════════════════════════════
 * FIRESTORE WRITE-ARM AUTHORIZATION CENSUS · READ ONLY
 * ══════════════════════════════════════════════════════════════════════════
 *
 *   php scripts/firestore_write_arm_audit.php <RULES_FILE>
 *
 * Classifies EVERY write-capable arm in a ruleset, independently of its read
 * arm. Run it against a FRESH production pull, never the repository file.
 *
 * ── WHY PER-ARM, NOT PER-BLOCK ────────────────────────────────────────────
 * An earlier census tested each `match` BLOCK for the presence of a scoping
 * helper and reported 9 unscoped collections. That was wrong: a correctly
 * scoped READ arm masked an unscoped WRITE arm.
 *
 *     match /notifications/{docId} {
 *       allow read:  if isSameSchool();     <- helper found here
 *       allow write: if isStaff();          <- the actual defect, missed
 *     }
 *
 * Evaluating each arm on its own condition raised the count from 9 to 43.
 * Read and write authorization are separate questions and must be judged
 * separately.
 *
 * ── WHY BRACE MATCHING, NOT A NON-GREEDY REGEX ────────────────────────────
 * DEFECT FOUND 2026-08-21. The block extractor used to be:
 *
 *     /match \/([A-Za-z_]+)\/\{([^}]+)\}\s*\{(.*?)\n    \}/s
 *
 * That `(.*?)\n    \}` assumes every block ENDS on a line of its own. Blocks
 * written on ONE line break the assumption:
 *
 *     match /feeIdempotency/{docId}  { allow read, write: if false; }   line 990
 *     ...
 *     match /feeCarryForward/{docId} {                                  line 1059
 *       allow write: if isAdmin();                                      <- real defect
 *     }                                                                 line 1063
 *
 * The scan for feeIdempotency ran straight past its own inline `}` to line
 * 1063, swallowing six intervening blocks. It then reported feeCarryForward's
 * unscoped arm under the name `feeIdempotency`, while feeCarryForward itself
 * never appeared at all. One false positive on an already-DENY collection and
 * one false negative on a genuinely unscoped one — the two errors cancelled in
 * the totals, which is exactly why the count looked stable and trustworthy.
 *
 * Blocks are now delimited by brace DEPTH from the opening `{`, so inline and
 * multi-line blocks parse identically.
 *
 * ── CLASSIFICATION ────────────────────────────────────────────────────────
 *   DENY          server-only (`if false`) — the backend uses a service
 *                 account and bypasses rules, so this is the strongest posture
 *   TENANT        compares the document's school to the caller's token
 *   OWNER         compares the document's owner to `request.auth.uid`
 *   TENANT+RBAC   tenant comparison AND a role/capability requirement
 *   PLATFORM      deliberately cross-tenant (superadmin surface)
 *   UNSCOPED      role checked, tenant NOT — the defect class
 *   INVESTIGATE   cannot be classified statically; needs the app contract
 *
 * UNSCOPED is a CANDIDATE, not a verdict. Every one still requires a
 * blast-radius census (who writes it, with what payload, is the right boundary
 * tenancy or ownership) before any rule is changed. `userDevices` is the
 * precedent: the obvious tenant fix would have broken push registration for
 * every user, because ownership was the correct boundary.
 */

if (PHP_SAPI !== 'cli') { fwrite(STDERR, "CLI only\n"); exit(2); }

$path = trim((string) ($argv[1] ?? ''));
if ($path === '' || !is_file($path)) {
    fwrite(STDERR, "usage: php scripts/firestore_write_arm_audit.php <RULES_FILE>\n");
    exit(2);
}
$src = (string) file_get_contents($path);
if (strlen($src) < 10000) { fwrite(STDERR, "rules file looks truncated\n"); exit(2); }

/** Strip comments so a helper named in prose is never mistaken for a check. */
$clean = preg_replace('!//[^\n]*!', '', $src);

/**
 * Extract every `match /<collection>/{var} { ... }` body by brace depth.
 * Scanning resumes INSIDE each body, so nested matches are found on their own
 * pass rather than being hidden by their parent.
 */
$blocks = [];
$len = strlen($clean);
$off = 0;
while (preg_match('/match\s+\/([A-Za-z_][A-Za-z0-9_]*)\/\{([^}]*)\}\s*\{/', $clean, $m, PREG_OFFSET_CAPTURE, $off)) {
    $col      = $m[1][0];
    $var      = trim($m[2][0]);
    $bodyFrom = $m[0][1] + strlen($m[0][0]);   // just past the opening brace
    $depth    = 1;
    $i        = $bodyFrom;
    while ($i < $len && $depth > 0) {
        $ch = $clean[$i];
        if ($ch === '{')      $depth++;
        elseif ($ch === '}')  $depth--;
        $i++;
    }
    if ($depth !== 0) { fwrite(STDERR, "unbalanced braces after match /{$col}/ — refusing\n"); exit(2); }
    $blocks[] = ['col' => $col, 'var' => $var, 'body' => substr($clean, $bodyFrom, $i - 1 - $bodyFrom)];
    $off = $m[0][1] + strlen($m[0][0]);        // continue inside, so nested blocks are seen
}

/** Arms belonging to a NESTED match must not be counted against the parent. */
function stripNested($body) {
    $out   = '';
    $depth = 0;
    $len   = strlen($body);
    for ($i = 0; $i < $len; $i++) {
        $ch = $body[$i];
        if ($ch === '{') { $depth++; continue; }
        if ($ch === '}') { $depth--; continue; }
        if ($depth === 0) $out .= $ch;
    }
    return $out;
}

$rows  = [];
$tally = ['DENY' => 0, 'TENANT' => 0, 'OWNER' => 0, 'TENANT+RBAC' => 0,
          'PLATFORM' => 0, 'UNSCOPED' => 0, 'INVESTIGATE' => 0];

foreach ($blocks as $b) {
    $col  = $b['col'];
    $var  = $b['var'];
    $body = stripNested($b['body']);

    if (!preg_match_all('/allow\s+([a-z, ]*?)\s*:\s*if\s+(.*?);/s', $body, $arms, PREG_SET_ORDER)) continue;

    foreach ($arms as $a) {
        $verbs = preg_replace('/\s+/', ' ', trim($a[1]));
        if (!preg_match('/write|create|update|delete/', $verbs)) continue;   // write-capable only
        $cond = preg_replace('/\s+/', ' ', trim($a[2]));

        $hasTenant = (strpos($cond, 'isSameSchoolWrite') !== false)
                  || (strpos($cond, 'isSameSchool') !== false)
                  || (strpos($cond, 'school_id') !== false);
        $hasOwner  = (strpos($cond, 'request.auth.uid') !== false)
                  || (strpos($cond, $var . ' ==') !== false);
        $hasRole   = (bool) preg_match('/isAdmin|isStaff|isTeacher|hasCapability|token\.role/', $cond);

        if (preg_match('/^false$/', $cond))              $cls = 'DENY';
        elseif (strpos($cond, 'isSuperAdmin') !== false) $cls = 'PLATFORM';
        elseif ($hasTenant && $hasRole)                  $cls = 'TENANT+RBAC';
        elseif ($hasTenant)                              $cls = 'TENANT';
        elseif ($hasOwner)                               $cls = 'OWNER';
        elseif ($hasRole)                                $cls = 'UNSCOPED';
        else                                             $cls = 'INVESTIGATE';

        $tally[$cls]++;
        $rows[] = ['col' => $col, 'verbs' => $verbs, 'cls' => $cls,
                   'cond' => strlen($cond) > 72 ? substr($cond, 0, 69) . '...' : $cond];
    }
}

echo "FIRESTORE WRITE-ARM AUTHORIZATION CENSUS\n";
echo 'source: ' . basename($path) . "\n";
echo str_repeat('=', 96) . "\n\n";
printf("blocks parsed: %d   write-capable arms: %d\n\n", count($blocks), count($rows));

foreach ($tally as $k => $v) printf("  %-14s %d\n", $k, $v);

foreach (['UNSCOPED', 'INVESTIGATE', 'OWNER', 'PLATFORM'] as $show) {
    $sel = array_values(array_filter($rows, function ($r) use ($show) { return $r['cls'] === $show; }));
    if ($sel === []) continue;
    printf("\n── %s (%d) %s\n", $show, count($sel), str_repeat('─', max(1, 70 - strlen($show))));
    foreach ($sel as $r) printf("  %-26s allow %-22s if %s\n", $r['col'], $r['verbs'], $r['cond']);
}

echo "\n" . str_repeat('=', 96) . "\n";
echo "UNSCOPED arms are CANDIDATES, not verdicts. Each needs a blast-radius\n";
echo "census (writers, payload, ownership vs tenancy) before any rule changes.\n";
echo "No rules were modified. Nothing was deployed.\n";
exit(0);
