<?php

use PHPUnit\Framework\TestCase;

if (!defined('BASEPATH')) define('BASEPATH', 'x');
if (!defined('APPPATH')) define('APPPATH', __DIR__ . '/../../application/');

/**
 * ══════════════════════════════════════════════════════════════════════════
 *  PAY-OPS-4 · TWO DEFECTS ONLY A REAL RUN COULD FIND
 * ══════════════════════════════════════════════════════════════════════════
 *
 * Both of these were invisible to the unit suite and to reading. Both were
 * found by `scripts/payops4_webhook_e2e.js` — a genuinely-signed webhook
 * delivered over HTTP to the real endpoint against a real fixture tenant in
 * the Firestore emulator — asserting outcomes rather than shapes.
 *
 * They are pinned here so the unit suite can hold them from now on. These are
 * source-structural assertions, which is weaker than executing the code: a CI3
 * controller cannot be instantiated outside the framework. That limit is
 * stated rather than glossed, and the E2E script remains the executable proof.
 */
final class WebhookRuntimeDefectsTest extends TestCase
{
    private static string $src  = '';
    private static string $code = '';

    public static function setUpBeforeClass(): void
    {
        self::$src = (string) file_get_contents(APPPATH . 'controllers/Fee_management.php');

        // Structural assertions must read the EXECUTABLE controller, not its
        // prose. Every defect below carries a long forensic comment that quotes
        // the ORIGINAL broken line verbatim — so a naive scan of the raw file
        // finds the very defect it is asserting the absence of, and a
        // window-limited scan runs out of room inside the comment before it
        // ever reaches the code. Both failure modes were observed while writing
        // this file, which is a small demonstration of why "the assertion
        // passed" is not the same as "the assertion tested something".
        //
        // Same token_get_all approach GatewayWebhookUrlCorrespondenceTest
        // already uses, for the same reason.
        $out = '';
        foreach (token_get_all(self::$src) as $t) {
            if (is_array($t)) {
                if (in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true)) continue;
                $out .= $t[1];
            } else {
                $out .= $t;
            }
        }
        // Stripping a comment leaves its NEWLINES behind, so a 2600-character
        // window after an anchor can be almost entirely blank lines and reach
        // no code at all — a false failure that looks exactly like a real one.
        // Blank lines are dropped; indentation and intra-line spacing are left
        // untouched, because the assertions below match exact source strings.
        self::$code = preg_replace("/(\r?\n)(?:[ \t]*\r?\n)+/", "\$1", $out);
    }

    // ══ DEFECT 1 · THE LOADER SILENTLY DISARMED PAYMENT VERIFICATION ════════

    /**
     * The webhook needs the adapter's CLASS FILE so that
     * `method_exists($class, 'parse_callback')` is true. It used
     * `$this->load->library($__adapterClass)`, which does far more: it
     * INSTANTIATES the adapter with NO parameters — so with EMPTY credentials
     * — and registers it in CI's loaded-library registry.
     *
     * Later in the same request `_init_payment_service()` calls
     *     $this->load->library('Payment_gateway_razorpay', [api_key, …])
     * CI3 sees the name already registered, logs "Second attempt ignored" at
     * DEBUG (a level config.php discards), and SKIPS the load. Payment_service
     * is handed the CREDENTIAL-LESS instance.
     *
     * Before PAY-OPS-4 this was harmless-looking: the webhook died at the
     * signature check long before any credential was used. The moment the
     * webhook began confirming payments by CALLING the provider, it became the
     * whole story — every delivery failed with "Razorpay credentials are not
     * configured", was correctly classified unconfirmable-and-retryable, and
     * NO payment could ever be reconciled.
     *
     * The defect lives in CI's loader, not in this file's logic, which is
     * exactly why no unit test would have seen it.
     */
    public function test_the_webhook_does_not_preload_the_adapter_through_the_ci_loader(): void
    {
        // Anchor on the METHOD, not on the adapter_class() call: that call text
        // also appears in test_gateway_connection() and gateway(), earlier in
        // the file, so a bare strpos lands in the wrong method and asserts
        // against code that was never the subject. (Observed: the first version
        // of this test was reading the gateway diagnostic.)
        $anchor = strpos(self::$code, 'public function payment_webhook()');
        $this->assertNotFalse($anchor, 'payment_webhook() was renamed or removed');

        $block = substr(self::$code, $anchor, 9000);
        $this->assertStringContainsString('Payment_gateway_selector::parse_provider_callback', $block,
            'the window does not reach the adapter resolution — this test would pass '
            . 'without inspecting the code it claims to guard');

        $this->assertStringNotContainsString('$this->load->library($__adapterClass)', $block,
            'the webhook loads the provider adapter through CI\'s library loader again. That '
            . 'instantiates it WITHOUT credentials and registers the name, so the later '
            . 'credentialed load in _init_payment_service() is silently skipped and every '
            . 'webhook fails with "credentials are not configured".');

        $this->assertStringContainsString('require_once $__adapterFile', $block,
            'the adapter class file is no longer made available by require_once. Without the '
            . 'class present, parse_provider_callback falls back to generic top-level parsing '
            . 'and a nested webhook yields empty ids (PAY-OPS-3 all over again).');
    }

    /**
     * `_init_payment_service()` must remain the ONLY place the Razorpay
     * adapter is constructed with credentials — a second construction site is
     * a second chance to build it without them.
     */
    public function test_the_credentialed_adapter_has_exactly_one_construction_site(): void
    {
        $this->assertSame(1, substr_count(self::$code, "\$this->load->library('Payment_gateway_razorpay'"),
            'the credentialed Razorpay adapter is loaded in more than one place. Whichever '
            . 'runs first wins the CI registry, and if that one omits credentials the other '
            . 'is silently ignored.');
    }

    // ══ DEFECT 2 · THE DOUBLE-PREFIXED ORDER DOCUMENT ═══════════════════════

    /**
     * `_verify_and_process()` built its order doc id as
     * `"{$this->school_name}_{$recordId}"`, but `record_id` is ALREADY the
     * full document id (Payment_service returns `_orderDocId()`'s output,
     * itself `"{$schoolId}_{$gatewayOrderId}"`). The result was
     *
     *     SCH_XXXX_SCH_XXXX_order_yyy
     *
     * so every state transition — 'processing', 'paid', 'fees_failed',
     * 'amount_mismatch', receipt_key, payment_status — landed on a phantom
     * document nothing reads, while the real order stayed at 'verified'.
     *
     * Consequences, none cosmetic:
     *   • verify_payment()'s already-paid short-circuit keys on status='paid',
     *     which was never written — a repeat verification re-entered the money
     *     pipeline, stopped only by the later feeProcessedPayments marker;
     *   • the admin Retry path accepts only 'fees_failed', a status this
     *     method could never set, so a failed fee write was unrecoverable by
     *     the very tool built to recover it;
     *   • reconciliation read an order frozen at the wrong state;
     *   • one orphan document accumulated per payment.
     *
     * It affected the FRONTEND path identically — the method is shared.
     */
    public function test_the_shared_core_builds_the_order_doc_id_from_the_gateway_order_id(): void
    {
        $anchor = strpos(self::$code, "\$recordId  = \$verifyResult['record_id'];");
        $this->assertNotFalse($anchor, '_verify_and_process no longer reads record_id');

        $block = substr(self::$code, $anchor, 2600);

        $this->assertStringNotContainsString('$orderDocId = "{$this->school_name}_{$recordId}"', $block,
            'the order document id is being built by prefixing record_id, which is ALREADY '
            . 'the full doc id. Every order state transition would again write to '
            . 'SCH_XXXX_SCH_XXXX_order_yyy and the real order would never reach "paid".');

        $this->assertStringContainsString('$orderDocId = "{$this->school_name}_{$gwOrderId}";', $block,
            '_verify_and_process no longer derives the order doc id from the gateway order '
            . 'id, the way parent_verify_payment and _webhook_record_non_success both do.');
    }

    /**
     * All three sites that address a feeOnlineOrders document must agree.
     * They disagreed for as long as this defect existed, and nothing noticed
     * because the wrong one wrote to a document no reader ever asked for.
     */
    public function test_every_order_document_id_is_built_the_same_way(): void
    {
        $this->assertSame(3, substr_count(self::$code, '"{$this->school_name}_{$gwOrderId}"'),
            'the three feeOnlineOrders addressing sites (parent_verify_payment, '
            . '_verify_and_process, _webhook_record_non_success) no longer agree. A '
            . 'disagreement here writes money state to a document nothing reads.');
    }

    /**
     * THE ONE REMAINING `_{$recordId}` SITE IS CORRECT, AND HERE IS WHY.
     *
     * A blanket "never prefix a recordId" rule would be wrong and would fail
     * on legitimate code, so the boundary is pinned precisely instead.
     *
     * `retry_payment_processing()` also builds `"{$school}_{$recordId}"`, and
     * that IS right: its `$recordId` arrives from the reconciliation screen,
     * which populates the list through `_stripSchoolPrefix()` — so it is the
     * BARE gateway order id, not a qualified doc id. The two sites looked
     * identical and meant different things, which is precisely how the defect
     * survived review.
     *
     * Exactly one such site may exist. A second one is either a new defect or
     * the old one returning.
     */
    public function test_the_only_remaining_record_id_prefix_is_the_admin_retry_path(): void
    {
        $sites = substr_count(self::$code, '"{$this->school_name}_{$recordId}"');
        $this->assertSame(1, $sites,
            "expected exactly one \"{\$school}_{\$recordId}\" site (retry_payment_processing, "
            . "where recordId is already stripped) but found {$sites}. A second site is the "
            . 'double-prefix defect returning: record_id from Payment_service is already a '
            . 'fully-qualified document id.');

        // …and it must be the retry path, not something new that merely counts one.
        $at    = strpos(self::$code, '"{$this->school_name}_{$recordId}"');
        $retry = strpos(self::$code, 'public function retry_payment_processing()');
        $this->assertNotFalse($retry, 'retry_payment_processing was renamed or removed');
        $this->assertGreaterThan($retry, $at,
            'the surviving recordId-prefix site is no longer inside retry_payment_processing. '
            . 'Somewhere else is prefixing a record id, and only the retry path has a '
            . 'pre-stripped one.');
    }
}
