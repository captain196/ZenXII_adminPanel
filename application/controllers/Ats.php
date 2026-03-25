<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Applicant Tracking System (ATS) Controller
 *
 * Full hiring pipeline: APPLIED → SHORTLISTED → INTERVIEWED → SELECTED → HIRED
 * Integrates with HR job postings and Staff module for convert-to-staff flow.
 *
 * Firebase paths:
 *   Schools/{school_name}/HR/Recruitment/Applicants/{APP0001}  — shared with HR
 *   Schools/{school_name}/HR/Recruitment/Jobs/{JOB0001}        — shared with HR
 *   Schools/{school_name}/HR/Counters/Applicant                — shared counter
 */
class Ats extends MY_Controller
{
    /** Roles that can manage ATS (move stages, add reviews, convert) */
    const ADMIN_ROLES = ['Super Admin', 'School Super Admin', 'Admin', 'Principal', 'Vice Principal', 'HR Manager'];

    /** Roles that can view the pipeline */
    const VIEW_ROLES  = ['Super Admin', 'School Super Admin', 'Principal', 'Vice Principal', 'Admin', 'Teacher', 'HR Manager'];

    /** Ordered hiring stages */
    const STAGES = ['Applied', 'Shortlisted', 'Interviewed', 'Selected', 'Hired'];

    /** Valid terminal statuses */
    const STATUSES = ['active', 'rejected', 'hired', 'withdrawn'];

    /** Allowed review ratings */
    const MIN_RATING = 1;
    const MAX_RATING = 5;

    /** Max review comment length */
    const MAX_COMMENT_LENGTH = 2000;

    /** Stage mapping: HR module uses slightly different names */
    const HR_STATUS_MAP = [
        'Applied'      => 'Applied',
        'Shortlisted'  => 'Shortlisted',
        'Interviewed'  => 'Interview',
        'Selected'     => 'Selected',
        'Hired'        => 'Joined',
    ];

    public function __construct()
    {
        parent::__construct();
        require_permission('HR');
    }

    // ── Access helpers ───────────────────────────────────────────────

    private function _require_admin()
    {
        if (!in_array($this->admin_role, self::ADMIN_ROLES, true)) {
            $this->json_error('Access denied.', 403);
        }
    }

    private function _require_view()
    {
        if (!in_array($this->admin_role, self::VIEW_ROLES, true)) {
            $this->json_error('Access denied.', 403);
        }
    }

    // ── Path helpers (shared with HR module) ─────────────────────────

    private function _hr(string $sub = ''): string
    {
        $b = "Schools/{$this->school_name}/HR";
        return $sub !== '' ? "{$b}/{$sub}" : $b;
    }

    private function _applicants(string $id = ''): string
    {
        return $this->_hr('Recruitment/Applicants') . ($id !== '' ? "/{$id}" : '');
    }

    private function _jobs(string $id = ''): string
    {
        return $this->_hr('Recruitment/Jobs') . ($id !== '' ? "/{$id}" : '');
    }

    private function _counter(string $type): string
    {
        return $this->_hr("Counters/{$type}");
    }

    private function _next_id(string $prefix, string $type, int $pad = 4): string
    {
        $path = $this->_counter($type);
        $cur  = (int) ($this->firebase->get($path) ?? 0);
        $next = $cur + 1;
        $this->firebase->set($path, $next);
        return $prefix . str_pad($next, $pad, '0', STR_PAD_LEFT);
    }

    // ── Validation helpers ───────────────────────────────────────────

    private function _sanitize(string $text): string
    {
        return htmlspecialchars(trim($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    private function _get_stage_index(string $stage): int
    {
        $idx = array_search($stage, self::STAGES, true);
        return $idx !== false ? $idx : -1;
    }

    /**
     * Validate that a stage transition is allowed.
     * Returns true if valid, error message string if not.
     */
    private function _validate_transition(string $currentStage, string $newStage, bool $strict = true)
    {
        $curIdx = $this->_get_stage_index($currentStage);
        $newIdx = $this->_get_stage_index($newStage);

        if ($curIdx === -1 || $newIdx === -1) {
            return 'Invalid stage.';
        }

        if ($newIdx === $curIdx) {
            return 'Candidate is already at this stage.';
        }

        // Cannot go backward
        if ($newIdx < $curIdx) {
            return "Cannot move backward from {$currentStage} to {$newStage}.";
        }

        // Strict mode: must advance exactly one step (except rejecting)
        if ($strict && ($newIdx - $curIdx) > 1) {
            $expected = self::STAGES[$curIdx + 1];
            return "Cannot skip stages. Next stage should be {$expected}.";
        }

        // Cannot move directly to Hired — must use convert_to_staff flow
        if ($newStage === 'Hired') {
            return 'Candidates can only be hired through the Convert to Staff flow.';
        }

        return true;
    }

    // ====================================================================
    //  PAGE ROUTES
    // ====================================================================

    /**
     * GET /ats — Main ATS pipeline dashboard (SPA).
     */
    public function index()
    {
        $this->_require_role(self::VIEW_ROLES, 'ats_view');

        $data = ['active_tab' => 'pipeline'];
        $this->load->view('include/header', $data);
        $this->load->view('ats/index', $data);
        $this->load->view('include/footer');
    }

    // ====================================================================
    //  AJAX — DASHBOARD / PIPELINE DATA
    // ====================================================================

    /**
     * GET — Pipeline overview: counts per stage, recent activity.
     */
    public function get_pipeline()
    {
        $this->_require_view();

        $raw = $this->firebase->get($this->_applicants());
        $stageCounts = array_fill_keys(self::STAGES, 0);
        $rejected    = 0;
        $all         = [];

        if (is_array($raw)) {
            foreach ($raw as $id => $a) {
                if (!is_array($a)) continue;
                $a['id'] = $id;

                $stage  = $a['stage'] ?? ($a['status'] ?? 'Applied');
                $status = $a['ats_status'] ?? 'active';

                // Map HR status values to ATS stages
                $stage = $this->_normalize_stage($stage);

                if ($status === 'rejected') {
                    $rejected++;
                } else {
                    $stageCounts[$stage] = ($stageCounts[$stage] ?? 0) + 1;
                }
                $all[] = $a;
            }
        }

        // Sort by most recently updated
        usort($all, fn($a, $b) => strcmp($b['updated_at'] ?? $b['applied_date'] ?? '', $a['updated_at'] ?? $a['applied_date'] ?? ''));

        // Get job postings for reference (flat array for JS forEach compat)
        $jobsRaw = $this->firebase->get($this->_jobs());
        $jobs    = [];
        if (is_array($jobsRaw)) {
            foreach ($jobsRaw as $jid => $j) {
                if (!is_array($j)) continue;
                $jobs[] = [
                    'id'         => $jid,
                    'title'      => $j['title'] ?? '',
                    'department' => $j['department'] ?? '',
                    'positions'  => (int) ($j['positions'] ?? 0),
                    'status'     => $j['status'] ?? '',
                ];
            }
        }

        $this->json_success([
            'stage_counts' => $stageCounts,
            'rejected'     => $rejected,
            'total'        => count($all),
            'applicants'   => $all,
            'jobs'         => $jobs,
        ]);
    }

    /**
     * Normalize HR status names to ATS stage names.
     */
    private function _normalize_stage(string $stage): string
    {
        $map = [
            'Applied'     => 'Applied',
            'Shortlisted' => 'Shortlisted',
            'Interview'   => 'Interviewed',
            'Interviewed' => 'Interviewed',
            'Selected'    => 'Selected',
            'Joined'      => 'Hired',
            'Hired'       => 'Hired',
        ];
        return $map[$stage] ?? 'Applied';
    }

    // ====================================================================
    //  AJAX — APPLICANT CRUD
    // ====================================================================

    /**
     * GET — Get single applicant with full history.
     */
    public function get_applicant()
    {
        $this->_require_view();

        $id = $this->safe_path_segment(trim($this->input->get('id') ?? ''), 'id');
        $applicant = $this->firebase->get($this->_applicants($id));
        if (!is_array($applicant)) {
            $this->json_error('Applicant not found.');
        }

        $applicant['id'] = $id;

        // Normalize stage
        $applicant['stage'] = $this->_normalize_stage($applicant['stage'] ?? $applicant['status'] ?? 'Applied');

        // Get linked job info
        $jobId = $applicant['job_id'] ?? '';
        if ($jobId !== '') {
            $job = $this->firebase->get($this->_jobs($jobId));
            if (is_array($job)) {
                $applicant['job_title']      = $job['title'] ?? '';
                $applicant['job_department'] = $job['department'] ?? '';
            }
        }

        $this->json_success(['applicant' => $applicant]);
    }

    /**
     * POST — Create a new applicant.
     */
    public function save_applicant()
    {
        $this->_require_admin();

        $id    = trim($this->input->post('id') ?? '');
        $jobId = trim($this->input->post('job_id') ?? '');
        $name  = trim($this->input->post('name') ?? '');
        $email = trim($this->input->post('email') ?? '');
        $phone = trim($this->input->post('phone') ?? '');
        $qualification = trim($this->input->post('qualification') ?? '');
        $experience    = trim($this->input->post('experience') ?? '');
        $resumeNotes   = trim($this->input->post('resume_notes') ?? '');

        if ($name === '') $this->json_error('Candidate name is required.');
        if ($jobId === '') $this->json_error('Job posting is required.');

        $jobId = $this->safe_path_segment($jobId, 'job_id');
        $job   = $this->firebase->get($this->_jobs($jobId));
        if (!is_array($job)) $this->json_error('Job posting not found.');

        $name        = $this->_sanitize($name);
        $email       = $this->_sanitize($email);
        $phone       = $this->_sanitize($phone);
        $resumeNotes = $this->_sanitize($resumeNotes);

        $now   = date('c');
        $isNew = ($id === '');

        if ($isNew) {
            $id = $this->_next_id('APP', 'Applicant');
        } else {
            $id = $this->safe_path_segment($id, 'id');
            $existing = $this->firebase->get($this->_applicants($id));
            if (!is_array($existing)) $this->json_error('Applicant not found.');
        }

        $data = [
            'job_id'        => $jobId,
            'name'          => $name,
            'email'         => $email,
            'phone'         => $phone,
            'qualification' => $this->_sanitize($qualification),
            'experience'    => $this->_sanitize($experience),
            'resume_notes'  => $resumeNotes,
            'stage'         => $isNew ? 'Applied' : ($existing['stage'] ?? 'Applied'),
            'status'        => $isNew ? 'Applied' : ($existing['status'] ?? 'Applied'),
            'ats_status'    => $isNew ? 'active' : ($existing['ats_status'] ?? 'active'),
            'reviews'       => $isNew ? [] : ($existing['reviews'] ?? []),
            'applied_date'  => $isNew ? date('Y-m-d') : ($existing['applied_date'] ?? date('Y-m-d')),
            'updated_at'    => $now,
            'updated_by'    => $this->admin_name ?? 'Admin',
        ];

        if ($isNew) {
            $data['created_at'] = $now;
            $this->firebase->set($this->_applicants($id), $data);
        } else {
            // Use update to preserve HR-managed fields (interview_date, interview_notes, etc.)
            $this->firebase->update($this->_applicants($id), $data);
        }

        $this->json_success(['id' => $id, 'message' => $isNew ? 'Applicant added to pipeline.' : 'Applicant updated.']);
    }

    // ====================================================================
    //  AJAX — STAGE TRANSITIONS
    // ====================================================================

    /**
     * POST — Move applicant to next stage.
     * Params: id, new_stage, strict (optional, defaults to true)
     */
    public function move_stage()
    {
        $this->_require_admin();

        $id       = $this->safe_path_segment(trim($this->input->post('id') ?? ''), 'id');
        $newStage = trim($this->input->post('new_stage') ?? '');
        $strict   = ($this->input->post('strict') ?? '1') !== '0';

        if (!in_array($newStage, self::STAGES, true)) {
            $this->json_error('Invalid stage: ' . htmlspecialchars($newStage));
        }

        $applicant = $this->firebase->get($this->_applicants($id));
        if (!is_array($applicant)) $this->json_error('Applicant not found.');

        // Check not already hired
        if (($applicant['ats_status'] ?? '') === 'hired') {
            $this->json_error('This candidate has already been hired.');
        }

        $currentStage = $this->_normalize_stage($applicant['stage'] ?? $applicant['status'] ?? 'Applied');
        $validation   = $this->_validate_transition($currentStage, $newStage, $strict);

        if ($validation !== true) {
            $this->json_error($validation);
        }

        // Map ATS stage back to HR-compatible status
        $hrStatus = self::HR_STATUS_MAP[$newStage] ?? $newStage;

        $this->firebase->update($this->_applicants($id), [
            'stage'      => $newStage,
            'status'     => $hrStatus,
            'updated_at' => date('c'),
            'updated_by' => $this->admin_name ?? 'Admin',
        ]);

        $this->json_success([
            'message'   => "Candidate moved to {$newStage}.",
            'new_stage' => $newStage,
        ]);
    }

    /**
     * POST — Reject an applicant (can happen from any stage).
     * Params: id, reason (optional)
     */
    public function reject_applicant()
    {
        $this->_require_admin();

        $id     = $this->safe_path_segment(trim($this->input->post('id') ?? ''), 'id');
        $reason = $this->_sanitize(trim($this->input->post('reason') ?? ''));

        $applicant = $this->firebase->get($this->_applicants($id));
        if (!is_array($applicant)) $this->json_error('Applicant not found.');

        $currentAtsStatus = $applicant['ats_status'] ?? '';
        if ($currentAtsStatus === 'hired') {
            $this->json_error('Cannot reject an already-hired candidate.');
        }
        if ($currentAtsStatus === 'rejected') {
            $this->json_error('This candidate is already rejected.');
        }

        $update = [
            'ats_status'  => 'rejected',
            'status'      => 'Rejected',
            'updated_at'  => date('c'),
            'updated_by'  => $this->admin_name ?? 'Admin',
        ];

        if ($reason !== '') {
            $update['rejection_reason'] = $reason;
        }

        // Add rejection review entry
        $reviews = $applicant['reviews'] ?? [];
        if (!is_array($reviews)) $reviews = [];
        $reviews[] = [
            'stage'     => $this->_normalize_stage($applicant['stage'] ?? 'Applied'),
            'action'    => 'rejected',
            'comment'   => $reason !== '' ? $reason : 'Rejected by admin.',
            'reviewer'  => $this->admin_name ?? 'Admin',
            'timestamp' => date('c'),
        ];
        $update['reviews'] = $reviews;

        $this->firebase->update($this->_applicants($id), $update);
        $this->json_success(['message' => 'Applicant rejected.']);
    }

    // ====================================================================
    //  AJAX — STAGE-WISE REVIEWS
    // ====================================================================

    /**
     * POST — Add a review/feedback at current stage.
     * Params: id, rating (1-5), comment, stage (optional — defaults to current)
     */
    public function add_review()
    {
        $this->_require_admin();

        $id      = $this->safe_path_segment(trim($this->input->post('id') ?? ''), 'id');
        $rating  = (int) ($this->input->post('rating') ?? 0);
        $comment = trim($this->input->post('comment') ?? '');

        if ($rating < self::MIN_RATING || $rating > self::MAX_RATING) {
            $this->json_error('Rating must be between ' . self::MIN_RATING . ' and ' . self::MAX_RATING . '.');
        }
        if ($comment === '') {
            $this->json_error('Review comment is required.');
        }
        if (mb_strlen($comment) > self::MAX_COMMENT_LENGTH) {
            $this->json_error('Comment exceeds maximum length of ' . self::MAX_COMMENT_LENGTH . ' characters.');
        }

        $applicant = $this->firebase->get($this->_applicants($id));
        if (!is_array($applicant)) $this->json_error('Applicant not found.');

        $currentStage = $this->_normalize_stage($applicant['stage'] ?? $applicant['status'] ?? 'Applied');

        $reviews = $applicant['reviews'] ?? [];
        if (!is_array($reviews)) $reviews = [];

        $review = [
            'stage'     => $currentStage,
            'rating'    => $rating,
            'comment'   => $this->_sanitize($comment),
            'reviewer'  => $this->admin_name ?? 'Admin',
            'timestamp' => date('c'),
        ];

        $reviews[] = $review;

        // Also update the top-level rating to the latest review's rating (for HR compat)
        $this->firebase->update($this->_applicants($id), [
            'reviews'    => $reviews,
            'rating'     => $rating,
            'updated_at' => date('c'),
        ]);

        $this->json_success([
            'message' => 'Review added for ' . $currentStage . ' stage.',
            'review'  => $review,
        ]);
    }

    /**
     * GET — Get all reviews for an applicant.
     */
    public function get_reviews()
    {
        $this->_require_view();

        $id = $this->safe_path_segment(trim($this->input->get('id') ?? ''), 'id');
        $applicant = $this->firebase->get($this->_applicants($id));
        if (!is_array($applicant)) $this->json_error('Applicant not found.');

        $reviews = $applicant['reviews'] ?? [];
        if (!is_array($reviews)) $reviews = [];

        $this->json_success(['reviews' => $reviews]);
    }

    // ====================================================================
    //  AJAX — CONVERT TO STAFF
    // ====================================================================

    /**
     * GET — Get prefill data for convert-to-staff.
     * Returns applicant + job data formatted for new_staff form.
     */
    public function get_convert_data()
    {
        $this->_require_admin();

        $id = $this->safe_path_segment(trim($this->input->get('id') ?? ''), 'id');
        $applicant = $this->firebase->get($this->_applicants($id));
        if (!is_array($applicant)) $this->json_error('Applicant not found.');

        $stage = $this->_normalize_stage($applicant['stage'] ?? $applicant['status'] ?? 'Applied');
        if ($stage !== 'Selected') {
            $this->json_error('Only candidates at the Selected stage can be converted to staff.');
        }

        if (($applicant['ats_status'] ?? '') === 'hired') {
            $this->json_error('This candidate has already been hired.');
        }

        // Get job info for role/department
        $jobId  = $applicant['job_id'] ?? '';
        $job    = null;
        $role   = '';
        $dept   = '';
        $position = '';

        if ($jobId !== '') {
            $job = $this->firebase->get($this->_jobs($jobId));
            if (is_array($job)) {
                $position = $job['title'] ?? '';
                $dept     = $job['department'] ?? '';
            }
        }

        $this->json_success([
            'application_id' => $id,
            'prefill' => [
                'Name'           => $applicant['name'] ?? '',
                'email'          => $applicant['email'] ?? '',
                'phone_number'   => $applicant['phone'] ?? '',
                'staff_position' => $position,
                'department'     => $dept,
                'qualification'  => $applicant['qualification'] ?? '',
                'experience'     => $applicant['experience'] ?? '',
            ],
        ]);
    }

    /**
     * POST — Finalize hiring: mark application as Hired, decrement job positions.
     * Called AFTER staff is successfully created.
     * Params: application_id, staff_id (newly created)
     */
    public function finalize_hire()
    {
        $this->_require_admin();

        $appId   = $this->safe_path_segment(trim($this->input->post('application_id') ?? ''), 'application_id');
        $staffId = trim($this->input->post('staff_id') ?? '');

        if ($appId === '') $this->json_error('Application ID is required.');
        if ($staffId === '') $this->json_error('Staff ID is required.');

        $applicant = $this->firebase->get($this->_applicants($appId));
        if (!is_array($applicant)) $this->json_error('Applicant not found.');

        // Idempotency: if already hired, succeed silently
        if (($applicant['ats_status'] ?? '') === 'hired') {
            $this->json_success(['message' => 'Already finalized.', 'already_done' => true]);
            return;
        }

        $stage = $this->_normalize_stage($applicant['stage'] ?? $applicant['status'] ?? 'Applied');
        if ($stage !== 'Selected') {
            $this->json_error('Applicant must be at Selected stage to finalize hire.');
        }

        // Verify staff record actually exists (data integrity)
        $staffPath = "Users/Teachers/{$this->school_id}/{$staffId}";
        $staff = $this->firebase->get($staffPath);
        if (!is_array($staff)) {
            $this->json_error('Staff record not found. Please ensure staff creation completed successfully.');
        }

        $now = date('c');

        // 1. Update application → Hired
        $reviews = $applicant['reviews'] ?? [];
        if (!is_array($reviews)) $reviews = [];
        $reviews[] = [
            'stage'     => 'Hired',
            'action'    => 'hired',
            'comment'   => "Converted to staff. Staff ID: {$staffId}",
            'reviewer'  => $this->admin_name ?? 'Admin',
            'timestamp' => $now,
        ];

        $this->firebase->update($this->_applicants($appId), [
            'stage'      => 'Hired',
            'status'     => 'Joined',
            'ats_status' => 'hired',
            'staff_id'   => $staffId,
            'hired_at'   => $now,
            'reviews'    => $reviews,
            'updated_at' => $now,
            'updated_by' => $this->admin_name ?? 'Admin',
        ]);

        // 2. Decrement positions_open on the job posting
        $jobId = $applicant['job_id'] ?? '';
        if ($jobId !== '') {
            $job = $this->firebase->get($this->_jobs($jobId));
            if (is_array($job)) {
                $positions = (int) ($job['positions'] ?? 0);
                $newPositions = max(0, $positions - 1);
                $filled = (int) ($job['filled_positions'] ?? 0) + 1;
                $jobUpdate = [
                    'positions'        => $newPositions,
                    'filled_positions' => $filled,
                    'updated_at'       => $now,
                ];
                // Auto-close job if no positions left
                if ($newPositions === 0) {
                    $jobUpdate['status'] = 'Closed';
                }
                $this->firebase->update($this->_jobs($jobId), $jobUpdate);
            }
        }

        $this->json_success([
            'message'  => 'Hire finalized. Application marked as Hired.',
            'staff_id' => $staffId,
        ]);
    }

    // ====================================================================
    //  AJAX — BULK / UTILITY
    // ====================================================================

    /**
     * GET — Get all job postings (for dropdowns).
     */
    public function get_jobs()
    {
        $this->_require_view();

        $raw  = $this->firebase->get($this->_jobs());
        $list = [];
        if (is_array($raw)) {
            foreach ($raw as $id => $j) {
                if (!is_array($j)) continue;
                $list[] = [
                    'id'         => $id,
                    'title'      => $j['title'] ?? '',
                    'department' => $j['department'] ?? '',
                    'positions'  => (int) ($j['positions'] ?? 0),
                    'status'     => $j['status'] ?? '',
                ];
            }
        }
        $this->json_success(['jobs' => $list]);
    }

    /**
     * POST — Delete an applicant (only if Applied or Rejected).
     */
    public function delete_applicant()
    {
        $this->_require_admin();

        $id = $this->safe_path_segment(trim($this->input->post('id') ?? ''), 'id');
        $applicant = $this->firebase->get($this->_applicants($id));
        if (!is_array($applicant)) $this->json_error('Applicant not found.');

        $atsStatus = $applicant['ats_status'] ?? 'active';
        if ($atsStatus === 'hired') {
            $this->json_error('Cannot delete a hired candidate.');
        }

        $this->firebase->delete($this->_hr('Recruitment/Applicants'), $id);
        $this->json_success(['message' => 'Applicant deleted.']);
    }
}
