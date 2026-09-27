<?php

use PHPUnit\Framework\TestCase;

if (!defined('APPPATH')) define('APPPATH', __DIR__ . '/../../application/');
if (!defined('BASEPATH')) define('BASEPATH', 'x');

require_once APPPATH . 'libraries/Receipt_number.php';

/**
 * ══════════════════════════════════════════════════════════════════════════
 *  RECEIPT IDENTITY vs RECEIPT NUMBER
 * ══════════════════════════════════════════════════════════════════════════
 *
 * ── THE COLLISION THIS EXISTS TO PREVENT ─────────────────────────────────
 * Indian practice (Rule 46(b), CGST Rules) wants a serial that is unique per
 * financial year, restarting each year. ZenX's persisted key was 'F' plus the
 * receipt number. Reset the number and the key resets with it:
 *
 *     2026-27  receipt 1  ->  feeReceipts/SCH_X_F1
 *     2027-28  receipt 1  ->  feeReceipts/SCH_X_F1   <-- SAME DOCUMENT
 *
 * The second write would overwrite a historical receipt — a different
 * student, a different amount, silently, with no error. The books would still
 * reference JE_FEE_F1, now pointing at the wrong payment.
 *
 * So the two identifiers are separated: the internal key never resets, the
 * accounting number does. These tests assert both halves and, most
 * importantly, that they cannot collide.
 */
final class ReceiptNumberIdentityTest extends TestCase
{
    // ── THE COLLISION ────────────────────────────────────────────────────

    public function test_internal_keys_never_collide_across_sessions(): void
    {
        // The lifetime sequence keeps advancing, so a new session's first
        // receipt is NOT key F1 again.
        $lastOf202627  = Receipt_number::internalKey(1);
        $firstOf202728 = Receipt_number::internalKey(2);
        $this->assertNotSame($lastOf202627, $firstOf202728,
            'two receipts share an internal key — one would overwrite the other');
    }

    public function test_display_numbers_deliberately_do_repeat_across_sessions(): void
    {
        // This repetition is CORRECT and is the whole reason the key must not
        // follow it. Each year restarts at 000001.
        $this->assertSame('2026-27/000001', Receipt_number::displayNumber('2026-27', 1));
        $this->assertSame('2027-28/000001', Receipt_number::displayNumber('2027-28', 1));
    }

    public function test_the_two_counters_are_distinct_kinds(): void
    {
        // Sharing one counter is how the collision comes back.
        $this->assertNotSame(
            Receipt_number::KIND_LIFETIME,
            Receipt_number::sessionCounterKind('2026-27')
        );
        $this->assertNotSame(
            Receipt_number::sessionCounterKind('2026-27'),
            Receipt_number::sessionCounterKind('2027-28'),
            'both sessions share a display counter, so the series would not restart'
        );
    }

    // ── RULE 46(b) CONFORMANCE ───────────────────────────────────────────

    public function test_the_serial_stays_within_sixteen_characters(): void
    {
        $this->assertLessThanOrEqual(16, strlen(Receipt_number::displayNumber('2026-27', 999999)));
    }

    public function test_an_over_long_session_label_degrades_rather_than_emitting_a_bad_serial(): void
    {
        // Better an unlabelled serial than one that breaches the cap.
        $out = Receipt_number::displayNumber('20262027-20272028', 1);
        $this->assertLessThanOrEqual(16, strlen($out));
        $this->assertSame('000001', $out);
    }

    public function test_the_serial_uses_only_permitted_characters(): void
    {
        // Rule 46(b) permits alphanumerics, hyphen and slash.
        $this->assertMatchesRegularExpression('#^[A-Za-z0-9\-/]+$#',
            Receipt_number::displayNumber('2026-27', 42));
    }

    public function test_the_sequence_is_zero_padded_so_it_sorts(): void
    {
        $a = Receipt_number::displayNumber('2026-27', 9);
        $b = Receipt_number::displayNumber('2026-27', 10);
        $this->assertSame('2026-27/000009', $a);
        $this->assertTrue($a < $b, 'unpadded serials sort wrongly as strings');
    }

    // ── SESSION NORMALISATION ────────────────────────────────────────────

    public function test_session_labels_normalise_to_one_form(): void
    {
        foreach (['2026-27', '2026-2027', '2026 - 27', '2026/27'] as $in) {
            $this->assertSame('2026-27', Receipt_number::normaliseSession($in), "failed for '{$in}'");
        }
    }

    public function test_an_unusable_session_label_yields_nothing_rather_than_a_guess(): void
    {
        // Inventing a financial year on a receipt is worse than omitting it.
        $this->assertSame('', Receipt_number::normaliseSession('Academic Year Twenty Twenty Six'));
        $this->assertSame('000001', Receipt_number::displayNumber('Academic Year Twenty Twenty Six', 1));
    }

    // ── DISPLAY PRECEDENCE + HISTORICAL RECEIPTS ─────────────────────────

    public function test_the_stored_accounting_number_wins_when_present(): void
    {
        $this->assertSame('2026-27/000001', Receipt_number::forDisplay([
            'receiptDisplayNo' => '2026-27/000001',
            'receiptKey'       => 'F1',
            'receiptNo'        => '1',
        ]));
    }

    public function test_a_historical_receipt_shows_its_key_and_is_not_retro_numbered(): void
    {
        // F1 predates the display series. It must NOT be assigned one on the
        // fly: a stored financial identifier is not rewritten, and a derived
        // one could disagree with what the office already filed.
        $this->assertSame('F1', Receipt_number::forDisplay([
            'receiptKey' => 'F1',
            'receiptNo'  => '1',
            'session'    => '2026-27',
        ]));
    }

    public function test_a_malformed_receipt_degrades_rather_than_rendering_blank(): void
    {
        $this->assertSame('F7', Receipt_number::forDisplay(['receiptNo' => '7']));
        $this->assertSame('', Receipt_number::forDisplay([]));
    }

    public function test_the_internal_key_keeps_its_fee_marker(): void
    {
        // 'F' distinguishes a fee receipt from an admission receipt, which
        // numbers separately. Dropping it makes '1' ambiguous across types.
        $this->assertSame('F1', Receipt_number::internalKey(1));
        $this->assertStringStartsWith('F', Receipt_number::internalKey(12345));
    }
}
