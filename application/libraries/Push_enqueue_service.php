<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Push_enqueue_service — Phase 15.7.
 *
 * THE ONE enqueue path for `pushRequests`.
 *
 * ──────────────────────────────────────────────────────────────────────
 *  WHY THIS EXISTS
 * ──────────────────────────────────────────────────────────────────────
 * `MY_Controller::emit_push()` was the only sanctioned way to enqueue a push,
 * but it is `protected` on the web-controller spine, so CLI jobs (the fee
 * crons) could not reach it. That left exactly two bad options: duplicate the
 * enqueue in every CLI job — the "second sender" CLAUDE.md forbids — or leave
 * automation unable to notify anyone.
 *
 * Phase 15.6 recorded this as an architectural boundary. RE-TRACING IT IN
 * PHASE 15.7 SHOWED THAT ASSESSMENT WAS TOO PESSIMISTIC: emit_push's body
 * depends on nothing but (a) an initialised Firestore_service and (b) a
 * schoolId STRING. Session and custom claims are merely how the web context
 * happens to POPULATE that string — they are not used by the enqueue itself.
 * Extracting it is therefore mechanical, not architectural.
 *
 * ──────────────────────────────────────────────────────────────────────
 *  ARCHITECTURE — unchanged in shape, single-sourced in code
 * ──────────────────────────────────────────────────────────────────────
 *        Web controllers ──┐
 *                          ├──► Push_enqueue_service ──► pushRequests
 *        CLI jobs ─────────┘                                  │
 *                                                             ▼
 *                              functions/index.js MARK_REGISTRY (the ONLY
 *                              dispatcher) ──► FCM ──► Parent / Teacher app
 *
 * ONE enqueue. ONE dispatcher. No second sender.
 *
 * IDEMPOTENCY is structural and pre-existing: the doc id is
 * `{schoolId}_{dedupeKey}`, so repeated enqueues for the same logical event
 * COALESCE onto one document instead of fanning out. A nightly job that runs
 * twice therefore cannot notify a parent twice.
 *
 * ⚠ CARRIED FORWARD FROM emit_push's OWN WARNING: do not enqueue marks still
 * owned by the legacy poller in MY_Controller::_auto_process_push_requests()
 * (teacher / teacher_leave_* / homework_*). A doc processed by BOTH the poller
 * and the Cloud Function double-sends. FEE_DUE is not in that set — it is
 * dispatcher-owned only — so fee reminders are safe.
 */
class Push_enqueue_service
{
    private const COL = 'pushRequests';

    /**
     * Enqueue one push request.
     *
     * @param object $fs        an INITIALISED Firestore_service (provides
     *                          docId() scoping and the tenant-scoped set()).
     * @param string $schoolId  tenant id; written into the payload and used by
     *                          the dispatcher for recipient resolution.
     * @param string $mark      MARK_REGISTRY key, e.g. 'FEE_DUE'.
     * @param string $dedupeKey stable per-event token — this is what makes the
     *                          enqueue idempotent. Choose it so that "the same
     *                          logical notification" always produces the same
     *                          string.
     * @param array  $fields    audience + payload fields.
     * @param string $markedBy  actor label for audit ('system' for CLI jobs).
     */
    public function enqueue($fs, string $schoolId, string $mark, string $dedupeKey,
                            array $fields, string $markedBy = ''): bool
    {
        if (!is_object($fs) || $schoolId === '' || $mark === '' || $dedupeKey === '') return false;

        // ── PER-SCHOOL NOTIFICATION PREFERENCES ─────────────────────────
        // Checked at the single choke point every producer passes through, so
        // one test covers all of them. Absent preferences SEND, which is what
        // every tenant did before this existed.
        if (!$this->allowed($fs, $schoolId, $mark)) {
            log_message('info',
                "Push_enqueue_service: [{$mark}] is switched off for school [{$schoolId}] — not enqueued."
            );
            return false;
        }
        try {
            $reqId   = $fs->docId($dedupeKey);
            $payload = array_merge([
                'schoolId'  => $schoolId,
                'mark'      => $mark,
                'status'    => 'pending',
                'markedBy'  => $markedBy,
                'createdAt' => date('c'),
            ], $fields);
            return (bool) $fs->set(self::COL, $reqId, $payload, false);
        } catch (\Throwable $e) {
            log_message('error', "Push_enqueue_service::enqueue({$mark}) failed: " . $e->getMessage());
            return false;
        }
    }

    /** Request-scoped cache of each school's notification preferences. */
    private $_prefs = [];

    /**
     * May this school send this notification?
     *
     * Reads `schools/{schoolId}_profile.notificationSettings.{MARK}.enabled`.
     * The school profile is already a cached config document, so this costs no
     * meaningful round-trip on the hot path.
     *
     * FAILS OPEN by design — see the class docblock. A school that has never
     * opened the notification screen has no preferences at all and must keep
     * receiving everything it received yesterday.
     */
    private function allowed($fs, string $schoolId, string $mark): bool
    {
        if (!array_key_exists($schoolId, $this->_prefs)) {
            $prefs = null;
            try {
                // Composed from the EXPLICIT $schoolId rather than $fs->docId(),
                // because a CLI cron's helper may be scoped to another tenant or
                // to none at all.
                $doc = $fs->get('schools', $schoolId . '_profile');
                if (is_array($doc) && is_array($doc['notificationSettings'] ?? null)) {
                    $prefs = $doc['notificationSettings'];
                }
            } catch (\Throwable $e) {
                log_message('error',
                    'Push_enqueue_service: notification preferences unreadable for ['
                    . $schoolId . '] — sending: ' . $e->getMessage()
                );
                $prefs = null;
            }
            $this->_prefs[$schoolId] = $prefs;
        }

        $prefs = $this->_prefs[$schoolId];
        if (!is_array($prefs)) {
            return true;                  // never configured, or unreadable
        }

        $row = $prefs[$mark] ?? null;
        if (!is_array($row) || !array_key_exists('enabled', $row)) {
            return true;                  // this event was never configured
        }

        return (bool) $row['enabled'];
    }
}
