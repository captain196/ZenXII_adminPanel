<?php
namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Doc_serializer;

/**
 * Doc_serializer — GOLDEN FILES (P2.8).
 *
 * DocSerializerTest pins the emission rules I thought to assert. This pins
 * everything else: byte-for-byte output for one rich fixture that exercises
 * every §5.4 rule at once — absolute object, three-deep anchor chain, table
 * with a merge field, shape, pageNumber in the footer band, header/footer
 * regions, escaping, and a showWhen-gated duplicate mark.
 *
 * The two layers answer different questions and neither replaces the other:
 * an assertion test says "the rule I named still holds"; a golden file says
 * "NOTHING changed that I did not intend" — including whitespace, attribute
 * order, and unit formatting, all of which move rendered output.
 *
 * The serializer feeds the browser preview and mPDF from one string, so an
 * unintended byte is a preview/print divergence waiting to happen.
 *
 * REGENERATING, deliberately awkward:
 *     ZXDT_GOLDEN_UPDATE=1 vendor/bin/phpunit --testsuite Unit --filter Golden
 * Then READ THE DIFF before committing. A golden file regenerated without
 * reading the diff records the bug as the new truth and the test goes green
 * forever after.
 */
class DocSerializerGoldenTest extends TestCase
{
    private const DIR = __DIR__ . '/../doctemplates/golden';

    private Doc_serializer $s;

    public static function setUpBeforeClass(): void
    {
        if (!defined('BASEPATH')) {
            define('BASEPATH', __DIR__);
        }
        require_once __DIR__ . '/../../application/libraries/Doc_serializer.php';
        if (!is_dir(self::DIR)) {
            mkdir(self::DIR, 0755, true);
        }
    }

    protected function setUp(): void
    {
        $this->s = new Doc_serializer();
    }

    /* ---------------------------------------------------------------- */

    private function contract(): array
    {
        return [
            'school.name'          => ['label' => 'School name', 'sample' => 'DPS Ranchi'],
            'school.affiliationNo' => ['label' => 'Affiliation', 'sample' => '3430006'],
            'student.fullName'     => ['label' => 'Student name', 'sample' => 'Aarav Sharma'],
            'tc.reasonForLeaving'  => ['label' => 'Reason', 'sample' => 'Parent transferred',
                                       'p95' => 'Parent transferred out of station on Government service'],
            'doc.issueDate'        => ['label' => 'Issue date', 'sample' => '04/04/2026'],
        ];
    }

    /** One fixture exercising every §5.4 emission rule. */
    private function fixture(): array
    {
        $st = fn(float $lh = 1.4, int $pt = 10) =>
            ['sizePt' => $pt, 'lineHeight' => $lh, 'weight' => 400, 'align' => 'left', 'colour' => '#14100D'];

        return [
            'templateId' => 'TPL0007',
            'docType'    => 'transfer_certificate',
            'languages'  => ['en'],
            'pageMode'   => 'single',
            'page'       => ['size' => 'A4', 'orientation' => 'portrait',
                             'marginsMm' => ['t' => 15, 'r' => 15, 'b' => 16, 'l' => 15]],
            'objects'    => [
                // header region — absolute, auto height
                ['id' => 'h_name', 'type' => 'text', 'region' => 'header',
                 'xMm' => 40, 'yMm' => 9, 'wMm' => 155, 'hMm' => 9, 'z' => 2, 'height' => 'auto',
                 'style' => ['sizePt' => 16, 'lineHeight' => 1.2, 'weight' => 700, 'align' => 'left'],
                 'content' => ['i18n' => ['en' => ['runs' => [['f' => 'school.name']]]]]],

                ['id' => 'h_rule', 'type' => 'shape', 'region' => 'header',
                 'xMm' => 15, 'yMm' => 33, 'wMm' => 180, 'hMm' => 0.6, 'z' => 1, 'height' => 'fixed',
                 'content' => ['shape' => 'line'], 'style' => ['colour' => '#14100D']],

                // body — a three-deep anchor chain with escaping and formatting
                ['id' => 'title', 'type' => 'text', 'xMm' => 15, 'yMm' => 46, 'wMm' => 180,
                 'hMm' => 8, 'z' => 3, 'height' => 'auto',
                 'style' => ['sizePt' => 14, 'lineHeight' => 1.25, 'weight' => 700,
                             'align' => 'center', 'track' => '.14em'],
                 'content' => ['i18n' => ['en' => ['runs' => [['t' => 'TRANSFER CERTIFICATE']]]]]],

                ['id' => 'line1', 'type' => 'text', 'anchorTo' => 'title', 'anchorGapMm' => 4,
                 'wMm' => 180, 'z' => 3, 'height' => 'auto', 'style' => $st(),
                 'content' => ['i18n' => ['en' => ['runs' => [
                     ['t' => 'Name: '], ['f' => 'student.fullName'],
                     ['t' => "  <flagged & escaped>\nsecond line", 'b' => true],
                 ]]]]],

                ['id' => 'line2', 'type' => 'text', 'anchorTo' => 'line1', 'anchorGapMm' => 6,
                 'wMm' => 180, 'z' => 3, 'height' => 'auto', 'maxHMm' => 34, 'style' => $st(1.45),
                 'content' => ['i18n' => ['en' => ['runs' => [
                     ['t' => 'Reason: '], ['f' => 'tc.reasonForLeaving'],
                 ]]]]],

                // table with a merge field in a cell
                ['id' => 'tbl', 'type' => 'table', 'xMm' => 15, 'yMm' => 120, 'wMm' => 180,
                 'z' => 3, 'height' => 'auto', 'style' => ['sizePt' => 9, 'lineHeight' => 1.55],
                 'content' => ['rows' => [
                     [['wPct' => 40, 'i18n' => ['en' => ['runs' => [['t' => 'Affiliation No.']]]]],
                      ['wPct' => 60, 'i18n' => ['en' => ['runs' => [['f' => 'school.affiliationNo']]]]]],
                 ]]],

                // statutory duplicate mark — KER r.22 / TNER r.44 / CBSE r.8(vi)
                ['id' => 'dup', 'type' => 'text', 'xMm' => 120, 'yMm' => 50, 'wMm' => 70,
                 'z' => 9, 'height' => 'auto', 'showWhen' => 'doc.isDuplicate',
                 'style' => ['sizePt' => 12, 'lineHeight' => 1.2, 'weight' => 700,
                             'align' => 'right', 'colour' => '#A8322A'],
                 'content' => ['i18n' => ['en' => ['runs' => [['t' => 'DUPLICATE']]]]]],

                // footer band
                ['id' => 'pn', 'type' => 'pageNumber', 'region' => 'footer',
                 'xMm' => 100, 'yMm' => 285, 'wMm' => 20, 'z' => 1, 'height' => 'auto'],
            ],
        ];
    }

    private function assertGolden(string $name, string $actual): void
    {
        $path = self::DIR . '/' . $name . '.html';

        if (getenv('ZXDT_GOLDEN_UPDATE')) {
            file_put_contents($path, $actual);
            $this->addToAssertionCount(1);
            return;
        }

        $this->assertFileExists($path,
            "Golden file missing. Generate it with:\n"
            . "  ZXDT_GOLDEN_UPDATE=1 vendor/bin/phpunit --testsuite Unit --filter Golden\n"
            . 'then READ THE DIFF before committing.');

        $this->assertSame(file_get_contents($path), $actual,
            "Serialized output changed for '$name'.\n"
            . "If the change is intended, regenerate with ZXDT_GOLDEN_UPDATE=1 and read the diff.\n"
            . 'If it is not, the serializer just changed what a certificate prints.');
    }

    /* ---------------------------------------------------------------- */

    public function test_typical_sample_render_matches_golden(): void
    {
        $this->assertGolden('tc_typical', $this->s->render(
            $this->fixture(), [], 'en', ['contract' => $this->contract(), 'sample' => 'typical']
        ));
    }

    /** p95 must move real bytes, or the stress mode is decorative. */
    public function test_p95_stress_render_matches_golden(): void
    {
        $this->assertGolden('tc_p95', $this->s->render(
            $this->fixture(), [], 'en', ['contract' => $this->contract(), 'sample' => 'p95']
        ));
    }

    /** The duplicate mark is the one place a statute specifies RENDERING. */
    public function test_duplicate_render_matches_golden(): void
    {
        $this->assertGolden('tc_duplicate', $this->s->render(
            $this->fixture(), [], 'en',
            ['contract' => $this->contract(), 'sample' => 'typical', 'isDuplicate' => true]
        ));
    }

    /**
     * Guards the guard: if typical and p95 ever produce identical bytes, the
     * sample machinery has silently stopped switching and all three goldens
     * would still pass.
     */
    public function test_the_three_goldens_are_genuinely_different(): void
    {
        $t = file_get_contents(self::DIR . '/tc_typical.html');
        $p = file_get_contents(self::DIR . '/tc_p95.html');
        $d = file_get_contents(self::DIR . '/tc_duplicate.html');

        $this->assertNotSame($t, $p, 'p95 renders identically to typical — the stress mode is inert');
        $this->assertNotSame($t, $d, 'the duplicate mark did not change the output');
        $this->assertStringContainsString('DUPLICATE', $d);
        $this->assertStringNotContainsString('DUPLICATE', $t);
    }
}
