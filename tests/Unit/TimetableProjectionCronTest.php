<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

if (!defined('BASEPATH')) define('BASEPATH', __DIR__ . '/../../system/');
if (!defined('APPPATH'))  define('APPPATH',  __DIR__ . '/../../application/');
if (!function_exists('log_message')) { function log_message($l, $m) {} }
require_once __DIR__ . '/../../application/libraries/Timetable_plan_store.php';

/**
 * PHASE C — the T2 scheduled compatibility projection.
 *
 * The cron controller cannot be instantiated in a unit test (CI_Controller
 * needs a bootstrapped framework), so its DECISION LOGIC is exercised here
 * against the real store and resolver, and its wiring is pinned separately.
 *
 * The property under test is the one that makes a scheduler safe next to a
 * resolver: the cron must ask what is live and copy it, never work it out. Any
 * tie-breaking, precedence or date interpretation here would be a second place
 * where "which timetable is live" is decided.
 */
class FakeCronFirestore
{
    public array $versions = [];
    public array $days     = [];
    public array $legacy   = [];
    public array $plans    = [];
    public array $writes   = [];
    public bool  $versionsUnreadable = false;

    public function firestoreGetOrFail($collection, $docId)
    {
        if ($collection === 'timetablePlans') return $this->plans[$docId] ?? null;
        return $this->versions[$docId] ?? null;
    }

    public function firestoreQueryOrFail($collection, $conditions = [], $orderBy = null, $dir = 'ASC', $limit = null)
    {
        if ($collection === 'timetableVersions' && $this->versionsUnreadable) {
            throw new \RuntimeException('index missing');
        }
        $want = [];
        foreach ($conditions as $c) $want[(string) ($c[0] ?? '')] = $c[2] ?? null;

        $src = ['timetableVersionDays' => $this->days,
                'timetableVersions'    => $this->versions,
                'timetables'           => $this->legacy][$collection] ?? [];

        $out = [];
        foreach ($src as $row) {
            foreach ($want as $k => $v) {
                if (($row[$k] ?? null) !== $v) continue 2;
            }
            $out[] = $row;
        }
        return $out;
    }

    public function firestoreSet($collection, $docId, $data, $merge = true)
    {
        $target = ['timetables' => 'legacy', 'timetableVersions' => 'versions',
                   'timetableVersionDays' => 'days', 'timetablePlans' => 'plans'][$collection] ?? null;
        if ($target !== null) {
            $this->{$target}[$docId] = $merge
                ? array_merge($this->{$target}[$docId] ?? [], $data) : $data;
        }
        $this->writes[] = ['collection' => $collection, 'docId' => $docId,
                           'data' => $data, 'merge' => $merge];
        return true;
    }
}

final class TimetableProjectionCronTest extends TestCase
{
    private const SCHOOL = 'SCH_A';
    private const SESS   = '2026-2027';
    private const PLAN   = 'PLAN_A';


    private function store(FakeCronFirestore $fb): Timetable_plan_store
    {
        return (new Timetable_plan_store())->init(
            $fb, self::SCHOOL, self::SESS, 'timetable_projection_cron');
    }

    private function vDoc(string $vid): string
    {
        return self::SCHOOL . '_' . self::SESS . '_' . self::PLAN . '_' . $vid;
    }

    private function addVersion(FakeCronFirestore $fb, string $vid, string $status,
                                string $from, string $to = ''): void
    {
        $fb->versions[$this->vDoc($vid)] = [
            'schoolId' => self::SCHOOL, 'session' => self::SESS,
            'planId' => self::PLAN, 'versionId' => $vid, 'status' => $status,
            'effectiveFrom' => $from, 'effectiveTo' => $to,
            'generation' => ['runId' => 'GEN_' . $vid,
                             'state' => Timetable_plan_service::GEN_COMPLETE,
                             'daysWritten' => 1],
        ];
        $fb->days[$this->vDoc($vid) . '_Class 8th_Section A_Monday'] = [
            'schoolId' => self::SCHOOL, 'session' => self::SESS,
            'planId' => self::PLAN, 'versionId' => $vid,
            'classSection' => 'Class 8th/Section A', 'day' => 'Monday',
            'periods' => [[
                'periodNumber' => 1, 'subject' => 'Subject-' . $vid,
                'teacher' => 'T', 'teacherId' => 'STA001',
                'startTime' => '09:00AM', 'endTime' => '09:45AM',
                'room' => '', 'type' => 'class',
            ]],
            'manuallyEdited' => false,
        ];
    }

    /**
     * The cron's decision — the REAL one.
     *
     * This calls Timetable_plan_service::projectionDecision(), the same pure
     * function the controller calls. It deliberately does not reimplement the
     * branches: a CI_Controller cannot be instantiated here, and a test that
     * copies a safety rule drifts away from the rule it is supposed to defend.
     */
    private function cronDecision(Timetable_plan_store $store, string $date): array
    {
        $r = $store->resolveForDate($date);
        $d = Timetable_plan_service::projectionDecision($r);

        return [
            'action'     => $d['action'],
            'exit'       => $d['exitCode'],
            'reason'     => $d['reason'],
            'resolution' => $r,
            'planId'     => (string) ($r['version']['planId'] ?? ''),
            'versionId'  => (string) ($r['version']['versionId'] ?? ''),
        ];
    }

    // ── it materialises what the resolver says, and nothing else ────────

    public function test_it_projects_the_version_the_resolver_selected(): void
    {
        $fb = new FakeCronFirestore();
        $this->addVersion($fb, 'V1', 'published', '2026-04-01', '2026-05-31');
        $this->addVersion($fb, 'V2', 'published', '2026-06-01');
        $store = $this->store($fb);

        $d = $this->cronDecision($store, '2026-06-15');
        $this->assertSame('project', $d['action']);
        $this->assertSame('V2', $d['versionId'],
            'the cron must take the resolver\'s answer, not pick the newest itself');

        $store->projectPublishedVersion($d['planId'], $d['versionId'], true);
        $doc = array_values($fb->legacy)[0];
        $this->assertSame('Subject-V2', $doc['periods'][0]['subject']);
        $this->assertSame('V2', $doc['projectedFromVersion']);
    }

    public function test_it_projects_the_older_version_when_that_is_what_applies(): void
    {
        $fb = new FakeCronFirestore();
        $this->addVersion($fb, 'V1', 'published', '2026-04-01', '2026-05-31');
        $this->addVersion($fb, 'V2', 'published', '2026-06-01');
        $store = $this->store($fb);

        $d = $this->cronDecision($store, '2026-05-15');
        $this->assertSame('V1', $d['versionId'],
            'a future-dated version must not be materialised early; the date decides');

        $store->projectPublishedVersion($d['planId'], $d['versionId'], true);
        $this->assertSame('Subject-V1',
            array_values($fb->legacy)[0]['periods'][0]['subject']);
    }

    // ── fail closed ─────────────────────────────────────────────────────

    public function test_it_refuses_when_two_published_versions_cover_the_date(): void
    {
        $fb = new FakeCronFirestore();
        $this->addVersion($fb, 'V1', 'published', '2026-04-01');   // open-ended
        $this->addVersion($fb, 'V2', 'published', '2026-06-01');
        $store = $this->store($fb);

        $d = $this->cronDecision($store, '2026-06-15');

        $this->assertSame('refuse', $d['action']);
        $this->assertSame(2, $d['exit']);
        $this->assertSame(Timetable_plan_service::AMBIGUOUS, $d['resolution']['status'],
            'the legacy collection holds one document per section per day and cannot '
            . 'represent two timetables; guessing would show one to every teacher');
        $this->assertSame([], $fb->legacy,
            'a refusal must leave what teachers currently see untouched');
    }

    public function test_it_refuses_when_the_versions_cannot_be_read(): void
    {
        $fb = new FakeCronFirestore();
        $this->addVersion($fb, 'V1', 'published', '2026-04-01');
        $fb->versionsUnreadable = true;

        $d = $this->cronDecision($this->store($fb), '2026-06-15');

        $this->assertSame('refuse', $d['action']);
        $this->assertSame('unreadable', $d['resolution']['status'],
            'a failed read must never be reported as "no timetable"');
        $this->assertSame([], $fb->legacy);
    }

    public function test_an_invalid_date_is_refused_not_coerced(): void
    {
        $fb = new FakeCronFirestore();
        $this->addVersion($fb, 'V1', 'published', '2026-04-01');

        $d = $this->cronDecision($this->store($fb), 'not-a-date');
        $this->assertSame('refuse', $d['action']);
        $this->assertSame(Timetable_plan_service::INVALID, $d['resolution']['status']);
    }

    // ── nothing published is not an error, and not a reason to clear ────

    public function test_no_published_version_leaves_the_legacy_documents_alone(): void
    {
        $fb = new FakeCronFirestore();
        $this->addVersion($fb, 'V1', 'candidate', '2026-04-01');
        // Something is already live from the legacy era.
        $existing = self::SCHOOL . '_' . self::SESS . '_Class 8th_Section A_Monday';
        $fb->legacy[$existing] = [
            'schoolId' => self::SCHOOL, 'session' => self::SESS,
            'sectionKey' => 'Class 8th/Section A', 'day' => 'Monday',
            'periods' => [['periodNumber' => 1, 'subject' => 'Legacy']],
        ];

        $d = $this->cronDecision($this->store($fb), '2026-06-15');

        $this->assertSame('skip', $d['action']);
        $this->assertSame(0, $d['exit'], 'nothing published is not a failure');
        $this->assertSame('Legacy', $fb->legacy[$existing]['periods'][0]['subject'],
            'this cron materialises what IS live; it has no mandate to wipe a '
            . 'school\'s timetable because no canonical version covers today');
    }

    public function test_a_draft_is_never_materialised(): void
    {
        $fb = new FakeCronFirestore();
        $this->addVersion($fb, 'V1', 'draft', '2026-04-01');
        $this->addVersion($fb, 'V2', 'candidate', '2026-04-01');

        $d = $this->cronDecision($this->store($fb), '2026-06-15');
        $this->assertSame('skip', $d['action'],
            'unpublished work must never reach a teacher through the back door');
        $this->assertSame([], $fb->legacy);
    }

    // ── idempotence over repeated runs ──────────────────────────────────

    public function test_running_daily_writes_only_when_the_answer_changes(): void
    {
        $fb = new FakeCronFirestore();
        $this->addVersion($fb, 'V1', 'published', '2026-04-01', '2026-05-31');
        $this->addVersion($fb, 'V2', 'published', '2026-06-01');
        $store = $this->store($fb);

        $runs = [];
        foreach (['2026-05-30', '2026-05-31', '2026-06-01', '2026-06-02'] as $date) {
            $d = $this->cronDecision($store, $date);
            $p = $store->projectPublishedVersion($d['planId'], $d['versionId'], true);
            $runs[$date] = ['version' => $d['versionId'], 'written' => $p['written']];
        }

        $this->assertSame(1, $runs['2026-05-30']['written'], 'first run materialises V1');
        $this->assertSame(0, $runs['2026-05-31']['written'],
            'a day where nothing changed must write nothing');
        $this->assertSame('V2', $runs['2026-06-01']['version']);
        $this->assertSame(1, $runs['2026-06-01']['written'],
            'the changeover day must materialise the new version');
        $this->assertSame(0, $runs['2026-06-02']['written'],
            'and the day after must go quiet again');
    }

    public function test_the_changeover_needs_no_republish_or_flag_flip(): void
    {
        $fb = new FakeCronFirestore();
        $this->addVersion($fb, 'V1', 'published', '2026-04-01', '2026-05-31');
        $this->addVersion($fb, 'V2', 'published', '2026-06-01');
        $store = $this->store($fb);

        $before = $fb->versions[$this->vDoc('V2')];
        $d = $this->cronDecision($store, '2026-06-01');
        $store->projectPublishedVersion($d['planId'], $d['versionId'], true);

        $this->assertSame($before, $fb->versions[$this->vDoc('V2')],
            'activation is a resolver concern: becoming effective must not require '
            . 'the cron to mutate the version, or the scheduler owns the lifecycle');
        $this->assertSame($fb->versions[$this->vDoc('V1')]['status'], 'published',
            'and the version that just stopped applying must not be rewritten either');
    }

    // ── the cron is not a second rule engine ────────────────────────────

    public function test_the_cron_contains_no_timetable_selection_logic(): void
    {
        $src = (string) file_get_contents(
            __DIR__ . '/../../application/controllers/Timetable_projection_cron.php');
        $code = (string) preg_replace('~/\*.*?\*/~s', '', $src);
        $code = (string) preg_replace('~^\s*//.*$~m', '', $code);

        // It may READ these to report, but it must not compare or sort by them.
        foreach (['usort', 'uasort', 'max(', 'min(',
                  "effectiveFrom'] >", "effectiveFrom'] <",
                  'STATUS_PUBLISHED'] as $ruleLogic) {
            $this->assertStringNotContainsString($ruleLogic, $code,
                "the cron must not contain '{$ruleLogic}': deciding which version "
                . 'is live is the resolver\'s job, and a second implementation of '
                . 'it is exactly the competing-authority failure being avoided');
        }

        $this->assertStringContainsString('resolveForDate(', $code,
            'it must ask the canonical resolver');
    }

    public function test_the_cron_is_cli_only_and_lifecycle_guarded(): void
    {
        $src = (string) file_get_contents(
            __DIR__ . '/../../application/controllers/Timetable_projection_cron.php');

        $this->assertStringContainsString('is_cli_request', $src,
            'a job that rewrites what every teacher sees must not be reachable '
            . 'over HTTP');
        $this->assertStringContainsString('lifecycle_guard_or_skip', $src,
            'a suspended or closed tenant must not have its timetable rewritten');
        $this->assertStringContainsString('JOB_OPERATIONAL', $src);
    }

    public function test_writes_require_an_explicit_apply_flag(): void
    {
        $src = (string) file_get_contents(
            __DIR__ . '/../../application/controllers/Timetable_projection_cron.php');

        $this->assertMatchesRegularExpression("~getenv\('APPLY'\)~", $src,
            'dry run by default, following Timetables_reconcile — the only other '
            . 'tool that writes this collection');
        $this->assertMatchesRegularExpression('~\$this->apply\s*=\s*.*===\s*\'1\'~', $src,
            'anything other than an explicit 1 must not enable writes');
    }

    /** The projection itself must still refuse anything unpublished. */
    public function test_the_projection_gate_holds_even_if_the_cron_asked_wrongly(): void
    {
        $fb = new FakeCronFirestore();
        $this->addVersion($fb, 'V1', 'candidate', '2026-04-01');

        $this->expectException(Service_exception::class);
        $this->expectExceptionMessageMatches('~not been published~i');
        $this->store($fb)->projectPublishedVersion(self::PLAN, 'V1', true);
    }
    /**
     * The controller must not re-decide. If it stops calling the pure decision
     * function, every test above silently stops testing the real cron.
     */
    public function test_the_controller_delegates_the_decision(): void
    {
        $src = (string) file_get_contents(
            __DIR__ . "/../../application/controllers/Timetable_projection_cron.php");

        $this->assertStringContainsString(
            "Timetable_plan_service::projectionDecision(", $src,
            "the cron must delegate to the pure decision function, or its safety "
            . "rules exist only in a controller no test can execute");
        $this->assertStringNotContainsString("=== Timetable_plan_service::NONE", $src,
            "re-testing the resolution status inline would be a second copy of "
            . "the decision");

        // The controller BODY cannot be executed here — CI_Controller needs a
        // bootstrapped framework — and a mutation that neutered this guard
        // survived the behavioural suite for precisely that reason. So the guard
        // and its ORDERING are pinned structurally instead: it must refuse
        // before control can reach the projection call.
        $guard   = strpos($src, '$decision[\'action\'] !== \'project\'');
        $project = strpos($src, 'projectPublishedVersion(');

        $this->assertNotFalse($guard,
            'the cron must refuse any action that is not "project"; without this '
            . 'an ambiguous or unreadable resolution would be materialised and '
            . 'every teacher would be shown a timetable nobody chose');
        $this->assertNotFalse($project, 'the projection call is gone');
        $this->assertLessThan($project, $guard,
            'the refusal must come BEFORE the projection call, or it refuses only '
            . 'after the legacy documents have already been rewritten');
    }

    public function test_the_decision_function_fails_closed_on_junk(): void
    {
        foreach ([null, [], "nonsense", ["status" => "weird"], 42] as $junk) {
            $d = Timetable_plan_service::projectionDecision($junk);
            $this->assertSame("refuse", $d["action"],
                "an unrecognised resolution must never be treated as safe to project");
            $this->assertSame(2, $d["exitCode"]);
        }
    }
}