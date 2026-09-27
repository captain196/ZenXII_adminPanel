<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * ACAD-D8 / ACAD-D9 — a failed delete must never be audited as a success.
 *
 * The Academic services follow a delete-then-audit shape:
 *
 *     try { $this->firebase->firestoreDelete(COLLECTION, $fsDocId); }
 *     catch (\Throwable $e) {}                      // swallowed
 *     $this->_audit('delete', …, $before, null, []);
 *     return [];                                    // always success
 *
 * When the delete fails the caller is told the record was removed, the audit
 * trail records a removal that never happened, and the record survives.
 *
 * This is not data loss — it is the inverse, and it is worse for trust: the
 * audit log exists to answer "who removed this", and it now answers wrongly.
 *
 * Observed consequences, per service:
 *   Substitute_service — the cover keeps showing in the Teacher app and in the
 *                        day's arrangements after "cancellation".
 *   Calendar_service   — calendarEvents is where HOLIDAYS live (Holiday_service
 *                        reads them). A holiday whose delete failed keeps
 *                        suppressing attendance while the admin believes it is
 *                        gone.
 *
 * This is a sweep rather than one test per service, so a new Academic service
 * cannot reintroduce the shape unnoticed.
 *
 * NOT IN SCOPE: B2_registry_service.php has the same shape at 4 of its 8
 * delete sites. That file belongs to the Billing programme; it is recorded as a
 * cross-programme observation and deliberately left untouched here.
 */
final class AcademicDeleteAuditIntegrityTest extends TestCase
{
    /** Academic-module services that delete and then audit. */
    private const SERVICES = [
        'Substitute_service.php',
        'Calendar_service.php',
    ];

    private function src(string $file): string
    {
        $p = __DIR__ . '/../../application/libraries/' . $file;
        $this->assertFileExists($p);
        return (string) file_get_contents($p);
    }

    /** Comments naming a call must not satisfy assertions about that call. */
    private function codeOnly(string $file): string
    {
        $s = preg_replace('~/\*.*?\*/~s', '', $this->src($file));
        return (string) preg_replace('~^\s*//.*$~m', '', (string) $s);
    }

    /** Premise: these services still delete and still audit. */
    public function test_the_services_still_delete_and_audit(): void
    {
        foreach (self::SERVICES as $f) {
            $code = $this->codeOnly($f);
            $this->assertStringContainsString('firestoreDelete', $code, "{$f}: no delete");
            $this->assertMatchesRegularExpression("~_audit\(\s*'delete'~", $code, "{$f}: no delete audit");
        }
    }

    /**
     * DEFECT ACAD-D8 / ACAD-D9 — the delete itself must not be swallowed.
     */
    public function test_no_academic_service_swallows_a_delete(): void
    {
        $offenders = [];

        foreach (self::SERVICES as $f) {
            $code = $this->codeOnly($f);
            $off  = 0;
            while (($i = strpos($code, 'firestoreDelete', $off)) !== false) {
                $off = $i + 1;
                if (preg_match('~catch\s*\([^)]*\)\s*\{\s*\}~', substr($code, $i, 300))) {
                    $offenders[] = $f;
                    break;
                }
            }
        }

        $this->assertSame([], $offenders,
            "These services swallow a failed delete and still report success,\n"
            . "so the audit trail records a removal that never happened and the\n"
            . 'record survives:  ' . implode(', ', $offenders));
    }

    /** The audit must be unreachable when the delete failed. */
    public function test_the_delete_refuses_before_it_audits(): void
    {
        foreach (self::SERVICES as $f) {
            $code = $this->codeOnly($f);

            $d = strpos($code, 'firestoreDelete');
            $this->assertNotFalse($d, "{$f}: no delete");

            $a = strpos($code, "_audit('delete'", $d);
            $this->assertNotFalse($a, "{$f}: no delete audit after the delete");

            $this->assertMatchesRegularExpression(
                '~throw new Service_exception~', substr($code, $d, $a - $d),
                "{$f}: nothing stops the audit entry being written when the delete failed");
        }
    }

    /** A refusal that cannot be diagnosed is its own trap. */
    public function test_the_delete_failure_is_logged_before_refusing(): void
    {
        foreach (self::SERVICES as $f) {
            $code = $this->codeOnly($f);

            $i = strpos($code, 'firestoreDelete');
            $this->assertNotFalse($i);
            $after = substr($code, $i, 700);

            $l = strpos($after, 'log_message');
            $t = strpos($after, 'throw new Service_exception');

            $this->assertTrue($t !== false, "{$f}: the delete failure must refuse");
            $this->assertTrue($l !== false && $l < $t,
                "{$f}: the delete failure must be logged before it refuses");
        }
    }
}
