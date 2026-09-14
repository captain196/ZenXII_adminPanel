<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

if (!defined('BASEPATH')) define('BASEPATH', __DIR__ . '/../../system/');
if (!defined('APPPATH'))  define('APPPATH',  __DIR__ . '/../../application/');
if (!function_exists('log_message')) { function log_message($l, $m) {} }
require_once __DIR__ . '/../../application/libraries/Timetable_plan_store.php';
require_once __DIR__ . '/../Unit/TimetablePublishTest.php';

/**
 * TT-C8 — EXACTLY ONE PUBLISH WINS.
 *
 * Publishing decides a fact about the WHOLE set of published versions ("is
 * anything else already in force over these dates?") and then writes one of
 * them. That read and that write used to be separate steps, so two
 * administrators publishing at the same moment each saw no overlap, each
 * published, and each projected — and at real school scale the two projection
 * bursts interleaved and left different classes on different versions.
 *
 * The fix is aggregate-root optimistic concurrency: a per-(school, session)
 * token document, read before the check and written in the SAME batch as the
 * version, preconditioned on the value that was read.
 *
 * ── WHY THESE TESTS OPEN A REAL WINDOW ──────────────────────────────────
 *
 * Calling publishCandidate() twice in a row proves nothing: the second call
 * re-reads the token and sees the first one's write. A race only exists while
 * one caller is BETWEEN its read and its write, so the double fires a hook at
 * exactly that point and lets the other publish land inside it. Every test
 * below that claims to be a race asserts that the window actually opened.
 *
 * The same behaviour is proven against a real Firestore emulator, with two OS
 * processes, by scripts/timetable_publish_race_proof.js. This file is the fast
 * feedback loop; that one is the evidence.
 */
final class TimetablePublishConcurrencyTest extends TestCase
{
    private const SCHOOL = 'SCH_A';
    private const SESS   = '2026-2027';
    private const PLAN   = 'PLAN_A';

    private function vDoc(string $vid, string $plan = self::PLAN): string
    {
        return self::SCHOOL . '_' . self::SESS . '_' . $plan . '_' . $vid;
    }

    private function guardId(): string
    {
        return self::SCHOOL . '_' . self::SESS;
    }

    /** A candidate that is complete, checked and ready to publish. */
    private function seedCandidate(FakePublishFirestore $fb, string $vid,
                                   string $subject, string $plan = self::PLAN): void
    {
        $days = [];
        foreach (['Monday', 'Tuesday'] as $day) {
            $d = [
                'schoolId' => self::SCHOOL, 'session' => self::SESS,
                'planId' => $plan, 'versionId' => $vid,
                'classSection' => 'Class 9th/Section A', 'day' => $day,
                'periods' => [[
                    'periodNumber' => 1, 'subject' => $subject,
                    'teacherId' => 'STA0001', 'teacher' => 'A Teacher',
                    'startTime' => '09:00', 'endTime' => '09:40', 'type' => 'class',
                ]],
                'manuallyEdited' => false,
            ];
            $fb->days[self::SCHOOL . '_' . self::SESS . '_' . $plan . '_' . $vid
                      . '_Class 9th_Section A_' . $day] = $d;
            $days[] = $d;
        }

        $fb->versions[$this->vDoc($vid, $plan)] = [
            'schoolId' => self::SCHOOL, 'session' => self::SESS,
            'planId' => $plan, 'versionId' => $vid, 'planName' => 'Plan ' . $plan,
            'status' => Timetable_plan_service::STATUS_CANDIDATE,
            'effectiveFrom' => '', 'effectiveTo' => '',
            'generation' => ['state' => Timetable_plan_service::GEN_COMPLETE],
            'validation' => [
                'hard' => [], 'soft' => [], 'hardCount' => 0, 'softCount' => 0,
                'publishable' => true,
                // The REAL fingerprint over the REAL days — publishCandidate
                // refuses a verdict that does not describe the content, so an
                // invented value would simply be rejected.
                'fingerprint' => Timetable_validation_service::fingerprint($days),
            ],
            'validationStale' => false,
        ];
    }

    private function store(FakePublishFirestore $fb, string $admin = 'STA0001'): Timetable_plan_store
    {
        return (new Timetable_plan_store())->init($fb, self::SCHOOL, self::SESS, $admin);
    }

    // ── the guard behaves as a guard ─────────────────────────────────────

    public function test_a_first_publish_creates_the_token(): void
    {
        $fb = new FakePublishFirestore();
        $this->seedCandidate($fb, 'V1', 'Mathematics');

        $this->store($fb)->publishCandidate(self::PLAN, 'V1', '2026-06-01');

        $this->assertArrayHasKey($this->guardId(), $fb->guards,
            'the very first publish of a school must be guarded too, or the '
            . 'window is simply open on day one');
        $this->assertSame('V1', $fb->guards[$this->guardId()]['lastPublishedVersion']);
        $this->assertSame(self::SCHOOL, $fb->guards[$this->guardId()]['schoolId']);
    }

    public function test_the_version_and_the_token_move_together(): void
    {
        $fb = new FakePublishFirestore();
        $this->seedCandidate($fb, 'V1', 'Mathematics');
        $this->store($fb)->publishCandidate(self::PLAN, 'V1', '2026-06-01');

        $batched = array_values(array_filter($fb->writes, fn($w) =>
            in_array($w['collection'], ['timetableVersions', 'timetablePublishGuards'], true)));

        $this->assertSame(
            ['timetableVersions', 'timetablePublishGuards'],
            array_column(array_slice($batched, 0, 2), 'collection'),
            'the status write and the token write must be one commit — if the '
            . 'version could land without the token, "exactly one wins" would '
            . 'be a coincidence rather than a guarantee');
    }

    // ── the race ─────────────────────────────────────────────────────────

    /**
     * TWO ADMINISTRATORS, ONE INSTANT.
     *
     * Publisher A is held between its token read and its commit. Inside that
     * window, publisher B publishes a different version completely. A's commit
     * then finds the token moved and the whole batch rolls back.
     */
    public function test_only_one_of_two_simultaneous_publishes_succeeds(): void
    {
        $fb = new FakePublishFirestore();
        $this->seedCandidate($fb, 'VA', 'Mathematics');
        $this->seedCandidate($fb, 'VB', 'Chemistry');

        $landed = false;
        $fb->beforeCommit = function (FakePublishFirestore $inner) use (&$landed) {
            // B goes all the way through while A is mid-publish.
            (new Timetable_plan_store())
                ->init($inner, self::SCHOOL, self::SESS, 'STA_B')
                ->publishCandidate(self::PLAN, 'VB', '2026-06-01');
            $landed = true;
        };

        $conflict = null;
        try {
            $this->store($fb, 'STA_A')->publishCandidate(self::PLAN, 'VA', '2026-06-01');
        } catch (Service_exception $e) {
            $conflict = $e;
        }

        $this->assertTrue($landed,
            'the window never opened, so this test proved nothing about a race');

        $this->assertNotNull($conflict, 'the second writer must be refused');
        $this->assertSame('conflict', $conflict->errorType);
        $this->assertMatchesRegularExpression('~someone else published~i',
            $conflict->getMessage(),
            'the message has to say what happened, or the administrator will '
            . 'assume their publish worked');
        $this->assertMatchesRegularExpression('~refresh~i', $conflict->getMessage(),
            'and what to do about it');

        // Exactly one version is live, and it is B's.
        $live = array_values(array_filter($fb->versions, fn($v) =>
            ($v['status'] ?? '') === Timetable_plan_service::STATUS_PUBLISHED));
        $this->assertCount(1, $live, 'exactly one published version may exist');
        $this->assertSame('VB', $live[0]['versionId']);

        $this->assertSame(Timetable_plan_service::STATUS_CANDIDATE,
            $fb->versions[$this->vDoc('VA')]['status'],
            'the loser is left exactly as it was — a refused publish is not a '
            . 'half publish');
    }

    /**
     * THE POINT OF THE WHOLE FIX: the projection is not split.
     *
     * The failure this closes was never really "two rows say published" — it
     * was that the collection both apps read ended up holding some classes
     * from one version and some from another.
     */
    public function test_the_projection_holds_exactly_one_version(): void
    {
        $fb = new FakePublishFirestore();
        $this->seedCandidate($fb, 'VA', 'Mathematics');
        $this->seedCandidate($fb, 'VB', 'Chemistry');

        $fb->beforeCommit = function (FakePublishFirestore $inner) {
            (new Timetable_plan_store())
                ->init($inner, self::SCHOOL, self::SESS, 'STA_B')
                ->publishCandidate(self::PLAN, 'VB', date('Y-m-d'));
        };

        try {
            $this->store($fb, 'STA_A')->publishCandidate(self::PLAN, 'VA', date('Y-m-d'));
        } catch (Service_exception $e) { /* expected */ }

        $this->assertNotEmpty($fb->legacy, 'premise: something was projected');

        $sources = array_values(array_unique(array_map(
            fn($d) => (string) ($d['projectedFromVersion'] ?? ''), $fb->legacy)));
        $this->assertSame(['VB'], $sources,
            'every projected document must come from ONE version — a mixture is '
            . 'a timetable no published version describes');

        $subjects = array_values(array_unique(array_map(
            fn($d) => (string) (($d['periods'][0] ?? [])['subject'] ?? ''), $fb->legacy)));
        $this->assertSame(['Chemistry'], $subjects);
    }

    /**
     * THE TOKEN MUST BE READ BEFORE THE QUESTION IT PROTECTS.
     *
     * The overlap check and the CAS cover different halves of the window, and
     * only their ORDER makes them cover all of it:
     *
     *   read token → check overlaps → commit(precondition)
     *                └── a publish landing anywhere in here moves the token,
     *                    so the commit is refused.
     *
     * Reverse those two reads and a publish that lands DURING the overlap scan
     * is invisible twice over: the scan already returned without it, and the
     * token read afterwards already includes it, so the precondition passes and
     * both timetables go live.
     *
     * That is why this test opens its window at the scan rather than at the
     * commit — a mutant that swaps the two reads survives every other test in
     * this file, which is exactly how it was found.
     */
    public function test_a_publish_landing_during_the_overlap_scan_is_still_caught(): void
    {
        $fb = new FakePublishFirestore();
        $this->seedCandidate($fb, 'VA', 'Mathematics');
        $this->seedCandidate($fb, 'VB', 'Chemistry');

        $landed = false;
        $fb->afterVersionScan = function (FakePublishFirestore $inner) use (&$landed) {
            // A has just been told "nothing else is in force". B publishes now.
            (new Timetable_plan_store())
                ->init($inner, self::SCHOOL, self::SESS, 'STA_B')
                ->publishCandidate(self::PLAN, 'VB', '2026-06-01');
            $landed = true;
        };

        $conflict = null;
        try {
            $this->store($fb, 'STA_A')->publishCandidate(self::PLAN, 'VA', '2026-06-01');
        } catch (Service_exception $e) { $conflict = $e; }

        $this->assertTrue($landed, 'the window never opened');
        $this->assertNotNull($conflict,
            'A asked "is anything in force?", was told no, and B published '
            . 'before A could act on that answer — the token is what makes A '
            . 'notice, and only if it was read first');
        $this->assertSame('conflict', $conflict->errorType);

        $live = array_values(array_filter($fb->versions, fn($v) =>
            ($v['status'] ?? '') === Timetable_plan_service::STATUS_PUBLISHED));
        $this->assertCount(1, $live);
        $this->assertSame('VB', $live[0]['versionId']);
    }

    /** Cross-PLAN concurrency is the same invariant, and must hold identically. */
    public function test_two_plans_publishing_at_once_cannot_both_win(): void
    {
        $fb = new FakePublishFirestore();
        $this->seedCandidate($fb, 'V1', 'Mathematics', 'PLAN_REGULAR');
        $this->seedCandidate($fb, 'V1', 'Chemistry',   'PLAN_EXAM');

        $landed = false;
        $fb->beforeCommit = function (FakePublishFirestore $inner) use (&$landed) {
            (new Timetable_plan_store())
                ->init($inner, self::SCHOOL, self::SESS, 'STA_B')
                ->publishCandidate('PLAN_EXAM', 'V1', '2026-06-01');
            $landed = true;
        };

        $conflict = null;
        try {
            $this->store($fb, 'STA_A')->publishCandidate('PLAN_REGULAR', 'V1', '2026-06-01');
        } catch (Service_exception $e) { $conflict = $e; }

        $this->assertTrue($landed, 'the window never opened');
        $this->assertNotNull($conflict);
        $this->assertSame('conflict', $conflict->errorType);

        $live = array_filter($fb->versions, fn($v) =>
            ($v['status'] ?? '') === Timetable_plan_service::STATUS_PUBLISHED);
        $this->assertCount(1, $live,
            'the token is per (school, session) — NOT per plan — because the '
            . 'invariant it protects is per school');
    }

    // ── SUPERSEDE shares the guard, so it serialises against PUBLISH too ──
    //
    // Supersede and publish both change which timetable is in force. If they
    // used different guards — or if supersede used none — either could decide
    // against a state the other was in the middle of changing. All three
    // orderings are exercised, and each asserts the window actually opened.

    /** A published, open-ended version, ready to be ended. */
    private function seedPublished(FakePublishFirestore $fb, string $vid,
                                   string $from, string $plan = self::PLAN): void
    {
        $fb->versions[$this->vDoc($vid, $plan)] = [
            'schoolId' => self::SCHOOL, 'session' => self::SESS,
            'planId' => $plan, 'versionId' => $vid, 'planName' => 'Plan ' . $plan,
            'status' => Timetable_plan_service::STATUS_PUBLISHED,
            'effectiveFrom' => $from, 'effectiveTo' => '',
        ];
    }

    private function future(int $days): string
    {
        return date('Y-m-d', strtotime("+{$days} day"));
    }

    private function past(int $days): string
    {
        return date('Y-m-d', strtotime("-{$days} day"));
    }

    public function test_two_simultaneous_supersedes_cannot_both_win(): void
    {
        $fb = new FakePublishFirestore();
        $this->seedPublished($fb, 'VA', $this->past(30));
        $this->seedPublished($fb, 'VB', $this->past(20), 'PLAN_B');

        $landed = false;
        $fb->beforeCommit = function (FakePublishFirestore $inner) use (&$landed) {
            (new Timetable_plan_store())->init($inner, self::SCHOOL, self::SESS, 'STA_B')
                ->supersedeVersion('PLAN_B', 'VB', $this->future(20), 'B ends it');
            $landed = true;
        };

        $conflict = null;
        try {
            (new Timetable_plan_store())->init($fb, self::SCHOOL, self::SESS, 'STA_A')
                ->supersedeVersion(self::PLAN, 'VA', $this->future(10), 'A ends it');
        } catch (Service_exception $e) { $conflict = $e; }

        $this->assertTrue($landed, 'the window never opened');
        $this->assertNotNull($conflict, 'the second writer must be refused');
        $this->assertSame('conflict', $conflict->errorType);
        $this->assertSame('', $fb->versions[$this->vDoc('VA')]['effectiveTo'],
            'the loser changed nothing — a refused supersede is not a half one');
        $this->assertSame($this->future(20),
            $fb->versions[$this->vDoc('VB', 'PLAN_B')]['effectiveTo']);
    }

    public function test_a_publish_landing_mid_supersede_refuses_the_supersede(): void
    {
        $fb = new FakePublishFirestore();
        // VOLD is published, open-ended, and starts in the FUTURE. That is what
        // makes both operations individually legal at the same moment: a publish
        // over a past range does not overlap it, so the publish is not refused
        // for an overlap and the two genuinely race on the token alone.
        $this->seedPublished($fb, 'VOLD', $this->future(50));
        $this->seedCandidate($fb, 'VNEW', 'Chemistry', 'PLAN_B');

        $landed = false;
        $fb->beforeCommit = function (FakePublishFirestore $inner) use (&$landed) {
            (new Timetable_plan_store())->init($inner, self::SCHOOL, self::SESS, 'STA_B')
                ->publishCandidate('PLAN_B', 'VNEW', $this->past(30), $this->past(10));
            $landed = true;
        };

        $conflict = null;
        try {
            (new Timetable_plan_store())->init($fb, self::SCHOOL, self::SESS, 'STA_A')
                ->supersedeVersion(self::PLAN, 'VOLD', $this->future(80), 'ending');
        } catch (Service_exception $e) { $conflict = $e; }

        $this->assertTrue($landed, 'the window never opened');
        $this->assertNotNull($conflict,
            'a publish changes what is in force, so a supersede deciding across it '
            . 'must be refused — one shared token is what makes that true');
        $this->assertSame('conflict', $conflict->errorType);
        $this->assertSame('', $fb->versions[$this->vDoc('VOLD')]['effectiveTo']);
    }

    public function test_a_supersede_landing_mid_publish_refuses_the_publish(): void
    {
        $fb = new FakePublishFirestore();
        // VOLD is published, open-ended, and starts in the FUTURE. That is what
        // makes both operations individually legal at the same moment: a publish
        // over a past range does not overlap it, so the publish is not refused
        // for an overlap and the two genuinely race on the token alone.
        $this->seedPublished($fb, 'VOLD', $this->future(50));
        $this->seedCandidate($fb, 'VNEW', 'Chemistry', 'PLAN_B');

        $landed = false;
        $fb->beforeCommit = function (FakePublishFirestore $inner) use (&$landed) {
            (new Timetable_plan_store())->init($inner, self::SCHOOL, self::SESS, 'STA_B')
                ->supersedeVersion(self::PLAN, 'VOLD', $this->future(80), 'ending');
            $landed = true;
        };

        $conflict = null;
        try {
            (new Timetable_plan_store())->init($fb, self::SCHOOL, self::SESS, 'STA_A')
                ->publishCandidate('PLAN_B', 'VNEW', $this->past(30), $this->past(10));
        } catch (Service_exception $e) { $conflict = $e; }

        $this->assertTrue($landed, 'the window never opened');
        $this->assertNotNull($conflict, 'the publish must be refused');
        $this->assertSame('conflict', $conflict->errorType);
        $this->assertSame(Timetable_plan_service::STATUS_CANDIDATE,
            $fb->versions[$this->vDoc('VNEW', 'PLAN_B')]['status'],
            'the candidate is untouched');
    }

    // ── retry ────────────────────────────────────────────────────────────

    /**
     * The loser can retry, and gets a truthful answer rather than a second
     * failure of the same kind.
     */
    public function test_the_refused_publisher_can_retry_and_is_told_the_real_reason(): void
    {
        $fb = new FakePublishFirestore();
        $this->seedCandidate($fb, 'VA', 'Mathematics');
        $this->seedCandidate($fb, 'VB', 'Chemistry');

        $fb->beforeCommit = function (FakePublishFirestore $inner) {
            (new Timetable_plan_store())
                ->init($inner, self::SCHOOL, self::SESS, 'STA_B')
                ->publishCandidate(self::PLAN, 'VB', '2026-06-01');
        };
        try {
            $this->store($fb, 'STA_A')->publishCandidate(self::PLAN, 'VA', '2026-06-01');
            $this->fail('expected a conflict');
        } catch (Service_exception $e) {
            $this->assertSame('conflict', $e->errorType);
        }

        // Retry, no window this time. The overlap is now REAL — B's timetable
        // is live over those dates — so the honest answer is the overlap
        // refusal, not a second conflict.
        try {
            $this->store($fb, 'STA_A')->publishCandidate(self::PLAN, 'VA', '2026-06-01');
            $this->fail('the retry should still be refused — VB is live');
        } catch (Service_exception $e) {
            $this->assertSame('overlap', $e->errorType,
                'the second attempt must report what is actually in the way, not '
                . 'repeat a concurrency error that is no longer true');
        }

        // And a retry over dates nobody claims succeeds. Note the CLOSED
        // range: VB is open-ended from 2026-06-01, so every later date is
        // already claimed — see the test below, which pins that consequence.
        $res = $this->store($fb, 'STA_A')
            ->publishCandidate(self::PLAN, 'VA', '2025-01-01', '2025-12-31');
        $this->assertSame('VA', $res['versionId']);
    }

    /**
     * THE COST OF THE INVARIANT, PINNED SO IT CANNOT BE FORGOTTEN.
     *
     * An open-ended published timetable claims every future date. With one
     * timetable in force enforced, the NEXT one can then only be published over
     * dates that end before it starts — which is to say, not at all, going
     * forward. And there is no unpublish and no re-dating, so an administrator
     * who published the first timetable without an end date cannot publish a
     * second one, ever.
     *
     * This is not a defect in the guard. It is the ordinary consequence of the
     * rule, and it lands on the most ordinary workflow there is: publishing
     * next term's timetable. It is the concrete case for the recovery action
     * (TT-C8 option 6), which is DESIGN-ONLY at the time of writing.
     *
     * The test asserts today's real behaviour rather than the behaviour we
     * would like, so that when the recovery action ships this test fails and
     * has to be revisited deliberately.
     */
    public function test_an_open_ended_timetable_blocks_every_later_publish(): void
    {
        $fb = new FakePublishFirestore();
        $this->seedCandidate($fb, 'V1', 'Mathematics');
        $this->seedCandidate($fb, 'V2', 'Chemistry');

        // Term 1, open-ended — the natural thing to enter when you do not yet
        // know when it ends.
        $this->store($fb)->publishCandidate(self::PLAN, 'V1', '2026-04-01');

        // Term 2, six months later. Refused.
        try {
            $this->store($fb)->publishCandidate(self::PLAN, 'V2', '2026-10-01');
            $this->fail('an open-ended predecessor should block this today');
        } catch (Service_exception $e) {
            $this->assertSame('overlap', $e->errorType);
        }

        // And nothing in the product can end the first one: publishing is
        // one-way, and re-dating a published version is refused by design.
        $this->assertSame('', $fb->versions[$this->vDoc('V1')]['effectiveTo'],
            'no path here closes the predecessor — which is exactly why a '
            . 'recovery/supersede action is needed before this rule is usable '
            . 'for a school that runs more than one timetable a year');

        // Bounding it AT PUBLISH TIME is the only way through today, and it
        // works — so the invariant is enforceable, it just demands foresight.
        $fb2 = new FakePublishFirestore();
        $this->seedCandidate($fb2, 'V1', 'Mathematics');
        $this->seedCandidate($fb2, 'V2', 'Chemistry');
        $this->store($fb2)->publishCandidate(self::PLAN, 'V1', '2026-04-01', '2026-09-30');
        $res = $this->store($fb2)->publishCandidate(self::PLAN, 'V2', '2026-10-01');
        $this->assertSame('V2', $res['versionId'],
            'consecutive, non-overlapping terms publish normally');
    }

    // ── fail closed ──────────────────────────────────────────────────────

    /**
     * An unreadable token is not permission to publish.
     *
     * Proceeding here would publish with no concurrency control at all, and
     * would do it silently — which is worse than the original defect, because
     * it would look guarded.
     */
    public function test_an_unreadable_guard_refuses_the_publish(): void
    {
        $fb = new FakePublishFirestore();
        $this->seedCandidate($fb, 'V1', 'Mathematics');
        $fb->guardUnreadable = true;

        try {
            $this->store($fb)->publishCandidate(self::PLAN, 'V1', '2026-06-01');
            $this->fail('an unreadable guard must refuse');
        } catch (Service_exception $e) {
            $this->assertSame('read', $e->errorType);
            $this->assertMatchesRegularExpression('~nothing was changed~i', $e->getMessage());
        }

        $this->assertSame(Timetable_plan_service::STATUS_CANDIDATE,
            $fb->versions[$this->vDoc('V1')]['status'],
            'and must leave the candidate untouched');
        $this->assertSame([], $fb->legacy);
    }

    /** A guard belonging to another tenant is never adopted as ours. */
    public function test_a_foreign_guard_document_is_refused(): void
    {
        $fb = new FakePublishFirestore();
        $this->seedCandidate($fb, 'V1', 'Mathematics');
        $fb->guards[$this->guardId()] = ['schoolId' => 'SCH_OTHER', 'session' => self::SESS];

        $this->expectException(Service_exception::class);
        $this->expectExceptionMessageMatches('~contact support~i');
        $this->store($fb)->publishCandidate(self::PLAN, 'V1', '2026-06-01');
    }

    /** An unclassifiable commit outcome is a failure, never a publish. */
    public function test_an_unknown_commit_outcome_is_not_treated_as_published(): void
    {
        $fb = new class extends FakePublishFirestore {
            public function firestoreCommitBatchStatus($ops)
            {
                return ['status' => 'UNAVAILABLE'];
            }
        };
        $this->seedCandidate($fb, 'V1', 'Mathematics');

        try {
            $this->store($fb)->publishCandidate(self::PLAN, 'V1', '2026-06-01');
            $this->fail('an unclassified commit must not count as a publish');
        } catch (Service_exception $e) {
            $this->assertSame('write', $e->errorType);
        }
        $this->assertSame([], $fb->legacy,
            'nothing may reach the collection the apps read');
    }
}
