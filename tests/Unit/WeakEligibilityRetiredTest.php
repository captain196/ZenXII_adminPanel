<?php
/**
 * WEAK-ELIG-1 — names and phone numbers no longer decide money.
 *
 * WHAT WAS THERE. `Fee_management::fetch_eligible_students()` built the bulk
 * discount list from:
 *   sibling    — FATHER-NAME STRING EQUALITY. Two unrelated families whose
 *                fathers are both "Ramesh Kumar" became siblings; real siblings
 *                whose records spell the father differently did not.
 *   staff_ward — a parent phone matching ANY staff phone. A recycled number, a
 *                shared handset, or an employee who is a neighbour qualified a
 *                child.
 *
 * WHY IT MATTERED. That list is applied as a bulk discount into
 * `studentDiscounts`, which is a real financial authority at the COLLECTION
 * layer: `Fees::fetch_months` computes `netDue = max(0, totalRemaining -
 * discAmt)` from `onDemandDiscount`, and FeeCollectionService uses the same
 * field as the authorised cap on what a clerk may discount on a receipt. So a
 * coincidence of names or digits reduced what a family actually paid.
 *
 * PROVEN AT RUNTIME after the change, through the real endpoint:
 *   SCH_AUTHTEST1 (no verified relationships)  -> "students":[]
 *   SCH_FAREAUDIT1 (verified siblings on file) -> STU0007, STU0016, STU0017
 * Eligibility now comes only from `studentRelationships`, which is written when
 * a human resolves an SR Number or Staff ID against THIS tenant.
 */

use PHPUnit\Framework\TestCase;

final class WeakEligibilityRetiredTest extends TestCase
{
    private const FM = __DIR__ . '/../../application/controllers/Fee_management.php';

    private function src(): string { return (string) file_get_contents(self::FM); }

    // ══ THE HEURISTICS ARE GONE ═════════════════════════════════════════════

    /** Father-name grouping must not come back in any form. */
    public function test_father_name_can_no_longer_group_siblings(): void
    {
        $s = $this->src();

        foreach (['$fatherMap', "\$d['fatherName']", 'Father_name'] as $gone) {
            $this->assertStringNotContainsString($gone, $s,
                "father-name matching is back. Two families sharing a common name would be "
                . 'treated as siblings and discounted at the counter.');
        }
    }

    /** Phone matching must not come back either. */
    public function test_a_phone_number_can_no_longer_make_a_staff_ward(): void
    {
        $s = $this->src();

        foreach (['$staffPhones', 'Father_phone', 'Mother_phone'] as $gone) {
            $this->assertStringNotContainsString($gone, $s,
                'phone matching is back. A recycled or shared number would qualify a child '
                . 'for an employee benefit.');
        }
    }

    // ══ VERIFIED EVIDENCE IS THE ONLY EVIDENCE ══════════════════════════════

    public function test_eligibility_now_comes_from_verified_relationships(): void
    {
        $s  = $this->src();
        $at = strpos($s, 'WEAK-ELIG-1');
        $this->assertNotFalse($at, 'the replacement, and the reason for it, must stay documented');

        $body = substr($s, $at, 7000);   // past the explanatory block — a 3000-char window stopped short of the guard
        $this->assertStringContainsString("firestoreQuery('studentRelationships'", $body,
            'the canonical verified relationship model is the only acceptable evidence');
        $this->assertStringContainsString("['status',   '==', 'active']", $body,
            'a withdrawn relationship must not keep qualifying anyone');
        $this->assertStringContainsString("['schoolId', '==', \$schoolFs]", $body,
            'one school must never qualify another school\'s students');
    }

    /**
     * FAILS CLOSED. If the relationships cannot be read, the eligible list is
     * EMPTY — an empty list discounts nobody, whereas a fallback guess
     * discounts the wrong families. Silence must never mean "use the old way".
     */
    public function test_an_unreadable_relationship_store_qualifies_nobody(): void
    {
        $s  = $this->src();
        $at = strpos($s, 'WEAK-ELIG-1');
        $body = substr($s, $at, 7000);   // past the explanatory block — a 3000-char window stopped short of the guard

        $this->assertStringContainsString('$relOk = false;', $body);
        $this->assertMatchesRegularExpression('/if \(!\$relOk\) \{\s*\$students = \[\];/', $body,
            'a read failure must produce an EMPTY eligible list, never a fallback heuristic');
    }

    /** An empty result is explained, so nobody assumes the feature is broken. */
    public function test_an_empty_result_tells_the_operator_what_to_do(): void
    {
        $s = $this->src();
        $this->assertStringContainsString('no VERIFIED relationships on record', $s,
            'a bulk screen that silently returns nobody looks broken. It must say that links '
            . 'are created from the student record, by SR Number or Staff ID.');
    }

    /**
     * The manual criteria are deliberately untouched: none of them infers a
     * family relationship, so an operator choosing students by hand is exactly
     * what they are for.
     */
    public function test_the_manual_criteria_are_left_alone(): void
    {
        $s = $this->src();
        $this->assertStringContainsString("'early_bird', 'merit', 'staff_ward', 'custom'", $s,
            'early_bird, merit and custom must remain available — they make no eligibility '
            . 'claim the system could get wrong on its own');
    }
}
