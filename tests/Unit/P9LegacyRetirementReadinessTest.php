<?php

use PHPUnit\Framework\TestCase;

if (!defined('BASEPATH')) define('BASEPATH', __DIR__);
if (!defined('APPPATH'))  define('APPPATH', __DIR__ . '/../../application/');
if (!function_exists('log_message')) { function log_message($l, $m) {} }

/**
 * P9 — LEGACY DISCOUNT RETIREMENT READINESS.
 *
 * Retirement is not "the replacement exists". It is: every reader, writer,
 * caller and cross-platform consumer is known, and the surface has stopped
 * growing. This file pins the census so that a NEW legacy touchpoint appearing
 * fails the suite instead of silently enlarging what has to be retired later.
 *
 * ── THE THREE LEGACY DISCOUNT AUTHORITIES ─────────────────────────────────
 *   1. `scholarshipAwards`        — pooled, head-agnostic (magic `_scholarship` key)
 *   2. `students.exemptedFees{}`  — full exemption per head
 *   3. `students.discountHeads{}` — fixed amount per head
 *
 * ── MEASURED LIVE ESTATE (all tenants) ────────────────────────────────────
 *      scholarshipAwards 0 · exemptedFees 0 · discountHeads 0
 * **No data migration is required.** But an empty collection is not an unused
 * one: the write path is live, and the Parent app reads it.
 *
 * ── WHY THIS IS NOT YET RETIREMENT ────────────────────────────────────────
 * Nothing here deletes, disables or migrates anything. Removing legacy code
 * before its replacement is production-proven is how a working system becomes a
 * broken one, and the standing rule is explicit: prove zero callers FIRST.
 */
class P9LegacyRetirementReadinessTest extends TestCase
{
    private function src(string $rel): string
    {
        return (string) file_get_contents(APPPATH . $rel);
    }

    // ══ THE WRITE SURFACE — what retirement must close FIRST ════════════

    /**
     * `scholarshipAwards` has exactly TWO write sites, both in Fee_management.
     * Retirement closes these before anything else: while a writer is live, the
     * collection can gain rows after you have "proved" it empty.
     */
    public function test_the_scholarship_write_surface_is_exactly_two_sites(): void
    {
        $fm = $this->src('controllers/Fee_management.php');
        $this->assertSame(2, substr_count($fm, "firestoreSet('scholarshipAwards'"),
            'a THIRD writer would enlarge the retirement surface — find it before retiring');

        // And no other server file may write the collection at all.
        $writers = [];
        foreach (array_merge(glob(APPPATH . 'controllers/*.php'),
                             glob(APPPATH . 'libraries/*.php'),
                             glob(APPPATH . 'services/*.php')) as $f) {
            if (strpos(basename($f), 'Test_') === 0) continue;
            $s = (string) file_get_contents($f);
            if (preg_match("/firestore(Set|Delete)\(\s*'scholarshipAwards'/", $s)) {
                $writers[] = basename($f);
            }
        }
        $this->assertSame(['Fee_management.php'], $writers,
            'exactly one file may write scholarshipAwards: ' . implode(', ', $writers));
    }

    /** The per-student legacy fields are written from a known, small surface. */
    public function test_the_per_student_legacy_fields_have_a_bounded_surface(): void
    {
        $files = [];
        foreach (array_merge(glob(APPPATH . 'controllers/*.php'),
                             glob(APPPATH . 'libraries/*.php')) as $f) {
            if (strpos(basename($f), 'Test_') === 0) continue;
            $s = (string) file_get_contents($f);
            if (strpos($s, 'exemptedFees') !== false || strpos($s, 'discountHeads') !== false) {
                $files[] = basename($f);
            }
        }
        sort($files);
        $this->assertSame(['Fee_concessions.php', 'Fees.php', 'Sis.php'], $files,
            'the exemptedFees/discountHeads surface must not grow: ' . implode(', ', $files));
    }

    // ══ THE READ SURFACE — retired only AFTER the writers ═══════════════

    /** The single server reader that feeds pricing. */
    public function test_pricing_reads_scholarships_through_exactly_one_reader(): void
    {
        $txn = $this->src('libraries/Fee_firestore_txn.php');
        $this->assertStringContainsString('function readScholarshipAwards', $txn,
            'one reader keeps the retirement cut-point single');

        $fees = $this->src('controllers/Fees.php');
        $this->assertSame(1, substr_count($fees, 'readScholarshipAwards('),
            'pricing must consult it from ONE place (_getStudentDiscounts)');
    }

    /**
     * The three authorities are still combined in one place. That single
     * function is the retirement cut-point: when it is gone, legacy discounting
     * is gone.
     */
    public function test_the_three_authorities_still_share_one_cut_point(): void
    {
        $fees = $this->src('controllers/Fees.php');
        $at   = strpos($fees, 'function _getStudentDiscounts');
        $this->assertNotFalse($at, 'the cut-point must exist');
        $seg  = substr($fees, $at, 1600);
        foreach (['readScholarshipAwards', 'exemptedFees', 'discountHeads', '_scholarship'] as $needle) {
            $this->assertStringContainsString($needle, $seg,
                "'{$needle}' must still be read from the single cut-point, not scattered");
        }
    }

    // ══ THE CROSS-PLATFORM DEPENDENCY — the real P9 blocker ═════════════

    /**
     * The Parent app reads `scholarshipAwards` DIRECTLY. This is the reason P9
     * is a cross-platform contract change and not a server-side cleanup:
     * closing the writer alone would leave the app querying a collection that
     * can never gain rows, degrading silently to ₹0 rather than failing loudly.
     *
     * Asserted from the Admin repo's own record so the dependency cannot be
     * forgotten when someone reads only this file.
     */
    public function test_the_parent_app_dependency_is_recorded(): void
    {
        $baseline = (string) @file_get_contents(
            APPPATH . '../STUDENT_ENTITLEMENTS_ARCHITECTURE_BASELINE.md');
        $this->assertNotSame('', $baseline, 'the baseline document must remain');
        $this->assertStringContainsString('scholarshipAwards', $baseline);
        $this->assertStringContainsString('FeeFirestoreRepository', $baseline,
            'the Parent-app read path must stay documented — verified live at '
            . 'FeeFirestoreRepository.kt:306 <- FeesViewModel.kt:599,:925');
    }

    // ══ THE REPLACEMENT EXISTS, AND IS NOT YET WIRED ════════════════════

    /** Phase C is built — retirement has somewhere to go. */
    public function test_the_replacement_allocator_exists(): void
    {
        $this->assertFileExists(APPPATH . 'libraries/Scholarship_allocator.php');
        $src = $this->src('libraries/Scholarship_allocator.php');
        $this->assertStringContainsString('BASIS_WHOLE_SESSION', $src);
        $this->assertStringContainsString('BASIS_PER_PERIOD', $src);
    }

    /**
     * …and is NOT wired. The legacy engine must remain the one in force until
     * its replacement is production-proven.
     */
    public function test_the_replacement_is_not_wired_and_legacy_still_runs(): void
    {
        $callers = [];
        foreach (array_merge(glob(APPPATH . 'controllers/*.php'),
                             glob(APPPATH . 'libraries/*.php')) as $f) {
            if (basename($f) === 'Scholarship_allocator.php') continue;
            if (strpos((string) file_get_contents($f), 'Scholarship_allocator') !== false) {
                $callers[] = basename($f);
            }
        }
        // BOUNDARY MOVED (2026-08-19): `Fee_management` now uses ONE method,
        // `percentageBase()`, to fix P3D-F3 — the per-period base that wrongly
        // summed annual heads (design §2, per-month safe). The APPORTIONMENT is
        // still unadopted; that is asserted immediately below, so fixing the
        // base can never be mistaken for wiring Phase C.
        $this->assertSame(['Fee_management.php'], $callers,
            'only the percentage-base fix may use the allocator: ' . implode(', ', $callers));

        foreach (array_merge(glob(APPPATH . 'controllers/*.php'),
                             glob(APPPATH . 'libraries/*.php')) as $f) {
            if (basename($f) === 'Scholarship_allocator.php') continue;
            $this->assertStringNotContainsString('Scholarship_allocator::allocate',
                (string) file_get_contents($f),
                basename($f) . ' must not adopt whole-session apportionment before P5');
        }

        $this->assertStringContainsString('$scholarshipTotal * $proportion',
            $this->src('controllers/Fees.php'),
            'the legacy per-month engine must remain in force until retirement is authorized');
    }

    // ══ NOTHING HAS BEEN RETIRED YET ════════════════════════════════════

    /**
     * The standing rule: do not delete legacy code because a replacement
     * exists. Every legacy authority must still be present and functional.
     */
    public function test_no_legacy_authority_has_been_removed(): void
    {
        $this->assertStringContainsString('function readScholarshipAwards',
            $this->src('libraries/Fee_firestore_txn.php'));
        $this->assertStringContainsString('function _getStudentDiscounts',
            $this->src('controllers/Fees.php'));
        $this->assertStringContainsString("firestoreSet('scholarshipAwards'",
            $this->src('controllers/Fee_management.php'),
            'the writer stays until the Parent app has migrated — closing it '
            . 'early degrades the app silently to zero');
    }

    /** And the legacy concession reader is still available as the flag-off path. */
    public function test_the_legacy_concession_reader_survives(): void
    {
        $this->assertFileExists(APPPATH . 'libraries/Fee_concession_reader.php');
        $this->assertStringContainsString('Fee_concession_reader',
            $this->src('libraries/Fee_lifecycle.php'),
            'it is the rollback path for USE_ENTITLEMENT_BILLING — retiring it '
            . 'would remove the ability to switch entitlement billing OFF');
    }
}
