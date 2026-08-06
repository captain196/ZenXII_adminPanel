<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Transport_probe_log3 — event/trip/student notification idempotency (LOG-3).
 *
 * _computeIdempotencyKey's salient set omitted event_id/trip_id/student_id, so
 * two distinct notifications sharing category + route/vehicle in the same TTL
 * bucket collided → the second was suppressed. Worst cases: event.scheduled
 * (carries no route/vehicle → any two events shared one key) and multiple
 * trip.student_no_show on one trip (differ only by student_id). Fix adds
 * event/trip/student to the salient set.
 *
 * Reflection-drives the private key builder (deterministic; no writes).
 *   G1 two events (same bucket, no route/vehicle) → DIFFERENT keys
 *   G2 two no-shows on one trip (same trip, different student) → DIFFERENT keys
 *   G3 identical ctx → SAME key (idempotency preserved — real dupes still dedup)
 *
 * CLI-only.
 *   SCHOOL_ID=SCH_D94FE8F7AD SESSION_YEAR=2026-27 \
 *     php index.php transport_probe_log3 run
 */
class Transport_probe_log3 extends CI_Controller
{
    public function __construct()
    {
        parent::__construct();
        if (!$this->input->is_cli_request()) { show_error('CLI-only.', 403); }
        $this->load->library('firebase',          null, 'firebase');
        $this->load->library('firestore_service', null, 'fs');
        $this->load->library('numbering_service', null, 'numbering');
        $this->load->library('transport_service', null, 'transport');
        $this->load->library('transport_notifier', null, 'notifier');
    }

    public function run(): void
    {
        $sid  = (string) (getenv('SCHOOL_ID')    ?: '');
        $sess = (string) (getenv('SESSION_YEAR') ?: '');
        if ($sid === '' || $sess === '') { fwrite(STDERR, "ERROR: SCHOOL_ID + SESSION_YEAR required.\n"); exit(1); }
        try {
            $this->fs->init($sid, $sess);
            $this->numbering->init($this->fs, $sid, $sess);
            $this->transport->init($this->fs, $this->numbering, $sid, $sess, 'transport_probe_log3');
            $this->notifier->init($this->fs, $this->numbering, $this->transport, $sid, $sess, 'transport_probe_log3');
        } catch (\Throwable $e) { fwrite(STDERR, "ERROR: init — " . $e->getMessage() . "\n"); exit(1); }

        $passed = 0; $failed = 0;
        $pass = function (string $m) use (&$passed) { echo "  PASS: {$m}\n"; $passed++; };
        $fail = function (string $m) use (&$failed) { echo "  FAIL: {$m}\n"; $failed++; };

        $rm = new \ReflectionMethod($this->notifier, '_computeIdempotencyKey');
        $rm->setAccessible(true);
        $key = fn(string $cat, array $ctx) => (string) $rm->invoke($this->notifier, $cat, $ctx, 21600); // 6h bucket

        echo "==================================================\n";
        echo " LOG-3 notification idempotency salience probe\n";
        echo " tenant={$sid} session={$sess}\n";
        echo "==================================================\n";

        echo "\n[G1] two events in one bucket produce DIFFERENT keys\n";
        $k1 = $key('event.scheduled', ['event_id' => 'EVT_A']);
        $k2 = $key('event.scheduled', ['event_id' => 'EVT_B']);
        ($k1 !== $k2)
            ? $pass("EVT_A vs EVT_B keys differ ({$k1} != {$k2}) — 2nd event no longer suppressed")
            : $fail("both events collapsed to one key {$k1} (2nd suppressed)");

        echo "\n[G2] two no-shows on one trip produce DIFFERENT keys\n";
        $base = ['trip_id' => 'TRP_X', 'route_id' => 'RT_1', 'vehicle_id' => 'VH_1'];
        $k3 = $key('trip.student_no_show', array_merge($base, ['student_id' => 'STU_1']));
        $k4 = $key('trip.student_no_show', array_merge($base, ['student_id' => 'STU_2']));
        ($k3 !== $k4)
            ? $pass("STU_1 vs STU_2 keys differ ({$k3} != {$k4}) — both parents notified")
            : $fail("both no-shows collapsed to one key {$k3} (2nd student's parent suppressed)");

        echo "\n[G3] identical ctx still yields the SAME key (idempotency intact)\n";
        $k5 = $key('trip.student_no_show', array_merge($base, ['student_id' => 'STU_1']));
        ($k3 === $k5)
            ? $pass("identical ctx → same key ({$k3}) — genuine duplicates still dedup")
            : $fail("identical ctx produced different keys ({$k3} != {$k5}) — idempotency broken");

        echo "\n==================================================\n";
        echo " RESULT: passed={$passed} failed={$failed}\n";
        echo "==================================================\n";
        exit($failed === 0 ? 0 : 2);
    }
}
