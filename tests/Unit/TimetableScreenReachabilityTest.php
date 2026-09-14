<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * THE MASTER TIMETABLE SCREEN — reachability and fail-closed rendering.
 *
 * Two failure modes in this codebase have bitten before and are both cheap to
 * pin, so they are pinned here rather than found in a browser:
 *
 *   1. A screen that exists but nobody can get to. A view, a route and a menu
 *      entry are three separate things, and shipping two of the three produces
 *      a feature that is "done" and unreachable.
 *
 *   2. Phantom success. fetch() does not reject on 403 or 500, so panel JS that
 *      checks neither the HTTP status nor the payload's own status field draws
 *      a denied action as if it had worked. The server is the source of truth.
 */
final class TimetableScreenReachabilityTest extends TestCase
{
    private function view(): string
    {
        return (string) file_get_contents(
            __DIR__ . '/../../application/views/timetable/lifecycle.php');
    }

    private function file(string $rel): string
    {
        return (string) file_get_contents(__DIR__ . '/../../' . $rel);
    }

    // ── menu + route + screen ───────────────────────────────────────────

    public function test_the_screen_exists(): void
    {
        $this->assertFileExists(
            __DIR__ . '/../../application/views/timetable/lifecycle.php');
    }

    public function test_the_screen_is_routed(): void
    {
        $this->assertMatchesRegularExpression(
            "~\\\$route\\['timetable'\\]\s*=\s*'Timetable_lifecycle/index'~",
            $this->file('application/config/routes.php'),
            'without the route, the menu entry 404s');
    }

    /**
     * The panel is rendered by Academic Planner's tab, not by a page of its own.
     * The old URL redirects there rather than rendering a second copy — two
     * screens showing one timetable is how they drift apart.
     */
    public function test_the_panel_is_rendered_by_the_academic_tab(): void
    {
        $ctrl = $this->file('application/controllers/Timetable_lifecycle.php');
        $i    = strpos($ctrl, 'public function index(');
        $this->assertNotFalse($i, 'the entry action is gone');
        $body = substr($ctrl, $i, 900);

        $this->assertStringContainsString("redirect(base_url('academic#timetable'))", $body,
            'the old URL must land on the tab');
        $this->assertStringNotContainsString("load->view('timetable/lifecycle'", $body,
            'rendering it here as well would give one timetable two screens');

        $acad = $this->file('application/views/academic/index.php');
        $this->assertStringContainsString("load->view('timetable/lifecycle')", $acad,
            'the tab is where the panel actually renders');
    }

    /**
     * The timetable is reached through Academic Planner, which already sits
     * behind the Class Management feature and the Academic permission — so it
     * inherits both rather than needing its own gate.
     */
    public function test_it_is_reached_through_academic_planner(): void
    {
        $header = $this->file('application/views/include/header.php');

        $i = strpos($header, "base_url('academic')");
        $this->assertNotFalse($i, 'Academic Planner is gone from the menu');

        $above = substr($header, max(0, $i - 400), min($i, 400));
        $this->assertStringContainsString("in_array('Class Management', \$school_features)", $above,
            'it must not appear for schools without Class Management');
        $this->assertStringContainsString("\$can('Academic')", $above);

        $this->assertStringNotContainsString("base_url('timetable')", $header,
            'a separate entry would be a second front door to one room');
    }

    // ── fail closed ─────────────────────────────────────────────────────

    public function test_the_page_checks_both_http_status_and_payload_status(): void
    {
        $js = $this->view();

        $this->assertStringContainsString('!r.ok', $js,
            'fetch() does not reject on 403 or 500; without this a denied action '
            . 'renders as success');
        $this->assertStringContainsString("d.status === 'error'", $js,
            'an endpoint can answer 200 with {status:"error"}, and that must not '
            . 'be drawn as if it worked — both signals are required');
    }

    public function test_every_request_goes_through_the_fail_closed_helper(): void
    {
        $js = $this->view();

        // Comments are stripped first: the docblock explaining why fetch() is
        // unsafe mentions it by name, and counting that as a call made this
        // assertion fail for the wrong reason.
        $code = (string) preg_replace('~/\*.*?\*/~s', '', $js);
        $code = (string) preg_replace('~^\s*//.*$~m', '', $code);

        // One fetch, inside the helper. A second raw fetch would bypass the
        // status checks and the CSRF handling in one go.
        $this->assertSame(1, substr_count($code, 'fetch('),
            'every request must go through the single checked helper');
    }

    public function test_write_requests_carry_the_csrf_token(): void
    {
        $js = $this->view();

        $this->assertStringContainsString('get_csrf_token_name()', $js);
        $this->assertStringContainsString('get_csrf_hash()', $js);
        $this->assertStringContainsString('params[CSRF_NAME] = CSRF_TOKEN', $js,
            'these endpoints are deliberately NOT csrf-exempt, so a POST without '
            . 'the token is rejected with a blank 403 and no console error');
        $this->assertStringContainsString('d.csrf_token', $js,
            'the rotated token must be picked up, or the second write of a '
            . 'session fails');
    }

    public function test_server_values_are_escaped_before_being_rendered(): void
    {
        $js = $this->view();

        $this->assertStringContainsString('function (s)', $js);
        $this->assertMatchesRegularExpression('~replace\(/\[&<>"\x27\]/g~', $js,
            'version ids, explanations and violation messages come back from the '
            . 'server and are written into innerHTML; unescaped they are an '
            . 'injection point');

        // A spot-check is not enough. Removing ONE esc() call survived it,
        // because the same expression still appeared elsewhere in the file. So
        // this scans for the SHAPE of an unescaped interpolation instead:
        //
        //     '…' + something.property + '…'
        //
        // An escaped one reads `+ esc(x.y) +`, where the token after the plus is
        // `esc(` and not a dotted path, so it does not match. Anything this does
        // match is a server value being concatenated into markup raw.
        $code = (string) preg_replace('~/\*.*?\*/~s', '', $js);
        $code = (string) preg_replace('~^\s*//.*$~m', '', $code);

        // Scoped to MARKUP: only flag an interpolation sitting between string
        // literals where one contains a tag. Values concatenated into a
        // window.alert() are not rendered as HTML, and escaping those would show
        // the operator &amp; instead of &.
        preg_match_all(
            '~(\'[^\'\n]*\')\s*\+\s*([a-z_]\w*(?:\.\w+)+)\s*\+\s*(\'[^\'\n]*\')~i',
            $code, $m, PREG_SET_ORDER);

        $raw = [];
        foreach ($m as $hit) {
            if (strpos($hit[1], '<') !== false || strpos($hit[3], '<') !== false) {
                $raw[] = $hit[2];
            }
        }
        $raw = array_values(array_unique($raw));
        $this->assertSame([], $raw,
            'these server-supplied values are concatenated into innerHTML without '
            . 'esc(): ' . implode(', ', $raw) . '. Version ids, resolution '
            . 'explanations and violation messages all originate server-side and '
            . 'are rendered as markup; unescaped, any one of them is an injection '
            . 'point. Wrap it in esc().');
    }

    /**
     * A declarative control with nothing listening for it.
     *
     * The design system defines the `data-app-modal-open` handler in
     * tools/js/admin-system.js — which NO view loads. The Generate button was
     * therefore completely inert: clicking it did nothing, with no error in the
     * console to explain why, because nothing was wrong. Nothing was listening.
     *
     * So every declarative opener on this page must have a handler bound by the
     * page itself, unless that script is actually included somewhere.
     */
    public function test_every_declarative_modal_opener_has_a_handler(): void
    {
        $html = $this->view();

        preg_match_all('~data-app-modal-open="([^"]+)"~', $html, $m);
        $openers = array_values(array_unique($m[1]));
        $this->assertNotEmpty($openers, 'premise: the page uses declarative openers');

        foreach ($openers as $id) {
            $this->assertMatchesRegularExpression('~id="' . preg_quote($id, '~') . '"~', $html,
                "data-app-modal-open=\"{$id}\" points at a modal that does not exist");
        }

        // Either the page binds the attribute itself, or the script that does is
        // genuinely loaded. One of the two must be true.
        $bindsItself = strpos($html, "closest('[data-app-modal-open]')") !== false;

        $loaded = false;
        foreach (['include/header.php', 'include/footer.php'] as $shell) {
            $src = (string) file_get_contents(__DIR__ . '/../../application/views/' . $shell);
            if (strpos($src, 'admin-system.js') !== false) { $loaded = true; }
        }

        $this->assertTrue($bindsItself || $loaded,
            'the modal openers on this page have nothing listening for them: '
            . 'admin-system.js is not loaded by the header or footer, so the page '
            . 'must bind data-app-modal-open itself or the button is inert');
    }

    public function test_the_page_does_not_depend_on_an_unloaded_global(): void
    {
        $html = $this->view();

        // Fallbacks, not bare uses: `window.AppBtn || {...}` is fine, calling
        // AppBtn.start() directly is not.
        $this->assertMatchesRegularExpression('~window\.AppModal \|\|~', $html,
            'AppModal must be used with a local fallback');
        $this->assertMatchesRegularExpression('~window\.AppBtn \|\|~', $html,
            'AppBtn must be used with a local fallback');

        $code = (string) preg_replace('~/\*.*?\*/~s', '', $html);
        $code = (string) preg_replace('~^\s*//.*$~m', '', $code);
        $this->assertDoesNotMatchRegularExpression('~(?<!window\.)\bAppBtn\.(start|stop)\(~', $code,
            'call the local Btn helper, not the global directly');
        $this->assertDoesNotMatchRegularExpression('~(?<!window\.)\bAppModal\.(open|close)\(~', $code,
            'call the local Modal helper, not the global directly');
    }

    /**
     * With no versions yet, a successful refresh re-renders the identical empty
     * state — which is indistinguishable from a dead button. It must show that
     * it is working.
     */
    /**
     * alert() blocks the page, cannot be styled, and reads as a browser warning
     * rather than the application answering — and being modal, it covers the
     * very thing it is talking about.
     */
    public function test_no_browser_alert_is_used(): void
    {
        $code = (string) preg_replace('~/\*.*?\*/~s', '', $this->view());
        $code = (string) preg_replace('~^\s*//.*$~m', '', $code);

        $this->assertStringNotContainsString('window.alert', $code,
            'server messages must be shown as toasts, not browser alerts');
        $this->assertStringNotContainsString('alert(', $code,
            'no blocking dialogs on this screen');
    }

    public function test_toast_messages_are_escaped_and_typed(): void
    {
        $js = $this->view();

        $this->assertStringContainsString('function toast(message, type)', $js);

        // Server text goes in as text, never as markup: a message can carry a
        // version id or a subject name that came from Firestore.
        $this->assertMatchesRegularExpression(
            "~\.tt-toast__body.\)\.textContent = String\(message~", $js,
            'the toast body must be set with textContent, not innerHTML');

        foreach (['error', 'success'] as $type) {
            $this->assertMatchesRegularExpression("~'{$type}'~", $js,
                "toasts must distinguish {$type} from the others");
        }

        // An error usually carries an instruction the operator needs while
        // looking at the screen, so it must not vanish on a timer.
        $this->assertMatchesRegularExpression(
            "~if \(type !== 'error'\) \{ setTimeout\(remove~", $js,
            'errors must stay until dismissed; only non-errors auto-dismiss');
    }

    public function test_every_failure_path_reports_through_a_toast(): void
    {
        $code = (string) preg_replace('~/\*.*?\*/~s', '', $this->view());
        $code = (string) preg_replace('~^\s*//.*$~m', '', $code);

        // Every .catch on this page must SURFACE the message — a silent catch
        // turns a refusal into a button that appears to do nothing, which is
        // exactly the failure this screen already had once.
        //
        // Not necessarily a toast, though: the load handler renders the error
        // INTO the grid instead, which is better there. An empty grid would
        // invite the operator to fill in cells over data they simply could not
        // see, so the failure has to replace the content rather than float over
        // it. What matters is that e.message reaches the operator somehow.
        preg_match_all('~\.catch\(function \(e\) \{(.*?)\n\s*\}\)~s', $code, $m);
        $this->assertNotEmpty($m[1], 'premise: the page has failure handlers');

        // A third form is legitimate: carrying the message into a structure
        // that is escaped and rendered a moment later. That is still not a
        // swallow, so it is accepted here AND pinned below — every field the
        // page parks an error into must provably reach the screen.
        foreach ($m[1] as $bodyText) {
            $this->assertMatchesRegularExpression(
                '~(toast\(e\.message|esc\(e\.message\)|error: e\.message)~', $bodyText,
                'a swallowed failure leaves the operator with a button that looks '
                . 'broken. This handler does not surface e.message: ' . trim($bodyText));
        }

        // The parked errors, pinned to their render sites.
        $this->assertStringContainsString('esc(q.error', $code,
            'a per-section quota failure is parked on q.error and must be shown');
        $this->assertStringContainsString('esc(extra.error)', $code,
            'a failed report is parked on extra.error and must be shown');
    }

    public function test_refresh_shows_that_it_is_working(): void
    {
        $js = $this->view();

        $this->assertMatchesRegularExpression(
            '~tt_refresh.\)\.addEventListener\(.click., function \(\) \{~', $js,
            'refresh needs its own handler so it can show progress');
        $this->assertMatchesRegularExpression('~Btn\.start\(b\);~', $js);
        $this->assertMatchesRegularExpression('~load\(\)\.finally~', $js,
            'the spinner must stop when the request finishes, so load() has to '
            . 'hand back its promise');
        $this->assertMatchesRegularExpression('~return api\(.timetable/versions~', $js,
            'load() must return its promise or the spinner never stops');
    }

    // ── the screen never reaches the legacy collection ──────────────────

    public function test_the_screen_only_calls_canonical_endpoints(): void
    {
        $js = $this->view();

        preg_match_all("~api\('([a-z_/]+)~", $js, $m);
        $called = array_values(array_unique($m[1]));
        sort($called);

        $this->assertSame([
            'timetable/abandon',
            // Sends the timetable in force TODAY to the Teacher and Parent apps.
            // publishCandidate() already does this when a version is in force on
            // the day it is published; a version published to start LATER was
            // left to a cron this repository does not schedule, so it reached
            // nobody. Added 2026-09-12 after a real tenant published on the 12th
            // for the 13th and the apps would never have shown it. `manage`, and
            // today-only — the apps hold one timetable per weekday with no date.
            'timetable/apply_projection',
            'timetable/capacity',
            'timetable/create_blank',
            'timetable/create_change_version',
            'timetable/days',
            'timetable/discard',
            'timetable/editor_options',
            // The screen builds through the MULTI endpoint, with a count of one
            // when only one is wanted. The single-candidate action still exists
            // server-side and is still tested; the screen simply has no reason to
            // call two things that differ only in how many they make.
            'timetable/generate_candidates',
            'timetable/publish',
            'timetable/reclaim',
            'timetable/rename',
            // ONE write path for cells: the week grid groups changes by day
            // and sends save_day, which handles one cell or twenty with the
            // same server-side re-read. save_period remains a valid endpoint,
            // it simply has no second caller in the UI.
            'timetable/save_day',
            // Ending a published timetable. Canonical, not legacy: it sets the
            // last date a version applies so a later one can take over, which
            // the one-in-force rule otherwise makes impossible for an
            // open-ended publish. Added 2026-09-12 when the refusal was found
            // to instruct the operator to do something no control could do.
            'timetable/supersede',
            'timetable/validate_candidate',
            'timetable/verdict',
            'timetable/versions',
            // Read-only: does the legacy mirror match what the resolver says is
            // in force? Publishing is invisible to every app until the projection
            // runs, and this repo owns no scheduler — so the screen states the gap
            // where it is created. It APPLIES nothing; the CLI/APPLY=1 seam is
            // untouched, which the test below still enforces.
            'timetable/visibility',
        ], $called,
            'the screen must reach the canonical architecture only; calling a '
            . 'legacy Academic timetable endpoint would put the old direct-write '
            . 'path back behind the new UI');
    }

    /**
     * The bulk save must send only what the operator CHANGED.
     *
     * Posting the whole day back is the legacy saveSectionTimetable shape
     * (ACAD-D21): the browser's copy may be minutes old, so writing it over the
     * stored day silently reverts anyone else's changes and reports success.
     */
    public function test_the_bulk_save_sends_only_changed_cells(): void
    {
        $js = $this->view();

        $this->assertStringContainsString('cells:         JSON.stringify(byDay[day])', $js,
            'the bulk save must send the tracked changes, grouped by day, not the
             rendered week');
        $this->assertMatchesRegularExpression('~byDay\[day\]\[idx\] = DIRTY\[k\]~', $js,
            'changes must be grouped by day: the store replaces one day at a '
            . 'time and re-reads it server-side');
        $this->assertStringNotContainsString('periods: JSON.stringify', $js,
            'posting a whole day back is the ACAD-D21 shape and must not return');
        $this->assertMatchesRegularExpression(
            "~DIRTY\[sel.getAttribute\('data-day'\) \+ '\|' \+ sel.getAttribute\('data-cell'\)\]~", $js,
            'a changed cell must be tracked by DAY and period, because the grid '
            . 'now shows the whole week at once');
        // NOT a bare 'DIRTY = {}': that also matches the `var DIRTY = {};`
        // declaration, so the assertion could never fail. The reset must sit
        // immediately after the grid is re-rendered.
        $this->assertMatchesRegularExpression(
            '~grid\.innerHTML = html;\s*DIRTY = \{\};~', $js,
            'the tracked set must reset when the grid is re-rendered, or a '
            . 'stale index would be resent against different content');
    }

    public function test_the_screen_cannot_apply_a_projection_or_unpublish(): void
    {
        $js = $this->view();

        foreach (['projection', 'unpublish', 'delete_version'] as $forbidden) {
            $this->assertStringNotContainsString('timetable/' . $forbidden, $js,
                "the screen must not offer {$forbidden}: applying the compatibility "
                . 'projection is CLI-only behind APPLY=1, and published timetables '
                . 'are immutable');
        }
    }

    // ── it tells the truth about what it cannot do ──────────────────────

    public function test_the_page_states_that_publishing_is_irreversible(): void
    {
        $html = $this->view();

        // Tags out, whitespace collapsed — the sentence is split across lines and
        // wrapped in <strong>, and matching the raw source meant matching markup.
        $text = (string) preg_replace('~\s+~', ' ', strip_tags($html));

        $this->assertMatchesRegularExpression('~cannot be edited~i', $text,
            'the publish dialog must say a published timetable cannot be edited, '
            . 'because that is the one thing an administrator cannot undo');
        $this->assertMatchesRegularExpression('~create a new version~i', $html,
            'and it must say what to do instead');
    }

    /**
     * A version left behind by a FAILED run looks identical to a good one in a
     * status column: both say "candidate". Publishing it is refused with no clue
     * why, so the run's own state has to be visible.
     */
    public function test_the_build_state_and_its_failure_reason_are_shown(): void
    {
        $js = $this->view();

        foreach (["=== 'complete'", "=== 'failed'", "=== 'running'"] as $state) {
            $this->assertStringContainsString($state, $js,
                "the version list must distinguish {$state} builds");
        }
        $this->assertStringContainsString('gen.reason', $js,
            'a failed build must say what went wrong, not just that it failed');
        $this->assertStringContainsString('Build failed', $js);
    }

    public function test_an_unusable_version_is_not_offered_check_or_publish(): void
    {
        $js = $this->view();

        $this->assertMatchesRegularExpression(
            "~var usable = \(state !== 'failed' && state !== 'running'\);~", $js,
            'a failed or still-running build cannot be checked or published — the '
            . 'server refuses both, and offering the buttons only collects refusals');
        $this->assertStringContainsString('CAN_EDIT && usable', $js);
        $this->assertStringContainsString('CAN_MANAGE && usable', $js);
    }

    public function test_lineage_is_shown_as_provenance_only(): void
    {
        $js = $this->view();

        // NOT a bare 'v.regeneratedFrom': that also matches the render inside
        // the branch, so neutering the guard would slip past. Pin the guard.
        $this->assertMatchesRegularExpression('~if \(v\.regeneratedFrom\)~', $js,
            'where a version came from is useful and must be visible');
        // It must be shown by NAME, never as its identifier. "from
        // CAND_20260829154943_f16245" is a database key on an administrator's
        // screen, which is the thing this suite keeps catching elsewhere.
        $this->assertStringNotContainsString('esc(v.regeneratedFrom)', $js,
            'the source must be named, not identified');
        $this->assertStringContainsString('VERSION_LABEL[v.regeneratedFrom]', $js,
            'the name comes from the same place every other label does');

        // The source is a proposal like any other, so it can since have been
        // removed. Saying so is the honest answer; printing an id that
        // resolves to nothing is not.
        $this->assertStringContainsString('has since been removed', $js,
            'a discarded source must be described, not left dangling');
        // Comments are stripped: the code legitimately describes a day being
        // REPLACED by a save, which has nothing to do with version lineage.
        $code = (string) preg_replace('~/\*.*?\*/~s', '', $js);
        $code = (string) preg_replace('~^\s*//.*$~m', '', $code);

        foreach (['supersedes', 'invalidates'] as $claim) {
            $this->assertStringNotContainsString($claim, $code,
                "lineage is provenance only; the screen must not imply {$claim}");
        }
    }

    /**
     * "candidate" is our internal lifecycle word, not a school's. The screen
     * must speak the operator's vocabulary while the stored data, the API and
     * the security rules keep theirs — so this is a LABEL map, never a change
     * to the values themselves.
     */
    /**
     * Two proposals built on the same day were indistinguishable on screen —
     * same status, same "Generated", same placeholder date — so the wrong one
     * got checked and reported someone else's verdict.
     */
    public function test_versions_are_ordered_and_told_apart_deterministically(): void
    {
        $js = $this->view();

        // effectiveFrom alone ties for every unpublished version.
        $this->assertStringContainsString("String(b.createdAt || '')", $js,
            'ordering must fall back to when the version was built, or two '
            . 'proposals made on one day come back in arbitrary order');

        $this->assertStringContainsString('var made = String(v.createdAt', $js,
            'the build time must be shown, or two same-day proposals differ only '
            . 'by a 24-character id');
    }

    /**
     * An unpublished version carries a PLACEHOLDER effectiveFrom. Printing it
     * under "Applies from" reads as a commitment the version has not made.
     */
    public function test_unpublished_versions_do_not_advertise_an_effective_date(): void
    {
        $js = $this->view();

        $this->assertStringNotContainsString(
            "'<td>' + esc(v.effectiveFrom || '—') + '</td>'", $js,
            'the effective date must not be printed unconditionally');
        $this->assertStringContainsString('set when published', $js,
            'an unpublished version must say its dates are not set yet');
        $this->assertMatchesRegularExpression(
            "~st === 'published'\s*\?\s*esc\(v\.effectiveFrom~", $js,
            'only a published version may show a real effective date');
    }

    /**
     * A document id is not a name.
     *
     * "CAND_20260829154943_f16245" cannot be read, said aloud, or told apart at
     * a glance from another built the same day — which is exactly how the wrong
     * proposal got checked. The id belongs in the data and in requests; a person
     * sees what the timetable IS.
     */
    public function test_no_raw_version_id_is_shown_as_text(): void
    {
        $code = (string) preg_replace('~/\*.*?\*/~s', '', $this->view());
        $code = (string) preg_replace('~^\s*//.*$~m', '', $code);

        // Every remaining use must be machine plumbing: a data-* attribute that
        // identifies the row for the API, or a hover tooltip for support.
        preg_match_all('~esc\((?:v|c|d|versionId|r\.version)\.?\w*versionId\w*\)~', $code, $m);
        foreach ($m[0] as $use) {
            $at = strpos($code, $use);
            $before = substr($code, max(0, $at - 60), min(60, $at));
            $this->assertMatchesRegularExpression('~(data-[a-z]+="|title=")~', $before,
                'a raw version id reaches the screen as text here: ' . $use
                . '. Names go through labelFor().');
        }

        $this->assertStringContainsString('function labelFor(', $code,
            'versions must be named for a person, not keyed');
        $this->assertStringContainsString('buildLabels(list)', $code,
            'the names must be rebuilt whenever the list is loaded');
    }

    public function test_versions_are_named_the_way_a_school_identifies_them(): void
    {
        $js = $this->view();

        // A published timetable is known by when it applies.
        $this->assertMatchesRegularExpression("~'Timetable from ' \+ from~", $js,
            'a published timetable is identified by the date it applies from');

        // An unfinished one by which attempt it is and when it was built.
        $this->assertMatchesRegularExpression("~'Proposal ' \+ \(i \+ 1\)~", $js,
            'proposals must be numbered so two people can refer to the same one');
        $this->assertStringContainsString('built ', $js,
            'and dated, so two proposals from one day are distinguishable');

        // Numbering must be by build order, not list order, or it would change
        // whenever the sort does.
        $this->assertMatchesRegularExpression(
            "~proposals = list\.filter.*?\.sort\(function \(a, b\) \{\s*return String\(a\.createdAt~s", $js,
            'proposal numbers must follow the order they were built in');
    }

    /**
     * The server's explain strings are written for a log and the CLI projection
     * cron: they carry the document id, ISO dates, the plan key, and words like
     * AMBIGUOUS and open-ended. That is right for a cron transcript and wrong on
     * a school's screen, so the page writes its own sentence from the structured
     * fields instead of degrading the one an engineer relies on.
     */
    public function test_server_log_strings_are_not_shown_to_the_operator(): void
    {
        $code = (string) preg_replace('~/\*.*?\*/~s', '', $this->view());
        $code = (string) preg_replace('~^\s*//.*$~m', '', $code);

        foreach (['esc(r.explain', 'd.explain'] as $leak) {
            $this->assertStringNotContainsString($leak, $code,
                'the server explain string names the document id and an ISO date; '
                . 'the page must compose its own wording');
        }
        $this->assertStringContainsString('function resolutionSentence(', $code,
            'the page needs its own wording for what is in force');
    }

    /** And the server keeps its precise wording — the cron transcript needs it. */
    public function test_the_server_explanation_is_left_intact_for_the_cron(): void
    {
        $store = (string) file_get_contents(
            __DIR__ . '/../../application/libraries/Timetable_plan_store.php');

        $this->assertStringContainsString('_explainPublish(', $store,
            'the server-side explanation must survive: the projection cron runs '
            . 'on a terminal, where the document id is the useful part');
    }

    public function test_dates_are_shown_in_words_not_iso(): void
    {
        $js = $this->view();

        $this->assertStringContainsString('function fmtDate(', $js);
        $this->assertStringContainsString("MONTHS = ['Jan'", $js,
            '"2026-09-01" is a machine date; a school reads "1 Sep 2026"');

        // Parsing with Date() would shift the day across a timezone boundary.
        $i = strpos($js, 'function fmtDate(');
        $body = substr($js, $i, 400);
        $this->assertStringNotContainsString('new Date(', $body,
            'the date is sliced from the ISO string on purpose: parsing it would '
            . 'shift the day for anyone east or west of the server');
    }

    public function test_lifecycle_states_are_shown_in_school_language(): void
    {
        $js = $this->view();

        $this->assertStringContainsString('var STATUS_LABEL', $js,
            'the screen must translate stored states into words a school uses');

        foreach (['draft' => 'Draft', 'candidate' => 'Proposed',
                  'published' => 'Published', 'superseded' => 'Replaced'] as $stored => $shown) {
            $this->assertMatchesRegularExpression(
                "~{$stored}:\s*'{$shown}'~", $js,
                "the stored state '{$stored}' must be shown to the operator as "
                . "'{$shown}'");
        }

        // The pills must go through the map, not print the raw value.
        $this->assertStringNotContainsString("pill + '\">' + esc(st) +", $js,
            'the versions table must render the label, not the stored value');
        $this->assertStringContainsString('esc(statusLabel(st))', $js);
        $this->assertStringContainsString('esc(statusLabel(EDIT.status))', $js);
    }

    public function test_the_stored_vocabulary_is_not_renamed(): void
    {
        // Renaming the STORED values would break existing documents, the
        // Firestore rules and every test. Only the display changes.
        $svc = (string) file_get_contents(
            __DIR__ . '/../../application/libraries/Timetable_plan_service.php');

        $this->assertMatchesRegularExpression(
            "~STATUS_CANDIDATE\s*=\s*'candidate'~", $svc,
            'the stored lifecycle value must stay "candidate" — the label map is '
            . 'presentation only, and renaming the data would orphan every '
            . 'existing version document');
        $this->assertMatchesRegularExpression("~STATUS_DRAFT\s*=\s*'draft'~", $svc);
    }

    /**
     * Discard is offered for UNPUBLISHED work only, and never without asking.
     * A published timetable is the record of what was in force.
     */
    public function test_discard_is_offered_only_for_unpublished_work(): void
    {
        $js = $this->view();

        $this->assertMatchesRegularExpression(
            "~if \(CAN_EDIT && \(st === 'draft' \|\| st === 'candidate'\)\) \{\s*\n\s*actions \+= ' <button[^\n]*ac-btn-d~", $js,
            'the Discard button must be gated on the version being unpublished — '
            . 'the server refuses either way, but offering it on a published '
            . 'timetable invites an action that cannot succeed');

        // The call being present is not enough — a mutation that short-circuits
        // the guard leaves it there untouched. Pin the guard itself: the discard
        // request must sit INSIDE the answer callback, behind an explicit
        // refusal on "no".
        //
        // Themed since 2026-09-12. window.confirm() renders the browser's own
        // dialog, which reads "localhost says" — it names the developer's
        // machine to a school office and cannot be worded properly.
        $this->assertMatchesRegularExpression(
            "#confirmModal\(\{\s*\n\s*title: 'Discard "
            . "[\s\S]{0,400}?if \(!ok\) \{ return; \}"
            . "[\s\S]{0,400}?api\('timetable/discard'#", $js,
            'discarding removes work outright, so the confirmation must GATE it, '
            . 'not merely appear beside it');
        // Comments stripped: confirmModal()'s own doc block names window.confirm
        // to say what it replaced, and matching raw text would read that
        // explanation as the call itself.
        $code = (string) preg_replace('#/\*[\s\S]*?\*/#', '', $js);
        $this->assertStringNotContainsString('window.confirm', $code,
            "the browser's own confirm dialog is back: it reads \"localhost says\" "
            . 'to a school office and cannot be styled or worded');
        $this->assertMatchesRegularExpression('~cannot be undone~i', $js,
            'and the confirmation must say so');
    }

    /**
     * THE TIMETABLE. Periods down, days across — this is what a school means by
     * a timetable, and what a findings list and a one-day dropdown column are
     * both not.
     */
    public function test_the_grid_renders_a_whole_week(): void
    {
        $js = $this->view();

        $this->assertStringContainsString('function renderWeek(', $js);

        // Every working day becomes a column. Asserting the loop exists is not
        // enough: a mutation that narrows `days` just before it leaves the loop
        // intact and renders one column. So the list must be computed once and
        // never reassigned.
        $code = (string) preg_replace('~/\*.*?\*/~s', '', $js);
        $code = (string) preg_replace('~^\s*//.*$~m', '', $code);
        $i = strpos($code, 'function renderWeek(');
        $body = substr($code, $i, (int) strpos(substr($code, $i), "
    }"));

        $this->assertSame(1, preg_match_all('~\bdays\s*=~', $body),
            'the day list must be assigned exactly once inside renderWeek: any '
            . 'reassignment could silently drop days from the week');
        $this->assertMatchesRegularExpression(
            "~days\.forEach\(function \(d\) \{ html \+= '<th>' \+ esc\(d\) \+ '</th>'; \}\);~", $body,
            'each day the timetable covers must be a column');

        // …and every period a row, counted from the timetable itself rather
        // than assumed.
        $this->assertStringContainsString('var periods = periodCount(sectionKey);', $js,
            'the number of rows must come from the stored timetable, not a guess');
        $this->assertMatchesRegularExpression('~for \(var i = 0; i < periods; i\+\+\)~', $js,
            'every period must be a row');

        // A published timetable is readable without being editable.
        $this->assertStringContainsString('EDIT.editable ? cellSelect(d, i, p) : cellRead(p)', $js,
            'a published timetable must still be VIEWABLE — read-only, not hidden');
    }

    /**
     * A button that has been pressed must LOOK pressed.
     *
     * The panel briefly carried its own busy convention (`is-loading` plus a
     * spinner span) beside the page's (`loading`, which draws the spinner from
     * a ::before and needs no span). Buttons built without the span — Refresh,
     * Discard, Generate — dimmed to 65% and showed nothing, which reads as a
     * button that has died rather than one that is working. There must be
     * exactly one convention, and it must be the page's.
     */
    public function test_busy_buttons_use_the_pages_own_spinner(): void
    {
        $js = $this->view();

        $this->assertStringContainsString("b.classList.add('loading');", $js,
            'marking a button busy must use the class the page actually styles');
        $this->assertStringContainsString("b.classList.remove('loading');", $js);

        $this->assertStringNotContainsString('is-loading', $js,
            'a second busy convention leaves every button that lacks its markup '
            . 'with no spinner at all');
        $this->assertStringNotContainsString('ac-tt-btnspin', $js,
            'the spinner span belonged to the abandoned convention');

        // And the page really does draw a spinner for that class.
        $host = file_get_contents(FCPATH . 'application/views/academic/index.php');
        $this->assertMatchesRegularExpression(
            '~\.ac-btn\.loading::before\s*\{[^}]*animation:\s*btn-spin~', $host,
            'the panel relies on the host page drawing .ac-btn.loading');
    }

    /**
     * Check results must show the SHAPE of a problem, not eighteen sentences
     * that differ by one word.
     *
     * A flat list of "Class 9th/Section A has nothing scheduled on Friday in
     * period 6." repeated eighteen times hides the thing worth knowing — that
     * period 6 is empty all week. Grouping by type, then by section, with each
     * occurrence as a slot chip, makes that legible at a glance.
     */
    public function test_findings_are_grouped_by_type_not_listed_flat(): void
    {
        $js = $this->view();

        $this->assertStringContainsString('function renderFindings(list, severity)', $js);
        $this->assertStringContainsString("renderFindings(hard, 'hard')", $js);
        $this->assertStringContainsString("renderFindings(soft, 'soft')", $js);

        // Each type carries a human name AND what to do about it.
        $this->assertStringContainsString("unallocated_period:      ['Free periods',", $js,
            'a code like unallocated_period means nothing to a head teacher');
        $this->assertMatchesRegularExpression('~day ends early~', $js,
            'a finding must say what the operator should do about it');

        // The old flat list is gone, not merely bypassed.
        $this->assertStringNotContainsString(
            "hard.forEach(function (x) { html += '<li>' + esc(x.message) + '</li>'; });", $js,
            'the flat list must be removed, not left behind as a second path');
    }

    /**
     * Staff IDs are for the database, not the screen.
     *
     * Two findings are teacher-scoped rather than section-scoped, and the
     * validator — which is pure and knows no names — writes the raw ID into
     * its message. The screen must resolve it, and fall back to the ID rather
     * than hiding a finding it cannot name.
     */
    public function test_teacher_findings_are_shown_by_name(): void
    {
        $js = $this->view();

        $this->assertStringContainsString('function teacherName(id)', $js);
        $this->assertStringContainsString('return TEACHER_NAME[id] || id;', $js,
            'an unknown teacher must still be shown, by ID, not dropped');
        $this->assertStringContainsString('learnTeachers(EDIT.days);', $js,
            'names must be learnt from the timetable that has been loaded');
        $this->assertStringContainsString('learnTeachers(EDIT.options);', $js);

        $this->assertMatchesRegularExpression(
            '~w\.section \? w\.section\s*\n\s*: \(w\.teacherId \? teacherName\(w\.teacherId\) : ..\);~', $js,
            'a teacher-scoped finding must group under the teacher\'s NAME');
    }

    /**
     * EVERY handler that reaches the network must mark its button busy.
     *
     * Proving the spinner MECHANISM works is not the same as proving it is
     * used, and the difference is exactly what the operator hit: Results and
     * View both fetched, neither called Btn.start, and both looked dead. This
     * walks the delegated click handler branch by branch instead.
     */
    public function test_every_async_action_shows_a_loader(): void
    {
        $js = $this->view();

        $start = strpos($js, "document.addEventListener('click', function (ev) {");
        $this->assertNotFalse($start, 'the delegated click handler must exist');
        $block = substr($js, $start, strpos($js, "\n    });", $start) - $start);

        // Strip comments BEFORE analysing. A comment that MENTIONS api() is not
        // a call to it, and reading one as a call reports a defect that is not
        // there — the same substring trap this suite keeps walking into.
        $block = (string) preg_replace('~/\*.*?\*/~s', '', $block);
        $block = (string) preg_replace('~^[ \t]*//.*$~m', '', $block);

        preg_match_all("~if \((btn\.dataset\.\w+|btn\.id === '[^']+')\) \{~",
            $block, $m, PREG_OFFSET_CAPTURE);
        $this->assertGreaterThanOrEqual(6, count($m[0]),
            'the handler should cover every row action');

        $missing = [];
        foreach ($m[0] as $i => $hit) {
            $name = $m[1][$i][0];
            $from = $hit[1];
            $to   = $m[0][$i + 1][1] ?? strlen($block);
            $body = substr($block, $from, $to - $from);

            $async = (bool) preg_match('~\bapi\(~', $body)
                  || (bool) preg_match('~\b(openEditor|saveAll|showReport)\(~', $body);
            if (!$async) { continue; }

            // saveAll is handed the button and manages its own busy state.
            $busy = strpos($body, 'Btn.start(') !== false
                 || strpos($body, 'saveAll(btn)') !== false;
            if (!$busy) { $missing[] = $name; }
        }

        $this->assertSame([], $missing,
            'these actions fetch but never show a loader, so the button looks '
            . 'dead while it works: ' . implode(', ', $missing));
    }

    /**
     * The report must answer the question a school actually has.
     *
     * A list of validator findings says what is WRONG. It does not say whether
     * the timetable delivers the curriculum the school asked for — and a
     * subject that is quietly one period short every week is a bigger problem
     * than an empty last period, with nothing else in the product to catch it.
     */
    public function test_the_report_checks_the_curriculum_it_was_asked_for(): void
    {
        $js = $this->view();

        $this->assertStringContainsString('function renderCurriculum(t, quotas)', $js);
        $this->assertStringContainsString('Curriculum delivered', $js);

        // The target is the SUM of periodsPerWeek across a subject's rows,
        // which is what the generator places from. Anything else would compare
        // the timetable against a standard it was never built to.
        $this->assertStringContainsString(
            "asked[o.subject] += (o.periodsPerWeek || 0);", $js,
            'the target must be summed across assignment rows, matching the generator');

        $this->assertMatchesRegularExpression(
            "~else if \(g < a\) \{ flag = \['short', \(a - g\) \+ ' short'\]; \}~", $js,
            'a subject with fewer periods than asked for must be flagged short');

        // A subject taught but never assigned must not be silently dropped.
        $this->assertMatchesRegularExpression(
            '~Object\.keys\(got\)\.forEach\(function \(sub\) \{\s*\n\s*if \(!\(sub in asked\)\)~', $js,
            'a subject on the timetable but not in the assignments must still appear');

        $this->assertStringContainsString('function renderLoad(t)', $js);
        $this->assertStringContainsString('Teaching load', $js);
    }

    /**
     * A quota that could not be read must be SAID.
     *
     * Treating an unreadable assignment list as a zero target would mark every
     * subject "not assigned" and make a correct timetable look broken — the
     * silent-empty-read failure this whole programme exists to remove, in the
     * one place where it would be most convincing.
     */
    public function test_an_unreadable_curriculum_is_stated_not_assumed(): void
    {
        $js = $this->view();

        $this->assertMatchesRegularExpression(
            '~if \(!q\.options\) \{~', $js,
            'a section whose assignments could not be read must be handled apart '
            . 'from one that genuinely has none');
        $this->assertStringContainsString(
            'cannot be checked against its curriculum', $js,
            'the operator must be told which section could not be checked');

        // And a failed report must not take the findings down with it.
        $this->assertStringContainsString('The findings below are unaffected.', $js);
    }

    /**
     * The rename box must open with the school's OWN name in it, never the
     * label this screen generated.
     *
     * Seeding it with "Proposal 2 · built 29 Aug 2026, 9:40 pm" would mean the
     * first person who opens the box and presses Save silently adopts a
     * machine-written string as the timetable's real name — and from then on it
     * stops tracking the build time it was describing.
     */
    public function test_the_rename_box_offers_the_schools_own_name(): void
    {
        $js = $this->view();

        $this->assertStringContainsString(
            "VERSION_OWN[btn.dataset.rename] || ''", $js,
            'the box must be seeded from the stored name, not from labelFor()');

        $this->assertMatchesRegularExpression(
            '~Object\.keys\(VERSION_OWN\)\.forEach~', $js,
            "a name the school typed must be applied AFTER the generated ones, "
            . 'or it loses to them');
    }

    /**
     * The break between periods must be drawn, on screen and on paper.
     *
     * A recess is configured as "after period N, D minutes", and the stored
     * times already encode it: period N ends, period N+1 starts D minutes
     * later. Nothing is ever scheduled in between, because a break is not a
     * slot — which is why it was easy to leave out, and why leaving it out
     * matters: a week that jumps from 10:15 to 11:00 with no explanation is one
     * a teacher has to guess at. The legacy timetable showed it; the canonical
     * one dropped it.
     */
    public function test_the_break_between_periods_is_drawn(): void
    {
        $js = $this->view();

        // Derived from the TIMES, not read from settings again — so the row
        // always describes the timetable that actually exists, even for a
        // version generated before the settings changed.
        $this->assertStringContainsString('function breakAfter(thisPeriod, nextPeriod)', $js,
            'the gap must be computed from the stored period times');
        $this->assertStringContainsString('function timeToMin(s)', $js);
        $this->assertMatchesRegularExpression('~var gap = breakAfter\(t, nextT\);~', $js);
        $this->assertMatchesRegularExpression(
            '~if \(gap > 0\) \{\s*\n\s*html \+= .<tr class="ac-tt-break">~', $js,
            'a break row must be emitted only where there is actually a gap');

        // It spans every day column: a recess applies to the whole week.
        $this->assertStringContainsString("colspan=\"' + (days.length + 1) + '\"", $js);

        // And it says how long it is, which is the part a teacher needs.
        $this->assertStringContainsString("' \\u00b7 ' + gap + ' min", $js,
            'the length of the break must be shown, not merely that one exists');
    }

    /** The same on paper, where it matters most. */
    public function test_the_pdf_draws_the_break_too(): void
    {
        $pdf = (string) file_get_contents(
            __DIR__ . '/../../application/views/timetable/pdf.php');

        $this->assertStringContainsString('$toMin', $pdf,
            'the PDF needs its own time parser: it is rendered by Dompdf, away '
            . 'from any of the page helpers');
        $this->assertMatchesRegularExpression('~if \(\$gap > 0\): \?>~', $pdf);
        // The word is no longer hardcoded: a school may call it Lunch, Recess,
        // Tiffin or Interval. What must hold is that the label is RESOLVED and
        // that a school which has named nothing still gets a word.
        $this->assertStringContainsString('$breakName($i + 1)', $pdf,
            'the printed label must come from the school\'s own setting');
        $this->assertStringContainsString("\$name !== '' ? \$name : 'Break'", $pdf,
            'and a school that has named nothing must still see a word');
        $this->assertStringContainsString('colspan="<?= count($dayNames) + 1 ?>"', $pdf,
            'the break spans the week on paper as well');
    }


    /**
     * A school may call its break Lunch, Recess, Tiffin or Interval.
     *
     * The label is looked up by the period the break FOLLOWS, so a school that
     * later moves its break finds no match against an older timetable's gap and
     * falls back to the generic word — a historical timetable is labelled
     * generically, never wrongly.
     */
    public function test_the_break_label_is_the_schools_own_word(): void
    {
        $js = $this->view();

        $this->assertStringContainsString('function breakName(n)', $js);
        $this->assertStringContainsString("EDIT.breaks   = d.breaks || {};", $js,
            'the labels must arrive with the week they describe');

        // Keyed by the period it follows, and tolerant of the key arriving as a
        // string — JSON object keys are strings, and a lookup that only tried
        // the number would silently never match.
        $this->assertStringContainsString(
            "(EDIT.breaks || {})[n] || (EDIT.breaks || {})[String(n)]", $js);

        $this->assertStringContainsString("? name.trim() : 'Break'", $js,
            'a school that has named nothing must still see a word');

        $this->assertStringContainsString('esc(breakName(i + 1))', $js,
            'the label is school-supplied text and must be escaped');
    }

    public function test_no_fabricated_score_is_displayed(): void
    {
        $js = $this->view();

        // Careful: 'v.score' is a substring of 'v.scoreReason', so the naive
        // assertion here could never pass. What must be absent is the score
        // being RENDERED as a value.
        foreach (['esc(v.score)', 'v.score)', 'v.score +', '+ v.score'] as $render) {
            $this->assertStringNotContainsString($render, $js,
                'ranking soft violations needs weights nobody has chosen; a number '
                . 'on this page would be acted on as though it meant something');
        }
        $this->assertStringContainsString('scoreReason', $js,
            'the page must instead say WHY there is no overall score');
    }

    public function test_an_untrusted_verdict_is_surfaced_before_its_numbers(): void
    {
        $js = $this->view();

        $this->assertStringContainsString('payload.trusted === false', $js,
            'a verdict that no longer describes the timetable must be flagged, or '
            . 'the counts above it describe an arrangement that no longer exists');
    }

    public function test_an_ambiguous_resolution_is_shown_not_silently_resolved(): void
    {
        $js = $this->view();

        $this->assertStringContainsString("ambiguous", $js);
        $this->assertMatchesRegularExpression('~r\.status === .ambiguous.~', $js,
            'two published timetables covering one date must be reported to the '
            . 'operator with both named, never quietly resolved to one');
    }
}
