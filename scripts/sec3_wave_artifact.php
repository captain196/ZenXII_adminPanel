<?php
/**
 * ══════════════════════════════════════════════════════════════════════════
 * SEC-3 — MINIMAL RULES ARTIFACT BUILDER (waves 3–5) · BUILDS ONLY
 * ══════════════════════════════════════════════════════════════════════════
 *
 *   php scripts/sec3_wave_artifact.php <LIVE_PULL> <wave3|wave4|wave5>
 *
 * NOTHING IS DEPLOYED. This writes `<wave>_rebuilt.rules` next to the pull.
 *
 * ── BUILD FROM THE LIVE PULL, NEVER THE REPOSITORY FILE ───────────────────
 * `firebase-rules/firestore.rules` is ~34KB AHEAD of production and carries
 * another workstream's undeployed work. A deploy ships the WHOLE file, so
 * building from it would ship that work as a side effect.
 *
 * ── SEQUENCING ────────────────────────────────────────────────────────────
 * SEC-1, SEC-2 and each SEC-3 wave are all built from the same live baseline,
 * so deploying two as-built would revert whichever went first. The order is:
 *
 *     pull live -> build A -> deploy A -> RE-PULL -> build B -> deploy B -> ...
 *
 * ── WHY EVERY ARM IS MODELLED SEPARATELY ──────────────────────────────────
 * `allow write` covers create + update + delete, but `request.resource` is
 * NULL on delete. A transform emitting
 *
 *     allow write: if isAdmin() && isSameSchoolWrite();
 *
 * silently denies EVERY delete, because `isSameSchoolWrite()` dereferences
 * `request.resource.data.schoolId`. That defect was actually produced by an
 * earlier version of the SEC-1 builder and caught only by a behavioural test.
 * So a transform here never rewrites `allow write` as one operation: it either
 * denies the arm outright, or emits create/update and delete as separate arms.
 *
 * ── DO NOT TRUST THIS SCRIPT'S OWN ASSERTIONS ─────────────────────────────
 * The checks below are necessary, not sufficient. Independent verification —
 * an allow-statement census, a direct read of every emitted block, and a
 * behavioural test suite on the emulator — is the actual proof. An earlier
 * "minimality proof" here used array_diff() and reported "0 changed lines" for
 * a nine-line change, because array_diff compares VALUES and the string
 * `allow write: if isAdmin();` recurs throughout the file.
 */

if (PHP_SAPI !== 'cli') { fwrite(STDERR, "CLI only\n"); exit(2); }

$livePath = trim((string) ($argv[1] ?? ''));
$wave     = trim((string) ($argv[2] ?? ''));

if ($livePath === '' || !in_array($wave, ['wave3', 'wave4', 'wave5'], true)) {
    fwrite(STDERR, "usage: php scripts/sec3_wave_artifact.php <LIVE_PULL> <wave3|wave4|wave5>\n");
    exit(2);
}
if (!is_file($livePath)) { fwrite(STDERR, "live pull not found: {$livePath}\n"); exit(2); }

$live = (string) file_get_contents($livePath);
if (strlen($live) < 100000) { fwrite(STDERR, "live pull looks truncated — refusing\n"); exit(2); }

/**
 * ── WAVE 3 — IDENTITY, PAYROLL, RBAC AND PRICING AUTHORITY ────────────────
 *
 * Boundary evidence gathered BEFORE choosing, not after:
 *
 *  1. The admin panel contains NO Firebase JS SDK. Every panel write goes
 *     through PHP on the service account, which bypasses rules entirely.
 *     (The one `firebasejs` match in the tree is a commented-out line in
 *     application/config/migration.php.)
 *  2. Cloud Functions use firebase-admin — also bypassing rules.
 *  3. `scripts/app_firestore_write_census.php` reports READ-ONLY on BOTH the
 *     Parent and Teacher apps for staff / salarySlips / rbacRoles /
 *     feeSettings / feeStructures, and exactly one client write on `students`.
 *     That census was validated against a known-positive control
 *     (`userDevices`, which both apps demonstrably write) before its
 *     READ-ONLY verdicts were relied on.
 *  4. Neither app uses `docRef()`, a raw `.collection()` or a batch /
 *     transaction against ANY wave-3 collection.
 *
 * So for these arms `isAdmin()` authorises a client population that does not
 * exist. The correct boundary is SERVER-ONLY — the same `if false` posture the
 * ruleset already uses for 87 other arms (feeIdempotency, studentEntitlements,
 * entitlementAllocations, examResultMeta, ...), and strictly stronger than
 * tenant-scoping.
 *
 * `students` is the exception and is deliberately NOT deny-all: the Parent app
 * really does write it. Its two self-service `allow update` clauses are left
 * untouched and only the blanket admin arm is denied. Firestore ORs all
 * matching allow rules, so the self-service path is unaffected — which the
 * behavioural suite asserts rather than assumes.
 */
$WAVES = [
    'wave3' => [
        'students' => [
            'mode'   => 'deny',
            'reason' => "Blanket admin write denied; the two self-service `allow update`\n"
                      . "      // clauses below remain and are the ONLY client write path.\n"
                      . "      // Parent app writes {mustChangePassword, updatedAt} on its own doc.",
        ],
        'staff'         => ['mode' => 'deny', 'reason' => 'No client writes staff (both apps READ-ONLY, census-verified).'],
        'salarySlips'   => ['mode' => 'deny', 'reason' => 'Payroll. Teacher app reads own payslip only; no client writes.'],
        'rbacRoles'     => ['mode' => 'deny', 'reason' => 'Role catalogue — a cross-tenant write here is privilege escalation.'],
        'feeSettings'   => ['mode' => 'deny', 'reason' => 'Holds PLAINTEXT gateway api_secret/webhook_secret. Zero client refs.'],
        'feeStructures' => ['mode' => 'deny', 'reason' => 'The fee chart — pricing authority. Both apps READ-ONLY.'],
    ],

    /**
     * ── WAVE 4 — ACADEMIC STRUCTURE AND SCHEDULING ────────────────────────
     *
     * Eight collections have zero client writes (census-verified on both apps)
     * and become server-only.
     *
     * `timetables` was flagged REVIEW by the census rather than READ-ONLY,
     * because the Teacher app reaches it through a raw `.collection()` instead
     * of the service wrapper. Both of those sites turned out to be READS
     * (`.get()` and `queryDocumentsAs`) — the flag was the census refusing to
     * silently pass something it could not classify, which is the behaviour
     * that makes its READ-ONLY verdicts worth trusting.
     *
     * `leaveApplications` is the opposite case and is NOT denied: BOTH apps
     * legitimately write it, and its create/update arms are already among the
     * best-scoped in the ruleset (tenant + applicant ownership + status
     * transition + immutable-field checks). Only `allow delete: if isAdmin()`
     * lacks tenant scoping, so only delete is touched.
     */
    'wave4' => [
        'sections'           => ['mode' => 'deny', 'reason' => 'Class sections. No client writes.'],
        'subjects'           => ['mode' => 'deny', 'reason' => 'Subject catalogue. No client writes.'],
        'subjectAssignments' => ['mode' => 'deny', 'reason' => 'Teacher-subject mapping — drives Teacher app scoping. No client writes.'],
        'exams'              => ['mode' => 'deny', 'reason' => 'Exam definitions. No client writes.'],
        'examSchedule'       => ['mode' => 'deny', 'reason' => 'Datesheet. No client writes.'],
        'timetables'         => ['mode' => 'deny', 'reason' => "Was `isStaff()` — any staff of any tenant could rewrite any\n"
                                                             . "      // timetable. Both apps only READ (verified: the two raw\n"
                                                             . "      // .collection() sites are .get() / queryDocumentsAs).",
                                 'from' => 'isStaff'],
        'calendarEvents'     => ['mode' => 'deny', 'reason' => 'Academic calendar. No client writes.'],
        'appraisals'         => ['mode' => 'deny', 'reason' => 'Staff appraisals. Teacher app reads own only; no client writes.'],

        'leaveApplications'  => ['mode' => 'delete_scope', 'actor' => 'isAdmin',
                                 'reason' => "create/update are already tenant + ownership scoped and BOTH apps\n"
                                           . "      // write them — only the delete arm lacked tenant scoping."],
    ],

    /**
     * ── WAVE 5 — COMMUNICATION, TRANSPORT, LIBRARY, PTM ───────────────────
     *
     * Mostly DELETE-only. Nine of these collections already scope create and
     * update correctly (tenant + author/owner) and leak only on delete, which
     * is exactly the asymmetry that appears when `allow write` is reasoned
     * about as one operation. Each delete gets the strict predicate.
     *
     * `pushRequests` keeps `isStaff()` rather than being promoted to
     * `isAdmin()`: this transform tightens tenancy, it does not silently
     * re-grade who may act.
     *
     * `notifications` and `libraryIssues` carry real client writes and get
     * properly split arms instead of a deny.
     */
    'wave5' => [
        'routes'        => ['mode' => 'deny', 'reason' => 'Transport routes. No client writes (driver app writes trip data, not routes).'],
        'vehicles'      => ['mode' => 'deny', 'reason' => 'Fleet register. No client writes.'],
        'studentRoutes' => ['mode' => 'deny', 'reason' => 'Student-route assignment. No client writes.'],
        'libraryBooks'  => ['mode' => 'deny', 'reason' => 'Catalogue. Was `isStaff()`; both apps READ-ONLY.', 'from' => 'isStaff'],

        'circulars'     => ['mode' => 'delete_scope', 'actor' => 'isAdmin', 'reason' => 'create/update already tenant + author scoped.'],
        'notices'       => ['mode' => 'delete_scope', 'actor' => 'isAdmin', 'reason' => 'create/update already tenant + author scoped.'],
        'events'        => ['mode' => 'delete_scope', 'actor' => 'isAdmin', 'reason' => 'create/update already tenant scoped.'],
        'ptmEvents'     => ['mode' => 'delete_scope', 'actor' => 'isAdmin', 'reason' => 'create/update already tenant scoped.'],
        'ptmRsvps'      => ['mode' => 'delete_scope', 'actor' => 'isAdmin', 'reason' => 'create/update already owner scoped (parent AND teacher arms).'],
        'ptmCounters'   => ['mode' => 'delete_scope', 'actor' => 'isAdmin', 'reason' => 'create/update already tenant scoped.'],
        'libraryFines'  => ['mode' => 'delete_scope', 'actor' => 'isAdmin', 'reason' => 'create/update already tenant scoped.'],
        'conversations' => ['mode' => 'delete_scope', 'actor' => 'isAdmin', 'reason' => 'create/update already tenant scoped.'],
        'messages'      => ['mode' => 'delete_scope', 'actor' => 'isAdmin', 'reason' => 'create/update already tenant scoped.'],
        'pushRequests'  => ['mode' => 'delete_scope', 'actor' => 'isStaff',
                            'reason' => "Actor stays isStaff() — this tightens TENANCY only and does not\n"
                                      . "      // re-grade who may delete."],

        'notifications' => ['mode' => 'split_tenant', 'actor' => 'isStaff',
                            'reason' => "Both apps UPDATE (markNotificationRead -> {read:true}); neither\n"
                                      . "      // creates or deletes. Actor stays isStaff() — see\n"
                                      . "      // SEC3_NOTIFICATIONS_PARENT_GAP in the wave-5 packet for the\n"
                                      . "      // separate finding that parents are blocked by isStaff() today.",
                            'from' => 'isStaff'],
        'libraryIssues' => ['mode' => 'split_tenant', 'actor' => 'isStaff',
                            'reason' => "Teacher app creates (issueBook — payload carries schoolId) and\n"
                                      . "      // updates (returnBook). On update request.resource.data is the\n"
                                      . "      // MERGED post-write document, so schoolId is present there too.",
                            'from' => 'isStaff'],
    ],
];

if (!isset($WAVES[$wave])) {
    fwrite(STDERR, "wave {$wave} not yet defined — investigation must precede the artifact\n");
    exit(2);
}
$plan = $WAVES[$wave];

$out     = $live;
$applied = [];
$failed  = [];

foreach ($plan as $col => $spec) {
    // Anchor on the collection's own block so an identical `allow write` line
    // elsewhere in the file cannot be hit by accident.
    $pattern = '/(match \/' . preg_quote($col, '/') . '\/\{[^}]*\}\s*\{)(.*?)(\n    \})/s';

    if (!preg_match($pattern, $out, $m)) {
        $failed[] = "{$col}: block not found";
        continue;
    }
    $body = $m[2];
    $mode = $spec['mode'];

    // Which arm this collection's defect actually lives on. Matching the exact
    // expected text means a live drift is REFUSED rather than silently
    // transformed into something else.
    $from  = $spec['from'] ?? 'isAdmin';
    $actor = $spec['actor'] ?? 'isAdmin';

    if ($mode === 'delete_scope') {
        $expect = '/\n(\s*)allow delete:\s*if ' . preg_quote($actor, '/') . '\(\);/';
    } else {
        $expect = '/\n(\s*)allow write:\s*if ' . preg_quote($from, '/') . '\(\);/';
    }

    if (!preg_match($expect, $body, $am)) {
        $verb = $mode === 'delete_scope' ? 'delete' : 'write';
        $who  = $mode === 'delete_scope' ? $actor : $from;
        $failed[] = "{$col}: expected `allow {$verb}: if {$who}();` not present — live has drifted";
        continue;
    }
    $indent = $am[1];

    if ($mode === 'delete_scope') {
        /**
         * DELETE IS MODELLED ON ITS OWN, AND NOT VIA `isSameSchool()`.
         *
         * `request.resource` is NULL on delete, so `isSameSchoolWrite()` is
         * unusable here. The obvious alternative — `isSameSchool()` — is
         * REJECTED for two reasons established during the SEC-1 adversarial
         * review:
         *
         *   1. it carries the legacy `resource.data.schoolCode == school_id`
         *      arm that D7 (2026-07-12) deliberately removed from the WRITE
         *      path as "the spoofable schoolCode fallback (F-2)", and a delete
         *      IS a write;
         *   2. it opens with `resource == null ||`, i.e. it fails OPEN on a
         *      missing document.
         *
         * The explicit predicate below has neither property and fails closed.
         */
        $new = "\n{$indent}// SEC-3 {$wave}: tenant-scoped delete. " . $spec['reason']
             . "\n{$indent}// Strict on purpose — NOT isSameSchool(), which carries the legacy"
             . "\n{$indent}// spoofable schoolCode arm (removed from writes by D7) and fails OPEN"
             . "\n{$indent}// on a missing document via its leading `resource == null ||`."
             . "\n{$indent}allow delete: if {$actor}()"
             . "\n{$indent}  && resource != null"
             . "\n{$indent}  && resource.data.schoolId == request.auth.token.school_id;";

        $newBody = preg_replace($expect, $new, $body, 1, $cnt);

    } elseif ($mode === 'split_tenant') {
        /**
         * CREATE/UPDATE AND DELETE INSPECT DIFFERENT OBJECTS.
         *
         *   create/update -> request.resource  (the incoming document)
         *   delete        -> resource          (the stored document)
         *
         * Emitting `allow write: if <actor>() && isSameSchoolWrite();` would
         * deny EVERY delete, because `isSameSchoolWrite()` dereferences
         * `request.resource.data.schoolId`, which is null on delete. An earlier
         * SEC-1 builder did exactly that.
         */
        $new = "\n{$indent}// SEC-3 {$wave}: tenant-scoped, arms modelled separately. " . $spec['reason']
             . "\n{$indent}// create/update read request.resource; delete reads resource — a single"
             . "\n{$indent}// `allow write` with isSameSchoolWrite() would deny every delete."
             . "\n{$indent}allow create, update: if {$actor}() && isSameSchoolWrite();"
             . "\n{$indent}allow delete: if {$actor}()"
             . "\n{$indent}  && resource != null"
             . "\n{$indent}  && resource.data.schoolId == request.auth.token.school_id;";

        $newBody = preg_replace($expect, $new, $body, 1, $cnt);

    } elseif ($mode === 'deny') {
        // SERVER-ONLY. Note this covers create, update AND delete in one
        // statement — which is safe ONLY because the condition is constant
        // `false` and therefore never dereferences `request.resource` or
        // `resource`. This is the single case where not splitting the arms
        // cannot hide an asymmetry.
        $new = "\n{$indent}// SEC-3 {$wave}: server-only. " . $spec['reason']
             . "\n{$indent}// PHP backend + Cloud Functions use the Admin SDK and bypass rules,"
             . "\n{$indent}// so denying clients removes an authorization nothing exercises."
             . "\n{$indent}allow write: if false;";

        $newBody = preg_replace($expect, $new, $body, 1, $cnt);

    } else {
        fwrite(STDERR, "unknown mode '{$mode}' for {$col}\n");
        exit(2);
    }

    if ($cnt !== 1) { $failed[] = "{$col}: replacement count {$cnt}"; continue; }

    $out = str_replace($m[0], $m[1] . $newBody . $m[3], $out);
    $applied[] = $col;
}

echo "SEC-3 {$wave} ARTIFACT BUILD\n";
echo str_repeat('=', 96) . "\n";
echo 'source : ' . basename($livePath) . ' (' . strlen($live) . " bytes)\n";
echo 'applied: ' . (count($applied) ? implode(', ', $applied) : '(none)') . "\n";
if ($failed) {
    echo "\nFAILED:\n";
    foreach ($failed as $f) echo "  - {$f}\n";
}

// ── STRUCTURAL INVARIANTS ────────────────────────────────────────────────
$depth = 0;
for ($i = 0, $n = strlen($out); $i < $n; $i++) {
    if ($out[$i] === '{') $depth++;
    elseif ($out[$i] === '}') $depth--;
}
$liveMatches = preg_match_all('/match \//', $live);
$artMatches  = preg_match_all('/match \//', $out);

printf("\nbrace depth   : %d %s\n", $depth, $depth === 0 ? '(balanced)' : '(UNBALANCED — REFUSE)');
printf("match blocks  : live %d -> artifact %d %s\n", $liveMatches, $artMatches,
    $liveMatches === $artMatches ? '(unchanged)' : '(CHANGED — REFUSE)');

// ── POSITIONAL DIFF ──────────────────────────────────────────────────────
// Positional, NOT array_diff(): array_diff compares VALUES, and identical
// rule lines recur throughout the file, so it reported "0 changed lines" for
// a nine-line change in an earlier version of this tooling.
$lf = explode("\n", str_replace("\r", '', $live));
$af = explode("\n", str_replace("\r", '', $out));
$changed = 0;
$i = 0; $j = 0;
while ($i < count($lf) && $j < count($af)) {
    if ($lf[$i] === $af[$j]) { $i++; $j++; continue; }
    $changed++;
    // The transform only ever replaces one line with several, so advance the
    // artifact cursor until it re-synchronises with the live file.
    $sync = false;
    for ($k = $j + 1; $k < min($j + 12, count($af)); $k++) {
        if ($af[$k] === $lf[$i + 1] ?? null) { $j = $k; $sync = true; break; }
    }
    $i++; if (!$sync) $j++;
}
printf("live lines    : %d\nartifact lines: %d  (+%d)\n", count($lf), count($af), count($af) - count($lf));
printf("divergences   : %d\n", $changed);

$fail = ($depth !== 0) || ($liveMatches !== $artMatches) || $applied === [] || $failed !== [];
if ($fail) {
    echo "\nREFUSING TO WRITE ARTIFACT — an invariant failed.\n";
    exit(1);
}

$outFile = dirname($livePath) . "/sec3_{$wave}_rebuilt.rules";
file_put_contents($outFile, $out);
echo "\nartifact: {$outFile}\n";

echo "\n" . str_repeat('=', 96) . "\n";
echo "These checks are NECESSARY, NOT SUFFICIENT. Before this artifact is\n";
echo "considered verified:\n";
echo "  1. run scripts/firestore_write_arm_audit.php against it\n";
echo "  2. READ every emitted block directly\n";
echo "  3. run the behavioural suite on the emulator — create, update AND\n";
echo "     delete, per collection, allow AND deny\n";
echo "  4. adversarially attempt to break the intended workflow\n";
echo "Rollback: redeploy the untouched pull (" . basename($livePath) . ").\n";
echo "NOTHING WAS DEPLOYED.\n";
exit(0);
