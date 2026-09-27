<?php
namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * teacher_profile.php inactive-manager indicator — a display-only
 * consistency fix, not the previously-considered "Phase 5" data change.
 *
 * Consumer trace (Staff Lifecycle audit continuation) found edit_staff()
 * already resolves the current Reporting Manager's status and warns in the
 * UI when Inactive (Phase 2C) — but reportingManagerId itself is never
 * touched, and the FINAL locked policy is: never auto-clear/reassign it,
 * HR reviews and reassigns manually via Staff Edit. teacher_profile.php's
 * read-only Professional/History tabs resolved only the name, with no such
 * indicator. This closes that inconsistency by resolving the SAME status
 * value teacher_profile() already fetches the manager doc for (previously
 * only used for the name), and rendering a small warning icon — no write
 * path touched, reportingManagerId never cleared/reassigned anywhere.
 *
 * Source-regression tests (Firestore_service has no behavioral mock for
 * controller code — see StaffCreationAuditMetadataTest's docblock).
 */
class TeacherProfileInactiveManagerIndicatorTest extends TestCase
{
    private string $staffSrc;
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
        $this->staffSrc = $out;

        $this->viewSrc = file_get_contents(__DIR__ . '/../../application/views/teacher_profile.php');
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

    private function teacherProfileBody(): string
    {
        $start = strpos($this->staffSrc, 'function teacher_profile(');
        $this->assertNotFalse($start);
        return $this->extractFunctionBody($this->staffSrc, $start);
    }

    // ── Controller: resolves status, read-only, additive ──────────────────

    public function test_resolves_reporting_manager_status_alongside_name(): void
    {
        $body = $this->teacherProfileBody();
        $this->assertStringContainsString('$reportingManagerStatus', $body);
        $this->assertStringContainsString("(string) (\$rmDoc['status'] ?? \$rmDoc['Status'] ?? '')", $body);
    }

    public function test_status_passed_to_view(): void
    {
        $body = $this->teacherProfileBody();
        $this->assertStringContainsString("'reportingManagerStatus' => \$reportingManagerStatus,", $body);
    }

    public function test_reporting_manager_id_never_written_or_cleared_in_teacher_profile(): void
    {
        $body = $this->teacherProfileBody();
        $this->assertStringNotContainsString('updateEntity(', $body);
        $this->assertStringNotContainsString("'reportingManagerId' => ''", $body);
        $this->assertStringNotContainsString("reportingManagerId'] = ", $body);
    }

    public function test_no_locked_policy_violation_no_reassignment_logic(): void
    {
        // Must never select/infer a replacement manager here.
        $body = $this->teacherProfileBody();
        $this->assertStringNotContainsString('replacement', $body);
        $this->assertStringNotContainsString('reassign', $body);
    }

    // ── View: renders the warning only when Inactive, in both surfaces ────

    public function test_view_renders_inactive_indicator_condition_in_professional_tab(): void
    {
        $profPos = strpos($this->viewSrc, 'Reporting Manager');
        $this->assertNotFalse($profPos);
        $window = substr($this->viewSrc, $profPos, 500);
        $this->assertStringContainsString("(\$reportingManagerStatus ?? '') === 'Inactive'", $window);
        $this->assertStringContainsString('currently Inactive', $window);
    }

    public function test_view_renders_inactive_indicator_in_history_current_state_summary(): void
    {
        $histPos = strpos($this->viewSrc, 'tp-hist-current-lbl">Reporting Manager');
        $this->assertNotFalse($histPos);
        $window = substr($this->viewSrc, $histPos, 500);
        $this->assertStringContainsString("(\$reportingManagerStatus ?? '') === 'Inactive'", $window);
    }

    public function test_indicator_only_shows_when_a_manager_name_is_actually_present(): void
    {
        // Guards against showing a warning icon next to an empty "no manager"
        // state — !empty($reportingManagerName) must gate the icon.
        $occurrences = substr_count($this->viewSrc, "!empty(\$reportingManagerName) && ((\$reportingManagerStatus ?? '') === 'Inactive')");
        $this->assertSame(2, $occurrences, 'Both surfaces (Professional tab + History current-state) must gate the icon on a non-empty manager name.');
    }

    public function test_no_new_rbac_or_write_endpoint_introduced(): void
    {
        $this->assertStringNotContainsString('token.role', $this->viewSrc);
        $this->assertStringNotContainsString('staff/clear_reporting_manager', $this->viewSrc);
        $this->assertStringNotContainsString('staff/reassign', $this->viewSrc);
    }
}
