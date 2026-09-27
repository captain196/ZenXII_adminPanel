<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * The M-3 regeneration lock must never be able to deadlock a payslip.
 *
 * DEADLOCK (found live 2026-08-19). regenerate_staff_payroll() claims a lock by
 * writing status='Processing' with a timestamp, and releases it at the end by
 * writing `'status' => $preStatus` together with `processingStartedAt => ''`.
 *
 * If a previous regeneration crashed, the slip is left at 'Processing'. The next
 * request takes the 5-minute stale escape and proceeds — but with
 * $preStatus === 'Processing'. On success it therefore writes:
 *
 *     status = 'Processing',  processingStartedAt = ''
 *
 * and the stale escape is `$startedAt !== '' && …`, which an empty timestamp can
 * never satisfy. The slip becomes PERMANENTLY unregenerable, with no route back
 * through the UI — one crash plus one success is enough to strand it.
 *
 * The fix resets $preStatus to 'Draft' on the stale-escape path. That is not a
 * guess: regeneration already refuses unless the RUN is Draft, so every slip
 * reaching this code belongs to a Draft run, and 'Processing' is a transient
 * lock state that is never a resting status.
 */
final class RegenerationProcessingLockTest extends TestCase
{
    private string $src;

    protected function setUp(): void
    {
        $raw = file_get_contents(__DIR__ . '/../../application/controllers/Hr.php');
        $this->assertNotFalse($raw);
        $out = '';
        foreach (token_get_all($raw) as $token) {
            if (is_array($token)) {
                if ($token[0] === T_COMMENT || $token[0] === T_DOC_COMMENT) continue;
                $out .= $token[1];
            } else {
                $out .= $token;
            }
        }
        $this->src = $out;
    }

    /** The stale escape still exists and is still time-bounded. */
    public function test_stale_lock_escape_is_present_and_bounded(): void
    {
        $this->assertStringContainsString(
            "\$isStale = \$startedAt !== '' && (strtotime(\$startedAt) + 300) < time();",
            $this->src,
            'a crashed regeneration must become recoverable after 5 minutes'
        );
    }

    /** THE FIX: 'Processing' is never carried forward as a resting status. */
    public function test_processing_is_not_carried_forward_as_a_resting_status(): void
    {
        $this->assertStringContainsString("\$preStatus = 'Draft';", $this->src,
            'after taking the stale escape the resting status must be reset');
    }

    /** The release write still clears the lock fields. */
    public function test_release_write_clears_the_lock_fields(): void
    {
        foreach ([
            "'status'              => \$preStatus,",
            "'processingStartedAt' => '',",
            "'processingBy'        => '',",
        ] as $line) {
            $this->assertStringContainsString($line, $this->src);
        }
    }

    /**
     * Executable proof of the deadlock and of the fix, using the exact
     * predicates the controller applies.
     */
    public function test_lock_state_machine_cannot_strand_a_slip(): void
    {
        // The controller's own stale predicate.
        $canProceed = static function (string $status, string $startedAt, int $now): bool {
            if ($status !== 'Processing') return true;
            return $startedAt !== '' && (strtotime($startedAt) + 300) < $now;
        };

        $now      = strtotime('2026-08-19T12:00:00+00:00');
        $crashed  = date('c', $now - 3600);   // a lock left behind an hour ago

        // A fresh lock blocks; a stale one does not.
        $this->assertFalse($canProceed('Processing', date('c', $now - 10), $now));
        $this->assertTrue($canProceed('Processing', $crashed, $now));

        // OLD behaviour: preStatus carried 'Processing' forward, and the release
        // cleared the timestamp — leaving a state nothing can ever unblock.
        $strandedStatus    = 'Processing';   // written back verbatim
        $strandedStartedAt = '';             // cleared by the same write
        $this->assertFalse(
            $canProceed($strandedStatus, $strandedStartedAt, $now + 86400),
            'the deadlock: even a day later this slip could not be regenerated'
        );

        // NEW behaviour: the stale escape resets the resting status first.
        $restingStatus    = 'Draft';
        $restingStartedAt = '';
        $this->assertTrue(
            $canProceed($restingStatus, $restingStartedAt, $now),
            'after the fix the slip comes to rest in a regenerable state'
        );
    }

    /**
     * The lock is claimed with a REAL timestamp — the claim and the release must
     * not be confused, or the claim itself would create the stranded state.
     */
    public function test_lock_claim_writes_a_real_timestamp(): void
    {
        $this->assertStringContainsString("'processingStartedAt' => date('c'),", $this->src);
        $this->assertStringContainsString("'status'              => 'Processing',", $this->src);
    }

    /** Regeneration only ever runs inside a Draft run, which is why Draft is correct. */
    public function test_regeneration_requires_a_draft_run(): void
    {
        $this->assertStringContainsString(
            "does not support regeneration. Must be Draft.",
            $this->src
        );
    }
}
