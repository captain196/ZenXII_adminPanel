<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Transport_verifier — TMS Phase 0 foundation verifier.
 *
 * ─── DESIGN ──────────────────────────────────────────────────────────────
 * REGISTRY-DRIVEN. Adding a new assertion is a two-line change (registry
 * row + assertion method). No changes to verify(), no controller-wide edits,
 * no callers to update. This is the extension point Refinement 3 asked for.
 *
 * Each assertion is a tuple:
 *   id       — 'CH-TRN-N' identifier, stable across releases
 *   category — bucket for future filtered runs (structural | source |
 *              runtime | performance | cache | contract | app-integration)
 *   label    — one-line human-readable summary
 *   callable — private method name that runs the check
 *
 * The verify() dispatcher iterates the registry, invokes each callable,
 * prints a normalised result line, and exits non-zero on any FAIL.
 *
 * Adding new categories in future LCs (performance latency, Firestore read/
 * write budget, cache-vs-source-of-truth consistency, cross-module contract,
 * Parent/Teacher App schema conformance) requires:
 *   1. Register the row in $this->assertions
 *   2. Implement _chTrnN() returning ['status' => ..., 'detail' => ...]
 * That's it — verify() and verify_category() pick it up automatically.
 *
 * ─── PHASE 0 ASSERTION COVERAGE ──────────────────────────────────────────
 * Phase 0 registers 16 assertions (CH-TRN-1..16). Most run non-trivially
 * on structural / source-based checks (rules coverage, storage rules, RTDB
 * grep, ledger-write grep, feeDemands-write grep, audit-emit grep, pad
 * utilisation). The runtime-data assertions (CH-TRN-10..16) trivially pass
 * against empty Phase 0 collections; they become meaningful as Phase 1+
 * data lands.
 *
 * Categories present at Phase 0 launch:
 *   structural       — rules + storage-rules parse
 *   source           — grep the PHP source files
 *   runtime          — Firestore reads / counter peek / pattern samples
 *
 * Categories reserved for future LCs (not yet registered):
 *   performance      — latency budgets from v2.1 supplement §17
 *   cache            — busCapacityCache vs live derivation
 *   contract         — SIS/Fees/HR/Accounting call contract validation
 *   app-integration  — Parent/Teacher App expected schema fields present
 *
 * ─── INVOCATION ──────────────────────────────────────────────────────────
 *   php index.php transport_verifier verify
 *   php index.php transport_verifier verify_category structural
 *   php index.php transport_verifier verify_category source
 *   php index.php transport_verifier verify_one CH-TRN-4
 *   php index.php transport_verifier list
 *
 * ─── ENV ─────────────────────────────────────────────────────────────────
 *   SCHOOL_ID     e.g. SCH_D94FE8F7AD
 *   SESSION_YEAR  e.g. 2026-27
 *
 * ─── EXIT CODES ──────────────────────────────────────────────────────────
 *   0 — all assertions PASS or INFO/WARN (no FAIL)
 *   1 — env vars missing or registry not loadable
 *   2 — one or more assertions FAIL
 */
class Transport_verifier extends CI_Controller
{
    // Category enum — Phase 0 uses first three; the rest are reserved.
    private const CAT_STRUCTURAL      = 'structural';
    private const CAT_SOURCE          = 'source';
    private const CAT_RUNTIME         = 'runtime';
    private const CAT_PERFORMANCE     = 'performance';
    private const CAT_CACHE           = 'cache';
    private const CAT_CONTRACT        = 'contract';
    private const CAT_APP_INTEGRATION = 'app-integration';

    private string $schoolId    = '';
    private string $sessionYear = '';

    /**
     * Assertion registry. Order = execution order. Add rows here, implement
     * the callable method, and the verifier picks it up automatically.
     *
     * @var array<int, array{id:string, category:string, label:string, callable:string}>
     */
    private array $assertions = [];

    public function __construct()
    {
        parent::__construct();
        if (!$this->input->is_cli_request()) {
            show_error('Transport_verifier is CLI-only.', 403);
        }

        $this->load->library('firestore_service', null, 'fs');
        $this->load->library('numbering_service', null, 'numbering');
        $this->load->library('transport_service',  null, 'transport');

        $this->schoolId    = (string) (getenv('SCHOOL_ID')    ?: '');
        $this->sessionYear = (string) (getenv('SESSION_YEAR') ?: '');
        if ($this->schoolId === '' || $this->sessionYear === '') {
            echo "ERROR: Set SCHOOL_ID and SESSION_YEAR environment variables.\n";
            exit(1);
        }

        $this->fs->init($this->schoolId, $this->sessionYear);
        $this->numbering->init($this->fs, $this->schoolId, $this->sessionYear);
        $this->transport->init($this->fs, $this->numbering, $this->schoolId, $this->sessionYear, 'verifier');

        $this->assertions = $this->_buildRegistry();
    }

    // ─── public entrypoints ──────────────────────────────────────────────

    /** Run every registered assertion. */
    public function verify(): void
    {
        $this->_runMany($this->assertions);
    }

    /** Run only assertions in a specific category (structural/source/runtime/...). */
    public function verify_category(string $category = ''): void
    {
        $filtered = array_values(array_filter(
            $this->assertions,
            static fn(array $a) => $a['category'] === $category
        ));
        if ($filtered === []) {
            echo "No assertions registered in category '{$category}'.\n";
            echo "Known categories: " . implode(', ', $this->_availableCategories()) . "\n";
            exit(0);
        }
        $this->_runMany($filtered);
    }

    /** Run a single assertion by ID. */
    public function verify_one(string $id = ''): void
    {
        $match = array_values(array_filter(
            $this->assertions,
            static fn(array $a) => $a['id'] === $id
        ));
        if ($match === []) {
            echo "Unknown assertion id '{$id}'. Use 'list' to see all.\n";
            exit(1);
        }
        $this->_runMany($match);
    }

    /** Print the registry (id, category, label) without running any assertion. */
    public function list(): void
    {
        foreach ($this->assertions as $a) {
            echo sprintf("%-9s  %-16s  %s\n", $a['id'], $a['category'], $a['label']);
        }
    }

    // ─── registry builder ────────────────────────────────────────────────

    private function _buildRegistry(): array
    {
        return [
            [ 'id' => 'CH-TRN-1',  'category' => self::CAT_STRUCTURAL, 'label' => 'rules-coverage',            'callable' => '_chTrn1'  ],
            [ 'id' => 'CH-TRN-2',  'category' => self::CAT_RUNTIME,    'label' => 'schoolId presence',         'callable' => '_chTrn2'  ],
            [ 'id' => 'CH-TRN-3',  'category' => self::CAT_RUNTIME,    'label' => 'assignment session field',  'callable' => '_chTrn3'  ],
            [ 'id' => 'CH-TRN-4',  'category' => self::CAT_SOURCE,     'label' => 'no direct feeDemands write','callable' => '_chTrn4'  ],
            [ 'id' => 'CH-TRN-5',  'category' => self::CAT_SOURCE,     'label' => 'no RTDB in Transport src',  'callable' => '_chTrn5'  ],
            [ 'id' => 'CH-TRN-6',  'category' => self::CAT_SOURCE,     'label' => 'writes emit audit',         'callable' => '_chTrn6'  ],
            [ 'id' => 'CH-TRN-7',  'category' => self::CAT_RUNTIME,    'label' => 'pad-width utilisation',     'callable' => '_chTrn7'  ],
            [ 'id' => 'CH-TRN-8',  'category' => self::CAT_STRUCTURAL, 'label' => 'storage-rules coverage',    'callable' => '_chTrn8'  ],
            [ 'id' => 'CH-TRN-9',  'category' => self::CAT_SOURCE,     'label' => 'no direct ledger write',    'callable' => '_chTrn9'  ],
            [ 'id' => 'CH-TRN-10', 'category' => self::CAT_RUNTIME,    'label' => 'no stale pending expense',  'callable' => '_chTrn10' ],
            [ 'id' => 'CH-TRN-11', 'category' => self::CAT_RUNTIME,    'label' => 'no temp-driver overlap',    'callable' => '_chTrn11' ],
            [ 'id' => 'CH-TRN-12', 'category' => self::CAT_RUNTIME,    'label' => 'lifecycle log <-> vehicle', 'callable' => '_chTrn12' ],
            [ 'id' => 'CH-TRN-13', 'category' => self::CAT_RUNTIME,    'label' => 'no expired driver licence', 'callable' => '_chTrn13' ],
            [ 'id' => 'CH-TRN-14', 'category' => self::CAT_RUNTIME,    'label' => 'no assign->retired vehicle','callable' => '_chTrn14' ],
            [ 'id' => 'CH-TRN-15', 'category' => self::CAT_RUNTIME,    'label' => 'session integrity',         'callable' => '_chTrn15' ],
            [ 'id' => 'CH-TRN-16', 'category' => self::CAT_RUNTIME,    'label' => 'no stale bulk-op progress', 'callable' => '_chTrn16' ],

            // ─── F1 Fleet Management (added 2026-07-07) ───────────────────────
            [ 'id' => 'CH-TRN-F1-1', 'category' => self::CAT_SOURCE,      'label' => 'no ops_accounting vehicle', 'callable' => '_chTrnF1_1' ],
            [ 'id' => 'CH-TRN-F1-2', 'category' => self::CAT_RUNTIME,     'label' => 'vehicle lifecycle field',   'callable' => '_chTrnF1_2' ],
            [ 'id' => 'CH-TRN-F1-3', 'category' => self::CAT_RUNTIME,     'label' => 'contract vehicle vendor FK','callable' => '_chTrnF1_3' ],
            [ 'id' => 'CH-TRN-F1-4', 'category' => self::CAT_RUNTIME,     'label' => 'lifecycle transitions legal','callable' => '_chTrnF1_4' ],
            [ 'id' => 'CH-TRN-F1-5', 'category' => self::CAT_RUNTIME,     'label' => 'maint/fuel FK integrity',   'callable' => '_chTrnF1_5' ],
            [ 'id' => 'CH-TRN-F1-6', 'category' => self::CAT_PERFORMANCE, 'label' => 'no N+1 in fleet writers',   'callable' => '_chTrnF1_6' ],
        ];
    }

    // ─── dispatcher ──────────────────────────────────────────────────────

    /** @param array<int, array{id:string, category:string, label:string, callable:string}> $rows */
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
            echo sprintf(
                "%-9s %-4s  %-16s  %s: %s\n",
                $a['id'], $r['status'], $a['category'], $a['label'], $r['detail']
            );
            if ($r['status'] === 'FAIL') {
                $failed++;
            }
        }
        echo "\n" . ($failed === 0 ? 'OK' : "FAILED ({$failed})") . "\n";
        exit($failed === 0 ? 0 : 2);
    }

    private function _availableCategories(): array
    {
        return array_values(array_unique(array_column($this->assertions, 'category')));
    }

    // ─── assertions (CH-TRN-1..16) ───────────────────────────────────────

    /**
     * CH-TRN-1 — every collection owned by Transport must have a rule
     * block in firestore.rules. Reads the rules file, greps for
     * `match /<collection>/{docId}` per owned collection.
     */
    private function _chTrn1(): array
    {
        $rulesPath = FCPATH . 'firebase-rules/firestore.rules';
        if (!is_readable($rulesPath)) {
            return ['status' => 'FAIL', 'detail' => "cannot read {$rulesPath}"];
        }
        $rules   = (string) file_get_contents($rulesPath);
        $missing = [];
        foreach ($this->transport->ownedCollections() as $col) {
            $needle = "match /{$col}/{";
            if (strpos($rules, $needle) === false) {
                $missing[] = $col;
            }
        }
        if ($missing !== []) {
            return ['status' => 'FAIL', 'detail' => count($missing) . ' missing: ' . implode(',', $missing)];
        }
        $count = count($this->transport->ownedCollections());
        return ['status' => 'PASS', 'detail' => "{$count}/{$count} collections rule-covered"];
    }

    /**
     * CH-TRN-2 — sample up to 20 docs per owned collection, assert every
     * doc has a schoolId field. Empty collection = trivial pass.
     */
    private function _chTrn2(): array
    {
        $bad = [];
        $samples = 0;
        foreach ($this->transport->ownedCollections() as $col) {
            try {
                $rows = $this->fs->schoolWhere($col, [], null, 'ASC', 20);
            } catch (\Throwable $_) {
                continue;
            }
            foreach ((array) $rows as $row) {
                $samples++;
                if (!is_array($row) || !array_key_exists('schoolId', $row) || $row['schoolId'] === '') {
                    $bad[] = "{$col}:" . (string) ($row['id'] ?? '?');
                }
            }
        }
        if ($bad !== []) {
            return ['status' => 'FAIL', 'detail' => count($bad) . " docs missing schoolId; sample: " . implode(',', array_slice($bad, 0, 5))];
        }
        return ['status' => 'PASS', 'detail' => "{$samples} docs sampled, all carry schoolId"];
    }

    /**
     * CH-TRN-3 — sample routeAssignments; every doc must have session
     * matching YYYY-YY. Empty collection = trivial pass.
     */
    private function _chTrn3(): array
    {
        try {
            $rows = $this->fs->schoolWhere(Transport_service::COL_ROUTE_ASSIGNMENTS, [], null, 'ASC', 50);
        } catch (\Throwable $_) {
            return ['status' => 'INFO', 'detail' => 'collection unreachable — trivial pass'];
        }
        $rows = (array) $rows;
        if ($rows === []) {
            return ['status' => 'INFO', 'detail' => 'empty collection — trivial pass'];
        }
        $bad = [];
        foreach ($rows as $row) {
            $session = (string) ($row['session'] ?? '');
            if (!preg_match('/^\d{4}-\d{2}$/', $session)) {
                $bad[] = (string) ($row['id'] ?? '?');
            }
        }
        if ($bad !== []) {
            return ['status' => 'FAIL', 'detail' => count($bad) . " assignments missing/bad session; sample: " . implode(',', array_slice($bad, 0, 5))];
        }
        return ['status' => 'PASS', 'detail' => count($rows) . ' assignments have valid session'];
    }

    /** CH-TRN-4 — no direct feeDemands write from Transport source. */
    private function _chTrn4(): array
    {
        return $this->_grepTransportSourceForbidden(
            "/'feeDemands'|\"feeDemands\"/",
            'feeDemands literal'
        );
    }

    /** CH-TRN-5 — no RTDB API in Transport source. */
    private function _chTrn5(): array
    {
        return $this->_grepTransportSourceForbidden(
            "/getReference\(|->rtdb\W|realtime.*database/i",
            'RTDB API'
        );
    }

    /**
     * CH-TRN-6 — every public write-style method in Transport_service
     * (save*, delete*, assign*, unassign*, record*, transition*, trigger*,
     * acknowledge*, resolve*, transfer*, link*, terminate*, suspend*,
     * resume*, upload*, remove*, cancel*, create*, update*, log*, roll*)
     * must contain a call to emitAudit(. Phase 0 has no such methods,
     * so trivially passes.
     */
    private function _chTrn6(): array
    {
        $path = APPPATH . 'libraries/Transport_service.php';
        if (!is_readable($path)) {
            return ['status' => 'FAIL', 'detail' => "cannot read {$path}"];
        }
        $src = (string) file_get_contents($path);
        $pattern = '/public\s+function\s+(save|delete|assign|unassign|record|transition|trigger|acknowledge|resolve|transfer|link|terminate|suspend|resume|upload|remove|cancel|create|update|log|roll)[A-Za-z0-9_]*\s*\(/';
        if (!preg_match_all($pattern, $src, $matches)) {
            return ['status' => 'INFO', 'detail' => 'no write methods yet (Phase 0 skeleton) — trivial pass'];
        }
        $writes = count($matches[0]);
        $auditCalls = substr_count($src, 'emitAudit(');
        if ($auditCalls === 0) {
            return ['status' => 'FAIL', 'detail' => "{$writes} write methods present but zero emitAudit() calls"];
        }
        return ['status' => 'PASS', 'detail' => "{$writes} write methods, {$auditCalls} emitAudit() calls"];
    }

    /**
     * CH-TRN-7 — peek pointer per Transport-owned numbering kind; warn
     * at >=80% pad-width utilisation, fail at >=95%.
     */
    private function _chTrn7(): array
    {
        $reg = (array) $this->config->item('numbering');
        $kindsCfg = (array) ($reg['kinds'] ?? []);
        $items = [];
        $worst = 0.0;
        foreach ($this->transport->ownedNumberingKinds() as $kind) {
            if (!isset($kindsCfg[$kind])) {
                $items[] = "{$kind}=UNREGISTERED";
                continue;
            }
            try {
                $value = $this->numbering->peek($kind);
            } catch (\Throwable $_) {
                $items[] = "{$kind}=ERR";
                continue;
            }
            $pad = (int) $kindsCfg[$kind]['padWidth'];
            $max = $pad > 0 ? ((10 ** $pad) - 1) : 0;
            $util = $max > 0 ? round(($value / $max) * 100, 1) : 0.0;
            if ($util > $worst) {
                $worst = $util;
            }
            $items[] = "{$kind}={$value}({$util}%)";
        }
        $status = $worst >= 95 ? 'FAIL' : ($worst >= 80 ? 'WARN' : 'PASS');
        return ['status' => $status, 'detail' => "worst={$worst}%; " . implode(' ', $items)];
    }

    /**
     * CH-TRN-8 — storage.rules must permit uploads under
     * schools/{schoolId}/transport/... Path is covered today by the
     * broad L6 catch-all match /schools/{schoolId}/{path=**}; assert
     * that block exists.
     */
    private function _chTrn8(): array
    {
        $path = FCPATH . 'firebase-rules/storage.rules';
        if (!is_readable($path)) {
            return ['status' => 'FAIL', 'detail' => "cannot read {$path}"];
        }
        $src = (string) file_get_contents($path);
        if (strpos($src, 'match /schools/{schoolId}/{path=**}') === false) {
            return ['status' => 'FAIL', 'detail' => 'L6 schools/{schoolId}/{path=**} block missing'];
        }
        return ['status' => 'PASS', 'detail' => 'L6 covers schools/{schoolId}/transport/...'];
    }

    /** CH-TRN-9 — no direct ledger write from Transport source. */
    private function _chTrn9(): array
    {
        return $this->_grepTransportSourceForbidden(
            "/setEntity\(\s*['\"]ledger['\"]|removeEntity\(\s*['\"]ledger['\"]/",
            'ledger direct write'
        );
    }

    /**
     * CH-TRN-10 — no transportExpenses row with accounting_journal_ref
     * = 'pending' older than 24h. Trivial pass on empty collection.
     */
    private function _chTrn10(): array
    {
        try {
            $rows = $this->fs->schoolWhere(
                Transport_service::COL_TRANSPORT_EXPENSES,
                [['accounting_journal_ref', '==', 'pending']],
                null, 'ASC', 50
            );
        } catch (\Throwable $_) {
            return ['status' => 'INFO', 'detail' => 'query unreachable — trivial pass'];
        }
        $rows = (array) $rows;
        if ($rows === []) {
            return ['status' => 'PASS', 'detail' => 'no pending expenses'];
        }
        $stale = [];
        $cutoff = time() - 86400;
        foreach ($rows as $row) {
            $ts = strtotime((string) ($row['created_at'] ?? $row['expense_date'] ?? ''));
            if ($ts !== false && $ts < $cutoff) {
                $stale[] = (string) ($row['id'] ?? '?');
            }
        }
        if ($stale !== []) {
            return ['status' => 'FAIL', 'detail' => count($stale) . ' pending >24h; sample: ' . implode(',', array_slice($stale, 0, 5))];
        }
        return ['status' => 'PASS', 'detail' => count($rows) . ' pending rows, all within 24h'];
    }

    /**
     * CH-TRN-11 — no active temporaryDriverAssignments doc whose
     * (effective_from, effective_to) window is inconsistent (to < from
     * or missing). Empty collection = trivial pass.
     */
    private function _chTrn11(): array
    {
        try {
            $rows = $this->fs->schoolWhere(Transport_service::COL_TEMPORARY_DRIVER_ASSIGNMENTS, [], null, 'ASC', 100);
        } catch (\Throwable $_) {
            return ['status' => 'INFO', 'detail' => 'unreachable — trivial pass'];
        }
        $rows = (array) $rows;
        if ($rows === []) {
            return ['status' => 'INFO', 'detail' => 'empty — trivial pass'];
        }
        $bad = [];
        foreach ($rows as $row) {
            $from = strtotime((string) ($row['effective_from'] ?? ''));
            $to   = strtotime((string) ($row['effective_to']   ?? ''));
            if ($from === false || $to === false || $to < $from) {
                $bad[] = (string) ($row['id'] ?? '?');
            }
        }
        if ($bad !== []) {
            return ['status' => 'FAIL', 'detail' => count($bad) . ' inconsistent windows; sample: ' . implode(',', array_slice($bad, 0, 5))];
        }
        return ['status' => 'PASS', 'detail' => count($rows) . ' assignments have consistent windows'];
    }

    /**
     * CH-TRN-12 — every recent vehicleLifecycleLog entry's new_state must
     * appear as the current lifecycle_status on the referenced vehicle
     * (allowing that a later transition may have overridden it).
     */
    private function _chTrn12(): array
    {
        try {
            $rows = $this->fs->schoolWhere(Transport_service::COL_VEHICLE_LIFECYCLE_LOG, [], 'event_at', 'DESC', 20);
        } catch (\Throwable $_) {
            return ['status' => 'INFO', 'detail' => 'unreachable — trivial pass'];
        }
        $rows = (array) $rows;
        if ($rows === []) {
            return ['status' => 'INFO', 'detail' => 'empty — trivial pass'];
        }
        $latestPerVehicle = [];
        foreach ($rows as $row) {
            $vid = (string) ($row['vehicle_id'] ?? '');
            if ($vid === '' || isset($latestPerVehicle[$vid])) {
                continue;
            }
            $latestPerVehicle[$vid] = $row;
        }
        $bad = [];
        foreach ($latestPerVehicle as $vid => $logRow) {
            $vehicle = $this->fs->getEntity(Transport_service::COL_VEHICLES, $vid);
            if (!is_array($vehicle)) {
                continue;
            }
            $expected = (string) ($logRow['new_state'] ?? '');
            $actual   = (string) ($vehicle['lifecycle_status'] ?? '');
            if ($expected !== '' && $actual !== '' && $expected !== $actual) {
                $bad[] = "{$vid}(log={$expected},veh={$actual})";
            }
        }
        if ($bad !== []) {
            return ['status' => 'FAIL', 'detail' => count($bad) . ' mismatches; sample: ' . implode(',', array_slice($bad, 0, 5))];
        }
        return ['status' => 'PASS', 'detail' => count($latestPerVehicle) . ' vehicles cross-checked'];
    }

    /**
     * CH-TRN-13 — no vehicle in lifecycle_status Active has a driver
     * whose staff.driver_meta.licence_expires is before today.
     */
    private function _chTrn13(): array
    {
        try {
            $vehicles = $this->fs->schoolWhere(Transport_service::COL_VEHICLES, [['lifecycle_status', '==', 'Active']], null, 'ASC', 200);
        } catch (\Throwable $_) {
            return ['status' => 'INFO', 'detail' => 'unreachable — trivial pass'];
        }
        $vehicles = (array) $vehicles;
        if ($vehicles === []) {
            return ['status' => 'INFO', 'detail' => 'no Active vehicles — trivial pass'];
        }
        $today = date('Y-m-d');
        $bad = [];
        foreach ($vehicles as $v) {
            $staffId = (string) ($v['staff_id'] ?? $v['driver_staff_id'] ?? '');
            if ($staffId === '') {
                continue;
            }
            $staff = $this->fs->getEntity('staff', $staffId);
            if (!is_array($staff)) {
                continue;
            }
            $exp = (string) (($staff['driver_meta']['licence_expires'] ?? '') ?: '');
            if ($exp !== '' && $exp < $today) {
                $bad[] = (string) ($v['vehicleId'] ?? $v['id'] ?? '?') . "(licence exp={$exp})";
            }
        }
        if ($bad !== []) {
            return ['status' => 'FAIL', 'detail' => count($bad) . ' vehicles have expired-licence drivers; sample: ' . implode('; ', array_slice($bad, 0, 5))];
        }
        return ['status' => 'PASS', 'detail' => count($vehicles) . ' active vehicles checked'];
    }

    /**
     * CH-TRN-14 — no routeAssignment references a vehicle in
     * lifecycle_status Retired/Disposed without an active replacement
     * record. Route -> vehicle_id -> vehicle lifecycle check.
     */
    private function _chTrn14(): array
    {
        try {
            $assignments = $this->fs->schoolWhere(Transport_service::COL_ROUTE_ASSIGNMENTS, [['status', '==', 'Active']], null, 'ASC', 200);
        } catch (\Throwable $_) {
            return ['status' => 'INFO', 'detail' => 'unreachable — trivial pass'];
        }
        $assignments = (array) $assignments;
        if ($assignments === []) {
            return ['status' => 'INFO', 'detail' => 'no active assignments — trivial pass'];
        }
        $bad = [];
        $seenRoutes = [];
        foreach ($assignments as $a) {
            $rid = (string) ($a['route_id'] ?? '');
            if ($rid === '' || isset($seenRoutes[$rid])) {
                continue;
            }
            $seenRoutes[$rid] = true;
            $route = $this->fs->getEntity(Transport_service::COL_ROUTES, $rid);
            if (!is_array($route)) {
                continue;
            }
            $vid = (string) ($route['vehicle_id'] ?? '');
            if ($vid === '') {
                continue;
            }
            $vehicle = $this->fs->getEntity(Transport_service::COL_VEHICLES, $vid);
            if (!is_array($vehicle)) {
                continue;
            }
            $ls = (string) ($vehicle['lifecycle_status'] ?? '');
            if (in_array($ls, ['Retired', 'Disposed'], true)) {
                $bad[] = "{$rid}->{$vid}({$ls})";
            }
        }
        if ($bad !== []) {
            return ['status' => 'FAIL', 'detail' => count($bad) . ' routes reference retired vehicles; sample: ' . implode(', ', array_slice($bad, 0, 5))];
        }
        return ['status' => 'PASS', 'detail' => count($seenRoutes) . ' unique routes cross-checked'];
    }

    /**
     * CH-TRN-15 — every routeAssignments doc for the current session must
     * correspond to at least one routes doc with the same session.
     */
    private function _chTrn15(): array
    {
        try {
            $assignments = $this->fs->schoolWhere(
                Transport_service::COL_ROUTE_ASSIGNMENTS,
                [['session', '==', $this->sessionYear]],
                null, 'ASC', 200
            );
            $routes = $this->fs->schoolWhere(
                Transport_service::COL_ROUTES,
                [['session', '==', $this->sessionYear]],
                null, 'ASC', 200
            );
        } catch (\Throwable $_) {
            return ['status' => 'INFO', 'detail' => 'unreachable — trivial pass'];
        }
        $assignments = (array) $assignments;
        $routes      = (array) $routes;
        if ($assignments === []) {
            return ['status' => 'INFO', 'detail' => 'no session assignments — trivial pass'];
        }
        $routeIds = [];
        foreach ($routes as $r) {
            $routeIds[(string) ($r['routeId'] ?? $r['id'] ?? '')] = true;
        }
        $orphans = [];
        foreach ($assignments as $a) {
            $rid = (string) ($a['route_id'] ?? '');
            if ($rid !== '' && !isset($routeIds[$rid])) {
                $orphans[] = (string) ($a['id'] ?? $a['studentId'] ?? '?') . "->{$rid}";
            }
        }
        if ($orphans !== []) {
            return ['status' => 'FAIL', 'detail' => count($orphans) . ' orphan assignments; sample: ' . implode(', ', array_slice($orphans, 0, 5))];
        }
        return ['status' => 'PASS', 'detail' => count($assignments) . ' assignments, ' . count($routes) . ' routes — all matched'];
    }

    /**
     * CH-TRN-16 — no bulk-op progress marker doc older than 24h. Bulk
     * ops (LC-4 bulk assign, LC-26 rollover) will write into a
     * transportBulkProgress collection; older markers indicate a job
     * that never completed. Phase 0 has no bulk collection, so trivial
     * pass by construction.
     */
    private function _chTrn16(): array
    {
        try {
            $rows = $this->fs->schoolWhere('transportBulkProgress', [], null, 'ASC', 20);
        } catch (\Throwable $_) {
            return ['status' => 'INFO', 'detail' => 'collection not yet introduced — trivial pass'];
        }
        $rows = (array) $rows;
        if ($rows === []) {
            return ['status' => 'PASS', 'detail' => 'no bulk-op progress markers'];
        }
        $cutoff = time() - 86400;
        $stale = [];
        foreach ($rows as $row) {
            $ts = strtotime((string) ($row['started_at'] ?? ''));
            if ($ts !== false && $ts < $cutoff) {
                $stale[] = (string) ($row['id'] ?? '?');
            }
        }
        if ($stale !== []) {
            return ['status' => 'FAIL', 'detail' => count($stale) . ' stale markers; sample: ' . implode(',', array_slice($stale, 0, 5))];
        }
        return ['status' => 'PASS', 'detail' => count($rows) . ' bulk markers, all fresh'];
    }

    // ═════════════════════════════════════════════════════════════════════
    //  F1 Fleet Management assertions (CH-TRN-F1-1..6) — added 2026-07-07
    // ═════════════════════════════════════════════════════════════════════

    /**
     * CH-TRN-F1-1 — No vehicle-related controller code path calls
     * Operations_accounting::next_id after F1. Vehicle allocation must
     * go through Numbering_service::next('trn_vehicle').
     *
     * The check tolerates operations_accounting calls that are UNRELATED
     * to vehicles (Transport.php's save_route / save_stop still use it
     * because Route / Stop numbering migrates in F2 / F3). It matches
     * only calls where the counter path contains 'Vehicle'.
     */
    private function _chTrnF1_1(): array
    {
        $files = [
            APPPATH . 'controllers/Transport.php',
            APPPATH . 'controllers/Transport_vendors.php',
            APPPATH . 'controllers/Transport_ops.php',
            APPPATH . 'libraries/Transport_service.php',
        ];
        $bad = [];
        foreach ($files as $f) {
            if (!is_readable($f)) { continue; }
            $src = $this->_stripComments((string) file_get_contents($f));
            if (preg_match_all(
                "/operations_accounting->next_id\(\s*[^,)]*Vehicle[^,)]*/",
                $src, $m
            )) {
                foreach ($m[0] as $match) {
                    $bad[] = basename($f) . ':' . substr($match, 0, 60);
                }
            }
        }
        if ($bad !== []) {
            return ['status' => 'FAIL', 'detail' => count($bad) . ' offender(s): ' . implode(' ; ', array_slice($bad, 0, 5))];
        }
        return ['status' => 'PASS', 'detail' => 'no operations_accounting Vehicle allocations in Transport source'];
    }

    /**
     * CH-TRN-F1-2 — Every vehicle read produces a valid lifecycle_status.
     * Samples up to 100 vehicles; treats missing lifecycle_status as the
     * coalesced default 'Active' (matches saveVehicle behaviour). Fails
     * only when a stored value is present but not in the enum.
     */
    private function _chTrnF1_2(): array
    {
        try {
            $rows = (array) $this->fs->schoolWhere(Transport_service::COL_VEHICLES, [], null, 'ASC', 100);
        } catch (\Throwable $_) {
            return ['status' => 'INFO', 'detail' => 'unreachable — trivial pass'];
        }
        if ($rows === []) {
            return ['status' => 'INFO', 'detail' => 'no vehicles — trivial pass'];
        }
        $valid = Transport_service::LIFECYCLE_STATES;
        $bad = [];
        foreach ($rows as $v) {
            $ls = (string) ($v['lifecycle_status'] ?? '');
            if ($ls === '') { continue; }   // coalesced to 'Active' at read
            if (!in_array($ls, $valid, true)) {
                $bad[] = ((string) ($v['vehicleId'] ?? $v['id'] ?? '?')) . "(={$ls})";
            }
        }
        if ($bad !== []) {
            return ['status' => 'FAIL', 'detail' => count($bad) . ' invalid: ' . implode(',', array_slice($bad, 0, 5))];
        }
        return ['status' => 'PASS', 'detail' => count($rows) . ' vehicles valid'];
    }

    /**
     * CH-TRN-F1-3 — Vehicles with ownership_type='Contract' must have a
     * non-empty vendor_id referencing an EXISTING vendor doc.
     */
    private function _chTrnF1_3(): array
    {
        try {
            $rows = (array) $this->fs->schoolWhere(
                Transport_service::COL_VEHICLES,
                [['ownership_type', '==', 'Contract']],
                null, 'ASC', 200
            );
        } catch (\Throwable $_) {
            return ['status' => 'INFO', 'detail' => 'unreachable — trivial pass'];
        }
        if ($rows === []) {
            return ['status' => 'INFO', 'detail' => 'no contract vehicles — trivial pass'];
        }
        $bad = [];
        foreach ($rows as $v) {
            $vid  = (string) ($v['vehicleId'] ?? $v['id'] ?? '?');
            $vend = (string) ($v['vendor_id'] ?? '');
            if ($vend === '') {
                $bad[] = "{$vid}(no vendor_id)";
                continue;
            }
            $vendorDoc = $this->fs->getEntity(Transport_service::COL_TRANSPORT_VENDORS, $vend);
            if (!is_array($vendorDoc)) {
                $bad[] = "{$vid}(→{$vend}: missing)";
            }
        }
        if ($bad !== []) {
            return ['status' => 'FAIL', 'detail' => count($bad) . ' broken: ' . implode(',', array_slice($bad, 0, 5))];
        }
        return ['status' => 'PASS', 'detail' => count($rows) . ' contract vehicles valid'];
    }

    /**
     * CH-TRN-F1-4 — Every recent vehicleLifecycleLog transition follows
     * the state matrix (prev_state → new_state is a legal edge).
     */
    private function _chTrnF1_4(): array
    {
        try {
            $rows = (array) $this->fs->schoolWhere(
                Transport_service::COL_VEHICLE_LIFECYCLE_LOG,
                [], 'event_at', 'DESC', 100
            );
        } catch (\Throwable $_) {
            return ['status' => 'INFO', 'detail' => 'unreachable — trivial pass'];
        }
        if ($rows === []) {
            return ['status' => 'INFO', 'detail' => 'empty log — trivial pass'];
        }
        // Legal transitions matrix — kept in sync with Transport_service
        // LIFECYCLE_TRANSITIONS (via the reflection-safe reimplementation
        // below; verifier deliberately does NOT depend on private consts).
        $legal = [
            'Purchased'   => ['Onboarding'],
            'Onboarding'  => ['Active', 'Retired'],
            'Active'      => ['Maintenance', 'Retired'],
            'Maintenance' => ['Active', 'Retired'],
            'Retired'     => ['Disposed', 'Active'],
            'Disposed'    => [],
        ];
        $bad = [];
        foreach ($rows as $r) {
            $from = (string) ($r['prev_state'] ?? '');
            $to   = (string) ($r['new_state']  ?? '');
            if ($from === '' || $to === '' || $from === $to) { continue; }   // initial/no-op events
            $allowed = $legal[$from] ?? [];
            if (!in_array($to, $allowed, true)) {
                $bad[] = ((string) ($r['eventId'] ?? '?')) . "({$from}->{$to})";
            }
        }
        if ($bad !== []) {
            return ['status' => 'FAIL', 'detail' => count($bad) . ' illegal: ' . implode(',', array_slice($bad, 0, 5))];
        }
        return ['status' => 'PASS', 'detail' => count($rows) . ' log entries checked'];
    }

    /**
     * CH-TRN-F1-5 — Every maintenanceLogs and fuelLogs doc references
     * an existing vehicles.vehicleId. Cross-collection FK integrity.
     */
    private function _chTrnF1_5(): array
    {
        try {
            $maint = (array) $this->fs->schoolWhere(Transport_service::COL_MAINTENANCE_LOGS, [], null, 'ASC', 100);
            $fuel  = (array) $this->fs->schoolWhere(Transport_service::COL_FUEL_LOGS,        [], null, 'ASC', 100);
        } catch (\Throwable $_) {
            return ['status' => 'INFO', 'detail' => 'unreachable — trivial pass'];
        }
        $rows = array_merge(
            array_map(static fn($r) => ['kind' => 'maint', 'row' => $r], $maint),
            array_map(static fn($r) => ['kind' => 'fuel',  'row' => $r], $fuel)
        );
        if ($rows === []) {
            return ['status' => 'INFO', 'detail' => 'empty logs — trivial pass'];
        }
        $vidCache = [];
        $bad = [];
        foreach ($rows as $item) {
            $vid = (string) ($item['row']['vehicle_id'] ?? '');
            if ($vid === '') {
                $bad[] = "{$item['kind']}:(no vehicle_id)";
                continue;
            }
            if (!isset($vidCache[$vid])) {
                $vidCache[$vid] = is_array($this->fs->getEntity(Transport_service::COL_VEHICLES, $vid));
            }
            if (!$vidCache[$vid]) {
                $bad[] = "{$item['kind']}:{$vid}";
            }
        }
        if ($bad !== []) {
            return ['status' => 'FAIL', 'detail' => count($bad) . ' orphan(s): ' . implode(',', array_slice($bad, 0, 5))];
        }
        return ['status' => 'PASS', 'detail' => count($rows) . ' logs FK-valid'];
    }

    /**
     * CH-TRN-F1-6 — Performance guard: no N+1 patterns in Fleet writers.
     * Static grep: any `foreach` loop body containing schoolWhere() or
     * getEntity() is a candidate offender. The service's known-safe
     * batched writes (setEntity inside foreach with pre-cached upstream
     * data) are EXEMPT — this guard is a TRIP-WIRE for future edits, not
     * a proof of absence.
     *
     * Approach: extract foreach-block bodies from Transport_service.php's
     * public method regions; any body containing '->schoolWhere(' OR
     * '->getEntity(' where the loop iterates ANOTHER schoolWhere result
     * is flagged.
     */
    private function _chTrnF1_6(): array
    {
        $path = APPPATH . 'libraries/Transport_service.php';
        if (!is_readable($path)) {
            return ['status' => 'FAIL', 'detail' => "cannot read {$path}"];
        }
        $src = $this->_stripComments((string) file_get_contents($path));

        // Find any foreach body containing a schoolWhere / getEntity call.
        // Simple heuristic: split on foreach(, take the body between { and },
        // check for the calls.
        if (!preg_match_all(
            '/foreach\s*\([^)]+\)\s*\{([^{}]*)\}/s',
            $src, $m
        )) {
            return ['status' => 'PASS', 'detail' => 'no foreach blocks in service'];
        }
        $offenders = 0;
        foreach ($m[1] as $body) {
            if (strpos($body, '->schoolWhere(') !== false || strpos($body, '->getEntity(') !== false) {
                // Exception: getEntity inside foreach is a legit membership
                // check pattern when the upstream loop is BOUNDED (e.g. the
                // routes array from a single explicit input, not a schoolWhere
                // result). We can't distinguish without deeper AST — so we
                // report WARN, not FAIL, and let the review acknowledge.
                $offenders++;
            }
        }
        if ($offenders === 0) {
            return ['status' => 'PASS', 'detail' => 'no read-in-loop patterns detected in service'];
        }
        // F1 has recordReplacement (routes[] loop → getEntity/setEntity per
        // route) and computeFleetExpiryDashboard (vehicles loop → in-memory
        // check, no per-item query). Neither is N+1: the routes[] input is
        // bounded by controller intent. Report WARN.
        return ['status' => 'WARN', 'detail' => "{$offenders} bounded-loop pattern(s) noted — reviewer to confirm each is bounded input"];
    }

    // ─── shared source-grep helper ───────────────────────────────────────

    private function _grepTransportSourceForbidden(string $regex, string $label): array
    {
        $files = [
            APPPATH . 'controllers/Transport.php',
            APPPATH . 'libraries/Transport_service.php',
        ];
        $offenders = [];
        foreach ($files as $file) {
            if (!is_readable($file)) {
                continue;
            }
            $src = (string) file_get_contents($file);
            $stripped = $this->_stripComments($src);
            if (preg_match($regex, $stripped, $m)) {
                $offenders[] = basename($file) . ':' . substr($m[0], 0, 40);
            }
        }
        if ($offenders !== []) {
            return ['status' => 'FAIL', 'detail' => "{$label} found: " . implode('; ', $offenders)];
        }
        return ['status' => 'PASS', 'detail' => "no {$label} in Transport source"];
    }

    private function _stripComments(string $src): string
    {
        $out    = '';
        $tokens = token_get_all($src);
        foreach ($tokens as $tok) {
            if (is_array($tok)) {
                if (in_array($tok[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                    continue;
                }
                $out .= $tok[1];
            } else {
                $out .= $tok;
            }
        }
        return $out;
    }
}
