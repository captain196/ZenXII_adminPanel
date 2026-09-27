<?php
/**
 * THE IDENTIFIERS AND NAMES A REQUEST CAN CLAIM.
 *
 * Everything about who is covering arrives from the browser: the substitute's
 * staff id, and the name shown beside it. The clash checks all reason about
 * those ids, but nothing established that they belong to anybody.
 *
 * So a request could book "STA_NOBODY" as "Headmaster", and the cover would be
 * stored, shown on the day, pushed at a device id that does not exist, and
 * counted in the cover log — a class with nobody in front of it and a name in
 * the record that came from the request rather than from the staff list.
 *
 * The UI never does this. That is not the point: the endpoint is reachable by
 * anybody who can type a URL, and the panel is not the security boundary.
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../application/libraries/Substitute_service.php';
require_once __DIR__ . '/SubstituteConcurrentAssignmentTest.php';

final class SubstituteIdentifierIntegrityTest extends TestCase
{
    private const SCHOOL = 'SCH_SUB01';
    private const SESS   = '2026-27';
    private const DATE   = '2026-09-07';          // a Monday
    private const DAY    = 'Monday';

    private function svc(FakeSubstituteFirestore $fb): Substitute_service
    {
        return (new Substitute_service())->init(
            $fb, null, self::SCHOOL, self::SESS, 'STA_ADMIN', 'R Rao');
    }

    private function timetable(FakeSubstituteFirestore $fb): void
    {
        $fb->docs['timetables'][self::SCHOOL . '_tt1'] = [
            'schoolId' => self::SCHOOL, 'session' => self::SESS, 'day' => self::DAY,
            'className' => 'Class 9th', 'section' => 'Section A',
            'periods' => [
                ['periodNumber' => 3, 'subject' => 'Maths', 'teacherId' => 'STA_ABSENT'],
            ],
        ];
    }

    private function staff(FakeSubstituteFirestore $fb, string $id, string $name,
                          array $extra = []): void
    {
        $fb->docs['staff'][self::SCHOOL . '_' . $id] = $extra + [
            'schoolId' => self::SCHOOL, 'staffId' => $id, 'Name' => $name,
            'status' => 'Active',
        ];
    }

    private function save(FakeSubstituteFirestore $fb, string $subId,
                          string $subName = 'Whoever'): array
    {
        return $this->svc($fb)->saveSubstitute('', self::DATE, 'STA_ABSENT', 'A Absent', [[
            'periodNumber' => 3, 'subject' => 'Maths',
            'className' => 'Class 9th', 'section' => 'Section A',
            'substituteTeacherId' => $subId, 'substituteTeacherName' => $subName,
        ]], 'sick');
    }

    // -- the identifier has to belong to somebody --------------------------

    /** THE DEFECT: an invented identifier was accepted and stored. */
    public function test_a_substitute_who_does_not_exist_is_refused(): void
    {
        $fb = new FakeSubstituteFirestore();
        $this->timetable($fb);
        $this->staff($fb, 'STA_ABSENT', 'A Absent');

        try {
            $this->save($fb, 'STA_NOBODY', 'Headmaster');
            $this->fail('a cover cannot be given to somebody who is not on the staff');
        } catch (Service_exception $e) {
            $this->assertMatchesRegularExpression('~staff record~i', $e->getMessage());
        }

        $this->assertSame([], $fb->docs['substitutes'] ?? [], 'and nothing is stored');
    }

    public function test_an_absent_teacher_who_does_not_exist_is_refused(): void
    {
        $fb = new FakeSubstituteFirestore();
        $this->timetable($fb);
        $this->staff($fb, 'STA_COVER', 'Cover Teacher');

        try {
            $this->svc($fb)->saveSubstitute('', self::DATE, 'STA_GHOST', 'Ghost', [[
                'periodNumber' => 3, 'subject' => 'Maths',
                'className' => 'Class 9th', 'section' => 'Section A',
                'substituteTeacherId' => 'STA_COVER',
                'substituteTeacherName' => 'Cover Teacher',
            ]], 'sick');
            $this->fail('cover cannot be arranged for a teacher who does not exist');
        } catch (Service_exception $e) {
            $this->assertMatchesRegularExpression('~staff record~i', $e->getMessage());
        }
    }

    public function test_a_real_member_of_staff_is_accepted(): void
    {
        $fb = new FakeSubstituteFirestore();
        $this->timetable($fb);
        $this->staff($fb, 'STA_ABSENT', 'A Absent');
        $this->staff($fb, 'STA_COVER', 'Cover Teacher');

        $this->assertSame(1, $this->save($fb, 'STA_COVER')['assignments_count']);
    }

    /**
     * Somebody who has since left is still who they were. Editing or ending a
     * cover arranged before they left must stay possible, so existence is the
     * test, not current employment.
     */
    public function test_a_member_of_staff_who_has_left_is_still_a_real_person(): void
    {
        $fb = new FakeSubstituteFirestore();
        $this->timetable($fb);
        $this->staff($fb, 'STA_ABSENT', 'A Absent');
        $this->staff($fb, 'STA_GONE', 'Departed Teacher', ['status' => 'Inactive']);

        $this->assertSame(1, $this->save($fb, 'STA_GONE')['assignments_count'],
            'their record exists, so a cover naming them can still be corrected');
    }

    // -- the name comes from the staff list, not from the request -----------

    /**
     * The name is what everybody sees — on the day, in the cover log, in the
     * teacher's notification. Taking it from the request lets it say anything.
     */
    public function test_the_stored_name_is_the_one_on_the_staff_record(): void
    {
        $fb = new FakeSubstituteFirestore();
        $this->timetable($fb);
        $this->staff($fb, 'STA_ABSENT', 'A Absent');
        $this->staff($fb, 'STA_COVER', 'Anita Rao');

        $this->save($fb, 'STA_COVER', 'Somebody Else Entirely');

        $stored = null;
        foreach ($fb->docs['substitutes'] as $d) { $stored = $d; }
        $this->assertSame('Anita Rao',
            $stored['assignments'][0]['substituteTeacherName'],
            'the record says who it actually is');
    }

    /** A staff record carrying no name does not erase what was submitted. */
    public function test_a_nameless_staff_record_keeps_the_submitted_name(): void
    {
        $fb = new FakeSubstituteFirestore();
        $this->timetable($fb);
        $this->staff($fb, 'STA_ABSENT', 'A Absent');
        $this->staff($fb, 'STA_COVER', '');

        $this->save($fb, 'STA_COVER', 'Cover Teacher');

        $stored = null;
        foreach ($fb->docs['substitutes'] as $d) { $stored = $d; }
        $this->assertSame('Cover Teacher',
            $stored['assignments'][0]['substituteTeacherName']);
    }

    // -- and the check itself has to be trustworthy ------------------------

    /**
     * A staff list that could not be READ is not a school with no staff.
     * Refusing every cover during an outage is wrong; accepting invented
     * identifiers is worse. It denies, and says why.
     */
    public function test_an_unreadable_staff_list_stops_the_save(): void
    {
        $fb = new FakeSubstituteFirestore();
        $this->timetable($fb);
        $this->staff($fb, 'STA_ABSENT', 'A Absent');
        $this->staff($fb, 'STA_COVER', 'Cover Teacher');
        $fb->failCollections = ['staff'];

        try {
            $this->save($fb, 'STA_COVER');
            $this->fail('who these people are could not be established');
        } catch (Service_exception $e) {
            $this->assertMatchesRegularExpression('~try again~i', $e->getMessage());
        }
    }

    /**
     * Tenancy is in the document id, so a real staff member of ANOTHER school
     * simply does not exist here.
     */
    public function test_a_teacher_from_another_school_does_not_exist_here(): void
    {
        $fb = new FakeSubstituteFirestore();
        $this->timetable($fb);
        $this->staff($fb, 'STA_ABSENT', 'A Absent');
        $fb->docs['staff']['SCH_OTHER_STA_THEIRS'] = [
            'schoolId' => 'SCH_OTHER', 'staffId' => 'STA_THEIRS',
            'Name' => 'Another School Teacher', 'status' => 'Active',
        ];

        try {
            $this->save($fb, 'STA_THEIRS');
            $this->fail('another school\'s staff are not available to cover here');
        } catch (Service_exception $e) {
            $this->assertMatchesRegularExpression('~staff record~i', $e->getMessage());
        }
    }
}
