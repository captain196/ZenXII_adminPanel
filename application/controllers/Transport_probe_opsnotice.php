<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Transport_probe_opsnotice — ops notice appears in the Admin notice-bell (B3).
 *
 * Transport ops notices (_fanoutOps → `notices`) omitted the canonical
 * `timestamp` field, so the Admin Panel bell (NoticeAnnouncement::getRecentNotices,
 * ORDER BY timestamp DESC via a server-side composite index) dropped them.
 * Fix emits the canonical `timestamp` + `timestampMs` (matching create_notice)
 * without touching the reader/index or the app-facing `sentAt`.
 *
 *   G1 the written notices doc carries canonical timestamp + timestampMs + sentAt
 *   G2 an admin-bell-style query (schoolWhere notices ORDER BY timestamp DESC)
 *      returns the transport ops notice (was excluded server-side pre-fix)
 *   G3 exactly ONE notices doc per fire (no duplicate notice)
 *
 * Self-teardown. CLI-only.
 *   SCHOOL_ID=SCH_D94FE8F7AD SESSION_YEAR=2026-27 \
 *     php index.php transport_probe_opsnotice run
 */
class Transport_probe_opsnotice extends CI_Controller
{
    private const COL = 'notices';
    private array $rawFixtures = [];

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
            $this->transport->init($this->fs, $this->numbering, $sid, $sess, 'probe_opsnotice');
            $this->notifier->init($this->fs, $this->numbering, $this->transport, $sid, $sess, 'probe_opsnotice');
        } catch (\Throwable $e) { fwrite(STDERR, "ERROR: init — " . $e->getMessage() . "\n"); exit(1); }

        $passed = 0; $failed = 0;
        $pass = function (string $m) use (&$passed) { echo "  PASS: {$m}\n"; $passed++; };
        $fail = function (string $m) use (&$failed) { echo "  FAIL: {$m}\n"; $failed++; };

        $ts  = (int) (microtime(true) * 1000);
        $rid = "PROBE_OPSN_RT_{$ts}";   // unique → no idempotency collision across runs

        echo "==================================================\n";
        echo " B3 transport ops-notice admin-bell visibility probe\n";
        echo " tenant={$sid}\n";
        echo "==================================================\n";

        $findByNotice = function (string $nid) use ($sid): array {
            $rows = (array) $this->fs->schoolWhere(self::COL, [['noticeId', '==', $nid]], null, 'ASC', 10);
            $out = [];
            foreach ($rows as $r) {
                $inner = (isset($r['data']) && is_array($r['data'])) ? $r['data'] : $r;
                if ((string) ($inner['noticeId'] ?? '') === $nid) {
                    $out[] = ['env' => (string) ($r['id'] ?? ''), 'data' => $inner];
                    $this->rawFixtures[] = (string) ($r['id'] ?? '');
                }
            }
            return $out;
        };

        try {
            // Fire a transport OPS-scope notification (route.suspended → _fanoutOps).
            $res = (array) $this->notifier->notify('route.suspended', [
                'route_id'   => $rid,
                'route_name' => 'Probe Ops Route',
                'reason'     => 'probe',
            ]);
            $nid = (string) ($res['notice_id'] ?? '');
            if ($nid === '') { throw new \RuntimeException('notify returned no notice_id (per_user_count=' . ($res['per_user_count'] ?? '?') . ')'); }

            echo "\n[G1] notices doc carries canonical timestamp + timestampMs + sentAt\n";
            $found = $findByNotice($nid);
            $doc = $found[0]['data'] ?? [];
            $hasTs   = isset($doc['timestamp']) && (string) $doc['timestamp'] !== '';
            $hasTsMs = isset($doc['timestampMs']) && is_int($doc['timestampMs']) && $doc['timestampMs'] > 0;
            $hasSent = isset($doc['sentAt']) && (string) $doc['sentAt'] !== '';
            ($hasTs && $hasTsMs && $hasSent)
                ? $pass("canonical fields present: timestamp={$doc['timestamp']}, timestampMs={$doc['timestampMs']}, sentAt={$doc['sentAt']}")
                : $fail("missing canonical fields: ts=" . var_export($hasTs, true) . " tsMs=" . var_export($hasTsMs, true) . " sentAt=" . var_export($hasSent, true));

            echo "\n[G2] admin-bell-style query (ORDER BY timestamp DESC) returns the ops notice\n";
            $bell = (array) $this->fs->schoolWhere(self::COL, [], 'timestamp', 'DESC', 200);
            $seen = false;
            foreach ($bell as $r) {
                $inner = (isset($r['data']) && is_array($r['data'])) ? $r['data'] : $r;
                if ((string) ($inner['noticeId'] ?? '') === $nid) { $seen = true; break; }
            }
            $seen
                ? $pass("transport ops notice appears in the timestamp-ordered bell query (admin-bell visible)")
                : $fail("transport ops notice NOT returned by the timestamp-ordered query (still dropped)");

            echo "\n[G3] exactly ONE notices doc per fire (no duplicate)\n";
            (count($found) === 1)
                ? $pass("single notices doc for this fire (no duplicate notice introduced)")
                : $fail("found " . count($found) . " notices docs for one fire");
        } catch (\Throwable $e) {
            $fail("unexpected exception: " . $e->getMessage());
        } finally {
            echo "\n[TEARDOWN]\n";
            foreach (array_unique(array_filter($this->rawFixtures)) as $env) {
                try { $this->firebase->firestoreDelete(self::COL, $env); } catch (\Throwable $e) {}
            }
            echo "  cleaned " . count(array_unique(array_filter($this->rawFixtures))) . " notice docs\n";
        }

        echo "\n==================================================\n";
        echo " RESULT: passed={$passed} failed={$failed}\n";
        echo "==================================================\n";
        exit($failed === 0 ? 0 : 2);
    }
}
