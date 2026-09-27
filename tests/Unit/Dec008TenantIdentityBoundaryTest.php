<?php

use PHPUnit\Framework\TestCase;

if (!defined('BASEPATH')) define('BASEPATH', __DIR__);
if (!defined('APPPATH'))  define('APPPATH', __DIR__ . '/../../application/');
if (!function_exists('log_message')) { function log_message($l, $m) {} }

require_once __DIR__ . '/../../application/libraries/Fee_head_resolver.php';

/**
 * DEC-008 — the tenant-identity boundary into Entitlement_service.
 *
 * THE DEFECT THIS CLOSES. `Sis::save_admission()` sets
 * `$school_id = $this->parent_db_key`, and `parent_db_key = school_code ?: school_id`. On
 * the UAT tenant the login code is "10012" while the canonical id is "SCH_B56BB9A401". That
 * legacy key reached Entitlement_service, so Fee_head_resolver was asked for
 * `10012_FH_DF3C200439E2` — a document that cannot exist, because feeHeadRegistry is keyed
 * by canonical school_id. EVERY head-scoped admission entitlement failed closed with "fee
 * head not registered", and the student was admitted BILLING PENDING.
 *
 * WHY THE FIX IS ONE ARGUMENT, NOT A RENAME. `parent_db_key` is CORRECT for the legacy key
 * space that owns it: `srNoIndex` rows are stored under the login code and are
 * self-consistent — writes and reads both use it, which is why SRNO allocation SUCCEEDED in
 * the failed UAT. A blanket rename would ORPHAN those claims and could re-issue a live SRNO.
 * So: canonical id for canonical-keyed stores, legacy key for legacy stores.
 */
class Dec008TenantIdentityBoundaryTest extends TestCase
{
    private const CANONICAL = 'SCH_B56BB9A401';
    private const LEGACY    = '10012';                // the real school_code
    private const REF       = 'FH_DF3C200439E2';      // registered Tuition Fee

    private function code(string $rel): string
    {
        $out = '';
        foreach (token_get_all(file_get_contents(APPPATH . $rel)) as $t) {
            $out .= is_array($t) ? (in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true) ? ' ' : $t[1]) : $t;
        }
        return $out;
    }

    /** Registry double keyed exactly as production is: {canonicalSchoolId}_{ref}. */
    private function registryFs()
    {
        return new class {
            public array $asked = [];
            public function schoolCode(): string { return 'SCH_B56BB9A401'; }
            public function get(string $col, string $docId): ?array
            {
                $this->asked[] = $docId;
                if ($docId !== 'SCH_B56BB9A401_FH_DF3C200439E2') return null;
                return ['feeHeadId' => 'FH_DF3C200439E2', 'schoolId' => 'SCH_B56BB9A401',
                        'name' => 'Tuition Fee', 'category' => 'academic', 'status' => 'active'];
            }
        };
    }

    // ══ 3-5 · RESOLUTION UNDER EACH KEY ══════════════════════════════════

    public function test_3_valid_ref_resolves_with_the_canonical_school_id(): void
    {
        $fs = $this->registryFs();
        $r  = (new Fee_head_resolver())->init($fs)->resolveForNewUse(self::CANONICAL, self::REF);
        $this->assertTrue($r['ok'], 'the registered head must resolve under the canonical id');
        $this->assertSame('academic', $r['category']);
        $this->assertSame(['SCH_B56BB9A401_FH_DF3C200439E2'], $fs->asked);
    }

    public function test_4_the_same_ref_FAILS_CLOSED_with_the_legacy_key(): void
    {
        $fs = $this->registryFs();
        $r  = (new Fee_head_resolver())->init($fs)->resolveForNewUse(self::LEGACY, self::REF);
        $this->assertFalse($r['ok'], 'this is exactly the production failure');
        $this->assertSame('not_found', $r['reason']);
        $this->assertSame(['10012_FH_DF3C200439E2'], $fs->asked,
            'proves the wrong document was requested');
    }

    public function test_5_the_registry_document_id_is_exact(): void
    {
        $this->assertSame('SCH_B56BB9A401_FH_DF3C200439E2',
            Fee_head_resolver::docId(self::CANONICAL, self::REF));
        $this->assertNotSame(Fee_head_resolver::docId(self::CANONICAL, self::REF),
                             Fee_head_resolver::docId(self::LEGACY, self::REF));
    }

    // ══ 1-2, 9 · EVERY INIT SITE PASSES A CANONICAL ID ═══════════════════

    public function test_1_2_9_no_Entitlement_service_init_receives_parent_db_key(): void
    {
        $sites = [
            'controllers/Sis.php'          => '(string) $this->school_id',
            'controllers/Entitlements.php' => '$this->school_id',
            'controllers/Student360.php'   => '$this->school_id',
        ];
        foreach ($sites as $file => $expected) {
            $code = $this->code($file);
            $this->assertMatchesRegularExpression(
                '/entitlementSvc->init\(\s*\$this->firebase,\s*\$this->fs,\s*'
                . preg_quote($expected, '/') . '\s*,/',
                $code, $file . ' must pass the canonical school id');
            $this->assertDoesNotMatchRegularExpression(
                '/entitlementSvc->init\([^;]*parent_db_key/s', $code,
                $file . ' must never hand parent_db_key to Entitlement_service');
        }
        // The parent-app route derives it from the verified token claim.
        $this->assertStringContainsString("\$claims['school_id']",
            $this->code('controllers/Entitlement_requests.php'));
    }

    // ══ 6-7 · BOTH TENANT SHAPES ═════════════════════════════════════════

    /** school_code != school_id — the live UAT case that broke. */
    public function test_6_divergent_school_code_still_resolves(): void
    {
        $parentDbKey = self::LEGACY ?: self::CANONICAL;   // school_code wins
        $this->assertSame(self::LEGACY, $parentDbKey, 'parent_db_key derivation');
        $fs = $this->registryFs();
        $this->assertTrue((new Fee_head_resolver())->init($fs)
            ->resolveForNewUse(self::CANONICAL, self::REF)['ok'],
            'the fix uses school_id, so divergence no longer matters');
    }

    /** school_code == school_id, or absent — must not regress. */
    public function test_7_identical_or_absent_school_code_is_unaffected(): void
    {
        foreach (['', self::CANONICAL] as $schoolCode) {
            $parentDbKey = $schoolCode ?: self::CANONICAL;
            $this->assertSame(self::CANONICAL, $parentDbKey);
            $fs = $this->registryFs();
            $this->assertTrue((new Fee_head_resolver())->init($fs)
                ->resolveForNewUse(self::CANONICAL, self::REF)['ok']);
        }
    }

    // ══ 8 · LEGACY KEY SPACE DELIBERATELY UNCHANGED ══════════════════════

    /**
     * SRNO/APAAR keep parent_db_key ON PURPOSE — `srNoIndex` rows live under the login code
     * and are self-consistent. Renaming would orphan a live claim and could re-issue an
     * allocated SRNO. Pinned so a future tidy-up cannot silently do it.
     */
    public function test_8_srno_and_apaar_still_receive_parent_db_key(): void
    {
        $code = $this->code('controllers/Sis.php');
        $this->assertStringContainsString(
            '$this->srnoSvc->init($this->firebase, $this->fs, $this->parent_db_key', $code);
        $this->assertStringContainsString(
            '$this->apaarSvc->init($this->firebase, $this->fs, $this->parent_db_key', $code);
        $this->assertStringContainsString('$school_id   = $this->parent_db_key;', $code,
            'the legacy variable itself feeds many legacy paths and stays');
    }

    // ══ 10 · TENANT ISOLATION ════════════════════════════════════════════

    public function test_10_a_foreign_tenant_cannot_resolve_this_head(): void
    {
        $fs = $this->registryFs();
        $r  = (new Fee_head_resolver())->init($fs)->resolveForNewUse('SCH_218AAF5C23', self::REF);
        $this->assertFalse($r['ok'], 'cross-tenant registry lookup must never succeed');
    }

    // ══ 11-15 · NOTHING ELSE MOVED ═══════════════════════════════════════

    public function test_11_to_14_prior_decisions_remain_intact(): void
    {
        $svc = $this->code('libraries/Entitlement_service.php');
        $this->assertStringContainsString('_assertRegisteredHead', $svc);            // DEC-004
        $this->assertStringContainsString('resolveForNewUse', $svc);                 // archived rule
        $this->assertStringContainsString('cannot be changed at approval', $svc);    // scope immutability

        $grant = $this->code('libraries/Rollout_grant_service.php');
        $this->assertStringContainsString('USE_ENTITLEMENT_BILLING', $grant);        // DEC-006
        $this->assertStringContainsString('$current[$flag] = $entry;', $grant);      // sibling preservation

        $lif = $this->code('libraries/Fee_lifecycle.php');
        $this->assertStringContainsString('$dedupKeyByName', $lif);                  // DEC-007
        $this->assertStringContainsString('ACC_FEE_DEMAND_IDENTITY_CONFLICT', $lif); // DEC-007
        $this->assertStringContainsString('categorySource', $lif);                   // M5 stamping
    }

    public function test_15_parent_and_teacher_financial_contract_untouched(): void
    {
        $txn = $this->code('libraries/Fee_firestore_txn.php');
        foreach (['grossAmount', 'netAmount', 'discountAmount', 'fineAmount'] as $f) {
            $this->assertStringContainsString("'" . $f . "'", $txn);
        }
    }

    // ══ REPO-WIDE GUARD — the CLASS of defect ════════════════════════════

    /**
     * Fee_head_resolver is the only feeHeadRegistry reader, so guarding its consumers
     * guards the store: no canonical-keyed registry lookup may be fed a parent_db_key.
     */
    public function test_no_registry_consumer_is_initialised_from_parent_db_key(): void
    {
        $files = array_merge(glob(APPPATH . 'controllers/*.php'), glob(APPPATH . 'libraries/*.php'));
        foreach ($files as $f) {
            $src = file_get_contents($f);
            if (strpos($src, 'Fee_head_resolver') === false) continue;
            $this->assertDoesNotMatchRegularExpression(
                '/(feeHeadResolver|m5Resolver|headResolver)[^;]{0,120}parent_db_key/s', $src,
                basename($f) . ' must not feed parent_db_key into the fee-head registry');
        }
    }
}
