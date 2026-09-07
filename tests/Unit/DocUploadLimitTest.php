<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * The upload cap the code declares and the cap the server enforces were different
 * numbers, and the message named the wrong failure.
 *
 * Observed live: a 3.97 MB PNG — comfortably under this controller's own 4 MiB
 * ASSET_MAX_BYTES — was refused with "No file was uploaded". PHP's
 * `upload_max_filesize` was 2M and stopped it long before the application's size
 * check could run, so `$_FILES['file']['error']` was UPLOAD_ERR_INI_SIZE and every
 * non-OK code collapsed into the one message about a file that never arrived.
 *
 * Two defects in one line:
 *
 *  1. The declared cap was UNREACHABLE. Nothing between 2 MB and 4 MiB could ever
 *     reach the application's check, so the constant described a promise the
 *     deployment did not keep.
 *  2. The message described the WRONG FAILURE. A user told nothing was uploaded
 *     retries the same file; a user told it is too large picks a smaller one.
 *
 * The fix reports the EFFECTIVE limit — the smallest of the application's constant,
 * `upload_max_filesize` and `post_max_size` — because php.ini is deployment-specific
 * and quoting a constant the server will not honour is how the first defect happened.
 *
 * qa/certificates/06-uat-matrix.csv T2-44
 */
final class DocUploadLimitTest extends TestCase
{
    private static string $src;

    public static function setUpBeforeClass(): void
    {
        $src = @file_get_contents(__DIR__ . '/../../application/controllers/Doc_templates.php');
        self::assertNotFalse($src, 'Doc_templates.php is unreadable');
        self::$src = (string) $src;
    }

    private function call(string $name, ...$args)
    {
        $m = new ReflectionMethod('Doc_templates_probe', $name);
        $m->setAccessible(true);
        return $m->invoke(null, ...$args);
    }

    /** php.ini shorthand must decode, or the effective cap is silently wrong. */
    public function test_ini_shorthand_decodes_to_bytes(): void
    {
        $this->assertSame(2097152,    $this->call('iniBytes', '2M'));
        $this->assertSame(8388608,    $this->call('iniBytes', '8M'));
        $this->assertSame(524288,     $this->call('iniBytes', '512K'));
        $this->assertSame(1073741824, $this->call('iniBytes', '1G'));
        $this->assertSame(4096,       $this->call('iniBytes', '4096'));
    }

    /** An unset or unlimited directive must not be read as a cap of zero. */
    public function test_an_empty_directive_is_not_a_cap_of_zero(): void
    {
        $this->assertSame(0, $this->call('iniBytes', ''),
            'An empty directive returning anything but 0 would let it win the min() and '
            . 'cap every upload at nothing.');
    }

    /** The reported cap is the smallest real limit, never the biggest hope. */
    public function test_the_effective_cap_never_exceeds_the_php_limit(): void
    {
        $eff = $this->call('effectiveMaxBytes');
        $php = $this->call('iniBytes', (string) ini_get('upload_max_filesize'));
        if ($php > 0) {
            $this->assertLessThanOrEqual($php, $eff,
                'The effective cap exceeds upload_max_filesize, so the message quotes a size '
                . 'PHP will refuse — the original defect.');
        }
        $this->assertLessThanOrEqual(4194304, $eff, 'The effective cap exceeds the application constant.');
        $this->assertGreaterThan(0, $eff);
    }

    public function test_sizes_are_reported_in_units_a_person_reads(): void
    {
        $this->assertSame('2 MB',   $this->call('humanBytes', 2097152));
        $this->assertSame('4 MB',   $this->call('humanBytes', 4194304));
        $this->assertSame('1.5 MB', $this->call('humanBytes', 1572864));
        $this->assertSame('512 KB', $this->call('humanBytes', 524288));
    }

    /** Each upload failure must be distinguishable, which is the whole point. */
    public function test_a_size_rejection_no_longer_claims_nothing_was_uploaded(): void
    {
        $this->assertMatchesRegularExpression(
            '/UPLOAD_ERR_INI_SIZE\s*,\s*UPLOAD_ERR_FORM_SIZE\s*=>/',
            self::$src,
            'A size rejection is not distinguished from a missing file. A user told nothing '
            . 'was uploaded retries the same file.'
        );
        $this->assertStringContainsString('UPLOAD_ERR_PARTIAL', self::$src, 'An interrupted upload is not distinguished.');
        $this->assertStringContainsString('UPLOAD_ERR_NO_TMP_DIR', self::$src, 'A server-side storage failure is not distinguished.');
    }

    /** The user-facing text must quote the effective limit, not the constant. */
    public function test_the_message_quotes_the_effective_limit(): void
    {
        $this->assertStringNotContainsString(
            "(self::ASSET_MAX_BYTES / 1048576) . ' MB'",
            self::$src,
            'The message still quotes the constant. On a deployment where php.ini is lower '
            . 'that names a size the server refuses.'
        );
        $this->assertStringContainsString('effectiveMaxBytes()', self::$src);
    }
}

/** The controller cannot be loaded without CodeIgniter, so mirror the two pure helpers. */
final class Doc_templates_probe
{
    const ASSET_MAX_BYTES = 4194304;

    private static function effectiveMaxBytes(): int
    {
        $caps = [self::ASSET_MAX_BYTES];
        foreach (['upload_max_filesize', 'post_max_size'] as $k) {
            $v = self::iniBytes((string) ini_get($k));
            if ($v > 0) {
                $caps[] = $v;
            }
        }
        return min($caps);
    }

    private static function iniBytes(string $v): int
    {
        $v = trim($v);
        if ($v === '') {
            return 0;
        }
        $n = (int) $v;
        return match (strtolower(substr($v, -1))) {
            'g'     => $n * 1073741824,
            'm'     => $n * 1048576,
            'k'     => $n * 1024,
            default => $n,
        };
    }

    private static function humanBytes(int $b): string
    {
        return $b >= 1048576
            ? rtrim(rtrim(number_format($b / 1048576, 1), '0'), '.') . ' MB'
            : max(1, (int) round($b / 1024)) . ' KB';
    }
}
