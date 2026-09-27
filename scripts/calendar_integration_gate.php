<?php
/**
 * CALENDAR INTEGRATION GATE — clean-worktree execution proof.
 *
 * The question this answers is NOT "does the Calendar code work" (it does, in
 * the working tree, where other programmes' uncommitted files happen to sit
 * alongside it). It is: "if someone checked out committed HEAD and applied
 * ONLY the Calendar change set, would the feature run?"
 *
 * That distinction is the whole point. A static symbol search got this wrong
 * once already, weighting five blockers equally when only one is fatal at load
 * time, so this gate EXECUTES rather than greps.
 *
 * RULES THIS GATE ENFORCES ON ITSELF
 *   - It copies ONLY Calendar-owned files into the clean base.
 *   - It copies NO dependency file. A missing dependency is the RESULT, never
 *     something to work around.
 *   - It never writes to the main working tree.
 *   - It creates and removes its OWN worktree and touches no other.
 *
 * Re-run after each dependency lands at HEAD. Exit 0 = clean-base runnable.
 *
 * Usage:  php scripts/calendar_integration_gate.php [--keep] [--simulate-landed]
 *
 * Exit codes: 0 = clean-base runnable · 1 = blocked · 2 = simulation only · 3 = gate error
 */

$MAIN = str_replace('\\', '/', realpath(__DIR__ . '/..'));
$WT   = str_replace('\\', '/', sys_get_temp_dir()) . '/calendar_gate_' . getmypid();
$KEEP = in_array('--keep', $argv, true);
/**
 * --simulate-landed: copy the dependency files in, to prove this gate is
 * CAPABLE of going green. A red gate that could never go green is no more
 * evidence than a green suite that could never go red. A simulated run is
 * explicitly NOT the integration verdict and forces a distinct exit code.
 */
$SIM  = in_array('--simulate-landed', $argv, true);
$SIM_DEPS = array(
    'application/helpers/effective_dating_helper.php',
    'application/libraries/Leave_year_service.php',
    'application/libraries/Firebase.php',
    'application/libraries/Firestore_service.php',
    'application/libraries/Holiday_service.php',
);

/**
 * Calendar's SHARE of shared files. These lines are Calendar's own work living
 * inside files other programmes also edit; applying them here is not importing
 * foreign work. Without them the feature is unreachable, which is an
 * integration failure just as real as a missing symbol.
 */
$CALENDAR_ROUTES = [
    "\$route['student_calendar']              = 'Student_calendar/index';",
    "\$route['student_calendar/get_policy']   = 'Student_calendar/get_policy';",
    "\$route['student_calendar/preview']      = 'Student_calendar/preview';",
    "\$route['student_calendar/save_version'] = 'Student_calendar/save_version';",
];
$CALENDAR_MENU_HREF = 'student_calendar';
/** Calendar's OWN change set. Nothing here belongs to another programme. */
$CALENDAR_OWNED = [
    'application/libraries/Student_calendar_service.php',
    'application/libraries/Student_calendar_reader.php',
    'application/libraries/Student_calendar_store.php',
    'application/controllers/Student_calendar.php',
    'application/views/student_calendar/index.php',
];

/**
 * Required external symbols, by tier. The tier decides what a failure MEANS:
 * a CORE failure breaks the feature at load time; the others break one path
 * and are caught, leaving the page usable but incomplete.
 */
$REQUIRED = [
    ['tier' => 'CORE',      'symbol' => 'ed_select_for',                        'file' => 'application/helpers/effective_dating_helper.php', 'match' => 'function ed_select_for'],
    ['tier' => 'CORE',      'symbol' => 'ed_is_iso_date',                       'file' => 'application/helpers/effective_dating_helper.php', 'match' => 'function ed_is_iso_date'],
    ['tier' => 'CORE',      'symbol' => 'ed_find_overlaps',                     'file' => 'application/helpers/effective_dating_helper.php', 'match' => 'function ed_find_overlaps'],
    ['tier' => 'SAVE',      'symbol' => 'Firebase::firestoreGetOrFail',         'file' => 'application/libraries/Firebase.php',              'match' => 'function firestoreGetOrFail'],
    ['tier' => 'SAVE',      'symbol' => 'Firebase::firestoreCommitBatchStatus', 'file' => 'application/libraries/Firebase.php',              'match' => 'function firestoreCommitBatchStatus'],
    ['tier' => 'SWD',       'symbol' => 'Holiday_service::special_working_days_in_month', 'file' => 'application/libraries/Holiday_service.php', 'match' => 'function special_working_days_in_month'],
    ['tier' => 'SWD',       'symbol' => 'Firestore_service::schoolWhereOrFail', 'file' => 'application/libraries/Firestore_service.php', 'match' => 'function schoolWhereOrFail'],
    ['tier' => 'READINESS', 'symbol' => 'Leave_year_service::boundsForSession',  'file' => 'application/libraries/Leave_year_service.php',   'match' => 'function boundsForSession'],
    ['tier' => 'READINESS', 'symbol' => 'Leave_year_service::DEFAULT_START_MONTH', 'file' => 'application/libraries/Leave_year_service.php', 'match' => 'DEFAULT_START_MONTH'],
];

function sh($cmd) { $o = []; $rc = 0; exec($cmd . ' 2>&1', $o, $rc); return [$rc, trim(implode("\n", $o))]; }

function copyIn($main, $wt, $rel)
{
    $src = $main . '/' . $rel;
    if (!is_file($src)) return false;
    $dst = $wt . '/' . $rel;
    if (!is_dir(dirname($dst))) @mkdir(dirname($dst), 0775, true);
    return copy($src, $dst);
}

/** Execute a probe INSIDE the clean worktree, in its own PHP process. */
function probe($wt, $body)
{
    $f = $wt . '/_gate_probe_' . bin2hex(random_bytes(4)) . '.php';
    $pre = "<?php\n"
        . "define('BASEPATH',1);\n"
        . "define('APPPATH','" . addslashes($wt) . "/application/');\n"
        . "if(!function_exists('log_message')){function log_message(\$a,\$b){}}\n";
    file_put_contents($f, $pre . $body . "\n");
    list($rc, $out) = sh('php ' . escapeshellarg($f));
    @unlink($f);
    $fatal = (stripos($out, 'Fatal error') !== false || stripos($out, 'Uncaught') !== false);
    return [(!$fatal && $rc === 0), $out];
}

echo "=== CALENDAR INTEGRATION GATE ===\n";
list($x1, $head)    = sh('git -C ' . escapeshellarg($MAIN) . ' rev-parse HEAD');
list($x2, $headMsg) = sh('git -C ' . escapeshellarg($MAIN) . ' log -1 --oneline');
echo "HEAD under test : " . $headMsg . "\n";
echo "full sha        : " . $head . "\n";

list($rc, $out) = sh('git -C ' . escapeshellarg($MAIN) . ' worktree add --detach ' . escapeshellarg($WT) . ' ' . escapeshellarg($head));
if ($rc !== 0) { fwrite(STDERR, "could not create worktree:\n" . $out . "\n"); exit(3); }
echo "clean worktree  : " . $WT . "\n";

$exit = 0;
try {
    echo "\n--- 1. REQUIRED SYMBOLS AT COMMITTED HEAD ---\n";
    $missingByTier = array();
    foreach ($REQUIRED as $r) {
        $path    = $WT . '/' . $r['file'];
        $present = is_file($path) && strpos((string)file_get_contents($path), $r['match']) !== false;
        if (!$present) { $missingByTier[$r['tier']][] = $r['symbol']; }
        printf("  [%-9s] %-48s %s\n", $r['tier'], $r['symbol'], $present ? 'PRESENT' : 'ABSENT');
    }

    echo "\n--- 2. APPLYING CALENDAR-OWNED FILES (no dependency copied) ---\n";
    $copied = 0;
    foreach ($CALENDAR_OWNED as $rel) {
        $ok = copyIn($MAIN, $WT, $rel);
        $copied += $ok ? 1 : 0;
        if (!$ok) { printf("  MISSING FROM WORKING TREE: %s\n", $rel); }
    }
    printf("  copied %d/%d Calendar-owned files\n", $copied, count($CALENDAR_OWNED));

    if ($SIM) {
        echo "\n  !! SIMULATION MODE - dependency files copied from the working tree.\n";
        echo "  !! This is a CAPABILITY CHECK of the gate, NOT the integration verdict.\n";
        foreach ($SIM_DEPS as $rel) {
            printf("  !!   %s %s\n", copyIn($MAIN, $WT, $rel) ? '+ok ' : 'MISS', $rel);
        }
    }

    echo "\n--- 3. RUNTIME ENTRY POINTS (executed, not inspected) ---\n";
    $svc = "require APPPATH.'libraries/Student_calendar_service.php';\n";
    $entry = array(
        array('CORE', 'resolveForDate() pure path',
            $svc . "\$r=Student_calendar_service::resolveForDate(array('effectiveFrom'=>'2026-04-01','weeklyOffs'=>array('Sun')),'2026-06-10');\n"
                 . "echo 'attendance='.var_export(\$r['attendance'],true);"),
        array('CORE', 'effectiveVersion()',
            $svc . "\$v=array(array('effectiveFrom'=>'2026-04-01','weeklyOffs'=>array('Sun')));\n"
                 . "echo 'selected='.var_export(Student_calendar_service::effectiveVersion(\$v,'2026-06-10')!==null,true);"),
        array('CORE', 'validateVersion()',
            $svc . "echo 'ok='.var_export(Student_calendar_service::validateVersion(array('effectiveFrom'=>'2026-04-01','weeklyOffs'=>array('Sun')))['ok'],true);"),
        array('CORE', 'validateVersionAgainst()',
            $svc . "echo 'ok='.var_export(Student_calendar_service::validateVersionAgainst(array('effectiveFrom'=>'2027-01-01','weeklyOffs'=>array('Sun')),array(),'2026-12-31')['ok'],true);"),
        array('CORE', 'reader->resolveMonth()',
            "require APPPATH.'libraries/Student_calendar_reader.php';\n"
          . "class GateFs{ function get(\$c,\$i){ return array('studentCalendar'=>array('versions'=>array(array('effectiveFrom'=>'2026-01-01','weeklyOffs'=>array('Sun'))))); } }\n"
          . "\$o=(new Student_calendar_reader())->init(new GateFs(),'SCH_GATE','2026-27')->resolveMonth(2026,6,array(),array());\n"
          . "echo 'nonWorking='.count(\$o['nonWorking']).' source='.\$o['source'];"),
        array('CORE', 'reader->resolveDate()',
            "require APPPATH.'libraries/Student_calendar_reader.php';\n"
          . "class GateFs2{ function get(\$c,\$i){ return array(); } }\n"
          . "\$o=(new Student_calendar_reader())->init(new GateFs2(),'SCH_GATE','2026-27')->resolveDate('2026-06-10');\n"
          . "echo 'instruction='.var_export(\$o['instruction'],true).' source='.\$o['source'];"),
        array('SAVE', 'store loads + save path wired',
            "require APPPATH.'libraries/Student_calendar_store.php';\n"
          . "require APPPATH.'libraries/Firebase.php';\n"
          . "if(!class_exists('Student_calendar_store')) throw new Exception('store class ABSENT');\n"
          . "if(!method_exists('Firebase','firestoreGetOrFail')) throw new Exception('Firebase::firestoreGetOrFail ABSENT');\n"
          . "if(!method_exists('Firebase','firestoreCommitBatchStatus')) throw new Exception('Firebase::firestoreCommitBatchStatus ABSENT');\n"
          . "echo 'save path wired';"),
        array('SWD', 'Holiday_service::special_working_days_in_month',
            "require APPPATH.'libraries/Holiday_service.php';\n"
          . "if(!method_exists('Holiday_service','special_working_days_in_month')) throw new Exception('ABSENT');\n"
          . "echo 'SWD reader present';"),
        array('READINESS', 'Leave_year_service::boundsForSession',
            "if(!file_exists(APPPATH.'libraries/Leave_year_service.php')) throw new Exception('file ABSENT');\n"
          . "require APPPATH.'libraries/Leave_year_service.php';\n"
          . "if(!method_exists('Leave_year_service','boundsForSession')) throw new Exception('method ABSENT');\n"
          . "echo 'session authority present';"),
    );

    $fail = array();
    foreach ($entry as $e) {
        list($tier, $name, $body) = $e;
        list($ok, $out) = probe($WT, $body);
        $first = '';
        foreach (explode("\n", $out) as $l) {
            if (stripos($l, 'error') !== false || stripos($l, 'ABSENT') !== false) { $first = trim($l); break; }
        }
        $shown = $ok ? $out : ($first !== '' ? $first : $out);
        printf("  %-4s [%-9s] %-42s %s\n", $ok ? 'PASS' : 'FAIL', $tier, $name,
            substr(str_replace("\n", ' ', $shown), 0, 76));
        if (!$ok) { $fail[$tier][] = $name; }
    }

    // --------------------------------------------------- 3b. REACHABILITY
    echo "\n--- 3b. REACHABILITY (route -> controller method) ---\n";

    // Apply Calendar's route lines to the clean base's routes.php.
    $routesPath = $WT . '/application/config/routes.php';
    if (is_file($routesPath)) {
        $r = file_get_contents($routesPath);
        if (strpos($r, "student_calendar") === false) {
            file_put_contents($routesPath, $r . "\n" . implode("\n", $CALENDAR_ROUTES) . "\n");
        }
    }

    // Apply the menu entry.
    $headerPath = $WT . '/application/views/include/header.php';
    if (is_file($headerPath)) {
        $h = file_get_contents($headerPath);
        if (strpos($h, $CALENDAR_MENU_HREF) === false) {
            file_put_contents($headerPath, $h . "\n<!-- CALENDAR --><li><a href=\"<?= base_url('student_calendar') ?>\">School Week</a></li>\n");
        }
    }

    // Extract public method names with the TOKENIZER, never a substring match:
    // substring collisions have produced seven false results in this programme.
    $reachBody =
        "\$ctrl = APPPATH.'controllers/Student_calendar.php';\n"
      . "if(!is_file(\$ctrl)) throw new Exception('controller ABSENT');\n"
      . "\$toks = token_get_all(file_get_contents(\$ctrl));\n"
      . "\$methods = array(); \$vis = null;\n"
      . "for(\$i=0;\$i<count(\$toks);\$i++){\n"
      . "  \$t=\$toks[\$i];\n"
      . "  if(is_array(\$t)){\n"
      . "    if(\$t[0]===T_PUBLIC){\$vis='public';}\n"
      . "    elseif(\$t[0]===T_PRIVATE||\$t[0]===T_PROTECTED){\$vis='other';}\n"
      . "    elseif(\$t[0]===T_FUNCTION){\n"
      . "      for(\$j=\$i+1;\$j<count(\$toks);\$j++){\n"
      . "        if(is_array(\$toks[\$j])&&\$toks[\$j][0]===T_STRING){\n"
      . "          if(\$vis==='public'){\$methods[]=\$toks[\$j][1];}\n"
      . "          \$vis=null; break;\n"
      . "        }\n"
      . "      }\n"
      . "    }\n"
      . "  }\n"
      . "}\n"
      . "\$routes = file_get_contents(APPPATH.'config/routes.php');\n"
      . "if(!preg_match_all(\"/\\\\\\\$route\\\\['student_calendar[^']*'\\\\]\\\\s*=\\\\s*'Student_calendar\\\\/([a-z_]+)'/\", \$routes, \$m))\n"
      . "  throw new Exception('NO student_calendar routes found');\n"
      . "\$missing = array();\n"
      . "foreach(\$m[1] as \$target){ if(!in_array(\$target,\$methods,true)) \$missing[]=\$target; }\n"
      . "if(\$missing) throw new Exception('route targets with NO public method: '.implode(',',\$missing));\n"
      . "echo count(\$m[1]).' routes -> all resolve to public methods';";

    list($rOk, $rOut) = probe($WT, $reachBody);
    $rFirst = '';
    foreach (explode("\n", $rOut) as $l) { if (stripos($l,'error')!==false || stripos($l,'Exception')!==false) { $rFirst=trim($l); break; } }
    printf("  %-4s [%-9s] %-42s %s\n", $rOk?'PASS':'FAIL', 'REACH', 'routes resolve to public methods',
        substr(str_replace("\n",' ', $rOk ? $rOut : ($rFirst?:$rOut)), 0, 76));
    if (!$rOk) { $fail['REACH'][] = 'route -> method resolution'; }

    // Menu entry must exist, or the screen is unreachable by a human.
    $menuOk = is_file($headerPath) && strpos(file_get_contents($headerPath), $CALENDAR_MENU_HREF) !== false;
    printf("  %-4s [%-9s] %-42s %s\n", $menuOk?'PASS':'FAIL', 'REACH', 'navigation entry present',
        $menuOk ? 'School Week menu item found' : 'menu entry MISSING');
    if (!$menuOk) { $fail['REACH'][] = 'navigation entry'; }

    echo "\n--- 4. VERDICT ---\n";
    $core = !isset($fail['CORE']);
    $all  = empty($fail);
    printf("  clean-base LOADS + RESOLVES (CORE) : %s\n", $core ? 'YES' : 'NO');
    printf("  clean-base FULLY FUNCTIONAL        : %s\n", $all ? 'YES' : 'NO');
    if (!$all) {
        echo "\n  BLOCKED TIERS:\n";
        foreach ($fail as $t => $names) {
            $syms = isset($missingByTier[$t]) ? $missingByTier[$t] : array();
            printf("    %-9s missing: %s\n", $t, $syms ? implode(', ', $syms) : '(see failures above)');
        }
        echo "\n  Calendar is NOT clean-base runnable. This is a SEQUENCING result,\n";
        echo "  not a defect: no Calendar code change can resolve it.\n";
        $exit = 1;
    } else {
        if ($SIM) {
            echo "\n  SIMULATION ONLY: the gate is proven CAPABLE of passing once the\n";
            echo "  dependencies land at HEAD. Calendar is NOT integrated. Exit forced to 2.\n";
            $exit = 2;
        } else {
            echo "\n  Calendar IS clean-base runnable at this HEAD.\n";
        }
    }
} catch (Throwable $e) {
    echo "GATE ERROR: " . $e->getMessage() . "\n";
    $exit = 3;
}

if ($KEEP) {
    echo "\n(worktree kept at " . $WT . ")\n";
} else {
    sh('git -C ' . escapeshellarg($MAIN) . ' worktree remove --force ' . escapeshellarg($WT));
    list($x3, $still) = sh('git -C ' . escapeshellarg($MAIN) . ' worktree list');
    echo "\nworktree removed; gate worktree still listed: "
       . (strpos($still, basename($WT)) !== false ? 'YES (INVESTIGATE)' : 'no') . "\n";
}
exit($exit);
