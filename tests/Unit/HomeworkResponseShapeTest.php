<?php
namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * BUG-016 regression — get_overview and get_trend_data MUST dual-emit
 * both their original flat-field payload AND a namespaced wrapper
 * ('overview' and 'trends' respectively), matching the project's HR
 * canonical dual-emit pattern (PROJECT_STATUS.md §1).
 *
 * Current view-side consumers read flat fields — they continue working.
 * Future API consumers (2027 public-API roadmap) get the namespaced
 * form so the response shape is uniform across all 11 read endpoints.
 *
 * Discovered: cycle 5 (DETECTION_REPORT, 2026-05-21)
 * Fixed:      session 5 cycle 5 (FIX_PATCH, 2026-05-21)
 * Quality bar: FINAL_BLUEPRINT.md §5 D5 (consistency); intra-codebase
 *              9-of-11 namespaced-pattern dominance; HR dual-emit precedent
 */
class HomeworkResponseShapeTest extends TestCase
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
     * get_overview must build the $overview array and dual-emit it.
     */
    public function test_bug016_get_overview_dual_emits(): void
    {
        $this->assertStringContainsString(
            "\$overview = [",
            $this->src,
            "BUG-016 regression: get_overview no longer builds the \$overview array"
        );
        $this->assertStringContainsString(
            "\$this->json_success(array_merge(\$overview, ['overview' => \$overview]));",
            $this->src,
            "BUG-016 regression: get_overview no longer dual-emits flat + namespaced"
        );
    }

    /**
     * get_trend_data must build the $trends array and dual-emit it.
     */
    public function test_bug016_get_trend_data_dual_emits(): void
    {
        $this->assertStringContainsString(
            "\$trends = [",
            $this->src,
            "BUG-016 regression: get_trend_data no longer builds the \$trends array"
        );
        $this->assertStringContainsString(
            "\$this->json_success(array_merge(\$trends, ['trends' => \$trends]));",
            $this->src,
            "BUG-016 regression: get_trend_data no longer dual-emits flat + namespaced"
        );
    }

    /**
     * The original pre-fix patterns (json_success directly receiving the
     * flat array literal) must be gone — would indicate revert.
     */
    public function test_bug016_pre_fix_direct_emit_gone(): void
    {
        // Pre-fix get_overview emitted the literal array directly:
        $this->assertStringNotContainsString(
            "\$this->json_success([\n            'total'       => \$total,\n            'active'      => \$active,",
            $this->src,
            "BUG-016 regression: pre-fix get_overview direct-emit reappeared"
        );
        // Pre-fix get_trend_data emitted the literal array directly:
        $this->assertStringNotContainsString(
            "\$this->json_success([\n            'labels'  => \$labels,\n            'created' => \$created,\n            'rates'   => \$rates,\n        ]);",
            $this->src,
            "BUG-016 regression: pre-fix get_trend_data direct-emit reappeared"
        );
    }
}
