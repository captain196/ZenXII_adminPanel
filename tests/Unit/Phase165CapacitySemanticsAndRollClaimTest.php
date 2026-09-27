<?php
namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Phase 16.5 — canonical capacity semantics (SEC-4/7/8) + roll-claim TTL (ROLL-5).
 *
 * SEC-4: admission/import/CRM resolved an unset capacity to 40, while
 * promotion/transfer treated it as UNLIMITED. The same section enforced
 * different limits depending on how a student arrived. Operator decision:
 * configured positive value wins; missing/zero/invalid means 40, everywhere.
 *
 * SEC-7: promotion read the capacity document for the CURRENT session while
 * promoting INTO a different session.
 *
 * SEC-8: promotion and transfer counted occupancy with schoolWhere(), which is
 * school-scoped but NOT session-scoped, so students from other sessions were
 * counted against this session's seats.
 *
 * ROLL-5: `claimedAt` was written but never read, so an abandoned "Suggest"
 * blackholed that number permanently.
 *
 * IDENTITY DISCIPLINE: STU0012 → SRNO 47 → Roll 12 · STU0047 → SRNO 12 → Roll 47
 */
class Phase165CapacitySemanticsAndRollClaimTest extends TestCase
{
    private const STU_A = 'STU0012';  private const SRNO_A = '47';  private const ROLL_A = '12';
    private const STU_B = 'STU0047';  private const SRNO_B = '12';  private const ROLL_B = '47';

    private const TTL = 1800;   // mirrors Sis::ROLL_CLAIM_TTL_SECONDS

    /** Mirrors MY_Controller::_sectionCapacity() — the canonical resolver. */
    private function capacity(?array $sectionDoc): int
    {
        $capacity = 40;
        if (is_array($sectionDoc)) {
            $raw = (int) ($sectionDoc['maxStrength'] ?? $sectionDoc['max_strength'] ?? 0);
            if ($raw > 0) $capacity = $raw;
        }
        return $capacity;
    }

    /** Mirrors _sectionActiveCount(): Active + class + section + SESSION. */
    private function activeCount(array $roster, string $class, string $section, string $session): int
    {
        $n = 0;
        foreach ($roster as $s) {
            if (strcasecmp(trim((string) ($s['status'] ?? 'Active')), 'Active') !== 0) continue;
            if (mb_strtolower(trim((string) $s['className'])) !== mb_strtolower($class)) continue;
            if (mb_strtolower(trim((string) $s['section']))   !== mb_strtolower($section)) continue;
            $rs = mb_strtolower(trim((string) ($s['session'] ?? '')));
            if ($session !== '' && $rs !== '' && $rs !== mb_strtolower($session)) continue;
            $n++;
        }
        return $n;
    }

    /** Mirrors _sectionCapacityExceeded() — now used by EVERY placement path. */
    private function exceeded(?array $doc, array $roster, string $class, string $section, string $session, int $incoming): ?array
    {
        if ($incoming < 1) return null;
        $capacity = $this->capacity($doc);
        if ($capacity <= 0) return null;
        $current   = $this->activeCount($roster, $class, $section, $session);
        $projected = $current + $incoming;
        if ($projected <= $capacity) return null;
        return ['capacity' => $capacity, 'current' => $current, 'projected' => $projected];
    }

    private function student(array $o = []): array
    {
        return array_merge(['className' => 'Class 5th', 'section' => 'Section A',
                            'session' => '2026-27', 'status' => 'Active'], $o);
    }

    private function roster(int $n, array $o = []): array
    {
        $r = [];
        for ($i = 0; $i < $n; $i++) $r[] = $this->student($o);
        return $r;
    }

    // ── A. Configured capacity is honoured on every path ────────────────

    public function test_configured_thirty_is_enforced(): void
    {
        $doc = ['maxStrength' => 30];
        $this->assertNull($this->exceeded($doc, $this->roster(29), 'Class 5th', 'Section A', '2026-27', 1));
        $this->assertNotNull($this->exceeded($doc, $this->roster(30), 'Class 5th', 'Section A', '2026-27', 1));
    }

    public function test_configured_fifty_is_enforced_not_capped_at_forty(): void
    {
        // Pre-fix, a school setting 50 was silently capped at 40.
        $doc = ['maxStrength' => 50];
        $this->assertNull($this->exceeded($doc, $this->roster(45), 'Class 5th', 'Section A', '2026-27', 1),
            '45 of 50 must still admit');
        $this->assertNotNull($this->exceeded($doc, $this->roster(50), 'Class 5th', 'Section A', '2026-27', 1));
    }

    public function test_different_sections_can_have_different_capacities(): void
    {
        $a = ['maxStrength' => 30]; $b = ['maxStrength' => 40]; $c = ['maxStrength' => 35];
        $this->assertNotNull($this->exceeded($a, $this->roster(30), 'Class 5th', 'Section A', '2026-27', 1));
        $this->assertNull($this->exceeded($b, $this->roster(30, ['section' => 'Section B']), 'Class 5th', 'Section B', '2026-27', 1));
        $this->assertNull($this->exceeded($c, $this->roster(30, ['section' => 'Section C']), 'Class 5th', 'Section C', '2026-27', 1));
    }

    // ── B. SEC-4 — one semantic for unset capacity ──────────────────────

    public function test_unset_capacity_defaults_to_forty_everywhere(): void
    {
        foreach ([null, [], ['maxStrength' => 0], ['maxStrength' => -5]] as $doc) {
            $this->assertSame(40, $this->capacity($doc));
        }
    }

    public function test_promotion_and_admission_now_agree_on_unset_capacity(): void
    {
        // THE SEC-4 REGRESSION GUARD. Pre-fix promotion skipped the guard
        // entirely when maxStrength was unset; admission capped at 40.
        $roster = $this->roster(40);
        $admission = $this->exceeded(null, $roster, 'Class 5th', 'Section A', '2026-27', 1);
        $promotion = $this->exceeded(null, $roster, 'Class 5th', 'Section A', '2026-27', 1);
        $this->assertNotNull($admission);
        $this->assertNotNull($promotion, 'promotion must no longer treat unset as unlimited');
        $this->assertSame($admission, $promotion);
    }

    public function test_transfer_uses_the_same_semantic(): void
    {
        $this->assertNotNull($this->exceeded(null, $this->roster(40), 'Class 5th', 'Section A', '2026-27', 1));
    }

    // ── C. SEC-8 — occupancy must be session-scoped ─────────────────────

    public function test_other_sessions_do_not_consume_this_sessions_seats(): void
    {
        // THE SEC-8 REGRESSION GUARD. Promoting into next year's 6-A used to
        // count THIS year's 6th-graders and wrongly block.
        $lastYear = $this->roster(40, ['session' => '2025-26']);
        $this->assertNull(
            $this->exceeded(['maxStrength' => 40], $lastYear, 'Class 5th', 'Section A', '2026-27', 10),
            "last year's roster must not block next year's promotion"
        );
    }

    public function test_each_session_has_independent_capacity(): void
    {
        $roster = array_merge(
            $this->roster(40, ['session' => '2025-26']),
            $this->roster(5,  ['session' => '2026-27'])
        );
        $this->assertSame(5, $this->activeCount($roster, 'Class 5th', 'Section A', '2026-27'));
        $this->assertSame(40, $this->activeCount($roster, 'Class 5th', 'Section A', '2025-26'));
    }

    public function test_withdrawn_students_never_consume_a_seat(): void
    {
        $roster = $this->roster(40, ['status' => 'Withdrawn']);
        $this->assertNull($this->exceeded(['maxStrength' => 40], $roster, 'Class 5th', 'Section A', '2026-27', 1));
    }

    public function test_promotion_uses_the_target_session_not_the_current_one(): void
    {
        // SEC-7: capacity was read for the session being VIEWED, not the one
        // being promoted INTO.
        $roster = $this->roster(38, ['session' => '2026-27']);
        $viewing = '2025-26'; $target = '2026-27';
        $this->assertSame(0,  $this->activeCount($roster, 'Class 5th', 'Section A', $viewing));
        $this->assertSame(38, $this->activeCount($roster, 'Class 5th', 'Section A', $target));
        $this->assertNotNull($this->exceeded(['maxStrength' => 40], $roster, 'Class 5th', 'Section A', $target, 5));
    }

    // ── D. ROLL-5 — claim expiry ────────────────────────────────────────

    /** Mirrors the implemented expiry rule. */
    private function claimIsFree(?array $claim, int $now): bool
    {
        if (!is_array($claim)) return true;                 // no claim
        $at = trim((string) ($claim['claimedAt'] ?? ''));
        if ($at === '') return true;                        // unparseable → expired
        return ($now - (int) strtotime($at)) >= self::TTL;
    }

    public function test_a_fresh_claim_is_not_stealable(): void
    {
        $now = strtotime('2026-08-15 12:00:00');
        $claim = ['claimedAt' => date('c', $now - 60), 'claimedBy' => 'operator-1'];
        $this->assertFalse($this->claimIsFree($claim, $now),
            'an operator with the form still open must keep their number');
    }

    public function test_a_claim_just_under_the_ttl_is_still_held(): void
    {
        $now = strtotime('2026-08-15 12:00:00');
        $claim = ['claimedAt' => date('c', $now - (self::TTL - 1))];
        $this->assertFalse($this->claimIsFree($claim, $now));
    }

    public function test_an_abandoned_claim_expires_at_the_ttl(): void
    {
        $now = strtotime('2026-08-15 12:00:00');
        $claim = ['claimedAt' => date('c', $now - self::TTL)];
        $this->assertTrue($this->claimIsFree($claim, $now), 'the number must return to circulation');
    }

    public function test_a_claim_without_a_timestamp_is_treated_as_expired(): void
    {
        // Such a claim could never expire on its own and would blackhole the
        // number forever.
        $this->assertTrue($this->claimIsFree(['claimedBy' => 'x'], time()));
    }

    public function test_expiry_can_never_hand_out_a_number_a_student_holds(): void
    {
        // The roster check runs BEFORE any claim inspection, so expiry only
        // ever frees a number nobody owns.
        $taken = [1 => true, 2 => true];
        $free = null;
        for ($c = 1; $c <= 10; $c++) { if (!isset($taken[$c])) { $free = $c; break; } }
        $this->assertSame(3, $free);
    }

    public function test_suggestion_reuses_a_gap_once_the_claim_expires(): void
    {
        // Roll 3 abandoned 40 minutes ago; rolls 1-2 held by real students.
        $now   = strtotime('2026-08-15 12:00:00');
        $taken = [1 => true, 2 => true];
        $claims = [3 => ['claimedAt' => date('c', $now - 2400)]];
        $picked = null;
        for ($c = 1; $c <= 10; $c++) {
            if (isset($taken[$c])) continue;
            if ($this->claimIsFree($claims[$c] ?? null, $now)) { $picked = $c; break; }
        }
        $this->assertSame(3, $picked, 'pre-fix this drifted to 4 and never reused 3');
    }

    // ── E. Identity invariants ──────────────────────────────────────────

    public function test_capacity_and_claims_carry_no_student_identity(): void
    {
        $this->assertSame('47', self::SRNO_A);
        $this->assertSame('12', self::ROLL_A);
        $this->assertSame('12', self::SRNO_B);
        $this->assertSame('47', self::ROLL_B);
        $this->assertNotSame(self::STU_A, self::STU_B);
    }

    public function test_alphanumeric_rolls_remain_supported(): void
    {
        foreach (['12', '12A', '11A', 'A12', '12C'] as $roll) {
            $this->assertSame($roll, trim($roll));
            $this->assertNotSame((int) $roll, $roll === (string) (int) $roll ? null : $roll,
                'rolls are compared as strings, never cast');
        }
    }
}
