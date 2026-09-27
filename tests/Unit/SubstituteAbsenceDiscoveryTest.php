<?php
/**
 * WHO IS AWAY TODAY.
 *
 * This is the first question of the whole workflow, and the only one where a
 * wrong answer is silent all the way to the classroom: a teacher missing from
 * this list is a teacher nobody arranges cover for, and the first anybody hears
 * of it is thirty children with no adult.
 *
 * Two sources, and the distinction matters. Approved leave is foreseeable
 * absence, known days ahead. An attendance mark of A is unforeseeable — the
 * absence the STPCD says teachers may actually be asked to cover. Both had to
 * be read through catches that swallowed everything, so a failure in either
 * produced a shorter list rather than an error.
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../application/libraries/Substitute_service.php';
require_once __DIR__ . '/SubstituteConcurrentAssignmentTest.php';   // FakeSubstituteFirestore

final class SubstituteAbsenceDiscoveryTest extends TestCase
{
    private const SCHOOL = 'SCH_SUB01';
    private const SESS   = '2026-27';
    private const DATE   = '2026-09-07';

    private function svc(FakeSubstituteFirestore $fb): Substitute_service
    {
        return (new Substitute_service())->init(
            $fb, null, self::SCHOOL, self::SESS, 'STA_ADMIN', 'R Rao');
    }

    private function staff(FakeSubstituteFirestore $fb, string $id, string $name,
                           array $extra = []): void
    {
        $fb->docs['staff'][self::SCHOOL . '_' . $id] = $extra + [
            'schoolId' => self::SCHOOL, 'staffId' => $id, 'Name' => $name,
            'status' => 'Active', 'staff_roles' => ['ROLE_TEACHER'],
        ];
    }

    private function leave(FakeSubstituteFirestore $fb, string $id, string $staffId,
                           string $from, string $to, string $status = 'approved'): void
    {
        $fb->docs['leaveApplications'][$id] = [
            'schoolId' => self::SCHOOL, 'staffId' => $staffId,
            'applicantType' => 'staff', 'status' => $status,
            'fromDate' => $from, 'toDate' => $to, 'leaveType' => 'Casual',
        ];
    }

    private function mark(FakeSubstituteFirestore $fb, string $staffId,
                          string $status, string $date = self::DATE): void
    {
        $fb->docs['staffAttendance'][self::SCHOOL . '_' . $staffId . '_' . $date] = [
            'schoolId' => self::SCHOOL, 'staffId' => $staffId,
            'date' => $date, 'status' => $status,
        ];
    }

    private function ids(array $res): array
    {
        $out = array_column($res['absentTeachers'], 'id');
        sort($out);
        return $out;
    }

    // -- the two sources --------------------------------------------------

    public function test_approved_leave_covering_the_date_makes_a_teacher_absent(): void
    {
        $fb = new FakeSubstituteFirestore();
        $this->staff($fb, 'STA_A', 'Anita Rao');
        $this->leave($fb, 'L1', 'STA_A', '2026-09-05', '2026-09-09');

        $res = $this->svc($fb)->getAbsentTeachers(self::DATE);

        $this->assertSame(['STA_A'], $this->ids($res));
        $this->assertSame('Anita Rao', $res['absentTeachers'][0]['name']);
        $this->assertMatchesRegularExpression('~leave~i', $res['absentTeachers'][0]['reason']);
    }

    public function test_leave_that_does_not_cover_the_date_is_ignored(): void
    {
        $fb = new FakeSubstituteFirestore();
        $this->staff($fb, 'STA_A', 'Anita Rao');
        $this->leave($fb, 'L1', 'STA_A', '2026-09-01', '2026-09-03');

        $this->assertSame([], $this->ids($this->svc($fb)->getAbsentTeachers(self::DATE)));
    }

    public function test_leave_that_is_not_approved_is_ignored(): void
    {
        $fb = new FakeSubstituteFirestore();
        $this->staff($fb, 'STA_A', 'Anita Rao');
        $this->leave($fb, 'L1', 'STA_A', '2026-09-05', '2026-09-09', 'pending');

        $this->assertSame([], $this->ids($this->svc($fb)->getAbsentTeachers(self::DATE)));
    }

    public function test_an_attendance_mark_of_absent_makes_a_teacher_absent(): void
    {
        $fb = new FakeSubstituteFirestore();
        $this->staff($fb, 'STA_B', 'Bhavna Iyer');
        $this->mark($fb, 'STA_B', 'A');

        $res = $this->svc($fb)->getAbsentTeachers(self::DATE);

        $this->assertSame(['STA_B'], $this->ids($res));
        $this->assertSame('Bhavna Iyer', $res['absentTeachers'][0]['name']);
    }

    public function test_a_teacher_marked_present_is_not_absent(): void
    {
        $fb = new FakeSubstituteFirestore();
        $this->staff($fb, 'STA_B', 'Bhavna Iyer');
        $this->mark($fb, 'STA_B', 'P');

        $this->assertSame([], $this->ids($this->svc($fb)->getAbsentTeachers(self::DATE)));
    }

    public function test_a_teacher_absent_in_both_sources_is_listed_once(): void
    {
        $fb = new FakeSubstituteFirestore();
        $this->staff($fb, 'STA_A', 'Anita Rao');
        $this->leave($fb, 'L1', 'STA_A', '2026-09-05', '2026-09-09');
        $this->mark($fb, 'STA_A', 'L');

        $this->assertSame(['STA_A'], $this->ids($this->svc($fb)->getAbsentTeachers(self::DATE)));
    }

    /**
     * Foreseeable and unforeseeable absence are treated differently by every
     * cover policy there is, so the list has to say which this was.
     */
    public function test_the_list_says_whether_the_absence_was_known_in_advance(): void
    {
        $fb = new FakeSubstituteFirestore();
        $this->staff($fb, 'STA_A', 'Anita Rao');
        $this->staff($fb, 'STA_B', 'Bhavna Iyer');
        $this->leave($fb, 'L1', 'STA_A', '2026-09-05', '2026-09-09');
        $this->mark($fb, 'STA_B', 'A');

        $res = $this->svc($fb)->getAbsentTeachers(self::DATE);
        $by = [];
        foreach ($res['absentTeachers'] as $t) { $by[$t['id']] = $t; }

        $this->assertSame('leave', $by['STA_A']['source'],
            'approved leave is foreseeable');
        $this->assertSame('attendance', $by['STA_B']['source'],
            'an attendance mark on the day is not');
    }

    // -- the manual list --------------------------------------------------

    /** The administrator may always name somebody the sources did not. */
    public function test_every_active_staff_member_can_still_be_chosen(): void
    {
        $fb = new FakeSubstituteFirestore();
        $this->staff($fb, 'STA_A', 'Anita Rao');
        $this->staff($fb, 'STA_B', 'Bhavna Iyer');

        $res = $this->svc($fb)->getAbsentTeachers(self::DATE);

        $this->assertCount(2, $res['allTeachers']);
        $this->assertSame('Anita Rao', $res['allTeachers'][0]['name'], 'sorted by name');
    }

    public function test_inactive_staff_are_not_offered(): void
    {
        $fb = new FakeSubstituteFirestore();
        $this->staff($fb, 'STA_A', 'Anita Rao');
        $this->staff($fb, 'STA_GONE', 'Gone Away', ['status' => 'Inactive']);

        $res = $this->svc($fb)->getAbsentTeachers(self::DATE);

        $this->assertSame(['STA_A'], array_column($res['allTeachers'], 'id'));
    }

    // -- no identifiers pretending to be names ----------------------------

    public function test_a_staff_member_with_no_name_is_not_shown_as_an_id(): void
    {
        $fb = new FakeSubstituteFirestore();
        $this->staff($fb, 'STA_X', '');
        $this->mark($fb, 'STA_X', 'A');

        $res = $this->svc($fb)->getAbsentTeachers(self::DATE);

        $this->assertNotSame('STA_X', $res['absentTeachers'][0]['name']);
        $this->assertSame('STA_X', $res['absentTeachers'][0]['staffCode'],
            'the code is still available, labelled as a code and not as a name');
    }

    /**
     * Somebody absent whose staff record is gone is still absent. Dropping them
     * would hide a class that needs cover.
     */
    public function test_an_absence_with_no_matching_staff_record_is_still_listed(): void
    {
        $fb = new FakeSubstituteFirestore();
        $this->mark($fb, 'STA_ORPHAN', 'A');

        $res = $this->svc($fb)->getAbsentTeachers(self::DATE);

        $this->assertSame(['STA_ORPHAN'], $this->ids($res));
        $this->assertNotSame('STA_ORPHAN', $res['absentTeachers'][0]['name']);
    }

    // -- a source that could not be read ----------------------------------

    /**
     * THE DEFECT. Each read sat inside an empty catch, and each of them routed
     * through a helper that returns [] on failure — so the catch could not fire
     * and the failure arrived as a shorter list of absent teachers.
     */
    public function test_an_unreadable_leave_list_stops_the_screen(): void
    {
        $fb = new FakeSubstituteFirestore();
        $this->staff($fb, 'STA_A', 'Anita Rao');
        $fb->failCollections = ['leaveApplications'];

        try {
            $this->svc($fb)->getAbsentTeachers(self::DATE);
            $this->fail('a failed leave read must not pass as nobody being on leave');
        } catch (Service_exception $e) {
            $this->assertMatchesRegularExpression('~try again~i', $e->getMessage());
        }
    }

    public function test_an_unreadable_attendance_list_stops_the_screen(): void
    {
        $fb = new FakeSubstituteFirestore();
        $this->staff($fb, 'STA_A', 'Anita Rao');
        $fb->failCollections = ['staffAttendance'];

        try {
            $this->svc($fb)->getAbsentTeachers(self::DATE);
            $this->fail('a failed attendance read must not pass as everybody present');
        } catch (Service_exception $e) {
            $this->assertMatchesRegularExpression('~try again~i', $e->getMessage());
        }
    }

    public function test_an_unreadable_staff_list_stops_the_screen(): void
    {
        $fb = new FakeSubstituteFirestore();
        $fb->failCollections = ['staff'];

        try {
            $this->svc($fb)->getAbsentTeachers(self::DATE);
            $this->fail('without the staff list there is nobody to name or to choose');
        } catch (Service_exception $e) {
            $this->assertMatchesRegularExpression('~try again~i', $e->getMessage());
        }
    }

    /**
     * A scan that hit its bound has not seen everybody either. The teacher
     * whose row fell off the end is still away, and still has nobody covering
     * them, so a shortened list is refused rather than shown.
     */
    public function test_a_staff_list_that_hit_its_bound_stops_the_screen(): void
    {
        $fb = new FakeSubstituteFirestore();
        for ($i = 0; $i <= Substitute_service::STAFF_SCAN_LIMIT; $i++) {
            $this->staff($fb, 'STA_' . $i, 'Teacher ' . $i);
        }

        try {
            $this->svc($fb)->getAbsentTeachers(self::DATE);
            $this->fail('a half-read staff list must not be presented as the school');
        } catch (Service_exception $e) {
            $this->assertMatchesRegularExpression('~too many~i', $e->getMessage());
        }
    }

    public function test_a_day_when_nobody_is_away_is_an_ordinary_empty_list(): void
    {
        $fb = new FakeSubstituteFirestore();
        $this->staff($fb, 'STA_A', 'Anita Rao');

        $res = $this->svc($fb)->getAbsentTeachers(self::DATE);

        $this->assertSame([], $res['absentTeachers']);
        $this->assertCount(1, $res['allTeachers']);
    }

    public function test_a_date_is_required(): void
    {
        $fb = new FakeSubstituteFirestore();

        try {
            $this->svc($fb)->getAbsentTeachers('not-a-date');
            $this->fail('a date is required');
        } catch (Service_exception $e) {
            $this->assertMatchesRegularExpression('~date~i', $e->getMessage());
        }
    }
}
