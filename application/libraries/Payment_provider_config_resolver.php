<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * ══════════════════════════════════════════════════════════════════════════
 *  P4 — SCHOOL-SPECIFIC, MULTI-PROVIDER CONFIGURATION (2026-08-24)
 * ══════════════════════════════════════════════════════════════════════════
 *
 * The product requirement: each school configures its OWN payment provider,
 * and can change it later. Today's model — one `feeSettings/{school}_{session}
 * _gateway` document — holds exactly one provider per school per session, with
 * no notion of "configured but not active" or "the school has more than one
 * provider on file."
 *
 * ── THE MODEL ──────────────────────────────────────────────────────────────
 * New, ADDITIVE collection: `paymentProviders/{schoolId}_{providerId}`
 *
 *   schoolId, providerId, providerType, displayName
 *   active       bool  — this provider may be selected at all
 *   isDefault    bool  — exactly one true per school is "the" active choice
 *   mode         'test'|'live'
 *   api_key, api_secret, webhook_secret   — server-only, never client-readable
 *   createdAt, updatedAt
 *
 * Deliberately school-scoped, NOT session-scoped: provider credentials are a
 * merchant-account property, not an academic-year property. The existing
 * `feeSettings` doc IS session-scoped, and the architecture audit already
 * found the consequence live: `2025-26` is ABSENT for the one configured
 * tenant while `2026-27` exists — a session rollover silently drops the
 * gateway config under that model. The new model does not carry that defect
 * forward.
 *
 * ── WHY THIS IS SAFE TO WIRE IN NOW, WITHOUT MIGRATING ANY LIVE TENANT ──────
 * `resolve()` tries `paymentProviders` FIRST, and falls back to the existing
 * `feeSettings/{school}_{session}_gateway` doc when nothing is found there.
 * No `paymentProviders` document has been created for any tenant by this
 * change — the census in the architecture audit found ONE existing
 * `feeSettings` gateway doc across nine tenants, and it is untouched.
 * `test_falls_back_byte_identically_when_no_paymentProviders_doc_exists`
 * proves the fallback path returns the EXACT same document the old direct
 * read would have, so every existing tenant's behaviour — configured or
 * not — is unchanged until an explicit, separate, future action creates a
 * `paymentProviders` document for it.
 *
 * `Payment_gateway_selector::select()` itself is NOT touched by this file —
 * it already decides correctly given a config; this only changes WHERE that
 * config is found.
 */
class Payment_provider_config_resolver
{
    private const COL_PROVIDERS = 'paymentProviders';

    /** Registry verdicts — see resolve() for why three states are required. */
    private const RESOLVED   = 'resolved';
    private const NO_OPINION = 'no_opinion';
    private const REFUSE     = 'refuse';
    private const COL_LEGACY    = 'feeSettings';

    /**
     * @param object $firebase   the Firestore helper (get + query)
     * @param string $schoolId
     * @param string $schoolName legacy doc-id convention uses the school
     *                           NAME, not the id — kept exactly as today
     * @param string $session
     * @return array|null the resolved gateway config, in the SAME shape
     *         Payment_gateway_selector::select() already expects, or null if
     *         nothing could be resolved at all (selector treats null the
     *         same as any non-array — UNAVAILABLE).
     */
    public static function resolve($firebase, string $schoolId, string $schoolName, string $session): ?array
    {
        [$verdict, $config] = self::_resolveFromProviderRegistry($firebase, $schoolId);

        // ── THREE-STATE VERDICT, NOT A NULLABLE ────────────────────────────
        // The registry can say three genuinely different things, and collapsing
        // any two of them into `null` is a money-safety defect:
        //
        //   RESOLVED   → this school's active provider. Use it.
        //   NO_OPINION → this school has NO registry rows at all. It has not
        //                been migrated, so the legacy document is still its
        //                real configuration. Fall back.
        //   REFUSE     → this school HAS registry rows, but none is usable
        //                (all deactivated, ambiguous defaults, malformed).
        //                The school has expressed an intent and the answer is
        //                "no". Falling back to legacy here would collect money
        //                through a gateway the school deliberately switched
        //                off — see the defect note on REFUSE below.
        if ($verdict === self::RESOLVED)  return $config;
        if ($verdict === self::REFUSE)    return null;

        return self::_resolveLegacy($firebase, $schoolId, $schoolName, $session);
    }

    /**
     * The school's active provider from the NEW model: the row with
     * `isDefault === true` AND `active === true`.
     *
     * ── WHY THIS RETURNS A VERDICT RATHER THAN `?array` ───────────────────
     * It originally returned null for BOTH "no rows at all" and "rows exist
     * but none usable", and `resolve()` fell back to legacy on either. That
     * was a real, reproduced defect: a school that DEACTIVATED its provider
     * silently resumed collecting through the legacy Razorpay config — the
     * exact "do not silently fall back" case the architecture forbids.
     *
     * Once a school has ANY row in this collection it has been migrated, and
     * the registry — not the legacy document — is authoritative for it. That
     * includes authoritatively saying "no provider is currently usable".
     *
     * @return array{0:string,1:?array} [verdict, config]
     */
    private static function _resolveFromProviderRegistry($firebase, string $schoolId): array
    {
        // No query capability at all (a bare double, a legacy client) is NOT
        // the registry refusing — it is the registry being unreachable.
        if (!method_exists($firebase, 'firestoreQuery')) {
            return [self::NO_OPINION, null];
        }

        try {
            $rows = $firebase->firestoreQuery(self::COL_PROVIDERS, [
                ['schoolId', '==', $schoolId],
            ]);
        } catch (\Exception $e) {
            // A transport/index failure is not a decision either. Falling back
            // to the legacy document keeps an already-working school paying
            // during a transient Firestore problem, which is why this is
            // NO_OPINION and not REFUSE.
            log_message('error', 'Payment_provider_config_resolver: registry query failed for school ['
                . $schoolId . ']: ' . $e->getMessage());
            return [self::NO_OPINION, null];
        }

        $rows = is_array($rows) ? $rows : [];

        // Rows belonging to THIS school only. A query is not a substitute for
        // re-asserting tenant identity on the returned data.
        $ownRows = [];
        foreach ($rows as $row) {
            $data = is_array($row['data'] ?? null) ? $row['data'] : $row;
            if (!is_array($data)) continue;
            if ((string) ($data['schoolId'] ?? '') !== $schoolId) {
                log_message('error', 'Payment_provider_config_resolver: TENANT MISMATCH — a row for '
                    . 'school [' . (string) ($data['schoolId'] ?? '?') . '] was returned while '
                    . 'resolving [' . $schoolId . ']. Discarded.');
                continue;
            }
            $ownRows[] = $data;
        }

        // Not migrated. The legacy document is genuinely this school's config.
        if (count($ownRows) === 0) {
            return [self::NO_OPINION, null];
        }

        $defaults = [];
        foreach ($ownRows as $data) {
            if (!empty($data['isDefault']) && !empty($data['active'])) {
                $defaults[] = $data;
            }
        }

        if (count($defaults) === 1) {
            return [self::RESOLVED, $defaults[0]];
        }

        if (count($defaults) > 1) {
            log_message('error', 'Payment_provider_config_resolver: MULTIPLE default active '
                . 'providers for school [' . $schoolId . '] — refusing to guess which is '
                . 'authoritative. No payment will be created until exactly one is default.');
        } else {
            log_message('error', 'Payment_provider_config_resolver: school [' . $schoolId
                . '] has ' . count($ownRows) . ' configured provider(s) but NONE is both active '
                . 'and default. Refusing to collect. NOT falling back to legacy config — the '
                . 'school has deliberately deactivated its provider(s).');
        }

        return [self::REFUSE, null];
    }

    /** Byte-identical to what every existing call site already does directly. */
    private static function _resolveLegacy($firebase, string $schoolId, string $schoolName, string $session): ?array
    {
        $cfg = $firebase->firestoreGet(self::COL_LEGACY, "{$schoolName}_{$session}_gateway");
        return is_array($cfg) ? $cfg : null;
    }
}
