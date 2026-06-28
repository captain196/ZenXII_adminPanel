<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Numbering_service — platform allocator for sequential business document IDs.
 *
 * Single entry point for every ZenX ERP module that needs human-readable
 * sequential identifiers (NOT0042, R000123, JV-000007, …). Wraps the proven
 * Firestore_service::nextSchoolCounter primitive (claim-doc CAS) with:
 *
 *   - registry-driven format (prefix + padWidth) per kind
 *   - period scoping (Indian financial year / month) per kind
 *   - audit-actor tracking (claimedBy / claimedFor) on every allocation
 *   - pad-width overflow warning at 80% / error at 100%
 *   - retry-then-throw on contention exhaustion (gapless contract)
 *   - structured observability log line per call
 *   - per-kind enable/disable flag for rollback granularity
 *
 * Public methods (Phase 1):
 *   next($kind, $opts = [])  — allocate the next ID; returns formatted string
 *   peek($kind, $opts = [])  — read current pointer value (operator/verifier)
 *   describe($kind)          — return registry entry (operator/verifier)
 *
 * Adoption pattern for any ERP module:
 *   1. Register the kind in application/config/numbering.php
 *   2. Call $id = $this->numbering->next('your_kind', [...])
 *   3. Store the returned $id on the business document
 *   4. NEVER generate business numbers locally (no inline counters, no
 *      peek()-then-increment, no mobile-side allocation)
 *
 * Loaded as:
 *   $this->load->library('numbering_service', null, 'numbering');
 *   $this->numbering->init($this->fs, $this->school_id, $this->session_year);
 *
 * Allocation authority: Admin backend only. Parent App and Teacher App
 * observe canonical IDs via Firestore listeners; they never mint them.
 */

class Numbering_service
{
    /** @var \CI_Controller */
    private $ci;

    /** @var \Firestore_service|null */
    private $fs = null;

    /** @var array */
    private $registry = [];

    /** @var string */
    private $tenantId = '';

    /** @var string */
    private $session = '';

    /** @var bool */
    private $ready = false;

    public function __construct()
    {
        $this->ci = &get_instance();
        $this->ci->config->load('numbering', false, true);
        $registry = $this->ci->config->item('numbering');
        if (!is_array($registry)) {
            throw new \LogicException(
                'Numbering_service: application/config/numbering.php not loadable or malformed'
            );
        }
        $this->registry = $registry;
    }

    /**
     * Bind tenant context. Must be called before next() / peek().
     * describe() works without init() (pure registry lookup).
     *
     * @param object $fs        Firestore_service instance (already initialised
     *                          with the same tenant context).
     * @param string $tenantId  School ID (e.g. SCH_XXXXXX). Required.
     * @param string $session   Session year (e.g. "2026-27"). Optional.
     */
    public function init($fs, string $tenantId, string $session = ''): self
    {
        if ($tenantId === '') {
            throw new \LogicException(
                'Numbering_service::init requires a non-empty tenantId'
            );
        }
        $this->fs       = $fs;
        $this->tenantId = $tenantId;
        $this->session  = $session;
        $this->ready    = true;
        return $this;
    }

    /**
     * Allocate the next ID for the given kind.
     *
     * @param string $kind  Registered kind slug (e.g. 'notice', 'circular').
     * @param array  $opts  Optional:
     *   - seedFloor:      int    Minimum starting value (migration use)
     *   - maxAttempts:    int    CAS retry budget (default 8)
     *   - period:         string Explicit period override
     *   - claimedBy:      string Audit actor (auto-resolved if absent)
     *   - claimedFor:     string Audit context tag
     *   - idempotencyKey: string Recorded on claim doc for audit (Phase 1
     *                            does not yet implement dedup cache)
     *
     * @return string Formatted ID (e.g. "NOT0042").
     * @throws \RuntimeException on CAS retry exhaustion (gapless contract).
     * @throws \LogicException   on unknown kind, disabled service or kind,
     *                           or unrecognised periodScope.
     */
    public function next(string $kind, array $opts = []): string
    {
        $this->_assertReady();
        $cfg = $this->_assertKindEnabled($kind);

        $effectiveKind = $this->_effectiveKind($kind, $cfg, $opts);
        $seedFloor     = (int) ($opts['seedFloor'] ?? 0);

        // CAS retry budget — matches Firestore_service::nextSchoolCounter default.
        $maxAttempts = (int) ($opts['maxAttempts'] ?? 8);

        // Null-valued metadata is omitted from the claim doc by the primitive's
        // isset-based whitelist. claimedBy auto-resolves so it is always present.
        $metadata = [
            'claimedBy'      => $opts['claimedBy']      ?? $this->_resolveClaimedBy(),
            'claimedFor'     => $opts['claimedFor']     ?? null,
            'idempotencyKey' => $opts['idempotencyKey'] ?? null,
        ];

        // Self-heal callback: the primitive invokes this ONLY when the
        // pointer doc is missing (i.e. the very first allocation for this
        // (tenant, kind) over the lifetime of the system). After the
        // primitive writes its pointer doc, the callback is never invoked
        // again. This preserves legacy ID continuity at cutover without
        // any operator seed step and without per-request overhead.
        $seedCallback = function () use ($kind, $cfg): int {
            return $this->_selfHealSeed($kind, $cfg);
        };

        $started    = microtime(true);
        $value      = $this->fs->nextSchoolCounter($effectiveKind, $seedFloor, $maxAttempts, $metadata, $seedCallback);
        $durationMs = (int) round((microtime(true) - $started) * 1000);

        if ($value === 0) {
            log_message('error', sprintf(
                'numbering_service kind=%s tenant=%s value=null duration_ms=%d outcome=exhausted_throw',
                $kind, $this->tenantId, $durationMs
            ));
            throw new \RuntimeException(
                "Numbering_service: allocation exhausted for kind={$kind} tenant={$this->tenantId}"
            );
        }

        $this->_warnIfPadwidthHigh($kind, $cfg, $value);
        $formatted = $this->_format($cfg, $value);

        log_message('info', sprintf(
            'numbering_service kind=%s tenant=%s value=%d id=%s duration_ms=%d outcome=success',
            $kind, $this->tenantId, $value, $formatted, $durationMs
        ));

        return $formatted;
    }

    /**
     * Read current pointer value without allocating. Operational/diagnostic
     * use only — never call from business logic to predict the next ID
     * (race-vulnerable, gives no reservation).
     *
     * @return int Current pointer value, or 0 if no allocation has occurred.
     */
    public function peek(string $kind, array $opts = []): int
    {
        $this->_assertReady();
        if (!isset($this->registry['kinds'][$kind])) {
            throw new \LogicException("Numbering_service: unknown kind '{$kind}'");
        }
        $cfg           = $this->registry['kinds'][$kind];
        $effectiveKind = $this->_effectiveKind($kind, $cfg, $opts);
        $pointerId     = "{$this->tenantId}_{$effectiveKind}";

        try {
            $doc = $this->fs->raw_client()->getDocument(
                \Firestore_service::SYSTEM_COUNTERS, $pointerId
            );
            return is_array($doc) ? (int) ($doc['value'] ?? 0) : 0;
        } catch (\Exception $_) {
            return 0;
        }
    }

    /**
     * Return the registry entry + flag state for a kind. Pure lookup — no
     * Firestore reads. Safe to call before init().
     */
    public function describe(string $kind): array
    {
        if (!isset($this->registry['kinds'][$kind])) {
            throw new \LogicException("Numbering_service: unknown kind '{$kind}'");
        }
        $cfg = $this->registry['kinds'][$kind];
        return [
            'kind'         => $kind,
            'prefix'       => $cfg['prefix'],
            'padWidth'     => (int) $cfg['padWidth'],
            'gaplessClass' => $cfg['gaplessClass'],
            'periodScope'  => $cfg['periodScope'],
            'seedSource'   => $cfg['seedSource'] ?? null,
            'flagState'    => $this->registry['_kindFlags'][$kind] ?? 'disabled',
        ];
    }

    // ─── private helpers ─────────────────────────────────────────────────

    private function _assertReady(): void
    {
        if (!$this->ready) {
            throw new \LogicException(
                'Numbering_service: init() must be called before next()/peek()'
            );
        }
    }

    private function _assertKindEnabled(string $kind): array
    {
        if (!isset($this->registry['kinds'][$kind])) {
            throw new \LogicException("Numbering_service: unknown kind '{$kind}'");
        }
        if (empty($this->registry['_serviceEnabled'])) {
            throw new \LogicException('Numbering_service: service is globally disabled');
        }
        $flag = $this->registry['_kindFlags'][$kind] ?? 'disabled';
        if ($flag !== 'enabled') {
            throw new \LogicException(
                "Numbering_service: kind '{$kind}' is not enabled (flag={$flag})"
            );
        }
        return $this->registry['kinds'][$kind];
    }

    private function _effectiveKind(string $kind, array $cfg, array $opts): string
    {
        $period = $opts['period'] ?? $this->_derivePeriod($cfg['periodScope']);
        return $period !== null && $period !== ''
            ? "{$kind}__{$period}"
            : $kind;
    }

    /**
     * Derive the current period string for a kind's periodScope.
     *   'none'    → null (no period suffix on the doc key)
     *   'session' → Indian financial year, e.g. "2026_27" (April–March)
     *   'month'   → calendar year_month, e.g. "2026_06"
     */
    private function _derivePeriod(string $periodScope): ?string
    {
        switch ($periodScope) {
            case 'none':
                return null;
            case 'session':
                return $this->_indianFinancialYear();
            case 'month':
                return date('Y_m');
            default:
                throw new \LogicException(
                    "Numbering_service: unknown periodScope '{$periodScope}'"
                );
        }
    }

    /**
     * Indian financial year starting in April. Returns "YYYY_YY" (e.g.
     * dates in April 2026 through March 2027 all return "2026_27").
     */
    private function _indianFinancialYear(): string
    {
        $month = (int) date('n');
        $year  = (int) date('Y');
        if ($month < 4) {
            $year--;
        }
        return $year . '_' . substr((string) ($year + 1), -2);
    }

    private function _resolveClaimedBy(): string
    {
        $userId = $this->ci->session->userdata('admin_id')
               ?? $this->ci->session->userdata('user_id');
        return $userId ? (string) $userId : 'system';
    }

    /**
     * Compute the seed floor for a kind by scanning its source collection
     * for the highest existing matching doc ID. Preserves the legacy
     * self-heal behaviour of Communication::_next_id and
     * Communication_helper::_nextCommCounter at the new allocation layer.
     *
     * Strategy:
     *   1. Indexed query, limit 1, ordered by __name__ DESC. Returns the
     *      lex-highest doc key within the current tenant's docs (filtered
     *      by schoolId field). For zero-padded fixed-width business IDs
     *      this equals the numeric maximum.
     *   2. Trim the {tenantId}_ doc-key prefix if present, regex-match
     *      the registry's seedSource.pattern, extract the numeric portion.
     *   3. If the top doc does not match (mixed-format legacy data, etc.),
     *      fall back to a full collection scan with regex matching.
     *
     * Returns 0 when no matching doc exists (collection empty) or when no
     * seedSource is configured for the kind. Throws nothing — failures
     * degrade to 0 with a logged warning, letting allocation start at 1
     * (correct behaviour for a kind with no pre-existing data).
     */
    private function _selfHealSeed(string $kind, array $cfg): int
    {
        $src = $cfg['seedSource'] ?? null;
        if (!is_array($src) || empty($src['collection']) || empty($src['pattern'])) {
            return 0;
        }
        $collection   = $src['collection'];
        $pattern      = $src['pattern'];
        $tenantPrefix = $this->tenantId . '_';

        // Common case: indexed limit-1 lookup. Works when this tenant's
        // business doc keys share the {tenantId}_{prefix}{padded-N} shape
        // and pad width is consistent (lex order = numeric order).
        try {
            $top = $this->fs->schoolWhere($collection, [], '__name__', 'DESC', 1);
            if (!empty($top)) {
                $docId   = (string) ($top[0]['id'] ?? '');
                $trimmed = (strpos($docId, $tenantPrefix) === 0)
                    ? substr($docId, strlen($tenantPrefix))
                    : $docId;
                if (preg_match($pattern, $trimmed, $m)) {
                    $value = (int) $m[1];
                    log_message('info', sprintf(
                        'numbering_service self_heal kind=%s tenant=%s indexed_seed=%d',
                        $kind, $this->tenantId, $value
                    ));
                    return $value;
                }
            }
        } catch (\Exception $e) {
            log_message('warning', sprintf(
                'numbering_service self_heal kind=%s tenant=%s indexed_lookup_failed: %s',
                $kind, $this->tenantId, $e->getMessage()
            ));
        }

        // Fallback: full scan with regex matching (legacy self-heal behaviour).
        // Triggered when the top-1 doc does not match the registry pattern —
        // e.g. legacy data with mixed-format keys that lex-sort above the
        // conforming docs.
        try {
            $rows = $this->fs->schoolWhere($collection, []);
            $max  = 0;
            if (is_array($rows)) {
                foreach ($rows as $row) {
                    $rid     = (string) ($row['id'] ?? '');
                    $trimmed = (strpos($rid, $tenantPrefix) === 0)
                        ? substr($rid, strlen($tenantPrefix))
                        : $rid;
                    if (preg_match($pattern, $trimmed, $m)) {
                        $n = (int) $m[1];
                        if ($n > $max) {
                            $max = $n;
                        }
                    }
                }
            }
            if ($max > 0) {
                log_message('info', sprintf(
                    'numbering_service self_heal kind=%s tenant=%s fallback_seed=%d',
                    $kind, $this->tenantId, $max
                ));
            }
            return $max;
        } catch (\Exception $e) {
            log_message('error', sprintf(
                'numbering_service self_heal kind=%s tenant=%s fallback_scan_failed: %s',
                $kind, $this->tenantId, $e->getMessage()
            ));
            return 0;
        }
    }

    private function _warnIfPadwidthHigh(string $kind, array $cfg, int $value): void
    {
        $padWidth      = (int) $cfg['padWidth'];
        $maxAtPadWidth = $padWidth > 0 ? ((10 ** $padWidth) - 1) : 0;
        if ($maxAtPadWidth <= 0) {
            return;
        }
        if ($value > $maxAtPadWidth) {
            log_message('error', sprintf(
                'numbering_service kind=%s tenant=%s value=%d padWidth=%d outcome=padwidth_exceeded',
                $kind, $this->tenantId, $value, $padWidth
            ));
        } elseif ($value >= $maxAtPadWidth * 0.80) {  // warn at 80% pad-width utilisation
            log_message('warning', sprintf(
                'numbering_service kind=%s tenant=%s value=%d padWidth=%d util_pct=%s',
                $kind, $this->tenantId, $value, $padWidth,
                number_format(($value / $maxAtPadWidth) * 100, 1)
            ));
        }
    }

    private function _format(array $cfg, int $value): string
    {
        return $cfg['prefix']
            . str_pad((string) $value, (int) $cfg['padWidth'], '0', STR_PAD_LEFT);
    }
}
