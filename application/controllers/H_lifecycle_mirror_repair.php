<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * H-LIFECYCLE H1.5 — one-shot CLI backfill for tenantPublic mirror.
 *
 * Iterates EVERY tenant in the `schools` collection and writes
 * tenantPublic/{schoolId}.lifecycleState + .adminDisabled fields,
 * sourced from the canonical schoolControl/{schoolId}.lifecycle.state
 * and schools/{schoolId}.adminDisabled.value respectively.
 *
 * Idempotent — safe to re-run. Reports a per-tenant verdict and a
 * summary at the end. Used as the pre-deploy gate for H1 (the
 * Firestore Rules lifecycle gate); without the mirror, mobile apps
 * cannot detect lifecycle transitions in seconds.
 *
 * Run: php index.php H_lifecycle_mirror_repair run
 */
class H_lifecycle_mirror_repair extends CI_Controller
{
    public function __construct()
    {
        parent::__construct();
        if (!defined('STDIN')) { exit("CLI only.\n"); }
        $this->load->library('firebase');
    }

    public function run()
    {
        echo "═══════════════════════════════════════════════════════════════════\n";
        echo "H-LIFECYCLE H1.5 — tenantPublic mirror backfill\n";
        echo "═══════════════════════════════════════════════════════════════════\n";

        $schools = $this->firebase->firestoreQuery('schools', []);
        if (!is_array($schools)) { echo "schools query failed\n"; exit(2); }

        $pass = 0; $fail = 0; $skip = 0;
        foreach ($schools as $row) {
            $data = is_array($row['data'] ?? null) ? $row['data'] : (is_array($row) ? $row : []);
            $sid  = (string) ($row['id'] ?? $data['__firestoreId'] ?? '');
            if (!preg_match('/^SCH_[A-Z0-9]+$/', $sid)) { $skip++; continue; }

            $ctrl = $this->firebase->firestoreGet('schoolControl', $sid) ?: [];
            $life = is_array($ctrl['lifecycle'] ?? null) ? $ctrl['lifecycle'] : [];
            $state = (string) ($life['state'] ?? '');
            if ($state === '') {
                echo "  ⚠️  {$sid}  no lifecycle.state in schoolControl — SKIP\n";
                $skip++;
                continue;
            }

            $sch = $this->firebase->firestoreGet('schools', $sid) ?: [];
            $ad  = is_array($sch['adminDisabled'] ?? null) ? $sch['adminDisabled'] : [];
            $adminDisabled = !empty($ad['value']);

            $ok = $this->firebase->firestoreUpdate('tenantPublic', $sid, [
                'lifecycleState' => $state,
                'adminDisabled'  => $adminDisabled,
                'mirroredAt'     => date('c'),
            ]);

            // Verify the read-back
            $pub = $this->firebase->firestoreGet('tenantPublic', $sid) ?: [];
            $mirroredState = (string) ($pub['lifecycleState'] ?? '');
            $mirroredAd    = !empty($pub['adminDisabled']);
            $verified = ($mirroredState === $state) && ($mirroredAd === $adminDisabled);

            if ($ok && $verified) {
                printf("  ✅ %s  lifecycleState=%s  adminDisabled=%s  → mirrored OK\n",
                    $sid, $state, $adminDisabled ? 'true' : 'false');
                $pass++;
            } else {
                printf("  ❌ %s  write_ok=%s  state_match=%s  adminDisabled_match=%s\n",
                    $sid,
                    var_export($ok, true),
                    ($mirroredState === $state) ? 'yes' : 'no',
                    ($mirroredAd === $adminDisabled) ? 'yes' : 'no');
                $fail++;
            }
        }

        echo "\n═══════════════════════════════════════════════════════════════════\n";
        printf("SUMMARY: PASS=%d  FAIL=%d  SKIP=%d  total=%d\n", $pass, $fail, $skip, $pass + $fail + $skip);
        echo ($fail === 0) ? "GATE: ✅ PASS\n" : "GATE: ❌ FAIL\n";
        echo "═══════════════════════════════════════════════════════════════════\n";
        exit($fail === 0 ? 0 : 1);
    }
}
