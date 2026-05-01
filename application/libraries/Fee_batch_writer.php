<?php
/**
 * Fee_batch_writer — Phase 3 batch write transport (Phase 3.5 hardened).
 *
 * ──────────────────────────────────────────────────────────────────────
 *  TRANSPORT ABSTRACTION
 * ──────────────────────────────────────────────────────────────────────
 * The commit function is injected as a callable so the business logic
 * never depends on the underlying transport. Today the CLI worker wires
 * it to FirestoreRestClient::commitBatch (single HTTPS request per
 * batch). Once gRPC is installed, swap the callable to the Firestore
 * PHP SDK's `batch()->commit()` — nothing else changes.
 *
 *     $writer = new Fee_batch_writer(
 *         fn(array $ops) => $fsRest->commitBatch($ops),   // REST today
 *     );
 *     // Later:
 *     $writer = new Fee_batch_writer(
 *         fn(array $ops) => $sdk->batch()->commit($ops),   // SDK swap
 *     );
 *
 * Operation shape (stable across transports):
 *     [
 *       'op'         => 'set' | 'update' | 'delete',
 *       'collection' => 'feeDemands',
 *       'docId'      => 'DEM_STU0001_202604_FH_ABC123',
 *       'merge'      => true,                 // only for 'set'
 *       'data'       => [ ... camelCase ... ],
 *     ]
 *
 * ──────────────────────────────────────────────────────────────────────
 *  GUARANTEES (Phase 3.5)
 * ──────────────────────────────────────────────────────────────────────
 *  • Chunks incoming ops into commits of at most `maxBatchSize` (≤500).
 *  • Retries each chunk up to `maxRetries` with exponential backoff
 *    (delay_ms = min(backoffCapMs, baseBackoffMs * attempt)).
 *  • Throttles `throttleMicros` between successful commits to stay
 *    well under Firestore's 500-writes/s soft ceiling.
 *  • FAILURE TRACKING — every batch that exhausts retries is tagged
 *    with a stable batchId and handed to the caller-supplied
 *    `onBatchFailed` callback (e.g. persist to
 *    fee_generation_failed_batches/{batchId}). The writer itself never
 *    touches Firestore for this; persistence is the caller's concern
 *    to keep transport and storage concerns separate.
 *  • NEVER throws. Returns a stats struct so the caller can decide
 *    whether to continue, abort, or escalate — but the caller now has
 *    `failedBatchIds` pointing at durable records of every failure.
 *
 *  Stats shape:
 *    [
 *      'committedOps'     => int,
 *      'committedBatches' => int,
 *      'failedBatches'    => int,
 *      'failedBatchIds'   => string[],   // persisted via onBatchFailed
 *      'totalOps'         => int,
 *      'ok'               => bool,
 *      'errors'           => string[],   // last-attempt message per failed batch
 *    ]
 *
 *  Failure-callback signature:
 *      fn(array $failedBatch): void
 *
 *      $failedBatch = [
 *        'batchId'       => string,
 *        'ops'           => array,      // the exact op list that failed
 *        'attempts'      => int,
 *        'lastError'     => string,
 *        'firstAttemptAt'=> ISO-8601,
 *        'lastAttemptAt' => ISO-8601,
 *      ]
 */
final class Fee_batch_writer
{
    /** @var callable(array): bool */
    private $commitFn;
    /** @var null|callable(array): void */
    private $onBatchFailed;

    private int $maxBatchSize;
    private int $maxRetries;
    private int $baseBackoffMs;
    private int $backoffCapMs;
    private int $throttleMicros;
    /** @var null|callable(string,string):void */
    private $logger;
    /** Context prefix for every log line (e.g. "[job=X worker=N]"). */
    private string $logContext = '';

    /**
     * @param callable(array $ops): bool $commitFn    Transport commit (REST today, SDK tomorrow)
     * @param array{
     *   maxBatchSize?: int,
     *   maxRetries?: int,
     *   baseBackoffMs?: int,
     *   backoffCapMs?: int,
     *   throttleMicros?: int,
     *   logger?: callable,
     *   onBatchFailed?: callable,     // invoked once per batch that exhausts retries
     * } $opts
     */
    public function __construct(callable $commitFn, array $opts = [])
    {
        $this->commitFn       = $commitFn;
        $this->onBatchFailed  = $opts['onBatchFailed'] ?? null;
        $this->maxBatchSize   = max(1, min(500, (int) ($opts['maxBatchSize']  ?? 400)));
        $this->maxRetries     = max(1, (int) ($opts['maxRetries']    ?? 3));
        $this->baseBackoffMs  = max(0, (int) ($opts['baseBackoffMs'] ?? 1000));
        $this->backoffCapMs   = max(0, (int) ($opts['backoffCapMs']  ?? 5000));
        $this->throttleMicros = max(0, (int) ($opts['throttleMicros'] ?? 200000));
        $this->logger         = $opts['logger'] ?? null;
        $this->logContext     = (string) ($opts['logContext'] ?? '');
    }

    /**
     * Bind a context tag (e.g. "[job=… worker=…]") onto every subsequent
     * log line. Call once right after construction so the engine can
     * stitch commit logs to its jobId + workerId.
     */
    public function setLogContext(string $context): void
    {
        $this->logContext = $context;
    }

    /**
     * Commit an arbitrary number of ops. Internally chunks into batches
     * of maxBatchSize and retries each batch independently.
     */
    public function commit(array $ops): array
    {
        $stats = [
            'committedOps'     => 0,
            'committedBatches' => 0,
            'failedBatches'    => 0,
            'failedBatchIds'   => [],
            'totalOps'         => count($ops),
            'ok'               => true,
            'errors'           => [],
        ];
        if (empty($ops)) return $stats;

        $chunks = array_chunk($ops, $this->maxBatchSize);
        foreach ($chunks as $i => $chunk) {
            $outcome = $this->commitOneBatch($chunk, $i + 1, count($chunks));
            if ($outcome['ok']) {
                $stats['committedOps']     += count($chunk);
                $stats['committedBatches'] += 1;
                if ($this->throttleMicros > 0 && $i < count($chunks) - 1) {
                    usleep($this->throttleMicros);
                }
            } else {
                $stats['failedBatches']  += 1;
                $stats['failedBatchIds'][] = $outcome['batchId'];
                $stats['errors'][]         = $outcome['lastError'];
                $stats['ok'] = false;
                // Hand the failure off to the caller so it lands in a
                // durable store (fee_generation_failed_batches/…).
                if (is_callable($this->onBatchFailed)) {
                    try {
                        ($this->onBatchFailed)([
                            'batchId'        => $outcome['batchId'],
                            'ops'            => $chunk,
                            'attempts'       => $this->maxRetries,
                            'lastError'      => $outcome['lastError'],
                            'firstAttemptAt' => $outcome['firstAttemptAt'],
                            'lastAttemptAt'  => $outcome['lastAttemptAt'],
                        ]);
                    } catch (\Throwable $e) {
                        $this->log('error', "onBatchFailed threw for batch {$outcome['batchId']}: " . $e->getMessage());
                    }
                }
            }
        }
        return $stats;
    }

    /**
     * Re-commit an ops array that was previously captured by
     * `onBatchFailed`. Used by the `--retry-failed` CLI path. Returns
     * true on first-try success, false otherwise. The caller is
     * expected to update / delete the persisted failure record based
     * on the return value.
     */
    public function replay(array $ops): bool
    {
        if (empty($ops)) return true;
        $outcome = $this->commitOneBatch($ops, 1, 1);
        return (bool) $outcome['ok'];
    }

    /**
     * @return array{ok:bool,batchId:string,lastError:string,firstAttemptAt:string,lastAttemptAt:string,attempts:int}
     */
    private function commitOneBatch(array $chunk, int $idx, int $total): array
    {
        $batchId = self::makeBatchId();
        $firstAt = date('c');
        $lastErr = '';
        for ($attempt = 1; $attempt <= $this->maxRetries; $attempt++) {
            $lastAt = date('c');
            try {
                $ok = (bool) (($this->commitFn)($chunk));
                if ($ok) {
                    $this->log('info',
                        "batch={$batchId} ({$idx}/{$total}) COMMITTED ops=" . count($chunk) . " attempt={$attempt}");
                    return [
                        'ok' => true, 'batchId' => $batchId,
                        'lastError' => '', 'firstAttemptAt' => $firstAt,
                        'lastAttemptAt' => $lastAt, 'attempts' => $attempt,
                    ];
                }
                $lastErr = 'commit returned false';
                $this->log('warning',
                    "batch={$batchId} ({$idx}/{$total}) RETRY {$attempt}/{$this->maxRetries} reason={$lastErr}");
            } catch (\Throwable $e) {
                $lastErr = $e->getMessage();
                $this->log('warning',
                    "batch={$batchId} ({$idx}/{$total}) RETRY {$attempt}/{$this->maxRetries} reason=exception:{$lastErr}");
            }
            if ($attempt < $this->maxRetries) {
                $delayMs = min($this->backoffCapMs, $this->baseBackoffMs * $attempt);
                usleep($delayMs * 1000);
            }
        }
        $this->log('error',
            "batch={$batchId} ({$idx}/{$total}) FAILED after {$this->maxRetries} attempts lastError={$lastErr}");
        return [
            'ok' => false, 'batchId' => $batchId,
            'lastError' => $lastErr, 'firstAttemptAt' => $firstAt,
            'lastAttemptAt' => date('c'), 'attempts' => $this->maxRetries,
        ];
    }

    /**
     * Stable, sortable, globally-unique batch ID. Sortable prefix helps
     * admin scans of the failed-batches collection.
     */
    public static function makeBatchId(): string
    {
        return 'BATCH_' . date('YmdHis') . '_' . substr(bin2hex(random_bytes(4)), 0, 8);
    }

    private function log(string $level, string $msg): void
    {
        $prefixed = $this->logContext !== '' ? "{$this->logContext} {$msg}" : $msg;
        if (is_callable($this->logger)) {
            ($this->logger)($level, $prefixed);
        } elseif (function_exists('log_message')) {
            log_message($level, "Fee_batch_writer: {$prefixed}");
        }
    }
}
