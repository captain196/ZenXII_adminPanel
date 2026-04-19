<?php
defined('BASEPATH') or exit('No direct script access allowed');

class Classes extends MY_Controller
{
    private const MANAGE_ROLES = ['Super Admin', 'School Super Admin', 'Admin', 'Principal', 'Vice Principal', 'Academic Coordinator'];
    private const VIEW_ROLES   = ['Super Admin', 'School Super Admin', 'Admin', 'Principal', 'Vice Principal', 'Academic Coordinator', 'Class Teacher', 'Teacher'];

    public function __construct()
    {
        parent::__construct();
        require_permission('Configuration');

        $this->load->library('Fee_lifecycle', null, 'feeLifecycle');
        $this->feeLifecycle->init($this->firebase, $this->school_name, $this->session_year, $this->admin_id ?? 'system');
    }


    public function ensure_class_exists()
    {
        $this->_require_role(self::MANAGE_ROLES);

        $class_label = $this->safe_path_segment(
            $this->input->post('class_name'),
            'class_name'
        );

        // In Firestore, classes are implicit from sections — no separate node needed.
        // Just verify at least one section exists for this class, or return success.
        $this->json_success([
            'class' => $class_label
        ]);
    }

    public function manage_classes()
    {
        $this->_require_role(self::VIEW_ROLES);
        $school_id    = $this->parent_db_key;
        $school_name  = $this->school_name;
        $session_year = $this->session_year;


        $this->load->view('include/header');
        $this->load->view('manage_classes');
        $this->load->view('include/footer');
    }





    public function section_students($class_slug, $section_slug)
    {
        $this->_require_role(self::VIEW_ROLES);
        $class = urldecode($class_slug);

        // Numeric class → add prefix
        if (is_numeric($class) || preg_match('/^\d+/', $class)) {
            $data['class_name'] = 'Class ' . $class;
        } else {
            // Nursery / LKG / UKG
            $data['class_name'] = $class;
        }

        $data['section_name'] = 'Section ' . urldecode($section_slug);

        $this->load->view('include/header');
        $this->load->view('section_students', $data);
        $this->load->view('include/footer');
    }

    public function fetch_classes_grid()
    {
        $this->_require_role(self::VIEW_ROLES);
        header('Content-Type: application/json');

        // Query all sections for this school from Firestore
        $sectionDocs = $this->fs->schoolWhere('sections', []);

        // Firestore is the sole source per no-RTDB policy.

        $classSet = [];
        foreach ($sectionDocs as $doc) {
            $name = $doc['data']['className'] ?? '';
            if ($name === '' || isset($classSet[$name])) continue;

            if (preg_match('/^Class\s+(Nursery|LKG|UKG)$/i', $name, $m)) {
                $label = ucfirst(strtolower($m[1]));
            } else {
                $label = $name;
            }

            $classSet[$name] = ['key' => $name, 'label' => $label];
        }

        echo json_encode(array_values($classSet));
    }



    public function fetch_class_sections()
    {
        $this->_require_role(self::VIEW_ROLES);
        header('Content-Type: application/json');

        $class_name = trim((string) $this->input->post('class_name'));

        if (!$class_name) {
            echo json_encode([]);
            return;
        }

        // Query sections for this class from Firestore
        $classKey = Firestore_service::classKey($class_name);
        $sectionDocs = $this->fs->schoolWhere('sections', [['className', '==', $classKey]]);

        // RTDB fallback + auto-heal if Firestore is empty
        if (empty($sectionDocs)) {
            $sectionDocs = []; // Firestore-only; no RTDB fallback
        }

        if (empty($sectionDocs)) {
            echo json_encode([]);
            return;
        }

        $sections = [];
        foreach ($sectionDocs as $doc) {
            $sd = $doc['data'];
            $sectionName = $sd['section'] ?? '';

            // Count students in this section from students collection
            $sectionPrefixed = Firestore_service::sectionKey($sectionName);
            $classPrefixed   = Firestore_service::classKey($classKey);
            $studentDocs = $this->fs->schoolWhere('students', [
                ['Class', '==', $classPrefixed],
                ['Section', '==', $sectionPrefixed],
                ['Status', '==', 'Active'],
            ]);

            // Student fallback if Firestore students also empty
            if (empty($studentDocs)) {
                $studentDocs = []; // Firestore-only; no RTDB fallback
            }

            $students = [];
            foreach ($studentDocs as $sDocs) {
                $st = $sDocs['data'];
                $students[] = [
                    'id'   => $st['User ID'] ?? $st['studentId'] ?? $sDocs['id'],
                    'name' => $st['Name'] ?? $st['name'] ?? '',
                ];
            }

            $sections[] = [
                'name'         => $sectionName,
                'strength'     => count($students),
                'max_strength' => (int) ($sd['maxStrength'] ?? $sd['max_strength'] ?? 0),
                'students'     => $students,
            ];
        }

        echo json_encode($sections);
    }

    public function add_section()
    {
        $this->_require_role(self::MANAGE_ROLES);
        header('Content-Type: application/json');

        $class_name   = trim((string) $this->input->post('class_name'));
        $section_name = trim((string) $this->input->post('section_name'));
        $max_strength = (int) $this->input->post('max_strength');

        if (!$class_name || !$section_name || $max_strength <= 0) {
            echo json_encode(['status' => 'error', 'message' => 'Invalid input']);
            return;
        }

        if (stripos($section_name, 'Section') !== 0) {
            $section_name = 'Section ' . strtoupper($section_name);
        }

        $classKey = Firestore_service::classKey($class_name);
        $sectionDocId = $this->fs->schoolId() . '_' . $classKey . '_' . $section_name;

        // Prevent overwrite
        $existing = $this->fs->get('sections', $sectionDocId);
        if ($existing) {
            echo json_encode(['status' => 'error', 'message' => 'Section already exists']);
            return;
        }

        $this->fs->set('sections', $sectionDocId, [
            'schoolId'     => $this->school_id,
            'className'    => $classKey,
            'section'      => $section_name,
            'maxStrength'  => $max_strength,
            'session'      => $this->session_year,
            'updatedAt'    => date('c'),
        ]);

        // RTDB mirror removed per no-RTDB policy.

        echo json_encode([
            'status'        => 'success',
            'section'       => $section_name,
            'max_strength'  => $max_strength,
        ]);
    }

    public function fetch_sections_list()
    {
        $this->_require_role(self::VIEW_ROLES);
        header('Content-Type: application/json');

        $class = trim((string) $this->input->post('class_name'));
        if (!$class) {
            echo json_encode([]);
            return;
        }

        $classKey = Firestore_service::classKey($class);
        $sectionDocs = $this->fs->schoolWhere('sections', [['className', '==', $classKey]]);

        // RTDB fallback + auto-heal
        if (empty($sectionDocs)) {
            $sectionDocs = []; // Firestore-only; no RTDB fallback
        }

        $sections = [];
        foreach ($sectionDocs as $doc) {
            $sName = $doc['data']['section'] ?? '';
            if (preg_match('/^Section\s+[A-Z]$/', $sName)) {
                $sections[] = $sName;
            }
        }

        sort($sections);
        echo json_encode($sections);
    }

    public function get_class_details()
    {
        $this->_require_role(self::VIEW_ROLES);
        header('Content-Type: application/json');

        // Get distinct class names from sections collection
        $sectionDocs = $this->fs->schoolWhere('sections', []);

        // RTDB fallback + auto-heal
        if (empty($sectionDocs)) {
            $sectionDocs = $this->_rtdb_fallback_all_sections();
        }

        $classSet = [];
        foreach ($sectionDocs as $doc) {
            $className = $doc['data']['className'] ?? '';
            if ($className === '') continue;
            // Use prefixed format for the value (e.g. "Class 9th")
            $prefixed = Firestore_service::classKey($className);
            $classSet[$prefixed] = true;
        }

        $classes = [];
        foreach (array_keys($classSet) as $name) {
            // Display label strips prefix; value stays prefixed
            $displayName = str_replace('Class ', '', $name);
            if (preg_match('/^(Nursery|LKG|UKG)$/i', $displayName)) {
                $classes[] = ['value' => $name, 'label' => ucfirst(strtolower($displayName))];
            } elseif (is_numeric($displayName)) {
                $classes[] = ['value' => $name, 'label' => $this->format_class_name($displayName)];
            } else {
                $classes[] = ['value' => $name, 'label' => $name];
            }
        }

        usort($classes, function ($a, $b) {
            $order = ['class nursery' => 0, 'class lkg' => 1, 'class ukg' => 2];
            $aVal = strtolower($a['value']);
            $bVal = strtolower($b['value']);
            if (isset($order[$aVal]) && isset($order[$bVal])) return $order[$aVal] <=> $order[$bVal];
            if (isset($order[$aVal])) return -1;
            if (isset($order[$bVal])) return 1;
            // Extract numeric portion for sorting (e.g. "Class 9th" → 9)
            $aNum = (int) preg_replace('/\D/', '', $aVal);
            $bNum = (int) preg_replace('/\D/', '', $bVal);
            return $aNum <=> $bNum;
        });

        echo json_encode(array_values($classes));
    }




    public function view($class_slug = null)
    {
        $this->_require_role(self::VIEW_ROLES);
        if (!$class_slug) {
            show_404();
            return;
        }

        // Convert slug to Firebase key
        // 8th → Class 8th
        $class_name = 'Class ' . urldecode($class_slug);

        $data['class_name'] = $class_name;

        $this->load->view('include/header');
        $this->load->view('class_profile', $data);
        $this->load->view('include/footer');
    }


    public function fetch_section_students()
    {
        $this->_require_role(self::VIEW_ROLES);
        header('Content-Type: application/json');

        $class   = trim((string) $this->input->post('class_name'));
        $section = trim((string) $this->input->post('section_name'));

        if (!$class || !$section) {
            echo json_encode([]);
            return;
        }

        // Use prefixed format for queries
        $classPrefixed   = Firestore_service::classKey($class);
        $sectionPrefixed = Firestore_service::sectionKey($section);

        // Query students from Firestore by class + section
        $studentDocs = $this->fs->schoolWhere('students', [
            ['Class', '==', $classPrefixed],
            ['Section', '==', $sectionPrefixed],
            ['Status', '==', 'Active'],
        ], 'Name', 'ASC');

        // RTDB fallback + auto-heal
        if (empty($studentDocs)) {
            $studentDocs = $this->_rtdb_fallback_students($classPrefixed, $sectionPrefixed);
        }

        $students = [];
        foreach ($studentDocs as $doc) {
            $profile = $doc['data'];
            $stuId   = $profile['User ID'] ?? $profile['studentId'] ?? $doc['id'];
            $students[] = [
                'id'          => $stuId,
                'name'        => $profile['Name'] ?? $profile['name'] ?? '',
                'phone'       => $profile['Phone Number'] ?? $profile['phone'] ?? '-',
                'photo'       => $profile['Profile Pic'] ?? $profile['profilePic'] ?? null,
                'last_result' => $profile['Last_result'] ?? 'N/A',
            ];
        }

        echo json_encode($students);
    }




    public function get_section_settings()
    {
        $this->_require_role(self::VIEW_ROLES);
        header('Content-Type: application/json');

        $class   = trim((string) $this->input->post('class_name'));
        $section = trim((string) $this->input->post('section_name'));

        if (!$class || !$section) {
            echo json_encode(['max_strength' => 0]);
            return;
        }

        $classKey = Firestore_service::classKey($class);
        $sectionKey = Firestore_service::sectionKey($section);
        $sectionDocId = $this->fs->sectionDocId($class, $section);
        $data = $this->fs->get('sections', $sectionDocId);

        // Firestore-only per no-RTDB policy.
        if (!is_array($data)) {
            echo json_encode(['max_strength' => 0]);
            return;
        }

        $ms = $data['maxStrength'] ?? $data['max_strength'] ?? 0;
        echo json_encode(['max_strength' => (int) $ms]);
    }


    public function save_section_settings()
    {
        $this->_require_role(self::MANAGE_ROLES);
        header('Content-Type: application/json');

        $class        = trim((string) $this->input->post('class_name'));
        $section      = trim((string) $this->input->post('section_name'));
        $max_strength = (int) $this->input->post('max_strength');

        if (!$class || !$section || $max_strength <= 0) {
            echo json_encode(['status' => 'error', 'message' => 'Invalid input']);
            return;
        }

        $classKey = Firestore_service::classKey($class);
        $sectionKey = Firestore_service::sectionKey($section);
        $sectionDocId = $this->fs->sectionDocId($class, $section);

        $this->fs->set('sections', $sectionDocId, [
            'schoolId'    => $this->school_id,
            'className'   => $classKey,
            'section'     => $sectionKey,
            'session'     => $this->session_year,
            'maxStrength' => $max_strength,
            'updatedAt'   => date('c'),
        ], true);

        // RTDB mirror removed per no-RTDB policy.

        echo json_encode(['status' => 'success']);
    }






    private function format_class_name($className)
    {
        if (!is_numeric($className)) {
            return $className;
        }

        $num = (int)$className;

        if ($num % 100 >= 11 && $num % 100 <= 13) {
            $suffix = 'th';
        } else {
            switch ($num % 10) {
                case 1:
                    $suffix = 'st';
                    break;
                case 2:
                    $suffix = 'nd';
                    break;
                case 3:
                    $suffix = 'rd';
                    break;
                default:
                    $suffix = 'th';
            }
        }

        return "Class {$num}{$suffix}";
    }

    public function loadClassesForTransfer()
    {
        $this->_require_role(self::VIEW_ROLES);
        header('Content-Type: application/json');

        // Query all sections for this school from Firestore
        $sectionDocs = $this->fs->schoolWhere('sections', []);

        // RTDB fallback + auto-heal
        if (empty($sectionDocs)) {
            $sectionDocs = $this->_rtdb_fallback_all_sections();
        }

        $data = [
            'classes'  => [],
            'sections' => [],
        ];

        foreach ($sectionDocs as $doc) {
            $sd = $doc['data'];
            $classKey   = $sd['className'] ?? '';
            $sectionKey = $sd['section'] ?? '';
            if (!$classKey || !$sectionKey) continue;

            if (!isset($data['classes'][$classKey])) {
                $data['classes'][$classKey] = $classKey;
                $data['sections'][$classKey] = [];
            }
            $data['sections'][$classKey][] = $sectionKey;
        }

        echo json_encode($data);
    }

    public function transfer_students()
    {
        $this->_require_role(self::MANAGE_ROLES);
        header('Content-Type: application/json');

        $studentIds  = $this->input->post('student_ids');
        $fromClass   = trim((string) $this->input->post('from_class'));
        $fromSection = trim((string) $this->input->post('from_section'));
        $toClass     = trim((string) $this->input->post('to_class'));
        $toSection   = trim((string) $this->input->post('to_section'));

        if (
            empty($studentIds) ||
            !$fromClass || !$fromSection ||
            !$toClass || !$toSection
        ) {
            echo json_encode(['status' => 'error', 'message' => 'Invalid data']);
            return;
        }

        if ($fromClass === $toClass && $fromSection === $toSection) {
            echo json_encode(['status' => 'error', 'message' => 'Same section selected']);
            return;
        }

        // Check target section capacity
        $toClassKey = Firestore_service::classKey($toClass);
        $toSectionKey = Firestore_service::sectionKey($toSection);
        $toSectionDocId = $this->fs->schoolId() . '_' . $toClassKey . '_' . $toSectionKey;
        $toSectionDoc = $this->fs->get('sections', $toSectionDocId);
        $maxStrength = (int) ($toSectionDoc['maxStrength'] ?? $toSectionDoc['max_strength'] ?? 0);

        if ($maxStrength > 0) {
            $currentStudents = $this->fs->schoolWhere('students', [
                ['Class', '==', $toClassKey],
                ['Section', '==', $toSectionKey],
                ['Status', '==', 'Active'],
            ]);
            $currentCount = count($currentStudents);
            $transferCount = count($studentIds);
            if (($currentCount + $transferCount) > $maxStrength) {
                echo json_encode(['status' => 'error', 'message' => "Target section capacity exceeded ({$currentCount}/{$maxStrength}). Cannot add {$transferCount} more student(s)."]);
                return;
            }
        }

        $cleanClass   = Firestore_service::classKey($toClass);
        $cleanSection = Firestore_service::sectionKey($toSection);

        // Build RTDB path components for roster move
        $fromClassKey   = Firestore_service::classKey($fromClass);
        $fromSectionKey = Firestore_service::sectionKey($fromSection);
        $toClassNode    = $toClassKey;   // already prefixed above
        $toSectionNode  = $toSectionKey; // already prefixed above
        $sessionRoot    = "Schools/{$this->school_name}/{$this->session_year}";
        $parentRoot     = "Users/Parents/{$this->parent_db_key}";

        // Update each student in Firestore + RTDB
        $transferred = 0;
        foreach ($studentIds as $stuId) {
            // Firestore write
            $ok = $this->fs->updateEntity('students', $stuId, [
                'Class'     => $cleanClass,
                'Section'   => $cleanSection,
                'className' => $cleanClass,   // lowercase alias
                'section'   => $cleanSection,  // lowercase alias
                'updatedAt' => date('c'),
            ]);
            if ($ok) $transferred++;

            // RTDB mirror removed per no-RTDB policy. Firestore `students` is the sole source.
        }

        echo json_encode([
            'status'  => 'success',
            'message' => "{$transferred} student(s) transferred successfully",
        ]);
    }

    /**
     * Delete a section. POST only.
     * Checks for pending student fees before allowing deletion.
     */
    public function delete_section()
    {
        $this->_require_role(self::MANAGE_ROLES);
        header('Content-Type: application/json');

        $class   = trim((string) $this->input->post('class_name'));
        $section = trim((string) $this->input->post('section_name'));

        if (!$class || !$section) {
            echo json_encode(['status' => 'error', 'message' => 'Class and section are required.']);
            return;
        }

        // Check if any students in this section have pending fee demands
        try {
            $classPrefixed   = Firestore_service::classKey($class);
            $sectionPrefixed = Firestore_service::sectionKey($section);

            $sectionStudents = $this->fs->schoolWhere('students', [
                ['Class', '==', $classPrefixed],
                ['Section', '==', $sectionPrefixed],
                ['Status', '==', 'Active'],
            ]);

            if (!empty($sectionStudents)) {
                $studentsWithDues = 0;
                $totalDues = 0;

                foreach ($sectionStudents as $doc) {
                    $studentId = $doc['data']['User ID'] ?? $doc['data']['studentId'] ?? $doc['id'];
                    $pendingDocs = $this->fs->schoolWhere('feeDemands', [
                        ['studentId', '==', $studentId],
                        ['status', 'in', ['pending', 'Pending', 'overdue', 'Overdue']],
                    ]);
                    $dues = 0;
                    foreach ($pendingDocs as $pd) {
                        $dues += (float) ($pd['data']['amount'] ?? 0);
                    }
                    if ($dues > 0) {
                        $studentsWithDues++;
                        $totalDues += $dues;
                    }
                }

                if ($studentsWithDues > 0) {
                    echo json_encode([
                        'status'             => 'error',
                        'message'            => "Cannot delete section: {$studentsWithDues} student(s) have pending fees totaling Rs. {$totalDues}. Clear dues first.",
                        'students_with_dues' => $studentsWithDues,
                        'total_dues'         => $totalDues,
                    ]);
                    return;
                }
            }
        } catch (Exception $e) {
            log_message('error', "Fee check on section delete failed: " . $e->getMessage());
        }

        // Delete section document from Firestore
        try {
            $classKey = Firestore_service::classKey($class);
            $sectionKey = Firestore_service::sectionKey($section);
            $sectionDocId = $this->fs->sectionDocId($class, $section);
            $this->fs->remove('sections', $sectionDocId);

            // RTDB mirror removed per no-RTDB policy.

            echo json_encode([
                'status'  => 'success',
                'message' => "Section {$section} deleted successfully.",
            ]);
        } catch (Exception $e) {
            log_message('error', "Section delete failed: " . $e->getMessage());
            echo json_encode(['status' => 'error', 'message' => 'Failed to delete section: ' . $e->getMessage()]);
        }
    }

    // RTDB fallback methods removed per no-RTDB policy.
    // All reads now use Firestore `sections` + `students` collections exclusively.

    // Lines below were the 3 deleted _rtdb_fallback methods. Removed entirely.
}
