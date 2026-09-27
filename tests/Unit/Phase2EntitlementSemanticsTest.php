<?php
namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * ══════════════════════════════════════════════════════════════════════════
 * PHASE 2 — F1 / F2 / F3 entitlement semantics
 * ══════════════════════════════════════════════════════════════════════════
 *
 * Goal, from the phase brief: "No entitlement/concession can be silently lost,
 * silently ignored, or silently produce an unintended monetary result."
 *
 * ── WHAT EXECUTES HERE ──────────────────────────────────────────────────────
 * The REAL production classes, unmodified and un-mocked:
 *   • Fee_concession_reader::appliesTo()          — legacy matcher
 *   • Fee_entitlement_reader::appliesTo()         — new matcher (incl. I3)
 *   • Fee_generation_service::_applyDeductions()  — the single deduction
 *     algorithm both sides share, reached by reflection so the arithmetic
 *     under test is the SHIPPED arithmetic. It is never re-implemented here.
 *   • Entitlement_service::VALUE_TYPES            — the accepted vocabulary
 *
 * Only the INPUT records are authored by this file. Every applies / type /
 * value / arithmetic decision belongs to production code, so a failure here is
 * a property of the shipped system.
 *
 * ── NO I/O ──────────────────────────────────────────────────────────────────
 * No Firestore, no network, no tenant. Nothing can be written.
 */
class Phase2EntitlementSemanticsTest extends TestCase
{
    private static $legacy;
    private static $newRdr;
    private static $gen;
    private static $apply;

    /** Head ids are minted as FH_ + 12 hex (Fee_firestore_sync:148). */
    private const HEAD_TUITION_ID = 'FH_A1B2C3D4E5F6';
    private const HEAD_LAB_ID     = 'FH_0A0B0C0D0E0F';

    public static function setUpBeforeClass(): void
    {
        if (!defined('BASEPATH')) define('BASEPATH', __DIR__);
        if (!class_exists('CI_Controller', false)) {
            eval('class CI_Controller { public static $instance; }');
        }
        if (!function_exists('get_instance')) {
            eval('function &get_instance() { return \CI_Controller::$instance; }');
        }
        foreach (['Fee_concession_reader', 'Fee_entitlement_reader', 'Fee_generation_service', 'Entitlement_service'] as $c) {
            if (!class_exists($c, false)) require_once __DIR__ . '/../../application/libraries/' . $c . '.php';
        }

        self::$legacy = new \Fee_concession_reader();
        self::$newRdr = new \Fee_entitlement_reader();
        self::$gen    = (new \ReflectionClass('Fee_generation_service'))->newInstanceWithoutConstructor();
        self::$apply  = new \ReflectionMethod('Fee_generation_service', '_applyDeductions');
        self::$apply->setAccessible(true);
    }

    // ── fixture builders ─────────────────────────────────────────────────

    private function head(string $id, string $name, string $category, float $amount): array
    {
        return ['id' => $id, 'name' => $name, 'category' => $category, 'amount' => $amount];
    }

    private function specs(array $heads, array $periods = ['2026-04']): array
    {
        $out = [];
        foreach ($heads as $h) {
            foreach ($periods as $p) {
                $out[] = ['demandId' => "D_{$h['name']}_{$p}", 'data' => [
                    'periodKey'   => $p,
                    'feeHeadId'   => $h['id'],
                    'feeHead'     => $h['name'],
                    'category'    => $h['category'],
                    'grossAmount' => $h['amount'],
                    'netAmount'   => $h['amount'],
                    'balance'     => $h['amount'],
                    'status'      => 'unpaid',
                ]];
            }
        }
        return ['specs' => $out, 'assigned' => array_column($heads, 'name'), 'chartFound' => true];
    }

    /** Run the REAL deduction algorithm over the NEW reader. */
    private function netsNew(array $built, array $ents): array
    {
        $r = self::$apply->invoke(self::$gen, $built, $ents,
            fn(array $e, string $p, string $hId, string $h, string $c): bool => self::$newRdr->appliesTo($e, $p, $hId, $h, $c),
            fn(array $e): string => self::$newRdr->deductionType($e),
            fn(array $e): float  => self::$newRdr->deductionValue($e)
        );
        return $this->nets($r);
    }

    /** Run the REAL deduction algorithm over the LEGACY reader. */
    private function netsLegacy(array $built, array $concessions): array
    {
        $r = self::$apply->invoke(self::$gen, $built, $concessions,
            fn(array $c, string $p, string $hId, string $h, string $cat): bool => self::$legacy->appliesTo($c, $p, $hId, $h, $cat),
            fn(array $c): string => (string) ($c['type']  ?? ''),
            fn(array $c): float  => (float)  ($c['value'] ?? 0)
        );
        return $this->nets($r);
    }

    private function nets(array $built): array
    {
        $m = [];
        foreach ($built['specs'] as $s) $m[$s['demandId']] = round((float) ($s['data']['netAmount'] ?? 0), 2);
        ksort($m);
        return $m;
    }

    private function ent(array $over = []): array
    {
        return array_merge([
            'studentId' => 'STU_T', 'schoolId' => 'SCH_T', 'session' => '2026-27',
            'kind' => 'concession', 'scope' => 'all', 'valueType' => 'percent', 'value' => 25,
            'effectiveFrom' => '2026-04-01', 'effectiveTo' => null, 'status' => 'active',
        ], $over);
    }

    private function conc(array $over = []): array
    {
        return array_merge([
            'studentId' => 'STU_T', 'schoolId' => 'SCH_T', 'session' => '2026-27',
            'scope' => 'all', 'type' => 'percent', 'value' => 25,
            'effectiveFrom' => '2026-04-01', 'effectiveTo' => null, 'status' => 'active',
        ], $over);
    }

    /** The migration's scope mapping, executed FOR REAL through node. */
    private function migrateScope(array $legacyDoc): ?string
    {
        $probe = realpath(__DIR__ . '/../Support/map_scope_probe.js');
        $this->assertNotFalse($probe, 'map_scope_probe.js not found');

        $cmd = 'node ' . escapeshellarg($probe) . ' '
             . escapeshellarg(base64_encode(json_encode($legacyDoc))) . ' 2>&1';
        $out = trim((string) shell_exec($cmd));

        if ($out === '') {
            $this->markTestSkipped('node is unavailable — the migration mapper could not be executed.');
        }
        $v = json_decode($out, true);
        if (is_array($v) && isset($v['probeError'])) {
            $this->fail('migration mapScope() could not be executed: ' . $v['probeError']);
        }
        if ($v === null && $out !== 'null') {
            $this->fail("migration mapScope() is not callable — the script must export it.\n{$out}");
        }
        return $v === null ? null : (string) $v;
    }

    // ══════════════════════════════════════════════════════════════════
    //  F1 — head-scoped mapping must not lose the fee-head name
    // ══════════════════════════════════════════════════════════════════

    /** F1-01 — a stable id maps and bills identically on both sides. */
    public function test_F1_01_head_with_stable_id_bills_identically(): void
    {
        $built = $this->specs([$this->head(self::HEAD_TUITION_ID, 'Tuition Fee', '', 1000.0)]);
        $legacy = $this->netsLegacy($built, [$this->conc([
            'scope' => 'head', 'type' => 'percent', 'value' => 50,
            'targetFeeHeadId' => self::HEAD_TUITION_ID, 'targetFeeHead' => 'Tuition Fee',
        ])]);
        $scope = $this->migrateScope([
            'scope' => 'head', 'targetFeeHeadId' => self::HEAD_TUITION_ID, 'targetFeeHead' => 'Tuition Fee',
        ]);
        $this->assertSame('head:' . self::HEAD_TUITION_ID, $scope);
        $new = $this->netsNew($built, [$this->ent(['scope' => $scope, 'valueType' => 'percent', 'value' => 50])]);

        $this->assertSame(['D_Tuition Fee_2026-04' => 500.0], $legacy);
        $this->assertSame($legacy, $new, 'A stable-id head concession must bill identically.');
    }

    /**
     * F1-02 — THE DEFECT. A legacy head concession with an EMPTY id but a valid
     * head NAME applies in legacy via its name fallback (Fee_concession_reader:73),
     * and must not be discarded by the migration.
     */
    public function test_F1_02_head_with_empty_id_but_valid_name_survives(): void
    {
        $built = $this->specs([$this->head(self::HEAD_LAB_ID, 'Lab Fee', '', 400.0)]);
        $legacyRule = $this->conc([
            'scope' => 'head', 'type' => 'percent', 'value' => 50,
            'targetFeeHeadId' => '', 'targetFeeHead' => 'Lab Fee',
        ]);
        $legacy = $this->netsLegacy($built, [$legacyRule]);
        $this->assertSame(['D_Lab Fee_2026-04' => 200.0], $legacy, 'Legacy applies this by NAME.');

        $scope = $this->migrateScope(['scope' => 'head', 'targetFeeHeadId' => '', 'targetFeeHead' => 'Lab Fee']);
        $this->assertNotNull($scope,
            'F1: the migration must NOT discard a head concession that legacy honours by name — '
            . 'the student would silently be billed MORE.');
        $this->assertSame('head:Lab Fee', $scope);

        $new = $this->netsNew($built, [$this->ent(['scope' => $scope, 'valueType' => 'percent', 'value' => 50])]);
        $this->assertSame($legacy, $new, 'Same money on both sides.');
    }

    /**
     * F1-03 — matching must be deterministic, never ambiguous. The scope token
     * matches a head id OR a head name; it must match NEITHER when it is simply
     * wrong, and a name-token must not leak onto a different head.
     */
    public function test_F1_03_wrong_target_matches_nothing(): void
    {
        $built = $this->specs([
            $this->head(self::HEAD_TUITION_ID, 'Tuition Fee', '', 1000.0),
            $this->head(self::HEAD_LAB_ID,     'Lab Fee',     '', 400.0),
        ]);

        $wrongId = $this->netsNew($built, [$this->ent(['scope' => 'head:FH_DEADBEEFDEAD', 'value' => 50])]);
        $this->assertSame(['D_Lab Fee_2026-04' => 400.0, 'D_Tuition Fee_2026-04' => 1000.0], $wrongId,
            'An unknown id must discount nothing.');

        $byName = $this->netsNew($built, [$this->ent(['scope' => 'head:Lab Fee', 'valueType' => 'percent', 'value' => 50])]);
        $this->assertSame(['D_Lab Fee_2026-04' => 200.0, 'D_Tuition Fee_2026-04' => 1000.0], $byName,
            'A name token must hit exactly its own head and no other.');
    }

    /** F1-04 — an unknown head fails CLOSED (no discount), never open. */
    public function test_F1_04_unknown_head_fails_closed(): void
    {
        $built = $this->specs([$this->head(self::HEAD_TUITION_ID, 'Tuition Fee', '', 1000.0)]);
        $out = $this->netsNew($built, [$this->ent(['scope' => 'head:No Such Head', 'value' => 100])]);
        $this->assertSame(['D_Tuition Fee_2026-04' => 1000.0], $out,
            'Fail closed: an unresolvable head must never produce a discount.');
    }

    /** F1-05 — the migration discards a head concession ONLY when it truly cannot be resolved. */
    public function test_F1_05_migration_discards_only_the_unresolvable(): void
    {
        $this->assertSame('head:' . self::HEAD_LAB_ID,
            $this->migrateScope(['scope' => 'head', 'targetFeeHeadId' => self::HEAD_LAB_ID, 'targetFeeHead' => 'Lab Fee']));
        $this->assertSame('head:Lab Fee',
            $this->migrateScope(['scope' => 'head', 'targetFeeHeadId' => '', 'targetFeeHead' => 'Lab Fee']));
        $this->assertNull(
            $this->migrateScope(['scope' => 'head', 'targetFeeHeadId' => '', 'targetFeeHead' => '']),
            'With neither id nor name there is nothing to resolve — skipping is correct.');
        $this->assertSame('all', $this->migrateScope(['scope' => 'all']));
        $this->assertSame('category:transport', $this->migrateScope(['scope' => 'category', 'targetCategory' => 'transport']));
    }

    // ══════════════════════════════════════════════════════════════════
    //  F2 — blanket concessions vs service heads (invariant I3)
    // ══════════════════════════════════════════════════════════════════

    /** F2-01 — a blanket concession DOES apply to academic/tuition heads. */
    public function test_F2_01_blanket_applies_to_academic(): void
    {
        $built = $this->specs([$this->head(self::HEAD_TUITION_ID, 'Tuition Fee', 'academic', 1000.0)]);
        $this->assertSame(['D_Tuition Fee_2026-04' => 800.0],
            $this->netsNew($built, [$this->ent(['scope' => 'all', 'value' => 20])]));
    }

    /**
     * F2-02/03/04 — the canonical rule: a blanket concession NEVER nets against
     * a separately-enrolled service head.
     */
    public function servicePairs(): array
    {
        return [
            'transport' => ['transport', 'Bus Fee',    800.0],
            'hostel'    => ['hostel',    'Hostel Fee', 5000.0],
            'meals'     => ['meals',     'Mess Fee',   1200.0],
        ];
    }

    /** @dataProvider servicePairs */
    public function test_F2_02_03_04_blanket_never_discounts_a_service_head(string $cat, string $name, float $amt): void
    {
        $built = $this->specs([$this->head('FH_SVC000000001', $name, $cat, $amt)]);
        $this->assertSame(["D_{$name}_2026-04" => $amt],
            $this->netsNew($built, [$this->ent(['scope' => 'all', 'value' => 20])]),
            "Canonical rule: a blanket concession must not discount the {$cat} head.");
    }

    /** F2-05 — an EXPLICITLY service-targeted concession does apply. */
    public function test_F2_05_explicit_service_scope_applies(): void
    {
        $built = $this->specs([$this->head('FH_SVC000000001', 'Bus Fee', 'transport', 800.0)]);
        $this->assertSame(['D_Bus Fee_2026-04' => 600.0],
            $this->netsNew($built, [$this->ent(['scope' => 'category:transport', 'value' => 25])]),
            'A deliberately service-scoped concession is the sanctioned way to discount a service.');
        $this->assertSame(['D_Bus Fee_2026-04' => 400.0],
            $this->netsNew($built, [$this->ent(['scope' => 'head:Bus Fee', 'value' => 50])]),
            'A head-scoped concession on the service head also applies.');
    }

    /** F2-06 — ordinary academic concessions are completely unaffected by I3. */
    public function test_F2_06_ordinary_academic_concessions_unchanged(): void
    {
        $built = $this->specs([$this->head(self::HEAD_TUITION_ID, 'Tuition Fee', 'academic', 1000.0)]);
        foreach ([['percent', 10, 900.0], ['fixed', 150, 850.0]] as [$vt, $v, $expect]) {
            $this->assertSame(['D_Tuition Fee_2026-04' => $expect],
                $this->netsNew($built, [$this->ent(['scope' => 'all', 'valueType' => $vt, 'value' => $v])]));
        }
    }

    /**
     * F2-07 — the difference vs legacy, asserted numerically so it can never
     * become a surprise. This is an INTENTIONAL business-rule difference, not a
     * defect: it activates only when service fees flow through this generator.
     */
    public function test_F2_07_documented_numeric_difference_vs_legacy(): void
    {
        $built = $this->specs([$this->head('FH_SVC000000001', 'Bus Fee', 'transport', 800.0)]);
        $legacy = $this->netsLegacy($built, [$this->conc(['scope' => 'all', 'type' => 'percent', 'value' => 20])]);
        $new    = $this->netsNew($built,    [$this->ent(['scope'  => 'all', 'valueType' => 'percent', 'value' => 20])]);

        $this->assertSame(['D_Bus Fee_2026-04' => 640.0], $legacy, 'Legacy DOES discount the fare.');
        $this->assertSame(['D_Bus Fee_2026-04' => 800.0], $new,    'The new rule bills the full fare.');
        $this->assertNotSame($legacy, $new,
            'INTENTIONAL: recorded so the cutover cannot present this as an accident.');
    }

    // ══════════════════════════════════════════════════════════════════
    //  F3 — the amountDue vocabulary gap
    // ══════════════════════════════════════════════════════════════════

    /**
     * F3-01 — the core hazard: a value type the service accepts but the
     * algorithm ignores bills the FULL amount with no error. After the fix no
     * such type may exist in the vocabulary.
     */
    public function test_F3_01_no_accepted_value_type_is_silently_ignored(): void
    {
        $accepted   = (new \ReflectionClass('Entitlement_service'))->getConstant('VALUE_TYPES');
        $implemented = ['percent', 'fixed', 'fullExempt'];   // Fee_generation_service:145-147

        $orphans = array_values(array_diff($accepted, $implemented));
        $this->assertSame([], $orphans,
            'Entitlement_service accepts ' . implode('/', $orphans) . ' but _applyDeductions cannot '
            . 'apply it, so such an entitlement silently bills the GROSS amount. Either implement it '
            . 'or remove it from the vocabulary — accepted-but-ignored is not an option.');
    }

    /** F3-02/03/04 — the three supported types keep working, numerically. */
    public function test_F3_02_03_04_supported_types_still_work(): void
    {
        $built = $this->specs([$this->head(self::HEAD_TUITION_ID, 'Tuition Fee', 'academic', 1000.0)]);
        $this->assertSame(['D_Tuition Fee_2026-04' => 750.0],
            $this->netsNew($built, [$this->ent(['valueType' => 'percent', 'value' => 25])]));
        $this->assertSame(['D_Tuition Fee_2026-04' => 700.0],
            $this->netsNew($built, [$this->ent(['valueType' => 'fixed', 'value' => 300])]));
        $this->assertSame([],
            $this->netsNew($built, [$this->ent(['valueType' => 'fullExempt', 'value' => 0])]),
            'fullExempt drops the demand entirely.');
    }

    /** F3-05 — an unrecognised value type must fail CLOSED, never discount. */
    public function test_F3_05_unrecognised_value_type_fails_closed(): void
    {
        $built = $this->specs([$this->head(self::HEAD_TUITION_ID, 'Tuition Fee', 'academic', 1000.0)]);
        $this->assertSame(['D_Tuition Fee_2026-04' => 1000.0],
            $this->netsNew($built, [$this->ent(['valueType' => 'amountDue', 'value' => 250])]),
            'Fail closed: bill the full amount rather than guess. The protection against this '
            . 'reaching production is F3-01 (vocabulary) plus the diagnostic in F3-07.');
    }

    /** F3-06 — multiple rules follow ONE explicit precedence: fullExempt wins, others sum. */
    public function test_F3_06_multiple_rules_have_one_explicit_precedence(): void
    {
        $built = $this->specs([$this->head(self::HEAD_TUITION_ID, 'Tuition Fee', 'academic', 1000.0)]);

        $this->assertSame(['D_Tuition Fee_2026-04' => 650.0],
            $this->netsNew($built, [
                $this->ent(['valueType' => 'percent', 'value' => 25]),
                $this->ent(['valueType' => 'fixed',   'value' => 100]),
            ]), 'percent + fixed SUM: 1000 - 250 - 100 = 650.');

        $this->assertSame([],
            $this->netsNew($built, [
                $this->ent(['valueType' => 'percent',    'value' => 25]),
                $this->ent(['valueType' => 'fullExempt', 'value' => 0]),
            ]), 'fullExempt WINS over any accumulated deduction.');

        $this->assertSame(['D_Tuition Fee_2026-04' => 0.0],
            $this->netsNew($built, [
                $this->ent(['valueType' => 'fixed', 'value' => 900]),
                $this->ent(['valueType' => 'fixed', 'value' => 900]),
            ]), 'Over-deduction clamps at zero — never negative, never a credit.');
    }

    /**
     * F3-07 — a value type the algorithm cannot apply must leave a greppable
     * diagnostic. Silence is what made this hazard invisible in the first place.
     */
    public function test_F3_07_unapplicable_value_type_is_logged(): void
    {
        $GLOBALS['__test_log_capture'] = [];
        $built = $this->specs([$this->head(self::HEAD_TUITION_ID, 'Tuition Fee', 'academic', 1000.0)]);
        $this->netsNew($built, [$this->ent(['valueType' => 'amountDue', 'value' => 250])]);

        $found = false;
        foreach ($GLOBALS['__test_log_capture'] as $l) {
            if (strpos($l['msg'], 'ENTITLEMENT_VALUETYPE_UNSUPPORTED') !== false) { $found = true; break; }
        }
        unset($GLOBALS['__test_log_capture']);
        $this->assertTrue($found,
            'An entitlement whose valueType cannot be applied must be greppable in the log, '
            . 'or a mis-typed rule bills full price invisibly.');
    }

    // ══════════════════════════════════════════════════════════════════
    //  Cross-cutting matrix: window + status + session
    // ══════════════════════════════════════════════════════════════════

    /** Effective window is honoured identically by both readers. */
    public function test_MATRIX_effective_window_matches_legacy(): void
    {
        $built = $this->specs(
            [$this->head(self::HEAD_TUITION_ID, 'Tuition Fee', 'academic', 1000.0)],
            ['2026-04', '2026-09', '2027-01']
        );
        $legacy = $this->netsLegacy($built, [$this->conc(['effectiveTo' => '2026-09-30', 'type' => 'percent', 'value' => 30])]);
        $new    = $this->netsNew($built,    [$this->ent(['effectiveTo'  => '2026-09-30', 'valueType' => 'percent', 'value' => 30])]);
        $this->assertSame($legacy, $new);
        $this->assertSame(1000.0, $legacy['D_Tuition Fee_2027-01'], 'Outside the window: no discount.');
        $this->assertSame(700.0,  $legacy['D_Tuition Fee_2026-04'], 'Inside the window: discounted.');
    }

    /** A missing effectiveFrom fails closed on both sides. */
    public function test_MATRIX_missing_effective_from_fails_closed(): void
    {
        $built = $this->specs([$this->head(self::HEAD_TUITION_ID, 'Tuition Fee', 'academic', 1000.0)]);
        $this->assertSame(['D_Tuition Fee_2026-04' => 1000.0],
            $this->netsNew($built, [$this->ent(['effectiveFrom' => ''])]));
        $this->assertSame(['D_Tuition Fee_2026-04' => 1000.0],
            $this->netsLegacy($built, [$this->conc(['effectiveFrom' => ''])]));
    }

    /** An unrecognised scope fails closed rather than matching everything. */
    public function test_MATRIX_unrecognised_scope_fails_closed(): void
    {
        $built = $this->specs([$this->head(self::HEAD_TUITION_ID, 'Tuition Fee', 'academic', 1000.0)]);
        foreach (['', 'everything', 'head', 'category'] as $bad) {
            $this->assertSame(['D_Tuition Fee_2026-04' => 1000.0],
                $this->netsNew($built, [$this->ent(['scope' => $bad])]),
                "Scope '{$bad}' must match nothing.");
        }
    }
}
