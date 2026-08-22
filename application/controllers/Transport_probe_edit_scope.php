<?php
defined('BASEPATH') or exit('No direct script access allowed');

/** Session stand-in so has_permission() can be exercised in CLI. Same technique
 *  already used by Transport_probe_f14_authz — userdata() is all RBAC reads. */
class Edit_Scope_Session_Stub
{
    private array $d = [];
    public function __construct(array $d) { $this->d = $d; }
    public function userdata($k = null) { return $k === null ? $this->d : ($this->d[$k] ?? null); }
    public function set_userdata($k, $v = null): void { $this->d[$k] = $v; }
}

/**
 * Transport_probe_edit_scope — runtime evidence for the two remaining gaps:
 *   §DM  save_driver_meta field partition + actor scope   (A–F)
 *   §RT  request_transport direct-assignment boundary     (A–D)
 *
 * Both invariants live in the SERVICE and read the real, unmodified Graded RBAC
 * resolver. This probe drives them with a stubbed session so a manage-level and
 * an edit-level principal can both be exercised from CLI. Graded RBAC itself is
 * untouched — only the session the resolver reads is substituted.
 *
 *   SCHOOL_ID=SCH_202512 SESSION_YEAR=2026-27 php index.php transport_probe_edit_scope run
 */
class Transport_probe_edit_scope extends CI_Controller
{
    private const ALLOWED_TENANT = 'SCH_202512';
    private const MARK = 'PROBE_EDITSCOPE';

    public $fs = null; public $numbering = null; public $transport = null;
    private int $pass = 0; private int $fail = 0; private array $failures = [];
    private array $fixtures = [];      // [collection, entityId]
    private string $tag = ''; private string $sid = ''; private string $sess = '';

    public function __construct() { parent::__construct(); if (!is_cli()) { show_404(); } }

    private function ok(string $i, string $w): void { $this->pass++; printf("  PASS  %-6s %s\n", $i, $w); }
    private function bad(string $i, string $w, string $d): void
    { $this->fail++; $this->failures[] = "{$i} — {$w}: {$d}"; printf("  FAIL  %-6s %s\n          → %s\n", $i, $w, $d); }
    private function eq(string $i, string $w, $e, $g): void
    { $e === $g ? $this->ok($i, $w) : $this->bad($i, $w, 'expected ' . var_export($e, true) . ', got ' . var_export($g, true)); }
    private function truthy(string $i, string $w, $v, string $d = ''): void
    { $v ? $this->ok($i, $w) : $this->bad($i, $w, $d !== '' ? $d : 'falsy'); }

    /** Assert the call is refused, and for the right reason. */
    private function refuses(string $i, string $w, string $needle, callable $fn): void
    {
        try { $fn(); $this->bad($i, $w, 'ALLOWED — no exception thrown'); }
        catch (\Throwable $e) {
            stripos($e->getMessage(), $needle) !== false
                ? $this->ok($i, $w)
                : $this->bad($i, $w, 'refused for the wrong reason: ' . $e->getMessage());
        }
    }

    /** Install a principal for the REAL has_permission() to read. */
    private function principal(string $level): void
    {
        $CI =& get_instance();
        $CI->session = new Edit_Scope_Session_Stub([
            'admin_role'       => $level === 'manage' ? 'Transport Manager' : 'Driver',
            'rbac_permissions' => ['Transport'],
            'rbac_levels'      => ['Transport' => $level],
        ]);
    }

    /** Re-init the service so $adminId is the given actor. */
    private function actAs(string $actorId): void
    {
        $this->transport->init($this->fs, $this->numbering, $this->sid, $this->sess, $actorId);
    }

    private function meta(string $staffId): array
    {
        $s = $this->fs->getEntity('staff', $staffId);
        if (!is_array($s)) { return []; }
        if (isset($s['data']) && is_array($s['data']) && !isset($s['driver_meta'])) { $s = $s['data']; }
        return (array) ($s['driver_meta'] ?? []);
    }

    public function run(): void
    {
        $this->sid  = (string) (getenv('SCHOOL_ID') ?: '');
        $this->sess = (string) (getenv('SESSION_YEAR') ?: '');
        if ($this->sid === '' || $this->sess === '') { fwrite(STDERR, "ERROR: SCHOOL_ID + SESSION_YEAR required.\n"); exit(1); }
        if ($this->sid !== self::ALLOWED_TENANT) {
            fwrite(STDERR, "REFUSED: writes fixtures; runs only on " . self::ALLOWED_TENANT . ".\n"); exit(1);
        }
        $this->tag = (string) (int) (microtime(true) * 1000);

        echo "==================================================\n";
        echo " Edit-scope runtime evidence  tenant={$this->sid}\n";
        echo "==================================================\n";

        $this->load->library('firestore_service', null, 'fs');   $this->fs->init($this->sid, $this->sess);
        $this->load->library('numbering_service', null, 'numbering'); $this->numbering->init($this->fs, $this->sid, $this->sess);
        $this->load->library('transport_service', null, 'transport');
        $this->load->helper('rbac');

        try { $this->dm(); $this->rt(); }
        catch (\Throwable $e) { $this->bad('EXC', 'unhandled', $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine()); }
        finally { $this->teardown(); }

        echo "\n==================================================\n";
        echo " RESULT: pass={$this->pass} fail={$this->fail}\n";
        if ($this->failures !== []) { echo "\n DEFECTS:\n"; foreach ($this->failures as $f) { echo "   • {$f}\n"; } }
        echo "==================================================\n";
        if ($this->fail > 0) { exit(1); }
    }

    // ── §DM save_driver_meta ────────────────────────────────────────────
    private function dm(): void
    {
        echo "\n[DM] save_driver_meta field partition + actor scope\n";
        $me    = self::MARK . '_STA_' . $this->tag;
        $other = self::MARK . '_OTH_' . $this->tag;
        foreach ([$me, $other] as $s) {
            $this->fs->setEntity('staff', $s, [
                'id' => $s, 'schoolId' => $this->sid, 'name' => 'Edit Scope ' . $s, 'status' => 'Active',
                'flags' => ['can_drive_transport' => true],
                'driver_meta' => ['licence_no' => 'BASE-LIC', 'licence_expires' => '2099-01-01',
                                  'years_experience' => 1, 'emergency_contact_name' => 'Base Contact'],
            ], false);
            $this->fixtures[] = ['staff', $s];
        }

        // A — Driver, own record, benign field
        $this->principal('edit'); $this->actAs($me);
        try {
            $this->transport->updateDriverMeta($me, ['emergency_contact_name' => 'DM-A Updated']);
            $this->eq('DM-A', 'Driver CAN update own benign field', 'DM-A Updated',
                (string) ($this->meta($me)['emergency_contact_name'] ?? ''));
        } catch (\Throwable $e) { $this->bad('DM-A', 'Driver CAN update own benign field', $e->getMessage()); }

        // E — partial update must not erase unsubmitted fields
        $m = $this->meta($me);
        $this->eq('DM-E', 'unsubmitted driver_meta fields PRESERVED', 'BASE-LIC', (string) ($m['licence_no'] ?? '(ERASED)'));
        $this->eq('DM-E2', 'unsubmitted licence_expires PRESERVED', '2099-01-01', (string) ($m['licence_expires'] ?? '(ERASED)'));

        // B — Driver, own record, sensitive field
        $this->refuses('DM-B', 'Driver CANNOT update own SENSITIVE field', 'manage',
            fn() => $this->transport->updateDriverMeta($me, ['licence_expires' => '2100-01-01']));
        $this->eq('DM-B2', 'licence_expires unchanged after refusal', '2099-01-01',
            (string) ($this->meta($me)['licence_expires'] ?? ''));

        // C — Driver, ANOTHER staff member's benign field
        $this->refuses('DM-C', "Driver CANNOT update another staff member's benign field", 'your own driver details',
            fn() => $this->transport->updateDriverMeta($other, ['emergency_contact_name' => 'DM-C Should Not Apply']));
        $this->eq('DM-C2', "other staff member's record unchanged", 'Base Contact',
            (string) ($this->meta($other)['emergency_contact_name'] ?? ''));

        // F — unknown field rejected
        $this->refuses('DM-F', 'unknown driver_meta field REJECTED', 'Unrecognised driver_meta field',
            fn() => $this->transport->updateDriverMeta($me, ['totally_made_up_field' => 'x']));

        // D — manage principal may write sensitive fields
        $this->principal('manage'); $this->actAs(self::MARK . '_MGR');
        try {
            $this->transport->updateDriverMeta($other, ['licence_expires' => '2098-06-30']);
            $this->eq('DM-D', 'manage CAN update sensitive field on another record', '2098-06-30',
                (string) ($this->meta($other)['licence_expires'] ?? ''));
        } catch (\Throwable $e) { $this->bad('DM-D', 'manage CAN update sensitive field', $e->getMessage()); }
    }

    // ── §RT request_transport ───────────────────────────────────────────
    private function rt(): void
    {
        echo "\n[RT] request_transport direct-assignment boundary\n";
        $stu   = self::MARK . '_STU_' . $this->tag;
        $stu2  = self::MARK . '_STU2_' . $this->tag;
        $route = 'PROBE_EDITSCOPE_RT_' . $this->tag;
        $stop  = 'PROBE_EDITSCOPE_STP_' . $this->tag;
        $now   = date('c');
        $this->fs->setEntity('routes', $route, ['id' => $route, 'schoolId' => $this->sid,
            'name' => 'EditScope Route', 'status' => 'Active', 'direction' => 'Both',
            'created_at' => $now, 'updated_at' => $now], false);
        $this->fixtures[] = ['routes', $route];
        $this->fs->setEntity('transportStops', $stop, ['stopId' => $stop, 'schoolId' => $this->sid,
            'route_id' => $route, 'name' => 'EditScope Stop', 'order' => 1, 'status' => 'Active',
            'created_at' => $now, 'updated_at' => $now], false);
        $this->fixtures[] = ['transportStops', $stop];

        $payload = fn(string $s) => ['studentId' => $s, 'route_id' => $route, 'stop_id' => $stop,
                                     'type' => 'both', 'effective_from' => date('Y-m-d')];

        // A — edit-level caller supplying route+stop must NOT assign directly
        $this->principal('edit'); $this->actAs(self::MARK . '_DRV');
        $res = null;
        try { $res = $this->transport->requestTransport($payload($stu), 'mid_session'); }
        catch (\Throwable $e) { $this->bad('RT-A', 'edit caller can submit a request', $e->getMessage()); }

        $outcome = is_array($res) ? (string) ($res['outcome'] ?? '') : '';
        $this->eq('RT-A', 'edit + route/stop ⇒ PENDING request, not an assignment', 'pending', $outcome);
        $reqId = is_array($res) ? (string) ($res['requestId'] ?? '') : '';
        if ($reqId !== '') { $this->fixtures[] = ['transportRequests', $reqId]; }
        $this->eq('RT-A2', 'no assignmentId returned to the edit caller', '',
            is_array($res) ? (string) ($res['assignmentId'] ?? '') : '(none)');

        // MODEL A (ratified 2026-08-15) — ABSENCE proof, not preservation.
        //
        // These gates previously asserted that route_id/stop_id were PRESERVED on
        // the pending request. That was a Model-B expectation. Under Model A a
        // request is DESCRIPTIVE: it never establishes a canonical route/stop —
        // management supplies those at approve_transport_request. So the correct
        // assertion is that the persisted request carries NEITHER key, and that
        // neither was smuggled into the free-text hint fields.
        //
        // This is a corrected expectation, not a weakened one: it now proves a
        // stronger property (absence) than the original.
        $reqDoc = $reqId !== '' ? $this->fs->getEntity('transportRequests', $reqId) : null;
        if (is_array($reqDoc) && isset($reqDoc['data']) && !isset($reqDoc['studentId'])) { $reqDoc = $reqDoc['data']; }
        $this->truthy('RT-A3', 'MODEL A: persisted request contains NO route_id',
            is_array($reqDoc) && !array_key_exists('route_id', $reqDoc),
            'route_id present: ' . (is_array($reqDoc) ? (string) ($reqDoc['route_id'] ?? '?') : 'no doc'));
        $this->truthy('RT-A4', 'MODEL A: persisted request contains NO stop_id',
            is_array($reqDoc) && !array_key_exists('stop_id', $reqDoc),
            'stop_id present: ' . (is_array($reqDoc) ? (string) ($reqDoc['stop_id'] ?? '?') : 'no doc'));
        // No conflation: canonical IDs must not appear inside the hint fields.
        $this->truthy('RT-A5', 'MODEL A: canonical IDs NOT mapped into the free-text hint fields',
            is_array($reqDoc)
              && strpos((string) ($reqDoc['requested_area'] ?? ''), $route) === false
              && strpos((string) ($reqDoc['requested_stop_hint'] ?? ''), $stop) === false);

        // B/C — canonical assignment ABSENCE proof
        $found = [];
        try {
            $rows = (array) $this->fs->schoolWhere('studentRoutes', [['studentId', '==', $stu]], null, 'ASC', 20);
            foreach ($rows as $r) { $d = (isset($r['data']) && is_array($r['data'])) ? $r['data'] : $r;
                $found[] = (string) ($d['id'] ?? '?'); }
        } catch (\Throwable $e) { $this->bad('RT-B', 'assignment absence verifiable', $e->getMessage()); $found = null; }
        if (is_array($found)) {
            $this->eq('RT-B', 'NO canonical studentRoutes assignment created for the probe student', 0, count($found));
            if ($found !== []) { echo '        leaked: ' . implode(', ', $found) . "\n"; }
        }

        // D — manage caller may take the direct-assignment path
        $this->principal('manage'); $this->actAs(self::MARK . '_MGR');
        $res2 = null;
        try { $res2 = $this->transport->requestTransport($payload($stu2), 'mid_session'); }
        catch (\Throwable $e) { $this->bad('RT-D', 'manage caller submits a request (no direct-assignment path exists)', $e->getMessage()); }
        $out2 = is_array($res2) ? (string) ($res2['outcome'] ?? '') : '';
        // Phase 4 (2026-08-15): the direct-assignment shortcut was REMOVED, so a
        // manage caller now lands in the same pending worklist as everyone else.
        // This tracks the authorised removal — it is not a weakened assertion.
        $this->truthy('RT-D', 'Phase 4: manage + route/stop ⇒ PENDING too, no shortcut remains', $out2 === 'pending',
            "outcome was '{$out2}'");
        $aid = is_array($res2) ? (string) ($res2['assignmentId'] ?? '') : '';
        if ($aid !== '') { $this->fixtures[] = ['studentRoutes', $aid]; }
        $this->truthy('RT-D2', 'Phase 4: NO assignmentId returned — direct branch removed', $aid === '');
        if (is_array($res2) && ($res2['requestId'] ?? '') !== '') { $this->fixtures[] = ['transportRequests', (string) $res2['requestId']]; }
    }

    /** Deterministic cleanup — probe-created records only. */
    private function teardown(): void
    {
        echo "\n[TEARDOWN]\n";
        $removed = 0; $failed = 0;
        foreach (array_reverse($this->fixtures) as [$col, $id]) {
            if ($id === '') { continue; }
            try { $this->fs->removeEntity($col, $id); $removed++; }
            catch (\Throwable $e) { $failed++; echo "  FAILED remove {$col}:{$id} — " . $e->getMessage() . "\n"; }
        }
        echo "  probe records created : " . count($this->fixtures) . "\n";
        echo "  removed               : {$removed}" . ($failed ? " (failed {$failed})" : '') . "\n";
        echo "  tenant config         : NOT modified\n";
    }
}
