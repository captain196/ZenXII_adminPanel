<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Events Controller — Event and Activity Management
 *
 * Sub-modules: Events (school/cultural/sports), Calendar, Participation
 *
 * Firebase paths:
 *   Schools/{school_id}/Events/List/{EVT0001}
 *   Schools/{school_id}/Events/Participants/{EVT0001}/{participantId}
 *   Schools/{school_id}/Events/Counters/Event
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

    public function __construct()
    {
        parent::__construct();
        require_permission('Events');
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

    /**
     * Deny access — JSON error for AJAX, redirect for page loads.
     */
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
     * Strip control characters from text for safe Firebase storage.
     * Do NOT HTML-encode here — display-layer (EV.esc in JS) handles output encoding.
     * HTML-encoding on write + EV.esc on read = double-encoding bug.
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

    // ── Path helpers ────────────────────────────────────────────────────
    private function _evt(string $sub = ''): string
    {
        $b = "Schools/{$this->school_name}/Events";
        return $sub !== '' ? "{$b}/{$sub}" : $b;
    }

    private function _counter(string $type): string
    {
        return $this->_evt("Counters/{$type}");
    }

    /**
     * Generate next sequential ID with collision detection.
     * Retries up to 3 times if a race condition produces a duplicate.
     */
    private function _next_id(string $type, string $prefix, int $pad = 4): string
    {
        $path       = $this->_counter($type);
        $listPath   = $this->_evt('List');
        $maxRetries = 3;

        for ($attempt = 0; $attempt < $maxRetries; $attempt++) {
            $cur  = (int) ($this->firebase->get($path) ?? 0);
            $next = $cur + 1;
            $this->firebase->set($path, $next);
            $id = $prefix . str_pad($next, $pad, '0', STR_PAD_LEFT);

            // Verify no collision — another request may have used this ID
            $existing = $this->firebase->get("{$listPath}/{$id}");
            if (!is_array($existing)) {
                return $id;
            }
            // Collision detected — counter was stale; loop will re-read
            log_message('debug', "Events: ID collision on {$id}, retrying (attempt " . ($attempt + 1) . ')');
        }

        // Fallback: append microsecond suffix to guarantee uniqueness
        $cur = (int) ($this->firebase->get($path) ?? 0);
        $this->firebase->set($path, $cur + 1);
        return $prefix . str_pad($cur + 1, $pad, '0', STR_PAD_LEFT) . '_' . substr(uniqid(), -6);
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

    /**
     * Circular / advertisement page for an event (standalone printable).
     */
    public function circular(string $eventId = '')
    {
        $this->_require_role(self::VIEW_ROLES, 'events_view');
        $this->_require_view();

        if ($eventId === '') redirect(base_url('events/list'));

        $event = $this->firebase->get($this->_evt("List/{$eventId}"));
        if (!is_array($event)) {
            redirect(base_url('events/list'));
        }
        $event['id'] = $eventId;

        // Fetch school info for the circular header
        $schoolDisplay = $this->school_display_name ?: $this->school_name;
        $schoolLogo    = '';
        $sysProfile    = $this->firebase->get("System/Schools/{$this->school_id}/profile");
        if (is_array($sysProfile) && !empty($sysProfile['logo'])) {
            $schoolLogo = $sysProfile['logo'];
        } else {
            $cfgProfile = $this->firebase->get("Schools/{$this->school_name}/Config/Profile");
            if (is_array($cfgProfile) && !empty($cfgProfile['logo'])) {
                $schoolLogo = $cfgProfile['logo'];
            }
        }

        // Count participants
        $participants = $this->firebase->get($this->_evt("Participants/{$eventId}"));
        $pCount = is_array($participants) ? count($participants) : 0;

        $data = [
            'event'        => $event,
            'school_name'  => $schoolDisplay,
            'school_logo'  => $schoolLogo,
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

        $all = $this->firebase->get($this->_evt('List'));
        $total = 0; $upcoming = 0; $ongoing = 0; $completed = 0; $cancelled = 0;
        $upcomingEvents = [];
        $today = date('Y-m-d');

        if (is_array($all)) {
            foreach ($all as $id => $e) {
                if (!is_array($e) || $id === 'Counter') continue;
                $total++;
                $s = $e['status'] ?? '';
                if ($s === 'scheduled') {
                    $upcoming++;
                    $e['id'] = $id;
                    $upcomingEvents[] = $e;
                }
                elseif ($s === 'ongoing')   $ongoing++;
                elseif ($s === 'completed') $completed++;
                elseif ($s === 'cancelled') $cancelled++;
            }
            usort($upcomingEvents, fn($a, $b) => strcmp($a['start_date'] ?? '', $b['start_date'] ?? ''));
            $upcomingEvents = array_slice($upcomingEvents, 0, 5);
        }

        // Recent participants — only scan upcoming/ongoing events (not ALL events)
        // to avoid unbounded full-tree read on the entire Participants node
        $recentParticipants = [];
        $activeEventIds = [];
        if (is_array($all)) {
            foreach ($all as $id => $e) {
                if (!is_array($e) || $id === 'Counter') continue;
                $s = $e['status'] ?? '';
                if ($s === 'scheduled' || $s === 'ongoing') {
                    $activeEventIds[] = $id;
                }
            }
        }
        // Cap to 10 most recent active events to bound reads
        $activeEventIds = array_slice($activeEventIds, 0, 10);
        foreach ($activeEventIds as $evtId) {
            $participants = $this->firebase->get($this->_evt("Participants/{$evtId}"));
            if (!is_array($participants)) continue;
            foreach ($participants as $pid => $p) {
                if (!is_array($p)) continue;
                $p['event_id'] = $evtId;
                $p['id'] = $pid;
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

        // Validate filter values — reject invalid enums rather than silently returning empty
        if ($category !== '' && !in_array($category, self::ALLOWED_CATEGORIES, true)) {
            $this->json_error('Invalid category filter.');
        }
        if ($status !== '' && !in_array($status, self::ALLOWED_STATUSES, true)) {
            $this->json_error('Invalid status filter.');
        }

        $all  = $this->firebase->get($this->_evt('List'));
        $list = [];
        if (is_array($all)) {
            foreach ($all as $id => $e) {
                if (!is_array($e) || $id === 'Counter') continue;
                if ($category !== '' && ($e['category'] ?? '') !== $category) continue;
                if ($status !== '' && ($e['status'] ?? '') !== $status) continue;
                $e['id'] = $id;
                $list[] = $e;
            }
            usort($list, fn($a, $b) => strcmp($b['start_date'] ?? '', $a['start_date'] ?? ''));
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
        $event = $this->firebase->get($this->_evt("List/{$id}"));
        if (!is_array($event)) $this->json_error('Event not found.');
        $event['id'] = $id;

        // Get participant count
        $participants = $this->firebase->shallow_get($this->_evt("Participants/{$id}"));
        $event['participant_count'] = is_array($participants) ? count($participants) : 0;

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

        // Clean text (strip control chars) — no HTML-encoding; display layer handles that
        $title       = $this->_clean_text($title);
        $description = $this->_clean_text($description);
        $location    = $this->_clean_text($location);
        $organizer   = $this->_clean_text($organizer);

        $isNew = ($id === '');
        if ($isNew) {
            $id = $this->_next_id('Event', 'EVT');
        } else {
            $id = $this->safe_path_segment($id, 'event_id');
            $existing = $this->firebase->get($this->_evt("List/{$id}"));
            if (!is_array($existing)) $this->json_error('Event not found.');
        }

        $data = [
            'title'            => $title,
            'description'      => $description,
            'category'         => $category,
            'location'         => $location,
            'start_date'       => $startDate,
            'end_date'         => $endDate !== '' ? $endDate : $startDate,
            'organizer'        => $organizer,
            'max_participants' => $maxParticipants,
            'status'           => $status,
            'updated_at'       => date('c'),
        ];

        if ($isNew) {
            $data['created_at']      = date('c');
            $data['created_by']      = $this->admin_id;
            $data['created_by_name'] = $this->admin_name;
            $this->firebase->set($this->_evt("List/{$id}"), $data);
        } else {
            // update() merges — preserves created_at, created_by, created_by_name
            $data['updated_by']      = $this->admin_id;
            $data['updated_by_name'] = $this->admin_name;
            $this->firebase->update($this->_evt("List/{$id}"), $data);
        }

        // ── Sync to Firestore 'events' collection ──
        try {
            $fsData = [
                'schoolId'    => $this->school_name,
                'session'     => $this->session_year,
                'title'       => $title,
                'description' => $description,
                'category'    => $category,
                'startDate'   => $startDate,
                'endDate'     => $endDate !== '' ? $endDate : $startDate,
                'location'    => $location,
                'status'      => $status,
                'mediaUrls'   => [],
                'createdBy'   => $this->admin_id,
                'createdAt'   => date('c'),
            ];
            $this->firebase->firestoreSet('events', $id, $fsData, !$isNew);
        } catch (\Exception $e) {
            log_message('error', "save_event: Firestore sync failed [{$id}]: " . $e->getMessage());
        }

        // Fire communication event for new events
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

        $event = $this->firebase->get($this->_evt("List/{$id}"));
        if (!is_array($event)) $this->json_error('Event not found.');

        // Delete event, participants, and gallery media
        $this->firebase->delete($this->_evt('List'), $id);
        $this->firebase->delete($this->_evt('Participants'), $id);
        $this->firebase->delete($this->_evt('Media'), $id);

        $this->json_success(['message' => 'Event deleted.']);
    }

    public function update_status()
    {
        $this->_require_role(self::MANAGE_ROLES, 'update_status');
        $this->_require_admin();
        $id     = $this->safe_path_segment(trim($this->input->post('id') ?? ''), 'event_id');
        $status = trim($this->input->post('status') ?? '');
        $this->_validate_enum($status, self::ALLOWED_STATUSES, 'status');

        $event = $this->firebase->get($this->_evt("List/{$id}"));
        if (!is_array($event)) $this->json_error('Event not found.');

        $this->firebase->update($this->_evt("List/{$id}"), [
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

        $all   = $this->firebase->get($this->_evt('List'));
        $items = [];
        if (is_array($all)) {
            foreach ($all as $id => $e) {
                if (!is_array($e) || $id === 'Counter') continue;
                $eStart = $e['start_date'] ?? '';
                $eEnd   = $e['end_date'] ?? $eStart;
                if ($eStart === '') continue;

                // Include events that overlap with the requested month
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
        }

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

        // Verify event exists
        $event = $this->firebase->get($this->_evt("List/{$eventId}"));
        if (!is_array($event)) $this->json_error('Event not found.');

        $all  = $this->firebase->get($this->_evt("Participants/{$eventId}"));
        $list = [];
        if (is_array($all)) {
            foreach ($all as $pid => $p) {
                if (!is_array($p)) continue;
                $p['id'] = $pid;
                $list[] = $p;
            }
            usort($list, fn($a, $b) => strcmp($a['name'] ?? '', $b['name'] ?? ''));
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

        // Verify event exists
        $event = $this->firebase->get($this->_evt("List/{$eventId}"));
        if (!is_array($event)) $this->json_error('Event not found.');

        // Check if event is still open for registration
        $evtStatus = $event['status'] ?? '';
        if ($evtStatus === 'completed' || $evtStatus === 'cancelled') {
            $this->json_error('Cannot register for a ' . $evtStatus . ' event.');
        }

        // Check max participants (only for new registrations)
        $existing = $this->firebase->get($this->_evt("Participants/{$eventId}/{$participantId}"));
        $isNew = !is_array($existing);

        if ($isNew) {
            $maxP = (int) ($event['max_participants'] ?? 0);
            if ($maxP > 0) {
                $currentP = $this->firebase->shallow_get($this->_evt("Participants/{$eventId}"));
                $currentCount = is_array($currentP) ? count($currentP) : 0;
                if ($currentCount >= $maxP) {
                    $this->json_error("Event is full. Maximum {$maxP} participants allowed.");
                }
            }
        }

        $data = [
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

        $this->firebase->set($this->_evt("Participants/{$eventId}/{$participantId}"), $data);

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

        $existing = $this->firebase->get($this->_evt("Participants/{$eventId}/{$participantId}"));
        if (!is_array($existing)) $this->json_error('Participant not found.');

        $this->firebase->delete($this->_evt("Participants/{$eventId}"), $participantId);
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

        $existing = $this->firebase->get($this->_evt("Participants/{$eventId}/{$participantId}"));
        if (!is_array($existing)) $this->json_error('Participant not found.');

        $this->firebase->update($this->_evt("Participants/{$eventId}/{$participantId}"), [
            'status'     => $status,
            'updated_at' => date('c'),
        ]);
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
        $session    = $this->session_year;
        $maxResults = 20;

        // Search students
        $sessionKeys = $this->firebase->shallow_get("Schools/{$this->school_name}/{$session}");
        foreach ((array) $sessionKeys as $classKey) {
            if (count($results) >= $maxResults) break;
            if (strpos($classKey, 'Class ') !== 0) continue;
            $sectionKeys = $this->firebase->shallow_get("Schools/{$this->school_name}/{$session}/{$classKey}");
            foreach ((array) $sectionKeys as $sectionKey) {
                if (count($results) >= $maxResults) break;
                if (strpos($sectionKey, 'Section ') !== 0) continue;
                $students = $this->firebase->get("Schools/{$this->school_name}/{$session}/{$classKey}/{$sectionKey}/Students/List");
                if (!is_array($students)) continue;
                foreach ($students as $sid => $sName) {
                    if (count($results) >= $maxResults) break;
                    $name = (string) $sName;
                    if (stripos($name, $query) !== false || stripos($sid, $query) !== false) {
                        $sec = str_replace('Section ', '', $sectionKey);
                        $cls = str_replace('Class ', '', $classKey);
                        $results[] = [
                            'id'    => $sid,
                            'name'  => $this->_clean_text($name),
                            'type'  => 'student',
                            'class' => $cls,
                            'section' => $sec,
                            'label' => $this->_clean_text("{$name} ({$sid}) - {$classKey} {$sectionKey}"),
                        ];
                    }
                }
            }
        }

        // Search teachers
        if (count($results) < $maxResults) {
            $teachers = $this->firebase->get("Schools/{$this->school_name}/{$session}/Teachers");
            if (is_array($teachers)) {
                foreach ($teachers as $tid => $t) {
                    if (count($results) >= $maxResults) break;
                    if (!is_array($t)) continue;
                    $name = $t['Name'] ?? '';
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
        }

        $this->json_success(['results' => $results]);
    }

    // ====================================================================
    //  COMMUNICATION INTEGRATION
    // ====================================================================

    /**
     * Fire event notification via Communication module.
     *
     * 1. Fires 'event_created' through Communication_helper::fire_event()
     *    so any configured triggers (push/sms/email) are queued.
     * 2. Creates a Communication/Notices entry (primary announcement)
     *    plus a legacy All Notices entry (fallback for mobile apps).
     */
    private function _fire_event_notification(string $eventId, array $data): void
    {
        try {
            $this->load->library('communication_helper');
            $this->communication_helper->init($this->firebase, $this->school_name, $this->session_year, $this->parent_db_key);

            // Structured payload for trigger-based notifications
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

            // 1. Fire trigger-based notifications (push/sms/email)
            //    Only queues messages if school has configured triggers for 'event_created'
            $this->communication_helper->fire_event('event_created', $payload);

            // 2. Create announcement notice (Communication/Notices + legacy All Notices)
            //    This is the school-wide announcement visible to everyone
            $this->communication_helper->write_event_notice($eventId, $data, $this->admin_id);

        } catch (\Exception $e) {
            log_message('error', 'Events: notification failed - ' . $e->getMessage());
        }
    }
}
