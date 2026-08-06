<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Transport_probe_opscert — full certification of EVERY Transport ops-notice
 * category reaching the Admin notice-bell with the canonical schema.
 *
 * Fires each of the 19 SCOPE_OPS categories via notify() (all route through the
 * single _fanoutOps → `notices` writer) and verifies, per category:
 *   - canonical fields present: timestamp + timestampMs + sentAt + createdAt
 *     + noticeId + schoolId + targetType + targetRoles + status
 *   - appears in the admin-bell query (schoolWhere notices ORDER BY timestamp DESC)
 *   - exactly ONE notices doc per fire (no duplicate)
 *
 * Self-teardown (notices + noticeRecipients). CLI-only.
 *   SCHOOL_ID=SCH_D94FE8F7AD SESSION_YEAR=2026-27 \
 *     php index.php transport_probe_opscert run
 */
class Transport_probe_opscert extends CI_Controller
{
    private array $noticeEnv = [];   // envelope docIds to delete (notices)
    private array $rcptNids  = [];   // noticeIds to clean recipients for

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
            $this->transport->init($this->fs, $this->numbering, $sid, $sess, 'probe_opscert');
            $this->notifier->init($this->fs, $this->numbering, $this->transport, $sid, $sess, 'probe_opscert');
        } catch (\Throwable $e) { fwrite(STDERR, "ERROR: init — " . $e->getMessage() . "\n"); exit(1); }

        $passed = 0; $failed = 0;
        $pass = function (string $m) use (&$passed) { echo "  PASS: {$m}\n"; $passed++; };
        $fail = function (string $m) use (&$failed) { echo "  FAIL: {$m}\n"; $failed++; };

        $ts  = (int) (microtime(true) * 1000);
        $tag = "OPSCERT_{$ts}";

        // The 19 SCOPE_OPS categories (route through _fanoutOps → notices).
        $opsCats = [
            'fleet.doc_expiring', 'fleet.vehicle_replaced', 'fleet.vehicle_retired',
            'route.suspended', 'route.resumed',
            'assignment.bulk_complete', 'assignment.rollover_complete',
            'driver.replaced', 'driver.critical_incident', 'driver.licence_expiring',
            'expense.post_failed', 'expense.pending_stale',
            'sos.raised', 'sos.responded', 'sos.resolved',
            'trip.started', 'trip.completed',
            'event.trip_dispatched', 'event.completed',
        ];

        // Unique-per-category ctx covering every salient id field, so each fire
        // gets a distinct idempotency key (no dedup collision) and renderers have data.
        $ctxFor = function (string $cat) use ($tag) {
            $u = fn(string $f) => "{$tag}_{$f}_{$cat}";
            return [
                'route_id' => $u('RT'), 'route_name' => 'Cert Route', 'vehicle_id' => $u('VH'),
                'staff_id' => $u('STA'), 'driver_id' => $u('STA'), 'driver_name' => 'Cert Driver',
                'expense_id' => $u('EXP'), 'amount' => 100, 'incident_id' => $u('INC'),
                'sos_id' => $u('SOS'), 'batch_id' => $u('BAT'), 'event_id' => $u('EVT'),
                'trip_id' => $u('TRP'), 'student_id' => $u('STU'),
                'title' => 'Cert', 'reason' => 'cert', 'count' => 1,
            ];
        };

        // Query helpers.
        $findByNotice = function (string $nid) use ($sid): array {
            $rows = (array) $this->fs->schoolWhere('notices', [['noticeId', '==', $nid]], null, 'ASC', 10);
            $out = [];
            foreach ($rows as $r) {
                $inner = (isset($r['data']) && is_array($r['data'])) ? $r['data'] : $r;
                if ((string) ($inner['noticeId'] ?? '') === $nid) {
                    $out[] = ['env' => (string) ($r['id'] ?? ''), 'data' => $inner];
                }
            }
            return $out;
        };

        echo "==================================================\n";
        echo " Transport ops-notice full certification (19 categories)\n";
        echo " tenant={$sid}\n";
        echo "==================================================\n";

        $required = ['noticeId','schoolId','title','body','targetType','targetRoles','status','sentAt','createdAt','timestamp','timestampMs'];
        $fired = [];   // cat => notice_id

        try {
            // Fire every ops category.
            echo "\n[FIRE] firing all 19 ops categories via notify()\n";
            foreach ($opsCats as $cat) {
                try {
                    $res = (array) $this->notifier->notify($cat, $ctxFor($cat));
                    $nid = (string) ($res['notice_id'] ?? '');
                    if ($nid === '' || empty($res['emitted'])) {
                        $fail("{$cat}: notify emitted nothing (emitted=" . var_export($res['emitted'] ?? null, true) . ")");
                        continue;
                    }
                    $fired[$cat] = $nid;
                    $this->rcptNids[] = $nid;
                } catch (\Throwable $e) {
                    $fail("{$cat}: notify threw — " . $e->getMessage());
                }
            }
            echo "  fired " . count($fired) . " / " . count($opsCats) . " categories\n";

            // Per-category: canonical fields + exactly one doc.
            echo "\n[G-per-cat] canonical schema + single doc, each category\n";
            $goodSchema = 0;
            foreach ($fired as $cat => $nid) {
                $found = $findByNotice($nid);
                foreach ($found as $f) { $this->noticeEnv[] = $f['env']; }
                if (count($found) !== 1) { $fail("{$cat}: {" . count($found) . "} notices docs (expected exactly 1)"); continue; }
                $doc = $found[0]['data'];
                $missing = [];
                foreach ($required as $k) { if (!isset($doc[$k]) || $doc[$k] === '' || $doc[$k] === null) { $missing[] = $k; } }
                if (!is_int($doc['timestampMs'] ?? null) || (int) ($doc['timestampMs'] ?? 0) <= 0) { $missing[] = 'timestampMs(int>0)'; }
                if ($missing !== []) { $fail("{$cat}: missing/blank canonical fields: " . implode(',', $missing)); continue; }
                $goodSchema++;
            }
            ($goodSchema === count($fired) && count($fired) === count($opsCats))
                ? $pass("all " . count($fired) . " ops categories wrote a single notices doc with the full canonical schema (timestamp+timestampMs+sentAt+createdAt+…)")
                : $fail("{$goodSchema}/" . count($fired) . " categories had a clean canonical single-doc (fired " . count($fired) . "/" . count($opsCats) . ")");

            // Admin-bell visibility: one ordered query, confirm ALL fired notices present.
            echo "\n[G-bell] every ops notice appears in the timestamp-ordered admin bell\n";
            $bell = (array) $this->fs->schoolWhere('notices', [], 'timestamp', 'DESC', 500);
            $bellIds = [];
            foreach ($bell as $r) {
                $inner = (isset($r['data']) && is_array($r['data'])) ? $r['data'] : $r;
                $bellIds[(string) ($inner['noticeId'] ?? '')] = true;
            }
            $missingFromBell = [];
            foreach ($fired as $cat => $nid) { if (empty($bellIds[$nid])) { $missingFromBell[] = $cat; } }
            ($missingFromBell === [])
                ? $pass("all " . count($fired) . " ops notices returned by the ORDER BY timestamp DESC bell query (admin-bell visible)")
                : $fail("NOT in admin bell: " . implode(', ', $missingFromBell));
        } catch (\Throwable $e) {
            $fail("unexpected exception: " . $e->getMessage());
        } finally {
            echo "\n[TEARDOWN]\n";
            foreach (array_unique(array_filter($this->noticeEnv)) as $env) {
                try { $this->firebase->firestoreDelete('notices', $env); } catch (\Throwable $e) {}
            }
            // Clean the per-role noticeRecipients rows too.
            foreach (array_unique($this->rcptNids) as $nid) {
                try {
                    $rows = (array) $this->fs->schoolWhere('noticeRecipients', [['noticeId', '==', $nid]], null, 'ASC', 20);
                    foreach ($rows as $r) { $env = (string) ($r['id'] ?? ''); if ($env !== '') { try { $this->firebase->firestoreDelete('noticeRecipients', $env); } catch (\Throwable $e) {} } }
                } catch (\Throwable $e) {}
            }
            echo "  cleaned " . count(array_unique(array_filter($this->noticeEnv))) . " notices + recipient rows\n";
        }

        echo "\n==================================================\n";
        echo " RESULT: passed={$passed} failed={$failed}\n";
        echo "==================================================\n";
        exit($failed === 0 ? 0 : 2);
    }
}
