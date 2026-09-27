<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * READY-D1 — Payroll Readiness judged the wrong architecture.
 *
 * Which compensation store is authoritative is a DEPLOYMENT decision, held in
 * USE_COMPONENT_COMPENSATION. F&F already respected it: _salary_structure_as_of()
 * resolves through Compensation_reader::modeFromFlags(). Payroll Readiness did
 * not -- it resolved component assignments unconditionally.
 *
 * With the flag off (the production default) that collection is empty, so the
 * screen reported EVERY employee of a live tenant as blocked, with
 * payrollEligible=false and the reason "No salary assignment is effective for
 * this period" -- while payroll itself ran correctly from legacy structures.
 * An operator reading that screen would conclude payroll was broken school-wide.
 *
 * Salary_assignment_service::readiness() already documents this exact class of
 * failure for payout details, and defends against it there:
 * "a false positive at scale, and the worst possible failure mode for a
 * readiness check whose whole job is to be trusted."
 */
final class PayrollReadinessRespectsCompensationModeTest extends TestCase
{
    private static string $ctrl = '';
    private static string $svc  = '';

    public static function setUpBeforeClass(): void
    {
        $root = __DIR__ . '/../../application/';
        self::$ctrl = (string) file_get_contents($root . 'controllers/Compensation.php');
        self::$svc  = (string) file_get_contents($root . 'libraries/Salary_assignment_service.php');
    }

    public static function tearDownAfterClass(): void
    {
        self::$ctrl = self::$svc = '';
    }

    private function endpoint(): string
    {
        $i = strpos(self::$ctrl, 'public function get_payroll_readiness');
        $this->assertNotFalse($i, 'get_payroll_readiness() has moved');

        $j = strpos(self::$ctrl, "\n    public function ", (int) $i + 10);
        return substr(self::$ctrl, (int) $i, ($j === false ? 4000 : (int) $j - (int) $i));
    }

    /** DEFECT READY-D1: the endpoint must consult the deployment flag. */
    public function test_readiness_resolves_through_the_mode_in_force(): void
    {
        $body = $this->endpoint();

        $this->assertStringContainsString('Compensation_reader::modeFromFlags(', $body,
            'readiness must judge the architecture actually in force, not assume one');
        $this->assertStringContainsString("USE_COMPONENT_COMPENSATION", $body);
        $this->assertStringContainsString("COMPENSATION_SHADOW_COMPARE", $body);
    }

    /** Under legacy/shadow it evaluates legacy structures instead. */
    public function test_legacy_mode_evaluates_legacy_structures(): void
    {
        $body = $this->endpoint();

        $this->assertStringContainsString('MODE_COMPONENT', $body);
        $this->assertStringContainsString("\$opts['resolver']", $body,
            'the flag-aware resolver is what stops the false blockers');
        $this->assertStringContainsString("\$opts['missingReason']", $body,
            'the reason shown must match the authority that was judged');
    }

    /** The mode is reported, so the screen can say what it judged. */
    public function test_the_mode_is_reported_to_the_caller(): void
    {
        $this->assertStringContainsString("'compensation_mode' => \$mode", $this->endpoint());
    }

    /** The service honours an injected resolver rather than hardcoding one. */
    public function test_the_service_accepts_an_injected_resolver(): void
    {
        $i    = strpos(self::$svc, 'public function readiness');
        $body = substr(self::$svc, (int) $i, 3000);

        $this->assertStringContainsString("\$resolver       = \$opts['resolver']", $body);
        $this->assertStringContainsString(
            '$a = $resolver !== null ? $resolver($staffId, $date) : $this->resolve($staffId, $date);',
            $body);

        // Default behaviour is unchanged when nothing is injected.
        $this->assertStringContainsString("?? null;", $body);
    }

    /**
     * The guard that already existed for payout details must stay -- it is the
     * precedent this fix follows, and the comment is the record of why.
     */
    public function test_the_existing_false_positive_guard_is_intact(): void
    {
        $this->assertStringContainsString('DEFAULT OFF, and that is a correctness decision', self::$svc);
        $this->assertStringContainsString("\$checkPayout    = \$opts['checkPayoutDetails'] ?? false;", self::$svc);
    }
}