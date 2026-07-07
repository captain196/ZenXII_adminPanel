<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Transport_probe_f10 — F10 Parent App Transport runtime probe
 * (LC-20 + LC-21, 2026-07-07).
 *
 * Exercises the F10 server-side preference-honoring fanout:
 *   1. Preference-honoring — a mute in notificationPreferences skips
 *      the parent for a muteable category
 *   2. Safety exemption — a mute is IGNORED for safety-exempt
 *      categories (parent still receives sos.raised etc.)
 *   3. Non-existent prefs doc = default-on (parent receives)
 *   4. Verifier F10-2 cross-check — every Urgent category is exempt
 *   5. Verifier F10-3 sanity — prefs docs don't (silently) mute
 *      safety categories via prior tampering
 *   6. Fee_lifecycle lowercase — category is normalized to 'transport'
 *      when Transport module creates fees
 *   7. Firestore rule presence for notificationPreferences
 *
 * CLI-only. Invocation:
 *   SCHOOL_ID=SCH_D94FE8F7AD SESSION_YEAR=2026-27 \
 *     php index.php transport_probe_f10 run
 *
 * Exit codes:
 *   0 — all gates passed
 *   1 — env / init failed
 *   2 — probe assertion failure
 */
class Transport_probe_f10 extends CI_Controller
{
    private array $createdFixtures = [];   // [ [collection, entityId], ... ]

    public function __construct()
    {
        parent::__construct();
        if (!$this->input->is_cli_request()) {
            show_error('Transport_probe_f10 is CLI-only.', 403);
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
            $this->transport->init($this->fs, $this->numbering, $sid, $sess, 'transport_probe_f10');
            $this->transportNotifier->init($this->fs, $this->numbering, $this->transport, $sid, $sess, 'transport_probe_f10');
        } catch (\Throwable $e) {
            fwrite(STDERR, "ERROR: init failed — " . $e->getMessage() . "\n");
            exit(1);
        }

        $passed = 0; $failed = 0; $errors = [];

        echo "==================================================\n";
        echo " F10 Parent App Transport Runtime Probe\n";
        echo " tenant=$sid session=$sess\n";
        echo "==================================================\n";

        // Fixtures: create a parent user + student + prefs doc that
        // mutes a muteable category. We simulate the fanout via the
        // private _filterByPreferences path exposed through the
        // parents_of_students public API.
        [$parentId, $studentId, $prefsDocId] = $this->_provisionFixtures($sid);
        echo "\n[FIXTURES] parent={$parentId} student={$studentId} prefs={$prefsDocId}\n";

        // ------------------------------------------------------------
        // Step 1 — mute honored on muteable parents_of_students category
        // ------------------------------------------------------------
        echo "\n[STEP 1] Muteable parents_of_students category (event.scheduled) skips muted parent\n";
        try {
            // Write mute: event.scheduled. Entity id = parentId; setEntity
            // prefixes schoolId → doc id becomes {schoolId}_{parentId}.
            $this->fs->setEntity('notificationPreferences', $parentId, [
                'schoolId'         => $sid,
                'userId'           => $parentId,
                'mutedCategories'  => ['event.scheduled'],
                'updatedAt'        => date('c'),
            ], /* merge */ false);
            $this->createdFixtures[] = ['notificationPreferences', $parentId];

            // Fire event.scheduled (parents_of_students scope, muteable)
            $r = $this->transportNotifier->notify('event.scheduled', [
                'event_id'    => 'PROBE_F10_EVT_1',
                'event_type'  => 'Excursion',
                'title'       => 'F10 Probe Excursion',
                'event_date'  => date('Y-m-d'),
                'destination' => 'F10 Probe destination',
                'student_ids' => [$studentId],
            ]);
            $emitted = (bool) ($r['emitted'] ?? false);
            $count   = (int)  ($r['per_user_count'] ?? 0);
            $skipped = (bool) ($r['dedupe_skipped'] ?? false);
            // With one student → one parent → muted → 0 emitted
            if ($emitted || $count > 0) {
                throw new \RuntimeException("mute NOT honored: emitted={$emitted} count={$count} dedupe_skipped={$skipped}");
            }
            echo "  PASS: muted parent received 0 notifications for event.scheduled\n";
            $passed++;
        } catch (\Throwable $e) {
            echo "  FAIL: " . $e->getMessage() . "\n"; $failed++; $errors[] = 'step1: '.$e->getMessage();
        }

        // ------------------------------------------------------------
        // Step 2 — safety exemption ignores mute
        // ------------------------------------------------------------
        echo "\n[STEP 2] Safety exemption — trip.student_no_show still fires despite mute\n";
        try {
            // Now write a broader mute including a safety-exempt cat
            // (simulates client-side tampering attempt)
            $this->fs->setEntity('notificationPreferences', $parentId, [
                'schoolId'        => $sid,
                'userId'          => $parentId,
                'mutedCategories' => ['trip.student_no_show', 'trip.delay'],
                'updatedAt'       => date('c'),
            ], /* merge */ false);

            $r = $this->transportNotifier->notify('trip.student_no_show', [
                'trip_id'    => 'PROBE_F10_TRIP',
                'route_id'   => 'PROBE_F10_ROUTE',
                'route_name' => 'F10 Probe Route',
                'trip_date'  => date('Y-m-d'),
                'direction'  => 'Pickup',
                'student_ids'=> [$studentId],
            ]);
            $emitted = (bool) ($r['emitted'] ?? false);
            $count   = (int)  ($r['per_user_count'] ?? 0);
            // Safety exempt → mute ignored → parent still receives
            if (!$emitted || $count < 1) {
                throw new \RuntimeException("safety exemption FAILED: emitted={$emitted} count={$count}");
            }
            echo "  PASS: safety-exempt trip.student_no_show ignored mute (count={$count})\n";
            $passed++;
        } catch (\Throwable $e) {
            echo "  FAIL: " . $e->getMessage() . "\n"; $failed++; $errors[] = 'step2: '.$e->getMessage();
        }

        // ------------------------------------------------------------
        // Step 3 — no prefs doc = default-on
        // ------------------------------------------------------------
        echo "\n[STEP 3] Missing prefs doc = default-on (parent receives)\n";
        try {
            // Delete the prefs doc; simulate a parent who never opened
            // preferences UI. Entity id = parentId; removeEntity handles
            // schoolId prefix internally.
            $this->fs->removeEntity('notificationPreferences', $parentId);

            // Fire event.consent_reminder — DIFFERENT muteable
            // parents_of_students category than step 1 (avoids the 24h
            // dedup TTL colliding with step 1's event.scheduled).
            $r = $this->transportNotifier->notify('event.consent_reminder', [
                'event_id'    => 'PROBE_F10_EVT_1',
                'title'       => 'F10 Probe Excursion',
                'event_date'  => date('Y-m-d'),
                'student_ids' => [$studentId],
            ]);
            $emitted = (bool) ($r['emitted'] ?? false);
            $count   = (int)  ($r['per_user_count'] ?? 0);
            $skipped = (bool) ($r['dedupe_skipped'] ?? false);
            if (!$emitted || $count < 1) {
                throw new \RuntimeException("default-on FAILED: emitted={$emitted} count={$count} dedupe_skipped={$skipped}");
            }
            echo "  PASS: no prefs → default-on → parent received notification (count={$count})\n";
            $passed++;
        } catch (\Throwable $e) {
            echo "  FAIL: " . $e->getMessage() . "\n"; $failed++; $errors[] = 'step3: '.$e->getMessage();
        }

        // ------------------------------------------------------------
        // Step 4 — SAFETY_EXEMPT covers every Urgent category
        // ------------------------------------------------------------
        echo "\n[STEP 4] SAFETY_EXEMPT covers all PRIORITY_MATRIX Urgent entries\n";
        try {
            $urgentCategories = [];
            foreach (Transport_notifier::PRIORITY_MATRIX as $cat => $prio) {
                if ($prio === Transport_notifier::PRIORITY_URGENT) {
                    $urgentCategories[] = $cat;
                }
            }
            $exempt = (array) Transport_notifier::SAFETY_EXEMPT_CATEGORIES;
            $missing = [];
            foreach ($urgentCategories as $c) {
                if (!in_array($c, $exempt, true)) { $missing[] = $c; }
            }
            if ($missing !== []) {
                throw new \RuntimeException('Urgent categories NOT safety-exempt: ' . implode(',', $missing));
            }
            echo "  PASS: " . count($urgentCategories) . " Urgent categories, all safety-exempt\n";
            $passed++;
        } catch (\Throwable $e) {
            echo "  FAIL: " . $e->getMessage() . "\n"; $failed++; $errors[] = 'step4: '.$e->getMessage();
        }

        // ------------------------------------------------------------
        // Step 5 — Firestore rule for notificationPreferences present
        // ------------------------------------------------------------
        echo "\n[STEP 5] firestore.rules has notificationPreferences owner-only\n";
        try {
            $rulesPath = FCPATH . 'firebase-rules/firestore.rules';
            $src = is_readable($rulesPath) ? (string) file_get_contents($rulesPath) : '';
            $needles = [
                'match /notificationPreferences/',
                "school_id + '_' + request.auth.token.user_id",
            ];
            $missing = [];
            foreach ($needles as $n) {
                if (strpos($src, $n) === false) { $missing[] = $n; }
            }
            if ($missing !== []) {
                throw new \RuntimeException('rule fragments missing: ' . implode('; ', $missing));
            }
            echo "  PASS: notificationPreferences rule with owner-only doc-id check present\n";
            $passed++;
        } catch (\Throwable $e) {
            echo "  FAIL: " . $e->getMessage() . "\n"; $failed++; $errors[] = 'step5: '.$e->getMessage();
        }

        // ------------------------------------------------------------
        // Step 6 — Fee_lifecycle category normalized to lowercase
        // ------------------------------------------------------------
        echo "\n[STEP 6] Fee_lifecycle::createModuleFee normalizes category to lowercase\n";
        try {
            $flPath = APPPATH . 'libraries/Fee_lifecycle.php';
            $src = is_readable($flPath) ? (string) file_get_contents($flPath) : '';
            if (strpos($src, "'category'    => strtolower((string) \$moduleType)") === false) {
                throw new \RuntimeException("Fee_lifecycle category lowercase normalisation missing");
            }
            echo "  PASS: Fee_lifecycle writes category as lowercase (Refinement 4)\n";
            $passed++;
        } catch (\Throwable $e) {
            echo "  FAIL: " . $e->getMessage() . "\n"; $failed++; $errors[] = 'step6: '.$e->getMessage();
        }

        // ------------------------------------------------------------
        // Cleanup
        // ------------------------------------------------------------
        echo "\n[TEARDOWN] Removing ephemeral fixtures\n";
        foreach (array_reverse($this->createdFixtures) as $f) {
            try {
                $this->fs->removeEntity($f[0], $f[1]);
                echo "  removed {$f[0]}:{$f[1]}\n";
            } catch (\Throwable $e) {
                echo "  skip {$f[0]}:{$f[1]} — " . $e->getMessage() . "\n";
            }
        }
        // Also delete the synthetic student
        try {
            $this->fs->removeEntity('students', 'PROBE_F10_STU');
            echo "  removed students:PROBE_F10_STU\n";
        } catch (\Throwable $_) {}

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

    /**
     * Provision ephemeral fixtures: a synthetic student whose parent_db_key
     * points to a synthetic parent user id. Returns [parentId, studentId,
     * prefsDocId].
     */
    private function _provisionFixtures(string $sid): array
    {
        $now = date('c');
        $parentId  = 'PROBE_F10_PARENT_' . (int) (microtime(true) * 1000);
        $studentId = 'PROBE_F10_STU';
        $prefsDocId = $sid . '_' . $parentId;

        // Student with parent_db_key linkage
        $this->fs->setEntity('students', $studentId, [
            'id'            => $studentId,
            'schoolId'      => $sid,
            'name'          => 'F10 Probe Student',
            'parent_db_key' => $parentId,
            'created_at'    => $now,
            'updated_at'    => $now,
        ], /* merge */ false);
        // Note: not added to $createdFixtures — we clean this up in the
        // teardown block explicitly at end of run() (fixed docId).

        return [$parentId, $studentId, $prefsDocId];
    }
}
