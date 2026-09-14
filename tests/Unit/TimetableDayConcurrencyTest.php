<?php
/**
 * TWO ADMINISTRATORS, ONE DAY.
 *
 * A day document is written whole. Two people editing DIFFERENT periods of the
 * SAME day therefore race: each reads the day, replaces its own cell, and writes
 * the lot back — so whichever saves second silently reverts the other's change.
 * Nobody is told, and the lost period simply is not taught.
 *
 * Read-modify-write inside one request does not help, because the two requests
 * interleave. The protection is a precondition on the document's server-assigned
 * updateTime, and these tests exercise it against a stand-in that behaves the way
 * Firestore does: a commit whose precondition no longer holds is refused.
 */

use PHPUnit\Framework\TestCase;

/** A Firestore stand-in that honours updateTime preconditions. */
class FakeCasFirestore
{
    public array $versions = [];
    public array $days     = [];      // docId => data (with __updateTime)
    public array $commits  = [];      // every write op it was asked to commit
    public array $settings = [];

    /** Bumped on every successful day write, standing in for a server clock. */
    private int $clock = 100;

    public function firestoreGetOrFail($collection, $docId)
    {
        if ($collection === 'timetableVersions')    { return $this->versions[$docId] ?? null; }
        if ($collection === 'timetableVersionDays') { return $this->days[$docId] ?? null; }
        if ($collection === 'timetableSettings')    { return $this->settings[$docId] ?? null; }
        return null;
    }

    public function firestoreQueryOrFail($collection, $conditions = [], $orderBy = null,
                                         $dir = 'ASC', $limit = null)
    {
        $src = $collection === 'timetableVersionDays' ? $this->days
             : ($collection === 'timetableVersions' ? $this->versions : []);
        $rows = [];
        foreach ($src as $doc) {
            $ok = true;
            foreach ($conditions as $c) {
                [$f, $op, $v] = $c;
                if ($op !== '==') { continue; }
                if (($doc[$f] ?? null) !== $v) { $ok = false; break; }
            }
            if ($ok) { $rows[] = $doc; }
        }
        return $limit !== null ? array_slice($rows, 0, $limit) : $rows;
    }

    public function firestoreSet($collection, $docId, $data, $merge = false)
    {
        if ($collection === 'timetableVersions') {
            $this->versions[$docId] = $merge
                ? array_merge($this->versions[$docId] ?? [], $data) : $data;
        }
        if ($collection === 'timetableVersionDays') {
            $data['__updateTime'] = (string) (++$this->clock);
            $this->days[$docId] = $data;
        }
        return true;
    }

    public function firestoreDelete($collection, $docId) { return true; }

    /** The real behaviour: refuse when the stored document has moved. */
    public function firestoreCommitBatchStatus(array $ops)
    {
        foreach ($ops as $op) {
            $this->commits[] = $op;
            $docId = $op['docId'];
            $pre   = $op['precondition'] ?? null;
            $have  = $this->days[$docId] ?? null;

            if (is_array($pre) && array_key_exists('updateTime', $pre)) {
                if (!is_array($have)
                    || (string) ($have['__updateTime'] ?? '') !== (string) $pre['updateTime']) {
                    return ['status' => 'PRECONDITION_CONFLICT'];
                }
            } elseif (is_array($pre) && array_key_exists('exists', $pre) && $pre['exists'] === false) {
                if (is_array($have)) { return ['status' => 'PRECONDITION_CONFLICT']; }
            }

            $data = $op['data'];
            $data['__updateTime'] = (string) (++$this->clock);
            $this->days[$docId] = $data;
        }
        // 'SUCCESS' — the literal the real client returns (COMMIT_SUCCESS). 'OK'
        // matched only what the production check happened to compare against, so
        // the double and the code agreed with each other and not with Firestore.
        return ['status' => 'SUCCESS'];
    }
}

final class TimetableDayConcurrencyTest extends TestCase
{
    private const SCHOOL = 'SCH_CAS01';
    private const SESS   = '2026-27';
    private const PLAN   = 'PLAN_A';
    private const VER    = 'CAND_1';
    private const SEC    = 'Class 9th/Section A';
    private const DAY    = 'Monday';

    private function docId(): string
    {
        return self::SCHOOL . '_' . self::SESS . '_' . self::PLAN . '_' . self::VER
             . '_' . str_replace('/', '_', self::SEC) . '_' . self::DAY;
    }

    private function fb(): FakeCasFirestore
    {
        $fb = new FakeCasFirestore();
        $fb->settings[self::SCHOOL . '_' . self::SESS] = ['periodsPerDay' => 6];
        $fb->versions[self::SCHOOL . '_' . self::SESS . '_' . self::PLAN . '_' . self::VER] = [
            'schoolId' => self::SCHOOL, 'session' => self::SESS, 'planId' => self::PLAN,
            'versionId' => self::VER, 'status' => 'candidate',
            'generation' => ['state' => 'complete'],
        ];

        $periods = [];
        for ($i = 0; $i < 6; $i++) {
            $periods[] = ['periodNumber' => $i + 1, 'subject' => 'Original ' . $i,
                          'teacher' => 'T', 'teacherId' => 'STA0' . $i,
                          'startTime' => '09:00AM', 'endTime' => '09:45AM'];
        }
        $fb->days[$this->docId()] = [
            'schoolId' => self::SCHOOL, 'session' => self::SESS, 'planId' => self::PLAN,
            'versionId' => self::VER, 'classSection' => self::SEC, 'day' => self::DAY,
            'periods' => $periods, '__updateTime' => '100',
        ];
        return $fb;
    }

    private function store(FakeCasFirestore $fb): Timetable_plan_store
    {
        return (new Timetable_plan_store())->init($fb, self::SCHOOL, self::SESS, 'STA0001');
    }

    private function cell(string $subject): array
    {
        return ['subject' => $subject, 'teacher' => 'X', 'teacherId' => 'STA99',
                'startTime' => '09:00AM', 'endTime' => '09:45AM', 'room' => '', 'type' => 'class'];
    }

    private function subjectAt(FakeCasFirestore $fb, int $i): string
    {
        return (string) ($fb->days[$this->docId()]['periods'][$i]['subject'] ?? '');
    }

    // ── the protection is actually wired ─────────────────────────────────

    /**
     * The editor's write must be CONDITIONAL. A fix that silently does not run
     * is worse than no fix, so this checks the commit carried a precondition
     * rather than trusting that it did.
     */
    public function test_the_editor_writes_conditionally(): void
    {
        $fb = $this->fb();
        $this->store($fb)->saveCandidateDayCells(
            self::PLAN, self::VER, self::SEC, self::DAY, [0 => $this->cell('Maths')]);

        $this->assertCount(1, $fb->commits,
            'the editor must go through the conditional commit, not a plain write');
        $this->assertSame(['updateTime' => '100'], $fb->commits[0]['precondition'],
            'and the condition must be the token the day carried when it was read');
    }

    /** One editor alone is unaffected: the day is saved normally. */
    public function test_a_single_editor_saves_normally(): void
    {
        $fb = $this->fb();
        $this->store($fb)->saveCandidateDayCells(
            self::PLAN, self::VER, self::SEC, self::DAY, [0 => $this->cell('Maths')]);

        $this->assertSame('Maths', $this->subjectAt($fb, 0));
        $this->assertSame('Original 3', $this->subjectAt($fb, 3),
            'and the periods nobody touched are untouched');
    }

    // ── the race ─────────────────────────────────────────────────────────

    /**
     * THE DEFECT THIS CLOSES.
     *
     * Two tabs open the same day. Tab A edits period 1, tab B edits period 4.
     * B saves first. A then saves against a snapshot that no longer describes
     * the stored day — and must be REFUSED rather than allowed to write back a
     * version of the day in which B's change never happened.
     */
    public function test_a_second_save_against_a_stale_snapshot_is_refused(): void
    {
        $fb = $this->fb();
        $st = $this->store($fb);

        // Both tabs are looking at __updateTime 100.
        // Tab B saves period 4 first.
        $st->saveCandidateDayCells(
            self::PLAN, self::VER, self::SEC, self::DAY, [3 => $this->cell('Hindi')]);
        $this->assertSame('Hindi', $this->subjectAt($fb, 3));

        // Tab A now saves period 1 — but it read the day BEFORE B wrote. Force
        // that stale view by handing the store the token tab A captured.
        $stale = '100';
        try {
            $st->saveCandidateDay(
                self::PLAN, self::VER, self::SEC, self::DAY,
                $this->staleDayWith($fb, 0, 'Maths'), true, null, $stale);
            $this->fail('a save against a stale snapshot must be refused');
        } catch (Service_exception $e) {
            $this->assertMatchesRegularExpression('~Someone else changed this day~i',
                $e->getMessage(), 'the operator must be told WHY, in words they can act on');
            $this->assertMatchesRegularExpression('~Refresh~i', $e->getMessage(),
                'and told what to do about it');
        }

        // The decisive assertion: nothing was lost.
        $this->assertSame('Hindi', $this->subjectAt($fb, 3),
            "the first editor's change must survive the second's refusal");
        $this->assertSame('Original 0', $this->subjectAt($fb, 0),
            'and the refused change must not be half-applied');
    }

    /** The day as tab A would have written it: its own cell over a stale read. */
    private function staleDayWith(FakeCasFirestore $fb, int $idx, string $subject): array
    {
        $periods = [];
        for ($i = 0; $i < 6; $i++) {
            $periods[] = ['periodNumber' => $i + 1, 'subject' => 'Original ' . $i,
                          'teacher' => 'T', 'teacherId' => 'STA0' . $i,
                          'startTime' => '09:00AM', 'endTime' => '09:45AM'];
        }
        $periods[$idx] = ['periodNumber' => $idx + 1] + $this->cell($subject);
        return $periods;
    }

    /**
     * Edits to DIFFERENT days never collide: they are different documents, so
     * both survive with no refusal at all. This is what makes the protection
     * proportionate rather than a blanket lock on the week.
     */
    public function test_edits_to_different_days_both_survive(): void
    {
        $fb = $this->fb();
        // A second day for the same section.
        $other = str_replace('_' . self::DAY, '_Tuesday', $this->docId());
        $fb->days[$other] = array_merge($fb->days[$this->docId()],
            ['day' => 'Tuesday', '__updateTime' => '100']);

        $st = $this->store($fb);
        $st->saveCandidateDayCells(self::PLAN, self::VER, self::SEC, self::DAY,
            [0 => $this->cell('Maths')]);
        $st->saveCandidateDayCells(self::PLAN, self::VER, self::SEC, 'Tuesday',
            [2 => $this->cell('Hindi')]);

        $this->assertSame('Maths', (string) $fb->days[$this->docId()]['periods'][0]['subject']);
        $this->assertSame('Hindi', (string) $fb->days[$other]['periods'][2]['subject']);
    }

    /**
     * Two people creating the same day at once is the same race. A day that did
     * not exist when it was read must not exist when it is written.
     */
    public function test_two_people_creating_the_same_day_at_once(): void
    {
        $fb = $this->fb();
        $st = $this->store($fb);

        // Nobody has this day yet.
        $missing = str_replace('_' . self::DAY, '_Saturday', $this->docId());

        // The first save creates it.
        $st->saveCandidateDay(self::PLAN, self::VER, self::SEC, 'Saturday',
            [['periodNumber' => 1] + $this->cell('First')], true, null, '');
        $this->assertArrayHasKey($missing, $fb->days);

        // The second, still believing it does not exist, must be refused.
        $this->expectException(Service_exception::class);
        $this->expectExceptionMessageMatches('~Someone else changed this day~i');
        $st->saveCandidateDay(self::PLAN, self::VER, self::SEC, 'Saturday',
            [['periodNumber' => 1] + $this->cell('Second')], true, null, '');
    }

    /**
     * Generation must NOT be conditional. It writes into a version created
     * moments earlier that nothing else can be editing, and a precondition
     * there would refuse legitimate writes for a race that cannot happen.
     */
    public function test_generation_writes_are_not_conditional(): void
    {
        $fb = $this->fb();
        $this->store($fb)->saveCandidateDay(
            self::PLAN, self::VER, self::SEC, self::DAY,
            [['periodNumber' => 1] + $this->cell('Generated')], false, 'GEN_1');

        $this->assertSame([], $fb->commits,
            'generation must use the plain write, or a batch would refuse itself');
        $this->assertSame('Generated', $this->subjectAt($fb, 0));
    }

    /**
     * If the day cannot be read, the save is refused. Writing unconditionally
     * because the token could not be fetched would restore the very overwrite
     * this exists to prevent.
     */
    public function test_an_unreadable_day_refuses_rather_than_writing_blind(): void
    {
        $fb = new class extends FakeCasFirestore {
            public function firestoreGetOrFail($collection, $docId)
            {
                if ($collection === 'timetableVersionDays') {
                    throw new \RuntimeException('read failed');
                }
                return parent::firestoreGetOrFail($collection, $docId);
            }
        };
        $fb->settings[self::SCHOOL . '_' . self::SESS] = ['periodsPerDay' => 6];
        $fb->versions[self::SCHOOL . '_' . self::SESS . '_' . self::PLAN . '_' . self::VER] = [
            'schoolId' => self::SCHOOL, 'session' => self::SESS, 'planId' => self::PLAN,
            'versionId' => self::VER, 'status' => 'candidate',
            'generation' => ['state' => 'complete'],
        ];

        try {
            $this->store($fb)->saveCandidateDayCells(
                self::PLAN, self::VER, self::SEC, self::DAY, [0 => $this->cell('Maths')]);
            $this->fail('an unreadable day must not be written blind');
        } catch (Service_exception $e) {
            $this->assertMatchesRegularExpression('~could not be read~i', $e->getMessage());
            $this->assertMatchesRegularExpression('~[Nn]othing was changed~', $e->getMessage());
        }

        $this->assertSame([], $fb->commits, 'and nothing may be committed');
    }
}
