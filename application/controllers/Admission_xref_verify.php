<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Admission_xref_verify — Admission Tier 1.4 + 1.6 + 1.7 batch verifier.
 *
 * READ-ONLY CLI tool. Covers:
 *
 *   T1.4: cross-reference applications → schools / sections
 *   T1.6: auditLogs integrity
 *   T1.7: phone_norm + email normalization
 *
 * INVOCATION:
 *   php index.php admission_xref_verify verify
 *   Env required: SCHOOL_ID=<schoolFs>  SESSION_YEAR=<YYYY-YY>
 *
 * Mutates nothing. Idempotent. Safe to run during live traffic.
 */
class Admission_xref_verify extends CI_Controller
{
    private string $schoolFs    = '';
    private string $sessionYear = '';

    public function __construct()
    {
        parent::__construct();
        if (!$this->input->is_cli_request()) {
            show_error('Admission_xref_verify is CLI-only.', 403);
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

    /** CLI: php index.php admission_xref_verify verify */
    public function verify(): void
    {
        echo "=== Admission Tier 1.4 + 1.6 + 1.7 batch verification ===\n";
        echo "Scope: schoolId={$this->schoolFs} session={$this->sessionYear}\n";
        echo str_repeat('-', 64) . "\n\n";

        $this->_verify_xref();           // T1.4
        echo "\n";
        $this->_verify_audit_logs();     // T1.6
        echo "\n";
        $this->_verify_normalization();  // T1.7

        echo "\n=== End batch verification ===\n";
    }

    // ── T1.4: applications → schools/sections cross-reference ────────────
    private function _verify_xref(): void
    {
        echo "─── T1.4: applications → schools / sections cross-reference ───\n";

        // School doc presence
        try {
            $school = $this->firebase->firestoreGet('schools', $this->schoolFs);
            echo "  school doc {$this->schoolFs}: " . (is_array($school) ? "present (name=\"" . ($school['name'] ?? '?') . "\")" : "MISSING") . "\n";
        } catch (\Throwable $e) {
            echo "  ERROR loading school doc: " . $e->getMessage() . "\n";
        }

        try {
            $apps = $this->firebase->firestoreQuery('crmApplications', [
                ['schoolId', '==', $this->schoolFs],
            ], null, 'ASC', 500);
            $sections = $this->firebase->firestoreQuery('sections', [
                ['schoolId', '==', $this->schoolFs],
            ], null, 'ASC', 500);
        } catch (\Throwable $e) {
            echo "  ERROR: " . $e->getMessage() . "\n";
            return;
        }
        echo "  applications: " . count($apps) . "  sections: " . count($sections) . "\n";

        // Build set of canonical class values from sections
        $sectionClassSet = [];
        foreach ($sections as $s) {
            $d = is_array($s['data'] ?? null) ? $s['data'] : [];
            $cn = (string)($d['className'] ?? '');
            if ($cn !== '') $sectionClassSet[$cn] = true;
        }
        echo "  distinct className values in sections: " . count($sectionClassSet) . "\n";

        // For each application, check if class resolves
        $unresolvedClass = [];
        $appClassTally   = [];
        foreach ($apps as $a) {
            $d = is_array($a['data'] ?? null) ? $a['data'] : [];
            $docId = (string)($a['id'] ?? '');
            $cls = (string)($d['class'] ?? '');
            if ($cls !== '') $appClassTally[$cls] = ($appClassTally[$cls] ?? 0) + 1;

            // Try direct match + normalized variants
            $resolved = false;
            if (isset($sectionClassSet[$cls])) $resolved = true;
            // Try "Class {x}" form
            $prefixedVariants = ["Class {$cls}"];
            foreach ($prefixedVariants as $pv) {
                if (isset($sectionClassSet[$pv])) { $resolved = true; break; }
            }
            if (!$resolved) {
                $unresolvedClass[] = "{$docId}: class=\"{$cls}\" — no matching section (and no \"Class {$cls}\" canonical variant)";
            }
        }
        echo "  application.class distribution:\n";
        foreach ($appClassTally as $v => $c) echo "    \"{$v}\" x {$c}\n";
        echo "  applications with class not resolving to any section: " . count($unresolvedClass) . "\n";
        foreach ($unresolvedClass as $row) echo "    - {$row}\n";

        if (count($unresolvedClass) === 0) {
            echo "  ✅ T1.4 NORMAL — all applications resolve to existing sections\n";
        } else {
            echo "  ⚠ INVESTIGATE — application class doesn't resolve\n";
        }
    }

    // ── T1.6: auditLogs integrity ─────────────────────────────────────────
    private function _verify_audit_logs(): void
    {
        echo "─── T1.6: auditLogs integrity ───\n";
        try {
            $rows = $this->firebase->firestoreQuery('auditLogs', [
                ['schoolId', '==', $this->schoolFs],
            ], null, 'ASC', 500);
        } catch (\Throwable $e) {
            echo "  collection unavailable: " . $e->getMessage() . "\n";
            return;
        }
        $total = count($rows);
        echo "  total auditLogs: {$total}\n";
        if ($total === 0) {
            echo "  ✅ T1.6 TRIVIAL PASS — no audit logs in scope\n";
            return;
        }

        $sample = is_array($rows[0]['data'] ?? null) ? $rows[0]['data'] : [];
        echo "  first-doc fields: " . implode(', ', array_keys($sample)) . "\n";

        $actionTally = [];
        $monthTally  = [];
        $missingActor = [];
        $missingTimestamp = [];

        foreach ($rows as $r) {
            $data = is_array($r['data'] ?? null) ? $r['data'] : [];
            $docId = (string)($r['id'] ?? '');

            $action = (string)($data['action'] ?? $data['event'] ?? $data['type'] ?? '');
            if ($action !== '') $actionTally[$action] = ($actionTally[$action] ?? 0) + 1;

            $ts = (string)($data['timestamp'] ?? $data['created_at'] ?? $data['at'] ?? '');
            if ($ts !== '' && preg_match('/^(\d{4}-\d{2})/', $ts, $m)) {
                $monthTally[$m[1]] = ($monthTally[$m[1]] ?? 0) + 1;
            }
            if ($ts === '') $missingTimestamp[] = $docId;

            $actor = (string)($data['actor'] ?? $data['by'] ?? $data['user'] ?? $data['admin_id'] ?? '');
            if ($actor === '') $missingActor[] = $docId;
        }
        echo "  action distribution: " . json_encode($actionTally) . "\n";
        echo "  month distribution: " . json_encode($monthTally) . "\n";
        echo "  missing actor field: " . count($missingActor) . "\n";
        foreach (array_slice($missingActor, 0, 5) as $row) echo "    - {$row}\n";
        if (count($missingActor) > 5) echo "    ... (" . (count($missingActor) - 5) . " more)\n";
        echo "  missing timestamp field: " . count($missingTimestamp) . "\n";
        foreach (array_slice($missingTimestamp, 0, 5) as $row) echo "    - {$row}\n";

        if (count($missingActor) + count($missingTimestamp) === 0) {
            echo "  ✅ T1.6 NORMAL — audit logs structurally coherent\n";
        } else {
            echo "  ⚠ WATCH — audit field gaps detected\n";
        }
    }

    // ── T1.7: phone_norm + email normalization ───────────────────────────
    private function _verify_normalization(): void
    {
        echo "─── T1.7: phone_norm + email normalization ───\n";
        try {
            $apps = $this->firebase->firestoreQuery('crmApplications', [
                ['schoolId', '==', $this->schoolFs],
            ], null, 'ASC', 500);
        } catch (\Throwable $e) {
            echo "  ERROR: " . $e->getMessage() . "\n";
            return;
        }
        $total = count($apps);
        echo "  total applications: {$total}\n";
        if ($total === 0) {
            echo "  ✅ T1.7 TRIVIAL PASS\n";
            return;
        }

        $phoneNormMissing = 0;
        $phoneNormDrift   = [];
        $emailMalformed   = [];
        $duplicatePhones  = [];

        $byPhoneNorm = [];   // phoneNorm => count

        foreach ($apps as $a) {
            $data = is_array($a['data'] ?? null) ? $a['data'] : [];
            $docId = (string)($a['id'] ?? '');

            $phone = (string)($data['phone'] ?? '');
            $phoneNorm = (string)($data['phone_norm'] ?? '');
            if ($phoneNorm === '') {
                $phoneNormMissing++;
            } else {
                // Verify phoneNorm == digits-only of phone
                $expected = preg_replace('/\D/', '', $phone);
                if ($phoneNorm !== $expected) {
                    $phoneNormDrift[] = "{$docId}: phone=\"{$phone}\" phone_norm=\"{$phoneNorm}\" expected=\"{$expected}\"";
                }
                $byPhoneNorm[$phoneNorm] = ($byPhoneNorm[$phoneNorm] ?? 0) + 1;
            }

            $email = (string)($data['email'] ?? '');
            if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $emailMalformed[] = "{$docId}: email=\"{$email}\"";
            }
        }

        // Duplicate detection
        foreach ($byPhoneNorm as $pn => $cnt) {
            if ($cnt > 1) $duplicatePhones[] = "{$pn} x {$cnt}";
        }

        echo "  applications missing phone_norm: {$phoneNormMissing} (legacy carry — pre-Phase-0)\n";
        echo "  phone_norm coherence violations: " . count($phoneNormDrift) . "\n";
        foreach ($phoneNormDrift as $row) echo "    - {$row}\n";
        echo "  malformed email: " . count($emailMalformed) . "\n";
        foreach ($emailMalformed as $row) echo "    - {$row}\n";
        echo "  duplicate phone_norm values (potential dup submissions): " . count($duplicatePhones) . "\n";
        foreach ($duplicatePhones as $row) echo "    - {$row}\n";

        if (count($phoneNormDrift) + count($emailMalformed) === 0) {
            if ($phoneNormMissing > 0) {
                echo "  ⚠ WATCH — {$phoneNormMissing} apps missing phone_norm (documented Phase-0 legacy carry); no coherence drift on docs that have it\n";
            } else {
                echo "  ✅ T1.7 NORMAL — full normalization integrity\n";
            }
        } else {
            echo "  ⚠ INVESTIGATE — normalization drift detected\n";
        }
    }
}
