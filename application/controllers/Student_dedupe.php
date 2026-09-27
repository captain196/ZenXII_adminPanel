<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * DEDUPE-1 (2026-09-20) — retire duplicate student records.
 *
 * ── WHY THIS EXISTS ──────────────────────────────────────────────────────
 * Direct admission had no person-level duplicate check until ADM-DUP-1: the
 * only guard that called itself one tested whether the ID GENERATOR had
 * collided. Admitting the same child twice was therefore unobstructed, and
 * Vikrant carries the result — Saanvi Reddy and Kabir Agarwal each exist three
 * times with identical name, DOB, father, email, class, section and admission
 * date, differing only in studentId.
 *
 * The cost is not cosmetic. Each copy occupies a seat in section capacity,
 * counts once in the roll and in a UDISE+ return, and — once SRNO-BACKFILL-1
 * runs — would receive its own PERMANENT register number. An SR number is the
 * one identifier meant never to change, so numbering a duplicate and unwinding
 * it afterwards means relinking SR numbers. Retiring first is much cheaper.
 *
 * ── WHAT "RETIRE" MEANS ──────────────────────────────────────────────────
 * `status = 'Inactive'`, exactly as Sis::change_status() writes it, plus the
 * same STATUS_CHANGE history entry through Student_history_service. NOT a
 * delete: the document survives, so the trail of what was here and why it was
 * retired survives with it. Reversing it is one status flip.
 *
 * Inactive — not 'TC' and not 'Alumni'. Those are real outcomes for a real
 * child; a record created in error did not transfer anywhere and did not
 * graduate, and recording it as though it had would corrupt the very reports
 * this is meant to protect.
 *
 * ── SAFETY ───────────────────────────────────────────────────────────────
 *   - CLI ONLY, dry-run by default, `--commit` required to write.
 *   - REFUSES to retire a student that has ANY feeDemands or attendance
 *     history. A record with money or marks against it is not a phantom, and
 *     the caller has identified the wrong copy. Checked at write time, not
 *     merely when the list was drawn up.
 *   - Student ids are passed explicitly. There is no "find and kill duplicates"
 *     mode: deciding which copy is real is a human judgement, and a heuristic
 *     that got it wrong would retire the record carrying the history.
 *
 * ── USAGE ────────────────────────────────────────────────────────────────
 *   php index.php student_dedupe inspect SCH_B56BB9A401 STU0028 STU0030
 *   php index.php student_dedupe retire  SCH_B56BB9A401 STU0028 STU0030 --commit
 */
class Student_dedupe extends CI_Controller
{
    public function __construct()
    {
        parent::__construct();
        if (!is_cli()) show_404();

        // Firestore_service — the same object MY_Controller exposes as $this->fs.
        // It carries docId() and the schoolWhere() query family; Firestore_helper is
        // a DIFFERENT class with an overlapping but not identical surface (no
        // docId()), which is what an earlier draft of this file reached for.
        $this->load->library('firebase');
        $this->load->library('firestore_service', null, 'fs');
    }

    /** Read-only: what is on these records, and what depends on them. */
    public function inspect($schoolId = '', $ids = '')
    {
        $ctx = $this->_context($schoolId, $ids);
        if ($ctx === null) return;

        foreach ($ctx['ids'] as $id) {
            $this->_report($id, $ctx['schoolId']);
        }
    }

    /** Set status = Inactive. Writes only with --commit. */
    public function retire($schoolId = '', $ids = '')
    {
        $ctx = $this->_context($schoolId, $ids);
        if ($ctx === null) return;

        $commit = in_array('--commit', $this->_argv(), true);
        $this->_out($commit
            ? '*** COMMIT MODE — these records will be set Inactive ***'
            : 'DRY RUN — nothing will be written. Add --commit to apply.');
        $this->_out('');

        $done = 0; $refused = 0;

        foreach ($ctx['ids'] as $id) {
            $doc = $this->fs->get('students', $this->fs->docId($id));
            if (!is_array($doc)) {
                $this->_out("  REFUSED {$id}: no such student in {$schoolId}");
                $refused++;
                continue;
            }

            $name   = (string) ($doc['name'] ?? $doc['Name'] ?? '');
            $before = trim((string) ($doc['status'] ?? $doc['Status'] ?? ''));

            if (strcasecmp($before, 'Active') !== 0) {
                $this->_out("  SKIP    {$id} ({$name}): already '{$before}'");
                continue;
            }

            // Re-checked HERE, not when the list was built: a demand raised
            // between the two would otherwise be retired out from under.
            $deps = $this->_dependants($ctx['schoolId'], $id);
            if ($deps['total'] > 0) {
                $this->_out("  REFUSED {$id} ({$name}): has {$deps['demands']} fee demand(s) and "
                    . "{$deps['attendance']} attendance record(s). This is not a phantom — "
                    . 'check which copy you meant.');
                $refused++;
                continue;
            }

            if (!$commit) {
                $this->_out("  would retire {$id} ({$name})  Active -> Inactive");
                continue;
            }

            // Same write Sis::change_status() performs: camelCase `status` only.
            $ok = $this->fs->update('students', $this->fs->docId($id), [
                'status'    => 'Inactive',
                'updatedAt' => date('c'),
            ]);
            if (!$ok) {
                $this->_out("  FAILED  {$id} ({$name}): Firestore update returned false");
                $refused++;
                continue;
            }

            // Same trail the UI leaves, so this is indistinguishable in the
            // student's timeline from an operator doing it by hand — except
            // that the reason says plainly what happened.
            $this->load->library('Student_history_service', null, 'histSvc');
            $this->histSvc->init($this->firebase, 'student_dedupe')->log(
                $ctx['schoolId'], $id, 'STATUS_CHANGE',
                "Retired as a duplicate record (Active -> Inactive). An identical student "
                . "exists with the same name, date of birth, father's name, class and section; "
                . "this copy had no fee or attendance history.",
                ['from' => 'Active', 'to' => 'Inactive', 'reason' => 'duplicate_record']
            );

            $this->_out("  RETIRED {$id} ({$name})");
            $done++;
        }

        $this->_out('');
        $this->_out($commit ? "retired: {$done}   refused: {$refused}"
                            : "No writes performed.");

        if ($commit && $done > 0) {
            $this->_out('');
            $this->_out('Section occupancy is recomputed lazily by the roster and the');
            $this->_out('admission seat guard, both of which count Active students only —');
            $this->_out('so the freed seats appear on the next read.');
        }
    }

    // ══ internals ════════════════════════════════════════════════════════

    private function _context($schoolId, $ids)
    {
        $schoolId = trim((string) $schoolId);
        if ($schoolId === '' || !preg_match('~^SCH_[A-Z0-9]+$~', $schoolId)) {
            $this->_out('Usage: php index.php student_dedupe inspect|retire SCH_XXXXXXXX STU0001 STU0002 [--commit]');
            return null;
        }

        // Ids come from argv, not from the URI segment. CodeIgniter's
        // permitted_uri_chars rejects a comma outright ('The URI you submitted has
        // disallowed characters'), so a comma-separated segment can never reach
        // this method. Reading argv also means the ids are taken exactly as typed,
        // with no URI decoding in between.
        $list = [];
        foreach ($this->_argv() as $a) {
            if (preg_match('~^STU[0-9]+$~', (string) $a)) $list[] = (string) $a;
        }

        // Anything that looks like it was MEANT to be an id but is not one must
        // stop the run rather than be silently skipped — a typo'd id would
        // otherwise retire a shorter list than the operator believes.
        foreach ($this->_argv() as $a) {
            $a = (string) $a;
            // Case-SENSITIVE: a lowercase ~^STU~i also matches the controller name
            // 'student_dedupe' sitting in argv, which made the guard refuse every run.
            if (preg_match('~^STU~', $a) && !preg_match('~^STU[0-9]+$~', $a)) {
                $this->_out("Refusing to act: '{$a}' is not a valid student id (expected STU0001).");
                return null;
            }
        }
        unset($ids);   // documented in the signature; argv is the authority
        if (!$list) { $this->_out('No student ids given.'); return null; }

        // Firestore_helper only marks itself ready with a non-empty session, and
        // an unready helper silently returns [] / false from everything.
        $this->fs->init($schoolId);
        $school  = $this->fs->get('schools', $schoolId);
        $session = trim((string) ($school['currentSession'] ?? ''));
        if ($session === '' && is_array($school['sessions'] ?? null) && $school['sessions']) {
            $session = (string) end($school['sessions']);
        }
        if ($session === '') { $this->_out("No current session on {$schoolId}."); return null; }

        $this->fs->init($schoolId, $session);

        return ['schoolId' => $schoolId, 'session' => $session, 'ids' => $list];
    }

    private function _dependants(string $schoolId, string $studentId): array
    {
        // schoolWhere() adds the tenant condition itself — the one thing that must
        // never be forgotten on a read that decides whether a record is disposable.
        $demands = $this->fs->schoolWhere('feeDemands', [['studentId', '=', $studentId]]);
        $att     = $this->fs->schoolWhere('attendanceSummary', [['studentId', '=', $studentId]]);

        $d = is_array($demands) ? count($demands) : 0;
        $a = is_array($att) ? count($att) : 0;

        return ['demands' => $d, 'attendance' => $a, 'total' => $d + $a];
    }

    private function _report(string $id, string $schoolIdForReport)
    {
        $doc = $this->fs->get('students', $this->fs->docId($id));
        if (!is_array($doc)) { $this->_out("{$id}: not found"); return; }

        $deps = $this->_dependants($schoolIdForReport, $id);

        $this->_out("── {$id} ──");
        foreach ([
            'name' => $doc['name'] ?? $doc['Name'] ?? '',
            'dob'  => $doc['dob'] ?? $doc['DOB'] ?? '',
            'father' => $doc['fatherName'] ?? $doc['Father Name'] ?? '',
            'class'  => trim(((string) ($doc['className'] ?? $doc['Class'] ?? '')) . ' '
                            . ((string) ($doc['section'] ?? $doc['Section'] ?? ''))),
            'phone'  => $doc['phone'] ?? $doc['Phone Number'] ?? '',
            'status' => $doc['status'] ?? $doc['Status'] ?? '',
            'srNo'   => $doc['srNo'] ?? '(none)',
        ] as $k => $v) {
            $this->_out('   ' . str_pad($k, 8) . ': ' . (string) $v);
        }
        $this->_out("   depends : {$deps['demands']} fee demand(s), {$deps['attendance']} attendance record(s)");
        $this->_out('');
    }

    private function _argv(): array
    {
        return isset($_SERVER['argv']) && is_array($_SERVER['argv']) ? $_SERVER['argv'] : [];
    }

    private function _out(string $line)
    {
        fwrite(STDOUT, $line . PHP_EOL);
    }
}
