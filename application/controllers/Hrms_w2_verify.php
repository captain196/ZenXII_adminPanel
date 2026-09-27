<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Hrms_w2_verify — RUNTIME verification for the W2 Compensation Core gates.
 *
 * READ-ONLY. Zero writes, no documents created, no tenant mutated. Safe against
 * a live tenant during traffic. Idempotent and re-runnable.
 *
 * WHY A RUNTIME HARNESS AND NOT JUST PHPUNIT
 * ------------------------------------------
 * The single most important W2.2 assertion is that
 * `Statutory_config_service::applyRatio()` is arithmetically IDENTICAL to the
 * W1.2 money engine `Hr::_applyRatio()`. Proving that requires invoking both.
 * `Hr` extends `MY_Controller`, which the PHPUnit bootstrap cannot load, so the
 * numeric proof lives here — the same place the P0 harness already exercises
 * Hr's private methods via reflection. The unit suite pins the formula
 * textually; this pins the numbers.
 *
 * INVOCATION:  php index.php hrms_w2_verify run
 */
class Hrms_w2_verify extends CI_Controller
{
    private int $pass = 0;
    private int $fail = 0;
    private int $skip = 0;
    private array $failures = [];

    public function __construct()
    {
        parent::__construct();
        if (!defined('STDIN')) exit("CLI only\n");
        $this->load->library('salary_component_service', null, 'components');
        $this->load->library('statutory_config_service', null, 'statutory');
    }

    private function ok(string $name, bool $cond, string $detail = ''): void
    {
        if ($cond) { $this->pass++; echo "  [PASS] {$name}" . ($detail !== '' ? "  ({$detail})" : '') . "\n"; }
        else { $this->fail++; $this->failures[] = "{$name} — {$detail}"; echo "  [FAIL] {$name}  ({$detail})\n"; }
    }
    private function skipped(string $n, string $w): void { $this->skip++; echo "  [SKIP] {$n}  ({$w})\n"; }
    private function head(string $t): void { echo "\n" . str_repeat('=', 74) . "\n{$t}\n" . str_repeat('=', 74) . "\n"; }

    public function run(): void
    {
        echo "\nZenX HRMS — W2 COMPENSATION CORE :: RUNTIME VERIFICATION\n";
        echo "READ-ONLY — no writes, no tenant mutated.\n";

        $this->group_money_equivalence();
        $this->group_calculator_w27();
        $this->group_resolver();
        $this->group_boundary();
        $this->group_live_state();

        $this->head('SUMMARY');
        echo "  PASS {$this->pass}   FAIL {$this->fail}   SKIP {$this->skip}\n";
        foreach ($this->failures as $f) echo "    - {$f}\n";
        echo "\n" . ($this->fail === 0 ? "RESULT: PASS\n" : "RESULT: FAIL\n");
    }

    // ── A. The money-equivalence proof ───────────────────────────────────

    private function group_money_equivalence(): void
    {
        $this->head('A. Money arithmetic — Statutory service vs the W1.2 engine (both invoked)');

        require_once APPPATH . 'controllers/Hr.php';
        $m = new ReflectionMethod('Hr', '_applyRatio');
        $m->setAccessible(true);

        // Amounts chosen to exercise rounding edges: odd paise, half-paise
        // products, statutory ceilings/thresholds, and large values.
        $amounts = [0, 1, 3, 7, 50, 99, 100, 333, 1250, 1500000, 2100000,
                    3333333, 5000001, 987654321];
        $ratios  = [0.0, 0.0075, 0.0325, 0.0367, 0.12, 0.1, 0.5, 1.0,
                    0.333333, 0.666667, 0.005, 0.015];

        $checked = 0; $same = 0; $firstDiff = '';
        foreach ($amounts as $a) {
            foreach ($ratios as $r) {
                $checked++;
                $expected = (int) $m->invoke(null, $a, $r);
                $actual   = Statutory_config_service::applyRatio($a, $r);
                if ($expected === $actual) { $same++; }
                elseif ($firstDiff === '') { $firstDiff = "applyRatio({$a}, {$r}): Hr={$expected} vs statutory={$actual}"; }
            }
        }
        $this->ok('A1 applyRatio is byte-identical to Hr::_applyRatio',
            $checked > 0 && $same === $checked, "{$same}/{$checked}" . ($firstDiff ? " | first divergence: {$firstDiff}" : ''));

        // Half-up rounding on an exact .5 product is the classic divergence point.
        $this->ok('A2 exact .5 products round identically',
            (int) $m->invoke(null, 1, 0.5) === Statutory_config_service::applyRatio(1, 0.5),
            'Hr=' . $m->invoke(null, 1, 0.5) . ' statutory=' . Statutory_config_service::applyRatio(1, 0.5));

        // A rate must never be turned into money.
        $this->ok('A3 pctToRatio does not convert a rate to paise',
            abs(Statutory_config_service::pctToRatio(12.0) - 0.12) < 1e-12);
    }

    // ── A2. W2.7 calculator extensions — executed against the REAL engine ──

    /** Real Hr instance without running the constructor (pure methods only). */
    private function hr()
    {
        require_once APPPATH . 'controllers/Hr.php';
        return (new ReflectionClass('Hr'))->newInstanceWithoutConstructor();
    }

    private function payslip($hr, array $sal, float $wd, float $abs, float $lwp,
                             float $xd = 0.0, float $xr = 1.0, ?array $stat = null, array $extras = [])
    {
        $m = new ReflectionMethod('Hr', '_compute_payslip');
        $m->setAccessible(true);
        return $m->invokeArgs($hr, [$sal, $wd, $abs, $lwp, $xd, $xr, $stat, $extras]);
    }

    private function group_calculator_w27(): void
    {
        $this->head('A2. W2.7 — statutory-aware and component-aware payslip (real engine)');

        $hr  = $this->hr();
        $sal = [
            'basic' => 30000, 'hra' => 12000, 'da' => 0, 'ta' => 0, 'medical' => 0, 'other_allowances' => 0,
            'pf_employee' => 12, 'pf_employer' => 12, 'esi_employee' => 0.75, 'esi_employer' => 3.25,
            'professional_tax' => 200, 'tds' => 0, 'other_deductions' => 0,
        ];

        // ── THE LOAD-BEARING CHECK: defaults reproduce the certified engine ──
        // Every existing call site omits the two new parameters, so if this
        // diverges by even a paisa the whole compensation programme is unsafe.
        $legacy = $this->payslip($hr, $sal, 26.0, 2.0, 1.0);
        $explicitDefaults = $this->payslip($hr, $sal, 26.0, 2.0, 1.0, 0.0, 1.0, null, []);
        $this->ok('W1 defaults are byte-identical to the legacy call',
            $legacy['paise'] == $explicitDefaults['paise'],
            'net=' . $legacy['paise']['net_pay']);

        $this->ok('W2 legacy path is labelled as structure-sourced',
            ($legacy['statutory_source'] ?? '') === 'structure');
        $this->ok('W3 legacy path emits no tenant components',
            ($legacy['extra_components'] ?? []) === []);

        // ── Ceiling awareness (the W2.6 inconsistency, now closed) ──
        $cfg = [
            'effectiveFrom' => '2026-04-01', 'effectiveTo' => null,
            'pf'  => ['employeeRate' => 12.0, 'employerRate' => 12.0,
                      'wageCeilingPaise' => 1500000, 'applyCeiling' => true, 'eligibleAboveCeiling' => true],
            'esi' => ['employeeRate' => 0.75, 'employerRate' => 3.25,
                      'wageThresholdPaise' => 2100000, 'applicable' => true],
            'pt'  => ['state' => '', 'slabs' => []],
            'tds' => ['mode' => 'FLAT_PERCENT', 'rate' => 0.0],
        ];

        // Full month, no absence: earned basic = ₹30,000, above the ₹15,000 ceiling.
        $noStat = $this->payslip($hr, $sal, 26.0, 0.0, 0.0);
        $withStat = $this->payslip($hr, $sal, 26.0, 0.0, 0.0, 0.0, 1.0, $cfg, []);

        $this->ok('W4 without statutory config PF ignores the ceiling (legacy)',
            (int) $noStat['paise']['pf_employee'] === 360000, 'ee=' . $noStat['paise']['pf_employee']);
        $this->ok('W5 with statutory config PF caps at the ceiling',
            (int) $withStat['paise']['pf_employee'] === 180000, 'ee=' . $withStat['paise']['pf_employee']);
        $this->ok('W6 the payslip records which policy priced it',
            ($withStat['statutory_source'] ?? '') === 'statutoryConfig');

        // Gross ₹42,000 exceeds the ₹21,000 ESI threshold → not covered at all.
        $this->ok('W7 ESI above threshold is not deducted (coverage, not cap)',
            (int) $withStat['paise']['esi_employee'] === 0, 'ee=' . $withStat['paise']['esi_employee']);
        $this->ok('W8 the payslip explains why ESI was not applied',
            count(array_filter($withStat['warnings'], fn($w) => stripos($w, 'ESI') !== false)) > 0);

        // PT slabs supersede the structure's flat amount ONLY when configured.
        $this->ok('W9 with no slab table the structure PT stands',
            (int) $withStat['paise']['professional_tax'] === 20000,
            'pt=' . $withStat['paise']['professional_tax']);

        $slabCfg = $cfg;
        $slabCfg['pt']['slabs'] = [
            ['fromPaise' => 0, 'toPaise' => 2000000, 'amountPaise' => 0],
            ['fromPaise' => 2000001, 'toPaise' => null, 'amountPaise' => 25000],
        ];
        $withSlabs = $this->payslip($hr, $sal, 26.0, 0.0, 0.0, 0.0, 1.0, $slabCfg, []);
        $this->ok('W10 a configured slab table supersedes the flat PT',
            (int) $withSlabs['paise']['professional_tax'] === 25000,
            'pt=' . $withSlabs['paise']['professional_tax']);

        // ── Tenant components reach the payslip ──
        $extras = [
            ['code' => 'CONVEYANCE', 'kind' => 'earning',   'amountPaise' => 250000, 'prorated' => true],
            ['code' => 'CANTEEN',    'kind' => 'deduction', 'amountPaise' => 50000,  'prorated' => false],
        ];
        $withExtras = $this->payslip($hr, $sal, 26.0, 0.0, 0.0, 0.0, 1.0, null, $extras);

        $this->ok('W11 a tenant earning raises gross',
            (int) $withExtras['paise']['gross'] === (int) $noStat['paise']['gross'] + 250000,
            $withExtras['paise']['gross'] . ' vs ' . $noStat['paise']['gross']);
        // Isolate the deduction: $withExtras also carries a ₹2,500 earning, which
        // outweighs the ₹500 deduction, so comparing it to the baseline would
        // (correctly) show net RISING. Test the deduction on its own.
        $dedOnly = $this->payslip($hr, $sal, 26.0, 0.0, 0.0, 0.0, 1.0, null,
            [['code' => 'CANTEEN', 'kind' => 'deduction', 'amountPaise' => 50000, 'prorated' => false]]);
        $this->ok('W12 a tenant deduction reduces net by exactly its amount',
            (int) $dedOnly['paise']['net_pay'] === (int) $noStat['paise']['net_pay'] - 50000,
            $noStat['paise']['net_pay'] . ' → ' . $dedOnly['paise']['net_pay']);
        $this->ok('W13 tenant components appear in the breakdown',
            ($withExtras['extra_components']['CONVEYANCE'] ?? 0) === 250000
            && ($withExtras['extra_components']['CANTEEN'] ?? 0) === -50000);

        // The invariant that must survive everything.
        $g = (int) $withExtras['paise']['gross'];
        $n = (int) $withExtras['paise']['net_pay'];
        $d = (int) $withExtras['paise']['pf_employee'] + (int) $withExtras['paise']['esi_employee']
           + (int) $withExtras['paise']['tds'] + (int) $withExtras['paise']['professional_tax']
           + (int) $withExtras['paise']['other_deductions'];
        $this->ok('W14 gross == net + deductions still holds with tenant components',
            $g === $n + $d, "{$g} == {$n} + {$d}");

        // Prorated extras follow attendance, exactly like basic and HRA.
        $absent = $this->payslip($hr, $sal, 26.0, 13.0, 0.0, 0.0, 1.0, null, $extras);
        $this->ok('W15 a prorated tenant earning scales with attendance',
            (int) $absent['extra_components']['CONVEYANCE'] === 125000,
            'half a month → ' . $absent['extra_components']['CONVEYANCE']);
        $this->ok('W16 a non-prorated tenant deduction does not scale',
            (int) $absent['extra_components']['CANTEEN'] === -50000);
    }

    // ── B. Resolver executed ─────────────────────────────────────────────

    private function group_resolver(): void
    {
        $this->head('B. Statutory resolver — real production code, executed');

        $cfg = [
            'effectiveFrom' => '2026-04-01', 'effectiveTo' => null,
            'pf'  => ['employeeRate' => 12.0, 'employerRate' => 12.0,
                      'wageCeilingPaise' => 1500000, 'applyCeiling' => true, 'eligibleAboveCeiling' => true],
            'esi' => ['employeeRate' => 0.75, 'employerRate' => 3.25,
                      'wageThresholdPaise' => 2100000, 'applicable' => true],
            'pt'  => ['state' => '', 'slabs' => [
                        ['fromPaise' => 0,       'toPaise' => 1500000, 'amountPaise' => 0],
                        ['fromPaise' => 1500001, 'toPaise' => null,    'amountPaise' => 20000]]],
            'tds' => ['mode' => 'FLAT_PERCENT', 'rate' => 0.0],
        ];

        $pfUnder = Statutory_config_service::computePf($cfg, 1000000);
        $this->ok('B1 PF below ceiling → 12% of full wage',
            $pfUnder['employeePaise'] === 120000 && $pfUnder['basePaise'] === 1000000,
            'ee=' . $pfUnder['employeePaise']);

        $pfOver = Statutory_config_service::computePf($cfg, 5000000);
        $this->ok('B2 PF above ceiling → capped at the ceiling',
            $pfOver['basePaise'] === 1500000 && $pfOver['employeePaise'] === 180000,
            'base=' . $pfOver['basePaise']);

        $excl = $cfg; $excl['pf']['eligibleAboveCeiling'] = false;
        $pfExcl = Statutory_config_service::computePf($excl, 5000000);
        $this->ok('B3 PF excluded-employee → NOT covered (not merely capped)',
            $pfExcl['covered'] === false && $pfExcl['employeePaise'] === 0, $pfExcl['reason']);

        $esiIn = Statutory_config_service::computeEsi($cfg, 2000000);
        $this->ok('B4 ESI below threshold → covered on the FULL wage',
            $esiIn['covered'] && $esiIn['employeePaise'] === 15000 && $esiIn['employerPaise'] === 65000,
            'ee=' . $esiIn['employeePaise'] . ' er=' . $esiIn['employerPaise']);

        $esiEdge = Statutory_config_service::computeEsi($cfg, 2100000);
        $this->ok('B5 ESI exactly at threshold → still covered', $esiEdge['covered']);

        $esiOut = Statutory_config_service::computeEsi($cfg, 2100001);
        $this->ok('B6 ESI above threshold → not covered, not capped',
            $esiOut['covered'] === false && $esiOut['employeePaise'] === 0, $esiOut['reason']);

        $this->ok('B7 PT slab boundaries',
            Statutory_config_service::computePt($cfg, 1500000)['amountPaise'] === 0
            && Statutory_config_service::computePt($cfg, 1500001)['amountPaise'] === 20000
            && Statutory_config_service::computePt($cfg, 99999999)['amountPaise'] === 20000);

        $tds = Statutory_config_service::computeTds(
            array_merge($cfg, ['tds' => ['mode' => 'FLAT_PERCENT', 'rate' => 10.0]]), 5000000);
        $this->ok('B8 TDS flat percent parity with current behaviour',
            $tds['supported'] && $tds['amountPaise'] === 500000, 'amt=' . $tds['amountPaise']);

        $slab = Statutory_config_service::computeTds(
            array_merge($cfg, ['tds' => ['mode' => 'SLAB']]), 5000000);
        $this->ok('B9 TDS slab mode refuses to guess a figure',
            $slab['supported'] === false && $slab['amountPaise'] === 0);

        // Effective dating
        $set = [
            ['effectiveFrom' => '2025-04-01', 'effectiveTo' => '2026-03-31', 'tag' => 'FY25'],
            ['effectiveFrom' => '2026-04-01', 'effectiveTo' => null,         'tag' => 'FY26'],
        ];
        $this->ok('B10 effective dating selects the window in force',
            Statutory_config_service::selectFor($set, '2026-03-31')['tag'] === 'FY25'
            && Statutory_config_service::selectFor($set, '2026-04-01')['tag'] === 'FY26');
        $this->ok('B11 a date before any configuration resolves to nothing',
            Statutory_config_service::selectFor($set, '2025-01-01') === null);
        $this->ok('B12 overlapping windows are detected',
            count(Statutory_config_service::findOverlaps([
                ['effectiveFrom' => '2025-04-01', 'effectiveTo' => null],
                ['effectiveFrom' => '2026-04-01', 'effectiveTo' => null],
            ])) > 0);
    }

    // ── C. Gate boundary ─────────────────────────────────────────────────

    private function group_boundary(): void
    {
        $this->head('C. W2.2 boundary — payroll path untouched');

        // W2.7 MOVED THIS BOUNDARY DELIBERATELY, and it is re-stated rather than
        // relaxed. `Hr.php` may now use Statutory_config_service, because the
        // calculator became statutory-aware — that is the whole point of the
        // gate, and it is what stops an operator configuring a PF ceiling that
        // silently does nothing.
        //
        // What must STILL be absent is the CUTOVER: the calculator receives
        // statutory policy and tenant components as PARAMETERS from its caller.
        // It must not fetch them itself, and it must not know about the reader
        // or the assignment collection — otherwise the compensation path would
        // be live without the parity gate and the flag.
        $hrSrc = (string) file_get_contents(APPPATH . 'controllers/Hr.php');

        $this->ok('C Hr.php MAY use the statutory resolver (W2.7 — deliberate)',
            strpos($hrSrc, 'Statutory_config_service') !== false,
            'the calculator is now ceiling-aware');
        $this->ok('C Hr.php does NOT query statutoryConfig itself',
            strpos($hrSrc, "schoolWhere('statutoryConfig'") === false
            && strpos($hrSrc, "getEntity('statutoryConfig'") === false,
            'policy arrives as a parameter, not a fetch');
        $this->ok('C Hr.php does NOT read the component catalogue',
            strpos($hrSrc, 'salaryComponents') === false);
        $this->ok('C Hr.php does NOT read salary assignments (cutover not done)',
            strpos($hrSrc, 'staffSalaryAssignments') === false);
        // W3.1 RE-STATED. Wiring the payroll run to the reader IS the seam, and
        // is what makes the W2 cutover a mode change rather than a rewrite. The
        // two assertions immediately above are the ones that keep the cutover
        // un-flipped — Hr.php reaches compensation only through the seam and
        // resolves nothing behind it — so those, not this, are the real guard.
        $this->ok('C Hr.php reaches compensation through the reader seam (W3.1)',
            strpos($hrSrc, 'Compensation_reader') !== false);
        $this->ok('C Hr.php does not construct the assignment service itself',
            strpos($hrSrc, 'Salary_assignment_service') === false);

        $paySrc = (string) file_get_contents(APPPATH . 'controllers/Payroll.php');
        foreach (['statutoryConfig', 'Statutory_config_service', 'salaryComponents',
                  'staffSalaryAssignments', 'Compensation_reader'] as $needle) {
            $this->ok("C Payroll.php does not reference {$needle}",
                strpos($paySrc, $needle) === false);
        }

        $this->ok('C W2.1 catalogue still has exactly 13 system components',
            count(Salary_component_service::systemComponents()) === 13);
    }

    // ── D. Live tenant state (read-only) ─────────────────────────────────

    private function group_live_state(): void
    {
        $this->head('D. Live state — has anything been written yet?');

        try {
            $this->load->library('firestore_service', null, 'fsv');
            $this->fsv->init('BOOTSTRAP', '2026-27', 'BOOTSTRAP');
            $cfgs = $this->fsv->where('statutoryConfig', []);
            $comps = $this->fsv->where('salaryComponents', []);
        } catch (\Throwable $e) {
            $this->skipped('D live read', 'Firestore unreachable: ' . $e->getMessage());
            return;
        }

        // W2.2 writes NO tenant configuration — the discovery found 7 of 8
        // tenants with no statutory data at all, so seeding them would be
        // inventing policy. This asserts that restraint held.
        $this->ok('D1 no statutoryConfig document has been written',
            count($cfgs) === 0, count($cfgs) . ' doc(s)');
        $this->ok('D2 no salaryComponents seeded yet (operator-triggered)',
            count($comps) === 0, count($comps) . ' doc(s)');
    }
}
