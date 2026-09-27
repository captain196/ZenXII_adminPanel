<?php

use PHPUnit\Framework\TestCase;

if (!defined('BASEPATH')) define('BASEPATH', __DIR__);
if (!defined('APPPATH'))  define('APPPATH', __DIR__ . '/../../application/');
if (!function_exists('log_message')) { function log_message($l, $m) {} }

require_once APPPATH . 'helpers/webhook_signature_helper.php';

/**
 * ══════════════════════════════════════════════════════════════════════════
 *  GW-SEC4 — ROTATING THE WEBHOOK SECRET STRANDED IN-FLIGHT PAYMENTS
 * ══════════════════════════════════════════════════════════════════════════
 *
 * The webhook secret was a single stored value. The moment an operator rotated
 * it, every delivery already in flight — and every gateway retry of an earlier
 * one — was still signed with the OLD secret. Those failed HMAC, returned 401,
 * and the gateway eventually stopped retrying.
 *
 * The payment itself succeeded at the gateway. What was lost is the
 * NOTIFICATION that it succeeded. If the parent's client callback also did not
 * complete, the order never reached _verify_and_process at all.
 *
 * ── WHY THE OBVIOUS RECOVERY IS FORBIDDEN ─────────────────────────────────
 * Such an order sits in `created`/`attempted`, and `retry_payment_processing()`
 * refuses anything that is not `fees_failed`. It would be tempting to widen
 * that. It must not be widened: the retry path does NOT call verify_payment()
 * — it goes straight to FeeCollectionService, trusting the order's stored
 * gateway_payment_id. `fees_failed` is precisely the state that guarantees
 * signature verification already succeeded once, so that restriction IS the
 * verification guarantee. Relaxing it would trade a rare stranded payment for a
 * permanent hole in payment verification.
 *
 * Hence prevention: a bounded window in which the rotated-out secret is still
 * accepted. These are REAL behavioural tests of the actual helper — it is a
 * pure function, so nothing here is source-shaped.
 */
class WebhookSignatureRotationTest extends TestCase
{
    private const BODY = '{"event":"payment.captured","payload":{"amount":60000}}';

    private const CURRENT  = 'secret_current_value';
    private const PREVIOUS = 'secret_previous_value';

    private function sign(string $secret, string $body = self::BODY): string
    {
        return hash_hmac('sha256', $body, $secret);
    }

    private function config(array $overrides = []): array
    {
        return array_merge([
            'provider'       => 'razorpay',
            'mode'           => 'live',
            'webhook_secret' => self::CURRENT,
        ], $overrides);
    }

    // ══ THE ORDINARY CASE ═══════════════════════════════════════════════════

    public function test_a_signature_from_the_current_secret_is_accepted(): void
    {
        $this->assertTrue(webhook_signature_valid(
            self::BODY, $this->sign(self::CURRENT), $this->config()
        ));
    }

    public function test_a_wrong_signature_is_rejected(): void
    {
        $this->assertFalse(webhook_signature_valid(
            self::BODY, $this->sign('some_other_secret'), $this->config()
        ));
    }

    public function test_an_empty_signature_is_rejected(): void
    {
        $this->assertFalse(webhook_signature_valid(self::BODY, '', $this->config()));
    }

    /**
     * The signature covers the exact bytes. A body altered after signing must
     * not verify — this is the whole point of the check.
     */
    public function test_a_tampered_body_is_rejected(): void
    {
        $sig = $this->sign(self::CURRENT, self::BODY);
        $tampered = str_replace('60000', '1', self::BODY);

        $this->assertFalse(webhook_signature_valid($tampered, $sig, $this->config()),
            'a body modified after signing verified — the amount could be rewritten in '
            . 'transit');
    }

    // ══ THE ROTATION WINDOW ═════════════════════════════════════════════════

    /**
     * THE DEFECT. Without the window this is false, and a payment whose webhook
     * was in flight during a rotation is stranded with no recovery path.
     */
    public function test_a_signature_from_the_previous_secret_is_accepted_inside_the_window(): void
    {
        $cfg = $this->config([
            'webhook_secret_previous'   => self::PREVIOUS,
            'webhook_secret_rotated_at' => date('c', time() - 600),   // 10 minutes ago
        ]);

        $this->assertTrue(webhook_signature_valid(self::BODY, $this->sign(self::PREVIOUS), $cfg),
            'a webhook signed with the just-rotated-out secret was rejected. It is retried '
            . 'a few times and then dropped, and the resulting order state cannot be '
            . 'recovered by the admin retry path.');
    }

    /**
     * The widened trust must expire on its own. An indefinite window means two
     * valid signing keys forever, which is strictly worse than one.
     */
    public function test_the_previous_secret_stops_working_once_the_window_closes(): void
    {
        $cfg = $this->config([
            'webhook_secret_previous'   => self::PREVIOUS,
            'webhook_secret_rotated_at' => date('c', time() - 200000),  // > 24h
        ]);

        $this->assertFalse(webhook_signature_valid(self::BODY, $this->sign(self::PREVIOUS), $cfg),
            'a rotated-out secret is still accepted long after the rotation. Accepting a '
            . 'second signing key permanently defeats the purpose of rotating.');

        $this->assertTrue(webhook_signature_valid(self::BODY, $this->sign(self::CURRENT), $cfg),
            'the current secret must keep working after the window closes');
    }

    public function test_the_window_boundary_is_respected(): void
    {
        $inside = $this->config([
            'webhook_secret_previous'   => self::PREVIOUS,
            'webhook_secret_rotated_at' => date('c', time() - 3600),
        ]);
        $this->assertTrue(webhook_signature_valid(self::BODY, $this->sign(self::PREVIOUS), $inside, 7200));
        $this->assertFalse(webhook_signature_valid(self::BODY, $this->sign(self::PREVIOUS), $inside, 60),
            'the grace period is not being honoured as a bound');
    }

    // ══ EVERY UNBOUNDED CASE FAILS CLOSED ═══════════════════════════════════

    /**
     * A previous secret with no timestamp cannot be bounded, so it would be
     * accepted forever. Refusing is the only safe reading.
     */
    public function test_a_previous_secret_without_a_rotation_timestamp_is_refused(): void
    {
        $cfg = $this->config(['webhook_secret_previous' => self::PREVIOUS]);

        $this->assertFalse(webhook_signature_valid(self::BODY, $this->sign(self::PREVIOUS), $cfg),
            'a previous secret with no rotation timestamp was honoured. Nothing would ever '
            . 'close that window.');
    }

    public function test_an_unparseable_rotation_timestamp_is_refused(): void
    {
        $cfg = $this->config([
            'webhook_secret_previous'   => self::PREVIOUS,
            'webhook_secret_rotated_at' => 'whenever',
        ]);

        $this->assertFalse(webhook_signature_valid(self::BODY, $this->sign(self::PREVIOUS), $cfg));
    }

    public function test_an_empty_previous_secret_is_not_treated_as_a_match(): void
    {
        $cfg = $this->config([
            'webhook_secret_previous'   => '',
            'webhook_secret_rotated_at' => date('c'),
        ]);

        // A signature computed over the empty string must not slip through.
        $this->assertFalse(webhook_signature_valid(self::BODY, $this->sign(''), $cfg));
    }

    public function test_a_config_with_no_secret_at_all_verifies_nothing(): void
    {
        $this->assertFalse(webhook_signature_valid(self::BODY, $this->sign(self::CURRENT), []));
        $this->assertFalse(webhook_signature_valid(self::BODY, 'anything', ['webhook_secret' => '']));
    }

    // ══ TENANT ISOLATION ════════════════════════════════════════════════════

    /**
     * Each tenant's config carries its own secret. A signature valid for one
     * school must never verify against another's — the helper is given the
     * tenant's own document, so this pins that the caller cannot cross-verify.
     */
    public function test_one_tenants_signature_does_not_verify_against_another(): void
    {
        $schoolA = ['webhook_secret' => 'school_A_secret'];
        $schoolB = ['webhook_secret' => 'school_B_secret'];

        $sigA = $this->sign('school_A_secret');

        $this->assertTrue(webhook_signature_valid(self::BODY, $sigA, $schoolA));
        $this->assertFalse(webhook_signature_valid(self::BODY, $sigA, $schoolB),
            "School A's webhook signature verified against School B's configuration — a "
            . "webhook for one tenant could be replayed into another's money path");
    }

    /**
     * Nor may a neighbouring tenant's rotated-out secret help.
     */
    public function test_a_foreign_previous_secret_does_not_verify(): void
    {
        $schoolB = [
            'webhook_secret'            => 'school_B_secret',
            'webhook_secret_previous'   => 'school_B_old',
            'webhook_secret_rotated_at' => date('c'),
        ];

        $this->assertFalse(
            webhook_signature_valid(self::BODY, $this->sign('school_A_secret'), $schoolB)
        );
    }
}
