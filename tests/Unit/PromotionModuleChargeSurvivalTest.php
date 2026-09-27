<?php

use PHPUnit\Framework\TestCase;

/**
 * ══════════════════════════════════════════════════════════════════════════
 *  PROMO-SC-1 — PROMOTION ARCHIVED EVERY OUTSTANDING ONE-OFF CHARGE
 * ══════════════════════════════════════════════════════════════════════════
 *
 * `reassignFeesOnPromotion()` archives every UNPAID demand whose class/section
 * differs from the target:
 *
 *     if (((string) ($d['className'] ?? '')) === $newClass &&
 *         ((string) ($d['section']   ?? '')) === $newSection) continue;
 *     … archive …
 *
 * For TUITION that is correct: Class 9 fees are not Class 10 fees, and
 * `assignInitialFees()` regenerates them from the new chart immediately after
 * the loop.
 *
 * For a MODULE charge it is wrong, and expensively so. `createModuleFee()`
 * stamps the student's class and section onto the demand, so a lost book, a
 * breakage, an ID-card replacement or a transport fare fails that comparison
 * the moment either changes — and **nothing regenerates it**. The regeneration
 * on this path reads the fee chart only; `createModuleFee` is never called.
 *
 * So promoting a student, or merely correcting their section, silently
 * archived every outstanding one-off charge and the school lost the money.
 * The demand ends `status: archived`, `balance: 0` — indistinguishable from a
 * charge that was deliberately written off.
 *
 * ── WHY IT SURVIVED ───────────────────────────────────────────────────────
 * The predicate is *about* class, so it reads as obviously correct. Its
 * comment even says "purely className/section based; session was never
 * load-bearing" — the author was reasoning about SESSION drift, and the
 * class-derived-vs-not distinction was never the question being asked.
 */
class PromotionModuleChargeSurvivalTest extends TestCase
{
    private static string $src = '';

    public static function setUpBeforeClass(): void
    {
        self::$src = (string) file_get_contents(APPPATH . 'libraries/Fee_lifecycle.php');
    }

    private function promotionBody(): string
    {
        $at = strpos(self::$src, 'public function reassignFeesOnPromotion');
        $this->assertNotFalse($at, 'reassignFeesOnPromotion was renamed');

        $end = strpos(self::$src, "\n    public function ", $at + 30);
        return substr(self::$src, $at, ($end !== false ? $end - $at : 20000));
    }

    // ══ THE PREMISE ═════════════════════════════════════════════════════════

    /**
     * Module demands really do carry the student's class and section, which is
     * what makes them fail the archive predicate.
     */
    public function test_control_module_charges_carry_class_and_section(): void
    {
        $at   = strpos(self::$src, 'public function createModuleFee');
        $end  = strpos(self::$src, "\n    public function ", $at + 30);
        $body = substr(self::$src, $at, ($end !== false ? $end - $at : 20000));

        $this->assertStringContainsString("'className'   => (string) (\$stu['className'] ?? ''),", $body,
            'module demands no longer carry a class — if so, they can no longer fail the '
            . 'promotion predicate and this file should be re-derived');
        $this->assertStringContainsString("'category'    => strtolower((string) \$moduleType),", $body,
            'the module category is no longer written, so the exclusion below cannot identify '
            . 'a module charge');
    }

    /**
     * And nothing regenerates them. This is the half that turns an archive into
     * a loss: tuition comes back, a lost-book charge does not.
     */
    public function test_control_promotion_regenerates_only_chart_fees(): void
    {
        $body = $this->promotionBody();

        $this->assertStringContainsString('$this->assignInitialFees($studentId, $newClass, $newSection', $body,
            'promotion no longer regenerates chart fees');

        // Executable lines only. The PROMO-SC-1 rationale in this method names
        // createModuleFee() while explaining why it is NOT called here, so a
        // raw scan matches the note rather than a call.
        $code = (string) preg_replace('!^\s*//.*$!m', '', $body);

        $this->assertStringNotContainsString('createModuleFee(', $code,
            'promotion now regenerates module charges. If it does, archiving them is no longer '
            . 'a loss and this exclusion may be unnecessary — re-derive before removing it.');
    }

    // ══ PROMO-SC-1 ══════════════════════════════════════════════════════════

    /** WOULD HAVE FAILED BEFORE: the predicate had no category exclusion at all. */
    public function test_a_module_charge_is_not_archived_by_a_class_change(): void
    {
        $body = $this->promotionBody();

        $this->assertStringContainsString(
            "if (trim((string) (\$d['moduleType'] ?? '')) !== '') { \$preserved++; continue; }", $body,
            'promotion still archives module charges. A student promoted with an outstanding '
            . 'lost-book charge loses it — the demand becomes archived with balance 0 and '
            . 'nothing recreates it.');
    }

    /**
     * The exclusion must be evaluated BEFORE the class comparison, or a module
     * charge whose class differs is archived before the exclusion is reached.
     */
    public function test_the_exclusion_runs_before_the_class_comparison(): void
    {
        $body = $this->promotionBody();

        $catAt   = strpos($body, "\$d['moduleType']");
        $classAt = strpos($body, "=== \$newClass &&");

        $this->assertNotFalse($catAt, 'the module-charge exclusion is gone');
        $this->assertNotFalse($classAt, 'the class comparison is gone');
        $this->assertLessThan($classAt, $catAt,
            'the module exclusion is evaluated after the class comparison, so a module charge '
            . 'whose class differs is archived before the exclusion can protect it');
    }

    /**
     * The marker must be written by the generator, or the exclusion has
     * nothing to test. Every module gets it automatically — that is the point
     * of a marker rather than a list.
     */
    public function test_the_generator_stamps_the_module_marker(): void
    {
        $at   = strpos(self::$src, 'public function createModuleFee');
        $end  = strpos(self::$src, "\n    public function ", $at + 30);
        $body = substr(self::$src, $at, ($end !== false ? $end - $at : 20000));

        $this->assertStringContainsString("'moduleType'  => \$moduleType,", $body,
            'createModuleFee no longer stamps the module marker, so promotion cannot tell a '
            . 'module charge from a chart fee and will archive it');

        $txn = (string) file_get_contents(APPPATH . 'libraries/Fee_firestore_txn.php');
        $this->assertStringContainsString("if (array_key_exists('moduleType', \$data)) {", $txn,
            'the write boundary discards the module marker — it builds from named keys, so an '
            . 'unlisted field never reaches the document');
    }

    /**
     * A MARKER, not a list of module names.
     *
     * Two earlier attempts were wrong and both are worth pinning:
     *
     *  • enumerating categories ('transport', 'idcard', …) couples this
     *    generic method to specific modules — which existing guards forbid —
     *    AND does not work, because the M5 registry stamp OVERRIDES the
     *    module-name category with the registry's own ('administrative' for an
     *    ID card). It would have protected nothing on precisely the tenants
     *    that have the registry enabled;
     *  • parsing the module out of the demand id is the
     *    re-derive-identity-from-a-string anti-pattern this phase removed.
     */
    public function test_the_exclusion_names_no_module(): void
    {
        $at   = strpos(self::$src, 'public function reassignFeesOnPromotion');
        $end  = strpos(self::$src, "\n    public function ", $at + 30);
        $body = substr(self::$src, $at, ($end !== false ? $end - $at : 20000));

        // Executable lines only — the rationale above names the modules.
        $code = (string) preg_replace('!^\s*//.*$!m', '', $body);

        foreach (['idcard', 'IDCard', 'studentcharge', 'transport', 'hostel'] as $module) {
            $this->assertStringNotContainsString($module, $code,
                "the promotion exclusion names '{$module}'. It must key on the generic marker: "
                . 'naming modules both couples this method to them and fails once the fee-head '
                . 'registry overrides the category it would be matching on.');
        }
    }

    // ══ WHAT MUST STILL BE ARCHIVED ═════════════════════════════════════════

    /**
     * Tuition must still be archived on a class change — that is the whole
     * purpose of the method, and the regeneration depends on it.
     */
    public function test_chart_fees_are_still_archived_on_a_class_change(): void
    {
        $body = $this->promotionBody();

        $this->assertStringContainsString("'status'        => 'archived',", $body,
            'promotion no longer archives anything, so a promoted student would keep both '
            . 'their old and new class fees');
        $this->assertStringContainsString("if (\$st !== 'unpaid') { \$preserved++; continue; }", $body,
            'promotion now archives PAID demands, destroying real payment history');
    }

    /** A demand already in the target class must still be left alone. */
    public function test_a_demand_already_in_the_target_class_is_untouched(): void
    {
        $body = $this->promotionBody();

        $this->assertStringContainsString("=== \$newClass &&", $body);
        $this->assertStringContainsString("=== \$newSection) continue;", $body,
            'the same-class skip was lost, so re-running a promotion would archive the demands '
            . 'it had just regenerated');
    }
    // ══ XMOD-1 · ONE MODULE MUST NOT CANCEL ANOTHER'S CHARGE ════════════════

    /**
     * `cancelSessionDemands()` matches on `category`, and with the fee-head
     * registry ON that value is the REGISTRY's, not the module's — the M5 stamp
     * deliberately overrides it.
     *
     * So a Student Charge raised against the "Transport Fee" head is stored
     * with `category: 'transport'` and is matched by a TRANSPORT rollover. A
     * lost-book charge would be cancelled by a transport session rollover.
     *
     * Reachable today: the Student Charges picker offers "Transport Fee ·
     * transport" under fee-structure heads.
     */
    public function test_a_rollover_cannot_cancel_another_modules_charge(): void
    {
        $at   = strpos(self::$src, 'public function cancelSessionDemands');
        $this->assertNotFalse($at, 'cancelSessionDemands was renamed');
        $end  = strpos(self::$src, "\n    public function ", $at + 30);
        $body = substr(self::$src, $at, ($end !== false ? $end - $at : 12000));

        $this->assertStringContainsString(
            "if (\$rowModule !== '' && strcasecmp(\$rowModule, \$moduleType) !== 0) {", $body,
            'a session rollover still cancels by category alone. With the registry on, a '
            . 'Student Charge raised against the Transport Fee head carries category '
            . "'transport' and would be cancelled by a transport rollover.");
    }

    /**
     * Backward compatibility is the reason this is an in-memory filter rather
     * than a query predicate: demands written before `moduleType` existed carry
     * none, and must keep being cancelled by their own module's rollover.
     */
    public function test_a_pre_marker_demand_still_falls_through_to_the_category_match(): void
    {
        $at   = strpos(self::$src, 'public function cancelSessionDemands');
        $end  = strpos(self::$src, "\n    public function ", $at + 30);
        $body = substr(self::$src, $at, ($end !== false ? $end - $at : 12000));

        $this->assertStringContainsString("\$rowModule !== ''", $body,
            'the guard no longer tolerates an absent marker, so every demand written before '
            . 'moduleType existed would stop being cancelled by its own rollover — leaving '
            . 'phantom cross-session dues, the exact defect FIN-2 fixed');
    }

    /**
     * And it must stay OUT of the Firestore predicate. `firestore.indexes.json`
     * records a deliberate decision not to add composite indexes that are not
     * needed; an undeployed index fails a query CLOSED in production, which
     * here would mean no old-session demand is ever cancelled.
     */
    public function test_the_module_guard_is_not_in_the_query_predicate(): void
    {
        $at   = strpos(self::$src, 'public function cancelSessionDemands');
        $end  = strpos(self::$src, "\n    public function ", $at + 30);
        $body = substr(self::$src, $at, ($end !== false ? $end - $at : 12000));

        // Bounded at the query's own closing bracket. A fixed window ran past
        // it into the XMOD-1 rationale, which necessarily mentions moduleType
        // while explaining why it is NOT in the predicate.
        $queryAt  = strpos($body, 'schoolWhere(');
        $this->assertNotFalse($queryAt, 'the rollover query was restructured');
        $queryEnd = strpos($body, ']);', $queryAt);
        $query    = substr($body, $queryAt, $queryEnd - $queryAt);

        // The FIELD, not the variable. The predicate legitimately uses the
        // $moduleType variable to build the category clause, so searching for
        // the bare word matches correct code.
        $this->assertStringNotContainsString("['moduleType'", $query,
            'moduleType was added to the query predicate as a filter field. That needs a new '
            . 'composite index, and an undeployed index fails the query closed — no '
            . 'old-session demand would be cancelled at all.');

        // And the category clause must still be built from the module name.
        $this->assertStringContainsString("['category',  '==', strtolower((string) \$moduleType)]", $query,
            'the rollover no longer scopes by category, so it would consider every demand in '
            . 'the period regardless of which module raised it');
    }

    /** Paid and already-cancelled demands must still be skipped first. */
    public function test_settled_demands_are_still_skipped_by_rollover(): void
    {
        $at   = strpos(self::$src, 'public function cancelSessionDemands');
        $end  = strpos(self::$src, "\n    public function ", $at + 30);
        $body = substr(self::$src, $at, ($end !== false ? $end - $at : 12000));

        $paidAt   = strpos($body, "if (\$status === 'cancelled' || \$status === 'paid') {");
        $moduleAt = strpos($body, "\$rowModule = trim(");

        $this->assertNotFalse($paidAt, 'the paid/cancelled skip is gone — rollover would '
            . 'cancel a demand that has already been settled');
        $this->assertLessThan($moduleAt, $paidAt,
            'the settled-demand skip now runs after the module guard; it must come first so a '
            . 'paid demand is never reconsidered for cancellation');
    }
}
