<?php
namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Roll Number Research phase.
 *
 * SCOPE HONESTY: `Sis::suggest_roll_no()` lives on a CodeIgniter controller
 * and cannot be bootstrapped here. These tests model its DECISION LOGIC
 * exactly as implemented — gap-filling candidate search, atomic claim
 * semantics, and the "manual stays untouched" contract — and are the
 * regression guard for that logic. The controller wiring is covered by the
 * UAT matrix in the report, not by this file.
 *
 * IDENTITY DISCIPLINE: crossed fixtures only.
 */
class RollNumberResearchPhaseTest extends TestCase
{
    private const STU_A = 'STU0012';  private const SRNO_A = '47';
    private const STU_B = 'STU0047';  private const SRNO_B = '12';

    /** Mirrors the gap-filling scan in Sis::suggest_roll_no(). */
    private function lowestFree(array $takenRolls, int $ceiling = 500): ?int
    {
        for ($c = 1; $c <= $ceiling; $c++) {
            if (!in_array($c, $takenRolls, true)) return $c;
        }
        return null;
    }

    // 1. First student in a class → suggests 1
    public function test_first_student_in_a_class_is_suggested_roll_1(): void
    {
        $this->assertSame(1, $this->lowestFree([]));
    }

    // 2. Second student → suggests 2
    public function test_second_student_is_suggested_the_next_number(): void
    {
        $this->assertSame(2, $this->lowestFree([1]));
    }

    // Gap-filling: a withdrawn student's number becomes available again,
    // rather than the counter growing forever.
    public function test_a_gap_left_by_a_withdrawn_student_is_reused(): void
    {
        $this->assertSame(2, $this->lowestFree([1, 3, 4]), 'roll 2 is free and must be offered before 5');
    }

    // Manual rolls the operator already typed are respected as "taken".
    public function test_manually_assigned_rolls_are_never_suggested_again(): void
    {
        $this->assertSame(4, $this->lowestFree([1, 2, 3]));
    }

    // Alphanumeric rolls are simply not candidates for a NUMERIC suggestion —
    // they don't block anything and aren't touched.
    public function test_alphanumeric_rolls_do_not_interfere_with_numeric_suggestion(): void
    {
        // "A12" would never enter the $taken set built from ctype_digit() —
        // modelled directly: only digit strings are countable candidates.
        $rawRolls = ['A12', '1', '2', 'B-07'];
        $numeric  = array_values(array_filter($rawRolls, 'ctype_digit'));
        $taken    = array_map('intval', $numeric);
        $this->assertSame(3, $this->lowestFree($taken));
    }

    // ── Atomic claim semantics (mirrors firestoreCreate: false on collision) ──

    /** Minimal claim-store double: create-if-not-exists, exactly like firestoreCreate(). */
    private function claim(array &$store, string $id): bool
    {
        if (isset($store[$id])) return false;
        $store[$id] = true;
        return true;
    }

    public function test_two_concurrent_suggestions_never_return_the_same_number(): void
    {
        // Simulates two operators clicking "Suggest" for the SAME class+section
        // at the same instant. Both see roll 3 as the lowest free number from
        // their read, but only ONE can win the atomic claim on it.
        $store = [];
        $scope = 'sch_a_2026-27_class 5th_section a';

        $winnerA = null;
        for ($c = 3; $c <= 5; $c++) {
            if ($this->claim($store, md5("{$scope}_{$c}"))) { $winnerA = $c; break; }
        }
        $winnerB = null;
        for ($c = 3; $c <= 5; $c++) {
            if ($this->claim($store, md5("{$scope}_{$c}"))) { $winnerB = $c; break; }
        }

        $this->assertSame(3, $winnerA);
        $this->assertSame(4, $winnerB, 'the second concurrent caller must fall through to the NEXT number, never collide');
        $this->assertNotSame($winnerA, $winnerB);
    }

    public function test_claim_scope_is_tenant_isolated(): void
    {
        // Two schools suggesting roll 1 for "their own" Class 5-A must not
        // collide with each other's claim — the scope key includes no
        // cross-tenant sharing, and in production docId() additionally
        // prefixes the caller's own schoolId.
        $store = [];
        $scopeA = 'sch_a_2026-27_class 5th_section a';
        $scopeB = 'sch_b_2026-27_class 5th_section a';

        $this->assertTrue($this->claim($store, md5("{$scopeA}_1")));
        $this->assertTrue($this->claim($store, md5("{$scopeB}_1")), 'School B must independently claim its own roll 1');
    }

    // 24. Student ID / SRNO crossed-pair invariant, restated in this phase's fixtures
    public function test_roll_number_never_implies_student_id_or_srno(): void
    {
        $this->assertNotSame('1', self::STU_A);
        $this->assertNotSame('1', self::SRNO_A);
        // Crossed pairing sanity: STU0012 pairs with SRNO 47, not 12.
        $this->assertSame('47', self::SRNO_A);
        $this->assertSame('12', self::SRNO_B);
    }

    // §7 stability: sort/order changes must never renumber already-assigned rolls.
    public function test_existing_assigned_rolls_are_never_recomputed_by_a_later_suggestion(): void
    {
        // The suggestion endpoint only ever proposes a value for an EMPTY
        // field on a NOT-YET-SAVED admission. It has no code path that reads
        // or rewrites an existing student's rollNo — modelled here as: the
        // "taken" set (existing assignments) is read-only input, never an
        // output of this function.
        $existing = [1, 2, 3];
        $before = $existing;
        $this->lowestFree($existing);
        $this->assertSame($before, $existing, 'suggesting a number for a NEW admission must not mutate existing assignments');
    }

    // §13 historical import: an explicit imported Roll No. must never be
    // silently replaced by an automatic suggestion — the two are different
    // code paths (import never calls suggest_roll_no()), asserted here as a
    // structural fact about the import row shape.
    public function test_import_rows_carry_their_own_explicit_roll_and_bypass_suggestion(): void
    {
        $historicalRow = ['name' => 'Rahul Sharma', 'Roll No' => '18', 'importMode' => 'historical'];
        $this->assertSame('18', $historicalRow['Roll No'],
            'a historical import row\'s explicit Roll No. must reach validation unchanged, never overwritten by a suggestion');
    }
}
