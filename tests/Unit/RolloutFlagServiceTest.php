<?php

use PHPUnit\Framework\TestCase;

if (!defined('BASEPATH')) define('BASEPATH', __DIR__);
if (!defined('APPPATH'))  define('APPPATH', __DIR__ . '/../../application/');
if (!function_exists('log_message')) { function log_message($l, $m) {} }

require_once __DIR__ . '/../../application/libraries/Rollout_flag_service.php';

/**
 * T1 — the tenant-scoped rollout flag resolver.
 *
 * `enabled = tenantOverride ?? globalDefault`, failing to the GLOBAL DEFAULT
 * (which for every flag in this programme is false — today's legacy path)
 * whenever anything is missing, malformed, expired or unreadable.
 *
 * This phase is infrastructure only: nothing in production calls the service,
 * and the last two tests pin that so T2 cannot happen by accident.
 */
class RolloutFlagServiceTest extends TestCase
{
    private const KEY = 'USE_FEE_HEAD_REGISTRY';
    private const UAT = 'SCH_B56BB9A401';

    /** Firebase double — counts reads, can be made to throw. */
    private function fb(?array $doc, bool $throw = false)
    {
        return new class($doc, $throw) {
            public int $reads = 0;
            public function __construct(private ?array $doc, private bool $throw) {}
            public function firestoreGet(string $c, string $id): ?array
            {
                $this->reads++;
                if ($this->throw) throw new \RuntimeException('transport failure');
                return $this->doc;
            }
        };
    }

    private function svc($fb, string $school = self::UAT): Rollout_flag_service
    {
        return (new Rollout_flag_service())->init($fb, $school);
    }

    /** A control doc carrying one rollout override. */
    private function control($row): array
    {
        return ['schoolId' => self::UAT, 'subscription' => ['addOns' => []],
                'rolloutFlags' => [self::KEY => $row]];
    }

    // ══ THE HAPPY PATH ═══════════════════════════════════════════════════

    public function test_a_tenant_override_turns_a_globally_off_flag_ON(): void
    {
        $s = $this->svc($this->fb($this->control([
            'enabled' => true, 'setBy' => 'SUP0001', 'setAt' => '2026-08-18T10:00:00Z',
            'reason' => 'M5 UAT gate', 'expiresAt' => null,
        ])));

        $this->assertTrue($s->isEnabled(self::KEY, false));
        $r = $s->resolve(self::KEY, false);
        $this->assertSame('tenant', $r['source']);
        $this->assertSame('M5 UAT gate', $r['reason']);
    }

    /** The override wins in BOTH directions — a per-tenant kill switch. */
    public function test_a_tenant_override_can_also_turn_a_globally_on_flag_OFF(): void
    {
        $s = $this->svc($this->fb($this->control(['enabled' => false])));
        $this->assertFalse($s->isEnabled(self::KEY, true));
        $this->assertSame('tenant', $s->resolve(self::KEY, true)['source']);
    }

    public function test_a_bare_boolean_override_is_honoured(): void
    {
        $this->assertTrue($this->svc($this->fb($this->control(true)))->isEnabled(self::KEY, false));
        $this->assertFalse($this->svc($this->fb($this->control(false)))->isEnabled(self::KEY, true));
    }

    // ══ FAIL CLOSED — always to the caller's global default ══════════════

    public function test_every_failure_mode_falls_back_to_the_global_default(): void
    {
        $cases = [
            'no document'          => $this->fb(null),
            'no rolloutFlags'      => $this->fb(['schoolId' => self::UAT]),
            'rolloutFlags not map' => $this->fb(['rolloutFlags' => 'nonsense']),
            'read throws'          => $this->fb(null, true),
            'malformed row'        => $this->fb($this->control(['note' => 'no enabled key'])),
            'enabled not bool'     => $this->fb($this->control(['enabled' => 'yes'])),
            'row is a string'      => $this->fb($this->control('true')),
        ];
        foreach ($cases as $label => $fb) {
            $s = $this->svc($fb);
            $this->assertFalse($s->isEnabled(self::KEY, false), "{$label}: OFF default must stay OFF");
            $this->assertTrue($s->isEnabled(self::KEY, true),   "{$label}: must fall back, not force OFF");
            $this->assertSame('global', $s->resolve(self::KEY, false)['source'], $label);
        }
    }

    /** "Fail closed" means fall back to legacy — never abort, never throw. */
    public function test_an_unreadable_control_document_never_throws(): void
    {
        $s = $this->svc($this->fb(null, true));
        $this->assertFalse($s->isEnabled(self::KEY, false));
        $this->assertSame([], $s->all());
    }

    public function test_an_unknown_or_empty_key_uses_the_global_default(): void
    {
        $s = $this->svc($this->fb($this->control(['enabled' => true])));
        $this->assertFalse($s->isEnabled('SOME_OTHER_FLAG', false));
        $this->assertFalse($s->isEnabled('', false));
        $this->assertTrue($s->isEnabled('SOME_OTHER_FLAG', true));
    }

    // ══ EXPIRY — a grant that has done its job ═══════════════════════════

    public function test_an_expired_grant_reverts_to_the_global_default(): void
    {
        $s = $this->svc($this->fb($this->control([
            'enabled' => true, 'expiresAt' => '2020-01-01T00:00:00Z',
        ])));
        $this->assertFalse($s->isEnabled(self::KEY, false));
        $this->assertSame('expired', $s->resolve(self::KEY, false)['reason']);
    }

    public function test_a_future_expiry_still_applies(): void
    {
        $s = $this->svc($this->fb($this->control([
            'enabled' => true, 'expiresAt' => '2099-01-01T00:00:00Z',
        ])));
        $this->assertTrue($s->isEnabled(self::KEY, false));
    }

    public function test_an_unparsable_expiry_is_treated_as_expired(): void
    {
        $s = $this->svc($this->fb($this->control(['enabled' => true, 'expiresAt' => 'soon'])));
        $this->assertFalse($s->isEnabled(self::KEY, false),
            'an expiry that cannot be read must not extend a grant indefinitely');
        $this->assertSame('unparsable_expiry', $s->resolve(self::KEY, false)['reason']);
    }

    public function test_an_empty_or_null_expiry_means_no_expiry(): void
    {
        foreach ([null, ''] as $exp) {
            $s = $this->svc($this->fb($this->control(['enabled' => true, 'expiresAt' => $exp])));
            $this->assertTrue($s->isEnabled(self::KEY, false));
        }
    }

    // ══ TENANT ISOLATION + READ COST ═════════════════════════════════════

    /** One tenant's rollout state must never leak into another's. */
    public function test_re_init_with_a_different_tenant_clears_the_memo(): void
    {
        $fb = $this->fb($this->control(['enabled' => true]));
        $s  = $this->svc($fb);
        $this->assertTrue($s->isEnabled(self::KEY, false));
        $this->assertSame(1, $fb->reads);

        $s->init($this->fb(null), 'SCH_OTHER');           // different tenant, empty doc
        $this->assertFalse($s->isEnabled(self::KEY, false),
            'the previous tenant\'s grant must not survive the switch');
    }

    public function test_re_init_with_the_SAME_tenant_keeps_the_memo(): void
    {
        $fb = $this->fb($this->control(['enabled' => true]));
        $s  = $this->svc($fb);
        $s->isEnabled(self::KEY, false);
        $s->init($fb, self::UAT);
        $s->isEnabled(self::KEY, false);
        $this->assertSame(1, $fb->reads, 'no repeat read for the same tenant in one request');
    }

    public function test_the_control_document_is_read_at_most_once_per_request(): void
    {
        $fb = $this->fb($this->control(['enabled' => true]));
        $s  = $this->svc($fb);
        for ($i = 0; $i < 10; $i++) {
            $s->isEnabled(self::KEY, false);
            $s->isEnabled('ANOTHER_FLAG', false);
            $s->resolve(self::KEY, false);
        }
        $this->assertSame(1, $fb->reads, 'Gate 8: one read per request, memoised');
    }

    public function test_no_read_happens_until_a_flag_is_actually_asked_for(): void
    {
        $fb = $this->fb($this->control(['enabled' => true]));
        $this->svc($fb);
        $this->assertSame(0, $fb->reads, 'constructing the service must cost nothing');
    }

    public function test_an_unbound_service_is_inert(): void
    {
        $s = new Rollout_flag_service();
        $this->assertFalse($s->isEnabled(self::KEY, false));
        $this->assertTrue($s->isEnabled(self::KEY, true));
        $this->assertSame([], $s->all());
    }

    // ══ ARCHITECTURAL BOUNDARIES ════════════════════════════════════════

    /** Rollout state is NOT a commercial entitlement. */
    public function test_it_reads_schoolControl_and_never_activeModules(): void
    {
        $src = file_get_contents(APPPATH . 'libraries/Rollout_flag_service.php');
        $this->assertSame('schoolControl', Rollout_flag_service::COL);
        $this->assertSame('rolloutFlags',  Rollout_flag_service::FIELD);

        $code = '';
        foreach (token_get_all($src) as $t) {
            $code .= is_array($t) ? (in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true) ? ' ' : $t[1]) : $t;
        }
        $this->assertStringNotContainsString('activeModules', $code,
            'rollout state must never be written into or read from the commercial entitlement mirror');
        $this->assertStringNotContainsString('tenantPublic', $code);
        $this->assertStringNotContainsString('subscription', $code);
    }

    /** It is not a second config master — the caller owns its default. */
    public function test_it_never_reads_a_config_file_itself(): void
    {
        $src = file_get_contents(APPPATH . 'libraries/Rollout_flag_service.php');
        $code = '';
        foreach (token_get_all($src) as $t) {
            $code .= is_array($t) ? (in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true) ? ' ' : $t[1]) : $t;
        }
        $this->assertStringNotContainsString('config->item', $code);
        $this->assertStringNotContainsString('get_instance', $code);
    }

    /** Read-only by construction. */
    public function test_the_service_can_never_write(): void
    {
        $src = file_get_contents(APPPATH . 'libraries/Rollout_flag_service.php');
        $code = '';
        foreach (token_get_all($src) as $t) {
            $code .= is_array($t) ? (in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true) ? ' ' : $t[1]) : $t;
        }
        foreach (['firestoreSet', 'firestoreUpdate', 'firestoreCreate', 'firestoreDelete',
                  'firestoreCommitBatch'] as $verb) {
            $this->assertStringNotContainsString($verb, $code, "T1 is a RESOLVER — {$verb} must not appear");
        }
    }

    // ══ T2 HAS NOT HAPPENED ═════════════════════════════════════════════

    /**
     * This test was written at T1 as "no production caller uses the service
     * yet", deliberately designed to FAIL the moment routing happened. T2 was
     * then explicitly authorized, so it fired correctly and is now updated to
     * the new contract — NOT deleted, and NOT weakened: it still enumerates
     * every caller and fails on any file outside the five authorized sites.
     */
    public function test_only_the_five_authorized_sites_use_the_service(): void
    {
        // T2's five routing sites + T3's two grant files (separately authorized).
        $authorized = ['MY_Controller.php', 'Fees.php', 'Fee_lifecycle.php',
                       'Fee_generation_service.php', 'Rollout_flag_service.php',
                       'Rollout_grant_service.php', 'Superadmin_rollout.php',
                       // P5-BF (2026-08-29): Rollout_grant_cli is the CLI front door to
                       // Rollout_grant_service, added so a controlled, evidence-logged
                       // canary activation could be issued from a terminal. It contains no
                       // grant logic of its own — every rule stays in the service.
                       'Rollout_grant_cli.php', 'Superadmin_schools.php'];
        $callers = [];
        foreach (array_merge(
            glob(APPPATH . 'controllers/*.php'), glob(APPPATH . 'libraries/*.php'),
            glob(APPPATH . 'core/*.php'),        glob(APPPATH . 'helpers/*.php')
        ) as $f) {
            $src = file_get_contents($f);
            if (strpos($src, 'ollout_flag_service') !== false || strpos($src, 'rolloutFlags') !== false) {
                $callers[] = basename($f);
            }
        }
        $unexpected = array_values(array_diff(array_unique($callers), $authorized));
        $this->assertSame([], $unexpected,
            'a sixth routing site is scope creep — T2 authorized exactly five');
    }

    public function test_every_global_default_is_still_OFF(): void
    {
        // SUPERSEDED 2026-08-30 (owner decision): the billing chain ships ON so a
        // new school bills with no grant. Safe only via the capability guards in
        // Fee_lifecycle (FEEHEAD_REGISTRY_HELD / ENTITLEMENT_AUTHORITY_HELD),
        // asserted in BillingV2RollbackVerificationTest. Genuinely unfinished
        // features stay OFF and are still checked in the loop below.
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
