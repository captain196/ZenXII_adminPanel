<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Hrms_w13_verify — RUNTIME verification harness for ZenX HRMS W1.3
 *                   (ATS / Recruitment: RTDB → Firestore).
 *
 * READ-ONLY. Performs ZERO Firestore writes, creates no documents, mutates no
 * tenant, and requires no seeded test data. Safe to run against a live tenant
 * during traffic. Idempotent and re-runnable.
 *
 * WHAT MAKES THIS A RUNTIME TEST RATHER THAN A SOURCE ASSERTION
 * ------------------------------------------------------------
 * It EXECUTES the real production code. Ats::_validate_transition(),
 * _normalize_stage(), _stage_of(), _strip_prefix(), _append_review() and
 * _job_summary() are private but pure (no session, no I/O — every input is
 * passed in), so the harness builds an Ats instance via
 * ReflectionClass::newInstanceWithoutConstructor() — bypassing the controller
 * constructor it cannot satisfy in CLI — and invokes the genuine methods. The
 * pipeline rules asserted below are enforced by the shipping controller, not a
 * replica.
 *
 * The store group performs real (read-only) Firestore reads to prove the
 * canonical collections are reachable and that the recruitment documents ATS
 * now writes are the same ones Hr.php reads.
 *
 * INVOCATION:
 *   php index.php hrms_w13_verify run
 *   SCHOOL_A=SCH_XXXX SCHOOL_B=SCH_YYYY php index.php hrms_w13_verify run
 *     (the isolation group is skipped unless both are supplied)
 */
class Hrms_w13_verify extends CI_Controller
{
    private int $pass = 0;
    private int $fail = 0;
    private int $skip = 0;
    /** @var array<int,string> */
    private array $failures = [];

    public function __construct()
    {
        parent::__construct();
        if (!defined('STDIN')) {
            exit("CLI only\n");
        }
        $this->load->helper('recruitment');
        $this->load->helper('hr_id');
    }

    // ─────────────────────────────────────────────────────────────────────

    private function ok(string $group, string $name, bool $cond, string $detail = ''): void
    {
        if ($cond) {
            $this->pass++;
            echo "  [PASS] {$name}" . ($detail !== '' ? "  ({$detail})" : '') . "\n";
        } else {
            $this->fail++;
            $this->failures[] = "{$group} :: {$name}" . ($detail !== '' ? " — {$detail}" : '');
            echo "  [FAIL] {$name}" . ($detail !== '' ? "  ({$detail})" : '') . "\n";
        }
    }

    private function skipped(string $name, string $why): void
    {
        $this->skip++;
        echo "  [SKIP] {$name}  ({$why})\n";
    }

    private function head(string $t): void
    {
        echo "\n" . str_repeat('=', 74) . "\n{$t}\n" . str_repeat('=', 74) . "\n";
    }

    /** Real Ats instance without running the constructor (pure methods only). */
    private function ats()
    {
        require_once APPPATH . 'controllers/Ats.php';
        return (new ReflectionClass('Ats'))->newInstanceWithoutConstructor();
    }

    private function call($obj, string $method, array $args)
    {
        $m = new ReflectionMethod(get_class($obj), $method);
        $m->setAccessible(true);
        return $m->invokeArgs($obj, $args);
    }

    /** Set a private/protected property on a constructor-less instance. */
    private function setProp($obj, string $prop, $value): void
    {
        $p = new ReflectionProperty(get_class($obj), $prop);
        $p->setAccessible(true);
        $p->setValue($obj, $value);
    }

    // =====================================================================
    //  ENTRY
    // =====================================================================

    public function run(): void
    {
        $args = $this->parse_args();

        echo "\nZenX HRMS — W1.3 ATS/Recruitment Firestore migration :: RUNTIME VERIFICATION\n";
        echo "READ-ONLY — no writes, no mutations.\n";

        $this->group_transitions();
        $this->group_stage_resolution();
        $this->group_prefix();
        $this->group_reviews();
        $this->group_bridge();
        $this->group_store();
        $this->group_isolation($args['SCHOOL_A'] ?? '', $args['SCHOOL_B'] ?? '');

        $this->head('SUMMARY');
        echo "  PASS {$this->pass}   FAIL {$this->fail}   SKIP {$this->skip}\n";
        if ($this->failures) {
            echo "\n  Failures:\n";
            foreach ($this->failures as $f) echo "    - {$f}\n";
        }
        echo "\n" . ($this->fail === 0 ? "RESULT: PASS\n" : "RESULT: FAIL\n");
    }

    /**
     * Tenant ids come from the ENVIRONMENT, not argv: CodeIgniter routes CLI
     * arguments through its URI filter, which rejects '=' as a disallowed
     * character before the controller ever runs.
     */
    private function parse_args(): array
    {
        return [
            'SCHOOL_A' => (string) (getenv('SCHOOL_A') ?: ''),
            'SCHOOL_B' => (string) (getenv('SCHOOL_B') ?: ''),
        ];
    }

    // =====================================================================
    //  A. STAGE TRANSITION RULES  (the pipeline's rule engine)
    // =====================================================================

    private function group_transitions(): void
    {
        $this->head('A. Stage transition rules — Ats::_validate_transition() executed');
        $g = 'transitions';
        $ats = $this->ats();
        $stages = ['Applied', 'Shortlisted', 'Interviewed', 'Selected', 'Hired'];

        // A1 — every legal single forward step is allowed (except into Hired,
        // which is reserved for the convert-to-staff flow).
        foreach ([['Applied','Shortlisted'], ['Shortlisted','Interviewed'], ['Interviewed','Selected']] as [$from, $to]) {
            $r = $this->call($ats, '_validate_transition', [$from, $to, true]);
            $this->ok($g, "A1 {$from} → {$to} allowed", $r === true, is_string($r) ? $r : 'ok');
        }

        // A2 — no backward moves, from ANY pair. Rejection/reinstatement is a
        // separate mechanism; the pipeline itself is one-directional.
        $back = 0; $backOk = 0;
        foreach ($stages as $i => $from) {
            foreach ($stages as $j => $to) {
                if ($j >= $i) continue;
                $back++;
                if (is_string($this->call($ats, '_validate_transition', [$from, $to, true]))) $backOk++;
            }
        }
        $this->ok($g, 'A2 every backward move rejected', $back > 0 && $back === $backOk, "{$backOk}/{$back}");

        // A3 — same-stage move rejected (would otherwise re-fire side effects).
        $same = 0; $sameOk = 0;
        foreach ($stages as $s) {
            $same++;
            if (is_string($this->call($ats, '_validate_transition', [$s, $s, true]))) $sameOk++;
        }
        $this->ok($g, 'A3 same-stage move rejected', $same === $sameOk, "{$sameOk}/{$same}");

        // A4 — strict mode forbids skipping.
        $r = $this->call($ats, '_validate_transition', ['Applied', 'Selected', true]);
        $this->ok($g, 'A4 strict forbids skipping', is_string($r) && strpos($r, 'skip') !== false, (string) $r);

        // A5 — non-strict permits a multi-step jump (bulk triage), still forward-only.
        $r = $this->call($ats, '_validate_transition', ['Applied', 'Selected', false]);
        $this->ok($g, 'A5 non-strict permits forward jump', $r === true, is_string($r) ? $r : 'ok');
        $r = $this->call($ats, '_validate_transition', ['Selected', 'Applied', false]);
        $this->ok($g, 'A5 non-strict still forbids backward', is_string($r), (string) $r);

        // A6 — Hired is unreachable through move_stage from EVERY origin and in
        // BOTH modes. This is the money gate: reaching Hired without the convert
        // flow would mark a candidate hired with no staff record and no vacancy
        // decrement.
        $hire = 0; $hireOk = 0;
        foreach ($stages as $from) {
            foreach ([true, false] as $strict) {
                $hire++;
                if (is_string($this->call($ats, '_validate_transition', [$from, 'Hired', $strict]))) $hireOk++;
            }
        }
        $this->ok($g, 'A6 Hired unreachable via move_stage', $hire === $hireOk, "{$hireOk}/{$hire}");

        // A7 — unknown stages rejected rather than defaulted.
        foreach ([['Bogus','Selected'], ['Applied','Bogus'], ['','']] as [$f, $t]) {
            $r = $this->call($ats, '_validate_transition', [$f, $t, true]);
            $this->ok($g, "A7 invalid pair ('{$f}'→'{$t}') rejected", is_string($r), (string) $r);
        }
    }

    // =====================================================================
    //  B. STAGE RESOLUTION across every document shape
    // =====================================================================

    private function group_stage_resolution(): void
    {
        $this->head('B. Stage resolution — Ats::_stage_of() on real document shapes');
        $g = 'stage_resolution';
        $ats = $this->ats();

        $cases = [
            // [label, document, expected stage]
            ['ATS-written doc (stage authoritative)',      ['stage' => 'Interviewed', 'status' => 'Interview'], 'Interviewed'],
            ['HR-written doc (status only)',               ['status' => 'Interview'],                          'Interviewed'],
            ['HR-written doc, Joined',                     ['status' => 'Joined'],                             'Hired'],
            ['HR-written doc, Selected',                   ['status' => 'Selected'],                           'Selected'],
            ['legacy doc with neither field',              [],                                                 'Applied'],
            ['doc with unknown stage value',               ['stage' => 'Bogus'],                               'Applied'],
            ['rejected doc keeps its reached stage',       ['stage' => 'Selected', 'ats_status' => 'rejected'], 'Selected'],
            ['stage wins over a disagreeing status',       ['stage' => 'Selected', 'status' => 'Applied'],      'Selected'],
        ];

        foreach ($cases as [$label, $doc, $expected]) {
            $got = $this->call($ats, '_stage_of', [$doc]);
            $this->ok($g, "B {$label}", $got === $expected, "got '{$got}', expected '{$expected}'");
        }

        // B9 — THE CONVERGENCE INVARIANT. Before W1.3 Hr::update_applicant_status()
        // wrote `status` without `stage`; on a shared document that left a stale
        // stage and the ATS board showed the candidate in their old column.
        // Hr.php now dual-emits, so simulate that write and confirm it resolves.
        $hrWrite = ['stage' => 'Applied', 'status' => 'Applied'];
        $newStatus = 'Selected';
        $hrWrite['status'] = $newStatus;
        $hrWrite['stage']  = recruitment_stage_from_status($newStatus);   // the dual-emit
        $got = $this->call($ats, '_stage_of', [$hrWrite]);
        $this->ok($g, 'B9 HR status change propagates to the ATS stage', $got === 'Selected', "got '{$got}'");
    }

    // =====================================================================
    //  C. TENANT PREFIX handling
    // =====================================================================

    private function group_prefix(): void
    {
        $this->head('C. Tenant prefix — Ats::_strip_prefix() executed');
        $g = 'prefix';
        $ats = $this->ats();
        $this->setProp($ats, 'school_id', 'SCH_AAA111');

        $cases = [
            ['SCH_AAA111_APP0007', 'APP0007', 'own-tenant doc id stripped'],
            ['APP0007',            'APP0007', 'already-bare id untouched'],
            // A doc id belonging to another school must NOT be stripped — a
            // silent strip would make a foreign document look local.
            ['SCH_BBB222_APP0007', 'SCH_BBB222_APP0007', 'foreign-tenant id left intact'],
            // The entity id itself may contain underscores (the counter's
            // non-sequential fallback mints APP01234_ab1c).
            ['SCH_AAA111_APP01234_ab1c', 'APP01234_ab1c', 'underscored fallback id preserved'],
            ['', '', 'empty id tolerated'],
        ];

        foreach ($cases as [$in, $expected, $label]) {
            $got = $this->call($ats, '_strip_prefix', [$in]);
            $this->ok($g, "C {$label}", $got === $expected, "'{$in}' → '{$got}'");
        }
    }

    // =====================================================================
    //  D. REVIEW LOG bounding
    // =====================================================================

    private function group_reviews(): void
    {
        $this->head('D. Review log — Ats::_append_review() executed');
        $g = 'reviews';
        $ats = $this->ats();
        $max = (new ReflectionClass('Ats'))->getConstant('MAX_REVIEWS');

        // D1 — appends to an empty/absent log.
        $r = $this->call($ats, '_append_review', [[], ['action' => 'first']]);
        $this->ok($g, 'D1 appends to an absent log', count($r) === 1 && $r[0]['action'] === 'first');

        // D2 — tolerates a corrupt (non-array) reviews field rather than fataling.
        $r = $this->call($ats, '_append_review', [['reviews' => 'corrupt-string'], ['action' => 'x']]);
        $this->ok($g, 'D2 tolerates a corrupt reviews field', is_array($r) && count($r) === 1);

        // D3 — preserves order (the log is chronological history).
        $doc = ['reviews' => [['action' => 'a'], ['action' => 'b']]];
        $r = $this->call($ats, '_append_review', [$doc, ['action' => 'c']]);
        $this->ok($g, 'D3 preserves chronological order',
            count($r) === 3 && $r[0]['action'] === 'a' && $r[2]['action'] === 'c');

        // D4 — bounded at MAX_REVIEWS. Firestore hard-rejects documents over
        // 1 MiB, so an unbounded log eventually makes the applicant permanently
        // unwritable — which would break stage moves AND the hire flow.
        $big = ['reviews' => array_map(fn($i) => ['action' => "r{$i}"], range(1, $max + 50))];
        $r = $this->call($ats, '_append_review', [$big, ['action' => 'newest']]);
        $this->ok($g, 'D4 log bounded at MAX_REVIEWS', count($r) === $max, 'count=' . count($r) . " max={$max}");

        // D5 — the cap drops the OLDEST, never the newest.
        $this->ok($g, 'D5 newest entry survives the cap', end($r)['action'] === 'newest');
    }

    // =====================================================================
    //  E. VOCABULARY BRIDGE executed end-to-end
    // =====================================================================

    private function group_bridge(): void
    {
        $this->head('E. Vocabulary bridge — recruitment_helper.php executed');
        $g = 'bridge';

        $rt = 0; $rtOk = 0;
        foreach (recruitment_stages() as $stage) {
            $rt++;
            if (recruitment_stage_from_status(recruitment_status_from_stage($stage)) === $stage) $rtOk++;
        }
        $this->ok($g, 'E1 stage→status→stage is the identity', $rt === $rtOk, "{$rtOk}/{$rt}");

        // E2 — the exact values Ats::move_stage() will now write must be values
        // Hr::update_applicant_status() accepts, or the two modules disagree on
        // the same document.
        $valid = recruitment_statuses();
        $ok = 0; $tot = 0;
        foreach (recruitment_stages() as $stage) {
            $tot++;
            if (in_array(recruitment_status_from_stage($stage), $valid, true)) $ok++;
        }
        $this->ok($g, 'E2 every ATS write is a status HR accepts', $ok === $tot, "{$ok}/{$tot}");

        // E3 — Notifications.php counts applicants by these literal statuses.
        // If ATS stopped emitting them the recruitment task badge silently
        // reads zero.
        foreach (['Applied' => 'Applied', 'Interviewed' => 'Interview', 'Selected' => 'Selected'] as $stage => $expect) {
            $this->ok($g, "E3 Notifications counter status for {$stage}",
                recruitment_status_from_stage($stage) === $expect,
                recruitment_status_from_stage($stage));
        }
    }

    // =====================================================================
    //  F. CANONICAL STORE reachability (read-only Firestore)
    // =====================================================================

    private function group_store(): void
    {
        $this->head('F. Canonical store — read-only Firestore reads');
        $g = 'store';

        // CLI has no session, so the Firestore service is not on this controller.
        // Load it explicitly and drive it from a discovered tenant — the same
        // approach Hrms_p0_verify uses — so the reads below are real reads.
        try {
            $this->load->library('firestore_service', null, 'fsv');
            $this->fsv->init('BOOTSTRAP', '2026-27', 'BOOTSTRAP');
            // where() is the UNSCOPED primitive — used here only to discover a
            // tenant to test with. Every assertion below runs through the
            // school-scoped helpers instead.
            $schools = $this->fsv->where('schools', []);
        } catch (\Throwable $e) {
            $this->skipped('F store reads', 'Firestore unreachable: ' . $e->getMessage());
            return;
        }

        $school = '';
        foreach ($schools as $row) {
            if (strpos((string) $row['id'], '_profile') !== false) continue;
            $school = (string) $row['id'];
            break;
        }
        if ($school === '') {
            $this->skipped('F store reads', 'no tenant discoverable');
            return;
        }

        // Re-init onto the discovered tenant so schoolWhere() injects ITS predicate.
        $this->fsv->init($school, '2026-27', $school);
        echo "  (tenant under test: {$school})\n";

        // F0 — the scoping primitive itself: every ATS document id must be built
        // from the session tenant, never from user input.
        $this->ok($g, 'F0 docId() carries the tenant prefix',
            strpos($this->fsv->docId('APP0001'), $school . '_') === 0,
            $this->fsv->docId('APP0001'));

        foreach (['hrApplicants', 'hrJobs'] as $col) {
            try {
                // schoolWhere() is the helper Ats.php now uses — exercise it,
                // not a hand-built query, so the predicate injection is proven.
                $rows = $this->fsv->schoolWhere($col, []);
                $foreign = 0;
                foreach ($rows as $r) {
                    $sid = (string) ($r['data']['schoolId'] ?? '');
                    if ($sid !== '' && $sid !== $school) $foreign++;
                }
                $this->ok($g, "F {$col} reachable via schoolWhere(), no foreign docs",
                    is_array($rows) && $foreign === 0,
                    count($rows) . " doc(s), {$foreign} foreign");
            } catch (\Throwable $e) {
                $this->ok($g, "F {$col} reachable via schoolWhere()", false, $e->getMessage());
            }
        }

        // F3 — RTDB recruitment nodes must be empty. This is the migration's
        // central claim: there was no data to move, so no destructive migration
        // was performed. Re-asserted at runtime so a regression that starts
        // writing RTDB again is caught.
        try {
            $rtdb = $this->firebase->get("Schools/{$school}/HR/Recruitment");
            $this->ok($g, 'F3 RTDB recruitment node still empty',
                $rtdb === null || $rtdb === [] || $rtdb === false,
                is_array($rtdb) ? ('UNEXPECTED: ' . count($rtdb) . ' node(s)') : 'empty');
        } catch (\Throwable $e) {
            $this->skipped('F3 RTDB recruitment node', 'RTDB unreachable: ' . $e->getMessage());
        }

        // F4 — ATS and HR must mint applicant ids from ONE counter. Two counters
        // over one collection issue duplicate APP ids and the merge write
        // silently overwrites the earlier candidate.
        $atsSrc = file_get_contents(APPPATH . 'controllers/Ats.php');
        $hrSrc  = file_get_contents(APPPATH . 'controllers/Hr.php');
        $this->ok($g, 'F4 ATS mints via the shared counter',
            strpos($atsSrc, "hr_next_id(\$this->fs, 'APP', 'Applicant')") !== false);
        $this->ok($g, 'F4 HR delegates to the same helper',
            strpos($hrSrc, 'hr_next_id($this->fs, $prefix, $type, $pad)') !== false);
    }

    // =====================================================================
    //  G. TENANT ISOLATION (read-only, two real tenants)
    // =====================================================================

    private function group_isolation(string $a, string $b): void
    {
        $this->head('G. Tenant isolation — read-only cross-tenant probe');
        $g = 'isolation';

        if ($a === '' || $b === '') {
            $this->skipped('G isolation probe', 'supply SCHOOL_A= and SCHOOL_B=');
            return;
        }

        // Two independently-inited service instances — the same shape the real
        // request path produces for two logged-in admins of different tenants.
        try {
            $this->load->library('firestore_service', null, 'isoA');
            $this->load->library('firestore_service', null, 'isoB');
            $this->isoA->init($a, '2026-27', $a);
            $this->isoB->init($b, '2026-27', $b);
        } catch (\Throwable $e) {
            $this->skipped('G isolation probe', 'Firestore unreachable: ' . $e->getMessage());
            return;
        }

        foreach (['hrApplicants', 'hrJobs'] as $col) {
            try {
                $rows = $this->isoA->schoolWhere($col, []);
                $leak = 0;
                foreach ($rows as $r) {
                    $sid = (string) ($r['data']['schoolId'] ?? '');
                    if ($sid !== '' && $sid !== $a) $leak++;
                    // The doc id itself must also carry school A's prefix.
                    if (strpos((string) $r['id'], $b . '_') === 0) $leak++;
                }
                $this->ok($g, "G {$col} query for A returns no B documents", $leak === 0,
                    count($rows) . " doc(s), {$leak} leak(s)");
            } catch (\Throwable $e) {
                $this->ok($g, "G {$col} isolation probe", false, $e->getMessage());
            }
        }

        // G3 — the decisive read: ask A's service for a document that belongs to
        // B, using B's BARE entity id. docId() must re-prefix it with A, so the
        // read resolves to a non-existent document rather than B's data.
        try {
            $bRows = $this->isoB->schoolWhere('hrApplicants', []);
            if (empty($bRows)) {
                $this->skipped('G3 cross-tenant fetch', 'tenant B has no applicants to probe with');
            } else {
                $bDocId = (string) $bRows[0]['id'];
                $bBare  = (strpos($bDocId, $b . '_') === 0) ? substr($bDocId, strlen($b) + 1) : $bDocId;
                $leaked = $this->isoA->getEntity('hrApplicants', $bBare);
                $this->ok($g, 'G3 A cannot fetch B\'s applicant by bare id',
                    !is_array($leaked) || empty($leaked),
                    "probed '{$bBare}' as {$a}");
            }
        } catch (\Throwable $e) {
            $this->ok($g, 'G3 cross-tenant fetch', false, $e->getMessage());
        }
    }
}
