<?php
namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * BUG-011 regression — the two read-side submissions queries in
 * Homework.php (get_homework_detail at line ~221, get_submissions at
 * line ~346) MUST carry an explicit schoolId predicate, matching the
 * defense-in-depth pattern already applied to mutating paths.
 *
 * Without it, a future refactor weakening the parent-doc schoolId gate
 * would silently expose every school's submissions. The Firestore
 * (schoolId, homeworkId) composite index already exists.
 *
 * Discovered: cycle 4 (DETECTION_REPORT, 2026-05-21)
 * Fixed:      session 4 cycle 3 (FIX_PATCH, 2026-05-21)
 * Quality bar: STACK_INVARIANTS §5 (every doc carries schoolId; rules + queries enforce);
 *              FINAL_BLUEPRINT.md §3 (DB-layer tenant isolation)
 */
class HomeworkSchoolIdPredicateTest extends TestCase
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
     * Both read-side submissions queries must be the multi-predicate
     * form. Counted via substring presence — should appear at least
     * twice (the 2 BUG-011 sites). Note: the cascade-write queries
     * at lines ~1284 and ~1182 ALSO use this exact predicate, so the
     * total file-wide count is higher.
     */
    public function test_bug011_submissions_queries_carry_school_id(): void
    {
        $multiPredicate = "[\n                ['schoolId',   '=', \$this->school_name],\n                ['homeworkId', '=', \$hwId],\n            ]";
        $count = substr_count($this->src, $multiPredicate);
        $this->assertGreaterThanOrEqual(
            2,
            $count,
            "BUG-011 regression: expected ≥ 2 read-side submissions queries with schoolId+homeworkId predicate (the 2 BUG-011 sites); found $count"
        );
    }

    /**
     * The pre-fix single-predicate form `[['homeworkId', '=', $hwId]]`
     * for the get_homework_detail and get_submissions read paths must
     * be gone. (The _calc_submission_rate helper at line ~1717 also
     * uses this form — it's NOT in BUG-011 scope per cycle-4 finding
     * citing only the 2 endpoint sites — so file-wide count goes from
     * 3 to 1 after this fix.)
     */
    public function test_bug011_school_blind_count_dropped(): void
    {
        $schoolBlind = "[['homeworkId', '=', \$hwId]]";
        $count = substr_count($this->src, $schoolBlind);
        $this->assertLessThanOrEqual(
            1,
            $count,
            "BUG-011 regression: expected ≤ 1 school-blind submissions query remaining (the _calc_submission_rate helper deliberately out of scope per finding); found $count"
        );
    }
}
