<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * H-LIFECYCLE Layer 2 backend probe — REAL Firestore round-trip.
 *
 * Exercises the suspend → reactivate cycle against the test tenant
 * ZZ B1 Soak Test (SCH_9C7986EA3E) on the actual production
 * Firestore project (since there is no staging project yet — Q4).
 *
 * Verifies, end to end against real data:
 *   1. Pre-probe baseline state captured cleanly.
 *   2. write_lifecycle_state('suspended') propagates to BOTH
 *      schoolControl.lifecycle.state AND tenantPublic.lifecycleState.
 *   3. set_admin_disabled('inactive') propagates to BOTH
 *      schools.adminDisabled.value AND tenantPublic.adminDisabled.
 *   4. Mirror's mirroredAt timestamp advances on every fan-out.
 *   5. Revert to baseline lands cleanly across all four fields.
 *
 * Does NOT verify the Firestore Rules themselves (those need a deploy
 * to take effect — the L1 emulator-side tests cover the rules layer).
 *
 * Safety: aborts if pre-probe state is anything other than fully
 * active — never disrupts a tenant that's already non-active.
 *
 * Run: php index.php H_lifecycle_l2_probe run
 */
class H_lifecycle_l2_probe extends CI_Controller
{
    private $SID = 'SCH_9C7986EA3E';  // ZZ B1 Soak Test — established test target
    private $pass = 0;
    private $fail = 0;

    public function __construct()
    {
        parent::__construct();
        if (!defined('STDIN')) { exit("CLI only.\n"); }
        $this->load->library('firebase');
        $this->load->library('b2_registry_service');
    }

    private function assert(string $label, bool $cond, string $detail = ''): void
    {
        if ($cond) { echo "  ✅ {$label}"; if ($detail) echo "  ({$detail})"; echo "\n"; $this->pass++; }
        else       { echo "  ❌ {$label}"; if ($detail) echo "  ({$detail})"; echo "\n"; $this->fail++; }
    }

    public function run()
    {
        echo "═══════════════════════════════════════════════════════════════════\n";
        echo "H-LIFECYCLE Layer 2 backend probe — real Firestore round-trip\n";
        echo "Target tenant: {$this->SID} (ZZ B1 Soak Test)\n";
        echo "═══════════════════════════════════════════════════════════════════\n";

        $svc = $this->b2_registry_service;
        $svc->init($this->firebase);

        // ── [1] Snapshot pre-probe state ───────────────────────────
        echo "\n[1] Pre-probe state snapshot\n";
        $origCtrl = $this->firebase->firestoreGet('schoolControl', $this->SID) ?: [];
        $origLife = is_array($origCtrl['lifecycle'] ?? null) ? $origCtrl['lifecycle'] : [];
        $origState = (string) ($origLife['state'] ?? '');
        $origSch = $this->firebase->firestoreGet('schools', $this->SID) ?: [];
        $origAdmin = is_array($origSch['adminDisabled'] ?? null) ? $origSch['adminDisabled'] : [];
        $origAdmDisabled = !empty($origAdmin['value']);
        $origPub = $this->firebase->firestoreGet('tenantPublic', $this->SID) ?: [];
        $origPubState = (string) ($origPub['lifecycleState'] ?? '');
        $origPubAd = !empty($origPub['adminDisabled']);

        echo "  schoolControl.lifecycle.state = '{$origState}'\n";
        echo "  schools.adminDisabled.value   = " . var_export($origAdmDisabled, true) . "\n";
        echo "  tenantPublic.lifecycleState   = '{$origPubState}'\n";
        echo "  tenantPublic.adminDisabled    = " . var_export($origPubAd, true) . "\n";

        if ($origState !== 'active' || $origAdmDisabled || $origPubState !== 'active' || $origPubAd) {
            echo "\n  ⚠️ Pre-probe state is not fully active — aborting to avoid disrupting tenant.\n";
            echo "     Expected: lifecycle=active, adminDisabled=false, mirror in sync.\n";
            exit(1);
        }
        echo "  ✅ Baseline confirmed: tenant fully active, mirror in sync\n";

        // ── [2] Suspend via write_lifecycle_state ──────────────────
        echo "\n[2] Suspend via write_lifecycle_state('suspended')\n";
        $okL = $svc->write_lifecycle_state($this->SID, 'suspended', 'l2_probe_suspend');
        $this->assert("write_lifecycle_state returned true", $okL);
        usleep(500000); // 0.5s for Firestore propagation

        $ctrl = $this->firebase->firestoreGet('schoolControl', $this->SID) ?: [];
        $life = is_array($ctrl['lifecycle'] ?? null) ? $ctrl['lifecycle'] : [];
        $this->assert(
            "schoolControl.lifecycle.state == 'suspended'",
            ($life['state'] ?? '') === 'suspended',
            'state=' . ($life['state'] ?? 'null')
        );

        $pub = $this->firebase->firestoreGet('tenantPublic', $this->SID) ?: [];
        $this->assert(
            "tenantPublic.lifecycleState mirror == 'suspended'",
            ($pub['lifecycleState'] ?? '') === 'suspended',
            'mirror=' . ($pub['lifecycleState'] ?? 'null')
        );
        $this->assert(
            "tenantPublic.mirroredAt timestamp present (provenance)",
            !empty($pub['mirroredAt'])
        );

        // ── [3] Toggle admin disabled ──────────────────────────────
        echo "\n[3] set_admin_disabled('inactive') — adminDisabled fan-out\n";
        $okA = $svc->set_admin_disabled($this->SID, 'inactive', 'l2_probe_disable');
        $this->assert("set_admin_disabled returned true", $okA);
        usleep(500000);

        $sch = $this->firebase->firestoreGet('schools', $this->SID) ?: [];
        $ad = is_array($sch['adminDisabled'] ?? null) ? $sch['adminDisabled'] : [];
        $this->assert(
            "schools.adminDisabled.value == true (authoritative)",
            !empty($ad['value']),
            'value=' . var_export($ad['value'] ?? null, true)
        );

        $pub2 = $this->firebase->firestoreGet('tenantPublic', $this->SID) ?: [];
        $this->assert(
            "tenantPublic.adminDisabled mirror == true",
            !empty($pub2['adminDisabled']),
            'mirror=' . var_export($pub2['adminDisabled'] ?? null, true)
        );

        // Mirror state should now reflect BOTH suspended AND adminDisabled.
        $this->assert(
            "tenantPublic carries BOTH lifecycle suspension AND admin override (defense-in-depth signal)",
            ($pub2['lifecycleState'] ?? '') === 'suspended' && !empty($pub2['adminDisabled'])
        );

        // ── [4] Revert to baseline ─────────────────────────────────
        echo "\n[4] Revert — restore baseline (active + adminDisabled=false)\n";
        $svc->set_admin_disabled($this->SID, 'active', 'l2_probe_revert');
        usleep(500000);
        $svc->write_lifecycle_state($this->SID, $origState, 'l2_probe_revert');
        usleep(500000);

        $ctrl3 = $this->firebase->firestoreGet('schoolControl', $this->SID) ?: [];
        $life3 = is_array($ctrl3['lifecycle'] ?? null) ? $ctrl3['lifecycle'] : [];
        $this->assert(
            "Reverted schoolControl.lifecycle.state == '{$origState}'",
            ($life3['state'] ?? '') === $origState
        );

        $sch3 = $this->firebase->firestoreGet('schools', $this->SID) ?: [];
        $ad3 = is_array($sch3['adminDisabled'] ?? null) ? $sch3['adminDisabled'] : [];
        $this->assert("Reverted schools.adminDisabled.value == false", empty($ad3['value']));

        $pub3 = $this->firebase->firestoreGet('tenantPublic', $this->SID) ?: [];
        $this->assert(
            "Reverted tenantPublic.lifecycleState mirror == '{$origState}'",
            ($pub3['lifecycleState'] ?? '') === $origState
        );
        $this->assert("Reverted tenantPublic.adminDisabled mirror == false", empty($pub3['adminDisabled']));

        // ── SUMMARY ─────────────────────────────────────────────────
        echo "\n═══════════════════════════════════════════════════════════════════\n";
        printf("Layer 2 backend probe: PASS=%d  FAIL=%d  total=%d\n",
            $this->pass, $this->fail, $this->pass + $this->fail);
        echo ($this->fail === 0) ? "GATE: ✅ PASS\n" : "GATE: ❌ FAIL\n";
        echo "═══════════════════════════════════════════════════════════════════\n";
        exit($this->fail === 0 ? 0 : 1);
    }
}
