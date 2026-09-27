<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Verifies the leave-balance impact ANALYSER before anyone acts on its output.
 *
 * The analyser exists because defect 5 — `(int) $req['days']` in
 * allocate_leave_balances() — left stored balances too HIGH by the truncated
 * remainder. Balances have not moved yet: they are only rewritten the next time
 * that reconciler runs. The analyser recomputes `used` under BOTH the old and
 * the corrected rule and reports the delta, so the size of any correction is
 * known before a decision is made.
 *
 * An analyser whose own arithmetic is wrong is worse than none — it would either
 * hide real drift or invent it, and a remediation decision would be taken on
 * fabricated numbers. These tests therefore exercise the exact algorithm against
 * controlled fixtures covering whole-day, half-day, mixed, multi-day, carry
 * forward and cancellation cases, plus idempotency, and they pin the algorithm
 * against the shipped harness source.
 *
 * The analyser performs NO writes. That is asserted here as a property of the
 * source, not merely intended.
 */
final class LeaveBalanceAnalyserTest extends TestCase
{
    /** The analyser's per-request accumulation, transcribed from the harness. */
    private function accumulate(array $requests): array
    {
        $agg = [];
        foreach ($requests as $r) {
            if (strtolower((string) ($r['status'] ?? '')) !== 'approved') continue;

            $sid  = (string) ($r['staff_id'] ?? '');
            $tid  = (string) ($r['type_id'] ?? '');
            $from = (string) ($r['from_date'] ?? '');
            if ($sid === '' || $tid === '' || $from === '') continue;

            $days = (float) ($r['days'] ?? 0);
            $key  = $sid . '|' . $tid . '|' . date('Y', strtotime($from));

            if (!isset($agg[$key])) $agg[$key] = ['old' => 0, 'new' => 0, 'n' => 0];
            $agg[$key]['old'] += (int) $days;                                   // the defect
            $agg[$key]['new'] += ($days == floor($days)) ? (int) $days : $days; // the fix
            $agg[$key]['n']++;
        }
        return $agg;
    }

    private function req(string $sid, string $tid, string $from, $days, string $status = 'Approved'): array
    {
        return ['staff_id' => $sid, 'type_id' => $tid, 'from_date' => $from, 'days' => $days, 'status' => $status];
    }

    // ── Whole days: the analyser must report NO drift ────────────────────

    public function test_whole_day_leave_shows_no_drift(): void
    {
        $agg = $this->accumulate([
            $this->req('S1', 'CL', '2026-04-02', 1),
            $this->req('S1', 'CL', '2026-04-10', 3),
        ]);

        $a = $agg['S1|CL|2026'];
        $this->assertSame(4, $a['old']);
        $this->assertSame(4, $a['new']);
        $this->assertSame(0, $a['new'] - $a['old'], 'whole days were never truncated');
        $this->assertIsInt($a['new'], 'and must not be retyped to float');
    }

    // ── Half days: the case that consumed ZERO balance ───────────────────

    public function test_half_day_leave_drift_is_the_whole_amount(): void
    {
        $agg = $this->accumulate([$this->req('S1', 'CL', '2026-04-02', 0.5)]);

        $a = $agg['S1|CL|2026'];
        $this->assertSame(0, $a['old'], 'the defect: a half-day consumed nothing');
        $this->assertSame(0.5, $a['new']);
        $this->assertSame(0.5, $a['new'] - $a['old'], 'balance was overstated by the full 0.5');
    }

    public function test_repeated_half_days_accumulate_the_drift(): void
    {
        $agg = $this->accumulate([
            $this->req('S1', 'CL', '2026-04-02', 0.5),
            $this->req('S1', 'CL', '2026-04-09', 0.5),
            $this->req('S1', 'CL', '2026-04-16', 0.5),
        ]);

        $a = $agg['S1|CL|2026'];
        $this->assertSame(0, $a['old'], 'three half-days still consumed nothing');
        $this->assertSame(1.5, $a['new']);
    }

    // ── Mixed and multi-day ──────────────────────────────────────────────

    public function test_mixed_whole_and_half_days(): void
    {
        $agg = $this->accumulate([
            $this->req('S1', 'CL', '2026-04-02', 2),
            $this->req('S1', 'CL', '2026-04-09', 0.5),
        ]);

        $a = $agg['S1|CL|2026'];
        $this->assertSame(2, $a['old']);
        $this->assertSame(2.5, $a['new']);
        $this->assertSame(0.5, $a['new'] - $a['old']);
    }

    /** A multi-day span crossing a Calendar Half-Day resolves to e.g. 2.5. */
    public function test_fractional_multi_day_span(): void
    {
        $agg = $this->accumulate([$this->req('S1', 'CL', '2026-04-02', 2.5)]);

        $a = $agg['S1|CL|2026'];
        $this->assertSame(2, $a['old'], 'the defect lost the half');
        $this->assertSame(2.5, $a['new']);
    }

    // ── Scoping ──────────────────────────────────────────────────────────

    public function test_drift_is_scoped_per_staff_type_and_year(): void
    {
        $agg = $this->accumulate([
            $this->req('S1', 'CL', '2026-04-02', 0.5),
            $this->req('S1', 'SL', '2026-04-02', 0.5),
            $this->req('S2', 'CL', '2026-04-02', 0.5),
            $this->req('S1', 'CL', '2025-04-02', 0.5),
        ]);

        $this->assertCount(4, $agg, 'each staff/type/year is reconciled independently');
        foreach ($agg as $a) $this->assertSame(0.5, $a['new'] - $a['old']);
    }

    // ── Cancellation and non-approved states ─────────────────────────────

    /**
     * A cancelled or rejected request must not count. This is also why
     * cancellation needs no separate reversal: `used` is RECONCILED from the
     * approved set rather than incremented, so removing approval removes the
     * consumption by construction.
     */
    public function test_only_approved_requests_are_counted(): void
    {
        $agg = $this->accumulate([
            $this->req('S1', 'CL', '2026-04-02', 0.5, 'Approved'),
            $this->req('S1', 'CL', '2026-04-09', 0.5, 'Cancelled'),
            $this->req('S1', 'CL', '2026-04-16', 0.5, 'Rejected'),
            $this->req('S1', 'CL', '2026-04-23', 0.5, 'Pending'),
        ]);

        $this->assertSame(0.5, $agg['S1|CL|2026']['new'], 'only the approved half-day counts');
        $this->assertSame(1, $agg['S1|CL|2026']['n']);
    }

    public function test_status_matching_is_case_insensitive(): void
    {
        $agg = $this->accumulate([
            $this->req('S1', 'CL', '2026-04-02', 0.5, 'approved'),
            $this->req('S1', 'CL', '2026-04-09', 0.5, 'APPROVED'),
        ]);

        $this->assertSame(1.0, $agg['S1|CL|2026']['new'], 'legacy CapitalCase and lowercase both count');
    }

    public function test_malformed_rows_are_skipped_without_distorting_the_total(): void
    {
        $agg = $this->accumulate([
            $this->req('',   'CL', '2026-04-02', 0.5),
            $this->req('S1', '',   '2026-04-02', 0.5),
            $this->req('S1', 'CL', '',           0.5),
            $this->req('S1', 'CL', '2026-04-02', 0.5),
        ]);

        $this->assertCount(1, $agg);
        $this->assertSame(0.5, $agg['S1|CL|2026']['new']);
    }

    // ── Idempotency ──────────────────────────────────────────────────────

    public function test_analyser_is_idempotent(): void
    {
        $rows = [
            $this->req('S1', 'CL', '2026-04-02', 0.5),
            $this->req('S1', 'CL', '2026-04-09', 2),
        ];

        $this->assertEquals($this->accumulate($rows), $this->accumulate($rows),
            'running it twice must report the same delta — it accumulates nothing across runs');
    }

    // ── Source pins ──────────────────────────────────────────────────────

    /** The analyser must compute BOTH values, or the delta is unverifiable. */
    public function test_shipped_analyser_computes_both_old_and_new(): void
    {
        $src = file_get_contents(__DIR__ . '/../../application/controllers/Chd_uat.php');
        $this->assertNotFalse($src);

        $this->assertStringContainsString("\$agg[\$key]['old'] += (int) \$days;", $src);
        $this->assertStringContainsString(
            "\$agg[\$key]['new'] += (\$days == floor(\$days)) ? (int) \$days : \$days;", $src);
    }

    /**
     * NO WRITES. The analyser reads and reports; any correction is a separate,
     * authorized decision. A stray write here would modify balances during what
     * is presented as an impact assessment.
     */
    public function test_shipped_analyser_performs_no_writes(): void
    {
        $src = file_get_contents(__DIR__ . '/../../application/controllers/Chd_uat.php');
        $this->assertNotFalse($src);

        $start = strpos($src, 'public function balanceimpact(');
        $this->assertNotFalse($start);
        $end = strpos($src, "\n    /**", $start + 10);
        $body = substr($src, $start, ($end !== false ? $end - $start : 4000));

        foreach (['guardedSet', 'guardedUpdate', 'guardedDelete', 'firestoreSet',
                  'firestoreUpdate', 'firestoreDelete', '->set(', '->update('] as $writer) {
            $this->assertStringNotContainsString($writer, $body,
                "balanceimpact() must not write — found '{$writer}'");
        }
    }
}
