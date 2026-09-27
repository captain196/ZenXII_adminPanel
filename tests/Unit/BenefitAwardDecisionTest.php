<?php
/**
 * Benefit_award_service — the decision that turns eligibility into money.
 *
 * Runtime proof exists (SCH_FAREAUDIT1): disabled produced no entitlement,
 * approval produced exactly one pending row that stayed one across two
 * re-evaluations, 25%→10% discontinued the old and created the new without ever
 * leaving both live, an automatic staff-ward benefit granted at 50%, and
 * setting the employee Inactive withdrew it.
 *
 * These tests pin the DECISION itself with fakes, because the runtime pass
 * cannot show what was NOT done — that a disabled policy never even reads a
 * family's relationships, and that a hand-entered concession is never withdrawn
 * by a policy change.
 */

use PHPUnit\Framework\TestCase;

if (!defined('BASEPATH')) define('BASEPATH', __DIR__);
if (!function_exists('log_message')) { function log_message($l, $m) {} }

require_once __DIR__ . '/../../application/libraries/Benefit_policy_service.php';
require_once __DIR__ . '/../../application/libraries/Benefit_award_service.php';

final class BenefitAwardDecisionTest extends TestCase
{
    private function policy(array $pol)
    {
        return new class($pol) {
            public function __construct(private array $pol) {}
            public function resolve(string $b): array
            {
                return array_merge(Benefit_policy_service::disabledPolicy($b), $this->pol);
            }
            public function isInForce(array $p, string $on = ''): bool
            {
                if (($p['mode'] ?? 'disabled') === 'disabled') return false;
                $from = (string) ($p['effectiveFrom'] ?? '');
                $to   = (string) ($p['effectiveTo'] ?? '');
                $on   = $on !== '' ? $on : date('Y-m-d');
                if ($from !== '' && strcmp($on, $from) < 0) return false;
                if ($to   !== '' && strcmp($on, $to)   > 0) return false;
                return true;
            }
        };
    }

    /** Records every propose/discontinue so we can assert what was NOT done. */
    private function ent(array $existing = [])
    {
        return new class($existing) {
            public array $proposed = [];
            public array $discontinued = [];
            public function __construct(private array $existing) {}
            public function propose(array $in): array
            {
                $this->proposed[] = $in;
                return ['ok' => true, 'id' => 'ENT_' . count($this->proposed),
                        'status' => !empty($in['approvalRequired']) ? 'pending_approval' : 'active'];
            }
            public function discontinue(string $id, string $reason, string $to = ''): array
            {
                $this->discontinued[] = ['id' => $id, 'reason' => $reason];
                return ['ok' => true];
            }
            public function forStudent(string $s, int $l = 100): array { return $this->existing; }
        };
    }

    /** Counts reads, so "a disabled policy must not even look" is testable. */
    private function rel(array $rows)
    {
        return new class($rows) {
            public int $reads = 0;
            public function __construct(private array $rows) {}
            public function forStudent(string $s, string $kind = ''): array
            {
                $this->reads++;
                return $this->rows;
            }
        };
    }

    private function fs(array $docs = [])
    {
        return new class($docs) {
            public function __construct(private array $docs) {}
            public function get(string $c, string $id) { return $this->docs[$id] ?? null; }
            public function set(string $c, string $id, array $d, bool $m = false): bool { return true; }
            public function schoolWhere(string $c, array $x = [], $o = null, string $dir = 'ASC', $lim = null): array { return []; }
        };
    }

    private function svc($pol, $ent, $rel, $fs): Benefit_award_service
    {
        return (new Benefit_award_service())->init($pol, $ent, $rel, $fs, 'SCH_T1', '2026-27', 'SSA9001');
    }

    private function siblingRows(): array
    {
        return [['studentId' => 'STU1', 'kind' => 'sibling', 'relatedId' => 'STU2', 'status' => 'active']];
    }

    // ══ DISABLED ════════════════════════════════════════════════════════════

    /**
     * A disabled benefit must not even READ the family's relationships. Not a
     * privacy nicety: reading first and deciding later is how a "just compute
     * it and see" shortcut turns into an accidental grant.
     */
    public function test_a_disabled_policy_grants_nothing_and_reads_nothing(): void
    {
        $ent = $this->ent();
        $rel = $this->rel($this->siblingRows());

        $r = $this->svc($this->policy(['mode' => 'disabled']), $ent, $rel, $this->fs())
                  ->evaluate('STU1', Benefit_policy_service::BENEFIT_SIBLING);

        $this->assertSame('policy_disabled', $r['outcome']);
        $this->assertSame(0.0, $r['value']);
        $this->assertSame([], $ent->proposed, 'no entitlement may be created');
        $this->assertSame(0, $rel->reads,
            'a disabled benefit must not consult relationships at all');
    }

    /** Out-of-window policies behave exactly like disabled ones. */
    public function test_a_policy_outside_its_window_grants_nothing(): void
    {
        foreach ([['effectiveFrom' => '2999-01-01'], ['effectiveTo' => '2000-01-01']] as $window) {
            $ent = $this->ent();
            $r = $this->svc($this->policy(array_merge(
                    ['mode' => 'automatic', 'valueType' => 'percent', 'value' => 25, 'scope' => 'all'],
                    $window)), $ent, $this->rel($this->siblingRows()), $this->fs())
                 ->evaluate('STU1', Benefit_policy_service::BENEFIT_SIBLING);

            $this->assertSame('policy_not_in_force', $r['outcome']);
            $this->assertSame([], $ent->proposed);
        }
    }

    // ══ APPROVAL vs AUTOMATIC ═══════════════════════════════════════════════

    /**
     * The MODE decides whether a human must authorise, and it comes from the
     * policy — never from the caller. A caller-supplied flag would let any
     * endpoint mint a pre-approved discount.
     */
    public function test_approval_mode_proposes_a_benefit_that_needs_authorising(): void
    {
        $ent = $this->ent();
        $r = $this->svc($this->policy(['mode' => 'approval', 'valueType' => 'percent',
              'value' => 25, 'scope' => 'category:tuition']),
              $ent, $this->rel($this->siblingRows()), $this->fs())
             ->evaluate('STU1', Benefit_policy_service::BENEFIT_SIBLING);

        $this->assertSame('pending_approval', $r['outcome']);
        $this->assertCount(1, $ent->proposed);
        $this->assertTrue($ent->proposed[0]['approvalRequired'],
            'approval mode must not be able to produce an already-active benefit');
        $this->assertSame('concession', $ent->proposed[0]['kind']);
        $this->assertSame('sibling', $ent->proposed[0]['concessionType']);
        $this->assertSame(25.0, $ent->proposed[0]['value']);
        $this->assertSame('category:tuition', $ent->proposed[0]['scope']);
        $this->assertSame(Benefit_award_service::SOURCE, $ent->proposed[0]['source'],
            'stamped so it can be found and withdrawn later');
    }

    public function test_automatic_mode_grants_without_approval(): void
    {
        $ent = $this->ent();
        $r = $this->svc($this->policy(['mode' => 'automatic', 'valueType' => 'fixed',
              'value' => 500, 'scope' => 'all']),
              $ent, $this->rel($this->siblingRows()), $this->fs())
             ->evaluate('STU1', Benefit_policy_service::BENEFIT_SIBLING);

        $this->assertSame('granted', $r['outcome']);
        $this->assertFalse($ent->proposed[0]['approvalRequired']);
        $this->assertSame(500.0, $ent->proposed[0]['value']);
    }

    /** Every benefit carries a reason naming the policy version behind it. */
    public function test_the_benefit_records_why_it_exists(): void
    {
        $ent = $this->ent();
        $this->svc($this->policy(['mode' => 'automatic', 'valueType' => 'percent',
              'value' => 25, 'scope' => 'all', 'policyVersion' => 7]),
              $ent, $this->rel($this->siblingRows()), $this->fs())
             ->evaluate('STU1', Benefit_policy_service::BENEFIT_SIBLING);

        $this->assertStringContainsString('v7', $ent->proposed[0]['reason'],
            'a benefit whose terms cannot be traced to a policy version is unauditable');
        $this->assertStringContainsString('STU2', $ent->proposed[0]['reason'],
            'and must name the evidence it rests on');
    }

    // ══ ELIGIBILITY ═════════════════════════════════════════════════════════

    public function test_no_verified_relationship_means_no_benefit(): void
    {
        $ent = $this->ent();
        $r = $this->svc($this->policy(['mode' => 'automatic', 'valueType' => 'percent',
              'value' => 25, 'scope' => 'all']), $ent, $this->rel([]), $this->fs())
             ->evaluate('STU1', Benefit_policy_service::BENEFIT_SIBLING);

        $this->assertSame('not_eligible', $r['outcome']);
        $this->assertSame([], $ent->proposed);
    }

    /** An employee who has left cannot support a staff-ward benefit. */
    public function test_an_inactive_employee_ends_the_staff_ward_benefit(): void
    {
        $ent = $this->ent();
        $rel = $this->rel([['studentId' => 'STU1', 'kind' => 'staff_ward',
                            'relatedId' => 'STA1', 'status' => 'active']]);
        $fs  = $this->fs(['SCH_T1_STA1' => ['staffId' => 'STA1', 'status' => 'Inactive']]);

        $r = $this->svc($this->policy(['mode' => 'automatic', 'valueType' => 'percent',
              'value' => 50, 'scope' => 'all', 'requireStaffActive' => true]), $ent, $rel, $fs)
             ->evaluate('STU1', Benefit_policy_service::BENEFIT_STAFF_WARD);

        $this->assertSame('not_eligible', $r['outcome']);
        $this->assertSame([], $ent->proposed);
    }

    /** Whether employment must be current is the SCHOOL's call, not ours. */
    public function test_a_school_may_choose_to_keep_the_benefit_after_an_employee_leaves(): void
    {
        $ent = $this->ent();
        $rel = $this->rel([['studentId' => 'STU1', 'kind' => 'staff_ward',
                            'relatedId' => 'STA1', 'status' => 'active']]);
        $fs  = $this->fs(['SCH_T1_STA1' => ['staffId' => 'STA1', 'status' => 'Inactive']]);

        $r = $this->svc($this->policy(['mode' => 'automatic', 'valueType' => 'percent',
              'value' => 50, 'scope' => 'all', 'requireStaffActive' => false]), $ent, $rel, $fs)
             ->evaluate('STU1', Benefit_policy_service::BENEFIT_STAFF_WARD);

        $this->assertSame('granted', $r['outcome'],
            'a school that honours the benefit after an employee leaves is unusual, not wrong');
    }

    // ══ SUPERSESSION — THE DUPLICATE-MONEY GUARD ════════════════════════════

    /**
     * Entitlement ids fingerprint value and dates, so a rate change mints a
     * SECOND entitlement. Unguarded, a student moved from 25% to 10% would hold
     * both — 35%. The old one must be discontinued.
     */
    public function test_a_rate_change_withdraws_the_previous_policy_benefit(): void
    {
        $old = [['entitlementId' => 'ENT_OLD', 'source' => Benefit_award_service::SOURCE,
                 'concessionType' => 'sibling', 'status' => 'active',
                 'valueType' => 'percent', 'value' => 25, 'scope' => 'all',
                 'effectiveFrom' => '2026-04-01', 'effectiveTo' => '']];
        $ent = $this->ent($old);

        $this->svc($this->policy(['mode' => 'automatic', 'valueType' => 'percent',
              'value' => 10, 'scope' => 'all']), $ent, $this->rel($this->siblingRows()), $this->fs())
             ->evaluate('STU1', Benefit_policy_service::BENEFIT_SIBLING);

        $this->assertCount(1, $ent->discontinued, 'the superseded benefit must be withdrawn');
        $this->assertSame('ENT_OLD', $ent->discontinued[0]['id']);
        $this->assertCount(1, $ent->proposed, 'and exactly one replacement proposed');
        $this->assertSame(10.0, $ent->proposed[0]['value']);
    }

    /**
     * A concession the finance office entered and approved by hand is not ours
     * to withdraw, whatever the policy now says.
     */
    public function test_a_hand_entered_concession_is_never_withdrawn_by_policy(): void
    {
        $manual = [['entitlementId' => 'ENT_MANUAL', 'source' => 'manual',
                    'concessionType' => 'sibling', 'status' => 'active',
                    'valueType' => 'percent', 'value' => 40, 'scope' => 'all']];
        $ent = $this->ent($manual);

        $this->svc($this->policy(['mode' => 'disabled']), $ent, $this->rel($this->siblingRows()), $this->fs())
             ->evaluate('STU1', Benefit_policy_service::BENEFIT_SIBLING);

        $this->assertSame([], $ent->discontinued,
            'disabling a policy must not silently cancel concessions a human approved — '
            . 'those follow their own lifecycle');
    }

    /** A benefit of a different type is left alone. */
    public function test_withdrawal_is_scoped_to_the_benefit_being_evaluated(): void
    {
        $other = [['entitlementId' => 'ENT_SW', 'source' => Benefit_award_service::SOURCE,
                   'concessionType' => 'staff_ward', 'status' => 'active',
                   'valueType' => 'percent', 'value' => 50, 'scope' => 'all']];
        $ent = $this->ent($other);

        $this->svc($this->policy(['mode' => 'disabled']), $ent, $this->rel([]), $this->fs())
             ->evaluate('STU1', Benefit_policy_service::BENEFIT_SIBLING);

        $this->assertSame([], $ent->discontinued,
            'turning off the sibling benefit must not touch the staff-ward one');
    }

    /** Re-running on unchanged terms must not churn the entitlement. */
    public function test_re_evaluating_on_identical_terms_withdraws_nothing(): void
    {
        $today = date('Y-m-d');
        $same  = [['entitlementId' => 'ENT_SAME', 'source' => Benefit_award_service::SOURCE,
                   'concessionType' => 'sibling', 'status' => 'active',
                   'valueType' => 'percent', 'value' => 25, 'scope' => 'all',
                   'effectiveFrom' => $today, 'effectiveTo' => '']];
        $ent = $this->ent($same);

        $this->svc($this->policy(['mode' => 'automatic', 'valueType' => 'percent',
              'value' => 25, 'scope' => 'all']), $ent, $this->rel($this->siblingRows()), $this->fs())
             ->evaluate('STU1', Benefit_policy_service::BENEFIT_SIBLING, $today);

        $this->assertSame([], $ent->discontinued,
            'an unchanged policy must leave the benefit alone. Deriving the effective-from '
            . 'twice once made every re-run discontinue its own entitlement, leaving the '
            . 'student with a withdrawn benefit and no active one.');
    }

    // ══ FAIL CLOSED ═════════════════════════════════════════════════════════

    public function test_an_error_grants_nothing(): void
    {
        $boom = new class {
            public function resolve(string $b): array { throw new \RuntimeException('policy store down'); }
            public function isInForce(array $p, string $on = ''): bool { return true; }
        };
        $ent = $this->ent();

        $r = $this->svc($boom, $ent, $this->rel($this->siblingRows()), $this->fs())
                  ->evaluate('STU1', Benefit_policy_service::BENEFIT_SIBLING);

        $this->assertSame('error', $r['outcome']);
        $this->assertSame([], $ent->proposed, 'an error must never leave a benefit half-granted');
    }
}
