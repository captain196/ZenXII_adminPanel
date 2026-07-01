<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Wave B2.3.2 — Firestore-authoritative registry service.
 *
 * Single Firestore-only data-access surface for the entire B2 read+write
 * co-cutover. Every call site that currently reads or writes RTDB for the
 * B2 surface (registry / subscriptions / plans / billing / school codes /
 * status gate) delegates here when `b2.registry_firestore` is TRUE.
 *
 * What this is NOT:
 *   - NOT a dual-write dispatcher. It NEVER writes RTDB.
 *   - NOT a fallback layer. It NEVER reads RTDB.
 *   - NOT a compatibility mirror. Field shapes are the canonical Firestore
 *     shape (lifecycle.state ∈ {trialing,active,expiring_soon,grace,past_due,
 *     suspended,expired}; NOT the legacy RTDB strings). Callers handle
 *     translation, NOT this library.
 *   - NOT a shadow layer. Reads return Firestore truth or NULL (Firestore
 *     unreachable) — never a synthesized result.
 *
 * Build phases — methods land incrementally as sub-packages ship:
 *   B2.3.2-A (THIS BUILD) — flag gate + lifecycle access query for the
 *     MY_Controller per-request status check.
 *   B2.3.2-D — login-path methods (school code → schoolId, profile,
 *     entitlement features).
 *   B2.3.2-B — plan CRUD + billing read/write (payments, subscriptions).
 *   B2.3.2-C — school list + view + update + assign_plan + stats cache.
 *
 * Until cutover, this library is dormant: `firestore_authoritative()`
 * returns false and no production caller invokes any data method.
 *
 * @schema-locked  2026-05-30 (B2.3.2-A)
 */
class B2_registry_service
{
    // ── Canonical lifecycle states that PERMIT access ─────────────────
    // Mirror of B2_derive::ACCESS_STATES. Hardcoded here so MY_Controller
    // does not need to depend on B2_derive at read time.
    const ACCESS_LIFECYCLE_STATES = [
        'trialing',
        'active',
        'expiring_soon',
        'grace',
    ];

    /** @var object|null */ private $firebase   = null;
    /** @var object|null */ private $ci         = null;
    /** @var bool */        private $ready      = false;
    /** @var array|null */  private $flagsCache = null;
    /** @var array|null  Request-scoped memo for list_tenants_summary() (P0-1). */
    private $tenantsSummaryMemo = null;
    /** @var array  Request-scoped memo for per-school tenantPublic docs. */
    private $tenantPublicMemo = [];

    /**
     * Bind dependencies. Idempotent.
     */
    public function init($firebase, $ci = null): self
    {
        // P0-1 (request-scoped memo): only drop the memoized tenant summary
        // when the Firebase binding actually changes — a memo built against a
        // different data source would be invalid. Idempotent re-init with the
        // SAME binding (the common case, e.g. _b23_registry() re-init per call)
        // preserves the memo so it can actually hit across callers.
        if ($this->firebase !== $firebase) {
            $this->tenantsSummaryMemo = null;
            $this->tenantPublicMemo   = [];
        }
        $this->firebase = $firebase;
        $this->ci       = $ci ?? get_instance();
        $this->ready    = ($firebase !== null);
        return $this;
    }

    public function is_ready(): bool { return $this->ready; }

    // ─── FLAG GATE ────────────────────────────────────────────────────

    /**
     * Single atomic co-cutover gate. When TRUE, every B2 call site MUST
     * use this service. When FALSE, every B2 call site MUST use its
     * legacy RTDB path. Callers branch tightly around this; no fallback,
     * no dual-write.
     *
     * Cached per-request (CI3 library singleton) — flag changes require
     * a new request to take effect.
     */
    public function firestore_authoritative(): bool
    {
        if ($this->flagsCache === null) {
            $this->ci->config->load('b2_migration_flags', FALSE, TRUE);
            $this->flagsCache = $this->ci->config->item('b2_migration_flags') ?: [];
        }
        return !empty($this->flagsCache['b2.registry_firestore']);
    }

    // ─── B2.3.2-A: STATUS GATE READS ─────────────────────────────────

    /**
     * Read schoolControl/{schoolId}. Returns the document array or NULL
     * if Firestore is unreachable / doc absent. Callers MUST treat NULL
     * as "unknown" and follow the same fail-open behavior as the legacy
     * "Firebase unreachable" branch (do not force-logout on null).
     */
    public function get_school_control(string $schoolId): ?array
    {
        if (!$this->ready)     return null;
        if ($schoolId === '')  return null;
        try {
            return $this->firebase->firestoreGet('schoolControl', $schoolId);
        } catch (\Throwable $e) {
            log_message('error',
                'B2_registry_service::get_school_control firestore failed school=['
                . $schoolId . '] err=' . $e->getMessage()
            );
            return null;
        }
    }

    /**
     * Lifecycle-state access decision for the per-request bootstrap gate.
     *
     * Returns:
     *   ['known' => false]                                    — Firestore unreachable / doc absent
     *   ['known' => true, 'allowed' => bool, 'state' => str]  — authoritative decision
     *
     * Callers MUST NOT force-logout when `known === false` (matches the
     * legacy "Firebase unreachable → skip, retry next interval" contract).
     */
    public function lifecycle_access(string $schoolId): array
    {
        $sc = $this->get_school_control($schoolId);
        if (!is_array($sc)) return ['known' => false];
        $state = (string) ($sc['lifecycle']['state'] ?? '');
        if ($state === '') return ['known' => false];

        // 2026-06-03 Phase 1H H1.P0.d SECURITY FIX: parallel to the
        // login_access_view adminDisabled enforcement (commit f10ccba4).
        // Pre-fix this function only checked lifecycle.state; operator-
        // disabled tenants would pass the per-request access gate even
        // though the SA UI + Phase 1G display + login flow correctly
        // showed/enforced the disabled state. Reads adminDisabled in
        // H1.5 canonical priority order with strict === true coercion;
        // fail-CLOSED on read error.
        $adminDisabled = false;
        try {
            $pub = $this->_tenantPublic($schoolId);
            if (is_array($pub) && (($pub['adminDisabled'] ?? null) === true)) {
                $adminDisabled = true;
            } else {
                $sch = $this->firebase->firestoreGet('schools', $schoolId);
                if (is_array($sch) && is_array($sch['adminDisabled'] ?? null)
                    && (($sch['adminDisabled']['value'] ?? false) === true)) {
                    $adminDisabled = true;
                }
            }
        } catch (\Throwable $e) {
            log_message('error',
                'B2_registry_service::lifecycle_access adminDisabled read failed schoolId=['
                . $schoolId . '] err=' . $e->getMessage()
            );
            $adminDisabled = true;
        }

        return [
            'known'         => true,
            'allowed'       => in_array($state, self::ACCESS_LIFECYCLE_STATES, true)
                               && !$adminDisabled,
            'state'         => $state,
            'adminDisabled' => $adminDisabled,
        ];
    }

    // ─── B2.3.2-D: LOGIN-PATH READS ──────────────────────────────────

    /**
     * Resolve a school code (e.g. "10001") to its canonical schoolId
     * (e.g. "SCH_D94FE8F7AD"). Reads schoolCodeIndex/{code}.schoolId only;
     * returns NULL on Firestore unreachable or absent code.
     */
    public function resolve_school_code(string $code): ?string
    {
        if (!$this->ready)  return null;
        if ($code === '')   return null;
        try {
            $idx = $this->firebase->firestoreGet('schoolCodeIndex', $code);
            if (!is_array($idx)) return null;
            $sid = (string) ($idx['schoolId'] ?? '');
            return $sid !== '' ? $sid : null;
        } catch (\Throwable $e) {
            log_message('error',
                'B2_registry_service::resolve_school_code firestore failed code=['
                . $code . '] err=' . $e->getMessage()
            );
            return null;
        }
    }

    /**
     * Login-path access view. Computes the access decision + the unix
     * timestamps the login flow needs (`periodEndTs`, `graceEndTs`)
     * from the canonical schoolControl/{schoolId} + subscriptions/{id}
     * docs in one focused query.
     *
     * Returns:
     *   ['known' => false]
     *     — Firestore unreachable / schoolControl doc absent.
     *   ['known' => true, 'allowed' => bool, 'state' => str,
     *    'periodEndTs' => int, 'graceEndTs' => int]
     *
     * Callers MUST NOT redirect-to-error when `known === false`. The
     * downstream legacy fallback semantics ("subscription record not found
     * → contact support") apply ONLY when the source-of-truth store is
     * authoritatively missing the record; Firestore unreachable is a
     * transient condition.
     *
     * Date semantics: subscription.periodEnd / graceEnd are stored as
     * "YYYY-MM-DD" strings. periodEndTs / graceEndTs are computed as
     * `strtotime($date . ' 23:59:59')` so they match the legacy timestamp
     * shape exactly (same downstream session keys: subscription_expiry,
     * subscription_grace_end).
     */
    public function login_access_view(string $schoolId, int $now): array
    {
        $sc = $this->get_school_control($schoolId);
        if (!is_array($sc)) return ['known' => false];

        $state = (string) ($sc['lifecycle']['state'] ?? '');
        if ($state === '') return ['known' => false];

        $subPtr = (string) ($sc['subscription']['subscriptionId'] ?? '');
        $periodEnd = '';
        $graceEnd  = '';
        if ($subPtr !== '') {
            try {
                $sub = $this->firebase->firestoreGet('subscriptions', $subPtr);
                if (is_array($sub)) {
                    $periodEnd = (string) ($sub['periodEnd'] ?? '');
                    $graceEnd  = (string) ($sub['graceEnd']  ?? '');
                }
            } catch (\Throwable $e) {
                // Best-effort; lifecycle.state is the canonical access gate.
                log_message('error',
                    'B2_registry_service::login_access_view subscription read failed schoolId=['
                    . $schoolId . '] subId=[' . $subPtr . '] err=' . $e->getMessage()
                );
            }
        }

        $periodEndTs = ($periodEnd !== '') ? (int) strtotime($periodEnd . ' 23:59:59') : 0;
        $graceEndTs  = ($graceEnd  !== '') ? (int) strtotime($graceEnd  . ' 23:59:59')
                                            : ($periodEndTs > 0 ? $periodEndTs + (7 * 86400) : 0);

        // 2026-06-02 SECURITY FIX: adminDisabled enforcement at the web login
        // gate. Pre-fix this function only checked lifecycle.state + periodEnd
        // and ignored both schools.adminDisabled.value AND tenantPublic.
        // adminDisabled. Operator-disabled tenants could authenticate through
        // the web login flow despite the SA UI and Phase 1G surfaces correctly
        // showing the disabled badge. Firestore Rules (H1.5 mobile gate)
        // already blocked these — only the PHP-side web flow had the gap.
        //
        // Read priority matches Phase 1G's get_tenant_identity():
        //   1. tenantPublic.adminDisabled (H1.5 canonical mirror — operator
        //      design lock; same source mobile clients read)
        //   2. schools.adminDisabled.value (audit-log Array struct's value
        //      field; falls back when mirror is missing)
        // STRICT === true check; non-bool (Array without .value=true) or
        // missing → defaults to false (not disabled).
        $adminDisabled = false;
        try {
            $pub = $this->_tenantPublic($schoolId);
            if (is_array($pub) && (($pub['adminDisabled'] ?? null) === true)) {
                $adminDisabled = true;
            } else {
                $sch = $this->firebase->firestoreGet('schools', $schoolId);
                if (is_array($sch) && is_array($sch['adminDisabled'] ?? null)
                    && (($sch['adminDisabled']['value'] ?? false) === true)) {
                    $adminDisabled = true;
                }
            }
        } catch (\Throwable $e) {
            // Defensive: on read failure, do NOT silently allow. Treat as
            // disabled (fail-closed) to err on the safe side for the gate.
            log_message('error',
                'B2_registry_service::login_access_view adminDisabled read failed schoolId=['
                . $schoolId . '] err=' . $e->getMessage()
            );
            $adminDisabled = true;
        }

        $allowed = in_array($state, self::ACCESS_LIFECYCLE_STATES, true)
                 && $periodEndTs > 0
                 && $periodEndTs >= $now
                 && !$adminDisabled;

        return [
            'known'         => true,
            'allowed'       => $allowed,
            'state'         => $state,
            'periodEndTs'   => $periodEndTs,
            'graceEndTs'    => $graceEndTs,
            'adminDisabled' => $adminDisabled,
        ];
    }

    /**
     * Request-scoped read of tenantPublic/{schoolId}. The same doc is fetched
     * up to 3× in one login (adminDisabled gates + activeModules); memoizing
     * collapses that to a single round trip. Re-throws on read error so each
     * caller's own try/catch behaves EXACTLY as before (a thrown read is never
     * memoized → stays retryable); only successful reads (incl. a genuine
     * doc-miss → null) are cached. Memo is cleared when the Firebase binding
     * changes (init()).
     */
    private function _tenantPublic(string $schoolId): ?array
    {
        if ($schoolId === '') return null;
        if (array_key_exists($schoolId, $this->tenantPublicMemo)) {
            return $this->tenantPublicMemo[$schoolId];
        }
        $d = $this->firebase->firestoreGet('tenantPublic', $schoolId); // may throw — caller handles
        $doc = is_array($d) ? $d : null;
        $this->tenantPublicMemo[$schoolId] = $doc;
        return $doc;
    }

    /**
     * Return the entitled-module list for `schoolId`. Reads the canonical
     * tenantPublic/{schoolId}.activeModules array (precomputed at backfill;
     * updated by the future Firestore-authoritative writer). Returns an
     * empty array on Firestore unreachable or doc absent — same downstream
     * shape as the legacy RTDB read.
     */
    public function get_features(string $schoolId): array
    {
        if (!$this->ready)     return [];
        if ($schoolId === '')  return [];
        try {
            $tp = $this->_tenantPublic($schoolId);
            if (!is_array($tp)) return [];
            $am = $tp['activeModules'] ?? [];
            if (!is_array($am)) return [];
            return array_values(array_filter($am, 'is_string'));
        } catch (\Throwable $e) {
            log_message('error',
                'B2_registry_service::get_features firestore failed schoolId=['
                . $schoolId . '] err=' . $e->getMessage()
            );
            return [];
        }
    }

    /**
     * Display name for `schoolId`. Reads schools/{schoolId}; prefers
     * camelCase canonical field. Returns empty string on Firestore
     * unreachable or doc absent — caller falls back to schoolId per the
     * legacy behavior.
     */
    public function get_display_name(string $schoolId): string
    {
        if (!$this->ready)     return '';
        if ($schoolId === '')  return '';
        try {
            $sch = $this->firebase->firestoreGet('schools', $schoolId);
            if (!is_array($sch)) return '';
            $name = (string) ($sch['schoolName'] ?? $sch['name'] ?? '');
            return $name;
        } catch (\Throwable $e) {
            log_message('error',
                'B2_registry_service::get_display_name firestore failed schoolId=['
                . $schoolId . '] err=' . $e->getMessage()
            );
            return '';
        }
    }

    // ─── B2.3.2-B: PLANS / PAYMENTS / SUBSCRIPTIONS ──────────────────

    /**
     * Plan-family id → versioned Firestore doc id. Plans are stored as
     * `plans/{PLAN_XXX}__v1` after backfill; legacy callers know only
     * the family id ("PLAN_XXX"). This translation is here so callers
     * never have to know about the version suffix.
     */
    private function _plan_doc_id(string $planFamilyId): string
    {
        return $planFamilyId . '__v1';
    }

    /**
     * B2.3.2-FIX R1 — unwrap the Firestore_rest_client::query response shape.
     * The REST client returns [{id: <docId>, data: <fields>}, ...]; legacy
     * callers iterate expecting flat field dicts. This helper normalises
     * every query return to a flat array where each row is the document's
     * fields plus a `__firestoreId` key carrying the doc id (for client-side
     * sorting when orderBy on a missing field is unsafe).
     *
     * Defensive: tolerates already-flat input (in case REST client behaviour
     * changes upstream) by returning the row unchanged.
     */
    private function _unwrap_query_rows($rows): array
    {
        if (!is_array($rows)) return [];
        $out = [];
        foreach ($rows as $r) {
            if (is_array($r) && array_key_exists('id', $r)
                && array_key_exists('data', $r) && is_array($r['data'])) {
                $row = $r['data'];
                $row['__firestoreId'] = (string) $r['id'];
                $out[] = $row;
            } else {
                $out[] = $r;
            }
        }
        return $out;
    }

    /**
     * List all plans. Returns an indexed array of plan docs in canonical
     * Firestore shape (planId, planFamilyId, name, price, billingCycle,
     * graceDays, modules, limits, status, createdAt, createdBy, version).
     * Sorted by name ascending (the legacy `sort_order` field is not part
     * of the Firestore canonical shape; callers requiring an explicit
     * ordering should override post-call).
     */
    public function list_plans(): array
    {
        if (!$this->ready) return [];
        try {
            // B2.3.2-FIX R3: no orderBy at query layer (Firestore would exclude
            // any plan doc missing the `name` field). Unwrap + sort client-side.
            $rows = $this->firebase->firestoreQuery('plans', []);
            $rows = $this->_unwrap_query_rows($rows);
            usort($rows, fn($a, $b) => strcmp(
                (string) ($a['name'] ?? ''),
                (string) ($b['name'] ?? '')
            ));
            return array_values($rows);
        } catch (\Throwable $e) {
            log_message('error', 'B2_registry_service::list_plans firestore failed: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Read one plan by family id. Returns NULL if Firestore unreachable
     * or the doc is absent.
     */
    public function get_plan(string $planFamilyId): ?array
    {
        if (!$this->ready || $planFamilyId === '') return null;
        try {
            $doc = $this->firebase->firestoreGet('plans', $this->_plan_doc_id($planFamilyId));
            return is_array($doc) ? $doc : null;
        } catch (\Throwable $e) {
            log_message('error', 'B2_registry_service::get_plan firestore failed: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Create a plan. `$data` is in canonical Firestore shape — caller
     * MUST NOT pass legacy snake_case fields. Returns true on success.
     */
    public function create_plan(string $planFamilyId, array $data): bool
    {
        if (!$this->ready || $planFamilyId === '') return false;
        try {
            $docId = $this->_plan_doc_id($planFamilyId);
            $data['planId']         = $docId;
            $data['planFamilyId']   = $planFamilyId;
            $data['version']        = $data['version'] ?? 1;
            return (bool) $this->firebase->firestoreSet('plans', $docId, $data, false);
        } catch (\Throwable $e) {
            log_message('error', 'B2_registry_service::create_plan firestore failed: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Patch a plan. Merge semantics; only the supplied keys are written.
     */
    public function update_plan(string $planFamilyId, array $patch): bool
    {
        if (!$this->ready || $planFamilyId === '') return false;
        try {
            return (bool) $this->firebase->firestoreUpdate('plans',
                $this->_plan_doc_id($planFamilyId), $patch);
        } catch (\Throwable $e) {
            log_message('error', 'B2_registry_service::update_plan firestore failed: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Delete a plan. Caller MUST first verify no schoolControl docs hold
     * `subscription.planId == planFamilyId` via count_schools_on_plan().
     */
    public function delete_plan(string $planFamilyId): bool
    {
        if (!$this->ready || $planFamilyId === '') return false;
        try {
            return (bool) $this->firebase->firestoreDelete('plans',
                $this->_plan_doc_id($planFamilyId));
        } catch (\Throwable $e) {
            log_message('error', 'B2_registry_service::delete_plan firestore failed: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Count schoolControl docs whose `subscription.planId` equals
     * `$planFamilyId`. Used as the safety guard for delete_plan().
     */
    public function count_schools_on_plan(string $planFamilyId): int
    {
        if (!$this->ready || $planFamilyId === '') return 0;
        try {
            // B2.3.2-FIX R2: positional condition shape — REST client uses
            // list-destructuring `as [$field,$op,$value]` (line 916), which
            // requires the inner array be indexed [0,1,2].
            $rows = $this->firebase->firestoreQuery('schoolControl',
                [['subscription.planId', '==', $planFamilyId]]);
            $rows = $this->_unwrap_query_rows($rows);
            return count($rows);
        } catch (\Throwable $e) {
            log_message('error', 'B2_registry_service::count_schools_on_plan firestore failed: ' . $e->getMessage());
            return 0;
        }
    }

    /**
     * PL-1: school-count for ALL plans in a single pass — one schoolControl
     * read grouped by subscription.planId. Equivalent to calling
     * count_schools_on_plan() for every planId, but without the per-plan
     * N+1 query. Returns [planFamilyId => count]; plans with zero schools
     * are simply absent (callers use `?? 0`). Empty planIds are skipped,
     * mirroring count_schools_on_plan()'s `$planFamilyId === ''` guard.
     */
    public function count_schools_by_plan(): array
    {
        if (!$this->ready) return [];
        try {
            $rows = $this->firebase->firestoreQuery('schoolControl', []);
            $rows = $this->_unwrap_query_rows($rows);
            $out = [];
            foreach ($rows as $c) {
                $sub = is_array($c['subscription'] ?? null) ? $c['subscription'] : [];
                $pid = (string) ($sub['planId'] ?? '');
                if ($pid === '') continue;
                $out[$pid] = ($out[$pid] ?? 0) + 1;
            }
            return $out;
        } catch (\Throwable $e) {
            log_message('error', 'B2_registry_service::count_schools_by_plan firestore failed: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Return all tenant docs in a single pass: schools + schoolControl
     * joined on schoolId. Each row carries the canonical Firestore shape
     * for both collections plus a synthesized `subscription` block from
     * schoolControl.subscription + lifecycle. NO RTDB read.
     *
     * Returns: list<array> with keys: schoolId, schoolName, schoolCode,
     *   logoUrl, primarySsaId, planFamilyId, lifecycleState,
     *   subscriptionPeriodEnd, subscriptionGraceEnd, subscriptionStatus.
     */
    public function list_tenants_summary(): array
    {
        if (!$this->ready) return [];
        // P0-1: request-scoped memo. This method is invoked multiple times
        // per SA request (dashboard ×5, statistics ×3, cross-school ×4); the
        // underlying collections do not change within a request lifecycle, so
        // a SUCCESSFUL result is cached on the instance and returned by value
        // on subsequent calls. Error/empty returns are intentionally NOT
        // memoized, so transient failures stay retryable (prior behavior).
        if ($this->tenantsSummaryMemo !== null) {
            return $this->tenantsSummaryMemo;
        }
        try {
            // B2.3.2-FIX R3: no orderBy at query layer. Schools docs do not
            // have a `schoolId` field — schoolId is the doc id, not stored.
            // Firestore orderBy on a missing field excludes the doc → 0 rows.
            // Fetch unsorted, unwrap {id,data} envelopes, sort client-side
            // by doc id (which IS the schoolId).
            $schoolsRaw = $this->firebase->firestoreQuery('schools', []);
            $ctrlsRaw   = $this->firebase->firestoreQuery('schoolControl', []);
            // 2026-06-03 Phase 1H H1.P0.a: also pull tenantPublic for the
            // H1.5 canonical adminDisabled mirror. Same source Phase 1G
            // get_tenant_identity() + the login_access_view security fix use.
            // Enriching here means every consumer (Phase 1B Hub Top Schools,
            // Phase 1D School Search, Phase 1F Cross-School matrix) automatically
            // shows the correct DISABLED state instead of phantom ACTIVE.
            $publicRaw  = $this->firebase->firestoreQuery('tenantPublic', []);
            $schoolsRaw = $this->_unwrap_query_rows($schoolsRaw);
            $ctrlsRaw   = $this->_unwrap_query_rows($ctrlsRaw);
            $publicRaw  = $this->_unwrap_query_rows($publicRaw);

            // Index control docs by schoolId (doc id, surfaced as __firestoreId
            // post-unwrap; also tolerate a stored schoolId field if present).
            $ctrls = [];
            foreach ($ctrlsRaw as $c) {
                $sid = (string) ($c['schoolId'] ?? $c['__firestoreId'] ?? '');
                if ($sid !== '') $ctrls[$sid] = $c;
            }
            $publics = [];
            foreach ($publicRaw as $p) {
                $sid = (string) ($p['schoolId'] ?? $p['__firestoreId'] ?? '');
                if ($sid !== '') $publics[$sid] = $p;
            }

            $out = [];
            foreach ($schoolsRaw as $s) {
                // B2.3.2-FIX R4: schools docs use doc id as schoolId. Backfill
                // intent was to also write a `schoolId` field but the existing
                // docs were written by Wave A/B1 onboarding which used `name`
                // not `schoolName` and `stats` not `statsCache`. Read both.
                $sid = (string) ($s['schoolId'] ?? $s['__firestoreId'] ?? '');
                if ($sid === '') continue;
                // Skip non-tenant docs (e.g., schools/{sid}_profile counters).
                if (!preg_match('/^SCH_[A-Z0-9]+$/', $sid)) continue;

                $c    = $ctrls[$sid] ?? [];
                $sub  = is_array($c['subscription'] ?? null) ? $c['subscription'] : [];
                $life = is_array($c['lifecycle']    ?? null) ? $c['lifecycle']    : [];

                // Resolve subscription period/grace date strings via the
                // pointer in schoolControl.subscription.subscriptionId.
                $periodEnd = ''; $graceEnd = '';
                $subPtr = (string) ($sub['subscriptionId'] ?? '');
                if ($subPtr !== '') {
                    try {
                        $subDoc = $this->firebase->firestoreGet('subscriptions', $subPtr);
                        if (is_array($subDoc)) {
                            $periodEnd = (string) ($subDoc['periodEnd'] ?? '');
                            $graceEnd  = (string) ($subDoc['graceEnd']  ?? '');
                        }
                    } catch (\Throwable $e) { /* best-effort */ }
                }

                // B2.3.2-FIX R4: field-name tolerance. Schools docs may have
                // either `schoolName`/`statsCache` (backfill canonical) or
                // `name`/`stats` (Wave A/B1 legacy). Read both; first non-empty
                // wins.
                $schoolName = (string) ($s['schoolName'] ?? $s['name'] ?? '');
                $stats      = is_array($s['statsCache'] ?? null) ? $s['statsCache']
                            : (is_array($s['stats'] ?? null) ? $s['stats'] : []);
                $totalStudents = (int) ($stats['totalStudents'] ?? $stats['total_students'] ?? 0);
                $totalStaff    = (int) ($stats['totalStaff']    ?? $stats['total_staff']    ?? 0);
                $lastUpdated   = (string) ($stats['lastUpdated'] ?? $stats['last_updated'] ?? '');

                // 2026-06-03 Phase 1H H1.P0.a: adminDisabled resolution.
                // Reads in H1.5 canonical priority order matching Phase 1G's
                // get_tenant_identity() and the login_access_view security fix:
                //   1. tenantPublic.adminDisabled (canonical bool mirror)
                //   2. schools.adminDisabled.value (audit-log Array fallback)
                // STRICT === true coercion; non-bool or missing → false.
                $pub = $publics[$sid] ?? [];
                $adminDisabled = false;
                if (($pub['adminDisabled'] ?? null) === true) {
                    $adminDisabled = true;
                } elseif (is_array($s['adminDisabled'] ?? null)
                          && (($s['adminDisabled']['value'] ?? false) === true)) {
                    $adminDisabled = true;
                }

                $out[] = [
                    'schoolId'              => $sid,
                    'schoolName'            => $schoolName,
                    'schoolCode'            => (string) ($s['schoolCode'] ?? ''),
                    'logoUrl'               => (string) ($s['logoUrl']    ?? ''),
                    'domainIdentifier'      => (string) ($s['domainIdentifier'] ?? ''),
                    'primarySsaId'          => (string) ($s['primarySsaId'] ?? ''),
                    'planFamilyId'          => (string) ($sub['planId']   ?? ''),
                    'lifecycleState'        => (string) ($life['state']   ?? ''),
                    'adminDisabled'         => $adminDisabled,
                    'subscriptionPeriodEnd' => $periodEnd,
                    'subscriptionGraceEnd'  => $graceEnd,
                    'subscriptionStatus'    => (string) ($sub['status']   ?? ''),
                    'city'                  => (string) ($s['city']       ?? ''),
                    'totalStudents'         => $totalStudents,
                    'totalStaff'            => $totalStaff,
                    'statsLastUpdated'      => $lastUpdated,
                ];
            }
            // Stable order by schoolId for deterministic UI rendering.
            usort($out, fn($a, $b) => strcmp($a['schoolId'], $b['schoolId']));
            // P0-1: memoize ONLY the successful result (returned by value).
            $this->tenantsSummaryMemo = $out;
            return $out;
        } catch (\Throwable $e) {
            log_message('error', 'B2_registry_service::list_tenants_summary firestore failed: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Per-tenant detail used by the single-school view in
     * Superadmin_plans::get_school_plan. Returns NULL if Firestore
     * unreachable / tenant absent.
     */
    public function get_tenant_detail(string $schoolId): ?array
    {
        if (!$this->ready || $schoolId === '') return null;
        try {
            $sch  = $this->firebase->firestoreGet('schools',       $schoolId) ?? null;
            $ctrl = $this->firebase->firestoreGet('schoolControl', $schoolId) ?? null;
            if (!is_array($sch) || !is_array($ctrl)) return null;
            $sub  = is_array($ctrl['subscription'] ?? null) ? $ctrl['subscription'] : [];
            $life = is_array($ctrl['lifecycle']    ?? null) ? $ctrl['lifecycle']    : [];
            $subDoc = null;
            $subPtr = (string) ($sub['subscriptionId'] ?? '');
            if ($subPtr !== '') {
                try { $subDoc = $this->firebase->firestoreGet('subscriptions', $subPtr); }
                catch (\Throwable $e) { /* best-effort */ }
            }
            return [
                'schools'          => $sch,
                'schoolControl'    => $ctrl,
                'subscriptionDoc'  => is_array($subDoc) ? $subDoc : [],
                'lifecycleState'   => (string) ($life['state'] ?? ''),
                'planFamilyId'     => (string) ($sub['planId'] ?? ''),
            ];
        } catch (\Throwable $e) {
            log_message('error', 'B2_registry_service::get_tenant_detail firestore failed: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Set lifecycle.state for a tenant. Updates schoolControl/{id}.lifecycle
     * atomically and appends a tenantAudit row. Used by expire_check + manual
     * status flips. Returns true on success.
     */
    public function write_lifecycle_state(string $schoolId, string $newState, string $reason = ''): bool
    {
        if (!$this->ready || $schoolId === '') return false;
        if (!in_array($newState, [
            'trialing','active','expiring_soon','grace','past_due','suspended','expired',
            // Onboarding quarantine — set when the SSA login-contract self-heal
            // fails. NOT in ACCESS_LIFECYCLE_STATES, so login_access_view()
            // auto-denies access until the tenant is repaired (idempotent
            // recovery flips it back to 'active').
            'provisioning_incomplete',
        ], true)) return false;
        try {
            $nowIso = date('c');
            $ok = (bool) $this->firebase->firestoreUpdate('schoolControl', $schoolId, [
                'lifecycle.state'      => $newState,
                'lifecycle.reason'     => $reason !== '' ? $reason : $newState,
                'lifecycle.computedAt' => time(),
                'updatedAt'            => $nowIso,
            ]);
            // H-LIFECYCLE H1.5 — fan out lifecycle.state to the
            // client-readable mirror collection so mobile apps
            // (Parent + Teacher) can subscribe and detect transitions
            // in seconds for reactive logout. schoolControl is
            // deny-all to clients; tenantPublic is the canonical
            // public-mirror surface (same pattern as activeModules).
            // Non-fatal on failure — the lifecycle write to
            // schoolControl above is authoritative; mirror staleness
            // is detected by B2_runtime_verify probe #19.
            try {
                $this->firebase->firestoreUpdate('tenantPublic', $schoolId, [
                    'lifecycleState' => $newState,
                    'mirroredAt'     => $nowIso,
                ]);
            } catch (\Throwable $e) {
                log_message('error', 'B2_registry_service::write_lifecycle_state tenantPublic fan-out failed (non-fatal): ' . $e->getMessage());
            }
            $auditId = 'B2_LIFECYCLE_' . $schoolId . '_' . time() . '_' . substr(bin2hex(random_bytes(3)), 0, 6);
            try {
                $this->firebase->firestoreSet('tenantAudit', $auditId, [
                    'eventId'    => $auditId,
                    'action'     => 'b2_lifecycle_transition',
                    'entityType' => 'school',
                    'entityId'   => $schoolId,
                    'schoolId'   => $schoolId,
                    'actor'      => ['role' => 'sa', 'uid' => 'service'],
                    'metadata'   => ['newState' => $newState, 'reason' => $reason],
                    'ts'         => $nowIso,
                ], false);
            } catch (\Throwable $e) { /* audit failure non-fatal */ }
            return $ok;
        } catch (\Throwable $e) {
            log_message('error', 'B2_registry_service::write_lifecycle_state firestore failed: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * List all payment docs.
     */
    public function list_payments(): array
    {
        if (!$this->ready) return [];
        try {
            // B2.3.2-FIX R3: drop orderBy; unwrap; sort client-side by createdAt
            // DESC. Defensive against payment rows lacking createdAt (would be
            // excluded by Firestore orderBy).
            $rows = $this->firebase->firestoreQuery('payments', []);
            $rows = $this->_unwrap_query_rows($rows);
            usort($rows, fn($a, $b) => strcmp(
                (string) ($b['createdAt'] ?? ''),
                (string) ($a['createdAt'] ?? '')
            ));
            return array_values($rows);
        } catch (\Throwable $e) {
            log_message('error', 'B2_registry_service::list_payments firestore failed: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * List payment docs for a single school.
     */
    public function list_payments_for_school(string $schoolId): array
    {
        if (!$this->ready || $schoolId === '') return [];
        try {
            // B2.3.2-FIX R2 + R3: positional condition shape; no orderBy.
            // The schoolId condition narrows scope server-side; sorting is
            // client-side post-unwrap.
            $rows = $this->firebase->firestoreQuery('payments',
                [['schoolId', '==', $schoolId]]);
            $rows = $this->_unwrap_query_rows($rows);
            // Also accept payments that store the school reference as
            // `school_uid` (legacy invoice docs from before B2.3.2-B-3).
            if (empty($rows)) {
                $alt = $this->firebase->firestoreQuery('payments',
                    [['school_uid', '==', $schoolId]]);
                $rows = $this->_unwrap_query_rows($alt);
            }
            usort($rows, fn($a, $b) => strcmp(
                (string) ($b['createdAt'] ?? ''),
                (string) ($a['createdAt'] ?? '')
            ));
            return array_values($rows);
        } catch (\Throwable $e) {
            log_message('error', 'B2_registry_service::list_payments_for_school firestore failed: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Read one payment by id.
     */
    public function get_payment(string $paymentId): ?array
    {
        if (!$this->ready || $paymentId === '') return null;
        try {
            $doc = $this->firebase->firestoreGet('payments', $paymentId);
            return is_array($doc) ? $doc : null;
        } catch (\Throwable $e) {
            log_message('error', 'B2_registry_service::get_payment firestore failed: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Create a payment doc. Doc id is supplied by the caller (deterministic
     * payment id or invoice id minted by Billing_integrity / id_generator).
     */
    public function create_payment(string $paymentId, array $data): bool
    {
        if (!$this->ready || $paymentId === '') return false;
        try {
            $data['paymentId'] = $paymentId;
            return (bool) $this->firebase->firestoreSet('payments', $paymentId, $data, false);
        } catch (\Throwable $e) {
            log_message('error', 'B2_registry_service::create_payment firestore failed: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Patch a payment doc.
     */
    public function update_payment(string $paymentId, array $patch): bool
    {
        if (!$this->ready || $paymentId === '') return false;
        try {
            return (bool) $this->firebase->firestoreUpdate('payments', $paymentId, $patch);
        } catch (\Throwable $e) {
            log_message('error', 'B2_registry_service::update_payment firestore failed: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Delete a payment doc.
     */
    public function delete_payment(string $paymentId): bool
    {
        if (!$this->ready || $paymentId === '') return false;
        try {
            return (bool) $this->firebase->firestoreDelete('payments', $paymentId);
        } catch (\Throwable $e) {
            log_message('error', 'B2_registry_service::delete_payment firestore failed: ' . $e->getMessage());
            return false;
        }
    }

    // ─── B2.3.2-B-3: billing-write subscription sync ────────────────

    /**
     * Record the completion of a fully-paid invoice against a tenant's
     * subscription. Firestore-only. The cutover analogue of the legacy
     * Superadmin_plans::_sync_school_sub:
     *
     *   subscription.last_payment_date / amount  (mutable mirror in legacy)
     *   → schoolControl/{schoolId}.subscription.lastPaymentDate / Amount
     *     + updatedAt
     *
     *   When `periodEnd` is supplied (full-period payment):
     *     subscription.expiry_date = periodEnd, grace_end, status = Active
     *     school.status = active, school.profile.status = active
     *   →
     *     subscriptions/{newSubId} created (append-only history) with
     *     periodStart=periodEnd-cycle / periodEnd / graceEnd derived from
     *     plans/{planId}.graceDays;
     *     schoolControl.subscription.subscriptionId repointed;
     *     schoolControl.lifecycle = active + new subscriptionPeriodEnd;
     *     schools/{schoolId}.adminDisabled.value = false (un-suspend).
     *
     *   Always appends one tenantAudit row.
     *
     * Args:
     *   schoolId     — tenant id
     *   paidDate     — "YYYY-MM-DD"
     *   amount       — float
     *   periodEnd    — "YYYY-MM-DD" (optional; absent for partial payments)
     *   planFamilyId — only needed when periodEnd is supplied (for graceDays)
     *
     * Returns true on success (best-effort; audit failure non-fatal).
     */
    public function record_payment_completion(string $schoolId, array $args): bool
    {
        if (!$this->ready || $schoolId === '') return false;

        $paidDate  = (string) ($args['paidDate']     ?? date('Y-m-d'));
        $amount    = (float)  ($args['amount']       ?? 0);
        $periodEnd = (string) ($args['periodEnd']    ?? '');
        $planFid   = (string) ($args['planFamilyId'] ?? '');
        $nowIso    = date('c');
        $now       = time();

        // Always: mirror last_payment_date / amount onto schoolControl.subscription.
        $patch = [
            'subscription.lastPaymentDate'   => $paidDate,
            'subscription.lastPaymentAmount' => $amount,
            'updatedAt'                       => $nowIso,
        ];

        // Period-advance branch: append history row + repoint + un-suspend.
        $newSubId = '';
        if ($periodEnd !== '') {
            // Resolve graceDays from the plan (best-effort; default 7).
            $graceDays = 7;
            if ($planFid !== '') {
                try {
                    $plan = $this->firebase->firestoreGet('plans', $this->_plan_doc_id($planFid));
                    if (is_array($plan)) $graceDays = (int) ($plan['graceDays'] ?? 7);
                } catch (\Throwable $e) { /* best-effort */ }
            }
            $graceEndStr = date('Y-m-d', strtotime($periodEnd . ' +' . $graceDays . ' days'));
            $newSubId    = 'SUB_' . $schoolId . '_' . $now . '_' . substr(bin2hex(random_bytes(3)), 0, 6);

            try {
                $this->firebase->firestoreSet('subscriptions', $newSubId, [
                    'subscriptionId' => $newSubId,
                    'schoolId'       => $schoolId,
                    'planId'         => $planFid,
                    'periodStart'    => $paidDate,
                    'periodEnd'      => $periodEnd,
                    'graceEnd'       => $graceEndStr,
                    'status'         => 'active',
                    'changeType'     => 'payment_advance',
                    'createdAt'      => $nowIso,
                    'createdBy'      => 'system_payment',
                ], false);
            } catch (\Throwable $e) {
                log_message('error', 'B2_registry_service::record_payment_completion sub create failed: ' . $e->getMessage());
                // Continue with the schoolControl patch anyway — last-payment
                // fields are still useful; pointer just doesn't advance.
                $newSubId = '';
            }

            if ($newSubId !== '') {
                $patch['subscription.subscriptionId']          = $newSubId;
                $patch['lifecycle.state']                       = 'active';
                $patch['lifecycle.reason']                      = 'payment_advance';
                $patch['lifecycle.subscriptionPeriodEnd']       = (int) strtotime($periodEnd . ' 23:59:59');
                $patch['lifecycle.computedAt']                  = $now;
            }
        }

        try {
            $this->firebase->firestoreUpdate('schoolControl', $schoolId, $patch);
        } catch (\Throwable $e) {
            log_message('error', 'B2_registry_service::record_payment_completion control update failed: ' . $e->getMessage());
            return false;
        }

        // Un-suspend via schools/{id}.adminDisabled (canonical Firestore equivalent
        // of the legacy school.status='active' write).
        if ($periodEnd !== '') {
            try {
                $this->firebase->firestoreUpdate('schools', $schoolId, [
                    'adminDisabled'        => ['value' => false, 'reason' => '', 'updatedAt' => $nowIso],
                    'updatedAt'             => $nowIso,
                ]);
            } catch (\Throwable $e) { /* best-effort un-suspend */ }
        }

        // Audit row (non-fatal).
        try {
            $auditId = 'B2_PAYMENT_' . $schoolId . '_' . $now . '_' . substr(bin2hex(random_bytes(3)), 0, 6);
            $this->firebase->firestoreSet('tenantAudit', $auditId, [
                'eventId'    => $auditId,
                'action'     => 'b2_payment_completion',
                'entityType' => 'school',
                'entityId'   => $schoolId,
                'schoolId'   => $schoolId,
                'actor'      => ['role' => 'sa', 'uid' => 'service'],
                'metadata'   => [
                    'paidDate'   => $paidDate,
                    'amount'     => $amount,
                    'periodEnd'  => $periodEnd,
                    'newSubId'   => $newSubId,
                ],
                'ts'         => $nowIso,
            ], false);
        } catch (\Throwable $e) { /* audit failure non-fatal */ }

        return true;
    }

    // ─── B2.3.2-C: SCHOOLS-PANEL READS / WRITES ──────────────────────

    /**
     * Code-uniqueness probe: is schoolCodeIndex/{code} already taken?
     * Returns true ONLY when Firestore reports an existing index entry.
     * Returns false on absent / Firestore unreachable (caller MUST treat
     * an unreachable store as "unknown" — same as the legacy RTDB read
     * that fails open to "available").
     */
    public function code_taken(string $code): bool
    {
        if (!$this->ready || $code === '') return false;
        try {
            $idx = $this->firebase->firestoreGet('schoolCodeIndex', $code);
            return is_array($idx) && !empty($idx['schoolId']);
        } catch (\Throwable $e) {
            log_message('error', 'B2_registry_service::code_taken firestore failed: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Name-uniqueness probe: is schoolNameIndex/{slug} already taken?
     * Caller passes the slug (the same `_school_name_key` produces).
     */
    public function name_taken(string $nameKey): bool
    {
        if (!$this->ready || $nameKey === '') return false;
        try {
            $idx = $this->firebase->firestoreGet('schoolNameIndex', $nameKey);
            return is_array($idx) && !empty($idx['schoolId']);
        } catch (\Throwable $e) {
            log_message('error', 'B2_registry_service::name_taken firestore failed: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Mint a fresh, unique schoolId (SCH_<10 hex>) and atomically claim it.
     *
     * Firestore-only replacement for the legacy RTDB-claim minter
     * (Superadmin_schools::_generate_school_id, which wrote System/Schools/{id}/_claim).
     * The claim is a one-field doc in the `schoolIdIndex` uniqueness collection;
     * firestoreCreate returns false on HTTP 409 (doc exists), so two concurrent
     * onboards can never win the same id. Returns '' on exhaustion — the caller
     * MUST treat '' as a hard error and abort onboarding (never invent an id).
     */
    public function mint_school_id(string $actor = '', int $maxAttempts = 6): string
    {
        if (!$this->ready) return '';
        for ($attempt = 0; $attempt < $maxAttempts; $attempt++) {
            $candidate = 'SCH_' . strtoupper(bin2hex(random_bytes(5)));
            try {
                $claimed = $this->firebase->firestoreCreate('schoolIdIndex', $candidate, [
                    'schoolId'  => $candidate,
                    'claimedAt' => date('c'),
                    'claimedBy' => $actor ?: 'system',
                ]);
            } catch (\Throwable $e) {
                log_message('error', 'B2_registry_service::mint_school_id firestore failed: ' . $e->getMessage());
                $claimed = false;
            }
            if ($claimed) return $candidate;
            // false → either a 409 collision (retry) or a transient write error.
        }
        log_message('error', "B2_registry_service::mint_school_id exhausted {$maxAttempts} attempts");
        return '';
    }

    /**
     * Patch schools/{schoolId} with arbitrary canonical-shape fields.
     * Caller MUST pass camelCase keys (city, street, email, phone, logoUrl,
     * domainIdentifier, updatedAt, updatedBy). Field translation happens at
     * the call site, not here.
     */
    public function update_school_profile(string $schoolId, array $patch): bool
    {
        if (!$this->ready || $schoolId === '') return false;
        try {
            return (bool) $this->firebase->firestoreUpdate('schools', $schoolId, $patch);
        } catch (\Throwable $e) {
            log_message('error', 'B2_registry_service::update_school_profile firestore failed: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Toggle schools/{schoolId}.adminDisabled — the 5-axis canonical access
     * gate (Wave B2.2-F).
     *
     * legacy: school.status='active|inactive|suspended' + subscription.status
     * canonical:
     *   active     → adminDisabled.value=false (lifecycle.state untouched —
     *                lifecycle is the billing-state axis, not the admin axis)
     *   inactive   → adminDisabled.value=true,  reason='manual_inactive'
     *   suspended  → adminDisabled.value=true,  reason='manual_suspended';
     *                ALSO sets lifecycle.state='suspended' (mirrors the legacy
     *                effect on MY_Controller's status-check). This is the only
     *                place outside payment flows that overrides lifecycle.
     *
     * Returns true on success. Audit row appended; audit failure non-fatal.
     */
    public function set_admin_disabled(string $schoolId, string $newStatus, string $actor = ''): bool
    {
        if (!$this->ready || $schoolId === '') return false;
        if (!in_array($newStatus, ['active', 'inactive', 'suspended'], true)) return false;
        $disabled = ($newStatus !== 'active');
        $reason   = ($newStatus === 'active') ? ''
                  : (($newStatus === 'inactive') ? 'manual_inactive' : 'manual_suspended');
        $nowIso   = date('c');

        $schPatch = [
            'adminDisabled' => [
                'value'     => $disabled,
                'reason'    => $reason,
                'actor'     => $actor !== '' ? $actor : 'sa',
                'updatedAt' => $nowIso,
            ],
            'updatedAt' => $nowIso,
        ];

        try {
            $this->firebase->firestoreUpdate('schools', $schoolId, $schPatch);
        } catch (\Throwable $e) {
            log_message('error', 'B2_registry_service::set_admin_disabled schools update failed: ' . $e->getMessage());
            return false;
        }

        // H-LIFECYCLE H1.5 — fan out adminDisabled.value to the
        // client-readable tenantPublic mirror (paired with the
        // lifecycleState mirror written by write_lifecycle_state
        // below). Mobile apps watch tenantPublic for either signal
        // and force-logout on transition. Non-fatal on failure;
        // schools.adminDisabled remains authoritative for the SA
        // detail page and lifecycle.state remains authoritative for
        // billing/access gates.
        try {
            $this->firebase->firestoreUpdate('tenantPublic', $schoolId, [
                'adminDisabled' => $disabled,
                'mirroredAt'    => $nowIso,
            ]);
        } catch (\Throwable $e) {
            log_message('error', 'B2_registry_service::set_admin_disabled tenantPublic fan-out failed (non-fatal): ' . $e->getMessage());
        }

        // Keep schoolControl/{id}.lifecycle.state in sync with the toggle
        // both directions. Without this the dashboard active-count, the
        // tenant-list "Plan / Status" badge, and any future gate that
        // reads lifecycle.state would drift relative to adminDisabled
        // after a suspend → reactivate cycle (the suspend writes
        // lifecycle='suspended', but the reactivate left it suspended).
        if ($newStatus === 'suspended') {
            try { $this->write_lifecycle_state($schoolId, 'suspended', 'manual_admin_action'); }
            catch (\Throwable $e) { /* logged inside write_lifecycle_state */ }
        } elseif ($newStatus === 'active') {
            try { $this->write_lifecycle_state($schoolId, 'active', 'manual_admin_reactivate'); }
            catch (\Throwable $e) { /* logged inside write_lifecycle_state */ }
        }
        // 'inactive' is a deactivation that doesn't touch lifecycle.state —
        // it disables admin access via adminDisabled.value alone. lifecycle
        // continues to reflect the subscription billing state.

        try {
            $auditId = 'B2_ADMIN_TOGGLE_' . $schoolId . '_' . time() . '_' . substr(bin2hex(random_bytes(3)), 0, 6);
            $this->firebase->firestoreSet('tenantAudit', $auditId, [
                'eventId'    => $auditId,
                'action'     => 'b2_admin_toggle',
                'entityType' => 'school',
                'entityId'   => $schoolId,
                'schoolId'   => $schoolId,
                'actor'      => ['role' => 'sa', 'uid' => $actor !== '' ? $actor : 'service'],
                'metadata'   => ['newStatus' => $newStatus, 'disabled' => $disabled, 'reason' => $reason],
                'ts'         => $nowIso,
            ], false);
        } catch (\Throwable $e) { /* audit failure non-fatal */ }

        return true;
    }

    /**
     * Assign / renew a plan for a tenant. Firestore-only.
     *
     * Appends a new subscriptions/{newId} row with the new plan + period,
     * repoints schoolControl.subscription pointer at it, sets
     * lifecycle.state='active' + subscriptionPeriodEnd, recomputes
     * entitlements from plans/{plan}.modules, mirrors entitlements into
     * tenantPublic.activeModules. Logs a tenantAudit row.
     *
     * Args:
     *   schoolId      — tenant id
     *   planFamilyId  — e.g. PLAN_2E596A (resolved internally to __v1)
     *   expiryDate    — "YYYY-MM-DD" (period end)
     *   actor         — optional uid for audit
     *
     * Returns true on success.
     */
    public function assign_plan_to_school(string $schoolId, string $planFamilyId, string $expiryDate, string $actor = ''): bool
    {
        if (!$this->ready || $schoolId === '' || $planFamilyId === '' || $expiryDate === '') return false;
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $expiryDate)) return false;

        // Plan must exist
        $plan = $this->get_plan($planFamilyId);
        if (!is_array($plan)) return false;

        $graceDays   = (int) ($plan['graceDays'] ?? 7);
        $graceEndStr = date('Y-m-d', strtotime($expiryDate . ' +' . $graceDays . ' days'));
        $now         = time();
        $nowIso      = date('c');

        // Append new subscriptions doc.
        $newSubId = 'SUB_' . $schoolId . '_' . $now . '_' . substr(bin2hex(random_bytes(3)), 0, 6);
        try {
            $this->firebase->firestoreSet('subscriptions', $newSubId, [
                'subscriptionId' => $newSubId,
                'schoolId'       => $schoolId,
                'planId'         => $planFamilyId,
                'periodStart'    => date('Y-m-d', $now),
                'periodEnd'      => $expiryDate,
                'graceEnd'       => $graceEndStr,
                'status'         => 'active',
                'changeType'     => 'assign_plan',
                'createdAt'      => $nowIso,
                'createdBy'      => $actor !== '' ? $actor : 'sa',
            ], false);
        } catch (\Throwable $e) {
            log_message('error', 'B2_registry_service::assign_plan_to_school sub create failed: ' . $e->getMessage());
            return false;
        }

        // Recompute entitlements from plan.modules.
        $modules     = is_array($plan['modules'] ?? null) ? $plan['modules'] : [];
        $entitlements = [];
        foreach ($modules as $m => $enabled) {
            if (!empty($enabled)) $entitlements[$m] = true;
        }
        $activeModules = array_keys($entitlements);

        try {
            $this->firebase->firestoreUpdate('schoolControl', $schoolId, [
                'subscription.subscriptionId'         => $newSubId,
                'subscription.planId'                 => $planFamilyId,
                'subscription.status'                 => 'active',
                'lifecycle.state'                     => 'active',
                'lifecycle.reason'                    => 'assign_plan',
                'lifecycle.computedAt'                => $now,
                'lifecycle.subscriptionPeriodEnd'     => (int) strtotime($expiryDate . ' 23:59:59'),
                'entitlements'                        => $entitlements,
                'updatedAt'                            => $nowIso,
            ]);
        } catch (\Throwable $e) {
            log_message('error', 'B2_registry_service::assign_plan_to_school control update failed: ' . $e->getMessage());
            return false;
        }

        // Mirror entitlement set into tenantPublic.activeModules (best-effort).
        try {
            $this->firebase->firestoreUpdate('tenantPublic', $schoolId, [
                'activeModules' => $activeModules,
                'accessAllowed' => true,
                'computedAt'    => $nowIso,
            ]);
        } catch (\Throwable $e) { /* best-effort mirror */ }

        // Audit (non-fatal).
        try {
            $auditId = 'B2_ASSIGN_PLAN_' . $schoolId . '_' . $now . '_' . substr(bin2hex(random_bytes(3)), 0, 6);
            $this->firebase->firestoreSet('tenantAudit', $auditId, [
                'eventId'    => $auditId,
                'action'     => 'b2_assign_plan',
                'entityType' => 'school',
                'entityId'   => $schoolId,
                'schoolId'   => $schoolId,
                'actor'      => ['role' => 'sa', 'uid' => $actor !== '' ? $actor : 'service'],
                'metadata'   => [
                    'planFamilyId' => $planFamilyId,
                    'periodEnd'    => $expiryDate,
                    'graceEnd'     => $graceEndStr,
                    'newSubId'     => $newSubId,
                ],
                'ts'         => $nowIso,
            ], false);
        } catch (\Throwable $e) { /* audit failure non-fatal */ }

        return true;
    }

    // ─── B2.3.2-E: ONBOARD CANONICAL TENANT CREATION ─────────────────

    /**
     * Create a brand-new tenant in the Firestore canonical store.
     * Firestore-only — NO RTDB writes, NO dual-write, NO shadow.
     *
     * Writes (in this order):
     *   Phase 1 — uniqueness gates via firestoreCreate(exists:false):
     *     schoolCodeIndex/{schoolCode} → {schoolId, createdAt}
     *     schoolNameIndex/{nameKey}    → {schoolId, createdAt}
     *
     *     Either gate failing returns the appropriate error code; if
     *     schoolNameIndex fails after schoolCodeIndex succeeds, the
     *     schoolCodeIndex is rolled back via firestoreDelete (best-effort).
     *
     *   Phase 2 — atomic commitBatch of the data set:
     *     schools/{schoolId}
     *     schoolControl/{schoolId}
     *     tenantPublic/{schoolId}
     *     subscriptions/{newSubId}  (newSubId is deterministic per tenant)
     *     schoolSsa/{primarySsaId}
     *     tenantAudit/{eventId}
     *
     *     A batch failure rolls back BOTH indexes via firestoreDelete.
     *
     * On full success returns:
     *   ['success' => true, 'schoolId', 'subscriptionId', 'auditId']
     *
     * On any failure returns:
     *   ['success' => false, 'error' => <code>]
     *   error codes: service_not_ready, missing_required_fields,
     *                code_taken, name_taken, firestore_unreachable_code_index,
     *                firestore_unreachable_name_index, data_batch_failed
     *
     * Args (all strings unless noted):
     *   schoolId, schoolCode, schoolName, nameKey
     *   city, street, email, phone, logoUrl, domainIdentifier
     *   planFamilyId, planModules (array), planBillingCycle
     *   periodStart, periodEnd, graceEnd
     *   primarySsaId, ssaName, ssaEmail
     *   createdBy
     */
    public function create_tenant(array $args): array
    {
        if (!$this->ready) return ['success' => false, 'error' => 'service_not_ready'];

        $schoolId     = (string) ($args['schoolId']     ?? '');
        $schoolCode   = (string) ($args['schoolCode']   ?? '');
        $schoolName   = (string) ($args['schoolName']   ?? '');
        $nameKey      = (string) ($args['nameKey']      ?? '');
        $primarySsaId = (string) ($args['primarySsaId'] ?? '');
        $planFamilyId = (string) ($args['planFamilyId'] ?? '');
        if ($schoolId === '' || $schoolCode === '' || $schoolName === '' ||
            $nameKey === '' || $primarySsaId === '' || $planFamilyId === '') {
            return ['success' => false, 'error' => 'missing_required_fields'];
        }

        $now           = time();
        $nowIso        = date('c');
        $createdBy     = (string) ($args['createdBy']         ?? 'sa');
        $planModules   = is_array($args['planModules'] ?? null) ? $args['planModules'] : [];
        $periodStart   = (string) ($args['periodStart']       ?? date('Y-m-d'));
        $periodEnd     = (string) ($args['periodEnd']         ?? '');
        $graceEnd      = (string) ($args['graceEnd']          ?? '');
        $billingCycle  = (string) ($args['planBillingCycle']  ?? 'annual');
        // SC-Step8 (Session Convergence — 2026-06-02): accept sessionYear so
        // the initial sessions[] + currentSession seed lands ATOMICALLY in
        // the schools doc creation below, eliminating the pre-Step-8 race
        // where create_tenant succeeded but Superadmin_schools::onboard's
        // separate firestoreUpdate at L430 could fail, leaving a tenant
        // with no session state. When absent/empty, the schools doc is
        // created without these fields (preserves backward compatibility
        // for any caller that doesn't pass sessionYear yet).
        $sessionYear   = (string) ($args['sessionYear']       ?? '');

        $entitlements  = [];
        foreach ($planModules as $m => $enabled) {
            if (!empty($enabled)) $entitlements[$m] = true;
        }
        $activeModules = array_keys($entitlements);

        // Deterministic subscription id for the initial-period row (mirrors
        // the BACKFILL_INITIAL_* deterministic pattern from sa_b2_backfill.js
        // — re-issued onboards for the same schoolId would overwrite cleanly
        // rather than littering history).
        $newSubId = 'ONBOARD_INITIAL_' . $schoolId;
        $auditId  = 'B2_ONBOARD_' . $schoolId . '_' . $now . '_' . substr(bin2hex(random_bytes(3)), 0, 6);

        // ── Phase 1: index uniqueness gates ─────────────────────────────
        // schoolCodeIndex first — failure means the code was somehow already
        // minted to another tenant. This is rare (SCHCODE is atomic counter)
        // but is the defence-in-depth gate.
        try {
            $codeCreated = (bool) $this->firebase->firestoreCreate('schoolCodeIndex', $schoolCode, [
                'schoolId'  => $schoolId,
                'createdAt' => $nowIso,
            ]);
        } catch (\Throwable $e) {
            log_message('error', 'B2_registry_service::create_tenant schoolCodeIndex create failed: ' . $e->getMessage());
            return ['success' => false, 'error' => 'firestore_unreachable_code_index'];
        }
        if (!$codeCreated) {
            return ['success' => false, 'error' => 'code_taken'];
        }

        // schoolNameIndex — name uniqueness race-safe gate.
        try {
            $nameCreated = (bool) $this->firebase->firestoreCreate('schoolNameIndex', $nameKey, [
                'schoolId'  => $schoolId,
                'createdAt' => $nowIso,
            ]);
        } catch (\Throwable $e) {
            // Roll back the code index — best-effort.
            try { $this->firebase->firestoreDelete('schoolCodeIndex', $schoolCode); } catch (\Throwable $ignored) {}
            log_message('error', 'B2_registry_service::create_tenant schoolNameIndex create failed: ' . $e->getMessage());
            return ['success' => false, 'error' => 'firestore_unreachable_name_index'];
        }
        if (!$nameCreated) {
            try { $this->firebase->firestoreDelete('schoolCodeIndex', $schoolCode); } catch (\Throwable $ignored) {}
            return ['success' => false, 'error' => 'name_taken'];
        }

        // ── Phase 2: atomic data batch ──────────────────────────────────
        $ops = [];

        // SC-Step8: schools doc payload — sessions[] + currentSession now
        // populated atomically WITHIN the create_tenant batch when sessionYear
        // is provided. Eliminates the pre-Step-8 "tenant exists but has no
        // sessions" failure mode that the separate Superadmin_schools::onboard
        // L430 firestoreUpdate was vulnerable to.
        $schoolsData = [
            'schoolId'         => $schoolId,
            'schoolCode'       => $schoolCode,
            'schoolName'       => $schoolName,
            'name'             => $schoolName,
            'city'             => (string) ($args['city']             ?? ''),
            'street'           => (string) ($args['street']           ?? ''),
            'address'          => (string) ($args['street'] ?? $args['address'] ?? ''),
            'email'            => (string) ($args['email']            ?? ''),
            'phone'            => (string) ($args['phone']            ?? ''),
            'logoUrl'          => (string) ($args['logoUrl']          ?? ''),
            'domainIdentifier' => (string) ($args['domainIdentifier'] ?? ''),
            'primarySsaId'     => $primarySsaId,
            // Denormalised SSA contact block for the password-recovery flow.
            // Purpose-named so the forgot-password feature can read the school's
            // recovery contact directly off schools/{id} without a second
            // schoolSsa lookup. Kept in sync with the schoolSsa/{ssaId} doc
            // below (same name/email/number source args).
            'forget_password_details' => [
                'name'   => (string) ($args['ssaName']  ?? ''),
                'email'  => (string) ($args['ssaEmail'] ?? ''),
                'number' => (string) ($args['ssaPhone'] ?? ''),
            ],
            'adminDisabled'    => ['value' => false, 'reason' => '', 'actor' => $createdBy, 'updatedAt' => $nowIso],
            'statsCache'       => ['totalStudents' => 0, 'totalStaff' => 0, 'lastUpdated' => $nowIso],
            'createdAt'        => $nowIso,
            'createdBy'        => $createdBy,
            'updatedAt'        => $nowIso,
        ];
        if ($sessionYear !== '') {
            $schoolsData['sessions']       = [$sessionYear];
            $schoolsData['currentSession'] = $sessionYear;
        }
        $ops[] = ['op' => 'set', 'collection' => 'schools', 'docId' => $schoolId, 'merge' => false,
            'data' => $schoolsData,
        ];

        $ops[] = ['op' => 'set', 'collection' => 'schoolControl', 'docId' => $schoolId, 'merge' => false,
            'data' => [
                'schoolId'      => $schoolId,
                'subscription'  => [
                    'subscriptionId'    => $newSubId,
                    'planId'            => $planFamilyId,
                    'status'            => 'active',
                    'lastPaymentDate'   => '',
                    'lastPaymentAmount' => 0,
                ],
                'lifecycle'     => [
                    'state'                  => 'active',
                    'reason'                 => 'onboarded',
                    'computedAt'             => $now,
                    'subscriptionPeriodEnd'  => $periodEnd !== '' ? (int) strtotime($periodEnd . ' 23:59:59') : 0,
                ],
                'entitlements'  => $entitlements,
                'billing'       => [],
                'featureFlags'  => [],
                'createdAt'     => $nowIso,
                'updatedAt'     => $nowIso,
            ],
        ];

        $ops[] = ['op' => 'set', 'collection' => 'tenantPublic', 'docId' => $schoolId, 'merge' => false,
            'data' => [
                'schoolId'       => $schoolId,
                'schoolName'     => $schoolName,
                'name'           => $schoolName,
                'logoUrl'        => (string) ($args['logoUrl'] ?? ''),
                'accessAllowed'  => true,
                'activeModules'  => $activeModules,
                'computedAt'     => $nowIso,
            ],
        ];

        $ops[] = ['op' => 'set', 'collection' => 'subscriptions', 'docId' => $newSubId, 'merge' => false,
            'data' => [
                'subscriptionId' => $newSubId,
                'schoolId'       => $schoolId,
                'planId'         => $planFamilyId,
                'periodStart'    => $periodStart,
                'periodEnd'      => $periodEnd,
                'graceEnd'       => $graceEnd,
                'status'         => 'active',
                'billingCycle'   => $billingCycle,
                'changeType'     => 'onboard_initial',
                'createdAt'      => $nowIso,
                'createdBy'      => $createdBy,
            ],
        ];

        // schoolSsa doc — shape MUST match the legacy SSAs (SSA0001, SSA0002)
        // that were created before the canonical onboarding flow shipped.
        // Pre-existing fields: ssaId, uid (both equal to the SSA id),
        // adminId, schoolId, schoolCode, name, email, role, status,
        // createdAt, createdBy. Omitting `status` made list/badge views
        // and any future SSA-status-aware feature treat new SSAs as
        // missing-status; omitting `ssaId` / `uid` causes downstream
        // readers that look up either alias to fail on new SSAs.
        // Phone is optional (collected by some flows, not all).
        $ops[] = ['op' => 'set', 'collection' => 'schoolSsa', 'docId' => $primarySsaId, 'merge' => false,
            'data' => [
                'adminId'      => $primarySsaId,
                'ssaId'        => $primarySsaId,
                'uid'          => $primarySsaId,
                'schoolId'     => $schoolId,
                'schoolCode'   => $schoolCode,
                'name'         => (string) ($args['ssaName']  ?? ''),
                'email'        => (string) ($args['ssaEmail'] ?? ''),
                'phone'        => (string) ($args['ssaPhone'] ?? ''),
                'role'         => 'school_super_admin',
                'status'       => 'Active',
                'createdAt'    => $nowIso,
                'createdBy'    => $createdBy,
            ],
        ];

        $ops[] = ['op' => 'set', 'collection' => 'tenantAudit', 'docId' => $auditId, 'merge' => false,
            'data' => [
                'eventId'    => $auditId,
                'action'     => 'b2_onboard',
                'entityType' => 'school',
                'entityId'   => $schoolId,
                'schoolId'   => $schoolId,
                'actor'      => ['role' => 'sa', 'uid' => $createdBy],
                'metadata'   => [
                    'planFamilyId' => $planFamilyId,
                    'periodEnd'    => $periodEnd,
                    'graceEnd'     => $graceEnd,
                    'newSubId'     => $newSubId,
                    'primarySsaId' => $primarySsaId,
                ],
                'ts'         => $nowIso,
            ],
        ];

        try {
            $batchOk = (bool) $this->firebase->firestoreCommitBatch($ops);
        } catch (\Throwable $e) {
            log_message('error', 'B2_registry_service::create_tenant commitBatch failed: ' . $e->getMessage());
            $batchOk = false;
        }
        if (!$batchOk) {
            try { $this->firebase->firestoreDelete('schoolCodeIndex', $schoolCode); } catch (\Throwable $ignored) {}
            try { $this->firebase->firestoreDelete('schoolNameIndex', $nameKey);   } catch (\Throwable $ignored) {}
            return ['success' => false, 'error' => 'data_batch_failed'];
        }

        return [
            'success'        => true,
            'schoolId'       => $schoolId,
            'subscriptionId' => $newSubId,
            'auditId'        => $auditId,
        ];
    }

    // ─── B2.3.4-E: REPORTS QUERIES ───────────────────────────────────

    /**
     * Range-query tenantAudit for the SA Activity Report. Date strings are
     * ISO8601 (YYYY-MM-DD); the upper bound is extended to end-of-day
     * (Z timezone) so the date_to inclusive semantics from the legacy
     * report are preserved.
     *
     * Scope note: tenantAudit holds B2-canonical actions only
     * (b2_onboard, b2_admin_toggle, b2_lifecycle_transition,
     * b2_payment_completion, b2_assign_plan). Legacy log types
     * (Login / Error / SchoolLogin / ApiUsage) live in their own RTDB
     * buckets and are retired by the separate B-MON wave. The report
     * controller stamps `_meta.source = 'b2_audit'` so the operator can
     * distinguish current scope from the eventual B-MON consolidation.
     *
     * Returns indexed array of audit docs in canonical Firestore shape.
     */
    public function list_recent_activity(string $dateFrom, string $dateTo, int $limit = 200): array
    {
        if (!$this->ready)        return [];
        if ($dateFrom === '')     return [];
        if ($dateTo   === '')     return [];
        try {
            // B2.3.2-FIX R2 + R3: positional condition shape; orderBy on `ts`
            // is safe (every audit row has `ts`) so kept at query layer for
            // limit-correctness. Apply limit client-side as a backstop.
            $rows = $this->firebase->firestoreQuery('tenantAudit',
                [
                    ['ts', '>=', $dateFrom],
                    ['ts', '<=', $dateTo . 'T23:59:59'],
                ],
                'ts', 'DESC', $limit
            );
            $rows = $this->_unwrap_query_rows($rows);
            // Defensive client-side sort + truncate in case orderBy was
            // dropped by REST client retry path.
            usort($rows, fn($a, $b) => strcmp(
                (string) ($b['ts'] ?? ''),
                (string) ($a['ts'] ?? '')
            ));
            if ($limit > 0 && count($rows) > $limit) {
                $rows = array_slice($rows, 0, $limit);
            }
            return array_values($rows);
        } catch (\Throwable $e) {
            log_message('error',
                'B2_registry_service::list_recent_activity firestore failed dateFrom=['
                . $dateFrom . '] dateTo=[' . $dateTo . '] err=' . $e->getMessage()
            );
            return [];
        }
    }

    /**
     * List all paid payments. Used by Superadmin_reports::revenue_summary
     * for the per-school revenue aggregation. Returns indexed array.
     */
    public function list_paid_payments(): array
    {
        if (!$this->ready) return [];
        try {
            // B2.3.2-FIX R2 + R3: positional condition shape; drop orderBy
            // (paid_date is only populated after collect_payment full-settle,
            // so partial payments would be excluded). Sort client-side.
            $rows = $this->firebase->firestoreQuery('payments',
                [['status', '==', 'paid']]);
            $rows = $this->_unwrap_query_rows($rows);
            usort($rows, fn($a, $b) => strcmp(
                (string) ($b['paid_date'] ?? ''),
                (string) ($a['paid_date'] ?? '')
            ));
            return array_values($rows);
        } catch (\Throwable $e) {
            log_message('error',
                'B2_registry_service::list_paid_payments firestore failed: ' . $e->getMessage()
            );
            return [];
        }
    }

    /**
     * Update schools/{schoolId}.statsCache with totals (the cutover
     * analogue of the legacy System/Schools/{name}/stats_cache field).
     * Always overwrites the statsCache sub-object.
     */
    public function update_stats_cache(string $schoolId, array $stats): bool
    {
        if (!$this->ready || $schoolId === '') return false;
        $nowIso = date('c');
        try {
            return (bool) $this->firebase->firestoreUpdate('schools', $schoolId, [
                'statsCache' => array_merge($stats, ['lastUpdated' => $nowIso]),
                'updatedAt'   => $nowIso,
            ]);
        } catch (\Throwable $e) {
            log_message('error', 'B2_registry_service::update_stats_cache firestore failed: ' . $e->getMessage());
            return false;
        }
    }
}
