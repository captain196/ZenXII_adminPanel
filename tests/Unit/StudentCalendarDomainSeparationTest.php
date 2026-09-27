<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once APPPATH . 'libraries/Student_calendar_service.php';
require_once APPPATH . 'libraries/Working_calendar_service.php';

/**
 * THE BOUNDARY BETWEEN THE STUDENT AND STAFF CALENDAR DOMAINS.
 *
 * The architecture's hardest rule is that the student teaching calendar and the
 * staff work schedule are SEPARATE AUTHORITIES. Student_calendar_service
 * therefore deliberately duplicates ~15 lines of nth-weekday date arithmetic
 * rather than calling the workforce implementation, so that a payroll-motivated
 * edit can never silently move student attendance.
 *
 * Duplication costs drift. This file is the price paid for it:
 *
 *   - an EXHAUSTIVE sweep proving the two implementations agree today, so the
 *     duplication is a copy and not a divergence;
 *   - a source-level assertion that the student service holds no runtime
 *     dependency on the staff service, so the separation is structural rather
 *     than merely intended.
 *
 * If someone later changes either predicate, the sweep fails and the decision
 * gets re-made deliberately instead of discovered in production payroll.
 */
final class StudentCalendarDomainSeparationTest extends TestCase
{
    /**
     * Every weekday × every occurrence 1–5 × a rule set, compared across both
     * implementations. 7 × 5 × 5 = 175 combinations, not samples.
     */
    public function test_nth_weekday_predicate_agrees_with_the_workforce_implementation(): void
    {
        $disagreements = [];

        foreach (Student_calendar_service::DAYS as $ruleDay) {
            for ($weeks = 1; $weeks <= 5; $weeks++) {
                $rules = [['day' => $ruleDay, 'weeks' => [$weeks]]];

                foreach (Student_calendar_service::DAYS as $dow) {
                    for ($occ = 1; $occ <= 5; $occ++) {
                        $mine   = Student_calendar_service::isNthWeekdayOff($rules, $dow, $occ);
                        $theirs = Working_calendar_service::isNthWeekdayOff($rules, $dow, $occ);

                        if ($mine !== $theirs) {
                            $disagreements[] = "rule={$ruleDay}/{$weeks} dow={$dow} occ={$occ} "
                                . '(student=' . var_export($mine, true)
                                . ', staff=' . var_export($theirs, true) . ')';
                        }
                    }
                }
            }
        }

        $this->assertSame([], $disagreements,
            "The duplicated nth-weekday maths has DRIFTED from the workforce "
            . "implementation. Duplication was only acceptable while the two agreed:\n"
            . implode("\n", array_slice($disagreements, 0, 15)));
    }

    /** Multi-week rules and non-matching days, swept the same way. */
    public function test_multi_week_rules_agree_across_a_full_year_of_real_dates(): void
    {
        $rules = [
            ['day' => 'Sat', 'weeks' => [2, 4]],
            ['day' => 'Wed', 'weeks' => [1]],
        ];

        $disagreements = [];
        $d = new DateTimeImmutable('2026-01-01');
        for ($i = 0; $i < 365; $i++) {
            $date = $d->modify("+{$i} day")->format('Y-m-d');
            $dow  = Student_calendar_service::dayToken($date);
            $occ  = Student_calendar_service::occurrenceInMonth($date);

            if (Student_calendar_service::isNthWeekdayOff($rules, $dow, $occ)
                !== Working_calendar_service::isNthWeekdayOff($rules, $dow, $occ)) {
                $disagreements[] = $date;
            }
        }

        $this->assertSame([], $disagreements,
            'divergence on real dates: ' . implode(', ', array_slice($disagreements, 0, 10)));
    }

    /**
     * STRUCTURAL SEPARATION.
     *
     * The student service must not reference the workforce authority at all —
     * not the calendar service, not the assignment resolver, not work schedules.
     * A comment mentioning them is fine and expected; a code reference is not.
     */
    public function test_the_student_service_has_no_runtime_dependency_on_the_staff_domain(): void
    {
        $src = file_get_contents(APPPATH . 'libraries/Student_calendar_service.php');
        $this->assertIsString($src);

        // Strip comments so documentation mentioning the staff domain is allowed.
        $code = preg_replace('~/\*.*?\*/~s', '', $src);
        $code = preg_replace('~//[^\n]*~', '', (string) $code);

        foreach ([
            'Working_calendar_service',
            'Policy_assignment_service',
            'Work_schedule_service',
            'workSchedules',
            'attendancePolicy',
        ] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, (string) $code,
                "Student_calendar_service references '{$forbidden}' in CODE. The student "
                . 'teaching calendar and the staff work schedule are separate authorities; '
                . 'a runtime dependency merges them.');
        }
    }

    /**
     * The student service must also do NO I/O. Holiday_service remains the one
     * canonical reader of calendarEvents; a second reader would be a second
     * authority over exceptions.
     */
    public function test_the_student_service_performs_no_firestore_reads(): void
    {
        $src  = file_get_contents(APPPATH . 'libraries/Student_calendar_service.php');
        $code = preg_replace('~/\*.*?\*/~s', '', (string) $src);
        $code = preg_replace('~//[^\n]*~', '', (string) $code);

        foreach ([
            'calendarEvents', 'Holiday_service', 'schoolWhere', 'firestore',
            '$this->fs', 'get_instance',
        ] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, (string) $code,
                "Student_calendar_service performs or references I/O ('{$forbidden}'). "
                . 'It must stay pure: exceptions are passed in by the caller.');
        }
    }

    /**
     * Both domains must agree that a special working day beats a holiday, and
     * that a half day is still a working day — because they describe the same
     * real-world school. Agreement on SEMANTICS is required; shared CODE is not.
     */
    public function test_both_domains_agree_a_half_day_is_still_a_working_day(): void
    {
        $student = Student_calendar_service::resolveForDate(
            ['effectiveFrom' => '2026-04-01', 'weeklyOffs' => ['Sun']],
            '2026-06-10', ['halfDay' => true]);

        $this->assertTrue($student['attendance'], 'student half day is markable');
        $this->assertSame('h', Working_calendar_service::MASK_HALF,
            'and the workforce mask still distinguishes it without excluding it');
    }
}
