<?php
namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * BUG-010 regression — `_fetch_all_homework`'s usort comparator MUST use
 * a Schwartzian transform (precomputed `_ts` field) rather than calling
 * `_normalizeTimestamp` inside the comparator on every comparison.
 *
 * Pre-fix the comparator made ~2N log N strtotime calls per dashboard
 * render. Post-fix it makes N normalize calls (precompute) + N log N
 * integer compares.
 *
 * Discovered: cycle 3 (DETECTION_REPORT, 2026-05-21)
 * Fixed:      session 5 cycle 1 (FIX_PATCH, 2026-05-21)
 * Quality bar: FINAL_BLUEPRINT.md §5 D8 (no unbounded loops on user-supplied data);
 *              FINAL_BLUEPRINT.md §4 (300-5000 students customer scale)
 */
class HomeworkSchwartzianSortTest extends TestCase
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
     * The Schwartzian precompute loop must be present: a foreach loop
     * by reference setting `$item['_ts']` to the normalized timestamp.
     */
    public function test_bug010_schwartzian_precompute_loop_present(): void
    {
        $this->assertStringContainsString(
            "foreach (\$result as &\$item) {",
            $this->src,
            "BUG-010 regression: Schwartzian precompute foreach loop removed"
        );
        $this->assertStringContainsString(
            "\$item['_ts'] = \$this->_normalizeTimestamp(\$item['createdAt'] ?? 0);",
            $this->src,
            "BUG-010 regression: _ts precompute assignment removed"
        );
    }

    /**
     * The usort comparator must NOT call _normalizeTimestamp inside the
     * closure anymore — should just integer-compare the precomputed _ts.
     */
    public function test_bug010_usort_uses_precomputed_ts(): void
    {
        $this->assertStringContainsString(
            "return \$b['_ts'] <=> \$a['_ts'];",
            $this->src,
            "BUG-010 regression: usort comparator no longer uses precomputed _ts"
        );
    }

    /**
     * The pre-fix in-comparator _normalizeTimestamp calls MUST be gone
     * from the _fetch_all_homework region.
     */
    public function test_bug010_pre_fix_in_comparator_normalize_gone(): void
    {
        // The exact pre-fix pattern: two adjacent _normalizeTimestamp calls
        // inside a closure on $a['createdAt'] and $b['createdAt'].
        $preFixPattern = "\$aTs = \$this->_normalizeTimestamp(\$a['createdAt'] ?? 0);\n            \$bTs = \$this->_normalizeTimestamp(\$b['createdAt'] ?? 0);";
        $this->assertStringNotContainsString(
            $preFixPattern,
            $this->src,
            "BUG-010 regression: pre-fix in-comparator _normalizeTimestamp pattern reappeared"
        );
    }
}
