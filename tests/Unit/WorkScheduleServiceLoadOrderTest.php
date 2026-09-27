<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * WS-D1 — /work_schedules died with a PHP fatal on every visit.
 *
 *     An uncaught Exception was encountered
 *     Type: Error
 *     Message: Class "Policy_assignment_service" not found
 *     Filename: system/core/Loader.php   Line: 1286
 *
 * `Work_schedule_service` declares
 *
 *     private const BLOCK_MAP = [ \Policy_assignment_service::BLOCK_NO_POLICIES => ... ]
 *
 * and PHP evaluates class constants when the CLASS IS DEFINED, not when a
 * method first runs. The file did `require_once` that service — but from
 * inside `resolveEmployeeCalendar()`, hundreds of lines below the class
 * declaration. Simply loading the library was therefore fatal, so the entire
 * Work Schedules screen (a Staff Lifecycle stage) was unreachable.
 *
 * The fix is the pattern this codebase already uses: require the dependency
 * ABOVE the class, exactly as Work_schedules.php does for
 * Working_calendar_service, with the same reasoning — CI's loader does not
 * resolve static calls.
 *
 * A repo-wide scan (with a control proving the scanner could see this very
 * instance) found no other library with the same shape.
 */
final class WorkScheduleServiceLoadOrderTest extends TestCase
{
    private static string $src = '';

    public static function setUpBeforeClass(): void
    {
        self::$src = (string) file_get_contents(
            __DIR__ . '/../../application/libraries/Work_schedule_service.php');
    }

    public static function tearDownAfterClass(): void
    {
        self::$src = '';
    }

    private static function classDeclLine(): int
    {
        foreach (preg_split('~\r?\n~', self::$src) as $i => $l) {
            if (preg_match('~^\s*(final\s+|abstract\s+)?class\s+Work_schedule_service~', $l)) {
                return $i + 1;
            }
        }
        throw new RuntimeException('class declaration not found — this test is stale');
    }

    /** @return int|null 1-indexed line of the first matching require, or null */
    private static function requireLineFor(string $library): ?int
    {
        foreach (preg_split('~\r?\n~', self::$src) as $i => $l) {
            if (strpos($l, "libraries/{$library}.php") !== false
                && preg_match('~require(_once)?~', $l)
                && strpos(ltrim($l), '//') !== 0) {
                return $i + 1;
            }
        }

        return null;
    }

    // ── The property that was violated ───────────────────────────────────

    /**
     * THE CENTRAL TEST. Every class this file names inside a CLASS CONSTANT
     * must be required before the class is declared, because that is when PHP
     * resolves it.
     */
    public function test_constant_dependencies_are_required_before_the_class(): void
    {
        $classLine = self::classDeclLine();
        $lines = preg_split('~\r?\n~', self::$src) ?: [];

        $inConst = false;
        $needed = [];
        foreach ($lines as $i => $raw) {
            if (preg_match('~^\s*(private|protected|public)?\s*const\s+\w+\s*=~', $raw)) {
                $inConst = true;
            }
            if (!$inConst) { continue; }

            // Comments describe the mapping; they do not resolve anything.
            $line = preg_replace('~//.*$~', '', $raw) ?? '';
            $line = preg_match('~^\s*\*~', $line) ? '' : $line;

            if (preg_match_all('~\\\\?([A-Z]\w+_\w+)::~', $line, $m)) {
                foreach ($m[1] as $cls) {
                    if ($cls !== 'Work_schedule_service') { $needed[$cls] = $i + 1; }
                }
            }
            if (strpos($raw, '];') !== false) { $inConst = false; }
        }

        $this->assertNotEmpty($needed,
            'expected BLOCK_MAP to still build from another service — if that changed, '
            . 'update this test rather than deleting it');

        foreach ($needed as $cls => $usedAtLine) {
            $reqLine = self::requireLineFor($cls);

            $this->assertNotNull($reqLine,
                "{$cls} is used in a class constant (line {$usedAtLine}) but never required");
            $this->assertLessThan($classLine, $reqLine,
                "{$cls} is required at line {$reqLine}, AFTER the class is declared at line "
                . "{$classLine}. PHP resolves class constants at class-definition time, so "
                . 'merely loading this library will fatal with "Class not found" — which is '
                . 'exactly what took /work_schedules down (WS-D1).');
        }
    }

    /** The specific dependency, named, so the regression is unmistakable. */
    public function test_policy_assignment_service_is_required_above_the_class(): void
    {
        $reqLine = self::requireLineFor('Policy_assignment_service');

        $this->assertNotNull($reqLine, 'Policy_assignment_service must be required');
        $this->assertLessThan(self::classDeclLine(), $reqLine,
            'the require must sit above the class declaration');
    }

    /** The dependency it points at must actually exist and parse. */
    public function test_the_dependency_exists_and_declares_that_class(): void
    {
        $p = __DIR__ . '/../../application/libraries/Policy_assignment_service.php';

        $this->assertFileExists($p);
        $this->assertMatchesRegularExpression(
            '~^\s*(final\s+|abstract\s+)?class\s+Policy_assignment_service~m',
            (string) file_get_contents($p),
            'the filename and the class it declares must agree, or CI cannot load it');
    }

    /**
     * The constants BLOCK_MAP reads must exist on the other service. A renamed
     * constant there would fatal here in exactly the same way.
     */
    public function test_the_referenced_constants_exist_on_the_dependency(): void
    {
        $dep = (string) file_get_contents(
            __DIR__ . '/../../application/libraries/Policy_assignment_service.php');

        // Comments mention `Policy_assignment_service::BLOCK_*` as prose; that
        // wildcard is not a constant and matching it asserts nothing.
        $code = preg_replace('~//.*$|^\s*\*.*$~m', '', self::$src) ?? '';

        preg_match_all('~\\\\?Policy_assignment_service::(\w+)~', $code, $m);
        $used = array_unique(array_filter($m[1], static fn ($c) => ctype_upper($c[0])));

        $this->assertNotEmpty($used);
        foreach ($used as $const) {
            if (!preg_match('~^[A-Z0-9_]+$~', $const)) { continue; }   // methods, not constants
            $this->assertMatchesRegularExpression(
                '~const\s+' . preg_quote($const, '~') . '\s*=~', $dep,
                "Work_schedule_service reads Policy_assignment_service::{$const}, "
                . 'which that class does not declare');
        }
    }
}
