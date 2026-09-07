<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * The designer canvas is a THIRD image-render path, and it had no guard.
 *
 * `Doc_renderer::guardImages()` guards the PDF. `Doc_serializer::guardSrc()`
 * guards the preview — its own doc-comment explains that the preview never
 * passes through the renderer, which is exactly why it needs a second copy of
 * the rule. The live canvas in `designer.js` draws straight from the in-memory
 * template and reaches neither of them, so it needed a third.
 *
 * Two defects were proven against live Firestore before this guard existed:
 *
 *  1. STORED XSS / PRIVILEGE ESCALATION. `content.src` survives `save()` byte
 *     for byte — `boundObject()` clamps geometry and type size and nothing
 *     else — and was interpolated raw into `src="..."`. `seal.png" onerror="…`
 *     closes the attribute. An EDIT-grade user plants it; a MANAGE-grade user
 *     runs it merely by opening the template, in their own session, with their
 *     own CSRF token, one same-origin fetch away from publish/activate/archive/
 *     delete. That is the boundary the edit/manage split exists to hold.
 *
 *  2. OFF-SITE FETCH. A scheme-qualified or protocol-relative src made the
 *     canvas request a third party from the school's browser, from a document
 *     nobody thinks of as networked.
 *
 * These are static assertions over the shipped asset, in the style of
 * DocCssCollisionTest: the guard is a property of the source, and a behavioural
 * test would need a DOM the suite does not have.
 *
 * qa/certificates/06-uat-matrix.csv T2-24, T2-54, T2-55
 */
final class DocCanvasSrcGuardTest extends TestCase
{
    private static string $js;

    public static function setUpBeforeClass(): void
    {
        $js = @file_get_contents(__DIR__ . '/../../assets/js/doctemplates/designer.js');
        self::assertNotFalse($js, 'designer.js is unreadable — the canvas guard cannot be checked');
        self::$js = (string) $js;
    }

    public function test_the_canvas_declares_its_own_image_source_guard(): void
    {
        $this->assertMatchesRegularExpression(
            '/function\s+safeAssetSrc\s*\(/',
            self::$js,
            'The canvas has no image-source guard. It is a third render path and cannot '
            . 'borrow the serializer\'s or the renderer\'s.'
        );
    }

    /** Whatever the guard rejects, it must reject for the same reasons the server does. */
    public function test_the_guard_refuses_every_shape_the_server_refuses(): void
    {
        $fn = $this->guardBody();
        $this->assertStringContainsString('a-z0-9+.', $fn, 'no scheme test — javascript: and data: carry no "//"');
        $this->assertStringContainsString('//',      $fn, 'no protocol-relative test');
        $this->assertStringContainsString('..',      $fn, 'no parent-directory test');
    }

    /** The one the server does NOT need, because only the canvas builds an attribute. */
    public function test_the_guard_refuses_a_value_that_could_close_the_attribute(): void
    {
        $this->assertMatchesRegularExpression(
            '/\[[^\]]*"[^\]]*\]/',
            $this->guardBody(),
            'The guard does not reject quotes. A quote in content.src closes the src attribute, '
            . 'which is the stored-XSS path this class exists for.'
        );
    }

    public function test_no_image_src_is_interpolated_unescaped(): void
    {
        $bad = [];
        if (preg_match_all('/src="\$\{([^}]*)\}"/', self::$js, $m)) {
            foreach ($m[1] as $expr) {
                if (!str_contains($expr, 'esc(')) {
                    $bad[] = trim($expr);
                }
            }
        }
        $this->assertSame(
            [],
            $bad,
            "An image src is built without esc():\n  " . implode("\n  ", $bad)
            . "\nA src attribute is a string the browser parses; anything user-controlled in it "
            . 'must be escaped even when it has already been shape-checked.'
        );
    }

    /**
     * The guard is load-bearing precisely because the server does not sanitise
     * on the way in — proven live: a hostile src round-trips through save()
     * unchanged. If that ever becomes untrue this test should be revisited, not
     * deleted: defence in depth is the point.
     */
    public function test_save_still_does_not_sanitise_content_src(): void
    {
        $php = (string) file_get_contents(
            __DIR__ . '/../../application/libraries/Doc_template_service.php'
        );
        $this->assertMatchesRegularExpression(
            '/private function boundObject/',
            $php,
            'boundObject() is gone — re-check what now normalises objects on save.'
        );
        $body = $this->slice($php, 'private function boundObject');
        $this->assertStringNotContainsString(
            "content", $body,
            'boundObject() now touches content — if it sanitises src, say so here and keep '
            . 'the canvas guard anyway.'
        );
    }

    private function guardBody(): string
    {
        return $this->slice(self::$js, 'function safeAssetSrc');
    }

    /** The declaration plus whatever follows it, which is enough for these checks. */
    private function slice(string $hay, string $needle): string
    {
        $i = strpos($hay, $needle);
        $this->assertNotFalse($i, "'$needle' not found");
        return substr($hay, $i, 1400);
    }
}
