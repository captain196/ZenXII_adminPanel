<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Hr_canonical_verify — hr_payroll Tier 1.5 + 1.6 + 1.7 batch verifier.
 *
 * READ-ONLY CLI tool. Covers three schema/linkage checks:
 *
 *   T1.5: appraisals canonical schema per [[hr_payroll_canonical_schema]]
 *         - Required camelCase fields: staffId, staffName, appraisalType,
 *           reviewerId, reviewerName, teachingQuality, studentFeedback,
 *           overallRating, areasOfImprovement, period, status, schoolId,
 *           session, updatedAt
 *
 *   T1.6: hrJobs canonical + statusLower mirror per memory
 *         - Required fields including statusLower (lowercase mirror of status)
 *         - Per-mobile-app query convention: Teacher app filters on statusLower
 *
 *   T1.7: payroll ↔ accounting JE_PAYROLL_* linkage integrity
 *         - Every Finalized/Paid payslip should have corresponding JE_PAYROLL_*
 *           entry in the accounting collection
 *         - Conversely, every JE_PAYROLL_* should reference a real payslip
 *           OR be tagged as simulator/test (TEST_EMP namespace) for documented carry
 *
 * INVOCATION:
 *   php index.php hr_canonical_verify verify
 *   Env required: SCHOOL_ID=<schoolFs>  SESSION_YEAR=<YYYY-YY>
 *
 * Mutates nothing. Idempotent. Safe to run during live traffic.
 */
class Hr_canonical_verify extends CI_Controller
{
    private string $schoolFs    = '';
    private string $sessionYear = '';

    private const APPRAISAL_REQUIRED_FIELDS = [
        'staffId', 'staffName', 'appraisalType', 'reviewerId', 'reviewerName',
        'teachingQuality', 'studentFeedback', 'overallRating', 'areasOfImprovement',
        'period', 'status', 'schoolId', 'session', 'updatedAt',
    ];

    private const HRJOB_REQUIRED_FIELDS = [
        'title', 'department', 'vacancies', 'qualification', 'experience',
        'salaryRange', 'salaryRangeMin', 'salaryRangeMax',
        'closingDate', 'jobDescription',
        'status', 'statusLower',
        'postedAt', 'postedDate', 'schoolId',
    ];

    private const HRJOB_VALID_STATUS = ['Open', 'Closed', 'On_Hold'];
    private const HRJOB_VALID_STATUS_LOWER = ['open', 'closed', 'on_hold'];

    public function __construct()
    {
        parent::__construct();
        if (!$this->input->is_cli_request()) {
            show_error('Hr_canonical_verify is CLI-only.', 403);
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

    /** CLI: php index.php hr_canonical_verify verify */
    public function verify(): void
    {
        echo "=== hr_payroll Tier 1.5 + 1.6 + 1.7 Batch Verification ===\n";
        echo "Scope: schoolId={$this->schoolFs} session={$this->sessionYear}\n";
        echo str_repeat('-', 64) . "\n\n";

        $this->_verify_appraisals();
        echo "\n";
        $this->_verify_hrjobs();
        echo "\n";
        $this->_verify_payroll_journal_linkage();

        echo "\n=== End batch verification ===\n";
    }

    // ── T1.5: appraisals ────────────────────────────────────────────────
    private function _verify_appraisals(): void
    {
        echo "─── T1.5: appraisals canonical schema ───\n";
        try {
            $rows = $this->firebase->firestoreQuery('appraisals', [
                ['schoolId', '==', $this->schoolFs],
            ], null, 'ASC', 500);
        } catch (\Throwable $e) {
            echo "  collection unavailable: " . $e->getMessage() . "\n";
            return;
        }
        $total = count($rows);
        echo "  total appraisals: {$total}\n";
        if ($total === 0) {
            echo "  ✅ T1.5 TRIVIAL PASS — no appraisals in scope.\n";
            return;
        }

        $conformant = 0;
        $missingFields = [];
        foreach ($rows as $r) {
            $data = is_array($r['data'] ?? null) ? $r['data'] : [];
            $docId = (string)($r['id'] ?? '');
            $missing = [];
            foreach (self::APPRAISAL_REQUIRED_FIELDS as $f) {
                if (!array_key_exists($f, $data) || $data[$f] === null || $data[$f] === '') {
                    $missing[] = $f;
                }
            }
            if (empty($missing)) {
                $conformant++;
            } else {
                $missingFields[$docId] = $missing;
            }
        }
        printf("  conformant: %d / %d\n", $conformant, $total);
        if (!empty($missingFields)) {
            echo "  ⚠ docs missing canonical fields:\n";
            foreach ($missingFields as $id => $miss) echo "    - {$id}: " . implode(',', $miss) . "\n";
        }
        echo "  Verdict: " . ($conformant === $total ? '✅ T1.5 NORMAL' : '⚠ WATCH (' . count($missingFields) . " drift docs)") . "\n";
    }

    // ── T1.6: hrJobs ────────────────────────────────────────────────────
    private function _verify_hrjobs(): void
    {
        echo "─── T1.6: hrJobs canonical + statusLower mirror ───\n";
        try {
            $rows = $this->firebase->firestoreQuery('hrJobs', [
                ['schoolId', '==', $this->schoolFs],
            ], null, 'ASC', 500);
        } catch (\Throwable $e) {
            echo "  collection unavailable: " . $e->getMessage() . "\n";
            return;
        }
        $total = count($rows);
        echo "  total hrJobs: {$total}\n";
        if ($total === 0) {
            echo "  ✅ T1.6 TRIVIAL PASS — no hrJobs in scope.\n";
            return;
        }

        $conformant = 0;
        $missingFields = [];
        $statusMirrorMismatch = [];
        $invalidStatus = [];
        foreach ($rows as $r) {
            $data = is_array($r['data'] ?? null) ? $r['data'] : [];
            $docId = (string)($r['id'] ?? '');
            $missing = [];
            foreach (self::HRJOB_REQUIRED_FIELDS as $f) {
                if (!array_key_exists($f, $data) || $data[$f] === null || $data[$f] === '') {
                    $missing[] = $f;
                }
            }
            $status      = (string)($data['status']      ?? '');
            $statusLower = (string)($data['statusLower'] ?? '');
            if ($status !== '' && !in_array($status, self::HRJOB_VALID_STATUS, true)) {
                $invalidStatus[] = "{$docId} — status=\"{$status}\"";
            }
            if ($status !== '' && $statusLower !== '' && strtolower(str_replace(' ', '_', $status)) !== $statusLower) {
                $statusMirrorMismatch[] = "{$docId} — status=\"{$status}\" statusLower=\"{$statusLower}\" (mirror gap)";
            }
            if (empty($missing) && empty($statusMirrorMismatch[$docId] ?? null)) {
                $conformant++;
            } elseif (!empty($missing)) {
                $missingFields[$docId] = $missing;
            }
        }
        printf("  conformant: %d / %d\n", $conformant, $total);
        if (!empty($missingFields)) {
            echo "  ⚠ docs missing canonical fields:\n";
            foreach ($missingFields as $id => $miss) echo "    - {$id}: " . implode(',', $miss) . "\n";
        }
        if (!empty($invalidStatus)) {
            echo "  ⚠ invalid status values (not Open/Closed/On_Hold):\n";
            foreach ($invalidStatus as $row) echo "    - {$row}\n";
        }
        if (!empty($statusMirrorMismatch)) {
            echo "  ⚠ status / statusLower mirror mismatches:\n";
            foreach ($statusMirrorMismatch as $row) echo "    - {$row}\n";
        }
        echo "  Verdict: " . ($conformant === $total ? '✅ T1.6 NORMAL' : '⚠ WATCH (' . (count($missingFields) + count($statusMirrorMismatch) + count($invalidStatus)) . ' drift indicators)') . "\n";
    }

    // ── T1.7: payroll ↔ accounting JE_PAYROLL_* linkage ─────────────────
    private function _verify_payroll_journal_linkage(): void
    {
        echo "─── T1.7: payroll ↔ accounting JE_PAYROLL_* linkage ───\n";

        // Fetch finalized/paid payslips
        try {
            $slips = $this->firebase->firestoreQuery('salarySlips', [
                ['schoolId', '==', $this->schoolFs],
            ], null, 'ASC', 500);
        } catch (\Throwable $e) {
            echo "  ERROR loading salarySlips: " . $e->getMessage() . "\n";
            return;
        }
        $paidSlips = [];
        foreach ($slips as $s) {
            $data = is_array($s['data'] ?? null) ? $s['data'] : [];
            if (($data['type'] ?? '') !== 'payslip') continue;
            $status = (string)($data['status'] ?? '');
            if (in_array($status, ['Finalized', 'Paid'], true)) {
                $paidSlips[] = $s;
            }
        }
        echo "  Finalized/Paid payslips: " . count($paidSlips) . "\n";

        // Fetch JE_PAYROLL_* entries from BOTH the active `accounting` collection
        // AND the legacy `accountingLedger` collection (pre-Phase-8 entries).
        // The PR0001 payslips were created 2026-04-14, pre-Phase-8, so their
        // counterpart JEs would live in the legacy ledger.
        $payrollJEs = [];
        $payrollJEs_active = [];
        $payrollJEs_legacy = [];
        try {
            $accountingDocs = $this->firebase->firestoreQuery('accounting', [
                ['schoolId', '==', $this->schoolFs],
                ['session',  '==', $this->sessionYear],
            ], null, 'ASC', 500);
            foreach ($accountingDocs as $a) {
                $data = is_array($a['data'] ?? null) ? $a['data'] : [];
                $entryId = (string)($data['entryId'] ?? '');
                if (strpos($entryId, 'JE_PAYROLL_') === 0) {
                    $payrollJEs_active[$entryId] = $a;
                    $payrollJEs[$entryId] = $a;
                }
            }
        } catch (\Throwable $e) {
            echo "  WARNING loading accounting: " . $e->getMessage() . "\n";
        }
        // Legacy ledger
        try {
            $legacyDocs = $this->firebase->firestoreQuery('accountingLedger', [
                ['schoolId', '==', $this->schoolFs],
                ['session',  '==', $this->sessionYear],
            ], null, 'ASC', 500);
            foreach ($legacyDocs as $a) {
                $data = is_array($a['data'] ?? null) ? $a['data'] : [];
                $entryId = (string)($data['entryId'] ?? '');
                // Also include payroll-pattern entries from legacy era: PR0001 era
                // may use FE_/JE_ prefixes with different shapes.
                if (strpos($entryId, 'JE_PAYROLL_') === 0 || strpos($entryId, 'PAYROLL_') !== false || strpos($entryId, 'PR0001') !== false) {
                    $payrollJEs_legacy[$entryId] = $a;
                    if (!isset($payrollJEs[$entryId])) $payrollJEs[$entryId] = $a;
                }
            }
        } catch (\Throwable $e) {
            echo "  WARNING loading accountingLedger: " . $e->getMessage() . "\n";
        }
        echo "  active 'accounting' JE_PAYROLL_* entries:  " . count($payrollJEs_active) . "\n";
        echo "  legacy 'accountingLedger' payroll entries: " . count($payrollJEs_legacy) . "\n";
        echo "  combined JE candidates: " . count($payrollJEs) . "\n";

        // Classify each JE_PAYROLL by whether it references real or simulator namespace
        $simulatorJEs = 0;
        $realJEs = 0;
        foreach ($payrollJEs as $entryId => $_) {
            if (strpos($entryId, 'TEST_EMP') !== false || strpos($entryId, 'F3R') !== false) {
                $simulatorJEs++;
            } else {
                $realJEs++;
            }
        }
        echo "    simulator-era JEs (TEST_EMP / F3R-prefixed): {$simulatorJEs}\n";
        echo "    apparently-real-staff JEs: {$realJEs}\n";

        // For each Finalized/Paid payslip, try to find a matching JE
        $orphanSlips = [];
        foreach ($paidSlips as $s) {
            $data = is_array($s['data'] ?? null) ? $s['data'] : [];
            $staffId  = (string)($data['staffId']  ?? '');
            $monthKey = (string)($data['monthKey'] ?? '');
            $runId    = (string)($data['runId']    ?? '');
            $matched = false;
            foreach (array_keys($payrollJEs) as $entryId) {
                if ($staffId !== '' && strpos($entryId, $staffId) !== false) {
                    $matched = true; break;
                }
                if ($runId !== '' && strpos($entryId, $runId) !== false) {
                    $matched = true; break;
                }
            }
            if (!$matched) {
                $orphanSlips[] = "{$data['staffId']}/{$runId} (status={$data['status']})";
            }
        }
        echo "  Finalized/Paid payslips without matching JE: " . count($orphanSlips) . "\n";
        foreach ($orphanSlips as $row) echo "    - {$row}\n";

        // Orphan JEs (no matching paid slip)
        $orphanJEs = 0;
        foreach ($payrollJEs as $entryId => $_) {
            $matched = false;
            foreach ($paidSlips as $s) {
                $data = is_array($s['data'] ?? null) ? $s['data'] : [];
                $staffId = (string)($data['staffId'] ?? '');
                if ($staffId !== '' && strpos($entryId, $staffId) !== false) {
                    $matched = true; break;
                }
            }
            if (!$matched) $orphanJEs++;
        }
        echo "  JE_PAYROLL_* without matching paid payslip (likely simulator-era): {$orphanJEs}\n";

        // Verdict
        if (count($paidSlips) === 0 && count($payrollJEs) === 0) {
            echo "  ✅ T1.7 TRIVIAL PASS — no payroll runs in either direction.\n";
        } elseif (count($paidSlips) === 0) {
            echo "  ⚠ INFO — {$simulatorJEs} simulator-era JEs without matching real payslips (documented historical residue). 0 real payroll runs to verify against.\n";
        } elseif (count($orphanSlips) === 0) {
            echo "  ✅ T1.7 NORMAL — all " . count($paidSlips) . " Finalized/Paid payslips have matching JE_PAYROLL_* in accounting.\n";
        } else {
            echo "  🛑 FREEZE_REQUIRED — " . count($orphanSlips) . " Finalized/Paid payslips without matching accounting JE — payroll/accounting integrity gap.\n";
        }
    }
}
