<?php
/**
 * ZenX Maps — 5E PRODUCTION-PATH validation (MAP-T1 … MAP-T20).
 *
 * WHY A SECOND SUITE. MapsPlatformInheritanceTest proves the ENGINE
 * (resolveCapability). Phase 5's worst discovery was "green tests, dead
 * feature": the engine was correct and the application never called it. This
 * suite therefore drives the REAL production entry point — getClientConfig() —
 * which is what Transport_fleet::map_config and the school settings page call,
 * and asserts on the serialized payload a browser would actually receive.
 *
 * It also covers the shape the other suite structurally cannot: a tenant with
 * NO mapsConfig document, whose `providers` is a stdClass. That gap let a real
 * fatal reach a real school while 45 tests stayed green.
 */

use PHPUnit\Framework\TestCase;

if (!defined('BASEPATH')) { define('BASEPATH', 1); }

require_once __DIR__ . '/../../application/libraries/Maps_config_service.php';

/**
 * Firestore stand-in that records every write.
 *
 * Deliberately NOT shared with MapsPlatformInheritanceTest's stub: that one
 * always hands back an array for `providers`, which is exactly the assumption
 * that hid the stdClass fatal. This one returns null for an unknown tenant so
 * the service builds its own default document — the real onboarding shape.
 */
class MapsProdStubFs
{
    public array $platform = [];
    public array $tenants  = [];
    public array $secrets  = [];
    public array $writes   = [];   // [collection, docId] per write attempt
    public array $written  = [];   // collection => docId => last payload

    public function get(string $col, string $docId)
    {
        if ($col === Maps_config_service::COL_PLATFORM_CONFIG)  { return $this->platform ?: null; }
        if ($col === Maps_config_service::COL_PLATFORM_SECRETS) { return $this->secrets  ?: null; }
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
        if ($col === Maps_config_service::COL_CONFIG) {
            $this->tenants[$entityId] = array_merge($this->tenants[$entityId] ?? [], $d);
        }
        return true;
    }
    public function removeEntity(string $col, string $entityId): bool
    { $this->writes[] = ['remove:' . $col, $entityId]; return true; }

    /** Writes that touched a tenant-scoped collection. */
    public function tenantWrites(): array
    {
        return array_values(array_filter($this->writes, static fn($w) => strpos($w[0], 'platform') !== 0));
    }
}

final class MapsProductionPathTest extends TestCase
{
    private MapsProdStubFs $fs;

    /** Credential values that must be traceable in a full-payload scan. */
    private const PLATFORM_KEY = 'PLATFORM_SECRET_TEST_VALUE';
    private const TENANT_KEY   = 'TENANT_SECRET_TEST_VALUE';

    protected function setUp(): void { $this->fs = new MapsProdStubFs(); }

    private function svc(string $sid): Maps_config_service
    {
        return (new Maps_config_service())->init($this->fs, $sid, 'TEST_ACTOR');
    }

    /** The production payload for one school on one surface. */
    private function client(string $sid, string $surface = 'admin'): array
    {
        return $this->svc($sid)->getClientConfig($surface);
    }

    private function platform(string $provider, bool $enabled = true, string $validation = 'valid'): array
    {
        return ['capabilities' => [
            'render' => [
                'provider'          => $provider,
                'enabled'           => $enabled,
                'validation_status' => $validation,
                'public'            => ['api_key' => self::PLATFORM_KEY,
                                        'style_url' => 'https://platform.example/style.json'],
            ],
        ], 'environment' => 'uat', 'commercial_status' => 'free_noncommercial'];
    }

    /** A tenant document. $providers omitted → the school configured nothing. */
    private function tenantDoc(string $sid, string $source, array $providers = []): array
    {
        return ['schoolId' => $sid, 'capability_source' => ['render' => $source],
                'mode' => 'global', 'global_provider' => '', 'providers' => $providers];
    }

    /** A fully configured + activated tenant provider. */
    private function tenantProvider(string $provider): array
    {
        return [$provider => ['enabled' => true, 'validation_status' => 'valid',
                              'public' => ['api_key' => self::TENANT_KEY,
                                           'style_url' => 'https://tenant.example/style.json']]];
    }

    // ─────────────────────────────────────────────────────────────────────
    //  MAP-T1 — platform inheritance, through the production entry point
    // ─────────────────────────────────────────────────────────────────────

    public function test_MAP_T1_inheritance_matrix_via_getClientConfig(): void
    {
        $X = 'maptiler'; $Y = 'maplibre'; $Z = 'maplibre';

        $this->fs->platform  = $this->platform($X);
        $this->fs->tenants['B'] = $this->tenantDoc('B', 'inherit');
        $this->fs->tenants['C'] = $this->tenantDoc('C', $Y, $this->tenantProvider($Y));
        // Tenant A has NO document at all — the onboarding case.

        $this->assertSame($X, $this->client('A')['provider'], 'A (no doc) inherits X');
        $this->assertSame($X, $this->client('B')['provider'], 'B (inherit) inherits X');
        $this->assertSame($Y, $this->client('C')['provider'], 'C overrides with Y');

        // Platform moves X → Z. Nothing about the tenants changes.
        $this->fs->platform = $this->platform($Z);

        $this->assertSame($Z, $this->client('A')['provider'], 'A follows the platform');
        $this->assertSame($Z, $this->client('B')['provider'], 'B follows the platform');
        $this->assertSame($Y, $this->client('C')['provider'], 'C stays on its override');
    }

    public function test_MAP_T1_platform_change_writes_nothing_to_any_tenant(): void
    {
        $this->fs->platform     = $this->platform('maptiler');
        $this->fs->tenants['B'] = $this->tenantDoc('B', 'inherit');
        $this->fs->tenants['C'] = $this->tenantDoc('C', 'maplibre', $this->tenantProvider('maplibre'));

        foreach (['A', 'B', 'C'] as $s) { $this->client($s); }
        $this->fs->platform = $this->platform('maplibre');
        foreach (['A', 'B', 'C'] as $s) { $this->client($s); }

        $this->assertSame([], $this->fs->writes,
            'Resolution and a platform switch must be READ-ONLY for tenants. '
          . 'Any write here means inheritance is being materialised per school.');
    }

    // ─────────────────────────────────────────────────────────────────────
    //  MAP-T2 — new-school onboarding: zero configuration required
    // ─────────────────────────────────────────────────────────────────────

    public function test_MAP_T2_new_school_with_no_mapsConfig_inherits_automatically(): void
    {
        $this->fs->platform = $this->platform('maptiler');
        $c = $this->client('BRAND_NEW_SCHOOL');

        $this->assertSame('maptiler', $c['provider']);
        $this->assertSame(Maps_config_service::ST_INHERITED, $c['state']);
        $this->assertSame('platform', $c['source']);
        $this->assertTrue($c['enabled']);
        $this->assertTrue($c['supported']);
        $this->assertSame(self::PLATFORM_KEY, $c['credentials']['api_key'],
            'An unconfigured school must render on the platform credentials.');
        $this->assertSame([], $this->fs->writes,
            'Onboarding must not create a tenant maps document.');
    }

    /**
     * REGRESSION — the stdClass fatal found by browser testing in 5D.
     *
     * _defaultConfig() seeds `providers` as new stdClass() so it serialises to a
     * JSON object. A school that selects a provider it has never configured hit
     * "Cannot use object of type stdClass as array". The other suite could not
     * catch it: its stub always supplies an array.
     */
    public function test_MAP_T2_regression_override_on_a_school_with_no_providers_object(): void
    {
        $this->fs->platform = $this->platform('maptiler');
        // No tenant doc → providers is a stdClass, and the source is an override.
        $this->fs->tenants['S'] = ['schoolId' => 'S',
                                   'capability_source' => ['render' => 'maplibre']];

        $c = $this->client('S');   // must not fatal
        $this->assertSame(Maps_config_service::ST_OVERRIDE_INVALID, $c['state']);
        $this->assertSame('maptiler', $c['provider'], 'falls back to the platform');
        $this->assertNotSame('', $c['warning']);
    }

    // ─────────────────────────────────────────────────────────────────────
    //  MAP-T3 / MAP-T4 — override, and returning to inherit
    // ─────────────────────────────────────────────────────────────────────

    public function test_MAP_T3_override_is_independent_of_the_platform(): void
    {
        $this->fs->platform     = $this->platform('maptiler');
        $this->fs->tenants['S'] = $this->tenantDoc('S', 'maplibre', $this->tenantProvider('maplibre'));

        $c = $this->client('S');
        $this->assertSame('maplibre', $c['provider']);
        $this->assertSame(Maps_config_service::ST_OVERRIDDEN, $c['state']);
        $this->assertSame('tenant', $c['source']);
        $this->assertSame(self::TENANT_KEY, $c['credentials']['api_key']);

        $this->fs->platform = $this->platform('tomtom');
        $this->assertSame('maplibre', $this->client('S')['provider'],
            'An overriding school must not follow the platform.');
    }

    public function test_MAP_T4_return_to_inherit_keeps_the_stored_provider(): void
    {
        $this->fs->platform     = $this->platform('maptiler');
        $this->fs->tenants['S'] = $this->tenantDoc('S', 'maplibre', $this->tenantProvider('maplibre'));

        $res = $this->svc('S')->setTenantCapabilitySource('render', 'inherit');
        $this->assertTrue($res['ok']);

        $payload = $this->fs->written[Maps_config_service::COL_CONFIG]['S'];
        $this->assertSame('inherit', $payload['capability_source']['render'],
            'The stored value must be the WORD "inherit", never a resolved provider — '
          . 'storing the provider would freeze this school onto today\'s platform default.');
        $this->assertArrayNotHasKey('providers', $payload,
            'Returning to inherit must not rewrite or delete stored credentials.');

        // Still stored, and now inactive.
        $this->assertSame(self::TENANT_KEY,
            $this->fs->tenants['S']['providers']['maplibre']['public']['api_key']);
        $c = $this->client('S');
        $this->assertSame('maptiler', $c['provider']);
        $this->assertSame(Maps_config_service::ST_INHERITED, $c['state']);

        // And the school follows the platform again.
        $this->fs->platform = $this->platform('tomtom');
        $this->assertSame('tomtom', $this->client('S')['provider']);
    }

    // ─────────────────────────────────────────────────────────────────────
    //  MAP-T6 — an invalid override never silently activates
    // ─────────────────────────────────────────────────────────────────────

    /** @dataProvider invalidOverrides */
    public function test_MAP_T6_invalid_override_falls_back_with_a_warning(string $case, array $doc): void
    {
        $this->fs->platform     = $this->platform('maptiler');
        $this->fs->tenants['S'] = $doc + ['schoolId' => 'S'];

        $c = $this->client('S');
        $this->assertSame(Maps_config_service::ST_OVERRIDE_INVALID, $c['state'], $case);
        $this->assertSame('maptiler', $c['provider'], $case . ': must fall back to the platform');
        $this->assertNotSame('', $c['warning'], $case . ': the fallback must be surfaced, never silent');
        $this->assertSame(self::PLATFORM_KEY, $c['credentials']['api_key'],
            $case . ': fallback must use PLATFORM credentials');
    }

    public function invalidOverrides(): array
    {
        return [
            'unknown provider' => ['unknown provider',
                ['capability_source' => ['render' => 'nosuchprovider'], 'providers' => []]],
            'not activated' => ['not activated',
                ['capability_source' => ['render' => 'maplibre'],
                 'providers' => ['maplibre' => ['enabled' => false,
                                                'public' => ['api_key' => self::TENANT_KEY]]]]],
            'never configured' => ['never configured',
                ['capability_source' => ['render' => 'maplibre'], 'providers' => []]],
        ];
    }

    // ─────────────────────────────────────────────────────────────────────
    //  MAP-T7 — platform unavailable: explicit states, never false success
    // ─────────────────────────────────────────────────────────────────────

    public function test_MAP_T7_platform_states_are_explicit(): void
    {
        $cases = [
            'A not configured' => [[], Maps_config_service::ST_PLATFORM_NOT_CONFIGURED],
            'B inactive'       => [$this->platform('maptiler', false), Maps_config_service::ST_PLATFORM_NOT_CONFIGURED],
            'C unknown id'     => [$this->platform('not_a_provider'), Maps_config_service::ST_PROVIDER_UNSUPPORTED],
        ];
        foreach ($cases as $label => [$plat, $expect]) {
            $this->fs->platform = $plat;
            $c = $this->client('S');
            $this->assertSame($expect, $c['state'], $label);
            $this->assertSame('', $c['provider'], $label);
            $this->assertFalse($c['enabled'], $label . ': must NOT report a working map');
            $this->assertSame([], $c['credentials'], $label);
        }
    }

    public function test_MAP_T7E_invalid_override_AND_no_platform_keeps_the_warning(): void
    {
        $this->fs->platform     = [];
        $this->fs->tenants['S'] = $this->tenantDoc('S', 'maplibre');   // configured nothing

        $c = $this->client('S');
        $this->assertSame(Maps_config_service::ST_PLATFORM_NOT_CONFIGURED, $c['state']);
        $this->assertFalse($c['enabled']);
        $this->assertNotSame('', $c['warning'],
            'The tenant-side reason must survive a platform failure, or the school '
          . 'is told only "not configured" and never learns its own override failed.');
    }

    // ─────────────────────────────────────────────────────────────────────
    //  MAP-T8 — surface-aware resolution
    // ─────────────────────────────────────────────────────────────────────

    public function test_MAP_T8_admin_only_provider_does_not_leak_into_parent(): void
    {
        // TomTom has an admin renderer and no Android one.
        $this->fs->platform     = $this->platform('maptiler');
        $this->fs->tenants['S'] = $this->tenantDoc('S', 'tomtom', $this->tenantProvider('tomtom'));

        $admin = $this->client('S', 'admin');
        $this->assertSame('tomtom', $admin['provider']);
        $this->assertSame(Maps_config_service::ST_OVERRIDDEN, $admin['state']);

        $parent = $this->client('S', 'parent');
        $this->assertSame('maptiler', $parent['provider'],
            'Parent must fall back — TomTom cannot draw there.');
        $this->assertSame(Maps_config_service::ST_OVERRIDE_INVALID, $parent['state']);
        $this->assertNotSame('', $parent['warning']);
        $this->assertTrue($parent['supported']);
    }

    public function test_MAP_T8_a_resolved_provider_is_always_renderable_on_its_surface(): void
    {
        foreach (['maplibre', 'maptiler', 'tomtom'] as $p) {
            foreach (Maps_config_service::PLATFORMS as $surface) {
                $this->fs->platform     = $this->platform($p);
                $this->fs->tenants['S'] = $this->tenantDoc('S', 'inherit');
                $c = $this->client('S', $surface);
                if ($c['provider'] !== '') {
                    $this->assertTrue($c['supported'],
                        "resolved {$c['provider']} on {$surface} must be renderable there");
                }
            }
        }
    }

    // ─────────────────────────────────────────────────────────────────────
    //  MAP-T9 — credential isolation. Scans the FULL serialized payload.
    // ─────────────────────────────────────────────────────────────────────

    public function test_MAP_T9_inheriting_tenant_never_receives_its_own_stored_key(): void
    {
        $this->fs->platform     = $this->platform('maptiler');
        // The school HAS stored credentials for another provider, but inherits.
        $this->fs->tenants['S'] = $this->tenantDoc('S', 'inherit', $this->tenantProvider('maplibre'));

        $json = json_encode($this->client('S'));
        $this->assertStringContainsString(self::PLATFORM_KEY, $json);
        $this->assertStringNotContainsString(self::TENANT_KEY, $json,
            'A dormant tenant credential must never reach the client while inheriting.');
    }

    public function test_MAP_T9_overriding_tenant_never_receives_the_platform_key(): void
    {
        $this->fs->platform     = $this->platform('maptiler');
        $this->fs->tenants['S'] = $this->tenantDoc('S', 'maplibre', $this->tenantProvider('maplibre'));

        $json = json_encode($this->client('S'));
        $this->assertStringContainsString(self::TENANT_KEY, $json);
        $this->assertStringNotContainsString(self::PLATFORM_KEY, $json,
            'PLATFORM CREDENTIAL LEAK: an overriding school received ZenX\'s own key.');
    }

    public function test_MAP_T9_secrets_never_appear_in_a_client_payload(): void
    {
        $this->fs->platform = $this->platform('maptiler');
        // Secrets live in a separate store and in a `secret` sub-key; assert the
        // payload carries neither, whichever shape a future writer uses.
        $this->fs->secrets = ['capabilities' => ['render' => ['secret' => ['server_key' => 'SEKRIT_PLATFORM']]]];
        $this->fs->tenants['S'] = ['schoolId' => 'S', 'capability_source' => ['render' => 'maplibre'],
            'providers' => ['maplibre' => ['enabled' => true,
                'public' => ['api_key' => self::TENANT_KEY],
                'secret' => ['secret_token' => 'SEKRIT_TENANT']]]];

        $json = json_encode($this->client('S'));
        $this->assertStringNotContainsString('SEKRIT_TENANT', $json);
        $this->assertStringNotContainsString('SEKRIT_PLATFORM', $json);
    }

    public function test_MAP_T9_only_the_resolved_providers_credentials_are_sent(): void
    {
        $this->fs->platform     = $this->platform('maptiler');
        $this->fs->tenants['S'] = ['schoolId' => 'S', 'capability_source' => ['render' => 'maplibre'],
            'providers' => [
                'maplibre' => ['enabled' => true, 'public' => ['api_key' => self::TENANT_KEY]],
                'tomtom'   => ['enabled' => true, 'public' => ['api_key' => 'OTHER_PROVIDER_KEY']],
            ]];

        $json = json_encode($this->client('S'));
        $this->assertStringNotContainsString('OTHER_PROVIDER_KEY', $json,
            'A payload must carry ONE provider\'s credentials, not the whole config document.');
    }

    // ─────────────────────────────────────────────────────────────────────
    //  MAP-T14 — audit records carry no credential values
    // ─────────────────────────────────────────────────────────────────────

    public function test_MAP_T14_source_change_audit_has_before_after_and_no_secrets(): void
    {
        $this->fs->platform     = $this->platform('maptiler');
        $this->fs->tenants['S'] = $this->tenantDoc('S', 'maplibre', $this->tenantProvider('maplibre'));

        $this->svc('S')->setTenantCapabilitySource('render', 'inherit');

        $audits = $this->fs->written[Maps_config_service::COL_AUDIT] ?? [];
        $this->assertNotEmpty($audits, 'A source change must be audited.');
        $row = end($audits);
        $blob = json_encode($row);
        $this->assertStringContainsString('capability_source_changed', $blob);
        $this->assertStringContainsString('maplibre', $blob, 'before-state must be recorded');
        $this->assertStringContainsString('inherit', $blob, 'after-state must be recorded');
        $this->assertStringNotContainsString(self::TENANT_KEY, $blob);
        $this->assertStringNotContainsString(self::PLATFORM_KEY, $blob);
    }

    // ─────────────────────────────────────────────────────────────────────
    //  MAP-T20 — data model: no new collections, no platform copy
    // ─────────────────────────────────────────────────────────────────────

    public function test_MAP_T20_source_change_touches_only_mapsConfig_and_the_audit_log(): void
    {
        $this->fs->platform     = $this->platform('maptiler');
        $this->fs->tenants['S'] = $this->tenantDoc('S', 'inherit');

        $this->svc('S')->setTenantCapabilitySource('render', 'maplibre');

        $cols = array_values(array_unique(array_map(static fn($w) => $w[0], $this->fs->writes)));
        sort($cols);
        $this->assertSame(
            [Maps_config_service::COL_CONFIG, Maps_config_service::COL_AUDIT],
            $cols,
            'No new collection may be introduced, and the platform documents must be unreachable here.');
    }

    public function test_MAP_T20_no_platform_configuration_is_copied_into_the_tenant(): void
    {
        $this->fs->platform     = $this->platform('maptiler');
        $this->fs->tenants['S'] = $this->tenantDoc('S', 'inherit');

        $this->svc('S')->setTenantCapabilitySource('render', 'inherit');
        $payload = json_encode($this->fs->written[Maps_config_service::COL_CONFIG]['S']);

        $this->assertStringNotContainsString(self::PLATFORM_KEY, $payload);
        $this->assertStringNotContainsString('platform.example', $payload);
        $this->assertStringNotContainsString('maptiler', $payload,
            'The resolved platform provider must never be materialised into a tenant document.');
    }

    // ─────────────────────────────────────────────────────────────────────
    //  Capability discipline — geocode/route must stay honestly unavailable
    // ─────────────────────────────────────────────────────────────────────

    public function test_unimplemented_capabilities_cannot_resolve_or_be_selected(): void
    {
        $this->fs->platform = $this->platform('maptiler');
        $svc = $this->svc('S');

        foreach (['geocode', 'route'] as $cap) {
            $r = $svc->resolveCapability($cap, Maps_config_service::SCOPE_SERVER);
            $this->assertSame(Maps_config_service::ST_CAP_NOT_IMPLEMENTED, $r['state'], $cap);
            $this->assertSame('', $r['provider'], $cap);

            $res = $svc->setTenantCapabilitySource($cap, 'maptiler');
            $this->assertFalse($res['ok'], $cap . ' must not be selectable while unbuilt');
        }
    }

    public function test_a_provider_that_cannot_render_anywhere_is_refused_as_a_source(): void
    {
        $this->fs->platform = $this->platform('maptiler');
        $res = $this->svc('S')->setTenantCapabilitySource('render', 'google');
        $this->assertFalse($res['ok']);
        $this->assertSame([], $this->fs->writes, 'A refused selection must write nothing.');
    }
}
