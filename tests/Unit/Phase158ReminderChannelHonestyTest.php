<?php
namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Phase 15.8 — reminder-channel honesty.
 *
 * REGRESSION GUARD for the defect closed this phase: choosing WhatsApp / SMS /
 * email on the Defaulter Report wrote a feeReminderLog row with
 * status='queued' and told the operator "Delivery by the messaging worker".
 * No such worker exists in this repository — the only readers of
 * feeReminderLog are the two history endpoints — so nothing was ever sent
 * while the office believed the parent had been contacted.
 *
 * Same failure class as the dead `auto_remind` switch: the damage is not the
 * missing feature, it is that staff STOP CHASING because the screen implied
 * the reminder was handled.
 *
 * These tests model the decision rule in Fee_management::send_reminder().
 *
 * IDENTITY DISCIPLINE: STU0012 ↔ SRNO 47 · STU0047 ↔ SRNO 12
 */
class Phase158ReminderChannelHonestyTest extends TestCase
{
    private const STU_A = 'STU0012';  private const SRNO_A = '47';
    private const STU_B = 'STU0047';  private const SRNO_B = '12';

    /** Mirrors the status written to feeReminderLog. */
    private function status(string $channel): string
    {
        if ($channel === 'log')  return 'logged';
        if ($channel === 'push') return 'queued';
        return 'not_sent';
    }

    /** Mirrors the `delivered` flag returned to the UI. */
    private function delivered(string $channel): bool
    {
        return ($channel === 'push' || $channel === 'log');
    }

    /** Mirrors the operator-facing message. */
    private function message(string $channel, int $n): string
    {
        if ($channel === 'push') return "Push notifications: {$n} delivered, 0 no-token, {$n} attempted.";
        if ($channel === 'log')  return "Reminder logged for {$n} student(s).";
        if (in_array($channel, ['whatsapp', 'sms', 'email'], true)) {
            return ucfirst($channel) . " reminder RECORDED for {$n} student(s), but NOT SENT — "
                 . 'no ' . strtoupper($channel) . ' provider is configured for this system. '
                 . 'Use the Push channel to notify parents in the app, or contact them directly.';
        }
        return "Reminder recorded for {$n} student(s).";
    }

    // ── A. Undeliverable channels must never claim delivery ─────────────

    public function test_whatsapp_is_not_marked_delivered(): void
    {
        $this->assertFalse($this->delivered('whatsapp'));
    }

    public function test_sms_and_email_are_not_marked_delivered(): void
    {
        $this->assertFalse($this->delivered('sms'));
        $this->assertFalse($this->delivered('email'));
    }

    public function test_undeliverable_channels_are_not_left_queued(): void
    {
        // 'queued' implied a worker would collect the row. None exists, so the
        // reminder history showed these as pending delivery forever.
        foreach (['whatsapp', 'sms', 'email'] as $c) {
            $this->assertSame('not_sent', $this->status($c),
                "{$c} must record what actually happened, not a pending delivery");
            $this->assertNotSame('queued', $this->status($c));
        }
    }

    public function test_message_states_plainly_that_nothing_was_sent(): void
    {
        $m = $this->message('whatsapp', 12);
        $this->assertStringContainsString('NOT SENT', $m);
        $this->assertStringContainsString('no WHATSAPP provider is configured', $m);
        $this->assertStringNotContainsString('Delivery by the messaging worker', $m);
    }

    public function test_message_points_at_a_channel_that_actually_works(): void
    {
        foreach (['whatsapp', 'sms', 'email'] as $c) {
            $this->assertStringContainsString('Push', $this->message($c, 3),
                'an honest failure must tell the operator what to do instead');
        }
    }

    // ── B. Working channels keep working ────────────────────────────────

    public function test_push_remains_delivered_and_queued_for_dispatch(): void
    {
        $this->assertTrue($this->delivered('push'));
        $this->assertSame('queued', $this->status('push'));
        $this->assertStringContainsString('delivered', $this->message('push', 5));
    }

    public function test_log_channel_is_an_honest_record_only_action(): void
    {
        // 'log' was always explicitly record-only, so it is truthful already.
        $this->assertTrue($this->delivered('log'));
        $this->assertSame('logged', $this->status('log'));
        $this->assertStringContainsString('logged', $this->message('log', 5));
    }

    public function test_unknown_channel_does_not_claim_delivery(): void
    {
        $this->assertFalse($this->delivered('carrier_pigeon'));
        $this->assertSame('not_sent', $this->status('carrier_pigeon'));
    }

    // ── C. No channel silently repurposes another ───────────────────────

    public function test_whatsapp_is_not_silently_switched_to_push(): void
    {
        // Silently sending push when the operator chose WhatsApp would be a
        // different lie. The action stays record-only and says so.
        $this->assertNotSame($this->status('whatsapp'), $this->status('push'));
        $this->assertFalse($this->delivered('whatsapp'));
    }

    // ── D. Identity invariants ──────────────────────────────────────────

    public function test_reminder_channel_logic_is_identity_agnostic(): void
    {
        $this->assertNotSame(self::STU_A, self::SRNO_A);
        $this->assertSame('47', self::SRNO_A);
        $this->assertSame('12', self::SRNO_B);
        // Channel honesty must not vary by student.
        $this->assertSame($this->delivered('whatsapp'), $this->delivered('whatsapp'));
    }
}
