<?php

use PHPUnit\Framework\TestCase;

if (!defined('BASEPATH')) define('BASEPATH', 'x');
if (!defined('APPPATH')) define('APPPATH', __DIR__ . '/../../application/');

/**
 * ══════════════════════════════════════════════════════════════════════════
 *  LEDGER-EMIT-1 · A GRANTED LEDGER WITH NOTHING TO RECORD
 * ══════════════════════════════════════════════════════════════════════════
 *
 * ── THE DEFECT ───────────────────────────────────────────────────────────
 * A tenant with USE_ALLOCATION_LEDGER correctly granted generated 25 genuinely
 * discounted demands and produced ZERO allocation rows. The flag resolved true,
 * the emission path ran, and Entitlement_allocation_service::build() refused
 * every row with `missing_entitlement_id`.
 *
 * The rules reaching build() were legacy `studentConcessions` documents, which
 * carry `concessionId`. No entitlement exists behind them — no approval record,
 * no effective-dated grant row — so the ledger had nothing it could honestly
 * point at.
 *
 * ── WHY IT WAS INVISIBLE ─────────────────────────────────────────────────
 * It failed closed, which is right, but it failed closed 25 times in a row with
 * a per-demand message that reads like a data problem. The single fact that
 * explained all 25 — that this tenant's pricing authority cannot back a ledger
 * at all — was never stated anywhere. An operator saw a granted flag, correct
 * bills, and no ledger, with nothing connecting them.
 *
 * ── THE ARCHITECTURE PINNED HERE ─────────────────────────────────────────
 * The ledger is entitlement-backed BY DESIGN. Rather than discovering that one
 * refusal at a time inside the allocation service, the pricing reader now
 * DECLARES whether it can back a ledger, and the generator asks once, before
 * pricing. Legacy concessions are not promoted and no identity is invented:
 * minting an entitlementId from a concessionId would fabricate a reference to a
 * document that does not exist, producing an audit trail that reconciles and
 * lies.
 */
final class LedgerAuthorityBackingTest extends TestCase
{
    private static string $gen = '';
    /** Executable source only — comments stripped. See the note on the flag test. */
    private static string $code = '';

    public static function setUpBeforeClass(): void
    {
        self::$gen = (string) file_get_contents(APPPATH . 'libraries/Fee_generation_service.php');

        $out = '';
        foreach (token_get_all(self::$gen) as $t) {
            if (is_array($t)) {
                if (in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true)) continue;
                $out .= $t[1];
            } else {
                $out .= $t;
            }
        }
        self::$code = $out;
        require_once APPPATH . 'libraries/Fee_concession_reader.php';
        require_once APPPATH . 'libraries/Fee_entitlement_reader.php';
    }

    // ══ THE READER CONTRACT ═════════════════════════════════════════════════

    /** Both readers must answer the same question, or the generator cannot ask it. */
    public function test_both_readers_expose_the_ledger_capability_contract(): void
    {
        foreach (['Fee_concession_reader', 'Fee_entitlement_reader'] as $cls) {
            foreach (['supportsAllocationLedger', 'ledgerIdentity'] as $m) {
                $this->assertTrue(method_exists($cls, $m),
                    "{$cls} is missing {$m}(). The generator is handed whichever reader the "
                    . 'authority boundary selected and cannot know which it holds; a contract '
                    . 'only one reader implements is the DEC-009 failure again.');
            }
        }
    }

    /** The legacy authority must declare honestly that it cannot back a ledger. */
    public function test_the_legacy_reader_refuses_to_back_the_ledger(): void
    {
        $r = new Fee_concession_reader();
        $this->assertFalse($r->supportsAllocationLedger(),
            'the legacy concession reader claims it can back the allocation ledger. Its rows '
            . 'carry concessionId and no entitlement exists behind them, so every row would be '
            . 'refused as missing_entitlement_id — a granted ledger that silently writes nothing.');
    }

    /** ...and must never invent an identity to get past the refusal. */
    public function test_the_legacy_reader_never_mints_an_entitlement_identity(): void
    {
        $r = new Fee_concession_reader();
        $this->assertSame('', $r->ledgerIdentity([
            'concessionId' => 'CONC_123', 'type' => 'percent', 'value' => 10,
        ]), 'a concessionId was promoted into a ledger identity. That fabricates a reference to '
          . 'a studentEntitlements document that does not exist: the ledger would reconcile '
          . 'perfectly against demands while every row pointed at nothing.');
    }

    /** The entitlement authority backs the ledger and reports the real grant id. */
    public function test_the_entitlement_reader_backs_the_ledger_with_the_real_grant_id(): void
    {
        $r = new Fee_entitlement_reader();
        $this->assertTrue($r->supportsAllocationLedger());
        $this->assertSame('SCH_A_STU1_E9', $r->ledgerIdentity(['entitlementId' => 'SCH_A_STU1_E9']));
        $this->assertSame('', $r->ledgerIdentity([]),
            'an entitlement with no id must yield no identity rather than an empty-string row');
    }

    // ══ THE GATE ════════════════════════════════════════════════════════════

    /** The gate must exist and must be consulted by the flag resolver. */
    public function test_the_ledger_is_gated_on_the_authority_that_prices(): void
    {
        $this->assertStringContainsString('_ledgerBackedByAuthority', self::$gen,
            'the authority gate is gone; a granted ledger under a legacy authority would go '
            . 'back to writing nothing while reading as ON');
        $this->assertStringContainsString('supportsAllocationLedger', self::$gen,
            'the generator no longer asks the reader whether it can back a ledger');
    }

    /**
     * The gate must not fire when the ledger is simply OFF — the common case.
     * A tenant that never asked for the ledger must not log a misconfiguration.
     */
    public function test_an_ungranted_ledger_is_silent(): void
    {
        $at = strpos(self::$gen, 'private function _ledgerBackedByAuthority');
        $this->assertNotFalse($at);
        $body = substr(self::$gen, $at, 2000);

        $guard = strpos($body, 'if (!$granted) return false;');
        $log   = strpos($body, 'LEDGER_AUTHORITY_MISMATCH');
        $this->assertNotFalse($guard, 'the gate no longer returns early for an ungranted ledger');
        $this->assertNotFalse($log);
        $this->assertLessThan($log, $guard,
            'the mismatch is logged before checking whether the ledger was even granted, so '
            . 'every ordinary legacy tenant would emit a configuration error it cannot act on');
    }

    /**
     * Phase 3B pins that the authority is chosen at ONE boundary. The generator
     * consumes whichever reader it is given and must not name the flag that
     * selected it — a second literal is a second switch that can drift.
     */
    public function test_the_generator_still_does_not_name_the_authority_flag(): void
    {
        // Against EXECUTABLE source, not the raw file. A long-standing comment in
        // _allocationLedgerEnabled() explains why the ledger has its own flag and
        // names the authority flag while doing so — that is documentation, and
        // banning the word from prose would delete the explanation rather than
        // the coupling. What must never come back is a runtime dependency.
        $this->assertStringNotContainsString('USE_ENTITLEMENT_BILLING', self::$code,
            'the generator names the authority flag again. It must consume whichever reader '
            . 'Fee_lifecycle injected; naming the flag here recreates the drift Phase 3B closed.');
    }

    /** The refusal must be diagnosable once, not 25 times per student. */
    public function test_the_mismatch_is_reported_once_per_tenant(): void
    {
        $this->assertStringContainsString('ledgerMismatchWarned', self::$gen,
            'the gate runs per demand, so without a per-tenant guard one configuration answer '
            . 'is repeated for every demand — reburying it exactly as the 25 per-row refusals did');
    }
}
