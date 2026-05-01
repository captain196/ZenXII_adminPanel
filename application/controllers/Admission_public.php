<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * PUBLIC ADMISSION FIX — Public admission controller (no login required).
 *
 * Extends CI_Controller directly (NOT MY_Controller) to bypass auth.
 * Provides a public-facing admission form for any school identified by school_id.
 *
 * Routes:
 *   GET  admission/form/{school_id}   → renders form
 *   POST admission/submit/{school_id} → processes submission
 *
 * Firestore collections (Phase-0 migration: NO RTDB anywhere in the form flow):
 *   schools/{school_id}                     — school profile + activeSession (read)
 *   sections                                — class list resolution (read)
 *   crmApplications                         — application storage (write — same
 *                                             collection admin's CRM reads)
 *   crmRateLimits/{schoolId}_{ipKey}        — IP rate limit window
 *   crmDupChecks/{schoolId}_{phoneHash}     — duplicate-submission guard
 *   auditLogs/{schoolId}_{logId}            — public audit trail
 *
 * Payment endpoints (initiate_payment / payment_callback / payment_status)
 * still touch RTDB — they are scheduled for Phase-1 along with the live
 * Razorpay swap, so the public form flow is decoupled from payments.
 */
class Admission_public extends CI_Controller
{
    public function __construct()
    {
        parent::__construct();
        // PUBLIC ADMISSION FIX — load only what we need, no session/auth.
        // firebase library kept for the not-yet-migrated payment endpoints
        // (Phase 1) — the form flow uses firestore_service exclusively.
        $this->load->library('firebase');
        $this->load->library('firestore_service', null, 'fs');
        $this->load->library('session'); // needed for CSRF
        $this->load->helper('url');
        $this->load->helper('notification');
        $this->load->helper('school_guard');
    }

    // ─── PUBLIC: Render admission form ─────────────────────────────────

    public function form($school_id = '')
    {
        // SCHOOL GUARD — validate format + existence (shows 404 if invalid).
        // The guard now reads Firestore `schools/{id}`; no RTDB fallback.
        $school_id = validate_public_school_id($school_id);
        $this->fs->init($school_id);

        // Fetch school profile from Firestore. The guard already confirmed
        // the doc exists, but we need the actual data for the form view.
        $schoolDoc = $this->fs->get('schools', $school_id) ?? [];

        // Normalize a profile shape the view template expects (display_name,
        // school_name, logo_url, address, phone, etc.). The Firestore
        // `schools` doc keys are camelCase; the view legacy code reads a
        // mix. Pass both shapes so existing template references keep working.
        $profile = array_merge($schoolDoc, [
            'display_name' => $schoolDoc['name']
                            ?? $schoolDoc['displayName']
                            ?? $schoolDoc['display_name']
                            ?? $school_id,
            'school_name'  => $schoolDoc['name'] ?? $schoolDoc['schoolName'] ?? '',
            'logo_url'     => $schoolDoc['logo'] ?? $schoolDoc['logoUrl'] ?? $schoolDoc['logo_url'] ?? '',
            'address'      => $schoolDoc['address'] ?? '',
            'phone'        => $schoolDoc['phone'] ?? $schoolDoc['contact'] ?? '',
            'email'        => $schoolDoc['email'] ?? '',
        ]);

        // Resolve the active session — `currentSession` is the canonical
        // Firestore field per School_config.php:2880-2882; if the school
        // doc was migrated before that field existed, we derive it from
        // the `sections` collection instead so the form still works.
        $activeSession = $this->_resolve_active_session($school_id, $schoolDoc);

        // Build class list by querying the `sections` collection scoped to
        // this school + active session. We dedupe to className since the
        // form only needs the class options, not the per-section breakdown.
        $classes = [];
        if ($activeSession !== '') {
            try {
                $sectionDocs = $this->fs->schoolList('sections', [
                    ['session', '==', $activeSession],
                ]);
                $seen = [];
                foreach ($sectionDocs as $sec) {
                    $cn = $sec['className'] ?? '';
                    if ($cn === '') continue;
                    $seen[$cn] = true;
                }
                $classes = array_keys($seen);
                // Natural sort: Class 1st, Class 2nd, ... Class 10th
                usort($classes, 'strnatcmp');
            } catch (\Exception $e) {
                log_message('error', "Admission_public::form sections query failed: " . $e->getMessage());
            }
        }

        $data = [
            'school_id'      => $school_id,
            'school_profile' => $profile,
            'session_year'   => $activeSession,
            'classes'        => $classes,
        ];

        $this->load->view('admission/public_form', $data);
    }

    // ─── PUBLIC: Handle form submission ─────────────────────────────────

    public function submit($school_id = '')
    {
        header('Content-Type: application/json');

        // PUBLIC ADMISSION FIX — accept only POST
        if ($this->input->method() !== 'post') {
            http_response_code(405);
            echo json_encode(['status' => 'error', 'message' => 'POST required.']);
            return;
        }

        // SCHOOL GUARD — Firestore-only existence check
        $school_id = validate_public_school_id($school_id);
        $this->fs->init($school_id);

        // School profile from the canonical Firestore doc.
        $schoolDoc = $this->fs->get('schools', $school_id) ?? [];
        $profile = array_merge($schoolDoc, [
            'display_name' => $schoolDoc['name']
                            ?? $schoolDoc['displayName']
                            ?? $schoolDoc['display_name']
                            ?? $school_id,
            'school_name'  => $schoolDoc['name'] ?? $schoolDoc['schoolName'] ?? '',
        ]);
        $activeSession = $this->_resolve_active_session($school_id, $schoolDoc);

        // ── Rate limiting: 10 submissions per IP per 15 minutes ───────────
        // Doc-per-IP in `crmRateLimits`. Each doc carries an `attempts` array
        // of unix timestamps; we trim the array to the active window on
        // every read+write so it never grows unbounded.
        $clientIp = $this->input->ip_address();
        $ipKey    = preg_replace('/[^a-zA-Z0-9]/', '_', $clientIp);
        $rateDocId   = "{$school_id}_{$ipKey}";
        $windowStart = time() - 900;
        $rateDoc     = $this->fs->get('crmRateLimits', $rateDocId);
        $existingAttempts = [];
        if (is_array($rateDoc) && is_array($rateDoc['attempts'] ?? null)) {
            foreach ($rateDoc['attempts'] as $ts) {
                if ((int) $ts >= $windowStart) $existingAttempts[] = (int) $ts;
            }
            if (count($existingAttempts) >= 10) {
                http_response_code(429);
                echo json_encode(['status' => 'error', 'message' => 'Too many submissions. Please try again later.']);
                return;
            }
        }
        // Record this attempt (best-effort; never blocks the submit)
        try {
            $existingAttempts[] = time();
            $this->fs->set('crmRateLimits', $rateDocId, [
                'schoolId'  => $school_id,
                'ip'        => $clientIp,
                'ipKey'     => $ipKey,
                'attempts'  => $existingAttempts,
                'updatedAt' => date('c'),
            ], false);
        } catch (\Exception $e) {
            log_message('error', "Admission_public rate-limit write failed: " . $e->getMessage());
        }

        // ── Input validation with length limits ──
        // Student basics + parent contact + new student-profile fields
        // (DOB, gender, blood group, category, religion, nationality) +
        // additional parent fields + address + previous school. Each
        // field has a hard length cap so a malicious submitter can't
        // bloat doc size.
        $fieldLimits = [
            // Existing
            'student_name' => 100, 'parent_name' => 100, 'phone' => 20,
            'email' => 150, 'class' => 50, 'message' => 500,
            // Student profile additions
            'dob'                 => 20,
            'gender'              => 20,
            'blood_group'         => 5,
            'category'            => 30,
            'religion'            => 50,
            'nationality'         => 50,
            // Parent additions
            'father_occupation'   => 100,
            'mother_name'         => 100,
            'mother_occupation'   => 100,
            'guardian_phone'      => 20,
            'guardian_relation'   => 50,
            // Address
            'address'             => 200,
            'city'                => 80,
            'state'               => 80,
            'pincode'             => 10,
            // Previous school
            'previous_school'     => 150,
            'previous_class'      => 50,
            'previous_marks'      => 50,
        ];
        $data = [];
        foreach ($fieldLimits as $f => $maxLen) {
            $val = trim($this->input->post($f) ?? '');
            if (mb_strlen($val) > $maxLen) {
                echo json_encode(['status' => 'error', 'message' => "Field '{$f}' exceeds maximum length."]);
                return;
            }
            $data[$f] = $val;
        }

        // Required fields
        if ($data['student_name'] === '') {
            echo json_encode(['status' => 'error', 'message' => 'Student name is required.']);
            return;
        }
        if ($data['class'] === '') {
            echo json_encode(['status' => 'error', 'message' => 'Class is required.']);
            return;
        }
        if ($data['phone'] === '') {
            echo json_encode(['status' => 'error', 'message' => 'Phone number is required.']);
            return;
        }
        // DOB + Gender are required because the SIS enroll flow uses
        // both (DOB seeds the auto-generated student password; gender
        // gates the leave-type filter / PE class assignment).
        if ($data['dob'] === '') {
            echo json_encode(['status' => 'error', 'message' => 'Date of birth is required.']);
            return;
        }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $data['dob'])) {
            echo json_encode(['status' => 'error', 'message' => 'Date of birth must be in YYYY-MM-DD format.']);
            return;
        }
        if ($data['gender'] === '') {
            echo json_encode(['status' => 'error', 'message' => 'Gender is required.']);
            return;
        }
        if ($data['pincode'] !== '' && !preg_match('/^\d{4,10}$/', $data['pincode'])) {
            echo json_encode(['status' => 'error', 'message' => 'Pincode must be digits only.']);
            return;
        }
        if ($data['guardian_phone'] !== '' && !preg_match('/^\+?\d{10,15}$/', preg_replace('/[\s\-]/', '', $data['guardian_phone']))) {
            echo json_encode(['status' => 'error', 'message' => 'Invalid guardian phone format.']);
            return;
        }

        // Consent — DPDP / GDPR. The form's checkbox posts the literal
        // string "on" when ticked. Reject the submission outright if it's
        // missing; storing PII without explicit consent is a compliance
        // problem we don't want to inherit.
        $consentRaw = trim((string) $this->input->post('consent'));
        if ($consentRaw === '') {
            echo json_encode(['status' => 'error', 'message' => 'You must accept the consent statement to submit the application.']);
            return;
        }
        $consentGivenAt = date('c'); // ISO-8601, recorded with the doc

        // Phone format: 10-15 digits (with optional + prefix)
        $phoneCleaned = preg_replace('/[\s\-]/', '', $data['phone']);
        if (!preg_match('/^\+?\d{10,15}$/', $phoneCleaned)) {
            echo json_encode(['status' => 'error', 'message' => 'Invalid phone number format.']);
            return;
        }

        // Email format (optional)
        if ($data['email'] !== '' && !filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
            echo json_encode(['status' => 'error', 'message' => 'Invalid email address.']);
            return;
        }

        // ── Duplicate handling ────────────────────────────────────────────
        // New policy (2026-04-30): support real-world cases like siblings
        // and twins without over-blocking. See `crmApplications.source`
        // and `possible_duplicate` fields below.
        //
        //   Hard block — only true accidental double-submits:
        //     same phone + same class + same student name + within 2 min
        //   Soft flag — leave the submission through, mark for admin review:
        //     same phone + same class (any time)
        //
        // Real-world allowed cases:
        //   • Siblings (same phone, different class) — pass cleanly
        //   • Twins   (same phone, same class, different name) — flagged
        //     soft, NOT blocked
        //   • Re-application after a few minutes — passes cleanly
        $phoneNorm = preg_replace('/[^0-9]/', '', $phoneCleaned);
        $studentNameLower = strtolower(trim($data['student_name']));
        $nowTs = time();
        $hardWindowSec = 120; // 2 minutes — anti-double-click only

        $possibleDuplicate = false;
        $hardBlock = false;
        try {
            // Bounded scan: applications already submitted by this phone
            // for this school. Typical family count is 1-5; well within
            // an in-memory pass.
            $existing = $this->fs->schoolList('crmApplications', [
                ['phone_norm', '==', $phoneNorm],
            ]);
            foreach ($existing as $a) {
                if (!is_array($a)) continue;
                $aClass   = (string) ($a['class'] ?? '');
                $aName    = strtolower(trim((string) ($a['student_name'] ?? '')));
                $aCreated = strtotime((string) ($a['created_at'] ?? ''));
                if ($aCreated <= 0) continue;

                // Hard block — narrow: phone + class + name + 2-min window.
                if ($aClass === $data['class']
                    && $aName === $studentNameLower
                    && ($nowTs - $aCreated) <= $hardWindowSec) {
                    $hardBlock = true;
                    break;
                }
                // Soft flag — same phone + same class (any time, any name).
                if ($aClass === $data['class']) {
                    $possibleDuplicate = true;
                }
            }
        } catch (\Exception $e) {
            log_message('error', 'Admission_public submit dup-scan failed: ' . $e->getMessage());
        }

        if ($hardBlock) {
            echo json_encode([
                'status'  => 'error',
                'message' => 'A nearly-identical application was submitted moments ago. Please wait a minute and try again.',
            ]);
            return;
        }

        // ── Generate lead ID and store ─────────────────────────────────────
        // No more Firebase push() (RTDB-only primitive). Build a unique-ish
        // app id from current ms + a 5-digit random tail. Collision-safe at
        // public-form rates (one school, one form, ~tens of submits/min cap).
        $now   = date('Y-m-d H:i:s');
        $appId = 'APP_' . strtoupper(substr(dechex((int) (microtime(true) * 1000)), -6))
               . '_' . str_pad((string) mt_rand(0, 99999), 5, '0', STR_PAD_LEFT);

        // Schema mirrors what admin's `convert_to_application` writes to
        // `crmApplications` so the same Pipeline / Inquiries / Applications
        // grids show public submissions without any normalization.
        $applicationData = [
            'id'                => $appId,
            // Student profile
            'student_name'      => $data['student_name'],
            'class'             => $data['class'],
            'dob'               => $data['dob'],
            'gender'            => $data['gender'],
            'blood_group'       => $data['blood_group'],
            'category'          => $data['category'],
            'religion'          => $data['religion'],
            'nationality'       => $data['nationality'],
            // Parent / Guardian
            'parent_name'       => $data['parent_name'],
            'father_name'       => $data['parent_name'],
            'father_occupation' => $data['father_occupation'],
            'mother_name'       => $data['mother_name'],
            'mother_occupation' => $data['mother_occupation'],
            'phone'             => $data['phone'],
            // Normalized phone (digits only) — exists purely so the
            // duplicate scan can do an indexable equality query without
            // worrying about formatting variations between submissions.
            'phone_norm'        => $phoneNorm,
            'email'             => $data['email'],
            'guardian_phone'    => $data['guardian_phone'],
            'guardian_relation' => $data['guardian_relation'],
            // Address
            'address'           => $data['address'],
            'city'              => $data['city'],
            'state'             => $data['state'],
            'pincode'           => $data['pincode'],
            // Previous schooling
            'previous_school'   => $data['previous_school'],
            'previous_class'    => $data['previous_class'],
            'previous_marks'    => $data['previous_marks'],
            // Workflow / metadata
            'notes'             => $data['message'],
            // `pending` is what admin's `convert_to_application` flow
            // writes, and the row-action JS at applications.php:334 only
            // renders Approve/Reject/Waitlist buttons when
            // `status === 'pending'`. Writing "new" here previously hid
            // those buttons so admins couldn't act on public submissions.
            'status'            => 'pending',
            // `document_collection` is the first stage in the pipeline's
            // default stages map (`Sis::_default_stages`). Writing the
            // canonical value here means the card lands in the correct
            // column instead of relying on the "unknown stage → first
            // column" fallback in fetch_pipeline.
            'stage'             => 'document_collection',
            'source'            => 'public_form',
            'payment_status'    => 'pending',
            'session'           => $activeSession,
            'created_at'        => $now,
            'updated_at'        => $now,
            'submitted_ip'      => $clientIp,
            // DPDP / GDPR audit — proves the parent acknowledged the
            // consent statement before the application was stored.
            'consent_given_at'  => $consentGivenAt,
            // Soft-duplicate marker — admin sees a badge in the CRM list
            // but the application is NOT blocked. Set when another app
            // with the same phone+class exists for this school. Twins
            // legitimately trigger this; admin reviews and clears.
            'possible_duplicate' => $possibleDuplicate,
            'documents'         => [],
            'history'           => [
                ['action' => 'Application submitted via public form', 'by' => 'Public', 'timestamp' => $now],
            ],
        ];

        // Canonical write — `setEntity` injects `schoolId` + `updatedAt` and
        // uses `{schoolId}_{appId}` as the doc id (matching admin convention).
        $written = $this->fs->setEntity('crmApplications', $appId, $applicationData);
        if (!$written) {
            log_message('error', "Admission_public::submit Firestore write failed for {$school_id}/{$appId}");
            echo json_encode(['status' => 'error', 'message' => 'Could not save your application. Please try again.']);
            return;
        }

        // crmDupChecks side-table dropped — the new dup-handling logic
        // queries `crmApplications` directly via the `phone_norm` field
        // populated above. The side-table is no longer maintained.

        // Receipt URL — printable PDF acknowledgement (Tier-A QW #1).
        // Pre-computed so we can pass it to both the JSON response (for
        // the success modal's download button) and the SMS body.
        $receiptUrl = $this->_receipt_url($school_id, $appId);

        // LEAD SYSTEM — notify parent (fire-and-forget, never blocks response)
        $schoolDisplayName = $profile['display_name'] ?? $profile['school_name'] ?? $school_id;
        notify_admission_received($data['phone'], $schoolDisplayName, $appId, $receiptUrl);

        // AUDIT — log public application submission
        $this->_public_audit($school_id, 'Admission', 'public_application', $appId,
            "Public admission submitted: {$data['student_name']} for class {$data['class']}");

        // Hybrid admission-fee lookup: feeStructures (class-specific) first,
        // then schools.admissionFee (global). See _resolve_admission_fee().
        $feeInfo = $this->_resolve_admission_fee($school_id, $schoolDoc, $activeSession, $data['class']);

        echo json_encode([
            'status'           => 'success',
            'message'          => 'Application submitted successfully.',
            'app_id'           => $appId,
            'receipt_url'      => $receiptUrl,
            'payment_required' => $feeInfo['enabled'] && $feeInfo['amount'] > 0,
            'payment_amount'   => $feeInfo['amount'],
            'payment_label'    => $feeInfo['label'],
            'payment_source'   => $feeInfo['source'], // "feeStructures" | "schools.admissionFee" | "none"
            'csrf_token'       => $this->security->get_csrf_hash(),
        ]);
    }

    // ─── PAYMENT: Initiate admission fee payment ───────────────────────

    public function initiate_payment($school_id = '')
    {
        header('Content-Type: application/json');

        if ($this->input->method() !== 'post') {
            http_response_code(405);
            echo json_encode(['status' => 'error', 'message' => 'POST required.']);
            return;
        }

        // SCHOOL GUARD — validate format + existence (Firestore-only).
        $school_id = validate_public_school_id($school_id);
        $this->fs->init($school_id);

        $appId = trim($this->input->post('app_id') ?? '');
        if ($appId === '' || !preg_match('/^[A-Za-z0-9_]+$/', $appId)) {
            echo json_encode(['status' => 'error', 'message' => 'Invalid application ID.']);
            return;
        }

        // Fetch application from Firestore `crmApplications`.
        $app = $this->fs->get('crmApplications', $this->fs->docId($appId));
        if (!is_array($app)) {
            echo json_encode(['status' => 'error', 'message' => 'Application not found.']);
            return;
        }
        // Prevent duplicate payment
        if (($app['payment_status'] ?? '') === 'paid') {
            echo json_encode(['status' => 'error', 'message' => 'Payment already completed for this application.']);
            return;
        }

        // Resolve admission fee using the same hybrid lookup as `submit()` —
        // class-specific from feeStructures wins; schools.admissionFee is
        // the fallback. The class is taken from the application doc so a
        // tampered POST can't change the fee amount.
        $schoolDoc = $this->fs->get('schools', $school_id) ?? [];
        $session = $this->_resolve_active_session($school_id, $schoolDoc);
        $appClass = (string) ($app['class'] ?? '');
        $feeInfo = $this->_resolve_admission_fee($school_id, $schoolDoc, $session, $appClass);
        if (!$feeInfo['enabled'] || $feeInfo['amount'] <= 0) {
            echo json_encode(['status' => 'error', 'message' => 'Admission fee is not configured for this class/school.']);
            return;
        }
        $amount   = (float) $feeInfo['amount'];
        $currency = (string) $feeInfo['currency'];

        // Resolve and load the gateway adapter (Razorpay or mock).
        try {
            $gw = $this->_load_payment_gateway($school_id, $schoolDoc);
        } catch (\Exception $e) {
            log_message('error', 'Admission_public::initiate_payment gateway load failed: ' . $e->getMessage());
            echo json_encode(['status' => 'error', 'message' => 'Payment gateway is not configured. Please contact the school office.']);
            return;
        }

        try {
            $order = $gw['adapter']->create_order($amount, $appId, [
                'school_id'    => $school_id,
                'app_id'       => $appId,
                'student_name' => $app['student_name'] ?? '',
                'type'         => 'admission_fee',
            ]);
        } catch (\Exception $e) {
            log_message('error', 'Admission_public::initiate_payment create_order failed: ' . $e->getMessage());
            echo json_encode(['status' => 'error', 'message' => 'Could not create payment order. Please try again.']);
            return;
        }

        $now = date('Y-m-d H:i:s');
        $paymentId = 'ADMPAY_' . strtoupper(substr(dechex((int) (microtime(true) * 1000)), -6))
                   . '_' . str_pad((string) mt_rand(0, 9999), 4, '0', STR_PAD_LEFT);

        // Firestore-only payment record. Doc id: {schoolId}_{paymentId}.
        $paymentRecord = [
            'payment_id'   => $paymentId,
            'order_id'     => $order['order_id'],
            'app_id'       => $appId,
            'student_name' => $app['student_name'] ?? '',
            'phone'        => $app['phone'] ?? '',
            'amount'       => $amount,
            'currency'     => $currency,
            'status'       => 'created',
            'gateway'      => $gw['name'],
            'created_at'   => $now,
            'ip'           => $this->input->ip_address(),
        ];
        $this->fs->setEntity('admissionPayments', $paymentId, $paymentRecord);

        // Mark application as payment-initiated. Status / stage NOT touched.
        $this->fs->updateEntity('crmApplications', $appId, [
            'payment_status' => 'initiated',
            'payment_id'     => $paymentId,
            'order_id'       => $order['order_id'],
            'updated_at'     => $now,
        ]);

        // Return order details for frontend checkout. The Razorpay
        // checkout SDK consumes `key`, `order_id`, `amount`, `currency`,
        // `name`, `prefill.{name,email,phone}`. We send everything the
        // browser-side checkout init needs.
        $schoolDisplayName = $schoolDoc['name']
            ?? $schoolDoc['displayName']
            ?? $schoolDoc['display_name']
            ?? $school_id;

        echo json_encode([
            'status'       => 'success',
            'payment_id'   => $paymentId,
            'order_id'     => $order['order_id'],
            'amount'       => $amount,
            'amount_paise' => (int) ($amount * 100),
            'currency'     => $currency,
            'school_name'  => $schoolDisplayName,
            'student_name' => $app['student_name'] ?? '',
            'email'        => $app['email'] ?? '',
            'phone'        => $app['phone'] ?? '',
            'gateway'      => $gw['name'],
            'key'          => $gw['public_key'], // Razorpay key_id for checkout init
            'csrf_token'   => $this->security->get_csrf_hash(),
        ]);
    }

    // ─── PAYMENT: Verify and confirm payment ───────────────────────────

    public function payment_callback($school_id = '')
    {
        header('Content-Type: application/json');

        if ($this->input->method() !== 'post') {
            http_response_code(405);
            echo json_encode(['status' => 'error', 'message' => 'POST required.']);
            return;
        }

        // SCHOOL GUARD — validate format + existence
        $school_id = validate_public_school_id($school_id);
        $this->fs->init($school_id);

        $paymentId  = trim($this->input->post('payment_id') ?? '');
        $orderId    = trim($this->input->post('order_id') ?? '');
        $gatewayPid = trim($this->input->post('gateway_payment_id') ?? '');
        $signature  = trim($this->input->post('signature') ?? '');

        if ($paymentId === '' || $orderId === '' || $gatewayPid === '') {
            echo json_encode(['status' => 'error', 'message' => 'Missing payment details.']);
            return;
        }

        // Fetch payment record from Firestore.
        $payment = $this->fs->get('admissionPayments', $this->fs->docId($paymentId));
        if (!is_array($payment)) {
            echo json_encode(['status' => 'error', 'message' => 'Payment record not found.']);
            return;
        }

        // Prevent double-processing.
        if (($payment['status'] ?? '') === 'paid') {
            echo json_encode(['status' => 'success', 'message' => 'Payment already confirmed.', 'already_paid' => true]);
            return;
        }

        // Verify order_id matches.
        if (($payment['order_id'] ?? '') !== $orderId) {
            echo json_encode(['status' => 'error', 'message' => 'Order ID mismatch.']);
            return;
        }

        // Load the same gateway adapter that created the order so signature
        // verification uses matching credentials.
        try {
            $schoolDoc = $this->fs->get('schools', $school_id) ?? [];
            $gw = $this->_load_payment_gateway($school_id, $schoolDoc);
        } catch (\Exception $e) {
            log_message('error', 'Admission_public::payment_callback gateway load failed: ' . $e->getMessage());
            echo json_encode(['status' => 'error', 'message' => 'Payment gateway is not configured.']);
            return;
        }

        // Mock-mode kept for local dev: server-side simulates a payment
        // because there's no client-side gateway SDK to drive. Razorpay
        // (real) verifies the HMAC signature returned by the checkout SDK.
        if ($gw['name'] === 'mock') {
            $simResult  = $gw['adapter']->simulate_payment($orderId, (float)($payment['amount'] ?? 0));
            $gatewayPid = $simResult['payment_id'];
            $signature  = $simResult['signature'] ?? '';
            $verified   = $simResult['status'] === 'success'
                        && $gw['adapter']->verify_signature($orderId, $gatewayPid, $signature);
        } else {
            $verified = $gw['adapter']->verify_signature($orderId, $gatewayPid, $signature);
        }

        $now   = date('Y-m-d H:i:s');
        $appId = $payment['app_id'] ?? '';

        if ($verified) {
            // Mark payment as paid in Firestore.
            $this->fs->updateEntity('admissionPayments', $paymentId, [
                'status'             => 'paid',
                'gateway_payment_id' => $gatewayPid,
                'signature'          => $signature,
                'verified_at'        => $now,
            ]);

            // Update application — payment_status only. Status/stage NOT
            // touched; admin must still review and approve manually.
            // feeReceipts NOT created here either; that happens after
            // enrollment when student_id is allocated. (Both decisions
            // locked by user during Phase-1 scoping.)
            if ($appId !== '') {
                $app = $this->fs->get('crmApplications', $this->fs->docId($appId));
                $history = is_array($app) ? ($app['history'] ?? []) : [];
                $history[] = [
                    'action'    => "Admission fee paid ({$gatewayPid})",
                    'by'        => 'Payment Gateway',
                    'timestamp' => $now,
                ];
                $this->fs->updateEntity('crmApplications', $appId, [
                    'payment_status' => 'paid',
                    'updated_at'     => $now,
                    'history'        => $history,
                ]);
            }

            // Notify parent. Currency falls back to INR.
            $this->load->helper('notification');
            $schoolDisplayName = $schoolDoc['name']
                ?? $schoolDoc['displayName']
                ?? $schoolDoc['display_name']
                ?? $school_id;
            $payCurrency = $payment['currency'] ?? 'INR';
            notify_sms(
                $payment['phone'] ?? '',
                "Payment of {$payCurrency} {$payment['amount']} received for {$schoolDisplayName} admission (Ref: {$paymentId}). Your application is awaiting school review.",
                ['type' => 'payment_success', 'payment_id' => $paymentId]
            );

            // AUDIT — log successful payment.
            $this->_public_audit($school_id, 'Payment', 'payment_success', $paymentId,
                "Admission fee paid: {$payCurrency} {$payment['amount']} for app {$appId}");

            echo json_encode([
                'status'     => 'success',
                'message'    => 'Payment verified successfully. Awaiting school review.',
                'csrf_token' => $this->security->get_csrf_hash(),
            ]);
        } else {
            // Payment verification failed — Firestore write only.
            $this->fs->updateEntity('admissionPayments', $paymentId, [
                'status'             => 'failed',
                'gateway_payment_id' => $gatewayPid,
                'failure_reason'     => 'Signature verification failed',
                'failed_at'          => $now,
            ]);

            $this->_public_audit($school_id, 'Payment', 'payment_failed', $paymentId,
                "Payment verification failed for app {$appId}");

            echo json_encode(['status' => 'error', 'message' => 'Payment verification failed.', 'csrf_token' => $this->security->get_csrf_hash()]);
        }
    }

    // ─── PAYMENT: Status check (polling) ───────────────────────────────

    public function payment_status($school_id = '')
    {
        header('Content-Type: application/json');

        // SCHOOL GUARD — validate format + existence
        $school_id = validate_public_school_id($school_id);
        $this->fs->init($school_id);

        $paymentId = trim($this->input->get('payment_id') ?? '');
        if ($paymentId === '' || !preg_match('/^[A-Za-z0-9_]+$/', $paymentId)) {
            echo json_encode(['status' => 'error', 'message' => 'Invalid payment ID.']);
            return;
        }
        $payment = $this->fs->get('admissionPayments', $this->fs->docId($paymentId));
        if (!is_array($payment)) {
            echo json_encode(['status' => 'error', 'message' => 'Payment not found.']);
            return;
        }
        echo json_encode([
            'status'         => 'success',
            'payment_status' => $payment['status'] ?? 'unknown',
            'amount'         => $payment['amount'] ?? 0,
        ]);
    }

    // ─── Gateway loader (Razorpay or mock) ─────────────────────────────

    /**
     * Resolve and instantiate the configured payment gateway adapter.
     *
     * Reuses the same `feeSettings/{schoolName}_{session}_gateway` Firestore
     * doc the fees module reads, so a school configures Razorpay credentials
     * once and both fees + admission share them.
     *
     * Returns a small struct: { adapter, name, public_key }
     *   - adapter: instance with create_order / verify_signature / simulate_payment
     *   - name: "razorpay" | "mock"
     *   - public_key: the Razorpay key_id (safe to expose to the browser)
     *                 or empty string for mock
     *
     * Throws RuntimeException if Razorpay is configured but credentials
     * are missing.
     */
    private function _load_payment_gateway(string $school_id, array $schoolDoc): array
    {
        $schoolName = (string) (
            $schoolDoc['name']
            ?? $schoolDoc['schoolName']
            ?? $schoolDoc['displayName']
            ?? $school_id
        );
        $session = $this->_resolve_active_session($school_id, $schoolDoc);

        // Two-stage lookup. Some tenants store the gateway doc keyed by
        // `{schoolName}_{session}_gateway` (fees-module convention),
        // others by `{schoolId}_{session}_gateway`, and a few have the
        // schoolName slightly different from what `schools/{id}.name`
        // returns. We try the canonical doc id first, fall back to a
        // field-based query — this is what guarantees Razorpay is found
        // even when the doc id format differs from our guess.
        $gwConfig = null;
        try {
            // Try 1: doc-id lookup ({schoolName}_{session}_gateway).
            if ($schoolName !== '' && $session !== '') {
                $gwConfig = $this->fs->get('feeSettings', "{$schoolName}_{$session}_gateway");
            }
            // Try 2: doc-id lookup with schoolId in case admin saved that way.
            if (!is_array($gwConfig) && $session !== '') {
                $gwConfig = $this->fs->get('feeSettings', "{$school_id}_{$session}_gateway");
            }
            // Try 3: query by fields. The doc structure has schoolId,
            // session, type='gateway' so this finds it irrespective of
            // doc-id format.
            if (!is_array($gwConfig)) {
                $rows = $this->fs->schoolList('feeSettings', [
                    ['type', '==', 'gateway'],
                ]);
                foreach ($rows as $r) {
                    // Prefer a row matching the active session; fall back
                    // to any gateway row for this school if no session match.
                    $rowSession = (string) ($r['session'] ?? '');
                    if ($session === '' || $rowSession === '' || $rowSession === $session) {
                        $gwConfig = $r;
                        break;
                    }
                }
            }
        } catch (\Exception $e) {
            log_message('error', 'Admission_public::_load_payment_gateway feeSettings read failed: ' . $e->getMessage());
        }

        // Honor an explicit `active: false` flag; otherwise treat the
        // configured provider as truth.
        $isActive = is_array($gwConfig) ? !isset($gwConfig['active']) || !empty($gwConfig['active']) : false;
        $provider = (is_array($gwConfig) && $isActive) ? (string) ($gwConfig['provider'] ?? 'mock') : 'mock';

        if ($provider === 'razorpay') {
            $apiKey    = (string) ($gwConfig['api_key']    ?? '');
            $apiSecret = (string) ($gwConfig['api_secret'] ?? '');
            if ($apiKey === '' || $apiSecret === '') {
                throw new \RuntimeException('Razorpay credentials missing in feeSettings.');
            }
            $this->load->library('Payment_gateway_razorpay', [
                'api_key'    => $apiKey,
                'api_secret' => $apiSecret,
            ]);
            return [
                'adapter'    => $this->payment_gateway_razorpay,
                'name'       => 'razorpay',
                'public_key' => $apiKey,
            ];
        }

        // Default: mock adapter for dev / when no provider configured.
        $this->load->library('Payment_gateway_mock');
        return [
            'adapter'    => $this->payment_gateway_mock,
            'name'       => 'mock',
            'public_key' => '',
        ];
    }

    // ─── Audit logging for public context (no session) ─────────────────

    /**
     * Write audit entry from public controller (no session/admin context).
     * Uses 'Public' as the user identifier. Non-blocking.
     */
    private function _public_audit(string $schoolId, string $module, string $action, string $entityId, string $description): void
    {
        try {
            $logId = 'AL_' . date('Ymd_His') . '_' . substr(uniqid('', true), -6);
            // Ensure firestore_service is initialized for callers that hit
            // payment endpoints first (those still use $this->firebase and
            // may not have called fs->init yet).
            if (method_exists($this->fs, 'isReady') && !$this->fs->isReady()) {
                $this->fs->init($schoolId);
            }
            $this->fs->setEntity('auditLogs', $logId, [
                'userId'      => 'public',
                'userName'    => 'Public Form',
                'userRole'    => 'Public',
                'module'      => $module,
                'action'      => $action,
                'entityId'    => $entityId,
                'description' => $description,
                'ipAddress'   => $this->input->ip_address(),
                'timestamp'   => gmdate('Y-m-d\TH:i:s\Z'),
            ]);
        } catch (\Exception $e) {
            log_message('error', 'Admission_public::_public_audit failed: ' . $e->getMessage());
        }
    }

    /**
     * Resolve the admission fee for a given class.
     *
     * Hybrid lookup (closest-match wins):
     *   1. `feeStructures/{schoolId}_{session}_{className}_*` — search any
     *      section's `feeHeads[]` for a head whose name contains
     *      "admission" (case-insensitive). If found, that amount overrides
     *      the global default. Lets schools price admission per class
     *      (e.g. Pre-K higher than Grade 10) using the same fee-structure
     *      UI they already use for tuition.
     *   2. `schools/{id}.admissionFee` — global default set via
     *      `School_config/admission_payment_config`.
     *
     * Returns: [enabled, amount, currency, source, fee_head, label]
     *   - source = "feeStructures" | "schools.admissionFee" | "none"
     *   - When source = "none", payment is not required.
     */
    private function _resolve_admission_fee(string $school_id, array $schoolDoc, string $session, string $cls): array
    {
        // Default fallback shape (unconfigured).
        $result = [
            'enabled'  => false,
            'amount'   => 0.0,
            'currency' => 'INR',
            'source'   => 'none',
            'fee_head' => 'Admission Fee',
            'label'    => 'Admission Fee',
        ];

        // Pass 1 — class-specific lookup in feeStructures.
        if ($cls !== '' && $session !== '') {
            try {
                // The fee-structure doc id is built with "Class X" + "Section Y"
                // prefixes, and the className field is stored with the prefix.
                $normCls = (stripos($cls, 'Class ') === 0) ? $cls : ('Class ' . $cls);
                $rows = $this->fs->schoolList('feeStructures', [
                    ['session',   '==', $session],
                    ['className', '==', $normCls],
                ]);
                foreach ($rows as $r) {
                    $heads = is_array($r['feeHeads'] ?? null) ? $r['feeHeads'] : [];
                    foreach ($heads as $h) {
                        if (!is_array($h)) continue;
                        $nameLower = strtolower(trim((string) ($h['name'] ?? '')));
                        if ($nameLower === '') continue;
                        // Match "Admission Fee", "admission", or any head
                        // containing the word "admission" (case-insensitive)
                        // — generous on naming so admin's existing labels
                        // work without a forced rename.
                        if (strpos($nameLower, 'admission') === false) continue;
                        $amt = (float) ($h['amount'] ?? 0);
                        if ($amt <= 0) continue;
                        return [
                            'enabled'  => true,
                            'amount'   => $amt,
                            'currency' => 'INR',
                            'source'   => 'feeStructures',
                            'fee_head' => (string) ($h['name'] ?? 'Admission Fee'),
                            'label'    => (string) ($h['name'] ?? 'Admission Fee'),
                        ];
                    }
                }
            } catch (\Exception $e) {
                log_message('error', "Admission_public::_resolve_admission_fee feeStructures lookup failed: " . $e->getMessage());
            }
        }

        // Pass 2 — global default on the schools doc.
        $admissionFee = is_array($schoolDoc['admissionFee'] ?? null) ? $schoolDoc['admissionFee'] : [];
        $globalEnabled = !empty($admissionFee['enabled']);
        $globalAmount  = isset($admissionFee['amount']) ? (float) $admissionFee['amount'] : 0.0;
        if ($globalEnabled && $globalAmount > 0) {
            return [
                'enabled'  => true,
                'amount'   => $globalAmount,
                'currency' => (string) ($admissionFee['currency'] ?? 'INR'),
                'source'   => 'schools.admissionFee',
                'fee_head' => (string) ($admissionFee['label'] ?? 'Admission Fee'),
                'label'    => (string) ($admissionFee['label'] ?? 'Admission Fee'),
            ];
        }

        return $result;
    }

    /**
     * Resolve the school's active session.
     *
     * Read order:
     *   1. `currentSession` on the schools doc (canonical, per
     *      School_config.php:2880-2882)
     *   2. Legacy fields: `activeSession`, `active_session`, `session`
     *   3. Sections-collection fallback — pick the latest `session` value
     *      across this school's sections. This handles tenants whose
     *      schools doc was created before the session field existed but
     *      whose sections rows do carry it. The result is also written
     *      back to the schools doc so subsequent reads are O(1).
     *
     * Returns empty string if nothing resolves (caller treats this as
     * "no class list available").
     */
    private function _resolve_active_session(string $school_id, array $schoolDoc): string
    {
        $fromDoc = (string) (
            $schoolDoc['currentSession']
            ?? $schoolDoc['activeSession']
            ?? $schoolDoc['active_session']
            ?? $schoolDoc['session']
            ?? ''
        );
        if ($fromDoc !== '') return $fromDoc;

        // Sections-collection fallback. Sort lexicographically — session
        // strings are formatted "YYYY-YY" so newer windows sort later.
        try {
            $sections = $this->fs->schoolList('sections');
            $sessions = [];
            foreach ($sections as $s) {
                $sv = (string) ($s['session'] ?? '');
                if ($sv !== '') $sessions[$sv] = true;
            }
            if (!empty($sessions)) {
                $sessionKeys = array_keys($sessions);
                rsort($sessionKeys);
                $resolved = (string) $sessionKeys[0];

                // Best-effort: persist the resolved value to the schools
                // doc so future reads skip the sections query. Failure
                // here is non-fatal — we still return the value.
                try {
                    $this->fs->update('schools', $school_id, ['currentSession' => $resolved]);
                } catch (\Exception $e) { /* non-fatal */ }

                return $resolved;
            }
        } catch (\Exception $e) {
            log_message('error', "Admission_public::_resolve_active_session sections fallback failed: " . $e->getMessage());
        }
        return '';
    }

    // ─── PUBLIC: Printable PDF receipt ──────────────────────────────────
    //
    // Tier-A admission Quick Win #1 — gives every applicant a stable,
    // printable artifact bearing their app_id, the submitted details,
    // and a QR encoding the receipt URL itself (so school staff /
    // parents can re-fetch the same PDF later via a quick scan).
    //
    // Token verification: the URL carries a 16-char HMAC-SHA256 of
    // "{schoolId}|{appId}" signed with the site's encryption_key. This
    // makes the receipt URL un-enumerable (a stranger can't iterate
    // app_ids to print other people's receipts) without requiring any
    // separate DB write — the token is derivable from the same fields
    // already in the URL whenever the server holds the secret.

    /**
     * Generate the 16-char receipt token for (schoolId, appId).
     * Used by both the public download flow and the SMS template.
     */
    private function _receipt_token(string $schoolId, string $appId): string
    {
        $secret = (string) config_item('encryption_key');
        if ($secret === '') {
            // Fall back to a project-fixed string so the receipt URL still
            // works in dev environments where encryption_key isn't set.
            // Production deployments MUST set encryption_key in config.php.
            $secret = 'grader-admission-receipt-fallback';
        }
        return substr(hash_hmac('sha256', "{$schoolId}|{$appId}", $secret), 0, 16);
    }

    /**
     * Build the absolute receipt URL for use in the success modal + SMS.
     */
    private function _receipt_url(string $schoolId, string $appId): string
    {
        $token = $this->_receipt_token($schoolId, $appId);
        return base_url("admission/receipt/" . rawurlencode($schoolId)
            . "/" . rawurlencode($appId) . "/" . $token);
    }

    public function receipt($school_id = '', $app_id = '', $token = '')
    {
        $school_id = validate_public_school_id($school_id);
        $app_id    = trim((string) $app_id);
        $token     = trim((string) $token);

        if ($app_id === '' || $token === '') {
            show_404();
        }
        // App IDs are alphanumeric + hyphen/underscore only.
        if (!preg_match('/^[A-Za-z0-9_\-]{4,64}$/', $app_id)) {
            show_404();
        }
        // Constant-time HMAC compare to prevent timing-based enumeration.
        $expected = $this->_receipt_token($school_id, $app_id);
        if (!hash_equals($expected, $token)) {
            show_404();
        }

        $this->fs->init($school_id);

        // crmApplications doc id is `{schoolId}_{appId}` — same convention
        // as setEntity uses on submit.
        $appDoc = $this->fs->get('crmApplications', "{$school_id}_{$app_id}");
        if (!is_array($appDoc) || empty($appDoc)) {
            show_404();
        }

        $schoolDoc = $this->fs->get('schools', $school_id) ?? [];
        $profile = array_merge($schoolDoc, [
            'display_name' => $schoolDoc['name']
                            ?? $schoolDoc['displayName']
                            ?? $schoolDoc['display_name']
                            ?? $school_id,
            'logo_url'     => $schoolDoc['logo'] ?? $schoolDoc['logoUrl'] ?? $schoolDoc['logo_url'] ?? '',
            'address'      => $schoolDoc['address'] ?? '',
            'phone'        => $schoolDoc['phone']   ?? $schoolDoc['contact'] ?? '',
            'email'        => $schoolDoc['email']   ?? '',
        ]);

        // QR encodes the receipt URL itself — scanning re-opens this
        // PDF. External API call; if it fails the PDF still renders
        // (the IMG silently 404s and Dompdf moves on).
        $receiptUrl = $this->_receipt_url($school_id, $app_id);
        $qrUrl = 'https://api.qrserver.com/v1/create-qr-code/?size=160x160&margin=2&data='
               . rawurlencode($receiptUrl);

        $data = [
            'school_id'      => $school_id,
            'school_profile' => $profile,
            'app_id'         => $app_id,
            'application'    => $appDoc,
            'receipt_url'    => $receiptUrl,
            'qr_url'         => $qrUrl,
        ];

        $this->load->library('pdf_generator');
        $html = $this->load->view('admission/receipt_pdf', $data, true);

        $filename = 'admission_receipt_' . preg_replace('/[^A-Za-z0-9_-]/', '', $app_id) . '.pdf';
        $this->pdf_generator->inline($html, $filename);
    }
}
