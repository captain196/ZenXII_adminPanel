<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * ACAD-D6 / ACAD-D7 — the COMPLETE failure path, not just the backend refusal.
 *
 * Refusing server-side is only the first of five things that have to hold when
 * the existing timetable cannot be read, or the period configuration cannot be
 * determined:
 *
 *   1. zero writes are performed;
 *   2. the stored timetable is left untouched;
 *   3. a clear, actionable message reaches the Admin UI;
 *   4. the loading/busy state terminates;
 *   5. a retry is safe once the underlying problem is resolved.
 *
 * Each is asserted against the actual chain:
 *
 *     savePeriod()  →  Academic::save_period  →  json_error (HTTP 400)
 *                   →  post() in views/academic  →  .catch in the cell editor
 *
 * The subtle one is (3). json_error answers HTTP 400, so the response is NOT
 * a resolved promise — an implementation that only handled the success path
 * would swallow the message entirely and leave the operator with a silent
 * no-op. post() is fail-closed by design and rethrows d.message; this test
 * exists to keep it that way.
 */
final class TimetableSaveFailurePathTest extends TestCase
{
    private function svc(): string
    {
        $p = __DIR__ . '/../../application/libraries/Timetable_service.php';
        $this->assertFileExists($p);
        return (string) file_get_contents($p);
    }

    private function view(): string
    {
        $p = __DIR__ . '/../../application/views/academic/index.php';
        $this->assertFileExists($p);
        return (string) file_get_contents($p);
    }

    private function savePeriodBody(): string
    {
        $s = $this->svc();
        $i = strpos($s, 'function savePeriod(');
        $this->assertNotFalse($i, 'savePeriod() is gone');
        $e = strpos($s, "\n    public function ", $i + 10);
        $this->assertNotFalse($e);
        return substr($s, $i, $e - $i);
    }

    /**
     * (1) + (2) — every guard must fire before the single persistence call, so
     * a refusal performs no write and the stored document is untouched.
     */
    public function test_no_write_can_precede_a_refusal(): void
    {
        $fn = $this->savePeriodBody();

        // ACAD-D20 moved the persistence call from firestoreSet() to a CAS
        // commit (firestoreCommitBatchStatus) so a concurrent edit cannot be
        // silently overwritten. It is still exactly ONE call, and it must
        // still sit after every guard — case-insensitive so the CommitBatch
        // spelling cannot slip past this check unnoticed.
        preg_match_all(
            '~firestoreSet|firestoreDelete|firestoreUpdate|->set\(|->update\(|->remove\(|commitBatch|batchSet~i',
            $fn, $writes, PREG_OFFSET_CAPTURE
        );
        $this->assertCount(1, $writes[0],
            'savePeriod must have exactly ONE persistence call; more than one '
            . 'means a refusal could leave a partial write behind');

        $writeAt = $writes[0][0][1];

        preg_match_all('~ACAD-D[67]~', $fn, $guards, PREG_OFFSET_CAPTURE);

        // Pin the COUNT, not just the ordering. Asserting only "every guard
        // found precedes the write" lets a deleted or renamed guard pass
        // silently — the loop simply checks fewer things.
        $this->assertGreaterThanOrEqual(3, count($guards[0]),
            'savePeriod must retain all three preservation guards: the existing-day '
            . 'read (ACAD-D6) and both period-count guards (ACAD-D7). Found '
            . count($guards[0]));

        foreach ($guards[0] as $k => [$_, $at]) {
            $this->assertLessThan($writeAt, $at,
                "guard #{$k} sits AFTER the write — a refusal would leave the "
                . 'document half-rebuilt');
        }
    }

    /** (3) — the service message must survive into an HTTP error response. */
    public function test_the_controller_turns_the_refusal_into_a_json_error(): void
    {
        $c = (string) file_get_contents(__DIR__ . '/../../application/controllers/Academic.php');

        $i = strpos($c, 'public function save_period');
        $this->assertNotFalse($i, 'save_period() is gone');
        // Window must clear save_period's long argument list to reach the catch.
        $body = substr($c, $i, 1600);

        $this->assertMatchesRegularExpression(
            '~catch\s*\(\s*Service_exception\s+\$e\s*\)\s*\{\s*return \$this->json_error\(\$e->getMessage\(\)\)~s',
            $body,
            'the refusal must reach the client as its own message, not a generic error');
    }

    /**
     * (3) again — json_error answers 4xx, so the client MUST handle the
     * rejected path. A success-only handler would swallow the message.
     */
    public function test_the_client_helper_rethrows_the_server_message(): void
    {
        $v = $this->view();

        $i = strpos($v, 'function post(url,params)');
        $this->assertNotFalse($i, 'the academic post() helper is gone');
        $fn = substr($v, $i, 1400);

        $this->assertMatchesRegularExpression('~if\(!r\.ok\)~', $fn,
            'post() must treat a non-2xx as a failure, not resolve it as success');
        $this->assertMatchesRegularExpression('~d\.message~', $fn,
            'post() must surface the server message, not a generic one');
        $this->assertMatchesRegularExpression('~throw e~', $fn,
            'post() must reject so callers can .catch it');
    }

    /** (4) — the busy indicator must clear on the failure path too. */
    public function test_the_busy_state_terminates_on_failure(): void
    {
        $v = $this->view();

        $i = strpos($v, 'function post(url,params)');
        $this->assertNotFalse($i);
        $fn = substr($v, $i, 1400);

        $this->assertMatchesRegularExpression('~acBusyStart\(\)~', $fn);
        $this->assertMatchesRegularExpression('~\.finally\(acBusyEnd\)~', $fn,
            'the busy state must be released in finally() — a then()-only release '
            . 'leaves the loading bar stuck after a refusal');

        // The counter must not be able to go negative and strand the bar.
        $this->assertMatchesRegularExpression(
            '~function acBusyEnd\(\)\s*\{[^}]*Math\.max\(0~', $v,
            'acBusyEnd must floor its counter at zero');
    }

    /**
     * (4) + (5) — the cell editor must release its own lock, revert the
     * optimistic value, and tell the operator, so a retry is clean.
     */
    public function test_the_cell_editor_recovers_and_allows_retry(): void
    {
        $v = $this->view();

        $i = strpos($v, "post('academic/save_period'");
        $this->assertNotFalse($i, 'the save_period call site is gone');
        $region = substr($v, $i, 2600);

        $c = strpos($region, '.catch(');
        $this->assertNotFalse($c, 'the save has no failure handler — the message is lost');
        $handler = substr($region, $c, 400);

        $this->assertStringContainsString('restoreCell()', $handler,
            'the cell must revert, or the grid shows a value that was never saved');
        $this->assertStringContainsString('toast(', $handler,
            'the operator must be told the save was refused');
        $this->assertMatchesRegularExpression('~saving\s*=\s*false~', $handler,
            'the save lock must release, or the cell cannot be retried');
    }

    /**
     * (5) — the local cache must only be advanced on success, so a retry is
     * computed from the true stored state rather than a phantom value.
     */
    public function test_the_local_cache_is_only_updated_on_success(): void
    {
        $v = $this->view();

        $i = strpos($v, "post('academic/save_period'");
        $this->assertNotFalse($i);
        $region = substr($v, $i, 2600);

        $ok    = strpos($region, "d.status==='success'");
        $cache = strpos($region, 'AC.tt.timetables[lbl][day][periodIdx]');

        $this->assertNotFalse($ok, 'the success branch is gone');
        $this->assertNotFalse($cache, 'the cache update is gone');
        $this->assertGreaterThan($ok, $cache,
            'the cache must only advance inside the success branch, otherwise a '
            . 'refused save leaves the client believing a value it never stored');
    }

    /**
     * (5) — the retry's hidden dependency.
     *
     * post() sends a CSRF token captured once at page load. json_error()
     * returns a fresh one, and post() adopts it — but only usefully if that
     * adoption happens BEFORE the `if(!r.ok)` throw. Move it inside the
     * success branch and the failure path stops refreshing the token.
     *
     * Today csrf_regenerate is FALSE, so the token never rotates and nothing
     * would break. That makes this a latent coupling rather than a defect:
     * whichever programme flips csrf_regenerate to TRUE would silently turn
     * every academic retry-after-refusal into CI's blank 403 — no log, no
     * console error, no message. Pinning the ordering now means the failure
     * path stays correct under either setting.
     */
    public function test_the_failure_path_refreshes_the_csrf_token_for_retry(): void
    {
        $v = $this->view();

        $i = strpos($v, 'function post(url,params)');
        $this->assertNotFalse($i, 'the academic post() helper is gone');
        $fn = substr($v, $i, 1400);

        $adopt = strpos($fn, 'CSRF_TOKEN=d.csrf_token');
        $throw = strpos($fn, 'if(!r.ok)');

        $this->assertNotFalse($adopt,
            'post() must adopt the csrf_token that json_error() returns, or a '
            . 'retry after a refusal can be rejected with a blank 403');
        $this->assertNotFalse($throw, 'the fail-closed check is gone');

        $this->assertLessThan($throw, $adopt,
            'the token refresh sits AFTER the failure throw, so it only runs on '
            . 'success — exactly the path that does not need it. A retry after a '
            . 'refusal would keep sending the stale token.');
    }
}
