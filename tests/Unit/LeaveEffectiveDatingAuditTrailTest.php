<?php
namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Workforce Policy Programme Phase F — Effective Dating sub-phase
 * (2026-08-18). Effective dating itself (should a quota edit apply
 * prospectively or retroactively?) is a Payroll financial-policy decision
 * that was explicitly NOT made — see the stop report. This covers the one
 * safe, non-financial groundwork change: `save_leave_type()`'s audit log
 * previously recorded only the NEW value of an allocation-affecting field,
 * so a reader of the audit trail could see THAT days_per_year changed but
 * not the delta that drove `_propagate_leave_type_to_balances()`'s
 * retroactive rewrite. Adding the previous value is pure observability —
 * it changes no balance math and no propagation behaviour.
 *
 * Source-regression tests (Firestore_service has no behavioral mock for
 * controller code).
 */
class LeaveEffectiveDatingAuditTrailTest extends TestCase
{
    private string $src;

    protected function setUp(): void
    {
        $raw = file_get_contents(__DIR__ . '/../../application/controllers/Hr.php');
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

    private function saveLeaveTypeBody(): string
    {
        $start = strpos($this->src, 'public function save_leave_type(');
        $this->assertNotFalse($start);
        return $this->extractFunctionBody($this->src, $start);
    }

    public function test_audit_entry_captures_previous_values_only_on_edit(): void
    {
        $body = $this->saveLeaveTypeBody();
        $this->assertStringContainsString("if (!\$isNew && is_array(\$prevType)) {", $body);
        $this->assertStringContainsString("\$auditEntry['previous_days_per_year'] = (int) (\$prevType['days_per_year'] ?? 0);", $body);
        $this->assertStringContainsString("\$auditEntry['previous_carry_forward'] = (bool) (\$prevType['carry_forward'] ?? false);", $body);
        $this->assertStringContainsString("\$auditEntry['previous_max_carry']     = (int) (\$prevType['max_carry'] ?? 0);", $body);
        $this->assertStringContainsString("\$auditEntry['previous_status']        = (string) (\$prevType['status'] ?? 'Active');", $body);
    }

    public function test_propagation_audit_entry_records_previous_days_per_year(): void
    {
        $body = $this->saveLeaveTypeBody();
        $this->assertStringContainsString(
            "'previous_days_per_year' => (int) (\$prevType['days_per_year'] ?? 0),",
            $body
        );
    }

    public function test_audit_change_does_not_touch_propagation_logic(): void
    {
        // The retroactive rewrite itself must be byte-identical to before —
        // this phase only adds visibility into it.
        $body = $this->saveLeaveTypeBody();
        $this->assertStringContainsString(
            '$affected = $this->_propagate_leave_type_to_balances($id, $data, $propYear);',
            $body
        );
    }

    public function test_new_leave_type_creation_does_not_emit_previous_value_fields(): void
    {
        // $isNew branch never touches $prevType — creation has no "before".
        $body = $this->saveLeaveTypeBody();
        $auditBuildStart = strpos($body, '$auditEntry = [');
        $ifPos = strpos($body, "if (!\$isNew && is_array(\$prevType)) {", $auditBuildStart);
        $this->assertNotFalse($auditBuildStart);
        $this->assertNotFalse($ifPos);
        $between = substr($body, $auditBuildStart, $ifPos - $auditBuildStart);
        $this->assertStringNotContainsString('previous_', $between,
            'the initial $auditEntry literal must not itself carry previous_* keys — they are conditionally appended only on an edit.');
    }
}
