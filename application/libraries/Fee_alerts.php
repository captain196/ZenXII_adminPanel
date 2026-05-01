<?php
/**
 * Fee_alerts — Firestore-backed alert sink for the Fees module.
 *
 * Emits a doc into fee_alerts/{alertId} whenever something actionable
 * happens (job failure, high failure rate, data inconsistency, stuck
 * worker). The admin dashboard reads this collection in descending
 * createdAt order; an operator acknowledges an alert by setting
 * status='acknowledged' via the dashboard's ack endpoint.
 *
 * Severity levels (simple triage):
 *   info     – informational, no action required
 *   warning  – degraded but self-healing (e.g. batch retry succeeded)
 *   error    – operator attention needed (failed job, stuck worker)
 *   critical – data integrity breach (inconsistency across systems)
 *
 * Doc shape:
 *   {
 *     alertId,
 *     schoolId, session,
 *     severity:    'info'|'warning'|'error'|'critical',
 *     category:    'job_failure'|'high_failure_rate'|'integrity'|'stuck_worker'|'payment',
 *     title,
 *     message,
 *     jobId,       // optional
 *     workerId,    // optional
 *     refEntity,   // optional — 'demand'|'receipt'|'defaulter'
 *     refId,       // optional
 *     payload,     // arbitrary JSON bag for dashboard rendering
 *     status:      'open'|'acknowledged'|'resolved',
 *     createdAt, updatedAt,
 *     ackedBy,     // set on acknowledge
 *     ackedAt,
 *   }
 *
 * CI-free. Never throws.
 */
final class Fee_alerts
{
    public const COLLECTION = 'fee_alerts';

    public const SEV_INFO     = 'info';
    public const SEV_WARNING  = 'warning';
    public const SEV_ERROR    = 'error';
    public const SEV_CRITICAL = 'critical';

    private $firebase;
    private string $schoolId;
    private string $session;

    /**
     * External dispatch config (loaded lazily from feeSettings/{schoolId}_alerts
     * or env). Keeping the URL/email out of constructors so callers don't
     * have to know about them; dispatch is best-effort and never fails
     * the `raise()` call that triggered it.
     *
     * Firestore doc shape: feeSettings/{schoolId}_alerts
     *   {
     *     slackWebhookUrl:   "https://hooks.slack.com/services/...",
     *     emailRecipients:   ["ops@school.in"],          // optional
     *     minSeverity:       "error",                     // 'warning'|'error'|'critical'
     *     enabled:           true,
     *   }
     */
    private ?array $dispatchConfig = null;

    public function __construct($firebase, string $schoolId, string $session = '')
    {
        $this->firebase = $firebase;
        $this->schoolId = $schoolId;
        $this->session  = $session;
    }

    /**
     * Raise an alert. Returns the alertId on success, false otherwise.
     *
     * @param array $opts  optional:
     *   jobId, workerId, refEntity, refId, payload, dedupKey
     */
    public function raise(
        string $severity,
        string $category,
        string $title,
        string $message,
        array  $opts = []
    ) {
        if (!in_array($severity, [self::SEV_INFO, self::SEV_WARNING, self::SEV_ERROR, self::SEV_CRITICAL], true)) {
            $severity = self::SEV_WARNING;
        }
        // dedupKey lets callers collapse alert storms (e.g. one alert
        // per jobId+category instead of one per failed batch). If the
        // existing open alert with this dedup key is still open, we
        // bump its updatedAt + occurrence count and skip a new write.
        $dedupKey = (string) ($opts['dedupKey'] ?? '');
        if ($dedupKey !== '') {
            $existing = $this->findOpenByDedup($dedupKey);
            if ($existing !== null) {
                $this->bumpOccurrence($existing['alertId'] ?? '', $existing['occurrences'] ?? 1);
                return $existing['alertId'] ?? false;
            }
        }

        $alertId = 'ALRT_' . date('YmdHis') . '_' . substr(bin2hex(random_bytes(3)), 0, 6);
        $doc = [
            'alertId'     => $alertId,
            'schoolId'    => $this->schoolId,
            'session'     => $this->session,
            'severity'    => $severity,
            'category'    => $category,
            'title'       => $title,
            'message'     => $message,
            'jobId'       => (string) ($opts['jobId']    ?? ''),
            'workerId'    => isset($opts['workerId']) ? (int) $opts['workerId'] : -1,
            'refEntity'   => (string) ($opts['refEntity'] ?? ''),
            'refId'       => (string) ($opts['refId']    ?? ''),
            'payload'     => is_array($opts['payload'] ?? null) ? $opts['payload'] : [],
            'dedupKey'    => $dedupKey,
            'status'      => 'open',
            'occurrences' => 1,
            'createdAt'   => date('c'),
            'updatedAt'   => date('c'),
        ];
        try {
            $ok = (bool) $this->firebase->firestoreSet(self::COLLECTION, $alertId, $doc);
        } catch (\Throwable $e) {
            if (function_exists('log_message')) log_message('warning', "Fee_alerts: write failed for {$alertId}: " . $e->getMessage());
            return false;
        }
        if ($ok) $this->dispatchExternal($severity, $doc);
        return $ok ? $alertId : false;
    }

    // ─── External dispatch (Slack webhook + email) ────────────────────

    /**
     * Fire out-of-band notifications for high-severity alerts. Called
     * once per `raise()`; silently skips when:
     *   • dispatch is disabled in config
     *   • severity is below the configured floor (default: 'error')
     *   • no webhook URL and no email recipients configured
     *
     * Never throws — a failed webhook must not take down the caller.
     */
    private function dispatchExternal(string $severity, array $alertDoc): void
    {
        $cfg = $this->loadDispatchConfig();
        if (empty($cfg['enabled'])) return;

        static $severityRank = [
            self::SEV_INFO => 0, self::SEV_WARNING => 1,
            self::SEV_ERROR => 2, self::SEV_CRITICAL => 3,
        ];
        $minSev  = (string) ($cfg['minSeverity'] ?? self::SEV_ERROR);
        if (($severityRank[$severity] ?? 0) < ($severityRank[$minSev] ?? 2)) return;

        // Slack webhook
        if (!empty($cfg['slackWebhookUrl'])) {
            $this->postSlack((string) $cfg['slackWebhookUrl'], $alertDoc);
        }
        // Email (SMTP via mail() — environment-dependent; caller may
        // override by wiring a proper MTA). Skipped if no recipients.
        if (!empty($cfg['emailRecipients']) && is_array($cfg['emailRecipients'])) {
            $this->sendEmail($cfg['emailRecipients'], $alertDoc);
        }
    }

    private function loadDispatchConfig(): array
    {
        if (is_array($this->dispatchConfig)) return $this->dispatchConfig;
        $cfg = [
            'enabled'         => false,
            'slackWebhookUrl' => (string) (getenv('FEE_ALERTS_SLACK_URL') ?: ''),
            'emailRecipients' => [],
            'minSeverity'     => self::SEV_ERROR,
        ];
        try {
            if (method_exists($this->firebase, 'firestoreGet')) {
                $doc = $this->firebase->firestoreGet('feeSettings', "{$this->schoolId}_alerts");
                if (is_array($doc)) $cfg = array_merge($cfg, $doc);
            }
        } catch (\Throwable $_) { /* best-effort */ }
        $cfg['enabled'] = !empty($cfg['enabled'])
                       || !empty($cfg['slackWebhookUrl'])
                       || !empty($cfg['emailRecipients']);
        return $this->dispatchConfig = $cfg;
    }

    private function postSlack(string $webhookUrl, array $alertDoc): void
    {
        $emoji = [
            self::SEV_INFO     => ':information_source:',
            self::SEV_WARNING  => ':warning:',
            self::SEV_ERROR    => ':rotating_light:',
            self::SEV_CRITICAL => ':fire:',
        ][(string) ($alertDoc['severity'] ?? 'info')] ?? ':bell:';

        $title   = (string) ($alertDoc['title']   ?? 'Alert');
        $message = (string) ($alertDoc['message'] ?? '');
        $school  = (string) ($alertDoc['schoolId'] ?? $this->schoolId);
        $lines   = [
            "{$emoji} *{$title}*",
            $message !== '' ? "> {$message}" : '',
            "_school=`{$school}` category=`" . ($alertDoc['category'] ?? '') . "`_",
        ];
        if (!empty($alertDoc['jobId']))    $lines[] = "_job=`{$alertDoc['jobId']}`_";
        if (!empty($alertDoc['refEntity'])) $lines[] = "_{$alertDoc['refEntity']}=`{$alertDoc['refId']}`_";

        $payload = json_encode(['text' => implode("\n", array_filter($lines))]);
        $ch = curl_init($webhookUrl);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 5,
            CURLOPT_CONNECTTIMEOUT => 3,
        ]);
        @curl_exec($ch);
        curl_close($ch);
    }

    private function sendEmail(array $recipients, array $alertDoc): void
    {
        $subj = sprintf('[%s] %s — %s',
            strtoupper((string) ($alertDoc['severity'] ?? 'alert')),
            (string) ($alertDoc['title']    ?? 'Alert'),
            (string) ($alertDoc['schoolId'] ?? $this->schoolId)
        );
        $body = json_encode($alertDoc, JSON_PRETTY_PRINT);
        $headers = "From: no-reply@schoolsync.local\r\n"
                 . "Content-Type: text/plain; charset=UTF-8\r\n"
                 . "X-SchoolSync-Alert: " . (string) ($alertDoc['alertId'] ?? '');
        foreach ($recipients as $to) {
            if (!is_string($to) || $to === '') continue;
            @mail($to, $subj, $body, $headers);
        }
    }

    public function acknowledge(string $alertId, string $ackedBy, string $note = ''): bool
    {
        if ($alertId === '') return false;
        try {
            return (bool) $this->firebase->firestoreSet(self::COLLECTION, $alertId, [
                'status'    => 'acknowledged',
                'ackedBy'   => $ackedBy,
                'ackNote'   => $note,
                'ackedAt'   => date('c'),
                'updatedAt' => date('c'),
            ], /* merge */ true);
        } catch (\Throwable $_) { return false; }
    }

    public function resolve(string $alertId, string $resolvedBy, string $note = ''): bool
    {
        if ($alertId === '') return false;
        try {
            return (bool) $this->firebase->firestoreSet(self::COLLECTION, $alertId, [
                'status'      => 'resolved',
                'resolvedBy'  => $resolvedBy,
                'resolveNote' => $note,
                'resolvedAt'  => date('c'),
                'updatedAt'   => date('c'),
            ], /* merge */ true);
        } catch (\Throwable $_) { return false; }
    }

    private function findOpenByDedup(string $dedupKey): ?array
    {
        try {
            if (!method_exists($this->firebase, 'firestoreQuery')) return null;
            $rows = $this->firebase->firestoreQuery(self::COLLECTION, [
                ['schoolId', '==', $this->schoolId],
                ['dedupKey', '==', $dedupKey],
                ['status',   '==', 'open'],
            ]);
            foreach ((array) $rows as $r) {
                $d = $r['data'] ?? $r;
                if (is_array($d)) return $d;
            }
        } catch (\Throwable $_) {}
        return null;
    }

    private function bumpOccurrence(string $alertId, $prior): void
    {
        if ($alertId === '') return;
        try {
            $this->firebase->firestoreSet(self::COLLECTION, $alertId, [
                'occurrences' => (int) $prior + 1,
                'updatedAt'   => date('c'),
            ], /* merge */ true);
        } catch (\Throwable $_) {}
    }
}
