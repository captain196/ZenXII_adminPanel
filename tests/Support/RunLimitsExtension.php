<?php
namespace Tests\Support;

use PHPUnit\Runner\BeforeTestHook;

/**
 * Re-asserts the suite's run limits before EVERY test.
 *
 * ── WHY A PER-TEST HOOK AND NOT JUST BOOTSTRAP ──────────────────────────────
 * Production code legitimately sets process limits for its own long operations:
 *
 *     application/libraries/Timetable_service.php   @set_time_limit(300)   ×4
 *     application/controllers/Backup_cron.php       set_time_limit(300)
 *                                                   ini_set('memory_limit','256M')
 *     application/controllers/Fees.php              @set_time_limit(600)   ×3
 *
 * Those are correct in a web request. In a test run they are process-global and
 * PERMANENT: the moment one test exercises Timetable_service, every remaining
 * test in the run inherits a 300-second budget that started counting there. The
 * suite then dies with a fatal inside whichever innocent test happens to be
 * running when the clock expires — PayrollAuditTrailTest, NoTrackedCredential-
 * MaterialTest and TimetableLegacyAuthorityTest have all taken the blame.
 *
 * Worse, PHPUnit never prints its "Tests: … Failures: …" summary after a fatal,
 * so the run leaves no summary line at all. Anything reading the tail, or
 * grepping for "Failures:", sees a crash as a clean pass — which is how this
 * project's baseline came to be quoted from truncated runs for months.
 *
 * Setting the limits once in bootstrap is not enough precisely because the
 * poisoning happens later, mid-run. Re-asserting before each test bounds the
 * blast radius of any such call to the single test that made it.
 *
 * This changes NO production behaviour: the application still sets its own
 * limits wherever it did before.
 */
final class RunLimitsExtension implements BeforeTestHook
{
    /** Matches tests/bootstrap.php — kept in step deliberately. */
    private const MEMORY = '1G';

    public function executeBeforeTest(string $test): void
    {
        // No time limit: a test run is not a web request.
        @set_time_limit(0);

        // Only ever raise, never lower — a test that deliberately constrains
        // memory to prove a limit is respected keeps its own setting.
        $current = self::toBytes((string) ini_get('memory_limit'));
        if ($current !== -1 && $current < self::toBytes(self::MEMORY)) {
            @ini_set('memory_limit', self::MEMORY);
        }
    }

    /** '256M' → 268435456; '-1' (unlimited) → -1. */
    private static function toBytes(string $v): int
    {
        $v = trim($v);
        if ($v === '' || $v === '-1') return -1;
        $unit = strtolower(substr($v, -1));
        $n    = (int) $v;
        switch ($unit) {
            case 'g': return $n * 1024 * 1024 * 1024;
            case 'm': return $n * 1024 * 1024;
            case 'k': return $n * 1024;
            default:  return $n;
        }
    }
}
