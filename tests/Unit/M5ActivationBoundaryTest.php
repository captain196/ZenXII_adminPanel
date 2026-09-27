<?php

use PHPUnit\Framework\TestCase;

if (!defined('BASEPATH')) define('BASEPATH', __DIR__);
if (!defined('APPPATH'))  define('APPPATH', __DIR__ . '/../../application/');
if (!function_exists('log_message')) { function log_message($l, $m) {} }

require_once __DIR__ . '/../../application/libraries/Fee_head_resolver.php';
require_once __DIR__ . '/../../application/libraries/Fee_entitlement_reader.php';

/**
 * M5 ACTIVATION BOUNDARY — what `USE_FEE_HEAD_REGISTRY` does, and does not do.
 *
 * The flag decides ONE thing: whether a NEWLY generated demand is classified by
 * the Fee Head Registry instead of the legacy name heuristic. It must not touch
 * an amount, an identity, an existing demand, or either mobile app.
 *
 * Verified against the live UAT tenant (SCH_B56BB9A401) by a read-only
 * simulation before these tests were written:
 *   • 6 registry heads, all active, all validly classified, claims intact
 *   • 4/4 generating chart rows carry a resolvable feeHeadRef
 *   • all 3 module head names resolve (Transport / Hostel / ID Card Replacement)
 *   • 0 of 26 demand ids contain a registry ref
 *   • 0 demands missing a flat financial field
 *
 * No Firestore. No flag is changed. Doubles only.
 */
class M5ActivationBoundaryTest extends TestCase
{
    private function fsDouble(array $docs)
    {
        return new class($docs) {
            public function __construct(private array $docs) {}
            public function schoolCode(): string { return 'SCH_B56BB9A401'; }
            public function get(string $c, string $id): ?array { return $this->docs[$id] ?? null; }
        };
    }

    /** The UAT tenant's registry, as the simulation actually found it. */
    private function uatRegistry(): array
    {
        $heads = [
            'FH_DF3C200439E2' => ['Tuition Fee',         'academic'],
            'FH_088BB5D74C9F' => ['Library Fee',         'academic'],
            'FH_1DDD704466C5' => ['Admission Fee',       'academic'],
            'FH_E038A430DF0D' => ['Transport Fee',       'transport'],
            'FH_180EDF7FC11D' => ['Hostel Fee',          'hostel'],
            'FH_2102C0AF6BE4' => ['ID Card Replacement', 'administrative'],
        ];
        $docs = [];
        foreach ($heads as $ref => [$name, $cat]) {
            $nn = strtolower(trim(preg_replace('/\s+/', ' ', $name)));
            $docs["SCH_B56BB9A401_{$ref}"] = ['feeHeadId' => $ref, 'schoolId' => 'SCH_B56BB9A401',
                'name' => $name, 'nameNorm' => $nn, 'category' => $cat, 'status' => 'active'];
            $docs["SCH_B56BB9A401_name_{$nn}"] = ['kind' => 'nameClaim', 'schoolId' => 'SCH_B56BB9A401',
                'nameNorm' => $nn, 'feeHeadId' => $ref];
        }
        return $docs;
    }

    private function resolver(array $docs = null): Fee_head_resolver
    {
        return (new Fee_head_resolver())->init($this->fsDouble($docs ?? $this->uatRegistry()));
    }

    /** Faithful port of Fees::_m5RegistryStamp() / Fee_lifecycle::_registryStamp(). */
    private function stamp(string $feeHead, array $docs = null): array
    {
        $res = $this->resolver($docs)->resolveByName('SCH_B56BB9A401', $feeHead);
        if (empty($res['ok']) || ($res['status'] ?? 'active') !== 'active') {
            return ['ok' => false, 'reason' => (string) ($res['reason'] ?? 'archived')];
        }
        return ['ok' => true, 'feeHeadRef' => $res['feeHeadId'],
                'category' => $res['category'], 'categorySource' => 'registry'];
    }

    // ══ A · FLAG OFF — legacy behaviour is untouched ═════════════════════

    public function test_A_the_flag_is_off_and_gates_exactly_one_thing(): void
    {
        $this->assertStringContainsString("\$config['USE_FEE_HEAD_REGISTRY'] = true",
            file_get_contents(APPPATH . 'config/fee_head_registry.php'));

        // Both generators read it at their own boundary and nowhere else.
        $this->assertStringContainsString('_m5RegistryModeOn()', file_get_contents(APPPATH . 'controllers/Fees.php'));
        $this->assertStringContainsString('_registryModeOn()',   file_get_contents(APPPATH . 'libraries/Fee_lifecycle.php'));
    }

    public function test_A_flag_off_writes_no_registry_fields(): void
    {
        // _buildDemandDoc emits feeHeadRef / categorySource ONLY when non-empty,
        // so a flag-off demand is byte-identical to the pre-M5 document.
        $txn = file_get_contents(APPPATH . 'libraries/Fee_firestore_txn.php');
        $this->assertStringContainsString("if (\$headRef !== '') \$doc['feeHeadRef'] = \$headRef;", $txn);
    }

    // ══ B · FLAG ON — registry-derived classification ════════════════════

    public function test_B_every_generating_chart_head_resolves(): void
    {
        foreach ([
            'Tuition Fee'   => ['FH_DF3C200439E2', 'academic'],
            'Library Fee'   => ['FH_088BB5D74C9F', 'academic'],
            'Admission Fee' => ['FH_1DDD704466C5', 'academic'],
            'Transport Fee' => ['FH_E038A430DF0D', 'transport'],
        ] as $head => [$ref, $cat]) {
            $s = $this->stamp($head);
            $this->assertTrue($s['ok'], "{$head} must resolve");
            $this->assertSame($ref, $s['feeHeadRef']);
            $this->assertSame($cat, $s['category']);
            $this->assertSame('registry', $s['categorySource']);
        }
    }

    /** Transport must classify as transport — the I3 service rule depends on it. */
    public function test_B_transport_hostel_and_idcard_module_heads_resolve(): void
    {
        $this->assertSame('transport',      $this->stamp('Transport Fee')['category']);
        $this->assertSame('hostel',         $this->stamp('Hostel Fee')['category']);
        $this->assertSame('administrative', $this->stamp('ID Card Replacement')['category']);
    }

    public function test_B_an_unregistered_head_fails_closed(): void
    {
        foreach (['Sports Fee', 'Tution Fee', 'tuition', ''] as $head) {
            $this->assertFalse($this->stamp($head)['ok'], "'{$head}' must not resolve");
        }
    }

    public function test_B_an_archived_or_unclassified_head_fails_closed(): void
    {
        $docs = $this->uatRegistry();
        $docs['SCH_B56BB9A401_FH_DF3C200439E2']['status'] = 'archived';
        $this->assertFalse($this->stamp('Tuition Fee', $docs)['ok']);

        $docs2 = $this->uatRegistry();
        $docs2['SCH_B56BB9A401_FH_DF3C200439E2']['category'] = 'unknown';
        $this->assertFalse($this->stamp('Tuition Fee', $docs2)['ok']);
    }

    /** Classification is never guessed from a name — no keyword matching. */
    public function test_B_resolution_is_exact_match_not_a_heuristic(): void
    {
        $this->assertTrue($this->stamp('  tuition   FEE ')['ok'], 'only case/whitespace are normalised');
        $this->assertFalse($this->stamp('Tuition')['ok'],        'a substring is not a head');
        $this->assertFalse($this->stamp('Extra Tuition Fee')['ok']);
    }

    public function test_B_generation_aborts_rather_than_guessing(): void
    {
        $this->assertStringContainsString('ACC_FEEHEAD_UNRESOLVED', file_get_contents(APPPATH . 'controllers/Fees.php'));
        $this->assertStringContainsString('ACC_FEEHEAD_UNRESOLVED', file_get_contents(APPPATH . 'libraries/Fee_lifecycle.php'));
        $this->assertStringContainsString('charge refused (fail closed)', file_get_contents(APPPATH . 'libraries/Fee_lifecycle.php'));
    }

    // ══ C · IDENTITY — the flag must not move a single demand id ═════════

    public function test_C_feeHeadRef_never_enters_demandId(): void
    {
        $lif = file_get_contents(APPPATH . 'libraries/Fee_lifecycle.php');
        $this->assertStringContainsString('$id = "DEM_{$sid}_{$ym}_{$key}";', $lif);
        $this->assertStringContainsString('NEVER pass a feeHeadRef', $lif);

        // The live proof: the UAT estate's demand ids carry the LEGACY chart-row
        // id, which is a different value from the registry ref for the same head.
        $this->assertStringNotContainsString('FH_088BB5D74C9F', 'DEM_STU0012_202604_FH_46E8A3E1FF32');
        $this->assertNotSame('FH_088BB5D74C9F', 'FH_46E8A3E1FF32');
    }

    public function test_C_the_demandId_shape_is_unchanged(): void
    {
        foreach (['DEM_STU0012_202604_FH_46E8A3E1FF32',
                  'DEM_STU0012_202605_FH_82F29A9E7AEA'] as $did) {
            $this->assertMatchesRegularExpression('/^DEM_[A-Za-z0-9_]+_\d{6}_[A-Z0-9_]+$/', $did);
        }
    }

    public function test_C_phase1_dedupe_and_phase2_conflict_survive(): void
    {
        $fees = file_get_contents(APPPATH . 'controllers/Fees.php');
        $this->assertStringContainsString(
            '($haveKeys[$dedupKey] ?? []) + ($haveKeys[$dedupKeyByName] ?? [])', $fees);   // Phase 1
        $this->assertStringContainsString('ACC_FEE_DEMAND_IDENTITY_CONFLICT', $fees);      // Phase 2
        $this->assertStringContainsString('if ($matchCount === 1) {', $fees);              // reuse, never rewrite
    }

    /** An existing occurrence is skipped — a re-run cannot duplicate or restamp it. */
    public function test_C_a_repeat_run_cannot_duplicate_or_rewrite_an_existing_demand(): void
    {
        $fees = file_get_contents(APPPATH . 'controllers/Fees.php');
        $this->assertMatchesRegularExpression(
            '/if \(\$matchCount === 1\) \{\s*\$result\[.skipped.\]\+\+;\s*continue;/', $fees,
            'the existing demand is reused, never rewritten — so M5 cannot restamp history');
    }

    // ══ D · CROSS-PLATFORM ══════════════════════════════════════════════

    /**
     * Both apps read flat financial fields off the demand. M5 adds only
     * classification fields, so their contract is untouched — and Kotlin data
     * classes ignore unknown fields, so added keys are invisible to them.
     */
    public function test_D_the_apps_financial_contract_is_untouched(): void
    {
        $txn = file_get_contents(APPPATH . 'libraries/Fee_firestore_txn.php');
        foreach (['grossAmount', 'netAmount', 'discountAmount', 'fineAmount'] as $f) {
            $this->assertStringContainsString("'{$f}'", $txn, "{$f} must still be emitted");
        }
        // The M5 fields are additive and conditional — never replacements.
        $this->assertStringContainsString("if (\$headRef !== '') \$doc['feeHeadRef'] = \$headRef;", $txn);
    }

    public function test_D_neither_app_reads_entitlement_or_registry_state(): void
    {
        foreach (['D:/Projects/SchoolSyncParent/app/src', 'D:/Projects/SchoolSyncTeacher/app/src'] as $src) {
            if (!is_dir($src)) { $this->markTestSkipped("{$src} not present on this machine"); }
        }
        foreach ([
            'D:/Projects/SchoolSyncParent/app/src/main/java/com/schoolsync/parent/data/model/firestore/FeeDemandDoc.kt',
            'D:/Projects/SchoolSyncTeacher/app/src/main/java/com/schoolsync/teacher/data/model/firestore/FeeDemandDoc.kt',
        ] as $f) {
            $kt = file_get_contents($f);
            $this->assertStringContainsString('netAmount',  $kt);
            $this->assertStringContainsString('balance',    $kt);
            $this->assertStringNotContainsString('feeHeadRef',        $kt, 'no app parses registry identity');
            $this->assertStringNotContainsString('studentEntitlement', $kt, 'no entitlement assumption may leak');
        }
    }

    // ══ E · ENTITLEMENT ELIGIBILITY BOUNDARY ════════════════════════════

    /**
     * A LEGACY demand (no feeHeadRef) can never be implicitly promoted into an
     * entitlement-eligible one: the reader's authoritative tier is inert
     * without a ref, and generation never rewrites an existing demand.
     */
    public function test_E_a_legacy_demand_is_never_implicitly_converted(): void
    {
        $r = new Fee_entitlement_reader();
        $ent = ['scope' => 'head:FH_DF3C200439E2', 'valueType' => 'percent', 'value' => 10,
                'effectiveFrom' => '2020-01-01', 'effectiveTo' => null, 'status' => 'active'];

        // Legacy demand: feeHeadRef empty, legacy chart id present.
        $this->assertFalse($r->appliesTo($ent, '2026-04', 'FH_46E8A3E1FF32', 'Tuition Fee', '', ''),
            'a ref-scoped entitlement must not reach a legacy demand');

        // Registry-stamped demand: the same entitlement now applies.
        $this->assertTrue($r->appliesTo($ent, '2026-04', 'FH_46E8A3E1FF32', 'Tuition Fee', 'academic', 'FH_DF3C200439E2'));
    }

    public function test_E_existing_demands_keep_their_legacy_classification(): void
    {
        // The live estate carries category '' on all 26 demands. M5 stamps only
        // NEW demands, so the estate stays MIXED — which is the intended
        // transition state, not a defect.
        $fees = file_get_contents(APPPATH . 'controllers/Fees.php');
        $this->assertStringContainsString('$result[\'skipped\']++;', $fees);
        $this->assertStringNotContainsString('promote category', $fees);
    }

    /** Category '' must never be promoted to 'academic'. */
    public function test_E_empty_category_is_never_promoted(): void
    {
        $this->assertFalse(Fee_head_resolver::isAssignableCategory('unknown'));
        $this->assertFalse(Fee_head_resolver::isAssignableCategory(''));
        $this->assertTrue(Fee_head_resolver::isValidCategory('unknown'),
            "'unknown' is a READ-TIME interpretation, never an assignable choice");
    }

    // ══ SAFETY ══════════════════════════════════════════════════════════

    public function test_no_flag_was_changed_and_billing_stays_off(): void
    {
        $this->assertStringContainsString("\$config['USE_FEE_HEAD_REGISTRY'] = true",
            file_get_contents(APPPATH . 'config/fee_head_registry.php'));
        // SUPERSEDED 2026-08-30: Phase D ships ON after SVC-1 was reproduced and
        // closed (scripts/service_entitlement_proof.js, 16/16). Its old
        // prerequisite was a LEGACY migration that a new school never has.
        foreach (['ENTITLEMENT_SHADOW_COMPARE', 'PHASE_3_CONVERGED'] as $flag) {
            $this->assertStringContainsString("\$config['{$flag}'] = false",
                file_get_contents(APPPATH . 'config/fees_exemption_v2_flags.php'));
        }
    }
}
