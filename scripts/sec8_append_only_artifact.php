<?php
/**
 * ══════════════════════════════════════════════════════════════════════════
 * SEC-8 — B-022 RESOLUTION · EXPLICIT APPEND-ONLY DELETE DENIAL · BUILDS ONLY
 * ══════════════════════════════════════════════════════════════════════════
 *
 *   php scripts/sec8_append_only_artifact.php <LIVE_PULL>
 *
 * NOTHING IS DEPLOYED. Writes `sec8_rebuilt.rules` next to the pull.
 *
 * ── THE QUESTION B-022 ASKED ──────────────────────────────────────────────
 * `marks` and `academicAuditLog` both carry
 *
 *     allow write: if isStaff() && isSameSchoolWrite() [&& hasCapability(...)];
 *
 * `request.resource` is NULL on delete, so that predicate dereferences null,
 * Firestore raises an evaluation error, and a legitimate same-school delete is
 * REFUSED. Measured: sec5 (academicAuditLog) and sec7 Q2 (the isolated shape).
 *
 * The obvious "repair" — emitting a delete arm over `resource` — would GRANT an
 * operation production currently refuses. Before doing that, the intended
 * requirement had to be established rather than inferred from the rule's shape.
 *
 * ── WHAT THE CODEBASE SAYS ────────────────────────────────────────────────
 * `application/libraries/Audit_log_service.php` opens:
 *
 *     "Audit_log_service — write-only audit trail for academic-planner
 *      mutations."
 *
 * It exposes `log()` and nothing else; there is no delete method anywhere in
 * the service. (`'delete'` appears in its ACTIONS list as an action TYPE being
 * recorded, not as a deletion of the log.)
 *
 * For `marks`:
 *   - Neither app deletes marks. The Teacher app's only client deletes are
 *     homework and stories.
 *   - The Teacher app writes marks with `setDocument(..., merge = true)` and a
 *     `status: "draft"` field — corrections are OVERWRITES, not deletions.
 *   - The admin panel deletes `exams` and `examSchedule`, but never `marks`.
 *   - `Firestore_helper` documents `marksAudit` as an "append-only marks change
 *     trail" — a design that assumes marks do not silently vanish.
 *
 * ── VERDICT ───────────────────────────────────────────────────────────────
 * Client deletion should remain PROHIBITED for both. This artifact therefore
 * makes the existing denial EXPLICIT and INTENTIONAL rather than an accident of
 * null dereferencing.
 *
 * **Behavioural change: ZERO.** Both operations are already refused. What
 * changes is that they are refused *on purpose*, so a future author fixing the
 * "broken" predicate cannot accidentally open them.
 *
 * The admin panel and Cloud Functions use the Admin SDK and bypass these rules,
 * so a genuine back-office correction path remains available and is unaffected.
 *
 * ── DELIBERATELY NOT DONE ─────────────────────────────────────────────────
 * `academicAuditLog` UPDATE is left alone. An append-only log arguably should
 * refuse updates too, and its doc ids are timestamp-prefixed so updates are not
 * used in practice — but denying update would be a real behavioural tightening
 * beyond the accident this artifact removes, and it belongs in its own change
 * with its own evidence.
 */

if (PHP_SAPI !== 'cli') { fwrite(STDERR, "CLI only\n"); exit(2); }

$livePath = trim((string) ($argv[1] ?? ''));
if ($livePath === '' || !is_file($livePath)) {
    fwrite(STDERR, "usage: php scripts/sec8_append_only_artifact.php <LIVE_PULL>\n");
    exit(2);
}
$live = (string) file_get_contents($livePath);
if (strlen($live) < 100000) { fwrite(STDERR, "live pull looks truncated — refusing\n"); exit(2); }

$out = $live;
$applied = [];
$failed = [];

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

edit($out, 'marks:append-only',
<<<'OLD'
      allow write: if isStaff() && isSameSchoolWrite() && hasCapability('Results');
OLD
,
<<<'NEW'
      // SEC-8 / B-022 (2026-08-21). Arms split so each inspects the object that
      // exists for it, and delete denied EXPLICITLY.
      //
      // The previous single `allow write` covered delete through a predicate
      // that dereferences the NULL `request.resource`, so delete was already
      // refused — by accident, via an evaluation error. This states the intent.
      //
      // Client deletion of marks is not part of the ZenX model: neither app
      // deletes marks, the Teacher app corrects them with setDocument(merge) and
      // a `status: "draft"` field, the admin panel deletes exams/examSchedule but
      // never marks, and `marksAudit` is an append-only change trail. A
      // back-office correction path remains available through the Admin SDK,
      // which bypasses these rules.
      //
      // BEHAVIOURAL CHANGE: ZERO. Delete was refused before and is refused now.
      allow create, update: if isStaff() && isSameSchoolWrite() && hasCapability('Results');
      allow delete: if false;
NEW
, $applied, $failed);

edit($out, 'academicAuditLog:append-only',
<<<'OLD'
      allow write: if isStaff() && isSameSchoolWrite();
OLD
,
<<<'NEW'
      // SEC-8 / B-022 (2026-08-21). Same split, same reasoning.
      //
      // Audit_log_service.php describes itself as a "write-only audit trail" and
      // exposes only log() — there is no delete anywhere in the service. Delete
      // was already refused via a null dereference on `request.resource`; this
      // makes the denial intentional so a future author "fixing" the broken
      // predicate cannot accidentally open it.
      //
      // UPDATE is deliberately left as-is: denying it would be a real tightening
      // beyond this accident and belongs in its own change.
      //
      // BEHAVIOURAL CHANGE: ZERO.
      allow create, update: if isStaff() && isSameSchoolWrite();
      allow delete: if false;
NEW
, $applied, $failed);

echo "SEC-8 APPEND-ONLY ARTIFACT (B-022 resolution)\n";
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

$outFile = dirname($livePath) . '/sec8_rebuilt.rules';
file_put_contents($outFile, $out);
echo "\nartifact: {$outFile}\n";
echo "\nThis artifact GRANTS NOTHING. Both deletes were already refused; they are\n";
echo "now refused deliberately. Verify with the authorization graph and\n";
echo "sec8_append_only.test.js, which asserts the behaviour is unchanged\n";
echo "against BOTH the live pull and the artifact.\n";
echo "Rollback: redeploy the untouched pull (" . basename($livePath) . ").\n";
echo "NOTHING WAS DEPLOYED.\n";
exit(0);
