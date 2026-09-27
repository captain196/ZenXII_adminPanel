<?php

use PHPUnit\Framework\TestCase;

if (!defined('APPPATH')) define('APPPATH', __DIR__ . '/../../application/');

/**
 * ══════════════════════════════════════════════════════════════════════════
 *  P11 — NO FABRICATED PROVIDER IDENTITY, ANYWHERE IN THE PAYMENT PATH
 * ══════════════════════════════════════════════════════════════════════════
 *
 * PA-CORE-1 (P0) fixed three receipt writers that hardcoded `'razorpay'` and
 * one display default. A later adversarial sweep found **seven more**
 * fabricated `?? 'mock'` defaults that the first pass missed:
 *
 *   • 3 × persisted `feeOnlinePayments` money records (verify / webhook / retry)
 *   • 2 × the `gateway_name` fallback chain in verify + _verify_and_process
 *   • 1 × `create_payment_order`'s client-facing `provider` field
 *   • 1 × `Payment_service::create_order`'s order-reuse return
 *   • 1 × `get_reconciliation_data()`'s display row (P11, see below)
 *
 * ── WHY 'mock' IS WORSE THAN 'razorpay' HERE ──────────────────────────────
 * `'mock'` does not merely name the wrong provider — it asserts the payment
 * was SIMULATED. On the reconciliation screen, which exists specifically so
 * an operator can investigate payment discrepancies, that invites dismissing
 * a genuine orphaned real-money order as a test artifact.
 *
 * ── THE INVARIANT ─────────────────────────────────────────────────────────
 * Provider identity is OBSERVED (from the active adapter's get_name(), or
 * from the order document that adapter wrote), never invented. Where it
 * genuinely cannot be observed, it must read `'unknown'` — visibly absent,
 * not plausibly present.
 *
 * This test scans EXECUTABLE code only (comments stripped), because every one
 * of the fixes above is accompanied by a comment that quotes the old defect.
 * A raw grep would match the explanation and report a defect that is not there.
 */
class NoFabricatedProviderDefaultsTest extends TestCase
{
    private const PAYMENT_PATH_FILES = [
        'controllers/Fee_management.php',
        'controllers/Admission_public.php',
        'libraries/Payment_service.php',
        'libraries/Payment_provider_config_resolver.php',
        'libraries/Payment_gateway_selector.php',
        'libraries/Payment_order_provenance.php',
    ];

    private function executableSource(string $relPath): string
    {
        $src = (string) file_get_contents(APPPATH . $relPath);
        $code = '';
        foreach (token_get_all($src) as $t) {
            if (is_array($t)) {
                if (in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true)) continue;
                $code .= $t[1];
            } else {
                $code .= $t;
            }
        }
        return $code;
    }

    /** THE CONTROL: comment-stripping must actually work, or this file is vacuous. */
    public function test_control_comment_stripping_removes_the_explanatory_prose(): void
    {
        $raw  = (string) file_get_contents(APPPATH . 'controllers/Fee_management.php');
        $code = $this->executableSource('controllers/Fee_management.php');

        $this->assertStringContainsString("was `?? 'mock'`", $raw,
            'the explanatory comments describing the old defect are gone — this test can no '
            . 'longer demonstrate that it is distinguishing comments from code');
        $this->assertStringNotContainsString("was `?? 'mock'`", $code,
            'comment stripping is not working, so every assertion below is scanning prose '
            . 'as though it were code');
    }

    /**
     * THE INVARIANT. No executable `?? 'mock'` or `?? 'razorpay'` anywhere in
     * the payment path.
     */
    public function test_no_payment_path_file_fabricates_a_provider_default(): void
    {
        foreach (self::PAYMENT_PATH_FILES as $file) {
            $code = $this->executableSource($file);

            $this->assertSame(0,
                preg_match_all("/\?\?\s*'(mock|razorpay)'/", $code),
                basename($file) . " contains an executable `?? 'mock'` or `?? 'razorpay'` "
                . 'fallback. Provider identity must be OBSERVED from the active adapter or '
                . "the order that adapter wrote — never invented. Use 'unknown' where it "
                . 'genuinely cannot be determined.');
        }
    }

    /**
     * The reconciliation report specifically — the sharpest case, because it
     * is the screen used to hunt for exactly this class of defect.
     */
    public function test_reconciliation_shows_unrecorded_provenance_as_unknown(): void
    {
        $code = $this->executableSource('controllers/Fee_management.php');

        $this->assertStringContainsString("'gateway'      => \$order['gateway'] ?? 'unknown',", $code,
            'get_reconciliation_data() no longer surfaces unrecorded provenance as '
            . "'unknown'. Labelling it 'mock' asserts the payment was simulated, which "
            . 'invites an operator to dismiss a genuine real-money orphan.');
    }

    /**
     * The persisted money records. These are what reconciliation READS, so a
     * fabricated value here outlives the request that created it.
     */
    public function test_persisted_payment_records_never_fabricate_the_gateway(): void
    {
        $code = $this->executableSource('controllers/Fee_management.php');

        $this->assertSame(3,
            substr_count($code, "'gateway'            => \$order['gateway'] ?? 'unknown',"),
            'expected exactly 3 feeOnlinePayments writers (parent verify, webhook '
            . "_verify_and_process, admin retry) to record provenance as 'unknown' when the "
            . 'order carries none — a different count means a writer was added without the '
            . 'same guarantee, or one regressed');
    }

    /**
     * `Payment_service`'s reuse path: GW-ORD-1 already proves the stored
     * gateway matches the active one, so degrading to `$activeGateway` is both
     * truthful and unreachable — whereas `'mock'` was untruthful.
     */
    public function test_order_reuse_returns_the_active_gateway_not_a_simulator(): void
    {
        $code = $this->executableSource('libraries/Payment_service.php');

        $this->assertStringContainsString("'gateway'           => \$existing['gateway'] ?? \$activeGateway,", $code,
            'the order-reuse return no longer degrades to the ACTIVE gateway. It must never '
            . "degrade to 'mock': GW-ORD-1 has already proven the stored gateway matches "
            . 'the active one at this point, so a simulator name here is provably wrong.');
    }
}
