<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * IDC-PRINT-1 — the ID card is a PHYSICAL object, so its geometry is testable.
 *
 * These are not source-guards dressed up: the fit test parses the real grid
 * values out of the stylesheet and does the millimetre arithmetic. The bug it
 * replaces was arithmetic — three 260px columns is 238mm of content on a 210mm
 * page — and no amount of "the file mentions A4" would have caught it.
 */
final class IdCardPrintGeometryTest extends TestCase
{
    private const VIEW = __DIR__ . '/../../application/views/sis/id_card.php';

    /** A4 portrait, in millimetres. */
    private const A4_W = 210.0;
    private const A4_H = 297.0;

    /** ISO/IEC 7810 ID-1 ("CR80") — what badge holders and card printers take. */
    private const CR80_W = 53.98;
    private const CR80_H = 85.60;

    private const PARTIAL = __DIR__ . '/../../application/views/sis/_id_card_css.php';

    /**
     * View + shared partial.
     *
     * IDC-CSS-1 moved the card's own rules into _id_card_css.php, which both the
     * page and the bulk-print window now read. These assertions are about the
     * CARD, so they must follow the card — reading only the view would leave
     * every geometry guard silently passing against markup that no longer
     * contains the geometry.
     */
    private function css(): string
    {
        return (string) file_get_contents(self::VIEW) . "\n" . $this->cardCss();
    }

    /**
     * The EXPORTED card stylesheet — the bytes the browser and the print window
     * actually receive.
     *
     * Deliberately not file_get_contents(PARTIAL): that file's docblock explains
     * which rules were removed and why, so a guard asserting their absence would
     * match its own explanation. Including it yields the heredoc alone.
     */
    private function cardCss(): string
    {
        if (!defined('BASEPATH')) define('BASEPATH', 'test');
        if (!defined('APPPATH')) define('APPPATH', __DIR__ . '/../../application/');

        $IDC_CARD_CSS = '';
        include self::PARTIAL;

        return (string) $IDC_CARD_CSS;
    }

    /** CSS px are 1/96 inch by definition, which is the whole point of the bug. */
    private static function pxToMm(float $px): float
    {
        return $px / 96 * 25.4;
    }

    // ══ 1 · it has to fit on the paper ═══════════════════════════════════

    /**
     * THE regression. Parsed from the stylesheet, not hard-coded here, so the
     * test fails if someone widens the card or adds a fourth column.
     */
    public function test_a_printed_sheet_fits_on_a4(): void
    {
        $css = $this->css();

        $this->assertMatchesRegularExpression(
            '~@page\s*\{[^}]*size\s*:\s*A4~i', $css,
            'no @page rule — paper size and margins fall to browser defaults, and '
            . 'Chrome and Firefox disagree'
        );

        // @page margin
        preg_match('~@page\s*\{[^}]*margin\s*:\s*([\d.]+)mm~i', $css, $mg);
        $margin = isset($mg[1]) ? (float) $mg[1] : 0.0;

        // The print grid: repeat(N, var(--idc-w)) with a row/column gap.
        preg_match('~@media print\s*\{.*?\.idc-grid\s*\{([^}]*)\}~s', $css, $g);
        $this->assertNotEmpty($g, 'no print rule for .idc-grid');

        preg_match('~repeat\(\s*(\d+)\s*,~', $g[1], $cols);
        $this->assertNotEmpty($cols, 'the print grid must declare a fixed column count');
        $columns = (int) $cols[1];

        preg_match('~gap\s*:\s*([\d.]+)mm(?:\s+([\d.]+)mm)?~', $g[1], $gap);
        $rowGap = (float) ($gap[1] ?? 0);
        $colGap = (float) ($gap[2] ?? $gap[1] ?? 0);

        // Card footprint, from the :root declaration.
        preg_match('~--idc-w\s*:\s*([\d.]+)mm~', $css, $cw);
        preg_match('~--idc-h\s*:\s*([\d.]+)mm~', $css, $ch);
        $this->assertNotEmpty($cw, '--idc-w must declare the physical card width');
        $cardW = (float) $cw[1];
        $cardH = (float) $ch[1];

        $printableW = self::A4_W - 2 * $margin;
        $printableH = self::A4_H - 2 * $margin;

        $usedW = $columns * $cardW + ($columns - 1) * $colGap;
        $this->assertLessThanOrEqual(
            $printableW, $usedW,
            sprintf(
                '%d columns of %.2fmm plus %d gaps of %.2fmm is %.2fmm, which does not '
                . 'fit the %.1fmm printable width of A4 — the last column runs off the page.',
                $columns, $cardW, $columns - 1, $colGap, $usedW, $printableW
            )
        );

        // At least two full rows, or a sheet holds almost nothing.
        $twoRows = 2 * $cardH + $rowGap;
        $this->assertLessThanOrEqual($printableH, $twoRows,
            'fewer than two rows fit per sheet');
    }

    /** The old values, kept as an explicit demonstration of what was wrong. */
    public function test_the_old_layout_really_did_overflow(): void
    {
        // 3 columns of 260px, 8mm gaps, 8mm padding — the rule that shipped.
        $old = 3 * self::pxToMm(260) + 2 * 8 + 2 * 8;

        $this->assertGreaterThan(self::A4_W, $old,
            'sanity check on the arithmetic this fix is based on: 260 CSS px is '
            . '68.79mm, not an abstract unit, so three columns was always 238mm');
        $this->assertStringNotContainsString(
            'grid-template-columns:repeat(3, 260px)', $this->css(),
            'the overflowing print grid is back'
        );
    }

    // ══ 2 · every card the same size ═════════════════════════════════════

    /**
     * Height used to be content-driven, so a student WITH a blood group got a
     * taller card than one without and a cut sheet would not stack.
     */
    public function test_the_card_has_a_fixed_height_in_the_cr80_ratio(): void
    {
        $css = $this->css();

        // From the partial: the view's @media print block also declares
        // `.idc-card`, and a whole-file scan would match that override — which
        // carries no width or height — instead of the box rule.
        preg_match('~\.idc-card\s*\{([^}]*)\}~', $this->cardCss(), $m);
        $this->assertNotEmpty($m, '.idc-card rule not found');

        preg_match('~width\s*:\s*(\d+)px~', $m[1], $w);
        preg_match('~height\s*:\s*(\d+)px~', $m[1], $h);
        $this->assertNotEmpty($h, '.idc-card must declare a height, or cards in one '
            . 'batch come out different sizes depending on which optional fields a '
            . 'student has');

        $ratio = (int) $h[1] / (int) $w[1];
        $this->assertEqualsWithDelta(self::CR80_H / self::CR80_W, $ratio, 0.01,
            'the on-screen card must hold the CR80 portrait ratio, because the print '
            . 'rules scale it into a CR80 cell — a different ratio distorts the print');

        $this->assertStringContainsString('overflow:hidden', $m[1],
            'overflow:hidden is what stops an unusually long name pushing the geometry out');
    }

    /** The scale must land on CR80, within the tolerance of a paper cutter. */
    public function test_the_print_scale_produces_a_cr80_card(): void
    {
        $css = $this->css();

        // From the partial: the view's @media print block also declares
        // `.idc-card`, and a whole-file scan would match that override — which
        // carries no width or height — instead of the box rule.
        preg_match('~\.idc-card\s*\{([^}]*)\}~', $this->cardCss(), $m);
        preg_match('~width\s*:\s*(\d+)px~', $m[1], $w);
        preg_match('~height\s*:\s*(\d+)px~', $m[1], $h);
        preg_match('~--idc-print-scale\s*:\s*([\d.]+)~', $css, $sc);
        $this->assertNotEmpty($sc, '--idc-print-scale must be declared');

        $scale = (float) $sc[1];
        $this->assertEqualsWithDelta(self::CR80_W, self::pxToMm((float) $w[1]) * $scale, 0.5,
            'the scaled card width must be CR80, or it will not fit a badge holder');
        $this->assertEqualsWithDelta(self::CR80_H, self::pxToMm((float) $h[1]) * $scale, 0.5,
            'the scaled card height must be CR80');
    }

    // ══ 3 · it has to print in colour ════════════════════════════════════

    /**
     * The header and footer ARE the school's branding. Browsers drop background
     * colours when printing unless told otherwise, so without this the card
     * prints with white bands where the school name and validity line sit.
     *
     * This used to assert per REGION, because the page and the print window each
     * carried their own stylesheet. One source serves both now, so the property
     * is simply that the shared stylesheet asks for it.
     */
    public function test_backgrounds_survive_printing(): void
    {
        $card = $this->cardCss();

        $this->assertStringContainsString('print-color-adjust:exact', $card,
            'without this the card prints with a white band where the school name is');
        $this->assertStringContainsString('-webkit-print-color-adjust:exact', $card,
            'Safari still needs the prefixed form');
    }

    // ══ 4 · two copies of the CSS must not drift ═════════════════════════

    /**
     * IDC-CSS-1 — SUPERSEDES "the two copies agree".
     *
     * There used to be two definitions of the card: the page stylesheet and a
     * hand-maintained JS-string paraphrase in cardCSS(), kept because the
     * bulk-print window is a blank document that inherits nothing. This test
     * pinned them together — but agreement is the weaker property, and they had
     * already drifted: the copy still styled `.idc-sid*`, elements the card
     * stopped rendering when SR No. became the printed identity, while missing
     * the CR80 geometry and print-color-adjust entirely.
     *
     * Both consumers now read _id_card_css.php, so the assertion is that the
     * second definition does not come back.
     */
    public function test_there_is_only_one_definition_of_the_card(): void
    {
        $this->assertFileExists(self::PARTIAL, 'the shared card stylesheet must exist');

        $view = (string) file_get_contents(self::VIEW);

        $this->assertStringContainsString("include APPPATH . 'views/sis/_id_card_css.php'", $view,
            'the page must render the shared partial, not its own card rules');
        $this->assertStringContainsString('json_encode($IDC_CARD_CSS', $view,
            'cardCSS() must hand the print window the SAME stylesheet, encoded — '
            . 'not a paraphrase of it');

        // The old paraphrase, in any of its recognisable forms.
        $this->assertStringNotContainsString("'.idc-card{width:260px", $view,
            'the hand-maintained copy of the card CSS is back');
        $this->assertStringNotContainsString('.idc-sid', $this->cardCss(),
            'the stale .idc-sid rules are back — the card has not rendered them '
            . 'since SR No. became the printed identity');

        // Geometry declared once: the print window must not re-declare it.
        $this->assertSame(1, preg_match_all('~--idc-print-scale\s*:~', $this->css()),
            'the print scale must be declared exactly once, or a size change in '
            . 'one place prints at the old scale in the other');
    }


    // ══ 5 · the card still says what it is ═══════════════════════════════

    /** A card with no validity cannot be time-boxed or reclaimed at the gate. */
    public function test_the_card_carries_its_session_validity(): void
    {
        $this->assertMatchesRegularExpression(
            '~idc-ftr-valid[^>]*>\s*Valid:~', $this->css(),
            'the footer must print the session the card is valid for — revocation '
            . 'that is only visible to a QR scanner is not revocation at the gate'
        );
    }
}
