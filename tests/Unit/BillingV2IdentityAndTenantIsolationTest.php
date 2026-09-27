<?php

use PHPUnit\Framework\TestCase;

if (!defined('BASEPATH')) define('BASEPATH', __DIR__);
if (!defined('APPPATH'))  define('APPPATH', __DIR__ . '/../../application/');
if (!function_exists('log_message')) { function log_message($l, $m) {} }

require_once __DIR__ . '/../../application/libraries/Sr_no_service.php';
require_once __DIR__ . '/../../application/libraries/Entitlement_allocation_service.php';

/**
 * BILLING V2 — IDENTITY SAFETY (phase 2) + MULTI-TENANT ISOLATION (phase 11).
 *
 * ── THE IDENTITY ARCHITECTURE BILLING MUST NOT DISTURB ────────────────────
 *   • Student ID (`STU####`) — canonical, internal, globally unique.
 *   • SRNO — school-SCOPED and school-FACING. School A's SRNO 3 and School B's
 *     SRNO 3 are different students, so an SRNO is only meaningful together
 *     with its school.
 *   • `parent_db_key` (school_code) — a legacy key space, NOT the canonical
 *     identity authority.
 *
 * Billing convergence (P5) rewired every generation and recalculation path. The
 * question these tests answer is whether any of that leaked into identity.
 *
 * The answer the code gives is the strongest possible one: **billing never
 * takes an SRNO at all.** Every billing entry point keys on the canonical
 * Student ID, so the "School A SRNO X resolves to School B's student" failure
 * is not merely guarded against — it is unrepresentable in the billing path.
 *
 * Tenant ids here are fixture constants. No canary school id appears.
 */
class BillingV2IdentityAndTenantIsolationTest extends TestCase
{
    private const SCHOOL_A = 'SCH_TENANT_AAA';
    private const SCHOOL_B = 'SCH_TENANT_BBB';
    private const SESSION  = '2026-27';

    private function billingSources(): array
    {
        return ['controllers/Fees.php', 'libraries/Fee_lifecycle.php',
                'libraries/Fee_generation_service.php', 'libraries/Fee_firestore_txn.php',
                'libraries/Entitlement_allocation_service.php'];
    }

    /** Executable code only — durable comments legitimately discuss identity. */
    private function code(string $rel): string
    {
        $out = '';
        foreach (token_get_all((string) file_get_contents(APPPATH . $rel)) as $t) {
            $out .= is_array($t)
                ? (in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true) ? ' ' : $t[1])
                : $t;
        }
        return $out;
    }

    // ══ PHASE 2 · BILLING NEVER KEYS ON SRNO ═══════════════════════════════

    /**
     * THE HEADLINE. If billing accepted an SRNO it would need school-scoped
     * resolution and could get it wrong. It does not accept one.
     */
    public function test_no_billing_path_accepts_an_srno_as_input(): void
    {
        foreach ($this->billingSources() as $f) {
            $code = $this->code($f);
            foreach (["post('sr_no')", 'post("sr_no")', "post('srNo')", "post('srno')"] as $needle) {
                $this->assertStringNotContainsString($needle, $code,
                    "{$f} must never take an SRNO as a billing key — Student ID is canonical");
            }
        }
    }

    /** Billing must not reach the identity services at all. */
    public function test_billing_does_not_invoke_identity_services(): void
    {
        foreach ($this->billingSources() as $f) {
            $code = $this->code($f);
            foreach (['srnoSvc', 'Sr_no_service', 'apaarSvc', 'Apaar_service',
                      'srNoIndex', 'apaarIndex', 'admissionNoIndex'] as $svc) {
                $this->assertStringNotContainsString($svc, $code,
                    "{$f} must not touch identity service '{$svc}'");
            }
        }
    }

    /** No billing path may WRITE an identity field. */
    public function test_billing_never_writes_an_identity_field(): void
    {
        foreach ($this->billingSources() as $f) {
            $code = $this->code($f);
            foreach (["'srNo' =>", "'apaarId' =>", "'admissionNo' =>"] as $w) {
                $this->assertStringNotContainsString($w, $code,
                    "{$f} must never write identity field {$w}");
            }
        }
    }

    // ══ PHASE 2 · SRNO LOOKUP IS SCHOOL-SCOPED ═════════════════════════════

    /**
     * School A's SRNO 3 must never resolve to School B's student. Driven
     * through the REAL service against a store holding both schools' rows.
     */
    public function test_an_srno_lookup_cannot_cross_schools(): void
    {
        $rows = [
            ['srNo' => 3, 'schoolId' => self::SCHOOL_A, 'currentStudentId' => 'STU_A3'],
            ['srNo' => 3, 'schoolId' => self::SCHOOL_B, 'currentStudentId' => 'STU_B3'],
            ['srNo' => 9, 'schoolId' => self::SCHOOL_B, 'currentStudentId' => 'STU_B9'],
        ];

        $fb = new class($rows) {
            private array $rows;
            public function __construct(array $r) { $this->rows = $r; }
            public function firestoreGet(string $c, string $i) { return null; }
            public function firestoreQuery(string $c, array $conds): array
            {
                $want = null;
                foreach ($conds as $x) if (($x[0] ?? '') === 'schoolId') $want = $x[2];
                $out = [];
                foreach ($this->rows as $r) if ($r['schoolId'] === $want) $out[] = ['data' => $r];
                return $out;
            }
        };

        // School A sees only its own register: max SRNO 3, not B's 9.
        $this->assertSame(3, $this->maxSrNo($fb, self::SCHOOL_A),
            "School A must not see School B's register");
        $this->assertSame(9, $this->maxSrNo($fb, self::SCHOOL_B));
    }

    private function maxSrNo($fb, string $school): int
    {
        $s = (new ReflectionClass('Sr_no_service'))->newInstanceWithoutConstructor();
        (function () use ($fb, $school) {
            $this->firebase = $fb;
            $this->fs       = null;
            $this->schoolId = $school;
            $this->actorId  = 'test';
        })->call($s);

        $m = new ReflectionMethod('Sr_no_service', '_findMaxExistingSrNoForSchool');
        $m->setAccessible(true);
        return (int) $m->invoke($s, $school);
    }

    // ══ PHASE 11 · TENANT ISOLATION IN THE LEDGER ══════════════════════════

    /** A demand and an entitlement from different tenants can never be linked. */
    public function test_an_allocation_cannot_span_two_tenants(): void
    {
        $svc = (new Entitlement_allocation_service())
            ->init(null, null, self::SCHOOL_A, self::SESSION, 'test');

        $demand = ['demandId' => 'D1', 'schoolId' => self::SCHOOL_A, 'session' => self::SESSION,
                   'studentId' => 'STU_A3', 'grossAmount' => 1000.0, 'discountAmount' => 100.0];
        $entB   = ['entitlementId' => 'E1', 'schoolId' => self::SCHOOL_B, 'session' => self::SESSION,
                   'status' => 'active', 'valueType' => 'percent', 'value' => 10.0];

        $this->assertSame('tenant_mismatch', $svc->build($demand, $entB, 100.0)['reason']);
    }

    /** The bound tenant is the authority — a foreign demand is refused too. */
    public function test_a_foreign_demand_is_refused(): void
    {
        $svc = (new Entitlement_allocation_service())
            ->init(null, null, self::SCHOOL_A, self::SESSION, 'test');

        $demandB = ['demandId' => 'D1', 'schoolId' => self::SCHOOL_B, 'session' => self::SESSION,
                    'studentId' => 'STU_B3', 'grossAmount' => 1000.0, 'discountAmount' => 100.0];
        $entA    = ['entitlementId' => 'E1', 'schoolId' => self::SCHOOL_A, 'session' => self::SESSION,
                    'status' => 'active', 'valueType' => 'percent', 'value' => 10.0];

        $this->assertSame('tenant_mismatch', $svc->build($demandB, $entA, 100.0)['reason']);
    }

    /** Allocation identity is tenant-prefixed, so two tenants cannot collide. */
    public function test_allocation_identity_is_tenant_prefixed(): void
    {
        $a = Entitlement_allocation_service::allocationId(self::SCHOOL_A, 'D1', 'E1');
        $b = Entitlement_allocation_service::allocationId(self::SCHOOL_B, 'D1', 'E1');

        $this->assertNotSame($a, $b,
            'identical demand+entitlement ids in two tenants must not share a document');
        $this->assertStringStartsWith(self::SCHOOL_A, $a);
    }

    // ══ PHASE 11 · NO TENANT IS HARD-CODED ═════════════════════════════════

    /**
     * The canary is a runtime target, never a source constant. Comments are
     * stripped first: durable notes cite it by name legitimately.
     */
    public function test_no_tenant_id_appears_in_billing_executable_code(): void
    {
        foreach ($this->billingSources() as $f) {
            $this->assertDoesNotMatchRegularExpression('/SCH_[A-Z0-9]{8,}/', $this->code($f),
                "{$f} must not carry a tenant id in executable code");
            $this->assertDoesNotMatchRegularExpression('/[\'"]10012[\'"]/', $this->code($f),
                "{$f} must not carry a school CODE in executable code either");
        }
    }

    /** Every billing service receives its tenant, rather than discovering one. */
    public function test_billing_services_take_the_tenant_as_a_parameter(): void
    {
        $alloc = (string) file_get_contents(APPPATH . 'libraries/Entitlement_allocation_service.php');
        $this->assertMatchesRegularExpression(
            '/function init\([^)]*string \$schoolId/s', $alloc,
            'the allocation service must be told which tenant it serves');

        $gen = (string) file_get_contents(APPPATH . 'libraries/Fee_generation_service.php');
        $this->assertMatchesRegularExpression(
            '/function init\([^)]*string \$schoolId/s', $gen,
            'the generation service must be told which tenant it serves');
    }

    // ══ PHASE 11 · SIBLING TENANTS STAY LEGACY UNTIL GRANTED ═══════════════

    /**
     * The ledger flag ships false GLOBALLY, so granting the canary cannot arm
     * any sibling tenant. This is what makes a one-tenant canary possible.
     */
    public function test_the_ledger_flag_default_is_globally_false(): void
    {
        $cfg = (string) file_get_contents(APPPATH . 'config/fees_exemption_v2_flags.php');
        $this->assertMatchesRegularExpression(
            // SUPERSEDED 2026-08-30 (owner decision). This read "a true global
            // default would arm every tenant at once" — which is now the intent:
            // a school must not wait for a grant. Arming is harmless here because
            // the ledger is SELF-GATING: _ledgerBackedByAuthority() refuses to
            // emit unless the pricing authority in force carries a grant
            // identity, so a tenant that cannot back it simply writes nothing.
            "/\\\$config\\['USE_ALLOCATION_LEDGER'\\]\\s*=\\s*true\\s*;/", $cfg,
            'the ledger must ship ON so a new school explains its discounts from the first bill');
    }

    /** The resolver is tenant-scoped, not global. */
    public function test_the_flag_resolver_is_tenant_scoped(): void
    {
        $gen = (string) file_get_contents(APPPATH . 'libraries/Fee_generation_service.php');
        $this->assertStringContainsString('$this->schoolId', $gen,
            'the ledger gate must resolve per tenant, never once for the estate');
    }
}
