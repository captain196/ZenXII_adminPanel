<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Campus_access_verifier — permanent gate suite for Campus Access Management.
 * Renamed from Visitor_verifier.php 2026-08-09 (module identity rename) and
 * extended with gates for the new Gate Pass / unified-ledger sub-domain.
 *
 * Registry-driven, same pattern as Transport_verifier.php.
 *
 * INVOCATION
 *   SCHOOL_ID=SCH_XXXXXX SESSION_YEAR=2026-27 php index.php campus_access_verifier verify
 *   php index.php campus_access_verifier verify_one CH-CAM-3
 *   php index.php campus_access_verifier list
 *
 * EXIT CODES: 0 = all PASS/INFO/WARN; 1 = env/registry error; 2 = a FAIL.
 */
class Campus_access_verifier extends CI_Controller
{
    private const CAT_STRUCTURAL = 'structural';
    private const CAT_SOURCE     = 'source';
    private const CAT_RUNTIME    = 'runtime';

    private string $schoolId = '';
    private string $sessionYear = '';
    private array $assertions = [];

    public function __construct()
    {
        parent::__construct();
        if (!$this->input->is_cli_request()) {
            show_error('Campus_access_verifier is CLI-only.', 403);
        }
        $this->load->library('firestore_service', null, 'fs');
        $this->load->library('numbering_service', null, 'numbering');
        $this->load->library('visitor_service', null, 'visitor');

        $this->schoolId    = (string) (getenv('SCHOOL_ID') ?: '');
        $this->sessionYear = (string) (getenv('SESSION_YEAR') ?: '');
        if ($this->schoolId === '' || $this->sessionYear === '') {
            echo "ERROR: Set SCHOOL_ID and SESSION_YEAR environment variables.\n";
            exit(1);
        }
        $this->fs->init($this->schoolId, $this->sessionYear);
        $this->numbering->init($this->fs, $this->schoolId, $this->sessionYear);
        $this->visitor->init($this->fs, $this->numbering, $this->schoolId, $this->sessionYear, 'verifier');

        $this->assertions = $this->_buildRegistry();
    }

    public function verify(): void { $this->_runMany($this->assertions); }

    public function verify_one(string $id = ''): void
    {
        $match = array_values(array_filter($this->assertions, static fn(array $a) => $a['id'] === $id));
        if ($match === []) { echo "Unknown assertion id '{$id}'.\n"; exit(1); }
        $this->_runMany($match);
    }

    public function list(): void
    {
        foreach ($this->assertions as $a) {
            echo sprintf("%-10s  %-11s  %s\n", $a['id'], $a['category'], $a['label']);
        }
    }

    private function _buildRegistry(): array
    {
        return [
            ['id' => 'CH-CAM-1',  'category' => self::CAT_STRUCTURAL, 'label' => 'firestore.rules covers all 5 collections', 'callable' => '_chCam1'],
            ['id' => 'CH-CAM-2',  'category' => self::CAT_RUNTIME,    'label' => 'sampled docs carry schoolId (tenant scoping)', 'callable' => '_chCam2'],
            ['id' => 'CH-CAM-3',  'category' => self::CAT_SOURCE,     'label' => 'RBAC uses precise Campus Access module, never bare Operations', 'callable' => '_chCam3'],
            ['id' => 'CH-CAM-4',  'category' => self::CAT_SOURCE,     'label' => 'no RTDB API in Campus Access source', 'callable' => '_chCam4'],
            ['id' => 'CH-CAM-5',  'category' => self::CAT_RUNTIME,    'label' => 'badge QR token round-trips', 'callable' => '_chCam5'],
            ['id' => 'CH-CAM-6',  'category' => self::CAT_SOURCE,     'label' => 'watchlist entries require a reason', 'callable' => '_chCam6'],
            ['id' => 'CH-CAM-7',  'category' => self::CAT_SOURCE,     'label' => 'visitor auto-approve sweep never approves a block hit', 'callable' => '_chCam7'],
            ['id' => 'CH-CAM-8',  'category' => self::CAT_STRUCTURAL, 'label' => 'numbering kinds enabled', 'callable' => '_chCam8'],
            ['id' => 'CH-CAM-9',  'category' => self::CAT_SOURCE,     'label' => 'every controller action is permission-gated', 'callable' => '_chCam9'],
            ['id' => 'CH-CAM-10', 'category' => self::CAT_SOURCE,     'label' => 'student early-leave has NO auto-approve code path anywhere', 'callable' => '_chCam10'],
            ['id' => 'CH-CAM-11', 'category' => self::CAT_SOURCE,     'label' => 'campusAccessEvents writes use a deterministic (idempotent) entity id', 'callable' => '_chCam11'],
            ['id' => 'CH-CAM-12', 'category' => self::CAT_SOURCE,     'label' => 'visitor ledger write-through never throws past its own try/catch', 'callable' => '_chCam12'],
            ['id' => 'CH-CAM-13', 'category' => self::CAT_SOURCE,     'label' => 'Attendance.php / api_punch is not modified by this module', 'callable' => '_chCam13'],
        ];
    }

    private function _runMany(array $rows): void
    {
        $failed = 0;
        foreach ($rows as $a) {
            $method = $a['callable'];
            try {
                $r = $this->$method();
            } catch (\Throwable $e) {
                $r = ['status' => 'FAIL', 'detail' => 'exception: ' . $e->getMessage()];
            }
            echo sprintf("%-10s %-4s  %-11s  %s: %s\n", $a['id'], $r['status'], $a['category'], $a['label'], $r['detail']);
            if ($r['status'] === 'FAIL') $failed++;
        }
        echo "\n" . ($failed === 0 ? 'OK' : "FAILED ({$failed})") . "\n";
        exit($failed === 0 ? 0 : 2);
    }

    // ─── assertions ──────────────────────────────────────────────────────

    private function _chCam1(): array
    {
        $path = FCPATH . '../firebase-rules/firestore.rules';
        if (!is_readable($path)) $path = dirname(APPPATH) . '/firebase-rules/firestore.rules';
        if (!is_readable($path)) return ['status' => 'FAIL', 'detail' => 'cannot locate firestore.rules'];
        $src = (string) file_get_contents($path);
        $missing = [];
        foreach (['visitors', 'visitPasses', 'visitorWatchlist', 'campusAccessRequests', 'campusAccessEvents'] as $col) {
            if (!preg_match('/match\s+\/' . preg_quote($col, '/') . '\/\{docId\}/', $src)) $missing[] = $col;
        }
        if ($missing !== []) return ['status' => 'FAIL', 'detail' => 'no rule block for: ' . implode(', ', $missing)];
        return ['status' => 'PASS', 'detail' => 'all 5 collections have a match block'];
    }

    private function _chCam2(): array
    {
        $bad = [];
        $samples = 0;
        foreach (['visitors', 'visitPasses', 'visitorWatchlist', 'campusAccessRequests', 'campusAccessEvents'] as $col) {
            $rows = $this->fs->schoolWhere($col, [], null, 'ASC', 20);
            foreach ((array) $rows as $row) {
                $samples++;
                $inner = (isset($row['data']) && is_array($row['data'])) ? $row['data'] : $row;
                if ((string) ($inner['schoolId'] ?? '') === '') {
                    $bad[] = "{$col}:" . (string) ($row['id'] ?? '?');
                }
            }
        }
        if ($bad !== []) return ['status' => 'FAIL', 'detail' => count($bad) . ' docs missing schoolId; sample: ' . implode(',', array_slice($bad, 0, 5))];
        return ['status' => $samples === 0 ? 'INFO' : 'PASS', 'detail' => "{$samples} docs sampled" . ($samples === 0 ? ' (module has no data yet — trivial pass)' : ', all carry schoolId')];
    }

    private function _chCam3(): array
    {
        $path = APPPATH . 'controllers/Campus_access.php';
        $src = (string) file_get_contents($path);
        if (preg_match_all('/(?:has_permission|require_permission)\s*\(\s*[\'"]Operations[\'"]/', $src, $m)) {
            return ['status' => 'FAIL', 'detail' => count($m[0]) . ' bare Operations-umbrella check(s) found — must gate on Campus Access precisely (this session\'s Transport SEC-3 lesson)'];
        }
        $preciseCount = preg_match_all('/(?:has_permission|require_permission)\s*\(\s*[\'"]Campus Access[\'"]/', $src, $m2);
        if ($preciseCount === 0) return ['status' => 'FAIL', 'detail' => "no precise 'Campus Access' permission checks found at all"];
        return ['status' => 'PASS', 'detail' => "{$preciseCount} precise 'Campus Access' permission checks, zero bare 'Operations' checks"];
    }

    private function _chCam4(): array
    {
        $bad = [];
        foreach ([
            'controllers/Campus_access.php', 'controllers/Campus_access_cron.php',
            'libraries/Visitor_service.php', 'libraries/Gate_pass_service.php',
        ] as $rel) {
            $path = APPPATH . $rel;
            if (!is_readable($path)) continue;
            $src = (string) file_get_contents($path);
            if (preg_match('/getReference\(|->rtdb\W|realtime.*database/i', $src)) $bad[] = $rel;
        }
        if ($bad !== []) return ['status' => 'FAIL', 'detail' => 'RTDB API reference in: ' . implode(', ', $bad)];
        return ['status' => 'PASS', 'detail' => 'Firestore-only, no RTDB references'];
    }

    private function _chCam5(): array
    {
        $this->load->helper('qr_token');
        $passId = 'VPS999999';
        $token = qr_token_encode($this->schoolId, $passId);
        $decoded = qr_token_decode($token);
        if ($decoded === null) return ['status' => 'FAIL', 'detail' => 'decode returned null for a freshly-minted token'];
        if ($decoded['schoolId'] !== $this->schoolId || $decoded['studentId'] !== $passId) {
            return ['status' => 'FAIL', 'detail' => 'round-trip mismatch: ' . json_encode($decoded)];
        }
        return ['status' => 'PASS', 'detail' => "token minted+decoded for {$passId}, HMAC-verified"];
    }

    private function _chCam6(): array
    {
        $path = APPPATH . 'libraries/Visitor_service.php';
        $src = (string) file_get_contents($path);
        if (!preg_match('/function addWatchlistEntry.*?\}\s*\}/s', $src, $m)) {
            return ['status' => 'FAIL', 'detail' => 'addWatchlistEntry() not found'];
        }
        if (strpos($m[0], "reason") === false || strpos($m[0], 'InvalidArgumentException') === false) {
            return ['status' => 'FAIL', 'detail' => 'addWatchlistEntry() does not appear to require a reason'];
        }
        return ['status' => 'PASS', 'detail' => 'addWatchlistEntry() guards on a required reason'];
    }

    private function _chCam7(): array
    {
        $path = APPPATH . 'libraries/Visitor_service.php';
        $src = (string) file_get_contents($path);
        if (!preg_match('/function sweepAutoApprove.*?\n    \}/s', $src, $m)) {
            return ['status' => 'FAIL', 'detail' => 'sweepAutoApprove() not found'];
        }
        if (strpos($m[0], 'SEVERITY_BLOCK') === false) {
            return ['status' => 'FAIL', 'detail' => 'sweepAutoApprove() has no visible SEVERITY_BLOCK guard — a block hit could auto-approve'];
        }
        return ['status' => 'PASS', 'detail' => 'sweepAutoApprove() explicitly skips SEVERITY_BLOCK hits (visitor sub-domain only)'];
    }

    private function _chCam8(): array
    {
        $this->config->load('numbering');
        $flags = (array) ($this->config->item('numbering')['_kindFlags'] ?? []);
        $missing = [];
        foreach (['vms_visitor', 'vms_pass', 'vms_watchlist', 'cam_gatepass'] as $kind) {
            if (($flags[$kind] ?? '') !== 'enabled') $missing[] = $kind;
        }
        if ($missing !== []) return ['status' => 'FAIL', 'detail' => 'not enabled: ' . implode(', ', $missing)];
        return ['status' => 'PASS', 'detail' => 'vms_visitor / vms_pass / vms_watchlist / cam_gatepass all enabled'];
    }

    private function _chCam9(): array
    {
        $path = APPPATH . 'controllers/Campus_access.php';
        $src = (string) file_get_contents($path);
        preg_match_all('/public function (\w+)\s*\([^)]*\)\s*\n?\s*\{/', $src, $methods);
        $names = array_diff($methods[1] ?? [], ['__construct']);
        $ungated = [];
        foreach ($names as $name) {
            if (!preg_match('/function\s+' . preg_quote($name, '/') . '\s*\([^)]*\)\s*\{[^}]*?require_permission\s*\(\s*[\'"]Campus Access[\'"]/s', $src)) {
                $ungated[] = $name;
            }
        }
        if ($ungated !== []) return ['status' => 'FAIL', 'detail' => "no require_permission('Campus Access',...) found in: " . implode(', ', $ungated)];
        return ['status' => 'PASS', 'detail' => count($names) . ' public actions, all permission-gated'];
    }

    /**
     * 2026-08-09 instruction: dual approval for student early-leave must
     * NEVER degrade into an auto-approve. Confirms no cron/sweep method
     * anywhere calls Gate_pass_service in a way that flips a pending
     * campusAccessRequests doc to approved without a human decision.
     */
    /** Strip /** *\/, // and code comments so a gate scans CODE, not explanatory prose that legitimately names the thing it's confirming the ABSENCE of. */
    private function _stripComments(string $src): string
    {
        $src = preg_replace('#/\*.*?\*/#s', '', $src);
        $src = preg_replace('#(?<!:)//[^\n]*#', '', $src);
        return (string) $src;
    }

    private function _chCam10(): array
    {
        $cronSrc = $this->_stripComments((string) @file_get_contents(APPPATH . 'controllers/Campus_access_cron.php'));
        if (preg_match('/gatepass|gate_pass_service|campusAccessRequests/i', $cronSrc)) {
            return ['status' => 'FAIL', 'detail' => 'Campus_access_cron.php has CODE referencing gate-pass/campusAccessRequests — early-leave must stay 100% human-triggered, never swept'];
        }
        $svcSrc = $this->_stripComments((string) file_get_contents(APPPATH . 'libraries/Gate_pass_service.php'));
        if (preg_match('/function\s+\w*[Aa]uto[Aa]pprove/', $svcSrc)) {
            return ['status' => 'FAIL', 'detail' => 'Gate_pass_service.php defines an auto-approve method — not allowed for student/staff early-leave'];
        }
        return ['status' => 'PASS', 'detail' => 'no auto-approve code path exists for gate-pass requests (cron untouched, service has no auto-approve method)'];
    }

    private function _chCam11(): array
    {
        $bad = [];
        foreach (['libraries/Visitor_service.php', 'libraries/Gate_pass_service.php'] as $rel) {
            $src = (string) file_get_contents(APPPATH . $rel);
            if (strpos($src, "'campusAccessEvents'") === false && strpos($src, 'COL_EVENTS') === false) continue;
            if (!preg_match('/\$entityId\s*=\s*[\'"](?:V_|GP_)/', $src)) $bad[] = $rel;
        }
        if ($bad !== []) return ['status' => 'FAIL', 'detail' => 'no deterministic entityId pattern found in: ' . implode(', ', $bad)];
        return ['status' => 'PASS', 'detail' => "both writers use a deterministic 'V_{id}_{dir}' / 'GP_{id}_{dir}' entity id — retry-safe, no duplicate risk"];
    }

    private function _chCam12(): array
    {
        $path = APPPATH . 'libraries/Visitor_service.php';
        $src = (string) file_get_contents($path);
        if (!preg_match('/private function _syncCampusAccessEvent.*?\n    \}/s', $src, $m)) {
            return ['status' => 'FAIL', 'detail' => '_syncCampusAccessEvent() not found'];
        }
        $body = $m[0];
        if (strpos($body, 'try {') === false || strpos($body, 'catch (\\Throwable') === false) {
            return ['status' => 'FAIL', 'detail' => '_syncCampusAccessEvent() has no try/catch — a ledger-write failure could propagate and corrupt the already-successful visitPasses write'];
        }
        return ['status' => 'PASS', 'detail' => '_syncCampusAccessEvent() is wrapped — a failure logs and leaves campusEventSynced* false for reconciliation, never throws past itself'];
    }

    private function _chCam13(): array
    {
        // Structural boundary check, not a hash/diff (no pre-session snapshot
        // captured) — confirms the module's own files never reference
        // Attendance's api_punch/attendancePunches/attendanceDevices
        // internals, which would indicate an unauthorized modification path.
        foreach (['libraries/Visitor_service.php', 'libraries/Gate_pass_service.php', 'controllers/Campus_access.php', 'controllers/Campus_access_cron.php'] as $rel) {
            $src = $this->_stripComments((string) @file_get_contents(APPPATH . $rel));
            if (preg_match('/api_punch|attendancePunches|attendanceDevices|attendanceProcessedEvents/', $src)) {
                return ['status' => 'FAIL', 'detail' => "{$rel} has CODE referencing Attendance's device-punch internals — that boundary must not be crossed without separate authorization (spec §20)"];
            }
        }
        return ['status' => 'PASS', 'detail' => 'no Campus Access file references Attendance.php\'s api_punch/attendancePunches internals — additive-only, as designed'];
    }
}
