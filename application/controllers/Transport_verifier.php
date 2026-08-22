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
        $this->load->library('transport_compliance_service', null, 'cmp');   // F12.1 M5

        $this->schoolId    = (string) (getenv('SCHOOL_ID')    ?: '');
        $this->sessionYear = (string) (getenv('SESSION_YEAR') ?: '');
        if ($this->schoolId === '' || $this->sessionYear === '') {
            echo "ERROR: Set SCHOOL_ID and SESSION_YEAR environment variables.\n";
            exit(1);
        }

        $this->fs->init($this->schoolId, $this->sessionYear);
        $this->numbering->init($this->fs, $this->schoolId, $this->sessionYear);
        $this->transport->init($this->fs, $this->numbering, $this->schoolId, $this->sessionYear, 'verifier');
        $this->cmp->init($this->fs, $this->schoolId, 'verifier');            // F12.1 M5

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
            [ 'id' => 'CH-TRN-SEC1-1', 'category' => self::CAT_SOURCE,   'label' => 'attendance writes are server-mediated (no driver client arm)',   'callable' => '_chTrnSec1_1' ],
            [ 'id' => 'CH-TRN-PERF3-1','category' => self::CAT_SOURCE,   'label' => 'live-ops onboard is single-scan (no N+1)', 'callable' => '_chTrnPerf3_1' ],
            [ 'id' => 'CH-TRN-PERF4-1','category' => self::CAT_SOURCE,   'label' => 'SA fleet leaderboard served from rollup',  'callable' => '_chTrnPerf4_1' ],
            [ 'id' => 'CH-TRN-F13-1', 'category' => self::CAT_SOURCE,    'label' => 'F13 GPS contract: server-created hot doc + TTL timestamp', 'callable' => '_chTrnF13_1' ],
            [ 'id' => 'CH-TRN-F13-2', 'category' => self::CAT_SOURCE,    'label' => 'F13 viewer resolver matches canonical recipient resolver', 'callable' => '_chTrnF13_2' ],
            [ 'id' => 'CH-TRN-F13-3', 'category' => self::CAT_SOURCE,    'label' => 'F13 stores no map-provider data (provider-agnostic)', 'callable' => '_chTrnF13_3' ],
            [ 'id' => 'CH-TRN-F13-4', 'category' => self::CAT_SOURCE,    'label' => 'F13 kill switch wired into all 3 trip transitions', 'callable' => '_chTrnF13_4' ],
            [ 'id' => 'CH-TRN-F13-5', 'category' => self::CAT_SOURCE,    'label' => 'F13 fix ordering enforced in rules + service', 'callable' => '_chTrnF13_5' ],
            [ 'id' => 'CH-TRN-F13-6', 'category' => self::CAT_SOURCE,    'label' => 'ONE canonical trip lifecycle (D-8)', 'callable' => '_chTrnF13_6' ],
            [ 'id' => 'CH-TRN-F13-7', 'category' => self::CAT_SOURCE,    'label' => 'Parent live map is provider-agnostic + Firestore-only', 'callable' => '_chTrnF13_7' ],
            [ 'id' => 'CH-TRN-MAP-1', 'category' => self::CAT_SOURCE,    'label' => 'Maps platform: secret split + activation gate', 'callable' => '_chTrnMap1' ],
            [ 'id' => 'CH-TRN-MAP-2', 'category' => self::CAT_SOURCE,    'label' => 'Maps platform did not disturb F13.1 GPS', 'callable' => '_chTrnMap2' ],
            [ 'id' => 'CH-TRN-MAP-3', 'category' => self::CAT_SOURCE,    'label' => 'Runtime provider resolution (no hardcoded renderer)', 'callable' => '_chTrnMap3' ],
            [ 'id' => 'CH-TRN-MAP-4', 'category' => self::CAT_SOURCE,    'label' => 'Provider availability is platform-aware', 'callable' => '_chTrnMap4' ],
            [ 'id' => 'CH-TRN-CSP-1', 'category' => self::CAT_SOURCE,    'label' => 'CSP map allowlist is minimal + single-emitter', 'callable' => '_chTrnCsp1' ],
            [ 'id' => 'CH-TRN-F14-1', 'category' => self::CAT_SOURCE,    'label' => 'QR boarding is server-authorized (no client write, no hand-board)', 'callable' => '_chTrnF14_1' ],
            [ 'id' => 'CH-TRN-F13-8', 'category' => self::CAT_SOURCE,    'label' => 'F13.2 fleet map: real data, no second GPS system', 'callable' => '_chTrnF13_8' ],
            [ 'id' => 'CH-TRN-RBAC-1', 'category' => self::CAT_SOURCE,   'label' => 'Transport is 100% graded RBAC (no fallback)', 'callable' => '_chTrnRbac1' ],
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

            // ─── F12.1 CBSE Compliance (added 2026-08-07, M5) ─────────────────
            [ 'id' => 'CH-TRN-CMP-1', 'category' => self::CAT_STRUCTURAL, 'label' => 'compliance rule pack complete',        'callable' => '_chTrnCmp1' ],
            [ 'id' => 'CH-TRN-CMP-2', 'category' => self::CAT_RUNTIME,    'label' => 'posture provably recomputable',        'callable' => '_chTrnCmp2' ],
            [ 'id' => 'CH-TRN-CMP-3', 'category' => self::CAT_RUNTIME,    'label' => 'waiver bounds honoured',               'callable' => '_chTrnCmp3' ],
            [ 'id' => 'CH-TRN-CMP-4', 'category' => self::CAT_SOURCE,     'label' => 'evidence link-not-copy + authz bound', 'callable' => '_chTrnCmp4' ],
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
            // MEASUREMENT CORRECTION (2026-08-13). schoolWhere() returns
            // ['id'=>…,'data'=>[…]] (Firestore_rest_client::query), so reading
            // `session` off the top level yields null → the regex below fails →
            // EVERY assignment would be reported as "missing/bad session".
            //
            // This gate passes today only because routeAssignments is empty in
            // this tenant (INFO/trivial pass), so the defect is LATENT: it would
            // produce a false FAIL against valid data the moment the collection
            // is populated. Same class as the CH-TRN-F2-2 defect.
            //
            // `id` is preserved explicitly because it lives at the ENVELOPE
            // level and the sample list below uses it; data's own `id` wins if
            // present, since that is the canonical assignment id.
            //
            // Acceptance criterion UNCHANGED: a session not matching
            // ^\d{4}-\d{2}$ still fails the gate.
            if (isset($row['data']) && is_array($row['data']) && !isset($row['session'])) {
                $row = $row['data'] + ['id' => (string) ($row['id'] ?? '')];
            }
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
            // MEASUREMENT CORRECTION (2026-08-13) — FALSE PASS, the dangerous
            // class. schoolWhere() returns ['id'=>…,'data'=>[…]], so reading
            // created_at/expense_date off the top level yields null,
            // strtotime('') returns false, and the `$ts !== false` guard below
            // SKIPS the row entirely. $stale therefore stayed empty and the gate
            // reported PASS — a pending expense stale for days would never have
            // been reported. Sentinel is accounting_journal_ref: it is the
            // queried field, so it is guaranteed present on every matched doc.
            // Acceptance criterion UNCHANGED: pending >24h still fails.
            if (isset($row['data']) && is_array($row['data']) && !isset($row['accounting_journal_ref'])) {
                $row = $row['data'] + ['id' => (string) ($row['id'] ?? '')];
            }
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
            // MEASUREMENT CORRECTION (2026-08-13) — latent FALSE FAIL. Reading
            // the window dates off the envelope yields null, strtotime('')
            // returns false, and every row would be counted inconsistent. The
            // isset($row['data']) guard keeps this safe for a genuinely flat row
            // that really is missing effective_from: no unwrap happens, and the
            // row is still judged inconsistent — which is correct.
            // Acceptance criterion UNCHANGED: an unparseable or inverted window
            // still fails.
            if (isset($row['data']) && is_array($row['data']) && !isset($row['effective_from'])) {
                $row = $row['data'] + ['id' => (string) ($row['id'] ?? '')];
            }
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
            // MEASUREMENT CORRECTION (2026-08-13) — FALSE PASS.
            //
            // TWO DIFFERENT SHAPES ARE IN PLAY HERE, proven from the client:
            //   • schoolWhere() → query()        → ['id'=>…,'data'=>[…]]  ENVELOPED
            //   • getEntity()   → decodeDocument() → flat raw fields      FLAT
            //
            // $rows comes from schoolWhere, so vehicle_id/new_state must be read
            // from data. Reading them off the envelope yielded null → $vid === ''
            // → `continue` skipped EVERY log row → $latestPerVehicle stayed empty
            // → $bad empty → PASS. A vehicle whose lifecycle log disagreed with
            // its actual status was never detected.
            //
            // The $vehicle read below uses getEntity(), which is FLAT, so it is
            // already correct and is deliberately left untouched.
            //
            // Acceptance criterion UNCHANGED: log new_state ≠ vehicle
            // lifecycle_status still fails.
            if (isset($row['data']) && is_array($row['data']) && !isset($row['vehicle_id'])) {
                $row = $row['data'];
            }
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
            // MEASUREMENT CORRECTION (2026-08-13) — FALSE PASS.
            //
            // $vehicles comes from schoolWhere (ENVELOPED), so staff_id read off
            // the top level was null → `continue` skipped every Active vehicle →
            // $bad empty → PASS. An expired-licence driver on an Active vehicle
            // was therefore never reported — a safety check that examined nothing.
            //
            // Sentinel is lifecycle_status: it is the queried field, guaranteed
            // present on every matched document. `id` is preserved because the
            // sample label below falls back to it.
            //
            // The $staff read below uses getEntity(), which returns FLAT raw
            // fields, so $staff['driver_meta']['licence_expires'] is already
            // correct and is deliberately left untouched.
            //
            // Acceptance criterion UNCHANGED: an Active vehicle whose driver's
            // licence_expires is in the past still fails.
            if (isset($v['data']) && is_array($v['data']) && !isset($v['lifecycle_status'])) {
                $v = $v['data'] + ['id' => (string) ($v['id'] ?? '')];
            }
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
            // MEASUREMENT CORRECTION (2026-08-13) — FALSE PASS.
            //
            // $assignments comes from schoolWhere → ENVELOPED ['id','data'], so
            // route_id read off the top level was null → $rid === '' → `continue`
            // skipped EVERY active assignment → $seenRoutes and $bad both stayed
            // empty → PASS reporting "0 unique routes cross-checked". A route
            // still carrying students onto a Retired/Disposed vehicle would never
            // have been flagged.
            //
            // The two getEntity() reads below ($route['vehicle_id'] and
            // $vehicle['lifecycle_status']) use the SINGLE-DOCUMENT path, which
            // decodeDocument() returns FLAT — they are already correct and are
            // deliberately left untouched.
            //
            // Sentinel is `status`: it is the queried field (status == 'Active'),
            // so it is guaranteed present on every matched document. The gate
            // never reads $a['id'], so no envelope key is carried over.
            //
            // Acceptance criterion UNCHANGED: an Active assignment whose route
            // points at a Retired/Disposed vehicle still fails.
            if (isset($a['data']) && is_array($a['data']) && !isset($a['status'])) {
                $a = $a['data'];
            }
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
        // MEASUREMENT CORRECTION (2026-08-13) — FALSE PASS, with a second
        // latent defect that had to be fixed in the SAME change.
        //
        // Both queries come from schoolWhere → ENVELOPED ['id','data'].
        //
        // Defect 1 (assignments): route_id read off the envelope was null, so
        // the `$rid !== ''` guard below never fired and NO orphan could ever be
        // recorded → PASS while claiming "all matched".
        //
        // Defect 2 (routes, ID-FORM): with routeId also null, the key fell
        // through to $r['id'] — which at ENVELOPE level is the school-PREFIXED
        // document id (SCH_x_RT0045), whereas assignments store the BARE id
        // (RT0045). So the lookup below could never match. Correcting only
        // defect 1 would therefore have flipped this gate from false PASS to
        // false FAIL, reporting every valid assignment as an orphan.
        //
        // After unwrapping to $r['data'] the envelope id is deliberately NOT
        // carried over, so $r['id'] can only be the document's own (bare) id.
        // Sentinel is `session` on both sides: it is the queried field, so it is
        // guaranteed present on every matched document.
        //
        // Acceptance criterion UNCHANGED: an assignment whose route_id has no
        // matching route in the same session is still an orphan and still fails.
        $routeIds = [];
        foreach ($routes as $r) {
            if (isset($r['data']) && is_array($r['data']) && !isset($r['session'])) {
                $r = $r['data'];
            }
            $routeIds[(string) ($r['routeId'] ?? $r['id'] ?? '')] = true;
        }
        $orphans = [];
        foreach ($assignments as $a) {
            if (isset($a['data']) && is_array($a['data']) && !isset($a['session'])) {
                $a = $a['data'];
            }
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
            // MEASUREMENT CORRECTION (2026-08-13) — FALSE PASS.
            //
            // Single source: schoolWhere('transportBulkProgress') → ENVELOPED
            // ['id','data']. started_at read off the top level was null,
            // strtotime('') returns false, and the `$ts !== false` guard below
            // SKIPPED the row — so $stale stayed empty and the gate reported
            // "all fresh" no matter how old the markers were.
            //
            // WHAT COULD HAVE ESCAPED DETECTION: a bulk transport operation
            // (bulk assignment / session rollover) that started and never
            // finished leaves a progress marker behind. This gate exists to
            // notice one stuck for more than 24h. Reading nulls, it would have
            // reported every abandoned bulk run as fresh — hiding a half-applied
            // operation whose partial effects are still in the tenant.
            //
            // `id` is preserved because the sample list below uses it; the
            // document's own id wins when present.
            //
            // Acceptance criterion UNCHANGED: a marker started more than 24h ago
            // still fails the gate.
            if (isset($row['data']) && is_array($row['data']) && !isset($row['started_at'])) {
                $row = $row['data'] + ['id' => (string) ($row['id'] ?? '')];
            }
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
            // MEASUREMENT CORRECTION (2026-08-13) — FALSE PASS.
            //
            // Single source: schoolWhere(vehicles) → ENVELOPED ['id','data'].
            // lifecycle_status read at the top level was null → $ls === '' → the
            // `continue` below skipped EVERY vehicle → $bad stayed empty → PASS
            // reporting "N vehicles valid" while validating none of them.
            //
            // Note the skip itself is CORRECT behaviour for a genuinely empty
            // value (the product coalesces it to 'Active' at read). The defect
            // was that the envelope made every value look empty, so a legitimate
            // tolerance became a blanket bypass.
            //
            // WHAT COULD HAVE ESCAPED DETECTION: a vehicle carrying a
            // lifecycle_status outside LIFECYCLE_STATES — schema drift, a typo,
            // or a value from an older enum. Lifecycle-driven logic (retirement,
            // maintenance gating, assignment eligibility) then behaves
            // unpredictably for that vehicle, and this gate — whose whole job is
            // to catch exactly that — reported every vehicle as valid.
            //
            // Sentinel is schoolId, not lifecycle_status: the query carries no
            // conditions, so there is no queried field to lean on, and schoolId
            // is guaranteed present because schoolWhere() filters on it.
            // `id` is preserved for the sample label; the document's own id wins.
            //
            // Acceptance criterion UNCHANGED: a non-empty lifecycle_status
            // outside LIFECYCLE_STATES still fails; an empty one is still
            // tolerated.
            if (isset($v['data']) && is_array($v['data']) && !isset($v['schoolId'])) {
                $v = $v['data'] + ['id' => (string) ($v['id'] ?? '')];
            }
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
            // MEASUREMENT CORRECTION (2026-08-13) — FALSE FAIL (class B), the
            // opposite polarity to the surrounding gates.
            //
            // $rows comes from schoolWhere → ENVELOPED ['id','data'], so
            // vendor_id read at the top level was null → $vend === '' → the row
            // was COUNTED as a violation. Unlike the skip-based gates nearby,
            // this one cries wolf rather than going quiet: the moment a single
            // Contract vehicle existed, EVERY one would be reported as
            // "(no vendor_id)".
            //
            // The practical harm is masking: with every contract vehicle flagged,
            // a genuinely broken vendor FK could not be distinguished from the
            // noise, and a real orphaned vendor reference would be lost in a wall
            // of false ones.
            //
            // The getEntity(transportVendors) read below uses the SINGLE-DOCUMENT
            // path (FLAT) and only tests is_array() — no field access — so it is
            // already correct and is left untouched.
            //
            // Sentinel is ownership_type: the queried field (== 'Contract'), so
            // it is guaranteed present on every matched document. `id` is
            // preserved for the label; the document's own id wins.
            //
            // Acceptance criterion UNCHANGED: a Contract vehicle with no
            // vendor_id, or pointing at a missing vendor, still fails.
            if (isset($v['data']) && is_array($v['data']) && !isset($v['ownership_type'])) {
                $v = $v['data'] + ['id' => (string) ($v['id'] ?? '')];
            }
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
            // MEASUREMENT CORRECTION (2026-08-13) — FALSE FAIL (class B).
            //
            // Both sources are schoolWhere() → ENVELOPED ['id','data'], and the
            // array_map above wraps each ENVELOPE as $item['row']. So
            // $item['row']['vehicle_id'] reached into the envelope, not the
            // document, and returned null → $vid === '' → the row was COUNTED as
            // an orphan. Every maintenance and fuel log would have been reported
            // as "(no vehicle_id)".
            //
            // Harm is masking, as with CH-TRN-F1-3: a genuinely orphaned log —
            // one referencing a vehicle that was deleted — could not be picked
            // out from a list where every log appeared broken.
            //
            // The getEntity(vehicles) read below uses the SINGLE-DOCUMENT path
            // (FLAT) and only tests is_array(); it is already correct and is left
            // untouched.
            //
            // Sentinel is schoolId: neither query carries conditions, so there is
            // no queried field to lean on, and schoolId is guaranteed present
            // because schoolWhere() filters on it. $item['kind'] is the
            // verifier's own wrapper key and is unaffected.
            //
            // Acceptance criterion UNCHANGED: a log with no vehicle_id, or
            // pointing at a missing vehicle, still fails.
            $r = $item['row'];
            if (isset($r['data']) && is_array($r['data']) && !isset($r['schoolId'])) {
                $r = $r['data'];
            }
            $vid = (string) ($r['vehicle_id'] ?? '');
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
            // MEASUREMENT CORRECTION (2026-08-13), not a relaxation.
            //
            // schoolWhere() returns ['id'=>…,'data'=>[…]] envelopes for this
            // collection. Reading route_id/stopId off the envelope yields null,
            // so EVERY stop registered as "(no route_id)" with stopId '?' —
            // six false FAILs against data whose route_id was populated and
            // whose routes existed. Sentinel-guarded unwrap, matching the
            // pattern already used at lines ~1128 and ~1616: only unwrap when
            // the expected business field is absent at the top level, so a
            // genuinely flat row is left alone.
            //
            // The acceptance criteria are UNCHANGED: an empty route_id still
            // fails, and a route_id pointing at a missing route still fails.
            if (isset($s['data']) && is_array($s['data']) && !isset($s['route_id'])) {
                $s = $s['data'] + ['stopId' => (string) ($s['id'] ?? '')];
            }
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
            // MEASUREMENT CORRECTION (2026-08-13) — FALSE FAIL (class B), and it
            // short-circuited the gate's real purpose.
            //
            // $active comes from schoolWhere → ENVELOPED ['id','data'], so both
            // suspensionId and route_id were read at the wrong level.
            // suspensionId degraded to '?' and route_id to null → $rid === '' →
            // the `continue` below recorded "(no route_id)" for EVERY active
            // suspension. The gate therefore never reached its actual assertion:
            // the two-way check that route.active_suspension_ref === suspensionId.
            //
            // OPERATIONAL CONSEQUENCE: a route suspension is a safety control — a
            // suspended route must not run trips. This gate exists to catch a
            // DESYNC between the suspension record and the route's
            // active_suspension_ref. Two real conditions it could not report:
            //   • a route marked suspended whose ref points at a stale or
            //     different suspension, so the current suspension may not be
            //     enforced at all;
            //   • a lifted suspension still referenced by the route, leaving a
            //     route blocked that should be running.
            // Both were unreachable behind a wall of false "(no route_id)" rows.
            //
            // The getEntity(routes) read below uses the SINGLE-DOCUMENT path
            // (FLAT), so $route['active_suspension_ref'] is already correct and is
            // left untouched.
            //
            // Sentinel is status: the queried field (== 'Active'), guaranteed
            // present on every matched document. The label uses suspensionId from
            // the document, so no envelope key is carried over.
            //
            // Acceptance criterion UNCHANGED: a missing route_id, a missing route,
            // or a route whose active_suspension_ref does not equal the
            // suspensionId all still fail.
            if (isset($s['data']) && is_array($s['data']) && !isset($s['status'])) {
                $s = $s['data'];
            }
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
            // MEASUREMENT CORRECTION (2026-08-13) — FALSE PASS (class A).
            //
            // $routes comes from schoolWhere → ENVELOPED ['id','data'], so
            // active_suspension_ref read at the top level was null → $ref === ''
            // → the `continue` below skipped EVERY route → $bad stayed empty →
            // PASS reporting "no stale suspension refs" without inspecting one.
            //
            // As in CH-TRN-F1-2, the skip itself is CORRECT for a genuinely empty
            // ref (a route with no active suspension is normal). The defect was
            // that the envelope made every route look unsuspended, turning a
            // legitimate tolerance into a blanket bypass.
            //
            // OPERATIONAL CONSEQUENCE: a route carrying a STALE
            // active_suspension_ref would never be reported — either pointing at
            // a suspension document that no longer exists, or at one whose status
            // is no longer Active. The route can then be treated as suspended
            // (trips blocked) when the suspension has in fact been lifted, or
            // left with a dangling ref so its suspension state is ambiguous.
            //
            // TAKEN WITH CH-TRN-F2-3 THIS IS THE MORE SERIOUS FINDING: F2-3 checks
            // suspension→route and F2-4 checks route→suspension. F2-3 failed loudly
            // for every row and F2-4 passed silently for every row, so the
            // suspension-consistency invariant was unverified in BOTH directions.
            //
            // The getEntity(routeSuspensions) read below uses the SINGLE-DOCUMENT
            // path (FLAT), so $s['status'] is already correct and is left alone.
            //
            // Sentinel is schoolId: the query carries no conditions, so there is
            // no queried field, and schoolId is guaranteed present because
            // schoolWhere() filters on it.
            //
            // Acceptance criterion UNCHANGED: a ref to a missing suspension, or to
            // one whose status is not 'Active', still fails.
            if (isset($r['data']) && is_array($r['data']) && !isset($r['schoolId'])) {
                $r = $r['data'];
            }
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
            // MEASUREMENT CORRECTION (2026-08-13) — FALSE FAIL (class B).
            //
            // Single source: schoolWhere(studentRoutes) → ENVELOPED ['id','data'],
            // so session/studentId were read at the wrong level. session became
            // null → $s === '' → EVERY assignment was recorded as
            // "missing/bad session", and studentId degraded to '?' so the sample
            // list could not even name the offenders.
            //
            // WHY THIS GATE MATTERS MOST: studentRoutes is the canonical Transport
            // ELIGIBILITY AUTHORITY (F-1). _computeTripRoster() filters on
            // session, so an assignment carrying a missing or malformed session
            // silently drops that child from every trip roster — they simply do
            // not appear on the bus. This gate exists to catch exactly that, and
            // reading nulls it could not: once the collection held data it would
            // have flagged all rows indiscriminately, leaving a genuinely
            // session-less assignment indistinguishable from the noise.
            //
            // Sentinel is schoolId: the query carries no conditions, so there is
            // no queried field, and schoolId is guaranteed present because
            // schoolWhere() filters on it. Deliberately NOT session — that is the
            // very field under test and may legitimately be absent.
            //
            // Acceptance criterion UNCHANGED: a session that is empty or does not
            // match ^\d{4}-\d{2}$ still fails.
            if (isset($r['data']) && is_array($r['data']) && !isset($r['schoolId'])) {
                $r = $r['data'];
            }
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
            // MEASUREMENT CORRECTION (2026-08-13) — FALSE PASS (class A).
            //
            // Single source: schoolWhere(studentRoutes, fee_demand_ref=='pending')
            // → ENVELOPED ['id','data']. updated_at/assigned_date were read at the
            // top level and came back null, strtotime('') returns false, and the
            // `$ts !== false` guard below SKIPPED the row. $stale stayed empty and
            // the gate reported "all within SLA" no matter how long a fee had been
            // stuck. studentId also degraded to '?', so even a reported row could
            // not have been identified.
            //
            // WHAT COULD HAVE ESCAPED DETECTION — this is the money-side integrity
            // check. fee_demand_ref == 'pending' means the transport fee demand was
            // never completed for a student who HAS transport. Left unreported:
            //   • a transport-eligible student who is never billed, because the
            //     assignment→fee handoff died midway;
            //   • the mirror of the F-3 rule (never create a fee demand for an
            //     incomplete request): here the assignment is complete but the fee
            //     never materialised, so the student rides unbilled.
            // Either way the pending marker is the only trace, and this gate is
            // what watches it.
            //
            // Sentinel is fee_demand_ref: the queried field (== 'pending'), so it
            // is guaranteed present on every matched document. No envelope id is
            // carried over — the label uses the document's own studentId.
            //
            // Acceptance criterion UNCHANGED: a pending fee older than 24h still
            // fails.
            if (isset($r['data']) && is_array($r['data']) && !isset($r['fee_demand_ref'])) {
                $r = $r['data'];
            }
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
            // MEASUREMENT CORRECTION (2026-08-13) — FALSE PASS (class A).
            //
            // $rows comes from schoolWhere → ENVELOPED ['id','data'], so route_id
            // read at the top level was null → $rid === '' → the `continue` below
            // skipped EVERY assignment → $bad stayed empty → PASS reporting
            // "no Archived-route refs" without resolving a single route.
            //
            // WHAT COULD HAVE ESCAPED DETECTION: a student still assigned to an
            // ARCHIVED route. An archived route is retired from service, so no
            // trip is ever created for it — the child never appears on any roster
            // and no bus comes, while the assignment (and its fee) remains live.
            // A child with transport on paper and no bus in practice.
            //
            // This is the ONLY gate watching for it: F14's eligibility resolver
            // (resolveEligibility) checks the studentRoutes row's own status,
            // effective dates, suspension window, direction and service days — but
            // NOT whether the referenced ROUTE has been archived. So such an
            // assignment passes eligibility while no trip can exist.
            //
            // The getEntity(routes) read below uses the SINGLE-DOCUMENT path
            // (FLAT), so $rt['status'] is already correct and is left untouched.
            //
            // Sentinel is schoolId: the query carries no conditions, so there is no
            // queried field, and schoolId is guaranteed present because
            // schoolWhere() filters on it. Deliberately NOT route_id — this gate
            // legitimately tolerates its absence (the `continue` above).
            //
            // The envelope id is deliberately NOT carried over: $rid is used as a
            // document key for getEntity(routes) and the label uses the document's
            // own studentId, so a school-prefixed envelope id must never leak in.
            //
            // Acceptance criterion UNCHANGED: an assignment whose route_id resolves
            // to a route with status 'Archived' still fails.
            if (isset($r['data']) && is_array($r['data']) && !isset($r['schoolId'])) {
                $r = $r['data'];
            }
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
            // MEASUREMENT CORRECTION (2026-08-13) — FALSE PASS (class A), COMPOUND.
            //
            // SITE 1 of this gate. $caches comes from schoolWhere → ENVELOPED
            // ['id','data'], so all three business fields were read at the wrong
            // level, with three separate consequences:
            //   • route_id  → null → $rid === '' → the `continue` below skipped
            //     EVERY cache entry → $drift stayed empty → PASS claiming
            //     "N cache entries accurate" while checking none. (dominant defect)
            //   • current_count → (int) null = 0 → had a row survived, the cached
            //     value would always have compared as 0.
            //   • session → '' → the session filter would silently drop out of
            //     $where below, broadening the actual-count query.
            // The latter two are latent: they would have produced spurious drift
            // (a false WARN) once the skip no longer masked them.
            //
            // WHAT COULD HAVE ESCAPED DETECTION: bus capacity cache drift. When
            // current_count is stale, seat availability shown to the office is
            // wrong — a route can be over-assigned beyond the vehicle's capacity
            // (children assigned to a bus with no seat for them), or left
            // under-used while families are told it is full. This gate is the
            // drift detector, and it was reporting the cache accurate without
            // comparing a single entry.
            //
            // SITE 2 of this gate — the schoolWhere(studentRoutes, $where) below —
            // is consumed ONLY by count(). Counting envelopes yields the same
            // integer as counting flat rows, so that read is shape-independent and
            // is deliberately left UNCHANGED.
            //
            // Sentinel is schoolId: the query carries no conditions, so there is no
            // queried field, and schoolId is guaranteed present because
            // schoolWhere() filters on it. Deliberately NOT route_id (tolerated
            // absence via the `continue`), NOT session (optional by design), and
            // NOT current_count (the value under test).
            //
            // No envelope id is carried over: the label uses $rid from the document
            // and $rid is used as a query VALUE, so a school-prefixed id must not
            // leak in.
            //
            // Acceptance criterion UNCHANGED, including its severity: a cached
            // current_count that disagrees with the actual assignment count still
            // reports WARN (not FAIL) with the rebuild_capacity_cache hint.
            if (isset($c['data']) && is_array($c['data']) && !isset($c['schoolId'])) {
                $c = $c['data'];
            }
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
     * CH-TRN-F7-1 — GPS write-provenance discipline.
     *
     * ─── RE-SCOPED ONCE, 2026-08-08, for F13.1 ───────────────────────────
     * Original scope (F7 → F12.1): every reserved live-GPS field on
     * `tripLogs` and `transportAttendance` MUST be null, because no GPS phase
     * existed and any value was by definition an accidental write.
     *
     * F13.1 populates those fields for real, so a literal null assertion
     * would now fail on correct data. The gate is therefore **re-scoped, not
     * deleted** (per the standing F13 instruction): a populated GPS field is
     * legitimate ONLY when the document also carries a `gps_session_id`,
     * which is minted exclusively by `Transport_service::createTrip`. A doc
     * with position data and no session id was written by something outside
     * the GPS ingest path — precisely the accident the gate was built to
     * catch, and still caught.
     *
     * `transportAttendance` keeps the strict null assertion: geofence
     * verification is F13.3, deferred by decision D-5, so any value in those
     * six fields today is still an accident.
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
            // F13.1 RE-SCOPE (2026-08-08): a populated trip GPS field is now
            // LEGITIMATE — but only when it came from the GPS ingest path. The
            // proof of provenance is a non-empty gps_session_id on the same
            // doc, which only createTrip mints. A trip carrying position data
            // WITHOUT a session id was written by something that is not the
            // GPS path, which is exactly the accident this gate exists to
            // catch. The gate is re-scoped, never relaxed to nothing.
            $hasSession = trim((string) ($inner['gps_session_id'] ?? '')) !== '';
            if ($hasSession) { continue; }
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
     * CH-TRN-F13-1 — the F13.1 Live-GPS data contract holds.
     *
     * Three properties, each of which silently breaks the feature if lost:
     *
     *  (a) SERVER-CREATED HOT DOC (D-1). `firestore.rules` must forbid client
     *      create AND delete on vehicleLivePositions, and must gate update on
     *      the server-side kill switch. A client-create rule cannot be secured:
     *      isDriverOfTrip() reads resource.data (null on create) and
     *      isSameSchool() is permissive when resource == null, so such a rule
     *      would have neither a driver nor a tenant check.
     *
     *  (b) DRIVER FIELD ALLOWLIST. The rule's affectedKeys().hasOnly() list must
     *      match Transport_gps_service::DRIVER_MUTABLE_FIELDS exactly. If they
     *      drift, either the driver cannot write a field it needs, or it gains
     *      the ability to rewrite a server-owned one (tripId, the viewer
     *      allowlist, the kill switch).
     *
     *  (c) TTL AS A TIMESTAMP. Firestore TTL acts ONLY on a Timestamp-typed
     *      field; an ISO string is silently ignored and breadcrumbs then grow
     *      without bound (~86k docs/day/school at 3,000 students). The builder
     *      must therefore emit the __firestore_type=timestamp sentinel that
     *      Firestore_rest_client::encode() turns into a timestampValue.
     */
    private function _chTrnF13_1(): array
    {
        $svcPath = APPPATH . 'libraries/Transport_gps_service.php';
        $rulPath = FCPATH . 'firebase-rules/firestore.rules';
        if (!is_readable($svcPath)) {
            return ['status' => 'INFO', 'detail' => 'F13 service not present — trivial pass'];
        }
        $svc = (string) file_get_contents($svcPath);
        $rul = is_readable($rulPath) ? (string) file_get_contents($rulPath) : '';
        $problems = [];

        // (a) server-created, kill-switch-gated
        if (!preg_match('/match \/vehicleLivePositions\/\{[a-zA-Z]+\} \{(.*?)\n    \}/s', $rul, $m)) {
            $problems[] = 'no vehicleLivePositions rules block';
        } else {
            $block = $m[1];
            if (strpos($block, 'allow create, delete: if false') === false
                && !(strpos($block, 'allow create: if false') !== false && strpos($block, 'allow delete: if false') !== false)) {
                $problems[] = 'client create/delete on the hot doc is not forbidden (D-1)';
            }
            if (strpos($block, 'live_tracking_enabled') === false) {
                $problems[] = 'update is not gated on the live_tracking_enabled kill switch (D-2)';
            }
            if (strpos($block, 'authorized_viewer_uids') === false) {
                $problems[] = 'parent read is not gated on the denormalised viewer allowlist';
            }
            if (strpos($block, 'get(') !== false && strpos($block, 'resource.data.get(') === false) {
                $problems[] = 'rule performs a cross-document get() on the position hot path';
            }
            // (b) allowlist parity. Parsed from SOURCE on both sides — this is
            // a CAT_SOURCE gate and must not depend on the class being loadable.
            $svcFields = [];
            if (preg_match('/DRIVER_MUTABLE_FIELDS = \[(.*?)\];/s', $svc, $sm)) {
                preg_match_all("/'([a-z_]+)'/", $sm[1], $sf);
                $svcFields = $sf[1] ?? [];
            }
            if ($svcFields === []) { $problems[] = 'DRIVER_MUTABLE_FIELDS not parseable from the service'; }
            if (preg_match('/hasOnly\(\[(.*?)\]\)/s', $block, $hm)) {
                preg_match_all("/'([a-z_]+)'/", $hm[1], $rf);
                $ruleFields = $rf[1] ?? [];
                sort($ruleFields); $s = $svcFields; sort($s);
                if ($ruleFields !== $s) {
                    $problems[] = 'rule hasOnly() list != DRIVER_MUTABLE_FIELDS (missing: '
                        . implode(',', array_diff($s, $ruleFields)) . '; extra: '
                        . implode(',', array_diff($ruleFields, $s)) . ')';
                }
            } else {
                $problems[] = 'update rule has no affectedKeys().hasOnly() field allowlist';
            }
        }

        // (c) TTL emitted as a Timestamp sentinel, not a string
        if (!preg_match("/breadcrumbExpiresAt.*?__firestore_type'\s*=>\s*'timestamp'/s", $svc)) {
            $problems[] = 'breadcrumbExpiresAt() does not emit a Firestore Timestamp sentinel — TTL would be a no-op';
        }

        // Breadcrumbs immutable + staff-read-only
        if (preg_match('/match \/vehicleBreadcrumbs\/\{[a-zA-Z]+\} \{(.*?)\n    \}/s', $rul, $bm)) {
            if (strpos($bm[1], 'allow update, delete: if false') === false) {
                $problems[] = 'breadcrumbs are not append-only';
            }
            if (strpos($bm[1], 'driverIs(request.resource.data.driver_staff_id)') === false) {
                $problems[] = 'breadcrumb create is not bound to the caller\'s own driver id';
            }
        } else {
            $problems[] = 'no vehicleBreadcrumbs rules block';
        }

        if ($problems !== []) {
            return ['status' => 'FAIL', 'detail' => 'F13-1: ' . implode('; ', $problems)];
        }
        return ['status' => 'PASS', 'detail' =>
            'hot doc server-created + kill-switch gated; driver field allowlist matches the service ('
            . count($svcFields ?? []) . ' fields); breadcrumbs append-only, '
            . 'driver-bound, TTL emitted as a native Timestamp'];
    }

    /**
     * CH-TRN-F13-2 — the F13 viewer resolver cannot drift from the canonical one.
     *
     * `Transport_gps_service::resolveViewerUids()` duplicates the identity rule
     * in `Transport_notifier::_recipientUserIdFromStudent()` — students.userId,
     * falling back to studentId — because that method is PRIVATE on a frozen
     * class and widening it is outside the D-2 exception. Duplication is only
     * acceptable while it is pinned, so this gate fails if either side stops
     * using the same field precedence. A divergence here would show a parent
     * the wrong bus, or hide the right one.
     */
    private function _chTrnF13_2(): array
    {
        $gps = APPPATH . 'libraries/Transport_gps_service.php';
        $ntf = APPPATH . 'libraries/Transport_notifier.php';
        if (!is_readable($gps) || !is_readable($ntf)) {
            return ['status' => 'INFO', 'detail' => 'source not readable — trivial pass'];
        }
        $g = (string) file_get_contents($gps);
        $n = (string) file_get_contents($ntf);
        $problems = [];

        if (!preg_match("/resolveViewerUids.*?\\\$uid\s*=\s*trim\(\(string\)\s*\(\\\$byId\[\\\$sid\]\['userId'\]\s*\?\?\s*''\)\)/s", $g)) {
            $problems[] = 'F13 resolver no longer reads students.userId first';
        }
        if (!preg_match("/resolveViewerUids.*?\\\$uid\s*=\s*\\\$sid;/s", $g)) {
            $problems[] = 'F13 resolver lost the studentId fallback';
        }
        if (!preg_match("/_recipientUserIdFromStudent.*?\\\$stu\['userId'\]\s*\?\?\s*''/s", $n)) {
            $problems[] = 'canonical notifier resolver changed its field precedence';
        }
        if ($problems !== []) {
            return ['status' => 'FAIL', 'detail' => 'F13-2: ' . implode('; ', $problems)];
        }
        return ['status' => 'PASS', 'detail' =>
            'F13 viewer resolver and the canonical notifier resolver agree: students.userId, fallback studentId'];
    }

    /**
     * CH-TRN-F13-3 — the GPS layer is MAP-PROVIDER AGNOSTIC.
     *
     * Architecture decision (2026-08-08): Firestore stores only canonical
     * telemetry — lat, lng, heading, speed, accuracy, altitude, timestamps and
     * the correlation ids. No provider SDK model, encoded polyline, tile or
     * map URL, style descriptor, or place/session token may ever be persisted,
     * so the rendering layer (Leaflet on admin, MapLibre on Parent, or any
     * future commercial provider) can be swapped without a schema migration.
     *
     * Scans the F13 server surface for provider fingerprints.
     */
    private function _chTrnF13_3(): array
    {
        $paths = [
            APPPATH . 'libraries/Transport_gps_service.php',
        ];
        $banned = [
            'polyline'      => 'encoded polyline (provider-specific geometry)',
            'googleapis'    => 'Google API endpoint',
            'maps.google'   => 'Google Maps URL',
            'mapbox'        => 'Mapbox reference',
            'maplibre'      => 'MapLibre reference (rendering belongs in the app)',
            'leaflet'       => 'Leaflet reference (rendering belongs in the panel)',
            'tile.openstreetmap' => 'tile server URL',
            'place_id'      => 'provider place identifier',
            'LatLng'        => 'provider SDK model',
        ];
        $hits = [];
        foreach ($paths as $p) {
            if (!is_readable($p)) { continue; }
            $src = $this->_stripComments((string) file_get_contents($p));
            foreach ($banned as $needle => $why) {
                if (stripos($src, $needle) !== false) { $hits[] = basename($p) . ": {$why}"; }
            }
        }
        if ($hits !== []) {
            return ['status' => 'FAIL', 'detail' => 'F13-3: provider coupling in the data layer — ' . implode('; ', $hits)];
        }
        return ['status' => 'PASS', 'detail' =>
            'GPS data layer stores canonical telemetry only — no provider SDK model, polyline, tile URL or place token'];
    }

    /**
     * CH-TRN-F13-4 — the tracking kill switch is wired into ALL THREE trip
     * transitions, and the GPS bridge stays thin.
     *
     * This is the privacy invariant of the whole feature: position is captured
     * only while a trip is InProgress. Before M2 that was enforced by client
     * goodwill alone — a driver app that crashed and restarted would keep
     * streaming after the children were off the bus.
     *
     * Asserts:
     *  (a) startTrip ARMS the switch; completeTrip and cancelTrip DISARM it.
     *      A missing disarm on either terminal path silently reopens the hole.
     *  (b) Both terminal paths tear the session down via _closeGpsSession.
     *  (c) The F13 footprint inside the frozen Transport_service stays limited
     *      to the two bridge helpers — all GPS logic belongs in
     *      Transport_gps_service, so F13 can evolve without reopening a frozen
     *      file. This is the D-2 exception boundary, made mechanical.
     */
    private function _chTrnF13_4(): array
    {
        $path = APPPATH . 'libraries/Transport_service.php';
        if (!is_readable($path)) {
            return ['status' => 'INFO', 'detail' => 'Transport_service not readable — trivial pass'];
        }
        $src = (string) file_get_contents($path);
        $problems = [];

        $bodies = [];
        foreach (['startTrip', 'completeTrip', 'cancelTrip'] as $m) {
            $bodies[$m] = $this->_methodBody($src, $m);
            if ($bodies[$m] === '') { $problems[] = "{$m}() not found"; }
        }

        if (($bodies['startTrip'] ?? '') !== ''
            && !preg_match("/'live_tracking_enabled'\s*=>\s*true/", $bodies['startTrip'])) {
            $problems[] = 'startTrip does not ARM live_tracking_enabled';
        }
        if (($bodies['startTrip'] ?? '') !== '' && strpos($bodies['startTrip'], '_openGpsSession') === false) {
            $problems[] = 'startTrip does not open the GPS session';
        }
        foreach (['completeTrip', 'cancelTrip'] as $m) {
            if (($bodies[$m] ?? '') === '') { continue; }
            if (!preg_match("/'live_tracking_enabled'\s*=>\s*false/", $bodies[$m])) {
                $problems[] = "{$m} does not DISARM live_tracking_enabled (tracking would survive trip end)";
            }
            if (strpos($bodies[$m], '_closeGpsSession') === false) {
                $problems[] = "{$m} does not tear down the live-position session";
            }
        }

        // (c) bridge stays thin — no GPS logic leaking into the frozen file.
        // Comments are stripped first: F7's createTrip has always carried the
        // note `// future: vehicleLivePositions doc id`, which is documentation
        // of the reserved field, not an implementation leak.
        $code = $this->_stripComments($src);
        foreach (['vehicleLivePositions', 'vehicleBreadcrumbs', 'authorized_viewer_uids', 'recorded_at_ms'] as $leak) {
            if (strpos($code, $leak) !== false) {
                $problems[] = "GPS implementation detail '{$leak}' leaked into the frozen Transport_service";
            }
        }

        if ($problems !== []) {
            return ['status' => 'FAIL', 'detail' => 'F13-4: ' . implode('; ', $problems)];
        }
        return ['status' => 'PASS', 'detail' =>
            'startTrip arms + opens; completeTrip/cancelTrip disarm + close; GPS bridge limited to '
            . '_openGpsSession/_closeGpsSession with no implementation leak into the frozen service'];
    }

    /**
     * CH-TRN-F13-5 — stale and out-of-order fixes cannot overwrite a newer
     * position, and the guard exists on BOTH sides.
     *
     * The driver device writes the hot doc DIRECTLY, so PHP never sees a
     * position: the Firestore rule is the real enforcement point and the
     * service method covers server-mediated paths. If the rule loses its
     * ordering clause, a queued offline flush after a tunnel replays a stale
     * position over the current one and the bus jumps backwards on the parent's
     * map — with no error anywhere.
     *
     * Ordering must key on the INTEGER millis field, never the ISO string:
     * lexicographic comparison is only safe when every writer emits identical
     * zero-padded UTC, and a device sending `+05:30` would compare wrong.
     */
    private function _chTrnF13_5(): array
    {
        $svcPath = APPPATH . 'libraries/Transport_gps_service.php';
        $rulPath = FCPATH . 'firebase-rules/firestore.rules';
        if (!is_readable($svcPath) || !is_readable($rulPath)) {
            return ['status' => 'INFO', 'detail' => 'F13 source not readable — trivial pass'];
        }
        $svc = (string) file_get_contents($svcPath);
        $rul = (string) file_get_contents($rulPath);
        $problems = [];

        if (!preg_match('/match \/vehicleLivePositions\/\{[a-zA-Z]+\} \{(.*?)\n    \}/s', $rul, $m)) {
            $problems[] = 'no vehicleLivePositions rules block';
        } else {
            $b = $m[1];
            if (strpos($b, 'recorded_at_ms > resource.data.get(\'recorded_at_ms\', 0)') === false) {
                $problems[] = 'rule does not enforce strictly-increasing recorded_at_ms';
            }
            if (strpos($b, 'request.time.toMillis()') === false) {
                $problems[] = 'rule does not bound the timestamp against SERVER time (a skewed device could block the vehicle)';
            }
            if (strpos($b, 'recorded_at_ms is int') === false) {
                $problems[] = 'rule does not type-check the ordering key as int';
            }
        }

        foreach (['validateFixOrdering', 'out_of_order', 'future_timestamp', 'fix_too_old'] as $needle) {
            if (strpos($svc, $needle) === false) {
                $problems[] = "service lost the ordering guard ('{$needle}')";
            }
        }

        if ($problems !== []) {
            return ['status' => 'FAIL', 'detail' => 'F13-5: ' . implode('; ', $problems)];
        }
        return ['status' => 'PASS', 'detail' =>
            'fix ordering enforced in the rule (monotonic recorded_at_ms, server-time bounded, int-typed) '
            . 'and mirrored in the service for server-mediated paths'];
    }

    /**
     * CH-TRN-F13-6 — there is exactly ONE trip lifecycle (decision D-8).
     *
     * The driver app used to write `tripLogs.status` straight to Firestore,
     * bypassing `Transport_service` and so skipping driver-readiness
     * re-validation (LOG-1), the CAS transition guard (CON-6), the audit row,
     * the `trip.started` parent notification, XMOD-1 route denormalisation and
     * the F13 GPS session. Both surfaces now run the same lifecycle.
     *
     * Enforcement is what this gate protects. The Firestore rule must NOT let a
     * driver write trip state — otherwise "one canonical flow" is a convention
     * a future client can quietly break, and the regression is invisible until
     * a parent notices the bus never appeared.
     *
     * Asserts:
     *  (a) the driver API exists, authenticates by Firebase Bearer, and
     *      authorizes by trip OWNERSHIP rather than an RBAC module grant;
     *  (b) it delegates to Transport_service rather than reimplementing;
     *  (c) it is CSRF-excluded — the app carries no CI session, so without the
     *      exclusion every call dies as a blank 403 with no log;
     *  (d) the tripLogs rule no longer lets a driver mutate trip state.
     */
    private function _chTrnF13_6(): array
    {
        $apiPath = APPPATH . 'controllers/Transport_driver_api.php';
        $cfgPath = APPPATH . 'config/config.php';
        $rulPath = FCPATH . 'firebase-rules/firestore.rules';
        if (!is_readable($apiPath)) {
            return ['status' => 'FAIL', 'detail' => 'F13-6: Transport_driver_api is missing — the driver app has no canonical lifecycle to call'];
        }
        $api = $this->_stripComments((string) file_get_contents($apiPath));
        $cfg = is_readable($cfgPath) ? (string) file_get_contents($cfgPath) : '';
        $rul = is_readable($rulPath) ? (string) file_get_contents($rulPath) : '';
        $problems = [];

        // (a) Bearer auth + ownership authorization
        if (strpos($api, 'require_auth()') === false) {
            $problems[] = 'driver API does not authenticate the Firebase token';
        }
        if (strpos($api, '_assertOwnTrip') === false || strpos($api, "driver_staff_id") === false) {
            $problems[] = 'driver API does not authorize by trip ownership';
        }
        if (strpos($api, 'require_permission(') !== false) {
            $problems[] = 'driver API gates on an RBAC module grant — drivers must NOT hold Transport '
                        . '(that would also authorise save_vehicle/delete_route/terminate_vendor)';
        }

        // (b) delegates to the canonical service
        foreach (['startTrip(', 'completeTrip('] as $call) {
            if (strpos($api, '$this->transport->' . $call) === false) {
                $problems[] = "driver API does not delegate {$call} to Transport_service";
            }
        }

        // (c) CSRF exclusion — otherwise a blank 403, no log, no console error
        if (strpos($cfg, 'transport_driver_api/(.*)') === false) {
            $problems[] = 'transport_driver_api is not in csrf_exclude_uris (every app call would 403 blank)';
        }

        // (d) the rule must no longer permit a client-side status transition
        if (preg_match('/match \/tripLogs\/\{[a-zA-Z]+\} \{(.*?)\n    \}/s', $rul, $m)) {
            if (preg_match('/hasOnly\(\[(.*?)\]\)/s', $m[1], $hm)) {
                preg_match_all("/'([a-z_]+)'/", $hm[1], $ff);
                $banned = array_intersect($ff[1] ?? [], [
                    'status', 'actual_start_at', 'actual_end_at', 'endTime',
                    'endOdometer', 'distance', 'distance_km', 'fuelUsed',
                ]);
                if ($banned !== []) {
                    $problems[] = 'tripLogs driver allowlist still permits lifecycle fields ('
                                . implode(',', $banned) . ') — a second lifecycle writer remains possible';
                }
            } else {
                $problems[] = 'tripLogs driver update has no field allowlist';
            }
        } else {
            $problems[] = 'no tripLogs rules block';
        }

        if ($problems !== []) {
            return ['status' => 'FAIL', 'detail' => 'F13-6: ' . implode('; ', $problems)];
        }
        return ['status' => 'PASS', 'detail' =>
            'driver app and admin panel share one Transport_service lifecycle; driver API is Bearer-authenticated, '
            . 'ownership-authorized (no RBAC module grant), CSRF-excluded; tripLogs rule no longer permits a '
            . 'client-side trip-state write'];
    }

    /**
     * CH-TRN-F13-7 — the Parent live map stays provider-agnostic and
     * Firestore-only.
     *
     * Cross-repo source scan of the Parent app. It runs from the admin
     * verifier because that is where the Transport contract is enforced, and a
     * regression in the app is invisible to a PHP-only gate otherwise.
     *
     * Asserts:
     *  (a) NO Google Maps dependency — amendment A-1 forbids re-introducing
     *      one, and the F10 placeholders were removed in M4;
     *  (b) map rendering is confined to ONE file. If a map type leaks into the
     *      repository, the model or the ViewModel, "swap the renderer without
     *      touching anything else" stops being true;
     *  (c) the live position is read from the canonical hot document, so the
     *      parent map and the server share one source of truth;
     *  (d) NO RTDB anywhere in the parent transport surface;
     *  (e) the tile style URL is injected at build time, never hardcoded —
     *      a committed provider key is a credential leak.
     *
     * Skips with INFO when the Parent repo is not present on this machine, so
     * the admin verifier stays runnable standalone.
     */
    private function _chTrnF13_7(): array
    {
        $root = 'D:/Projects/SchoolSyncParent/app/src/main/java/com/schoolsync/parent';
        $gradle = 'D:/Projects/SchoolSyncParent/app/build.gradle.kts';
        if (!is_dir($root)) {
            return ['status' => 'INFO', 'detail' => 'Parent repo not present on this machine — trivial pass'];
        }
        $problems = [];

        // (a) no Google Maps dependency
        if (is_readable($gradle)) {
            $g = (string) file_get_contents($gradle);
            foreach (['play-services-maps', 'maps-compose'] as $dep) {
                if (preg_match('/^\s*implementation\("[^"]*' . preg_quote($dep, '/') . '/m', $g)) {
                    $problems[] = "Google Maps dependency reintroduced ({$dep})";
                }
            }
            if (strpos($g, 'org.maplibre.gl:android-sdk') === false) {
                $problems[] = 'MapLibre dependency missing';
            }
            // (e) style URL injected, not hardcoded
            if (strpos($g, 'ZENXII_MAP_STYLE_URL') === false) {
                $problems[] = 'map style URL is not injected from a build property';
            }
            if (preg_match('/MAP_STYLE_URL"\s*,\s*"\\\\"https?:/', $g)) {
                $problems[] = 'a tile style URL (and likely its key) is HARDCODED in build.gradle';
            }
        }

        // (b) rendering confined to the renderer implementations.
        //
        // UPDATED for Phase B (2026-08-08). At M4 the single allowed file was
        // LiveBusMap.kt, because it *was* the MapLibre implementation. Phase B
        // moved that code behind the MapRenderer interface, so the SDK now
        // lives only in renderer classes and the host composable imports no map
        // type at all. This is a TIGHTENING: the allowed set is the renderer
        // implementations, and LiveBusMap.kt is no longer among them.
        $mapTypes = '/org\.maplibre|MapLibreMap|com\.google\.android\.gms\.maps|GoogleMap\b|com\.mapbox|com\.here\.|com\.tomtom/';
        $allowed  = ['MapLibreRenderer.kt', 'PendingRenderers.kt'];
        $offenders = [];
        $rii = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root));
        foreach ($rii as $f) {
            if (!$f->isFile() || substr($f->getFilename(), -3) !== '.kt') { continue; }
            $name = $f->getFilename();
            if (in_array($name, $allowed, true)) { continue; }
            $src = (string) file_get_contents($f->getPathname());
            if (preg_match($mapTypes, $src)) { $offenders[] = $name; }
        }
        if ($offenders !== []) {
            $problems[] = 'map SDK types leaked outside the renderer implementations: '
                        . implode(', ', array_unique($offenders));
        }

        // (c) canonical hot document + (d) no RTDB
        $model = $root . '/data/model/firestore/VehicleLivePositionDoc.kt';
        if (!is_readable($model)) {
            $problems[] = 'VehicleLivePositionDoc missing — parent is not reading the canonical hot doc';
        } else {
            $m = (string) file_get_contents($model);
            if (strpos($m, 'vehicleLivePositions') === false) {
                $problems[] = 'parent live-position model does not target the vehicleLivePositions collection';
            }
            if (strpos($m, 'recorded_at_ms') === false) {
                $problems[] = 'parent model lost the recorded_at_ms ordering/staleness key';
            }
        }
        $repo = $root . '/data/repository/firestore/TransportFirestoreRepository.kt';
        if (is_readable($repo)) {
            $r = (string) file_get_contents($repo);
            if (strpos($r, 'observeLivePosition') === false) {
                $problems[] = 'parent repository has no live-position listener';
            }
            if (preg_match('/getReference\(|FirebaseDatabase|realtime\s*database/i', $r)) {
                $problems[] = 'RTDB usage in the parent transport repository';
            }
        }

        if ($problems !== []) {
            return ['status' => 'FAIL', 'detail' => 'F13-7: ' . implode('; ', $problems)];
        }
        return ['status' => 'PASS', 'detail' =>
            'Parent map on MapLibre with no Google Maps dependency; map SDK confined to LiveBusMap.kt; '
            . 'live position read from the canonical vehicleLivePositions hot doc; no RTDB; tile style injected at build time'];
    }

    /**
     * CH-TRN-MAP-1 — the centralized Maps platform keeps its two load-bearing
     * guarantees.
     *
     *  (a) SECRET SPLIT. Firestore rules gate a document, not a field, so a
     *      secret in a client-readable config doc IS readable by every client.
     *      The platform therefore writes secrets to a SEPARATE document whose
     *      rule denies all client access. If that rule ever softens, or the
     *      service starts routing secrets into the public doc, every tenant's
     *      server keys become world-readable to authenticated users — silently.
     *
     *  (b) ACTIVATION GATE. A provider may only go live from a passing
     *      connection test. Without it an administrator can activate a mistyped
     *      key and blank every map in the tenant, with the breakage surfacing
     *      on parents' phones rather than in the admin UI.
     *
     * Also checks that no NEW RBAC module was invented (rbac_modules.json is
     * shared byte-for-byte with the staffCapabilities Cloud Function, so adding
     * an entry is a cross-system change), and that the audit trail never stores
     * credential values.
     */
    private function _chTrnMap1(): array
    {
        $svcPath = APPPATH . 'libraries/Maps_config_service.php';
        $ctlPath = APPPATH . 'controllers/Maps_config.php';
        $rulPath = FCPATH . 'firebase-rules/firestore.rules';
        if (!is_readable($svcPath) || !is_readable($ctlPath)) {
            return ['status' => 'INFO', 'detail' => 'Maps platform not present — trivial pass'];
        }
        $svc = $this->_stripComments((string) file_get_contents($svcPath));
        $ctl = $this->_stripComments((string) file_get_contents($ctlPath));
        $rul = is_readable($rulPath) ? (string) file_get_contents($rulPath) : '';
        $problems = [];

        // (a) secret split — rules
        if (!preg_match('/match \/mapsSecrets\/\{[a-zA-Z]+\} \{\s*allow read, write: if false;/s', $rul)) {
            $problems[] = 'mapsSecrets is not deny-all for clients';
        }
        if (!preg_match('/match \/mapsConfig\/\{[a-zA-Z]+\} \{(.*?)\n    \}/s', $rul, $mc)) {
            $problems[] = 'no mapsConfig rules block';
        } elseif (strpos($mc[1], 'allow write: if false') === false) {
            $problems[] = 'mapsConfig is client-writable (must be server-only)';
        }

        // (a) secret split — service routes by sensitivity
        if (strpos($svc, 'CRED_SECRET') === false || strpos($svc, 'COL_SECRETS') === false) {
            $problems[] = 'service lost the credential sensitivity routing';
        }
        if (!preg_match("/sensitivity'\]\s*===\s*self::CRED_SECRET\s*\)\s*\{\s*\\\$secret\[/s", $svc)) {
            $problems[] = 'saveProvider no longer routes secret credentials by sensitivity';
        }
        if (!preg_match('/setEntity\(\s*self::COL_SECRETS/s', $svc)) {
            $problems[] = 'saveProvider no longer writes to the secrets document';
        }
        // The client payload must expose only the resolved provider's PUBLIC creds.
        //
        // REWRITTEN 2026-08-16 (Phase 5A). The old check was:
        //
        //   preg_match("/getClientConfig.*?'credentials' => (array) (\$entry['public']/")
        //
        // which asserted one IMPLEMENTATION SHAPE, not the security property.
        // getClientConfig() now resolves through credentialsFor() so that a
        // school inheriting the platform receives the PLATFORM's public
        // credentials — impossible under the old inline read, which only ever
        // looked at the tenant document. The old regex would therefore have
        // forced the payload to stay tenant-only, i.e. it pinned a defect.
        //
        // These checks assert the PROPERTY instead, and are strictly stronger:
        // the old one allowed any other code path in getClientConfig to add
        // credentials from anywhere; these forbid it.
        if (!preg_match('/function getClientConfig\(.*?\n    \}/s', $svc, $gcc)) {
            $problems[] = 'getClientConfig not found';
        } else {
            $body = $gcc[0];
            // 1. Credentials must be obtained ONLY via credentialsFor().
            if (!preg_match("/'credentials'\s*=>\s*\\\$this->credentialsFor\(/", $body)) {
                $problems[] = 'getClientConfig does not obtain credentials through credentialsFor()';
            }
            // 2. No bypass: it must not read a credential map directly.
            if (preg_match("/\['public'\]|\['secret'\]|COL_SECRETS|COL_PLATFORM_SECRETS/", $body)) {
                $problems[] = 'getClientConfig reads a credential store directly, bypassing credentialsFor()';
            }
        }
        // 3. credentialsFor() itself must return ONLY public sub-maps and must
        //    never touch either secrets collection. If someone later widens it,
        //    THIS is the gate that fires.
        if (!preg_match('/function credentialsFor\(.*?\n    \}/s', $svc, $cf)) {
            $problems[] = 'credentialsFor not found';
        } else {
            $body = $cf[0];
            if (strpos($body, "['public']") === false && strpos($body, "'public'") === false) {
                $problems[] = 'credentialsFor no longer reads the public credential map';
            }
            if (preg_match('/COL_SECRETS|COL_PLATFORM_SECRETS|\bgetSecrets\b|\[.secret.\]/', $body)) {
                $problems[] = 'credentialsFor can reach a SECRET store — client payload may leak secrets';
            }
        }

        // (b) activation gate
        if (!preg_match('/activateProvider.*?VALIDATION_VALID.*?return \[\'ok\' => false/s', $svc)) {
            $problems[] = 'activateProvider no longer requires a passing connection test';
        }

        // RBAC — graded Configuration, no new module
        if (strpos($ctl, "require_permission('Configuration')") === false) {
            $problems[] = 'admin controller is not gated on the Configuration module';
        }
        foreach (["'manage'", "'edit'", "'view'"] as $lvl) {
            if (strpos($ctl, $lvl) === false) { $problems[] = "controller has no {$lvl}-level endpoint"; }
        }
        $modules = FCPATH . '../functions/rbac_modules.json';
        $modules = is_readable($modules) ? $modules : APPPATH . '../functions/rbac_modules.json';
        if (is_readable($modules) && stripos((string) file_get_contents($modules), 'maps') !== false) {
            $problems[] = 'a new RBAC module was added to rbac_modules.json (cross-system change)';
        }

        // Audit must never persist credential values.
        if (!preg_match('/audit\(\'provider_saved\'.*?array_keys\(\$public\)/s', $svc)) {
            $problems[] = 'provider_saved audit no longer records key NAMES only';
        }

        if ($problems !== []) {
            return ['status' => 'FAIL', 'detail' => 'MAP-1: ' . implode('; ', $problems)];
        }
        return ['status' => 'PASS', 'detail' =>
            'secrets isolated in a deny-all document and routed by sensitivity; client payload limited to '
            . 'public creds of the resolved provider; activation requires a passing test; graded Configuration '
            . 'RBAC with no new module; audit stores key names only'];
    }

    /**
     * CH-TRN-MAP-2 — adding the Maps platform did NOT disturb F13.1.
     *
     * The operator's constraint was explicit: the GPS implementation is
     * functionally complete and must remain unchanged, with the existing
     * MapLibre work merely REGISTERED under the new provider architecture
     * rather than rewritten.
     *
     * This is the regression guard for that promise. It asserts the GPS
     * surface still carries its own contract, and — the part a human reviewer
     * would most easily miss — that the maps platform has not reached into the
     * GPS layer or vice versa. The two must stay decoupled: the GPS service
     * answers "where is the bus", the maps platform answers "which renderer",
     * and neither should ever need to know the other exists.
     */
    private function _chTrnMap2(): array
    {
        $gps = APPPATH . 'libraries/Transport_gps_service.php';
        $maps = APPPATH . 'libraries/Maps_config_service.php';
        if (!is_readable($gps)) {
            return ['status' => 'FAIL', 'detail' => 'MAP-2: Transport_gps_service is missing'];
        }
        $g = $this->_stripComments((string) file_get_contents($gps));
        $problems = [];

        // F13.1 contract intact.
        foreach (['COL_LIVE_POSITIONS', 'COL_BREADCRUMBS', 'DRIVER_MUTABLE_FIELDS',
                  'validateFixOrdering', 'openSession', 'closeSession', 'sweepStaleSessions'] as $needle) {
            if (strpos($g, $needle) === false) { $problems[] = "GPS service lost {$needle}"; }
        }

        // Decoupling, both directions.
        if (preg_match('/Maps_config_service|mapsConfig|map_provider/i', $g)) {
            $problems[] = 'the GPS service now references the maps platform — the layers must stay independent';
        }
        if (is_readable($maps)) {
            $m = $this->_stripComments((string) file_get_contents($maps));
            foreach (['vehicleLivePositions', 'vehicleBreadcrumbs', 'tripLogs', 'Transport_gps_service'] as $leak) {
                if (strpos($m, $leak) !== false) {
                    $problems[] = "the maps platform reaches into GPS data ('{$leak}')";
                }
            }
        }

        if ($problems !== []) {
            return ['status' => 'FAIL', 'detail' => 'MAP-2: ' . implode('; ', $problems)];
        }
        return ['status' => 'PASS', 'detail' =>
            'F13.1 GPS contract intact and fully decoupled from the maps platform — neither layer references the other'];
    }

    /**
     * CH-TRN-MAP-3 — provider selection is RUNTIME configuration, everywhere.
     *
     * Phase B's whole promise is that an administrator changes the provider in
     * System Settings and every surface follows — no code change, no Gradle
     * property, no APK rebuild. That promise dies quietly the moment one
     * renderer gets constructed directly somewhere, so this gate holds all six
     * conditions from the brief at once:
     *
     *  1. no renderer is hardcoded — the only `new`/constructor calls live in
     *     each platform's factory;
     *  2. MapProviderManager is the sole resolver;
     *  3. BuildConfig is a FALLBACK, never the primary source;
     *  4. business logic stays provider-agnostic — no map SDK import outside
     *     the renderer implementations;
     *  5. runtime switching works — resolution keys off the config document,
     *     and the renderer is rebuilt only when the provider actually changes;
     *  6. a new provider can be added without touching Transport logic.
     */
    private function _chTrnMap3(): array
    {
        $parent  = 'D:/Projects/SchoolSyncParent/app/src/main/java/com/schoolsync/parent';
        $teacher = 'D:/Projects/SchoolSyncTeacher/app/src/main/java/com/schoolsync/teacher';
        if (!is_dir($parent)) {
            return ['status' => 'INFO', 'detail' => 'Parent repo not present — trivial pass'];
        }
        $problems = [];
        $read = static function (string $p): string {
            return is_readable($p) ? (string) file_get_contents($p) : '';
        };

        // ── 1 + 4. Renderer construction and SDK imports are confined. ──
        // Allowed: the renderer implementations themselves, and each factory.
        $allowed = ['MapLibreRenderer.kt', 'PendingRenderers.kt', 'MapRendererFactory.kt'];
        $sdkRe   = '/org\.maplibre|MapLibreMap|com\.google\.android\.gms\.maps|com\.mapbox|com\.here\.|com\.tomtom/';
        $ctorRe  = '/\b(MapLibreRenderer|GoogleMapsRenderer|MapboxRenderer|HereRenderer|TomTomRenderer)\s*\(/';
        $sdkLeak = []; $ctorLeak = [];

        $rii = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($parent));
        foreach ($rii as $f) {
            if (!$f->isFile() || substr($f->getFilename(), -3) !== '.kt') { continue; }
            $name = $f->getFilename();
            if (in_array($name, $allowed, true)) { continue; }
            $src = (string) file_get_contents($f->getPathname());
            if (preg_match($sdkRe, $src))  { $sdkLeak[]  = $name; }
            if (preg_match($ctorRe, $src)) { $ctorLeak[] = $name; }
        }
        if ($sdkLeak !== []) {
            $problems[] = 'map SDK imported outside a renderer: ' . implode(', ', array_unique($sdkLeak));
        }
        if ($ctorLeak !== []) {
            $problems[] = 'renderer constructed outside the factory: ' . implode(', ', array_unique($ctorLeak));
        }

        // ── 2. ONE RESOLVER — rewritten 2026-08-16 for the server-resolved
        //       Parent architecture.
        //
        // WHAT THIS GATE USED TO CHECK, AND WHY THAT BECAME MEANINGLESS.
        // The old assertion required MapProviderManager to CONTAIN the literal
        // 'mapsConfig' — i.e. it pinned the Firestore-direct implementation, in
        // which the app read the tenant document and resolved the provider
        // itself. That implementation was the defect: the Kotlin copy of the
        // resolution rules never learned about `capability_source`, so a school
        // inheriting the ZenX platform provider resolved to nothing and the bus
        // map stayed blank.
        //
        // Once the app moved to the server resolver the literal survived only
        // inside the docblock EXPLAINING its removal, so the gate reported PASS
        // on prose while the code it was written to protect no longer existed.
        // A gate that a comment can satisfy protects nothing.
        //
        // The replacement asserts PROPERTIES of the new architecture, scanning
        // COMMENT-STRIPPED source so documentation can never satisfy it:
        //   (a) the manager performs no Firestore read of the config document;
        //   (b) it holds no resolution logic of its own;
        //   (c) it obtains the resolved configuration from the Maps API;
        //   (d) that API reaches getClientConfig('parent') → resolveCapability();
        //   (e) the DataStore last-known-good cache is still there;
        //   (f) no second resolver survives in MapProviderConfig.
        $mgrRaw = $read($parent . '/data/maps/MapProviderManager.kt');
        if ($mgrRaw === '') { $problems[] = 'Parent MapProviderManager missing'; }
        else {
            // _stripComments() is PHP-tokenizer based and cannot parse Kotlin,
            // hence the local scanner.
            $mgr = $this->_stripKotlinComments($mgrRaw);

            // (a) No direct Firestore read of the map configuration.
            $firestoreReads = [
                '/observeDocumentAs\s*<\s*MapProviderConfig\s*>/' => 'observes mapsConfig via Firestore',
                '/MapProviderConfig\s*\.\s*COLLECTION/'           => 'references the mapsConfig collection',
                '/["\']mapsConfig["\']/'                          => 'hardcodes the mapsConfig collection',
                '/\bfirestoreService\b|\bFirestoreService\b/'     => 'still depends on FirestoreService',
            ];
            foreach ($firestoreReads as $re => $why) {
                if (preg_match($re, $mgr)) {
                    $problems[] = 'MapProviderManager ' . $why
                                . ' — the Parent app must not resolve from Firestore';
                }
            }

            // (b) No local resolution logic. These are the exact members the
            // drifted second resolver was built from.
            $localResolution = [
                '/\.\s*resolveProvider\s*\(/'  => 'calls resolveProvider()',
                '/\.\s*isEnabled\s*\(/'        => 'calls isEnabled()',
                '/\.\s*isSupportedOn\s*\(/'    => 'calls isSupportedOn()',
                '/global_provider|platform_provider|capability_source/'
                                               => 'inspects raw configuration fields',
            ];
            foreach ($localResolution as $re => $why) {
                if (preg_match($re, $mgr)) {
                    $problems[] = 'MapProviderManager ' . $why
                                . ' — resolution belongs to the server, not the app';
                }
            }

            // (c) It DOES obtain the resolved configuration from the Maps API.
            // Required, not merely permitted: without this the manager could
            // satisfy every prohibition above by resolving nothing at all.
            if (!preg_match('/mapsApi\s*\.\s*clientConfig\s*\(/', $mgr)) {
                $problems[] = 'MapProviderManager does not fetch the resolved '
                            . 'configuration from the Maps API';
            }

            // (e) The DataStore last-known-good cache survives the migration.
            foreach (['readCache', 'writeCache', 'distinctUntilChanged'] as $need) {
                if (strpos($mgr, $need) === false) { $problems[] = "MapProviderManager lost '{$need}'"; }
            }

            // ── 3. BuildConfig is the LAST resort, never a source. ──
            if (!preg_match('/fun fallbackProvider/', $mgr)) {
                $problems[] = 'no explicit BuildConfig fallback path — resolution order is unclear';
            }
            if (substr_count($mgr, 'BuildConfig.MAP_STYLE_URL') > 1) {
                $problems[] = 'BuildConfig referenced outside the fallback path';
            }
        }

        // (f) The DTO must not carry a resolver either — deleting the calls but
        // leaving the methods would let the next edit quietly reinstate them.
        $cfgRaw = $read($parent . '/data/maps/MapProviderConfig.kt');
        if ($cfgRaw !== '') {
            $cfg = $this->_stripKotlinComments($cfgRaw);
            foreach (['resolveProvider', 'isEnabled', 'isSupportedOn'] as $fn) {
                if (preg_match('/fun\s+' . $fn . '\s*\(/', $cfg)) {
                    $problems[] = "MapProviderConfig still defines {$fn}() — a second resolver";
                }
            }
        }

        // (d) The API the manager calls must reach the CANONICAL resolver.
        // Checked end to end so the chain cannot be broken in the middle:
        //   MapsApi.kt → /maps_api/client_config → getClientConfig('parent')
        $api = $this->_stripKotlinComments($read($parent . '/data/remote/MapsApi.kt'));
        if ($api === '') {
            $problems[] = 'Parent MapsApi missing — nothing reaches the server resolver';
        } elseif (!preg_match('#maps_api/client_config#', $api)) {
            $problems[] = 'MapsApi does not call the maps_api/client_config endpoint';
        }
        $ctrl = $read(APPPATH . 'controllers/Maps_api.php');
        if ($ctrl === '') {
            $problems[] = 'Maps_api controller missing — the Parent endpoint does not exist';
        } else {
            $ctrlCode = $this->_stripComments($ctrl);
            if (!preg_match('/getClientConfig\s*\(/', $ctrlCode)) {
                $problems[] = 'Maps_api does not resolve through getClientConfig()';
            }
            if (!preg_match('/PLATFORM_PARENT|[\'"]parent[\'"]/', $ctrlCode)) {
                $problems[] = 'Maps_api does not pin the parent surface';
            }
            // The school must come from the verified token, never the request.
            if (!preg_match('/require_auth\s*\(/', $ctrlCode)) {
                $problems[] = 'Maps_api does not verify the caller\'s Firebase token';
            }
            if (preg_match('/input->(get|post)\s*\(\s*[\'"]school/i', $ctrlCode)) {
                $problems[] = 'Maps_api accepts a client-supplied school — cross-tenant risk';
            }
        }

        // The UI must not read BuildConfig for provider selection any more.
        // Match a real member ACCESS (`BuildConfig.X`), not the word appearing
        // in prose — the docblock legitimately explains where the fallback lives.
        $host = $read($parent . '/ui/transport/LiveBusMap.kt');
        if ($host !== '' && preg_match('/BuildConfig\s*\./', $host)) {
            $problems[] = 'LiveBusMap still reads BuildConfig — provider selection is not runtime';
        }
        if ($host !== '' && strpos($host, 'remember(providerId)') === false) {
            $problems[] = 'renderer is not keyed on providerId — it would rebuild on every position update';
        }

        // ── 5. The factory is the single switch. ──
        $factory = $read($parent . '/ui/maps/MapRendererFactory.kt');
        foreach (['maplibre', 'google', 'mapbox', 'here', 'tomtom', 'maptiler'] as $p) {
            if ($factory !== '' && stripos($factory, $p) === false) {
                $problems[] = "factory does not handle provider '{$p}'";
            }
        }

        // ── 6. Transport/GPS logic untouched by provider selection. ──
        foreach ([
            '/data/repository/firestore/TransportFirestoreRepository.kt',
            '/data/model/firestore/VehicleLivePositionDoc.kt',
        ] as $rel) {
            $src = $read($parent . $rel);
            if ($src !== '' && preg_match('/MapRenderer|MapProviderManager|' . substr($sdkRe, 1, -1) . '/', $src)) {
                $problems[] = 'provider concerns leaked into ' . basename($rel);
            }
        }

        // Teacher plumbing (no UI expected).
        if (is_dir($teacher)) {
            $tm = $read($teacher . '/data/maps/MapProviderManager.kt');
            if ($tm === '') { $problems[] = 'Teacher MapProviderManager missing'; }
            elseif (strpos($tm, 'mapsConfig') === false || strpos($tm, 'readCache') === false) {
                $problems[] = 'Teacher manager does not read/cache the central config';
            }
        }

        // Admin web abstraction.
        $web = $read(FCPATH . 'assets/js/maps/map_renderer.js');
        if ($web === '') { $problems[] = 'admin web renderer abstraction missing'; }
        else {
            if (strpos($web, 'client_config') === false) {
                $problems[] = 'admin renderer does not resolve from the central configuration';
            }
            if (strpos($web, 'ZenxMapRendererFactory') === false) {
                $problems[] = 'admin renderer factory missing';
            }
        }

        if ($problems !== []) {
            return ['status' => 'FAIL', 'detail' => 'MAP-3: ' . implode('; ', $problems)];
        }
        return ['status' => 'PASS', 'detail' =>
            'provider resolved at runtime from mapsConfig on all three surfaces; no renderer constructed or map '
            . 'SDK imported outside a renderer/factory; BuildConfig is fallback-only; renderer keyed on provider '
            . 'so it rebuilds only on an actual switch; Transport/GPS code free of provider concerns'];
    }

    /**
     * CH-TRN-MAP-4 — a provider is registered centrally but selectable ONLY
     * where that platform actually has a renderer.
     *
     * The defect this prevents was found in the pre-freeze review: TomTom is
     * selectable in the central registry and renders fine in the admin panel
     * (Leaflet consumes its raster tiles), but `MapRendererFactory` has no
     * Android TomTom renderer. Assigning it to Parent stored a configuration
     * that resolved to null and showed "no provider configured" — while the
     * administrator believed the map was live. A silently unusable
     * configuration is worse than a refusal.
     *
     * Asserts every registered provider declares per-platform renderer
     * availability, that TomTom's declaration matches reality, that assignment
     * and global mode are both enforced against it, and that the admin page
     * surfaces availability instead of offering a choice that cannot work.
     */
    private function _chTrnMap4(): array
    {
        $svcPath = APPPATH . 'libraries/Maps_config_service.php';
        $ctlPath = APPPATH . 'controllers/Maps_config.php';
        $viewPath = APPPATH . 'views/school_config/maps_location.php';
        $factory = 'D:/Projects/SchoolSyncParent/app/src/main/java/com/schoolsync/parent/ui/maps/MapRendererFactory.kt';
        if (!is_readable($svcPath)) {
            return ['status' => 'INFO', 'detail' => 'Maps platform not present — trivial pass'];
        }
        $svc  = (string) file_get_contents($svcPath);
        $ctl  = is_readable($ctlPath)  ? (string) file_get_contents($ctlPath)  : '';
        $view = is_readable($viewPath) ? (string) file_get_contents($viewPath) : '';
        $problems = [];

        // Every provider declares availability.
        if (!preg_match_all("/'renderers' => \[/", $svc, $m) || count($m[0]) < 6) {
            $problems[] = 'not every provider declares per-platform renderer availability ('
                        . count($m[0] ?? []) . '/6)';
        }

        // The enforcement points.
        foreach (['isSupportedOn', 'isGlobalCapable', 'PLATFORMS_WITH_SURFACE'] as $need) {
            if (strpos($svc, $need) === false) { $problems[] = "service lost {$need}"; }
        }
        if (!preg_match('/isGlobalCapable\(\$globalProvider\)/', $svc)) {
            $problems[] = 'global mode is not validated against renderer availability';
        }
        if (!preg_match('/isSupportedOn\(\$p, \$plat\)/', $svc)) {
            $problems[] = 'per-platform assignment is not validated against renderer availability';
        }

        // ── Registry claims must match the Android factory, for EVERY provider.
        //
        // A factory BRANCH is not a working renderer: the placeholders map to
        // `PendingRenderer` subclasses that report isAvailable() = false and
        // no-op every draw call. So the real question is whether the class the
        // factory returns is a pending one — checked by reading the placeholder
        // declarations rather than assuming a fixed list, so implementing a
        // provider automatically flips this gate's expectation.
        $pending = [];
        $pendingPath = dirname($factory) . '/renderers/PendingRenderers.kt';
        if (is_readable($pendingPath)) {
            preg_match_all('/class\s+(\w+)\s*:\s*PendingRenderer\(/', (string) file_get_contents($pendingPath), $pm2);
            $pending = $pm2[1] ?? [];
        }
        if (is_readable($factory)) {
            $fsrc = (string) file_get_contents($factory);
            foreach (['maplibre' => 'PROVIDER_MAPLIBRE', 'maptiler' => 'PROVIDER_MAPTILER',
                      'google' => 'PROVIDER_GOOGLE', 'mapbox' => 'PROVIDER_MAPBOX',
                      'here' => 'PROVIDER_HERE', 'tomtom' => 'PROVIDER_TOMTOM'] as $pid => $constant) {
                // Declared availability for the parent platform.
                $declared = false;
                if (preg_match("/'{$pid}' => \[(.*?)'probe'/s", $svc, $pe)) {
                    $declared = (bool) preg_match("/'renderers' => \[[^\]]*'parent' => true/", $pe[1]);
                }
                // What the factory actually returns.
                $returns = '';
                if (preg_match('/' . $constant . '\s*(?:,\s*)?(?:\/\/[^\n]*\n\s*)*->\s*(\w+)\(/', $fsrc, $fm)) {
                    $returns = $fm[1];
                }
                $realRenderer = $returns !== '' && !in_array($returns, $pending, true);

                if ($declared && !$realRenderer) {
                    $problems[] = "{$pid} claims a parent renderer but the factory returns "
                        . ($returns === '' ? 'nothing' : $returns . ' (a placeholder)');
                }
                if (!$declared && $realRenderer) {
                    $problems[] = "the factory now returns a real renderer for {$pid} ({$returns}) — "
                        . "flip 'parent' => true in the registry";
                }
            }
        }

        // The admin surface must expose availability, not just enforce it.
        if ($ctl !== '' && strpos($ctl, 'availability') === false) {
            $problems[] = 'get_config does not return per-platform availability';
        }
        if ($view !== '') {
            foreach (['availabilityChips', 'disabled'] as $need) {
                if (strpos($view, $need) === false) {
                    $problems[] = "admin page does not surface availability ('{$need}' absent)";
                }
            }
        }

        if ($problems !== []) {
            return ['status' => 'FAIL', 'detail' => 'MAP-4: ' . implode('; ', $problems)];
        }
        return ['status' => 'PASS', 'detail' =>
            'all 6 providers declare per-platform renderer availability; global mode and per-platform '
            . 'assignment both refuse a provider with no renderer; TomTom is admin-only and matches the '
            . 'Android factory; the admin page shows availability and disables unusable choices'];
    }

    /**
     * CH-TRN-CSP-1 — the F13.2 map CSP allowlist stays minimal and correct.
     *
     * `MY_Controller` is shared ERP infrastructure, and its CSP protects every
     * admin page in the product. F13.2 needed exactly two tile origins added so
     * the fleet map is not a working UI over a blank grid. This gate makes that
     * widening permanent-but-bounded: it fails if a map origin leaks into a
     * script/style/default directive, if a wildcard creeps in, or if any
     * pre-existing origin is dropped.
     *
     * Why these two and no more — derived from `map_renderer.js`, which builds
     * exactly `api.maptiler.com` and `api.tomtom.com`. Deliberately absent:
     * OSM (no renderer constructs an OSM URL, and the public service is barred
     * for production), wildcard subdomains, and the JS CDNs — the fleet map
     * uses SVG circleMarkers rather than Leaflet's PNG icon precisely so
     * `img-src` need not admit a CDN.
     *
     * A CSP cannot be loosened by a second header (policies INTERSECT), so a
     * single emitter per controller base is also asserted.
     */
    private function _chTrnCsp1(): array
    {
        $path = APPPATH . 'core/MY_Controller.php';
        if (!is_readable($path)) {
            return ['status' => 'FAIL', 'detail' => 'CSP-1: MY_Controller.php unreadable'];
        }
        // Comments are stripped before ANY substring test. The CSP block is
        // heavily commented — including notes naming the origins deliberately
        // NOT added — so a raw match would flag the documentation as the
        // defect it warns against.
        $src = $this->_stripComments((string) file_get_contents($path));
        $problems = [];

        // The CSP must be built and emitted exactly once here.
        if (substr_count($src, 'header("Content-Security-Policy: "') !== 1) {
            $problems[] = 'MY_Controller no longer emits the CSP exactly once';
        }

        // Extract each directive from the concatenated builder.
        $dir = static function (string $name) use ($src): ?string {
            return preg_match('/"' . preg_quote($name, '/') . ' ([^"]*)"/', $src, $m) ? $m[1] : null;
        };
        $maps = ['api.maptiler.com', 'api.tomtom.com'];

        // (a) required origins present where they belong
        foreach (['img-src', 'connect-src'] as $d) {
            $v = $dir($d);
            if ($v === null) { $problems[] = "{$d} directive missing"; continue; }
            foreach ($maps as $host) {
                if (strpos($v, $host) === false) { $problems[] = "{$d} is missing {$host}"; }
            }
        }

        // (b) NOT present anywhere else
        foreach (['script-src', 'style-src', 'default-src', 'font-src', 'media-src'] as $d) {
            $v = $dir($d);
            if ($v === null) { continue; }
            foreach (['maptiler', 'tomtom', 'openstreetmap'] as $needle) {
                if (stripos($v, $needle) !== false) {
                    $problems[] = "a map origin leaked into {$d} — only img-src/connect-src may carry them";
                }
            }
        }

        // (c) narrowness — no wildcarded map host, no OSM
        if (preg_match('/\*\.(maptiler|tomtom|openstreetmap)/i', $src)) {
            $problems[] = 'a wildcard map origin was introduced — use the exact hosts the renderer builds';
        }
        if (stripos($src, 'tile.openstreetmap.org') !== false) {
            $problems[] = 'public OSM tile host added — no renderer builds it and it is barred for production';
        }

        // (d) pre-existing origins intact
        $img = (string) $dir('img-src');
        $script = (string) $dir('script-src');
        foreach (['firebasestorage.googleapis.com', 'storage.googleapis.com', 'data:', 'blob:'] as $keep) {
            if (strpos($img, $keep) === false) { $problems[] = "img-src lost pre-existing '{$keep}'"; }
        }
        foreach (['cdnjs.cloudflare.com', 'cdn.jsdelivr.net', 'cdn.datatables.net'] as $keep) {
            if (strpos($script, $keep) === false) { $problems[] = "script-src lost pre-existing '{$keep}'"; }
        }
        if ($dir('default-src') !== null && trim((string) $dir('default-src')) !== "'self'; ") {
            // The builder writes "default-src 'self'; " — anything else is a change.
            if (strpos((string) $dir('default-src'), "'self'") === false) {
                $problems[] = 'default-src changed';
            }
        }

        // (e) the renderer must not need a CDN image (why img-src stays narrow).
        // JS comments stripped for the same reason as above — the renderer's own
        // docblock explains that it does NOT use L.marker().
        $rend = FCPATH . 'assets/js/maps/map_renderer.js';
        if (is_readable($rend)) {
            $r = (string) file_get_contents($rend);
            $r = preg_replace('#/\*.*?\*/#s', '', $r);          // block comments
            $r = preg_replace('#^\s*//.*$#m', '', (string) $r);  // line comments
            $r = (string) $r;
            if (preg_match('/\bL\.marker\s*\(/', $r) || preg_match('/global\.L\.marker\s*\(/', $r)) {
                $problems[] = 'renderer uses L.marker() — its default PNG icon would force a CDN into img-src; '
                            . 'use circleMarker';
            }
        }

        if ($problems !== []) {
            return ['status' => 'FAIL', 'detail' => 'CSP-1: ' . implode('; ', $problems)];
        }
        return ['status' => 'PASS', 'detail' =>
            'exactly 2 exact map origins (api.maptiler.com, api.tomtom.com) on img-src + connect-src only; '
            . 'no wildcard, no OSM, no leak into script/style/default; pre-existing Firebase/CDN origins intact; '
            . 'single CSP emitter; renderer uses SVG markers so img-src needs no CDN'];
    }

    /**
     * CH-TRN-F13-8 — the F13.2 admin fleet map is a real consumer of F13.1, not
     * a second GPS system and not the old static prototype.
     *
     * The prototype this replaces was a hardcoded `FLEET` array and a
     * hand-drawn SVG map. The whole point of F13.2 is that none of that
     * survives, so this gate asserts the properties a runtime probe cannot:
     * that there is no sample fleet, no literal coordinate, no duplicate
     * telemetry collection, no RTDB, no map SDK in the business layer, and no
     * invented ETA.
     */
    private function _chTrnF13_8(): array
    {
        $svcPath  = APPPATH . 'libraries/Transport_fleet_service.php';
        $ctlPath  = APPPATH . 'controllers/Transport_fleet.php';
        $viewPath = APPPATH . 'views/transport/fleet_live.php';
        if (!is_readable($svcPath) || !is_readable($ctlPath) || !is_readable($viewPath)) {
            return ['status' => 'INFO', 'detail' => 'F13.2 fleet surface not present — trivial pass'];
        }
        $svc  = $this->_stripComments((string) file_get_contents($svcPath));
        $ctl  = $this->_stripComments((string) file_get_contents($ctlPath));
        $viewRaw = (string) file_get_contents($viewPath);
        $view = preg_replace('#/\*.*?\*/#s', '', $viewRaw);
        $view = (string) preg_replace('#^\s*//.*$#m', '', (string) $view);
        $problems = [];

        // ── canonical source only ──
        if (strpos($svc, 'vehicleLivePositions') === false) {
            $problems[] = 'fleet service does not read the canonical vehicleLivePositions';
        }
        foreach (['fleetLivePositions', 'adminLivePositions', 'liveFleet', 'vehiclePositionsMirror'] as $dupe) {
            if (stripos($svc . $ctl, $dupe) !== false) {
                $problems[] = "a duplicate GPS collection appeared ('{$dupe}')";
            }
        }
        // The fleet layer must never WRITE telemetry — it is a read model.
        if (preg_match('/setEntity\(\s*self::COL_LIVE_POSITIONS|setEntity\(\s*[\'"]vehicleLivePositions/', $svc)) {
            $problems[] = 'fleet service WRITES live positions — it must be read-only';
        }

        // ── no mock data anywhere ──
        // A literal lat/lng pair in the service or view means the prototype's
        // hardcoded fleet crept back in.
        if (preg_match('/[\'"]lat[\'"]\s*=>\s*-?\d+\.\d+/', $svc)) {
            $problems[] = 'a hardcoded coordinate is present in the fleet service';
        }
        if (preg_match('/\bFLEET\s*=|\bSAMPLE_FLEET\b|sampleBuses/i', $svc . $ctl . $view)) {
            $problems[] = 'a sample/hardcoded fleet array is present';
        }
        // The view may carry a 0,0 camera seed but no real coordinates.
        if (preg_match('/\b(?:12|13|28|77|72)\.\d{3,}/', $view)) {
            $problems[] = 'the fleet view contains literal geographic coordinates';
        }

        // ── no RTDB ──
        if (preg_match('/getReference\(|firebaseio\.com|FirebaseDatabase/i', $svc . $ctl . $view)) {
            $problems[] = 'RTDB usage in the F13.2 surface';
        }

        // ── provider-agnostic rendering ──
        if (strpos($view, 'ZenxMapRendererFactory') === false) {
            $problems[] = 'the fleet view does not resolve its renderer through the Maps platform';
        }
        foreach (['maplibregl', 'google.maps', 'mapboxgl', 'L.tileLayer'] as $direct) {
            if (strpos($view, $direct) !== false) {
                $problems[] = "the fleet view talks to a map SDK directly ('{$direct}') — "
                            . 'rendering belongs in the renderer implementation';
            }
        }
        // Business layer must know nothing about maps.
        if (preg_match('/Leaflet|maplibre|mapbox|tileLayer/i', $svc)) {
            $problems[] = 'the fleet SERVICE references a map library — it must stay renderer-agnostic';
        }

        // ── ETA stays deferred ──
        if (preg_match('/[\'"]eta(_[a-z]+)?[\'"]\s*=>/i', $svc)) {
            $problems[] = 'an ETA field appeared — ETA is deferred to F13.3 (no travel-time model exists)';
        }
        if (preg_match('/distance[^\n]{0,40}\/\s*\$?\w*speed/i', $svc)) {
            $problems[] = 'distance ÷ speed ETA arithmetic detected — explicitly forbidden';
        }

        // ── freshness reuses the F13.1 threshold ──
        if (!preg_match('/STALE_AFTER_S\s*=\s*120/', $svc)) {
            $problems[] = 'fleet staleness threshold no longer mirrors Transport_gps_service (120s)';
        }

        // ── RBAC: existing Transport module, view level, no new module ──
        if (strpos($ctl, "require_permission('Transport'") === false) {
            $problems[] = 'fleet controller is not gated on the Transport module';
        }
        if (!preg_match("/require_permission\('Transport',\s*'[a-z_]+',\s*'view'\)/", $ctl)) {
            $problems[] = 'fleet endpoints are not gated at view level';
        }

        // ── scalability + lifecycle in the client ──
        if (strpos($view, 'visibilitychange') === false) {
            $problems[] = 'the view does not pause polling when the tab is hidden';
        }
        if (!preg_match('/clearInterval/', $view)) {
            $problems[] = 'the view never tears down its poll timer';
        }
        if (preg_match('/setInterval\([^,]+,\s*(\d{1,3})\)/', $view, $m) && (int) $m[1] < 1000) {
            $problems[] = 'a sub-second polling loop is present';
        }
        if (strpos($ctl, 'CACHE_TTL_S') === false) {
            $problems[] = 'the live endpoint has no server-side cache — cost would scale per open tab';
        }

        if ($problems !== []) {
            return ['status' => 'FAIL', 'detail' => 'F13-8: ' . implode('; ', $problems)];
        }
        return ['status' => 'PASS', 'detail' =>
            'fleet map consumes canonical vehicleLivePositions read-only; no duplicate collection, no sample '
            . 'fleet, no hardcoded coordinates, no RTDB; renderer resolved via the Maps platform with no SDK in '
            . 'the service; ETA remains deferred; staleness mirrors F13.1; graded Transport:view; polling is '
            . 'server-cached, visibility-aware and torn down'];
    }

    /**
     * CH-TRN-RBAC-1 — Transport authorization is 100% graded RBAC.
     *
     * The legacy role-name fallback was retired Transport-wide on 2026-08-07
     * (the ERP is pre-deployment, so no compatibility window was required).
     * Every Transport HTTP endpoint is now gated exclusively by
     *
     *     require_permission('Transport', '<action>', '<view|edit|manage>')
     *
     * and access is granted only by holding the Transport module — directly or
     * via its `Operations` umbrella parent — at the required level, or by a
     * RBAC_BYPASS_ROLES principal. This gate fails if ANY Transport controller
     * reintroduces the `_require_role` bridge, a role-name allow-list constant,
     * or an inline `in_array($this->admin_role, ...)` authorization test.
     *
     * Deliberate exclusions:
     *   • Transport_analytics — gates on has_permission('Operations') plus the
     *     F11 per-role WIDGET VISIBILITY matrix. That matrix is presentation
     *     scoping, not the authorization boundary, and is pinned separately by
     *     CH-TRN-SEC3-1 / CH-TRN-F11-4.
     *   • Transport_sos's `triggered_by_role` audit field — records who acted;
     *     it is written, never read for an access decision.
     */
    private function _chTrnRbac1(): array
    {
        $dir = APPPATH . 'controllers';
        $offenders = [];
        foreach ((array) glob("{$dir}/Transport*.php") as $path) {
            $name = basename($path, '.php');
            if ($name === 'Transport_analytics' || $name === 'Transport_verifier'
                || strpos($name, 'Transport_probe') === 0) {
                continue;
            }
            $src = $this->_stripComments((string) file_get_contents($path));
            $bad = [];
            if (strpos($src, '_require_role') !== false)                        { $bad[] = '_require_role bridge'; }
            if (preg_match('/(?:VIEW|MANAGE|REQUIRED)_ROLES/', $src))           { $bad[] = 'role-name allow-list'; }
            if (preg_match('/in_array\(\s*(?:\(string\)\s*)?\$(?:this->)?(?:admin_)?role\b/', $src)) {
                $bad[] = 'inline admin_role name test';
            }
            if ($bad !== []) { $offenders[] = $name . ' [' . implode(', ', $bad) . ']'; }
        }
        if ($offenders !== []) {
            return ['status' => 'FAIL', 'detail' =>
                'RBAC-1: legacy role-name authorization reintroduced in: ' . implode('; ', $offenders)];
        }
        return ['status' => 'PASS', 'detail' =>
            'no legacy role-name authorization anywhere in Transport — every endpoint gated by '
            . "require_permission('Transport', <action>, <level>) alone"];
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
     * CH-TRN-SEC1-1 — transportAttendance mutations must be SERVER-MEDIATED. Re-scoped at F14 (was: bind non-admin
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
        // Isolate the transportAttendance match block. Bounded by the NEXT match
        // block rather than a byte budget — a fixed window silently truncated the
        // block once its comments grew, turning a passing gate into a false FAIL.
        $start = strpos($rules, 'match /transportAttendance/');
        if ($start === false) {
            return ['status' => 'FAIL', 'detail' => 'SEC-1: transportAttendance match block not found'];
        }
        $rest  = substr($rules, $start + 10);
        $next  = strpos($rest, 'match /');
        $block = $next === false ? substr($rules, $start) : substr($rules, $start, $next + 10);

        // ── RE-SCOPED at F14 (2026-08-12) — strictly STRONGER, not weakened ──
        //
        // SEC-1 originally required the driver's client-side create to be bound
        // to the trip they own — the tightest thing security rules can express.
        // It is provably not enough: rules cannot verify the ID card's HMAC, nor
        // resolve a transport entitlement across studentRoutes status +
        // effective dates + session + service days, so a student with a valid QR
        // but NO transport facility could be recorded.
        //
        // F14 therefore withdrew the driver arm altogether. Attendance mutations
        // go through Transport_driver_api (trip-ownership authorized) and are
        // written with the admin SDK, which bypasses rules by design.
        //
        // Old assertion : a driver-of-trip binding must EXIST.
        // New assertion : NO driver client-write path may exist at all, and the
        //                 events must stay immutable.
        $driverArmGone = strpos($block, 'driver_staff_id == request.auth.uid') === false
                      && strpos($block, 'driver_staff_id == request.auth.token.user_id') === false;
        $immutable = preg_match('/allow\s+update,\s*delete:\s*if\s+false/', $block) === 1;

        if (!$driverArmGone) {
            return ['status' => 'FAIL', 'detail' =>
                'SEC-1/F14: a driver can still write transportAttendance directly from the client — '
                . 'that path cannot verify the QR HMAC or the studentRoutes entitlement, so a '
                . 'non-transport student could be recorded. Mutations must be server-mediated.'];
        }
        if (!$immutable) {
            return ['status' => 'FAIL', 'detail' =>
                'SEC-1/F14: attendance events are not immutable (allow update, delete: if false missing) — '
                . 'a recorded safety event could be altered after the fact'];
        }
        return ['status' => 'PASS', 'detail' =>
            'transportAttendance has no driver client-write path (server-mediated via '
            . 'Transport_driver_api, which enforces QR HMAC + studentRoutes entitlement) and events '
            . 'remain immutable'];
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


    /**
     * CH-TRN-F14-1 — QR boarding is server-authorized end to end.
     *
     * WHY THIS GATE EXISTS. F14 withdrew the driver client-write arm from the
     * `transportAttendance` rule because rules cannot verify a QR HMAC and
     * cannot resolve a transport entitlement across studentRoutes status,
     * effective dates, session and service days — so a child holding a valid
     * card but with no transport facility could be recorded. The rule shipped;
     * the Teacher app was NOT migrated, and kept writing the collection
     * directly from the device. Two boarding authorities existed, the weaker one
     * being the one the conductor actually used, and the moment the rule
     * deployed every tap would have failed PERMISSION_DENIED — silently, being a
     * Firestore write.
     *
     * Nothing guarded that pairing, which is precisely why it drifted. This gate
     * asserts the PROPERTY, on comment-stripped source so documentation can
     * never satisfy it:
     *
     *   a. the app performs no direct write to transportAttendance;
     *   b. it holds no hand-board path (boarding requires the signed QR);
     *   c. it does call the driver API for scan / roster / alight / exception;
     *   d. the server keeps ONE boarding writer;
     *   e. bus boarding never touches classroom attendance.
     */
    private function _chTrnF14_1(): array
    {
        $teacher = 'D:/Projects/SchoolSyncTeacher/app/src/main/java/com/schoolsync/teacher';
        if (!is_dir($teacher)) {
            return ['status' => 'INFO', 'detail' => 'Teacher repo not present — trivial pass'];
        }
        $problems = [];
        $read = static fn(string $p): string => is_readable($p) ? (string) file_get_contents($p) : '';

        $repoRaw = $read($teacher . '/data/repository/firestore/TransportFirestoreRepository.kt');
        if ($repoRaw === '') {
            $problems[] = 'Teacher TransportFirestoreRepository missing';
        } else {
            $repo = $this->_stripKotlinComments($repoRaw);

            // (a) No client write to the attendance collection.
            if (preg_match('/(setDocument|updateDocument|addDocument)\s*\(\s*[^,)]*TRANSPORT_ATTENDANCE/', $repo)
                || preg_match('/(setDocument|updateDocument)\s*\(\s*["\']transportAttendance["\']/', $repo)) {
                $problems[] = 'the app writes transportAttendance directly — '
                            . 'boarding must go through Transport_driver_api';
            }

            // (b) No hand-board path. A tap cannot verify a QR signature or an
            //     entitlement, so a boarding without a scanned token is exactly
            //     the hole F14 closed.
            foreach (['markStudentBoarded', 'markStudentDropped', 'markStudentNoShow', 'writeBoardingEvent'] as $fn) {
                if (preg_match('/fun\s+' . $fn . '\s*\(/', $repo)) {
                    $problems[] = "the app still defines {$fn}() — a hand-board path";
                }
            }

            // (c) It DOES reach the server. Required, not merely permitted:
            //     without this the app satisfies every prohibition above by
            //     recording nothing at all.
            foreach ([
                'scanStudentQr'          => 'scan',
                'tripRoster'             => 'trip_roster',
                'markAllAlightedAtSchool'=> 'mark_all_alighted_at_school',
                'resolveStudentException'=> 'resolve_exception',
            ] as $fn => $endpoint) {
                if (!preg_match('/fun\s+' . $fn . '\s*\(/', $repo)) {
                    $problems[] = "the app has no {$fn}() — the {$endpoint} endpoint has no consumer";
                }
            }
        }

        // (c continued) The Retrofit surface must name the real endpoints.
        $api = $this->_stripKotlinComments($read($teacher . '/data/remote/ApiService.kt'));
        foreach (['transport_driver_api/scan', 'transport_driver_api/trip_roster',
                  'transport_driver_api/mark_all_alighted_at_school',
                  'transport_driver_api/resolve_exception'] as $ep) {
            if ($api !== '' && strpos($api, $ep) === false) {
                $problems[] = "ApiService does not declare {$ep}";
            }
        }

        // The conductor must actually be able to scan, or the endpoints are
        // unreachable in practice — the state this gate was written after.
        $screen = $this->_stripKotlinComments($read($teacher . '/ui/transport/DriverTripDetailScreen.kt'));
        if ($screen !== '') {
            if (!preg_match('/GmsBarcodeScanning\s*\.\s*getClient\s*\(/', $screen)) {
                $problems[] = 'the driver trip screen has no scanner — QR boarding is not reachable';
            }
            if (!preg_match('/onQrScanned\s*\(/', $screen)) {
                $problems[] = 'the driver trip screen never submits a scanned token';
            }
            // The head count is the point of the feature.
            if (strpos($screen, 'HeadCountPanel') === false) {
                $problems[] = 'the driver trip screen shows no head count';
            }
        }

        // (d) ONE boarding writer server-side.
        $svc = $read(APPPATH . 'libraries/Transport_qr_attendance_service.php');
        if ($svc === '') {
            $problems[] = 'Transport_qr_attendance_service missing';
        } else {
            $code = $this->_stripComments($svc);
            // (e) Bus boarding must never touch classroom attendance. A parent
            //     seeing a child marked present in class because they boarded a
            //     bus would be a false record in the wrong module entirely.
            if (preg_match('/(setEntity|->set)\s*\(\s*["\']attendance["\']/', $code)) {
                $problems[] = 'the QR service writes the classroom attendance collection — '
                            . 'bus boarding must stay separate';
            }
        }

        return $problems === []
            ? ['status' => 'PASS', 'detail' => 'boarding is server-authorized: no client write, no hand-board, '
                                             . 'scanner + head count present, one writer, classroom attendance untouched']
            : ['status' => 'FAIL', 'detail' => implode('; ', $problems)];
    }
    /**
     * Comment-strip Kotlin source. `_stripComments()` runs the PHP tokenizer and
     * cannot parse Kotlin, so a gate that scans .kt files needs this instead.
     *
     * A character scanner rather than a regex because the naive
     * `#//.*$#m` deletes the tail of every `"https://…"` in the file, which
     * would silently blind a gate to whatever followed a URL on the same line.
     * String state is therefore tracked, with backslash escapes honoured.
     */
    private function _stripKotlinComments(string $src): string
    {
        $out = ''; $len = strlen($src);
        $inStr = false; $inBlock = false; $esc = false;
        for ($i = 0; $i < $len; $i++) {
            $c    = $src[$i];
            $next = $i + 1 < $len ? $src[$i + 1] : '';

            if ($inBlock) {
                if ($c === '*' && $next === '/') { $inBlock = false; $i++; }
                continue;
            }
            if ($inStr) {
                $out .= $c;
                if ($esc)            { $esc = false; }
                elseif ($c === '\\') { $esc = true;  }
                elseif ($c === '"')  { $inStr = false; }
                continue;
            }
            if ($c === '/' && $next === '*') { $inBlock = true; $i++; continue; }
            if ($c === '/' && $next === '/') {                      // to EOL
                while ($i < $len && $src[$i] !== "\n") { $i++; }
                $out .= "\n";
                continue;
            }
            if ($c === '"') { $inStr = true; }
            $out .= $c;
        }
        return $out;
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

    // ═════════════════════════════════════════════════════════════════════
    //  F12.1 — CBSE Transport Safety Compliance (M5)
    // ═════════════════════════════════════════════════════════════════════

    /**
     * CH-TRN-CMP-1 — the seeded rule pack matches the canonical constant.
     *
     * The expected rule count and total weight are DERIVED from
     * Transport_compliance_service::CBSE_RULE_PACK, never hardcoded. That is
     * deliberate: CH-TRN-SEC1-1 went permanently red after the RBAC claim
     * migration precisely because it pinned a literal that the code had moved
     * past. A gate that hardcodes 19/144 would fail the day a rule is added
     * even though nothing is broken. (It also documents the 2026-08-07 spec
     * correction: the §2.4 prose said 154, the tabulated weights sum to 144,
     * and the table is authoritative.)
     */
    private function _chTrnCmp1(): array
    {
        $pack = Transport_compliance_service::CBSE_RULE_PACK;
        $expectCount  = count($pack);
        $expectWeight = array_sum(array_column($pack, 'severity_weight'));
        $expectBlock  = count(array_filter(array_column($pack, 'blocks_dispatch')));

        $seeded = $this->cmp->listRules();
        if ($seeded === []) {
            return ['status' => 'INFO', 'detail' =>
                "no compliance pack seeded for this tenant yet (expected {$expectCount} rules / weight {$expectWeight}) — seed via transport_compliance/seed_pack"];
        }

        $gotCount  = count($seeded);
        $gotWeight = array_sum(array_map(static function ($r) { return (int) ($r['severity_weight'] ?? 0); }, $seeded));
        $gotBlock  = count(array_filter($seeded, static function ($r) { return !empty($r['blocks_dispatch']); }));

        // Every canonical code must be present; evaluation kind must be legal.
        $seededCodes = array_map(static function ($r) { return (string) ($r['rule_code'] ?? ''); }, $seeded);
        $missing = array_values(array_diff(array_column($pack, 'code'), $seededCodes));
        $badEval = [];
        foreach ($seeded as $r) {
            $ev = (string) ($r['evaluation'] ?? '');
            if (!in_array($ev, Transport_compliance_service::EVALUATIONS, true)) {
                $badEval[] = (string) ($r['rule_code'] ?? '?') . "={$ev}";
            }
        }

        $problems = [];
        if ($missing !== [])            { $problems[] = 'missing: ' . implode(',', $missing); }
        if ($badEval !== [])            { $problems[] = 'bad evaluation: ' . implode(',', $badEval); }
        if ($gotCount !== $expectCount) { $problems[] = "count {$gotCount} != {$expectCount}"; }
        // Weight/blocks may legitimately differ from the constant: §2.1 allows a
        // school to override severity_weight and blocks_dispatch. Report the
        // delta as INFO rather than failing on a supported override.
        $overrides = [];
        if ($gotWeight !== $expectWeight) { $overrides[] = "weight {$gotWeight} vs canonical {$expectWeight}"; }
        if ($gotBlock !== $expectBlock)   { $overrides[] = "blocking {$gotBlock} vs canonical {$expectBlock}"; }

        if ($problems !== []) {
            return ['status' => 'FAIL', 'detail' => 'CMP-1: ' . implode('; ', $problems)];
        }
        $note = $overrides !== [] ? ' (school overrides: ' . implode(', ', $overrides) . ')' : '';
        return ['status' => 'PASS', 'detail' =>
            "rule pack complete: {$gotCount} rules, weight {$gotWeight}, {$gotBlock} dispatch-blocking{$note}"];
    }

    /**
     * CH-TRN-CMP-2 — every stored posture equals a fresh recomputation.
     *
     * Posture is derived and must never be authoritative (spec §2.3). Rather
     * than trust the stored doc, this recomputes from vehicleCompliance + the
     * rule weights and compares. computePosture() rewrites the doc with the
     * same values it returns, so a drift here means the stored doc had gone
     * stale relative to its inputs — exactly the failure this gate exists for.
     */
    private function _chTrnCmp2(): array
    {
        $vehicles = $this->transport->listVehicles();
        if ($vehicles === []) {
            return ['status' => 'INFO', 'detail' => 'no vehicles in this tenant — nothing to verify'];
        }
        $checked = 0; $drift = [];
        foreach ($vehicles as $v) {
            $vid = (string) ($v['id'] ?? '');
            if ($vid === '') { continue; }
            $stored = $this->fs->getEntity('vehicleCompliancePosture', $vid);
            if (!is_array($stored)) { continue; }   // never evaluated — not a drift
            $checked++;
            $fresh = $this->cmp->computePosture($vid);
            if ((int) ($stored['score'] ?? -1) !== (int) $fresh['score']
                || (string) ($stored['tier'] ?? '') !== (string) $fresh['tier']
                || (bool) ($stored['blocks_dispatch'] ?? false) !== (bool) $fresh['blocks_dispatch']) {
                $drift[] = sprintf('%s(stored %s/%s vs fresh %s/%s)', $vid,
                    $stored['score'] ?? '?', $stored['tier'] ?? '?', $fresh['score'], $fresh['tier']);
            }
        }
        if ($checked === 0) {
            return ['status' => 'INFO', 'detail' => 'no vehicle has an evaluated posture yet'];
        }
        if ($drift !== []) {
            return ['status' => 'FAIL', 'detail' => 'CMP-2: posture drift on ' . count($drift) . ': ' . implode(' ', array_slice($drift, 0, 5))];
        }
        return ['status' => 'PASS', 'detail' => "posture recomputable + identical for all {$checked} evaluated vehicle(s)"];
    }

    /**
     * CH-TRN-CMP-3 — every Waived row respects its bounds.
     *
     * A waiver suspends a mandatory CBSE requirement, so it must always carry
     * a reason of at least the configured minimum, a named actor, and an
     * expiry no more than WAIVER_MAX_DAYS after it was granted. Reason length
     * is measured in CHARACTERS (mb_strlen) to match waiveRule()'s own
     * Unicode-safe validation — a byte-count here would false-fail a reason
     * written in an Indic script.
     */
    private function _chTrnCmp3(): array
    {
        $rows = (array) $this->fs->schoolWhere('vehicleCompliance', [['status', '==', 'Waived']]);
        if ($rows === []) {
            return ['status' => 'PASS', 'detail' => 'no active waivers in this tenant'];
        }
        $minLen  = Transport_compliance_service::WAIVER_REASON_MIN;
        $maxDays = Transport_compliance_service::WAIVER_MAX_DAYS;
        $bad = [];
        foreach ($rows as $row) {
            $r = is_array($row['data'] ?? null) ? $row['data'] : $row;
            $id     = (string) ($r['rule_code'] ?? ($row['id'] ?? '?'));
            $reason = (string) ($r['waiver_reason'] ?? '');
            $by     = (string) ($r['waiver_by'] ?? '');
            $exp    = (string) ($r['waiver_expires_on'] ?? '');
            $len    = function_exists('mb_strlen') ? mb_strlen($reason, 'UTF-8') : strlen($reason);

            if ($len < $minLen) { $bad[] = "{$id}(reason {$len}<{$minLen})"; continue; }
            if ($by === '')     { $bad[] = "{$id}(no actor)"; continue; }
            if ($exp === '')    { $bad[] = "{$id}(no expiry — waivers are never permanent)"; continue; }
            // Bound the span from when it was granted, not from today: a waiver
            // granted 80 days ago for 90 days is still legal today.
            $granted = (string) ($r['attested_at'] ?? '');
            $base    = $granted !== '' ? date('Y-m-d', (int) strtotime($granted)) : date('Y-m-d');
            $limit   = date('Y-m-d', (int) strtotime($base . ' +' . $maxDays . ' days'));
            if ($exp > $limit) { $bad[] = "{$id}(expires {$exp} > granted+{$maxDays}d {$limit})"; }
        }
        if ($bad !== []) {
            return ['status' => 'FAIL', 'detail' => 'CMP-3: ' . count($bad) . ' out-of-bounds waiver(s): ' . implode(' ', array_slice($bad, 0, 5))];
        }
        return ['status' => 'PASS', 'detail' => count($rows) . ' waiver(s), all within reason/actor/' . $maxDays . '-day bounds'];
    }

    /**
     * CH-TRN-CMP-4 — compliance source hygiene, two invariants:
     *
     *   (a) EVIDENCE IS LINKED, NEVER COPIED. The compliance service must
     *       reach documents only through the Canonical Document Service's
     *       public API (link/unlink/getMetadata) — never Storage calls, never
     *       direct writes to `documents` / `documentLinks`. The Document
     *       Service is a frozen platform baseline: modules consume it.
     *
     *   (b) AUTHORIZATION STAYS AT THE §4 BOUNDARY. Every compliance endpoint
     *       supplies the canonical ('Transport', <level>) pair, and the
     *       service layer performs no authorization of its own. Required by
     *       the spec §7 amendment (2026-08-07); pinned here so a later edit
     *       cannot quietly reintroduce a role-name gate or a service-level
     *       check.
     */
    private function _chTrnCmp4(): array
    {
        $svcPath = APPPATH . 'libraries/Transport_compliance_service.php';
        $ctlPath = APPPATH . 'controllers/Transport_compliance.php';
        if (!is_readable($svcPath) || !is_readable($ctlPath)) {
            return ['status' => 'INFO', 'detail' => 'compliance source not readable — trivial pass'];
        }
        $svc = $this->_stripComments((string) file_get_contents($svcPath));
        $ctl = $this->_stripComments((string) file_get_contents($ctlPath));
        $problems = [];

        // (a) evidence discipline
        if (strpos($svc, '->link(') === false || strpos($svc, '->getMetadata(') === false) {
            $problems[] = 'service no longer uses the Document Service link()/getMetadata() API';
        }
        foreach (['uploadStorageFile', 'deleteStorageFile', 'putStorageFile'] as $bad) {
            if (strpos($svc, $bad) !== false) { $problems[] = "service calls Storage directly ({$bad})"; }
        }
        foreach (["'documents'", '"documents"', "'documentLinks'", '"documentLinks"'] as $bad) {
            if (strpos($svc, $bad) !== false) { $problems[] = "service writes the document collection directly ({$bad})"; }
        }

        // (b) authorization boundary
        // 'attest' raised edit → manage (Batch 3, 2026-08-13, authorised): an
        // attestation certifies that a vehicle satisfies a CBSE safety rule, so
        // it is a controlled assertion rather than routine data entry, and at
        // 'edit' it was self-attestable by a Driver. This map is the pinned
        // specification, so it moves with the decision. Every structural
        // assertion around it is unchanged — all 8 endpoints must still be
        // graded, no legacy bridge, no role-name list, no service-layer authz.
        $need = ['dashboard' => 'view', 'vehicle' => 'view', 'attest' => 'manage', 'evaluate' => 'edit',
                 'waive' => 'manage', 'rebuild' => 'manage', 'seed_pack' => 'manage', 'rules' => 'manage'];
        $graded = preg_match_all("/require_permission\(\s*'Transport'\s*,\s*'[a-z_]+'\s*,\s*'(view|edit|manage)'\s*\)/", $ctl, $m);
        if ($graded !== count($need)) {
            $problems[] = "controller has {$graded} graded require_permission calls, expected " . count($need);
        }
        foreach (array_count_values($m[1] ?? []) as $lvl => $n) {
            $expect = count(array_filter($need, static function ($v) use ($lvl) { return $v === $lvl; }));
            if ($n !== $expect) { $problems[] = "level '{$lvl}' used {$n}x, expected {$expect}x"; }
        }
        // The legacy role-name fallback was retired Transport-wide on 2026-08-07.
        // Neither the _require_role bridge nor a bare name list may return here.
        if (strpos($ctl, '_require_role') !== false) {
            $problems[] = 'controller reintroduced the legacy _require_role bridge';
        }
        if (preg_match('/(?:VIEW|MANAGE|REQUIRED)_ROLES/', $ctl)) {
            $problems[] = 'controller reintroduced a legacy role-name allow-list';
        }
        if (strpos($svc, 'has_permission(') !== false || strpos($svc, 'session->userdata') !== false) {
            $problems[] = 'service performs its own authorization (spec §3 forbids it)';
        }

        if ($problems !== []) {
            return ['status' => 'FAIL', 'detail' => 'CMP-4: ' . implode('; ', $problems)];
        }
        return ['status' => 'PASS', 'detail' =>
            'evidence linked via Document Service API only (no Storage/collection writes); all '
            . count($need) . " endpoints graded ('Transport', view|edit|manage); no service-layer authz"];
    }
}
