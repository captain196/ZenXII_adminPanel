<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Transport_notifier — F6 Communication Integration (2026-07-07)
 * ══════════════════════════════════════════════════════════════════════
 *
 * Single facade for ALL Transport-module notification emission. Consolidates
 * the 7 dormant call-sites in Transport_service (which invoked a
 * non-existent Communication_helper::createNotice) into a real, testable,
 * unified contract.
 *
 * SCOPE (LC-12 notice fanout + LC-13 SOS admin+cascade):
 *   • 18 canonical categories × 4 audience scopes × Firestore-only fanout
 *   • Category → priority matrix (Refinement additional 2026-07-07)
 *   • 30-day idempotency (Refinement 2)
 *   • No auto-escalation (Refinement 1) — architecture stays open for future
 *   • No rate-limiting (Refinement 4) — dedup handles storm prevention
 *   • No parent preferences (Refinement 3) — safety notices never suppressed
 *
 * COLLECTIONS TOUCHED:
 *   • notices                       — ops-scope role fanout (reuse existing)
 *   • noticeRecipients              — per-user delivery rows (reuse existing)
 *   • notifications                 — per-parent inbox (reuse existing;
 *                                     type='transport' reserved by app)
 *   • sosAlerts                     — SOS state (new writes; shipped model)
 *   • transportNoticeIdempotency    — dedup layer (NEW, F6)
 *   • transportAuditLog             — audit rows via Transport_service
 *
 * CONTRACT PER FIRING:
 *   $notifier->notify('route.suspended', [
 *       'route_id'      => $routeId,
 *       'route_name'    => $routeName,
 *       'reason'        => $reason,
 *       'reason_category'=> $cat,
 *       'from_date'     => $from,
 *       'to_date'       => $to,
 *       'suspension_id' => $suspId,
 *       'notify_parents'=> true,
 *   ]);
 *   Returns: ['emitted'=>bool, 'notice_id'=>string, 'per_user_count'=>int,
 *            'dedupe_skipped'=>bool, 'category'=>string, 'priority'=>string]
 *
 * FANOUT TOPOLOGY (chosen by category → audienceScope, not by caller):
 *   ops                    → 1 notices doc + N noticeRecipients rows
 *   parents_of_route       → resolve parents → N notifications docs + push
 *   parents_of_vehicle     → aggregate routes → parents_of_route path
 *   staff_admin_only       → 1 notices doc with targetRoles=['Admin'] only
 *
 * FIRESTORE-ONLY. NO RTDB. Reads no legacy path. All Transport call-sites
 * that previously targeted a non-existent createNotice route through this
 * class post-F6.
 */
class Transport_notifier
{
    // ═════════════════════════════════════════════════════════════════════
    //  Constants — category enum, priority matrix, dedup TTL, audience map
    // ═════════════════════════════════════════════════════════════════════

    public const PRIORITY_NORMAL    = 'Normal';
    public const PRIORITY_IMPORTANT = 'Important';
    public const PRIORITY_URGENT    = 'Urgent';

    public const SCOPE_OPS                 = 'ops';
    public const SCOPE_PARENTS_OF_ROUTE    = 'parents_of_route';
    public const SCOPE_PARENTS_OF_VEHICLE  = 'parents_of_vehicle';
    public const SCOPE_PARENTS_OF_STUDENTS = 'parents_of_students';   // F7
    public const SCOPE_STAFF_ADMIN_ONLY    = 'staff_admin_only';

    // F9 (2026-07-07) — SOS severity levels + priority routing.
    // Teacher-app default is 'High' (Refinement 1 — bias toward
    // operational safety); driver may explicitly lower.
    public const SOS_SEVERITIES = ['Low', 'Medium', 'High', 'Critical'];
    public const SOS_DEFAULT_SEVERITY = 'High';

    /**
     * Severity → notifier priority. High + Critical route as Urgent
     * (safety exemption preserved — never deduped via DEDUP_TTL
     * omission of sos.raised). Medium → Important (respects existing
     * dedup TTL). Low → Normal.
     */
    public const SOS_SEVERITY_TO_PRIORITY = [
        'Low'      => self::PRIORITY_NORMAL,
        'Medium'   => self::PRIORITY_IMPORTANT,
        'High'     => self::PRIORITY_URGENT,
        'Critical' => self::PRIORITY_URGENT,
    ];

    /**
     * The 18 canonical F6 categories. Every emitter must reference one of
     * these strings; a category not present here is a LogicException.
     */
    public const CATEGORIES = [
        // Fleet (F1)
        'fleet.doc_expiring',
        'fleet.vehicle_replaced',
        'fleet.vehicle_retired',
        // Route (F2)
        'route.suspended',
        'route.resumed',
        'route.suspension_notice_parents',
        // Assignment (F3)
        'assignment.bulk_complete',
        'assignment.rollover_complete',
        // Driver (F4)
        'driver.replaced',
        'driver.critical_incident',
        'driver.licence_expiring',
        // Expense (F5)
        'expense.post_failed',
        'expense.pending_stale',
        // SOS (F6)
        'sos.raised',
        'sos.responded',
        'sos.resolved',
        // Trip (F6 - admin-fired MVP; F9 adds teacher-fired)
        'trip.delay',
        'trip.disruption',
        // Trip lifecycle + boarding (F7, 2026-07-07)
        'trip.started',
        'trip.completed',
        'trip.student_no_show',
        // Transport Events (F8, 2026-07-07)
        'event.scheduled',
        'event.rescheduled',
        'event.cancelled',
        'event.trip_dispatched',
        'event.consent_reminder',
        'event.completed',
    ];

    /**
     * Priority matrix (Refinement additional 2026-07-07). Every Transport
     * notification category → fixed default priority. Ensures consistent
     * treatment across current and future emitters; no ad-hoc priority
     * decisions at the call-site.
     *
     * Rationale grouping:
     *   Normal    — operational summaries, positive resolutions
     *   Important — service continuity, compliance-adjacent, parent impact
     *   Urgent    — safety events, live incidents, blocked accounting
     */
    public const PRIORITY_MATRIX = [
        // Fleet
        'fleet.doc_expiring'              => self::PRIORITY_IMPORTANT,
        'fleet.vehicle_replaced'          => self::PRIORITY_NORMAL,
        'fleet.vehicle_retired'           => self::PRIORITY_NORMAL,
        // Route
        'route.suspended'                 => self::PRIORITY_IMPORTANT,
        'route.resumed'                   => self::PRIORITY_NORMAL,
        'route.suspension_notice_parents' => self::PRIORITY_IMPORTANT,
        // Assignment
        'assignment.bulk_complete'        => self::PRIORITY_NORMAL,
        'assignment.rollover_complete'    => self::PRIORITY_NORMAL,
        // Driver
        'driver.replaced'                 => self::PRIORITY_IMPORTANT,
        'driver.critical_incident'        => self::PRIORITY_URGENT,
        'driver.licence_expiring'         => self::PRIORITY_IMPORTANT,
        // Expense
        'expense.post_failed'             => self::PRIORITY_IMPORTANT,
        'expense.pending_stale'           => self::PRIORITY_IMPORTANT,
        // SOS
        'sos.raised'                      => self::PRIORITY_URGENT,
        'sos.responded'                   => self::PRIORITY_IMPORTANT,
        'sos.resolved'                    => self::PRIORITY_NORMAL,
        // Trip
        'trip.delay'                      => self::PRIORITY_IMPORTANT,
        'trip.disruption'                 => self::PRIORITY_URGENT,
        // F7 (2026-07-07) — trip lifecycle + boarding
        'trip.started'                    => self::PRIORITY_NORMAL,
        'trip.completed'                  => self::PRIORITY_NORMAL,
        'trip.student_no_show'            => self::PRIORITY_IMPORTANT,
        // F8 (2026-07-07) — Transport Events
        'event.scheduled'                 => self::PRIORITY_IMPORTANT,
        'event.rescheduled'               => self::PRIORITY_IMPORTANT,
        'event.cancelled'                 => self::PRIORITY_URGENT,
        'event.trip_dispatched'           => self::PRIORITY_NORMAL,
        'event.consent_reminder'          => self::PRIORITY_NORMAL,
        'event.completed'                 => self::PRIORITY_NORMAL,
    ];

    /**
     * Category → audience scope. Determines fanout topology.
     */
    public const AUDIENCE_MATRIX = [
        'fleet.doc_expiring'              => self::SCOPE_OPS,
        'fleet.vehicle_replaced'          => self::SCOPE_OPS,
        'fleet.vehicle_retired'           => self::SCOPE_OPS,
        'route.suspended'                 => self::SCOPE_OPS,
        'route.resumed'                   => self::SCOPE_OPS,
        'route.suspension_notice_parents' => self::SCOPE_PARENTS_OF_ROUTE,
        'assignment.bulk_complete'        => self::SCOPE_OPS,
        'assignment.rollover_complete'    => self::SCOPE_OPS,
        'driver.replaced'                 => self::SCOPE_OPS,
        'driver.critical_incident'        => self::SCOPE_OPS,
        'driver.licence_expiring'         => self::SCOPE_OPS,
        'expense.post_failed'             => self::SCOPE_OPS,
        'expense.pending_stale'           => self::SCOPE_OPS,
        'sos.raised'                      => self::SCOPE_OPS,
        'sos.responded'                   => self::SCOPE_OPS,
        'sos.resolved'                    => self::SCOPE_OPS,
        'trip.delay'                      => self::SCOPE_PARENTS_OF_ROUTE,
        'trip.disruption'                 => self::SCOPE_PARENTS_OF_ROUTE,
        // F7 (2026-07-07)
        'trip.started'                    => self::SCOPE_OPS,
        'trip.completed'                  => self::SCOPE_OPS,
        'trip.student_no_show'            => self::SCOPE_PARENTS_OF_STUDENTS,
        // F8 (2026-07-07) — Transport Events
        'event.scheduled'                 => self::SCOPE_PARENTS_OF_STUDENTS,
        'event.rescheduled'               => self::SCOPE_PARENTS_OF_STUDENTS,
        'event.cancelled'                 => self::SCOPE_PARENTS_OF_STUDENTS,
        'event.trip_dispatched'           => self::SCOPE_OPS,
        'event.consent_reminder'          => self::SCOPE_PARENTS_OF_STUDENTS,
        'event.completed'                 => self::SCOPE_OPS,
    ];

    /**
     * Category → ops-role audience (used when scope=ops). Uses the same role
     * strings as the notices.targetRoles / write_event_notice contract; those
     * are matched by Communication_helper resolver + Parent app filter.
     */
    public const OPS_ROLE_MATRIX = [
        'fleet.doc_expiring'          => ['Admin', 'Transport Manager'],
        'fleet.vehicle_replaced'      => ['Admin', 'Transport Manager'],
        'fleet.vehicle_retired'       => ['Admin', 'Transport Manager'],
        'route.suspended'             => ['Admin', 'Transport Manager'],
        'route.resumed'               => ['Admin', 'Transport Manager'],
        'assignment.bulk_complete'    => ['Admin', 'Transport Manager'],
        'assignment.rollover_complete'=> ['Admin', 'Transport Manager'],
        'driver.replaced'             => ['Admin', 'Transport Manager', 'HR Manager'],
        'driver.critical_incident'    => ['Admin', 'Transport Manager', 'HR Manager'],
        'driver.licence_expiring'     => ['Admin', 'Transport Manager', 'HR Manager'],
        'expense.post_failed'         => ['Admin', 'Accountant', 'Transport Manager'],
        'expense.pending_stale'       => ['Admin', 'Accountant', 'Transport Manager'],
        'sos.raised'                  => ['Admin', 'Transport Manager', 'Principal'],
        'sos.responded'               => ['Admin', 'Transport Manager'],
        'sos.resolved'                => ['Admin', 'Transport Manager'],
        // F7 (2026-07-07) — trip lifecycle ops audiences
        'trip.started'                => ['Admin', 'Transport Manager'],
        'trip.completed'              => ['Admin', 'Transport Manager'],
        // F8 (2026-07-07) — Transport Events ops audiences
        'event.trip_dispatched'       => ['Admin', 'Transport Manager'],
        'event.completed'             => ['Admin', 'Transport Manager', 'Principal'],
    ];

    /**
     * Per-category dedup TTL bucket (seconds). Non-existent = never dedupe.
     * SOS + critical_incident are explicitly absent → never deduped.
     * (Refinement 2) — idempotency doc retention = 30 days regardless of
     * bucket length; bucket length only affects when the "same" event
     * fires again as a genuinely new emission.
     */
    public const DEDUP_TTL = [
        'fleet.doc_expiring'              => 86400,   // 24h
        'fleet.vehicle_replaced'          => 3600,    // 1h
        'fleet.vehicle_retired'           => 3600,
        'route.suspended'                 => 3600,
        'route.resumed'                   => 3600,
        'route.suspension_notice_parents' => 3600,
        'assignment.bulk_complete'        => 300,     // 5min
        'assignment.rollover_complete'    => 300,
        'driver.replaced'                 => 3600,
        'driver.licence_expiring'         => 86400,
        'expense.post_failed'             => 21600,   // 6h
        'expense.pending_stale'           => 21600,
        'sos.responded'                   => 60,      // 1min (avoid double-fire on double-click)
        'sos.resolved'                    => 60,
        'trip.delay'                      => 900,     // 15min
        'trip.disruption'                 => 900,
        // F7 (2026-07-07) — trip lifecycle + no-show
        'trip.started'                    => 300,     // 5min guard on double-start UI
        'trip.completed'                  => 300,     // 5min guard on double-complete UI
        // F8 (2026-07-07) — Transport Events
        'event.scheduled'                 => 3600,    // 1h — first Scheduled notice
        'event.trip_dispatched'           => 300,     // 5min guard on double-dispatch UI
        'event.consent_reminder'          => 86400,   // 24h — parent reminder cadence
        'event.completed'                 => 300,
        // trip.student_no_show intentionally OMITTED — never dedupe a
        // per-student no-show notification (Refinement 4 + safety
        // exemption: a repeat no-show for the same student on a fresh
        // trip must always reach the parent).
        // event.rescheduled + event.cancelled intentionally OMITTED —
        // safety-adjacent notices always inform parents (parallels
        // sos.raised + driver.critical_incident safety exemption).
        // sos.raised + driver.critical_incident intentionally OMITTED —
        // never dedupe safety events.
    ];

    /** Idempotency doc retention (30 days). */
    public const IDEMPOTENCY_RETENTION_SECS = 2592000;

    // Firestore collection constants (reuse existing Communication surface)
    private const COL_NOTICES               = 'notices';
    private const COL_NOTICE_RECIPIENTS     = 'noticeRecipients';
    private const COL_NOTIFICATIONS         = 'notifications';
    private const COL_SOS_ALERTS            = 'sosAlerts';
    private const COL_IDEMPOTENCY           = 'transportNoticeIdempotency';
    private const COL_STUDENT_ROUTES        = 'studentRoutes';
    private const COL_ROUTE_ASSIGNMENTS     = 'routeAssignments';

    // Firestore batch write ceiling (matches Firestore's hard limit)
    private const FIRESTORE_BATCH_LIMIT = 500;

    // ═════════════════════════════════════════════════════════════════════
    //  State
    // ═════════════════════════════════════════════════════════════════════

    /** @var object|null */
    private $fs = null;
    /** @var object|null */
    private $numbering = null;
    /** @var object|null */
    private $transport = null;   // Transport_service — audit emission
    /** @var object|null */
    private $ci = null;
    private string $schoolId    = '';
    private string $sessionYear = '';
    private string $adminId     = 'system';
    private bool   $ready       = false;

    public function __construct()
    {
        $this->ci = &get_instance();
    }

    /**
     * Initialise. Must be called BEFORE any notify() invocation.
     */
    public function init($fs, $numbering, $transport, string $schoolId, string $sessionYear, string $adminId = 'system'): self
    {
        $this->fs          = $fs;
        $this->numbering   = $numbering;
        $this->transport   = $transport;
        $this->schoolId    = $schoolId;
        $this->sessionYear = $sessionYear;
        $this->adminId     = $adminId !== '' ? $adminId : 'system';
        $this->ready       = ($fs !== null && $numbering !== null && $transport !== null
                              && $schoolId !== '' && $sessionYear !== '');
        return $this;
    }

    public function isEnabled(): bool { return $this->ready; }

    // ═════════════════════════════════════════════════════════════════════
    //  Public API — single entry point
    // ═════════════════════════════════════════════════════════════════════

    /**
     * Fire a notification for the given category with the given context.
     *
     * @param string $category one of self::CATEGORIES
     * @param array  $ctx      category-specific fields
     * @param array  $overrides optional overrides:
     *                          - 'title' / 'body' — replace defaults
     *                          - 'priority' — override matrix (rare)
     *                          - 'firedBy' / 'firedByRole' — audit trail
     *                          - 'context' — extra context fields
     *
     * @return array{
     *   emitted:bool,
     *   notice_id:string,
     *   per_user_count:int,
     *   dedupe_skipped:bool,
     *   category:string,
     *   priority:string,
     *   audience_scope:string
     * }
     */
    public function notify(string $category, array $ctx, array $overrides = []): array
    {
        $this->_assertReady();

        if (!in_array($category, self::CATEGORIES, true)) {
            throw new \LogicException("Transport_notifier: unknown category '{$category}'.");
        }
        $priority = (string) ($overrides['priority'] ?? (self::PRIORITY_MATRIX[$category] ?? self::PRIORITY_NORMAL));
        $scope    = (string) (self::AUDIENCE_MATRIX[$category] ?? self::SCOPE_OPS);
        $firedBy  = (string) ($overrides['firedBy']     ?? $this->adminId);
        $firedRol = (string) ($overrides['firedByRole'] ?? 'system');

        // Idempotency pre-check (unless category is never-deduped, e.g. SOS raised).
        $ttl = (int) (self::DEDUP_TTL[$category] ?? 0);
        $idemKey = $this->_computeIdempotencyKey($category, $ctx, $ttl);
        if ($ttl > 0 && $this->_isIdempotencyClaimed($idemKey)) {
            return [
                'emitted'        => false,
                'notice_id'      => '',
                'per_user_count' => 0,
                'dedupe_skipped' => true,
                'category'       => $category,
                'priority'       => $priority,
                'audience_scope' => $scope,
            ];
        }

        // Render title + body (per-category defaults; overridable).
        [$title, $body] = $this->_renderTitleBody($category, $ctx);
        if (isset($overrides['title']) && $overrides['title'] !== '') { $title = (string) $overrides['title']; }
        if (isset($overrides['body'])  && $overrides['body']  !== '') { $body  = (string) $overrides['body']; }
        $mergedContext = array_merge($ctx, (array) ($overrides['context'] ?? []));

        // Dispatch by scope.
        switch ($scope) {
            case self::SCOPE_OPS:
            case self::SCOPE_STAFF_ADMIN_ONLY:
                $result = $this->_fanoutOps($category, $priority, $title, $body, $mergedContext, $firedBy, $firedRol);
                break;
            case self::SCOPE_PARENTS_OF_ROUTE:
                $result = $this->_fanoutParentsOfRoute($category, $priority, $title, $body, $mergedContext, $firedBy, $firedRol);
                break;
            case self::SCOPE_PARENTS_OF_VEHICLE:
                $result = $this->_fanoutParentsOfVehicle($category, $priority, $title, $body, $mergedContext, $firedBy, $firedRol);
                break;
            case self::SCOPE_PARENTS_OF_STUDENTS:
                $result = $this->_fanoutParentsOfStudents($category, $priority, $title, $body, $mergedContext, $firedBy, $firedRol);
                break;
            default:
                throw new \LogicException("Transport_notifier: unknown scope '{$scope}'.");
        }

        // Claim idempotency AFTER successful emission (so a failed fanout can
        // be retried by the caller without being blocked by a stale claim).
        if ($ttl > 0 && $result['emitted']) {
            $this->_claimIdempotency($idemKey, $category, $mergedContext);
        }

        // Audit — every fanout emits ONE audit row (per F6-5 assertion).
        try {
            $this->transport->emitAudit(
                'transportNotification',
                $result['notice_id'] !== '' ? $result['notice_id'] : $category,
                'emitted',
                null,
                [
                    'category'       => $category,
                    'priority'       => $priority,
                    'scope'          => $scope,
                    'per_user_count' => $result['per_user_count'],
                    'idempotency_key'=> $idemKey,
                ],
                ['domain' => 'notification', 'source' => 'transport_notifier']
            );
        } catch (\Throwable $e) {
            log_message('warning', 'Transport_notifier audit emit failed: ' . $e->getMessage());
        }

        return array_merge($result, [
            'category'       => $category,
            'priority'       => $priority,
            'audience_scope' => $scope,
        ]);
    }

    // ═════════════════════════════════════════════════════════════════════
    //  SOS write-through (LC-13)
    // ═════════════════════════════════════════════════════════════════════

    /**
     * Persist a new SOS alert doc and fire the URGENT cascade. Dual-writes
     * both `active:bool` (Parent app repo filter uses this) and
     * `status:string` ('active'|'responded'|'resolved') to match the
     * shipped SosAlertDoc.kt model on both apps without needing a mobile
     * release.
     *
     * @return array{sos_id:string, notify:array<string,mixed>}
     */
    public function raiseSos(array $payload): array
    {
        $this->_assertReady();
        $triggeredBy     = (string) ($payload['triggered_by']      ?? $this->adminId);
        $triggeredByRole = (string) ($payload['triggered_by_role'] ?? 'admin');
        $vehicleId       = (string) ($payload['vehicle_id']        ?? '');
        $routeId         = (string) ($payload['route_id']          ?? '');
        $lat             = (float)  ($payload['lat']               ?? 0);
        $lng             = (float)  ($payload['lng']               ?? 0);
        $alertType       = (string) ($payload['alert_type']        ?? 'emergency');
        // F9 (2026-07-07) — severity picker (Refinement 1: default High).
        // Client uses the same enum on Kotlin side; rules enforce membership.
        $severity        = (string) ($payload['severity']          ?? self::SOS_DEFAULT_SEVERITY);
        $message         = trim((string) ($payload['message']      ?? ''));

        if ($message === '') {
            throw new \InvalidArgumentException('SOS message is required.');
        }
        if (!in_array($alertType, ['emergency', 'breakdown', 'accident', 'medical', 'other'], true)) {
            throw new \InvalidArgumentException("Invalid SOS alert_type: {$alertType}");
        }
        if (!in_array($severity, self::SOS_SEVERITIES, true)) {
            throw new \InvalidArgumentException("Invalid SOS severity: {$severity}. Allowed: " . implode(',', self::SOS_SEVERITIES));
        }

        $sosId = $this->numbering->next('trn_sos', [
            'claimedBy'  => $this->adminId,
            'claimedFor' => 'F6:raiseSos',
        ]);

        $now = date('c');
        $doc = [
            'id'                => $sosId,
            'schoolId'          => $this->schoolId,
            'triggeredBy'       => $triggeredBy,
            'triggeredByRole'   => $triggeredByRole,
            'vehicleId'         => $vehicleId,
            'routeId'           => $routeId,
            'lat'               => $lat,
            'lng'               => $lng,
            'alertType'         => $alertType,
            // F9 (2026-07-07) — severity: shipped-client compat kept
            // (adds field to SosAlertDoc.kt in the same F9 release).
            'severity'          => $severity,
            'message'           => $message,
            // Dual-write for shipped-client compat:
            'status'            => 'active',
            'active'            => true,
            'respondedBy'       => '',
            'respondedByRole'   => '',
            'respondedAt'       => '',
            'responseNotes'     => '',
            'resolvedBy'        => '',
            'resolvedAt'        => '',
            'resolutionNotes'   => '',
            // Kotlin SosAlertDoc.notifiedParents is declared as Int — write
            // an integer COUNT (Parent+Teacher shipped-client contract).
            // The resolved userId list is kept in a separate field
            // (notifiedParentIds) for admin-panel audit without breaking
            // the mobile deserialization.
            'notifiedParents'   => 0,
            'notifiedParentIds' => [],
            'createdAt'         => $now,
            'updatedAt'         => $now,
            // Architecture-open for future auto-escalation (Refinement 1):
            // consumers can layer a scheduled job that reads unresponded
            // rows and fires sos.raised again with a higher-priority
            // audience — no schema change required.
            'escalationPolicy'  => 'manual',
        ];
        $this->fs->setEntity(self::COL_SOS_ALERTS, $sosId, $doc, /* merge */ false);

        // Fire the cascade. F9 (2026-07-07) — severity drives the notifier
        // priority via SOS_SEVERITY_TO_PRIORITY. High + Critical stay
        // Urgent (safety exemption in DEDUP_TTL preserved — sos.raised is
        // absent from the dedup table so it's ALWAYS emitted). Low/Medium
        // downshift to Normal/Important without changing dedup semantics.
        $priorityOverride = self::SOS_SEVERITY_TO_PRIORITY[$severity] ?? self::PRIORITY_URGENT;
        $notifyResult = $this->notify('sos.raised', [
            'sos_id'      => $sosId,
            'vehicle_id'  => $vehicleId,
            'route_id'    => $routeId,
            'alert_type'  => $alertType,
            'severity'    => $severity,
            'message'     => $message,
            'triggered_by'=> $triggeredBy,
            'lat'         => $lat,
            'lng'         => $lng,
        ], [
            'firedBy'     => $triggeredBy,
            'firedByRole' => $triggeredByRole,
            'priority'    => $priorityOverride,
        ]);

        // If parents_of_vehicle applied, mirror the resolved user IDs into
        // the SOS doc for downstream audit/UI. Preserve Kotlin Int contract
        // on notifiedParents (count) and store the list in notifiedParentIds.
        if (($notifyResult['per_user_count'] ?? 0) > 0) {
            $userIds = (array) ($notifyResult['notified_user_ids'] ?? []);
            $this->fs->setEntity(self::COL_SOS_ALERTS, $sosId, [
                'notifiedParents'   => count($userIds),
                'notifiedParentIds' => array_values(array_map('strval', $userIds)),
                'updatedAt'         => date('c'),
            ], /* merge */ true);
        }

        return ['sos_id' => $sosId, 'notify' => $notifyResult];
    }

    /**
     * Mark an active SOS as responded. Emits sos.responded to ops audience.
     */
    public function respondSos(string $sosId, string $notes): array
    {
        $this->_assertReady();
        if ($sosId === '') { throw new \InvalidArgumentException('sosId required.'); }
        $existing = $this->fs->getEntity(self::COL_SOS_ALERTS, $sosId);
        if (!is_array($existing)) { throw new \InvalidArgumentException("SOS not found: {$sosId}"); }
        if (($existing['status'] ?? '') !== 'active') {
            throw new \InvalidArgumentException("SOS not in Active state (status='" . ($existing['status'] ?? '') . "').");
        }
        $now = date('c');
        $update = [
            'status'          => 'responded',
            'active'          => true,        // still active until resolved
            'respondedBy'     => $this->adminId,
            'respondedByRole' => 'admin',
            'respondedAt'     => $now,
            'responseNotes'   => $notes,
            'updatedAt'       => $now,
        ];
        $this->fs->setEntity(self::COL_SOS_ALERTS, $sosId, $update, /* merge */ true);

        $notify = $this->notify('sos.responded', [
            'sos_id'     => $sosId,
            'vehicle_id' => (string) ($existing['vehicleId'] ?? ''),
            'route_id'   => (string) ($existing['routeId']   ?? ''),
            'responder'  => $this->adminId,
            'notes'      => $notes,
        ]);
        return ['sos_id' => $sosId, 'notify' => $notify];
    }

    /**
     * Mark a responded SOS as resolved. Emits sos.resolved to ops audience.
     */
    public function resolveSos(string $sosId, string $notes): array
    {
        $this->_assertReady();
        if ($sosId === '') { throw new \InvalidArgumentException('sosId required.'); }
        $existing = $this->fs->getEntity(self::COL_SOS_ALERTS, $sosId);
        if (!is_array($existing)) { throw new \InvalidArgumentException("SOS not found: {$sosId}"); }
        $curStatus = (string) ($existing['status'] ?? '');
        if (!in_array($curStatus, ['active', 'responded'], true)) {
            throw new \InvalidArgumentException("SOS not resolvable from status='{$curStatus}'.");
        }
        $now = date('c');
        $update = [
            'status'         => 'resolved',
            'active'         => false,
            'resolvedBy'     => $this->adminId,
            'resolvedAt'     => $now,
            'resolutionNotes'=> $notes,
            'updatedAt'      => $now,
        ];
        $this->fs->setEntity(self::COL_SOS_ALERTS, $sosId, $update, /* merge */ true);

        $notify = $this->notify('sos.resolved', [
            'sos_id'     => $sosId,
            'vehicle_id' => (string) ($existing['vehicleId'] ?? ''),
            'route_id'   => (string) ($existing['routeId']   ?? ''),
            'resolver'   => $this->adminId,
            'notes'      => $notes,
        ]);
        return ['sos_id' => $sosId, 'notify' => $notify];
    }

    /** List active + recent SOS alerts. */
    public function listSos(int $limit = 50, ?string $statusFilter = null): array
    {
        $this->_assertReady();
        $extra = [];
        if ($statusFilter !== null && $statusFilter !== '') {
            $extra[] = ['status', '==', $statusFilter];
        }
        try {
            $rows = (array) $this->fs->schoolWhere(self::COL_SOS_ALERTS, $extra, 'createdAt', 'DESC', $limit);
        } catch (\Throwable $_) { return []; }
        $out = [];
        foreach ($rows as $r) {
            // schoolWhere may return {id, data:{...}} nested; unwrap.
            $inner = (isset($r['data']) && is_array($r['data'])) ? $r['data'] : $r;
            if (isset($r['id']) && !isset($inner['id'])) { $inner['id'] = $r['id']; }
            $out[] = $inner;
        }
        return $out;
    }

    // ═════════════════════════════════════════════════════════════════════
    //  Fanout implementations
    // ═════════════════════════════════════════════════════════════════════

    /**
     * ops / staff_admin_only fanout — single notices doc + N noticeRecipients
     * delivery rows (matches NoticeAnnouncement::create_notice pattern the
     * Parent/Teacher notice-bell already reads).
     */
    private function _fanoutOps(
        string $category, string $priority, string $title, string $body,
        array $ctx, string $firedBy, string $firedByRole
    ): array {
        $roles = (array) (self::OPS_ROLE_MATRIX[$category] ?? ['Admin']);
        $noticeId = 'TNOT_' . (int) round(microtime(true) * 1000) . '_' . substr(sha1($category . implode('|', $roles) . microtime(true)), 0, 6);
        $docId = "{$this->schoolId}_{$noticeId}";
        $now = date('c');

        $notice = [
            'noticeId'      => $noticeId,
            'schoolId'      => $this->schoolId,
            'title'         => $title,
            'body'          => $body,
            'description'   => $body,
            'author'        => 'Transport (System)',
            'authorId'      => $firedBy,
            'authorRole'    => $firedByRole,
            'category'      => 'Transport',
            'priority'      => $priority,
            'targetType'    => 'role',
            'targetClasses' => [],
            'targetRoles'   => $roles,
            'status'        => 'sent',
            'sentAt'        => $now,
            'createdAt'     => $now,
            // F6 extension fields (additive; ignored by existing readers):
            'module'        => 'Transport',
            'trnCategory'   => $category,
            'trnContext'    => $ctx,
        ];
        $this->fs->setEntity(self::COL_NOTICES, $docId, $notice, /* merge */ false);

        // Per-role delivery rows (noticeRecipients pattern). One row per role
        // (not per user) — role expansion happens at the notice-bell reader.
        $perUser = 0;
        foreach ($roles as $role) {
            $key = strtolower(preg_replace('/\s+/', '_', $role));
            $rcpId = "{$this->schoolId}_{$noticeId}_role_{$key}";
            try {
                $this->fs->setEntity(self::COL_NOTICE_RECIPIENTS, $rcpId, [
                    'schoolId'      => $this->schoolId,
                    'noticeId'      => $noticeId,
                    'recipientType' => 'role',
                    'recipientKey'  => $role,
                    'status'        => 'sent',
                    'deliveredAt'   => $now,
                ], /* merge */ false);
                $perUser++;
            } catch (\Throwable $e) {
                log_message('warning', "Transport_notifier ops delivery row failed for role={$role}: " . $e->getMessage());
            }
        }
        return [
            'emitted'          => true,
            'notice_id'        => $noticeId,
            'per_user_count'   => $perUser,
            'dedupe_skipped'   => false,
            'notified_user_ids'=> [],
        ];
    }

    /**
     * parents_of_route fanout — resolve to parent user IDs via studentRoutes
     * (+ fallback routeAssignments for the newer schema), write N notifications
     * docs (Attendance pattern; type='transport' reserved on Parent+Teacher
     * apps), chunk into Firestore batch limits.
     */
    private function _fanoutParentsOfRoute(
        string $category, string $priority, string $title, string $body,
        array $ctx, string $firedBy, string $firedByRole
    ): array {
        $routeId = trim((string) ($ctx['route_id'] ?? ''));
        if ($routeId === '') {
            log_message('warning', "Transport_notifier: parents_of_route fanout for {$category} missing route_id.");
            return ['emitted' => false, 'notice_id' => '', 'per_user_count' => 0, 'dedupe_skipped' => false, 'notified_user_ids' => []];
        }
        $userIds = $this->_resolveParentsOfRoute($routeId);
        return $this->_fanoutNotifications($category, $priority, $title, $body, $ctx, $userIds, $firedBy, $firedByRole);
    }

    /**
     * parents_of_vehicle fanout — aggregate all active routes on the vehicle
     * (vehicles today have 1 permanent route, but the code path stays
     * multi-route safe), then reuse parents_of_route resolution.
     */
    private function _fanoutParentsOfVehicle(
        string $category, string $priority, string $title, string $body,
        array $ctx, string $firedBy, string $firedByRole
    ): array {
        $vehicleId = trim((string) ($ctx['vehicle_id'] ?? ''));
        if ($vehicleId === '') {
            return ['emitted' => false, 'notice_id' => '', 'per_user_count' => 0, 'dedupe_skipped' => false, 'notified_user_ids' => []];
        }
        try {
            $vehicle = $this->fs->getEntity('vehicles', $vehicleId);
        } catch (\Throwable $_) { $vehicle = null; }
        $routeId = is_array($vehicle) ? (string) ($vehicle['route_id'] ?? $vehicle['routeId'] ?? '') : '';
        if ($routeId === '') {
            return ['emitted' => false, 'notice_id' => '', 'per_user_count' => 0, 'dedupe_skipped' => false, 'notified_user_ids' => []];
        }
        $userIds = $this->_resolveParentsOfRoute($routeId);
        $ctx['route_id'] = $routeId;
        return $this->_fanoutNotifications($category, $priority, $title, $body, $ctx, $userIds, $firedBy, $firedByRole);
    }

    /**
     * parents_of_students fanout (F7) — accept an explicit student_ids
     * list, resolve each to parent userId, then reuse the shared
     * per-user notifications-collection fanout path.
     */
    private function _fanoutParentsOfStudents(
        string $category, string $priority, string $title, string $body,
        array $ctx, string $firedBy, string $firedByRole
    ): array {
        $studentIds = (array) ($ctx['student_ids'] ?? []);
        if ($studentIds === [] && !empty($ctx['student_id'])) {
            $studentIds = [(string) $ctx['student_id']];
        }
        $studentIds = array_values(array_unique(array_filter(array_map('strval', $studentIds))));
        if ($studentIds === []) {
            return ['emitted' => false, 'notice_id' => '', 'per_user_count' => 0, 'dedupe_skipped' => false, 'notified_user_ids' => []];
        }
        $userIds = [];
        foreach ($studentIds as $sid) {
            try {
                $stu = $this->fs->getEntity('students', $sid);
                if (!is_array($stu)) { continue; }
                $pid = (string) ($stu['parent_db_key'] ?? $stu['parentId'] ?? $stu['parent_id'] ?? '');
                if ($pid !== '') { $userIds[$pid] = true; }
            } catch (\Throwable $_) {}
        }
        return $this->_fanoutNotifications($category, $priority, $title, $body, $ctx, array_keys($userIds), $firedBy, $firedByRole);
    }

    /**
     * Write per-parent notifications docs in chunks (Firestore 500-write
     * limit per batch). Also fires per-user push best-effort via
     * Push_service::sendToUser if available.
     */
    private function _fanoutNotifications(
        string $category, string $priority, string $title, string $body,
        array $ctx, array $userIds, string $firedBy, string $firedByRole
    ): array {
        $userIds = array_values(array_unique(array_filter(array_map('strval', $userIds))));
        if ($userIds === []) {
            return ['emitted' => false, 'notice_id' => '', 'per_user_count' => 0, 'dedupe_skipped' => false, 'notified_user_ids' => []];
        }
        $noticeId = 'TNOT_' . (int) round(microtime(true) * 1000) . '_' . substr(sha1($category . implode('|', $userIds) . microtime(true)), 0, 6);
        $now = date('c');

        // Convert ctx to Map<String,String> shape that Parent+Teacher app
        // NotificationDoc.data field expects.
        $dataMap = [];
        foreach ($ctx as $k => $v) {
            if (is_scalar($v) || $v === null) {
                $dataMap[(string) $k] = (string) ($v ?? '');
            } elseif (is_array($v)) {
                $dataMap[(string) $k] = json_encode($v);
            }
        }
        $dataMap['module']      = 'Transport';
        $dataMap['category']    = $category;
        $dataMap['trnPriority'] = $priority;
        $dataMap['groupId']     = $noticeId;

        $writeCount = 0;
        $push = $this->_loadPushService();

        // Chunk writes (Firestore batch limit safety).
        foreach (array_chunk($userIds, self::FIRESTORE_BATCH_LIMIT) as $chunk) {
            foreach ($chunk as $uid) {
                $docId = "{$this->schoolId}_{$noticeId}_{$uid}";
                try {
                    $this->fs->setEntity(self::COL_NOTIFICATIONS, $docId, [
                        'schoolId'  => $this->schoolId,
                        'userId'    => $uid,
                        'type'      => 'transport',
                        'title'     => $title,
                        'body'      => $body,
                        'priority'  => $priority,
                        'data'      => $dataMap,
                        'read'      => false,
                        'createdAt' => $now,
                        'expiresAt' => date('c', time() + 30 * 86400),
                    ], /* merge */ false);
                    $writeCount++;
                    if (is_object($push) && method_exists($push, 'sendToUser')) {
                        try {
                            $push->sendToUser($uid, [
                                'title'    => $title,
                                'body'     => $body,
                                'type'     => 'transport',
                                'priority' => $priority,
                                'data'     => $dataMap,
                            ]);
                        } catch (\Throwable $pe) {
                            log_message('warning', "Transport_notifier push to {$uid} failed: " . $pe->getMessage());
                        }
                    }
                } catch (\Throwable $e) {
                    log_message('warning', "Transport_notifier notifications write for {$uid} failed: " . $e->getMessage());
                }
            }
        }
        return [
            'emitted'          => $writeCount > 0,
            'notice_id'        => $noticeId,
            'per_user_count'   => $writeCount,
            'dedupe_skipped'   => false,
            'notified_user_ids'=> $userIds,
        ];
    }

    // ═════════════════════════════════════════════════════════════════════
    //  Audience resolvers
    // ═════════════════════════════════════════════════════════════════════

    /**
     * Resolve parent user IDs subscribed to a route. Reads BOTH schemas —
     * legacy studentRoutes and new routeAssignments — to cover the rollover
     * transition window without missing anyone. Deduped on parent id.
     */
    private function _resolveParentsOfRoute(string $routeId): array
    {
        $studentIds = [];
        try {
            $rows = (array) $this->fs->schoolWhere(self::COL_ROUTE_ASSIGNMENTS,
                [['route_id', '==', $routeId], ['status', '==', 'Active']], null, 'ASC', self::FIRESTORE_BATCH_LIMIT);
            foreach ($rows as $r) {
                $inner = (isset($r['data']) && is_array($r['data'])) ? $r['data'] : $r;
                $sid = (string) ($inner['studentId'] ?? $inner['student_id'] ?? '');
                if ($sid !== '') { $studentIds[$sid] = true; }
            }
        } catch (\Throwable $_) { /* new schema empty — fall through */ }

        try {
            $rows = (array) $this->fs->schoolWhere(self::COL_STUDENT_ROUTES,
                [['route_id', '==', $routeId], ['session', '==', $this->sessionYear]], null, 'ASC', self::FIRESTORE_BATCH_LIMIT);
            foreach ($rows as $r) {
                $inner = (isset($r['data']) && is_array($r['data'])) ? $r['data'] : $r;
                $sid = (string) ($inner['studentId'] ?? $inner['student_id'] ?? '');
                if ($sid !== '') { $studentIds[$sid] = true; }
            }
        } catch (\Throwable $_) {}

        if ($studentIds === []) { return []; }

        // Resolve parent user IDs via student docs.
        $userIds = [];
        foreach (array_keys($studentIds) as $sid) {
            try {
                $stu = $this->fs->getEntity('students', $sid);
                if (!is_array($stu)) { continue; }
                // parent_db_key is the historical parent auth id; parentId
                // is the newer field. Accept either.
                $pid = (string) ($stu['parent_db_key'] ?? $stu['parentId'] ?? $stu['parent_id'] ?? '');
                if ($pid !== '') { $userIds[$pid] = true; }
            } catch (\Throwable $_) {}
        }
        return array_keys($userIds);
    }

    // ═════════════════════════════════════════════════════════════════════
    //  Idempotency
    // ═════════════════════════════════════════════════════════════════════

    /**
     * Compute a deterministic idempotency key from category + salient
     * context fields + TTL bucket start (so a "same" event that recurs
     * in a fresh window fires again).
     */
    private function _computeIdempotencyKey(string $category, array $ctx, int $ttlSecs): string
    {
        // Bucket start = floor(now / ttl) * ttl — same window → same key.
        $bucketStart = $ttlSecs > 0 ? (int) floor(time() / $ttlSecs) * $ttlSecs : 0;
        // Salient fields per category (subset that identifies the event).
        $salient = [
            'category' => $category,
            'route'    => (string) ($ctx['route_id']       ?? ''),
            'vehicle'  => (string) ($ctx['vehicle_id']     ?? ''),
            'staff'    => (string) ($ctx['staff_id']       ?? ''),
            'expense'  => (string) ($ctx['expense_id']     ?? ''),
            'incident' => (string) ($ctx['incident_id']    ?? ''),
            'sos'      => (string) ($ctx['sos_id']         ?? ''),
            'batch'    => (string) ($ctx['batch_id']       ?? ''),
            'bucket'   => $bucketStart,
        ];
        return substr(sha1($this->schoolId . '|' . json_encode($salient)), 0, 40);
    }

    private function _isIdempotencyClaimed(string $key): bool
    {
        try {
            $existing = $this->fs->getEntity(self::COL_IDEMPOTENCY, "{$this->schoolId}_{$key}");
        } catch (\Throwable $_) { return false; }
        if (!is_array($existing)) { return false; }
        $exp = (string) ($existing['expiresAt'] ?? '');
        if ($exp === '') { return true; }
        $expTs = strtotime($exp);
        return ($expTs !== false && $expTs > time());
    }

    private function _claimIdempotency(string $key, string $category, array $ctx): void
    {
        $now = time();
        $doc = [
            'schoolId'    => $this->schoolId,
            'category'    => $category,
            'claimedAt'   => date('c', $now),
            'expiresAt'   => date('c', $now + self::IDEMPOTENCY_RETENTION_SECS),
            'contextHash' => substr(sha1(json_encode($ctx)), 0, 16),
        ];
        try {
            $this->fs->setEntity(self::COL_IDEMPOTENCY, "{$this->schoolId}_{$key}", $doc, /* merge */ false);
        } catch (\Throwable $e) {
            log_message('warning', "Transport_notifier idempotency claim failed: " . $e->getMessage());
        }
    }

    // ═════════════════════════════════════════════════════════════════════
    //  Title/body rendering per category
    // ═════════════════════════════════════════════════════════════════════

    private function _renderTitleBody(string $category, array $ctx): array
    {
        switch ($category) {
            case 'fleet.doc_expiring':
                $label  = (string) ($ctx['label']       ?? 'Document');
                $number = (string) ($ctx['number']      ?? '');
                $days   = (int)    ($ctx['days_left']   ?? 0);
                $exp    = (string) ($ctx['expiry_date'] ?? '');
                return [
                    "Transport: {$label} expiring in {$days}d for vehicle {$number}",
                    "Vehicle: {$number}\nItem: {$label}\nExpires: {$exp} ({$days} days from today)\nAction: renew before expiry.",
                ];
            case 'fleet.vehicle_replaced':
                return [
                    "Transport: Vehicle replaced — " . (string) ($ctx['vehicle_id'] ?? ''),
                    "Vehicle: " . (string) ($ctx['vehicle_id'] ?? '') . "\nReason: " . (string) ($ctx['reason'] ?? ''),
                ];
            case 'fleet.vehicle_retired':
                return [
                    "Transport: Vehicle retired — " . (string) ($ctx['vehicle_id'] ?? ''),
                    "Vehicle: " . (string) ($ctx['vehicle_id'] ?? '') . "\nRetired: " . date('Y-m-d'),
                ];
            case 'route.suspended':
                return [
                    "Transport route suspended: " . (string) ($ctx['route_name'] ?? ''),
                    $this->_suspensionBody($ctx, 'suspended'),
                ];
            case 'route.resumed':
                return [
                    "Transport route resumed: " . (string) ($ctx['route_name'] ?? ''),
                    $this->_suspensionBody($ctx, 'resumed'),
                ];
            case 'route.suspension_notice_parents':
                return [
                    "Bus service update: " . (string) ($ctx['route_name'] ?? ''),
                    "Your child's bus route " . (string) ($ctx['route_name'] ?? '') . " is currently "
                    . ((string) ($ctx['action'] ?? 'affected')) . ".\nReason: " . (string) ($ctx['reason'] ?? '')
                    . "\nFrom: " . (string) ($ctx['from_date'] ?? '')
                    . (($ctx['to_date'] ?? '') !== '' ? "\nTo: " . $ctx['to_date'] : ''),
                ];
            case 'assignment.bulk_complete':
                return [
                    "Transport bulk assign complete: " . (int) ($ctx['assigned'] ?? 0) . " assigned",
                    "Batch " . (string) ($ctx['batch_id'] ?? '') . "\n"
                    . "Assigned: " . (int) ($ctx['assigned'] ?? 0) . "\n"
                    . "Capacity-skipped: " . (int) ($ctx['skipped_capacity'] ?? 0) . "\n"
                    . "Errors: " . (int) ($ctx['errors'] ?? 0),
                ];
            case 'assignment.rollover_complete':
                return [
                    "Transport session rollover complete: " . (int) ($ctx['processed'] ?? 0) . " students",
                    "From: " . (string) ($ctx['from_session'] ?? '')
                    . "\nTo: " . (string) ($ctx['to_session'] ?? '')
                    . "\nBatch: " . (string) ($ctx['batch_id'] ?? '')
                    . "\nProcessed: " . (int) ($ctx['processed'] ?? 0),
                ];
            case 'driver.replaced':
                return [
                    "Transport driver replaced on " . (string) ($ctx['vehicle_id'] ?? ''),
                    "Vehicle: " . (string) ($ctx['vehicle_id'] ?? '') . "\n"
                    . "Old driver: " . (string) ($ctx['old_staff_id'] ?? '') . "\n"
                    . "New driver: " . (string) ($ctx['new_staff_id'] ?? '') . "\n"
                    . "Reason: " . (string) ($ctx['reason'] ?? ''),
                ];
            case 'driver.critical_incident':
                return [
                    "Transport CRITICAL: " . (string) ($ctx['incident_type'] ?? 'incident')
                    . " — " . (string) ($ctx['staff_name'] ?? $ctx['staff_id'] ?? ''),
                    "Driver: " . (string) ($ctx['staff_name'] ?? $ctx['staff_id'] ?? '')
                    . "\nVehicle: " . (string) ($ctx['vehicle_id'] ?? '-')
                    . "\nType: "    . (string) ($ctx['incident_type'] ?? '')
                    . "\nDetails: " . (string) ($ctx['description'] ?? ''),
                ];
            case 'driver.licence_expiring':
                return [
                    "Transport: Driver licence expiring — " . (string) ($ctx['staff_name'] ?? $ctx['staff_id'] ?? ''),
                    "Driver: " . (string) ($ctx['staff_name'] ?? $ctx['staff_id'] ?? '')
                    . "\nExpires: " . (string) ($ctx['licence_expires'] ?? '')
                    . "\nDays left: " . (int) ($ctx['days_left'] ?? 0),
                ];
            case 'expense.post_failed':
            case 'expense.pending_stale':
                return [
                    "Transport expense FAILED to post to Accounting: " . (string) ($ctx['expense_id'] ?? ''),
                    "Expense: " . (string) ($ctx['expense_id'] ?? '')
                    . "\nType: "  . (string) ($ctx['expense_type'] ?? '')
                    . "\nAmount: INR " . (float) ($ctx['total_amount'] ?? 0)
                    . "\nLast error: " . (string) ($ctx['accounting_last_error'] ?? '')
                    . "\n\nAction: review and manually reconcile via Transport Ops -> Reconcile Pending Posts.",
                ];
            case 'sos.raised':
                return [
                    "🚨 Transport SOS raised — " . (string) ($ctx['alert_type'] ?? 'emergency'),
                    "SOS ID: " . (string) ($ctx['sos_id'] ?? '')
                    . "\nVehicle: " . (string) ($ctx['vehicle_id'] ?? '-')
                    . "\nRoute: "   . (string) ($ctx['route_id'] ?? '-')
                    . "\nType: "    . (string) ($ctx['alert_type'] ?? '')
                    . "\nMessage: " . (string) ($ctx['message'] ?? '')
                    . "\nLocation: " . (float) ($ctx['lat'] ?? 0) . ", " . (float) ($ctx['lng'] ?? 0),
                ];
            case 'sos.responded':
                return [
                    "Transport SOS responded — " . (string) ($ctx['sos_id'] ?? ''),
                    "SOS: " . (string) ($ctx['sos_id'] ?? '')
                    . "\nResponder: " . (string) ($ctx['responder'] ?? '')
                    . "\nNotes: "    . (string) ($ctx['notes'] ?? ''),
                ];
            case 'sos.resolved':
                return [
                    "Transport SOS resolved — " . (string) ($ctx['sos_id'] ?? ''),
                    "SOS: " . (string) ($ctx['sos_id'] ?? '')
                    . "\nResolver: " . (string) ($ctx['resolver'] ?? '')
                    . "\nNotes: "    . (string) ($ctx['notes'] ?? ''),
                ];
            case 'trip.delay':
                return [
                    "Bus running late — " . (string) ($ctx['route_name'] ?? $ctx['route_id'] ?? ''),
                    "Route: "  . (string) ($ctx['route_name'] ?? $ctx['route_id'] ?? '')
                    . "\nDelay: " . (int) ($ctx['delay_minutes'] ?? 0) . " minutes"
                    . "\nReason: " . (string) ($ctx['reason'] ?? 'traffic'),
                ];
            case 'trip.disruption':
                return [
                    "Bus service disruption — " . (string) ($ctx['route_name'] ?? $ctx['route_id'] ?? ''),
                    "Route: "  . (string) ($ctx['route_name'] ?? $ctx['route_id'] ?? '')
                    . "\nReason: " . (string) ($ctx['reason'] ?? '')
                    . "\nAction: " . (string) ($ctx['action_taken'] ?? 'Alternate arrangements being made.'),
                ];
            case 'trip.started':
                return [
                    "Transport trip started — " . (string) ($ctx['trip_id'] ?? ''),
                    "Trip: "     . (string) ($ctx['trip_id'] ?? '')
                    . "\nRoute: "  . (string) ($ctx['route_id'] ?? '')
                    . "\nVehicle: ". (string) ($ctx['vehicle_id'] ?? '')
                    . "\nDriver: " . (string) ($ctx['driver_id'] ?? '')
                    . "\nDirection: " . (string) ($ctx['direction'] ?? '')
                    . "\nDate: "   . (string) ($ctx['trip_date'] ?? '')
                    . "\nRoster: " . (int)    ($ctx['roster_size'] ?? 0) . ' students',
                ];
            case 'trip.completed':
                return [
                    "Transport trip completed — " . (string) ($ctx['trip_id'] ?? ''),
                    "Trip: "        . (string) ($ctx['trip_id'] ?? '')
                    . "\nRoute: "     . (string) ($ctx['route_id'] ?? '')
                    . "\nDriver: "    . (string) ($ctx['driver_id'] ?? '')
                    . "\nDate: "      . (string) ($ctx['trip_date'] ?? '')
                    . "\nDuration: "  . (int) ($ctx['duration_minutes'] ?? 0) . ' min'
                    . "\nDistance: "  . (float) ($ctx['distance_km'] ?? 0) . ' km'
                    . "\nOn-time: "   . ((bool) ($ctx['on_time'] ?? false) ? 'yes' : 'no')
                    . "\nBoarded: "   . (int) ($ctx['boarded'] ?? 0)
                    . "\nNo-shows: "  . (int) ($ctx['no_show'] ?? 0),
                ];
            case 'trip.student_no_show':
                return [
                    "Your child did not board the bus today",
                    "Route: "  . (string) ($ctx['route_name'] ?? $ctx['route_id'] ?? '')
                    . "\nDate: "  . (string) ($ctx['trip_date'] ?? '')
                    . "\nDirection: " . (string) ($ctx['direction'] ?? '')
                    . "\n\nIf your child took an alternate transport home, please ignore this message. "
                    . "If not, please contact the school Transport Office.",
                ];
            case 'event.scheduled':
                return [
                    "School event scheduled — " . (string) ($ctx['title'] ?? ''),
                    "Event: "       . (string) ($ctx['title'] ?? '')
                    . "\nType: "      . (string) ($ctx['event_type'] ?? '')
                    . "\nDate: "      . (string) ($ctx['event_date'] ?? '')
                    . "\nDestination: " . (string) ($ctx['destination'] ?? '')
                    . "\n\nYour child is included on the transport roster. Please confirm consent with the school office.",
                ];
            case 'event.rescheduled':
                return [
                    "School event rescheduled — " . (string) ($ctx['title'] ?? ''),
                    "Event: "         . (string) ($ctx['title'] ?? '')
                    . "\nOriginal: "    . (string) ($ctx['original_date'] ?? '')
                    . "\nNew date: "    . (string) ($ctx['event_date'] ?? '')
                    . "\nReason: "      . (string) ($ctx['reason'] ?? '')
                    . "\n\nPlease note the change in scheduling.",
                ];
            case 'event.cancelled':
                return [
                    "School event CANCELLED — " . (string) ($ctx['title'] ?? ''),
                    "Event: "     . (string) ($ctx['title'] ?? '')
                    . "\nType: "    . (string) ($ctx['event_type'] ?? '')
                    . "\nDate: "    . (string) ($ctx['event_date'] ?? '')
                    . "\nReason: "  . (string) ($ctx['reason']    ?? '')
                    . "\n\nTransport for this event will not proceed.",
                ];
            case 'event.trip_dispatched':
                return [
                    "Event trip dispatched — " . (string) ($ctx['event_id'] ?? ''),
                    "Event: "     . (string) ($ctx['title']      ?? '')
                    . "\nType: "    . (string) ($ctx['event_type'] ?? '')
                    . "\nTrip: "    . (string) ($ctx['trip_id']    ?? '')
                    . "\nVehicle: " . (string) ($ctx['vehicle_id'] ?? '')
                    . "\nRoute: "   . ((string) ($ctx['route_id']  ?? '') !== '' ? $ctx['route_id'] : '(free-form)'),
                ];
            case 'event.consent_reminder':
                return [
                    "Consent pending — " . (string) ($ctx['title'] ?? ''),
                    "Event: "  . (string) ($ctx['title'] ?? '')
                    . "\nDate: " . (string) ($ctx['event_date'] ?? '')
                    . "\n\nYour consent for your child's participation is still Pending. "
                    . "Please contact the school office at the earliest.",
                ];
            case 'event.completed':
                return [
                    "Event completed — " . (string) ($ctx['title'] ?? ''),
                    "Event: "          . (string) ($ctx['title'] ?? '')
                    . "\nType: "         . (string) ($ctx['event_type'] ?? '')
                    . "\nTrips: "        . (int) ($ctx['trips_completed'] ?? 0) . '/' . (int) ($ctx['trips_dispatched'] ?? 0)
                    . "\nBudgeted: INR " . (float) ($ctx['budgeted_amount'] ?? 0)
                    . "\nActual: INR "   . (float) ($ctx['actual_amount']   ?? 0)
                    . (!empty($ctx['over_budget']) ? "\n\nWARNING: actual transport cost exceeded budget. Review in Events dashboard." : ''),
                ];
        }
        return ['Transport notification', json_encode($ctx)];
    }

    private function _suspensionBody(array $ctx, string $verb): string
    {
        $catLabel = str_replace('_', ' ', (string) ($ctx['reason_category'] ?? ''));
        return "Route: " . (string) ($ctx['route_name'] ?? '') . " (" . (string) ($ctx['route_id'] ?? '') . ")\n"
             . "Category: {$catLabel}\n"
             . "Reason: " . (string) ($ctx['reason'] ?? '') . "\n"
             . "Action: {$verb}\n"
             . "From: " . (string) ($ctx['from_date'] ?? '')
             . (($ctx['to_date'] ?? '') !== '' ? "\nTo: " . $ctx['to_date'] : '');
    }

    private function _loadPushService()
    {
        try {
            if (!isset($this->ci->push)) {
                $this->ci->load->library('push_service', null, 'push');
            }
            return $this->ci->push ?? null;
        } catch (\Throwable $_) { return null; }
    }

    private function _assertReady(): void
    {
        if (!$this->ready) {
            throw new \RuntimeException('Transport_notifier is not initialised. Call init() first.');
        }
    }
}
