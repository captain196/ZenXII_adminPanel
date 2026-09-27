<?php
namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Consolidated Staff HR reconciliation audit (2026-08-19) — DEFECT FIX.
 *
 * `leaveApplications` holds requests in two shapes: the admin panel's
 * snake_case (`type: 'request'`) and the Teacher app's camelCase
 * (`applicantType: 'staff'`). Both readers in Hr.php normalise camelCase →
 * snake_case so downstream code sees one shape.
 *
 * `_fsGetAllLeaveRequests()` normalises 10 fields. `_fsGetLeaveRequest()`
 * normalised only 9 — it was missing `type_code`.
 *
 * That single omission had a real financial consequence. `cancel_leave()`
 * decides between two MUTUALLY EXCLUSIVE restore paths with an if/elseif:
 *
 *     $isCompOffCancel = strtoupper($request['type_code'] ?? '') === 'COMP_OFF'
 *       → release the consumed Comp-Off ledger credits
 *     elseif (approved && ...)
 *       → credit the days back onto the standard BAL_ balance doc
 *
 * A Comp-Off request whose stored doc carried only `typeCode` — every
 * Teacher-app-filed request, plus any request where decide_leave() failed
 * to resolve the type and persisted '?' — read as an empty `type_code`,
 * took the ELSE branch, and therefore:
 *   1. credited a standard balance doc that was never debited (Comp-Off is
 *      deliberately funded from the ledger, its days_per_year is 0), and
 *   2. left the consumed ledger credits stranded as 'consumed' forever,
 *      since only the comp-off branch releases them.
 *
 * Both halves are silent — the user is told "Balance restored".
 *
 * The fix copies the sibling reader's existing normalisation line verbatim.
 * No new behaviour was invented; the canonical mapping already existed six
 * lines away in the same file.
 *
 * Source-regression tests (Firestore_service has no behavioral mock).
 */
class LeaveRequestTypeCodeNormalizationTest extends TestCase
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

    private function extractFunctionBody(string $src, int $offset): string
    {
        $openBrace = strpos($src, '{', $offset);
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
        $this->fail('Unbalanced braces.');
    }

    private function fn(string $name): string
    {
        $start = strpos($this->src, 'function ' . $name . '(');
        $this->assertNotFalse($start, "{$name}() must exist.");
        return $this->extractFunctionBody($this->src, $start);
    }

    public function test_single_request_reader_now_normalizes_type_code(): void
    {
        $body = $this->fn('_fsGetLeaveRequest');
        $this->assertStringContainsString(
            "\$request['type_code']  = \$request['typeCode']      ?? \$request['leaveType']     ?? '';",
            $body,
            'the single-request reader must normalise type_code, or cancel_leave cannot detect a Comp-Off cancellation.');
    }

    public function test_both_readers_normalize_the_same_field_set(): void
    {
        // The two readers must not drift again. Every snake_case key one
        // normalises, the other must too.
        $single = $this->fn('_fsGetLeaveRequest');
        $all    = $this->fn('_fsGetAllLeaveRequests');

        foreach (['staff_id', 'staff_name', 'type_id', 'from_date', 'to_date',
                  'type_code', 'paid_days', 'lwp_days', 'days', 'applied_on'] as $field) {
            $this->assertStringContainsString("['{$field}']", $single,
                "_fsGetLeaveRequest must normalise {$field}");
            $this->assertStringContainsString("['{$field}']", $all,
                "_fsGetAllLeaveRequests must normalise {$field}");
        }
    }

    public function test_normalization_is_conditional_never_overwrites_an_existing_value(): void
    {
        // isset() guard — an admin-panel doc that already carries snake_case
        // must keep its own value.
        $body = $this->fn('_fsGetLeaveRequest');
        $this->assertStringContainsString("if (!isset(\$request['type_code']))", $body);
    }

    public function test_cancel_leave_still_branches_on_type_code(): void
    {
        // The consumer this fix exists for. If this branch is ever
        // restructured, revisit the normalisation.
        $body = $this->fn('cancel_leave');
        $this->assertStringContainsString("\$request['type_code']", $body);
        $this->assertStringContainsString('self::COMP_OFF_TYPE_CODE', $body);
    }

    public function test_comp_off_and_standard_restore_remain_mutually_exclusive(): void
    {
        // Documents the shape that made the omission financially material:
        // exactly one of the two restore paths runs.
        $body = $this->fn('cancel_leave');
        $this->assertStringContainsString('$isCompOffCancel', $body);
        $this->assertStringContainsString('} elseif ($currentStatus === \'approved\'', $body);
    }

    public function test_decide_leave_display_string_still_prefers_leavetype(): void
    {
        // Why this fix is non-disruptive at decide_leave: it reads
        // `leaveType` FIRST, so normalising type_code cannot change the
        // string it renders.
        $body = $this->fn('decide_leave');
        $this->assertStringContainsString(
            "\$ltName = \$request['leaveType'] ?? \$request['type_code'] ?? '?';", $body);
    }

    public function test_no_leave_financial_policy_was_altered(): void
    {
        // The fix touches field naming only. Comp-Off funding rules,
        // balance arithmetic and the LWP split are untouched.
        $this->assertStringContainsString("COMP_OFF_TYPE_CODE", $this->src);
        $this->assertStringContainsString('_comp_off_available_balance', $this->src);
        $this->assertStringContainsString('_releaseCompOffLedger', $this->src);
    }
}
