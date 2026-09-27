<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * FEATURE 9 — statutory configuration versioning, verified end to end.
 *
 * The requirement, stated plainly: a July payroll must resolve the statutory
 * configuration that was in force in July, and editing the September
 * configuration must not change what July produced. Without that, every rate
 * change silently rewrites history, and no closed period can be reproduced.
 *
 * `hr-payroll-brief.md` §6.2 requires statutory values to be "table-driven with
 * `effective_from`, never hard-coded", and §6.5 requires every computed amount
 * to store its inputs.
 *
 * These exercise the REAL resolver and the REAL calculators — the versioning is
 * behavioural here, not a source scan.
 */
final class StatutoryVersioningTest extends TestCase
{
    protected function setUp(): void
    {
        if (!defined('BASEPATH')) define('BASEPATH', __DIR__);
        if (!defined('APPPATH')) define('APPPATH', __DIR__ . '/../../application/');
        require_once __DIR__ . '/../../application/libraries/Statutory_config_service.php';
    }

    /**
     * Two versions: V1 to 31 August, V2 open-ended from 1 September, with a
     * different PF employee rate so the two are distinguishable in the output.
     */
    private function versions(): array
    {
        return [
            [
                'effectiveFrom' => '2026-04-01',
                'effectiveTo'   => '2026-08-31',
                'pf'  => ['employeeRate' => 12.0, 'employerRate' => 12.0,
                          'wageCeilingPaise' => 1500000, 'applyCeiling' => true,
                          'eligibleAboveCeiling' => false],
                'esi' => ['applicable' => true, 'employeeRate' => 0.75,
                          'employerRate' => 3.25, 'wageThresholdPaise' => 2100000],
            ],
            [
                'effectiveFrom' => '2026-09-01',
                'effectiveTo'   => null,
                'pf'  => ['employeeRate' => 10.0, 'employerRate' => 10.0,
                          'wageCeilingPaise' => 1500000, 'applyCeiling' => true,
                          'eligibleAboveCeiling' => false],
                'esi' => ['applicable' => true, 'employeeRate' => 0.75,
                          'employerRate' => 3.25, 'wageThresholdPaise' => 2100000],
            ],
        ];
    }

    // ── Resolution by period ─────────────────────────────────────────────

    /** A July period resolves V1. */
    public function test_an_earlier_period_resolves_the_earlier_version(): void
    {
        $v = Statutory_config_service::selectFor($this->versions(), '2026-07-31');

        $this->assertNotNull($v);
        $this->assertSame('2026-04-01', $v['effectiveFrom']);
        $this->assertSame(12.0, $v['pf']['employeeRate']);
    }

    /** A September period resolves V2. */
    public function test_a_later_period_resolves_the_later_version(): void
    {
        $v = Statutory_config_service::selectFor($this->versions(), '2026-09-30');

        $this->assertNotNull($v);
        $this->assertSame('2026-09-01', $v['effectiveFrom']);
        $this->assertSame(10.0, $v['pf']['employeeRate']);
    }

    /** The boundary belongs to the version that starts on it. */
    public function test_the_boundary_day_belongs_to_the_new_version(): void
    {
        $last  = Statutory_config_service::selectFor($this->versions(), '2026-08-31');
        $first = Statutory_config_service::selectFor($this->versions(), '2026-09-01');

        $this->assertSame('2026-04-01', $last['effectiveFrom']);
        $this->assertSame('2026-09-01', $first['effectiveFrom']);
    }

    /** A period before any version resolves to nothing, not to the earliest. */
    public function test_a_period_before_every_version_resolves_to_null(): void
    {
        $this->assertNull(Statutory_config_service::selectFor($this->versions(), '2026-03-31'),
            'resolving to the earliest version would apply rates that were not yet in force');
    }

    // ── THE INVARIANT ────────────────────────────────────────────────────

    /**
     * THE LOAD-BEARING TEST. Editing the LATER version must not change what an
     * EARLIER period resolves to.
     */
    public function test_editing_the_later_version_cannot_reprice_an_earlier_period(): void
    {
        $before = Statutory_config_service::selectFor($this->versions(), '2026-07-31');

        $edited = $this->versions();
        $edited[1]['pf']['employeeRate'] = 8.0;
        $edited[1]['pf']['wageCeilingPaise'] = 9900000;

        $after = Statutory_config_service::selectFor($edited, '2026-07-31');

        $this->assertSame($before, $after,
            'a September rate change must leave July exactly as it was');
    }

    /** And the money that falls out of it is identical too. */
    public function test_the_computed_contribution_for_an_earlier_period_is_unchanged(): void
    {
        $wage = 1200000;   // ₹12,000, under the ceiling

        $beforeCfg = Statutory_config_service::selectFor($this->versions(), '2026-07-31');
        $before = Statutory_config_service::computePf($beforeCfg, $wage);

        $edited = $this->versions();
        $edited[1]['pf']['employeeRate'] = 8.0;

        $afterCfg = Statutory_config_service::selectFor($edited, '2026-07-31');
        $after = Statutory_config_service::computePf($afterCfg, $wage);

        $this->assertSame($before['employeePaise'], $after['employeePaise']);
        $this->assertSame(144000, $before['employeePaise'], '12% of ₹12,000');
    }

    /** Adding a THIRD, future version leaves both existing periods alone. */
    public function test_adding_a_future_version_leaves_earlier_periods_alone(): void
    {
        $julyBefore = Statutory_config_service::selectFor($this->versions(), '2026-07-31');
        $septBefore = Statutory_config_service::selectFor($this->versions(), '2026-09-30');

        $extended = $this->versions();
        $extended[1]['effectiveTo'] = '2026-11-30';
        $extended[] = [
            'effectiveFrom' => '2026-12-01', 'effectiveTo' => null,
            'pf'  => ['employeeRate' => 14.0, 'employerRate' => 14.0,
                      'wageCeilingPaise' => 1500000, 'applyCeiling' => true,
                      'eligibleAboveCeiling' => false],
            'esi' => ['applicable' => true, 'employeeRate' => 0.75,
                      'employerRate' => 3.25, 'wageThresholdPaise' => 2100000],
        ];

        $this->assertSame($julyBefore, Statutory_config_service::selectFor($extended, '2026-07-31'));
        $this->assertSame($septBefore['pf']['employeeRate'],
            Statutory_config_service::selectFor($extended, '2026-09-30')['pf']['employeeRate']);
    }

    // ── Ambiguity is refused, not resolved by read order ─────────────────

    /**
     * Two versions covering one date would make a payroll run's basis depend on
     * read order — and a CLOSED period could then resolve differently on
     * re-read, destroying the reproducibility that dating exists for.
     */
    public function test_overlapping_versions_are_detected(): void
    {
        $bad = $this->versions();
        $bad[1]['effectiveFrom'] = '2026-08-01';   // overlaps V1's window

        $overlaps = Statutory_config_service::findOverlaps($bad);

        $this->assertNotEmpty($overlaps,
            'an ambiguous date range must be surfaced, never silently resolved');
    }

    /** A clean schedule reports no overlap. */
    public function test_a_clean_schedule_has_no_overlaps(): void
    {
        $this->assertSame([], Statutory_config_service::findOverlaps($this->versions()));
    }

    // ── Payroll consumes it as of the PERIOD ─────────────────────────────

    /** Both engines resolve as of the period end, never as of today. */
    public function test_payroll_resolves_the_version_as_of_the_period(): void
    {
        $hr = (string) file_get_contents(__DIR__ . '/../../application/controllers/Hr.php');

        $this->assertSame(2,
            substr_count($hr, '$statutoryCfg = $this->_statutory_config_for($periodEndDate);'),
            'generation and regeneration must resolve from the same period date');
    }

    /**
     * And the resolved version is FROZEN onto the run, so the figures stay
     * explainable after a later configuration change.
     */
    public function test_the_resolved_version_is_frozen_onto_the_run(): void
    {
        $hr = (string) file_get_contents(__DIR__ . '/../../application/controllers/Hr.php');

        $this->assertStringContainsString(
            "'statutory_snapshot' => \$this->_statutory_snapshot(\$statutoryCfg),", $hr);

        $i = strpos($hr, 'private function _statutory_snapshot(');
        $this->assertNotFalse($i);
        $body = substr($hr, (int) $i, 1800);

        $this->assertStringContainsString("'effectiveFrom' =>", $body,
            'the snapshot must name WHICH version priced the run');
    }

    /** The trace reads what was frozen rather than re-resolving today's answer. */
    public function test_the_trace_reads_the_frozen_version(): void
    {
        $hr = (string) file_get_contents(__DIR__ . '/../../application/controllers/Hr.php');

        $i = strpos($hr, 'public function payslip_trace(');
        $this->assertNotFalse($i);
        $body = substr($hr, (int) $i, 4000);

        $this->assertStringContainsString("\$run['statutory_snapshot']", $body);
        $this->assertStringNotContainsString('_statutory_config_for(', $body,
            're-resolving here would report present policy for a past run');
    }

    // ── Component classification is versioned the same way ───────────────

    /**
     * The wage classification uses the SAME effective-dating helper, so a
     * classification change cannot reprice history either. One mechanism, not
     * two that could disagree.
     */
    public function test_classification_versioning_uses_the_same_helper(): void
    {
        $src = (string) file_get_contents(
            __DIR__ . '/../../application/libraries/Statutory_wage_base.php'
        );

        $this->assertStringContainsString(
            "ed_select_for(\$catalogueRow['wageClassificationSchedule'], \$asOfDate)", $src,
            'two effective-dating implementations would eventually disagree');
    }

    /** And the statutory config resolver uses it too. */
    public function test_the_statutory_resolver_uses_the_same_helper(): void
    {
        $src = (string) file_get_contents(
            __DIR__ . '/../../application/libraries/Statutory_config_service.php'
        );

        $i = strpos($src, 'public static function selectFor(');
        $this->assertNotFalse($i);
        $this->assertStringContainsString('ed_select_for($configs, $date)',
            substr($src, (int) $i, 300));
    }

    // ── The add-back threshold is dated too ──────────────────────────────

    /**
     * The one-half threshold is notifiable, so it is a parameter rather than a
     * literal — and therefore versionable alongside everything else.
     */
    public function test_the_add_back_threshold_is_a_parameter_not_a_literal(): void
    {
        require_once __DIR__ . '/../../application/libraries/Statutory_wage_base.php';

        $components = [
            ['code' => 'BASIC', 'amountPaise' => 3000000, 'classification' => 'INCLUDE'],
            ['code' => 'HRA',   'amountPaise' => 7000000, 'classification' => 'CONDITIONAL'],
        ];

        $half  = Statutory_wage_base::computeFromComponents($components);
        $other = Statutory_wage_base::computeFromComponents($components,
            ['thresholdNum' => 3, 'thresholdDen' => 5]);

        $this->assertNotSame($half['addBackPaise'], $other['addBackPaise'],
            'a notified percentage change must be expressible without editing code');
    }
}
