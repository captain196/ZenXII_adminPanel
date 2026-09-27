<?php

use PHPUnit\Framework\TestCase;

if (!defined('APPPATH')) define('APPPATH', __DIR__ . '/../../application/');
if (!defined('BASEPATH')) define('BASEPATH', 'x');

/**
 * ══════════════════════════════════════════════════════════════════════════
 *  A PAYMENT RELEASE MUST NOT SWITCH ON AN UNRELATED WORKSTREAM
 * ══════════════════════════════════════════════════════════════════════════
 *
 * ── THE PROBLEM ──────────────────────────────────────────────────────────
 * Payment and Student Charges share one controller, so a payment release
 * necessarily ships Fee_management.php — and with it four new public methods:
 *
 *     student_charges · list_student_charges
 *     raise_student_charge · waive_student_charge
 *
 * CodeIgniter falls back to automatic controller/method routing, so those
 * endpoints become reachable in production even though routes.php is NOT part
 * of the release. That produced two unacceptable outcomes:
 *
 *   ship Student_charge_service  -> silently ACTIVATES an unrelated workstream
 *   omit  Student_charge_service -> four endpoints raise a PHP fatal on call
 *
 * The decoupling is the third option: the endpoints report themselves
 * unavailable when the service is not deployed. The payment release then
 * carries the code without turning the feature on, and nothing fatals.
 *
 * ── WHY THIS IS NOT A WEAKENING ──────────────────────────────────────────
 * Where the service IS deployed — as it is locally, where F1's ID Card
 * Replacement charge was raised and settled — behaviour is byte-identical.
 * The guard only changes what happens in an installation that never had the
 * feature, where the alternative was a 500.
 */
final class ReleaseDecouplingTest extends TestCase
{
    private function controllerSource(): string
    {
        $out = '';
        foreach (token_get_all((string) file_get_contents(APPPATH . 'controllers/Fee_management.php')) as $t) {
            if (is_array($t)) {
                if (in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true)) continue;
                $out .= $t[1];
            } else { $out .= $t; }
        }
        return $out;
    }

    /** Endpoints the payment release exposes but must not activate. */
    private const GUARDED = [
        'student_charges',
        'list_student_charges',
        'raise_student_charge',
        'waive_student_charge',
    ];

    public function test_every_student_charge_endpoint_refuses_when_the_feature_is_absent(): void
    {
        $src = $this->controllerSource();
        foreach (self::GUARDED as $m) {
            $at = strpos($src, "public function {$m}(");
            $this->assertNotFalse($at, "endpoint {$m} not found — the controller shape changed");

            // The guard must be the FIRST thing the method does. A guard placed
            // after a library load has already fataled by the time it runs.
            $body = substr($src, $at, 600);
            $guardAt = strpos($body, '_refuse_if_student_charges_unavailable');
            $this->assertNotFalse($guardAt, "{$m} is unguarded — it would fatal in an installation without the service");

            $loadAt = strpos($body, 'load->library');
            if ($loadAt !== false) {
                $this->assertLessThan($loadAt, $guardAt,
                    "{$m} loads a library before checking availability — the fatal happens first");
            }
            $initAt = strpos($body, '_init_student_charge_service');
            if ($initAt !== false) {
                $this->assertLessThan($initAt, $guardAt,
                    "{$m} initialises the service before checking availability");
            }
        }
    }

    public function test_availability_is_decided_by_deployment_not_by_a_flag(): void
    {
        // A config flag would have to be set correctly in production, which is
        // one more thing to get wrong. Presence of the service file IS the
        // deployment fact, and cannot disagree with itself.
        $src = $this->controllerSource();
        $this->assertMatchesRegularExpression(
            "/_student_charges_available\(\).*?is_file\(APPPATH \. 'libraries\/Student_charge_service\.php'\)/s",
            $src,
            'availability must be derived from whether the service is deployed'
        );
    }

    public function test_the_refusal_is_503_not_404(): void
    {
        // 404 would say "no such endpoint", which is false and misleads anyone
        // reading access logs after the feature ships.
        $src = $this->controllerSource();
        $at  = strpos($src, 'function _refuse_if_student_charges_unavailable');
        $this->assertNotFalse($at);
        $body = substr($src, $at, 500);
        $this->assertStringContainsString('http_response_code(503)', $body);
        $this->assertStringNotContainsString('http_response_code(404)', $body);
    }

    public function test_the_guard_is_inert_where_the_service_is_deployed(): void
    {
        // Control: locally the service EXISTS, so the guard must not fire —
        // otherwise this "decoupling" would have quietly disabled a working
        // feature on the machine that uses it.
        $this->assertFileExists(APPPATH . 'libraries/Student_charge_service.php',
            'the service is missing locally — the control below proves nothing');

        // The predicate the guard uses, evaluated directly.
        $this->assertTrue(is_file(APPPATH . 'libraries/Student_charge_service.php'));
    }

    public function test_the_payment_path_itself_is_not_guarded(): void
    {
        // The decoupling must not reach into payment. A guard on
        // parent_create_order or parent_verify_payment would make the payment
        // path depend on an unrelated feature's deployment.
        $src = $this->controllerSource();
        foreach (['parent_create_order', 'parent_verify_payment', 'payment_webhook'] as $m) {
            $at = strpos($src, "public function {$m}(");
            $this->assertNotFalse($at, "{$m} not found");
            $body = substr($src, $at, 3000);
            $this->assertStringNotContainsString('_refuse_if_student_charges_unavailable', $body,
                "{$m} is gated on Student Charges availability — payment must not depend on it");
        }
    }

    public function test_the_release_does_not_need_routes_php(): void
    {
        // routes.php carries several workstreams' changes. The payment release
        // excludes it, which is only safe because the webhook route already
        // exists in production — verified live: the production endpoint answers
        // HTTP 400 (reached, bad body), not 404.
        $routes = (string) file_get_contents(APPPATH . 'config/routes.php');
        $this->assertStringContainsString("fee_management/payment_webhook", $routes,
            'the webhook route vanished from routes.php — re-verify production routing before release');
    }
}
