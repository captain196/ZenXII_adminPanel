<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Applicant Tracking System (ATS) Controller
 *
 * Full hiring pipeline: APPLIED → SHORTLISTED → INTERVIEWED → SELECTED → HIRED
 * Integrates with HR job postings and the Staff module for the convert-to-staff flow.
 *
 * CANONICAL STORE — Firestore (ZenX HRMS W1.3)
 * --------------------------------------------
 *   hrApplicants/{schoolId}_{APPnnnn}   — shared with Hr.php
 *   hrJobs/{schoolId}_{JOBnnnn}         — shared with Hr.php (Teacher app also reads this)
 *   counter kind `hr_Applicant`         — shared with Hr.php via hr_next_id()
 *
 * Until W1.3 this controller's docblock claimed those paths were "shared with
 * HR", but they were RTDB paths (`Schools/{school_name}/HR/Recruitment/...`)
 * while Hr.php had already moved to the Firestore collections above. The two
 * modules therefore operated on two disjoint stores that merely looked alike:
 * a job posted in HR was invisible to the ATS board, and a candidate advanced
 * in ATS never reached HR's applicant list or the recruitment task counters in
 * Notifications.php. This file now reads and writes the SAME documents Hr.php
 * does, which is what the original docblock always intended.
 *
 * The stage↔status vocabulary bridge lives in `helpers/recruitment_helper.php`
 * — it is shared with Hr.php so the two mappings cannot drift.
 */
class Ats extends MY_Controller
{
    /**
     * Roles that can VIEW the pipeline.
     *
     * RBAC NOTE: these lists are passed to `_require_role()`, the graded
     * role→permission bridge, which resolves them through the RBAC catalogue.
     * They are NOT an authorization bypass and must never be used as one — see
     * `_require_admin()` / `_require_view()` below.
     */
    const VIEW_ROLES  = ['Super Admin', 'School Super Admin', 'Principal', 'Vice Principal', 'Admin', 'Teacher', 'HR Manager'];

    /** Ordered hiring stages — canonical list lives in recruitment_helper.php. */
    const STAGES = ['Applied', 'Shortlisted', 'Interviewed', 'Selected', 'Hired'];

    /** Valid terminal statuses */
    const STATUSES = ['active', 'rejected', 'hired', 'withdrawn'];

    /** Allowed review ratings */
    const MIN_RATING = 1;
    const MAX_RATING = 5;

    /** Max review comment length */
    const MAX_COMMENT_LENGTH = 2000;

    /** Cap on stored review entries — see _append_review(). */
    const MAX_REVIEWS = 200;

    public function __construct()
    {
        parent::__construct();
        $this->load->helper('recruitment');
        $this->load->helper('hr_id');
        require_permission('HR');
    }

    // ── Access helpers ───────────────────────────────────────────────

    /**
     * Gate for every mutating endpoint.
     *
     * W1.3: this previously fell back to
     * `in_array($this->admin_role, self::ADMIN_ROLES, true)` when the graded
     * check failed. That is a privilege escalation, not a fallback: an operator
     * granted HR *view* only, but whose job title happened to be one of the six
     * hardcoded strings, silently gained the ability to advance stages, reject
     * candidates and finalize hires. It also made custom tenant-defined roles
     * unable to manage ATS no matter what permissions they were granted, since
     * their titles were not on the list. Removed — RBAC is now the only gate,
     * matching the P0 hardening applied across Hr.php.
     */
    private function _require_admin()
    {
        require_permission('HR', 'ats_manage', 'manage');
    }

    /** Gate for every read endpoint. Same reasoning as _require_admin(). */
    private function _require_view()
    {
        require_permission('HR', 'ats_view', 'view');
    }

    // ── Id helpers ───────────────────────────────────────────────────

    /**
     * Mint the next applicant id.
     *
     * MUST use the same ('APP', 'Applicant') pair as Hr::_next_id(), so both
     * modules advance the one atomic per-school counter and can never issue the
     * same APP id. See helpers/hr_id_helper.php for why this matters.
     */
    private function _next_applicant_id(): string
    {
        return hr_next_id($this->fs, 'APP', 'Applicant');
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
     * Normalize any stored status/stage value to a canonical ATS stage.
     * Delegates to the shared bridge so Hr.php and Ats.php agree.
     */
    private function _normalize_stage(string $stage): string
    {
        return recruitment_stage_from_status($stage);
    }

    /** Read the effective stage of an applicant document. */
    private function _stage_of(array $applicant): string
    {
        return $this->_normalize_stage(
            (string) ($applicant['stage'] ?? $applicant['status'] ?? 'Applied')
        );
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

    // ── Firestore access helpers ─────────────────────────────────────

    /**
     * Fetch one applicant, tenant-scoped.
     *
     * `getEntity()` resolves `hrApplicants/{schoolId}_{id}`, so a caller from
     * school A cannot read school B's applicant even by guessing the raw id —
     * the tenant prefix is applied server-side from the session, never from
     * user input.
     */
    private function _get_applicant(string $id): ?array
    {
        try {
            $doc = $this->fs->getEntity('hrApplicants', $id);
        } catch (\Exception $e) {
            log_message('error', "ATS _get_applicant Firestore read FAILED for {$id}: " . $e->getMessage());
            return null;
        }
        return (is_array($doc) && !empty($doc)) ? $doc : null;
    }

    /** Fetch one job posting, tenant-scoped. */
    private function _get_job(string $id): ?array
    {
        try {
            $doc = $this->fs->getEntity('hrJobs', $id);
        } catch (\Exception $e) {
            log_message('error', "ATS _get_job Firestore read FAILED for {$id}: " . $e->getMessage());
            return null;
        }
        return (is_array($doc) && !empty($doc)) ? $doc : null;
    }

    /**
     * Project an hrJobs document into the compact shape the ATS UI consumes.
     * Kept in one place so get_pipeline() and get_jobs() cannot drift.
     */
    private function _job_summary(string $id, array $j): array
    {
        return [
            'id'         => $id,
            'title'      => $j['title'] ?? '',
            'department' => $j['department'] ?? '',
            'positions'  => (int) ($j['positions'] ?? 0),
            'status'     => $j['status'] ?? '',
        ];
    }

    /**
     * List this school's job postings as {id: summary}.
     * `schoolWhere()` injects the schoolId predicate — never a bare query().
     */
    private function _list_jobs(): array
    {
        try {
            $rows = $this->fs->schoolWhere('hrJobs', []);
        } catch (\Exception $e) {
            log_message('error', 'ATS _list_jobs Firestore query FAILED: ' . $e->getMessage());
            return [];
        }

        $out = [];
        foreach ($rows as $row) {
            $data = $row['data'] ?? null;
            if (!is_array($data)) continue;
            $out[] = $this->_job_summary($this->_strip_prefix((string) $row['id']), $data);
        }
        return $out;
    }

    /**
     * Strip the `{schoolId}_` document-id prefix back to the bare entity id
     * (APP0001 / JOB0001) that the UI and every stored cross-reference use.
     */
    private function _strip_prefix(string $docId): string
    {
        $prefix = $this->school_id . '_';
        return (strpos($docId, $prefix) === 0) ? substr($docId, strlen($prefix)) : $docId;
    }

    /**
     * Append an entry to an applicant's review log.
     *
     * The log is a bounded array on the applicant document, matching the
     * pre-W1.3 shape the UI reads. It is capped because Firestore rejects any
     * document over 1 MiB outright — an uncapped append would eventually make
     * the applicant permanently unwritable, taking down stage moves and the
     * hire flow with it. Oldest entries are dropped first.
     */
    private function _append_review(array $applicant, array $entry): array
    {
        $reviews = $applicant['reviews'] ?? [];
        if (!is_array($reviews)) $reviews = [];
        $reviews[] = $entry;
        if (count($reviews) > self::MAX_REVIEWS) {
            $reviews = array_slice($reviews, -self::MAX_REVIEWS);
        }
        return $reviews;
    }

    // ====================================================================
    //  PAGE ROUTES
    // ====================================================================

    /**
     * GET /ats — Main ATS pipeline dashboard (SPA).
     */
    public function index()
    {
        $this->_require_role(self::VIEW_ROLES, 'ats_view', 'HR', 'view');

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

        try {
            $rows = $this->fs->schoolWhere('hrApplicants', []);
        } catch (\Exception $e) {
            log_message('error', 'ATS get_pipeline Firestore query FAILED: ' . $e->getMessage());
            $this->json_error('Could not load the recruitment pipeline. Please try again.');
            return;
        }

        $stageCounts = array_fill_keys(self::STAGES, 0);
        $rejected    = 0;
        $all         = [];

        foreach ($rows as $row) {
            $a = $row['data'] ?? null;
            if (!is_array($a)) continue;
            $a['id'] = $this->_strip_prefix((string) $row['id']);

            $stage  = $this->_stage_of($a);
            $status = $a['ats_status'] ?? 'active';

            if ($status === 'rejected') {
                $rejected++;
            } else {
                $stageCounts[$stage] = ($stageCounts[$stage] ?? 0) + 1;
            }

            // Surface the normalized stage to the UI so a document written by
            // Hr.php (which stores `status` only) still lands in the right column.
            $a['stage'] = $stage;
            $all[] = $a;
        }

        // Sort by most recently updated
        usort($all, fn($a, $b) => strcmp($b['updated_at'] ?? $b['applied_date'] ?? '', $a['updated_at'] ?? $a['applied_date'] ?? ''));

        $this->json_success([
            'stage_counts' => $stageCounts,
            'rejected'     => $rejected,
            'total'        => count($all),
            'applicants'   => $all,
            'jobs'         => $this->_list_jobs(),
        ]);
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
        $applicant = $this->_get_applicant($id);
        if ($applicant === null) {
            $this->json_error('Applicant not found.');
            return;
        }

        $applicant['id']    = $id;
        $applicant['stage'] = $this->_stage_of($applicant);

        // Get linked job info
        $jobId = $applicant['job_id'] ?? '';
        if ($jobId !== '') {
            $job = $this->_get_job($jobId);
            if ($job !== null) {
                $applicant['job_title']      = $job['title'] ?? '';
                $applicant['job_department'] = $job['department'] ?? '';
            }
        }

        $this->json_success(['applicant' => $applicant]);
    }

    /**
     * POST — Create or update an applicant.
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
        $job   = $this->_get_job($jobId);
        if ($job === null) $this->json_error('Job posting not found.');

        $name        = $this->_sanitize($name);
        $email       = $this->_sanitize($email);
        $phone       = $this->_sanitize($phone);
        $resumeNotes = $this->_sanitize($resumeNotes);

        $now   = date('c');
        $isNew = ($id === '');
        $existing = [];

        if ($isNew) {
            $id = $this->_next_applicant_id();
        } else {
            $id = $this->safe_path_segment($id, 'id');
            $found = $this->_get_applicant($id);
            if ($found === null) {
                $this->json_error('Applicant not found.');
                return;
            }
            $existing = $found;
        }

        $data = [
            'job_id'        => $jobId,
            'name'          => $name,
            'email'         => $email,
            'phone'         => $phone,
            'qualification' => $this->_sanitize($qualification),
            'experience'    => $this->_sanitize($experience),
            'resume_notes'  => $resumeNotes,
            'stage'         => $isNew ? 'Applied' : $this->_stage_of($existing),
            'status'        => $isNew ? 'Applied' : ($existing['status'] ?? 'Applied'),
            'ats_status'    => $isNew ? 'active'  : ($existing['ats_status'] ?? 'active'),
            'applied_date'  => $isNew ? date('Y-m-d') : ($existing['applied_date'] ?? date('Y-m-d')),
            'updated_at'    => $now,
            'updated_by'    => $this->admin_name ?? 'Admin',
        ];

        if ($isNew) {
            $data['created_at'] = $now;
            $data['reviews']    = [];
        }
        // On update the `reviews` key is deliberately OMITTED rather than
        // rewritten: setEntity() merges, so leaving it out preserves the stored
        // log. Echoing a read-then-write copy back would let a save submitted
        // from a stale form silently drop a review added in between.

        try {
            // merge=true also preserves the HR-owned fields on a shared document
            // (interview_date, interview_notes, notes) that ATS never sends.
            $this->fs->setEntity('hrApplicants', $id, $data);
        } catch (\Exception $e) {
            log_message('error', "ATS save_applicant Firestore write FAILED for {$id}: " . $e->getMessage());
            $this->json_error('Could not save the candidate. Please try again.');
            return;
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

        $applicant = $this->_get_applicant($id);
        if ($applicant === null) {
            $this->json_error('Applicant not found.');
            return;
        }

        // Check not already hired
        if (($applicant['ats_status'] ?? '') === 'hired') {
            $this->json_error('This candidate has already been hired.');
        }
        // A rejected candidate must be explicitly reinstated, not advanced.
        // Pre-W1.3 this was unguarded, so a rejected applicant could be moved
        // forward and would reappear on the board still flagged `rejected`.
        if (($applicant['ats_status'] ?? '') === 'rejected') {
            $this->json_error('This candidate has been rejected and cannot be advanced.');
        }

        $currentStage = $this->_stage_of($applicant);
        $validation   = $this->_validate_transition($currentStage, $newStage, $strict);

        if ($validation !== true) {
            $this->json_error($validation);
        }

        try {
            $this->fs->updateEntity('hrApplicants', $id, [
                'stage'      => $newStage,
                // Dual-emit the HR vocabulary so Hr.php's applicant list and
                // the Notifications recruitment counters see the same move.
                'status'     => recruitment_status_from_stage($newStage),
                'updated_at' => date('c'),
                'updated_by' => $this->admin_name ?? 'Admin',
            ]);
        } catch (\Exception $e) {
            log_message('error', "ATS move_stage Firestore write FAILED for {$id}: " . $e->getMessage());
            $this->json_error('Could not move the candidate. Please try again.');
            return;
        }

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

        $applicant = $this->_get_applicant($id);
        if ($applicant === null) {
            $this->json_error('Applicant not found.');
            return;
        }

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

        $update['reviews'] = $this->_append_review($applicant, [
            'stage'     => $this->_stage_of($applicant),
            'action'    => 'rejected',
            'comment'   => $reason !== '' ? $reason : 'Rejected by admin.',
            'reviewer'  => $this->admin_name ?? 'Admin',
            'timestamp' => date('c'),
        ]);

        try {
            $this->fs->updateEntity('hrApplicants', $id, $update);
        } catch (\Exception $e) {
            log_message('error', "ATS reject_applicant Firestore write FAILED for {$id}: " . $e->getMessage());
            $this->json_error('Could not reject the candidate. Please try again.');
            return;
        }

        $this->json_success(['message' => 'Applicant rejected.']);
    }

    // ====================================================================
    //  AJAX — STAGE-WISE REVIEWS
    // ====================================================================

    /**
     * POST — Add a review/feedback at current stage.
     * Params: id, rating (1-5), comment
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

        $applicant = $this->_get_applicant($id);
        if ($applicant === null) {
            $this->json_error('Applicant not found.');
            return;
        }

        $currentStage = $this->_stage_of($applicant);

        $review = [
            'stage'     => $currentStage,
            'rating'    => $rating,
            'comment'   => $this->_sanitize($comment),
            'reviewer'  => $this->admin_name ?? 'Admin',
            'timestamp' => date('c'),
        ];

        try {
            $this->fs->updateEntity('hrApplicants', $id, [
                'reviews'    => $this->_append_review($applicant, $review),
                // Mirror the latest rating to the top level — Hr.php's applicant
                // list renders this field.
                'rating'     => $rating,
                'updated_at' => date('c'),
            ]);
        } catch (\Exception $e) {
            log_message('error', "ATS add_review Firestore write FAILED for {$id}: " . $e->getMessage());
            $this->json_error('Could not save the review. Please try again.');
            return;
        }

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
        $applicant = $this->_get_applicant($id);
        if ($applicant === null) {
            $this->json_error('Applicant not found.');
            return;
        }

        $reviews = $applicant['reviews'] ?? [];
        if (!is_array($reviews)) $reviews = [];

        $this->json_success(['reviews' => $reviews]);
    }

    // ====================================================================
    //  AJAX — CONVERT TO STAFF
    // ====================================================================

    /**
     * GET — Get prefill data for convert-to-staff.
     * Returns applicant + job data formatted for the new_staff form.
     */
    public function get_convert_data()
    {
        $this->_require_admin();

        $id = $this->safe_path_segment(trim($this->input->get('id') ?? ''), 'id');
        $applicant = $this->_get_applicant($id);
        if ($applicant === null) {
            $this->json_error('Applicant not found.');
            return;
        }

        $stage = $this->_stage_of($applicant);
        if ($stage !== 'Selected') {
            $this->json_error('Only candidates at the Selected stage can be converted to staff.');
        }

        $atsStatus = $applicant['ats_status'] ?? '';
        if ($atsStatus === 'hired') {
            $this->json_error('This candidate has already been hired.');
        }
        if ($atsStatus === 'rejected') {
            $this->json_error('This candidate has been rejected and cannot be converted to staff.');
        }

        // Staff Lifecycle audit finding: ats_status alone isn't a reliable
        // guard — a manual HR status edit can leave ats_status stale while
        // staff_id is already linked (see the 'Joined' stage comment in
        // finalize_hire()). Check the link directly so a second, independent
        // staff doc is never created for the same applicant.
        if (trim((string) ($applicant['staff_id'] ?? '')) !== '') {
            $this->json_error('This candidate is already linked to a staff record and cannot be converted again.');
        }

        // Get job info for role/department
        $jobId    = $applicant['job_id'] ?? '';
        $dept     = '';
        $position = '';

        if ($jobId !== '') {
            $job = $this->_get_job($jobId);
            if ($job !== null) {
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

        // Validate before it reaches docId() — a staff id containing a slash
        // would otherwise address a different Firestore path than intended.
        $staffId = $this->safe_path_segment($staffId, 'staff_id');

        $applicant = $this->_get_applicant($appId);
        if ($applicant === null) {
            $this->json_error('Applicant not found.');
            return;
        }

        // Idempotency: the browser retries this call after staff creation, so a
        // repeat MUST NOT decrement the job's open positions a second time.
        //
        // The key is "already LINKED to a staff record", not merely
        // ats_status === 'hired'. Hr::update_applicant_status() can set the
        // status to 'Joined' by hand, which derives ats_status = 'hired'
        // without ever creating a staff record; keying on the flag alone would
        // make this endpoint report success for such an applicant and silently
        // skip both the staff link and the vacancy decrement.
        $alreadyHired = ($applicant['ats_status'] ?? '') === 'hired';
        $linkedStaff  = trim((string) ($applicant['staff_id'] ?? ''));

        if ($alreadyHired && $linkedStaff !== '') {
            $this->json_success([
                'message'      => 'Already finalized.',
                'already_done' => true,
                'staff_id'     => $linkedStaff,
            ]);
            return;
        }

        // Stage gate. An applicant marked 'Joined' in HR reads as stage 'Hired'
        // rather than 'Selected', so allow that case through to complete the
        // linking it never received; everything else must be at Selected.
        $stage = $this->_stage_of($applicant);
        if ($stage !== 'Selected' && !$alreadyHired) {
            $this->json_error('Applicant must be at Selected stage to finalize hire.');
        }

        // Verify the staff record actually exists (data integrity) — tenant-scoped.
        $staff = $this->fs->getEntity('staff', $staffId);
        if (!is_array($staff) || empty($staff)) {
            $this->json_error('Staff record not found. Please ensure staff creation completed successfully.');
        }

        $now = date('c');

        // 1. Update application → Hired
        $reviews = $this->_append_review($applicant, [
            'stage'     => 'Hired',
            'action'    => 'hired',
            'comment'   => "Converted to staff. Staff ID: {$staffId}",
            'reviewer'  => $this->admin_name ?? 'Admin',
            'timestamp' => $now,
        ]);

        try {
            $this->fs->updateEntity('hrApplicants', $appId, [
                'stage'      => 'Hired',
                'status'     => recruitment_status_from_stage('Hired'),
                'ats_status' => 'hired',
                'staff_id'   => $staffId,
                'hired_at'   => $now,
                'reviews'    => $reviews,
                'updated_at' => $now,
                'updated_by' => $this->admin_name ?? 'Admin',
            ]);
        } catch (\Exception $e) {
            // Fail loudly: the staff record already exists, so a silent failure
            // here would strand the application at Selected and let a second
            // convert-to-staff run create a duplicate employee.
            log_message('error', "ATS finalize_hire Firestore write FAILED for {$appId}: " . $e->getMessage());
            $this->json_error('Staff was created but the application could not be marked as hired. Please retry.');
            return;
        }

        // 2. Decrement positions_open on the job posting.
        //    Ordered AFTER the application update on purpose: the application is
        //    the idempotency key, so if this step fails a retry is now a no-op
        //    rather than a second decrement.
        $jobId = $applicant['job_id'] ?? '';
        if ($jobId !== '') {
            $job = $this->_get_job($jobId);
            if ($job !== null) {
                $positions    = (int) ($job['positions'] ?? 0);
                $newPositions = max(0, $positions - 1);
                $filled       = (int) ($job['filled_positions'] ?? 0) + 1;
                $jobUpdate = [
                    'positions'        => $newPositions,
                    'filled_positions' => $filled,
                    // Keep the camelCase mirror the Teacher app reads in sync;
                    // Hr::save_job() writes both, so updating only `positions`
                    // would leave the app advertising a filled vacancy.
                    'vacancies'        => $newPositions,
                    'updated_at'       => $now,
                ];
                // Auto-close job if no positions left
                if ($newPositions === 0) {
                    $jobUpdate['status']      = 'Closed';
                    $jobUpdate['statusLower'] = 'closed';
                }
                try {
                    $this->fs->updateEntity('hrJobs', $jobId, $jobUpdate);
                } catch (\Exception $e) {
                    log_message('error', "ATS finalize_hire job update FAILED for {$jobId}: " . $e->getMessage());
                }
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
        $this->json_success(['jobs' => $this->_list_jobs()]);
    }

    /**
     * POST — Delete an applicant.
     */
    public function delete_applicant()
    {
        $this->_require_admin();

        $id = $this->safe_path_segment(trim($this->input->post('id') ?? ''), 'id');
        $applicant = $this->_get_applicant($id);
        if ($applicant === null) {
            $this->json_error('Applicant not found.');
            return;
        }

        $atsStatus = $applicant['ats_status'] ?? 'active';
        if ($atsStatus === 'hired') {
            $this->json_error('Cannot delete a hired candidate.');
        }

        try {
            $this->fs->removeEntity('hrApplicants', $id);
        } catch (\Exception $e) {
            log_message('error', "ATS delete_applicant Firestore delete FAILED for {$id}: " . $e->getMessage());
            $this->json_error('Could not delete the candidate. Please try again.');
            return;
        }

        $this->json_success(['message' => 'Applicant deleted.']);
    }
}
