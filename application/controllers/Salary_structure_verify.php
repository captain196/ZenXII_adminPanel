<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Salary_structure_verify — hr_payroll Tier 1.1 conformance verification.
 *
 * READ-ONLY CLI tool. Verifies each staff doc carries a canonical
 * `salaryStructure` map per [[hr_payroll_canonical_schema]] (2026-04-15):
 *
 *   Required core:        basic
 *   Standard earnings:    da, hra, ta, medical, other_allowances
 *   Statutory deductions: pf_employee, pf_employer, esi_employee,
 *                         esi_employer, professional_tax, tds, other_deductions
 *   Meta:                 _version (>=1), source, created_at, updated_at
 *
 *   All numeric values must be >= 0. _version must be a positive integer.
 *
 * INVOCATION:
 *   php index.php salary_structure_verify verify
 *   Env required: SCHOOL_ID=<schoolFs>  SESSION_YEAR=<YYYY-YY>
 *
 * Mutates nothing. Idempotent. Safe to run during live traffic.
 */
class Salary_structure_verify extends CI_Controller
{
    private string $schoolFs    = '';
    private string $sessionYear = '';

    /** Earnings fields — at least `basic` is mandatory; others expected but optional. */
    private const EARNINGS_FIELDS = ['basic', 'da', 'hra', 'ta', 'medical', 'other_allowances'];

    /** Statutory deduction fields — all expected when employee is subject to PF/ESI. */
    private const STATUTORY_FIELDS = [
        'pf_employee', 'pf_employer',
        'esi_employee', 'esi_employer',
        'professional_tax', 'tds', 'other_deductions',
    ];

    /** Required meta fields. */
    private const META_FIELDS = ['_version', 'source', 'created_at', 'updated_at'];

    /**
     * Administrative roles exempt from payroll-eligibility — these staff
     * records exist for cross-system identity attribution (audit trails,
     * deactivatedBy fields, etc.) but don't participate in payroll
     * computation, so absence of salaryStructure is expected, not drift.
     * Case-insensitive matching.
     */
    private const ADMIN_EXEMPT_ROLES = [
        'school super admin', 'super school admin', 'super admin',
        'school admin', 'admin', 'system',
    ];

    public function __construct()
    {
        parent::__construct();
        if (!$this->input->is_cli_request()) {
            show_error('Salary_structure_verify is CLI-only.', 403);
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

    /** CLI: php index.php salary_structure_verify verify */
    public function verify(): void
    {
        echo "=== hr_payroll Tier 1.1 salaryStructure Conformance Verification ===\n";
        echo "Scope: schoolId={$this->schoolFs} session={$this->sessionYear}\n";
        echo str_repeat('-', 64) . "\n\n";

        try {
            $rows = $this->firebase->firestoreQuery('staff', [
                ['schoolId', '==', $this->schoolFs],
            ], null, 'ASC', 500);
        } catch (\Throwable $e) {
            echo "ERROR loading staff: " . $e->getMessage() . "\n";
            return;
        }

        $total = count($rows);
        echo "Total staff: {$total}\n\n";
        if ($total === 0) {
            echo "=== T1.1 TRIVIAL PASS — no staff to verify ===\n";
            return;
        }

        $conformantStrict   = 0;   // all earnings + all statutory + all meta present + valid
        $conformantCore     = 0;   // has basic + _version, some statutory missing
        $admin_exempt       = [];  // admin-role staff legitimately without salaryStructure
        $missing_structure  = [];
        $missing_basic      = [];
        $missing_version    = [];
        $bad_version        = [];
        $negative_values    = [];
        $missing_earnings   = [];   // missing one or more standard earnings fields
        $missing_statutory  = [];   // missing one or more statutory fields
        $missing_meta       = [];   // missing one or more meta fields

        // Distribution tallies for visibility
        $sourceTally        = [];
        $versionTally       = [];

        foreach ($rows as $s) {
            $data = is_array($s['data'] ?? null) ? $s['data'] : [];
            $docId = (string)($s['id'] ?? '');
            $uid  = (string)($data['userId'] ?? $data['User ID'] ?? '');
            $name = (string)($data['name'] ?? $data['Name'] ?? '?');
            $status = (string)($data['status'] ?? $data['Status'] ?? '?');
            $role = (string)($data['role'] ?? $data['Position'] ?? $data['primary_role'] ?? $data['roleId'] ?? '');
            $tag = "docId={$docId} uid={$uid} name=\"{$name}\" status={$status} role=\"{$role}\"";

            // Admin-role exemption — these staff records exist for identity
            // attribution but don't participate in payroll computation.
            $isAdminExempt = in_array(strtolower($role), self::ADMIN_EXEMPT_ROLES, true);

            $struct = $data['salaryStructure'] ?? null;
            if (!is_array($struct)) {
                if ($isAdminExempt) {
                    $admin_exempt[] = $tag;
                } else {
                    $missing_structure[] = $tag;
                }
                continue;
            }

            // ── Core: basic must be present + numeric + >= 0 ──────────────
            $basic = $struct['basic'] ?? null;
            if ($basic === null || !is_numeric($basic)) {
                $missing_basic[] = $tag;
            } elseif ((float)$basic < 0) {
                $negative_values[] = "{$tag} — basic=" . $basic;
            }

            // ── Meta: _version must be present + positive int ─────────────
            $version = $struct['_version'] ?? null;
            if ($version === null) {
                $missing_version[] = $tag;
            } elseif (!is_int($version) && !ctype_digit((string)$version)) {
                $bad_version[] = "{$tag} — _version=" . var_export($version, true);
            } elseif ((int)$version < 1) {
                $bad_version[] = "{$tag} — _version={$version}";
            }
            if ($version !== null) {
                $versionTally[(string)$version] = ($versionTally[(string)$version] ?? 0) + 1;
            }

            // source field tally
            $source = (string)($struct['source'] ?? '');
            if ($source !== '') $sourceTally[$source] = ($sourceTally[$source] ?? 0) + 1;

            // ── Field-presence checks ────────────────────────────────────
            $earningsMissing  = [];
            $statutoryMissing = [];
            $metaMissing      = [];

            foreach (self::EARNINGS_FIELDS as $f) {
                if (!array_key_exists($f, $struct)) $earningsMissing[] = $f;
                elseif (is_numeric($struct[$f]) && (float)$struct[$f] < 0) {
                    $negative_values[] = "{$tag} — {$f}=" . $struct[$f];
                }
            }
            foreach (self::STATUTORY_FIELDS as $f) {
                if (!array_key_exists($f, $struct)) $statutoryMissing[] = $f;
                elseif (is_numeric($struct[$f]) && (float)$struct[$f] < 0) {
                    $negative_values[] = "{$tag} — {$f}=" . $struct[$f];
                }
            }
            foreach (self::META_FIELDS as $f) {
                if (!array_key_exists($f, $struct) || $struct[$f] === '' || $struct[$f] === null) {
                    $metaMissing[] = $f;
                }
            }

            if (!empty($earningsMissing)) {
                $missing_earnings[] = "{$tag} — missing: " . implode(',', $earningsMissing);
            }
            if (!empty($statutoryMissing)) {
                $missing_statutory[] = "{$tag} — missing: " . implode(',', $statutoryMissing);
            }
            if (!empty($metaMissing)) {
                $missing_meta[] = "{$tag} — missing: " . implode(',', $metaMissing);
            }

            // Classify conformance level
            $hasBasic = ($basic !== null && is_numeric($basic) && (float)$basic >= 0);
            $hasVersion = ($version !== null && (is_int($version) || ctype_digit((string)$version)) && (int)$version >= 1);
            if ($hasBasic && $hasVersion
                && empty($earningsMissing) && empty($statutoryMissing) && empty($metaMissing)) {
                $conformantStrict++;
            } elseif ($hasBasic && $hasVersion) {
                $conformantCore++;
            }
        }

        // ── Distribution report ──────────────────────────────────────────
        echo "─── Distribution ───\n";
        echo "_version distribution:\n";
        ksort($versionTally);
        foreach ($versionTally as $v => $c) echo "  v{$v} x {$c}\n";
        echo "\nsource distribution:\n";
        ksort($sourceTally);
        foreach ($sourceTally as $v => $c) echo "  \"{$v}\" x {$c}\n";

        // ── Conformance report ───────────────────────────────────────────
        echo "\n─── Conformance ───\n";
        printf("Strict conformant (all 17 fields valid): %d\n", $conformantStrict);
        printf("Core conformant (basic + _version present): %d\n", $conformantCore);

        echo "\nAdmin-role exempt (legitimately no salaryStructure): " . count($admin_exempt) . "\n";
        foreach ($admin_exempt as $row) echo "  - {$row}\n";

        echo "\nMissing salaryStructure map entirely (payroll-eligible): " . count($missing_structure) . "\n";
        foreach ($missing_structure as $row) echo "  - {$row}\n";

        echo "\nMissing 'basic' field: " . count($missing_basic) . "\n";
        foreach ($missing_basic as $row) echo "  - {$row}\n";

        echo "\nMissing '_version' field: " . count($missing_version) . "\n";
        foreach ($missing_version as $row) echo "  - {$row}\n";

        echo "\nMalformed '_version' field: " . count($bad_version) . "\n";
        foreach ($bad_version as $row) echo "  - {$row}\n";

        echo "\nNegative numeric values (any field): " . count($negative_values) . "\n";
        foreach ($negative_values as $row) echo "  - {$row}\n";

        echo "\nMissing earnings fields (da/hra/ta/medical/other_allowances): " . count($missing_earnings) . "\n";
        foreach ($missing_earnings as $row) echo "  - {$row}\n";

        echo "\nMissing statutory fields (pf/esi/pt/tds/other_deductions): " . count($missing_statutory) . "\n";
        foreach ($missing_statutory as $row) echo "  - {$row}\n";

        echo "\nMissing meta fields (_version/source/created_at/updated_at): " . count($missing_meta) . "\n";
        foreach ($missing_meta as $row) echo "  - {$row}\n";

        // ── Verdict ──────────────────────────────────────────────────────
        $criticalDrift = count($missing_structure) + count($missing_basic) + count($missing_version) + count($bad_version) + count($negative_values);
        $minorDrift    = count($missing_earnings) + count($missing_statutory) + count($missing_meta);

        $adminExemptCount = count($admin_exempt);
        $payrollEligible = $total - $adminExemptCount;

        echo "\n─── Verdict ───\n";
        echo "Total: {$total}  (payroll-eligible: {$payrollEligible}, admin-exempt: {$adminExemptCount})\n";
        if ($criticalDrift > 0) {
            echo "🛑 FREEZE_REQUIRED — {$criticalDrift} critical drift indicators (missing structure / basic / _version / negative values) among payroll-eligible staff. hr_payroll depends on these for every payroll computation.\n";
        } elseif ($minorDrift === 0 && $conformantStrict === $payrollEligible) {
            echo "✅ T1.1 NORMAL — all {$payrollEligible} payroll-eligible staff have strict canonical salaryStructure (all 17 fields valid). {$adminExemptCount} admin-role staff legitimately exempt.\n";
        } elseif ($conformantStrict + $conformantCore === $payrollEligible) {
            echo "⚠ WATCH — all {$payrollEligible} payroll-eligible have core conformant (basic + _version) but {$minorDrift} drift indicators on secondary fields. Classify each above per soak contract.\n";
        } else {
            echo "⚠ INVESTIGATE — partial conformance: strict={$conformantStrict} core={$conformantCore} of {$payrollEligible}. Drill in.\n";
        }

        echo "\n=== End verification ===\n";
    }
}
