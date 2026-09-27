<?php
/**
 * LEGACY-SEAL-1 — a clean school never acquires the legacy pricing authority.
 *
 * THE ARCHITECTURE THIS PROTECTS. Exactly ONE pricing reader is injected per
 * generation (`Fee_lifecycle` picks `$pricingReader`), so the demand layer can
 * never double-discount. Which reader is chosen is decided PER STUDENT
 * (AUTH-SCOPE-1): a student holding an active legacy `studentConcession` is
 * priced by the legacy authority, and their canonical entitlements — including
 * every policy-driven sibling or staff-ward benefit — are NOT applied.
 *
 * PROVEN AT RUNTIME on a fresh unpaid student holding BOTH an active legacy
 * concession and an active 50% entitlement:
 *     12 demands updated, 0 preserved
 *     gross 12,000   discount 6,000   net 6,000
 * Exactly ONE 50%. Never stacked. The legacy authority won, by design.
 *
 * FOR A SCHOOL WITH LEGACY RECORDS that is correct: switching their authority
 * mid-history would drop discounts currently reducing real bills.
 *
 * FOR A SCHOOL WITH NONE it is pure harm — one click would silently opt a
 * student out of the canonical benefit engine, with nothing in the UI saying
 * so. So the door is sealed exactly there.
 *
 * PROVEN, both directions:
 *     SCH_FAREAUDIT1 (no legacy records) -> first legacy concession REFUSED
 *     SCH_AUTHTEST1  (has legacy records) -> still records normally
 */

use PHPUnit\Framework\TestCase;

final class LegacyConcessionSealTest extends TestCase
{
    private const FC = __DIR__ . '/../../application/controllers/Fee_concessions.php';
    private const FL = __DIR__ . '/../../application/libraries/Fee_lifecycle.php';

    /**
     * The body of create_concession(), which is where the seal lives.
     *
     * 2026-09-14 — was substr($s, $at - 400, 4000). The firestoreSet needle sat 228
     * characters from the far edge, so this would have started failing on growth
     * while the seal itself was intact. Bounded by the method now: the assertions
     * are about that method, and it can grow without silently truncating them.
     */
    private function createBody(): string
    {
        $s  = (string) file_get_contents(self::FC);
        $at = strpos($s, 'LEGACY-SEAL-1');
        $this->assertNotFalse($at, 'the seal, and the reason for it, must stay at the write site');

        $mStart = strrpos(substr($s, 0, $at), 'public function create_concession(');
        $this->assertNotFalse($mStart,
            'the seal is no longer inside create_concession() — the write site moved');
        $mEnd = preg_match('/\n    (?:public|private|protected) function /', $s, $m,
                           PREG_OFFSET_CAPTURE, $at) ? $m[0][1] : strlen($s);
        return substr($s, $mStart, $mEnd - $mStart);
    }

    // ══ THE SEAL ════════════════════════════════════════════════════════════

    /** The gate is "does this school already hold legacy records?" — nothing else. */
    public function test_a_school_with_no_legacy_records_cannot_create_its_first(): void
    {
        $body = $this->createBody();

        $this->assertStringContainsString("firestoreQuery('studentConcessions'", $body,
            'the seal must ask whether this SCHOOL already holds legacy concessions');
        $this->assertStringContainsString('if (empty($existingLegacy))', $body,
            'no existing legacy records means a clean school, and a clean school must stay clean');
        $this->assertStringContainsString('LEGACY_SEAL_1', $body,
            'a refusal that changes what an operator can do must be logged');
    }

    /** Schools that already depend on the legacy path keep working. */
    public function test_a_school_with_legacy_records_is_untouched(): void
    {
        $body = $this->createBody();

        // The write still exists and is reached when the school has history.
        $this->assertStringContainsString("firestoreSet('studentConcessions', \$docId, \$doc)", $body,
            'existing legacy schools must still be able to record a concession — switching '
            . 'their authority mid-history would drop live discounts');
    }

    /** The refusal names where to go instead. A dead end teaches nothing. */
    public function test_the_refusal_points_at_the_canonical_path(): void
    {
        $body = $this->createBody();

        $this->assertStringContainsString('Student Benefits', $body,
            'refusing without naming the replacement leaves the operator stuck and looks like '
            . 'a bug rather than a deliberate boundary');
    }

    /**
     * If the check itself fails, REFUSE. A wrongly-created legacy row silently
     * changes which engine prices that student — the expensive direction.
     */
    public function test_an_unverifiable_school_is_refused_not_written(): void
    {
        $body = $this->createBody();

        $this->assertMatchesRegularExpression(
            '/catch \(\\\\Throwable \$e\).{0,700}return \$this->json_error/s', $body,
            'a failure to determine the school\'s setup must refuse the write, not fall '
            . 'through to it');
    }

    // ══ THE INVARIANT IT RESTS ON ═══════════════════════════════════════════

    /**
     * One reader per generation. If this ever became two, both authorities
     * could reduce the same charge and the seal would be beside the point.
     */
    public function test_exactly_one_pricing_reader_is_injected(): void
    {
        $s = (string) file_get_contents(self::FL);

        $this->assertSame(1, substr_count($s, '$CI->genSvc->init($this, $pricingReader,'),
            'demand pricing must receive ONE reader. Two would make double-discounting '
            . 'possible at the demand layer, which is currently impossible by construction.');
        $this->assertStringContainsString('$pricingReader = $CI->entitlementReader;', $s);
        $this->assertStringContainsString('$pricingReader = $CI->concessionReader;', $s);
    }

    /** The per-student hold must stay per-student — see AUTH-SCOPE-1. */
    public function test_the_authority_hold_is_still_per_student(): void
    {
        $s = (string) file_get_contents(self::FL);

        $this->assertStringContainsString("['studentId', '==', (string) \$studentId]", $s,
            'a school-wide hold would let one family\'s legacy concession stop every other '
            . 'family\'s entitlement from being priced');
    }
}
