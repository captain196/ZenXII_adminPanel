<?php
namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * salaryStructure privacy routing — closes a gap found during the Staff
 * Lifecycle completeness audit: salaryStructure sat on the same-school-
 * readable `staff` doc (unlike its sibling salaryDetails, already private),
 * so any same-school parent/student session could read it. Re-verified
 * blast radius before implementing: Payroll's live calculation path
 * (Compensation_reader::resolveMany()) reads `salarySlips`, and the
 * auto-create fallback (Hr.php::_auto_create_from_profile()) reads
 * profile['salaryDetails'] — neither touches staff.salaryStructure — so
 * this is a narrow privacy/data-model fix, not a Payroll change.
 *
 * Source-regression tests (Firestore_service has no behavioral mock for
 * controller code — see StaffCreationAuditMetadataTest's docblock).
 */
class StaffSalaryStructurePrivacyRoutingTest extends TestCase
{
    private string $staffSrc;
    private string $verifySrc;

    protected function setUp(): void
    {
        $this->staffSrc  = $this->readCodeOnly(__DIR__ . '/../../application/controllers/Staff.php');
        $this->verifySrc = $this->readCodeOnly(__DIR__ . '/../../application/controllers/Salary_structure_verify.php');
    }

    private function readCodeOnly(string $path): string
    {
        $raw = file_get_contents($path);
        $this->assertNotFalse($raw, "Could not read {$path}");
        $out = '';
        foreach (token_get_all($raw) as $token) {
            if (is_array($token)) {
                if ($token[0] === T_COMMENT || $token[0] === T_DOC_COMMENT) continue;
                $out .= $token[1];
            } else {
                $out .= $token;
            }
        }
        return $out;
    }

    private function extractFunctionBody(string $src, int $functionKeywordOffset): string
    {
        $openBrace = strpos($src, '{', $functionKeywordOffset);
        $this->assertNotFalse($openBrace);
        $depth = 0;
        $len = strlen($src);
        for ($i = $openBrace; $i < $len; $i++) {
            if ($src[$i] === '{') $depth++;
            elseif ($src[$i] === '}') {
                $depth--;
                if ($depth === 0) return substr($src, $openBrace, $i - $openBrace + 1);
            }
        }
        $this->fail('Unbalanced braces while extracting function body.');
    }

    private function syncBody(): string
    {
        $start = strpos($this->staffSrc, 'function _sync_salary_structure(');
        $this->assertNotFalse($start, '_sync_salary_structure() must exist.');
        return $this->extractFunctionBody($this->staffSrc, $start);
    }

    // ── PII_KEYS classification ──────────────────────────────────────────

    public function test_salary_structure_is_in_pii_keys(): void
    {
        $this->assertStringContainsString("'salaryStructure'", $this->staffSrc);
        $piiKeysLine = substr($this->staffSrc, strpos($this->staffSrc, 'private const PII_KEYS = ['), 300);
        $this->assertStringContainsString("'salaryStructure'", $piiKeysLine);
    }

    // ── Write side: reads the private mirror, not the public doc ─────────

    public function test_sync_reads_existing_structure_from_staff_private_not_public_doc(): void
    {
        $body = $this->syncBody();
        $this->assertStringContainsString("\$this->_load_staff_private(\$staffId)['salaryStructure']", $body);
        $this->assertStringNotContainsString("\$this->fs->getEntity('staff', \$staffId)", $body,
            'The old direct public-doc read must be gone — reading the public doc would always see null after the field moved to staffPrivate.');
    }

    // ── Write side: routes through _split_staff_private(), never a direct
    //    updateEntity('staff', ..., ['salaryStructure' => <real value>]) ──

    public function test_sync_never_writes_the_real_structure_value_directly_to_the_public_staff_doc(): void
    {
        $body = $this->syncBody();
        $this->assertStringNotContainsString("updateEntity('staff', \$staffId, ['salaryStructure' => \$structure])", $body);
        $this->assertStringNotContainsString("updateEntity('staff', \$staffId, ['salaryStructure' => array_merge(\$existing", $body);
    }

    public function test_sync_routes_both_branches_through_split_staff_private(): void
    {
        $body = $this->syncBody();
        // Manual branch (don't overwrite HR's own value, just note the sync).
        $this->assertStringContainsString("\$patch = ['salaryStructure' => array_merge(\$existing, ['last_synced_at' => \$now])];", $body);
        // Create/update branch.
        $this->assertStringContainsString("\$patch = ['salaryStructure' => \$structure];", $body);
        $splitCount = substr_count($body, '$this->_split_staff_private($staffId, $patch);');
        $this->assertSame(2, $splitCount, 'Both branches (manual and create/update) must route through _split_staff_private().');
    }

    public function test_sync_still_writes_back_to_public_doc_to_actively_scrub_any_legacy_value(): void
    {
        // After _split_staff_private() nulls $patch['salaryStructure'], the
        // same $patch is written to the public doc — same active-scrub idiom
        // used by new_staff/edit_staff/set_status, so a legacy public value
        // gets cleared the next time this staff member's structure syncs.
        $body = $this->syncBody();
        $updatePos = strpos($body, "\$this->fs->updateEntity('staff', \$staffId, \$patch);");
        $this->assertNotFalse($updatePos, 'Must write the scrubbed $patch back to the public staff doc, not skip the write entirely.');
    }

    public function test_split_happens_before_the_public_doc_write_in_both_branches(): void
    {
        $body = $this->syncBody();
        $splitPositions  = [];
        $writePositions  = [];
        $offset = 0;
        while (($pos = strpos($body, '$this->_split_staff_private($staffId, $patch);', $offset)) !== false) {
            $splitPositions[] = $pos;
            $offset = $pos + 1;
        }
        $offset = 0;
        while (($pos = strpos($body, "\$this->fs->updateEntity('staff', \$staffId, \$patch);", $offset)) !== false) {
            $writePositions[] = $pos;
            $offset = $pos + 1;
        }
        $this->assertCount(2, $splitPositions);
        $this->assertCount(2, $writePositions);
        foreach ($splitPositions as $i => $splitPos) {
            $this->assertLessThan($writePositions[$i], $splitPos,
                'The split (which nulls $patch) must happen before the public-doc write in each branch, or the real value would leak onto the public doc for one write cycle.');
        }
    }

    // ── Diagnostic reader updated ─────────────────────────────────────────

    public function test_verify_script_reads_from_staff_private_not_the_public_row(): void
    {
        $this->assertStringContainsString("\$this->firestore_service->get('staffPrivate', \$docId)", $this->verifySrc);
        $this->assertStringNotContainsString("\$data['salaryStructure'] ?? null;", $this->verifySrc,
            'The diagnostic script must no longer read salaryStructure off the raw public staff row.');
    }

    // ── Scope control — Payroll calculation path untouched ────────────────

    public function test_payroll_calculation_path_not_referenced_by_this_change(): void
    {
        $body = $this->syncBody();
        $this->assertStringNotContainsString('Compensation_reader', $body);
        $this->assertStringNotContainsString('salarySlips', $body);
    }

    public function test_salary_details_field_unaffected(): void
    {
        // salaryDetails was already private before this phase; this phase
        // must not touch its handling.
        $body = $this->syncBody();
        $this->assertStringNotContainsString('salaryDetails', $body);
    }

    public function test_no_new_rbac_or_collection_introduced(): void
    {
        $body = $this->syncBody();
        $this->assertStringNotContainsString('token.role', $body);
        $this->assertStringNotContainsString('salaryStructureHistory', $this->staffSrc);
        $this->assertStringNotContainsString('salaryStructureAudit', $this->staffSrc);
    }
}
