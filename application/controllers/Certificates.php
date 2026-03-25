<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Certificates Controller — Certificate Management Module
 *
 * Tab-based SPA with clean URL routing — each tab loads via server route + AJAX data.
 *
 * Access: Admin / Principal can manage (create/issue/revoke).
 *         Teacher can only view issued certificates.
 *
 * Firebase paths:
 *   Schools/{school}/{session}/Certificates/Templates/{type}     — certificate templates
 *   Schools/{school}/{session}/Certificates/Issued/{certId}      — issued certificates
 *   Schools/{school}/{session}/Certificates/Counters/             — sequential counters
 *   Schools/{school}/Config/Profile                               — school profile (logo, name, address)
 *   Users/Parents/{parent_db_key}/{userId}                        — student profile data
 *
 * Routes:
 *   certificates                          → index (dashboard)
 *   certificates/templates                → index (templates tab)
 *   certificates/generate                 → index (generate tab)
 *   certificates/issued                   → index (issued tab)
 *   certificates/get_dashboard            → AJAX dashboard stats
 *   certificates/get_templates            → AJAX list templates
 *   certificates/save_template            → AJAX create/update template
 *   certificates/delete_template          → AJAX delete template
 *   certificates/get_students             → AJAX student list for class/section
 *   certificates/get_student_details      → AJAX single student profile
 *   certificates/generate_certificate     → AJAX issue + save certificate
 *   certificates/get_issued               → AJAX list issued certificates
 *   certificates/revoke_certificate       → AJAX revoke/delete issued certificate
 *   certificates/get_certificate          → AJAX single issued certificate
 *   certificates/get_school_profile       → AJAX school profile for print header
 */
class Certificates extends MY_Controller
{
    /** Roles that can manage (create/issue/revoke) certificates */
    const MANAGE_ROLES = ['Super Admin', 'School Super Admin', 'Admin', 'Principal'];

    /** All roles that can view certificates */
    const VIEW_ROLES = ['Super Admin', 'School Super Admin', 'Admin', 'Principal', 'Teacher'];

    /** Allowed certificate types */
    const CERT_TYPES = ['bonafide', 'transfer', 'character', 'custom'];

    /** Default placeholders available in templates */
    const PLACEHOLDERS = [
        '{student_name}', '{father_name}', '{mother_name}',
        '{class}', '{section}', '{admission_number}',
        '{date_of_birth}', '{issue_date}', '{school_name}',
        '{school_address}', '{certificate_number}', '{session}',
        '{gender}', '{nationality}', '{religion}', '{caste}',
        '{enrollment_date}', '{leaving_date}', '{conduct}',
        '{reason_for_leaving}',
    ];

    private $_certBase;

    public function __construct()
    {
        parent::__construct();
        require_permission('Certificates');
        $this->_certBase = "Schools/{$this->school_name}/{$this->session_year}/Certificates";
    }

    // ═══════════════════════════════════════════════════════════════════════
    //  PAGE LOAD
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * Main page — renders the SPA shell with $active_tab set by URL.
     */
    public function index($tab = 'dashboard')
    {
        $this->_require_role(self::VIEW_ROLES, 'certificate_view');

        $validTabs = ['dashboard', 'templates', 'generate', 'issued'];
        if (!in_array($tab, $validTabs, true)) $tab = 'dashboard';

        $data['session_year']  = $this->session_year;
        $data['school_name']   = $this->school_display_name;
        $data['school_fb_key'] = $this->school_name;
        $data['admin_role']    = $this->admin_role ?? '';
        $data['admin_id']      = $this->admin_id ?? '';
        $data['admin_name']    = $this->session->userdata('admin_name') ?? '';
        $data['active_tab']    = $tab;
        $data['can_manage']    = in_array($this->admin_role, self::MANAGE_ROLES, true)
                                  || $this->admin_role === 'Super Admin'
                                  || $this->admin_role === 'School Super Admin';

        $this->load->view('include/header');
        $this->load->view('certificates/index', $data);
        $this->load->view('include/footer');
    }

    // ═══════════════════════════════════════════════════════════════════════
    //  AJAX — DASHBOARD
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * GET — dashboard summary stats + recent certificates.
     */
    public function get_dashboard()
    {
        $this->_require_role(self::VIEW_ROLES, 'cert_dashboard');

        $issued = $this->firebase->get("{$this->_certBase}/Issued");
        if (!is_array($issued)) $issued = [];

        $today       = date('Y-m-d');
        $total       = 0;
        $todayCount  = 0;
        $bonafide    = 0;
        $transfer    = 0;
        $character   = 0;
        $recent      = [];

        foreach ($issued as $id => $cert) {
            if (!is_array($cert) || $id === 'Counter') continue;
            if (!empty($cert['revoked'])) continue;

            $total++;
            $type = $cert['certificateType'] ?? '';
            if ($type === 'bonafide')  $bonafide++;
            if ($type === 'transfer')  $transfer++;
            if ($type === 'character') $character++;

            $issueDate = $cert['issueDate'] ?? '';
            if ($issueDate === $today) $todayCount++;

            $recent[] = [
                'id'                => $id,
                'certificateNumber' => $cert['certificateNumber'] ?? '',
                'certificateType'   => $type,
                'studentName'       => $cert['studentName'] ?? '',
                'classKey'          => $cert['classKey'] ?? '',
                'sectionKey'        => $cert['sectionKey'] ?? '',
                'issueDate'         => $issueDate,
                'issuedBy'          => $cert['issuedBy'] ?? '',
            ];
        }

        // Sort recent by createdAt desc, take top 10
        usort($recent, function ($a, $b) {
            return strcmp($b['issueDate'], $a['issueDate']);
        });
        $recent = array_slice($recent, 0, 10);

        $this->json_success(['data' => [
            'total'      => $total,
            'today'      => $todayCount,
            'bonafide'   => $bonafide,
            'transfer'   => $transfer,
            'character'  => $character,
            'recent'     => $recent,
        ]]);
    }

    // ═══════════════════════════════════════════════════════════════════════
    //  AJAX — TEMPLATES
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * GET — list all certificate templates.
     */
    public function get_templates()
    {
        $this->_require_role(self::VIEW_ROLES, 'cert_templates');

        $templates = $this->firebase->get("{$this->_certBase}/Templates");
        if (!is_array($templates)) $templates = [];

        $result = [];

        // Built-in types
        foreach (['Bonafide', 'Transfer', 'Character'] as $type) {
            $tpl = $templates[$type] ?? null;
            if (is_array($tpl)) {
                $tpl['id']   = $type;
                $tpl['type'] = strtolower($type);
                $tpl['builtIn'] = true;
                $result[] = $tpl;
            }
        }

        // Custom templates
        $customs = $templates['Custom'] ?? [];
        if (is_array($customs)) {
            foreach ($customs as $cid => $tpl) {
                if (!is_array($tpl) || $cid === 'Counter') continue;
                $tpl['id']   = 'Custom/' . $cid;
                $tpl['type'] = 'custom';
                $tpl['builtIn'] = false;
                $result[] = $tpl;
            }
        }

        $this->json_success(['data' => [
            'templates'    => $result,
            'placeholders' => self::PLACEHOLDERS,
        ]]);
    }

    /**
     * POST — create or update a certificate template.
     */
    public function save_template()
    {
        if ($this->input->method() !== 'post') return $this->json_error('POST required', 405);
        $this->_require_role(self::MANAGE_ROLES, 'cert_save_template');

        $type = trim($this->input->post('type') ?? '');
        $name = trim($this->input->post('name') ?? '');
        $body = trim($this->input->post('body') ?? '');
        $title = trim($this->input->post('title') ?? '');
        $editId = trim($this->input->post('id') ?? '');

        if (empty($name)) return $this->json_error('Template name is required.');
        if (empty($body)) return $this->json_error('Template body is required.');
        if (!in_array($type, self::CERT_TYPES, true)) {
            return $this->json_error('Invalid certificate type.');
        }

        $tplData = [
            'name'      => $name,
            'title'     => $title ?: $name,
            'body'      => $body,
            'type'      => $type,
            'updatedAt' => date('c'),
            'updatedBy' => $this->admin_id,
        ];

        if ($type === 'custom') {
            if ($editId && strpos($editId, 'Custom/') === 0) {
                // Update existing custom template
                $cid = str_replace('Custom/', '', $editId);
                $this->safe_path_segment($cid, 'templateId');
                $this->firebase->update("{$this->_certBase}/Templates/Custom/{$cid}", $tplData);
                $path = "Custom/{$cid}";
            } else {
                // New custom template
                $counter = (int) ($this->firebase->get("{$this->_certBase}/Templates/Custom/Counter") ?? 0) + 1;
                $this->firebase->set("{$this->_certBase}/Templates/Custom/Counter", $counter);
                $cid = 'TPL' . str_pad($counter, 4, '0', STR_PAD_LEFT);
                $tplData['createdAt'] = date('c');
                $this->firebase->set("{$this->_certBase}/Templates/Custom/{$cid}", $tplData);
                $path = "Custom/{$cid}";
            }
        } else {
            // Built-in type: Bonafide, Transfer, Character
            $typeKey = ucfirst($type);
            if (empty($editId)) {
                $tplData['createdAt'] = date('c');
            }
            $this->firebase->update("{$this->_certBase}/Templates/{$typeKey}", $tplData);
            $path = $typeKey;
        }

        $this->json_success(['data' => ['message' => 'Template saved.', 'id' => $path]]);
    }

    /**
     * POST — delete a certificate template (custom only).
     */
    public function delete_template()
    {
        if ($this->input->method() !== 'post') return $this->json_error('POST required', 405);
        $this->_require_role(self::MANAGE_ROLES, 'cert_delete_template');

        $id = trim($this->input->post('id') ?? '');
        if (empty($id)) return $this->json_error('Template ID required.');

        // Only custom templates can be deleted
        if (strpos($id, 'Custom/') !== 0) {
            return $this->json_error('Built-in templates cannot be deleted.');
        }

        $cid = str_replace('Custom/', '', $id);
        $this->safe_path_segment($cid, 'templateId');

        $this->firebase->delete("{$this->_certBase}/Templates/Custom", $cid);
        $this->json_success(['data' => ['message' => 'Template deleted.']]);
    }

    // ═══════════════════════════════════════════════════════════════════════
    //  AJAX — STUDENT DATA (SIS Integration)
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * GET — return class-section list for dropdowns.
     */
    public function get_classes()
    {
        $this->_require_role(self::VIEW_ROLES, 'cert_classes');
        $classes = $this->_get_session_classes();
        $this->json_success(['data' => ['classes' => $classes]]);
    }

    /**
     * POST — get students enrolled in a class/section.
     */
    public function get_students()
    {
        if ($this->input->method() !== 'post') return $this->json_error('POST required', 405);
        $this->_require_role(self::VIEW_ROLES, 'cert_students');

        $classKey   = trim($this->input->post('classKey') ?? '');
        $sectionKey = trim($this->input->post('sectionKey') ?? '');

        if (empty($classKey)) return $this->json_error('Class is required.');
        $this->safe_path_segment($classKey, 'classKey');

        $school  = $this->school_name;
        $session = $this->session_year;

        $students = [];

        if (!empty($sectionKey)) {
            $this->safe_path_segment($sectionKey, 'sectionKey');
            $secNode = "Section {$sectionKey}";
            $list = $this->firebase->get(
                "Schools/{$school}/{$session}/{$classKey}/{$secNode}/Students/List"
            );
            if (is_array($list)) {
                foreach ($list as $uid => $name) {
                    $students[] = ['user_id' => $uid, 'name' => $name, 'section' => $sectionKey];
                }
            }
        } else {
            // All sections
            $sectionKeys = $this->firebase->shallow_get("Schools/{$school}/{$session}/{$classKey}");
            if (is_array($sectionKeys)) {
                foreach (array_keys($sectionKeys) as $sk) {
                    if (strpos($sk, 'Section ') !== 0) continue;
                    $sec  = str_replace('Section ', '', $sk);
                    $list = $this->firebase->get(
                        "Schools/{$school}/{$session}/{$classKey}/{$sk}/Students/List"
                    );
                    if (is_array($list)) {
                        foreach ($list as $uid => $name) {
                            $students[] = ['user_id' => $uid, 'name' => $name, 'section' => $sec];
                        }
                    }
                }
            }
        }

        // Sort by name
        usort($students, function ($a, $b) {
            return strcasecmp($a['name'], $b['name']);
        });

        $this->json_success(['data' => ['students' => $students]]);
    }

    /**
     * POST — get full student profile for certificate population.
     */
    public function get_student_details()
    {
        if ($this->input->method() !== 'post') return $this->json_error('POST required', 405);
        $this->_require_role(self::VIEW_ROLES, 'cert_student_details');

        $userId = trim($this->input->post('userId') ?? '');
        if (empty($userId)) return $this->json_error('Student ID required.');
        $this->safe_path_segment($userId, 'userId');

        $student = $this->firebase->get("Users/Parents/{$this->parent_db_key}/{$userId}");
        if (!is_array($student)) {
            return $this->json_error('Student not found.');
        }

        $this->json_success(['data' => [
            'student' => [
                'user_id'          => $userId,
                'name'             => $student['Name'] ?? '',
                'father_name'      => $student['Father Name'] ?? '',
                'mother_name'      => $student['Mother Name'] ?? '',
                'class'            => $student['Class'] ?? '',
                'section'          => $student['Section'] ?? '',
                'dob'              => $student['DOB'] ?? ($student['Date of Birth'] ?? ''),
                'admission_number' => $student['Admission Number'] ?? ($student['User Id'] ?? $userId),
                'gender'           => $student['Gender'] ?? '',
                'nationality'      => $student['Nationality'] ?? 'Indian',
                'religion'         => $student['Religion'] ?? '',
                'caste'            => $student['Caste'] ?? '',
                'enrollment_date'  => $student['Admission Date'] ?? '',
                'photo'            => $student['Photo'] ?? '',
            ],
        ]]);
    }

    // ═══════════════════════════════════════════════════════════════════════
    //  AJAX — GENERATE / ISSUE CERTIFICATE
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * POST — generate and save an issued certificate.
     */
    public function generate_certificate()
    {
        if ($this->input->method() !== 'post') {
            return $this->json_error('POST required', 405);
        }
        $this->_require_role(self::MANAGE_ROLES, 'cert_generate');

        $certType    = trim($this->input->post('certificateType') ?? '');
        $templateId  = trim($this->input->post('templateId') ?? '');
        $userId      = trim($this->input->post('userId') ?? '');
        $classKey    = trim($this->input->post('classKey') ?? '');
        $sectionKey  = trim($this->input->post('sectionKey') ?? '');
        $extraData   = $this->input->post('extraData');

        // Validation
        if (empty($certType) || !in_array($certType, self::CERT_TYPES, true)) {
            return $this->json_error('Invalid certificate type.');
        }
        if (empty($templateId)) return $this->json_error('Template is required.');
        if (empty($userId))     return $this->json_error('Student is required.');

        $this->safe_path_segment($userId, 'userId');
        if (!empty($classKey))   $this->safe_path_segment($classKey, 'classKey');
        if (!empty($sectionKey)) $this->safe_path_segment($sectionKey, 'sectionKey');

        // Load template
        $tplPath = $this->_resolveTemplatePath($templateId);
        if (!$tplPath) return $this->json_error('Invalid template ID.');

        $template = $this->firebase->get("{$this->_certBase}/Templates/{$tplPath}");
        if (!is_array($template)) {
            return $this->json_error('Template not found.');
        }

        // Load student profile
        $student = $this->firebase->get("Users/Parents/{$this->parent_db_key}/{$userId}");
        if (!is_array($student)) {
            return $this->json_error('Student not found.');
        }

        // Generate certificate number (read-increment-write; best-effort atomicity)
        $counterPath = "{$this->_certBase}/Counters/certificateNumber";
        $counter = (int) ($this->firebase->get($counterPath) ?? 0) + 1;
        $this->firebase->set($counterPath, $counter);
        $year = date('Y');
        $certNumber = "CERT-{$year}-" . str_pad($counter, 5, '0', STR_PAD_LEFT);

        // Generate certificate ID (same counter, same padding)
        $certId = 'CRT' . str_pad($counter, 5, '0', STR_PAD_LEFT);

        // Build placeholder data
        $issueDate = date('Y-m-d');
        $placeholderData = [
            '{student_name}'       => $student['Name'] ?? '',
            '{father_name}'        => $student['Father Name'] ?? '',
            '{mother_name}'        => $student['Mother Name'] ?? '',
            '{class}'              => $student['Class'] ?? str_replace('Class ', '', $classKey),
            '{section}'            => $student['Section'] ?? $sectionKey,
            '{admission_number}'   => $student['Admission Number'] ?? ($student['User Id'] ?? $userId),
            '{date_of_birth}'      => $student['DOB'] ?? ($student['Date of Birth'] ?? ''),
            '{issue_date}'         => $issueDate,
            '{school_name}'        => $this->school_display_name,
            '{school_address}'     => '',
            '{certificate_number}' => $certNumber,
            '{session}'            => $this->session_year,
            '{gender}'             => $student['Gender'] ?? '',
            '{nationality}'        => $student['Nationality'] ?? 'Indian',
            '{religion}'           => $student['Religion'] ?? '',
            '{caste}'              => $student['Caste'] ?? '',
            '{enrollment_date}'    => $student['Admission Date'] ?? '',
            '{leaving_date}'       => '',
            '{conduct}'            => 'Good',
            '{reason_for_leaving}' => '',
        ];

        // Load school profile for address
        $profile = $this->firebase->get("Schools/{$this->school_name}/Config/Profile");
        if (is_array($profile)) {
            $placeholderData['{school_address}'] = $profile['Address'] ?? '';
        }

        // Merge extra data from form (e.g. leaving_date, conduct, reason)
        if (is_array($extraData)) {
            foreach ($extraData as $key => $val) {
                $phKey = '{' . $key . '}';
                if (in_array($phKey, self::PLACEHOLDERS, true)) {
                    $placeholderData[$phKey] = trim($val);
                }
            }
        }

        // Resolve template content (XSS protection handled client-side by esc())
        $resolvedTitle = $this->_replacePlaceholders($template['title'] ?? $template['name'] ?? '', $placeholderData);
        $resolvedBody  = $this->_replacePlaceholders($template['body'] ?? '', $placeholderData);

        // Build issued record
        $issuedData = [
            'certificateNumber' => $certNumber,
            'certificateType'   => $certType,
            'templateId'        => $templateId,
            'studentId'         => $userId,
            'studentName'       => $student['Name'] ?? '',
            'classKey'          => $classKey,
            'sectionKey'        => $sectionKey,
            'issueDate'         => $issueDate,
            'issuedBy'          => $this->admin_name ?? $this->admin_id,
            'issuedById'        => $this->admin_id,
            'createdAt'         => date('c'),
            'templateData'      => [
                'title' => $resolvedTitle,
                'body'  => $resolvedBody,
            ],
            'placeholderValues'  => $placeholderData,
            'pdfUrl'             => '',
            'revoked'            => false,
        ];

        $this->firebase->set("{$this->_certBase}/Issued/{$certId}", $issuedData);

        $this->json_success(['data' => [
            'message'           => 'Certificate issued successfully.',
            'certificateId'     => $certId,
            'certificateNumber' => $certNumber,
            'certificate'       => $issuedData,
        ]]);
    }

    // ═══════════════════════════════════════════════════════════════════════
    //  AJAX — ISSUED CERTIFICATES
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * GET — list all issued certificates.
     */
    public function get_issued()
    {
        $this->_require_role(self::VIEW_ROLES, 'cert_issued');

        $issued = $this->firebase->get("{$this->_certBase}/Issued");
        if (!is_array($issued)) $issued = [];

        $result = [];
        foreach ($issued as $id => $cert) {
            if (!is_array($cert) || $id === 'Counter') continue;
            $result[] = [
                'id'                => $id,
                'certificateNumber' => $cert['certificateNumber'] ?? '',
                'certificateType'   => $cert['certificateType'] ?? '',
                'studentId'         => $cert['studentId'] ?? '',
                'studentName'       => $cert['studentName'] ?? '',
                'classKey'          => $cert['classKey'] ?? '',
                'sectionKey'        => $cert['sectionKey'] ?? '',
                'issueDate'         => $cert['issueDate'] ?? '',
                'issuedBy'          => $cert['issuedBy'] ?? '',
                'revoked'           => !empty($cert['revoked']),
                'createdAt'         => $cert['createdAt'] ?? '',
            ];
        }

        // Sort by createdAt desc
        usort($result, function ($a, $b) {
            return strcmp($b['createdAt'], $a['createdAt']);
        });

        $this->json_success(['data' => ['issued' => $result]]);
    }

    /**
     * POST — get a single issued certificate (for print/view).
     */
    public function get_certificate()
    {
        if ($this->input->method() !== 'post') return $this->json_error('POST required', 405);
        $this->_require_role(self::VIEW_ROLES, 'cert_get');

        $certId = trim($this->input->post('certId') ?? '');
        if (empty($certId)) return $this->json_error('Certificate ID required.');
        $this->safe_path_segment($certId, 'certId');

        $cert = $this->firebase->get("{$this->_certBase}/Issued/{$certId}");
        if (!is_array($cert)) {
            return $this->json_error('Certificate not found.');
        }

        // Load school profile for print header
        $profile = $this->firebase->get("Schools/{$this->school_name}/Config/Profile");
        $schoolInfo = [
            'name'    => $this->school_display_name,
            'address' => is_array($profile) ? ($profile['Address'] ?? '') : '',
            'phone'   => is_array($profile) ? ($profile['Phone Number'] ?? '') : '',
            'email'   => is_array($profile) ? ($profile['Email'] ?? '') : '',
            'logo'    => is_array($profile) ? ($profile['Logo'] ?? '') : '',
        ];

        $this->json_success(['data' => [
            'certificate' => $cert,
            'school'      => $schoolInfo,
        ]]);
    }

    /**
     * POST — revoke an issued certificate.
     */
    public function revoke_certificate()
    {
        if ($this->input->method() !== 'post') return $this->json_error('POST required', 405);
        $this->_require_role(self::MANAGE_ROLES, 'cert_revoke');

        $certId = trim($this->input->post('certId') ?? '');
        if (empty($certId)) return $this->json_error('Certificate ID required.');
        $this->safe_path_segment($certId, 'certId');

        $cert = $this->firebase->get("{$this->_certBase}/Issued/{$certId}");
        if (!is_array($cert)) {
            return $this->json_error('Certificate not found.');
        }

        $this->firebase->update("{$this->_certBase}/Issued/{$certId}", [
            'revoked'    => true,
            'revokedAt'  => date('c'),
            'revokedBy'  => $this->admin_id,
        ]);

        $this->json_success(['data' => ['message' => 'Certificate revoked.']]);
    }

    /**
     * GET — school profile for print header.
     */
    public function get_school_profile()
    {
        $this->_require_role(self::VIEW_ROLES, 'cert_school_profile');

        $profile = $this->firebase->get("Schools/{$this->school_name}/Config/Profile");
        $this->json_success(['data' => [
            'school' => [
                'name'    => $this->school_display_name,
                'address' => is_array($profile) ? ($profile['Address'] ?? '') : '',
                'phone'   => is_array($profile) ? ($profile['Phone Number'] ?? '') : '',
                'email'   => is_array($profile) ? ($profile['Email'] ?? '') : '',
                'logo'    => is_array($profile) ? ($profile['Logo'] ?? '') : '',
                'state'   => is_array($profile) ? ($profile['State'] ?? '') : '',
            ],
        ]]);
    }

    // ═══════════════════════════════════════════════════════════════════════
    //  INTERNALS
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * Resolve template path from ID.
     * "Bonafide" → "Bonafide", "Custom/TPL0001" → "Custom/TPL0001"
     */
    private function _resolveTemplatePath(string $id): string
    {
        $builtIn = ['Bonafide', 'Transfer', 'Character'];
        if (in_array($id, $builtIn, true)) return $id;

        if (strpos($id, 'Custom/') === 0) {
            $cid = str_replace('Custom/', '', $id);
            if (preg_match('/^TPL\d+$/', $cid)) return $id;
        }

        return '';
    }

    /**
     * Replace {placeholder} tokens in template text.
     * XSS protection handled client-side by JavaScript esc() function.
     */
    private function _replacePlaceholders(string $text, array $data): string
    {
        foreach ($data as $placeholder => $value) {
            $text = str_replace($placeholder, $value, $text);
        }
        return $text;
    }

    /**
     * Sanitize all placeholder values to prevent XSS when rendered.
     */
    private function _sanitizePlaceholderValues(array $data): array
    {
        $clean = [];
        foreach ($data as $key => $value) {
            $clean[$key] = htmlspecialchars((string) $value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }
        return $clean;
    }
}
