<?php
/**
 * AUTH-SCOPE-1 — the legacy-authority hold is PER STUDENT, never per school.
 *
 * THE DEFECT. USE_ENTITLEMENT_BILLING decides which collection prices a demand.
 * A guard held a school on the LEGACY authority whenever it still had active
 * `studentConcessions`, so that a family whose bill was currently being reduced
 * by a legacy concession would not silently lose it. That reasoning is right —
 * but the query was school-wide, and that turned a migration safeguard into a
 * live financial defect.
 *
 * PROVEN BY EXECUTION on a clean two-student tenant, Tuition 1,000/month:
 *
 *   B holds a 50% ENTITLEMENT; no legacy concession anywhere in the school:
 *       gross 12,000   discount 4,000   net  8,000
 *   Student A — someone else entirely — is granted ONE legacy concession
 *   through the real /fee_concessions endpoint, and B is re-priced:
 *       gross 12,000   discount     0   net 12,000
 *
 * B's entitlement was still ACTIVE at 50% and untouched. An operator action on
 * another child's record raised B's bill by 4,000, with no error and nothing on
 * B's record to explain it. Any school still holding one legacy concession
 * could never price an entitlement for anybody — including every benefit the
 * new policy layer grants.
 *
 * AFTER SCOPING THE HOLD TO THE STUDENT, with A's concession still in place:
 *       B  gross 12,000  discount 4,000  net 8,000   (entitlement authority)
 *       A  gross 12,000  discount 6,000  net 6,000   (legacy authority kept)
 * Both authorities coexist per student and neither family loses a discount.
 */

use PHPUnit\Framework\TestCase;

final class EntitlementAuthorityScopeTest extends TestCase
{
    private const LIFECYCLE = __DIR__ . '/../../application/libraries/Fee_lifecycle.php';

    private function guardBody(): string
    {
        $s  = (string) file_get_contents(self::LIFECYCLE);
        $at = strpos($s, "\$legacy = \$this->firebase->firestoreQuery('studentConcessions'");
        $this->assertNotFalse($at, 'the legacy-authority hold no longer exists');
        // 2026-09-14 — was a ±200/1200 character window. This one had the most
        // headroom of the seven, but it pins the studentId predicate that keeps one
        // family's legacy concession from demoting an entire school, so it is worth
        // making insertion-proof rather than merely comfortable.
        return $this->enclosingMethod($s, $at);
    }

    /**
     * The whole defect in one assertion: without a studentId predicate the
     * query answers "does ANY family here hold a legacy concession?", and one
     * yes demotes the entire school.
     */
    public function test_the_legacy_hold_is_filtered_by_student(): void
    {
        $this->assertStringContainsString("['studentId', '==', (string) \$studentId]", $this->guardBody(),
            'the hold must ask whether THIS student holds a legacy concession. School-wide, one '
            . "family's concession stops every other family's entitlement from being priced — "
            . 'proven at runtime as a silent 4,000 rise on an unrelated student.');
    }

    /** The school predicate must remain: tenant isolation is not negotiable. */
    public function test_the_hold_is_still_tenant_scoped(): void
    {
        $this->assertStringContainsString("['schoolId',  '==', (string) \$this->schoolName]", $this->guardBody(),
            'narrowing to the student must not drop the school filter');
    }

    /**
     * Only ACTIVE concessions may hold the authority. Counting revoked ones
     * would pin a student on legacy pricing forever, and migrating them would
     * become impossible.
     */
    public function test_only_an_active_concession_holds_the_authority(): void
    {
        $this->assertStringContainsString("['status',    '==', 'active']", $this->guardBody());
    }

    /**
     * The hold must not be deleted in the name of "moving to entitlements".
     * A student whose bill is currently reduced by a legacy concession keeps
     * the legacy authority, or that discount vanishes and their fees RISE —
     * which is the very outcome the guard exists to prevent.
     */
    public function test_the_hold_still_exists_at_all(): void
    {
        $s = (string) file_get_contents(self::LIFECYCLE);

        $this->assertStringContainsString('ENTITLEMENT_AUTHORITY_HELD', $s,
            'removing the hold entirely would drop live discounts for students who still have '
            . 'legacy concessions — a silent price rise, which is what it was written to stop');
        $this->assertStringContainsString('AUTH-SCOPE-1', $s,
            'the reason the hold is per-student must stay next to it, or someone will widen it '
            . 'again to "be safe"');
    }

    /** Unanswerable question → stay on the authority that has been billing. */
    public function test_the_guard_still_fails_safe(): void
    {
        $s  = (string) file_get_contents(self::LIFECYCLE);
        $at = strpos($s, "\$legacy = \$this->firebase->firestoreQuery('studentConcessions'");
        $tail = substr($s, $at, 1600);

        $this->assertMatchesRegularExpression(
            '/catch \(\\\\Throwable \$e\) \{\s*\$useEntitlementAuthority = false;/', $tail,
            'if the legacy check cannot run, pricing must stay on legacy rather than guess');
    }

    /**
     * The enclosing method body around $at.
     *
     * Replaces a fixed character window. A window silently stops covering the
     * line it was written to pin the moment code is inserted nearby — the
     * assertion then passes or fails on a truncated string rather than on the
     * property it claims to test.
     */
    private function enclosingMethod(string $s, int $at): string
    {
        $start = strrpos(substr($s, 0, $at), '    function ');
        foreach (['    public function ', '    private function ', '    protected function '] as $sig) {
            $p = strrpos(substr($s, 0, $at), $sig);
            if ($p !== false && ($start === false || $p > $start)) $start = $p;
        }
        if ($start === false) return $s;
        $end = preg_match('/\n    (?:public|private|protected) function /', $s, $m,
                          PREG_OFFSET_CAPTURE, $at) ? $m[0][1] : strlen($s);
        return substr($s, $start, $end - $start);
    }
}
