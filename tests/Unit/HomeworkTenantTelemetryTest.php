<?php
namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * BUG-014 regression — each of the 5 cross-tenant 403 sites in Homework.php
 * must emit Security_telemetry::emit('CROSS_TENANT_PROBE', ...). The 5 sites
 * are the schoolId-mismatch branches of: get_homework_detail, get_submissions,
 * update_homework, delete_homework, close_homework.
 *
 * Without the emit, an authenticated user probing cross-school hwIds
 * generates zero forensic trail — defeats the Staff Hardening Phase A
 * precedent that wired CROSS_TENANT_PROBE / RBAC_DENIED telemetry.
 *
 * Discovered: cycle 5 (DETECTION_REPORT, 2026-05-21)
 * Fixed:      session 2 cycle 6 (FIX_PATCH, 2026-05-21)
 * Quality bar: FINAL_BLUEPRINT.md §5 D9; PROJECT_STATUS.md §2.3
 */
class HomeworkTenantTelemetryTest extends TestCase
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
     * Each of the 5 endpoints must carry its own emit() call with the
     * correct endpoint string. Substring-anchored — any reword of the
     * endpoint string fails the assertion.
     */
    public function test_bug014_all_five_endpoints_emit_telemetry(): void
    {
        $expected = [
            'get_homework_detail' => "'endpoint'         => 'Homework::get_homework_detail',",
            'get_submissions'     => "'endpoint'         => 'Homework::get_submissions',",
            'update_homework'     => "'endpoint'         => 'Homework::update_homework',",
            'delete_homework'     => "'endpoint'         => 'Homework::delete_homework',",
            'close_homework'      => "'endpoint'         => 'Homework::close_homework',",
        ];
        foreach ($expected as $endpoint => $needle) {
            $this->assertStringContainsString(
                $needle,
                $this->src,
                "BUG-014 regression: $endpoint cross-tenant branch missing security_telemetry emit"
            );
        }
    }

    /**
     * Each emit call uses the canonical event type 'CROSS_TENANT_PROBE'
     * from Security_telemetry::EVENT_TYPES. Exactly 5 occurrences expected
     * (one per site). More or fewer indicates a regression — too few means
     * a site lost its emit; too many means an emit was added outside the
     * 5 designated cross-tenant branches.
     */
    public function test_bug014_exactly_five_cross_tenant_probe_emits(): void
    {
        $count = substr_count($this->src, "'CROSS_TENANT_PROBE'");
        $this->assertSame(
            5,
            $count,
            "BUG-014 regression: expected exactly 5 CROSS_TENANT_PROBE emit calls; found $count"
        );
    }

    /**
     * Each emit is guarded by `isset($this->sec_telem) && $this->sec_telem->isReady()`
     * so a misconfigured request (e.g., a future direct controller invocation
     * that skips _require_role) doesn't fatal on a null sec_telem.
     */
    public function test_bug014_emit_guarded_by_isready_check(): void
    {
        $count = substr_count($this->src, "isset(\$this->sec_telem) && \$this->sec_telem->isReady()");
        $this->assertSame(
            5,
            $count,
            "BUG-014 regression: expected 5 isReady() guards (one per emit site); found $count"
        );
    }
}
