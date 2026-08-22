<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Transport_probe_f14 — F14 QR Attendance gates.
 *
 * Proves the security and business rules that matter, against REAL Firestore
 * with real fixtures, then removes them. The single most important gate is B1:
 * a student holding a perfectly valid QR but NO transport entitlement must
 * produce nothing at all — no event, no count, no notification.
 *
 *   SCHOOL_ID=… SESSION_YEAR=… php index.php transport_probe_f14 run
 */
class Transport_probe_f14 extends CI_Controller
{
    public $fs = null;
    public $numbering = null;
    public $transport = null;
    public $qratt = null;

    private int $pass = 0;
    private int $fail = 0;
    private array $failures = [];
    private array $fixtures = [];
    private array $vehicleIds = [];
    private string $tag = '';

    public function __construct()
    {
        parent::__construct();
        if (!is_cli()) { show_404(); }
    }

    private function ok(string $id, string $w): void { $this->pass++; printf("  PASS  %-6s %s\n", $id, $w); }
    private function bad(string $id, string $w, string $d): void
    {
        $this->fail++; $this->failures[] = "{$id} — {$w}: {$d}";
        printf("  FAIL  %-6s %s\n          → %s\n", $id, $w, $d);
    }
    private function eq(string $id, string $w, $exp, $got): void
    {
        if ($exp === $got) { $this->ok($id, $w); return; }
        $this->bad($id, $w, 'expected ' . var_export($exp, true) . ', got ' . var_export($got, true));
    }
    private function truthy(string $id, string $w, $v, string $d = ''): void
    {
        if ($v) { $this->ok($id, $w); return; }
        $this->bad($id, $w, $d !== '' ? $d : 'falsy');
    }

    public function run(): void
    {
        $sid  = (string) (getenv('SCHOOL_ID')    ?: '');
        $sess = (string) (getenv('SESSION_YEAR') ?: '');
        if ($sid === '' || $sess === '') {
            fwrite(STDERR, "ERROR: SCHOOL_ID + SESSION_YEAR required.\n"); exit(1);
        }
        $this->tag = (string) (int) (microtime(true) * 1000);

        echo "==================================================\n";
        echo " F14 — QR Attendance gates   tenant={$sid}\n";
        echo "==================================================\n";

        $this->load->helper('qr_token');
        $this->load->library('firestore_service', null, 'fs');
        $this->fs->init($sid, $sess);
        $this->load->library('numbering_service', null, 'numbering');
        $this->numbering->init($this->fs, $sid, $sess);
        $this->load->library('transport_service', null, 'transport');
        $this->transport->init($this->fs, $this->numbering, $sid, $sess, 'PROBE_F14');
        $this->load->library('transport_gps_service', null, 'gps');
        $this->gps->init($this->fs, $sid, 'PROBE_F14');
        $this->load->library('transport_qr_attendance_service', null, 'qratt');
        $this->qratt->init($this->fs, $this->transport, $sid, $sess, 'PROBE_F14_DRIVER');

        try {
            $this->a_tokenSecurity($sid);
            $this->b_entitlementGate($sid, $sess);
            $this->c_stateMachine();
            $this->d_alternateBus($sid, $sess);
            $this->e_notifierWiring();
            $this->f_writerDiscipline();
            $this->g_rulesAndIndexes();
            $this->h_requestWorklist($sid, $sess);
            $this->i_schoolArrivalGuard($sid, $sess);
        } catch (\Throwable $e) {
            $this->bad('EXC', 'unhandled exception', $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
        } finally {
            echo "\n[TEARDOWN]\n";
            foreach ($this->vehicleIds as $vid) {
                try { $this->gps->closeSession($vid); } catch (\Throwable $_) {}
            }
            $removed = 0;
            foreach (array_reverse($this->fixtures) as [$col, $docId]) {
                if ($docId === '') { continue; }
                try { $this->fs->removeEntity($col, $docId); $removed++; } catch (\Throwable $_) {}
            }
            echo "  removed {$removed} fixture doc(s)\n";
        }

        echo "\n==================================================\n";
        echo " RESULT: pass={$this->pass} fail={$this->fail}\n";
        if ($this->failures !== []) {
            echo "\n DEFECTS:\n";
            foreach ($this->failures as $f) { echo "   • {$f}\n"; }
        }
        echo "==================================================\n";
        if ($this->fail > 0) { exit(1); }
    }

    // ─── §A  QR token security ───────────────────────────────────────────

    private function a_tokenSecurity(string $sid): void
    {
        echo "\n[A] QR token security\n";
        $studentId = 'PROBE_F14_STU_' . $this->tag;

        // A1 — a properly signed token round-trips.
        $signed = qr_token_encode($sid, $studentId);
        try {
            $out = $this->qratt->decodeStudentToken($signed);
            $this->eq('A1', 'a signed token resolves to its student', $studentId, $out['studentId']);
        } catch (\Throwable $e) {
            $this->bad('A1', 'a signed token resolves to its student', $e->getMessage());
        }

        // A2 — an UNSIGNED legacy token must be refused here even though the
        // shared helper still tolerates it for other migrating features.
        $legacy = rtrim(strtr(base64_encode($sid . '|' . $studentId), '+/', '-_'), '=');
        $this->truthy('A2', 'unsigned legacy token REJECTED on the F14 path',
            $this->_throws(fn() => $this->qratt->decodeStudentToken($legacy)),
            'an unsigned token was accepted — it is forgeable for any student');

        // A3 — a tampered signature must not pass.
        $forged = rtrim(strtr(base64_encode($sid . '|' . $studentId . '|' . str_repeat('a', 16)), '+/', '-_'), '=');
        $this->truthy('A3', 'forged signature REJECTED',
            $this->_throws(fn() => $this->qratt->decodeStudentToken($forged)));

        // A4 — a valid token minted for ANOTHER school must not cross the tenant.
        $other = qr_token_encode('SCH_PROBE_OTHER', $studentId);
        $this->truthy('A4', 'cross-tenant token REJECTED',
            $this->_throws(fn() => $this->qratt->decodeStudentToken($other)));

        // A5 — junk in, refusal out (never a silent pass).
        $this->truthy('A5', 'malformed QR REJECTED',
            $this->_throws(fn() => $this->qratt->decodeStudentToken('not-a-token!!')));
        $this->truthy('A6', 'empty QR REJECTED',
            $this->_throws(fn() => $this->qratt->decodeStudentToken('   ')));
    }

    private function _throws(callable $fn): bool
    {
        try { $fn(); return false; } catch (\Throwable $_) { return true; }
    }

    // ─── §B  the entitlement gate — the whole point of F14 ────────────────

    private function b_entitlementGate(string $sid, string $sess): void
    {
        echo "\n[B] Transport entitlement gate (studentRoutes is the authority)\n";
        $today = date('Y-m-d');
        $day   = date('l', strtotime($today));

        // B1 — no assignment at all ⇒ NOT eligible. This is the headline gate:
        // a valid QR must never be sufficient.
        $ghost = 'PROBE_F14_GHOST_' . $this->tag;
        $r = $this->qratt->resolveEligibility($ghost, $today, 'Pickup');
        $this->eq('B1', 'student with NO transport facility is NOT eligible', false, $r['eligible']);
        $this->eq('B1b', 'rejection reason is not_enrolled', 'not_enrolled', $r['reason']);

        $stu = 'PROBE_F14_STU_' . $this->tag;
        $mk = function (array $over) use ($sid, $sess, $stu, $day) {
            $id = 'PROBE_F14_SR_' . $this->tag . '_' . substr(md5(json_encode($over)), 0, 6);
            $this->fs->setEntity('studentRoutes', $id, array_merge([
                'id' => $id, 'schoolId' => $sid, 'session' => $sess, 'studentId' => $stu,
                'route_id' => 'PROBE_F14_RT', 'stop_id' => 'PROBE_F14_STP',
                'status' => 'Active', 'type' => 'both',
                'service_days' => [substr($day, 0, 3)],
                'created_at' => date('c'), 'updated_at' => date('c'),
            ], $over), false);
            $this->fixtures[] = ['studentRoutes', $id];
            return $id;
        };

        // B2 — an Active, in-window assignment ⇒ eligible.
        $active = $mk([]);
        $r = $this->qratt->resolveEligibility($stu, $today, 'Pickup');
        $this->eq('B2', 'Active assignment ⇒ eligible', true, $r['eligible']);

        // B3 — service_days stored ABBREVIATED must still match. date('l') gives
        // "Tuesday" while real data stores "Tue"; a strict compare silently
        // excluded every real student (the bug fixed on 2026-08-12).
        $this->eq('B3', 'abbreviated service_days still match the weekday', true, $r['eligible']);

        // B4 — status not Active ⇒ rejected.
        $this->fs->removeEntity('studentRoutes', $active);
        $mk(['status' => 'Cancelled']);
        $r = $this->qratt->resolveEligibility($stu, $today, 'Pickup');
        $this->eq('B4', 'Cancelled assignment ⇒ NOT eligible', false, $r['eligible']);

        // B5 — outside the effective window ⇒ rejected (deactivation, and
        // activation that has not started yet).
        $this->_purgeStudentRoutes();
        $mk(['effective_to' => date('Y-m-d', strtotime('-1 day'))]);
        $r = $this->qratt->resolveEligibility($stu, $today, 'Pickup');
        $this->eq('B5', 'expired effective_to ⇒ NOT eligible', false, $r['eligible']);

        $this->_purgeStudentRoutes();
        $mk(['effective_from' => date('Y-m-d', strtotime('+3 day'))]);
        $r = $this->qratt->resolveEligibility($stu, $today, 'Pickup');
        $this->eq('B6', 'future effective_from ⇒ NOT eligible (mid-session activation)', false, $r['eligible']);

        // B7 — suspension window blocks.
        $this->_purgeStudentRoutes();
        $mk(['suspended_from' => date('Y-m-d', strtotime('-1 day')),
             'suspended_until' => date('Y-m-d', strtotime('+1 day'))]);
        $r = $this->qratt->resolveEligibility($stu, $today, 'Pickup');
        $this->eq('B7', 'suspended assignment ⇒ NOT eligible', false, $r['eligible']);

        // B8 — direction cover: a pickup-only student on the afternoon leg.
        $this->_purgeStudentRoutes();
        $mk(['type' => 'pickup']);
        $r = $this->qratt->resolveEligibility($stu, $today, 'Drop');
        $this->eq('B8', 'pickup-only student NOT eligible on the Drop leg', false, $r['eligible']);
        $r = $this->qratt->resolveEligibility($stu, $today, 'Pickup');
        $this->eq('B9', 'pickup-only student IS eligible on the Pickup leg', true, $r['eligible']);

        // B10 — not a service day.
        $this->_purgeStudentRoutes();
        $mk(['service_days' => ['Xxx']]);
        $r = $this->qratt->resolveEligibility($stu, $today, 'Pickup');
        $this->eq('B10', 'non-service day ⇒ NOT eligible', false, $r['eligible']);

        // B11 — a different session's assignment must not confer eligibility.
        $this->_purgeStudentRoutes();
        $mk(['session' => '1999-00']);
        $r = $this->qratt->resolveEligibility($stu, $today, 'Pickup');
        $this->eq('B11', 'another session\'s assignment ⇒ NOT eligible', false, $r['eligible']);
        $this->_purgeStudentRoutes();
    }

    private function _purgeStudentRoutes(): void
    {
        foreach ($this->fixtures as $i => [$col, $docId]) {
            if ($col !== 'studentRoutes') { continue; }
            try { $this->fs->removeEntity($col, $docId); } catch (\Throwable $_) {}
            unset($this->fixtures[$i]);
        }
        $this->fixtures = array_values($this->fixtures);
    }

    // ─── §C  four-event state machine ────────────────────────────────────

    private function c_stateMachine(): void
    {
        echo "\n[C] Four-event state machine (morning and afternoon are separate)\n";
        $S = 'Transport_qr_attendance_service';

        // C1/C2 — the legal morning path.
        $t = $this->qratt->validateTransition('Pickup', '', $S::EV_BOARDED_AT_STOP);
        $this->eq('C1', 'morning: nothing → boarded_at_stop allowed', true, $t['ok']);
        $t = $this->qratt->validateTransition('Pickup', $S::EV_BOARDED_AT_STOP, $S::EV_ALIGHTED_AT_SCHOOL);
        $this->eq('C2', 'morning: boarded → alighted_at_school allowed', true, $t['ok']);

        // C3 — alighting before boarding must be refused.
        $t = $this->qratt->validateTransition('Pickup', '', $S::EV_ALIGHTED_AT_SCHOOL);
        $this->eq('C3', 'cannot alight before boarding', false, $t['ok']);

        // C4 — a repeat is idempotent, not an error and not a second row.
        $t = $this->qratt->validateTransition('Pickup', $S::EV_BOARDED_AT_STOP, $S::EV_BOARDED_AT_STOP);
        $this->truthy('C4', 'double scan is idempotent, not a duplicate', $t['idempotent'] && !$t['ok']);

        // C5/C6 — the legal afternoon path.
        $t = $this->qratt->validateTransition('Drop', '', $S::EV_BOARDED_AT_SCHOOL);
        $this->eq('C5', 'afternoon: nothing → boarded_at_school allowed', true, $t['ok']);
        $t = $this->qratt->validateTransition('Drop', $S::EV_BOARDED_AT_SCHOOL, $S::EV_ALIGHTED_AT_STOP);
        $this->eq('C6', 'afternoon: boarded → alighted_at_stop allowed', true, $t['ok']);

        // C7/C8 — the legs must never mix.
        $t = $this->qratt->validateTransition('Pickup', '', $S::EV_BOARDED_AT_SCHOOL);
        $this->eq('C7', 'afternoon event refused on a morning trip', false, $t['ok']);
        $t = $this->qratt->validateTransition('Drop', '', $S::EV_ALIGHTED_AT_STOP);
        $this->eq('C8', 'cannot alight at a stop before boarding at school', false, $t['ok']);

        // C9 — legacy rows stay readable, mapped not rewritten.
        $this->eq('C9', 'legacy boarded_pickup reads as boarded_at_stop',
            $S::EV_BOARDED_AT_STOP, $S::canonicalEvent('boarded_pickup'));
        $this->eq('C10', 'legacy dropped_home reads as alighted_at_stop',
            $S::EV_ALIGHTED_AT_STOP, $S::canonicalEvent('dropped_home'));
        $this->eq('C11', 'no_show is NOT remapped (it is a resolution, not a journey event)',
            'no_show', $S::canonicalEvent('no_show'));
    }

    // ─── §D  alternate bus ───────────────────────────────────────────────

    private function d_alternateBus(string $sid, string $sess): void
    {
        echo "\n[D] Alternate bus — assigned vs actual\n";
        $S = 'Transport_qr_attendance_service';

        // D1 — with no evidence anywhere, nothing is invented.
        $r = $this->qratt->findBoardingOnOtherTrip('PROBE_F14_NOBODY_' . $this->tag, date('Y-m-d'), 'PROBE_TRIP');
        $this->eq('D1', 'no evidence ⇒ no alternate-bus claim (stays unresolved)', false, $r['found']);

        // D2 — a driver may not simply assert alternate_bus.
        $this->truthy('D2', 'alternate_bus is NOT driver-selectable',
            !in_array($S::MODE_ALTERNATE_BUS, $S::DRIVER_RESOLUTIONS, true)
            && !in_array($S::RES_ALTERNATE_BUS, $S::DRIVER_RESOLUTIONS, true),
            'a driver could assert alternate_bus without evidence');

        // D3 — the writer must expose an off-roster escape hatch, since a child
        // on the wrong bus is a real situation, but it must be opt-in.
        $ref = new \ReflectionMethod('Transport_service', '_writeBoardingEvent');
        $params = array_map(static fn($p) => $p->getName(), $ref->getParameters());
        $this->truthy('D3', 'canonical writer accepts an $opts array (alternate-bus + attribution)',
            in_array('opts', $params, true));
        $this->truthy('D4', 'off-roster writes are opt-in, not the default',
            strpos(file_get_contents(APPPATH . 'libraries/Transport_service.php'),
                   "allow_off_roster") !== false);
    }

    // ─── §E  notifications ───────────────────────────────────────────────

    private function e_notifierWiring(): void
    {
        echo "\n[E] Parent notifications — all four, on the existing engine\n";
        $cats = ['student.boarded_at_stop', 'student.alighted_at_school',
                 'student.boarded_at_school', 'student.alighted_at_stop'];
        // CI loads libraries lazily — the class does not exist until asked for,
        // and constant() on a missing class throws rather than returning null.
        $this->load->library('transport_notifier', null, 'tnotif');
        $N = 'Transport_notifier';
        foreach ($cats as $i => $c) {
            $n = $i + 1;
            $inCat = in_array($c, constant($N . '::CATEGORIES'), true);
            $inPri = array_key_exists($c, constant($N . '::PRIORITY_MATRIX'));
            $inAud = array_key_exists($c, constant($N . '::AUDIENCE_MATRIX'));
            $this->truthy("E{$n}", "{$c} registered (categories+priority+audience)",
                $inCat && $inPri && $inAud,
                "categories=" . var_export($inCat, true) . " priority=" . var_export($inPri, true)
                . " audience=" . var_export($inAud, true));
            if ($inAud) {
                $this->eq("E{$n}b", "{$c} reaches only that student's parents",
                    constant($N . '::SCOPE_PARENTS_OF_STUDENTS'),
                    constant($N . '::AUDIENCE_MATRIX')[$c]);
            }
        }
        // E9 — a message template must exist, or parents get an empty push.
        $src = file_get_contents(APPPATH . 'libraries/Transport_notifier.php');
        $missing = [];
        foreach ($cats as $c) {
            if (strpos($src, "case '{$c}':") === false) { $missing[] = $c; }
        }
        $this->truthy('E9', 'every new category has a parent-facing message template',
            $missing === [], 'missing templates: ' . implode(', ', $missing));

        // E10 — no second dispatcher. F14 must not add a MARK_REGISTRY row.
        $svc = file_get_contents(APPPATH . 'libraries/Transport_qr_attendance_service.php');
        $this->truthy('E10', 'F14 uses Transport_notifier only (no second push sender)',
            strpos($svc, 'transport_notifier') !== false
            && strpos($svc, 'MARK_REGISTRY') === false
            && strpos($svc, 'Push_service') === false);
    }

    // ─── §F  writer discipline ───────────────────────────────────────────

    private function f_writerDiscipline(): void
    {
        echo "\n[F] One writer, one GPS system, no RTDB\n";
        $svc = file_get_contents(APPPATH . 'libraries/Transport_qr_attendance_service.php');

        // F1 — the decision layer must never write attendance itself.
        $this->truthy('F1', 'F14 service never writes transportAttendance directly',
            strpos($svc, "setEntity('transportAttendance'") === false
            && strpos($svc, 'COL_TRANSPORT_ATTENDANCE,') === false,
            'the decision layer writes storage directly — that is a second writer');

        // F2 — no second GPS system; F13.1 stays canonical and read-only.
        $this->truthy('F2', 'F14 creates no GPS collection of its own',
            strpos($svc, "setEntity('vehicleLivePositions'") === false
            && strpos($svc, "setEntity('vehicleBreadcrumbs'") === false);
        $this->truthy('F3', 'F14 consumes F13.1 live positions read-only',
            strpos($svc, "getEntity('vehicleLivePositions'") !== false);

        // F4 — ZERO RTDB.
        $this->truthy('F4', 'no RTDB anywhere in F14',
            stripos($svc, 'firebase_db') === false
            && stripos($svc, 'getReference') === false
            && stripos($svc, 'realtime') === false);

        // F5 — the campus fence is the shared one, not a new store.
        $this->truthy('F5', 'school geofence reuses attendancePolicy.gps (same as Staff Attendance)',
            strpos($svc, 'attendancePolicy') !== false && strpos($svc, 'geofences') !== false);

        // F6 — arrival must be gated, never trusted from the client.
        $this->truthy('F6', 'school arrival is geofence-gated',
            strpos($svc, 'verifyBusAtSchool') !== false
            && strpos($svc, 'outside_geofence') !== false);

        // F7 — bulk alighting is morning-only. The afternoon drop is individual
        // by nature: being let off at the right stop is the entire point.
        $this->truthy('F7', 'bulk alighting refuses the afternoon leg',
            strpos($svc, "wrong_leg") !== false);

        // F8 — the four canonical events exist on the canonical writer.
        foreach (['markBoardedAtStop', 'markAlightedAtSchool', 'markBoardedAtSchool', 'markAlightedAtStop'] as $m) {
            if (!method_exists('Transport_service', $m)) {
                $this->bad('F8', 'canonical writer exposes all four events', "missing {$m}");
                return;
            }
        }
        $this->ok('F8', 'canonical writer exposes all four events');
    }

    // ─── §G  rules + indexes ─────────────────────────────────────────────

    private function g_rulesAndIndexes(): void
    {
        echo "\n[G] Firestore rules + indexes\n";
        $rulesPath = FCPATH . '../firebase-rules/firestore.rules';
        if (!is_file($rulesPath)) { $rulesPath = FCPATH . 'firebase-rules/firestore.rules'; }
        $rules = is_file($rulesPath) ? file_get_contents($rulesPath) : '';
        if ($rules === '') { $this->bad('G0', 'firestore.rules readable', 'not found'); return; }

        // Isolate the transportAttendance block so a match elsewhere cannot
        // make these gates pass by accident. Bounded by the NEXT match block,
        // not by a byte budget — a fixed window silently truncated the block
        // once the comments grew and turned a passing gate into a false FAIL.
        $start = strpos($rules, 'match /transportAttendance/');
        if ($start === false) { $this->bad('G0', 'transportAttendance rule present', 'block not found'); return; }
        $rest = substr($rules, $start + 10);
        $next = strpos($rest, 'match /');
        $block = $next === false ? substr($rules, $start) : substr($rules, $start, $next + 10);

        $this->truthy('G1', 'parent may read their own child\'s attendance',
            strpos($block, "student_ids") !== false && strpos($block, 'studentId') !== false);
        $this->truthy('G2', 'attendance stays immutable (no update, no delete)',
            preg_match('/allow\s+update,\s*delete:\s*if\s+false/', $block) === 1);
        // The driver client-write arm must be gone — that path could not enforce
        // the entitlement gate.
        $this->truthy('G3', 'driver client-write arm withdrawn (server-only writes)',
            strpos($block, 'driver_staff_id == request.auth.uid') === false,
            'a driver can still write attendance straight to Firestore, bypassing the entitlement gate');

        $idxPath = dirname($rulesPath) . '/firestore.indexes.json';
        $idx = is_file($idxPath) ? json_decode((string) file_get_contents($idxPath), true) : null;
        if (!is_array($idx)) { $this->bad('G4', 'indexes JSON parses', 'unreadable'); return; }
        $want = ['schoolId', 'tripId', 'studentId'];
        $found = false;
        foreach ((array) ($idx['indexes'] ?? []) as $i) {
            if ((string) ($i['collectionGroup'] ?? '') !== 'transportAttendance') { continue; }
            $f = array_map(static fn($x) => (string) ($x['fieldPath'] ?? ''), (array) ($i['fields'] ?? []));
            if ($f === $want) { $found = true; break; }
        }
        $this->truthy('G4', 'composite index for per-student trip state exists', $found,
            'the scan state machine query would fail at runtime without it');

        // Phase 1g — request worklist must be staff-read, server-write only.
        $rs = strpos($rules, 'match /transportRequests/');
        $rblock = $rs === false ? '' : substr($rules, $rs, 600);
        $this->truthy('G5', 'transportRequests exists and is staff-read only',
            $rs !== false && strpos($rblock, 'isStaff()') !== false);
        $this->truthy('G6', 'transportRequests is server-write only (no client may approve)',
            $rs !== false && preg_match('/allow\s+write:\s*if\s+false/', $rblock) === 1,
            'a client could mint or approve a request — approval creates an assignment AND a fee');

        $want2 = ['schoolId', 'session', 'status'];
        $found2 = false;
        foreach ((array) ($idx['indexes'] ?? []) as $i) {
            if ((string) ($i['collectionGroup'] ?? '') !== 'transportRequests') { continue; }
            $f = array_map(static fn($x) => (string) ($x['fieldPath'] ?? ''), (array) ($i['fields'] ?? []));
            if ($f === $want2) { $found2 = true; break; }
        }
        $this->truthy('G7', 'worklist index (schoolId+session+status) exists', $found2);
    }

    // ─── §H  Phase 1g — request worklist (hybrid F-3) ────────────────────

    private function h_requestWorklist(string $sid, string $sess): void
    {
        echo "\n[H] Transport request worklist — hybrid F-3\n";
        $stu = 'PROBE_F14_REQ_' . $this->tag;
        $reqId = "{$stu}_{$sess}";   // unprefixed entity id; getEntity adds {schoolId}_
        $this->fixtures[] = ['transportRequests', $reqId];

        // H1 — an INCOMPLETE request (no route/stop) must NOT create an
        // assignment and must NOT create a fee. It becomes a worklist row.
        $r = $this->transport->requestTransport([
            'studentId' => $stu, 'student_name' => 'Probe Req', 'requested_area' => 'Maninagar',
        ], 'admission');
        $this->eq('H1', 'incomplete request ⇒ pending worklist row (no assignment)', 'pending', $r['outcome']);
        $this->eq('H1b', 'no assignment id handed back', '', $r['assignmentId']);

        // H2 — and critically, nothing appeared in the eligibility authority.
        $el = $this->qratt->resolveEligibility($stu, date('Y-m-d'), 'Pickup');
        $this->eq('H2', 'a pending request confers NO transport eligibility', false, $el['eligible']);

        // H3 — no studentRoutes row was created at all.
        $rows = (array) $this->fs->schoolWhere('studentRoutes', [['studentId', '==', $stu]], null, 'ASC', 5);
        $this->eq('H3', 'no studentRoutes assignment created from an incomplete request', 0, count($rows));

        // H4 — idempotent: a re-submitted form does not create a second row.
        $r2 = $this->transport->requestTransport(['studentId' => $stu], 'admission');
        $this->eq('H4', 'duplicate request is idempotent', 'already_pending', $r2['outcome']);

        // H5 — the worklist surfaces it.
        $list = $this->transport->listTransportRequests('pending');
        $ids = array_map(static fn($x) => (string) ($x['studentId'] ?? ''), $list);
        $this->truthy('H5', 'pending request appears in the admin worklist', in_array($stu, $ids, true));

        // H6 — approving WITHOUT a route/stop must be refused, or the worklist
        // would just recreate the incomplete assignment it exists to prevent.
        $this->truthy('H6', 'approval without route+stop REJECTED',
            $this->_throws(fn() => $this->transport->approveTransportRequest($reqId, [])));

        // H7 — a rejected request still confers nothing.
        $this->transport->rejectTransportRequest($reqId, 'probe: no route serves this area');
        $el = $this->qratt->resolveEligibility($stu, date('Y-m-d'), 'Pickup');
        $this->eq('H7', 'a rejected request confers NO eligibility', false, $el['eligible']);

        // H8 — rejecting twice is refused (terminal state).
        $this->truthy('H8', 'rejecting a non-pending request REJECTED',
            $this->_throws(fn() => $this->transport->rejectTransportRequest($reqId, 'again')));

        // H9 — a re-request after rejection is allowed and PRESERVES the earlier
        // outcome in history rather than erasing what the school decided.
        $r3 = $this->transport->requestTransport(['studentId' => $stu], 'mid_session');
        $this->eq('H9', 're-request after rejection is allowed', 'pending', $r3['outcome']);
        $doc = $this->fs->getEntity('transportRequests', $reqId);   // getEntity, not raw get — the id is unprefixed
        $doc = is_array($doc) ? ($doc['data'] ?? $doc) : [];
        $this->truthy('H9b', 'prior rejection preserved in history',
            is_array($doc['history'] ?? null) && count($doc['history']) >= 1);
        $this->eq('H9c', 'source records mid-session activation', 'mid_session', (string) ($doc['source'] ?? ''));

        // H10 — tenant isolation: a request from another school is invisible and
        // cannot be approved through this tenant's service.
        $foreign = 'SCH_PROBE_FOREIGN_' . $this->tag . '_X_' . $sess;
        $this->fs->set('transportRequests', $foreign, [
            'id' => $foreign, 'schoolId' => 'SCH_PROBE_FOREIGN', 'session' => $sess,
            'studentId' => 'FOREIGN_STU', 'status' => 'pending', 'created_at' => date('c'),
        ], false);
        $this->fixtures[] = ['transportRequests', $foreign];
        $this->truthy('H10', 'cross-tenant request cannot be approved',
            $this->_throws(fn() => $this->transport->approveTransportRequest($foreign,
                ['route_id' => 'X', 'stop_id' => 'Y'])),
            'a request belonging to another school was approvable');

        // H11 — studentEntitlements must stay untouched: studentRoutes is the
        // only eligibility authority, and the parked entitlement programme must
        // not be activated by this work.
        $svc = file_get_contents(APPPATH . 'libraries/Transport_service.php');
        $this->truthy('H11', 'Phase 1g never touches studentEntitlements',
            strpos($svc, 'studentEntitlements') === false);

        // H12 — conversion goes through the CANONICAL assignment path, not a
        // reimplementation, so fee creation/validation/audit are inherited.
        $ref = new \ReflectionMethod('Transport_service', 'approveTransportRequest');
        $src = implode('', array_slice(file(APPPATH . 'libraries/Transport_service.php'),
            $ref->getStartLine() - 1, $ref->getEndLine() - $ref->getStartLine() + 1));
        $this->_h12($stu);
    }

    private function _h12(string $stu): void
    {
        $ref = new \ReflectionMethod('Transport_service', 'approveTransportRequest');
        $src = implode('', array_slice(file(APPPATH . 'libraries/Transport_service.php'),
            $ref->getStartLine() - 1, $ref->getEndLine() - $ref->getStartLine() + 1));
        $this->truthy('H12', 'approval delegates to assignStudent() (one assignment system)',
            strpos($src, '$this->assignStudent(') !== false
            && strpos($src, "setEntity(self::COL_STUDENT_ROUTES_LEGACY") === false,
            'approval writes studentRoutes directly — that is a second assignment path');
    }

    // ─── §I  Part A — school-arrival completion guard ─────────────────────

    /**
     * The non-negotiable rule: a Pickup trip must not complete just because the
     * driver pressed a button. Audited 2026-08-12 and found UNENFORCED —
     * completeTrip() checked only status + Pending checkpoints. These gates
     * prove each refusal case, and that the afternoon leg is NOT caught by it.
     */
    private function i_schoolArrivalGuard(string $sid, string $sess): void
    {
        echo "\n[I] Part A — Pickup completion requires proven school arrival\n";

        // I1 — the guard must live in the SERVICE, not only in the API, or an
        // admin-panel completion would bypass it entirely.
        $svc = file_get_contents(APPPATH . 'libraries/Transport_service.php');
        $this->truthy('I1', 'guard enforced inside completeTrip() (all callers, incl. admin)',
            strpos($svc, '_assertSchoolArrivalBeforeComplete') !== false
            && preg_match('/completeTrip\(string \$tripId[^{]*\{(?:(?!function).)*?_assertSchoolArrivalBeforeComplete/s', $svc) === 1,
            'the guard is not invoked from completeTrip — an admin call would bypass it');

        // I2 — it must run BEFORE the CAS mutator (it performs reads).
        // Needle updated 2026-08-13: Phase 2A gave the guard a second argument
        // ($ovActive). The assertion is unchanged — the guard must still precede
        // the CAS — only the call signature it matches on moved.
        $pos_guard = strpos($svc, '_assertSchoolArrivalBeforeComplete($tripId, $ovActive);');
        $pos_cas   = strpos($svc, 'casUpdateEntity', (int) $pos_guard);
        $this->truthy('I2', 'guard runs before the CAS transaction', $pos_guard !== false && $pos_cas !== false && $pos_guard < $pos_cas);

        // I3 — Drop trips must NOT be subjected to the school-arrival rule.
        $guardSrc = '';
        try {
            $ref = new \ReflectionMethod('Transport_service', '_assertSchoolArrivalBeforeComplete');
            $guardSrc = implode('', array_slice(file(APPPATH . 'libraries/Transport_service.php'),
                $ref->getStartLine() - 1, $ref->getEndLine() - $ref->getStartLine() + 1));
        } catch (\Throwable $e) {
            $this->bad('I3', 'guard method exists', $e->getMessage());
        }
        $this->truthy('I3', 'guard applies to Pickup only (afternoon untouched)',
            strpos($guardSrc, "!== 'Pickup'") !== false && strpos($guardSrc, 'return;') !== false);

        // I4 — fails CLOSED: an unexpected error must keep the trip open.
        $this->truthy('I4', 'guard fails closed on error (trip stays open)',
            strpos($guardSrc, 'cannot be completed yet') !== false);

        // I5..I9 — each individual refusal reason must be reachable. Driven
        // through verifyBusAtSchool() against real tenant config rather than
        // asserted from source.
        $vehNo = 'PROBE_F14_VEH_' . $this->tag;
        $geo = $this->qratt->verifyBusAtSchool($vehNo);
        $this->eq('I5', 'no live position ⇒ arrival refused', false, $geo['ok']);
        $this->truthy('I5b', 'refusal names a concrete reason',
            in_array($geo['reason'], ['no_geofence', 'no_position', 'no_fix', 'stale_fix',
                                      'poor_accuracy', 'outside_geofence', 'bad_fix'], true),
            'reason was: ' . (string) $geo['reason']);

        // I6 — every refusal reason the requirement lists must be implemented.
        $qsrc = file_get_contents(APPPATH . 'libraries/Transport_qr_attendance_service.php');
        foreach (['no_geofence' => 'I6', 'no_position' => 'I7', 'no_fix' => 'I7b',
                  'stale_fix' => 'I8', 'poor_accuracy' => 'I8b', 'outside_geofence' => 'I9'] as $reason => $gate) {
            $this->truthy($gate, "refusal case implemented: {$reason}",
                strpos($qsrc, "'{$reason}'") !== false);
        }

        // I10 — the blocker set must include the unresolved-student case, so a
        // child left unaccounted for cannot be completed away.
        $this->truthy('I10', 'unresolved students block Pickup completion',
            strpos($qsrc, 'unresolved_students') !== false
            && strpos($qsrc, 'still_on_board') !== false);

        // I11 — and the geofence blocker is wired into completionBlockers, not
        // only into the bulk-alight endpoint.
        $ref2 = new \ReflectionMethod('Transport_qr_attendance_service', 'completionBlockers');
        $cbSrc = implode('', array_slice(file(APPPATH . 'libraries/Transport_qr_attendance_service.php'),
            $ref2->getStartLine() - 1, $ref2->getEndLine() - $ref2->getStartLine() + 1));
        $this->truthy('I11', 'completionBlockers itself checks the school geofence',
            strpos($cbSrc, 'verifyBusAtSchool') !== false
            && strpos($cbSrc, 'school_not_reached') !== false,
            'the geofence was only checked by mark_all_alighted_at_school — completeTrip could still bypass it');

        // I12 — F13.1 GPS remains the only position source; the guard must not
        // read a client-supplied coordinate.
        $this->truthy('I12', 'guard sources position from F13.1 only',
            strpos($qsrc, "getEntity('vehicleLivePositions'") !== false
            && strpos($cbSrc, 'input->post') === false);
    }
}
