<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * A TRANSIENT attendanceSettings read failure must not be cached as "no
 * settings" for the 5-minute TTL.
 *
 * ── THE CLASS OF BUG (same as the getDocument negative-cache trap) ────────
 *
 * `_get_attendance_settings()` read attendanceSettings/{schoolId} through the
 * plain `$this->fs->get()`, which returns null for BOTH a genuine 404 (a school
 * that never configured settings) AND a swallowed transient failure (429/500/
 * network). It then merged DEFAULTS and cached them for ATT_SETTINGS_CACHE_TTL.
 * On a transient blip, a configured school's real settings — including
 * attendanceSettings.studentCalendar, the sole non-working-day authority — were
 * dropped for the whole 5-minute window, so holidays read as working days.
 *
 * THE FIX: read through firestoreGetOrFail, which returns null ONLY on a genuine
 * 404 and THROWS on a transient/non-404 failure. A genuine result (doc or 404)
 * is cached; a transient failure is served as defaults for the current request
 * ONLY and never cached, so the next request retries.
 *
 * Source-assertion, matching the other offline RBAC/settings tests in this
 * suite — Attendance is a CI controller and cannot be instantiated offline.
 */
final class AttendanceSettingsTransientCacheTest extends TestCase
{
    private static string $src = '';
    private static string $method = '';

    public static function setUpBeforeClass(): void
    {
        self::$src = (string) file_get_contents(
            __DIR__ . '/../../application/controllers/Attendance.php');

        // Isolate the _get_attendance_settings method body.
        $i = strpos(self::$src, 'function _get_attendance_settings');
        self::assertNotFalse($i, '_get_attendance_settings is gone');
        // grab a generous window that covers the whole method
        self::$method = substr(self::$src, $i, 2600);
    }

    /** Strip // and # line comments and /* *\/ blocks so a comment mentioning a
     *  removed call cannot satisfy (or fail) a source assertion. */
    private function code(string $s): string
    {
        $out = '';
        foreach (token_get_all("<?php\n" . $s) as $t) {
            if (is_array($t)) {
                if (in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true)) continue;
                $out .= $t[1];
            } else {
                $out .= $t;
            }
        }
        return preg_replace('/\s+/', ' ', $out);
    }

    public function test_reads_settings_through_the_fail_loud_primitive(): void
    {
        $code = $this->code(self::$method);
        $this->assertStringContainsString(
            "getDocumentOrFail('attendanceSettings'", $code,
            'attendanceSettings must be read via getDocumentOrFail so a transient '
            . 'failure is distinguishable from a genuine 404');
    }

    public function test_does_not_use_the_swallowing_get_for_settings(): void
    {
        $code = $this->code(self::$method);
        $this->assertDoesNotMatchRegularExpression(
            "~->fs->get\(\s*'attendanceSettings'~", $code,
            'the plain ->fs->get() swallows a transient failure to null, which is '
            . 'exactly the negative-cache trap this method must avoid');
    }

    public function test_a_transient_failure_is_not_cached(): void
    {
        $code = $this->code(self::$method);
        // The cache save must be guarded so it does not run on the transient path.
        $this->assertMatchesRegularExpression(
            "~if\s*\(\s*!\s*\\\$transient\s*\)\s*\{[^}]*cache->save~", $code,
            'the settings cache->save must be guarded by !$transient, or a '
            . 'transient failure pins default settings for the full TTL');
    }

    public function test_a_genuine_result_is_still_cached(): void
    {
        // The cache save must still be present (a genuine 404 / real doc caches).
        $this->assertStringContainsString('cache->save', $this->code(self::$method),
            'the read-through cache must remain for genuine results');
    }
}
