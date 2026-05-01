<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Red Flags Dashboard Controller
 *
 * Comprehensive red-flag monitoring for student issues raised by teachers
 * via the mobile app. Provides KPIs, analytics, flag management, and
 * student drill-down views.
 *
 * Firestore (canonical) — Phase B migration 2026-04-25:
 *   Collection: studentFlags
 *   Document ID: {schoolId}_{flagId}
 *   Fields stored canonically (camelCase + lowercase enums); responses
 *   here remap to PascalCase so existing dashboard JS keeps working.
 */
class Red_flags extends MY_Controller
{
    /** Roles that may manage (resolve/create/delete) flags */
    private const MANAGE_ROLES = ['Super Admin', 'School Super Admin', 'Admin', 'Principal', 'Vice Principal'];

    /** Roles that may view flags */
    private const VIEW_ROLES = ['Super Admin', 'School Super Admin', 'Admin', 'Principal', 'Vice Principal', 'Academic Coordinator', 'Teacher'];

    /** Valid flag types */
    private const ALLOWED_TYPES = ['Homework', 'Behavior', 'Performance'];

    /** Valid severity levels */
    private const ALLOWED_SEVERITIES = ['Low', 'Medium', 'High'];

    /** Valid statuses */
    private const ALLOWED_STATUSES = ['Active', 'Resolved'];

    /** Max text length for flag messages */
    private const MAX_MESSAGE_LENGTH = 1000;

    public function __construct()
    {
        parent::__construct();
        require_permission('Red Flags');
    }

    // =========================================================================
    //  PRIVATE HELPERS
    // =========================================================================

    /**
     * Build base Firebase path for a class/section within current session.
     */
    private function _class_path(string $classKey, string $sectionKey): string
    {
        return "Schools/{$this->school_name}/{$this->session_year}/{$classKey}/{$sectionKey}";
    }

    /**
     * Deny access — JSON for AJAX, redirect for pages.
     */
    private function _deny_access(): void
    {
        if ($this->input->is_ajax_request()) {
            $this->json_error('Access denied.', 403);
        }
        redirect(base_url('admin'));
    }

    /**
     * Strip control characters from text for safe Firebase storage.
     */
    private function _clean_text(string $text): string
    {
        return preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', trim($text));
    }

    /**
     * Generate a unique flag ID.
     */
    private function _generate_flag_id(): string
    {
        return 'RF_' . date('Ymd_His') . '_' . substr(uniqid('', true), -6);
    }

    /**
     * Collapse repeated "Class " prefixes and ensure exactly one. Empty or
     * bare-prefix input ("Class", "Class Class") → empty string.
     * Defends against frontend bugs that double-prepend ("Class Class 9th").
     */
    private function _normalize_class_key(string $s): string
    {
        $s = trim($s);
        if ($s === '') return '';
        $s = preg_replace('/^(Class\s+)+/i', '', $s);
        if ($s === '' || preg_match('/^Class\s*$/i', $s)) return '';
        return 'Class ' . $s;
    }

    /**
     * Same as above for "Section ". Empty stays empty (used for class-wide
     * assignments where section is intentionally blank).
     */
    private function _normalize_section_key(string $s): string
    {
        $s = trim($s);
        if ($s === '') return '';
        $s = preg_replace('/^(Section\s+)+/i', '', $s);
        if ($s === '' || preg_match('/^Section\s*$/i', $s)) return '';
        return 'Section ' . $s;
    }

    /**
     * Map a canonical lowercase enum value to its PascalCase display form.
     * Falls back to ucfirst for unknown values.
     */
    private function _display_enum(string $value, array $whitelist): string
    {
        $lower = strtolower(trim($value));
        foreach ($whitelist as $w) {
            if (strtolower($w) === $lower) return $w;
        }
        return $value === '' ? '' : ucfirst($value);
    }

    /**
     * Collect ALL red flags from Firestore for current school + session.
     * Returns flat array in the legacy PascalCase shape so existing dashboard
     * JS continues to work unchanged.
     *
     * The Firestore rule allows reads where `schoolId == X` OR `schoolCode == X`,
     * so we query both fields and merge — this matches mobile clients that
     * may store either field (TokenManager.schoolId vs schoolCode), and
     * mirrors the rule's permissiveness so the admin panel doesn't silently
     * miss flags written under the alternative key.
     *
     * @param  array|null $classFilter  Optional — restrict to specific classes
     *                                  Items: ['class_key' => 'Class 9th', 'section' => 'A']
     * @param  bool        $includeDeleted  When false (default) hides
     *                                      soft-deleted flags. The audit
     *                                      log endpoint passes true.
     * @return array
     */
    private function _collect_all_flags(?array $classFilter = null, bool $includeDeleted = false): array
    {
        if (!isset($this->fs) || !$this->school_id) return [];

        $sessionFilter = !empty($this->session_year)
            ? [['session', '==', $this->session_year]]
            : [];

        // Two-query OR: Firestore composite queries don't support OR on
        // different fields, so we run both and dedupe by document id.
        $bySchoolId = (array) $this->fs->where(
            'studentFlags',
            array_merge([['schoolId', '==', $this->school_id]], $sessionFilter),
            'createdAtMs',
            'DESC',
            500
        );
        $bySchoolCode = (array) $this->fs->where(
            'studentFlags',
            array_merge([['schoolCode', '==', $this->school_id]], $sessionFilter),
            'createdAtMs',
            'DESC',
            500
        );

        // Dedupe by document id; keep the row whose createdAtMs is greatest
        // (defensive — both queries return the same doc, so values match).
        $deduped = [];
        foreach (array_merge($bySchoolId, $bySchoolCode) as $row) {
            if (!is_array($row) || !isset($row['id'])) continue;
            $deduped[$row['id']] = $row;
        }

        // Re-sort the merged set by createdAtMs DESC so downstream code
        // (recent-flags slice, weekly trend) sees consistent ordering.
        $rows = array_values($deduped);
        usort($rows, function ($a, $b) {
            $ta = (int)($a['data']['createdAtMs'] ?? 0);
            $tb = (int)($b['data']['createdAtMs'] ?? 0);
            return $tb <=> $ta;
        });

        // Build classFilter as a set of canonical "Class X|Section Y" keys.
        $filterSet = null;
        if ($classFilter !== null) {
            $filterSet = [];
            foreach ($classFilter as $cf) {
                if (!isset($cf['class_key'], $cf['section'])) continue;
                $ck = (stripos($cf['class_key'], 'Class ') === 0)
                    ? $cf['class_key'] : 'Class ' . $cf['class_key'];
                $sk = 'Section ' . $cf['section'];
                $filterSet[$ck . '|' . $sk] = true;
            }
        }

        $allFlags = [];
        foreach ($rows as $row) {
            $f = $row['data'];
            $classKey   = (string)($f['className'] ?? '');
            $sectionKey = (string)($f['section']   ?? '');

            if ($filterSet !== null
                && !isset($filterSet[$classKey . '|' . $sectionKey])) {
                continue;
            }

            // Teacher RBAC — same helper as before
            if (!$this->_teacher_can_access($classKey, $sectionKey)) {
                continue;
            }

            // Hide soft-deleted flags by default. Audit endpoints can opt
            // back in via $includeDeleted=true.
            if (!$includeDeleted
                && strtolower((string)($f['status'] ?? '')) === 'deleted') {
                continue;
            }

            $allFlags[] = [
                'flagId'      => $f['flagId']      ?? $row['id'],
                'studentId'   => $f['studentId']   ?? '',
                'studentName' => $f['studentName'] ?? ($f['studentId'] ?? ''),
                'rollNo'      => $f['rollNo']      ?? '',
                'fatherName'  => $f['fatherName']  ?? '',
                'classKey'    => $classKey,
                'sectionKey'  => $sectionKey,
                'classLabel'  => $classKey !== '' && $sectionKey !== ''
                    ? $classKey . ' / ' . $sectionKey
                    : ($classKey ?: $sectionKey ?: 'Unknown'),
                'type'        => $this->_display_enum((string)($f['type']     ?? ''), self::ALLOWED_TYPES),
                'severity'    => $this->_display_enum((string)($f['severity'] ?? 'low'), self::ALLOWED_SEVERITIES),
                'message'     => $f['message']     ?? '',
                'subject'     => $f['subject']     ?? '',
                'teacherId'   => $f['teacherId']   ?? '',
                'teacherName' => $f['teacherName'] ?? '',
                'createdAt'   => $f['createdAtMs'] ?? 0,
                'status'      => $this->_display_enum((string)($f['status']   ?? 'active'), self::ALLOWED_STATUSES),
                'resolvedAt'  => $f['resolvedAtMs'] ?? null,
                'resolvedBy'  => $f['resolvedBy']  ?? null,
            ];
        }

        return $allFlags;
    }

    // =========================================================================
    //  PAGE ROUTES
    // =========================================================================

    /**
     * Main Red Flags Dashboard (SPA — all tabs in one view).
     */
    public function index()
    {
        $this->_require_role(self::VIEW_ROLES, 'red_flags_view');
        $data = [];
        $this->load->view('include/header', $data);
        $this->load->view('red_flags/index', $data);
        $this->load->view('include/footer');
    }

    // =========================================================================
    //  AJAX ENDPOINTS
    // =========================================================================

    /**
     * GET — Return classes for filter dropdowns.
     */
    public function get_classes()
    {
        $this->_require_role(self::VIEW_ROLES, 'red_flags_classes');

        $classes = $this->_get_session_classes();

        // For Teacher role, filter to assigned classes only
        if (($this->admin_role ?? '') === 'Teacher') {
            $filtered = [];
            foreach ($classes as $cls) {
                $sectionKey = 'Section ' . $cls['section'];
                if ($this->_teacher_can_access($cls['class_key'], $sectionKey)) {
                    $filtered[] = $cls;
                }
            }
            $classes = $filtered;
        }

        $this->json_success(['classes' => $classes]);
    }

    /**
     * GET — Dashboard KPIs: total, active, resolved, by type, by severity, by class.
     */
    public function get_overview()
    {
        $this->_require_role(self::VIEW_ROLES, 'red_flags_overview');

        $allFlags = $this->_collect_all_flags();

        $total    = count($allFlags);
        $active   = 0;
        $resolved = 0;
        $high     = 0;
        $thisWeek = 0;

        $byType     = ['Homework' => 0, 'Behavior' => 0, 'Performance' => 0];
        $bySeverity = ['Low' => 0, 'Medium' => 0, 'High' => 0];
        $byClass    = [];
        $byStatus   = ['Active' => 0, 'Resolved' => 0];

        $weekAgo = strtotime('-7 days');
        $recentFlags = [];
        $studentCounts = [];

        foreach ($allFlags as $f) {
            // Status counts
            if (($f['status'] ?? 'Active') === 'Active') {
                $active++;
                $byStatus['Active']++;
            } else {
                $resolved++;
                $byStatus['Resolved']++;
            }

            // Severity
            $sev = $f['severity'] ?? 'Low';
            if (isset($bySeverity[$sev])) $bySeverity[$sev]++;
            if ($sev === 'High') $high++;

            // Type
            $type = $f['type'] ?? 'Unknown';
            if (isset($byType[$type])) $byType[$type]++;

            // Class breakdown
            $cl = $f['classLabel'] ?? 'Unknown';
            if (!isset($byClass[$cl])) $byClass[$cl] = ['total' => 0, 'active' => 0, 'high' => 0];
            $byClass[$cl]['total']++;
            if (($f['status'] ?? 'Active') === 'Active') $byClass[$cl]['active']++;
            if ($sev === 'High') $byClass[$cl]['high']++;

            // This week
            $ts = is_numeric($f['createdAt']) ? (int) $f['createdAt'] : 0;
            // Handle millisecond timestamps
            if ($ts > 9999999999) $ts = (int) ($ts / 1000);
            if ($ts >= $weekAgo) $thisWeek++;

            // Recent flags (top 10)
            if (count($recentFlags) < 10) {
                $recentFlags[] = $f;
            }

            // Student flag counts
            $sid = $f['studentId'];
            if (!isset($studentCounts[$sid])) {
                $studentCounts[$sid] = [
                    'studentId'   => $sid,
                    'studentName' => $f['studentName'],
                    'classLabel'  => $f['classLabel'],
                    'rollNo'      => $f['rollNo'],
                    'count'       => 0,
                    'lastFlag'    => $f['createdAt'],
                    'highCount'   => 0,
                ];
            }
            $studentCounts[$sid]['count']++;
            if ($sev === 'High') $studentCounts[$sid]['highCount']++;
        }

        // Top flagged students (top 10 by count)
        usort($studentCounts, function ($a, $b) {
            return $b['count'] <=> $a['count'];
        });
        $topStudents = array_slice(array_values($studentCounts), 0, 10);

        $this->json_success([
            'total'       => $total,
            'active'      => $active,
            'resolved'    => $resolved,
            'high'        => $high,
            'thisWeek'    => $thisWeek,
            'byType'      => $byType,
            'bySeverity'  => $bySeverity,
            'byClass'     => $byClass,
            'byStatus'    => $byStatus,
            'recentFlags' => $recentFlags,
            'topStudents' => $topStudents,
        ]);
    }

    /**
     * POST — Fetch flags with filters.
     *
     * Filters: class_key, section, type, severity, status, student, teacher, date_from, date_to
     */
    public function get_flags()
    {
        $this->_require_role(self::VIEW_ROLES, 'red_flags_list');

        $allFlags = $this->_collect_all_flags();

        // Apply filters from POST
        $fClassKey  = trim($this->input->post('class_key') ?? '');
        $fSection   = trim($this->input->post('section') ?? '');
        $fType      = trim($this->input->post('type') ?? '');
        $fSeverity  = trim($this->input->post('severity') ?? '');
        $fStatus    = trim($this->input->post('status') ?? '');
        $fStudent   = trim($this->input->post('student') ?? '');
        $fTeacher   = trim($this->input->post('teacher') ?? '');
        $fDateFrom  = trim($this->input->post('date_from') ?? '');
        $fDateTo    = trim($this->input->post('date_to') ?? '');

        $filtered = [];

        foreach ($allFlags as $f) {
            // Class filter
            if ($fClassKey !== '' && $f['classKey'] !== $fClassKey) continue;
            if ($fSection !== '' && $f['sectionKey'] !== 'Section ' . $fSection) continue;

            // Type
            if ($fType !== '' && $f['type'] !== $fType) continue;

            // Severity
            if ($fSeverity !== '' && $f['severity'] !== $fSeverity) continue;

            // Status
            if ($fStatus !== '' && $f['status'] !== $fStatus) continue;

            // Student name search (case-insensitive partial)
            if ($fStudent !== '' && stripos($f['studentName'], $fStudent) === false
                && stripos($f['studentId'], $fStudent) === false) {
                continue;
            }

            // Teacher search
            if ($fTeacher !== '' && stripos($f['teacherName'], $fTeacher) === false
                && stripos($f['teacherId'], $fTeacher) === false) {
                continue;
            }

            // Date range
            $ts = is_numeric($f['createdAt']) ? (int) $f['createdAt'] : 0;
            if ($ts > 9999999999) $ts = (int) ($ts / 1000);

            if ($fDateFrom !== '') {
                $from = strtotime($fDateFrom . ' 00:00:00');
                if ($from !== false && $ts < $from) continue;
            }
            if ($fDateTo !== '') {
                $to = strtotime($fDateTo . ' 23:59:59');
                if ($to !== false && $ts > $to) continue;
            }

            $filtered[] = $f;
        }

        $this->json_success([
            'flags'   => $filtered,
            'total'   => count($filtered),
        ]);
    }

    /**
     * GET — All flags for a specific student.
     */
    public function get_student_flags(string $studentId = '')
    {
        $this->_require_role(self::VIEW_ROLES, 'red_flags_student');

        if ($studentId === '') {
            $this->json_error('Student ID is required.', 400);
        }

        $studentId = $this->safe_path_segment($studentId, 'studentId');
        $allFlags = $this->_collect_all_flags();

        $studentFlags = [];
        $studentInfo  = null;

        foreach ($allFlags as $f) {
            if ($f['studentId'] === $studentId) {
                if ($studentInfo === null) {
                    $studentInfo = [
                        'studentId'   => $f['studentId'],
                        'studentName' => $f['studentName'],
                        'rollNo'      => $f['rollNo'],
                        'fatherName'  => $f['fatherName'],
                        'classLabel'  => $f['classLabel'],
                    ];
                }
                $studentFlags[] = $f;
            }
        }

        // Student exists but has no flags? Look up profile directly so the
        // UI can still render the student card with an empty flag list,
        // instead of showing a misleading "not found" message.
        if ($studentInfo === null && isset($this->fs)) {
            $stuFs = $this->fs->getEntity('students', $studentId);
            if (is_array($stuFs)) {
                $cls = (string)($stuFs['className'] ?? $stuFs['Class']  ?? '');
                $sec = (string)($stuFs['section']   ?? $stuFs['Section'] ?? '');
                $studentInfo = [
                    'studentId'   => $studentId,
                    'studentName' => (string)($stuFs['name']       ?? $stuFs['Name']       ?? $studentId),
                    'rollNo'      => (string)($stuFs['rollNo']     ?? $stuFs['RollNo']     ?? ''),
                    'fatherName'  => (string)($stuFs['fatherName'] ?? $stuFs['FatherName'] ?? ''),
                    'classLabel'  => $cls !== '' && $sec !== ''
                        ? $cls . ' / ' . $sec
                        : ($cls ?: $sec ?: ''),
                ];
            }
        }

        // Pattern analysis
        $typeBreakdown     = ['Homework' => 0, 'Behavior' => 0, 'Performance' => 0];
        $severityBreakdown = ['Low' => 0, 'Medium' => 0, 'High' => 0];
        $activeCount       = 0;
        $resolvedCount     = 0;

        foreach ($studentFlags as $sf) {
            $t = $sf['type'] ?? 'Unknown';
            if (isset($typeBreakdown[$t])) $typeBreakdown[$t]++;

            $s = $sf['severity'] ?? 'Low';
            if (isset($severityBreakdown[$s])) $severityBreakdown[$s]++;

            if (($sf['status'] ?? 'Active') === 'Active') {
                $activeCount++;
            } else {
                $resolvedCount++;
            }
        }

        $this->json_success([
            'student'   => $studentInfo,
            'flags'     => $studentFlags,
            'total'     => count($studentFlags),
            'analysis'  => [
                'byType'     => $typeBreakdown,
                'bySeverity' => $severityBreakdown,
                'active'     => $activeCount,
                'resolved'   => $resolvedCount,
            ],
        ]);
    }

    /**
     * POST — Resolve a single flag.
     *
     * Inputs: flag_id (required); class_key/section_key/student_id accepted
     * for backward compatibility but no longer needed.
     */
    public function resolve_flag()
    {
        $this->_require_role(self::MANAGE_ROLES, 'red_flags_resolve');

        $flagId = $this->safe_path_segment($this->input->post('flag_id') ?? '', 'flag_id');
        $docId  = $this->fs->docId($flagId);

        $existing = $this->fs->get('studentFlags', $docId);
        if (!is_array($existing)) {
            $this->json_error('Flag not found.', 404);
        }

        if (strtolower((string)($existing['status'] ?? '')) === 'resolved') {
            $this->json_error('Flag is already resolved.', 400);
        }

        $nowMs = (int) round(microtime(true) * 1000);

        $ok = $this->fs->update('studentFlags', $docId, [
            'status'       => 'resolved',
            'resolvedAtMs' => $nowMs,
            'resolvedAt'   => date('c', (int) ($nowMs / 1000)),
            'resolvedBy'   => $this->admin_id,
            'updatedAt'    => date('c'),
        ]);

        if (!$ok) {
            $this->json_error('Failed to resolve flag.', 500);
        }

        log_audit('Red Flags', 'resolve_flag', $flagId,
            "Resolved flag for student " . ($existing['studentId'] ?? '?'));

        $this->json_success(['message' => 'Flag resolved successfully.']);
    }

    /**
     * POST — Admin creates a new flag.
     */
    public function create_flag()
    {
        $this->_require_role(self::MANAGE_ROLES, 'red_flags_create');

        $classKey   = $this->safe_path_segment($this->input->post('class_key') ?? '', 'class_key');
        $sectionKey = $this->safe_path_segment($this->input->post('section_key') ?? '', 'section_key');
        $studentId  = $this->safe_path_segment($this->input->post('student_id') ?? '', 'student_id');

        // Normalize prefixes — collapse any duplication a misbehaving caller
        // may have introduced ("Section Section A" → "Section A").
        $classKey   = $this->_normalize_class_key($classKey);
        $sectionKey = $this->_normalize_section_key($sectionKey);

        $type     = trim($this->input->post('type') ?? '');
        $severity = trim($this->input->post('severity') ?? '');
        $message  = $this->_clean_text($this->input->post('message') ?? '');
        $subject  = $this->_clean_text($this->input->post('subject') ?? '');

        // Validate (input is in PascalCase from existing UI; we store lowercase)
        if (!in_array($type, self::ALLOWED_TYPES, true)) {
            $this->json_error('Invalid flag type. Must be: ' . implode(', ', self::ALLOWED_TYPES));
        }
        if (!in_array($severity, self::ALLOWED_SEVERITIES, true)) {
            $this->json_error('Invalid severity. Must be: ' . implode(', ', self::ALLOWED_SEVERITIES));
        }
        if ($message === '') {
            $this->json_error('Message is required.');
        }
        if (mb_strlen($message) > self::MAX_MESSAGE_LENGTH) {
            $this->json_error('Message exceeds maximum length of ' . self::MAX_MESSAGE_LENGTH . ' characters.');
        }

        // Resolve student denorm (Firestore canonical; RTDB fallback for legacy
        // students not yet mirrored). Reading the students collection is not a
        // module change — we're only consuming existing data.
        $studentName = '';
        $rollNo      = '';
        $fatherName  = '';

        $stuFs = $this->fs->getEntity('students', $studentId);
        if (is_array($stuFs)) {
            $studentName = (string)($stuFs['name']       ?? $stuFs['Name']       ?? '');
            $rollNo      = (string)($stuFs['rollNo']     ?? $stuFs['RollNo']     ?? '');
            $fatherName  = (string)($stuFs['fatherName'] ?? $stuFs['FatherName'] ?? '');
        } else {
            $studentPath = $this->_class_path($classKey, $sectionKey) . "/Students/{$studentId}";
            $stuRtdb = $this->firebase->get($studentPath);
            if (is_array($stuRtdb)) {
                $studentName = (string)($stuRtdb['Name']       ?? '');
                $rollNo      = (string)($stuRtdb['RollNo']     ?? '');
                $fatherName  = (string)($stuRtdb['FatherName'] ?? '');
            }
        }
        if ($studentName === '') {
            $this->json_error('Student not found.', 404);
        }

        $flagId = $this->_generate_flag_id();
        $nowMs  = (int) round(microtime(true) * 1000);
        $nowIso = date('c', (int) ($nowMs / 1000));

        $flagData = [
            'flagId'        => $flagId,
            'schoolId'      => $this->school_id,
            'schoolCode'    => $this->school_code ?? $this->school_id,
            'session'       => $this->session_year,
            'studentId'     => $studentId,
            'studentName'   => $studentName,
            'rollNo'        => $rollNo,
            'fatherName'    => $fatherName,
            'className'     => $classKey,
            'section'       => $sectionKey,
            'type'          => strtolower($type),
            'severity'      => strtolower($severity),
            'status'        => 'active',
            'message'       => $message,
            'subject'       => $subject,
            'teacherId'     => $this->admin_id,
            'teacherName'   => $this->admin_name ?? $this->admin_id,
            'createdAtMs'   => $nowMs,
            'createdAt'     => $nowIso,
            'updatedAt'     => $nowIso,
            'createdByRole' => 'admin',
            'resolvedAt'    => null,
            'resolvedAtMs'  => null,
            'resolvedBy'    => null,
            'deletedAtMs'   => null,
            'deletedBy'     => null,
            'hwId'          => null,
        ];

        $ok = $this->fs->set('studentFlags', $this->fs->docId($flagId), $flagData);
        if (!$ok) {
            $this->json_error('Failed to create flag.', 500);
        }

        log_audit('Red Flags', 'create_flag', $flagId,
            "Created {$severity} {$type} flag for student {$studentId}");

        $this->json_success([
            'message' => 'Flag created successfully.',
            'flagId'  => $flagId,
        ]);
    }

    /**
     * POST — Soft-delete a flag (sets status='deleted'; admin only).
     *
     * Soft delete preserves the audit trail and matches the canonical
     * mobile-side delete path. Hard delete is intentionally not exposed
     * here — admins who genuinely need to purge can do so via Firestore
     * console (rules still allow `delete: if isAdmin()`).
     *
     * Inputs: flag_id (required); other params kept for backward compatibility.
     */
    public function delete_flag()
    {
        $this->_require_role(self::MANAGE_ROLES, 'red_flags_delete');

        $flagId    = $this->safe_path_segment($this->input->post('flag_id') ?? '', 'flag_id');
        $studentId = trim($this->input->post('student_id') ?? '');
        $docId     = $this->fs->docId($flagId);

        $existing = $this->fs->get('studentFlags', $docId);
        if (!is_array($existing)) {
            $this->json_error('Flag not found.', 404);
        }

        if (strtolower((string)($existing['status'] ?? '')) === 'deleted') {
            $this->json_error('Flag is already deleted.', 400);
        }

        $nowMs  = (int) round(microtime(true) * 1000);
        $nowIso = date('c', (int) ($nowMs / 1000));

        $ok = $this->fs->update('studentFlags', $docId, [
            'status'      => 'deleted',
            'deletedAtMs' => $nowMs,
            'deletedBy'   => $this->admin_id,
            'updatedAt'   => $nowIso,
        ]);
        if (!$ok) {
            $this->json_error('Failed to delete flag.', 500);
        }

        log_audit('Red Flags', 'delete_flag', $flagId,
            "Soft-deleted flag for student " . ($studentId ?: ($existing['studentId'] ?? '?')));

        $this->json_success(['message' => 'Flag deleted successfully.']);
    }

    /**
     * POST — Bulk resolve multiple flags.
     *
     * Expects POST body: flags[] = array of { flag_id, ... } — only flag_id
     * is required now; class_key/section_key/student_id accepted but ignored.
     */
    public function bulk_resolve()
    {
        $this->_require_role(self::MANAGE_ROLES, 'red_flags_bulk_resolve');

        $flags = $this->input->post('flags');
        if (!is_array($flags) || empty($flags)) {
            $this->json_error('No flags provided for resolution.');
        }

        if (count($flags) > 100) {
            $this->json_error('Maximum 100 flags can be resolved at once.');
        }

        $nowMs    = (int) round(microtime(true) * 1000);
        $nowIso   = date('c', (int) ($nowMs / 1000));
        $resolved = 0;
        $errors   = [];

        foreach ($flags as $i => $f) {
            if (!is_array($f)) continue;

            $fid = trim($f['flag_id'] ?? '');
            if ($fid === '') {
                $errors[] = "Item {$i}: missing flag_id.";
                continue;
            }

            // Reuse the same path-safety regex for the flag id
            if (!preg_match("/^[A-Za-z0-9 ',_\-]+$/u", $fid)) {
                $errors[] = "Item {$i}: invalid characters in flag_id.";
                continue;
            }

            $docId    = $this->fs->docId($fid);
            $existing = $this->fs->get('studentFlags', $docId);
            if (!is_array($existing)) {
                $errors[] = "Item {$i}: flag not found.";
                continue;
            }
            if (strtolower((string)($existing['status'] ?? '')) === 'resolved') {
                continue; // already resolved, skip silently
            }

            $ok = $this->fs->update('studentFlags', $docId, [
                'status'       => 'resolved',
                'resolvedAtMs' => $nowMs,
                'resolvedAt'   => $nowIso,
                'resolvedBy'   => $this->admin_id,
                'updatedAt'    => $nowIso,
            ]);
            if ($ok) $resolved++;
        }

        log_audit('Red Flags', 'bulk_resolve', '', "Bulk resolved {$resolved} flags");

        $this->json_success([
            'message'  => "{$resolved} flag(s) resolved successfully.",
            'resolved' => $resolved,
            'errors'   => $errors,
        ]);
    }

    /**
     * GET — Analytics: trends over time, by class, by type, teacher activity.
     */
    public function get_trends()
    {
        $this->_require_role(self::VIEW_ROLES, 'red_flags_trends');

        $allFlags = $this->_collect_all_flags();

        // ── Weekly trend (last 12 weeks) ──
        $weeklyTrend  = [];
        $now          = time();
        for ($w = 11; $w >= 0; $w--) {
            $weekStart = strtotime("-{$w} weeks", strtotime('monday this week', $now));
            $weekEnd   = $weekStart + (7 * 86400) - 1;
            $label     = date('M d', $weekStart);
            $weeklyTrend[$label] = ['created' => 0, 'resolved' => 0];
        }

        // ── Month comparison ──
        $thisMonthStart = strtotime(date('Y-m-01'));
        $lastMonthStart = strtotime(date('Y-m-01', strtotime('-1 month')));
        $lastMonthEnd   = $thisMonthStart - 1;
        $thisMonth = 0;
        $lastMonth = 0;

        // ── Teacher activity ──
        $teacherActivity = [];

        // ── Subject breakdown ──
        $subjectBreakdown = [];

        // ── Resolution time analysis ──
        $resolutionTimes = [];

        foreach ($allFlags as $f) {
            $ts = is_numeric($f['createdAt']) ? (int) $f['createdAt'] : 0;
            if ($ts > 9999999999) $ts = (int) ($ts / 1000);

            // Weekly trend
            foreach ($weeklyTrend as $label => &$wk) {
                // Reconstruct week start from label
                $wStart = strtotime($label . ' ' . date('Y'));
                if ($wStart === false) continue;
                $wEnd = $wStart + (7 * 86400) - 1;
                if ($ts >= $wStart && $ts <= $wEnd) {
                    $wk['created']++;
                    if (($f['status'] ?? 'Active') === 'Resolved') {
                        $wk['resolved']++;
                    }
                    break;
                }
            }
            unset($wk);

            // Month comparison
            if ($ts >= $thisMonthStart) {
                $thisMonth++;
            } elseif ($ts >= $lastMonthStart && $ts <= $lastMonthEnd) {
                $lastMonth++;
            }

            // Teacher activity
            $tid = $f['teacherName'] ?: ($f['teacherId'] ?? 'Unknown');
            if (!isset($teacherActivity[$tid])) {
                $teacherActivity[$tid] = ['name' => $tid, 'teacherId' => $f['teacherId'] ?? '', 'count' => 0, 'high' => 0];
            }
            $teacherActivity[$tid]['count']++;
            if (($f['severity'] ?? '') === 'High') $teacherActivity[$tid]['high']++;

            // Subject breakdown
            $subj = $f['subject'] ?: 'General';
            if (!isset($subjectBreakdown[$subj])) $subjectBreakdown[$subj] = 0;
            $subjectBreakdown[$subj]++;

            // Resolution time
            if (($f['status'] ?? '') === 'Resolved' && !empty($f['resolvedAt'])) {
                $rts = is_numeric($f['resolvedAt']) ? (int) $f['resolvedAt'] : 0;
                if ($rts > 9999999999) $rts = (int) ($rts / 1000);
                if ($rts > $ts && $ts > 0) {
                    $diffHours = ($rts - $ts) / 3600;
                    $resolutionTimes[] = round($diffHours, 1);
                }
            }
        }

        // Sort teacher activity by count
        usort($teacherActivity, function ($a, $b) {
            return $b['count'] <=> $a['count'];
        });
        $teacherActivity = array_slice($teacherActivity, 0, 15);

        // Sort subject breakdown
        arsort($subjectBreakdown);

        // Avg resolution time
        $avgResolution = !empty($resolutionTimes)
            ? round(array_sum($resolutionTimes) / count($resolutionTimes), 1)
            : 0;

        $this->json_success([
            'weeklyTrend'      => $weeklyTrend,
            'thisMonth'        => $thisMonth,
            'lastMonth'        => $lastMonth,
            'monthChange'      => $lastMonth > 0
                ? round((($thisMonth - $lastMonth) / $lastMonth) * 100, 1)
                : ($thisMonth > 0 ? 100 : 0),
            'teacherActivity'  => array_values($teacherActivity),
            'subjectBreakdown' => $subjectBreakdown,
            'avgResolutionHrs' => $avgResolution,
            'resolutionTimes'  => $resolutionTimes,
        ]);
    }

    // =========================================================================
    //  ADDITIONAL AJAX ENDPOINTS
    // =========================================================================

    /**
     * GET — Single flag detail with full student info.
     *
     * Direct Firestore document fetch by {schoolId}_{flagId}.
     */
    public function get_flag_detail(string $flagId = '')
    {
        $this->_require_role(self::VIEW_ROLES, 'red_flags_detail');

        if ($flagId === '') {
            $this->json_error('Flag ID is required.', 400);
        }

        $flagId = $this->safe_path_segment($flagId, 'flagId');
        $f      = $this->fs->getEntity('studentFlags', $flagId);

        if (!is_array($f)) {
            $this->json_error('Flag not found.', 404);
        }

        // Same-school guard (defense in depth — the rules already enforce it
        // for client reads, but PHP uses a service account so check here).
        if (($f['schoolId'] ?? '') !== $this->school_id
            && ($f['schoolCode'] ?? '') !== $this->school_id) {
            $this->json_error('Flag not found.', 404);
        }

        // Teacher RBAC
        $classKey   = (string)($f['className'] ?? '');
        $sectionKey = (string)($f['section']   ?? '');
        if (!$this->_teacher_can_access($classKey, $sectionKey)) {
            $this->json_error('Access denied for this flag.', 403);
        }

        $found = [
            'flagId'      => $f['flagId']      ?? $flagId,
            'studentId'   => $f['studentId']   ?? '',
            'studentName' => $f['studentName'] ?? ($f['studentId'] ?? ''),
            'rollNo'      => $f['rollNo']      ?? '',
            'fatherName'  => $f['fatherName']  ?? '',
            'classKey'    => $classKey,
            'sectionKey'  => $sectionKey,
            'classLabel'  => $classKey !== '' && $sectionKey !== ''
                ? $classKey . ' / ' . $sectionKey
                : ($classKey ?: $sectionKey ?: 'Unknown'),
            'type'        => $this->_display_enum((string)($f['type']     ?? ''), self::ALLOWED_TYPES),
            'severity'    => $this->_display_enum((string)($f['severity'] ?? 'low'), self::ALLOWED_SEVERITIES),
            'message'     => $f['message']     ?? '',
            'subject'     => $f['subject']     ?? '',
            'teacherId'   => $f['teacherId']   ?? '',
            'teacherName' => $f['teacherName'] ?? '',
            'createdAt'   => $f['createdAtMs'] ?? 0,
            'status'      => $this->_display_enum((string)($f['status']   ?? 'active'), self::ALLOWED_STATUSES),
            'resolvedAt'  => $f['resolvedAtMs'] ?? null,
            'resolvedBy'  => $f['resolvedBy']  ?? null,
        ];

        $this->json_success(['flag' => $found]);
    }

    /**
     * POST — Get students for a specific class/section.
     *
     * Used by the Create Flag form to populate the student dropdown.
     * Reads from Firestore (canonical) — the previous RTDB read returned
     * a wrapper layer whose first key was "List", which then leaked into
     * the dropdown AND caused admin-created flags to be written with
     * studentId="List" — invisible to every parent and teacher app.
     */
    public function get_students_for_class()
    {
        $this->_require_role(self::VIEW_ROLES, 'red_flags_students');

        $classKey   = $this->safe_path_segment($this->input->post('class_key') ?? '', 'class_key');
        $sectionKey = $this->safe_path_segment($this->input->post('section_key') ?? '', 'section_key');

        // Normalize prefixes so "Section Section A" can never reach the
        // teacher-access check or the Firestore query.
        $classKey   = $this->_normalize_class_key($classKey);
        $sectionKey = $this->_normalize_section_key($sectionKey);

        // Verify teacher access
        if (!$this->_teacher_can_access($classKey, $sectionKey)) {
            $this->json_error('Access denied for this class.', 403);
        }

        // Firestore students collection: schoolId+className+section+status.
        // Two field naming conventions exist for legacy reasons — query the
        // canonical camelCase first, fall back to PascalCase if empty.
        $docs = $this->fs->schoolWhere('students', [
            ['className', '==', $classKey],
            ['section',   '==', $sectionKey],
            ['status',    '==', 'Active'],
        ]);
        if (empty($docs)) {
            $docs = $this->fs->schoolWhere('students', [
                ['Class',   '==', $classKey],
                ['Section', '==', $sectionKey],
                ['Status',  '==', 'Active'],
            ]);
        }

        $list = [];
        foreach ((array) $docs as $row) {
            $d = $row['data'] ?? $row;
            $d = is_array($row) ? ($row['data'] ?? $row) : null;
            if (!is_array($d)) continue;

            // Resolve canonical bare userId — drives the studentId we
            // write into the flag doc, which the parent app queries on.
            // The doc id is `{schoolId}_{userId}` so strip the prefix
            // when only the doc id is available.
            $userId = (string)(
                $d['userId']
                ?? $d['User Id']
                ?? $d['User ID']
                ?? $d['studentId']
                ?? ''
            );
            if ($userId === '' && isset($d['id'])) {
                $rawId = (string) $d['id'];
                $prefix = $this->school_id . '_';
                $userId = (str_starts_with($rawId, $prefix))
                    ? substr($rawId, strlen($prefix))
                    : $rawId;
            }
            if ($userId === '') continue;

            $list[] = [
                'studentId'  => $userId,
                'name'       => (string)($d['name']       ?? $d['Name']       ?? $userId),
                'rollNo'     => (string)($d['rollNo']     ?? $d['RollNo']     ?? ''),
                'fatherName' => (string)($d['fatherName'] ?? $d['FatherName'] ?? ''),
            ];
        }

        // Sort by name for predictable dropdown order.
        usort($list, function ($a, $b) {
            return strcasecmp($a['name'], $b['name']);
        });

        $this->json_success(['students' => $list]);
    }

    /**
     * GET — Per-class flag summary: total, active, high, per class-section.
     */
    public function get_class_summary()
    {
        $this->_require_role(self::VIEW_ROLES, 'red_flags_class_summary');

        $allFlags = $this->_collect_all_flags();
        $summary  = [];

        foreach ($allFlags as $f) {
            $label = $f['classLabel'] ?? 'Unknown';
            if (!isset($summary[$label])) {
                $summary[$label] = [
                    'classLabel' => $label,
                    'classKey'   => $f['classKey'] ?? '',
                    'sectionKey' => $f['sectionKey'] ?? '',
                    'total'      => 0,
                    'active'     => 0,
                    'resolved'   => 0,
                    'high'       => 0,
                    'medium'     => 0,
                    'low'        => 0,
                    'students'   => [],
                ];
            }

            $summary[$label]['total']++;

            if (($f['status'] ?? 'Active') === 'Active') {
                $summary[$label]['active']++;
            } else {
                $summary[$label]['resolved']++;
            }

            $sev = strtolower($f['severity'] ?? 'low');
            if (isset($summary[$label][$sev])) {
                $summary[$label][$sev]++;
            }

            // Track unique students
            $sid = $f['studentId'] ?? '';
            if ($sid !== '' && !in_array($sid, $summary[$label]['students'], true)) {
                $summary[$label]['students'][] = $sid;
            }
        }

        // Convert students array to count
        foreach ($summary as &$s) {
            $s['uniqueStudents'] = count($s['students']);
            unset($s['students']);
        }
        unset($s);

        // Sort by total descending
        usort($summary, function ($a, $b) {
            return $b['total'] <=> $a['total'];
        });

        $this->json_success(['classSummary' => array_values($summary)]);
    }

    /**
     * GET — Teacher activity: flags created per teacher with breakdowns.
     */
    public function get_teacher_activity()
    {
        $this->_require_role(self::VIEW_ROLES, 'red_flags_teacher_activity');

        $allFlags = $this->_collect_all_flags();
        $teachers = [];

        foreach ($allFlags as $f) {
            $tid  = $f['teacherId'] ?? 'Unknown';
            $name = $f['teacherName'] ?: $tid;

            if (!isset($teachers[$tid])) {
                $teachers[$tid] = [
                    'teacherId'   => $tid,
                    'teacherName' => $name,
                    'total'       => 0,
                    'high'        => 0,
                    'medium'      => 0,
                    'low'         => 0,
                    'homework'    => 0,
                    'behavior'    => 0,
                    'performance' => 0,
                    'active'      => 0,
                    'resolved'    => 0,
                    'lastFlagAt'  => 0,
                ];
            }

            $teachers[$tid]['total']++;

            $sev = strtolower($f['severity'] ?? 'low');
            if (isset($teachers[$tid][$sev])) {
                $teachers[$tid][$sev]++;
            }

            $type = strtolower($f['type'] ?? '');
            if (isset($teachers[$tid][$type])) {
                $teachers[$tid][$type]++;
            }

            if (($f['status'] ?? 'Active') === 'Active') {
                $teachers[$tid]['active']++;
            } else {
                $teachers[$tid]['resolved']++;
            }

            $ts = is_numeric($f['createdAt']) ? (int) $f['createdAt'] : 0;
            if ($ts > $teachers[$tid]['lastFlagAt']) {
                $teachers[$tid]['lastFlagAt'] = $ts;
            }
        }

        // Sort by total descending
        usort($teachers, function ($a, $b) {
            return $b['total'] <=> $a['total'];
        });

        $this->json_success(['teachers' => array_values($teachers)]);
    }

    /**
     * Alias — get_trend_data maps to get_trends.
     */
    public function get_trend_data()
    {
        $this->get_trends();
    }

    /**
     * Alias — add_flag maps to create_flag. Explicit guard here so a
     * future refactor that inlines logic doesn't accidentally expose
     * an unguarded entry point; create_flag re-checks as well.
     */
    public function add_flag()
    {
        $this->_require_role(self::MANAGE_ROLES, 'red_flags_create');
        $this->create_flag();
    }
}
