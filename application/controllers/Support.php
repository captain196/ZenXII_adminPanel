<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Support — parent→school ticketing desk (P1 read + P2 writes).
 *
 * ─────────────────────────────────────────────────────────────────────────────
 *  THE GATING MODEL IS NOT THE USUAL ONE. READ THIS BEFORE ADDING A METHOD.
 * ─────────────────────────────────────────────────────────────────────────────
 * Every other module in this panel puts require_permission() in the constructor
 * and is done. This one deliberately does NOT, and adding one would reintroduce
 * the exact bug this design was built to avoid.
 *
 * An admin may assign a ticket to ANY staff member — the accountant, the
 * warden, a class teacher — because all staff hold panel logins. If the whole
 * controller were gated on the 'Support' module, that assignee would receive
 * the push, tap it, and hit "You don't have access to the Support module."
 * The assignment succeeds, the admin believes it is handled, and the work is
 * invisible to the person who owns it.
 *
 * So the surface is split:
 *
 *   index()  · the QUEUE          → require_permission('Support', …, 'view')
 *   mine()   · MY assigned tickets → IDENTITY ONLY, no module check
 *   thread() · one ticket          → assignee OR 'Support' at view
 *
 * The module governs *triage*, not *doing the work*. This mirrors
 * Staff_attendance::me(), where an own-record endpoint is gated on identity
 * rather than on a module grant.
 *
 * Permission ladder:
 *   (no grant) see / reply / resolve / return tickets ASSIGNED TO ME
 *   view       + browse the whole queue, read-only
 *   edit       + reply to any ticket in the queue          (P2)
 *   manage     + assign, reassign, resolve any, force-close (P2)
 *   never      the confidential lane — not reachable at ANY level. Access is
 *              membership in schools.supportConfig.confidentialRecipients[],
 *              checked separately. Not implemented in v1.
 *
 * Note that `manage` is the ONLY route to assign and force_close. Being a
 * ticket's assignee must not let you reassign it away or close it — see the
 * $assigneeMayAct flag on _load_for_write().
 *
 * ─────────────────────────────────────────────────────────────────────────────
 *  SCOPE
 * ─────────────────────────────────────────────────────────────────────────────
 * P1 reads: index, mine, thread + get_queue, get_mine, get_thread, search.
 * P2 writes: assign, reply, add_note, resolve, return_to_queue, force_close,
 * bulk_force_close, get_assignees.
 *
 * Every write records to Support_audit. That ledger deliberately does NOT live
 * in Firestore — the panel writes through the Admin SDK, which bypasses rules,
 * so a Firestore audit log is one the audited party can rewrite. See the
 * library header.
 *
 * NOT here, by design: parent-side creation (the app writes it directly), the
 * ticketNo counter and the reopen transition (both Cloud Functions, P5), and
 * the confidential lane (P6, blocked on legal).
 *
 * ─────────────────────────────────────────────────────────────────────────────
 *  SESSION SCOPING — A DELIBERATE EXCEPTION
 * ─────────────────────────────────────────────────────────────────────────────
 * The house rule is that every query is scoped twice: school AND academic
 * session. Support is the documented exception. A fee dispute raised in March
 * must stay readable in April after the session rolls, or the parent loses
 * their own history and the school loses the audit trail.
 *
 * sessionId IS written onto each ticket so reporting can group by it. NOTHING
 * FILTERS ON IT. Use schoolWhere(), never sessionWhere(), in this controller.
 * A future session-filter sweep that "fixes" this will silently orphan every
 * ticket at year end.
 *
 * Indexes backing the queries below were deployed 2026-08-26 (8 composites,
 * all READY). See firebase-rules/firestore.indexes.json.
 */
class Support extends MY_Controller
{
    /** Active work — the default queue view. */
    private const ACTIVE_STATUSES = ['open', 'assigned', 'reopened'];

    /** Every status a ticket may hold. Order is the lifecycle order. */
    private const ALL_STATUSES = ['open', 'assigned', 'reopened', 'resolved', 'closed'];

    /** Page size for the queue and My Tickets. */
    private const PAGE_SIZE = 25;

    /** Hard ceiling on any single Firestore read from this controller. */
    private const MAX_LIMIT = 100;

    /**
     * The only lane v1 implements. `lane` ships in its final four-value shape
     * ('normal' | 'general' | 'posh' | 'safeguarding') so the queue index never
     * has to be rebuilt, but nothing creates a confidential ticket yet.
     */
    private const LANE_NORMAL = 'normal';

    // NOTE: no require_permission() here. See the header block.
    public function __construct()
    {
        parent::__construct();
        $this->load->helper('rbac');
    }

    // =========================================================================
    //  GATING HELPERS
    // =========================================================================

    /**
     * Does the current user hold the Support module at $level?
     *
     * has_permission() already short-circuits RBAC_BYPASS_ROLES ('Super Admin',
     * 'School Super Admin'), so there is no role-name list here on purpose —
     * roughly 40 controllers still carry _require_role name gates that block
     * custom roles, and this module does not join them.
     */
    private function _can(string $level = 'view'): bool
    {
        return function_exists('has_permission') && has_permission('Support', $level);
    }

    /** Is the logged-in staff member the assignee of this ticket? */
    private function _is_assignee(array $ticket): bool
    {
        $me = (string) ($this->admin_id ?? '');
        return $me !== '' && (string) ($ticket['assignedTo'] ?? '') === $me;
    }

    /**
     * May the current user open this specific ticket?
     *
     * Assignee OR queue-viewer. The confidential lane is excluded outright:
     * it is not reachable through this controller at any permission level.
     */
    private function _may_read_ticket(array $ticket): bool
    {
        if ((string) ($ticket['lane'] ?? self::LANE_NORMAL) !== self::LANE_NORMAL) {
            return false;   // fail closed — confidential lane is a separate surface
        }
        return $this->_is_assignee($ticket) || $this->_can('view');
    }

    // =========================================================================
    //  READ HELPERS
    // =========================================================================

    /**
     * Fetch one ticket by its short id, scoped to this school.
     *
     * Returns null when absent OR when it belongs to another tenant — the
     * caller cannot distinguish the two, which is the point: existence probing
     * across tenants leaks that a ticket number is in use elsewhere.
     */
    private function _get_ticket(string $ticketId): ?array
    {
        $ticketId = trim($ticketId);
        if ($ticketId === '' || !preg_match('/^TKT_[A-Za-z0-9]{1,32}$/', $ticketId)) {
            return null;
        }
        $doc = $this->fs->get('supportTickets', $this->fs->docId($ticketId));
        if (!is_array($doc) || empty($doc)) return null;
        if ((string) ($doc['schoolId'] ?? '') !== (string) $this->school_id) return null;
        return $doc;
    }

    /**
     * Normalise a raw ticket document into the shape the list views render.
     *
     * Deliberately narrow: this never returns message bodies, and never returns
     * reporter identity for a ticket flagged anonymous. Widening it is how a
     * "just add one field" change leaks a name a parent asked to withhold.
     */
    private function _row(array $t): array
    {
        $anon = !empty($t['isAnonymous']);
        return [
            'ticketId'      => (string) ($t['ticketId'] ?? ''),
            'ticketNo'      => isset($t['ticketNo']) ? (int) $t['ticketNo'] : null,
            'lane'          => (string) ($t['lane'] ?? self::LANE_NORMAL),
            'category'      => (string) ($t['category'] ?? ''),
            'subject'       => (string) ($t['subject'] ?? ''),
            'status'        => (string) ($t['status'] ?? 'open'),
            'studentName'   => $anon ? '' : (string) ($t['studentName'] ?? ''),
            'className'     => $anon ? '' : (string) ($t['className'] ?? ''),
            'reporterName'  => $anon ? '' : (string) ($t['reporterName'] ?? ''),
            'isAnonymous'   => $anon,
            'assignedTo'    => (string) ($t['assignedTo'] ?? ''),
            'assignedName'  => (string) ($t['assignedName'] ?? ''),
            'messageCount'  => (int) ($t['messageCount'] ?? 0),
            'lastMessageAt' => $this->_ts($t['lastMessageAt'] ?? null),
            'createdAt'     => $this->_ts($t['createdAt'] ?? null),
            'awaitingUs'    => $this->_awaiting_us($t),
            'attachments'   => count((array) ($t['attachments'] ?? [])),
        ];
    }

    /**
     * Is the parent waiting on us?
     *
     * lastParentReplyAt later than lastStaffReplyAt. §03 captures both fields
     * specifically so this question is answerable without reading the thread.
     */
    private function _awaiting_us(array $t): bool
    {
        $p = $this->_ts($t['lastParentReplyAt'] ?? null);
        $s = $this->_ts($t['lastStaffReplyAt'] ?? null);
        if ($p === null) return false;
        return $s === null || $p > $s;
    }

    /** Firestore timestamps arrive in several shapes; normalise to epoch seconds. */
    /**
     * The ONE format this controller writes timestamps in.
     *
     * R8/R17: `lastMessageAt` is written by BOTH runtimes — the panel with
     * date('c') ("…T08:26:02+00:00") and the Cloud Function with toISOString()
     * ("…T08:26:02.632Z"). Both are strings, so Firestore compares them
     * lexicographically, and '+' (0x2B) sorts before '.' (0x2E): for the same
     * instant the PHP form sorts BEFORE the CF form. Ordering on that field is
     * therefore wrong across the boundary, and a cursor over it cannot be
     * trusted.
     *
     * Matching the CF's shape makes every NEW panel write directly comparable
     * with every CF write. Existing documents keep their old format — see the
     * note on _page(): a backfill is still required before pagination over these
     * fields is trustworthy.
     */
    private function _iso(?int $at = null): string
    {
        return gmdate('Y-m-d\TH:i:s', $at ?? time()) . '.000Z';
    }

    private function _ts($v): ?int
    {
        if ($v === null || $v === '') return null;
        if (is_int($v))    return $v > 9999999999 ? (int) ($v / 1000) : $v;
        if (is_float($v))  return (int) $v;
        if (is_array($v))  return isset($v['seconds']) ? (int) $v['seconds'] : null;
        if (is_string($v)) { $t = strtotime($v); return $t === false ? null : $t; }
        if (is_object($v) && method_exists($v, 'get')) {
            try { return (int) $v->get()->format('U'); } catch (\Throwable $e) { return null; }
        }
        return null;
    }

    /** Clamp a caller-supplied limit into a sane range. */
    private function _limit($raw): int
    {
        $n = (int) $raw;
        if ($n <= 0) return self::PAGE_SIZE;
        return min($n, self::MAX_LIMIT);
    }

    /**
     * Core queue read.
     *
     * Uses index 1  [schoolId, lane, status, lastMessageAt DESC]
     *   or  index 6  [schoolId, lane, status, lastParentReplyAt DESC]
     *
     * Cursor pagination is expressed as a RANGE on the orderBy field rather
     * than a startAfter cursor, because Firestore_service::where() does not
     * expose the REST client's $startAfter. Same index, same result, one less
     * dependency on an unexposed method.
     *
     * Firestore permits this combination: `status IN [...]` is not a range, so
     * the single range field is the orderBy field, which is legal.
     */
    /**
     * Firestore_service::where() (and therefore schoolWhere()) returns rows
     * WRAPPED as ['id' => docId, 'data' => [...]] — see its @return at
     * Firestore_service.php:425. get() returns the document FLAT. Reading a
     * wrapped row as if it were flat yields no keys at all, so every field
     * falls through to its default: the queue rendered a real ticket with a
     * blank number, blank category and blank student, and only `status`/`lane`
     * looked right because those two defaults happen to match.
     *
     * Found on device UAT 2026-08-28 with the first real ticket (#1). Tolerates
     * both shapes on purpose, matching the idiom already used in
     * Firestore_service.php:551.
     */
    /**
     * Staff who should hear about a ticket landing back in the queue.
     *
     * Mirrors deskRecipients() in functions/supportDesk.js — the same capability
     * query, so both producers of TICKET_RAISED address the same set: everyone
     * holding the Support module, resolved from staffCapabilities.
     *
     * ON THE INDEX, because an assertion here would be worthless: this query
     * needs [schoolId, modules CONTAINS], and firestore.indexes.json still
     * labels that block "DEPLOY-PENDING" (a stale comment). Executed against
     * LIVE on 2026-08-30 it SERVES and resolved 2 staff. That matters because
     * Firestore_service::where() swallows FAILED_PRECONDITION and returns [] —
     * so a missing index would not error here, it would silently produce an
     * empty recipient list and reinstate the very bug this method fixes.
     * Re-verify with the Index Sentinel before trusting it on another tenant.
     *
     * NOTE the cap. The Cloud Function's copy stops at 50 with no signal, so a
     * school granting Support to more than 50 staff silently drops recipients 51+
     * from every new-ticket alert. This copy uses a higher bound AND logs when it
     * is reached, so the same failure is at least visible here. The CF's cap is
     * tracked separately as R5.
     *
     * Returns document ids: staffCapabilities is keyed by staff id.
     */
    private function _desk_recipients(): array
    {
        $cap  = 200;
        $rows = $this->fs->schoolWhere(
            'staffCapabilities',
            [['modules', 'array-contains', 'Support']],
            null,
            'ASC',
            $cap
        );
        if (!is_array($rows)) return [];

        $ids = [];
        foreach ($rows as $r) {
            // The document ID is the staff id, so this reads the WRAPPER, not
            // the flattened data — _flatten() would discard exactly what we need.
            $id = is_array($r) ? (string) ($r['id'] ?? '') : '';
            if ($id !== '') $ids[] = $id;
        }

        if (count($ids) >= $cap) {
            log_message('error',
                'Support::_desk_recipients hit the ' . $cap . ' cap for school '
                . $this->school_id . ' — recipients beyond it were NOT notified.');
        }
        return $ids;
    }

    private function _flatten($rows): array
    {
        if (!is_array($rows)) return [];
        $out = [];
        foreach ($rows as $r) {
            if (!is_array($r)) continue;
            $d = isset($r['data']) && is_array($r['data']) ? $r['data'] : $r;
            if ($d !== []) $out[] = $d;
        }
        return $out;
    }

    private function _query_tickets(array $statuses, $cursor, int $limit, string $orderBy = 'lastMessageAt'): array
    {
        $conds = [
            ['lane', '==', self::LANE_NORMAL],
        ];

        // A single status is an equality filter, which is cheaper and avoids IN
        // entirely; more than one uses IN. Both hit the same composite index.
        if (count($statuses) === 1) {
            $conds[] = ['status', '==', reset($statuses)];
        } elseif (count($statuses) > 1) {
            $conds[] = ['status', 'in', array_values($statuses)];
        }

        // The cursor is passed through UNCHANGED and untyped: it is whatever
        // _page() read off the last document, so it always matches the field's
        // stored type. Casting it (it used to arrive as (int)) is what made the
        // comparison type-mismatch and return nothing.
        if ($cursor !== null && $cursor !== '' && $cursor !== 0) {
            $conds[] = [$orderBy, '<', $cursor];
        }

        $rows = $this->fs->schoolWhere('supportTickets', $conds, $orderBy, 'DESC', $limit);
        return $this->_flatten($rows);
    }

    /**
     * Defence in depth: drop anything that is not the normal lane.
     *
     * The queue query already filters lane, but the SEARCH query cannot —
     * index 7 is [schoolId, keywords, lastMessageAt] with no lane column, so
     * adding a lane filter there would need an index that does not exist. This
     * filter is what keeps search fail-closed until the confidential lane
     * ships with its own index.
     */
    private function _normal_only(array $rows): array
    {
        $out = [];
        foreach ($rows as $r) {
            if (!is_array($r)) continue;
            if ((string) ($r['lane'] ?? self::LANE_NORMAL) !== self::LANE_NORMAL) continue;
            $out[] = $r;
        }
        return $out;
    }

    /** Build the {rows, nextCursor} envelope both list endpoints return. */
    /**
     * Build the {rows, nextCursor} envelope both list endpoints return.
     *
     * $cursorDocs, when given, is the UNFILTERED result of the query. Pagination
     * is a property of the query's page boundary, not of whatever survives a
     * post-filter — see B8 below.
     */
    private function _page(array $docs, int $limit, string $orderBy = 'lastMessageAt', ?array $cursorDocs = null): array
    {
        $docs = $this->_normal_only($docs);
        $rows = array_map([$this, '_row'], $docs);

        // B8, first half: the cursor is read from the SOURCE DOCUMENT, not from
        // the rendered row. It used to be
        //     $key = $orderBy === 'lastParentReplyAt' ? 'lastMessageAt' : $orderBy;
        // which handed back a lastMessageAt value and then applied it as a range
        // on lastParentReplyAt — a different field entirely. That ternary existed
        // because _row() does not expose lastParentReplyAt at all, so the rendered
        // row simply had no correct value to offer. Reading the source document
        // removes the constraint rather than working around it.
        //
        // B8, second half: get_queue applies the "awaiting" filter AFTER the
        // limit, so count($rows) >= $limit was almost never true and nextCursor
        // stayed null — "Load more" never appeared, making everything past the
        // first raw page unreachable. Callers that post-filter pass the unfiltered
        // docs here so the page boundary is judged on what the query returned.
        $src  = $cursorDocs !== null ? $cursorDocs : $docs;
        $next = null;
        if (count($src) >= $limit && $src) {
            $last = end($src);
            // R17: emit the value AS STORED. It used to pass through _ts(),
            // which returns an epoch int — and Firestore inequality is
            // type-exact, so an integerValue cursor could never match a
            // stringValue field. Every "Load more" past page 1 returned empty,
            // silently. The cursor must be the same type as the field it filters.
            $raw  = $last[$orderBy] ?? null;
            $next = ($raw === null || $raw === '') ? null : $raw;
        }
        return ['rows' => $rows, 'nextCursor' => $next, 'count' => count($rows)];
    }

    // =========================================================================
    //  PAGES
    // =========================================================================

    /**
     * The triage queue. Requires the Support module at 'view'.
     */
    public function index(): void
    {
        require_permission('Support', 'support_queue', 'view');

        $data = [
            'can_edit'   => $this->_can('edit'),
            'can_manage' => $this->_can('manage'),
            'active_tab' => 'queue',
        ];
        $this->load->view('include/header', $data);
        $this->load->view('support/index', $data);
        $this->load->view('include/footer');
    }

    /**
     * Tickets assigned to me.
     *
     * NO MODULE CHECK. Any authenticated staff member reaches this, because any
     * staff member can be handed a ticket. MY_Controller has already enforced
     * that there is a valid admin session; that is the whole gate, by design.
     */
    public function mine(): void
    {
        $data = [
            'can_edit'   => $this->_can('edit'),
            'can_manage' => $this->_can('manage'),
            'has_queue'  => $this->_can('view'),
            'active_tab' => 'mine',
        ];
        $this->load->view('include/header', $data);
        $this->load->view('support/mine', $data);
        $this->load->view('include/footer');
    }

    /**
     * One ticket thread. Assignee OR queue-viewer.
     */
    public function thread(string $ticketId = ''): void
    {
        $ticket = $this->_get_ticket($ticketId);
        if ($ticket === null) {
            show_404();
            return;
        }
        if (!$this->_may_read_ticket($ticket)) {
            redirect(rbac_denied_url('Support', 'view'));
            return;
        }

        $data = [
            'ticket_id'  => (string) ($ticket['ticketId'] ?? ''),
            'can_edit'   => $this->_can('edit') || $this->_is_assignee($ticket),
            'can_manage' => $this->_can('manage'),
            'has_queue'  => $this->_can('view'),
            'active_tab' => 'queue',
        ];
        $this->load->view('include/header', $data);
        $this->load->view('support/thread', $data);
        $this->load->view('include/footer');
    }

    // =========================================================================
    //  JSON ENDPOINTS (read-only)
    // =========================================================================

    /**
     * Queue data.
     *
     * GET params:
     *   status  'active' (default) | one of ALL_STATUSES | 'all'
     *   filter  'awaiting' — only tickets where the parent is waiting on us
     *   cursor  epoch seconds from a previous nextCursor
     *   limit   1..100
     */
    public function get_queue(): void
    {
        require_permission('Support', 'support_queue', 'view');

        $statusParam = (string) $this->input->get('status', true);
        $filter      = (string) $this->input->get('filter', true);
        $cursor      = $this->input->get('cursor', true);
        $limit       = $this->_limit($this->input->get('limit', true));

        if ($statusParam === '' || $statusParam === 'active') {
            $statuses = self::ACTIVE_STATUSES;
        } elseif ($statusParam === 'all') {
            $statuses = self::ALL_STATUSES;
        } elseif (in_array($statusParam, self::ALL_STATUSES, true)) {
            $statuses = [$statusParam];
        } else {
            $this->json_error('Unknown status filter.', 400);
            return;
        }

        $orderBy = ($filter === 'awaiting') ? 'lastParentReplyAt' : 'lastMessageAt';
        // R17: passed through untyped — the cursor must keep the type of the field
        // it filters on, and casting it to int is what made every page past the
        // first return nothing.
        $docs    = $this->_query_tickets($statuses, ($cursor === null || $cursor === '') ? null : $cursor, $limit, $orderBy);

        $rawDocs = $docs;   // page boundary is judged on this, before filtering
        if ($filter === 'awaiting') {
            $docs = array_values(array_filter($docs, [$this, '_awaiting_us']));
        }

        // B3: where() returns [] on failure as well as on genuine emptiness, so
        // this endpoint was answering a failed read with a confident "no tickets".
        // The client is written to prevent exactly this — support_desk.js's own
        // comment says a failure "must never read as 'no tickets'" — but it can
        // only act on a non-ok status or {status:'error'}, and the server was
        // handing it 200/success. Ask the query layer which it was.
        if (empty($docs) && $this->fs->lastQueryFailed()) {
            log_message('error', 'Support::get_queue — query failed; refusing to render an empty result as success');
            $this->json_error('Could not load the ticket queue. Please retry.', 503);
            return;
        }

        // Pass the pre-filter docs so the cursor tracks the QUERY's page boundary,
        // not the count that survived _awaiting_us().
        $page = $this->_page($docs, $limit, $orderBy, $rawDocs);
        $this->json_success($page + ['status_filter' => $statusParam ?: 'active', 'filter' => $filter]);
    }

    /**
     * My assigned tickets. Identity-gated — no module grant required.
     *
     * Uses index 3 [schoolId, assignedTo, status, lastMessageAt DESC].
     */
    public function get_mine(): void
    {
        $me = (string) ($this->admin_id ?? '');
        if ($me === '') {
            $this->json_error('No staff identity on this session.', 401);
            return;
        }

        $statusParam = (string) $this->input->get('status', true);
        $cursor      = $this->input->get('cursor', true);
        $limit       = $this->_limit($this->input->get('limit', true));

        if ($statusParam === '' || $statusParam === 'active') {
            $statuses = self::ACTIVE_STATUSES;
        } elseif ($statusParam === 'all') {
            $statuses = self::ALL_STATUSES;
        } elseif (in_array($statusParam, self::ALL_STATUSES, true)) {
            $statuses = [$statusParam];
        } else {
            $this->json_error('Unknown status filter.', 400);
            return;
        }

        $conds = [['assignedTo', '==', $me]];
        if (count($statuses) === 1) {
            $conds[] = ['status', '==', reset($statuses)];
        } else {
            $conds[] = ['status', 'in', array_values($statuses)];
        }
        // R17: same fix as get_queue — no cast, so the cursor's type matches
        // lastMessageAt's stored type.
        if ($cursor !== null && $cursor !== '' && $cursor !== 0) {
            $conds[] = ['lastMessageAt', '<', $cursor];
        }

        $docs = $this->fs->schoolWhere('supportTickets', $conds, 'lastMessageAt', 'DESC', $limit);
        // B3: where() returns [] on failure as well as on genuine emptiness, so
        // this endpoint was answering a failed read with a confident "no tickets".
        // The client is written to prevent exactly this — support_desk.js's own
        // comment says a failure "must never read as 'no tickets'" — but it can
        // only act on a non-ok status or {status:'error'}, and the server was
        // handing it 200/success. Ask the query layer which it was.
        if (empty($docs) && $this->fs->lastQueryFailed()) {
            log_message('error', 'Support::get_mine — query failed; refusing to render an empty result as success');
            $this->json_error('Could not load your tickets. Please retry.', 503);
            return;
        }

        $page = $this->_page($this->_flatten($docs), $limit);
        $this->json_success($page + ['scope' => 'mine']);
    }

    /**
     * One thread: the ticket, its messages, and — for queue-holders only —
     * the staff-internal notes.
     *
     * supportNotes is a SEPARATE collection denied to every client by rules,
     * precisely so a parent can never reach it. It is served here only through
     * the Admin SDK, and only to staff who can read the ticket.
     */
    public function get_thread(string $ticketId = ''): void
    {
        $ticket = $this->_get_ticket($ticketId);
        if ($ticket === null) {
            $this->json_error('Ticket not found.', 404);
            return;
        }
        if (!$this->_may_read_ticket($ticket)) {
            $this->json_error('You do not have access to this ticket.', 403);
            return;
        }

        $tid = (string) ($ticket['ticketId'] ?? '');

        // B7: fetch the NEWEST MAX_LIMIT, not the oldest.
        //
        // This queried createdAt ASC with limit 100, so past 100 messages staff
        // saw the opening exchange and never the parent's most recent message —
        // with no indicator that anything was missing. On a long-running fee or
        // safeguarding dispute that is the one screen that must not lie by
        // omission, and it lies in the worst direction: the newest message is the
        // one being replied to.
        //
        // Ordered DESC here and reversed below, so the thread still renders
        // chronologically. Needs index [schoolId, ticketId, createdAt DESC] —
        // declared in firestore.indexes.json. Without it, query() silently drops
        // the orderBy and re-sorts an arbitrary page in PHP (A4's B1), which
        // would reintroduce exactly the same bug with no signal at all.
        $msgs = $this->fs->schoolWhere(
            'supportMessages',
            [['ticketId', '==', $tid]],
            'createdAt',
            'DESC',
            self::MAX_LIMIT
        );

        // B2: a failed query and a genuinely empty thread are INDISTINGUISHABLE
        // at this call site — Firestore_service::where() catches every error and
        // returns []. Before this guard, a transient failure on the messages
        // query produced HTTP 200, status:'success', a fully populated ticket
        // header, and messages:[]. The panel then rendered a real complaint with
        // an empty conversation, and every action on that screen — reply,
        // resolve, force-close — stayed enabled. A triager could close a
        // safeguarding ticket whose contents they had never seen.
        //
        // messageCount is the tripwire. Every ticket carries at least the opening
        // message, so an empty result against a non-zero count means the read
        // failed or the denormal has drifted. Either way the honest answer is an
        // error, not an empty thread: the one thing this screen must never do is
        // claim a parent said nothing.
        $expected = (int) ($ticket['messageCount'] ?? 0);
        if (empty($msgs) && $expected > 0) {
            log_message('error',
                'Support::get_thread — messages query returned empty for ' . $tid .
                ' but messageCount=' . $expected . '; failing closed rather than rendering an empty thread');
            $this->json_error(
                'Could not load this conversation. Nothing has been changed — please retry.', 503);
            return;
        }

        // Back to chronological for display. array_reverse, not a re-sort: the
        // server already ordered these, and re-sorting in PHP is what B1 does.
        $msgs = array_reverse($this->_flatten($msgs));

        // Tell the client when it is not seeing everything, so the thread can say
        // so instead of quietly presenting a partial conversation as complete.
        $truncated = count($msgs) >= self::MAX_LIMIT
                     && (int) ($ticket['messageCount'] ?? 0) > count($msgs);

        $anon = !empty($ticket['isAnonymous']);
        $messages = [];
        foreach ($msgs as $m) {
            if (!is_array($m)) continue;
            $isParent = ((string) ($m['senderType'] ?? '')) === 'parent';
            $messages[] = [
                'senderType'  => (string) ($m['senderType'] ?? ''),
                // An anonymous reporter's name is withheld on every parent-authored
                // message too, not just on the ticket header. Showing it here is
                // the obvious way to leak an identity that was promised withheld.
                'senderName'  => ($isParent && $anon) ? '' : (string) ($m['senderName'] ?? ''),
                'body'        => (string) ($m['body'] ?? ''),
                'attachments' => array_values((array) ($m['attachments'] ?? [])),
                'createdAt'   => $this->_ts($m['createdAt'] ?? null),
            ];
        }

        // Internal notes: queue-holders only. An assignee without the module
        // works the ticket but does not read colleagues' commentary about it.
        $notes = [];
        if ($this->_can('view')) {
            $raw = $this->fs->schoolWhere(
                'supportNotes',
                [['ticketId', '==', $tid]],
                'createdAt',
                'ASC',
                self::MAX_LIMIT
            );
            foreach ($this->_flatten($raw) as $n) {
                if (!is_array($n)) continue;
                $notes[] = [
                    'authorName' => (string) ($n['authorName'] ?? ''),
                    'body'       => (string) ($n['body'] ?? ''),
                    'createdAt'  => $this->_ts($n['createdAt'] ?? null),
                ];
            }
        }

        $this->json_success([
            'ticket'      => $this->_row($ticket) + [
                'sessionId'       => (string) ($ticket['sessionId'] ?? ''),
                'resolvedAt'      => $this->_ts($ticket['resolvedAt'] ?? null),
                'reopenableUntil' => $this->_ts($ticket['reopenableUntil'] ?? null),
                'closureReason'   => (string) ($ticket['closureReason'] ?? ''),
            ],
            // Filenames, not paths. The browser hands one back to
            // attachment() and the server rebuilds the path itself.
            'attachmentNames' => array_values(array_map(
                'strval', (array) ($ticket['attachments'] ?? [])
            )),
            'messages'    => $messages,
            // B7: true when the thread holds more messages than were returned.
            // The client must say so — a partial conversation presented as
            // complete is how a triager replies to the wrong thing.
            'truncated'   => $truncated,
            'total_messages' => (int) ($ticket['messageCount'] ?? count($messages)),
            'notes'       => $notes,
            'can_note'    => $this->_can('view'),
            'is_assignee' => $this->_is_assignee($ticket),
        ]);
    }

    /**
     * Keyword search.
     *
     * Uses index 7 [schoolId, keywords ARRAY_CONTAINS, lastMessageAt DESC].
     *
     * keywords[] is normalised at WRITE time from subject + student name +
     * ticketNo, because Firestore cannot substring-search. That index has no
     * `lane` column, so the lane filter cannot live in the query — it is
     * applied in _normal_only() instead, which fails closed.
     */
    public function search(): void
    {
        require_permission('Support', 'support_search', 'view');

        $q = strtolower(trim((string) $this->input->get('q', true)));
        if ($q === '' || mb_strlen($q) < 2) {
            $this->json_error('Enter at least two characters.', 400);
            return;
        }

        // One token per query — array-contains takes a single value. The first
        // token is the discriminating one for the "TKT-142" / surname cases
        // that make up almost all real searches.
        $token = preg_split('/\s+/', $q)[0];
        $token = preg_replace('/[^a-z0-9]/', '', $token);
        if ($token === '') {
            $this->json_error('Enter a searchable word or ticket number.', 400);
            return;
        }

        $limit = $this->_limit($this->input->get('limit', true));
        $docs  = $this->fs->schoolWhere(
            'supportTickets',
            [['keywords', 'array-contains', $token]],
            'lastMessageAt',
            'DESC',
            $limit
        );

        // B3: where() returns [] on failure as well as on genuine emptiness, so
        // this endpoint was answering a failed read with a confident "no results".
        // The client is written to prevent exactly this — support_desk.js's own
        // comment says a failure "must never read as 'no tickets'" — but it can
        // only act on a non-ok status or {status:'error'}, and the server was
        // handing it 200/success. Ask the query layer which it was.
        if (empty($docs) && $this->fs->lastQueryFailed()) {
            log_message('error', 'Support::search — query failed; refusing to render an empty result as success');
            $this->json_error('Could not load search results. Please retry.', 503);
            return;
        }

        $page = $this->_page($this->_flatten($docs), $limit);
        $this->json_success($page + ['q' => $q, 'token' => $token]);
    }

    // =========================================================================
    //  P2 · WRITES
    //
    //  Every method below follows the same six steps, in this order:
    //     1. POST only
    //     2. re-read the ticket SERVER-SIDE (never trust a posted status)
    //     3. gate — and record the denial if it fails
    //     4. write
    //     5. emit push (inert until P5 registers the marks; see _push())
    //     6. audit
    //
    //  Nothing here trusts a field the client sent about the ticket's current
    //  state. The browser posts an id and an intent; the server decides.
    // =========================================================================

    /** Reject anything that is not a POST. */
    private function _require_post(): bool
    {
        if (strtoupper((string) $this->input->method()) !== 'POST') {
            $this->json_error('POST required.', 405);
            return false;
        }
        return true;
    }

    /** Lazily load the append-only ledger. */
    private function _audit()
    {
        if (!isset($this->_audit_lib)) {
            $this->load->library('support_audit');
            $this->_audit_lib = $this->support_audit;
        }
        return $this->_audit_lib;
    }
    private $_audit_lib = null;

    /**
     * Load a ticket for mutation, or emit the JSON error and return null.
     *
     * $level is the module level that would grant access WITHOUT being the
     * assignee. Pass null when only the assignee may act.
     */
    private function _load_for_write(string $ticketId, ?string $level, string $what, bool $assigneeMayAct = true): ?array
    {
        $t = $this->_get_ticket($ticketId);
        if ($t === null) {
            $this->json_error('Ticket not found.', 404);
            return null;
        }
        if ((string) ($t['lane'] ?? self::LANE_NORMAL) !== self::LANE_NORMAL) {
            // The confidential lane is not reachable through this controller at
            // any permission level. Refuse before anything else can look at it.
            $this->_audit()->denied($ticketId, 'confidential_lane', ['action' => $what]);
            $this->json_error('This ticket is not available here.', 403);
            return null;
        }

        // $assigneeMayAct=false means the module level is the ONLY route in.
        // assign() and force_close() use it: being the assignee of a ticket must
        // not let you reassign it away or close it without 'manage'.
        $ok = ($assigneeMayAct && $this->_is_assignee($t))
              || ($level !== null && $this->_can($level));
        if (!$ok) {
            $this->_audit()->denied($ticketId, 'insufficient_permission', [
                'action' => $what, 'needed' => (string) $level,
            ]);
            $this->json_error('You do not have permission to do that on this ticket.', 403);
            return null;
        }
        return $t;
    }

    /** Read and validate a required free-text field. */
    private function _text(string $field, int $max = 5000, int $min = 1): ?string
    {
        // config global_xss_filtering is TRUE, so CI has already filtered this
        // regardless of the per-call flag. Output is escaped in the views; this
        // is defence in depth, not the only layer.
        $v = trim((string) $this->input->post($field, true));
        if (mb_strlen($v) < $min) {
            $this->json_error('Please write something first.', 400);
            return null;
        }
        if (mb_strlen($v) > $max) {
            $this->json_error('That is too long — keep it under ' . $max . ' characters.', 400);
            return null;
        }
        return $v;
    }

    /**
     * Append a message to a thread and move the ticket's denormals.
     *
     * messageCount uses a SERVER-SIDE increment transform, not read-modify-
     * write: two staff replying in the same second would otherwise both read
     * the same count and one write would be lost (E-18).
     */
    /** Document id written by the most recent _append_message(). See B6. */
    private string $_last_message_id = '';

    private function _append_message(array $t, string $senderType, string $body): bool
    {
        $tid  = (string) $t['ticketId'];
        $now  = date('c');

        // B4: an IDEMPOTENT message id, not a fresh random one.
        //
        // The REST client's cURL timeout is 15s. On a timeout curl_exec returns
        // false and the HTTP code is 0, so set() reports failure for a write that
        // may well have landed — and reply() then told the user, with complete
        // confidence, "Nothing was sent." With a random id per call, the staff
        // member's natural response (retype and resend) produced a SECOND copy of
        // the same message in the parent's thread.
        //
        // Deriving the id from the content makes the retry converge: the same
        // author sending the same body on the same ticket writes the SAME
        // document, so a retry overwrites rather than duplicates. The 5-minute
        // bucket keeps a genuine later repeat ("are you there?" twice in a day) a
        // distinct message, while collapsing the retry that happens seconds after
        // a timeout — which is the only window this failure occurs in.
        $bucket = (int) floor(time() / 300);
        $mid = $this->fs->docId2($tid, substr(hash('sha256', implode('|', [
            $tid,
            (string) ($this->admin_id ?? ''),
            $senderType,
            $body,
            (string) $bucket,
        ])), 0, 16));

        // Exposed so the caller can use it as a push dedupe key. It is already
        // idempotent (see above), which makes it the ideal event identity: stable
        // across a retry of the same reply, distinct for a genuinely new one.
        $this->_last_message_id = $mid;

        // Already written? Then this is the retry the idempotent id exists for.
        // Return success WITHOUT re-running the denormal patch or _bump_count —
        // overwriting the message but incrementing messageCount a second time
        // would trade a duplicate message for a corrupt count, which is worse:
        // the count is what the parent's thread and the queue badge both read,
        // and nothing would ever reconcile it.
        //
        // get() returns null on read failure as well as on absence, so a failed
        // existence check falls through to the write — the safe direction.
        if ($this->fs->get('supportMessages', $mid) !== null) {
            log_message('debug', 'Support::_append_message — idempotent hit for ' . $mid . '; not re-counting');
            return true;
        }

        $ok = $this->fs->set('supportMessages', $mid, [
            'schoolId'   => $this->school_id,
            'ticketId'   => $tid,
            // Denormalised so the parent's READ rule needs no get() — see C-05.
            'reporterId' => (string) ($t['reporterId'] ?? ''),
            'senderType' => $senderType,
            'senderId'   => (string) ($this->admin_id ?? ''),
            'senderName' => (string) ($this->admin_name ?? ''),
            'body'       => $body,
            'attachments'=> [],
            'createdAt'  => $now,
        ]);
        if (!$ok) return false;

        $patch = [
            'lastMessageAt' => $now,
            'updatedAt'     => $now,
            'updatedBy'     => (string) ($this->admin_id ?? ''),
        ];
        // A system message is bookkeeping, not a reply — it must not reset the
        // "awaiting us" clock, or an assignment would make a waiting parent
        // look answered.
        if ($senderType === 'staff') {
            $patch['lastStaffReplyAt'] = $now;
            if (empty($t['firstStaffReplyAt'])) {
                // Un-backfillable once missed, which is why it is captured from
                // the very first reply rather than when reporting is built.
                $patch['firstStaffReplyAt'] = $now;
            }
        }
        // The denormal patch. $tid already comes from $t['ticketId'] here, so B10
        // never applied — but the return was still discarded (W4), which meant a
        // failed patch left lastMessageAt stale and the ticket never surfaced as
        // touched. Not fatal to the message itself, so it logs and continues.
        $this->_patch_ticket($t, $patch, 'append_message_denormals');
        $this->_bump_count($tid);
        return true;
    }

    /** Atomic messageCount += 1, with an honest fallback. */
    private function _bump_count(string $ticketId): void
    {
        try {
            $c = $this->fs->raw_client();
            if ($c && method_exists($c, 'incrementDoc')) {
                $c->incrementDoc('supportTickets', $this->fs->docId($ticketId), ['messageCount' => 1]);
                return;
            }
        } catch (\Throwable $e) { /* fall through */ }
        // No transform available. A lost update here costs a wrong badge count,
        // never a lost message — the message document is already written.
        log_message('error', 'Support::_bump_count fell back to no-op for ' . $ticketId);
    }

    /**
     * Emit a push through the one door.
     *
     * These marks are NOT in MARK_REGISTRY until P5. That is deliberate and
     * safe: the dispatch CF ignores an unregistered mark and returns without
     * setting status, so the legacy poller's next sweep deletes the still-
     * `pending` document. No push, no accumulation, no garbage — and P5 turns
     * them on by adding registry rows, touching nothing here.
     */
    private function _push(string $mark, string $dedupeKey, array $fields): void
    {
        try { $this->emit_push($mark, $dedupeKey, $fields); }
        catch (\Throwable $e) { log_message('error', 'Support push ' . $mark . ': ' . $e->getMessage()); }
    }

    // ── assign ───────────────────────────────────────────────────────────────

    /**
     * Assign or reassign. Requires 'manage'.
     *
     * Eligibility is enforced HERE, not by filtering the picker. ServiceNow
     * documents exactly this failure: its assignee field is filtered only in
     * the UI, so integrations happily save an incident assigned outside its own
     * group. The dropdown is a convenience; this check is the rule.
     */
    public function assign(): void
    {
        if (!$this->_require_post()) return;

        $ticketId = (string) $this->input->post('ticket_id', true);
        $staffId  = (string) $this->input->post('staff_id', true);

        $t = $this->_load_for_write($ticketId, 'manage', 'assign', false);
        if ($t === null) return;

        // X1: assign() had no status check of any kind, while force_close (:1093),
        // resolve (:992) and reply (:913) all had one. So a CLOSED ticket could be
        // assigned: status flipped back to 'assigned' while closedAt and
        // closureReason stayed on the document, and onSupportTicketStatusChanged
        // saw inactive→active and spent one of the reporter's five cap slots on a
        // ticket they had been told was finished.
        if ((string) ($t['status'] ?? '') === 'closed') {
            $this->json_error('This ticket is closed. Reopen it before assigning.', 409);
            return;
        }

        if ($staffId === '' || !preg_match('/^[A-Za-z0-9_-]{1,32}$/', $staffId)) {
            $this->json_error('Pick a staff member.', 400);
            return;
        }

        $staff = $this->fs->get('staff', $this->fs->docId($staffId));
        if (!is_array($staff) || empty($staff)) {
            $this->json_error('That staff member no longer exists.', 400);
            return;
        }
        // Same status rule Admin_login enforces, so we never assign to someone
        // who cannot sign in to work it.
        $status = (string) ($staff['status'] ?? $staff['Status'] ?? 'Active');
        if (strcasecmp(trim($status), 'Active') !== 0) {
            $this->json_error('That staff member is not active.', 400);
            return;
        }

        $prev = (string) ($t['assignedTo'] ?? '');
        $name = (string) ($staff['name'] ?? $staff['Name'] ?? $staffId);
        $now  = date('c');

        if (!$this->_patch_ticket($t, [
            'assignedTo'   => $staffId,
            'assignedName' => $name,     // snapshot — survives the staff member leaving
            'status'       => 'assigned',
            'updatedAt'    => $now,
            'updatedBy'    => (string) ($this->admin_id ?? ''),
        ], 'assign')) {
            $this->json_error('Could not assign this ticket. Nothing has been changed.', 500);
            return;
        }

        $t['assignedTo'] = $staffId;
        $this->_append_message($t, 'system',
            ($prev === '' ? 'Assigned to ' : 'Reassigned to ') . $name .
            ' by ' . (string) ($this->admin_name ?? 'an administrator') . '.');

        // B6: the key must identify this ASSIGNMENT, not this (ticket, staff) pair.
        // Keyed on the pair alone, assigning to A, returning to the queue, then
        // assigning to A again produced the SAME key — emit_push writes with
        // merge=false, the REST client falls back to PATCH on 409, and the
        // dispatcher is onDocumentCreated, so the second push simply never fired.
        // A never learned they held the ticket. $now is the recorded assignment
        // time, so retries inside one request share a key and separate
        // assignments do not.
        $this->_push('TICKET_ASSIGNED', 'sup_asg_' . (string) $t['ticketId'] . '_' . $staffId . '_' . $now, [
            'recipientStaffIds' => [$staffId],
            'ticketId'          => $ticketId,
            'category'          => (string) ($t['category'] ?? ''),
        ]);

        $this->_audit()->record($prev === '' ? 'ASSIGNED' : 'REASSIGNED', $ticketId, [
            'from' => $prev, 'to' => $staffId, 'status' => 'assigned',
        ]);

        $this->json_success(['assignedTo' => $staffId, 'assignedName' => $name, 'status' => 'assigned']);
    }

    // ── reply ────────────────────────────────────────────────────────────────

    /** Reply to the parent. Assignee, or anyone with 'edit'. */
    public function reply(): void
    {
        if (!$this->_require_post()) return;

        $ticketId = (string) $this->input->post('ticket_id', true);
        $t = $this->_load_for_write($ticketId, 'edit', 'reply');
        if ($t === null) return;

        if ((string) ($t['status'] ?? '') === 'closed') {
            $this->json_error('This ticket is closed. Reopen it before replying.', 409);
            return;
        }

        $body = $this->_text('body');
        if ($body === null) return;

        if (!$this->_append_message($t, 'staff', $body)) {
            // Deliberately does NOT claim nothing was sent. A cURL timeout is
            // indeterminate — the write may have landed. Telling staff it
            // definitely failed is what made them resend. The id is idempotent
            // now, so a resend is safe, but the message should still be honest.
            $this->json_error(
                'Could not confirm your reply was saved. Reload the thread before resending — '
                . 'if it is already there, it went through.', 500);
            return;
        }

        // P-08: time() in a dedupe key defeats the key — emit_push writes
        // pushRequests/{schoolId}_{key}, and a stable key is exactly what makes
        // the send idempotent by overwrite. The message id is already
        // content-derived (B4), so it is stable for a retry and unique per reply.
        $this->_push('TICKET_REPLIED', 'sup_rep_' . $this->_last_message_id, [
            'recipientIds' => [(string) ($t['reporterId'] ?? '')],
            'ticketId'     => $ticketId,
            'senderName'   => (string) ($this->admin_name ?? 'School'),
            'preview'      => mb_substr($body, 0, 160),
        ]);

        $this->_audit()->record('REPLIED', $ticketId, ['chars' => mb_strlen($body)]);
        $this->json_success(['appended' => true]);
    }

    // ── internal note ────────────────────────────────────────────────────────

    /**
     * Add a staff-internal note. Requires the module at 'view'.
     *
     * Notes go to supportNotes — a SEPARATE collection denied to every client —
     * rather than a flag on supportMessages. One bad rule on a flag would show
     * staff commentary to the parent it is about; a separate denied collection
     * makes that structurally impossible rather than merely prevented.
     *
     * Deliberately does NOT move lastMessageAt or emit a push to the parent.
     */
    public function add_note(): void
    {
        if (!$this->_require_post()) return;

        $ticketId = (string) $this->input->post('ticket_id', true);
        $t = $this->_get_ticket($ticketId);
        if ($t === null) { $this->json_error('Ticket not found.', 404); return; }

        if (!$this->_can('view')) {
            $this->_audit()->denied($ticketId, 'insufficient_permission', ['action' => 'add_note']);
            $this->json_error('You do not have permission to add notes.', 403);
            return;
        }

        $body = $this->_text('body', 2000);
        if ($body === null) return;

        $nid = $this->fs->docId2($ticketId, bin2hex(random_bytes(8)));
        $ok  = $this->fs->set('supportNotes', $nid, [
            'schoolId'   => $this->school_id,
            'ticketId'   => $ticketId,
            'authorId'   => (string) ($this->admin_id ?? ''),
            'authorName' => (string) ($this->admin_name ?? ''),
            'body'       => $body,
            'createdAt'  => date('c'),
        ]);
        if (!$ok) { $this->json_error('Could not save the note.', 500); return; }

        $this->_audit()->record('NOTE_ADDED', $ticketId, ['chars' => mb_strlen($body)]);
        $this->json_success(['added' => true]);
    }

    // ── resolve ──────────────────────────────────────────────────────────────

    /** Resolve with a required closing message. Assignee, or 'manage'. */
    public function resolve(): void
    {
        if (!$this->_require_post()) return;

        $ticketId = (string) $this->input->post('ticket_id', true);
        $t = $this->_load_for_write($ticketId, 'manage', 'resolve');
        if ($t === null) return;

        $cur = (string) ($t['status'] ?? '');
        if ($cur === 'resolved' || $cur === 'closed') {
            $this->json_error('This ticket is already ' . $cur . '.', 409);
            return;
        }

        // Required, not optional. "Resolved" with no explanation is the single
        // biggest driver of reopens.
        $body = $this->_text('body', 5000);
        if ($body === null) return;

        $now = time();
        // B5: the return value is checked, not discarded. The comment above calls
        // this message "required, not optional -- the single biggest driver of
        // reopens", and the code then dropped the boolean on the floor: a failed
        // write still flipped status to resolved, still pushed TICKET_RESOLVED to
        // the parent, still wrote RESOLVED to the append-only ledger, and still
        // returned 200. The parent got "your ticket was resolved" with no
        // explanation attached, and the ledger recorded an explanation that does
        // not exist. Fail closed instead: no message, no resolution.
        if (!$this->_append_message($t, 'staff', $body)) {
            $this->json_error('Could not save the closing message. The ticket has NOT been resolved.', 500);
            return;
        }
        if (!$this->_patch_ticket($t, [
            'status'          => 'resolved',
            'resolvedAt'      => date('c', $now),
            // 7-day reopen window. Past it the parent raises a new ticket
            // instead, which keeps "resolved" meaning something.
            'reopenableUntil' => date('c', $now + (7 * 86400)),
            'updatedAt'       => date('c', $now),
            'updatedBy'       => (string) ($this->admin_id ?? ''),
        ], 'resolve')) {
            $this->json_error('Could not resolve this ticket. The closing message was saved; the status is unchanged.', 500);
            return;
        }

        // B6: keyed on the ticket alone, a SECOND resolve (after the parent reopened
        // it) collided with the first and sent nothing — the parent was never told
        // it had been resolved again. resolvedAt is this resolution's own identity.
        // A retry cannot duplicate: resolve() 409s on an already-resolved ticket
        // before reaching this line.
        $this->_push('TICKET_RESOLVED', 'sup_res_' . (string) $t['ticketId'] . '_' . $now, [
            'recipientIds' => [(string) ($t['reporterId'] ?? '')],
            'ticketId'     => $ticketId,
        ]);

        $this->_audit()->record('RESOLVED', $ticketId, ['from' => $cur, 'to' => 'resolved']);
        $this->json_success(['status' => 'resolved']);
    }

    // ── return to queue ──────────────────────────────────────────────────────

    /**
     * Hand a wrongly-routed ticket back. ASSIGNEE ONLY — note the null level.
     *
     * Without this, a misrouted ticket dead-ends: resolving it would be a lie
     * and reassigning needs 'manage', which the assignee does not have. The
     * reason is mandatory because it is the only signal the admin ever gets
     * that their routing was wrong — and in a system with no category routing
     * yet, those reasons are the evidence that eventually builds one.
     */
    public function return_to_queue(): void
    {
        if (!$this->_require_post()) return;

        $ticketId = (string) $this->input->post('ticket_id', true);
        $t = $this->_load_for_write($ticketId, null, 'return_to_queue');
        if ($t === null) return;

        // X2/X3: return_to_queue had no status check either, and it is the
        // LOWEST-privilege mutator in the module — _load_for_write is called with
        // $level = null, so the module-permission arm can never fire and being the
        // assignee is the entire gate. Someone holding no Support grant at all
        // could therefore move a closed or resolved ticket back to 'open'.
        //
        // _close_one deliberately preserves assignedTo (so `mine` still shows what
        // you closed), which left every force-closed ticket that had an assignee
        // permanently eligible. On a resolved ticket it also stranded resolvedAt
        // and reopenableUntil on a document whose status was 'open'.
        $cur = (string) ($t['status'] ?? '');
        if ($cur === 'closed' || $cur === 'resolved') {
            $this->json_error(
                $cur === 'closed'
                    ? 'This ticket is closed. It cannot be returned to the queue.'
                    : 'This ticket is resolved. Reopen it before returning it to the queue.',
                409
            );
            return;
        }

        if ((string) ($t['assignedTo'] ?? '') === '') {
            $this->json_error('This ticket is not assigned to anyone.', 409);
            return;
        }

        $reason = $this->_text('reason', 500);
        if ($reason === null) return;

        $who = (string) ($this->admin_name ?? 'A staff member');
        // B5: fail closed. Returning a ticket to the queue without the system
        // message that says who did it and why leaves the next triager holding an
        // unassigned ticket with no history of how it got there.
        if (!$this->_append_message($t, 'system', $who . ' returned this to the queue — ' . $reason)) {
            $this->json_error('Could not record the reason. The ticket has NOT been returned to the queue.', 500);
            return;
        }

        $now = date('c');
        if (!$this->_patch_ticket($t, [
            'assignedTo'   => '',
            'assignedName' => '',
            'status'       => 'open',
            'updatedAt'    => $now,
            'updatedBy'    => (string) ($this->admin_id ?? ''),
        ], 'return_to_queue')) {
            $this->json_error('Could not return this ticket to the queue. Nothing has been changed.', 500);
            return;
        }

        // Back to the desk, not to nobody.
        // recipientStaffIds is REQUIRED: TICKET_RAISED declares it as its idField
        // (functions/index.js MARK_REGISTRY). Without it the dispatcher resolves
        // zero recipients and the push goes nowhere — so "back to the desk" went
        // to nobody, silently, every time an assignee handed a ticket back.
        // The desk is whoever holds the Support module, same set the Cloud
        // Function notifies when a ticket is first raised.
        $this->_push('TICKET_RAISED', 'sup_ret_' . (string) $t['ticketId'] . '_' . $now, [
            'recipientStaffIds' => $this->_desk_recipients(),
            'ticketId' => $ticketId,
            'subject'  => (string) ($t['subject'] ?? ''),
            'category' => (string) ($t['category'] ?? ''),
        ]);

        $this->_audit()->record('RETURNED', $ticketId, [
            'from' => (string) ($t['assignedTo'] ?? ''), 'reason' => $reason,
        ]);
        $this->json_success(['status' => 'open']);
    }

    // ── force close ──────────────────────────────────────────────────────────

    /** Force-close with a recorded reason. Requires 'manage'. */
    public function force_close(): void
    {
        if (!$this->_require_post()) return;

        $ticketId = (string) $this->input->post('ticket_id', true);
        $t = $this->_load_for_write($ticketId, 'manage', 'force_close', false);
        if ($t === null) return;

        if ((string) ($t['status'] ?? '') === 'closed') {
            $this->json_error('Already closed.', 409);
            return;
        }

        $reason = $this->_text('reason', 500);
        if ($reason === null) return;

        if (!$this->_close_one($t, $reason)) {
            $this->json_error('Could not record the closure reason. The ticket has NOT been closed.', 500);
            return;
        }
        $this->json_success(['status' => 'closed']);
    }

    /**
     * Bulk force-close. Requires 'manage'.
     *
     * The minimum honest answer to a mass event until incident mode exists.
     * Without it, "accepted risk" means an admin clicking three hundred times.
     * Capped so one request cannot become an unbounded write loop.
     */
    public function bulk_force_close(): void
    {
        if (!$this->_require_post()) return;
        if (!$this->_can('manage')) {
            $this->json_error('You do not have permission to close tickets.', 403);
            return;
        }

        $ids = $this->input->post('ticket_ids', true);
        if (!is_array($ids) || !$ids) { $this->json_error('Select at least one ticket.', 400); return; }
        if (count($ids) > 50) { $this->json_error('Close at most 50 at a time.', 400); return; }

        $reason = $this->_text('reason', 500);
        if ($reason === null) return;

        $closed = 0; $skipped = [];
        foreach ($ids as $raw) {
            $t = $this->_get_ticket((string) $raw);
            if ($t === null
                || (string) ($t['lane'] ?? self::LANE_NORMAL) !== self::LANE_NORMAL
                || (string) ($t['status'] ?? '') === 'closed') {
                $skipped[] = (string) $raw;
                continue;
            }
            if (!$this->_close_one($t, $reason)) { $skipped[] = (string) $raw; continue; }
            $closed++;
        }

        // Report what did NOT happen as well as what did — a bulk action that
        // silently skips is how an admin believes 300 tickets are closed.
        $this->json_success(['closed' => $closed, 'skipped' => $skipped]);
    }

    /**
     * Patch a ticket, from the AUTHORISED DOCUMENT, and report whether it worked.
     *
     * Two bug classes are closed by the signature alone.
     *
     * B10 — it takes the ticket array, never an id. `_get_ticket()` trims a LOCAL
     * copy of its argument, so the caller's $ticketId stays untrimmed; four
     * mutators then built the document id from that raw request value. Firestore
     * PATCH upserts, so `resolve()` on " TKT_ABC " wrote status:'resolved' into a
     * brand-new GHOST document, returned 200, and left the real ticket open —
     * while the closing message, which correctly used $t['ticketId'], landed on
     * the real thread. Taking the document makes the wrong id unexpressible.
     *
     * W4 — Firestore_service::update() returns bool and every Support call site
     * discarded it. A transient failure meant the status never changed while the
     * audit ledger recorded the action, the push went out, and the endpoint
     * returned success. Checked in one place instead of five.
     */
    private function _patch_ticket(array $t, array $fields, string $what): bool
    {
        $tid = (string) ($t['ticketId'] ?? '');
        if ($tid === '') {
            log_message('error', 'Support::_patch_ticket — ticket has no ticketId; refusing to write (' . $what . ')');
            return false;
        }
        $ok = $this->fs->update('supportTickets', $this->fs->docId($tid), $fields);
        if (!$ok) {
            log_message('error', 'Support::_patch_ticket — ' . $what . ' failed for ' . $tid
                . '; fields=' . implode(',', array_keys($fields)));
        }
        return $ok;
    }

    /**
     * Shared close path, so single and bulk cannot drift apart.
     *
     * Returns false when the ticket was NOT closed. It used to return void, so a
     * failed closing message was invisible to both callers (B5): the status
     * flipped, the ledger recorded FORCE_CLOSED, and the caller reported success.
     */
    private function _close_one(array $t, string $reason): bool
    {
        $ticketId = (string) $t['ticketId'];
        $prev     = (string) ($t['status'] ?? '');
        $who      = (string) ($this->admin_name ?? 'An administrator');

        // B5: a close with no recorded reason is exactly the state the closure
        // reason exists to prevent, and force_close is the one action that ends a
        // parent's complaint without their agreement. Refuse rather than close
        // silently; the caller reports it (bulk_force_close lists it in skipped[]).
        if (!$this->_append_message($t, 'system', $who . ' closed this ticket — ' . $reason)) {
            log_message('error', 'Support::_close_one — closing message failed for ' . $ticketId . '; ticket left open');
            return false;
        }
        $now = date('c');
        if (!$this->_patch_ticket($t, [
            'status'        => 'closed',
            'closedAt'      => $now,
            'closureReason' => $reason,
            'updatedAt'     => $now,
            'updatedBy'     => (string) ($this->admin_id ?? ''),
        ], 'force_close')) {
            return false;
        }

        // B16: tell the parent. This is the one action that ends their complaint
        // without their agreement, and it emitted nothing — they found out by
        // reopening the app and noticing the thread had closed. resolve() has
        // always pushed; closing is strictly more consequential.
        //
        // Keyed on the ticket AND this closure's own timestamp, so a second close
        // after a reopen is a distinct event rather than a silent collision (B6).
        $this->_push('TICKET_CLOSED', 'sup_cls_' . $ticketId . '_' . $now, [
            'ticketId'      => $ticketId,
            'recipientIds'  => [(string) ($t['reporterId'] ?? '')],
            'closureReason' => $reason,
        ]);
        $this->_audit()->record('FORCE_CLOSED', $ticketId, [
            'from' => $prev, 'to' => 'closed', 'reason' => $reason,
        ]);

        return true;
    }

    /**
     * Serve one attachment, by REDIRECTING to a short-lived signed URL.
     *
     * ─────────────────────────────────────────────────────────────────────
     *  THIS IS THE SECOND HALF OF THE ATTACHMENT CONTROL. THE RULES ARE THE
     *  FIRST HALF, AND THEY DO NOT COVER THIS PATH.
     * ─────────────────────────────────────────────────────────────────────
     * The panel reads and signs through the Admin SDK, which bypasses
     * firestore.rules AND storage.rules entirely. So a rules fix alone would
     * have left a cross-tenant read primitive sitting right here: hand the
     * panel a path pointing at another school and it would sign it happily.
     *
     * The control is not validation — it is construction. This endpoint never
     * accepts a path. It accepts a ticket id and a bare filename, then:
     *
     *   1. loads the ticket and re-checks the caller may read it,
     *   2. refuses anything that is not a bare basename (no slashes, no "..",
     *      no scheme),
     *   3. refuses any name the TICKET ITSELF does not declare, and
     *   4. builds `schools/{schoolId}/support/{ticketId}/{name}` from the
     *      ticket it just authorised — never from anything the caller sent.
     *
     * Step 3 is the one that matters most. Shape validation alone would still
     * let a caller read `1.jpg` from a ticket they can see, guessing at files
     * that were never attached to it.
     */
    public function attachment(string $ticketId = '', string $name = ''): void
    {
        $ticket = $this->_get_ticket($ticketId);
        if ($ticket === null) { show_404(); return; }

        if (!$this->_may_read_ticket($ticket)) {
            $this->_audit()->denied($ticketId, 'insufficient_permission', ['action' => 'attachment']);
            show_error('You do not have access to this ticket.', 403);
            return;
        }

        // A bare basename. Anything with a separator, a traversal segment or a
        // scheme is refused outright rather than sanitised — sanitising invites
        // a bypass, refusing does not.
        $name = trim($name);
        if ($name === '' || !preg_match('/^[A-Za-z0-9_-]{1,40}\.(jpg|jpeg|png)$/i', $name)) {
            $this->_audit()->denied($ticketId, 'bad_attachment_name', ['name' => $name]);
            show_404();
            return;
        }

        // Must be one the ticket actually declares.
        $declared = array_map('strval', (array) ($ticket['attachments'] ?? []));
        if (!in_array($name, $declared, true)) {
            $this->_audit()->denied($ticketId, 'attachment_not_on_ticket', ['name' => $name]);
            show_404();
            return;
        }

        // Built here, from the authorised ticket. Never from request input.
        //
        // The reporter id is part of the path. Storage rules cannot read the
        // ticket to check ownership — attachments upload BEFORE the ticket
        // document is created — so the owner is carried as a path segment and
        // the rule compares it to request.auth.uid. Taken from the ticket this
        // method has already authorised, never from the request.
        $reporterId = (string) ($ticket['reporterId'] ?? '');
        if ($reporterId === '') {
            log_message('error', 'Support::attachment — ticket ' . $ticketId . ' has no reporterId');
            show_404();
            return;
        }
        $path = 'schools/' . $this->school_id . '/support/' . $reporterId . '/' . $ticketId . '/' . $name;

        try {
            $object = $this->firebase->getStorageBucket()->object($path);
            if (!$object->exists()) { show_404(); return; }

            // Short TTL: long enough to render, short enough that a URL copied
            // out of devtools is useless by the time it is pasted anywhere.
            $url = $object->signedUrl(new \DateTime('+5 minutes'));
            redirect($url);
        } catch (\Throwable $e) {
            log_message('error', 'Support::attachment failed for ' . $path . ' — ' . $e->getMessage());
            show_error('Could not load that attachment.', 500);
        }
    }

    /**
     * Active staff, for the assignee picker.
     *
     * NOT filtered by RBAC on purpose: any staff member can be assigned a
     * ticket, because the no-grant tier lets them work it from My Tickets
     * without holding the module. Inactive staff ARE excluded, and assign()
     * re-checks that server-side regardless of what this returns.
     */
    public function get_assignees(): void
    {
        require_permission('Support', 'support_assign', 'manage');

        // _flatten() is load-bearing here, not cosmetic. Reading the WRAPPED rows
        // as flat produced three faults at once on device UAT 2026-08-28:
        //   1. `status` fell through to its 'Active' default, so EVERY staff row
        //      passed the active filter — including deactivated staff.
        //   2. `staffId` was absent but the wrapper's `id` was not, so the option
        //      value became the DOC id (SCH_..._STA0025) instead of STA0025.
        //      _is_assignee() compares against admin_id (STA0025), so an assigned
        //      ticket would never have matched its assignee and My Tickets would
        //      have stayed empty for the person actually holding the ticket.
        //   3. `name` fell back to that same id, so the picker listed raw ids.
        $rows = $this->_flatten($this->fs->schoolWhere('staff', [], 'name', 'ASC', 500));
        $out  = [];
        foreach ($rows as $s) {
            if (!is_array($s)) continue;
            $status = (string) ($s['status'] ?? $s['Status'] ?? 'Active');
            if (strcasecmp(trim($status), 'Active') !== 0) continue;
            $id = (string) ($s['staffId'] ?? '');
            if ($id === '') continue;
            $out[] = [
                'staffId'    => $id,
                'name'       => (string) ($s['name'] ?? $s['Name'] ?? $id),
                'department' => (string) ($s['department'] ?? $s['Department'] ?? ''),
            ];
        }
        // B3: an empty picker from a failed staff query looks identical to a
        // school with no active staff. The admin then cannot assign anyone and is
        // given no reason — and assign() would reject whatever they picked anyway.
        if (empty($rows) && $this->fs->lastQueryFailed()) {
            log_message('error', 'Support::get_assignees — staff query failed; refusing to return an empty picker as success');
            $this->json_error('Could not load the staff list. Please retry.', 503);
            return;
        }

        $this->json_success(['staff' => $out, 'count' => count($out)]);
    }
}
