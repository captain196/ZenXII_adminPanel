<?php
namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

if (!defined('APPPATH')) define('APPPATH', __DIR__ . '/../../application/');
require_once __DIR__ . '/../../application/helpers/effective_dating_helper.php';
require_once __DIR__ . '/../../application/libraries/Salary_component_service.php';
require_once __DIR__ . '/../../application/libraries/Statutory_config_service.php';
require_once __DIR__ . '/../../application/libraries/Salary_assignment_service.php';
require_once __DIR__ . '/../../application/libraries/Compensation_reader.php';

/**
 * ZenX HRMS W2.4 — Compensation_reader.
 *
 * The reader is the seam between payroll and where salary comes from. Its whole
 * value is that `Hr::_compute_payslip()` cannot tell which store answered, so
 * these tests concentrate on two things:
 *
 *   • the component path produces the SAME thirteen fields as the legacy path
 *     (amounts in rupees, PF/ESI/TDS as percentages);
 *   • `compare()` catches a real one-paisa difference and does not invent one.
 *
 * A tolerance that hides a paisa would be worse than no comparison at all — the
 * cutover decision rests on this primitive.
 */
class CompensationReaderTest extends TestCase
{
    private function catalogue(): array
    {
        $out = [];
        foreach (\Salary_component_service::systemComponents() as $c) $out[$c['code']] = $c;
        return $out;
    }

    private function legacyStructure(): array
    {
        return [
            'basic' => 30000, 'hra' => 12000, 'da' => 0, 'ta' => 0,
            'medical' => 0, 'other_allowances' => 0,
            'pf_employee' => 12, 'pf_employer' => 12,
            'esi_employee' => 0.75, 'esi_employer' => 3.25,
            'professional_tax' => 200, 'tds' => 0, 'other_deductions' => 0,
        ];
    }

    /** A reader driven by an in-memory assignment + catalogue. */
    private function readerFor(array $assignment, ?array $catalogue = null): \Compensation_reader
    {
        $cat = $catalogue ?? $this->catalogue();

        $assignSvc = new class($assignment) {
            private ?array $a;
            public function __construct(?array $a) { $this->a = $a; }
            public function resolve(string $staffId, string $date): ?array { return $this->a; }
        };
        $fs = new class($cat) {
            private array $cat;
            public array $legacyDoc = [];
            public function __construct(array $c) { $this->cat = $c; }
            public function schoolWhere(string $col, array $cond = []): array {
                $out = [];
                foreach ($this->cat as $code => $c) $out[] = ['id' => $code, 'data' => $c];
                return $out;
            }
            public function getEntity(string $col, string $id) { return $this->legacyDoc ?: null; }
        };

        $r = new \Compensation_reader();
        $r->init($fs, \Compensation_reader::MODE_COMPONENT, $assignSvc);
        return $r;
    }

    // ── Mode resolution ──────────────────────────────────────────────────

    public function test_flags_resolve_to_the_right_mode(): void
    {
        $this->assertSame(\Compensation_reader::MODE_LEGACY,
            \Compensation_reader::modeFromFlags(false, false), 'both off = today\'s behaviour');
        $this->assertSame(\Compensation_reader::MODE_SHADOW,
            \Compensation_reader::modeFromFlags(false, true));
        $this->assertSame(\Compensation_reader::MODE_COMPONENT,
            \Compensation_reader::modeFromFlags(true, false));
        $this->assertSame(\Compensation_reader::MODE_COMPONENT,
            \Compensation_reader::modeFromFlags(true, true), 'cutover wins over shadow');
    }

    public function test_shipped_flag_defaults_are_off(): void
    {
        // The gate is only safe while both are false; pin it so a stray edit is loud.
        $cfg = [];
        $config = &$cfg;
        require __DIR__ . '/../../application/config/hrms_compensation_flags.php';
        $this->assertFalse($config['USE_COMPONENT_COMPENSATION']);
        $this->assertFalse($config['COMPENSATION_SHADOW_COMPARE']);
    }

    // ── The money boundary ───────────────────────────────────────────────

    public function test_paise_to_rupees_is_exact_at_the_calculator_boundary(): void
    {
        $this->assertSame(0.0,      \Compensation_reader::paiseToRupees(0));
        $this->assertSame(0.01,     \Compensation_reader::paiseToRupees(1));
        $this->assertSame(30000.0,  \Compensation_reader::paiseToRupees(3000000));
        $this->assertSame(30000.01, \Compensation_reader::paiseToRupees(3000001), 'odd paise survive');
        $this->assertSame(999999.99, \Compensation_reader::paiseToRupees(99999999));
    }

    public function test_round_trip_paise_rupees_paise_loses_nothing(): void
    {
        // The calculator converts straight back with _toPaise(); a lost paisa
        // here would break gross == net + deductions downstream.
        $toPaise = fn(float $r) => (int) round($r * 100.0);
        foreach ([0, 1, 7, 50, 99, 100, 101, 999, 1234567, 3000001, 99999999] as $p) {
            $this->assertSame($p, $toPaise(\Compensation_reader::paiseToRupees($p)), "paise {$p}");
        }
    }

    // ── Component path shape ─────────────────────────────────────────────

    public function test_component_path_returns_the_calculator_shape(): void
    {
        $a = \Salary_assignment_service::fromLegacyStructure($this->legacyStructure(), 'STA0001', '2026-04-01');
        $out = $this->readerFor($a)->resolveFromComponents('STA0001', '2026-06-01');

        $this->assertNotNull($out);
        foreach (\Compensation_reader::LEGACY_FIELDS as $f) {
            $this->assertArrayHasKey($f, $out, "the calculator reads {$f}");
        }
        $this->assertCount(13, $out);
    }

    public function test_fixed_lines_become_rupees_and_percent_lines_stay_rates(): void
    {
        $a = \Salary_assignment_service::fromLegacyStructure($this->legacyStructure(), 'STA0001', '2026-04-01');
        $out = $this->readerFor($a)->resolveFromComponents('STA0001', '2026-06-01');

        $this->assertSame(30000.0, $out['basic'], 'amount in rupees');
        $this->assertSame(12000.0, $out['hra']);
        $this->assertSame(200.0,   $out['professional_tax']);

        // The calculator multiplies these by a base; they must NOT be amounts.
        $this->assertSame(12.0,  $out['pf_employee']);
        $this->assertSame(0.75,  $out['esi_employee']);
        $this->assertSame(3.25,  $out['esi_employer']);
    }

    /** THE PARITY PROPERTY: legacy → assignment → reader reproduces legacy. */
    public function test_round_trip_through_the_migration_is_lossless(): void
    {
        $legacyDoc = $this->legacyStructure();
        $legacyShape = [];
        foreach (\Compensation_reader::LEGACY_FIELDS as $f) {
            $legacyShape[$f] = (float) ($legacyDoc[$f] ?? 0);
        }

        $a = \Salary_assignment_service::fromLegacyStructure($legacyDoc, 'STA0001', '2026-04-01');
        $component = $this->readerFor($a)->resolveFromComponents('STA0001', '2026-06-01');

        $this->assertSame([], \Compensation_reader::compare($legacyShape, $component),
            'the migration + reader must reproduce the legacy inputs exactly');
    }

    public function test_odd_paise_survive_the_full_round_trip(): void
    {
        // ₹30,000.01 is the case a naive paise→rupee conversion loses.
        $doc = $this->legacyStructure();
        $doc['basic'] = 30000.01;

        $legacyShape = [];
        foreach (\Compensation_reader::LEGACY_FIELDS as $f) $legacyShape[$f] = (float) ($doc[$f] ?? 0);

        $a = \Salary_assignment_service::fromLegacyStructure($doc, 'STA0001', '2026-04-01');
        $component = $this->readerFor($a)->resolveFromComponents('STA0001', '2026-06-01');

        $this->assertSame([], \Compensation_reader::compare($legacyShape, $component));
        $this->assertSame(30000.01, $component['basic']);
    }

    public function test_balancing_component_is_expanded_not_dropped(): void
    {
        // Without expansion the remainder would silently vanish from gross.
        $cat = $this->catalogue();
        $cat['OTHER_ALLOWANCES']['calcType'] = 'BALANCING';

        $a = [
            'staffId' => 'STA0001', 'effectiveFrom' => '2026-04-01', 'effectiveTo' => null,
            'status' => 'active', 'grossPaise' => 5000000,
            'lines' => [
                ['componentId' => 'BASIC', 'calcType' => 'FIXED', 'basis' => 'NONE', 'amountPaise' => 2000000],
                ['componentId' => 'OTHER_ALLOWANCES', 'calcType' => 'BALANCING', 'basis' => 'NONE'],
            ],
        ];
        $out = $this->readerFor($a, $cat)->resolveFromComponents('STA0001', '2026-06-01');

        $this->assertSame(20000.0, $out['basic']);
        $this->assertSame(30000.0, $out['other_allowances'], 'the ₹30,000 remainder reaches the calculator');
    }

    // ── Null is a block, never zero pay ──────────────────────────────────

    public function test_no_assignment_resolves_to_null_not_a_zero_salary(): void
    {
        $r = $this->readerFor([]);
        $this->assertNull($r->resolveFromComponents('STA0001', '2026-06-01'),
            'callers must block payroll, not pay zero');
    }

    public function test_a_draft_assignment_is_not_payable(): void
    {
        $a = \Salary_assignment_service::fromLegacyStructure($this->legacyStructure(), 'STA0001', '2026-04-01');
        $a['status'] = 'draft';
        $this->assertNull($this->readerFor($a)->resolveFromComponents('STA0001', '2026-06-01'));
    }

    public function test_an_assignment_with_no_lines_is_not_payable(): void
    {
        $a = \Salary_assignment_service::fromLegacyStructure($this->legacyStructure(), 'STA0001', '2026-04-01');
        $a['lines'] = [];
        $this->assertNull($this->readerFor($a)->resolveFromComponents('STA0001', '2026-06-01'));
    }

    // ── compare(): the primitive the cutover decision rests on ───────────

    public function test_compare_reports_nothing_for_identical_structures(): void
    {
        $s = array_fill_keys(\Compensation_reader::LEGACY_FIELDS, 0.0);
        $s['basic'] = 30000.0; $s['pf_employee'] = 12.0;
        $this->assertSame([], \Compensation_reader::compare($s, $s));
    }

    public function test_compare_detects_a_single_paisa_difference(): void
    {
        // The whole point: no tolerance may hide one paisa.
        $a = array_fill_keys(\Compensation_reader::LEGACY_FIELDS, 0.0);
        $b = $a;
        $a['basic'] = 30000.00;
        $b['basic'] = 30000.01;

        $diff = \Compensation_reader::compare($a, $b);
        $this->assertArrayHasKey('basic', $diff);
        $this->assertSame(30000.00, $diff['basic']['legacy']);
        $this->assertSame(30000.01, $diff['basic']['component']);
    }

    public function test_compare_does_not_invent_a_difference_from_float_noise(): void
    {
        $a = array_fill_keys(\Compensation_reader::LEGACY_FIELDS, 0.0);
        $b = $a;
        $a['basic'] = 0.1 + 0.2;   // 0.30000000000000004
        $b['basic'] = 0.3;
        $this->assertSame([], \Compensation_reader::compare($a, $b), 'compared at paise precision');
    }

    public function test_compare_detects_a_rate_difference(): void
    {
        $a = array_fill_keys(\Compensation_reader::LEGACY_FIELDS, 0.0);
        $b = $a;
        $a['pf_employee'] = 12.0;
        $b['pf_employee'] = 10.0;
        $this->assertArrayHasKey('pf_employee', \Compensation_reader::compare($a, $b));
    }

    public function test_compare_flags_a_missing_side(): void
    {
        $s = array_fill_keys(\Compensation_reader::LEGACY_FIELDS, 0.0);
        $this->assertArrayHasKey('__missing', \Compensation_reader::compare(null, $s));
        $this->assertArrayHasKey('__missing', \Compensation_reader::compare($s, null));
        $this->assertSame([], \Compensation_reader::compare(null, null), 'both absent is consistent');
    }

    // ── Boundary ─────────────────────────────────────────────────────────

    /**
     * W3.1 RE-STATED, NOT RELAXED.
     *
     * This used to assert the reader was referenced nowhere in the payroll
     * controllers. W3.1 wires `Hr.php` to it deliberately — that is the seam,
     * and the reason it exists: the W2 cutover becomes a MODE change instead of
     * a rewrite of the payroll run.
     *
     * The protective half of the original assertion is what survives, and it is
     * the half that actually keeps the cutover un-flipped: the payroll
     * controller must reach compensation ONLY through the seam, and must name
     * none of the classes or collections behind it. A future change that starts
     * resolving assignments inline would still fail here.
     */
    public function test_payroll_reaches_compensation_only_through_the_seam(): void
    {
        $hr = file_get_contents(__DIR__ . '/../../application/controllers/Hr.php');
        $this->assertNotFalse($hr);

        $this->assertStringContainsString('Compensation_reader', $hr,
            'the payroll run is expected to resolve compensation through the seam');

        foreach (['staffSalaryAssignments', 'salaryComponents',
                  'Salary_assignment_service', 'Salary_component_service'] as $behindTheSeam) {
            $this->assertStringNotContainsString($behindTheSeam, $hr,
                "Hr.php must not reach past the seam to {$behindTheSeam}");
        }

        // Payroll.php is not part of the seam and must stay clear of it.
        $payroll = file_get_contents(__DIR__ . '/../../application/controllers/Payroll.php');
        $this->assertNotFalse($payroll);
        $this->assertStringNotContainsString('Compensation_reader', $payroll);
    }

    public function test_no_rtdb_and_no_hardcoded_tenant(): void
    {
        $src = file_get_contents(__DIR__ . '/../../application/libraries/Compensation_reader.php');
        $this->assertNotFalse($src);
        $this->assertStringNotContainsString('firebase->', $src);
        $this->assertDoesNotMatchRegularExpression('/SCH_[A-Z0-9]{6,}/', $src);
    }

    public function test_statutory_config_is_deliberately_not_in_this_path(): void
    {
        // Feeding ceilings/thresholds in would change computed money, which is a
        // payroll-behaviour change and belongs to its own gate — not to a reader
        // whose purpose is to be inert.
        $src = file_get_contents(__DIR__ . '/../../application/libraries/Compensation_reader.php');
        $this->assertStringNotContainsString('Statutory_config_service::computePf', (string) $src);
        $this->assertStringNotContainsString('computeEsi', (string) $src);
    }
}
