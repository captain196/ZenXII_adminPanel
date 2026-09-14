<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

if (!defined('BASEPATH')) define('BASEPATH', __DIR__ . '/../../system/');
if (!defined('APPPATH'))  define('APPPATH',  __DIR__ . '/../../application/');
if (!function_exists('log_message')) { function log_message($l, $m) {} }
require_once __DIR__ . '/../../application/libraries/Timetable_plan_store.php';
require_once __DIR__ . '/../Unit/TimetablePublishTest.php';

/**
 * TT-C8 OPTION 6 — ENDING A TIMETABLE'S FUTURE, NEVER ITS PAST.
 *
 * A published version with no end date claims every future date. Because
 * publishing refuses an overlap (option 4), such a version blocks every later
 * publish for its school and session — including its own correction. SUPERSEDE
 * is the one narrow action that unblocks it.
 *
 * Everything here defends two invariants. They are stated as PROPERTIES over a
 * date range rather than as examples, because an example proves a case and this
 * needs to prove a rule:
 *
 *   HISTORICAL SAFETY      for every date before today, resolution is unchanged
 *   COVERAGE MONOTONICITY  for every date, covers_after ⊆ covers_before
 *
 * The second is what makes the action structurally incapable of creating an
 * ambiguity — which is exactly why it is safe to use as the repair for one.
 */
final class TimetableSupersedeTest extends TestCase
{
    private const SCHOOL = 'SCH_A';
    private const SESS   = '2026-2027';
    private const PLAN   = 'PLAN_A';

    private function vDoc(string $vid, string $plan = self::PLAN): string
    {
        return self::SCHOOL . '_' . self::SESS . '_' . $plan . '_' . $vid;
    }

    private function today(): string { return date('Y-m-d'); }

    private function shift(string $date, int $days): string
    {
        return date('Y-m-d', strtotime($date . ' ' . $days . ' day'));
    }

    /** A published version, open-ended unless bounded. */
    private function published(FakePublishFirestore $fb, string $vid, string $from,
                               string $to = '', string $plan = self::PLAN): void
    {
        $fb->versions[$this->vDoc($vid, $plan)] = [
            'schoolId' => self::SCHOOL, 'session' => self::SESS,
            'planId' => $plan, 'versionId' => $vid, 'planName' => 'Plan ' . $plan,
            'status' => Timetable_plan_service::STATUS_PUBLISHED,
            'effectiveFrom' => $from, 'effectiveTo' => $to,
            'publishedAt' => '2026-01-01T00:00:00+00:00', 'publishedBy' => 'STA0001',
            'publishedFingerprint' => 'FP_' . $vid, 'publishedDayCount' => 6,
            'generation' => ['state' => Timetable_plan_service::GEN_COMPLETE],
            'validation' => ['fingerprint' => 'FP_' . $vid, 'hardCount' => 0],
        ];
    }

    private function store(FakePublishFirestore $fb, string $admin = 'STA0009'): Timetable_plan_store
    {
        return (new Timetable_plan_store())->init($fb, self::SCHOOL, self::SESS, $admin);
    }

    /** Every version the store can see, as resolution sees them. */
    private function coversByDate(FakePublishFirestore $fb, string $date): array
    {
        $out = [];
        foreach ($fb->versions as $v) {
            if (Timetable_plan_service::coversDate($v, $date)) {
                $out[] = $v['planId'] . '/' . $v['versionId'];
            }
        }
        sort($out);
        return $out;
    }

    // ── the ordinary case ────────────────────────────────────────────────

    public function test_an_open_ended_published_version_can_be_closed(): void
    {
        $fb = new FakePublishFirestore();
        $this->published($fb, 'V1', $this->shift($this->today(), -30));
        $end = $this->shift($this->today(), 30);

        $res = $this->store($fb)->supersedeVersion(self::PLAN, 'V1', $end, 'Term 1 ends');

        $this->assertSame($end, $res['effectiveTo']);
        $this->assertSame($end, $fb->versions[$this->vDoc('V1')]['effectiveTo']);
        $this->assertSame('Term 1 ends', $fb->versions[$this->vDoc('V1')]['supersededReason']);
        $this->assertSame('STA0009', $fb->versions[$this->vDoc('V1')]['supersededBy']);
        $this->assertNotSame('', $fb->versions[$this->vDoc('V1')]['supersededAt']);
    }

    /**
     * The point of the whole action: a successor becomes publishable.
     */
    public function test_a_successor_can_then_be_published(): void
    {
        $fb = new FakePublishFirestore();
        $this->published($fb, 'V1', $this->shift($this->today(), -30));
        $end = $this->shift($this->today(), 30);

        $this->store($fb)->supersedeVersion(self::PLAN, 'V1', $end, 'Term 1 ends');

        // The overlap check — the thing that was blocking — is now clear.
        $overlaps = Timetable_plan_service::publishedOverlaps(
            array_values($fb->versions), self::PLAN, 'V2', $this->shift($end, 1), '');

        $this->assertSame([], $overlaps,
            'closing the predecessor is what makes the next term publishable at all');
    }

    // ── PROPERTY: the past cannot move ───────────────────────────────────

    /**
     * HISTORICAL SAFETY, over an exhaustive range rather than a few samples.
     *
     * 120 days either side of today, every single one compared before and
     * after. Any date before today whose resolution changes is a rewrite of
     * what a timetable applied to on a day that has already happened —
     * attendance and lesson plans were recorded against those days.
     */
    public function test_no_past_date_resolves_differently_afterwards(): void
    {
        $fb = new FakePublishFirestore();
        $this->published($fb, 'V0', $this->shift($this->today(), -200),
                                     $this->shift($this->today(), -101));
        $this->published($fb, 'V1', $this->shift($this->today(), -100));

        $dates = [];
        for ($i = -120; $i <= 120; $i++) $dates[] = $this->shift($this->today(), $i);

        $before = [];
        foreach ($dates as $d) $before[$d] = $this->coversByDate($fb, $d);

        $this->store($fb)->supersedeVersion(
            self::PLAN, 'V1', $this->shift($this->today(), 10), 'closing the term');

        $changedPast = [];
        foreach ($dates as $d) {
            if ($d >= $this->today()) continue;
            $now = $this->coversByDate($fb, $d);
            if ($now !== $before[$d]) $changedPast[$d] = [$before[$d], $now];
        }

        $this->assertSame([], $changedPast,
            'a past date changed which timetable applied to it: '
            . json_encode(array_slice($changedPast, 0, 3)));
    }

    /**
     * And the future genuinely DID change — otherwise the test above would
     * pass on an action that does nothing at all.
     */
    public function test_the_future_is_what_changes(): void
    {
        $fb = new FakePublishFirestore();
        $this->published($fb, 'V1', $this->shift($this->today(), -30));
        $end   = $this->shift($this->today(), 10);
        $after = $this->shift($end, 1);

        $this->assertSame([self::PLAN . '/V1'], $this->coversByDate($fb, $after));
        $this->store($fb)->supersedeVersion(self::PLAN, 'V1', $end, 'closing');
        $this->assertSame([], $this->coversByDate($fb, $after),
            'the day after the end date must no longer be covered');
        $this->assertSame([self::PLAN . '/V1'], $this->coversByDate($fb, $end),
            'and the end date itself is inclusive — it still applies that day');
    }

    // ── PROPERTY: coverage only ever shrinks ─────────────────────────────

    /**
     * COVERAGE MONOTONICITY, exhaustively.
     *
     * For every date, the set of versions covering it afterwards must be a
     * SUBSET of the set before. If that holds, a date covered by one version
     * can never come to be covered by two, so this action cannot create an
     * ambiguity — no matter what state it is applied to.
     */
    public function test_coverage_can_only_shrink(): void
    {
        $fb = new FakePublishFirestore();
        // Deliberately started from an AMBIGUOUS state: two open-ended
        // versions, in different plans, overlapping. This is the worst input
        // the action can be handed, and the property must still hold.
        $this->published($fb, 'V1', $this->shift($this->today(), -60), '', 'PLAN_REGULAR');
        $this->published($fb, 'V2', $this->shift($this->today(), -10), '', 'PLAN_EXAM');

        $dates = [];
        for ($i = -90; $i <= 180; $i++) $dates[] = $this->shift($this->today(), $i);

        $before = [];
        foreach ($dates as $d) $before[$d] = $this->coversByDate($fb, $d);

        $this->store($fb)->supersedeVersion(
            'PLAN_REGULAR', 'V1', $this->shift($this->today(), 5), 'ended by the exam plan');

        $violations = [];
        foreach ($dates as $d) {
            $now = $this->coversByDate($fb, $d);
            $added = array_diff($now, $before[$d]);
            if (!empty($added)) $violations[$d] = $added;
        }

        $this->assertSame([], $violations,
            'coverage was ADDED on some date, so this action could create an '
            . 'ambiguity: ' . json_encode(array_slice($violations, 0, 3)));
    }

    public function test_it_never_increases_the_number_of_versions_covering_a_date(): void
    {
        $fb = new FakePublishFirestore();
        $this->published($fb, 'V1', $this->shift($this->today(), -60), '', 'PLAN_REGULAR');
        $this->published($fb, 'V2', $this->shift($this->today(), -10), '', 'PLAN_EXAM');

        $counts = [];
        for ($i = -90; $i <= 180; $i++) {
            $d = $this->shift($this->today(), $i);
            $counts[$d] = count($this->coversByDate($fb, $d));
        }

        $this->store($fb)->supersedeVersion(
            'PLAN_REGULAR', 'V1', $this->shift($this->today(), 5), 'ended');

        foreach ($counts as $d => $wasCount) {
            $this->assertLessThanOrEqual($wasCount, count($this->coversByDate($fb, $d)),
                "more versions cover {$d} than before — an ambiguity was created");
        }
    }

    // ── PUBLISHED CONTENT IS IMMUTABLE ───────────────────────────────────

    /**
     * The write mask, checked field by field against what was there.
     *
     * Not "did the periods change" — the whole document, minus the four fields
     * the action is permitted to touch, must be byte-identical.
     */
    public function test_only_the_authorised_fields_are_written(): void
    {
        $fb = new FakePublishFirestore();
        $this->published($fb, 'V1', $this->shift($this->today(), -30));
        $before = $fb->versions[$this->vDoc('V1')];

        $this->store($fb)->supersedeVersion(
            self::PLAN, 'V1', $this->shift($this->today(), 10), 'closing');

        $after = $fb->versions[$this->vDoc('V1')];

        foreach ($before as $field => $value) {
            if (in_array($field, Timetable_plan_store::SUPERSEDE_FIELDS, true)) continue;
            $this->assertSame($value, $after[$field] ?? null,
                "published field `{$field}` was modified by a transition that may "
                . 'only close a date range');
        }

        $added = array_diff(array_keys($after), array_keys($before));
        sort($added);
        $this->assertSame(['supersededAt', 'supersededBy', 'supersededReason'], $added,
            'only the provenance fields may appear');
    }

    public function test_the_batch_write_mask_contains_only_authorised_fields(): void
    {
        $fb = new FakePublishFirestore();
        $this->published($fb, 'V1', $this->shift($this->today(), -30));

        $this->store($fb)->supersedeVersion(
            self::PLAN, 'V1', $this->shift($this->today(), 10), 'closing');

        $versionWrites = array_values(array_filter($fb->writes,
            fn($w) => $w['collection'] === 'timetableVersions'));
        $this->assertCount(1, $versionWrites);

        $written = array_keys($versionWrites[0]['data']);
        sort($written);
        $allowed = Timetable_plan_store::SUPERSEDE_FIELDS;
        sort($allowed);
        $this->assertSame($allowed, $written,
            'the payload itself — not merely its effect — must be limited to the '
            . 'authorised fields, or a merge could carry a published field');
    }

    /** status is deliberately NOT written. See the method docblock. */
    public function test_status_is_not_mutated(): void
    {
        $fb = new FakePublishFirestore();
        $this->published($fb, 'V1', $this->shift($this->today(), -30));

        $this->store($fb)->supersedeVersion(
            self::PLAN, 'V1', $this->shift($this->today(), 10), 'closing');

        $this->assertSame(Timetable_plan_service::STATUS_PUBLISHED,
            $fb->versions[$this->vDoc('V1')]['status'],
            'coversDate() gates on status === published, so flipping it would '
            . 'stop the version covering the PAST dates it genuinely applied to');
    }

    public function test_the_version_days_are_never_touched(): void
    {
        $fb = new FakePublishFirestore();
        $this->published($fb, 'V1', $this->shift($this->today(), -30));
        $fb->days['day1'] = ['periods' => [['periodNumber' => 1, 'subject' => 'Maths']]];
        $daysBefore = $fb->days;

        $this->store($fb)->supersedeVersion(
            self::PLAN, 'V1', $this->shift($this->today(), 10), 'closing');

        $this->assertSame($daysBefore, $fb->days, 'timetable CONTENT is immutable');
        $this->assertSame([], $fb->legacy,
            'and nothing is written to the collection the apps read — the '
            . 'projection stays responsible for materialising');
    }

    // ── PRECONDITIONS: each refuses, and writes nothing ──────────────────

    /** @dataProvider refusals */
    public function test_a_refusal_writes_nothing(callable $arrange, string $errorType,
                                                  string $messageRe): void
    {
        $fb = new FakePublishFirestore();
        [$plan, $vid, $date, $reason] = $arrange($this, $fb);

        try {
            $this->store($fb)->supersedeVersion($plan, $vid, $date, $reason);
            $this->fail("expected a {$errorType} refusal");
        } catch (Service_exception $e) {
            $this->assertSame($errorType, $e->errorType);
            $this->assertMatchesRegularExpression($messageRe, $e->getMessage());
        }

        $this->assertSame([], $fb->writes, 'a refusal must write nothing at all');
        $this->assertSame([], $fb->guards, 'and must not move the concurrency token');
    }

    public function refusals(): array
    {
        return [
            'blank reason' => [
                function (self $t, FakePublishFirestore $fb) {
                    $t->published($fb, 'V1', $t->shift($t->today(), -30));
                    return [self::PLAN, 'V1', $t->shift($t->today(), 10), '   '];
                }, 'validation', '~say why~i',
            ],
            'malformed date' => [
                function (self $t, FakePublishFirestore $fb) {
                    $t->published($fb, 'V1', $t->shift($t->today(), -30));
                    return [self::PLAN, 'V1', '31-12-2026', 'closing'];
                }, 'validation', '~real date~i',
            ],
            'impossible date' => [
                function (self $t, FakePublishFirestore $fb) {
                    $t->published($fb, 'V1', $t->shift($t->today(), -30));
                    return [self::PLAN, 'V1', '2026-02-30', 'closing'];
                }, 'validation', '~real date~i',
            ],
            'version does not exist' => [
                function (self $t, FakePublishFirestore $fb) {
                    return [self::PLAN, 'NOPE', $t->shift($t->today(), 10), 'closing'];
                }, 'not_found', '~does not exist~i',
            ],
            'not published' => [
                function (self $t, FakePublishFirestore $fb) {
                    $t->published($fb, 'V1', $t->shift($t->today(), -30));
                    $fb->versions[$t->vDoc('V1')]['status'] =
                        Timetable_plan_service::STATUS_CANDIDATE;
                    return [self::PLAN, 'V1', $t->shift($t->today(), 10), 'closing'];
                }, 'not_published', '~only a published timetable~i',
            ],
            'already bounded — the one-way rule' => [
                function (self $t, FakePublishFirestore $fb) {
                    $t->published($fb, 'V1', $t->shift($t->today(), -30),
                                              $t->shift($t->today(), 20));
                    return [self::PLAN, 'V1', $t->shift($t->today(), 40), 'again'];
                }, 'already_bounded', '~already has a last day~i',
            ],
            'ends before it starts' => [
                function (self $t, FakePublishFirestore $fb) {
                    $t->published($fb, 'V1', $t->shift($t->today(), 30));
                    return [self::PLAN, 'V1', $t->shift($t->today(), 10), 'closing'];
                }, 'validation', '~cannot end before it starts~i',
            ],
            'retroactive — the historical-safety gate' => [
                function (self $t, FakePublishFirestore $fb) {
                    $t->published($fb, 'V1', $t->shift($t->today(), -60));
                    return [self::PLAN, 'V1', $t->shift($t->today(), -1), 'closing'];
                }, 'retroactive', '~already happened~i',
            ],
        ];
    }

    /** Another tenant's version is not ours to end, even with the right ids. */
    public function test_a_foreign_tenant_version_is_refused(): void
    {
        $fb = new FakePublishFirestore();
        $this->published($fb, 'V1', $this->shift($this->today(), -30));
        $fb->versions[$this->vDoc('V1')]['schoolId'] = 'SCH_OTHER';

        $this->expectException(Service_exception::class);
        $this->expectExceptionMessageMatches('~does not exist~i');
        $this->store($fb)->supersedeVersion(
            self::PLAN, 'V1', $this->shift($this->today(), 10), 'closing');
    }

    public function test_a_foreign_session_version_is_refused(): void
    {
        $fb = new FakePublishFirestore();
        $this->published($fb, 'V1', $this->shift($this->today(), -30));
        $fb->versions[$this->vDoc('V1')]['session'] = '2099-2100';

        $this->expectException(Service_exception::class);
        $this->store($fb)->supersedeVersion(
            self::PLAN, 'V1', $this->shift($this->today(), 10), 'closing');
    }

    // ── FAIL CLOSED ──────────────────────────────────────────────────────

    public function test_an_unreadable_guard_refuses(): void
    {
        $fb = new FakePublishFirestore();
        $this->published($fb, 'V1', $this->shift($this->today(), -30));
        $fb->guardUnreadable = true;

        try {
            $this->store($fb)->supersedeVersion(
                self::PLAN, 'V1', $this->shift($this->today(), 10), 'closing');
            $this->fail('an unreadable guard must refuse');
        } catch (Service_exception $e) {
            $this->assertSame('read', $e->errorType);
        }
        $this->assertSame('', $fb->versions[$this->vDoc('V1')]['effectiveTo'],
            'the version must be left open-ended');
    }

    public function test_an_unclassified_commit_is_not_treated_as_success(): void
    {
        $fb = new class extends FakePublishFirestore {
            public function firestoreCommitBatchStatus($ops) { return ['status' => 'UNAVAILABLE']; }
        };
        $this->published($fb, 'V1', $this->shift($this->today(), -30));

        try {
            $this->store($fb)->supersedeVersion(
                self::PLAN, 'V1', $this->shift($this->today(), 10), 'closing');
            $this->fail('UNKNOWN is not success');
        } catch (Service_exception $e) {
            $this->assertSame('write', $e->errorType);
        }
    }

    // ── RETRY ────────────────────────────────────────────────────────────

    public function test_a_repeat_is_refused_rather_than_rewriting(): void
    {
        $fb = new FakePublishFirestore();
        $this->published($fb, 'V1', $this->shift($this->today(), -30));
        $first = $this->shift($this->today(), 10);

        $this->store($fb)->supersedeVersion(self::PLAN, 'V1', $first, 'closing');

        try {
            $this->store($fb)->supersedeVersion(
                self::PLAN, 'V1', $this->shift($this->today(), 40), 'changed my mind');
            $this->fail('an end date is set once');
        } catch (Service_exception $e) {
            $this->assertSame('already_bounded', $e->errorType);
        }

        $this->assertSame($first, $fb->versions[$this->vDoc('V1')]['effectiveTo'],
            'the original end date stands — there is no rollback and no re-dating');
    }

    // ── AUDIT ────────────────────────────────────────────────────────────

    /**
     * The audit entry must be ACCEPTED, not merely attempted.
     *
     * Audit_log_service::log() returns false for an unregistered action or
     * entity type and never throws, so "the code ran" proves nothing. This is
     * how the whole timetable module's audit trail came to be missing.
     */
    public function test_the_transition_is_audited_and_the_result_is_checked(): void
    {
        $fb = new FakePublishFirestore();
        $this->published($fb, 'V1', $this->shift($this->today(), -30));

        $captured = [];
        $audit = new class($captured) {
            public array $calls = [];
            public bool  $accept = true;
            public function __construct(&$sink) { $sink =& $this->calls; }
            public function log($action, $entity, $id, $before, $after, $extra = [])
            {
                $this->calls[] = compact('action', 'entity', 'id', 'before', 'after');
                return $this->accept;
            }
        };

        $store = (new Timetable_plan_store())->init($fb, self::SCHOOL, self::SESS,
            'STA0009', $audit);
        $res = $store->supersedeVersion(
            self::PLAN, 'V1', $this->shift($this->today(), 10), 'Term 1 ends');

        $this->assertTrue($res['audited'], 'the caller is told the entry was accepted');
        $this->assertCount(1, $audit->calls);

        $c = $audit->calls[0];
        $this->assertSame('status_change', $c['action'],
            'an action the audit service actually accepts — inventing a new global '
            . 'action for one caller would widen a shared vocabulary for nothing');
        $this->assertSame('timetableVersion', $c['entity']);
        $this->assertSame($this->vDoc('V1'), $c['id']);
        $this->assertSame('', $c['before']['effectiveTo'], 'before: open-ended');
        $this->assertSame($this->shift($this->today(), 10), $c['after']['effectiveTo']);
        $this->assertSame('Term 1 ends', $c['after']['supersededReason']);
        $this->assertSame('STA0009', $c['after']['supersededBy']);
        $this->assertSame('timetable_supersede', $c['after']['transition']);
    }

    public function test_a_refused_audit_entry_is_reported_not_swallowed(): void
    {
        $fb = new FakePublishFirestore();
        $this->published($fb, 'V1', $this->shift($this->today(), -30));

        $audit = new class {
            public function log($a, $e, $i, $b, $af, $x = []) { return false; }
        };
        $store = (new Timetable_plan_store())->init($fb, self::SCHOOL, self::SESS,
            'STA0009', $audit);

        $res = $store->supersedeVersion(
            self::PLAN, 'V1', $this->shift($this->today(), 10), 'closing');

        $this->assertFalse($res['audited'],
            'a refused audit entry must be visible to the caller, not discarded — '
            . 'the transition itself already committed and is not undone by it');
    }

    /**
     * `timetableVersion` and `status_change` must BOTH be accepted by the real
     * allowlists. This is the check that was claimed and not actually made.
     */
    public function test_the_real_audit_service_accepts_this_action_and_entity(): void
    {
        require_once __DIR__ . '/../../application/libraries/Audit_log_service.php';

        $this->assertContains('timetableVersion', Audit_log_service::ENTITY_TYPES,
            'the entity type must be registered, or log() silently returns false');
        $this->assertContains('status_change', Audit_log_service::ACTIONS,
            'and so must the action');
    }
}
