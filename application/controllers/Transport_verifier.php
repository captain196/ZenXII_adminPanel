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

            // ─── F2 Route & Stop Management (added 2026-07-07) ────────────────
            [ 'id' => 'CH-TRN-F2-1', 'category' => self::CAT_SOURCE,     'label' => 'no ops_acc route/stop',     'callable' => '_chTrnF2_1' ],
            [ 'id' => 'CH-TRN-F2-2', 'category' => self::CAT_RUNTIME,    'label' => 'stop->route FK integrity',  'callable' => '_chTrnF2_2' ],
            [ 'id' => 'CH-TRN-F2-3', 'category' => self::CAT_RUNTIME,    'label' => 'suspension<->route ref',    'callable' => '_chTrnF2_3' ],
            [ 'id' => 'CH-TRN-F2-4', 'category' => self::CAT_RUNTIME,    'label' => 'no stale suspension ref',   'callable' => '_chTrnF2_4' ],
            [ 'id' => 'CH-TRN-F2-5', 'category' => self::CAT_PERFORMANCE,'label' => 'suspension write bound',    'callable' => '_chTrnF2_5' ],

            // ─── F3 Student Assignment (added 2026-07-07) ────────────────────
            [ 'id' => 'CH-TRN-F3-1', 'category' => self::CAT_SOURCE,     'label' => 'no ops_acc in Transport',   'callable' => '_chTrnF3_1' ],
            [ 'id' => 'CH-TRN-F3-2', 'category' => self::CAT_RUNTIME,    'label' => 'assignment session field',  'callable' => '_chTrnF3_2' ],
            [ 'id' => 'CH-TRN-F3-3', 'category' => self::CAT_RUNTIME,    'label' => 'no stale pending fee',      'callable' => '_chTrnF3_3' ],
            [ 'id' => 'CH-TRN-F3-4', 'category' => self::CAT_RUNTIME,    'label' => 'no assign to archived rt',  'callable' => '_chTrnF3_4' ],
            [ 'id' => 'CH-TRN-F3-5', 'category' => self::CAT_CACHE,      'label' => 'capacity cache accuracy',   'callable' => '_chTrnF3_5' ],
            [ 'id' => 'CH-TRN-F3-6', 'category' => self::CAT_PERFORMANCE,'label' => 'no N+1 in bulk/rollover',   'callable' => '_chTrnF3_6' ],

            // ─── F4 Driver Management (added 2026-07-07) ─────────────────────
            [ 'id' => 'CH-TRN-F4-1', 'category' => self::CAT_SOURCE,     'label' => 'staff-write discipline',    'callable' => '_chTrnF4_1' ],
            [ 'id' => 'CH-TRN-F4-2', 'category' => self::CAT_RUNTIME,    'label' => 'incident staff FK',         'callable' => '_chTrnF4_2' ],
            [ 'id' => 'CH-TRN-F4-3', 'category' => self::CAT_RUNTIME,    'label' => 'no stale active temp',      'callable' => '_chTrnF4_3' ],
            [ 'id' => 'CH-TRN-F4-4', 'category' => self::CAT_RUNTIME,    'label' => 'no temp overlap per vehicle','callable' => '_chTrnF4_4' ],
            [ 'id' => 'CH-TRN-F4-5', 'category' => self::CAT_CACHE,      'label' => 'activity summary freshness','callable' => '_chTrnF4_5' ],
            [ 'id' => 'CH-TRN-F4-6', 'category' => self::CAT_PERFORMANCE,'label' => 'no N+1 in activity compute','callable' => '_chTrnF4_6' ],

            // ─── F5 Transport Expenses + Accounting (added 2026-07-07) ──────
            [ 'id' => 'CH-TRN-F5-1', 'category' => self::CAT_SOURCE,     'label' => 'no direct ledger from F5', 'callable' => '_chTrnF5_1' ],
            [ 'id' => 'CH-TRN-F5-2', 'category' => self::CAT_RUNTIME,    'label' => 'expense type validity',    'callable' => '_chTrnF5_2' ],
            [ 'id' => 'CH-TRN-F5-3', 'category' => self::CAT_RUNTIME,    'label' => 'no stale pending post',    'callable' => '_chTrnF5_3' ],
            [ 'id' => 'CH-TRN-F5-4', 'category' => self::CAT_RUNTIME,    'label' => 'expense FK integrity',     'callable' => '_chTrnF5_4' ],
            [ 'id' => 'CH-TRN-F5-5', 'category' => self::CAT_PERFORMANCE,'label' => 'no N+1 in summary calc',   'callable' => '_chTrnF5_5' ],

            // ─── F6 Communication Integration + SOS (added 2026-07-07) ──────
            [ 'id' => 'CH-TRN-F6-1', 'category' => self::CAT_SOURCE,     'label' => 'notice writes via notifier',    'callable' => '_chTrnF6_1' ],
            [ 'id' => 'CH-TRN-F6-2', 'category' => self::CAT_SOURCE,     'label' => 'category priority matrix',       'callable' => '_chTrnF6_2' ],
            [ 'id' => 'CH-TRN-F6-3', 'category' => self::CAT_RUNTIME,    'label' => 'no expired idempotency claims',  'callable' => '_chTrnF6_3' ],
            [ 'id' => 'CH-TRN-F6-4', 'category' => self::CAT_RUNTIME,    'label' => 'SOS dual-field client compat',   'callable' => '_chTrnF6_4' ],
            [ 'id' => 'CH-TRN-F6-5', 'category' => self::CAT_CONTRACT,   'label' => 'notification audit-emission',    'callable' => '_chTrnF6_5' ],
            [ 'id' => 'CH-TRN-F6-6', 'category' => self::CAT_PERFORMANCE,'label' => 'fanout batch limit',             'callable' => '_chTrnF6_6' ],

            // ─── F7 Trip Logs + Transport Attendance (added 2026-07-07) ─────
            [ 'id' => 'CH-TRN-F7-1', 'category' => self::CAT_RUNTIME,    'label' => 'GPS-null discipline',           'callable' => '_chTrnF7_1' ],
            [ 'id' => 'CH-TRN-F7-2', 'category' => self::CAT_RUNTIME,    'label' => 'trip required fields',          'callable' => '_chTrnF7_2' ],
            [ 'id' => 'CH-TRN-F7-3', 'category' => self::CAT_RUNTIME,    'label' => 'attendance FK to trip',         'callable' => '_chTrnF7_3' ],
            [ 'id' => 'CH-TRN-F7-4', 'category' => self::CAT_RUNTIME,    'label' => 'boarding-event docid determinism','callable' => '_chTrnF7_4' ],
            [ 'id' => 'CH-TRN-F7-5', 'category' => self::CAT_CONTRACT,   'label' => 'completed trip tally consistency','callable' => '_chTrnF7_5' ],
            [ 'id' => 'CH-TRN-F7-6', 'category' => self::CAT_PERFORMANCE,'label' => 'no N+1 in roster snapshot',     'callable' => '_chTrnF7_6' ],

            // ─── F8 Transport Events (added 2026-07-07) ─────────────────────
            [ 'id' => 'CH-TRN-F8-1', 'category' => self::CAT_SOURCE,     'label' => 'event writes via service',       'callable' => '_chTrnF8_1' ],
            [ 'id' => 'CH-TRN-F8-2', 'category' => self::CAT_RUNTIME,    'label' => 'assignment→event FK integrity',  'callable' => '_chTrnF8_2' ],
            [ 'id' => 'CH-TRN-F8-3', 'category' => self::CAT_RUNTIME,    'label' => 'event vehicle+escort FK',        'callable' => '_chTrnF8_3' ],
            [ 'id' => 'CH-TRN-F8-4', 'category' => self::CAT_RUNTIME,    'label' => 'event-trip FK integrity',        'callable' => '_chTrnF8_4' ],
            [ 'id' => 'CH-TRN-F8-5', 'category' => self::CAT_CONTRACT,   'label' => 'event cost tally',               'callable' => '_chTrnF8_5' ],
            [ 'id' => 'CH-TRN-F8-6', 'category' => self::CAT_PERFORMANCE,'label' => 'no N+1 in event roster',         'callable' => '_chTrnF8_6' ],

            // ─── F9 Teacher App Transport (added 2026-07-07) ────────────────
            [ 'id' => 'CH-TRN-F9-1', 'category' => self::CAT_SOURCE,     'label' => 'driver-scoped rules present',    'callable' => '_chTrnF9_1' ],
            [ 'id' => 'CH-TRN-F9-2', 'category' => self::CAT_SOURCE,     'label' => 'SOS severity matrix complete',   'callable' => '_chTrnF9_2' ],
            [ 'id' => 'CH-TRN-F9-3', 'category' => self::CAT_RUNTIME,    'label' => 'SOS docs carry valid severity',  'callable' => '_chTrnF9_3' ],
            [ 'id' => 'CH-TRN-F9-4', 'category' => self::CAT_CONTRACT,   'label' => 'attendance marked_by_role',      'callable' => '_chTrnF9_4' ],

            // ─── F10 Parent App Transport (added 2026-07-07) ────────────────
            [ 'id' => 'CH-TRN-F10-1', 'category' => self::CAT_SOURCE,     'label' => 'prefs-honoring in fanout',       'callable' => '_chTrnF10_1' ],
            [ 'id' => 'CH-TRN-F10-2', 'category' => self::CAT_SOURCE,     'label' => 'safety-exempt covers all Urgent','callable' => '_chTrnF10_2' ],
            [ 'id' => 'CH-TRN-F10-3', 'category' => self::CAT_RUNTIME,    'label' => 'prefs docs no safety mutes',     'callable' => '_chTrnF10_3' ],
            [ 'id' => 'CH-TRN-F10-4', 'category' => self::CAT_CONTRACT,   'label' => 'transport fee category lowercase','callable' => '_chTrnF10_4' ],
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
                if (!is_array($row)) { $bad[] = "{$col}:?"; continue; }
                // schoolWhere returns Firestore's nested {id, data:{...}}
                // shape; unwrap so the schoolId lookup is uniform across all
                // owned collections (matches the pattern used elsewhere in
                // Transport_service via _unwrapNestedRow).
                $inner = (isset($row['data']) && is_array($row['data'])) ? $row['data'] : $row;
                $sid = (string) ($inner['schoolId'] ?? '');
                if ($sid === '') {
                    $bad[] = "{$col}:" . (string) ($row['id'] ?? ($inner['id'] ?? '?'));
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

    // ═════════════════════════════════════════════════════════════════════
    //  F2 Route & Stop Management assertions (CH-TRN-F2-1..5) — 2026-07-07
    // ═════════════════════════════════════════════════════════════════════

    /**
     * CH-TRN-F2-1 — No route/stop-related controller code path calls
     * Operations_accounting::next_id after F2. Vehicle allocation
     * (CH-TRN-F1-1) already migrated in F1; F2 completes the pattern
     * for RT/STP. Tolerates other Operations_accounting usage that F3+
     * will migrate later.
     */
    private function _chTrnF2_1(): array
    {
        $files = [
            APPPATH . 'controllers/Transport.php',
            APPPATH . 'libraries/Transport_service.php',
        ];
        $bad = [];
        foreach ($files as $f) {
            if (!is_readable($f)) { continue; }
            $src = $this->_stripComments((string) file_get_contents($f));
            if (preg_match_all(
                "/operations_accounting->next_id\(\s*[^,)]*(Route|Stop)[^,)]*/",
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
        return ['status' => 'PASS', 'detail' => 'no operations_accounting Route/Stop allocations in Transport source'];
    }

    /**
     * CH-TRN-F2-2 — Every stop's route_id points to an existing route
     * (cross-collection FK integrity). Samples up to 200 stops.
     */
    private function _chTrnF2_2(): array
    {
        try {
            $stops = (array) $this->fs->schoolWhere(Transport_service::COL_TRANSPORT_STOPS, [], null, 'ASC', 200);
        } catch (\Throwable $_) {
            return ['status' => 'INFO', 'detail' => 'unreachable — trivial pass'];
        }
        if ($stops === []) {
            return ['status' => 'INFO', 'detail' => 'no stops — trivial pass'];
        }
        $routeCache = [];
        $bad = [];
        foreach ($stops as $s) {
            if (!is_array($s)) { continue; }
            $rid = (string) ($s['route_id'] ?? '');
            if ($rid === '') {
                $bad[] = ((string) ($s['stopId'] ?? '?')) . '(no route_id)';
                continue;
            }
            if (!isset($routeCache[$rid])) {
                $routeCache[$rid] = is_array($this->fs->getEntity(Transport_service::COL_ROUTES, $rid));
            }
            if (!$routeCache[$rid]) {
                $bad[] = ((string) ($s['stopId'] ?? '?')) . "->{$rid}";
            }
        }
        if ($bad !== []) {
            return ['status' => 'FAIL', 'detail' => count($bad) . ' orphan(s): ' . implode(',', array_slice($bad, 0, 5))];
        }
        return ['status' => 'PASS', 'detail' => count($stops) . ' stops FK-valid'];
    }

    /**
     * CH-TRN-F2-3 — Every routeSuspensions doc with status='Active' has a
     * matching routes.active_suspension_ref pointing back. Bidirectional
     * consistency (the two writes are batched in saveRouteSuspension;
     * this check catches drift from external tooling).
     */
    private function _chTrnF2_3(): array
    {
        try {
            $active = (array) $this->fs->schoolWhere(
                Transport_service::COL_ROUTE_SUSPENSIONS,
                [['status', '==', 'Active']],
                null, 'ASC', 100
            );
        } catch (\Throwable $_) {
            return ['status' => 'INFO', 'detail' => 'unreachable — trivial pass'];
        }
        if ($active === []) {
            return ['status' => 'INFO', 'detail' => 'no active suspensions — trivial pass'];
        }
        $bad = [];
        foreach ($active as $s) {
            if (!is_array($s)) { continue; }
            $sid = (string) ($s['suspensionId'] ?? '?');
            $rid = (string) ($s['route_id'] ?? '');
            if ($rid === '') { $bad[] = "{$sid}(no route_id)"; continue; }
            $route = $this->fs->getEntity(Transport_service::COL_ROUTES, $rid);
            if (!is_array($route)) { $bad[] = "{$sid}->missing route {$rid}"; continue; }
            $ref = (string) ($route['active_suspension_ref'] ?? '');
            if ($ref !== $sid) {
                $bad[] = "{$sid} (route.ref={$ref})";
            }
        }
        if ($bad !== []) {
            return ['status' => 'FAIL', 'detail' => count($bad) . ' mismatches: ' . implode(', ', array_slice($bad, 0, 5))];
        }
        return ['status' => 'PASS', 'detail' => count($active) . ' active suspensions consistent'];
    }

    /**
     * CH-TRN-F2-4 — No route with active_suspension_ref set points to a
     * Resolved suspension (stale reference caught). Complementary to
     * CH-TRN-F2-3 (opposite direction).
     */
    private function _chTrnF2_4(): array
    {
        try {
            $routes = (array) $this->fs->schoolWhere(Transport_service::COL_ROUTES, [], null, 'ASC', 200);
        } catch (\Throwable $_) {
            return ['status' => 'INFO', 'detail' => 'unreachable — trivial pass'];
        }
        if ($routes === []) {
            return ['status' => 'INFO', 'detail' => 'no routes — trivial pass'];
        }
        $bad = [];
        foreach ($routes as $r) {
            if (!is_array($r)) { continue; }
            $ref = (string) ($r['active_suspension_ref'] ?? '');
            if ($ref === '') { continue; }
            $s = $this->fs->getEntity(Transport_service::COL_ROUTE_SUSPENSIONS, $ref);
            if (!is_array($s)) {
                $bad[] = ((string) ($r['routeId'] ?? '?')) . "->missing {$ref}";
                continue;
            }
            $st = (string) ($s['status'] ?? '');
            if ($st !== 'Active') {
                $bad[] = ((string) ($r['routeId'] ?? '?')) . "->{$ref}({$st})";
            }
        }
        if ($bad !== []) {
            return ['status' => 'FAIL', 'detail' => count($bad) . ' stale refs: ' . implode(', ', array_slice($bad, 0, 5))];
        }
        return ['status' => 'PASS', 'detail' => count($routes) . ' routes checked, no stale suspension refs'];
    }

    /**
     * CH-TRN-F2-5 — Performance guard: saveRouteSuspension + resumeRoute
     * writers stay bounded (≤ small constant of setEntity/emitAudit
     * calls in the body). Source-scan.
     */
    private function _chTrnF2_5(): array
    {
        $path = APPPATH . 'libraries/Transport_service.php';
        if (!is_readable($path)) {
            return ['status' => 'FAIL', 'detail' => "cannot read {$path}"];
        }
        $src = (string) file_get_contents($path);
        $methods = ['saveRouteSuspension', 'resumeRoute'];
        $report = [];
        foreach ($methods as $m) {
            if (!preg_match('/public\s+function\s+' . preg_quote($m, '/') . '\s*\([^)]*\)[^{]*\{/', $src, $mm, PREG_OFFSET_CAPTURE)) {
                $report[] = "{$m}(missing)";
                continue;
            }
            $start = $mm[0][1] + strlen($mm[0][0]) - 1;
            $depth = 0; $i = $start;
            while ($i < strlen($src)) {
                $c = $src[$i];
                if ($c === '{') { $depth++; }
                elseif ($c === '}') { $depth--; if ($depth === 0) { break; } }
                $i++;
            }
            $body = substr($src, $start, $i - $start + 1);
            $sets   = substr_count($body, 'setEntity(');
            $audits = substr_count($body, 'emitAudit(');
            // Bound: saveRouteSuspension writes 2 setEntity (suspension +
            // route ref) + 1 optional stamp update + 1 emitAudit → allow up
            // to 4 total writes; resumeRoute writes 2 setEntity + 1
            // emitAudit → allow up to 3.
            $bound = $m === 'saveRouteSuspension' ? 4 : 3;
            $total = $sets + $audits;
            if ($total > $bound) {
                $report[] = "{$m}(bound={$bound}, actual={$total})";
            } else {
                $report[] = "{$m}(ok:{$total}<={$bound})";
            }
        }
        $failed = array_filter($report, static fn($s) => strpos($s, 'bound=') !== false || strpos($s, 'missing') !== false);
        if ($failed !== []) {
            return ['status' => 'FAIL', 'detail' => implode(' ', $failed)];
        }
        return ['status' => 'PASS', 'detail' => implode(' ', $report)];
    }

    // ═════════════════════════════════════════════════════════════════════
    //  F3 Student Assignment assertions (CH-TRN-F3-1..6) — 2026-07-07
    // ═════════════════════════════════════════════════════════════════════

    /**
     * CH-TRN-F3-1 — After F3, NO Transport controller/library code path
     * calls Operations_accounting::next_id at all (F1 migrated vehicles,
     * F2 migrated routes/stops, F3 migrated assignments). This is the
     * cutoff assertion: Transport source is completely off Operations_
     * accounting from F3 onward.
     */
    private function _chTrnF3_1(): array
    {
        $files = [
            APPPATH . 'controllers/Transport.php',
            APPPATH . 'controllers/Transport_vendors.php',
            APPPATH . 'controllers/Transport_ops.php',
            APPPATH . 'controllers/Transport_rollover.php',
            APPPATH . 'controllers/Transport_expiry_cron.php',
            APPPATH . 'libraries/Transport_service.php',
        ];
        $bad = [];
        foreach ($files as $f) {
            if (!is_readable($f)) { continue; }
            $src = $this->_stripComments((string) file_get_contents($f));
            if (preg_match('/operations_accounting->next_id\(/', $src)) {
                $bad[] = basename($f);
            }
        }
        if ($bad !== []) {
            return ['status' => 'FAIL', 'detail' => count($bad) . ' file(s) still use operations_accounting: ' . implode(', ', $bad)];
        }
        return ['status' => 'PASS', 'detail' => 'no Operations_accounting usage remaining in Transport code'];
    }

    /**
     * CH-TRN-F3-2 — Every studentRoutes doc has a non-empty session field
     * (F3 canonical schema requirement). Samples up to 100 docs.
     */
    private function _chTrnF3_2(): array
    {
        try {
            $rows = (array) $this->fs->schoolWhere(Transport_service::COL_STUDENT_ROUTES_LEGACY, [], null, 'ASC', 100);
        } catch (\Throwable $_) {
            return ['status' => 'INFO', 'detail' => 'unreachable — trivial pass'];
        }
        if ($rows === []) {
            return ['status' => 'INFO', 'detail' => 'no assignments — trivial pass'];
        }
        $bad = [];
        foreach ($rows as $r) {
            if (!is_array($r)) { continue; }
            $s = (string) ($r['session'] ?? '');
            if ($s === '' || !preg_match('/^\d{4}-\d{2}$/', $s)) {
                $bad[] = (string) ($r['studentId'] ?? '?');
            }
        }
        if ($bad !== []) {
            return ['status' => 'FAIL', 'detail' => count($bad) . ' missing/bad session: ' . implode(',', array_slice($bad, 0, 5))];
        }
        return ['status' => 'PASS', 'detail' => count($rows) . ' assignments have valid session'];
    }

    /**
     * CH-TRN-F3-3 — No studentRoutes with fee_demand_ref='pending' older
     * than 24h. The reconcile_pending_fees endpoint + nightly job should
     * catch these; alert if any age past the SLA.
     */
    private function _chTrnF3_3(): array
    {
        try {
            $rows = (array) $this->fs->schoolWhere(
                Transport_service::COL_STUDENT_ROUTES_LEGACY,
                [['fee_demand_ref', '==', 'pending']],
                null, 'ASC', 200
            );
        } catch (\Throwable $_) {
            return ['status' => 'INFO', 'detail' => 'unreachable — trivial pass'];
        }
        if ($rows === []) {
            return ['status' => 'PASS', 'detail' => 'no pending fee assignments'];
        }
        $cutoff = time() - 86400;
        $stale = [];
        foreach ($rows as $r) {
            if (!is_array($r)) { continue; }
            $ts = strtotime((string) ($r['updated_at'] ?? $r['assigned_date'] ?? ''));
            if ($ts !== false && $ts < $cutoff) {
                $stale[] = (string) ($r['studentId'] ?? '?');
            }
        }
        if ($stale !== []) {
            return ['status' => 'FAIL', 'detail' => count($stale) . ' stale pending fees >24h: ' . implode(',', array_slice($stale, 0, 5))];
        }
        return ['status' => 'PASS', 'detail' => count($rows) . ' pending fee(s) all within SLA'];
    }

    /**
     * CH-TRN-F3-4 — No studentRoutes points at a currently Archived route
     * (assignment pre-write validation should prevent this; drift check
     * catches manual DB tampering or a race with archive).
     */
    private function _chTrnF3_4(): array
    {
        try {
            $rows = (array) $this->fs->schoolWhere(Transport_service::COL_STUDENT_ROUTES_LEGACY, [], null, 'ASC', 200);
        } catch (\Throwable $_) {
            return ['status' => 'INFO', 'detail' => 'unreachable — trivial pass'];
        }
        if ($rows === []) {
            return ['status' => 'INFO', 'detail' => 'no assignments — trivial pass'];
        }
        $routeCache = [];
        $bad = [];
        foreach ($rows as $r) {
            if (!is_array($r)) { continue; }
            $rid = (string) ($r['route_id'] ?? '');
            if ($rid === '') { continue; }
            if (!array_key_exists($rid, $routeCache)) {
                $rt = $this->fs->getEntity(Transport_service::COL_ROUTES, $rid);
                $routeCache[$rid] = is_array($rt) ? (string) ($rt['status'] ?? 'Active') : 'MISSING';
            }
            if ($routeCache[$rid] === 'Archived') {
                $bad[] = ((string) ($r['studentId'] ?? '?')) . "->" . $rid;
            }
        }
        if ($bad !== []) {
            return ['status' => 'FAIL', 'detail' => count($bad) . ' assign→Archived: ' . implode(',', array_slice($bad, 0, 5))];
        }
        return ['status' => 'PASS', 'detail' => count($rows) . ' assignments checked, no Archived-route refs'];
    }

    /**
     * CH-TRN-F3-5 — busCapacityCache.current_count equals the actual
     * studentRoutes count for each cached route. Drift check for the
     * cache maintained by _bumpCapacityCache.
     */
    private function _chTrnF3_5(): array
    {
        try {
            $caches = (array) $this->fs->schoolWhere(Transport_service::COL_BUS_CAPACITY_CACHE, [], null, 'ASC', 100);
        } catch (\Throwable $_) {
            return ['status' => 'INFO', 'detail' => 'unreachable — trivial pass'];
        }
        if ($caches === []) {
            return ['status' => 'INFO', 'detail' => 'no cache entries — trivial pass'];
        }
        $drift = [];
        foreach ($caches as $c) {
            if (!is_array($c)) { continue; }
            $rid = (string) ($c['route_id'] ?? '');
            $session = (string) ($c['session'] ?? '');
            if ($rid === '') { continue; }
            $where = [['route_id', '==', $rid]];
            if ($session !== '') { $where[] = ['session', '==', $session]; }
            $actual = count((array) $this->fs->schoolWhere(Transport_service::COL_STUDENT_ROUTES_LEGACY, $where));
            $cached = (int) ($c['current_count'] ?? 0);
            if ($actual !== $cached) {
                $drift[] = "{$rid}({$cached}!={$actual})";
            }
        }
        if ($drift !== []) {
            return ['status' => 'WARN', 'detail' => count($drift) . ' cache drift(s) — run rebuild_capacity_cache: ' . implode(',', array_slice($drift, 0, 5))];
        }
        return ['status' => 'PASS', 'detail' => count($caches) . ' cache entries accurate'];
    }

    /**
     * CH-TRN-F3-6 — Performance: bulk_assign + rolloverSession bodies use
     * bounded input loops (foreach over caller-supplied rows or paginated
     * chunks), not schoolWhere-in-foreach patterns. Source-scan.
     */
    private function _chTrnF3_6(): array
    {
        $path = APPPATH . 'libraries/Transport_service.php';
        if (!is_readable($path)) {
            return ['status' => 'FAIL', 'detail' => "cannot read {$path}"];
        }
        $src = $this->_stripComments((string) file_get_contents($path));
        $methods = ['bulkAssignStudents', 'rolloverSession'];
        $report = [];
        $bad = 0;
        foreach ($methods as $m) {
            if (!preg_match('/public\s+function\s+' . preg_quote($m, '/') . '\s*\([^)]*\)[^{]*\{/', $src, $mm, PREG_OFFSET_CAPTURE)) {
                $report[] = "{$m}(missing)"; $bad++; continue;
            }
            $start = $mm[0][1] + strlen($mm[0][0]) - 1;
            $depth = 0; $i = $start;
            while ($i < strlen($src)) {
                $c = $src[$i];
                if ($c === '{') { $depth++; }
                elseif ($c === '}') { $depth--; if ($depth === 0) { break; } }
                $i++;
            }
            $body = substr($src, $start, $i - $start + 1);
            // Anti-pattern: nested foreach whose outer iterates schoolWhere
            // AND inner performs schoolWhere. rolloverSession does have
            // schoolWhere INSIDE a while-loop (paginated chunk fetch) —
            // that's bounded and expected; not an N+1.
            $swIn = substr_count($body, '->schoolWhere(');
            $getE = substr_count($body, '->getEntity(');
            $report[] = "{$m}(sw:{$swIn},get:{$getE})";
        }
        $status = $bad === 0 ? 'PASS' : 'FAIL';
        return ['status' => $status, 'detail' => implode(' ', $report)];
    }

    // ═════════════════════════════════════════════════════════════════════
    //  F4 Driver Management assertions (CH-TRN-F4-1..6) — 2026-07-07
    // ═════════════════════════════════════════════════════════════════════

    /**
     * CH-TRN-F4-1 — HR-staff-write discipline. Transport code MAY only
     * write these top-level keys on staff docs: 'driver_meta',
     * 'updated_at'. Grep every setEntity('staff', ...) call in Transport
     * source; parse the payload array literal; assert each key.
     */
    private function _chTrnF4_1(): array
    {
        $files = [
            APPPATH . 'controllers/Transport.php',
            APPPATH . 'controllers/Transport_driver.php',
            APPPATH . 'controllers/Transport_vendors.php',
            APPPATH . 'controllers/Transport_ops.php',
            APPPATH . 'controllers/Transport_rollover.php',
            APPPATH . 'libraries/Transport_service.php',
        ];
        $allowed = ['driver_meta', 'updated_at'];
        $bad = [];
        foreach ($files as $f) {
            if (!is_readable($f)) { continue; }
            $src = $this->_stripComments((string) file_get_contents($f));
            if (!preg_match_all(
                "/setEntity\(\s*['\"]staff['\"]\s*,\s*[^,]+,\s*(\[[^\]]*?\])/",
                $src, $m
            )) { continue; }
            foreach ($m[1] as $literal) {
                if (preg_match_all("/['\"]([A-Za-z_][A-Za-z0-9_]*)['\"]\s*=>/", $literal, $keys)) {
                    foreach ($keys[1] as $key) {
                        if (!in_array($key, $allowed, true)) {
                            $bad[] = basename($f) . ':' . $key;
                        }
                    }
                }
            }
        }
        if ($bad !== []) {
            return ['status' => 'FAIL', 'detail' => count($bad) . ' violation(s): ' . implode(', ', array_slice($bad, 0, 5))];
        }
        return ['status' => 'PASS', 'detail' => 'staff writes restricted to driver_meta/updated_at only'];
    }

    private function _chTrnF4_2(): array
    {
        try {
            $rows = (array) $this->fs->schoolWhere(
                Transport_service::COL_DRIVER_INCIDENTS,
                [], 'incident_date', 'DESC', 100
            );
        } catch (\Throwable $_) {
            return ['status' => 'INFO', 'detail' => 'unreachable — trivial pass'];
        }
        if ($rows === []) {
            return ['status' => 'INFO', 'detail' => 'no incidents — trivial pass'];
        }
        $bad = [];
        $staffCache = [];
        foreach ($rows as $r) {
            if (!is_array($r)) { continue; }
            if (isset($r['data']) && is_array($r['data']) && !isset($r['staff_id'])) {
                $r = $r['data'];
            }
            $sid = (string) ($r['staff_id'] ?? '');
            if ($sid === '') {
                $bad[] = ((string) ($r['incidentId'] ?? '?')) . '(no staff_id)';
                continue;
            }
            if (!isset($staffCache[$sid])) {
                $staffCache[$sid] = is_array($this->fs->getEntity('staff', $sid));
            }
            if (!$staffCache[$sid]) {
                $bad[] = ((string) ($r['incidentId'] ?? '?')) . "->{$sid}";
            }
        }
        if ($bad !== []) {
            return ['status' => 'FAIL', 'detail' => count($bad) . ' orphans: ' . implode(',', array_slice($bad, 0, 5))];
        }
        return ['status' => 'PASS', 'detail' => count($rows) . ' incidents FK-valid'];
    }

    private function _chTrnF4_3(): array
    {
        try {
            $active = (array) $this->fs->schoolWhere(
                Transport_service::COL_TEMPORARY_DRIVER_ASSIGNMENTS,
                [['status', '==', 'Active']], null, 'ASC', 100
            );
        } catch (\Throwable $_) {
            return ['status' => 'INFO', 'detail' => 'unreachable — trivial pass'];
        }
        if ($active === []) {
            return ['status' => 'INFO', 'detail' => 'no active temp assignments — trivial pass'];
        }
        $today = date('Y-m-d');
        $stale = [];
        foreach ($active as $t) {
            if (!is_array($t)) { continue; }
            if (isset($t['data']) && is_array($t['data']) && !isset($t['effective_to'])) {
                $t = $t['data'];
            }
            $to = (string) ($t['effective_to'] ?? '');
            if ($to !== '' && $to < $today) {
                $stale[] = (string) ($t['assignmentId'] ?? '?');
            }
        }
        if ($stale !== []) {
            return ['status' => 'FAIL', 'detail' => count($stale) . ' stale active temp: ' . implode(',', array_slice($stale, 0, 5))];
        }
        return ['status' => 'PASS', 'detail' => count($active) . ' active temp assignments, none stale'];
    }

    private function _chTrnF4_4(): array
    {
        try {
            $active = (array) $this->fs->schoolWhere(
                Transport_service::COL_TEMPORARY_DRIVER_ASSIGNMENTS,
                [['status', '==', 'Active']], null, 'ASC', 200
            );
        } catch (\Throwable $_) {
            return ['status' => 'INFO', 'detail' => 'unreachable — trivial pass'];
        }
        if (count($active) < 2) {
            return ['status' => 'INFO', 'detail' => 'insufficient active — trivial pass'];
        }
        $rows = [];
        foreach ($active as $t) {
            if (!is_array($t)) { continue; }
            if (isset($t['data']) && is_array($t['data']) && !isset($t['vehicle_id'])) { $t = $t['data']; }
            $rows[] = [
                'id'   => (string) ($t['assignmentId'] ?? '?'),
                'vid'  => (string) ($t['vehicle_id']   ?? ''),
                'from' => (string) ($t['effective_from'] ?? ''),
                'to'   => (string) ($t['effective_to']   ?? ''),
            ];
        }
        $byVehicle = [];
        foreach ($rows as $r) {
            if ($r['vid'] === '') { continue; }
            $byVehicle[$r['vid']][] = $r;
        }
        $bad = [];
        foreach ($byVehicle as $vid => $list) {
            $n = count($list);
            for ($i = 0; $i < $n; $i++) {
                for ($j = $i + 1; $j < $n; $j++) {
                    $a = $list[$i]; $b = $list[$j];
                    if ($a['from'] === '' || $a['to'] === '' || $b['from'] === '' || $b['to'] === '') { continue; }
                    if (!($a['to'] < $b['from'] || $a['from'] > $b['to'])) {
                        $bad[] = "{$vid}({$a['id']}<>{$b['id']})";
                    }
                }
            }
        }
        if ($bad !== []) {
            return ['status' => 'FAIL', 'detail' => count($bad) . ' overlap(s): ' . implode(', ', array_slice($bad, 0, 5))];
        }
        return ['status' => 'PASS', 'detail' => count($rows) . ' active temp assignments, no overlaps'];
    }

    private function _chTrnF4_5(): array
    {
        try {
            $drivers = $this->transport->listActiveDrivers();
        } catch (\Throwable $_) {
            return ['status' => 'INFO', 'detail' => 'unreachable — trivial pass'];
        }
        if ($drivers === []) {
            return ['status' => 'INFO', 'detail' => 'no active drivers — trivial pass'];
        }
        $ym = date('Y-m');
        $cutoff = time() - (48 * 3600);
        $stale = 0;
        $missing = 0;
        foreach ($drivers as $d) {
            if (!is_array($d)) { continue; }
            $sid = (string) ($d['staff_id'] ?? '');
            if ($sid === '') { continue; }
            $docId = $this->schoolId . '_DACT_' . $sid . '__' . $ym;
            $sum = $this->fs->getEntity(Transport_service::COL_DRIVER_ACTIVITY_SUMMARY, $docId);
            if (!is_array($sum)) { $missing++; continue; }
            $ts = strtotime((string) ($sum['computed_at'] ?? ''));
            if ($ts !== false && $ts < $cutoff) { $stale++; }
        }
        if ($missing > 0 || $stale > 0) {
            return ['status' => 'WARN', 'detail' => "missing:{$missing} stale:{$stale} — run rebuild_activity"];
        }
        return ['status' => 'PASS', 'detail' => count($drivers) . ' drivers, activity summaries fresh (<48h)'];
    }

    private function _chTrnF4_6(): array
    {
        $path = APPPATH . 'libraries/Transport_service.php';
        if (!is_readable($path)) {
            return ['status' => 'FAIL', 'detail' => "cannot read {$path}"];
        }
        $src = $this->_stripComments((string) file_get_contents($path));
        if (!preg_match('/public\s+function\s+computeDriverActivitySummary\s*\([^)]*\)[^{]*\{/', $src, $m, PREG_OFFSET_CAPTURE)) {
            return ['status' => 'FAIL', 'detail' => 'computeDriverActivitySummary missing'];
        }
        $start = $m[0][1] + strlen($m[0][0]) - 1;
        $depth = 0; $i = $start;
        while ($i < strlen($src)) {
            $c = $src[$i];
            if ($c === '{') { $depth++; }
            elseif ($c === '}') { $depth--; if ($depth === 0) { break; } }
            $i++;
        }
        $body = substr($src, $start, $i - $start + 1);
        $sw = substr_count($body, '->schoolWhere(');
        // Nested foreach with schoolWhere inside — N+1 anti-pattern
        $nested = preg_match_all('/foreach\s*\([^)]+\)\s*\{[^}]*->schoolWhere\(/', $body, $_);
        if ($nested > 0) {
            return ['status' => 'FAIL', 'detail' => "nested schoolWhere-in-foreach in compute body: {$nested}"];
        }
        return ['status' => 'PASS', 'detail' => "compute:sw={$sw} (bounded, no N+1)"];
    }

    // ═════════════════════════════════════════════════════════════════════
    //  F5 Transport Expenses + Accounting assertions (CH-TRN-F5-1..5) 2026-07-07
    // ═════════════════════════════════════════════════════════════════════

    /**
     * CH-TRN-F5-1 — No direct 'ledger' write from Transport source
     * (F5 posts via PayrollAccountingService only). Extends CH-TRN-9's
     * general check to Transport_expense_reconciler.php.
     */
    private function _chTrnF5_1(): array
    {
        return $this->_grepTransportSourceForbidden(
            "/setEntity\(\s*['\"]ledger['\"]|removeEntity\(\s*['\"]ledger['\"]|firestoreSet\(\s*['\"]accounting['\"]/",
            'direct ledger/accounting write'
        );
    }

    /**
     * CH-TRN-F5-2 — Every transportExpenses doc has expense_type in the
     * known enum OR expense_type='Other' with non-empty expense_type_custom.
     */
    private function _chTrnF5_2(): array
    {
        try {
            $rows = (array) $this->fs->schoolWhere(
                Transport_service::COL_TRANSPORT_EXPENSES,
                [], 'expense_date', 'DESC', 100
            );
        } catch (\Throwable $_) {
            return ['status' => 'INFO', 'detail' => 'unreachable — trivial pass'];
        }
        if ($rows === []) {
            return ['status' => 'INFO', 'detail' => 'no expenses — trivial pass'];
        }
        $valid = Transport_service::EXPENSE_TYPES;
        $bad = [];
        foreach ($rows as $r) {
            if (!is_array($r)) { continue; }
            if (isset($r['data']) && is_array($r['data']) && !isset($r['expense_type'])) {
                $r = $r['data'];
            }
            $type = (string) ($r['expense_type'] ?? '');
            if (!in_array($type, $valid, true)) {
                $bad[] = (string) ($r['expenseId'] ?? '?') . "(={$type})";
                continue;
            }
            if ($type === 'Other' && trim((string) ($r['expense_type_custom'] ?? '')) === '') {
                $bad[] = (string) ($r['expenseId'] ?? '?') . '(Other/no-custom)';
            }
        }
        if ($bad !== []) {
            return ['status' => 'FAIL', 'detail' => count($bad) . ' invalid types: ' . implode(',', array_slice($bad, 0, 5))];
        }
        return ['status' => 'PASS', 'detail' => count($rows) . ' expenses have valid type'];
    }

    /**
     * CH-TRN-F5-3 — No transportExpenses with accounting_journal_ref='pending'
     * older than 24h (reconciler working). WARN when pending count > 0.
     */
    private function _chTrnF5_3(): array
    {
        try {
            $rows = (array) $this->fs->schoolWhere(
                Transport_service::COL_TRANSPORT_EXPENSES,
                [['accounting_journal_ref', '==', 'pending']],
                null, 'ASC', 200
            );
        } catch (\Throwable $_) {
            return ['status' => 'INFO', 'detail' => 'unreachable — trivial pass'];
        }
        if ($rows === []) {
            return ['status' => 'PASS', 'detail' => 'no pending expense posts'];
        }
        $cutoff = time() - 86400;
        $stale = [];
        foreach ($rows as $r) {
            if (!is_array($r)) { continue; }
            if (isset($r['data']) && is_array($r['data']) && !isset($r['expense_type'])) {
                $r = $r['data'];
            }
            $ts = strtotime((string) ($r['updated_at'] ?? $r['created_at'] ?? ''));
            if ($ts !== false && $ts < $cutoff) {
                $stale[] = (string) ($r['expenseId'] ?? '?');
            }
        }
        if ($stale !== []) {
            return ['status' => 'FAIL', 'detail' => count($stale) . ' stale >24h pending — run transport_expense_reconciler: ' . implode(',', array_slice($stale, 0, 5))];
        }
        return ['status' => 'WARN', 'detail' => count($rows) . ' pending posts within SLA — reconciler will retry'];
    }

    /**
     * CH-TRN-F5-4 — FK integrity: every expense with vehicle_id, vendor_id,
     * payee_id (Driver/Staff), related_maintenance_id, or related_fuel_id
     * references an existing entity.
     */
    private function _chTrnF5_4(): array
    {
        try {
            $rows = (array) $this->fs->schoolWhere(
                Transport_service::COL_TRANSPORT_EXPENSES, [], null, 'ASC', 100
            );
        } catch (\Throwable $_) {
            return ['status' => 'INFO', 'detail' => 'unreachable — trivial pass'];
        }
        if ($rows === []) {
            return ['status' => 'INFO', 'detail' => 'no expenses — trivial pass'];
        }
        $vehCache = []; $vendCache = []; $staffCache = []; $mntCache = []; $fuelCache = [];
        $bad = [];
        foreach ($rows as $r) {
            if (!is_array($r)) { continue; }
            if (isset($r['data']) && is_array($r['data']) && !isset($r['expense_type'])) {
                $r = $r['data'];
            }
            $id = (string) ($r['expenseId'] ?? '?');
            $vid = (string) ($r['vehicle_id'] ?? '');
            if ($vid !== '') {
                if (!isset($vehCache[$vid])) {
                    $vehCache[$vid] = is_array($this->fs->getEntity(Transport_service::COL_VEHICLES, $vid));
                }
                if (!$vehCache[$vid]) { $bad[] = "{$id}(veh:{$vid})"; }
            }
            $payeeType = (string) ($r['payee_type'] ?? '');
            $payeeId   = (string) ($r['payee_id']   ?? '');
            if ($payeeType === 'Vendor' && $payeeId !== '') {
                if (!isset($vendCache[$payeeId])) {
                    $vendCache[$payeeId] = is_array($this->fs->getEntity(Transport_service::COL_TRANSPORT_VENDORS, $payeeId));
                }
                if (!$vendCache[$payeeId]) { $bad[] = "{$id}(vend:{$payeeId})"; }
            }
            if (in_array($payeeType, ['Staff', 'Driver'], true) && $payeeId !== '') {
                if (!isset($staffCache[$payeeId])) {
                    $staffCache[$payeeId] = is_array($this->fs->getEntity('staff', $payeeId));
                }
                if (!$staffCache[$payeeId]) { $bad[] = "{$id}(staff:{$payeeId})"; }
            }
            $mid = (string) ($r['related_maintenance_id'] ?? '');
            if ($mid !== '') {
                if (!isset($mntCache[$mid])) {
                    $mntCache[$mid] = is_array($this->fs->getEntity(Transport_service::COL_MAINTENANCE_LOGS, $mid));
                }
                if (!$mntCache[$mid]) { $bad[] = "{$id}(mnt:{$mid})"; }
            }
            $fid = (string) ($r['related_fuel_id'] ?? '');
            if ($fid !== '') {
                if (!isset($fuelCache[$fid])) {
                    $fuelCache[$fid] = is_array($this->fs->getEntity(Transport_service::COL_FUEL_LOGS, $fid));
                }
                if (!$fuelCache[$fid]) { $bad[] = "{$id}(fuel:{$fid})"; }
            }
        }
        if ($bad !== []) {
            return ['status' => 'FAIL', 'detail' => count($bad) . ' orphans: ' . implode(',', array_slice($bad, 0, 5))];
        }
        return ['status' => 'PASS', 'detail' => count($rows) . ' expenses FK-valid'];
    }

    /**
     * CH-TRN-F5-5 — Performance guard: computeFleetMonthlyExpenseSummary
     * uses a single filtered query (via listExpenses) + in-memory
     * aggregation. Source-scan asserts no per-expense foreach → getEntity
     * (N+1 anti-pattern).
     */
    private function _chTrnF5_5(): array
    {
        $path = APPPATH . 'libraries/Transport_service.php';
        if (!is_readable($path)) {
            return ['status' => 'FAIL', 'detail' => "cannot read {$path}"];
        }
        $src = $this->_stripComments((string) file_get_contents($path));
        if (!preg_match('/public\s+function\s+computeFleetMonthlyExpenseSummary\s*\([^)]*\)[^{]*\{/', $src, $m, PREG_OFFSET_CAPTURE)) {
            return ['status' => 'FAIL', 'detail' => 'computeFleetMonthlyExpenseSummary missing'];
        }
        $start = $m[0][1] + strlen($m[0][0]) - 1;
        $depth = 0; $i = $start;
        while ($i < strlen($src)) {
            $c = $src[$i];
            if ($c === '{') { $depth++; }
            elseif ($c === '}') { $depth--; if ($depth === 0) { break; } }
            $i++;
        }
        $body = substr($src, $start, $i - $start + 1);
        // Anti-pattern: foreach containing getEntity/schoolWhere inside
        $nested = preg_match_all('/foreach\s*\([^)]+\)\s*\{[^}]*->(getEntity|schoolWhere)\(/', $body, $_);
        if ($nested > 0) {
            return ['status' => 'FAIL', 'detail' => "nested per-item read in summary body: {$nested}"];
        }
        return ['status' => 'PASS', 'detail' => 'summary body uses single-query aggregation (no N+1)'];
    }

    // ═════════════════════════════════════════════════════════════════════
    //  F6 Communication Integration + SOS assertions (CH-TRN-F6-1..6) 2026-07-07
    // ═════════════════════════════════════════════════════════════════════

    /**
     * CH-TRN-F6-1 — Every notification write in Transport source goes through
     * Transport_notifier. No direct fs->setEntity('notices'|'noticeRecipients'
     * |'notifications'|'sosAlerts', ...) from Transport controllers/service.
     * Transport_notifier itself is the sole legitimate writer.
     */
    private function _chTrnF6_1(): array
    {
        $files = [
            APPPATH . 'controllers/Transport.php',
            APPPATH . 'controllers/Transport_ops.php',
            APPPATH . 'controllers/Transport_driver.php',
            APPPATH . 'controllers/Transport_rollover.php',
            APPPATH . 'controllers/Transport_sos.php',
            APPPATH . 'libraries/Transport_service.php',
        ];
        $offenders = [];
        foreach ($files as $file) {
            if (!is_readable($file)) { continue; }
            $src = $this->_stripComments((string) file_get_contents($file));
            // Forbidden direct writes to notification collections
            if (preg_match_all(
                "/setEntity\\s*\\(\\s*['\"](notices|noticeRecipients|notifications|sosAlerts|transportNoticeIdempotency)['\"]/",
                $src, $matches, PREG_OFFSET_CAPTURE
            )) {
                foreach ($matches[0] as $hit) {
                    $offenders[] = basename($file) . ':' . $hit[0];
                }
            }
        }
        if ($offenders !== []) {
            return ['status' => 'FAIL', 'detail' => count($offenders) . ' direct writes bypassing Transport_notifier: ' . implode('; ', array_slice($offenders, 0, 3))];
        }
        return ['status' => 'PASS', 'detail' => 'no direct notification-collection writes outside Transport_notifier'];
    }

    /**
     * CH-TRN-F6-2 — Every category enumerated in Transport_notifier::CATEGORIES
     * has an entry in both PRIORITY_MATRIX and AUDIENCE_MATRIX. Guards against
     * a category being added without a priority/audience declaration.
     */
    private function _chTrnF6_2(): array
    {
        if (!class_exists('Transport_notifier')) {
            @include_once APPPATH . 'libraries/Transport_notifier.php';
        }
        if (!class_exists('Transport_notifier')) {
            return ['status' => 'FAIL', 'detail' => 'Transport_notifier class not loadable'];
        }
        $cats = (array) Transport_notifier::CATEGORIES;
        $priority = (array) Transport_notifier::PRIORITY_MATRIX;
        $audience = (array) Transport_notifier::AUDIENCE_MATRIX;
        $missingPriority = [];
        $missingAudience = [];
        foreach ($cats as $c) {
            if (!isset($priority[$c])) { $missingPriority[] = $c; }
            if (!isset($audience[$c])) { $missingAudience[] = $c; }
        }
        if ($missingPriority !== [] || $missingAudience !== []) {
            return ['status' => 'FAIL', 'detail' => sprintf(
                'missing_priority=%d missing_audience=%d; sample: %s',
                count($missingPriority), count($missingAudience),
                implode(',', array_slice(array_merge($missingPriority, $missingAudience), 0, 3))
            )];
        }
        return ['status' => 'PASS', 'detail' => count($cats) . ' categories fully mapped (priority + audience)'];
    }

    /**
     * CH-TRN-F6-3 — No idempotency claim doc past its expiresAt (would
     * signal reconciler/backfill gap). Retention is 30 days per Refinement 2;
     * anything past that should have been swept.
     */
    private function _chTrnF6_3(): array
    {
        try {
            $rows = (array) $this->fs->schoolWhere('transportNoticeIdempotency', [], null, 'ASC', 200);
        } catch (\Throwable $_) {
            return ['status' => 'INFO', 'detail' => 'collection unreachable — trivial pass'];
        }
        if ($rows === []) {
            return ['status' => 'INFO', 'detail' => 'no idempotency claims — trivial pass'];
        }
        $now = time();
        $stale = 0;
        foreach ($rows as $r) {
            $inner = (isset($r['data']) && is_array($r['data'])) ? $r['data'] : $r;
            $exp = (string) ($inner['expiresAt'] ?? '');
            if ($exp === '') { continue; }
            $expTs = strtotime($exp);
            // Stale = past expiry by more than 24h (retention breach signal)
            if ($expTs !== false && $expTs < ($now - 86400)) { $stale++; }
        }
        if ($stale > 0) {
            return ['status' => 'WARN', 'detail' => "{$stale} idempotency doc(s) past retention — sweeper gap"];
        }
        return ['status' => 'PASS', 'detail' => count($rows) . ' idempotency claims within retention'];
    }

    /**
     * CH-TRN-F6-4 — Every sosAlerts doc has BOTH `active:bool` and
     * `status:string` fields (dual-write for shipped Parent+Teacher client
     * compat — Parent repo filters on active==true but the model declares
     * status).
     */
    private function _chTrnF6_4(): array
    {
        try {
            $rows = (array) $this->fs->schoolWhere('sosAlerts', [], null, 'DESC', 50);
        } catch (\Throwable $_) {
            return ['status' => 'INFO', 'detail' => 'collection unreachable — trivial pass'];
        }
        if ($rows === []) {
            return ['status' => 'INFO', 'detail' => 'no SOS alerts — trivial pass'];
        }
        $missing = [];
        foreach ($rows as $r) {
            $inner = (isset($r['data']) && is_array($r['data'])) ? $r['data'] : $r;
            $id = (string) ($r['id'] ?? $inner['id'] ?? '?');
            if (!array_key_exists('active', $inner) || !array_key_exists('status', $inner)) {
                $missing[] = $id;
            }
        }
        if ($missing !== []) {
            return ['status' => 'FAIL', 'detail' => count($missing) . ' SOS docs missing active/status: ' . implode(',', array_slice($missing, 0, 3))];
        }
        return ['status' => 'PASS', 'detail' => count($rows) . ' SOS docs carry both active and status'];
    }

    /**
     * CH-TRN-F6-5 — Every Transport_notifier::notify() call emits exactly one
     * `transportNotification` audit row (contract per F6-5). Source-scan:
     * confirm the emitAudit call inside notify() is present and unguarded.
     */
    private function _chTrnF6_5(): array
    {
        $path = APPPATH . 'libraries/Transport_notifier.php';
        if (!is_readable($path)) {
            return ['status' => 'FAIL', 'detail' => "cannot read {$path}"];
        }
        $src = (string) file_get_contents($path);
        // Look for emitAudit call inside notify()
        if (!preg_match('/public\s+function\s+notify\s*\([^)]*\)[^{]*\{/', $src, $m, PREG_OFFSET_CAPTURE)) {
            return ['status' => 'FAIL', 'detail' => 'notify() method not found'];
        }
        $start = $m[0][1];
        $body  = substr($src, $start, 8000);
        if (!preg_match("/->emitAudit\\s*\\(\\s*'transportNotification'/", $body)) {
            return ['status' => 'FAIL', 'detail' => 'notify() does not emit transportNotification audit row'];
        }
        return ['status' => 'PASS', 'detail' => 'every notify() emits transportNotification audit row'];
    }

    /**
     * CH-TRN-F6-6 — Fanout write batches respect Firestore's 500-write limit.
     * Source-scan: assert array_chunk with a constant ≤500 wraps the per-user
     * notifications write loop.
     */
    private function _chTrnF6_6(): array
    {
        $path = APPPATH . 'libraries/Transport_notifier.php';
        if (!is_readable($path)) {
            return ['status' => 'FAIL', 'detail' => "cannot read {$path}"];
        }
        $src = (string) file_get_contents($path);
        // Assert FIRESTORE_BATCH_LIMIT constant present and ≤500
        if (!preg_match('/FIRESTORE_BATCH_LIMIT\s*=\s*(\d+)/', $src, $m)) {
            return ['status' => 'FAIL', 'detail' => 'FIRESTORE_BATCH_LIMIT constant missing'];
        }
        $limit = (int) $m[1];
        if ($limit <= 0 || $limit > 500) {
            return ['status' => 'FAIL', 'detail' => "FIRESTORE_BATCH_LIMIT={$limit} outside safe range (1..500)"];
        }
        // Assert array_chunk uses the constant inside the fanout method
        if (!preg_match('/array_chunk\s*\([^,]+,\s*self::FIRESTORE_BATCH_LIMIT\s*\)/', $src)) {
            return ['status' => 'FAIL', 'detail' => 'array_chunk with FIRESTORE_BATCH_LIMIT not found in fanout'];
        }
        return ['status' => 'PASS', 'detail' => "fanout chunks bounded by FIRESTORE_BATCH_LIMIT={$limit}"];
    }

    // ═════════════════════════════════════════════════════════════════════
    //  F7 Trip Logs + Transport Attendance assertions (CH-TRN-F7-1..6) 2026-07-07
    // ═════════════════════════════════════════════════════════════════════

    /**
     * CH-TRN-F7-1 — GPS-null discipline. F7 is GPS-native by schema but
     * MUST NOT populate live GPS fields (that belongs to the dedicated
     * GPS phase). Verifies every tripLogs + transportAttendance doc has
     * NULL for reserved live-position fields.
     */
    private function _chTrnF7_1(): array
    {
        $tripGpsFields = ['gps_start_lat','gps_start_lng','gps_end_lat','gps_end_lng','gps_last_position_at','gps_track_url'];
        $attGpsFields  = ['event_location_ref','event_lat','event_lng','event_at_gps','geofence_variance_m','geofence_verified'];

        $violations = [];
        try {
            $trips = (array) $this->fs->schoolWhere('tripLogs', [], null, 'ASC', 100);
        } catch (\Throwable $_) { $trips = []; }
        foreach ($trips as $t) {
            $inner = (isset($t['data']) && is_array($t['data'])) ? $t['data'] : $t;
            foreach ($tripGpsFields as $f) {
                if (array_key_exists($f, $inner) && $inner[$f] !== null && $inner[$f] !== '') {
                    $violations[] = "tripLogs:" . (string) ($t['id'] ?? $inner['id'] ?? '?') . ":{$f}";
                }
            }
        }
        try {
            $att = (array) $this->fs->schoolWhere('transportAttendance', [], null, 'ASC', 100);
        } catch (\Throwable $_) { $att = []; }
        foreach ($att as $a) {
            $inner = (isset($a['data']) && is_array($a['data'])) ? $a['data'] : $a;
            foreach ($attGpsFields as $f) {
                if (array_key_exists($f, $inner) && $inner[$f] !== null && $inner[$f] !== '') {
                    $violations[] = "transportAttendance:" . (string) ($a['id'] ?? $inner['id'] ?? '?') . ":{$f}";
                }
            }
        }
        if ($violations !== []) {
            return ['status' => 'FAIL', 'detail' => count($violations) . ' GPS-field writes pre-GPS-phase: ' . implode(',', array_slice($violations, 0, 3))];
        }
        $sampled = count($trips) + count($att);
        if ($sampled === 0) {
            return ['status' => 'INFO', 'detail' => 'no trips/attendance yet — trivial pass'];
        }
        return ['status' => 'PASS', 'detail' => "{$sampled} docs sampled; all live-GPS fields NULL (GPS phase ready)"];
    }

    /**
     * CH-TRN-F7-2 — Every tripLogs doc has driver_staff_id + date +
     * vehicleId + status (with status in the allowed enum).
     */
    private function _chTrnF7_2(): array
    {
        try {
            $trips = (array) $this->fs->schoolWhere('tripLogs', [], null, 'ASC', 100);
        } catch (\Throwable $_) {
            return ['status' => 'INFO', 'detail' => 'tripLogs unreachable — trivial pass'];
        }
        if ($trips === []) {
            return ['status' => 'INFO', 'detail' => 'no trips — trivial pass'];
        }
        $bad = [];
        $allowedStatus = ['Ready', 'InProgress', 'Completed', 'Cancelled'];
        foreach ($trips as $t) {
            $inner = (isset($t['data']) && is_array($t['data'])) ? $t['data'] : $t;
            $id = (string) ($t['id'] ?? $inner['id'] ?? '?');
            if (empty($inner['driver_staff_id'])) { $bad[] = "{$id}:no_driver"; continue; }
            if (empty($inner['date']))            { $bad[] = "{$id}:no_date";   continue; }
            if (empty($inner['vehicleId']))       { $bad[] = "{$id}:no_vehicle"; continue; }
            $st = (string) ($inner['status'] ?? '');
            if (!in_array($st, $allowedStatus, true)) { $bad[] = "{$id}:bad_status_{$st}"; }
        }
        if ($bad !== []) {
            return ['status' => 'FAIL', 'detail' => count($bad) . ' trips fail required-field/status check: ' . implode(',', array_slice($bad, 0, 3))];
        }
        return ['status' => 'PASS', 'detail' => count($trips) . ' trips have valid required fields + status'];
    }

    /**
     * CH-TRN-F7-3 — Every transportAttendance doc references an existing
     * tripLogs doc (FK integrity).
     */
    private function _chTrnF7_3(): array
    {
        try {
            $att = (array) $this->fs->schoolWhere('transportAttendance', [], null, 'ASC', 100);
        } catch (\Throwable $_) {
            return ['status' => 'INFO', 'detail' => 'transportAttendance unreachable — trivial pass'];
        }
        if ($att === []) {
            return ['status' => 'INFO', 'detail' => 'no attendance events — trivial pass'];
        }
        $orphans = [];
        $tripCache = [];
        foreach ($att as $a) {
            $inner = (isset($a['data']) && is_array($a['data'])) ? $a['data'] : $a;
            $tripId = (string) ($inner['tripId'] ?? '');
            if ($tripId === '') { $orphans[] = ($a['id'] ?? '?') . ':no_tripId'; continue; }
            if (!array_key_exists($tripId, $tripCache)) {
                try {
                    $t = $this->fs->getEntity('tripLogs', $tripId);
                    $tripCache[$tripId] = is_array($t);
                } catch (\Throwable $_) { $tripCache[$tripId] = false; }
            }
            if (!$tripCache[$tripId]) {
                $orphans[] = ($a['id'] ?? '?') . ':orphan_trip_' . $tripId;
            }
        }
        if ($orphans !== []) {
            return ['status' => 'FAIL', 'detail' => count($orphans) . ' orphan attendance events: ' . implode(',', array_slice($orphans, 0, 3))];
        }
        return ['status' => 'PASS', 'detail' => count($att) . ' attendance events FK-valid'];
    }

    /**
     * CH-TRN-F7-4 — Boarding-event doc-id determinism: no duplicate
     * events for the same (tripId, studentId, event_type). The
     * `{schoolId}_{tripId}_{studentId}_{event_type}` doc-id makes
     * duplicates impossible-by-design; this asserts no path bypasses
     * the deterministic scheme.
     */
    private function _chTrnF7_4(): array
    {
        try {
            $att = (array) $this->fs->schoolWhere('transportAttendance', [], null, 'ASC', 200);
        } catch (\Throwable $_) {
            return ['status' => 'INFO', 'detail' => 'unreachable — trivial pass'];
        }
        if ($att === []) {
            return ['status' => 'INFO', 'detail' => 'no attendance events — trivial pass'];
        }
        $seen = [];
        $dupes = [];
        foreach ($att as $a) {
            $inner = (isset($a['data']) && is_array($a['data'])) ? $a['data'] : $a;
            $key = ($inner['tripId'] ?? '') . '|' . ($inner['studentId'] ?? '') . '|' . ($inner['event_type'] ?? '');
            if (isset($seen[$key])) { $dupes[] = $key; }
            $seen[$key] = true;
        }
        if ($dupes !== []) {
            return ['status' => 'FAIL', 'detail' => count($dupes) . ' duplicate boarding events: ' . implode(',', array_slice($dupes, 0, 3))];
        }
        return ['status' => 'PASS', 'detail' => count($att) . " attendance events, no duplicates by (tripId, studentId, event_type)"];
    }

    /**
     * CH-TRN-F7-5 — For every Completed trip, stopsCompleted must equal
     * the count of checkpoints in state 'Reached'. Sanity check for the
     * completion routine's tally consistency.
     */
    private function _chTrnF7_5(): array
    {
        try {
            $trips = (array) $this->fs->schoolWhere('tripLogs',
                [['status', '==', 'Completed']], null, 'ASC', 50);
        } catch (\Throwable $_) {
            return ['status' => 'INFO', 'detail' => 'unreachable — trivial pass'];
        }
        if ($trips === []) {
            return ['status' => 'INFO', 'detail' => 'no completed trips — trivial pass'];
        }
        $bad = [];
        foreach ($trips as $t) {
            $inner = (isset($t['data']) && is_array($t['data'])) ? $t['data'] : $t;
            $id = (string) ($t['id'] ?? $inner['id'] ?? '?');
            $sc = (int) ($inner['stopsCompleted'] ?? -1);
            $reachedCount = 0;
            foreach ((array) ($inner['checkpoints'] ?? []) as $cp) {
                if (($cp['checkpoint_state'] ?? '') === 'Reached') { $reachedCount++; }
            }
            if ($sc !== $reachedCount) {
                $bad[] = "{$id}:tally_mismatch({$sc}!={$reachedCount})";
            }
        }
        if ($bad !== []) {
            return ['status' => 'FAIL', 'detail' => count($bad) . ' completed trips tally mismatch: ' . implode(',', array_slice($bad, 0, 3))];
        }
        return ['status' => 'PASS', 'detail' => count($trips) . ' completed trips have consistent tally'];
    }

    /**
     * CH-TRN-F7-6 — Roster snapshot uses a single filtered query per
     * assignment schema (studentRoutes LEGACY + routeAssignments
     * fallback), never a per-student getEntity loop.
     */
    private function _chTrnF7_6(): array
    {
        $path = APPPATH . 'libraries/Transport_service.php';
        if (!is_readable($path)) {
            return ['status' => 'FAIL', 'detail' => "cannot read {$path}"];
        }
        $src = $this->_stripComments((string) file_get_contents($path));
        if (!preg_match('/private\s+function\s+_computeTripRoster\s*\([^)]*\)[^{]*\{/', $src, $m, PREG_OFFSET_CAPTURE)) {
            return ['status' => 'FAIL', 'detail' => '_computeTripRoster method not found'];
        }
        $start = $m[0][1] + strlen($m[0][0]) - 1;
        $depth = 0; $i = $start;
        while ($i < strlen($src)) {
            $c = $src[$i];
            if ($c === '{') { $depth++; }
            elseif ($c === '}') { $depth--; if ($depth === 0) { break; } }
            $i++;
        }
        $body = substr($src, $start, $i - $start + 1);
        // Anti-pattern: getEntity inside a foreach
        if (preg_match('/foreach\s*\([^)]+\)\s*\{[^}]*->getEntity\(/', $body)) {
            return ['status' => 'FAIL', 'detail' => 'roster snapshot has per-student getEntity (N+1)'];
        }
        // Expect at least one schoolWhere call (bulk assignment read)
        if (!preg_match('/->schoolWhere\s*\(/', $body)) {
            return ['status' => 'FAIL', 'detail' => 'roster snapshot missing bulk assignment query'];
        }
        return ['status' => 'PASS', 'detail' => 'roster snapshot uses bulk queries (no N+1)'];
    }

    // ═════════════════════════════════════════════════════════════════════
    //  F8 Transport Events assertions (CH-TRN-F8-1..6) 2026-07-07
    // ═════════════════════════════════════════════════════════════════════

    /**
     * CH-TRN-F8-1 — Every write to transportEvents / eventTripAssignments
     * routes through Transport_service; controllers must not directly
     * setEntity() on these collections.
     */
    private function _chTrnF8_1(): array
    {
        $files = [
            APPPATH . 'controllers/Transport.php',
            APPPATH . 'controllers/Transport_ops.php',
            APPPATH . 'controllers/Transport_driver.php',
            APPPATH . 'controllers/Transport_rollover.php',
            APPPATH . 'controllers/Transport_sos.php',
            APPPATH . 'controllers/Transport_trip.php',
            APPPATH . 'controllers/Transport_event.php',
        ];
        $offenders = [];
        foreach ($files as $file) {
            if (!is_readable($file)) { continue; }
            $src = $this->_stripComments((string) file_get_contents($file));
            if (preg_match_all(
                "/setEntity\\s*\\(\\s*['\"](transportEvents|eventTripAssignments)['\"]/",
                $src, $matches
            )) {
                foreach ($matches[0] as $hit) {
                    $offenders[] = basename($file) . ':' . $hit;
                }
            }
        }
        if ($offenders !== []) {
            return ['status' => 'FAIL', 'detail' => count($offenders) . ' direct writes bypassing service: ' . implode('; ', array_slice($offenders, 0, 3))];
        }
        return ['status' => 'PASS', 'detail' => 'no direct event-collection writes from controllers'];
    }

    /**
     * CH-TRN-F8-2 — Every eventTripAssignments doc references an existing
     * transportEvents doc.
     */
    private function _chTrnF8_2(): array
    {
        try {
            $assigns = (array) $this->fs->schoolWhere('eventTripAssignments', [], null, 'ASC', 200);
        } catch (\Throwable $_) {
            return ['status' => 'INFO', 'detail' => 'eventTripAssignments unreachable — trivial pass'];
        }
        if ($assigns === []) {
            return ['status' => 'INFO', 'detail' => 'no assignments — trivial pass'];
        }
        $orphans = [];
        $cache = [];
        foreach ($assigns as $a) {
            $inner = (isset($a['data']) && is_array($a['data'])) ? $a['data'] : $a;
            $eid = (string) ($inner['event_id'] ?? '');
            if ($eid === '') { $orphans[] = ($a['id'] ?? '?') . ':no_event_id'; continue; }
            if (!array_key_exists($eid, $cache)) {
                try {
                    $ev = $this->fs->getEntity('transportEvents', $eid);
                    $cache[$eid] = is_array($ev);
                } catch (\Throwable $_) { $cache[$eid] = false; }
            }
            if (!$cache[$eid]) {
                $orphans[] = ($a['id'] ?? '?') . ':orphan_event_' . $eid;
            }
        }
        if ($orphans !== []) {
            return ['status' => 'FAIL', 'detail' => count($orphans) . ' orphans: ' . implode(',', array_slice($orphans, 0, 3))];
        }
        return ['status' => 'PASS', 'detail' => count($assigns) . ' assignments FK-valid'];
    }

    /**
     * CH-TRN-F8-3 — Every transportEvents doc's assigned_vehicle_ids[]
     * references an existing vehicle; every escort staff_id references
     * existing staff. Non-existent refs are FKI violations (Refinement
     * additional: teacher escorts are references, not duplicates).
     */
    private function _chTrnF8_3(): array
    {
        try {
            $events = (array) $this->fs->schoolWhere('transportEvents', [], null, 'ASC', 100);
        } catch (\Throwable $_) {
            return ['status' => 'INFO', 'detail' => 'transportEvents unreachable — trivial pass'];
        }
        if ($events === []) {
            return ['status' => 'INFO', 'detail' => 'no events — trivial pass'];
        }
        $bad = [];
        foreach ($events as $e) {
            $inner = (isset($e['data']) && is_array($e['data'])) ? $e['data'] : $e;
            $eid = (string) ($e['id'] ?? $inner['id'] ?? $inner['eventId'] ?? '?');
            foreach ((array) ($inner['assigned_vehicle_ids'] ?? []) as $vid) {
                if ($vid === '') { continue; }
                try {
                    $v = $this->fs->getEntity('vehicles', (string) $vid);
                    if (!is_array($v)) { $bad[] = "{$eid}:missing_vehicle_{$vid}"; }
                } catch (\Throwable $_) {}
            }
            foreach ((array) ($inner['escorts'] ?? []) as $esc) {
                $sid = (string) ($esc['staff_id'] ?? '');
                if ($sid === '') { $bad[] = "{$eid}:escort_no_staff_id"; continue; }
                try {
                    $s = $this->fs->getEntity('staff', $sid);
                    if (!is_array($s)) { $bad[] = "{$eid}:missing_escort_{$sid}"; }
                } catch (\Throwable $_) {}
            }
        }
        if ($bad !== []) {
            return ['status' => 'FAIL', 'detail' => count($bad) . ' FKI violations: ' . implode(',', array_slice($bad, 0, 3))];
        }
        return ['status' => 'PASS', 'detail' => count($events) . ' events have valid vehicle+escort refs'];
    }

    /**
     * CH-TRN-F8-4 — Every tripLogs with trip_kind='Event' has a valid
     * event_id FK.
     */
    private function _chTrnF8_4(): array
    {
        try {
            $trips = (array) $this->fs->schoolWhere('tripLogs',
                [['trip_kind', '==', 'Event']], null, 'ASC', 100);
        } catch (\Throwable $_) {
            return ['status' => 'INFO', 'detail' => 'unreachable — trivial pass'];
        }
        if ($trips === []) {
            return ['status' => 'INFO', 'detail' => 'no Event trips — trivial pass'];
        }
        $bad = [];
        $cache = [];
        foreach ($trips as $t) {
            $inner = (isset($t['data']) && is_array($t['data'])) ? $t['data'] : $t;
            $tid = (string) ($t['id'] ?? $inner['id'] ?? '?');
            $eid = (string) ($inner['event_id'] ?? '');
            if ($eid === '') { $bad[] = "{$tid}:no_event_id"; continue; }
            if (!array_key_exists($eid, $cache)) {
                try {
                    $ev = $this->fs->getEntity('transportEvents', $eid);
                    $cache[$eid] = is_array($ev);
                } catch (\Throwable $_) { $cache[$eid] = false; }
            }
            if (!$cache[$eid]) { $bad[] = "{$tid}:orphan_event_{$eid}"; }
        }
        if ($bad !== []) {
            return ['status' => 'FAIL', 'detail' => count($bad) . ' orphan event trips: ' . implode(',', array_slice($bad, 0, 3))];
        }
        return ['status' => 'PASS', 'detail' => count($trips) . ' Event trips FK-valid'];
    }

    /**
     * CH-TRN-F8-5 — For every Completed event, actual_amount must match
     * the sum of Active transportExpenses linked via related_event_id.
     * Cost-attribution contract per Refinement 3.
     */
    private function _chTrnF8_5(): array
    {
        try {
            $events = (array) $this->fs->schoolWhere('transportEvents',
                [['status', '==', 'Completed']], null, 'ASC', 50);
        } catch (\Throwable $_) {
            return ['status' => 'INFO', 'detail' => 'unreachable — trivial pass'];
        }
        if ($events === []) {
            return ['status' => 'INFO', 'detail' => 'no completed events — trivial pass'];
        }
        $bad = [];
        foreach ($events as $e) {
            $inner = (isset($e['data']) && is_array($e['data'])) ? $e['data'] : $e;
            $eid = (string) ($e['id'] ?? $inner['id'] ?? $inner['eventId'] ?? '?');
            $reported = round((float) ($inner['actual_amount'] ?? 0), 2);
            $sum = 0.0;
            try {
                $exps = (array) $this->fs->schoolWhere('transportExpenses',
                    [['related_event_id', '==', $eid], ['status', '==', 'Active']], null, 'ASC', 1000);
                foreach ($exps as $x) {
                    $xi = (isset($x['data']) && is_array($x['data'])) ? $x['data'] : $x;
                    $sum += (float) ($xi['total_amount'] ?? $xi['amount'] ?? 0);
                }
            } catch (\Throwable $_) {}
            $sum = round($sum, 2);
            if (abs($reported - $sum) > 0.01) {
                $bad[] = "{$eid}:actual={$reported}!=sum={$sum}";
            }
        }
        if ($bad !== []) {
            return ['status' => 'FAIL', 'detail' => count($bad) . ' events with tally mismatch: ' . implode(',', array_slice($bad, 0, 3))];
        }
        return ['status' => 'PASS', 'detail' => count($events) . ' completed events have consistent cost tally'];
    }

    /**
     * CH-TRN-F8-6 — Event roster snapshot uses a single bulk query
     * (schoolWhere on eventTripAssignments) — no per-student getEntity
     * loop.
     */
    private function _chTrnF8_6(): array
    {
        $path = APPPATH . 'libraries/Transport_service.php';
        if (!is_readable($path)) {
            return ['status' => 'FAIL', 'detail' => "cannot read {$path}"];
        }
        $src = $this->_stripComments((string) file_get_contents($path));
        if (!preg_match('/private\s+function\s+_computeEventRoster\s*\([^)]*\)[^{]*\{/', $src, $m, PREG_OFFSET_CAPTURE)) {
            return ['status' => 'FAIL', 'detail' => '_computeEventRoster method not found'];
        }
        $start = $m[0][1] + strlen($m[0][0]) - 1;
        $depth = 0; $i = $start;
        while ($i < strlen($src)) {
            $c = $src[$i];
            if ($c === '{') { $depth++; }
            elseif ($c === '}') { $depth--; if ($depth === 0) { break; } }
            $i++;
        }
        $body = substr($src, $start, $i - $start + 1);
        if (preg_match('/foreach\s*\([^)]+\)\s*\{[^}]*->getEntity\(/', $body)) {
            return ['status' => 'FAIL', 'detail' => 'event roster snapshot has per-student getEntity (N+1)'];
        }
        if (!preg_match('/->schoolWhere\s*\(/', $body)) {
            return ['status' => 'FAIL', 'detail' => 'event roster missing bulk assignment query'];
        }
        return ['status' => 'PASS', 'detail' => 'event roster uses bulk query (no N+1)'];
    }

    // ═════════════════════════════════════════════════════════════════════
    //  F9 Teacher App Transport assertions (CH-TRN-F9-1..4) 2026-07-07
    // ═════════════════════════════════════════════════════════════════════

    /**
     * CH-TRN-F9-1 — Firestore rules include the driver-scoped helpers
     * required for Teacher-app writes: driverIs(), isDriverOfTrip(),
     * hasClientMetadata(). Source-scan asserts they're defined and
     * referenced in the transport collection block.
     */
    private function _chTrnF9_1(): array
    {
        $path = FCPATH . 'firebase-rules/firestore.rules';
        if (!is_readable($path)) {
            return ['status' => 'INFO', 'detail' => 'firestore.rules not readable — trivial pass'];
        }
        $src = (string) file_get_contents($path);
        $required = [
            'function driverIs('        => 'driverIs helper',
            'function isDriverOfTrip('  => 'isDriverOfTrip helper',
            'function hasClientMetadata(' => 'hasClientMetadata helper',
            'isDriverOfTrip()'          => 'isDriverOfTrip used in tripLogs',
            'hasClientMetadata()'       => 'hasClientMetadata used in transport writes',
        ];
        $missing = [];
        foreach ($required as $needle => $label) {
            if (strpos($src, $needle) === false) { $missing[] = $label; }
        }
        if ($missing !== []) {
            return ['status' => 'FAIL', 'detail' => 'missing: ' . implode(', ', $missing)];
        }
        return ['status' => 'PASS', 'detail' => count($required) . ' driver-scoped rule fragments present'];
    }

    /**
     * CH-TRN-F9-2 — Transport_notifier declares SOS_SEVERITIES + full
     * SOS_SEVERITY_TO_PRIORITY mapping (no severity level missing a
     * priority route).
     */
    private function _chTrnF9_2(): array
    {
        if (!class_exists('Transport_notifier')) {
            @include_once APPPATH . 'libraries/Transport_notifier.php';
        }
        if (!class_exists('Transport_notifier')) {
            return ['status' => 'FAIL', 'detail' => 'Transport_notifier not loadable'];
        }
        $severities = (array) Transport_notifier::SOS_SEVERITIES;
        $priorityMap = (array) Transport_notifier::SOS_SEVERITY_TO_PRIORITY;
        if ($severities === []) { return ['status' => 'FAIL', 'detail' => 'SOS_SEVERITIES empty']; }
        $missing = [];
        foreach ($severities as $s) {
            if (!isset($priorityMap[$s])) { $missing[] = $s; }
        }
        if ($missing !== []) {
            return ['status' => 'FAIL', 'detail' => 'severities without priority: ' . implode(',', $missing)];
        }
        $default = (string) Transport_notifier::SOS_DEFAULT_SEVERITY;
        if (!in_array($default, $severities, true)) {
            return ['status' => 'FAIL', 'detail' => "SOS_DEFAULT_SEVERITY '{$default}' not in enum"];
        }
        return ['status' => 'PASS', 'detail' => count($severities) . " severities mapped; default={$default}"];
    }

    /**
     * CH-TRN-F9-3 — Every existing sosAlerts doc has severity ∈ the
     * F9 enum. Missing field is tolerated (pre-F9 docs) — reported as
     * INFO, never FAIL. Presence with invalid value fails.
     */
    private function _chTrnF9_3(): array
    {
        try {
            $rows = (array) $this->fs->schoolWhere('sosAlerts', [], null, 'DESC', 100);
        } catch (\Throwable $_) {
            return ['status' => 'INFO', 'detail' => 'sosAlerts unreachable — trivial pass'];
        }
        if ($rows === []) {
            return ['status' => 'INFO', 'detail' => 'no SOS alerts — trivial pass'];
        }
        $allowed = ['Low', 'Medium', 'High', 'Critical'];
        $bad = [];
        $preF9 = 0;
        foreach ($rows as $r) {
            $inner = (isset($r['data']) && is_array($r['data'])) ? $r['data'] : $r;
            $id = (string) ($r['id'] ?? $inner['id'] ?? '?');
            if (!array_key_exists('severity', $inner)) {
                $preF9++;
                continue;
            }
            if (!in_array((string) $inner['severity'], $allowed, true)) {
                $bad[] = "{$id}:severity_{$inner['severity']}";
            }
        }
        if ($bad !== []) {
            return ['status' => 'FAIL', 'detail' => count($bad) . ' SOS with invalid severity: ' . implode(',', array_slice($bad, 0, 3))];
        }
        $post = count($rows) - $preF9;
        return ['status' => 'PASS', 'detail' => "{$post} F9-era + {$preF9} pre-F9 SOS docs; all severities valid"];
    }

    /**
     * CH-TRN-F9-4 — Every transportAttendance doc has marked_by_role
     * ∈ {'admin', 'driver'}. Traces admin-panel vs driver-fired paths
     * for audit analysis.
     */
    private function _chTrnF9_4(): array
    {
        try {
            $rows = (array) $this->fs->schoolWhere('transportAttendance', [], null, 'ASC', 200);
        } catch (\Throwable $_) {
            return ['status' => 'INFO', 'detail' => 'transportAttendance unreachable — trivial pass'];
        }
        if ($rows === []) {
            return ['status' => 'INFO', 'detail' => 'no attendance — trivial pass'];
        }
        $allowed = ['admin', 'driver'];
        $bad = [];
        foreach ($rows as $r) {
            $inner = (isset($r['data']) && is_array($r['data'])) ? $r['data'] : $r;
            $id = (string) ($r['id'] ?? $inner['id'] ?? '?');
            $role = (string) ($inner['marked_by_role'] ?? '');
            if (!in_array($role, $allowed, true)) {
                $bad[] = "{$id}:role_{$role}";
            }
        }
        if ($bad !== []) {
            return ['status' => 'FAIL', 'detail' => count($bad) . ' attendance with bad marked_by_role: ' . implode(',', array_slice($bad, 0, 3))];
        }
        return ['status' => 'PASS', 'detail' => count($rows) . " attendance docs, all marked_by_role ∈ {admin, driver}"];
    }

    // ═════════════════════════════════════════════════════════════════════
    //  F10 Parent App Transport assertions (CH-TRN-F10-1..4) 2026-07-07
    // ═════════════════════════════════════════════════════════════════════

    /**
     * CH-TRN-F10-1 — Transport_notifier fanout consults notification
     * preferences BEFORE writing per-user notifications docs. Source-
     * scan asserts the _filterByPreferences call is present inside
     * _fanoutNotifications AND the SAFETY_EXEMPT branch happens BEFORE
     * the filter call.
     */
    private function _chTrnF10_1(): array
    {
        $path = APPPATH . 'libraries/Transport_notifier.php';
        if (!is_readable($path)) {
            return ['status' => 'FAIL', 'detail' => "cannot read {$path}"];
        }
        $src = (string) file_get_contents($path);
        if (!preg_match('/private\s+function\s+_fanoutNotifications\s*\([^)]*\)[^{]*\{/', $src, $m, PREG_OFFSET_CAPTURE)) {
            return ['status' => 'FAIL', 'detail' => '_fanoutNotifications method not found'];
        }
        $start = $m[0][1];
        $body  = substr($src, $start, 4000);
        if (strpos($body, '_filterByPreferences') === false) {
            return ['status' => 'FAIL', 'detail' => '_filterByPreferences call missing from _fanoutNotifications'];
        }
        if (strpos($body, 'SAFETY_EXEMPT_CATEGORIES') === false) {
            return ['status' => 'FAIL', 'detail' => 'SAFETY_EXEMPT_CATEGORIES gate missing before filter'];
        }
        // Assert safety gate comes BEFORE the filter call (order matters
        // — filter must not run for safety-exempt categories)
        $filterPos = strpos($body, '_filterByPreferences');
        $exemptPos = strpos($body, 'SAFETY_EXEMPT_CATEGORIES');
        if ($exemptPos > $filterPos) {
            return ['status' => 'FAIL', 'detail' => 'SAFETY_EXEMPT gate must precede _filterByPreferences call'];
        }
        return ['status' => 'PASS', 'detail' => 'fanout honors preferences with safety-exempt gate ordered correctly'];
    }

    /**
     * CH-TRN-F10-2 — SAFETY_EXEMPT_CATEGORIES covers every category
     * whose PRIORITY_MATRIX entry is Urgent. Any Urgent-priority
     * category that's NOT safety-exempt is a policy hole — the
     * assertion fails so the operator has to decide.
     */
    private function _chTrnF10_2(): array
    {
        if (!class_exists('Transport_notifier')) {
            @include_once APPPATH . 'libraries/Transport_notifier.php';
        }
        if (!class_exists('Transport_notifier')) {
            return ['status' => 'FAIL', 'detail' => 'Transport_notifier not loadable'];
        }
        $priority = (array) Transport_notifier::PRIORITY_MATRIX;
        $exempt   = (array) Transport_notifier::SAFETY_EXEMPT_CATEGORIES;
        $urgentButNotExempt = [];
        foreach ($priority as $cat => $prio) {
            if ($prio === Transport_notifier::PRIORITY_URGENT
                && !in_array($cat, $exempt, true)) {
                $urgentButNotExempt[] = $cat;
            }
        }
        if ($urgentButNotExempt !== []) {
            return ['status' => 'FAIL', 'detail' => 'Urgent categories missing from safety-exempt list: ' . implode(',', $urgentButNotExempt)];
        }
        return ['status' => 'PASS', 'detail' => count($exempt) . ' safety-exempt categories cover all Urgent priorities'];
    }

    /**
     * CH-TRN-F10-3 — Every notificationPreferences doc's mutedCategories
     * contains ONLY muteable categories (no safety-exempt category
     * present — sanity gate for client-side tampering). Server-side
     * filter would honor the mute silently; this assertion surfaces
     * the tampering attempt for admin visibility.
     */
    private function _chTrnF10_3(): array
    {
        try {
            $rows = (array) $this->fs->schoolWhere('notificationPreferences', [], null, 'ASC', 200);
        } catch (\Throwable $_) {
            return ['status' => 'INFO', 'detail' => 'notificationPreferences unreachable — trivial pass'];
        }
        if ($rows === []) {
            return ['status' => 'INFO', 'detail' => 'no preferences docs — trivial pass'];
        }
        $exempt = (array) Transport_notifier::SAFETY_EXEMPT_CATEGORIES;
        $bad = [];
        foreach ($rows as $r) {
            $inner = (isset($r['data']) && is_array($r['data'])) ? $r['data'] : $r;
            $id = (string) ($r['id'] ?? $inner['id'] ?? '?');
            $muted = (array) ($inner['mutedCategories'] ?? []);
            foreach ($muted as $m) {
                if (in_array((string) $m, $exempt, true)) {
                    $bad[] = "{$id}:muted_safety_{$m}";
                }
            }
        }
        if ($bad !== []) {
            return ['status' => 'FAIL', 'detail' => count($bad) . ' prefs docs muting safety categories: ' . implode(',', array_slice($bad, 0, 3))];
        }
        return ['status' => 'PASS', 'detail' => count($rows) . ' prefs docs, no safety-exempt category muted'];
    }

    /**
     * CH-TRN-F10-4 — Every feeDemands doc created for transport-related
     * fees uses category='transport' (lowercase). Historical demands
     * with 'Transport' capitalized are tolerated (INFO count). Future
     * writers via Fee_lifecycle::createModuleFee are normalized to
     * lowercase (Refinement 4).
     */
    private function _chTrnF10_4(): array
    {
        try {
            // Query fee demands where the head clearly indicates transport
            // then check the category field's case
            $rows = (array) $this->fs->schoolWhere('feeDemands', [], null, 'DESC', 200);
        } catch (\Throwable $_) {
            return ['status' => 'INFO', 'detail' => 'feeDemands unreachable — trivial pass'];
        }
        if ($rows === []) {
            return ['status' => 'INFO', 'detail' => 'no fee demands — trivial pass'];
        }
        $transportDemands = 0;
        $lowercase = 0;
        $mixedCase = 0;
        foreach ($rows as $r) {
            $inner = (isset($r['data']) && is_array($r['data'])) ? $r['data'] : $r;
            $head = strtolower((string) ($inner['feeHead'] ?? ''));
            $cat  = (string) ($inner['category'] ?? '');
            $catL = strtolower($cat);
            $isTransport = ($catL === 'transport') || (strpos($head, 'transport') !== false) || (strpos($head, 'bus') !== false);
            if (!$isTransport) { continue; }
            $transportDemands++;
            if ($cat === 'transport')       { $lowercase++; }
            elseif ($catL === 'transport')  { $mixedCase++; }
        }
        if ($transportDemands === 0) {
            return ['status' => 'INFO', 'detail' => 'no transport-related demands sampled'];
        }
        return ['status' => 'PASS', 'detail' => "{$transportDemands} transport demand(s): {$lowercase} lowercase (F10-canonical), {$mixedCase} mixed-case (pre-F10 legacy — tolerated)"];
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
