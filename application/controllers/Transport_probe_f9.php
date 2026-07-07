<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Transport_probe_f9 — F9 Teacher App Transport runtime probe
 * (LC-22, 2026-07-07).
 *
 * Exercises the F9 PHP-side flow:
 *   1. SOS severity priority routing — Low/Medium/High/Critical map
 *      to Normal/Important/Urgent/Urgent
 *   2. Default severity ('High') when omitted (Refinement 1)
 *   3. Invalid severity is rejected at raiseSos() input validation
 *   4. Firestore rule source contains all F9 helpers + driver-scope
 *      predicates
 *   5. Every raised SOS carries severity in the doc (F9-3 gate)
 *   6. Kotlin-canonical SOS shape preserved (severity is ADDITIVE,
 *      existing fields unchanged) — Parent+Teacher app compat
 *   7. Client diagnostic metadata (Refinement additional) does NOT
 *      participate in server-side raiseSos() authz — admin-panel writes
 *      still work without app_version/client_platform/client_timestamp
 *
 * CLI-only. Invocation:
 *   SCHOOL_ID=SCH_D94FE8F7AD SESSION_YEAR=2026-27 \
 *     php index.php transport_probe_f9 run
 *
 * Exit codes:
 *   0 — all gates passed
 *   1 — env / init failed
 *   2 — probe assertion failure
 */
class Transport_probe_f9 extends CI_Controller
{
    private array $createdSos = [];

    public function __construct()
    {
        parent::__construct();
        if (!$this->input->is_cli_request()) {
            show_error('Transport_probe_f9 is CLI-only.', 403);
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
            $this->transport->init($this->fs, $this->numbering, $sid, $sess, 'transport_probe_f9');
            $this->transportNotifier->init($this->fs, $this->numbering, $this->transport, $sid, $sess, 'transport_probe_f9');
        } catch (\Throwable $e) {
            fwrite(STDERR, "ERROR: init failed — " . $e->getMessage() . "\n");
            exit(1);
        }

        $passed = 0; $failed = 0; $errors = [];

        echo "==================================================\n";
        echo " F9 Teacher App Transport Runtime Probe\n";
        echo " tenant=$sid session=$sess\n";
        echo "==================================================\n";

        // ------------------------------------------------------------
        // Step 1 — severity → priority routing (Low/Medium/High/Critical)
        // ------------------------------------------------------------
        echo "\n[STEP 1] SOS severity → priority routing\n";
        $expectedRouting = [
            'Low'      => 'Normal',
            'Medium'   => 'Important',
            'High'     => 'Urgent',
            'Critical' => 'Urgent',
        ];
        try {
            foreach ($expectedRouting as $sev => $expectedPrio) {
                $r = $this->transportNotifier->raiseSos([
                    'triggered_by'      => 'probe:f9',
                    'triggered_by_role' => 'driver',
                    'vehicle_id'        => "PROBE_F9_VEH_{$sev}",
                    'route_id'          => 'PROBE_F9_R1',
                    'alert_type'        => 'breakdown',
                    'severity'          => $sev,
                    'message'           => "F9 probe severity {$sev}",
                    'lat'               => 12.9716,
                    'lng'               => 77.5946,
                ]);
                $sosId = (string) ($r['sos_id'] ?? '');
                if ($sosId === '') { throw new \RuntimeException("no sos_id for severity {$sev}"); }
                $this->createdSos[] = $sosId;
                $actualPrio = (string) ($r['notify']['priority'] ?? '');
                if ($actualPrio !== $expectedPrio) {
                    throw new \RuntimeException("severity={$sev} → priority={$actualPrio}, expected {$expectedPrio}");
                }
            }
            echo "  PASS: 4 severities routed correctly (Low→Normal, Medium→Important, High/Critical→Urgent)\n";
            $passed++;
        } catch (\Throwable $e) {
            echo "  FAIL: " . $e->getMessage() . "\n"; $failed++; $errors[] = 'step1: '.$e->getMessage();
        }

        // ------------------------------------------------------------
        // Step 2 — default severity is 'High' when omitted (Refinement 1)
        // ------------------------------------------------------------
        echo "\n[STEP 2] Default severity = 'High' when omitted (Refinement 1)\n";
        try {
            $r = $this->transportNotifier->raiseSos([
                'triggered_by'      => 'probe:f9',
                'triggered_by_role' => 'driver',
                'vehicle_id'        => 'PROBE_F9_VEH_DEFAULT',
                'route_id'          => 'PROBE_F9_R1',
                'alert_type'        => 'emergency',
                // severity intentionally omitted
                'message'           => 'F9 probe default severity',
            ]);
            $sosId = (string) ($r['sos_id'] ?? '');
            $this->createdSos[] = $sosId;
            $doc = $this->fs->getEntity('sosAlerts', $sosId);
            $sev = (string) ($doc['severity'] ?? '');
            if ($sev !== 'High') {
                throw new \RuntimeException("default severity was '{$sev}', expected 'High'");
            }
            if (($r['notify']['priority'] ?? '') !== 'Urgent') {
                throw new \RuntimeException("default High → priority " . ($r['notify']['priority'] ?? 'null') . " (expected Urgent)");
            }
            echo "  PASS: default severity='High' → priority=Urgent\n";
            $passed++;
        } catch (\Throwable $e) {
            echo "  FAIL: " . $e->getMessage() . "\n"; $failed++; $errors[] = 'step2: '.$e->getMessage();
        }

        // ------------------------------------------------------------
        // Step 3 — invalid severity rejected
        // ------------------------------------------------------------
        echo "\n[STEP 3] Invalid severity rejected\n";
        try {
            $rejected = false;
            try {
                $this->transportNotifier->raiseSos([
                    'triggered_by'      => 'probe:f9',
                    'triggered_by_role' => 'driver',
                    'vehicle_id'        => 'PROBE_F9_VEH_BAD',
                    'alert_type'        => 'emergency',
                    'severity'          => 'Catastrophic',   // not in enum
                    'message'           => 'F9 probe bad severity',
                ]);
            } catch (\InvalidArgumentException $ie) {
                $rejected = true;
            }
            if (!$rejected) { throw new \RuntimeException('invalid severity was NOT rejected'); }
            echo "  PASS: invalid severity 'Catastrophic' rejected with InvalidArgumentException\n";
            $passed++;
        } catch (\Throwable $e) {
            echo "  FAIL: " . $e->getMessage() . "\n"; $failed++; $errors[] = 'step3: '.$e->getMessage();
        }

        // ------------------------------------------------------------
        // Step 4 — Firestore rule source contains F9 helpers
        // ------------------------------------------------------------
        echo "\n[STEP 4] Firestore rules contain F9 driver-scope helpers\n";
        try {
            $rulesPath = FCPATH . 'firebase-rules/firestore.rules';
            $src = is_readable($rulesPath) ? (string) file_get_contents($rulesPath) : '';
            $needles = [
                'function driverIs(',
                'function isDriverOfTrip(',
                'function hasClientMetadata(',
                'user_id',
                'client_platform',
                "'admin', 'driver'",       // marked_by_role guard
                "'Low', 'Medium', 'High', 'Critical'",  // severity enum
            ];
            $missing = [];
            foreach ($needles as $n) {
                if (strpos($src, $n) === false) { $missing[] = $n; }
            }
            if ($missing !== []) {
                throw new \RuntimeException('missing rule fragments: ' . implode('; ', $missing));
            }
            echo "  PASS: all " . count($needles) . " F9 rule fragments present\n";
            $passed++;
        } catch (\Throwable $e) {
            echo "  FAIL: " . $e->getMessage() . "\n"; $failed++; $errors[] = 'step4: '.$e->getMessage();
        }

        // ------------------------------------------------------------
        // Step 5 — every raised SOS carries severity in the doc
        // ------------------------------------------------------------
        echo "\n[STEP 5] Every raised SOS carries severity (F9-3 client compat)\n";
        try {
            $missing = 0;
            foreach ($this->createdSos as $sid_) {
                $doc = $this->fs->getEntity('sosAlerts', $sid_);
                if (!is_array($doc)) { continue; }
                if (!array_key_exists('severity', $doc)) { $missing++; }
            }
            if ($missing > 0) {
                throw new \RuntimeException("{$missing} SOS docs missing severity field");
            }
            echo "  PASS: all " . count($this->createdSos) . " probe SOS docs carry severity\n";
            $passed++;
        } catch (\Throwable $e) {
            echo "  FAIL: " . $e->getMessage() . "\n"; $failed++; $errors[] = 'step5: '.$e->getMessage();
        }

        // ------------------------------------------------------------
        // Step 6 — Kotlin-canonical SOS shape preserved
        // ------------------------------------------------------------
        echo "\n[STEP 6] Kotlin SosAlertDoc shape preserved (severity additive)\n";
        try {
            if (empty($this->createdSos)) { throw new \RuntimeException('no probe SOS'); }
            $doc = $this->fs->getEntity('sosAlerts', $this->createdSos[0]);
            $requiredKotlinFields = ['id', 'schoolId', 'triggeredBy', 'triggeredByRole',
                                     'vehicleId', 'routeId', 'lat', 'lng', 'alertType',
                                     'message', 'status', 'respondedBy', 'notifiedParents',
                                     'createdAt'];
            $missing = [];
            foreach ($requiredKotlinFields as $k) {
                if (!array_key_exists($k, $doc)) { $missing[] = $k; }
            }
            if ($missing !== []) {
                throw new \RuntimeException('Kotlin base fields missing: ' . implode(',', $missing));
            }
            if (!array_key_exists('severity', $doc)) {
                throw new \RuntimeException('severity field missing (F9 additive)');
            }
            echo "  PASS: SOS doc has full Kotlin base shape + severity additive\n";
            $passed++;
        } catch (\Throwable $e) {
            echo "  FAIL: " . $e->getMessage() . "\n"; $failed++; $errors[] = 'step6: '.$e->getMessage();
        }

        // ------------------------------------------------------------
        // Step 7 — client diagnostic metadata does NOT gate PHP-side authz
        // ------------------------------------------------------------
        echo "\n[STEP 7] Client metadata not required for PHP admin writes\n";
        try {
            // Simulate an admin-panel SOS with NO client metadata — must
            // succeed (Refinement additional: metadata for troubleshooting,
            // not authz).
            $r = $this->transportNotifier->raiseSos([
                'triggered_by'      => 'probe:f9',
                'triggered_by_role' => 'admin',      // admin path
                'vehicle_id'        => 'PROBE_F9_VEH_ADMIN',
                'alert_type'        => 'other',
                'severity'          => 'Medium',
                'message'           => 'F9 admin path — no client metadata',
                // NO app_version, client_platform, client_timestamp
            ]);
            $sid2 = (string) ($r['sos_id'] ?? '');
            if ($sid2 === '') { throw new \RuntimeException('admin SOS without metadata failed'); }
            $this->createdSos[] = $sid2;
            echo "  PASS: admin-panel SOS ({$sid2}) succeeded without client metadata\n";
            $passed++;
        } catch (\Throwable $e) {
            echo "  FAIL: " . $e->getMessage() . "\n"; $failed++; $errors[] = 'step7: '.$e->getMessage();
        }

        // ------------------------------------------------------------
        // Cleanup — resolve all probe SOS
        // ------------------------------------------------------------
        echo "\n[CLEANUP] Resolving probe SOS docs\n";
        foreach ($this->createdSos as $id) {
            if ($id === '') { continue; }
            try {
                $doc = $this->fs->getEntity('sosAlerts', $id);
                if (is_array($doc) && ($doc['status'] ?? '') !== 'resolved') {
                    $this->transportNotifier->resolveSos($id, 'F9 probe teardown');
                    echo "  resolved: {$id}\n";
                }
            } catch (\Throwable $e) {
                echo "  skip {$id}: " . $e->getMessage() . "\n";
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
