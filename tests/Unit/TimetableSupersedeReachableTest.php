<?php

use PHPUnit\Framework\TestCase;

/**
 * UI REACHABILITY for ending a published timetable.
 *
 * Provenance: UAT 2026-09-12. Publishing a second timetable was refused with
 * "A school can have only one timetable in force on any day... end the
 * existing timetable first." The existing one ran "from 2026-08-30 onwards"
 * with no end date, so EVERY later date overlapped and no timetable could ever
 * be published again.
 *
 * `Timetable_lifecycle::supersede()` — the endpoint that sets that end date —
 * already existed, was permissioned (`manage`) and audited, and had a route.
 * Only the button was missing, so the refusal instructed the operator to do
 * something the screen could not do.
 *
 * A route plus a controller is NOT a feature: the chain from a control the
 * operator can click through to the endpoint has to be whole. This test pins
 * every link of it, because each one alone passes while the feature stays
 * unreachable.
 */
final class TimetableSupersedeReachableTest extends TestCase
{
    private static string $view   = '';
    private static string $routes = '';
    private static string $ctrl   = '';

    public static function setUpBeforeClass(): void
    {
        $root = __DIR__ . '/../..';
        self::$view   = (string) file_get_contents($root . '/application/views/timetable/lifecycle.php');
        self::$routes = (string) file_get_contents($root . '/application/config/routes.php');
        self::$ctrl   = (string) file_get_contents($root . '/application/controllers/Timetable_lifecycle.php');
    }

    /** The back half: route → controller → store. This half always existed. */
    public function test_the_endpoint_half_of_the_chain_exists(): void
    {
        $this->assertStringContainsString(
            "\$route['timetable/supersede']", self::$routes,
            'the supersede route is gone');
        $this->assertMatchesRegularExpression(
            '/public function supersede\(\)/', self::$ctrl,
            'the supersede controller action is gone');
        $this->assertStringContainsString('supersedeVersion(', self::$ctrl,
            'supersede() no longer reaches the store');
    }

    /**
     * The front half: a control the operator can actually click. This is the
     * half that was missing, and the half a route-only test would never catch.
     */
    public function test_a_control_exists_that_the_operator_can_click(): void
    {
        $this->assertStringContainsString('data-supersede="', self::$view,
            'no button emits data-supersede — the endpoint is unreachable from the screen');

        // It must be registered in the delegated click selector, or the button
        // renders and does nothing at all.
        $this->assertMatchesRegularExpression(
            '/closest\([^)]*\[data-supersede\]/', self::$view,
            'data-supersede is not in the delegated click selector, so the button is inert');

        $this->assertStringContainsString('btn.dataset.supersede', self::$view,
            'nothing handles the supersede click');
    }

    /** The middle: the modal and the form that posts to the endpoint. */
    public function test_the_modal_posts_to_the_endpoint(): void
    {
        $this->assertStringContainsString('id="tt_supersede_modal"', self::$view,
            'the supersede modal is gone');
        $this->assertStringContainsString('id="tt_supersede_form"', self::$view,
            'the supersede form is gone');
        $this->assertStringContainsString("api('timetable/supersede'", self::$view,
            'the form no longer posts to timetable/supersede');

        // The endpoint refuses without a last date, so the form must collect one.
        $this->assertStringContainsString('last_effective_date:', self::$view,
            'the form does not send last_effective_date, which the endpoint requires');
        $this->assertStringContainsString('id="tt_supersede_last"', self::$view,
            'there is no date input for the last day');
    }

    /**
     * The form must demand what the server demands.
     *
     * `supersedeVersion()` throws on an empty reason — "the reason is the only
     * record of it afterwards". The modal first shipped labelling that field
     * "(optional)", so the operator filled in the date, submitted, and was
     * refused by the server for a field the screen had told them to skip
     * (UAT 2026-09-12). A required server field marked optional in the UI is a
     * broken contract, not a validation message.
     */
    public function test_every_field_the_server_requires_is_required_in_the_form(): void
    {
        $store = (string) file_get_contents(
            __DIR__ . '/../../application/libraries/Timetable_plan_store.php');

        // Establish that the server really does refuse an empty reason, so this
        // test tracks the contract rather than asserting a UI preference.
        $i = strpos($store, 'function supersedeVersion');
        $this->assertNotFalse($i, 'supersedeVersion is gone');
        $body = substr($store, $i, 1200);
        $this->assertMatchesRegularExpression(
            '/\$reason\s*===\s*\x27\x27/', $body,
            'the store no longer refuses an empty reason — re-check whether the form should still require one');

        // Therefore the input must be required.
        $j = strpos(self::$view, 'id="tt_supersede_reason"');
        $this->assertNotFalse($j, 'the reason input is gone');
        $field = substr(self::$view, $j, 260);
        $this->assertStringContainsString('required', $field,
            'the reason input is not marked required, but the server refuses an empty one');

        // And it must not be advertised as optional. Strip HTML comments first:
        // the comment explaining this very fix contains the word "optional",
        // and asserting over comments is the substring collision that has
        // produced false results in this codebase before.
        $labelWindow = substr(self::$view, max(0, $j - 500), 500);
        $labelWindow = (string) preg_replace('/<!--.*?-->/s', '', $labelWindow);
        $this->assertStringNotContainsStringIgnoringCase('optional', $labelWindow,
            'the reason is labelled optional but the server requires it');
    }

    /**
     * The date field must refuse exactly what the server refuses.
     *
     * supersedeVersion() rejects any last-day before `date('Y-m-d')`, because
     * ending a timetable in the past rewrites which one applied on days that
     * already happened — days with attendance marked against them. The field
     * first shipped with no `min`, so a past date could be picked and was only
     * refused after submitting (UAT 2026-09-12).
     *
     * And the bound must come from the SERVER's date, not the browser's UTC
     * clock: this server runs Europe/Berlin, so `toISOString()` returns
     * yesterday between midnight and 02:00 and would offer a date the server
     * then rejects. That exact trap has already cost this codebase once.
     */
    public function test_the_last_day_field_cannot_offer_a_date_the_server_will_refuse(): void
    {
        $store = (string) file_get_contents(
            __DIR__ . '/../../application/libraries/Timetable_plan_store.php');
        $this->assertStringContainsString('must be today or later', $store,
            'the server no longer refuses a past end date — re-check whether the field still needs a min');

        $i = strpos(self::$view, 'id="tt_supersede_last"');
        $this->assertNotFalse($i, 'the last-day input is gone');
        $field = substr(self::$view, $i, 200);

        $this->assertStringContainsString('min="', $field,
            'the last-day input has no min, so a past date can be picked and is only refused after submitting');
        $this->assertStringContainsString("min=\"<?= date('Y-m-d') ?>\"", $field,
            'the min must be the SERVER date, which is what the validator compares against');

        // The UTC trap, stated as an assertion so it cannot creep back in.
        $this->assertStringNotContainsString('toISOString', $field,
            'the bound is being computed from the browser UTC clock, which yields '
            . 'yesterday in a positive-offset timezone');
    }

    /**
     * Offered only where it is the actual remedy: an open-ended published
     * timetable. One that already has an end date blocks nothing, and a
     * proposal has no dates at all.
     */
    public function test_the_control_is_offered_only_for_an_open_ended_published_timetable(): void
    {
        $i = strpos(self::$view, 'data-supersede="');
        $this->assertNotFalse($i);
        // Wide enough to span the enclosing published/CAN_EDIT guard and the
        // comment between it and the button.
        $window = substr(self::$view, max(0, $i - 1600), 1700);

        $this->assertStringContainsString("st === 'published'", $window,
            'the end-date control is not restricted to published timetables');
        $this->assertStringContainsString('!v.effectiveTo', $window,
            'the control is offered even when the timetable already has an end date');
        $this->assertStringContainsString('CAN_EDIT', $window,
            'the control is not gated on edit permission');
    }
}
