<?php

use PHPUnit\Framework\TestCase;

/**
 * ══════════════════════════════════════════════════════════════════════════
 *  ENT-DISC-1 — A SCHEDULED END DATE THAT ENDED THE CONCESSION TODAY
 * ══════════════════════════════════════════════════════════════════════════
 *
 * `Entitlement_service::discontinue()` documented itself as follows:
 *
 *   "`effectiveTo` is stamped so the readers' window logic stops matching it
 *    from that date, rather than relying on status alone."
 *
 * That was the intent, and it is not what happened.
 * `Fee_entitlement_reader::activeConcessionsForStudent()` queries
 * `status == 'active'`, so a discontinued row leaves the candidate set the
 * instant its status changes — **before any window logic runs**.
 *
 * The endpoint accepts `effective_to` as a POST parameter and validates its
 * format, so a future date is reachable. An operator ending a scholarship
 * "from the end of this term" got it ended **today**: the family would be
 * billed at full rate from the next generation run, months early, while the
 * stored `effectiveTo` appeared to say otherwise.
 *
 * ── THE FIX: KEEP THE ROW ACTIVE, LET THE WINDOW DECIDE ──────────────────
 * A future end date now leaves the status **'active'** and stamps `effectiveTo`.
 * The row stays in the candidate set and the window logic excludes it from that
 * date onward — exactly what the docblock always described.
 *
 * The obvious alternative — teach the reader to fetch discontinued rows too via
 * `status in [...]` — was rejected. That changes a live Firestore query, and
 * `firestore.indexes.json` records a deliberate decision that this read has NO
 * composite index because it is equality-only and served by index merging, with
 * an explicit "re-evaluate only if that read ever gains an orderBy or a range
 * filter". Chasing a fix that does not need it would spend that decision for
 * nothing.
 *
 * It is also the semantically true answer: an entitlement scheduled to end next
 * term IS still active today. Reporting it as discontinued while it is still
 * discounting bills would be its own inconsistency.
 *
 * A PAST or same-day date behaves exactly as before — the status flips at once.
 * That is the common case and the default when no date is supplied.
 *
 * ── WHAT WAS ALREADY CORRECT ──────────────────────────────────────────────
 * Everything else about revocation. It never deletes the row — "a deleted
 * concession is an unexplainable gap in a family's billing history" — it is
 * idempotent, and it is audited. Demands already generated at a discounted rate
 * KEEP that discount, which is right: you do not retroactively re-bill a family
 * for months already invoiced. Re-pricing is a deliberate operator action
 * through the P5-E recalculation, which is entitlement-aware.
 */
class EntitlementRevocationSemanticsTest extends TestCase
{
    private static string $svc = '';
    private static string $reader = '';

    public static function setUpBeforeClass(): void
    {
        self::$svc    = (string) file_get_contents(APPPATH . 'libraries/Entitlement_service.php');
        self::$reader = (string) file_get_contents(APPPATH . 'libraries/Fee_entitlement_reader.php');
    }

    private function discontinueBody(): string
    {
        $at = strpos(self::$svc, 'public function discontinue');
        $this->assertNotFalse($at, 'discontinue was renamed');
        $end = strpos(self::$svc, "\n    public function ", $at + 30);
        return substr(self::$svc, $at, ($end !== false ? $end - $at : 4000));
    }

    // ══ THE PREMISE ═════════════════════════════════════════════════════════

    /**
     * Everything here rests on the reader excluding by STATUS before the window
     * is consulted. If that changes, scheduled end dates become honourable and
     * this file must be re-derived.
     */
    public function test_control_the_reader_excludes_by_status_before_any_window_check(): void
    {
        $at = strpos(self::$reader, 'public function activeConcessionsForStudent');
        $this->assertNotFalse($at);
        $body = substr(self::$reader, $at, 900);

        $this->assertStringContainsString("['status',    '==', 'active'],", $body,
            'the candidate query no longer filters on active status. If discontinued rows now '
            . 'reach appliesTo(), the window governs and a future effectiveTo could be honoured '
            . '— re-derive ENT-DISC-1 rather than deleting it.');
    }

    /** And the window logic must still exist for rows that DO reach it. */
    public function test_control_the_window_still_governs_active_rows(): void
    {
        $at   = strpos(self::$reader, 'public function appliesTo');
        $body = substr(self::$reader, $at, 3500);

        $this->assertStringContainsString(
            "if (\$effTo !== null && \$effTo !== '' && strcmp((string) \$effTo, \$firstOfMonth) < 0) return false;",
            $body,
            'the expiry half of the window check is gone, so an entitlement would apply '
            . 'forever regardless of its end date');
    }

    // ══ ENT-DISC-1 ══════════════════════════════════════════════════════════

    /**
     * WOULD HAVE FAILED BEFORE: the status flipped immediately, so the row left
     * the reader's candidate set and the concession stopped applying at once —
     * whatever date was stamped.
     *
     * The fix keeps the row ACTIVE so the window logic can do exactly what this
     * method's docblock always claimed.
     */
    public function test_a_future_end_date_schedules_rather_than_ending_now(): void
    {
        $body = $this->discontinueBody();

        $this->assertStringContainsString("\$scheduled = \$effectiveTo > date('Y-m-d');", $body,
            'a future end date is no longer distinguished from an immediate one');

        $this->assertStringContainsString("\$patch['scheduledEnd'] = true;", $body,
            'a scheduled end is not marked, so a UI cannot show "ending on <date>" and a '
            . 'future backfill cannot find rows whose date has passed');

        // The decisive assertion: status must NOT be written on the scheduled path.
        $schedAt = strpos($body, "if (\$scheduled) {");
        $elseAt  = strpos($body, '} else {', $schedAt);
        $this->assertNotFalse($schedAt, 'the scheduled branch is gone');
        $this->assertNotFalse($elseAt);

        $scheduledBranch = substr($body, $schedAt, $elseAt - $schedAt);
        $this->assertStringNotContainsString("'status'", $scheduledBranch,
            'the scheduled branch writes a status. Flipping it to discontinued removes the row '
            . "from Fee_entitlement_reader's `status == 'active'` query immediately, so the "
            . 'concession stops applying today and the family is billed at full rate months '
            . 'early — the exact defect ENT-DISC-1 closed.');
    }

    /** An immediate end must still flip the status — that is the common case. */
    public function test_a_past_or_same_day_end_takes_effect_immediately(): void
    {
        $body = $this->discontinueBody();

        $schedAt = strpos($body, "if (\$scheduled) {");
        $elseAt  = strpos($body, '} else {', $schedAt);
        $tail    = substr($body, $elseAt, 400);

        $this->assertStringContainsString("\$patch['status']       = 'discontinued';", $tail,
            'an immediate discontinuation no longer flips the status, so the concession would '
            . 'keep applying until its effectiveTo month rolls past — the opposite defect');
        $this->assertStringContainsString("\$patch['scheduledEnd'] = false;", $tail,
            'a previously-scheduled row that is then ended immediately keeps its stale '
            . 'scheduledEnd marker');
    }

    /**
     * The reader's query must stay untouched. `firestore.indexes.json` records a
     * deliberate decision that this read has NO composite index because it is
     * equality-only and served by index merging — "re-evaluate only if that read
     * ever gains an orderBy or a range filter". The fix was chosen precisely so
     * that decision does not have to be spent.
     */
    public function test_the_fix_required_no_change_to_the_reader_query(): void
    {
        $at   = strpos(self::$reader, 'public function activeConcessionsForStudent');
        $body = substr(self::$reader, $at, 900);

        $this->assertStringContainsString("['status',    '==', 'active'],", $body,
            'the reader query changed. If it now uses `in` or fetches discontinued rows, '
            . 'confirm the composite-index decision in firestore.indexes.json was revisited '
            . 'and the index deployed — an undeployed composite index fails the query closed '
            . 'in production.');
    }

    /** The two outcomes must be reported differently, or a caller cannot tell what happened. */
    public function test_the_two_outcomes_are_reported_distinctly(): void
    {
        $body = $this->discontinueBody();

        $this->assertStringContainsString("'scheduled' => true", $body,
            'a scheduled end returns the same envelope as an immediate one, so the admin UI '
            . 'would report the concession as ended when it is still live');
        $this->assertStringContainsString("entitlement_end_scheduled", $body,
            'a scheduled end is audited as an immediate discontinuation, so the audit trail '
            . 'disagrees with what actually happened');
    }
    /** A past date is meaningful and must still be accepted. */
    public function test_a_past_end_date_is_still_accepted(): void
    {
        $body = $this->discontinueBody();

        // The guard must be strictly greater-than, not >=, or "today" is refused.
        $this->assertStringNotContainsString("if (\$effectiveTo >= date('Y-m-d')) {", $body,
            'the guard refuses TODAY as well as future dates, so an operator cannot end an '
            . 'entitlement on the day they are actually ending it — which is the default case');
        $this->assertStringContainsString("\$effectiveTo = trim(\$effectiveTo) !== '' ? trim(\$effectiveTo) : date('Y-m-d');", $body,
            'the default of "today" was lost, so omitting the date no longer works');
    }

    /** The format check must run BEFORE the future check, or garbage compares as a date. */
    public function test_the_format_is_validated_before_the_future_check(): void
    {
        $body = $this->discontinueBody();

        $formatAt = strpos($body, "preg_match('/^\\d{4}-\\d{2}-\\d{2}\$/', \$effectiveTo)");
        $futureAt = strpos($body, "\$scheduled = \$effectiveTo > date('Y-m-d');");

        $this->assertNotFalse($formatAt, 'the format validation is gone');
        $this->assertNotFalse($futureAt);
        $this->assertLessThan($futureAt, $formatAt,
            'the future check runs before the format check, so a malformed value would be '
            . 'string-compared against a date and could pass or fail arbitrarily');
    }

    // ══ WHAT MUST NOT CHANGE ════════════════════════════════════════════════

    /**
     * Never deletes. The row IS the audit trail, and a family's billing history
     * with a concession silently missing is unexplainable.
     */
    public function test_revocation_preserves_the_row(): void
    {
        $body = $this->discontinueBody();

        $this->assertStringContainsString("\$patch['status']       = 'discontinued';", $body,
            'revocation no longer marks the row — confirm it does not now delete it');
        // Executable code only — the docblock explains at length that this
        // method must never delete, and a raw scan would match that prose.
        $code = (string) preg_replace('!^\s*//.*$!m', '', $body);
        $code = (string) preg_replace('!/\*.*?\*/!s', '', $code);

        $this->assertStringNotContainsString('->delete', strtolower($code),
            'revocation deletes the entitlement row. That removes the only record of why a '
            . "family's fees changed.");
    }

    /** Idempotent — a repeated revocation must be a no-op, not an error or a second write. */
    public function test_revocation_is_idempotent(): void
    {
        $body = $this->discontinueBody();

        $this->assertStringContainsString(
            "if (in_array((string) (\$cur['status'] ?? ''), ['discontinued', 'rejected'], true)) {", $body,
            'a second revocation is no longer a no-op, so a double-click would rewrite the '
            . 'effectiveTo and move the end date');
        $this->assertStringContainsString("'noop' => true", $body);
    }

    /** Audited — an unaudited revocation is indistinguishable from data loss. */
    public function test_revocation_is_audited(): void
    {
        $body = $this->discontinueBody();

        // The audit event name is now chosen by a ternary — an immediate end and
        // a scheduled one are different events and must not report as one.
        $this->assertStringContainsString('entitlement_discontinued', $body,
            'revocation is no longer audited, so a concession disappearing from a family\'s '
            . 'billing has no explanation attached');
    }

    /**
     * Demands already generated keep their discount. Recorded as a decision so
     * nobody "fixes" revocation into a retroactive re-bill.
     */
    public function test_revocation_does_not_reprice_existing_demands(): void
    {
        $body = $this->discontinueBody();

        foreach (['feeDemands', 'writeDemand', 'updateDemand', 'recalculate'] as $needle) {
            $this->assertStringNotContainsString($needle, $body,
                "discontinue() now touches '{$needle}'. Demands already invoiced at a "
                . 'discounted rate must keep it — retroactively re-billing months a family '
                . 'has already been invoiced for is not what ending a concession means. '
                . 'Re-pricing is a deliberate operator action through P5-E recalculation.');
        }
    }
}
