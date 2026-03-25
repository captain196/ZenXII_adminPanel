<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Admission CRM Controller
 *
 * Manages the full admission lifecycle: inquiries → applications →
 * pipeline stages → approval → waitlist → enrollment.
 *
 * Firebase paths:
 *   Schools/{school}/CRM/Admissions/Settings      — pipeline stages, form fields, session config
 *   Schools/{school}/CRM/Admissions/Inquiries/{id} — inquiry records
 *   Schools/{school}/CRM/Admissions/Applications/{id} — full applications
 *   Schools/{school}/CRM/Admissions/Waitlist/{id}  — waitlisted entries
 *   Schools/{school}/CRM/Admissions/Counter        — auto-increment counter
 */
class Admission_crm extends MY_Controller
{
    /** Roles for admission management */
    private const MANAGE_ROLES = ['Admin', 'Principal'];

    /** Roles that may view admission data */
    private const VIEW_ROLES   = ['Admin', 'Principal', 'Teacher'];

    private $crm_base;

    public function __construct()
    {
        parent::__construct();
        $this->crm_base = "Schools/{$this->school_name}/CRM/Admissions";
    }

    /* ══════════════════════════════════════════════════════════════════════
       DASHBOARD / INDEX
    ══════════════════════════════════════════════════════════════════════ */

    public function index()
    {
        $this->_require_role(self::VIEW_ROLES, 'crm_view');
        $school_id   = $this->parent_db_key;
        $school_name = $this->school_name;
        $session     = $this->session_year;

        // Fetch all CRM data for analytics
        $inquiries    = $this->firebase->get("{$this->crm_base}/Inquiries") ?? [];
        $applications = $this->firebase->get("{$this->crm_base}/Applications") ?? [];
        $waitlist     = $this->firebase->get("{$this->crm_base}/Waitlist") ?? [];
        $settings     = $this->firebase->get("{$this->crm_base}/Settings") ?? [];

        if (!is_array($inquiries))    $inquiries = [];
        if (!is_array($applications)) $applications = [];
        if (!is_array($waitlist))     $waitlist = [];
        if (!is_array($settings))     $settings = [];

        // Filter for current session
        $sessionInquiries = array_filter($inquiries, function($i) use ($session) {
            return is_array($i) && ($i['session'] ?? '') === $session;
        });
        $sessionApps = array_filter($applications, function($a) use ($session) {
            return is_array($a) && ($a['session'] ?? '') === $session;
        });
        $sessionWaitlist = array_filter($waitlist, function($w) use ($session) {
            return is_array($w) && ($w['session'] ?? '') === $session;
        });

        // Analytics
        $stats = [
            'total_inquiries'    => count($sessionInquiries),
            'total_applications' => count($sessionApps),
            'total_waitlist'     => count($sessionWaitlist),
            'pending_approval'   => 0,
            'approved'           => 0,
            'rejected'           => 0,
            'enrolled'           => 0,
        ];

        foreach ($sessionApps as $app) {
            $status = $app['status'] ?? 'pending';
            if ($status === 'pending')  $stats['pending_approval']++;
            if ($status === 'approved') $stats['approved']++;
            if ($status === 'rejected') $stats['rejected']++;
            if ($status === 'enrolled') $stats['enrolled']++;
        }

        // Class-wise breakdown
        $classBreakdown = [];
        foreach ($sessionApps as $app) {
            $cls = $app['class'] ?? 'Unknown';
            if (!isset($classBreakdown[$cls])) {
                $classBreakdown[$cls] = ['applied' => 0, 'approved' => 0, 'enrolled' => 0, 'waitlisted' => 0];
            }
            $classBreakdown[$cls]['applied']++;
            $st = $app['status'] ?? '';
            if (isset($classBreakdown[$cls][$st])) $classBreakdown[$cls][$st]++;
        }
        foreach ($sessionWaitlist as $w) {
            $cls = $w['class'] ?? 'Unknown';
            if (!isset($classBreakdown[$cls])) {
                $classBreakdown[$cls] = ['applied' => 0, 'approved' => 0, 'enrolled' => 0, 'waitlisted' => 0];
            }
            $classBreakdown[$cls]['waitlisted']++;
        }
        ksort($classBreakdown);

        // Source-wise inquiry breakdown
        $sourceBreakdown = [];
        foreach ($sessionInquiries as $inq) {
            $src = $inq['source'] ?? 'Walk-in';
            $sourceBreakdown[$src] = ($sourceBreakdown[$src] ?? 0) + 1;
        }

        // Monthly trend (last 6 months)
        $monthlyTrend = [];
        foreach ($sessionInquiries as $inq) {
            $dt = $inq['created_at'] ?? '';
            if ($dt) {
                $m = substr($dt, 0, 7); // YYYY-MM
                $monthlyTrend[$m] = ($monthlyTrend[$m] ?? 0) + 1;
            }
        }
        ksort($monthlyTrend);
        $monthlyTrend = array_slice($monthlyTrend, -6, 6, true);

        $data['stats']            = $stats;
        $data['class_breakdown']  = $classBreakdown;
        $data['source_breakdown'] = $sourceBreakdown;
        $data['monthly_trend']    = $monthlyTrend;
        $data['settings']         = $settings;
        $data['session_year']     = $session;

        $this->load->view('include/header');
        $this->load->view('admission_crm/index', $data);
        $this->load->view('include/footer');
    }

    /* ══════════════════════════════════════════════════════════════════════
       INQUIRIES
    ══════════════════════════════════════════════════════════════════════ */

    public function inquiries()
    {
        $this->_require_role(self::VIEW_ROLES, 'crm_view');
        $data['session_year'] = $this->session_year;
        $data['classes']      = $this->_get_classes();

        $this->load->view('include/header');
        $this->load->view('admission_crm/inquiries', $data);
        $this->load->view('include/footer');
    }

    public function fetch_inquiries()
    {
        $this->_require_role(self::VIEW_ROLES, 'crm_fetch');
        $inquiries = $this->firebase->get("{$this->crm_base}/Inquiries") ?? [];
        if (!is_array($inquiries)) $inquiries = [];

        $session = $this->session_year;
        $result = [];
        foreach ($inquiries as $id => $inq) {
            if (!is_array($inq)) continue;
            if (($inq['session'] ?? '') !== $session) continue;
            $inq['id'] = $id;
            $result[] = $inq;
        }

        // Sort by created_at descending
        usort($result, function($a, $b) {
            return strcmp($b['created_at'] ?? '', $a['created_at'] ?? '');
        });

        return $this->json_success(['inquiries' => $result]);
    }

    public function save_inquiry()
    {
        $this->_require_role(self::MANAGE_ROLES, 'crm_save_inquiry');
        $id             = trim($this->input->post('id') ?? '');
        if ($id !== '') $id = $this->safe_path_segment($id, 'id');
        $student_name   = trim($this->input->post('student_name') ?? '');
        $parent_name    = trim($this->input->post('parent_name') ?? '');
        $phone          = trim($this->input->post('phone') ?? '');
        $email          = trim($this->input->post('email') ?? '');
        $class          = trim($this->input->post('class') ?? '');
        $source         = trim($this->input->post('source') ?? 'Walk-in');
        $notes          = trim($this->input->post('notes') ?? '');
        $status         = trim($this->input->post('status') ?? 'new');
        $follow_up_date = trim($this->input->post('follow_up_date') ?? '');

        if ($student_name === '' || $parent_name === '' || $phone === '') {
            return $this->json_error('Student name, parent name, and phone are required');
        }

        // Phone validation (10-15 digits, optional leading +)
        if (!preg_match('/^\+?\d{10,15}$/', preg_replace('/[\s\-]/', '', $phone))) {
            return $this->json_error('Invalid phone number format');
        }
        // Email validation (if provided)
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return $this->json_error('Invalid email address');
        }

        $now = date('Y-m-d H:i:s');

        if ($id) {
            // Update existing
            $existing = $this->firebase->get("{$this->crm_base}/Inquiries/{$id}");
            if (!is_array($existing)) {
                return $this->json_error('Inquiry not found');
            }
            $data = array_merge($existing, [
                'student_name'   => $student_name,
                'parent_name'    => $parent_name,
                'phone'          => $phone,
                'email'          => $email,
                'class'          => $class,
                'source'         => $source,
                'notes'          => $notes,
                'status'         => $status,
                'follow_up_date' => $follow_up_date,
                'updated_at'     => $now,
            ]);
            $this->firebase->set("{$this->crm_base}/Inquiries/{$id}", $data);
        } else {
            // Create new
            $counter = (int)($this->firebase->get("{$this->crm_base}/Counter") ?? 0);
            $counter++;
            $id = 'INQ' . str_pad($counter, 5, '0', STR_PAD_LEFT);

            $data = [
                'inquiry_id'     => $id,
                'student_name'   => $student_name,
                'parent_name'    => $parent_name,
                'phone'          => $phone,
                'email'          => $email,
                'class'          => $class,
                'source'         => $source,
                'notes'          => $notes,
                'status'         => $status,
                'follow_up_date' => $follow_up_date,
                'session'        => $this->session_year,
                'created_at'     => $now,
                'updated_at'     => $now,
                'created_by'     => $this->admin_name,
            ];
            $this->firebase->set("{$this->crm_base}/Inquiries/{$id}", $data);
            $this->firebase->set("{$this->crm_base}/Counter", $counter);
        }

        return $this->json_success(['id' => $id]);
    }

    public function delete_inquiry()
    {
        $this->_require_role(self::MANAGE_ROLES, 'crm_delete_inquiry');
        $id = trim($this->input->post('id') ?? '');
        if (!$id) return $this->json_error('Inquiry ID required');
        $id = $this->safe_path_segment($id, 'id');

        $this->firebase->delete("{$this->crm_base}/Inquiries", $id);
        return $this->json_success();
    }

    public function convert_to_application()
    {
        $this->_require_role(self::MANAGE_ROLES, 'crm_convert');
        $inquiry_id = trim($this->input->post('inquiry_id') ?? '');
        if (!$inquiry_id) return $this->json_error('Inquiry ID required');
        $inquiry_id = $this->safe_path_segment($inquiry_id, 'inquiry_id');

        $inquiry = $this->firebase->get("{$this->crm_base}/Inquiries/{$inquiry_id}");
        if (!is_array($inquiry)) return $this->json_error('Inquiry not found');

        // Generate application ID
        $counter = (int)($this->firebase->get("{$this->crm_base}/Counter") ?? 0);
        $counter++;
        $app_id = 'APP' . str_pad($counter, 5, '0', STR_PAD_LEFT);

        $now = date('Y-m-d H:i:s');
        $application = [
            'application_id' => $app_id,
            'inquiry_id'     => $inquiry_id,
            'student_name'   => $inquiry['student_name'] ?? '',
            'parent_name'    => $inquiry['parent_name'] ?? '',
            'phone'          => $inquiry['phone'] ?? '',
            'email'          => $inquiry['email'] ?? '',
            'class'          => $inquiry['class'] ?? '',
            'session'        => $inquiry['session'] ?? $this->session_year,
            'status'         => 'pending',
            'stage'          => 'document_collection',
            'created_at'     => $now,
            'updated_at'     => $now,
            'created_by'     => $this->admin_name,
            'source_inquiry' => $inquiry_id,
            // Placeholder fields for the full application form
            'dob'            => '',
            'gender'         => '',
            'address'        => '',
            'father_name'    => $inquiry['parent_name'] ?? '',
            'mother_name'    => '',
            'documents'      => [],
            'notes'          => $inquiry['notes'] ?? '',
            'history'        => [
                [
                    'action'    => 'Application created from inquiry ' . $inquiry_id,
                    'by'        => $this->admin_name,
                    'timestamp' => $now,
                ]
            ],
        ];

        $this->firebase->set("{$this->crm_base}/Applications/{$app_id}", $application);
        $this->firebase->set("{$this->crm_base}/Counter", $counter);

        // Update inquiry status
        $this->firebase->update("{$this->crm_base}/Inquiries/{$inquiry_id}", [
            'status'         => 'converted',
            'application_id' => $app_id,
            'updated_at'     => $now,
        ]);

        return $this->json_success(['application_id' => $app_id]);
    }

    /* ══════════════════════════════════════════════════════════════════════
       APPLICATIONS
    ══════════════════════════════════════════════════════════════════════ */

    public function applications()
    {
        $this->_require_role(self::VIEW_ROLES, 'crm_view');
        $data['session_year'] = $this->session_year;
        $data['classes']      = $this->_get_classes();

        $this->load->view('include/header');
        $this->load->view('admission_crm/applications', $data);
        $this->load->view('include/footer');
    }

    public function fetch_applications()
    {
        $this->_require_role(self::VIEW_ROLES, 'crm_fetch');
        $applications = $this->firebase->get("{$this->crm_base}/Applications") ?? [];
        if (!is_array($applications)) $applications = [];

        $session = $this->session_year;
        $result = [];
        foreach ($applications as $id => $app) {
            if (!is_array($app)) continue;
            if (($app['session'] ?? '') !== $session) continue;
            $app['id'] = $id;
            $result[] = $app;
        }

        usort($result, function($a, $b) {
            return strcmp($b['created_at'] ?? '', $a['created_at'] ?? '');
        });

        return $this->json_success(['applications' => $result]);
    }

    public function save_application()
    {
        $this->_require_role(self::MANAGE_ROLES, 'crm_save_app');
        $id = trim($this->input->post('id') ?? '');
        if ($id !== '') $id = $this->safe_path_segment($id, 'id');
        $now = date('Y-m-d H:i:s');

        $fields = [
            'student_name', 'parent_name', 'father_name', 'mother_name',
            'phone', 'email', 'class', 'section', 'dob', 'gender',
            'address', 'city', 'state', 'pincode',
            'previous_school', 'previous_class', 'previous_marks',
            'blood_group', 'category', 'religion', 'nationality',
            'father_occupation', 'mother_occupation',
            'guardian_name', 'guardian_phone', 'guardian_relation',
            'notes',
        ];

        $data = [];
        foreach ($fields as $f) {
            $data[$f] = trim($this->input->post($f) ?? '');
        }

        if ($data['student_name'] === '' || $data['class'] === '') {
            return $this->json_error('Student name and class are required');
        }

        // Phone validation (if provided)
        if ($data['phone'] !== '' && !preg_match('/^\+?\d{10,15}$/', preg_replace('/[\s\-]/', '', $data['phone']))) {
            return $this->json_error('Invalid phone number format');
        }
        // Email validation (if provided)
        if ($data['email'] !== '' && !filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
            return $this->json_error('Invalid email address');
        }

        if ($id) {
            // Update existing
            $existing = $this->firebase->get("{$this->crm_base}/Applications/{$id}");
            if (!is_array($existing)) return $this->json_error('Application not found');

            $data['updated_at'] = $now;
            $history = $existing['history'] ?? [];
            $history[] = [
                'action'    => 'Application updated',
                'by'        => $this->admin_name,
                'timestamp' => $now,
            ];
            $data['history'] = $history;

            $this->firebase->update("{$this->crm_base}/Applications/{$id}", $data);
            return $this->json_success(['id' => $id]);
        } else {
            // New application (direct, no inquiry)
            $counter = (int)($this->firebase->get("{$this->crm_base}/Counter") ?? 0);
            $counter++;
            $app_id = 'APP' . str_pad($counter, 5, '0', STR_PAD_LEFT);

            $data = array_merge($data, [
                'application_id' => $app_id,
                'session'        => $this->session_year,
                'status'         => 'pending',
                'stage'          => 'document_collection',
                'created_at'     => $now,
                'updated_at'     => $now,
                'created_by'     => $this->admin_name,
                'documents'      => [],
                'history'        => [[
                    'action'    => 'Application created directly',
                    'by'        => $this->admin_name,
                    'timestamp' => $now,
                ]],
            ]);

            $this->firebase->set("{$this->crm_base}/Applications/{$app_id}", $data);
            $this->firebase->set("{$this->crm_base}/Counter", $counter);

            return $this->json_success(['id' => $app_id]);
        }
    }

    public function get_application()
    {
        $this->_require_role(self::VIEW_ROLES, 'crm_fetch');
        $id = trim($this->input->get('id') ?? '');
        if (!$id) return $this->json_error('Application ID required');
        $id = $this->safe_path_segment($id, 'id');

        $app = $this->firebase->get("{$this->crm_base}/Applications/{$id}");
        if (!is_array($app)) return $this->json_error('Application not found');

        $app['id'] = $id;
        return $this->json_success(['application' => $app]);
    }

    public function delete_application()
    {
        $this->_require_role(self::MANAGE_ROLES, 'crm_delete_app');
        $id = trim($this->input->post('id') ?? '');
        if (!$id) return $this->json_error('Application ID required');
        $id = $this->safe_path_segment($id, 'id');

        $this->firebase->delete("{$this->crm_base}/Applications", $id);
        return $this->json_success();
    }

    /* ══════════════════════════════════════════════════════════════════════
       PIPELINE — Stage transitions
    ══════════════════════════════════════════════════════════════════════ */

    public function pipeline()
    {
        $this->_require_role(self::VIEW_ROLES, 'crm_view');
        $data['session_year'] = $this->session_year;
        $data['classes']      = $this->_get_classes();

        // Get pipeline settings
        $settings = $this->firebase->get("{$this->crm_base}/Settings") ?? [];
        if (!is_array($settings)) $settings = [];
        $data['settings'] = $settings;

        $this->load->view('include/header');
        $this->load->view('admission_crm/pipeline', $data);
        $this->load->view('include/footer');
    }

    public function fetch_pipeline()
    {
        $this->_require_role(self::VIEW_ROLES, 'crm_fetch');
        $applications = $this->firebase->get("{$this->crm_base}/Applications") ?? [];
        if (!is_array($applications)) $applications = [];

        $settings = $this->firebase->get("{$this->crm_base}/Settings") ?? [];
        $stages = $settings['stages'] ?? $this->_default_stages();

        $session = $this->session_year;
        $pipeline = [];
        foreach ($stages as $key => $label) {
            $pipeline[$key] = ['label' => $label, 'items' => []];
        }

        foreach ($applications as $id => $app) {
            if (!is_array($app)) continue;
            if (($app['session'] ?? '') !== $session) continue;
            if (($app['status'] ?? '') === 'enrolled') continue; // already enrolled, skip

            $stage = $app['stage'] ?? 'document_collection';
            $app['id'] = $id;
            if (isset($pipeline[$stage])) {
                $pipeline[$stage]['items'][] = $app;
            } else {
                // Unknown stage → put in first
                $firstKey = array_key_first($pipeline);
                if ($firstKey) $pipeline[$firstKey]['items'][] = $app;
            }
        }

        return $this->json_success(['pipeline' => $pipeline, 'stages' => $stages]);
    }

    public function update_stage()
    {
        $this->_require_role(self::MANAGE_ROLES, 'crm_update_stage');
        $id    = trim($this->input->post('id') ?? '');
        $stage = $this->input->post('stage');
        if (!$id || !$stage) return $this->json_error('Application ID and stage required');
        $id = $this->safe_path_segment($id, 'id');

        // Validate stage against allowed stages
        $settings = $this->firebase->get("{$this->crm_base}/Settings") ?? [];
        $allowedStages = (is_array($settings) && !empty($settings['stages']))
            ? array_keys($settings['stages'])
            : array_keys($this->_default_stages());
        if (!in_array($stage, $allowedStages, true)) {
            return $this->json_error('Invalid stage: ' . $stage);
        }

        $app = $this->firebase->get("{$this->crm_base}/Applications/{$id}");
        if (!is_array($app)) return $this->json_error('Application not found');

        $now = date('Y-m-d H:i:s');
        $history = $app['history'] ?? [];
        $history[] = [
            'action'    => "Stage changed: {$app['stage']} → {$stage}",
            'by'        => $this->admin_name,
            'timestamp' => $now,
        ];

        $this->firebase->update("{$this->crm_base}/Applications/{$id}", [
            'stage'      => $stage,
            'updated_at' => $now,
            'history'    => $history,
        ]);

        return $this->json_success();
    }

    /* ══════════════════════════════════════════════════════════════════════
       APPROVAL WORKFLOW
    ══════════════════════════════════════════════════════════════════════ */

    public function approve_application()
    {
        $this->_require_role(self::MANAGE_ROLES, 'crm_approve');
        $id      = trim($this->input->post('id') ?? '');
        $remarks = trim($this->input->post('remarks') ?? '');
        if (!$id) return $this->json_error('Application ID required');
        $id = $this->safe_path_segment($id, 'id');

        $app = $this->firebase->get("{$this->crm_base}/Applications/{$id}");
        if (!is_array($app)) return $this->json_error('Application not found');

        $currentStatus = $app['status'] ?? 'pending';
        if ($currentStatus === 'enrolled') {
            return $this->json_error('Cannot approve an already enrolled application');
        }
        if ($currentStatus === 'approved') {
            return $this->json_error('Application is already approved');
        }

        $now = date('Y-m-d H:i:s');
        $history = $app['history'] ?? [];
        $history[] = [
            'action'    => 'Application approved' . ($remarks ? ": {$remarks}" : ''),
            'by'        => $this->admin_name,
            'timestamp' => $now,
        ];

        $this->firebase->update("{$this->crm_base}/Applications/{$id}", [
            'status'       => 'approved',
            'stage'        => 'approved',
            'approved_by'  => $this->admin_name,
            'approved_at'  => $now,
            'remarks'      => $remarks,
            'updated_at'   => $now,
            'history'      => $history,
        ]);

        return $this->json_success();
    }

    public function reject_application()
    {
        $this->_require_role(self::MANAGE_ROLES, 'crm_reject');
        $id     = trim($this->input->post('id') ?? '');
        $reason = trim($this->input->post('reason') ?? '');
        if (!$id) return $this->json_error('Application ID required');
        $id = $this->safe_path_segment($id, 'id');

        $app = $this->firebase->get("{$this->crm_base}/Applications/{$id}");
        if (!is_array($app)) return $this->json_error('Application not found');

        $currentStatus = $app['status'] ?? 'pending';
        if ($currentStatus === 'enrolled') {
            return $this->json_error('Cannot reject an already enrolled application');
        }
        if ($currentStatus === 'rejected') {
            return $this->json_error('Application is already rejected');
        }

        $now = date('Y-m-d H:i:s');
        $history = $app['history'] ?? [];
        $history[] = [
            'action'    => 'Application rejected' . ($reason ? ": {$reason}" : ''),
            'by'        => $this->admin_name,
            'timestamp' => $now,
        ];

        $this->firebase->update("{$this->crm_base}/Applications/{$id}", [
            'status'      => 'rejected',
            'stage'       => 'rejected',
            'rejected_by' => $this->admin_name,
            'rejected_at' => $now,
            'reject_reason' => $reason,
            'updated_at'  => $now,
            'history'     => $history,
        ]);

        return $this->json_success();
    }

    public function enroll_student()
    {
        $this->_require_role(self::MANAGE_ROLES, 'crm_enroll');
        $id = trim($this->input->post('id') ?? '');
        if (!$id) return $this->json_error('Application ID required');
        $id = $this->safe_path_segment($id, 'id');

        $app = $this->firebase->get("{$this->crm_base}/Applications/{$id}");
        if (!is_array($app)) return $this->json_error('Application not found');

        if (($app['status'] ?? '') !== 'approved') {
            return $this->json_error('Only approved applications can be enrolled');
        }

        $school_id   = $this->parent_db_key;
        $school_name = $this->school_name;
        $session     = $this->session_year;

        // Generate student ID
        $studentIdCount = (int)($this->firebase->get("Users/Parents/{$school_id}/Count") ?? 0);
        if ($studentIdCount === 0) $studentIdCount = 1;
        $studentId = 'STU000' . $studentIdCount;

        $className = trim($app['class'] ?? '');
        $section   = trim($app['section'] ?? 'A');

        if ($className === '') return $this->json_error('Class not specified in application');

        // Build class node path
        $classNode = "Class {$className}";
        $combinedPath = "{$classNode}/Section {$section}";

        // Format DOB
        $formattedDOB = '';
        if (!empty($app['dob'])) {
            $formattedDOB = date('d-m-Y', strtotime($app['dob']));
        }

        $now = date('Y-m-d H:i:s');

        // Build student data matching existing schema
        $studentData = [
            "Name"              => $app['student_name'] ?? '',
            "User Id"           => $studentId,
            "DOB"               => $formattedDOB,
            "Admission Date"    => date('d-m-Y'),
            "Class"             => $className,
            "Section"           => $section,
            "Gender"            => $app['gender'] ?? '',
            "Blood Group"       => $app['blood_group'] ?? '',
            "Category"          => $app['category'] ?? '',
            "Religion"          => $app['religion'] ?? '',
            "Nationality"       => $app['nationality'] ?? '',
            "Father Name"       => $app['father_name'] ?? '',
            "Father Occupation" => $app['father_occupation'] ?? '',
            "Mother Name"       => $app['mother_name'] ?? '',
            "Mother Occupation" => $app['mother_occupation'] ?? '',
            "Guard Contact"     => $app['guardian_phone'] ?? '',
            "Guard Relation"    => $app['guardian_relation'] ?? '',
            "Phone Number"      => $app['phone'] ?? '',
            "Email"             => $app['email'] ?? '',
            "Password"          => $this->_generate_password($app['student_name'] ?? '', $formattedDOB),
            "Address"           => [
                "Street"     => $app['address'] ?? '',
                "City"       => $app['city'] ?? '',
                "State"      => $app['state'] ?? '',
                "PostalCode" => $app['pincode'] ?? '',
            ],
            "Pre School"        => $app['previous_school'] ?? '',
            "Pre Class"         => $app['previous_class'] ?? '',
            "Pre Marks"         => $app['previous_marks'] ?? '',
            "Profile Pic"       => "",
            "Doc"               => [
                "Aadhar Card"          => ["thumbnail" => "", "url" => ""],
                "Birth Certificate"    => ["thumbnail" => "", "url" => ""],
                "Photo"                => ["thumbnail" => "", "url" => ""],
                "Transfer Certificate" => ["thumbnail" => "", "url" => ""],
            ],
        ];

        // Insert student profile
        $this->firebase->set("Users/Parents/{$school_id}/{$studentId}", $studentData);

        // Add to class roster
        $this->firebase->update(
            "Schools/{$school_name}/{$session}/{$combinedPath}/Students",
            [$studentId => ['Name' => $app['student_name'] ?? '']]
        );
        $this->firebase->update(
            "Schools/{$school_name}/{$session}/{$combinedPath}/Students/List",
            [$studentId => $app['student_name'] ?? '']
        );

        // Update count
        $this->firebase->set("Users/Parents/{$school_id}/Count", $studentIdCount + 1);

        // Phone mapping
        $phone = trim($app['phone'] ?? '');
        if ($phone !== '') {
            // Tenant-scoped phone index (primary)
            $this->firebase->update("Schools/{$school_name}/Phone_Index", [$phone => $studentId]);
            // Legacy global indexes — kept for mobile app backward compatibility
            $this->firebase->update('Exits', [$phone => $school_id]);
            $this->firebase->update('User_ids_pno', [$phone => $studentId]);
        }

        // Update application status
        $history = $app['history'] ?? [];
        $history[] = [
            'action'    => "Enrolled as {$studentId} in Class {$className} Section {$section}",
            'by'        => $this->admin_name,
            'timestamp' => $now,
        ];

        $this->firebase->update("{$this->crm_base}/Applications/{$id}", [
            'status'       => 'enrolled',
            'stage'        => 'enrolled',
            'student_id'   => $studentId,
            'enrolled_at'  => $now,
            'enrolled_by'  => $this->admin_name,
            'updated_at'   => $now,
            'history'      => $history,
        ]);

        return $this->json_success([
            'student_id' => $studentId,
            'class'      => $className,
            'section'    => $section,
        ]);
    }

    /* ══════════════════════════════════════════════════════════════════════
       WAITLIST
    ══════════════════════════════════════════════════════════════════════ */

    public function waitlist()
    {
        $this->_require_role(self::VIEW_ROLES, 'crm_view');
        $data['session_year'] = $this->session_year;
        $data['classes']      = $this->_get_classes();

        $this->load->view('include/header');
        $this->load->view('admission_crm/waitlist', $data);
        $this->load->view('include/footer');
    }

    public function fetch_waitlist()
    {
        $this->_require_role(self::VIEW_ROLES, 'crm_fetch');
        $waitlist = $this->firebase->get("{$this->crm_base}/Waitlist") ?? [];
        if (!is_array($waitlist)) $waitlist = [];

        $session = $this->session_year;
        $result = [];
        foreach ($waitlist as $id => $w) {
            if (!is_array($w)) continue;
            if (($w['session'] ?? '') !== $session) continue;
            $w['id'] = $id;
            $result[] = $w;
        }

        usort($result, function($a, $b) {
            $p = ($a['priority'] ?? 999) - ($b['priority'] ?? 999);
            if ($p !== 0) return $p;
            return strcmp($a['created_at'] ?? '', $b['created_at'] ?? '');
        });

        return $this->json_success(['waitlist' => $result]);
    }

    public function add_to_waitlist()
    {
        $this->_require_role(self::MANAGE_ROLES, 'crm_waitlist_add');
        $app_id = trim($this->input->post('application_id') ?? '');
        $reason = trim($this->input->post('reason') ?? '');
        $priority = (int)($this->input->post('priority') ?? 99);

        if (!$app_id) return $this->json_error('Application ID required');
        $app_id = $this->safe_path_segment($app_id, 'application_id');

        $app = $this->firebase->get("{$this->crm_base}/Applications/{$app_id}");
        if (!is_array($app)) return $this->json_error('Application not found');

        $now = date('Y-m-d H:i:s');

        $counter = (int)($this->firebase->get("{$this->crm_base}/Counter") ?? 0);
        $counter++;
        $wl_id = 'WL' . str_pad($counter, 5, '0', STR_PAD_LEFT);

        $waitEntry = [
            'waitlist_id'    => $wl_id,
            'application_id' => $app_id,
            'student_name'   => $app['student_name'] ?? '',
            'parent_name'    => $app['parent_name'] ?? '',
            'phone'          => $app['phone'] ?? '',
            'class'          => $app['class'] ?? '',
            'session'        => $app['session'] ?? $this->session_year,
            'priority'       => $priority,
            'reason'         => $reason,
            'status'         => 'waiting',
            'created_at'     => $now,
            'updated_at'     => $now,
        ];

        $this->firebase->set("{$this->crm_base}/Waitlist/{$wl_id}", $waitEntry);
        $this->firebase->set("{$this->crm_base}/Counter", $counter);

        // Update application stage
        $history = $app['history'] ?? [];
        $history[] = [
            'action'    => 'Added to waitlist',
            'by'        => $this->admin_name,
            'timestamp' => $now,
        ];
        $this->firebase->update("{$this->crm_base}/Applications/{$app_id}", [
            'status'     => 'waitlisted',
            'stage'      => 'waitlisted',
            'updated_at' => $now,
            'history'    => $history,
        ]);

        return $this->json_success(['id' => $wl_id]);
    }

    public function remove_from_waitlist()
    {
        $this->_require_role(self::MANAGE_ROLES, 'crm_waitlist_remove');
        $id = trim($this->input->post('id') ?? '');
        if (!$id) return $this->json_error('Waitlist ID required');
        $id = $this->safe_path_segment($id, 'id');

        $entry = $this->firebase->get("{$this->crm_base}/Waitlist/{$id}");
        if (is_array($entry) && !empty($entry['application_id'])) {
            // Restore application to pending
            $this->firebase->update("{$this->crm_base}/Applications/{$entry['application_id']}", [
                'status'     => 'pending',
                'stage'      => 'document_collection',
                'updated_at' => date('Y-m-d H:i:s'),
            ]);
        }

        $this->firebase->delete("{$this->crm_base}/Waitlist", $id);
        return $this->json_success();
    }

    public function promote_from_waitlist()
    {
        $this->_require_role(self::MANAGE_ROLES, 'crm_waitlist_promote');
        $id = trim($this->input->post('id') ?? '');
        if (!$id) return $this->json_error('Waitlist ID required');
        $id = $this->safe_path_segment($id, 'id');

        $entry = $this->firebase->get("{$this->crm_base}/Waitlist/{$id}");
        if (!is_array($entry)) return $this->json_error('Waitlist entry not found');

        $app_id = $entry['application_id'] ?? '';
        if (!$app_id) return $this->json_error('No linked application');

        $now = date('Y-m-d H:i:s');

        // Move application to approved
        $app = $this->firebase->get("{$this->crm_base}/Applications/{$app_id}");
        if (is_array($app)) {
            $history = $app['history'] ?? [];
            $history[] = [
                'action'    => 'Promoted from waitlist and approved',
                'by'        => $this->admin_name,
                'timestamp' => $now,
            ];
            $this->firebase->update("{$this->crm_base}/Applications/{$app_id}", [
                'status'      => 'approved',
                'stage'       => 'approved',
                'approved_by' => $this->admin_name,
                'approved_at' => $now,
                'updated_at'  => $now,
                'history'     => $history,
            ]);
        }

        // Remove from waitlist
        $this->firebase->delete("{$this->crm_base}/Waitlist", $id);

        return $this->json_success();
    }

    /* ══════════════════════════════════════════════════════════════════════
       SETTINGS
    ══════════════════════════════════════════════════════════════════════ */

    public function settings()
    {
        $this->_require_role(self::VIEW_ROLES, 'crm_view');
        $settings = $this->firebase->get("{$this->crm_base}/Settings") ?? [];
        if (!is_array($settings)) $settings = [];

        $data['settings']     = $settings;
        $data['session_year'] = $this->session_year;
        $data['classes']      = $this->_get_classes();

        $this->load->view('include/header');
        $this->load->view('admission_crm/settings', $data);
        $this->load->view('include/footer');
    }

    public function save_settings()
    {
        $this->_require_role(self::MANAGE_ROLES, 'crm_save_settings');
        $stages_json   = $this->input->post('stages');
        $class_limits  = $this->input->post('class_limits');
        $form_fields   = $this->input->post('form_fields');
        $notifications = $this->input->post('notifications');

        $settings = $this->firebase->get("{$this->crm_base}/Settings") ?? [];
        if (!is_array($settings)) $settings = [];

        if ($stages_json) {
            $decoded = json_decode($stages_json, true);
            if (is_array($decoded)) $settings['stages'] = $decoded;
        }
        if ($class_limits) {
            $decoded = json_decode($class_limits, true);
            if (is_array($decoded)) $settings['class_limits'] = $decoded;
        }
        if ($form_fields) {
            $decoded = json_decode($form_fields, true);
            if (is_array($decoded)) $settings['form_fields'] = $decoded;
        }
        if ($notifications) {
            $decoded = json_decode($notifications, true);
            if (is_array($decoded)) $settings['notifications'] = $decoded;
        }

        $settings['updated_at'] = date('Y-m-d H:i:s');
        $this->firebase->set("{$this->crm_base}/Settings", $settings);

        return $this->json_success();
    }

    public function get_settings()
    {
        $this->_require_role(self::VIEW_ROLES, 'crm_fetch');
        $settings = $this->firebase->get("{$this->crm_base}/Settings") ?? [];
        if (!is_array($settings)) $settings = [];

        // Merge defaults
        if (empty($settings['stages'])) {
            $settings['stages'] = $this->_default_stages();
        }

        return $this->json_success(['settings' => $settings]);
    }

    /* ══════════════════════════════════════════════════════════════════════
       ONLINE ADMISSION FORM (public-facing stub — can be opened without auth)
    ══════════════════════════════════════════════════════════════════════ */

    public function online_form()
    {
        $school_name = $this->school_name;
        $session     = $this->session_year;

        // Get settings for form fields
        $settings = $this->firebase->get("{$this->crm_base}/Settings") ?? [];
        $classes  = $this->_get_classes();

        // Get school profile for header
        $profile = $this->firebase->get("Schools/{$school_name}/Config/Profile") ?? [];

        $data['school_name']  = $school_name;
        $data['session_year'] = $session;
        $data['settings']     = is_array($settings) ? $settings : [];
        $data['classes']      = $classes;
        $data['profile']      = is_array($profile) ? $profile : [];

        $this->load->view('admission_crm/online_form', $data);
    }

    public function submit_online_form()
    {
        $now = date('Y-m-d H:i:s');

        $fields = [
            'student_name', 'parent_name', 'father_name', 'mother_name',
            'phone', 'email', 'class', 'dob', 'gender',
            'address', 'city', 'state', 'pincode',
            'previous_school', 'previous_class',
            'blood_group', 'category', 'religion', 'nationality',
            'father_occupation', 'mother_occupation',
            'notes',
        ];

        $data = [];
        foreach ($fields as $f) {
            $data[$f] = trim($this->input->post($f) ?? '');
        }

        if ($data['student_name'] === '' || $data['phone'] === '' || $data['class'] === '') {
            return $this->json_error('Student name, phone, and class are required');
        }

        // Phone validation
        $cleanPhone = preg_replace('/[\s\-]/', '', $data['phone']);
        if (!preg_match('/^\+?\d{10,15}$/', $cleanPhone)) {
            return $this->json_error('Invalid phone number format');
        }
        // Email validation (if provided)
        if ($data['email'] !== '' && !filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
            return $this->json_error('Invalid email address');
        }

        // Duplicate detection: check if same phone already has a pending/approved application this session
        $existingApps = $this->firebase->get("{$this->crm_base}/Applications") ?? [];
        if (is_array($existingApps)) {
            foreach ($existingApps as $ea) {
                if (!is_array($ea)) continue;
                if (($ea['session'] ?? '') !== $this->session_year) continue;
                if (($ea['phone'] ?? '') === $data['phone'] && in_array($ea['status'] ?? '', ['pending', 'approved', 'waitlisted', 'enrolled'])) {
                    return $this->json_error('An application with this phone number already exists for this session (ID: ' . ($ea['application_id'] ?? 'N/A') . ')');
                }
            }
        }

        // Create both inquiry and application
        $counter = (int)($this->firebase->get("{$this->crm_base}/Counter") ?? 0);

        // Inquiry
        $counter++;
        $inq_id = 'INQ' . str_pad($counter, 5, '0', STR_PAD_LEFT);
        $this->firebase->set("{$this->crm_base}/Inquiries/{$inq_id}", [
            'inquiry_id'   => $inq_id,
            'student_name' => $data['student_name'],
            'parent_name'  => $data['parent_name'],
            'phone'        => $data['phone'],
            'email'        => $data['email'],
            'class'        => $data['class'],
            'source'       => 'Online Form',
            'status'       => 'converted',
            'session'      => $this->session_year,
            'created_at'   => $now,
            'updated_at'   => $now,
            'created_by'   => 'Online',
        ]);

        // Application
        $counter++;
        $app_id = 'APP' . str_pad($counter, 5, '0', STR_PAD_LEFT);
        $appData = array_merge($data, [
            'application_id' => $app_id,
            'inquiry_id'     => $inq_id,
            'session'        => $this->session_year,
            'status'         => 'pending',
            'stage'          => 'document_collection',
            'created_at'     => $now,
            'updated_at'     => $now,
            'created_by'     => 'Online',
            'documents'      => [],
            'history'        => [[
                'action'    => 'Application submitted via online form',
                'by'        => 'Online',
                'timestamp' => $now,
            ]],
        ]);

        $this->firebase->set("{$this->crm_base}/Applications/{$app_id}", $appData);

        // Update inquiry with app link
        $this->firebase->update("{$this->crm_base}/Inquiries/{$inq_id}", [
            'application_id' => $app_id,
        ]);

        $this->firebase->set("{$this->crm_base}/Counter", $counter);

        return $this->json_success([
            'application_id' => $app_id,
        ]);
    }

    /* ══════════════════════════════════════════════════════════════════════
       ANALYTICS ENDPOINT (AJAX)
    ══════════════════════════════════════════════════════════════════════ */

    public function fetch_analytics()
    {
        $this->_require_role(self::VIEW_ROLES, 'crm_fetch');
        $inquiries    = $this->firebase->get("{$this->crm_base}/Inquiries") ?? [];
        $applications = $this->firebase->get("{$this->crm_base}/Applications") ?? [];
        $waitlist     = $this->firebase->get("{$this->crm_base}/Waitlist") ?? [];

        if (!is_array($inquiries))    $inquiries = [];
        if (!is_array($applications)) $applications = [];
        if (!is_array($waitlist))     $waitlist = [];

        $session = $this->session_year;

        // Filter current session
        $sInq  = array_filter($inquiries, fn($i) => is_array($i) && ($i['session'] ?? '') === $session);
        $sApp  = array_filter($applications, fn($a) => is_array($a) && ($a['session'] ?? '') === $session);
        $sWl   = array_filter($waitlist, fn($w) => is_array($w) && ($w['session'] ?? '') === $session);

        // Conversion funnel
        $funnel = [
            'inquiries'    => count($sInq),
            'applications' => count($sApp),
            'approved'     => count(array_filter($sApp, fn($a) => ($a['status'] ?? '') === 'approved')),
            'enrolled'     => count(array_filter($sApp, fn($a) => ($a['status'] ?? '') === 'enrolled')),
            'rejected'     => count(array_filter($sApp, fn($a) => ($a['status'] ?? '') === 'rejected')),
            'waitlisted'   => count($sWl),
        ];

        // Source breakdown
        $sources = [];
        foreach ($sInq as $i) {
            $s = $i['source'] ?? 'Walk-in';
            $sources[$s] = ($sources[$s] ?? 0) + 1;
        }

        // Class breakdown
        $classes = [];
        foreach ($sApp as $a) {
            $c = $a['class'] ?? 'Unknown';
            $st = $a['status'] ?? 'pending';
            if (!isset($classes[$c])) $classes[$c] = ['total' => 0, 'approved' => 0, 'enrolled' => 0, 'pending' => 0, 'rejected' => 0];
            $classes[$c]['total']++;
            if (isset($classes[$c][$st])) $classes[$c][$st]++;
        }

        // Monthly trend
        $monthly = [];
        foreach ($sInq as $i) {
            $m = substr($i['created_at'] ?? '', 0, 7);
            if ($m) $monthly[$m] = ($monthly[$m] ?? 0) + 1;
        }
        ksort($monthly);

        return $this->json_success([
            'funnel'  => $funnel,
            'sources' => $sources,
            'classes' => $classes,
            'monthly' => $monthly,
        ]);
    }

    /* ══════════════════════════════════════════════════════════════════════
       HELPERS
    ══════════════════════════════════════════════════════════════════════ */

    private function _default_stages()
    {
        return [
            'document_collection' => 'Document Collection',
            'under_review'        => 'Under Review',
            'interview'           => 'Interview / Test',
            'approved'            => 'Approved',
            'rejected'            => 'Rejected',
            'waitlisted'          => 'Waitlisted',
        ];
    }

    private function _get_classes()
    {
        $school_name = $this->school_name;
        $session     = $this->session_year;
        $classes = [];

        $sessionKeys = $this->firebase->shallow_get("Schools/{$school_name}/{$session}");
        if (!is_array($sessionKeys)) return $classes;

        foreach ($sessionKeys as $key) {
            if (strpos($key, 'Class ') !== 0) continue;
            $sectionKeys = $this->firebase->shallow_get("Schools/{$school_name}/{$session}/{$key}");
            if (!is_array($sectionKeys)) continue;
            foreach ($sectionKeys as $sk) {
                if (strpos($sk, 'Section ') !== 0) continue;
                $classes[] = [
                    'class_name' => $key,
                    'section'    => str_replace('Section ', '', $sk),
                    'label'      => $key . ' / Section ' . str_replace('Section ', '', $sk),
                ];
            }
        }

        return $classes;
    }

    private function _generate_password($name, $dob)
    {
        $parts = explode(' ', strtolower(trim($name)));
        $first = $parts[0] ?? 'student';
        $dobParts = explode('-', $dob);
        $year = end($dobParts);
        return $first . '@' . $year;
    }
}
