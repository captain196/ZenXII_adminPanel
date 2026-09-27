<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * NOTICE-D1 — phantom success on the Staff Lifecycle screens.
 *
 * CLAUDE.md names this trap explicitly:
 *
 *   "Phantom success. fetch() doesn't reject on 403/500. Panel JS must check
 *    r.ok AND {status: 'error'} before rendering success, or a denied action
 *    reports as done. Fail closed."
 *
 * The resignation, notice-decision, withdraw, clearance and onboarding handlers
 * all guarded with `if (res && res.status)` -- a TRUTHINESS test. Every refusal
 * in this codebase is {status: 'error', message: '...'}, and the string 'error'
 * is truthy, so a rejected action rendered a green success toast and reloaded
 * the panel as though it had worked.
 *
 * Found by being refused: decide_notice_period rejected decision='approved'
 * (the vocabulary is 'approve'), and the screen would have said
 * "Decision recorded."
 *
 * Note the endpoint returns {status: 'approved'} rather than 'success', so the
 * fix cannot simply compare against 'success' -- it must exclude the failure
 * sentinel instead.
 */
final class PanelHandlersFailClosedTest extends TestCase
{
    /** Views carrying the lifecycle handlers this defect was found in. */
    private const GUARDED = [
        'application/views/edit_staff.php',
        'application/views/hr/index.php',
    ];

    /** DEFECT NOTICE-D1: a truthiness test treats 'error' as success. */
    public function test_no_handler_accepts_a_bare_truthy_status(): void
    {
        $offenders = [];

        foreach (self::GUARDED as $rel) {
            $src = (string) file_get_contents(__DIR__ . '/../../' . $rel);

            if (preg_match_all('~if\s*\(\s*(res|r)\s*&&\s*\1\.status\s*\)~', $src, $m, PREG_OFFSET_CAPTURE)) {
                foreach ($m[0] as $hit) {
                    $line = substr_count(substr($src, 0, (int) $hit[1]), "\n") + 1;
                    $offenders[] = basename($rel) . ':' . $line . '  ' . trim($hit[0]);
                }
            }
        }

        $this->assertSame([], $offenders,
            "these handlers render a REFUSAL as success, because {status:'error'} is truthy:\n  "
            . implode("\n  ", $offenders));
    }

    /** The replacement actually excludes the failure sentinel. */
    public function test_the_guard_excludes_the_error_sentinel(): void
    {
        foreach (self::GUARDED as $rel) {
            $src = (string) file_get_contents(__DIR__ . '/../../' . $rel);

            $this->assertMatchesRegularExpression(
                "~\.status\s*!==\s*'error'~", $src,
                basename($rel) . ' has no fail-closed status guard at all');
        }
    }

    /**
     * Why comparing against 'success' would have been wrong: this endpoint
     * answers with the DECISION, not a generic envelope. The guard has to
     * exclude failure rather than whitelist one success word.
     */
    public function test_the_notice_endpoint_really_does_answer_with_the_decision(): void
    {
        $staff = (string) file_get_contents(
            __DIR__ . '/../../application/controllers/Staff.php');

        $i = strpos($staff, 'public function decide_notice_period');
        $this->assertNotFalse($i, 'decide_notice_period() has moved');

        $body = substr($staff, (int) $i, 4000);
        $this->assertMatchesRegularExpression(
            '~\'status\'\s*=>\s*\$statusMap\[\$decision\]~', $body,
            'if this endpoint ever returns a plain success envelope, the guard '
            . 'can be tightened to compare positively instead');
    }
}