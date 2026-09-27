<?php
namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Staff HR Advanced Capability Programme — Phase 5 (Read-Only Exit
 * Summary Aggregation), 2026-08-18.
 *
 * PURE AGGREGATION. This endpoint must never calculate leave encashment,
 * notice recovery, statutory deductions, tax, or a final payable amount —
 * it only reads figures that already exist, owned by their own modules
 * (Payroll's salarySlips, Leave's balance doc, this programme's own
 * Notice Period / Clearance records). Pending reimbursements must be
 * reported as explicitly untracked, never as a fabricated zero.
 *
 * Source-regression tests (Firestore_service has no behavioral mock for
 * controller code).
 */
class StaffExitSummaryTest extends TestCase
{
    private string $controllerSrc;

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

    private function summaryFnBody(): string
    {
        $start = strpos($this->controllerSrc, 'public function get_exit_summary(');
        $this->assertNotFalse($start);
        return $this->extractFunctionBody($this->controllerSrc, $start);
    }

    public function test_endpoint_exists_and_is_gated(): void
    {
        $body = $this->summaryFnBody();
        // Same VIEW_ROLES + "view" pairing as its sibling print_exit_document() —
        // both are read-only actions open to the same audience (Phase 6 gap
        // re-audit flagged and fixed a "manage"/VIEW_ROLES mismatch here that
        // would have wrongly denied a graded-role viewer holding only SIS:view).
        $this->assertStringContainsString('_require_role(self::VIEW_ROLES, "", "SIS", "view")', $body);
    }

    public function test_staff_id_validated_before_use(): void
    {
        $body = $this->summaryFnBody();
        $this->assertStringContainsString("safe_path_segment(trim((string) \$this->input->get('staff_id')), 'staff_id')", $body);
    }

    public function test_reads_notice_period_read_only(): void
    {
        $body = $this->summaryFnBody();
        $this->assertStringContainsString("\$this->fs->get(self::NOTICE_PERIOD_COL, \$this->_notice_doc_id(\$staffId))", $body);
    }

    public function test_reads_clearance_read_only(): void
    {
        $body = $this->summaryFnBody();
        $this->assertStringContainsString("\$this->fs->get(self::CLEARANCE_COL, \$this->_clearance_doc_id(\$staffId))", $body);
    }

    public function test_reads_leave_balance_doc_read_only_same_shape_as_hr(): void
    {
        $body = $this->summaryFnBody();
        // Same BAL_{staffId}_{year} shape Hr.php's own balance reader uses —
        // no recomputation of the balance here.
        $this->assertStringContainsString("\$this->fs->get('leaveApplications', \$this->fs->docId(\"BAL_{\$staffId}_{\$year}\"))", $body);
    }

    public function test_reads_payslip_using_the_same_query_shape_as_my_payslips(): void
    {
        $body = $this->summaryFnBody();
        $this->assertStringContainsString("\$this->fs->schoolWhere('salarySlips', [", $body);
        $this->assertStringContainsString("['type', '==', 'payslip']", $body);
        $this->assertStringContainsString("['staffId', '==', \$staffId]", $body);
        $this->assertStringContainsString("in_array((string) (\$run['status'] ?? ''), ['Finalized', 'Paid'], true)", $body);
    }

    public function test_pending_reimbursements_reported_as_untracked_never_a_fabricated_zero(): void
    {
        $body = $this->summaryFnBody();
        $this->assertStringContainsString("'pendingReimbursements'", $body);
        $this->assertStringContainsString("'not_tracked' => true", $body);
    }

    public function test_never_computes_encashment_recovery_deduction_or_tax(): void
    {
        $body = strtolower($this->summaryFnBody());
        foreach (['encashment', 'notice recovery', 'noticerecovery', 'statutory', 'tax', 'final payable', 'finalpayable', 'gratuity'] as $term) {
            $this->assertStringNotContainsString($term, $body, "get_exit_summary() must never compute '{$term}' — pure read-only aggregation only.");
        }
    }

    public function test_writes_nothing_no_createdat_or_setentity_calls(): void
    {
        $body = $this->summaryFnBody();
        // Read-only: no document is created or mutated by this endpoint.
        $this->assertStringNotContainsString('createdAt', $body);
        $this->assertStringNotContainsString('setEntity', $body);
        $this->assertStringNotContainsString('->set(', $body);
    }

    public function test_returns_json_success_with_summary_key(): void
    {
        $body = $this->summaryFnBody();
        $this->assertStringContainsString("json_success(['summary' => \$summary])", $body);
    }
}
