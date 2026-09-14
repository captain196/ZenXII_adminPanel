<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

if (!defined('BASEPATH')) define('BASEPATH', __DIR__ . '/../../system/');
if (!defined('APPPATH'))  define('APPPATH',  __DIR__ . '/../../application/');
if (!function_exists('log_message')) { function log_message($l, $m) {} }
require_once __DIR__ . '/../../application/libraries/Timetable_validation_service.php';

/**
 * PHASE A — candidate validation.
 *
 * The split these tests defend: a HARD violation is something that cannot be
 * true of a real school day and therefore blocks publishing; a SOFT violation is
 * a preference this layer detects, explains and refuses to rank, because ranking
 * needs weights that are the school's to choose.
 *
 * The failure mode being designed against is a validator that looks strict and
 * is not — one that reports a clean verdict for content it could not read, or
 * whose verdict outlives the arrangement that produced it.
 */
final class TimetableValidationTest extends TestCase
{
    /** @param array<int,array{0:string,1:string,2:string}> $cells [subject, teacherId, room] */
    private function day(string $section, string $day, array $cells): array
    {
        $periods = [];
        foreach ($cells as $i => [$sub, $tid]) {
            $periods[] = [
                'periodNumber' => $i + 1, 'subject' => $sub,
                'teacher' => $tid === '' ? '' : 'T ' . $tid, 'teacherId' => $tid,
                'startTime' => '09:00AM', 'endTime' => '09:45AM',
                'room' => '', 'type' => 'class',
            ];
        }
        return ['classSection' => $section, 'day' => $day, 'periods' => $periods];
    }

    private function codes(array $list): array
    {
        return array_values(array_unique(array_column($list, 'code')));
    }

    // ── hard constraints ─────────────────────────────────────────────────

    public function test_a_teacher_in_two_sections_at_once_is_hard(): void
    {
        $r = Timetable_validation_service::validate([
            $this->day('Class 8th/A', 'Monday', [['Maths', 'STA1']]),
            $this->day('Class 9th/A', 'Monday', [['Maths', 'STA1']]),
        ]);

        $this->assertContains(Timetable_validation_service::H_TEACHER_DOUBLE_BOOKED,
            $this->codes($r['hard']));
        $this->assertFalse($r['publishable'],
            'no school policy makes one teacher in two rooms acceptable');
        $this->assertMatchesRegularExpression('~STA1.*Monday.*period 1~',
            $r['hard'][0]['message'], 'the report must name who, when and where');
    }

    public function test_the_same_teacher_in_the_same_section_is_not_a_clash(): void
    {
        $r = Timetable_validation_service::validate([
            $this->day('Class 8th/A', 'Monday', [['Maths', 'STA1'], ['Maths', 'STA1']]),
        ]);
        $this->assertNotContains(Timetable_validation_service::H_TEACHER_DOUBLE_BOOKED,
            $this->codes($r['hard']),
            'a teacher taking the same class twice in a day is a preference, not a clash');
        $this->assertTrue($r['publishable']);
    }

    public function test_a_subject_with_no_teacher_is_hard(): void
    {
        $r = Timetable_validation_service::validate([
            $this->day('Class 8th/A', 'Monday', [['Maths', '']]),
        ]);
        $this->assertContains(Timetable_validation_service::H_SUBJECT_WITHOUT_TEACHER,
            $this->codes($r['hard']),
            'the Teacher app resolves schedules by teacherId, so a lesson with no '
            . 'teacher is invisible to everyone and would publish an unattended class');
        $this->assertFalse($r['publishable']);
    }

    public function test_a_day_stored_twice_for_one_section_is_hard(): void
    {
        $r = Timetable_validation_service::validate([
            $this->day('Class 8th/A', 'Monday', [['Maths', 'STA1']]),
            $this->day('Class 8th/A', 'Monday', [['Science', 'STA2']]),
        ]);
        $this->assertContains(Timetable_validation_service::H_DAY_DUPLICATED,
            $this->codes($r['hard']));
    }

    public function test_two_lessons_in_one_slot_is_hard(): void
    {
        $d = $this->day('Class 8th/A', 'Monday', [['Maths', 'STA1']]);
        $d['periods'][] = ['periodNumber' => 1, 'subject' => 'Science',
                           'teacherId' => 'STA2', 'teacher' => 'T2',
                           'startTime' => '', 'endTime' => '', 'room' => '', 'type' => 'class'];
        $r = Timetable_validation_service::validate([$d]);

        $this->assertContains(Timetable_validation_service::H_SLOT_DUPLICATED,
            $this->codes($r['hard']));
    }

    // ── soft constraints: detected, explained, never ranked ──────────────

    public function test_an_empty_period_is_soft_not_hard(): void
    {
        $r = Timetable_validation_service::validate([
            $this->day('Class 8th/A', 'Monday', [['Maths', 'STA1'], ['', '']]),
        ]);

        $this->assertContains(Timetable_validation_service::S_UNALLOCATED_PERIOD,
            $this->codes($r['soft']));
        $this->assertTrue($r['publishable'],
            'the generator legitimately produces gaps; making them hard would refuse '
            . 'to publish almost every real timetable');
    }

    public function test_a_subject_twice_in_a_day_is_soft(): void
    {
        $r = Timetable_validation_service::validate([
            $this->day('Class 8th/A', 'Monday', [['Maths', 'STA1'], ['Maths', 'STA1']]),
        ]);
        $this->assertContains(Timetable_validation_service::S_SUBJECT_TWICE_IN_A_DAY,
            $this->codes($r['soft']));
        $this->assertTrue($r['publishable']);
    }

    public function test_a_long_unbroken_teaching_stretch_is_soft(): void
    {
        $cells = [];
        for ($i = 0; $i < 5; $i++) $cells[] = ['Maths', 'STA1'];
        $r = Timetable_validation_service::validate([
            $this->day('Class 8th/A', 'Monday', $cells),
        ]);

        $s = null;
        foreach ($r['soft'] as $v) {
            if ($v['code'] === Timetable_validation_service::S_TEACHER_LONG_STRETCH) $s = $v;
        }
        $this->assertNotNull($s, 'a 5-period unbroken stretch must be reported');
        $this->assertSame(5, $s['where']['length']);
        $this->assertTrue($r['publishable'], 'a long day is a preference, not an impossibility');
    }

    /**
     * The boundary itself. A mutation changing `>=` to `>` survived a 5-period
     * fixture, because 5 trips both. Only the exact threshold distinguishes them.
     */
    public function test_the_long_stretch_boundary_is_exact(): void
    {
        $run = function (int $n): array {
            $cells = [];
            for ($i = 0; $i < $n; $i++) $cells[] = ['Maths', 'STA1'];
            return $this->codes(Timetable_validation_service::validate([
                $this->day('Class 8th/A', 'Monday', $cells),
            ])['soft']);
        };

        $this->assertNotContains(Timetable_validation_service::S_TEACHER_LONG_STRETCH,
            $run(Timetable_validation_service::LONG_STRETCH - 1),
            'one period below the threshold must not be reported');
        $this->assertContains(Timetable_validation_service::S_TEACHER_LONG_STRETCH,
            $run(Timetable_validation_service::LONG_STRETCH),
            'the threshold itself must be reported, or the constant means one more '
            . 'than it says');
    }

    public function test_a_gap_breaks_the_stretch(): void
    {
        $r = Timetable_validation_service::validate([
            $this->day('Class 8th/A', 'Monday', [
                ['Maths', 'STA1'], ['Maths', 'STA1'], ['', ''],
                ['Maths', 'STA1'], ['Maths', 'STA1'],
            ]),
        ]);
        $this->assertNotContains(Timetable_validation_service::S_TEACHER_LONG_STRETCH,
            $this->codes($r['soft']),
            'two runs of two are not one run of four');
    }

    public function test_a_section_missing_a_day_others_have_is_soft(): void
    {
        $r = Timetable_validation_service::validate([
            $this->day('Class 8th/A', 'Monday',  [['Maths', 'STA1']]),
            $this->day('Class 8th/A', 'Tuesday', [['Maths', 'STA1']]),
            $this->day('Class 9th/A', 'Monday',  [['Maths', 'STA2']]),
        ]);

        $missing = array_values(array_filter($r['soft'],
            fn($v) => $v['code'] === Timetable_validation_service::S_MISSING_DAY_FOR_SECTION));
        $this->assertCount(1, $missing);
        $this->assertSame('Class 9th/A', $missing[0]['where']['section']);
        $this->assertSame('Tuesday', $missing[0]['where']['day']);
        $this->assertTrue($r['publishable'],
            'this is inferred from content, not from a working-days setting, so it '
            . 'reports rather than refuses');
    }

    // ── the refusal to fabricate a score ────────────────────────────────

    public function test_no_combined_score_is_invented(): void
    {
        $r = Timetable_validation_service::validate([
            $this->day('Class 8th/A', 'Monday', [['Maths', 'STA1'], ['', '']]),
        ]);

        $this->assertNull($r['score'],
            'ranking soft violations needs weights the school has not chosen; a '
            . 'fabricated number would be trusted as if it meant something');
        $this->assertNotSame('', $r['scoreReason'],
            'a null score must say why it is null');
    }

    // ── unreadable is not clean ─────────────────────────────────────────

    public function test_unreadable_content_is_a_hard_violation_not_a_pass(): void
    {
        foreach ([null, 'nonsense', 42] as $bad) {
            $r = Timetable_validation_service::validate($bad);
            $this->assertFalse($r['publishable'],
                'content that could not be read must never validate clean');
            $this->assertSame('unreadable_input', $r['hard'][0]['code']);
        }
    }

    public function test_a_genuinely_empty_timetable_reports_no_violations(): void
    {
        $r = Timetable_validation_service::validate([]);
        $this->assertSame(0, $r['hardCount']);
        $this->assertSame(0, $r['softCount']);
    }

    public function test_malformed_rows_are_skipped_not_crashed_on(): void
    {
        $r = Timetable_validation_service::validate([
            'garbage',
            ['classSection' => '', 'day' => 'Monday', 'periods' => []],
            ['classSection' => 'Class 8th/A', 'day' => 'Monday', 'periods' => 'not-an-array'],
            $this->day('Class 8th/A', 'Tuesday', [['Maths', 'STA1']]),
        ]);
        $this->assertIsArray($r['hard']);
        $this->assertIsArray($r['soft']);
    }

    // ── explainability ──────────────────────────────────────────────────

    public function test_every_violation_is_explained_in_a_sentence(): void
    {
        $r = Timetable_validation_service::validate([
            $this->day('Class 8th/A', 'Monday', [['Maths', 'STA1'], ['', '']]),
            $this->day('Class 9th/A', 'Monday', [['Maths', 'STA1']]),
        ]);

        $this->assertCount($r['hardCount'] + $r['softCount'], $r['explain'],
            'a violation with no explanation is a number an administrator cannot act on');
        foreach ($r['explain'] as $line) {
            $this->assertMatchesRegularExpression('~^(MUST FIX|Consider) — .+~', $line);
        }
    }

    public function test_hard_and_soft_are_reported_separately(): void
    {
        $r = Timetable_validation_service::validate([
            $this->day('Class 8th/A', 'Monday', [['Maths', 'STA1'], ['', '']]),
            $this->day('Class 9th/A', 'Monday', [['Maths', 'STA1']]),
        ]);
        foreach ($r['hard'] as $v) $this->assertSame('hard', $v['severity']);
        foreach ($r['soft'] as $v) $this->assertSame('soft', $v['severity']);
        $this->assertSame(count($r['hard']), $r['hardCount']);
        $this->assertSame(count($r['soft']), $r['softCount']);
    }

    // ── fingerprints: a verdict must be provably about THIS content ─────

    public function test_the_fingerprint_changes_when_content_changes(): void
    {
        $a = [$this->day('Class 8th/A', 'Monday', [['Maths', 'STA1']])];
        $b = [$this->day('Class 8th/A', 'Monday', [['Science', 'STA1']])];

        $this->assertNotSame(
            Timetable_validation_service::fingerprint($a),
            Timetable_validation_service::fingerprint($b));
    }

    public function test_the_fingerprint_changes_when_the_teacher_changes(): void
    {
        $a = [$this->day('Class 8th/A', 'Monday', [['Maths', 'STA1']])];
        $b = [$this->day('Class 8th/A', 'Monday', [['Maths', 'STA2']])];

        $this->assertNotSame(
            Timetable_validation_service::fingerprint($a),
            Timetable_validation_service::fingerprint($b));
    }

    public function test_the_fingerprint_ignores_row_order(): void
    {
        $a = [$this->day('Class 8th/A', 'Monday', [['Maths', 'STA1']]),
              $this->day('Class 8th/A', 'Tuesday', [['Science', 'STA2']])];
        $b = array_reverse($a);

        $this->assertSame(
            Timetable_validation_service::fingerprint($a),
            Timetable_validation_service::fingerprint($b),
            'a re-read that returns the same content in a different order must not '
            . 'look like an edit, or every verdict would be permanently untrusted');
    }

    public function test_a_verdict_only_describes_the_content_it_was_made_from(): void
    {
        $days = [$this->day('Class 8th/A', 'Monday', [['Maths', 'STA1']])];
        $v    = Timetable_validation_service::validate($days);

        $this->assertTrue(Timetable_validation_service::verdictDescribes($v, $days));

        $edited = [$this->day('Class 8th/A', 'Monday', [['Science', 'STA1']])];
        $this->assertFalse(Timetable_validation_service::verdictDescribes($v, $edited),
            'a verdict that survives an edit is exactly the stale-verdict trap');
    }

    public function test_a_verdict_without_a_fingerprint_is_never_trusted(): void
    {
        $days = [$this->day('Class 8th/A', 'Monday', [['Maths', 'STA1']])];

        foreach ([null, [], ['fingerprint' => ''], 'nonsense'] as $bad) {
            $this->assertFalse(Timetable_validation_service::verdictDescribes($bad, $days),
                'an unprovable verdict must fail closed');
        }
    }
}
