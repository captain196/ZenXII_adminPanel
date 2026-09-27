<?php
namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * FEE-Z5 regression — `Entitlements::approve()` must NOT return a top-level
 * `status` key.
 *
 * `MY_Controller::json_success()` builds its envelope as
 *     array_merge(['status' => 'success'], $data)
 * so any `status` key in $data OVERWRITES the envelope. `approve()` used to
 * send `'status' => $res['status']`, putting `{"status":"active", …}` on the
 * wire. Every client applying the standard phantom-success guard —
 * `body.status !== 'success'` — therefore read a SUCCESSFUL approval as a
 * FAILURE: Student 360's approvals panel threw the success into its error
 * branch, rendered it red, and never refreshed the list.
 *
 * Discovered: Student 360 UAT 9.10c / 9.10d (2026-08-12)
 * Fixed:      the entitlement's lifecycle state moved to `entitlementStatus`
 *
 * The contract this locks:
 *     {"status":"success","message":"Entitlement approved.","entitlementStatus":"active"}
 *
 * Scope note: `json_success()` itself is deliberately NOT changed — that is a
 * global contract. The sibling collisions on reject/discontinue/propose are
 * recorded as FEE-Z6 and are NOT asserted here, because they are unfixed by
 * decision rather than by oversight; asserting them would fail the suite for a
 * defect that is knowingly open.
 */
class EntitlementsApproveEnvelopeTest extends TestCase
{
    private string $src;

    protected function setUp(): void
    {
        $path = __DIR__ . '/../../application/controllers/Entitlements.php';
        $this->assertFileExists($path, 'Entitlements controller is missing.');
        $this->src = (string) file_get_contents($path);
        $this->assertNotSame('', $this->src);
    }

    /** Isolate the body of approve() so sibling endpoints cannot mask a regression. */
    private function approveBody(): string
    {
        $start = strpos($this->src, 'public function approve(');
        $this->assertNotFalse($start, 'approve() not found.');
        // Ends where the next method begins.
        $next = strpos($this->src, 'public function ', $start + 10);
        return substr($this->src, $start, $next === false ? null : $next - $start);
    }

    public function test_approve_returns_entitlement_status_not_status(): void
    {
        $body = $this->approveBody();

        $this->assertStringContainsString(
            "'entitlementStatus' => \$res['status']",
            $body,
            "approve() must expose the entitlement's new state as `entitlementStatus`."
        );
    }

    public function test_approve_does_not_shadow_the_success_envelope(): void
    {
        $body = $this->approveBody();

        // Strip comments so the explanatory block above the fix cannot satisfy
        // — or trip — this assertion.
        $code = '';
        foreach (token_get_all('<?php ' . $body) as $t) {
            if (is_array($t)) {
                if (in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true)) continue;
                $code .= $t[1];
            } else {
                $code .= $t;
            }
        }

        $this->assertDoesNotMatchRegularExpression(
            "/json_success\(\s*\[[^\]]*'status'\s*=>/s",
            $code,
            'approve() passes a top-level `status` to json_success(), which '
            . 'overwrites the success envelope and makes every standards-compliant '
            . 'client read the approval as a failure (FEE-Z5).'
        );
    }

    public function test_approve_still_states_the_success_message(): void
    {
        $this->assertStringContainsString(
            "'message'           => 'Entitlement approved.'",
            $this->approveBody(),
            'The human-facing confirmation must survive the envelope fix.'
        );
    }

    /**
     * The fix must be presentation-only: authorization, the service call and
     * the failure path are unchanged.
     */
    public function test_approve_business_logic_untouched(): void
    {
        $body = $this->approveBody();

        $this->assertStringContainsString(
            "_require_role([], 'entitlement_approve', 'Fees', 'manage')",
            $body,
            'RBAC gate on approve() must remain Fees:manage.'
        );
        $this->assertStringContainsString(
            '$this->_svc()->approve($id',
            $body,
            'approve() must still delegate to Entitlement_service — the sole write path.'
        );
        $this->assertStringContainsString(
            "if (empty(\$res['ok'])) return \$this->json_error(",
            $body,
            'The failure path must still return json_error, not a success envelope.'
        );
    }
}
