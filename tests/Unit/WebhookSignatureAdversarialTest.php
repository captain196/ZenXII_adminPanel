<?php

use PHPUnit\Framework\TestCase;

if (!defined('APPPATH')) define('APPPATH', __DIR__ . '/../../application/');
if (!defined('BASEPATH')) define('BASEPATH', 'x');
if (!function_exists('log_message')) { function log_message($l, $m) {} }

require_once APPPATH . 'helpers/webhook_signature_helper.php';

/**
 * ══════════════════════════════════════════════════════════════════════════
 *  THE WEBHOOK SIGNATURE VERIFIER — EXECUTED, NOT DESCRIBED
 * ══════════════════════════════════════════════════════════════════════════
 *
 * ── THE GAP THIS CLOSES (found 2026-08-26) ───────────────────────────────
 * `webhook_signature_valid()` is the function that decides whether an inbound
 * webhook is genuinely from the payment provider. It is the entire security
 * boundary of that endpoint: everything downstream — receipts, allocations,
 * journals — trusts it.
 *
 * It had **no test that called it**. The "42 webhook tests" asserted on source
 * text (that `hash_hmac` appears, that `hash_equals` appears), and the only
 * behavioural signature tests in the repository targeted the *Cashfree*
 * adapter, which is not a certified provider. So the primary Razorpay path's
 * crypto was verified by reading it, not by running it.
 *
 * Reading proves the code LOOKS right. For a security boundary that is not
 * enough — a transposed argument, an `==` that should be `hash_equals`, or a
 * grace window that never expires all read perfectly well.
 *
 * ── SCOPE ────────────────────────────────────────────────────────────────
 * This certifies the HANDLER'S CRYPTO. It does not, and cannot, certify REAL
 * PROVIDER DELIVERY — that needs a dedicated webhook secret created in the
 * Razorpay dashboard and a publicly reachable host. Those remain separate
 * claims and are reported separately.
 *
 * No secret here is a real credential. Every key is generated in-test.
 */
final class WebhookSignatureAdversarialTest extends TestCase
{
    /** A test-only key. Never a real credential, never read from config. */
    private const KEY_A = 'test_key_alpha_0123456789abcdef';
    private const KEY_B = 'test_key_bravo_fedcba9876543210';

    private const BODY = '{"event":"payment.captured","payload":{"payment":{"entity":{"id":"pay_TEST","amount":70000}}}}';

    private function sign(string $body, string $key): string
    {
        return hash_hmac('sha256', $body, $key);
    }

    private function cfg(array $over = []): array
    {
        return array_merge(['webhook_secret' => self::KEY_A], $over);
    }

    // ── THE HAPPY PATH, SO THE REJECTIONS MEAN SOMETHING ─────────────────

    public function test_a_correctly_signed_body_is_accepted(): void
    {
        // Positive control. Without this every assertion below could pass
        // because the function simply always returns false.
        $this->assertTrue(webhook_signature_valid(
            self::BODY, $this->sign(self::BODY, self::KEY_A), $this->cfg()
        ));
    }

    // ── FORGERY AND TAMPERING ────────────────────────────────────────────

    public function test_a_signature_from_the_wrong_key_is_rejected(): void
    {
        $this->assertFalse(webhook_signature_valid(
            self::BODY, $this->sign(self::BODY, self::KEY_B), $this->cfg()
        ));
    }

    public function test_a_single_altered_byte_in_the_body_invalidates_it(): void
    {
        // The amount is the field an attacker would change. 70000 -> 70001.
        $sig = $this->sign(self::BODY, self::KEY_A);
        $tampered = str_replace('70000', '70001', self::BODY);
        $this->assertNotSame(self::BODY, $tampered, 'the tamper did not apply');
        $this->assertFalse(webhook_signature_valid($tampered, $sig, $this->cfg()));
    }

    public function test_a_signature_valid_for_a_different_body_is_rejected(): void
    {
        $other = str_replace('pay_TEST', 'pay_OTHER', self::BODY);
        $this->assertFalse(webhook_signature_valid(
            self::BODY, $this->sign($other, self::KEY_A), $this->cfg()
        ));
    }

    public function test_an_empty_signature_is_rejected(): void
    {
        $this->assertFalse(webhook_signature_valid(self::BODY, '', $this->cfg()));
    }

    public function test_a_whitespace_padded_signature_is_rejected(): void
    {
        // hash_equals is exact. A verifier that trimmed would be accepting a
        // value it did not compute.
        $this->assertFalse(webhook_signature_valid(
            self::BODY, ' ' . $this->sign(self::BODY, self::KEY_A) . ' ', $this->cfg()
        ));
    }

    public function test_an_uppercased_hex_signature_is_rejected(): void
    {
        $this->assertFalse(webhook_signature_valid(
            self::BODY, strtoupper($this->sign(self::BODY, self::KEY_A)), $this->cfg()
        ));
    }

    public function test_a_signature_from_a_weaker_algorithm_is_rejected(): void
    {
        // sha1 of the same body with the same key must not pass a sha256 check.
        $this->assertFalse(webhook_signature_valid(
            self::BODY, hash_hmac('sha1', self::BODY, self::KEY_A), $this->cfg()
        ));
    }

    // ── MISSING CONFIGURATION MUST FAIL CLOSED ───────────────────────────

    public function test_an_absent_secret_rejects_everything(): void
    {
        // The current live state of the test school. An endpoint with no secret
        // must authenticate nothing at all — including a well-formed signature.
        $cfg = ['webhook_secret' => ''];
        $this->assertFalse(webhook_signature_valid(
            self::BODY, $this->sign(self::BODY, self::KEY_A), $cfg
        ));
        $this->assertFalse(webhook_signature_valid(self::BODY, 'anything', $cfg));
    }

    public function test_a_config_with_no_secret_key_at_all_rejects(): void
    {
        $this->assertFalse(webhook_signature_valid(self::BODY, 'x', []));
    }

    public function test_an_empty_secret_cannot_be_matched_by_an_empty_signature(): void
    {
        // The degenerate case: hash of nothing vs nothing. Must not authenticate.
        $this->assertFalse(webhook_signature_valid(self::BODY, '', ['webhook_secret' => '']));
    }

    // ── ROTATION GRACE — THE WIDENING MUST EXPIRE BY ITSELF ──────────────

    public function test_the_previous_secret_is_accepted_inside_the_window(): void
    {
        $cfg = [
            'webhook_secret'            => self::KEY_B,   // already rotated
            'webhook_secret_previous'   => self::KEY_A,
            'webhook_secret_rotated_at' => date('c', time() - 3600),
        ];
        $this->assertTrue(webhook_signature_valid(
            self::BODY, $this->sign(self::BODY, self::KEY_A), $cfg
        ), 'an in-flight webhook signed with the old secret was rejected during rotation');
    }

    public function test_the_previous_secret_expires(): void
    {
        $cfg = [
            'webhook_secret'            => self::KEY_B,
            'webhook_secret_previous'   => self::KEY_A,
            'webhook_secret_rotated_at' => date('c', time() - 90000),  // > 86400
        ];
        $this->assertFalse(webhook_signature_valid(
            self::BODY, $this->sign(self::BODY, self::KEY_A), $cfg
        ), 'the rotation window never closes — an old secret is trusted forever');
    }

    public function test_a_previous_secret_with_no_timestamp_is_refused(): void
    {
        // Unbounded acceptance. Refusing is the only safe reading.
        $cfg = [
            'webhook_secret'          => self::KEY_B,
            'webhook_secret_previous' => self::KEY_A,
        ];
        $this->assertFalse(webhook_signature_valid(
            self::BODY, $this->sign(self::BODY, self::KEY_A), $cfg
        ));
    }

    public function test_an_unparseable_rotation_timestamp_is_refused(): void
    {
        $cfg = [
            'webhook_secret'            => self::KEY_B,
            'webhook_secret_previous'   => self::KEY_A,
            'webhook_secret_rotated_at' => 'not a date',
        ];
        $this->assertFalse(webhook_signature_valid(
            self::BODY, $this->sign(self::BODY, self::KEY_A), $cfg
        ));
    }

    public function test_the_grace_window_is_honoured_as_given(): void
    {
        // Caller-supplied window, so a shorter one really is shorter.
        $cfg = [
            'webhook_secret'            => self::KEY_B,
            'webhook_secret_previous'   => self::KEY_A,
            'webhook_secret_rotated_at' => date('c', time() - 120),
        ];
        $sig = $this->sign(self::BODY, self::KEY_A);
        $this->assertTrue(webhook_signature_valid(self::BODY, $sig, $cfg, 600));
        $this->assertFalse(webhook_signature_valid(self::BODY, $sig, $cfg, 60));
    }

    public function test_rotation_grace_does_not_accept_an_unrelated_key(): void
    {
        // The window widens to ONE named previous secret, not to any key.
        $cfg = [
            'webhook_secret'            => self::KEY_B,
            'webhook_secret_previous'   => self::KEY_A,
            'webhook_secret_rotated_at' => date('c', time() - 60),
        ];
        $this->assertFalse(webhook_signature_valid(
            self::BODY, $this->sign(self::BODY, 'a_third_key_entirely'), $cfg
        ));
    }

    // ── CROSS-TENANT ─────────────────────────────────────────────────────

    public function test_one_tenants_signature_does_not_authenticate_against_another(): void
    {
        // Each school holds its own webhook secret. A webhook correctly signed
        // for school A must not be accepted by school B — otherwise any tenant
        // could post financial events into any other tenant's books.
        $schoolA = ['webhook_secret' => self::KEY_A];
        $schoolB = ['webhook_secret' => self::KEY_B];
        $sigForA = $this->sign(self::BODY, self::KEY_A);

        $this->assertTrue(webhook_signature_valid(self::BODY, $sigForA, $schoolA));
        $this->assertFalse(webhook_signature_valid(self::BODY, $sigForA, $schoolB),
            'a webhook signed for one tenant authenticated against another');
    }

    // ── REPLAY ───────────────────────────────────────────────────────────

    public function test_signature_validity_alone_does_not_prevent_replay(): void
    {
        // Stated explicitly so nobody mistakes this function for replay
        // protection. The SAME body and signature verify every time — by
        // design, because a gateway legitimately retries. Replay is defeated
        // downstream by the processed-payment marker and event dedup, which
        // ProcessedPaymentIdempotencyTest covers. Conflating the two would
        // leave a real gap while looking covered.
        $sig = $this->sign(self::BODY, self::KEY_A);
        $this->assertTrue(webhook_signature_valid(self::BODY, $sig, $this->cfg()));
        $this->assertTrue(webhook_signature_valid(self::BODY, $sig, $this->cfg()),
            'a valid signature is deterministic — replay defence lives downstream');
    }

    // ── BINARY SAFETY ────────────────────────────────────────────────────

    public function test_a_body_with_multibyte_and_null_bytes_is_handled_exactly(): void
    {
        // Raw body means raw bytes. A verifier that re-encoded or truncated
        // would compute a different HMAC than the provider did.
        $body = "{\"note\":\"\xE2\x82\xB9700 \xF0\x9F\x92\xB0\",\"x\":\"a\0b\"}";
        $this->assertTrue(webhook_signature_valid($body, $this->sign($body, self::KEY_A), $this->cfg()));
        $this->assertFalse(webhook_signature_valid($body . ' ', $this->sign($body, self::KEY_A), $this->cfg()));
    }

    public function test_an_empty_body_still_requires_a_correct_signature(): void
    {
        $this->assertTrue(webhook_signature_valid('', $this->sign('', self::KEY_A), $this->cfg()));
        $this->assertFalse(webhook_signature_valid('', 'deadbeef', $this->cfg()));
    }

    // ══════════════════════════════════════════════════════════════════════
    //  MUTATION ANALYSIS — WHY THREE MUTATIONS SURVIVED, AND WHAT NOW GUARDS
    //  THEM
    // ══════════════════════════════════════════════════════════════════════
    //
    // Four mutations were applied to the verifier. Two were caught by the
    // tests above (grace-window expiry, caller-supplied window). Three
    // survived, and each was investigated rather than accepted:
    //
    //   M1  hash_equals(...) -> (... == ...)
    //       UNOBSERVABLE BY CONSTRUCTION. Both produce identical booleans for
    //       every input; the difference is how long the comparison takes. No
    //       assertion on a return value can ever see it. A structural check is
    //       the correct instrument here — not a lazy substitute for one.
    //
    //   M2  the `$signature === ''` early return removed
    //       EQUIVALENT. An empty signature falls through to hash_equals, which
    //       rejects on length mismatch. The guard is a short-circuit, not the
    //       control.
    //
    //   M4  the "previous secret with no rotation timestamp" refusal removed
    //       EQUIVALENT. strtotime('') returns false, so the next branch refuses
    //       anyway. The guard exists for its explicit log line.
    //
    // M2 and M4 are defence-in-depth. That is worth keeping, but a surviving
    // mutation on a redundant guard is not a defect. What WOULD be a defect is
    // losing the backstop as well, so the backstops are pinned below.

    /** The helper's executable source, with prose removed. */
    private function strippedHelperSource(): string
    {
        $out = '';
        foreach (token_get_all((string) file_get_contents(APPPATH . 'helpers/webhook_signature_helper.php')) as $t) {
            if (is_array($t)) {
                if (in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true)) continue;
                $out .= $t[1];
            } else { $out .= $t; }
        }
        return $out;
    }

    public function test_the_comment_stripper_actually_strips(): void
    {
        // Control: if it did not, the guard above would be counting prose.
        $this->assertStringNotContainsString('Comparisons use hash_equals() throughout',
            $this->strippedHelperSource());
    }

    public function test_the_comparison_is_timing_safe(): void
    {
        // Structural, and stated as such. A webhook signature is attacker-
        // supplied; comparing it with == or strcmp leaks how many leading
        // bytes matched, which is enough to forge one byte at a time.
        // Comment-stripped. The file's own docblock says "Comparisons use
        // hash_equals() throughout", and counting that sentence as evidence
        // would let the guard be satisfied by prose describing the fix.
        $src = $this->strippedHelperSource();
        $this->assertSame(2, substr_count($src, 'hash_equals('),
            'both signature comparisons (current secret and rotation-grace secret) '
            . 'must use hash_equals');
        $this->assertDoesNotMatchRegularExpression(
            '/hash_hmac\([^)]*\)\s*(===?|!==?)\s*\$signature/',
            $src,
            'a signature comparison uses a plain operator — that is timing-observable'
        );
    }

    public function test_the_backstop_for_an_empty_signature_holds(): void
    {
        // The property M2 relies on. If hash_equals ever accepted a
        // length-mismatched pair, removing the early return would become a
        // real hole rather than an equivalent mutation.
        $this->assertFalse(hash_equals(hash_hmac('sha256', self::BODY, self::KEY_A), ''),
            'hash_equals no longer rejects on length mismatch — the empty-signature '
            . 'early return is now load-bearing and must not be removed');
    }

    public function test_the_backstop_for_a_missing_rotation_timestamp_holds(): void
    {
        // The property M4 relies on. If strtotime('') ever returned a number,
        // an absent rotation timestamp would be read as an epoch date and the
        // grace window would be evaluated against 1970 — permanently expired,
        // which is safe — or, worse, against "now" depending on the value.
        $this->assertFalse(strtotime(''),
            'strtotime("") no longer returns false — the explicit empty-timestamp '
            . 'refusal is now load-bearing and must not be removed');
    }

    public function test_both_defence_in_depth_guards_are_still_present(): void
    {
        // They survive mutation individually, so nothing else would notice
        // their removal. Losing one is harmless; losing a layer silently is
        // how the next change removes the other one too.
        $src = '';
        foreach (token_get_all((string) file_get_contents(APPPATH . 'helpers/webhook_signature_helper.php')) as $t) {
            if (is_array($t)) {
                if (in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true)) continue;
                $src .= $t[1];
            } else { $src .= $t; }
        }
        $this->assertStringContainsString("if (\$signature === '') return false;", $src,
            'the empty-signature short-circuit was removed');
        $this->assertMatchesRegularExpression("/if\s*\(\s*\\\$rotatedAt\s*===\s*''\s*\)/", $src,
            'the empty rotation-timestamp refusal was removed');
    }
}
