<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Support_audit — append-only event ledger for the Support Desk.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 *  WHY THIS IS NOT A FIRESTORE COLLECTION
 * ─────────────────────────────────────────────────────────────────────────────
 * The obvious implementation is a `supportAuditLog` collection. It is wrong,
 * and the reason is worth stating so nobody "simplifies" it back:
 *
 * The panel writes Firestore through the Admin SDK, which bypasses security
 * rules by design. There is NO Firestore mechanism that makes a collection
 * immutable to the Admin SDK. So a Firestore audit log is a log the audited
 * party can rewrite — which is not an audit log, it is a note.
 *
 * That matters here more than in most modules. This ledger exists partly to
 * answer the question a POCSO §21(2) prosecution asks of a school head: who
 * knew, and when. A record the accused can edit answers nothing.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 *  CURRENT SINK — INTERIM, AND DELIBERATELY SO
 * ─────────────────────────────────────────────────────────────────────────────
 * Entries go to CodeIgniter's file log via log_message(), one structured JSON
 * line per event, tagged SUPPORT_AUDIT.
 *
 * This is a genuine improvement over Firestore rather than a placeholder for
 * one: the log lives on the server filesystem, outside the Admin SDK's reach
 * entirely, so the credential that performs the audited action cannot rewrite
 * the record of it. It is NOT immutable — a shell on the box can edit it.
 *
 * The agreed final sink is Cloud Logging with a LOCKED RETENTION BUCKET, which
 * is append-only even to project owners. It is a decision, not yet provisioned.
 * Swap it in at _emit() below; every call site stays unchanged, which is the
 * whole point of routing every mutation through record().
 *
 * ⚠ The confidential lane (POSH / safeguarding, not built in v1) MUST NOT ship
 *   on the interim sink. Its evidentiary weight is the reason C-08 exists.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 *  WHAT IS DELIBERATELY NOT RECORDED
 * ─────────────────────────────────────────────────────────────────────────────
 * No IP address, no user agent, no device id. Under DPDP that is additional
 * personal data with a retention obligation attached, and it buys nothing
 * operationally here — the actor is already identified by staff id, which is
 * the question an audit actually needs answered.
 *
 * Message BODIES are never recorded either. The ledger records that a reply
 * happened, by whom, on what ticket — not what a parent wrote about a child.
 * Duplicating that text into a second store doubles the disclosure surface for
 * no investigative gain; the message itself is already in supportMessages.
 */
class Support_audit
{
    /** Every action this ledger understands. Anything else is rejected. */
    public const ACTIONS = [
        'CREATED',
        'VIEWED',
        'ASSIGNED',
        'REASSIGNED',
        'RETURNED',
        'REPLIED',
        'NOTE_ADDED',
        'RESOLVED',
        'REOPENED',
        'FORCE_CLOSED',
        'PERMISSION_DENIED',
    ];

    /** Log tag. Grep this to extract the ledger from the mixed CI log. */
    private const TAG = 'SUPPORT_AUDIT';

    /** @var CI_Controller */
    private $CI;

    public function __construct()
    {
        $this->CI =& get_instance();
    }

    /**
     * Record one event.
     *
     * Never throws. An audit sink that can break a reply is an audit sink that
     * gets removed — so a failure here is logged loudly and swallowed, and the
     * mutation proceeds.
     *
     * That trade is correct for the NORMAL lane, where the ledger is an
     * operational record. It is NOT correct for the confidential lane, where
     * the ledger is evidence and a write failure must abort the read. That
     * branch does not exist yet because the lane does not exist yet; when it
     * does, it belongs here, not at the call sites.
     *
     * @param string $action   one of self::ACTIONS
     * @param string $ticketId short id, e.g. TKT_01J8F2K9
     * @param array  $detail   small, non-sensitive facts (status transitions,
     *                         assignee ids, a truncated reason). NEVER a body.
     * @return bool            true when the entry was emitted
     */
    public function record(string $action, string $ticketId, array $detail = []): bool
    {
        try {
            if (!in_array($action, self::ACTIONS, true)) {
                log_message('error', self::TAG . ' rejected unknown action: ' . $action);
                return false;
            }

            // Attribution comes from the SESSION, not from $this->CI->admin_id.
            //
            // MY_Controller declares admin_id, admin_name, admin_role and
            // school_id as PROTECTED. Reading them from out here is not an
            // error you would ever see: PHP's `??` on an inaccessible property
            // yields the fallback silently — no warning, no exception — so
            // every entry this ledger wrote carried an empty actor and an empty
            // school while looking perfectly healthy. An append-only audit log
            // that records what happened but never who did it is the one
            // failure mode this class exists to prevent, and it is the part
            // that matters legally on the confidential lane.
            //
            // MY_Controller populates all four FROM these session keys, so the
            // session is the same source, reached through a public accessor.
            // Verified on device UAT 2026-08-29.
            $sess = $this->CI->session ?? null;
            $sv = static function ($key) use ($sess) {
                if ($sess === null) return '';
                $v = $sess->userdata($key);
                return is_scalar($v) ? (string) $v : '';
            };

            $entry = [
                'ts'       => gmdate('c'),
                'action'   => $action,
                'schoolId' => $sv('school_id'),
                'ticketId' => $ticketId,
                'actorId'  => $sv('admin_id'),
                'actorName'=> $sv('admin_name'),
                'actorRole'=> $sv('admin_role'),
                'detail'   => $this->_scrub($detail),
            ];

            return $this->_emit($entry);
        } catch (\Throwable $e) {
            log_message('error', self::TAG . ' record() failed: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Convenience: record a refused action.
     *
     * A denial is the single most useful line in an audit log — it is the one
     * that shows someone tried. Recording only successes produces a ledger that
     * looks clean precisely when it matters most.
     */
    public function denied(string $ticketId, string $reason, array $detail = []): bool
    {
        return $this->record('PERMISSION_DENIED', $ticketId, $detail + ['reason' => $reason]);
    }

    // =========================================================================

    /**
     * Strip anything that should not be in a second store.
     *
     * Free-text fields are truncated hard, and anything that looks like a
     * message body is dropped outright. This is defence against a future call
     * site casually passing the whole ticket array.
     */
    private function _scrub(array $detail): array
    {
        $banned = ['body', 'message', 'subject', 'description', 'attachments', 'keywords'];
        $out = [];
        foreach ($detail as $k => $v) {
            if (in_array(strtolower((string) $k), $banned, true)) continue;
            if (is_array($v) || is_object($v)) {
                $out[$k] = '[omitted:' . gettype($v) . ']';
                continue;
            }
            $s = (string) $v;
            $out[$k] = mb_strlen($s) > 160 ? (mb_substr($s, 0, 157) . '…') : $s;
        }
        return $out;
    }

    /**
     * THE SINK SEAM.
     *
     * Replace the body of this method with a Cloud Logging write once the
     * locked-retention bucket is provisioned. Do not change record()'s shape
     * and do not touch a single call site.
     *
     * ─────────────────────────────────────────────────────────────────────────
     *  WHY THIS DOES NOT USE log_message()
     * ─────────────────────────────────────────────────────────────────────────
     * The obvious implementation is log_message('info', …). It would have
     * written NOTHING in production and nobody would have noticed:
     * config log_threshold defaults to 1 (errors only), so every 'info' line is
     * discarded before it reaches disk.
     *
     * More fundamentally — an audit ledger must not be switchable by a log
     * verbosity setting. Someone tuning noise down should not silently turn off
     * the record of who read a safeguarding report. So this writes its own file,
     * unconditionally, and only falls back to log_message('error') — which DOES
     * survive threshold 1 — when the file write itself fails.
     */
    private function _emit(array $entry): bool
    {
        $line = json_encode($entry, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($line === false) {
            log_message('error', self::TAG . ' json_encode failed');
            return false;
        }

        $dir  = rtrim(APPPATH, '/\\') . DIRECTORY_SEPARATOR . 'logs';
        $file = $dir . DIRECTORY_SEPARATOR . 'support_audit-' . gmdate('Y-m-d') . '.log';

        // One line, appended, with an exclusive lock so concurrent requests do
        // not interleave mid-line. Append-only by convention here; append-only
        // by enforcement once the sink moves to a locked retention bucket.
        if (is_dir($dir) && is_writable($dir)) {
            if (@file_put_contents($file, $line . PHP_EOL, FILE_APPEND | LOCK_EX) !== false) {
                return true;
            }
        }

        // Sink unavailable. Escalate to the error log so the GAP is visible —
        // a silently missing audit trail is worse than a noisy one.
        log_message('error', self::TAG . ' SINK-UNAVAILABLE ' . $line);
        return false;
    }
}
