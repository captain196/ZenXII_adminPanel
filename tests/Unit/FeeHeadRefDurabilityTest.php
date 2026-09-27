<?php

use PHPUnit\Framework\TestCase;

if (!defined('BASEPATH')) define('BASEPATH', __DIR__);
if (!defined('APPPATH'))  define('APPPATH', __DIR__ . '/../../application/');
if (!function_exists('log_message')) { function log_message($l, $m) {} }

require_once __DIR__ . '/../../application/libraries/Fee_firestore_sync.php';
require_once __DIR__ . '/../../application/libraries/Fee_firestore_txn.php';
require_once __DIR__ . '/../../application/libraries/Fee_head_resolver.php';

/** Captures what syncFeeStructure() would write, and serves the prior snapshot. */
class FhrdFirebaseDouble
{
    public array $docs = [];      // "col/id" => data
    public array $writes = [];    // "col/id" => data
    public array $registry = [];  // rows returned for feeHeadRegistry

    public function firestoreGet(string $col, string $id) { return $this->docs[$col . '/' . $id] ?? null; }
    public function firestoreSet(string $col, string $id, array $data, bool $merge = false): bool
    {
        $this->writes[$col . '/' . $id] = $data;
        $this->docs[$col . '/' . $id] = $data;
        return true;
    }
    public function firestoreQuery(string $col, array $where = []): array
    {
        return $col === 'feeHeadRegistry' ? $this->registry : [];
    }
}

/** A registry that is unreachable — must degrade to 'no identity', never mint one. */
class FhrdRegistryDownDouble extends FhrdFirebaseDouble
{
    public function firestoreQuery(string $col, array $where = []): array
    { throw new \RuntimeException('registry unavailable'); }
}

/**
 * Prerequisite hardening — feeHeadRef durability + categorySource provenance.
 *
 * The defect under test: syncFeeStructure() rebuilds every row from a fixed
 * key set and writes with NO merge flag, so an ordinary pricing edit erased
 * the registry identity from all 28 live rows.
 */
class FeeHeadRefDurabilityTest extends TestCase
{
    private const SCHOOL  = 'SCH_B56BB9A401';
    private const SESSION = '2026-27';
    private const DOC     = 'SCH_B56BB9A401_2026-27_Class 9th_Section A';

    private FhrdFirebaseDouble $fb;
    private Fee_firestore_sync $sync;

    protected function setUp(): void
    {
        $this->fb   = new FhrdFirebaseDouble();
        $this->sync = (new ReflectionClass(Fee_firestore_sync::class))->newInstanceWithoutConstructor();
        foreach (['firebase' => $this->fb, 'schoolCode' => self::SCHOOL,
                  'session' => self::SESSION, 'ready' => true] as $p => $v) {
            $r = new ReflectionProperty(Fee_firestore_sync::class, $p);
            $r->setAccessible(true);
            $r->setValue($this->sync, $v);
        }
    }

    /** Seed the "already saved" chart, i.e. the state M4 produced. */
    private function seedPrior(array $rows): void
    {
        $this->fb->docs['feeStructures/' . self::DOC] = [
            'schoolId' => self::SCHOOL, 'session' => self::SESSION,
            'className' => 'Class 9th', 'section' => 'Section A', 'feeHeads' => $rows,
        ];
    }

    private function seedRegistry(array $nameToId): void
    {
        $this->fb->registry = [];
        foreach ($nameToId as $name => $id) {
            $this->fb->registry[] = ['data' => [
                'feeHeadId' => $id, 'schoolId' => self::SCHOOL, 'name' => $name,
                'category' => 'academic', 'status' => 'active',
            ]];
        }
    }

    /** @param array $monthly name=>amount ; $yearly name=>amount */
    private function save(array $monthly, array $yearly = []): array
    {
        // Real chart shape: monthly heads are read from the April bucket,
        // annual heads from 'Yearly Fees' (Fee_firestore_sync:236).
        $chart = ['April' => $monthly];
        if ($yearly !== []) $chart['Yearly Fees'] = $yearly;
        $m = new ReflectionMethod(Fee_firestore_sync::class, 'syncFeeStructure');
        $m->setAccessible(true);
        $m->invoke($this->sync, 'Class 9th', 'Section A', $chart);
        return $this->fb->writes['feeStructures/' . self::DOC]['feeHeads'] ?? [];
    }

    private function rowFor(array $rows, string $name): ?array
    {
        foreach ($rows as $r) if (($r['name'] ?? '') === $name) return $r;
        return null;
    }

    // ══ A. feeHeadRef durability ══════════════════════════════════════════

    /** A1 — THE defect: an ordinary save must not erase the identity. */
    public function test_A1_existing_feeHeadRef_survives_a_chart_save(): void
    {
        $this->seedPrior([['feeHeadId' => 'FH_ROW1', 'name' => 'Tuition Fee', 'amount' => 600,
                           'frequency' => 'monthly', 'billingType' => 'Monthly',
                           'feeHeadRef' => 'FH_DF3C200439E2']]);
        $out = $this->save(['Tuition Fee' => 600]);
        $this->assertSame('FH_DF3C200439E2', $this->rowFor($out, 'Tuition Fee')['feeHeadRef'] ?? null);
    }

    /** A2 — repricing is the most common edit and must not disturb identity. */
    public function test_A2_editing_the_amount_preserves_feeHeadRef_and_feeHeadId(): void
    {
        $this->seedPrior([['feeHeadId' => 'FH_ROW1', 'name' => 'Tuition Fee', 'amount' => 600,
                           'frequency' => 'monthly', 'billingType' => 'Monthly',
                           'feeHeadRef' => 'FH_DF3C200439E2']]);
        $row = $this->rowFor($this->save(['Tuition Fee' => 950]), 'Tuition Fee');
        $this->assertSame(950.0, (float) $row['amount']);
        $this->assertSame('FH_DF3C200439E2', $row['feeHeadRef']);
        $this->assertSame('FH_ROW1', $row['feeHeadId'], 'the legacy demand key must not move either');
    }

    /** A3 — monthly → annual is a billing change, not an identity change. */
    public function test_A3_changing_frequency_preserves_feeHeadRef(): void
    {
        $this->seedPrior([['feeHeadId' => 'FH_ROW1', 'name' => 'Examination Fee', 'amount' => 500,
                           'frequency' => 'monthly', 'billingType' => 'Monthly',
                           'feeHeadRef' => 'FH_EA952DF24FF5']]);
        $row = $this->rowFor($this->save([], ['Examination Fee' => 500]), 'Examination Fee');
        $this->assertSame('annual', $row['frequency']);
        $this->assertSame('FH_EA952DF24FF5', $row['feeHeadRef']);
    }

    /** A4 — repeated saves must converge, not drift. */
    public function test_A4_resaving_the_same_chart_is_stable(): void
    {
        $this->seedPrior([['feeHeadId' => 'FH_ROW1', 'name' => 'Tuition Fee', 'amount' => 600,
                           'frequency' => 'monthly', 'billingType' => 'Monthly',
                           'feeHeadRef' => 'FH_DF3C200439E2']]);
        $first  = $this->save(['Tuition Fee' => 600]);
        $second = $this->save(['Tuition Fee' => 600]);
        $this->assertSame($first, $second);
        $this->assertSame('FH_DF3C200439E2', $this->rowFor($second, 'Tuition Fee')['feeHeadRef']);
    }

    /** A5/A6 — identity is school-scoped; a session must never enter it. */
    public function test_A5_no_session_component_enters_feeHeadRef(): void
    {
        $this->seedPrior([['feeHeadId' => 'FH_ROW1', 'name' => 'Tuition Fee', 'amount' => 600,
                           'frequency' => 'monthly', 'billingType' => 'Monthly',
                           'feeHeadRef' => 'FH_DF3C200439E2']]);
        $ref = $this->rowFor($this->save(['Tuition Fee' => 600]), 'Tuition Fee')['feeHeadRef'];
        $this->assertStringNotContainsString(self::SESSION, $ref);
        $this->assertStringNotContainsString('2026', $ref);
        $this->assertSame(2, (new ReflectionMethod(Fee_head_resolver::class, 'docId'))->getNumberOfParameters());
    }

    /** A7 — the registry resolves a head the previous snapshot doesn't know. */
    public function test_A7_registry_resolves_a_row_with_no_prior_binding(): void
    {
        $this->seedPrior([['feeHeadId' => 'FH_ROW1', 'name' => 'Library Fee', 'amount' => 100,
                           'frequency' => 'monthly', 'billingType' => 'Monthly']]);   // no ref yet
        $this->seedRegistry(['Library Fee' => 'FH_088BB5D74C9F']);
        $this->assertSame('FH_088BB5D74C9F',
            $this->rowFor($this->save(['Library Fee' => 100]), 'Library Fee')['feeHeadRef'] ?? null);
    }

    public function test_A7b_registry_match_is_case_and_whitespace_insensitive(): void
    {
        $this->seedPrior([]);
        $this->seedRegistry(['Tuition Fee' => 'FH_DF3C200439E2']);
        $this->assertSame('FH_DF3C200439E2',
            $this->rowFor($this->save(['  tuition   FEE ' => 600]), '  tuition   FEE ')['feeHeadRef'] ?? null);
    }

    /** An archived head must never be bound to new pricing. */
    public function test_archived_registry_entry_is_not_used_for_new_pricing(): void
    {
        $this->seedPrior([]);
        $this->fb->registry = [['data' => ['feeHeadId' => 'FH_OLD', 'schoolId' => self::SCHOOL,
                                           'name' => 'Retired Fee', 'status' => 'archived']]];
        $this->assertArrayNotHasKey('feeHeadRef', $this->rowFor($this->save(['Retired Fee' => 10]), 'Retired Fee'));
    }

    public function test_name_claim_documents_are_not_treated_as_registry_entries(): void
    {
        $this->seedPrior([]);
        $this->fb->registry = [['data' => ['kind' => 'nameClaim', 'schoolId' => self::SCHOOL,
                                           'nameNorm' => 'tuition fee', 'feeHeadId' => 'FH_CLAIM']]];
        $this->assertArrayNotHasKey('feeHeadRef', $this->rowFor($this->save(['Tuition Fee' => 600]), 'Tuition Fee'));
    }

    /** THE fail-closed rule: an unmatched row gets NO identity, never a new one. */
    public function test_an_unresolvable_row_gets_no_feeHeadRef_and_none_is_minted(): void
    {
        $this->seedPrior([['feeHeadId' => 'FH_ROW1', 'name' => 'Transport Fee', 'amount' => 300,
                           'frequency' => 'monthly', 'billingType' => 'Monthly',
                           'feeHeadRef' => 'FH_E038A430DF0D']]);
        $this->seedRegistry([]);                       // registry knows nothing of the new name
        $row = $this->rowFor($this->save(['School Bus Fee' => 300]), 'School Bus Fee');   // chart-only rename
        $this->assertArrayNotHasKey('feeHeadRef', $row,
            'a chart-only rename must fail closed — minting here is the defect this design removes');
    }

    public function test_a_brand_new_head_unknown_to_the_registry_gets_no_ref(): void
    {
        $this->seedPrior([]);
        $this->seedRegistry([]);
        $this->assertArrayNotHasKey('feeHeadRef',
            $this->rowFor($this->save(['Smart Classroom Fee' => 250]), 'Smart Classroom Fee'));
    }

    public function test_a_registry_read_failure_never_invents_an_identity(): void
    {
        // A registry that is unavailable must degrade to "no identity", never
        // to a freshly minted one.
        $fb = new FhrdRegistryDownDouble();
        $sync = (new ReflectionClass(Fee_firestore_sync::class))->newInstanceWithoutConstructor();
        foreach (['firebase' => $fb, 'schoolCode' => self::SCHOOL,
                  'session' => self::SESSION, 'ready' => true] as $p => $v) {
            $r = new ReflectionProperty(Fee_firestore_sync::class, $p);
            $r->setAccessible(true);
            $r->setValue($sync, $v);
        }
        $m = new ReflectionMethod(Fee_firestore_sync::class, 'syncFeeStructure');
        $m->setAccessible(true);
        $m->invoke($sync, 'Class 9th', 'Section A', ['April' => ['Tuition Fee' => 600]]);

        $rows = $fb->writes['feeStructures/' . self::DOC]['feeHeads'] ?? [];
        $row  = $this->rowFor($rows, 'Tuition Fee');
        $this->assertIsArray($row, 'the chart itself must still save');
        $this->assertArrayNotHasKey('feeHeadRef', $row);
    }

    public function test_every_other_row_field_is_untouched_by_the_change(): void
    {
        $this->seedPrior([['feeHeadId' => 'FH_ROW1', 'name' => 'Tuition Fee', 'amount' => 600,
                           'frequency' => 'monthly', 'billingType' => 'Monthly',
                           'feeHeadRef' => 'FH_DF3C200439E2']]);
        $row = $this->rowFor($this->save(['Tuition Fee' => 600]), 'Tuition Fee');
        $this->assertSame(['feeHeadId', 'name', 'amount', 'frequency', 'billingType', 'feeHeadRef'],
            array_keys($row), 'no field added or dropped beyond feeHeadRef');
    }

    // ══ B. Identity safety ════════════════════════════════════════════════

    /** B8 + B9 + B10 — the non-negotiable invariant. */
    public function test_B8_feeHeadRef_never_enters_demandId(): void
    {
        $src = file_get_contents(APPPATH . 'libraries/Fee_lifecycle.php');
        $code = '';
        foreach (token_get_all($src) as $t) {
            $code .= is_array($t) ? (in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true) ? ' ' : $t[1]) : $t;
        }
        preg_match_all('/_demandId\s*\(([^;]*?)\)\s*;/s', $code, $m);
        $this->assertNotEmpty($m[1]);
        foreach ($m[1] as $args) $this->assertStringNotContainsString('feeHeadRef', $args);

        $life = (new ReflectionClass(Fee_lifecycle::class))->newInstanceWithoutConstructor();
        $d = new ReflectionMethod(Fee_lifecycle::class, '_demandId');
        $d->setAccessible(true);
        $this->assertSame('DEM_STU0012_202604_TRANSPORT_FEE_TRANSPORT',
            $d->invoke($life, 'STU0012', '2026-04', '', 'Transport Fee_Transport'));
        $this->assertSame('DEM_STU0012_202604_HOSTEL_FEE_HOSTEL',
            $d->invoke($life, 'STU0012', '2026-04', '', 'Hostel Fee_Hostel'));
    }

    public function test_B13_the_dead_feeHeads_collection_is_not_written_by_the_sync(): void
    {
        $this->seedPrior([]);
        $this->save(['Tuition Fee' => 600]);
        $this->assertNotEmpty($this->fb->writes, 'the chart itself must have been written');
        foreach (array_keys($this->fb->writes) as $k) {
            $this->assertStringStartsNotWith('feeHeads/', $k);
            $this->assertStringStartsNotWith('feeHeadRegistry/', $k, 'a pricing save must never write the registry');
        }
    }

    // ══ C. categorySource writer ══════════════════════════════════════════

    private function buildDoc(array $data): array
    {
        $txn = (new ReflectionClass(Fee_firestore_txn::class))->newInstanceWithoutConstructor();
        foreach (['schoolId' => self::SCHOOL, 'session' => self::SESSION, 'ready' => true] as $p => $v) {
            $r = new ReflectionProperty(Fee_firestore_txn::class, $p);
            $r->setAccessible(true);
            $r->setValue($txn, $v);
        }
        $memo = new ReflectionProperty(Fee_firestore_txn::class, '_canonHeadMap');
        $memo->setAccessible(true);
        $memo->setValue($txn, []);            // avoid any Firestore reach-out
        $m = new ReflectionMethod(Fee_firestore_txn::class, '_buildDemandDoc');
        $m->setAccessible(true);
        return $m->invoke($txn, 'DEM_TEST', $data);
    }

    private function base(): array
    {
        return ['studentId' => 'STU0012', 'feeHead' => 'Tuition Fee', 'periodKey' => '2026-04',
                'grossAmount' => 600, 'netAmount' => 600, 'category' => 'academic'];
    }

    public function test_C14_absent_categorySource_creates_no_field(): void
    {
        $this->assertArrayNotHasKey('categorySource', $this->buildDoc($this->base()));
    }

    public function test_C15_registry_value_is_persisted(): void
    {
        $doc = $this->buildDoc($this->base() + ['categorySource' => 'registry']);
        $this->assertSame('registry', $doc['categorySource']);
    }

    public function test_C16_empty_or_whitespace_is_not_persisted(): void
    {
        foreach (['', '   ', "\t"] as $empty) {
            $this->assertArrayNotHasKey('categorySource',
                $this->buildDoc($this->base() + ['categorySource' => $empty]),
                'an empty provenance is meaningless — never store a second way to say "unknown"');
        }
    }

    public function test_C19_payment_fields_are_still_conditionally_emitted(): void
    {
        $doc = $this->buildDoc($this->base() + ['categorySource' => 'registry']);
        foreach (['paidAmount', 'balance', 'status'] as $f) $this->assertArrayNotHasKey($f, $doc);
    }

    public function test_C20_adding_categorySource_does_not_alter_category(): void
    {
        $without = $this->buildDoc($this->base());
        $with    = $this->buildDoc($this->base() + ['categorySource' => 'registry']);
        $this->assertSame($without['category'], $with['category']);
        unset($with['categorySource'], $without['updatedAt'], $with['updatedAt']);
        $this->assertSame($without, $with, 'the field must be purely additive');
    }

    /** C17/C18 — both write paths share _buildDemandDoc(), so both persist it. */
    public function test_C18_single_and_batch_paths_share_one_builder(): void
    {
        $src = file_get_contents(APPPATH . 'libraries/Fee_firestore_txn.php');
        $this->assertSame(1, substr_count($src, "if (array_key_exists('categorySource', \$data))"),
            'exactly one implementation, inside the shared builder');
        $this->assertGreaterThanOrEqual(2, substr_count($src, '_buildDemandDoc('),
            'the shared builder is invoked by more than one write path');
    }

    // ══ D. Legacy safety ══════════════════════════════════════════════════

    public function test_D_legacy_empty_category_is_never_promoted(): void
    {
        $doc = $this->buildDoc(['studentId' => 'STU0012', 'feeHead' => 'Tuition Fee',
                                'periodKey' => '2026-04', 'grossAmount' => 600, 'netAmount' => 600]);
        $this->assertSame('', $doc['category'], "an absent category must stay '' — never become academic");
        $this->assertArrayNotHasKey('categorySource', $doc);
    }

    public function test_D_out_of_contract_legacy_values_pass_through_unchanged(): void
    {
        foreach (['Academic', 'Transport', 'General'] as $legacy) {
            $doc = $this->buildDoc(array_merge($this->base(), ['category' => $legacy]));
            $this->assertSame($legacy, $doc['category'], 'legacy values must not be normalised here');
        }
    }
}
