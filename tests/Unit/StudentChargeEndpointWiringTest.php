<?php

use PHPUnit\Framework\TestCase;

/**
 * ══════════════════════════════════════════════════════════════════════════
 *  SC-LOAD-1 — THE ENDPOINT HAD NEVER ACTUALLY BEEN RUN
 * ══════════════════════════════════════════════════════════════════════════
 *
 * The first real request to `raise_student_charge()` died with:
 *
 *     Type: Error
 *     Message: Class "Student_charge_service" not found
 *     Fee_management.php:3302
 *
 * `Student_charge_service::DEFAULT_DUE_DAYS` was read on the line ABOVE the
 * `_init_student_charge_service()` call that loads the class. CodeIgniter loads
 * libraries on demand, and a **static constant access does not trigger that
 * load** — so the class genuinely did not exist at that point.
 *
 * ── WHY 28 PASSING TESTS DID NOT CATCH IT ─────────────────────────────────
 * Every one of them drove `Student_charge_service` DIRECTLY, constructing it in
 * the test and injecting doubles. None went through CI's loader, and none
 * invoked the controller. The service was thoroughly tested; the wiring between
 * the endpoint and the service was never executed at all.
 *
 * That is the same shape as the missing UI: routes existed, tests passed, and
 * nothing had ever travelled the real path end to end. A unit test proves a
 * unit; only the wired path proves the wiring.
 *
 * ── WHAT THIS FILE ASSERTS ────────────────────────────────────────────────
 * Not "the constant is on the right line" — that would break on any reordering.
 * It asserts the ORDERING PROPERTY: in every endpoint that touches the service,
 * the library load must come before any use of the class, static or otherwise.
 */
class StudentChargeEndpointWiringTest extends TestCase
{
    private static string $src = '';

    public static function setUpBeforeClass(): void
    {
        self::$src = (string) file_get_contents(APPPATH . 'controllers/Fee_management.php');
    }

    /** One endpoint's body, bounded by the next method rather than a fixed window. */
    private function endpoint(string $name): string
    {
        $at = strpos(self::$src, "public function {$name}(");
        $this->assertNotFalse($at, "{$name}() was renamed or removed");

        $end = strpos(self::$src, "\n    public function ", $at + 30);
        $body = substr(self::$src, $at, ($end !== false ? $end - $at : 9000));

        // Comments explain this very defect and name the class — a raw scan
        // would match the note describing the fix rather than the code.
        $body = (string) preg_replace('!^\s*//.*$!m', '', $body);
        return (string) preg_replace('!/\*.*?\*/!s', '', $body);
    }

    // ══ THE PREMISE ═════════════════════════════════════════════════════════

    /**
     * The loader must exist and must actually load the library. If it stopped
     * doing that, ordering would be irrelevant and every assertion below would
     * pass while the endpoint stayed broken.
     */
    public function test_control_the_initialiser_loads_the_library(): void
    {
        $at = strpos(self::$src, 'private function _init_student_charge_service');
        $this->assertNotFalse($at, 'the initialiser was renamed');
        $body = substr(self::$src, $at, 900);

        $this->assertStringContainsString(
            "\$this->load->library('Student_charge_service', null, 'studentCharge');", $body,
            'the initialiser no longer loads the library, so the class would never exist '
            . 'however the endpoint is ordered');
        $this->assertStringContainsString('$this->studentCharge->init(', $body,
            'the service is loaded but never initialised — it would refuse every call');
    }

    // ══ SC-LOAD-1 ═══════════════════════════════════════════════════════════

    /**
     * WOULD HAVE FAILED BEFORE: the constant was read one line before the load.
     *
     * Asserted as an ordering property rather than a line number, so it keeps
     * protecting through any future reshuffle of this method.
     */
    public function test_the_service_is_loaded_before_any_static_use_of_it(): void
    {
        foreach (['raise_student_charge', 'waive_student_charge', 'list_student_charges'] as $fn) {
            $body = $this->endpoint($fn);

            $staticAt = strpos($body, 'Student_charge_service::');
            if ($staticAt === false) continue;   // nothing static to order against

            $initAt = strpos($body, '$this->_init_student_charge_service();');
            $this->assertNotFalse($initAt,
                "{$fn}() uses Student_charge_service statically but never loads it. "
                . 'CodeIgniter loads libraries on demand and a static constant access does '
                . 'not trigger that load, so the class does not exist at that point.');

            $this->assertLessThan($staticAt, $initAt,
                "{$fn}() reads Student_charge_service:: BEFORE loading the class. That is a "
                . 'fatal `Class "Student_charge_service" not found` on the first real request '
                . '— exactly what SC-LOAD-1 was.');
        }
    }

    /** Every endpoint that uses the service instance must load it first too. */
    public function test_the_service_is_loaded_before_the_instance_is_used(): void
    {
        foreach (['raise_student_charge', 'waive_student_charge', 'list_student_charges'] as $fn) {
            $body = $this->endpoint($fn);

            $useAt = strpos($body, '$this->studentCharge->');
            if ($useAt === false) continue;

            $initAt = strpos($body, '$this->_init_student_charge_service();');
            $this->assertNotFalse($initAt, "{$fn}() uses \$this->studentCharge without loading it");
            $this->assertLessThan($useAt, $initAt,
                "{$fn}() calls \$this->studentCharge-> before the initialiser runs");
        }
    }

    // ══ THE VALIDATION MUST STILL COME FIRST ════════════════════════════════

    /**
     * Loading the service earlier must not have moved it ahead of the input
     * validation. A malformed request should be refused before any collaborator
     * is constructed — cheaper, and it keeps the failure a clean 400-style
     * refusal rather than a loader error.
     */
    public function test_input_is_still_validated_before_the_service_is_built(): void
    {
        $body = $this->endpoint('raise_student_charge');

        $initAt   = strpos($body, '$this->_init_student_charge_service();');
        $amountAt = strpos($body, "if (\$amount <= 0) \$this->json_error(");
        $reasonAt = strpos($body, "if (\$reason === '')");

        $this->assertNotFalse($amountAt, 'the amount guard is gone');
        $this->assertNotFalse($reasonAt, 'the reason guard is gone');
        $this->assertLessThan($initAt, $amountAt,
            'the service is now constructed before the amount is validated, so a junk request '
            . 'builds collaborators it will never use');
        $this->assertLessThan($initAt, $reasonAt);
    }

    // ══ THE SCREEN THAT REACHES IT ══════════════════════════════════════════

    /**
     * The endpoints shipped with routes, tests and **no screen**. A feature
     * that can only be reached by hand-crafting a POST with a CSRF token is not
     * reachable, and this project has been bitten by exactly that before
     * (`/compensation/fnf` 404'd while its tests passed).
     *
     * Menu + route + controller + view — all four, or an operator cannot use it.
     */
    public function test_the_feature_is_reachable_from_the_ui(): void
    {
        $this->assertStringContainsString('public function student_charges()', self::$src,
            'the page controller is gone, so the route resolves to nothing');

        $this->assertFileExists(APPPATH . 'views/fee_management/student_charges.php',
            'the Student Charges view is missing — the route would fatal on a missing view');

        $routes = (string) file_get_contents(APPPATH . 'config/routes.php');
        $this->assertStringContainsString("\$route['fee_management/student_charges']", $routes,
            'the page route is gone — the URL would 404');

        $dash = (string) file_get_contents(APPPATH . 'views/fees/dashboard.php');
        $this->assertStringContainsString("base_url('fee_management/student_charges')", $dash,
            'the Fees dashboard no longer links to Student Charges, so the page exists but '
            . 'nobody can find it — reachable by URL is not reachable by an operator');
    }

    /**
     * The page must not offer charges against a student whose fee book is
     * frozen: the demand would be uncollectable and, since FRZ-1, would not
     * even attract a late fee.
     */
    public function test_the_picker_offers_only_active_students(): void
    {
        $at = strpos(self::$src, 'public function student_charges()');
        $this->assertNotFalse($at);
        $body = substr(self::$src, $at, 2600);

        $this->assertStringContainsString("strcasecmp(\$status, 'Active') !== 0) continue;", $body,
            'the student picker no longer filters to Active students, so an operator can '
            . 'raise a charge against a withdrawn student whose fee book is frozen');
    }

    /**
     * The panel JS must fail closed on BOTH counts: `fetch()` does not reject
     * on 403/500, and this API reports business refusals as HTTP 200 with
     * `{status:'error'}`. Checking one alone reports a refused charge as raised.
     */
    public function test_the_panel_js_fails_closed_on_both_signals(): void
    {
        $view = (string) file_get_contents(APPPATH . 'views/fee_management/student_charges.php');

        // Assert the SIGNALS the failure predicate covers, not its syntax.
        // This test has now been rewritten twice because it pinned an exact
        // expression shape and the shape improved underneath it — first when
        // the body began to be parsed before the status was judged, then when
        // three separate guards were folded into one predicate. Neither change
        // weakened anything; the test was simply over-specified.
        $at = strpos($view, 'var failed =');
        $this->assertNotFalse($at,
            'the panel no longer computes an explicit failure predicate — re-derive how it '
            . 'decides success before trusting it');

        $predicate = substr($view, $at, 160);

        foreach ([
            '!r.ok'              => 'the HTTP status is ignored, so a 403 or 500 with no body '
                                  . 'would be treated as success',
            '!d'                 => 'an unparseable body is treated as success',
            "d.status === 'error'" => "the {status:'error'} envelope is ignored — this API "
                                  . 'answers business refusals with HTTP 200, so a refused '
                                  . 'charge would report as raised',
        ] as $signal => $why) {
            $this->assertStringContainsString($signal, $predicate,
                'the failure check omits `' . $signal . '`: ' . $why);
        }
    }

    /**
     * SC-SUCCESS-1 — a SUCCESSFUL charge was reported as a failure.
     *
     * The panel threw on any response carrying a `message`, and the success
     * envelope carries one too ("Charge of 350.00 raised for this student.").
     * So a charge that really was raised surfaced as an error and the list
     * never refreshed — `list_student_charges` was never even requested.
     *
     * Phantom FAILURE, the mirror of the phantom-success trap this codebase
     * already documents. Both come from reading one signal instead of the pair.
     */
    public function test_a_success_response_carrying_a_message_is_not_treated_as_failure(): void
    {
        $view = (string) file_get_contents(APPPATH . 'views/fee_management/student_charges.php');

        $this->assertStringContainsString(
            "var failed = !r.ok || !d || d.status === 'error';", $view,
            'the panel decides failure from something other than the status pair. If it throws '
            . 'on the presence of a message, a SUCCESSFUL charge reports as an error and the '
            . 'charge list never reloads.');

        $this->assertStringNotContainsString('if (d && d.message) throw new Error(d.message);', $view,
            'the panel throws on any message field again — success responses carry one');

        // The server's wording must still win when it genuinely IS a failure.
        $this->assertStringContainsString(
            "throw new Error((d && d.message) || ('Server returned ' + r.status));", $view,
            "a real refusal no longer shows the server's explanation, so an operator is back "
            . 'to a bare status code');
    }

    /**
     * The Paid column reads from the DEMAND, because a charge record does not
     * carry payment state. Without it the panel could not tell a settled charge
     * from an unpaid one.
     */
    public function test_the_list_reports_payment_from_the_demand(): void
    {
        $this->assertStringContainsString(
            "'paidAmount' => \$this->_chargePaidAmount((string) (\$d['demandId'] ?? '')),", self::$src,
            'the charge list no longer reports how much has been collected, so the panel '
            . 'offers Waive on a paid charge — which the server then refuses');

        $at = strpos(self::$src, 'private function _chargePaidAmount');
        $this->assertNotFalse($at, 'the paid-amount helper is gone');
        $body = substr(self::$src, $at, 900);

        $this->assertStringContainsString('return 0.0;', $body,
            'the helper no longer fails to zero. An unreadable demand must not make a settled '
            . 'charge look unpaid in a way that hides money.');
    }
    /**
     * A charge with money against it must not offer a Waive button — waiving a
     * paid charge would strand its receipt and journal entry. The server-side
     * CAS guard remains the real authority; this is the UI not inviting a
     * refusal.
     */
    public function test_a_paid_charge_is_not_offered_a_waive_button(): void
    {
        $view = (string) file_get_contents(APPPATH . 'views/fee_management/student_charges.php');

        $at = strpos($view, 'var canWaive');
        $this->assertNotFalse($at, 'the Waive button is no longer conditional at all');
        $expr = substr($view, $at, 120);

        // The guard, not its spelling: a paid charge must be excluded, and a
        // waived one must not be offered again.
        $this->assertStringContainsString('paid <= 0.005', $expr,
            'the UI offers Waive on a charge that carries a payment. The server refuses it '
            . '(WAIVE-RACE-1), but the operator is being invited to do something that cannot '
            . 'work — and a refusal they did not expect reads as a bug.');
        $this->assertStringContainsString("'waived'", $expr,
            'an already-waived charge is offered for waiving again');
    }
}
