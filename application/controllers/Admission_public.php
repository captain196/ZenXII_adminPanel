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
 *   schools/{school_id}                     — school profile + currentSession (read-only;
 *                                             SC-Step11/G3 sole session authority, no write)
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

    /**
     * Resolve the URL segment to a canonical school id.
     *
     *  - Raw school id ("SCH_…" / legacy code): only `[A-Za-z0-9_]`, so it
     *    passes through untouched (and the guard existence-checks it). This
     *    keeps every pre-slug link and printed QR working forever.
     *  - Readable slug ("st-marys-school", has hyphens): looked up in the
     *    global `admissionSlugs/{slug}` map via the raw Firebase client
     *    (server-side, admin-credentialed). The map is 1 slug → 1 schoolId,
     *    so there is no cross-tenant ambiguity. Unknown/malformed → 404.
     */
    private function _resolve_admission_ref(string $ref): string
    {
        $ref = trim($ref);

        // Raw id shape (no hyphen) — hand to the existing guard unchanged.
        if ($ref !== '' && preg_match('/^[A-Za-z0-9_]+$/', $ref)) {
            return $ref;
        }

        // Slug shape: lowercase alphanumeric + internal hyphens, ≤100 chars.
        $slug = strtolower($ref);
        if (!preg_match('/^[a-z0-9][a-z0-9-]{0,98}[a-z0-9]$/', $slug)) {
            log_message('error', "ADMISSION_SLUG: invalid slug format — input=[{$ref}]");
            show_404();
            exit;
        }

        try {
            $map = $this->firebase->firestoreGet('admissionSlugs', $slug);
        } catch (\Throwable $e) {
            log_message('error', 'ADMISSION_SLUG: lookup failed for [' . $slug . '] — ' . $e->getMessage());
            show_404();
            exit;
        }

        $sid = is_array($map) ? trim((string) ($map['schoolId'] ?? '')) : '';
        if ($sid === '') {
            log_message('error', "ADMISSION_SLUG: no school for slug=[{$slug}]");
            show_404();
            exit;
        }
        return $sid;
    }

    // ─── PUBLIC: Render admission form ─────────────────────────────────

    public function form($school_id = '')
    {
        // Resolve a readable slug (…/admission/form/st-marys-school) to the
        // canonical school id BEFORE the guard — the guard's format check
        // rejects hyphens. Raw SCH_ ids pass straight through (old links/QRs
        // keep working). See _resolve_admission_ref().
        $school_id = $this->_resolve_admission_ref($school_id);

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

        // Resolve the active session — `schools/{id}.currentSession` is the
        // sole canonical authority (SC-Step11/G3). Fail-closed: if absent,
        // $activeSession is '' and the class list stays empty, so the form
        // renders "No classes available" rather than inventing a session.
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

        // The public form embeds a per-request CSRF token and is served fresh on
        // every open — it must never be cached. A stale cached copy would (a)
        // run outdated inline JS and (b) carry a dead CSRF token that silently
        // breaks submission. Force no-store so parents always get the live page.
        $this->output
            ->set_header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0')
            ->set_header('Pragma: no-cache')
            ->set_header('Expires: 0');

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

        // SC-Step11/G3 fail-closed: no canonical session → reject before any
        // write (do not persist a session-less application).
        if ($activeSession === '') {
            http_response_code(409);
            echo json_encode(['status' => 'error', 'message' => 'Admissions are not currently open for this school.']);
            return;
        }

        // ── Rate limiting: 10 submissions per IP per 15 minutes ───────────
        // Shared per-IP window guard (see _rate_limited). The submit bucket
        // uses the legacy "{school}_{ipKey}" doc id (bucket '') so existing
        // rate-limit docs keep counting; the payment endpoints use their own
        // buckets so they never exhaust the submit budget and vice-versa.
        $clientIp = $this->input->ip_address();
        if ($this->_rate_limited($school_id, $clientIp, 10, 900, '')) {
            http_response_code(429);
            echo json_encode(['status' => 'error', 'message' => 'Too many submissions. Please try again later.']);
            return;
        }

        // ── Honeypot bot guard (MED #9) ──
        // `company_website` is a visually-hidden field a real applicant never
        // sees. Any value means an automated bot blindly filled every input →
        // drop the submission. Not a substitute for reCAPTCHA (noted as an
        // optional follow-up) but stops naive form-spamming bots for free.
        if (trim((string) $this->input->post('company_website')) !== '') {
            log_message('error', "Admission_public::submit honeypot triggered ip=[{$clientIp}] school=[{$school_id}]");
            echo json_encode(['status' => 'error', 'message' => 'Submission could not be processed.']);
            return;
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

        // ── Optional file uploads: student photo, Aadhaar, supporting docs ──
        // Uploaded to Firebase Cloud Storage under admission/{school}/{appId}/…
        // (service-account write — independent of the parent's network and of
        // client Storage rules). The tokenized download URLs are persisted on
        // the application doc so the admin CRM can view them. Files are
        // OPTIONAL; an invalid file (wrong type / >1 MB) rejects the submit
        // with a clear message BEFORE the doc is written, rather than being
        // silently dropped.
        $uploads = $this->_handle_admission_uploads($school_id, $appId);
        if (!empty($uploads['error'])) {
            echo json_encode(['status' => 'error', 'message' => $uploads['error']]);
            return;
        }

        // Schema mirrors what admin's `convert_to_application` writes to
        // `crmApplications` so the same Pipeline / Inquiries / Applications
        // grids show public submissions without any normalization.
        // Phase E — entitlement DECLARATIONS captured by the shared
        // _entitlement_declarations.php partial. These are requests, not
        // grants: this controller is unauthenticated, so it can never create a
        // studentEntitlements record, and nothing here reduces any fee. They
        // are read back to pre-fill the staff capture component at admission,
        // where the canonical Entitlement_service creates the real entitlement
        // under maker-checker. Booleans are coerced server-side — a posted
        // string is never trusted as a flag.
        $declarations = [
            'sibling'        => (string) $this->input->post('decl_sibling')    === '1',
            // SR Number, which staff resolve against THIS school at enrolment.
            // `siblingName` was free text that nothing ever read; it is kept
            // only so an application submitted by an older cached copy of the
            // form still records what the family typed.
            'siblingSrNo'    => substr(trim((string) $this->input->post('decl_sibling_srno')), 0, 32),
            'siblingName'    => substr(trim((string) $this->input->post('decl_sibling_name')), 0, 120),
            // A parent CANNOT verify their own employment claim — this is an
            // unauthenticated form. The id is carried and resolved by staff at
            // enrolment; an unresolvable one becomes a visible warning there.
            'staffWardId'    => substr(trim((string) $this->input->post('decl_staff_ward_id')), 0, 64),
            'rte'            => (string) $this->input->post('decl_rte')        === '1',
            'rteRef'         => substr(trim((string) $this->input->post('decl_rte_ref')), 0, 120),
            'staffWard'      => (string) $this->input->post('decl_staff_ward') === '1',
            'transport'      => (string) $this->input->post('decl_transport')  === '1',
            'transportArea'  => substr(trim((string) $this->input->post('decl_transport_area')), 0, 120),
            'hostel'         => (string) $this->input->post('decl_hostel')     === '1',
            'declaredAt'     => date('c'),
            'source'         => 'public_form',
        ];

        $applicationData = [
            'id'                => $appId,
            // Phase E — family-declared concession/service requests (see above).
            'entitlementDeclarations' => $declarations,
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
            // Uploaded files (optional). `photo_url` and `aadhaar_url` are the
            // student photo + Aadhaar scan; `documents` holds any additional
            // supporting files [{name,url,category}]. Empty string / [] when
            // the parent skipped them.
            'photo_url'         => $uploads['photo_url'],
            'aadhaar_url'       => $uploads['aadhaar_url'],
            'documents'         => $uploads['documents'],
            // Canonical Document Service references (Phase 1). The *_url fields
            // above stay as denormalized pointers for existing readers; these
            // ids are the lifecycle handles (verify/replace/delete/link).
            'photoDocId'        => $uploads['photoDocId'],
            'aadhaarDocId'      => $uploads['aadhaarDocId'],
            'documentRefs'      => $uploads['documentRefs'],
            'history'           => [
                ['action' => 'Application submitted via public form', 'by' => 'Public', 'timestamp' => $now],
            ],
        ];

        // C7 — searchTokens for the CRM name-search (array-contains). Canonical
        // student-name tokenizer; the single source used at every write site.
        $this->load->helper('admission_search');
        $applicationData['searchTokens'] = admission_search_tokens($applicationData['student_name'] ?? '');

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
            "Public admission submitted: {$data['student_name']} for class {$data['class']}", $appId);

        // Hybrid admission-fee lookup: feeStructures (class-specific) first,
        // then schools.admissionFee (global). See _resolve_admission_fee().
        $feeInfo = $this->_resolve_admission_fee($school_id, $schoolDoc, $activeSession, $data['class']);

        echo json_encode([
            'status'           => 'success',
            'message'          => 'Application submitted successfully.',
            'app_id'           => $appId,
            'receipt_url'      => $receiptUrl,
            // Ownership proof (HIGH #5): the same HMAC receipt token, returned
            // so ONLY the browser that just submitted can start the payment.
            // initiate_payment refuses without a matching token → no PII
            // enumeration by iterating app_ids.
            'payment_token'    => $this->_receipt_token($school_id, $appId),
            'payment_required' => $feeInfo['enabled'] && $feeInfo['amount'] > 0,
            'payment_amount'   => $feeInfo['amount'],
            'payment_label'    => $feeInfo['label'],
            'payment_source'   => $feeInfo['source'], // "feeStructures" | "schools.admissionFee" | "none"
            'csrf_token'       => $this->security->get_csrf_hash(),
        ]);
    }

    /**
     * Handle OPTIONAL admission file uploads from the public form.
     *
     * Inputs (all optional): `photo` (student photo, images only),
     * `aadhaar_card` (image/PDF) and `documents[]` (multiple supporting
     * files). Each is validated against an extension + MIME allowlist and a
     * 1 MB cap, then uploaded to Firebase Cloud Storage under
     *   admission/{schoolId}/{appId}/…
     * using the existing service-account client (Firebase::uploadFile —
     * Admin write, so no client Storage rule is required). Returns the
     * tokenized download URLs for persistence on the application doc.
     *
     * On the first invalid file it returns early with a human-readable
     * `error`; the caller rejects the whole submit before writing anything.
     *
     * @return array{photo_url:string, aadhaar_url:string, documents:array, error:?string}
     */
    private function _handle_admission_uploads(string $school_id, string $appId): array
    {
        // Phase 1 — route uploads through the canonical Document Service:
        // each file becomes one canonical document (id-addressed, optimized,
        // audited) linked to this application. We STILL return the same
        // photo_url / aadhaar_url / documents[] shape (now enriched with the
        // documentId) so every existing reader — the CRM, the enrollment
        // carry-over, older records — keeps working unchanged (backward compat).
        $out = ['photo_url' => '', 'aadhaar_url' => '', 'documents' => [],
                'photoDocId' => '', 'aadhaarDocId' => '', 'documentRefs' => [], 'error' => null];
        if (empty($_FILES)) return $out;

        $this->load->library('document_service', null, 'docsvc');
        $this->docsvc->init($this->firebase, $school_id);

        // Build the canonical DENORMALIZED pointer for one uploaded document and
        // push it onto documentRefs[]. The service record (documents/{id}) stays
        // authoritative for lifecycle; this cached pointer lets the CRM render +
        // enrollment carry-over run with NO extra Firestore reads (ADR §Canonical).
        $pushRef = function (array $p, string $role) use (&$out): void {
            $out['documentRefs'][] = [
                'documentId'         => $p['documentId'],
                'type'               => $p['type'],
                'role'               => $role,
                'label'              => $p['label'],
                'url'                => $p['downloadUrl'],
                'category'           => $p['category'],
                'verificationStatus' => $p['verificationStatus'] ?? 'pending',
            ];
        };

        // Process one uploaded $_FILES entry via the service + link it to the
        // application. Returns [pointer|null, errorOrNull]; null pointer = "not
        // provided" (optional field skipped).
        $handle = function (array $file, string $type, string $role)
                use ($appId): array {
            $err = $file['error'] ?? UPLOAD_ERR_NO_FILE;
            if ($err === UPLOAD_ERR_NO_FILE) return [null, null];
            if ($err !== UPLOAD_ERR_OK)      return [null, 'A file failed to upload. Please try again.'];
            if ((int) ($file['size'] ?? 0) <= 0) return [null, null];

            $res = $this->docsvc->upload(
                (string) ($file['tmp_name'] ?? ''),
                (string) ($file['name'] ?? 'file'),
                $type,
                ['uploadedBy' => 'public']
            );
            if (!$res['ok']) {
                $label = ucfirst(str_replace('_', ' ', $type));
                return [null, "{$label}: " . $res['error'] . '.'];
            }
            $this->docsvc->link($res['document']['documentId'], 'admission', $appId, $role, 'public');
            return [$res['document'], null];
        };

        // Student photo (optimized to JPEG by the service)
        if (isset($_FILES['photo'])) {
            [$p, $e] = $handle($_FILES['photo'], 'photo', 'photo');
            if ($e !== null) { $out['error'] = $e; return $out; }
            if ($p) { $out['photo_url'] = $p['downloadUrl']; $out['photoDocId'] = $p['documentId']; $pushRef($p, 'photo'); }
        }
        // Aadhaar card
        if (isset($_FILES['aadhaar_card'])) {
            [$p, $e] = $handle($_FILES['aadhaar_card'], 'aadhaar', 'aadhaar');
            if ($e !== null) { $out['error'] = $e; return $out; }
            if ($p) { $out['aadhaar_url'] = $p['downloadUrl']; $out['aadhaarDocId'] = $p['documentId']; $pushRef($p, 'aadhaar'); }
        }
        // Additional predefined certificates (D2) — each an optional single-file
        // input; routed to its canonical type so it renders in its own CRM field.
        // input name === document type; role === type (predefined 1:1).
        foreach (['birth_certificate', 'transfer_certificate', 'marksheet', 'medical_certificate'] as $type) {
            if (!isset($_FILES[$type])) continue;
            [$p, $e] = $handle($_FILES[$type], $type, $type);
            if ($e !== null) { $out['error'] = $e; return $out; }
            if ($p) $pushRef($p, $type);
        }
        // Additional documents — <input name="documents[]" multiple>
        if (isset($_FILES['documents']) && is_array($_FILES['documents']['name'] ?? null)) {
            $n = count($_FILES['documents']['name']);
            if ($n > 10) { $out['error'] = 'Please upload at most 10 supporting documents.'; return $out; }
            for ($i = 0; $i < $n; $i++) {
                $one = [
                    'name'     => $_FILES['documents']['name'][$i]     ?? '',
                    'tmp_name' => $_FILES['documents']['tmp_name'][$i] ?? '',
                    'error'    => $_FILES['documents']['error'][$i]    ?? UPLOAD_ERR_NO_FILE,
                    'size'     => $_FILES['documents']['size'][$i]     ?? 0,
                ];
                [$p, $e] = $handle($one, 'additional', 'document');
                if ($e !== null) { $out['error'] = $e; return $out; }
                if ($p) {
                    $nm = preg_replace('/[^A-Za-z0-9._ -]/', '', (string) ($one['name'] ?: ('Document ' . ($i + 1))));
                    $label = substr($nm, 0, 80) ?: ('Document ' . ($i + 1));
                    $out['documents'][] = [
                        'name'       => $label,
                        'url'        => $p['downloadUrl'],
                        'category'   => 'document',
                        'documentId' => $p['documentId'],
                        'type'       => 'additional',
                    ];
                    // Enriched pointer (carry the parent-supplied label for display).
                    $ref = $p; $ref['label'] = $label;
                    $pushRef($ref, 'document');
                }
            }
        }

        return $out;
    }

    // ─── PAYMENT MODE: parent chooses "Pay at School" (offline) ────────

    /**
     * POST — Parent selected "Pay at School". Marks the application
     * 'awaiting_offline', server-resolves the (non-editable) fee, records a
     * reference, and tells the parent to pay at the office. The admin later
     * records the collection from Admission Leads (Sis::collect_admission_fee),
     * which is the ONLY place the receipt + accounting are posted.
     *
     * No money moves here — this only sets the intent + reference.
     */
    public function choose_offline($school_id = '')
    {
        header('Content-Type: application/json');
        if ($this->input->method() !== 'post') {
            http_response_code(405);
            echo json_encode(['status' => 'error', 'message' => 'POST required.']);
            return;
        }

        $school_id = validate_public_school_id($school_id);
        $this->fs->init($school_id);

        $appId = trim($this->input->post('app_id') ?? '');
        if ($appId === '' || !preg_match('/^[A-Za-z0-9_]+$/', $appId)) {
            echo json_encode(['status' => 'error', 'message' => 'Invalid application ID.']);
            return;
        }

        $app = $this->fs->get('crmApplications', $this->fs->docId($appId));
        if (!is_array($app)) {
            echo json_encode(['status' => 'error', 'message' => 'Application not found.']);
            return;
        }
        if (($app['payment_status'] ?? '') === 'paid') {
            echo json_encode(['status' => 'error', 'message' => 'Payment already completed for this application.']);
            return;
        }

        // Server-resolve the fee (never trust the client for the amount).
        $schoolDoc = $this->fs->get('schools', $school_id) ?? [];
        $session   = $this->_resolve_active_session($school_id, $schoolDoc);
        if ($session === '') {
            echo json_encode(['status' => 'error', 'message' => 'Admissions are not currently open for this school.']);
            return;
        }
        $feeInfo = $this->_resolve_admission_fee($school_id, $schoolDoc, $session, (string) ($app['class'] ?? ''));
        if (!$feeInfo['enabled'] || $feeInfo['amount'] <= 0) {
            echo json_encode(['status' => 'error', 'message' => 'Admission fee is not configured for this class/school.']);
            return;
        }

        $now       = date('Y-m-d H:i:s');
        $reference = 'ADMREF-' . strtoupper($appId);

        $this->fs->updateEntity('crmApplications', $appId, [
            'payment_status'  => 'awaiting_offline',
            'payment_mode'    => 'offline',
            'admission_ref'   => $reference,
            'fee_amount'      => (float) $feeInfo['amount'],
            'fee_head'        => (string) ($feeInfo['fee_head'] ?? 'Admission Fee'),
            'fee_label'       => (string) ($feeInfo['label'] ?? 'Admission Fee'),
            'updated_at'      => $now,
        ]);

        // Timeline (reused shared component).
        $this->load->library('Admission_collection', null, 'admissionCol');
        $this->admissionCol->init($this->firebase, $this->fs, $school_id, $session);
        $this->admissionCol->append_timeline($appId, 'offline_selected',
            "Parent chose Pay at School — ₹{$feeInfo['amount']} due, ref {$reference}", 'Parent');

        // Notify parent.
        $this->load->helper('notification');
        $schoolDisplayName = $schoolDoc['name'] ?? $schoolDoc['displayName'] ?? $schoolDoc['display_name'] ?? $school_id;
        notify_sms(
            $app['phone'] ?? '',
            "Your admission application for {$schoolDisplayName} is received. Please pay the admission fee of INR {$feeInfo['amount']} at the school office. Reference: {$reference}.",
            ['type' => 'admission_offline', 'app_id' => $appId]
        );

        $this->_public_audit($school_id, 'Admission', 'offline_selected', $appId,
            "Parent chose Pay at School — ref {$reference}, amount {$feeInfo['amount']}", $appId);

        echo json_encode([
            'status'         => 'success',
            'message'        => 'Your seat request is recorded. Please pay the admission fee at the school office.',
            'reference'      => $reference,
            'amount'         => (float) $feeInfo['amount'],
            'fee_label'      => (string) ($feeInfo['label'] ?? 'Admission Fee'),
            'csrf_token'     => $this->security->get_csrf_hash(),
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

        // Rate-limit (HIGH #6): 20 initiations per IP / 15 min, own bucket so
        // it never eats into the submit budget.
        $clientIp = $this->input->ip_address();
        if ($this->_rate_limited($school_id, $clientIp, 20, 900, 'pay')) {
            http_response_code(429);
            echo json_encode(['status' => 'error', 'message' => 'Too many payment attempts. Please try again later.']);
            return;
        }

        $appId = trim($this->input->post('app_id') ?? '');
        if ($appId === '' || !preg_match('/^[A-Za-z0-9_]+$/', $appId)) {
            echo json_encode(['status' => 'error', 'message' => 'Invalid application ID.']);
            return;
        }

        // OWNERSHIP PROOF (HIGH #5) — require the HMAC receipt token issued to
        // the browser at submit() time. Without it we return NO application
        // PII, so a stranger can't enumerate app_ids to harvest
        // name/email/phone. Constant-time compare (token derives from
        // encryption_key; a guesser can't forge it).
        $token = trim($this->input->post('token') ?? '');
        if ($token === '' || !hash_equals($this->_receipt_token($school_id, $appId), $token)) {
            log_message('error', "Admission_public::initiate_payment token mismatch app=[{$appId}] school=[{$school_id}] ip=[{$clientIp}]");
            http_response_code(403);
            echo json_encode(['status' => 'error', 'message' => 'This payment link is invalid or has expired. Please re-submit the form.']);
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
        // SC-Step11/G3 fail-closed: no canonical session → no payment order.
        if ($session === '') {
            echo json_encode(['status' => 'error', 'message' => 'Admissions are not currently open for this school.']);
            return;
        }
        $appClass = (string) ($app['class'] ?? '');
        $feeInfo = $this->_resolve_admission_fee($school_id, $schoolDoc, $session, $appClass);
        if (!$feeInfo['enabled'] || $feeInfo['amount'] <= 0) {
            echo json_encode(['status' => 'error', 'message' => 'Admission fee is not configured for this class/school.']);
            return;
        }
        $amount   = (float) $feeInfo['amount'];
        $currency = (string) $feeInfo['currency'];

        // Currency guard (#8): the Razorpay adapter + paise math below assume
        // INR. A USD/GBP/EUR fee would be charged as the same number of
        // rupees — a wrong amount. Fail closed until multi-currency is wired
        // end-to-end (create_order currency + display), rather than silently
        // taking the wrong amount.
        if (strtoupper($currency) !== 'INR') {
            log_message('error', "Admission_public::initiate_payment unsupported currency [{$currency}] school=[{$school_id}] app=[{$appId}]");
            echo json_encode(['status' => 'error', 'message' => 'Online payment for this fee currency is not supported yet. Please contact the school office.']);
            return;
        }

        // Resolve and load the gateway adapter (Razorpay or mock).
        try {
            $gw = $this->_load_payment_gateway($school_id, $schoolDoc);
        } catch (\Exception $e) {
            log_message('error', 'Admission_public::initiate_payment gateway load failed: ' . $e->getMessage());
            echo json_encode(['status' => 'error', 'message' => 'Payment gateway is not configured. Please contact the school office.']);
            return;
        }

        // FAIL-CLOSED (C2): never let the mock gateway collect a real fee in
        // production. A fee IS due here (amount > 0 was checked above); if the
        // resolved gateway is 'mock' the school simply hasn't finished Razorpay
        // setup. Marking such an application "paid" via the mock path would
        // record money that was never collected — refuse instead. Mock stays
        // available in dev/testing where ENVIRONMENT !== 'production'.
        if ($gw['name'] === 'mock' && !in_array(ENVIRONMENT, ['development', 'testing'], true)) {
            log_message('error', "Admission_public::initiate_payment BLOCKED mock gateway in production school=[{$school_id}] app=[{$appId}] amount=[{$amount}]");
            echo json_encode(['status' => 'error', 'message' => 'Online payment is not available for this school yet. Please contact the school office to complete your admission.']);
            return;
        }

        // De-dup (#11): a double-click / retry within the window should reuse
        // the existing fresh order rather than mint a second Razorpay order
        // that orphans the first. If the application already points at a
        // 'created' payment of the same amount from the last 15 min, return
        // it verbatim.
        $existingPid = (string) ($app['payment_id'] ?? '');
        if ($existingPid !== '') {
            $existing = $this->fs->get('admissionPayments', $this->fs->docId($existingPid));
            // GW-ORD-1 (admission variant) — an order id is only meaningful to
            // the gateway that issued it. Reusing one minted by a different
            // adapter hands the checkout an id the gateway never issued, which
            // it refuses the instant the sheet renders — indistinguishable, to
            // an applicant, from a declined card.
            //
            // Materially safer here than in the fees path: the 15-minute window
            // below means a mismatched order expires on its own rather than
            // permanently poisoning the combination. Guarded anyway, because
            // "wrong for at most 15 minutes" is still wrong, and the check costs
            // one comparison. An order with no recorded gateway cannot be shown
            // to belong to the active one, so it is not reused either.
            // One shared definition with the fees path — see
            // Payment_order_provenance. Two separately-written predicates is how
            // the same defect came to exist in both places.
            $this->load->library('Payment_order_provenance');
            $sameGateway = is_array($existing)
                && Payment_order_provenance::matchesActiveGateway(
                       $existing['gateway'] ?? '',
                       (string) ($gw['name'] ?? '')
                   );

            if (is_array($existing)
                && $sameGateway
                && ($existing['status'] ?? '') === 'created'
                && abs((float) ($existing['amount'] ?? 0) - $amount) < 0.01
                && (strtotime((string) ($existing['created_at'] ?? '')) ?: 0) >= time() - 900) {
                echo json_encode([
                    'status'       => 'success',
                    'payment_id'   => $existing['payment_id'] ?? $existingPid,
                    'order_id'     => $existing['order_id'] ?? '',
                    'amount'       => $amount,
                    'amount_paise' => (int) ($amount * 100),
                    'currency'     => $currency,
                    'school_name'  => $schoolDoc['name'] ?? $schoolDoc['displayName'] ?? $schoolDoc['display_name'] ?? $school_id,
                    'student_name' => $app['student_name'] ?? '',
                    'email'        => $app['email'] ?? '',
                    'phone'        => $app['phone'] ?? '',
                    'gateway'      => $existing['gateway'] ?? $gw['name'],
                    'key'          => $gw['public_key'],
                    'reused'       => true,
                    'csrf_token'   => $this->security->get_csrf_hash(),
                ]);
                return;
            }
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
            // Fee head + label captured at initiate time so the confirmation
            // path (receipt + accounting) credits the CONFIGURED income account
            // (Admission/Registration/Prospectus) and stamps the right receipt
            // type — without re-resolving the fee later.
            'fee_head'     => (string) ($feeInfo['fee_head'] ?? 'Admission Fee'),
            'fee_label'    => (string) ($feeInfo['label']    ?? 'Admission Fee'),
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

    /**
     * CANONICAL captured-amount verification — the SINGLE payment-validation
     * policy shared by BOTH the client callback and the server webhook (C3 + M1).
     * Mirrors the certified Fees BUG-050/PS-3 policy exactly:
     *
     *   • gateway = mock              → ACCEPT (no real captured amount to check)
     *   • fetch_payment unavailable   → ACCEPT (fail-open + telemetry — never brick
     *                                   a transport-authenticated payment on a
     *                                   Razorpay API outage / missing capability)
     *   • not captured                → REJECT (kills payment.failed/authorized → C3)
     *   • order_id mismatch           → REJECT (binding)
     *   • |expected − captured| >₹0.50 → REJECT (amount tampering → M1)
     *   • else                        → ACCEPT
     *
     * Both entry points call THIS and honour its verdict identically, so there is
     * no scenario where one accepts a payment the other would reject.
     *
     * @param array  $gw              loaded gateway {name, adapter}
     * @param string $gatewayPid      Razorpay payment id to fetch
     * @param string $expectedOrderId order id the local payment record is bound to
     * @param float  $expectedAmount  locally-stored order amount (rupees)
     * @return array {ok:bool, reason:string, gateway_amount:?float}
     */
    private function _assert_captured_amount(array $gw, string $gatewayPid, string $expectedOrderId, float $expectedAmount): array
    {
        $adapter = $gw['adapter'] ?? null;

        // ── CAPABILITY, NOT PROVIDER NAME (2026-08-24) ─────────────────────
        // This began `if ($gw['name'] === 'mock')` — a provider-NAME gate, the
        // same shape as the allowlist-of-one that disabled verification for
        // every non-Razorpay provider in payment_callback. It also duplicated
        // the `method_exists(fetch_payment)` probe below, so two conditions
        // answered one question and could disagree.
        //
        // `reports_captured_amount` subsumes both: an adapter that cannot
        // report what the provider captured has nothing to compare, whatever
        // it is called. A second simulator, or any future adapter without a
        // fetch API, is handled correctly without being named here.
        $caps = (is_object($adapter) && method_exists($adapter, 'capabilities'))
            ? $adapter->capabilities()
            : [];
        $reportsAmount = !empty($caps['reports_captured_amount']);

        if (!$reportsAmount || !is_object($adapter) || !method_exists($adapter, 'fetch_payment')) {
            log_message('error', 'ADM_CAPTURE_UNVERIFIED no_fetch_capability payment=' . $gatewayPid
                . ' adapter=[' . (string) ($gw['name'] ?? 'unknown') . ']');
            return ['ok' => true, 'reason' => 'no_fetch_capability', 'gateway_amount' => null];
        }
        try {
            $fp = $adapter->fetch_payment($gatewayPid);
        } catch (\Throwable $e) {
            log_message('error', "ADM_CAPTURE_UNVERIFIED fetch_error payment={$gatewayPid}: " . $e->getMessage());
            return ['ok' => true, 'reason' => 'fetch_error', 'gateway_amount' => null];
        }

        $gwAmount = round((float) ($fp['amount'] ?? 0), 2);
        $gwOrder  = (string) ($fp['order_id'] ?? '');

        if (empty($fp['captured'])) {
            return ['ok' => false, 'reason' => 'not_captured', 'gateway_amount' => $gwAmount];
        }
        if ($expectedOrderId !== '' && $gwOrder !== '' && $gwOrder !== $expectedOrderId) {
            return ['ok' => false, 'reason' => 'order_mismatch', 'gateway_amount' => $gwAmount];
        }
        // ── MONEY-1 ON THE ADMISSION SURFACE (2026-08-24) ──────────────────
        // This was `abs($expectedAmount - $gwAmount) > 0.50` — a ±₹0.50 FLOAT
        // tolerance. Fee_management fixed exactly this (MONEY-1) and Admission
        // never received the fix, on the surface that is PUBLIC and
        // unauthenticated.
        //
        // The money never was a float: providers settle in PAISE and return an
        // integer, which the adapters already carry as `amount_paise`. The old
        // window was ~50x wider than the ≤₹0.01 error rounding can introduce,
        // so a genuine capture shortfall of up to fifty paise counted as a
        // match — a blind spot created solely by discarding an exact integer
        // that was already in hand.
        //
        // Compare exactly when the provider gave us minor units; fall back to
        // the historical tolerance only when it did not, rather than failing a
        // real payment over a precision the provider does not expose.
        $gwMinor = (isset($fp['amount_paise']) && is_numeric($fp['amount_paise']))
            ? (int) $fp['amount_paise']
            : null;

        if ($gwMinor !== null) {
            // round() before the cast: (int)(6000 * 100) can be 599999 for
            // values a float cannot represent precisely.
            $expectedMinor = (int) round($expectedAmount * 100);
            if ($expectedMinor !== $gwMinor) {
                return ['ok' => false, 'reason' => 'amount_mismatch', 'gateway_amount' => $gwAmount];
            }
        } elseif (abs($expectedAmount - $gwAmount) > 0.50) {
            log_message('error', 'ADM_CAPTURE_TOLERANT_COMPARE payment=' . $gatewayPid
                . ' — adapter did not supply amount_paise, so the exact comparison was not '
                . 'possible and the ±0.50 fallback was used.');
            return ['ok' => false, 'reason' => 'amount_mismatch', 'gateway_amount' => $gwAmount];
        }
        return ['ok' => true, 'reason' => 'verified', 'gateway_amount' => $gwAmount];
    }

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
        // FAIL-CLOSED (C2): the mock gateway must never confirm a payment in
        // production. If we reach here with mock in prod, the order was
        // created before Razorpay was configured (or config regressed) —
        // refuse to fake a "paid" confirmation.
        if ($gw['name'] === 'mock' && !in_array(ENVIRONMENT, ['development', 'testing'], true)) {
            log_message('error', "Admission_public::payment_callback BLOCKED mock confirmation in production pid=[{$paymentId}] order=[{$orderId}]");
            echo json_encode(['status' => 'error', 'message' => 'Online payment is not available for this school yet. Please contact the school office.']);
            return;
        }

        if ($gw['name'] === 'mock') {
            $simResult  = $gw['adapter']->simulate_payment($orderId, (float)($payment['amount'] ?? 0));
            $gatewayPid = $simResult['payment_id'];
            $signature  = $simResult['signature'] ?? '';
            $verified   = $simResult['status'] === 'success'
                        && $gw['adapter']->verify_signature($orderId, $gatewayPid, $signature);
        } else {
            $verified = $gw['adapter']->verify_signature($orderId, $gatewayPid, $signature);
        }

        // Amount re-verification (#3): a valid signature only proves the
        // payload wasn't tampered — it does NOT prove the payment was
        // actually captured for the right amount. For real Razorpay, re-fetch
        // the payment server-side and confirm it is CAPTURED for the exact
        // order amount before trusting it. Defends against a signed-but-
        // uncaptured / wrong-amount payment.
        // ── GATE ON CAPABILITY, NOT PROVIDER NAME (2026-08-24) ─────────────
        // This read `$gw['name'] === 'razorpay'` — an ALLOWLIST OF ONE. The
        // moment a second provider is enabled, that check is false and this
        // entire amount/capture re-verification is SKIPPED, on a public,
        // unauthenticated admission form.
        //
        // Fee_management hit exactly this defect and fixed it (GW-2, see the
        // AMOUNTLESS_GATEWAYS note there); Admission never got the same fix.
        // The correct question was never "is this Razorpay?" but "can this
        // adapter tell me what was actually captured?" — which the adapter
        // itself now answers via capabilities(). An adapter that cannot
        // report a captured amount (a simulator, the refusal sentinel) has
        // nothing to compare, and that is a different thing from skipping the
        // comparison.
        $__caps = method_exists($gw['adapter'], 'capabilities')
            ? $gw['adapter']->capabilities() : [];
        $__canReportAmount = !empty($__caps['reports_captured_amount']);

        if ($verified && !$__canReportAmount) {
            // Loud, not silent. A provider that cannot support defence-in-depth
            // amount verification is a real operational fact, not a non-event.
            log_message('error', 'Admission_public::payment_callback PAYMENT_AMOUNT_UNVERIFIED '
                . '(fail-open) — adapter [' . (string) ($gw['name'] ?? 'unknown') . '] does not '
                . 'report a captured amount, so capture/amount re-verification was not possible.');
        }

        if ($verified && $__canReportAmount) {
            try {
                $remote   = $gw['adapter']->fetch_payment($gatewayPid);
                $expected = (float) ($payment['amount'] ?? 0);
                $captured = round((float) ($remote['amount'] ?? 0), 2);
                if (empty($remote['captured'])
                    || (string) ($remote['status'] ?? '') !== 'captured'
                    || abs($captured - $expected) > 0.01) {
                    log_message('error', "Admission_public::payment_callback amount/capture mismatch pid=[{$gatewayPid}] expected=[{$expected}] got=[{$captured}] status=[" . (string) ($remote['status'] ?? '') . "]");
                    $verified = false;
                }
            } catch (\Exception $e) {
                // Could not confirm with the gateway — do NOT mark paid on an
                // unverifiable payment.
                log_message('error', "Admission_public::payment_callback fetch_payment failed pid=[{$gatewayPid}]: " . $e->getMessage());
                $verified = false;
            }
        }

        $now   = date('Y-m-d H:i:s');
        $appId = $payment['app_id'] ?? '';

        if ($verified) {
            // ── C3 + M1: canonical captured-amount verification (SHARED w/ webhook) ──
            // Signature proves the client didn't forge the binding; this proves the
            // gateway actually CAPTURED the expected amount for this order. Same
            // helper + verdict the webhook applies — see _assert_captured_amount().
            $cap = $this->_assert_captured_amount($gw, $gatewayPid, $orderId, (float) ($payment['amount'] ?? 0));
            if (empty($cap['ok'])) {
                $this->fs->updateEntity('admissionPayments', $paymentId, [
                    'status'             => $cap['reason'] === 'amount_mismatch' ? 'amount_mismatch' : 'capture_failed',
                    'gateway_payment_id' => $gatewayPid,
                    'gateway_amount'     => $cap['gateway_amount'],
                    'failure_reason'     => 'capture verification failed: ' . $cap['reason'],
                    'failed_at'          => $now,
                ]);
                $this->_public_audit($school_id, 'Payment', 'payment_capture_rejected', $paymentId,
                    "Capture REJECTED (callback) app={$appId} reason={$cap['reason']} gwAmount=" . ($cap['gateway_amount'] ?? 'n/a'), $appId);
                log_message('error', "ADM_CAPTURE_REJECTED source=callback reason={$cap['reason']} order={$orderId} payment={$paymentId}");
                echo json_encode([
                    'status'     => 'error',
                    'message'    => 'We could not verify this payment with the gateway. If any amount was debited it will be reversed automatically — please do not pay again; contact the school if unsure.',
                    'csrf_token' => $this->security->get_csrf_hash(),
                ]);
                return;
            }

            // Reconcile through the SHARED path so the client callback and the
            // server-to-server webhook mark 'paid' identically (no duplicate
            // business logic). Idempotent: a replay returns already_paid.
            $result = $this->_apply_admission_payment_paid(
                $school_id, $schoolDoc, $payment, $gatewayPid, $signature, 'callback'
            );

            // Surface the FEE receipt (ADM…) so the parent can download it right
            // after paying. Token-secured public route (no auth) — same pattern
            // as the application-acknowledgement receipt.
            $feeReceiptNo  = (string) ($result['receipt_no'] ?? '');
            $feeReceiptUrl = $feeReceiptNo !== '' ? $this->_fee_receipt_url($school_id, $feeReceiptNo) : '';

            echo json_encode([
                'status'          => 'success',
                'message'         => ($result['already_paid'] ?? false)
                                ? 'Payment already confirmed.'
                                : 'Payment verified successfully. Awaiting school review.',
                'receipt_no'      => $feeReceiptNo,
                'fee_receipt_url' => $feeReceiptUrl,
                'csrf_token'      => $this->security->get_csrf_hash(),
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
                "Payment verification failed for app {$appId}", $appId);

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

    // ─── Shared reconciliation (client callback + server webhook) ──────

    /**
     * Mark an admission payment as PAID and reconcile the application.
     *
     * The SINGLE place that flips admissionPayments → 'paid' + crmApplications
     * → payment_status 'paid'. Called by BOTH payment_callback (browser, after
     * signature verify) and payment_webhook (server-to-server, after webhook
     * HMAC verify), so there is exactly one reconciliation implementation —
     * mirroring how Fee_management reuses _verify_and_process for both paths.
     *
     * Idempotent: if the payment is already 'paid' it no-ops (no double SMS /
     * double audit / double receipt), so a replayed webhook or a
     * callback-then-webhook race both converge to one confirmation.
     *
     * On first confirmation it ALSO generates the official admission receipt
     * (ADM-series) and posts the accounting journal through the canonical fee
     * engine (Cr configurable Admission income account, Dr cash/bank) — via the
     * shared _post_admission_receipt_and_accounting() so the online (here) and
     * offline (admin counter) paths reuse one receipt + accounting path.
     *
     * @return array { ok: bool, already_paid: bool, receipt_no?: string }
     */
    private function _apply_admission_payment_paid(
        string $school_id, array $schoolDoc, array $payment,
        string $gatewayPid, string $signature, string $source
    ): array {
        $paymentId = (string) ($payment['payment_id'] ?? '');
        $now       = date('Y-m-d H:i:s');

        // Idempotency guard — already confirmed → no-op.
        if (($payment['status'] ?? '') === 'paid') {
            return ['ok' => true, 'already_paid' => true, 'receipt_no' => (string) ($payment['receipt_no'] ?? '')];
        }

        // Mark payment as paid in Firestore.
        $this->fs->updateEntity('admissionPayments', $paymentId, [
            'status'             => 'paid',
            'gateway_payment_id' => $gatewayPid,
            'signature'          => $signature,
            'verified_at'        => $now,
            'confirmed_via'      => $source, // 'callback' | 'webhook'
        ]);

        // Receipt + accounting (ONLINE): the money settled to the gateway's
        // bank, so post as an 'online' collection. 'online' is in the engine's
        // debit-mode list (Operations_accounting line 867), so with an empty
        // bank_code the engine auto-resolves the school's active BANK ledger for
        // the debit (falls back to Cash 1010 only if no bank ledger exists) —
        // never miscategorised as cash. Reuses the canonical fee engine.
        $appId   = (string) ($payment['app_id'] ?? '');
        $session = $this->_resolve_active_session($school_id, $schoolDoc);
        $this->load->library('Admission_collection', null, 'admissionCol');
        $this->admissionCol->init($this->firebase, $this->fs, $school_id, $session);
        $rc = $this->admissionCol->record_payment([
            'app_id'             => $appId,
            'amount'             => (float) ($payment['amount'] ?? 0),
            'currency'           => (string) ($payment['currency'] ?? 'INR'),
            'student_name'       => (string) ($payment['student_name'] ?? ''),
            'fee_head'           => (string) ($payment['fee_head'] ?? 'Admission Fee'),
            'fee_label'          => (string) ($payment['fee_label'] ?? 'Admission Fee'),
            'payment_mode'       => 'online',   // engine → Dr Bank (auto-resolve)
            'mode_label'         => 'Online',
            'bank_code'          => '',
            'collected_by'       => 'Payment Gateway',
            'source'             => $source,    // 'callback' | 'webhook'
            'admission_ref'      => $appId,
            'payment_id'         => $paymentId,
            'gateway_payment_id' => $gatewayPid,
        ]);
        $receiptNo = is_array($rc) ? (string) ($rc['receipt_no'] ?? '') : '';

        // Stamp the receipt no onto the payment (idempotency anchor: a re-entry
        // is short-circuited by the already-paid guard above, so the receipt is
        // minted exactly once).
        if ($receiptNo !== '') {
            $this->fs->updateEntity('admissionPayments', $paymentId, [
                'receipt_no'   => $receiptNo,
                'receipted_at' => $now,
            ]);
        }

        // Update application — payment_status + history. Status/stage NOT
        // auto-advanced; admin still reviews and approves manually. (The
        // library already appended the shared 'payment_collected' timeline.)
        if ($appId !== '') {
            $appUpdate = [
                'payment_status' => 'paid',
                'updated_at'     => $now,
            ];
            // Only stamp receipt_no when THIS call actually minted it. A concurrent
            // reconcile that lost the idempotency claim returns '' (in_progress) —
            // it must NOT clobber the winner's receipt_no (admin "Print Receipt"
            // keys off this field). The winner sets it; the loser leaves it intact.
            if ($receiptNo !== '') {
                $appUpdate['receipt_no'] = $receiptNo;
            }
            // M7-B: single CAS-atomic write (payment fields + bounded history),
            // concurrency-safe. dedupeKey on the gateway payment id makes the
            // callback/webhook race idempotent — one "fee paid" history entry.
            // Financial truth is in the payment record + receipt + journal; this
            // recent-cache maintenance never gates payment success.
            $this->load->helper('admission_crm');
            $this->fs->casAppendBounded('crmApplications', $appId, 'history',
                [
                    'action'    => "Admission fee paid ({$gatewayPid})" . ($receiptNo !== '' ? " — Receipt {$receiptNo}" : ''),
                    'by'        => $source === 'webhook' ? 'Payment Gateway (webhook)' : 'Payment Gateway',
                    'timestamp' => $now,
                ],
                crm_history_cap(),
                $appUpdate,
                'paid_' . $gatewayPid);
        }

        // Notify parent. Currency falls back to INR.
        $this->load->helper('notification');
        $schoolDisplayName = $schoolDoc['name']
            ?? $schoolDoc['displayName']
            ?? $schoolDoc['display_name']
            ?? $school_id;
        $payCurrency = $payment['currency'] ?? 'INR';
        $rcptMsg = $receiptNo !== '' ? " Receipt: {$receiptNo}." : '';
        notify_sms(
            $payment['phone'] ?? '',
            "Payment of {$payCurrency} {$payment['amount']} received for {$schoolDisplayName} admission (Ref: {$paymentId}).{$rcptMsg} Your application is awaiting school review.",
            ['type' => 'payment_success', 'payment_id' => $paymentId]
        );

        // AUDIT — log successful payment.
        $this->_public_audit($school_id, 'Payment', 'payment_success', $paymentId,
            "Admission fee paid: {$payCurrency} {$payment['amount']} for app {$appId} (via {$source})"
            . ($receiptNo !== '' ? " — Receipt {$receiptNo}" : ''), $appId);

        return ['ok' => true, 'already_paid' => false, 'receipt_no' => $receiptNo];
    }

    /**
     * Engine failure routing for the REUSED accounting engine.
     *
     * Operations_accounting::create_fee_journal calls $CI->json_error() to halt
     * a fee-counter WEB request on period-lock / missing-account / unbalanced
     * failures. In the admission (public) context the payment has ALREADY
     * succeeded, so halting/fatal would be wrong. We THROW instead, so the
     * caller's try/catch treats a journal failure as SOFT (logged; the receipt
     * and paid-status stand and can be reconciled) — never fatal, never a
     * half-finished confirmation. Mirrors PayrollReconciler::json_error.
     * Public because the engine invokes it on $this (the injected CI).
     */
    public function json_error(string $message, int $http_code = 400): void
    {
        throw new \RuntimeException($message);
    }

    // ─── PAYMENT: Server-to-server webhook reconciliation ──────────────

    /**
     * POST — Razorpay server-to-server webhook for admission payments.
     *
     * The authoritative recovery path: when the parent's browser drops after
     * Razorpay captures the money but before payment_callback fires, this
     * server-to-server call reconciles the payment so it is NEVER stranded.
     * Mirrors Fee_management::payment_webhook (IP whitelist, config-drift
     * refusal, timestamp replay protection, webhook-secret HMAC with live-mode
     * enforcement, event-id dedup) and reuses the SAME shared reconciliation
     * as the client callback (_apply_admission_payment_paid).
     *
     * Tenant scope comes from the URL ({schoolId}) — each school configures
     * its own Razorpay webhook to .../admission/payment_webhook/{schoolId}.
     * No admin session (gateway calls this directly); CSRF-excluded in config.
     */
    public function payment_webhook($school_id = '')
    {
        header('Content-Type: application/json');

        if ($this->input->method() !== 'post') {
            http_response_code(405);
            echo json_encode(['status' => 'error', 'message' => 'POST required.']);
            return;
        }

        // SCHOOL GUARD — tenant scope from the URL (Firestore-only).
        $school_id = validate_public_school_id($school_id);
        $this->fs->init($school_id);

        $schoolDoc = $this->fs->get('schools', $school_id) ?? [];
        $session   = $this->_resolve_active_session($school_id, $schoolDoc);

        // Gateway config — the SAME feeSettings/{...}_gateway doc the fees
        // module and initiate_payment use (mode / webhook_secret / IP list).
        $gwConfig   = null;
        $schoolName = (string) ($schoolDoc['name'] ?? $schoolDoc['schoolName'] ?? $schoolDoc['displayName'] ?? $school_id);
        if ($session !== '') {
            try {
                $gwConfig = $this->fs->get('feeSettings', "{$schoolName}_{$session}_gateway");
                if (!is_array($gwConfig)) {
                    $gwConfig = $this->fs->get('feeSettings', "{$school_id}_{$session}_gateway");
                }
                if (!is_array($gwConfig)) {
                    foreach ((array) $this->fs->schoolList('feeSettings', [['type', '==', 'gateway']]) as $r) {
                        $rowSession = (string) ($r['session'] ?? '');
                        if ($rowSession === '' || $rowSession === $session) { $gwConfig = $r; break; }
                    }
                }
            } catch (\Exception $e) {
                log_message('error', 'Admission_public::payment_webhook gateway config read failed: ' . $e->getMessage());
            }
        }

        $ip     = $this->input->ip_address();
        $gwMode = is_array($gwConfig) ? (string) ($gwConfig['mode'] ?? '') : '';
        $apiKey = is_array($gwConfig) ? (string) ($gwConfig['api_key'] ?? '') : '';

        // ── IP whitelist (Razorpay + localhost + configured) ──
        $defaultIPs = ['52.66.166.11', '52.66.171.94', '3.7.116.0/24', '127.0.0.1', '::1'];
        $extraIPs   = is_array($gwConfig) && is_array($gwConfig['webhook_allowed_ips'] ?? null) ? $gwConfig['webhook_allowed_ips'] : [];
        $ipAllowed  = false;
        foreach (array_merge($defaultIPs, $extraIPs) as $allowed) {
            if ($allowed === $ip) { $ipAllowed = true; break; }
            if (strpos($allowed, '/') !== false && $this->_ip_in_cidr($ip, $allowed)) { $ipAllowed = true; break; }
        }
        if (!$ipAllowed) {
            log_message('error', "Admission_public::payment_webhook IP BLOCKED ip={$ip} school={$school_id}");
            http_response_code(403);
            echo json_encode(['status' => 'error', 'message' => 'IP not whitelisted.']);
            return;
        }

        // ── Config-drift refusal (live key vs mode) ──
        $isLiveKey = strpos($apiKey, 'rzp_live_') === 0;
        $isTestKey = strpos($apiKey, 'rzp_test_') === 0;
        if (($isLiveKey && $gwMode !== 'live') || ($isTestKey && $gwMode === 'live')) {
            log_message('error', "Admission_public::payment_webhook CONFIG DRIFT key/mode mismatch school={$school_id}");
            http_response_code(500);
            echo json_encode(['status' => 'error', 'message' => 'Gateway configuration mismatch. Contact admin.']);
            return;
        }

        // ── Raw body ──
        $rawBody = (string) file_get_contents('php://input');
        $payload = json_decode($rawBody, true);
        if (!is_array($payload)) $payload = $this->input->post() ?: [];

        // ── Timestamp replay protection (5-min window) ──
        $ts = $_SERVER['HTTP_X_RAZORPAY_TIMESTAMP'] ?? ($payload['timestamp'] ?? '');
        if ($ts !== '' && abs(time() - (int) $ts) > 300) {
            log_message('error', "Admission_public::payment_webhook REPLAY REJECTED school={$school_id} ip={$ip}");
            http_response_code(401);
            echo json_encode(['status' => 'error', 'message' => 'Webhook expired (replay protection).']);
            return;
        }

        // ── Webhook HMAC (webhook_secret; live-mode enforced) ──
        $webhookSecret = is_array($gwConfig) ? (string) ($gwConfig['webhook_secret'] ?? '') : '';
        $webhookSig    = $_SERVER['HTTP_X_RAZORPAY_SIGNATURE'] ?? $_SERVER['HTTP_X_WEBHOOK_SIGNATURE'] ?? ($payload['webhook_signature'] ?? '');
        // ── GW-SEC2 · KEYED ON THE PROVIDER, NOT THE MODE ────────────────────
        // Identical to the fee webhook's defect. These guards keyed on
        // `$gwMode === 'live'`, but mode is not what makes a webhook worth
        // authenticating — a real payment provider is. An unconfigured tenant
        // resolves $gwMode to '', so neither guard fired and the HMAC check
        // below was skipped: an unsigned POST was accepted.
        //
        // This endpoint is PUBLIC and unauthenticated, which makes it the more
        // exposed of the two. Any real provider must present a secret AND a
        // signature, in test mode as much as live; only a simulator may skip,
        // and GW-1b already confines that to development/testing.
        $gwProvider   = is_array($gwConfig) ? (string) ($gwConfig['provider'] ?? '') : '';
        $realProvider = ($gwProvider !== '' && $gwProvider !== 'mock');

        if ($realProvider && $webhookSecret === '') {
            log_message('error', "Admission_public::payment_webhook provider={$gwProvider} but webhook_secret missing school={$school_id} mode={$gwMode}");
            http_response_code(500);
            echo json_encode(['status' => 'error', 'message' => 'Webhook secret not configured for this school.']);
            return;
        }
        if ($realProvider && $webhookSig === '') {
            log_message('error', "Admission_public::payment_webhook provider={$gwProvider} but no signature ip={$ip}");
            http_response_code(401);
            echo json_encode(['status' => 'error', 'message' => 'Missing webhook signature.']);
            return;
        }
        if ($webhookSecret !== '' && $webhookSig !== '') {
            // GW-SEC4: current secret, or a rotated-out one inside a bounded
            // grace window. See webhook_signature_helper for why rotation must
            // be handled here rather than by widening any retry path.
            $this->load->helper('webhook_signature');
            if (!webhook_signature_valid($rawBody, $webhookSig, is_array($gwConfig) ? $gwConfig : [])) {
                log_message('error', "Admission_public::payment_webhook HMAC FAILED school={$school_id} ip={$ip}");
                http_response_code(401);
                echo json_encode(['status' => 'error', 'message' => 'Invalid webhook signature.']);
                return;
            }
        } elseif ($webhookSecret === '') {
            log_message('warning', "Admission_public::payment_webhook webhook_secret empty — HMAC skipped (mode={$gwMode}, school={$school_id})");
        }

        // ── Extract order_id / payment_id (nested Razorpay entity or flat) ──
        $entity      = (array) ($payload['payload']['payment']['entity'] ?? []);
        $gwOrderId   = (string) ($payload['razorpay_order_id']   ?? $payload['order_id']   ?? ($entity['order_id'] ?? ''));
        $gwPaymentId = (string) ($payload['razorpay_payment_id'] ?? $payload['payment_id'] ?? ($entity['id']       ?? ''));
        if ($gwOrderId === '') {
            http_response_code(400);
            echo json_encode(['status' => 'error', 'message' => 'Missing order_id.']);
            return;
        }

        // ── Event-id dedup (idempotent replays) — tenant-scoped doc ──
        $eventId = $_SERVER['HTTP_X_RAZORPAY_EVENT_ID'] ?? ($payload['event_id'] ?? '');
        $hash    = $eventId !== '' ? md5($eventId) : md5($rawBody);
        $dedupId = "{$school_id}_{$hash}";
        $seen    = $this->fs->get('admissionPaymentIdempotency', $dedupId);
        if (is_array($seen) && !empty($seen['processed_at'])) {
            http_response_code(200);
            echo json_encode(['status' => 'success', 'message' => 'Already processed (duplicate webhook).']);
            return;
        }

        // ── Resolve the admissionPayments doc by order_id (tenant-scoped) ──
        $payment = null;
        try {
            foreach ((array) $this->fs->schoolList('admissionPayments', [['order_id', '==', $gwOrderId]]) as $r) {
                $payment = $r; break;
            }
        } catch (\Exception $e) {
            log_message('error', 'Admission_public::payment_webhook admissionPayments lookup failed: ' . $e->getMessage());
        }
        if (!is_array($payment)) {
            // Order not ours (e.g. a fees order on a shared Razorpay account).
            // Record dedup + 200 so Razorpay stops retrying a payment we don't own.
            $this->fs->setEntity('admissionPaymentIdempotency', $dedupId, [
                'schoolId'     => $school_id,
                'order_id'     => $gwOrderId,
                'processed_at' => date('c'),
                'result'       => 'unknown_order',
            ]);
            http_response_code(200);
            echo json_encode(['status' => 'success', 'message' => 'No matching admission payment for this order.']);
            return;
        }

        // ── C3 + M1: canonical captured-amount verification (SHARED w/ callback) ──
        // The webhook body is HMAC-authenticated, but that only proves Razorpay
        // sent it — NOT that the payment was captured for the right amount. Load
        // the adapter and run the SAME helper + verdict the callback runs, so a
        // signed payment.failed / authorized / amount-mismatch event cannot book.
        try {
            $gwForCap = $this->_load_payment_gateway($school_id, $schoolDoc);
        } catch (\Exception $e) {
            // PARITY with the callback (which cannot verify a payment without the
            // gateway loaded — it errors): do NOT book on a gateway-load failure.
            // 500 ⇒ Razorpay retries when the config is readable again. This keeps
            // "gateway unavailable" a non-booking outcome on BOTH entry points.
            log_message('error', 'Admission_public::payment_webhook gateway load failed (capture check): ' . $e->getMessage());
            http_response_code(500);
            echo json_encode(['status' => 'error', 'message' => 'Gateway temporarily unavailable; will retry.']);
            return;
        }
        $cap = $this->_assert_captured_amount($gwForCap, $gwPaymentId, (string) ($payment['order_id'] ?? $gwOrderId), (float) ($payment['amount'] ?? 0));
        if (empty($cap['ok'])) {
            $this->fs->updateEntity('admissionPayments', (string) ($payment['payment_id'] ?? ''), [
                'status'         => $cap['reason'] === 'amount_mismatch' ? 'amount_mismatch' : 'capture_failed',
                'gateway_amount' => $cap['gateway_amount'],
                'failure_reason' => 'capture verification failed (webhook): ' . $cap['reason'],
                'failed_at'      => date('Y-m-d H:i:s'),
            ]);
            // Record dedup so Razorpay stops retrying a genuinely non-captured event.
            $this->fs->setEntity('admissionPaymentIdempotency', $dedupId, [
                'schoolId'     => $school_id,
                'order_id'     => $gwOrderId,
                'payment_id'   => $gwPaymentId,
                'processed_at' => date('c'),
                'result'       => 'capture_rejected:' . $cap['reason'],
            ]);
            log_message('error', "ADM_CAPTURE_REJECTED source=webhook reason={$cap['reason']} order={$gwOrderId} payment={$gwPaymentId}");
            http_response_code(200);
            echo json_encode(['status' => 'success', 'message' => 'Event acknowledged; payment not captured/verified — not reconciled.']);
            return;
        }

        // ── Reconcile via the SAME shared path as the client callback ──
        $result = $this->_apply_admission_payment_paid(
            $school_id, $schoolDoc, $payment, $gwPaymentId,
            ($webhookSig !== '' ? $webhookSig : 'webhook'), 'webhook'
        );

        // ── Mark dedup processed ──
        $this->fs->setEntity('admissionPaymentIdempotency', $dedupId, [
            'schoolId'     => $school_id,
            'order_id'     => $gwOrderId,
            'payment_id'   => $gwPaymentId,
            'processed_at' => date('c'),
            'result'       => ($result['already_paid'] ?? false) ? 'already_paid' : 'success',
        ]);

        http_response_code(200);
        echo json_encode(['status' => 'success', 'message' => 'Webhook processed.']);
    }

    /**
     * IPv4 CIDR membership test (webhook IP whitelist). Non-CIDR input is an
     * exact-match. IPv6 / malformed input returns false (never whitelists).
     */
    private function _ip_in_cidr(string $ip, string $cidr): bool
    {
        if (strpos($cidr, '/') === false) return $ip === $cidr;
        [$subnet, $bits] = array_pad(explode('/', $cidr, 2), 2, '32');
        $ipLong  = ip2long($ip);
        $subLong = ip2long($subnet);
        if ($ipLong === false || $subLong === false) return false; // IPv4 only
        $mask = -1 << (32 - (int) $bits);
        return ($ipLong & $mask) === ($subLong & $mask);
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
        // SC-Step11/G3 fail-closed: gateway lookup requires the canonical
        // session. Absent → throw; both callers (initiate_payment,
        // payment_callback) catch and surface "gateway not configured".
        if ($session === '') {
            throw new \RuntimeException('SC-G3: currentSession absent — admission payment gateway not resolvable (fail-closed)');
        }

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
                    if ($rowSession === '' || $rowSession === $session) {
                        $gwConfig = $r;
                        break;
                    }
                }
            }
        } catch (\Exception $e) {
            log_message('error', 'Admission_public::_load_payment_gateway feeSettings read failed: ' . $e->getMessage());
        }

        // ── PA-DUP-1 (2026-08-24) · ONE PROVIDER-SELECTION AUTHORITY ────────
        // This used to reimplement the active-flag check and the provider
        // decision inline, independently of Fees' Payment_gateway_selector —
        // and it had ALREADY drifted once: Admission honoured `active: false`
        // while Fees ignored the field entirely until GW-SEL-1. Two
        // implementations of one money-safety rule is exactly the shape that
        // drifts, so both callers now consume the same decision.
        //
        // What stays admission-specific, deliberately NOT moved into the
        // selector: the two-stage config lookup above (doc-id guess, then a
        // field query — tenants store the gateway doc under different id
        // shapes) and the {adapter, name, public_key} return shape below.
        // Those are admission's own concerns; provider SELECTION is not.
        $this->load->library('Payment_gateway_selector');
        $selection = Payment_gateway_selector::select(
            $gwConfig,
            Payment_gateway_selector::effective_environment()
        );

        // ── ANY ENABLED PROVIDER, NOT JUST RAZORPAY (2026-08-24) ───────────
        // This branch was `if ($selection === ::RAZORPAY)` and returned a
        // hardcoded `'name' => 'razorpay'`. Two defects in one block:
        //
        //  1. Admission could serve ONLY Razorpay. Fees already resolves any
        //     enabled provider through the registry, so a school that switched
        //     to a second enabled provider would keep collecting fees fine and
        //     silently lose admission payments — a split-brain between two
        //     surfaces reading the SAME configuration.
        //  2. The returned `name` was a LITERAL, not the adapter's own
        //     get_name(). That is the PA-CORE-1 fabricated-provenance defect:
        //     the value flows into money records and GW-ORD-1 comparisons, so
        //     it must be observed from the adapter, never asserted here.
        //
        // The mock keeps its own branch below — it is deliberately NOT
        // credential-driven and its environment gate is the whole point.
        if ($selection !== Payment_gateway_selector::UNAVAILABLE
            && $selection !== Payment_gateway_selector::MOCK) {

            $adapterClass = Payment_gateway_selector::adapter_class($selection);
            if ($adapterClass === null) {
                // The selector said yes and the registry says no adapter. That
                // is a contradiction, not a configuration problem — refuse
                // rather than guess which half is right.
                throw new \RuntimeException(
                    'Provider "' . $selection . '" was selected but resolves to no adapter.');
            }

            $apiKey    = (string) ($gwConfig['api_key']    ?? '');
            $apiSecret = (string) ($gwConfig['api_secret'] ?? '');
            if ($apiKey === '' || $apiSecret === '') {
                throw new \RuntimeException(
                    'Payment credentials missing in the gateway configuration.');
            }

            // Explicit object name rather than relying on CI's
            // strtolower($class) derivation: the alias is deterministic, it
            // cannot collide with the Fees path's own instance of the same
            // adapter, and it means this code does not silently break if the
            // loader's naming rule ever changes.
            $this->load->library($adapterClass, [
                'api_key'    => $apiKey,
                'api_secret' => $apiSecret,
            ], 'admission_gw_adapter');
            $adapter = $this->admission_gw_adapter;

            return [
                'adapter' => $adapter,
                // OBSERVED from the adapter, never asserted. Falls back to the
                // selector's own verdict — still a real resolved provider id,
                // never a fabricated default.
                'name'    => method_exists($adapter, 'get_name')
                    ? $adapter->get_name()
                    : $selection,
                // The PUBLIC key only. Every currently enabled provider uses
                // api_key as its client-safe identifier; api_secret is never
                // returned here and never reaches a client.
                'public_key' => $apiKey,
            ];
        }

        // PRODUCTION FAIL-CLOSED: the mock gateway fakes a successful payment
        // (simulate_payment) and would mark an application 'paid' with no money
        // collected. It must NEVER run on a live tenant. If Razorpay is not
        // configured in production, refuse rather than silently mock-succeed.
        // Both callers (initiate_payment / payment_callback) catch this and
        // surface "Payment gateway is not configured — contact the school
        // office." Mirrors Fees::_reject_in_production's ENVIRONMENT guard.
        //
        // PA-DUP-1 (2026-08-24): the environment gate this comment used to
        // describe (GW-1b: inverted from `ENVIRONMENT === 'production'` to
        // `!in_array(ENVIRONMENT, ['development','testing'])`, so an unset or
        // mistyped value denies) now lives ONCE, in
        // Payment_gateway_selector::SIMULATOR_ENVIRONMENTS — the exact same
        // list, applied identically to Fees. $selection can only be MOCK when
        // that gate already passed; this branch just translates the verdict
        // into admission's own {adapter, name, public_key} return shape. This
        // path remains worse-exposed than the fees equivalent (GW-1): it lives
        // in Admission_public, reachable from the PUBLIC admission form with
        // no authentication at all — one more reason the two must not drift.
        if ($selection === Payment_gateway_selector::MOCK) {
            $this->load->library('Payment_gateway_mock');
            return [
                'adapter'    => $this->payment_gateway_mock,
                'name'       => 'mock',
                'public_key' => '',
            ];
        }

        // UNAVAILABLE — malformed config, inactive gateway, unknown provider,
        // or a real provider outside a simulator environment. Every one of
        // those paths refuses rather than silently mock-succeeding.
        log_message('error', "Admission_public::_load_payment_gateway refused: selector returned UNAVAILABLE for school={$school_id} (Razorpay not configured or inactive)");
        throw new \RuntimeException('Payment gateway (Razorpay) is not configured for this school; mock gateway is disabled outside development (fail-closed).');
    }

    // ─── Audit logging for public context (no session) ─────────────────

    /**
     * Write audit entry from public controller (no session/admin context).
     * Uses 'Public' as the user identifier. Non-blocking.
     */
    private function _public_audit(string $schoolId, string $module, string $action, string $entityId, string $description, string $appId = ''): void
    {
        try {
            $logId = 'AL_' . date('Ymd_His') . '_' . substr(uniqid('', true), -6);
            // Ensure firestore_service is initialized for callers that hit
            // payment endpoints first (those still use $this->firebase and
            // may not have called fs->init yet).
            if (method_exists($this->fs, 'isReady') && !$this->fs->isReady()) {
                $this->fs->init($schoolId);
            }
            $nowMs = (int) round(microtime(true) * 1000);
            $entry = [
                'userId'      => 'public',
                'userName'    => 'Public Form',
                'userRole'    => 'Public',
                'module'      => $module,
                'action'      => $action,
                'entityId'    => $entityId,
                'description' => $description,
                'ipAddress'   => $this->input->ip_address(),
                'timestamp'   => gmdate('Y-m-d\TH:i:s\Z', (int) ($nowMs / 1000)),
                'timestampMs' => $nowMs,
            ];
            // M7-A — additive application correlation key. Payment/receipt events
            // keep their native entityId (paymentId) for accounting consumers;
            // `appId` links them to the originating application without changing
            // any existing identifier.
            if ($appId !== '') $entry['appId'] = $appId;
            $this->fs->setEntity('auditLogs', $logId, $entry);
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
        // Fix D — delegate to the SINGLE canonical resolver shared with the offline
        // admin-collect path (Sis::collect_admission_fee), so both charge the exact
        // same class-specific admission fee. Behaviour is byte-identical to the
        // previous inline implementation (class-specific feeStructures → global
        // schools.admissionFee). $school_id retained for signature/BC (logging).
        $this->load->helper('admission_crm');
        return admission_resolve_fee($this->fs, $schoolDoc, $session, $cls);
    }

    /**
     * Resolve the school's active session — Firestore canonical authority.
     *
     * SC-Step11/G3 (2026-06-06): `schools/{id}.currentSession` is the SOLE
     * session authority. This resolver no longer consults legacy fields
     * (`activeSession`, `active_session`, `session`), no longer derives a
     * session from the `sections` collection, and no longer writes
     * `currentSession` back. The removed side-channel write let the public
     * admission form mutate the canonical authority and re-seed a value the
     * SC-Step10 read fail-closes (G1 login, G2 api_punch) depend on being
     * absent. `currentSession` is written only by School_config (canonical)
     * and the Superadmin onboarding seed.
     *
     * Fail-closed: returns '' when `currentSession` is absent; every caller
     * treats '' as "admissions not currently open / session not configured"
     * rather than inventing or persisting a session.
     *
     * @param string $school_id  Retained for signature stability; unused now
     *                           that the side-channel write has been removed.
     */
    private function _resolve_active_session(string $school_id, array $schoolDoc): string
    {
        return (string) ($schoolDoc['currentSession'] ?? '');
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
        // 32 hex chars (128-bit) — un-enumerable receipt/payment token. Also
        // gates initiate_payment (ownership proof), so width matters. (#7)
        return substr(hash_hmac('sha256', "{$schoolId}|{$appId}", $secret), 0, 32);
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

    /**
     * Token for the FEE receipt (ADM…). Distinct namespace ('fee') from the
     * application-acknowledgement token so the two URLs are not interchangeable.
     */
    private function _fee_receipt_token(string $schoolId, string $receiptNo): string
    {
        $secret = (string) config_item('encryption_key');
        if ($secret === '') $secret = 'grader-admission-receipt-fallback';
        return substr(hash_hmac('sha256', "{$schoolId}|fee|{$receiptNo}", $secret), 0, 16);
    }

    /** Absolute, token-secured public URL for a paid admission FEE receipt. */
    private function _fee_receipt_url(string $schoolId, string $receiptNo): string
    {
        return base_url("admission/fee_receipt/" . rawurlencode($schoolId)
            . "/" . rawurlencode($receiptNo) . "/" . $this->_fee_receipt_token($schoolId, $receiptNo));
    }

    /**
     * PUBLIC: download the paid admission FEE receipt (ADM…) as a PDF. Parents
     * reach this from the payment-success screen and the SMS. Token-secured +
     * tenant-checked so the sequential ADM numbers can't be enumerated. Renders
     * the SAME commercial receipt view the admin panel uses (single source).
     */
    public function fee_receipt($school_id = '', $receipt_no = '', $token = '')
    {
        $school_id  = validate_public_school_id($school_id);
        $receipt_no = strtoupper(trim((string) $receipt_no));
        $token      = trim((string) $token);

        if (!preg_match('/^ADM[0-9]{4,10}$/', $receipt_no)) show_404();
        if (!hash_equals($this->_fee_receipt_token($school_id, $receipt_no), $token)) show_404();

        $this->fs->init($school_id);
        $receipt = $this->fs->get('admissionReceipts', "{$school_id}_{$receipt_no}");
        if (!is_array($receipt) || empty($receipt) || (string) ($receipt['schoolId'] ?? '') !== $school_id) {
            show_404();
        }

        $schoolDoc = $this->fs->get('schools', $school_id) ?? [];
        // Embed the logo as a data: URI (dompdf can't fetch Firebase Storage
        // URLs reliably). CACHED + SSRF-hardened resolver (M4) — Firebase Storage
        // stays the source of truth; this is a disposable CI-file-cache optimization.
        $this->load->helper('public_url');
        $logoRaw = (string) ($schoolDoc['logo'] ?? $schoolDoc['logoUrl'] ?? $schoolDoc['logo_url'] ?? '');
        $logoEmbed = $logoRaw !== '' ? cached_school_logo_data_uri($school_id, $logoRaw) : '';

        $profile = array_merge($schoolDoc, [
            'display_name' => $schoolDoc['name'] ?? $schoolDoc['displayName'] ?? $schoolDoc['display_name'] ?? $school_id,
            'school_name'  => $schoolDoc['name'] ?? '',
            'logo_url'     => $logoEmbed, // '' → view hides it (no broken box)
            'address'      => $schoolDoc['address'] ?? '',
            'phone'        => $schoolDoc['phone']   ?? $schoolDoc['contact'] ?? '',
            'email'        => $schoolDoc['email']   ?? '',
        ]);

        $this->load->library('pdf_generator');
        $html = $this->load->view('admission/fee_receipt', ['receipt' => $receipt, 'school_profile' => $profile], true);
        $this->pdf_generator->inline($html, 'admission_fee_receipt_' . $receipt_no . '.pdf');
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

        // QR encodes the receipt URL itself — scanning re-opens this PDF.
        // Rendered locally as a data: URI (chillerlan) — no external QR API,
        // so it never silently 404s and works offline / behind ad-blockers.
        $receiptUrl = $this->_receipt_url($school_id, $app_id);
        $this->load->helper('qr_token');
        $qrUrl = qr_svg_data_uri($receiptUrl);

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

    /**
     * Sliding-window per-IP rate limiter backed by `crmRateLimits`.
     *
     * One doc per {school}_{ipKey}[_bucket]; the doc carries an `attempts`
     * array of unix timestamps trimmed to the active window on every call so
     * it never grows unbounded. Returns TRUE when the caller is OVER the
     * limit (should be blocked).
     *
     * Fails OPEN on a Firestore infra error (a read blip must not lock out
     * every applicant) but still enforces the quota whenever the read
     * succeeds.
     *
     * @param string $bucket '' = submit budget (legacy doc id preserved so
     *                       existing counters keep counting); a non-empty
     *                       bucket (e.g. 'pay') gets its own doc + budget so
     *                       the payment endpoints never exhaust the submit
     *                       budget and vice-versa.
     */
    private function _rate_limited(string $school_id, string $clientIp, int $max, int $windowSecs, string $bucket = ''): bool
    {
        $ipKey       = preg_replace('/[^a-zA-Z0-9]/', '_', $clientIp);
        $rateDocId   = $bucket === '' ? "{$school_id}_{$ipKey}" : "{$school_id}_{$ipKey}_{$bucket}";
        $windowStart = time() - $windowSecs;

        $attempts = [];
        try {
            $rateDoc = $this->fs->get('crmRateLimits', $rateDocId);
            if (is_array($rateDoc) && is_array($rateDoc['attempts'] ?? null)) {
                foreach ($rateDoc['attempts'] as $ts) {
                    if ((int) $ts >= $windowStart) $attempts[] = (int) $ts;
                }
            }
        } catch (\Exception $e) {
            // Infra error — fail OPEN so a Firestore blip can't block every
            // legitimate applicant. Quota is still enforced on healthy reads.
            log_message('error', 'Admission_public::_rate_limited read failed: ' . $e->getMessage());
            return false;
        }

        if (count($attempts) >= $max) {
            return true;
        }

        // Record this attempt (best-effort; never blocks a legitimate call).
        try {
            $attempts[] = time();
            $this->fs->set('crmRateLimits', $rateDocId, [
                'schoolId'  => $school_id,
                'ip'        => $clientIp,
                'ipKey'     => $ipKey,
                'bucket'    => $bucket,
                'attempts'  => $attempts,
                'updatedAt' => date('c'),
            ], false);
        } catch (\Exception $e) {
            log_message('error', 'Admission_public::_rate_limited write failed: ' . $e->getMessage());
        }
        return false;
    }
}
