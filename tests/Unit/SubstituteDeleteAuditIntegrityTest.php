<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * ACAD-D8 — a failed substitute delete reports success and forges an audit entry.
 *
 * Substitute_service::deleteSubstitute():
 *
 *     try { $this->firebase->firestoreDelete(self::COLLECTION, $fsDocId); }
 *     catch (\Throwable $e) {}                                   // swallowed
 *
 *     $this->_audit('delete', 'substitute', $id, $before, null, []);
 *     return [];                                                 // always success
 *
 * The controller wraps that in json_success(), so when the delete fails:
 *
 *   - the UI reports the substitute was removed;
 *   - the audit trail records a deletion that never happened;
 *   - the substitute survives and keeps appearing in the Teacher app and the
 *     day's cover arrangements.
 *
 * This is NOT data loss — it is the opposite. The record persists while every
 * surface claims it is gone, and the audit log, which exists precisely to
 * answer "who removed this cover", becomes untrustworthy.
 *
 * Scope note: the sibling updateSubstituteStatus() does NOT swallow its write —
 * only its audit before-state read — so it is not part of this defect.
 * deleteSubstitute is the sole site.
 */
final class SubstituteDeleteAuditIntegrityTest extends TestCase
{
    private string $src;

    protected function setUp(): void
    {
        $p = __DIR__ . '/../../application/libraries/Substitute_service.php';
        $this->assertFileExists($p);
        $this->src = (string) file_get_contents($p);
    }

    private function method(string $name): string
    {
        $i = strpos($this->src, "function {$name}(");
        $this->assertNotFalse($i, "{$name}() is gone");
        $end = strpos($this->src, "\n    public function ", $i + 10);
        if ($end === false) $end = strpos($this->src, "\n    private function ", $i + 10);
        $this->assertNotFalse($end, "could not bound {$name}()");
        return substr($this->src, $i, $end - $i);
    }

    /** Comments naming a call must not satisfy assertions about that call. */
    private function codeOnly(string $name): string
    {
        $s = preg_replace('~/\*.*?\*/~s', '', $this->method($name));
        return (string) preg_replace('~^\s*//.*$~m', '', (string) $s);
    }

    /** Premise: the doc id is school-prefixed, so tenancy is not the issue here. */
    public function test_the_delete_is_tenant_bound_by_doc_id(): void
    {
        $this->assertMatchesRegularExpression(
            '~\$fsDocId\s*=\s*"\{\$this->schoolId\}_\{\$id\}"~',
            $this->codeOnly('deleteSubstitute'),
            'the school prefix is what stops a foreign id reaching another tenant');
    }

    /**
     * DEFECT ACAD-D8 — the delete itself must not be swallowed.
     */
    public function test_a_failed_delete_is_not_reported_as_success(): void
    {
        $fn = $this->codeOnly('deleteSubstitute');

        $i = strpos($fn, 'firestoreDelete');
        $this->assertNotFalse($i, 'the delete call is gone');

        $after = substr($fn, $i, 300);

        $this->assertDoesNotMatchRegularExpression(
            '~catch\s*\([^)]*\)\s*\{\s*\}~', $after,
            "The delete failure is swallowed and the method still returns success,\n"
            . "so the UI reports removal, the audit records a deletion that never\n"
            . 'happened, and the substitute survives.');
    }

    /** The audit must not claim a deletion that did not occur. */
    public function test_the_audit_entry_follows_a_successful_delete(): void
    {
        $fn = $this->codeOnly('deleteSubstitute');

        $d = strpos($fn, 'firestoreDelete');
        $a = strpos($fn, "_audit('delete'");
        $this->assertNotFalse($d);
        $this->assertNotFalse($a, 'the delete audit entry is gone');
        $this->assertLessThan($a, $d, 'the audit must come after the delete');

        // Between the delete and the audit there must be something that stops
        // the audit running on failure.
        $between = substr($fn, $d, $a - $d);
        $this->assertMatchesRegularExpression(
            '~throw new Service_exception~', $between,
            'nothing prevents the audit entry being written when the delete failed');
    }

    /** A refusal that cannot be diagnosed is its own trap. */
    public function test_the_delete_failure_is_logged(): void
    {
        $fn = $this->codeOnly('deleteSubstitute');

        $i = strpos($fn, 'firestoreDelete');
        $this->assertNotFalse($i);
        $after = substr($fn, $i, 700);

        $l = strpos($after, 'log_message');
        $t = strpos($after, 'throw new Service_exception');

        $this->assertTrue($t !== false, 'the delete failure must refuse');
        $this->assertTrue($l !== false && $l < $t,
            'the delete failure must be logged before it refuses');
    }
}
