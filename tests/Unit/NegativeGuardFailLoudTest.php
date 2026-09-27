<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * ACAD-D12 — guards that reason NEGATIVELY must not read a silent [].
 *
 * Three Academic guards decide "is this teacher free?" from a map they build
 * out of a Firestore read:
 *
 *     if (isset($busyMap[$subId][$pn])) throw ...conflict...   // substitutes
 *     if (isset($dayTimetables[$sk][$pi]['teacher_id'] ...))   // timetable x2
 *
 * Absence from the map means "free". So an EMPTY map approves everything.
 *
 * THE PREMISE THAT BREAKS THEM: FirestoreRestClient::query() does not throw on
 * failure. _requestImpl() returns ['code' => 0, 'body' => []] on a curl error,
 * and query() turns every non-200 — missing composite index
 * (FAILED_PRECONDITION), 403, 429, network — into `return []`.
 * Firebase::firestoreQuery() likewise returns [] when Firestore is
 * uninitialised. The ONLY throw reachable on that path is an OAuth failure.
 *
 * ACAD-D5 therefore wrapped these reads in `try { ... } catch (\Throwable)`
 * and threw a denial from the catch — but that catch was unreachable for every
 * realistic failure mode. The guard kept answering "no conflict" when what it
 * meant was "I could not look".
 *
 * A large school is NOT required to trigger this. One failed read is enough,
 * and a missing composite index makes it permanent.
 *
 * The fix is a fail-loud read (queryOrFail / firestoreQueryOrFail), mirroring
 * the shape queryCursor() already uses, so the existing denials become
 * reachable — plus a saturation check, because a full page is also
 * "cannot evaluate".
 */
final class NegativeGuardFailLoudTest extends TestCase
{
    /**
     * Every fail-loud timetable read, by file.
     *
     * Timetable_service has three: the two conflict scans (which gate a WRITE
     * and must deny) and getMasterTimetable's read (which feeds a DISPLAY and
     * marks the payload incomplete instead — ACAD-D11).
     */
    // ACAD-D23 added a fourth: autoGenerate's existing-day preload, which is what
    // protects manually edited days from being overwritten.
    // The substitute module reads the timetable twice: the busy map that
    // gates a booking, and the day grid behind "the timetable has changed
    // since this cover was arranged".
    private const FAIL_LOUD_READS = [
        'Timetable_service.php'  => 4,
        'Substitute_service.php' => 2,
    ];

    /**
     * EVERY fail-loud read, whatever collection. Substitute_service has a
     * second one on `substitutes` itself: the already-arranged-cover list,
     * read just as negatively as the timetable busy map — a teacher absent
     * from it is treated as free to cover.
     */
    private const ALL_FAIL_LOUD_READS = [
        'Timetable_service.php'  => 4,
        // Seven, and every one of them is read negatively by something:
        //   1 the own-class busy map          4 the day grid behind drift
        //   2 the covers already arranged     5 the week, for a weekly cap
        //   3 the list the screen is drawn    6 the cover log
        //     from                            7 who is away (staff, approved
        //                                       leave, the day's attendance)
        //   8 the most recent policy an earlier session left behind, which is
        //     carried forward so a limit is not lost at the turn of the year
        //   9 the history of one cover, read from the platform Academic audit
        //     trail to answer "who cancelled this?"
        'Substitute_service.php' => 9,
    ];

    /**
     * The subset that must DENY rather than report incompleteness.
     *
     * Chiefly reads that gate a WRITE: marking a result incomplete is not
     * good enough when the next statement persists data. But the same rule
     * covers a read whose EMPTINESS WILL BE ACTED ON — the substitute list
     * is not idle reading, it is what an administrator checks before
     * deciding a period needs cover, so showing it as an empty day is as
     * consequential as approving a write. Reporting incompleteness is for
     * reads that return PARTIAL data; a failed read returns none.
     */
    // The generator preload is a write gate too: it refuses the whole run rather
    // than overwrite days it cannot prove were untouched.
    private const WRITE_GATES = [
        'Timetable_service.php'  => 3,
        // Six deny. The seventh — the day grid behind "the timetable has
        // changed since this cover was arranged" — returns null instead,
        // because it BUILDS something and has no payload to mark incomplete.
        // Null is the same statement in another grammar: I cannot say. Its
        // caller turns that into timetableChecked=false and claims nothing.
        'Substitute_service.php' => 8,
    ];

    private function lib(string $f): string
    {
        $p = __DIR__ . '/../../application/libraries/' . $f;
        $this->assertFileExists($p);
        return (string) file_get_contents($p);
    }

    /** Comments naming a call must not satisfy assertions about that call. */
    private function codeOnly(string $f): string
    {
        $s = preg_replace('~/\*.*?\*/~s', '', $this->lib($f));
        return (string) preg_replace('~^\s*//.*$~m', '', (string) $s);
    }

    /**
     * The body of the catch block that follows $from, matched by braces.
     *
     * A fixed-size window is not good enough here: the substitute guard's catch
     * is immediately followed by the SATURATION check, which also throws a
     * Service_exception. A window would credit that throw to the catch and
     * report a denial that had actually been removed — which is exactly how a
     * downgraded gate survived the first two versions of this test.
     */
    private function catchBlockAfter(string $code, int $from): string
    {
        $c = strpos($code, 'catch', $from);
        if ($c === false) return '';

        $open = strpos($code, '{', $c);
        if ($open === false) return '';

        // A try may be followed by SEVERAL catch clauses — Substitute_service's
        // existing-cover read has `catch (Service_exception) { throw $e; }`
        // ahead of its \Throwable denial. Returning only the first would read
        // the rethrow and conclude the gate no longer denies. Span them all.
        $blocks = '';
        while (true) {
            $depth = 0; $close = -1;
            for ($p = $open, $n = strlen($code); $p < $n; $p++) {
                if ($code[$p] === '{') $depth++;
                elseif ($code[$p] === '}') {
                    $depth--;
                    if ($depth === 0) { $close = $p; break; }
                }
            }
            if ($close === -1) break;
            $blocks .= substr($code, $open, $close - $open + 1);

            $rest = ltrim(substr($code, $close + 1));
            if (strpos($rest, 'catch') !== 0) break;
            $c2 = strpos($code, 'catch', $close + 1);
            $open = strpos($code, '{', $c2);
            if ($open === false) break;
        }
        return $blocks;
    }

    // -- the premise ----------------------------------------------------

    /** query() must still return [] by default — hundreds of callers rely on it. */
    public function test_the_plain_query_still_swallows_failure_for_existing_callers(): void
    {
        $c = $this->codeOnly('Firestore_rest_client.php');

        $i = strpos($c, 'FirestoreREST::query $collection HTTP');
        $this->assertNotFalse($i, 'the non-200 branch of query() is gone');

        $this->assertStringContainsString('return [];', substr($c, $i, 700),
            'query() must keep returning [] by default; making it throw outright '
            . 'would break every caller that reads [] as "no rows"');
    }

    /** The transport reports failure as a code, never an exception. */
    public function test_the_transport_reports_failure_as_a_code_not_an_exception(): void
    {
        $c = $this->codeOnly('Firestore_rest_client.php');

        $i = strpos($c, 'private function _requestImpl');
        $this->assertNotFalse($i, '_requestImpl is gone');

        $this->assertMatchesRegularExpression(
            "~return \['code'\s*=>~", substr($c, $i, 4000),
            'if _requestImpl started throwing instead of returning a code, the '
            . 'fail-loud plumbing below would be redundant and should be revisited');
    }

    // -- the fix --------------------------------------------------------

    public function test_a_fail_loud_read_exists_at_both_layers(): void
    {
        $rest = $this->codeOnly('Firestore_rest_client.php');
        $fb   = $this->codeOnly('Firebase.php');

        $this->assertStringContainsString('public function queryOrFail(', $rest);
        $this->assertMatchesRegularExpression(
            '~queryOrFail\([^{]*\{\s*return \$this->query\([^;]*,\s*true\s*\)~s', $rest,
            'queryOrFail must delegate to query() with failLoud = true');

        $this->assertStringContainsString('public function firestoreQueryOrFail(', $fb);
        $this->assertMatchesRegularExpression(
            '~firestoreQueryOrFail.*?firestoreDb === null.*?throw new \\\\RuntimeException~s', $fb,
            'an uninitialised client is also a failed read and must throw, not return []');
    }

    public function test_fail_loud_actually_throws_on_a_non_200(): void
    {
        $c = $this->codeOnly('Firestore_rest_client.php');

        $i = strpos($c, 'FirestoreREST::query $collection HTTP');
        $this->assertNotFalse($i);
        $branch = substr($c, $i, 700);

        $t = strpos($branch, 'throw new \RuntimeException');
        $r = strpos($branch, 'return [];');

        $this->assertNotFalse($t, 'failLoud does not throw on a non-200');
        $this->assertNotFalse($r, 'the default return is gone');
        $this->assertLessThan($r, $t,
            'the throw must precede the return, or failLoud still returns []');
        $this->assertStringContainsString('$failLoud', $branch,
            'the throw must be gated on failLoud so default callers are unaffected');
    }

    // -- the guards -----------------------------------------------------

    /** Every timetable read that is reasoned over must be fail-loud. */
    public function test_every_timetable_guard_read_is_fail_loud(): void
    {
        foreach (self::FAIL_LOUD_READS as $file => $expected) {
            $code = $this->codeOnly($file);

            $n = preg_match_all(
                '~firestoreQueryOrFail\(\s*(self::COLLECTION_TT|.timetables.)~', $code);

            $this->assertSame($expected, $n,
                "{$file}: expected {$expected} fail-loud timetable read(s), found {$n}. "
                . 'A read still on firestoreQuery() cannot tell a failed read from '
                . '"no rows", so it either approves a write or reports partial data '
                . 'as complete.');
        }
    }

    /**
     * A fail-loud read must be HANDLED — never swallowed. Two handlings are
     * legitimate, and which one is correct depends on what the read gates:
     *
     *   throw new Service_exception  — the next statement writes; deny.
     *   $complete = false            — the read feeds a display; report it
     *                                  as incomplete (ACAD-D11).
     *   return null                  — the read BUILDS something, and the
     *                                  builder has no payload to flag. Null
     *                                  is the same statement in a different
     *                                  grammar: "I cannot say". It counts
     *                                  only when the catch returns it, never
     *                                  a null further down the method.
     *
     * An empty catch is neither, and puts back the exact silent-[] behaviour
     * the fail-loud read was introduced to remove.
     */
    public function test_no_fail_loud_read_is_swallowed(): void
    {
        foreach (self::ALL_FAIL_LOUD_READS as $file => $expected) {
            $code = $this->codeOnly($file);

            $off = 0; $seen = 0;
            while (($i = strpos($code, 'firestoreQueryOrFail', $off)) !== false) {
                $off = $i + 1; $seen++;
                $after = substr($code, $i, 1400);

                $this->assertDoesNotMatchRegularExpression(
                    '~catch\s*\([^)]*\)\s*\{\s*\}~', $after,
                    "{$file}: a fail-loud read sits inside an empty catch, which "
                    . 'restores exactly the silent-[] behaviour it was meant to remove');

                $handled = strpos($after, 'throw new Service_exception') !== false
                        || preg_match('~\$complete\s*=\s*false;~', $after) === 1
                        || preg_match('~catch\s*\([^)]*\)\s*\{[^}]*return null;~s', $after) === 1;

                $this->assertTrue($handled,
                    "{$file}: fail-loud read #{$seen} is neither denied nor marked "
                    . 'incomplete, so the failure reaches the caller as ordinary data');
            }

            $this->assertSame($expected, $seen,
                "{$file}: expected to inspect {$expected} fail-loud read(s), saw {$seen}");
        }
    }

    /**
     * The subset that gates a WRITE must DENY. For those, "record that the read
     * failed and carry on" would still persist the row.
     *
     * Each read is examined in its OWN window, bounded by the next fail-loud
     * read. A lazy match across the whole file would happily pair a failed
     * read with some later, unrelated throw and report a denial that is not
     * there — which is exactly how a downgraded gate slipped past the first
     * version of this test.
     */
    public function test_every_write_gate_denies_on_a_failed_read(): void
    {
        foreach (self::ALL_FAIL_LOUD_READS as $file => $expected) {
            $code = $this->codeOnly($file);

            $denies = 0; $flags = 0; $seen = 0;
            $off = 0;
            while (($i = strpos($code, 'firestoreQueryOrFail', $off)) !== false) {
                $off = $i + 1; $seen++;

                $block = $this->catchBlockAfter($code, $i);
                $this->assertNotSame('', $block,
                    "{$file}: read #{$seen} has no catch block");

                if (strpos($block, 'throw new Service_exception') !== false) {
                    $denies++;
                } elseif (preg_match('~\$complete\s*=\s*false;~', $block) === 1
                          || strpos($block, 'return null;') !== false) {
                    $flags++;
                }
            }

            $this->assertSame($expected, $seen,
                "{$file}: expected {$expected} fail-loud read(s), saw {$seen}");

            $this->assertSame(self::WRITE_GATES[$file], $denies,
                "{$file}: expected exactly " . self::WRITE_GATES[$file]
                . " write-gating read(s) whose OWN catch denies, found {$denies}. "
                . 'A write gate that merely records incompleteness still writes '
                . 'the row.');

            $this->assertSame($expected - self::WRITE_GATES[$file], $flags,
                "{$file}: the remaining read(s) must report incompleteness to the "
                . 'caller rather than deny, since they feed a display');
        }
    }

    /** A full page is also "cannot evaluate" and must deny. */
    public function test_every_negative_guard_denies_on_saturation(): void
    {
        // Every scan cap, per file. Substitute_service has TWO: the own-timetable
        // busy map and the already-arranged-cover list. Listing only one left the
        // other free to truncate silently.
        $expect = [
            'Timetable_service.php'  => ['CONFLICT_SCAN_LIMIT'],
            'Substitute_service.php' => ['BUSY_SCAN_LIMIT', 'EXISTING_SUB_SCAN_LIMIT'],
        ];

        foreach ($expect as $file => $consts) {
        foreach ($consts as $const) {
            $code = $this->codeOnly($file);

            $this->assertMatchesRegularExpression(
                '~const ' . $const . '\s*=\s*\d+~', $code, "{$file}: {$const} is gone");

            $this->assertMatchesRegularExpression(
                // Two correct shapes, depending on how the read was sized:
                //   read exactly the cap  -> saturated at  count >= LIMIT
                //   read cap+1 as a probe -> saturated at  count >  LIMIT
                // The probe form is the better one (it can tell "exactly full"
                // from "truncated"), so accept either rather than force a shape.
                '~count\(\$\w+\)\s*>=?\s*self::' . $const . '~', $code,
                "{$file}: {$const} has no saturation check — a truncated page "
                . 'reads as proof of no clash');
        }
        }
    }

    /** The thing being protected must still be there. */
    public function test_the_substitute_busy_map_is_still_read_negatively(): void
    {
        $code = $this->codeOnly('Substitute_service.php');

        $this->assertMatchesRegularExpression(
            '~isset\(\$busyMap\[\$subId\]\[\$pn\]\)\)\s*\{?\s*throw new Service_exception~',
            $code,
            'the double-booking check is gone — without it the fail-loud plumbing '
            . 'above protects nothing');
    }
}
