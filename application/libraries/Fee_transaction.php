<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Fee_transaction — Transaction safety wrapper (Task 4).
 *
 * Tracks multi-step operations, records completion state,
 * and provides recovery info for incomplete transactions.
 *
 * Firebase path: Schools/{school}/{session}/Fees/Transactions/{txn_id}
 */
class Fee_transaction
{
    private $firebase;
    private $basePath;
    private $txnId;
    private $steps = [];
    private $startTime;
    private $metadata = [];

    public function init($firebase, string $basePath): self
    {
        $this->firebase  = $firebase;
        $this->basePath  = $basePath;
        return $this;
    }

    /**
     * Begin a new tracked transaction.
     *
     * @param  string $type     e.g. 'fee_payment', 'refund', 'demand_generation'
     * @param  array  $meta     Context: student_id, amount, receipt_no, etc.
     * @return string           Transaction ID
     */
    public function begin(string $type, array $meta = []): string
    {
        $this->txnId     = 'TXN_' . date('YmdHis') . '_' . bin2hex(random_bytes(4));
        $this->startTime = microtime(true);
        $this->steps     = [];
        $this->metadata  = $meta;

        $record = [
            'txn_id'     => $this->txnId,
            'type'       => $type,
            'status'     => 'started',
            'started_at' => date('c'),
            'metadata'   => $meta,
            'steps'      => [],
        ];

        try {
            $this->firebase->set("{$this->basePath}/Transactions/{$this->txnId}", $record);
        } catch (\Exception $e) {
            log_message('error', "Fee_transaction::begin failed: " . $e->getMessage());
        }

        return $this->txnId;
    }

    /**
     * Record a completed step within the transaction.
     */
    public function step(string $name, array $data = []): void
    {
        $entry = [
            'name'       => $name,
            'completed'  => date('c'),
            'elapsed_ms' => round((microtime(true) - $this->startTime) * 1000),
            'data'       => $data,
        ];
        $this->steps[] = $entry;

        try {
            $this->firebase->update("{$this->basePath}/Transactions/{$this->txnId}", [
                'last_step'       => $name,
                'last_step_at'    => date('c'),
                'steps_completed' => count($this->steps),
            ]);
        } catch (\Exception $e) {
            // Non-blocking — step tracking is best-effort
        }
    }

    /**
     * Mark transaction as complete.
     */
    public function complete(array $result = []): void
    {
        $elapsed = round((microtime(true) - $this->startTime) * 1000);

        try {
            $this->firebase->update("{$this->basePath}/Transactions/{$this->txnId}", [
                'status'          => 'complete',
                'completed_at'    => date('c'),
                'elapsed_ms'      => $elapsed,
                'steps_completed' => count($this->steps),
                'steps'           => $this->steps,
                'result'          => $result,
            ]);
        } catch (\Exception $e) {
            log_message('error', "Fee_transaction::complete failed: " . $e->getMessage());
        }

        if ($elapsed > 5000) {
            log_message('info', "Fee_transaction: SLOW txn {$this->txnId} took {$elapsed}ms");
        }
    }

    /**
     * Mark transaction as failed/partial.
     */
    public function fail(string $reason, string $failedStep = ''): void
    {
        $elapsed = round((microtime(true) - $this->startTime) * 1000);

        try {
            $this->firebase->update("{$this->basePath}/Transactions/{$this->txnId}", [
                'status'          => 'partial',
                'failed_at'       => date('c'),
                'failed_step'     => $failedStep,
                'failure_reason'  => $reason,
                'elapsed_ms'      => $elapsed,
                'steps_completed' => count($this->steps),
                'steps'           => $this->steps,
            ]);
        } catch (\Exception $e) {
            log_message('error', "Fee_transaction::fail write failed: " . $e->getMessage());
        }

        log_message('error', "Fee_transaction: PARTIAL txn={$this->txnId} step={$failedStep} reason={$reason}");
    }

    public function getTxnId(): string
    {
        return $this->txnId ?? '';
    }
}
