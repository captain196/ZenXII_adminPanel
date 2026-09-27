<?php
namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Trail 2 (Work Schedule → Calendar → Attendance → Leave/LOP → Payroll),
 * Phase I — Staff Transfer, 2026-08-18.
 *
 * "Do not implement Transfer until this dependency is understood" — Trail 2
 * Phase J verified and fixed exactly this dependency (Payroll resolving an
 * employee's department from the effective-dated employment-history
 * timeline, not the live field). This phase adds Staff::transfer_staff(),
 * modeled directly on the already-proven Staff::promote_staff() — same
 * write pattern (immediate live-field update + effective-dated employment-
 * history record), not a new one.
 *
 * Work Schedule reassignment is deliberately OUT of this endpoint —
 * Work_schedules::save_assignment() already safely owns creating/closing a
 * staff-level schedule assignment (its own overlap validation via
 * Policy_assignment_service). Reimplementing that here would be a second
 * writer for the same collection — exactly what this whole Trail has been
 * eliminating.
 *
 * Source-regression tests (Firestore_service has no behavioral mock for
 * controller code).
 */
class StaffTransferTest extends TestCase
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

    private function transferFnBody(): string
    {
        $start = strpos($this->controllerSrc, 'public function transfer_staff(');
        $this->assertNotFalse($start);
        return $this->extractFunctionBody($this->controllerSrc, $start);
    }

    // ── RBAC matches promote_staff() exactly ─────────────────────────────

    public function test_gated_the_same_as_promote_staff(): void
    {
        $body = $this->transferFnBody();
        $this->assertStringContainsString('_require_role(self::MANAGE_ROLES, "", "SIS", "manage")', $body);
    }

    // ── Effective date is REQUIRED (unlike promote_staff, where it's optional) ─

    public function test_effective_date_is_required_unlike_promotion(): void
    {
        $body = $this->transferFnBody();
        $this->assertStringContainsString("if (\$effectiveFrom === '' || !preg_match('/^\\d{4}-\\d{2}-\\d{2}\$/', \$effectiveFrom)) {", $body);
        $this->assertStringContainsString('Effective date is required', $body);
    }

    // ── Same write pattern as promote_staff(): immediate live field + dated history ─

    public function test_writes_the_live_staff_record_immediately(): void
    {
        $body = $this->transferFnBody();
        $this->assertStringContainsString("\$this->fs->updateEntity('staff', \$staffId, \$fieldUpdate);", $body);
    }

    public function test_records_effective_dated_employment_history(): void
    {
        $body = $this->transferFnBody();
        $this->assertStringContainsString('$this->load->library(\'staff_employment_history_service\');', $body);
        $this->assertStringContainsString('$ehSvc->recordChanges($staffId, $ehChanges, $effectiveFrom, $reason);', $body);
    }

    public function test_reuses_the_same_reporting_manager_validation_as_promotion(): void
    {
        $body = $this->transferFnBody();
        $this->assertStringContainsString('$ehSvc->validateManagerAssignment($staffId, $newReportingMgr);', $body);
    }

    public function test_refuses_for_an_inactive_staff_member(): void
    {
        $body = $this->transferFnBody();
        $this->assertStringContainsString("if (strtolower(trim(\$rawStatus)) !== 'active') {", $body);
        $this->assertStringContainsString('Cannot record a transfer for an inactive staff member.', $body);
    }

    public function test_requires_at_least_one_actual_field_change(): void
    {
        $body = $this->transferFnBody();
        $this->assertStringContainsString('if (empty($ehChanges)) {', $body);
    }

    public function test_empty_manager_selection_means_leave_unchanged_not_clear(): void
    {
        // Matches promote_staff()'s own fixed bug from Phase 1 — must not
        // regress: the field is only submitted when a real change is
        // intended (checked via array_key_exists, not truthiness alone,
        // since clearing to '' is itself a valid target value).
        $body = $this->transferFnBody();
        $this->assertStringContainsString("array_key_exists('new_reporting_manager_id', \$_POST)", $body);
        $this->assertStringContainsString('$newReportingMgr !== $oldManagerId', $body);
    }

    // ── Work Schedule reassignment deliberately NOT duplicated here ──────

    public function test_never_writes_to_the_schedule_assignments_collection(): void
    {
        $body = $this->transferFnBody();
        $this->assertStringNotContainsString('staffScheduleAssignments', $body);
        $this->assertStringNotContainsString('Policy_assignment_service', $body);
    }

    public function test_points_the_admin_at_the_existing_work_schedules_screen(): void
    {
        $body = $this->transferFnBody();
        $this->assertStringContainsString('Attendance → Work Schedules → Assignments', $body);
    }

    // ── Audit trail ────────────────────────────────────────────────────

    public function test_logs_an_audit_entry(): void
    {
        $body = $this->transferFnBody();
        $this->assertStringContainsString("log_audit('Staff', 'transfer', \$staffId, \$reason);", $body);
    }

    public function test_history_write_failure_is_surfaced_as_a_warning_not_swallowed(): void
    {
        $body = $this->transferFnBody();
        $this->assertStringContainsString("\$historyWarning = 'Transfer recorded, but employment history could not be updated", $body);
        $this->assertStringContainsString("if (\$historyWarning !== null) \$resp['warning'] = \$historyWarning;", $body);
    }
}
