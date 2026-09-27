<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * CA-6 — the employee statutory profile, and the case it exists to express.
 *
 * BUILT 2026-08-22. ZenX had no per-employee PF membership record: coverage was
 * re-derived on every payroll run from the employee's current wage against the
 * statutory ceiling. That cannot express the commonest real case in Indian
 * payroll —
 *
 *      joined below the ceiling → became a member → now earns above it
 *      → REMAINS a member
 *
 * — because a wage comparison drops such an employee out of PF the month they
 * cross the ceiling. It also cannot express the converse: someone who joined
 * ABOVE the ceiling is a member only by election under Para 26(6), and then
 * receives no pension-scheme share.
 *
 * These exercise the REAL service. There is no mirror of its logic here.
 */
final class StaffStatutoryProfileTest extends TestCase
{
    protected function setUp(): void
    {
        if (!defined('BASEPATH')) define('BASEPATH', __DIR__);
        require_once __DIR__ . '/../../application/libraries/Staff_statutory_profile_service.php';
    }

    private function member(array $over = []): array
    {
        return ['pf' => array_merge([
            'state'       => 'member',
            'basis'       => 'below_ceiling_at_joining',
            'uan'         => '123456789012',
            'memberSince' => '2024-04-01',
            'epsEligible' => true,
        ], $over)];
    }

    // ── The case the old derivation could not express ────────────────────

    /**
     * THE LOAD-BEARING TEST. A recorded member stays covered even when the
     * wage-versus-ceiling derivation says they should not be.
     */
    public function test_a_recorded_member_stays_covered_above_the_ceiling(): void
    {
        $r = Staff_statutory_profile_service::resolvePf($this->member(), '2026-08-31', false);

        $this->assertTrue($r['covered'],
            'someone who joined below the ceiling remains a member after crossing it');
        $this->assertSame('profile', $r['source'],
            'the recorded fact must outrank the derivation');
    }

    /** And a recorded non-member is not covered even if the derivation says so. */
    public function test_a_recorded_non_member_is_not_covered(): void
    {
        $r = Staff_statutory_profile_service::resolvePf(
            ['pf' => ['state' => 'not_member', 'basis' => 'excluded']], '2026-08-31', true
        );

        $this->assertFalse($r['covered']);
        $this->assertSame('profile', $r['source']);
    }

    // ── Para 26(6) ───────────────────────────────────────────────────────

    /**
     * An above-ceiling election carries membership but NOT the pension scheme —
     * the whole employer contribution goes to the fund. That is statutory, not
     * an employer choice, so the resolver forces it.
     */
    public function test_an_above_ceiling_election_carries_no_pension_scheme(): void
    {
        $r = Staff_statutory_profile_service::resolvePf(
            $this->member(['basis' => 'above_ceiling_election', 'epsEligible' => true]),
            '2026-08-31',
            false
        );

        $this->assertTrue($r['covered']);
        $this->assertFalse($r['epsEligible'],
            'Para 26(6) membership must not carry pension-scheme eligibility, '
            . 'even if the stored value claims otherwise');
    }

    /** And validation refuses to store the contradiction in the first place. */
    public function test_validation_refuses_pension_eligibility_on_an_election(): void
    {
        [$ok, $clean, $errors] = Staff_statutory_profile_service::validate(
            $this->member(['basis' => 'above_ceiling_election', 'epsEligible' => true])
        );

        $this->assertFalse($ok);
        $this->assertNotEmpty($errors);
        $this->assertFalse($clean['pf']['epsEligible'],
            'even the normalised output must not carry the impossible combination');
    }

    // ── Fail-safe: an unconfigured employee is unchanged ─────────────────

    /**
     * THE PROPERTY THAT MAKES THIS SAFE TO SHIP. No profile ⇒ the derivation
     * stands, whatever it concluded. No staff document carries a profile today.
     */
    public function test_an_unrecorded_employee_falls_back_to_the_derivation(): void
    {
        foreach ([true, false] as $derived) {
            $r = Staff_statutory_profile_service::resolvePf([], '2026-08-31', $derived);

            $this->assertSame($derived, $r['covered'],
                'the fallback must return exactly what the derivation decided');
            $this->assertSame('ceiling_derivation', $r['source']);
        }
    }

    /** "Undetermined" is not "not a member" — collapsing them would exclude staff. */
    public function test_undetermined_is_not_treated_as_not_a_member(): void
    {
        $empty = Staff_statutory_profile_service::emptyProfile();

        $this->assertSame('undetermined', $empty['pf']['state']);

        $r = Staff_statutory_profile_service::resolvePf($empty, '2026-08-31', true);
        $this->assertTrue($r['covered'],
            'an unestablished profile must not silently exclude someone from PF');
        $this->assertSame('ceiling_derivation', $r['source']);
    }

    // ── Membership cannot be back-dated ──────────────────────────────────

    /**
     * Membership starting after the period must not apply to it — that would
     * back-date a contribution onto a month the person was not a member.
     */
    public function test_membership_does_not_apply_before_it_begins(): void
    {
        $r = Staff_statutory_profile_service::resolvePf(
            $this->member(['memberSince' => '2026-09-01']), '2026-08-31', false
        );

        $this->assertFalse($r['covered']);
        $this->assertStringContainsString('after this period', $r['reason']);
    }

    /** On the exact start date it does apply. */
    public function test_membership_applies_from_its_start_date(): void
    {
        $r = Staff_statutory_profile_service::resolvePf(
            $this->member(['memberSince' => '2026-08-31']), '2026-08-31', false
        );

        $this->assertTrue($r['covered']);
    }

    // ── Validation refuses incoherent records ────────────────────────────

    public function test_a_member_must_record_how_membership_arose(): void
    {
        [$ok, , $errors] = Staff_statutory_profile_service::validate(
            $this->member(['basis' => 'undetermined'])
        );

        $this->assertFalse($ok);
        $this->assertStringContainsString('basis', implode(' ', $errors));
    }

    public function test_a_member_must_record_a_start_date(): void
    {
        [$ok, , $errors] = Staff_statutory_profile_service::validate(
            $this->member(['memberSince' => ''])
        );

        $this->assertFalse($ok);
        $this->assertStringContainsString('member-since', implode(' ', $errors));
    }

    public function test_a_non_member_cannot_carry_a_membership_basis(): void
    {
        [$ok, , $errors] = Staff_statutory_profile_service::validate(
            ['pf' => ['state' => 'not_member', 'basis' => 'below_ceiling_at_joining']]
        );

        $this->assertFalse($ok);
        $this->assertStringContainsString('non-member', implode(' ', $errors));
    }

    public function test_a_member_cannot_be_excluded(): void
    {
        [$ok, , $errors] = Staff_statutory_profile_service::validate(
            $this->member(['basis' => 'excluded'])
        );

        $this->assertFalse($ok);
    }

    // ── Identifier formats ───────────────────────────────────────────────

    public function test_uan_must_be_twelve_digits(): void
    {
        foreach (['12345', '1234567890123', 'abcdefghijkl', '12345678901a'] as $bad) {
            [$ok, , $errors] = Staff_statutory_profile_service::validate($this->member(['uan' => $bad]));
            $this->assertFalse($ok, "UAN '{$bad}' must be rejected");
            $this->assertStringContainsString('12 digits', implode(' ', $errors));
        }

        [$ok] = Staff_statutory_profile_service::validate($this->member(['uan' => '123456789012']));
        $this->assertTrue($ok);
    }

    public function test_esic_number_must_be_seventeen_digits(): void
    {
        [$ok, , $errors] = Staff_statutory_profile_service::validate([
            'pf'  => ['state' => 'not_member', 'basis' => 'excluded'],
            'esi' => ['esicNumber' => '123'],
        ]);

        $this->assertFalse($ok);
        $this->assertStringContainsString('17 digits', implode(' ', $errors));
    }

    /** Dates are validated strictly — `strtotime` would accept "next tuesday". */
    public function test_dates_must_be_real_iso_dates(): void
    {
        $this->assertTrue(Staff_statutory_profile_service::isIsoDate('2026-02-28'));
        $this->assertFalse(Staff_statutory_profile_service::isIsoDate('2026-02-30'),
            'a calendar-invalid date must be refused, not silently rolled over');
        $this->assertFalse(Staff_statutory_profile_service::isIsoDate('next tuesday'));
        $this->assertFalse(Staff_statutory_profile_service::isIsoDate('01-04-2026'));
    }

    // ── Readiness ────────────────────────────────────────────────────────

    /** An unconfigured profile reports what is missing rather than looking complete. */
    public function test_completeness_names_what_is_missing(): void
    {
        $c = Staff_statutory_profile_service::completeness(
            Staff_statutory_profile_service::emptyProfile()
        );

        $this->assertFalse($c['complete']);
        $this->assertContains('PF membership state', $c['missing']);
    }

    public function test_a_fully_recorded_member_is_complete(): void
    {
        $c = Staff_statutory_profile_service::completeness($this->member());

        $this->assertTrue($c['complete'], implode(', ', $c['missing']));
    }

    public function test_a_member_missing_a_uan_is_incomplete(): void
    {
        $c = Staff_statutory_profile_service::completeness($this->member(['uan' => '']));

        $this->assertFalse($c['complete']);
        $this->assertContains('UAN', $c['missing']);
    }

    // ── Purity ───────────────────────────────────────────────────────────

    /**
     * The service must carry no rate, ceiling or persistence. It answers
     * "is this person a member?", never "how much" — the amounts stay with the
     * tenant's CA-verified statutory configuration.
     */
    public function test_the_service_contains_no_rate_ceiling_or_persistence(): void
    {
        $src = (string) file_get_contents(
            __DIR__ . '/../../application/libraries/Staff_statutory_profile_service.php'
        );

        $code = '';
        foreach (token_get_all($src) as $t) {
            if (is_array($t)) {
                if ($t[0] === T_COMMENT || $t[0] === T_DOC_COMMENT) continue;
                $code .= $t[1];
            } else {
                $code .= $t;
            }
        }

        $this->assertStringContainsString('class Staff_statutory_profile_service', $code, 'strip sanity');

        foreach (['15000', '1250', '8.33', '12.0', 'firestore', '$this->fs', 'firebase'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $code,
                "the profile service must stay free of {$forbidden}");
        }
    }
}
