<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * The server and the designer must agree on what a minimal `page` is.
 *
 * `create()` fell back to `['size'=>'A4','orientation'=>'portrait']` with no
 * `marginsMm`. The designer's `adoptTemplate()` then did
 *
 *     S.tpl = Object.assign(starterTC(), t);
 *
 * and Object.assign is SHALLOW — so the stored page replaced the starter's page
 * wholesale and took its margins with it. Eight places read
 * `S.tpl.page.marginsMm.t` / `.l` directly, so opening such a template threw
 * "Cannot read properties of undefined" and the editor rendered NOTHING: a blank
 * screen, with the failure only in the console.
 *
 * Reproduced live by creating a template through the API and opening it.
 *
 * No current UI path produced one — `createOnServer()` sends the client's own
 * page, and every starter and `blankTemplate()` carries margins — which is
 * exactly why it survived: the crash needed a template the UI never makes, and
 * the server's own default was the one thing that made them.
 *
 * Fixed at both ends: the server default carries margins, and adoptTemplate()
 * merges `page` deeply instead of replacing it.
 *
 * qa/certificates/06-uat-matrix.csv T1-01
 */
final class DocPageShapeTest extends TestCase
{
    private static string $php;
    private static string $js;

    public static function setUpBeforeClass(): void
    {
        $php = @file_get_contents(__DIR__ . '/../../application/libraries/Doc_template_service.php');
        $js  = @file_get_contents(__DIR__ . '/../../assets/js/doctemplates/designer.js');
        self::assertNotFalse($php, 'Doc_template_service.php unreadable');
        self::assertNotFalse($js, 'designer.js unreadable');
        self::$php = (string) $php;
        self::$js  = (string) $js;
    }

    /** The server must not invent a page the client cannot draw. */
    public function test_the_default_page_carries_margins(): void
    {
        $at = strpos(self::$php, "'page'             => \$seed['page']");
        $this->assertNotFalse($at, "create()'s page default is gone — re-check this contract");
        $slice = substr(self::$php, $at, 500);
        $this->assertStringContainsString('marginsMm', $slice,
            "The server's default page has no marginsMm. The designer reads page.marginsMm.l "
            . 'directly in several places, so this default produces a template that renders a '
            . 'blank editor.');
    }

    /** And the client must survive one that does not, whatever the server sends. */
    public function test_adopting_a_template_merges_page_rather_than_replacing_it(): void
    {
        $at = strpos(self::$js, 'function adoptTemplate');
        $this->assertNotFalse($at, 'adoptTemplate() is gone');
        $body = substr(self::$js, $at, 2200);

        $this->assertMatchesRegularExpression(
            '/S\.tpl\.page\s*=\s*Object\.assign\(\s*\{\}/',
            $body,
            'adoptTemplate() no longer merges page onto a fresh object. A shallow '
            . 'Object.assign(starterTC(), t) lets a stored page replace the starter\'s '
            . 'wholesale and drop marginsMm with it.'
        );
        $this->assertStringContainsString('marginsMm', $body,
            'adoptTemplate() does not normalise marginsMm, so a page without them reaches '
            . 'the eight readers that dereference it.');
    }

    /**
     * The renderer keeps its own guard, because its failure mode is the worst one
     * available: not a wrong number, but nothing on screen at all.
     */
    public function test_the_page_renderer_tolerates_absent_margins(): void
    {
        $at = strpos(self::$js, 'function layoutPage');
        $this->assertNotFalse($at, 'layoutPage() is gone');
        $body = substr(self::$js, $at, 1600);
        $this->assertMatchesRegularExpression(
            '/Object\.assign\(\s*\{\s*t:\s*15/',
            $body,
            'layoutPage() dereferences margins with no fallback. Every other reader shows a '
            . 'wrong number when this is missing; this one shows an empty editor.'
        );
    }
}
