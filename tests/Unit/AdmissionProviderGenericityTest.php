<?php

use PHPUnit\Framework\TestCase;

if (!defined('APPPATH')) define('APPPATH', __DIR__ . '/../../application/');
if (!defined('BASEPATH')) define('BASEPATH', 'x');

require_once APPPATH . 'libraries/Payment_gateway_selector.php';

/**
 * ══════════════════════════════════════════════════════════════════════════
 *  ADMISSION MUST SERVE ANY ENABLED PROVIDER — NO SPLIT-BRAIN WITH FEES
 * ══════════════════════════════════════════════════════════════════════════
 *
 * ── THE DEFECT ───────────────────────────────────────────────────────────
 * `Admission_public::_load_payment_gateway()` branched on
 * `$selection === ::RAZORPAY` and returned a hardcoded `'name' => 'razorpay'`.
 *
 * A school that switched to a second ENABLED provider would keep collecting
 * **fees** perfectly (Fees resolves through the registry) and **silently lose
 * admission payments** — two surfaces reading the SAME configuration document
 * and disagreeing about it. That state must be impossible.
 *
 * The hardcoded `name` was separately the PA-CORE-1 fabricated-provenance
 * defect: that value flows into money records and GW-ORD-1 comparisons, so it
 * must be OBSERVED from the adapter, never asserted.
 *
 * ── WHY THIS FILE EXISTS AT ALL ──────────────────────────────────────────
 * Written because a mutation SURVIVED. Reverting the branch to
 * `=== ::RAZORPAY` left all 170 tests across the payment and admission suites
 * green — nothing anywhere guarded it. A defect that has been fixed but is
 * unguarded is a defect waiting to return.
 *
 * ── ON THE SOURCE-BASED ASSERTIONS, STATED PLAINLY ───────────────────────
 * `_load_payment_gateway()` is a private method on a CI3 controller, which
 * cannot be instantiated without the framework (the same limitation
 * `GatewayFallbackSafetyTest` documents). The genericity of the branch is
 * therefore asserted against the source; everything the branch DEPENDS on —
 * registry resolution, capability gating — is driven behaviourally below.
 * The source assertions are anchored on the ABSENCE of the defect and the
 * PRESENCE of the generic call, so the exact mutation that survived now fails.
 */
class AdmissionProviderGenericityTest extends TestCase
{
    private static string $src = '';

    public static function setUpBeforeClass(): void
    {
        self::$src = (string) file_get_contents(APPPATH . 'controllers/Admission_public.php');
    }

    /** Raw body — includes comments. Use for asserting on documentation. */
    private function loaderBody(): string
    {
        $at = strpos(self::$src, 'private function _load_payment_gateway');
        $this->assertNotFalse($at, '_load_payment_gateway was renamed or removed');
        $end = strpos(self::$src, "\n    private function ", $at + 30);
        return substr(self::$src, $at, ($end !== false ? $end - $at : 9000));
    }

    /**
     * Comment-stripped body — use for asserting on BEHAVIOUR.
     *
     * Every fix in this area is documented with a comment that QUOTES the old
     * defect verbatim ("returned a hardcoded `'name' => 'razorpay'`"). A raw
     * scan matches that explanation and reports a defect that is not there —
     * which is exactly what happened while writing this file.
     */
    private function loaderCode(): string
    {
        $code = '';
        foreach (token_get_all('<?php ' . $this->loaderBody()) as $t) {
            if (is_array($t)) {
                if (in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true)) continue;
                $code .= $t[1];
            } else {
                $code .= $t;
            }
        }
        return $code;
    }

    /** THE CONTROL for the stripper — otherwise every assertion below is vacuous. */
    public function test_control_comment_stripping_removes_the_quoted_old_defect(): void
    {
        $this->assertMatchesRegularExpression("/'name'\s*=>\s*'razorpay'/i", $this->loaderBody(),
            'the comment quoting the old defect is gone — this test can no longer show it '
            . 'distinguishes comments from code');
        $this->assertDoesNotMatchRegularExpression("/'name'\s*=>\s*'razorpay'/i", $this->loaderCode(),
            'comment stripping is not working, so the behavioural assertions are scanning '
            . 'prose as though it were code');
    }

    // ══ THE MUTATION THAT SURVIVED MUST NOW FAIL ═════════════════════════

    /**
     * THE regression guard. This is the precise mutation that passed 170
     * tests before this file existed.
     */
    public function test_the_provider_branch_is_not_keyed_on_razorpay(): void
    {
        $body = $this->loaderCode();   // comments stripped — see the control above

        $this->assertStringNotContainsString(
            '$selection === Payment_gateway_selector::RAZORPAY',
            $body,
            'Admission loads providers only when the selection is RAZORPAY. A school that '
            . 'switched to any other enabled provider would keep collecting FEES correctly '
            . 'and silently lose ADMISSION payments — two surfaces reading the same '
            . 'configuration and disagreeing.'
        );

        // The generic form: anything not UNAVAILABLE and not MOCK.
        $this->assertStringContainsString(
            '$selection !== Payment_gateway_selector::UNAVAILABLE',
            $body,
            'the generic provider branch is gone');
        $this->assertStringContainsString(
            '$selection !== Payment_gateway_selector::MOCK',
            $body,
            'the mock is no longer separated from real providers, so the simulator could '
            . 'be loaded through the credential-driven path that has no environment gate');
    }

    /** Provenance must be OBSERVED from the adapter, never asserted. */
    public function test_the_returned_provider_name_comes_from_the_adapter(): void
    {
        $body = $this->loaderCode();

        // WHITESPACE-INDEPENDENT. An earlier version of this assertion matched
        // the exact string "'name'       => 'razorpay'," and a mutation with
        // different spacing SURVIVED it. Any quoted provider literal assigned
        // to the `name` key is the defect, however it is formatted.
        //
        // 'mock' is deliberately EXCLUDED from this list. The mock has its own
        // branch below the generic one, and `'name' => 'mock'` there is not a
        // fabrication — it is the simulator correctly naming itself, and that
        // branch never loads a credential-driven adapter. An earlier version of
        // this regex included 'mock' and failed against perfectly correct code.
        $this->assertDoesNotMatchRegularExpression(
            "/'name'\s*=>\s*'(razorpay|cashfree|paytm|stripe|payu)'/i",
            $body,
            "Admission returns a hardcoded provider name. That value reaches money records "
            . 'and GW-ORD-1 comparisons — it is provenance, and provenance is observed, '
            . 'never asserted.');

        $this->assertStringContainsString("method_exists(\$adapter, 'get_name')", $body,
            'the provider name is no longer taken from the adapter that will actually '
            . 'process the payment');
    }

    /** The adapter class must come from the shared registry, not a literal. */
    public function test_the_adapter_class_is_resolved_through_the_registry(): void
    {
        $body = $this->loaderCode();

        $this->assertStringContainsString('Payment_gateway_selector::adapter_class($selection)', $body,
            'Admission resolves its adapter some way other than the shared registry — that '
            . 'is a second provider authority, which is how Fees and Admission drifted apart '
            . 'in the first place');

        $this->assertStringNotContainsString("load->library('Payment_gateway_razorpay'", $body,
            'Admission hardcodes the Razorpay adapter class again');
    }

    /**
     * A selector verdict with no adapter behind it is a contradiction, not a
     * configuration problem. Guessing which half is right would mean loading
     * *something* for a provider the registry does not recognise.
     */
    public function test_a_selection_with_no_adapter_refuses_rather_than_guessing(): void
    {
        $body = $this->loaderCode();

        $this->assertStringContainsString('if ($adapterClass === null)', $body,
            'a provider the selector approved but the registry cannot resolve is not '
            . 'refused — the loader would continue with no adapter');
        $this->assertMatchesRegularExpression('/adapterClass === null\)\s*\{\s*.*?throw new/s', $body,
            'the unresolvable-adapter branch does not throw');
    }

    // ══ WHAT THE BRANCH DEPENDS ON — DRIVEN BEHAVIOURALLY ════════════════

    /**
     * The generic branch is only correct if `adapter_class()` returns a real,
     * loadable class for EVERY enabled provider. Driven directly.
     */
    public function test_every_enabled_provider_resolves_to_a_loadable_adapter(): void
    {
        foreach (['production', 'development'] as $env) {
            foreach (Payment_gateway_selector::selectable_provider_ids($env) as $id) {
                $class = Payment_gateway_selector::adapter_class($id);

                $this->assertNotNull($class,
                    "provider '{$id}' is selectable in {$env} but resolves to no adapter — "
                    . 'Admission\'s generic branch would refuse a provider a school can save');

                $this->assertTrue(
                    class_exists($class) || file_exists(APPPATH . "libraries/{$class}.php"),
                    "provider '{$id}' names adapter '{$class}', which does not exist");
            }
        }
    }

    /**
     * An uncertified provider must be unreachable from Admission's generic
     * branch too — genericity must not become permissiveness.
     */
    public function test_an_uncertified_provider_is_still_unreachable(): void
    {
        $this->assertSame(Payment_gateway_selector::STAGE_IMPLEMENTED,
            Payment_gateway_selector::provider_stage('cashfree'),
            'cashfree was promoted without live API evidence');

        $this->assertNull(Payment_gateway_selector::adapter_class('cashfree'),
            'an IMPLEMENTED-only provider resolves to a runnable adapter, so Admission\'s '
            . 'now-generic branch would happily load it');
    }

    // ══ NO SPLIT-BRAIN: BOTH SURFACES, ONE AUTHORITY ═════════════════════

    /**
     * The property that makes the whole fix meaningful: Fees and Admission
     * must consult the SAME selector and the SAME registry, so they cannot
     * reach different conclusions about one configuration document.
     */
    public function test_fees_and_admission_share_one_selection_authority(): void
    {
        $fees = (string) file_get_contents(APPPATH . 'controllers/Fee_management.php');

        foreach ([['Admission', self::$src], ['Fees', $fees]] as [$label, $src]) {
            $this->assertStringContainsString('Payment_gateway_selector::select(', $src,
                "{$label} does not consult the shared selector");
            $this->assertStringContainsString('Payment_gateway_selector::adapter_class(', $src,
                "{$label} does not resolve adapters through the shared registry");
        }
    }
}
