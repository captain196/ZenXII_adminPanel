<?php

use PHPUnit\Framework\TestCase;

if (!defined('BASEPATH')) define('BASEPATH', __DIR__);
if (!defined('APPPATH'))  define('APPPATH', __DIR__ . '/../../application/');
if (!function_exists('log_message')) { function log_message($l, $m) {} }

require_once __DIR__ . '/../../application/libraries/Rollout_grant_service.php';
require_once __DIR__ . '/../../application/libraries/Rollout_flag_service.php';

/**
 * ══════════════════════════════════════════════════════════════════════════
 *  REVOKE IS NOT A KILL SWITCH ONCE A FLAG SHIPS ON
 * ══════════════════════════════════════════════════════════════════════════
 *
 * ── HOW THIS WAS FOUND ───────────────────────────────────────────────────
 * The SVC-1 proof revoked USE_ENTITLEMENT_SERVICE_PRICING for a tenant and
 * then billed a transport fare — expecting the full fare, because the
 * capability was supposed to be off. The fare came back DISCOUNTED.
 *
 * `revoke` DELETES the per-tenant entry. While every V2 default was false that
 * was indistinguishable from "off", so revoke read as a kill switch for years.
 * The moment the billing chain shipped ON by default, deleting the override
 * started meaning "fall back to the default" — which is now ENABLED.
 *
 * So an operator reaching for the obvious verb to disable a capability for one
 * school would have left it running, with the CLI reporting success. Nothing in
 * the codebase said otherwise, because nothing had ever needed to.
 *
 * ── THE DISTINCTION THIS PINS ────────────────────────────────────────────
 *   revoke → forget the decision   → tenant follows the global default
 *   hold   → reverse the decision  → explicit per-tenant false, beats the default
 *
 * Both verbs are correct and both are needed. What must never regress is the
 * belief that they are interchangeable.
 */
final class RolloutRevokeVsHoldTest extends TestCase
{
    private const SCHOOL = 'SCH_B56BB9A401';
    private const FLAG   = 'USE_ENTITLEMENT_SERVICE_PRICING';

    /** Firebase double: one school, records writes, replays the control doc. */
    private function fb(?array $control = null)
    {
        return new class($control) {
            public array $writes = [];
            public function __construct(private ?array $control) {}
            public function firestoreGet(string $c, string $id): ?array
            {
                if ($c === 'schools')       return ['id' => $id];
                if ($c === 'schoolControl') return $this->control;
                return null;
            }
            public function firestoreSet(string $c, string $id, array $d, bool $m = false): bool
            {
                $this->writes[] = $d;
                // TOP-LEVEL FIELD REPLACEMENT, not a deep merge.
                //
                // Both set() and revoke() read-modify-write the WHOLE rolloutFlags
                // map and send it under an updateMask naming that one field, so
                // Firestore replaces the field wholesale while leaving the rest of
                // the control document alone. (set() does this deliberately — a
                // previous incident had a granted flag DELETE its siblings.)
                //
                // Deep-merging here would silently resurrect the key revoke just
                // removed, and the revoke test would then "pass" for the wrong
                // reason — the double, not the code, deciding the outcome.
                $this->control = array_replace((array) $this->control, $d);
                return true;
            }
        };
    }

    private function svc($fb): Rollout_grant_service
    {
        return (new Rollout_grant_service())->init($fb, 'SUP0001');
    }

    private function resolver($fb): Rollout_flag_service
    {
        return (new Rollout_flag_service())->init($fb, self::SCHOOL);
    }

    // ══ THE DEFECT, REPRODUCED ══════════════════════════════════════════════

    /**
     * THE TEST THAT WOULD HAVE CAUGHT IT. With the flag ON globally, revoking
     * a tenant's override leaves the capability ENABLED for that tenant.
     */
    public function test_revoke_does_NOT_disable_a_flag_that_ships_on(): void
    {
        $fb = $this->fb(['rolloutFlags' => [self::FLAG => ['enabled' => true, 'reason' => 'granted']]]);

        $res = $this->svc($fb)->revoke(self::SCHOOL, self::FLAG, 'operator wants it off');
        $this->assertTrue($res['ok'], 'revoke itself should succeed — that is the trap');

        // globalDefault = true, i.e. the world after the 2026-08-30 flip.
        $this->assertTrue($this->resolver($fb)->isEnabled(self::FLAG, true),
            'revoke disabled a shipped-ON capability. If this ever passes as FALSE the verb has '
            . 'changed meaning, and every operator instruction saying "revoke to roll back" is '
            . 'now describing something else.');

        $this->assertSame('global', $this->resolver($fb)->resolve(self::FLAG, true)['source'],
            'after revoke the tenant must follow the GLOBAL default — that is what revoke means');
    }

    /** Under the OLD world (default off) revoke did look like a kill switch. */
    public function test_revoke_looked_like_a_kill_switch_only_because_defaults_were_off(): void
    {
        $fb = $this->fb(['rolloutFlags' => [self::FLAG => ['enabled' => true]]]);
        $this->svc($fb)->revoke(self::SCHOOL, self::FLAG, 'x');

        $this->assertFalse($this->resolver($fb)->isEnabled(self::FLAG, false),
            'with an OFF global default, revoke does disable — which is exactly why the verb was '
            . 'trusted, and exactly why the default flip changed its meaning silently');
    }

    // ══ THE VERB THAT ACTUALLY DISABLES ═════════════════════════════════════

    public function test_hold_writes_an_explicit_per_tenant_false(): void
    {
        $fb  = $this->fb();
        $res = $this->svc($fb)->set(self::SCHOOL, self::FLAG, false, 'hold this school back');

        $this->assertTrue($res['ok'], (string) ($res['error'] ?? ''));
        $this->assertCount(1, $fb->writes);
        $entry = $fb->writes[0]['rolloutFlags'][self::FLAG];
        $this->assertFalse($entry['enabled'], 'hold must persist enabled=false, not remove the key');
        $this->assertSame('hold this school back', $entry['reason'],
            'the reason is the audit trail for a deliberate rollback');
    }

    /** The property that makes hold usable: it beats an enabled global. */
    public function test_hold_beats_an_enabled_global_default(): void
    {
        $fb = $this->fb();
        $this->svc($fb)->set(self::SCHOOL, self::FLAG, false, 'rollback');

        $this->assertFalse($this->resolver($fb)->isEnabled(self::FLAG, true),
            'an explicitly held tenant was switched on by the global default. Without this, a '
            . 'capability could not be rolled back for ONE school without moving the default for '
            . 'every school.');
        $this->assertSame('tenant', $this->resolver($fb)->resolve(self::FLAG, true)['source']);
    }

    /** Hold then grant must restore — a rollback that cannot be undone is a trap. */
    public function test_a_held_tenant_can_be_restored(): void
    {
        $fb = $this->fb();
        $this->svc($fb)->set(self::SCHOOL, self::FLAG, false, 'rollback');
        $this->assertFalse($this->resolver($fb)->isEnabled(self::FLAG, true));

        $this->svc($fb)->set(self::SCHOOL, self::FLAG, true, 'restored after fix');
        $this->assertTrue($this->resolver($fb)->isEnabled(self::FLAG, true));
    }

    // ══ THE CLI MUST EXPOSE BOTH, AND SAY WHICH IS WHICH ════════════════════

    public function test_the_cli_exposes_hold_as_a_distinct_verb(): void
    {
        $cli = (string) file_get_contents(APPPATH . 'controllers/Rollout_grant_cli.php');

        $this->assertStringContainsString('public function hold(', $cli,
            'the CLI lost the only verb that can disable a shipped-ON capability for one tenant');
        $this->assertStringContainsString('public function revoke(', $cli);

        // hold must route to an explicit false, not to revoke().
        $this->assertMatchesRegularExpression('/set\(\$schoolId,\s*\$flag,\s*false,\s*\$reason\)/', $cli,
            'hold must persist an explicit per-tenant false');
    }

    /**
     * The distinction must be written down where an operator will meet it.
     * This defect was invisible precisely because nothing said the two verbs
     * differ once a default flips.
     */
    public function test_the_difference_is_documented_for_whoever_reaches_for_it(): void
    {
        $cli = (string) file_get_contents(APPPATH . 'controllers/Rollout_grant_cli.php');
        $this->assertStringContainsString('revoke` DELETES the per-tenant entry', $cli,
            'the reason hold exists must stay next to it — an operator choosing between two '
            . 'verbs needs to know that one of them stopped meaning what it used to');
    }
}
