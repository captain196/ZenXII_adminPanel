<?php
/**
 * WHAT THIS SCHOOL CALLS ITS BREAK.
 *
 * ZenX serves many schools and they do not agree on the word: Lunch, Recess,
 * Tiffin, Interval, Short break. The break itself is already versioned — a day
 * document carries each period's start and end, so the GAP is part of the
 * timetable and cannot drift. Only the word is configured.
 *
 * The properties that matter are backward compatibility (every school that
 * exists today has no name and must keep working) and that a missing or
 * unreadable name is never allowed to break a week.
 */

use PHPUnit\Framework\TestCase;

class FakeBreakNameFirestore
{
    public array $docs = [];
    public array $writes = [];
    public bool  $readFails = false;

    public function firestoreGetOrFail($collection, $docId)
    {
        if ($this->readFails) { throw new \RuntimeException('settings unreadable'); }
        return $this->docs[$docId] ?? null;
    }

    public function firestoreQueryOrFail($c, $cond = [], $o = null, $d = 'ASC', $l = null)
    {
        return [];
    }

    public function firestoreSet($collection, $docId, $data, $merge = false)
    {
        $this->writes[] = ['collection' => $collection, 'docId' => $docId, 'data' => $data];
        $this->docs[$docId] = $merge ? array_merge($this->docs[$docId] ?? [], $data) : $data;
        return true;
    }

    public function firestoreDelete($c, $d) { return true; }
}

final class TimetableBreakNameTest extends TestCase
{
    private const SCHOOL = 'SCH_BRK01';
    private const SESS   = '2026-27';

    private function svc(FakeBreakNameFirestore $fb): Timetable_service
    {
        $svc = new Timetable_service();
        foreach (['schoolId' => self::SCHOOL, 'session' => self::SESS,
                  'adminId' => 'STA0001', 'adminName' => 'R Rao',
                  'firebase' => $fb] as $p => $v) {
            $r = new ReflectionProperty(Timetable_service::class, $p);
            $r->setAccessible(true);
            $r->setValue($svc, $v);
        }
        return $svc;
    }

    private function store(FakeBreakNameFirestore $fb): Timetable_plan_store
    {
        return (new Timetable_plan_store())->init($fb, self::SCHOOL, self::SESS, 'STA0001');
    }

    /** The recesses as they were actually written to Firestore. */
    private function savedRecesses(FakeBreakNameFirestore $fb): array
    {
        foreach (array_reverse($fb->writes) as $w) {
            if (isset($w['data']['recesses'])) { return $w['data']['recesses']; }
        }
        $this->fail('no settings write carried recesses');
    }

    // ── saving ───────────────────────────────────────────────────────────

    public function test_a_school_can_name_its_break(): void
    {
        $fb = new FakeBreakNameFirestore();
        $this->svc($fb)->saveSettings('8:00AM', '2:00PM', 6,
            [['after_period' => 3, 'duration' => 45, 'name' => 'Lunch']],
            ['Monday', 'Tuesday']);

        $rec = $this->savedRecesses($fb);
        $this->assertCount(1, $rec);
        $this->assertSame(3,       $rec[0]['afterPeriod']);
        $this->assertSame(45,      $rec[0]['durationMin']);
        $this->assertSame('Lunch', $rec[0]['name']);
    }

    /**
     * BACKWARD COMPATIBILITY. Every school that exists today configured a break
     * before names were possible. Saving one without a name must keep working
     * and must store an empty name rather than omitting the field, so a reader
     * never has to guess whether it was absent or unreadable.
     */
    public function test_a_break_with_no_name_still_saves(): void
    {
        $fb = new FakeBreakNameFirestore();
        $this->svc($fb)->saveSettings('8:00AM', '2:00PM', 6,
            [['after_period' => 3, 'duration' => 45]], ['Monday']);

        $rec = $this->savedRecesses($fb);
        $this->assertSame(3, $rec[0]['afterPeriod']);
        $this->assertSame('', $rec[0]['name']);
    }

    /** Several breaks were always supported; each keeps its own word. */
    public function test_several_breaks_each_keep_their_own_name(): void
    {
        $fb = new FakeBreakNameFirestore();
        $this->svc($fb)->saveSettings('8:00AM', '3:00PM', 8, [
            ['after_period' => 2, 'duration' => 15, 'name' => 'Short break'],
            ['after_period' => 5, 'duration' => 40, 'name' => 'Lunch'],
        ], ['Monday']);

        $rec = $this->savedRecesses($fb);
        $this->assertCount(2, $rec);
        $this->assertSame('Short break', $rec[0]['name']);
        $this->assertSame('Lunch',       $rec[1]['name']);
    }

    public function test_a_name_is_tidied_and_bounded(): void
    {
        $fb = new FakeBreakNameFirestore();
        $long = str_repeat('x', Timetable_service::BREAK_NAME_MAX + 20);
        $this->svc($fb)->saveSettings('8:00AM', '3:00PM', 8, [
            ['after_period' => 2, 'duration' => 15, 'name' => "  Mid\t\tmorning  "],
            ['after_period' => 5, 'duration' => 20, 'name' => $long],
        ], ['Monday']);

        $rec = $this->savedRecesses($fb);
        $this->assertSame('Mid morning', $rec[0]['name'],
            'surrounding and repeated whitespace must be tidied, not stored');
        $this->assertSame(Timetable_service::BREAK_NAME_MAX, mb_strlen($rec[1]['name']),
            'a name is a word or two; a paragraph is capped rather than refused');
    }

    // ── reading it back ──────────────────────────────────────────────────

    public function test_the_store_reports_the_name_against_the_period_it_follows(): void
    {
        $fb = new FakeBreakNameFirestore();
        $fb->docs[self::SCHOOL . '_' . self::SESS] = [
            'periodsPerDay' => 8,
            'recesses' => [
                ['afterPeriod' => 2, 'durationMin' => 15, 'name' => 'Short break'],
                ['afterPeriod' => 5, 'durationMin' => 40, 'name' => 'Lunch'],
            ],
        ];

        $this->assertSame([2 => 'Short break', 5 => 'Lunch'],
            $this->store($fb)->breakLabels());
    }

    /**
     * A break with no name is ABSENT from the map, not present with an empty
     * string — so the surface falls back to the generic word rather than
     * drawing a label with nothing in it.
     */
    public function test_an_unnamed_break_is_absent_rather_than_blank(): void
    {
        $fb = new FakeBreakNameFirestore();
        $fb->docs[self::SCHOOL . '_' . self::SESS] = [
            'periodsPerDay' => 6,
            'recesses' => [
                ['afterPeriod' => 3, 'durationMin' => 45, 'name' => ''],
                ['afterPeriod' => 5, 'durationMin' => 10],
            ],
        ];

        $this->assertSame([], $this->store($fb)->breakLabels());
    }

    /**
     * A label is cosmetic. Failing to draw a week because a name could not be
     * read would be far worse than drawing it with the generic word, so an
     * unreadable settings document reports nothing rather than throwing.
     */
    public function test_unreadable_settings_lose_the_names_not_the_week(): void
    {
        $fb = new FakeBreakNameFirestore();
        $fb->readFails = true;

        $this->assertSame([], $this->store($fb)->breakLabels(),
            'this must never throw: the week matters more than the word');
    }

    /** A school with no breaks at all, which is most of them on day one. */
    public function test_a_school_with_no_breaks_reports_none(): void
    {
        $fb = new FakeBreakNameFirestore();
        $fb->docs[self::SCHOOL . '_' . self::SESS] = ['periodsPerDay' => 6];

        $this->assertSame([], $this->store($fb)->breakLabels());
    }

    /**
     * Settings written before names existed use the older key shape. They must
     * still be read, or an existing school's breaks would vanish from the map
     * the moment naming shipped.
     */
    public function test_the_older_key_shape_is_still_understood(): void
    {
        $fb = new FakeBreakNameFirestore();
        $fb->docs[self::SCHOOL . '_' . self::SESS] = [
            'periodsPerDay' => 6,
            'recesses' => [['after_period' => 3, 'duration' => 45, 'label' => 'Tiffin']],
        ];

        $this->assertSame([3 => 'Tiffin'], $this->store($fb)->breakLabels());
    }
}
