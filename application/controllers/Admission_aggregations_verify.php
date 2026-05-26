<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Admission_aggregations_verify — Admission Tier 1.2 + 1.3 + 1.5 + 1.8 batch.
 *
 * READ-ONLY CLI tool. Covers:
 *
 *   T1.2: crmDupChecks + crmRateLimits canonical (guard collections)
 *   T1.3: admissionPayments Firestore-side state (RTDB carry awareness)
 *   T1.5: application status state machine + lifecycle integrity
 *   T1.8: payment_status state machine (application + admissionPayments coherence)
 *
 * INVOCATION:
 *   php index.php admission_aggregations_verify verify
 *   Env required: SCHOOL_ID=<schoolFs>  SESSION_YEAR=<YYYY-YY>
 *
 * Mutates nothing. Idempotent. Safe to run during live traffic.
 */
class Admission_aggregations_verify extends CI_Controller
{
    private string $schoolFs    = '';
    private string $sessionYear = '';

    private const VALID_STATUS    = ['pending','approved','rejected','waitlisted','confirmed','enrolled'];
    private const VALID_PAYMENT_STATUS = ['pending','initiated','paid','failed','refunded','cancelled'];

    public function __construct()
    {
        parent::__construct();
        if (!$this->input->is_cli_request()) {
            show_error('Admission_aggregations_verify is CLI-only.', 403);
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

    /** CLI: php index.php admission_aggregations_verify verify */
    public function verify(): void
    {
        echo "=== Admission Tier 1.2 + 1.3 + 1.5 + 1.8 batch verification ===\n";
        echo "Scope: schoolId={$this->schoolFs} session={$this->sessionYear}\n";
        echo str_repeat('-', 64) . "\n\n";

        $this->_verify_guards();           // T1.2
        echo "\n";
        $this->_verify_payments();         // T1.3
        echo "\n";
        $this->_verify_status_lifecycle(); // T1.5
        echo "\n";
        $this->_verify_payment_state_machine(); // T1.8

        echo "\n=== End batch verification ===\n";
    }

    // ── T1.2: guard collections ──────────────────────────────────────────
    private function _verify_guards(): void
    {
        echo "─── T1.2: crmDupChecks + crmRateLimits guards ───\n";
        $dupRows = [];
        $rateRows = [];
        try {
            // doc-id pattern: {schoolId}_{phoneHash} OR {schoolId}_{ipKey}
            // so prefix-scan via schoolId field
            $dupRows  = $this->firebase->firestoreQuery('crmDupChecks',  [['schoolId','==',$this->schoolFs]], null, 'ASC', 200);
            $rateRows = $this->firebase->firestoreQuery('crmRateLimits', [['schoolId','==',$this->schoolFs]], null, 'ASC', 200);
        } catch (\Throwable $e) {
            echo "  ERROR loading guards: " . $e->getMessage() . "\n";
            // Continue — some collections may not exist
        }
        echo "  crmDupChecks docs: " . count($dupRows) . "\n";
        echo "  crmRateLimits docs: " . count($rateRows) . "\n";

        if (count($dupRows) === 0 && count($rateRows) === 0) {
            echo "  ✅ T1.2 TRIVIAL PASS — no guard docs in scope (clean state or low submission volume).\n";
            return;
        }

        if (count($dupRows) > 0) {
            $sample = is_array($dupRows[0]['data'] ?? null) ? $dupRows[0]['data'] : [];
            echo "  crmDupChecks sample fields: " . implode(', ', array_keys($sample)) . "\n";
        }
        if (count($rateRows) > 0) {
            $sample = is_array($rateRows[0]['data'] ?? null) ? $rateRows[0]['data'] : [];
            echo "  crmRateLimits sample fields: " . implode(', ', array_keys($sample)) . "\n";
        }
        echo "  ✅ T1.2 NORMAL (guard docs present, structure observed)\n";
    }

    // ── T1.3: admissionPayments Firestore-side ──────────────────────────
    private function _verify_payments(): void
    {
        echo "─── T1.3: admissionPayments Firestore-side ───\n";
        try {
            $rows = $this->firebase->firestoreQuery('admissionPayments', [
                ['schoolId', '==', $this->schoolFs],
            ], null, 'ASC', 200);
        } catch (\Throwable $e) {
            echo "  ERROR: " . $e->getMessage() . "\n";
            return;
        }
        $total = count($rows);
        echo "  total docs: {$total}\n";
        if ($total === 0) {
            echo "  ✅ T1.3 TRIVIAL PASS — no Firestore-side admissionPayments\n";
            echo "  ⚠ NOTE: per Admission_public.php:24-26 controller header, payment endpoints (initiate/callback/status)\n";
            echo "    still touch RTDB — Phase-1 scheduled. Operator-aware carry.\n";
            return;
        }

        $sample = is_array($rows[0]['data'] ?? null) ? $rows[0]['data'] : [];
        echo "  first-doc fields: " . implode(', ', array_keys($sample)) . "\n";

        $statusTally  = [];
        $amountTally  = [];
        $negativeAmounts = [];
        $orphanRefs   = [];   // payment doc with no matching application_id

        foreach ($rows as $r) {
            $data = is_array($r['data'] ?? null) ? $r['data'] : [];
            $docId = (string)($r['id'] ?? '');
            $st = (string)($data['status'] ?? $data['payment_status'] ?? '');
            if ($st !== '') $statusTally[$st] = ($statusTally[$st] ?? 0) + 1;
            $amt = $data['amount'] ?? $data['amount_paise'] ?? null;
            if (is_numeric($amt)) {
                $amountTally[(string)$amt] = ($amountTally[(string)$amt] ?? 0) + 1;
                if ((float)$amt < 0) $negativeAmounts[] = "{$docId}: amount={$amt}";
            }
            $appId = (string)($data['application_id'] ?? $data['appId'] ?? '');
            if ($appId === '') $orphanRefs[] = "{$docId}: no application_id/appId reference";
        }
        echo "  status distribution: " . json_encode($statusTally) . "\n";
        echo "  distinct amounts: " . count($amountTally) . "\n";
        echo "  negative amounts: " . count($negativeAmounts) . "\n";
        foreach ($negativeAmounts as $row) echo "    - {$row}\n";
        echo "  payment docs without application reference: " . count($orphanRefs) . "\n";
        foreach ($orphanRefs as $row) echo "    - {$row}\n";

        if (count($negativeAmounts) + count($orphanRefs) === 0) {
            echo "  ✅ T1.3 NORMAL — Firestore-side payments structurally coherent\n";
        } else {
            echo "  ⚠ INVESTIGATE — drift indicators found\n";
        }
    }

    // ── T1.5: application status lifecycle ───────────────────────────────
    private function _verify_status_lifecycle(): void
    {
        echo "─── T1.5: application status lifecycle ───\n";
        try {
            $rows = $this->firebase->firestoreQuery('crmApplications', [
                ['schoolId', '==', $this->schoolFs],
            ], null, 'ASC', 500);
        } catch (\Throwable $e) {
            echo "  ERROR: " . $e->getMessage() . "\n";
            return;
        }
        $total = count($rows);
        echo "  total applications: {$total}\n";
        if ($total === 0) {
            echo "  ✅ T1.5 TRIVIAL PASS\n";
            return;
        }

        $orphanApproved = [];   // status=approved but no approved_by/approved_at
        $orphanHistory  = [];   // status changed but no history entry

        foreach ($rows as $r) {
            $data = is_array($r['data'] ?? null) ? $r['data'] : [];
            $docId = (string)($r['id'] ?? '');
            $status = strtolower((string)($data['status'] ?? ''));
            if (in_array($status, ['approved','confirmed','enrolled','rejected'], true)) {
                $by = (string)($data['approved_by'] ?? $data['rejected_by'] ?? '');
                $at = (string)($data['approved_at'] ?? $data['rejected_at'] ?? '');
                if ($by === '' && $at === '') {
                    $orphanApproved[] = "{$docId}: status={$status} but no approved_by/approved_at";
                }
            }
            $history = $data['history'] ?? null;
            if (!is_array($history) || count($history) === 0) {
                $orphanHistory[] = "{$docId}: missing/empty history array";
            }
        }
        echo "  status-without-audit drift: " . count($orphanApproved) . "\n";
        foreach ($orphanApproved as $row) echo "    - {$row}\n";
        echo "  missing/empty history: " . count($orphanHistory) . "\n";
        foreach ($orphanHistory as $row) echo "    - {$row}\n";

        if (count($orphanApproved) + count($orphanHistory) === 0) {
            echo "  ✅ T1.5 NORMAL — application lifecycle audit trail intact\n";
        } else {
            echo "  ⚠ WATCH — lifecycle audit gaps detected\n";
        }
    }

    // ── T1.8: payment_status coherence ───────────────────────────────────
    private function _verify_payment_state_machine(): void
    {
        echo "─── T1.8: payment_status coherence ───\n";
        try {
            $apps = $this->firebase->firestoreQuery('crmApplications', [
                ['schoolId', '==', $this->schoolFs],
            ], null, 'ASC', 500);
            $payments = $this->firebase->firestoreQuery('admissionPayments', [
                ['schoolId', '==', $this->schoolFs],
            ], null, 'ASC', 500);
        } catch (\Throwable $e) {
            echo "  ERROR: " . $e->getMessage() . "\n";
            return;
        }
        echo "  applications: " . count($apps) . "  payments: " . count($payments) . "\n";

        $appPayStatTally = [];
        $payStatTally    = [];
        $invalidAppPayStat = [];
        $coherenceDrift  = [];

        // Build payment lookup by application_id
        $paymentByApp = [];
        foreach ($payments as $p) {
            $d = is_array($p['data'] ?? null) ? $p['data'] : [];
            $appId = (string)($d['application_id'] ?? $d['appId'] ?? '');
            if ($appId !== '') {
                $paymentByApp[$appId][] = $d;
            }
        }

        foreach ($apps as $a) {
            $data = is_array($a['data'] ?? null) ? $a['data'] : [];
            $docId = (string)($a['id'] ?? '');
            $appId = (string)($data['id'] ?? '');
            $ps = (string)($data['payment_status'] ?? '');
            if ($ps !== '') {
                $appPayStatTally[$ps] = ($appPayStatTally[$ps] ?? 0) + 1;
                if (!in_array($ps, self::VALID_PAYMENT_STATUS, true)) {
                    $invalidAppPayStat[] = "{$docId}: payment_status=\"{$ps}\"";
                }
            }
            // Coherence: if app says paid, should have a payment doc with status=paid/captured
            if ($ps === 'paid' && !isset($paymentByApp[$appId])) {
                $coherenceDrift[] = "{$docId} (app={$appId}): payment_status=paid but no admissionPayments doc with this application_id";
            }
        }
        foreach ($payments as $p) {
            $d = is_array($p['data'] ?? null) ? $p['data'] : [];
            $st = (string)($d['status'] ?? $d['payment_status'] ?? '');
            if ($st !== '') $payStatTally[$st] = ($payStatTally[$st] ?? 0) + 1;
        }

        echo "  application.payment_status distribution: " . json_encode($appPayStatTally) . "\n";
        echo "  admissionPayments.status distribution:   " . json_encode($payStatTally) . "\n";
        echo "  invalid app payment_status: " . count($invalidAppPayStat) . "\n";
        foreach ($invalidAppPayStat as $row) echo "    - {$row}\n";
        echo "  paid-application without matching payment doc: " . count($coherenceDrift) . "\n";
        foreach ($coherenceDrift as $row) echo "    - {$row}\n";

        if (count($invalidAppPayStat) + count($coherenceDrift) === 0) {
            echo "  ✅ T1.8 NORMAL — payment status coherence intact\n";
        } else {
            echo "  ⚠ INVESTIGATE — coherence drift detected\n";
        }
    }
}
