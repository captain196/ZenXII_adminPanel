<?php

use PHPUnit\Framework\TestCase;

/**
 * ══════════════════════════════════════════════════════════════════════════
 *  FRZ-4 — A RE-INSTATED STUDENT'S FEE BOOK STAYED FROZEN FOREVER
 * ══════════════════════════════════════════════════════════════════════════
 *
 * The freeze half of the lifecycle was complete and the thaw half did not
 * exist.
 *
 *   withdraw_student()  → freezeFeesOnSoftDelete()  → status 'frozen'
 *   delete_student()    → freezeFeesOnSoftDelete()  → status 'frozen'
 *   Hostel.php:402      → freezeFeesOnSoftDelete()  → status 'frozen'
 *   cancel_tc()         → Status 'Active'           → ...nothing
 *
 * `grep` for an unfreeze returned nothing: no such method existed anywhere in
 * the codebase, and `cancel_tc()` referenced `Fee_lifecycle` zero times. A
 * student withdrawn and then re-instated came back Active with their entire fee
 * book still marked "do not collect" — the meaning `Fee_lifecycle`'s own status
 * vocabulary gives to 'frozen'.
 *
 * ── THIS GOT WORSE BECAUSE OF AN EARLIER FIX IN THIS PROGRAMME ────────────
 * Stated plainly rather than quietly corrected. Before FRZ-1, `fineFor()` used
 * a denylist that did not include 'frozen', so a re-instated student's demands
 * kept accruing late fees — accidentally the right outcome, produced by a
 * defect. FRZ-1 correctly exempted frozen demands from fines, and in doing so
 * converted this dormant gap into a live one: a re-instated student is now
 * silently exempt from late fees for the remainder of the session.
 *
 * That is the argument for re-checking old invariants after every fix rather
 * than only testing the fix itself.
 *
 * ── WHY THE STATUS IS DERIVED, NOT RESTORED ───────────────────────────────
 * The freeze records no prior status, and adding one now would leave every
 * already-frozen demand un-restorable. The status is re-derived from the
 * demand's own money using the same paid/partial/unpaid rule the payment and
 * module paths apply — which is also strictly better than a snapshot: a payment
 * or waiver applied while the student was withdrawn is respected instead of
 * being overwritten by a stale restored value.
 */
class FeeFreezeLifecycleTest extends TestCase
{
    private static string $lib = '';
    private static string $sis = '';

    public static function setUpBeforeClass(): void
    {
        self::$lib = (string) file_get_contents(APPPATH . 'libraries/Fee_lifecycle.php');
        self::$sis = (string) file_get_contents(APPPATH . 'controllers/Sis.php');
    }

    private function method(string $src, string $needle): string
    {
        $at = strpos($src, $needle);
        $this->assertNotFalse($at, "{$needle} was renamed");
        $end = strpos($src, "\n    public function ", $at + 30);
        return substr($src, $at, ($end !== false ? $end - $at : 12000));
    }

    // ══ THE TRANSITION EXISTS AT ALL ════════════════════════════════════════

    /** WOULD HAVE FAILED BEFORE: no unfreeze existed anywhere. */
    public function test_a_thaw_exists_for_every_freeze(): void
    {
        $this->assertStringContainsString('public function unfreezeFeesOnRestore', self::$lib,
            'there is no way to reverse freezeFeesOnSoftDelete(). A re-instated student keeps '
            . 'a fee book marked "do not collect" — and since FRZ-1, that means no late fee '
            . 'can ever be applied to them again.');
    }

    /** And the re-instatement path must actually call it. */
    public function test_cancelling_a_tc_unfreezes_the_fee_book(): void
    {
        $body = $this->method(self::$sis, 'public function cancel_tc');

        $this->assertStringContainsString("'Status' => 'Active'", $body,
            'cancel_tc no longer reactivates the student — re-derive this test');
        $this->assertStringContainsString('unfreezeFeesOnRestore($userId)', $body,
            'cancel_tc restores the student to Active without thawing their demands, so the '
            . 'money half of the withdrawal is never reversed');
    }

    /** The freeze side must still exist, or the thaw is answering nothing. */
    public function test_control_the_freeze_still_happens_on_withdrawal(): void
    {
        $body = $this->method(self::$sis, 'public function withdraw_student');

        $this->assertStringContainsString('freezeFeesOnSoftDelete($userId)', $body,
            'withdrawal no longer freezes fees — if that is deliberate, this whole file needs '
            . 're-deriving');
    }

    // ══ WHAT IT MAY AND MAY NOT TOUCH ═══════════════════════════════════════

    /**
     * Only 'frozen'. An archived or reversed demand left the dues surface for
     * reasons unrelated to the withdrawal, and resurrecting one would put a
     * historical charge back in front of a family.
     */
    public function test_only_frozen_demands_are_restored(): void
    {
        $body = $this->method(self::$lib, 'public function unfreezeFeesOnRestore');

        $this->assertStringContainsString("if ((string) (\$d['status'] ?? '') !== 'frozen') continue;", $body,
            'the thaw no longer restricts itself to frozen demands. An archived or reversed '
            . 'charge would be resurrected onto the family\'s dues.');
    }

    /**
     * The restored status must come from the money. A blanket 'unpaid' would
     * erase the record of a payment taken during the TC window, and re-bill it.
     */
    public function test_the_restored_status_is_derived_from_the_demand_money(): void
    {
        $body = $this->method(self::$lib, 'public function unfreezeFeesOnRestore');

        $this->assertStringNotContainsString("'status'      => 'unpaid',", $body,
            'every restored demand is forced back to unpaid, which erases a payment taken '
            . 'while the student was withdrawn and re-bills the family for it');

        $this->assertStringContainsString("\$status = 'paid';", $body,
            'a demand settled during the TC window must come back paid, not partial');
        $this->assertStringContainsString("\$status = 'partial';", $body);
        $this->assertStringContainsString("\$status = 'unpaid';", $body);
    }

    /**
     * Ordering: `paid` must be tested before `partial`, or a fully settled
     * demand comes back 'partial' and shows as owing in every report that keys
     * on status rather than balance.
     */
    public function test_a_fully_settled_demand_is_not_restored_as_partial(): void
    {
        $body = $this->method(self::$lib, 'public function unfreezeFeesOnRestore');

        $paidAt    = strpos($body, "\$status = 'paid';");
        $partialAt = strpos($body, "\$status = 'partial';");

        $this->assertNotFalse($paidAt);
        $this->assertNotFalse($partialAt);
        $this->assertLessThan($partialAt, $paidAt,
            'the partial branch is evaluated before the paid branch, so a demand settled in '
            . 'full during the TC window comes back marked partial');
    }

    // ══ IT MUST NOT BREAK THE RE-INSTATEMENT ════════════════════════════════

    /**
     * Fail-soft, mirroring the freeze. Losing a status restore must not fail a
     * re-instatement — by that point the student is already Active and the
     * caller retrying would repeat the whole operation.
     */
    public function test_a_thaw_failure_never_aborts_the_reinstatement(): void
    {
        $body = $this->method(self::$sis, 'public function cancel_tc');

        $at = strpos($body, 'unfreezeFeesOnRestore($userId)');
        $this->assertNotFalse($at);

        $before = substr($body, max(0, $at - 200), min(200, $at));
        $after  = substr($body, $at, 300);

        $this->assertStringContainsString('try {', $before,
            'the thaw is unguarded, so one unwritable demand fails the whole re-instatement');
        $this->assertMatchesRegularExpression('/catch \(\\\\Throwable/', $after,
            'the thaw is guarded by catch(\\Exception). A missing collaborator raises an '
            . 'Error, and the student is already Active by this point.');
    }

    // ══ PROVENANCE ══════════════════════════════════════════════════════════

    /**
     * PROV-1 made `updateDemand()` persist the who/when of a freeze. The
     * reverse transition needs the same treatment, or the thaw is invisible —
     * and `updateDemand()` is a strict whitelist, so an unlisted field is
     * silently discarded rather than rejected.
     *
     * This is exactly the re-check the directive calls for: a field added after
     * PROV-1 inherits none of its protection automatically.
     */
    public function test_the_thaw_records_who_performed_it(): void
    {
        $txn = (string) file_get_contents(APPPATH . 'libraries/Fee_firestore_txn.php');
        $at  = strpos($txn, 'public function updateDemand');
        $this->assertNotFalse($at);
        $end  = strpos($txn, ' function ', $at + 30);
        $body = substr($txn, $at, ($end !== false ? $end - $at : 12000));

        foreach (['unfrozenAt', 'unfrozenBy'] as $k) {
            $this->assertStringContainsString("'{$k}'", $body,
                "updateDemand() discards '{$k}'. The thaw would land on the document with no "
                . 'record of who restored the fee book or when — the same loss PROV-1 closed '
                . 'for the freeze.');
        }
    }

    /** The freeze provenance must not have been displaced by the addition. */
    public function test_the_freeze_provenance_survives(): void
    {
        $txn = (string) file_get_contents(APPPATH . 'libraries/Fee_firestore_txn.php');

        $this->assertStringContainsString("'frozenAt'       => ['frozenAt']", $txn);
        $this->assertStringContainsString("'frozenBy'       => ['frozenBy']", $txn);
    }

    // ══ THE INTERACTION WITH FRZ-1 ══════════════════════════════════════════

    /**
     * The reason this mattered. Pinned so the relationship between the two
     * fixes stays visible: if fine eligibility ever stopped excluding 'frozen',
     * FRZ-4 would become cosmetic again — and someone might then remove it as
     * unnecessary.
     */
    public function test_frozen_demands_are_still_exempt_from_fines(): void
    {
        $svc = (string) file_get_contents(APPPATH . 'libraries/Fee_late_fee_service.php');

        $this->assertStringContainsString("public const FINEABLE_STATUSES = ['unpaid', 'partial'];", $svc,
            'fine eligibility changed. FRZ-4 exists because FRZ-1 exempts frozen demands from '
            . 'late fees — if that is no longer true, re-derive why the thaw matters.');
    }
}
