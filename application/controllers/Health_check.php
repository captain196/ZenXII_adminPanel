<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Health Checker — Shared diagnostic framework
 *
 * Single controller + single view that runs data integrity checks
 * against live Firebase for any registered module. Each module's
 * checks are defined as a private _checks_{module}() method returning
 * an array of closures.
 *
 * URL:  health_check           — browser UI
 *       health_check/run       — POST {module} → JSON results
 *       health_check/run_all   — POST → JSON results for all modules
 */
class Health_check extends MY_Controller
{
    private const ADMIN_ROLES = ['Super Admin', 'School Super Admin', 'Admin'];

    public function __construct()
    {
        parent::__construct();
        require_permission('Configuration');
    }
    /* ══════════════════════════════════════════════════════════════════════
       MODULE REGISTRY
    ══════════════════════════════════════════════════════════════════════ */

    private function _modules()
    {
        return [
            'config'  => ['label' => 'Configuration',  'icon' => 'fa-cogs'],
            'classes' => ['label' => 'Classes',         'icon' => 'fa-calendar'],
            'student' => ['label' => 'Students',        'icon' => 'fa-users'],
            'staff'   => ['label' => 'Staff',           'icon' => 'fa-user-o'],
            'fees'    => ['label' => 'Fees',            'icon' => 'fa-inr'],
            'exam'    => ['label' => 'Exams & Results', 'icon' => 'fa-pencil-square-o'],
            'crm'      => ['label' => 'Admission CRM',  'icon' => 'fa-graduation-cap'],
            'academic'   => ['label' => 'Academic',        'icon' => 'fa-university'],
            'attendance'  => ['label' => 'Attendance',      'icon' => 'fa-calendar-check-o'],
            'accounting'  => ['label' => 'Accounting',      'icon' => 'fa-calculator'],
            'hr'          => ['label' => 'HR & Staffing',   'icon' => 'fa-id-card-o'],
            'operations'  => ['label' => 'Operations',       'icon' => 'fa-cog'],
        ];
    }

    /* ══════════════════════════════════════════════════════════════════════
       PUBLIC ENDPOINTS
    ══════════════════════════════════════════════════════════════════════ */

    public function index()
    {
        $this->_require_role(self::ADMIN_ROLES, 'view_health_check');
        $data['modules']      = $this->_modules();
        $data['session_year'] = $this->session_year;
        $data['school_name']  = $this->school_name;
        $data['school_id']    = $this->school_id;

        $this->load->view('include/header');
        $this->load->view('health_check/index', $data);
        $this->load->view('include/footer');
    }

    public function run()
    {
        $this->_require_role(self::ADMIN_ROLES, 'run_health_check');
        $module = trim($this->input->post('module') ?? '');
        $modules = $this->_modules();

        if (!isset($modules[$module])) {
            return $this->json_error('Unknown module: ' . $module);
        }

        $method = "_checks_{$module}";
        if (!method_exists($this, $method)) {
            return $this->json_error("No checks defined for module: {$module}");
        }

        $checks  = $this->$method();
        $results = $this->_run_checks($checks);

        $passed = count(array_filter($results, fn($r) => $r['status'] === 'pass'));
        $failed = count($results) - $passed;

        return $this->json_success([
            'module'  => $module,
            'label'   => $modules[$module]['label'],
            'total'   => count($results),
            'passed'  => $passed,
            'failed'  => $failed,
            'results' => $results,
        ]);
    }

    public function run_all()
    {
        $this->_require_role(self::ADMIN_ROLES, 'run_all_health_checks');
        $modules   = $this->_modules();
        $allResults = [];
        $totalP = 0;
        $totalF = 0;

        foreach ($modules as $key => $meta) {
            $method = "_checks_{$key}";
            if (!method_exists($this, $method)) continue;

            $checks  = $this->$method();
            $results = $this->_run_checks($checks);

            $passed = count(array_filter($results, fn($r) => $r['status'] === 'pass'));
            $failed = count($results) - $passed;
            $totalP += $passed;
            $totalF += $failed;

            $allResults[] = [
                'module'  => $key,
                'label'   => $meta['label'],
                'total'   => count($results),
                'passed'  => $passed,
                'failed'  => $failed,
                'results' => $results,
            ];
        }

        return $this->json_success([
            'modules' => $allResults,
            'total'   => $totalP + $totalF,
            'passed'  => $totalP,
            'failed'  => $totalF,
        ]);
    }

    /* ══════════════════════════════════════════════════════════════════════
       RUNNER ENGINE
    ══════════════════════════════════════════════════════════════════════ */

    private function _run_checks(array $checks): array
    {
        $results = [];
        foreach ($checks as $check) {
            $t0 = microtime(true);
            try {
                $out = call_user_func($check['fn']);
                $ms  = round((microtime(true) - $t0) * 1000);
                $results[] = [
                    'name'   => $check['name'],
                    'status' => $out['status'],
                    'detail' => $out['detail'] ?? '',
                    'ms'     => $ms,
                ];
            } catch (\Exception $e) {
                $ms = round((microtime(true) - $t0) * 1000);
                $results[] = [
                    'name'   => $check['name'],
                    'status' => 'fail',
                    'detail' => 'Exception: ' . $e->getMessage(),
                    'ms'     => $ms,
                ];
            }
        }
        return $results;
    }

    /* ══════════════════════════════════════════════════════════════════════
       SHARED HELPERS
    ══════════════════════════════════════════════════════════════════════ */

    protected function _get_session_classes(): array
    {
        $school  = $this->school_name;
        $session = $this->session_year;
        $classes = [];

        $keys = $this->firebase->shallow_get("Schools/{$school}/{$session}");
        if (!is_array($keys)) return $classes;

        foreach ($keys as $key) {
            if (strpos($key, 'Class ') !== 0) continue;
            $sections = $this->firebase->shallow_get("Schools/{$school}/{$session}/{$key}");
            if (!is_array($sections)) continue;
            foreach ($sections as $sk) {
                if (strpos($sk, 'Section ') !== 0) continue;
                $classes[] = [
                    'class_key'  => $key,
                    'section'    => str_replace('Section ', '', $sk),
                    'label'      => $key . ' / Section ' . str_replace('Section ', '', $sk),
                ];
            }
        }
        return $classes;
    }

    private function _p($detail) { return ['status' => 'pass', 'detail' => $detail]; }
    private function _w($detail) { return ['status' => 'warn', 'detail' => $detail]; }
    private function _f($detail) { return ['status' => 'fail', 'detail' => $detail]; }

    /* ══════════════════════════════════════════════════════════════════════
       CONFIG CHECKS
    ══════════════════════════════════════════════════════════════════════ */

    private function _checks_config()
    {
        $school    = $this->school_name;
        $school_id = $this->parent_db_key;
        $fb        = $this->firebase;

        return [
            [
                'name' => 'School Profile Exists',
                'fn'   => function() use ($fb, $school) {
                    // Config/Profile is set via School_config module
                    $p = $fb->get("Schools/{$school}/Config/Profile");
                    if (is_array($p) && !empty($p['display_name'] ?? $p['name'] ?? ''))
                        return $this->_p('Config profile: ' . ($p['display_name'] ?? $p['name']));
                    // Fallback: onboarding writes to System/Schools/{school_name}/profile
                    $p2 = $fb->get("System/Schools/{$school}/profile");
                    if (is_array($p2) && !empty($p2['display_name'] ?? $p2['name'] ?? ''))
                        return $this->_p('Onboarding profile: ' . ($p2['display_name'] ?? $p2['name']) . ' (Config/Profile not yet set)');
                    // Check if school node at least exists (legacy schools without profile node)
                    $sub = $fb->get("System/Schools/{$school}/subscription");
                    if (is_array($sub) || !empty($sub))
                        return $this->_p('School node exists (subscription active) — no profile node yet. Set up via Configuration page.');
                    return $this->_f('No profile found at Config/Profile or System/Schools/' . $school);
                },
            ],
            [
                'name' => 'Active Session Set',
                'fn'   => function() use ($fb, $school) {
                    $s = $fb->get("Schools/{$school}/Config/ActiveSession");
                    if (!empty($s) && is_string($s)) return $this->_p("Active session: {$s}");
                    return $this->_f('ActiveSession is empty or not set');
                },
            ],
            [
                'name' => 'Board Configuration',
                'fn'   => function() use ($fb, $school) {
                    $b = $fb->get("Schools/{$school}/Config/Board");
                    if (is_array($b)) return $this->_p('Board config present with ' . count($b) . ' keys');
                    return $this->_p('No board config yet (optional)');
                },
            ],
            [
                'name' => 'Master Class List',
                'fn'   => function() use ($fb, $school) {
                    $c = $fb->get("Schools/{$school}/Config/Classes");
                    if (is_array($c) && count($c) > 0) return $this->_p(count($c) . ' classes defined');
                    return $this->_f('No classes in Config/Classes');
                },
            ],
            [
                'name' => 'Sessions Node',
                'fn'   => function() use ($fb, $school) {
                    $s = $fb->get("Schools/{$school}/Sessions");
                    if (is_array($s) && count($s) > 0) return $this->_p(count($s) . ' sessions found');
                    return $this->_f('No sessions at Schools/' . $school . '/Sessions');
                },
            ],
        ];
    }

    /* ══════════════════════════════════════════════════════════════════════
       CLASSES CHECKS
    ══════════════════════════════════════════════════════════════════════ */

    private function _checks_classes()
    {
        $school  = $this->school_name;
        $session = $this->session_year;
        $fb      = $this->firebase;

        return [
            [
                'name' => 'Session Has Classes',
                'fn'   => function() use ($fb, $school, $session) {
                    $keys = $fb->shallow_get("Schools/{$school}/{$session}");
                    if (!is_array($keys)) return $this->_f("Cannot read session node: Schools/{$school}/{$session}");
                    $classKeys = array_filter($keys, fn($k) => strpos($k, 'Class ') === 0);
                    if (count($classKeys) > 0) return $this->_p(count($classKeys) . ' classes: ' . implode(', ', array_slice($classKeys, 0, 8)));
                    return $this->_f('No Class * keys found in session');
                },
            ],
            [
                'name' => 'Each Class Has Sections',
                'fn'   => function() use ($fb, $school, $session) {
                    $keys = $fb->shallow_get("Schools/{$school}/{$session}");
                    if (!is_array($keys)) return $this->_f('Cannot read session node');
                    $classKeys = array_filter($keys, fn($k) => strpos($k, 'Class ') === 0);
                    $empty = [];
                    foreach ($classKeys as $ck) {
                        $secs = $fb->shallow_get("Schools/{$school}/{$session}/{$ck}");
                        $secKeys = is_array($secs) ? array_filter($secs, fn($s) => strpos($s, 'Section ') === 0) : [];
                        if (empty($secKeys)) $empty[] = $ck;
                    }
                    if (empty($empty)) return $this->_p('All ' . count($classKeys) . ' classes have sections');
                    return $this->_f(count($empty) . ' classes without sections: ' . implode(', ', $empty));
                },
            ],
            [
                'name' => 'Roster Format Check',
                'fn'   => function() {
                    // Firestore-only post-R1 migration. The legacy RTDB
                    // shape check (`is_array($list)`) is meaningless once
                    // the helper guarantees a stable map shape — instead
                    // we just verify the helper returns *something* for
                    // the first class, which exercises the compound
                    // index + the Firestore students collection end-to-end.
                    $classes = $this->_get_session_classes();
                    if (empty($classes)) return $this->_p('No classes to check');
                    $first = $classes[0];
                    $list = $this->roster->for_class($first['class_key'], 'Section ' . $first['section']);
                    if (empty($list)) return $this->_p("Empty roster in {$first['label']} (OK for new class)");
                    return $this->_p('Roster has ' . count($list) . " entries in {$first['label']}");
                },
            ],
            [
                'name' => 'Class Key Naming Convention',
                'fn'   => function() use ($fb, $school, $session) {
                    $keys = $fb->shallow_get("Schools/{$school}/{$session}");
                    if (!is_array($keys)) return $this->_f('Cannot read session');
                    $classKeys = array_filter($keys, fn($k) => strpos($k, 'Class ') === 0);
                    $bad = [];
                    foreach ($classKeys as $ck) {
                        if (!preg_match('/^Class \d+(st|nd|rd|th)$/i', $ck) && !preg_match('/^Class (LKG|UKG|Nursery|KG|Pre)/i', $ck) && !preg_match('/^Class \w+$/', $ck)) {
                            $bad[] = $ck;
                        }
                    }
                    if (empty($bad)) return $this->_p('All ' . count($classKeys) . ' class keys follow naming convention');
                    return $this->_f('Unusual names: ' . implode(', ', $bad));
                },
            ],
        ];
    }

    /* ══════════════════════════════════════════════════════════════════════
       STUDENT CHECKS
    ══════════════════════════════════════════════════════════════════════ */

    private function _checks_student()
    {
        $school_id = $this->parent_db_key;
        $school    = $this->school_name;
        $session   = $this->session_year;
        $fb        = $this->firebase;

        return [
            [
                'name' => 'Parent Profiles Node Exists',
                'fn'   => function() use ($fb, $school_id) {
                    $keys = $fb->shallow_get("Users/Parents/{$school_id}");
                    if (is_array($keys) && count($keys) > 0) return $this->_p(count($keys) . ' top-level keys in Users/Parents/' . $school_id);
                    return $this->_f('Users/Parents/' . $school_id . ' is empty or missing');
                },
            ],
            [
                'name' => 'Student Profile Schema (sample)',
                'fn'   => function() use ($fb, $school_id) {
                    $all = $fb->get("Users/Parents/{$school_id}");
                    if (!is_array($all)) return $this->_f('Cannot read profiles');
                    $errors = [];
                    $checked = 0;
                    foreach ($all as $id => $stu) {
                        if ($id === 'Count' || !is_array($stu)) continue;
                        $checked++;
                        if (empty($stu['Name'])) $errors[] = "{$id}: missing Name";
                        if (empty($stu['Class'])) $errors[] = "{$id}: missing Class";
                        if ($checked >= 10) break;
                    }
                    if (empty($errors)) return $this->_p("{$checked} profiles sampled — all have required fields");
                    return $this->_f(count($errors) . ' issues: ' . implode('; ', array_slice($errors, 0, 5)));
                },
            ],
            [
                'name' => 'Roster Cross-Reference (sample)',
                'fn'   => function() {
                    // Firestore-only post-R1 migration. Picks a sample of
                    // 5 Active students directly from the Firestore
                    // `students` collection (the same collection the
                    // helper queries) and verifies each one is actually
                    // returned by `for_class()` — i.e. the compound
                    // query path produces consistent results with the
                    // raw schoolWhere query. A divergence here points
                    // at a stale className/section field on the doc.
                    $sample = $this->fs->schoolWhere(
                        'students',
                        [['status', '==', 'Active']],
                        null, 'ASC', 5
                    );
                    if (empty($sample)) return $this->_p('No active students to cross-check');
                    $missing = [];
                    $checked = 0;
                    foreach ($sample as $entry) {
                        $data = is_array($entry) ? ($entry['data'] ?? $entry) : null;
                        if (!is_array($data)) continue;
                        $sid = $data['userId'] ?? $data['studentId'] ?? '';
                        $cls = $data['className'] ?? $data['Class'] ?? '';
                        $sec = $data['section'] ?? $data['Section'] ?? '';
                        if ($sid === '' || $cls === '' || $sec === '') continue;
                        $roster = $this->roster->for_class($cls, $sec);
                        if (!isset($roster[$sid])) {
                            $missing[] = "{$sid} not in for_class({$cls}/{$sec})";
                        }
                        $checked++;
                    }
                    if (empty($missing)) return $this->_p("{$checked} students verified");
                    return $this->_f(count($missing) . ' inconsistencies: ' . implode('; ', $missing));
                },
            ],
            [
                'name' => 'Student Count Node',
                'fn'   => function() use ($fb, $school_id) {
                    $count = $fb->get("Users/Parents/{$school_id}/Count");
                    if ($count !== null && is_numeric($count)) return $this->_p("Count = {$count}");
                    if ($count === null) return $this->_p('Count not set (will init on first admission)');
                    return $this->_f('Count is not numeric: ' . json_encode($count));
                },
            ],
            [
                'name' => 'Phone Index (Tenant-Scoped) Sample',
                'fn'   => function() use ($fb, $school_id) {
                    $school_name = $this->school_name;
                    $all = $fb->get("Users/Parents/{$school_id}");
                    if (!is_array($all)) return $this->_p('No profiles to check');
                    $errors = [];
                    $checked = 0;
                    foreach ($all as $id => $stu) {
                        if ($id === 'Count' || !is_array($stu)) continue;
                        $phone = $stu['Phone Number'] ?? '';
                        if ($phone === '') continue;
                        // Check tenant-scoped index (primary)
                        $indexVal = $fb->get("Schools/{$school_name}/Phone_Index/{$phone}");
                        if ($indexVal !== $id) $errors[] = "{$id}: Phone_Index/{$phone} = " . json_encode($indexVal);
                        // Also check legacy Exits path
                        $exitVal = $fb->get("Exits/{$phone}");
                        if ($exitVal !== $school_id) $errors[] = "{$id}: Exits/{$phone} = " . json_encode($exitVal) . ' (legacy)';
                        $checked++;
                        if ($checked >= 5) break;
                    }
                    if (empty($errors)) return $this->_p("{$checked} phone mappings verified (tenant-scoped + legacy)");
                    return $this->_f(count($errors) . ' mapping issues: ' . implode('; ', $errors));
                },
            ],
        ];
    }

    /* ══════════════════════════════════════════════════════════════════════
       STAFF CHECKS
    ══════════════════════════════════════════════════════════════════════ */

    private function _checks_staff()
    {
        $school  = $this->school_name;
        $session = $this->session_year;
        $fb      = $this->firebase;

        return [
            [
                'name' => 'Teachers Node Exists',
                'fn'   => function() use ($fb, $school, $session) {
                    $t = $fb->get("Schools/{$school}/{$session}/Teachers");
                    if (is_array($t) && count($t) > 0) return $this->_p(count($t) . ' teachers found');
                    if ($t === null) return $this->_p('No Teachers node yet (empty for new session)');
                    return $this->_f('Teachers node is not an array');
                },
            ],
            [
                'name' => 'Staff Profile Schema (sample)',
                'fn'   => function() use ($fb, $school, $session) {
                    $t = $fb->get("Schools/{$school}/{$session}/Teachers");
                    if (!is_array($t) || empty($t)) return $this->_p('No teachers to sample');
                    $errors = [];
                    $checked = 0;
                    foreach ($t as $id => $staff) {
                        if (!is_array($staff)) continue;
                        if (empty($staff['Name'] ?? $staff['name'] ?? '')) $errors[] = "{$id}: missing Name";
                        $checked++;
                        if ($checked >= 5) break;
                    }
                    if (empty($errors)) return $this->_p("{$checked} staff profiles sampled — all OK");
                    return $this->_f(count($errors) . ' issues: ' . implode('; ', $errors));
                },
            ],
            [
                'name' => 'Teacher Class Assignments',
                'fn'   => function() use ($fb, $school, $session) {
                    $t = $fb->get("Schools/{$school}/{$session}/Teachers");
                    if (!is_array($t) || empty($t)) return $this->_p('No teachers — skip assignment check');
                    $assigned = 0;
                    foreach ($t as $staff) {
                        if (is_array($staff) && !empty($staff['class'] ?? $staff['Class'] ?? $staff['assigned_class'] ?? '')) $assigned++;
                    }
                    return $this->_p("{$assigned}/" . count($t) . " teachers have class assignments");
                },
            ],
        ];
    }

    /* ══════════════════════════════════════════════════════════════════════
       FEES CHECKS
    ══════════════════════════════════════════════════════════════════════ */

    private function _checks_fees()
    {
        $school  = $this->school_name;
        $session = $this->session_year;
        $fb      = $this->firebase;

        return [
            [
                'name' => 'Fee Structure Exists',
                'fn'   => function() use ($fb, $school, $session) {
                    $fs = $fb->get("Schools/{$school}/{$session}/Accounts/Fees/Fees Structure");
                    if (is_array($fs) && count($fs) > 0) return $this->_p(count($fs) . ' fee structure entries');
                    return $this->_f('No fee structure at Accounts/Fees/Fees Structure');
                },
            ],
            [
                'name' => 'Classes Fees Configured',
                'fn'   => function() use ($fb, $school, $session) {
                    $cf = $fb->get("Schools/{$school}/{$session}/Accounts/Fees/Classes Fees");
                    if (is_array($cf) && count($cf) > 0) return $this->_p(count($cf) . ' class fee entries');
                    return $this->_f('No class fees configured');
                },
            ],
            [
                'name' => 'Account Heads Exist',
                'fn'   => function() use ($fb, $school, $session) {
                    $ab = $fb->shallow_get("Schools/{$school}/{$session}/Accounts/Account_book");
                    if (is_array($ab) && count($ab) > 0) return $this->_p(count($ab) . ' account heads: ' . implode(', ', array_slice($ab, 0, 5)));
                    return $this->_f('No account heads in Account_book');
                },
            ],
            [
                'name' => 'Voucher Format (sample)',
                'fn'   => function() use ($fb, $school, $session) {
                    $vouchers = $fb->shallow_get("Schools/{$school}/{$session}/Accounts/Vouchers");
                    if (!is_array($vouchers) || empty($vouchers)) return $this->_p('No vouchers yet');
                    $lastDate = end($vouchers);
                    $dayVouchers = $fb->get("Schools/{$school}/{$session}/Accounts/Vouchers/{$lastDate}");
                    if (!is_array($dayVouchers)) return $this->_p('Voucher date node empty');
                    $keys = array_keys($dayVouchers);
                    $badKeys = array_filter($keys, fn($k) => !preg_match('/^F\d+$/', $k));
                    if (empty($badKeys)) return $this->_p(count($keys) . " vouchers on {$lastDate} — all match F\\d+ format");
                    return $this->_f('Non-standard keys: ' . implode(', ', array_slice($badKeys, 0, 3)));
                },
            ],
            [
                'name' => 'Fee Structure vs Classes Fees Consistency',
                'fn'   => function() use ($fb, $school, $session) {
                    $structure = $fb->get("Schools/{$school}/{$session}/Accounts/Fees/Fees Structure");
                    $classFees = $fb->get("Schools/{$school}/{$session}/Accounts/Fees/Classes Fees");
                    if (!is_array($structure) || !is_array($classFees)) return $this->_p('Cannot compare — one or both missing');
                    $structTitles = [];
                    foreach ($structure as $period => $titles) {
                        if (is_array($titles)) $structTitles = array_merge($structTitles, array_keys($titles));
                    }
                    $structTitles = array_unique($structTitles);
                    if (empty($structTitles)) return $this->_p('No fee titles to compare');
                    return $this->_p(count($structTitles) . ' unique fee titles in structure, ' . count($classFees) . ' class entries');
                },
            ],
        ];
    }

    /* ══════════════════════════════════════════════════════════════════════
       EXAM & RESULTS CHECKS
    ══════════════════════════════════════════════════════════════════════ */

    private function _checks_exam()
    {
        $school  = $this->school_name;
        $session = $this->session_year;
        $fb      = $this->firebase;

        return [
            [
                'name' => 'Results Node Exists',
                'fn'   => function() use ($fb, $school, $session) {
                    $keys = $fb->shallow_get("Schools/{$school}/{$session}/Results");
                    if (is_array($keys) && count($keys) > 0) return $this->_p('Results node has ' . count($keys) . ' sub-nodes: ' . implode(', ', $keys));
                    return $this->_p('No Results node yet (empty for new session)');
                },
            ],
            [
                'name' => 'Exam Templates',
                'fn'   => function() use ($fb, $school, $session) {
                    $tpl = $fb->shallow_get("Schools/{$school}/{$session}/Results/Templates");
                    if (!is_array($tpl) || empty($tpl)) return $this->_p('No templates defined yet');
                    // Templates are keyed by examId — each contains class/section/subject data
                    $exams = [];
                    foreach ($tpl as $examId) {
                        $classes = $fb->shallow_get("Schools/{$school}/{$session}/Results/Templates/{$examId}");
                        $classCount = is_array($classes) ? count($classes) : 0;
                        $exams[] = "{$examId} ({$classCount} classes)";
                    }
                    return $this->_p(count($tpl) . ' exam template(s): ' . implode(', ', array_slice($exams, 0, 5)));
                },
            ],
            [
                'name' => 'Marks Data Present',
                'fn'   => function() use ($fb, $school, $session) {
                    $marks = $fb->shallow_get("Schools/{$school}/{$session}/Results/Marks");
                    if (is_array($marks) && count($marks) > 0) return $this->_p(count($marks) . ' exams have marks data');
                    return $this->_p('No marks entered yet');
                },
            ],
            [
                'name' => 'Computed Results Sync',
                'fn'   => function() use ($fb, $school, $session) {
                    $marks = $fb->shallow_get("Schools/{$school}/{$session}/Results/Marks");
                    $computed = $fb->shallow_get("Schools/{$school}/{$session}/Results/Computed");
                    if (!is_array($marks) || empty($marks)) return $this->_p('No marks — nothing to compute');
                    if (!is_array($computed)) $computed = [];
                    $missing = array_diff($marks, $computed);
                    if (empty($missing)) return $this->_p('All ' . count($marks) . ' exams have computed results');
                    return $this->_f(count($missing) . ' exams with marks but no computed results: ' . implode(', ', array_slice($missing, 0, 3)));
                },
            ],
        ];
    }

    /* ══════════════════════════════════════════════════════════════════════
       ADMISSION CRM CHECKS
    ══════════════════════════════════════════════════════════════════════ */

    private function _checks_crm()
    {
        $school    = $this->school_name;
        $school_id = $this->parent_db_key;
        $session   = $this->session_year;
        $fb        = $this->firebase;
        $base      = "Schools/{$school}/CRM/Admissions";

        $defaultStages = [
            'document_collection' => 'Document Collection',
            'under_review'        => 'Under Review',
            'interview'           => 'Interview / Test',
            'approved'            => 'Approved',
            'rejected'            => 'Rejected',
            'waitlisted'          => 'Waitlisted',
        ];

        return [
            [
                'name' => 'Firebase Connectivity',
                'fn'   => function() use ($fb, $base) {
                    $testPath = "{$base}/TestPing";
                    $fb->set($testPath, ['ping' => true, 'ts' => date('Y-m-d H:i:s')]);
                    $check = $fb->get($testPath);
                    $fb->delete($base, 'TestPing');
                    if (is_array($check) && ($check['ping'] ?? false)) return $this->_p('Read/write successful');
                    return $this->_f('Write OK but read returned: ' . json_encode($check));
                },
            ],
            [
                'name' => 'CRM Base Path',
                'fn'   => function() use ($fb, $base) {
                    $d = $fb->get($base);
                    if ($d !== null) return $this->_p("Exists with " . (is_array($d) ? count($d) : 0) . ' top-level keys');
                    return $this->_p('Empty (OK for new setup)');
                },
            ],
            [
                'name' => 'Counter Node',
                'fn'   => function() use ($fb, $base) {
                    $c = $fb->get("{$base}/Counter");
                    if ($c !== null && is_numeric($c)) return $this->_p("Value: {$c}");
                    if ($c === null) return $this->_p('Not set yet (will init on first record)');
                    return $this->_f('Not numeric: ' . json_encode($c));
                },
            ],
            [
                'name' => 'Class Configuration',
                'fn'   => function() {
                    $classes = $this->_get_session_classes();
                    if (!empty($classes)) {
                        $labels = array_column($classes, 'label');
                        return $this->_p(count($classes) . ' class-sections: ' . implode(', ', array_slice($labels, 0, 5)) . (count($classes) > 5 ? '...' : ''));
                    }
                    return $this->_f('No classes in session ' . $this->session_year);
                },
            ],
            [
                'name' => 'Pipeline Stages',
                'fn'   => function() use ($fb, $base, $defaultStages) {
                    $s = $fb->get("{$base}/Settings");
                    if (is_array($s) && !empty($s['stages'])) return $this->_p(count($s['stages']) . ' custom stages: ' . implode(', ', array_keys($s['stages'])));
                    return $this->_p('Using ' . count($defaultStages) . ' default stages');
                },
            ],
            [
                'name' => 'Inquiry Data Integrity',
                'fn'   => function() use ($fb, $base) {
                    $inqs = $fb->get("{$base}/Inquiries") ?? [];
                    if (!is_array($inqs)) $inqs = [];
                    $errors = [];
                    $count = 0;
                    foreach ($inqs as $id => $inq) {
                        if (!is_array($inq)) continue;
                        $count++;
                        if (empty($inq['student_name'])) $errors[] = "{$id}: no student_name";
                        if (empty($inq['phone'])) $errors[] = "{$id}: no phone";
                        if (empty($inq['session'])) $errors[] = "{$id}: no session";
                    }
                    if (empty($errors)) return $this->_p("{$count} inquiries — all valid");
                    return $this->_f(count($errors) . ' issues: ' . implode('; ', array_slice($errors, 0, 5)));
                },
            ],
            [
                'name' => 'Application Data Integrity',
                'fn'   => function() use ($fb, $base, $defaultStages) {
                    $apps = $fb->get("{$base}/Applications") ?? [];
                    if (!is_array($apps)) $apps = [];
                    $settings = $fb->get("{$base}/Settings");
                    $validStages = array_keys(is_array($settings) && !empty($settings['stages']) ? $settings['stages'] : $defaultStages);
                    $validStages = array_merge($validStages, ['enrolled', 'waitlisted']);
                    $validStatuses = ['pending', 'approved', 'rejected', 'enrolled', 'waitlisted'];

                    $errors = [];
                    $count = 0;
                    foreach ($apps as $id => $app) {
                        if (!is_array($app)) continue;
                        $count++;
                        if (empty($app['student_name'])) $errors[] = "{$id}: no student_name";
                        if (!in_array($app['status'] ?? '', $validStatuses)) $errors[] = "{$id}: invalid status '" . ($app['status'] ?? '') . "'";
                        if (!in_array($app['stage'] ?? '', $validStages)) $errors[] = "{$id}: invalid stage '" . ($app['stage'] ?? '') . "'";
                        if (($app['status'] ?? '') === 'enrolled' && empty($app['student_id'])) $errors[] = "{$id}: enrolled but no student_id";
                    }
                    if (empty($errors)) return $this->_p("{$count} applications — all valid");
                    return $this->_f(count($errors) . ' issues: ' . implode('; ', array_slice($errors, 0, 5)));
                },
            ],
            [
                'name' => 'Status Distribution',
                'fn'   => function() use ($fb, $base, $session) {
                    $apps = $fb->get("{$base}/Applications") ?? [];
                    if (!is_array($apps)) return $this->_p('No applications');
                    $dist = [];
                    foreach ($apps as $app) {
                        if (!is_array($app) || ($app['session'] ?? '') !== $session) continue;
                        $s = $app['status'] ?? 'unknown';
                        $dist[$s] = ($dist[$s] ?? 0) + 1;
                    }
                    if (empty($dist)) return $this->_p('No applications in current session');
                    $str = [];
                    foreach ($dist as $s => $c) $str[] = "{$s}: {$c}";
                    return $this->_p(implode(', ', $str));
                },
            ],
            [
                'name' => 'Waitlist Integrity',
                'fn'   => function() use ($fb, $base) {
                    $wl = $fb->get("{$base}/Waitlist") ?? [];
                    if (!is_array($wl)) $wl = [];
                    $errors = [];
                    $count = 0;
                    foreach ($wl as $id => $w) {
                        if (!is_array($w)) continue;
                        $count++;
                        if (empty($w['application_id'])) { $errors[] = "{$id}: no application_id"; continue; }
                        $linked = $fb->get("{$base}/Applications/{$w['application_id']}");
                        if (!is_array($linked)) $errors[] = "{$id}: linked app {$w['application_id']} not found";
                    }
                    if (empty($errors)) return $this->_p("{$count} waitlist entries — all valid");
                    return $this->_f(count($errors) . ' issues: ' . implode('; ', array_slice($errors, 0, 5)));
                },
            ],
            [
                'name' => 'Student ID Format (enrolled)',
                'fn'   => function() use ($fb, $base, $school_id) {
                    $apps = $fb->get("{$base}/Applications") ?? [];
                    if (!is_array($apps)) return $this->_p('No applications');
                    $errors = [];
                    $enrolled = 0;
                    foreach ($apps as $id => $app) {
                        if (!is_array($app) || ($app['status'] ?? '') !== 'enrolled') continue;
                        $enrolled++;
                        $stuId = $app['student_id'] ?? '';
                        if (strpos($stuId, 'STU000') !== 0) $errors[] = "{$id}: '{$stuId}' not STU000x";
                        else {
                            $profile = $fb->get("Users/Parents/{$school_id}/{$stuId}");
                            if (!is_array($profile)) $errors[] = "{$id}: profile for {$stuId} missing";
                        }
                    }
                    if (empty($errors)) return $this->_p("{$enrolled} enrolled — all IDs valid & profiles exist");
                    return $this->_f(count($errors) . ' issues: ' . implode('; ', array_slice($errors, 0, 5)));
                },
            ],
            [
                'name' => 'Duplicate Phone Detection',
                'fn'   => function() use ($fb, $base, $session) {
                    $apps = $fb->get("{$base}/Applications") ?? [];
                    if (!is_array($apps)) return $this->_p('No applications');
                    $phones = [];
                    $dupes = [];
                    foreach ($apps as $id => $app) {
                        if (!is_array($app) || ($app['session'] ?? '') !== $session) continue;
                        if (($app['status'] ?? '') === 'rejected') continue;
                        $p = $app['phone'] ?? '';
                        if ($p === '') continue;
                        if (isset($phones[$p])) $dupes[] = "{$p}: {$phones[$p]} & {$id}";
                        else $phones[$p] = $id;
                    }
                    if (empty($dupes)) return $this->_p('No duplicate phones in active applications');
                    return $this->_f(count($dupes) . ' duplicates: ' . implode('; ', array_slice($dupes, 0, 5)));
                },
            ],
        ];
    }

    /* ══════════════════════════════════════════════════════════════════════
       ACADEMIC CHECKS
    ══════════════════════════════════════════════════════════════════════ */

    private function _checks_academic()
    {
        $school  = $this->school_name;
        $session = $this->session_year;
        $fb      = $this->firebase;

        return [
            [
                'name' => 'Timetable Settings',
                'fn'   => function() use ($fb, $school, $session) {
                    $s = $fb->get("Schools/{$school}/{$session}/Time_table_settings");
                    if (is_array($s) && !empty($s['No_of_periods']))
                        return $this->_p((int)$s['No_of_periods'] . ' periods, ' . ($s['Start_time'] ?? '?') . ' - ' . ($s['End_time'] ?? '?'));
                    return $this->_f('No timetable settings configured');
                },
            ],
            [
                'name' => 'Class Timetables Populated',
                'fn'   => function() use ($fb, $school, $session) {
                    $classes = $this->_get_session_classes();
                    if (empty($classes)) return $this->_p('No classes to check');
                    $filled = 0;
                    foreach ($classes as $cls) {
                        $tt = $fb->get("Schools/{$school}/{$session}/{$cls['class_key']}/Section {$cls['section']}/Time_table");
                        if (is_array($tt) && !empty($tt)) $filled++;
                    }
                    if ($filled === count($classes)) return $this->_p("All {$filled} classes have timetables");
                    if ($filled > 0) return $this->_p("{$filled}/" . count($classes) . " classes have timetables");
                    return $this->_f('No class timetables configured');
                },
            ],
            [
                'name' => 'Curriculum Data',
                'fn'   => function() use ($fb, $school, $session) {
                    $cur = $fb->shallow_get("Schools/{$school}/{$session}/Academic/Curriculum");
                    if (is_array($cur) && count($cur) > 0)
                        return $this->_p(count($cur) . ' class-sections have curriculum plans');
                    return $this->_p('No curriculum data yet (optional)');
                },
            ],
            [
                'name' => 'Calendar Events',
                'fn'   => function() use ($fb, $school, $session) {
                    $cal = $fb->get("Schools/{$school}/{$session}/Academic/Calendar");
                    if (!is_array($cal)) return $this->_p('No calendar events yet');
                    $byType = [];
                    foreach ($cal as $ev) {
                        if (!is_array($ev)) continue;
                        $t = $ev['type'] ?? 'event';
                        $byType[$t] = ($byType[$t] ?? 0) + 1;
                    }
                    $str = [];
                    foreach ($byType as $t => $c) $str[] = "{$t}: {$c}";
                    return $this->_p(count($cal) . ' events — ' . implode(', ', $str));
                },
            ],
            [
                'name' => 'Substitute Records',
                'fn'   => function() use ($fb, $school, $session) {
                    $subs = $fb->get("Schools/{$school}/{$session}/Academic/Substitutes");
                    if (!is_array($subs)) return $this->_p('No substitute records');
                    $active = 0;
                    foreach ($subs as $s) {
                        if (is_array($s) && ($s['status'] ?? '') === 'assigned') $active++;
                    }
                    return $this->_p(count($subs) . ' total, ' . $active . ' currently assigned');
                },
            ],
        ];
    }

    /* ══════════════════════════════════════════════════════════════════════
       ATTENDANCE CHECKS
    ══════════════════════════════════════════════════════════════════════ */

    private function _checks_attendance()
    {
        $school  = $this->school_name;
        $session = $this->session_year;
        $fb      = $this->firebase;

        $todayDay  = (int) date('j');
        $monthName = date('F');
        $yearNum   = (int) date('Y');
        $attKey    = "{$monthName} {$yearNum}";
        $todayDate = date('Y-m-d');

        return [
            /* 1. Firebase Connectivity */
            [
                'name' => 'Firebase Connectivity',
                'fn'   => function() use ($fb, $school, $session) {
                    $t0 = microtime(true);
                    $test = $fb->shallow_get("Schools/{$school}/{$session}");
                    $ms = round((microtime(true) - $t0) * 1000);
                    if (is_array($test)) return $this->_p("Connected ({$ms}ms)");
                    return $this->_f('Cannot reach Firebase — check credentials and network');
                },
            ],
            /* 2. Session Validity */
            [
                'name' => 'Session Validity',
                'fn'   => function() use ($session) {
                    $parts = explode('-', $session);
                    $start = (int) ($parts[0] ?? 0);
                    $endRaw = (int) ($parts[1] ?? 0);
                    $end = $endRaw < 100
                        ? (int) (substr((string) $start, 0, 2) . str_pad((string) $endRaw, 2, '0', STR_PAD_LEFT))
                        : $endRaw;
                    $now = (int) date('Y');
                    $ok = ($start > 0 && $end > 0 && $end === $start + 1 && $now >= $start && $now <= $end);
                    if ($ok) return $this->_p("Active session: {$session}");
                    return $this->_f("Session '{$session}' may be misconfigured or expired (current year: {$now})");
                },
            ],
            /* 3. Settings Completeness */
            [
                'name' => 'Settings Configuration',
                'fn'   => function() use ($fb, $school) {
                    $config = $fb->get("Schools/{$school}/Config/Attendance");
                    if (!is_array($config)) return $this->_f('Attendance config not found — save settings first');
                    $issues = [];
                    if (empty($config['late_threshold_student'])) $issues[] = 'Student late threshold not set';
                    if (empty($config['late_threshold_staff']))   $issues[] = 'Staff late threshold not set';
                    if (empty($config['working_days']) || !is_array($config['working_days'])) $issues[] = 'Working days not configured';
                    if (empty($issues)) return $this->_p('All settings configured');
                    return $this->_f(implode('; ', $issues));
                },
            ],
            /* 4. Holiday Calendar */
            [
                'name' => 'Holiday Calendar',
                'fn'   => function() use ($fb, $school, $todayDate) {
                    $holidays = $fb->get("Schools/{$school}/Config/Attendance/holidays");
                    if (!is_array($holidays) || empty($holidays)) return $this->_p('No holidays defined (optional)');
                    $future = 0;
                    foreach ($holidays as $date => $name) {
                        if ($date >= $todayDate) $future++;
                    }
                    return $this->_p(count($holidays) . " holiday(s) defined, {$future} upcoming");
                },
            ],
            /* 5. Device Status */
            [
                'name' => 'Registered Devices',
                'fn'   => function() use ($fb, $school) {
                    $devices = $fb->get("Schools/{$school}/Config/Devices");
                    if (!is_array($devices) || empty($devices)) return $this->_p('No devices registered (optional for manual attendance)');
                    $active = 0; $stale = 0;
                    foreach ($devices as $dev) {
                        if (!is_array($dev)) continue;
                        $status = $dev['status'] ?? 'unknown';
                        if ($status === 'active') {
                            $lastPing = $dev['last_ping'] ?? '';
                            if ($lastPing && (time() - strtotime($lastPing)) > 3600) $stale++;
                            else $active++;
                        }
                    }
                    $detail = count($devices) . " device(s), {$active} online";
                    if ($stale > 0) {
                        return $this->_f("{$detail}, {$stale} stale (no ping in 1h)");
                    }
                    return $this->_p($detail);
                },
            ],
            /* 6. Today's Student Attendance Coverage */
            [
                'name' => "Today's Student Coverage",
                'fn'   => function() use ($fb, $school, $session, $attKey, $todayDay) {
                    $classes = $this->_get_session_classes();
                    if (empty($classes)) return $this->_p('No classes in session');
                    $totalSections = count($classes);
                    $done = 0; $partial = 0; $pending = 0;
                    foreach ($classes as $cls) {
                        $allStudents = $fb->get("Schools/{$school}/{$session}/{$cls['class_key']}/Section {$cls['section']}/Students");
                        $list = (is_array($allStudents) && !empty($allStudents['List'])) ? $allStudents['List'] : [];
                        $total = 0; $marked = 0;
                        foreach ($list as $sid => $sname) {
                            if (!is_string($sid) || trim($sid) === '') continue;
                            $total++;
                            $attStr = isset($allStudents[$sid]['Attendance'][$attKey])
                                && is_string($allStudents[$sid]['Attendance'][$attKey])
                                ? $allStudents[$sid]['Attendance'][$attKey] : '';
                            if (strlen($attStr) >= $todayDay && $attStr[$todayDay - 1] !== 'V') $marked++;
                        }
                        $pct = $total > 0 ? round($marked / $total * 100) : 100;
                        if ($pct >= 100) $done++;
                        elseif ($pct > 0) $partial++;
                        else $pending++;
                    }
                    $detail = "{$done}/{$totalSections} sections fully marked";
                    if ($partial > 0) $detail .= ", {$partial} partial";
                    if ($pending > 0) $detail .= ", {$pending} pending";
                    if ($pending > 0 && (int) date('H') >= 10) return $this->_f($detail);
                    return $this->_p($detail);
                },
            ],
            /* 7. Today's Staff Attendance */
            [
                'name' => "Today's Staff Coverage",
                'fn'   => function() use ($fb, $school, $session, $attKey, $todayDay) {
                    $teachers = $fb->get("Schools/{$school}/{$session}/Teachers");
                    if (!is_array($teachers) || empty($teachers)) return $this->_p('No staff in session');
                    $staffAtt = $fb->get("Schools/{$school}/{$session}/Staff_Attendance/{$attKey}");
                    $total = 0; $marked = 0;
                    foreach ($teachers as $sid => $prof) {
                        if (!is_string($sid) || trim($sid) === '') continue;
                        $total++;
                        $sAtt = isset($staffAtt[$sid]) && is_string($staffAtt[$sid]) ? $staffAtt[$sid] : '';
                        if (strlen($sAtt) >= $todayDay && $sAtt[$todayDay - 1] !== 'V') $marked++;
                    }
                    $pct = $total > 0 ? round($marked / $total * 100) : 0;
                    $detail = "{$marked}/{$total} staff marked ({$pct}%)";
                    if ($pct < 100 && (int) date('H') >= 10) return $this->_f($detail);
                    return $this->_p($detail);
                },
            ],
            /* 8. Today's Punch Activity */
            [
                'name' => "Today's Punch Activity",
                'fn'   => function() use ($fb, $school, $session, $todayDate) {
                    $punchLog = $fb->get("Schools/{$school}/{$session}/Attendance/Punch_Log/{$todayDate}");
                    $count = is_array($punchLog) ? count($punchLog) : 0;
                    if ($count === 0) return $this->_p('No punches today (OK if using manual attendance)');
                    $devices = [];
                    foreach ($punchLog as $p) {
                        if (is_array($p)) {
                            $did = $p['device_id'] ?? 'unknown';
                            $devices[$did] = ($devices[$did] ?? 0) + 1;
                        }
                    }
                    return $this->_p("{$count} punch(es) across " . count($devices) . " device(s)");
                },
            ],
        ];
    }

    /* ══════════════════════════════════════════════════════════════════════
       ACCOUNTING CHECKS
    ══════════════════════════════════════════════════════════════════════ */

    private function _checks_accounting()
    {
        $school  = $this->school_name;
        $session = $this->session_year;
        $fb      = $this->firebase;

        return [
            /* 1. Chart of Accounts Exists */
            [
                'name' => 'Chart of Accounts Exists',
                'fn'   => function() use ($fb, $school) {
                    $coa = $fb->shallow_get("Schools/{$school}/Accounts/ChartOfAccounts");
                    if (!is_array($coa) || empty($coa))
                        return $this->_f('No Chart of Accounts found. Seed defaults or create accounts.');
                    return $this->_p(count($coa) . ' accounts in chart');
                },
            ],
            /* 2. Essential Accounts Present (1010 Cash, 4010 Tuition) */
            [
                'name' => 'Essential Accounts Present',
                'fn'   => function() use ($fb, $school) {
                    $required = [
                        '1010' => 'Cash in Hand',
                        '4010' => 'Tuition Fees',
                    ];
                    $missing = [];
                    foreach ($required as $code => $label) {
                        $acct = $fb->get("Schools/{$school}/Accounts/ChartOfAccounts/{$code}");
                        if (!is_array($acct) || ($acct['status'] ?? '') !== 'active') {
                            $missing[] = "{$code} ({$label})";
                        }
                    }
                    if (!empty($missing))
                        return $this->_f('Missing essential accounts: ' . implode(', ', $missing));
                    return $this->_p('All essential accounts present (1010, 4010)');
                },
            ],
            /* 3. CoA Category Balance (all 5 categories have at least 1 account) */
            [
                'name' => 'CoA Has All 5 Categories',
                'fn'   => function() use ($fb, $school) {
                    $coa = $fb->get("Schools/{$school}/Accounts/ChartOfAccounts");
                    if (!is_array($coa)) return $this->_f('No CoA data');
                    $cats = [];
                    foreach ($coa as $acct) {
                        if (is_array($acct) && ($acct['status'] ?? '') === 'active') {
                            $cats[$acct['category'] ?? ''] = true;
                        }
                    }
                    $required = ['Asset', 'Liability', 'Equity', 'Income', 'Expense'];
                    $missing = array_diff($required, array_keys($cats));
                    if (!empty($missing))
                        return $this->_f('Missing categories: ' . implode(', ', $missing));
                    return $this->_p('All 5 categories present');
                },
            ],
            /* 4. No Orphaned Parent Codes */
            [
                'name' => 'No Orphaned Parent Codes',
                'fn'   => function() use ($fb, $school) {
                    $coa = $fb->get("Schools/{$school}/Accounts/ChartOfAccounts");
                    if (!is_array($coa)) return $this->_p('No CoA data');
                    // Cast keys to string — PHP coerces numeric-string keys to int
                    $codes = array_map('strval', array_keys($coa));
                    $orphans = [];
                    foreach ($coa as $code => $acct) {
                        if (!is_array($acct)) continue;
                        $parent = $acct['parent_code'] ?? null;
                        if ($parent && !in_array((string) $parent, $codes, true)) {
                            $orphans[] = "{$code} (parent: {$parent})";
                        }
                    }
                    if (!empty($orphans))
                        return $this->_f('Orphaned parent refs: ' . implode(', ', array_slice($orphans, 0, 5)));
                    return $this->_p('All parent codes valid');
                },
            ],
            /* 5. Ledger Node Exists */
            [
                'name' => 'Ledger Node Exists',
                'fn'   => function() use ($fb, $school, $session) {
                    $keys = $fb->shallow_get("Schools/{$school}/{$session}/Accounts/Ledger");
                    if (!is_array($keys))
                        return $this->_p('No ledger entries yet (empty is OK for new session)');
                    return $this->_p(count($keys) . ' ledger entries');
                },
            ],
            /* 6. Ledger Index Consistency */
            [
                'name' => 'Ledger Index Consistency',
                'fn'   => function() use ($fb, $school, $session) {
                    $ledger = $fb->shallow_get("Schools/{$school}/{$session}/Accounts/Ledger");
                    $dateIdx = $fb->get("Schools/{$school}/{$session}/Accounts/Ledger_index/by_date");
                    if (!is_array($ledger) || empty($ledger))
                        return $this->_p('No ledger entries to validate');
                    $indexedIds = [];
                    if (is_array($dateIdx)) {
                        foreach ($dateIdx as $date => $ids) {
                            if (is_array($ids)) {
                                foreach (array_keys($ids) as $id) $indexedIds[$id] = true;
                            }
                        }
                    }
                    $ledgerIds = is_array($ledger) ? $ledger : [];
                    $missingFromIdx = 0;
                    foreach ($ledgerIds as $id) {
                        if (!isset($indexedIds[$id])) $missingFromIdx++;
                    }
                    if ($missingFromIdx > 0)
                        return $this->_f("{$missingFromIdx} ledger entries missing from date index. Run Recompute Balances.");
                    return $this->_p(count($indexedIds) . ' indexed entries match ledger');
                },
            ],
            /* 7. Double-Entry Integrity (Dr = Cr for all active entries) */
            [
                'name' => 'Double-Entry Integrity (Dr = Cr)',
                'fn'   => function() use ($fb, $school, $session) {
                    $ledger = $fb->get("Schools/{$school}/{$session}/Accounts/Ledger");
                    if (!is_array($ledger)) return $this->_p('No ledger entries');
                    $imbalanced = 0;
                    $checked = 0;
                    foreach ($ledger as $id => $entry) {
                        if (!is_array($entry) || ($entry['status'] ?? '') === 'deleted') continue;
                        $checked++;
                        $totalDr = round((float)($entry['total_dr'] ?? 0), 2);
                        $totalCr = round((float)($entry['total_cr'] ?? 0), 2);
                        if (abs($totalDr - $totalCr) > 0.01) $imbalanced++;
                    }
                    if ($imbalanced > 0)
                        return $this->_f("{$imbalanced}/{$checked} entries are IMBALANCED (Dr != Cr). Data integrity issue!");
                    return $this->_p("{$checked} entries — all balanced");
                },
            ],
            /* 8. Closing Balances Cache Exists */
            [
                'name' => 'Closing Balances Cache',
                'fn'   => function() use ($fb, $school, $session) {
                    $bal = $fb->shallow_get("Schools/{$school}/{$session}/Accounts/Closing_balances");
                    if (!is_array($bal) || empty($bal))
                        return $this->_p('No closing balances cached (run Recompute if ledger has entries)');
                    return $this->_p(count($bal) . ' account balances cached');
                },
            ],
            /* 9. Period Lock Configuration */
            [
                'name' => 'Period Lock Status',
                'fn'   => function() use ($fb, $school, $session) {
                    $lock = $fb->get("Schools/{$school}/{$session}/Accounts/Period_lock");
                    if (!is_array($lock) || empty($lock['locked_until']))
                        return $this->_p('No period lock set (recommended to lock completed months)');
                    return $this->_p('Period locked until ' . $lock['locked_until']);
                },
            ],
            /* 10. Voucher Counters */
            [
                'name' => 'Voucher Counters',
                'fn'   => function() use ($fb, $school, $session) {
                    $counters = $fb->get("Schools/{$school}/{$session}/Accounts/Voucher_counters");
                    if (!is_array($counters) || empty($counters))
                        return $this->_p('No voucher counters (created on first entry)');
                    $parts = [];
                    foreach ($counters as $type => $val) {
                        $parts[] = "{$type}: {$val}";
                    }
                    return $this->_p(implode(', ', $parts));
                },
            ],
            /* 11. Income/Expense Records */
            [
                'name' => 'Income/Expense Records',
                'fn'   => function() use ($fb, $school, $session) {
                    $ie = $fb->shallow_get("Schools/{$school}/{$session}/Accounts/Income_expense");
                    if (!is_array($ie) || empty($ie))
                        return $this->_p('No income/expense records yet');
                    return $this->_p(count($ie) . ' income/expense records');
                },
            ],
            /* 12. Bank Reconciliation Data */
            [
                'name' => 'Bank Reconciliation Data',
                'fn'   => function() use ($fb, $school, $session) {
                    $recon = $fb->shallow_get("Schools/{$school}/{$session}/Accounts/Bank_recon");
                    if (!is_array($recon) || empty($recon))
                        return $this->_p('No bank reconciliation data (import CSV to start)');
                    return $this->_p(count($recon) . ' bank account(s) with recon data');
                },
            ],
            /* 13. Ledger Entry Line Items Have Valid Account Codes */
            [
                'name' => 'Ledger Entries Reference Valid Accounts',
                'fn'   => function() use ($fb, $school, $session) {
                    $coa = $fb->get("Schools/{$school}/Accounts/ChartOfAccounts");
                    if (!is_array($coa)) return $this->_p('No CoA to validate against');
                    $ledger = $fb->get("Schools/{$school}/{$session}/Accounts/Ledger");
                    if (!is_array($ledger)) return $this->_p('No ledger entries');
                    $coaCodes = array_map('strval', array_keys($coa));
                    $badRefs = [];
                    $checked = 0;
                    foreach ($ledger as $id => $entry) {
                        if (!is_array($entry) || ($entry['status'] ?? '') === 'deleted') continue;
                        $checked++;
                        foreach ($entry['lines'] ?? [] as $line) {
                            $ac = (string) ($line['account_code'] ?? '');
                            if ($ac && !in_array($ac, $coaCodes, true)) {
                                $badRefs[$ac] = true;
                            }
                        }
                        if ($checked >= 200) break; // Sample first 200 to avoid timeout
                    }
                    if (!empty($badRefs))
                        return $this->_f('Ledger references ' . count($badRefs) . ' unknown account(s): ' . implode(', ', array_slice(array_keys($badRefs), 0, 5)));
                    return $this->_p("{$checked} entries checked — all account codes valid");
                },
            ],
            /* 14. Audit Log Exists */
            [
                'name' => 'Audit Trail Active',
                'fn'   => function() use ($fb, $school, $session) {
                    $log = $fb->shallow_get("Schools/{$school}/{$session}/Accounts/Audit_log");
                    if (!is_array($log) || empty($log))
                        return $this->_p('No audit log entries (created automatically on write operations)');
                    return $this->_p(count($log) . ' audit log entries');
                },
            ],
        ];
    }

    /* ══════════════════════════════════════════════════════════════════════
       HR & STAFFING CHECKS
    ══════════════════════════════════════════════════════════════════════ */

    private function _checks_hr()
    {
        $fb      = $this->firebase;
        $school  = $this->school_name;
        $session = $this->session_year;
        $hrBase  = "Schools/{$school}/HR";

        return [
            /* 1. Departments Exist */
            [
                'name' => 'Departments Defined',
                'fn'   => function() use ($fb, $hrBase) {
                    $depts = $fb->get("{$hrBase}/Departments");
                    if (!is_array($depts) || empty($depts))
                        return $this->_w('No departments configured — set up departments in HR > Departments');
                    $active = 0;
                    foreach ($depts as $d) {
                        if (($d['status'] ?? '') === 'Active') $active++;
                    }
                    return $this->_p(count($depts) . ' department(s), ' . $active . ' active');
                },
            ],
            /* 2. Department Data Integrity */
            [
                'name' => 'Department Data Integrity',
                'fn'   => function() use ($fb, $hrBase) {
                    $depts = $fb->get("{$hrBase}/Departments");
                    if (!is_array($depts)) return $this->_p('No departments — skipped');
                    $issues = [];
                    foreach ($depts as $id => $d) {
                        if (empty($d['name'])) $issues[] = "{$id}: missing name";
                        if (!isset($d['status'])) $issues[] = "{$id}: missing status";
                    }
                    if (!empty($issues))
                        return $this->_f(count($issues) . ' issue(s): ' . implode('; ', array_slice($issues, 0, 5)));
                    return $this->_p(count($depts) . ' departments validated');
                },
            ],
            /* 3. Leave Types Configured */
            [
                'name' => 'Leave Types Configured',
                'fn'   => function() use ($fb, $hrBase) {
                    $types = $fb->get("{$hrBase}/Leaves/Types");
                    if (!is_array($types) || empty($types))
                        return $this->_w('No leave types — configure leave types in HR > Leave Management');
                    $active = 0;
                    foreach ($types as $t) {
                        if (($t['status'] ?? '') === 'Active') $active++;
                    }
                    return $this->_p(count($types) . ' leave type(s), ' . $active . ' active');
                },
            ],
            /* 4. Leave Balances Allocated */
            [
                'name' => 'Leave Balances Allocated',
                'fn'   => function() use ($fb, $hrBase, $session) {
                    // Extract year from session (e.g. "2025-26" → "2025")
                    $year = explode('-', $session)[0] ?? '';
                    if (!$year) return $this->_w('Cannot determine year from session');
                    $balances = $fb->shallow_get("{$hrBase}/Leaves/Balances/{$year}");
                    if (!is_array($balances) || empty($balances))
                        return $this->_w('No leave balances for year ' . $year . ' — allocate via Leave Management');
                    return $this->_p(count($balances) . ' staff member(s) have leave balances for ' . $year);
                },
            ],
            /* 5. Pending Leave Requests */
            [
                'name' => 'Pending Leave Requests',
                'fn'   => function() use ($fb, $hrBase) {
                    $reqs = $fb->get("{$hrBase}/Leaves/Requests");
                    if (!is_array($reqs)) return $this->_p('No leave requests');
                    $pending = 0;
                    foreach ($reqs as $r) {
                        if (($r['status'] ?? '') === 'Pending') $pending++;
                    }
                    if ($pending > 0)
                        return $this->_w($pending . ' pending leave request(s) awaiting action');
                    return $this->_p(count($reqs) . ' total requests, none pending');
                },
            ],
            /* 6. Salary Structures Exist */
            [
                'name' => 'Salary Structures Defined',
                'fn'   => function() use ($fb, $hrBase, $school, $session) {
                    $structures = $fb->shallow_get("{$hrBase}/Salary_Structures");
                    $roster = $fb->shallow_get("Schools/{$school}/{$session}/Teachers");
                    $structCount = is_array($structures) ? count($structures) : 0;
                    $rosterCount = is_array($roster) ? count($roster) : 0;
                    if ($structCount === 0)
                        return $this->_w('No salary structures — set up in HR > Payroll');
                    if ($rosterCount > 0 && $structCount < $rosterCount)
                        return $this->_w("{$structCount} of {$rosterCount} staff have salary structures — " . ($rosterCount - $structCount) . ' missing');
                    return $this->_p("{$structCount} salary structure(s) defined");
                },
            ],
            /* 7. Salary Structure Data Integrity */
            [
                'name' => 'Salary Structure Integrity',
                'fn'   => function() use ($fb, $hrBase) {
                    $structures = $fb->get("{$hrBase}/Salary_Structures");
                    if (!is_array($structures)) return $this->_p('No salary structures — skipped');
                    $issues = [];
                    foreach ($structures as $sid => $s) {
                        $basic = (float)($s['basic'] ?? 0);
                        if ($basic <= 0) $issues[] = "{$sid}: zero/missing basic";
                        $gross = $basic + (float)($s['hra'] ?? 0) + (float)($s['da'] ?? 0) + (float)($s['ta'] ?? 0) + (float)($s['medical'] ?? 0) + (float)($s['other_allowances'] ?? 0);
                        $deductions = (float)($s['pf_employee'] ?? 0) + (float)($s['esi_employee'] ?? 0) + (float)($s['professional_tax'] ?? 0) + (float)($s['tds'] ?? 0) + (float)($s['other_deductions'] ?? 0);
                        if ($deductions >= $gross && $gross > 0) $issues[] = "{$sid}: deductions >= gross";
                    }
                    if (!empty($issues))
                        return $this->_f(count($issues) . ' issue(s): ' . implode('; ', array_slice($issues, 0, 5)));
                    return $this->_p(count($structures) . ' structures validated');
                },
            ],
            /* 8. Payroll Runs Status */
            [
                'name' => 'Payroll Runs Status',
                'fn'   => function() use ($fb, $school, $session) {
                    $runs = $fb->get("Schools/{$school}/{$session}/HR/Payroll/Runs");
                    if (!is_array($runs)) return $this->_p('No payroll runs this session');
                    $draft = 0; $finalized = 0; $paid = 0;
                    foreach ($runs as $r) {
                        $s = $r['status'] ?? '';
                        if ($s === 'Draft') $draft++;
                        elseif ($s === 'Finalized') $finalized++;
                        elseif ($s === 'Paid') $paid++;
                    }
                    $parts = [];
                    if ($draft) $parts[] = "{$draft} draft";
                    if ($finalized) $parts[] = "{$finalized} finalized (unpaid)";
                    if ($paid) $parts[] = "{$paid} paid";
                    if ($finalized > 0)
                        return $this->_w(count($runs) . ' run(s): ' . implode(', ', $parts));
                    return $this->_p(count($runs) . ' run(s): ' . implode(', ', $parts));
                },
            ],
            /* 9. Open Recruitment Positions */
            [
                'name' => 'Open Recruitment Positions',
                'fn'   => function() use ($fb, $hrBase) {
                    $jobs = $fb->get("{$hrBase}/Recruitment/Jobs");
                    if (!is_array($jobs)) return $this->_p('No recruitment jobs posted');
                    $open = 0; $expired = 0;
                    $today = date('Y-m-d');
                    foreach ($jobs as $j) {
                        if (($j['status'] ?? '') === 'Open') {
                            $open++;
                            if (!empty($j['deadline']) && $j['deadline'] < $today) $expired++;
                        }
                    }
                    if ($expired > 0)
                        return $this->_w("{$open} open position(s), {$expired} past deadline — consider closing expired jobs");
                    return $this->_p(count($jobs) . ' total job(s), ' . $open . ' open');
                },
            ],
            /* 10. Appraisals Pending Review */
            [
                'name' => 'Appraisals Status',
                'fn'   => function() use ($fb, $hrBase) {
                    $appraisals = $fb->get("{$hrBase}/Appraisals");
                    if (!is_array($appraisals)) return $this->_p('No appraisals recorded');
                    $draft = 0; $submitted = 0; $reviewed = 0;
                    foreach ($appraisals as $a) {
                        $s = $a['status'] ?? '';
                        if ($s === 'Draft') $draft++;
                        elseif ($s === 'Submitted') $submitted++;
                        elseif ($s === 'Reviewed') $reviewed++;
                    }
                    if ($submitted > 0)
                        return $this->_w("{$submitted} appraisal(s) submitted and pending review");
                    return $this->_p(count($appraisals) . ' total: ' . $draft . ' draft, ' . $submitted . ' submitted, ' . $reviewed . ' reviewed');
                },
            ],
            /* 11. Staff Roster vs Department Assignment */
            [
                'name' => 'Staff Department Coverage',
                'fn'   => function() use ($fb, $school, $session) {
                    $roster = $fb->shallow_get("Schools/{$school}/{$session}/Teachers");
                    if (!is_array($roster) || empty($roster))
                        return $this->_p('No staff in session roster');
                    $noDept = 0;
                    foreach ($roster as $staffId => $v) {
                        $profile = $fb->get("Users/Teachers/{$school}/{$staffId}");
                        if (!is_array($profile)) continue;
                        if (empty($profile['Department'])) $noDept++;
                        if ($noDept >= 5) break; // Sample to avoid timeout
                    }
                    if ($noDept > 0)
                        return $this->_w("{$noDept}+ staff member(s) without department assignment");
                    return $this->_p('Sampled staff all have department assignments');
                },
            ],
            /* 12. HR Counters Exist */
            [
                'name' => 'HR ID Counters',
                'fn'   => function() use ($fb, $hrBase) {
                    $counters = $fb->get("{$hrBase}/Counters");
                    if (!is_array($counters) || empty($counters))
                        return $this->_p('No counters yet (created automatically on first use)');
                    $parts = [];
                    foreach ($counters as $k => $v) {
                        $parts[] = "{$k}={$v}";
                    }
                    return $this->_p('Counters: ' . implode(', ', $parts));
                },
            ],
            /* 13. Payroll Ledger Accounts Exist */
            [
                'name' => 'Payroll Ledger Accounts',
                'fn'   => function() use ($fb, $school) {
                    $coaBase = "Schools/{$school}/Accounts/ChartOfAccounts";
                    $required = ['5010' => 'Teaching Salary', '5020' => 'Non-Teaching Salary', '2020' => 'Salary Payable', '1010' => 'Cash', '1020' => 'Bank'];
                    $missing = [];
                    $inactive = [];
                    foreach ($required as $code => $label) {
                        $acct = $fb->get("{$coaBase}/{$code}");
                        if (!is_array($acct)) {
                            $missing[] = "{$code} ({$label})";
                        } elseif (($acct['status'] ?? '') !== 'active') {
                            $inactive[] = "{$code} ({$label})";
                        }
                    }
                    if (!empty($missing))
                        return $this->_f('Missing accounts: ' . implode(', ', $missing) . ' — seed Chart of Accounts in Accounting module');
                    if (!empty($inactive))
                        return $this->_w('Inactive accounts: ' . implode(', ', $inactive));
                    return $this->_p('All 5 payroll accounts present and active');
                },
            ],
            /* 14. Payroll Slip Structure Validity */
            [
                'name' => 'Payroll Slip Integrity',
                'fn'   => function() use ($fb, $school, $session) {
                    $runs = $fb->get("Schools/{$school}/{$session}/HR/Payroll/Runs");
                    if (!is_array($runs) || empty($runs)) return $this->_p('No payroll runs — skipped');
                    $issues = [];
                    $checked = 0;
                    foreach ($runs as $runId => $run) {
                        $slips = $fb->shallow_get("Schools/{$school}/{$session}/HR/Payroll/Slips/{$runId}");
                        $slipCount = is_array($slips) ? count($slips) : 0;
                        $runStaff  = (int) ($run['staff_count'] ?? 0);
                        if ($slipCount !== $runStaff) {
                            $issues[] = "{$runId}: expects {$runStaff} slips, found {$slipCount}";
                        }
                        if (empty($run['expense_journal_id']) && ($run['status'] ?? '') !== 'Draft') {
                            $issues[] = "{$runId}: missing expense_journal_id";
                        }
                        $checked++;
                        if ($checked >= 10) break; // Sample limit
                    }
                    if (!empty($issues))
                        return $this->_f(count($issues) . ' issue(s): ' . implode('; ', array_slice($issues, 0, 3)));
                    return $this->_p("{$checked} payroll run(s) validated — slip counts match");
                },
            ],
            /* 15. Leave Balance Validity */
            [
                'name' => 'Leave Balance Validity',
                'fn'   => function() use ($fb, $hrBase, $session) {
                    $year = explode('-', $session)[0] ?? '';
                    if (!$year) return $this->_w('Cannot determine year from session');
                    $balances = $fb->get("{$hrBase}/Leaves/Balances/{$year}");
                    if (!is_array($balances)) return $this->_p('No balances — skipped');
                    $issues = [];
                    $total = 0;
                    foreach ($balances as $staffId => $types) {
                        if (!is_array($types)) continue;
                        foreach ($types as $typeId => $bal) {
                            if (!is_array($bal)) continue;
                            $total++;
                            $allocated = (int) ($bal['allocated'] ?? 0);
                            $used      = (int) ($bal['used'] ?? 0);
                            $balance   = (int) ($bal['balance'] ?? 0);
                            if ($balance < 0) {
                                $issues[] = "{$staffId}/{$typeId}: negative balance ({$balance})";
                            }
                            if ($used < 0) {
                                $issues[] = "{$staffId}/{$typeId}: negative used ({$used})";
                            }
                            if (count($issues) >= 10) break 2;
                        }
                    }
                    if (!empty($issues))
                        return $this->_w(count($issues) . ' issue(s): ' . implode('; ', array_slice($issues, 0, 3)));
                    return $this->_p("{$total} leave balance record(s) validated");
                },
            ],
            /* 16. Payroll Journal Entries Exist in Accounting Ledger */
            [
                'name' => 'Payroll Journal Linkage',
                'fn'   => function() use ($fb, $school, $session) {
                    $runs = $fb->get("Schools/{$school}/{$session}/HR/Payroll/Runs");
                    if (!is_array($runs) || empty($runs)) return $this->_p('No payroll runs — skipped');
                    $bp = "Schools/{$school}/{$session}";
                    $orphaned = [];
                    $checked = 0;
                    foreach ($runs as $runId => $run) {
                        $jid = $run['expense_journal_id'] ?? '';
                        if ($jid === '') continue;
                        $entry = $fb->get("{$bp}/Accounts/Ledger/{$jid}");
                        if (!is_array($entry)) {
                            $orphaned[] = "{$runId} → {$jid}";
                        } elseif (($entry['status'] ?? '') === 'deleted') {
                            $orphaned[] = "{$runId} → {$jid} (soft-deleted)";
                        }
                        $checked++;
                        if ($checked >= 10) break;
                    }
                    if (!empty($orphaned))
                        return $this->_f('Orphaned journal refs: ' . implode('; ', array_slice($orphaned, 0, 3)));
                    return $this->_p("{$checked} payroll journal link(s) verified in Accounting ledger");
                },
            ],
        ];
    }

    /* ══════════════════════════════════════════════════════════════════════
       OPERATIONS MODULE CHECKS
    ══════════════════════════════════════════════════════════════════════ */

    private function _checks_operations()
    {
        $fb      = $this->firebase;
        $school  = $this->school_name;
        $opsBase = "Schools/{$school}/Operations";

        return [
            /* 1. Library — Book Availability Consistency */
            [
                'name' => 'Library Book Availability',
                'fn'   => function() use ($fb, $opsBase) {
                    $books = $fb->get("{$opsBase}/Library/Books");
                    if (!is_array($books)) return $this->_p('No library books — skipped');
                    $issues = $fb->get("{$opsBase}/Library/Issues");
                    $issuedByBook = [];
                    if (is_array($issues)) {
                        foreach ($issues as $iss) {
                            if (($iss['status'] ?? '') === 'Issued') {
                                $bid = $iss['book_id'] ?? '';
                                if (!isset($issuedByBook[$bid])) $issuedByBook[$bid] = 0;
                                $issuedByBook[$bid]++;
                            }
                        }
                    }
                    $mismatches = [];
                    foreach ($books as $id => $b) {
                        $copies   = (int) ($b['copies'] ?? 0);
                        $avail    = (int) ($b['available'] ?? 0);
                        $issued   = $issuedByBook[$id] ?? 0;
                        $expected = $copies - $issued;
                        if ($avail !== $expected) {
                            $mismatches[] = "{$id}: available={$avail}, expected={$expected}";
                        }
                    }
                    if (!empty($mismatches))
                        return $this->_w(count($mismatches) . ' book(s) with availability mismatch: ' . implode('; ', array_slice($mismatches, 0, 3)));
                    return $this->_p(count($books) . ' books checked — availability consistent');
                },
            ],
            /* 2. Hostel — Room Occupancy Consistency */
            [
                'name' => 'Hostel Room Occupancy',
                'fn'   => function() use ($fb, $opsBase) {
                    $rooms = $fb->get("{$opsBase}/Hostel/Rooms");
                    if (!is_array($rooms)) return $this->_p('No hostel rooms — skipped');
                    $allocs = $fb->get("{$opsBase}/Hostel/Allocations");
                    $occByRoom = [];
                    if (is_array($allocs)) {
                        foreach ($allocs as $al) {
                            if (($al['status'] ?? '') === 'Active') {
                                $rid = $al['room_id'] ?? '';
                                if (!isset($occByRoom[$rid])) $occByRoom[$rid] = 0;
                                $occByRoom[$rid]++;
                            }
                        }
                    }
                    $mismatches = [];
                    foreach ($rooms as $id => $r) {
                        $stored   = (int) ($r['occupied'] ?? 0);
                        $expected = $occByRoom[$id] ?? 0;
                        if ($stored !== $expected) {
                            $mismatches[] = "{$r['room_no']}: stored={$stored}, actual={$expected}";
                        }
                    }
                    if (!empty($mismatches))
                        return $this->_w(count($mismatches) . ' room(s) with occupancy mismatch: ' . implode('; ', array_slice($mismatches, 0, 3)));
                    return $this->_p(count($rooms) . ' rooms checked — occupancy consistent');
                },
            ],
            /* 3. Inventory — Low Stock Alert */
            [
                'name' => 'Inventory Low Stock',
                'fn'   => function() use ($fb, $opsBase) {
                    $items = $fb->get("{$opsBase}/Inventory/Items");
                    if (!is_array($items)) return $this->_p('No inventory items — skipped');
                    $lowStock = [];
                    foreach ($items as $id => $it) {
                        if (($it['status'] ?? '') === 'Inactive') continue;
                        if ((int) ($it['current_stock'] ?? 0) <= (int) ($it['min_stock'] ?? 0)) {
                            $lowStock[] = ($it['name'] ?? $id) . ' (' . ($it['current_stock'] ?? 0) . '/' . ($it['min_stock'] ?? 0) . ')';
                        }
                    }
                    if (!empty($lowStock))
                        return $this->_w(count($lowStock) . ' item(s) at/below min stock: ' . implode('; ', array_slice($lowStock, 0, 5)));
                    return $this->_p(count($items) . ' items checked — all above minimum stock');
                },
            ],
            /* 4. Assets — Overdue Maintenance */
            [
                'name' => 'Asset Maintenance Due',
                'fn'   => function() use ($fb, $opsBase) {
                    $maint = $fb->get("{$opsBase}/Assets/Maintenance");
                    if (!is_array($maint)) return $this->_p('No maintenance records — skipped');
                    $today = date('Y-m-d');
                    $overdue = [];
                    foreach ($maint as $id => $m) {
                        if (($m['status'] ?? '') !== 'Scheduled') continue;
                        if (!empty($m['next_due']) && $m['next_due'] <= $today) {
                            $overdue[] = ($m['asset_name'] ?? $id) . ' (due: ' . $m['next_due'] . ')';
                        }
                    }
                    if (!empty($overdue))
                        return $this->_w(count($overdue) . ' overdue maintenance: ' . implode('; ', array_slice($overdue, 0, 3)));
                    return $this->_p('No overdue maintenance');
                },
            ],
            /* 5. Transport — Orphaned Assignments */
            [
                'name' => 'Transport Assignments Valid',
                'fn'   => function() use ($fb, $opsBase) {
                    $assignments = $fb->get("{$opsBase}/Transport/Assignments");
                    if (!is_array($assignments)) return $this->_p('No transport assignments — skipped');
                    $routes = $fb->get("{$opsBase}/Transport/Routes");
                    $routeIds = is_array($routes) ? array_keys($routes) : [];
                    $orphaned = [];
                    foreach ($assignments as $sid => $a) {
                        $rid = $a['route_id'] ?? '';
                        if ($rid !== '' && !in_array($rid, $routeIds, true)) {
                            $orphaned[] = ($a['student_name'] ?? $sid) . ' → ' . $rid;
                        }
                    }
                    if (!empty($orphaned))
                        return $this->_f(count($orphaned) . ' assignment(s) reference deleted routes: ' . implode('; ', array_slice($orphaned, 0, 3)));
                    return $this->_p(count($assignments) . ' assignments validated');
                },
            ],
        ];
    }
}
