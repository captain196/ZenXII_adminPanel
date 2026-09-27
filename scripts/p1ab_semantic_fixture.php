<?php
/**
 * ══════════════════════════════════════════════════════════════════════════
 * PHASE 1 — A/B SEMANTIC EQUIVALENCE, DETERMINISTIC FIXTURE
 * ══════════════════════════════════════════════════════════════════════════
 *
 * The live tenant holds ZERO records in studentConcessions,
 * studentEntitlements, studentServiceEnrollments and scholarshipAwards
 * (verified platform-wide), so a live A/B is INCONCLUSIVE — there is nothing
 * to compare. This harness answers the question the live data cannot:
 *
 *   "Given a legacy concession, does the entitlement the migration WOULD
 *    produce bill the student identically?"
 *
 * ── WHAT IS REAL HERE ───────────────────────────────────────────────────────
 * The production classes, unmodified, executed for real:
 *   • Fee_concession_reader::appliesTo()          (legacy matcher)
 *   • Fee_entitlement_reader::appliesTo()         (new matcher, incl. I3)
 *   • Fee_generation_service::_applyDeductions()  (the SINGLE deduction
 *     algorithm both sides share) — reached by reflection, not re-implemented
 *   • The migration's own scope mapping, transcribed from
 *     scripts/migrate_student_entitlements.js::mapScope()
 *
 * ── WHY THIS IS NOT "FAKE EVIDENCE" ─────────────────────────────────────────
 * Nothing about the OUTCOME is authored here. The fixture supplies only the
 * INPUT records; every applies/type/value/arithmetic decision is made by the
 * production code. A divergence reported below is a property of the shipped
 * code, not of this file. It is clearly labelled FIXTURE, never live.
 *
 * ── READ-ONLY ───────────────────────────────────────────────────────────────
 * No Firestore connection is opened at all. No writes are possible.
 *
 * Usage:  php scripts/p1ab_semantic_fixture.php
 */

define('BASEPATH', __DIR__);
define('APPPATH',  realpath(__DIR__ . '/../application') . DIRECTORY_SEPARATOR);
define('ENVIRONMENT', 'production');

if (!function_exists('log_message')) { function log_message($l, $m) {} }
class CI_Controller { public static $instance; }
if (!function_exists('get_instance')) { function &get_instance() { return CI_Controller::$instance; } }

require_once APPPATH . 'libraries/Fee_concession_reader.php';
require_once APPPATH . 'libraries/Fee_entitlement_reader.php';
require_once APPPATH . 'libraries/Fee_generation_service.php';
require_once APPPATH . 'libraries/Entitlement_service.php';

function line($s = '') { echo $s . "\n"; }
function head($s) { line(); line('── ' . $s . ' ' . str_repeat('─', max(0, 64 - strlen($s)))); }

$legacy = new Fee_concession_reader();
$newRdr = new Fee_entitlement_reader();

/** The REAL deduction algorithm, reached without copying it. */
$gen = (new ReflectionClass('Fee_generation_service'))->newInstanceWithoutConstructor();
$applyDeductions = new ReflectionMethod('Fee_generation_service', '_applyDeductions');
$applyDeductions->setAccessible(true);

/** Transcribed verbatim from migrate_student_entitlements.js::mapScope(). */
function migrateScope(array $d): ?string
{
    $scope = strtolower(trim((string) ($d['scope'] ?? '')));
    if ($scope === 'all') return 'all';
    if ($scope === 'head') {
        // PHASE 2 / F1 — mirrors the FIXED migration: stable id first, then
        // the head NAME, so a legacy name-only concession is no longer dropped.
        $h = trim((string) ($d['targetFeeHeadId'] ?? ''));
        if ($h !== '') return 'head:' . $h;
        $n = trim((string) ($d['targetFeeHead'] ?? ''));
        return $n === '' ? null : 'head:' . $n;
    }
    if ($scope === 'category') {
        $c = trim((string) ($d['targetCategory'] ?? ''));
        return $c === '' ? null : 'category:' . $c;
    }
    return null;
}

/** Legacy `type` → baseline `valueType` (migration's VALUE_TYPE_MAP). */
function migrateValueType(string $t): ?string
{
    $m = ['percent' => 'percent', 'fixed' => 'fixed', 'fullexempt' => 'fullExempt'];
    return $m[strtolower(trim($t))] ?? null;
}

/** Build the entitlement the migration WOULD create for this legacy record. */
function migrateRecord(array $c): ?array
{
    $scope = migrateScope($c);
    $vt    = migrateValueType((string) ($c['type'] ?? ''));
    if ($scope === null || $vt === null) return null;
    return [
        'studentId'     => $c['studentId'] ?? '',
        'schoolId'      => $c['schoolId']  ?? '',
        'session'       => $c['session']   ?? null,
        'kind'          => 'concession',
        'scope'         => $scope,
        'valueType'     => $vt,
        'value'         => $vt === 'fullExempt' ? 0 : (float) ($c['value'] ?? 0),
        'effectiveFrom' => $c['effectiveFrom'] ?? '',
        'effectiveTo'   => ($c['effectiveTo'] ?? null) ?: null,
        'status'        => (strtolower((string) ($c['status'] ?? 'active')) === 'revoked') ? 'discontinued' : 'active',
    ];
}

/** A synthetic chart: one spec per (head, month). */
function specs(array $heads, array $periods): array
{
    $out = [];
    foreach ($heads as $h) {
        foreach ($periods as $p) {
            $out[] = ['demandId' => "D_{$h['id']}_{$p}", 'data' => [
                'periodKey'   => $p,
                'feeHeadId'   => $h['id'],
                'feeHead'     => $h['name'],
                'category'    => $h['category'] ?? '',
                'grossAmount' => $h['amount'],
                'netAmount'   => $h['amount'],
                'balance'     => $h['amount'],
                'status'      => 'unpaid',
            ]];
        }
    }
    return ['specs' => $out, 'assigned' => array_column($heads, 'name'), 'chartFound' => true];
}

function netMap(array $built): array
{
    $m = [];
    foreach ($built['specs'] as $s) $m[$s['demandId']] = round((float) ($s['data']['netAmount'] ?? 0), 2);
    ksort($m);
    return $m;
}

// ── the fixture cases ───────────────────────────────────────────────────────
$TUITION   = ['id' => 'HD_TUI', 'name' => 'Tuition Fee', 'category' => 'academic', 'amount' => 1000.0];
$NOID      = ['id' => '',       'name' => 'Lab Fee',     'category' => 'academic', 'amount' => 400.0];
$TRANSPORT = ['id' => 'HD_BUS', 'name' => 'Bus Fee',     'category' => 'transport', 'amount' => 800.0];
$PERIODS   = ['2026-04', '2026-09', '2027-01'];

$base = ['studentId' => 'STU_FIX', 'schoolId' => 'SCH_FIX', 'session' => '2026-27',
         'effectiveFrom' => '2026-04-01', 'effectiveTo' => null, 'status' => 'active'];

$cases = [
    'C1  scope=all percent 25'          => [$base + ['scope' => 'all', 'type' => 'percent', 'value' => 25], [$TUITION, $NOID]],
    'C2  scope=all fixed 200'           => [$base + ['scope' => 'all', 'type' => 'fixed',   'value' => 200], [$TUITION]],
    'C3  scope=all fullExempt'          => [$base + ['scope' => 'all', 'type' => 'fullExempt', 'value' => 0], [$TUITION]],
    'C4  scope=head by ID'              => [$base + ['scope' => 'head', 'type' => 'percent', 'value' => 50, 'targetFeeHeadId' => 'HD_TUI', 'targetFeeHead' => 'Tuition Fee'], [$TUITION, $NOID]],
    'C5  scope=head ID EMPTY, name set' => [$base + ['scope' => 'head', 'type' => 'percent', 'value' => 50, 'targetFeeHeadId' => '', 'targetFeeHead' => 'Lab Fee'], [$NOID]],
    'C6  scope=head ID set, head HAS NO id (name-match case)'
                                        => [$base + ['scope' => 'head', 'type' => 'percent', 'value' => 50, 'targetFeeHeadId' => 'Lab Fee', 'targetFeeHead' => 'Lab Fee'], [$NOID]],
    'C7  scope=category academic'       => [$base + ['scope' => 'category', 'type' => 'percent', 'value' => 10, 'targetCategory' => 'academic'], [$TUITION, $TRANSPORT]],
    'C8  scope=all vs SERVICE head (I3)'=> [$base + ['scope' => 'all', 'type' => 'percent', 'value' => 20], [$TUITION, $TRANSPORT]],
    'C9  window closes mid-session'     => [array_merge($base, ['effectiveTo' => '2026-09-30']) + ['scope' => 'all', 'type' => 'percent', 'value' => 30], [$TUITION]],
    'C10 revoked/discontinued'          => [array_merge($base, ['status' => 'revoked']) + ['scope' => 'all', 'type' => 'percent', 'value' => 25], [$TUITION]],
];

line('════════════════════════════════════════════════════════════════════════');
line(' PHASE 1 — A/B SEMANTIC EQUIVALENCE (DETERMINISTIC FIXTURE, NOT LIVE)');
line(' Real production matchers + real _applyDeductions(); no Firestore opened.');
line('════════════════════════════════════════════════════════════════════════');

$verdicts = [];
foreach ($cases as $label => [$conc, $heads]) {
    $built = specs($heads, $PERIODS);
    $ent   = migrateRecord($conc);

    // Legacy side — exactly the callables Fee_generation_service uses at :99-103.
    $oldOut = $applyDeductions->invoke($gen, $built, [$conc],
        fn(array $c, string $p, string $hId, string $h, string $cat): bool => $legacy->appliesTo($c, $p, $hId, $h, $cat),
        fn(array $c): string => (string) ($c['type']  ?? ''),
        fn(array $c): float  => (float)  ($c['value'] ?? 0)
    );

    if ($ent === null) {
        $verdicts[$label] = 'MISSING_NEW (migration cannot map this record — it is SKIPPED)';
        line();
        line("  {$label}");
        line('    legacy nets : ' . json_encode(netMap($oldOut)));
        line('    new         : record would not exist');
        line('    => MISSING_NEW');
        continue;
    }

    // New side — the callables the shadow uses at Fee_generation_service:233-238.
    $rules  = ($ent['status'] === 'active') ? [$ent] : [];   // reader filters status server-side
    $newOut = $applyDeductions->invoke($gen, $built, $rules,
        fn(array $e, string $p, string $hId, string $h, string $cat): bool => $newRdr->appliesTo($e, $p, $hId, $h, $cat),
        fn(array $e): string => $newRdr->deductionType($e),
        fn(array $e): float  => $newRdr->deductionValue($e)
    );

    // Legacy status filter is applied by ITS reader too — mirror it fairly.
    if (strtolower((string) $conc['status']) !== 'active') {
        $oldOut = $applyDeductions->invoke($gen, $built, [],
            fn($c, $p, $hId, $h, $cat) => false, fn($c) => '', fn($c) => 0.0);
    }

    $a = netMap($oldOut); $b = netMap($newOut);
    $same = ($a === $b);
    $verdicts[$label] = $same ? 'MATCH' : 'VALUE_DIVERGENCE';

    line();
    line("  {$label}");
    line('    migrated scope/type/value : ' . $ent['scope'] . ' / ' . $ent['valueType'] . ' / ' . $ent['value']);
    line('    legacy nets : ' . json_encode($a));
    line('    new    nets : ' . json_encode($b));
    line('    => ' . ($same ? 'MATCH' : '*** VALUE_DIVERGENCE ***'));
    if (!$same) {
        foreach ($a as $k => $v) if (($b[$k] ?? null) !== $v) line("       {$k}: legacy={$v} new=" . var_export($b[$k] ?? null, true));
        foreach ($b as $k => $v) if (!array_key_exists($k, $a)) line("       {$k}: legacy=(dropped) new={$v}");
    }
}

// ── the value types the new model allows but the algorithm ignores ──────────
head('VALUE-TYPE VOCABULARY GAP');

line('  Entitlement_service::VALUE_TYPES = ' . implode(' | ', (new ReflectionClass('Entitlement_service'))->getConstant('VALUE_TYPES')));
line('  _applyDeductions handles         = percent | fixed | fullExempt');
$amt = $base + ['scope' => 'all', 'type' => 'percent', 'value' => 40];
$entAmt = migrateRecord($amt); $entAmt['valueType'] = 'amountDue'; $entAmt['value'] = 250;
$builtA = specs([$TUITION], ['2026-04']);
$outA = $applyDeductions->invoke($gen, $builtA, [$entAmt],
    fn(array $e, string $p, string $hId, string $h, string $cat): bool => $newRdr->appliesTo($e, $p, $hId, $h, $cat),
    fn(array $e): string => $newRdr->deductionType($e),
    fn(array $e): float  => $newRdr->deductionValue($e)
);
line("  an ACTIVE entitlement valueType='amountDue' value=250 on a 1000 head");
line('  → net produced by the real algorithm: ' . json_encode(netMap($outA)) . '  (expected 750 if honoured)');

head('SUMMARY');
foreach ($verdicts as $l => $v) printf("  %-58s %s\n", $l, $v);
$counts = array_count_values(array_map(fn($v) => explode(' ', $v)[0], $verdicts));
line();
line('  ' . json_encode($counts));
line();
line('── end (fixture only; no Firestore, no writes) ──');
