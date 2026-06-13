<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Fee_audit — Centralized audit logging + monitoring for the Fees module.
 *
 * Covers Task 3 (Audit Logs) and Task 5 (Monitoring/Alerts).
 *
 * Firebase path: Schools/{school}/{session}/Fees/Audit_Logs/{log_id}
 * Alert path:    Schools/{school}/{session}/Fees/Alerts/{alert_id}
 */
class Fee_audit
{
    private $firebase;
    private $basePath;    // legacy RTDB path string — retained only to derive schoolId/session
    private $schoolId = '';
    private $session  = '';
    private $adminId;
    private $adminName;
    private $schoolName;

    /** Events that trigger alerts (logged + flagged for admin attention) */
    private const ALERT_EVENTS = [
        'refund_failed',
        'payment_inconsistency',
        'duplicate_attempt',
        'amount_mismatch',
        'transaction_incomplete',
        'demand_reversal_failed',
    ];

    public function init($firebase, string $basePath, string $adminId, string $adminName, string $schoolName): self
    {
        $this->firebase   = $firebase;
        $this->basePath   = $basePath;
        // Derive tenant-isolation fields from the canonical path "Schools/{schoolId}/{session}/Fees".
        // Safe fallback: schoolName (== SCH_ id) for schoolId, '' for session.
        $parts            = explode('/', trim($basePath, '/'));
        $this->schoolId   = $parts[1] ?? $schoolName;
        $this->session    = $parts[2] ?? '';
        $this->adminId    = $adminId;
        $this->adminName  = $adminName;
        $this->schoolName = $schoolName;
        return $this;
    }

    /**
     * Log a fee event with full context.
     *
     * @param string $event     One of: fee_paid, refund_processed, refund_failed, demand_created,
     *                          demand_updated, payment_inconsistency, duplicate_attempt, amount_mismatch,
     *                          transaction_incomplete, migration_completed
     * @param array  $data      Event-specific payload
     * @return string|null      Log ID (push key)
     */
    public function log(string $event, array $data): ?string
    {
        $logEntry = [
            'event'        => $event,
            'student_id'   => $data['student_id'] ?? '',
            'amount'       => $data['amount'] ?? 0,
            'receipt_no'   => $data['receipt_no'] ?? '',
            'performed_by' => $this->adminName ?: ($this->adminId ?: 'system'),
            'admin_id'     => $this->adminId,
            'timestamp'    => date('c'),
            'school'       => $this->schoolName,
            'metadata'     => $data,
        ];

        try {
            // Firestore-canonical sink (NO RTDB). Sortable, tenant-isolated doc id.
            $auditId              = 'FAE_' . date('YmdHis') . '_' . substr(bin2hex(random_bytes(3)), 0, 6);
            $logEntry['auditId']  = $auditId;
            $logEntry['schoolId'] = $this->schoolId;
            $logEntry['session']  = $this->session;
            $ok    = (bool) $this->firebase->firestoreSet('feeAuditEvents', $auditId, $logEntry);
            $logId = $ok ? $auditId : null;

            // CI log for server-side trail
            $brief = "{$event} | student={$logEntry['student_id']} | amount={$logEntry['amount']} | receipt={$logEntry['receipt_no']}";
            log_message('info', "Fee_audit: {$brief}");

            // Trigger alert if critical event
            if (in_array($event, self::ALERT_EVENTS, true)) {
                $this->_createAlert($event, $logEntry, $logId);
            }

            return $logId;
        } catch (\Exception $e) {
            log_message('error', 'Fee_audit::log failed: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Create an alert for critical events.
     */
    private function _createAlert(string $event, array $logEntry, ?string $logId): void
    {
        try {
            // Route through the existing operator alert system (Fee_alerts → fee_alerts)
            // so criticals appear on the operator dashboard. Single Firestore alert sink
            // (no second 'feeAlerts' collection). Severity mapped to Fee_alerts levels.
            require_once APPPATH . 'libraries/Fee_alerts.php';
            $alerts = new Fee_alerts($this->firebase, $this->schoolId, $this->session);
            $sevMap = [
                'critical' => Fee_alerts::SEV_CRITICAL,
                'high'     => Fee_alerts::SEV_ERROR,
                'medium'   => Fee_alerts::SEV_WARNING,
            ];
            $sev = $sevMap[$this->_severity($event)] ?? Fee_alerts::SEV_WARNING;
            $msg = $this->_alertMessage($event, $logEntry);
            $alerts->raise($sev, 'fee_' . $event, $msg, $msg, [
                'refEntity' => 'student',
                'refId'     => (string) ($logEntry['student_id'] ?? ''),
                'payload'   => ['amount' => $logEntry['amount'] ?? 0, 'log_id' => $logId, 'event' => $event],
                'dedupKey'  => $event . '|' . ($logEntry['student_id'] ?? ''),
            ]);

            // Also log at CI error level for server monitoring
            log_message('error', "FEE_ALERT [{$this->_severity($event)}] {$msg}");
        } catch (\Exception $e) {
            log_message('error', 'Fee_audit::_createAlert failed: ' . $e->getMessage());
        }
    }

    private function _severity(string $event): string
    {
        $map = [
            'refund_failed'          => 'high',
            'payment_inconsistency'  => 'critical',
            'duplicate_attempt'      => 'medium',
            'amount_mismatch'        => 'critical',
            'transaction_incomplete' => 'high',
            'demand_reversal_failed' => 'high',
        ];
        return $map[$event] ?? 'medium';
    }

    private function _alertMessage(string $event, array $entry): string
    {
        $student = $entry['student_id'] ?: 'unknown';
        $amount  = $entry['amount'] ?? 0;
        $map = [
            'refund_failed'          => "Refund processing failed for student {$student}",
            'payment_inconsistency'  => "Payment amount mismatch detected — student {$student}, amount {$amount}",
            'duplicate_attempt'      => "Duplicate payment attempt blocked for student {$student}",
            'amount_mismatch'        => "Gateway amount does not match order for student {$student}",
            'transaction_incomplete' => "Fee transaction left incomplete for student {$student} — amount {$amount}",
            'demand_reversal_failed' => "Demand reversal failed during refund for student {$student}",
        ];
        return $map[$event] ?? "Fee event: {$event} for student {$student}";
    }
}
