<?php
/**
 * School-configurable benefit policy — Phase 2/3.
 *
 * THE PRINCIPLE THESE DEFEND:
 *      VERIFIED RELATIONSHIP  !=  AUTOMATIC FINANCIAL BENEFIT
 *
 * ZenX now knows, verifiably, that two students are siblings or that a parent
 * is an employee. Whether that is worth any money is a decision only the school
 * may take, and until it does the answer is nothing.
 *
 * PROVEN AT RUNTIME before these were written, on SCH_FAREAUDIT1:
 *   unconfigured school      -> both benefits resolve DISABLED
 *   disabled + verified link -> outcome policy_disabled, no entitlement
 *   approval mode            -> ONE entitlement, pending_approval
 *   re-evaluated twice more  -> still ONE, still pending (idempotent)
 *   25% -> 10%               -> old DISCONTINUED, new pending. Never both.
 *   automatic staff ward     -> granted, 50%, active
 *   employee set Inactive    -> not_eligible, entitlement DISCONTINUED
 *   School B                 -> sibling still disabled while School A is on
 */

use PHPUnit\Framework\TestCase;

if (!defined('BASEPATH')) define('BASEPATH', __DIR__);
if (!function_exists('log_message')) { function log_message($l, $m) {} }

require_once __DIR__ . '/../../application/libraries/Benefit_policy_service.php';

final class BenefitPolicyLayerTest extends TestCase
{
    private function fs(?array $doc = null)
    {
        return new class($doc) {
            public array $written = [];
            public function __construct(private ?array $doc) {}
            public function get(string $c, string $id) { return $this->doc; }
            public function set(string $c, string $id, array $d, bool $m = false): bool
            {
                $this->written[$id] = $d; $this->doc = array_merge((array) $this->doc, $d); return true;
            }
        };
    }

    private function svc($fs): Benefit_policy_service
    {
        return (new Benefit_policy_service())->init($fs, 'SCH_T1', '2026-27', 'SSA9001');
    }

    // ══ FAIL SAFE — THE MOST IMPORTANT PROPERTY HERE ════════════════════════

    /**
     * A school that has not decided has not consented. Absent document, absent
     * key, unreadable store — every route ends in "no benefit".
     */
    public function test_an_unconfigured_school_grants_nothing(): void
    {
        foreach ([null, [], ['version' => 3], ['sibling' => 'not-an-array']] as $stored) {
            $svc = $this->svc($this->fs($stored));
            foreach (Benefit_policy_service::BENEFITS as $b) {
                $p = $svc->resolve($b);
                $this->assertSame(Benefit_policy_service::MODE_DISABLED, $p['mode'],
                    'an unconfigured or unreadable policy must resolve to DISABLED — '
                    . '"we could not tell" must never become "so we gave a discount"');
                $this->assertSame(0.0, $p['value'], 'there is no default percentage anywhere');
            }
        }
    }

    /** A read failure is a refusal, not a fallback. */
    public function test_a_storage_failure_disables_the_benefit(): void
    {
        $fs = new class {
            public function get(string $c, string $id) { throw new \RuntimeException('firestore down'); }
            public function set(string $c, string $id, array $d, bool $m = false): bool { return true; }
        };
        $p = $this->svc($fs)->resolve(Benefit_policy_service::BENEFIT_SIBLING);
        $this->assertSame(Benefit_policy_service::MODE_DISABLED, $p['mode']);
    }

    /**
     * An ENABLED policy whose numbers do not validate is more dangerous than no
     * policy, because it would price something. It collapses to disabled.
     */
    public function test_an_enabled_but_invalid_policy_collapses_to_disabled(): void
    {
        $bad = [
            'a percent over 100'   => ['mode' => 'automatic', 'valueType' => 'percent', 'value' => 150, 'scope' => 'all'],
            'a zero value'         => ['mode' => 'automatic', 'valueType' => 'percent', 'value' => 0,   'scope' => 'all'],
            'a negative value'     => ['mode' => 'approval',  'valueType' => 'fixed',   'value' => -5,  'scope' => 'all'],
            'an unknown valueType' => ['mode' => 'automatic', 'valueType' => 'free',    'value' => 10,  'scope' => 'all'],
            'a malformed scope'    => ['mode' => 'automatic', 'valueType' => 'percent', 'value' => 10,  'scope' => 'tuition'],
            'an empty scope target'=> ['mode' => 'automatic', 'valueType' => 'percent', 'value' => 10,  'scope' => 'category:'],
            'a bad date'           => ['mode' => 'automatic', 'valueType' => 'percent', 'value' => 10,  'scope' => 'all', 'effectiveFrom' => '01-04-2026'],
            'an inverted window'   => ['mode' => 'automatic', 'valueType' => 'percent', 'value' => 10,  'scope' => 'all',
                                       'effectiveFrom' => '2026-09-01', 'effectiveTo' => '2026-06-01'],
        ];
        foreach ($bad as $why => $cfg) {
            $p = $this->svc($this->fs(['version' => 1, 'sibling' => $cfg]))
                      ->resolve(Benefit_policy_service::BENEFIT_SIBLING);
            $this->assertSame(Benefit_policy_service::MODE_DISABLED, $p['mode'],
                "an enabled policy with {$why} must not price anything");
        }
    }

    /** The same rules refuse the write, so invalid config is never stored. */
    public function test_invalid_configuration_is_rejected_at_save(): void
    {
        $fs = $this->fs();
        $res = $this->svc($fs)->saveBenefit(Benefit_policy_service::BENEFIT_SIBLING, [
            'mode' => 'automatic', 'valueType' => 'percent', 'value' => 150, 'scope' => 'all',
        ]);

        $this->assertFalse($res['ok']);
        $this->assertSame([], $fs->written, 'nothing may be stored that the resolver would refuse');
    }

    // ══ VALID CONFIGURATION ═════════════════════════════════════════════════

    public function test_a_valid_policy_round_trips(): void
    {
        $fs  = $this->fs();
        $res = $this->svc($fs)->saveBenefit(Benefit_policy_service::BENEFIT_SIBLING, [
            'mode' => 'approval', 'valueType' => 'percent', 'value' => 25,
            'scope' => 'category:tuition', 'effectiveFrom' => '2026-04-01',
        ]);
        $this->assertTrue($res['ok']);
        $this->assertSame(1, $res['version']);

        $p = $this->svc($fs)->resolve(Benefit_policy_service::BENEFIT_SIBLING);
        $this->assertSame('approval', $p['mode']);
        $this->assertSame(25.0, $p['value']);
        $this->assertSame('category:tuition', $p['scope']);
        $this->assertSame(1, $p['policyVersion']);
    }

    /** Disabling is always valid — a school must be able to stop offering. */
    public function test_a_benefit_can_always_be_switched_off(): void
    {
        $fs = $this->fs(['version' => 4, 'sibling' => ['mode' => 'automatic',
              'valueType' => 'percent', 'value' => 25, 'scope' => 'all']]);
        $res = $this->svc($fs)->saveBenefit(Benefit_policy_service::BENEFIT_SIBLING, ['mode' => 'disabled']);

        $this->assertTrue($res['ok']);
        $this->assertSame(Benefit_policy_service::MODE_DISABLED,
            $this->svc($fs)->resolve(Benefit_policy_service::BENEFIT_SIBLING)['mode']);
    }

    // ══ VERSIONING AND AUDIT ════════════════════════════════════════════════

    /**
     * Every change bumps a version and appends to an immutable trail. A benefit
     * records the version it was granted under, so changing the rate later is
     * traceable instead of retroactive.
     */
    public function test_each_change_bumps_a_version_and_is_recorded(): void
    {
        $fs  = $this->fs();
        $svc = $this->svc($fs);

        $svc->saveBenefit(Benefit_policy_service::BENEFIT_SIBLING,
            ['mode' => 'approval', 'valueType' => 'percent', 'value' => 25, 'scope' => 'all']);
        $svc->saveBenefit(Benefit_policy_service::BENEFIT_SIBLING,
            ['mode' => 'approval', 'valueType' => 'percent', 'value' => 10, 'scope' => 'all']);

        $doc = $fs->written['SCH_T1_2026-27_benefit_policy'];
        $this->assertSame(2, $doc['version']);
        $this->assertCount(2, $doc['history'], 'the trail is append-only');
        $this->assertSame(25.0, $doc['history'][0]['value']);
        $this->assertSame(10.0, $doc['history'][1]['value']);
        $this->assertSame('SSA9001', $doc['history'][1]['changedBy'],
            'a financial policy change must be answerable for later');
    }

    // ══ EFFECTIVE DATES ═════════════════════════════════════════════════════

    /** Future-dated policies do not apply early; ended ones do not continue. */
    public function test_a_policy_only_applies_inside_its_window(): void
    {
        $svc = $this->svc($this->fs());
        $pol = ['mode' => 'automatic', 'effectiveFrom' => '2026-06-01', 'effectiveTo' => '2026-09-30'];

        $this->assertFalse($svc->isInForce($pol, '2026-05-31'), 'not before it starts');
        $this->assertTrue($svc->isInForce($pol, '2026-06-01'),  'on the first day');
        $this->assertTrue($svc->isInForce($pol, '2026-09-30'),  'on the last day');
        $this->assertFalse($svc->isInForce($pol, '2026-10-01'), 'not after it ends');

        $this->assertFalse($svc->isInForce(['mode' => 'disabled'], '2026-07-01'),
            'a disabled benefit is never in force, whatever its dates say');
    }

    public function test_an_open_ended_policy_is_always_in_force(): void
    {
        $svc = $this->svc($this->fs());
        $this->assertTrue($svc->isInForce(
            ['mode' => 'approval', 'effectiveFrom' => '', 'effectiveTo' => ''], '2030-01-01'));
    }

    // ══ TENANT SCOPE ════════════════════════════════════════════════════════

    /**
     * The document id carries school AND session, so one school's policy cannot
     * be another's, and next year's rate cannot reach into this year's billing.
     */
    public function test_policy_is_scoped_to_school_and_session(): void
    {
        $fs = $this->fs();
        $this->svc($fs)->saveBenefit(Benefit_policy_service::BENEFIT_SIBLING,
            ['mode' => 'approval', 'valueType' => 'percent', 'value' => 25, 'scope' => 'all']);

        $this->assertArrayHasKey('SCH_T1_2026-27_benefit_policy', $fs->written);
        $doc = $fs->written['SCH_T1_2026-27_benefit_policy'];
        $this->assertSame('SCH_T1', $doc['schoolId']);
        $this->assertSame('2026-27', $doc['session']);
    }

    // ══ WHAT MUST NOT BE CONFIGURABLE ═══════════════════════════════════════

    /**
     * The policy document is a place a school states preferences. It must never
     * become a place a school switches off a financial safety rule — a settings
     * page that appears to offer that is worse than one that does not.
     */
    public function test_no_platform_invariant_is_exposed_as_a_setting(): void
    {
        $src = (string) file_get_contents(__DIR__ . '/../../application/libraries/Benefit_policy_service.php');
        $ui  = (string) file_get_contents(__DIR__ . '/../../application/views/financial_policies/index.php');

        foreach (['allowNegative', 'skipAudit', 'disableAudit', 'bypassApproval',
                  'allowCrossTenant', 'skipVerification', 'unlimitedStacking',
                  'allowOverDiscount'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $src,
                "'{$forbidden}' would let a school switch off a financial safety rule");
            $this->assertStringNotContainsString($forbidden, $ui);
        }
    }

    /** The screen must say plainly that a relationship is not a discount. */
    public function test_the_screen_states_that_a_relationship_is_not_a_discount(): void
    {
        $ui = (string) file_get_contents(__DIR__ . '/../../application/views/financial_policies/index.php');

        $this->assertStringContainsString('A verified relationship is not a discount', $ui,
            'the single most likely misreading is "I ticked the relationship, so the discount '
            . 'happened" — the screen must contradict it before the operator can make it');
        $this->assertStringContainsString('Set by the platform', $ui,
            'and must show what is NOT theirs to change, so its absence reads as deliberate');
    }

    /** Reachability: a policy screen nobody can open leaves every school on defaults. */
    public function test_the_screen_is_reachable_from_the_sidebar_and_routed(): void
    {
        $routes = (string) file_get_contents(__DIR__ . '/../../application/config/routes.php');
        $nav    = (string) file_get_contents(__DIR__ . '/../../application/views/include/header.php');

        $this->assertStringContainsString("\$route['financial_policies']", $routes);
        $this->assertStringContainsString("base_url('financial_policies')", $nav,
            'an unreachable configuration page would silently leave every school on the safe '
            . 'defaults with no way to change them');
    }

    /** Both endpoints are Fees:manage — reading who gets a discount is sensitive too. */
    public function test_the_endpoints_require_fees_manage(): void
    {
        $c = (string) file_get_contents(__DIR__ . '/../../application/controllers/Financial_policies.php');

        $this->assertSame(4, substr_count($c, "'Fees', 'manage'"),
            'index, get_policies, save_policy and evaluate_student must all require manage — '
            . 'a view-level read of which families receive a discount is still financial data');
        $this->assertStringNotContainsString("'Fees', 'view'", $c);
    }
}
