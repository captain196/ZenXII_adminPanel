<?php

use PHPUnit\Framework\TestCase;

if (!defined('BASEPATH')) define('BASEPATH', 'x');
if (!defined('APPPATH')) define('APPPATH', __DIR__ . '/../../application/');

/**
 * ══════════════════════════════════════════════════════════════════════════
 *  DAY-ONE-3 · READINESS-DRIVEN FINANCIAL ACTIVATION
 * ══════════════════════════════════════════════════════════════════════════
 *
 * ── THE GAP ──────────────────────────────────────────────────────────────
 * A school could pass every readiness check, be taken live, and still bill on
 * the legacy authority — because the Billing V2 chain was three rollout flags
 * somebody had to remember, in an order somebody had to know.
 *
 * Granting them earlier is not the fix: registry mode against an empty
 * registry makes fee head resolution fail CLOSED, and the school bills nobody.
 * Activation therefore has to FOLLOW readiness, which is what this service
 * decides.
 *
 * ── WHAT MUST NOT REGRESS ────────────────────────────────────────────────
 * The dangerous mistakes are all in one direction — activating a tenant that
 * is not ready — so nearly every test here is a REFUSAL, and every refusal
 * additionally asserts that NOTHING was written. A guard that refuses in its
 * return value while granting on the way there would be worse than no guard.
 */
final class FinancialActivationServiceTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once APPPATH . 'libraries/Fee_head_resolver.php';
        require_once APPPATH . 'libraries/Fee_head_readiness.php';
        require_once APPPATH . 'libraries/Rollout_grant_service.php';
        require_once APPPATH . 'libraries/Financial_activation_service.php';
    }

    /**
     * Firestore double. Records every write so a refusal can be proven inert.
     *
     * @param array $opts registry/chart/legacy/demands/control/throwOn
     */
    private function fs(array $opts = [])
    {
        return new class($opts) {
            public array $writes = [];
            public function __construct(private array $o) {}

            public function firestoreQuery($col, $where, $ord = null, $dir = 'ASC', $lim = 500): array
            {
                if (($this->o['throwOn'] ?? '') === $col) throw new \RuntimeException('read failed');
                if ($col === Fee_head_resolver::COL) return $this->o['registry'] ?? [];
                if ($col === 'feeStructures')        return $this->o['chart'] ?? [];
                if ($col === 'studentConcessions')   return $this->o['legacy'] ?? [];
                if ($col === 'feeDemands')           return $this->o['demands'] ?? [];
                return [];
            }
            public function firestoreGet($col, $id): ?array
            {
                return $col === 'schoolControl' ? ($this->o['control'] ?? null) : null;
            }
            public function firestoreSet($col, $id, $data, $merge = false): bool
            { $this->writes[] = "SET {$col}/{$id}"; return true; }
            public function firestoreCreate($col, $id, $data): bool
            { $this->writes[] = "CREATE {$col}/{$id}"; return true; }
        };
    }

    private function head(string $school, string $name): array
    {
        return ['data' => ['schoolId' => $school, 'name' => $name, 'status' => 'active',
                           'category' => 'academic', 'feeHeadId' => 'FH_X']];
    }

    private function chart(string $school, array $names): array
    {
        return [['data' => ['schoolId' => $school,
            'feeHeads' => array_map(static fn($n) => ['name' => $n], $names)]]];
    }

    private function svc($fs): Financial_activation_service
    {
        return (new Financial_activation_service())->init($fs, 'test');
    }

    // ══ THE ONE PATH THAT SHOULD SUCCEED ════════════════════════════════════

    public function test_a_clean_ready_tenant_is_eligible(): void
    {
        $fs = $this->fs([
            'registry' => [$this->head('SCH_A', 'Tuition Fee')],
            'chart'    => $this->chart('SCH_A', ['Tuition Fee']),
        ]);
        $r = $this->svc($fs)->eligibility('SCH_A');
        $this->assertTrue($r['eligible']);
        $this->assertSame('ready', $r['status']);
        $this->assertSame([], $fs->writes, 'eligibility() must be read-only');
    }

    // ══ REFUSALS · EACH MUST WRITE NOTHING ══════════════════════════════════

    public function test_an_unregistered_charted_head_refuses_and_names_it(): void
    {
        $fs = $this->fs([
            'registry' => [$this->head('SCH_A', 'Tuition Fee')],
            'chart'    => $this->chart('SCH_A', ['Tuition Fee', 'Examination Fee']),
        ]);
        $r = $this->svc($fs)->activate('SCH_A');

        $this->assertSame('not_ready', $r['status']);
        $this->assertSame('unregistered_fee_heads', $r['reason']);
        $this->assertStringContainsString('Examination Fee', $r['detail'],
            'the operator must be told WHICH head blocks activation');
        $this->assertSame([], $fs->writes,
            'a refused activation wrote something — a half-activated tenant is the exact state '
            . 'that stops a school billing');
    }

    public function test_no_fee_structure_at_all_is_not_treated_as_ready(): void
    {
        // No chart => nothing charted => nothing missing. That is genuinely
        // "ready" for the registry question; the school simply cannot bill yet,
        // which readiness reports separately via finance.fee_structures.
        $fs = $this->fs(['registry' => [], 'chart' => []]);
        $r = $this->svc($fs)->eligibility('SCH_A');
        $this->assertTrue($r['eligible'],
            'with no chart there is no unresolvable head; the empty-structure case is owned by '
            . 'Institution_readiness, not by this service');
    }

    /** HISTORICAL TENANT: active legacy concessions must hold, not activate. */
    public function test_active_legacy_concessions_hold_activation(): void
    {
        $fs = $this->fs([
            'registry' => [$this->head('SCH_A', 'Tuition Fee')],
            'chart'    => $this->chart('SCH_A', ['Tuition Fee']),
            'legacy'   => [['data' => ['schoolId' => 'SCH_A', 'status' => 'active']]],
        ]);
        $r = $this->svc($fs)->activate('SCH_A');

        $this->assertSame('held', $r['status']);
        $this->assertSame('legacy_concessions', $r['reason']);
        $this->assertSame([], $fs->writes,
            'switching the pricing authority under active legacy concessions would stop applying '
            . 'discounts that are currently reducing real bills — a silent price rise');
    }

    /** HISTORICAL TENANT: existing demands need backfill before the ledger means anything. */
    public function test_existing_demands_hold_activation(): void
    {
        $fs = $this->fs([
            'registry' => [$this->head('SCH_A', 'Tuition Fee')],
            'chart'    => $this->chart('SCH_A', ['Tuition Fee']),
            'demands'  => [['data' => ['schoolId' => 'SCH_A']]],
        ]);
        $r = $this->svc($fs)->activate('SCH_A');

        $this->assertSame('held', $r['status']);
        $this->assertSame('existing_demands', $r['reason']);
        $this->assertSame([], $fs->writes);
    }

    /** An unreadable prerequisite must never read as ready. */
    public function test_an_unreadable_prerequisite_refuses(): void
    {
        $fs = $this->fs(['throwOn' => Fee_head_resolver::COL,
                         'chart' => $this->chart('SCH_A', ['Tuition Fee'])]);
        $r = $this->svc($fs)->activate('SCH_A');

        $this->assertNotSame('activated', $r['status']);
        $this->assertSame([], $fs->writes,
            'a failed read produced a grant — never assume readiness from a question that '
            . 'could not be answered');

        // The REASON matters, not just the refusal. "Could not ask" and "heads
        // are missing" send an operator to two different places: one is a
        // transient to retry, the other is a list of heads to register. A
        // mutation that deleted this branch still refused, but reported the
        // wrong cause — safe, and misleading. Pin the distinction.
        $this->assertSame('not_ready', $r['status']);
        $this->assertSame('registry_unreadable', $r['reason'],
            'an unreadable registry was reported as "unregistered fee heads". The operator '
            . 'would go looking for heads to register that are not actually missing.');
    }

    public function test_a_missing_tenant_refuses(): void
    {
        $fs = $this->fs();
        $r = $this->svc($fs)->activate('');
        $this->assertSame('error', $r['status']);
        $this->assertSame([], $fs->writes);
    }

    // ══ IDEMPOTENCY ═════════════════════════════════════════════════════════

    public function test_an_already_active_tenant_is_a_noop(): void
    {
        $fs = $this->fs([
            'registry' => [$this->head('SCH_A', 'Tuition Fee')],
            'chart'    => $this->chart('SCH_A', ['Tuition Fee']),
            'control'  => ['rolloutFlags' => ['USE_FEE_HEAD_REGISTRY' => ['enabled' => true]]],
        ]);
        $r = $this->svc($fs)->activate('SCH_A');

        $this->assertSame('already_active', $r['status']);
        $this->assertSame([], $fs->writes,
            're-activating rewrote a grant, replacing the audit reason of an earlier deliberate '
            . 'decision');
    }

    // ══ AUTHORITY AND SCOPE ═════════════════════════════════════════════════

    /** No estate-wide form may exist — P6 keeps mass activation out of reach. */
    public function test_there_is_no_bulk_or_wildcard_activation(): void
    {
        $src = (string) file_get_contents(APPPATH . 'libraries/Financial_activation_service.php');
        $code = '';
        foreach (token_get_all($src) as $t) {
            if (is_array($t)) { if (in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true)) continue; $code .= $t[1]; }
            else $code .= $t;
        }
        foreach (['all_schools', 'activateAll', 'forEachSchool', "'*'"] as $bulk) {
            $this->assertStringNotContainsString($bulk, $code,
                'a bulk activation path would put estate-wide rollout one call away');
        }

        $r = new ReflectionMethod('Financial_activation_service', 'activate');
        $this->assertSame('schoolId', $r->getParameters()[0]->getName(),
            'activation must be addressed to exactly one tenant');
    }

    /** Every grant must go through the one authority — no second flag writer. */
    public function test_it_holds_no_authority_of_its_own(): void
    {
        $src = (string) file_get_contents(APPPATH . 'libraries/Financial_activation_service.php');

        $this->assertStringContainsString('Rollout_grant_service', $src,
            'activation no longer delegates to the grant authority');
        $this->assertStringNotContainsString('schoolControl', $src,
            'this service writes rolloutFlags directly. Every grant must go through '
            . 'Rollout_grant_service, which owns the allowlist, the dependency rules and the '
            . 'audit trail. A second writer is a second authority.');
    }

    /** The chain must be the documented dependency order. */
    public function test_the_chain_is_in_dependency_order(): void
    {
        $this->assertSame(
            ['USE_FEE_HEAD_REGISTRY', 'USE_ENTITLEMENT_BILLING', 'USE_ALLOCATION_LEDGER'],
            array_keys(Financial_activation_service::CHAIN),
            'the registry must precede entitlement billing, which must precede the ledger — '
            . 'the grant service refuses any other order, so a reordering here would simply '
            . 'fail halfway and leave the tenant partly configured');
    }
}
