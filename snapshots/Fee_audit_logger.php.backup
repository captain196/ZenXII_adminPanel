<?php
/**
 * Fee_audit_logger — enterprise-grade financial audit trail.
 *
 * Every write that touches money (demand create/update, receipt
 * finalise, payment mark-processed, manual admin edits) should call
 * `record()` with a before+after snapshot. The logger stores the
 * diff in fee_audit_logs/{auditId} for regulator-ready history.
 *
 * Doc shape (fee_audit_logs/{auditId}):
 *   {
 *     auditId,                      // stable, sortable (YYYYMMDD + hex)
 *     schoolId, session,
 *     action:       'create'|'update'|'delete',
 *     entity:       'demand'|'receipt'|'payment'|'defaulter'|'refund'|'job',
 *     entityId,                     // whatever the collection key is
 *     before:       { ...snapshot before this change, keys diffed only },
 *     after:        { ...snapshot after this change },
 *     changedKeys:  [ 'paidAmount', 'balance', 'status' ],
 *     performedBy,                  // admin id / 'parent_app' / 'cli_worker'
 *     source,                       // optional: 'web'|'cli'|'razorpay'|'webhook'
 *     reason,                       // optional free-form
 *     timestamp,                    // ISO-8601
 *   }
 *
 * The logger never fails the caller: if the audit write breaks, we
 * log a warning and return false — the underlying transaction
 * proceeds unaffected. Financial correctness always beats audit
 * completeness; the reconciliation script closes any gaps nightly.
 *
 * CI-free: accepts a raw firebase wrapper (must expose
 * firestoreSet($collection, $docId, $data)). Works from CLI workers
 * AND from CI controllers.
 */
final class Fee_audit_logger
{
    public const COLLECTION = 'fee_audit_logs';

    /** @var object must expose firestoreSet($collection, $docId, array $data[, bool $merge]) */
    private $firebase;
    private string $schoolId;
    private string $session;

    /** Fields to scrub from snapshots before writing — never audit PII. */
    private const REDACTED_KEYS = ['password', 'token', 'secret', 'otp'];

    public function __construct($firebase, string $schoolId, string $session)
    {
        $this->firebase = $firebase;
        $this->schoolId = $schoolId;
        $this->session  = $session;
    }

    /**
     * Record a financial change. Safe to call with an empty `before`
     * (for pure creates) or empty `after` (for deletes).
     *
     * @return string|false  auditId on success, false on write failure.
     */
    public function record(
        string $action,
        string $entity,
        string $entityId,
        array  $before,
        array  $after,
        string $performedBy,
        array  $opts = []
    ) {
        if ($entity === '' || $entityId === '' || !in_array($action, ['create','update','delete'], true)) {
            return false;
        }
        $before  = $this->scrub($before);
        $after   = $this->scrub($after);
        $changed = $this->diffKeys($before, $after);

        // Skip writes where nothing actually changed (noise reduction).
        if ($action === 'update' && empty($changed)) return false;

        $auditId = self::makeAuditId($entity, $entityId);
        $doc = [
            'auditId'     => $auditId,
            'schoolId'    => $this->schoolId,
            'session'     => $this->session,
            'action'      => $action,
            'entity'      => $entity,
            'entityId'    => $entityId,
            'before'      => $this->projectKeys($before, $changed),
            'after'       => $this->projectKeys($after,  $changed),
            'changedKeys' => array_values($changed),
            'performedBy' => $performedBy !== '' ? $performedBy : 'system',
            'source'      => (string) ($opts['source'] ?? ''),
            'reason'      => (string) ($opts['reason'] ?? ''),
            'timestamp'   => date('c'),
        ];
        try {
            $ok = (bool) $this->firebase->firestoreSet(self::COLLECTION, $auditId, $doc);
            return $ok ? $auditId : false;
        } catch (\Throwable $e) {
            if (function_exists('log_message')) log_message('warning', "Fee_audit_logger: write failed for {$auditId}: " . $e->getMessage());
            return false;
        }
    }

    public static function makeAuditId(string $entity, string $entityId): string
    {
        // sortable-by-time prefix + disambiguator; ≤1e6-way collision-resistant per ms.
        return 'AUD_' . date('YmdHis') . '_' . strtoupper($entity) . '_'
             . substr(bin2hex(random_bytes(3)), 0, 6);
    }

    /** Returns the keys whose VALUES differ between before/after (top-level only). */
    private function diffKeys(array $before, array $after): array
    {
        $all = array_unique(array_merge(array_keys($before), array_keys($after)));
        $out = [];
        foreach ($all as $k) {
            $bv = $before[$k] ?? null;
            $av = $after[$k]  ?? null;
            // Loose equality is deliberate — we don't care whether
            // 0 vs "0" moved, only whether the economic value did.
            if ($bv != $av) $out[] = $k;
        }
        return $out;
    }

    private function projectKeys(array $src, array $keys): array
    {
        $out = [];
        foreach ($keys as $k) if (array_key_exists($k, $src)) $out[$k] = $src[$k];
        return $out;
    }

    private function scrub(array $d): array
    {
        foreach (self::REDACTED_KEYS as $k) unset($d[$k]);
        return $d;
    }
}
