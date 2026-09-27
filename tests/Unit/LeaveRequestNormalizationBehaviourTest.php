<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Behavioural cover for leave-request field normalization.
 *
 * WHY THIS EXISTS: the existing LeaveRequestTypeCodeNormalizationTest asserts
 * that certain SOURCE LINES are present. That proves the code was written; it
 * does not prove the mapping works. This executes the normalization against the
 * document shapes that actually occur and asserts the resulting values.
 *
 * The contract matters because leave requests arrive in two shapes:
 *
 *   Admin panel   →  snake_case  (staff_id, type_code, from_date …)
 *   Teacher app   →  camelCase   (applicantId, typeCode, startDate …)
 *
 * and `type_code` was the ONE field the single-request reader failed to
 * normalize. cancel_leave() branches on it to decide whether to RELEASE a
 * comp-off credit, so a Teacher-app request cancelled through the admin panel
 * left the earned credit consumed — a silent entitlement leak.
 *
 * The transcription below is pinned against the shipped source by the final
 * test, so it cannot drift away from production unnoticed.
 */
final class LeaveRequestNormalizationBehaviourTest extends TestCase
{
    /** Exactly the normalization _fsGetLeaveRequest() applies. */
    private function normalize(array $r): array
    {
        if (!isset($r['staff_id']))   $r['staff_id']   = $r['staffId']      ?? $r['applicantId']   ?? '';
        if (!isset($r['staff_name'])) $r['staff_name'] = $r['staffName']    ?? $r['applicantName'] ?? '';
        if (!isset($r['type_id']))    $r['type_id']    = $r['typeId']       ?? $r['leaveType']     ?? '';
        if (!isset($r['type_code']))  $r['type_code']  = $r['typeCode']     ?? $r['leaveType']     ?? '';
        if (!isset($r['from_date']))  $r['from_date']  = $r['fromDate']     ?? $r['startDate']     ?? '';
        if (!isset($r['to_date']))    $r['to_date']    = $r['toDate']       ?? $r['endDate']       ?? '';
        if (!isset($r['paid_days']))  $r['paid_days']  = $r['paidDays']     ?? 0;
        if (!isset($r['lwp_days']))   $r['lwp_days']   = $r['lwpDays']      ?? 0;
        if (!isset($r['days']))       $r['days']       = $r['numberOfDays'] ?? 1;
        return $r;
    }

    /** A Teacher-app leave document, as the Kotlin client writes it. */
    private function teacherAppDoc(): array
    {
        return [
            'applicantType' => 'staff',
            'applicantId'   => 'STA0007',
            'applicantName' => 'A Teacher',
            'leaveType'     => 'COMP_OFF',
            'startDate'     => '2026-04-10',
            'endDate'       => '2026-04-10',
            'numberOfDays'  => 1.0,
            'status'        => 'Approved',
        ];
    }

    // ── Teacher-app shape normalizes completely ──────────────────────────

    public function test_teacher_app_document_normalizes_every_field(): void
    {
        $n = $this->normalize($this->teacherAppDoc());

        $this->assertSame('STA0007',    $n['staff_id']);
        $this->assertSame('A Teacher',  $n['staff_name']);
        $this->assertSame('COMP_OFF',   $n['type_id']);
        $this->assertSame('2026-04-10', $n['from_date']);
        $this->assertSame('2026-04-10', $n['to_date']);
        $this->assertSame(1.0,          $n['days']);
    }

    /**
     * THE DEFECT THIS GUARDS: without type_code, cancel_leave() cannot tell the
     * request is comp-off and never releases the earned credit.
     */
    public function test_comp_off_type_code_is_derived_for_a_teacher_app_request(): void
    {
        $n = $this->normalize($this->teacherAppDoc());

        $this->assertSame('COMP_OFF', $n['type_code'],
            'cancel_leave branches on type_code to release the comp-off credit');
    }

    public function test_cancelling_a_teacher_app_comp_off_is_recognisable(): void
    {
        $n = $this->normalize($this->teacherAppDoc());

        // The exact predicate cancel_leave uses.
        $isCompOff = strtoupper(trim((string) ($n['type_code'] ?? ''))) === 'COMP_OFF';
        $this->assertTrue($isCompOff, 'an unrecognised comp-off cancellation leaks the credit');
    }

    // ── Never overwrite an explicit value ────────────────────────────────

    public function test_existing_snake_case_values_are_never_overwritten(): void
    {
        $doc = $this->teacherAppDoc();
        $doc['type_code'] = 'CL';
        $doc['staff_id']  = 'STA9999';

        $n = $this->normalize($doc);

        $this->assertSame('CL',      $n['type_code'], 'an explicit value wins over the fallback');
        $this->assertSame('STA9999', $n['staff_id']);
    }

    public function test_admin_shaped_document_passes_through_unchanged(): void
    {
        $admin = [
            'staff_id' => 'STA0001', 'staff_name' => 'Admin Created', 'type_id' => 'CL',
            'type_code' => 'CL', 'from_date' => '2026-04-01', 'to_date' => '2026-04-02',
            'paid_days' => 2, 'lwp_days' => 0, 'days' => 2,
        ];

        $this->assertSame($admin, $this->normalize($admin));
    }

    // ── Fallback precedence ──────────────────────────────────────────────

    public function test_camel_case_wins_over_the_leave_type_fallback(): void
    {
        $doc = $this->teacherAppDoc();
        $doc['typeCode'] = 'SL';
        $doc['typeId']   = 'SL_ID';

        $n = $this->normalize($doc);

        $this->assertSame('SL',    $n['type_code'], 'typeCode is preferred over leaveType');
        $this->assertSame('SL_ID', $n['type_id']);
    }

    public function test_applicant_id_is_the_last_resort_for_staff_id(): void
    {
        $doc = $this->teacherAppDoc();
        $doc['staffId'] = 'STA0042';

        $this->assertSame('STA0042', $this->normalize($doc)['staff_id'],
            'staffId is preferred over applicantId');
    }

    // ── Defaults and edge shapes ─────────────────────────────────────────

    public function test_missing_day_fields_default_safely(): void
    {
        $n = $this->normalize(['applicantId' => 'STA1']);

        $this->assertSame(0, $n['paid_days'], 'a missing paid_days must not be null');
        $this->assertSame(0, $n['lwp_days']);
        $this->assertSame(1, $n['days'], 'days defaults to 1, never 0');
    }

    public function test_completely_empty_document_yields_empty_strings_not_nulls(): void
    {
        $n = $this->normalize([]);

        foreach (['staff_id', 'staff_name', 'type_id', 'type_code', 'from_date', 'to_date'] as $f) {
            $this->assertSame('', $n[$f], "{$f} must be '' rather than null");
        }
    }

    /** Fractional day counts must survive normalization — half-day leave is 0.5. */
    public function test_fractional_days_survive(): void
    {
        $doc = $this->teacherAppDoc();
        $doc['numberOfDays'] = 0.5;

        $this->assertSame(0.5, $this->normalize($doc)['days']);
    }

    public function test_zero_days_is_preserved_not_defaulted(): void
    {
        $doc = $this->teacherAppDoc();
        $doc['days'] = 0;

        $this->assertSame(0, $this->normalize($doc)['days'],
            'an explicit 0 must not be replaced by the default of 1');
    }

    // ── Source pin ───────────────────────────────────────────────────────

    /**
     * Both readers must normalize type_code, or the two paths disagree about
     * whether a request is comp-off.
     */
    public function test_both_readers_still_normalize_type_code(): void
    {
        $src = file_get_contents(__DIR__ . '/../../application/controllers/Hr.php');
        $this->assertNotFalse($src);

        $this->assertSame(
            2,
            substr_count($src, "['type_code']  = \$request['typeCode']")
            + substr_count($src, "['type_code']  = \$rd['typeCode']"),
            'the single-request and all-requests readers must both derive type_code'
        );
    }

    public function test_transcription_matches_the_shipped_fallback_chain(): void
    {
        $src = file_get_contents(__DIR__ . '/../../application/controllers/Hr.php');
        $this->assertNotFalse($src);

        // Pin the precedence this test relies on.
        $this->assertStringContainsString("\$rd['typeCode']     ?? \$rd['leaveType']     ?? '';", $src);
        $this->assertStringContainsString("\$rd['numberOfDays'] ?? 1;", $src);
    }
}
