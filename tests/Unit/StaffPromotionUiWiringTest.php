<?php
namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Staff HR Advanced Capability Programme — Phase 1 (Promotion UI),
 * 2026-08-18.
 *
 * Extends the Phase-I backend (StaffPromotionTest.php covers the original
 * position/department-only endpoint) with reporting-manager support, reused
 * from edit_staff()'s own existing validateManagerAssignment() call — not
 * reimplemented — and a dedicated Promotion modal in edit_staff.php,
 * deliberately outside <form id="edit_staff_form"> so it can never be swept
 * into the unrelated "Save Changes" submit.
 *
 * Source-regression tests (Firestore_service has no behavioral mock for
 * controller code; the view is HTML+JS — comment-stripping convention
 * matches every other view-touching test in this suite).
 */
class StaffPromotionUiWiringTest extends TestCase
{
    private string $controllerSrc;
    private string $viewSrc;

    protected function setUp(): void
    {
        $raw = file_get_contents(__DIR__ . '/../../application/controllers/Staff.php');
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
        $this->controllerSrc = $out;

        $this->viewSrc = file_get_contents(__DIR__ . '/../../application/views/edit_staff.php');
        $this->assertNotFalse($this->viewSrc);
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

    private function promoteBody(): string
    {
        $start = strpos($this->controllerSrc, 'public function promote_staff(');
        $this->assertNotFalse($start);
        return $this->extractFunctionBody($this->controllerSrc, $start);
    }

    private function viewCode(): string
    {
        $v = $this->viewSrc;
        $v = preg_replace('/<!--.*?-->/s', '', $v);
        $v = preg_replace('~/\*.*?\*/~s', '', $v);
        $v = preg_replace('~^\s*//.*$~m', '', $v);
        return (string) $v;
    }

    // ── Backend: reporting-manager extension ────────────────────────────

    public function test_reporting_manager_change_reuses_existing_validation(): void
    {
        $body = $this->promoteBody();
        $this->assertStringContainsString('$ehSvc->validateManagerAssignment($staffId, $newReportingMgr);', $body);
    }

    public function test_reporting_manager_only_evaluated_when_actually_submitted(): void
    {
        // array_key_exists, not just non-empty — distinguishes "field
        // omitted" (no change intended) from "field present but empty"
        // (explicit clear), same convention edit_staff() itself uses.
        $body = $this->promoteBody();
        $this->assertStringContainsString("array_key_exists('new_reporting_manager_id', \$_POST)", $body);
    }

    public function test_invalid_manager_refuses_before_any_write(): void
    {
        $body = $this->promoteBody();
        $checkPos = strpos($body, "if (!\$rmValidation['ok']) {");
        $writePos = strpos($body, "\$this->fs->updateEntity('staff', \$staffId, \$fieldUpdate);");
        $this->assertNotFalse($checkPos);
        $this->assertNotFalse($writePos);
        $this->assertLessThan($writePos, $checkPos);
    }

    public function test_manager_change_still_goes_through_the_same_locked_vocabulary(): void
    {
        // reportingManagerId is already in Staff_employment_history_service's
        // locked ATTRIBUTES list — this phase's extension rides on it, adds
        // nothing new to that vocabulary.
        $body = $this->promoteBody();
        $this->assertStringContainsString("\$ehChanges['reportingManagerId']  = \$newReportingMgr;", $body);
        $svcSrc = file_get_contents(__DIR__ . '/../../application/libraries/Staff_employment_history_service.php');
        $this->assertStringContainsString(
            "public const ATTRIBUTES = ['position', 'department', 'employmentType', 'reportingManagerId', 'employmentStatus'];",
            $svcSrc
        );
    }

    public function test_still_never_touches_salary_or_payroll(): void
    {
        $body = $this->promoteBody();
        $this->assertStringNotContainsString('salary', strtolower($body));
        $this->assertStringNotContainsString('payroll', strtolower($body));
    }

    // ── UI: modal placement and independence from the main form ─────────

    public function test_promotion_modal_exists_outside_the_main_edit_form(): void
    {
        $formClosePos = strpos($this->viewSrc, '</form>');
        $modalPos = strpos($this->viewSrc, 'id="promotionModalOverlay"');
        $this->assertNotFalse($formClosePos);
        $this->assertNotFalse($modalPos);
        $this->assertLessThan($modalPos, $formClosePos,
            'the promotion modal must be a sibling of the main form, never nested inside it — otherwise FormData(form) on Save Changes could sweep its fields into an unrelated submit.');
    }

    public function test_promotion_modal_inputs_carry_no_name_attribute(): void
    {
        // Belt-and-suspenders on top of the placement check above — even if
        // the modal were ever moved, unnamed inputs still can't be swept
        // into a FormData(form) submit.
        //
        // Trail 2 Priority 3/4 (2026-08-19): the old '<!-- ══ ACTION BAR'/
        // '/.nsa-main' fallback boundary was already imprecise — both
        // markers sit AFTER the Transfer modal too, so the computed chunk
        // always included the entire Transfer modal as well (it just never
        // had a `name="..."` attribute to trip this assertion until the
        // Work Schedule decision point's radio group added one — radios
        // genuinely need a shared `name` to group correctly, and are safe
        // here since the Transfer modal is also a sibling of
        // #edit_staff_form, never a descendant). Bounding on the Transfer
        // modal's own opening comment is the precise fix: it isolates
        // exactly the promotion modal's markup, same as intended.
        $modalStart = strpos($this->viewSrc, 'id="promotionModalOverlay"');
        $modalEnd = strpos($this->viewSrc, '<!-- ══ TRANSFER MODAL', $modalStart);
        $this->assertNotFalse($modalEnd);
        $modalHtml = substr($this->viewSrc, $modalStart, $modalEnd - $modalStart);
        $this->assertStringNotContainsString(' name="', $modalHtml);
    }

    public function test_shows_current_values_read_only_next_to_new_value_inputs(): void
    {
        $this->assertStringContainsString('id="promoCurrentPosition"', $this->viewSrc);
        $this->assertStringContainsString('id="promoNewPosition"', $this->viewSrc);
        $this->assertStringContainsString('id="promoCurrentDepartment"', $this->viewSrc);
        $this->assertStringContainsString('id="promoNewDepartment"', $this->viewSrc);
        $this->assertStringContainsString('id="promoCurrentManager"', $this->viewSrc);
        $this->assertStringContainsString('id="promoNewManager"', $this->viewSrc);
        $this->assertStringContainsString('id="promoEffectiveFrom"', $this->viewSrc);
        $this->assertStringContainsString('id="promoReason"', $this->viewSrc);
        // Current-value fields are read-only, not editable.
        $this->assertStringContainsString('id="promoCurrentPosition" class="nsa-input" disabled', $this->viewSrc);
    }

    public function test_empty_manager_selection_is_never_submitted_as_a_clear(): void
    {
        $code = $this->viewCode();
        $this->assertStringContainsString("if (newManager) fd.append('new_reporting_manager_id', newManager);", $code);
        $this->assertStringNotContainsString("fd.append('new_reporting_manager_id', newManager);\n", str_replace(
            "if (newManager) fd.append('new_reporting_manager_id', newManager);", '', $code
        ), 'the field must only be appended inside the if-guard — an unconditional append would send "" and accidentally clear an existing manager.');
    }

    public function test_reuses_existing_department_and_staff_list_endpoints_not_new_ones(): void
    {
        $this->assertStringContainsString('base_url("hr/get_departments")', $this->viewSrc);
        $this->assertStringContainsString('base_url("hr/get_staff_list")', $this->viewSrc);
    }

    public function test_submit_calls_the_correct_backend_endpoint(): void
    {
        $this->assertStringContainsString('base_url("staff/promote_staff")', $this->viewSrc);
    }

    public function test_open_button_does_not_trigger_native_form_submission(): void
    {
        $this->assertStringContainsString('id="openPromotionModalBtn"', $this->viewSrc);
        $btnPos = strpos($this->viewSrc, 'id="openPromotionModalBtn"');
        $tagStart = strrpos(substr($this->viewSrc, 0, $btnPos), '<button');
        $tag = substr($this->viewSrc, $tagStart, $btnPos - $tagStart + 40);
        $this->assertStringContainsString('type="button"', $tag);
    }
}
