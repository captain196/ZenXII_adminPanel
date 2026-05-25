<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Crm_applications_verify — Admission Tier 1.1 crmApplications canonical
 * verification.
 *
 * READ-ONLY CLI tool. Verifies canonical schema per Admission_public.php:348-414:
 *
 *   Collection: crmApplications
 *   Doc-id:     {schoolId}_{appId}  (appId format: "APP_<hex6>_<5digits>")
 *
 *   Required canonical fields (snake_case module-native):
 *     id, schoolId, session,
 *     student_name, class, dob, gender,
 *     parent_name, phone, phone_norm, email,
 *     address, city, state, pincode,
 *     status, stage, source, payment_status,
 *     created_at, updated_at, consent_given_at
 *
 *   Status enum: pending | approved | rejected | waitlisted | (admin variants)
 *   Stage enum:  document_collection | (other Sis::_default_stages values)
 *   Source enum: public_form | (admin manual)
 *
 *   phone_norm should be digits-only normalization of phone (per
 *   Admission_public.php:367 — "digits only ... indexable equality query")
 *
 * INVOCATION:
 *   php index.php crm_applications_verify verify
 *   Env required: SCHOOL_ID=<schoolFs>  SESSION_YEAR=<YYYY-YY>
 *
 * Mutates nothing. Idempotent. Safe to run during live traffic.
 */
class Crm_applications_verify extends CI_Controller
{
    private string $schoolFs    = '';
    private string $sessionYear = '';

    private const REQUIRED_CORE = [
        'id', 'schoolId', 'session',
        'student_name', 'class',
        'phone', 'phone_norm',
        'status', 'stage', 'source',
        'created_at', 'updated_at',
    ];

    private const VALID_STATUS = ['pending','approved','rejected','waitlisted','confirmed','enrolled'];
    private const VALID_SOURCE = ['public_form','admin_manual','crm_import','referral','enquiry'];

    public function __construct()
    {
        parent::__construct();
        if (!$this->input->is_cli_request()) {
            show_error('Crm_applications_verify is CLI-only.', 403);
        }
        $this->load->library('firebase');
        $this->load->library('firestore_service');

        $this->schoolFs    = (string) (getenv('SCHOOL_ID')    ?: '');
        $this->sessionYear = (string) (getenv('SESSION_YEAR') ?: '');
        if ($this->schoolFs === '' || $this->sessionYear === '') {
            echo "ERROR: Set SCHOOL_ID and SESSION_YEAR environment variables.\n";
            exit(1);
        }
    }

    /** CLI: php index.php crm_applications_verify verify */
    public function verify(): void
    {
        echo "=== Admission Tier 1.1 crmApplications canonical verification ===\n";
        echo "Scope: schoolId={$this->schoolFs} session={$this->sessionYear}\n";
        echo str_repeat('-', 64) . "\n\n";

        try {
            $rows = $this->firebase->firestoreQuery('crmApplications', [
                ['schoolId', '==', $this->schoolFs],
            ], null, 'ASC', 500);
        } catch (\Throwable $e) {
            echo "ERROR loading crmApplications: " . $e->getMessage() . "\n";
            return;
        }

        $total = count($rows);
        echo "Total crmApplications: {$total}\n\n";
        if ($total === 0) {
            echo "=== T1.1 TRIVIAL PASS — no applications in scope ===\n";
            return;
        }

        // Distribution tallies
        $statusTally     = [];
        $stageTally      = [];
        $sourceTally     = [];
        $paymentStatTally = [];
        $classTally      = [];
        $sessionTally    = [];
        $sourceMonthTally = [];

        // Drift trackers
        $missingFields    = [];
        $docIdMismatch    = [];
        $invalidStatus    = [];
        $invalidSource    = [];
        $phoneNormDrift   = [];   // phone_norm != digits-only of phone
        $missingConsent   = [];
        $createdAfterUpdated = [];
        $appIdFormatBad   = [];
        $crossSchoolLeak  = [];   // schoolId field != query schoolFs

        // Writer signatures for multi-writer detection
        $writerSigs       = [];

        foreach ($rows as $r) {
            $data = is_array($r['data'] ?? null) ? $r['data'] : [];
            $docId = (string)($r['id'] ?? '');

            // Required field presence
            $missing = [];
            foreach (self::REQUIRED_CORE as $f) {
                if (!array_key_exists($f, $data) || $data[$f] === null || $data[$f] === '') {
                    $missing[] = $f;
                }
            }
            if (!empty($missing)) $missingFields[$docId] = $missing;

            // Doc-id pattern: {schoolFs}_{appId}
            $expectedPrefix = "{$this->schoolFs}_";
            if (strpos($docId, $expectedPrefix) !== 0) {
                $docIdMismatch[] = "{$docId} — expected prefix \"{$expectedPrefix}\"";
            }

            // Cross-school leak check
            $sid = (string)($data['schoolId'] ?? '');
            if ($sid !== '' && $sid !== $this->schoolFs) {
                $crossSchoolLeak[] = "{$docId}: schoolId=\"{$sid}\" doesn't match query schoolFs";
            }

            // appId format (APP_<hex>_<digits>)
            $appId = (string)($data['id'] ?? '');
            if ($appId !== '' && !preg_match('/^APP_[A-F0-9]+_\d+$/i', $appId)) {
                $appIdFormatBad[] = "{$docId}: id=\"{$appId}\"";
            }

            // Status enum
            $status = strtolower((string)($data['status'] ?? ''));
            if ($status !== '') {
                $statusTally[$status] = ($statusTally[$status] ?? 0) + 1;
                if (!in_array($status, self::VALID_STATUS, true)) {
                    $invalidStatus[] = "{$docId}: status=\"{$status}\"";
                }
            }

            // Stage tally (open enum)
            $stage = (string)($data['stage'] ?? '');
            if ($stage !== '') $stageTally[$stage] = ($stageTally[$stage] ?? 0) + 1;

            // Source enum
            $source = (string)($data['source'] ?? '');
            if ($source !== '') {
                $sourceTally[$source] = ($sourceTally[$source] ?? 0) + 1;
                if (!in_array($source, self::VALID_SOURCE, true)) {
                    $invalidSource[] = "{$docId}: source=\"{$source}\"";
                }
            }

            // Payment status tally
            $ps = (string)($data['payment_status'] ?? '');
            if ($ps !== '') $paymentStatTally[$ps] = ($paymentStatTally[$ps] ?? 0) + 1;

            // Class tally
            $cls = (string)($data['class'] ?? '');
            if ($cls !== '') $classTally[$cls] = ($classTally[$cls] ?? 0) + 1;

            $sess = (string)($data['session'] ?? '');
            if ($sess !== '') $sessionTally[$sess] = ($sessionTally[$sess] ?? 0) + 1;

            // phone_norm coherence
            $phone = (string)($data['phone'] ?? '');
            $phoneNorm = (string)($data['phone_norm'] ?? '');
            if ($phone !== '' && $phoneNorm !== '') {
                $expectedNorm = preg_replace('/\D/', '', $phone);
                if ($phoneNorm !== $expectedNorm) {
                    $phoneNormDrift[] = "{$docId}: phone=\"{$phone}\" vs phone_norm=\"{$phoneNorm}\" (expected \"{$expectedNorm}\")";
                }
            }

            // DPDP consent timestamp
            $consent = (string)($data['consent_given_at'] ?? '');
            if ($consent === '' && $source === 'public_form') {
                $missingConsent[] = "{$docId}: missing consent_given_at on public_form submission";
            }

            // Timestamp ordering
            $ca = (string)($data['created_at'] ?? '');
            $ua = (string)($data['updated_at'] ?? '');
            if ($ca !== '' && $ua !== '' && $ca > $ua) {
                $createdAfterUpdated[] = "{$docId}: created_at={$ca} > updated_at={$ua}";
            }
            if ($ca !== '') {
                $month = substr($ca, 0, 7);
                $sourceMonthTally[$month] = ($sourceMonthTally[$month] ?? 0) + 1;
            }

            // Writer signature
            $fields = array_keys($data);
            sort($fields);
            $sigShort = implode(',', array_slice($fields, 0, 5));
            $writerSigs[$sigShort] = ($writerSigs[$sigShort] ?? 0) + 1;
        }

        // ── Distribution report ─────────────────────────────────────────
        echo "─── Distribution ───\n";
        echo "Status:\n";
        ksort($statusTally);
        foreach ($statusTally as $v => $c) echo "  \"{$v}\" x {$c}\n";
        echo "\nStage:\n";
        ksort($stageTally);
        foreach ($stageTally as $v => $c) echo "  \"{$v}\" x {$c}\n";
        echo "\nSource:\n";
        ksort($sourceTally);
        foreach ($sourceTally as $v => $c) echo "  \"{$v}\" x {$c}\n";
        echo "\nPayment status:\n";
        ksort($paymentStatTally);
        foreach ($paymentStatTally as $v => $c) echo "  \"{$v}\" x {$c}\n";
        echo "\nClass:\n";
        ksort($classTally);
        foreach ($classTally as $v => $c) echo "  \"{$v}\" x {$c}\n";
        echo "\nSession:\n";
        foreach ($sessionTally as $v => $c) echo "  \"{$v}\" x {$c}\n";
        echo "\ncreated_at month distribution:\n";
        ksort($sourceMonthTally);
        foreach ($sourceMonthTally as $v => $c) echo "  {$v} x {$c}\n";
        echo "\nWriter signatures (first 5 fields, alpha):\n";
        $sigIdx = 0;
        foreach ($writerSigs as $sig => $cnt) {
            echo "  Sig#" . (++$sigIdx) . " (n={$cnt}): " . substr($sig, 0, 90) . "\n";
        }

        // ── Conformance ─────────────────────────────────────────────────
        echo "\n─── Conformance ───\n";
        echo "Docs missing required core fields: " . count($missingFields) . "\n";
        foreach ($missingFields as $id => $miss) echo "  - {$id}: " . implode(',', $miss) . "\n";
        echo "\nCross-school leaks: " . count($crossSchoolLeak) . "\n";
        foreach ($crossSchoolLeak as $row) echo "  - {$row}\n";
        echo "\nDoc-id prefix mismatches: " . count($docIdMismatch) . "\n";
        foreach ($docIdMismatch as $row) echo "  - {$row}\n";
        echo "\nappId format violations: " . count($appIdFormatBad) . "\n";
        foreach ($appIdFormatBad as $row) echo "  - {$row}\n";
        echo "\nInvalid status enum: " . count($invalidStatus) . "\n";
        foreach ($invalidStatus as $row) echo "  - {$row}\n";
        echo "\nInvalid source enum: " . count($invalidSource) . "\n";
        foreach ($invalidSource as $row) echo "  - {$row}\n";
        echo "\nphone_norm coherence violations: " . count($phoneNormDrift) . "\n";
        foreach ($phoneNormDrift as $row) echo "  - {$row}\n";
        echo "\nPublic-form submissions missing consent_given_at: " . count($missingConsent) . "\n";
        foreach ($missingConsent as $row) echo "  - {$row}\n";
        echo "\ncreated_at > updated_at: " . count($createdAfterUpdated) . "\n";
        foreach ($createdAfterUpdated as $row) echo "  - {$row}\n";

        // ── Verdict ─────────────────────────────────────────────────────
        $criticalDrift = count($crossSchoolLeak) + count($invalidStatus) + count($createdAfterUpdated);
        $watchDrift = count($missingFields) + count($docIdMismatch) + count($appIdFormatBad)
                    + count($invalidSource) + count($phoneNormDrift) + count($missingConsent);

        echo "\n─── Verdict ───\n";
        if ($criticalDrift > 0) {
            echo "🛑 FREEZE_REQUIRED candidate — {$criticalDrift} critical drift (cross-school leak / invalid status / timestamp inversion)\n";
        } elseif ($watchDrift > 0) {
            echo "⚠ WATCH — {$watchDrift} drift indicators (likely legacy / multi-writer / normalization gaps). Cross-check before escalation.\n";
        } else {
            echo "✅ T1.1 NORMAL — all {$total} applications canonical\n";
        }

        echo "\n=== End verification ===\n";
    }
}
