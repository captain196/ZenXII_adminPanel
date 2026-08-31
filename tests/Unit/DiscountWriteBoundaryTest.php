<?php
/**
 * THE WRITE BOUNDARY AND THE RECEIPT PATH — ADJ-GOV-3, ADJ-GOV-6, ADJ-GOV-8
 * and the FCS session regression.
 *
 * These four findings live entirely in `Fee_firestore_txn` and
 * `FeeCollectionService`, which is why they are pinned here rather than in
 * CollectionAdjustmentGovernanceTest: that file also asserts against
 * `Fee_management`, which cannot yet be committed because it carries another
 * session's staged payment-gateway work. Splitting the assertions lets the code
 * and the test that governs it ship TOGETHER — the asymmetry that caused the
 * original Fees.php loss was exactly code tracked while its test was not.
 *
 * Every finding below was proven on the PERSISTED Firestore document after all
 * downstream writers completed, not on an endpoint response.
 */

use PHPUnit\Framework\TestCase;

if (!defined('BASEPATH')) define('BASEPATH', __DIR__);
if (!function_exists('log_message')) { function log_message($l, $m) {} }

final class DiscountWriteBoundaryTest extends TestCase
{
    private const TXN = __DIR__ . '/../../application/libraries/Fee_firestore_txn.php';
    private const FCS = __DIR__ . '/../../application/services/FeeCollectionService.php';

    private function src(string $f): string { return (string) file_get_contents($f); }

    /** Executable code only — these files DISCUSS the banned forms at length. */
    private function code(string $f): string
    {
        $out = '';
        foreach (token_get_all($this->src($f)) as $t) {
            $out .= is_array($t)
                ? (in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true) ? ' ' : $t[1])
                : $t;
        }
        return $out;
    }

    private function method(string $file, string $needle): string
    {
        $s  = $this->src($file);
        $at = strpos($s, $needle);
        $this->assertNotFalse($at, "{$needle} no longer exists");
        $end = strpos($s, "\n    public function ", $at + 10);
        return substr($s, $at, ($end ?: strlen($s)) - $at);
    }

    // ══ ADJ-GOV-3 · ATTRIBUTION SURVIVES THE WRITE BOUNDARY ════════════════

    /**
     * `updateDiscount` built a FIXED document, so `reason`, `appliedBy`,
     * `appliedAt`, `source` and `validUntil` were all discarded — and because
     * the write is a full overwrite, anything previously stored was erased too.
     *
     * This is the root cause of SD-1 in STUDENT_DISCOUNTS_FORENSIC_AUDIT, which
     * found a live school carrying a permanent, unattributed reduction and
     * concluded its provenance was "unknown and unknowable from code". It was
     * unknowable because the writer threw it away. A gate that makes a reason
     * mandatory is defeated if the storage layer drops it one call later.
     */
    public function test_the_writer_preserves_attribution_and_expiry(): void
    {
        $body = $this->method(self::TXN, 'function updateDiscount');

        foreach (['reason', 'appliedBy', 'appliedAt', 'source', 'validUntil'] as $field) {
            $this->assertStringContainsString("'{$field}'", $body,
                "updateDiscount must persist '{$field}'");
        }
        $this->assertStringContainsString('$existing[$keep]', $body,
            'an unrelated update must not erase attribution that is already stored');
    }

    // ══ ADJ-GOV-8 · THE DUAL-EMITTED EXPIRY PAIR AGREES ════════════════════

    /**
     * Each writer supplies only ONE of `validUntil` / `valid_until`, so the
     * preserve loop could pair a caller's camel value with a STALE stored snake
     * value. Every reader is camel-first and `??` only falls through on NULL,
     * so this was harmless today — but a self-contradictory record sets up the
     * dangerous reading: "" = never expires.
     */
    public function test_the_expiry_pair_is_normalised(): void
    {
        $body = $this->method(self::TXN, 'function updateDiscount');
        $this->assertStringContainsString("\$doc['validUntil']  = \$__vu;", $body);
        $this->assertStringContainsString("\$doc['valid_until'] = \$__vu;", $body,
            'the snake key is a compatibility echo of the authoritative camel key');
    }

    // ══ ADJ-GOV-6 · THE AUTHORISATION IS CONSUMED BY THE RECEIPT ═══════════

    /**
     * `onDemandDiscount` is a SINGLE STANDING reduction — `fetch_months`
     * subtracts it once from the whole outstanding. The receipt path re-read it
     * fresh on every collection and never decremented it, so one approved
     * waiver could be spent again on the next receipt.
     *
     * PROVEN at runtime before the fix: a 500 waiver accepted on receipt 1 AND
     * receipt 2, both recording discount=500, with 500 still standing after.
     * After: 500 -> 0, and the second attempt is refused with
     * "exceeds the approved concession Rs. 0.00".
     */
    public function test_a_receipt_consumes_the_authorisation_it_spends(): void
    {
        $fcs = $this->src(self::FCS);

        $this->assertStringContainsString(
            '$__remainingAuth = round(max(0.0, $approvedDiscountCap - $discountFees), 2);', $fcs,
            'the receipt must reduce the standing authorisation by what it used, never below zero');
        $this->assertStringContainsString("'onDemandDiscount' => \$__remainingAuth", $fcs,
            'and must WRITE the reduced authorisation back — re-reading an unchanged field '
            . 'is what let one approval be spent on every receipt');
        $this->assertStringContainsString('ACC_DISCOUNT_CONSUMED', $fcs,
            'consuming an approved waiver is a money event and must be recorded');
    }

    /** SD-2 — the reported total is the sum of its parts, on this door too. */
    public function test_the_receipt_writes_a_coherent_total(): void
    {
        $this->assertStringContainsString(
            "'totalDiscount' => round(\$__remainingAuth + \$__scholKeep, 2),", $this->src(self::FCS),
            'writing only the last receipt\'s discount made totalDiscount a drifting tally');
    }

    // ══ THE SESSION REGRESSION ═════════════════════════════════════════════

    /**
     * `MY_Controller::$session_year` is PROTECTED and FeeCollectionService is a
     * separate class, so `$controller->session_year` throws — and the enclosing
     * \Throwable catch swallowed it, left the authorised cap at 0 and silently
     * REJECTED EVERY counter discount.
     *
     * No unit test caught that. It was found by driving the real endpoint and
     * reading the log: "Cannot access protected property Fees::$session_year".
     */
    public function test_the_receipt_cap_reads_the_session_from_its_own_data(): void
    {
        $code = $this->code(self::FCS);

        $this->assertStringNotContainsString('$controller->session_year', $code,
            'reading a protected property from this class throws, and the catch turns that '
            . 'into a silent rejection of every discount');
        $this->assertStringContainsString('$__docSession !== (string) $session', $code,
            "use \$session, taken from \$data['session_year']");
    }

    /** Session scope must gate the cap assignment, not merely be computed. */
    public function test_the_session_guard_actually_gates_the_cap(): void
    {
        $this->assertStringContainsString('if (!$__expired && !$__wrongSession) {', $this->src(self::FCS),
            'a mutant that computed $__wrongSession and then ignored it survived an earlier '
            . 'version of this assertion — the variable existed, the money was still authorised');
    }
}
