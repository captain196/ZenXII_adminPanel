<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * The teacher app renders this endpoint's `message` verbatim on screen.
 *
 * OBSERVED ON A REAL DEVICE: "My Correction Requests" showed
 *
 *     Unable to resolve school/session from token.
 *
 * in red as the whole content of the screen. That sentence describes our token
 * plumbing. A teacher cannot act on it, and it names internals ("token") that
 * a user-facing surface should not discuss.
 *
 * The 409 itself is correct — the request genuinely cannot be served without a
 * resolvable tenant context. Only the wording changed, and the diagnostic
 * detail (which of school_id / session_year was missing, and for which uid)
 * moved into the log, where it is actually useful.
 */
final class StaffAttendanceTokenErrorWordingTest extends TestCase
{
    private static string $src = '';

    public static function setUpBeforeClass(): void
    {
        self::$src = (string) file_get_contents(
            __DIR__ . '/../../application/controllers/Staff_attendance.php');
    }

    public static function tearDownAfterClass(): void
    {
        self::$src = '';
    }

    /** The guard block that answers the app when tenant context is missing. */
    private static function guardBlock(): string
    {
        $i = strpos(self::$src, "if (\$this->school_id === '' || \$this->session_year === '')");
        if ($i === false) {
            throw new RuntimeException('locator stale — the tenant-context guard moved');
        }
        $j = strpos(self::$src, 'exit;', (int) $i);

        return substr(self::$src, (int) $i, ((int) $j) - ((int) $i));
    }

    // ── What the user is told ────────────────────────────────────────────

    /** The technical sentence must be gone from the response. */
    public function test_the_technical_wording_is_no_longer_returned(): void
    {
        $this->assertStringNotContainsString(
            'Unable to resolve school/session from token', self::guardBlock(),
            'this is the exact string a teacher saw on the device');
    }

    /** Whatever replaced it must not discuss internals either. */
    public function test_the_message_names_no_internals(): void
    {
        $block = self::guardBlock();

        // The message is the value sent to the app; isolate it from the log line.
        $this->assertNotFalse(strpos($block, "'message'"), 'the response must carry a message');

        preg_match("~'message'\s*=>\s*(.+?)(?:,\n|\n\s*\])~s", $block, $m);
        $this->assertNotEmpty($m, 'could not isolate the user-facing message');
        $message = $m[1];

        foreach (['token', 'session_year', 'school_id', 'claims', 'Firestore', 'null'] as $internal) {
            $this->assertStringNotContainsStringIgnoringCase($internal, $message,
                "the user-facing message must not mention '{$internal}' — got: {$message}");
        }
    }

    /** And it must tell the user what to do about it. */
    public function test_the_message_is_actionable(): void
    {
        preg_match("~'message'\s*=>\s*(.+?)(?:,\n|\n\s*\])~s", self::guardBlock(), $m);
        $message = $m[1] ?? '';

        $this->assertMatchesRegularExpression('~try again|sign (out|in)~i', $message,
            'an error a teacher cannot act on is not finished — got: ' . $message);
    }

    // ── What the operator is told ────────────────────────────────────────

    /**
     * The detail that got removed from the screen must still exist somewhere.
     * Silently dropping it would trade one defect for another.
     */
    public function test_the_real_reason_is_still_logged(): void
    {
        $block = self::guardBlock();

        $this->assertStringContainsString('log_message(', $block,
            'the diagnostic detail must survive in the log');
        $this->assertStringContainsString('school_id=[', $block);
        $this->assertStringContainsString('session_year=[', $block);
    }

    /**
     * CI3 has no 'warning' log level — those lines are silently discarded, so
     * a log written at that level would be no log at all.
     */
    public function test_the_log_uses_a_level_ci3_actually_emits(): void
    {
        $block = self::guardBlock();

        $this->assertStringNotContainsString("log_message('warning'", $block,
            "CI3 drops 'warning'; this line would never be written");
        $this->assertStringContainsString("log_message('error'", $block);
    }

    /** The status code was already right and must not drift. */
    public function test_the_status_code_is_unchanged(): void
    {
        $this->assertStringContainsString('http_response_code(409)', self::guardBlock(),
            'the request genuinely cannot be served — 409 stays');
    }
}
