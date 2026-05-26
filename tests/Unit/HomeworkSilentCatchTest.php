<?php
namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * BUG-002 regression — every Firestore-read catch in Homework.php must emit
 * `log_message('error', ...)` so operators can distinguish "no data" from
 * "Firestore outage". Six sites were silent before the cycle-6 fix; this
 * test asserts each one now logs.
 *
 * Static-source assertion (no controller instantiation needed). Fails when
 * a future refactor reintroduces a silent catch at any of the six sites.
 *
 * Discovered: cycle 1 (DETECTION_REPORT, 2026-05-21)
 * Fixed:      cycle 6 (FIX_PATCH, 2026-05-21)
 * Quality bar: FINAL_BLUEPRINT.md §5 D9 (observability)
 */
class HomeworkSilentCatchTest extends TestCase
{
    private string $src;

    protected function setUp(): void
    {
        $this->src = file_get_contents(
            __DIR__ . '/../../application/controllers/Homework.php'
        );
        $this->assertNotFalse(
            $this->src,
            'Could not read Homework.php — test infrastructure broken'
        );
    }

    /**
     * The six BUG-002 silent-catch sites, each identified by a unique
     * log_message substring written by the fix. If any substring is
     * missing, the BUG-002 fix has been regressed at that site.
     */
    public function test_bug002_all_six_firestore_catches_now_log(): void
    {
        $expected = [
            'get_homework_detail teacherMarks' =>
                "Homework::get_homework_detail — teacherMarks query failed",
            'get_submissions teacherMarks' =>
                "Homework::get_submissions — teacherMarks query failed",
            'get_submissions roster' =>
                "Homework::get_submissions — students roster query failed",
            'get_subjects_for_class' =>
                "Homework::get_subjects_for_class — Firestore query failed",
            'get_students_for_class' =>
                "Homework::get_students_for_class — Firestore query failed",
            'get_class_sections' =>
                "Homework::get_class_sections — Firestore query failed",
        ];

        foreach ($expected as $site => $needle) {
            $this->assertStringContainsString(
                $needle,
                $this->src,
                "BUG-002 regression: '$site' catch is missing its log_message — "
                . "expected substring '$needle' not found. Re-apply the cycle-6 fix."
            );
        }
    }

    /**
     * Defense-in-depth: the six pre-fix silent-catch comment strings must
     * NOT reappear. If a future commit reintroduces "best-effort" or
     * "Firestore failed — return empty list" as the SOLE catch body,
     * this test catches the regression.
     */
    public function test_bug002_pre_fix_silent_comments_are_gone(): void
    {
        $forbiddenPatterns = [
            "// best-effort\n        }",
            "// teacherMarks query failed — best-effort, continue without them\n        }",
            "// Firestore students query failed — fall through to submission-only list\n            }",
            "// Firestore failed — return empty list. Per the no-RTDB policy,\n            // there is no fallback.\n        }",
        ];

        foreach ($forbiddenPatterns as $pattern) {
            $this->assertStringNotContainsString(
                $pattern,
                $this->src,
                "BUG-002 regression: pre-fix silent-catch comment pattern reappeared — '$pattern'"
            );
        }
    }
}
