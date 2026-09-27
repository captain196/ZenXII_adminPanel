<?php
/**
 * THE COVER LOG.
 *
 * The research is unanimous on this even where it disagrees on limits. The
 * STPCD sets no ceiling on cover but expects schools to share the burden
 * equitably across the year, and says a cover log is what makes that possible.
 * Untis takes the same view from the other end: it keeps a count of the periods
 * each teacher has covered and feeds that count into its suggestions rather
 * than enforcing a ceiling.
 *
 * So the log is not a report somebody runs at the end of term. It is the record
 * that answers "who have we been leaning on?" — before the next absence, not
 * after the complaint.
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../application/libraries/Substitute_service.php';
require_once __DIR__ . '/SubstituteConcurrentAssignmentTest.php';   // FakeSubstituteFirestore

final class SubstituteCoverLogTest extends TestCase
{
    private const SCHOOL = 'SCH_SUB01';
    private const SESS   = '2026-27';

    private function svc(FakeSubstituteFirestore $fb): Substitute_service
    {
        return (new Substitute_service())->init(
            $fb, null, self::SCHOOL, self::SESS, 'STA_ADMIN', 'R Rao');
    }

    /** One stored cover: a teacher, a date, and the periods they took. */
    private function cover(FakeSubstituteFirestore $fb, string $id, string $date,
                           string $subId, string $subName, array $periods,
                           string $status = 'assigned'): void
    {
        $fb->docs['substitutes'][self::SCHOOL . '_' . $id] = [
            'id' => $id, 'schoolId' => self::SCHOOL, 'session' => self::SESS,
            'date' => $date, 'status' => $status,
            'absentTeacherId' => 'STA_ABSENT', 'absentTeacherName' => 'A Absent',
            'assignments' => array_map(fn($p) => [
                'periodNumber' => $p, 'subject' => 'Maths',
                'className' => 'Class 9th', 'section' => 'Section A',
                'substituteTeacherId' => $subId, 'substituteTeacherName' => $subName,
            ], $periods),
        ];
    }

    private function rowFor(array $log, string $teacherId): ?array
    {
        foreach ($log['teachers'] as $t) {
            if ($t['teacherId'] === $teacherId) { return $t; }
        }
        return null;
    }

    // -- counting ---------------------------------------------------------

    public function test_it_counts_periods_and_days_per_teacher(): void
    {
        $fb = new FakeSubstituteFirestore();
        $this->cover($fb, 'S1', '2026-09-07', 'STA_A', 'Anita Rao',  [1, 2]);
        $this->cover($fb, 'S2', '2026-09-08', 'STA_A', 'Anita Rao',  [3]);
        $this->cover($fb, 'S3', '2026-09-08', 'STA_B', 'Bhavna Iyer', [4]);

        $log = $this->svc($fb)->coverLog('2026-09-01', '2026-09-30');

        $a = $this->rowFor($log, 'STA_A');
        $this->assertSame(3, $a['periods'], 'three periods across two days');
        $this->assertSame(2, $a['days']);
        $this->assertSame('Anita Rao', $a['name']);

        $this->assertSame(1, $this->rowFor($log, 'STA_B')['periods']);
        $this->assertSame(4, $log['totalPeriods']);
    }

    /**
     * Heaviest first. The log exists to answer "who have we been leaning on?",
     * and an alphabetical list makes the reader work that out for themselves.
     */
    public function test_the_heaviest_load_is_listed_first(): void
    {
        $fb = new FakeSubstituteFirestore();
        // Names chosen so the alphabet ARGUES WITH the load: sorted by name,
        // Amit comes first; sorted by what they have carried, Zara does.
        $this->cover($fb, 'S1', '2026-09-07', 'STA_LIGHT', 'Amit Light', [1]);
        $this->cover($fb, 'S2', '2026-09-07', 'STA_HEAVY', 'Zara Heavy', [2, 3, 4]);

        $log = $this->svc($fb)->coverLog('2026-09-01', '2026-09-30');

        $this->assertSame('STA_HEAVY', $log['teachers'][0]['teacherId']);
        $this->assertSame('STA_LIGHT', $log['teachers'][1]['teacherId']);
    }

    public function test_cancelled_cover_is_not_counted_against_anybody(): void
    {
        $fb = new FakeSubstituteFirestore();
        $this->cover($fb, 'S1', '2026-09-07', 'STA_A', 'Anita Rao', [1, 2], 'cancelled');
        $this->cover($fb, 'S2', '2026-09-08', 'STA_A', 'Anita Rao', [3]);

        $log = $this->svc($fb)->coverLog('2026-09-01', '2026-09-30');

        $this->assertSame(1, $this->rowFor($log, 'STA_A')['periods'],
            'cover that was cancelled was never taken');
    }

    public function test_it_counts_only_the_range_asked_for(): void
    {
        $fb = new FakeSubstituteFirestore();
        $this->cover($fb, 'S1', '2026-09-07', 'STA_A', 'Anita Rao', [1]);
        $this->cover($fb, 'S2', '2026-10-07', 'STA_A', 'Anita Rao', [1]);

        $log = $this->svc($fb)->coverLog('2026-09-01', '2026-09-30');

        $this->assertSame(1, $this->rowFor($log, 'STA_A')['periods']);
    }

    public function test_another_session_is_not_in_this_log(): void
    {
        $fb = new FakeSubstituteFirestore();
        $this->cover($fb, 'S1', '2026-09-07', 'STA_A', 'Anita Rao', [1]);
        $fb->docs['substitutes'][self::SCHOOL . '_S_OLD'] = [
            'id' => 'S_OLD', 'schoolId' => self::SCHOOL, 'session' => '2025-26',
            'date' => '2026-09-07', 'status' => 'assigned',
            'assignments' => [['periodNumber' => 2, 'substituteTeacherId' => 'STA_A',
                               'substituteTeacherName' => 'Anita Rao']],
        ];

        $log = $this->svc($fb)->coverLog('2026-09-01', '2026-09-30');

        $this->assertSame(1, $this->rowFor($log, 'STA_A')['periods']);
    }

    // -- honesty ----------------------------------------------------------

    /** A log that could not be read is not a school where nobody covered. */
    public function test_a_log_that_cannot_be_read_is_not_an_empty_log(): void
    {
        $fb = new FakeSubstituteFirestore();
        $this->cover($fb, 'S1', '2026-09-07', 'STA_A', 'Anita Rao', [1]);
        $fb->failReads = true;

        try {
            $this->svc($fb)->coverLog('2026-09-01', '2026-09-30');
            $this->fail('a failed read must not be presented as no cover taken');
        } catch (Service_exception $e) {
            $this->assertMatchesRegularExpression('~try again~i', $e->getMessage());
        }
    }

    /** No name on the record is not a licence to print an identifier. */
    public function test_a_teacher_with_no_recorded_name_is_not_shown_as_an_id(): void
    {
        $fb = new FakeSubstituteFirestore();
        $this->cover($fb, 'S1', '2026-09-07', 'STA_GHOST', '', [1]);

        $log = $this->svc($fb)->coverLog('2026-09-01', '2026-09-30');
        $row = $this->rowFor($log, 'STA_GHOST');

        $this->assertNotSame('STA_GHOST', $row['name'],
            'a staff identifier is not a name and must never be shown as one');
        $this->assertNotSame('', $row['name'], 'but the row still says something');
    }

    public function test_a_range_the_wrong_way_round_is_refused(): void
    {
        $fb = new FakeSubstituteFirestore();

        try {
            $this->svc($fb)->coverLog('2026-09-30', '2026-09-01');
            $this->fail('an end before its start is not a range');
        } catch (Service_exception $e) {
            $this->assertMatchesRegularExpression('~before|after|range~i', $e->getMessage());
        }
    }

    public function test_an_empty_range_is_an_ordinary_empty_log(): void
    {
        $fb = new FakeSubstituteFirestore();

        $log = $this->svc($fb)->coverLog('2026-09-01', '2026-09-30');

        $this->assertSame([], $log['teachers']);
        $this->assertSame(0, $log['totalPeriods']);
        $this->assertFalse($log['truncated']);
    }

    // -- the load behind a suggestion -------------------------------------

    /**
     * Untis shows the running count next to each candidate so the person
     * choosing can spread the load. That is the whole mechanism: not a ceiling,
     * but a number in front of the person deciding.
     */
    public function test_it_reports_todays_and_this_weeks_load_for_a_teacher(): void
    {
        $fb = new FakeSubstituteFirestore();
        $this->cover($fb, 'S1', '2026-09-07', 'STA_A', 'Anita Rao', [1, 2]);   // Mon
        $this->cover($fb, 'S2', '2026-09-09', 'STA_A', 'Anita Rao', [3]);      // Wed
        $this->cover($fb, 'S3', '2026-09-14', 'STA_A', 'Anita Rao', [4]);      // next Mon

        $load = $this->svc($fb)->coverLoadOn('2026-09-07');

        $this->assertSame(2, $load['STA_A']['day'],  'two periods on the day itself');
        $this->assertSame(3, $load['STA_A']['week'], 'three across that week only');
    }

    public function test_a_teacher_with_no_cover_is_simply_absent_from_the_load(): void
    {
        $fb = new FakeSubstituteFirestore();

        $load = $this->svc($fb)->coverLoadOn('2026-09-07');

        $this->assertSame([], $load);
    }

    public function test_the_load_read_fails_loud_rather_than_reporting_zero(): void
    {
        $fb = new FakeSubstituteFirestore();
        $this->cover($fb, 'S1', '2026-09-07', 'STA_A', 'Anita Rao', [1]);
        $fb->failReads = true;

        try {
            $this->svc($fb)->coverLoadOn('2026-09-07');
            $this->fail('an unreadable load must not be offered as zero');
        } catch (Service_exception $e) {
            $this->assertMatchesRegularExpression('~try again~i', $e->getMessage());
        }
    }
}
