<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Maps_config_service — the ERP's centralized Maps & Location platform.
 *
 * ONE place where every surface that draws a map gets its provider, its
 * credentials and its defaults: Transport live tracking today; fleet
 * dashboards, staff-attendance maps, field-staff and asset tracking, route
 * planning, trip replay, ETA and geofencing later. None of them should ever
 * hardcode a provider again.
 *
 * ─── THE SECURITY DECISION: TWO DOCUMENTS, NOT ONE ───────────────────────
 * Firestore rules gate access per DOCUMENT; they cannot hide a field. So a
 * secret placed in a client-readable configuration document IS readable by
 * every client, whatever the UI pretends. Splitting by sensitivity is the only
 * design that actually satisfies "clients receive only the configuration they
 * require":
 *
 *   mapsConfig/{schoolId}   PUBLIC  — mode, per-platform provider, style URLs,
 *       client-restricted keys, default centre/zoom, feature flags, validation
 *       status. Readable by any authenticated same-school user, because all
 *       three apps need it to render.
 *
 *   mapsSecrets/{schoolId}  SERVER-ONLY — unrestricted/server keys used for
 *       server-side geocoding, static-map rendering and provider validation.
 *       Rules deny read AND write to every client; only the PHP service
 *       account touches it.
 *
 * A credential is classified by [CRED_PUBLIC] / [CRED_SECRET] in the registry
 * below, and `saveProvider()` routes each value to the right document. A key
 * cannot land in the wrong one by accident.
 *
 * "Public" never means unprotected: a browser-key style credential is exposed
 * by necessity (the map SDK sends it from the device) and must be locked down
 * at the PROVIDER with an HTTP-referrer or package-name + SHA-1 restriction.
 * The admin UI says so at the point of entry rather than in a wiki nobody
 * reads.
 *
 * ─── MULTI-TENANT ────────────────────────────────────────────────────────
 * Per-school, not a single global document: this is a multi-tenant ERP, so one
 * school switching provider must not move another school's map. Doc id is the
 * bare `{schoolId}` through `docId()` — a single prefix, per RC-3.
 *
 * ─── WHAT THIS SERVICE DOES NOT DO ───────────────────────────────────────
 * It renders nothing and knows nothing about GPS. F13.1's telemetry, lifecycle,
 * hot document, collections, rules and repositories are untouched — this only
 * answers "which renderer, with which credentials". The existing MapLibre work
 * becomes provider #1 by registration, not by rewrite.
 */
class Maps_config_service
{
    public const COL_CONFIG  = 'mapsConfig';
    public const COL_SECRETS = 'mapsSecrets';
    public const COL_AUDIT   = 'mapsConfigAudit';

    // ── PLATFORM LAYER (2026-08-16) ──────────────────────────────────────
    // Platform documents belong to NO tenant, so they are addressed by a fixed
    // doc id via get()/set() — NOT getEntity(), which would prefix a schoolId
    // and scope them to whichever school happened to be in context.
    //
    // Firestore rules for both are deny-all in BOTH directions: the resolved
    // PUBLIC configuration reaches clients only through getClientConfig(), and
    // writes happen only through the superadmin backend using the Admin SDK
    // (which bypasses rules). A school admin can therefore never read the
    // platform document, only the values it resolves to for their own surface.
    public const COL_PLATFORM_CONFIG  = 'platformConfig';
    public const COL_PLATFORM_SECRETS = 'platformSecrets';
    public const PLATFORM_DOC         = 'maps';

    /** A tenant capability set to this INHERITS the platform default. */
    public const SOURCE_INHERIT = 'inherit';

    // Inheritance states. Every resolution returns exactly one of these, so a
    // caller can always say WHY a map is or is not available — the whole point
    // is that "no map" is never silent or ambiguous.
    public const ST_INHERITED       = 'INHERITED';
    public const ST_OVERRIDDEN      = 'OVERRIDDEN';
    public const ST_OVERRIDE_INVALID = 'OVERRIDE_INVALID';
    public const ST_PLATFORM_NOT_CONFIGURED = 'PLATFORM_NOT_CONFIGURED';
    public const ST_PLATFORM_UNAVAILABLE    = 'PLATFORM_PROVIDER_UNAVAILABLE';
    public const ST_TENANT_UNAVAILABLE      = 'TENANT_PROVIDER_UNAVAILABLE';
    public const ST_CAP_NOT_IMPLEMENTED     = 'CAPABILITY_NOT_IMPLEMENTED';
    public const ST_PROVIDER_UNSUPPORTED    = 'PROVIDER_NOT_SUPPORTED_FOR_CAPABILITY';

    /** Operating modes. */
    public const MODE_GLOBAL   = 'global';    // one provider everywhere
    public const MODE_PLATFORM = 'platform';  // per-platform provider
    public const MODES = [self::MODE_GLOBAL, self::MODE_PLATFORM];

    /** Surfaces that render maps. Extend as new ones appear. */
    public const PLATFORM_ADMIN   = 'admin';
    public const PLATFORM_PARENT  = 'parent';
    public const PLATFORM_TEACHER = 'teacher';
    public const PLATFORM_DRIVER  = 'driver';
    public const PLATFORMS = [
        self::PLATFORM_ADMIN, self::PLATFORM_PARENT,
        self::PLATFORM_TEACHER, self::PLATFORM_DRIVER,
    ];

    /**
     * Platforms that actually DRAW a map today.
     *
     * `teacher` and `driver` are registered surfaces with configuration
     * plumbing but no map UI yet, so a provider assignment there cannot be
     * "wrong" — it renders nothing either way. Assignment validation is
     * therefore enforced only on the surfaces below, and the admin page labels
     * the others as having no map surface yet rather than silently pretending
     * the choice matters.
     */
    public const PLATFORMS_WITH_SURFACE = [self::PLATFORM_ADMIN, self::PLATFORM_PARENT];

    /** Credential sensitivity — decides which document a value is written to. */
    public const CRED_PUBLIC = 'public';
    public const CRED_SECRET = 'secret';

    /** Validation lifecycle. A provider may only be ACTIVATED from `valid`. */
    public const VALIDATION_UNTESTED = 'untested';
    public const VALIDATION_VALID    = 'valid';
    public const VALIDATION_INVALID  = 'invalid';

    /**
     * The provider registry — declarative on purpose.
     *
     * Adding a provider is a data change here plus a renderer in each client
     * that needs it. No branching logic anywhere else: the admin form, the
     * validator, the storage split and the client payload are all derived from
     * this table.
     *
     * `probe` is how a credential set is proven to work before it can be made
     * live. `style_required` marks providers whose renderer needs a style
     * document rather than just a key.
     */
    public const PROVIDERS = [
        'maplibre' => [
            'label'    => 'MapLibre + OSM tiles',
            // Renderer availability per platform. A provider is only
            // ASSIGNABLE to a platform listed true here.
            'renderers' => ['admin' => true, 'parent' => true, 'teacher' => false, 'driver' => false],
            'renderer' => 'maplibre',
            'note'     => 'Open-source renderer. Tiles come from a commercial OSM provider '
                        . '(MapTiler, Stadia, Thunderforest, Carto) — the public OSM tile service '
                        . 'excludes commercial use.',
            'style_required' => true,
            'credentials' => [
                'style_url' => ['label' => 'Style URL', 'sensitivity' => self::CRED_PUBLIC,
                                'required' => true, 'hint' => 'https://…/style.json?key=…'],
                'api_key'   => ['label' => 'API key (if separate from the style URL)',
                                'sensitivity' => self::CRED_PUBLIC, 'required' => false],
            ],
            'probe' => ['type' => 'style_json', 'from' => 'style_url'],
        ],
        'maptiler' => [
            'label'    => 'MapTiler',
            'renderers' => ['admin' => true, 'parent' => true, 'teacher' => false, 'driver' => false],
            // GAP-1: the provider offers a geocoding API (WEB-VERIFIED,
            // maptiler.com/cloud/geocoding). Declared so a future geocoding
            // service can resolve it — ZenX consumes NOTHING here today, and
            // capabilityImplemented('geocode') still returns false.
            'capabilities' => ['geocode' => ['server' => true], 'route' => false],
            'renderer' => 'maplibre',      // MapTiler serves styles MapLibre renders
            'note'     => 'Vector tiles for the MapLibre renderer.',
            'style_required' => true,
            'credentials' => [
                'api_key'   => ['label' => 'API key', 'sensitivity' => self::CRED_PUBLIC, 'required' => true],
                'style_url' => ['label' => 'Style URL', 'sensitivity' => self::CRED_PUBLIC, 'required' => true,
                                'hint' => 'https://api.maptiler.com/maps/streets-v2/style.json?key=…'],
            ],
            'probe' => ['type' => 'style_json', 'from' => 'style_url'],
        ],
        'google' => [
            'label'    => 'Google Maps',
            // No renderer anywhere yet — registered centrally, selectable nowhere.
            'renderers' => ['admin' => false, 'parent' => false, 'teacher' => false, 'driver' => false],
            // Geocoding + routing are offered by the provider; the registry's
            // existing `server_api_key` is already labelled "(geocoding)" and
            // marked CRED_SECRET. Declaring them costs nothing and lets a
            // tenant one day render with MapTiler and geocode with Google.
            'capabilities' => ['geocode' => ['server' => true], 'route' => ['server' => true]],
            'renderer' => 'google',
            'note'     => 'Separate keys per platform. Restrict each at the Google console — '
                        . 'HTTP referrer for web, package name + SHA-1 for Android.',
            'style_required' => false,
            'credentials' => [
                'web_api_key'     => ['label' => 'Web API key', 'sensitivity' => self::CRED_PUBLIC, 'required' => false],
                'android_api_key' => ['label' => 'Android API key', 'sensitivity' => self::CRED_PUBLIC, 'required' => false],
                'ios_api_key'     => ['label' => 'iOS API key', 'sensitivity' => self::CRED_PUBLIC, 'required' => false],
                'map_id'          => ['label' => 'Map ID', 'sensitivity' => self::CRED_PUBLIC, 'required' => false],
                'server_api_key'  => ['label' => 'Server key (geocoding)', 'sensitivity' => self::CRED_SECRET, 'required' => false],
            ],
            // Google answers a bad key with HTTP 200 and a text/html error
            // page rather than an image, so the content type is the real signal.
            'probe' => ['type' => 'http_get', 'expect' => 'image',
                        'url' => 'https://maps.googleapis.com/maps/api/staticmap?center=0,0&zoom=1&size=1x1&key={web_api_key}'],
        ],
        'mapbox' => [
            'label'    => 'Mapbox',
            'renderers' => ['admin' => false, 'parent' => false, 'teacher' => false, 'driver' => false],
            'renderer' => 'mapbox',
            'note'     => 'Public token is client-visible by design; keep any secret token server-side.',
            'style_required' => true,
            'credentials' => [
                'public_token' => ['label' => 'Public access token', 'sensitivity' => self::CRED_PUBLIC, 'required' => true,
                                   'hint' => 'pk.…'],
                'style_url'    => ['label' => 'Style URL', 'sensitivity' => self::CRED_PUBLIC, 'required' => true,
                                   'hint' => 'mapbox://styles/mapbox/streets-v12'],
                'secret_token' => ['label' => 'Secret token', 'sensitivity' => self::CRED_SECRET, 'required' => false,
                                   'hint' => 'sk.… — server use only'],
            ],
            // Mapbox answers a malformed token with HTTP **200** and
            // {"code":"TokenMalformed"} — the status alone would pass a broken
            // key straight through the activation gate.
            'probe' => ['type' => 'http_get', 'expect' => 'json_field',
                        'field' => 'code', 'equals' => 'TokenValid',
                        'url' => 'https://api.mapbox.com/tokens/v2?access_token={public_token}'],
        ],
        'here' => [
            'label'    => 'HERE Maps',
            'renderers' => ['admin' => false, 'parent' => false, 'teacher' => false, 'driver' => false],
            'renderer' => 'here',
            'style_required' => false,
            'credentials' => [
                'api_key' => ['label' => 'API key', 'sensitivity' => self::CRED_PUBLIC, 'required' => true],
                'app_id'  => ['label' => 'App ID', 'sensitivity' => self::CRED_PUBLIC, 'required' => true],
            ],
            // HERE returns 200 with an empty items[] for some failures.
            'probe' => ['type' => 'http_get', 'expect' => 'json_has', 'field' => 'items',
                        'url' => 'https://geocode.search.hereapi.com/v1/geocode?q=London&apiKey={api_key}'],
        ],
        'tomtom' => [
            'label'    => 'TomTom',
            // ADMIN ONLY. Leaflet consumes TomTom raster tiles directly, but
            // MapRendererFactory has no Android TomTom renderer, so selecting
            // it for Parent would resolve to null and show "no provider
            // configured" while the admin believed it was live.
            'renderers' => ['admin' => true, 'parent' => false, 'teacher' => false, 'driver' => false],
            'renderer' => 'tomtom',
            'style_required' => false,
            'credentials' => [
                'api_key' => ['label' => 'API key', 'sensitivity' => self::CRED_PUBLIC, 'required' => true],
            ],
            // A real tile is a PNG; an error is JSON or HTML.
            'probe' => ['type' => 'http_get', 'expect' => 'image',
                        'url' => 'https://api.tomtom.com/map/1/tile/basic/main/0/0/0.png?key={api_key}'],
        ],
    ];

    /** Sensible world defaults until an administrator sets the school's own. */
    public const DEFAULT_ZOOM = 15.0;

    /** @var object|null */
    private $fs = null;
    private string $schoolId = '';
    private string $adminId  = 'system';

    public function init($fs, string $schoolId, string $adminId = 'system'): self
    {
        $this->fs       = $fs;
        $this->schoolId = trim($schoolId);
        $this->adminId  = trim($adminId) !== '' ? trim($adminId) : 'system';
        return $this;
    }

    private function _assertReady(): void
    {
        if ($this->fs === null || $this->schoolId === '') {
            throw new \LogicException('Maps_config_service::init() must be called first.');
        }
    }

    // ─────────────────────────────────────────────────────────────────────
    //  Read
    // ─────────────────────────────────────────────────────────────────────

    /** The public configuration document, seeded with defaults when absent. */
    public function getConfig(): array
    {
        $this->_assertReady();
        $doc = null;
        try { $doc = $this->fs->getEntity(self::COL_CONFIG, $this->schoolId); } catch (\Throwable $_) {}
        return is_array($doc) ? $this->_withDefaults($doc) : $this->_defaultConfig();
    }

    private function _defaultConfig(): array
    {
        return [
            'schoolId'          => $this->schoolId,
            'mode'              => self::MODE_GLOBAL,
            'global_provider'   => '',
            'platform_provider' => array_fill_keys(self::PLATFORMS, ''),
            'providers'         => new \stdClass(),
            'default_center'    => ['lat' => null, 'lng' => null],
            'default_zoom'      => self::DEFAULT_ZOOM,
            'feature_flags'     => new \stdClass(),
            'updated_at'        => null,
            'updated_by'        => '',
        ];
    }

    private function _withDefaults(array $doc): array
    {
        $d = $this->_defaultConfig();
        foreach ($d as $k => $v) { if (!array_key_exists($k, $doc)) { $doc[$k] = $v; } }
        $doc['platform_provider'] = array_merge(
            array_fill_keys(self::PLATFORMS, ''),
            (array) ($doc['platform_provider'] ?? [])
        );
        return $doc;
    }

    /**
     * Does [platform] actually have a renderer for [provider]?
     *
     * A provider is registered CENTRALLY but selectable only where a renderer
     * exists. TomTom is the case that forced this: Leaflet consumes its raster
     * tiles so the admin panel works, but there is no Android TomTom renderer —
     * assigning it to Parent would resolve to null and show "no provider
     * configured" while the administrator believed the map was live.
     */
    public function isSupportedOn(string $provider, string $platform): bool
    {
        $r = self::PROVIDERS[$provider]['renderers'] ?? null;
        return is_array($r) && !empty($r[$platform]);
    }

    // ─────────────────────────────────────────────────────────────────────
    //  GAP-1 — CAPABILITY-AWARE RESOLUTION (2026-08-16)
    //
    //  The registry historically modelled ONE capability: rendering. That is
    //  why `renderers` is keyed by platform and nothing else. It cannot express
    //  "MapTiler draws the map, Google resolves the address", which is the
    //  combination a multi-tenant ERP eventually needs.
    //
    //  This layer adds the capability dimension WITHOUT restructuring the
    //  stored configuration or the PROVIDERS literal:
    //
    //    • CAP_RENDER  is DERIVED from the existing `renderers` map, so every
    //      current provider, every stored mapsConfig document and the two
    //      Transport_verifier source gates that scan for "'renderers' => ["
    //      keep working unchanged. No migration, no Firestore write.
    //    • CAP_GEOCODE / CAP_ROUTE are declared per provider via an OPTIONAL
    //      `capabilities` key. Absent means "not offered", which is the correct
    //      default for every provider today — NOTHING in ZenX consumes them yet.
    //
    //  Declaring a capability here is a statement about the PROVIDER, not a
    //  promise that ZenX implements it. Geocoding and routing remain unbuilt.
    // ─────────────────────────────────────────────────────────────────────

    public const CAP_RENDER  = 'render';
    public const CAP_GEOCODE = 'geocode';
    public const CAP_ROUTE   = 'route';
    public const CAPABILITIES = [self::CAP_RENDER, self::CAP_GEOCODE, self::CAP_ROUTE];

    /** Geocoding/routing are server-side concerns, not per-surface ones. */
    public const SCOPE_SERVER = 'server';

    /**
     * Can [provider] serve [capability] on [scope]?
     *
     * $scope is a PLATFORM for render (admin|parent|teacher|driver) and
     * SCOPE_SERVER for geocode/route. Unknown provider or capability → false:
     * fail closed, so an unimplemented capability can never resolve.
     */
    public function supportsCapability(string $provider, string $capability, string $scope = self::SCOPE_SERVER): bool
    {
        if (!isset(self::PROVIDERS[$provider])) { return false; }

        // render: derived from the legacy map — the backward-compatible shim.
        if ($capability === self::CAP_RENDER) {
            return $this->isSupportedOn($provider, $scope);
        }
        if (!in_array($capability, self::CAPABILITIES, true)) { return false; }

        $caps = self::PROVIDERS[$provider]['capabilities'] ?? [];
        $decl = $caps[$capability] ?? false;
        if (is_bool($decl)) { return $decl; }
        return is_array($decl) && !empty($decl[$scope]);
    }

    /** Scopes on which [provider] can serve [capability]. */
    public function capabilityScopes(string $provider, string $capability): array
    {
        $scopes = ($capability === self::CAP_RENDER) ? self::PLATFORMS : [self::SCOPE_SERVER];
        $out = [];
        foreach ($scopes as $s) {
            if ($this->supportsCapability($provider, $capability, $s)) { $out[] = $s; }
        }
        return $out;
    }

    /**
     * Providers that could serve [capability] on [scope]. Registry-level only —
     * it says nothing about whether a tenant has configured or activated one.
     */
    public function providersForCapability(string $capability, string $scope = self::SCOPE_SERVER): array
    {
        $out = [];
        foreach (array_keys(self::PROVIDERS) as $p) {
            if ($this->supportsCapability($p, $capability, $scope)) { $out[] = $p; }
        }
        return $out;
    }

    /**
     * Is [capability] implemented by ZenX at all?
     *
     * Deliberately explicit. A provider may advertise geocoding while ZenX has
     * no geocoding service — resolving one would hand a caller a provider it
     * cannot use. Flip a value here only when the service actually ships.
     */
    public function capabilityImplemented(string $capability): bool
    {
        return $capability === self::CAP_RENDER;   // geocode/route: not built
    }

    // ─────────────────────────────────────────────────────────────────────
    //  PLATFORM DEFAULT + TENANT OVERRIDE  (2026-08-16)
    //
    //  Inheritance is DYNAMIC. A tenant stores the word "inherit", never a
    //  resolved provider, so changing the platform default moves every
    //  inheriting school with ZERO tenant writes. Copying the platform config
    //  into each tenant would produce N documents that silently drift — the
    //  failure this design exists to prevent.
    // ─────────────────────────────────────────────────────────────────────

    /** Platform document. Fixed doc id — never getEntity(), never tenant-scoped. */
    public function getPlatformConfig(): array
    {
        $this->_assertReady();
        $doc = null;
        try { $doc = $this->fs->get(self::COL_PLATFORM_CONFIG, self::PLATFORM_DOC); } catch (\Throwable $_) {}
        if (is_array($doc) && isset($doc['data']) && is_array($doc['data'])) { $doc = $doc['data']; }
        if (!is_array($doc)) { $doc = []; }
        $caps = is_array($doc['capabilities'] ?? null) ? $doc['capabilities'] : [];
        foreach (self::CAPABILITIES as $c) {
            if (!is_array($caps[$c] ?? null)) {
                $caps[$c] = ['provider' => '', 'enabled' => false, 'validation_status' => self::VALIDATION_UNTESTED];
            }
        }
        return [
            'capabilities'      => $caps,
            // Guardrail metadata ONLY. commercial_status is NEVER evidence that
            // a licence exists — it records what a human asserted, nothing more.
            'environment'       => (string) ($doc['environment'] ?? 'dev'),
            'commercial_status' => (string) ($doc['commercial_status'] ?? 'unknown'),
            'commercial_note'   => (string) ($doc['commercial_note'] ?? ''),
            'updated_at'        => $doc['updated_at'] ?? null,
            'updated_by'        => (string) ($doc['updated_by'] ?? ''),
        ];
    }

    /** What this tenant asked for on [capability]: a provider id, or "inherit". */
    public function tenantCapabilitySource(string $capability, ?array $cfg = null): string
    {
        $cfg = is_array($cfg) ? $cfg : $this->getConfig();
        $src = $cfg['capability_source'][$capability] ?? null;
        if (is_string($src) && trim($src) !== '') { return trim($src); }

        // LEGACY COMPATIBILITY — a tenant configured before this feature has no
        // capability_source. If it has a working provider assignment it must
        // keep resolving EXACTLY as before; only a blank tenant inherits.
        if ($capability === self::CAP_RENDER) {
            $legacy = $this->resolveProvider(self::PLATFORM_ADMIN);
            if ($legacy !== '') { return $legacy; }
        }
        return self::SOURCE_INHERIT;
    }

    /**
     * 5C (2026-08-16) — set this tenant's source for one capability.
     *
     * The ONLY thing that writes `capability_source`. It writes that key and
     * nothing else: no provider entry, no credentials, and never a platform
     * document. Returning to inherit therefore does NOT delete the tenant's
     * stored provider configuration — the configuration simply stops being
     * consulted, so a school can switch back and forth without re-entering
     * credentials.
     *
     * `$source` is either SOURCE_INHERIT or a provider id. Storing the word
     * "inherit" rather than the currently-resolved provider is what keeps
     * inheritance DYNAMIC: a later platform change moves this tenant with zero
     * writes here. Resolving the platform provider and storing THAT would
     * silently freeze the tenant onto today's provider — the exact failure this
     * architecture exists to prevent.
     *
     * A provider that cannot render on ANY surface is refused: selecting one
     * would guarantee a permanent silent fallback, which looks like the
     * override "worked" while never taking effect. A provider that renders on
     * some surfaces but not others IS allowed — resolveCapability() falls back
     * per surface and returns a warning.
     */
    public function setTenantCapabilitySource(string $capability, string $source): array
    {
        $this->_assertReady();
        if (!in_array($capability, self::CAPABILITIES, true)) {
            return ['ok' => false, 'errors' => ['Unknown capability.']];
        }
        if (!$this->capabilityImplemented($capability)) {
            return ['ok' => false, 'errors' => ['ZenX does not implement this capability yet.']];
        }

        $source = trim($source);
        if ($source !== self::SOURCE_INHERIT) {
            if (!isset(self::PROVIDERS[$source])) {
                return ['ok' => false, 'errors' => ['Unknown provider.']];
            }
            if ($this->capabilityScopes($source, $capability) === []) {
                return ['ok' => false, 'errors' => [
                    (self::PROVIDERS[$source]['label'] ?? $source)
                    . ' cannot provide this capability on any surface, so it cannot be selected.']];
            }
        }

        $cfg    = $this->getConfig();
        $before = (string) ($cfg['capability_source'][$capability] ?? '');
        $map    = (array) ($cfg['capability_source'] ?? []);
        $map[$capability] = $source;

        // MERGE write of exactly one key. `providers` is untouched, so the
        // tenant keeps whatever credentials it had.
        $this->fs->setEntity(self::COL_CONFIG, $this->schoolId, [
            'schoolId'          => $this->schoolId,
            'capability_source' => $map,
            'updated_at'        => date('c'),
            'updated_by'        => $this->adminId,
        ], /* merge */ true);

        // audit() is (action, subject, detail) — a 4th argument would be
        // silently dropped by PHP, losing the "after" state, so before/after
        // are carried inside the single detail array. No credential value is
        // involved here: a source is a provider id or the word "inherit".
        $this->audit('capability_source_changed', $capability, [
            'before' => $before !== '' ? $before : '(unset)',
            'after'  => $source,
        ]);

        return ['ok' => true, 'capability' => $capability, 'source' => $source];
    }

    /**
     * THE resolution entry point. Returns the state, the provider and where it
     * came from — never just a provider, because a caller that cannot tell
     * INHERITED from OVERRIDE_INVALID cannot render an honest message.
     *
     * Config faults DEGRADE to the platform default with a warning; a school
     * admin's typo must not blank the map for parents and drivers. Security
     * and authorization failures are handled elsewhere and stay fail-closed.
     *
     * @return array{state:string, provider:string, source:string, warning:string}
     */
    public function resolveCapability(string $capability, string $scope = self::SCOPE_SERVER, ?array $cfg = null): array
    {
        $out = static fn(string $s, string $p, string $src, string $w = '') =>
            ['state' => $s, 'provider' => $p, 'source' => $src, 'warning' => $w];

        if (!$this->capabilityImplemented($capability)) {
            return $out(self::ST_CAP_NOT_IMPLEMENTED, '', '');
        }

        $cfg      = is_array($cfg) ? $cfg : $this->getConfig();
        $platform = $this->getPlatformConfig();
        $warning  = '';

        // 1. Tenant override, if any.
        //
        // `providers` must be cast: _defaultConfig() seeds it as new stdClass()
        // (so it serialises to a JSON object, not []), and a tenant that has
        // never saved a provider therefore carries an OBJECT here. Indexing it
        // as an array is a fatal "Cannot use object of type stdClass as array".
        // The unit stub always supplies an array, so 45 green tests missed this
        // — it only appeared when a real school selected a provider it had not
        // yet configured.
        $provs = (array) ($cfg['providers'] ?? []);
        $src   = $this->tenantCapabilitySource($capability, $cfg);
        if ($src !== self::SOURCE_INHERIT) {
            $entry = $provs[$src] ?? [];
            if (!isset(self::PROVIDERS[$src])) {
                $warning = "This school is set to an unknown map provider ('{$src}'). "
                         . 'Falling back to the ZenX platform default.';
            } elseif (!$this->supportsCapability($src, $capability, $scope)) {
                $warning = (self::PROVIDERS[$src]['label'] ?? $src)
                         . ' cannot provide this capability here. Falling back to the platform default.';
            } elseif (empty($entry['enabled'])) {
                $warning = 'This school\'s map provider is not activated. Falling back to the platform default.';
            } else {
                return $out(self::ST_OVERRIDDEN, $src, 'tenant');
            }
            // fall through to platform — deliberately, with the warning kept.
        }

        // 2. Platform default.
        $pc = $platform['capabilities'][$capability] ?? [];
        $pp = trim((string) ($pc['provider'] ?? ''));
        if ($pp === '' || empty($pc['enabled'])) {
            return $out(self::ST_PLATFORM_NOT_CONFIGURED, '', 'platform', $warning);
        }
        if (!isset(self::PROVIDERS[$pp]) || !$this->supportsCapability($pp, $capability, $scope)) {
            return $out(self::ST_PROVIDER_UNSUPPORTED, '', 'platform', $warning);
        }
        $state = ($warning !== '') ? self::ST_OVERRIDE_INVALID : self::ST_INHERITED;
        return $out($state, $pp, 'platform', $warning);
    }

    // ─── PLATFORM WRITE PATH — superadmin backend only ───────────────────
    // These are called ONLY from the superadmin controller, which sits behind
    // MY_Superadmin_Controller (sa_id session). There is deliberately no
    // school-facing entry point: Maps_config (the tenant page) has no method
    // that reaches them, and a test asserts that absence.

    /**
     * Save the platform provider + PUBLIC credentials for one capability.
     *
     * Saving always resets validation to untested and DISABLES the capability —
     * changed credentials have not been proven, and leaving a platform default
     * live across an unverified edit would take every inheriting school's map
     * down at once. Same discipline the tenant path already uses, with more at
     * stake because the blast radius is the whole platform.
     *
     * Secrets never travel here; they go to platformSecrets via saveSecret().
     */
    public function savePlatformCapability(string $capability, string $provider, array $public): array
    {
        $this->_assertReady();
        if (!in_array($capability, self::CAPABILITIES, true)) {
            return ['ok' => false, 'errors' => ['Unknown capability.']];
        }
        if (!$this->capabilityImplemented($capability)) {
            return ['ok' => false, 'errors' => ['ZenX does not implement this capability yet.']];
        }
        if ($provider !== '' && !isset(self::PROVIDERS[$provider])) {
            return ['ok' => false, 'errors' => ['Unknown provider.']];
        }
        $cfg    = $this->getPlatformConfig();
        $before = $cfg['capabilities'][$capability] ?? [];

        // A blank field means "keep what is stored", which is what the form
        // placeholder promises — a saved credential is never echoed back, so a
        // blank box is the ONLY thing the superadmin can submit for an
        // unchanged key. Replacing the map outright therefore wiped the live
        // platform credential on any re-save (e.g. correcting a style URL), and
        // on the platform default that takes maps down for every inheriting
        // school at once. Carry existing values forward instead.
        //
        // Carried forward ONLY when the provider is unchanged: switching
        // provider must not leave the previous provider's keys behind.
        $keepFrom = (($before['provider'] ?? '') === $provider)
            ? (array) ($before['public'] ?? [])
            : [];

        $clean = [];
        foreach ((array) (self::PROVIDERS[$provider]['credentials'] ?? []) as $key => $meta) {
            if (($meta['sensitivity'] ?? self::CRED_PUBLIC) !== self::CRED_PUBLIC) { continue; }
            $v = trim((string) ($public[$key] ?? ''));
            if ($v === '' && isset($keepFrom[$key])) { $v = (string) $keepFrom[$key]; }
            if ($v !== '') { $clean[$key] = $v; }
        }

        // Required credentials are enforced HERE rather than only at Test time.
        // A config that can be saved but never tested is a trap: it looks saved,
        // and the reason only appears one click later.
        $missing = [];
        foreach ((array) (self::PROVIDERS[$provider]['credentials'] ?? []) as $key => $meta) {
            if (($meta['sensitivity'] ?? self::CRED_PUBLIC) !== self::CRED_PUBLIC) { continue; }
            if (!empty($meta['required']) && !isset($clean[$key])) {
                $missing[] = (string) ($meta['label'] ?? $key);
            }
        }
        if ($provider !== '' && $missing !== []) {
            return ['ok' => false, 'errors' => ['Required: ' . implode(', ', $missing) . '.']];
        }
        $cfg['capabilities'][$capability] = [
            'provider'          => $provider,
            'enabled'           => false,                        // must re-validate
            'validation_status' => self::VALIDATION_UNTESTED,
            'validated_at'      => null,
            'public'            => $clean,
            'updated_at'        => date('c'),
            'updated_by'        => $this->adminId,
        ];
        $this->writePlatform($cfg);
        $this->auditPlatform('platform_provider_saved', $capability, $before, $cfg['capabilities'][$capability]);
        return ['ok' => true, 'config' => $cfg['capabilities'][$capability]];
    }

    /** Record a validation outcome for a platform capability. */
    public function setPlatformValidation(string $capability, bool $valid, string $error = ''): array
    {
        $this->_assertReady();
        $cfg = $this->getPlatformConfig();
        if (!isset($cfg['capabilities'][$capability])) { return ['ok' => false, 'errors' => ['Unknown capability.']]; }
        $before = $cfg['capabilities'][$capability];
        $cfg['capabilities'][$capability]['validation_status'] = $valid ? self::VALIDATION_VALID : self::VALIDATION_INVALID;
        $cfg['capabilities'][$capability]['validated_at']      = date('c');
        $cfg['capabilities'][$capability]['validation_error']  = $valid ? '' : substr($error, 0, 300);
        if (!$valid) { $cfg['capabilities'][$capability]['enabled'] = false; }
        $this->writePlatform($cfg);
        $this->auditPlatform('platform_validated', $capability, $before, $cfg['capabilities'][$capability]);
        return ['ok' => true, 'valid' => $valid];
    }

    /**
     * Activate a platform capability. Two guards, both refusals:
     *   • it must have passed validation — an unproven default would blank
     *     every inheriting school at once;
     *   • a credential recorded as free/non-commercial cannot go live in a
     *     PRODUCTION environment. commercial_status is metadata, never proof of
     *     a licence — this guard only BLOCKS an obviously wrong combination, it
     *     never authorises one.
     */
    public function activatePlatformCapability(string $capability): array
    {
        $this->_assertReady();
        $cfg   = $this->getPlatformConfig();
        $entry = $cfg['capabilities'][$capability] ?? null;
        if (!is_array($entry) || ($entry['provider'] ?? '') === '') {
            return ['ok' => false, 'errors' => ['Configure a provider first.']];
        }
        if (($entry['validation_status'] ?? '') !== self::VALIDATION_VALID) {
            return ['ok' => false, 'errors' => ['Test the configuration successfully before activating.']];
        }
        if (($cfg['environment'] ?? 'dev') === 'production'
            && ($cfg['commercial_status'] ?? 'unknown') === 'free_noncommercial') {
            return ['ok' => false, 'errors' => [
                'This credential is recorded as free / non-commercial and cannot be activated in a '
                . 'PRODUCTION environment. Record the commercial arrangement first.']];
        }
        $before = $entry;
        $cfg['capabilities'][$capability]['enabled'] = true;
        $this->writePlatform($cfg);
        $this->auditPlatform('platform_activated', $capability, $before, $cfg['capabilities'][$capability]);
        return ['ok' => true];
    }

    public function deactivatePlatformCapability(string $capability): array
    {
        $this->_assertReady();
        $cfg = $this->getPlatformConfig();
        if (!isset($cfg['capabilities'][$capability])) { return ['ok' => false, 'errors' => ['Unknown capability.']]; }
        $before = $cfg['capabilities'][$capability];
        $cfg['capabilities'][$capability]['enabled'] = false;
        $this->writePlatform($cfg);
        $this->auditPlatform('platform_deactivated', $capability, $before, $cfg['capabilities'][$capability]);
        return ['ok' => true];
    }

    /** Environment + commercial metadata. Guardrails, not licence evidence. */
    public function savePlatformMeta(string $environment, string $commercialStatus, string $note): array
    {
        $this->_assertReady();
        $envs = ['dev', 'uat', 'production'];
        $coms = ['unknown', 'free_noncommercial', 'paid', 'custom'];
        if (!in_array($environment, $envs, true))        { return ['ok' => false, 'errors' => ['Invalid environment.']]; }
        if (!in_array($commercialStatus, $coms, true))   { return ['ok' => false, 'errors' => ['Invalid commercial status.']]; }
        $cfg    = $this->getPlatformConfig();
        $before = ['environment' => $cfg['environment'], 'commercial_status' => $cfg['commercial_status']];
        $cfg['environment']       = $environment;
        $cfg['commercial_status'] = $commercialStatus;
        $cfg['commercial_note']   = substr(trim($note), 0, 500);
        $this->writePlatform($cfg);
        $this->auditPlatform('platform_meta_saved', '-', $before,
            ['environment' => $environment, 'commercial_status' => $commercialStatus]);
        return ['ok' => true];
    }

    /** Persist the platform document. Fixed doc id, never tenant-scoped. */
    private function writePlatform(array $cfg): void
    {
        $cfg['updated_at'] = date('c');
        $cfg['updated_by'] = $this->adminId;
        $this->fs->set(self::COL_PLATFORM_CONFIG, self::PLATFORM_DOC, $cfg, false);
    }

    /**
     * Platform audit. Records WHICH credential keys changed, never their
     * values — the trail must not become a key archive.
     */
    private function auditPlatform(string $action, string $capability, array $before, array $after): void
    {
        $strip = static function (array $e): array {
            $e['public'] = array_keys((array) ($e['public'] ?? []));   // key NAMES only
            unset($e['secret'], $e['secrets']);
            return $e;
        };
        try {
            $this->fs->set(self::COL_AUDIT, 'platform_' . str_replace('.', '', (string) microtime(true)), [
                'scope'      => 'platform',
                'action'     => $action,
                'capability' => $capability,
                'before'     => $strip($before),
                'after'      => $strip($after),
                'actor'      => $this->adminId,
                'at'         => date('c'),
            ], false);
        } catch (\Throwable $_) { /* audit must never block a config change */ }
    }

    /** Public credentials for a resolved provider, from the right layer. */
    public function credentialsFor(string $provider, string $source, ?array $cfg = null): array
    {
        if ($provider === '') { return []; }
        if ($source === 'platform') {
            $pc = $this->getPlatformConfig()['capabilities'] ?? [];
            foreach ($pc as $entry) {
                if (($entry['provider'] ?? '') === $provider) { return (array) ($entry['public'] ?? []); }
            }
            return [];
        }
        $cfg = is_array($cfg) ? $cfg : $this->getConfig();
        // Same stdClass hazard as resolveCapability() — cast before indexing.
        $provs = (array) ($cfg['providers'] ?? []);
        return (array) ($provs[$provider]['public'] ?? []);
    }
    /** Platforms where [provider] can actually draw. */
    public function supportedPlatforms(string $provider): array
    {
        $out = [];
        foreach (self::PLATFORMS as $p) {
            if ($this->isSupportedOn($provider, $p)) { $out[] = $p; }
        }
        return $out;
    }

    /**
     * A provider usable in GLOBAL mode must render on every platform that HAS a
     * map surface — global means "everywhere", so a provider that works on one
     * and not the other cannot honestly be global.
     */
    public function isGlobalCapable(string $provider): bool
    {
        foreach (self::PLATFORMS_WITH_SURFACE as $p) {
            if (!$this->isSupportedOn($provider, $p)) { return false; }
        }
        return true;
    }

    /**
     * Resolve the provider a given platform must use.
     *
     * The whole point of the platform: one call, and no caller ever branches on
     * provider identity. In global mode every platform gets the same answer; in
     * platform mode each gets its own, falling back to the global provider when
     * that platform has not been given an explicit one — so switching to
     * platform mode never blanks a working map.
     */
    public function resolveProvider(string $platform): string
    {
        $cfg = $this->getConfig();
        if (!in_array($platform, self::PLATFORMS, true)) { return ''; }
        if (($cfg['mode'] ?? self::MODE_GLOBAL) === self::MODE_GLOBAL) {
            return (string) ($cfg['global_provider'] ?? '');
        }
        $p = (string) ($cfg['platform_provider'][$platform] ?? '');
        return $p !== '' ? $p : (string) ($cfg['global_provider'] ?? '');
    }

    /**
     * The payload one platform needs to render — and nothing else.
     *
     * Returns only the resolved provider's PUBLIC credentials. Other providers'
     * settings and every secret are excluded, so a compromised client leaks
     * only what its own map already sends over the wire.
     */
    public function getClientConfig(string $platform): array
    {
        $cfg = $this->getConfig();

        // 5A (2026-08-16) — THE PRODUCTION CONNECTION.
        //
        // This used to call resolveProvider(), which reads the TENANT document
        // only. The Phase 4 inheritance engine therefore had zero production
        // callers: platformConfig could be configured, validated and active
        // while every school still resolved to nothing. The unit tests passed
        // because they exercised resolveCapability() directly — the engine was
        // proven and simply not plugged in.
        //
        // Routing this through resolveCapability() is what makes a school
        // actually inherit. credentialsFor() then takes the public credentials
        // from WHICHEVER layer resolved, so an inheriting tenant receives the
        // platform's public key and an overriding tenant receives its own —
        // and neither ever receives the other's, nor any secret.
        $r        = $this->resolveCapability(self::CAP_RENDER, $platform, $cfg);
        $provider = (string) $r['provider'];
        $source   = (string) $r['source'];

        return [
            'platform'       => $platform,
            'provider'       => $provider,
            'renderer'       => (string) (self::PROVIDERS[$provider]['renderer'] ?? ''),
            // Whether THIS platform can actually draw with the resolved
            // provider. A client that receives false must say so rather than
            // render a blank map.
            'supported'      => $provider !== '' && $this->isSupportedOn($provider, $platform),
            // A resolved provider is by definition an ACTIVE one: resolution
            // rejects a disabled tenant override and a disabled platform
            // capability. Keeping the key preserves the existing client
            // contract — fleet_live.php and MapRendererFactory both read it.
            'enabled'        => $provider !== '',
            'credentials'    => $this->credentialsFor($provider, $source, $cfg),
            'default_center' => $cfg['default_center'] ?? ['lat' => null, 'lng' => null],
            'default_zoom'   => (float) ($cfg['default_zoom'] ?? self::DEFAULT_ZOOM),
            'feature_flags'  => (array) ($cfg['feature_flags'] ?? []),
            // ADDITIVE — existing consumers ignore these; the school settings
            // page uses them to say WHY, and to surface a fallback warning
            // instead of silently switching a school's provider.
            'state'          => (string) $r['state'],
            'source'         => $source,
            'warning'        => (string) $r['warning'],
        ];
    }

    // ─────────────────────────────────────────────────────────────────────
    //  Write
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Persist one provider's credentials, splitting them by sensitivity.
     *
     * Saving always resets validation to `untested` and DEACTIVATES the
     * provider. Credentials that changed have not been proven, and leaving a
     * provider live across an unverified edit is how a whole tenant's maps go
     * dark on a typo.
     *
     * @return array{ok:bool, errors:array<int,string>}
     */
    public function saveProvider(string $provider, array $values): array
    {
        $this->_assertReady();
        if (!isset(self::PROVIDERS[$provider])) {
            return ['ok' => false, 'errors' => ["Unknown provider '{$provider}'."]];
        }
        $spec   = self::PROVIDERS[$provider];
        $errors = [];
        $public = [];
        $secret = [];

        $cfg       = $this->getConfig();
        $providers = (array) (is_array($cfg['providers'] ?? null) ? $cfg['providers'] : []);

        // 5B (2026-08-16) — BLANK MEANS "KEEP", NOT "CLEAR".
        //
        // A stored credential is never echoed back to the browser and inputs
        // are never pre-filled, so a blank box is the ONLY thing an admin can
        // submit for a credential they are not changing. Treating blank as
        // missing made the required-check fire, so a school could not re-save
        // ANY setting without re-typing its API key from scratch — and if it
        // had been mislaid, the configuration became uneditable.
        //
        // The carry-forward source is $providers[$provider], which is keyed BY
        // PROVIDER. Cross-contamination is therefore impossible by
        // construction: switching MapTiler → Google reads $providers['google'],
        // which holds no MapTiler values. A provider change with blank required
        // fields still fails the check below, exactly as before.
        $keep = (array) ($providers[$provider]['public'] ?? []);

        foreach ($spec['credentials'] as $key => $meta) {
            $val = trim((string) ($values[$key] ?? ''));
            if ($val === '' && ($meta['sensitivity'] ?? self::CRED_PUBLIC) !== self::CRED_SECRET
                && isset($keep[$key]) && $keep[$key] !== '') {
                $public[$key] = (string) $keep[$key];   // unchanged — carry forward
                continue;
            }
            if ($val === '') {
                // A blank SECRET is also "keep": secrets live in a separate
                // document and are written only when supplied, so omitting one
                // leaves the stored value untouched (merge write below).
                if (!empty($meta['required'])
                    && ($meta['sensitivity'] ?? self::CRED_PUBLIC) !== self::CRED_SECRET) {
                    $errors[] = "{$meta['label']} is required.";
                }
                continue;
            }
            if ($meta['sensitivity'] === self::CRED_SECRET) { $secret[$key] = $val; }
            else                                            { $public[$key] = $val; }
        }
        if ($errors !== []) { return ['ok' => false, 'errors' => $errors]; }
        $providers[$provider] = [
            'public'            => $public,
            'enabled'           => false,                       // must re-validate
            'validation_status' => self::VALIDATION_UNTESTED,
            'validated_at'      => null,
            'validation_error'  => '',
            'updated_at'        => date('c'),
            'updated_by'        => $this->adminId,
        ];

        $this->fs->setEntity(self::COL_CONFIG, $this->schoolId, [
            'schoolId'   => $this->schoolId,
            'providers'  => $providers,
            'updated_at' => date('c'),
            'updated_by' => $this->adminId,
        ], /* merge */ true);

        if ($secret !== []) {
            $this->fs->setEntity(self::COL_SECRETS, $this->schoolId, [
                'schoolId'   => $this->schoolId,
                $provider    => $secret,
                'updated_at' => date('c'),
                'updated_by' => $this->adminId,
            ], /* merge */ true);
        }

        $this->audit('provider_saved', $provider, [
            'public_keys' => array_keys($public),
            'secret_keys' => array_keys($secret),   // NAMES only — never values
        ]);
        return ['ok' => true, 'errors' => []];
    }

    /**
     * Prove a provider's credentials actually work.
     *
     * A real outbound request, not a shape check: a well-formed key that the
     * provider rejects is exactly the failure this must catch, and it is the
     * gate `activateProvider()` depends on.
     *
     * @return array{ok:bool, status:string, message:string}
     */
    public function validateProvider(string $provider): array
    {
        $this->_assertReady();
        if (!isset(self::PROVIDERS[$provider])) {
            return ['ok' => false, 'status' => self::VALIDATION_INVALID, 'message' => 'Unknown provider.'];
        }
        $cfg   = $this->getConfig();
        $entry = (array) (is_array($cfg['providers'] ?? null) ? ($cfg['providers'][$provider] ?? []) : []);
        $creds = (array) ($entry['public'] ?? []) + $this->_secretsFor($provider);

        $probe = self::PROVIDERS[$provider]['probe'] ?? [];
        $result = $this->_runProbe($probe, $creds);

        $providers = (array) (is_array($cfg['providers'] ?? null) ? $cfg['providers'] : []);
        $providers[$provider] = array_merge($entry, [
            'validation_status' => $result['ok'] ? self::VALIDATION_VALID : self::VALIDATION_INVALID,
            'validated_at'      => date('c'),
            'validation_error'  => $result['ok'] ? '' : $result['message'],
        ]);
        $this->fs->setEntity(self::COL_CONFIG, $this->schoolId,
            ['providers' => $providers, 'updated_at' => date('c')], true);

        $this->audit($result['ok'] ? 'validation_passed' : 'validation_failed', $provider,
            ['message' => $result['message']]);

        return ['ok' => $result['ok'],
                'status' => $result['ok'] ? self::VALIDATION_VALID : self::VALIDATION_INVALID,
                'message' => $result['message']];
    }

    /**
     * Enable a provider for use.
     *
     * **Refuses unless validation currently passes** — requirement §4. Without
     * this an administrator could activate a mistyped key and take out every
     * map in the tenant, with the breakage surfacing on parents' phones rather
     * than in the admin UI.
     *
     * @return array{ok:bool, message:string}
     */
    public function activateProvider(string $provider): array
    {
        $this->_assertReady();
        $cfg   = $this->getConfig();
        $entry = (array) (is_array($cfg['providers'] ?? null) ? ($cfg['providers'][$provider] ?? []) : []);
        if ($entry === []) {
            return ['ok' => false, 'message' => 'Configure the provider before activating it.'];
        }
        if (($entry['validation_status'] ?? '') !== self::VALIDATION_VALID) {
            return ['ok' => false, 'message' => 'Run a successful connection test before activating this provider.'];
        }
        $providers = (array) $cfg['providers'];
        $providers[$provider] = array_merge($entry, ['enabled' => true, 'updated_at' => date('c')]);
        $this->fs->setEntity(self::COL_CONFIG, $this->schoolId,
            ['providers' => $providers, 'updated_at' => date('c'), 'updated_by' => $this->adminId], true);
        $this->audit('provider_enabled', $provider, []);
        return ['ok' => true, 'message' => 'Provider activated.'];
    }

    /** Disable a provider. Never blocked — turning something off must always work. */
    public function deactivateProvider(string $provider): array
    {
        $this->_assertReady();
        $cfg   = $this->getConfig();
        $providers = (array) (is_array($cfg['providers'] ?? null) ? $cfg['providers'] : []);
        if (!isset($providers[$provider])) { return ['ok' => false, 'message' => 'Provider not configured.']; }
        $providers[$provider] = array_merge((array) $providers[$provider],
            ['enabled' => false, 'updated_at' => date('c')]);
        $this->fs->setEntity(self::COL_CONFIG, $this->schoolId,
            ['providers' => $providers, 'updated_at' => date('c'), 'updated_by' => $this->adminId], true);
        $this->audit('provider_disabled', $provider, []);
        return ['ok' => true, 'message' => 'Provider disabled.'];
    }

    /**
     * Set the operating mode and the provider assignments.
     *
     * Refuses to point any platform at a provider that is not enabled — the
     * one check that stops a mode switch silently blanking a live map.
     *
     * @return array{ok:bool, errors:array<int,string>}
     */
    public function saveMode(string $mode, string $globalProvider, array $platformProviders): array
    {
        $this->_assertReady();
        if (!in_array($mode, self::MODES, true)) {
            return ['ok' => false, 'errors' => ["Unknown mode '{$mode}'."]];
        }
        $cfg    = $this->getConfig();
        $errors = [];

        $isEnabled = function (string $p) use ($cfg): bool {
            if ($p === '') { return true; }            // "unset" is allowed
            $e = is_array($cfg['providers'] ?? null) ? ($cfg['providers'][$p] ?? []) : [];
            return !empty($e['enabled']);
        };

        if ($mode === self::MODE_GLOBAL) {
            if ($globalProvider === '') { $errors[] = 'Choose a provider for global mode.'; }
            elseif (!$isEnabled($globalProvider)) {
                $errors[] = 'The global provider must be activated first.';
            } elseif (!$this->isGlobalCapable($globalProvider)) {
                // Refuse rather than silently store an unusable configuration.
                $missing = array_values(array_diff(self::PLATFORMS_WITH_SURFACE,
                                                   $this->supportedPlatforms($globalProvider)));
                $errors[] = (self::PROVIDERS[$globalProvider]['label'] ?? $globalProvider)
                    . ' cannot be used in Global mode — it has no renderer for: '
                    . implode(', ', $missing) . '. Use Platform mode instead.';
            }
        }
        $clean = array_fill_keys(self::PLATFORMS, '');
        foreach (self::PLATFORMS as $plat) {
            $p = trim((string) ($platformProviders[$plat] ?? ''));
            if ($p !== '' && !isset(self::PROVIDERS[$p])) { $errors[] = "Unknown provider '{$p}' for {$plat}."; continue; }
            if ($mode === self::MODE_PLATFORM && $p !== '') {
                if (!$isEnabled($p)) {
                    $errors[] = ucfirst($plat) . ' is pointed at a provider that is not activated.';
                }
                // Enforced only on platforms that actually draw a map. `teacher`
                // and `driver` have configuration plumbing but no map UI, so an
                // assignment there renders nothing either way — the admin page
                // labels them rather than blocking a harmless choice.
                if (in_array($plat, self::PLATFORMS_WITH_SURFACE, true) && !$this->isSupportedOn($p, $plat)) {
                    $errors[] = (self::PROVIDERS[$p]['label'] ?? $p) . ' has no ' . $plat
                        . ' renderer yet, so it cannot be assigned to that platform.';
                }
            }
            $clean[$plat] = $p;
        }
        if ($errors !== []) { return ['ok' => false, 'errors' => $errors]; }

        $this->fs->setEntity(self::COL_CONFIG, $this->schoolId, [
            'schoolId'          => $this->schoolId,
            'mode'              => $mode,
            'global_provider'   => $globalProvider,
            'platform_provider' => $clean,
            'updated_at'        => date('c'),
            'updated_by'        => $this->adminId,
        ], /* merge */ true);

        $this->audit('mode_changed', $mode, ['global' => $globalProvider, 'platform' => $clean]);
        return ['ok' => true, 'errors' => []];
    }

    /** Default map centre + zoom for this tenant. */
    public function saveDefaults(?float $lat, ?float $lng, float $zoom): array
    {
        $this->_assertReady();
        $this->load_helper_geofence();
        if ($lat !== null && $lng !== null && !gf_valid_coord($lat, $lng)) {
            return ['ok' => false, 'errors' => ['Default centre is not a valid coordinate.']];
        }
        if ($zoom < 1.0 || $zoom > 22.0) {
            return ['ok' => false, 'errors' => ['Zoom must be between 1 and 22.']];
        }
        $this->fs->setEntity(self::COL_CONFIG, $this->schoolId, [
            'default_center' => ['lat' => $lat, 'lng' => $lng],
            'default_zoom'   => $zoom,
            'updated_at'     => date('c'),
            'updated_by'     => $this->adminId,
        ], true);
        $this->audit('defaults_changed', '', ['lat' => $lat, 'lng' => $lng, 'zoom' => $zoom]);
        return ['ok' => true, 'errors' => []];
    }

    // ─────────────────────────────────────────────────────────────────────
    //  Audit
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Append an audit row.
     *
     * Credential VALUES are never recorded — only which keys were touched.
     * An audit trail that quietly archives every API key a tenant has ever
     * used is a breach waiting for someone to read the logs.
     */
    public function audit(string $action, string $subject, array $detail): void
    {
        try {
            $id = $this->schoolId . '_' . (int) (microtime(true) * 1000) . '_' . bin2hex(random_bytes(3));
            $this->fs->setEntity(self::COL_AUDIT, $id, [
                'schoolId'   => $this->schoolId,
                'action'     => $action,
                'subject'    => $subject,
                'detail'     => $detail,
                'actor'      => $this->adminId,
                'created_at' => date('c'),
            ], false);
        } catch (\Throwable $e) {
            log_message('error', 'Maps_config_service audit failed: ' . $e->getMessage());
        }
    }

    /** Most recent audit rows, newest first. */
    public function auditHistory(int $limit = 25): array
    {
        $this->_assertReady();
        try {
            $rows = (array) $this->fs->schoolWhere(self::COL_AUDIT, [], 'created_at', 'DESC', $limit);
            return array_map(static function ($r) {
                return (isset($r['data']) && is_array($r['data'])) ? $r['data'] : $r;
            }, $rows);
        } catch (\Throwable $e) {
            return [];
        }
    }

    // ─────────────────────────────────────────────────────────────────────
    //  Internals
    // ─────────────────────────────────────────────────────────────────────

    /** Server-only credentials. Never leaves the server. */
    private function _secretsFor(string $provider): array
    {
        try {
            $doc = $this->fs->getEntity(self::COL_SECRETS, $this->schoolId);
            return is_array($doc) && is_array($doc[$provider] ?? null) ? $doc[$provider] : [];
        } catch (\Throwable $_) { return []; }
    }

    /**
     * Execute a provider probe.
     *
     * @return array{ok:bool, message:string}
     */
    private function _runProbe(array $probe, array $creds): array
    {
        $type = (string) ($probe['type'] ?? '');
        if ($type === '') { return ['ok' => false, 'message' => 'No connection test defined for this provider.']; }

        $url = '';
        if ($type === 'style_json') {
            $url = trim((string) ($creds[(string) ($probe['from'] ?? 'style_url')] ?? ''));
            if ($url === '') { return ['ok' => false, 'message' => 'Style URL is empty.']; }
        } else {
            $url = (string) ($probe['url'] ?? '');
            foreach ($creds as $k => $v) { $url = str_replace('{' . $k . '}', rawurlencode((string) $v), $url); }
            if (preg_match('/\{[a-z_]+\}/', $url)) {
                return ['ok' => false, 'message' => 'Required credential missing for the connection test.'];
            }
        }
        if (!preg_match('#^https://#i', $url)) {
            return ['ok' => false, 'message' => 'Only https endpoints may be tested.'];
        }
        if (!function_exists('curl_init')) {
            return ['ok' => false, 'message' => 'ext-curl unavailable on this server.'];
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        $body = (string) curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $ctype = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        $err  = curl_error($ch);
        curl_close($ch);

        if ($code === 0)   { return ['ok' => false, 'message' => 'Could not reach the provider: ' . $err]; }
        if ($code === 401 || $code === 403) {
            return ['ok' => false, 'message' => "Provider rejected the credentials (HTTP {$code}). "
                . 'Check the key and any referrer / package restrictions on it.'];
        }
        if ($code >= 400)  { return ['ok' => false, 'message' => "Provider returned HTTP {$code}."]; }

        // ── HTTP 200 IS NOT PROOF OF A WORKING KEY ───────────────────────
        // Several providers answer a bad credential with 200 and an error
        // payload. Mapbox returns {"code":"TokenMalformed"}; Google's Static
        // Maps API returns an HTML error page instead of an image. Trusting
        // the status alone would mark a broken key "valid" and let it through
        // the activation gate — the precise failure that gate exists to stop,
        // and one that would only surface later on parents' phones.
        $expect = (string) ($probe['expect'] ?? ($type === 'style_json' ? 'style_json' : ''));

        if ($type === 'style_json' || $expect === 'style_json') {
            $json = json_decode($body, true);
            if (!is_array($json) || !isset($json['version'])) {
                return ['ok' => false, 'message' => 'The URL responded but is not a MapLibre/Mapbox style document.'];
            }
        } elseif ($expect === 'image') {
            if (stripos($ctype, 'image/') !== 0) {
                return ['ok' => false, 'message' =>
                    'Provider returned ' . ($ctype !== '' ? $ctype : 'a non-image response')
                    . ' instead of a map tile — the key is almost certainly rejected or restricted.'];
            }
        } elseif ($expect === 'json_field') {
            $json  = json_decode($body, true);
            $field = (string) ($probe['field'] ?? '');
            $want  = (string) ($probe['equals'] ?? '');
            if (!is_array($json)) {
                return ['ok' => false, 'message' => 'Provider returned a non-JSON response.'];
            }
            $got = (string) ($json[$field] ?? '');
            if ($got !== $want) {
                return ['ok' => false, 'message' =>
                    'Provider rejected the credentials' . ($got !== '' ? " ({$got})" : '') . '.'];
            }
        } elseif ($expect === 'json_has') {
            $json  = json_decode($body, true);
            $field = (string) ($probe['field'] ?? '');
            if (!is_array($json) || !array_key_exists($field, $json)) {
                return ['ok' => false, 'message' => 'Provider responded but not with the expected payload.'];
            }
        }
        return ['ok' => true, 'message' => "Connection test passed (HTTP {$code})."];
    }

    private function load_helper_geofence(): void
    {
        if (!function_exists('gf_valid_coord')) { get_instance()->load->helper('geofence'); }
    }
}
