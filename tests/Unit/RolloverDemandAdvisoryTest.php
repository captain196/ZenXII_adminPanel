<?php

use PHPUnit\Framework\TestCase;

if (!defined('APPPATH')) define('APPPATH', __DIR__ . '/../../application/');
if (!defined('BASEPATH')) define('BASEPATH', 'x');

/**
 * ══════════════════════════════════════════════════════════════════════════
 *  A ROLLOVER MUST SAY THAT THE PROMOTED STUDENTS OWE NOTHING YET
 * ══════════════════════════════════════════════════════════════════════════
 *
 * ── LC-14 · THE GAP ──────────────────────────────────────────────────────
 * `School_config::rollover_session()` moves students into the next session
 * and deliberately raises NO fee demands there. That is correct: the
 * destination session's fee structures may not exist yet, and admission is
 * itself fail-closed on them, so generating demands during rollover would
 * either fail or invent amounts.
 *
 * What was missing was telling anyone. The success message read
 * "12 sections copied, 3 students promoted" and stopped — leaving every
 * promoted student Active in the new session, owing nothing, with no
 * indication that a further step was required.
 *
 * ── WHY THIS IS NOT COSMETIC ─────────────────────────────────────────────
 * The codebase names this state as the danger itself: "Active-with-zero-
 * demands". `Sis::execute_promotion()` guards it with a deferred fee
 * regeneration job (LC-5) whose interruption is detectable via
 * `promote_jobs.promotedStudentIds`.
 *
 * The rollover path has NO such job — confirmed at runtime on the emulator,
 * where a rollover promoted 4 students and left `promote_jobs` empty with
 * every demand still belonging to the source session. On this path the
 * OPERATOR is the recovery mechanism, so the operator has to be told.
 *
 * ── SCOPE ────────────────────────────────────────────────────────────────
 * The endpoint cannot be executed here (it needs the framework, a tenant and
 * Firestore), so the message construction is asserted on comment-stripped
 * source, with a control. The end-to-end proof is the emulator run, where the
 * advisory appears in the real JSON response.
 */
final class RolloverDemandAdvisoryTest extends TestCase
{
    private static string $src = '';

    public static function setUpBeforeClass(): void
    {
        $out = '';
        foreach (token_get_all((string) file_get_contents(APPPATH . 'controllers/School_config.php')) as $t) {
            if (is_array($t)) {
                if (in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true)) continue;
                $out .= $t[1];
            } else { $out .= $t; }
        }
        self::$src = (string) preg_replace('/\s+/', ' ', $out);
    }

    public function test_the_comment_stripper_actually_strips(): void
    {
        // Control: this fix's own comments contain the phrases asserted below.
        $this->assertStringNotContainsString('LC-14', self::$src);
        $this->assertStringNotContainsString('Active-with-zero-demands', self::$src);
    }

    public function test_the_operator_is_told_demands_do_not_exist_yet(): void
    {
        $this->assertStringContainsString('no fee demands in', self::$src,
            'the rollover reports success without saying that promoted students '
            . 'have no fee book in the destination session');
    }

    public function test_the_advisory_names_the_destination_session(): void
    {
        // "no demands yet" is not actionable without saying WHERE.
        $this->assertMatchesRegularExpression(
            "/'\s*Promoted students have no fee demands in '\s*\.\s*\\\$to/", self::$src,
            'the advisory does not name the destination session');
    }

    public function test_the_advisory_says_what_to_do_next(): void
    {
        // A warning that does not name the remedy just worries the operator.
        $this->assertStringContainsString('set up the fee structure for', self::$src,
            'the advisory does not tell the operator how to resolve the state');
        $this->assertStringContainsString('generate demands', self::$src);
    }

    public function test_the_advisory_only_appears_when_students_moved(): void
    {
        // A rollover that copied sections but promoted nobody has no such
        // problem, and warning about it would train operators to ignore it.
        $at = strpos(self::$src, 'Promoted students have no fee demands in');
        $this->assertNotFalse($at);
        $window = substr(self::$src, max(0, $at - 220), 260);
        $this->assertMatchesRegularExpression(
            "/if\s*\(\s*\\\$summary\['students_promoted'\]\s*>\s*0\s*\)/", $window,
            'the advisory is not conditional on students actually having been promoted');
    }

    public function test_the_advisory_is_attached_to_the_operator_message(): void
    {
        // It must ride the message the operator actually sees, not only the
        // summary payload, which the UI may never render.
        $at = strpos(self::$src, 'Promoted students have no fee demands in');
        $window = substr(self::$src, max(0, $at - 60), 120);
        $this->assertStringContainsString('$msg .=', $window,
            'the advisory is not appended to the operator-facing message');
    }

    public function test_rollover_still_does_not_generate_demands(): void
    {
        // The fix is an advisory, NOT a behaviour change. Silently generating
        // demands here would bill students against fee structures that may not
        // exist for the destination session — the opposite of the fail-closed
        // contract admission already enforces.
        $at = strpos(self::$src, 'Rollover ');
        $this->assertNotFalse($at);
        $window = substr(self::$src, max(0, $at - 3000), 3600);
        foreach (['generateDemands', 'assignInitialFees', 'regenerateFees'] as $generator) {
            $this->assertStringNotContainsString($generator, $window,
                "rollover now calls {$generator} — this fix was meant to REPORT the "
                . 'missing fee book, not create one against a possibly-unconfigured session');
        }
    }
}
