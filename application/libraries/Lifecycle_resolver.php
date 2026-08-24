<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Lifecycle_resolver — the ONE canonical answer to
 * "may ANYTHING happen for this tenant right now?"
 *
 * ──────────────────────────────────────────────────────────────────────
 *  WHAT THIS IS NOT
 * ──────────────────────────────────────────────────────────────────────
 * This is NOT authorization. It never looks at the acting user, their role,
 * or their permissions. rbac_helper.php's has_permission()/require_permission()
 * remains the sole authority for "can THIS USER do X", exactly as
 * Tenant_capability_service remains the sole authority for "does this tenant
 * have module X". Three separate gates, composed with AND, never OR:
 *
 *     LAYER 1  Lifecycle_resolver      — may anyone act here, right now?
 *     LAYER 2  rbac_helper             — may THIS actor do it?
 *     LAYER 3  tenant scoping / rules  — is it THEIR data?
 *
 * A permissive answer here grants nothing. A restrictive answer denies
 * everything below it.
 *
 * ──────────────────────────────────────────────────────────────────────
 *  THE TWO AXES
 * ──────────────────────────────────────────────────────────────────────
 * COMMERCIAL  schoolControl/{id}.lifecycle.state — "is the tenant entitled
 *             to operate ZenX?"  Owned by B2 (B2_registry_service). This
 *             library READS it and never writes it.
 *
 * INSTITUTION schools/{id}.institution.state — "what is the real-world
 *             operational state of this institution?"  Owned by
 *             Institution_lifecycle. This library READS it and never
 *             writes it.
 *
 * They are ORTHOGONAL. A school can be commercially suspended while it is
 * institutionally ACTIVE (they still teach children; the invoice bounced).
 * A school can be institutionally CLOSED while commercially active (records
 * retained under a paid plan). Composition is MOST-RESTRICTIVE-WINS.
 *
 * ──────────────────────────────────────────────────────────────────────
 *  BACKWARD COMPATIBILITY — the single most important property
 * ──────────────────────────────────────────────────────────────────────
 * A tenant with NO `schools.institution.state` resolves to ACTIVE, whose
 * capability row is all-true. The institution axis is therefore completely
 * INERT until a state is explicitly written for a tenant. Shipping this
 * library changes NO existing behaviour on ANY tenant; the only behaviour
 * change in this release comes from the commercial axis finally being
 * consulted by CLI jobs, which previously consulted nothing at all.
 *
 * @see Institution_lifecycle   — writes the institution axis
 * @see B2_registry_service     — owns the commercial axis
 * @see lifecycle_guard_helper  — the CLI-job caller
 */
class Lifecycle_resolver
{
    // ── Commercial states (mirror of B2_registry_service) ─────────────
    /** States that PERMIT access. Mirror of B2_registry_service::ACCESS_LIFECYCLE_STATES. */
    const COMMERCIAL_ALLOW = ['trialing', 'active', 'expiring_soon', 'grace'];

    /** Known commercial states that DENY access. */
    const COMMERCIAL_DENY = ['past_due', 'suspended', 'expired', 'provisioning_incomplete'];

    // ── Institution states ────────────────────────────────────────────
    const INST_PROSPECT   = 'PROSPECT';
    const INST_ONBOARDING = 'ONBOARDING';
    const INST_ACTIVE     = 'ACTIVE';
    const INST_CLOSING    = 'CLOSING';
    const INST_CLOSED     = 'CLOSED';
    const INST_ARCHIVED   = 'ARCHIVED';

    const INSTITUTION_STATES = [
        self::INST_PROSPECT,
        self::INST_ONBOARDING,
        self::INST_ACTIVE,
        self::INST_CLOSING,
        self::INST_CLOSED,
        self::INST_ARCHIVED,
    ];

    // ── Capability keys ───────────────────────────────────────────────
    const CAPS = [
        'allowRead',
        'allowWrite',
        'allowLogin',
        'allowPayments',
        'allowNewIntake',
        'allowJobs',
        'allowNotifications',
        'allowDocuments',
        'allowTransportSafety',
        'allowHistoricalAccess',
    ];

    // ── Job classes (used by CLI schedulers) ──────────────────────────
    const JOB_MONEY        = 'money';        // fee demands, fines, payroll, reconcilers
    const JOB_BILLING      = 'billing';      // SaaS billing — must survive suspension
    const JOB_SAFETY       = 'safety';       // GPS, SOS, in-flight trips — never blocked
    const JOB_RETENTION    = 'retention';    // backups, exports, archival
    const JOB_OPERATIONAL  = 'operational';  // attendance locks, activity sweeps
    const JOB_NOTIFICATION = 'notification'; // birthdays, expiry reminders
    const JOB_ANALYTICS    = 'analytics';    // rollups
    const JOB_INTEGRITY    = 'integrity';    // consistency checks, self-heal

    const JOB_CLASSES = [
        self::JOB_MONEY, self::JOB_BILLING, self::JOB_SAFETY, self::JOB_RETENTION,
        self::JOB_OPERATIONAL, self::JOB_NOTIFICATION, self::JOB_ANALYTICS, self::JOB_INTEGRITY,
    ];

    /** @var object|null Firebase library instance (firestoreGet). */
    private $firebase = null;
    /** @var array Request-scoped memo, keyed by schoolId. */
    private $memo = [];

    // ═════════════════════════════════════════════════════════════════
    //  PURE CORE — no I/O, fully unit-testable, the parity surface
    // ═════════════════════════════════════════════════════════════════

    /**
     * Normalize an institution state. Anything unrecognised — including the
     * empty string and null — becomes ACTIVE.
     *
     * This is the backward-compatibility rule and it is deliberate: a tenant
     * that predates this feature has no institution field, and must behave
     * exactly as it did before. Fail-OPEN here is correct precisely because
     * the commercial axis (which fails closed) is composed on top.
     */
    public static function normalize_institution($state): string
    {
        $s = strtoupper(trim((string) $state));
        return in_array($s, self::INSTITUTION_STATES, true) ? $s : self::INST_ACTIVE;
    }

    /**
     * Normalize a commercial state. Unknown/absent is preserved as '' so the
     * caller can distinguish "unreadable" from "known-denied" — an unreadable
     * tenant is NOT the same as a suspended one and is logged differently.
     */
    public static function normalize_commercial($state): string
    {
        $s = strtolower(trim((string) $state));
        return ($s === '') ? '' : $s;
    }

    /** Capability row for a commercial state. */
    public static function commercial_caps(string $state): array
    {
        $s = self::normalize_commercial($state);
        if (in_array($s, self::COMMERCIAL_ALLOW, true)) {
            return self::_row(true);
        }
        // Known-denied AND unknown both deny. The ONLY carve-out is
        // transport safety: a bus with children aboard is a safety system,
        // never a billing lever. See Institution lifecycle research §11.
        $row = self::_row(false);
        $row['allowTransportSafety'] = true;
        return $row;
    }

    /** Capability row for an institution state. */
    public static function institution_caps(string $state): array
    {
        switch (self::normalize_institution($state)) {
            case self::INST_PROSPECT:
                return self::_row(false);

            case self::INST_ONBOARDING:
                // Admin-side configuration and migration only. No live
                // operations, no money, no parent/teacher-facing traffic.
                return array_merge(self::_row(false), [
                    'allowRead'             => true,
                    'allowWrite'            => true,
                    'allowLogin'            => true,
                    'allowHistoricalAccess' => true,
                ]);

            case self::INST_CLOSING:
                // CBSE: a closing school must complete the running year —
                // exams, results, TCs, fee settlement all continue. The ONLY
                // thing that stops is new intake.
                return array_merge(self::_row(true), [
                    'allowNewIntake' => false,
                ]);

            case self::INST_CLOSED:
                // Operations ended; records live. Certificates remain
                // issuable (RBAC narrows WHO may issue them).
                return array_merge(self::_row(false), [
                    'allowRead'             => true,
                    'allowLogin'            => true,
                    'allowDocuments'        => true,
                    'allowHistoricalAccess' => true,
                ]);

            case self::INST_ARCHIVED:
                // Retention only. Access is SA-mediated and out-of-band.
                return array_merge(self::_row(false), [
                    'allowHistoricalAccess' => true,
                ]);

            case self::INST_ACTIVE:
            default:
                return self::_row(true);
        }
    }

    /**
     * Compose both axes. MOST-RESTRICTIVE-WINS: a capability survives only
     * if BOTH axes permit it. There is no key for which one axis can
     * re-enable something the other denied.
     */
    public static function compose(string $commercialState, $institutionState): array
    {
        $c = self::commercial_caps($commercialState);
        $i = self::institution_caps((string) $institutionState);
        $out = [];
        foreach (self::CAPS as $k) {
            $out[$k] = !empty($c[$k]) && !empty($i[$k]);
        }
        return $out;
    }

    /**
     * May a scheduled job of $jobClass run for this tenant?
     *
     * Separate from the capability row because job semantics are not a
     * subset of user semantics: billing must keep running on a suspended
     * tenant (that is how it gets un-suspended), and retention must keep
     * running on an archived one (that is the whole point of an archive).
     */
    public static function job_allowed(string $commercialState, $institutionState, string $jobClass): bool
    {
        if (!in_array($jobClass, self::JOB_CLASSES, true)) {
            return false; // unknown class → fail closed
        }
        return self::_commercial_job($commercialState, $jobClass)
            && self::_institution_job((string) $institutionState, $jobClass);
    }

    /** Commercial axis job verdict. */
    private static function _commercial_job(string $state, string $jobClass): bool
    {
        if (in_array(self::normalize_commercial($state), self::COMMERCIAL_ALLOW, true)) {
            return true;
        }
        // Denied or unknown: only the classes that MUST survive non-payment.
        // Money is deliberately absent — this is the P0 fix: a tenant
        // suspended for non-payment must stop accruing parent-facing fines.
        return in_array($jobClass, [
            self::JOB_BILLING, self::JOB_RETENTION, self::JOB_SAFETY, self::JOB_INTEGRITY,
        ], true);
    }

    /** Institution axis job verdict. */
    private static function _institution_job(string $state, string $jobClass): bool
    {
        switch (self::normalize_institution($state)) {
            case self::INST_PROSPECT:
                return $jobClass === self::JOB_RETENTION;

            case self::INST_ONBOARDING:
                return in_array($jobClass, [self::JOB_RETENTION, self::JOB_INTEGRITY], true);

            case self::INST_CLOSED:
                return in_array($jobClass, [self::JOB_RETENTION, self::JOB_INTEGRITY], true);

            case self::INST_ARCHIVED:
                return $jobClass === self::JOB_RETENTION;

            case self::INST_CLOSING:
                // Settlement, exams, transport and notices all continue.
                return true;

            case self::INST_ACTIVE:
            default:
                return true;
        }
    }

    /**
     * A stable machine code + human sentence for the composed verdict.
     * ONE canonical message set, reused by the admin panel, the apps and the
     * CLI, so a suspended school never surfaces as a raw PERMISSION_DENIED.
     *
     * @return array{code:string,message:string}
     */
    public static function explain(string $commercialState, $institutionState): array
    {
        $inst = self::normalize_institution($institutionState);
        $comm = self::normalize_commercial($commercialState);

        // Institution state is the more meaningful explanation when it is
        // itself restrictive — "the school has closed" is a better answer
        // than "the subscription lapsed", even if both are true.
        switch ($inst) {
            case self::INST_ARCHIVED:
                return ['code' => 'SCHOOL_ARCHIVED',
                        'message' => 'This school\'s records have been archived. Access is available only through the platform administrator.'];
            case self::INST_CLOSED:
                return ['code' => 'SCHOOL_CLOSED',
                        'message' => 'This school has closed. Records remain available in read-only mode.'];
            case self::INST_PROSPECT:
                return ['code' => 'SCHOOL_NOT_STARTED',
                        'message' => 'This school has not been set up yet.'];
            case self::INST_ONBOARDING:
                return ['code' => 'SCHOOL_ONBOARDING',
                        'message' => 'This school is still being set up and is not open for day-to-day use yet.'];
        }

        if (!in_array($comm, self::COMMERCIAL_ALLOW, true)) {
            if ($comm === '') {
                return ['code' => 'TENANT_STATE_UNKNOWN',
                        'message' => 'The school\'s account status could not be confirmed. Please try again shortly.'];
            }
            if ($comm === 'provisioning_incomplete') {
                return ['code' => 'TENANT_PROVISIONING',
                        'message' => 'This school\'s setup did not finish. Please contact support.'];
            }
            return ['code' => 'TENANT_' . strtoupper($comm),
                    'message' => 'This school\'s ZenX subscription is not active. Please contact your school administrator.'];
        }

        if ($inst === self::INST_CLOSING) {
            return ['code' => 'SCHOOL_CLOSING',
                    'message' => 'This school is closing. New admissions are closed; all other operations continue.'];
        }

        return ['code' => 'OK', 'message' => ''];
    }

    /** Build a capability row with every key set to $v. */
    private static function _row(bool $v): array
    {
        $row = [];
        foreach (self::CAPS as $k) { $row[$k] = $v; }
        return $row;
    }

    // ═════════════════════════════════════════════════════════════════
    //  I/O SHELL — reads the two axes for a real tenant
    // ═════════════════════════════════════════════════════════════════

    /** Bind a Firebase client. Idempotent. */
    public function init($firebase): self
    {
        $this->firebase = $firebase;
        return $this;
    }

    /**
     * Resolve both axes for a tenant.
     *
     * READ BUDGET: at most 2 document reads (schoolControl + schools), memoized
     * per request. Callers that already hold either document SHOULD use the
     * pure static core instead and spend zero reads.
     *
     * @return array{
     *   known:bool, commercial:string, institution:string,
     *   caps:array, code:string, message:string
     * }
     */
    public function resolve(string $schoolId): array
    {
        if ($schoolId === '') {
            return $this->_verdict('', self::INST_ACTIVE, false);
        }
        if (isset($this->memo[$schoolId])) {
            return $this->memo[$schoolId];
        }

        $commercial = '';
        $institution = '';
        $known = false;

        if ($this->firebase !== null) {
            try {
                $ctrl = $this->firebase->firestoreGet('schoolControl', $schoolId);
                if (is_array($ctrl)) {
                    $commercial = (string) ($ctrl['lifecycle']['state'] ?? '');
                    $known = ($commercial !== '');
                }
            } catch (\Throwable $e) {
                log_message('error', 'Lifecycle_resolver: schoolControl read failed for '
                    . $schoolId . ': ' . $e->getMessage());
            }
            try {
                $sch = $this->firebase->firestoreGet('schools', $schoolId);
                if (is_array($sch)) {
                    $institution = (string) ($sch['institution']['state'] ?? '');
                    // The SA operational toggle is a second suspension signal
                    // and the MORE RESTRICTIVE one wins, exactly as
                    // firestore.rules::tenantActive() composes it.
                    if (!empty($sch['adminDisabled']['value'])) {
                        $commercial = 'suspended';
                        $known = true;
                    }
                }
            } catch (\Throwable $e) {
                log_message('error', 'Lifecycle_resolver: schools read failed for '
                    . $schoolId . ': ' . $e->getMessage());
            }
        }

        return $this->memo[$schoolId] = $this->_verdict($commercial, $institution, $known);
    }

    private function _verdict(string $commercial, $institution, bool $known): array
    {
        $explain = self::explain($commercial, $institution);
        return [
            'known'       => $known,
            'commercial'  => self::normalize_commercial($commercial),
            'institution' => self::normalize_institution($institution),
            'caps'        => self::compose($commercial, $institution),
            'code'        => $explain['code'],
            'message'     => $explain['message'],
        ];
    }

    /** Clear the request-scoped memo (used by tests and long-running CLI loops). */
    public function reset(): self
    {
        $this->memo = [];
        return $this;
    }
}
