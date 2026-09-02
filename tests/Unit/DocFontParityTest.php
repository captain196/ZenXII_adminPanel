<?php
namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Font parity — the browser and mPDF must set in the SAME faces (P7.1/P7.2).
 *
 * `Doc_serializer` emits `font-family:lohitdeva`. mPDF has that family
 * registered via `Doc_renderer::fontData()`. THE BROWSER DOES NOT — unless the
 * serializer also emits an `@font-face` for it. Before P7.2 it did not, so the
 * preview silently reflowed in a system font while the PDF set in Lohit:
 * different metrics, different line breaks, potentially a different page count.
 *
 * G0.5 measured this class of divergence at up to 2× on Tamil, and the whole
 * "one serializer, two sinks — divergence is a bug" rule exists to stop it.
 *
 * A family registered on one side only is that same silent fallback wearing a
 * new disguise, so these tests pin the two tables together and pin both to the
 * files actually on disk.
 */
class DocFontParityTest extends TestCase
{
    private static array $serializerFaces;
    private static array $rendererFamilies;

    public static function setUpBeforeClass(): void
    {
        foreach (['BASEPATH' => __DIR__, 'FCPATH' => __DIR__ . '/', 'APPPATH' => __DIR__ . '/'] as $k => $v) {
            if (!defined($k)) {
                define($k, $v);
            }
        }
        require_once __DIR__ . '/../../application/libraries/Doc_serializer.php';
        require_once __DIR__ . '/../../application/libraries/Doc_renderer.php';

        $rc = new ReflectionClass(\Doc_serializer::class);
        self::$serializerFaces = $rc->getConstant('FONT_FACES');

        // fontData() is private and does not touch instance state, so it can be
        // read without constructing (the constructor wants CI and a temp dir).
        $rr = new ReflectionClass(\Doc_renderer::class);
        $m  = $rr->getMethod('fontData');
        self::$rendererFamilies = $m->invoke($rr->newInstanceWithoutConstructor());
    }

    /** The two tables must name the same families. */
    public function test_the_serializer_and_the_renderer_declare_the_same_families(): void
    {
        $preview = array_keys(self::$serializerFaces);
        $pdf     = array_keys(self::$rendererFamilies);
        sort($preview);
        sort($pdf);

        $this->assertSame(
            $pdf,
            $preview,
            "Font families have drifted.\n"
            . 'Only in the PDF path: ' . implode(', ', array_diff($pdf, $preview)) . "\n"
            . 'Only in the preview:  ' . implode(', ', array_diff($preview, $pdf)) . "\n"
            . 'A family on one side only means the preview and the print use different faces.'
        );
    }

    /** And the same FILE for each family — same name, different TTF is worse. */
    public function test_each_family_maps_to_the_same_ttf_on_both_sides(): void
    {
        foreach (self::$serializerFaces as $family => $file) {
            $this->assertSame(
                $file,
                self::$rendererFamilies[$family]['R'] ?? null,
                "Family '$family' maps to a different file in the preview than in the PDF"
            );
        }
    }

    /** Both are useless if the file is not actually there. */
    public function test_every_declared_face_exists_on_disk(): void
    {
        $dir = __DIR__ . '/../../assets/fonts/lohit';
        foreach (self::$serializerFaces as $family => $file) {
            $this->assertFileExists("$dir/$file", "'$family' declares $file, which is not on disk");
            $this->assertGreaterThan(1000, filesize("$dir/$file"), "$file looks truncated");
        }
    }

    /**
     * Lohit ships Regular only — mPDF SYNTHESISES bold. Recorded as a test so
     * the limitation is discovered here rather than at UAT on a certificate
     * whose heading was supposed to be bold Devanagari.
     */
    public function test_no_family_claims_a_bold_face_it_does_not_have(): void
    {
        foreach (self::$rendererFamilies as $family => $spec) {
            $this->assertArrayNotHasKey(
                'B',
                $spec,
                "'$family' declares a bold face. Lohit ships Regular only; mPDF "
                . 'synthesises bold, and claiming a real B file would fail at registration.'
            );
        }
    }
}
