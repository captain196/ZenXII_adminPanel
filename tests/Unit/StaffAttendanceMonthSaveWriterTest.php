<?php
namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Trail 2 (Work Schedule → Calendar → Attendance → Leave/LOP → Payroll),
 * Phase D, Finding #2, 2026-08-18.
 *
 * Confirmed defect: Attendance::_save_staff_attendance_fs() (the whole-
 * month admin grid save) wrote ONLY the staffAttendanceSummary doc via a
 * raw firestoreCommitBatch — no per-day staffAttendance docs, unlike every
 * other write path (punch, mark_staff_day, bulk_mark_staff), which all go
 * through Staff_attendance_writer and produce a per-day audit trail.
 *
 * Fix: a new Staff_attendance_writer::saveMonthForStaff() method — the
 * SAME canonical writer, extended, not a second one — atomically writes
 * per-day docs for days that actually changed plus one CAS-protected
 * summary write, in a single firestoreCommitBatch.
 *
 * Source-regression tests (Firestore_service has no behavioral mock for
 * controller/library code).
 */
class StaffAttendanceMonthSaveWriterTest extends TestCase
{
    private string $writerSrc;
    private string $attendanceSrc;

    protected function setUp(): void
    {
        foreach ([
            'writerSrc'     => __DIR__ . '/../../application/libraries/Staff_attendance_writer.php',
            'attendanceSrc' => __DIR__ . '/../../application/controllers/Attendance.php',
        ] as $prop => $path) {
            $raw = file_get_contents($path);
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
            $this->$prop = $out;
        }
    }

    private function extractFunctionBody(string $src, int $functionKeywordOffset): string
    {
        $openBrace = strpos($src, '{', $functionKeywordOffset);
        $this->assertNotFalse($openBrace);
        $depth = 0;
        $len = strlen($src);
        for ($i = $openBrace; $i < $len; $i++) {
            if ($src[$i] === '{') $depth++;
            elseif ($src[$i] === '}') {
                $depth--;
                if ($depth === 0) return substr($src, $openBrace, $i - $openBrace + 1);
            }
        }
        $this->fail('Unbalanced braces while extracting function body.');
    }

    private function saveMonthForStaffBody(): string
    {
        $start = strpos($this->writerSrc, 'public function saveMonthForStaff(');
        $this->assertNotFalse($start);
        return $this->extractFunctionBody($this->writerSrc, $start);
    }

    private function controllerBody(): string
    {
        $start = strpos($this->attendanceSrc, 'private function _save_staff_attendance_fs()');
        $this->assertNotFalse($start);
        return $this->extractFunctionBody($this->attendanceSrc, $start);
    }

    // ── No second writer ─────────────────────────────────────────────────

    public function test_no_second_writer_class_was_created(): void
    {
        $this->assertEquals(1, substr_count($this->writerSrc, 'class Staff_attendance_writer'));
    }

    public function test_controller_no_longer_calls_firestore_commit_batch_directly(): void
    {
        // The bypass this finding fixes: the controller must delegate to the
        // writer, never build/commit its own Firestore batch for this save.
        $body = $this->controllerBody();
        $this->assertStringNotContainsString('$this->firebase->firestoreCommitBatch(', $body);
        $this->assertStringContainsString('$this->staff_attendance_writer->saveMonthForStaff(', $body);
    }

    public function test_controller_loads_the_canonical_writer_not_a_bespoke_one(): void
    {
        $body = $this->controllerBody();
        $this->assertStringContainsString("\$this->load->library('staff_attendance_writer');", $body);
    }

    // ── Atomicity ─────────────────────────────────────────────────────────

    public function test_summary_and_changed_day_docs_commit_in_one_atomic_batch(): void
    {
        $body = $this->saveMonthForStaffBody();
        // Exactly one firestoreCommitBatch call per attempt — summary op is
        // pushed into the SAME $ops array the per-day docs are appended to,
        // never a second, separate commit.
        $this->assertEquals(1, substr_count($body, '$this->firebase->firestoreCommitBatch($ops)'));
        $this->assertStringContainsString("'collection' => 'staffAttendanceSummary'", $body);
        $this->assertStringContainsString("'collection' => 'staffAttendance',", $body);
    }

    // ── CAS / concurrency preserved ──────────────────────────────────────

    public function test_summary_write_keeps_cas_precondition(): void
    {
        $body = $this->saveMonthForStaffBody();
        $this->assertStringContainsString("'precondition' => \$precondition", $body);
        $this->assertStringContainsString("'updateTime' => \$captured", $body);
    }

    public function test_cas_conflict_retries_with_a_fresh_read_not_the_stale_prefetch(): void
    {
        $body = $this->saveMonthForStaffBody();
        $this->assertStringContainsString('if ($attempt === 0 && $preReadSummary !== null) {', $body);
        $this->assertStringContainsString("\$sum = \$this->firebase->firestoreGet('staffAttendanceSummary', \$summaryDocId);", $body);
    }

    public function test_cas_exhaustion_throws_the_shared_exception_type(): void
    {
        $body = $this->saveMonthForStaffBody();
        $this->assertStringContainsString('throw new CASRetryExhaustedException(', $body);
    }

    public function test_month_lock_is_checked_via_the_shared_lock_cache_contract(): void
    {
        $body = $this->saveMonthForStaffBody();
        $this->assertStringContainsString('$this->lock_cache->is_locked($this->schoolId, $this->sessionYear, $monthKey);', $body);
        $this->assertStringContainsString('throw new MonthLockedException(', $body);
    }

    public function test_controller_preserves_the_locked_response_contract(): void
    {
        $body = $this->controllerBody();
        $this->assertStringContainsString('catch (MonthLockedException $e) {', $body);
        $this->assertStringContainsString('is locked. Unlock before editing.', $body);
    }

    public function test_controller_preserves_the_cas_exhausted_skip_reason(): void
    {
        $body = $this->controllerBody();
        $this->assertStringContainsString('catch (CASRetryExhaustedException $e) {', $body);
        $this->assertStringContainsString("'reason' => 'firestore_cas_exhausted'", $body);
    }

    // ── No duplicate per-day docs / no duplicate audit entries ───────────

    public function test_per_day_doc_written_only_for_days_that_actually_changed(): void
    {
        $body = $this->saveMonthForStaffBody();
        $this->assertStringContainsString('if ($clean[$i] === $oldDayWise[$i]) continue;', $body);
    }

    public function test_changed_day_doc_uses_the_same_composite_id_convention_as_every_other_write_path(): void
    {
        $body = $this->saveMonthForStaffBody();
        $this->assertStringContainsString('$this->_att_doc_id($dateISO, $staffId)', $body);
    }

    // ── Full recompute, not a fragile delta, for a multi-day change ──────

    public function test_counts_are_a_full_recompute_from_the_final_string_not_a_delta(): void
    {
        $body = $this->saveMonthForStaffBody();
        $this->assertStringContainsString("\$field = self::STATUS_TO_FIELD[\$clean[\$i]] ?? 'void';", $body);
        $this->assertStringContainsString('$counts[$field]++;', $body);
        // The single-day methods use a delta; this one must not reuse that helper —
        // a delta is only correct when exactly one day transitions per call.
        $this->assertStringNotContainsString('_compute_delta', $body);
    }

    public function test_unknown_status_chars_are_sanitized_to_vacant_never_written_raw(): void
    {
        $body = $this->saveMonthForStaffBody();
        $this->assertStringContainsString('if (!in_array($clean[$i], self::ALLOWED_STATUS, true)) $clean[$i] = \'V\';', $body);
    }

    public function test_staff_id_is_validated_before_use(): void
    {
        $body = $this->saveMonthForStaffBody();
        $this->assertStringContainsString("preg_match('/^[A-Za-z0-9_\\-]+\$/', \$staffId)", $body);
    }

    // ── Batch size stays well under Firestore's 500-op ceiling ───────────

    public function test_worst_case_batch_size_is_bounded_by_days_in_month_plus_one(): void
    {
        // 31 possible per-day docs + 1 summary doc = 32, far under 500 —
        // documented reasoning, verified structurally (loop bound is $daysIn).
        $body = $this->saveMonthForStaffBody();
        $this->assertStringContainsString('for ($i = 0; $i < $daysIn; $i++) {', $body);
    }
}
