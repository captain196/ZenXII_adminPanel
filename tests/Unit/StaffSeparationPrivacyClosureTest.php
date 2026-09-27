<?php
namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Phase 3A.1 — Separation Metadata Privacy Closure.
 *
 * Source-regression tests following this repo's established convention
 * (Firestore_service has no behavioral mock — see StaffCreationAuditMetadataTest's
 * docblock) for the two changes: PII_KEYS gains 4 fields, and
 * Staff::set_status() — previously the one staff-write path that never
 * called _split_staff_private() — now does.
 */
class StaffSeparationPrivacyClosureTest extends TestCase
{
    private string $staffSrc;

    protected function setUp(): void
    {
        $this->staffSrc = $this->readCodeOnly(__DIR__ . '/../../application/controllers/Staff.php');
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

    // ── PII_KEYS field set ───────────────────────────────────────────────

    /**
     * Originally asserted the literal 10-field PII_KEYS array from Phase
     * 3A.1. Correctly superseded by the later-authorized "salaryStructure
     * privacy routing" phase, which added an 11th field — same mechanism,
     * same array, no other field touched (see
     * StaffSalaryStructurePrivacyRoutingTest for that phase's own guards).
     */
    public function test_pii_keys_contains_the_four_narrative_and_actor_fields(): void
    {
        $this->assertStringContainsString(
            "private const PII_KEYS = ['panNumber', 'aadharNumber', 'pfNumber', 'esiNumber', 'salaryDetails', 'salaryStructure', 'bankDetails', 'exitReason', 'deactivationReason', 'deactivatedBy', 'reactivatedBy'];",
            $this->staffSrc
        );
    }

    public function test_pii_keys_deliberately_excludes_exit_date_and_last_working_date(): void
    {
        // These stay public — Hr.php's payroll final-pay roster reads them
        // directly off bulk staff rows; moving them would require a Payroll
        // code change, out of this phase's authorized scope.
        $start = strpos($this->staffSrc, "private const PII_KEYS = [");
        $line = substr($this->staffSrc, $start, 300);
        $this->assertStringNotContainsString("'exitDate'", $line);
        $this->assertStringNotContainsString("'lastWorkingDate'", $line);
    }

    public function test_pii_keys_still_contains_the_original_six_fields(): void
    {
        foreach (['panNumber', 'aadharNumber', 'pfNumber', 'esiNumber', 'salaryDetails', 'bankDetails'] as $k) {
            $this->assertStringContainsString("'{$k}'", $this->staffSrc);
        }
    }

    // ── set_status() now calls the split ─────────────────────────────────

    public function test_set_status_calls_split_staff_private(): void
    {
        $start = strpos($this->staffSrc, 'function set_status(');
        $this->assertNotFalse($start);
        $body = $this->extractFunctionBody($this->staffSrc, $start);
        $this->assertStringContainsString('$this->_split_staff_private($user_id, $patch);', $body);
    }

    public function test_set_status_splits_before_writing_not_after(): void
    {
        $start = strpos($this->staffSrc, 'function set_status(');
        $body = $this->extractFunctionBody($this->staffSrc, $start);
        $splitPos = strpos($body, '_split_staff_private($user_id, $patch)');
        $writePos = strpos($body, "updateEntity('staff', \$user_id, \$patch)");
        $this->assertNotFalse($splitPos);
        $this->assertNotFalse($writePos);
        $this->assertLessThan($writePos, $splitPos, 'The split must happen BEFORE the staff doc write, or the sensitive values would already be public for one write cycle.');
    }

    public function test_set_status_still_writes_exit_date_and_last_working_date_directly(): void
    {
        // Unaffected by the privacy change — these two remain plain $patch keys.
        $start = strpos($this->staffSrc, 'function set_status(');
        $body = $this->extractFunctionBody($this->staffSrc, $start);
        $this->assertStringContainsString("\$patch['exitDate']        = \$exitDate;", $body);
        $this->assertStringContainsString("\$patch['lastWorkingDate'] = \$lastWork;", $body);
    }

    /**
     * Originally asserted exactly 4 call sites (new_staff, _commit_staff_row,
     * edit_staff, set_status) — correctly superseded by the salaryStructure
     * privacy routing phase, which added 2 more call sites inside
     * _sync_salary_structure() (the manual-structure branch and the
     * create/update branch — see StaffSalaryStructurePrivacyRoutingTest).
     */
    public function test_exactly_six_split_staff_private_call_sites(): void
    {
        $count = substr_count($this->staffSrc, '_split_staff_private(');
        // 6 call sites + 1 function definition = 7 occurrences of the literal name.
        $this->assertSame(7, $count, 'Expected exactly 6 call sites (new_staff, _commit_staff_row, edit_staff, set_status, and 2 inside _sync_salary_structure) + 1 definition.');
    }

    public function test_split_staff_private_signature_unchanged_pass_by_reference(): void
    {
        $this->assertStringContainsString(
            'private function _split_staff_private(string $staffId, array &$staffDoc): void',
            $this->staffSrc
        );
    }

    // ── Scope control — nothing else touched ─────────────────────────────

    /**
     * Originally asserted Phase 3A.1 didn't wire Employment History into
     * set_status() at all — that boundary was point-in-time to 3A.1's own
     * scope and was correctly, separately superseded by Phase 3B.1's
     * authorized Employment History integration (see
     * StaffSeparationWiringTest for 3B.1's own equivalent guards). The one
     * claim from this test that remains permanently true — set_status()
     * still never touches Reporting Manager, a completely separate standing
     * decision — is kept; the now-stale Employment-History-absence
     * assertions are removed rather than left to fail against correct,
     * later-authorized behavior.
     */
    /**
     * Originally asserted set_status() never references reportingManagerId
     * at all — true as of Phase 3A.1/3B.1, correctly superseded by the
     * later-authorized Rehire Workflow phase, which lets a rehire optionally
     * update the Reporting Manager (see StaffRehireWorkflowTest for that
     * phase's own guards). The claim that remains permanently true — a
     * PLAIN Reactivate/Deactivate submitting no rehire fields never touches
     * reportingManagerId — is preserved here in narrowed form.
     */
    public function test_reporting_manager_only_touched_when_explicitly_submitted_by_rehire(): void
    {
        $start = strpos($this->staffSrc, 'function set_status(');
        $body = $this->extractFunctionBody($this->staffSrc, $start);
        $this->assertStringContainsString("array_key_exists('reporting_manager_id', \$_POST)", $body,
            'reportingManagerId must remain gated behind an explicit opt-in check, never touched unconditionally.');
    }

    public function test_set_status_rbac_gate_unchanged(): void
    {
        $start = strpos($this->staffSrc, 'function set_status(');
        $body = $this->extractFunctionBody($this->staffSrc, $start);
        $this->assertStringContainsString('$this->_require_role(self::MANAGE_ROLES, "", "SIS", "manage");', $body);
    }
}
