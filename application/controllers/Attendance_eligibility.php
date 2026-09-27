<?php
defined('BASEPATH') or exit('No direct script access allowed');

require_once APPPATH . 'libraries/Attendance_eligibility_service.php';

/**
 * Attendance_eligibility — PHASE B2: configurable eligibility / shortage engine.
 *
 * Policy is TENANT-scoped config in `attendancePolicies/{schoolId}` (a config
 * doc, NOT an attendance master). The percentage is ALWAYS derived live from the
 * canonical `attendanceSummary` via the pure Attendance_eligibility_service —
 * never stored as an authoritative mutable field. No hardcoded 75% (default is
 * configurable; research: CBSE 75%/60% X–XII, CISCE/state differ).
 *
 * Reads only from canonical attendance; writes ONLY the tenant policy doc. Does
 * not touch the entangled Attendance.php.
 */
class Attendance_eligibility extends MY_Controller
{
    const POLICY_COL = 'attendancePolicies';
    private const MANAGE_ROLES = ['Super Admin', 'School Super Admin', 'Admin', 'Principal', 'Vice Principal'];

    public function __construct()
    {
        parent::__construct();
        $this->load->helper('rbac');
    }

    public function index()
    {
        require_permission('Attendance', 'view');
        $this->load->view('include/header');
        $this->load->view('attendance/eligibility');
        $this->load->view('include/footer');
    }

    /** GET — current policy (merged over defaults). */
    public function get_policy()
    {
        require_permission('Attendance', 'view');
        header('Content-Type: application/json');
        $stored = [];
        try {
            $doc = $this->fs->get(self::POLICY_COL, (string) $this->school_id);
            if (is_array($doc)) $stored = $doc;
        } catch (\Throwable $e) { log_message('error', 'eligibility get_policy: ' . $e->getMessage()); }
        $v = Attendance_eligibility_service::validate($stored);
        return $this->json_success([
            'policy'     => $v['policy'],
            'configured' => !empty($stored),
            'defaults'   => Attendance_eligibility_service::defaults(),
        ]);
    }

    /** POST — save policy (manage). */
    public function save_policy()
    {
        require_permission('Attendance', 'manage');
        if ($this->input->method() !== 'post') return $this->json_error('POST required.');

        $raw = [
            'minPercent'          => $this->input->post('minPercent'),
            'condonableFloor'     => $this->input->post('condonableFloor'),
            'warningThreshold'    => $this->input->post('warningThreshold'),
            'includeTardyPresent' => $this->input->post('includeTardyPresent'),
            'leaveTreatment'      => (string) $this->input->post('leaveTreatment'),
            'boardLabel'          => (string) $this->input->post('boardLabel'),
            'cutoffDate'          => (string) $this->input->post('cutoffDate'),
        ];
        $ac = $this->input->post('applicableClasses');
        if (is_string($ac)) $ac = json_decode($ac, true);
        $raw['applicableClasses'] = is_array($ac) ? $ac : [];

        $v = Attendance_eligibility_service::validate($raw);
        if (!$v['ok']) return $this->json_error(implode(' ', $v['errors']), 422);

        $data = $v['policy'];
        $data['schoolId']  = $this->school_id;
        $data['updatedAt'] = date('c');
        $data['updatedBy'] = $this->admin_id ?? 'system';
        try {
            $ok = $this->fs->set(self::POLICY_COL, (string) $this->school_id, $data, true);
            if (!$ok) return $this->json_error('Failed to save policy.', 500);
        } catch (\Throwable $e) {
            log_message('error', 'eligibility save_policy: ' . $e->getMessage());
            return $this->json_error('Failed to save policy.', 500);
        }
        return $this->json_success(['policy' => $v['policy'], 'message' => 'Eligibility policy saved.']);
    }

    /**
     * GET attendance/eligibility_report?class=Class 10th&section=A
     * Derived per-student eligibility for the current session.
     */
    public function report()
    {
        require_permission('Attendance', 'view');
        header('Content-Type: application/json');

        $class   = trim((string) $this->input->get('class'));
        $section = trim((string) $this->input->get('section'));
        if ($class === '' || $section === '') return $this->json_error('class and section are required.');
        $classKey   = Firestore_service::classKey($class);
        $sectionKey = Firestore_service::sectionKey($section);

        // policy
        $stored = [];
        try { $doc = $this->fs->get(self::POLICY_COL, (string) $this->school_id); if (is_array($doc)) $stored = $doc; } catch (\Throwable $e) {}
        $policy = Attendance_eligibility_service::validate($stored)['policy'];

        // canonical summaries for this class/section (all months), current session.
        $byStudent = [];  // studentId => ['name'=>, 'months'=>[mk=>dayWise]]
        try {
            $rows = $this->fs->schoolWhere('attendanceSummary', [
                ['className', '==', $classKey],
                ['section',   '==', $sectionKey],
            ]);
            foreach ($rows as $entry) {
                $d = is_array($entry) ? ($entry['data'] ?? $entry) : null;
                if (!is_array($d)) continue;
                if (strcasecmp((string) ($d['session'] ?? ''), (string) $this->session_year) !== 0) continue;
                $sid = (string) ($d['studentId'] ?? '');
                if ($sid === '') continue;
                $mk = (string) ($d['month'] ?? '');
                if ($mk === '') continue;
                if (!isset($byStudent[$sid])) $byStudent[$sid] = ['name' => (string) ($d['studentName'] ?? $sid), 'months' => []];
                $byStudent[$sid]['months'][$mk] = (string) ($d['dayWise'] ?? '');
                if (($d['studentName'] ?? '') !== '') $byStudent[$sid]['name'] = (string) $d['studentName'];
            }
        } catch (\Throwable $e) {
            log_message('error', 'eligibility report query: ' . $e->getMessage());
            return $this->json_error('Could not read attendance for this section.', 500);
        }

        $out = []; $counts = ['eligible'=>0,'warning'=>0,'shortage'=>0,'not_applicable'=>0];
        foreach ($byStudent as $sid => $info) {
            $r = Attendance_eligibility_service::computeStudent($info['months'], $policy, $classKey);
            $counts[$r['status']] = ($counts[$r['status']] ?? 0) + 1;
            $out[] = array_merge(['studentId' => $sid, 'name' => $info['name']], $r);
        }
        usort($out, fn($a, $b) => ($a['percentage'] <=> $b['percentage']));  // worst first

        return $this->json_success([
            'class' => $classKey, 'section' => $sectionKey,
            'policy' => ['minPercent'=>$policy['minPercent'],'condonableFloor'=>$policy['condonableFloor'],'boardLabel'=>$policy['boardLabel']],
            'students' => $out, 'summary' => $counts, 'total' => count($out),
        ]);
    }
}
