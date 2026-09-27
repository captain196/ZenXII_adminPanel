<?php

use PHPUnit\Framework\TestCase;

if (!defined('BASEPATH')) define('BASEPATH', __DIR__);
if (!defined('APPPATH'))  define('APPPATH', __DIR__ . '/../../application/');
if (!function_exists('log_message')) { function log_message($l, $m) {} }

require_once __DIR__ . '/../../application/libraries/Fee_lifecycle.php';
require_once __DIR__ . '/../../application/libraries/Fee_firestore_txn.php';
require_once __DIR__ . '/../../application/libraries/Id_card_fee_policy.php';

/** Neutralises the one collaborator _buildDemandDoc() would otherwise reach out to. */
class FhciTxnDouble extends Fee_firestore_txn
{
    public function canonicalHeadIdMap(): array { return []; }
}

/**
 * T39–T44 + the shared-writer persistence guard.
 *
 * The subject: ONE stable fee head ("what type of charge") plus an explicit
 * charge instance ("which occurrence"), replacing the retired scheme where the
 * card version was smuggled through the DISPLAY NAME and was therefore
 * load-bearing for financial identity.
 */
class FeeHeadChargeInstanceTest extends TestCase
{
    /** @return callable(string,string,string,string,string):string */
    private function demandId(): callable
    {
        $life = (new ReflectionClass(Fee_lifecycle::class))->newInstanceWithoutConstructor();
        $m = new ReflectionMethod(Fee_lifecycle::class, '_demandId');
        $m->setAccessible(true);
        return fn(string $stu, string $pk, string $headId, string $fallback = '', string $inst = '')
            => $m->invoke($life, $stu, $pk, $headId, $fallback, $inst);
    }

    /** Runs the REAL whitelist builder, so a dropped field fails the test. */
    private function buildDoc(array $data): array
    {
        $txn = (new ReflectionClass(FhciTxnDouble::class))->newInstanceWithoutConstructor();
        foreach (['schoolId' => 'SCH_B56BB9A401', 'session' => '2026-27', 'ready' => true] as $p => $v) {
            $r = new ReflectionProperty(Fee_firestore_txn::class, $p);
            $r->setAccessible(true);
            $r->setValue($txn, $v);
        }
        $m = new ReflectionMethod(Fee_firestore_txn::class, '_buildDemandDoc');
        $m->setAccessible(true);
        return $m->invoke($txn, 'DEM_TEST', $data);
    }

    private function policy(): Id_card_fee_policy
    {
        $p = (new ReflectionClass(Id_card_fee_policy::class))->newInstanceWithoutConstructor();
        foreach (['head' => 'ID Card Replacement', 'module' => 'IDCard'] as $prop => $val) {
            $r = new ReflectionProperty(Id_card_fee_policy::class, $prop);
            $r->setAccessible(true);
            $r->setValue($p, $val);
        }
        return $p;
    }

    // ══ T39 — two replacements, one period, two charges ═══════════════════

    public function test_T39_two_card_versions_in_one_period_produce_two_distinct_demands(): void
    {
        $mk = $this->demandId();
        $p  = $this->policy();
        $head = $p->feeHeadFor(2) . '_IDCard';

        $v2 = $mk('STU0012', '2026-04', '', $head, $p->instanceKey(2));
        $v3 = $mk('STU0012', '2026-04', '', $head, $p->instanceKey(3));

        $this->assertNotSame($v2, $v3, 'two replacements must remain two charges');
        $this->assertSame('DEM_STU0012_202604_ID_CARD_REPLACEMENT_IDCARD_V2', $v2);
        $this->assertSame('DEM_STU0012_202604_ID_CARD_REPLACEMENT_IDCARD_V3', $v3);
    }

    public function test_T39b_the_head_is_identical_for_both_so_only_the_instance_separates_them(): void
    {
        $p = $this->policy();
        $this->assertSame($p->feeHeadFor(2), $p->feeHeadFor(3));
        $this->assertNotSame($p->instanceKey(2), $p->instanceKey(3));
    }

    // ══ T40 — retry is idempotent ═════════════════════════════════════════

    public function test_T40_same_version_retried_yields_one_identical_demand_id(): void
    {
        $mk = $this->demandId();
        $p  = $this->policy();
        $head = $p->feeHeadFor(2) . '_IDCard';
        $this->assertSame(
            $mk('STU0012', '2026-04', '', $head, $p->instanceKey(2)),
            $mk('STU0012', '2026-04', '', $head, $p->instanceKey(2)),
            'a retried issuance must target the SAME demand, never duplicate it'
        );
    }

    /** FIN-1 / BUG-075: payment state is emitted only when the caller supplies it. */
    public function test_T40b_a_rewrite_without_payment_fields_cannot_clobber_paid_state(): void
    {
        $doc = $this->buildDoc(['studentId' => 'STU0012', 'feeHead' => 'ID Card Replacement',
                                'periodKey' => '2026-04', 'grossAmount' => 100, 'netAmount' => 100,
                                'chargeInstance' => 'V2']);
        foreach (['paidAmount', 'balance', 'status'] as $f) {
            $this->assertArrayNotHasKey($f, $doc, "{$f} must not be emitted unless explicitly supplied");
        }
    }

    // ══ T41 / T42 — module regression: ids must be byte-identical ═════════

    public function test_T41_transport_demand_id_is_byte_identical(): void
    {
        $mk = $this->demandId();
        $this->assertSame(
            'DEM_STU0012_202604_TRANSPORT_FEE_TRANSPORT',
            $mk('STU0012', '2026-04', '', 'Transport Fee_Transport'),
            'changing this string means every live Transport demand would be duplicated'
        );
    }

    public function test_T42_hostel_demand_id_is_byte_identical(): void
    {
        $mk = $this->demandId();
        $this->assertSame(
            'DEM_STU0012_202604_HOSTEL_FEE_HOSTEL',
            $mk('STU0012', '2026-04', '', 'Hostel Fee_Hostel')
        );
    }

    public function test_T41b_ordinary_chart_fee_demand_ids_are_unchanged(): void
    {
        $mk = $this->demandId();
        // assignInitialFees passes a real feeHeadId and NO instance.
        $this->assertSame('DEM_STU0012_202604_FH_AACC5FDE7970',
            $mk('STU0012', '2026-04', 'FH_AACC5FDE7970', 'Tuition Fee'));
        // …and the name-fallback form when a chart row carries no id.
        $this->assertSame('DEM_STU0012_202604_TUITION_FEE',
            $mk('STU0012', '2026-04', '', 'Tuition Fee'));
    }

    public function test_an_absent_instance_appends_nothing_at_all(): void
    {
        $mk = $this->demandId();
        $base = $mk('STU0012', '2026-04', '', 'Transport Fee_Transport');
        foreach (['', '   ', '___'] as $empty) {
            $this->assertSame($base, $mk('STU0012', '2026-04', '', 'Transport Fee_Transport', $empty),
                'an empty/degenerate instance must never add a trailing separator');
        }
    }

    // ══ T43 — stored instance regenerates the id ══════════════════════════

    public function test_T43_stored_charge_instance_reproduces_the_same_demand_id(): void
    {
        $doc = $this->buildDoc(['studentId' => 'STU0012', 'feeHead' => 'ID Card Replacement',
                                'periodKey' => '2026-04', 'grossAmount' => 100, 'netAmount' => 100,
                                'chargeInstance' => 'V2']);
        $this->assertSame('V2', $doc['chargeInstance']);

        // Recalculation rebuilds identity from STORED data — no string parsing.
        $mk = $this->demandId();
        $this->assertSame(
            'DEM_STU0012_202604_ID_CARD_REPLACEMENT_IDCARD_V2',
            $mk($doc['studentId'], $doc['periodKey'], '', $doc['feeHead'] . '_IDCard', $doc['chargeInstance'])
        );
    }

    public function test_T43b_the_instance_is_never_recovered_by_parsing_a_display_name(): void
    {
        $p = $this->policy();
        $this->assertStringNotContainsString('Card v', $p->feeHeadFor(3),
            'the head must carry no version — parsing it back out is the retired anti-pattern');
    }

    // ══ T44 — a rename cannot move an existing demand ═════════════════════

    public function test_T44_changing_the_head_display_name_cannot_alter_an_existing_demand_id(): void
    {
        // A demand's identity is the STORED demandId, echoed back by the
        // writer — never recomputed from the head name at read time.
        $doc = $this->buildDoc(['studentId' => 'STU0012', 'feeHead' => 'Transport Fee',
                                'periodKey' => '2026-04', 'grossAmount' => 300, 'netAmount' => 300]);
        $this->assertSame('DEM_TEST', $doc['demandId']);

        $renamed = $this->buildDoc(['studentId' => 'STU0012', 'feeHead' => 'Conveyance Charge',
                                    'periodKey' => '2026-04', 'grossAmount' => 300, 'netAmount' => 300]);
        $this->assertSame('DEM_TEST', $renamed['demandId'],
            'the id travels with the demand; renaming the head does not move it');
    }

    // ══ Shared-writer persistence — the defect that caused the §5 stop ════

    public function test_charge_instance_survives_the_writer_whitelist(): void
    {
        $doc = $this->buildDoc(['studentId' => 'STU0012', 'feeHead' => 'ID Card Replacement',
                                'periodKey' => '2026-04', 'grossAmount' => 100, 'netAmount' => 100,
                                'chargeInstance' => 'V9']);
        $this->assertArrayHasKey('chargeInstance', $doc,
            'writeDemand() rebuilds $doc from a whitelist — an unlisted field is SILENTLY DROPPED');
        $this->assertSame('V9', $doc['chargeInstance']);
    }

    public function test_callers_that_supply_no_instance_get_no_field(): void
    {
        $doc = $this->buildDoc(['studentId' => 'STU0012', 'feeHead' => 'Transport Fee',
                                'periodKey' => '2026-04', 'grossAmount' => 300, 'netAmount' => 300]);
        $this->assertArrayNotHasKey('chargeInstance', $doc,
            'no synthetic value — an absent instance must leave the document byte-identical');
    }

    public function test_an_empty_instance_is_not_persisted_as_an_empty_string(): void
    {
        foreach (['', '   '] as $empty) {
            $doc = $this->buildDoc(['studentId' => 'STU0012', 'feeHead' => 'Transport Fee',
                                    'periodKey' => '2026-04', 'grossAmount' => 300, 'netAmount' => 300,
                                    'chargeInstance' => $empty]);
            $this->assertArrayNotHasKey('chargeInstance', $doc,
                'an empty instance is meaningless — never store a second way to say "none"');
        }
    }

    // ══ The non-negotiable invariant ══════════════════════════════════════

    public function test_feeHeadRef_never_participates_in_demand_identity(): void
    {
        $src = file_get_contents(APPPATH . 'libraries/Fee_lifecycle.php');

        // Every _demandId() call site, with comments stripped so documentation
        // mentioning feeHeadRef cannot mask a real one.
        $code = '';
        foreach (token_get_all($src) as $t) {
            $code .= is_array($t) ? (in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true) ? ' ' : $t[1]) : $t;
        }
        preg_match_all('/_demandId\s*\(([^;]*?)\)\s*;/s', $code, $m);
        $this->assertNotEmpty($m[1], 'expected to find _demandId call sites');
        foreach ($m[1] as $args) {
            $this->assertStringNotContainsString('feeHeadRef', $args,
                'feeHeadRef is classification only — putting it in a demand id would '
              . 'change every existing Transport/Hostel id and duplicate live charges');
        }

        // And the module path must keep passing an EMPTY head-id argument.
        $this->assertMatchesRegularExpression(
            "/_demandId\(\\\$studentId,\s*\\\$periodKey,\s*'',\s*\\\$headName/",
            $code,
            'createModuleFee must keep deriving its key from the head name, not a registry ref'
        );
    }

    public function test_the_resolver_exposes_nothing_that_could_become_a_demand_id(): void
    {
        require_once APPPATH . 'libraries/Fee_head_resolver.php';
        $public = get_class_methods(Fee_head_resolver::class);
        foreach ($public as $method) {
            $this->assertStringNotContainsString('demandId', $method);
            $this->assertStringNotContainsString('instance', strtolower($method));
        }
    }
}
