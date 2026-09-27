<?php
namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Staff HR Advanced Capability Programme — Phase 6: Structured Onboarding
 * (2026-08-19).
 *
 * Confirmed green-field by a dedicated research pass: new_staff() creates a
 * staff RECORD only — no post-hire task/checklist concept (IT setup, ID
 * card, orientation, documents collected) existed anywhere in this
 * codebase. Modeled directly on Phase 3's Clearance/Handover — the closest
 * existing precedent for "a tenant-configurable, per-item checklist with a
 * recomputed overall completion state" — same collection-naming shape
 * (staffOnboarding/{schoolId}_{staffId}), same manual-initiate +
 * per-item-update + recomputed-overall-status choreography, same
 * tenant-configurable-list-with-sane-default pattern (schools.onboardingTasks).
 *
 * Deliberately MANUAL-initiate, never auto-triggered from new_staff() — the
 * already-proven creation flow is never touched.
 *
 * Source-regression tests (Firestore_service has no behavioral mock for
 * controller/view code).
 */
class StaffOnboardingChecklistTest extends TestCase
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

    private function jsFn(string $name): string
    {
        $start = strpos($this->viewSrc, 'function ' . $name . '(');
        $this->assertNotFalse($start, "JS function {$name}() must exist.");
        return $this->extractFunctionBody($this->viewSrc, $start);
    }

    // ── Constants ──────────────────────────────────────────────────────

    public function test_onboarding_collection_and_default_tasks_defined_once(): void
    {
        $this->assertEquals(1, substr_count($this->staffSrc, "private const ONBOARDING_COL = 'staffOnboarding';"));
        $this->assertStringContainsString('ONBOARDING_DEFAULT_TASKS', $this->staffSrc);
        $this->assertStringContainsString("ONBOARDING_TASK_STATUSES = ['pending', 'done', 'skipped'];", $this->staffSrc);
    }

    public function test_onboarding_doc_id_matches_the_clearance_shape(): void
    {
        $body = $this->fn('_onboarding_doc_id');
        $this->assertStringContainsString('return "{$this->school_id}_{$staffId}";', $body);
    }

    public function test_onboarding_tasks_are_tenant_configurable_with_sane_default(): void
    {
        $body = $this->fn('_onboarding_tasks');
        $this->assertStringContainsString("\$school['onboardingTasks']", $body);
        $this->assertStringContainsString('return self::ONBOARDING_DEFAULT_TASKS;', $body);
    }

    // ── get_onboarding() ──────────────────────────────────────────────

    public function test_get_onboarding_is_view_gated_and_read_only(): void
    {
        $body = $this->fn('get_onboarding');
        $this->assertStringContainsString('$this->_require_role(self::VIEW_ROLES, "", "SIS", "view");', $body);
        $this->assertStringNotContainsString('->set(', $body);
        $this->assertStringNotContainsString('->update(', $body);
    }

    // ── initiate_onboarding() ─────────────────────────────────────────

    public function test_initiate_onboarding_is_manage_gated(): void
    {
        $body = $this->fn('initiate_onboarding');
        $this->assertStringContainsString('$this->_require_role(self::MANAGE_ROLES, "", "SIS", "manage");', $body);
    }

    public function test_initiate_onboarding_refuses_a_second_concurrent_checklist(): void
    {
        $body = $this->fn('initiate_onboarding');
        $this->assertStringContainsString("(string) (\$existing['status'] ?? '') === 'in_progress'", $body);
        $this->assertStringContainsString('already in progress', $body);
    }

    public function test_initiate_onboarding_opens_one_entry_per_configured_task_all_pending(): void
    {
        $body = $this->fn('initiate_onboarding');
        $this->assertStringContainsString('$tasks = $this->_onboarding_tasks();', $body);
        $this->assertStringContainsString("'status' => 'pending', 'note' => '', 'decidedBy' => null, 'decidedAt' => null", $body);
        $this->assertStringContainsString("'status'       => 'in_progress',", $body);
    }

    public function test_initiate_onboarding_never_auto_triggers_from_new_staff(): void
    {
        // Confirms the deliberate manual-initiate design — new_staff()'s
        // own body must never call initiate_onboarding() or write to the
        // onboarding collection itself.
        $start = strpos($this->staffSrc, 'function new_staff(');
        $this->assertNotFalse($start, 'new_staff() must exist.');
        $body = $this->extractFunctionBody($this->staffSrc, $start);
        $this->assertStringNotContainsString('initiate_onboarding', $body);
        $this->assertStringNotContainsString('ONBOARDING_COL', $body);
    }

    // ── update_onboarding_task() ──────────────────────────────────────

    public function test_update_onboarding_task_is_manage_gated_and_validates_status(): void
    {
        $body = $this->fn('update_onboarding_task');
        $this->assertStringContainsString('$this->_require_role(self::MANAGE_ROLES, "", "SIS", "manage");', $body);
        $this->assertStringContainsString('in_array($status, self::ONBOARDING_TASK_STATUSES, true)', $body);
    }

    public function test_update_onboarding_task_refuses_a_task_not_in_the_checklist(): void
    {
        $body = $this->fn('update_onboarding_task');
        $this->assertStringContainsString('array_key_exists($task, $tasks)', $body);
        $this->assertStringContainsString('is not part of this onboarding checklist', $body);
    }

    public function test_update_onboarding_task_refuses_editing_an_already_completed_checklist(): void
    {
        $body = $this->fn('update_onboarding_task');
        $this->assertStringContainsString("(string) (\$onboarding['status'] ?? '') !== 'in_progress'", $body);
        $this->assertStringContainsString('already completed', $body);
    }

    public function test_overall_completion_requires_every_task_done_or_skipped_never_auto_resolves(): void
    {
        $body = $this->fn('update_onboarding_task');
        $this->assertStringContainsString('$allResolved = true;', $body);
        $this->assertStringContainsString("\$st !== 'done' && \$st !== 'skipped'", $body);
        $this->assertStringContainsString("\$patch['status'] = 'completed';", $body);
    }

    public function test_update_onboarding_task_never_silently_marks_a_task_done_when_skipped(): void
    {
        // 'skipped' must remain its own distinct status, not collapsed into
        // 'done' — the whole point of the 3-state model is that "not
        // applicable" is never misrepresented as "actually completed".
        $body = $this->fn('update_onboarding_task');
        $this->assertStringContainsString("'status'    => \$status,", $body);
        $this->assertStringNotContainsString("\$status = 'done';", $body);
    }

    // ── No second writer / no entanglement with other modules ─────────

    public function test_onboarding_endpoints_never_touch_unrelated_collections(): void
    {
        foreach (['get_onboarding', 'initiate_onboarding', 'update_onboarding_task'] as $f) {
            $body = $this->fn($f);
            $this->assertStringNotContainsString('staffScheduleAssignments', $body);
            $this->assertStringNotContainsString('staffEmploymentTimeline', $body);
            $this->assertStringNotContainsString('salarySlips', $body);
            $this->assertStringNotContainsString('appraisals', $body);
        }
    }

    // ── View wiring ────────────────────────────────────────────────────

    public function test_onboarding_button_and_modal_exist_outside_the_main_form(): void
    {
        $formClosePos = strpos($this->viewSrc, '</form>');
        $modalPos = strpos($this->viewSrc, 'id="onboardingModalOverlay"');
        $this->assertNotFalse($formClosePos);
        $this->assertNotFalse($modalPos);
        $this->assertLessThan($modalPos, $formClosePos);
        $this->assertStringContainsString('id="openOnboardingModalBtn"', $this->viewSrc);
    }

    public function test_onboarding_js_calls_the_correct_three_backend_endpoints(): void
    {
        $this->assertStringContainsString('base_url("staff/get_onboarding")', $this->viewSrc);
        $this->assertStringContainsString('base_url("staff/initiate_onboarding")', $this->viewSrc);
        $this->assertStringContainsString('base_url("staff/update_onboarding_task")', $this->viewSrc);
    }

    public function test_onboarding_render_state_offers_done_and_na_never_a_third_pending_button(): void
    {
        $body = $this->jsFn('onboardingRenderState');
        $this->assertStringContainsString("onboardingUpdateTask(' + JSON.stringify(task) + ',\\'done\\')", $body);
        $this->assertStringContainsString("onboardingUpdateTask(' + JSON.stringify(task) + ',\\'skipped\\')", $body);
        $this->assertStringNotContainsString("',\\'pending\\')", $body);
    }

    // ── No DOM-id-shadows-global-function-name regression (established pattern) ──

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
