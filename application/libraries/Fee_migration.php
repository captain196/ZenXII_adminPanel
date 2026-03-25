<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Fee_migration — Migrate legacy Month Fee flags to demand-based system.
 *
 * Phase 1: Reads Users/Parents/{key}/{STU}/Month Fee → creates Fees/Demands
 * Phase 2: Marks each student as migrated
 *
 * Safe to run multiple times (idempotent via migrated flag).
 */
class Fee_migration
{
    private $firebase;
    private $schoolName;
    private $sessionYear;
    private $parentDbKey;
    private $sessionRoot;

    /** @var Fee_audit|null */
    private $audit;

    public function init($firebase, string $schoolName, string $sessionYear, string $parentDbKey, $audit = null): self
    {
        $this->firebase    = $firebase;
        $this->schoolName  = $schoolName;
        $this->sessionYear = $sessionYear;
        $this->parentDbKey = $parentDbKey;
        $this->sessionRoot = "Schools/{$schoolName}/{$sessionYear}";
        $this->audit       = $audit;
        return $this;
    }

    /**
     * Migrate all students' legacy Month Fee data to demands.
     *
     * @return array Summary: {migrated, skipped, errors, details[]}
     */
    public function migrateAll(): array
    {
        $result = ['migrated' => 0, 'skipped' => 0, 'errors' => 0, 'details' => []];

        // TC-CF007: Lock fee system during migration
        $lockPath = "Schools/{$this->schoolName}/{$this->sessionYear}/Fees/System_Lock";
        $this->firebase->set($lockPath, [
            'locked' => true,
            'locked_at' => date('c'),
            'locked_by' => 'migration',
            'reason' => 'Fee data migration in progress'
        ]);

        try {

        // 1. Get fee structure to know amounts
        $structure = $this->firebase->get("{$this->sessionRoot}/Accounts/Fees/Fees Structure");
        if (!is_array($structure)) {
            $result['details'][] = 'No fee structure found — cannot migrate.';
            $this->firebase->delete($lockPath);
            return $result;
        }

        // 2. Get all class/sections
        $sessionKeys = $this->firebase->shallow_get($this->sessionRoot);
        if (!is_array($sessionKeys)) {
            $this->firebase->delete($lockPath);
            return $result;
        }

        foreach ($sessionKeys as $classKey) {
            $classKey = (string)$classKey;
            if (strpos($classKey, 'Class ') !== 0) continue;

            $sectionKeys = $this->firebase->shallow_get("{$this->sessionRoot}/{$classKey}");
            if (!is_array($sectionKeys)) continue;

            foreach ($sectionKeys as $secKey) {
                $secKey = (string)$secKey;
                if (strpos($secKey, 'Section ') !== 0) continue;

                $students = $this->firebase->shallow_get("{$this->sessionRoot}/{$classKey}/{$secKey}/Students");
                if (!is_array($students)) continue;

                // Get class fees for this section
                $classFees = $this->firebase->get("{$this->sessionRoot}/Accounts/Fees/Classes Fees/{$classKey}/{$secKey}");
                $classFees = is_array($classFees) ? $classFees : [];

                foreach ($students as $studentId) {
                    $studentId = (string)$studentId;
                    $r = $this->_migrateStudent($studentId, $classKey, $secKey, $classFees, $structure);
                    $result[$r['status']]++;
                    if ($r['detail']) $result['details'][] = $r['detail'];
                }
            }
        }

        if ($this->audit) {
            $this->audit->log('migration_completed', [
                'migrated' => $result['migrated'],
                'skipped'  => $result['skipped'],
                'errors'   => $result['errors'],
            ]);
        }

        // Unlock fee system
        $this->firebase->delete($lockPath);

        return $result;

        } catch (\Exception $e) {
            // Unlock fee system on failure
            $this->firebase->delete($lockPath);
            log_message('error', "Fee_migration::migrateAll failed: " . $e->getMessage());
            $result['details'][] = 'Migration error: ' . $e->getMessage();
            $result['errors']++;
            return $result;
        }
    }

    /**
     * Migrate a single student's Month Fee data to demands.
     */
    private function _migrateStudent(
        string $studentId, string $classKey, string $secKey,
        array $classFees, array $structure
    ): array {
        $studentBase = "{$this->sessionRoot}/{$classKey}/{$secKey}/Students/{$studentId}";
        $demandsPath = "{$this->sessionRoot}/Fees/Demands/{$studentId}";

        // Check if already migrated
        $migFlag = $this->firebase->get("{$demandsPath}/_migrated");
        if ($migFlag === true || $migFlag === 'true') {
            return ['status' => 'skipped', 'detail' => null];
        }

        // Check if demands already exist (from live system)
        $existing = $this->firebase->get($demandsPath);
        if (is_array($existing) && count($existing) > 1) {
            // Mark migrated to skip next time
            $this->firebase->set("{$demandsPath}/_migrated", true);
            return ['status' => 'skipped', 'detail' => null];
        }

        // Read legacy Month Fee flags
        $monthFee = $this->firebase->get("{$studentBase}/Month Fee");
        if (!is_array($monthFee) || empty($monthFee)) {
            return ['status' => 'skipped', 'detail' => null];
        }

        // Read payment history
        $feesRecord = $this->firebase->get("{$studentBase}/Fees Record");
        $feesRecord = is_array($feesRecord) ? $feesRecord : [];

        try {
            $monthlyHeads = $structure['Monthly'] ?? [];
            $yearlyHeads  = $structure['Yearly'] ?? [];
            $demandCount  = 0;
            $academicMonths = [
                'April','May','June','July','August','September',
                'October','November','December','January','February','March'
            ];

            // Create demands for each month
            foreach ($academicMonths as $monthName) {
                $paid = isset($monthFee[$monthName]) && (int)$monthFee[$monthName] === 1;

                if (!is_array($monthlyHeads)) continue;
                foreach ($monthlyHeads as $feeHead => $_) {
                    $amount = 0;
                    if (isset($classFees[$monthName][$feeHead])) {
                        $amount = floatval(str_replace(',', '', $classFees[$monthName][$feeHead]));
                    }
                    if ($amount <= 0) continue;

                    $demandId = "MIG_{$monthName}_{$this->_sanitize($feeHead)}";
                    $yearNum  = in_array($monthName, ['January','February','March'])
                        ? ((int)explode('-', $this->sessionYear)[0] + 1)
                        : (int)explode('-', $this->sessionYear)[0];
                    $periodKey = $yearNum . '-' . str_pad(array_search($monthName, $academicMonths) + 4, 2, '0', STR_PAD_LEFT);
                    if ((int)substr($periodKey, 5) > 12) {
                        $periodKey = ($yearNum) . '-' . str_pad((int)substr($periodKey, 5) - 12, 2, '0', STR_PAD_LEFT);
                    }

                    $demand = [
                        'fee_head'    => $feeHead,
                        'period'      => "{$monthName} {$yearNum}",
                        'period_key'  => $periodKey,
                        'frequency'   => 'monthly',
                        'amount'      => $amount,
                        'net_amount'  => $amount,
                        'fine_amount' => 0,
                        'paid_amount' => $paid ? $amount : 0,
                        'balance'     => $paid ? 0 : $amount,
                        'status'      => $paid ? 'paid' : 'unpaid',
                        'created_at'  => date('c'),
                        'migrated'    => true,
                        'source'      => 'legacy_month_fee',
                    ];
                    $this->firebase->set("{$demandsPath}/{$demandId}", $demand);
                    $demandCount++;
                }
            }

            // Create demands for yearly fees
            $yearlyPaid = isset($monthFee['Yearly Fees']) && (int)$monthFee['Yearly Fees'] === 1;
            if (is_array($yearlyHeads)) {
                foreach ($yearlyHeads as $feeHead => $_) {
                    $amount = 0;
                    if (isset($classFees['Yearly Fees'][$feeHead])) {
                        $amount = floatval(str_replace(',', '', $classFees['Yearly Fees'][$feeHead]));
                    }
                    if ($amount <= 0) continue;

                    $demandId = "MIG_Yearly_{$this->_sanitize($feeHead)}";
                    $demand = [
                        'fee_head'    => $feeHead,
                        'period'      => "Yearly {$this->sessionYear}",
                        'period_key'  => '0000-00',
                        'frequency'   => 'yearly',
                        'amount'      => $amount,
                        'net_amount'  => $amount,
                        'fine_amount' => 0,
                        'paid_amount' => $yearlyPaid ? $amount : 0,
                        'balance'     => $yearlyPaid ? 0 : $amount,
                        'status'      => $yearlyPaid ? 'paid' : 'unpaid',
                        'created_at'  => date('c'),
                        'migrated'    => true,
                        'source'      => 'legacy_month_fee',
                    ];
                    $this->firebase->set("{$demandsPath}/{$demandId}", $demand);
                    $demandCount++;
                }
            }

            // Mark as migrated
            $this->firebase->set("{$demandsPath}/_migrated", true);

            return [
                'status' => 'migrated',
                'detail' => "{$studentId}: {$demandCount} demands created from {$classKey}/{$secKey}"
            ];
        } catch (\Exception $e) {
            log_message('error', "Fee_migration: failed for {$studentId}: " . $e->getMessage());
            return ['status' => 'errors', 'detail' => "{$studentId}: " . $e->getMessage()];
        }
    }

    private function _sanitize(string $s): string
    {
        return preg_replace('/[^A-Za-z0-9_]/', '_', $s);
    }
}
