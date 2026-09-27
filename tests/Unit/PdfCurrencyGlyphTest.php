<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * RT-3 / RT-4 / RT-5 — the rupee sign in dompdf-rendered documents.
 *
 * THE TRAP. dompdf resolves `font-family: sans-serif` to the PDF core font
 * Helvetica, which is WinAnsi-encoded and cannot represent U+20B9. dompdf does
 * not warn: it substitutes a literal '?'. Every payslip generated to date
 * therefore read
 *
 *     Net Pay: ? 71,940.25
 *
 * which is not something reading the source tells you — the source says '₹',
 * the PDF says '?'. It was found by decoding the PDF content stream, and the
 * fix was confirmed the same way.
 *
 * THE FIX. Wrap the symbol alone in a span whose font-family is 'DejaVu Sans'
 * — a font dompdf bundles and which DOES carry U+20B9 (glyph 2959, confirmed
 * in DejaVuSans.ufm). Scoping it to the symbol embeds a ~4KB subset while
 * every other glyph stays on Helvetica, so no text metric elsewhere in the
 * document moves. Switching a whole payslip to DejaVu would have reflowed a
 * document schools already rely on.
 *
 * These tests guard the property for every dompdf-bound renderer, so the next
 * one to print money does not silently reintroduce the '?'.
 */
final class PdfCurrencyGlyphTest extends TestCase
{
    /** U+20B9, built without writing the literal byte into this file. */
    private const RUPEE = "\u{20B9}";

    /**
     * Renderers whose HTML is handed to dompdf, and therefore subject to the
     * core-font limitation. Web views and HTML e-mail are NOT in this list:
     * a browser and a mail client both have the glyph.
     */
    private const PDF_RENDERERS = [
        'application/controllers/Compensation.php' => '_render_settlement_html',
        'application/controllers/Hr.php'           => '_render_payslip_html',
    ];

    private static function methodBody(string $file, string $method): string
    {
        $src = (string) file_get_contents(__DIR__ . '/../../' . $file);
        $i = strpos($src, 'private function ' . $method);
        if ($i === false) {
            throw new RuntimeException("{$method} not found in {$file} — this test's locator is stale");
        }
        $j = strpos($src, "\n    }\n", $i);

        return substr($src, $i, ($j === false ? strlen($src) : $j) - $i);
    }

    // ── The property ─────────────────────────────────────────────────────

    /**
     * THE CENTRAL TEST. In a dompdf renderer, every rupee sign is inside the
     * scoped span. A bare one is the defect, and it renders as '?'.
     */
    public function test_every_rupee_in_a_pdf_renderer_is_font_scoped(): void
    {
        foreach (self::PDF_RENDERERS as $file => $method) {
            $body = self::methodBody($file, $method);

            $total  = substr_count($body, self::RUPEE);
            $scoped = substr_count($body, '<span class="cur">' . self::RUPEE . '</span>');

            $this->assertSame($total, $scoped,
                "{$file}::{$method} emits a rupee sign outside <span class=\"cur\">. "
                . 'dompdf will substitute a literal "?" for it.');
        }
    }

    /** And the class it relies on is actually defined, with a font that has the glyph. */
    public function test_the_scoped_class_is_defined_with_a_capable_font(): void
    {
        foreach (self::PDF_RENDERERS as $file => $method) {
            $body = self::methodBody($file, $method);

            if (substr_count($body, self::RUPEE) === 0) {
                continue;                       // nothing to denominate
            }

            $this->assertStringContainsString('.cur{font-family:"DejaVu Sans"}', $body,
                "{$file}::{$method} uses class=\"cur\" but never defines it — "
                . 'an undefined class inherits sans-serif and the glyph is lost again');
        }
    }

    /**
     * The claim the fix rests on, checked against the shipped font rather than
     * taken on trust: dompdf's DejaVu metrics list U+20B9.
     *
     * Note the `U ` prefix — an earlier version of this check looked for `C `
     * and reported the glyph missing from every font, including for a control
     * character that was plainly present. The control is kept for that reason.
     */
    public function test_the_bundled_font_really_carries_the_glyph(): void
    {
        $dir = __DIR__ . '/../../vendor/dompdf/dompdf/lib/fonts/';

        foreach (['DejaVuSans.ufm', 'DejaVuSans-Bold.ufm'] as $ufm) {
            if (!is_file($dir . $ufm)) {
                $this->markTestSkipped("{$ufm} not present — dompdf layout differs from the verified one");
            }

            $m = (string) file_get_contents($dir . $ufm);

            // CONTROL first: if this fails the assertion below proves nothing.
            $this->assertStringContainsString('U 8364 ;', $m,
                "control failed: {$ufm} does not list the euro sign, so this check cannot be trusted");

            $this->assertStringContainsString('U 8377 ;', $m,
                "{$ufm} does not carry U+20B9 — the scoped-span fix would not render");
        }
    }

    // ── Denomination and the blank-row idiom ─────────────────────────────

    /** The settlement denominates its amounts, as the payslip beside it does. */
    public function test_the_settlement_denominates_its_amounts(): void
    {
        $body = self::methodBody(
            'application/controllers/Compensation.php', '_render_settlement_html');

        $this->assertStringContainsString('$money = static function', $body);
        $this->assertStringContainsString(self::RUPEE, $body,
            'a money document that states no unit leaves the reader to assume one');
    }

    /**
     * RT-4. A labelled row with an empty cell reads as missing data. The
     * document already uses an em dash for exactly this in the history table.
     */
    public function test_absent_values_are_dashed_not_left_blank(): void
    {
        $body = self::methodBody(
            'application/controllers/Compensation.php', '_render_settlement_html');

        $this->assertStringContainsString('$orDash = static function', $body);
        $this->assertStringContainsString("\$orDash(\$e(\$comp['computedAt'] ?? ''))", $body);
    }

    // ── Scope ────────────────────────────────────────────────────────────

    /**
     * The fix stays scoped. Setting DejaVu on `body` would re-metric every
     * glyph in a payslip schools already use, which is a different and much
     * larger change than making one symbol render.
     */
    public function test_the_body_font_is_left_alone(): void
    {
        foreach (self::PDF_RENDERERS as $file => $method) {
            $body = self::methodBody($file, $method);

            $this->assertStringContainsString('body{font-family:sans-serif', $body,
                "{$file}::{$method} changed its body font — every text metric in the "
                . 'document shifts, which this fix deliberately avoided');
        }
    }
}
