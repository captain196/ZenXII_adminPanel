<?php
/**
 * The boarding event-type classification — one source of truth (2026-08-16).
 *
 * WHY THIS TEST EXISTS. `transportAttendance.event_type` has EIGHT valid values:
 * the four the pre-F14 tap-to-board app wrote, and the four leg-explicit ones
 * the QR attendance service writes. Two separate head-count consumers —
 * `Transport_service`'s trip tallies and `Transport_analytics_service`'s live
 * operations snapshot — each kept their OWN hardcoded list, and both listed
 * only the legacy four.
 *
 * The effect was invisible until a conductor actually scanned: a trip boarded by
 * QR counted as ZERO students in the trip modal and on the operations dashboard,
 * so the office would lose its head count at precisely the moment the feature
 * started being used. Nothing failed; the numbers were simply wrong.
 *
 * These tests pin the classification and, just as importantly, pin the fact that
 * consumers DERIVE from it instead of retyping it.
 */

use PHPUnit\Framework\TestCase;

final class TransportBoardingVocabularyTest extends TestCase
{
    private const SERVICE   = __DIR__ . '/../../application/libraries/Transport_service.php';
    private const ANALYTICS = __DIR__ . '/../../application/libraries/Transport_analytics_service.php';
    private const QR        = __DIR__ . '/../../application/libraries/Transport_qr_attendance_service.php';

    /** The three constants, read out of the source without booting CodeIgniter. */
    private function constantList(string $name): array
    {
        $src = (string) file_get_contents(self::SERVICE);
        $this->assertNotSame('', $src, 'Transport_service.php unreadable');
        if (!preg_match('/public const ' . $name . ' = \[(.*?)\];/s', $src, $m)) {
            $this->fail("Transport_service::{$name} not found — the classification was removed or renamed");
        }
        preg_match_all("/'([a-z_]+)'/", $m[1], $vals);
        return $vals[1];
    }

    // ── the classification itself ────────────────────────────────────────

    public function test_every_journey_event_is_classified_as_on_or_off(): void
    {
        $all = $this->constantList('BOARDING_EVENT_TYPES');
        $on  = $this->constantList('BOARDING_ON_EVENTS');
        $off = $this->constantList('BOARDING_OFF_EVENTS');

        $this->assertCount(8, $all, 'the enum should still hold the legacy four + the F14 four');

        foreach ($all as $event) {
            // 'no_show' is a RESOLUTION, not a journey event — it belongs to
            // neither set, and counting it as either would inflate the head count.
            if ($event === 'no_show') {
                $this->assertNotContains($event, $on);
                $this->assertNotContains($event, $off);
                continue;
            }
            $this->assertTrue(
                in_array($event, $on, true) || in_array($event, $off, true),
                "event_type '{$event}' is classified as neither boarding nor alighting — "
                . 'a head count that ignores it is silently wrong'
            );
        }
    }

    public function test_the_two_sets_are_disjoint(): void
    {
        $on  = $this->constantList('BOARDING_ON_EVENTS');
        $off = $this->constantList('BOARDING_OFF_EVENTS');
        $this->assertSame([], array_values(array_intersect($on, $off)),
            'an event counted as BOTH getting on and getting off would cancel itself out');
    }

    public function test_both_vocabularies_are_represented_in_each_direction(): void
    {
        $on  = $this->constantList('BOARDING_ON_EVENTS');
        $off = $this->constantList('BOARDING_OFF_EVENTS');

        // Legacy — historical rows carry these and are never rewritten.
        $this->assertContains('boarded_pickup', $on);
        $this->assertContains('alternate_pickup', $on);
        $this->assertContains('dropped_home', $off);

        // F14 canonical — what a QR scan actually writes today. Their absence
        // was the defect.
        $this->assertContains('boarded_at_stop', $on, 'a morning QR boarding must count');
        $this->assertContains('boarded_at_school', $on, 'an afternoon QR boarding must count');
        $this->assertContains('alighted_at_school', $off, 'arriving at school must count as off');
        $this->assertContains('alighted_at_stop', $off, 'being dropped at a stop must count as off');
    }

    // ── consumers must derive, not retype ────────────────────────────────

    public function test_trip_tallies_derive_from_the_constants(): void
    {
        $src = (string) file_get_contents(self::SERVICE);
        foreach (['_tallyBoardingEvents', '_tallyBoardingEventsByStop'] as $fn) {
            $this->assertTrue(
                (bool) preg_match('/function ' . $fn . '\(.*?\n    \}/s', $src, $m),
                "{$fn}() not found"
            );
            $body = $m[0];
            $this->assertMatchesRegularExpression(
                '/BOARDING_(ON|OFF)_EVENTS/', $body,
                "{$fn}() no longer derives from the shared classification"
            );
            // A retyped literal is the regression this test exists to catch.
            $this->assertDoesNotMatchRegularExpression(
                "/=== '(boarded_pickup|dropped_home|alternate_pickup)'/", $body,
                "{$fn}() compares a hardcoded legacy event type again — "
                . 'F14 events would stop counting'
            );
        }
    }

    public function test_live_operations_snapshot_derives_from_the_constants(): void
    {
        $src = (string) file_get_contents(self::ANALYTICS);
        $this->assertStringContainsString('Transport_service::BOARDING_ON_EVENTS', $src,
            'the live operations snapshot must not keep its own copy of the classification');
        $this->assertStringContainsString('Transport_service::BOARDING_OFF_EVENTS', $src);
        $this->assertDoesNotMatchRegularExpression(
            "/\\\$boardEvents\s*=\s*\[/", $src,
            'the snapshot retyped the event list again');
    }

    // ── the QR service stays the only boarding authority ─────────────────

    public function test_bus_boarding_never_touches_classroom_attendance(): void
    {
        // A child who boarded a bus is not thereby present in class. These are
        // separate modules with separate authorities, and a boarding that wrote
        // a classroom record would be a false attendance mark.
        $src = (string) file_get_contents(self::QR);
        $this->assertDoesNotMatchRegularExpression(
            "/(setEntity|->set)\s*\(\s*['\"]attendance['\"]/", $src,
            'the QR attendance service writes the classroom attendance collection');
        $this->assertStringContainsString('transportAttendance', $src,
            'the QR service should read/write the transport attendance log');
    }
}
