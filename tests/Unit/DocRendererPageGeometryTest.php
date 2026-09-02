<?php
namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Doc_renderer;
use ReflectionClass;

/**
 * Doc_renderer — page geometry, and the overflow gate that depends on it.
 *
 * REGRESSION. `wouldOverflow()` decides whether an over-long field silently
 * clips the signature block off a Transfer Certificate. It computed the page
 * height as:
 *
 *     is_array($cfg['format']) ? $cfg['format'][1]
 *                              : ($orientation === 'L' ? 210.0 : 297.0)  // A4 default
 *
 * but `self::PAPER` maps names to STRINGS ('A4' => 'A4'), so `is_array()` was
 * false for every named size and the A4 fallback ran every time. Three of the
 * four supported papers got the wrong height, and the dangerous direction is
 * "too lenient" — on A5 the gate passed content 87mm past the end of the page.
 *
 * These tests pin the real dimensions so the fallback cannot come back.
 *
 * The constructor needs a CI instance and a writable temp dir, and none of that
 * is relevant to pure geometry, so the object is built WITHOUT the constructor
 * and the private method is invoked directly. That keeps the test honest about
 * what it covers: arithmetic, not rendering.
 */
class DocRendererPageGeometryTest extends TestCase
{
    private \ReflectionMethod $height;
    private Doc_renderer $r;

    public static function setUpBeforeClass(): void
    {
        if (!defined('BASEPATH')) {
            define('BASEPATH', __DIR__);
        }
        if (!defined('FCPATH')) {
            define('FCPATH', __DIR__ . '/');
        }
        if (!defined('APPPATH')) {
            define('APPPATH', __DIR__ . '/');
        }
        require_once __DIR__ . '/../../application/libraries/Doc_renderer.php';
    }

    protected function setUp(): void
    {
        $rc = new ReflectionClass(Doc_renderer::class);
        $this->r = $rc->newInstanceWithoutConstructor();
        // No setAccessible() — it has been a no-op since PHP 8.1 and is
        // deprecated in 8.5, which this repo runs. Calling it emits a notice on
        // every suite run.
        $this->height = $rc->getMethod('pageHeightMm');
    }

    private function h(string|array $format, string $orientation = 'P'): float
    {
        return $this->height->invoke($this->r, ['format' => $format, 'orientation' => $orientation]);
    }

    /** The four supported papers, portrait. A5/Letter/Legal all used to be wrong. */
    public function test_named_paper_heights_are_real_not_an_a4_default(): void
    {
        $this->assertSame(297.0, $this->h('A4'));
        $this->assertSame(210.0, $this->h('A5'),     'A5 was reading 297 — 87mm too lenient');
        $this->assertSame(279.4, $this->h('Letter'), 'Letter was reading 297');
        $this->assertSame(355.6, $this->h('Legal'),  'Legal was reading 297 — too strict, false positives');
    }

    /** Landscape swaps the axes: height becomes the SHORT edge. */
    public function test_landscape_uses_the_short_edge(): void
    {
        $this->assertSame(210.0, $this->h('A4', 'L'));
        $this->assertSame(148.0, $this->h('A5', 'L'));
        $this->assertSame(215.9, $this->h('Letter', 'L'));
        $this->assertSame(215.9, $this->h('Legal', 'L'));
    }

    /** The custom [w,h] branch was always correct and must stay correct. */
    public function test_custom_dimensions_still_work_in_both_orientations(): void
    {
        $this->assertSame(400.0, $this->h([300.0, 400.0]));
        $this->assertSame(300.0, $this->h([300.0, 400.0], 'L'));
    }

    /** Case-insensitive, because pageConfig() upper-cases before lookup. */
    public function test_lookup_is_case_insensitive(): void
    {
        $this->assertSame($this->h('A4'), $this->h('a4'));
        $this->assertSame($this->h('Letter'), $this->h('LETTER'));
    }

    /**
     * An unknown name must not silently become something taller than the real
     * page — that is the failure direction that clips content away.
     */
    public function test_an_unknown_name_falls_back_to_a4_rather_than_something_larger(): void
    {
        $this->assertSame(297.0, $this->h('Tabloid'));
    }

    /** PAPER and PAPER_MM must stay in step — they are two halves of one fact. */
    public function test_every_supported_paper_has_real_dimensions(): void
    {
        foreach (array_keys(Doc_renderer::PAPER) as $key) {
            $this->assertArrayHasKey(
                $key,
                Doc_renderer::PAPER_MM,
                "PAPER lists '$key' but PAPER_MM has no dimensions for it — the overflow "
                . 'gate would fall back to A4 for that size'
            );
        }
    }
}
