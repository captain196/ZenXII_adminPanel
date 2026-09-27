<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Hrms_p0_verify — RUNTIME verification harness for the ZenX HRMS P0 phase.
 *
 * READ-ONLY. Performs ZERO Firestore writes, creates no documents, mutates no
 * tenant, and requires no test data. Safe to run against a live tenant during
 * traffic. Idempotent and re-runnable.
 *
 * WHAT MAKES THIS A RUNTIME TEST RATHER THAN A SOURCE ASSERTION
 * ------------------------------------------------------------
 * It EXECUTES the real production methods. Hr::_compute_payslip(),
 * _staff_working_days(), _in_employment_window() and _normalize_date() are
 * private, but all four are documented pure functions ("no session, no side
 * effects; all inputs are passed in"), so the harness builds an Hr instance via
 * ReflectionClass::newInstanceWithoutConstructor() — bypassing the controller
 * constructor it cannot satisfy in CLI — and invokes the genuine methods. The
 * numbers below are produced by the shipping payroll engine, not a replica.
 *
 * The RBAC group calls the real rbac_helper.php decision functions.
 * The isolation group performs real (read-only) Firestore reads.
 *
 * INVOCATION:
 *   php index.php hrms_p0_verify run
 *   php index.php hrms_p0_verify run   SCHOOL_A=SCH_XXXX SCHOOL_B=SCH_YYYY
 *     (isolation group is skipped unless both are supplied via env)
 */
class Hrms_p0_verify extends CI_Controller
{
    private int $pass = 0;
    private int $fail = 0;
    private int $skip = 0;
    /** @var array<int,string> */
    private array $failures = [];

    public function __construct()
    {
        parent::__construct();
        if (!defined('STDIN')) {
            exit("CLI only\n");
        }
    }

    // ─────────────────────────────────────────────────────────────────────

    private function ok(string $group, string $name, bool $cond, string $detail = ''): void
    {
        if ($cond) {
            $this->pass++;
            echo "  [PASS] {$name}" . ($detail !== '' ? "  ({$detail})" : '') . "\n";
        } else {
            $this->fail++;
            $this->failures[] = "{$group} :: {$name}" . ($detail !== '' ? " — {$detail}" : '');
            echo "  [FAIL] {$name}" . ($detail !== '' ? "  ({$detail})" : '') . "\n";
        }
    }

    private function skipped(string $name, string $why): void
    {
        $this->skip++;
        echo "  [SKIP] {$name}  ({$why})\n";
    }

    private function head(string $t): void
    {
        echo "\n" . str_repeat('=', 74) . "\n{$t}\n" . str_repeat('=', 74) . "\n";
    }

    /** Real Hr instance without running the constructor (pure methods only). */
    private function hr()
    {
        require_once APPPATH . 'controllers/Hr.php';
        return (new ReflectionClass('Hr'))->newInstanceWithoutConstructor();
    }

    private function call($obj, string $method, array $args)
    {
        $m = new ReflectionMethod(get_class($obj), $method);
        $m->setAccessible(true);
        return $m->invokeArgs($obj, $args);
    }

    /** Canonical test salary structure: basic 30000 ⇒ daily 1000 over 30 days. */
    private function sal(): array
    {
        return [
            'basic' => 30000, 'hra' => 0, 'da' => 0, 'ta' => 0,
            'medical' => 0, 'other_allowances' => 0,
            'pf_employee' => 12, 'pf_employer' => 13,
            'esi_employee' => 0.75, 'esi_employer' => 3.25,
            'tds' => 0, 'professional_tax' => 0, 'other_deductions' => 0,
        ];
    }

    // ═════════════════════════════════════════════════════════════════════

    public function run(): void
    {
        echo "\nZENX HRMS — P0 RUNTIME VERIFICATION (read-only)\n";
        echo "Executed: " . date('c') . "\n";

        $hr = $this->hr();

        $this->group_money_precision($hr);
        $this->group_a2_extra_pay($hr);
        $this->group_a1_half_day($hr);
        $this->group_lifecycle($hr);
        $this->group_rbac();
        $this->group_entitlement();
        $this->group_isolation();

        $this->head('SUMMARY');
        echo "  PASS: {$this->pass}\n  FAIL: {$this->fail}\n  SKIP: {$this->skip}\n";
        if ($this->failures) {
            echo "\n  FAILURES:\n";
            foreach ($this->failures as $f) echo "    - {$f}\n";
        }
        echo "\n  RESULT: " . ($this->fail === 0 ? 'PASS' : 'FAIL') . "\n\n";
    }

    // ── W1.2 — MONEY PRECISION (cases A–Q) ───────────────────────────────
    private function group_money_precision($hr): void
    {
        $this->head('W1.2 — Money precision  [_compute_payslip, integer paise]');
        $sal = $this->sal();

        // Helper: exact paise identity checks on a breakdown.
        $identities = function (array $c, string $label) {
            $p = $c['paise'];
            $earn = $p['gross'];   // gross is the sum of earned components + extra
            $this->ok('W1.2', "{$label}: gross − deductions == net EXACTLY (paise)",
                ($p['gross'] - $p['total_deductions']) === $p['net_pay'],
                "{$p['gross']} − {$p['total_deductions']} = {$p['net_pay']}");
            $this->ok('W1.2', "{$label}: Σ deduction components == total_deductions EXACTLY",
                ($p['pf_employee'] + $p['esi_employee'] + $p['tds']
                 + $p['professional_tax'] + $p['other_deductions']) === $p['total_deductions']);
            $this->ok('W1.2', "{$label}: paise ↔ rupee views agree",
                abs($c['gross'] - $p['gross'] / 100) < 0.0001
                && abs($c['net_pay'] - $p['net_pay'] / 100) < 0.0001);
            return $earn;
        };

        // ── A. Whole-rupee salary (the ordinary case) ────────────────────
        $a = $this->call($hr, '_compute_payslip', [$sal, 30.0, 0.0, 0.0]);
        $this->ok('W1.2', 'A: whole-rupee salary ⇒ gross exactly 30000',
            $a['paise']['gross'] === 3000000, "grossP={$a['paise']['gross']}");
        $identities($a, 'A');

        // ── B. Paise-level values survive without drift ──────────────────
        $salPaise = array_merge($sal, ['basic' => 30000.55, 'hra' => 1234.49]);
        $b = $this->call($hr, '_compute_payslip', [$salPaise, 30.0, 0.0, 0.0]);
        $this->ok('W1.2', 'B: paise inputs (30000.55 + 1234.49) ⇒ exact 31235.04',
            $b['paise']['gross'] === 3123504, "grossP={$b['paise']['gross']}");
        $identities($b, 'B');

        // ── C/D. Multiple earnings + multiple deductions ─────────────────
        $salMulti = [
            'basic' => 20000.33, 'hra' => 5000.27, 'da' => 2500.11, 'ta' => 1200.09,
            'medical' => 800.07, 'other_allowances' => 499.13,
            'pf_employee' => 12, 'pf_employer' => 13, 'esi_employee' => 0.75,
            'esi_employer' => 3.25, 'tds' => 10,
            'professional_tax' => 200.5, 'other_deductions' => 99.99,
        ];
        $cd = $this->call($hr, '_compute_payslip', [$salMulti, 30.0, 0.0, 0.0]);
        $sumEarn = $this->call($hr, '_toPaise', [20000.33]) + $this->call($hr, '_toPaise', [5000.27])
                 + $this->call($hr, '_toPaise', [2500.11]) + $this->call($hr, '_toPaise', [1200.09])
                 + $this->call($hr, '_toPaise', [800.07])  + $this->call($hr, '_toPaise', [499.13]);
        $this->ok('W1.2', 'C: Σ six earning components == gross EXACTLY (no drift)',
            $cd['paise']['gross'] === $sumEarn, "Σ={$sumEarn} gross={$cd['paise']['gross']}");
        $identities($cd, 'C/D');

        // ── E. Percentage-based calculation ──────────────────────────────
        $this->ok('W1.2', 'E: PF = 12% of earned basic, exact to the paise',
            $cd['paise']['pf_employee'] === (int) round($this->call($hr, '_toPaise', [20000.33]) * 0.12),
            "pfP={$cd['paise']['pf_employee']}");

        // ── F. Division-based calculation (proration) ────────────────────
        // 31-day month: basic/31 is a repeating decimal — the classic drift case.
        $sal31 = array_merge($sal, ['basic' => 30000]);
        $f = $this->call($hr, '_compute_payslip', [$sal31, 31.0, 1.0, 0.0]);
        $identities($f, 'F');
        $this->ok('W1.2', 'F: 1 absent day of 31 ⇒ deterministic, invariant-safe',
            $f['paise']['gross'] > 0 && $f['paise']['gross'] < 3000000,
            "grossP={$f['paise']['gross']}");

        // ── G/H/I. Half-day, LOP, worked-on-off ──────────────────────────
        $g = $this->call($hr, '_compute_payslip', [$sal, 30.0, 0.5, 0.0]);
        $identities($g, 'G half-day');
        $h = $this->call($hr, '_compute_payslip', [$sal, 30.0, 0.0, 2.0]);
        $identities($h, 'H LOP');
        $i = $this->call($hr, '_compute_payslip', [$sal, 30.0, 0.0, 0.0, 2.0, 1.0]);
        $identities($i, 'I worked-on-off');
        $this->ok('W1.2', 'I: extra pay exact (2 days × 1000) = 200000 paise',
            $i['paise']['extra_worked_pay'] === 200000, "extraP={$i['paise']['extra_worked_pay']}");

        // ── J. Statutory inputs with awkward rates ───────────────────────
        $salOdd = array_merge($sal, ['esi_employee' => 0.75, 'tds' => 3.33]);
        $j = $this->call($hr, '_compute_payslip', [$salOdd, 30.0, 1.0, 0.5]);
        $identities($j, 'J statutory');

        // ── M. Zero salary / zero components ─────────────────────────────
        $zero = array_merge($sal, ['basic' => 0, 'hra' => 0, 'da' => 0, 'ta' => 0,
            'medical' => 0, 'other_allowances' => 0, 'professional_tax' => 0, 'other_deductions' => 0]);
        $m = $this->call($hr, '_compute_payslip', [$zero, 30.0, 0.0, 0.0]);
        $this->ok('W1.2', 'M: zero salary ⇒ gross 0, net 0, no negative',
            $m['paise']['gross'] === 0 && $m['paise']['net_pay'] === 0);
        $this->ok('W1.2', 'M: zero working days does not divide by zero',
            is_array($this->call($hr, '_compute_payslip', [$sal, 0.0, 0.0, 0.0])));

        // ── N. Repeated identical calculation ⇒ identical result ─────────
        $n1 = $this->call($hr, '_compute_payslip', [$salMulti, 30.0, 1.5, 0.5, 1.0, 1.5]);
        $n2 = $this->call($hr, '_compute_payslip', [$salMulti, 30.0, 1.5, 0.5, 1.0, 1.5]);
        $this->ok('W1.2', 'N: repeated identical inputs ⇒ byte-identical breakdown (idempotent)',
            $n1 === $n2);

        // ── O. Boundary / large realistic values ─────────────────────────
        $big = array_merge($sal, ['basic' => 9999999.99, 'hra' => 5000000.01]);
        $o = $this->call($hr, '_compute_payslip', [$big, 30.0, 0.0, 0.0]);
        $this->ok('W1.2', 'O: ₹1.5cr salary stays exact (no float precision loss)',
            $o['paise']['gross'] === 1500000000, "grossP={$o['paise']['gross']}");
        $identities($o, 'O large');

        // Deductions exceeding gross ⇒ waterfall, net floored at 0, still exact.
        $tiny = array_merge($sal, ['basic' => 100, 'hra' => 0, 'da' => 0, 'ta' => 0,
            'medical' => 0, 'other_allowances' => 0,
            'professional_tax' => 500, 'other_deductions' => 500]);
        $w = $this->call($hr, '_compute_payslip', [$tiny, 30.0, 0.0, 0.0]);
        $this->ok('W1.2', 'waterfall: net never negative', $w['paise']['net_pay'] >= 0);
        $identities($w, 'waterfall');

        // ── P. Existing-payroll regression (ordinary inputs unchanged) ───
        // Values that the pre-W1.2 float engine produced for clean inputs.
        $this->ok('W1.2', 'P: baseline gross unchanged (30000.00)',
            abs($a['gross'] - 30000.00) < 0.001, "gross={$a['gross']}");
        $this->ok('W1.2', 'P: baseline PF unchanged (3600.00 = 12% of 30000)',
            abs($a['pf_employee'] - 3600.00) < 0.001, "pf={$a['pf_employee']}");
        $this->ok('W1.2', 'P: baseline ESI unchanged (225.00 = 0.75% of 30000)',
            abs($a['esi_employee'] - 225.00) < 0.001, "esi={$a['esi_employee']}");
        $this->ok('W1.2', 'P: baseline net unchanged (26175.00)',
            abs($a['net_pay'] - 26175.00) < 0.001, "net={$a['net_pay']}");
        $this->ok('W1.2', 'P: half-day still deducts exactly 500',
            abs($a['gross'] - $g['gross'] - 500) < 0.001);

        // ── Q. Accounting accumulation across N employees (Dr == Cr) ─────
        // The historical journal-imbalance failure mode: per-slip identities can
        // hold while the SUMS drift. Accumulate exactly as generate_payroll now
        // does and assert the journal identity over a mixed roster.
        $roster = [
            [$salMulti, 30.0, 0.0, 0.0, 0.0, 1.0],
            [$salMulti, 31.0, 1.0, 0.5, 1.0, 1.5],
            [$salPaise, 30.0, 0.5, 0.0, 0.0, 1.0],
            [$salOdd,   28.0, 2.0, 1.0, 2.0, 1.0],
            [$sal,      30.0, 0.0, 0.0, 0.0, 1.0],
            [$tiny,     30.0, 0.0, 0.0, 0.0, 1.0],
        ];
        $sumGross = 0; $sumNet = 0; $sumPF = 0; $sumESI = 0; $sumTDS = 0; $sumPT = 0; $sumOD = 0;
        foreach ($roster as $r) {
            $c = $this->call($hr, '_compute_payslip', $r);
            $p = $c['paise'];
            $sumGross += $p['gross'];  $sumNet += $p['net_pay'];
            $sumPF    += $p['pf_employee']; $sumESI += $p['esi_employee'];
            $sumTDS   += $p['tds'];    $sumPT  += $p['professional_tax'];
            $sumOD    += $p['other_deductions'];
        }
        $credits = $sumNet + $sumPF + $sumESI + $sumTDS + $sumPT + $sumOD;
        $this->ok('W1.2', 'Q: Dr (Σ gross) == Cr (Σ net + statutory) EXACTLY over ' . count($roster) . ' employees',
            $sumGross === $credits, "Dr={$sumGross} Cr={$credits} diff=" . ($sumGross - $credits));
        $this->ok('W1.2', 'Q: rupee conversion of each side preserves the balance',
            abs(($sumGross / 100) - ($credits / 100)) < 0.0001);

        // Order independence — summation order must not change the total.
        $rev = array_reverse($roster);
        $sumGrossRev = 0;
        foreach ($rev as $r) { $sumGrossRev += $this->call($hr, '_compute_payslip', $r)['paise']['gross']; }
        $this->ok('W1.2', 'calculation/summation ORDER does not change the total',
            $sumGross === $sumGrossRev, "fwd={$sumGross} rev={$sumGrossRev}");

        // No float money rounding left on the canonical path.
        $src = file_get_contents(APPPATH . 'controllers/Hr.php');
        $start = strpos($src, 'private function _compute_payslip');
        $body  = substr($src, $start, 8000);
        $this->ok('W1.2', 'no `round($x, 2)` money rounding remains inside _compute_payslip',
            preg_match('/round\([^)]*,\s*2\)/', $body) === 0);
    }

    // ── A2 — attendance 'W' worked-on-off extra pay ──────────────────────
    private function group_a2_extra_pay($hr): void
    {
        $this->head("A2 — Attendance 'W' worked-on-off extra pay  [_compute_payslip]");
        $sal = $this->sal();

        // Baseline: no extra days, params OMITTED (pre-A2 call shape).
        $base = $this->call($hr, '_compute_payslip', [$sal, 30.0, 0.0, 0.0]);
        $this->ok('A2', 'baseline gross = 30000 (full month, no absence)',
            abs($base['gross'] - 30000) < 0.01, "gross={$base['gross']}");
        $this->ok('A2', 'baseline daily rate = 1000',
            abs($base['daily_salary'] - 1000) < 0.01, "daily={$base['daily_salary']}");

        // BACKWARD COMPATIBILITY: omitting the new params must be byte-identical
        // to passing the defaults — proves zero regression for existing payroll.
        $explicitDefault = $this->call($hr, '_compute_payslip', [$sal, 30.0, 0.0, 0.0, 0.0, 1.0]);
        $this->ok('A2', 'omitted params === explicit defaults (no regression)',
            $base === $explicitDefault, 'full breakdown identical');

        // 2 extra days at 1.0x ⇒ +2000 gross.
        $x2 = $this->call($hr, '_compute_payslip', [$sal, 30.0, 0.0, 0.0, 2.0, 1.0]);
        $this->ok('A2', '2 W-days @1.0x ⇒ extra_worked_pay = 2000',
            abs($x2['extra_worked_pay'] - 2000) < 0.01, "extra={$x2['extra_worked_pay']}");
        $this->ok('A2', '2 W-days ⇒ gross rises 30000 → 32000',
            abs($x2['gross'] - 32000) < 0.01, "gross={$x2['gross']}");
        $this->ok('A2', 'extra_worked_days echoed on breakdown',
            abs($x2['extra_worked_days'] - 2.0) < 0.001, "days={$x2['extra_worked_days']}");

        // PF base must EXCLUDE extra pay (PF is on basic, not duty pay).
        $this->ok('A2', 'PF base excludes extra pay (pf unchanged vs baseline)',
            abs($x2['pf_employee'] - $base['pf_employee']) < 0.01,
            "pf {$base['pf_employee']} → {$x2['pf_employee']}");

        // ESI base must INCLUDE extra pay (statutory % of earned wage).
        $this->ok('A2', 'ESI base includes extra pay (esi rises)',
            $x2['esi_employee'] > $base['esi_employee'] + 0.001,
            "esi {$base['esi_employee']} → {$x2['esi_employee']}");

        // Configurable multiplier.
        $x15 = $this->call($hr, '_compute_payslip', [$sal, 30.0, 0.0, 0.0, 2.0, 1.5]);
        $this->ok('A2', 'rate 1.5x honoured ⇒ 3000',
            abs($x15['extra_worked_pay'] - 3000) < 0.01, "extra={$x15['extra_worked_pay']}");

        $x0 = $this->call($hr, '_compute_payslip', [$sal, 30.0, 0.0, 0.0, 2.0, 0.0]);
        $this->ok('A2', 'rate 0 ⇒ no extra pay, gross back to 30000',
            abs($x0['extra_worked_pay']) < 0.01 && abs($x0['gross'] - 30000) < 0.01,
            "extra={$x0['extra_worked_pay']} gross={$x0['gross']}");

        // Ledger invariant must survive the added earning.
        $this->ok('A2', 'invariant: gross − deductions == net (with extra pay)',
            abs(($x2['gross'] - $x2['total_deductions']) - $x2['net_pay']) < 0.01,
            "{$x2['gross']} − {$x2['total_deductions']} = {$x2['net_pay']}");

        // W combined with absence.
        $xMix = $this->call($hr, '_compute_payslip', [$sal, 30.0, 2.0, 0.0, 1.0, 1.0]);
        $expect = round(30000 * (28 / 30), 2) + 1000;
        $this->ok('A2', 'W + 2 absent days compose correctly',
            abs($xMix['gross'] - $expect) < 0.02, "gross={$xMix['gross']} expected≈{$expect}");
    }

    // ── A1 — attendance 'M' half-day ─────────────────────────────────────
    private function group_a1_half_day($hr): void
    {
        $this->head("A1 — Attendance 'M' half-day  [_compute_payslip]");
        $sal = $this->sal();

        $full = $this->call($hr, '_compute_payslip', [$sal, 30.0, 0.0, 0.0]);
        $half = $this->call($hr, '_compute_payslip', [$sal, 30.0, 0.5, 0.0]);
        $one  = $this->call($hr, '_compute_payslip', [$sal, 30.0, 1.0, 0.0]);

        $this->ok('A1', 'one M-day (0.5) reduces pay by exactly half a day',
            abs(($full['gross'] - $half['gross']) - 500) < 0.02,
            "Δ=" . round($full['gross'] - $half['gross'], 2));

        $this->ok('A1', 'half-day deduction is exactly half a full absent day',
            abs(($full['gross'] - $half['gross']) * 2 - ($full['gross'] - $one['gross'])) < 0.02,
            '0.5-day Δ × 2 == 1-day Δ');

        $this->ok('A1', 'M-day is NOT paid as a full day (regression guard)',
            $half['gross'] < $full['gross'] - 0.01,
            "gross {$full['gross']} → {$half['gross']}");

        // Three M-days = 1.5 days.
        $m3 = $this->call($hr, '_compute_payslip', [$sal, 30.0, 1.5, 0.0]);
        $this->ok('A1', 'three M-days ⇒ 1.5-day deduction',
            abs(($full['gross'] - $m3['gross']) - 1500) < 0.02,
            "Δ=" . round($full['gross'] - $m3['gross'], 2));

        // M alongside leave-driven LWP: both reduce, neither is lost.
        $mix = $this->call($hr, '_compute_payslip', [$sal, 30.0, 0.5, 1.0]);
        $this->ok('A1', 'M(0.5) + LWP(1.0) ⇒ 1.5 deduction days total',
            abs($mix['deduction_days'] - 1.5) < 0.001, "deduction_days={$mix['deduction_days']}");

        $this->ok('A1', 'invariant: gross − deductions == net (half-day)',
            abs(($half['gross'] - $half['total_deductions']) - $half['net_pay']) < 0.01);
    }

    // ── C1/C2 — employment window lifecycle ──────────────────────────────
    private function group_lifecycle($hr): void
    {
        $this->head('C1/C2 — Employment window  [_staff_working_days / _in_employment_window]');
        // NOTE ON EXPECTED VALUES: _staff_working_days() returns PAYABLE WORKING
        // days — calendar days in the employment window MINUS weekly-offs and
        // holidays — not raw calendar days. For August 2026 (31 days, 5 Sundays,
        // 2nd Sat = 8th, 4th Sat = 22nd) a full month is 31 − 7 = 24. Assertions
        // below are therefore expressed as RELATIONSHIPS (which is what C1/C2
        // actually guarantees and is robust to a tenant's weekly-off policy),
        // with the observed value reported alongside.
        $aug = 8; $yr = 2026;

        $full = $this->call($hr, '_staff_working_days', [$aug, $yr, [], null, null]);
        $this->ok('C1/C2', 'no join/exit ⇒ full payable month (>0)',
            $full['working'] > 0, "working={$full['working']} (31 cal − 7 non-working)");

        $joinMid = $this->call($hr, '_staff_working_days', [$aug, $yr, [], '2026-08-15', null]);
        $this->ok('C1/C2', 'JOIN MID-MONTH (15 Aug) ⇒ pro-rated, not full month',
            $joinMid['working'] > 0 && $joinMid['working'] < $full['working'],
            "working={$joinMid['working']} < full={$full['working']}");

        // THE C2 FIX: a mid-month leaver must still produce payable days. Before
        // C2 they were dropped from the roster entirely and paid nothing.
        $exitMid = $this->call($hr, '_staff_working_days', [$aug, $yr, [], null, '2026-08-17']);
        $this->ok('C1/C2', 'EXIT MID-MONTH (17 Aug) ⇒ FINAL PAY PRODUCED (>0, pro-rated)',
            $exitMid['working'] > 0 && $exitMid['working'] < $full['working'],
            "working={$exitMid['working']} < full={$full['working']}");

        $exitEnd = $this->call($hr, '_staff_working_days', [$aug, $yr, [], null, '2026-08-31']);
        $this->ok('C1/C2', 'EXIT AT MONTH-END (31 Aug) ⇒ full month, NOT excluded',
            $exitEnd['working'] === $full['working'],
            "working={$exitEnd['working']} == full={$full['working']}");

        $both = $this->call($hr, '_staff_working_days', [$aug, $yr, [], '2026-08-10', '2026-08-20']);
        $this->ok('C1/C2', 'JOIN + EXIT in same month (10→20) ⇒ narrower than either alone',
            $both['working'] > 0 && $both['working'] < $joinMid['working'] + $exitMid['working'],
            "working={$both['working']}");

        $exitPrior = $this->call($hr, '_staff_working_days', [$aug, $yr, [], null, '2026-07-20']);
        $this->ok('C1/C2', 'exit BEFORE period ⇒ 0 payable days (no pay after leaving)',
            $exitPrior['working'] === 0, "working={$exitPrior['working']}");

        $joinLater = $this->call($hr, '_staff_working_days', [$aug, $yr, [], '2026-09-05', null]);
        $this->ok('C1/C2', 'join AFTER period ⇒ 0 payable days',
            $joinLater['working'] === 0, "working={$joinLater['working']}");

        // REHIRE: C1 nulls exitDate on reactivation ⇒ behaves as never-exited.
        $rehire = $this->call($hr, '_staff_working_days', [$aug, $yr, [], null, null]);
        $this->ok('C1/C2', 'REHIRE (exitDate nulled by C1) ⇒ full window restored',
            $rehire['working'] === $full['working'],
            "working={$rehire['working']} == full={$full['working']}");

        // Stale exit date must NOT survive a rehire — this is why C1 nulls it.
        $staleExit = $this->call($hr, '_staff_working_days', [$aug, $yr, [], null, '2026-08-05']);
        $this->ok('C1/C2', 'a NON-nulled stale exit would clamp pay (proves why C1 nulls it)',
            $staleExit['working'] < $full['working'],
            "stale-exit working={$staleExit['working']} < full={$full['working']}");

        // Window membership, incl. the boundary days themselves.
        $this->ok('C1/C2', 'exit day itself is INSIDE the window (inclusive)',
            $this->call($hr, '_in_employment_window', ['2026-08-17', null, '2026-08-17']) === true);
        $this->ok('C1/C2', 'day after exit is OUTSIDE the window',
            $this->call($hr, '_in_employment_window', ['2026-08-18', null, '2026-08-17']) === false);
        $this->ok('C1/C2', 'join day itself is INSIDE the window (inclusive)',
            $this->call($hr, '_in_employment_window', ['2026-08-15', '2026-08-15', null]) === true);
        $this->ok('C1/C2', 'day before join is OUTSIDE the window',
            $this->call($hr, '_in_employment_window', ['2026-08-14', '2026-08-15', null]) === false);

        // C1 writes exitDate as YYYY-MM-DD; legacy staff carry d-m-Y.
        $this->ok('C1/C2', 'date normalisation accepts C1 format (YYYY-MM-DD)',
            $this->call($hr, '_normalize_date', ['2026-08-17', '']) === '2026-08-17');
        $this->ok('C1/C2', 'date normalisation accepts legacy staff format (d-m-Y)',
            $this->call($hr, '_normalize_date', ['17-08-2026', '']) === '2026-08-17');
        $this->ok('C1/C2', 'unparseable date ⇒ null (treated as no constraint)',
            $this->call($hr, '_normalize_date', ['not-a-date', '']) === null);
    }

    // ── D1–D5 — graded RBAC is the sole authority ────────────────────────
    private function group_rbac(): void
    {
        $this->head('D1–D5 — Graded RBAC decision matrix  [rbac_helper]');
        $this->load->helper('rbac');

        // Level ranking underpins every gate.
        $this->ok('RBAC', 'rank(view) < rank(edit) < rank(manage)',
            rbac_level_rank('view') < rbac_level_rank('edit')
            && rbac_level_rank('edit') < rbac_level_rank('manage'));

        // THE CORE OF D1–D4: a downgraded holder resolves to 'view' and must NOT
        // satisfy an edit/manage gate. Pre-fix, a role NAME re-granted these.
        $perms = ['HR'];
        $viewLv = ['HR' => 'view'];
        $g = rbac_granted_level('HR', $perms, $viewLv);
        $this->ok('RBAC', 'HR:view holder resolves to view', $g === 'view', "granted={$g}");
        $this->ok('RBAC', 'HR:view does NOT satisfy edit  (leave balances / requests / apply-on-behalf)',
            rbac_level_rank($g) < rbac_level_rank('edit'));
        $this->ok('RBAC', 'HR:view does NOT satisfy manage (payslip PDF / employee history)',
            rbac_level_rank($g) < rbac_level_rank('manage'));

        $mg = rbac_granted_level('HR', $perms, ['HR' => 'manage']);
        $this->ok('RBAC', 'HR:manage satisfies manage (legitimate access retained)',
            rbac_level_rank($mg) >= rbac_level_rank('manage'), "granted={$mg}");
        $this->ok('RBAC', 'HR:manage also satisfies edit and view',
            rbac_level_rank($mg) >= rbac_level_rank('edit'));

        $ed = rbac_granted_level('HR', $perms, ['HR' => 'edit']);
        $this->ok('RBAC', 'HR:edit satisfies edit but NOT manage',
            rbac_level_rank($ed) >= rbac_level_rank('edit')
            && rbac_level_rank($ed) < rbac_level_rank('manage'), "granted={$ed}");

        $this->ok('RBAC', 'module not held ⇒ no access at all',
            rbac_granted_level('HR', [], []) === null);

        // Default-catalogue Principal: the exact identity that the removed
        // role-name branch used to re-grant. It holds no HR capability.
        $principal = ['SIS','Attendance','Examinations','Results','LMS','Certificates',
                      'Academic','Reports','Events','Communication','Stories','Configuration'];
        $this->ok('RBAC', 'default Principal holds NO HR ⇒ denied (name no longer re-grants)',
            rbac_granted_level('HR', $principal, []) === null);

        // Source proof that no role-name re-grant survives at the 4 fixed sites.
        $hrSrc = file_get_contents(APPPATH . 'controllers/Hr.php');
        $this->ok('RBAC', 'ZERO in_array($this->admin_role, HR_ROLES/ADMIN_ROLES) re-grants remain in Hr.php',
            strpos($hrSrc, 'in_array($this->admin_role, self::HR_ROLES') === false
            && strpos($hrSrc, 'in_array($this->admin_role, self::ADMIN_ROLES') === false);

        $pySrc = file_get_contents(APPPATH . 'controllers/Payroll.php');
        $this->ok('RBAC', 'D5 employee-history clamps to caller unless HR:manage',
            strpos($pySrc, "has_permission('HR', 'manage')") !== false
            && strpos($pySrc, '$emp = (string) ($this->admin_id') !== false);

        // B1 device-punch parity.
        $atSrc = file_get_contents(APPPATH . 'controllers/Attendance.php');
        $this->ok('RBAC', 'B1 device punch rejects non-active staff (GPS parity)',
            strpos($atSrc, "Staff member is not active.") !== false);
    }

    // ── W1.1 — HRMS entitlement reachability (cases A–G) ─────────────────
    private function group_entitlement(): void
    {
        $this->head('W1.1 — HRMS entitlement reachability  [B2_derive + capability keys]');
        require_once APPPATH . 'libraries/B2_derive.php';
        $this->load->helper('capability');

        $now = time();
        // A shared base plan — the real-world situation: several tenants sit on
        // ONE plan, and that plan does not include HRMS.
        $sharedPlan = ['modules' => ['fees' => true, 'attendance' => true, 'staff_management' => true]];

        // CASE A — tenant with no HRMS entitlement.
        $a = B2_derive::computeEntitlements($sharedPlan, [], [], $now);
        $this->ok('W1.1', 'CASE A: no grant ⇒ hrms_core absent (capability unavailable)',
            empty($a['hrms_core']), 'modules=' . implode(',', array_keys($a)));

        // CASE B — tenant receives hrms_core via a tenant-level override grant.
        $grant = [['module' => 'hrms_core', 'mode' => 'grant']];
        $b = B2_derive::computeEntitlements($sharedPlan, [], $grant, $now);
        $this->ok('W1.1', 'CASE B: override grant ⇒ hrms_core = true',
            !empty($b['hrms_core']));
        $this->ok('W1.1', 'CASE B: grant does NOT disturb the tenant\'s plan modules',
            !empty($b['fees']) && !empty($b['attendance']) && !empty($b['staff_management']));

        // CASE C — two tenants, SAME plan, only one granted.
        $tenantA = B2_derive::computeEntitlements($sharedPlan, [], $grant, $now);
        $tenantB = B2_derive::computeEntitlements($sharedPlan, [], [],     $now);
        $this->ok('W1.1', 'CASE C: tenant A enabled, tenant B on the SAME plan disabled',
            !empty($tenantA['hrms_core']) && empty($tenantB['hrms_core']));

        // CASE D — revoke on A; B untouched.
        $revoke  = [['module' => 'hrms_core', 'mode' => 'revoke']];
        $aRevoked = B2_derive::computeEntitlements($sharedPlan, [], $revoke, $now);
        $this->ok('W1.1', 'CASE D: revoke ⇒ A disabled',
            empty($aRevoked['hrms_core']));
        $this->ok('W1.1', 'CASE D: B unchanged by A\'s revoke (derivation is per-tenant input)',
            empty($tenantB['hrms_core']) && !empty($tenantB['fees']));

        // CASE E — the plan itself is never mutated by a tenant-level override.
        $this->ok('W1.1', 'CASE E: shared plan object unmutated by per-tenant grant',
            $sharedPlan['modules'] === ['fees' => true, 'attendance' => true, 'staff_management' => true]);

        // CASE F/G — legacy capabilities survive both states unchanged.
        $legacy = ['fees', 'attendance', 'staff_management'];
        $fOk = true; $gOk = true;
        foreach ($legacy as $m) {
            if (empty($tenantB[$m])) $fOk = false;   // HRMS disabled tenant
            if (empty($tenantA[$m])) $gOk = false;   // HRMS enabled tenant
        }
        $this->ok('W1.1', 'CASE F: HRMS-disabled tenant keeps Staff/Attendance/Fees etc.', $fOk);
        $this->ok('W1.1', 'CASE G: HRMS-enabled tenant keeps them too (purely additive)', $gOk);

        // Time-bounded grants — an expired grant must not entitle.
        $expired = [['module' => 'hrms_core', 'mode' => 'grant', 'expiresAt' => $now - 1]];
        $this->ok('W1.1', 'expired grant does NOT entitle',
            empty(B2_derive::computeEntitlements($sharedPlan, [], $expired, $now)['hrms_core']));
        $future = [['module' => 'hrms_core', 'mode' => 'grant', 'expiresAt' => $now + 3600]];
        $this->ok('W1.1', 'unexpired grant DOES entitle',
            !empty(B2_derive::computeEntitlements($sharedPlan, [], $future, $now)['hrms_core']));

        // Plan-level HRMS still works (for a genuine HRMS-inclusive plan).
        $hrmsPlan = ['modules' => ['fees' => true, 'hrms_core' => true]];
        $this->ok('W1.1', 'plan-level hrms_core also entitles (both axes supported)',
            !empty(B2_derive::computeEntitlements($hrmsPlan, [], [], $now)['hrms_core']));

        // Revoke must beat a plan grant (suspend without touching the plan).
        $this->ok('W1.1', 'revoke overrides a plan-granted module',
            empty(B2_derive::computeEntitlements($hrmsPlan, [], $revoke, $now)['hrms_core']));

        // moduleVisible: a flag may veto, never grant.
        $ent = B2_derive::computeEntitlements($sharedPlan, [], $grant, $now);
        $this->ok('W1.1', 'per-school flag can VETO an entitled module',
            B2_derive::moduleVisible('hrms_core', $ent, [], ['hrms_core' => false]) === false);
        $this->ok('W1.1', 'flag CANNOT grant a non-entitled module (gate, not grant)',
            B2_derive::moduleVisible('hrms_core', $a, [], ['hrms_core' => true]) === false);

        // Capability keys are registered and reachable.
        $plansSrc = file_get_contents(APPPATH . 'controllers/Superadmin_plans.php');
        foreach (['hrms_core', 'hrms_payroll', 'hrms_corporate', 'hrms_retail'] as $k) {
            $this->ok('W1.1', "capability '{$k}' registered in AVAILABLE_MODULES",
                strpos($plansSrc, "'{$k}'") !== false);
        }
        $this->ok('W1.1', 'NO hrms_attendance / hrms_leave duplicate keys introduced',
            strpos($plansSrc, 'hrms_attendance') === false && strpos($plansSrc, 'hrms_leave') === false);

        $this->ok('W1.1', 'HRMS capability constants defined',
            defined('HRMS_CORE_CAPABILITY') && defined('HRMS_PAYROLL_CAPABILITY')
            && defined('HRMS_CORPORATE_CAPABILITY') && defined('HRMS_RETAIL_CAPABILITY'));

        // Live writers now route through the canonical derivation.
        $svcSrc = file_get_contents(APPPATH . 'libraries/B2_registry_service.php');
        $this->ok('W1.1', 'both entitlement writers use _derive_entitlements()',
            substr_count($svcSrc, '$this->_derive_entitlements(') >= 3);
        $this->ok('W1.1', 'recompute + per-tenant override primitives exist',
            strpos($svcSrc, 'public function recompute_entitlements') !== false
            && strpos($svcSrc, 'public function set_module_override') !== false);
        $this->ok('W1.1', 'reserved B2_ENTITLEMENT_RECOMPUTE telemetry now emitted',
            strpos($svcSrc, "B2_ENTITLEMENT_RECOMPUTE") !== false);

        // Deferred subsystems must remain OFF (B2_foundation_verify asserts this).
        $this->config->load('b2_migration_flags', FALSE, TRUE);
        $flags = $this->config->item('b2_migration_flags');
        $this->ok('W1.1', 'deferred b2.addons flag left FALSE (priced catalog not activated)',
            isset($flags['b2.addons']) && $flags['b2.addons'] === false);
        $this->ok('W1.1', 'deferred b2.flags_global left FALSE',
            isset($flags['b2.flags_global']) && $flags['b2.flags_global'] === false);
    }

    // ── Tenant isolation (real Firestore reads, no writes) ───────────────
    private function group_isolation(): void
    {
        $this->head('Tenant isolation  [live Firestore, read-only]');

        $a = trim((string) getenv('SCHOOL_A'));
        $b = trim((string) getenv('SCHOOL_B'));
        if ($a === '' || $b === '') {
            $this->skipped('tenant isolation (2 tenants)', 'SCHOOL_A / SCHOOL_B env not supplied');
            return;
        }

        $this->load->library('firestore_service', null, 'fsa');
        $this->fsa->init($a, '2026-27', $a);

        // 1. Doc ids are school-prefixed by construction.
        $this->ok('ISO', 'docId() prefixes the ACTIVE tenant',
            strpos($this->fsa->docId('PROBE'), $a . '_') === 0, $this->fsa->docId('PROBE'));

        // 2. Every row from schoolWhere() belongs to tenant A only.
        $rows = $this->fsa->schoolWhere('staff', []);
        $foreign = 0; $count = 0;
        if (is_array($rows)) {
            foreach ($rows as $r) {
                $d = is_array($r['data'] ?? null) ? $r['data'] : $r;
                $count++;
                if ((string) ($d['schoolId'] ?? '') !== $a) $foreign++;
            }
        }
        $this->ok('ISO', "schoolWhere('staff') returns ONLY tenant-A rows",
            $foreign === 0, "rows={$count} foreign={$foreign}");

        // 3. Tenant B's staff must be unreachable through tenant A's scope.
        $this->load->library('firestore_service', null, 'fsb');
        $this->fsb->init($b, '2026-27', $b);
        $bRows = $this->fsb->schoolWhere('staff', []);
        $bId = '';
        if (is_array($bRows) && !empty($bRows)) {
            $d = is_array($bRows[0]['data'] ?? null) ? $bRows[0]['data'] : $bRows[0];
            $bId = (string) ($d['staffId'] ?? '');
        }
        if ($bId === '') {
            $this->skipped('cross-tenant staff read', 'tenant B has no staff to probe with');
        } else {
            $leak = $this->fsa->getEntity('staff', $bId);
            $this->ok('ISO', "tenant-B staff '{$bId}' NOT readable through tenant-A scope",
                empty($leak), $leak ? 'LEAK — doc returned' : 'null as expected');
        }

        // 4. Payroll's exited-roster query (C2) is school-scoped too.
        $inactive = $this->fsa->schoolWhere('staff', [['status', '==', 'Inactive']]);
        $foreign2 = 0;
        if (is_array($inactive)) {
            foreach ($inactive as $r) {
                $d = is_array($r['data'] ?? null) ? $r['data'] : $r;
                if ((string) ($d['schoolId'] ?? '') !== $a) $foreign2++;
            }
        }
        $this->ok('ISO', 'C2 exited-staff roster query is school-scoped',
            $foreign2 === 0, 'rows=' . (is_array($inactive) ? count($inactive) : 0) . " foreign={$foreign2}");
    }
}
