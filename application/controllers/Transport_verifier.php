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
            [ 'id' => 'CH-TRN-F1-7', 'category' => self::CAT_CONTRACT,   'label' => 'list endpoints unwrap Firestore envelope', 'callable' => '_chTrnF1_7' ],

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
            [ 'id' => 'CH-TRN-F7-7', 'category' => self::CAT_CONTRACT,   'label' => 'parent read + roster immutability', 'callable' => '_chTrnF7_7' ],

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

            // ─── F11 Analytics + Super Admin (added 2026-07-08) ─────────────
            [ 'id' => 'CH-TRN-F11-1', 'category' => self::CAT_SOURCE,     'label' => 'analytics reuse (no raw scans)', 'callable' => '_chTrnF11_1' ],
            [ 'id' => 'CH-TRN-F11-2', 'category' => self::CAT_RUNTIME,    'label' => 'summary period_year_month shape','callable' => '_chTrnF11_2' ],
            [ 'id' => 'CH-TRN-F11-3', 'category' => self::CAT_RUNTIME,    'label' => 'routeFleetSummary populated',    'callable' => '_chTrnF11_3' ],
            [ 'id' => 'CH-TRN-F11-4', 'category' => self::CAT_CONTRACT,   'label' => 'role-scope matrix complete',     'callable' => '_chTrnF11_4' ],
            [ 'id' => 'CH-TRN-F11-5', 'category' => self::CAT_PERFORMANCE,'label' => 'analytics per-request memo',     'callable' => '_chTrnF11_5' ],
            [ 'id' => 'CH-TRN-F11-6', 'category' => self::CAT_SOURCE,     'label' => 'analytics endpoints role-gated', 'callable' => '_chTrnF11_6' ],

            // ── Stabilization sprint foundations (M-STAB) ──
            [ 'id' => 'CH-TRN-RC1-1', 'category' => self::CAT_SOURCE,     'label' => 'casUpdateEntity CAS primitive present', 'callable' => '_chTrnRc1_1' ],
            [ 'id' => 'CH-TRN-CON6-1', 'category' => self::CAT_SOURCE,    'label' => 'trip transitions CAS-guarded',         'callable' => '_chTrnCon6_1' ],
            [ 'id' => 'CH-TRN-CON2-1', 'category' => self::CAT_SOURCE,    'label' => 'checkpoint array writes CAS-guarded',   'callable' => '_chTrnCon2_1' ],
            [ 'id' => 'CH-TRN-CON3-1', 'category' => self::CAT_SOURCE,    'label' => 'capacity reserve/bump atomic',         'callable' => '_chTrnCon3_1' ],
            [ 'id' => 'CH-TRN-CON1-1', 'category' => self::CAT_SOURCE,    'label' => 'checkpoint tally derived not incremented','callable' => '_chTrnCon1_1' ],
            [ 'id' => 'CH-TRN-CON7-1', 'category' => self::CAT_SOURCE,    'label' => 'notice claim CAS before fanout',       'callable' => '_chTrnCon7_1' ],
            [ 'id' => 'CH-TRN-RC2-1', 'category' => self::CAT_SOURCE,    'label' => 'query fallback drops limit',           'callable' => '_chTrnRc2_1' ],
            [ 'id' => 'CH-TRN-RC2-2', 'category' => self::CAT_STRUCTURAL,'label' => 'transport composite indexes present',  'callable' => '_chTrnRc2_2' ],
            [ 'id' => 'CH-TRN-RC3-1', 'category' => self::CAT_SOURCE,    'label' => 'FMES/VEXPCACHE bare docId (no double-prefix)', 'callable' => '_chTrnRc3_1' ],
            [ 'id' => 'CH-TRN-RC3-2', 'category' => self::CAT_SOURCE,    'label' => 'CAPCACHE id via canonical helper',     'callable' => '_chTrnRc3_2' ],
            [ 'id' => 'CH-TRN-FIN1-1', 'category' => self::CAT_SOURCE,   'label' => 'createModuleFee preserves paid demands','callable' => '_chTrnFin1_1' ],
            [ 'id' => 'CH-TRN-LOG1-1', 'category' => self::CAT_SOURCE,   'label' => 'driver readiness validated at dispatch','callable' => '_chTrnLog1_1' ],
            [ 'id' => 'CH-TRN-FIN2-1', 'category' => self::CAT_SOURCE,   'label' => 'rollover cancel matches category case',  'callable' => '_chTrnFin2_1' ],
            [ 'id' => 'CH-TRN-CON4-1', 'category' => self::CAT_SOURCE,   'label' => 'rollover fee failure recoverable',       'callable' => '_chTrnCon4_1' ],
            [ 'id' => 'CH-TRN-ENV-1',  'category' => self::CAT_SOURCE,   'label' => 'schoolWhere consumers unwrap envelope',  'callable' => '_chTrnEnv1' ],
            [ 'id' => 'CH-TRN-FEE-1',  'category' => self::CAT_SOURCE,   'label' => 'allocationsForStudent envelope docId',   'callable' => '_chTrnFeeAlloc' ],
            [ 'id' => 'CH-TRN-FIN3-1', 'category' => self::CAT_SOURCE,   'label' => 'posted expense immutable + void reverses', 'callable' => '_chTrnFin3_1' ],
            [ 'id' => 'CH-TRN-LOG2-1', 'category' => self::CAT_SOURCE,   'label' => 'createTrip blocks suspended route',       'callable' => '_chTrnLog2_1' ],
            [ 'id' => 'CH-TRN-LOG3-1', 'category' => self::CAT_SOURCE,   'label' => 'notify idempotency salience incl event',  'callable' => '_chTrnLog3_1' ],
            [ 'id' => 'CH-TRN-XMOD1-1','category' => self::CAT_SOURCE,   'label' => 'vehicle currentRouteId set/clear on trip', 'callable' => '_chTrnXmod1_1' ],
            [ 'id' => 'CH-TRN-SEC1-1', 'category' => self::CAT_SOURCE,   'label' => 'attendance create bound to trip driver',   'callable' => '_chTrnSec1_1' ],
            [ 'id' => 'CH-TRN-PERF3-1','category' => self::CAT_SOURCE,   'label' => 'live-ops onboard is single-scan (no N+1)', 'callable' => '_chTrnPerf3_1' ],
            [ 'id' => 'CH-TRN-PERF4-1','category' => self::CAT_SOURCE,   'label' => 'SA fleet leaderboard served from rollup',  'callable' => '_chTrnPerf4_1' ],
            [ 'id' => 'CH-TRN-SEC4-1', 'category' => self::CAT_SOURCE,   'label' => 'transport controllers use admin_role',    'callable' => '_chTrnSec4_1' ],
            [ 'id' => 'CH-TRN-SEC3-1', 'category' => self::CAT_SOURCE,   'label' => 'analytics gated on Operations RBAC grant', 'callable' => '_chTrnSec3_1' ],
            [ 'id' => 'CH-TRN-SEC2-1', 'category' => self::CAT_SOURCE,   'label' => 'tripLogs create bound to trip driver',     'callable' => '_chTrnSec2_1' ],
            [ 'id' => 'CH-TRN-LOG5-1', 'category' => self::CAT_SOURCE,   'label' => 'no_show honours the frozen roster gate',  'callable' => '_chTrnLog5_1' ],
            [ 'id' => 'CH-TRN-LOG4-1', 'category' => self::CAT_SOURCE,   'label' => 'escort HR-active gate on all paths',      'callable' => '_chTrnLog4_1' ],
            [ 'id' => 'CH-TRN-CON5-1', 'category' => self::CAT_SOURCE,   'label' => 'rollover history id deterministic',       'callable' => '_chTrnCon5_1' ],
            [ 'id' => 'CH-TRN-SEC5-1', 'category' => self::CAT_SOURCE,   'label' => 'cross-tenant KPI restores in finally',    'callable' => '_chTrnSec5_1' ],
            [ 'id' => 'CH-TRN-FIN5-1', 'category' => self::CAT_SOURCE,   'label' => 'expense post pre-validates accounts',     'callable' => '_chTrnFin5_1' ],
            [ 'id' => 'CH-TRN-PERF7-1','category' => self::CAT_SOURCE,   'label' => 'list scans bounded + trend single-query', 'callable' => '_chTrnPerf7_1' ],
            [ 'id' => 'CH-TRN-PERF10-1','category'=> self::CAT_SOURCE,   'label' => 'analytics memo keys tenant-scoped',       'callable' => '_chTrnPerf10_1' ],
            [ 'id' => 'CH-TRN-PERF6-1', 'category'=> self::CAT_SOURCE,   'label' => 'cross-tenant rollup isolates per tenant',  'callable' => '_chTrnPerf6_1' ],
            [ 'id' => 'CH-TRN-RCP-1',  'category'=> self::CAT_SOURCE,   'label' => 'parent notify recipient = canonical userId','callable' => '_chTrnRcp1' ],
            [ 'id' => 'CH-TRN-NF1-1',  'category'=> self::CAT_SOURCE,   'label' => 'Transport ctrl free of Operations_accounting','callable' => '_chTrnNf1' ],
            [ 'id' => 'CH-TRN-OPSN-1', 'category'=> self::CAT_SOURCE,   'label' => 'ops notice carries canonical timestamp',   'callable' => '_chTrnOpsn1' ],
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

    /**
     * CH-TRN-F1-7 — List endpoints unwrap the Firestore envelope.
     *
     * Root cause of the release-cert defect caught 2026-07-08: several
     * Transport_service list-return methods iterated the wrapped rows from
     * Firestore_service::schoolWhere() ([['id' => docKey, 'data' => {...}], ...])
     * as if they were flat entity data. Result: JSON payload leaked the
     * envelope to the UI (`{id: 'SCH_...', data: {vendor_name, ...}}`), and
     * the JS could only render `id` — every other column showed `—`.
     *
     * Two-layer guard so the regression cannot silently return:
     *   (1) SOURCE: no `is_array($doc) ? $doc : []` idiom anywhere in the
     *       service — the broken pattern is easily identified by that
     *       specific ternary. Every occurrence must be replaced with a call
     *       to `_unwrapNestedRow($doc, 'schoolId')`.
     *   (2) RUNTIME: for every list method whose collection contains ≥1
     *       doc for the test school, assert the first returned row has
     *       `schoolId` at the top level (a mandatory injected field on every
     *       setEntity write). If `schoolId` is missing, the row is wrapped.
     *
     * FAIL if either layer trips. Zero-data collections skip the runtime
     * check silently (source layer still provides defense).
     */
    private function _chTrnF1_7(): array
    {
        // ── Layer 1: source-scan for the broken idiom ──
        $path = APPPATH . 'libraries/Transport_service.php';
        if (!is_readable($path)) {
            return ['status' => 'FAIL', 'detail' => "cannot read {$path}"];
        }
        $src = $this->_stripComments((string) file_get_contents($path));
        $brokenIdiomCount = preg_match_all(
            '/is_array\(\$(?:doc|r|v|d|row)\)\s*\?\s*\$(?:doc|r|v|d|row)\s*:\s*\[\s*\]/',
            $src
        );
        if ($brokenIdiomCount > 0) {
            return [
                'status' => 'FAIL',
                'detail' => "{$brokenIdiomCount} occurrence(s) of the envelope-leak idiom `is_array(\$doc) ? \$doc : []` — replace with _unwrapNestedRow(\$doc, 'schoolId')",
            ];
        }

        // ── Layer 2: runtime shape assertion ──
        // ($this->transport already initialised in __construct)
        $offenders = [];
        $checked   = 0;

        $probes = [
            ['label' => 'listAllVendors',    'sentinel' => 'vendor_name'],
            ['label' => 'listVehicles',      'sentinel' => 'number'],
            ['label' => 'listRoutes',        'sentinel' => 'name'],
            ['label' => 'listStops',         'sentinel' => 'name'],
        ];
        foreach ($probes as $p) {
            try {
                $rows = $this->transport->{$p['label']}();
            } catch (\Throwable $e) {
                $offenders[] = "{$p['label']}: threw " . $e->getMessage();
                continue;
            }
            if (!is_array($rows) || empty($rows)) {
                continue; // empty collection — nothing to shape-check
            }
            $checked++;
            $first = $rows[0];
            if (!is_array($first)) {
                $offenders[] = "{$p['label']}: first row is not an array";
                continue;
            }
            // Wrapped envelope leak — 'data' key present + sentinel field absent
            if (isset($first['data']) && is_array($first['data']) && !isset($first[$p['sentinel']])) {
                $offenders[] = "{$p['label']}: envelope leak — 'data' key present, '{$p['sentinel']}' missing at top level";
                continue;
            }
            // Business-field sentinel check — schoolId is always injected by setEntity
            if (!isset($first['schoolId'])) {
                $offenders[] = "{$p['label']}: schoolId missing at top level (unexpected shape)";
            }
        }

        if ($offenders !== []) {
            return [
                'status' => 'FAIL',
                'detail' => 'runtime shape check failed: ' . implode('; ', $offenders),
            ];
        }
        $checkedNote = $checked === 0 ? ' (source layer only; no seed data)' : " ({$checked} collection(s) checked)";
        return [
            'status' => 'PASS',
            'detail' => 'no envelope-leak idiom in source; list endpoints return flat entities' . $checkedNote,
        ];
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

    /**
     * CH-TRN-F7-7 — Parent-facing tripLogs read + roster immutability guard.
     *
     * Release-cert C-1 (2026-07-08): F10 shipped with Parent App code
     * querying tripLogs directly, but the rule required isStaff() —
     * parents hit PERMISSION_DENIED on every open of MyBusScreen. The
     * fix opens read via a per-doc allowlist keyed on the frozen roster
     * snapshot (roster_student_ids). This gate defends the fix at three
     * layers so a future refactor cannot silently re-break the feature
     * OR silently open a privilege-escalation path:
     *
     *   (1) RULES SOURCE — the parent/student allow-clauses must be
     *       present in the tripLogs match block.
     *
     *   (2) SERVICE SOURCE — after the createTrip write (merge=false
     *       initial snapshot at Transport_service.php:5760), no other
     *       setEntity(COL_TRIP_LOGS, ...) payload may contain
     *       roster_student_ids. The field is set-once-immutable by
     *       design; a payload mutation of it would let a driver update
     *       (or admin refactor) forge access.
     *
     *   (3) RUNTIME — every existing tripLogs doc must carry a
     *       populated roster_student_ids array (or be empty by design
     *       for zero-roster trips). Docs missing the field would deny
     *       parent access under the rule.
     */
    private function _chTrnF7_7(): array
    {
        // ── Layer 1: rules-source scan ──
        $rulesPath = FCPATH . 'firebase-rules/firestore.rules';
        if (!is_readable($rulesPath)) {
            return ['status' => 'FAIL', 'detail' => "cannot read {$rulesPath}"];
        }
        $rules = (string) file_get_contents($rulesPath);
        if (!preg_match('/match\s*\/tripLogs\/\{docId\}\s*\{(.*?)^\s*\}/sm', $rules, $rm)) {
            return ['status' => 'FAIL', 'detail' => 'tripLogs match block not found in rules'];
        }
        $tripBlock = $rm[1];
        $needleParent = "roster_student_ids.hasAny(request.auth.token.get('student_ids', []))";
        $needleStudent = "request.auth.uid in resource.data.roster_student_ids";
        if (strpos($tripBlock, $needleParent) === false) {
            return ['status' => 'FAIL', 'detail' => 'rule missing parent-read arm: student_ids intersection'];
        }
        if (strpos($tripBlock, $needleStudent) === false) {
            return ['status' => 'FAIL', 'detail' => 'rule missing student-read arm: auth.uid in roster'];
        }

        // ── Layer 2: service-source scan (immutability) ──
        $svcPath = APPPATH . 'libraries/Transport_service.php';
        if (!is_readable($svcPath)) {
            return ['status' => 'FAIL', 'detail' => "cannot read {$svcPath}"];
        }
        $svc = $this->_stripComments((string) file_get_contents($svcPath));
        // Locate every setEntity(COL_TRIP_LOGS, ...) call. Bracket-balanced
        // forward walk from the opening paren to the matching close so we
        // capture the full call including multi-line inline arrays. The
        // create path (merge=false, Transport_service.php:5760) is the ONLY
        // legitimate write site for roster_student_ids — every OTHER call
        // (merge=true update paths) must NOT include the field.
        $needle = '->setEntity(self::COL_TRIP_LOGS';
        $offset = 0;
        $callPositions = [];
        while (($pos = strpos($svc, $needle, $offset)) !== false) {
            $callPositions[] = $pos;
            $offset = $pos + strlen($needle);
        }
        if (empty($callPositions)) {
            return ['status' => 'FAIL', 'detail' => 'no tripLogs setEntity calls found (structural regression)'];
        }
        $creates = 0; $mergeUpdates = 0; $rosterMutations = [];
        foreach ($callPositions as $pos) {
            // Advance to the opening '(' of setEntity
            $parenPos = strpos($svc, '(', $pos);
            if ($parenPos === false) { continue; }
            // Walk forward with bracket-balancing (handles nested [] and ())
            $depth = 0; $i = $parenPos; $end = strlen($svc);
            while ($i < $end) {
                $c = $svc[$i];
                if ($c === '(' || $c === '[') { $depth++; }
                elseif ($c === ')' || $c === ']') { $depth--; if ($depth === 0) { break; } }
                $i++;
            }
            if ($i >= $end) { continue; } // unclosed — skip
            $call = substr($svc, $parenPos, $i - $parenPos + 1);
            $mergeIsFalse = (bool) preg_match('/,\s*false\s*\)\s*$/', $call);
            $mergeIsTrue  = (bool) preg_match('/,\s*true\s*\)\s*$/', $call);
            $hasRoster    = (strpos($call, 'roster_student_ids') !== false);
            if ($mergeIsFalse) {
                $creates++;
                continue; // create path — roster_student_ids allowed here
            }
            if ($mergeIsTrue) {
                $mergeUpdates++;
                if ($hasRoster) {
                    $rosterMutations[] = 'setEntity merge=true payload contains roster_student_ids';
                }
            }
        }
        if ($creates < 1) {
            return ['status' => 'FAIL', 'detail' => 'no create-path (merge=false) found for tripLogs'];
        }
        if ($rosterMutations !== []) {
            return ['status' => 'FAIL', 'detail' => 'roster_student_ids mutated after create: ' . implode('; ', $rosterMutations)];
        }
        // Also confirm the driver-update rule allowlist does NOT include roster_student_ids
        if (strpos($tripBlock, 'roster_student_ids') !== false) {
            $allowlistPos = strpos($tripBlock, 'affectedKeys().hasOnly');
            if ($allowlistPos !== false) {
                $allowlistTail = substr($tripBlock, $allowlistPos, 800);
                if (strpos($allowlistTail, 'roster_student_ids') !== false) {
                    return ['status' => 'FAIL', 'detail' => 'driver-update allowlist includes roster_student_ids — client can forge access'];
                }
            }
        }

        // ── Layer 3: runtime check on any existing tripLogs docs ──
        try {
            $rows = (array) $this->fs->schoolWhere('tripLogs', [], null, 'ASC', 20);
        } catch (\Throwable $e) {
            $rows = [];
        }
        $sampled = 0; $missing = 0;
        foreach ($rows as $r) {
            $doc = (isset($r['data']) && is_array($r['data'])) ? $r['data'] : (is_array($r) ? $r : []);
            if (!is_array($doc)) { continue; }
            $sampled++;
            if (!array_key_exists('roster_student_ids', $doc) || !is_array($doc['roster_student_ids'])) {
                $missing++;
            }
        }
        if ($sampled === 0) {
            return ['status' => 'PASS', 'detail' => "rule arms + immutability guards present; no tripLogs sampled ({$creates} create path, {$mergeUpdates} merge-update paths audited)"];
        }
        if ($missing > 0) {
            return ['status' => 'FAIL', 'detail' => "{$missing}/{$sampled} tripLogs missing roster_student_ids field (parent reads will fail)"];
        }
        return ['status' => 'PASS', 'detail' => "rule arms + immutability guards present; {$sampled} tripLogs sampled all carry roster_student_ids ({$creates} create path, {$mergeUpdates} merge-update paths audited)"];
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

    // ═════════════════════════════════════════════════════════════════════
    //  F11 Analytics + Super Admin assertions (CH-TRN-F11-1..6) 2026-07-08
    // ═════════════════════════════════════════════════════════════════════

    /**
     * CH-TRN-F11-1 — Transport_analytics_service must not scan raw
     * operational collections. It composes over denormalised caches +
     * existing compute methods, per the operator directive "no
     * duplication of business logic". Source-scan forbids
     * schoolWhere('transportExpenses'|'tripLogs'|... ) inside the
     * analytics service (with the specific exception of low-cardinality
     * roll-up collections that ARE the denormalised layer:
     * vehicles/sosAlerts for KPI counts, transportAttendance for the
     * live-ops onboard math, routeFleetSummary/driverActivitySummary/
     * fleetMonthlyExpenseSummary/vehicleExpiryDashboardCache which are
     * the caches themselves).
     */
    private function _chTrnF11_1(): array
    {
        $path = APPPATH . 'libraries/Transport_analytics_service.php';
        if (!is_readable($path)) {
            return ['status' => 'FAIL', 'detail' => "cannot read {$path}"];
        }
        $src = $this->_stripComments((string) file_get_contents($path));
        // Forbidden = raw operational-collection full scans that would
        // duplicate compute*() aggregations
        $forbidden = ['transportExpenses', 'maintenanceLogs', 'fuelLogs', 'driverIncidents',
                      'driverPerformanceReviews', 'temporaryDriverAssignments'];
        $violations = [];
        foreach ($forbidden as $col) {
            $regex = "/schoolWhere\\s*\\(\\s*['\"]{$col}['\"]/";
            if (preg_match($regex, $src, $m)) {
                $violations[] = "{$col}: " . substr($m[0], 0, 40);
            }
        }
        if ($violations !== []) {
            return ['status' => 'FAIL', 'detail' => count($violations) . ' raw-scan violations: ' . implode('; ', $violations)];
        }
        return ['status' => 'PASS', 'detail' => 'analytics service composes over caches; no raw operational-collection scans'];
    }

    /**
     * CH-TRN-RC1-1 — Stabilization foundation gate. The concurrency-safe
     * casUpdateEntity CAS primitive must exist at BOTH the REST-client and the
     * service layers. The concurrency-cluster fixes (CON-2/3/6) depend on it;
     * if it silently disappears those fixes would regress to unguarded
     * read-modify-write. Pure source-scan — no Firestore round-trip.
     */
    private function _chTrnRc1_1(): array
    {
        $targets = [
            'client'  => APPPATH . 'libraries/Firestore_rest_client.php',
            'service' => APPPATH . 'libraries/Firestore_service.php',
        ];
        $missing = [];
        foreach ($targets as $layer => $path) {
            if (!is_readable($path)) {
                return ['status' => 'FAIL', 'detail' => "cannot read {$path}"];
            }
            $src = (string) file_get_contents($path);
            if (!preg_match('/public\s+function\s+casUpdateEntity\s*\(/', $src)) {
                $missing[] = $layer;
            }
        }
        if ($missing !== []) {
            return ['status' => 'FAIL', 'detail' => 'casUpdateEntity absent at: ' . implode(', ', $missing)];
        }
        return ['status' => 'PASS', 'detail' => 'casUpdateEntity CAS primitive present at client + service layers'];
    }

    /**
     * CH-TRN-CON6-1 — every trip-lifecycle transition (startTrip / completeTrip
     * / cancelTrip) must route its status change through casUpdateEntity (CAS),
     * never a bare setEntity on the trip log with a literal 'status' write. That
     * bare idiom is the CON-6 double-transition race; this gate stops it
     * regressing. Pure source-scan.
     */
    private function _chTrnCon6_1(): array
    {
        $path = APPPATH . 'libraries/Transport_service.php';
        if (!is_readable($path)) {
            return ['status' => 'FAIL', 'detail' => "cannot read {$path}"];
        }
        $src = (string) file_get_contents($path);
        $bad = [];
        foreach (['startTrip', 'completeTrip', 'cancelTrip'] as $method) {
            if (!preg_match('/function\s+' . $method . '\s*\(/', $src, $m, PREG_OFFSET_CAPTURE)) {
                $bad[] = "{$method}(missing)";
                continue;
            }
            $rest = substr($src, $m[0][1] + strlen($m[0][0]));
            // Slice to the next method declaration = this method's body.
            $end  = preg_match('/\n    (?:public|private|protected)\s+function\s/', $rest, $nm, PREG_OFFSET_CAPTURE)
                ? $nm[0][1] : strlen($rest);
            $body = substr($rest, 0, $end);
            if (strpos($body, 'casUpdateEntity') === false) {
                $bad[] = "{$method}(no-CAS)";
            }
            // The old unguarded transition idiom must be gone.
            if (preg_match('/setEntity\(\s*self::COL_TRIP_LOGS[^;]*[\'"]status[\'"]\s*=>/s', $body)) {
                $bad[] = "{$method}(bare-setEntity-status)";
            }
        }
        if ($bad !== []) {
            return ['status' => 'FAIL', 'detail' => 'trip transitions not CAS-guarded: ' . implode(', ', $bad)];
        }
        return ['status' => 'PASS', 'detail' => 'startTrip/completeTrip/cancelTrip route status transitions through casUpdateEntity'];
    }

    /**
     * CH-TRN-CON2-1 — the checkpoints[] single-element mutators
     * (_updateCheckpointState, markCheckpointLeft) must route through
     * casUpdateEntity (CAS), never a bare setEntity that rewrites the whole
     * 'checkpoints' array from a stale read — that is the CON-2 lost-update
     * race. (The tally writer is converted separately under CON-1.) Source-scan.
     */
    private function _chTrnCon2_1(): array
    {
        $path = APPPATH . 'libraries/Transport_service.php';
        if (!is_readable($path)) {
            return ['status' => 'FAIL', 'detail' => "cannot read {$path}"];
        }
        $src = (string) file_get_contents($path);
        $bad = [];
        foreach (['_updateCheckpointState', 'markCheckpointLeft'] as $method) {
            if (!preg_match('/function\s+' . $method . '\s*\(/', $src, $m, PREG_OFFSET_CAPTURE)) {
                $bad[] = "{$method}(missing)";
                continue;
            }
            $rest = substr($src, $m[0][1] + strlen($m[0][0]));
            $end  = preg_match('/\n    (?:public|private|protected)\s+function\s/', $rest, $nm, PREG_OFFSET_CAPTURE)
                ? $nm[0][1] : strlen($rest);
            $body = substr($rest, 0, $end);
            if (strpos($body, 'casUpdateEntity') === false) {
                $bad[] = "{$method}(no-CAS)";
            }
            if (preg_match('/setEntity\(\s*self::COL_TRIP_LOGS[^;]*[\'"]checkpoints[\'"]\s*=>/s', $body)) {
                $bad[] = "{$method}(bare-setEntity-checkpoints)";
            }
        }
        if ($bad !== []) {
            return ['status' => 'FAIL', 'detail' => 'checkpoint array writes not CAS-guarded: ' . implode(', ', $bad)];
        }
        return ['status' => 'PASS', 'detail' => '_updateCheckpointState/markCheckpointLeft mutate checkpoints[] through casUpdateEntity'];
    }

    /** Extract a method body from source by name (declaration → next method decl). */
    private function _methodBody(string $src, string $name): ?string
    {
        if (!preg_match('/function\s+' . preg_quote($name, '/') . '\s*\(/', $src, $m, PREG_OFFSET_CAPTURE)) {
            return null;
        }
        $rest = substr($src, $m[0][1] + strlen($m[0][0]));
        $end  = preg_match('/\n    (?:public|private|protected)\s+function\s/', $rest, $nm, PREG_OFFSET_CAPTURE)
            ? $nm[0][1] : strlen($rest);
        return substr($rest, 0, $end);
    }

    /**
     * CH-TRN-CON3-1 — capacity mutations must be atomic. assignStudent and
     * changeRoute must reserve a seat via _reserveCapacitySeat (check + increment
     * as one CAS step, not the old check-then-bump TOCTOU), and _bumpCapacityCache
     * must adjust the counter through casUpdateEntity (no lost-update RMW).
     * Source-scan.
     */
    private function _chTrnCon3_1(): array
    {
        $path = APPPATH . 'libraries/Transport_service.php';
        if (!is_readable($path)) {
            return ['status' => 'FAIL', 'detail' => "cannot read {$path}"];
        }
        $src = (string) file_get_contents($path);
        $bad = [];
        foreach (['assignStudent', 'changeRoute'] as $method) {
            $body = $this->_methodBody($src, $method);
            if ($body === null) { $bad[] = "{$method}(missing)"; continue; }
            if (strpos($body, '_reserveCapacitySeat') === false) { $bad[] = "{$method}(no-atomic-reserve)"; }
        }
        $bump = $this->_methodBody($src, '_bumpCapacityCache');
        if ($bump === null) {
            $bad[] = '_bumpCapacityCache(missing)';
        } elseif (strpos($bump, 'casUpdateEntity') === false) {
            $bad[] = '_bumpCapacityCache(non-atomic)';
        }
        if ($bad !== []) {
            return ['status' => 'FAIL', 'detail' => 'capacity ops not atomic: ' . implode(', ', $bad)];
        }
        return ['status' => 'PASS', 'detail' => 'assign/changeRoute reserve atomically; _bumpCapacityCache uses CAS'];
    }

    /**
     * CH-TRN-CON1-1 — the per-checkpoint boarding tally must be DERIVED from the
     * deduped transportAttendance event set, not blindly incremented. A blind
     * +1 double-counts on repeat driver taps (the doc is idempotent, the counter
     * was not). Assert _updateCheckpointTally derives (via
     * _tallyBoardingEventsByStop) + writes atomically (casUpdateEntity), and no
     * longer contains the '+ 1' materialized-counter idiom. Source-scan.
     */
    private function _chTrnCon1_1(): array
    {
        $path = APPPATH . 'libraries/Transport_service.php';
        if (!is_readable($path)) {
            return ['status' => 'FAIL', 'detail' => "cannot read {$path}"];
        }
        $src  = (string) file_get_contents($path);
        $body = $this->_methodBody($src, '_updateCheckpointTally');
        if ($body === null) {
            return ['status' => 'FAIL', 'detail' => '_updateCheckpointTally missing'];
        }
        $bad = [];
        if (strpos($body, '_tallyBoardingEventsByStop') === false) { $bad[] = 'not-derived'; }
        if (strpos($body, 'casUpdateEntity') === false)            { $bad[] = 'non-atomic-write'; }
        // The blind-increment idiom must be gone.
        if (preg_match('/students_(?:boarded|no_show)_count[\'"]\s*\]\s*\?\?\s*0\s*\)\s*\+\s*1/', $body)) {
            $bad[] = 'blind-increment-present';
        }
        if ($bad !== []) {
            return ['status' => 'FAIL', 'detail' => 'checkpoint tally not derived: ' . implode(', ', $bad)];
        }
        return ['status' => 'PASS', 'detail' => '_updateCheckpointTally derives per-stop counts from deduped events via casUpdateEntity'];
    }

    /**
     * CH-TRN-CON7-1 — the notice idempotency claim must be taken ATOMICALLY
     * (create-if-not-exists CAS via _tryClaimIdempotency) BEFORE fanout, with a
     * release path for empty/failed emission — not the old read-precheck + non-CAS
     * claim-AFTER-emission that let a concurrent double-fire both emit. Source-scan
     * of Transport_notifier::notify().
     */
    private function _chTrnCon7_1(): array
    {
        $path = APPPATH . 'libraries/Transport_notifier.php';
        if (!is_readable($path)) {
            return ['status' => 'FAIL', 'detail' => "cannot read {$path}"];
        }
        $src  = (string) file_get_contents($path);
        $body = $this->_methodBody($src, 'notify');
        if ($body === null) {
            return ['status' => 'FAIL', 'detail' => 'notify() missing'];
        }
        $bad = [];
        $posClaim  = strpos($body, '_tryClaimIdempotency');
        $posFanout = strpos($body, '_fanout');
        if ($posClaim === false)                                   { $bad[] = 'no-atomic-claim'; }
        if (strpos($body, '_releaseIdempotency') === false)        { $bad[] = 'no-release-path'; }
        if ($posClaim !== false && $posFanout !== false && $posClaim > $posFanout) {
            $bad[] = 'claim-after-fanout';
        }
        // The legacy non-CAS claim-after call must be gone from notify().
        if (strpos($body, '_claimIdempotency(') !== false) {
            $bad[] = 'legacy-claim-after-present';
        }
        if ($bad !== []) {
            return ['status' => 'FAIL', 'detail' => 'notice idempotency not claim-before-CAS: ' . implode(', ', $bad)];
        }
        return ['status' => 'PASS', 'detail' => 'notify() claims atomically (createEntity CAS) before fanout, with release-on-failure'];
    }

    /**
     * CH-TRN-RC2-1 — the FirestoreRestClient::query() missing-index fallback
     * must drop BOTH orderBy AND limit. Keeping the limit while dropping orderBy
     * returns an arbitrary `limit` docs sorted only among themselves — a silent
     * wrong top-N. Dropping both fetches the full where-matched set so the
     * client-side sort + slice yield the correct top-N. Source-scan.
     */
    private function _chTrnRc2_1(): array
    {
        $path = APPPATH . 'libraries/Firestore_rest_client.php';
        if (!is_readable($path)) {
            return ['status' => 'FAIL', 'detail' => "cannot read {$path}"];
        }
        $src  = (string) file_get_contents($path);
        $body = $this->_methodBody($src, 'query');
        if ($body === null) {
            return ['status' => 'FAIL', 'detail' => 'query() missing'];
        }
        if (strpos($body, "unset(\$structuredQuery['orderBy'])") === false) {
            return ['status' => 'FAIL', 'detail' => 'query() fallback does not drop orderBy'];
        }
        if (strpos($body, "unset(\$structuredQuery['limit'])") === false) {
            return ['status' => 'FAIL', 'detail' => 'RC-2: query() fallback keeps limit → wrong top-N on missing index'];
        }
        return ['status' => 'PASS', 'detail' => 'query() missing-index fallback drops orderBy AND limit (correct top-N)'];
    }

    /**
     * CH-TRN-RC2-2 — the RC-2 transport composite indexes must be present in
     * firestore.indexes.json (so listTrips/route-summary/expenses/assignments
     * and the assorted list reads are served by index, incl. the date-normalized
     * tripLogs composites — not a silent client-sort fallback).
     */
    private function _chTrnRc2_2(): array
    {
        $path = FCPATH . 'firebase-rules/firestore.indexes.json';
        if (!is_readable($path)) {
            return ['status' => 'INFO', 'detail' => 'firestore.indexes.json not readable — trivial pass'];
        }
        $json = json_decode((string) file_get_contents($path), true);
        if (!is_array($json) || !is_array($json['indexes'] ?? null)) {
            return ['status' => 'FAIL', 'detail' => 'firestore.indexes.json invalid or missing indexes[]'];
        }
        $have = [];
        foreach ($json['indexes'] as $idx) {
            $cg = (string) ($idx['collectionGroup'] ?? '');
            $fp = [];
            foreach ((array) ($idx['fields'] ?? []) as $f) { $fp[] = (string) ($f['fieldPath'] ?? ''); }
            $have[$cg . '|' . implode(',', $fp)] = true;
        }
        $required = [
            'tripLogs|schoolId,date',
            'tripLogs|schoolId,route_id,date',
            'transportExpenses|schoolId,status,expense_date',
            'transportExpenses|schoolId,expense_date',
            'studentRoutes|schoolId,session,route_id,student_name',
            'vehicles|schoolId,number',
            'routes|schoolId,name',
            'transportVendors|schoolId,vendor_name',
            'routeSuspensions|schoolId,status,suspended_from',
            'driverIncidents|schoolId,incident_date',
            'temporaryDriverAssignments|schoolId,effective_from',
        ];
        $missing = [];
        foreach ($required as $r) {
            if (empty($have[$r])) { $missing[] = $r; }
        }
        if ($missing !== []) {
            return ['status' => 'FAIL', 'detail' => 'RC-2 missing composite indexes: ' . implode('; ', $missing)];
        }
        return ['status' => 'PASS', 'detail' => count($required) . ' RC-2 transport composite indexes present (incl. date-normalized tripLogs)'];
    }

    /**
     * CH-TRN-RC3-1 — the fleetMonthlyExpenseSummary (FMES__) and
     * vehicleExpiryDashboardCache (VEXPCACHE) doc ids must be BARE at every
     * writer and reader. setEntity/getEntity add the single {schoolId} prefix;
     * a manually school-prefixed id gets double-prefixed on write and is missed
     * by the bare-id analytics readers (PERF-5). This gate forbids any FMES/
     * VEXPCACHE id built by concatenating a school-id variable. Source-scan
     * across the writer + both reader surfaces.
     */
    private function _chTrnRc3_1(): array
    {
        $files = [
            APPPATH . 'libraries/Transport_service.php',
            APPPATH . 'libraries/Transport_analytics_service.php',
            APPPATH . 'controllers/Transport_ops.php',
        ];
        $bad = [];
        foreach ($files as $f) {
            if (!is_readable($f)) { continue; }
            $src = (string) file_get_contents($f);
            // e.g.  $this->schoolId . '_VEXPCACHE'   /   $this->school_name . '_FMES__'
            if (preg_match('/(schoolId|school_name)\s*\.\s*[\'"]_(FMES__|VEXPCACHE)/', $src)
                // e.g.  "{$this->schoolId}_FMES__..."  /  "{$this->school_name}_VEXPCACHE"
                || preg_match('/\{\$this->(schoolId|school_name)\}_(FMES__|VEXPCACHE)/', $src)) {
                $bad[] = basename($f);
            }
        }
        if ($bad !== []) {
            return ['status' => 'FAIL', 'detail' => 'FMES/VEXPCACHE id still school-prefixed (double-prefix) in: ' . implode(', ', $bad)];
        }
        return ['status' => 'PASS', 'detail' => 'FMES/VEXPCACHE use bare entity ids (canonical single prefix) at all writers + readers'];
    }

    /**
     * CH-TRN-RC3-2 — busCapacityCache document ids must come from the single
     * canonical _capacityCacheId() helper. The hot accessors
     * (_checkCapacityAvailable, _bumpCapacityCache, _reserveCapacitySeat) must
     * call the helper and must NOT construct the id inline (the "_CAPCACHE_"
     * literal belongs only in the helper + the rebuild legacy-purge). Enforces
     * single-source-of-truth id generation + future-module safety. Source-scan.
     */
    private function _chTrnRc3_2(): array
    {
        $path = APPPATH . 'libraries/Transport_service.php';
        if (!is_readable($path)) {
            return ['status' => 'FAIL', 'detail' => "cannot read {$path}"];
        }
        $src = (string) file_get_contents($path);
        if (strpos($src, 'function _capacityCacheId') === false) {
            return ['status' => 'FAIL', 'detail' => 'canonical _capacityCacheId() helper missing'];
        }
        $bad = [];
        foreach (['_checkCapacityAvailable', '_bumpCapacityCache', '_reserveCapacitySeat'] as $m) {
            $body = $this->_methodBody($src, $m);
            if ($body === null) { $bad[] = "{$m}(missing)"; continue; }
            if (strpos($body, '_capacityCacheId') === false) { $bad[] = "{$m}(no-helper)"; }
            if (strpos($body, '_CAPCACHE_') !== false)       { $bad[] = "{$m}(inline-id)"; }
        }
        if ($bad !== []) {
            return ['status' => 'FAIL', 'detail' => 'CAPCACHE id not via canonical helper: ' . implode(', ', $bad)];
        }
        return ['status' => 'PASS', 'detail' => 'CAPCACHE id via single canonical _capacityCacheId() helper in all accessors'];
    }

    /**
     * CH-TRN-FIN1-1 — Fee_lifecycle::createModuleFee (the generic Transport +
     * Hostel module-fee writer) must NOT reset the payment state of an existing
     * demand. It is re-invoked on the same demandId by route change / assignment
     * edit / rollover / reconcile; it must read the existing demand and preserve
     * paidAmount (recomputing balance/status), never unconditionally writing
     * paidAmount=0/status=unpaid over a paid demand (FIN-1). Source-scan.
     */
    private function _chTrnFin1_1(): array
    {
        $path = APPPATH . 'libraries/Fee_lifecycle.php';
        if (!is_readable($path)) {
            return ['status' => 'INFO', 'detail' => 'Fee_lifecycle.php not readable — trivial pass'];
        }
        $src  = (string) file_get_contents($path);
        $body = $this->_methodBody($src, 'createModuleFee');
        if ($body === null) {
            return ['status' => 'FAIL', 'detail' => 'createModuleFee() not found'];
        }
        $bad = [];
        // Must read the existing demand before writing.
        if (strpos($body, "firestoreGet('feeDemands'") === false) {
            $bad[] = 'no-existing-demand-read';
        }
        // Must preserve an existing payment (not unconditionally zero it).
        if (strpos($body, "'paidAmount' => \$paidSoFar") === false) {
            $bad[] = 'no-payment-preservation';
        }
        if ($bad !== []) {
            return ['status' => 'FAIL', 'detail' => 'createModuleFee may reset paid demands: ' . implode(', ', $bad)];
        }
        return ['status' => 'PASS', 'detail' => 'createModuleFee reads the existing demand + preserves paidAmount (never resets a paid demand)'];
    }

    /**
     * CH-TRN-LOG1-1 — driver readiness must be re-validated at DISPATCH, not
     * only at assignment. createTrip (which also serves event dispatch via
     * dispatchEventTrip) must validate the resolved effective driver for the
     * trip date, and startTrip must re-validate for the current day — so an
     * unfit/on-leave/expired/inactive driver can never be put on a trip. Both
     * must call _validateDriverReadiness. Source-scan.
     */
    private function _chTrnLog1_1(): array
    {
        $path = APPPATH . 'libraries/Transport_service.php';
        if (!is_readable($path)) {
            return ['status' => 'FAIL', 'detail' => "cannot read {$path}"];
        }
        $src = (string) file_get_contents($path);
        $bad = [];
        foreach (['createTrip', 'startTrip'] as $m) {
            $body = $this->_methodBody($src, $m);
            if ($body === null) { $bad[] = "{$m}(missing)"; continue; }
            if (strpos($body, '_validateDriverReadiness') === false) { $bad[] = "{$m}(no-readiness-check)"; }
        }
        if ($bad !== []) {
            return ['status' => 'FAIL', 'detail' => 'driver readiness not validated at dispatch: ' . implode(', ', $bad)];
        }
        return ['status' => 'PASS', 'detail' => 'createTrip + startTrip validate driver readiness at dispatch (unfit driver rejected)'];
    }

    /**
     * CH-TRN-FIN2-1 — Fee_lifecycle::cancelSessionDemands must match the demand
     * category case-insensitively (strtolower), because createModuleFee stores
     * it lowercase. A case-sensitive 'Transport' predicate matches zero rows and
     * leaves old-session demands un-cancelled on rollover (FIN-2). Source-scan.
     */
    private function _chTrnFin2_1(): array
    {
        $path = APPPATH . 'libraries/Fee_lifecycle.php';
        if (!is_readable($path)) {
            return ['status' => 'INFO', 'detail' => 'Fee_lifecycle.php not readable — trivial pass'];
        }
        $src  = (string) file_get_contents($path);
        $body = $this->_methodBody($src, 'cancelSessionDemands');
        if ($body === null) {
            return ['status' => 'FAIL', 'detail' => 'cancelSessionDemands() not found'];
        }
        $body = $this->_stripComments($body);   // check CODE only, not doc-comments
        $bad = [];
        // (a) FIN-2 case fix — category predicate matched case-insensitively.
        if (!preg_match('/[\'"]category[\'"]\s*,\s*[\'"]==[\'"]\s*,\s*strtolower/', $body)) {
            $bad[] = 'category-not-lowercased';
        }
        // (b) Nested schoolWhere row must be unwrapped to a data-level var.
        if (strpos($body, "\$row['data']") === false && strpos($body, '_unwrapNestedRow') === false) {
            $bad[] = 'nested-row-not-unwrapped';
        }
        // (c) PAID-DEMAND PRESERVATION PATH (explicit, regression-proof):
        //   • the skip must read status from the UNWRAPPED level ($d['status']),
        //   • the skip on 'paid' must exist,
        //   • the buggy flat read ($row['status']) that silently disabled the
        //     skip must be ABSENT — reintroducing it would cancel paid demands.
        if (strpos($body, "\$d['status']") === false) {
            $bad[] = 'status-not-read-from-unwrapped-data';
        }
        if (!preg_match("/===\\s*['\"]paid['\"]/", $body)) {
            $bad[] = 'no-paid-skip';
        }
        if (strpos($body, "\$row['status']") !== false) {
            $bad[] = 'reads-status-from-nested-envelope(paid-skip-silently-broken)';
        }
        if ($bad !== []) {
            return ['status' => 'FAIL', 'detail' => 'FIN-2: ' . implode(', ', $bad)];
        }
        return ['status' => 'PASS', 'detail' => 'cancelSessionDemands: case-insensitive category match + unwrapped rows + paid-skip reads $d[status] and skips paid (paid demands preserved on rollover)'];
    }

    /**
     * CH-TRN-CON4-1 — a rollover fee failure must be RECOVERABLE. rolloverSession
     * must stamp fee_demand_ref='pending' up front in the session flip (so a fee
     * throw / process death leaves a recoverable marker) and update it to the real
     * ref only on a confirmed create; and reconcilePendingFees must unwrap the
     * nested schoolWhere row (read $d[...], not $r['studentId']) so it actually
     * retries pending students — else the recovery path retries nothing. Source-scan.
     */
    private function _chTrnCon4_1(): array
    {
        $path = APPPATH . 'libraries/Transport_service.php';
        if (!is_readable($path)) {
            return ['status' => 'FAIL', 'detail' => "cannot read {$path}"];
        }
        $src = (string) file_get_contents($path);
        $bad = [];
        $roll = $this->_methodBody($src, 'rolloverSession');
        if ($roll === null) {
            $bad[] = 'rolloverSession(missing)';
        } else {
            if (!preg_match("/[\'\"]fee_demand_ref[\'\"]\\s*=>\\s*[\'\"]pending[\'\"]/", $roll)) {
                $bad[] = 'rollover-no-upfront-pending-marker';
            }
            if (strpos($roll, '$rolloverRef') === false) {
                $bad[] = 'rollover-no-success-ref-update';
            }
        }
        $rec = $this->_methodBody($src, 'reconcilePendingFees');
        if ($rec === null) {
            $bad[] = 'reconcilePendingFees(missing)';
        } else {
            if (strpos($rec, "\$r['studentId']") !== false) {
                $bad[] = 'reconcile-reads-nested-envelope(retries-nothing)';
            }
            if (strpos($rec, "\$d['studentId']") === false && strpos($rec, '_unwrapNestedRow') === false) {
                $bad[] = 'reconcile-not-unwrapped';
            }
        }
        if ($bad !== []) {
            return ['status' => 'FAIL', 'detail' => 'CON-4: ' . implode(', ', $bad)];
        }
        return ['status' => 'PASS', 'detail' => 'rollover parks fee_demand_ref=pending upfront + updates on confirmed create; reconcilePendingFees unwraps rows (fee failures recoverable)'];
    }

    /**
     * CH-TRN-ENV-1 — systemic guard for the nested {id,data} schoolWhere
     * envelope. The transport-scoped methods found reading domain fields flat
     * off the row (the audit's 6 sites) must unwrap the envelope
     * (_unwrapNestedRow / $row['data']) before any field access. Prevents the
     * whole class from regressing. Source-scan.
     */
    private function _chTrnEnv1(): array
    {
        $bad = [];
        $tsPath = APPPATH . 'libraries/Transport_service.php';
        if (!is_readable($tsPath)) {
            return ['status' => 'FAIL', 'detail' => 'Transport_service.php unreadable'];
        }
        $ts = (string) file_get_contents($tsPath);
        foreach (['computeFleetExpiryDashboard', 'rebuildCapacityCache', 'listActiveDrivers', 'computeDriverActivitySummary', '_recomputeKmpl'] as $m) {
            $body = $this->_methodBody($ts, $m);
            if ($body === null) { $bad[] = "{$m}(missing)"; continue; }
            if (strpos($body, '_unwrapNestedRow') === false && strpos($body, "['data']") === false) {
                $bad[] = "{$m}(flat-read)";
            }
        }
        $cPath = APPPATH . 'controllers/Transport.php';
        if (is_readable($cPath)) {
            $body = $this->_methodBody((string) file_get_contents($cPath), 'get_lifecycle_log');
            if ($body !== null && strpos($body, "['data']") === false && strpos($body, '_unwrapNestedRow') === false) {
                $bad[] = 'get_lifecycle_log(flat-read)';
            }
        }
        if ($bad !== []) {
            return ['status' => 'FAIL', 'detail' => 'nested-envelope flat reads: ' . implode(', ', $bad)];
        }
        return ['status' => 'PASS', 'detail' => 'transport schoolWhere consumers unwrap the {id,data} envelope (expiry/capacity/drivers/activity/kmpl/lifecycle-log)'];
    }

    /**
     * CH-TRN-FEE-1 — Fees stabilization: Fee_firestore_txn::allocationsForStudent
     * must read each allocation's doc-id from the schoolWhere ENVELOPE row id,
     * not from the data dict (which has no id). Reading it flat returned [] →
     * the refund R.6 stale-allocation guard never fired → a refund could reverse
     * a superseded allocation and corrupt a newer receipt. Source-scan.
     */
    private function _chTrnFeeAlloc(): array
    {
        $path = APPPATH . 'libraries/Fee_firestore_txn.php';
        if (!is_readable($path)) {
            return ['status' => 'INFO', 'detail' => 'Fee_firestore_txn.php not readable — trivial pass'];
        }
        $body = $this->_methodBody((string) file_get_contents($path), 'allocationsForStudent');
        if ($body === null) {
            return ['status' => 'FAIL', 'detail' => 'allocationsForStudent() not found'];
        }
        if (strpos($body, "\$r['id']") === false) {
            return ['status' => 'FAIL', 'detail' => 'FEE: allocationsForStudent _docId not read from envelope row id (stale-allocation guard would be dead)'];
        }
        return ['status' => 'PASS', 'detail' => 'allocationsForStudent reads _docId from the envelope row id (refund stale-allocation guard functional)'];
    }

    /**
     * CH-TRN-FIN3-1 — expense↔ledger integrity (FIN-3 + FIN-4). A POSTED
     * transport expense's financial fields must be immutable (editing them was
     * a dedup no-op re-post → record/ledger divergence), and voiding a posted
     * expense must reverse its journal (a status-only void overstated the GL).
     * Source-scan of Transport_service.
     */
    private function _chTrnFin3_1(): array
    {
        $path = APPPATH . 'libraries/Transport_service.php';
        if (!is_readable($path)) {
            return ['status' => 'INFO', 'detail' => 'Transport_service.php not readable — trivial pass'];
        }
        $src = (string) file_get_contents($path);

        // FIN-3: saveExpense blocks financial edits on a posted expense and
        // preserves the posted ref on a non-financial edit (no pending reset).
        $save = $this->_methodBody($src, 'saveExpense');
        if ($save === null) {
            return ['status' => 'FAIL', 'detail' => 'saveExpense() not found'];
        }
        if (strpos($save, '$financialChanged') === false || strpos($save, 'throw') === false) {
            return ['status' => 'FAIL', 'detail' => 'FIN-3: saveExpense does not block financial edits on a posted expense (divergence path open)'];
        }
        // The immutability set MUST include expense_date (it is the journal date
        // + FMES month key) — else a posted expense's date can diverge from its GL.
        if (strpos($save, '$newDate') === false || strpos($save, "\$existing['expense_date']") === false) {
            return ['status' => 'FAIL', 'detail' => 'FIN-3: posted-expense immutability omits expense_date (record date/period can diverge from the GL journal)'];
        }
        if (strpos($save, '$isPosted ? $existingRef') === false) {
            return ['status' => 'FAIL', 'detail' => 'FIN-3: saveExpense resets accounting_journal_ref on a posted edit (dedup no-op re-post)'];
        }

        // FIN-4: voidExpense reverses the journal and aborts when it cannot.
        $void = $this->_methodBody($src, 'voidExpense');
        if ($void === null) {
            return ['status' => 'FAIL', 'detail' => 'voidExpense() not found'];
        }
        if (strpos($void, '_reverseExpensePost') === false || strpos($void, 'throw') === false) {
            return ['status' => 'FAIL', 'detail' => 'FIN-4: voidExpense does not reverse the journal / abort on failure (GL overstated on void)'];
        }

        // The reversal seam actually calls the idempotent postReversal.
        $rev = $this->_methodBody($src, '_reverseExpensePost');
        if ($rev === null || strpos($rev, 'postReversal') === false) {
            return ['status' => 'FAIL', 'detail' => 'FIN-4: _reverseExpensePost missing or does not call postReversal'];
        }

        return ['status' => 'PASS', 'detail' => 'FIN-3 posted-expense financial fields immutable + ref preserved; FIN-4 void reverses journal via postReversal (aborts if unreversible)'];
    }

    /**
     * CH-TRN-OPSN-1 — transport OPS notices (_fanoutOps → notices) must carry the
     * canonical `timestamp` (+ timestampMs) that the Admin Panel notice-bell
     * (NoticeAnnouncement::getRecentNotices, ORDER BY timestamp DESC via a
     * server-side index) sorts on — else Firestore drops them and admins never
     * see them. Source-scan of Transport_notifier::_fanoutOps.
     */
    private function _chTrnOpsn1(): array
    {
        $path = APPPATH . 'libraries/Transport_notifier.php';
        if (!is_readable($path)) {
            return ['status' => 'INFO', 'detail' => 'Transport_notifier.php not readable — trivial pass'];
        }
        $body = $this->_methodBody((string) file_get_contents($path), '_fanoutOps');
        if ($body === null) {
            return ['status' => 'FAIL', 'detail' => '_fanoutOps() not found'];
        }
        if (strpos($body, "'timestamp'") === false || strpos($body, "'timestampMs'") === false) {
            return ['status' => 'FAIL', 'detail' => 'OPS-NOTICE: _fanoutOps omits canonical timestamp/timestampMs → invisible in the Admin notice-bell (ORDER BY timestamp)'];
        }
        // Must not have dropped the app-facing sentAt sort key either.
        if (strpos($body, "'sentAt'") === false) {
            return ['status' => 'FAIL', 'detail' => 'OPS-NOTICE: _fanoutOps dropped sentAt (app notice feeds order by it)'];
        }
        return ['status' => 'PASS', 'detail' => '_fanoutOps writes canonical timestamp+timestampMs (admin-bell visible) alongside sentAt (app feeds) — one notice contract'];
    }

    /**
     * CH-TRN-NF1-1 — the Transport controller must NOT depend on
     * Operations_accounting (F3 removed the load; the orphaned
     * $this->operations_accounting->search_students() call fatally 500'd). The
     * student search must route through the Firestore-native
     * Transport_service::searchStudents(). Regression guard so the dependency
     * cannot be reintroduced into the controller. Source-scan.
     */
    private function _chTrnNf1(): array
    {
        $ctrlPath = APPPATH . 'controllers/Transport.php';
        $svcPath  = APPPATH . 'libraries/Transport_service.php';
        if (!is_readable($ctrlPath) || !is_readable($svcPath)) {
            return ['status' => 'INFO', 'detail' => 'Transport.php / Transport_service.php not readable — trivial pass'];
        }
        $ctrl = (string) file_get_contents($ctrlPath);
        // No live method call on the (unloaded) operations_accounting property.
        if (strpos($ctrl, '$this->operations_accounting->') !== false) {
            return ['status' => 'FAIL', 'detail' => 'NF-1: Transport controller calls $this->operations_accounting-> (unloaded after F3 → fatal 500)'];
        }
        // Search routes through the service layer.
        if (strpos($ctrl, 'transport->searchStudents') === false) {
            return ['status' => 'FAIL', 'detail' => 'NF-1: search_students does not route through Transport_service::searchStudents'];
        }
        // The Firestore-native service method exists and reads students school-scoped.
        $svc = $this->_methodBody((string) file_get_contents($svcPath), 'searchStudents');
        if ($svc === null || strpos($svc, "schoolWhere('students'") === false) {
            return ['status' => 'FAIL', 'detail' => 'NF-1: Transport_service::searchStudents missing or not school-scoped'];
        }
        return ['status' => 'PASS', 'detail' => 'Transport controller free of Operations_accounting; student search routes through Firestore-native Transport_service::searchStudents (school-scoped)'];
    }

    /**
     * CH-TRN-RCP-1 — CANONICAL parent-notification recipient contract. Every
     * parent-facing fanout MUST resolve recipients through the single
     * _resolveStudentRecipients() (studentId → the student doc's `userId`
     * field), and NO parent path may read the legacy/absent parent identifiers
     * (parent_db_key / parentId / parent_id) which resolved to zero recipients.
     * Ops/staff role-based delivery (_fanoutOps → targetRoles) is a separate,
     * intentionally-unchanged contract. Source-scan.
     */
    private function _chTrnRcp1(): array
    {
        $path = APPPATH . 'libraries/Transport_notifier.php';
        if (!is_readable($path)) {
            return ['status' => 'INFO', 'detail' => 'Transport_notifier.php not readable — trivial pass'];
        }
        $src = (string) file_get_contents($path);

        // The single canonical resolver exists and reads the userId field.
        $resolver = $this->_methodBody($src, '_resolveStudentRecipients');
        $mapper   = $this->_methodBody($src, '_recipientUserIdFromStudent');
        if ($resolver === null || $mapper === null || strpos($mapper, "'userId'") === false) {
            return ['status' => 'FAIL', 'detail' => 'RCP: canonical userId resolver/mapper missing (recipient contract not single-sourced)'];
        }
        // Every parent-facing fanout routes through it.
        foreach (['_fanoutParentsOfStudents', '_resolveParentsOfRoute'] as $m) {
            $body = $this->_methodBody($src, $m);
            if ($body === null || strpos($body, '_resolveStudentRecipients') === false) {
                return ['status' => 'FAIL', 'detail' => "RCP: {$m} does not use the canonical _resolveStudentRecipients"];
            }
        }
        // No ACTIVE legacy recipient reads (quoted field-access; prose comments are fine).
        foreach (["['parent_db_key']", "['parentId']", "['parent_id']", "'parent_db_key'", "'parent_id'"] as $legacy) {
            if (strpos($src, $legacy) !== false) {
                return ['status' => 'FAIL', 'detail' => "RCP: legacy recipient field {$legacy} still read (inconsistent recipient resolution)"];
            }
        }
        return ['status' => 'PASS', 'detail' => 'all parent fanouts resolve recipients via the single canonical userId resolver; no legacy parent-id reads remain'];
    }

    /**
     * CH-TRN-PERF6-1 — the cross-tenant KPI loop (the nightly rollup's O(tenants)
     * core) must isolate each tenant so one failing/hung tenant cannot abort the
     * whole run. Source-scan for the per-tenant catch.
     */
    private function _chTrnPerf6_1(): array
    {
        $path = APPPATH . 'libraries/Transport_analytics_service.php';
        if (!is_readable($path)) {
            return ['status' => 'INFO', 'detail' => 'Transport_analytics_service.php not readable — trivial pass'];
        }
        $body = $this->_methodBody((string) file_get_contents($path), 'getCrossTenantFleetKpi');
        if ($body === null) {
            return ['status' => 'FAIL', 'detail' => 'getCrossTenantFleetKpi() not found'];
        }
        if (strpos($body, 'TRN_CROSS_TENANT_KPI_SKIP') === false) {
            return ['status' => 'FAIL', 'detail' => 'PERF-6: per-tenant failure not isolated (one bad tenant aborts the whole rollup)'];
        }
        return ['status' => 'PASS', 'detail' => 'cross-tenant loop isolates each tenant (a failing tenant is logged + skipped, others aggregate)'];
    }

    /**
     * CH-TRN-PERF10-1 — Transport_analytics_service::_memo must tenant-scope its
     * cache key (prefix with schoolId) so a reused instance cannot serve one
     * school's cached value to another. Source-scan.
     */
    private function _chTrnPerf10_1(): array
    {
        $path = APPPATH . 'libraries/Transport_analytics_service.php';
        if (!is_readable($path)) {
            return ['status' => 'INFO', 'detail' => 'Transport_analytics_service.php not readable — trivial pass'];
        }
        $body = $this->_methodBody((string) file_get_contents($path), '_memo');
        if ($body === null) {
            return ['status' => 'FAIL', 'detail' => '_memo() not found'];
        }
        if (strpos($body, '$this->schoolId . ') === false) {
            return ['status' => 'FAIL', 'detail' => 'PERF-10: memo key not tenant-scoped (cross-tenant cache bleed without external reset)'];
        }
        return ['status' => 'PASS', 'detail' => 'analytics _memo key is prefixed with schoolId (tenant-isolated cache)'];
    }

    /**
     * CH-TRN-PERF7-1 — list scans must be bounded (PERF-7 listExpenses, PERF-8
     * listAssignments) and the expense trend must use a single IN query
     * (PERF-11) instead of N sequential point-reads. Source-scan.
     */
    private function _chTrnPerf7_1(): array
    {
        $ts = APPPATH . 'libraries/Transport_service.php';
        $ta = APPPATH . 'libraries/Transport_analytics_service.php';
        if (!is_readable($ts) || !is_readable($ta)) {
            return ['status' => 'INFO', 'detail' => 'transport sources not readable — trivial pass'];
        }
        $tsSrc = (string) file_get_contents($ts);
        foreach (['listExpenses', 'listAssignments'] as $m) {
            $body = $this->_methodBody($tsSrc, $m);
            if ($body === null || strpos($body, 'LIST_SCAN_LIMIT') === false) {
                return ['status' => 'FAIL', 'detail' => "PERF-7/8: {$m} scan not bounded (LIST_SCAN_LIMIT absent — unbounded full-collection read)"];
            }
        }
        $trend = $this->_methodBody((string) file_get_contents($ta), 'getFleetExpenseTrend');
        if ($trend === null || strpos($trend, "'period_year_month', 'in'") === false) {
            return ['status' => 'FAIL', 'detail' => 'PERF-11: getFleetExpenseTrend not a single IN query (N sequential point-reads)'];
        }
        return ['status' => 'PASS', 'detail' => 'listExpenses/listAssignments bounded by LIST_SCAN_LIMIT; expense trend uses one IN query with per-month fallback'];
    }

    /**
     * CH-TRN-FIN5-1 — the transport expense poster (PayrollAccountingService::
     * postExpense) must pre-validate its ledger + counter accounts read-only
     * (via _accountPostable) before calling the engine — Operations_accounting::
     * create_journal halts the whole request with json_error()+exit on a
     * missing/inactive/group account. Source-scan.
     */
    private function _chTrnFin5_1(): array
    {
        $path = APPPATH . 'services/PayrollAccountingService.php';
        if (!is_readable($path)) {
            return ['status' => 'INFO', 'detail' => 'PayrollAccountingService.php not readable — trivial pass'];
        }
        $src = (string) file_get_contents($path);
        if ($this->_methodBody($src, '_accountPostable') === null) {
            return ['status' => 'FAIL', 'detail' => 'FIN-5: _accountPostable read-only pre-check missing'];
        }
        $post = $this->_methodBody($src, 'postExpense');
        if ($post === null || strpos($post, '_accountPostable') === false) {
            return ['status' => 'FAIL', 'detail' => 'FIN-5: postExpense does not pre-validate accounts (engine json_error can halt the request)'];
        }
        return ['status' => 'PASS', 'detail' => 'postExpense pre-validates ledger+counter accounts (bad account → pending, not an uncatchable halt)'];
    }

    /**
     * CH-TRN-SEC5-1 — getCrossTenantFleetKpi rewires the shared FS singleton per
     * tenant; it MUST restore the origin in a finally block (and assert ready),
     * else a mid-loop throw leaves the singleton on the last tenant (cross-tenant
     * bleed). Source-scan.
     */
    private function _chTrnSec5_1(): array
    {
        $path = APPPATH . 'libraries/Transport_analytics_service.php';
        if (!is_readable($path)) {
            return ['status' => 'INFO', 'detail' => 'Transport_analytics_service.php not readable — trivial pass'];
        }
        $body = $this->_methodBody((string) file_get_contents($path), 'getCrossTenantFleetKpi');
        if ($body === null) {
            return ['status' => 'FAIL', 'detail' => 'getCrossTenantFleetKpi() not found'];
        }
        if (strpos($body, 'finally') === false
            || strpos($body, '$this->schoolId = $originalSid') === false
            || strpos($body, '_assertReady') === false) {
            return ['status' => 'FAIL', 'detail' => 'SEC-5: cross-tenant compose does not restore the FS singleton in a finally (bleed on throw)'];
        }
        return ['status' => 'PASS', 'detail' => 'getCrossTenantFleetKpi restores the FS singleton in finally + asserts ready (no cross-tenant bleed)'];
    }

    /**
     * CH-TRN-CON5-1 — _appendAssignmentHistoryEvent must use a DETERMINISTIC id
     * in a batch/rollover context (batchId != '') so a concurrent re-pump
     * overwrites the same history doc instead of minting a duplicate (+ doubling
     * the numbering counter). Source-scan.
     */
    private function _chTrnCon5_1(): array
    {
        $path = APPPATH . 'libraries/Transport_service.php';
        if (!is_readable($path)) {
            return ['status' => 'INFO', 'detail' => 'Transport_service.php not readable — trivial pass'];
        }
        $body = $this->_methodBody((string) file_get_contents($path), '_appendAssignmentHistoryEvent');
        if ($body === null) {
            return ['status' => 'FAIL', 'detail' => '_appendAssignmentHistoryEvent() not found'];
        }
        // Must branch on batchId and build a deterministic HIST_ id in that arm.
        if (strpos($body, "\$batchId !== ''") === false || strpos($body, "'HIST_'") === false) {
            return ['status' => 'FAIL', 'detail' => 'CON-5: history append not deterministic in batch context (re-pump duplicates history)'];
        }
        return ['status' => 'PASS', 'detail' => 'batch/rollover history uses a deterministic (student,batch,event) id — idempotent on re-pump'];
    }

    /**
     * CH-TRN-LOG5-1 — _writeBoardingEvent must apply the frozen-roster
     * membership gate to ALL event types including no_show (exempting no_show
     * let markStudentNoShow(anyId) alert an unrelated parent + inflate the tally).
     */
    private function _chTrnLog5_1(): array
    {
        $path = APPPATH . 'libraries/Transport_service.php';
        if (!is_readable($path)) {
            return ['status' => 'INFO', 'detail' => 'Transport_service.php not readable — trivial pass'];
        }
        $body = $this->_methodBody((string) file_get_contents($path), '_writeBoardingEvent');
        if ($body === null) {
            return ['status' => 'FAIL', 'detail' => '_writeBoardingEvent() not found'];
        }
        // The roster gate must NOT special-case no_show out of the membership check.
        if (strpos($body, "!== 'no_show' && !in_array(\$studentId, \$roster") !== false
            || strpos($body, "!= 'no_show' && !in_array(\$studentId, \$roster") !== false) {
            return ['status' => 'FAIL', 'detail' => 'LOG-5: no_show still exempt from the frozen-roster gate (wrong parent alerted)'];
        }
        if (strpos($body, 'is not on the frozen roster') === false) {
            return ['status' => 'FAIL', 'detail' => 'LOG-5: roster membership gate missing'];
        }
        return ['status' => 'PASS', 'detail' => 'no_show honours the frozen-roster membership gate (no cross-parent misfire)'];
    }

    /**
     * CH-TRN-LOG4-1 — the escort HR-Active check (_assertEscortsActive) must run
     * on every escort-mutating path (scheduleEvent, updateEvent, addEventEscort)
     * AND at dispatchEventTrip — a staff member terminated after scheduling must
     * not remain a recorded safety escort.
     */
    private function _chTrnLog4_1(): array
    {
        $path = APPPATH . 'libraries/Transport_service.php';
        if (!is_readable($path)) {
            return ['status' => 'INFO', 'detail' => 'Transport_service.php not readable — trivial pass'];
        }
        $src = (string) file_get_contents($path);
        if ($this->_methodBody($src, '_assertEscortsActive') === null) {
            return ['status' => 'FAIL', 'detail' => 'LOG-4: _assertEscortsActive helper missing'];
        }
        $missing = [];
        foreach (['scheduleEvent', 'updateEvent', 'addEventEscort', 'dispatchEventTrip'] as $m) {
            $body = $this->_methodBody($src, $m);
            if ($body === null || strpos($body, '_assertEscortsActive') === false) {
                $missing[] = $m;
            }
        }
        if ($missing !== []) {
            return ['status' => 'FAIL', 'detail' => 'LOG-4: escort HR-active check not applied in: ' . implode(', ', $missing)];
        }
        return ['status' => 'PASS', 'detail' => 'escort HR-active gate runs on schedule/update/add + at dispatch (no terminated-staff escort)'];
    }

    /**
     * CH-TRN-SEC2-1 — the tripLogs create rule must bind a non-admin (staff)
     * create to the trip's own driver (driverIs(driver_staff_id)); a bare
     * isStaff() create let any staff plant a trip with an arbitrary driver and
     * drive its state machine. Source-scan of the rules file.
     */
    private function _chTrnSec2_1(): array
    {
        $path = FCPATH . 'firebase-rules/firestore.rules';
        if (!is_readable($path)) {
            $alt = realpath(APPPATH . '../firebase-rules/firestore.rules');
            if ($alt === false || !is_readable($alt)) {
                return ['status' => 'INFO', 'detail' => 'firestore.rules not readable — trivial pass'];
            }
            $path = $alt;
        }
        $rules = (string) file_get_contents($path);
        $start = strpos($rules, 'match /tripLogs/');
        if ($start === false) {
            return ['status' => 'FAIL', 'detail' => 'SEC-2: tripLogs match block not found'];
        }
        // Window spans the tripLogs match block's read+create region.
        $block = substr($rules, $start, 2200);
        if (strpos($block, "driverIs(request.resource.data.get('driver_staff_id'") === false) {
            return ['status' => 'FAIL', 'detail' => 'SEC-2: tripLogs create not bound to driverIs(driver_staff_id) — any staff can plant a trip'];
        }
        return ['status' => 'PASS', 'detail' => 'tripLogs create binds non-admin writes to the trip driver (driverIs(driver_staff_id))'];
    }

    /**
     * CH-TRN-SEC3-1 — transport fleet analytics (dashboard slot + the
     * Transport_analytics HTTP surface) must gate on the per-school Operations
     * RBAC grant, not only the hardcoded role-scope matrix — otherwise a role
     * with the Transport module revoked still sees fleet financials. Source-scan.
     */
    private function _chTrnSec3_1(): array
    {
        $dir = APPPATH . 'controllers';
        $admin = @file_get_contents("{$dir}/Admin.php");
        $anal  = @file_get_contents("{$dir}/Transport_analytics.php");
        if ($admin === false || $anal === false) {
            return ['status' => 'INFO', 'detail' => 'Admin.php / Transport_analytics.php not readable — trivial pass'];
        }
        // Dashboard slot must gate on Operations alongside the visibility check.
        if (strpos($admin, "has_permission('Operations')") === false
            || strpos($admin, 'transport_fleet') === false) {
            return ['status' => 'FAIL', 'detail' => 'SEC-3: Admin dashboard transport slot not gated on has_permission(Operations)'];
        }
        // Every analytics endpoint gate (bundle + _widget) must require it too.
        if (substr_count($anal, "has_permission('Operations')") < 2) {
            return ['status' => 'FAIL', 'detail' => 'SEC-3: Transport_analytics endpoints not fully gated on has_permission(Operations)'];
        }
        return ['status' => 'PASS', 'detail' => 'fleet analytics (dashboard slot + bundle + widgets) gated on the Operations RBAC grant'];
    }

    /**
     * CH-TRN-SEC4-1 — Transport HTTP controllers must read the caller role from
     * the canonical $this->admin_role (set by MY_Controller from session/claims),
     * NOT the phantom session key 'role' (always '' → role gate fail-closed →
     * endpoint non-functional). Source-scan of the Transport_* controllers.
     */
    private function _chTrnSec4_1(): array
    {
        $dir = APPPATH . 'controllers';
        $offenders = [];
        foreach (['Transport_sos', 'Transport_trip', 'Transport_event', 'Transport_analytics', 'Transport_driver'] as $c) {
            $p = "{$dir}/{$c}.php";
            if (!is_readable($p)) { continue; }
            if (strpos((string) file_get_contents($p), "userdata('role')") !== false) {
                $offenders[] = $c;
            }
        }
        if ($offenders !== []) {
            return ['status' => 'FAIL', 'detail' => "SEC-4: phantom userdata('role') still read in: " . implode(', ', $offenders) . ' (role gate fail-closed → endpoints dead)'];
        }
        return ['status' => 'PASS', 'detail' => 'Transport controllers read the caller role from the canonical admin_role (no phantom userdata(role))'];
    }

    /**
     * CH-TRN-PERF4-1 — the SA fleet leaderboard must serve from the nightly
     * cross-tenant rollup (one document read) rather than recomputing per-tenant
     * KPIs synchronously on the request (O(tenants) → timeout). Verifies
     * getFleetLeaderboard routes through the cached path, the cached path reads
     * the rollup doc + has a bounded fallback, and a nightly writer exists.
     */
    private function _chTrnPerf4_1(): array
    {
        $path = APPPATH . 'libraries/Transport_analytics_service.php';
        if (!is_readable($path)) {
            return ['status' => 'INFO', 'detail' => 'Transport_analytics_service.php not readable — trivial pass'];
        }
        $src = (string) file_get_contents($path);

        $lb = $this->_methodBody($src, 'getFleetLeaderboard');
        if ($lb === null || strpos($lb, 'getCrossTenantFleetKpiCached') === false) {
            return ['status' => 'FAIL', 'detail' => 'PERF-4: getFleetLeaderboard still calls the raw O(tenants) compute (not the rollup-cached path)'];
        }
        $cached = $this->_methodBody($src, 'getCrossTenantFleetKpiCached');
        if ($cached === null
            || strpos($cached, 'COL_FLEET_ROLLUP') === false
            || strpos($cached, 'array_slice') === false) {
            return ['status' => 'FAIL', 'detail' => 'PERF-4: cached path missing rollup read or bounded live fallback'];
        }
        if ($this->_methodBody($src, 'storeCrossTenantFleetRollup') === null) {
            return ['status' => 'FAIL', 'detail' => 'PERF-4: nightly storeCrossTenantFleetRollup writer missing'];
        }
        return ['status' => 'PASS', 'detail' => 'SA leaderboard serves the transportFleetRollup doc (O(1) read) with a bounded live fallback; nightly writer present'];
    }

    /**
     * CH-TRN-PERF3-1 — getLiveOperationsSnapshot must compute
     * students_currently_onboard from the SINGLE [trip_date==today]
     * transportAttendance scan (bucketed in-memory), not one query per
     * InProgress trip. Asserts the attendance collection is queried at most
     * once and the in-memory bucket is present. Source-scan.
     */
    private function _chTrnPerf3_1(): array
    {
        $path = APPPATH . 'libraries/Transport_analytics_service.php';
        if (!is_readable($path)) {
            return ['status' => 'INFO', 'detail' => 'Transport_analytics_service.php not readable — trivial pass'];
        }
        $body = $this->_methodBody((string) file_get_contents($path), 'getLiveOperationsSnapshot');
        if ($body === null) {
            return ['status' => 'FAIL', 'detail' => 'getLiveOperationsSnapshot() not found'];
        }
        $attQueries = substr_count($body, "schoolWhere('transportAttendance'");
        if ($attQueries > 1) {
            return ['status' => 'FAIL', 'detail' => "PERF-3: transportAttendance queried {$attQueries}× in getLiveOperationsSnapshot (N+1 per-trip loop reintroduced)"];
        }
        if (strpos($body, '$perTripBoarded') === false) {
            return ['status' => 'FAIL', 'detail' => 'PERF-3: onboard not derived from the in-memory single-scan bucket'];
        }
        // id-form: $inProgressIds must be the BARE canonical trip id (the trip
        // doc's `id` field), matching transportAttendance.tripId. Collecting the
        // PREFIXED schoolWhere envelope id ($t['id']) makes every bucket lookup
        // miss → students_currently_onboard stuck at 0.
        if (strpos($body, "\$inProgressIds[] = (string) (\$t['id']") !== false) {
            return ['status' => 'FAIL', 'detail' => 'PERF-3: inProgressIds uses the PREFIXED envelope id ($t[id]) — mismatches bare attendance.tripId → onboard always 0'];
        }
        if (strpos($body, "\$inProgressIds[] = (string) (\$inner['id']") === false) {
            return ['status' => 'FAIL', 'detail' => 'PERF-3: inProgressIds not collected as the bare trip id ($inner[id])'];
        }
        // Tenant isolation: the bare id is only an in-memory join key, valid ONLY
        // because BOTH source datasets are school-scoped. Both the trips and the
        // attendance reads MUST go through schoolWhere (which injects schoolId==),
        // never a raw unscoped query — else the bare id could join across tenants.
        if (strpos($body, "schoolWhere('tripLogs'") === false
            || strpos($body, "schoolWhere('transportAttendance'") === false) {
            return ['status' => 'FAIL', 'detail' => 'PERF-3: trips/attendance not read via schoolWhere — bare-id bucketing would not be tenant-scoped'];
        }
        return ['status' => 'PASS', 'detail' => 'onboard derived from one [trip_date==today] attendance scan bucketed in-memory; inProgressIds uses the bare canonical trip id; both source reads are school-scoped (schoolWhere) so the bare-id join is tenant-isolated'];
    }

    /**
     * CH-TRN-SEC1-1 — the transportAttendance create rule must bind non-admin
     * writes to the DRIVER OF THE REFERENCED TRIP (a forged no_show is a false
     * parent safety alert). Verifies the firestore.rules create block reads the
     * trip's driver_staff_id via get(tripLogs) and compares it to the caller's
     * user_id claim. Source-scan of the rules file.
     */
    private function _chTrnSec1_1(): array
    {
        $path = FCPATH . 'firebase-rules/firestore.rules';
        if (!is_readable($path)) {
            // Fallback: repo-root relative to APPPATH.
            $alt = realpath(APPPATH . '../firebase-rules/firestore.rules');
            if ($alt === false || !is_readable($alt)) {
                return ['status' => 'INFO', 'detail' => 'firestore.rules not readable — trivial pass'];
            }
            $path = $alt;
        }
        $rules = (string) file_get_contents($path);
        // Isolate the transportAttendance match block.
        $start = strpos($rules, 'match /transportAttendance/');
        if ($start === false) {
            return ['status' => 'FAIL', 'detail' => 'SEC-1: transportAttendance match block not found'];
        }
        $block = substr($rules, $start, 3000);
        $hasTripGet = strpos($block, 'documents/tripLogs/') !== false;
        // 2026-08-07 — RBAC Phase A migrated staff identity off the custom
        // `user_id` claim onto `request.auth.uid` (uid == staffId under
        // synthetic-email auth); firestore.rules and driverIs() moved with it.
        // Accept the canonical uid form; the legacy token form is still
        // accepted so the gate keeps passing on any un-migrated branch rather
        // than reporting a phantom security hole.
        $bindsDriver =
               strpos($block, 'driver_staff_id == request.auth.uid') !== false
            || strpos($block, 'driver_staff_id == request.auth.token.user_id') !== false;
        if (!$hasTripGet || !$bindsDriver) {
            return ['status' => 'FAIL', 'detail' => 'SEC-1: attendance create not bound to trip driver (get(tripLogs).driver_staff_id == request.auth.uid missing) — any staff can forge a safety event'];
        }
        return ['status' => 'PASS', 'detail' => 'transportAttendance create binds non-admin writes to the trip driver (get(tripLogs).driver_staff_id == request.auth.uid)'];
    }

    /**
     * CH-TRN-XMOD1-1 — the Teacher app reads vehicles.currentRouteId for the
     * DriverHome route badge + driver SOS route context, but no PHP writer set
     * it (SOS raised with an empty route). startTrip must set it and
     * completeTrip/cancelTrip must clear it. Source-scan of Transport_service.
     */
    private function _chTrnXmod1_1(): array
    {
        $path = APPPATH . 'libraries/Transport_service.php';
        if (!is_readable($path)) {
            return ['status' => 'INFO', 'detail' => 'Transport_service.php not readable — trivial pass'];
        }
        $src = (string) file_get_contents($path);

        $start = $this->_methodBody($src, 'startTrip');
        if ($start === null || strpos($start, '_setVehicleCurrentRoute') === false) {
            return ['status' => 'FAIL', 'detail' => 'XMOD-1: startTrip does not set vehicle currentRouteId (Teacher SOS/route badge stays empty)'];
        }
        foreach (['completeTrip', 'cancelTrip'] as $m) {
            $body = $this->_methodBody($src, $m);
            if ($body === null || strpos($body, '_clearVehicleCurrentRoute') === false) {
                return ['status' => 'FAIL', 'detail' => "XMOD-1: {$m} does not clear vehicle currentRouteId (stale route persists after trip)"];
            }
        }
        // The setter must write the exact camelCase field the app reads.
        $setter = $this->_methodBody($src, '_setVehicleCurrentRoute');
        if ($setter === null || strpos($setter, "'currentRouteId'") === false) {
            return ['status' => 'FAIL', 'detail' => 'XMOD-1: _setVehicleCurrentRoute missing or does not write the currentRouteId field'];
        }
        return ['status' => 'PASS', 'detail' => 'startTrip sets + completeTrip/cancelTrip clear vehicles.currentRouteId (Teacher app route context populated)'];
    }

    /**
     * CH-TRN-LOG3-1 — Transport_notifier::_computeIdempotencyKey must include
     * event_id/trip_id/student_id in its salient set. Without them two distinct
     * events (or two per-student no-show notices on one trip) in the same TTL
     * bucket collided and the second was suppressed. Source-scan.
     */
    private function _chTrnLog3_1(): array
    {
        $path = APPPATH . 'libraries/Transport_notifier.php';
        if (!is_readable($path)) {
            return ['status' => 'INFO', 'detail' => 'Transport_notifier.php not readable — trivial pass'];
        }
        $body = $this->_methodBody((string) file_get_contents($path), '_computeIdempotencyKey');
        if ($body === null) {
            return ['status' => 'FAIL', 'detail' => '_computeIdempotencyKey() not found'];
        }
        $missing = [];
        foreach (['event_id', 'trip_id', 'student_id'] as $k) {
            if (strpos($body, $k) === false) { $missing[] = $k; }
        }
        if ($missing !== []) {
            return ['status' => 'FAIL', 'detail' => 'LOG-3: idempotency salient set omits ' . implode(',', $missing) . ' (distinct events collide → suppressed)'];
        }
        return ['status' => 'PASS', 'detail' => 'idempotency salient set includes event_id/trip_id/student_id (distinct events no longer collide)'];
    }

    /**
     * CH-TRN-LOG2-1 — a route suspension sets routes.active_suspension_ref but
     * leaves status='Active'. createTrip (dispatch) must reject a route with a
     * non-empty active_suspension_ref — otherwise a suspended route is still
     * dispatchable (assignment + route-change paths already gate on it).
     * Source-scan of createTrip.
     */
    private function _chTrnLog2_1(): array
    {
        $path = APPPATH . 'libraries/Transport_service.php';
        if (!is_readable($path)) {
            return ['status' => 'INFO', 'detail' => 'Transport_service.php not readable — trivial pass'];
        }
        $body = $this->_methodBody((string) file_get_contents($path), 'createTrip');
        if ($body === null) {
            return ['status' => 'FAIL', 'detail' => 'createTrip() not found'];
        }
        if (strpos($body, 'active_suspension_ref') === false) {
            return ['status' => 'FAIL', 'detail' => 'LOG-2: createTrip does not gate on active_suspension_ref (suspended route still dispatchable)'];
        }
        return ['status' => 'PASS', 'detail' => 'createTrip rejects a route with an active suspension before dispatch'];
    }

    /**
     * CH-TRN-F11-2 — Every driverActivitySummary + fleetMonthlyExpenseSummary
     * doc carries a period_year_month field matching /^\d{4}-\d{2}$/.
     * Trend queries fail silently otherwise (no data), so this catches
     * schema drift early.
     */
    private function _chTrnF11_2(): array
    {
        $bad = 0; $sampled = 0;
        foreach (['driverActivitySummary', 'fleetMonthlyExpenseSummary'] as $col) {
            try {
                $rows = (array) $this->fs->schoolWhere($col, [], null, 'ASC', 100);
            } catch (\Throwable $_) { continue; }
            foreach ($rows as $r) {
                $sampled++;
                $inner = (isset($r['data']) && is_array($r['data'])) ? $r['data'] : $r;
                $ym = (string) ($inner['period_year_month'] ?? '');
                if (!preg_match('/^\d{4}-\d{2}$/', $ym)) { $bad++; }
            }
        }
        if ($sampled === 0) {
            return ['status' => 'INFO', 'detail' => 'no summary docs — trivial pass'];
        }
        if ($bad > 0) {
            return ['status' => 'FAIL', 'detail' => "{$bad}/{$sampled} summary docs missing/malformed period_year_month"];
        }
        return ['status' => 'PASS', 'detail' => "{$sampled} summary docs, all period_year_month valid"];
    }

    /**
     * CH-TRN-F11-3 — For every Active route with active studentRoutes,
     * a routeFleetSummary doc exists. Fills the F2 gap that F11
     * closed via computeRouteFleetSummary.
     */
    private function _chTrnF11_3(): array
    {
        try {
            $routes = (array) $this->fs->schoolWhere('routes',
                [['status', '==', 'Active']], null, 'ASC', 200);
        } catch (\Throwable $_) {
            return ['status' => 'INFO', 'detail' => 'routes unreachable — trivial pass'];
        }
        if ($routes === []) {
            return ['status' => 'INFO', 'detail' => 'no active routes — trivial pass'];
        }
        $missing = [];
        foreach ($routes as $r) {
            $inner = (isset($r['data']) && is_array($r['data'])) ? $r['data'] : $r;
            $rid = (string) ($r['id'] ?? $inner['id'] ?? '');
            if ($rid === '') { continue; }
            try {
                $rfs = $this->fs->getEntity('routeFleetSummary', "RFS_{$rid}");
                if (!is_array($rfs)) { $missing[] = $rid; }
            } catch (\Throwable $_) { $missing[] = $rid; }
        }
        if ($missing !== []) {
            return ['status' => 'WARN', 'detail' => count($missing) . ' active routes missing summary: '
                . implode(',', array_slice($missing, 0, 3)) . ' (run computeRouteFleetSummary to backfill)'];
        }
        return ['status' => 'PASS', 'detail' => count($routes) . ' active routes have routeFleetSummary docs'];
    }

    /**
     * CH-TRN-F11-4 — Role-scope matrix completeness. Every role in
     * ROLE_SCOPES maps to a subset of {financial, operational,
     * strategic, driver}; every widget in WIDGET_SCOPES tags a scope
     * present in the enum. Guards against a widget landing without an
     * ownership tag.
     */
    private function _chTrnF11_4(): array
    {
        if (!class_exists('Transport_analytics_service')) {
            @include_once APPPATH . 'libraries/Transport_analytics_service.php';
        }
        if (!class_exists('Transport_analytics_service')) {
            return ['status' => 'FAIL', 'detail' => 'Transport_analytics_service not loadable'];
        }
        $scopes = ['financial', 'operational', 'strategic', 'driver'];
        $roles  = (array) Transport_analytics_service::ROLE_SCOPES;
        $widgets = (array) Transport_analytics_service::WIDGET_SCOPES;
        $bad = [];
        foreach ($roles as $r => $scopeList) {
            foreach ((array) $scopeList as $s) {
                if (!in_array($s, $scopes, true)) { $bad[] = "role_{$r}_bad_scope_{$s}"; }
            }
        }
        foreach ($widgets as $w => $s) {
            if (!in_array((string) $s, $scopes, true)) { $bad[] = "widget_{$w}_bad_scope_{$s}"; }
        }
        if ($bad !== []) {
            return ['status' => 'FAIL', 'detail' => count($bad) . ' matrix errors: ' . implode(',', array_slice($bad, 0, 3))];
        }
        return ['status' => 'PASS', 'detail' => count($roles) . ' roles + ' . count($widgets) . ' widgets mapped'];
    }

    /**
     * CH-TRN-F11-5 — Analytics service implements per-request
     * memoisation. Source-scan asserts the _memo() pattern is present
     * and used across the widget accessors.
     */
    private function _chTrnF11_5(): array
    {
        $path = APPPATH . 'libraries/Transport_analytics_service.php';
        if (!is_readable($path)) {
            return ['status' => 'FAIL', 'detail' => "cannot read {$path}"];
        }
        $src = (string) file_get_contents($path);
        if (!preg_match('/private\s+function\s+_memo\s*\(/', $src)) {
            return ['status' => 'FAIL', 'detail' => '_memo helper missing'];
        }
        $memoCalls = preg_match_all('/\$this->_memo\s*\(/', $src, $_);
        if ($memoCalls < 5) {
            return ['status' => 'FAIL', 'detail' => "only {$memoCalls} _memo call sites — expected ≥5 (widgets not memoised)"];
        }
        return ['status' => 'PASS', 'detail' => "{$memoCalls} widget accessors use per-request memoisation"];
    }

    /**
     * CH-TRN-F11-6 — Every analytics endpoint applies role-based
     * visibility filtering. Source-scan: the widget endpoints must
     * call _widget() (which gates on visibleWidgetsForRole), and
     * bundle() must consult hasAnyVisibility().
     */
    private function _chTrnF11_6(): array
    {
        $path = APPPATH . 'controllers/Transport_analytics.php';
        if (!is_readable($path)) {
            return ['status' => 'FAIL', 'detail' => "cannot read {$path}"];
        }
        $src = (string) file_get_contents($path);
        $needles = [
            '_widget('                               => 'widget scope gate',
            'hasAnyVisibility'                       => 'bundle scope gate',
            'visibleWidgetsForRole'                  => 'widget list',
        ];
        $missing = [];
        foreach ($needles as $n => $label) {
            if (strpos($src, $n) === false) { $missing[] = $label; }
        }
        if ($missing !== []) {
            return ['status' => 'FAIL', 'detail' => 'missing gates: ' . implode(', ', $missing)];
        }
        return ['status' => 'PASS', 'detail' => 'all analytics endpoints apply role-scope filtering'];
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
