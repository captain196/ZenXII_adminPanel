<?php
namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * BUG-008 regression — close_homework must (a) enrich its log_audit
 * description with before/after status capture, and (b) short-circuit
 * on idempotent re-close to prevent audit-log clutter.
 *
 * Pattern mirrors Finding #21 update_homework audit enrichment.
 *
 * Discovered: cycle 3 (DETECTION_REPORT, 2026-05-21)
 * Fixed:      session 3 cycle 6 (FIX_PATCH, 2026-05-21)
 * Quality bar: STACK_INVARIANTS §13 (before/after audit); FINAL_BLUEPRINT.md §11
 */
class HomeworkCloseAuditEnrichmentTest extends TestCase
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
     * The log_audit description must include the before/after marker.
     */
    public function test_bug008_log_audit_enriched_with_old_new_status(): void
    {
        $this->assertStringContainsString(
            "\"Closed homework | status old={\$prevStatus} new=closed\"",
            $this->src,
            "BUG-008 regression: log_audit description no longer captures old/new status"
        );
    }

    /**
     * The pre-fix static description "Closed homework" (with no
     * before/after marker) must be gone — otherwise the enrichment
     * was un-applied.
     */
    public function test_bug008_pre_fix_static_description_gone(): void
    {
        $this->assertStringNotContainsString(
            "log_audit('Homework', 'homework_close', \$hwId, \"Closed homework\")",
            $this->src,
            "BUG-008 regression: pre-fix static-description log_audit reappeared"
        );
    }

    /**
     * The idempotent short-circuit must capture prevStatus and return
     * "Homework already closed." when the doc is already closed.
     */
    public function test_bug008_idempotent_short_circuit_present(): void
    {
        $this->assertStringContainsString(
            "\$prevStatus = (string) (\$existing['status'] ?? 'active');",
            $this->src,
            "BUG-008 regression: prevStatus capture removed"
        );
        $this->assertStringContainsString(
            "if (strtolower(\$prevStatus) === 'closed') {",
            $this->src,
            "BUG-008 regression: idempotent short-circuit guard removed"
        );
        $this->assertStringContainsString(
            "'message' => 'Homework already closed.'",
            $this->src,
            "BUG-008 regression: already-closed success message removed"
        );
    }
}
