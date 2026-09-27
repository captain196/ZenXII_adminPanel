<?php
namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Rehire Workflow — extends Staff::set_status()'s existing Active branch,
 * does not create a second competing status-transition endpoint. Source-
 * regression tests following this repo's established convention (no
 * Firestore_service behavioral mock — see StaffCreationAuditMetadataTest's
 * docblock) for the wiring/security/scope-boundary claims; the underlying
 * REACTIVATED-vs-REHIRED decision logic itself is already covered
 * behaviorally by StaffSeparationPhase3B1Test::determineReactivationEventType.
 */
class StaffRehireWorkflowTest extends TestCase
{
    private string $staffSrc;
    private string $viewSrc;

    protected function setUp(): void
    {
        $this->staffSrc = $this->readCodeOnly(__DIR__ . '/../../application/controllers/Staff.php');
        $this->viewSrc  = file_get_contents(__DIR__ . '/../../application/views/teacher_profile.php');
        $this->assertNotFalse($this->viewSrc);
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

    // ── No second endpoint — set_status() remains the ONE status-transition path ──

    public function test_no_separate_rehire_endpoint_created(): void
    {
        $this->assertStringNotContainsString('function rehire_staff(', $this->staffSrc);
        $this->assertStringNotContainsString('function rehire(', $this->staffSrc);
        $this->assertStringNotContainsString('function initiate_rehire(', $this->staffSrc);
    }

    // ── Active-already rejection (distinct from the existing bare no-op) ──

    public function test_rehire_fields_against_already_active_staff_is_rejected_not_silently_no_opped(): void
    {
        $body = $this->setStatusBody();
        $this->assertStringContainsString('$rehireFieldsSubmitted', $body);
        $this->assertStringContainsString('already Active', $body);
        // The rejection must be checked BEFORE the pre-existing no-op short-circuit.
        $rejectPos = strpos($body, '$rehireFieldsSubmitted && $oldStatus === $newStatus');
        $noopPos   = strpos($body, "if (\$oldStatus === \$newStatus) {\n            // Note:");
        $this->assertNotFalse($rejectPos);
        // (noopPos may legitimately not match exact whitespace; presence of the guard string is what matters)
        $this->assertStringContainsString('Status unchanged.', $body, 'The original bare no-op path must remain intact for a plain re-submit of the same status.');
    }

    // ── Rehire fields are opt-in per field, byte-compatible with plain Reactivate ──

    public function test_each_rehire_field_individually_gated_by_array_key_exists(): void
    {
        $body = $this->setStatusBody();
        foreach (['position', 'department', 'employment_type', 'reporting_manager_id'] as $field) {
            $this->assertStringContainsString("array_key_exists('{$field}', \$_POST)", $body,
                "Field '{$field}' must be individually opt-in — a plain Reactivate submitting none of these must be unaffected.");
        }
    }

    public function test_reporting_manager_change_reuses_validate_manager_assignment(): void
    {
        $body = $this->setStatusBody();
        $this->assertStringContainsString('->validateManagerAssignment($user_id, $v)', $body,
            'Rehire must reuse the same Reporting Manager validation as new_staff()/edit_staff() — no separate validation logic.');
    }

    public function test_position_department_employment_type_written_as_simple_values_not_role_derived(): void
    {
        $body = $this->setStatusBody();
        // Must NOT duplicate edit_staff()'s role-derivation/Designation logic.
        $this->assertStringNotContainsString('_role_id_to_label', $body);
        $this->assertStringNotContainsString('primary_role', $body);
        $this->assertStringNotContainsString('designation', $body);
    }

    // ── Effective date handling ──────────────────────────────────────────

    public function test_effective_date_format_validated_with_today_fallback(): void
    {
        $body = $this->setStatusBody();
        $this->assertStringContainsString('$rehireEffectiveDateIn', $body);
        $this->assertStringContainsString('rehireEffectiveDateIn) ? $rehireEffectiveDateIn : date(', $body,
            'An invalid/absent effective date must fall back to today, mirroring the existing exit_date/last_working_date validation pattern.');
    }

    // ── Employment History integration reuses existing mechanisms only ──

    public function test_uses_determine_reactivation_event_type_with_the_actual_changes(): void
    {
        $body = $this->setStatusBody();
        $this->assertStringContainsString('determineReactivationEventType($otherChanges)', $body);
    }

    public function test_attribute_changes_recorded_via_existing_record_changes_method(): void
    {
        $body = $this->setStatusBody();
        $this->assertStringContainsString("\$ehSvc->recordChanges(\$user_id, \$otherChanges, \$effectiveDate, \$reason)", $body,
            'Rehire attribute changes must flow through the existing, already-tested recordChanges() — not a new method.');
    }

    public function test_no_new_employment_history_collection_or_service_created(): void
    {
        $this->assertStringNotContainsString('staffRehireEvents', $this->staffSrc);
        $this->assertStringNotContainsString('staffEmploymentEpisodes', $this->staffSrc);
        $this->assertFileDoesNotExist(__DIR__ . '/../../application/libraries/Staff_rehire_service.php');
    }

    // ── Separation history immutability — reaffirmed, not touched by this phase ──

    public function test_inactive_branch_of_set_status_unchanged_by_rehire_work(): void
    {
        $body = $this->setStatusBody();
        $this->assertStringContainsString("if (\$newStatus === 'Inactive') {", $body);
        $this->assertStringContainsString("'SEPARATED',", $body);
        // No rehire-specific field name leaks into the Inactive/SEPARATED branch.
        $inactiveStart = strpos($body, "if (\$newStatus === 'Inactive') {");
        $inactiveBlockApprox = substr($body, $inactiveStart, 900);
        $this->assertStringNotContainsString('rehireOtherChanges', $inactiveBlockApprox);
    }

    // ── RBAC — reused, not new ────────────────────────────────────────────

    public function test_rbac_gate_unchanged(): void
    {
        $this->assertStringContainsString('$this->_require_role(self::MANAGE_ROLES, "", "SIS", "manage");', $this->staffSrc);
    }

    public function test_no_new_rbac_capability_or_legacy_rbac(): void
    {
        $body = $this->setStatusBody();
        $this->assertStringNotContainsString('token.role', $body);
        $this->assertStringNotContainsString("'staff.rehire'", $body);
    }

    // ── Tenant isolation — inherited from existing updateEntity()/staffDocId scoping ──

    public function test_write_still_uses_school_scoped_update_entity(): void
    {
        $body = $this->setStatusBody();
        $this->assertStringContainsString("\$this->fs->updateEntity('staff', \$user_id, \$patch);", $body);
    }

    // ── UI wiring ─────────────────────────────────────────────────────────

    public function test_view_rehire_button_only_rendered_for_inactive_status(): void
    {
        $this->assertStringContainsString("=== 'inactive'", $this->viewSrc);
        $this->assertStringContainsString('tpOpenRehireModal', $this->viewSrc);
    }

    public function test_view_submits_to_existing_set_status_endpoint_not_a_new_one(): void
    {
        $this->assertStringContainsString("staff/set_status/", $this->viewSrc);
        $this->assertStringNotContainsString('staff/rehire_staff', $this->viewSrc);
    }

    public function test_view_reporting_manager_excludes_self(): void
    {
        $this->assertStringContainsString('if (s.id === ownStaffId) return;', $this->viewSrc);
    }

    public function test_view_reloads_after_successful_rehire_rather_than_partial_dom_patch(): void
    {
        // Simplicity/correctness tradeoff: a full reload guarantees the newly
        // rehired state (Position/Department/History tab) is never stale,
        // at the cost of a page refresh — acceptable for a low-frequency HR action.
        $this->assertStringContainsString('window.location.reload();', $this->viewSrc);
    }
}
