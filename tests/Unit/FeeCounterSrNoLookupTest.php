<?php
/**
 * SR-No resolution at the fee counter — RECONSTRUCTED after a recovery incident.
 *
 * WHY THIS TEST EXISTS. `Fees::lookup_student` resolved SR Numbers and was
 * verified working earlier in this session. It was then LOST: a splice accident
 * deleted a large region of Fees.php, and restoring that region from git HEAD
 * reverted the change, because it was uncommitted working-tree work.
 *
 * An exhaustive search found no copy — not in any local or remote branch, not
 * in dangling git objects, not in editor local history, not in any other
 * checkout on this machine. The behaviour was therefore RECONSTRUCTED from
 * observed evidence rather than restored byte-for-byte.
 *
 * The evidence it was reconstructed against, captured before the loss:
 *     SR "2"   -> {"user_id":"STU0032", ..., "resolved_via":"srNo"}
 *     SR "999" -> {"error":"SR No. '999' not found in this school"}
 *
 * RE-VERIFIED after reconstruction, through the real endpoint:
 *     SR 2 in SCH_AUTHTEST1   -> STU0032, resolved_via srNo
 *     SR 999                  -> "SR No. '999' not found in this school"
 *     STU0032 (direct id)     -> resolved_via studentId
 *     SR 2 in SCH_FAREAUDIT1  -> STU0008 — ITS OWN student, not AUTHTEST1's
 *
 * This test exists so the behaviour cannot be lost silently a second time.
 */

use PHPUnit\Framework\TestCase;

final class FeeCounterSrNoLookupTest extends TestCase
{
    private const FEES = __DIR__ . '/../../application/controllers/Fees.php';

    private function lookupBody(): string
    {
        $s  = (string) file_get_contents(self::FEES);
        $at = strpos($s, 'public function lookup_student');
        $this->assertNotFalse($at, 'lookup_student no longer exists');
        $next = strpos($s, "\n    public function ", $at + 10);
        return substr($s, $at, ($next ?: strlen($s)) - $at);
    }

    /** A parent can recite an SR Number. They cannot recite a student id. */
    public function test_the_counter_resolves_an_sr_number(): void
    {
        $body = $this->lookupBody();

        $this->assertStringContainsString('$this->_resolveStudentRef($entered)', $body,
            'the fee counter must accept an SR Number. Without it the operator can only search '
            . 'by internal student id, which no family knows.');
    }

    /**
     * ONE resolver. `_resolveStudentRef` delegates to Sr_no_service scoped to
     * $school_id; a second local implementation would be a second source of
     * truth and a place for tenant scoping to be forgotten.
     */
    public function test_it_delegates_to_the_canonical_resolver(): void
    {
        $body = $this->lookupBody();

        // Instantiation/query forms, not bare names — the docblock above the
        // method NAMES Sr_no_service to explain the delegation, so a bare-name
        // search matches the explanation instead of the code.
        foreach (["load->library('sr_no_service", 'new Sr_no_service',
                  'srNoNorm', "firestoreQuery('students'"] as $ownLookup) {
            $this->assertStringNotContainsString($ownLookup, $body,
                "lookup_student is doing its own SR resolution ('{$ownLookup}'). It must delegate "
                . 'to MY_Controller::_resolveStudentRef, which is tenant-scoped by construction.');
        }
    }

    /**
     * A well-formed SR No that matches nothing must say so precisely. "Student
     * not found" sends the operator hunting for a student id they do not have.
     */
    public function test_an_unmatched_sr_number_says_so_precisely(): void
    {
        $body = $this->lookupBody();

        // `$entered`, not `$userId`: the refusal must echo what the OPERATOR
        // typed. `$userId` is the canonical id and, on this branch, has not
        // been resolved — quoting it back would print the wrong number.
        $this->assertStringContainsString("SR No. '{\$entered}' not found in this school", $body,
            'the refusal must name the SR Number AND the school scope');
    }

    /** Direct student ids must keep working — this was additive, not a swap. */
    public function test_a_direct_student_id_still_resolves(): void
    {
        $body = $this->lookupBody();

        $this->assertStringContainsString("\$resolvedVia = 'studentId';", $body,
            'a student id must remain a valid input; SR No resolution is additional to it');
        $this->assertStringContainsString("'resolved_via' => \$resolvedVia", $body,
            'the response must say WHICH identifier matched, so the counter can show it');
    }

    /**
     * The reconstruction is labelled as such. Someone reading this later must
     * not believe an exact copy was recovered when it was not.
     */
    public function test_the_reconstruction_is_documented_as_a_reconstruction(): void
    {
        $body = $this->lookupBody();

        $this->assertStringContainsString('RECONSTRUCTED, NOT EXACTLY RECOVERED', $body,
            'honesty about provenance matters more here than usual: the original was lost, and '
            . 'a future reader must know this is rebuilt from observed behaviour');
    }
}
