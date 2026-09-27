<?php
/**
 * WHO COUNTS AS TEACHING STAFF.
 *
 * One definition, used by the teacher pickers and by the cover rules. It is
 * deliberately generous, because ZenX carries staff records from three eras and
 * dropping any of them would quietly shrink the pool of people who can be asked
 * to cover — which reads on screen as "nobody is available" rather than as a
 * data problem.
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../application/libraries/Teaching_staff.php';

final class TeachingStaffRuleTest extends TestCase
{
    // -- the three eras of staff record -----------------------------------

    public function test_a_graded_role_marks_a_teacher(): void
    {
        $this->assertTrue(Teaching_staff::isTeaching(['staff_roles' => ['ROLE_TEACHER']]));
        $this->assertTrue(Teaching_staff::isTeaching(['primary_role' => 'ROLE_TEACHER']));
    }

    public function test_teaching_subjects_mark_a_teacher(): void
    {
        $this->assertTrue(Teaching_staff::isTeaching(['teaching_subjects' => ['Maths']]),
            'somebody with subjects assigned to them teaches');
    }

    public function test_a_legacy_designation_marks_a_teacher(): void
    {
        foreach (['Maths Teacher', 'teacher', 'TGT', 'PGT', 'Senior Lecturer',
                  'Visiting Faculty', 'Head of Department'] as $title) {
            $this->assertTrue(
                Teaching_staff::isTeaching(['Position' => $title]),
                "'{$title}' is a teaching designation in the schools ZenX serves");
        }
    }

    public function test_the_designation_is_matched_whatever_its_case(): void
    {
        $this->assertTrue(Teaching_staff::isTeaching(['designation' => 'MATHS TEACHER']));
        $this->assertTrue(Teaching_staff::isTeaching(['position' => 'tgt']));
    }

    // -- and who does not ---------------------------------------------------

    public function test_support_staff_are_not_teaching_staff(): void
    {
        foreach (['Driver', 'Security Guard', 'Peon', 'Accountant', 'Receptionist'] as $title) {
            $this->assertFalse(
                Teaching_staff::isTeaching(['Position' => $title]),
                "'{$title}' does not teach lessons");
        }
    }

    public function test_a_record_saying_nothing_is_not_assumed_to_teach(): void
    {
        $this->assertFalse(Teaching_staff::isTeaching([]));
        $this->assertFalse(Teaching_staff::isTeaching(['teaching_subjects' => []]));
        $this->assertFalse(Teaching_staff::isTeaching(['Position' => '   ']));
    }

    // -- subjects -----------------------------------------------------------

    public function test_subjects_are_read_from_either_field(): void
    {
        $this->assertSame(['Maths'],
            Teaching_staff::subjects(['teaching_subjects' => ['Maths']]));
        $this->assertSame(['English'],
            Teaching_staff::subjects(['subjects' => ['English']]));
    }

    public function test_blank_subjects_are_dropped(): void
    {
        $this->assertSame(['Maths'],
            Teaching_staff::subjects(['teaching_subjects' => ['Maths', '', '  ']]));
    }

    public function test_a_record_with_no_subjects_yields_an_empty_list(): void
    {
        $this->assertSame([], Teaching_staff::subjects([]));
        $this->assertSame([], Teaching_staff::subjects(['teaching_subjects' => 'Maths']),
            'a string is not a list of subjects and is not guessed at');
    }

    // -- names --------------------------------------------------------------

    public function test_a_name_is_read_from_whichever_field_carries_it(): void
    {
        $this->assertSame('Anita Rao', Teaching_staff::name(['Name' => 'Anita Rao']));
        $this->assertSame('Anita Rao', Teaching_staff::name(['name' => 'Anita Rao']));
        $this->assertSame('Anita Rao', Teaching_staff::name(['staffName' => 'Anita Rao']));
    }

    public function test_a_record_with_no_name_says_so_rather_than_guessing(): void
    {
        $this->assertSame('', Teaching_staff::name(['staffId' => 'STA0031']),
            'an identifier is not a name; the caller decides what to show instead');
    }
}
