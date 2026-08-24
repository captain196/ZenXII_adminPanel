<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * lifecycle_guard_helper — the gate that scheduled jobs consult before
 * touching a tenant.
 *
 * ──────────────────────────────────────────────────────────────────────
 *  WHY THIS EXISTS
 * ──────────────────────────────────────────────────────────────────────
 * ZenX's tenant state was built as a LOGIN gate (MY_Controller) and a
 * CLIENT gate (firestore.rules::tenantActive()). Neither fires for a CLI
 * job: crons run under the service account, which bypasses rules entirely,
 * and never touch a session. Every scheduled job therefore operated on a
 * tenant handed to it by the scheduler without ever asking whether that
 * tenant was entitled to be operated on.
 *
 * The concrete consequence, and the reason this helper exists: a school
 * suspended for non-payment kept accruing PARENT-FACING late fees every
 * night. Money owed by families was being created for a tenant the platform
 * had switched off.
 *
 * ──────────────────────────────────────────────────────────────────────
 *  FAIL POSTURE — deliberately asymmetric
 * ──────────────────────────────────────────────────────────────────────
 * When tenant state cannot be read (Firestore blip, missing schoolControl
 * doc), the answer depends on what the job does:
 *
 *   money / notification / analytics / operational → SKIP (fail closed)
 *       Charging a family, or messaging a parent, on an unverifiable tenant
 *       is worse than skipping a night. All of these jobs are idempotent
 *       and resume on the next run.
 *
 *   billing / retention / safety / integrity       → RUN (fail open)
 *       Billing is how a suspended tenant gets un-suspended; retention
 *       protects data; safety must never depend on a billing lookup;
 *       integrity checks are read-mostly and self-healing.
 *
 * ──────────────────────────────────────────────────────────────────────
 *  USAGE (CLI job)
 * ──────────────────────────────────────────────────────────────────────
 *   $this->load->helper('lifecycle_guard');
 *   lifecycle_guard_or_skip($this->firebase, $schoolId,
 *       Lifecycle_resolver::JOB_MONEY, 'fees_late_fee_cron');
 *
 * On a skip the job prints one structured line and exits 0 — a skipped
 * tenant is a correct outcome, not a scheduler failure.
 */

// Loaded at helper-include time so callers can reference the JOB_* class
// constants in the same statement that calls the guard.
if (!class_exists('Lifecycle_resolver')) {
    require_once APPPATH . 'libraries/Lifecycle_resolver.php';
}

if (!function_exists('lifecycle_guard_check')) {
    /**
     * May a job of $jobClass run for $schoolId right now?
     *
     * Costs at most 2 Firestore document reads (schoolControl + schools),
     * memoized per process by Lifecycle_resolver.
     *
     * @return array{allowed:bool, known:bool, commercial:string,
     *               institution:string, code:string, message:string}
     */
    function lifecycle_guard_check($firebase, string $schoolId, string $jobClass): array
    {
        if (!class_exists('Lifecycle_resolver')) {
            require_once APPPATH . 'libraries/Lifecycle_resolver.php';
        }

        $resolver = new Lifecycle_resolver();
        $verdict  = $resolver->init($firebase)->resolve($schoolId);

        $allowed = Lifecycle_resolver::job_allowed(
            $verdict['commercial'], $verdict['institution'], $jobClass
        );

        // Unknown tenant state: apply the asymmetric posture documented above.
        if (empty($verdict['known'])) {
            $failOpen = in_array($jobClass, [
                Lifecycle_resolver::JOB_BILLING,
                Lifecycle_resolver::JOB_RETENTION,
                Lifecycle_resolver::JOB_SAFETY,
                Lifecycle_resolver::JOB_INTEGRITY,
            ], true);
            $allowed = $failOpen;
        }

        return [
            'allowed'     => $allowed,
            'known'       => !empty($verdict['known']),
            'commercial'  => (string) $verdict['commercial'],
            'institution' => (string) $verdict['institution'],
            'code'        => (string) $verdict['code'],
            'message'     => (string) $verdict['message'],
        ];
    }
}

if (!function_exists('lifecycle_web_verdict')) {
    /**
     * Resolve the lifecycle verdict for the CURRENT web request, once.
     *
     * Memoized in a static for the life of the request, and the underlying
     * reads are already served from Firestore_rest_client's request memo +
     * config cache (schools/schoolControl are both on it), so the steady-state
     * cost of this gate is zero additional round-trips.
     *
     * @return array|null null when there is no tenant context (login pages,
     *                    Superadmin surfaces) — the caller must then skip.
     */
    function lifecycle_web_verdict(): ?array
    {
        static $memo = null;
        static $memoSchool = '';

        $CI = function_exists('get_instance') ? get_instance() : null;
        if ($CI === null) return null;

        $schoolId = '';
        if (isset($CI->school_id) && is_string($CI->school_id)) {
            $schoolId = $CI->school_id;
        }
        if ($schoolId === '' || !isset($CI->firebase)) return null;

        if ($memo !== null && $memoSchool === $schoolId) return $memo;

        $memoSchool = $schoolId;
        $resolver = new Lifecycle_resolver();
        return $memo = $resolver->init($CI->firebase)->resolve($schoolId);
    }
}

if (!function_exists('lifecycle_allows_operation')) {
    /**
     * Per-operation gate for tenant-side Admin Panel requests.
     *
     * ──────────────────────────────────────────────────────────────────
     *  WHY THIS EXISTS (defect N1)
     * ──────────────────────────────────────────────────────────────────
     * Tenant state used to be checked ONLY at login and then re-checked on
     * a 300-second timer, and even then it merely forced a logout. Between
     * ticks, an administrator of a school that had just been suspended or
     * closed retained FULL WRITE ACCESS for up to five minutes — and because
     * the PHP backend writes through the Admin SDK, firestore.rules never
     * saw those writes either. Neither of the two existing gates covered the
     * in-session admin request.
     *
     * This closes that window by deciding per operation rather than per
     * session.
     *
     * ──────────────────────────────────────────────────────────────────
     *  COMPOSITION, NOT REPLACEMENT
     * ──────────────────────────────────────────────────────────────────
     * This never grants anything. It answers only "may ANYONE do this here,
     * right now?" and is ANDed with the existing graded RBAC decision, which
     * remains the sole authority on "may THIS actor do it". No permission
     * model is added, changed or duplicated.
     *
     * ──────────────────────────────────────────────────────────────────
     *  FAIL POSTURE
     * ──────────────────────────────────────────────────────────────────
     * KNOWN-denied  → block. UNKNOWN (Firestore unreachable) → ALLOW, and log.
     * A network blip must not brick every school's admin panel; the login
     * gate and the client rules both still apply, so an unverifiable state
     * degrades to today's behaviour rather than to an outage.
     *
     * ──────────────────────────────────────────────────────────────────
     *  SAFETY CARVE-OUT
     * ──────────────────────────────────────────────────────────────────
     * A small, explicitly enumerated set of transport operations is gated on
     * `allowTransportSafety` instead of `allowWrite`. See
     * LIFECYCLE_SAFETY_OPS below for the list and the reasoning.
     *
     * @param string $module RBAC module key, e.g. 'Fees'
     * @param string $level  'view' | 'edit' | 'manage'
     * @param string $action the action label already passed to
     *                       require_permission(), e.g. 'sos_raise'
     * @return array{allowed:bool, code:string, message:string, known:bool}
     */
    function lifecycle_allows_operation(string $module, string $level = 'view', string $action = ''): array
    {
        $ok = ['allowed' => true, 'code' => 'OK', 'message' => '', 'known' => false];

        $v = lifecycle_web_verdict();
        if ($v === null) return $ok;               // no tenant context
        if (empty($v['known'])) {
            log_message('error', 'LIFECYCLE_WEB_GATE_UNKNOWN module=' . $module
                . ' level=' . $level . ' — tenant state unreadable, allowing (fail-open)');
            return $ok;                            // unverifiable → today's behaviour
        }

        $caps    = is_array($v['caps'] ?? null) ? $v['caps'] : [];
        $isWrite = in_array(strtolower($level), ['edit', 'manage'], true);

        // Document/certificate issuance survives CLOSED: a closed school must
        // still be able to produce a transfer certificate or reprint a mark
        // sheet, which is precisely why CLOSED is not "switched off". RBAC
        // still decides WHO may do it, and the institution axis still denies
        // it outright once the school is ARCHIVED.
        $docModules = ['Certificates', 'Results'];
        $capKey = $isWrite ? 'allowWrite' : 'allowRead';
        if ($isWrite && in_array($module, $docModules, true) && !empty($caps['allowDocuments'])) {
            $capKey = 'allowDocuments';
        }

        // ── SAFETY CARVE-OUT ────────────────────────────────────────────
        // These operations are gated on allowTransportSafety, which stays
        // TRUE through commercial suspension and FALSE once the institution
        // is CLOSED or ARCHIVED (a closed school runs no buses).
        //
        // WHY THIS IS NEEDED, precisely:
        //   B2_lifecycle_job / Superadmin_plans::expire_check flip a tenant
        //   to `suspended` automatically when its grace period lapses. That
        //   job is unattended and date-driven, so it can fire at any hour —
        //   including while a bus is mid-route with children aboard. Without
        //   this carve-out, a billing job would silently disable the
        //   emergency channel and the ability to close out a running trip.
        //
        // WHAT IT DELIBERATELY DOES NOT DO:
        //   • It does not reopen general tenant access — every other module
        //     and action still resolves through allowWrite/allowRead.
        //   • It does not touch Fees, Accounting, admissions or attendance.
        //   • It does not bypass RBAC: require_permission() still evaluates
        //     the actor's graded permission immediately afterwards.
        //   • It does not bypass tenant isolation.
        //   • It grants nothing once the school is CLOSED or ARCHIVED.
        //
        // Scope is an explicit action allow-list, not a module-wide opening,
        // so adding a new Transport endpoint does not silently inherit it.
        if (defined('LIFECYCLE_SAFETY_OPS') === false) {
            define('LIFECYCLE_SAFETY_OPS', [
                // Emergency channel.
                'sos_raise', 'sos_respond', 'sos_resolve',
                // Closing out a trip that is already on the road. Starting a
                // NEW trip is deliberately absent — a suspended school may
                // finish what is running, not dispatch more.
                'trip_complete', 'override_complete_trip', 'trip_cancel',
                'trip_checkpoint_reached', 'trip_checkpoint_left', 'trip_checkpoint_skipped',
                'trip_board_student', 'trip_drop_student', 'trip_no_show_student',
            ]);
        }
        if ($module === 'Transport' && $action !== ''
            && in_array($action, LIFECYCLE_SAFETY_OPS, true)) {
            $capKey = 'allowTransportSafety';
        }

        if (!empty($caps[$capKey])) {
            return ['allowed' => true, 'code' => 'OK', 'message' => '', 'known' => true];
        }

        return [
            'allowed' => false,
            'code'    => (string) ($v['code'] ?? 'LIFECYCLE_BLOCKED'),
            'message' => (string) ($v['message'] ?? 'This school is not currently operating.'),
            'known'   => true,
        ];
    }
}

if (!function_exists('lifecycle_guard_or_skip')) {
    /**
     * Enforce the guard in a CLI job: on a deny, emit one structured line on
     * STDOUT, log it, and exit 0.
     *
     * Exit code 0 is deliberate — "this tenant is suspended/closed, so there
     * was nothing to do" is a successful run. A non-zero code would make
     * every scheduler alert on a perfectly correct skip.
     */
    function lifecycle_guard_or_skip($firebase, string $schoolId, string $jobClass, string $jobName): array
    {
        $v = lifecycle_guard_check($firebase, $schoolId, $jobClass);
        if (!empty($v['allowed'])) {
            return $v;
        }

        $line = sprintf(
            "LIFECYCLE_GUARD_SKIP job=%s class=%s school=%s commercial=%s institution=%s known=%s code=%s\n",
            $jobName, $jobClass, $schoolId,
            $v['commercial'] !== '' ? $v['commercial'] : 'unknown',
            $v['institution'],
            !empty($v['known']) ? 'yes' : 'no',
            $v['code']
        );

        if (function_exists('log_message')) {
            log_message('error', rtrim($line));
        }
        if (defined('STDOUT')) {
            fwrite(STDOUT, $line);
        }
        exit(0);
    }
}
