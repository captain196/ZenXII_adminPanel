<?php
/**
 * MUTATION BATTERY — IS THE PUBLISH CAS LOAD-BEARING?
 *
 * A concurrency guard that is never exercised looks exactly like one that
 * works. Each mutant below removes or weakens one piece of the TT-C8 fix and
 * re-runs the proof that is supposed to depend on it. A SURVIVING mutant means
 * the protection is decorative and the proof is not proving it.
 *
 * The emulator race proof is the target for the concurrency mutants, because
 * only two real processes can show the window; the unit suite is the target
 * for the predicate mutants, which are deterministic.
 *
 * Restores every file on exit, including on a fatal error. Never run two of
 * these over the same files at once.
 *
 *   FIRESTORE_EMULATOR_HOST=127.0.0.1:8080 php scripts/timetable_publish_cas_mutation.php
 */

$EMU = trim((string) getenv('FIRESTORE_EMULATOR_HOST'));
if ($EMU === '' || !preg_match('~^(127\.0\.0\.1|localhost):~', $EMU)) {
    fwrite(STDERR, "REFUSING: FIRESTORE_EMULATOR_HOST must point at a loopback emulator.\n");
    exit(1);
}

$ROOT  = str_replace('\\', '/', dirname(__DIR__));
$STORE = "$ROOT/application/libraries/Timetable_plan_store.php";
$SVC   = "$ROOT/application/libraries/Timetable_plan_service.php";

$raw = []; $orig = []; $crlf = [];
foreach ([$STORE, $SVC] as $f) {
    $raw[$f]  = file_get_contents($f);
    $crlf[$f] = strpos($raw[$f], "\r\n") !== false;
    $orig[$f] = str_replace("\r\n", "\n", $raw[$f]);
}
register_shutdown_function(function () use ($raw) {
    foreach ($raw as $f => $c) { file_put_contents($f, $c); }
    echo "\n(files restored)\n";
});

/**
 * @var array<int, array{0:string,1:string,2:string,3:string,4:string}>
 *   label, file, from, to, how-to-detect ('race' | phpunit filter)
 */
$MUTANTS = [

    // ── the CAS itself ───────────────────────────────────────────────────
    ['the guard write carries a precondition at all', $STORE,
     "            'precondition' => \$guardToken !== ''\n"
     . "                ? ['updateTime' => \$guardToken]\n"
     . "                : ['exists' => false],",
     "            // MUTANT: precondition removed\n",
     'TimetablePublishConcurrencyTest'],

    ['a moved token is rejected (updateTime arm)', $STORE,
     "            'precondition' => \$guardToken !== ''\n"
     . "                ? ['updateTime' => \$guardToken]\n"
     . "                : ['exists' => false],",
     "            'precondition' => ['exists' => true],\n",
     'TimetablePublishConcurrencyTest'],

    ['the FIRST publish is guarded too (exists arm)', $STORE,
     "            'precondition' => \$guardToken !== ''\n"
     . "                ? ['updateTime' => \$guardToken]\n"
     . "                : ['exists' => false],",
     "            'precondition' => \$guardToken !== ''\n"
     . "                ? ['updateTime' => \$guardToken]\n"
     . "                : null,\n",
     'TimetablePublishConcurrencyTest'],

    ['a conflict is refused rather than swallowed', $STORE,
     "        if (\$commitStatus === 'PRECONDITION_CONFLICT') {\n"
     . "            log_message('error', 'Timetable_plan_store: publish CAS conflict (doc='",
     "        if (false) {\n"
     . "            log_message('error', 'Timetable_plan_store: publish CAS conflict (doc='",
     'race'],

    ['the version and the token commit TOGETHER', $STORE,
     "            \$commit = \$this->firebase->firestoreCommitBatchStatus([\n"
     . "                ['collection' => self::COLLECTION_VERSIONS, 'docId' => \$docId,\n"
     . "                 'data' => \$update, 'merge' => true],\n"
     . "                \$guardWrite,\n"
     . "            ]);",
     "            \$this->firebase->firestoreCommitBatchStatus([\$guardWrite]);\n"
     . "            \$commit = \$this->firebase->firestoreCommitBatchStatus([\n"
     . "                ['collection' => self::COLLECTION_VERSIONS, 'docId' => \$docId,\n"
     . "                 'data' => \$update, 'merge' => true],\n"
     . "            ]);",
     'race'],

    
    // ── fail-closed ──────────────────────────────────────────────────────
    ['an unreadable guard refuses', $STORE,
     "            throw new Service_exception(\n"
     . "                'The timetable could not be published because it was not possible to '",
     "            return ''; throw new Service_exception(\n"
     . "                'The timetable could not be published because it was not possible to '",
     'TimetablePublishConcurrencyTest'],

    ['an unclassified commit is not a publish', $STORE,
     "        if (\$commitStatus !== 'SUCCESS') {\n"
     . "            log_message('error', 'Timetable_plan_store: publish commit failed (doc='",
     "        if (false) {\n"
     . "            log_message('error', 'Timetable_plan_store: publish commit failed (doc='",
     'TimetablePublishConcurrencyTest'],

    // ── the predicate (options 2 + 4) ────────────────────────────────────
    ['an overlapping publish is refused', $STORE,
     "        if (!empty(\$overlaps)) {\n            \$names = [];",
     "        if (false) {\n            \$names = [];",
     'TimetablePublishTest'],

    ['the overlap check spans ALL plans', $SVC,
     "            if ((string) (\$v['planId'] ?? '') === \$excludePlanId\n"
     . "                && (string) (\$v['versionId'] ?? '') === \$excludeVersionId) continue;",
     "            if ((string) (\$v['versionId'] ?? '') === \$excludeVersionId) continue;",
     'TimetableOneInForceTest'],

    ['open-ended ranges overlap everything after them', $SVC,
     "        \$aEndsFirst = (\$aTo !== '' && self::isIsoDate(\$aTo) && \$aTo < \$bFrom);\n"
     . "        \$bEndsFirst = (\$bTo !== '' && self::isIsoDate(\$bTo) && \$bTo < \$aFrom);",
     "        \$aEndsFirst = (\$aTo === '' || \$aTo < \$bFrom);\n"
     . "        \$bEndsFirst = (\$bTo === '' || \$bTo < \$aFrom);",
     'TimetableOneInForceTest'],

    ['resolution and publishing share one predicate', $SVC,
     "        foreach (\$versions as \$v) {\n            if (self::coversDate(\$v, \$date)) \$matches[] = \$v;\n        }",
     "        foreach (\$versions as \$v) {\n"
     . "            if (!is_array(\$v)) continue;\n"
     . "            if ((string) (\$v['status'] ?? '') !== self::STATUS_PUBLISHED) continue;\n"
     . "            \$f = (string) (\$v['effectiveFrom'] ?? '');\n"
     . "            if (\$f === '' || \$f > \$date) continue;\n"
     . "            \$matches[] = \$v;\n        }",
     'TimetableOneInForceTest'],

    // ── TT-C8 option 6 — SUPERSEDE ───────────────────────────────────────
    ['already-bounded is refused (the one-way rule)', $STORE,
     "        if (trim((string) (\$version['effectiveTo'] ?? '')) !== '') {",
     "        if (false) {",
     'TimetableSupersedeTest'],

    ['a past end date is refused (historical safety)', $STORE,
     "        if (\$lastEffectiveDate < \$today) {",
     "        if (false) {",
     'TimetableSupersedeTest'],

    ['an end date before the start is refused', $STORE,
     "        if (\$lastEffectiveDate < \$effectiveFrom) {",
     "        if (false) {",
     'TimetableSupersedeTest'],

    ['only a published version can be superseded', $STORE,
     "        if ((string) (\$version['status'] ?? '') !== Timetable_plan_service::STATUS_PUBLISHED) {\n"
     . "            throw new Service_exception(\n"
     . "                'Only a published timetable can be ended.",
     "        if (false) {\n"
     . "            throw new Service_exception(\n"
     . "                'Only a published timetable can be ended.",
     'TimetableSupersedeTest'],

    ['a blank reason is refused', $STORE,
     "        \$reason = trim(\$reason);\n        if (\$reason === '') {",
     "        \$reason = trim(\$reason);\n        if (false) {",
     'TimetableSupersedeTest'],

    ['the write mask cannot be widened to published content', $STORE,
     "        \$update = [\n            'effectiveTo'      => \$lastEffectiveDate,",
     "        \$update = [\n            'publishedFingerprint' => 'MUTANT',\n            'effectiveTo'      => \$lastEffectiveDate,",
     'TimetableSupersedeTest'],

    ['status is not flipped (flipping drops the past)', $STORE,
     "            'effectiveTo'      => \$lastEffectiveDate,\n            'supersededAt'     => \$now,",
     "            'effectiveTo'      => \$lastEffectiveDate,\n            'status'           => Timetable_plan_service::STATUS_SUPERSEDED,\n            'supersededAt'     => \$now,",
     'TimetableSupersedeTest'],

    ['supersede is guarded by the shared token', $STORE,
     "                 'precondition' => \$guardToken !== ''\n                     ? ['updateTime' => \$guardToken]\n                     : ['exists' => false],",
     "                 // MUTANT: precondition removed",
     'TimetablePublishConcurrencyTest'],

    ['a supersede conflict is refused, not swallowed', $STORE,
     "        if (\$commitStatus === 'PRECONDITION_CONFLICT') {\n            log_message('error', 'Timetable_plan_store: supersede CAS conflict",
     "        if (false) {\n            log_message('error', 'Timetable_plan_store: supersede CAS conflict",
     'TimetablePublishConcurrencyTest'],

    ['an unclassified supersede commit is not success', $STORE,
     "        if (\$commitStatus !== 'SUCCESS') {\n            log_message('error', 'Timetable_plan_store: supersede commit failed",
     "        if (false) {\n            log_message('error', 'Timetable_plan_store: supersede commit failed",
     'TimetableSupersedeTest'],

    ['a refused audit entry is not reported as recorded', $STORE,
     "        if (!\$ok) {\n            log_message('error', 'Timetable_plan_store: audit REFUSED '",
     "        \$ok = true;\n        if (!\$ok) {\n            log_message('error', 'Timetable_plan_store: audit REFUSED '",
     'TimetableSupersedeTest'],

    // ── the token must be read BEFORE any state it protects ──────────────
    // Both of these were REAL defects, found by this audit and proven by
    // execution before being fixed. Moving either read back after the version
    // read reopens a window in which a completed operation is invisible.
    ['supersede reads the token before the version', $STORE,
     "        \$guardToken = \$this->_readPublishGuard();\n\n        // getVersion() re-checks tenant AND session on the document itself and",
     "        // getVersion() re-checks tenant AND session on the document itself and",
     'TimetableSupersedeOrderingTest'],

    ['publish reads the token before the version', $STORE,
     "        \$guardToken = \$this->_readPublishGuard();\n\n        \$version = \$this->getVersion(\$planId, \$versionId);\n        if (\$version === null) {\n            throw new Service_exception(\n                'That timetable version does not exist, so nothing was published.',",
     "        \$version = \$this->getVersion(\$planId, \$versionId);\n        if (\$version === null) {\n            \$guardToken = \$this->_readPublishGuard();\n            throw new Service_exception(\n                'That timetable version does not exist, so nothing was published.',",
     'TimetableSupersedeOrderingTest'],
];

function runRace(string $root): bool
{
    $out = [];
    exec('cd /d ' . str_replace('/', '\\', $root)
        . ' && set ZX_RACE_SCALE=8&& node scripts\\timetable_publish_race_proof.js 2>&1',
        $out, $rc);
    return $rc === 0;
}

function runUnit(string $root, string $filter): bool
{
    $out = [];
    exec('cd /d ' . str_replace('/', '\\', $root)
        . ' && vendor\\bin\\phpunit --testsuite Unit --filter ' . $filter . ' 2>&1', $out, $rc);
    return $rc === 0;
}

echo "Baseline — everything must PASS before a single mutant means anything.\n";
$baseRace = runRace($ROOT);
echo '  race proof        : ' . ($baseRace ? "pass\n" : "FAIL\n");
foreach (['TimetablePublishConcurrencyTest', 'TimetablePublishTest', 'TimetableOneInForceTest', 'TimetableSupersedeTest',
                 'TimetableSupersedeOrderingTest'] as $f) {
    $ok = runUnit($ROOT, $f);
    echo sprintf("  %-32s: %s\n", $f, $ok ? 'pass' : 'FAIL');
    if (!$ok) $baseRace = false;
}
if (!$baseRace) { echo "\nBASELINE IS NOT GREEN — mutation results would be meaningless.\n"; exit(1); }

echo "\nMutants\n";
$killed = 0; $survived = []; $notApplied = [];
foreach ($MUTANTS as [$label, $file, $from, $to, $how]) {
    $mutated = str_replace($from, $to, $orig[$file], $c);
    if ($c !== 1) { $notApplied[] = "$label (n=$c)"; echo "  SKIP     $label (anchor n=$c)\n"; continue; }
    file_put_contents($file, $crlf[$file] ? str_replace("\n", "\r\n", $mutated) : $mutated);

    $passed = ($how === 'race') ? runRace($ROOT) : runUnit($ROOT, $how);

    file_put_contents($file, $raw[$file]);

    if (!$passed) { $killed++; echo "  KILLED   $label\n"; }
    else { $survived[] = $label; echo "  SURVIVED $label  <-- nothing noticed\n"; }
}

echo "\n$killed killed, " . count($survived) . " survived, " . count($notApplied) . " not applied\n";
if ($survived)   echo 'SURVIVED: ' . implode('; ', $survived) . "\n";
if ($notApplied) echo 'NOT APPLIED: ' . implode('; ', $notApplied) . "\n";
exit(empty($survived) && empty($notApplied) ? 0 : 2);
