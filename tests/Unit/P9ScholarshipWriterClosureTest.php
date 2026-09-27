<?php

use PHPUnit\Framework\TestCase;

if (!defined('BASEPATH')) define('BASEPATH', __DIR__);
if (!defined('APPPATH'))  define('APPPATH', __DIR__ . '/../../application/');
if (!function_exists('log_message')) { function log_message($l, $m) {} }

/**
 * P9 — the scholarshipAwards WRITER CLOSURE, prepared and flag-gated.
 *
 * `scholarshipAwards` is one of three legacy discount authorities being retired.
 * This is the CREATION gate: with SCHOLARSHIP_AWARDS_RETIRED on, no new legacy
 * award can be minted, so the surface being retired cannot GROW while the
 * replacement is finished.
 *
 * THE DESIGN RULE THIS ENCODES: creation closes, revocation and reads stay open.
 * Retiring a writer must never TRAP existing data — a school must still be able
 * to close an award it granted, and both apps must still display one. A closure
 * that strands live records is not a retirement, it is a data-loss incident with
 * a flag on it.
 *
 * DEFAULT FALSE and its prerequisites are UNMET — most importantly the Parent
 * app reads this collection DIRECTLY (`FeeFirestoreRepository.kt:306` <-
 * `FeesViewModel.kt:599,:925`). Closing the writer before that migrates would
 * leave the app querying a collection that can never gain rows, degrading
 * silently to zero rather than failing loudly.
 */
class P9ScholarshipWriterClosureTest extends TestCase
{
    private function code(string $rel): string
    {
        $out = '';
        foreach (token_get_all((string) file_get_contents(APPPATH . $rel)) as $t) {
            $out .= is_array($t) ? (in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true) ? ' ' : $t[1]) : $t;
        }
        return $out;
    }

    // == THE FLAG ========================================================

    public function test_the_retirement_flag_ships_false(): void
    {
        $this->assertStringContainsString("\$config['SCHOLARSHIP_AWARDS_RETIRED'] = false",
            (string) file_get_contents(APPPATH . 'config/fees_exemption_v2_flags.php'),
            'the legacy path must be untouched until retirement is authorized');
    }

    // == CREATION IS GATED ===============================================

    /** The gate sits at the TOP of the creator, before any work is done. */
    public function test_creation_is_gated_before_any_write(): void
    {
        $code = $this->code('controllers/Fee_management.php');
        $at   = strpos($code, 'function award_scholarship');
        $this->assertNotFalse($at);
        $seg  = substr($code, $at, 1400);

        $this->assertStringContainsString('SCHOLARSHIP_AWARDS_RETIRED', $seg,
            'the creator must consult the retirement flag');
        $gateAt  = strpos($seg, 'SCHOLARSHIP_AWARDS_RETIRED');
        $writeAt = strpos($seg, "firestoreSet('scholarshipAwards'");
        if ($writeAt !== false) {
            $this->assertLessThan($writeAt, $gateAt,
                'the gate must precede the write, or it gates nothing');
        }
    }

    /** It refuses LOUDLY — a silent no-op would look like a granted scholarship. */
    public function test_the_refusal_is_loud_and_names_the_replacement(): void
    {
        $code = $this->code('controllers/Fee_management.php');
        $this->assertStringContainsString('ACC_SCHOLARSHIP_WRITE_REFUSED', $code,
            'the refusal must be greppable');
        $this->assertStringContainsString('json_error', substr($code,
            strpos($code, 'ACC_SCHOLARSHIP_WRITE_REFUSED'), 600),
            'it must return an error, never fall through silently');

        $raw = (string) file_get_contents(APPPATH . 'controllers/Fee_management.php');
        $this->assertStringContainsString('concession on the', $raw,
            'the message must tell the operator where scholarships now live');
    }

    // == REVOCATION AND READS STAY OPEN ==================================

    /**
     * THE PART THAT PREVENTS A DATA-LOSS INCIDENT. Revocation must NOT be gated:
     * a school must be able to close an award it already granted.
     */
    public function test_revocation_is_not_gated(): void
    {
        $code = $this->code('controllers/Fee_management.php');
        $at   = strpos($code, 'function revoke_scholarship');
        $this->assertNotFalse($at, 'the revoke path must still exist');
        $seg  = substr($code, $at, 1200);
        $this->assertStringNotContainsString('SCHOLARSHIP_AWARDS_RETIRED', $seg,
            'gating revocation would TRAP existing awards — never do this');
    }

    /** Read paths stay open so both apps keep displaying existing awards. */
    public function test_read_paths_are_not_gated(): void
    {
        $txn = $this->code('libraries/Fee_firestore_txn.php');
        $this->assertStringContainsString('function readScholarshipAwards', $txn);
        $this->assertStringNotContainsString('SCHOLARSHIP_AWARDS_RETIRED', $txn,
            'gating the reader would blank existing awards in both apps');
    }

    /** Pricing must keep honouring existing awards after the writer closes. */
    public function test_pricing_still_reads_existing_awards(): void
    {
        $fees = $this->code('controllers/Fees.php');
        $this->assertStringContainsString('readScholarshipAwards(', $fees,
            'closing creation must not stop existing awards being priced');
        $this->assertStringNotContainsString('SCHOLARSHIP_AWARDS_RETIRED', $fees,
            'the pricing path is not part of the writer closure');
    }

    // == NOTHING WAS RETIRED YET =========================================

    public function test_the_writer_still_exists_and_works_by_default(): void
    {
        $raw = (string) file_get_contents(APPPATH . 'controllers/Fee_management.php');
        $this->assertStringContainsString("firestoreSet('scholarshipAwards'", $raw,
            'the writer is GATED, not deleted — deletion comes only after '
            . 'the Parent app has migrated and the replacement is proven');
    }

    /** The prerequisites are recorded where the flag lives, not just in prose. */
    public function test_the_prerequisites_are_documented_with_the_flag(): void
    {
        $cfg = (string) file_get_contents(APPPATH . 'config/fees_exemption_v2_flags.php');
        $this->assertStringContainsString('Parent app has migrated', $cfg);
        $this->assertStringContainsString('FeeFirestoreRepository', $cfg,
            'the exact cross-platform dependency must be named at the flag');
    }
}
