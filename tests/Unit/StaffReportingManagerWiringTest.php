<?php
namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Phase 2C — Reporting Manager wiring/sequencing/scope-boundary checks,
 * following the same literal-source-regression convention as
 * StaffEmploymentHistoryWiringTest (Phase 1) and
 * StaffCreationAuditMetadataTest — Firestore_service has no behavioral
 * mock, so controller-level wiring is proven by asserting on
 * comment-stripped source, not by instantiating Staff.php.
 */
class StaffReportingManagerWiringTest extends TestCase
{
    private string $staffSrc;
    private string $atsSrc;
    private string $serviceSrc;
    private string $rulesSrc;

    protected function setUp(): void
    {
        $this->staffSrc   = $this->readCodeOnly(__DIR__ . '/../../application/controllers/Staff.php');
        $this->atsSrc     = $this->readCodeOnly(__DIR__ . '/../../application/controllers/Ats.php');
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

    // ── Wired into the correct 3 paths, validation present ──────────────

    public function test_new_staff_validates_and_wires_reporting_manager(): void
    {
        $start = strpos($this->staffSrc, 'function new_staff(');
        $body  = $this->extractFunctionBody($this->staffSrc, $start);
        $this->assertStringContainsString('->validateManagerAssignment(', $body);
        $this->assertStringContainsString('->recordJoined(', $body);
        $this->assertStringContainsString("'reportingManagerId'", $body);
    }

    public function test_commit_staff_row_includes_blank_reporting_manager_in_joined(): void
    {
        $start = strpos($this->staffSrc, 'function _commit_staff_row(');
        $body  = $this->extractFunctionBody($this->staffSrc, $start);
        $this->assertStringContainsString("'reportingManagerId'", $body);
        // Bulk import has no CSV column for it — no validateManagerAssignment call needed here.
        $this->assertStringNotContainsString('->validateManagerAssignment(', $body, 'Bulk import must not attempt to validate a manager it never collects.');
    }

    public function test_edit_staff_validates_diffs_and_records_reporting_manager(): void
    {
        $start = strpos($this->staffSrc, 'function edit_staff(');
        $body  = $this->extractFunctionBody($this->staffSrc, $start);
        $this->assertStringContainsString('->validateManagerAssignment(', $body);
        $this->assertStringContainsString("\$ehChanges['reportingManagerId']", $body);
        $this->assertStringContainsString('reporting_manager_id', $body);
    }

    // ── Sequencing: validated/written before the staff-doc write; EH fired only after success ──

    public function test_new_staff_validates_reporting_manager_before_the_staff_write(): void
    {
        $start = strpos($this->staffSrc, 'function new_staff(');
        $body  = $this->extractFunctionBody($this->staffSrc, $start);
        $validatePos = strpos($body, '->validateManagerAssignment(');
        $writePos    = strpos($body, "\$result = \$this->fs->set('staff'");
        $this->assertNotFalse($validatePos);
        $this->assertNotFalse($writePos);
        $this->assertLessThan($writePos, $validatePos, 'Reporting Manager must be validated BEFORE the staff doc is created, not after.');
    }

    public function test_edit_staff_validates_reporting_manager_before_update_entity(): void
    {
        $start = strpos($this->staffSrc, 'function edit_staff(');
        $body  = $this->extractFunctionBody($this->staffSrc, $start);
        $validatePos = strpos($body, '->validateManagerAssignment(');
        $updatePos   = strpos($body, "updateEntity('staff'");
        $this->assertNotFalse($validatePos);
        $this->assertNotFalse($updatePos);
        $this->assertLessThan($updatePos, $validatePos, 'Reporting Manager must be validated BEFORE the staff doc update, not after.');
    }

    public function test_edit_staff_records_reporting_manager_change_only_inside_success_branch(): void
    {
        $start = strpos($this->staffSrc, 'function edit_staff(');
        $body  = $this->extractFunctionBody($this->staffSrc, $start);
        $ifResPos = strpos($body, 'if ($updateRes)');
        $diffPos  = strpos($body, "\$ehChanges['reportingManagerId']");
        $this->assertNotFalse($ifResPos);
        $this->assertNotFalse($diffPos);
        $this->assertGreaterThan($ifResPos, $diffPos, 'reportingManagerId diffing must be nested inside the if ($updateRes) success branch.');
    }

    // ── Rejection on invalid input is a hard stop, not a soft warning ───

    public function test_new_staff_rejects_invalid_reporting_manager_with_json_error(): void
    {
        $start = strpos($this->staffSrc, 'function new_staff(');
        $body  = $this->extractFunctionBody($this->staffSrc, $start);
        $validatePos = strpos($body, '->validateManagerAssignment(');
        $nearby = substr($body, $validatePos, 300);
        $this->assertStringContainsString('json_error', $nearby, 'An invalid Reporting Manager must reject the request (json_error), not silently proceed.');
    }

    public function test_edit_staff_rejects_invalid_reporting_manager_with_json_error(): void
    {
        $start = strpos($this->staffSrc, 'function edit_staff(');
        $body  = $this->extractFunctionBody($this->staffSrc, $start);
        $validatePos = strpos($body, '->validateManagerAssignment(');
        $nearby = substr($body, $validatePos, 300);
        $this->assertStringContainsString('json_error', $nearby);
    }

    // ── ATS untouched ─────────────────────────────────────────────────────

    public function test_ats_finalize_hire_still_never_writes_staff_or_touches_reporting_manager(): void
    {
        $start = strpos($this->atsSrc, 'function finalize_hire(');
        $this->assertNotFalse($start);
        $body = $this->extractFunctionBody($this->atsSrc, $start);
        $this->assertStringNotContainsString("fs->set('staff'", $body);
        $this->assertStringNotContainsString('reportingManagerId', $body, 'ATS must not become a second Reporting Manager write path.');
        $this->assertStringNotContainsString('validateManagerAssignment', $body);
    }

    // ── RBAC gates unchanged — no new/second mechanism ──────────────────

    public function test_new_staff_still_gated_sis_manage_only(): void
    {
        $this->assertStringContainsString(
            '$this->_require_role(self::MANAGE_ROLES, "", "SIS", "manage");',
            $this->staffSrc
        );
    }

    public function test_edit_staff_still_gated_sis_edit_only(): void
    {
        $start = strpos($this->staffSrc, 'function edit_staff(');
        $body  = $this->extractFunctionBody($this->staffSrc, $start);
        $this->assertStringContainsString('$this->_require_role(self::MANAGE_ROLES, "", "SIS", "edit");', $body);
    }

    public function test_no_new_permission_or_legacy_rbac_introduced(): void
    {
        $this->assertStringNotContainsString('reportingManagerId_permission', $this->staffSrc);
        $this->assertStringNotContainsString("token.role in [", $this->serviceSrc);
        $this->assertStringNotContainsString('token.role', $this->serviceSrc);
    }

    // ── Designation / Position / Department stay independent ────────────

    public function test_reporting_manager_never_derives_from_designation_or_position(): void
    {
        $start = strpos($this->staffSrc, 'function _commit_staff_row(');
        $body1 = $this->extractFunctionBody($this->staffSrc, $start);
        $start2 = strpos($this->staffSrc, 'function edit_staff(');
        $body2 = $this->extractFunctionBody($this->staffSrc, $start2);
        foreach ([$body1, $body2] as $body) {
            $this->assertStringNotContainsString("reportingManagerId'] = \$formattedData['designation", $body);
            $this->assertStringNotContainsString("reportingManagerId'] = \$formattedData['Position", $body);
        }
    }

    public function test_service_attribute_vocabulary_unchanged_otherwise(): void
    {
        $this->assertStringContainsString(
            "['position', 'department', 'employmentType', 'reportingManagerId']",
            $this->serviceSrc
        );
    }

    public function test_reporting_manager_event_type_is_not_id_suffixed(): void
    {
        $this->assertStringContainsString("'reportingManagerId' => 'REPORTING_MANAGER'", $this->serviceSrc);
        $this->assertStringNotContainsString('REPORTING_MANAGER_ID_CHANGED', $this->serviceSrc);
    }

    // ── Firestore rules: append-only/no-delete boundaries untouched, defense-in-depth added ──

    public function test_rules_staff_event_and_timeline_boundaries_still_intact(): void
    {
        $eventStart = strpos($this->rulesSrc, 'match /staffEmploymentEvents/{docId}');
        $eventClose = strpos($this->rulesSrc, "\n    }\n", $eventStart);
        $eventBlock = substr($this->rulesSrc, $eventStart, $eventClose - $eventStart);
        $this->assertStringNotContainsString('allow update', $eventBlock);
        $this->assertStringNotContainsString('allow delete', $eventBlock);

        $timelineStart = strpos($this->rulesSrc, 'match /staffEmploymentTimeline/{docId}');
        $timelineClose = strpos($this->rulesSrc, "\n    }\n", $timelineStart);
        $timelineBlock = substr($this->rulesSrc, $timelineStart, $timelineClose - $timelineStart);
        $this->assertStringNotContainsString('allow delete', $timelineBlock);
    }

    public function test_rules_reporting_manager_defense_in_depth_present_and_no_legacy_rbac(): void
    {
        $this->assertStringContainsString('function reportingManagerOk()', $this->rulesSrc);
        $staffStart = strpos($this->rulesSrc, 'match /staff/{docId}');
        $staffClose = strpos($this->rulesSrc, "\n    }\n", $staffStart);
        $staffBlock = substr($this->rulesSrc, $staffStart, $staffClose - $staffStart);
        $this->assertStringContainsString('reportingManagerOk()', $staffBlock);
        $this->assertStringNotContainsString("token.role in [", $staffBlock);
    }

    public function test_rules_do_not_attempt_recursive_cycle_detection(): void
    {
        // Defense-in-depth is self-reference + existence ONLY — no loop/recursion
        // construct exists in .rules (they can't do it); full cycle detection
        // stays PHP-only per the Phase 2B design.
        $fnStart = strpos($this->rulesSrc, 'function reportingManagerOk()');
        $fnEnd = strpos($this->rulesSrc, '}', $fnStart);
        $fnBody = substr($this->rulesSrc, $fnStart, $fnEnd - $fnStart);
        $this->assertLessThanOrEqual(1, substr_count($fnBody, 'exists('), 'Defense-in-depth must be at most one bounded exists() call, not a traversal.');
    }
}
