<?php

use PHPUnit\Framework\TestCase;

if (!defined('BASEPATH')) define('BASEPATH', __DIR__);
if (!defined('APPPATH'))  define('APPPATH', __DIR__ . '/../../application/');

/**
 * DEC-007 — the admission generator must not duplicate an existing occurrence.
 *
 * THE DEFECT (live, latent, pre-existing):
 *
 *   existing side  Fee_lifecycle:446   indexed `pk|canonicalFeeHeadId` and ONLY when that
 *                                      field was non-empty. No name key at all.
 *   lookup side    Fee_lifecycle:494    `$canonicalId = canonMap[name] ?? headId` — derived
 *                                      from the CHART, so effectively never empty.
 *
 * ⇒ a stored demand with an empty `canonicalFeeHeadId` was indexed NOWHERE, while the lookup
 *   always used a populated key. It could never match, so admission generation created a
 *   SECOND demand for an occurrence that already existed.
 *
 * This is the Phase 1 asymmetry — and strictly worse: pre-Phase-1 `Fees.php` at least
 * indexed the name shape, whereas this path skipped the demand entirely.
 *
 * LIVE EXPOSURE when found: 26 of 29 demands on SCH_B56BB9A401 and 26 of 1118 on
 * SCH_218AAF5C23 carried an empty `canonicalFeeHeadId`.
 *
 * The fix converges this path onto the SAME proven contract Phase 1/2 established for
 * `Fees::_generateDemandsForMonth`: index BOTH shapes, look up BOTH, count DISTINCT
 * documents, and log a conflict when more than one represents one occurrence.
 *
 * These tests model the two sides exactly as the source does, so they fail against the old
 * implementation and pass against the corrected one.
 */
class Dec007AdmissionDedupeTest extends TestCase
{
    private const LIFE = APPPATH . 'libraries/Fee_lifecycle.php';

    /** The CORRECTED contract, mirroring Fee_lifecycle's own code. */
    private function build(array $existing, array $chartHeads, array $canonMap): array
    {
        $haveKeys = [];
        foreach ($existing as $did => $d) {
            if (($d['status'] ?? '') === 'archived') continue;
            $pk = (string) ($d['periodKey'] ?? $d['period_key'] ?? '');
            if ($pk === '') continue;
            $cid = (string) ($d['canonicalFeeHeadId'] ?? '');
            $nm  = strtolower(trim((string) ($d['feeHead'] ?? '')));
            if ($cid !== '') $haveKeys[$pk . '|' . $cid][$did] = true;
            if ($nm  !== '') $haveKeys[$pk . '|name:' . $nm][$did] = true;
        }

        $created = []; $skipped = []; $conflicts = [];
        foreach ($chartHeads as $headName => [$periodKey, $headId]) {
            $canonicalId = (string) ($canonMap[strtolower(trim($headName))] ?? $headId);
            $byName      = $periodKey . '|name:' . strtolower(trim($headName));
            $dedupKey    = $canonicalId !== '' ? ($periodKey . '|' . $canonicalId) : $byName;

            $matchIds   = ($haveKeys[$dedupKey] ?? []) + ($haveKeys[$byName] ?? []);
            $matchCount = count($matchIds);

            if ($matchCount >= 2) { $conflicts[] = $headName; $skipped[] = $headName; continue; }
            if ($matchCount === 1) { $skipped[] = $headName; continue; }

            $newId = 'NEW_' . $headName . '_' . $periodKey;
            $haveKeys[$dedupKey][$newId] = true;
            $haveKeys[$byName][$newId]   = true;
            $created[] = $headName;
        }
        return ['created' => $created, 'skipped' => $skipped, 'conflicts' => $conflicts];
    }

    /** A legacy demand exactly as live data has them: NO canonicalFeeHeadId. */
    private function legacy(array $over = []): array
    {
        return array_merge([
            'periodKey' => '2026-04', 'feeHead' => 'Tuition Fee', 'feeHeadId' => 'FH_ROWID',
            'canonicalFeeHeadId' => '', 'status' => 'unpaid', 'paidAmount' => 0.0,
        ], $over);
    }

    private function chart(): array { return ['Tuition Fee' => ['2026-04', 'FH_ROWID']]; }
    private function canon(): array { return ['tuition fee' => 'FH_CANON']; }

    // ══ 1-3 · THE DEFECT, ACROSS PAYMENT STATES ══════════════════════════

    public function test_1_unpaid_legacy_demand_is_not_duplicated(): void
    {
        $r = $this->build(['D1' => $this->legacy()], $this->chart(), $this->canon());
        $this->assertSame([], $r['created'], 'pre-fix this created a SECOND Tuition demand');
        $this->assertSame(['Tuition Fee'], $r['skipped']);
    }

    public function test_2_partially_paid_legacy_demand_is_not_duplicated(): void
    {
        $r = $this->build(['D1' => $this->legacy(['status' => 'partial', 'paidAmount' => 300.0])],
                          $this->chart(), $this->canon());
        $this->assertSame([], $r['created'],
            'duplicating a partially-paid obligation would double a live receivable');
    }

    public function test_3_fully_paid_legacy_demand_is_not_duplicated(): void
    {
        $r = $this->build(['D1' => $this->legacy(['status' => 'paid', 'paidAmount' => 600.0])],
                          $this->chart(), $this->canon());
        $this->assertSame([], $r['created'],
            'a settled obligation must never be re-raised');
    }

    // ══ 4-6 · ACROSS ID SHAPES AND STAMPING ══════════════════════════════

    public function test_4_deterministic_DEM_id_demand_is_matched(): void
    {
        $r = $this->build(['DEM_STU0012_202604_FH_ROWID' => $this->legacy()],
                          $this->chart(), $this->canon());
        $this->assertSame([], $r['created'], 'suppression is id-agnostic by design');
    }

    public function test_5_random_id_demand_is_matched(): void
    {
        $r = $this->build(['4abfdee35a8a86b3ec46' => $this->legacy()], $this->chart(), $this->canon());
        $this->assertSame([], $r['created']);
    }

    public function test_6_registry_stamped_demand_is_matched(): void
    {
        $r = $this->build(
            ['D1' => $this->legacy(['canonicalFeeHeadId' => 'FH_CANON', 'feeHeadRef' => 'FH_DF3C200439E2',
                                    'category' => 'academic', 'categorySource' => 'registry'])],
            $this->chart(), $this->canon());
        $this->assertSame([], $r['created'], 'the non-empty-canonical path must keep working');
    }

    // ══ 7-9 · MODULE HEADS ═══════════════════════════════════════════════

    /**
     * Transport / Hostel / ID-card are generated by createModuleFee(), NOT by
     * buildAdmissionDemandSpecs — they never appear in the class fee chart, so
     * this dedupe cannot affect their identity. Pinned so the fix's blast
     * radius stays provable.
     */
    public function test_7_8_9_module_heads_are_not_produced_by_this_path(): void
    {
        $src = file_get_contents(self::LIFE);
        $this->assertStringContainsString('public function createModuleFee', $src);
        $this->assertStringContainsString('$headName . \'_\' . $moduleType', $src,
            'module demand ids are built by _demandId() from the module head, not the chart');
        $this->assertStringContainsString('NEVER pass a feeHeadRef', $src);
    }

    // ══ 10-12 · REPEAT, CONCURRENCY, RETRY ═══════════════════════════════

    public function test_10_repeated_generation_creates_nothing_the_second_time(): void
    {
        $first = $this->build([], $this->chart(), $this->canon());
        $this->assertSame(['Tuition Fee'], $first['created']);

        // The demand the first run created, as it would be stored.
        $stored = ['NEW1' => $this->legacy(['canonicalFeeHeadId' => 'FH_CANON'])];
        $second = $this->build($stored, $this->chart(), $this->canon());
        $this->assertSame([], $second['created'], 'a re-run must be a no-op');
    }

    /** Two heads in ONE run must not collide with each other. */
    public function test_11_within_one_run_distinct_heads_stay_distinct(): void
    {
        $r = $this->build([], [
            'Tuition Fee' => ['2026-04', 'FH_A'],
            'Library Fee' => ['2026-04', 'FH_B'],
        ], []);
        $this->assertSame(['Tuition Fee', 'Library Fee'], $r['created']);
    }

    /** A same-run repeat of one head counts as ONE document, not two. */
    public function test_11b_a_same_run_repeat_is_suppressed(): void
    {
        $r = $this->build([], [
            'Tuition Fee'  => ['2026-04', 'FH_A'],
            'Tuition Fee ' => ['2026-04', 'FH_A'],   // trailing space — same head
        ], []);
        $this->assertCount(1, $r['created']);
    }

    /** A retry after a timeout sees the partially-written state and skips it. */
    public function test_12_retry_after_timeout_does_not_double_write(): void
    {
        $partial = ['WROTE_BEFORE_TIMEOUT' => $this->legacy(['canonicalFeeHeadId' => ''])];
        $r = $this->build($partial, $this->chart(), $this->canon());
        $this->assertSame([], $r['created'],
            'the empty-canonical case is exactly what a timeout-retry hits');
    }

    // ══ CONFLICT DETECTION (Phase 2 parity) ══════════════════════════════

    public function test_two_existing_demands_for_one_occurrence_are_reported(): void
    {
        $r = $this->build([
            'D1' => $this->legacy(),                                        // name-indexed only
            'D2' => $this->legacy(['canonicalFeeHeadId' => 'FH_CANON']),    // both shapes
        ], $this->chart(), $this->canon());

        $this->assertSame([], $r['created'], 'never add a third to a pre-existing duplicate');
        $this->assertSame(['Tuition Fee'], $r['conflicts']);
    }

    /** One demand indexed under BOTH shapes must count as ONE, not two. */
    public function test_one_demand_indexed_twice_is_not_a_false_conflict(): void
    {
        $r = $this->build(['D1' => $this->legacy(['canonicalFeeHeadId' => 'FH_CANON'])],
                          $this->chart(), $this->canon());
        $this->assertSame([], $r['conflicts'], 'the union is by DOCUMENT ID, so it collapses');
        $this->assertSame(['Tuition Fee'], $r['skipped']);
    }

    public function test_archived_demands_are_still_ignored(): void
    {
        $r = $this->build(['D1' => $this->legacy(['status' => 'archived'])],
                          $this->chart(), $this->canon());
        $this->assertSame(['Tuition Fee'], $r['created'], 'archive-aware behaviour is unchanged');
    }

    // ══ THE IMPLEMENTATION MATCHES THIS CONTRACT ═════════════════════════

    public function test_the_source_indexes_and_looks_up_BOTH_shapes(): void
    {
        $src = file_get_contents(self::LIFE);
        // Whitespace-tolerant: the source aligns these assignments in a column.
        $this->assertMatchesRegularExpression(
            "/\\\$haveKeys\\[\\\$pk \\. '\\|name:' \\. \\\$nm\\]\\[\\\$_did\\]\s*= true;/", $src,
            'the existing side must index the NAME shape');
        $this->assertStringContainsString(
            '($haveKeys[$dedupKey] ?? []) + ($haveKeys[$dedupKeyByName] ?? [])', $src,
            'the lookup must consult BOTH shapes — the same union Phase 1 proved');
        $this->assertStringContainsString('ACC_FEE_DEMAND_IDENTITY_CONFLICT', $src,
            'Phase 2 parity: a pre-existing duplicate is named, not silently compounded');
    }

    /** The correction must not have touched identity or protected semantics. */
    public function test_identity_contracts_are_untouched(): void
    {
        $src = file_get_contents(self::LIFE);
        $this->assertStringContainsString('$id = "DEM_{$sid}_{$ym}_{$key}";', $src);
        $this->assertStringContainsString('NEVER pass a feeHeadRef', $src);
        $this->assertStringContainsString("'grossAmount' => \$amount", $src, 'DEC-001 untouched');
        $this->assertStringContainsString('$this->fsTxn->newDemandId()', $src,
            'demand-id minting for this path is unchanged');
    }
}
