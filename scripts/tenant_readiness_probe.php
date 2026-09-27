<?php
/**
 * ══════════════════════════════════════════════════════════════════════════
 * TENANT READINESS PROBE — WHAT MUST THIS SCHOOL STILL DO?  (READ-ONLY)
 * ══════════════════════════════════════════════════════════════════════════
 *
 * Prints the canonical Institution_readiness verdict for one tenant, plus the
 * financial-activation eligibility that go-live would evaluate.
 *
 * WHY IT EXISTS: the question "can a brand-new school reach normal operation
 * without a developer?" is answerable only by asking the same scorer the
 * product asks, against a tenant that has been through the real lifecycle.
 * Reading the source tells you what the checks ARE; only running them tells
 * you which ones a fresh tenant actually trips.
 *
 * Writes nothing. Institution_readiness is a pure scorer by construction —
 * it stores no state and owns no domain data — and eligibility() is read-only.
 *
 *   FIRESTORE_EMULATOR_HOST=127.0.0.1:8092 php scripts/tenant_readiness_probe.php SCH_XXX
 */

define('BASEPATH', __DIR__);
define('APPPATH', __DIR__ . '/../application/');
if (!function_exists('log_message')) { function log_message($l, $m) {} }

require_once APPPATH . 'libraries/Firestore_rest_client.php';

$schoolId = $argv[1] ?? '';
if ($schoolId === '') { fwrite(STDERR, "usage: php scripts/tenant_readiness_probe.php <SCHOOL_ID>\n"); exit(2); }

$emu = getenv('FIRESTORE_EMULATOR_HOST');
$sa  = APPPATH . 'config/graderadmin-firebase-adminsdk-a1sml-2b5f1862a7.json';
$fs  = new FirestoreRestClient($sa, 'graderadmin', '(default)');

/**
 * Institution_readiness and the activation service expect the Firebase
 * wrapper's method names. This adapter forwards to the REST client rather
 * than reimplementing anything — a second reader would be a second answer.
 */
$fb = new class($fs) {
    public function __construct(private $c) {}
    public function firestoreGet($col, $id): ?array { return $this->c->getDocument($col, $id); }
    public function firestoreQuery($col, $where, $o = null, $d = 'ASC', $l = 500): array
    {
        $w = array_map(static function ($f) {
            return [$f[0], $f[1] === '==' ? '=' : $f[1], $f[2]];
        }, (array) $where);
        return $this->c->query($col, $w, $o, $d, $l);
    }
    public function firestoreCount($col, $where): int
    {
        return count($this->firestoreQuery($col, $where, null, 'ASC', 1000));
    }
};

echo "════════════════════════════════════════════════════════════════════\n";
echo " TENANT READINESS — {$schoolId}\n";
echo ' target: ' . ($emu ? "EMULATOR {$emu}" : 'LIVE (read-only)') . "\n";
echo "════════════════════════════════════════════════════════════════════\n";

require_once APPPATH . 'libraries/Institution_readiness.php';
$rd = (new Institution_readiness())->init($fb)->evaluate($schoolId);

printf("\n  score %s   blocking=%d  warnings=%d  goLiveAllowed=%s\n\n",
    (string) ($rd['score'] ?? '?'), (int) ($rd['blocking'] ?? 0), (int) ($rd['warnings'] ?? 0),
    !empty($rd['goLiveAllowed']) ? 'YES' : 'no');

foreach ((array) ($rd['checks'] ?? []) as $c) {
    $st = (string) ($c['status'] ?? '?');
    if ($st === 'NOT_APPLICABLE') continue;      // module the tenant did not buy
    printf("  %-9s %-26s %s\n", $st, (string) ($c['key'] ?? ''), (string) ($c['what'] ?? ''));
    if ($st === 'BLOCKING' && !empty($c['fix'])) printf("            → %s\n", (string) $c['fix']);
}

// What go-live would decide about the financial capability chain.
require_once APPPATH . 'libraries/Financial_activation_service.php';
$el = (new Financial_activation_service())->init($fb, 'probe')->eligibility($schoolId);
echo "\n  ── financial activation ──────────────────────────────────────\n";
printf("  eligible : %s\n  status   : %s\n", !empty($el['eligible']) ? 'YES' : 'no', (string) $el['status']);
if (!empty($el['reason'])) printf("  reason   : %s\n", (string) $el['reason']);
if (!empty($el['detail'])) printf("  detail   : %s\n", (string) $el['detail']);

echo "\n  documents written by this probe: 0\n\n";
