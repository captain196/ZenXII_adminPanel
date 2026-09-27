<?php

use PHPUnit\Framework\TestCase;

if (!defined('BASEPATH')) define('BASEPATH', __DIR__);
if (!defined('APPPATH'))  define('APPPATH', __DIR__ . '/../../application/');
if (!function_exists('log_message')) { function log_message($l, $m) {} }

require_once __DIR__ . '/../../application/libraries/Entitlement_allocation_service.php';

/**
 * P9 — SCHOLARSHIP RETIREMENT: the parity contract the Parent app depends on.
 *
 * The Parent app computes one number from the collection being retired:
 *
 *     FeesViewModel: overview.scholarshipAmount =
 *         getScholarshipAwards(studentId)      // schoolId == <canonical>,
 *                                              // studentId ==, status == "active"
 *             .sumOf { it.amount }
 *
 * Retiring `scholarshipAwards` means some other source must produce the SAME
 * figure, or families silently see ₹0 where a scholarship used to appear. That
 * is the whole reason the retirement flag is still false.
 *
 * This file pins the arithmetic the replacement must reproduce, using the real
 * allocation service to build the rows. It does NOT wire anything and does not
 * authorize retirement — it makes the contract executable so a future migration
 * is checked rather than hoped.
 *
 * ── TWO FACTS ESTABLISHED BY MEASUREMENT (2026-08-19) ─────────────────────
 *  1. `scholarshipAwards` holds **0 documents on every tenant**. The Parent
 *     dependency is therefore a CODE dependency, not a DATA one: the app reads
 *     the collection, finds nothing, and shows ₹0 today.
 *  2. The namespaces already agree. Admin writes `schoolId = $this->school_name`,
 *     which `MY_Controller` sets to the canonical `school_id`; Parent's
 *     `getSchoolCode()` in `FeeFirestoreRepository` returns `user.schoolId`,
 *     also canonical, despite its misleading name. A switch to
 *     `entitlementAllocations` (canonical by DEC-008) is namespace-compatible.
 *
 * Neither fact authorizes retirement. Fact 1 lowers the blast radius; it does
 * not remove the code path, and a tenant awarding a scholarship tomorrow would
 * re-create the dependency immediately.
 */
class P9ScholarshipParityTest extends TestCase
{
    private const SCHOOL  = 'SCH_TESTTENANT1';
    private const SESSION = '2026-27';
    private const STUDENT = 'STU9001';

    private function svc(): Entitlement_allocation_service
    {
        return (new Entitlement_allocation_service())
            ->init(null, null, self::SCHOOL, self::SESSION, 'SSA_TEST');
    }

    /** Build a real allocation row for one demand/entitlement pair. */
    private function row(string $demandId, string $entId, float $amount, float $gross = 5000.0): array
    {
        $built = $this->svc()->build(
            ['demandId' => $demandId, 'schoolId' => self::SCHOOL, 'session' => self::SESSION,
             'studentId' => self::STUDENT, 'grossAmount' => $gross, 'discountAmount' => $amount],
            ['entitlementId' => $entId, 'schoolId' => self::SCHOOL, 'session' => self::SESSION,
             'status' => 'active', 'valueType' => 'amount', 'value' => $amount, 'scope' => 'head'],
            $amount
        );
        $this->assertTrue($built['ok'], 'fixture build failed: ' . ($built['reason'] ?? ''));
        return $built['row'];
    }

    /**
     * The replacement figure, expressed exactly as the Parent app expresses it:
     * sum the ACTIVE rows for this student in this tenant.
     */
    private function scholarshipAmountFromLedger(array $rows, string $studentId): float
    {
        $sum = 0.0;
        foreach ($rows as $r) {
            if ((string) ($r['schoolId']  ?? '') !== self::SCHOOL)  continue;
            if ((string) ($r['studentId'] ?? '') !== $studentId)    continue;
            if ((string) ($r['status']    ?? '') !== 'active')      continue;
            $sum += (float) ($r['allocatedAmount'] ?? 0);
        }
        return round($sum, 2);
    }

    /** The legacy figure, as `FeesViewModel` computes it today. */
    private function scholarshipAmountFromAwards(array $awards, string $studentId): float
    {
        $sum = 0.0;
        foreach ($awards as $a) {
            if ((string) ($a['schoolId']  ?? '') !== self::SCHOOL) continue;
            if ((string) ($a['studentId'] ?? '') !== $studentId)   continue;
            if ((string) ($a['status']    ?? '') !== 'active')     continue;
            $sum += (float) ($a['amount'] ?? 0);
        }
        return round($sum, 2);
    }

    // ══ THE PARITY CONTRACT ════════════════════════════════════════════════

    /** One grant: both sources must produce the identical figure. */
    public function test_the_ledger_reproduces_the_legacy_scholarship_total(): void
    {
        $rows   = [$this->row('FD_0001', 'SE_MERIT', 1500.0)];
        $awards = [['schoolId' => self::SCHOOL, 'studentId' => self::STUDENT,
                    'status' => 'active', 'amount' => 1500.0]];

        $this->assertSame(
            $this->scholarshipAmountFromAwards($awards, self::STUDENT),
            $this->scholarshipAmountFromLedger($rows, self::STUDENT),
            'the replacement must produce the same number a family already sees');
    }

    /** Several demands accumulate, exactly as several awards would. */
    public function test_allocations_across_demands_sum_like_awards_do(): void
    {
        $rows = [
            $this->row('FD_0001', 'SE_MERIT', 500.0),
            $this->row('FD_0002', 'SE_MERIT', 500.0),
            $this->row('FD_0003', 'SE_MERIT', 500.0),
        ];
        $this->assertSame(1500.0, $this->scholarshipAmountFromLedger($rows, self::STUDENT));
    }

    /** A REVERSED allocation drops out, exactly as a revoked award does. */
    public function test_a_reversed_allocation_drops_out_like_a_revoked_award(): void
    {
        $rows = [
            $this->row('FD_0001', 'SE_MERIT',   1000.0),
            $this->row('FD_0002', 'SE_DROPPED',  400.0),
        ];
        $rows[1] = array_merge($rows[1], $this->svc()->buildReversal($rows[1], 'overturned'));

        $awards = [
            ['schoolId' => self::SCHOOL, 'studentId' => self::STUDENT, 'status' => 'active',  'amount' => 1000.0],
            ['schoolId' => self::SCHOOL, 'studentId' => self::STUDENT, 'status' => 'revoked', 'amount' => 400.0],
        ];

        $this->assertSame(
            $this->scholarshipAmountFromAwards($awards, self::STUDENT),
            $this->scholarshipAmountFromLedger($rows, self::STUDENT));
        $this->assertSame(1000.0, $this->scholarshipAmountFromLedger($rows, self::STUDENT));
    }

    /** Another student's grants must never leak into this student's total. */
    public function test_another_students_allocations_do_not_leak(): void
    {
        $mine   = $this->row('FD_0001', 'SE_MERIT', 1000.0);
        $theirs = $mine;
        $theirs['studentId']    = 'STU9002';
        $theirs['allocationId'] = self::SCHOOL . '_FD_0009_SE_MERIT';

        $this->assertSame(1000.0, $this->scholarshipAmountFromLedger([$mine, $theirs], self::STUDENT));
        $this->assertSame(1000.0, $this->scholarshipAmountFromLedger([$mine, $theirs], 'STU9002'));
    }

    /** No rows means ₹0 — which is exactly what the app shows today. */
    public function test_no_rows_yields_zero_matching_todays_observed_behaviour(): void
    {
        $this->assertSame(0.0, $this->scholarshipAmountFromLedger([], self::STUDENT));
        $this->assertSame(0.0, $this->scholarshipAmountFromAwards([], self::STUDENT));
    }

    // ══ THE RETIREMENT STAYS SHUT ══════════════════════════════════════════

    /** The flag must remain false: the Parent code path still exists. */
    public function test_the_retirement_flag_is_still_false(): void
    {
        $cfg = (string) file_get_contents(APPPATH . 'config/fees_exemption_v2_flags.php');
        $this->assertMatchesRegularExpression(
            "/\\\$config\\['SCHOLARSHIP_AWARDS_RETIRED'\\]\\s*=\\s*false\\s*;/", $cfg,
            'retirement must not be armed while the Parent app still reads the collection');
    }

    /**
     * The writer gate must close CREATION only. A retirement that also blocked
     * revocation or reads would strand live records instead of retiring a surface.
     */
    public function test_the_writer_gate_closes_creation_only(): void
    {
        $src = (string) file_get_contents(APPPATH . 'controllers/Fee_management.php');
        $at  = strpos($src, 'function award_scholarship');
        $this->assertNotFalse($at);

        // The gate sits at the top of the creation endpoint...
        $head = substr($src, $at, 2000);
        $this->assertStringContainsString('SCHOLARSHIP_AWARDS_RETIRED', $head,
            'the gate must guard creation');

        // ...and nowhere near revocation, which must stay open.
        $revokeAt = strpos($src, 'function revoke_scholarship');
        if ($revokeAt !== false) {
            $revoke = substr($src, $revokeAt, 2000);
            $this->assertStringNotContainsString('SCHOLARSHIP_AWARDS_RETIRED', $revoke,
                'revocation must never be blocked — that would trap existing awards');
        }
    }
}
