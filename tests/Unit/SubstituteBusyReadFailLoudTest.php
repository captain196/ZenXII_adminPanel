<?php
/**
 * "IS THIS TEACHER ALREADY COVERING SOMETHING?"
 *
 * Two endpoints answer that question, and both answer it NEGATIVELY: a teacher
 * who does not appear among the existing covers is offered as free. So a read
 * that fails and returns nothing does not produce a cautious answer — it
 * produces the most dangerous one, every teacher free, offered with confidence.
 *
 * Both reads went through sessionWhere(), which routes to firestoreQuery() and
 * returns [] for a missing index, a network failure, 403 and 429 alike. None of
 * those throw, so the catch each site had written for exactly this could never
 * run.
 *
 * A controller is awkward to instantiate here, so this test reads the source.
 * It is deliberately narrow: it asserts the SHAPE that makes the failure
 * reachable, not the wording around it.
 */

use PHPUnit\Framework\TestCase;

final class SubstituteBusyReadFailLoudTest extends TestCase
{
    private const FILE = __DIR__ . '/../../application/controllers/Academic.php';

    /** Comments naming a call must not satisfy an assertion about that call. */
    private function codeOnly(): string
    {
        $src = (string) file_get_contents(self::FILE);
        $this->assertNotSame('', $src, 'Academic.php could not be read');

        $out = '';
        foreach (token_get_all($src) as $t) {
            if (is_array($t)) {
                if ($t[0] === T_COMMENT || $t[0] === T_DOC_COMMENT) { continue; }
                $out .= $t[1];
            } else {
                $out .= $t;
            }
        }
        return $out;
    }

    /**
     * Split a read's window into the bound check and the catch block, so an
     * assertion about one cannot be satisfied by the other.
     *
     * @return array{0:string,1:string} [bound check, catch block]
     */
    private function boundAndCatch(string $window): array
    {
        $c = strpos($window, 'catch');
        if ($c === false) { return [$window, '']; }

        $open = strpos($window, '{', $c);
        if ($open === false) { return [substr($window, 0, $c), '']; }

        $depth = 0; $end = strlen($window);
        for ($i = $open; $i < strlen($window); $i++) {
            if ($window[$i] === '{') { $depth++; }
            elseif ($window[$i] === '}') { $depth--; if ($depth === 0) { $end = $i; break; } }
        }

        return [substr($window, 0, $c), substr($window, $open, $end - $open + 1)];
    }

    /** Every place that asks who is already covering must be able to fail. */
    public function test_no_existing_cover_read_goes_through_the_silent_helper(): void
    {
        $code = $this->codeOnly();

        $this->assertSame(0,
            preg_match_all("~sessionWhere\(\s*'substitutes'~", $code),
            'an existing-cover read still goes through sessionWhere(), which '
            . 'returns [] on failure — so every already-booked teacher is '
            . 'offered as free and the catch written for it never runs');
    }

    public function test_both_sites_read_the_covers_fail_loud(): void
    {
        $code = $this->codeOnly();

        $this->assertSame(2,
            preg_match_all("~firestoreQueryOrFail\(\s*'substitutes'~", $code),
            'both the teacher-schedule and absent-teacher-schedule endpoints '
            . 'must read the existing covers fail-loud');
    }

    /** Each of those reads is scoped to the school AND the session. */
    public function test_the_cover_reads_are_tenant_and_session_scoped(): void
    {
        $code = $this->codeOnly();

        $off = 0; $seen = 0;
        while (($i = strpos($code, "firestoreQueryOrFail('substitutes'", $off)) !== false) {
            $off = $i + 1; $seen++;
            $window = substr($code, $i, 300);

            $this->assertMatchesRegularExpression("~\['schoolId',\s*'=='~", $window,
                "existing-cover read #{$seen} is not scoped to the school");
            $this->assertMatchesRegularExpression("~\['session',\s*'=='~", $window,
                "existing-cover read #{$seen} is not scoped to the session");
        }
        $this->assertSame(2, $seen);
    }

    /**
     * A bounded read that hit its bound has not proved anybody is free either.
     * Both sites must notice the bound as well as the failure.
     */
    public function test_a_saturated_or_failed_read_is_reported_as_incomplete(): void
    {
        $code = $this->codeOnly();

        $off = 0; $seen = 0;
        while (($i = strpos($code, "firestoreQueryOrFail('substitutes'", $off)) !== false) {
            $off = $i + 1; $seen++;
            $window = substr($code, $i, 2600);

            $this->assertMatchesRegularExpression(
                '~>\s*self::SUBSTITUTE_SCAN_LIMIT~', $window,
                "existing-cover read #{$seen} does not check whether it hit its bound");

            // Hitting the bound and failing outright are two different events,
            // and each has to set the flag for itself. Looking anywhere in the
            // window lets the bound check alone satisfy the assertion while the
            // catch quietly reports a failed read as a complete answer.
            [$bound, $catch] = $this->boundAndCatch($window);

            $this->assertMatchesRegularExpression(
                '~\$(busyComplete|complete)\s*=\s*false;~', $bound,
                "existing-cover read #{$seen} hits its bound without saying the "
                . 'answer is partial');

            $this->assertNotSame('', $catch,
                "existing-cover read #{$seen} has no catch block");

            $this->assertMatchesRegularExpression(
                '~\$(busyComplete|complete)\s*=\s*false;~', $catch,
                "existing-cover read #{$seen} does not report incompleteness when "
                . 'the read FAILS, so the failure reaches the screen as a full answer');

            $this->assertDoesNotMatchRegularExpression(
                '~catch\s*\([^)]*\)\s*\{\s*\}~', $window,
                "existing-cover read #{$seen} sits inside an empty catch");
        }
        $this->assertSame(2, $seen);
    }

    /**
     * Whoever asks must be TOLD the answer may be partial. Without this the
     * caller cannot tell "free" from "we could not find out" — which is the
     * whole point of the completeness contract (ACAD-D13).
     */
    public function test_the_teacher_schedule_endpoint_returns_its_completeness(): void
    {
        $code = $this->codeOnly();

        $i = strpos($code, "'busy_periods'");
        $this->assertNotFalse($i, 'the teacher-schedule response is not there');

        $this->assertMatchesRegularExpression(
            "~'completeness'\s*=>~", substr($code, $i, 600),
            'busy_periods is read negatively by the caller, so the response must '
            . 'say whether the covers behind it were read completely');
    }
}
