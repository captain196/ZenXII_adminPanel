<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Rollout_flag_service — answers "is technical rollout flag X switched on for
 * THIS TENANT", so a behaviour change can be proven on one school before it
 * reaches the estate.
 *
 * ─────────────────────────────────────────────────────────────────────────
 *  WHY THIS EXISTS
 * ─────────────────────────────────────────────────────────────────────────
 *  Every flag in the Fees/Entitlements programme is a process-global PHP
 *  config value read with NO school argument:
 *
 *      Fees::_m5RegistryModeOn()        → config->item('USE_FEE_HEAD_REGISTRY')
 *      Fee_lifecycle::_registryModeOn() → same
 *      Fee_lifecycle.php:571            → USE_ENTITLEMENT_BILLING
 *      Fee_lifecycle.php:632            → USE_ENTITLEMENT_SERVICE_PRICING
 *      Fee_generation_service.php:242   → ENTITLEMENT_SHADOW_COMPARE
 *
 *  So "enable M5 for the UAT tenant only" was not expressible, and the M5
 *  activation gate stopped there (DEC-005 / B-004). This service supplies the
 *  missing tenant dimension for those FOUR gates at once.
 *
 * ─────────────────────────────────────────────────────────────────────────
 *  WHAT IT IS NOT
 * ─────────────────────────────────────────────────────────────────────────
 *  • NOT a second feature-flag master. The PHP config file remains the sole
 *    author of the GLOBAL DEFAULT; callers read their own default and pass it
 *    in. This service only answers "does this tenant OVERRIDE that default?".
 *  • NOT a commercial entitlement. `tenantPublic.activeModules` answers "has
 *    this tenant BOUGHT module X" and is derived from plans + subscription.
 *    Rollout state is "is this behaviour SAFE here yet" — a different question
 *    with a different lifetime and a different owner. Keeping them apart is
 *    what stops a subscription edit from silently moving a financial switch.
 *    Deliberately a SIBLING field on schoolControl, never inside activeModules.
 *  • NOT an RBAC gate. Three independent questions, three gates:
 *        has_permission()   → can THIS USER?
 *        has_capability()   → has THIS TENANT bought it?
 *        isEnabled()        → is it ROLLED OUT here yet?
 *
 * ─────────────────────────────────────────────────────────────────────────
 *  RESOLUTION
 * ─────────────────────────────────────────────────────────────────────────
 *      enabled = tenantOverride ?? globalDefault
 *
 *  The tenant value wins in BOTH directions: an override may switch a flag on
 *  where the global default is off (the rollout case), and off where the
 *  global default is on (a per-tenant kill switch, without an estate-wide
 *  rollback).
 *
 *  FAIL CLOSED, and note what "closed" means here: it is NOT "abort". A
 *  missing document, a malformed entry, an unreadable collection or an expired
 *  grant all resolve to the GLOBAL DEFAULT — which for every flag in this
 *  programme is `false`, i.e. today's proven legacy behaviour. A rollout flag
 *  that cannot be read must never invent a rollout.
 *
 *  (Distinct from M5's OWN fail-closed rule: once M5 is on, an unregistered
 *  fee head still aborts generation. Different layer, deliberately.)
 *
 * ─────────────────────────────────────────────────────────────────────────
 *  STORAGE — no new collection, no rules change, no index
 * ─────────────────────────────────────────────────────────────────────────
 *      schoolControl/{schoolId}.rolloutFlags {
 *          "USE_FEE_HEAD_REGISTRY": {
 *              enabled:   true,
 *              setBy:     "SUP0001",
 *              setAt:     "2026-08-18T10:00:00Z",
 *              reason:    "M5 UAT gate",
 *              expiresAt: null            // optional auto-revert
 *          }
 *      }
 *
 *  `schoolControl` is already server-only and already the per-tenant control
 *  document (subscription, overrides, featureFlags). A bare boolean is also
 *  accepted — the same tolerance `B2_registry_service::_normalize_overrides()`
 *  applies, so a hand-authored value cannot silently fail to take effect.
 *
 *  ⚠ NOTHING IN PRODUCTION CALLS THIS YET. Routing the existing gate methods
 *  through it is a separate, separately-authorized phase (T2). Until then this
 *  class is inert: constructing it performs no read, and no caller exists.
 */
class Rollout_flag_service
{
    /** The per-tenant control document. Already server-only in the rules. */
    public const COL = 'schoolControl';

    /** Sibling of `featureFlags`/`overrides` — never inside activeModules. */
    public const FIELD = 'rolloutFlags';

    /** @var object|null Firebase client (needs firestoreGet()). */
    private $firebase;
    private string $schoolId = '';

    /**
     * Request-scoped memo of this tenant's rollout map. Deliberately NOT a
     * cross-request cache: a grant or a revocation must take effect on the
     * next request, not after a TTL — the same reasoning as
     * Fee_head_resolver's memo.
     */
    private ?array $cache = null;

    /**
     * Bind to a Firebase client + school scope. Idempotent; re-init with a
     * DIFFERENT schoolId clears the memo, so one tenant's rollout state can
     * never leak into another's within a single request. Mirrors
     * Tenant_capability_service::init() exactly.
     */
    public function init($firebase, string $schoolId): self
    {
        if ($this->schoolId !== $schoolId) {
            $this->cache = null;
        }
        $this->firebase = $firebase;
        $this->schoolId = $schoolId;
        return $this;
    }

    /**
     * Is this flag on for this tenant?
     *
     * @param string $key           flag name, e.g. 'USE_FEE_HEAD_REGISTRY'
     * @param bool   $globalDefault the value from the PHP config — the caller
     *                              owns its own default; this service never
     *                              becomes a second config master.
     */
    public function isEnabled(string $key, bool $globalDefault = false): bool
    {
        return (bool) $this->resolve($key, $globalDefault)['enabled'];
    }

    /**
     * The same answer, with its provenance — for an operator surface, a probe
     * or an audit line that has to explain WHY a flag reads the way it does.
     *
     * @return array{enabled:bool, source:string, reason:string}
     *         source: 'global' | 'tenant'
     */
    public function resolve(string $key, bool $globalDefault = false): array
    {
        $global = ['enabled' => $globalDefault, 'source' => 'global', 'reason' => ''];

        $key = trim($key);
        if ($key === '' || $this->schoolId === '') {
            return $global + [];
        }

        $flags = $this->flags();
        if (!array_key_exists($key, $flags)) {
            return $global;   // no override — the overwhelmingly common case
        }

        $row = $flags[$key];

        // Bare boolean: accepted deliberately. A hand-authored `true` must not
        // silently fail to take effect — same tolerance as
        // B2_registry_service::_normalize_overrides().
        if (is_bool($row)) {
            return ['enabled' => $row, 'source' => 'tenant', 'reason' => 'bare_boolean'];
        }

        if (!is_array($row) || !array_key_exists('enabled', $row) || !is_bool($row['enabled'])) {
            // Malformed. Fall back to the global default and SAY SO — a
            // silently ignored rollout grant is exactly the class of defect
            // this programme keeps closing.
            log_message('error', 'ROLLOUT_FLAG_MALFORMED school=[' . $this->schoolId
                . '] key=[' . $key . '] — falling back to the global default.');
            return ['enabled' => $globalDefault, 'source' => 'global', 'reason' => 'malformed_override'];
        }

        // Optional auto-revert. An expired grant is not an error; it is a
        // grant that has done its job.
        $expiresAt = trim((string) ($row['expiresAt'] ?? ''));
        if ($expiresAt !== '') {
            $ts = strtotime($expiresAt);
            if ($ts === false) {
                log_message('error', 'ROLLOUT_FLAG_BAD_EXPIRY school=[' . $this->schoolId
                    . '] key=[' . $key . '] expiresAt=[' . $expiresAt . '] — treating as expired.');
                return ['enabled' => $globalDefault, 'source' => 'global', 'reason' => 'unparsable_expiry'];
            }
            if ($ts <= time()) {
                return ['enabled' => $globalDefault, 'source' => 'global', 'reason' => 'expired'];
            }
        }

        return ['enabled' => (bool) $row['enabled'], 'source' => 'tenant',
                'reason'  => trim((string) ($row['reason'] ?? ''))];
    }

    /** Every override on file for this tenant — for an audit/probe surface. */
    public function all(): array
    {
        return $this->flags();
    }

    /**
     * The tenant's rollout map, read at most once per request.
     *
     * Any failure yields an EMPTY map, which makes every resolve() fall back
     * to its caller's global default. An unreadable control document must
     * never be interpreted as "everything rolled out".
     */
    private function flags(): array
    {
        if ($this->cache !== null) {
            return $this->cache;
        }
        if ($this->firebase === null || $this->schoolId === '') {
            return $this->cache = [];
        }
        try {
            $doc = $this->firebase->firestoreGet(self::COL, $this->schoolId);
            $map = is_array($doc) ? ($doc[self::FIELD] ?? []) : [];
            $map = is_array($map) ? $map : [];

            // ── ROLLOUT-READ-1 · AN EMPTY READ IS NOT PROOF OF NO GRANTS ─────
            //
            // firestoreGet() returns null on a transient failure WITHOUT
            // throwing — the same silent-empty class as query(). That made this
            // method answer "this tenant has no overrides" for a tenant that
            // has several, and the answer is then cached for the whole request.
            //
            // WHAT IS ACTUALLY PROVEN, AND WHAT IS NOT. Identical runs of the
            // ledger runtime proof over identical data were observed alternating
            // between the ENTITLEMENT and the LEGACY pricing authority — a
            // difference that decides HOW MUCH A STUDENT IS CHARGED and whether
            // the allocation ledger can emit at all. That flapping is real and
            // was seen repeatedly.
            //
            // Its CAUSE is not proven. The runs in question were taken while
            // this file's caller was being rewritten between iterations, which
            // is an equally good explanation, and once that stopped the proof
            // ran deterministically four times with this guard NEVER FIRING.
            //
            // So this is a GUARD, not a demonstrated repair: it costs one extra
            // read only for a tenant with no control document at all, and it
            // converts a silent money-affecting transient into a logged event if
            // one ever does occur. If ROLLOUT_FLAG_READ_FLAP never appears in
            // production logs, that is the expected outcome, not proof it was
            // needed.
            //
            // "No document" is legitimate — most tenants have none — so the two
            // cases cannot be separated by one read. They can be separated by
            // two: a transient is unlikely to repeat immediately, while a truly
            // absent document reads empty every time. One confirming re-read
            // collapses the flap without inventing state, and says so when it
            // changes the answer.
            if ($map === []) {
                $doc2 = $this->firebase->firestoreGet(self::COL, $this->schoolId);
                $map2 = is_array($doc2) ? ($doc2[self::FIELD] ?? []) : [];
                $map2 = is_array($map2) ? $map2 : [];
                if ($map2 !== []) {
                    log_message('error', 'ROLLOUT_FLAG_READ_FLAP school=[' . $this->schoolId
                        . '] — the first ' . self::COL . ' read came back empty and the '
                        . 'confirming re-read returned ' . count($map2) . ' flag(s). The first '
                        . 'read was a silent transient; without this re-read every per-tenant '
                        . 'grant would have been invisible for this request.');
                    $map = $map2;
                }
            }

            $this->cache = $map;
        } catch (\Throwable $e) {
            log_message('error', 'Rollout_flag_service: ' . self::COL . ' read failed for '
                . $this->schoolId . ': ' . $e->getMessage());
            $this->cache = [];
        }
        return $this->cache;
    }
}
