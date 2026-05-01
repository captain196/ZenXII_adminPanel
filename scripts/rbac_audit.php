<?php
/**
 * rbac_audit.php — static audit of admin-controller access guards.
 *
 * Scans every public method on every controller under
 * application/controllers/ and flags:
 *
 *   A. missing `_require_role(...)` / `_require_post(...)` guard
 *   B. public methods that appear to touch Firestore collections
 *      WITHOUT resolving `schoolId` through the admin context
 *      ($this->school_name, $this->fs->schoolId(), or similar) —
 *      i.e. candidate multi-tenant-bypass surfaces
 *
 * This is a LINT-GRADE heuristic check — not a proof. The output is
 * a triage list: sort by "no guard" first, work through manually.
 *
 * Skipped:
 *   — Super-admin controllers (their whole surface is privileged
 *     and uses a different guard helper).
 *   — Public callbacks intentionally open (Admission_public, the
 *     payment_intent_listener webhook, Admin_login itself).
 *
 * Usage:
 *   php scripts/rbac_audit.php                 — text report
 *   php scripts/rbac_audit.php --json          — JSON output
 *   php scripts/rbac_audit.php --only=Fees     — scope to one controller
 *
 * Exit code: 0 if no critical gaps, 1 if ≥1 unguarded public method
 * with a Firestore touchpoint found.
 */

$root = realpath(__DIR__ . '/..');
$ctrlDir = $root . '/application/controllers';
if (!is_dir($ctrlDir)) { fwrite(STDERR, "controllers dir not found\n"); exit(2); }

$opts = [];
foreach (array_slice($argv, 1) as $a) {
    if (strncmp($a, '--', 2) !== 0) continue;
    $a = substr($a, 2);
    $eq = strpos($a, '=');
    $opts[$eq === false ? $a : substr($a, 0, $eq)] = $eq === false ? true : substr($a, $eq + 1);
}
$json = !empty($opts['json']);
$only = (string) ($opts['only'] ?? '');

$skipControllers = [
    'Admin_login',                 // auth entry — intentional
    'Admission_public',            // public admission form
    'Payment_intent_listener',     // webhook (signature-checked instead)
    'Health_check',                // unauth probe
    'Backup_cron', 'Retry_worker', // key-secret endpoints
    'Fees_backup',                 // backup copy of Fees.php; not routed
    'Fee_simulation',              // dev sandbox only
    'Otp_service',                 // OTP send — phone-auth protected
    'Superadmin',                  // super-admin top-level index
    'Superadmin_login',            // super-admin auth
];
$skipRegex = '/^(Superadmin_|MY_)/'; // super-admin tier uses its own guard

// Method-level skiplist — specific public entry points where absence
// of _require_role is intentional (auth layer handles it upstream).
$skipMethods = [
    'Fee_management::parent_create_order'    => true, // Api_auth via _parent_claims
    'Fee_management::parent_verify_payment'  => true, // Api_auth via _parent_claims
    'Fee_management::payment_webhook'        => true, // webhook signature
    'Sis::online_form'                       => true, // public admission page
    'Sis::submit_online_form'                => true, // public admission submit
    'Attendance::api_punch'                  => true, // device-token auth
    'Stories::upload_story'                  => true, // teacher app signed request
];

$results = [];
foreach (glob($ctrlDir . '/*.php') as $file) {
    $base = basename($file, '.php');
    if ($only !== '' && stripos($base, $only) === false) continue;
    if (in_array($base, $skipControllers, true) || preg_match($skipRegex, $base)) continue;

    $src = (string) file_get_contents($file);
    // Lexically scan for public methods. Deliberately avoids
    // token_get_all complexity — a `public function x(…)` grep is
    // good enough for an audit triage.
    if (!preg_match_all('/^\s*public\s+function\s+([a-zA-Z_][a-zA-Z0-9_]*)\s*\(/m',
        $src, $m, PREG_OFFSET_CAPTURE)) continue;

    foreach ($m[1] as $match) {
        $method = $match[0];
        // Skip CodeIgniter / PHP lifecycle hooks.
        if (in_array($method, ['__construct','__destruct','__call','__get','__set','_remap'], true)) continue;
        // Method-level skiplist for intentionally-public entry points.
        if (!empty($skipMethods["{$base}::{$method}"])) continue;

        $methodStart = $match[1];
        $bodyStart   = strpos($src, '{', $methodStart);
        if ($bodyStart === false) continue;
        // Find matching close-brace by balancing. Good enough for
        // well-formed PHP; worst-case we over-include.
        $depth = 0; $end = $bodyStart;
        for ($i = $bodyStart, $n = strlen($src); $i < $n; $i++) {
            $c = $src[$i];
            if ($c === '{') $depth++;
            elseif ($c === '}') { $depth--; if ($depth === 0) { $end = $i; break; } }
        }
        $body = substr($src, $bodyStart, $end - $bodyStart + 1);

        // Guard detection — any of these counts as a guard.
        // Broad pattern: any $this->_require_* invocation. Catches
        // module-local helpers like _require_admin / _require_view
        // (Ats) alongside the canonical _require_role.
        $hasRoleGuard = (bool) preg_match('/\$this->(_require_role|require_admin_access|_require_admin|_require_view|_require_manage|_require_super|_require_hr|_require_accounting)\s*\(/', $body);
        $hasAnyGuard  = $hasRoleGuard
                     || (bool) preg_match('/\$this->_require_[a-z_]+\s*\(/', $body)  // generic fallback
                     // Parent-API endpoints are authenticated by Api_auth
                     // via the _parent_claims property populated upstream.
                     || (bool) preg_match('/\$this->_parent_claims/', $body)
                     // Webhook signature check (payment_intent_listener etc.)
                     || (bool) preg_match('/verify_(webhook|signature|razorpay_signature)\s*\(/', $body)
                     // CI hook / private helper / cron key already handled elsewhere.
                     || (bool) preg_match('/\$this->_require_cron_key|\bassert_cron_key\b/', $body)
                     // Super-admin / role helpers shipped as top-level functions.
                     || (bool) preg_match('/\bis_super_admin\(|require_super_admin\(|require_admin\(/', $body)
                     // Inline role check — some controllers do it manually
                     // instead of calling a helper: `if (!in_array($this->admin_role, [...])) show_error(403)`
                     || (bool) preg_match('/in_array\s*\(\s*\$this->admin_role/', $body);

        // Firestore-touch detection.
        $touchesFs    = (bool) preg_match('/firestore(Set|Get|Query|Delete|CommitBatch|Create)/', $body)
                     || (bool) preg_match('/\$this->fs(?![a-zA-Z])/', $body)
                     || (bool) preg_match('/\$this->fsTxn\b/', $body);

        // School-scoping detection — any of these patterns counts as
        // scoping this endpoint's Firestore access to the session's school.
        $scopesSchool = (bool) preg_match('/\$this->(school_name|fs->schoolId|session_year|school_id|school_code)/', $body)
                     // Explicit schoolId filter in a query.
                     || (bool) preg_match("/['\"]schoolId['\"]\s*,\s*['\"]==['\"]/", $body)
                     // Firestore_service helpers that inject schoolId.
                     // (schoolWhere / schoolQuery / get / set / docId /
                     //  getEntity / setEntity / removeEntity / remove / exists
                     //  updateEntity / setCollection / etc)
                     || (bool) preg_match('/->fs->(schoolWhere|schoolQuery|get|set|docId|docId2|docId3|getEntity|setEntity|updateEntity|removeEntity|remove|exists|update|setCollection)\b/', $body)
                     // Private controller helpers whose whole purpose is
                     // school-scoped CRUD (convention: _crm_*, _doc_id_*,
                     // _school_*, _safe_*). A common pattern for modules
                     // that encapsulate their Firestore plumbing.
                     || (bool) preg_match('/\$this->(_crm_|_school_|_doc_id_|_tenant_|_scoped_)[a-z_]+\(/', $body)
                     // MY_Controller RBAC helpers introduced in Phase 4.
                     || (bool) preg_match('/\$this->(school_scoped_where|assert_school_owned_doc|assert_school_ownership|require_admin_access)\s*\(/', $body)
                     // Parent/teacher-side auth pulls schoolId from claims.
                     || (bool) preg_match('/\$this->_parent_claims.*schoolId/s', $body)
                     // Doc ID pattern that embeds school ({schoolId}_…)
                     || (bool) preg_match('/\{\$?(this->)?school[_a-zA-Z]*\}/', $body)
                     // Common helper _admin_school / _resolve_school etc.
                     || (bool) preg_match('/_admin_school\(|_resolve_school\(|resolveSchoolId\(/', $body)
                     // Phase 2/3 Fees-module libraries that are inited
                     // per-request with schoolId and then scoped forever.
                     || (bool) preg_match('/\$this->(fsTxn|fsSync|fsSyncInline|feeAuditLogger|feeAlerts)\b/', $body);

        $gaps = [];
        if (!$hasAnyGuard)  $gaps[] = 'no _require_role/_require_post';
        if ($touchesFs && !$scopesSchool) $gaps[] = 'Firestore touch without schoolId scoping';

        // Risk classification — method names that smell like writes or
        // destructive admin actions are the most urgent to fix. Pure
        // index()/view() landing pages (just load a view with no side
        // effects) are LOW risk even without _require_role because
        // MY_Controller already enforces authentication in its ctor.
        $mutatorRegex = '/^(save|update|delete|create|add|submit|approve|reject|move|migrate|finalize|regenerate|change|upload|allocate|promote|cancel|issue|revoke|close|dismiss|acknowledge|moderate|lock|unlock|reset|disable|enable|set_)/i';
        $readerRegex  = '/^(get_|fetch_|list_|view|load|index|search|show|my_|preflight_|test_|profile)/i';
        if (preg_match($mutatorRegex, $method))      $risk = 'HIGH';
        elseif (preg_match($readerRegex,  $method))  $risk = 'LOW';
        else                                         $risk = 'MED';
        // Escalate: any mutator that ALSO lacks school scoping is
        // critical — it can rewrite another school's data.
        if ($risk === 'HIGH' && in_array('Firestore touch without schoolId scoping', $gaps, true)) $risk = 'CRIT';

        if ($gaps) {
            $results[] = [
                'controller' => $base,
                'method'     => $method,
                'gaps'       => $gaps,
                'risk'       => $risk,
                'touchesFs'  => $touchesFs,
                'hasGuard'   => $hasAnyGuard,
            ];
        }
    }
}

// Rank by risk (CRIT > HIGH > MED > LOW). Inside a tier, keep a
// deterministic secondary sort on controller+method so re-runs match.
$riskRank = ['CRIT' => 0, 'HIGH' => 1, 'MED' => 2, 'LOW' => 3];
usort($results, function ($a, $b) use ($riskRank) {
    $r = $riskRank[$a['risk']] <=> $riskRank[$b['risk']];
    if ($r !== 0) return $r;
    $c = strcmp($a['controller'], $b['controller']);
    return $c !== 0 ? $c : strcmp($a['method'], $b['method']);
});

// Bucket counts for the triage header.
$byRisk = ['CRIT'=>0,'HIGH'=>0,'MED'=>0,'LOW'=>0];
foreach ($results as $r) $byRisk[$r['risk']]++;

if ($json) {
    echo json_encode([
        'findings' => $results,
        'count'    => count($results),
        'byRisk'   => $byRisk,
    ], JSON_PRETTY_PRINT) . "\n";
} else {
    if (empty($results)) {
        echo "OK — every scanned public method has a guard and Firestore touches are school-scoped.\n";
    } else {
        echo "RBAC audit — " . count($results) . " finding(s) ";
        echo "(CRIT={$byRisk['CRIT']} HIGH={$byRisk['HIGH']} MED={$byRisk['MED']} LOW={$byRisk['LOW']})\n";
        echo str_pad('#', 4) . str_pad('RISK', 6) . str_pad('CONTROLLER', 24)
           . str_pad('METHOD', 38) . "GAPS\n";
        echo str_repeat('-', 118) . "\n";
        foreach ($results as $i => $r) {
            echo str_pad((string)($i+1), 4)
               . str_pad($r['risk'], 6)
               . str_pad($r['controller'], 24)
               . str_pad($r['method'], 38)
               . implode(' / ', $r['gaps']) . "\n";
        }
        echo "\nPriority: fix CRIT (write + cross-school) then HIGH (write, no guard).\n";
        echo "LOW-risk rows are landing pages/read-only; MY_Controller already enforces auth.\n";
    }
}
exit(empty($results) ? 0 : 1);
