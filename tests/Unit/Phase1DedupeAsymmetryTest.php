<?php

use PHPUnit\Framework\TestCase;

if (!defined('BASEPATH')) define('BASEPATH', __DIR__);
if (!defined('APPPATH'))  define('APPPATH', __DIR__ . '/../../application/');

/**
 * PHASE 1 — the asymmetric-lookup defect that duplicated financial demands.
 *
 * Live evidence: one fee-chart save took a student from 26 demands to 63, a
 * second save to 100 — 37 duplicates each time, every one a real obligation.
 *
 * The existing-demand side indexes BOTH `pk|canonicalFeeHeadId` (when set) and
 * always `pk|name:<lower head>`. The generator side picked ONE key from its own
 * canonical availability — always the canonical one, since the chart supplies
 * it — so a legacy demand indexed only under the name key was invisible.
 *
 * These tests model both sides exactly as production builds them.
 */
class Phase1DedupeAsymmetryTest extends TestCase
{
    /** Existing side — mirrors _generateDemandsForMonth()'s $haveKeys builder. */
    private function haveKeys(array $demands): array
    {
        $k = [];
        foreach ($demands as $d) {
            if (($d['status'] ?? '') === 'archived') continue;
            $pk = (string) ($d['periodKey'] ?? '');
            if ($pk === '') continue;
            $cid = (string) ($d['canonicalFeeHeadId'] ?? '');
            if ($cid !== '') $k[$pk . '|' . $cid] = true;
            $nm = strtolower(trim((string) ($d['feeHead'] ?? '')));
            if ($nm !== '') $k[$pk . '|name:' . $nm] = true;
        }
        return $k;
    }

    /** Generator side — PRE-fix: one key only. */
    private function skipsBefore(array $have, string $pk, string $canonicalId, string $feeHead): bool
    {
        $key = $canonicalId !== '' ? ($pk . '|' . $canonicalId) : ($pk . '|name:' . strtolower(trim($feeHead)));
        return isset($have[$key]);
    }

    /** Generator side — POST-fix: both shapes. */
    private function skipsAfter(array $have, string $pk, string $canonicalId, string $feeHead): bool
    {
        $key  = $canonicalId !== '' ? ($pk . '|' . $canonicalId) : ($pk . '|name:' . strtolower(trim($feeHead)));
        $name = $pk . '|name:' . strtolower(trim($feeHead));
        return isset($have[$key]) || isset($have[$name]);
    }

    private function legacy(string $pk, string $head, string $status = 'unpaid', float $paid = 0.0): array
    {
        return ['periodKey' => $pk, 'feeHead' => $head, 'canonicalFeeHeadId' => '',
                'status' => $status, 'paidAmount' => $paid];
    }

    // ══ 1 · the incident itself ═══════════════════════════════════════════

    public function test_1_legacy_demand_is_now_recognised_by_the_name_key(): void
    {
        $have = $this->haveKeys([$this->legacy('2026-04', 'Tuition Fee')]);

        $this->assertFalse($this->skipsBefore($have, '2026-04', 'FH_3F2B4F85161B', 'Tuition Fee'),
            'pre-fix: the legacy demand was invisible — this is what created the duplicate');
        $this->assertTrue($this->skipsAfter($have, '2026-04', 'FH_3F2B4F85161B', 'Tuition Fee'),
            'post-fix: the same occurrence is recognised, so no second demand is created');
    }

    /** The exact live scenario: 37 heads×months, none may regenerate. */
    public function test_the_incident_scenario_produces_zero_new_demands(): void
    {
        $months = ['2026-04','2026-05','2026-06','2026-07','2026-08','2026-09',
                   '2026-10','2026-11','2026-12','2027-01','2027-02','2027-03'];
        $heads  = ['Tuition Fee' => 'FH_3F2B4F85161B', 'Library Fee' => 'FH_46E8A3E1FF32',
                   'Transport Fee' => 'FH_82F29A9E7AEA'];
        $existing = [];
        foreach ($months as $m) foreach (array_keys($heads) as $h) $existing[] = $this->legacy($m, $h);
        $existing[] = $this->legacy('2026-04', 'Admission Fee');          // the yearly head
        $have = $this->haveKeys($existing);

        $created = 0;
        foreach ($months as $m) {
            foreach ($heads as $h => $canon) if (!$this->skipsAfter($have, $m, $canon, $h)) $created++;
        }
        if (!$this->skipsAfter($have, '2026-04', 'FH_835B48FC992B', 'Admission Fee')) $created++;

        $this->assertSame(0, $created, 'a re-run must create nothing — this is the 37-duplicate bug');
    }

    // ══ 2-3 · canonical key and both keys ═════════════════════════════════

    public function test_2_deterministic_demand_is_recognised_by_the_canonical_key(): void
    {
        $have = $this->haveKeys([['periodKey' => '2026-04', 'feeHead' => 'Tuition Fee',
                                  'canonicalFeeHeadId' => 'FH_3F2B4F85161B', 'status' => 'unpaid']]);
        $this->assertTrue($this->skipsAfter($have, '2026-04', 'FH_3F2B4F85161B', 'Tuition Fee'));
    }

    public function test_3_both_keys_present_still_yields_no_duplicate(): void
    {
        $have = $this->haveKeys([['periodKey' => '2026-04', 'feeHead' => 'Tuition Fee',
                                  'canonicalFeeHeadId' => 'FH_3F2B4F85161B', 'status' => 'unpaid']]);
        $this->assertArrayHasKey('2026-04|FH_3F2B4F85161B', $have);
        $this->assertArrayHasKey('2026-04|name:tuition fee', $have);
        $this->assertTrue($this->skipsAfter($have, '2026-04', 'FH_3F2B4F85161B', 'Tuition Fee'));
    }

    // ══ 4-5 · the fix must not over-match ═════════════════════════════════

    public function test_4_a_different_fee_head_is_not_deduped(): void
    {
        $have = $this->haveKeys([$this->legacy('2026-04', 'Tuition Fee')]);
        $this->assertFalse($this->skipsAfter($have, '2026-04', 'FH_46E8A3E1FF32', 'Library Fee'),
            'a legitimately different charge must still be created');
    }

    public function test_5_a_different_period_is_not_deduped(): void
    {
        $have = $this->haveKeys([$this->legacy('2026-04', 'Tuition Fee')]);
        $this->assertFalse($this->skipsAfter($have, '2026-05', 'FH_3F2B4F85161B', 'Tuition Fee'),
            'May is a different billing occurrence from April');
    }

    public function test_similar_but_distinct_head_names_do_not_collapse(): void
    {
        $have = $this->haveKeys([$this->legacy('2026-04', 'Tuition Fee')]);
        foreach (['Tuition Fee 2', 'Late Tuition Fee', 'Tuition'] as $other) {
            $this->assertFalse($this->skipsAfter($have, '2026-04', 'FH_OTHER', $other),
                "'{$other}' is a different head and must not be swallowed");
        }
    }

    // ══ 6-8 · payment state must always block creation ════════════════════

    /** A paid demand blocking regeneration is the whole point of BUG-075. */
    public function test_6_7_8_paid_partial_and_unpaid_all_block_creation(): void
    {
        foreach ([['paid', 1000.0], ['partial', 400.0], ['unpaid', 0.0]] as [$status, $paid]) {
            $have = $this->haveKeys([$this->legacy('2026-04', 'Tuition Fee', $status, $paid)]);
            $this->assertTrue($this->skipsAfter($have, '2026-04', 'FH_3F2B4F85161B', 'Tuition Fee'),
                "a {$status} demand must prevent a second demand for the same occurrence");
        }
    }

    public function test_archived_demands_are_still_excluded_from_the_guard(): void
    {
        $have = $this->haveKeys([$this->legacy('2026-04', 'Tuition Fee', 'archived')]);
        $this->assertFalse($this->skipsAfter($have, '2026-04', 'FH_3F2B4F85161B', 'Tuition Fee'),
            'archive-aware behaviour is pre-existing and must be preserved');
    }

    // ══ 9-12 · nothing else may move ══════════════════════════════════════

    public function test_9_10_11_12_untouched_paths_and_invariants(): void
    {
        $src = file_get_contents(APPPATH . 'controllers/Fees.php');

        // Transport / Hostel / ID-card are generated by Fee_lifecycle, not by
        // this method — Phase 1 cannot reach them.
        $this->assertStringNotContainsString('TRANSPORT_FEE_TRANSPORT', $src);
        $this->assertStringNotContainsString('HOSTEL_FEE_HOSTEL', $src);

        // chargeInstance is untouched by Phase 1.
        $this->assertSame(0, substr_count($src, "\$dedupKey . '|' . \$chargeInstance"));

        // feeHeadRef never becomes demand-id material.
        $code = '';
        foreach (token_get_all($src) as $t) {
            $code .= is_array($t) ? (in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true) ? ' ' : $t[1]) : $t;
        }
        preg_match_all('/_(?:build)?[dD]emandId\s*\(([^;]*?)\)\s*;/s', $code, $m);
        foreach ($m[1] as $args) $this->assertStringNotContainsString('feeHeadRef', $args);
    }

    /** The production lookup must consult BOTH shapes — the Phase 1 contract. */
    public function test_the_production_lookup_checks_both_key_shapes(): void
    {
        $src = file_get_contents(APPPATH . 'controllers/Fees.php');
        // UPDATED for Phase 2: the boolean `isset(A) || isset(B)` form was
        // superseded by a UNION OF DEMAND DOCUMENT IDS across both shapes —
        // strictly stronger, because it still consults both keys AND can now
        // tell 1 match from 2+. The Phase 1 contract (both shapes consulted)
        // is preserved, and is what this asserts.
        $this->assertStringContainsString(
            '($haveKeys[$dedupKey] ?? []) + ($haveKeys[$dedupKeyByName] ?? [])', $src,
            'both key shapes must still be consulted');
        $this->assertStringContainsString('$haveKeys[$dedupKeyByName][$demandId] = true;', $src,
            'the run-guard must still register both shapes');
    }

    /** Phase 2+ scope must remain untouched. */
    public function test_phase_1_did_not_stray_into_later_phases(): void
    {
        $src = file_get_contents(APPPATH . 'controllers/Fees.php');
        $this->assertStringContainsString('$this->fsTxn->newDemandId()', $src,
            'Phase 4 (deterministic ids) is NOT part of Phase 1');
        // Phase 2 has since landed, so the conflict code is now expected here.
        // Phase 4 (deterministic ids) remains the boundary this test guards.
        $this->assertStringContainsString('ACC_FEE_DEMAND_IDENTITY_CONFLICT', $src,
            'Phase 2 landed after Phase 1 and is now part of the contract');
    }
}
