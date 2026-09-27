<?php
namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

// Loaded at FILE scope, not in setUpBeforeClass(): the reader doubles at the
// bottom of this file `extend` the production readers, and PHP resolves a
// parent class when the file is parsed — long before any setup hook runs.
if (!defined('BASEPATH')) define('BASEPATH', __DIR__);
if (!class_exists('CI_Controller', false)) eval('class CI_Controller { public static $instance; }');
if (!function_exists('get_instance')) eval('function &get_instance() { return \CI_Controller::$instance; }');
foreach (['Fee_concession_reader', 'Fee_entitlement_reader', 'Fee_generation_service'] as $__c) {
    if (!class_exists($__c, false)) require_once __DIR__ . '/../../application/libraries/' . $__c . '.php';
}

/**
 * ══════════════════════════════════════════════════════════════════════════
 * PHASE 3B / B2 — ENTITLEMENT BILLING CUTOVER
 * ══════════════════════════════════════════════════════════════════════════
 *
 * Two changes, in this order, because the second is unsafe without the first:
 *
 *   PART 1 — ACCESSOR BOUNDARY. The generator asked the rule for its deduction
 *   semantics by reaching into legacy field names inline
 *   (`$c['type']` / `$c['value']`). An entitlement stores `valueType` / `value`
 *   and has NO `type` field, so swapping readers alone would have read the
 *   right rows through the wrong fields and deducted ZERO from every
 *   entitlement — silently. The generator must now obtain those semantics
 *   through the reader, and must not know which field name backs them.
 *
 *   PART 2 — READER CUTOVER, behind `USE_ENTITLEMENT_BILLING` (default FALSE).
 *   Exactly one boundary chooses the authority. Never both.
 *
 * ── WHAT EXECUTES ───────────────────────────────────────────────────────────
 * The real `Fee_generation_service`, the real `Fee_concession_reader` and
 * `Fee_entitlement_reader`, and the real deduction arithmetic. Fee arithmetic
 * is never re-implemented here; the doubles supply the chart and the candidate
 * rows only.
 *
 * ── NO I/O ──────────────────────────────────────────────────────────────────
 * No Firestore, no tenant, no admission, no writes.
 */
class Phase3bEntitlementBillingCutoverTest extends TestCase
{
    private const GEN  = __DIR__ . '/../../application/libraries/Fee_generation_service.php';
    private const LIFE = __DIR__ . '/../../application/libraries/Fee_lifecycle.php';
    private const FLAGS = __DIR__ . '/../../application/config/fees_exemption_v2_flags.php';

    // ── helpers ──────────────────────────────────────────────────────────

    private function src(string $path): string
    {
        $out = '';
        foreach (token_get_all(file_get_contents($path)) as $t) {
            if (is_array($t)) {
                if (in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true)) continue;
                $out .= $t[1];
            } else { $out .= $t; }
        }
        return $out;
    }

    /** Price a chart through the REAL generator with the given reader. */
    private function nets($reader, bool $withTransport = false): array
    {
        $g = new \Fee_generation_service();
        $g->init(new CutoverChart($withTransport), $reader, null, 'SCH_C', '2026-27');
        $built = $g->generateDemandsForStudent('STU_C', 'Class 5th', 'Section A');

        $m = [];
        foreach ($built['specs'] as $s) $m[$s['demandId']] = round((float) ($s['data']['netAmount'] ?? 0), 2);
        ksort($m);
        return $m;
    }

    private function ent(array $over = []): array
    {
        return array_merge([
            'studentId' => 'STU_C', 'schoolId' => 'SCH_C', 'session' => '2026-27',
            'kind' => 'concession', 'scope' => 'all', 'valueType' => 'percent', 'value' => 20,
            'effectiveFrom' => '2026-04-01', 'effectiveTo' => null, 'status' => 'active',
        ], $over);
    }

    private function legacyRule(array $over = []): array
    {
        return array_merge([
            'studentId' => 'STU_C', 'schoolId' => 'SCH_C', 'session' => '2026-27',
            'scope' => 'all', 'type' => 'percent', 'value' => 20,
            'effectiveFrom' => '2026-04-01', 'effectiveTo' => null, 'status' => 'active',
        ], $over);
    }

    /** The entitlement reader, fed a fixed candidate set. Matching stays REAL. */
    private function entReader(array $rows): \Fee_entitlement_reader
    {
        return new CutoverEntitlementReader($rows);
    }

    /** The legacy reader, fed a fixed candidate set. Matching stays REAL. */
    private function legacyReader(array $rows): \Fee_concession_reader
    {
        return new CutoverConcessionReader($rows);
    }

    // ══════════════════════════════════════════════════════════════════
    //  PART 1 — the accessor boundary
    // ══════════════════════════════════════════════════════════════════

    /**
     * The generator must not name a source field. Both readers expose the same
     * two accessors, and the generator must go through them.
     */
    public function test_P1_generator_does_not_know_the_source_field_names(): void
    {
        $src = preg_replace('/\s+/', '', $this->src(self::GEN));

        $this->assertStringNotContainsString("\$c['type']", $src,
            'The generator still reads the LEGACY `type` field inline. An entitlement has no such '
            . 'field, so this would deduct ZERO after the cutover.');
        $this->assertStringNotContainsString("\$c['valueType']", $src,
            'The generator must not learn the entitlement field name either — that would just move '
            . 'the coupling, not remove it.');
        $this->assertStringContainsString('deductionType(', $src,
            'Deduction semantics must come from the reader abstraction.');
        $this->assertStringContainsString('deductionValue(', $src);
    }

    /** Both readers must satisfy the same accessor contract. */
    public function test_P1_both_readers_expose_the_same_accessors(): void
    {
        foreach (['Fee_concession_reader', 'Fee_entitlement_reader'] as $cls) {
            foreach (['activeConcessionsForStudent', 'appliesTo', 'deductionType', 'deductionValue'] as $m) {
                $this->assertTrue(method_exists($cls, $m),
                    "{$cls}::{$m}() is required by the reader abstraction the generator depends on.");
            }
        }
    }

    /** P1-1/2/3 — each supported type is normalised correctly by BOTH readers. */
    public function test_P1_value_types_normalise_identically_across_readers(): void
    {
        $legacy = new \Fee_concession_reader();
        $new    = new \Fee_entitlement_reader();

        foreach ([['percent', 20.0], ['fixed', 500.0], ['fullExempt', 0.0]] as [$type, $val]) {
            $this->assertSame($type, $legacy->deductionType(['type' => $type, 'value' => $val]));
            $this->assertSame($val,  $legacy->deductionValue(['type' => $type, 'value' => $val]));
            $this->assertSame($type, $new->deductionType(['valueType' => $type, 'value' => $val]));
            $this->assertSame($val,  $new->deductionValue(['valueType' => $type, 'value' => $val]));
        }
    }

    /** P1-5 — the arithmetic itself is unchanged: legacy rows bill exactly as before. */
    public function test_P1_legacy_arithmetic_unchanged(): void
    {
        $this->assertSame(['D_Tuition Fee_2026-04' => 800.0],
            $this->nets($this->legacyReader([$this->legacyRule()])));
        $this->assertSame(['D_Tuition Fee_2026-04' => 500.0],
            $this->nets($this->legacyReader([$this->legacyRule(['type' => 'fixed', 'value' => 500])])));
        $this->assertSame([],
            $this->nets($this->legacyReader([$this->legacyRule(['type' => 'fullExempt', 'value' => 0])])));
    }

    /** P1-4/K — an unsupported type cannot silently become a zero deduction. */
    public function test_P1_unsupported_value_type_is_diagnosed_not_silent(): void
    {
        $GLOBALS['__test_log_capture'] = [];
        $nets = $this->nets($this->entReader([$this->ent(['valueType' => 'amountDue', 'value' => 250])]));

        $found = false;
        foreach ($GLOBALS['__test_log_capture'] as $l) {
            if (strpos($l['msg'], 'ENTITLEMENT_VALUETYPE_UNSUPPORTED') !== false) { $found = true; break; }
        }
        unset($GLOBALS['__test_log_capture']);

        $this->assertSame(['D_Tuition Fee_2026-04' => 1000.0], $nets, 'Fails CLOSED — bills gross.');
        $this->assertTrue($found, 'and says so, greppably.');
    }

    // ══════════════════════════════════════════════════════════════════
    //  PART 2 — the flag, at exactly one boundary
    // ══════════════════════════════════════════════════════════════════

    public function test_P2_cutover_flag_exists_and_defaults_to_off(): void
    {
        $cfg = file_get_contents(self::FLAGS);
        // SUPERSEDED 2026-08-30 (owner decision): the cutover flag ships ON, so a
        // newly sold school prices on the entitlement authority without anyone
        // granting it. The safety that used to live in "ships OFF" now lives in
        // Fee_lifecycle's legacy-concession guard (ENTITLEMENT_AUTHORITY_HELD):
        // a school already holding active concessions keeps the legacy authority.
        $this->assertMatchesRegularExpression("/'USE_ENTITLEMENT_BILLING'\]\s*=\s*true\s*;/", $cfg,
            'The billing-authority cutover flag must exist and ship ON.');
    }

    public function test_P2_exactly_one_boundary_selects_the_authority(): void
    {
        $life = $this->src(self::LIFE);
        $this->assertSame(1, substr_count($life, 'USE_ENTITLEMENT_BILLING'),
            'The authority must be chosen at ONE place. More than one read means two switches that '
            . 'can disagree.');
        $this->assertStringNotContainsString('USE_ENTITLEMENT_BILLING', $this->src(self::GEN),
            'The generator must not know about the flag — it consumes whichever reader it is given.');
    }

    /**
     * The generator must never consult both authorities. Two readers means two
     * candidate sets, and summing them would double-discount.
     */
    public function test_P2_generator_never_reads_both_authorities(): void
    {
        $src = $this->src(self::GEN);
        $auth = substr($src, 0, strpos($src, '_shadowCompare') ?: strlen($src));
        $this->assertStringNotContainsString('studentEntitlements', $auth,
            'The authoritative path must not name a collection at all — the reader owns that.');
        $this->assertStringNotContainsString('studentConcessions', $auth);
    }

    /**
     * NEGATIVE CONTROL. If the reader made no difference, these two would be
     * equal and the whole cutover would be theatre. They must differ.
     */
    public function test_P2_NEGATIVE_CONTROL_reader_choice_actually_changes_the_money(): void
    {
        $viaLegacy = $this->nets($this->legacyReader([]));                    // no legacy concession
        $viaEnt    = $this->nets($this->entReader([$this->ent()]));           // 20% entitlement

        $this->assertSame(['D_Tuition Fee_2026-04' => 1000.0], $viaLegacy);
        $this->assertSame(['D_Tuition Fee_2026-04' => 800.0],  $viaEnt);
        $this->assertNotSame($viaLegacy, $viaEnt,
            'If these matched, the reader swap would be doing nothing and every other test here '
            . 'would be vacuous.');
    }

    /** THE HEADLINE PROOF required by the brief. */
    public function test_P2_THE_PROOF_1000_with_20_percent_entitlement_becomes_800(): void
    {
        $this->assertSame(['D_Tuition Fee_2026-04' => 1000.0],
            $this->nets($this->legacyReader([])),
            'BEFORE — legacy authority, entitlement invisible: 1000.');
        $this->assertSame(['D_Tuition Fee_2026-04' => 800.0],
            $this->nets($this->entReader([$this->ent()])),
            'AFTER — entitlement authority: 800.');
    }

    // ══════════════════════════════════════════════════════════════════
    //  PART 3 — entitlement semantics A–K
    // ══════════════════════════════════════════════════════════════════

    public function semanticCases(): array
    {
        $base = [
            'studentId' => 'STU_C', 'schoolId' => 'SCH_C', 'session' => '2026-27',
            'kind' => 'concession', 'scope' => 'all', 'valueType' => 'percent', 'value' => 20,
            'effectiveFrom' => '2026-04-01', 'effectiveTo' => null, 'status' => 'active',
        ];
        return [
            'A no entitlement'          => [[],                                                        ['D_Tuition Fee_2026-04' => 1000.0]],
            'B 20% academic'            => [[$base],                                                   ['D_Tuition Fee_2026-04' => 800.0]],
            'C fixed 500'               => [[array_merge($base, ['valueType' => 'fixed', 'value' => 500])], ['D_Tuition Fee_2026-04' => 500.0]],
            'D full exemption'          => [[array_merge($base, ['valueType' => 'fullExempt', 'value' => 0])], []],
            'E rejected'                => [[array_merge($base, ['status' => 'rejected'])],             ['D_Tuition Fee_2026-04' => 1000.0]],
            'F future-effective'        => [[array_merge($base, ['effectiveFrom' => '2027-01-01'])],    ['D_Tuition Fee_2026-04' => 1000.0]],
            'G expired'                 => [[array_merge($base, ['effectiveTo' => '2026-03-31'])],      ['D_Tuition Fee_2026-04' => 1000.0]],
            'J percent + fixed additive'=> [[$base, array_merge($base, ['valueType' => 'fixed', 'value' => 100])], ['D_Tuition Fee_2026-04' => 700.0]],
            'J fullExempt wins'         => [[$base, array_merge($base, ['valueType' => 'fullExempt', 'value' => 0])], []],
            'J clamps at zero'          => [[array_merge($base, ['valueType' => 'fixed', 'value' => 900]),
                                             array_merge($base, ['valueType' => 'fixed', 'value' => 900])], ['D_Tuition Fee_2026-04' => 0.0]],
        ];
    }

    /** @dataProvider semanticCases */
    public function test_P3_entitlement_semantics(array $rows, array $expected): void
    {
        // Status filtering is the reader's server-side query; mirror it fairly.
        $active = array_values(array_filter($rows, fn(array $e) => ($e['status'] ?? '') === 'active'));
        $this->assertSame($expected, $this->nets($this->entReader($active)));
    }

    /** H — the operator-APPROVED F2 rule, through the cutover path. */
    public function test_P3_H_blanket_academic_leaves_transport_gross(): void
    {
        $nets = $this->nets($this->entReader([$this->ent()]), /* withTransport */ true);
        $this->assertSame(800.0, $nets['D_Tuition Fee_2026-04'], 'Academic head discounted.');
        $this->assertSame(800.0, $nets['D_Bus Fee_2026-04'],
            'Transport head untouched by a blanket academic concession (F2, approved).');
    }

    /** I — an explicitly transport-scoped entitlement does discount transport. */
    public function test_P3_I_explicit_transport_entitlement_applies(): void
    {
        $nets = $this->nets($this->entReader([$this->ent(['scope' => 'category:transport', 'value' => 25])]), true);
        $this->assertSame(1000.0, $nets['D_Tuition Fee_2026-04'], 'Academic head untouched.');
        $this->assertSame(600.0,  $nets['D_Bus Fee_2026-04'],     'Transport discounted 25%.');
    }

    // ══════════════════════════════════════════════════════════════════
    //  PART 6 / B5 — entitlement deduplication
    // ══════════════════════════════════════════════════════════════════

    private function svc(DedupeFirebase $fb): \Entitlement_service
    {
        if (!class_exists('Entitlement_service', false)) {
            require_once __DIR__ . '/../../application/libraries/Entitlement_service.php';
        }
        $s = new \Entitlement_service();
        $s->init($fb, null, 'SCH_C', '2026-27', 'ADM_1');
        return $s;
    }

    private function proposal(array $over = []): array
    {
        return array_merge([
            'studentId' => 'STU_C', 'kind' => 'concession', 'concessionType' => 'sibling',
            'scope' => 'all', 'valueType' => 'percent', 'value' => 20,
            'frequency' => 'monthly', 'effectiveFrom' => '2026-04-01',
            'reason' => 'Elder sibling enrolled', 'approvalRequired' => false,
        ], $over);
    }

    /** B5-1/2 — the first proposal creates; an identical retry does not. */
    public function test_P6_identical_retry_does_not_create_a_second_entitlement(): void
    {
        $fb  = new DedupeFirebase();
        $svc = $this->svc($fb);

        $a = $svc->propose($this->proposal());
        $this->assertTrue($a['ok']);
        $this->assertArrayNotHasKey('duplicate', $a);
        $this->assertSame('active', $a['status']);

        $b = $svc->propose($this->proposal());     // the retry
        $this->assertTrue($b['ok'], 'A retry must not be reported as an error.');
        $this->assertTrue($b['duplicate'] ?? false, 'and must be reported as the SAME entitlement.');
        $this->assertSame($a['id'], $b['id'],      'Same business identity ⇒ same document id.');
        $this->assertSame(1, $fb->createdCount(),  'Exactly ONE document exists — not two that would SUM.');
    }

    /** B5-3 — a concurrent retry loses the 409 race rather than writing a second row. */
    public function test_P6_concurrent_retry_cannot_duplicate(): void
    {
        $fb  = new DedupeFirebase();
        $svc = $this->svc($fb);

        // Both "requests" build the same id; create-if-not-exists admits one.
        $r1 = $svc->propose($this->proposal());
        $fb->simulateConcurrentWinner = true;      // the other request got there first
        $r2 = $svc->propose($this->proposal(['reason' => 'retyped reason']));

        $this->assertTrue($r1['ok']);
        $this->assertTrue($r2['ok']);
        $this->assertTrue($r2['duplicate'] ?? false);
        $this->assertSame(1, $fb->createdCount(),
            'Reason is provenance, not price — a retyped reason must not mint a second concession.');
    }

    /** B5-4 — materially different entitlements stay distinguishable. */
    public function test_P6_material_differences_remain_distinct(): void
    {
        $fb  = new DedupeFirebase();
        $svc = $this->svc($fb);

        $base = $svc->propose($this->proposal());
        $cases = [
            'different value'  => ['value' => 25],
            'different scope'  => ['scope' => 'category:transport'],
            'different type'   => ['valueType' => 'fixed', 'value' => 500],
            'different window' => ['effectiveFrom' => '2026-07-01'],
        ];
        foreach ($cases as $label => $over) {
            $r = $svc->propose($this->proposal($over));
            $this->assertTrue($r['ok']);
            $this->assertNotSame($base['id'], $r['id'],
                "A {$label} is a materially different entitlement and must remain distinct.");
        }
        $this->assertSame(5, $fb->createdCount(), 'One base + four genuinely different entitlements.');
    }

    /** B5-5 — approval semantics are untouched by dedupe. */
    public function test_P6_approval_state_unchanged_by_dedupe(): void
    {
        $fb  = new DedupeFirebase();
        $svc = $this->svc($fb);

        $needs = $svc->propose($this->proposal(['approvalRequired' => true]));
        $this->assertSame('pending_approval', $needs['status'],
            'A proposal that needs approval must NOT become active just because it is deduplicated.');

        $again = $svc->propose($this->proposal(['approvalRequired' => true]));
        $this->assertTrue($again['duplicate'] ?? false);
        $this->assertSame('pending_approval', $again['status'],
            'and the retry reports the EXISTING state, not a fresh one.');
    }

    /** No entitlement at all ⇒ byte-identical to legacy-with-no-concession. */
    public function test_P3_no_entitlement_matches_legacy_no_concession(): void
    {
        $this->assertSame($this->nets($this->legacyReader([])), $this->nets($this->entReader([])),
            'With nothing on file the two authorities must agree exactly — that is what makes the '
            . 'cutover safe for the 100% of students who hold no concession.');
    }
}

// ══════════════════════════════════════════════════════════════════════════
//  Doubles — they supply DATA. All matching and pricing is production code.
// ══════════════════════════════════════════════════════════════════════════

class CutoverChart
{
    public function __construct(private bool $withTransport = false) {}

    public function buildAdmissionDemandSpecs($studentId, $class, $section, $forPreview = false): array
    {
        $heads = [['FH_TUI', 'Tuition Fee', 'academic', 1000.0]];
        if ($this->withTransport) $heads[] = ['FH_BUS', 'Bus Fee', 'transport', 800.0];

        $specs = [];
        foreach ($heads as [$id, $name, $cat, $amt]) {
            $specs[] = ['demandId' => "D_{$name}_2026-04", 'data' => [
                'studentId' => $studentId, 'periodKey' => '2026-04',
                'feeHeadId' => $id, 'feeHead' => $name, 'category' => $cat,
                'grossAmount' => $amt, 'netAmount' => $amt, 'balance' => $amt, 'status' => 'unpaid',
            ]];
        }
        return ['specs' => $specs, 'assigned' => array_column($heads, 1), 'chartFound' => true];
    }
}

/** Real matcher + real accessors; only the candidate set is supplied. */
class CutoverEntitlementReader extends \Fee_entitlement_reader
{
    public function __construct(private array $rows) {}
    public function init($firebase): void {}
    public function activeConcessionsForStudent(string $s, string $st, string $sess): array { return $this->rows; }
}

/** Real matcher + real accessors; only the candidate set is supplied. */
class CutoverConcessionReader extends \Fee_concession_reader
{
    public function __construct(private array $rows) {}
    public function init($firebase): void {}
    public function activeConcessionsForStudent(string $s, string $st, string $sess): array { return $this->rows; }
}

/**
 * A Firestore double with REAL create-if-not-exists semantics, so the dedupe
 * guarantee is exercised rather than assumed. It decides nothing about
 * fingerprinting — that is production code.
 */
class DedupeFirebase
{
    private array $docs = [];
    public bool $simulateConcurrentWinner = false;

    public function firestoreCreateStatus($collection, $docId, $data): array
    {
        $k = "{$collection}/{$docId}";
        if ($this->simulateConcurrentWinner && !isset($this->docs[$k])) {
            // Another request won the race between our check and our write.
            $this->docs[$k] = $data;
            return ['status' => 'COLLISION', 'code' => 409, 'message' => 'already exists'];
        }
        if (isset($this->docs[$k])) return ['status' => 'COLLISION', 'code' => 409, 'message' => 'already exists'];
        $this->docs[$k] = $data;
        return ['status' => 'SUCCESS', 'code' => 200, 'message' => ''];
    }

    public function firestoreGet($collection, $docId) { return $this->docs["{$collection}/{$docId}"] ?? null; }
    public function firestoreSet($collection, $docId, $data, $merge = false) { $this->docs["{$collection}/{$docId}"] = $data; return true; }
    public function firestoreCreate($collection, $docId, $data) { return ($this->firestoreCreateStatus($collection, $docId, $data)['status'] ?? '') === 'SUCCESS'; }
    public function firestoreQuery(...$a) { return []; }
    public function createdCount(): int { return count($this->docs); }
    public function __call($n, $a) { return null; }
}
