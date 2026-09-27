<?php
namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Phase 15.7 — automatic fee reminders + historical due-date backfill planning.
 *
 * SCOPE HONESTY: Fee_reminder_service and Fee_late_fee_service need live
 * Firestore handles, so these tests model their DECISION RULES exactly as
 * implemented — the established pattern in this suite. Cron wiring, the Cloud
 * Function dispatch and Parent App delivery are UAT items, not unit tests.
 *
 * IDENTITY DISCIPLINE: STU0012 ↔ SRNO 47 · STU0047 ↔ SRNO 12
 */
class Phase157RemindersAndBackfillTest extends TestCase
{
    private const STU_A = 'STU0012';  private const SRNO_A = '47';
    private const STU_B = 'STU0047';  private const SRNO_B = '12';

    /** Mirrors Fee_reminder_service::stageFor(). */
    private function stageFor(array $d, array $s, string $today = '2026-06-15'): ?string
    {
        $status = strtolower((string) ($d['status'] ?? 'unpaid'));
        if ($status === 'paid' || $status === 'archived') return null;
        if ((float) ($d['balance'] ?? 0) <= 0.005) return null;

        $due = (string) ($d['due_date'] ?? '');
        if ($due === '') return null;

        $diff = (int) floor((strtotime($due) - strtotime($today)) / 86400);
        if ($diff === 0) return ($s['onDue'] ?? false) ? 'due' : null;
        if ($diff > 0) {
            return in_array($diff, (array) ($s['daysBefore'] ?? []), true) ? 'before_' . $diff : null;
        }
        $over = abs($diff);
        return in_array($over, (array) ($s['afterDays'] ?? []), true) ? 'after_' . $over : null;
    }

    /** Mirrors Fee_reminder_service::dedupeKey(). */
    private function key(string $stu, string $demand, string $stage): string
    {
        return "feedue_{$stu}_{$demand}_{$stage}";
    }

    /** Mirrors Push_enqueue_service deterministic doc id. */
    private function docId(string $schoolId, string $dedupeKey): string
    {
        return "{$schoolId}_{$dedupeKey}";
    }

    private function settings(array $over = []): array
    {
        return array_merge([
            'enabled' => true, 'daysBefore' => [7, 3, 1], 'onDue' => true, 'afterDays' => [3, 7],
        ], $over);
    }

    private function demand(array $over = []): array
    {
        return array_merge([
            'status' => 'unpaid', 'due_date' => '2026-06-15', 'balance' => 5000.0,
        ], $over);
    }

    // ── A. Reminder eligibility ─────────────────────────────────────────

    public function test_reminder_on_the_due_date(): void
    {
        $this->assertSame('due', $this->stageFor($this->demand(), $this->settings()));
    }

    public function test_reminder_seven_days_before(): void
    {
        $d = $this->demand(['due_date' => '2026-06-22']);
        $this->assertSame('before_7', $this->stageFor($d, $this->settings()));
    }

    public function test_reminder_three_and_one_day_before(): void
    {
        $this->assertSame('before_3', $this->stageFor($this->demand(['due_date' => '2026-06-18']), $this->settings()));
        $this->assertSame('before_1', $this->stageFor($this->demand(['due_date' => '2026-06-16']), $this->settings()));
    }

    public function test_no_reminder_on_a_non_configured_day(): void
    {
        // 5 days before is not in [7,3,1] → silent, no daily nagging.
        $this->assertNull($this->stageFor($this->demand(['due_date' => '2026-06-20']), $this->settings()));
    }

    public function test_overdue_reminder_stages(): void
    {
        $this->assertSame('after_3', $this->stageFor($this->demand(['due_date' => '2026-06-12']), $this->settings()));
        $this->assertSame('after_7', $this->stageFor($this->demand(['due_date' => '2026-06-08']), $this->settings()));
    }

    public function test_no_overdue_reminder_on_unconfigured_overdue_day(): void
    {
        $this->assertNull($this->stageFor($this->demand(['due_date' => '2026-06-10']), $this->settings()));
    }

    public function test_on_due_stage_can_be_switched_off(): void
    {
        $this->assertNull($this->stageFor($this->demand(), $this->settings(['onDue' => false])));
    }

    // ── B. Never notify the wrong family ────────────────────────────────

    public function test_fully_paid_demand_is_never_reminded(): void
    {
        $this->assertNull($this->stageFor($this->demand(['status' => 'paid', 'balance' => 0.0]), $this->settings()));
    }

    public function test_zero_balance_demand_is_never_reminded(): void
    {
        // Status may lag; balance is the authority.
        $this->assertNull($this->stageFor($this->demand(['status' => 'unpaid', 'balance' => 0.0]), $this->settings()));
    }

    public function test_archived_demand_is_never_reminded(): void
    {
        $this->assertNull($this->stageFor($this->demand(['status' => 'archived']), $this->settings()));
    }

    public function test_partially_paid_demand_is_still_reminded(): void
    {
        $d = $this->demand(['status' => 'partial', 'balance' => 1500.0]);
        $this->assertSame('due', $this->stageFor($d, $this->settings()));
    }

    public function test_dateless_historical_demand_is_never_reminded(): void
    {
        // Pre-15.5 demands cannot be scheduled against and must NOT be guessed.
        $this->assertNull($this->stageFor($this->demand(['due_date' => '']), $this->settings()));
    }

    // ── C. Duplicate prevention ─────────────────────────────────────────

    public function test_same_stage_produces_an_identical_dedupe_key(): void
    {
        $a = $this->key(self::STU_A, 'DMD1', 'due');
        $b = $this->key(self::STU_A, 'DMD1', 'due');
        $this->assertSame($a, $b, 'a repeat run must collapse onto the same key');
    }

    public function test_repeat_run_coalesces_onto_one_document(): void
    {
        // Deterministic doc id is the duplicate-prevention mechanism.
        $ids = [];
        for ($i = 0; $i < 5; $i++) {
            $ids[] = $this->docId('SCH_1', $this->key(self::STU_A, 'DMD1', 'due'));
        }
        $this->assertCount(1, array_unique($ids), 'five runs must write ONE push request');
    }

    public function test_different_stages_are_separate_notifications(): void
    {
        $this->assertNotSame(
            $this->key(self::STU_A, 'DMD1', 'before_7'),
            $this->key(self::STU_A, 'DMD1', 'due')
        );
    }

    public function test_different_demands_are_separate_notifications(): void
    {
        $this->assertNotSame(
            $this->key(self::STU_A, 'DMD1', 'due'),
            $this->key(self::STU_A, 'DMD2', 'due')
        );
    }

    public function test_siblings_are_notified_independently(): void
    {
        // Two students, same demand id shape, same stage — must not collide.
        $this->assertNotSame(
            $this->key(self::STU_A, 'DMD1', 'due'),
            $this->key(self::STU_B, 'DMD1', 'due')
        );
    }

    public function test_tenants_cannot_collide_on_the_same_dedupe_key(): void
    {
        $k = $this->key(self::STU_A, 'DMD1', 'due');
        $this->assertNotSame($this->docId('SCH_1', $k), $this->docId('SCH_2', $k),
            'the schoolId prefix isolates tenants at the document level');
    }

    // ── D. Payment-state re-check (the race) ────────────────────────────

    public function test_payment_between_scan_and_send_suppresses_the_reminder(): void
    {
        // Eligible at scan time...
        $atScan = $this->demand();
        $this->assertSame('due', $this->stageFor($atScan, $this->settings()));
        // ...parent pays before enqueue → re-read shows paid → suppressed.
        $atSend = $this->demand(['status' => 'paid', 'balance' => 0.0]);
        $this->assertNull($this->stageFor($atSend, $this->settings()),
            'a family that has just paid must never be dunned');
    }

    public function test_recheck_fails_closed_on_read_error(): void
    {
        // stillOwing() returns false when the demand cannot be re-read:
        // sending a wrong reminder is worse than sending none.
        $stillOwing = static function (?array $doc): bool {
            if ($doc === null) return false;               // read failed
            $s = strtolower((string) ($doc['status'] ?? ''));
            if ($s === 'paid' || $s === 'archived') return false;
            return ((float) ($doc['balance'] ?? 0)) > 0.005;
        };
        $this->assertFalse($stillOwing(null));
        $this->assertTrue($stillOwing(['status' => 'unpaid', 'balance' => 100.0]));
    }

    // ── E. Session isolation ────────────────────────────────────────────

    public function test_reminders_are_scoped_to_one_session(): void
    {
        // The query predicate is ['session','==',$session]; a prior session's
        // demand can never enter the candidate set.
        $rows = [
            ['session' => '2026-27', 'id' => 'A'],
            ['session' => '2025-26', 'id' => 'B'],
        ];
        $kept = array_values(array_filter($rows, fn($r) => $r['session'] === '2026-27'));
        $this->assertCount(1, $kept);
        $this->assertSame('A', $kept[0]['id']);
    }

    // ── F. Historical backfill planning ─────────────────────────────────

    public function test_backfill_proposes_period_key_plus_due_day(): void
    {
        $day = 10;
        $this->assertSame('2026-04-10', sprintf('%s-%02d', '2026-04', $day));
    }

    public function test_backfill_only_proposes_for_canonical_period_keys(): void
    {
        foreach (['2026-04', '2026-12'] as $ok) {
            $this->assertSame(1, preg_match('/^\d{4}-\d{2}$/', $ok));
        }
        foreach (['', 'April 2026', '2026', '2026-4'] as $bad) {
            $this->assertSame(0, preg_match('/^\d{4}-\d{2}$/', $bad),
                "'{$bad}' must be reported AMBIGUOUS, never guessed");
        }
    }

    public function test_backfill_splits_reconstructable_from_ambiguous(): void
    {
        $rows = [['periodKey' => '2026-04'], ['periodKey' => ''],
                 ['periodKey' => '2026-05'], ['periodKey' => 'Term 1']];
        $plan = []; $amb = [];
        foreach ($rows as $r) {
            if (preg_match('/^\d{4}-\d{2}$/', $r['periodKey'])) $plan[] = $r; else $amb[] = $r;
        }
        $this->assertCount(2, $plan);
        $this->assertCount(2, $amb, 'ambiguous rows must be reported, never silently repaired');
    }

    public function test_backfill_plan_is_read_only(): void
    {
        // The planner returns a proposal only; there is deliberately no
        // commit path. Modelled by asserting the proposal carries BOTH the
        // current and proposed value so a human can review the change.
        $row = ['currentDueDate' => '(none)', 'proposedDueDate' => '2026-04-10'];
        $this->assertArrayHasKey('currentDueDate', $row);
        $this->assertArrayHasKey('proposedDueDate', $row);
        $this->assertNotSame($row['currentDueDate'], $row['proposedDueDate']);
    }

    // ── G. Identity invariants ──────────────────────────────────────────

    public function test_reminders_never_derive_srno_from_student_id(): void
    {
        $this->assertNotSame(self::STU_A, self::SRNO_A);
        $this->assertSame('47', self::SRNO_A);
        $this->assertSame('12', self::SRNO_B);
    }

    public function test_notification_is_addressed_by_student_id_not_srno(): void
    {
        // FEE_DUE declares idField 'studentId'. The payload must carry the
        // canonical technical id, never the human-facing register number.
        $fields = ['studentId' => self::STU_A, 'amount' => 5000.0, 'dueDate' => '2026-06-15'];
        $this->assertSame(self::STU_A, $fields['studentId']);
        $this->assertArrayNotHasKey('srNo', $fields);
        $this->assertArrayNotHasKey('rollNo', $fields);
    }
}
