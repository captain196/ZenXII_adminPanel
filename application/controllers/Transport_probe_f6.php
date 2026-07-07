<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Transport_probe_f6 — F6 Communication Integration runtime probe
 * (2026-07-07).
 *
 * Exercises the F6 flow end-to-end via Transport_notifier:
 *   1. Ops-scope notify (priority matrix + dedup claim + audit)
 *   2. Idempotency dedupe on second identical fire
 *   3. Bad category raises LogicException (contract guard)
 *   4. SOS raise → active with dual-write active+status fields
 *   5. SOS respond → responded (still active)
 *   6. SOS resolve → resolved, active=false; also verify sos.raised
 *      is NEVER deduped (safety exemption)
 *
 * CLI-only. Invocation:
 *   SCHOOL_ID=SCH_D94FE8F7AD SESSION_YEAR=2026-27 \
 *     php index.php transport_probe_f6 run
 *
 * Exit codes:
 *   0 — all gates passed
 *   1 — env / init failed
 *   2 — probe assertion failure
 */
class Transport_probe_f6 extends CI_Controller
{
    private array $createdSos = [];

    public function __construct()
    {
        parent::__construct();
        if (!$this->input->is_cli_request()) {
            show_error('Transport_probe_f6 is CLI-only.', 403);
        }
        $this->load->library('firebase',           null, 'firebase');
        $this->load->library('firestore_service',  null, 'fs');
        $this->load->library('numbering_service',  null, 'numbering');
        $this->load->library('transport_service',  null, 'transport');
        $this->load->library('transport_notifier', null, 'transportNotifier');
    }

    public function run(): void
    {
        $sid  = (string) (getenv('SCHOOL_ID')    ?: '');
        $sess = (string) (getenv('SESSION_YEAR') ?: '');
        if ($sid === '' || $sess === '') {
            fwrite(STDERR, "ERROR: SCHOOL_ID + SESSION_YEAR required.\n");
            exit(1);
        }
        try {
            $this->fs->init($sid, $sess);
            $this->numbering->init($this->fs, $sid, $sess);
            $this->transport->init($this->fs, $this->numbering, $sid, $sess, 'transport_probe_f6');
            $this->transportNotifier->init($this->fs, $this->numbering, $this->transport, $sid, $sess, 'transport_probe_f6');
        } catch (\Throwable $e) {
            fwrite(STDERR, "ERROR: init failed — " . $e->getMessage() . "\n");
            exit(1);
        }

        $passed = 0; $failed = 0; $errors = [];
        // Per-run ID keeps the probe re-runnable within the same TTL bucket
        // (route.suspended default bucket = 1h). Without this, a second run
        // within an hour sees the first run's idempotency claim and
        // step 1 correctly reports dedupe_skipped=true.
        $runId = (string) (int) (microtime(true) * 1000);

        echo "==================================================\n";
        echo " F6 Communication Integration Runtime Probe\n";
        echo " tenant=$sid session=$sess\n";
        echo "==================================================\n";

        // ------------------------------------------------------------
        // Step 1 — ops-scope notify (route.suspended: Important, 1h dedup)
        // ------------------------------------------------------------
        echo "\n[STEP 1] ops-scope notify (route.suspended)\n";
        try {
            $r = $this->transportNotifier->notify('route.suspended', [
                'route_id'        => 'PROBE_F6_R1_' . $runId . '',
                'route_name'      => 'F6 Probe Route',
                'reason_category' => 'weather',
                'reason'          => 'F6 probe',
                'from_date'       => date('Y-m-d'),
                'to_date'         => date('Y-m-d', time() + 86400),
                'action'          => 'suspended',
                'suspension_id'   => 'PROBE_F6_SUS1',
            ]);
            if (!$r['emitted']) { throw new \RuntimeException('notify not emitted'); }
            if ($r['priority'] !== 'Important') { throw new \RuntimeException("expected Important, got {$r['priority']}"); }
            if ($r['dedupe_skipped']) { throw new \RuntimeException('first fire should NOT be dedup-skipped'); }
            echo "  PASS: notice={$r['notice_id']} priority={$r['priority']} audience={$r['audience_scope']}\n";
            $passed++;
        } catch (\Throwable $e) {
            echo "  FAIL: " . $e->getMessage() . "\n"; $failed++; $errors[] = 'step1: '.$e->getMessage();
        }

        // ------------------------------------------------------------
        // Step 2 — dedupe on identical repeat within TTL bucket
        // ------------------------------------------------------------
        echo "\n[STEP 2] Idempotency dedupe (identical repeat within bucket)\n";
        try {
            $r = $this->transportNotifier->notify('route.suspended', [
                'route_id'        => 'PROBE_F6_R1_' . $runId . '',
                'route_name'      => 'F6 Probe Route',
                'reason_category' => 'weather',
                'reason'          => 'F6 probe',
                'from_date'       => date('Y-m-d'),
                'to_date'         => date('Y-m-d', time() + 86400),
                'action'          => 'suspended',
                'suspension_id'   => 'PROBE_F6_SUS1',
            ]);
            if (!$r['dedupe_skipped']) { throw new \RuntimeException('expected dedupe_skipped=true'); }
            if ($r['emitted'])         { throw new \RuntimeException('expected emitted=false'); }
            echo "  PASS: second fire skipped by idempotency (30d retention, 1h bucket)\n";
            $passed++;
        } catch (\Throwable $e) {
            echo "  FAIL: " . $e->getMessage() . "\n"; $failed++; $errors[] = 'step2: '.$e->getMessage();
        }

        // ------------------------------------------------------------
        // Step 3 — bad category rejected
        // ------------------------------------------------------------
        echo "\n[STEP 3] Bad category rejected\n";
        try {
            $rejected = false;
            try {
                $this->transportNotifier->notify('nonexistent.category', []);
            } catch (\LogicException $le) {
                $rejected = true;
            }
            if (!$rejected) { throw new \RuntimeException('unknown category was NOT rejected'); }
            echo "  PASS: unknown category rejected with LogicException\n";
            $passed++;
        } catch (\Throwable $e) {
            echo "  FAIL: " . $e->getMessage() . "\n"; $failed++; $errors[] = 'step3: '.$e->getMessage();
        }

        // ------------------------------------------------------------
        // Step 4 — SOS raise: dual-write active + status
        // ------------------------------------------------------------
        echo "\n[STEP 4] SOS raise (dual-write active+status, URGENT cascade)\n";
        try {
            $r = $this->transportNotifier->raiseSos([
                'triggered_by'      => 'probe:step4',
                'triggered_by_role' => 'admin',
                'vehicle_id'        => 'PROBE_F6_VEH',
                'route_id'          => 'PROBE_F6_R1_' . $runId . '',
                'alert_type'        => 'breakdown',
                'message'           => 'F6 probe SOS raise',
                'lat'               => 12.9716,
                'lng'               => 77.5946,
            ]);
            $sosId = (string) ($r['sos_id'] ?? '');
            if ($sosId === '') { throw new \RuntimeException('no sos_id returned'); }
            $this->createdSos[] = $sosId;
            $doc = $this->fs->getEntity('sosAlerts', $sosId);
            if (!is_array($doc)) { throw new \RuntimeException('sos doc not persisted'); }
            if (($doc['status'] ?? '') !== 'active') { throw new \RuntimeException("status !== active (got '" . ($doc['status'] ?? '') . "')"); }
            if (($doc['active'] ?? null) !== true)   { throw new \RuntimeException('active !== true'); }
            // Cascade must not be deduped for sos.raised
            if (!($r['notify']['emitted'] ?? false)) { throw new \RuntimeException('SOS cascade not emitted'); }
            if ($r['notify']['priority'] !== 'Urgent') { throw new \RuntimeException("expected Urgent priority, got " . $r['notify']['priority']); }
            echo "  PASS: sos={$sosId} dual-write ok, cascade priority=Urgent\n";
            $passed++;
        } catch (\Throwable $e) {
            echo "  FAIL: " . $e->getMessage() . "\n"; $failed++; $errors[] = 'step4: '.$e->getMessage();
        }

        // ------------------------------------------------------------
        // Step 5 — SOS respond: still active, status='responded'
        // ------------------------------------------------------------
        echo "\n[STEP 5] SOS respond\n";
        try {
            if (empty($this->createdSos)) { throw new \RuntimeException('no probe SOS to respond'); }
            $sosId = $this->createdSos[0];
            $this->transportNotifier->respondSos($sosId, 'Probe: driver dispatched');
            $doc = $this->fs->getEntity('sosAlerts', $sosId);
            if (($doc['status'] ?? '') !== 'responded') { throw new \RuntimeException("status !== responded"); }
            if (($doc['active'] ?? null) !== true)     { throw new \RuntimeException('active flipped false too early'); }
            if (($doc['respondedAt'] ?? '') === '')    { throw new \RuntimeException('respondedAt not set'); }
            echo "  PASS: sos={$sosId} responded (still active=true until resolved)\n";
            $passed++;
        } catch (\Throwable $e) {
            echo "  FAIL: " . $e->getMessage() . "\n"; $failed++; $errors[] = 'step5: '.$e->getMessage();
        }

        // ------------------------------------------------------------
        // Step 6 — SOS resolve + sos.raised is NEVER deduped
        // ------------------------------------------------------------
        echo "\n[STEP 6] SOS resolve + sos.raised never-dedupe\n";
        try {
            if (empty($this->createdSos)) { throw new \RuntimeException('no probe SOS to resolve'); }
            $sosId = $this->createdSos[0];
            $this->transportNotifier->resolveSos($sosId, 'Probe: safe');
            $doc = $this->fs->getEntity('sosAlerts', $sosId);
            if (($doc['status'] ?? '') !== 'resolved') { throw new \RuntimeException("status !== resolved"); }
            if (($doc['active'] ?? null) !== false)   { throw new \RuntimeException('active !== false post-resolve'); }
            // Verify safety exemption: raise 2 SOS in quick succession with
            // identical vehicle/route/message; BOTH must emit (safety events
            // never dedupe).
            $r1 = $this->transportNotifier->raiseSos([
                'triggered_by'=> 'probe:step6a', 'triggered_by_role' => 'admin',
                'vehicle_id'  => 'PROBE_F6_VEH_SAFE', 'route_id' => 'PROBE_F6_R1_' . $runId . '',
                'alert_type'  => 'accident', 'message' => 'F6 probe safety exemption test',
            ]);
            $r2 = $this->transportNotifier->raiseSos([
                'triggered_by'=> 'probe:step6b', 'triggered_by_role' => 'admin',
                'vehicle_id'  => 'PROBE_F6_VEH_SAFE', 'route_id' => 'PROBE_F6_R1_' . $runId . '',
                'alert_type'  => 'accident', 'message' => 'F6 probe safety exemption test',
            ]);
            $this->createdSos[] = (string) ($r1['sos_id'] ?? '');
            $this->createdSos[] = (string) ($r2['sos_id'] ?? '');
            $e1 = (bool) ($r1['notify']['emitted'] ?? false);
            $e2 = (bool) ($r2['notify']['emitted'] ?? false);
            $s1 = (bool) ($r1['notify']['dedupe_skipped'] ?? false);
            $s2 = (bool) ($r2['notify']['dedupe_skipped'] ?? false);
            if (!$e1 || !$e2 || $s1 || $s2) {
                throw new \RuntimeException("sos.raised was deduped (emitted1={$e1} emitted2={$e2} skipped1={$s1} skipped2={$s2}) — SAFETY VIOLATION");
            }
            echo "  PASS: sos={$sosId} resolved; safety exemption honored (2 sos.raised both emitted)\n";
            $passed++;
        } catch (\Throwable $e) {
            echo "  FAIL: " . $e->getMessage() . "\n"; $failed++; $errors[] = 'step6: '.$e->getMessage();
        }

        // ------------------------------------------------------------
        // Cleanup — resolve any residual probe SOS
        // ------------------------------------------------------------
        echo "\n[CLEANUP] Resolve residual probe SOS docs\n";
        foreach ($this->createdSos as $id) {
            if ($id === '') { continue; }
            try {
                $doc = $this->fs->getEntity('sosAlerts', $id);
                if (is_array($doc) && ($doc['status'] ?? '') !== 'resolved') {
                    $this->transportNotifier->resolveSos($id, 'F6 probe teardown');
                    echo "  cleaned: {$id}\n";
                }
            } catch (\Throwable $e) {
                echo "  cleanup skip {$id}: " . $e->getMessage() . "\n";
            }
        }

        echo "\n==================================================\n";
        echo " RESULT: passed=$passed failed=$failed\n";
        if ($failed > 0) {
            foreach ($errors as $err) { echo "  - $err\n"; }
            echo "==================================================\n";
            exit(2);
        }
        echo "==================================================\n";
        exit(0);
    }
}
