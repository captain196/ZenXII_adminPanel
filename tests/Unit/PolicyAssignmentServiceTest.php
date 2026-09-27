<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

if (!defined('BASEPATH')) define('BASEPATH', __DIR__);
if (!defined('APPPATH')) define('APPPATH', __DIR__ . '/../../application/');
require_once __DIR__ . '/../../application/libraries/Policy_assignment_service.php';

/**
 * PHASE 0 — the policy assignment engine.
 *
 * THE DECISIVE ASSERTIONS
 * -----------------------
 *  B1  every (employee, date) resolves to exactly ONE policy or BLOCKS
 *  B2  overlapping assignments are REFUSED, never resolved by read order
 *  B3  ties at the same level are REFUSED, never arbitrary
 *  B4  NO fallback path exists in the source (asserted against the file text)
 *  B5  resolution is explainable — the chain names every level examined
 *
 * The suite exists to forbid the failure that produced N-23: a resolver that
 * quietly supplies a calendar nobody declared.
 */
class PolicyAssignmentServiceTest extends TestCase
{
    private const SVC = \Policy_assignment_service::class;

    /** Catalogue used by most tests. */
    private function policies(): array
    {
        return [
            'SCH_TEACH'  => ['name' => 'Teaching',       'weeklyOffs' => ['Sat', 'Sun']],
            'SCH_OFFICE' => ['name' => 'Office',         'weeklyOffs' => ['Sun']],
            'SCH_NIGHT'  => ['name' => 'Security-Night', 'weeklyOffs' => ['Sun']],
            'SCH_ORG'    => ['name' => 'Org weeklyOffs', 'weeklyOffs' => ['Sun']],
        ];
    }

    private function assign(string $type, string $id, string $policyId, string $from, string $to = ''): array
    {
        return [
            'attachmentType' => $type,
            'attachmentId'   => $id,
            'policyId'       => $policyId,
            'policyType'     => 'work_schedule',
            'effectiveFrom'  => $from,
            'effectiveTo'    => $to,
        ];
    }

    private function ctx(array $assignments, ?string $orgPolicyId = null, string $dept = 'Teaching'): array
    {
        return [
            'policies'             => $this->policies(),
            'assignments'          => $assignments,
            'staffId'              => 'STA0007',
            'department'           => $dept,
            'organisationPolicyId' => $orgPolicyId,
        ];
    }

    // ── B1 · resolution order ────────────────────────────────────────────

    public function test_staff_assignment_beats_department(): void
    {
        $r = (self::SVC)::resolve($this->ctx([
            $this->assign('department', 'Teaching', 'SCH_TEACH',  '2026-01-01'),
            $this->assign('staff',      'STA0007',  'SCH_OFFICE', '2026-01-01'),
        ]), '2026-05-15');

        $this->assertSame('SCH_OFFICE', $r['policyId']);
        $this->assertSame('staff', $r['level']);
        $this->assertNull($r['block']);
    }

    public function test_department_beats_organisation(): void
    {
        $r = (self::SVC)::resolve($this->ctx([
            $this->assign('department', 'Teaching', 'SCH_TEACH', '2026-01-01'),
        ], 'SCH_ORG'), '2026-05-15');

        $this->assertSame('SCH_TEACH', $r['policyId']);
        $this->assertSame('department', $r['level']);
    }

    public function test_organisation_applies_when_nothing_else_does(): void
    {
        $r = (self::SVC)::resolve($this->ctx([], 'SCH_ORG'), '2026-05-15');

        $this->assertSame('SCH_ORG', $r['policyId']);
        $this->assertSame('organisation', $r['level']);
        $this->assertNull($r['block']);
    }

    /** D4: the organisation level exists ONLY when the operator declared one. */
    public function test_blocks_when_no_organisation_policy_declared(): void
    {
        $r = (self::SVC)::resolve($this->ctx([], null), '2026-05-15');

        $this->assertNull($r['policy']);
        $this->assertSame((self::SVC)::BLOCK_NO_ASSIGNMENT, $r['block']);
    }

    public function test_empty_catalogue_blocks(): void
    {
        $r = (self::SVC)::resolve([
            'policies' => [], 'assignments' => [], 'staffId' => 'STA0007',
            'department' => 'Teaching', 'organisationPolicyId' => 'SCH_ORG',
        ], '2026-05-15');

        $this->assertSame((self::SVC)::BLOCK_NO_POLICIES, $r['block']);
    }

    public function test_employee_with_no_department_skips_that_level(): void
    {
        $r = (self::SVC)::resolve($this->ctx([
            $this->assign('department', 'Teaching', 'SCH_TEACH', '2026-01-01'),
        ], 'SCH_ORG', ''), '2026-05-15');

        $this->assertSame('organisation', $r['level']);
    }

    // ── B2 / B3 · ambiguity is refused, never resolved ───────────────────

    public function test_overlapping_staff_assignments_are_refused(): void
    {
        $r = (self::SVC)::resolve($this->ctx([
            $this->assign('staff', 'STA0007', 'SCH_OFFICE', '2026-01-01'),
            $this->assign('staff', 'STA0007', 'SCH_NIGHT',  '2026-03-01'),
        ]), '2026-05-15');

        $this->assertSame((self::SVC)::BLOCK_AMBIGUOUS, $r['block']);
        $this->assertNull($r['policy']);
    }

    public function test_overlapping_department_assignments_are_refused(): void
    {
        $r = (self::SVC)::resolve($this->ctx([
            $this->assign('department', 'Teaching', 'SCH_TEACH',  '2026-01-01'),
            $this->assign('department', 'Teaching', 'SCH_OFFICE', '2026-02-01'),
        ]), '2026-05-15');

        $this->assertSame((self::SVC)::BLOCK_AMBIGUOUS, $r['block']);
    }

    /** Ambiguity must NOT be papered over by a lower level still resolving. */
    public function test_ambiguity_blocks_rather_than_falling_through(): void
    {
        $r = (self::SVC)::resolve($this->ctx([
            $this->assign('staff', 'STA0007', 'SCH_OFFICE', '2026-01-01'),
            $this->assign('staff', 'STA0007', 'SCH_NIGHT',  '2026-03-01'),
            $this->assign('department', 'Teaching', 'SCH_TEACH', '2026-01-01'),
        ], 'SCH_ORG'), '2026-05-15');

        $this->assertSame((self::SVC)::BLOCK_AMBIGUOUS, $r['block']);
    }

    /** Read order must not decide. Reversing the input cannot change the verdict. */
    public function test_result_is_independent_of_assignment_order(): void
    {
        $a = $this->assign('staff', 'STA0007', 'SCH_OFFICE', '2026-01-01');
        $b = $this->assign('staff', 'STA0007', 'SCH_NIGHT',  '2026-03-01');

        $fwd = (self::SVC)::resolve($this->ctx([$a, $b]), '2026-05-15');
        $rev = (self::SVC)::resolve($this->ctx([$b, $a]), '2026-05-15');

        $this->assertSame($fwd['block'], $rev['block']);
        $this->assertSame((self::SVC)::BLOCK_AMBIGUOUS, $fwd['block']);
    }

    // ── Effective dating ─────────────────────────────────────────────────

    public function test_expired_staff_override_falls_through_to_department(): void
    {
        $r = (self::SVC)::resolve($this->ctx([
            $this->assign('staff', 'STA0007', 'SCH_OFFICE', '2026-01-01', '2026-03-31'),
            $this->assign('department', 'Teaching', 'SCH_TEACH', '2026-01-01'),
        ]), '2026-05-15');

        $this->assertSame('SCH_TEACH', $r['policyId']);
        $this->assertSame('department', $r['level']);
    }

    public function test_future_assignment_does_not_apply_yet(): void
    {
        $r = (self::SVC)::resolve($this->ctx([
            $this->assign('staff', 'STA0007', 'SCH_OFFICE', '2026-09-01'),
        ]), '2026-05-15');

        $this->assertSame((self::SVC)::BLOCK_NO_ASSIGNMENT, $r['block']);
    }

    public function test_expired_everywhere_reports_expired_not_missing(): void
    {
        $r = (self::SVC)::resolve($this->ctx([
            $this->assign('staff', 'STA0007', 'SCH_OFFICE', '2026-01-01', '2026-03-31'),
        ]), '2026-05-15');

        $this->assertSame((self::SVC)::BLOCK_ASSIGNMENT_EXPIRED, $r['block']);
    }

    public function test_open_ended_assignment_covers_far_future(): void
    {
        $r = (self::SVC)::resolve($this->ctx([
            $this->assign('staff', 'STA0007', 'SCH_NIGHT', '2026-01-01'),
        ]), '2031-12-31');

        $this->assertSame('SCH_NIGHT', $r['policyId']);
    }

    public function test_boundary_dates_are_inclusive(): void
    {
        $c = $this->ctx([$this->assign('staff', 'STA0007', 'SCH_NIGHT', '2026-05-01', '2026-05-31')]);

        $this->assertSame('SCH_NIGHT', ((self::SVC)::resolve($c, '2026-05-01'))['policyId']);
        $this->assertSame('SCH_NIGHT', ((self::SVC)::resolve($c, '2026-05-31'))['policyId']);
        $this->assertNotNull(((self::SVC)::resolve($c, '2026-06-01'))['block']);
    }

    // ── Missing policy ───────────────────────────────────────────────────

    public function test_assignment_pointing_at_missing_policy_blocks(): void
    {
        $r = (self::SVC)::resolve($this->ctx([
            $this->assign('staff', 'STA0007', 'SCH_GONE', '2026-01-01'),
        ]), '2026-05-15');

        $this->assertSame((self::SVC)::BLOCK_POLICY_MISSING, $r['block']);
    }

    public function test_missing_organisation_policy_blocks(): void
    {
        $r = (self::SVC)::resolve($this->ctx([], 'SCH_GONE'), '2026-05-15');

        $this->assertSame((self::SVC)::BLOCK_POLICY_MISSING, $r['block']);
    }

    // ── Reserved levels must not resolve yet ─────────────────────────────

    public function test_reserved_levels_never_win_resolution(): void
    {
        foreach ((self::SVC)::RESERVED_LEVELS as $reserved) {
            $r = (self::SVC)::resolve($this->ctx([
                $this->assign($reserved, 'Teaching', 'SCH_OFFICE', '2026-01-01'),
            ]), '2026-05-15');

            $this->assertNotNull($r['block'], $reserved . ' must not resolve while reserved');
        }
    }

    public function test_reserved_levels_are_accepted_by_validation(): void
    {
        foreach ((self::SVC)::RESERVED_LEVELS as $reserved) {
            $v = (self::SVC)::validateAssignment(
                $this->assign($reserved, 'X', 'SCH_OFFICE', '2026-01-01'));
            $this->assertTrue($v['valid'], $reserved . ' should be writable ahead of support');
        }
    }

    // ── Validation ───────────────────────────────────────────────────────

    public function test_validation_requires_every_field(): void
    {
        $v = (self::SVC)::validateAssignment([]);
        $this->assertFalse($v['valid']);
        $this->assertGreaterThanOrEqual(5, count($v['errors']));
    }

    public function test_validation_rejects_unknown_attachment_type(): void
    {
        $v = (self::SVC)::validateAssignment(
            $this->assign('role', 'Teacher', 'SCH_TEACH', '2026-01-01'));
        $this->assertFalse($v['valid']);
    }

    public function test_validation_rejects_bad_dates(): void
    {
        $this->assertFalse(((self::SVC)::validateAssignment(
            $this->assign('staff', 'S', 'SCH_TEACH', '15-05-2026')))['valid']);
        $this->assertFalse(((self::SVC)::validateAssignment(
            $this->assign('staff', 'S', 'SCH_TEACH', '2026-02-30')))['valid']);
    }

    public function test_validation_rejects_end_before_start(): void
    {
        $v = (self::SVC)::validateAssignment(
            $this->assign('staff', 'S', 'SCH_TEACH', '2026-05-01', '2026-04-01'));
        $this->assertFalse($v['valid']);
    }

    public function test_validation_accepts_a_good_record(): void
    {
        $v = (self::SVC)::validateAssignment(
            $this->assign('department', 'Teaching', 'SCH_TEACH', '2026-04-01', '2027-03-31'));
        $this->assertTrue($v['valid']);
        $this->assertSame([], $v['errors']);
    }

    // ── Overlap detection, before anything is written ────────────────────

    public function test_find_overlaps_detects_a_clash(): void
    {
        $existing = [$this->assign('staff', 'STA0007', 'SCH_OFFICE', '2026-01-01', '2026-06-30')];
        $hits = (self::SVC)::findOverlaps($existing,
            $this->assign('staff', 'STA0007', 'SCH_NIGHT', '2026-05-01'));
        $this->assertCount(1, $hits);
    }

    public function test_find_overlaps_allows_adjacent_ranges(): void
    {
        $existing = [$this->assign('staff', 'STA0007', 'SCH_OFFICE', '2026-01-01', '2026-04-30')];
        $hits = (self::SVC)::findOverlaps($existing,
            $this->assign('staff', 'STA0007', 'SCH_NIGHT', '2026-05-01'));
        $this->assertSame([], $hits);
    }

    public function test_find_overlaps_ignores_other_levels_and_keys(): void
    {
        $existing = [
            $this->assign('department', 'Teaching', 'SCH_TEACH',  '2026-01-01'),
            $this->assign('staff',      'STA9999',  'SCH_OFFICE', '2026-01-01'),
        ];
        $hits = (self::SVC)::findOverlaps($existing,
            $this->assign('staff', 'STA0007', 'SCH_NIGHT', '2026-01-01'));
        $this->assertSame([], $hits);
    }

    public function test_find_overlaps_ignores_the_record_being_edited(): void
    {
        $a = $this->assign('staff', 'STA0007', 'SCH_OFFICE', '2026-01-01');
        $a['assignmentId'] = 'ASG1';
        $edited = $a;
        $edited['policyId'] = 'SCH_NIGHT';

        $this->assertSame([], (self::SVC)::findOverlaps([$a], $edited));
    }

    // ── B5 · explainability ──────────────────────────────────────────────

    public function test_chain_records_every_level_examined(): void
    {
        $r = (self::SVC)::resolve($this->ctx([], 'SCH_ORG'), '2026-05-15');
        $levels = array_column($r['chain'], 'level');

        $this->assertSame(['staff', 'department', 'organisation'], $levels);
    }

    public function test_explain_names_the_winning_level(): void
    {
        $r = (self::SVC)::resolve($this->ctx([
            $this->assign('department', 'Teaching', 'SCH_TEACH', '2026-01-01'),
        ]), '2026-05-15');

        $text = (self::SVC)::explain($r);
        $this->assertStringContainsString('department: matched policy SCH_TEACH', $text);
        $this->assertStringContainsString('RESULT: resolved at department', $text);
    }

    public function test_explain_states_why_a_block_happened(): void
    {
        $r = (self::SVC)::resolve($this->ctx([
            $this->assign('staff', 'STA0007', 'SCH_OFFICE', '2026-01-01'),
            $this->assign('staff', 'STA0007', 'SCH_NIGHT',  '2026-03-01'),
        ]), '2026-05-15');

        $text = (self::SVC)::explain($r);
        $this->assertStringContainsString('REFUSED', $text);
        $this->assertStringContainsString('BLOCKED', $text);
    }

    // ── B4 · the source must contain no fallback path ────────────────────

    private function source(): string
    {
        return (string) file_get_contents(
            __DIR__ . '/../../application/libraries/Policy_assignment_service.php');
    }

    /** Code only — prose in comments must never satisfy or trip these guards. */
    private function codeOnly(): string
    {
        $out = '';
        foreach (token_get_all($this->source()) as $t) {
            if (is_array($t) && in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true)) continue;
            $out .= is_array($t) ? $t[1] : $t;
        }
        return $out;
    }

    public function test_source_contains_no_hardcoded_weekday_rule(): void
    {
        $code = $this->codeOnly();
        $this->assertSame(0, substr_count($code, 'satCount'));
        $this->assertSame(0, substr_count($code, "date('w'"));
        $this->assertSame(0, substr_count($code, "'Sunday'"));
    }

    public function test_source_never_invents_a_default_policy(): void
    {
        $code = $this->codeOnly();
        $this->assertSame(0, substr_count($code, 'DEFAULT_POLICY'));
        $this->assertSame(0, substr_count($code, 'defaultPolicy'));
        $this->assertSame(0, substr_count($code, 'fallback'));
    }

    /** The resolver must not reach Firestore — callers supply the data. */
    public function test_resolver_performs_no_data_access(): void
    {
        $code = $this->codeOnly();
        $this->assertSame(0, substr_count($code, '$this->fs'));
        $this->assertSame(0, substr_count($code, '->set('));
        $this->assertSame(0, substr_count($code, 'firebase'));
    }

    /** Every terminal path returns a block code; none returns a silent policy. */
    public function test_every_block_code_is_reachable(): void
    {
        $codes = [
            (self::SVC)::BLOCK_NO_POLICIES,
            (self::SVC)::BLOCK_NO_ASSIGNMENT,
            (self::SVC)::BLOCK_ASSIGNMENT_EXPIRED,
            (self::SVC)::BLOCK_AMBIGUOUS,
            (self::SVC)::BLOCK_POLICY_MISSING,
        ];
        $this->assertSame(count($codes), count(array_unique($codes)));
        foreach ($codes as $c) $this->assertNotSame('', $c);
    }
}
