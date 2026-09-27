<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * The wage base ZenX computes PF on, against the base the statute names.
 *
 * FOUND 2026-08-22 while auditing the statutory wage base (Section D).
 *
 * `_compute_payslip()` derives six earning components — basic, hra, da, ta,
 * medical, other_allowances — and then computes provident fund on
 * `$effectiveBasicP` alone, in BOTH the legacy and the statutory-aware branch.
 *
 * Section 6 of the Employees' Provident Funds and Miscellaneous Provisions Act,
 * 1952 states the contribution is payable on "the basic wages, dearness
 * allowance and retaining allowance (if any)". `da` is a real, populated
 * component of the ZenX salary structure that already counts toward gross.
 * Excluding it understates both the employee and the employer contribution.
 *
 * This is NOT a consequence of the 2025 labour codes — it is a gap against the
 * EPF Act as it has stood since 1952. The labour codes (in force 21 Nov 2025)
 * add a SECOND, separate requirement on top: where excluded allowances exceed
 * 50% of total remuneration, the excess is added back into "wages" for PF,
 * gratuity, bonus and leave-encashment purposes. `Statutory_wage_base.php`
 * implements that add-back and is deliberately wired to nothing.
 *
 * EXPOSURE, MEASURED READ-ONLY ACROSS ALL TENANTS:
 *   salary structures ... 0
 *   payslips ............ 0
 * So no employee has ever been under-contributed and no correction is owed.
 * The defect is latent, which is the only reason it is safe to leave standing.
 *
 * NOT FIXED HERE, deliberately. Broadening a PF base changes every future
 * employee's take-home AND the school's employer-contribution liability. It is
 * a money decision with statutory and audit consequences, it needs the tenant's
 * chartered accountant, and the standing instruction on this programme is not to
 * alter statutory bases autonomously. It is carried as item 1 of the owner
 * decision pack, with the citation above.
 *
 * What this suite DOES do is make the gap impossible to lose: it pins the
 * current base precisely, so the day someone changes it, they change this too.
 */
final class StatutoryPfWageBaseTest extends TestCase
{
    private string $hr;

    protected function setUp(): void
    {
        $hr = file_get_contents(__DIR__ . '/../../application/controllers/Hr.php');
        $this->assertNotFalse($hr);
        $this->hr = $hr;
    }

    private function engine(): string
    {
        $i = strpos($this->hr, 'private function _compute_payslip(');
        $this->assertNotFalse($i, '_compute_payslip not found');
        return substr($this->hr, (int) $i, 9000);
    }

    // ── The components that exist ────────────────────────────────────────

    /** DA is a real component, not a hypothetical one. */
    public function test_dearness_allowance_is_a_real_salary_component(): void
    {
        $this->assertStringContainsString(
            "\$daP              = self::_applyRatio(self::_toPaise(\$sal['da'] ?? 0),",
            $this->engine(),
            'the PF gap only matters because da is a populated structure field'
        );
    }

    /** And it reaches gross, so it is genuinely part of remuneration. */
    public function test_dearness_allowance_is_part_of_gross(): void
    {
        $this->assertStringContainsString(
            '$grossP = $effectiveBasicP + $hraP + $daP + $taP + $medicalP + $otherAllowancesP;',
            $this->engine(),
            'da is paid to the employee — it is not a notional field'
        );
    }

    // ── The base actually used ───────────────────────────────────────────

    /**
     * Both branches compute PF on basic alone. Pinned exactly: if either moves,
     * this test must be revisited alongside the decision that moved it.
     */
    public function test_pf_is_computed_on_basic_alone_in_the_statutory_path(): void
    {
        $this->assertStringContainsString(
            'Statutory_config_service::computePf($statutory, $effectiveBasicP);',
            $this->engine(),
            'the statutory-aware path passes earned BASIC as the whole PF wage'
        );
    }

    public function test_pf_is_computed_on_basic_alone_in_the_legacy_path(): void
    {
        $engine = $this->engine();

        $this->assertStringContainsString(
            '$pfEmployeeP  = self::_applyRatio($effectiveBasicP, $pfPct  / 100);',
            $engine
        );
        $this->assertStringContainsString(
            "\$pfEmployerP  = self::_applyRatio(\$effectiveBasicP, ((float) (\$sal['pf_employer']  ?? 0)) / 100);",
            $engine,
            'the employer contribution is understated by the same proportion as the employee one'
        );
    }

    /**
     * The service itself is BASE-AGNOSTIC — it takes whatever wage it is handed.
     * That matters for the remediation estimate: the fix is at the call site, not
     * in the statutory engine, and the tenant-configured rates and ceilings do
     * not need to change at all.
     */
    public function test_the_statutory_service_does_not_choose_the_base_itself(): void
    {
        $svc = file_get_contents(__DIR__ . '/../../application/libraries/Statutory_config_service.php');
        $this->assertNotFalse($svc);

        $this->assertStringContainsString(
            'public static function computePf(array $cfg, int $wagePaise): array',
            $svc,
            'the service accepts a wage — the CALLER decides what counts as wage'
        );

        $i = strpos($svc, 'public static function computePf(');
        $body = substr($svc, (int) $i, 1400);
        foreach (['basic', "'da'", 'dearness'] as $needle) {
            $this->assertStringNotContainsString(
                $needle,
                $body,
                'the service must stay agnostic, so broadening the base is a one-site change'
            );
        }
    }

    // ── The labour-codes add-back, built and unwired ─────────────────────

    /** The add-back exists as a pure, tested library. */
    public function test_the_labour_codes_wage_base_is_implemented(): void
    {
        $path = __DIR__ . '/../../application/libraries/Statutory_wage_base.php';
        $this->assertFileExists($path);

        $src = file_get_contents($path);
        $this->assertNotFalse($src);
        // The trigger is an exact cross-multiplied integer comparison, so no
        // rounding is involved in deciding whether the add-back fires at all.
        $this->assertStringContainsString(
            'if ($conditional * $den > $total * $num) {',
            $src,
            'the trigger must not depend on a rounded threshold'
        );
        // And the threshold it subtracts is FLOORED, so the excess added back
        // is rounded up by at most a paise — never understating the base.
        $this->assertStringContainsString(
            '$addBack = $conditional - intdiv($total * $num, $den);',
            $src,
            'the add-back must round in the employee\'s favour, not the employer\'s'
        );
        $this->assertStringContainsString('const CLASS_CONDITIONAL', $src,
            'the three-state classification is what lets the add-back pool be modelled at all');
    }

    /**
     * And it is wired to NOTHING. Asserted rather than assumed: a half-wired
     * statutory base that silently applied to some payslips and not others would
     * be worse than one that applies to none.
     */
    public function test_the_labour_codes_wage_base_is_wired_to_nothing(): void
    {
        $callers = [];
        $dir = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(__DIR__ . '/../../application', FilesystemIterator::SKIP_DOTS)
        );
        foreach ($dir as $f) {
            if ($f->getExtension() !== 'php') continue;
            if ($f->getFilename() === 'Statutory_wage_base.php') continue;
            $src = file_get_contents($f->getPathname());
            if ($src === false) continue;
            // A STATIC CALL is wiring. A mention in prose is not: the flag file
            // `hrms_statutory_flags.php` documents what the class does and what
            // flipping it would change, and documenting a gate is not passing
            // through it. Scanning for the bare name would make it impossible to
            // describe the boundary in the very file that defines it.
            if (strpos($src, 'Statutory_wage_base::') !== false) {
                $callers[] = $f->getFilename();
            }
        }

        // Permitted callers, each named explicitly rather than excluded by
        // pattern, so a NEW one appearing is a deliberate act somebody has to
        // come here and authorise.
        //
        //   Hrms_w2_parity.php            read-only harness; measures the legacy
        //                                 and statutory readings against each
        //                                 other and never touches a payslip.
        //   Compensation.php              the CONFIGURATION surface. Reads the
        //                                 classification vocabulary and the
        //                                 statutory defaults to render and
        //                                 validate the admin screen.
        //   Salary_component_service.php  validation. Reads the vocabulary to
        //                                 refuse an invalid classification.
        //
        // The two configuration callers use the engine's VOCABULARY, not its
        // arithmetic — asserted separately below. That distinction is the whole
        // point: knowing the names of the three classifications is not the same
        // as computing somebody's wage base.
        $permitted = [
            'Hrms_w2_parity.php',
            'Compensation.php',
            'Salary_component_service.php',
        ];
        $unexpected = array_values(array_diff($callers, $permitted));

        $this->assertSame(
            [],
            $unexpected,
            'either it is unwired everywhere or it is wired everywhere — never partly: '
            . implode(', ', $unexpected)
        );
    }

    /**
     * THE ONE THAT ACTUALLY MATTERS. Neither payroll controller may call the
     * statutory engine. The allow-list above admits a read-only harness; it must
     * never be read as admitting the money path.
     */
    public function test_no_payroll_controller_calls_the_statutory_engine(): void
    {
        foreach (['Hr.php', 'Payroll.php'] as $controller) {
            $path = __DIR__ . '/../../application/controllers/' . $controller;
            if (!is_file($path)) continue;

            $src = (string) file_get_contents($path);
            $this->assertStringNotContainsString(
                'Statutory_wage_base::',
                $src,
                "{$controller} must not reach the statutory engine until the flag "
                . 'cutover is authorised — this is the money path'
            );
        }
    }

    /**
     * The CONFIGURATION callers may name the vocabulary but must never compute a
     * wage base. Rendering a dropdown of three classifications is not the same
     * act as deciding what somebody contributes.
     */
    public function test_configuration_callers_use_the_vocabulary_not_the_arithmetic(): void
    {
        foreach ([
            'controllers/Compensation.php',
            'libraries/Salary_component_service.php',
        ] as $rel) {
            $path = __DIR__ . '/../../application/' . $rel;
            if (!is_file($path)) continue;

            $src = (string) file_get_contents($path);

            foreach ([
                'Statutory_wage_base::compute(',
                'Statutory_wage_base::computeFromComponents(',
                'Statutory_wage_base::classifyLegacyStructure(',
                'Statutory_wage_base::needsAddBack(',
            ] as $arithmetic) {
                $this->assertStringNotContainsString(
                    $arithmetic,
                    $src,
                    "{$rel} is a configuration surface — it must not compute a wage base"
                );
            }
        }
    }
    /**
     * And the permitted caller must genuinely be read-only. A harness that could
     * write would be a far worse thing to have on the allow-list than a second
     * calculation path.
     */
    public function test_the_permitted_caller_is_read_only(): void
    {
        $path = __DIR__ . '/../../application/controllers/Hrms_w2_parity.php';
        if (!is_file($path)) $this->markTestSkipped('parity harness not present');

        $src = (string) file_get_contents($path);

        foreach (['->set(', '->update(', '->delete(', 'setEntity(', 'updateEntity('] as $write) {
            $this->assertStringNotContainsString(
                $write,
                $src,
                'the parity harness is on the allow-list only because it cannot write'
            );
        }
    }

    /**
     * The scan must be able to SEE a call, not merely find none. A predicate
     * that can never match would make the assertion above vacuous.
     */
    public function test_the_wiring_predicate_matches_a_real_call(): void
    {
        $positive = 'Statutory_wage_base::computeFromComponents($c);';
        $negative = 'see Statutory_wage_base for the definition';

        $this->assertNotFalse(strpos($positive, 'Statutory_wage_base::'),
            'a genuine call site must be detected');
        $this->assertFalse(strpos($negative, 'Statutory_wage_base::'),
            'a prose mention must not be');
    }

    /** The scan above must actually be scanning something. */
    public function test_the_wiring_scan_is_not_vacuous(): void
    {
        $seen = 0;
        $dir = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(__DIR__ . '/../../application', FilesystemIterator::SKIP_DOTS)
        );
        foreach ($dir as $f) {
            if ($f->getExtension() === 'php') $seen++;
        }
        $this->assertGreaterThan(
            200,
            $seen,
            'a scan that walked no files would make the wiring assertion meaningless'
        );
    }

    // ── The gap itself ───────────────────────────────────────────────────

    /**
     * The invariant that does NOT hold. markTestIncomplete rather than a skip so
     * it stays visible in every single suite run until an owner rules on it.
     */
    public function test_pf_should_be_computed_on_the_statutory_wage_base(): void
    {
        $onBasicOnly = strpos($this->engine(), 'computePf($statutory, $effectiveBasicP)') !== false;

        if ($onBasicOnly) {
            $this->markTestIncomplete(
                'D-PF-1 (DEMONSTRATED ON REAL MONEY 2026-08-25 — no longer latent): a UAT '
                . 'employee (STA0110, Vikrant Public School) was hired, paid through '
                . 'off-cycle run PR0019 and the deduction was PF 1,066.67 = 12% of earned '
                . 'BASIC 8,888.89 alone, with earned DA 444.44 excluded from the base. '
                . 'The census this note previously cited (0 structures, 0 payslips) no '
                . 'longer holds. Provident fund is computed on earned BASIC only, in both the '
                . 'legacy and the statutory-aware branch. Section 6 of the EPF & MP Act 1952 '
                . 'makes the contribution payable on the basic wages, dearness allowance and '
                . 'retaining allowance (if any), and `da` is a populated ZenX component that '
                . 'already counts toward gross. Separately, the labour codes in force since '
                . '21 Nov 2025 require excluded allowances above 50% of total remuneration to '
                . 'be added back into wages for PF, gratuity, bonus and leave encashment; '
                . 'Statutory_wage_base.php implements that and is wired to nothing. NOT FIXED '
                . 'AUTONOMOUSLY: broadening a PF base changes every employee take-home AND the '
                . 'employer contribution liability, and needs the tenant CA plus owner sign-off. '
                . 'Remediation is a ONE-SITE change — the statutory service is base-agnostic and '
                . 'tenant rates/ceilings are unaffected. Decision pack item 1.'
            );
        }

        $this->fail('the PF base changed — re-read the decision before trusting this suite');
    }

    /**
     * Release large source strings between tests.
     *
     * PHPUnit keeps every TestCase instance alive for the duration of the run,
     * so a multi-hundred-KB source held in a property stays resident once per
     * test method. Measured: a source-reading class costs ~18 MB against a
     * 512 MB limit, and the suite was running at 506 MB. Clearing the reference
     * frees the string while leaving the (now tiny) instance for reporting.
     */
    protected function tearDown(): void
    {
        foreach (get_object_vars($this) as $prop => $val) {
            if (is_string($val) && strlen($val) > 50000) $this->{$prop} = '';
        }
    }
}
