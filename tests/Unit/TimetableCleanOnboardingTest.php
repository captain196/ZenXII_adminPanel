<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

if (!defined('BASEPATH')) define('BASEPATH', __DIR__ . '/../../system/');
if (!defined('APPPATH'))  define('APPPATH',  __DIR__ . '/../../application/');
if (!function_exists('log_message')) { function log_message($l, $m) {} }
require_once __DIR__ . '/../../application/libraries/Timetable_plan_store.php';
require_once __DIR__ . '/../Support/FirestoreBatchPreconditionTrait.php';
require_once __DIR__ . '/../../application/libraries/Timetable_service.php';

/**
 * THE ACCEPTANCE TEST: A BRAND-NEW SCHOOL.
 *
 * A school joins ZenX today. It has no legacy timetable documents, no history,
 * nothing inherited. It configures its academic structure and must then be able
 * to build, check, publish and deliver a timetable — with no legacy setup, no
 * feature flag, and no manual cutover.
 *
 * This file exists because every other test starts from data that already
 * exists. The failure mode it guards against is an architecture that works
 * beautifully for a school someone has already migrated, and quietly requires a
 * migration step for one that has nothing to migrate.
 */
class FakeNewSchoolFirestore
{
    use FirestoreBatchPreconditionTrait;

    /** TT-C8 publish concurrency token. */
    public array $guards   = [];

    public array $plans    = [];
    public array $versions = [];
    public array $days     = [];
    public array $legacy   = [];      // starts EMPTY — this school has no history
    public array $settings = [];
    public array $writes   = [];

    public function firestoreGetOrFail($collection, $docId)
    {
        if ($collection === 'timetablePublishGuards') {
            return $this->publishGuardDoc($docId, $this->guards);
        }
        if ($collection === 'timetablePlans')    { return $this->plans[$docId]    ?? null; }
        if ($collection === 'timetableSettings') { return $this->settings[$docId] ?? null; }
        return $this->versions[$docId] ?? null;
    }

    public function firestoreQueryOrFail($collection, $conditions = [], $orderBy = null, $dir = 'ASC', $limit = null)
    {
        $want = [];
        foreach ($conditions as $c) { $want[(string) ($c[0] ?? '')] = $c[2] ?? null; }

        $src = ['timetableVersionDays' => $this->days,
                'timetableVersions'    => $this->versions,
                'timetablePlans'       => $this->plans,
                'timetables'           => $this->legacy][$collection] ?? [];

        $out = [];
        foreach ($src as $row) {
            foreach ($want as $k => $v) {
                if (($row[$k] ?? null) !== $v) { continue 2; }
            }
            $out[] = $row;
        }
        return $out;
    }

    public function firestoreSet($collection, $docId, $data, $merge = true)
    {
        $t = ['timetableVersions' => 'versions', 'timetableVersionDays' => 'days',
              'timetablePlans' => 'plans', 'timetables' => 'legacy'][$collection] ?? null;
        if ($t !== null) {
            $this->{$t}[$docId] = $merge ? array_merge($this->{$t}[$docId] ?? [], $data) : $data;
        }
        $this->writes[] = ['collection' => $collection, 'docId' => $docId,
                           'data' => $data, 'merge' => $merge];
        return true;
    }
    /**
     * The interactive editor commits conditionally, so this stand-in needs the
     * primitive. It applies the write and reports success WITHOUT evaluating
     * the precondition: nothing here is testing concurrency, and a stand-in
     * that pretended to enforce it would give false confidence.
     * TimetableDayConcurrencyTest carries one that honours it properly.
     */
}

final class TimetableCleanOnboardingTest extends TestCase
{
    private const SCHOOL = 'SCH_BRAND_NEW';
    private const SESS   = '2027-2028';
    private const SEC    = 'Class 6th/Section A';

    private FakeNewSchoolFirestore $fb;
    private Timetable_plan_store $store;

    protected function setUp(): void
    {
        $this->fb = new FakeNewSchoolFirestore();
        // The only prerequisite: the school configured its period scheduling.
        $this->fb->settings[self::SCHOOL . '_' . self::SESS] = ['periodsPerDay' => 2];
        $this->store = (new Timetable_plan_store())->init(
            $this->fb, self::SCHOOL, self::SESS, 'STA_NEW_1');
    }

    private function planId(): string
    {
        return (string) $this->store->ensureRegularPlan()['planId'];
    }

    private function seed(string $plan, string $vid): void
    {
        $this->store->createDraftVersion([
            'planId' => $plan, 'versionId' => $vid,
            'effectiveFrom' => '2027-04-01', 'effectiveTo' => '',
        ]);
        $run = $this->store->beginGenerationRun($plan, $vid, ['kind' => 'onboarding']);
        $this->store->saveCandidateDay($plan, $vid, self::SEC, 'Monday', [
            ['periodNumber' => 1, 'subject' => 'Mathematics', 'teacher' => 'A Rao',
             'teacherId' => 'STA_N1', 'startTime' => '09:00AM', 'endTime' => '09:45AM',
             'room' => '', 'type' => 'class'],
            ['periodNumber' => 2, 'subject' => 'English', 'teacher' => 'B Das',
             'teacherId' => 'STA_N2', 'startTime' => '09:45AM', 'endTime' => '10:30AM',
             'room' => '', 'type' => 'class'],
        ], false, $run);
        $this->store->completeGenerationRun($plan, $vid, $run, 1);
    }

    // ── the acceptance run ──────────────────────────────────────────────

    public function test_a_brand_new_school_can_go_from_nothing_to_a_live_timetable(): void
    {
        $this->assertSame([], $this->fb->legacy, 'premise: no timetable history at all');
        $this->assertSame([], $this->fb->versions);

        // 1. the plan appears on first use — nobody has to create one
        $plan = $this->planId();
        $this->assertSame('REGULAR', $plan);

        // 2. before anything is published, nothing applies — and that is stated,
        //    not guessed at
        $before = $this->store->resolveForDate('2027-05-01');
        $this->assertSame(Timetable_plan_service::NONE, $before['status']);

        // 3. build, check, publish
        $this->seed($plan, 'V1');
        $verdict = $this->store->validateCandidate($plan, 'V1');
        $this->assertTrue($verdict['publishable']);

        $res = $this->store->publishCandidate($plan, 'V1', '2020-01-01');

        // 4. it resolves
        $after = $this->store->resolveForDate(date('Y-m-d'));
        $this->assertSame(Timetable_plan_service::RESOLVED, $after['status']);
        $this->assertSame('V1', $after['version']['versionId']);

        // 5. AND TEACHERS CAN SEE IT — with no cron scheduled, no flag enabled,
        //    and no legacy setup. This is the whole point.
        $this->assertTrue($res['projection']['applied'],
            'a school that joined this morning must be able to publish a timetable '
            . 'and have teachers see it, without anyone enabling anything');
        $this->assertNotEmpty($this->fb->legacy);

        $doc = array_values($this->fb->legacy)[0];
        $this->assertSame('Class 6th/Section A', $doc['sectionKey']);
        $this->assertSame('Mathematics', $doc['periods'][0]['subject']);
        $this->assertSame('STA_N1', $doc['periods'][0]['teacherId']);
    }

    /** No flag, anywhere, gates a new school's timetable. */
    public function test_no_feature_flag_stands_between_a_new_school_and_a_timetable(): void
    {
        $this->assertFileDoesNotExist(
            __DIR__ . '/../../application/config/timetable_switchover_flags.php',
            'a per-school cutover flag would mean a newly onboarded school has to '
            . 'have its timetable switched on before it works — and "School A on '
            . 'the old system, School B on the new" is a second source of truth '
            . 'spread across tenants');

        foreach (['Timetable_plan_store.php', 'Timetable_service.php'] as $lib) {
            $src = (string) file_get_contents(__DIR__ . '/../../application/libraries/' . $lib);
            $this->assertStringNotContainsString('CANONICAL_IS_AUTHORITATIVE', $src,
                "{$lib} must not gate the timetable on a per-school switch");
        }
    }

    /** And the legacy paths cannot be reached at all — by any school. */
    public function test_a_new_school_cannot_reach_the_legacy_writers(): void
    {
        $svc = new Timetable_service();
        foreach (['schoolId' => self::SCHOOL, 'session' => self::SESS,
                  'ready' => true] as $p => $v) {
            $r = new ReflectionProperty(Timetable_service::class, $p);
            $r->setAccessible(true);
            $r->setValue($svc, $v);
        }

        foreach ([
            'a single period' => fn() => $svc->savePeriod('Class 6', 'A', 'Monday', 0, 'Maths', 'T1', 'A'),
            'a whole week'    => fn() => $svc->saveSectionTimetable('Class 6', 'A', '[]'),
        ] as $what => $attempt) {
            try {
                $attempt();
                $this->fail("the legacy editor for {$what} must be retired");
            } catch (Service_exception $e) {
                $this->assertMatchesRegularExpression('~Master Timetable~', $e->getMessage(),
                    'the refusal must name where the work moved to');
            }
        }
    }

    /**
     * The one prerequisite a new school genuinely has. This is a configuration
     * step, not a legacy dependency — and the refusal says which step.
     */
    public function test_the_only_prerequisite_is_stated_plainly(): void
    {
        $this->fb->settings = [];   // period scheduling not configured yet
        $plan = $this->planId();

        $this->store->createDraftVersion([
            'planId' => $plan, 'versionId' => 'V1',
            'effectiveFrom' => '2027-04-01', 'effectiveTo' => '',
        ]);

        try {
            $this->store->saveCandidatePeriod($plan, 'V1', self::SEC, 'Monday', 0,
                ['subject' => 'Mathematics', 'teacherId' => 'STA_N1']);
            $this->fail('an unknown day length must not be guessed at');
        } catch (Service_exception $e) {
            $this->assertMatchesRegularExpression('~periods per day~i', $e->getMessage(),
                'a new school must be told exactly which setup step is missing');
        }
    }

    /** A new school inherits no legacy documents, and creates none. */
    public function test_nothing_creates_legacy_documents_except_publishing(): void
    {
        $plan = $this->planId();
        $this->seed($plan, 'V1');
        $this->store->saveCandidatePeriod($plan, 'V1', self::SEC, 'Monday', 0,
            ['subject' => 'Science', 'teacherId' => 'STA_N3', 'teacher' => 'C Roy']);
        $this->store->validateCandidate($plan, 'V1');

        $this->assertSame([], $this->fb->legacy,
            'building and editing must never touch the collection teachers read');

        $this->store->publishCandidate($plan, 'V1', '2020-01-01');
        $this->assertNotEmpty($this->fb->legacy,
            'publishing is the one moment it becomes visible');
    }
}
