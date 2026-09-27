<?php
namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Staff HR Advanced Capability Programme — Phase I (Promotion), 2026-08-18.
 *
 * Deliberately does NOT extend Staff_employment_history_service's own
 * "Phase 1+2C+3B.1 locked vocabulary — do not extend without a new
 * authorized phase" event-type list. Promotion reuses the EXISTING,
 * unmodified POSITION_CHANGED/DEPARTMENT_CHANGED generic attribute-change
 * events (already effective-dated, already history-preserving — confirmed
 * by the edit_staff() call site) via recordChanges()'s own pre-existing
 * $reason/$effectiveFrom parameters, which the generic edit form never
 * populated. No new event type, no service modification.
 *
 * Source-regression tests (Firestore_service has no behavioral mock for
 * controller code).
 */
class StaffPromotionTest extends TestCase
{
    private string $src;

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
        $this->src = $out;
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
        $start = strpos($this->src, 'public function promote_staff(');
        $this->assertNotFalse($start);
        return $this->extractFunctionBody($this->src, $start);
    }

    public function test_gated_at_manage_tier_same_sis_module(): void
    {
        $body = $this->promoteBody();
        $this->assertStringContainsString('_require_role(self::MANAGE_ROLES, "", "SIS", "manage")', $body);
    }

    public function test_refuses_when_nothing_actually_changes(): void
    {
        $body = $this->promoteBody();
        $this->assertStringContainsString('if (empty($ehChanges)) {', $body);
        // Phase 1 (Promotion UI, 2026-08-18) widened this endpoint to also
        // accept a reporting-manager change, so the refusal message was
        // reworded from "position/department" to name all three fields —
        // a legitimate wording update for a legitimately wider scope, not
        // a weakened assertion (the underlying "nothing changed → refuse"
        // guard, checked above, is unchanged).
        $this->assertStringContainsString(
            "'At least one of position, department, or reporting manager must differ from the current value.'",
            $body
        );
    }

    public function test_refuses_for_inactive_staff(): void
    {
        $body = $this->promoteBody();
        $this->assertStringContainsString("if (strtolower(trim(\$rawStatus)) !== 'active') {", $body);
    }

    public function test_reuses_existing_generic_attribute_change_events_not_a_new_type(): void
    {
        $body = $this->promoteBody();
        $this->assertStringContainsString('->recordChanges($staffId, $ehChanges, $effectiveFrom, $reason);', $body);
        // No new event-type constant/string is introduced anywhere in this
        // controller for promotion — it rides on 'position'/'department'
        // keys, which recordChanges() itself maps to the existing locked
        // POSITION_CHANGED/DEPARTMENT_CHANGED vocabulary.
        $this->assertStringNotContainsString("'PROMOTED'", $this->src);
    }

    public function test_locked_vocabulary_in_the_service_itself_is_untouched(): void
    {
        $svcSrc = file_get_contents(__DIR__ . '/../../application/libraries/Staff_employment_history_service.php');
        $this->assertStringContainsString(
            "public const ATTRIBUTES = ['position', 'department', 'employmentType', 'reportingManagerId', 'employmentStatus'];",
            $svcSrc,
            'the locked ATTRIBUTES vocabulary must be byte-identical to before this phase.'
        );
        $this->assertStringContainsString(
            "private const EMPLOYMENT_STATUS_EVENT_TYPES = ['SEPARATED', 'REACTIVATED', 'REHIRED'];",
            $svcSrc
        );
    }

    public function test_effective_date_validated_when_provided(): void
    {
        $body = $this->promoteBody();
        $this->assertStringContainsString(
            "if (\$effectiveFrom !== '' && !preg_match('/^\\d{4}-\\d{2}-\\d{2}\$/', \$effectiveFrom)) {",
            $body
        );
    }

    public function test_dual_cased_fields_written_matching_the_rest_of_the_controller(): void
    {
        $body = $this->promoteBody();
        $this->assertStringContainsString("\$fieldUpdate['position'] = \$newPosition;", $body);
        $this->assertStringContainsString("\$fieldUpdate['Position'] = \$newPosition;", $body);
        $this->assertStringContainsString("\$fieldUpdate['department'] = \$newDepartment;", $body);
        $this->assertStringContainsString("\$fieldUpdate['Department'] = \$newDepartment;", $body);
    }

    public function test_never_touches_salary_or_payroll(): void
    {
        $body = $this->promoteBody();
        $this->assertStringNotContainsString('salary', strtolower($body));
        $this->assertStringNotContainsString('payroll', strtolower($body));
    }

    public function test_history_failure_is_a_soft_warning_not_a_hard_failure(): void
    {
        // Matches the existing authWarning/phoneIndexWarning/
        // employmentHistoryWarning pattern already used by edit_staff() —
        // the staff-record write already succeeded; history is
        // best-effort, never silently swallowed, never a fatal error.
        $body = $this->promoteBody();
        $this->assertStringContainsString('$historyWarning = ', $body);
        $this->assertStringContainsString("if (\$historyWarning !== null) \$resp['warning'] = \$historyWarning;", $body);
    }

    public function test_promotion_is_audit_logged(): void
    {
        $this->assertStringContainsString("log_audit('Staff', 'promotion', \$staffId, \$reason);", $this->src);
    }

    public function test_field_update_written_before_history_recorded(): void
    {
        // The staff doc's current-state write must be confirmed FIRST —
        // same sequencing guarantee Staff_employment_history_service's own
        // docblock requires of every caller.
        $body = $this->promoteBody();
        $writePos = strpos($body, "\$this->fs->updateEntity('staff', \$staffId, \$fieldUpdate);");
        $historyPos = strpos($body, '->recordChanges(');
        $this->assertNotFalse($writePos);
        $this->assertNotFalse($historyPos);
        $this->assertLessThan($historyPos, $writePos);
    }
}
