<?php

use PHPUnit\Framework\TestCase;

/**
 * A published timetable must be able to reach the Teacher and Parent apps from
 * the school's own screen.
 *
 * ── THE GAP ─────────────────────────────────────────────────────────────
 *
 * The office reads the canonical versions; the apps read the legacy
 * `timetables` collection. publishCandidate() bridges the two, but ONLY when
 * the version is in force on the day it is published. Publish one that starts
 * later and the bridging falls to Timetable_projection_cron — which nothing in
 * this repository schedules.
 *
 * Seen on a real tenant on 2026-09-12: a timetable published on the 12th to
 * start on the 13th, with the apps still showing a copy that matched NO
 * published version (Class 8th absent entirely, and 18 of 18 day-documents
 * differing from the version in force). No error was raised anywhere. The only
 * remedy the screen offered was a shell command carrying a tenant code, which a
 * school office cannot run and should not have to read.
 *
 * ── WHAT THE FIX IS, AND IS NOT ─────────────────────────────────────────
 *
 * It is a button: a person decides, as everywhere else in this lifecycle. It is
 * NOT a scheduler (nothing runs on a timer), and it is NOT a change to
 * visibility(), which stays a dry run — reading whether teachers see the
 * current timetable and changing what they see are different authorities.
 */
final class TimetableReachesTheAppsTest extends TestCase
{
    private static string $ctrl = '';
    private static string $view = '';

    public static function setUpBeforeClass(): void
    {
        self::$ctrl = (string) file_get_contents(
            __DIR__ . '/../../application/controllers/Timetable_lifecycle.php');
        self::$view = (string) file_get_contents(
            __DIR__ . '/../../application/views/timetable/lifecycle.php');
    }

    private function method(string $name): string
    {
        $i = strpos(self::$ctrl, "public function {$name}(");
        $this->assertNotFalse($i, "{$name}() is gone");
        $next = strpos(self::$ctrl, "\n    public function ", $i + 10);
        return substr(self::$ctrl, $i, ($next !== false ? $next - $i : 9000));
    }

    /** The same method with comments removed, for order-sensitive checks. */
    private function codeOf(string $name): string
    {
        $code = '';
        foreach (token_get_all('<?php ' . $this->method($name)) as $t) {
            if (is_array($t)) {
                if ($t[0] === T_COMMENT || $t[0] === T_DOC_COMMENT) { continue; }
                $code .= $t[1];
            } else {
                $code .= $t;
            }
        }
        return $code;
    }

    // ── the action exists, and costs what it should ─────────────────────

    public function test_sending_to_the_apps_needs_the_same_authority_as_publishing(): void
    {
        $body = $this->method('apply_projection');

        $this->assertStringContainsString(
            "require_permission('Academic', 'timetable_projection_apply', 'manage')", $body,
            'sending a timetable to every teacher and parent must cost `manage`, '
            . 'the grade publishing costs — it decides what the whole school opens '
            . 'tomorrow and the apps hold no history to undo it with');
    }

    /**
     * The legacy collection is keyed by weekday with NO date. It can only ever
     * represent the timetable in force now, so applying any other day's would
     * either show a timetable early or roll the school back — and neither is
     * expressible as an undo. Same rule Timetable_projection_cron enforces.
     */
    public function test_only_todays_timetable_can_be_sent(): void
    {
        $body = $this->method('apply_projection');

        $this->assertStringContainsString("\$today = date('Y-m-d')", $body,
            'today is not derived from the server clock');
        $this->assertMatchesRegularExpression('~\$asked\s*!==\s*\$today~', $body,
            'the endpoint does not compare the requested date against the server\'s '
            . 'own today, so a screen left open overnight could send yesterday\'s '
            . 'timetable to every app');
        $this->assertMatchesRegularExpression(
            '~\$asked\s*!==\s*\$today\s*\)\s*\{[^}]*json_error~s', $body,
            'a mismatched date does not REFUSE — it must return before any write');
    }

    /** "No timetable applies" and "we could not tell" are both reasons not to write. */
    public function test_an_unresolved_date_writes_nothing(): void
    {
        $body = $this->method('apply_projection');

        $this->assertMatchesRegularExpression(
            '~\$status\s*!==\s*Timetable_plan_service::RESOLVED[^}]*json_error~s', $body,
            'the endpoint projects without a single resolved version, so it would '
            . 'write on an absence it never proved');

        // The refusal must come BEFORE the write, not merely exist somewhere.
        // Compared on comment-stripped CODE and on the whole guard expression:
        // the word RESOLVED alone appears in the prose above this method, so a
        // comment could otherwise satisfy the ordering while the guard sat
        // after the write.
        $code  = $this->codeOf('apply_projection');
        $guard = strpos($code, '$status !== Timetable_plan_service::RESOLVED');
        $write = strpos($code, 'projectPublishedVersion');
        $this->assertNotFalse($guard, 'the resolve guard expression is gone');
        $this->assertNotFalse($write, 'nothing projects any more');
        $this->assertLessThan($write, $guard,
            'the projection write happens before the resolve guard, which makes '
            . 'the guard a report rather than a guard');
    }

    /** The dry run stays a dry run. */
    public function test_the_visibility_check_still_applies_nothing(): void
    {
        $body = $this->method('visibility');

        $this->assertStringNotContainsString('projectPublishedVersion($planId, $out[\'versionId\'], true)',
            $body, 'visibility() now APPLIES — one endpoint holding both authorities '
            . 'means any caller of the cheap read can perform the costly write');
        $this->assertStringContainsString("require_permission('Academic', 'timetable_projection_preview', 'view')",
            $body, 'visibility() no longer reads at `view`');
    }

    // ── what the school is told ─────────────────────────────────────────

    /**
     * The banner used to lead with
     *   APPLY=1 SCHOOL_ID=SCH_… SESSION_YEAR=… php index.php …
     * A school office cannot run that, and should not have to read a tenant code
     * to learn its own timetable is stale (operator feedback, 2026-09-12).
     */
    public function test_the_school_is_not_shown_a_shell_command_as_its_remedy(): void
    {
        $v = self::$view;

        $i = strpos($v, 'function loadVisibility');
        $this->assertNotFalse($i);
        $banner = substr($v, $i, 6000);

        $this->assertStringContainsString('<details', $banner,
            'the command is still shown inline rather than tucked behind a '
            . 'disclosure for whoever runs the server');
        $this->assertMatchesRegularExpression('#d\.command[\s\S]{0,400}<details#', $banner,
            'the command is not inside the disclosure');

        // And the school gets an action it can actually take.
        $this->assertStringContainsString('Send to teachers and parents now', $banner,
            'the banner states the problem and offers no way to fix it');
    }

    /**
     * The old text said the apps' copy "is refreshed by a scheduled task".
     * Nothing in this repository schedules that task. Telling a school its
     * timetable will be fixed automatically, when it will not, is worse than
     * telling it nothing.
     */
    public function test_the_banner_does_not_promise_a_scheduler_that_does_not_exist(): void
    {
        $this->assertStringNotContainsString('refreshed by a', self::$view,
            'the banner still tells the school a scheduled task will update the '
            . 'apps; no scheduler exists here, so the timetable would silently '
            . 'never arrive');
    }

    /** A button that answers 403 teaches the office that the screen lies. */
    public function test_the_send_button_is_shown_only_to_someone_who_may_send(): void
    {
        $i = strpos(self::$view, 'function loadVisibility');
        $banner = substr(self::$view, $i, 6000);

        $this->assertMatchesRegularExpression('#CAN_MANAGE[\s\S]{0,300}tt_send_apps#', $banner,
            'the send button is offered without checking CAN_MANAGE');
    }

    /** Confirmed before it happens, and re-checked from the server after. */
    public function test_sending_is_confirmed_and_then_verified(): void
    {
        $i = strpos(self::$view, 'function sendToApps');
        $this->assertNotFalse($i, 'sendToApps() is gone');
        $fn = substr(self::$view, $i, 2000);

        $this->assertStringContainsString('confirmModal(', $fn,
            'sending happens on a single click with no confirmation, and there is '
            . 'no undo');
        $this->assertStringNotContainsString('window.confirm', $fn,
            "the browser's own dialog names the developer's machine "
            . '("localhost says") to a school office');
        $this->assertStringContainsString('loadVisibility()', $fn,
            'the banner is not re-checked against the server after the write, so '
            . 'it would show what we hoped happened rather than what did');
    }

    // ── re-audit 2026-09-12: the rest of the checklist ──────────────────

    /**
     * TENANT AND SESSION. Never from the request. A send that could be pointed
     * at another school would be the worst defect available here: it would put
     * one school's timetable in front of another school's parents.
     */
    public function test_the_tenant_and_session_come_from_the_server_context(): void
    {
        $code = $this->codeOf('apply_projection');

        // The store is built by the controller's own store(), which takes
        // school_id and session_year from MY_Controller. Nothing here may
        // reach for a school or session out of the request.
        $this->assertStringContainsString('$this->store()', $code,
            'the endpoint builds its own store instead of using the '
            . 'tenant-scoped one');
        foreach (['school_id', 'schoolId', 'session', 'session_year'] as $field) {
            $this->assertStringNotContainsString("input->post('{$field}')", $code,
                "the endpoint accepts {$field} from the request, so a crafted "
                . "call could act on another school's or session's timetable");
            $this->assertStringNotContainsString("input->get('{$field}')", $code);
        }

        // `date` is the ONLY thing it reads from the request, and only to
        // compare against the server's today.
        $this->assertSame(1, preg_match_all("~input->post\(~", $code),
            'the endpoint reads more than one value from the request; the only '
            . 'one it may read is the date it refuses unless it equals today');
    }

    /**
     * UNPUBLISHED WORK CANNOT BE SENT. Two independent reasons, and the test
     * pins both because either alone would be a single point of failure.
     */
    public function test_a_draft_or_superseded_timetable_cannot_be_sent(): void
    {
        $store = (string) file_get_contents(
            __DIR__ . '/../../application/libraries/Timetable_plan_store.php');

        // 1. The projection itself refuses anything not published.
        $this->assertMatchesRegularExpression(
            "~!==\s*Timetable_plan_service::STATUS_PUBLISHED\s*\)\s*\{[\s\S]{0,400}?"
            . "throw new Service_exception~", $store,
            'projectPublishedVersion no longer refuses an unpublished version, so '
            . 'a draft could be pushed to every teacher and parent');

        // 2. And the endpoint never names a version — it takes the resolver's,
        //    and the resolver only ever returns a published one in force.
        $code = $this->codeOf('apply_projection');
        $this->assertStringContainsString('resolveForDate($today)', $code,
            'the endpoint does not ask the resolver, so it could be handed a '
            . 'version that is not in force');
    }

    /**
     * DUPLICATE, CONCURRENT AND RETRIED SENDS. All three are the same property:
     * the write is a full materialisation of whatever the resolver says is in
     * force, and the resolver admits exactly one version per date. So two
     * admins pressing Send together write the same documents, and a second
     * press writes nothing at all.
     *
     * Pinned on plan(), which is where idempotency actually lives: a document
     * whose owned fields and provenance already match is reported unchanged and
     * is NOT rewritten — so a repeat run does not even bump the version counter.
     */
    public function test_sending_twice_writes_nothing_the_second_time(): void
    {
        require_once __DIR__ . '/../../application/libraries/Timetable_projection_service.php';

        $doc = [
            'schoolId' => 'SCH_X', 'session' => '2026-27', 'day' => 'Monday',
            'periods' => [['periodNumber' => 1, 'subject' => 'Mathematics']],
            'projectedFromPlan' => 'REGULAR', 'projectedFromVersion' => 'V1',
            'projectedAt' => '2026-09-13T00:20:00+00:00', 'projectedByUid' => 'cron',
            'version' => 3,
        ];

        $first = Timetable_projection_service::plan(['d' => $doc], []);
        $this->assertCount(1, $first['writes'], 'the first send writes the document');

        // What the collection holds after that first send.
        $after  = $first['writes']['d'];
        $second = Timetable_projection_service::plan(['d' => $doc], ['d' => $after]);

        $this->assertSame([], $second['writes'],
            'a second send rewrites documents that already match, so two admins '
            . 'pressing the button together — or one pressing it twice — would '
            . 'churn every teacher-visible document and drift the version counter');
        $this->assertSame(['d'], $second['unchanged']);
    }

    /**
     * PARTIAL FAILURE. Each document is written independently, so a run that
     * stops halfway leaves some classes on the new timetable and some on the
     * old. That is recoverable — running again finishes the job, because the
     * documents already written are reported unchanged — but ONLY if the
     * operator is told, in those terms, that it happened.
     */
    public function test_a_half_finished_send_says_so_and_says_it_is_resumable(): void
    {
        $store = (string) file_get_contents(
            __DIR__ . '/../../application/libraries/Timetable_plan_store.php');

        $this->assertStringContainsString('it stopped', $store,
            'a projection that fails part-way does not report how far it got, so '
            . 'the school cannot tell which classes were updated');
        $this->assertStringContainsString('may still be showing the previous timetable', $store,
            'a partial failure does not say that some classes are still on the '
            . 'old timetable — which is the whole consequence');
        $this->assertStringContainsString('Run it again to finish', $store,
            'the operator is not told that running again is safe and completes it');
    }

    /**
     * SUCCESS IS VERIFIED, NOT ASSUMED. After the write the screen re-asks the
     * server, and the server's answer is an equivalence comparison of every
     * class page — not "the command returned 200".
     */
    public function test_success_is_confirmed_by_comparison_not_by_the_write_returning(): void
    {
        $i  = strpos(self::$view, 'function sendToApps');
        $fn = substr(self::$view, $i, 2000);

        $this->assertStringContainsString('loadVisibility()', $fn,
            'the panel does not re-check after the write');

        // …and what it re-checks with is the equivalence verdict.
        $vis = $this->method('visibility');
        $this->assertStringContainsString("\$out['current']         = (bool) (\$dx['inSync']", $vis,
            "visibility() no longer takes `current` from the diagnosis, so the "
            . 'screen could report "teachers are seeing this timetable" from a '
            . 'count that happens to be zero rather than from a comparison');
    }

    /** The server's date, not the browser's. */
    public function test_the_screen_uses_the_servers_today(): void
    {
        $this->assertMatchesRegularExpression(
            "~var TODAY\s*=\s*'<\?=\s*date\('Y-m-d'\)~", self::$view,
            'TODAY comes from the browser clock, so a device in another timezone '
            . '(or with a wrong clock) would offer a send the server refuses, or '
            . 'hide the button on the one day it works');
    }
}
