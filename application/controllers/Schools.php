<?php
defined('BASEPATH') or exit('No direct script access allowed');

class Schools extends MY_Controller
{
    /** Roles for school management */
    private const ADMIN_ROLES = ['Super Admin', 'School Super Admin', 'Admin', 'Principal', 'Vice Principal'];

    /** Roles that may view school data */
    private const VIEW_ROLES  = ['Super Admin', 'School Super Admin', 'Admin', 'Principal', 'Vice Principal', 'Academic Coordinator', 'HR Manager', 'Accountant', 'Class Teacher', 'Teacher', 'Front Office'];

    /** Gallery endpoints belong to the Events feature, not school Configuration. */
    private const GALLERY_METHODS = [
        'schoolgallery', 'fetchGalleryAlbums', 'fetchAlbumMedia',
        'uploadMedia', 'deleteMedia', 'setEventCover',
    ];

    public function __construct()
    {
        parent::__construct();
        // The gallery lives inside this controller for historical reasons but is
        // logically part of Events (the Events page deep-links "Add Photos" into
        // it). Gate those methods on the 'Events' RBAC module so a role granted
        // Events — but not the unrelated school 'Configuration' module — can
        // manage gallery media. Every other Schools method stays Configuration-
        // gated. (Fixes audit M1: RBAC module mismatch.)
        $method = $this->router->fetch_method();
        if (in_array($method, self::GALLERY_METHODS, true)) {
            require_permission('Events');
        } else {
            require_permission('Configuration');
        }
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
    //  6. deleteMedia(): now POST (was DELETE+query params). CI3 csrf_verify
    //     only guards POST, so the delete is CSRF-protected, and inputs are
    //     read via $this->input->post(). A tenant-ownership check on the
    //     extracted Storage path blocks cross-tenant deletes (Admin SDK
    //     bypasses Storage rules).
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
        $this->_require_role(self::VIEW_ROLES, 'view_fees', 'Configuration', 'view');
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
        $this->_require_role(self::VIEW_ROLES, 'view_school_profile', 'Configuration', 'view');
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
    //
    // GALLERY ↔ APPS CONSOLIDATION (2026-07):
    //   The admin gallery historically stored media only in RTDB
    //   (Schools/{schoolId}/Events/Media) and built its album list from RTDB
    //   Events/List — but events now live in Firestore `events`, and the apps
    //   read gallery ONLY from Firestore galleryAlbums + galleryMedia. So the
    //   admin uploads never reached the apps.
    //
    //   Fix: keep the existing RTDB writes (dual-write, nothing regresses) and
    //   ALSO write the canonical Firestore contract via Entity_firestore_sync,
    //   best-effort/logged so a Firestore hiccup never breaks the live admin UI.
    //   The event-album picker now sources events from Firestore `events`.
    //
    //   Album id convention: albumId === eventId. For a real event that is the
    //   EVT id (source="event"), for the built-in buckets it is the special id
    //   (__photos__ / __videos__ / __legacy__ → source="general").

    /** Lazy-load + init the RTDB→Firestore sync helper (gallery dual-write). */
    private function _entity_sync()
    {
        if (!isset($this->entity_sync)) {
            $this->load->library('entity_firestore_sync', null, 'entity_sync');
            $this->entity_sync->init(
                $this->firebase, $this->school_name, $this->session_year, (string) ($this->school_code ?? '')
            );
        }
        return $this->entity_sync;
    }

    /**
     * Resolve album metadata (source / eventId / title / category) for a given
     * upload target id. Built-in buckets are "general"; anything else is an
     * event album whose title/category are pulled from the Firestore event doc.
     */
    private function _gallery_album_meta(string $eventId): array
    {
        $defaults = [
            '__photos__' => ['title' => 'School Photos', 'category' => 'general'],
            '__videos__' => ['title' => 'School Videos', 'category' => 'general'],
            '__legacy__' => ['title' => 'General Gallery', 'category' => 'general'],
        ];
        if (isset($defaults[$eventId])) {
            return [
                'source'   => 'general',
                'eventId'  => '',
                'title'    => $defaults[$eventId]['title'],
                'category' => $defaults[$eventId]['category'],
            ];
        }

        $title = $eventId;
        $category = 'event';
        try {
            $evt = $this->fs->getEntity('events', $eventId);
            if (is_array($evt)) {
                $title    = (string) ($evt['title'] ?? $eventId);
                $category = (string) ($evt['category'] ?? 'event');
            }
        } catch (\Throwable $e) {
            log_message('error', 'Schools::_gallery_album_meta event lookup failed: ' . $e->getMessage());
        }
        return ['source' => 'event', 'eventId' => $eventId, 'title' => $title, 'category' => $category];
    }

    public function schoolgallery()
    {
        $this->_require_role(self::VIEW_ROLES, 'view_gallery', 'Events', 'view');
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
    /**
     * Aggregate canonical Firestore `galleryMedia` (the docs the apps read),
     * grouped by albumId → ['img'=>n, 'vid'=>n, 'cover'=>url]. One query, no
     * RTDB. This is the count/cover authority that REPLACED the world-open RTDB
     * `Schools/{school}/Events/Media` tree (Critical C2). Cover = first image
     * url, else first video thumbnail. Archived media is excluded.
     */
    private function _galleryMediaByAlbum(): array
    {
        $out = [];
        foreach ((array) $this->firebase->firestoreQuery('galleryMedia', [
                    ['schoolId', '==', $this->school_id],
                ]) as $row) {
            $m = (is_array($row) && isset($row['data']) && is_array($row['data'])) ? $row['data'] : (is_array($row) ? $row : []);
            if (!empty($m['isArchived'])) continue;
            $aid = (string) ($m['albumId'] ?? '');
            if ($aid === '') continue;
            if (!isset($out[$aid])) $out[$aid] = ['img' => 0, 'vid' => 0, 'cover' => '', 'cover_ts' => -1, 'urls' => []];

            $isVideo   = (string) ($m['type'] ?? '') === 'video';
            $candidate = $isVideo ? (string) ($m['thumbnail'] ?? '') : (string) ($m['url'] ?? '');
            $ts        = strtotime((string) ($m['uploadedAt'] ?? '')) ?: 0;

            if ($isVideo) $out[$aid]['vid']++; else $out[$aid]['img']++;

            // Track every live cover-eligible URL so a stale album coverImage
            // (one pointing at a since-deleted/expired file) can be detected.
            if (!empty($m['url']))       $out[$aid]['urls'][(string) $m['url']]       = true;
            if (!empty($m['thumbnail'])) $out[$aid]['urls'][(string) $m['thumbnail']] = true;

            // Cover = the LATEST media (by uploadedAt) that has a usable image.
            if ($candidate !== '' && $ts >= $out[$aid]['cover_ts']) {
                $out[$aid]['cover']    = $candidate;
                $out[$aid]['cover_ts'] = $ts;
            }
        }
        return $out;
    }

    /**
     * Resolve the cover to show for an album under the "always latest" policy:
     * the cover tracks the most recent media UNLESS an admin pinned a specific
     * one via setEventCover (coverPinned) and that pinned file still exists.
     * When the displayed cover differs from the canonical galleryAlbums.coverImage
     * we self-heal that field (and release a dead pin) so the apps — which read
     * coverImage directly — match the grid and never show a stale/blank thumbnail.
     */
    private function _resolve_album_cover(string $albumId, array $agg, array $albumDoc): string
    {
        $computed = (string) ($agg['cover'] ?? '');   // latest media (by uploadedAt)
        $explicit = (string) ($albumDoc['coverImage'] ?? '');
        $pinned   = !empty($albumDoc['coverPinned']);
        $urls     = (array)  ($agg['urls'] ?? []);

        // A live pin wins.
        if ($pinned && $explicit !== '' && isset($urls[$explicit])) return $explicit;

        // Otherwise track the latest media, healing the canonical field when it
        // drifted (legacy first-image cover, a since-deleted cover, or a released
        // pin). Only write when we actually have a live replacement.
        if ($computed !== '' && $explicit !== $computed) {
            try { $this->_entity_sync()->updateGalleryAlbumCover($albumId, $computed, false); }
            catch (\Throwable $e) { log_message('error', "Schools::_resolve_album_cover heal [{$albumId}] failed: " . $e->getMessage()); }
        }
        return $computed !== '' ? $computed : $explicit;
    }

    public function fetchGalleryAlbums()
    {
        $this->_require_role(self::VIEW_ROLES, 'view_gallery_albums', 'Events', 'view');
        header('Content-Type: application/json');
        $school_name = $this->school_name;

        // 1. Event list — CORE C1 FIX (2026-07): events now live in Firestore
        //    `events`, not the dead RTDB Events/List node. Source the event-album
        //    picker from Firestore so gallery albums line up with real events
        //    (and therefore with the apps). schoolId === school_name === SCH_ id.
        $eventRows = $this->firebase->firestoreQuery(
            'events', [['schoolId', '==', $this->school_id]], 'startDate', 'DESC'
        );
        $events = [];
        foreach ((array) $eventRows as $row) {
            // firestoreQuery returns rows shaped ['id'=>docId,'data'=>[...]]; the
            // fields (title/status/…) live under `data`. Reading them flat left
            // every event album blank + named by its raw id. Unwrap, and recover
            // the RAW event id (strip "{schoolId}_") so album keys match the apps
            // (which query galleryAlbums by eventId == raw EVT id) and uploads land
            // in the right album.
            $e    = (is_array($row) && isset($row['data']) && is_array($row['data'])) ? $row['data'] : (is_array($row) ? $row : []);
            $full = (string) ($row['id'] ?? $e['id'] ?? '');
            $eid  = (string) ($e['eventId'] ?? '');
            if ($eid === '' && $full !== '') {
                $eid = (strpos($full, $this->school_id . '_') === 0) ? substr($full, strlen($this->school_id) + 1) : $full;
            }
            if ($eid === '') continue;
            $events[$eid] = [
                'title'      => (string) ($e['title'] ?? $eid),
                'category'   => (string) ($e['category'] ?? 'event'),
                'start_date' => (string) ($e['start_date'] ?? $e['startDate'] ?? ''),
                'status'     => (string) ($e['status'] ?? ''),
            ];
        }

        // 2. Canonical album metadata (coverImage / title) from Firestore
        //    galleryAlbums — the very docs the apps read. Keyed by albumId.
        $fsAlbums = [];
        foreach ((array) $this->firebase->firestoreQuery('galleryAlbums', [['schoolId', '==', $this->school_id]]) as $row) {
            $a = (is_array($row) && isset($row['data']) && is_array($row['data'])) ? $row['data'] : (is_array($row) ? $row : []);
            $aid = (string) ($a['albumId'] ?? '');
            if ($aid !== '') $fsAlbums[$aid] = $a;
        }

        // 3. Per-album image/video split + cover — sourced from the canonical
        //    Firestore `galleryMedia` (the very docs the apps read). This REPLACES
        //    the legacy world-open RTDB `Events/Media` tree (Critical C2). One
        //    query, grouped by albumId. `galleryAlbums.mediaCount` is only a single
        //    total, so we count image/video from galleryMedia here.
        $mediaByAlbum = $this->_galleryMediaByAlbum();

        $albums      = [];
        $totalImages = 0;
        $totalVideos = 0;

        // ── Default albums: School Photos & School Videos ────────────
        // These always exist and are shown first.
        $defaultAlbumIds = ['__photos__', '__videos__'];
        foreach ($defaultAlbumIds as $defId) {
            $agg  = $mediaByAlbum[$defId] ?? ['img' => 0, 'vid' => 0, 'cover' => ''];
            $imgC = (int) $agg['img']; $vidC = (int) $agg['vid']; $cover = (string) $agg['cover'];
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

        // ── Event albums (driven by Firestore events) ────────────────
        // Split counts + cover come from galleryMedia; the canonical Firestore
        // coverImage (set via setEventCover) wins for cover when present.
        // Upload picker options: EVERY event (title/date/status), so the admin can
        // start a photo album for any event — including completed/ongoing ones —
        // without empty event cards cluttering the album grid.
        $eventOptions = [];
        foreach ($events as $id => $evt) {
            $eventOptions[] = [
                'event_id'   => $id,
                'title'      => $evt['title'],
                'start_date' => $evt['start_date'],
                'status'     => $evt['status'],
                'category'   => $evt['category'],
            ];
        }

        $consumedMediaKeys = ['__photos__' => true, '__videos__' => true];
        foreach ($events as $id => $evt) {
            $consumedMediaKeys[$id] = true;
            $agg      = $mediaByAlbum[$id] ?? ['img' => 0, 'vid' => 0, 'cover' => '', 'urls' => []];
            $imgCount = (int) $agg['img']; $vidCount = (int) $agg['vid'];
            $cover    = $this->_resolve_album_cover($id, $agg, $fsAlbums[$id] ?? []);

            // Grid shows only event albums that actually HAVE media. Empty events
            // live in the upload picker instead.
            if ($imgCount + $vidCount === 0) continue;

            $totalImages += $imgCount;
            $totalVideos += $vidCount;

            $albums[] = [
                'event_id'    => $id,
                'title'       => $evt['title'],
                'category'    => $evt['category'],
                'start_date'  => $evt['start_date'],
                'status'      => $evt['status'],
                'cover'       => $cover,
                'image_count' => $imgCount,
                'video_count' => $vidCount,
                'total'       => $imgCount + $vidCount,
            ];
        }

        // ── Orphaned albums ──────────────────────────────────────────
        // galleryMedia grouped under an albumId that is neither a default album
        // nor a current Firestore event (e.g. pre-cutover ids). Surface them so
        // their media still displays.
        foreach ($mediaByAlbum as $rid => $agg) {
            $rid = (string) $rid;
            if ($rid === '' || isset($consumedMediaKeys[$rid])) continue;
            $imgCount = (int) $agg['img']; $vidCount = (int) $agg['vid'];
            if ($imgCount + $vidCount === 0) continue;
            $cover = $this->_resolve_album_cover($rid, $agg, $fsAlbums[$rid] ?? []);

            $totalImages += $imgCount;
            $totalVideos += $vidCount;

            $albums[] = [
                'event_id'    => $rid,
                'title'       => (string) ($fsAlbums[$rid]['title'] ?? $rid),
                'category'    => (string) ($fsAlbums[$rid]['category'] ?? 'event'),
                'start_date'  => '',
                'status'      => 'archived',
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
            'albums'        => $albums,
            'event_options' => $eventOptions,
            'total_images'  => $totalImages,
            'total_videos'  => $totalVideos,
            'limits'        => $limits,
        ]);
    }

    // ── Gallery: fetch media for a specific event album ─────────────────
    public function fetchAlbumMedia()
    {
        $this->_require_role(self::VIEW_ROLES, 'view_album_media', 'Events', 'view');
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
            // Legacy flat gallery (distinct pre-Events RTDB path; harmless
            // read-only fallback kept until confirmed empty for all schools).
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
            // Event + default albums — canonical Firestore `galleryMedia` (the
            // docs the apps read). This REPLACES the world-open RTDB Events/Media
            // read (Critical C2); Firestore is now the sole authority.
            foreach ((array) $this->firebase->firestoreQuery('galleryMedia', [
                        ['schoolId', '==', $this->school_id],
                        ['albumId',  '==', $eventId],
                    ]) as $row) {
                $m = (is_array($row) && isset($row['data']) && is_array($row['data'])) ? $row['data'] : (is_array($row) ? $row : []);
                if (!empty($m['isArchived'])) continue;
                $url = (string) ($m['url'] ?? '');
                if ($url === '') continue;
                // galleryMedia doc-id is "{albumId}_{mediaId}". Return the RAW
                // mediaId (strip the album prefix) so deleteMedia's
                // syncDeleteGalleryMedia rebuilds "{albumId}_{mediaId}" correctly.
                $rawMediaId  = (string) ($row['id'] ?? '');
                $albumPrefix = $eventId . '_';
                if ($albumPrefix !== '_' && strpos($rawMediaId, $albumPrefix) === 0) {
                    $rawMediaId = substr($rawMediaId, strlen($albumPrefix));
                }
                $item = [
                    'media_id'  => $rawMediaId,
                    'url'       => $url,
                    'timestamp' => strtotime((string) ($m['uploadedAt'] ?? '')) ?: 0,
                ];
                if ((string) ($m['type'] ?? '') === 'video') {
                    $item['type']      = 'video';
                    $item['thumbnail'] = (string) ($m['thumbnail'] ?? '');
                    $item['duration']  = (string) ($m['duration'] ?? '');
                    $videos[] = $item;
                } else {
                    $item['type'] = 'image';
                    $images[] = $item;
                }
            }
        }

        usort($images, fn($a, $b) => ($b['timestamp'] ?? 0) - ($a['timestamp'] ?? 0));
        usort($videos, fn($a, $b) => ($b['timestamp'] ?? 0) - ($a['timestamp'] ?? 0));

        echo json_encode(['images' => $images, 'videos' => $videos]);
    }

    /**
     * True when a Firebase Storage object path belongs to the CALLER's school,
     * across every scheme a school's media can live under. This is the single
     * cross-tenant gate for gallery delete / cover operations — every branch is
     * anchored to the caller's own {schoolId} (or, for the legacy events tree,
     * school_name == SCH id), so it can never authorize another tenant's file.
     *
     * Schemes (see firebase-rules/storage.rules + Storage_path_map):
     *   schools/{schoolId}/...             admin-panel uploads (canonical)
     *   {schoolId}/Events/...              legacy RTDB event media
     *   galleryMedia/{schoolId}/...        Teacher/Parent app gallery uploads
     *   stories/{schoolId}/...             app story media surfaced in a gallery album
     *   stories/admin/{schoolId}/...       admin story media
     */
    private function _storage_path_owned_by_caller(string $filePath): bool
    {
        $sid  = (string) $this->school_id;
        $name = (string) $this->school_name;

        if ($sid !== '') {
            if (strpos($filePath, "schools/{$sid}/")       === 0) return true;
            if (strpos($filePath, "galleryMedia/{$sid}/")  === 0) return true;
            if (strpos($filePath, "stories/{$sid}/")       === 0) return true;
            if (strpos($filePath, "stories/admin/{$sid}/") === 0) return true;
        }
        if ($name !== '' && strpos($filePath, "{$name}/Events/") === 0) return true;

        return false;
    }

    /**
     * Repoint an album's cover at its latest remaining media when the current
     * galleryAlbums.coverImage no longer matches any live media in the album
     * (e.g. the covering photo/video was just deleted, or it referenced an
     * expired story file). Clears the cover when nothing is left. No-op when the
     * cover is still valid, so an admin's explicit setEventCover choice stands.
     * Video covers use the poster thumbnail; images use the url. Latest wins.
     */
    private function _reset_album_cover_if_stale(string $albumId): void
    {
        if ($albumId === '' || in_array($albumId, ['__legacy__', '__photos__', '__videos__'], true)) return;

        try {
            $albumDoc = $this->firebase->firestoreGet('galleryAlbums', "{$this->school_id}_{$albumId}");
            $cover    = is_array($albumDoc) ? (string) ($albumDoc['coverImage'] ?? '') : '';
            if ($cover === '') return; // nothing set — grid computes a cover from media

            $liveUrls  = [];   // every cover-eligible URL still in the album
            $newCover  = '';   // latest remaining, as fallback
            $latestTs  = -1;
            foreach ((array) $this->firebase->firestoreQuery('galleryMedia', [
                        ['schoolId', '==', $this->school_id],
                        ['albumId',  '==', $albumId],
                    ]) as $row) {
                $m = (is_array($row) && isset($row['data']) && is_array($row['data'])) ? $row['data'] : (is_array($row) ? $row : []);
                if (!empty($m['isArchived'])) continue;
                $isVideo   = (string) ($m['type'] ?? '') === 'video';
                $candidate = $isVideo ? (string) ($m['thumbnail'] ?? '') : (string) ($m['url'] ?? '');
                if (!empty($m['url']))       $liveUrls[(string) $m['url']]       = true;
                if (!empty($m['thumbnail'])) $liveUrls[(string) $m['thumbnail']] = true;
                if ($candidate === '') continue;
                $ts = strtotime((string) ($m['uploadedAt'] ?? '')) ?: 0;
                if ($ts >= $latestTs) { $latestTs = $ts; $newCover = $candidate; }
            }

            // Cover still points at a live media item → leave it (respects a
            // deliberate setEventCover pick).
            if (isset($liveUrls[$cover])) return;

            // Stale cover → repoint at latest remaining (or '' clears it) and
            // release any pin, since the pinned file no longer exists.
            $this->_entity_sync()->updateGalleryAlbumCover($albumId, $newCover, false);
        } catch (\Throwable $e) {
            log_message('error', "Schools::_reset_album_cover_if_stale [{$albumId}] failed: " . $e->getMessage());
        }
    }

    // ── Gallery: delete media ───────────────────────────────────────────
    public function deleteMedia()
    {
        $this->_require_role(self::ADMIN_ROLES, 'delete_media', 'Events', 'manage');
        header('Content-Type: application/json');

        $school_name = $this->school_name;
        // FIX-2 (CSRF): state-changing delete now arrives via POST so CI3's
        // csrf_verify (POST-only) actually protects it. Reads switched to post().
        $fileUrl     = $this->input->post('url');
        $eventId     = trim($this->input->post('event_id') ?? '');
        $mediaId     = trim($this->input->post('media_id') ?? '');

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

            // FIX-1 (CROSS-TENANT DELETE IDOR): the Admin SDK delete bypasses
            // Storage rules, so we MUST assert the extracted path belongs to the
            // caller's school before touching anything. A gallery album can
            // reference media stored under ANY of the school's Storage schemes
            // (admin uploads, legacy events, app gallery uploads, or a story the
            // photo was sourced from), so we validate against every school-owned
            // scheme. Anything else is a cross-tenant probe: reject, log, delete NOTHING.
            $ownedByCaller = $this->_storage_path_owned_by_caller($filePath);

            if (!$ownedByCaller) {
                // Mirror Homework::CROSS_TENANT_PROBE telemetry pattern.
                $this->load->library('security_telemetry', null, 'sec_telem');
                if (isset($this->sec_telem)) {
                    $this->sec_telem->init(
                        $this->firebase,
                        (string) $this->school_id,
                        ['uid' => $this->admin_id, 'role' => $this->admin_role]
                    );
                    if ($this->sec_telem->isReady()) {
                        $this->sec_telem->emit('CROSS_TENANT_PROBE', 'warning', [
                            'endpoint'        => 'Schools::deleteMedia',
                            'attempted_path'  => $filePath,
                            'attempted_url'   => (string) $fileUrl,
                            'actor_school_id' => (string) $this->school_id,
                        ]);
                    }
                }
                log_message('error',
                    "Schools::deleteMedia CROSS_TENANT_PROBE — actor school=[{$this->school_id}]"
                    . " admin=[{$this->admin_id}] attempted path=[{$filePath}]"
                );
                http_response_code(403);
                echo json_encode(['status' => 'error', 'message' => 'You are not allowed to delete this file.']);
                return;
            }

            $this->CM->delete_file_from_firebase($filePath);

            // $fsMediaKey = the media doc-id component to remove from Firestore
            // galleryMedia ({albumId}_{mediaId}); resolved per-branch below.
            $fsMediaKey = $mediaId;

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
                            $fsMediaKey = (string) $key;
                            break;
                        }
                    }
                }
            } else {
                // Event/default album media — Firestore is authoritative now
                // (RTDB Events/Media removed, Critical C2).
                //
                // FIX-1b (CROSS-TENANT DELETE IDOR — Firestore leg): the Storage
                // guard above only proves the caller owns the `url` param. But
                // event_id/media_id — which key THIS galleryMedia doc and its
                // thumbnail — are independent client input. Without a check an
                // attacker can pass a self-owned `url` plus a VICTIM's
                // event_id/media_id and delete another school's galleryMedia doc
                // + thumbnail (galleryMedia doc-ids are NOT schoolId-namespaced,
                // and eventId is a small per-school sequential counter that
                // collides across tenants). Read the doc first and hard-assert
                // its schoolId matches the caller before deleting anything.
                $doc = null;
                try {
                    $doc = $this->firebase->firestoreGet('galleryMedia', "{$eventId}_{$mediaId}");
                } catch (\Throwable $e) {
                    log_message('error', 'Schools::deleteMedia galleryMedia lookup failed [' . $eventId . '/' . $mediaId . ']: ' . $e->getMessage());
                }
                if (is_array($doc)) {
                    $docSchool = (string) ($doc['schoolId'] ?? '');
                    // D8 (2026-07-12): fail CLOSED on schoolId mismatch OR empty.
                    // An empty schoolId no longer passes: galleryMedia doc-ids are
                    // not schoolId-namespaced, so a self-owned url + a victim's
                    // event_id/media_id targeting a legacy/empty-schoolId doc could
                    // otherwise delete another tenant's row. Empty == not provably
                    // ours → reject. (Legit ZenXii docs always stamp schoolId.)
                    if ($docSchool !== (string) $this->school_id) {
                        $this->_deletemedia_reject_cross_tenant($eventId, $mediaId, $docSchool);
                        return;
                    }
                    if (!empty($doc['thumbnail'])) {
                        $thumbPath = $this->extract_firebase_storage_path((string) $doc['thumbnail']);
                        if ($thumbPath) $this->CM->delete_file_from_firebase($thumbPath);
                    }
                }
            }

            // ── Firestore delete (canonical) ─────────────────────────────
            // Remove the galleryMedia doc and decrement the album mediaCount so
            // the apps stay in sync. Best-effort/logged: a Firestore failure must
            // NOT fail the request (the Storage file is already gone).
            if ($eventId !== '' && $fsMediaKey !== '') {
                try {
                    $sync = $this->_entity_sync();
                    if ($sync->syncDeleteGalleryMedia($eventId, $fsMediaKey)) {
                        $sync->bumpGalleryAlbumCount($eventId, -1);
                    }
                } catch (\Throwable $e) {
                    log_message('error', 'Schools::deleteMedia Firestore gallery sync failed [' . $eventId . '/' . $fsMediaKey . ']: ' . $e->getMessage());
                }
            }

            // If the media we just removed was the album's cover, the canonical
            // galleryAlbums.coverImage now points at a dead file — the apps and
            // gallery grid would render a blank thumbnail. Repoint it at the
            // latest remaining media (or clear it when the album is now empty).
            $this->_reset_album_cover_if_stale($eventId);

            echo json_encode(['status' => 'success', 'message' => 'File deleted successfully']);
        } catch (Exception $e) {
            echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
        }
    }

    /**
     * Emit CROSS_TENANT_PROBE telemetry + 403 for a deleteMedia attempt whose
     * Firestore galleryMedia doc belongs to a different school than the caller.
     * Caller must `return` immediately after invoking this (response is sent).
     */
    private function _deletemedia_reject_cross_tenant($eventId, $mediaId, $docSchool)
    {
        $this->load->library('security_telemetry', null, 'sec_telem');
        if (isset($this->sec_telem)) {
            $this->sec_telem->init(
                $this->firebase,
                (string) $this->school_id,
                ['uid' => $this->admin_id, 'role' => $this->admin_role]
            );
            if ($this->sec_telem->isReady()) {
                $this->sec_telem->emit('CROSS_TENANT_PROBE', 'warning', [
                    'endpoint'        => 'Schools::deleteMedia',
                    'attempted_doc'   => "galleryMedia/{$eventId}_{$mediaId}",
                    'doc_school_id'   => (string) $docSchool,
                    'actor_school_id' => (string) $this->school_id,
                ]);
            }
        }
        log_message('error',
            "Schools::deleteMedia CROSS_TENANT_PROBE (Firestore) — actor school=[{$this->school_id}]"
            . " admin=[{$this->admin_id}] doc=[galleryMedia/{$eventId}_{$mediaId}] doc_school=[{$docSchool}]"
        );
        http_response_code(403);
        echo json_encode(['status' => 'error', 'message' => 'You are not allowed to delete this file.']);
    }

    // ── Gallery: upload media to event album ────────────────────────────
    public function uploadMedia()
    {
        $this->_require_role(self::ADMIN_ROLES, 'upload_media', 'Events', 'manage');
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

        // ── Guard the PHP upload itself (FIX) ────────────────────────
        // A partial/failed upload (over upload_max_filesize, aborted, etc.)
        // leaves a missing/invalid tmp file; surface it before reading size.
        if (!isset($file['error']) || $file['error'] !== UPLOAD_ERR_OK) {
            echo json_encode(['status' => 'error', 'message' => 'File upload failed or was incomplete. Please try again.']);
            return;
        }

        $fileName      = $file['name'];
        $fileTmpPath   = $file['tmp_name'];
        $fileSize      = $file['size'];
        $fileExtension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
        $fileType      = $this->input->post('type');

        // ── Fail closed on any unknown media type (FIX — H1) ─────────
        // EVERY gate below is `if ($fileType == '1'|'2')`, so a missing or
        // other value (e.g. '3', '') would skip extension, size, MIME sniff
        // AND quota — allowing an unbounded arbitrary file. Reject up front.
        if ($fileType !== '1' && $fileType !== '2') {
            echo json_encode(['status' => 'error', 'message' => 'Invalid or missing media type.']);
            return;
        }

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

        // ── Server-side content MIME verification (FIX-5) ────────────
        // Extension + client-sent type are trivially spoofable. Sniff the
        // real MIME from the file bytes via finfo and reject any mismatch.
        $allowedImageMimes = ['image/jpeg', 'image/png', 'image/webp'];
        $allowedVideoMimes = ['video/mp4', 'video/quicktime', 'video/x-msvideo',
                              'video/avi', 'video/x-matroska', 'video/webm'];
        $realMime = '';
        if (function_exists('finfo_open')) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            if ($finfo !== false) {
                $realMime = (string) finfo_file($finfo, $fileTmpPath);
                finfo_close($finfo);
            }
        }
        if ($realMime === '') {
            echo json_encode(['status' => 'error', 'message' => 'Could not verify file content type.']);
            return;
        }
        if ($fileType == '1' && !in_array($realMime, $allowedImageMimes, true)) {
            echo json_encode(['status' => 'error', 'message' => "File content is not a valid image (detected: {$realMime})."]);
            return;
        }
        if ($fileType == '2' && !in_array($realMime, $allowedVideoMimes, true)) {
            echo json_encode(['status' => 'error', 'message' => "File content is not a valid video (detected: {$realMime})."]);
            return;
        }

        // ── Check per-school quota (total images/videos across all albums) ──
        // Sourced from canonical Firestore galleryMedia (RTDB Events/Media removed,
        // Critical C2). One query, grouped by album.
        $mediaByAlbum = $this->_galleryMediaByAlbum();
        $totalImages = 0;
        $totalVideos = 0;
        $albumFileCount = 0;
        foreach ($mediaByAlbum as $albumId => $agg) {
            $totalImages += (int) $agg['img'];
            $totalVideos += (int) $agg['vid'];
            if ($albumId === $eventId) $albumFileCount += (int) $agg['img'] + (int) $agg['vid'];
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

        // ── Firestore write (canonical — RTDB Events/Media removed, C2) ──
        // The apps AND the admin gallery now read gallery ONLY from Firestore
        // galleryAlbums/galleryMedia. albumId === $eventId. On failure the
        // uploaded Storage file is rolled back so we never orphan a file.
        try {
            $sync = $this->_entity_sync();
            $meta = $this->_gallery_album_meta($eventId);

            // Upsert the album shell (title/category/source/eventId). mediaCount
            // is maintained atomically below, not written here.
            $sync->syncGalleryAlbum($eventId, [
                'title'      => $meta['title'],
                'category'   => $meta['category'],
                'source'     => $meta['source'],
                'eventId'    => $meta['eventId'],
                'isArchived' => false,
                'createdBy'  => $this->admin_id,
                'createdAt'  => date('c'),
            ]);

            // Insert the media doc (same download url as RTDB).
            $sync->syncGalleryMedia($eventId, $mediaId, [
                'url'        => $downloadUrl,
                'type'       => ($fileType == '1') ? 'image' : 'video',
                'thumbnail'  => $mediaData['thumbnail'] ?? '',
                'duration'   => $mediaData['duration']  ?? '',
                'caption'    => '',
                'isArchived' => false,
                'uploadedBy' => $this->admin_id,
                'uploadedAt' => $mediaData['uploaded_at'],
            ]);

            // Atomic mediaCount++. The album cover then tracks the LATEST upload
            // (this one) so the thumbnail is always the most recent photo/video —
            // UNLESS an admin pinned a specific cover via setEventCover
            // (coverPinned). Images use their url; videos use the poster thumbnail
            // (a video with no extractable poster leaves the cover unchanged).
            $sync->bumpGalleryAlbumCount($eventId, 1);
            $newCover = ($fileType == '1') ? $downloadUrl : (string) ($mediaData['thumbnail'] ?? '');
            if ($newCover !== '') {
                $existingAlbum = $this->firebase->firestoreGet('galleryAlbums', "{$this->school_id}_{$eventId}");
                $pinned = is_array($existingAlbum) && !empty($existingAlbum['coverPinned']);
                if (!$pinned) {
                    $sync->updateGalleryAlbumCover($eventId, $newCover);
                }
            }
        } catch (\Throwable $e) {
            // Firestore is now the ONLY index for this media. If the write fails,
            // the Storage file would be orphaned (invisible + counting toward
            // quota), so roll it back and report the failure instead of a false
            // success. Mirrors the Teacher app's orphan-rollback.
            log_message('error', 'Schools::uploadMedia Firestore gallery sync failed [' . $eventId . ']: ' . $e->getMessage());
            try {
                $this->CM->delete_file_from_firebase($firebasePath);
                if (!empty($thumbStoragePath)) $this->CM->delete_file_from_firebase($thumbStoragePath);
            } catch (\Throwable $rollbackErr) {
                log_message('error', 'Schools::uploadMedia rollback failed [' . $firebasePath . ']: ' . $rollbackErr->getMessage());
            }
            echo json_encode(['status' => 'error', 'message' => 'Upload could not be saved. Please try again.']);
            return;
        }

        // ── Universal push: notify parents when an album first gets content ──
        // uploadMedia() runs once PER FILE, so gate on the pre-upload count
        // ($albumFileCount === 0 = this is the album's first media) to fire
        // exactly one "New Photos" push per album instead of one per photo.
        // The deterministic docId ({schoolId}_gallery_{albumId}) further
        // coalesces any races. Gallery is school-wide → parents.
        if ((int) $albumFileCount === 0) {
            $this->emit_push('GALLERY_ADDED', 'gallery_' . $eventId, [
                'target_group' => 'All Parents',
                'albumId'      => $eventId,
                'title'        => 'New Photos',
                'body'         => 'New photos have been added' . (!empty($meta['title']) ? ' to ' . $meta['title'] : '') . '.',
                'category'     => $meta['category'] ?? '',
            ]);
        }

        echo json_encode([
            'status'    => 'success',
            'message'   => 'File uploaded successfully',
            'mediaData' => $mediaData,
        ]);
    }

    // ── Gallery: set event cover image ──────────────────────────────────
    public function setEventCover()
    {
        $this->_require_role(self::ADMIN_ROLES, 'set_event_cover', 'Events', 'manage');
        header('Content-Type: application/json');
        $school_name = $this->school_name;
        $eventId     = trim($this->input->post('event_id') ?? '');
        $coverUrl    = $this->input->post('cover_url');

        if (empty($eventId) || empty($coverUrl)) {
            echo json_encode(['status' => 'error', 'message' => 'Missing event ID or cover URL']);
            return;
        }
        $eventId = $this->safe_path_segment($eventId, 'event_id');

        // FIX-3a (STORED XSS): cover_url is later injected into the album grid
        // markup, so only accept a well-formed https Firebase Storage URL.
        // Rejects javascript:/data: URIs and arbitrary hosts.
        $parts = parse_url((string) $coverUrl);
        $host  = strtolower($parts['host'] ?? '');
        $isValidCover = is_array($parts)
            && (($parts['scheme'] ?? '') === 'https')
            && ($host === 'firebasestorage.googleapis.com' || $host === 'storage.googleapis.com')
            && isset($parts['path']) && strpos($parts['path'], '/o/') !== false;
        if (!$isValidCover) {
            echo json_encode(['status' => 'error', 'message' => 'Invalid cover image URL.']);
            return;
        }

        // Scope the cover to the caller's own school storage — reject pointing an
        // album cover at another tenant's file (canonical ID prefix or legacy
        // name-rooted Events prefix). Mirrors the deleteMedia ownership guard.
        $coverPath = $this->extract_firebase_storage_path($coverUrl);
        $ownedByCaller = $coverPath !== null && $this->_storage_path_owned_by_caller($coverPath);
        if (!$ownedByCaller) {
            log_message('error', "Schools::setEventCover cross-tenant cover rejected — school=[{$this->school_id}] path=[{$coverPath}]");
            echo json_encode(['status' => 'error', 'message' => 'That cover image is not allowed.']);
            return;
        }

        // Canonical write: the cover lives on the galleryAlbums doc, which both
        // the apps and the admin gallery read. (Legacy RTDB Events/List write
        // removed — nothing reads it anymore; Critical C2 de-RTDB.)
        // Pin it: a deliberate admin choice must survive later uploads, which
        // otherwise auto-advance the cover to the latest media.
        try {
            $this->_entity_sync()->updateGalleryAlbumCover($eventId, (string) $coverUrl, true);
        } catch (\Throwable $e) {
            log_message('error', 'Schools::setEventCover Firestore gallery sync failed [' . $eventId . ']: ' . $e->getMessage());
            echo json_encode(['status' => 'error', 'message' => 'Could not set cover. Please try again.']);
            return;
        }

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