<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * ACAD-D15 and ACAD-D16 — the last two members of the silent-[] family.
 *
 * ACAD-D15 — Academic::suggest_substitutes()
 *
 *   $ttDocs = firestoreQuery('timetables', [school, session, day], null, 'ASC', 200);
 *   ... build $busyMap ...
 *   if (!isset($busyMap[$tid][$pn])) { ...offer this teacher... }
 *
 *   Read negatively, so an EMPTY map offers EVERY teacher — including ones
 *   already teaching their own class that period. The read had no try/catch at
 *   all, and firestoreQuery() returns [] on failure rather than throwing.
 *
 *   SEVERITY IS LOW, for a reason worth pinning rather than assuming: the
 *   endpoint is routed but has NO caller — not in the admin views, not in the
 *   Teacher app, not in the Parent app. It is hardened so it cannot be wired up
 *   later and inherit the family defect. Substitute_service (ACAD-D12) enforces
 *   the actual booking, so a bad suggestion could never create a clash by
 *   itself; it is a completeness problem, and it reports rather than denies.
 *
 * ACAD-D16 — Timetable_service::reconcileClassFromMatrix()
 *
 *   $rows = firestoreQuery(TT, [['schoolId', '==', ...]], null, 'ASC', 2000);
 *   ...
 *   if ((string)($d['session'] ?? '') !== $this->session) continue;
 *
 *   NOT a tenant-isolation defect — the session is filtered, just in PHP. The
 *   defect is where the 2000-row budget goes: it is spent across EVERY retained
 *   session. docIds sort {school}_{session}_… under __name__ ASC and session
 *   strings sort ascending ('2024-2025' < '2025-2026'), so older sessions are
 *   read first and the CURRENT session — the only one this method reconciles —
 *   is precisely the one truncated away. A school accumulates the bug by simply
 *   staying in business.
 *
 *   Filtering in the query reads strictly less and needs no index change:
 *   schoolId+session is already in firestore.indexes.json.
 */
final class SubstituteSuggestionAndSessionScopeTest extends TestCase
{
    private function ctrl(): string
    {
        $p = __DIR__ . '/../../application/controllers/Academic.php';
        $this->assertFileExists($p);
        return (string) file_get_contents($p);
    }

    private function svc(): string
    {
        $p = __DIR__ . '/../../application/libraries/Timetable_service.php';
        $this->assertFileExists($p);
        return (string) file_get_contents($p);
    }

    /**
     * Source with comments removed.
     *
     * The ACAD-D16 comment QUOTES the PHP session filter it explains, and the
     * retention test below matched that quotation instead of the code — so
     * deleting the real filter left the test green. Strip comments first.
     */
    private function codeOnly(string $src): string
    {
        $s = preg_replace('~/\*.*?\*/~s', '', $src);
        return (string) preg_replace('~^\s*//.*$~m', '', (string) $s);
    }

    private function suggest(): string
    {
        $s = $this->ctrl();
        $i = strpos($s, 'function suggest_substitutes(');
        $this->assertNotFalse($i, 'suggest_substitutes() is gone');
        $e = strpos($s, "\n    public function ", $i + 10);
        if ($e === false) $e = strlen($s);
        return substr($s, $i, $e - $i);
    }

    // -- ACAD-D15 --------------------------------------------------------

    public function test_the_suggestion_scan_is_fail_loud_and_capped_by_name(): void
    {
        $this->assertMatchesRegularExpression(
            '~const SUBSTITUTE_SUGGEST_LIMIT\s*=\s*\d+~', $this->ctrl(),
            'the cap must be named so the saturation check cannot drift from it');

        $fn = $this->suggest();

        $this->assertStringContainsString('firestoreQueryOrFail', $fn,
            'the busy map is read negatively; a failed read must not look like '
            . '"nobody is busy"');

        $this->assertMatchesRegularExpression(
            '~self::SUBSTITUTE_SUGGEST_LIMIT \+ 1~', $fn,
            'read cap+1 so saturation is detectable');

        $this->assertDoesNotMatchRegularExpression(
            "~'ASC',\s*200\s*\)~", $fn, 'a bare literal cap is back');
    }

    public function test_both_suggestion_failure_modes_are_reported(): void
    {
        $fn = $this->suggest();

        $this->assertSame(2, preg_match_all('~\$complete\s*=\s*false;~', $fn),
            'two paths make the suggestion list incomplete: saturation and a '
            . 'failed read');

        // Counting those assignments is not enough on its own: disabling the
        // saturation branch leaves the assignment inside dead code and the count
        // unchanged. Pin the condition itself.
        $this->assertMatchesRegularExpression(
            '~count\(\$ttDocs\)\s*>\s*self::SUBSTITUTE_SUGGEST_LIMIT~', $fn,
            'the saturation branch no longer tests the row count, so a truncated '
            . 'scan is reported as a complete one');

        $this->assertMatchesRegularExpression(
            '~array_slice\(\$ttDocs, 0, self::SUBSTITUTE_SUGGEST_LIMIT\)~', $fn,
            'the probe doc must be trimmed before the busy map is built');

        $this->assertMatchesRegularExpression(
            "~'completeness'\s*=>~", $fn,
            'the caller must be told the availability list may be wrong');
    }

    /** The negative read this all protects must still be there. */
    public function test_the_suggestion_still_reasons_negatively(): void
    {
        $this->assertMatchesRegularExpression(
            '~if \(!isset\(\$busyMap\[\$tid\]\[\$pn\]\)\)~', $this->suggest(),
            'if the availability test changed shape, the reasoning above needs '
            . 'rechecking before trusting this hardening');
    }

    /**
     * The low-severity claim rests on there being no caller. Pin it, so that
     * wiring the endpoint up forces a fresh look at the UI side of the contract.
     */
    public function test_the_endpoint_is_still_routed_but_unconsumed(): void
    {
        $routes = (string) file_get_contents(__DIR__ . '/../../application/config/routes.php');
        $this->assertStringContainsString('academic/suggest_substitutes', $routes,
            'the endpoint is expected to remain reachable');

        $view = (string) file_get_contents(__DIR__ . '/../../application/views/academic/index.php');
        $this->assertStringNotContainsString('suggest_substitutes', $view,
            'the admin panel now calls suggest_substitutes. Its `completeness` key '
            . 'has no UI consumer, so a partial availability list would be shown as '
            . 'authoritative — surface it before wiring this up.');
    }

    // -- ACAD-D16 --------------------------------------------------------

    private function reconcile(): string
    {
        $s = $this->svc();
        $i = strpos($s, 'function reconcileClassFromMatrix(');
        $this->assertNotFalse($i, 'reconcileClassFromMatrix() is gone');
        $e = strpos($s, "\n    public function ", $i + 10);
        if ($e === false) $e = strpos($s, "\n    private function ", $i + 10);
        $this->assertNotFalse($e);
        return $this->codeOnly(substr($s, $i, $e - $i));
    }

    /**
     * ACAD-D16 — SUPERSEDED BY RETIREMENT, 2026-08-29.
     *
     * This pair used to assert that the reconcile read carried a session filter,
     * because without one the 2000-row budget was spent on older sessions first
     * and the CURRENT session — the only one being reconciled — was exactly the
     * one truncated away.
     *
     * reconcileClassFromMatrix() no longer reads or writes anything. Subject
     * assignment changes now mark the affected draft/candidate verdicts stale
     * instead of rewriting live timetable teachers (approved Option B), so the
     * original defect cannot recur here: the query is gone.
     *
     * These are therefore not relaxed but tightened. An absent query cannot be
     * mis-scoped, and asserting there is NO query forbids re-introducing an
     * unscoped one — strictly stronger than the check it replaces.
     */
    public function test_the_retired_reconcile_performs_no_read_at_all(): void
    {
        $fn = $this->reconcile();

        foreach (['firestoreQuery', 'firestoreGet'] as $read) {
            $this->assertStringNotContainsString($read, $fn,
                'the retired assignment sync must not read timetable data. It '
                . 'should not be re-introduced at all: assignment changes mark '
                . 'verdicts stale, they do not reconcile timetable content.');
        }
    }

    /**
     * The in-PHP session check was what made the original reconcile tenant-safe.
     * With the method retired there is no row to check, so that guarantee is now
     * carried by the method writing nothing whatsoever.
     */
    public function test_the_retired_reconcile_performs_no_write_at_all(): void
    {
        $fn = $this->reconcile();

        foreach (['firestoreUpdate', 'firestoreSet', 'firestoreDelete'] as $write) {
            $this->assertStringNotContainsString($write, $fn,
                'a change to who teaches what must never edit a timetable: a '
                . 'published one is immutable, and a draft or candidate is '
                . "somebody's unfinished work");
        }
        $this->assertStringContainsString("'retired' => true", $fn,
            'and a caller must be able to tell the difference between "nothing '
            . 'needed changing" and "this no longer changes anything"');
    }

    /** The fix must not have quietly created an index requirement. */
    public function test_the_required_composite_index_is_already_deployed(): void
    {
        $p = __DIR__ . '/../../firebase-rules/firestore.indexes.json';
        $this->assertFileExists($p);

        $json = json_decode((string) file_get_contents($p), true);
        $this->assertIsArray($json);

        $found = false;
        foreach (($json['indexes'] ?? []) as $ix) {
            if (($ix['collectionGroup'] ?? '') !== 'timetables') continue;
            $fields = array_column($ix['fields'] ?? [], 'fieldPath');
            if ($fields === ['schoolId', 'session']) { $found = true; break; }
        }

        $this->assertTrue($found,
            'timetables(schoolId, session) is missing from firestore.indexes.json. '
            . 'The reconcile query now needs it, and shipping the code without the '
            . 'index deployed would break reconciliation outright.');
    }
}
