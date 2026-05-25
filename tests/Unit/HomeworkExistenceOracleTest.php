<?php
namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * BUG-015 regression — the 5 cross-tenant denial sites in Homework.php
 * MUST respond with the same shape as the truly-not-found branch
 * (HTTP 404 + "Homework not found.") so an authenticated user cannot
 * distinguish "doc absent" from "doc exists in another school" by
 * probing hwIds.
 *
 * The BUG-014 telemetry emit (CROSS_TENANT_PROBE) MUST remain intact
 * at each site so the server-side forensic trail is preserved — the
 * fix collapses the response shape but keeps the differential signal
 * in the logs.
 *
 * Discovered: cycle 5 (DETECTION_REPORT, 2026-05-21)
 * Fixed:      session 4 cycle 5 (FIX_PATCH, 2026-05-21)
 * Quality bar: QUALITY_BAR §D4 ("every authenticated route checks resource
 *              ownership" + same shape regardless of failure mode); project's
 *              own anti-oracle remediation (Finding #5 isSameSchoolStrict)
 */
class HomeworkExistenceOracleTest extends TestCase
{
    private string $src;

    protected function setUp(): void
    {
        $this->src = file_get_contents(
            __DIR__ . '/../../application/controllers/Homework.php'
        );
        $this->assertNotFalse($this->src);
    }

    /**
     * The pre-fix 403 response MUST be gone — exactly zero occurrences
     * of `json_error('Unauthorized', 403)` should remain.
     */
    public function test_bug015_no_403_unauthorized_responses_remain(): void
    {
        $count = substr_count($this->src, "\$this->json_error('Unauthorized', 403)");
        $this->assertSame(
            0,
            $count,
            "BUG-015 regression: $count 'Unauthorized' 403 response(s) remain — existence oracle reopened"
        );
    }

    /**
     * BUG-015's new comment marker must appear exactly 5 times
     * (one per former cross-tenant 403 site).
     */
    public function test_bug015_marker_comment_appears_at_five_sites(): void
    {
        $marker = "// BUG-015 — return 404 (same as truly-not-found) to close the";
        $count = substr_count($this->src, $marker);
        $this->assertSame(
            5,
            $count,
            "BUG-015 regression: expected 5 BUG-015 marker comments (one per former cross-tenant site); found $count"
        );
    }

    /**
     * BUG-014 telemetry MUST still fire at each of 5 sites — closing the
     * existence oracle removes the response-side differential, but the
     * server-side forensic trail (CROSS_TENANT_PROBE events) is the
     * operator's compensating visibility. Reuses BUG-014's assertion that
     * exactly 5 CROSS_TENANT_PROBE emits exist.
     */
    public function test_bug015_preserves_bug014_telemetry(): void
    {
        $count = substr_count($this->src, "'CROSS_TENANT_PROBE'");
        $this->assertSame(
            5,
            $count,
            "BUG-015 regression: CROSS_TENANT_PROBE emit count = $count; expected 5 (BUG-014 telemetry must survive the BUG-015 response-shape collapse)"
        );
    }
}
