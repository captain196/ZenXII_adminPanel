<?php

use PHPUnit\Framework\TestCase;

if (!defined('BASEPATH')) define('BASEPATH', __DIR__);
if (!defined('APPPATH'))  define('APPPATH', __DIR__ . '/../../application/');

/**
 * PHASE 2 — identity-conflict detection.
 *
 * The guard must tell 0 / 1 / 2+ apart. The trap it has to survive: one
 * physical demand is indexed under BOTH the canonical and the name shape, so
 * counting matched KEYS would report two matches for a single demand and fail
 * closed on healthy data. The count is therefore of DEMAND DOCUMENT IDS,
 * unioned across both shapes.
 */
class Phase2IdentityConflictTest extends TestCase
{
    /** Existing side — mirrors production: key => set of demand doc ids. */
    private function haveKeys(array $demands): array
    {
        $k = [];
        foreach ($demands as $did => $d) {
            if (($d['status'] ?? '') === 'archived') continue;
            $pk = (string) ($d['periodKey'] ?? '');
            if ($pk === '') continue;
            $cid = (string) ($d['canonicalFeeHeadId'] ?? '');
            if ($cid !== '') $k[$pk . '|' . $cid][$did] = true;
            $nm = strtolower(trim((string) ($d['feeHead'] ?? '')));
            if ($nm !== '') $k[$pk . '|name:' . $nm][$did] = true;
        }
        return $k;
    }

    /** Generator side — returns 'create' | 'skip' | 'conflict'. */
    private function decide(array $have, string $pk, string $canonicalId, string $feeHead): string
    {
        $key  = $canonicalId !== '' ? ($pk . '|' . $canonicalId) : ($pk . '|name:' . strtolower(trim($feeHead)));
        $name = $pk . '|name:' . strtolower(trim($feeHead));
        $ids  = ($have[$key] ?? []) + ($have[$name] ?? []);
        $n    = count($ids);
        return $n >= 2 ? 'conflict' : ($n === 1 ? 'skip' : 'create');
    }

    private function d(string $pk, string $head, string $canon = '', string $status = 'unpaid', float $paid = 0.0): array
    {
        return ['periodKey' => $pk, 'feeHead' => $head, 'canonicalFeeHeadId' => $canon,
                'status' => $status, 'paidAmount' => $paid];
    }

    // ══ 1-3 · the 0 / 1 outcomes ══════════════════════════════════════════

    public function test_1_zero_matches_still_creates(): void
    {
        $this->assertSame('create', $this->decide($this->haveKeys([]), '2026-04', 'FH_TUI', 'Tuition Fee'));
    }

    public function test_2_one_canonical_match_skips(): void
    {
        $have = $this->haveKeys(['DEM_A' => $this->d('2026-04', 'Tuition Fee', 'FH_TUI')]);
        $this->assertSame('skip', $this->decide($have, '2026-04', 'FH_TUI', 'Tuition Fee'));
    }

    public function test_3_one_legacy_name_match_skips(): void
    {
        $have = $this->haveKeys(['RANDOM_A' => $this->d('2026-04', 'Tuition Fee', '')]);
        $this->assertSame('skip', $this->decide($have, '2026-04', 'FH_TUI', 'Tuition Fee'));
    }

    // ══ 4 · THE critical case ═════════════════════════════════════════════

    /**
     * ONE demand indexed under BOTH shapes must count as ONE occurrence.
     * Counting keys instead of documents would call this a conflict and break
     * every healthy generation.
     */
    public function test_4_one_demand_under_both_keys_is_one_occurrence_not_two(): void
    {
        $have = $this->haveKeys(['DEM_A' => $this->d('2026-04', 'Tuition Fee', 'FH_TUI')]);

        $this->assertArrayHasKey('2026-04|FH_TUI', $have);
        $this->assertArrayHasKey('2026-04|name:tuition fee', $have);
        $this->assertSame(['DEM_A' => true], $have['2026-04|FH_TUI']);
        $this->assertSame(['DEM_A' => true], $have['2026-04|name:tuition fee']);

        $this->assertSame('skip', $this->decide($have, '2026-04', 'FH_TUI', 'Tuition Fee'),
            'two matching KEYS but one matching DOCUMENT — this must not be a conflict');
    }

    // ══ 5-9 · conflicts ═══════════════════════════════════════════════════

    public function test_5_two_distinct_demands_fail_closed(): void
    {
        $have = $this->haveKeys([
            'RANDOM_A' => $this->d('2026-04', 'Tuition Fee', ''),
            'DEM_B'    => $this->d('2026-04', 'Tuition Fee', 'FH_TUI'),
        ]);
        $this->assertSame('conflict', $this->decide($have, '2026-04', 'FH_TUI', 'Tuition Fee'));
    }

    public function test_6_three_matching_demands_fail_closed(): void
    {
        $have = $this->haveKeys([
            'R1' => $this->d('2026-04', 'Tuition Fee', ''),
            'R2' => $this->d('2026-04', 'Tuition Fee', ''),
            'D3' => $this->d('2026-04', 'Tuition Fee', 'FH_TUI'),
        ]);
        $this->assertSame('conflict', $this->decide($have, '2026-04', 'FH_TUI', 'Tuition Fee'));
    }

    /** 7-9 — payment state never changes the verdict; a conflict is a conflict. */
    public function test_7_8_9_paid_partial_and_unpaid_duplicate_pairs_all_fail_closed(): void
    {
        foreach ([['paid', 1000.0], ['partial', 400.0], ['unpaid', 0.0]] as [$status, $paid]) {
            $have = $this->haveKeys([
                'A' => $this->d('2026-04', 'Tuition Fee', '', $status, $paid),
                'B' => $this->d('2026-04', 'Tuition Fee', 'FH_TUI', $status, $paid),
            ]);
            $this->assertSame('conflict', $this->decide($have, '2026-04', 'FH_TUI', 'Tuition Fee'),
                "a {$status} duplicate pair must refuse, never pick one");
        }
    }

    // ══ 10-11 · pre-existing rules preserved ══════════════════════════════

    public function test_10_archived_demands_remain_excluded(): void
    {
        $have = $this->haveKeys([
            'ARCH' => $this->d('2026-04', 'Tuition Fee', 'FH_TUI', 'archived'),
            'LIVE' => $this->d('2026-04', 'Tuition Fee', 'FH_TUI'),
        ]);
        $this->assertSame('skip', $this->decide($have, '2026-04', 'FH_TUI', 'Tuition Fee'),
            'the archived one must not count toward a conflict');
    }

    public function test_11_similar_but_distinct_names_stay_distinct(): void
    {
        $have = $this->haveKeys(['A' => $this->d('2026-04', 'Tuition Fee', '')]);
        foreach (['Late Tuition Fee', 'Tuition Fee 2', 'Tuition'] as $other) {
            $this->assertSame('create', $this->decide($have, '2026-04', 'FH_OTHER', $other));
        }
    }

    public function test_a_different_period_is_unaffected_by_a_conflict_elsewhere(): void
    {
        $have = $this->haveKeys([
            'A' => $this->d('2026-04', 'Tuition Fee', ''),
            'B' => $this->d('2026-04', 'Tuition Fee', 'FH_TUI'),
        ]);
        $this->assertSame('conflict', $this->decide($have, '2026-04', 'FH_TUI', 'Tuition Fee'));
        $this->assertSame('create',   $this->decide($have, '2026-05', 'FH_TUI', 'Tuition Fee'));
    }

    // ══ 12-15 · scope and invariants ══════════════════════════════════════

    public function test_12_13_14_untouched_paths_and_invariants(): void
    {
        $src = file_get_contents(APPPATH . 'controllers/Fees.php');

        // Transport / Hostel / ID-card are generated by Fee_lifecycle.
        $this->assertStringNotContainsString('TRANSPORT_FEE_TRANSPORT', $src);
        $this->assertStringNotContainsString('HOSTEL_FEE_HOSTEL', $src);

        // feeHeadRef never becomes demand-id material.
        $code = '';
        foreach (token_get_all($src) as $t) {
            $code .= is_array($t) ? (in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true) ? ' ' : $t[1]) : $t;
        }
        preg_match_all('/_(?:build)?[dD]emandId\s*\(([^;]*?)\)\s*;/s', $code, $m);
        foreach ($m[1] as $args) $this->assertStringNotContainsString('feeHeadRef', $args);
    }

    public function test_production_counts_documents_and_fails_closed(): void
    {
        $src = file_get_contents(APPPATH . 'controllers/Fees.php');
        // sets keyed by demand doc id, not booleans
        $this->assertStringContainsString('$haveKeys[$pk . \'|\' . $cid][$_did] = true;', $src);
        $this->assertStringContainsString('$haveKeys[$pk . \'|name:\' . $nm][$_did] = true;', $src);
        // union across both shapes, then count documents
        $this->assertStringContainsString('$matchIds = ($haveKeys[$dedupKey] ?? []) + ($haveKeys[$dedupKeyByName] ?? []);', $src);
        $this->assertStringContainsString('if ($matchCount >= 2) {', $src);
        $this->assertStringContainsString('ACC_FEE_DEMAND_IDENTITY_CONFLICT', $src);
    }

    /** Phase 3/4 must remain out of scope. */
    public function test_15_phase_2_did_not_stray_into_later_phases(): void
    {
        $src = file_get_contents(APPPATH . 'controllers/Fees.php');
        $this->assertStringContainsString('$this->fsTxn->newDemandId()', $src,
            'Phase 4 (deterministic ids / create-if-not-exists) is NOT part of Phase 2');
        $this->assertStringContainsString('_processGenerationJob', $src,
            'Phase 3 (save/generation separation) is NOT part of Phase 2');
    }
}
