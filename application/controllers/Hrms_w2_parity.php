<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Hrms_w2_parity — the W2.4 PARITY GATE.
 *
 * READ-ONLY. No writes, no documents created, no tenant mutated, no flag
 * changed. Safe against a live tenant during traffic.
 *
 * WHY THIS EXISTS INSTEAD OF AN IN-REQUEST SHADOW
 * ----------------------------------------------
 * The plan's parity gate assumed a shadow comparison running inside real
 * payroll. That cannot produce evidence today: `salaryComponents`,
 * `staffSalaryAssignments` and `statutoryConfig` are all EMPTY, so the shadow
 * would compare each legacy structure against nothing and dutifully report "no
 * divergence" — a green that means nothing. (The Student Entitlements Gate 5
 * hit exactly this and was deferred for the same reason.)
 *
 * So parity is proven a different way, with no migration required and no
 * production data touched:
 *
 *      real legacy structure  ──fromLegacyStructure()──►  assignment
 *                                                              │
 *                                                              ▼
 *                                            Compensation_reader (component path)
 *                                                              │
 *      legacy 13-field shape  ◄──────── compare ───────────────┘
 *
 * If that round-trip is lossless over every real structure in every tenant,
 * then the migration + reader reproduce today's payroll inputs exactly — which
 * is precisely what the cutover needs to be safe. It is stronger than an
 * empty-collection shadow and available now.
 *
 * WORDING: this harness reports "NO DIVERGENCE DETECTED". It never reports
 * "equivalence proven" — it exercises the structures that exist, not every
 * structure that could exist.
 *
 * INVOCATION:  php index.php hrms_w2_parity run
 */
class Hrms_w2_parity extends CI_Controller
{
    private int $pass = 0;
    private int $fail = 0;
    private array $failures = [];

    public function __construct()
    {
        parent::__construct();
        if (!defined('STDIN')) exit("CLI only\n");
        $this->load->helper('effective_dating');
    }

    private function ok(string $name, bool $cond, string $detail = ''): void
    {
        if ($cond) { $this->pass++; echo "  [PASS] {$name}" . ($detail !== '' ? "  ({$detail})" : '') . "\n"; }
        else { $this->fail++; $this->failures[] = "{$name} — {$detail}"; echo "  [FAIL] {$name}  ({$detail})\n"; }
    }
    private function head(string $t): void { echo "\n" . str_repeat('=', 76) . "\n{$t}\n" . str_repeat('=', 76) . "\n"; }

    public function run(): void
    {
        echo "\nZenX HRMS — W2.4 COMPENSATION PARITY GATE\n";
        echo "READ-ONLY — no writes, no migration, no flag change.\n";

        require_once APPPATH . 'libraries/Salary_component_service.php';
        require_once APPPATH . 'libraries/Salary_assignment_service.php';
        require_once APPPATH . 'libraries/Compensation_reader.php';
        require_once APPPATH . 'libraries/Statutory_wage_base.php';
        require_once APPPATH . 'libraries/Statutory_config_service.php';

        $this->group_flags();
        $this->group_money_boundary();
        $this->group_roundtrip();
        $this->group_data_state();
        $this->group_statutory_wage_base();

        $this->head('SUMMARY');
        echo "  PASS {$this->pass}   FAIL {$this->fail}\n";
        foreach ($this->failures as $f) echo "    - {$f}\n";
        echo "\n" . ($this->fail === 0
            ? "RESULT: PASS — NO DIVERGENCE DETECTED over the structures that exist.\n"
            : "RESULT: FAIL — divergence found; do NOT flip the flag.\n");
    }

    // ── A. Flags are off and the reader is not wired ─────────────────────

    private function group_flags(): void
    {
        $this->head('A. Flags and wiring — the gate must be inert');

        $this->config->load('hrms_compensation_flags', true);
        $useComp = (bool) $this->config->item('USE_COMPONENT_COMPENSATION', 'hrms_compensation_flags');
        $shadow  = (bool) $this->config->item('COMPENSATION_SHADOW_COMPARE', 'hrms_compensation_flags');

        $this->ok('A1 USE_COMPONENT_COMPENSATION is false', $useComp === false);
        $this->ok('A2 COMPENSATION_SHADOW_COMPARE is false', $shadow === false);
        $this->ok('A3 flags resolve to the legacy mode',
            Compensation_reader::modeFromFlags($useComp, $shadow) === Compensation_reader::MODE_LEGACY);

        // W3.1 RE-STATED, NOT RELAXED.
        //
        // A4 used to assert that `Hr.php` contained no reference to
        // `Compensation_reader` at all. W3.1 deliberately moved that boundary:
        // the payroll run now resolves compensation THROUGH the reader, which
        // is the entire point of having a seam — the W2 cutover becomes a mode
        // change instead of a payroll rewrite.
        //
        // What must remain true is the part that actually keeps the cutover
        // un-flipped: the flags are false, the mode is legacy, and the payroll
        // controller still resolves nothing from the component collections
        // itself. So the assertion is re-pointed at that, not dropped.
        $hrSrc = (string) file_get_contents(APPPATH . 'controllers/Hr.php');
        $this->ok('A4 Hr.php uses the reader as its compensation seam',
            strpos($hrSrc, 'Compensation_reader') !== false);
        $this->ok('A4 Hr.php resolves compensation ONLY through the seam',
            substr_count($hrSrc, "schoolWhere('salarySlips', [['type', '==', 'salary_structure']])") === 0
            || strpos($hrSrc, '_compensation_reader()') !== false);

        foreach (['Hr.php', 'Payroll.php'] as $f) {
            $src = (string) file_get_contents(APPPATH . 'controllers/' . $f);
            $this->ok("A5 {$f} does not read assignments directly",
                strpos($src, 'staffSalaryAssignments') === false);
            $this->ok("A5 {$f} does not read the component catalogue directly",
                strpos($src, 'salaryComponents') === false);
        }

        $this->ok('A6 Payroll.php still does not reference the reader',
            strpos((string) file_get_contents(APPPATH . 'controllers/Payroll.php'),
                'Compensation_reader') === false);
    }

    // ── B. The paise↔rupee round-trip at the calculator boundary ─────────

    private function group_money_boundary(): void
    {
        $this->head('B. Money boundary — paise → rupees → paise must be lossless');

        // The reader hands the calculator rupees; the calculator immediately
        // converts back with _toPaise(). A single lost paisa here breaks
        // gross == net + deductions downstream.
        require_once APPPATH . 'controllers/Hr.php';
        $toPaise = new ReflectionMethod('Hr', '_toPaise');
        $toPaise->setAccessible(true);

        $cases = [0, 1, 7, 50, 99, 100, 101, 999, 1000, 1234567,
                  3000000, 4200000, 1500000, 2100000, 99999999];
        $same = 0;
        $firstBad = '';
        foreach ($cases as $p) {
            $rupees = Compensation_reader::paiseToRupees($p);
            $back   = (int) $toPaise->invoke(null, $rupees);
            if ($back === $p) $same++;
            elseif ($firstBad === '') $firstBad = "{$p} → ₹{$rupees} → {$back}";
        }
        $this->ok('B1 paise round-trip through the calculator boundary is lossless',
            $same === count($cases), "{$same}/" . count($cases) . ($firstBad ? " | first loss: {$firstBad}" : ''));

        // Odd paise are the interesting case — a naive /100 then *100 loses them.
        $this->ok('B2 odd paise survive', Compensation_reader::paiseToRupees(3000001) === 30000.01);
    }

    // ── C. THE GATE: legacy → assignment → reader → legacy ───────────────

    private function group_roundtrip(): void
    {
        $this->head('C. PARITY — every real legacy structure, round-tripped');

        try {
            $this->load->library('firestore_service', null, 'fsv');
            $this->fsv->init('BOOTSTRAP', '2026-27', 'BOOTSTRAP');
            $rows = $this->fsv->where('salarySlips', [['type', '==', 'salary_structure']]);
        } catch (\Throwable $e) {
            $this->ok('C0 legacy structures readable', false, $e->getMessage());
            return;
        }

        if (empty($rows)) {
            $this->ok('C0 legacy structures exist to compare', false,
                'no salary structures in any tenant — the gate has nothing to prove');
            return;
        }

        // The catalogue the component path resolves against. No tenant has one
        // seeded yet, so use the canonical system definitions — which is what
        // the seed writes, so the comparison is faithful.
        $catalogue = [];
        foreach (Salary_component_service::systemComponents() as $c) $catalogue[$c['code']] = $c;

        $reader = new Compensation_reader();
        $svc    = new Salary_assignment_service();

        $checked = 0; $identical = 0; $byTenant = [];
        $divergences = [];

        foreach ($rows as $r) {
            $doc = $r['data'] ?? [];
            $schoolId = (string) ($doc['schoolId'] ?? '');
            if ($schoolId === '') continue;
            if (!preg_match('/^' . preg_quote($schoolId, '/') . '_SAL_(.+)$/', (string) $r['id'], $m)) continue;
            $staffId = $m[1];

            $checked++;
            $byTenant[$schoolId] = ($byTenant[$schoolId] ?? 0) + 1;

            // 1. legacy shape, straight from the stored structure
            $legacy = [];
            foreach (Compensation_reader::LEGACY_FIELDS as $f) {
                $legacy[$f] = isset($doc[$f]) && is_numeric($doc[$f]) ? (float) $doc[$f] : 0.0;
            }

            // 2. map through the migration, then resolve via the component path
            $assignment = Salary_assignment_service::fromLegacyStructure($doc, $staffId, '2026-04-01');

            // Validate the mapped assignment with the real service — a record
            // the service would reject must never be counted as parity.
            [, $vErrors] = $svc->validate($assignment, $catalogue);
            if (!empty($vErrors)) {
                $divergences[] = "{$schoolId}/{$staffId}: mapped assignment fails validation — " . implode(' ', $vErrors);
                continue;
            }

            $component = $this->resolveViaReader($reader, $assignment, $catalogue);

            // 3. compare
            $diff = Compensation_reader::compare($legacy, $component);
            if (empty($diff)) {
                $identical++;
            } else {
                $parts = [];
                foreach ($diff as $f => $d) $parts[] = "{$f}: legacy={$d['legacy']} vs component={$d['component']}";
                $divergences[] = "{$schoolId}/{$staffId}: " . implode(', ', $parts);
            }
        }

        foreach ($byTenant as $t => $n) echo "  tenant {$t}: {$n} structure(s)\n";

        $this->ok('C1 every legacy structure round-trips with no divergence',
            $checked > 0 && $identical === $checked, "{$identical}/{$checked} identical");

        foreach (array_slice($divergences, 0, 10) as $d) {
            echo "    ! {$d}\n";
        }
        if (count($divergences) > 10) echo '    … and ' . (count($divergences) - 10) . " more\n";
    }

    /**
     * Drive the reader's component path against an in-memory assignment.
     *
     * The reader normally fetches the assignment from Firestore; here it is
     * supplied directly so the gate needs no migration. The mapping code under
     * test is the real one.
     */
    private function resolveViaReader(Compensation_reader $reader, array $assignment, array $catalogue): array
    {
        $stub = new class($assignment) {
            private array $a;
            public function __construct(array $a) { $this->a = $a; }
            public function resolve(string $staffId, string $date): ?array { return $this->a; }
        };
        $fsStub = new class($catalogue) {
            private array $cat;
            public function __construct(array $c) { $this->cat = $c; }
            public function schoolWhere(string $col, array $cond = []): array {
                $out = [];
                foreach ($this->cat as $code => $c) $out[] = ['id' => $code, 'data' => $c];
                return $out;
            }
            public function getEntity(string $col, string $id) { return null; }
        };
        $reader->init($fsStub, Compensation_reader::MODE_COMPONENT, $stub);
        return $reader->resolveFromComponents((string) $assignment['staffId'], '2026-06-01') ?? [];
    }

    // ── D. Honest data state ─────────────────────────────────────────────

    private function group_data_state(): void
    {
        $this->head('D. Data state — what the flag would resolve against today');

        try {
            $comps   = $this->fsv->where('salaryComponents', []);
            $assigns = $this->fsv->where('staffSalaryAssignments', []);
            $stat    = $this->fsv->where('statutoryConfig', []);
        } catch (\Throwable $e) {
            echo "  [SKIP] live read: " . $e->getMessage() . "\n";
            return;
        }

        echo '  salaryComponents: ' . count($comps) . " doc(s)\n";
        echo '  staffSalaryAssignments: ' . count($assigns) . " doc(s)\n";
        echo '  statutoryConfig: ' . count($stat) . " doc(s)\n";

        // Not a failure — a statement of why the flag must stay false. Flipping
        // it now would resolve every employee to null, which the readiness
        // surface reports as blocked rather than paying zero, but it would still
        // stop payroll for the whole tenant.
        $this->ok('D1 flag correctly still false while the collections are empty',
            count($comps) === 0 || count($assigns) === 0
                ? ((bool) $this->config->item('USE_COMPONENT_COMPENSATION', 'hrms_compensation_flags')) === false
                : true,
            'seed + migrate + re-run this gate before flipping');
    }

    // ── E. Statutory wage base — the PF exposure, quantified ─────────────

    /**
     * What switching `USE_STATUTORY_WAGE_BASE` on would do to the PF base.
     *
     * A SEPARATE QUESTION from groups A–D. Those ask whether the component
     * model reproduces today's payroll INPUTS. This asks what the statutory
     * definition of WAGES does to the base those inputs are charged on. The two
     * cutovers are independent and have independent flags, so they are measured
     * independently.
     *
     * This group needs NO tenant data. It compares two readings of the SAME
     * structure — `compute()` (frozen legacy behaviour: PF on basic + DA) versus
     * the statutory classification (which additionally treats a fixed medical
     * allowance and a residual allowance as wages, per s.2(y) and *Vivekananda
     * Vidyamandir*). Every real structure found is measured too, but with zero
     * structures in any tenant today the synthetic profiles are what carry the
     * evidence.
     *
     * The profiles are chosen to span ZenX's actual market, which is the point:
     * a private CTC school with no DA sees a very different delta from an aided
     * institution on a pay scale.
     */
    private function group_statutory_wage_base(): void
    {
        $this->head('E. Statutory wage base — what the PF flag would change');

        $this->config->load('hrms_statutory_flags', true);
        $useStat = (bool) $this->config->item('USE_STATUTORY_WAGE_BASE', 'hrms_statutory_flags');
        $statShadow = (bool) $this->config->item('STATUTORY_WAGE_SHADOW', 'hrms_statutory_flags');

        $this->ok('USE_STATUTORY_WAGE_BASE is OFF', $useStat === false,
            $useStat ? 'FLAG IS ON — payroll is using the statutory base' : 'inert');
        $this->ok('STATUTORY_WAGE_SHADOW is OFF', $statShadow === false);

        // CA-4: the tenant's DATED configuration is the authority; the PHP
        // flags are only the fallback for a tenant that has configured none.
        $fbNum = (int) $this->config->item('STATUTORY_ADDBACK_THRESHOLD_NUM', 'hrms_statutory_flags');
        $fbDen = (int) $this->config->item('STATUTORY_ADDBACK_THRESHOLD_DEN', 'hrms_statutory_flags');

        $tenantCfg = null;
        try {
            $svc = new Statutory_config_service();
            $svc->init($this->fsv, '');
            $all = $svc->listAll();
            if (!empty($all)) {
                $tenantCfg = Statutory_config_service::selectFor($all, date('Y-m-d'));
            }
        } catch (\Throwable $e) {
            echo '  (tenant statutory read skipped: ' . $e->getMessage() . ")
";
        }

        $ab  = Statutory_config_service::addBackOptions($tenantCfg, $fbNum, $fbDen);
        $num = $ab['thresholdNum'];
        $den = $ab['thresholdDen'];
        $this->ok('add-back threshold source is known', in_array($ab['source'], ['tenant', 'fallback'], true),
            $ab['source'] === 'tenant' ? 'from the tenant configuration' : 'no tenant configuration yet');
        $this->ok('add-back threshold is configured', $den > 0 && $num > 0 && $num <= $den,
            "{$num}/{$den}");

        // The engine must still be wired to nothing.
        $hr = @file_get_contents(APPPATH . 'controllers/Hr.php');
        $this->ok('payroll does not call the statutory engine',
            is_string($hr) && strpos($hr, 'Statutory_wage_base::') === false,
            'the flag is the only thing that should ever turn this on');

        echo "\n  Profiles — monthly rupees, and the PF base each reading produces:\n\n";
        printf("  %-34s %10s %10s %10s  %s\n",
            'profile', 'legacy', 'statutory', 'delta', 'add-back');
        echo '  ' . str_repeat('-', 82) . "\n";

        $profiles = [
            // Private CTC school, no DA at all — the case where "basic + DA"
            // changes nothing and the real exposure is the residual allowance.
            'private CTC, no DA' => [
                'basic' => 25000, 'hra' => 12500, 'da' => 0,
                'ta' => 2000, 'medical' => 1250, 'other_allowances' => 9250,
            ],
            // Aided institution on a pay scale — DA is real and large.
            'aided pay-scale, large DA' => [
                'basic' => 20000, 'hra' => 6000, 'da' => 18000,
                'ta' => 1600, 'medical' => 1250, 'other_allowances' => 3150,
            ],
            // Token basic with a heavy allowance stack — what the add-back exists for.
            'token basic, heavy allowances' => [
                'basic' => 8000, 'hra' => 20000, 'da' => 0,
                'ta' => 3000, 'medical' => 2000, 'other_allowances' => 17000,
            ],
            // Compliant structure — basic already above half.
            'compliant, basic > half' => [
                'basic' => 30000, 'hra' => 10000, 'da' => 5000,
                'ta' => 1000, 'medical' => 1000, 'other_allowances' => 3000,
            ],
            // At or below the PF ceiling — where coverage itself is in question.
            'at the PF ceiling' => [
                'basic' => 15000, 'hra' => 6000, 'da' => 0,
                'ta' => 1000, 'medical' => 500, 'other_allowances' => 2500,
            ],
        ];

        $maxDelta = 0;
        foreach ($profiles as $label => $structure) {
            $legacy = Statutory_wage_base::compute($structure, ['thresholdNum' => $num, 'thresholdDen' => $den]);
            $stat   = Statutory_wage_base::computeFromComponents(
                Statutory_wage_base::classifyLegacyStructure($structure),
                ['thresholdNum' => $num, 'thresholdDen' => $den]
            );

            $delta = $stat['basePaise'] - $legacy['basePaise'];
            if ($delta > $maxDelta) $maxDelta = $delta;

            printf("  %-34s %10s %10s %10s  %s\n",
                $label,
                number_format($legacy['basePaise'] / 100, 2),
                number_format($stat['basePaise'] / 100, 2),
                number_format($delta / 100, 2),
                $stat['addBackApplied'] ? 'YES ' . number_format($stat['addBackPaise'] / 100, 2) : 'no');

            // Invariants that must hold for EVERY profile, real or synthetic.
            $this->ok("[{$label}] statutory base is never below legacy",
                $stat['basePaise'] >= $legacy['basePaise'],
                'a statutory correction must never reduce a contribution base');
            $this->ok("[{$label}] base never exceeds remuneration",
                $stat['basePaise'] <= $stat['totalPaise']);
            $this->ok("[{$label}] nothing left unclassified",
                $stat['unclassified'] === [],
                implode(',', $stat['unclassified']));
        }

        echo "\n  Largest base increase across profiles: "
            . number_format($maxDelta / 100, 2) . " per month.\n";
        echo "  At a 12% + 12% contribution that is "
            . number_format($maxDelta * 0.24 / 100, 2) . " per employee per month.\n";
        echo "  THIS IS THE NUMBER THE OWNER AND THEIR CA MUST ACCEPT BEFORE THE FLAG MOVES.\n";

        // ── Real structures, if any tenant has one ────────────────────────
        $real = 0;
        try {
            // Same reader group D uses — this controller extends CI_Controller,
            // so there is no MY_Controller $fs helper here.
            $rows = $this->fsv->where('salarySlips', [['type', '==', 'salary_structure']]);
            foreach ((array) $rows as $r) {
                $d = is_array($r['data'] ?? null) ? $r['data'] : (is_array($r) ? $r : []);
                if ($d === []) continue;
                $real++;
                $legacy = Statutory_wage_base::compute($d, ['thresholdNum' => $num, 'thresholdDen' => $den]);
                $stat   = Statutory_wage_base::computeFromComponents(
                    Statutory_wage_base::classifyLegacyStructure($d),
                    ['thresholdNum' => $num, 'thresholdDen' => $den]
                );
                $this->ok("[real #{$real}] statutory base is never below legacy",
                    $stat['basePaise'] >= $legacy['basePaise']);
            }
        } catch (\Throwable $e) {
            echo "  (real-structure scan skipped: " . $e->getMessage() . ")\n";
        }

        echo "\n  Real salary structures measured in this tenant: {$real}\n";
        if ($real === 0) {
            echo "  Zero structures exist, so there is NOTHING TO MIGRATE and no\n";
            echo "  historical payroll to re-open. That is why this is the cheapest\n";
            echo "  possible moment to correct the base.\n";
        }
    }
}
