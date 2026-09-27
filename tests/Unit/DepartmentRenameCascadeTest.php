<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * DEPT-D1 — a department rename leaves the Title-Case mirror stale.
 *
 * Staff docs are dual-emitted. Staff.php writes BOTH keys on every create and
 * update path:
 *
 *     'Department' => $department      (Staff.php:1415, :2310)
 *     'department' => $department      (Staff.php:1494, :2370)
 *
 * Renaming a department is a first-class, supported operation
 * (Staff_access::save_department → _cascade_staff_department), and the
 * department model is explicitly configurable — operators are EXPECTED to
 * rename. But the cascade patches only one of the two keys:
 *
 *     $this->fs->updateEntity('staff', $id, ['department' => $newName]);
 *
 * 36 read sites resolve `['Department'] ?? ['department']` — Title-Case FIRST
 * — so after any rename those consumers keep serving the OLD department name
 * indefinitely, while the 55 camelCase-first readers serve the new one. The
 * same staff member is then in two different departments depending on who is
 * asking.
 *
 * This is a silent divergence: nothing errors, nothing logs.
 */
final class DepartmentRenameCascadeTest extends TestCase
{
    private string $src;

    protected function setUp(): void
    {
        $p = __DIR__ . '/../../application/controllers/Staff_access.php';
        $this->assertFileExists($p);
        $this->src = (string) file_get_contents($p);
    }

    private function cascade(): string
    {
        $i = strpos($this->src, 'private function _cascade_staff_department(');
        $this->assertNotFalse($i, '_cascade_staff_department() is gone');
        $end = strpos($this->src, "\n    private function ", $i + 10);
        if ($end === false) $end = strpos($this->src, "\n    public function ", $i + 10);
        $this->assertNotFalse($end);
        return substr($this->src, $i, $end - $i);
    }

    /** DEFECT DEPT-D1 — the cascade must repoint BOTH mirrors. */
    public function test_the_rename_cascade_writes_both_department_keys(): void
    {
        $fn = $this->cascade();

        $this->assertStringContainsString("'department' => \$newName", $fn,
            'the camelCase mirror is no longer repointed');

        $this->assertStringContainsString("'Department' => \$newName", $fn,
            "The cascade repoints only the camelCase mirror.\n"
            . "Staff docs carry BOTH keys, and 36 read sites resolve\n"
            . "['Department'] ?? ['department'] — Title-Case FIRST — so those\n"
            . "consumers keep serving the OLD department name after a rename.");
    }

    /** Both keys must be set in the SAME patch, or a partial failure diverges them. */
    public function test_both_keys_are_written_in_one_patch(): void
    {
        $fn = $this->cascade();

        $this->assertSame(1, preg_match_all("~updateEntity\('staff',~", $fn),
            'both mirrors must move in a single updateEntity call — two calls can '
            . 'half-fail and leave the document self-inconsistent');
    }

    /** The dual-emit contract this depends on must still hold in the writer. */
    public function test_the_staff_writer_still_dual_emits(): void
    {
        $s = (string) file_get_contents(__DIR__ . '/../../application/controllers/Staff.php');

        $this->assertGreaterThanOrEqual(2, preg_match_all("~'Department'\s*=>~", $s),
            'Staff.php no longer emits the Title-Case mirror');
        $this->assertGreaterThanOrEqual(2, preg_match_all("~'department'\s*=>~", $s),
            'Staff.php no longer emits the camelCase mirror');
    }

    /**
     * The reason this matters, pinned: readers really do prefer Title-Case.
     * If that ever stops being true the defect changes shape and this test
     * should be revisited rather than silently passing.
     */
    public function test_title_case_first_readers_still_exist(): void
    {
        $count = 0;
        foreach (['controllers', 'libraries'] as $dir) {
            $base = __DIR__ . '/../../application/' . $dir;
            if (!is_dir($base)) continue;
            foreach (glob($base . '/*.php') as $f) {
                $count += preg_match_all("~\['Department'\]\s*\?\?~", (string) file_get_contents($f));
            }
        }

        $this->assertGreaterThan(0, $count,
            'no Title-Case-first readers remain — DEPT-D1 may no longer apply');
    }

    /** The guard that makes a no-op rename cheap must survive. */
    public function test_a_noop_rename_still_short_circuits(): void
    {
        $this->assertMatchesRegularExpression(
            '~dept_key\(\$oldName\)\s*===\s*dept_key\(\$newName\)\)\s*return;~',
            $this->cascade(),
            'a rename to the same name must not scan every staff row');
    }
}
