<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once APPPATH . 'libraries/Student_calendar_service.php';
require_once APPPATH . 'libraries/Student_calendar_store.php';

/**
 * A Firebase stand-in that models the two things that actually matter here:
 * a document version (`__updateTime`) and a commit that honours preconditions.
 */
final class FakeCalendarFirebase
{
    public array  $docs = [];
    public array  $commits = [];
    public bool   $readThrows = false;
    /** null = do not force; a string (including '') = force that exact status. */
    public ?string $forceStatus = null;
    /** @var callable|null fires BETWEEN the read and the commit */
    public $betweenReadAndCommit = null;

    public function firestoreGetOrFail(string $collection, string $docId): ?array
    {
        if ($this->readThrows) throw new RuntimeException('simulated read failure');
        return $this->docs["{$collection}/{$docId}"] ?? null;
    }

    public function firestoreCommitBatchStatus(array $ops): array
    {
        if ($this->betweenReadAndCommit) {
            $cb = $this->betweenReadAndCommit;
            $this->betweenReadAndCommit = null;   // fire once
            $cb($this);
        }
        if ($this->forceStatus !== null) {
            return ['status' => $this->forceStatus, 'message' => 'forced'];
        }

        $op  = $ops[0];
        $key = "{$op['collection']}/{$op['docId']}";
        $cur = $this->docs[$key] ?? null;

        $pre = $op['precondition'] ?? [];
        if (isset($pre['updateTime'])) {
            $have = (string) ($cur['__updateTime'] ?? '');
            if ($have !== (string) $pre['updateTime']) {
                return ['status' => 'PRECONDITION_CONFLICT', 'message' => 'stale'];
            }
        } elseif (isset($pre['exists']) && $pre['exists'] === false) {
            if ($cur !== null) return ['status' => 'PRECONDITION_CONFLICT', 'message' => 'exists'];
        }

        $base = is_array($cur) ? $cur : [];
        $this->docs[$key] = array_merge($base, $op['data'], [
            '__updateTime' => 'T' . (count($this->commits) + 1),
        ]);
        $this->commits[] = $op;
        return ['status' => 'SUCCESS', 'message' => ''];
    }
}

/**
 * THE ONLY WRITER OF THE STUDENT CALENDAR.
 *
 * Two properties carry the whole design and are the reason this file is long:
 * the version list is APPEND-ONLY (so history cannot be rewritten by editing a
 * rule), and the write is COMPARE-AND-SET (so two administrators cannot silently
 * lose each other's policy).
 */
final class StudentCalendarStoreTest extends TestCase
{
    private const SCHOOL = 'SCH_A';
    private const SESS   = '2026-2027';

    private function fb(array $existing = null, string $updateTime = 'T0'): FakeCalendarFirebase
    {
        $fb = new FakeCalendarFirebase();
        if ($existing !== null) {
            $fb->docs['attendanceSettings/' . self::SCHOOL] = [
                'schoolId'       => self::SCHOOL,
                'working_days'   => ['Mon','Tue','Wed','Thu','Fri','Sat'],
                'studentCalendar'=> ['versions' => $existing],
                '__updateTime'   => $updateTime,
            ];
        }
        return $fb;
    }

    private function store(FakeCalendarFirebase $fb, string $actor = 'STA0001'): Student_calendar_store
    {
        return (new Student_calendar_store())->init($fb, self::SCHOOL, self::SESS, $actor);
    }

    private function v(string $from, array $offs = ['Sat','Sun']): array
    {
        return ['effectiveFrom' => $from, 'weeklyOffs' => $offs, 'nthWeekdayOffs' => []];
    }

    private function stored(FakeCalendarFirebase $fb): array
    {
        return $fb->docs['attendanceSettings/' . self::SCHOOL]['studentCalendar']['versions'] ?? [];
    }

    // ── HAPPY PATH ───────────────────────────────────────────────────────

    public function test_a_first_version_is_saved_and_stamped(): void
    {
        $fb = $this->fb([]);
        $r  = $this->store($fb)->saveVersion($this->v('2027-01-01'), '2026-12-31');

        $this->assertSame(Student_calendar_store::OK, $r['status'], implode(' | ', $r['errors']));
        $saved = $this->stored($fb);
        $this->assertCount(1, $saved);
        $this->assertSame('2027-01-01', $saved[0]['effectiveFrom']);
        $this->assertSame('STA0001', $saved[0]['createdBy'], 'authorship must be recorded');
        $this->assertNotSame('', $saved[0]['createdAt']);
        $this->assertSame(self::SESS, $saved[0]['session']);
    }

    public function test_the_tenant_is_written_explicitly(): void
    {
        $fb = $this->fb([]);
        $this->store($fb)->saveVersion($this->v('2027-01-01'), '2026-12-31');
        $this->assertSame(self::SCHOOL,
            $fb->docs['attendanceSettings/' . self::SCHOOL]['schoolId']);
    }

    public function test_neighbouring_settings_are_preserved(): void
    {
        $fb = $this->fb([]);
        $this->store($fb)->saveVersion($this->v('2027-01-01'), '2026-12-31');
        $this->assertSame(['Mon','Tue','Wed','Thu','Fri','Sat'],
            $fb->docs['attendanceSettings/' . self::SCHOOL]['working_days'],
            'a merge write must not clobber the co-located attendance settings');
    }

    // ── APPEND-ONLY — THE HISTORICAL GUARANTEE ───────────────────────────

    public function test_saving_never_edits_or_removes_an_existing_version(): void
    {
        $original = [$this->v('2026-04-01', ['Sun'])];
        $fb = $this->fb($original);

        $this->store($fb)->saveVersion($this->v('2027-01-01', ['Sat','Sun']), '2026-12-31');

        $saved = $this->stored($fb);
        $this->assertCount(2, $saved);
        $this->assertSame('2026-04-01', $saved[0]['effectiveFrom']);
        $this->assertSame(['Sun'], $saved[0]['weeklyOffs'],
            'the earlier policy must survive byte-for-byte — it is what every past '
            . 'date still resolves against');
    }

    public function test_versions_are_stored_in_effective_date_order(): void
    {
        $fb = $this->fb([$this->v('2027-06-01')]);
        $this->store($fb)->saveVersion($this->v('2027-01-01'), '2026-12-31');

        $saved = $this->stored($fb);
        $this->assertSame(['2027-01-01', '2027-06-01'],
            array_column($saved, 'effectiveFrom'));
    }

    // ── RETROACTIVE REFUSAL ──────────────────────────────────────────────

    public function test_a_version_landing_on_settled_attendance_is_refused_and_writes_nothing(): void
    {
        $fb = $this->fb([$this->v('2026-04-01', ['Sun'])]);
        $r  = $this->store($fb)->saveVersion($this->v('2026-06-01'), '2026-12-31');

        $this->assertSame(Student_calendar_store::INVALID, $r['status']);
        $this->assertStringContainsString('already recorded', implode(' ', $r['errors']));
        $this->assertCount(0, $fb->commits, 'a refusal must not reach the database at all');
        $this->assertCount(1, $this->stored($fb));
    }

    public function test_a_malformed_version_is_refused_before_any_write(): void
    {
        $fb = $this->fb([]);
        foreach ([
            ['effectiveFrom' => '',           'weeklyOffs' => ['Sun']],
            ['effectiveFrom' => '2027-01-01', 'weeklyOffs' => []],
            ['effectiveFrom' => '2027-01-01', 'weeklyOffs' => ['Funday']],
            ['effectiveFrom' => '01-01-2027', 'weeklyOffs' => ['Sun']],
        ] as $bad) {
            $r = $this->store($fb)->saveVersion($bad, '2026-12-31');
            $this->assertSame(Student_calendar_store::INVALID, $r['status']);
        }
        $this->assertCount(0, $fb->commits);
    }

    public function test_a_duplicate_effective_date_is_refused(): void
    {
        $fb = $this->fb([$this->v('2027-01-01', ['Sun'])]);
        $r  = $this->store($fb)->saveVersion($this->v('2027-01-01', ['Sat','Sun']), '2026-12-31');

        $this->assertSame(Student_calendar_store::INVALID, $r['status']);
        $this->assertCount(0, $fb->commits);
    }

    // ── CONCURRENCY ──────────────────────────────────────────────────────

    /**
     * THE LOST-UPDATE WINDOW. Two administrators each read a list of one and each
     * write a list of two. Without a precondition the second silently erases the
     * first's version, and the school believes a policy is in force that is not.
     */
    public function test_a_concurrent_save_is_refused_rather_than_silently_overwriting(): void
    {
        $fb = $this->fb([$this->v('2026-04-01', ['Sun'])], 'T0');

        // B completes an entire save in the window between A's read and A's commit.
        $fb->betweenReadAndCommit = function (FakeCalendarFirebase $inner) {
            (new Student_calendar_store())
                ->init($inner, self::SCHOOL, self::SESS, 'STA_B')
                ->saveVersion(['effectiveFrom' => '2027-03-01', 'weeklyOffs' => ['Sun'],
                               'nthWeekdayOffs' => []], '2026-12-31');
        };

        $r = $this->store($fb, 'STA_A')
            ->saveVersion($this->v('2027-01-01', ['Sat','Sun']), '2026-12-31');

        $this->assertSame(Student_calendar_store::CONFLICT, $r['status'],
            "A must be refused, not merged blindly over B's completed write");

        $saved  = $this->stored($fb);
        $froms  = array_column($saved, 'effectiveFrom');
        $this->assertContains('2027-03-01', $froms, "B's version must survive");
        $this->assertNotContains('2027-01-01', $froms,
            "A's version must NOT have landed — a conflict that still wrote would be "
            . 'the lost update this precondition exists to prevent');
    }

    public function test_the_commit_carries_the_documents_update_time(): void
    {
        $fb = $this->fb([], 'T7');
        $this->store($fb)->saveVersion($this->v('2027-01-01'), '2026-12-31');

        $this->assertSame(['updateTime' => 'T7'], $fb->commits[0]['precondition']);
    }

    public function test_a_missing_document_is_created_with_an_exists_false_guard(): void
    {
        $fb = new FakeCalendarFirebase();          // no document at all
        $r  = $this->store($fb)->saveVersion($this->v('2027-01-01'), '2026-12-31');

        $this->assertSame(Student_calendar_store::OK, $r['status'], implode(' | ', $r['errors']));
        $this->assertSame(['exists' => false], $fb->commits[0]['precondition']);
    }

    // ── FAILURE SEMANTICS ────────────────────────────────────────────────

    public function test_a_read_failure_refuses_and_writes_nothing(): void
    {
        $fb = $this->fb([$this->v('2026-04-01', ['Sun'])]);
        $fb->readThrows = true;

        $r = $this->store($fb)->saveVersion($this->v('2027-01-01'), '2026-12-31');

        $this->assertSame(Student_calendar_store::FAILED, $r['status']);
        $this->assertCount(0, $fb->commits,
            'a silent [] on read would have overwritten every historical version '
            . 'with a single element — so the read must fail loud and refuse');
        $this->assertCount(1, $this->stored($fb));
    }

    /** An UNKNOWN commit must never be reported as success. */
    public function test_an_unknown_commit_outcome_is_a_failure_not_a_success(): void
    {
        foreach (['UNKNOWN', 'FAILURE', '', 'MAYBE'] as $status) {
            $fb = $this->fb([]);
            $fb->forceStatus = $status;
            $r = $this->store($fb)->saveVersion($this->v('2027-01-01'), '2026-12-31');

            $this->assertSame(Student_calendar_store::FAILED, $r['status'],
                "'{$status}' must not be treated as success — telling the caller it "
                . 'worked would invite a double-write');
        }
    }

    public function test_only_the_literal_success_status_counts_as_success(): void
    {
        $fb = $this->fb([]);
        $fb->forceStatus = 'OK';                    // the classic wrong literal
        $r = $this->store($fb)->saveVersion($this->v('2027-01-01'), '2026-12-31');
        $this->assertSame(Student_calendar_store::FAILED, $r['status']);
    }

    public function test_an_uninitialised_store_refuses(): void
    {
        $r = (new Student_calendar_store())->saveVersion($this->v('2027-01-01'), '2026-12-31');
        $this->assertSame(Student_calendar_store::FAILED, $r['status']);
    }

    // ── NO SEEDING, EVER ─────────────────────────────────────────────────

    /**
     * The store must never invent a policy the school did not state — not from
     * `working_days`, not from the legacy pattern.
     */
    public function test_the_store_never_seeds_a_calendar_by_itself(): void
    {
        $fb = new FakeCalendarFirebase();
        $fb->docs['attendanceSettings/' . self::SCHOOL] = [
            'working_days' => ['Mon','Tue','Wed','Thu','Fri'],
            '__updateTime' => 'T0',
        ];

        // Merely constructing and reading must write nothing.
        $this->store($fb);
        $this->assertCount(0, $fb->commits);

        // And a save writes ONLY what was asked for.
        $this->store($fb)->saveVersion($this->v('2027-01-01', ['Sun']), '2026-12-31');
        $saved = $this->stored($fb);
        $this->assertCount(1, $saved);
        $this->assertSame(['Sun'], $saved[0]['weeklyOffs'],
            'the stale Mon-Fri setting must not have leaked into the saved policy');
    }

    // ── TENANT ISOLATION ─────────────────────────────────────────────────

    /**
     * A tenant hint smuggled into the payload must be ignored.
     *
     * The tenant comes from the authenticated session via init(), never from the
     * data being saved. This was assumed rather than proven until a surviving
     * mutant — which read the target document id out of `$proposed` — went
     * unnoticed by the whole suite, because no test had ever put a schoolId
     * there. It is exactly the shape of a cross-tenant write.
     */
    public function test_a_school_id_in_the_payload_cannot_redirect_the_write(): void
    {
        $fb = $this->fb([]);
        $fb->docs['attendanceSettings/SCH_VICTIM'] = ['__updateTime' => 'T0'];

        $poisoned = $this->v('2027-01-01') + ['schoolId' => 'SCH_VICTIM'];
        $r = $this->store($fb)->saveVersion($poisoned, '2026-12-31');

        $this->assertSame(Student_calendar_store::OK, $r['status'], implode(' | ', $r['errors']));
        $this->assertSame(self::SCHOOL, $fb->commits[0]['docId'],
            'the tenant must come from the session, never from the payload');
        $this->assertArrayNotHasKey('studentCalendar', $fb->docs['attendanceSettings/SCH_VICTIM'],
            'the other tenant must be untouched');
        $this->assertSame(self::SCHOOL,
            $fb->docs['attendanceSettings/' . self::SCHOOL]['schoolId'],
            'and the stored tenant binding must still be our own');
    }

    public function test_the_store_only_ever_writes_its_own_tenants_document(): void
    {
        $fb = $this->fb([]);
        $fb->docs['attendanceSettings/SCH_B'] = ['__updateTime' => 'T0'];

        $this->store($fb)->saveVersion($this->v('2027-01-01'), '2026-12-31');

        $this->assertSame('attendanceSettings/' . self::SCHOOL,
            $fb->commits[0]['collection'] . '/' . $fb->commits[0]['docId']);
        $this->assertArrayNotHasKey('studentCalendar', $fb->docs['attendanceSettings/SCH_B'],
            "School B's settings must be untouched");
    }
}
