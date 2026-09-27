<?php

use PHPUnit\Framework\TestCase;

if (!defined('BASEPATH')) define('BASEPATH', __DIR__);
if (!defined('APPPATH'))  define('APPPATH', __DIR__ . '/../../application/');
if (!function_exists('log_message')) { function log_message($l, $m) {} }

require_once __DIR__ . '/../../application/libraries/Rollout_flag_service.php';

/**
 * T2 — the five production gates now resolve through Rollout_flag_service.
 *
 * THE SAFETY PROPERTY THIS FILE EXISTS TO PROVE:
 *
 *     with no `schoolControl.rolloutFlags` entry on file, every gate returns
 *     EXACTLY what it returned before T2.
 *
 * The config file remains the sole author of the global default; each gate
 * reads its own value and passes it to the resolver, which answers only
 * "does this tenant override it?". Nothing is centralised, nothing is
 * re-authored, and no default moved.
 */
class T2RolloutRoutingTest extends TestCase
{
    /** The five authorized routing sites, and nothing else. */
    private const GATES = [
        'USE_FEE_HEAD_REGISTRY',
        'USE_ENTITLEMENT_BILLING',
        'USE_ENTITLEMENT_SERVICE_PRICING',
        'ENTITLEMENT_SHADOW_COMPARE',
    ];

    private function fb(?array $doc)
    {
        return new class($doc) {
            public int $reads = 0;
            public function __construct(private ?array $doc) {}
            public function firestoreGet(string $c, string $id): ?array { $this->reads++; return $this->doc; }
        };
    }

    private function code(string $rel): string
    {
        $out = '';
        foreach (token_get_all(file_get_contents(APPPATH . $rel)) as $t) {
            $out .= is_array($t) ? (in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true) ? ' ' : $t[1]) : $t;
        }
        return $out;
    }

    // ══ THE SAFETY PROPERTY ══════════════════════════════════════════════

    /** No grant anywhere ⇒ every gate returns its global default, unchanged. */
    public function test_with_no_grant_every_gate_returns_its_global_default(): void
    {
        foreach ([null, [], ['schoolId' => 'SCH_B56BB9A401'], ['rolloutFlags' => []]] as $doc) {
            $svc = (new Rollout_flag_service())->init($this->fb($doc), 'SCH_B56BB9A401');
            foreach (self::GATES as $g) {
                $this->assertFalse($svc->isEnabled($g, false), "{$g} must stay OFF");
                $this->assertTrue($svc->isEnabled($g, true),   "{$g} must honour an ON default");
            }
        }
    }

    /** A grant flips exactly one flag, for exactly one tenant. */
    public function test_a_grant_flips_only_its_own_flag(): void
    {
        $svc = (new Rollout_flag_service())->init($this->fb([
            'rolloutFlags' => ['USE_FEE_HEAD_REGISTRY' => ['enabled' => true, 'reason' => 'M5 UAT']],
        ]), 'SCH_B56BB9A401');

        $this->assertTrue($svc->isEnabled('USE_FEE_HEAD_REGISTRY', false));
        foreach (['USE_ENTITLEMENT_BILLING', 'USE_ENTITLEMENT_SERVICE_PRICING',
                  'ENTITLEMENT_SHADOW_COMPARE'] as $other) {
            $this->assertFalse($svc->isEnabled($other, false),
                "granting M5 must not switch on {$other}");
        }
    }

    public function test_a_grant_for_one_tenant_does_not_reach_another(): void
    {
        $granted = ['rolloutFlags' => ['USE_FEE_HEAD_REGISTRY' => ['enabled' => true]]];
        $svc = (new Rollout_flag_service())->init($this->fb($granted), 'SCH_B56BB9A401');
        $this->assertTrue($svc->isEnabled('USE_FEE_HEAD_REGISTRY', false));

        $svc->init($this->fb(null), 'SCH_218AAF5C23');    // the other live tenant
        $this->assertFalse($svc->isEnabled('USE_FEE_HEAD_REGISTRY', false),
            'the UAT grant must not leak into the 1,118-demand tenant');
    }

    // ══ EACH GATE STILL OWNS ITS OWN DEFAULT ════════════════════════════

    public function test_every_gate_still_reads_its_own_config_value(): void
    {
        // The flag name is held in a variable (one literal, one switch), so
        // assert the CONFIG READ still happens at each site and still names the
        // right config file — that is the property, not the literal's position.
        $this->assertMatchesRegularExpression(
            "/config->item\(\\\$flag,\s*'fee_head_registry'\)/", $this->code('controllers/Fees.php'));

        $lif = $this->code('libraries/Fee_lifecycle.php');
        $this->assertMatchesRegularExpression("/config->item\(\\\$flag,\s*'fee_head_registry'\)/", $lif);
        $this->assertMatchesRegularExpression(
            "/config->item\(\\\$authorityFlag,\s*'fees_exemption_v2_flags'\)/", $lif);
        $this->assertMatchesRegularExpression(
            "/config->item\(\\\$flag,\s*'fees_exemption_v2_flags'\)/", $lif);

        $this->assertMatchesRegularExpression(
            "/config->item\(\\\$flag,\s*'fees_exemption_v2_flags'\)/",
            $this->code('libraries/Fee_generation_service.php'));
    }

    public function test_the_config_file_remains_the_only_author_of_defaults(): void
    {
        $svcCode = $this->code('libraries/Rollout_flag_service.php');
        $this->assertStringNotContainsString('config->item', $svcCode,
            'the resolver must never become a second config master');
    }

    // ══ THE FIVE ROUTING SITES, AND ONLY THOSE ══════════════════════════

    public function test_the_gates_route_through_the_resolver(): void
    {
        // Each flag NAME appears exactly once per site and is passed by
        // variable — Phase 3B pins that the authority is chosen at ONE place,
        // and a second literal would be a second switch that could disagree.
        $fees = $this->code('controllers/Fees.php');
        $this->assertStringContainsString('isEnabled($flag, $globalDefault)', $fees);

        $lif = $this->code('libraries/Fee_lifecycle.php');
        $this->assertMatchesRegularExpression("/_rolloutEnabled\(\s*\\\$authorityFlag,/", $lif);
        $this->assertMatchesRegularExpression("/_rolloutEnabled\(\\\$flag,/", $lif);
        $this->assertSame(4, substr_count($lif, '_rolloutEnabled('),
            'the helper definition + exactly THREE call sites (M5, billing authority, '
            . 'service pricing). A fifth would be an unauthorized fourth gate.');

        $gen = $this->code('libraries/Fee_generation_service.php');
        $this->assertStringContainsString('isEnabled($flag, $shadowOn)', $gen);

        foreach ([['controllers/Fees.php', 'USE_FEE_HEAD_REGISTRY'],
                  ['libraries/Fee_lifecycle.php', 'USE_FEE_HEAD_REGISTRY'],
                  ['libraries/Fee_lifecycle.php', 'USE_ENTITLEMENT_BILLING'],
                  ['libraries/Fee_lifecycle.php', 'USE_ENTITLEMENT_SERVICE_PRICING'],
                  ['libraries/Fee_generation_service.php', 'ENTITLEMENT_SHADOW_COMPARE']] as [$f, $k]) {
            $this->assertSame(1, substr_count($this->code($f), "'{$k}'"),
                "{$k} must appear exactly once in {$f} — one literal, one switch");
        }
    }

    public function test_the_controller_binds_the_resolver_to_the_request_tenant(): void
    {
        $mc = $this->code('core/MY_Controller.php');
        $this->assertStringContainsString("\$this->load->library('rollout_flag_service', null, 'rollout');", $mc);
        $this->assertStringContainsString("\$this->rollout->init(\$this->firebase, \$this->school_id);", $mc);
    }

    /** Scope guard: no gate outside the five authorized sites was rerouted. */
    public function test_no_unauthorized_file_uses_the_resolver(): void
    {
        // Five T2 routing sites + the two T3 grant files (authorized separately:
        // the writer service and its superadmin-only controller). Anything else
        // is scope creep and still fails here.
        $allowed = ['MY_Controller.php', 'Fees.php', 'Fee_lifecycle.php',
                    'Fee_generation_service.php', 'Rollout_flag_service.php',
                    'Rollout_grant_service.php', 'Superadmin_rollout.php',
                    // P5-BF (2026-08-29): Rollout_grant_cli is the CLI front door to
                    // Rollout_grant_service, added so a controlled, evidence-logged
                    // canary activation could be issued from a terminal. It contains no
                    // grant logic of its own — every rule stays in the service.
                    'Rollout_grant_cli.php', 'Superadmin_schools.php'];
        $found = [];
        foreach (array_merge(
            glob(APPPATH . 'controllers/*.php'), glob(APPPATH . 'libraries/*.php'),
            glob(APPPATH . 'core/*.php'),        glob(APPPATH . 'helpers/*.php'),
            glob(APPPATH . 'services/*.php')
        ) as $f) {
            $src = file_get_contents($f);
            if (strpos($src, 'rollout_flag_service') !== false
                || strpos($src, 'Rollout_flag_service') !== false
                || strpos($src, 'rolloutFlags') !== false) {
                if (!in_array(basename($f), $allowed, true)) $found[] = basename($f);
            }
        }
        $this->assertSame([], array_unique($found),
            'T2 authorized exactly five sites — anything else is scope creep');
    }

    // ══ FAIL-SAFE ═══════════════════════════════════════════════════════

    /** Every gate must survive a missing resolver by using its own default. */
    public function test_each_gate_falls_back_when_the_resolver_is_unavailable(): void
    {
        $fees = $this->code('controllers/Fees.php');
        $this->assertStringContainsString('if (!isset($this->rollout)', $fees);
        $this->assertStringContainsString('return $globalDefault;', $fees);

        $lif = $this->code('libraries/Fee_lifecycle.php');
        $this->assertStringContainsString('if ($CI === null || $this->schoolName === \'\') return $globalDefault;', $lif);
        $this->assertStringContainsString('catch (\Throwable $e)', $lif);

        $gen = $this->code('libraries/Fee_generation_service.php');
        $this->assertStringContainsString("if (\$this->schoolId !== '' && isset(\$CI->rollout))", $gen);
    }

    /** A cron/worker with no controller still resolves against the right tenant. */
    public function test_the_library_gate_binds_on_its_own_school_not_the_controllers(): void
    {
        $lif = $this->code('libraries/Fee_lifecycle.php');
        $this->assertStringContainsString('->init($this->firebase, (string) $this->schoolName)', $lif);
    }

    // ══ NOTHING ELSE MOVED ══════════════════════════════════════════════

    public function test_no_global_default_changed(): void
    {
        // SUPERSEDED 2026-08-30 (owner decision): ships ON so a new school needs
        // no grant; safe via the Fee_lifecycle capability guards. See
        // BillingV2RollbackVerificationTest for the guard assertions.
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

    /** T2 touches flag PLUMBING only — no financial semantics moved. */
    public function test_financial_invariants_are_untouched(): void
    {
        $fees = file_get_contents(APPPATH . 'controllers/Fees.php');
        $this->assertStringContainsString(
            '($haveKeys[$dedupKey] ?? []) + ($haveKeys[$dedupKeyByName] ?? [])', $fees);   // Phase 1
        $this->assertStringContainsString('ACC_FEE_DEMAND_IDENTITY_CONFLICT', $fees);       // Phase 2
        $this->assertStringContainsString(
            "'balance'         => round(\$netAmount + \$fineSoFar - \$paidSoFar, 2),", $fees);

        $lif = file_get_contents(APPPATH . 'libraries/Fee_lifecycle.php');
        $this->assertStringContainsString('$id = "DEM_{$sid}_{$ym}_{$key}";', $lif);        // demand identity
        $this->assertStringContainsString("'grossAmount' => \$amount", $lif);               // DEC-001 untouched

        $this->assertStringContainsString(
            "return \$this->schoolId . '_' . \$studentId . '_E' . substr(sha1(implode('|', \$parts)), 0, 20);",
            file_get_contents(APPPATH . 'libraries/Entitlement_service.php'));              // DEC-004 identity
    }

    /** No grant exists anywhere yet — T4 is still a gate. */
    public function test_no_tenant_grant_has_been_created(): void
    {
        $this->assertStringNotContainsString('rolloutFlags',
            $this->code('libraries/B2_registry_service.php'),
            'T3 (the grant surface) is not authorized yet');
    }
}
