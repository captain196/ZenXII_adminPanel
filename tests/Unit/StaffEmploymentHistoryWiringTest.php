<?php
namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Employment History Phase 1 — wiring/sequencing/scope-boundary checks on
 * the live Staff.php write paths, following this repo's established
 * literal-source-regression convention (see
 * StaffCreationAuditMetadataTest's docblock / LocationSchoolIdPredicateTest).
 *
 * These tests do NOT re-verify Staff_employment_history_service's own
 * logic (see StaffEmploymentHistoryPhase1Test for that) — they verify that
 * Staff.php calls it correctly, in the right order, only from the three
 * live creation/edit paths, and that nothing outside Phase 1's authorized
 * scope (RBAC gates, ATS, Designation, Attendance) was touched.
 */
class StaffEmploymentHistoryWiringTest extends TestCase
{
    private string $staffSrc;
    private string $atsSrc;
    private string $rulesSrc;

    protected function setUp(): void
    {
        $this->staffSrc = $this->readCodeOnly(__DIR__ . '/../../application/controllers/Staff.php');
        $this->atsSrc   = $this->readCodeOnly(__DIR__ . '/../../application/controllers/Ats.php');
        $this->rulesSrc = file_get_contents(__DIR__ . '/../../firebase-rules/firestore.rules');
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

    // ── Presence + correct call site ────────────────────────────────────

    public function test_new_staff_calls_record_joined(): void
    {
        $start = strpos($this->staffSrc, 'function new_staff(');
        $this->assertNotFalse($start);
        $body = $this->extractFunctionBody($this->staffSrc, $start);
        $this->assertStringContainsString('->recordJoined(', $body);
    }

    public function test_commit_staff_row_calls_record_joined(): void
    {
        $start = strpos($this->staffSrc, 'function _commit_staff_row(');
        $this->assertNotFalse($start);
        $body = $this->extractFunctionBody($this->staffSrc, $start);
        $this->assertStringContainsString('->recordJoined(', $body);
    }

    public function test_edit_staff_calls_record_changes(): void
    {
        $start = strpos($this->staffSrc, 'function edit_staff(');
        $this->assertNotFalse($start);
        $body = $this->extractFunctionBody($this->staffSrc, $start);
        $this->assertStringContainsString('->recordChanges(', $body);
        $this->assertStringNotContainsString('->recordJoined(', $body, 'edit_staff() must never fire a JOINED event — that is creation-only.');
    }

    // ── Sequencing: fired AFTER the staff write, never before ───────────

    public function test_new_staff_records_joined_after_the_staff_write_succeeds(): void
    {
        $start = strpos($this->staffSrc, 'function new_staff(');
        $this->assertNotFalse($start);
        $body = $this->extractFunctionBody($this->staffSrc, $start);

        $writePos  = strpos($body, "\$result = \$this->fs->set('staff'");
        $joinedPos = strpos($body, '->recordJoined(');
        $this->assertNotFalse($writePos, "staff doc create call not found inside new_staff()'s body.");
        $this->assertNotFalse($joinedPos);
        $this->assertGreaterThan($writePos, $joinedPos, 'recordJoined() must be called AFTER the staff doc create, never before.');
    }

    public function test_edit_staff_records_changes_after_update_entity_succeeds(): void
    {
        $editStart = strpos($this->staffSrc, 'function edit_staff(');
        $body      = $this->extractFunctionBody($this->staffSrc, $editStart);
        $updatePos = strpos($body, "updateEntity('staff'");
        $ifResPos  = strpos($body, 'if ($updateRes)');
        $recPos    = strpos($body, '->recordChanges(');
        $this->assertNotFalse($updatePos);
        $this->assertNotFalse($ifResPos);
        $this->assertNotFalse($recPos);
        $this->assertGreaterThan($updatePos, $ifResPos, 'The success check must come after the write call.');
        $this->assertGreaterThan($ifResPos, $recPos, 'recordChanges() must be nested inside the if ($updateRes) success branch, never called unconditionally.');
    }

    // ── Non-fatal failure handling — never lets an Employment History error abort the request ──

    public function test_new_staff_employment_history_call_is_exception_guarded(): void
    {
        $start = strpos($this->staffSrc, 'function new_staff(');
        $body  = $this->extractFunctionBody($this->staffSrc, $start);
        $recPos = strpos($body, '->recordJoined(');
        $tryPos = strrpos(substr($body, 0, $recPos), 'try {');
        $this->assertNotFalse($tryPos, 'recordJoined() call in new_staff() must be wrapped in a try/catch so a failure cannot abort staff creation.');
    }

    public function test_edit_staff_employment_history_call_is_exception_guarded(): void
    {
        $start = strpos($this->staffSrc, 'function edit_staff(');
        $body  = $this->extractFunctionBody($this->staffSrc, $start);
        $recPos = strpos($body, '->recordChanges(');
        $tryPos = strrpos(substr($body, 0, $recPos), 'try {');
        $this->assertNotFalse($tryPos, 'recordChanges() call in edit_staff() must be wrapped in a try/catch.');
    }

    // ── Phase 1 attribute scope — exactly position/department/employmentType ──

    public function test_edit_staff_only_diffs_the_three_authorized_attributes(): void
    {
        $start = strpos($this->staffSrc, 'function edit_staff(');
        $body  = $this->extractFunctionBody($this->staffSrc, $start);
        $this->assertStringContainsString("\$ehChanges['position']", $body);
        $this->assertStringContainsString("\$ehChanges['department']", $body);
        $this->assertStringContainsString("\$ehChanges['employmentType']", $body);
        $this->assertStringNotContainsString("\$ehChanges['status']", $body, 'Employment Status is Phase 3 — must not be wired yet.');
        $this->assertStringNotContainsString("\$ehChanges['reportingManager']", $body, 'Reporting Manager is Phase 2 — must not be wired yet.');
        $this->assertStringNotContainsString("\$ehChanges['designation']", $body, 'Designation remains deferred — must never be wired into Employment History.');
        $this->assertStringNotContainsString("\$ehChanges['role']", $body, 'RBAC role is never an Employment-History-owned attribute.');
    }

    // ── RBAC gates unchanged — still the pre-existing SIS gates, no new/second mechanism ──

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

    public function test_no_new_permission_check_function_introduced(): void
    {
        // Employment History must ride the EXISTING gate, never define a
        // parallel authorization mechanism of its own inside Staff.php.
        $this->assertStringNotContainsString('function has_employment_history_permission', $this->staffSrc);
        $this->assertStringNotContainsString('EmploymentHistory_permission', $this->staffSrc);
    }

    // ── ATS untouched — finalize_hire() still never writes staff or fires JOINED itself ──

    public function test_ats_finalize_hire_still_never_writes_staff_or_fires_joined(): void
    {
        $start = strpos($this->atsSrc, 'function finalize_hire(');
        $this->assertNotFalse($start);
        $body = $this->extractFunctionBody($this->atsSrc, $start);
        $this->assertStringNotContainsString("fs->set('staff'", $body);
        $this->assertStringNotContainsString('->recordJoined(', $body, 'finalize_hire() must not create a second JOINED-writing path — Staff Admission is the one canonical path.');
    }

    // ── Rules: append-only event log, gated on the graded RBAC capability, no legacy role array ──

    public function test_rules_event_collection_has_no_update_or_delete(): void
    {
        $matchPos = strpos($this->rulesSrc, 'match /staffEmploymentEvents/{docId}');
        $this->assertNotFalse($matchPos);
        $close = strpos($this->rulesSrc, "\n    }\n", $matchPos);
        $block = substr($this->rulesSrc, $matchPos, $close - $matchPos);
        $this->assertStringNotContainsString('allow update', $block, 'staffEmploymentEvents must have no update rule at all — append-only must be structurally unreachable.');
        $this->assertStringNotContainsString('allow delete', $block, 'staffEmploymentEvents must have no delete rule at all.');
        $this->assertStringContainsString('hasCapabilityLevel(', $block, 'Writes must be gated on the graded RBAC capability model.');
    }

    public function test_rules_timeline_collection_has_no_delete(): void
    {
        $matchPos = strpos($this->rulesSrc, 'match /staffEmploymentTimeline/{docId}');
        $this->assertNotFalse($matchPos);
        $close = strpos($this->rulesSrc, "\n    }\n", $matchPos);
        $block = substr($this->rulesSrc, $matchPos, $close - $matchPos);
        $this->assertStringNotContainsString('allow delete', $block, 'A timeline entry is closed, never deleted.');
        $this->assertStringContainsString('hasCapabilityLevel(', $block);
    }

    public function test_rules_do_not_reference_legacy_role_arrays_or_designation(): void
    {
        $eventStart = strpos($this->rulesSrc, 'match /staffEmploymentEvents/{docId}');
        $timelineStart = strpos($this->rulesSrc, 'match /staffEmploymentTimeline/{docId}');
        $timelineClose = strpos($this->rulesSrc, "\n    }\n", $timelineStart);
        $block = substr($this->rulesSrc, $eventStart, $timelineClose - $eventStart);
        $this->assertStringNotContainsString("token.role in [", $block, 'Must not gate on the legacy role-array idiom — graded RBAC only.');
        $this->assertStringNotContainsString('designation', $block);
        $this->assertStringNotContainsString('employmentType', $block, 'Rules gate on RBAC capability + tenant only — never on the attribute value itself.');
    }
}
