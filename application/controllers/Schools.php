<?php
defined('BASEPATH') or exit('No direct script access allowed');

class Schools extends MY_Controller
{
    /** Roles for school management */
    private const ADMIN_ROLES = ['Super Admin', 'School Super Admin', 'Admin', 'Principal', 'Vice Principal'];

    /** Roles that may view school data */
    private const VIEW_ROLES  = ['Super Admin', 'School Super Admin', 'Admin', 'Principal', 'Vice Principal', 'Academic Coordinator', 'HR Manager', 'Accountant', 'Class Teacher', 'Teacher', 'Front Office'];

    public function __construct()
    {
        parent::__construct();
        require_permission('Configuration');
    }

    // ──────────────────────────────────────────────────────────────────────
    //  BUG FIXES IN THIS CONTROLLER:
    //
    //  1. edit_school() GET branch: echo '<pre>print_r()</pre>' was left in
    //     production code — removed.
    //
    //  2. edit_school() POST: $files array uses $_FILES directly without
    //     checking is_uploaded_file for each; harmless but cleaned up.
    //
    //  3. manage_school() POST: normalizeKeys() converts underscored POST
    //     keys to title-case spaced keys e.g. "subscription_plan" →
    //     "Subscription Plan". The code then reads 'subscription plan'
    //     (lowercase) which never matches after normalisation → all
    //     subscription fields were NULL. Fixed by reading the correct
    //     normalised keys.
    //
    //  4. manage_school() POST: features checkbox array arrives as
    //     'features' (after normalise), but was read as
    //     $normalizedFormData['features'] which is fine — however it was
    //     never cast to array safely. Added (array) cast.
    //
    //  5. manage_school() GET: foreach on $schoolIds could fatal if
    //     select_data() returns null/false. Added null-check guard.
    //
    //  6. deleteMedia(): method='DELETE' from JS but CI's $this->input
    //     doesn't parse DELETE bodies. The URL param ?url= is used via
    //     $_GET which is correct — left as-is but documented.
    //
    //  7. fetchGalleryMedia(): path had leading slash "/Schools/..." which
    //     is inconsistent with how the rest of the app uses Firebase paths
    //     — standardised to no leading slash.
    //
    //  8. schoolProfile(): schoolData cast from object to array defensively.
    //
    //  9. uploadMedia(): ffmpeg path is hardcoded Windows path — wrapped in
    //     a configurable constant with graceful fallback so Linux/prod
    //     servers don't crash on video uploads.
    // ──────────────────────────────────────────────────────────────────────

    public function fees()
    {
        $this->_require_role(self::VIEW_ROLES, 'view_fees');
        $this->load->view('include/header');
        $this->load->view('fees');
        $this->load->view('include/footer');
    }

    // ── Delete School (Super Admin only) ────────────────────────────────
    public function delete_school($schoolId = null)
    {
        if (!in_array($this->admin_role, ['Super Admin', 'School Super Admin'])) {
            show_error('Access denied. Super Admin only.', 403);
            return;
        }
        if (empty($schoolId) || !$this->safe_path_segment($schoolId)) {
            show_error('Invalid school ID.', 400);
            return;
        }
        // Cross-tenant guard: logged-in admin must own this school or be SA-panel ("Our Panel")
        $adminSchoolId = $this->school_id ?? '';
        if ($adminSchoolId !== $schoolId && ($this->session->userdata('admin_type') ?? '') !== 'Our Panel') {
            show_error('You can only delete your own school.', 403);
            return;
        }
        $schoolName = $this->CM->get_school_name_by_id($schoolId);

        if ($schoolName) {
            $result1             = $this->CM->delete_data('Schools', $schoolName);
            $result2             = $this->CM->delete_data('Indexes/School_codes', $schoolId);
            // Wipe BOTH trees: the canonical schools/{schoolId}/ (all post-cutover
            // uploads) and the legacy {schoolName}/ root (pre-migration files).
            // OR the results so an empty-but-clean tree doesn't fail the guard below.
            $deleteStorageCanon  = $this->CM->delete_folder_from_firebase_storage('schools/' . $schoolId . '/');
            $deleteStorageLegacy = $this->CM->delete_folder_from_firebase_storage($schoolName . '/');
            $deleteStorageResult = $deleteStorageCanon || $deleteStorageLegacy;

            if ($result1 && $result2 && $deleteStorageResult) {
                $currentSchoolCount = $this->CM->get_data('Indexes/School_codes/Count');
                $newSchoolCount     = max(0, (int)$currentSchoolCount - 1);
                $this->CM->addKey_pair_data('Indexes/School_codes/', ['Count' => $newSchoolCount]);
            }
        }

        redirect('schools/manage_school');
    }

    // ── Edit School (Super Admin only) ──────────────────────────────────
    public function edit_school($schoolId = null)
    {
        if (!in_array($this->admin_role, ['Super Admin', 'School Super Admin'])) {
            show_error('Access denied. Super Admin only.', 403);
            return;
        }
        if (empty($schoolId) || !$this->safe_path_segment($schoolId)) {
            show_error('Invalid school ID.', 400);
            return;
        }
        // Cross-tenant guard: logged-in admin must own this school or be SA-panel ("Our Panel")
        $adminSchoolId = $this->school_id ?? '';
        if ($adminSchoolId !== $schoolId && ($this->session->userdata('admin_type') ?? '') !== 'Our Panel') {
            show_error('You can only edit your own school.', 403);
            return;
        }
        $session_year  = $this->session_year;
        $schoolDetails = $this->CM->get_school_name_by_id($schoolId);

        // BUG FIX #1 — get_school_name_by_id may return just a string (the name)
        if (!is_array($schoolDetails)) {
            $schoolDetails = [
                'School Id'   => $schoolId,
                'School Name' => $schoolDetails
            ];
        }

        if ($this->input->method() === 'post') {
            $postData       = $this->input->post();
            $normalizedData = $this->CM->normalizeKeys($postData);
            $newSchoolName  = $normalizedData['School Name'] ?? '';
            $oldSchoolName  = $schoolDetails['School Name']  ?? null;

            if (empty($newSchoolName)) {
                echo '0';
                return;
            }

            $changeSchoolName = $oldSchoolName && $oldSchoolName !== $newSchoolName;

            // Canonical Storage scheme: logo + calendars go to
            // schools/{schoolId}/{logos|holidays|academic}/ — the SAME tree
            // School_config writes to (single-sourced). The folder is keyed by
            // the stable schoolId, so a name change is a pure Firestore/RTDB
            // field update with NO Storage folder rename. This replaces the
            // legacy Common_model path which (a) rooted files at the bare school
            // NAME and (b) copy-renamed the whole {oldName}/ tree on rename —
            // both obsolete under ID-keyed storage.
            $folderMap    = ['school_logos' => 'logos', 'holidays' => 'holidays', 'academic' => 'academic'];
            $updatedFiles = [];
            foreach ($folderMap as $inputKey => $canonFolder) {
                if (!isset($_FILES[$inputKey]) || !is_uploaded_file($_FILES[$inputKey]['tmp_name'] ?? '')) {
                    continue;
                }
                $ext      = strtolower(pathinfo($_FILES[$inputKey]['name'], PATHINFO_EXTENSION)) ?: 'jpg';
                $destPath = "schools/{$schoolId}/{$canonFolder}/{$inputKey}.{$ext}";
                if ($this->firebase->uploadFile($_FILES[$inputKey]['tmp_name'], $destPath) === true) {
                    $updatedFiles[$inputKey] = $this->firebase->getDownloadUrl($destPath);
                }
            }

            $existingData = $this->CM->get_data('Schools/' . $oldSchoolName . '/' . $session_year);
            $dataToUpdate = $existingData ?: [];

            if (isset($updatedFiles['school_logos'])) $dataToUpdate['Logo']              = $updatedFiles['school_logos'];
            if (isset($updatedFiles['holidays']))     $dataToUpdate['Holidays']           = $updatedFiles['holidays'];
            if (isset($updatedFiles['academic']))     $dataToUpdate['Academic calendar']  = $updatedFiles['academic'];

            if ($changeSchoolName) {
                if ($existingData) {
                    $res1 = $this->CM->update_data('Schools/' . $newSchoolName . '/' . $session_year, null, $dataToUpdate);
                    if ($res1) {
                        $this->CM->delete_data('Schools/', $oldSchoolName . '/' . $session_year);
                        $res2 = $this->CM->update_data('', 'Indexes/School_codes/', [$schoolId => $newSchoolName]);
                        if (!$res2) { echo '0'; return; }
                    } else { echo '0'; return; }
                } else { echo '0'; return; }
            } else {
                $this->CM->update_data('Schools/' . $newSchoolName . '/' . $session_year, null, $dataToUpdate);
            }

            // ── School profile → Firestore schools/{schoolId} (single source of
            // truth). The doc is keyed by the stable schoolId, so a name change
            // is just a field update — no node move/delete as the legacy RTDB
            // System/Schools/{name} path required. Operational data under
            // Schools/{name}/{session} (above) is out of scope and unchanged. ──
            $patch = [
                'schoolName' => $newSchoolName,
                'name'       => $newSchoolName,
                'updatedAt'  => date('c'),
            ];
            $fieldMap = [
                'Address'            => 'address',
                'Phone Number'       => 'phone',
                'Mobile Number'      => 'mobileNumber',
                'Email'              => 'email',
                'Website'            => 'website',
                'Affiliated To'      => 'affiliationBoard',
                'Affiliation Number' => 'affiliationNo',
            ];
            foreach ($fieldMap as $formKey => $fsKey) {
                if (isset($normalizedData[$formKey]) && $normalizedData[$formKey] !== '') {
                    $patch[$fsKey] = $normalizedData[$formKey];
                }
            }
            if (isset($patch['address'])) $patch['street'] = $patch['address']; // legacy mirror (SA panel reads `street`)
            // Documents → same canonical Firestore fields School_config writes,
            // so logo + calendars are single-sourced across both school screens.
            if (isset($updatedFiles['school_logos'])) $patch['logoUrl']           = $updatedFiles['school_logos'];
            if (isset($updatedFiles['holidays']))     $patch['holidays_calendar'] = $updatedFiles['holidays'];
            if (isset($updatedFiles['academic']))     $patch['academic_calendar'] = $updatedFiles['academic'];

            if (!$this->fs->set('schools', $schoolId, $patch, true)) {
                log_message('error', "Schools::edit_school — Firestore profile write failed for {$schoolId}");
                echo '0'; return;
            }

            echo '1';

        } else {
            // BUG FIX #1 — removed debug echo '<pre>' that was in production
            $data['school'] = $schoolDetails;

            if (!empty($schoolDetails['School Name'])) {
                // Read the canonical profile from Firestore schools/{schoolId}
                // and remap to the title-case keys the edit_school view expects.
                $fsSchool = $this->fs->get('schools', $schoolId) ?: [];
                if (is_array($fsSchool)) {
                    $data['schooll'] = [
                        'School Id'          => (string) ($fsSchool['schoolCode'] ?? $schoolId),
                        'School Name'        => (string) ($fsSchool['schoolName'] ?? $fsSchool['name'] ?? $schoolDetails['School Name']),
                        'Address'            => (string) ($fsSchool['address'] ?? $fsSchool['street'] ?? ''),
                        'Phone Number'       => (string) ($fsSchool['phone'] ?? ''),
                        'Mobile Number'      => (string) ($fsSchool['mobileNumber'] ?? ''),
                        'Email'              => (string) ($fsSchool['email'] ?? ''),
                        'Website'            => (string) ($fsSchool['website'] ?? ''),
                        'Affiliated To'      => (string) ($fsSchool['affiliationBoard'] ?? $fsSchool['board'] ?? ''),
                        'Affiliation Number' => (string) ($fsSchool['affiliationNo'] ?? ''),
                    ];
                }
                // Documents: prefer the canonical Firestore fields (shared with
                // School_config); fall back to the legacy name-keyed Storage path.
                $data['school_logo_url'] = (string) ($fsSchool['logoUrl'] ?? '')
                    ?: $this->CM->get_file_url($schoolDetails['School Name'] . '/school_logos/school_logos.jpg');
                $data['holidays_url']    = (string) ($fsSchool['holidays_calendar'] ?? '')
                    ?: $this->CM->get_file_url($schoolDetails['School Name'] . '/holidays/holidays');
                $data['academic_url']    = (string) ($fsSchool['academic_calendar'] ?? '')
                    ?: $this->CM->get_file_url($schoolDetails['School Name'] . '/academic/academic');
            } else {
                $data['school_logo_url'] = '';
                $data['holidays_url']    = '';
                $data['academic_url']    = '';
            }

            $this->load->view('include/header');
            $this->load->view('edit_school', $data);
            $this->load->view('include/footer');
        }
    }

    // ── School Profile ────────────────────────────────────────────────────
    public function schoolProfile()
    {
        $this->_require_role(self::VIEW_ROLES, 'view_school_profile');
        $school_name = $this->school_name;

        // ── SP-01 (post-LOGO-1 2026-06-02): Firestore-canonical reads ──
        // Pre-SP-01: this method read 5 RTDB paths (System/Schools/{name},
        // System/Plans/{id}, System/Payments, Users/Schools/{name},
        // Schools/{name}/Config/Profile) — all empty post-Firestore migration,
        // so the page rendered blank. Post-SP-01: 3 Firestore reads
        // (schools/{id} + schoolControl/{id} + systemPlans/{planId}) + a
        // camelCase → Title Case remap pass that preserves the view's
        // existing Title Case API. No view changes required.
        //
        // Field mappings (per pre-flight 2026-06-01 against live data):
        //   schools/{id}.name              → 'School Name'
        //   schools/{id}.logoUrl           → 'Logo'
        //   schools/{id}.street            → 'Address'
        //   schools/{id}.phone/mobile/email/website/city/state/pincode → Title Case
        //   schools/{id}.principal         → 'School Principal'
        //   schools/{id}.affiliationBoard  → 'Affiliated To' (legacy fallback: 'board')
        //   schools/{id}.affiliationNo     → 'Affiliation Number'
        //   Field names match Firestore_service::saveSchool canonical schema —
        //   the only writer for these fields (used by School_config::save_profile).
        //   schoolControl/{id}.subscription.planId → look up systemPlans/{planId}.name
        //   schoolControl/{id}.subscription.periodStart/periodEnd  → duration.startDate/endDate
        //   schoolControl/{id}.subscription.billingCycle           → payment.billingCycle
        //   schoolControl/{id}.lifecycle.state                     → subscription.status + payment.paymentStatus
        //   schoolControl/{id}.billingSummary.lastPaymentAmount    → payment.lastPaymentAmount
        //   schoolControl/{id}.billingSummary.lastPaymentDate      → payment.lastPaymentDate
        //   systemPlans/{planId}.price                             → subscription.amount.totalAmount
        //   systemPlans/{planId}.features                          → subscription.features
        $school_id = $this->school_id;

        $schoolDoc     = $this->fs->get('schools', $school_id) ?? [];
        $schoolControl = $this->fs->get('schoolControl', $school_id) ?? [];
        if (!is_array($schoolDoc))     $schoolDoc     = [];
        if (!is_array($schoolControl)) $schoolControl = [];

        $sub  = is_array($schoolControl['subscription']   ?? null) ? $schoolControl['subscription']   : [];
        $life = is_array($schoolControl['lifecycle']      ?? null) ? $schoolControl['lifecycle']      : [];
        $bill = is_array($schoolControl['billingSummary'] ?? null) ? $schoolControl['billingSummary'] : [];

        // Plan lookup (preserves the pre-SP-01 systemPlans pattern)
        $planData = [];
        $planId   = (string) ($sub['planId'] ?? '');
        if ($planId !== '') {
            try {
                $pd = $this->fs->get('systemPlans', $planId);
                if (is_array($pd)) $planData = $pd;
            } catch (\Exception $e) {
                log_message('error', "SP-01: systemPlans/{$planId} read failed: " . $e->getMessage());
            }
        }

        // Build $schoolData with Title Case keys (view contract preserved)
        $schoolData = [];
        $fsToTitle = [
            'name'              => 'School Name',
            'logoUrl'           => 'Logo',
            'address'           => 'Address',
            'phone'             => 'Phone Number',
            'mobileNumber'      => 'Mobile Number',
            'email'             => 'Email',
            'website'           => 'Website',
            'city'              => 'City',
            'state'             => 'State',
            'pincode'           => 'Pincode',
            'principal'         => 'School Principal',
            'affiliationBoard'  => 'Affiliated To',
            'affiliationNo'     => 'Affiliation Number',
        ];
        foreach ($fsToTitle as $fsKey => $titleKey) {
            if (!empty($schoolDoc[$fsKey])) {
                $schoolData[$titleKey] = $schoolDoc[$fsKey];
            }
        }
        // Legacy fallbacks for fields that have alias names in older docs:
        //   affiliationBoard ← board; address ← street (SA panel writes `street`);
        //   mobileNumber ← mobile.
        if (empty($schoolData['Affiliated To']) && !empty($schoolDoc['board'])) {
            $schoolData['Affiliated To'] = $schoolDoc['board'];
        }
        if (empty($schoolData['Address']) && !empty($schoolDoc['street'])) {
            $schoolData['Address'] = $schoolDoc['street'];
        }
        if (empty($schoolData['Mobile Number']) && !empty($schoolDoc['mobile'])) {
            $schoolData['Mobile Number'] = $schoolDoc['mobile'];
        }

        // Subscription block — schoolControl + systemPlans
        $periodStart  = (string) ($sub['periodStart']  ?? '');
        $periodEnd    = (string) ($sub['periodEnd']    ?? '');
        $billingCycle = (string) ($sub['billingCycle'] ?? 'annual');
        $planName     = (string) ($planData['name']    ?? '');
        $planPrice    = (float)  ($planData['price']   ?? 0);

        // Compute periodInMonths from start/end dates
        $periodInMonths = 0;
        if ($periodStart && $periodEnd) {
            try {
                $d1 = new DateTime($periodStart);
                $d2 = new DateTime($periodEnd);
                $diff = $d1->diff($d2);
                $periodInMonths = $diff->y * 12 + $diff->m;
            } catch (\Throwable $e) {}
        }
        if ($periodInMonths === 0) {
            $periodInMonths = ($billingCycle === 'monthly')   ? 1
                            : (($billingCycle === 'quarterly') ? 3 : 12);
        }

        $monthlyAmount = ($billingCycle === 'monthly') ? $planPrice
                       : (($billingCycle === 'quarterly') ? round($planPrice / 3, 2)
                       : round($planPrice / 12, 2));

        $schoolData['subscription'] = [
            'planName' => $planName,
            'status'   => (string) ($life['state'] ?? ''),
            'duration' => [
                'startDate'      => $periodStart,
                'endDate'        => $periodEnd,
                'periodInMonths' => $periodInMonths,
            ],
            'amount' => [
                'totalAmount' => $planPrice,
                'monthly'     => $monthlyAmount,
            ],
            'features' => is_array($planData['features'] ?? null) ? $planData['features'] : [],
        ];

        // Payment block — schoolControl.billingSummary + lifecycle.state
        $lifecycleState = (string) ($life['state'] ?? '');
        $schoolData['payment'] = [
            'lastPaymentAmount' => $bill['lastPaymentAmount'] ?? 0,
            'lastPaymentDate'   => $bill['lastPaymentDate']   ?? '—',
            'paymentStatus'     => $lifecycleState !== '' ? $lifecycleState : '—',
            'billingCycle'      => $billingCycle,
            'paymentMethod'     => $lifecycleState !== '' ? ucfirst($lifecycleState) : '—',
        ];

        // ── Final fallback: use session display name if School Name still empty ─
        if (empty($schoolData['School Name']) && !empty($this->school_display_name)) {
            $schoolData['School Name'] = $this->school_display_name;
        }

        $startDate = $schoolData['subscription']['duration']['startDate'] ?? null;
        $endDate   = $schoolData['subscription']['duration']['endDate']   ?? null;

        $startDateTimestamp = $startDate ? strtotime($startDate) : null;
        $endDateTimestamp   = $endDate   ? strtotime($endDate)   : null;

        $daysLeft = null;
        if ($endDateTimestamp) {
            $daysLeft = (int)ceil(($endDateTimestamp - time()) / 86400);
            if ($daysLeft < 0) $daysLeft = 0;
        }

        $data['schoolData'] = $schoolData;
        $data['daysLeft']   = $daysLeft;

        $this->load->view('include/header');
        $this->load->view('schoolprofile', $data);
        $this->load->view('include/footer');
    }

    // ── Manage Schools (Super Admin only — list + add) ─────────────────
    public function manage_school()
    {
        if (!in_array($this->admin_role, ['Super Admin', 'School Super Admin'])) {
            show_error('Access denied. Super Admin only.', 403);
            return;
        }
        if ($this->input->method() === 'post') {
            // Legacy school-creation path RETIRED. School onboarding is now
            // canonical via the Super Admin panel (Firestore tenant registry +
            // SSA login provisioning through create_tenant). This form created
            // an RTDB-only school (System/Schools/{name}) with no login account
            // and is no longer supported.
            log_message('info', 'Schools::manage_school legacy add-school POST blocked — onboard via Super Admin panel.');
            echo 'School creation has moved to the Super Admin panel. Please onboard new schools from Super Admin → Schools.';
            return;

        } else {
            // Canonical Firestore tenant list (replaces the legacy
            // Indexes/School_codes + System/Schools RTDB registry). 'School Id'
            // is the SCH_ schoolId so the edit link routes to the Firestore
            // edit path; profile detail fields come from schools/{schoolId}.
            $svc     = $this->_schools_registry();
            $tenants = $svc->list_tenants_summary();
            $schools = [];

            foreach ($tenants as $t) {
                $sid = (string) ($t['schoolId'] ?? '');
                if ($sid === '') continue;
                $doc = $this->fs->get('schools', $sid) ?: [];
                $schools[] = [
                    'School Id'        => $sid,
                    'School Name'      => (string) ($t['schoolName'] ?? $doc['schoolName'] ?? $doc['name'] ?? ''),
                    'School Principal' => (string) ($doc['principal'] ?? ''),
                    'Email'            => (string) ($doc['email'] ?? ''),
                    'Address'          => (string) ($doc['address'] ?? $t['city'] ?? ''),
                    'Affiliated To'    => (string) ($doc['affiliationBoard'] ?? $doc['board'] ?? ''),
                    'Phone Number'     => (string) ($doc['phone'] ?? ''),
                    'Logo'             => ((string) ($t['logoUrl'] ?? $doc['logoUrl'] ?? '')) ?: 'No logo',
                    'subscription'     => ['status' => (string) ($t['subscriptionStatus'] ?? $t['lifecycleState'] ?? '')],
                ];
            }

            $data['Schools']            = $schools;
            $data['currentSchoolCount'] = count($schools);

            $this->load->view('include/header');
            $this->load->view('manage_school', $data);
            $this->load->view('include/footer');
        }
    }

    /** Canonical Firestore tenant-registry accessor (B2_registry_service). */
    private function _schools_registry()
    {
        $this->load->library('b2_registry_service');
        $this->b2_registry_service->init($this->firebase);
        return $this->b2_registry_service;
    }

    // ── School Gallery ────────────────────────────────────────────────────
    public function schoolgallery()
    {
        $this->_require_role(self::VIEW_ROLES, 'view_gallery');
        $this->load->view('include/header');
        $this->load->view('schoolgallery');
        $this->load->view('include/footer');
    }

    // ── Storage quota constants ─────────────────────────────────────────
    // These limits protect storage costs. Adjust per plan tier if needed.
    const GALLERY_LIMITS = [
        'max_images_per_school'  => 200,    // total images across all albums
        'max_videos_per_school'  => 30,     // total videos across all albums
        'max_image_size_mb'      => 3,      // per-file image size limit
        'max_video_size_mb'      => 25,     // per-file video size limit
        'max_files_per_album'    => 50,     // max files in one album
        'max_total_storage_mb'   => 500,    // approx total storage per school
    ];

    // ── Gallery: fetch event albums ─────────────────────────────────────
    public function fetchGalleryAlbums()
    {
        $this->_require_role(self::VIEW_ROLES, 'view_gallery_albums');
        header('Content-Type: application/json');
        $school_name = $this->school_name;

        // 1. Load all events for album listing
        $events = $this->firebase->get("Schools/$school_name/Events/List") ?? [];
        if (!is_array($events)) $events = [];

        // 2. Load all media
        $mediaRoot = $this->firebase->get("Schools/$school_name/Events/Media") ?? [];
        if (!is_array($mediaRoot)) $mediaRoot = [];

        $albums      = [];
        $totalImages = 0;
        $totalVideos = 0;

        // ── Default albums: School Photos & School Videos ────────────
        // These always exist and are shown first.
        $defaultAlbumIds = ['__photos__', '__videos__'];
        foreach ($defaultAlbumIds as $defId) {
            $defMedia = isset($mediaRoot[$defId]) && is_array($mediaRoot[$defId]) ? $mediaRoot[$defId] : [];
            $imgC = 0; $vidC = 0; $cover = '';
            foreach ($defMedia as $m) {
                if (!is_array($m)) continue;
                if (($m['type'] ?? '') === 'image') { $imgC++; if (!$cover) $cover = $m['url'] ?? ''; }
                else { $vidC++; if (!$cover && !empty($m['thumbnail'])) $cover = $m['thumbnail']; }
            }
            $totalImages += $imgC;
            $totalVideos += $vidC;

            $isPhotos = ($defId === '__photos__');
            $albums[] = [
                'event_id'    => $defId,
                'title'       => $isPhotos ? 'School Photos' : 'School Videos',
                'category'    => 'default',
                'start_date'  => '9999-99-99', // always sort first
                'status'      => 'permanent',
                'cover'       => $cover,
                'image_count' => $imgC,
                'video_count' => $vidC,
                'total'       => $imgC + $vidC,
                'icon'        => $isPhotos ? 'fa-camera' : 'fa-video-camera',
                'is_default'  => true,
            ];
        }

        // ── Event albums ─────────────────────────────────────────────
        foreach ($events as $id => $evt) {
            if (!is_array($evt)) continue;
            $eventMedia = isset($mediaRoot[$id]) && is_array($mediaRoot[$id]) ? $mediaRoot[$id] : [];
            $imgCount = 0; $vidCount = 0; $cover = '';
            foreach ($eventMedia as $m) {
                if (!is_array($m)) continue;
                if (($m['type'] ?? '') === 'image') { $imgCount++; if (!$cover) $cover = $m['url'] ?? ''; }
                else { $vidCount++; }
            }
            if (!empty($evt['cover_image'])) $cover = $evt['cover_image'];

            $totalImages += $imgCount;
            $totalVideos += $vidCount;

            $albums[] = [
                'event_id'    => $id,
                'title'       => $evt['title'] ?? $id,
                'category'    => $evt['category'] ?? 'event',
                'start_date'  => $evt['start_date'] ?? '',
                'status'      => $evt['status'] ?? '',
                'cover'       => $cover,
                'image_count' => $imgCount,
                'video_count' => $vidCount,
                'total'       => $imgCount + $vidCount,
            ];
        }

        // ── Legacy gallery ───────────────────────────────────────────
        $legacyPath = "Schools/$school_name/{$this->session_year}/Gallery";
        $legacy     = $this->firebase->get($legacyPath) ?? [];
        if (is_array($legacy) && !empty($legacy)) {
            $lImg = 0; $lVid = 0; $lCover = '';
            foreach ($legacy as $m) {
                if (!is_array($m) || empty($m['image'])) continue;
                if (($m['type'] ?? '') == '1') { $lImg++; if (!$lCover) $lCover = $m['image']; }
                else { $lVid++; }
            }
            if ($lImg + $lVid > 0) {
                $totalImages += $lImg;
                $totalVideos += $lVid;
                $albums[] = [
                    'event_id'    => '__legacy__',
                    'title'       => 'General Gallery',
                    'category'    => 'general',
                    'start_date'  => '',
                    'status'      => '',
                    'cover'       => $lCover,
                    'image_count' => $lImg,
                    'video_count' => $lVid,
                    'total'       => $lImg + $lVid,
                ];
            }
        }

        // Sort: default albums first (9999-99-99), then by start_date desc
        usort($albums, function ($a, $b) {
            return strcmp($b['start_date'], $a['start_date']);
        });

        $limits = self::GALLERY_LIMITS;
        echo json_encode([
            'albums'       => $albums,
            'total_images' => $totalImages,
            'total_videos' => $totalVideos,
            'limits'       => $limits,
        ]);
    }

    // ── Gallery: fetch media for a specific event album ─────────────────
    public function fetchAlbumMedia()
    {
        $this->_require_role(self::VIEW_ROLES, 'view_album_media');
        header('Content-Type: application/json');
        $school_name = $this->school_name;
        $eventId     = trim($this->input->get('event_id') ?? '');

        if (empty($eventId)) {
            echo json_encode(['images' => [], 'videos' => []]);
            return;
        }
        $specialIds = ['__legacy__', '__photos__', '__videos__'];
        if (!in_array($eventId, $specialIds)) {
            $eventId = $this->safe_path_segment($eventId, 'event_id');
        }

        $images = [];
        $videos = [];

        if ($eventId === '__legacy__') {
            // Legacy flat gallery
            $galleryData = $this->firebase->get("Schools/$school_name/{$this->session_year}/Gallery") ?? [];
            if (is_array($galleryData)) {
                foreach ($galleryData as $key => $media) {
                    if (!is_array($media) || empty($media['image'])) continue;
                    $item = [
                        'media_id'  => $key,
                        'url'       => $media['image'],
                        'timestamp' => $media['Time_stamp'] ?? 0,
                    ];
                    if (($media['type'] ?? '') == '1') {
                        $item['type'] = 'image';
                        $images[] = $item;
                    } else {
                        $item['type']      = 'video';
                        $item['thumbnail'] = $media['thumbnail'] ?? '';
                        $item['duration']  = $media['duration'] ?? '';
                        $videos[] = $item;
                    }
                }
            }
        } else {
            // Event-based media
            $mediaData = $this->firebase->get("Schools/$school_name/Events/Media/$eventId") ?? [];
            if (is_array($mediaData)) {
                foreach ($mediaData as $key => $media) {
                    if (!is_array($media) || empty($media['url'])) continue;
                    $item = [
                        'media_id'  => $key,
                        'url'       => $media['url'],
                        'timestamp' => strtotime($media['uploaded_at'] ?? '') ?: 0,
                    ];
                    if (($media['type'] ?? '') === 'image') {
                        $item['type'] = 'image';
                        $images[] = $item;
                    } else {
                        $item['type']      = 'video';
                        $item['thumbnail'] = $media['thumbnail'] ?? '';
                        $item['duration']  = $media['duration'] ?? '';
                        $videos[] = $item;
                    }
                }
            }
        }

        usort($images, fn($a, $b) => ($b['timestamp'] ?? 0) - ($a['timestamp'] ?? 0));
        usort($videos, fn($a, $b) => ($b['timestamp'] ?? 0) - ($a['timestamp'] ?? 0));

        echo json_encode(['images' => $images, 'videos' => $videos]);
    }

    // ── Gallery: delete media ───────────────────────────────────────────
    public function deleteMedia()
    {
        $this->_require_role(self::ADMIN_ROLES, 'delete_media');
        header('Content-Type: application/json');

        $school_name = $this->school_name;
        $fileUrl     = $this->input->get('url');
        $eventId     = trim($this->input->get('event_id') ?? '');
        $mediaId     = trim($this->input->get('media_id') ?? '');

        $specialDeleteIds = ['__legacy__', '__photos__', '__videos__'];
        if ($eventId !== '' && !in_array($eventId, $specialDeleteIds)) {
            $eventId = $this->safe_path_segment($eventId, 'event_id');
        }
        if ($mediaId !== '') $mediaId = $this->safe_path_segment($mediaId, 'media_id');

        if (!$fileUrl) {
            echo json_encode(['status' => 'error', 'message' => 'File URL is required']);
            return;
        }

        try {
            $filePath = $this->extract_firebase_storage_path($fileUrl);
            if (!$filePath) {
                echo json_encode(['status' => 'error', 'message' => 'Invalid file path']);
                return;
            }

            $this->CM->delete_file_from_firebase($filePath);

            if ($eventId === '__legacy__') {
                // Legacy gallery — scan by URL match
                $galleryRef  = "Schools/$school_name/{$this->session_year}/Gallery";
                $galleryData = $this->firebase->get($galleryRef) ?? [];
                if (is_array($galleryData)) {
                    foreach ($galleryData as $key => $media) {
                        if (isset($media['image']) && trim($media['image']) === trim($fileUrl)) {
                            if (!empty($media['thumbnail'])) {
                                $thumbPath = $this->extract_firebase_storage_path($media['thumbnail']);
                                if ($thumbPath) $this->CM->delete_file_from_firebase($thumbPath);
                            }
                            $this->firebase->delete("$galleryRef/$key");
                            break;
                        }
                    }
                }
            } else {
                // Event media — direct path delete
                $mediaPath = "Schools/$school_name/Events/Media/$eventId/$mediaId";
                $existing  = $this->firebase->get($mediaPath);
                if (is_array($existing) && !empty($existing['thumbnail'])) {
                    $thumbPath = $this->extract_firebase_storage_path($existing['thumbnail']);
                    if ($thumbPath) $this->CM->delete_file_from_firebase($thumbPath);
                }
                $this->firebase->delete("Schools/$school_name/Events/Media/$eventId", $mediaId);
            }

            echo json_encode(['status' => 'success', 'message' => 'File deleted successfully']);
        } catch (Exception $e) {
            echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
        }
    }

    // ── Gallery: upload media to event album ────────────────────────────
    public function uploadMedia()
    {
        $this->_require_role(self::ADMIN_ROLES, 'upload_media');
        header('Content-Type: application/json');

        $school_name = $this->school_name;
        $eventId     = trim($this->input->post('event_id') ?? '');

        if (empty($eventId)) {
            echo json_encode(['status' => 'error', 'message' => 'Event/Album ID is required']);
            return;
        }
        $specialIds = ['__photos__', '__videos__'];
        if (!in_array($eventId, $specialIds)) {
            $eventId = $this->safe_path_segment($eventId, 'event_id');
        }
        if (!isset($_FILES['file'])) {
            echo json_encode(['status' => 'error', 'message' => 'No file uploaded']);
            return;
        }

        $file          = $_FILES['file'];
        $fileName      = $file['name'];
        $fileTmpPath   = $file['tmp_name'];
        $fileSize      = $file['size'];
        $fileExtension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
        $fileType      = $this->input->post('type');

        // ── Enforce storage limits ──────────────────────────────────
        $limits                 = self::GALLERY_LIMITS;
        $allowedImageExtensions = ['jpg', 'jpeg', 'png', 'webp'];
        $allowedVideoExtensions = ['mp4', 'mov', 'avi', 'mkv', 'webm'];
        $maxImageSize           = $limits['max_image_size_mb'] * 1024 * 1024;
        $maxVideoSize           = $limits['max_video_size_mb'] * 1024 * 1024;

        if ($fileType == '1' && (!in_array($fileExtension, $allowedImageExtensions) || $fileSize > $maxImageSize)) {
            echo json_encode(['status' => 'error', 'message' => "Invalid image format or size exceeded (max {$limits['max_image_size_mb']}MB). Allowed: jpg, png, webp."]);
            return;
        }
        if ($fileType == '2' && (!in_array($fileExtension, $allowedVideoExtensions) || $fileSize > $maxVideoSize)) {
            echo json_encode(['status' => 'error', 'message' => "Invalid video format or size exceeded (max {$limits['max_video_size_mb']}MB). Allowed: mp4, mov, avi, webm."]);
            return;
        }

        // ── Check per-school quota (total images/videos across all albums) ──
        $mediaRoot = $this->firebase->get("Schools/$school_name/Events/Media") ?? [];
        $totalImages = 0;
        $totalVideos = 0;
        $albumFileCount = 0;
        if (is_array($mediaRoot)) {
            foreach ($mediaRoot as $albumId => $albumMedia) {
                if (!is_array($albumMedia)) continue;
                foreach ($albumMedia as $m) {
                    if (!is_array($m)) continue;
                    if (($m['type'] ?? '') === 'image') $totalImages++;
                    else $totalVideos++;
                    if ($albumId === $eventId) $albumFileCount++;
                }
            }
        }

        if ($fileType == '1' && $totalImages >= $limits['max_images_per_school']) {
            echo json_encode(['status' => 'error', 'message' => "Image limit reached ({$limits['max_images_per_school']} images). Delete some images to upload more."]);
            return;
        }
        if ($fileType == '2' && $totalVideos >= $limits['max_videos_per_school']) {
            echo json_encode(['status' => 'error', 'message' => "Video limit reached ({$limits['max_videos_per_school']} videos). Delete some videos to upload more."]);
            return;
        }
        if ($albumFileCount >= $limits['max_files_per_album']) {
            echo json_encode(['status' => 'error', 'message' => "This album has reached its limit ({$limits['max_files_per_album']} files). Use another album or delete files."]);
            return;
        }

        $timestamp    = time();
        $randomString = substr(md5(uniqid(mt_rand(), true)), 0, 6);
        $safeEvent    = preg_replace('/[^A-Za-z0-9_\-]/', '_', $eventId);
        // Canonical Storage scheme: schools/{schoolId}/events/... (ID-keyed,
        // rename-proof). Previously rooted at the bare school NAME.
        $storagePath  = "schools/{$this->school_id}/events/{$safeEvent}/";
        $prefix       = ($fileType == '1') ? 'img_' : 'vid_';
        $newFileName  = "{$prefix}{$timestamp}_{$randomString}.{$fileExtension}";
        $firebasePath = $storagePath . $newFileName;

        $uploadResult = $this->firebase->uploadFile($fileTmpPath, $firebasePath);
        if ($uploadResult !== true) {
            echo json_encode(['status' => 'error', 'message' => $uploadResult]);
            return;
        }

        $downloadUrl = $this->firebase->getDownloadUrl($firebasePath);
        $mediaId     = "{$prefix}{$timestamp}_{$randomString}";
        $mediaData   = [
            'media_id'    => $mediaId,
            'type'        => ($fileType == '1') ? 'image' : 'video',
            'url'         => $downloadUrl,
            'uploaded_at' => date('c'),
            'uploaded_by' => $this->admin_id,
        ];

        if ($fileType == '2') {
            $ffmpeg  = defined('FFMPEG_PATH')  ? FFMPEG_PATH  : 'ffmpeg';
            $ffprobe = defined('FFPROBE_PATH') ? FFPROBE_PATH : 'ffprobe';

            $durationCmd    = "\"$ffprobe\" -v error -select_streams v:0 -show_entries stream=duration -of default=noprint_wrappers=1:nokey=1 " . escapeshellarg($fileTmpPath);
            $durationOutput = shell_exec($durationCmd);
            $durationSecs   = is_numeric(trim($durationOutput ?? '')) ? round((float)trim($durationOutput), 2) : 0;
            $minutes        = (int)floor($durationSecs / 60);
            $seconds        = (int)round($durationSecs - ($minutes * 60));
            if ($seconds === 60) { $minutes++; $seconds = 0; }
            $mediaData['duration'] = sprintf('%d:%02d', $minutes, $seconds);

            $thumbName  = "thumb_{$timestamp}_{$randomString}.jpg";
            $thumbLocal = sys_get_temp_dir() . DIRECTORY_SEPARATOR . $thumbName;
            $thumbCmd   = "\"$ffmpeg\" -i " . escapeshellarg($fileTmpPath) . " -ss 00:00:01.000 -vframes 1 -q:v 2 " . escapeshellarg($thumbLocal);
            shell_exec($thumbCmd);

            if (file_exists($thumbLocal)) {
                $thumbStoragePath       = $storagePath . "thumbnails/" . $thumbName;
                $this->firebase->uploadFile($thumbLocal, $thumbStoragePath);
                $mediaData['thumbnail'] = $this->firebase->getDownloadUrl($thumbStoragePath);
                @unlink($thumbLocal);
            }
        }

        $dbPath = "Schools/$school_name/Events/Media/$eventId";
        $this->firebase->update($dbPath, [$mediaId => $mediaData]);

        echo json_encode([
            'status'    => 'success',
            'message'   => 'File uploaded successfully',
            'mediaData' => $mediaData,
        ]);
    }

    // ── Gallery: set event cover image ──────────────────────────────────
    public function setEventCover()
    {
        $this->_require_role(self::ADMIN_ROLES, 'set_event_cover');
        header('Content-Type: application/json');
        $school_name = $this->school_name;
        $eventId     = trim($this->input->post('event_id') ?? '');
        $coverUrl    = $this->input->post('cover_url');

        if (empty($eventId) || empty($coverUrl)) {
            echo json_encode(['status' => 'error', 'message' => 'Missing event ID or cover URL']);
            return;
        }
        $eventId = $this->safe_path_segment($eventId, 'event_id');

        $this->firebase->update("Schools/$school_name/Events/List/$eventId", [
            'cover_image' => $coverUrl,
        ]);

        echo json_encode(['status' => 'success', 'message' => 'Cover image set successfully']);
    }

    // ── Private helpers ───────────────────────────────────────────────────
    private function extract_firebase_storage_path($url)
    {
        $parsedUrl = parse_url($url);
        if (!isset($parsedUrl['path'])) return null;

        $pos = strpos($parsedUrl['path'], '/o/');
        if ($pos === false) return null;

        return urldecode(substr($parsedUrl['path'], $pos + 3));
    }
}