<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Benefit_policy_service — WHETHER a school offers a benefit, and on what terms.
 *
 * ─────────────────────────────────────────────────────────────────────────
 *  WHAT THIS IS FOR
 * ─────────────────────────────────────────────────────────────────────────
 *  ZenX now has VERIFIED relationships — a sibling resolved by SR Number, a
 *  staff ward resolved by Staff ID — and every one of those rows carries
 *  `financialEffect: 'none'`. That was deliberate:
 *
 *      VERIFIED RELATIONSHIP  !=  AUTOMATIC FINANCIAL BENEFIT
 *
 *  Real schools differ. One gives 25% to a second child; one gives nothing;
 *  one gives it only after the principal signs; one gives staff children free
 *  tuition but still charges transport. None of those is more correct than the
 *  others, so none of them may be hardcoded here.
 *
 *  This service holds the school's ANSWER, and nothing else decides it.
 *
 * ─────────────────────────────────────────────────────────────────────────
 *  WHY feeSettings AND NOT A NEW STORE
 * ─────────────────────────────────────────────────────────────────────────
 *  feeSettings is already ZenX's school+session financial configuration
 *  collection — `{schoolId}_{session}_{type}` already carries gateway,
 *  counters, rollover and reminders. A second configuration system would be a
 *  second place to look when money is wrong. This is one more `type`.
 *
 *  Session-scoped, because fee policy belongs to an academic year: changing
 *  next year's sibling rate must not reach into this year's billing.
 *
 * ─────────────────────────────────────────────────────────────────────────
 *  FAIL SAFE IS THE WHOLE POINT
 * ─────────────────────────────────────────────────────────────────────────
 *  Absent document, absent key, unreadable value, malformed config, storage
 *  error — every one of them resolves to DISABLED. A school that has not
 *  decided has not consented, and "we could not read the policy" must never
 *  become "so we gave a discount". There is no default percentage anywhere in
 *  this file; a benefit exists only because a school explicitly configured it.
 *
 * ─────────────────────────────────────────────────────────────────────────
 *  WHAT IS NOT CONFIGURABLE HERE
 * ─────────────────────────────────────────────────────────────────────────
 *  Only the QUESTIONS a school may legitimately answer differently. Every
 *  financial safety rule stays in the platform and is unreachable from this
 *  document: tenant isolation, verified eligibility, no negative net, discount
 *  never exceeding the charge, idempotency, maker-checker, effective-date
 *  honouring, audit trails, and I3's protection of service categories from
 *  blanket concessions. There is deliberately no switch that turns any of
 *  those off, because they are not preferences.
 */
class Benefit_policy_service
{
    private const COL = 'feeSettings';
    private const DOC_TYPE = 'benefit_policy';

    public const BENEFIT_SIBLING    = 'sibling';
    public const BENEFIT_STAFF_WARD = 'staff_ward';
    public const BENEFITS = [self::BENEFIT_SIBLING, self::BENEFIT_STAFF_WARD];

    /**
     * ══ COLLECTION-LAYER ADJUSTMENT · A DIFFERENT BUSINESS CONCEPT ═════════
     *
     * A counter waiver is NOT a planned benefit. It is an exception a human
     * makes at the desk — "the family lost a term's income, take 2,000 off
     * today" — and schools genuinely need it. So it is governed, not removed.
     *
     * It is kept OUT of self::BENEFITS deliberately: the benefits above are
     * evaluated automatically against verified relationships, and an ad-hoc
     * waiver must never be granted by any automatic path.
     *
     * What the school may choose is the CEILING. What it may not choose is
     * whether a waiver needs a reason, or whether it may exceed the bill —
     * those are platform invariants enforced in Fees::submit_discount.
     */
    public const ADJUSTMENT = 'collection_adjustment';

    /** No configured ceiling means the invariant ceiling still applies. */
    public static function defaultAdjustmentPolicy(): array
    {
        return [
            'maxAmount'    => 0.0,    // 0 = no school ceiling beyond the outstanding balance
            'maxPercent'   => 0.0,    // 0 = no percentage ceiling
            'policyVersion' => 0,
            'source'       => 'default_uncapped_within_outstanding',
        ];
    }

    /** The school's ceiling for a counter adjustment. Never widens the invariant. */
    public function resolveAdjustment(): array
    {
        $out = self::defaultAdjustmentPolicy();
        $doc = $this->raw();
        if ($doc === []) return $out;

        $cfg = $doc[self::ADJUSTMENT] ?? null;
        if (!is_array($cfg)) return $out;

        $max = (float) ($cfg['maxAmount'] ?? 0);
        $pct = (float) ($cfg['maxPercent'] ?? 0);

        // A negative or absurd ceiling is a misconfiguration, not a licence.
        if ($max < 0) $max = 0.0;
        if ($pct < 0 || $pct > 100) $pct = 0.0;

        return array_merge($out, [
            'maxAmount'     => $max,
            'maxPercent'    => $pct,
            'policyVersion' => (int) ($doc['version'] ?? 0),
            'source'        => 'school_policy',
        ]);
    }

    /** The only three answers to "does this school give this benefit?". */
    public const MODE_DISABLED  = 'disabled';   // no benefit, ever
    public const MODE_APPROVAL  = 'approval';   // eligible, but a human must authorise
    public const MODE_AUTOMATIC = 'automatic';  // granted on verified eligibility alone
    public const MODES = [self::MODE_DISABLED, self::MODE_APPROVAL, self::MODE_AUTOMATIC];

    public const VALUE_PERCENT = 'percent';
    public const VALUE_FIXED   = 'fixed';

    /** @var object|null */ private $fs;
    private string $schoolId = '';
    private string $session  = '';
    private string $actorId  = '';
    private bool   $ready    = false;

    public function init($fs, string $schoolId, string $session, string $actorId = 'system'): self
    {
        $this->fs       = $fs;
        $this->schoolId = trim($schoolId);
        $this->session  = trim($session);
        $this->actorId  = trim($actorId) !== '' ? trim($actorId) : 'system';
        $this->ready    = ($fs !== null && $this->schoolId !== '' && $this->session !== '');
        return $this;
    }

    private function docId(): string
    {
        return "{$this->schoolId}_{$this->session}_" . self::DOC_TYPE;
    }

    /** The shape every benefit resolves to when the school has not said otherwise. */
    public static function disabledPolicy(string $benefit): array
    {
        return [
            'benefit'   => $benefit,
            'mode'      => self::MODE_DISABLED,
            'valueType' => self::VALUE_PERCENT,
            'value'     => 0.0,
            'scope'     => 'all',
            'effectiveFrom' => '',
            'effectiveTo'   => '',
            'maxBenefit'    => 0.0,
            'policyVersion' => 0,
            'source'        => 'default_disabled',
        ];
    }

    // ══════════════════════════════════════════════════════════════════════
    //  READ
    // ══════════════════════════════════════════════════════════════════════

    /** The whole stored document, or [] when there is none. */
    public function raw(): array
    {
        if (!$this->ready) return [];
        try {
            $doc = $this->fs->get(self::COL, $this->docId());
            return is_array($doc) ? $doc : [];
        } catch (\Throwable $e) {
            // A policy we cannot read is a policy we must not act on.
            log_message('error', 'BENEFIT_POLICY_READ_FAILED school=[' . $this->schoolId
                . '] session=[' . $this->session . '] — treating every benefit as DISABLED: '
                . $e->getMessage());
            return [];
        }
    }

    /**
     * The effective policy for one benefit. NEVER returns an enabled policy it
     * is not certain about: anything malformed collapses to disabled, and the
     * reason is logged rather than silently swallowed.
     */
    public function resolve(string $benefit): array
    {
        $off = self::disabledPolicy($benefit);
        if (!in_array($benefit, self::BENEFITS, true)) return $off;

        $doc = $this->raw();
        if ($doc === []) return $off;

        $cfg = $doc[$benefit] ?? null;
        if (!is_array($cfg)) return $off;

        $mode = strtolower(trim((string) ($cfg['mode'] ?? self::MODE_DISABLED)));
        if (!in_array($mode, self::MODES, true) || $mode === self::MODE_DISABLED) return $off;

        // An enabled policy whose numbers do not validate is MORE dangerous
        // than no policy: it would price something. Refuse it loudly.
        $candidate = array_merge($off, [
            'mode'          => $mode,
            'valueType'     => strtolower(trim((string) ($cfg['valueType'] ?? self::VALUE_PERCENT))),
            'value'         => (float) ($cfg['value'] ?? 0),
            'scope'         => trim((string) ($cfg['scope'] ?? 'all')),
            'effectiveFrom' => trim((string) ($cfg['effectiveFrom'] ?? '')),
            'effectiveTo'   => trim((string) ($cfg['effectiveTo'] ?? '')),
            'maxBenefit'    => (float) ($cfg['maxBenefit'] ?? 0),
            'policyVersion' => (int) ($doc['version'] ?? 0),
            'source'        => 'school_policy',
        ]);
        $candidate = array_merge($candidate, $this->benefitExtras($benefit, $cfg));

        $err = self::validateBenefit($benefit, $candidate);
        if ($err !== null) {
            log_message('error', 'BENEFIT_POLICY_INVALID school=[' . $this->schoolId
                . '] benefit=[' . $benefit . '] reason=[' . $err
                . '] — DISABLED. An enabled policy with unusable numbers would price something.');
            return $off;
        }
        return $candidate;
    }

    /** Per-benefit eligibility knobs that do not belong in the common shape. */
    private function benefitExtras(string $benefit, array $cfg): array
    {
        if ($benefit === self::BENEFIT_SIBLING) {
            return [
                // Whether the OTHER child must still be on the roll. A school
                // that gives the benefit for a departed sibling is unusual but
                // not wrong, so it is a choice rather than a rule.
                'requireSiblingActive' => (bool) ($cfg['requireSiblingActive'] ?? true),
            ];
        }
        return [
            // Employment must normally be current; what happens when a parent
            // leaves mid-year is a real school decision, so it is configurable.
            'requireStaffActive' => (bool) ($cfg['requireStaffActive'] ?? true),
            'maxChildren'        => (int)  ($cfg['maxChildren'] ?? 0),   // 0 = no limit
        ];
    }

    /** Is this policy in force on a given date? */
    public function isInForce(array $policy, string $onDate = ''): bool
    {
        if (($policy['mode'] ?? self::MODE_DISABLED) === self::MODE_DISABLED) return false;

        $on   = $onDate !== '' ? $onDate : date('Y-m-d');
        $from = (string) ($policy['effectiveFrom'] ?? '');
        $to   = (string) ($policy['effectiveTo'] ?? '');

        // A policy dated into the future must not apply early, and one that has
        // ended must not keep applying — the same window discipline the
        // entitlement reader enforces.
        if ($from !== '' && strcmp($on, $from) < 0) return false;
        if ($to   !== '' && strcmp($on, $to)   > 0) return false;
        return true;
    }

    // ══════════════════════════════════════════════════════════════════════
    //  VALIDATION — rejected here, never stored, never priced
    // ══════════════════════════════════════════════════════════════════════

    /** @return string|null null when valid */
    public static function validateBenefit(string $benefit, array $cfg): ?string
    {
        $mode = strtolower(trim((string) ($cfg['mode'] ?? '')));
        if (!in_array($mode, self::MODES, true)) {
            return "mode must be one of: " . implode(', ', self::MODES);
        }
        if ($mode === self::MODE_DISABLED) return null;   // nothing else matters

        $type  = strtolower(trim((string) ($cfg['valueType'] ?? '')));
        $value = (float) ($cfg['value'] ?? 0);

        if (!in_array($type, [self::VALUE_PERCENT, self::VALUE_FIXED], true)) {
            return "valueType must be 'percent' or 'fixed'";
        }
        if ($value <= 0) {
            return 'an enabled benefit must carry a value above zero — a zero benefit is a '
                 . 'disabled benefit, and storing it as enabled hides that from the operator';
        }
        if ($type === self::VALUE_PERCENT && $value > 100) {
            return 'a percentage benefit cannot exceed 100 — a discount may never be larger '
                 . 'than the charge it applies to';
        }

        $scope = trim((string) ($cfg['scope'] ?? ''));
        if ($scope !== 'all'
            && strpos($scope, 'category:') !== 0
            && strpos($scope, 'head:') !== 0) {
            return "scope must be 'all', 'category:<category>' or 'head:<feeHeadRef>'";
        }
        if ($scope !== 'all' && trim(substr($scope, strpos($scope, ':') + 1)) === '') {
            return 'a scoped benefit must name the category or head it applies to';
        }

        foreach (['effectiveFrom', 'effectiveTo'] as $f) {
            $d = trim((string) ($cfg[$f] ?? ''));
            if ($d !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) {
                return "{$f} must be YYYY-MM-DD";
            }
        }
        $from = trim((string) ($cfg['effectiveFrom'] ?? ''));
        $to   = trim((string) ($cfg['effectiveTo'] ?? ''));
        if ($from !== '' && $to !== '' && strcmp($to, $from) < 0) {
            return 'effectiveTo cannot be before effectiveFrom';
        }
        if ((float) ($cfg['maxBenefit'] ?? 0) < 0) {
            return 'maxBenefit cannot be negative';
        }
        if ($benefit === self::BENEFIT_STAFF_WARD && (int) ($cfg['maxChildren'] ?? 0) < 0) {
            return 'maxChildren cannot be negative (use 0 for no limit)';
        }
        return null;
    }

    // ══════════════════════════════════════════════════════════════════════
    //  WRITE
    // ══════════════════════════════════════════════════════════════════════

    /**
     * Save one benefit's policy.
     *
     * Bumps a monotonic `version`. Every benefit this policy later produces
     * records the version it was granted under, so a rate change is traceable
     * rather than retroactive: existing entitlements keep the terms they were
     * approved on, and the new version governs only what is evaluated next.
     *
     * @return array{ok:bool, version?:int, error?:string}
     */
    public function saveBenefit(string $benefit, array $cfg): array
    {
        if (!$this->ready) return ['ok' => false, 'error' => 'Policy service not initialised.'];
        if (!in_array($benefit, self::BENEFITS, true)) {
            return ['ok' => false, 'error' => "Unknown benefit: {$benefit}"];
        }

        $err = self::validateBenefit($benefit, $cfg);
        if ($err !== null) return ['ok' => false, 'error' => $err];

        try {
            $doc     = $this->raw();
            $version = ((int) ($doc['version'] ?? 0)) + 1;
            $now     = date('c');

            $clean = [
                'mode'          => strtolower(trim((string) $cfg['mode'])),
                'valueType'     => strtolower(trim((string) ($cfg['valueType'] ?? self::VALUE_PERCENT))),
                'value'         => round((float) ($cfg['value'] ?? 0), 4),
                'scope'         => trim((string) ($cfg['scope'] ?? 'all')),
                'effectiveFrom' => trim((string) ($cfg['effectiveFrom'] ?? '')),
                'effectiveTo'   => trim((string) ($cfg['effectiveTo'] ?? '')),
                'maxBenefit'    => round((float) ($cfg['maxBenefit'] ?? 0), 2),
                'updatedAt'     => $now,
                'updatedBy'     => $this->actorId,
            ];
            $clean = array_merge($clean, $this->benefitExtras($benefit, $cfg));

            $payload = [
                'schoolId'  => $this->schoolId,
                'session'   => $this->session,
                'type'      => self::DOC_TYPE,
                'version'   => $version,
                'updatedAt' => $now,
                'updatedBy' => $this->actorId,
                $benefit    => $clean,
                // Append-only trail. A financial policy change must be
                // answerable for later, and "who set 50% and when" is exactly
                // the question an auditor asks.
                'history'   => array_merge((array) ($doc['history'] ?? []), [[
                    'version'   => $version,
                    'benefit'   => $benefit,
                    'mode'      => $clean['mode'],
                    'valueType' => $clean['valueType'],
                    'value'     => $clean['value'],
                    'scope'     => $clean['scope'],
                    'changedBy' => $this->actorId,
                    'changedAt' => $now,
                ]]),
            ];

            if (!$this->fs->set(self::COL, $this->docId(), $payload, /* merge */ true)) {
                return ['ok' => false, 'error' => 'Could not save the policy.'];
            }

            log_message('error', 'BENEFIT_POLICY_CHANGED school=[' . $this->schoolId
                . '] session=[' . $this->session . '] benefit=[' . $benefit
                . '] mode=[' . $clean['mode'] . '] value=[' . $clean['valueType'] . ':' . $clean['value']
                . '] scope=[' . $clean['scope'] . '] version=[' . $version
                . '] by=[' . $this->actorId . ']');

            return ['ok' => true, 'version' => $version];
        } catch (\Throwable $e) {
            log_message('error', 'BENEFIT_POLICY_SAVE_FAILED: ' . $e->getMessage());
            return ['ok' => false, 'error' => 'Could not save the policy.'];
        }
    }
}
