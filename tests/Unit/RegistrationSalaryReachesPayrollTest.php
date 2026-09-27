<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * SAL-D1 — the hire -> payroll seam.
 *
 * Staff registration captures Basic Salary and Allowances and builds a full
 * salary structure from them. It wrote that structure to the PRIVATE MIRROR
 * (staffPrivate.salaryStructure) and nowhere else.
 *
 * But the mirror is not the payroll authority. Both readers that matter --
 * Compensation_reader::resolveLegacy(), which is what payroll resolves a salary
 * through, and Hr::get_salary_structures(), which is what HR sees on screen --
 * read salarySlips/{schoolId}_SAL_{staffId}, the document Hr::_fsSyncSalary()
 * writes.
 *
 * So a salary entered on the hiring form was invisible to both: HR showed a
 * blank structure, and payroll reported the employee as having no effective
 * salary. The employee could not be paid until somebody re-keyed the figure on
 * a different screen.
 *
 * Found by hiring an employee and trying to pay them, not by reading the code:
 * every writer and every reader is individually correct.
 */
final class RegistrationSalaryReachesPayrollTest extends TestCase
{
    private static string $staff  = '';
    private static string $hr     = '';
    private static string $reader = '';

    public static function setUpBeforeClass(): void
    {
        $root = __DIR__ . '/../../application/';
        self::$staff  = (string) file_get_contents($root . 'controllers/Staff.php');
        self::$hr     = (string) file_get_contents($root . 'controllers/Hr.php');
        self::$reader = (string) file_get_contents($root . 'libraries/Compensation_reader.php');
    }

    public static function tearDownAfterClass(): void
    {
        self::$staff = self::$hr = self::$reader = '';
    }

    /** The premise: the payroll authority reads the SAL_ document. */
    public function test_the_payroll_authority_reads_the_canonical_document(): void
    {
        $this->assertStringContainsString(
            'getEntity(\'salarySlips\', "SAL_{$staffId}")', self::$reader,
            'if the payroll authority moves, this whole defect changes shape');
    }

    /** And so does the screen HR reads a salary from. */
    public function test_the_hr_screen_reads_the_same_canonical_document(): void
    {
        $i = strpos(self::$hr, 'public function get_salary_structures');
        $this->assertNotFalse($i);

        $body = substr(self::$hr, (int) $i, 900);
        $this->assertStringContainsString('docId("SAL_{$staffId}")', $body);
    }

    /**
     * DEFECT SAL-D1: registration must write that document, not only the
     * private mirror.
     */
    public function test_registration_writes_the_canonical_salary_document(): void
    {
        $i = strpos(self::$staff, 'private function _sync_salary_structure');
        $this->assertNotFalse($i, '_sync_salary_structure() has moved');

        $end  = strpos(self::$staff, "\n    private function _fs_sync_canonical_salary", (int) $i);
        $body = substr(self::$staff, (int) $i, (int) $end - (int) $i);

        $this->assertStringContainsString('_fs_sync_canonical_salary($staffId, $structure)', $body,
            'a salary captured at hire never reaches payroll unless registration '
            . 'writes the canonical salarySlips document');
    }

    /** The two writers must agree on the document shape, or payroll drifts. */
    public function test_both_writers_produce_the_same_document_shape(): void
    {
        $i    = strpos(self::$staff, 'private function _fs_sync_canonical_salary');
        $this->assertNotFalse($i, 'the canonical writer is missing entirely');
        $mine = substr(self::$staff, (int) $i, 1200);

        $j    = strpos(self::$hr, 'private function _fsSyncSalary');
        $his  = substr(self::$hr, (int) $j, 1200);

        foreach (["'schoolId'", "'staffId'", "'type'", "'salary_structure'", "'updatedAt'"] as $field) {
            $this->assertStringContainsString($field, $mine, "registration writer is missing {$field}");
            $this->assertStringContainsString($field, $his,  "HR writer is missing {$field}");
        }

        // Same doc id, or they are two different documents.
        $this->assertStringContainsString('docId("SAL_{$staffId}")', $mine);
        $this->assertStringContainsString('docId("SAL_{$staffId}")', $his);

        // The audit trail stays off the shared document in both.
        $this->assertStringContainsString("unset(\$doc['_prev'])", $mine);
        $this->assertStringContainsString("unset(\$fsData['_prev'])", $his);
    }

    /**
     * A failed canonical write must be LOUD. Silently keeping the mirror
     * up to date while payroll cannot see the salary is the original defect
     * with extra steps.
     */
    public function test_a_failed_canonical_write_is_logged_as_an_error(): void
    {
        $i    = strpos(self::$staff, 'private function _fs_sync_canonical_salary');
        $body = substr(self::$staff, (int) $i, 1200);

        $this->assertStringContainsString("log_message('error'", $body);
        $this->assertStringContainsString('not payable', $body,
            'the log line should say what the consequence is');
    }
}