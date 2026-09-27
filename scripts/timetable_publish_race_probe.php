<?php
/**
 * PUBLISH RACE PROBE — drives the REAL Timetable_plan_store::publishCandidate().
 *
 * `POST /timetable_lifecycle/publish` reaches the projection writer through
 * publishCandidate(). That is a WEB path that writes the collection both apps
 * read, and the question it raises — can two administrators publishing at the
 * same time produce competing projection executions? — cannot be answered by
 * reading the source or by calling one method twice in a row.
 *
 * This probe exists so two OS processes can enter publishCandidate() at the
 * same instant against the same tenant, exactly as two browser requests would.
 * It re-implements nothing: it bootstraps the same Firestore client the web
 * request uses, adapts its method names to the four the store calls, and hands
 * control to the production method.
 *
 * ── SAFETY ──────────────────────────────────────────────────────────────
 * Refuses to run unless FIRESTORE_EMULATOR_HOST points at a loopback emulator.
 * It issues real publishes and real projections through production code.
 *
 *   FIRESTORE_EMULATOR_HOST=127.0.0.1:8080 php scripts/timetable_publish_race_probe.php \
 *       --school=SCH_X --session=2026-27 --plan=PLAN1 --version=V1 \
 *       --from=2026-09-01 [--to=] [--gate=/path/to/gatefile] [--admin=STA0001]
 *
 * --gate makes the race sharp: the process opens, does its setup, then spins
 * until the gate file appears, so both processes cross into publishCandidate()
 * within the same few milliseconds rather than whenever PHP finished booting.
 *
 * Prints one JSON object. exit 0 = published, 2 = refused/failed.
 */

$EMU = trim((string) getenv('FIRESTORE_EMULATOR_HOST'));
$host = preg_replace('~^https?://~', '', $EMU);
$host = explode(':', $host)[0] ?? '';
if ($EMU === '' || !in_array($host, ['127.0.0.1', 'localhost'], true)) {
    fwrite(STDERR, "REFUSING: FIRESTORE_EMULATOR_HOST must point at a loopback emulator.\n");
    exit(1);
}

define('BASEPATH', __DIR__ . '/../system/');
define('APPPATH',  __DIR__ . '/../application/');
if (!function_exists('log_message')) { function log_message($level, $msg) {} }

require_once APPPATH . 'libraries/Firestore_rest_client.php';
require_once APPPATH . 'libraries/Timetable_plan_store.php';

function arg(string $k, string $d = ''): string {
    foreach ($GLOBALS['argv'] as $a) {
        if (strpos($a, "--$k=") === 0) return substr($a, strlen($k) + 3);
    }
    return $d;
}

$SCHOOL  = arg('school');
$SESSION = arg('session');
$PLAN    = arg('plan');
$VERSION = arg('version');
$FROM    = arg('from');
$TO      = arg('to');
$ADMIN   = arg('admin', 'STA_PROBE');
$GATE    = arg('gate');

if ($SCHOOL === '' || $SESSION === '' || $PLAN === '' || $VERSION === '' || $FROM === '') {
    fwrite(STDERR, "--school --session --plan --version --from are required\n");
    exit(1);
}

/**
 * The four calls Timetable_plan_store makes, forwarded to the same REST client
 * the web request uses. Nothing here decides anything; it renames methods.
 */
final class ProbeFirebaseAdapter
{
    public function __construct(private FirestoreRestClient $c) {}

    public function firestoreQueryOrFail($collection, $conditions = [], $orderBy = null,
                                         $dir = 'ASC', $limit = null) {
        return $this->c->queryOrFail($collection, $conditions, $orderBy, $dir, $limit);
    }
    public function firestoreGetOrFail($collection, $docId) {
        return $this->c->getDocumentOrFail($collection, $docId);
    }
    public function firestoreSet($collection, $docId, $data, $merge = false) {
        return $this->c->setDocument($collection, $docId, $data, $merge);
    }
    public function firestoreDelete($collection, $docId) {
        return $this->c->deleteDocument($collection, $docId);
    }
    public function firestoreCommitBatchStatus($ops) {
        return $this->c->commitBatchStatus($ops);
    }
}

// The same service account the web request uses. Against the emulator no
// token is ever minted from it (Firestore_rest_client skips OAuth entirely
// when FIRESTORE_EMULATOR_HOST is set), but the client requires the file.
$saPath = APPPATH . 'config/graderadmin-firebase-adminsdk-a1sml-2b5f1862a7.json';
if (!is_file($saPath)) {
    fwrite(STDERR, "Service account not found at $saPath
"); exit(1);
}

$client = new FirestoreRestClient($saPath, 'graderadmin', '(default)');
$adapter = new ProbeFirebaseAdapter($client);

// The REAL audit service, wired the way the controller wires it. Without this
// the probe would prove the transition and prove nothing about its record —
// and "the audit call was made" is exactly the claim that has been wrong twice
// in this module. Audit_log_service writes through firestoreSet, which the
// adapter already forwards.
require_once APPPATH . 'libraries/Audit_log_service.php';
$audit = (new Audit_log_service())->init(
    $adapter, $SCHOOL, $SESSION,
    ['uid' => $ADMIN, 'name' => $ADMIN, 'role' => 'Admin']);

$store  = (new Timetable_plan_store())->init(
    $adapter, $SCHOOL, $SESSION, $ADMIN, $audit);

// ── setup mode: record a REAL verdict on the candidate ──────────────────
//
// publishCandidate() refuses a version whose recorded check no longer
// describes its content, and the fingerprint that decides that is computed by
// Timetable_validation_service. Hand-writing one would be inventing a value
// the production code would reject — so the real function computes it over the
// real days, exactly as a check run would.
if (arg('mode') === 'stamp-verdict') {
    $days = $store->listCandidateDays($PLAN, $VERSION);
    $fp   = Timetable_validation_service::fingerprint($days);
    $client->setDocument('timetableVersions',
        "{$SCHOOL}_{$SESSION}_{$PLAN}_{$VERSION}",
        ['validation' => ['hard' => [], 'soft' => [], 'hardCount' => 0,
                          'softCount' => 0, 'publishable' => true,
                          'fingerprint' => $fp],
         'validationStale' => false],
        true);
    echo json_encode(['stamped' => $VERSION, 'days' => count($days),
                      'fingerprint' => substr($fp, 0, 12)]), "\n";
    exit(0);
}

// ── supersede mode: drive the REAL Timetable_plan_store::supersedeVersion ──
//
// Same process shape as a publish, so two OS processes can enter supersede —
// or one supersede and one publish — at the same instant through the real
// Firestore client. --last and --reason carry the transition's own arguments.
if (arg('mode') === 'supersede') {
    if (arg('gate') !== '') {
        $spun = 0;
        while (!is_file(arg('gate'))) { usleep(500); $spun++; if ($spun > 20000000) break; }
    }
    $t0 = microtime(true);
    try {
        $res = $store->supersedeVersion($PLAN, $VERSION,
            arg('last'), arg('reason', 'closed by the race proof'));
        echo json_encode(['admin' => $ADMIN, 'outcome' => 'superseded'] + $res), "\n";
        exit(0);
    } catch (\Throwable $e) {
        echo json_encode([
            'admin'   => $ADMIN,
            'outcome' => 'refused',
            'code'    => property_exists($e, 'errorType') ? $e->errorType : '',
            'message' => $e->getMessage(),
            'ms'      => (int) ((microtime(true) - $t0) * 1000),
        ]), "\n";
        exit(2);
    }
}

// ── the starting gun ────────────────────────────────────────────────────
// Everything above is process startup, which varies by hundreds of
// milliseconds. Waiting here means both processes call publishCandidate()
// together, which is the only way the read-then-write window is actually
// tested rather than stepped over.
if ($GATE !== '') {
    $spun = 0;
    while (!is_file($GATE)) { usleep(500); $spun++; if ($spun > 20000000) break; }
}

$t0 = microtime(true);
try {
    $res = $store->publishCandidate($PLAN, $VERSION, $FROM, $TO);
    echo json_encode([
        'admin'      => $ADMIN,
        'outcome'    => 'published',
        'versionId'  => $res['versionId'] ?? null,
        'overlaps'   => array_column($res['overlaps'] ?? [], 'versionId'),
        'projection' => $res['projection'] ?? null,
        'ms'         => (int) ((microtime(true) - $t0) * 1000),
    ]), "\n";
    exit(0);
} catch (\Throwable $e) {
    echo json_encode([
        'admin'   => $ADMIN,
        'outcome' => 'refused',
        // errorType, NOT getCode(): Service_exception passes 0 to the parent
        // constructor, so getCode() is always 0 and every code-based assertion
        // on a publish refusal was vacuously false. The supersede branch above
        // already reported the right field.
        'code'    => property_exists($e, 'errorType') ? $e->errorType : '',
        'message' => $e->getMessage(),
        'ms'      => (int) ((microtime(true) - $t0) * 1000),
    ]), "\n";
    exit(2);
}
