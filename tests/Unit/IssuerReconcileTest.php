<?php
namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * `reconcile()` is the single decision three write doors share.
 *
 * These tests exist because the behaviour they pin was previously spread across
 * one controller — so the other two doors silently disagreed with it, and a
 * VERIFIED badge could survive a change of affiliation number nobody checked.
 */
final class IssuerReconcileTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        if (!defined('BASEPATH')) { define('BASEPATH', 1); }
        require_once __DIR__ . '/../../application/libraries/Issuer_identity.php';
    }

    /** A school as stored, verified, in a state written the legacy free-text way. */
    private function stored(array $over = []): array
    {
        return array_merge([
            'state'             => 'UP',
            'affiliationBoard'  => 'CBSE',
            'affiliationNo'     => '1234567',
            'registeredName'    => 'Adarsh Public School',
            'headOfInstitution' => 'S. Thakur',
            'issuerIdentity'    => ['verification' => [
                'verifiedBy' => 'staff:STA0025',
                'verifiedOn' => date('c'),
            ]],
        ], $over);
    }

    public function test_an_unchanged_claim_keeps_its_verification(): void
    {
        $r = \Issuer_identity::reconcile($this->stored(), [
            'affiliationBoard' => 'CBSE', 'affiliationNo' => '1234567',
        ]);
        $this->assertFalse($r['claimMoved']);
        $this->assertNotEmpty($r['verification'], 'Re-saving the same claim must not discard the check.');
        $this->assertSame(\Issuer_identity::VERIFIED, $r['level']);
    }

    public function test_changing_the_number_clears_the_verification_and_drops_the_level(): void
    {
        $r = \Issuer_identity::reconcile($this->stored(), [
            'affiliationBoard' => 'CBSE', 'affiliationNo' => '7654321',
        ]);
        $this->assertTrue($r['claimMoved']);
        $this->assertSame([], $r['verification'],
            'A badge must not outlive the claim it was attached to.');
        $this->assertSame(\Issuer_identity::CLAIMED, $r['level']);
    }

    /**
     * The defect this whole change exists to remove: the Profile door saves a
     * phone number and the Issuer tab's validated affiliation reverts.
     */
    public function test_a_door_that_does_not_carry_the_claim_cannot_move_it(): void
    {
        $r = \Issuer_identity::reconcile($this->stored(), ['phone' => '9999999999']);
        $this->assertFalse($r['claimMoved']);
        $this->assertNotEmpty($r['verification']);
        $this->assertArrayNotHasKey('affiliationNo', $r['fields'],
            'A door that did not submit the number must not write one.');
    }

    public function test_a_malformed_number_is_rejected_and_never_reaches_fields(): void
    {
        $r = \Issuer_identity::reconcile($this->stored(), [
            'affiliationBoard' => 'CBSE', 'affiliationNo' => 'not-a-number',
        ]);
        $this->assertArrayHasKey('affiliationNo', $r['errors']);
        $this->assertArrayNotHasKey('affiliationNo', $r['fields']);
    }

    /**
     * The UDISE cross-check has never run in production: the controller never
     * passed a state and only the unit test did. reconcile() supplies it from
     * the stored document.
     */
    public function test_the_state_for_the_udise_check_comes_from_the_stored_document(): void
    {
        $stored = $this->stored(['state' => 'Kerala']);
        $r = \Issuer_identity::reconcile($stored, ['udiseCode' => '09310113101']); // 09 = UP
        $this->assertArrayHasKey('udiseCode', $r['errors'],
            'A UP code on a Kerala school should be questioned without the caller passing a state.');
    }

    /** A legacy free-text state must not manufacture a mismatch. */
    public function test_an_unrecognised_state_does_not_reject_a_valid_code(): void
    {
        $r = \Issuer_identity::reconcile($this->stored(['state' => 'UP']), [
            'udiseCode' => '09310113101',
        ]);
        $this->assertArrayNotHasKey('udiseCode', $r['errors'],
            '"UP" is not evidence of a mismatch — it is an absence of evidence.');
    }

    public function test_canonical_state_resolves_spacing_and_ampersands(): void
    {
        $this->assertSame('jammu and kashmir', \Issuer_identity::canonicalState('Jammu & Kashmir'));
        $this->assertSame('himachal pradesh', \Issuer_identity::canonicalState('  Himachal Pradesh '));
        $this->assertNull(\Issuer_identity::canonicalState('UP'));
        $this->assertNull(\Issuer_identity::canonicalState(''));
    }
}
