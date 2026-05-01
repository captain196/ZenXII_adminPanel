<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * AccountingWatchdog — Phase 8D scheduled alert emitter.
 *
 * ──────────────────────────────────────────────────────────────────────
 *  PURPOSE
 * ──────────────────────────────────────────────────────────────────────
 *  The reconciler fixes drift. This watchdog's sole job is to EMIT
 *  alerts when conditions that need operator attention are present —
 *  so dashboards and external alert channels (email / webhook) don't
 *  have to scrape logs.
 *
 *  It is deliberately separate from the reconciler because:
 *    • Reconciler makes changes. Watchdog must never mutate state.
 *    • Their cadences differ (reconciler 15 min, watchdog 5 min).
 *    • If reconciler itself is down, the watchdog still fires.
 *
 * ──────────────────────────────────────────────────────────────────────
 *  WHAT IT DOES PER RUN
 * ──────────────────────────────────────────────────────────────────────
 *    1. Build the health snapshot via Accounting_health.
 *    2. For each alert in the snapshot:
 *         a. Dedup against existing open accountingAlerts of the same
 *            (rule, severity) to avoid spam.
 *         b. If none open → write a new alertId.
 *         c. If an open alert exists but the condition is now clear →
 *            auto-acknowledge it (timestamp + autoAck reason).
 *    3. Optionally POST the alert payload to a webhook URL from
 *       env var `ACCOUNTING_ALERT_WEBHOOK` — one-shot, best-effort,
 *       never blocks the run.
 *    4. Log an ACC_WATCHDOG_* line summarising counts.
 *
 *  The admin dashboard (`/accounting/health_dashboard`) reads the
 *  open-alerts list straight from Firestore — no coupling to this
 *  file beyond the collection shape.
 *
 * ──────────────────────────────────────────────────────────────────────
 *  INVOCATION
 * ──────────────────────────────────────────────────────────────────────
 *      php index.php accountingwatchdog run
 *
 *  Scheduler (Windows Task Scheduler, every 5 min):
 *      Program:   C:\xampp\php\php.exe
 *      Arguments: index.php accountingwatchdog run
 *      Env:       SCHOOL_NAME, SESSION_YEAR
 *                 ACCOUNTING_ALERT_WEBHOOK=<url>   (optional)
 */
class AccountingWatchdog extends CI_Controller
{
    private const COL_ALERTS = 'accountingAlerts';

    private string $schoolFs   = '';
    private string $session    = '';
    private string $schoolName = '';

    public function __construct()
    {
        parent::__construct();
        if (!$this->input->is_cli_request()) {
            show_error('AccountingWatchdog is CLI-only.', 403);
        }
        $this->load->library('firebase');

        $this->schoolName = (string) (getenv('SCHOOL_NAME')  ?: '');
        $this->session    = (string) (getenv('SESSION_YEAR') ?: '');
        if ($this->schoolName === '' || $this->session === '') {
            echo "ERROR: Set SCHOOL_NAME and SESSION_YEAR env vars.\n";
            exit(1);
        }
        $this->firebase->initFirestore($this->schoolName, $this->session);
        $this->load->library('firestore_service');
        $this->schoolFs = (string) $this->firebase->getSchoolId();
        if ($this->schoolFs === '') {
            echo "ERROR: Could not resolve schoolId for {$this->schoolName}.\n";
            exit(1);
        }
    }

    public function run(): void
    {
        $this->_out("AccountingWatchdog run @ " . date('c') . " school={$this->schoolFs}");

        $this->load->library('Accounting_health', null, 'acctHealth');
        $this->acctHealth->init($this->firebase, $this->schoolFs, $this->session, $this->schoolName);
        $snapshot = $this->acctHealth->snapshot();

        $active = (array) ($snapshot['alerts'] ?? []);
        $open   = $this->_listOpenAlerts();

        $emitted   = 0;
        $acked     = 0;
        $deduped   = 0;
        $webhookOk = 0;
        $webhookUrl = (string) getenv('ACCOUNTING_ALERT_WEBHOOK');

        // (a) Emit new alerts, dedup against already-open ones
        foreach ($active as $alert) {
            $rule     = (string) ($alert['rule']     ?? '');
            $severity = (string) ($alert['severity'] ?? 'warning');
            $message  = (string) ($alert['message']  ?? '');
            if ($rule === '') continue;

            $exists = false;
            foreach ($open as $o) {
                if (($o['rule'] ?? '') === $rule && ($o['severity'] ?? '') === $severity) {
                    $exists = true; break;
                }
            }
            if ($exists) { $deduped++; continue; }

            $alertId = $this->_writeAlert($rule, $severity, $message, $snapshot);
            if ($alertId !== '') {
                $emitted++;
                if ($webhookUrl !== '' && $this->_postWebhook($webhookUrl, [
                    'alertId'  => $alertId,
                    'schoolId' => $this->schoolFs,
                    'session'  => $this->session,
                    'rule'     => $rule,
                    'severity' => $severity,
                    'message'  => $message,
                    'at'       => date('c'),
                ])) {
                    $webhookOk++;
                }
            }
        }

        // (b) Auto-acknowledge open alerts whose condition is no longer
        //     active. Keeps the dashboard from showing stale red flags.
        $activeKeys = [];
        foreach ($active as $a) {
            $activeKeys[((string) ($a['rule'] ?? '')) . '::' . ((string) ($a['severity'] ?? ''))] = true;
        }
        foreach ($open as $o) {
            $key = ((string) ($o['rule'] ?? '')) . '::' . ((string) ($o['severity'] ?? ''));
            if (!isset($activeKeys[$key])) {
                if ($this->_autoAcknowledge((string) $o['alertId'])) $acked++;
            }
        }

        log_message('error',
            "ACC_WATCHDOG_RUN emitted={$emitted} deduped={$deduped} autoAcked={$acked} webhooks={$webhookOk} activeAlerts=" . count($active));
        $this->_out("  emitted={$emitted} deduped={$deduped} auto_ack={$acked} webhooks={$webhookOk}");
    }

    // ─────────────────────────────────────────────────────────────────
    //  Internals
    // ─────────────────────────────────────────────────────────────────

    private function _listOpenAlerts(): array
    {
        try {
            $rows = (array) $this->firebase->firestoreQuery(self::COL_ALERTS, [
                ['schoolId',     '==', $this->schoolFs],
                ['session',      '==', $this->session],
                ['acknowledged', '==', false],
            ], 'createdAt', 'DESC', 50);
            $out = [];
            foreach ($rows as $r) {
                $d = is_array($r['data'] ?? null) ? $r['data'] : [];
                $out[] = [
                    'alertId'  => (string) ($r['id'] ?? ''),
                    'rule'     => (string) ($d['rule']     ?? ''),
                    'severity' => (string) ($d['severity'] ?? ''),
                    'message'  => (string) ($d['message']  ?? ''),
                ];
            }
            return $out;
        } catch (\Throwable $e) {
            log_message('error', 'ACC_WATCHDOG_LIST_ERROR: ' . $e->getMessage());
            return [];
        }
    }

    private function _writeAlert(string $rule, string $severity, string $message, array $snapshot): string
    {
        $alertId = "{$this->schoolFs}_" . date('YmdHis') . '_' . bin2hex(random_bytes(3)) . "_{$rule}";
        try {
            $ok = $this->firebase->firestoreSet(self::COL_ALERTS, $alertId, [
                'alertId'        => $alertId,
                'schoolId'       => $this->schoolFs,
                'session'        => $this->session,
                'rule'           => $rule,
                'severity'       => $severity,
                'message'        => $message,
                'createdAt'      => date('c'),
                'acknowledged'   => false,
                'acknowledgedAt' => null,
                'acknowledgedBy' => null,
                // Compact context so operators can triage without
                // re-running the health endpoint.
                'context'        => [
                    'worker_down'        => (bool) ($snapshot['worker']['down']     ?? false),
                    'worker_age_sec'     => (int)  ($snapshot['worker']['age_seconds'] ?? -1),
                    'reconciler_down'    => (bool) ($snapshot['reconciler']['down'] ?? false),
                    'idemp_processing'   => (int)  ($snapshot['idempotency']['processing_count'] ?? 0),
                    'idemp_stuck'        => (int)  ($snapshot['idempotency']['stuck_count']      ?? 0),
                    'idemp_failed'       => (int)  ($snapshot['idempotency']['failed_count']     ?? 0),
                ],
            ]);
            if ($ok) {
                log_message('error', "ACC_ALERT_EMITTED rule={$rule} severity={$severity} alertId={$alertId}");
                return $alertId;
            }
        } catch (\Throwable $e) {
            log_message('error', "ACC_WATCHDOG_EMIT_ERROR rule={$rule}: " . $e->getMessage());
        }
        return '';
    }

    private function _autoAcknowledge(string $alertId): bool
    {
        try {
            $ok = (bool) $this->firebase->firestoreSet(self::COL_ALERTS, $alertId, [
                'acknowledged'   => true,
                'acknowledgedAt' => date('c'),
                'acknowledgedBy' => 'auto:watchdog_condition_cleared',
                'updatedAt'      => date('c'),
            ], /* merge */ true);
            if ($ok) log_message('error', "ACC_ALERT_AUTOACK alertId={$alertId}");
            return $ok;
        } catch (\Throwable $e) {
            log_message('error', "ACC_WATCHDOG_AUTOACK_ERROR alertId={$alertId}: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Best-effort webhook POST. 5 s timeout, never throws, never blocks
     * the run for longer than that. Output is logged; payload is
     * already in Firestore regardless of webhook outcome.
     */
    private function _postWebhook(string $url, array $payload): bool
    {
        if (!function_exists('curl_init')) return false;
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($payload),
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 5,
            CURLOPT_CONNECTTIMEOUT => 3,
        ]);
        $body = @curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($code >= 200 && $code < 300) return true;
        log_message('error', "ACC_WATCHDOG_WEBHOOK_FAIL url={$url} code={$code} body=" . substr((string) $body, 0, 200));
        return false;
    }

    private function _out(string $line): void
    {
        echo '[' . date('H:i:s') . "] {$line}\n";
    }
}
