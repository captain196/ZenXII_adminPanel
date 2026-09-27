<?php
/**
 * Verified admission relationships — sibling, staff-ward, RTE/EWS (2026-08-30).
 *
 * WHAT THIS REPLACED. Three admission controls that reported success and
 * produced nothing:
 *   • "A brother or sister already studies at this school" captured a free-text
 *     "name / admission number" that NO code read.
 *   • "A parent or guardian is employed by this school" captured nothing at all.
 *   • "Applying under RTE / EWS" captured a certificate reference that was
 *     stored on the online application and never read, and on the direct path
 *     not stored at all.
 * An admitted student carried no field matching any of them.
 *
 * PROVEN AT RUNTIME before these tests were written: SR 1 resolved to the right
 * student with class and status; SR 999 and a foreign tenant's SR 11 were both
 * refused; an inactive employee was refused; an admission carrying all three
 * declarations produced 2 sibling rows (both directions), 1 staff-ward row, the
 * RTE reference on the student, and ZERO entitlements.
 *
 * THE LINE THESE TESTS DEFEND MOST:
 *      VERIFIED RELATIONSHIP  !=  AUTOMATIC FINANCIAL BENEFIT
 */

use PHPUnit\Framework\TestCase;

final class AdmissionVerifiedRelationshipsTest extends TestCase
{
    private const SVC   = __DIR__ . '/../../application/libraries/Student_relationship_service.php';
    private const SIS   = __DIR__ . '/../../application/controllers/Sis.php';
    private const DECL  = __DIR__ . '/../../application/views/_entitlement_declarations.php';
    private const PUBC  = __DIR__ . '/../../application/controllers/Admission_public.php';
    private const FEELIFECYCLE = __DIR__ . '/../../application/libraries/Fee_lifecycle.php';

    private function src(string $f): string { return (string) file_get_contents($f); }

    // ══ THE FINANCIAL BOUNDARY ══════════════════════════════════════════════

    /**
     * The service must never mint money. ZenX has NO ratified automatic sibling
     * or staff-ward discount policy — and two DISAGREEING sibling definitions
     * already exist (dead `parentDbKey` grouping, and father-name string
     * equality in the bulk concession tool). Turning a relationship into a
     * discount is a pricing decision that has not been taken.
     */
    public function test_recording_a_relationship_never_creates_money(): void
    {
        $s = $this->src(self::SVC);

        // CALL syntax and collection names only. The header deliberately NAMES
        // the dead calculateSiblingDiscount() and the father-name bulk tool, so a
        // bare word search matches this file's own explanation of why they are
        // not used — and would pass for the wrong reason.
        foreach (['->createModuleFee(', '->propose(', '->assignInitialFees(',
                  "'studentEntitlements'", "'feeDemands'", "'studentConcessions'"] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $s,
                "the relationship service reached '{$forbidden}'. A verified relationship is a "
                . 'FACT about people; a discount is a decision about money with a reason, an '
                . 'approver and an allocation. Invariant I2 requires every discount to be backed '
                . 'by an allocation — a benefit granted from a ticked box has none.');
        }

        $this->assertStringContainsString("'financialEffect' => 'none'", $s,
            'every row states on its face that it granted nothing, so a future reader — or a '
            . 'future sibling-discount feature — cannot mistake its existence for an entitlement');
    }

    // ══ VERIFICATION, NOT DECLARATION ═══════════════════════════════════════

    /** SR Number, because a parent cannot recite an admission number. */
    public function test_the_sibling_is_found_by_sr_number_through_the_canonical_resolver(): void
    {
        $sis = $this->src(self::SIS);

        $at = strpos($sis, 'public function lookup_sibling');
        $this->assertNotFalse($at, 'lookup_sibling no longer exists');

        // Scoped. _resolveStudentRef appears throughout this controller, so a
        // whole-file search passed even with the lookup's own call replaced by
        // a stub that trusted the raw input.
        $this->assertStringContainsString('$ref = $this->_resolveStudentRef($srNo);', substr($sis, $at, 1800),
            'sibling lookup must go through the ONE canonical resolver (MY_Controller::'
            . '_resolveStudentRef -> Sr_no_service scoped to $school_id). A second student '
            . 'lookup would be a second source of truth, and a school-unscoped one would let a '
            . 'link cross tenants.');

        $this->assertStringNotContainsString('decl_sibling_name', $sis,
            'the free-text sibling name must not come back — it was unverifiable and unread');
    }

    /**
     * A relationship must be re-resolved SERVER-SIDE at save. The form's ids are
     * display state; trusting them would let a tampered hidden field fabricate a
     * link to another tenant's student.
     */
    public function test_the_saved_link_is_re_resolved_and_never_taken_from_the_form(): void
    {
        $sis = $this->src(self::SIS);
        $at  = strpos($sis, 'private function _captureAdmissionRelationships');
        $this->assertNotFalse($at);
        $body = substr($sis, $at, 4000);

        $this->assertStringContainsString('_resolveStudentRef(', $body,
            'the capture must resolve the SR NUMBER again rather than accept a student id');
        $this->assertStringNotContainsString("post('decl_sibling_id", $body,
            'a client-supplied student id must never be the basis of a verified link');
    }

    /** An employment claim is only meaningful against an ACTIVE employee. */
    public function test_only_an_active_employee_of_this_school_can_be_linked(): void
    {
        $s = $this->src(self::SVC);
        $at = strpos($s, 'public function lookupStaff');
        $this->assertNotFalse($at);
        $body = substr($s, $at, 2200);

        $this->assertStringContainsString("!== 'active'", $body,
            'an employee who has left cannot support an employment claim — proven at runtime: '
            . "STA0002 (Inactive) was refused with its status named");
        // The comparison, not the bare word: with only 'schoolId' asserted, a
        // mutation that replaced the whole tenant check with `if (false)` still
        // passed, because the field name survived elsewhere in the method.
        $this->assertStringContainsString("!== \$this->schoolId", $body,
            'the staff document must be confirmed to belong to THIS tenant');
    }

    // ══ ADVERSARIAL SHAPES ══════════════════════════════════════════════════

    /**
     * Deterministic ids: {studentId}_{kind}_{relatedId}. Duplicate prevention is
     * structural rather than a check that can be raced. Proven at runtime —
     * re-linking the same pair left the row count at 6.
     */
    public function test_relinking_the_same_pair_cannot_create_a_second_row(): void
    {
        $this->assertStringContainsString('"{$studentId}_{$kind}_{$relatedId}"', $this->src(self::SVC),
            'a non-deterministic id would let the same sibling be linked twice, and any future '
            . 'sibling count would then see one child as two');
    }

    /** A student is not their own sibling. Proven: refused at runtime. */
    public function test_a_student_cannot_be_their_own_sibling(): void
    {
        $this->assertStringContainsString('$studentId === $siblingId', $this->src(self::SVC),
            'an operator who types the SR No of the child being admitted would otherwise create '
            . 'a self-loop that every later sibling count reads as a second child');
    }

    /** Symmetric, so a reader needs one equality query rather than an OR. */
    public function test_a_sibling_link_is_written_in_both_directions(): void
    {
        $s  = $this->src(self::SVC);
        $at = strpos($s, 'public function linkSibling');
        $this->assertNotFalse($at);
        // Bounded by the NEXT method: a fixed window ran into linkStaffWard,
        // whose own write() call made the count read 3.
        $end  = strpos($s, "
    /**", $at);
        $body = substr($s, $at, ($end ?: strlen($s)) - $at);

        $this->assertSame(2, substr_count($body, '$this->write('),
            'both directions must be written, or "who are this student\'s siblings?" needs an OR '
            . 'across two fields that Firestore cannot express in one query');
        $this->assertStringContainsString('mirrorOf', $body);
    }

    // == SIB-DEF-1 · ONE DEFINITION OF "SIBLING", NOT THREE ==================

    /**
     * ZenX carried three disagreeing answers to "who is a sibling?":
     *   1. Fee_lifecycle::calculateSiblingDiscount() — parentDbKey grouping. DEAD
     *      (zero callers), and the feeSettings/{school}_sibling_policy document
     *      it read has no writer either, so it would resolve 0% for every school.
     *   2. Fee_management's bulk concession `criteria = 'sibling'` — LIVE — which
     *      groups by FATHER-NAME STRING EQUALITY: it merges unrelated families
     *      sharing a common name and splits real siblings whose records spell the
     *      father differently. It sits at a parked commit boundary and is
     *      REPORTED, not touched, by this workstream.
     *   3. studentRelationships — verified, SR-No resolved, human confirmed.
     *
     * (1) now fails closed. Leaving a dead second definition callable invites
     * someone to wire it up as the "existing" answer. Nothing is deleted: the
     * original body is retained but unreachable, so an unknown caller gets a
     * loud, safe refusal rather than a fatal error.
     */
    public function test_the_dead_parentdbkey_sibling_definition_cannot_price_anything(): void
    {
        $fl = $this->src(self::FEELIFECYCLE);
        $at = strpos($fl, 'public function calculateSiblingDiscount');
        $this->assertNotFalse($at, 'the retired method must still EXIST so callers fail safely');

        $body = substr($fl, $at, 1600);
        $this->assertStringContainsString('SIB-DEF-1', $body,
            'the retirement, and the reason for it, must stay next to the code');
        $this->assertMatchesRegularExpression(
            "/log_message\(.{0,2200}?return \['sibling_count' => 0/s", $body,
            'it must log and return "no discount" BEFORE the old parentDbKey logic — a silent '
            . 'return would let a future caller believe the answer was computed');
    }
    // ══ THE UNAUTHENTICATED FORM MUST NOT BE A DIRECTORY ════════════════════

    /**
     * The public admission form is filled by a parent with no login. Giving it
     * the lookup endpoints would let anyone enumerate a school's students and
     * employees by trying numbers, so the buttons are rendered only when the
     * including surface opts in, and only the staff form does.
     */
    public function test_the_public_form_cannot_enumerate_students_or_staff(): void
    {
        $decl = $this->src(self::DECL);

        $this->assertStringContainsString('decl_can_lookup', $decl,
            'the live lookups must be opt-in, not unconditional');
        // BOTH lookups. Gating only one still hands the unauthenticated form a
        // directory of the other — and a single-match regex passed while the
        // sibling gate had been opened.
        $this->assertSame(2, substr_count($decl, 'if (!empty($decl_can_lookup)):'),
            'the sibling AND staff Find buttons must both sit behind the opt-in');
        // The BUTTON elements. A bare 'entdecl-find' count is 5 — the class also
        // appears twice in CSS and once in the JS selector.
        $this->assertSame(2, substr_count($decl, 'class="entdecl-find" data-find='));

        $pub = $this->src(self::PUBC);
        $this->assertStringNotContainsString('decl_can_lookup', $pub,
            'the unauthenticated form must never enable the lookups');
        $this->assertStringContainsString('siblingSrNo', $pub,
            'it should still CARRY the claim, for staff to verify at enrolment');
    }

    // ══ RTE / EWS ═══════════════════════════════════════════════════════════

    /**
     * The reference is persisted so the claim can be audited; the MONEY still
     * goes through the existing canonical path — a concession of type
     * 'rte_ews' carrying govtReceivableAmount, under maker-checker. How much an
     * RTE/EWS student pays, and how much is recoverable from the state, is a
     * school-and-statute question this code must not answer.
     */
    public function test_the_rte_reference_is_stored_but_grants_nothing(): void
    {
        $sis = $this->src(self::SIS);

        $this->assertStringContainsString("'rteEwsRef'", $sis,
            'a government-category claim that leaves no trace on the student record cannot be '
            . 'audited or produced on request');
        $this->assertStringContainsString('it is not applied automatically', $sis,
            'the operator must be told the concession still has to be raised — otherwise they '
            . 'believe the first bill is already discounted');
    }

    // ══ HOSTEL ══════════════════════════════════════════════════════════════

    /**
     * Hostel has no request model at all — `hostelRequests` has no writer
     * anywhere, so the collection does not exist. The control is disabled and
     * says so, rather than accepting a request nobody will ever see. Building
     * the lifecycle is out of scope for this workstream.
     */
    public function test_the_hostel_option_no_longer_pretends_to_work(): void
    {
        $decl = $this->src(self::DECL);
        $at   = strpos($decl, 'Hostel accommodation is required');
        $this->assertNotFalse($at);

        // The INPUT itself. Asserting the word 'disabled' near the label passed
        // even with the attribute removed, because the comment above it
        // explains that the control IS disabled.
        $this->assertStringContainsString('value="1" disabled>', $decl,
            'ticking it wrote a declaration that nothing consumed — no queue, no operator '
            . 'screen, no allocation ever saw it');
        $this->assertStringContainsString('not yet available', $decl,
            'and it must say WHY, so staff who used to tick it know it stopped rather than '
            . 'wondering where it went');
    }
}
