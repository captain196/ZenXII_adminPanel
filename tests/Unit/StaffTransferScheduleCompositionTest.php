<?php
namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Trail 2 — Transfer × Work Schedule architecture decision, Priority 3/4
 * (2026-08-19).
 *
 * Priority 3: resolve the RBAC mismatch between Staff::transfer_staff()
 * (SIS:manage — the convention for every endpoint in this controller) and
 * Work_schedules::save_assignment() (HR:manage — its own module's existing
 * gate). Confirmed by direct read of every _require_role() call in
 * Staff.php: SIS:manage is the WHOLE CONTROLLER's established convention,
 * not a one-off mistake in transfer_staff() — so the fix is not to change
 * either gate (that would be a real, unauthorized RBAC change), but to
 * compose them correctly: the optional Work Schedule step is offered only
 * to an admin who independently holds HR:manage, checked via
 * has_permission('HR', 'manage') at render time. Because RBAC levels are
 * graded (manage ⊇ edit ⊇ view — confirmed by has_permission()'s
 * rbac_level_rank() comparison), this single flag is sufficient for both
 * previewing the current assignment (work_schedules/get_data, HR:view) and
 * saving the new one (work_schedules/save_assignment, HR:manage).
 *
 * Priority 4: build the actual optional Transfer→Work Schedule composition
 * — entirely at the UI level (edit_staff.php's submitTransfer()), calling
 * the EXISTING, UNMODIFIED Work_schedules::save_assignment() to close the
 * old staff-level assignment and open the new one. transfer_staff() itself
 * is untouched — it still does not read or write staffScheduleAssignments.
 * On failure, the already-successful transfer is never rolled back;
 * instead a persistent (Firestore-backed staff.pendingScheduleReview),
 * dismissible reminder is recorded via two new small endpoints that only
 * ever write to the `staff` collection — never a second writer for
 * staffScheduleAssignments.
 *
 * Source-regression tests (Firestore_service has no behavioral mock for
 * controller/view code).
 */
class StaffTransferScheduleCompositionTest extends TestCase
{
    private string $staffSrc;
    private string $viewSrc;

    protected function setUp(): void
    {
        foreach ([
            'staffSrc' => __DIR__ . '/../../application/controllers/Staff.php',
            'viewSrc'  => __DIR__ . '/../../application/views/edit_staff.php',
        ] as $prop => $path) {
            $raw = file_get_contents($path);
            $this->assertNotFalse($raw);
            $out = '';
            foreach (token_get_all($raw) as $token) {
                if (is_array($token)) {
                    if ($token[0] === T_COMMENT || $token[0] === T_DOC_COMMENT) continue;
                    $out .= $token[1];
                } else {
                    $out .= $token;
                }
            }
            $this->$prop = $out;
        }
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

    private function fn(string $name): string
    {
        $start = strpos($this->staffSrc, 'function ' . $name . '(');
        $this->assertNotFalse($start, "{$name}() must exist in Staff.php.");
        return $this->extractFunctionBody($this->staffSrc, $start);
    }

    // ── Priority 3: RBAC composition (not a gate change) ─────────────────

    public function test_every_staff_php_endpoint_still_gates_on_sis_manage_convention(): void
    {
        // Confirms the RBAC "mismatch" was never a bug in transfer_staff()
        // specifically — every endpoint in this controller uses the same
        // module. Changing transfer_staff()'s own gate was correctly
        // rejected as the fix.
        $count = substr_count($this->staffSrc, '_require_role(self::MANAGE_ROLES, "", "SIS", "manage")');
        $this->assertGreaterThan(10, $count, 'SIS:manage must remain the dominant convention across Staff.php.');
    }

    public function test_transfer_staff_gate_is_unchanged(): void
    {
        $body = $this->fn('transfer_staff');
        $this->assertStringContainsString('$this->_require_role(self::MANAGE_ROLES, "", "SIS", "manage");', $body);
    }

    public function test_transfer_staff_still_never_touches_work_schedule_collections(): void
    {
        $body = $this->fn('transfer_staff');
        $this->assertStringNotContainsString('staffScheduleAssignments', $body);
        $this->assertStringNotContainsString('save_assignment', $body);
        $this->assertStringNotContainsString('Work_schedule', $body);
        $this->assertStringNotContainsString('SCHEDULE_REVIEW_FIELD', $body,
            'the reminder flag is set from the UI composition failure path, never from transfer_staff() itself.');
    }

    public function test_edit_staff_view_render_branch_computes_hr_manage_via_graded_permission_check(): void
    {
        $start = strpos($this->staffSrc, 'function edit_staff(');
        $this->assertNotFalse($start);
        $body = $this->extractFunctionBody($this->staffSrc, $start);
        $this->assertStringContainsString("has_permission('HR', 'manage')", $body);
        $this->assertStringContainsString("'hr_manage'", $body);
    }

    // ── Priority 4: the two new reminder endpoints ────────────────────────

    public function test_flag_pending_schedule_review_exists_gates_correctly_and_only_writes_staff_collection(): void
    {
        $body = $this->fn('flag_pending_schedule_review');
        $this->assertStringContainsString('$this->_require_role(self::MANAGE_ROLES, "", "SIS", "manage");', $body);
        $this->assertStringContainsString("updateEntity('staff', \$staffId,", $body);
        $this->assertStringContainsString('self::SCHEDULE_REVIEW_FIELD', $body);
        $this->assertStringNotContainsString('staffScheduleAssignments', $body,
            'never a second writer for the schedule-assignment collection.');
    }

    public function test_clear_pending_schedule_review_exists_gates_correctly_and_nulls_the_field(): void
    {
        $body = $this->fn('clear_pending_schedule_review');
        $this->assertStringContainsString('$this->_require_role(self::MANAGE_ROLES, "", "SIS", "manage");', $body);
        $this->assertStringContainsString("self::SCHEDULE_REVIEW_FIELD => null", $body);
    }

    public function test_schedule_review_field_constant_defined_once(): void
    {
        $this->assertEquals(1, substr_count($this->staffSrc, "private const SCHEDULE_REVIEW_FIELD = 'pendingScheduleReview';"));
    }

    // ── View: decision point gated by hr_manage, never inferred from department ──

    public function test_transfer_modal_schedule_decision_block_is_gated_by_hr_manage(): void
    {
        $pos = strpos($this->viewSrc, 'id="transferModalOverlay"');
        $this->assertNotFalse($pos);
        $closePos = strpos($this->viewSrc, 'closeTransferModal()', $pos);
        $modalMarkup = substr($this->viewSrc, $pos, $closePos - $pos);
        $this->assertStringContainsString("if (!empty(\$hr_manage)):", $modalMarkup);
        $this->assertStringContainsString('Does this employee also need a new Work Schedule?', $modalMarkup);
        $this->assertStringContainsString('name="transferScheduleChoice"', $modalMarkup);
    }

    public function test_schedule_choice_defaults_to_keep_current_never_pre_selected_to_change(): void
    {
        $pos = strpos($this->viewSrc, 'value="keep" checked');
        $this->assertNotFalse($pos, 'the "keep current" radio must be the one marked checked by default.');
        $changePos = strpos($this->viewSrc, 'value="change" onchange');
        $this->assertNotFalse($changePos);
        $this->assertStringNotContainsString('value="change" checked', $this->viewSrc);
    }

    public function test_passive_hint_only_renders_when_hr_manage_is_false(): void
    {
        $pos = strpos($this->viewSrc, 'id="transferModalOverlay"');
        $closePos = strpos($this->viewSrc, 'Effective Date', $pos);
        $introMarkup = substr($this->viewSrc, $pos, $closePos - $pos);
        $this->assertStringContainsString('if (empty($hr_manage)):', $introMarkup);
        $this->assertStringContainsString('set that separately under', $introMarkup);
    }

    // ── JS: composition sequencing and independent-facts failure handling ──

    private function jsFn(string $name): string
    {
        $start = strpos($this->viewSrc, 'function ' . $name . '(');
        $this->assertNotFalse($start, "JS function {$name}() must exist.");
        return $this->extractFunctionBody($this->viewSrc, $start);
    }

    public function test_submit_transfer_never_attempts_schedule_composition_before_transfer_succeeds(): void
    {
        $body = $this->jsFn('submitTransfer');
        $transferAjaxPos = strpos($body, "url: '<?= base_url(\"staff/transfer_staff\") ?>'");
        $composePos = strpos($body, '_composeTransferScheduleChange(');
        $this->assertNotFalse($transferAjaxPos);
        $this->assertNotFalse($composePos);
        $this->assertGreaterThan($transferAjaxPos, $composePos,
            'composition must only be attempted from inside the transfer_staff() success callback.');
    }

    public function test_submit_transfer_never_rolls_back_a_successful_transfer_on_composition_failure(): void
    {
        $body = $this->jsFn('submitTransfer');
        // Composition failure must never call transfer_staff() again, nor
        // any endpoint that would revert the department/manager change
        // already committed — only the read-only get_data + the existing
        // save_assignment writer + the reminder-flag endpoint.
        $this->assertStringNotContainsString('delete_assignment', $body);
        $this->assertEquals(1, substr_count($body, 'staff/transfer_staff'),
            'transfer_staff() must be called exactly once — never retried/reverted from the composition failure path.');
        $this->assertStringContainsString('.catch(function (err) {', $body);
        $this->assertStringContainsString('_flagPendingScheduleReview(', $body);
    }

    public function test_compose_schedule_change_closes_existing_staff_level_assignment_before_creating_the_new_one(): void
    {
        $body = $this->jsFn('_composeTransferScheduleChange');
        $closeCallPos = strpos($body, "fd.append('assignment_id', current.assignmentId);");
        $createCallPos = strpos($body, "fd.append('schedule_id', newScheduleId);");
        $this->assertNotFalse($closeCallPos);
        $this->assertNotFalse($createCallPos);
        $this->assertLessThan($createCallPos, $closeCallPos,
            'the old assignment must be closed before the new one is created — Policy_assignment_service::findOverlaps() would otherwise reject the create.');
    }

    public function test_compose_schedule_change_only_targets_staff_level_open_assignments(): void
    {
        $body = $this->jsFn('_composeTransferScheduleChange');
        $this->assertStringContainsString("a.attachmentType === 'staff'", $body);
        $this->assertStringContainsString('!a.effectiveTo', $body);
    }

    public function test_compose_schedule_change_calls_only_the_existing_save_assignment_endpoint(): void
    {
        $body = $this->jsFn('_composeTransferScheduleChange');
        $this->assertEquals(2, substr_count($body, 'work_schedules/save_assignment'),
            'exactly two calls to the existing writer: one close, one create.');
    }

    public function test_flag_pending_schedule_review_js_posts_to_the_new_backend_endpoint(): void
    {
        $body = $this->jsFn('_flagPendingScheduleReview');
        $this->assertStringContainsString("staff/flag_pending_schedule_review", $body);
    }

    public function test_dismiss_schedule_review_js_posts_to_the_clear_endpoint(): void
    {
        $body = $this->jsFn('dismissScheduleReview');
        $this->assertStringContainsString('staff/clear_pending_schedule_review', $body);
    }

    // ── No DOM-id-shadows-global-function-name regressions (established pattern) ──

    public function test_no_element_id_shadows_a_global_function_name(): void
    {
        preg_match_all('/id="([A-Za-z_][A-Za-z0-9_]*)"/', $this->viewSrc, $idMatches);
        $ids = array_unique($idMatches[1]);
        preg_match_all('/\bfunction\s+([A-Za-z_][A-Za-z0-9_]*)\s*\(/', $this->viewSrc, $fnMatches);
        $fns = array_unique($fnMatches[1]);
        $collisions = array_intersect($ids, $fns);
        $this->assertEmpty($collisions, 'DOM id(s) shadow global function name(s): ' . implode(', ', $collisions));
    }
}
