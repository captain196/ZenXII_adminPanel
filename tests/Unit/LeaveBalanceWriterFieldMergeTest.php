<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * The leave-balance writer must merge per FIELD, not per leave type.
 *
 * DEFECT (found 2026-08-20 while behaviourally auditing the entitlement paths):
 * `_fsSyncLeaveBalance()` merged with `array_merge($existing, $balances)`, which
 * operates at the TYPE level — `$merged['CL']` became the incoming entry
 * outright, deleting every key the caller did not restate.
 *
 * Two engines write to the same entry and own different fields:
 *
 *   allocate_leave_balances()          owns allocated / used / carried / balance
 *   _accrue_monthly_leave_one_type()   owns accruedToDate / lastAccrualMonth
 *
 * So a single allocate run erased the accrual ledger. Worse, accrual resumes
 * from `lastAccrualMonth`; with that key gone it restarted at the year's
 * beginning and re-credited months it had already granted. Silent data loss
 * followed by silent double-credit of leave entitlement.
 *
 * LATENT, NOT LIVE: every leave Phase-2 flag — including
 * LEAVE_ACCRUAL_ENABLED — defaults to false, so no tenant runs accrual today.
 * This closes the trap before the first tenant switches it on.
 *
 * These tests execute the merge behaviour directly.
 */
final class LeaveBalanceWriterFieldMergeTest extends TestCase
{
    /** The merge as shipped after the fix. */
    private function merge(array $existing, array $incoming): array
    {
        $merged = $existing;
        foreach ($incoming as $tid => $bd) {
            $prev = $existing[$tid] ?? null;
            $merged[$tid] = (is_array($prev) && is_array($bd)) ? array_merge($prev, $bd) : $bd;
        }
        return $merged;
    }

    /** The old behaviour, kept to demonstrate the defect. */
    private function oldMerge(array $existing, array $incoming): array
    {
        return array_merge($existing, $incoming);
    }

    private function accrued(): array
    {
        return ['CL' => [
            'allocated' => 12, 'used' => 2, 'carried' => 0, 'balance' => 10,
            'accruedToDate' => 6.0, 'lastAccrualMonth' => '2026-06',
        ]];
    }

    private function allocateWrite(): array
    {
        return ['CL' => ['allocated' => 12, 'used' => 3, 'carried' => 1, 'balance' => 10]];
    }

    // ── THE DEFECT ───────────────────────────────────────────────────────

    public function test_old_merge_destroyed_the_accrual_ledger(): void
    {
        $out = $this->oldMerge($this->accrued(), $this->allocateWrite());

        $this->assertArrayNotHasKey('accruedToDate', $out['CL'],
            'demonstrates the defect: accrual state was deleted');
        $this->assertArrayNotHasKey('lastAccrualMonth', $out['CL']);
    }

    public function test_field_merge_preserves_accrual_owned_keys(): void
    {
        $out = $this->merge($this->accrued(), $this->allocateWrite());

        $this->assertSame(6.0, $out['CL']['accruedToDate'], 'accrual ledger must survive');
        $this->assertSame('2026-06', $out['CL']['lastAccrualMonth'],
            'without this, accrual restarts and re-credits months already granted');
    }

    public function test_field_merge_still_applies_the_incoming_values(): void
    {
        $out = $this->merge($this->accrued(), $this->allocateWrite());

        $this->assertSame(3, $out['CL']['used'], 'the writer must still update what it owns');
        $this->assertSame(1, $out['CL']['carried']);
        $this->assertSame(10, $out['CL']['balance']);
        $this->assertSame(12, $out['CL']['allocated']);
    }

    // ── Symmetry: accrual must not clobber allocate either ───────────────

    public function test_accrual_write_preserves_allocate_owned_keys(): void
    {
        $existing = $this->accrued();
        $accrualWrite = ['CL' => ['accruedToDate' => 7.0, 'lastAccrualMonth' => '2026-07']];

        $out = $this->merge($existing, $accrualWrite);

        $this->assertSame(7.0, $out['CL']['accruedToDate']);
        $this->assertSame('2026-07', $out['CL']['lastAccrualMonth']);
        $this->assertSame(10, $out['CL']['balance'], 'allocate-owned keys must survive an accrual write');
        $this->assertSame(12, $out['CL']['allocated']);
    }

    // ── Untouched types and new types ────────────────────────────────────

    public function test_types_not_being_written_are_untouched(): void
    {
        $existing = $this->accrued();
        $existing['SL'] = ['allocated' => 6, 'used' => 0, 'carried' => 0, 'balance' => 6];

        $out = $this->merge($existing, $this->allocateWrite());

        $this->assertSame($existing['SL'], $out['SL'], 'an unrelated leave type must not change');
    }

    public function test_a_brand_new_type_is_added_verbatim(): void
    {
        $out = $this->merge([], $this->allocateWrite());

        $this->assertSame($this->allocateWrite()['CL'], $out['CL']);
        $this->assertArrayNotHasKey('accruedToDate', $out['CL'],
            'nothing to preserve when there was no prior entry');
    }

    public function test_malformed_prior_entry_is_replaced_not_merged(): void
    {
        $out = $this->merge(['CL' => 'corrupt'], $this->allocateWrite());

        $this->assertSame($this->allocateWrite()['CL'], $out['CL']);
    }

    // ── The double-credit scenario, end to end ───────────────────────────

    /**
     * Reproduces the real sequence: accrue six months, run allocate, accrue
     * again. Under the old merge the second accrual saw no lastAccrualMonth and
     * re-credited from January.
     */
    public function test_allocate_between_accruals_does_not_cause_double_credit(): void
    {
        $state = ['CL' => ['accruedToDate' => 6.0, 'lastAccrualMonth' => '2026-06']];

        $afterAllocate = $this->merge($state, $this->allocateWrite());

        $resumeFrom = $afterAllocate['CL']['lastAccrualMonth'] ?? '';
        $this->assertSame('2026-06', $resumeFrom,
            'accrual must resume from July, not restart from January');

        $oldState = $this->oldMerge($state, $this->allocateWrite());
        $this->assertSame('', $oldState['CL']['lastAccrualMonth'] ?? '',
            'the old behaviour lost the resume point — that is the double-credit');
    }

    // ── Source pin ───────────────────────────────────────────────────────

    public function test_shipped_writer_merges_per_field(): void
    {
        $src = file_get_contents(__DIR__ . '/../../application/controllers/Hr.php');
        $this->assertNotFalse($src);

        $this->assertStringContainsString(
            '$merged[$tid] = (is_array($prev) && is_array($bd)) ? array_merge($prev, $bd) : $bd;',
            $src
        );
        $this->assertStringNotContainsString(
            '$merged = array_merge($existing, $balances);',
            $src,
            'the type-level merge must not come back'
        );
    }

    /** The negative floors the writer applies must still be in place. */
    public function test_negative_floors_survive_the_change(): void
    {
        $src = file_get_contents(__DIR__ . '/../../application/controllers/Hr.php');
        $this->assertNotFalse($src);

        $this->assertStringContainsString("(float) \$bd['balance'] < 0", $src);
        $this->assertStringContainsString("(float) \$bd['used']    < 0", $src);
    }
}
