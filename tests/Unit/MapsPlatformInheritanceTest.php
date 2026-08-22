<?php
/**
 * ZenX Maps — platform default + tenant override inheritance (2026-08-16).
 *
 * Proves the mandatory matrix:
 *
 *     A/B/C inherit, platform = X   →  A=X  B=X  C=X
 *     B overrides Y                 →  A=X  B=Y  C=X
 *     platform = Z                  →  A=Z  B=Y  C=Z
 *
 * WHY A STUB, NOT FIRESTORE. The requirement is that changing the platform
 * default moves inheriting tenants with ZERO tenant writes. A stub filesystem
 * lets the test ASSERT that: it counts writes and fails if a single tenant
 * document is touched. It also keeps the suite off the operator's live tenant —
 * the precise mistake that destroyed a real MapTiler config on 2026-08-16.
 */

use PHPUnit\Framework\TestCase;

if (!defined('BASEPATH')) { define('BASEPATH', 1); }

require_once __DIR__ . '/../../application/libraries/Maps_config_service.php';

/** Minimal Firestore stand-in: fixed docs in, write attempts counted. */
class MapsStubFs
{
    public array $platform = [];
    public array $tenants  = [];      // schoolId => tenant config doc
    public array $writes   = [];      // every write attempt, for assertions
    public string $schoolId = '';

    public function get(string $col, string $docId)
    {
        if ($col === Maps_config_service::COL_PLATFORM_CONFIG) { return $this->platform ?: null; }
        return null;
    }
    public function getEntity(string $col, string $entityId)
    {
        if ($col === Maps_config_service::COL_CONFIG) { return $this->tenants[$entityId] ?? null; }
        return null;
    }
    public function set(string $col, string $docId, array $d, bool $m = true): bool
    { $this->writes[] = [$col, $docId]; $this->written[$col][$docId] = $d; return true; }
    public function setEntity(string $col, string $entityId, array $d, bool $m = true): bool
    {
        $this->writes[] = [$col, $entityId];
        $this->written[$col][$entityId] = $d;
        // 5B: mirror a MERGE write back into the tenant doc so a save→re-save
        // sequence sees its own result, which is what Case A actually tests.
        if ($col === Maps_config_service::COL_CONFIG) {
            $this->tenants[$entityId] = array_merge($this->tenants[$entityId] ?? [], $d);
        }
        return true;
    }
    /** col => docId => last payload written, for assertions. */
    public array $written = [];
    public function removeEntity(string $col, string $entityId): bool
    { $this->writes[] = ['remove:' . $col, $entityId]; return true; }
}

final class MapsPlatformInheritanceTest extends TestCase
{
    private MapsStubFs $fs;

    protected function setUp(): void
    {
        $this->fs = new MapsStubFs();
    }

    /** Platform doc with one capability pointed at $provider. */
    private function platform(string $provider, bool $enabled = true): array
    {
        return ['capabilities' => [
            'render' => ['provider' => $provider, 'enabled' => $enabled,
                         'validation_status' => 'valid',
                         'public' => ['api_key' => 'PLATFORM_KEY', 'style_url' => 'https://example/style.json']],
        ], 'environment' => 'uat', 'commercial_status' => 'free_noncommercial'];
    }

    /** Tenant doc. $source = 'inherit' or a provider id. */
    private function tenant(string $sid, string $source, array $providers = []): array
    {
        return ['schoolId' => $sid, 'capability_source' => ['render' => $source],
                'mode' => 'global', 'global_provider' => '', 'providers' => $providers];
    }

    private function svc(string $sid): Maps_config_service
    {
        $s = new Maps_config_service();
        return $s->init($this->fs, $sid, 'TEST');
    }

    private function resolvedFor(string $sid): string
    {
        return $this->svc($sid)->resolveCapability('render', 'admin')['provider'];
    }

    private function stateFor(string $sid): string
    {
        return $this->svc($sid)->resolveCapability('render', 'admin')['state'];
    }

    // ── THE MANDATORY MATRIX ─────────────────────────────────────────────

    public function test_the_mandatory_inheritance_matrix(): void
    {
        // maplibre / maptiler are the two providers with a real admin renderer,
        // so X and Y are both legitimately resolvable — the test must not pass
        // by accident on a provider that could never render.
        $X = 'maptiler'; $Y = 'maplibre'; $Z = 'maplibre';

        // ── Step 1: A/B/C all inherit, platform = X
        $this->fs->platform = $this->platform($X);
        foreach (['A', 'B', 'C'] as $s) { $this->fs->tenants[$s] = $this->tenant($s, 'inherit'); }

        $this->assertSame($X, $this->resolvedFor('A'), 'A must inherit X');
        $this->assertSame($X, $this->resolvedFor('B'), 'B must inherit X');
        $this->assertSame($X, $this->resolvedFor('C'), 'C must inherit X');
        $this->assertSame(Maps_config_service::ST_INHERITED, $this->stateFor('A'));

        // ── Step 2: B overrides with Y
        $this->fs->tenants['B'] = $this->tenant('B', $Y, [$Y => ['enabled' => true]]);

        $this->assertSame($X, $this->resolvedFor('A'), 'A unaffected by B override');
        $this->assertSame($Y, $this->resolvedFor('B'), 'B must use its override');
        $this->assertSame($X, $this->resolvedFor('C'), 'C unaffected by B override');
        $this->assertSame(Maps_config_service::ST_OVERRIDDEN, $this->stateFor('B'));

        // ── Step 3: platform default changes to Z
        $this->fs->platform = $this->platform($Z);

        $this->assertSame($Z, $this->resolvedFor('A'), 'A must FOLLOW the platform to Z');
        $this->assertSame($Y, $this->resolvedFor('B'), 'B must REMAIN on its override');
        $this->assertSame($Z, $this->resolvedFor('C'), 'C must FOLLOW the platform to Z');
    }

    public function test_changing_the_platform_writes_NOTHING_to_tenant_documents(): void
    {
        $this->fs->platform = $this->platform('maptiler');
        foreach (['A', 'B', 'C'] as $s) { $this->fs->tenants[$s] = $this->tenant($s, 'inherit'); }
        foreach (['A', 'B', 'C'] as $s) { $this->resolvedFor($s); }

        $this->fs->platform = $this->platform('maplibre');       // the platform change
        foreach (['A', 'B', 'C'] as $s) { $this->resolvedFor($s); }

        // Dynamic inheritance means resolution is a READ. One tenant write here
        // would mean the platform config had been copied — the thing forbidden.
        $this->assertSame([], $this->fs->writes,
            'Inheritance must be dynamic: resolving must not write to any tenant document');
    }

    // ── FALLBACK / STATE MACHINE ─────────────────────────────────────────

    public function test_invalid_override_falls_back_to_platform_with_a_warning(): void
    {
        $this->fs->platform  = $this->platform('maptiler');
        $this->fs->tenants['B'] = $this->tenant('B', 'no_such_provider');

        $r = $this->svc('B')->resolveCapability('render', 'admin');
        $this->assertSame('maptiler', $r['provider'], 'must fall back to the platform default');
        $this->assertSame(Maps_config_service::ST_OVERRIDE_INVALID, $r['state']);
        $this->assertNotSame('', $r['warning'], 'a silent fallback is not acceptable');
    }

    public function test_unactivated_override_falls_back_rather_than_blanking_the_map(): void
    {
        $this->fs->platform = $this->platform('maptiler');
        $this->fs->tenants['B'] = $this->tenant('B', 'maplibre', ['maplibre' => ['enabled' => false]]);

        $r = $this->svc('B')->resolveCapability('render', 'admin');
        $this->assertSame('maptiler', $r['provider']);
        $this->assertSame(Maps_config_service::ST_OVERRIDE_INVALID, $r['state']);
        $this->assertStringContainsString('not activated', $r['warning']);
    }

    public function test_override_to_a_provider_that_cannot_render_here_falls_back(): void
    {
        // tomtom renders on admin but NOT on parent — the exact case the
        // renderer-availability model exists to catch.
        $this->fs->platform = $this->platform('maptiler');
        $this->fs->tenants['B'] = $this->tenant('B', 'tomtom', ['tomtom' => ['enabled' => true]]);

        $this->assertSame('tomtom', $this->svc('B')->resolveCapability('render', 'admin')['provider']);

        $r = $this->svc('B')->resolveCapability('render', 'parent');
        $this->assertSame('maptiler', $r['provider'], 'parent must fall back — tomtom has no parent renderer');
        $this->assertSame(Maps_config_service::ST_OVERRIDE_INVALID, $r['state']);
    }

    public function test_no_platform_configured_is_an_explicit_state_not_a_blank_map(): void
    {
        $this->fs->platform = [];
        $this->fs->tenants['A'] = $this->tenant('A', 'inherit');

        $r = $this->svc('A')->resolveCapability('render', 'admin');
        $this->assertSame(Maps_config_service::ST_PLATFORM_NOT_CONFIGURED, $r['state']);
        $this->assertSame('', $r['provider']);
    }

    public function test_disabled_platform_capability_is_not_configured(): void
    {
        $this->fs->platform = $this->platform('maptiler', false);
        $this->fs->tenants['A'] = $this->tenant('A', 'inherit');
        $this->assertSame(Maps_config_service::ST_PLATFORM_NOT_CONFIGURED, $this->stateFor('A'));
    }

    public function test_unimplemented_capabilities_never_resolve(): void
    {
        // The provider registry advertises geocoding; ZenX has not built it.
        // Resolving one would hand a caller a provider it cannot use.
        $this->fs->platform = ['capabilities' => [
            'geocode' => ['provider' => 'maptiler', 'enabled' => true],
        ]];
        $this->fs->tenants['A'] = $this->tenant('A', 'inherit');

        $r = $this->svc('A')->resolveCapability('geocode', 'server');
        $this->assertSame(Maps_config_service::ST_CAP_NOT_IMPLEMENTED, $r['state']);
        $this->assertSame('', $r['provider']);
    }

    // ── BACKWARD COMPATIBILITY ───────────────────────────────────────────

    public function test_legacy_tenant_without_capability_source_keeps_its_provider(): void
    {
        // A tenant configured before this feature: no capability_source, but a
        // working global_provider. It must resolve EXACTLY as it did before.
        $this->fs->platform = $this->platform('maplibre');
        $this->fs->tenants['LEG'] = ['schoolId' => 'LEG', 'mode' => 'global',
            'global_provider' => 'maptiler', 'providers' => ['maptiler' => ['enabled' => true]]];

        $r = $this->svc('LEG')->resolveCapability('render', 'admin');
        $this->assertSame('maptiler', $r['provider'], 'legacy tenant must not be silently migrated');
        $this->assertSame(Maps_config_service::ST_OVERRIDDEN, $r['state']);
    }

    public function test_blank_legacy_tenant_inherits(): void
    {
        $this->fs->platform = $this->platform('maptiler');
        $this->fs->tenants['NEW'] = ['schoolId' => 'NEW'];   // brand-new school, nothing configured
        $this->assertSame('maptiler', $this->resolvedFor('NEW'), 'a new school must just work');
        $this->assertSame(Maps_config_service::ST_INHERITED, $this->stateFor('NEW'));
    }

    // ── CROSS-TENANT ISOLATION ───────────────────────────────────────────

    public function test_one_tenant_override_cannot_alter_another_tenants_resolution(): void
    {
        $this->fs->platform = $this->platform('maptiler');
        $this->fs->tenants['A'] = $this->tenant('A', 'inherit');
        $this->fs->tenants['B'] = $this->tenant('B', 'maplibre', ['maplibre' => ['enabled' => true]]);

        $this->assertSame('maptiler', $this->resolvedFor('A'));
        $this->assertSame('maplibre', $this->resolvedFor('B'));
        $this->assertSame('maptiler', $this->resolvedFor('A'), 'A is unchanged after B resolves');
    }

    public function test_credentials_come_from_the_layer_that_resolved(): void
    {
        $this->fs->platform = $this->platform('maptiler');
        $this->fs->tenants['B'] = $this->tenant('B', 'maplibre',
            ['maplibre' => ['enabled' => true, 'public' => ['style_url' => 'TENANT_STYLE']]]);

        $svc = $this->svc('B');
        $r   = $svc->resolveCapability('render', 'admin');
        $c   = $svc->credentialsFor($r['provider'], $r['source']);
        $this->assertSame('TENANT_STYLE', $c['style_url'] ?? null, 'override must use ITS OWN credentials');

        $svcA = $this->svc('A');
        $this->fs->tenants['A'] = $this->tenant('A', 'inherit');
        $rA = $svcA->resolveCapability('render', 'admin');
        $cA = $svcA->credentialsFor($rA['provider'], $rA['source']);
        $this->assertSame('PLATFORM_KEY', $cA['api_key'] ?? null, 'inheriting tenant uses PLATFORM credentials');
        $this->assertArrayNotHasKey('style_url', array_diff_key($cA, ['api_key' => 1, 'style_url' => 1]));
    }

    public function test_platform_secrets_are_never_returned_to_a_tenant_caller(): void
    {
        // credentialsFor() reads only the PUBLIC sub-map. Even if a secret were
        // present on the platform document it must not surface here.
        $this->fs->platform = $this->platform('maptiler');
        $this->fs->platform['capabilities']['render']['secret_api_key'] = 'MUST_NEVER_LEAK';
        $this->fs->tenants['A'] = $this->tenant('A', 'inherit');

        $svc = $this->svc('A');
        $r   = $svc->resolveCapability('render', 'admin');
        $c   = $svc->credentialsFor($r['provider'], $r['source']);
        $this->assertNotContains('MUST_NEVER_LEAK', array_values($c));
        $this->assertArrayNotHasKey('secret_api_key', $c);
    }

    // ── 5A — THE PRODUCTION CONSUMER ─────────────────────────────────────
    // Phase 4 proved resolveCapability() and shipped a feature that did
    // nothing: getClientConfig() — the ONLY thing Admin and Parent actually
    // call — still used the tenant-only resolver, so a configured, validated,
    // ACTIVE platform default reached no school. The engine was tested
    // directly and was never plugged in.
    //
    // These tests bind the PRODUCTION entry point so that gap cannot reopen.

    public function test_getClientConfig_makes_an_inheriting_tenant_use_the_platform(): void
    {
        $this->fs->platform = $this->platform('maptiler');
        $this->fs->tenants['A'] = $this->tenant('A', 'inherit');

        $c = $this->svc('A')->getClientConfig('admin');
        $this->assertSame('maptiler', $c['provider'], 'the production consumer must inherit');
        $this->assertTrue($c['supported']);
        $this->assertTrue($c['enabled']);
        $this->assertSame(Maps_config_service::ST_INHERITED, $c['state']);
        $this->assertSame('platform', $c['source']);
        $this->assertSame('PLATFORM_KEY', $c['credentials']['api_key'] ?? null);
    }

    public function test_getClientConfig_uses_the_tenants_OWN_credentials_when_overriding(): void
    {
        $this->fs->platform = $this->platform('maptiler');
        $this->fs->tenants['B'] = $this->tenant('B', 'maplibre',
            ['maplibre' => ['enabled' => true, 'public' => ['style_url' => 'TENANT_STYLE']]]);

        $c = $this->svc('B')->getClientConfig('admin');
        $this->assertSame('maplibre', $c['provider']);
        $this->assertSame(Maps_config_service::ST_OVERRIDDEN, $c['state']);
        $this->assertSame('TENANT_STYLE', $c['credentials']['style_url'] ?? null);
        // The platform key must never bleed into an overriding tenant.
        $this->assertArrayNotHasKey('api_key', $c['credentials']);
    }

    public function test_getClientConfig_follows_a_platform_change_for_inheriting_tenants_only(): void
    {
        $this->fs->platform = $this->platform('maptiler');
        $this->fs->tenants['A'] = $this->tenant('A', 'inherit');
        $this->fs->tenants['B'] = $this->tenant('B', 'maplibre', ['maplibre' => ['enabled' => true]]);
        $this->assertSame('maptiler', $this->svc('A')->getClientConfig('admin')['provider']);

        $this->fs->platform = $this->platform('maplibre');          // platform switches
        $this->assertSame('maplibre', $this->svc('A')->getClientConfig('admin')['provider'], 'A follows');
        $this->assertSame('maplibre', $this->svc('B')->getClientConfig('admin')['provider'], 'B unchanged');
        $this->assertSame(Maps_config_service::ST_OVERRIDDEN,
            $this->svc('B')->getClientConfig('admin')['state']);
        $this->assertSame([], $this->fs->writes, 'resolution must never write to a tenant');
    }

    public function test_getClientConfig_never_leaks_a_platform_secret(): void
    {
        $this->fs->platform = $this->platform('maptiler');
        $this->fs->platform['capabilities']['render']['secret_api_key'] = 'MUST_NEVER_LEAK';
        $this->fs->tenants['A'] = $this->tenant('A', 'inherit');

        $c = $this->svc('A')->getClientConfig('admin');
        $this->assertNotContains('MUST_NEVER_LEAK', array_values($c['credentials']));
        $this->assertStringNotContainsString('MUST_NEVER_LEAK', (string) json_encode($c));
    }

    public function test_getClientConfig_reports_an_explicit_state_when_platform_unconfigured(): void
    {
        $this->fs->platform = [];
        $this->fs->tenants['A'] = $this->tenant('A', 'inherit');

        $c = $this->svc('A')->getClientConfig('admin');
        $this->assertSame('', $c['provider']);
        $this->assertFalse($c['enabled']);
        $this->assertSame(Maps_config_service::ST_PLATFORM_NOT_CONFIGURED, $c['state']);
    }

    public function test_getClientConfig_surfaces_the_fallback_warning_on_invalid_override(): void
    {
        $this->fs->platform = $this->platform('maptiler');
        $this->fs->tenants['B'] = $this->tenant('B', 'no_such_provider');

        $c = $this->svc('B')->getClientConfig('admin');
        $this->assertSame('maptiler', $c['provider'], 'falls back to the platform');
        $this->assertSame(Maps_config_service::ST_OVERRIDE_INVALID, $c['state']);
        $this->assertNotSame('', $c['warning'], 'the school must be told, not silently switched');
    }

    public function test_getClientConfig_respects_surface_a_tenant_override_cannot_render_on(): void
    {
        // tomtom renders on admin but has no parent renderer.
        $this->fs->platform = $this->platform('maptiler');
        $this->fs->tenants['B'] = $this->tenant('B', 'tomtom', ['tomtom' => ['enabled' => true]]);

        $this->assertSame('tomtom',   $this->svc('B')->getClientConfig('admin')['provider']);
        $this->assertSame('maptiler', $this->svc('B')->getClientConfig('parent')['provider'],
            'parent must fall back to the platform rather than render nothing');
    }

    // ── 5B — TENANT CREDENTIAL SAVE SAFETY ───────────────────────────────
    // A stored credential is never echoed back and inputs are never
    // pre-filled, so BLANK is the only thing an admin can submit for a
    // credential they are not changing. Blank must therefore mean KEEP.
    //
    // Tenant credentials are stored PER PROVIDER ($providers[<id>]), so
    // cross-provider contamination is structurally impossible — several tests
    // below pin that property so a future refactor cannot introduce it.

    /** Tenant doc already holding saved credentials for $provider. */
    private function tenantWithCreds(string $sid, string $provider, array $public, string $source = ''): array
    {
        return [
            'schoolId' => $sid,
            'capability_source' => ['render' => $source !== '' ? $source : $provider],
            'providers' => [$provider => [
                'public' => $public, 'enabled' => true,
                'validation_status' => 'valid', 'validated_at' => '2026-01-01T00:00:00+00:00',
            ]],
        ];
    }

    private function savedProviders(string $sid): array
    {
        return (array) ($this->fs->written[Maps_config_service::COL_CONFIG][$sid]['providers'] ?? []);
    }

    public function test_5B1_same_provider_blank_credentials_are_PRESERVED(): void
    {
        $this->fs->tenants['A'] = $this->tenantWithCreds('A', 'maptiler',
            ['api_key' => 'TENANT_KEY', 'style_url' => 'https://tenant/style.json']);

        $r = $this->svc('A')->saveProvider('maptiler', ['api_key' => '', 'style_url' => '']);
        $this->assertTrue($r['ok'], 'a blank re-save must not be rejected: ' . implode(' ', $r['errors'] ?? []));

        $p = $this->savedProviders('A')['maptiler']['public'];
        $this->assertSame('TENANT_KEY', $p['api_key']);
        $this->assertSame('https://tenant/style.json', $p['style_url']);
    }

    public function test_5B2_same_provider_supplied_credential_REPLACES(): void
    {
        $this->fs->tenants['A'] = $this->tenantWithCreds('A', 'maptiler',
            ['api_key' => 'OLD_KEY', 'style_url' => 'https://tenant/style.json']);

        $r = $this->svc('A')->saveProvider('maptiler', ['api_key' => 'NEW_KEY', 'style_url' => '']);
        $this->assertTrue($r['ok']);

        $p = $this->savedProviders('A')['maptiler']['public'];
        $this->assertSame('NEW_KEY', $p['api_key'], 'an explicit value must win');
        $this->assertSame('https://tenant/style.json', $p['style_url'], 'untouched field preserved');
    }

    public function test_5B3_provider_change_with_blank_required_is_REJECTED(): void
    {
        $this->fs->tenants['A'] = $this->tenantWithCreds('A', 'maptiler',
            ['api_key' => 'TENANT_KEY', 'style_url' => 'https://tenant/style.json']);

        // maplibre requires style_url; nothing stored for it, so blank must fail.
        $r = $this->svc('A')->saveProvider('maplibre', ['style_url' => '']);
        $this->assertFalse($r['ok'], 'an incomplete new provider must not save');
        $this->assertNotEmpty($r['errors']);
        $this->assertSame([], $this->fs->writes, 'a rejected save must write nothing');
    }

    public function test_5B4_provider_change_with_new_credentials_SUCCEEDS(): void
    {
        $this->fs->tenants['A'] = $this->tenantWithCreds('A', 'maptiler',
            ['api_key' => 'TENANT_KEY', 'style_url' => 'https://tenant/style.json']);

        $r = $this->svc('A')->saveProvider('maplibre', ['style_url' => 'https://new/style.json']);
        $this->assertTrue($r['ok'], implode(' ', $r['errors'] ?? []));

        $saved = $this->savedProviders('A');
        $this->assertSame('https://new/style.json', $saved['maplibre']['public']['style_url']);
    }

    public function test_5B5_provider_change_carries_NO_old_provider_credentials(): void
    {
        $this->fs->tenants['A'] = $this->tenantWithCreds('A', 'maptiler',
            ['api_key' => 'MAPTILER_ONLY', 'style_url' => 'https://tenant/style.json']);

        $this->svc('A')->saveProvider('maplibre', ['style_url' => 'https://new/style.json']);
        $new = $this->savedProviders('A')['maplibre']['public'];

        $this->assertArrayNotHasKey('api_key', $new, 'MapTiler key must not appear under MapLibre');
        $this->assertNotContains('MAPTILER_ONLY', array_values($new));
    }

    public function test_5B6_resaving_resets_validation_and_DISABLES(): void
    {
        $this->fs->tenants['A'] = $this->tenantWithCreds('A', 'maptiler',
            ['api_key' => 'TENANT_KEY', 'style_url' => 'https://tenant/style.json']);

        $this->svc('A')->saveProvider('maptiler', ['api_key' => 'CHANGED', 'style_url' => '']);
        $e = $this->savedProviders('A')['maptiler'];

        $this->assertFalse($e['enabled'], 'changed config must not stay active');
        $this->assertSame(Maps_config_service::VALIDATION_UNTESTED, $e['validation_status']);
        $this->assertNull($e['validated_at']);
    }

    public function test_5B7_tenant_save_does_NOT_touch_platform_documents(): void
    {
        $this->fs->platform = $this->platform('maptiler');
        $this->fs->tenants['A'] = $this->tenantWithCreds('A', 'maptiler',
            ['api_key' => 'TENANT_KEY', 'style_url' => 'https://tenant/style.json']);

        $this->svc('A')->saveProvider('maptiler', ['api_key' => 'CHANGED', 'style_url' => '']);

        foreach ($this->fs->writes as [$col, $doc]) {
            $this->assertNotSame(Maps_config_service::COL_PLATFORM_CONFIG, $col,
                'a tenant save must never write platformConfig');
            $this->assertNotSame(Maps_config_service::COL_PLATFORM_SECRETS, $col,
                'a tenant save must never write platformSecrets');
        }
        // Platform still resolves for an inheriting sibling.
        $this->fs->tenants['B'] = $this->tenant('B', 'inherit');
        $this->assertSame('maptiler', $this->svc('B')->getClientConfig('admin')['provider']);
    }

    public function test_5B8_saving_credentials_does_not_silently_convert_inherit(): void
    {
        // The tenant INHERITS but stores credentials for a provider. Saving
        // those credentials is a 5B operation; flipping the source is 5C and
        // must not happen here.
        $this->fs->platform = $this->platform('maptiler');
        $this->fs->tenants['A'] = ['schoolId' => 'A', 'capability_source' => ['render' => 'inherit'],
            'providers' => ['maplibre' => ['public' => ['style_url' => 'https://t/s.json'], 'enabled' => true]]];

        $this->svc('A')->saveProvider('maplibre', ['style_url' => 'https://t2/s.json']);

        $payload = $this->fs->written[Maps_config_service::COL_CONFIG]['A'] ?? [];
        $this->assertArrayNotHasKey('capability_source', $payload,
            'saveProvider must not write capability_source — that is 5C');
        $this->assertSame('maptiler', $this->svc('A')->getClientConfig('admin')['provider'],
            'the tenant must still be inheriting');
    }

    public function test_5B9_save_result_returns_no_credential_values(): void
    {
        $this->fs->tenants['A'] = $this->tenantWithCreds('A', 'maptiler',
            ['api_key' => 'SUPER_SECRET_VALUE', 'style_url' => 'https://tenant/style.json']);

        $r = $this->svc('A')->saveProvider('maptiler', ['api_key' => 'ANOTHER_VALUE', 'style_url' => '']);
        $this->assertStringNotContainsString('SUPER_SECRET_VALUE', (string) json_encode($r));
        $this->assertStringNotContainsString('ANOTHER_VALUE', (string) json_encode($r));
    }

    public function test_5B10_provider_sequence_A_to_B_to_A_stays_isolated(): void
    {
        $this->fs->tenants['A'] = $this->tenantWithCreds('A', 'maptiler',
            ['api_key' => 'KEY_A', 'style_url' => 'https://a/style.json']);

        $this->svc('A')->saveProvider('maplibre', ['style_url' => 'https://b/style.json']);
        $this->svc('A')->saveProvider('maptiler', ['api_key' => '', 'style_url' => '']);   // back to A, blank

        $saved = $this->savedProviders('A');
        $this->assertSame('KEY_A', $saved['maptiler']['public']['api_key'], 'A keeps its own key');
        $this->assertSame('https://b/style.json', $saved['maplibre']['public']['style_url'], 'B keeps its own');
        $this->assertArrayNotHasKey('api_key', $saved['maplibre']['public'], 'no A credential under B');
        $this->assertNotContains('https://b/style.json', array_values($saved['maptiler']['public']),
            'no B credential under A');
    }

    // ── 5C — INHERIT / OVERRIDE SOURCE CONTROL ───────────────────────────
    // setTenantCapabilitySource() is the ONLY writer of capability_source. It
    // stores the WORD "inherit", never the currently-resolved provider —
    // storing the resolved provider would freeze the tenant onto today's
    // platform choice and silently break dynamic inheritance.

    private function setSource(string $sid, string $source): array
    {
        return $this->svc($sid)->setTenantCapabilitySource('render', $source);
    }

    private function effective(string $sid): string
    {
        return $this->svc($sid)->getClientConfig('admin')['provider'];
    }

    public function test_5C1_a_school_with_no_tenant_config_inherits(): void
    {
        $this->fs->platform = $this->platform('maptiler');
        // No entry in $this->fs->tenants at all — a brand-new school.
        $this->assertSame('maptiler', $this->effective('BRAND_NEW'));
        $this->assertSame(Maps_config_service::ST_INHERITED,
            $this->svc('BRAND_NEW')->getClientConfig('admin')['state']);
    }

    public function test_5C2_explicit_inherit_resolves_to_the_platform(): void
    {
        $this->fs->platform = $this->platform('maptiler');
        $this->fs->tenants['A'] = $this->tenant('A', 'inherit');
        $this->assertSame('maptiler', $this->effective('A'));
    }

    public function test_5C3_explicit_override_resolves_to_the_tenant_provider(): void
    {
        $this->fs->platform = $this->platform('maptiler');
        $this->fs->tenants['A'] = $this->tenantWithCreds('A', 'maplibre',
            ['style_url' => 'https://t/s.json']);

        $r = $this->setSource('A', 'maplibre');
        $this->assertTrue($r['ok']);
        $this->assertSame('maplibre', $this->effective('A'));
        $this->assertSame(Maps_config_service::ST_OVERRIDDEN,
            $this->svc('A')->getClientConfig('admin')['state']);
    }

    public function test_5C4_override_then_back_to_inherit_returns_to_the_platform(): void
    {
        $this->fs->platform = $this->platform('maptiler');
        $this->fs->tenants['A'] = $this->tenantWithCreds('A', 'maplibre',
            ['style_url' => 'https://t/s.json']);

        $this->setSource('A', 'maplibre');
        $this->assertSame('maplibre', $this->effective('A'));

        $this->setSource('A', Maps_config_service::SOURCE_INHERIT);
        $this->assertSame('maptiler', $this->effective('A'), 'must resolve to the platform again');

        // The tenant's own provider config must SURVIVE, merely unused.
        $doc = $this->fs->tenants['A'];
        $this->assertArrayHasKey('maplibre', (array) $doc['providers'],
            'returning to inherit must not delete the tenant configuration');
        $this->assertSame('https://t/s.json', $doc['providers']['maplibre']['public']['style_url']);
    }

    public function test_5C5_and_5C6_platform_change_moves_only_inheriting_tenants(): void
    {
        $this->fs->platform = $this->platform('maptiler');
        $this->fs->tenants['A'] = $this->tenant('A', 'inherit');
        $this->fs->tenants['B'] = $this->tenantWithCreds('B', 'maplibre', ['style_url' => 'https://b/s.json']);
        $this->setSource('B', 'maplibre');

        $this->assertSame('maptiler', $this->effective('A'));
        $this->assertSame('maplibre', $this->effective('B'));

        $before = count($this->fs->writes);
        $this->fs->platform = $this->platform('tomtom');        // platform switches

        $this->assertSame('tomtom',   $this->effective('A'), 'inheriting tenant follows');
        $this->assertSame('maplibre', $this->effective('B'), 'overriding tenant does not');
        $this->assertCount($before, $this->fs->writes, 'a platform change must write to NO tenant');
    }

    public function test_5C7_school_A_source_change_does_not_affect_school_B(): void
    {
        $this->fs->platform = $this->platform('maptiler');
        $this->fs->tenants['A'] = $this->tenantWithCreds('A', 'maplibre', ['style_url' => 'https://a/s.json']);
        $this->fs->tenants['B'] = $this->tenant('B', 'inherit');

        $this->setSource('A', 'maplibre');
        $this->assertSame('maplibre', $this->effective('A'));
        $this->assertSame('maptiler', $this->effective('B'), 'B untouched');

        $this->setSource('A', Maps_config_service::SOURCE_INHERIT);
        $this->assertSame('maptiler', $this->effective('A'));
        $this->assertSame('maptiler', $this->effective('B'));
    }

    public function test_5C8_and_5C9_source_change_never_writes_platform_documents(): void
    {
        $this->fs->platform = $this->platform('maptiler');
        $this->fs->tenants['A'] = $this->tenantWithCreds('A', 'maplibre', ['style_url' => 'https://a/s.json']);
        $platformBefore = $this->fs->platform;

        $this->setSource('A', 'maplibre');
        $this->setSource('A', Maps_config_service::SOURCE_INHERIT);

        foreach ($this->fs->writes as [$col, $doc]) {
            $this->assertNotSame(Maps_config_service::COL_PLATFORM_CONFIG, $col);
            $this->assertNotSame(Maps_config_service::COL_PLATFORM_SECRETS, $col);
        }
        $this->assertSame($platformBefore, $this->fs->platform, 'platform document byte-identical');
    }

    public function test_5C10_invalid_override_falls_back_with_a_warning(): void
    {
        $this->fs->platform = $this->platform('maptiler');
        $this->fs->tenants['A'] = $this->tenant('A', 'maplibre');   // selected but never configured

        $c = $this->svc('A')->getClientConfig('admin');
        $this->assertSame('maptiler', $c['provider'], 'falls back to the platform');
        $this->assertSame(Maps_config_service::ST_OVERRIDE_INVALID, $c['state']);
        $this->assertNotSame('', $c['warning']);
    }

    public function test_5C11_provider_unsupported_on_a_surface_falls_back_there_only(): void
    {
        $this->fs->platform = $this->platform('maptiler');
        $this->fs->tenants['A'] = $this->tenantWithCreds('A', 'tomtom', ['api_key' => 'K']);
        $this->assertTrue($this->setSource('A', 'tomtom')['ok'], 'tomtom renders on admin, so selectable');

        $this->assertSame('tomtom',   $this->svc('A')->getClientConfig('admin')['provider']);
        $this->assertSame('maptiler', $this->svc('A')->getClientConfig('parent')['provider'],
            'no parent renderer → fall back on that surface only');
    }

    public function test_5C11b_a_provider_that_renders_nowhere_cannot_be_selected(): void
    {
        // google/mapbox/here have no renderer on any surface. Allowing one
        // would look like a working override while silently always falling
        // back — worse than refusing it.
        $this->fs->platform = $this->platform('maptiler');
        $this->fs->tenants['A'] = $this->tenant('A', 'inherit');

        $r = $this->setSource('A', 'google');
        $this->assertFalse($r['ok']);
        $this->assertSame([], $this->fs->writes, 'a refused source change writes nothing');
        $this->assertSame('maptiler', $this->effective('A'), 'still inheriting');
    }

    public function test_5C12_geocode_and_route_cannot_be_set_through_this_mechanism(): void
    {
        $this->fs->tenants['A'] = $this->tenant('A', 'inherit');
        foreach (['geocode', 'route'] as $cap) {
            $r = $this->svc('A')->setTenantCapabilitySource($cap, 'maptiler');
            $this->assertFalse($r['ok'], $cap . ' must be refused — not implemented');
        }
        $this->assertSame([], $this->fs->writes);
    }

    public function test_5C13_effective_credentials_come_from_the_resolving_layer(): void
    {
        $this->fs->platform = $this->platform('maptiler');           // PLATFORM_KEY
        $this->fs->tenants['A'] = $this->tenantWithCreds('A', 'maplibre', ['style_url' => 'TENANT_STYLE']);

        $this->setSource('A', 'maplibre');
        $c = $this->svc('A')->getClientConfig('admin');
        $this->assertSame('TENANT_STYLE', $c['credentials']['style_url'] ?? null);
        $this->assertArrayNotHasKey('api_key', $c['credentials'], 'no platform key while overriding');

        $this->setSource('A', Maps_config_service::SOURCE_INHERIT);
        $c = $this->svc('A')->getClientConfig('admin');
        $this->assertSame('PLATFORM_KEY', $c['credentials']['api_key'] ?? null);
        $this->assertNotContains('TENANT_STYLE', array_values($c['credentials']));
    }

    public function test_5C14_platform_credentials_are_never_copied_into_tenant_storage(): void
    {
        $this->fs->platform = $this->platform('maptiler');           // PLATFORM_KEY
        $this->fs->tenants['A'] = $this->tenant('A', 'inherit');

        $this->setSource('A', Maps_config_service::SOURCE_INHERIT);

        $written = $this->fs->written[Maps_config_service::COL_CONFIG]['A'] ?? [];
        $this->assertStringNotContainsString('PLATFORM_KEY', (string) json_encode($written),
            'the tenant document must never hold a platform credential');
        // and the write must be the source key only
        $this->assertArrayNotHasKey('providers', $written);
    }

    public function test_5C15_legacy_tenant_without_capability_source_keeps_legacy_behaviour(): void
    {
        $this->fs->platform = $this->platform('maplibre');
        $this->fs->tenants['LEG'] = ['schoolId' => 'LEG', 'mode' => 'global',
            'global_provider' => 'maptiler', 'providers' => ['maptiler' => ['enabled' => true]]];

        $this->assertSame('maptiler', $this->effective('LEG'), 'legacy provider retained');
        $this->assertSame(Maps_config_service::ST_OVERRIDDEN,
            $this->svc('LEG')->getClientConfig('admin')['state']);
    }

    public function test_5C16_setting_inherit_stores_the_WORD_not_the_resolved_provider(): void
    {
        // The single most important property: storing "maptiler" here instead
        // of "inherit" would freeze the tenant and break every later platform
        // change. Assert the persisted value directly.
        $this->fs->platform = $this->platform('maptiler');
        $this->fs->tenants['A'] = $this->tenantWithCreds('A', 'maplibre', ['style_url' => 'https://a/s.json']);

        $this->setSource('A', Maps_config_service::SOURCE_INHERIT);
        $written = $this->fs->written[Maps_config_service::COL_CONFIG]['A'];
        $this->assertSame('inherit', $written['capability_source']['render']);
    }
}
