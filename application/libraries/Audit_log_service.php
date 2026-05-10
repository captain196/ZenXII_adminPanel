<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Audit_log_service — write-only audit trail for academic-planner mutations.
 *
 * USAGE
 *   $this->load->library('audit_log_service', null, 'audit');
 *   $this->audit->init($this->firebase, $this->school_id, $this->session_year, [
 *       'uid'  => $this->admin_id,
 *       'name' => $this->admin_name,
 *       'role' => $this->admin_role,
 *   ]);
 *
 *   $this->audit->log(
 *     'status_change',                     // action
 *     'curriculumTopic',                   // entityType
 *     $topicId,                            // entityId
 *     ['status' => 'not_started'],         // before (changed fields only)
 *     ['status' => 'in_progress'],         // after  (changed fields only)
 *     ['parentDocId' => $fsDocId, ...]     // metadata
 *   );
 *
 * SEMANTICS
 *   - Best-effort: any error caught + logged via log_message('error', ...).
 *     A failed audit log NEVER breaks the calling user action.
 *   - Single doc per user action — even if the action touched 50 sub-docs.
 *     Pass parent ID + count in metadata instead of looping per child.
 *   - Snapshots are TINY: just the fields that changed. Don't dump full docs.
 *   - Doc-id encodes timestamp prefix so logs are naturally sortable.
 *
 * SCHEMA
 *   See firestore.rules `match /academicAuditLog/{logId}` for access policy.
 *   See firestore.indexes.json for query indexes.
 */
class Audit_log_service
{
    /** @var object Firebase library instance (has firestoreSet, etc.) */
    private $firebase = null;
    /** @var string */
    private $schoolId = '';
    /** @var string */
    private $session = '';
    /** @var array{uid:string,name:string,role:string} */
    private $defaultActor = ['uid' => '', 'name' => '', 'role' => ''];
    /** @var bool */
    private $ready = false;

    const COLLECTION    = 'academicAuditLog';
    const ACTIONS       = ['create', 'update', 'delete', 'status_change', 'rollover', 'generation'];
    const ENTITY_TYPES  = ['curriculum', 'curriculumTopic', 'timetable', 'timetableSettings',
                           'substitute', 'calendarEvent', 'subjectAssignment'];
    const MAX_STR_LEN   = 500;
    const MAX_ARR_ITEMS = 50;

    /**
     * Bind dependencies. Idempotent — safe to re-init.
     *
     * @param object $firebase   Firebase library instance
     * @param string $schoolId
     * @param string $session
     * @param array  $defaultActor ['uid'=>..., 'name'=>..., 'role'=>...]
     */
    public function init($firebase, string $schoolId, string $session, array $defaultActor = []): self
    {
        $this->firebase = $firebase;
        $this->schoolId = (string) $schoolId;
        $this->session  = (string) $session;
        $this->defaultActor = [
            'uid'  => (string) ($defaultActor['uid']  ?? ''),
            'name' => (string) ($defaultActor['name'] ?? ''),
            'role' => (string) ($defaultActor['role'] ?? ''),
        ];
        $this->ready = ($firebase !== null && $this->schoolId !== '');
        return $this;
    }

    public function isReady(): bool
    {
        return $this->ready;
    }

    /**
     * Write a single audit log entry. Returns true on success, false on
     * any error (errors are also logged to PHP error log).
     *
     * @param string     $action      one of ACTIONS
     * @param string     $entityType  one of ENTITY_TYPES
     * @param string     $entityId    the affected doc id
     * @param array|null $before      tiny map of changed-only pre-state (null on create)
     * @param array|null $after       tiny map of changed-only post-state (null on delete)
     * @param array      $metadata    optional context (parentDocId, generationId, etc.)
     * @param array|null $actor       optional actor override (else uses defaultActor)
     */
    public function log(
        string $action,
        string $entityType,
        string $entityId,
        ?array $before   = null,
        ?array $after    = null,
        array  $metadata = [],
        ?array $actor    = null
    ): bool {
        if (!$this->ready) return false;
        if (!in_array($action,     self::ACTIONS,      true)) {
            log_message('error', "audit_log: invalid action='$action'");
            return false;
        }
        if (!in_array($entityType, self::ENTITY_TYPES, true)) {
            log_message('error', "audit_log: invalid entityType='$entityType'");
            return false;
        }
        if ($entityId === '') {
            log_message('error', "audit_log: empty entityId for $action/$entityType");
            return false;
        }

        $now   = date('c');
        $logId = $this->_buildLogId($now);

        $useActor = ($actor !== null) ? [
            'uid'  => (string) ($actor['uid']  ?? ''),
            'name' => (string) ($actor['name'] ?? ''),
            'role' => (string) ($actor['role'] ?? ''),
        ] : $this->defaultActor;

        $doc = [
            'logId'      => $logId,
            'schoolId'   => $this->schoolId,
            'session'    => $this->session,
            'ts'         => $now,
            'createdAt'  => $now,
            'action'     => $action,
            'entityType' => $entityType,
            'entityId'   => $entityId,
            'actor'      => $useActor,
            'before'     => $before === null ? null : $this->_truncate($before),
            'after'      => $after  === null ? null : $this->_truncate($after),
            'metadata'   => empty($metadata) ? null : $this->_truncate($metadata),
        ];

        try {
            $this->firebase->firestoreSet(self::COLLECTION, $logId, $doc);
            return true;
        } catch (\Throwable $e) {
            log_message('error', 'audit_log write failed [' . $action . '/' . $entityType . '/' . $entityId . ']: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * docId format: {schoolId}_{YYYYMMDDTHHMMSS}_{8 hex}
     * Naturally sortable; the random suffix prevents collisions inside the
     * same second (rapid-fire writes from the same actor).
     */
    private function _buildLogId(string $isoTs): string
    {
        $compact = preg_replace('/[^0-9T]/', '', $isoTs); // 2026-05-02T09:30:45+00:00 → 20260502T093045 (+ trailing zone digits)
        if (strlen($compact) > 15) $compact = substr($compact, 0, 15);
        $rand = function_exists('random_bytes')
            ? bin2hex(random_bytes(4))
            : substr(md5(uniqid('', true)), 0, 8);
        return "{$this->schoolId}_{$compact}_{$rand}";
    }

    /**
     * Cap snapshot size:
     *   - strings >500 chars truncated with "..."
     *   - arrays  >50 items truncated, with __truncated counter sibling
     *   - recurses into nested arrays (depth-bounded by recursion + truncation)
     */
    private function _truncate(array $data): array
    {
        $out = [];
        foreach ($data as $k => $v) {
            if (is_string($v)) {
                $out[$k] = (strlen($v) > self::MAX_STR_LEN)
                    ? substr($v, 0, self::MAX_STR_LEN) . '...'
                    : $v;
            } elseif (is_array($v)) {
                if (count($v) > self::MAX_ARR_ITEMS) {
                    $out[$k] = array_values(array_slice($v, 0, self::MAX_ARR_ITEMS));
                    $out[$k . '__truncated'] = count($v);
                } else {
                    // Recurse so nested strings/arrays are also bounded.
                    $out[$k] = $this->_truncate(array_values($v) === $v
                        ? $v   // numeric-keyed: keep as-is for now (already capped above)
                        : $v); // assoc: recurse keys
                }
            } else {
                $out[$k] = $v;
            }
        }
        return $out;
    }
}
