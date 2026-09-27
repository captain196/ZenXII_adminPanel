<?php

use PHPUnit\Framework\TestCase;

if (!defined('APPPATH')) define('APPPATH', __DIR__ . '/../../application/');
if (!defined('BASEPATH')) define('BASEPATH', 'x');

require_once APPPATH . 'libraries/Payment_gateway_selector.php';

/**
 * ══════════════════════════════════════════════════════════════════════════
 *  PA-UI-1 — THE PROVIDER PICKER MUST NOT OFFER WHAT THE SERVER REFUSES
 * ══════════════════════════════════════════════════════════════════════════
 *
 * The gateway page offered Stripe and Paytm as selectable tiles — icon,
 * marketing copy, checkmark — while `save_gateway_config()`'s own
 * `$validProviders = ['razorpay']` has rejected both since GW-1. A school
 * could fill in credentials, press Save, and be told "Unsupported payment
 * provider" only then. Also the direct origin of an incorrect premise this
 * programme itself repeated once (that Paytm/Stripe adapters exist) — the UI
 * was the one place they appeared to.
 *
 * This does not merely assert the tiles are gone (a static fact that a future
 * edit could re-break in either direction). It extracts BOTH lists —
 * server-accepted providers and UI-offered providers — and asserts they are
 * the same set, so the two can never diverge silently again, regardless of
 * which one changes next.
 */
class ProviderUiServerParityTest extends TestCase
{
    /** Providers save_gateway_config() will actually accept. */
    /**
     * BEHAVIOURAL now, not a source scan.
     *
     * This used to parse a hand-maintained `$validProviders = ['razorpay']`
     * literal out of the controller. That literal is GONE: the allowlist is
     * derived from the provider catalogue, so the value can be asked for
     * directly instead of scraped. Strictly stronger — a source scan cannot
     * tell a live value from a commented-out one.
     */
    private function serverAcceptedProviders(): array
    {
        return Payment_gateway_selector::selectable_provider_ids('production');
    }

    /**
     * Providers the picker ACTUALLY RENDERS — by executing the view and
     * reading the `data-provider` attributes out of its real output.
     *
     * ── WHY NOT JUST CALL available_providers() AGAIN ─────────────────────
     * Because that would compare the catalogue to itself and pass no matter
     * what the view does. An earlier draft of this method did exactly that
     * and was tautological: it would have stayed green with the hardcoded
     * Stripe and Paytm tiles still in place, which is the one thing this file
     * exists to catch.
     *
     * Rendering the template is the only way to prove the view is genuinely
     * driven by the catalogue rather than by markup that happens to agree
     * with it today.
     */
    private function uiOfferedProviders(): array
    {
        $available = Payment_gateway_selector::available_providers('production');

        // The view calls CodeIgniter's html_escape(); provide it if the
        // framework is not loaded.
        if (!function_exists('html_escape')) {
            function html_escape($s) { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); }
        }

        $html = $this->renderPickerBlock($available);
        preg_match_all('/data-provider="([a-z0-9_]+)"/i', $html, $m);
        return array_values(array_unique($m[1]));
    }

    /**
     * Executes the REAL provider-picker template block from gateway.php.
     *
     * The full view cannot simply be included: it is a whole page and reaches
     * for CodeIgniter's $this->security (CSRF) further down, which does not
     * exist outside the framework. Extracting the picker block keeps this
     * genuinely behavioural — it runs the actual template code under test —
     * without pretending to boot CI.
     */
    private function renderPickerBlock(array $available_providers): string
    {
        $view = (string) file_get_contents(APPPATH . 'views/fee_management/gateway.php');
        $start = strpos($view, '<div class="fm-gw-providers"');
        $this->assertNotFalse($start, 'the provider-picker block is gone from gateway.php');
        $end = strpos($view, '<!-- Configuration Form -->', $start);
        $this->assertNotFalse($end, 'could not bound the provider-picker block');

        $block = substr($view, $start, $end - $start);
        $this->assertStringContainsString('foreach', $block,
            'the picker block contains no loop — it is hardcoded markup again, which is '
            . 'exactly the PA-UI-1 defect');

        ob_start();
        eval('?>' . $block);
        return (string) ob_get_clean();
    }

    /**
     * THE CONTROL for the method above: feed the view a catalogue entry that
     * is NOT in the real catalogue and prove it appears in the output. If the
     * view were still hardcoded, this synthetic provider would be missing and
     * the parity test's evidence would be worthless.
     */
    public function test_control_the_view_genuinely_renders_from_its_data(): void
    {
        if (!function_exists('html_escape')) {
            function html_escape($s) { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); }
        }

        $html = $this->renderPickerBlock([
            'synthetic_probe' => [
                'id'            => 'synthetic_probe',
                'display_name'  => 'Synthetic Probe',
                'description'   => 'injected by a test',
                'checkout_type' => 'native_sdk',
                'credentials'   => [],
            ],
        ]);

        $this->assertStringContainsString('data-provider="synthetic_probe"', $html,
            'the gateway view did not render a provider it was handed, so it is NOT driven '
            . 'by the catalogue — it is hardcoded, and the parity assertions in this file '
            . 'prove nothing');
        $this->assertStringNotContainsString('data-provider="razorpay"', $html,
            'the view rendered Razorpay even though it was not in the supplied data — a '
            . 'hardcoded tile survives alongside the loop');
    }

    /** THE CORE ASSERTION — the two lists must be exactly the same set. */
    public function test_ui_offers_exactly_the_providers_the_server_accepts(): void
    {
        $server = $this->serverAcceptedProviders();
        $ui     = $this->uiOfferedProviders();

        sort($server);
        sort($ui);

        $this->assertSame($server, $ui,
            'the gateway picker and the server allowlist have diverged. Server accepts ['
            . implode(',', $server) . '], UI offers [' . implode(',', $ui) . ']. Every '
            . 'provider the UI offers must be one the server can actually activate — this '
            . 'is exactly how Stripe and Paytm ended up as dead-end tiles.');
    }

    /**
     * Named regression check, kept alongside the parity check rather than
     * instead of it: even if parity holds by coincidence (both lists empty,
     * both lists wrong the same way), Stripe and Paytm specifically must
     * never reappear as tiles — they have no adapter, full stop.
     */
    public function test_stripe_and_paytm_are_not_offered_as_tiles(): void
    {
        $ui = $this->uiOfferedProviders();

        $this->assertNotContains('stripe', $ui,
            'the Stripe tile is back. No Payment_gateway_stripe adapter exists — this is '
            . 'PA-UI-1 regressing.');
        $this->assertNotContains('paytm', $ui,
            'the Paytm tile is back. No Payment_gateway_paytm adapter exists — this is '
            . 'PA-UI-1 regressing.');
    }

    /** The control: Razorpay must still be offered — this is a parity fix, not a lockout. */
    public function test_razorpay_is_still_offered(): void
    {
        $this->assertContains('razorpay', $this->uiOfferedProviders(),
            'the only real, working provider was removed along with the fake ones');
    }
}
