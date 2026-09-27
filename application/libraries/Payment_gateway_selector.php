<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * ══════════════════════════════════════════════════════════════════════════
 *  GW-SEL-1 — WHICH PAYMENT ADAPTER MAY COLLECT, GIVEN A CONFIG
 * ══════════════════════════════════════════════════════════════════════════
 *
 * The adapter choice used to be four lines inline in
 * `Fee_management::_init_payment_service()`, where it could not be executed by
 * a test — a CI controller cannot be instantiated without the framework. So the
 * only available assertions were string matches against the controller source,
 * which prove the code LOOKS right and not that it BEHAVES right. For a
 * decision that governs whether real money is collected through a forgeable
 * adapter, that is not good enough.
 *
 * The decision itself is pure: config in, adapter name out. Extracted here so
 * it can be driven directly. The controller keeps the wiring (`load->library`),
 * which is the part that genuinely needs the framework.
 *
 * ── WHY IT FAILS CLOSED ───────────────────────────────────────────────────
 * `Payment_gateway_mock` signs with 'mock_secret_key_for_dev', a constant in
 * this repository, and its `fetch_payment()` returns `mock_skip_amount_check`,
 * which disables amount enforcement outright. Collecting through it mints
 * receipts, clears demands and posts journals for money that never arrived.
 *
 * The previous inline logic was `if razorpay … else MOCK`, so ANY config
 * problem — absent document, unset provider, typo — silently selected the mock.
 * A census on 2026-08-24 found exactly one gateway document across nine
 * tenants: under that rule the other eight would each have collected through
 * the mock the moment a parent paid.
 *
 * Refusing to collect is recoverable — a school configures a gateway.
 * Collecting through a forgeable adapter is not: the books show money that
 * never arrived, and the receipt is already in a parent's hands.
 */
class Payment_gateway_selector
{
    public const RAZORPAY    = 'razorpay';
    public const MOCK        = 'mock';
    public const UNAVAILABLE = 'unavailable';

    /** Environments in which the forgeable mock may be selected at all. */
    public const SIMULATOR_ENVIRONMENTS = ['development', 'testing'];

    /**
     * Decide which adapter may serve this configuration.
     *
     * Never throws and never guesses: every path that is not a positively
     * recognised, active, real provider returns UNAVAILABLE.
     *
     * @param mixed  $gwConfig    the tenant's gateway document, or anything at
     *                            all if the read failed — non-arrays included.
     * @param string $environment CI's ENVIRONMENT for this deployment.
     * @return string one of RAZORPAY | MOCK | UNAVAILABLE
     */
    public static function select($gwConfig, string $environment): string
    {
        // A failed or absent read is NOT a reason to fall back to a simulator.
        if (!is_array($gwConfig)) {
            return self::UNAVAILABLE;
        }

        // An explicitly disabled gateway must not collect. Absent means active:
        // this mirrors Admission_public::_resolve_gateway() exactly rather than
        // inventing a second rule, and the census confirms no tenant stores
        // `active: false` today, so enforcing it strands nobody.
        //
        // Fees previously ignored this field altogether, so a school that had
        // deliberately switched its gateway off still collected fees through it.
        if (array_key_exists('active', $gwConfig) && empty($gwConfig['active'])) {
            return self::UNAVAILABLE;
        }

        // Firestore will happily hand back an array or object here — a
        // malformed write, a partial migration. Casting one to string raises
        // "Array to string conversion" INSIDE the money path, so the type is
        // checked before the cast rather than after. A non-scalar provider is
        // not a provider.
        $rawProvider = $gwConfig['provider'] ?? '';
        if (!is_scalar($rawProvider)) {
            return self::UNAVAILABLE;
        }

        $provider = strtolower(trim((string) $rawProvider));

        if ($provider === self::RAZORPAY) {
            // Credentials are NOT checked here. An empty key or secret makes
            // the adapter refuse at `_assert_credentials()`, which fails closed
            // on its own — whereas returning UNAVAILABLE for missing keys would
            // conflate "not configured" with "misconfigured" in the logs, and
            // both already refuse to collect.
            return self::RAZORPAY;
        }

        if ($provider === self::MOCK
            && in_array($environment, self::SIMULATOR_ENVIRONMENTS, true)) {
            // Explicitly chosen, and only off a real deployment.
            return self::MOCK;
        }

        return self::UNAVAILABLE;
    }

    /**
     * True when the selection refuses to collect. Purely for readability at the
     * call sites, which should log loudly rather than proceed quietly.
     */
    public static function refuses(string $selection): bool
    {
        return $selection === self::UNAVAILABLE;
    }

    // ══════════════════════════════════════════════════════════════════════
    //  PAY-OPS-7 · "development" IS THE DEFAULT, NOT A STATEMENT OF FACT
    // ══════════════════════════════════════════════════════════════════════

    /**
     * Harden a claimed environment before it is allowed to unlock the simulator.
     *
     * ── THE HOLE THIS CLOSES ────────────────────────────────────────────
     * `index.php` resolves:
     *
     *     define('ENVIRONMENT', isset($_SERVER['CI_ENV']) ? $_SERVER['CI_ENV'] : 'development');
     *
     * and `CI_ENV` is set NOWHERE in this repository — not in `.htaccess`, not
     * in either deployment runbook. Unless the production vhost happens to set
     * it (a file that is not in git), **production runs as `development`**.
     *
     * Every protection in this class that keys on the environment is then
     * inverted on the live server: `SIMULATOR_ENVIRONMENTS` contains
     * 'development', so `Payment_gateway_mock` — which validates signatures
     * against `mock_secret_key_for_dev`, a constant in this repository, and
     * disables amount enforcement — becomes a selectable provider in
     * production. GW-1's entire argument rests on this value being true.
     *
     * Latent rather than live today: a tenant must also have `provider: 'mock'`
     * saved, and the census found none. But `save_gateway_config()` validates
     * against this same list, so on a box running as 'development' an admin
     * could save it — and then collect real fees through a forgeable adapter.
     *
     * ── WHY NOT JUST CHANGE index.php's DEFAULT ─────────────────────────
     * Because that default is also what makes local development work. Flipping
     * it to 'production' would silently change error reporting and config
     * resolution for the whole application, for every developer, to fix a money
     * -path problem. Too broad a blast radius for the defect.
     *
     * ── THE RULE ────────────────────────────────────────────────────────
     * A simulator environment is believed only when something CORROBORATES it:
     *
     *   • `CI_ENV` was explicitly set  → the operator declared it. Trust it.
     *   • the request is on loopback   → genuine local development. Trust it.
     *   • otherwise                     → a public host claiming to be
     *                                     'development' on an unset variable.
     *                                     Treat as production.
     *
     * Non-simulator environments pass through untouched: this only ever makes
     * the answer stricter, never more permissive.
     *
     * @param string $environment  what ENVIRONMENT resolved to
     * @param bool   $ciEnvExplicit whether CI_ENV was actually present
     * @param string $host         the request host (may be empty on CLI)
     */
    public static function harden_environment(string $environment, bool $ciEnvExplicit, string $host): string
    {
        if (!in_array($environment, self::SIMULATOR_ENVIRONMENTS, true)) {
            return $environment;
        }
        if ($ciEnvExplicit) {
            return $environment;
        }

        // ── HOST NORMALISATION, IPv6-SAFE ───────────────────────────────
        // A naive "strip a trailing :port" pass CORRUPTS bare IPv6: `::1`
        // ends with `:1`, so it becomes `:` and stops being loopback — which
        // silently pushed local IPv6 development onto the production branch.
        // Caught by this method's own test.
        //
        // So the three shapes are handled distinctly:
        //   [::1] / [::1]:8080   bracketed — the brackets delimit the address
        //   ::1                  bare IPv6 — more than one colon, never ported
        //   localhost:8080       host or IPv4 — a single trailing :port
        $bare = strtolower(trim($host));

        if ($bare !== '' && $bare[0] === '[') {
            $bare = preg_replace('~^\[([^\]]+)\](?::\d+)?$~', '$1', $bare);
        } elseif (substr_count($bare, ':') <= 1) {
            $bare = preg_replace('~:\d+$~', '', $bare);
        }

        // CLI has no host. A CLI run is a developer or a cron on the box
        // itself, never a public request, so the claimed environment stands.
        if ($bare === '') {
            return $environment;
        }

        return in_array($bare, ['localhost', '127.0.0.1', '::1'], true)
            ? $environment
            : 'production';
    }

    /**
     * The environment every payment call site should use, hardened as above.
     * Thin wrapper so the four call sites cannot each get the gathering wrong;
     * the decision itself lives in `harden_environment()`, which is pure and
     * directly testable.
     */
    public static function effective_environment(): string
    {
        $env = defined('ENVIRONMENT') ? ENVIRONMENT : 'production';
        return self::harden_environment(
            $env,
            isset($_SERVER['CI_ENV']) && trim((string) $_SERVER['CI_ENV']) !== '',
            (string) ($_SERVER['HTTP_HOST'] ?? '')
        );
    }

    /**
     * ── P3 (2026-08-24) · THE REGISTRY HALF OF SELECTION ────────────────────
     *
     * `select()` answers "which provider may collect, given this config" —
     * a decision. This answers "what class implements that provider" — a
     * lookup. They stay in the SAME file deliberately: the directive for this
     * phase is explicit that a second, competing provider-selection authority
     * must not exist, and a lookup table that drifted from the decision table
     * would be exactly that.
     *
     * Used at the two callback/webhook parsing sites in Fee_management.php,
     * which run BEFORE Payment_service is initialized (the raw gateway config
     * has to be read first to know which provider is even configured) — so
     * they cannot reach the live, credentialed adapter Payment_service holds.
     * `parse_callback()` needs no credentials and touches no instance state on
     * any adapter, so a throwaway, uncredentialed instance built from this
     * lookup is sufficient and safe: no order can be created, no signature can
     * be (successfully) verified, no payment can be fetched through it.
     *
     * @return class-string|null null for any provider this registry cannot
     *         resolve — callers must fail closed on null, never guess.
     */
    // ══════════════════════════════════════════════════════════════════════
    //  THE PROVIDER CATALOGUE — ONE SOURCE OF TRUTH
    // ══════════════════════════════════════════════════════════════════════

    /**
     * Provider lifecycle. "An adapter file exists" and "a school may collect
     * real money through it" are NOT the same statement, and conflating them
     * is exactly how PA-UI-1 happened (the admin UI offered Stripe and Paytm
     * for years while the server rejected both).
     *
     * A provider advances one stage at a time, on evidence:
     *
     *   IMPLEMENTED — an adapter exists and satisfies the interface.
     *                 NOT selectable. NOT shown to schools.
     *   CERTIFIED   — additionally verified against the provider's REAL API
     *                 (sandbox or live). Still not shown, pending the client
     *                 side.
     *   ENABLED     — additionally supported end to end, including a checkout
     *                 engine the Parent App actually ships. Selectable by a
     *                 school and shown in the admin UI.
     *
     * Only ENABLED providers may be configured or resolved for real money.
     */
    public const STAGE_IMPLEMENTED = 'implemented';
    public const STAGE_CERTIFIED   = 'certified';
    public const STAGE_ENABLED     = 'enabled';

    /**
     * The catalogue. Everything else — the config-screen allowlist, the admin
     * UI tiles, adapter resolution — is DERIVED from this. No second list
     * exists anywhere; `available_providers()` and `adapter_class()` are the
     * only readers, and the config screen calls the former.
     *
     * `simulator_only` marks a provider that must never run outside a
     * simulator environment regardless of stage.
     */
    private const CATALOGUE = [
        self::RAZORPAY => [
            'adapter'        => 'Payment_gateway_razorpay',
            'stage'          => self::STAGE_ENABLED,
            'display_name'   => 'Razorpay',
            'description'    => "India's leading payment gateway for online payments",
            'checkout_type'  => 'native_sdk',
            'simulator_only' => false,
            'credentials'    => [
                ['key' => 'api_key',        'label' => 'Key ID',         'secret' => false],
                ['key' => 'api_secret',     'label' => 'Key Secret',     'secret' => true],
                ['key' => 'webhook_secret', 'label' => 'Webhook Secret', 'secret' => true],
            ],
        ],

        self::MOCK => [
            'adapter'        => 'Payment_gateway_mock',
            'stage'          => self::STAGE_ENABLED,
            'display_name'   => 'Mock (development only)',
            'description'    => 'Simulator. Never available on a real deployment.',
            'checkout_type'  => 'native_sdk',
            'simulator_only' => true,
            'credentials'    => [],
        ],

        // Written against Cashfree's published API and passing the shared
        // contract suite, but NEVER exercised against the real Cashfree API —
        // ZenX holds no Cashfree merchant credentials. It is therefore
        // IMPLEMENTED and nothing more: not selectable, not offered to any
        // school. Advancing it requires (1) sandbox verification against the
        // live API, and (2) a Parent App that ships a `webview` checkout
        // engine, which this build does not. Both are evidence, not opinion.
        'cashfree' => [
            'adapter'        => 'Payment_gateway_cashfree',
            'stage'          => self::STAGE_IMPLEMENTED,
            'display_name'   => 'Cashfree Payments',
            'description'    => 'Implemented, pending sandbox certification and client support.',
            'checkout_type'  => 'webview',
            'simulator_only' => false,
            'credentials'    => [
                ['key' => 'api_key',        'label' => 'Client ID',      'secret' => false],
                ['key' => 'api_secret',     'label' => 'Client Secret',  'secret' => true],
                ['key' => 'webhook_secret', 'label' => 'Webhook Secret', 'secret' => true],
            ],
        ],
    ];

    /**
     * Providers a school may actually configure, with the metadata the admin
     * UI needs to render itself. Secrets are never included — only the FIELD
     * DEFINITIONS describing which credentials to collect.
     *
     * @param string $environment used to withhold simulator-only providers.
     * @return array<string, array> keyed by provider id
     */
    public static function available_providers(string $environment = 'production'): array
    {
        $isSimulatorEnv = in_array($environment, self::SIMULATOR_ENVIRONMENTS, true);

        $out = [];
        foreach (self::CATALOGUE as $id => $meta) {
            if ($meta['stage'] !== self::STAGE_ENABLED) continue;
            if (!empty($meta['simulator_only']) && !$isSimulatorEnv) continue;

            $out[$id] = [
                'id'            => $id,
                'display_name'  => $meta['display_name'],
                'description'   => $meta['description'],
                'checkout_type' => $meta['checkout_type'],
                'credentials'   => $meta['credentials'],
            ];
        }
        return $out;
    }

    /** Provider ids a school may save. THE allowlist — derived, never duplicated. */
    public static function selectable_provider_ids(string $environment = 'production'): array
    {
        return array_keys(self::available_providers($environment));
    }

    /** Lifecycle stage, or null for a provider not in the catalogue at all. */
    public static function provider_stage(string $providerId): ?string
    {
        $id = strtolower(trim($providerId));
        return self::CATALOGUE[$id]['stage'] ?? null;
    }

    /**
     * The adapter class for a provider that may actually run.
     *
     * Deliberately gated on ENABLED: an IMPLEMENTED-only adapter must be
     * unreachable from every runtime path, not merely absent from the UI. A
     * config row naming it — hand-written, migrated, or left over — must still
     * fail closed.
     *
     * @return class-string|null null for unknown OR not-yet-enabled providers;
     *         callers must fail closed on null, never guess.
     */
    public static function adapter_class(string $providerId): ?string
    {
        $id = strtolower(trim($providerId));
        $meta = self::CATALOGUE[$id] ?? null;
        if ($meta === null) return null;
        if ($meta['stage'] !== self::STAGE_ENABLED) return null;
        return $meta['adapter'];
    }

    /**
     * The whole "resolve provider → instantiate → parse, or fall back
     * generically" decision from the two controller call sites, pulled out
     * as one PURE function so it can be driven directly in a test — the same
     * reason `select()` itself was extracted from the controller originally.
     * A CI3 controller method cannot be unit-tested without bootstrapping the
     * framework; this can.
     *
     * @param mixed $gwConfig the tenant's gateway config (or anything, if the
     *                        read failed)
     * @param array $payload  the callback body / webhook payload to parse
     * @return array{order_id: string, payment_id: string, signature: string}
     */
    public static function parse_provider_callback($gwConfig, array $payload): array
    {
        $providerId = is_array($gwConfig) ? (string) ($gwConfig['provider'] ?? '') : '';
        $class = self::adapter_class($providerId);

        if ($class !== null && method_exists($class, 'parse_callback')) {
            $parsed = (new $class())->parse_callback($payload);
            return [
                'order_id'   => (string) ($parsed['order_id']   ?? ''),
                'payment_id' => (string) ($parsed['payment_id'] ?? ''),
                'signature'  => (string) ($parsed['signature']  ?? ''),
            ];
        }

        // Unresolvable provider — the same generic fallback
        // Payment_service::parse_callback() itself falls back to.
        return [
            'order_id'   => trim((string) ($payload['gateway_order_id'] ?? $payload['order_id'] ?? '')),
            'payment_id' => trim((string) ($payload['gateway_payment_id'] ?? $payload['payment_id'] ?? '')),
            'signature'  => trim((string) ($payload['signature'] ?? '')),
        ];
    }

    // ══════════════════════════════════════════════════════════════════════
    //  PAY-OPS-4 · WEBHOOK EVENT CLASSIFICATION
    // ══════════════════════════════════════════════════════════════════════

    /** May proceed toward settlement — subject to provider re-confirmation. */
    public const EVENT_SUCCESS = 'success';
    /** Explicitly unsuccessful (failed / abandoned). Never financial. */
    public const EVENT_FAILURE = 'failure';
    /** Unknown, unsupported, or not-yet-final. No financial side effect. */
    public const EVENT_IGNORE  = 'ignore';

    /**
     * ── WHY THIS ROUTES INSTEAD OF DECIDING ─────────────────────────────
     *
     * The event vocabulary belongs to the provider, so the decision belongs to
     * the adapter — exactly as `parse_provider_callback()` above delegates the
     * payload shape. This method's only jobs are to reach the right adapter
     * and to normalise whatever it returns into the three classes the handler
     * understands.
     *
     * ── THE FALLBACK IS IGNORE, AND THAT IS THE WHOLE POINT ─────────────
     *
     * `parse_provider_callback()` falls back to a generic top-level parse when
     * the provider cannot be resolved, because guessing an id is harmless —
     * a wrong id simply fails to find an order.
     *
     * Guessing an EVENT CLASS is not harmless. If this method could not reach
     * an adapter and returned SUCCESS, an unresolvable provider would settle
     * fees. So every failure mode here — unknown provider, adapter without the
     * method, adapter returning a class this method does not recognise, adapter
     * throwing — resolves to IGNORE. The handler treats IGNORE as "acknowledge
     * and write nothing", which is the only safe answer to "I do not know what
     * this delivery means".
     *
     * A class string that is not one of the three is treated as unrecognised
     * rather than trusted, so a future adapter returning `'maybe'` cannot
     * accidentally settle money by falling through a loose comparison.
     *
     * @param  mixed $gwConfig the tenant's gateway document (anything at all)
     * @param  array $payload  the decoded webhook body
     * @return array{class: string, event: string, reason: string, provider: string}
     */
    public static function classify_provider_event($gwConfig, array $payload): array
    {
        // is_scalar BEFORE the cast, not after. Firestore hands back an array
        // or object here for a malformed or half-migrated config, and casting
        // one to string raises "Array to string conversion" INSIDE the money
        // path. `select()` above already learned this in its own words ("a
        // non-scalar provider is not a provider"); this method had to learn it
        // separately, which is what its own test caught.
        $rawProvider = is_array($gwConfig) ? ($gwConfig['provider'] ?? '') : '';
        $providerId  = is_scalar($rawProvider) ? (string) $rawProvider : '';
        $class       = self::adapter_class($providerId);

        $unknown = function (string $reason) use ($providerId, $payload): array {
            // Same scalar guard on the envelope: it is network JSON, so its
            // types are attacker-shaped. See any adapter's classify_event().
            $rawEvent = $payload['event'] ?? ($payload['type'] ?? '');
            return [
                'class'    => self::EVENT_IGNORE,
                // Surface whichever envelope key is present so the operator log
                // names the delivery even when nothing could interpret it.
                'event'    => (is_scalar($rawEvent) && !is_bool($rawEvent)) ? trim((string) $rawEvent) : '',
                'reason'   => $reason,
                'provider' => $providerId,
            ];
        };

        if ($class === null || !method_exists($class, 'classify_event')) {
            return $unknown($class === null
                ? "provider '{$providerId}' is not in the catalogue — cannot interpret its events"
                : "adapter for '{$providerId}' does not classify events");
        }

        try {
            $verdict = (new $class())->classify_event($payload);
        } catch (\Throwable $e) {
            // An adapter that throws has told us nothing. It must not thereby
            // become a success.
            return $unknown("adapter for '{$providerId}' threw while classifying: " . $e->getMessage());
        }

        if (!is_array($verdict)) {
            return $unknown("adapter for '{$providerId}' returned a non-array verdict");
        }

        $verdictClass = (string) ($verdict['class'] ?? '');
        if (!in_array($verdictClass, [self::EVENT_SUCCESS, self::EVENT_FAILURE, self::EVENT_IGNORE], true)) {
            return $unknown("adapter for '{$providerId}' returned unrecognised class '{$verdictClass}'");
        }

        return [
            'class'    => $verdictClass,
            // An adapter is trusted to classify, not to have returned strings.
            'event'    => is_scalar($verdict['event']  ?? null) ? (string) $verdict['event']  : '',
            'reason'   => is_scalar($verdict['reason'] ?? null) ? (string) $verdict['reason'] : '',
            'provider' => $providerId,
        ];
    }
}
