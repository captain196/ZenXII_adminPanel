<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

if (!defined('BASEPATH')) define('BASEPATH', __DIR__ . '/../../system/');
if (!defined('APPPATH'))  define('APPPATH',  __DIR__ . '/../../application/');
if (!function_exists('log_message')) { function log_message($l, $m) {} }
require_once __DIR__ . '/../../application/libraries/Timetable_plan_store.php';
require_once __DIR__ . '/../Unit/TimetablePublishTest.php';

/**
 * THE WINDOW BETWEEN READING A VERSION AND READING THE TOKEN.
 *
 * The authorised TT-C8 concurrency sequence is:
 *
 *     guard read → all validation/preconditions → one atomic batch
 *
 * The token has to be captured BEFORE the questions it protects are asked, so
 * that anything changing an answer between the question and the write
 * invalidates the write. publishCandidate() does that — a mutant that swaps its
 * two reads is killed by the battery.
 *
 * SUPERSEDE decides `already_bounded` from a version read. If the token were
 * read AFTER that decision, another supersede completing in between would be
 * invisible twice over: the version read already returned open-ended, and the
 * token picked up afterwards already includes their write, so the precondition
 * passes and the commit re-dates a version that is already bounded — breaking
 * the one-way rule that `already_bounded` exists to enforce.
 *
 * `beforeCommit` cannot model this: by the time it fires, the token has been
 * read either way. Only a window at the VERSION read can tell the two orderings
 * apart, which is why this file exists separately from the concurrency suite.
 */
final class TimetableSupersedeOrderingTest extends TestCase
{
    private const SCHOOL = 'SCH_A';
    private const SESS   = '2026-2027';
    private const PLAN   = 'PLAN_A';

    private function vDoc(): string
    {
        return self::SCHOOL . '_' . self::SESS . '_' . self::PLAN . '_V1';
    }

    private function day(int $days): string
    {
        return date('Y-m-d', strtotime(($days >= 0 ? '+' : '') . $days . ' day'));
    }

    public function test_a_supersede_completing_during_the_version_read_is_caught(): void
    {
        $fb = new FakePublishFirestore();
        $fb->versions[$this->vDoc()] = [
            'schoolId' => self::SCHOOL, 'session' => self::SESS,
            'planId' => self::PLAN, 'versionId' => 'V1',
            'status' => Timetable_plan_service::STATUS_PUBLISHED,
            'effectiveFrom' => $this->day(-30), 'effectiveTo' => '',
        ];

        $landed = false;
        $fb->afterVersionRead = function (FakePublishFirestore $inner) use (&$landed) {
            // B completes an entire supersede while A holds a version snapshot
            // that still says open-ended.
            (new Timetable_plan_store())
                ->init($inner, self::SCHOOL, self::SESS, 'STA_B')
                ->supersedeVersion(self::PLAN, 'V1', $this->day(60), 'B closes it');
            $landed = true;
        };

        $outcome = 'SUCCEEDED';
        try {
            (new Timetable_plan_store())
                ->init($fb, self::SCHOOL, self::SESS, 'STA_A')
                ->supersedeVersion(self::PLAN, 'V1', $this->day(10), 'A closes it');
        } catch (Service_exception $e) {
            $outcome = 'refused:' . $e->errorType;
        }

        $this->assertTrue($landed, 'the window never opened; this proves nothing');

        // THE INVARIANT. Whichever way A is refused — on the token, or on a
        // re-read finding the version already bounded — B's end date must
        // stand. An end date is set once.
        $this->assertSame($this->day(60), $fb->versions[$this->vDoc()]['effectiveTo'],
            "A re-dated a version that B had already bounded (outcome: {$outcome}). "
            . 'The one-way rule is broken: an effectiveTo was changed after being '
            . 'set, which is the general edit-in-place capability this transition '
            . 'exists to avoid.');

        $this->assertNotSame('SUCCEEDED', $outcome,
            'A must be refused, not silently succeed over a completed supersede');

        $this->assertSame('B closes it', $fb->versions[$this->vDoc()]['supersededReason'],
            "and B's reason must survive — it is the only record of why the "
            . 'timetable ended on that date');
    }

    /**
     * The same window on PUBLISH, which decides `already_published` the same way.
     *
     * Two administrators publishing the SAME version: A reads it as a candidate,
     * B publishes it completely, A's token read afterwards already includes B's
     * write, and A commits — re-publishing an already-published version with
     * A's dates over B's. The overlap check cannot catch it, because
     * publishedOverlaps() correctly excludes the version being published from
     * its own comparison.
     */
    public function test_a_publish_completing_during_the_version_read_is_caught(): void
    {
        $fb = new FakePublishFirestore();

        // A complete, checked candidate — the same shape publishCandidate needs.
        $days = [];
        foreach (['Monday', 'Tuesday'] as $day) {
            $d = [
                'schoolId' => self::SCHOOL, 'session' => self::SESS,
                'planId' => self::PLAN, 'versionId' => 'V1',
                'classSection' => 'Class 9th/Section A', 'day' => $day,
                'periods' => [['periodNumber' => 1, 'subject' => 'Mathematics',
                               'teacherId' => 'STA0001', 'teacher' => 'T',
                               'startTime' => '09:00', 'endTime' => '09:40',
                               'type' => 'class']],
                'manuallyEdited' => false,
            ];
            $fb->days[self::SCHOOL . '_' . self::SESS . '_' . self::PLAN
                      . '_V1_Class 9th_Section A_' . $day] = $d;
            $days[] = $d;
        }
        $fb->versions[$this->vDoc()] = [
            'schoolId' => self::SCHOOL, 'session' => self::SESS,
            'planId' => self::PLAN, 'versionId' => 'V1',
            'status' => Timetable_plan_service::STATUS_CANDIDATE,
            'effectiveFrom' => '', 'effectiveTo' => '',
            'generation' => ['state' => Timetable_plan_service::GEN_COMPLETE],
            'validation' => ['hard' => [], 'soft' => [], 'hardCount' => 0,
                             'softCount' => 0, 'publishable' => true,
                             'fingerprint' => Timetable_validation_service::fingerprint($days)],
            'validationStale' => false,
        ];

        $landed = false;
        $fb->afterVersionRead = function (FakePublishFirestore $inner) use (&$landed) {
            (new Timetable_plan_store())
                ->init($inner, self::SCHOOL, self::SESS, 'STA_B')
                ->publishCandidate(self::PLAN, 'V1', $this->day(60));
            $landed = true;
        };

        $outcome = 'SUCCEEDED';
        try {
            (new Timetable_plan_store())
                ->init($fb, self::SCHOOL, self::SESS, 'STA_A')
                ->publishCandidate(self::PLAN, 'V1', $this->day(10));
        } catch (Service_exception $e) {
            $outcome = 'refused:' . $e->errorType;
        }

        $this->assertTrue($landed, 'the window never opened; this proves nothing');

        $this->assertSame($this->day(60), $fb->versions[$this->vDoc()]['effectiveFrom'],
            "A re-published a version B had already published (outcome: {$outcome}), "
            . 'overwriting the dates it goes live on. Publishing is one-way: a '
            . 'published version is immutable and a correction is a NEW version.');

        $this->assertNotSame('SUCCEEDED', $outcome,
            'A must be refused rather than silently re-publishing');
    }
}
