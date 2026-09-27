<?php
namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * FEE-Z6 regression — `propose()`, `reject()` and `discontinue()` must NOT
 * return a top-level `status` key.
 *
 * Same mechanism as FEE-Z5 (see EntitlementsApproveEnvelopeTest):
 * `MY_Controller::json_success()` builds its envelope as
 *     array_merge(['status' => 'success'], $data)
 * so a `status` key in $data OVERWRITES the envelope.
 *
 * `propose()` was the most damaging instance. It returns 'active' or
 * 'pending_approval' — NEITHER is 'success' — so the shared capture partial
 * (`_entitlements_capture.php:193`, `body.status !== 'success'`) threw EVERY
 * successful capture into its error branch. Staff adding a concession during
 * admission saw a red failure for a record that had saved, inviting a retry
 * and a duplicate. All three admission pathways mount that partial.
 *
 * Verified on the wire 2026-08-12:
 *   propose (needs approval) → {"status":"success", …,"entitlementStatus":"pending_approval"}
 *   propose (auto-active)    → {"status":"success", …,"entitlementStatus":"active"}
 *   reject                   → {"status":"success", …,"entitlementStatus":"rejected"}
 *   discontinue              → {"status":"success", …,"entitlementStatus":"discontinued"}
 *   failures                 → {"status":"error", …}
 *
 * `json_success()` is deliberately NOT changed — global contract, separate
 * decision. The ~20 collisions in other controllers are recorded under
 * FEE-ENVELOPE-AUDIT and are knowingly unfixed, so they are NOT asserted here.
 */
class EntitlementsEnvelopeContractTest extends TestCase
{
    private string $src;

    protected function setUp(): void
    {
        $path = __DIR__ . '/../../application/controllers/Entitlements.php';
        $this->assertFileExists($path, 'Entitlements controller is missing.');
        $this->src = (string) file_get_contents($path);
        $this->assertNotSame('', $this->src);
    }

    /** Body of one method, so a sibling endpoint cannot mask a regression. */
    private function method(string $name): string
    {
        $start = strpos($this->src, 'public function ' . $name . '(');
        $this->assertNotFalse($start, $name . '() not found.');
        $next = strpos($this->src, 'public function ', $start + 10);
        return substr($this->src, $start, $next === false ? null : $next - $start);
    }

    /** Comments stripped, so explanatory text cannot satisfy or trip an assertion. */
    private function codeOf(string $name): string
    {
        $out = '';
        foreach (token_get_all('<?php ' . $this->method($name)) as $t) {
            if (is_array($t)) {
                if (in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true)) continue;
                $out .= $t[1];
            } else {
                $out .= $t;
            }
        }
        return $out;
    }

    public function endpointProvider(): array
    {
        return [
            'propose'     => ['propose'],
            'reject'      => ['reject'],
            'discontinue' => ['discontinue'],
            'approve'     => ['approve'],   // FEE-Z5 — must stay fixed
        ];
    }

    /**
     * @dataProvider endpointProvider
     */
    public function test_endpoint_does_not_shadow_the_success_envelope(string $endpoint): void
    {
        $this->assertDoesNotMatchRegularExpression(
            "/json_success\(\s*\[[^\]]*'status'\s*=>/s",
            $this->codeOf($endpoint),
            $endpoint . '() passes a top-level `status` to json_success(), which overwrites '
            . 'the success envelope. Every client applying the standard '
            . '`body.status !== \'success\'` guard reads the success as a failure.'
        );
    }

    /**
     * @dataProvider endpointProvider
     */
    public function test_endpoint_exposes_entitlement_status(string $endpoint): void
    {
        $this->assertStringContainsString(
            "'entitlementStatus' => \$res['status']",
            $this->codeOf($endpoint),
            $endpoint . '() must return the entitlement state as `entitlementStatus`.'
        );
    }

    /**
     * @dataProvider endpointProvider
     */
    public function test_failure_path_still_returns_json_error(string $endpoint): void
    {
        $this->assertMatchesRegularExpression(
            "/if \(empty\(\\\$res\['ok'\]\)\) return \\\$this->json_error\(/",
            $this->codeOf($endpoint),
            $endpoint . '() must still fail closed via json_error, never a success envelope.'
        );
    }

    /**
     * The three states must come from the SERVICE, not be hardcoded in the
     * controller. discontinue() in particular is idempotent: on an already
     * discontinued/rejected row it returns that row's EXISTING status with
     * `noop => true`, so hardcoding 'discontinued' would misreport a rejected
     * row as discontinued.
     */
    public function test_states_are_passed_through_not_invented(): void
    {
        foreach (['propose', 'reject', 'discontinue', 'approve'] as $endpoint) {
            $code = $this->codeOf($endpoint);
            $this->assertDoesNotMatchRegularExpression(
                "/'entitlementStatus'\s*=>\s*'/",
                $code,
                $endpoint . '() hardcodes a literal entitlement state. It must pass through '
                . "\$res['status'] so the service remains the single source of lifecycle truth."
            );
        }
    }

    /** RBAC and the service delegation must be untouched by an envelope fix. */
    public function test_authorization_and_service_delegation_unchanged(): void
    {
        $expect = [
            'propose'     => ["'entitlement_propose', 'Fees', 'edit'",       '$this->_svc()->propose('],
            'reject'      => ["'entitlement_reject', 'Fees', 'manage'",      '$this->_svc()->reject('],
            'discontinue' => ["'entitlement_discontinue', 'Fees', 'manage'", '$this->_svc()->discontinue('],
            'approve'     => ["'entitlement_approve', 'Fees', 'manage'",     '$this->_svc()->approve('],
        ];
        foreach ($expect as $endpoint => [$gate, $call]) {
            $body = $this->method($endpoint);
            $this->assertStringContainsString($gate, $body, $endpoint . '(): RBAC gate changed.');
            $this->assertStringContainsString($call, $body, $endpoint . '(): service call changed.');
        }
    }

    /** The maker-checker rule that decides approvalRequired must be untouched. */
    public function test_maker_checker_rule_unchanged(): void
    {
        $this->assertStringContainsString(
            "return !has_permission('Fees', 'manage');",
            $this->src,
            'The approval-required rule must remain: a Fees:manage holder does not '
            . 'need approval for their own proposal; anyone else does.'
        );
    }
}
