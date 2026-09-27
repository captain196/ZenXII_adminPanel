<?php

use PHPUnit\Framework\TestCase;

if (!defined('BASEPATH')) define('BASEPATH', __DIR__);
if (!defined('APPPATH'))  define('APPPATH', __DIR__ . '/../../application/');
if (!function_exists('log_message')) { function log_message($l, $m) {} }

require_once __DIR__ . '/../../application/libraries/Rollout_grant_service.php';
require_once __DIR__ . '/../../application/libraries/Rollout_flag_service.php';

/**
 * T3 — the Superadmin rollout-grant mechanism.
 *
 * Infrastructure only: **no grant has been created**. The last group of tests
 * pins that, so T4 (the first real production write) cannot happen by accident.
 *
 * The writer is a SEPARATE class from the resolver on purpose: the resolver
 * runs inside fee generation on every request and is asserted to contain no
 * write verb at all. Only this cold, superadmin-only path can mutate.
 */
class T3RolloutGrantTest extends TestCase
{
    private const UAT = 'SCH_B56BB9A401';

    /** Firebase double: knows which schools exist, records writes. */
    private function fb(array $schools = [self::UAT], ?array $control = null, bool $throw = false)
    {
        return new class($schools, $control, $throw) {
            public array $writes = [];
            public function __construct(private array $schools, private ?array $control, private bool $throw) {}
            public function firestoreGet(string $c, string $id): ?array
            {
                if ($this->throw) throw new \RuntimeException('read failed');
                if ($c === 'schools')       return in_array($id, $this->schools, true) ? ['id' => $id] : null;
                if ($c === 'schoolControl') return $this->control;
                return null;
            }
            public function firestoreSet(string $c, string $id, array $d, bool $m = false): bool
            {
                $this->writes[] = ['col' => $c, 'id' => $id, 'data' => $d, 'merge' => $m];
                return true;
            }
        };
    }

    private function svc($fb): Rollout_grant_service
    {
        return (new Rollout_grant_service())->init($fb, 'SUP0001');
    }

    private function code(string $rel): string
    {
        $out = '';
        foreach (token_get_all(file_get_contents(APPPATH . $rel)) as $t) {
            $out .= is_array($t) ? (in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true) ? ' ' : $t[1]) : $t;
        }
        return $out;
    }

    // ══ A VALID GRANT ════════════════════════════════════════════════════

    public function test_a_valid_grant_writes_one_nested_key_by_merge(): void
    {
        $fb  = $this->fb();
        $res = $this->svc($fb)->set(self::UAT, 'USE_FEE_HEAD_REGISTRY', true, 'M5 UAT gate');

        $this->assertTrue($res['ok'], (string) ($res['error'] ?? ''));
        $this->assertCount(1, $fb->writes);
        $w = $fb->writes[0];
        $this->assertSame('schoolControl', $w['col']);
        $this->assertSame(self::UAT, $w['id']);
        $this->assertTrue($w['merge'], 'must MERGE — never replace the control document');
        $this->assertSame(['rolloutFlags'], array_keys($w['data']),
            'only rolloutFlags may be sent; subscription/overrides/featureFlags stay untouched');
        $this->assertSame(['USE_FEE_HEAD_REGISTRY'], array_keys($w['data']['rolloutFlags']));

        $e = $w['data']['rolloutFlags']['USE_FEE_HEAD_REGISTRY'];
        $this->assertTrue($e['enabled']);
        $this->assertSame('SUP0001', $e['setBy']);
        $this->assertSame('M5 UAT gate', $e['reason']);
        $this->assertNull($e['expiresAt'], 'open-ended by default — a soak must not silently lapse');
    }

    /** What the writer produces must be exactly what the resolver consumes. */
    public function test_the_written_entry_is_readable_by_the_resolver(): void
    {
        $fb = $this->fb();
        $this->svc($fb)->set(self::UAT, 'USE_FEE_HEAD_REGISTRY', true, 'M5 UAT gate');
        $written = $fb->writes[0]['data'];

        $reader = new class($written) {
            public function __construct(private array $d) {}
            public function firestoreGet($c, $id): ?array { return $this->d; }
        };
        $resolver = (new Rollout_flag_service())->init($reader, self::UAT);
        $this->assertTrue($resolver->isEnabled('USE_FEE_HEAD_REGISTRY', false),
            'writer and resolver must agree on the shape');
        $this->assertSame('tenant', $resolver->resolve('USE_FEE_HEAD_REGISTRY', false)['source']);
    }

    public function test_a_disable_grant_is_a_per_tenant_kill_switch(): void
    {
        $fb = $this->fb();
        $this->svc($fb)->set(self::UAT, 'USE_ENTITLEMENT_BILLING', false, 'hold this school back');
        $this->assertFalse($fb->writes[0]['data']['rolloutFlags']['USE_ENTITLEMENT_BILLING']['enabled']);
    }

    // ══ VALIDATION — every refusal writes NOTHING ════════════════════════

    public function test_refusals_never_write(): void
    {
        $cases = [
            'unknown flag'      => [self::UAT, 'USE_BILLING_V2',        true, 'x',  null],
            'empty school'      => ['',        'USE_FEE_HEAD_REGISTRY', true, 'x',  null],
            'malformed school'  => ['nope',    'USE_FEE_HEAD_REGISTRY', true, 'x',  null],
            'no reason'         => [self::UAT, 'USE_FEE_HEAD_REGISTRY', true, '',   null],
            'past expiry'       => [self::UAT, 'USE_FEE_HEAD_REGISTRY', true, 'x',  '2020-01-01'],
            'unparsable expiry' => [self::UAT, 'USE_FEE_HEAD_REGISTRY', true, 'x',  'soon'],
        ];
        foreach ($cases as $label => [$school, $flag, $on, $reason, $exp]) {
            $fb  = $this->fb();
            $res = $this->svc($fb)->set($school, $flag, $on, $reason, $exp);
            $this->assertFalse($res['ok'], "{$label} must be refused");
            $this->assertSame([], $fb->writes, "{$label} must not write");
        }
    }

    /** A typo'd school must not create a control document for a ghost tenant. */
    public function test_a_nonexistent_school_is_refused(): void
    {
        $fb  = $this->fb([self::UAT]);
        $res = $this->svc($fb)->set('SCH_GHOST', 'USE_FEE_HEAD_REGISTRY', true, 'oops');
        $this->assertFalse($res['ok']);
        $this->assertStringContainsString('No such school', $res['error']);
        $this->assertSame([], $fb->writes);
    }

    public function test_a_read_failure_refuses_rather_than_guessing(): void
    {
        $fb  = $this->fb([self::UAT], null, true);
        $res = $this->svc($fb)->set(self::UAT, 'USE_FEE_HEAD_REGISTRY', true, 'x');
        $this->assertFalse($res['ok']);
        $this->assertSame([], $fb->writes);
    }

    /** The grantable vocabulary is exactly the four gates routed at T2. */
    public function test_the_grantable_set_matches_the_routed_gates(): void
    {
        // The rule this pins: a flag is grantable ONLY if code actually reads
        // it. Granting a flag nothing routes produces an inert grant that looks
        // live on screen. USE_ALLOCATION_LEDGER was added 2026-08-18 and IS
        // routed — Fee_generation_service::_allocationLedgerEnabled() resolves
        // it through Rollout_flag_service, the same path as the other four.
        $this->assertSame([
            'USE_FEE_HEAD_REGISTRY',
            'USE_ENTITLEMENT_BILLING',
            'USE_ENTITLEMENT_SERVICE_PRICING',
            'ENTITLEMENT_SHADOW_COMPARE',
            'USE_ALLOCATION_LEDGER',
        ], Rollout_grant_service::GRANTABLE);

        // Enforce the rule rather than just the list: every grantable flag must
        // appear in a resolver call somewhere in the codebase.
        foreach (Rollout_grant_service::GRANTABLE as $flag) {
            $found = false;
            foreach (array_merge(glob(APPPATH . 'libraries/*.php'),
                                 glob(APPPATH . 'controllers/*.php')) as $f) {
                if (basename($f) === 'Rollout_grant_service.php') continue;
                if (strpos(file_get_contents($f), $flag) !== false) { $found = true; break; }
            }
            $this->assertTrue($found, "grantable flag '{$flag}' is read by no code — it would be an inert grant");
        }
    }

    public function test_a_future_expiry_is_accepted(): void
    {
        $fb  = $this->fb();
        $res = $this->svc($fb)->set(self::UAT, 'USE_FEE_HEAD_REGISTRY', true, 'canary', '2099-01-01');
        $this->assertTrue($res['ok']);
        $this->assertNotNull($fb->writes[0]['data']['rolloutFlags']['USE_FEE_HEAD_REGISTRY']['expiresAt']);
    }

    // ══ ONE TENANT PER CALL ═════════════════════════════════════════════

    /** No bulk/wildcard path — estate-wide activation must never be one call. */
    public function test_there_is_no_bulk_or_wildcard_grant(): void
    {
        $code = $this->code('libraries/Rollout_grant_service.php');
        foreach (['foreach', 'all_schools', 'grantAll', "'*'"] as $bulk) {
            $this->assertStringNotContainsString($bulk, $code,
                'a bulk grant path would put estate-wide activation one call away');
        }
        $r = new ReflectionMethod(Rollout_grant_service::class, 'set');
        $this->assertSame('schoolId', $r->getParameters()[0]->getName());
        $this->assertSame('string',   (string) $r->getParameters()[0]->getType());
    }

    // ══ REVOKE ══════════════════════════════════════════════════════════

    public function test_revoke_removes_the_key_so_the_global_default_returns(): void
    {
        $fb = $this->fb([self::UAT], ['rolloutFlags' => [
            'USE_FEE_HEAD_REGISTRY' => ['enabled' => true],
            'ENTITLEMENT_SHADOW_COMPARE' => ['enabled' => true],
        ]]);
        $res = $this->svc($fb)->revoke(self::UAT, 'USE_FEE_HEAD_REGISTRY', 'soak finished');

        $this->assertTrue($res['ok']);
        $map = $fb->writes[0]['data']['rolloutFlags'];
        $this->assertArrayNotHasKey('USE_FEE_HEAD_REGISTRY', $map);
        $this->assertArrayHasKey('ENTITLEMENT_SHADOW_COMPARE', $map,
            'revoking one flag must not drop another');
        $this->assertSame(['rolloutFlags'], array_keys($fb->writes[0]['data']));
    }

    public function test_revoking_something_never_granted_is_a_noop(): void
    {
        $fb  = $this->fb([self::UAT], ['rolloutFlags' => []]);
        $res = $this->svc($fb)->revoke(self::UAT, 'USE_FEE_HEAD_REGISTRY', 'tidy up');
        $this->assertTrue($res['ok']);
        $this->assertTrue($res['noop']);
        $this->assertSame([], $fb->writes);
    }

    public function test_revoke_requires_a_reason_and_a_known_flag(): void
    {
        $fb = $this->fb([self::UAT], ['rolloutFlags' => ['USE_FEE_HEAD_REGISTRY' => ['enabled' => true]]]);
        $this->assertFalse($this->svc($fb)->revoke(self::UAT, 'USE_FEE_HEAD_REGISTRY', '')['ok']);
        $this->assertFalse($this->svc($fb)->revoke(self::UAT, 'NOPE', 'x')['ok']);
        $this->assertSame([], $fb->writes);
    }

    // ══ THE OPERATOR SURFACE ════════════════════════════════════════════

    public function test_the_controller_is_superadmin_only_and_separate_from_plans(): void
    {
        $src = file_get_contents(APPPATH . 'controllers/Superadmin_rollout.php');
        $this->assertStringContainsString('extends MY_Superadmin_Controller', $src);

        // Executable code only — the docblock legitimately NAMES the commercial
        // mirror while explaining why rollout is kept apart from it.
        $code = $this->code('controllers/Superadmin_rollout.php');
        $this->assertStringNotContainsString('activeModules', $code,
            'rollout must never touch the commercial entitlement mirror');
        $this->assertStringNotContainsString('subscription', $code);
        $this->assertStringNotContainsString('tenantPublic', $code);
    }

    /** The CSRF trap: a new superadmin POST without an exclusion is a blank 403. */
    public function test_the_routes_and_csrf_exclusion_exist(): void
    {
        $routes = file_get_contents(APPPATH . 'config/routes.php');
        foreach (['for_school', 'set', 'revoke'] as $ep) {
            $this->assertStringContainsString("superadmin/rollout/{$ep}", $routes);
        }
        $this->assertStringContainsString("'superadmin/rollout(.*)'",
            file_get_contents(APPPATH . 'config/config.php'),
            'without this the endpoints return a blank 403 — no log, no console error');
    }

    /** `enabled` must be explicit — a switch must not flip on an omitted field. */
    public function test_enabled_must_be_supplied_explicitly(): void
    {
        $this->assertStringContainsString('must be supplied explicitly',
            file_get_contents(APPPATH . 'controllers/Superadmin_rollout.php'));
    }

    // ══ THE RESOLVER IS STILL READ-ONLY ═════════════════════════════════

    public function test_the_resolver_still_contains_no_write_verb(): void
    {
        $code = $this->code('libraries/Rollout_flag_service.php');
        foreach (['firestoreSet', 'firestoreUpdate', 'firestoreCreate', 'firestoreDelete'] as $v) {
            $this->assertStringNotContainsString($v, $code,
                'the hot path must remain incapable of writing — that is why T3 is a separate class');
        }
    }

    // ══ T4 HAS NOT HAPPENED ═════════════════════════════════════════════

    /**
     * Infrastructure only. No caller invokes the grant service outside the
     * superadmin controller, and no script or migration creates a grant.
     */
    public function test_no_grant_is_created_anywhere_in_the_codebase(): void
    {
        $callers = [];
        foreach (array_merge(
            glob(APPPATH . 'controllers/*.php'), glob(APPPATH . 'libraries/*.php'),
            glob(APPPATH . 'core/*.php'),        glob(APPPATH . 'helpers/*.php'),
            glob(APPPATH . 'services/*.php'),    glob(__DIR__ . '/../../scripts/*.php')
        ) as $f) {
            $b = basename($f);
            // ── T4 HAS NOW HAPPENED, WITH AUTHORIZATION (2026-08-29) ────────
            //
            // This asserted ZERO callers, on the premise that "T4 — creating an
            // actual tenant grant — has not happened". It has: the operator
            // authorized a canary grant of USE_ALLOCATION_LEDGER for one tenant
            // after its historical backfill certified clean.
            //
            // The freeze is kept, not deleted, because the property worth
            // protecting was never "nobody grants" — it was "granting is a
            // deliberate act through a small, enumerable set of surfaces". So
            // the allowlist stays short and explicit:
            //
            //   Superadmin_rollout  — the HTTP surface, superadmin session
            //   Rollout_grant_cli   — the CLI front door, --commit required
            //
            // Neither contains grant logic; both delegate every rule to
            // Rollout_grant_service. A THIRD caller appearing here is scope
            // creep and should fail this test.
            if (in_array($b, ['Rollout_grant_service.php', 'Superadmin_rollout.php',
                              'Rollout_grant_cli.php',
                              // ONBOARD-LEDGER-1: onboarding grants the ledger to a
                              // BRAND-NEW tenant, which provably has no history to
                              // migrate. Delegates to the service like the other two.
                              'Superadmin_schools.php',
                              // DAY-ONE-3 (2026-08-29): the shared decision behind
                              // go-live activation. It is a LIBRARY, not a surface,
                              // and it is the reason the allowlist did not grow by
                              // two: Superadmin_schools (go-live) and
                              // Rollout_grant_cli (operator front door) both call
                              // it instead of each carrying their own copy of
                              // "is this tenant ready, and what does it get?".
                              //
                              // The property being protected is unchanged. It holds
                              // no authority of its own, delegates every grant and
                              // every dependency rule to Rollout_grant_service, is
                              // strictly single-tenant with no estate-wide form, and
                              // is reachable only from the two surfaces already
                              // enumerated above. Nothing school-facing may call it —
                              // a school granting its own tenant's platform
                              // capabilities is exactly what this freeze exists to
                              // prevent, and that remains impossible.
                              'Financial_activation_service.php'], true)) continue;
            if (strpos(file_get_contents($f), 'Rollout_grant_service') !== false) $callers[] = $b;
        }
        $this->assertSame([], array_unique($callers),
            'a new caller of Rollout_grant_service appeared: ' . implode(', ', array_unique($callers))
            . '. Issuing a tenant grant must stay confined to the superadmin HTTP surface and '
            . 'the CLI front door, both of which delegate every rule to the service.');
    }

    // ══ DEC-006 · ENTITLEMENT BILLING REQUIRES M5 ════════════════════════

    /**
     * I3 (a blanket concession never nets against a service fee) is enforced
     * against the DEMAND's category, which exists only because M5 stamped it.
     * Granting billing without M5 would let an `all` concession discount
     * transport — a real leak, since the UAT chart has a Transport Fee row.
     */
    public function test_DEC006_billing_refused_without_an_active_M5_grant(): void
    {
        $cases = [
            'no control doc'   => null,
            'no rolloutFlags'  => ['rolloutFlags' => []],
            'M5 explicitly off'=> ['rolloutFlags' => ['USE_FEE_HEAD_REGISTRY' => ['enabled' => false]]],
            'M5 grant expired' => ['rolloutFlags' => ['USE_FEE_HEAD_REGISTRY' =>
                                    ['enabled' => true, 'expiresAt' => '2020-01-01T00:00:00Z']]],
        ];
        foreach ($cases as $label => $ctl) {
            $fb  = $this->fb([self::UAT], $ctl);
            $res = $this->svc($fb)->set(self::UAT, 'USE_ENTITLEMENT_BILLING', true, 'try');
            $this->assertFalse($res['ok'], "{$label}: billing must require an ACTIVE M5 grant");
            $this->assertStringContainsString('Fee Head Registry', $res['error']);
            $this->assertSame([], $fb->writes, "{$label}: a refusal must never write");
        }
    }

    public function test_DEC006_billing_allowed_once_M5_is_granted(): void
    {
        $fb = $this->fb([self::UAT], ['rolloutFlags' => [
            'USE_FEE_HEAD_REGISTRY' => ['enabled' => true, 'expiresAt' => null]]]);
        $res = $this->svc($fb)->set(self::UAT, 'USE_ENTITLEMENT_BILLING', true, 'SE-V2 UAT');
        $this->assertTrue($res['ok'], (string) ($res['error'] ?? ''));
        $this->assertCount(1, $fb->writes);
    }

    public function test_DEC006_a_bare_boolean_M5_grant_satisfies_it(): void
    {
        $fb = $this->fb([self::UAT], ['rolloutFlags' => ['USE_FEE_HEAD_REGISTRY' => true]]);
        $this->assertTrue($this->svc($fb)->set(self::UAT, 'USE_ENTITLEMENT_BILLING', true, 'x')['ok']);
    }

    /** The rule must not leak onto other flags, or onto DISABLING billing. */
    public function test_DEC006_rule_is_scoped_to_enabling_billing_only(): void
    {
        $this->assertTrue($this->svc($this->fb([self::UAT], null))
            ->set(self::UAT, 'USE_FEE_HEAD_REGISTRY', true, 'm5')['ok']);
        $this->assertTrue($this->svc($this->fb([self::UAT], null))
            ->set(self::UAT, 'ENTITLEMENT_SHADOW_COMPARE', true, 'shadow')['ok']);
        $this->assertTrue($this->svc($this->fb([self::UAT], null))
            ->set(self::UAT, 'USE_ENTITLEMENT_BILLING', false, 'kill switch')['ok'],
            'disabling billing must never be blocked by the dependency');
    }

    /** Model A only — the READER was deliberately left untouched. */
    public function test_DEC006_no_reader_side_gate_was_added(): void
    {
        $reader = file_get_contents(APPPATH . 'libraries/Fee_entitlement_reader.php');
        $this->assertStringContainsString('$feeHeadId  !== \'\' && $target === $feeHeadId', $reader,
            'legacy tier 2 must remain — Model B would have retired it by side effect');
        $this->assertStringContainsString('$feeHead    !== \'\' && $target === $feeHead', $reader);

        $gen = file_get_contents(APPPATH . 'libraries/Fee_generation_service.php');
        $this->assertStringNotContainsString("categorySource", $gen,
            'no eligibility gate was introduced in the pricing path');
    }

    public function test_every_global_default_is_still_off(): void
    {
        // SUPERSEDED 2026-08-30 (owner decision): ships ON so a new school needs
        // no grant; safe via the Fee_lifecycle capability guards. See
        // BillingV2RollbackVerificationTest for the guard assertions.
        $this->assertStringContainsString("\$config['USE_FEE_HEAD_REGISTRY'] = true",
            file_get_contents(APPPATH . 'config/fee_head_registry.php'));
        // SUPERSEDED 2026-08-30: Phase D ships ON after SVC-1 was reproduced and
        // closed (scripts/service_entitlement_proof.js, 16/16). Its old
        // prerequisite was a LEGACY migration that a new school never has.
        foreach (['ENTITLEMENT_SHADOW_COMPARE'] as $f) {
            $this->assertStringContainsString("\$config['{$f}'] = false",
                file_get_contents(APPPATH . 'config/fees_exemption_v2_flags.php'));
        }
    }
}
