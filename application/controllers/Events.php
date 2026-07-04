<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Events Controller — Event and Activity Management (Firestore-only).
 *
 * Sub-modules: Events (school/cultural/sports), Calendar, Participation.
 *
 * Firestore collections (auto-scoped via Firestore_service::docId):
 *   events/{schoolId}_{EVT0001}                              (apps read this)
 *   eventParticipants/{schoolId}_{EVT0001}_{participantId}
 *
 * Counter (Firestore opsCounters via operations_accounting::next_id):
 *   Schools/{school_name}/Events/Counters/Event
 */
class Events extends MY_Controller
{
    /** Roles for event management */
    private const MANAGE_ROLES = ['Super Admin', 'School Super Admin', 'Admin', 'Principal', 'Vice Principal', 'Academic Coordinator', 'Front Office'];

    /** Roles that may view events */
    private const VIEW_ROLES   = ['Super Admin', 'School Super Admin', 'Admin', 'Principal', 'Vice Principal', 'Academic Coordinator', 'Class Teacher', 'Teacher', 'Front Office'];

    const ADMIN_ROLES   = ['Super Admin', 'School Super Admin', 'Principal', 'Vice Principal', 'Admin'];
    const TEACHER_ROLES = ['Super Admin', 'School Super Admin', 'Principal', 'Vice Principal', 'Admin', 'Teacher'];

    const ALLOWED_CATEGORIES = ['event', 'cultural', 'sports'];
    const ALLOWED_STATUSES   = ['scheduled', 'ongoing', 'completed', 'cancelled'];
    const ALLOWED_PTYPES     = ['student', 'teacher'];
    const ALLOWED_PSTATUSES  = ['registered', 'attended', 'absent'];

    const MAX_TITLE_LENGTH = 200;
    const MAX_DESC_LENGTH  = 2000;

    const COL_EVENTS       = 'events';
    const COL_PARTICIPANTS = 'eventParticipants';

    public function __construct()
    {
        parent::__construct();
        require_permission('Events');
        $this->load->library('operations_accounting');
        $this->operations_accounting->init(
            $this->firebase, $this->school_name, $this->session_year, $this->admin_id, $this, $this->parent_db_key
        );
    }

    // ── Access helpers ──────────────────────────────────────────────────
    private function _require_admin()
    {
        if (!in_array($this->admin_role, self::ADMIN_ROLES, true))
            $this->_deny_access();
    }
    private function _require_teacher()
    {
        if (!in_array($this->admin_role, self::TEACHER_ROLES, true))
            $this->_deny_access();
    }
    private function _require_view()
    {
        if (!in_array($this->admin_role, self::VIEW_ROLES, true))
            $this->_deny_access();
    }

    private function _deny_access(): void
    {
        if ($this->input->is_ajax_request()) {
            $this->json_error('Access denied.', 403);
        }
        redirect(base_url('admin'));
    }

    // ── Validation helpers ──────────────────────────────────────────────
    private function _validate_length(string $value, string $field, int $max): string
    {
        if (mb_strlen($value) > $max) {
            $this->json_error("{$field} exceeds maximum length of {$max} characters.");
        }
        return $value;
    }

    private function _validate_enum(string $value, array $allowed, string $field): string
    {
        if (!in_array($value, $allowed, true)) {
            $this->json_error("Invalid {$field}.");
        }
        return $value;
    }

    /**
     * Strip control characters from text. Display layer (EV.esc in JS) handles HTML-encoding.
     */
    private function _clean_text(string $text): string
    {
        return preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $text);
    }

    private function _validate_date(string $date, string $field): string
    {
        if ($date !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            $this->json_error("Invalid {$field} format. Use YYYY-MM-DD.");
        }
        return $date;
    }

    /** Firestore opsCounters key — operations_accounting::next_id normalises path → docId. */
    private function _counter_path(string $type): string
    {
        return "Schools/{$this->school_name}/Events/Counters/{$type}";
    }

    private function _participant_docId(string $eventId, string $participantId): string
    {
        return $this->fs->docId2($eventId, $participantId);
    }

    /**
     * Flatten a firestoreQuery() result row. firestoreQuery returns rows shaped
     * ['id' => docId, 'data' => [...fields...]] — NOT flat. Every list/dashboard/
     * calendar/participant loop in this controller historically read fields at
     * the top level, so titles/dates/status/names all came back blank (and the
     * dashboard/calendar rendered empty). This unwraps `data` and folds the doc
     * id in as `id`. Falls back to the row itself if it's already flat.
     */
    private function _row($doc): array
    {
        if (is_array($doc) && isset($doc['data']) && is_array($doc['data'])) {
            $d = $doc['data'];
            if (!isset($d['id']) && isset($doc['id'])) $d['id'] = $doc['id'];
            return $d;
        }
        return is_array($doc) ? $doc : [];
    }

    /**
     * Strip the "{schoolId}_" prefix from a full document id to recover the raw
     * entity id (e.g. "SCH_X_EVT0001" -> "EVT0001"). getEntity()/save/delete all
     * rebuild {schoolId}_{entityId}, so the client MUST receive the raw id, not
     * the full doc id — otherwise edit/delete/circular double-prefix and 404.
     */
    private function _entityId(string $docId): string
    {
        $prefix = $this->school_name . '_';
        if ($docId !== '' && strpos($docId, $prefix) === 0) {
            return substr($docId, strlen($prefix));
        }
        return $docId;
    }

    // ====================================================================
    //  PAGE ROUTES
    // ====================================================================

    public function index()
    {
        $this->_require_role(self::VIEW_ROLES, 'events_view');
        $this->_require_view();
        $data = ['active_tab' => 'dashboard'];
        $this->load->view('include/header', $data);
        $this->load->view('events/index', $data);
        $this->load->view('include/footer');
    }

    public function list()
    {
        $this->_require_role(self::VIEW_ROLES, 'events_view');
        $this->_require_view();
        $data = ['active_tab' => 'events'];
        $this->load->view('include/header', $data);
        $this->load->view('events/events', $data);
        $this->load->view('include/footer');
    }

    public function calendar()
    {
        $this->_require_role(self::VIEW_ROLES, 'events_view');
        $this->_require_view();
        $data = ['active_tab' => 'calendar'];
        $this->load->view('include/header', $data);
        $this->load->view('events/calendar', $data);
        $this->load->view('include/footer');
    }

    public function participation()
    {
        $this->_require_role(self::VIEW_ROLES, 'events_view');
        $this->_require_view();
        $data = ['active_tab' => 'participation'];
        $this->load->view('include/header', $data);
        $this->load->view('events/participation', $data);
        $this->load->view('include/footer');
    }

    /** Circular / advertisement page for an event (standalone printable). */
    public function circular(string $eventId = '')
    {
        $this->_require_role(self::VIEW_ROLES, 'events_view');
        $this->_require_view();

        if ($eventId === '') redirect(base_url('events/list'));

        $event = $this->fs->getEntity(self::COL_EVENTS, $eventId);
        if (!is_array($event)) {
            redirect(base_url('events/list'));
        }
        $event['id'] = $eventId;

        // School header info — Firestore `schools/{schoolId}` holds profile + logo.
        $schoolDisplay = $this->school_display_name ?: $this->school_name;
        $schoolLogo    = '';
        try {
            $schoolDoc = $this->fs->get('schools', $this->fs->schoolId());
            if (is_array($schoolDoc)) {
                $schoolLogo = (string) ($schoolDoc['logo'] ?? '');
                if ($schoolLogo === '') {
                    $profile    = $schoolDoc['profile'] ?? ($schoolDoc['Profile'] ?? []);
                    $schoolLogo = is_array($profile) ? (string) ($profile['logo'] ?? '') : '';
                }
            }
        } catch (\Exception $e) {
            log_message('error', 'Events::circular school logo lookup failed: ' . $e->getMessage());
        }

        // Participant count — query eventParticipants by eventId.
        $participants = $this->firebase->firestoreQuery(self::COL_PARTICIPANTS, [
            ['schoolId', '==', $this->school_name],
            ['eventId',  '==', $eventId],
        ]);
        $pCount = is_array($participants) ? count($participants) : 0;

        $data = [
            'event'             => $event,
            'school_name'       => $schoolDisplay,
            'school_logo'       => $schoolLogo,
            'participant_count' => $pCount,
        ];

        // Standalone page — no header/footer
        $this->load->view('events/circular', $data);
    }

    // ====================================================================
    //  DASHBOARD
    // ====================================================================

    public function get_dashboard()
    {
        $this->_require_role(self::VIEW_ROLES, 'events_view');
        $this->_require_view();

        $rows = $this->firebase->firestoreQuery(self::COL_EVENTS,
            [['schoolId', '==', $this->school_name]], 'startDate', 'DESC');

        $total = 0; $upcoming = 0; $ongoing = 0; $completed = 0; $cancelled = 0;
        $upcomingEvents = [];
        $activeEventIds = [];

        foreach ((array) $rows as $doc) {
            $e = $this->_row($doc);
            $id = (string) ($e['eventId'] ?? $this->_entityId((string) ($e['id'] ?? '')));
            if ($id === '') continue;
            $total++;
            $s = $e['status'] ?? '';
            if ($s === 'scheduled') {
                $upcoming++;
                $e['id']         = $id;
                $e['start_date'] = $e['start_date'] ?? $e['startDate'] ?? '';
                $e['end_date']   = $e['end_date']   ?? $e['endDate']   ?? '';
                $upcomingEvents[] = $e;
            } elseif ($s === 'ongoing')   $ongoing++;
              elseif ($s === 'completed') $completed++;
              elseif ($s === 'cancelled') $cancelled++;
            if ($s === 'scheduled' || $s === 'ongoing') {
                $activeEventIds[] = $id;
            }
        }
        usort($upcomingEvents, fn($a, $b) => strcmp($a['start_date'] ?? '', $b['start_date'] ?? ''));
        $upcomingEvents = array_slice($upcomingEvents, 0, 5);

        // Recent participants — bound scan to 10 most recent active events.
        $activeEventIds = array_slice($activeEventIds, 0, 10);
        $recentParticipants = [];
        foreach ($activeEventIds as $evtId) {
            $prows = $this->firebase->firestoreQuery(self::COL_PARTICIPANTS, [
                ['schoolId', '==', $this->school_name],
                ['eventId',  '==', $evtId],
            ]);
            foreach ((array) $prows as $pdoc) {
                $p = $this->_row($pdoc);
                $pid = (string) ($p['participantId'] ?? $p['id'] ?? '');
                if ($pid === '') continue;
                $p['event_id'] = $evtId;
                $p['id']       = $pid;
                $recentParticipants[] = $p;
            }
        }
        usort($recentParticipants, fn($a, $b) => strcmp($b['registration_date'] ?? '', $a['registration_date'] ?? ''));
        $recentParticipants = array_slice($recentParticipants, 0, 10);

        $this->json_success([
            'total'               => $total,
            'upcoming'            => $upcoming,
            'ongoing'             => $ongoing,
            'completed'           => $completed,
            'cancelled'           => $cancelled,
            'upcoming_events'     => $upcomingEvents,
            'recent_participants' => $recentParticipants,
        ]);
    }

    // ====================================================================
    //  EVENTS CRUD
    // ====================================================================

    public function get_events()
    {
        $this->_require_role(self::VIEW_ROLES, 'events_view');
        $this->_require_view();
        $category = trim($this->input->get('category') ?? '');
        $status   = trim($this->input->get('status') ?? '');

        if ($category !== '' && !in_array($category, self::ALLOWED_CATEGORIES, true)) {
            $this->json_error('Invalid category filter.');
        }
        if ($status !== '' && !in_array($status, self::ALLOWED_STATUSES, true)) {
            $this->json_error('Invalid status filter.');
        }

        // Query by schoolId only (single-field, no extra composite index needed);
        // category/status are filtered in PHP below so the tabs work without a
        // per-combination composite index (event volumes per school are small).
        $rows = $this->firebase->firestoreQuery(self::COL_EVENTS,
            [['schoolId', '==', $this->school_name]], 'startDate', 'DESC');
        $list = [];
        foreach ((array) $rows as $doc) {
            $e  = $this->_row($doc);                                   // unwrap ['id'=>,'data'=>]
            $id = (string) ($e['eventId'] ?? $this->_entityId((string) ($e['id'] ?? '')));
            if ($id === '') continue;
            if ($category !== '' && (string) ($e['category'] ?? '') !== $category) continue;
            if ($status   !== '' && (string) ($e['status']   ?? '') !== $status)   continue;
            // Normalize field names for JS which expects id/start_date/end_date.
            $e['id']         = $id;
            $e['start_date'] = $e['start_date'] ?? $e['startDate'] ?? '';
            $e['end_date']   = $e['end_date']   ?? $e['endDate']   ?? '';
            $list[] = $e;
        }

        $page  = max(1, (int) ($this->input->get('page') ?? 1));
        $limit = min(100, max(1, (int) ($this->input->get('limit') ?? 50)));
        $total = count($list);
        $list  = array_slice($list, ($page - 1) * $limit, $limit);

        $this->json_success(['events' => $list, 'page' => $page, 'limit' => $limit, 'total' => $total]);
    }

    public function get_event()
    {
        $this->_require_role(self::VIEW_ROLES, 'events_view');
        $this->_require_view();
        $id = $this->safe_path_segment(trim($this->input->get('id') ?? ''), 'event_id');
        $event = $this->fs->getEntity(self::COL_EVENTS, $id);
        if (!is_array($event)) $this->json_error('Event not found.');
        $event['id']         = $id;
        $event['start_date'] = $event['start_date'] ?? $event['startDate'] ?? '';
        $event['end_date']   = $event['end_date']   ?? $event['endDate']   ?? '';

        // Participant count
        $pRows = $this->firebase->firestoreQuery(self::COL_PARTICIPANTS, [
            ['schoolId', '==', $this->school_name],
            ['eventId',  '==', $id],
        ]);
        $event['participant_count'] = is_array($pRows) ? count($pRows) : 0;

        $this->json_success(['event' => $event]);
    }

    public function save_event()
    {
        $this->_require_role(self::MANAGE_ROLES, 'save_event');
        $this->_require_admin();
        $id              = trim($this->input->post('id') ?? '');
        $title           = trim($this->input->post('title') ?? '');
        $description     = trim($this->input->post('description') ?? '');
        $category        = trim($this->input->post('category') ?? 'event');
        $location        = trim($this->input->post('location') ?? '');
        $startDate       = trim($this->input->post('start_date') ?? '');
        $endDate         = trim($this->input->post('end_date') ?? '');
        $organizer       = trim($this->input->post('organizer') ?? '');
        $maxParticipants = (int) ($this->input->post('max_participants') ?? 0);
        $status          = trim($this->input->post('status') ?? 'scheduled');

        if ($title === '') $this->json_error('Title is required.');
        if ($startDate === '') $this->json_error('Start date is required.');

        $this->_validate_length($title, 'Title', self::MAX_TITLE_LENGTH);
        $this->_validate_length($description, 'Description', self::MAX_DESC_LENGTH);
        $this->_validate_enum($category, self::ALLOWED_CATEGORIES, 'category');
        $this->_validate_enum($status, self::ALLOWED_STATUSES, 'status');
        $this->_validate_date($startDate, 'start date');
        if ($endDate !== '') {
            $this->_validate_date($endDate, 'end date');
            if ($endDate < $startDate) $this->json_error('End date cannot be before start date.');
        }
        if ($maxParticipants < 0) $this->json_error('Max participants cannot be negative.');

        $title       = $this->_clean_text($title);
        $description = $this->_clean_text($description);
        $location    = $this->_clean_text($location);
        $organizer   = $this->_clean_text($organizer);

        $isNew = ($id === '');
        if ($isNew) {
            $id = $this->operations_accounting->next_id($this->_counter_path('Event'), 'EVT');
        } else {
            $id = $this->safe_path_segment($id, 'event_id');
            $existing = $this->fs->getEntity(self::COL_EVENTS, $id);
            if (!is_array($existing)) $this->json_error('Event not found.');
        }

        $effectiveEnd = $endDate !== '' ? $endDate : $startDate;

        // Canonical Firestore doc — includes both snake_case (legacy admin JS)
        // and camelCase (app EventDoc) field names.
        $data = [
            'eventId'          => $id,
            'title'            => $title,
            'description'      => $description,
            'category'         => $category,
            'location'         => $location,
            'start_date'       => $startDate,
            'end_date'         => $effectiveEnd,
            'startDate'        => $startDate,
            'endDate'          => $effectiveEnd,
            'organizer'        => $organizer,
            'max_participants' => $maxParticipants,
            'status'           => $status,
            'mediaUrls'        => [],
            'updated_at'       => date('c'),
        ];

        if ($isNew) {
            $data['created_at']      = date('c');
            $data['createdAt']       = date('c');
            $data['created_by']      = $this->admin_id;
            $data['createdBy']       = $this->admin_id;
            $data['created_by_name'] = $this->admin_name;
        } else {
            $data['updated_by']      = $this->admin_id;
            $data['updated_by_name'] = $this->admin_name;
        }

        $this->fs->setEntity(self::COL_EVENTS, $id, $data, /* merge */ !$isNew);

        if ($isNew) {
            $this->_fire_event_notification($id, $data);
        }

        $this->json_success(['id' => $id, 'message' => $isNew ? 'Event created.' : 'Event updated.']);
    }

    public function delete_event()
    {
        $this->_require_role(self::MANAGE_ROLES, 'delete_event');
        $this->_require_admin();
        $id = $this->safe_path_segment(trim($this->input->post('id') ?? ''), 'event_id');

        $event = $this->fs->getEntity(self::COL_EVENTS, $id);
        if (!is_array($event)) $this->json_error('Event not found.');

        // Delete participants for this event. Resilient: a mid-loop failure
        // must not silently orphan the rest — collect failures and report.
        $participants     = $this->firebase->firestoreQuery(self::COL_PARTICIPANTS, [
            ['schoolId', '==', $this->school_name],
            ['eventId',  '==', $id],
        ]);
        $participantsTotal   = 0;
        $participantsRemoved = 0;
        $participantFailures = [];
        foreach ((array) $participants as $pdoc) {
            $p = $this->_row($pdoc);
            $pid = (string) ($p['participantId'] ?? $p['id'] ?? '');
            if ($pid === '') continue;
            $participantsTotal++;
            try {
                if ($this->fs->remove(self::COL_PARTICIPANTS, $this->_participant_docId($id, $pid))) {
                    $participantsRemoved++;
                } else {
                    $participantFailures[] = $pid;
                }
            } catch (\Throwable $e) {
                $participantFailures[] = $pid;
                log_message('error', "Events::delete_event participant cleanup failed [{$id}/{$pid}]: " . $e->getMessage());
            }
        }

        // ── Best-effort cascade (FIX-4): gallery RTDB node, Storage folder,
        //    and the linked notices doc. Each step is isolated so one failure
        //    never aborts the event delete; outcomes are reported back.
        $cascade = [
            'gallery_rtdb'    => null,
            'storage_folder'  => null,
            'notices_removed' => 0,
        ];

        // 1. RTDB gallery media node: Schools/{school}/Events/Media/{id}
        try {
            $cascade['gallery_rtdb'] = (bool) $this->firebase->delete(
                "Schools/{$this->school_name}/Events/Media/{$id}"
            );
        } catch (\Throwable $e) {
            $cascade['gallery_rtdb'] = false;
            log_message('error', "Events::delete_event gallery RTDB cleanup failed [{$id}]: " . $e->getMessage());
        }

        // 2. Storage folder: schools/{school_id}/events/{safeEvent}/
        //    (mirrors the sanitisation Schools::uploadMedia used when writing)
        try {
            $safeEvent   = preg_replace('/[^A-Za-z0-9_\-]/', '_', $id);
            $storagePath = "schools/{$this->school_id}/events/{$safeEvent}/";
            $cascade['storage_folder'] = (bool) $this->CM->delete_folder_from_firebase_storage($storagePath);
        } catch (\Throwable $e) {
            $cascade['storage_folder'] = false;
            log_message('error', "Events::delete_event Storage cleanup failed [{$id}]: " . $e->getMessage());
        }

        // 3. Linked notices doc(s): notices where source=event & eventRef==id
        try {
            $notices = $this->firebase->firestoreQuery('notices', [
                ['schoolId', '==', $this->school_name],
                ['eventRef', '==', $id],
            ]);
            foreach ((array) $notices as $ndoc) {
                $n   = $this->_row($ndoc);
                $nid = (string) ($n['noticeId'] ?? $n['id'] ?? '');
                if ($nid === '') continue;
                try {
                    if ($this->fs->remove('notices', $this->fs->docId($nid))) {
                        $cascade['notices_removed']++;
                    }
                } catch (\Throwable $e) {
                    log_message('error', "Events::delete_event notice cleanup failed [{$id}/{$nid}]: " . $e->getMessage());
                }
            }
        } catch (\Throwable $e) {
            log_message('error', "Events::delete_event notices query failed [{$id}]: " . $e->getMessage());
        }

        $this->fs->remove(self::COL_EVENTS, $this->fs->docId($id));

        $msg = 'Event deleted.';
        if (!empty($participantFailures)) {
            $msg .= ' Warning: ' . count($participantFailures) . ' of ' . $participantsTotal
                  . ' participant record(s) could not be removed.';
        }

        $this->json_success([
            'message' => $msg,
            'cleanup' => [
                'participants_removed' => $participantsRemoved,
                'participants_total'   => $participantsTotal,
                'participant_failures' => $participantFailures,
                'gallery_rtdb_cleared' => $cascade['gallery_rtdb'],
                'storage_cleared'      => $cascade['storage_folder'],
                'notices_removed'      => $cascade['notices_removed'],
            ],
        ]);
    }

    public function update_status()
    {
        $this->_require_role(self::MANAGE_ROLES, 'update_status');
        $this->_require_admin();
        $id     = $this->safe_path_segment(trim($this->input->post('id') ?? ''), 'event_id');
        $status = trim($this->input->post('status') ?? '');
        $this->_validate_enum($status, self::ALLOWED_STATUSES, 'status');

        $event = $this->fs->getEntity(self::COL_EVENTS, $id);
        if (!is_array($event)) $this->json_error('Event not found.');

        $this->fs->updateEntity(self::COL_EVENTS, $id, [
            'status'     => $status,
            'updated_at' => date('c'),
        ]);
        $this->json_success(['message' => 'Status updated.']);
    }

    // ====================================================================
    //  CALENDAR
    // ====================================================================

    public function get_calendar()
    {
        $this->_require_role(self::VIEW_ROLES, 'events_view');
        $this->_require_view();
        $month = (int) ($this->input->get('month') ?? date('n'));
        $year  = (int) ($this->input->get('year') ?? date('Y'));

        if ($month < 1 || $month > 12) $month = (int) date('n');
        if ($year < 2020 || $year > 2040) $year = (int) date('Y');

        $startOfMonth = sprintf('%04d-%02d-01', $year, $month);
        $endOfMonth   = date('Y-m-t', strtotime($startOfMonth));

        $rows  = $this->firebase->firestoreQuery(self::COL_EVENTS,
            [['schoolId', '==', $this->school_name]], 'startDate', 'ASC');
        $items = [];
        foreach ((array) $rows as $doc) {
            $e = $this->_row($doc);
            $id = (string) ($e['eventId'] ?? $this->_entityId((string) ($e['id'] ?? '')));
            if ($id === '') continue;
            $eStart = (string) ($e['start_date'] ?? $e['startDate'] ?? '');
            $eEnd   = (string) ($e['end_date']   ?? $e['endDate']   ?? $eStart);
            if ($eStart === '') continue;

            if ($eEnd >= $startOfMonth && $eStart <= $endOfMonth) {
                $items[] = [
                    'id'         => $id,
                    'title'      => $e['title'] ?? '',
                    'start_date' => $eStart,
                    'end_date'   => $eEnd,
                    'category'   => $e['category'] ?? 'event',
                    'status'     => $e['status'] ?? 'scheduled',
                    'location'   => $e['location'] ?? '',
                ];
            }
        }
        usort($items, fn($a, $b) => strcmp($a['start_date'], $b['start_date']));

        $this->json_success([
            'events' => $items,
            'month'  => $month,
            'year'   => $year,
        ]);
    }

    // ====================================================================
    //  PARTICIPATION
    // ====================================================================

    public function get_participants()
    {
        $this->_require_role(self::VIEW_ROLES, 'events_view');
        $this->_require_view();
        $eventId = $this->safe_path_segment(trim($this->input->get('event_id') ?? ''), 'event_id');

        $event = $this->fs->getEntity(self::COL_EVENTS, $eventId);
        if (!is_array($event)) $this->json_error('Event not found.');

        $rows = $this->firebase->firestoreQuery(self::COL_PARTICIPANTS, [
            ['schoolId', '==', $this->school_name],
            ['eventId',  '==', $eventId],
        ], 'name', 'ASC');

        $list = [];
        foreach ((array) $rows as $doc) {
            $p = $this->_row($doc);
            $pid = (string) ($p['participantId'] ?? $p['id'] ?? '');
            if ($pid === '') continue;
            $p['id'] = $pid;
            $list[] = $p;
        }

        $page  = max(1, (int) ($this->input->get('page') ?? 1));
        $limit = min(100, max(1, (int) ($this->input->get('limit') ?? 50)));
        $total = count($list);
        $list  = array_slice($list, ($page - 1) * $limit, $limit);

        $this->json_success([
            'participants' => $list,
            'event'        => array_merge($event, ['id' => $eventId]),
            'page'         => $page,
            'limit'        => $limit,
            'total'        => $total,
        ]);
    }

    public function save_participant()
    {
        $this->_require_role(self::MANAGE_ROLES, 'save_participant');
        $this->_require_teacher();
        $eventId         = $this->safe_path_segment(trim($this->input->post('event_id') ?? ''), 'event_id');
        $participantId   = $this->safe_path_segment(trim($this->input->post('participant_id') ?? ''), 'participant_id');
        $participantType = trim($this->input->post('participant_type') ?? 'student');
        $name            = trim($this->input->post('name') ?? '');
        $class           = trim($this->input->post('class') ?? '');
        $section         = trim($this->input->post('section') ?? '');
        $status          = trim($this->input->post('status') ?? 'registered');

        if ($participantId === '') $this->json_error('Participant ID is required.');
        if ($name === '') $this->json_error('Participant name is required.');
        $this->_validate_enum($participantType, self::ALLOWED_PTYPES, 'participant type');
        $this->_validate_enum($status, self::ALLOWED_PSTATUSES, 'participation status');
        $this->_validate_length($name, 'Name', self::MAX_TITLE_LENGTH);
        $name    = $this->_clean_text($name);
        $class   = $this->_clean_text($class);
        $section = $this->_clean_text($section);

        $event = $this->fs->getEntity(self::COL_EVENTS, $eventId);
        if (!is_array($event)) $this->json_error('Event not found.');

        $evtStatus = $event['status'] ?? '';
        if ($evtStatus === 'completed' || $evtStatus === 'cancelled') {
            $this->json_error('Cannot register for a ' . $evtStatus . ' event.');
        }

        $partDocId = $this->_participant_docId($eventId, $participantId);
        $existing  = $this->firebase->firestoreGet(self::COL_PARTICIPANTS, $partDocId);
        $isNew     = !is_array($existing);

        if ($isNew) {
            $maxP = (int) ($event['max_participants'] ?? 0);
            if ($maxP > 0) {
                $currentRows = $this->firebase->firestoreQuery(self::COL_PARTICIPANTS, [
                    ['schoolId', '==', $this->school_name],
                    ['eventId',  '==', $eventId],
                ]);
                $currentCount = is_array($currentRows) ? count($currentRows) : 0;
                if ($currentCount >= $maxP) {
                    $this->json_error("Event is full. Maximum {$maxP} participants allowed.");
                }
            }
        }

        $data = [
            'schoolId'         => $this->school_name,
            'eventId'          => $eventId,
            'participantId'    => $participantId,
            'participant_id'   => $participantId,
            'participant_type' => $participantType,
            'name'             => $name,
            'class'            => $class,
            'section'          => $section,
            'status'           => $status,
            'registered_by'    => $this->admin_id,
            'updated_at'       => date('c'),
        ];
        if ($isNew) $data['registration_date'] = date('c');

        $this->firebase->firestoreSet(self::COL_PARTICIPANTS, $partDocId, $data, /* merge */ !$isNew);

        $this->json_success([
            'message' => $isNew ? 'Participant registered.' : 'Participant updated.',
        ]);
    }

    public function remove_participant()
    {
        $this->_require_role(self::MANAGE_ROLES, 'remove_participant');
        $this->_require_teacher();
        $eventId       = $this->safe_path_segment(trim($this->input->post('event_id') ?? ''), 'event_id');
        $participantId = $this->safe_path_segment(trim($this->input->post('participant_id') ?? ''), 'participant_id');

        $partDocId = $this->_participant_docId($eventId, $participantId);
        $existing  = $this->firebase->firestoreGet(self::COL_PARTICIPANTS, $partDocId);
        if (!is_array($existing)) $this->json_error('Participant not found.');

        $this->fs->remove(self::COL_PARTICIPANTS, $partDocId);
        $this->json_success(['message' => 'Participant removed.']);
    }

    public function mark_attendance()
    {
        $this->_require_role(self::MANAGE_ROLES, 'mark_attendance');
        $this->_require_teacher();
        $eventId       = $this->safe_path_segment(trim($this->input->post('event_id') ?? ''), 'event_id');
        $participantId = $this->safe_path_segment(trim($this->input->post('participant_id') ?? ''), 'participant_id');
        $status        = trim($this->input->post('status') ?? 'attended');
        $this->_validate_enum($status, self::ALLOWED_PSTATUSES, 'attendance status');

        $partDocId = $this->_participant_docId($eventId, $participantId);
        $existing  = $this->firebase->firestoreGet(self::COL_PARTICIPANTS, $partDocId);
        if (!is_array($existing)) $this->json_error('Participant not found.');

        $this->firebase->firestoreSet(self::COL_PARTICIPANTS, $partDocId, [
            'status'     => $status,
            'updated_at' => date('c'),
        ], /* merge */ true);
        $this->json_success(['message' => 'Attendance updated.']);
    }

    /**
     * Search students/teachers for participant registration.
     */
    public function search_people()
    {
        $this->_require_role(self::VIEW_ROLES, 'events_view');
        $this->_require_teacher();
        $query = strtolower(trim($this->input->get('q') ?? ''));
        if (mb_strlen($query) < 2) $this->json_error('Enter at least 2 characters.');
        if (mb_strlen($query) > 100) $this->json_error('Search query too long.');

        $results    = [];
        $maxResults = 20;

        // Students — cached Firestore-backed search.
        $students = $this->operations_accounting->search_students($query, $maxResults);
        foreach ($students as $s) {
            if (count($results) >= $maxResults) break;
            $sid   = (string) ($s['id'] ?? '');
            $name  = (string) ($s['name'] ?? '');
            $cls   = (string) ($s['class'] ?? '');
            $sec   = (string) ($s['section'] ?? '');
            $label = $this->_clean_text(trim("{$name} ({$sid}) - Class {$cls} Section {$sec}"));
            $results[] = [
                'id'      => $sid,
                'name'    => $this->_clean_text($name),
                'type'    => 'student',
                'class'   => $cls,
                'section' => $sec,
                'label'   => $label,
            ];
        }

        // Teachers / staff — Firestore `staff` collection.
        if (count($results) < $maxResults) {
            $teachers = $this->firebase->firestoreQuery('staff', [
                ['schoolId', '==', $this->school_name],
            ]);
            foreach ((array) $teachers as $r) {
                if (count($results) >= $maxResults) break;
                $d = $this->_row($r);
                $tid  = (string) ($d['staffId'] ?? $d['teacherId'] ?? $d['id'] ?? '');
                $name = (string) ($d['name'] ?? $d['Name'] ?? '');
                if ($tid === '' || $name === '') continue;
                $role = strtolower((string) ($d['role'] ?? $d['jobFunction'] ?? ''));
                if ($role !== '' && strpos($role, 'teach') === false && strpos($role, 'coordinator') === false) continue;
                if (stripos($name, $query) !== false || stripos($tid, $query) !== false) {
                    $results[] = [
                        'id'      => $tid,
                        'name'    => $this->_clean_text($name),
                        'type'    => 'teacher',
                        'class'   => '',
                        'section' => '',
                        'label'   => $this->_clean_text("{$name} ({$tid}) - Teacher"),
                    ];
                }
            }
        }

        $this->json_success(['results' => $results]);
    }

    // ====================================================================
    //  COMMUNICATION INTEGRATION
    // ====================================================================

    /**
     * Fire event notification via Communication module.
     */
    private function _fire_event_notification(string $eventId, array $data): void
    {
        try {
            $this->load->library('communication_helper');
            $this->communication_helper->init($this->firebase, $this->school_name, $this->session_year, $this->parent_db_key, $this->fs, $this->school_id);

            $payload = [
                'event_id'   => $eventId,
                'title'      => $data['title'] ?? '',
                'category'   => $data['category'] ?? 'event',
                'start_date' => $data['start_date'] ?? '',
                'end_date'   => $data['end_date'] ?? '',
                'location'   => $data['location'] ?? '',
                'organizer'  => $data['organizer'] ?? '',
                'school'     => $this->school_name,
            ];

            $this->communication_helper->fire_event('event_created', $payload);
            $this->communication_helper->write_event_notice($eventId, $data, $this->admin_id);

        } catch (\Exception $e) {
            log_message('error', 'Events: notification failed - ' . $e->getMessage());
        }
    }
}
