<?php
namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Phase 3B.1 — Separation/Reactivation/Rehire wiring, following this repo's
 * literal-source-regression convention (StaffEmploymentHistoryWiringTest /
 * StaffReportingManagerWiringTest) — Firestore_service has no behavioral
 * mock, so controller-level integration is proven against comment-stripped
 * source, not by instantiating Staff.php.
 */
class StaffSeparationWiringTest extends TestCase
{
    private string $staffSrc;
    private string $serviceSrc;
    private string $rulesSrc;

    protected function setUp(): void
    {
        $this->staffSrc   = $this->readCodeOnly(__DIR__ . '/../../application/controllers/Staff.php');
        $this->serviceSrc = $this->readCodeOnly(__DIR__ . '/../../application/libraries/Staff_employment_history_service.php');
        $this->rulesSrc   = file_get_contents(__DIR__ . '/../../firebase-rules/firestore.rules');
        $this->assertNotFalse($this->rulesSrc);
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

    private function setStatusBody(): string
    {
        $start = strpos($this->staffSrc, 'function set_status(');
        $this->assertNotFalse($start);
        return $this->extractFunctionBody($this->staffSrc, $start);
    }

    // ── set_status()'s EXISTING logic is untouched — no duplication ─────

    public function test_set_status_existing_write_and_cascade_logic_is_unchanged(): void
    {
        $body = $this->setStatusBody();
        // Every pre-3B.1 line, verbatim, still present — proves nothing was
        // restructured, only appended after.
        $this->assertStringContainsString("\$this->_require_role(self::MANAGE_ROLES, \"\", \"SIS\", \"manage\");", $this->staffSrc);
        $this->assertStringContainsString("if (!in_array(\$newStatus, ['Active', 'Inactive'], true)) {", $body);
        $this->assertStringContainsString("if (\$oldStatus === \$newStatus) {", $body);
        $this->assertStringContainsString("\$this->_split_staff_private(\$user_id, \$patch);", $body);
        $this->assertStringContainsString("\$this->fs->updateEntity('staff', \$user_id, \$patch);", $body);
        $this->assertStringContainsString('$this->_cascade_subject_assignments($user_id, $newStatus, $reason, $actorId, $now);', $body);
        $this->assertStringContainsString('$this->_disable_firebase_user($user_id)', $body);
        $this->assertStringContainsString('$this->_enable_firebase_user($user_id)', $body);
    }

    public function test_set_status_still_writes_exit_date_and_last_working_date_exactly_as_before(): void
    {
        $body = $this->setStatusBody();
        $this->assertStringContainsString("\$patch['exitDate']        = \$exitDate;", $body);
        $this->assertStringContainsString("\$patch['lastWorkingDate'] = \$lastWork;", $body);
    }

    // ── Employment History integration is appended, not interleaved ─────

    public function test_employment_history_call_happens_after_cascades_not_before(): void
    {
        $body = $this->setStatusBody();
        $authPos   = strpos($body, '_disable_firebase_user($user_id)');
        $ehPos     = strpos($body, 'recordEmploymentStatusChange(');
        $this->assertNotFalse($authPos);
        $this->assertNotFalse($ehPos);
        $this->assertGreaterThan($authPos, $ehPos, 'Employment History must fire AFTER the existing Auth/cascade side effects, never interleaved with or before them.');
    }

    public function test_employment_history_call_is_exception_guarded(): void
    {
        $body = $this->setStatusBody();
        $ehPos = strpos($body, 'recordEmploymentStatusChange(');
        $tryPos = strrpos(substr($body, 0, $ehPos), 'try {');
        $this->assertNotFalse($tryPos, 'The Employment History integration must be wrapped in try/catch — a failure must never abort or roll back the already-successful status change.');
    }

    public function test_inactive_branch_fires_separated(): void
    {
        $body = $this->setStatusBody();
        $this->assertStringContainsString("'SEPARATED',", $body);
        $this->assertStringContainsString("'Inactive',", $body);
    }

    /**
     * Originally asserted a literal determineReactivationEventType([]) call —
     * true prior to the Rehire Workflow phase, when set_status() could never
     * submit simultaneous attribute changes. That phase legitimately extended
     * the Active branch to accept opt-in Position/Department/EmploymentType/
     * ReportingManager changes, so the call site now passes the actual
     * $otherChanges variable (see StaffRehireWorkflowTest for that phase's
     * own guard: test_uses_determine_reactivation_event_type_with_the_actual_changes).
     * $otherChanges is empty for a plain Reactivate and non-empty only for a
     * Rehire, so the REACTIVATED-vs-REHIRED derivation itself is unchanged —
     * only the literal-empty-array shape of this test's assertion is stale.
     */
    public function test_active_branch_uses_determine_reactivation_event_type_with_the_submitted_changes(): void
    {
        $body = $this->setStatusBody();
        $this->assertStringContainsString('determineReactivationEventType($otherChanges)', $body,
            'set_status() must derive REACTIVATED vs REHIRED from the actually-submitted rehire field changes, never a hardcoded literal.');
    }

    public function test_effective_from_uses_last_work_not_expected_date(): void
    {
        $body = $this->setStatusBody();
        // $lastWork (actualLastWorkingDate) is passed as effectiveFrom AND actualLastWorkingDate;
        // no "expected" date variable exists anywhere in set_status().
        $this->assertStringContainsString('$lastWork,', $body);
        $this->assertStringNotContainsString('expectedLastWorkingDate', $body);
        $this->assertStringNotContainsString('expected_last_working', $body);
    }

    public function test_separation_type_defaults_to_other_when_not_submitted(): void
    {
        $body = $this->setStatusBody();
        $this->assertStringContainsString("if (\$separationType === '') \$separationType = 'Other';", $body);
    }

    // ── recordJoined() calls now include employmentStatus ────────────────

    public function test_new_staff_and_bulk_import_joined_snapshots_include_employment_status(): void
    {
        $newStaffStart = strpos($this->staffSrc, 'function new_staff(');
        $newStaffBody  = $this->extractFunctionBody($this->staffSrc, $newStaffStart);
        $this->assertStringContainsString("'employmentStatus'   => 'Active',", $newStaffBody);

        $bulkStart = strpos($this->staffSrc, 'function _commit_staff_row(');
        $bulkBody  = $this->extractFunctionBody($this->staffSrc, $bulkStart);
        $this->assertStringContainsString("'employmentStatus'   => 'Active',", $bulkBody);
    }

    // ── RBAC unchanged, no new capability, no legacy RBAC ────────────────

    public function test_set_status_rbac_gate_unchanged(): void
    {
        $this->assertStringContainsString('$this->_require_role(self::MANAGE_ROLES, "", "SIS", "manage");', $this->setStatusBody());
    }

    public function test_no_new_rbac_capability_or_legacy_rbac_introduced(): void
    {
        foreach ([$this->staffSrc, $this->serviceSrc] as $src) {
            $this->assertStringNotContainsString('token.role', $src);
            $this->assertStringNotContainsString("staff.separate'", $src);
            $this->assertStringNotContainsString("staff.terminate'", $src);
            $this->assertStringNotContainsString("staff.deactivate'", $src);
        }
    }

    public function test_rbac_helper_not_touched(): void
    {
        // Sanity marker only — this test file makes no runtime claim about a
        // file it doesn't read; scope control here is enforced by the
        // git-status audit in the final report, not a source assertion.
        $this->assertFileExists(__DIR__ . '/../../application/helpers/rbac_helper.php');
    }

    // ── Firestore rules: staffSeparations gated correctly, no legacy RBAC, no broader read ──

    public function test_rules_staff_separations_block_exists_and_is_sis_manage_gated(): void
    {
        $start = strpos($this->rulesSrc, 'match /staffSeparations/{docId}');
        $this->assertNotFalse($start);
        $close = strpos($this->rulesSrc, "\n    }\n", $start);
        $block = substr($this->rulesSrc, $start, $close - $start);
        $this->assertStringContainsString("hasCapabilityLevel('SIS', 'manage')", $block);
        $this->assertStringNotContainsString('allow delete', $block, 'A separation record is never deleted.');
        $this->assertStringNotContainsString('token.role in [', $block);
    }

    public function test_rules_staff_separations_read_is_not_the_same_broad_isSameSchool_as_staff(): void
    {
        $start = strpos($this->rulesSrc, 'match /staffSeparations/{docId}');
        $close = strpos($this->rulesSrc, "\n    }\n", $start);
        $block = substr($this->rulesSrc, $start, $close - $start);
        // The 'staff' collection's read rule is the bare `isSameSchool()` call
        // with no further conjunction; staffSeparations' read must ALSO
        // require isAdmin()+hasCapabilityLevel — i.e. NOT "allow read: if isSameSchool();" alone.
        $this->assertDoesNotMatchRegularExpression('/allow read:\s*if isSameSchool\(\);/', $block,
            'staffSeparations read must be narrower than the bare same-school staff read — SIS/manage required.');
    }

    // ── Scope control: nothing outside the authorized boundary touched ──

    /**
     * Originally asserted set_status() never references reportingManagerId/
     * position/department at all — true through Phase 3B.1, correctly
     * superseded by the later-authorized Rehire Workflow phase, which lets a
     * rehire optionally update these fields (see StaffRehireWorkflowTest for
     * that phase's own opt-in guards:
     * test_each_rehire_field_individually_gated_by_array_key_exists). The
     * claim that remains permanently true — none of these fields is EVER
     * written unconditionally, only behind an explicit array_key_exists()
     * opt-in — is preserved here in narrowed form.
     */
    public function test_reporting_manager_and_position_department_only_written_behind_explicit_opt_in(): void
    {
        $body = $this->setStatusBody();
        foreach (['position', 'department', 'reporting_manager_id'] as $field) {
            $this->assertStringContainsString("array_key_exists('{$field}', \$_POST)", $body,
                "Field '{$field}' must remain gated behind an explicit opt-in check, never written unconditionally.");
        }
    }
}
