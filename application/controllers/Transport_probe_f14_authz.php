<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Transport_probe_f14_authz — runtime authorization coverage for the F14
 * Phase 2A completion override (AZ-1 … AZ-10).
 *
 * WHAT THIS PROVES, precisely: the real shipped decision function
 * has_permission() — the exact function require_permission() delegates to — is
 * executed against four real principal shapes, and the override's required grade
 * ('Transport' at 'manage') is granted to exactly one of them.
 *
 * WHAT THIS DOES NOT PROVE: it is not a live HTTP request. CI3 builds no usable
 * session in CLI, so the session is substituted with a stub that returns the
 * same userdata keys a logged-in session carries. The authorization LOGIC under
 * test is production code, unmodified; only the session source is stubbed.
 * Endpoint-level HTTP coverage remains a documented gap.
 *
 * No permission is broadened anywhere to make a case pass.
 *
 *   php index.php transport_probe_f14_authz run
 */

/** Minimal stand-in for CI_Session — userdata() only, which is all RBAC reads. */
class F14_Authz_Session_Stub
{
    private array $d = [];
    public function __construct(array $d) { $this->d = $d; }
    public function userdata($k = null) { return $k === null ? $this->d : ($this->d[$k] ?? null); }
    public function set_userdata($k, $v = null): void { $this->d[$k] = $v; }
}

class Transport_probe_f14_authz extends CI_Controller
{
    private int $pass = 0;
    private int $fail = 0;
    private array $failures = [];

    public function __construct()
    {
        parent::__construct();
        if (!is_cli()) { show_404(); }
    }

    private function eq(string $id, string $w, $exp, $got): void
    {
        if ($exp === $got) { $this->pass++; printf("  PASS  %-6s %s\n", $id, $w); return; }
        $this->fail++;
        $d = 'expected ' . var_export($exp, true) . ', got ' . var_export($got, true);
        $this->failures[] = "{$id} — {$w}: {$d}";
        printf("  FAIL  %-6s %s\n          → %s\n", $id, $w, $d);
    }

    private function truthy(string $id, string $w, $v, string $d = ''): void
    {
        if ($v) { $this->pass++; printf("  PASS  %-6s %s\n", $id, $w); return; }
        $this->fail++;
        $this->failures[] = "{$id} — {$w}: " . ($d !== '' ? $d : 'falsy');
        printf("  FAIL  %-6s %s\n          → %s\n", $id, $w, $d !== '' ? $d : 'falsy');
    }

    /** Install a principal, then ask the REAL has_permission(). */
    private function can(array $session, string $module, string $level): bool
    {
        $CI =& get_instance();
        $CI->session = new F14_Authz_Session_Stub($session);
        return has_permission($module, $level);
    }

    public function run(): void
    {
        $this->load->helper('rbac');

        echo "==================================================\n";
        echo " F14 Phase 2A — override authorization (runtime)\n";
        echo "==================================================\n";
        echo "  NOTE: real has_permission(); session stubbed; not live HTTP.\n\n";

        // The four principals, in the session shape admin login actually writes.
        $manage = ['admin_role' => 'Transport Manager',
                   'rbac_permissions' => ['Transport'],
                   'rbac_levels' => ['Transport' => 'manage']];
        $edit   = ['admin_role' => 'Transport Clerk',
                   'rbac_permissions' => ['Transport'],
                   'rbac_levels' => ['Transport' => 'edit']];
        $none   = ['admin_role' => 'Librarian',
                   'rbac_permissions' => ['Library'],
                   'rbac_levels' => ['Library' => 'manage']];
        // A driver has no admin grant at all — they operate the Teacher app and
        // are authorized per-trip by OWNERSHIP, never by an RBAC module grant.
        $driver = ['admin_role' => 'Driver',
                   'rbac_permissions' => [],
                   'rbac_levels' => []];

        // ── AZ-1..AZ-4 — the override grade: Transport @ manage ──────────
        $this->eq('AZ-1', 'Transport manage user CAN override-complete',
            true,  $this->can($manage, 'Transport', 'manage'));
        $this->eq('AZ-2', 'Transport edit-only user CANNOT override-complete',
            false, $this->can($edit,   'Transport', 'manage'));
        $this->eq('AZ-3', 'user with no Transport grant CANNOT override-complete',
            false, $this->can($none,   'Transport', 'manage'));
        $this->eq('AZ-4', 'driver CANNOT override-complete',
            false, $this->can($driver, 'Transport', 'manage'));

        // ── AZ-5..AZ-6 — the edit-only user is not collaterally damaged: they
        // keep ORDINARY completion, which is what 'edit' is for.
        $this->eq('AZ-5', 'edit-only user RETAINS ordinary completion (edit)',
            true,  $this->can($edit,   'Transport', 'edit'));
        $this->eq('AZ-6', 'driver has no Transport grant even at view',
            false, $this->can($driver, 'Transport', 'view'));

        // ── AZ-7 — 'manage' is strictly above 'edit', so the override grade
        // cannot be satisfied by an edit grant through level inheritance.
        $this->eq('AZ-7', 'manage outranks edit (no upward inheritance)',
            true, rbac_level_rank('manage') > rbac_level_rank('edit'));

        // ── AZ-8 — no bypass role smuggles the override in. If 'Driver' or a
        // transport role were a bypass role, AZ-4 would be meaningless.
        $bypass = defined('RBAC_BYPASS_ROLES') ? (array) RBAC_BYPASS_ROLES : [];
        $this->eq('AZ-8', 'Driver is NOT an RBAC bypass role',
            false, in_array('Driver', $bypass, true));
        $this->eq('AZ-8b', 'Transport Manager is NOT an RBAC bypass role (grade really is checked)',
            false, in_array('Transport Manager', $bypass, true));

        // ── AZ-9 — a session with no cached permissions fails CLOSED.
        $this->eq('AZ-9', 'session with no rbac_permissions fails closed',
            false, $this->can(['admin_role' => 'Staff'], 'Transport', 'manage'));

        // ── AZ-10 — the endpoint really asks for the grade proven above. Ties
        // the runtime result to the code path it is meant to cover.
        $ctl = file_get_contents(APPPATH . 'controllers/Transport_trip.php');
        $this->eq('AZ-10', "override_complete() gates on Transport @ manage",
            1, preg_match("/function override_complete\(\)\s*:\s*void\s*\{\s*require_permission\(\s*'Transport',\s*'override_complete_trip',\s*'manage'\s*\)/s", $ctl));

        // ── AZ-12 — the AUTHORITATIVE catalogue shape ────────────────────
        //
        // Added 2026-08-13 after a UAT audit correction. AZ-1..AZ-4 above use
        // fixtures I invented; these use the shapes actually read from the live
        // tenant catalogue (schools.staffRoles[*].permissionLevels) via
        // staff_access/get_catalogue:
        //
        //     ROLE_ADMIN             permissions[Transport]  levels{Transport:manage}
        //     ROLE_TRANSPORT_MANAGER permissions[Transport]  levels{Transport:manage}
        //     ROLE_DRIVER            no Transport grant at all
        //
        // Session hydration copies staffRoles[].permissionLevels into the
        // session's rbac_levels, which is what has_permission() reads — so the
        // stub below supplies the catalogue values under that key. Neither
        // 'Admin' nor 'Transport Manager' is an RBAC bypass role, so the grade
        // is genuinely evaluated rather than short-circuited (see AZ-8b).
        $realAdmin  = ['admin_role' => 'Admin',
                       'rbac_permissions' => ['Operations', 'Transport'],
                       'rbac_levels' => ['Transport' => 'manage']];
        $realTM     = ['admin_role' => 'Transport Manager',
                       'rbac_permissions' => ['Operations', 'Transport'],
                       'rbac_levels' => ['Transport' => 'manage']];
        // Driver was granted Transport:edit in the role editor on 2026-08-13
        // (verified against staff_access/get_catalogue). Updated here to track
        // the live catalogue: the assertion is STRONGER than the previous
        // no-grant shape, because a driver who now genuinely holds Transport
        // must still be refused the override.
        $realDriver = ['admin_role' => 'Driver',
                       'rbac_permissions' => ['Transport'],
                       'rbac_levels' => ['Transport' => 'edit']];

        $this->eq('AZ-12a', 'LIVE shape: Admin @ Transport:manage CAN override',
            true,  $this->can($realAdmin,  'Transport', 'manage'));
        $this->eq('AZ-12b', 'LIVE shape: Transport Manager @ Transport:manage CAN override',
            true,  $this->can($realTM,     'Transport', 'manage'));
        $this->eq('AZ-12c', 'LIVE shape: Driver @ Transport:edit STILL CANNOT override',
            false, $this->can($realDriver, 'Transport', 'manage'));
        // Documents the capability the edit grant DOES confer, so the widening is
        // visible in the evidence rather than implicit.
        $this->eq('AZ-12d', 'LIVE shape: Driver @ Transport:edit can perform edit-level actions',
            true,  $this->can($realDriver, 'Transport', 'edit'));

        // ── AZ-13 — explicit edit is NOT manage ──────────────────────────
        //
        // Compensating resolver-level evidence for manual case M, which stays
        // BLOCKED: this tenant has no non-bypass login to test in a browser.
        // This is NOT a substitute for that browser test and must never be
        // recorded as M passing.
        //
        // The level is set EXPLICITLY to 'edit' so the `?? 'manage'` fallback in
        // rbac_granted_level() cannot be what produces the result — AZ-13c
        // asserts that directly.
        $editOnly = ['admin_role' => 'Transport Clerk',
                     'rbac_permissions' => ['Transport'],
                     'rbac_levels' => ['Transport' => 'edit']];

        $this->eq('AZ-13a', 'explicit Transport:edit IS allowed at edit (ordinary completion)',
            true,  $this->can($editOnly, 'Transport', 'edit'));
        $this->eq('AZ-13b', 'explicit Transport:edit is DENIED at manage (override refused)',
            false, $this->can($editOnly, 'Transport', 'manage'));
        // Prove manage was not inferred from a missing level: the resolver must
        // report exactly 'edit' for this shape.
        $this->eq('AZ-13c', "resolver returns 'edit', not an inferred 'manage'",
            'edit', rbac_granted_level('Transport', ['Transport'], ['Transport' => 'edit']));
        $this->eq('AZ-13d', 'edit rank is strictly below manage',
            true, rbac_level_rank('edit') < rbac_level_rank('manage'));

        // ── Batch 3 — DM gates ───────────────────────────────────────────
        //
        // Source-level assertions on the enforcement points, plus resolver-level
        // assertions on the grades. The invariants themselves are exercised
        // behaviourally by Transport_probe_f14_b3.
        echo "\n[Batch 3] driver-metadata partition + actor invariants\n";
        $svc = file_get_contents(APPPATH . 'libraries/Transport_service.php');
        $drv = file_get_contents(APPPATH . 'controllers/Transport_driver.php');
        $cmp = file_get_contents(APPPATH . 'controllers/Transport_compliance.php');
        // Define the class (no instantiation, no Firestore) so the partition
        // constants can be read as the single source of truth.
        require_once APPPATH . 'libraries/Transport_service.php';
        $S   = 'Transport_service';

        // DM-1..DM-4 — the four re-levelled endpoints now demand manage.
        foreach (['assign_temp' => 'DM-1', 'save_review' => 'DM-2',
                  'resolve_incident' => 'DM-3'] as $act => $gate) {
            $this->eq($gate, "{$act} requires Transport · manage", 1,
                preg_match("/require_permission\('Transport', *'{$act}', *'manage'\)/", $drv));
        }
        $this->eq('DM-4', 'compliance_attest requires Transport · manage', 1,
            preg_match("/require_permission\('Transport', *'compliance_attest', *'manage'\)/", $cmp));

        // DM-5 — an edit-only principal is denied all four.
        $editOnly2 = ['admin_role' => 'Transport Clerk',
                      'rbac_permissions' => ['Transport'],
                      'rbac_levels' => ['Transport' => 'edit']];
        $this->eq('DM-5', 'edit-only principal denied at the Batch 3 grade',
            false, $this->can($editOnly2, 'Transport', 'manage'));

        // DM-6 — save_driver_meta deliberately REMAINS at edit (mixed purpose).
        $this->eq('DM-6', 'save_driver_meta stays at edit (field-partitioned)', 1,
            preg_match("/require_permission\('Transport', *'save_driver_meta', *'edit'\)/", $drv));

        // DM-7 — the partition constants exist and are disjoint.
        $sens = $S::DRIVER_META_SENSITIVE_FIELDS;
        $ben  = $S::DRIVER_META_BENIGN_FIELDS;
        $this->eq('DM-7', 'sensitive/benign field lists are disjoint', [], array_intersect($sens, $ben));
        $this->eq('DM-7b', 'all four dispatch-gating fields are classified sensitive', [],
            array_diff(['licence_expires', 'medical_expires', 'badge_expires', 'operational_status'], $sens));
        $this->eq('DM-7c', 'badge_no is sensitive (blanking it disables the badge gate)',
            true, in_array('badge_no', $sens, true));

        // DM-8 — authorization is decided on SUBMITTED fields, not the array shape.
        $this->truthy('DM-8', 'endpoint gates on fields actually submitted (post() !== null)',
            strpos($drv, "\$this->input->post(\$f) !== null") !== false
            && strpos($drv, '$hasSensitive') !== false,
            'a sensitive field could be smuggled into a benign-looking request');

        // DM-9 — sensitive writes gated by the EXISTING resolver, not a role name.
        $this->truthy('DM-9', 'sensitive fields additionally require has_permission manage',
            strpos($drv, "has_permission('Transport', 'manage')") !== false
            && strpos($svc, "has_permission('Transport', 'manage')") !== false,
            'the manage requirement is missing at the controller or the service');

        // DM-10 — benign writes are own-record-only, at BOTH layers.
        $this->truthy('DM-10', 'benign writes restricted to the actor\'s own record',
            strpos($drv, 'only update your own driver details') !== false
            && strpos($svc, 'only update your own driver details') !== false);

        // DM-11 — the service fails CLOSED when manage cannot be proven.
        $this->truthy('DM-11', 'service fails closed without a provable manage grant',
            strpos($svc, "function_exists('has_permission') && has_permission('Transport', 'manage')") !== false);

        // DM-12 — unsubmitted fields are preserved (nested-map erase hazard).
        $this->truthy('DM-12', 'driver_meta merged over stored map (no field erasure)',
            strpos($svc, '$clean = $prev;') !== false);

        // DM-13 — unknown keys rejected rather than written blind.
        $this->truthy('DM-13', 'unrecognised driver_meta keys rejected',
            strpos($svc, 'Unrecognised driver_meta field(s)') !== false);

        // DM-14..DM-16 — actor invariants, service-layer so all callers bind.
        $this->truthy('DM-14', 'assign_temp forbids self-assignment',
            strpos($svc, 'cannot assign yourself as the temporary driver') !== false);
        $this->truthy('DM-15', 'save_review forbids self-review',
            strpos($svc, 'cannot record a performance review for yourself') !== false);
        $this->truthy('DM-16', 'resolve_incident forbids self-resolution',
            strpos($svc, 'cannot resolve an incident recorded against yourself') !== false);

        // DM-17 — reviewer identity is server-derived; POST cannot forge it.
        $this->truthy('DM-17', 'reviewer_staff_id is server-derived, POST ignored',
            strpos($svc, "'reviewer_staff_id'     => (string) \$this->adminId,") !== false
            && strpos($svc, "\$input['reviewer_staff_id']") === false,
            'a client-supplied reviewer_staff_id is still honoured — forgeable provenance');

        // DM-18 — the one-way resolution guard survives.
        $this->truthy('DM-18', 'incident one-way resolution guard preserved',
            strpos($svc, 'Incident is already resolved.') !== false);

        // DM-19 — no legacy mechanism anywhere in the touched controllers.
        $this->eq('DM-19', 'no legacy _require_role() call reintroduced', 0,
            preg_match_all('/_require_role\(/', $drv . $cmp));

        echo "\n==================================================\n";
        echo " RESULT: pass={$this->pass} fail={$this->fail}\n";
        if ($this->failures !== []) {
            echo "\n DEFECTS:\n";
            foreach ($this->failures as $f) { echo "   • {$f}\n"; }
        }
        echo "==================================================\n";
        if ($this->fail > 0) { exit(1); }
    }
}
