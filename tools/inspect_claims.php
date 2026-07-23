<?php
/**
 * READ-ONLY claim inspector — debugging "PERMISSION_DENIED everywhere" /
 * Teacher-app attendance "Not permitted." for a specific account.
 *
 * Does NOT write anything. Only reads the Firebase Auth user record and
 * (best-effort) the schoolControl tenant-gate doc.
 *
 * Usage:
 *   php tools/inspect_claims.php <login-or-userId>
 *   php tools/inspect_claims.php <full-email@schoolsync.app>
 *
 * Examples:
 *   php tools/inspect_claims.php STA0067
 *   php tools/inspect_claims.php sta0067@schoolsync.app
 */

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';

use Kreait\Firebase\Factory;

$saPath = $root . '/application/config/graderadmin-firebase-adminsdk-a1sml-2b5f1862a7.json';

if ($argc < 2) {
    fwrite(STDERR, "Usage: php tools/inspect_claims.php <login-or-userId | email@schoolsync.app>\n");
    exit(2);
}

$arg = trim($argv[1]);
$email = (strpos($arg, '@') !== false) ? strtolower($arg) : strtolower($arg) . '@schoolsync.app';

function line($k, $v) { printf("  %-22s %s\n", $k, $v); }

echo "── Claim inspector (READ-ONLY) ─────────────────────────────\n";
line('service account', basename($saPath));
line('lookup email', $email);
echo "\n";

try {
    $auth = (new Factory())->withServiceAccount($saPath)->createAuth();
} catch (\Throwable $e) {
    fwrite(STDERR, "FATAL: could not init Firebase Auth: " . $e->getMessage() . "\n");
    exit(1);
}

try {
    $user = $auth->getUserByEmail($email);
} catch (\Throwable $e) {
    fwrite(STDERR, "USER NOT FOUND for email $email  (" . $e->getMessage() . ")\n");
    fwrite(STDERR, "→ If the login is right, the Firebase Auth user may not exist (account never provisioned).\n");
    exit(1);
}

$claims = (array) $user->customClaims;

echo "AUTH RECORD\n";
line('uid', $user->uid);
line('disabled', $user->disabled ? 'YES  <-- disabled account' : 'no');
line('email', $user->email);
echo "\n";

echo "CUSTOM CLAIMS (raw)\n";
if (empty($claims)) {
    echo "  <EMPTY — no custom claims at all>\n";
} else {
    foreach ($claims as $k => $v) {
        line($k, is_scalar($v) ? var_export($v, true) : json_encode($v));
    }
}
echo "\n";

// ── Diagnosis ───────────────────────────────────────────────
$snake = $claims['school_id']  ?? null;   // what Firestore rules + backend read
$camel = $claims['schoolId']   ?? null;   // what web admin-login reads
$role  = $claims['role']       ?? null;

echo "DIAGNOSIS\n";
$problems = [];

if (empty($claims))                      $problems[] = "No custom claims → token has no school_id/role → denied everywhere. Account was never claim-minted (or minting failed).";
if ($snake === null)                     $problems[] = "MISSING snake `school_id` → Firestore rules tenantActive() + backend authz both fail → PERMISSION_DENIED / 403.";
elseif ($snake === '' )                  $problems[] = "BLANK snake `school_id` (empty string) → tenantActive(get schoolControl/'') = null → denied everywhere. Creator's session/token had no school_id at create time.";
if ($camel === null)                     $problems[] = "MISSING camel `schoolId` → web admin login would fail (ADMIN_LOGIN_AUTHZ_MISSING). Apps still OK if snake is present.";
if ($snake !== null && $camel !== null && $snake !== $camel)
                                         $problems[] = "MISMATCH: school_id ('$snake') != schoolId ('$camel') → casing contract broken.";
if ($role === null || $role === '')      $problems[] = "MISSING/blank `role` → backend attendance authz may 403 even if school_id is fine.";

if (empty($problems)) {
    line('school_id (snake)', var_export($snake, true));
    line('schoolId (camel)',  var_export($camel, true));
    line('role', var_export($role, true));
    echo "  ✓ Claims look structurally valid. If still denied, suspects:\n";
    echo "     1) App token is STALE (not force-refreshed) — reopen app / re-login forces getIdToken(true).\n";
    echo "     2) schoolControl/{$snake} lifecycle not active, or schools/{$snake}.adminDisabled=true (whole-tenant gate).\n";
    echo "     3) Teacher has no subjectAssignments doc with isClassTeacher=true (read-only, not '403 not permitted').\n";
} else {
    foreach ($problems as $i => $p) {
        echo "  [" . ($i + 1) . "] " . $p . "\n";
    }
    echo "\n  FIX: re-mint canonical claims via controllers/Auth_claims_backfill.php\n";
    echo "       (only after confirming the SOURCE value of school_id for this user is correct).\n";
}
echo "────────────────────────────────────────────────────────────\n";
