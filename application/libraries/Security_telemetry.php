<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Security_telemetry — emits adversarial-signal events.
 *
 *  USAGE
 *    $this->load->library('security_telemetry', null, 'sec_telem');
 *    $this->sec_telem->init(
 *        $this->firebase,
 *        $this->school_id,
 *        ['uid' => $this->admin_id, 'role' => $this->admin_role],
 *        $this->_correlation_id()
 *    );
 *
 *    $this->sec_telem->emit('RBAC_DENIED', 'warning', [
 *        'endpoint'       => 'Staff::new_staff',
 *        'attempted_role' => 'School Super Admin',
 *        'denied_reason'  => 'role=Teacher not in MANAGE_ROLES',
 *    ]);
 *
 *  SEMANTICS
 *    - Best-effort: any error in the Firestore write is swallowed and
 *      logged via log_message('error', ...). NEVER blocks the caller.
 *    - Always emits a structured log_message line FIRST; Firestore write
 *      is secondary.
 *    - Auto-masks any keys matching the sensitive-key pattern from
 *      config/security_telemetry.php.
 *    - Adds correlation_id from the parent controller if present.
 *
 *  EVENT TYPES (allowlist — extend deliberately, not casually)
 *    RBAC_DENIED            — _require_role rejected the actor
 *    RULE_DENIAL            — Firestore rule denied a server write (rare)
 *    BULK_STAFF_READ        — actor read N+ staff docs in one window
 *    DUPLICATE_PHONE_RACE   — TOCTOU detected in phone-index write
 *    ESCALATION_ATTEMPT     — actor tried to assign a role they cannot grant
 *    DEACTIVATED_LOGIN      — disabled user attempted login (server-side)
 *    UPLOAD_REJECTED        — staff document upload rejected at server
 *    CASCADE_PARTIAL        — deactivation cascade completed with row failures
 *    STALE_SESSION_WRITE    — pre-deactivation session attempted write post-revoke
 *    PAN_UPDATE_ATTEMPTED   — PAN field edit detected
 *    CROSS_TENANT_PROBE     — server-side cross-tenant read/write blocked
 *
 *  PHASE A — Observe-only. No enforcement. No paging.
 */
class Security_telemetry
{
    /** @var object|null  Firebase library instance */
    private $firebase = null;
    /** @var string */
    private $schoolId = '';
    /** @var array{uid:string,role:string,school_claim:string} */
    private $defaultActor = ['uid' => '', 'role' => '', 'school_claim' => ''];
    /** @var bool */
    private $ready = false;
    /** @var string  Per-request correlation id (set at init) */
    private $correlationId = '';
    /** @var string  Target Firestore collection (from config) */
    private $collection = 'security_events';
    /** @var string  Sensitive-key regex (from config) */
    private $sensitiveKeyPattern = '/(password|panNumber|aadhar|account|bankDetails|pfNumber|esiNumber|credentials|token|secret)/i';

    const EVENT_TYPES = [
        'RBAC_DENIED', 'RULE_DENIAL', 'BULK_STAFF_READ', 'DUPLICATE_PHONE_RACE',
        'ESCALATION_ATTEMPT', 'DEACTIVATED_LOGIN', 'UPLOAD_REJECTED',
        'CASCADE_PARTIAL', 'STALE_SESSION_WRITE', 'PAN_UPDATE_ATTEMPTED',
        'CROSS_TENANT_PROBE',
        // ── V7 AUTH-C5 (2026-05-27): Super Admin login telemetry ──
        // Emitted by Superadmin_login::authenticate(). actor.uid carries the
        // submitted adminId (even on failure) for forensic linkage. schoolId
        // is the synthetic 'SA_PANEL' since SA login has no school context.
        'SA_LOGIN_SUCCESS', 'SA_LOGIN_FAILED', 'SA_LOGIN_LOCKED',
        'SA_LOGIN_ROLE_REJECTED',
        // ── Wave A A2.1 (2026-05-28): SA-login authorization dual-read telemetry ──
        // Emitted by Superadmin_login authz resolution (Firestore-first, RTDB fallback).
        // SA_AUTHZ_PATH      — which store authorized (path=firestore|rtdb_fallback, +reason)
        // SA_AUTHZ_DIVERGENCE — Firestore decision != RTDB decision (must stay 0)
        // SA_AUTHZ_DOC_MISSING — superAdmins doc absent for an authenticated SA
        'SA_AUTHZ_PATH', 'SA_AUTHZ_DIVERGENCE', 'SA_AUTHZ_DOC_MISSING',
        // ── Wave B1 (2026-05-29): onboarding convergence (Mongo removal) ──
        // Emitted by Superadmin_schools::onboard().
        // ONBOARD_IDGEN_SOURCE — code/SSA id source (firestore|mongo|local_fallback)
        // ONBOARD_SSA_FBAUTH   — SSA Firebase Auth provisioning (created|claims_failed|create_failed)
        // ONBOARD_MONGO_HIT    — any residual auth_client call from onboard (expect 0 post-cutover)
        // ONBOARD_RESULT       — onboarding outcome (success|rollback +reason)
        // ONBOARD_ROLLBACK_INCOMPLETE — rollback deleteFirebaseUser failed (carries uid; expect 0)
        'ONBOARD_IDGEN_SOURCE', 'ONBOARD_SSA_FBAUTH', 'ONBOARD_MONGO_HIT',
        'ONBOARD_RESULT', 'ONBOARD_ROLLBACK_INCOMPLETE',
        // ── Wave B2.2-F F4 (2026-05-30): Firestore-only control-plane telemetry ──
        // PERMANENT (survive B2.8):
        //   B2_BILLING_CLAIM         — Billing_integrity claim outcome (claimed|dedup|in_progress|reclaimed|conflict|released)
        //   B2_BILLING_RECLAIM       — CAS reclaim of stale/failed payment claim
        //   B2_BILLING_WRITE         — invoice/payment commitBatch outcome + claim linkage
        //   B2_LIFECYCLE_TRANSITION  — schoolControl.lifecycle.state changed (from->to, trigger)
        //   B2_ENTITLEMENT_RECOMPUTE — schoolControl.entitlements rewritten (changed modules)
        //   B2_RECONCILE             — invariant sweep (scanned, healed, drift)
        //   B2_RULES_DEPLOY          — firestore.rules deploy audit (additive blocks)
        //   B2_READ_PARITY           — Firestore-vs-RTDB reader parity mismatch count (B2.5 soak)
        // SCAFFOLDING (retired at B2.8 with the bridge):
        //   B2_BRIDGE_COMPENSATE     — Firestore shadow-write failed; _b2_pending marker left
        //   B2_BACKFILL              — B2.4 backfill per-tenant outcome
        //   B2_REGISTRY_WRITE        — dual-write shadow emit (B2.3)
        //   B2_REGISTRY_READ         — dual-read source taken (B2.5)
        //   B2_ROLLBACK              — flag flipped OFF / writer reverted
        'B2_BILLING_CLAIM', 'B2_BILLING_RECLAIM', 'B2_BILLING_WRITE',
        'B2_LIFECYCLE_TRANSITION', 'B2_ENTITLEMENT_RECOMPUTE',
        'B2_RECONCILE', 'B2_RULES_DEPLOY', 'B2_READ_PARITY',
        'B2_BRIDGE_COMPENSATE', 'B2_BACKFILL',
        'B2_REGISTRY_WRITE', 'B2_REGISTRY_READ', 'B2_ROLLBACK',
    ];

    const SEVERITIES = ['info', 'warning', 'error', 'critical'];

    const MAX_STR_LEN = 500;

    /**
     * Bind dependencies. Idempotent — safe to re-init.
     *
     * @param object $firebase       Firebase library instance (server SDK)
     * @param string $schoolId       SCH_XXXXXX
     * @param array  $defaultActor   ['uid'=>..., 'role'=>..., 'school_claim'=>...]
     * @param string $correlationId  optional per-request correlation id
     */
    public function init($firebase, string $schoolId, array $defaultActor = [], string $correlationId = ''): self
    {
        $this->firebase = $firebase;
        $this->schoolId = (string) $schoolId;
        $this->defaultActor = [
            'uid'          => (string) ($defaultActor['uid']  ?? ''),
            'role'         => (string) ($defaultActor['role'] ?? ''),
            'school_claim' => (string) ($defaultActor['school_claim'] ?? $schoolId),
        ];
        $this->correlationId = (string) $correlationId;

        // Load config — best-effort; fall back to compiled defaults if missing.
        $this->_loadConfig();

        $this->ready = ($firebase !== null && $this->schoolId !== '');
        return $this;
    }

    public function isReady(): bool
    {
        return $this->ready;
    }

    /**
     * Emit a security event. Best-effort: never throws, never blocks caller.
     *
     * @param string $eventType  one of self::EVENT_TYPES
     * @param string $severity   one of self::SEVERITIES (invalid → coerced to 'warning')
     * @param array  $detail     event-specific context — masked before persisting
     * @param array  $subject    optional ['type'=>'staff', 'id'=>'SSA0042']
     * @return bool  true on full success (log line + firestore); false on any partial/total failure
     */
    public function emit(string $eventType, string $severity, array $detail = [], array $subject = []): bool
    {
        if (!$this->ready) {
            log_message('error', '[STAFF_SECURITY] dropped event=' . $eventType . ' reason=not_initialised');
            return false;
        }
        if (!in_array($eventType, self::EVENT_TYPES, true)) {
            log_message('error', '[STAFF_SECURITY] invalid event_type=' . $eventType);
            return false;
        }
        if (!in_array($severity, self::SEVERITIES, true)) {
            $severity = 'warning';
        }

        $maskedDetail = $this->_maskSensitive($detail);
        $now          = date('c');
        $evtId        = $this->_buildEvtId($now);
        $logLevel     = $this->_severityToLogLevel($severity);

        // ── 1. STRUCTURED LOG LINE (always fires, even if Firestore write fails) ──
        $kv = [
            'type=' . $eventType,
            'severity=' . $severity,
            'actor=' . $this->_safeKv($this->defaultActor['uid']),
            'role=' . $this->_safeKv($this->defaultActor['role']),
            'school=' . $this->_safeKv($this->schoolId),
            'correlation_id=' . $this->_safeKv($this->correlationId),
        ];
        if (!empty($subject)) {
            $kv[] = 'subject_type=' . $this->_safeKv((string)($subject['type'] ?? ''));
            $kv[] = 'subject_id='   . $this->_safeKv((string)($subject['id']   ?? ''));
        }
        foreach ($maskedDetail as $k => $v) {
            if (is_scalar($v)) {
                $kv[] = $k . '=' . $this->_safeKv((string)$v);
            }
        }
        log_message($logLevel, '[STAFF_SECURITY] ' . implode(' ', $kv));

        // ── 2. FIRESTORE WRITE (best-effort; failure does not propagate) ─────────
        $doc = [
            'evtId'      => $evtId,
            'ts'         => $now,
            'schoolId'   => $this->schoolId,
            'event_type' => $eventType,
            'severity'   => $severity,
            'actor'      => $this->defaultActor,
            'subject'    => empty($subject) ? null : [
                'type' => (string)($subject['type'] ?? ''),
                'id'   => (string)($subject['id']   ?? ''),
            ],
            'context'    => [
                'ip'             => $this->_clientIp(),
                'user_agent'     => $this->_userAgent(),
                'correlation_id' => $this->correlationId,
            ],
            'detail'     => $maskedDetail,
            'resolved'   => false,
        ];

        try {
            $this->firebase->firestoreSet($this->collection, $evtId, $doc);
            return true;
        } catch (\Throwable $e) {
            log_message('error', '[STAFF_SECURITY] firestore_write_failed event=' . $eventType
                . ' err=' . $this->_safeKv($e->getMessage()));
            return false;
        }
    }

    // =========================================================================
    //  PRIVATE HELPERS
    // =========================================================================

    /**
     * Load thresholds + collection name + sensitive pattern from
     * application/config/security_telemetry.php. Falls back silently
     * to compiled defaults on any error.
     */
    private function _loadConfig(): void
    {
        try {
            $CI =& get_instance();
            if ($CI !== null && isset($CI->config)) {
                // CI3 load — idempotent; second load is a no-op
                $CI->config->load('security_telemetry', true);
                $collections = $CI->config->item('security_collections', 'security_telemetry');
                if (is_array($collections) && !empty($collections['security_events'])) {
                    $this->collection = (string) $collections['security_events'];
                }
                $pattern = $CI->config->item('security_sensitive_key_pattern', 'security_telemetry');
                if (is_string($pattern) && $pattern !== '') {
                    $this->sensitiveKeyPattern = $pattern;
                }
            }
        } catch (\Throwable $e) {
            // Defaults already set as property values; log and continue.
            log_message('error', '[STAFF_SECURITY] config_load_failed err=' . $e->getMessage());
        }
    }

    /**
     * Build a doc id that is timestamp-prefixed (naturally sortable),
     * salted with actor uid for visual debuggability, plus 8 hex chars
     * to defeat single-second collisions.
     *
     * Format: {YYYYMMDDTHHMMSS}_{actor_uid|anon}_{8hex}
     */
    private function _buildEvtId(string $isoTs): string
    {
        $compact = preg_replace('/[^0-9T]/', '', $isoTs);
        if (strlen($compact) > 15) $compact = substr($compact, 0, 15);

        $rand = function_exists('random_bytes')
            ? bin2hex(random_bytes(4))
            : substr(md5(uniqid('', true)), 0, 8);

        $actor = $this->defaultActor['uid'] !== '' ? $this->defaultActor['uid'] : 'anon';
        // Strip any characters that would break Firestore doc-ids
        $actor = preg_replace('/[^A-Za-z0-9_\-]/', '_', $actor);

        return "{$compact}_{$actor}_{$rand}";
    }

    /**
     * Recursively walk an array and replace any value whose key matches
     * the sensitive-key pattern with '[MASKED]'. Non-sensitive scalars
     * pass through unchanged. Strings over MAX_STR_LEN are truncated.
     */
    private function _maskSensitive(array $data): array
    {
        $out = [];
        foreach ($data as $k => $v) {
            if (preg_match($this->sensitiveKeyPattern, (string)$k)) {
                $out[$k] = '[MASKED]';
                continue;
            }
            if (is_array($v)) {
                $out[$k] = $this->_maskSensitive($v);
            } elseif (is_string($v) && strlen($v) > self::MAX_STR_LEN) {
                $out[$k] = substr($v, 0, self::MAX_STR_LEN) . '...';
            } else {
                $out[$k] = $v;
            }
        }
        return $out;
    }

    /**
     * Map our 4-level severity onto CI3's narrower log levels.
     * CI3 supports only error / debug / info — 'warning' coerced to 'info'
     * (the structured prefix makes severity recoverable downstream).
     */
    private function _severityToLogLevel(string $severity): string
    {
        switch ($severity) {
            case 'info':     return 'info';
            case 'warning':  return 'info';
            case 'error':    return 'error';
            case 'critical': return 'error';
        }
        return 'info';
    }

    /** Best-effort client IP extraction. Never throws. */
    private function _clientIp(): string
    {
        $candidates = [
            $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '',
            $_SERVER['REMOTE_ADDR'] ?? '',
        ];
        foreach ($candidates as $ip) {
            if ($ip !== '') {
                $first = trim(explode(',', $ip)[0]);
                if (filter_var($first, FILTER_VALIDATE_IP)) return $first;
            }
        }
        return '';
    }

    /** User agent capped to 256 chars. */
    private function _userAgent(): string
    {
        return substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 256);
    }

    /**
     * Make a value safe for inclusion in the structured log line.
     * Replaces whitespace + control chars so the kv format is unambiguous.
     */
    private function _safeKv(string $v): string
    {
        $v = preg_replace('/[\s\x00-\x1F]+/', '_', $v);
        if (strlen($v) > 200) $v = substr($v, 0, 200) . '...';
        return $v;
    }
}
