<?php

use PHPUnit\Framework\TestCase;

if (!defined('APPPATH')) define('APPPATH', __DIR__ . '/../../application/');

/**
 * ══════════════════════════════════════════════════════════════════════════
 *  STUDENT CHARGES — THE ADMIN SURFACE
 * ══════════════════════════════════════════════════════════════════════════
 *
 * `StudentChargeServiceTest` proves the service's decisions by executing them.
 * This file guards the things that live at the ENDPOINT and cannot be reached
 * from a unit test without bootstrapping CodeIgniter: who may call it, whether
 * it is reachable at all, and whether it kept CSRF.
 *
 * A service nobody can call is not a feature; an endpoint anyone can call is a
 * liability. Both halves are checked here.
 */
class StudentChargeEndpointTest extends TestCase
{
    private static string $ctrl   = '';
    private static string $routes = '';
    private static string $config = '';

    public static function setUpBeforeClass(): void
    {
        self::$ctrl   = (string) file_get_contents(APPPATH . 'controllers/Fee_management.php');
        self::$routes = (string) file_get_contents(APPPATH . 'config/routes.php');
        self::$config = (string) file_get_contents(APPPATH . 'config/config.php');
    }

    /** One method body, bounded at the next method (never a fixed window). */
    private function methodBody(string $name): string
    {
        // Match ANY visibility. The first version searched only for
        // `public function`, so it could not find the private init helper and
        // failed claiming the method did not exist — a wrong answer about the
        // code caused by the test's own lookup.
        $found = preg_match(
            '/\n    (?:public|private|protected) function ' . preg_quote($name, '/') . '\(/',
            self::$ctrl, $m, PREG_OFFSET_CAPTURE
        );
        $this->assertSame(1, $found, "method {$name}() not found");
        $at = $m[0][1] + 1;

        $end = preg_match('/\n    (?:public|private|protected) function /', self::$ctrl, $m,
            PREG_OFFSET_CAPTURE, $at + 20) === 1 ? $m[0][1] : strlen(self::$ctrl);

        return substr(self::$ctrl, $at, $end - $at);
    }

    // ══ REACHABILITY ════════════════════════════════════════════════════════

    /**
     * Two pages in this codebase were built, routed and permission-gated and
     * still unreachable because nothing linked them. An endpoint with no route
     * is the same failure one layer down.
     */
    public function test_every_endpoint_is_routed(): void
    {
        foreach (['raise_student_charge', 'waive_student_charge', 'list_student_charges'] as $action) {
            $this->assertStringContainsString(
                "\$route['fee_management/{$action}']",
                self::$routes,
                "{$action}() exists but has no route — it is unreachable, which is the same "
                . 'as not existing'
            );
        }
    }

    // ══ AUTHORIZATION ═══════════════════════════════════════════════════════

    /**
     * Raising a charge bills a family. Waiving one un-bills them. Both are
     * financial acts and deliberately require the SAME authority — the ability
     * to bill and the ability to reverse should not sit at different levels.
     */
    public function test_raising_and_waiving_require_the_same_finance_authority(): void
    {
        foreach (['raise_student_charge', 'waive_student_charge'] as $action) {
            $body = $this->methodBody($action);

            $this->assertStringContainsString('self::FINANCE_ROLES', $body,
                "{$action}() does not require FINANCE_ROLES. Raising a charge against a "
                . 'family is a financial act, not a data edit.');
            $this->assertStringContainsString("'Fees', 'manage'", $body,
                "{$action}() does not require Fees:manage");
        }
    }

    /**
     * Listing is a read. It must NOT require manage — an accountant reviewing
     * what a family owes should not need the authority to bill them.
     */
    public function test_listing_is_a_read_not_a_management_action(): void
    {
        $body = $this->methodBody('list_student_charges');

        $this->assertStringContainsString("'Fees', 'view'", $body,
            'listing charges should require only view — forcing manage would mean anyone '
            . 'who can READ a charge could also RAISE one');
    }

    // ══ CSRF POSTURE ════════════════════════════════════════════════════════

    /**
     * Only session-less endpoints (payment_webhook, parent_*) are CSRF-exempt.
     * These are admin POSTs carrying a session, so they must keep CSRF —
     * widening the exemption list to a money-RAISING action would remove a real
     * protection to save a configuration line.
     */
    public function test_the_charge_endpoints_are_not_csrf_exempt(): void
    {
        foreach (['raise_student_charge', 'waive_student_charge'] as $action) {
            $this->assertStringNotContainsString("fee_management/{$action}", self::$config,
                "{$action} was added to csrf_exclude_uris. It is an authenticated admin POST "
                . 'that bills a family; the exemption exists for endpoints that CANNOT carry '
                . 'a session, which this can.');
        }
    }

    // ══ INPUT HANDLING ══════════════════════════════════════════════════════

    public function test_the_student_id_is_sanitised_before_use(): void
    {
        foreach (['raise_student_charge', 'list_student_charges'] as $action) {
            $this->assertStringContainsString('safe_path_segment(', $this->methodBody($action),
                "{$action}() uses the posted student id without sanitising it");
        }
    }

    public function test_a_non_numeric_amount_is_rejected_before_casting(): void
    {
        $body = $this->methodBody('raise_student_charge');

        $this->assertStringContainsString('is_numeric($amountRaw)', $body,
            'the amount is cast without an is_numeric check. (float)"abc" is 0.0, which '
            . 'would reach the service as a zero charge rather than as bad input.');
    }

    /**
     * ID-DUE-1 — a caller may shorten or lengthen the payment window but must
     * never be able to remove it, or the demand becomes invisible to both the
     * reminder service and the late-fee engine.
     */
    public function test_the_due_window_always_has_a_default(): void
    {
        $body = $this->methodBody('raise_student_charge');

        $this->assertStringContainsString('Student_charge_service::DEFAULT_DUE_DAYS', $body,
            'a missing due_days does not fall back to the default window. Without a due '
            . 'date the charge is never chased and never ages into a late fee.');
    }

    // ══ THE REFUND BOUNDARY MUST NOT BE BYPASSABLE ══════════════════════════

    /**
     * The service refuses to waive a paid charge. The endpoint must not offer a
     * way around that — a `force` flag here would quietly become a second,
     * unaudited refund path with no accounting reversal.
     */
    public function test_the_waiver_endpoint_offers_no_override(): void
    {
        $body = $this->methodBody('waive_student_charge');

        foreach (['force', 'override', 'skip_paid', 'ignore_paid'] as $escape) {
            $this->assertStringNotContainsString($escape, strtolower($body),
                "waive_student_charge() accepts a '{$escape}' style flag. Waiving a PAID "
                . 'charge strands its receipt, allocation and journal entry — returning '
                . 'money is a refund, with its own authorization and accounting reversal.');
        }

        // And it must not reach past the service to the demand itself.
        $this->assertStringNotContainsString("firestoreSet('feeDemands'", $body,
            'the endpoint writes the demand directly, bypassing the service\'s paid-charge '
            . 'guard entirely');
    }

    // ══ AUDIT ═══════════════════════════════════════════════════════════════

    public function test_both_money_events_are_audited(): void
    {
        $this->assertStringContainsString("feeAudit->log('student_charge_raised'", self::$ctrl,
            'raising a charge against a family is unaudited');
        $this->assertStringContainsString("feeAudit->log('student_charge_waived'", self::$ctrl,
            'reversing a charge is unaudited — who waived it and why is exactly what an '
            . 'auditor asks');
    }

    /**
     * FEE-W1: a missing collaborator raises an Error, not an Exception. The
     * charge is already billed by the time the audit runs, so an audit failure
     * must not turn a raised charge into an error response.
     */
    public function test_an_audit_failure_cannot_undo_a_raised_charge(): void
    {
        $body = $this->methodBody('raise_student_charge');

        $at = strpos($body, "feeAudit->log('student_charge_raised'");
        $this->assertNotFalse($at);

        $this->assertMatchesRegularExpression('/catch \(\\\\Throwable/', substr($body, $at, 800),
            'the audit is guarded by catch(\\Exception) or not at all. A missing collaborator '
            . 'raises an Error, and the charge is already billed at this point — the caller '
            . 'would see a failure for a charge that succeeded, and raise it again.');
    }

    // ══ ONE PIPELINE ════════════════════════════════════════════════════════

    /**
     * The whole point of the design: charges ride the canonical generator. If
     * the endpoint ever wrote feeDemands itself, that would be a second billing
     * pipeline with a second balance formula to keep correct.
     */
    public function test_the_endpoint_never_writes_a_demand_itself(): void
    {
        $body = $this->methodBody('raise_student_charge');

        $this->assertStringNotContainsString("firestoreSet('feeDemands'", $body,
            'the endpoint writes feeDemands directly instead of routing through '
            . 'Fee_lifecycle::createModuleFee(). That is a second billing engine.');
        $this->assertStringContainsString('studentCharge->raise(', $body,
            'the endpoint no longer delegates to the charge service');
    }

    /**
     * The service must be handed the canonical generator, not left to find its
     * own way to a demand.
     */
    public function test_the_service_is_wired_to_the_canonical_generator(): void
    {
        $init = $this->methodBody('_init_student_charge_service');

        $this->assertStringContainsString('Fee_lifecycle', $init,
            'the charge service is initialised without the canonical fee generator');
        $this->assertStringContainsString('$this->school_name, $this->session_year', $init,
            'the service is not scoped to this request\'s tenant and session');
    }
}
