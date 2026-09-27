<?php

use PHPUnit\Framework\TestCase;

/**
 * ══════════════════════════════════════════════════════════════════════════
 *  PROV-1 — A DEMAND RECORDED *THAT* IT WAS FROZEN, NEVER BY WHOM
 * ══════════════════════════════════════════════════════════════════════════
 *
 * `Fee_firestore_txn::updateDemand()` normalises its patch through a STRICT
 * WHITELIST with no passthrough: a key absent from the map is silently
 * discarded. That is deliberate and it is how the codebase keeps demand
 * documents camelCase-only.
 *
 * The cost had already been paid once. DEC-012 records it verbatim:
 *
 *   "This map is a STRICT WHITELIST with no passthrough, so any key missing
 *    from it is silently discarded. proRateFees() has always passed `prorated`
 *    and `prorateFactor`; both were dropped here and never reached Firestore
 *    … The audit trail for a money-changing operation was being thrown away
 *    at the write boundary."
 *
 * The same sentence applied to four more callers. Each writes a status change
 * that WAS whitelisted, alongside provenance fields that were not:
 *
 *   freezeFeesOnSoftDelete()  status:'frozen'   + frozenAt / frozenBy
 *   the merge archive loop    status:'archived' + archivedAt / archivedBy
 *   reverseAdmissionFees()    status:'reversed' + reversedAt / reversedBy
 *   Fee_late_fee_service      fine_amount       + fine_computed_at
 *
 * So the demand landed in the closed state and the record of who closed it,
 * and when, was dropped in the same call.
 *
 * ── WHY THE FREEZE CASE MATTERS MOST ──────────────────────────────────────
 * `freezeFeesOnSoftDelete()` is live from three call sites — `Sis::withdraw_student`,
 * `Sis::delete_student` and `Hostel.php:402` — and it stops collection across a
 * student's entire fee book. That is a consequential administrative act, and
 * there was no record anywhere of who performed it.
 *
 * ── WHAT MAKES THIS UNAMBIGUOUS ───────────────────────────────────────────
 * Not the judgement that provenance is desirable — the INCONSISTENCY.
 * `commitDemandBatch()` passes an archive op's data verbatim:
 *
 *     // 'archive' or 'update' — partial update; pass $data verbatim.
 *
 * So the promotion archive path persists `archivedAt`, while the single-doc
 * merge archive path — the same logical operation, the same field — loses it.
 * One of the two had to be wrong.
 *
 * It also shows the whitelist was never a security boundary: any caller
 * wanting a field through could route it via the batch API. It filtered the
 * careful callers and not the careless ones.
 *
 * ── SCOPE ─────────────────────────────────────────────────────────────────
 * Additive only. Every added key is a string written by a caller that already
 * passes it, the write is `merge: true`, and no reader anywhere depends on
 * these fields being absent — verified by search before the change.
 */
class DemandProvenanceFieldPersistenceTest extends TestCase
{
    private static string $src = '';

    public static function setUpBeforeClass(): void
    {
        self::$src = (string) file_get_contents(APPPATH . 'libraries/Fee_firestore_txn.php');
    }

    /**
     * The whole `updateDemand()` body, bounded at the next method rather than
     * by a fixed character count. A fixed window silently truncated once the
     * PROV-1 comment block was added, which made a passing assertion fail for
     * a reason that had nothing to do with the code under test.
     */
    private function updateDemandBody(): string
    {
        $at = strpos(self::$src, 'public function updateDemand');
        $this->assertNotFalse($at, 'updateDemand was renamed');

        $end = strpos(self::$src, ' function ', $at + 30);
        return substr(self::$src, $at, ($end !== false ? $end - $at : 12000));
    }
    /** The whitelist keys, parsed out of `updateDemand()`'s own map. */
    private function whitelist(): array
    {
        $at = strpos(self::$src, 'public function updateDemand');
        $this->assertNotFalse($at, 'updateDemand was renamed');

        $mapAt = strpos(self::$src, 'static $map = [', $at);
        $end   = strpos(self::$src, '];', $mapAt);
        $body  = substr(self::$src, $mapAt, $end - $mapAt);

        // Strip comments first — the block explaining this fix names every
        // field, and would otherwise be parsed as if it were the map.
        $body = (string) preg_replace('!^\s*//.*$!m', '', $body);

        preg_match_all("/'([a-zA-Z_]+)'\s*=>\s*\[/", $body, $m);
        return $m[1];
    }

    /** Keys a caller passes, mapped from the source names it actually uses. */
    private function accepts(string $camel): bool
    {
        return in_array($camel, $this->whitelist(), true);
    }

    // ══ CONTROL ═════════════════════════════════════════════════════════════

    /**
     * Without this the whitelist could parse empty and every assertion below
     * would fail for the wrong reason — or, worse, a `assertNotContains`
     * style check would pass vacuously.
     */
    public function test_control_the_whitelist_was_parsed_and_is_still_strict(): void
    {
        $wl = $this->whitelist();

        $this->assertContains('paidAmount', $wl, 'the whitelist did not parse');
        $this->assertContains('balance', $wl);
        $this->assertContains('prorated', $wl, 'the DEC-012 keys are missing — that fix regressed');

        // The strictness is the premise of this whole file: if a passthrough
        // were added, the fields below would persist for a different reason
        // and these tests would no longer be protecting anything.
        $this->assertStringContainsString('STRICT WHITELIST with no passthrough', $this->updateDemandBody(),
            'updateDemand may no longer be a strict whitelist — re-derive this file');
    }

    // ══ PROV-1 ══════════════════════════════════════════════════════════════

    /**
     * WOULD HAVE FAILED BEFORE: `frozenAt` and `frozenBy` were not in the map,
     * so a live administrative freeze left no trace of its author.
     */
    public function test_a_freeze_records_who_performed_it_and_when(): void
    {
        foreach (['frozenAt', 'frozenBy'] as $k) {
            $this->assertTrue($this->accepts($k),
                "updateDemand() discards '{$k}'. freezeFeesOnSoftDelete() writes it alongside "
                . "status:'frozen' from three live call sites, so a student's entire fee book "
                . 'is marked uncollectable with no record of who did it or when.');
        }
    }

    public function test_an_archive_records_its_author_and_reason(): void
    {
        foreach (['archivedAt', 'archivedBy', 'archiveReason'] as $k) {
            $this->assertTrue($this->accepts($k), "updateDemand() discards '{$k}'");
        }
    }

    public function test_a_reversal_records_its_author(): void
    {
        foreach (['reversedAt', 'reversedBy'] as $k) {
            $this->assertTrue($this->accepts($k), "updateDemand() discards '{$k}'");
        }
    }

    /**
     * The late-fee timestamp, which is the closest analogue to DEC-012: a
     * money-changing write whose audit field was dropped in the same call that
     * changed the money.
     *
     * It matters more here than elsewhere because `applyTo()` is idempotent by
     * AMOUNT, not by run — so `updatedAt` alone cannot tell you when the fine
     * was actually applied as opposed to when anything else on the demand last
     * changed.
     */
    public function test_a_late_fee_records_when_it_was_computed(): void
    {
        $this->assertTrue($this->accepts('fineComputedAt'),
            "updateDemand() discards the late-fee computation timestamp. Fee_late_fee_service "
            . 'is the only writer of this field and it was never persisted, so no demand in the '
            . 'estate carries a record of when its fine was applied.');
    }

    /**
     * The engine passes the SNAKE name. The map must accept it, or the fix
     * above persists a field nothing writes — which would look correct in the
     * source and change nothing in Firestore.
     */
    public function test_the_late_fee_timestamp_is_accepted_under_the_name_the_engine_writes(): void
    {
        $engine = (string) file_get_contents(APPPATH . 'libraries/Fee_late_fee_service.php');
        $this->assertStringContainsString("'fine_computed_at' => date('c')", $engine,
            'the late-fee engine no longer writes fine_computed_at — re-derive this test');

        $this->assertStringContainsString("'fineComputedAt' => ['fine_computed_at', 'fineComputedAt']", $this->updateDemandBody(),
            'the map does not accept the snake-case name the engine actually writes, so the '
            . 'timestamp is still discarded');
    }

    // ══ THE INCONSISTENCY THAT PROVED IT ════════════════════════════════════

    /**
     * The batch path passes archive data verbatim. That is what made this a
     * defect rather than a preference: two paths for one operation disagreed
     * about whether provenance survives.
     *
     * Pinned so the asymmetry cannot silently return from the other direction
     * — e.g. by someone adding a whitelist to the batch path and assuming the
     * single-doc path already dropped these fields.
     */
    public function test_the_batch_archive_path_still_passes_provenance_through(): void
    {
        $at = strpos(self::$src, 'public function commitDemandBatch');
        $this->assertNotFalse($at, 'commitDemandBatch was renamed');
        $body = substr(self::$src, $at, 1600);

        $this->assertStringContainsString('pass $data verbatim', $body,
            'the batch archive path no longer passes data verbatim. If it now filters, confirm '
            . 'it filters the SAME way as updateDemand — the two disagreeing is exactly the '
            . 'defect PROV-1 closed.');
    }

    // ══ NOTHING ELSE CHANGED ════════════════════════════════════════════════

    /**
     * The money fields must keep their float coercion. The added keys are all
     * strings and must NOT have been added to that list — coercing a timestamp
     * to float would write 0.0.
     */
    public function test_the_added_fields_are_not_coerced_to_float(): void
    {
        $body = $this->updateDemandBody();

        $coerceAt = strpos($body, "in_array(\$camel, ['paidAmount'");
        $this->assertNotFalse($coerceAt, 'the float-coercion list was renamed');
        $coerce = substr($body, $coerceAt, 400);

        foreach (['frozenAt', 'frozenBy', 'archivedAt', 'archivedBy', 'archiveReason',
                  'reversedAt', 'reversedBy', 'fineComputedAt'] as $k) {
            $this->assertStringNotContainsString("'{$k}'", $coerce,
                "'{$k}' is in the float-coercion list. It is an ISO timestamp or an actor id — "
                . 'casting it to float would store 0.0 and destroy the very provenance this '
                . 'change exists to preserve.');
        }

        // And the money fields must still BE coerced.
        foreach (['paidAmount', 'balance', 'netAmount', 'fineAmount'] as $k) {
            $this->assertStringContainsString("'{$k}'", $coerce,
                "'{$k}' lost its float coercion — a string amount would reach Firestore");
        }
    }

    /**
     * The whitelist must not have quietly become a passthrough. The point of
     * PROV-1 was to name the missing fields, not to stop filtering — an
     * unfiltered patch would let a snake_case key land on a camelCase document
     * and reintroduce the shape drift the map exists to prevent.
     */
    public function test_unknown_keys_are_still_discarded(): void
    {
        $wl = $this->whitelist();

        $this->assertNotContains('someUnknownField', $wl);
        $this->assertLessThan(45, count($wl),
            'the whitelist has grown far beyond the known demand vocabulary, which suggests a '
            . 'passthrough was added rather than specific fields');
    }
}
