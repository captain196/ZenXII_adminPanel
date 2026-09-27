<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * SRNO-BACKFILL-1 (2026-09-19) — allocate SR numbers to students admitted
 * before the server-side allocator existed.
 *
 * ── WHY ──────────────────────────────────────────────────────────────────
 * SR No. is the school's own permanent register number and, since IDC-SRNO-1,
 * the identity printed on the ID card. Phase 9.1 made it server-allocated on
 * every admission — but only for admissions AFTER that point. On Vikrant, 23 of
 * 27 students predate it and carry no srNo at all, so their cards fall back to
 * the internal Student ID and the school's own numbering is simply absent from
 * its own register.
 *
 * ── HOW ──────────────────────────────────────────────────────────────────
 * Through `Sr_no_service::allocateNext()` — the SAME atomic allocator admission
 * uses. Not a loop writing srNo fields directly: the counter and the srNoIndex
 * rows are what make a number unique and reversible, and a backfill that wrote
 * around them would hand out numbers the allocator would later reissue.
 *
 * ── ORDER MATTERS ────────────────────────────────────────────────────────
 * Register numbers are historically sequential by admission. Students are
 * therefore processed oldest-admission-first, so the numbers a school ends up
 * with read the way a register reads, rather than following Firestore's
 * document order.
 *
 * ── SAFETY ───────────────────────────────────────────────────────────────
 *   - CLI ONLY (`is_cli()`), like every other backfill controller here.
 *   - DRY RUN BY DEFAULT. `--commit` is required to write.
 *   - IDEMPOTENT: a student who already has an srNo is skipped, so a re-run
 *     after a partial failure resumes rather than renumbering.
 *   - Active students only. A withdrawn or alumni record is history; issuing it
 *     a fresh register number now would be inventing one.
 *   - One tenant per run, named explicitly. There is no "all schools" mode:
 *     numbering is a per-school decision and a fleet-wide sweep is not a thing
 *     anyone should be able to trigger by omitting an argument.
 *
 * ── USAGE ────────────────────────────────────────────────────────────────
 *   php index.php srno_backfill plan    SCH_B56BB9A401
 *   php index.php srno_backfill execute SCH_B56BB9A401 --commit
 */
class Srno_backfill extends CI_Controller
{
    public function __construct()
    {
        parent::__construct();
        if (!is_cli()) show_404();

        // Firestore_service — the SAME object MY_Controller exposes as $this->fs,
        // and the one Sr_no_service expects. The distinction is not cosmetic: the
        // service carries docId(), the helper does not, and Sr_no_service calls it
        // on the very first line of allocateNext(). An earlier draft of this file
        // passed the helper and every allocation failed with
        // "Call to undefined method Firestore_helper::docId()".
        //
        // A CLI controller extends CI_Controller, so none of MY_Controller's setup
        // exists here — the tenant is supplied per command instead of from a
        // session that does not exist.
        $this->load->library('firebase');
        $this->load->library('firestore_service', null, 'fs');

        // Sr_no_service::claim() calls log_audit(), which lives in audit_helper and
        // is loaded by MY_Controller — not by CI_Controller, which is what a CLI
        // controller extends. Without it every allocation dies AFTER the srNoIndex
        // row has been written, leaving a number reserved against no student.
        $this->load->helper('audit');

        // cleanup() reads Sr_no_service::COUNTER_COL / COL statically. LIB-AUTOLOAD-1
        // resolves that, but loading it here keeps this controller runnable even if
        // the hook is ever disabled.
        $this->load->library('Sr_no_service', null, 'srnoSvcPreload');
    }

    /**
     * Bind the helper to one tenant.
     *
     * An SR number is session-FREE — it follows the child for their whole time at
     * the school — but a Firestore object must still be bound to a tenant, and the
     * given a non-empty session, and an unready helper silently returns [] from
     * every query. Passing a placeholder would make this work while quietly lying
     * about the tenant state, so the school's real current session is read and
     * used. A school with none configured cannot be backfilled, and says so.
     */
    private function _bind(string $schoolId): bool
    {
        $this->fs->init($schoolId);
        $school = $this->fs->get('schools', $schoolId);

        $session = trim((string) ($school['currentSession'] ?? ''));
        if ($session === '' && is_array($school['sessions'] ?? null) && $school['sessions']) {
            $session = (string) end($school['sessions']);
        }
        if ($session === '') {
            $this->_out("No current session on {$schoolId} — cannot bind Firestore_service.");
            return false;
        }

        $this->fs->init($schoolId, $session);

        // Fail LOUDLY and immediately if the object cannot do what Sr_no_service
        // needs. The alternative is what happened: 18 identical failures, one per
        // student, each reported as if that student were the problem.
        // ── PRECONDITIONS, CHECKED ONCE ─────────────────────────────────────
        // Everything Sr_no_service needs that this controller does not get for
        // free. Each of these has already caused 18 identical failures, each one
        // reported as though that student were at fault, and each one leaving
        // reserved counter values behind. Fail here instead: before the loop,
        // before the first write, with the actual cause named.
        // Every Firestore_service method this controller calls, checked in one place.
        // 'delete' was assumed and is actually 'remove' — the third time in this
        // session that an unverified method name reached a live run.
        foreach (['docId', 'get', 'update', 'remove', 'schoolWhere', 'where'] as $__m) {
            if (!method_exists($this->fs, $__m)) {
                $this->_out("PRECONDITION FAILED: Firestore object has no {$__m}().");
                return false;
            }
        }
        if (false) {
            $this->_out('PRECONDITION FAILED: the Firestore object has no docId().');
            $this->_out('Sr_no_service needs Firestore_service, not Firestore_helper.');
            return false;
        }
        if (!function_exists('log_audit')) {
            $this->_out('PRECONDITION FAILED: log_audit() is not loaded.');
            $this->_out("Sr_no_service::claim() calls it AFTER writing srNoIndex, so "
                . "running without it reserves numbers against no student.");
            return false;
        }
        return true;
    }

    /** Read-only: who is missing a number, and what order would they be given one. */
    public function plan($schoolId = '')
    {
        $rows = $this->_candidates($schoolId);
        if ($rows === null) return;

        $this->_out("SR NUMBER BACKFILL — PLAN (read-only)");
        $this->_out("tenant            : {$schoolId}");
        $this->_out("active students   : {$this->_stat['active']}");
        $this->_out("already numbered  : {$this->_stat['numbered']}");
        $this->_out("to allocate       : " . count($rows));
        $this->_out('');

        if (!$rows) { $this->_out('Nothing to do.'); return; }

        $this->_out(str_pad('STUDENT', 12) . str_pad('ADMITTED', 14) . str_pad('CLASS', 22) . 'NAME');
        foreach ($rows as $r) {
            $this->_out(
                str_pad($r['studentId'], 12)
                . str_pad($r['admDate'] !== '' ? $r['admDate'] : '(unknown)', 14)
                . str_pad(trim($r['class'] . ' ' . $r['section']), 22)
                . $r['name']
            );
        }
        $this->_out('');
        $this->_out('Numbers are allocated by Sr_no_service in this order. Re-run with:');
        $this->_out("  php index.php srno_backfill execute {$schoolId} --commit");
    }

    /** Allocate. Writes only with --commit. */
    public function execute($schoolId = '')
    {
        $commit = in_array('--commit', $this->_argv(), true);

        $rows = $this->_candidates($schoolId);
        if ($rows === null) return;

        $this->_out($commit
            ? '*** COMMIT MODE — SR numbers will be allocated ***'
            : 'DRY RUN — nothing will be written. Add --commit to apply.');
        $this->_out("tenant: {$schoolId}   to allocate: " . count($rows));
        $this->_out('');

        if (!$rows) { $this->_out('Nothing to do.'); return; }

        if (!$commit) {
            foreach ($rows as $r) $this->_out("  would allocate -> {$r['studentId']}  {$r['name']}");
            $this->_out("\nNo writes performed.");
            return;
        }

        $this->load->library('Sr_no_service', null, 'srnoSvc');
        $this->srnoSvc->init($this->firebase, $this->fs, $schoolId, 'srno_backfill');

        $done = 0; $failed = 0;
        foreach ($rows as $r) {
            try {
                // SELF-HEALING: if a previous pass already reserved a number for this
                // student, take that one. srNoIndex records currentStudentId, so the
                // reservation is recoverable — and allocating again would strand it.
                $existing = $this->_claimedSrNoFor($schoolId, $r['studentId']);
                if ($existing !== '') {
                    if ($this->_stampStudent($r['studentId'], $existing)) {
                        $this->_out("  {$r['studentId']}  ->  SR {$existing}  (reserved earlier; stamped now)");
                        $done++;
                    } else {
                        $this->_out("  FAILED {$r['studentId']}: could not stamp SR {$existing} on the student");
                        $failed++;
                    }
                    continue;
                }

                $res = $this->srnoSvc->allocateNext($schoolId, $r['studentId']);
                if (empty($res['ok'])) {
                    // Reported, never swallowed: a half-numbered register is worse
                    // than an unnumbered one because nobody can tell which is which.
                    $this->_out("  FAILED {$r['studentId']}: " . (string) ($res['error'] ?? 'unknown'));
                    $failed++;
                    continue;
                }
                // The allocator reserved it. It is not the student's until it is ON
                // the student — this is the step whose absence produced "allocated: 18"
                // over a roster where nothing had changed.
                $sr = (string) ($res['srNo'] ?? '');
                if ($sr === '' || !$this->_stampStudent($r['studentId'], $sr)) {
                    $this->_out("  FAILED {$r['studentId']}: SR {$sr} was reserved but could not be "
                        . "written to the student. Re-run to stamp it — the reservation is kept.");
                    $failed++;
                    continue;
                }
                $this->_out("  {$r['studentId']}  ->  SR {$sr}");
                $done++;
            } catch (\Throwable $e) {
                $this->_out("  FAILED {$r['studentId']}: " . $e->getMessage());
                $failed++;
            }
        }

        $this->_out('');
        $this->_out("allocated: {$done}   failed: {$failed}");
        if ($failed) {
            $this->_out('Re-run the same command to retry only the failures — students that');
            $this->_out('already received a number are skipped.');
        }
    }

    /**
     * Release SR numbers reserved by a run that died part-way.
     *
     * allocateNext() reserves a candidate by creating a counter CLAIM document,
     * then calls claim() which writes the srNoIndex row and only afterwards
     * stamps the student. A fatal between those steps leaves the number burned:
     * a claim doc (and possibly an index row) exists, no student holds it, and
     * the next run starts above it.
     *
     * This releases exactly those numbers — a claim or index entry whose srNo is
     * held by NO student — and rewinds the counter to the highest number that IS
     * genuinely held, so the register continues from the right place instead of
     * starting at 41 because of a bug.
     *
     * What it will never touch: a number a student actually holds. That is the
     * whole safety property, and it is decided per number by reading the roster,
     * not by trusting the range the caller asked about.
     */
    public function cleanup($schoolId = '')
    {
        $schoolId = trim((string) $schoolId);
        if ($schoolId === '' || !preg_match('~^SCH_[A-Z0-9]+$~', $schoolId)) {
            $this->_out('Usage: php index.php srno_backfill cleanup SCH_XXXXXXXX [--commit]');
            return;
        }
        if (!$this->_bind($schoolId)) return;

        $commit = in_array('--commit', $this->_argv(), true);
        $this->_out($commit
            ? '*** COMMIT MODE — orphaned SR reservations will be released ***'
            : 'DRY RUN — nothing will be written. Add --commit to apply.');
        $this->_out("tenant: {$schoolId}");
        $this->_out('');

        // Which numbers are genuinely held, straight from the roster.
        $held = [];
        foreach ($this->fs->schoolWhere('students') as $d) {
            $v  = is_array($d['data'] ?? null) ? $d['data'] : $d;
            $sr = trim((string) ($v['srNo'] ?? ''));
            if ($sr !== '') $held[$sr] = (string) ($v['studentId'] ?? '?');
        }
        $this->_out('SR numbers held by a student: ' . (count($held) ? implode(', ', array_keys($held)) : '(none)'));

        $prefix   = Sr_no_service::COUNTER_DOC_PREFIX . $schoolId;
        $released = 0; $kept = 0;

        // ── counter CLAIM documents ─────────────────────────────────────
        foreach ($this->fs->where(Sr_no_service::COUNTER_COL, []) as $d) {
            $docId = (string) ($d['id'] ?? '');
            if (strpos($docId, $prefix . '_claim_') !== 0) continue;

            $n = substr($docId, strlen($prefix . '_claim_'));
            if (isset($held[$n])) {
                $kept++;
                continue;   // a real student holds this number
            }

            if ($commit) {
                $this->fs->remove(Sr_no_service::COUNTER_COL, $docId);
            }
            $this->_out(($commit ? '  released claim  ' : '  would release claim  ') . "SR {$n}");
            $released++;
        }

        // ── orphaned srNoIndex rows ─────────────────────────────────────
        $idxReleased = 0;
        foreach ($this->fs->where(Sr_no_service::COL, []) as $d) {
            $v   = is_array($d['data'] ?? null) ? $d['data'] : $d;
            if ((string) ($v['schoolId'] ?? '') !== $schoolId) continue;

            $sr  = trim((string) ($v['srNo'] ?? $v['srNoNorm'] ?? ''));
            $stu = trim((string) ($v['studentId'] ?? ''));
            if ($sr === '') continue;

            // Held by a student, or claiming to belong to one: leave it alone.
            if (isset($held[$sr]) || $stu !== '') continue;

            if ($commit) {
                $this->fs->remove(Sr_no_service::COL, (string) ($d['id'] ?? ''));
            }
            $this->_out(($commit ? '  released index  ' : '  would release index  ') . "SR {$sr}");
            $idxReleased++;
        }

        // ── rewind the counter to the highest number actually held ──────
        $high = 0;
        foreach (array_keys($held) as $sr) {
            // (string) is REQUIRED. PHP turns numeric array keys into INTEGERS, and
            // ctype_digit() given an int between -128 and 255 treats it as an ASCII
            // CODEPOINT, not a character — so ctype_digit(4) is false. Without the
            // cast the counter rewound to 0 and the next allocation would have
            // collided with the SR 1 a student already holds.
            $sr = (string) $sr;
            if (ctype_digit($sr) && (int) $sr > $high) $high = (int) $sr;
        }
        $counterId = $prefix;
        $before    = $this->fs->get(Sr_no_service::COUNTER_COL, $counterId);
        $beforeVal = (int) ($before['value'] ?? 0);

        $this->_out('');
        $this->_out("counter: {$beforeVal}  ->  {$high}   (highest number a student actually holds)");
        if ($commit && $beforeVal !== $high) {
            $this->fs->update(Sr_no_service::COUNTER_COL, $counterId, [
                'value'     => $high,
                'updatedAt' => date('c'),
            ]);
        }

        $this->_out('');
        $this->_out("claims released: {$released}   index rows released: {$idxReleased}   kept (in use): {$kept}");
        if (!$commit) $this->_out('No writes performed.');
    }

    // ══ internals ════════════════════════════════════════════════════════

    private $_stat = ['active' => 0, 'numbered' => 0];

    /**
     * Active students with no srNo, oldest admission first.
     * Returns null (after printing why) if the tenant argument is unusable.
     */
    private function _candidates($schoolId)
    {
        $schoolId = trim((string) $schoolId);
        if ($schoolId === '' || !preg_match('~^SCH_[A-Z0-9]+$~', $schoolId)) {
            $this->_out('Usage: php index.php srno_backfill plan|execute SCH_XXXXXXXX [--commit]');
            $this->_out('A canonical school id is required — numbering is per-school.');
            return null;
        }

        if (!$this->_bind($schoolId)) return null;

        // querySchool() adds the schoolId condition itself, in this codebase's
        // operator convention ('=' , not '=='), which is what an earlier hand-built
        // condition got wrong — it matched nothing and reported "0 active students"
        // as though the school were empty.
        $docs = $this->fs->schoolWhere('students');
        $rows = [];

        foreach ($docs as $d) {
            $v = is_array($d['data'] ?? null) ? $d['data'] : $d;

            $status = strtolower(trim((string) ($v['status'] ?? $v['Status'] ?? 'active')));
            if ($status !== '' && $status !== 'active') continue;
            $this->_stat['active']++;

            if (trim((string) ($v['srNo'] ?? '')) !== '') { $this->_stat['numbered']++; continue; }

            $rows[] = [
                'studentId' => (string) ($v['studentId'] ?? $v['User Id'] ?? ''),
                'name'      => (string) ($v['name'] ?? $v['Name'] ?? ''),
                'class'     => (string) ($v['className'] ?? $v['Class'] ?? ''),
                'section'   => (string) ($v['section'] ?? $v['Section'] ?? ''),
                'admDate'   => (string) ($v['Admission Date'] ?? $v['admissionDate'] ?? ''),
            ];
        }

        // Oldest admission first. Dates are stored d-m-Y, which does not sort as a
        // string, so they are normalised to a sortable key; an unparseable or
        // missing date sorts LAST rather than silently landing at the front of the
        // register.
        usort($rows, function ($a, $b) {
            $ka = $this->_sortKey($a['admDate']);
            $kb = $this->_sortKey($b['admDate']);
            return $ka === $kb ? strcmp($a['studentId'], $b['studentId']) : strcmp($ka, $kb);
        });

        return array_values(array_filter($rows, function ($r) { return $r['studentId'] !== ''; }));
    }

    /**
     * The SR number already reserved for this student, or ''.
     *
     * srNoIndex is the ownership record: claim() stamps currentStudentId on it.
     * That makes a half-finished pass recoverable instead of a source of
     * duplicate numbering.
     */
    private function _claimedSrNoFor(string $schoolId, string $studentId): string
    {
        foreach ($this->fs->where(Sr_no_service::COL, [
            ['schoolId', '=', $schoolId],
            ['currentStudentId', '=', $studentId],
        ]) as $d) {
            $v = is_array($d['data'] ?? null) ? $d['data'] : $d;
            $sr = trim((string) ($v['srNo'] ?? ''));
            if ($sr !== '') return $sr;
        }
        return '';
    }

    /**
     * Write the number onto the student, in the SAME field shape
     * _claimIdentityAttributes() uses at admission — srNo, srNoNorm and
     * srNoSource — so a backfilled student is indistinguishable from an
     * admitted one to every reader. srNoSource says how it got there.
     */
    private function _stampStudent(string $studentId, string $srNo): bool
    {
        $norm = Sr_no_service::normalize($srNo);

        return (bool) $this->fs->update('students', $this->fs->docId($studentId), [
            'srNo'       => $srNo,
            'srNoNorm'   => $norm['norm'] ?? '',
            'srNoSource' => 'backfill',
            'updatedAt'  => date('c'),
        ]);
    }

    /** d-m-Y -> Ymd, or '99999999' so unknown dates sort last. */
    private function _sortKey(string $raw): string
    {
        $raw = trim($raw);
        if ($raw === '') return '99999999';

        foreach (['d-m-Y', 'Y-m-d', 'd/m/Y'] as $fmt) {
            $dt  = DateTimeImmutable::createFromFormat('!' . $fmt, $raw);
            $err = DateTimeImmutable::getLastErrors();
            if ($err === false) $err = ['warning_count' => 0, 'error_count' => 0];
            if ($dt instanceof DateTimeImmutable
                && (int) $err['warning_count'] === 0 && (int) $err['error_count'] === 0) {
                return $dt->format('Ymd');
            }
        }
        return '99999999';
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
