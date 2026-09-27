<?php

use PHPUnit\Framework\TestCase;

if (!defined('BASEPATH')) define('BASEPATH', 'x');
if (!defined('APPPATH')) define('APPPATH', __DIR__ . '/../../application/');

/**
 * ══════════════════════════════════════════════════════════════════════════
 *  LEDGER-RESOLVE-1 · THE FLAG RESOLVER COULD NEVER RETURN TRUE
 * ══════════════════════════════════════════════════════════════════════════
 *
 * ── THE DEFECT ───────────────────────────────────────────────────────────
 * `Fee_generation_service::_allocationLedgerEnabled()` called
 *
 *     $CI->rolloutFlags->enabled($key, $schoolId, $global)
 *
 * `Rollout_flag_service` has no `enabled()` method and no `__call()`. Its
 * method is `isEnabled($key, $globalDefault)`, and it takes the tenant from
 * `init($firebase, $schoolId)` — which was never called either.
 *
 * So the line raised `Call to undefined method`, a blanket
 * `catch (\Throwable)` swallowed it, and the resolver returned FALSE. Every
 * time, for every tenant, regardless of any rollout grant.
 *
 * ── WHY IT WAS INVISIBLE ─────────────────────────────────────────────────
 * The failure value and the legitimate "this flag is off" value were the same
 * value. A fatal programming error was indistinguishable from configuration,
 * so the system looked exactly as it should while the feature was unreachable.
 * A per-tenant grant could be issued, recorded and audited, and change nothing.
 *
 * That is why no allocation row had ever been produced by the generation path,
 * and why the canary's two `n/a` checks could never have resolved.
 *
 * ── WHAT IS PINNED HERE ──────────────────────────────────────────────────
 * The method must exist on the service being called, the tenant must be bound
 * before the question is asked, and a resolver blow-up must still fail closed
 * but be LOUD rather than silent.
 *
 * `Fee_generation_service` needs the CI framework, so these are structural
 * assertions over the executable source plus a real contract check against
 * `Rollout_flag_service`, which is what actually caught the defect: the two
 * files disagreed and nothing compared them.
 */
final class LedgerFlagResolutionTest extends TestCase
{
    private static string $gen = '';

    public static function setUpBeforeClass(): void
    {
        $src = (string) file_get_contents(APPPATH . 'libraries/Fee_generation_service.php');
        $out = '';
        foreach (token_get_all($src) as $t) {
            if (is_array($t)) {
                if (in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true)) continue;
                $out .= $t[1];
            } else {
                $out .= $t;
            }
        }
        self::$gen = preg_replace("/(\r?\n)(?:[ \t]*\r?\n)+/", "\$1", $out);
    }

    // ══ THE CONTRACT THE TWO FILES MUST SHARE ═══════════════════════════════

    /**
     * THE TEST THAT WOULD HAVE CAUGHT IT.
     *
     * Every method the generator calls on the rollout service must actually
     * exist on that service. Nothing compared the two files, so they drifted.
     */
    public function test_every_rollout_method_the_generator_calls_exists(): void
    {
        require_once APPPATH . 'libraries/Rollout_flag_service.php';

        preg_match_all('/rolloutFlags\s*(?:->\s*\w+\s*\([^)]*\)\s*)*->\s*(\w+)\s*\(/',
            self::$gen, $m);
        $called = array_values(array_unique($m[1] ?? []));
        $this->assertNotEmpty($called, 'the generator no longer calls the rollout service at all');

        foreach ($called as $method) {
            $this->assertTrue(
                method_exists('Rollout_flag_service', $method),
                "Fee_generation_service calls rolloutFlags->{$method}(), which does not exist on "
                . 'Rollout_flag_service. Inside a catch(\\Throwable) that raises a fatal which is '
                . 'silently converted into "flag is off" — the feature becomes unreachable while '
                . 'looking correctly configured.'
            );
        }
    }

    /** The dead call must not come back. */
    public function test_the_nonexistent_enabled_method_is_not_called(): void
    {
        $this->assertStringNotContainsString('rolloutFlags->enabled(', self::$gen,
            'the generator calls rolloutFlags->enabled() again — a method that does not exist. '
            . 'It resolves to false for every tenant, so no rollout grant can ever take effect.');
    }

    /** The tenant must be bound before the flag is read. */
    public function test_the_tenant_is_bound_before_the_flag_is_read(): void
    {
        $fn = strpos(self::$gen, 'private function _allocationLedgerEnabled()');
        $this->assertNotFalse($fn, 'the ledger flag resolver was renamed or removed');
        $body = substr(self::$gen, $fn, 2200);

        $this->assertStringContainsString('->init($this->firebaseHandle(), $this->schoolId)', $body,
            'the rollout service is queried without init(), so it has no tenant and no firebase '
            . 'handle — every per-tenant grant would be invisible to it');

        $init = strpos($body, '->init(');
        $ask  = strpos($body, '->isEnabled(');
        $this->assertNotFalse($ask, 'isEnabled() is no longer called');
        $this->assertLessThan($ask, $init, 'the flag is read before the tenant is bound');
    }

    /** `isEnabled` takes (key, globalDefault) — the schoolId is NOT an argument. */
    public function test_the_call_matches_the_service_signature(): void
    {
        $this->assertStringContainsString("->isEnabled('USE_ALLOCATION_LEDGER', \$global)", self::$gen,
            'the isEnabled() call does not match the service signature (key, globalDefault). '
            . 'Passing the schoolId as an argument is what the original defect did.');
    }

    // ══ FAILURE MUST BE LOUD, NOT SILENT ════════════════════════════════════

    /**
     * A resolver blow-up must still fail closed — never emit an unexplained
     * ledger row — but it must be greppable. A silent false is what hid this
     * defect for its entire life.
     */
    public function test_a_resolver_failure_is_logged_not_swallowed(): void
    {
        $fn   = strpos(self::$gen, 'private function _allocationLedgerEnabled()');
        $body = substr(self::$gen, $fn, 2200);

        $this->assertStringContainsString('LEDGER_FLAG_UNRESOLVED', $body,
            'a resolver failure is silent again. The failure value and the legitimate '
            . '"flag is off" value are identical, so nothing distinguishes a fatal from '
            . 'configuration.');

        // Still fail closed.
        $this->assertStringContainsString('return false;', $body,
            'the resolver no longer fails closed on error');

        // The blanket catch that returned bare false must not be the only handler.
        $this->assertStringNotContainsString("catch (\\Throwable \$_) {\n            return false;\n        }", $body,
            'the silent blanket catch is back');
    }

    // ══ BEHAVIOURAL · THE RESOLVER ACROSS TWO TENANTS ═══════════════════════

    /** Firebase double returning a per-school control document. */
    private function reader(array $bySchool)
    {
        return new class($bySchool) {
            public function __construct(private array $m) {}
            public function firestoreGet($col, $id): ?array { return $this->m[$id] ?? null; }
        };
    }

    private function grant(bool $on): array
    {
        return ['rolloutFlags' => ['USE_ALLOCATION_LEDGER' => ['enabled' => $on]]];
    }

    /**
     * THE CLAIM THE WHOLE CANARY RESTS ON: a granted tenant resolves TRUE while
     * the global default is false. Before LEDGER-RESOLVE-1 this was impossible —
     * the resolver returned false for everyone — so a grant could be issued,
     * recorded and audited and still change nothing.
     */
    public function test_a_granted_tenant_resolves_true_while_the_global_stays_false(): void
    {
        require_once APPPATH . 'libraries/Rollout_flag_service.php';

        $fb = $this->reader([
            'SCH_A' => $this->grant(true),
            'SCH_B' => null,               // no control doc at all — a new tenant
        ]);

        $a = (new Rollout_flag_service())->init($fb, 'SCH_A');
        $this->assertTrue($a->isEnabled('USE_ALLOCATION_LEDGER', false),
            'a granted tenant does not resolve true, so the per-tenant grant is inert and the '
            . 'canary proved nothing');
        $this->assertSame('tenant', $a->resolve('USE_ALLOCATION_LEDGER', false)['source']);
    }

    /** TENANT ISOLATION: one school's grant must not leak into another. */
    public function test_one_tenants_grant_does_not_enable_another(): void
    {
        require_once APPPATH . 'libraries/Rollout_flag_service.php';

        $fb = $this->reader(['SCH_A' => $this->grant(true), 'SCH_B' => null]);

        $b = (new Rollout_flag_service())->init($fb, 'SCH_B');
        $this->assertFalse($b->isEnabled('USE_ALLOCATION_LEDGER', false),
            "school B inherited school A's grant — a per-tenant rollout that leaks across "
            . 'tenants is worse than no rollout control at all');
        $this->assertSame('global', $b->resolve('USE_ALLOCATION_LEDGER', false)['source']);
    }

    /**
     * The cache must be invalidated when the bound tenant changes. A single
     * process prices for more than one school, and a stale cache would carry
     * one tenant's answer into the next.
     */
    public function test_rebinding_to_another_tenant_does_not_reuse_the_first_answer(): void
    {
        require_once APPPATH . 'libraries/Rollout_flag_service.php';

        $fb  = $this->reader(['SCH_A' => $this->grant(true), 'SCH_B' => $this->grant(false)]);
        $svc = new Rollout_flag_service();

        $this->assertTrue($svc->init($fb, 'SCH_A')->isEnabled('USE_ALLOCATION_LEDGER', false));
        $this->assertFalse($svc->init($fb, 'SCH_B')->isEnabled('USE_ALLOCATION_LEDGER', false),
            'the previous tenant\'s answer was reused after rebinding — in a multi-tenant '
            . 'process that silently applies one school\'s configuration to another');
    }

    /** An explicit per-tenant OFF must beat an enabled global default. */
    public function test_a_tenant_can_be_held_back_from_an_enabled_global(): void
    {
        require_once APPPATH . 'libraries/Rollout_flag_service.php';

        $fb = $this->reader(['SCH_A' => $this->grant(false)]);
        $this->assertFalse(
            (new Rollout_flag_service())->init($fb, 'SCH_A')->isEnabled('USE_ALLOCATION_LEDGER', true),
            'a tenant explicitly held back was switched on by the global default — the '
            . 'per-tenant kill switch has to win, or a global rollout cannot be paused for '
            . 'one school that needs a backfill first');
    }

    /** A missing rollout library is configuration, not failure. */
    public function test_an_absent_rollout_service_falls_back_to_the_global_default(): void
    {
        $fn   = strpos(self::$gen, 'private function _allocationLedgerEnabled()');
        $body = substr(self::$gen, $fn, 2200);

        // LEDGER-EMIT-1 (2026-08-29): the fallback still yields the GLOBAL DEFAULT
        // rather than a hard false — but it is now routed through the authority
        // gate, because a global default of true is just as unbackable under a
        // pricing authority that carries no grant identity as a tenant grant is.
        // The property under test is "the global default is the whole answer",
        // not the literal spelling of the return.
        $this->assertStringContainsString('_ledgerBackedByAuthority($global)', $body,
            'without the rollout library the global default IS the whole answer; returning '
            . 'false instead would ignore a legitimately enabled global configuration');
        $this->assertStringNotContainsString('return false; // global', $body);
    }
}
