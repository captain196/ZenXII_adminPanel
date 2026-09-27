<?php

use PHPUnit\Framework\TestCase;

if (!defined('BASEPATH')) define('BASEPATH', __DIR__);
if (!defined('APPPATH'))  define('APPPATH', __DIR__ . '/../../application/');
if (!function_exists('log_message')) { function log_message($l, $m) {} }

require_once __DIR__ . '/../../application/libraries/Fee_head_resolver.php';
require_once __DIR__ . '/../../application/libraries/Fee_firestore_txn.php';
require_once __DIR__ . '/../../application/libraries/Fee_lifecycle.php';

/** fs double: serves registry entries and name claims by document id. */
class M5FsDouble
{
    public array $docs = [];
    public function get(string $col, string $id) { return $this->docs[$col . '/' . $id] ?? null; }
}

/**
 * M5 — Fee Head Registry as the authority for NEW demand generation.
 *
 * Covers the §19 fail-closed matrix and the invariants that must survive it:
 * the flag isolates old from new behaviour completely, classification never
 * comes from a name keyword, and feeHeadRef never becomes demand identity.
 */
class M5RegistryIntegrationTest extends TestCase
{
    private const SCHOOL = 'SCH_B56BB9A401';
    private M5FsDouble $fs;
    private Fee_head_resolver $r;

    protected function setUp(): void
    {
        $this->fs = new M5FsDouble();
        $this->r  = (new Fee_head_resolver())->init($this->fs);
    }

    private function seed(string $name, string $id, string $category, string $status = 'active'): void
    {
        $this->fs->docs['feeHeadRegistry/' . self::SCHOOL . '_' . $id] = [
            'feeHeadId' => $id, 'schoolId' => self::SCHOOL, 'name' => $name,
            'nameNorm' => Fee_head_resolver::nameNorm($name),
            'category' => $category, 'status' => $status,
        ];
        $this->fs->docs['feeHeadRegistry/' . Fee_head_resolver::claimDocId(self::SCHOOL, $name)] = [
            'kind' => 'nameClaim', 'schoolId' => self::SCHOOL,
            'nameNorm' => Fee_head_resolver::nameNorm($name), 'feeHeadId' => $id,
        ];
    }

    // ══ CASES 1–3 — the registry decides, the name never does ═════════════

    public function test_case1_valid_head_resolves_with_its_registry_category(): void
    {
        $this->seed('Tuition Fee', 'FH_TUI', 'academic');
        $res = $this->r->resolveForNewUse(self::SCHOOL, 'FH_TUI');
        $this->assertTrue($res['ok']);
        $this->assertSame('academic', $res['category']);
    }

    public function test_case2_transport_head_resolves_transport(): void
    {
        $this->seed('Transport Fee', 'FH_TRA', 'transport');
        $this->assertSame('transport', $this->r->resolveForNewUse(self::SCHOOL, 'FH_TRA')['category']);
    }

    /** CASE 3 — THE point of the registry: a head named "Conveyance". */
    public function test_case3_a_head_named_conveyance_is_transport_if_the_registry_says_so(): void
    {
        $this->seed('Conveyance', 'FH_CNV', 'transport');
        $res = $this->r->resolveForNewUse(self::SCHOOL, 'FH_CNV');
        $this->assertSame('transport', $res['category'],
            'no keyword "transport" appears in the name — the registry is authoritative');

        // …and the converse: a head that LOOKS like transport but is classified
        // academic must resolve academic.
        $this->seed('Transport Development Levy', 'FH_TDL', 'academic');
        $this->assertSame('academic', $this->r->resolveForNewUse(self::SCHOOL, 'FH_TDL')['category']);
    }

    // ══ CASES 4–7, 9 — fail closed ════════════════════════════════════════

    public function test_case4_unknown_feeHeadRef_is_refused(): void
    {
        $res = $this->r->resolveForNewUse(self::SCHOOL, 'FH_NOPE');
        $this->assertFalse($res['ok']);
        $this->assertSame('not_found', $res['reason']);
        $this->assertArrayNotHasKey('category', $res);
    }

    public function test_case5_missing_feeHeadRef_is_refused(): void
    {
        $res = $this->r->resolveForNewUse(self::SCHOOL, '');
        $this->assertFalse($res['ok']);
        $this->assertSame('missing_fee_head_ref', $res['reason']);
    }

    public function test_case6_invalid_category_is_refused_not_coerced(): void
    {
        foreach (['General', 'Academic', '', 'unknown', 'nonsense'] as $bad) {
            $this->fs->docs = [];
            $this->seed('Odd Fee', 'FH_ODD', $bad);
            $res = $this->r->resolveForNewUse(self::SCHOOL, 'FH_ODD');
            $this->assertFalse($res['ok'], "stored category '{$bad}' must be refused");
            $this->assertSame('invalid_category', $res['reason']);
        }
    }

    public function test_case7_inactive_head_is_refused_for_new_use(): void
    {
        $this->seed('Retired Fee', 'FH_RET', 'academic', 'archived');
        $res = $this->r->resolveForNewUse(self::SCHOOL, 'FH_RET');
        $this->assertFalse($res['ok']);
        $this->assertSame('archived', $res['reason']);
        // …but history can still explain itself.
        $this->assertTrue($this->r->resolve(self::SCHOOL, 'FH_RET')['ok']);
    }

    /** CASE 8 — rename keeps identity; CASE 9 — no identity is ever minted. */
    public function test_case8_rename_keeps_the_same_identity(): void
    {
        $this->seed('Smart Classroom Fee', 'FH_123', 'academic');
        $before = $this->r->resolveForNewUse(self::SCHOOL, 'FH_123');

        // The school renames the head in the registry. Identity is untouched.
        $this->fs->docs['feeHeadRegistry/' . self::SCHOOL . '_FH_123']['name'] = 'Digital Learning Fee';
        $after = $this->r->resolveForNewUse(self::SCHOOL, 'FH_123');

        $this->assertSame('FH_123', $before['feeHeadId']);
        $this->assertSame('FH_123', $after['feeHeadId'], 'a rename must never yield FH_999');
        $this->assertSame($before['category'], $after['category']);
    }

    public function test_case9_an_unresolvable_name_mints_nothing(): void
    {
        $res = $this->r->resolveByName(self::SCHOOL, 'A Head Nobody Registered');
        $this->assertFalse($res['ok']);
        $this->assertSame('not_found', $res['reason']);
        $this->assertSame([], array_filter($this->fs->docs, static fn($k) => str_contains($k, 'FH_'), ARRAY_FILTER_USE_KEY),
            'resolution must never create a registry document');
    }

    // ══ Module identity by exact name — NOT a keyword heuristic ═══════════

    public function test_module_heads_resolve_by_exact_registered_name(): void
    {
        $this->seed('Transport Fee', 'FH_TRA', 'transport');
        $this->seed('Hostel Fee', 'FH_HOS', 'hostel');
        $this->seed('ID Card Replacement', 'FH_IDC', 'administrative');

        $this->assertSame('transport',      $this->r->resolveByName(self::SCHOOL, 'Transport Fee')['category']);
        $this->assertSame('hostel',         $this->r->resolveByName(self::SCHOOL, 'Hostel Fee')['category']);
        $this->assertSame('administrative', $this->r->resolveByName(self::SCHOOL, 'ID Card Replacement')['category']);
    }

    public function test_module_name_lookup_is_exact_not_substring(): void
    {
        $this->seed('Transport Fee', 'FH_TRA', 'transport');
        // A near-miss must NOT resolve — no substring or keyword fallback.
        foreach (['Transport', 'Bus Fee', 'Transport Fees', 'School Transport Fee'] as $near) {
            $this->assertFalse($this->r->resolveByName(self::SCHOOL, $near)['ok'],
                "'{$near}' must not resolve by proximity to a registered name");
        }
        // …while case and whitespace normalisation still applies.
        $this->assertTrue($this->r->resolveByName(self::SCHOOL, '  transport   FEE ')['ok']);
    }

    public function test_an_unregistered_module_head_fails_closed(): void
    {
        $res = $this->r->resolveByName(self::SCHOOL, 'Hostel Fee');   // never registered
        $this->assertFalse($res['ok'], 'no runtime minting of module heads');
    }

    // ══ School isolation ══════════════════════════════════════════════════

    public function test_school_isolation_holds_for_both_lookups(): void
    {
        $this->seed('Tuition Fee', 'FH_TUI', 'academic');
        $this->assertFalse($this->r->resolveForNewUse('SCH_OTHER', 'FH_TUI')['ok']);
        $this->assertFalse($this->r->resolveByName('SCH_OTHER', 'Tuition Fee')['ok']);
    }

    // ══ CASES 10–11 + the non-negotiable invariant ════════════════════════

    public function test_case10_11_module_demand_ids_are_byte_identical(): void
    {
        $life = (new ReflectionClass(Fee_lifecycle::class))->newInstanceWithoutConstructor();
        $d = new ReflectionMethod(Fee_lifecycle::class, '_demandId');
        $d->setAccessible(true);
        $this->assertSame('DEM_STU0012_202604_TRANSPORT_FEE_TRANSPORT',
            $d->invoke($life, 'STU0012', '2026-04', '', 'Transport Fee_Transport'));
        $this->assertSame('DEM_STU0012_202604_HOSTEL_FEE_HOSTEL',
            $d->invoke($life, 'STU0012', '2026-04', '', 'Hostel Fee_Hostel'));
    }

    /** CASES 12–14 — ID-card instances remain the discriminator. */
    public function test_case12_14_idcard_instances_are_distinct_and_retry_stable(): void
    {
        $life = (new ReflectionClass(Fee_lifecycle::class))->newInstanceWithoutConstructor();
        $d = new ReflectionMethod(Fee_lifecycle::class, '_demandId');
        $d->setAccessible(true);
        $v2 = $d->invoke($life, 'STU0012', '2026-04', '', 'ID Card Replacement_IDCard', 'V2');
        $v3 = $d->invoke($life, 'STU0012', '2026-04', '', 'ID Card Replacement_IDCard', 'V3');
        $this->assertNotSame($v2, $v3);
        $this->assertSame($v2, $d->invoke($life, 'STU0012', '2026-04', '', 'ID Card Replacement_IDCard', 'V2'));
    }

    /** THE invariant: no generation path may feed feeHeadRef into a demand id. */
    public function test_feeHeadRef_never_enters_demandId_anywhere(): void
    {
        foreach (['libraries/Fee_lifecycle.php', 'controllers/Fees.php'] as $rel) {
            $code = '';
            foreach (token_get_all(file_get_contents(APPPATH . $rel)) as $t) {
                $code .= is_array($t) ? (in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true) ? ' ' : $t[1]) : $t;
            }
            preg_match_all('/_(?:build)?[dD]emandId\s*\(([^;]*?)\)\s*;/s', $code, $m);
            foreach ($m[1] as $args) {
                $this->assertStringNotContainsString('feeHeadRef', $args, "in {$rel}");
                $this->assertStringNotContainsString('feeHeadRefM5', $args, "in {$rel}");
            }
        }
    }

    // ══ CASES 16–17 — the flag isolates the two behaviours ════════════════

    public function test_case16_flag_defaults_to_false(): void
    {
        $config = [];
        require APPPATH . 'config/fee_head_registry.php';
        $this->assertArrayHasKey('USE_FEE_HEAD_REGISTRY', $config);
        // SUPERSEDED 2026-08-30 (owner decision): the billing chain ships ON so a
        // new school bills with no grant. Safe only via the capability guards in
        // Fee_lifecycle (FEEHEAD_REGISTRY_HELD / ENTITLEMENT_AUTHORITY_HELD), which
        // are asserted in BillingV2RollbackVerificationTest. Genuinely unfinished
        // features (Phase D pricing, shadow compare, Phase 3 convergence) stay OFF.
        $this->assertTrue($config['USE_FEE_HEAD_REGISTRY'],
            'the registry must ship ENABLED so a new school needs no grant');
    }

    /** Every live generation path must consult the same gate — never half-on. */
    public function test_case17_all_three_generation_paths_are_gated(): void
    {
        $life = file_get_contents(APPPATH . 'libraries/Fee_lifecycle.php');
        $fees = file_get_contents(APPPATH . 'controllers/Fees.php');

        $this->assertStringContainsString('_registryModeOn()', $life);
        $this->assertSame(2, substr_count($life, '$this->_registryStamp('),
            'admission + module paths both stamped');
        $this->assertStringContainsString('_m5RegistryModeOn()', $fees);
        $this->assertStringContainsString('_m5RegistryStamp(', $fees);
    }

    /** The legacy heuristic must survive only on the flag-off branch. */
    public function test_the_name_heuristic_is_confined_to_the_flag_off_branch(): void
    {
        $fees = file_get_contents(APPPATH . 'controllers/Fees.php');
        $this->assertSame(1, substr_count($fees, '$this->_resolveFeeCategory($feeHead)'),
            'exactly one call site remains, inside the flag-off else branch');
        $pos = strpos($fees, '$this->_resolveFeeCategory($feeHead)');
        $gate = strpos($fees, '_m5RegistryModeOn()');
        $this->assertLessThan($pos, $gate, 'the heuristic sits after the gate, i.e. inside it');
    }

    // ══ CASE 15 — legacy demands ══════════════════════════════════════════

    public function test_case15_the_writer_never_promotes_a_legacy_category(): void
    {
        $txn = (new ReflectionClass(Fee_firestore_txn::class))->newInstanceWithoutConstructor();
        foreach (['schoolId' => self::SCHOOL, 'session' => '2026-27', 'ready' => true] as $p => $v) {
            $r = new ReflectionProperty(Fee_firestore_txn::class, $p);
            $r->setAccessible(true);
            $r->setValue($txn, $v);
        }
        $memo = new ReflectionProperty(Fee_firestore_txn::class, '_canonHeadMap');
        $memo->setAccessible(true);
        $memo->setValue($txn, []);
        $b = new ReflectionMethod(Fee_firestore_txn::class, '_buildDemandDoc');
        $b->setAccessible(true);

        $doc = $b->invoke($txn, 'DEM_L', ['studentId' => 'STU1', 'feeHead' => 'Tuition Fee',
                                          'periodKey' => '2026-04', 'grossAmount' => 1, 'netAmount' => 1]);
        $this->assertSame('', $doc['category']);
        $this->assertArrayNotHasKey('categorySource', $doc);
        $this->assertArrayNotHasKey('feeHeadRef', $doc);
    }

    public function test_registry_fields_are_conditional_in_the_writer(): void
    {
        $txn = (new ReflectionClass(Fee_firestore_txn::class))->newInstanceWithoutConstructor();
        foreach (['schoolId' => self::SCHOOL, 'session' => '2026-27', 'ready' => true] as $p => $v) {
            $r = new ReflectionProperty(Fee_firestore_txn::class, $p);
            $r->setAccessible(true);
            $r->setValue($txn, $v);
        }
        $memo = new ReflectionProperty(Fee_firestore_txn::class, '_canonHeadMap');
        $memo->setAccessible(true);
        $memo->setValue($txn, []);
        $b = new ReflectionMethod(Fee_firestore_txn::class, '_buildDemandDoc');
        $b->setAccessible(true);
        $base = ['studentId' => 'STU1', 'feeHead' => 'Tuition Fee', 'periodKey' => '2026-04',
                 'grossAmount' => 1, 'netAmount' => 1, 'category' => 'academic'];

        $on = $b->invoke($txn, 'DEM_N', $base + ['feeHeadRef' => 'FH_TUI', 'categorySource' => 'registry']);
        $this->assertSame('FH_TUI', $on['feeHeadRef']);
        $this->assertSame('registry', $on['categorySource']);

        // Empty values must not be stored — the flag-off paths pass '' for both.
        $off = $b->invoke($txn, 'DEM_N', $base + ['feeHeadRef' => '', 'categorySource' => '']);
        $this->assertArrayNotHasKey('feeHeadRef', $off);
        $this->assertArrayNotHasKey('categorySource', $off);
    }
}
