<?php
/**
 * HOW MUCH COVER ONE TEACHER MAY BE GIVEN.
 *
 * There is no universal answer, and the research says so plainly. In England the
 * STPCD requires that teachers cover "only rarely" but sets NO number — schools
 * write their own policy and are expected to keep a cover log so the burden is
 * shared equitably over the year. CBSE's affiliation bye-laws cap total teaching
 * load at three quarters of the weekly periods, which is a WEEKLY limit on
 * teaching, not a daily one on cover. Untis, meanwhile, keeps a count of periods
 * each teacher has covered and feeds it into its suggestions rather than
 * enforcing a ceiling.
 *
 * So: a school sets the number, in periods per day and per week; ZenX enforces
 * whatever the school set and enforces nothing when nothing is set. Inventing a
 * default cap would impose one school's policy on every other school, and a
 * school that has set a cap must have it honoured even when the person arranging
 * cover is in a hurry.
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../application/libraries/Substitute_service.php';
require_once __DIR__ . '/SubstituteConcurrentAssignmentTest.php';   // FakeSubstituteFirestore

final class SubstituteCoverPolicyTest extends TestCase
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

    private function policy(FakeSubstituteFirestore $fb, array $fields): void
    {
        $fb->docs['substitutePolicy'][self::SCHOOL . '_' . self::SESS] =
            $fields + ['schoolId' => self::SCHOOL, 'session' => self::SESS,
                       '__updateTime' => '10'];
    }

    /** A day where the absent teacher has plenty of periods to cover. */
    private function timetable(FakeSubstituteFirestore $fb): void
    {
        $periods = [];
        for ($p = 1; $p <= 8; $p++) {
            $periods[] = ['periodNumber' => $p, 'subject' => 'Maths',
                          'teacherId' => 'STA_ABSENT'];
        }
        $fb->docs['timetables'][self::SCHOOL . '_tt1'] = [
            'schoolId' => self::SCHOOL, 'session' => self::SESS, 'day' => self::DAY,
            'className' => 'Class 9th', 'section' => 'Section A',
            'periods' => $periods,
        ];
        $fb->ensureStaff(self::SCHOOL, ['STA_ABSENT', 'STA_OTHER', 'STA_THIRD',
            'STA_SUN', 'STA_COVER', 'STA_COVER_A', 'STA_COVER_B']);
    }

    private function assignments(array $periods, string $subId = 'STA_COVER'): array
    {
        return array_map(fn($p) => [
            'periodNumber' => $p, 'subject' => 'Maths',
            'className' => 'Class 9th', 'section' => 'Section A',
            'substituteTeacherId' => $subId, 'substituteTeacherName' => 'Cover Teacher',
        ], $periods);
    }

    private function save(FakeSubstituteFirestore $fb, array $periods,
                          string $absent = 'STA_ABSENT', string $date = self::DATE,
                          string $id = ''): array
    {
        return $this->svc($fb)->saveSubstitute($id, $date, $absent, 'A Absent',
            $this->assignments($periods), 'sick');
    }

    // -- nothing set, nothing enforced ------------------------------------

    /**
     * A school that has set no policy is not a school with a policy of zero.
     * Every existing school is in exactly this position.
     */
    public function test_a_school_with_no_policy_is_not_limited(): void
    {
        $fb = new FakeSubstituteFirestore();
        $this->timetable($fb);

        $res = $this->save($fb, [1, 2, 3, 4, 5, 6]);

        $this->assertSame(6, $res['assignments_count'],
            'six cover periods stand where no school policy says otherwise');
    }

    public function test_a_limit_of_zero_means_not_limited(): void
    {
        $fb = new FakeSubstituteFirestore();
        $this->timetable($fb);
        $this->policy($fb, ['maxCoverPeriodsPerDay' => 0]);

        $res = $this->save($fb, [1, 2, 3, 4]);

        $this->assertSame(4, $res['assignments_count']);
    }

    // -- the daily cap ----------------------------------------------------

    public function test_cover_within_the_daily_cap_is_allowed(): void
    {
        $fb = new FakeSubstituteFirestore();
        $this->timetable($fb);
        $this->policy($fb, ['maxCoverPeriodsPerDay' => 2]);

        $res = $this->save($fb, [1, 2]);

        $this->assertSame(2, $res['assignments_count']);
    }

    public function test_one_save_may_not_exceed_the_daily_cap(): void
    {
        $fb = new FakeSubstituteFirestore();
        $this->timetable($fb);
        $this->policy($fb, ['maxCoverPeriodsPerDay' => 2]);

        try {
            $this->save($fb, [1, 2, 3]);
            $this->fail('three cover periods must not pass a cap of two');
        } catch (Service_exception $e) {
            // The name comes from the staff record now, not from the request,
            // so the refusal calls the person what the school calls them.
            $this->assertMatchesRegularExpression('~Staff STA_COVER~', $e->getMessage(),
                'the refusal names the teacher it is about');
            $this->assertMatchesRegularExpression('~2~', $e->getMessage(),
                'and the number the school set');
        }

        $this->assertSame([], $fb->docs['substitutes'] ?? [],
            'nothing is saved when the cap refuses it');
    }

    /** The cap counts cover already arranged that day, not just this save. */
    public function test_the_cap_counts_cover_already_arranged_that_day(): void
    {
        $fb = new FakeSubstituteFirestore();
        $this->timetable($fb);
        $this->policy($fb, ['maxCoverPeriodsPerDay' => 2]);

        $this->save($fb, [1, 2]);

        try {
            $this->svc($fb)->saveSubstitute('', self::DATE, 'STA_OTHER', 'O Other',
                $this->assignments([5]), 'sick');
            $this->fail('a third cover period on the same day must be refused');
        } catch (Service_exception $e) {
            $this->assertMatchesRegularExpression('~already~i', $e->getMessage());
        }
    }

    /** A cancelled cover is not a period anybody is covering. */
    public function test_cancelled_cover_does_not_count_towards_the_cap(): void
    {
        $fb = new FakeSubstituteFirestore();
        $this->timetable($fb);
        $this->policy($fb, ['maxCoverPeriodsPerDay' => 2]);

        $first = $this->save($fb, [1, 2]);
        $this->svc($fb)->updateSubstituteStatus($first['id'], 'cancelled');

        $res = $this->svc($fb)->saveSubstitute('', self::DATE, 'STA_OTHER', 'O Other',
            $this->assignments([5, 6]), 'sick');

        $this->assertSame(2, $res['assignments_count'],
            'the released periods are available again');
    }

    /** Editing a cover must not count that cover against itself. */
    public function test_editing_a_cover_does_not_count_it_twice(): void
    {
        $fb = new FakeSubstituteFirestore();
        $this->timetable($fb);
        $this->policy($fb, ['maxCoverPeriodsPerDay' => 2]);

        $first = $this->save($fb, [1, 2]);

        $res = $this->svc($fb)->saveSubstitute($first['id'], self::DATE,
            'STA_ABSENT', 'A Absent', $this->assignments([3, 4]), 'sick');

        $this->assertSame(2, $res['assignments_count']);
    }

    /** Different teachers each have their own allowance. */
    public function test_the_cap_is_per_teacher_not_per_school(): void
    {
        $fb = new FakeSubstituteFirestore();
        $this->timetable($fb);
        $this->policy($fb, ['maxCoverPeriodsPerDay' => 1]);

        $this->svc($fb)->saveSubstitute('', self::DATE, 'STA_ABSENT', 'A Absent',
            $this->assignments([1], 'STA_COVER_A'), 'sick');
        $res = $this->svc($fb)->saveSubstitute('', self::DATE, 'STA_OTHER', 'O Other',
            $this->assignments([2], 'STA_COVER_B'), 'sick');

        $this->assertSame(1, $res['assignments_count']);
    }

    // -- the weekly cap ---------------------------------------------------

    /**
     * CBSE's load limit is weekly, so a weekly ceiling has to be expressible
     * independently of the daily one.
     */
    public function test_the_weekly_cap_counts_across_the_whole_week(): void
    {
        $fb = new FakeSubstituteFirestore();
        $this->timetable($fb);
        $this->policy($fb, ['maxCoverPeriodsPerWeek' => 3]);

        $this->save($fb, [1, 2]);                                  // Monday
        $this->save($fb, [1], 'STA_OTHER', '2026-09-09');          // Wednesday

        try {
            $this->save($fb, [2], 'STA_THIRD', '2026-09-10');      // Thursday
            $this->fail('a fourth cover period in one week must be refused');
        } catch (Service_exception $e) {
            $this->assertMatchesRegularExpression('~week~i', $e->getMessage(),
                'the refusal says which limit was reached');
        }
    }

    /** The next week starts the count again. */
    public function test_the_weekly_cap_does_not_run_into_the_following_week(): void
    {
        $fb = new FakeSubstituteFirestore();
        $this->timetable($fb);
        $this->policy($fb, ['maxCoverPeriodsPerWeek' => 2]);

        $this->save($fb, [1, 2]);                                  // Mon 7 Sep

        $res = $this->save($fb, [1], 'STA_OTHER', '2026-09-14');   // Mon 14 Sep

        $this->assertSame(1, $res['assignments_count']);
    }

    /**
     * The week has to start on the right day. Sunday the 6th and Monday the
     * 7th are different weeks, and a boundary off by one silently lets a
     * teacher's Monday count against the week that has just ended.
     */
    public function test_the_week_starts_on_monday(): void
    {
        $fb = new FakeSubstituteFirestore();
        $this->timetable($fb);
        $this->policy($fb, ['maxCoverPeriodsPerWeek' => 2]);

        // Sunday 6 September — the week BEFORE the one being filled.
        $this->save($fb, [1, 2], 'STA_SUN', '2026-09-06');

        // Monday 7th starts a fresh week, so two more are allowed.
        $res = $this->save($fb, [3, 4]);

        $this->assertSame(2, $res['assignments_count'],
            "Sunday's cover belongs to the previous week");
    }

    public function test_cancelled_cover_does_not_count_towards_the_week(): void
    {
        $fb = new FakeSubstituteFirestore();
        $this->timetable($fb);
        $this->policy($fb, ['maxCoverPeriodsPerWeek' => 2]);

        $first = $this->save($fb, [1, 2]);
        $this->svc($fb)->updateSubstituteStatus($first['id'], 'cancelled');

        $res = $this->save($fb, [3, 4], 'STA_OTHER', '2026-09-09');

        $this->assertSame(2, $res['assignments_count'],
            'the released periods are available again in the same week');
    }

    public function test_editing_does_not_count_a_cover_against_its_own_week(): void
    {
        $fb = new FakeSubstituteFirestore();
        $this->timetable($fb);
        $this->policy($fb, ['maxCoverPeriodsPerWeek' => 2]);

        $first = $this->save($fb, [1, 2]);
        $res = $this->save($fb, [3, 4], 'STA_ABSENT', self::DATE, $first['id']);

        $this->assertSame(2, $res['assignments_count'],
            'a cover being edited is not competing with itself');
    }

    // -- who the school lets cover ----------------------------------------

    public function test_a_school_allowing_support_staff_is_not_stopped(): void
    {
        $fb = new FakeSubstituteFirestore();
        $this->timetable($fb);
        $fb->docs['staff'][self::SCHOOL . '_STA_COVER'] = [
            'schoolId' => self::SCHOOL, 'staffId' => 'STA_COVER',
            'Name' => 'Cover Teacher', 'status' => 'Active', 'Position' => 'Librarian',
        ];

        $res = $this->save($fb, [1]);

        $this->assertSame(1, $res['assignments_count'],
            'supervision by support staff is normal in many schools');
    }

    public function test_a_school_requiring_teaching_staff_refuses_support_staff(): void
    {
        $fb = new FakeSubstituteFirestore();
        $this->timetable($fb);
        $this->policy($fb, ['teachingStaffOnly' => true]);
        $fb->docs['staff'][self::SCHOOL . '_STA_COVER'] = [
            'schoolId' => self::SCHOOL, 'staffId' => 'STA_COVER',
            'Name' => 'Cover Teacher', 'status' => 'Active', 'Position' => 'Librarian',
        ];

        try {
            $this->save($fb, [1]);
            $this->fail('this school allows only teaching staff to cover');
        } catch (Service_exception $e) {
            $this->assertMatchesRegularExpression('~teaching staff~i', $e->getMessage());
        }
    }

    public function test_a_school_requiring_teaching_staff_accepts_a_teacher(): void
    {
        $fb = new FakeSubstituteFirestore();
        $this->timetable($fb);
        $this->policy($fb, ['teachingStaffOnly' => true]);
        $fb->docs['staff'][self::SCHOOL . '_STA_COVER'] = [
            'schoolId' => self::SCHOOL, 'staffId' => 'STA_COVER',
            'Name' => 'Cover Teacher', 'status' => 'Active',
            'staff_roles' => ['ROLE_TEACHER'],
        ];

        $this->assertSame(1, $this->save($fb, [1])['assignments_count']);
    }

    /**
     * A rule cannot be applied to somebody there is no record of. Passing
     * them silently would apply the school's rule to everybody except the
     * people it cannot see.
     */
    public function test_a_candidate_with_no_staff_record_is_refused_under_a_rule(): void
    {
        $fb = new FakeSubstituteFirestore();
        $this->timetable($fb);
        $this->policy($fb, ['teachingStaffOnly' => true]);
        // No staff document for STA_COVER at all — the shared fixture seeds one,
        // and this test exists precisely for the case where none is there.
        unset($fb->docs['staff'][self::SCHOOL . '_STA_COVER']);

        try {
            $this->save($fb, [1]);
            $this->fail('there is no record to check the rule against');
        } catch (Service_exception $e) {
            $this->assertMatchesRegularExpression('~staff record~i', $e->getMessage());
        }
    }

    // -- the policy read is itself a guard --------------------------------

    /**
     * The cap is read NEGATIVELY: no cap found means nobody is limited. A failed
     * read would therefore quietly suspend the school's own policy at exactly
     * the moment somebody is arranging cover.
     */
    public function test_a_policy_that_cannot_be_read_stops_the_save(): void
    {
        $fb = new FakeSubstituteFirestore();
        $this->timetable($fb);
        $this->policy($fb, ['maxCoverPeriodsPerDay' => 1]);
        $fb->failCollections = ['substitutePolicy'];

        try {
            $this->save($fb, [1]);
            $this->fail('cover must not be arranged against an unknown policy');
        } catch (Service_exception $e) {
            $this->assertMatchesRegularExpression('~try again~i', $e->getMessage());
        }

        $fb->failCollections = [];
        $this->assertSame([], $fb->docs['substitutes'] ?? [], 'nothing was saved');
    }

    // -- setting the policy -----------------------------------------------

    public function test_the_policy_can_be_set_and_read_back(): void
    {
        $fb = new FakeSubstituteFirestore();

        $this->svc($fb)->savePolicy([
            'maxCoverPeriodsPerDay'  => 2,
            'maxCoverPeriodsPerWeek' => 6,
            'requireSubjectMatch'    => true,
            'teachingStaffOnly'      => true,
        ]);

        $p = $this->svc($fb)->getPolicy();

        $this->assertSame(2, $p['maxCoverPeriodsPerDay']);
        $this->assertSame(6, $p['maxCoverPeriodsPerWeek']);
        $this->assertTrue($p['requireSubjectMatch']);
        $this->assertTrue($p['teachingStaffOnly']);
    }

    public function test_an_unset_policy_reads_as_no_limits(): void
    {
        $fb = new FakeSubstituteFirestore();

        $p = $this->svc($fb)->getPolicy();

        $this->assertSame(0, $p['maxCoverPeriodsPerDay']);
        $this->assertSame(0, $p['maxCoverPeriodsPerWeek']);
        $this->assertFalse($p['requireSubjectMatch']);
        $this->assertFalse($p['teachingStaffOnly']);
        $this->assertFalse($p['configured'],
            'the screen can tell "no policy set" from "a policy of no limits"');
    }

    public function test_a_negative_limit_is_refused(): void
    {
        $fb = new FakeSubstituteFirestore();

        try {
            $this->svc($fb)->savePolicy(['maxCoverPeriodsPerDay' => -1]);
            $this->fail('a negative number of periods is not a policy');
        } catch (Service_exception $e) {
            $this->assertMatchesRegularExpression('~cannot be negative|0 or more~i',
                $e->getMessage());
        }
    }

    /** A weekly ceiling below the daily one cannot ever be satisfied. */
    public function test_a_weekly_limit_below_the_daily_limit_is_refused(): void
    {
        $fb = new FakeSubstituteFirestore();

        try {
            $this->svc($fb)->savePolicy([
                'maxCoverPeriodsPerDay'  => 4,
                'maxCoverPeriodsPerWeek' => 2,
            ]);
            $this->fail('a week that allows less than a day is not a policy');
        } catch (Service_exception $e) {
            $this->assertMatchesRegularExpression('~week~i', $e->getMessage());
        }
    }

    // -- the policy survives the turn of the year -------------------------

    /**
     * A school that set a limit in one session has not withdrawn it by
     * reaching the next one. The policy document is keyed by session, like
     * every other setting in this module, so a new session starts with no
     * document of its own — and "no document" means "nothing is limited".
     *
     * Silently losing a safety limit at rollover is not a decision anybody
     * made. The previous session's policy is carried forward until somebody
     * writes one for this session, and the screen says it was inherited so
     * the school can see what it is being held to.
     */
    public function test_a_policy_carries_forward_into_a_new_session(): void
    {
        $fb = new FakeSubstituteFirestore();
        $fb->docs['substitutePolicy'][self::SCHOOL . '_2025-26'] = [
            'schoolId' => self::SCHOOL, 'session' => '2025-26',
            'maxCoverPeriodsPerDay' => 2, 'requireSubjectMatch' => true,
        ];

        $p = $this->svc($fb)->getPolicy();          // this session: 2026-27

        $this->assertSame(2, $p['maxCoverPeriodsPerDay'],
            "last session's limit still holds until somebody changes it");
        $this->assertTrue($p['requireSubjectMatch']);
        $this->assertTrue($p['configured']);
        $this->assertSame('2025-26', $p['inheritedFrom'],
            'and the screen can say where it came from');
    }

    /** The newest prior session wins, not merely any of them. */
    public function test_the_most_recent_policy_is_the_one_carried_forward(): void
    {
        $fb = new FakeSubstituteFirestore();
        $fb->docs['substitutePolicy'][self::SCHOOL . '_2024-25'] = [
            'schoolId' => self::SCHOOL, 'session' => '2024-25',
            'maxCoverPeriodsPerDay' => 9,
        ];
        $fb->docs['substitutePolicy'][self::SCHOOL . '_2025-26'] = [
            'schoolId' => self::SCHOOL, 'session' => '2025-26',
            'maxCoverPeriodsPerDay' => 2,
        ];

        $p = $this->svc($fb)->getPolicy();

        $this->assertSame(2, $p['maxCoverPeriodsPerDay']);
        $this->assertSame('2025-26', $p['inheritedFrom']);
    }

    /** A policy written for THIS session is not overruled by an older one. */
    public function test_this_session_s_own_policy_wins(): void
    {
        $fb = new FakeSubstituteFirestore();
        $fb->docs['substitutePolicy'][self::SCHOOL . '_2025-26'] = [
            'schoolId' => self::SCHOOL, 'session' => '2025-26',
            'maxCoverPeriodsPerDay' => 2,
        ];
        $this->policy($fb, ['maxCoverPeriodsPerDay' => 4]);

        $p = $this->svc($fb)->getPolicy();

        $this->assertSame(4, $p['maxCoverPeriodsPerDay']);
        $this->assertSame('', $p['inheritedFrom']);
    }

    /** A carried-forward limit is enforced, not merely displayed. */
    public function test_a_carried_forward_limit_is_actually_enforced(): void
    {
        $fb = new FakeSubstituteFirestore();
        $this->timetable($fb);
        $fb->docs['substitutePolicy'][self::SCHOOL . '_2025-26'] = [
            'schoolId' => self::SCHOOL, 'session' => '2025-26',
            'maxCoverPeriodsPerDay' => 1,
        ];

        try {
            $this->save($fb, [1, 2]);
            $this->fail("last session's limit still applies");
        } catch (Service_exception $e) {
            $this->assertMatchesRegularExpression('~1 per day~', $e->getMessage());
        }
    }

    /**
     * A policy written ahead for NEXT year is not in force this year. Only
     * sessions that have already happened can be carried forward.
     */
    public function test_a_policy_for_a_future_session_is_not_applied_yet(): void
    {
        $fb = new FakeSubstituteFirestore();
        $fb->docs['substitutePolicy'][self::SCHOOL . '_2027-28'] = [
            'schoolId' => self::SCHOOL, 'session' => '2027-28',
            'maxCoverPeriodsPerDay' => 1,
        ];

        $p = $this->svc($fb)->getPolicy();          // this session: 2026-27

        $this->assertFalse($p['configured'],
            'next year' . '\x27' . 's policy does not bind this year');
        $this->assertSame(0, $p['maxCoverPeriodsPerDay']);
        $this->assertSame('', $p['inheritedFrom']);
    }

    /** A school that never set one is still limited by nothing. */
    public function test_a_school_that_never_set_a_policy_inherits_nothing(): void
    {
        $fb = new FakeSubstituteFirestore();

        $p = $this->svc($fb)->getPolicy();

        $this->assertFalse($p['configured']);
        $this->assertSame('', $p['inheritedFrom']);
        $this->assertSame(0, $p['maxCoverPeriodsPerDay']);
    }

    /**
     * The search for an older policy is itself a read the limits depend on.
     * Failing it quietly would drop the school's rule at the rollover — the
     * exact moment this carry-forward exists to protect.
     */
    public function test_a_failed_search_for_an_older_policy_stops_the_save(): void
    {
        $fb = new FakeSubstituteFirestore();
        $this->timetable($fb);
        $fb->failCollections = ['substitutePolicy'];

        try {
            $this->save($fb, [1]);
            $this->fail('the policy could not be established');
        } catch (Service_exception $e) {
            $this->assertMatchesRegularExpression('~try again~i', $e->getMessage());
        }
    }

    // -- subject match, when the school requires it -----------------------

    /**
     * Whether cover must be by a subject specialist is a real difference between
     * schools: a primary school covers within the class, a senior school often
     * insists on the subject. Neither is the universal answer, so the school says.
     */
    public function test_subject_match_is_not_required_unless_the_school_says_so(): void
    {
        $fb = new FakeSubstituteFirestore();
        $this->timetable($fb);
        $fb->docs['staff'][self::SCHOOL . '_STA_COVER'] = [
            'schoolId' => self::SCHOOL, 'staffId' => 'STA_COVER', 'Name' => 'Cover Teacher',
            'status' => 'Active', 'subjects' => ['English'],
        ];

        $res = $this->save($fb, [1]);

        $this->assertSame(1, $res['assignments_count']);
    }

    public function test_a_school_requiring_subject_match_refuses_a_mismatch(): void
    {
        $fb = new FakeSubstituteFirestore();
        $this->timetable($fb);
        $this->policy($fb, ['requireSubjectMatch' => true]);
        $fb->docs['staff'][self::SCHOOL . '_STA_COVER'] = [
            'schoolId' => self::SCHOOL, 'staffId' => 'STA_COVER', 'Name' => 'Cover Teacher',
            'status' => 'Active', 'subjects' => ['English'],
        ];

        try {
            $this->save($fb, [1]);
            $this->fail('this school requires the cover to teach the subject');
        } catch (Service_exception $e) {
            $this->assertMatchesRegularExpression('~Maths~', $e->getMessage(),
                'the refusal names the subject that was needed');
        }
    }

    public function test_a_school_requiring_subject_match_accepts_a_specialist(): void
    {
        $fb = new FakeSubstituteFirestore();
        $this->timetable($fb);
        $this->policy($fb, ['requireSubjectMatch' => true]);
        $fb->docs['staff'][self::SCHOOL . '_STA_COVER'] = [
            'schoolId' => self::SCHOOL, 'staffId' => 'STA_COVER', 'Name' => 'Cover Teacher',
            'status' => 'Active', 'subjects' => ['Maths', 'Physics'],
        ];

        $res = $this->save($fb, [1]);

        $this->assertSame(1, $res['assignments_count']);
    }

    /**
     * A staff record that cannot be read is not proof the teacher lacks the
     * subject. Refusing on that basis would block cover during an outage.
     */
    public function test_an_unreadable_staff_record_does_not_decide_the_subject(): void
    {
        $fb = new FakeSubstituteFirestore();
        $this->timetable($fb);
        $this->policy($fb, ['requireSubjectMatch' => true]);
        $fb->failCollections = ['staff'];

        try {
            $this->save($fb, [1]);
            $this->fail('the subject requirement could not be evaluated');
        } catch (Service_exception $e) {
            $this->assertMatchesRegularExpression('~try again~i', $e->getMessage(),
                'the operator is told to retry, not that the teacher is unqualified');
        }
    }
}
