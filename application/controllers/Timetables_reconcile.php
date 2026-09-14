<?php
defined('BASEPATH') or exit('No direct script access allowed');

require_once APPPATH . 'libraries/Timetable_projection_service.php';

/**
 * Timetables_reconcile — repair stale period.teacherId in the LEGACY
 * `timetables` collection against the authoritative `subjectAssignments`.
 *
 * ── WHAT THIS TOOL MAY AND MAY NOT TOUCH ────────────────────────────────
 *
 * `timetables` is no longer canonical. Under the version architecture the
 * authority is timetableVersions/timetableVersionDays, and `timetables` is a
 * COMPATIBILITY PROJECTION of whichever version is published — rebuilt from
 * canonical state by Timetable_projection_service.
 *
 * A projected document therefore must never be repaired in place:
 *
 *   - the repair bypasses validation and publishing entirely, changing what
 *     teachers see without any version recording that it happened;
 *   - the next projection run rebuilds the document from the published
 *     version and silently destroys the repair, so the drift returns;
 *   - and for as long as it survives, the compatibility collection disagrees
 *     with the canonical version it claims to be a copy of.
 *
 * Documents carrying `projectedFromVersion` are therefore SKIPPED and
 * reported. The canonical repair for teacher drift is the approved one:
 * change the subject assignments (which marks affected proposals stale),
 * re-check, and publish a new version.
 *
 * What remains in scope is documents that predate the canonical lifecycle
 * and have no published version behind them.
 *
 * WHY THIS EXISTS
 *   `subjectAssignments` (the subject-matrix) is the single source of truth for
 *   "who teaches (class, section, subject)". The `timetables` collection stamps
 *   a teacherId/teacher onto each period at generation/edit time. When the
 *   subject-matrix is rebuilt AFTER a timetable was generated, the timetable
 *   keeps the OLD teacherId — so a teacher reassigned to a slot never sees it in
 *   the app (app matches period.teacherId == my id), and the slot still shows
 *   the previous teacher. Timetables_verify only catches BLANK teacherIds; this
 *   tool catches the subtler WRONG-teacherId drift and realigns it.
 *
 * WHAT IT DOES
 *   For every class-type period with a subject, resolves the authoritative
 *   (teacherId, teacherName) from subjectAssignments by (className, section,
 *   subjectName) — with a class-wide (section-less) assignment fallback — and,
 *   when it differs from the period, rewrites period.teacherId + period.teacher.
 *   Breaks/lunch/empty periods are never touched. Periods whose subject has no
 *   matching (or teacher-less) assignment are left as-is and reported.
 *
 * INVOCATION
 *   Dry-run (default, mutates nothing):
 *     SCHOOL_ID=<schoolFs> SESSION_YEAR=<YYYY-YY> php index.php timetables_reconcile run
 *   Apply:
 *     APPLY=1 SCHOOL_ID=<schoolFs> SESSION_YEAR=<YYYY-YY> php index.php timetables_reconcile run
 *
 *   Optional: SKIP_MANUAL=1 — leave docs with manuallyEdited==true untouched
 *             (they are always reported either way).
 */
class Timetables_reconcile extends CI_Controller
{
    /**
     * Both scans abort above this rather than answering from a truncated set.
     * A reconciler that silently examines the first N documents reports the
     * ones it happened to see as the whole picture — and its whole purpose is
     * to be believed when it says a school is clean.
     */
    const SCAN_LIMIT = 2000;

    private string $schoolFs    = '';
    private string $sessionYear = '';
    private bool   $apply       = false;
    private bool   $skipManual  = false;

    public function __construct()
    {
        parent::__construct();
        if (!$this->input->is_cli_request()) {
            show_error('Timetables_reconcile is CLI-only.', 403);
        }
        $this->load->library('firebase');

        $this->schoolFs    = (string) (getenv('SCHOOL_ID')    ?: '');
        $this->sessionYear = (string) (getenv('SESSION_YEAR') ?: '');
        $this->apply       = (string) (getenv('APPLY')       ?: '') === '1';
        $this->skipManual  = (string) (getenv('SKIP_MANUAL') ?: '') === '1';

        if ($this->schoolFs === '' || $this->sessionYear === '') {
            echo "ERROR: Set SCHOOL_ID and SESSION_YEAR environment variables.\n";
            exit(1);
        }
    }

    /** Normalized match key: lowercased, trimmed, single-spaced. */
    private function nk(string $s): string
    {
        return strtolower(trim(preg_replace('/\s+/', ' ', $s)));
    }

    /** CLI: php index.php timetables_reconcile run */
    public function run(): void
    {
        echo "=== Timetables ⇄ subjectAssignments teacherId reconciliation ===\n";
        echo "Scope: schoolId={$this->schoolFs} session={$this->sessionYear}\n";
        echo "Mode:  " . ($this->apply ? "APPLY (writes enabled)" : "DRY-RUN (no writes)")
            . ($this->skipManual ? "  [skip manuallyEdited]" : "") . "\n";
        echo str_repeat('-', 72) . "\n\n";

        // ── 1. Load authoritative subject assignments ────────────────────
        //
        // TT-C1 — FAIL LOUD. firestoreQuery() returns [] when the request
        // fails; only firestoreQueryOrFail() throws. Reading the authority
        // with the swallowing variant meant a failed read arrived here as
        // "this school has no subject assignments", and a failed timetable
        // read (below) as "no drift found" — a reconciliation tool reporting
        // a clean bill of health because it could not see anything.
        try {
            $saRows = $this->firebase->firestoreQueryOrFail('subjectAssignments', [
                ['schoolId', '==', $this->schoolFs],
                ['session',  '==', $this->sessionYear],
            ], null, 'ASC', self::SCAN_LIMIT + 1);
        } catch (\Throwable $e) {
            echo "ERROR loading subjectAssignments: " . $e->getMessage() . "\n";
            exit(2);
        }
        if (count($saRows) > self::SCAN_LIMIT) {
            echo "ERROR: more than " . self::SCAN_LIMIT . " subject assignments in "
                . "scope. Reconciling against a truncated authority would report "
                . "drift that is not real and miss drift that is. Aborting.\n";
            exit(2);
        }

        // Authoritative maps:
        //   sectioned[ class | section | subjectName ] = [teacherId, teacherName]
        //   classwide[ class | subjectName ]           = [teacherId, teacherName]
        $sectioned = [];
        $classwide = [];
        $saTotal = 0; $saUsable = 0;
        foreach ($saRows as $r) {
            $d = is_array($r['data'] ?? null) ? $r['data'] : [];
            if (!empty($d['archived'])) continue;
            $saTotal++;
            $tid = trim((string)($d['teacherId'] ?? ''));
            if ($tid === '') continue;                 // no authority to assert
            $tname   = trim((string)($d['teacherName'] ?? ''));
            $cls     = $this->nk((string)($d['className'] ?? ''));
            $sec     = (string)($d['section'] ?? '');
            $subName = $this->nk((string)($d['subjectName'] ?? ($d['subjectCode'] ?? '')));
            if ($cls === '' || $subName === '') continue;
            $saUsable++;
            if ($sec === '' || $sec === '_ALL_') {
                $classwide["{$cls}|{$subName}"] = [$tid, $tname];
            } else {
                $secK = $this->nk($sec);
                $sectioned["{$cls}|{$secK}|{$subName}"] = [$tid, $tname];
            }
        }
        echo "subjectAssignments: {$saTotal} active, {$saUsable} with a teacher "
            . "(" . count($sectioned) . " sectioned, " . count($classwide) . " class-wide)\n\n";

        if ($saUsable === 0) {
            echo "No teacher-bearing assignments in scope — nothing to reconcile against. Aborting.\n";
            return;
        }

        // ── 2. Load timetables ───────────────────────────────────────────
        try {
            $ttRows = $this->firebase->firestoreQueryOrFail('timetables', [
                ['schoolId', '==', $this->schoolFs],
            ], null, 'ASC', self::SCAN_LIMIT + 1);
        } catch (\Throwable $e) {
            echo "ERROR loading timetables: " . $e->getMessage() . "\n";
            exit(2);
        }
        if (count($ttRows) > self::SCAN_LIMIT) {
            echo "ERROR: more than " . self::SCAN_LIMIT . " timetable documents for "
                . "this school. A truncated scan would report the documents it "
                . "happened to see as the whole picture. Aborting.\n";
            exit(2);
        }
        // Session filtered client-side (mirrors Timetable app reader).
        $ttRows = array_values(array_filter($ttRows, function ($r) {
            $d = is_array($r['data'] ?? null) ? $r['data'] : [];
            return (string)($d['session'] ?? '') === $this->sessionYear;
        }));
        echo "timetables in scope: " . count($ttRows) . "\n\n";

        // ── 3. Diff each period against authority ────────────────────────
        $mismatches   = [];   // flat list of change rows (for reporting)
        $docUpdates   = [];   // docId => [newPeriods, className, section, manuallyEdited]
        $projected    = [];   // docId => versionId that owns it (never touched)
        $noAuthority  = 0;    // class period whose subject has no teacher-bearing assignment
        $periodsSeen  = 0;
        $manualHit    = [];   // docIds that are manuallyEdited AND have changes

        foreach ($ttRows as $r) {
            $d = is_array($r['data'] ?? null) ? $r['data'] : [];
            $docId = (string)($r['id'] ?? '');
            $periods = $d['periods'] ?? null;
            if (!is_array($periods)) continue;

            $clsK = $this->nk((string)($d['className'] ?? ''));
            $secK = $this->nk((string)($d['section'] ?? ''));
            $day  = (string)($d['day'] ?? '');
            $isManual = ($d['manuallyEdited'] ?? null) === true;

            // Owned by the canonical projection: repairing it here would
            // change a teacher's timetable with no version to show for it,
            // and would be erased by the next projection anyway.
            //
            // The test is the shared one, so this tool and the session
            // rollover cannot drift apart about what "derived" means — they
            // are the only two writers left that have to make this call, and
            // a document one of them protects and the other does not is the
            // worst possible outcome.
            if (Timetable_projection_service::isDerived($d)) {
                $projected[$docId] = trim((string) $d['projectedFromVersion']);
                continue;
            }

            $newPeriods = $periods;
            $docChanged = false;

            foreach ($periods as $idx => $p) {
                if (!is_array($p)) continue;
                $type = (string)($p['type'] ?? 'class');
                $subj = trim((string)($p['subject'] ?? ''));
                if ($type !== 'class' || $subj === '') continue;   // skip break/lunch/empty
                $periodsSeen++;

                $subN = $this->nk($subj);
                $auth = $sectioned["{$clsK}|{$secK}|{$subN}"]
                    ?? $classwide["{$clsK}|{$subN}"]
                    ?? null;

                if ($auth === null) { $noAuthority++; continue; }

                [$authTid, $authTname] = $auth;
                $curTid = trim((string)($p['teacherId'] ?? ''));
                if ($curTid === $authTid) continue;                 // already correct

                // Record the change.
                $mismatches[] = [
                    'docId'    => $docId,
                    'day'      => $day,
                    'period'   => $p['periodNumber'] ?? ($idx + 1),
                    'class'    => (string)($d['className'] ?? ''),
                    'section'  => (string)($d['section'] ?? ''),
                    'subject'  => $subj,
                    'fromTid'  => $curTid === '' ? '(blank)' : $curTid,
                    'fromName' => (string)($p['teacher'] ?? ''),
                    'toTid'    => $authTid,
                    'toName'   => $authTname,
                    'manual'   => $isManual,
                ];

                if ($this->skipManual && $isManual) continue;       // report but don't stage

                $newPeriods[$idx]['teacherId'] = $authTid;
                $newPeriods[$idx]['teacher']   = $authTname !== '' ? $authTname : (string)($p['teacher'] ?? '');
                $docChanged = true;
            }

            if ($docChanged) {
                $docUpdates[$docId] = [
                    'periods'  => $newPeriods,
                    'version'  => (int)($d['version'] ?? 0) + 1,
                ];
                if ($isManual) $manualHit[] = $docId;
            }
        }

        // ── 4. Report ────────────────────────────────────────────────────
        echo "─── Findings ───\n";
        echo "Class-type periods examined: {$periodsSeen}\n";
        echo "Periods with no teacher-bearing assignment (left as-is): {$noAuthority}\n";
        echo "Teacher-id mismatches found: " . count($mismatches) . "\n";
        echo "Docs needing update: " . count($docUpdates) . "\n";
        // TT-C7 — this notice used to be nested INSIDE the manuallyEdited
        // branch below, so a run that skipped projected documents said nothing
        // at all unless the school also happened to have hand-edited documents
        // with drift. A tool that silently declines to do the job it was asked
        // to do reads as "there was nothing to fix", which is the opposite of
        // what happened. It is unconditional now.
        if (!empty($projected)) {
            echo "\n  ⓘ " . count($projected) . " document(s) are projections of a"
                . " published timetable and were NOT touched.\n"
                . "    They are rebuilt from the canonical version, so a repair here\n"
                . "    would bypass validation and be erased by the next projection.\n"
                . "    To correct these: change the subject assignments, re-check the\n"
                . "    affected timetable, and publish a new version.\n";
            foreach (array_slice($projected, 0, 5, true) as $id => $vid) {
                echo "      - {$id}  (from {$vid})\n";
            }
            if (count($projected) > 5) {
                echo "      … and " . (count($projected) - 5) . " more\n";
            }
        }

        if (!empty($manualHit)) {
            echo "  ⚠ of which manuallyEdited: " . count(array_unique($manualHit))
                . ($this->skipManual ? " (SKIPPED)" : " (WILL be overwritten)") . "\n";
        }
        echo "\n";

        if (!empty($mismatches)) {
            echo "─── Changes (period-level) ───\n";
            // group by doc for readability
            $byDoc = [];
            foreach ($mismatches as $m) $byDoc[$m['docId']][] = $m;
            foreach ($byDoc as $docId => $rows) {
                $first = $rows[0];
                $mflag = $first['manual'] ? ' [manuallyEdited]' : '';
                echo "  {$first['class']} / {$first['section']} — {$first['day']}  ({$docId}){$mflag}\n";
                foreach ($rows as $m) {
                    printf("      P%-2s %-24s  %s (%s)  →  %s (%s)\n",
                        (string)$m['period'],
                        mb_strimwidth($m['subject'], 0, 24),
                        $m['fromTid'], mb_strimwidth($m['fromName'], 0, 18),
                        $m['toTid'],   mb_strimwidth($m['toName'], 0, 18));
                }
            }
            echo "\n";
        }

        // ── 5. Apply ─────────────────────────────────────────────────────
        if (!$this->apply) {
            echo "DRY-RUN complete. Re-run with APPLY=1 to write "
                . count($docUpdates) . " doc(s).\n";
            return;
        }

        if (empty($docUpdates)) {
            echo "Nothing to write.\n";
            return;
        }

        echo "─── Applying ───\n";
        $ok = 0; $fail = 0;
        $stamp = date('c');
        foreach ($docUpdates as $docId => $u) {
            try {
                $this->firebase->firestoreUpdate('timetables', $docId, [
                    'periods'         => $u['periods'],
                    'version'         => $u['version'],
                    'updatedAt'       => $stamp,
                    'reconciledAt'    => $stamp,
                    'reconciledBy'    => 'timetables_reconcile',
                ]);
                $ok++;
                echo "  ✓ {$docId}\n";
            } catch (\Throwable $e) {
                $fail++;
                echo "  ✗ {$docId} — " . $e->getMessage() . "\n";
            }
        }
        echo "\nDone. Updated {$ok} doc(s), {$fail} failure(s).\n";
    }
}
