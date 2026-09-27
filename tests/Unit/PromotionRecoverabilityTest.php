<?php

use PHPUnit\Framework\TestCase;

if (!defined('APPPATH')) define('APPPATH', __DIR__ . '/../../application/');
if (!defined('BASEPATH')) define('BASEPATH', 'x');

/**
 * ══════════════════════════════════════════════════════════════════════════
 *  AN INTERRUPTED PROMOTION MUST BE DISCOVERABLE
 * ══════════════════════════════════════════════════════════════════════════
 *
 * ── LC-5 · THE DEFECT ────────────────────────────────────────────────────
 * Promotion moves students SYNCHRONOUSLY, then defers fee regeneration to a
 * post-response shutdown handler (BUG-045, to keep a 60-student batch off the
 * 120s request ceiling). The deferral is sound. What was missing was any way
 * to find out that it did not finish.
 *
 * If that handler dies mid-loop — fatal, OOM, timeout, worker recycle — the
 * students are already in their new class while some have no demands in the
 * destination session. The code names this state itself: "Active-with-zero-
 * demands". Financially it is the same shape as LC-1: a student silently
 * owing nothing.
 *
 * Two things made it undiscoverable:
 *
 *   1. The job recorded only expectedCount — a NUMBER. Nothing said WHICH
 *      students were in the batch, so recovery could not be targeted.
 *   2. processedCount was written ONLY at the end. A handler killed at
 *      student 59 of 60 left the job claiming 0 — not stale, but false, and
 *      false in the direction that hides work already done.
 *
 * There is still no auto-resume, and that is deliberate: re-running fee
 * regeneration blindly over students who already have demands is how
 * duplicates are made. What this closes is the DETECTION gap — recovery is a
 * decision, and a decision needs facts.
 *
 * ── SCOPE ────────────────────────────────────────────────────────────────
 * The handler is a closure inside a CI controller and cannot be executed
 * here. The defect class is "the job document does not carry enough to
 * reconstruct what happened", which is a property of the write, so the write
 * is asserted — comment-stripped, with a control. The detector that consumes
 * these fields is separately verified by
 * `node scripts/promotion_recovery_audit_readonly.js --selftest` (8/8).
 */
final class PromotionRecoverabilityTest extends TestCase
{
    private static string $src = '';

    public static function setUpBeforeClass(): void
    {
        $out = '';
        foreach (token_get_all((string) file_get_contents(APPPATH . 'controllers/Sis.php')) as $t) {
            if (is_array($t)) {
                if (in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true)) continue;
                $out .= $t[1];
            } else { $out .= $t; }
        }
        self::$src = $out;
    }

    public function test_the_comment_stripper_actually_strips(): void
    {
        // Control: this fix's own comments name every field asserted below.
        $this->assertStringNotContainsString('Active-with-zero-demands', self::$src);
        $this->assertStringNotContainsString('LC-5', self::$src);
    }

    public function test_the_job_records_which_students_were_promoted(): void
    {
        // A count cannot be reconciled. An id list can.
        $this->assertStringContainsString("'promotedStudentIds'", self::$src,
            'the promotion job records only a count — an interrupted batch cannot be '
            . 'reconciled against the students it was supposed to process');
    }

    public function test_the_recorded_ids_come_from_the_actually_moved_students(): void
    {
        // $promoted is built from $moveResult['moved'] — students whose write
        // SUCCEEDED. Recording the requested set instead would report demands
        // missing for students who were never moved at all.
        $this->assertMatchesRegularExpression(
            "/'promotedStudentIds'\s*=>\s*array_values\(array_map\(.*?\\\$promoted\s*\)\)/s",
            self::$src,
            'the id list is not derived from $promoted (the students actually moved)'
        );
    }

    public function test_progress_is_checkpointed_during_the_loop(): void
    {
        // Not only at the end. A terminal-only write is false after a kill.
        $this->assertStringContainsString("'checkpointAt'", self::$src,
            'no mid-loop checkpoint — a killed handler still reports processedCount as 0');
    }

    public function test_the_checkpoint_is_inside_the_deferred_loop(): void
    {
        // A checkpoint after the loop is just the final write again.
        $loopAt  = strpos(self::$src, 'foreach ($__promotedLocal as $p)');
        $this->assertNotFalse($loopAt, 'the deferred promotion loop moved');
        $cpAt    = strpos(self::$src, "'checkpointAt'");
        $this->assertNotFalse($cpAt);
        $finalAt = strpos(self::$src, "'completedAt'");
        $this->assertNotFalse($finalAt, 'the final status write moved');

        $this->assertGreaterThan($loopAt, $cpAt, 'the checkpoint is before the loop');
        $this->assertLessThan($finalAt, $cpAt, 'the checkpoint is not inside the loop — '
            . 'it sits at or after the final write, which defeats the point');
    }

    public function test_the_checkpoint_carries_the_last_student_processed(): void
    {
        // Position, not just a count: it says where to look, not merely how far.
        $this->assertStringContainsString("'lastProcessedStudentId'", self::$src);
    }

    public function test_a_failing_checkpoint_never_aborts_the_regeneration(): void
    {
        // Observability must not be able to destroy the thing it observes.
        $at = strpos(self::$src, "'checkpointAt'");
        $this->assertNotFalse($at);
        $window = substr(self::$src, max(0, $at - 400), 900);
        $this->assertStringContainsString('try', $window);
        $this->assertStringContainsString('Throwable', $window,
            'catching \Exception misses an Error from a missing collaborator (FEE-W1)');
    }

    public function test_the_checkpoint_is_bounded_not_per_student(): void
    {
        // One write per student would put ~60 extra round-trips back on the
        // path BUG-045 deferred work off. Bounded cadence, deliberately.
        $this->assertMatchesRegularExpression(
            '/\$processedCount\s*%\s*\d+\s*\)\s*===\s*0/', self::$src,
            'the checkpoint runs per student — that reintroduces the cost the '
            . 'deferral exists to avoid'
        );
    }

    public function test_the_detector_that_consumes_these_fields_exists(): void
    {
        // Recording facts nobody reads is not detection.
        $script = __DIR__ . '/../../scripts/promotion_recovery_audit_readonly.js';
        $this->assertFileExists($script);
        $src = (string) file_get_contents($script);
        foreach (['promotedStudentIds', 'deferred-fees-pending', 'feeDemands'] as $needed) {
            $this->assertStringContainsString($needed, $src,
                "the recovery audit does not consume {$needed}");
        }
        $this->assertStringContainsString('--selftest', $src,
            'the detector has no way to prove it fires — there are no promote_jobs '
            . 'documents in the project, so a live run proves only that it runs');
    }
}
