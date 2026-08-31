<?php
/**
 * THE ROLE-SAFE FINANCIAL READ MODEL — what each surface may see, and why.
 *
 * ── THE PROBLEM THIS SETTLES ──────────────────────────────────────────────
 * The Teacher app shows fee figures labelled "Dues" and "Pending" that come
 * from `feeDemands` — the DEMAND layer. A counter waiver lives at the
 * COLLECTION layer (`studentDiscounts.onDemandDiscount`), which Teacher does
 * not read. So a waived family can appear to owe more than the counter would
 * actually ask for.
 *
 * There are two ways to close that gap, and only one of them is right.
 *
 * The WRONG fix is to teach the Teacher app to read `studentDiscounts` and
 * subtract it. That would put a THIRD implementation of the expiry rule, the
 * session rule, the floor-at-zero rule and the consumption rule into a Kotlin
 * codebase that cannot share the PHP authority — the duplicate-money-authority
 * failure this whole consolidation exists to remove. It would also hand every
 * class teacher a per-family record of who received a hardship waiver.
 *
 * The RIGHT fix is a deliberate, enforced contract:
 *
 *   DEMAND layer      what is BILLED     feeDemands.balance
 *                                        -> Teacher (operational follow-up)
 *   COLLECTION layer  what is ASKED      netDue = balance - applicable discount
 *                                        -> Admin counter, and Parent (own child)
 *
 * A teacher chases attendance and paperwork, not a family's concession. The
 * demand-layer figure is the CORRECT number for that role. What was wrong was
 * never the number — it was that the boundary was accidental rather than
 * stated. This file states it, so it cannot drift back.
 *
 * ── WHY THESE ASSERTIONS ARE SAFE TO RUN ANYWHERE ─────────────────────────
 * The app repositories live outside this one. Every check skips cleanly when
 * they are absent, so this never becomes a false failure on a machine that
 * only has the admin panel — the failure mode of the existing cross-repo
 * suites, which fail against a hardcoded path from another developer's box.
 */

use PHPUnit\Framework\TestCase;

if (!defined('BASEPATH')) define('BASEPATH', __DIR__);
if (!function_exists('log_message')) { function log_message($l, $m) {} }

final class CrossSurfaceFinancialContractTest extends TestCase
{
    private const PARENT_APP  = 'D:/Projects/SchoolSyncParent/app/src/main/java/com/schoolsync/parent';
    private const TEACHER_APP = 'D:/Projects/SchoolSyncTeacher/app/src/main/java/com/schoolsync/teacher';

    /** @return string[] every .kt file under a root, or [] when absent */
    private function kt(string $root): array
    {
        if (!is_dir($root)) return [];
        $out = [];
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root));
        foreach ($it as $f) {
            if ($f->isFile() && strtolower($f->getExtension()) === 'kt') $out[] = $f->getPathname();
        }
        return $out;
    }

    private function grepCount(array $files, string $needle): int
    {
        $n = 0;
        foreach ($files as $f) {
            $n += substr_count((string) file_get_contents($f), $needle);
        }
        return $n;
    }

    // ══ THE ROLE BOUNDARY ══════════════════════════════════════════════════

    /**
     * Teacher must NOT consume the collection-layer adjustment.
     *
     * This is the assertion that keeps the boundary deliberate. If someone
     * "fixes" the Dues discrepancy by reading studentDiscounts in Kotlin, this
     * fails and points them at the reason.
     */
    public function test_teacher_does_not_read_the_collection_layer_adjustment(): void
    {
        $files = $this->kt(self::TEACHER_APP);
        if (!$files) $this->markTestSkipped('Teacher app not present on this machine');

        $this->assertSame(0, $this->grepCount($files, 'onDemandDiscount'),
            'a teacher must not see a family\'s counter waiver, and the expiry/session/floor '
            . 'rules must not be reimplemented in Kotlin — that is a second pricing authority');
    }

    /** Neither app may reimplement the expiry rule that governs a discount. */
    public function test_neither_app_reimplements_the_discount_expiry_rule(): void
    {
        $files = array_merge($this->kt(self::PARENT_APP), $this->kt(self::TEACHER_APP));
        if (!$files) $this->markTestSkipped('apps not present on this machine');

        foreach (['validUntil', 'valid_until'] as $marker) {
            $this->assertSame(0, $this->grepCount($files, $marker),
                "the discount expiry rule lives in Collection_adjustment_policy. A copy in an "
                . "app drifts the moment the rule changes (marker: {$marker}).");
        }
    }

    /**
     * Parent MAY surface its own child's refund — that is the family's money
     * and the product contract already renders it.
     */
    public function test_parent_can_see_its_own_refund_vouchers(): void
    {
        $files = $this->kt(self::PARENT_APP);
        if (!$files) $this->markTestSkipped('Parent app not present on this machine');

        $this->assertGreaterThan(0, $this->grepCount($files, 'feeRefundVouchers'),
            'a processed refund is money owed back to the family and must be visible to them');
    }

    // ══ NO APP MAY DEPEND ON A LEGACY AUTHORITY ════════════════════════════

    /**
     * A new school never writes `studentConcessions` (LEGACY-SEAL-1), so any
     * app that READ it would show nothing for every new tenant — a silent,
     * tenant-shaped blank. Proven on the day-one tenant: 0 documents, and the
     * full lifecycle completed without it.
     */
    public function test_no_app_depends_on_the_legacy_concession_store(): void
    {
        $files = array_merge($this->kt(self::PARENT_APP), $this->kt(self::TEACHER_APP));
        if (!$files) $this->markTestSkipped('apps not present on this machine');

        foreach (['studentConcessions', 'studentServiceEnrollments'] as $legacy) {
            $this->assertSame(0, $this->grepCount($files, $legacy),
                "no app may require '{$legacy}': a clean school never creates it, so the app "
                . 'would render an empty screen for every new tenant');
        }
    }

    // ══ THE ADMIN SIDE OF THE CONTRACT ═════════════════════════════════════

    /**
     * The derivation the apps must NOT copy has exactly one home, and it is
     * expiry-aware and floors at zero.
     */
    public function test_the_canonical_derivation_has_one_home(): void
    {
        $src = (string) file_get_contents(
            __DIR__ . '/../../application/libraries/Collection_adjustment_policy.php');

        $this->assertStringContainsString('public static function currentReduction(', $src,
            'the truthful current reduction is derived in ONE place');
        $this->assertStringContainsString("\$vu = trim((string) (\$doc['validUntil'] ?? \$doc['valid_until'] ?? ''));", $src,
            'and it owns the expiry rule');
        $this->assertStringContainsString('return $sum > 0 ? round($sum, 2) : 0.0;', $src,
            'and it floors at zero rather than returning a negative reduction');
    }

    /**
     * The demand document carries everything Teacher's role needs, so the
     * boundary costs it nothing.
     */
    public function test_the_demand_layer_is_sufficient_for_the_teacher_role(): void
    {
        $src = (string) file_get_contents(
            __DIR__ . '/../../application/libraries/Fee_firestore_txn.php');

        foreach (['grossAmount', 'discountAmount', 'netAmount', 'balance', 'status'] as $f) {
            $this->assertStringContainsString("'{$f}'", $src,
                "the demand carries '{$f}', so operational follow-up needs no collection-layer read");
        }
    }
}
