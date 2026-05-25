<?php
namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * BUG-001 regression — `get_overview` week-end calculation must anchor
 * to the IST-derived $today (via `_school_today()`), not the server-TZ
 * "now". Without this anchor, after ~18:30 IST when server (UTC) date
 * trails IST by one, items legitimately due on the 7th IST day were
 * excluded from `dueWeek`.
 *
 * Discovered: cycle 1 (DETECTION_REPORT, 2026-05-21)
 * Fixed:      session 3 cycle 2 (FIX_PATCH, 2026-05-21)
 * Quality bar: FINAL_BLUEPRINT.md §5 D1; intra-codebase Finding #9 IST parity
 */
class HomeworkWeekEndTzTest extends TestCase
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
     * Post-fix, $weekEnd MUST be computed by anchoring `+7 days` to
     * `strtotime($today)` — i.e., the IST date string, not server-TZ now.
     */
    public function test_bug001_week_end_anchored_to_school_today(): void
    {
        $this->assertStringContainsString(
            "\$weekEnd  = date('Y-m-d', strtotime('+7 days', strtotime(\$today)));",
            $this->src,
            "BUG-001 regression: \$weekEnd no longer anchors to strtotime(\$today)"
        );
    }

    /**
     * The pre-fix server-TZ form MUST be gone. If it reappears, an
     * accidental revert has occurred.
     */
    public function test_bug001_pre_fix_server_tz_form_gone(): void
    {
        $this->assertStringNotContainsString(
            "\$weekEnd  = date('Y-m-d', strtotime('+7 days'));",
            $this->src,
            "BUG-001 regression: pre-fix server-TZ \$weekEnd form reappeared"
        );
    }
}
