<?php
/**
 * ══════════════════════════════════════════════════════════════════════════
 * SEC-6 — READ/TENANT CONVERGENCE ARTIFACT · BUILDS ONLY
 * ══════════════════════════════════════════════════════════════════════════
 *
 *   php scripts/sec6_read_convergence_artifact.php <LIVE_PULL>
 *
 * NOTHING IS DEPLOYED. Writes `sec6_rebuilt.rules` next to the pull.
 *
 * Closes SEC-4 (shape A), SEC-5 shapes B, C, D and F. See
 * `.claude/zenx/SEC4_SEC5_DESIGN_MATRIX.md` for the per-collection reasoning
 * and the measured behaviour each predicate is derived from.
 *
 * ── EVERY EDIT HERE IS A STRICT TIGHTENING ────────────────────────────────
 * It removes access that exists today and grants nothing. The one designed
 * change that would GRANT something — repairing the `marks` / `academicAuditLog`
 * delete arms, which currently deny a legitimate same-school delete because
 * they dereference a null `request.resource` — is deliberately NOT in this
 * artifact. Restoring a denied write path on assessment data is a change in
 * what the system permits and is held for explicit authorization.
 *
 * ── BUILD FROM THE LIVE PULL, NEVER THE REPOSITORY FILE ───────────────────
 * `firebase-rules/firestore.rules` is ~34KB ahead of production and carries
 * another workstream's undeployed work. A deploy ships the WHOLE file.
 *
 * ── SEQUENCING ────────────────────────────────────────────────────────────
 * SEC-1, SEC-2, the three SEC-3 waves and this artifact are all built from the
 * same baseline, so deploying two as-built reverts the first:
 *     pull -> build A -> deploy A -> RE-PULL -> build B -> deploy B -> ...
 *
 * ── DO NOT TRUST THIS SCRIPT'S OWN ASSERTIONS ─────────────────────────────
 * The invariants below are necessary, not sufficient. Proof is: the
 * authorization graph re-run over the artifact, every emitted block read
 * directly, and a differential emulator suite that must FAIL against the
 * unfixed live pull.
 */

if (PHP_SAPI !== 'cli') { fwrite(STDERR, "CLI only\n"); exit(2); }

require_once __DIR__ . '/lib/rules_parser.php';

$livePath = trim((string) ($argv[1] ?? ''));
if ($livePath === '' || !is_file($livePath)) {
    fwrite(STDERR, "usage: php scripts/sec6_read_convergence_artifact.php <LIVE_PULL>\n");
    exit(2);
}
$live = (string) file_get_contents($livePath);
if (strlen($live) < 100000) { fwrite(STDERR, "live pull looks truncated — refusing\n"); exit(2); }

$out = $live;
$applied = [];
$failed = [];

/** Replace an exact snippet exactly once, or record a failure. */
function edit(&$out, $label, $old, $new, &$applied, &$failed)
{
    $n = substr_count($out, $old);
    if ($n !== 1) {
        $failed[] = "{$label}: expected exactly 1 occurrence, found {$n} — live has drifted";
        return;
    }
    $out = str_replace($old, $new, $out);
    $applied[] = $label;
}

// ── A. THE HELPER (13 collections at once) ───────────────────────────────
//
// `isStaff() || (...)` short-circuits: for any staff caller the tenant check
// in the second disjunct is never evaluated. Re-composed with `&&`, which is
// exactly the shape `submissions` and `teacherMarks` already use and which
// MEASURABLY refuses the cross-tenant staff read.
edit($out, 'helper:isStaffOrOwnStudent',
<<<'OLD'
    function isStaffOrOwnStudent() {
      return isStaff() || (isSameSchool() && isOwnStudent());
    }
OLD
,
<<<'NEW'
    function isStaffOrOwnStudent() {
      // SEC-4 (2026-08-21). Was `isStaff() || (isSameSchool() && isOwnStudent())`.
      // `||` short-circuits, so for ANY staff caller the tenant comparison in
      // the second disjunct was never reached — a staff account of any tenant
      // could read every tenant's feeDemands, feeReceipts, marks and results.
      //
      // Re-composed with `&&`. Not a new restriction: `submissions` and
      // `teacherMarks` already use `isSameSchoolStrict() && (isStaff() ||
      // isOwnStudent())`, and `attendance` the `&&` equivalent — all three
      // measurably refuse the cross-tenant staff read today.
      //
      // `isSameSchool()` (not the Strict variant) is deliberate: Strict would
      // turn a read of a NON-EXISTENT document from empty into
      // PERMISSION_DENIED, a behavioural change with a wider blast radius than
      // the finding. A missing document has no data to disclose.
      return isSameSchool() && (isStaff() || isOwnStudent());
    }
NEW
, $applied, $failed);

// ── B. THE SAME SHAPE, WRITTEN OUT BY HAND ───────────────────────────────
// Missed by three earlier censuses, and positively CLEARED by the now-retired
// sec4_read_arm_census.php because it tested for the PRESENCE of isStaff() and
// isSameSchool() rather than their STRUCTURE.
edit($out, 'exams:read',
<<<'OLD'
      allow read: if isStaff()
                  || (isSameSchool() && resource.data.status in ['Published', 'Completed']);
OLD
,
<<<'NEW'
      // SEC-5 shape B (2026-08-21): was `isStaff() || (isSameSchool() && ...)`.
      // The staff disjunct carried no tenant check, so a teacher of any school
      // could read any school's exams — including Draft ones. Parent gating is
      // unchanged; only the staff branch gains the tenant comparison.
      allow read: if isSameSchool()
                  && (isStaff() || resource.data.status in ['Published', 'Completed']);
NEW
, $applied, $failed);

edit($out, 'examSchedule:read',
<<<'OLD'
      allow read: if isStaff()
                  || (isSameSchool() && examVisible(resource.data.schoolId, resource.data.examId));
OLD
,
<<<'NEW'
      // SEC-5 shape B (2026-08-21): same short-circuit as /exams above.
      // examVisible() gating for parents is unchanged.
      allow read: if isSameSchool()
                  && (isStaff() || examVisible(resource.data.schoolId, resource.data.examId));
NEW
, $applied, $failed);

// ── C. BARE isAuth() ON READ ─────────────────────────────────────────────
// Wider than SEC-4: isAuth() does not exclude parents, so a PARENT of another
// tenant could read these too. Measured.
edit($out, 'substitutes:read',
<<<'OLD'
    match /substitutes/{docId} {
      allow read: if isAuth();
OLD
,
<<<'NEW'
    match /substitutes/{docId} {
      // SEC-5 shape C (2026-08-21): was bare `isAuth()` — ANY authenticated
      // user of ANY tenant could read this, parents included. Tenant scoping is
      // the minimum change; a role requirement would be a second, undeclared
      // behavioural change and both apps legitimately read this collection.
      allow read: if isSameSchool();
NEW
, $applied, $failed);

edit($out, 'timetableSettings:read',
<<<'OLD'
    match /timetableSettings/{docId} {
      allow read: if isAuth();
OLD
,
<<<'NEW'
    match /timetableSettings/{docId} {
      // SEC-5 shape C (2026-08-21): was bare `isAuth()`.
      // Neither app currently references this collection, but absence of a
      // caller is NOT evidence that it is intentionally server-only, so it is
      // tenant-scoped rather than denied.
      allow read: if isSameSchool();
NEW
, $applied, $failed);

// ── D. FAIL-OPEN ON CREATE ───────────────────────────────────────────────
// `resource` is ALWAYS null on create, so `isSameSchool()`'s leading
// `resource == null ||` is unconditionally true and the whole disjunction
// collapses — leaving bare isStaff(). MEASURED: a School A teacher can create a
// School B document.
foreach (['subjectPlanProgress', 'dailyTeacherMonitoring'] as $col) {
    edit($out, "{$col}:write",
        "    match /{$col}/{docId} {\n"
        . ($col === 'subjectPlanProgress'
            ? "      allow read:  if isSameSchool();\n"
            : "      allow read:  if isStaff() && isSameSchool();\n")
        . "      allow write: if isStaff() && (isSameSchoolWrite() || isSameSchool());",

        "    match /{$col}/{docId} {\n"
        . ($col === 'subjectPlanProgress'
            ? "      allow read:  if isSameSchool();\n"
            : "      allow read:  if isStaff() && isSameSchool();\n")
        . "      // SEC-5 shape D (2026-08-21): was\n"
        . "      //   isStaff() && (isSameSchoolWrite() || isSameSchool())\n"
        . "      // On CREATE `resource` is always null, so isSameSchool()'s leading\n"
        . "      // `resource == null ||` is unconditionally true and the tenant check\n"
        . "      // evaporated — leaving bare isStaff(). Measured: a teacher of School A\n"
        . "      // could create a School B document.\n"
        . "      //\n"
        . "      // Arms split so each inspects the object that exists for it:\n"
        . "      // create/update read request.resource, delete reads resource.\n"
        . "      allow create, update: if isStaff() && isSameSchoolWrite();\n"
        . "      allow delete: if isStaff()\n"
        . "        && resource != null\n"
        . "        && resource.data.schoolId == request.auth.token.school_id;",
        $applied, $failed);
}

// ── F. circularReads ─────────────────────────────────────────────────────
// The read arm granted any authenticated user of any tenant a read of any
// non-existent receipt id, and `isStaff()` was a bare disjunct with no tenant
// comparison at all — the SEC-4 defect spelled out inline, which a helper fix
// would NOT have reached.
edit($out, 'circularReads:read',
<<<'OLD'
      allow read: if isAuth() && (
        resource == null
        || resource.data.get('userId', '') == request.auth.uid
        || isStaff()
      );
OLD
,
<<<'NEW'
      // SEC-5 shape F (2026-08-21). Two defects in one arm:
      //   1. `isStaff()` was a bare disjunct with NO document-school
      //      comparison — the SEC-4 short-circuit written out inline, which
      //      fixing isStaffOrOwnStudent() does NOT reach.
      //   2. `resource == null ||` let any authenticated user of any tenant
      //      read any non-existent receipt id.
      // Create/update are already `isAuth() && isSameSchoolWrite() && userId ==
      // uid` and are untouched.
      allow read: if isSameSchool() && (
        resource.data.get('userId', '') == request.auth.uid
        || isStaff()
      );
NEW
, $applied, $failed);

// ── G. BARE isAdmin() ON READ — back-office audit trails ─────────────────
//
// Surfaced only after the authorization graph's platform-detection bug was
// fixed: `isAdmin()` expands to a role list containing 'Super Admin', so every
// `isAdmin()` arm had been mis-filed as `platform-only` and hidden from the
// census. `isAdmin()` admits ordinary school admins and is NOT a platform
// boundary.
//
// These three carry no tenant comparison at all, so an admin of any school
// could read every school's fee audit trail, refund audit and generation-job
// history. Neither app references them (0 read refs, 0 writes), so tenant
// scoping cannot break a client contract.
edit($out, 'feeAuditLogs:read',
<<<'OLD'
    match /feeAuditLogs/{docId} {
      allow read:  if isAdmin();
OLD
,
<<<'NEW'
    match /feeAuditLogs/{docId} {
      // SEC-6 (2026-08-21): was bare `isAdmin()` — no document-school check, so
      // an admin of ANY school could read EVERY school's fee audit trail.
      // `isAdmin()` is not a platform role; it admits ordinary school admins.
      allow read:  if isAdmin() && isSameSchool();
NEW
, $applied, $failed);

edit($out, 'feeRefundAudit:read',
<<<'OLD'
    match /feeRefundAudit/{docId} {
      allow read: if isAdmin();
OLD
,
<<<'NEW'
    match /feeRefundAudit/{docId} {
      // SEC-6 (2026-08-21): was bare `isAdmin()`. Its create/update/delete arms
      // were already tenant-scoped — only the READ arm leaked, which is exactly
      // the per-operation asymmetry a whole-arm review misses.
      allow read: if isAdmin() && isSameSchool();
NEW
, $applied, $failed);

edit($out, 'fee_generation_jobs:read',
<<<'OLD'
    match /fee_generation_jobs/{docId} {
      allow read:  if isAdmin();
OLD
,
<<<'NEW'
    match /fee_generation_jobs/{docId} {
      // SEC-6 (2026-08-21): was bare `isAdmin()`. The WRITE arm of this block is
      // SEC-1's; this edit touches the READ arm only. Both must be present in
      // whichever artifact is rebuilt last — see the overlap table in
      // SEC6_DEPLOYMENT_PACKET.md.
      allow read:  if isAdmin() && isSameSchool();
NEW
, $applied, $failed);

// ── Report and invariants ────────────────────────────────────────────────
echo "SEC-6 READ/TENANT CONVERGENCE ARTIFACT\n";
echo str_repeat('=', 96) . "\n";
echo 'source : ' . basename($livePath) . ' (' . strlen($live) . " bytes)\n";
echo 'applied: ' . (count($applied) ? implode(', ', $applied) : '(none)') . "\n";
if ($failed) {
    echo "\nFAILED:\n";
    foreach ($failed as $f) echo "  - {$f}\n";
}

$depth = 0;
for ($i = 0, $n = strlen($out); $i < $n; $i++) {
    if ($out[$i] === '{') $depth++;
    elseif ($out[$i] === '}') $depth--;
}
$liveMatches = preg_match_all('/match \//', $live);
$artMatches  = preg_match_all('/match \//', $out);

printf("\nbrace depth  : %d %s\n", $depth, $depth === 0 ? '(balanced)' : '(UNBALANCED — REFUSE)');
printf("match blocks : live %d -> artifact %d %s\n", $liveMatches, $artMatches,
    $liveMatches === $artMatches ? '(unchanged)' : '(CHANGED — REFUSE)');

$fail = ($depth !== 0) || ($liveMatches !== $artMatches) || $applied === [] || $failed !== [];
if ($fail) { echo "\nREFUSING TO WRITE ARTIFACT — an invariant failed.\n"; exit(1); }

$outFile = dirname($livePath) . '/sec6_rebuilt.rules';
file_put_contents($outFile, $out);
echo "\nartifact: {$outFile}\n";

echo "\n" . str_repeat('=', 96) . "\n";
echo "NECESSARY, NOT SUFFICIENT. Before this is considered verified:\n";
echo "  1. php scripts/firestore_authz_graph.php <artifact>\n";
echo "  2. read every emitted block directly\n";
echo "  3. run the emulator suite against BOTH the artifact and the live pull —\n";
echo "     a suite green against both proves nothing\n";
echo "  4. adversarially attempt to break the intended workflow\n";
echo "Rollback: redeploy the untouched pull (" . basename($livePath) . ").\n";
echo "NOTHING WAS DEPLOYED.\n";
exit(0);
