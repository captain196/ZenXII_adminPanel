<?php
namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * The designer ships a page of absolutely positioned divs into a panel that
 * loads Bootstrap and AdminLTE globally. Scoping every rule under `.zxdt`
 * raises specificity — so every property we DECLARE wins — but a property we
 * never mention is never overridden, and the global one silently applies.
 *
 * That is not hypothetical. Two were found on the first live run:
 *
 *   .row    bootstrap sets `margin:0 -15px`. We set display, columns, gap and
 *           margin-bottom, but never margin-left — so every inspector row sat
 *           3px outside its panel and the first character of each left-column
 *           label was sliced off. "NAME" rendered as "AME".
 *
 *   .modal  bootstrap sets `position:fixed;top:0;right:0;bottom:0;left:0;
 *           z-index:1050;display:none`. We override display, so dialogs opened
 *           and the bug hid in plain sight — but every dialog was pinned to the
 *           TOP-LEFT CORNER of the viewport instead of centred in its scrim.
 *
 * The E2E harness cannot catch either, because the harness page does not load
 * Bootstrap. This test is the guard that can.
 *
 * @see CLAUDE.md — the `.att-grid` collision is the precedent
 */
class DocCssCollisionTest extends TestCase
{
    /** Properties a global utility can set that will visibly move our layout. */
    private const DANGEROUS = ['margin', 'position', 'z-index', 'float', 'display'];

    private const GLOBAL_SHEETS = [
        'tools/bower_components/bootstrap/dist/css/bootstrap.min.css',
        'tools/dist/css/AdminLTE.min.css',
    ];

    private function root(): string { return __DIR__ . '/../../'; }

    /**
     * For every class the designer shares with a global sheet, the designer's
     * own rule must neutralise each dangerous property the global one sets.
     */
    public function test_shared_class_names_reset_the_properties_the_globals_set(): void
    {
        $css = file_get_contents($this->root() . 'assets/css/doctemplates.css');
        preg_match_all('/\.zxdt \.([a-z][a-z0-9_-]*)\s*\{/', $css, $m);
        $mine = array_unique($m[1]);

        $problems = [];

        foreach (self::GLOBAL_SHEETS as $rel) {
            $path = $this->root() . $rel;
            if (!is_file($path)) {
                continue;                       // sheet not vendored in this checkout
            }
            $global = file_get_contents($path);

            foreach ($mine as $cls) {
                // The global rule for exactly this class, on its own.
                if (!preg_match('/(?:^|[},])\.' . preg_quote($cls, '/') . '\{([^}]*)\}/', $global, $g)) {
                    continue;
                }
                // What we declare for it.
                if (!preg_match('/\.zxdt \.' . preg_quote($cls, '/') . '\s*\{([^}]*)\}/s', $css, $o)) {
                    continue;
                }

                foreach (self::DANGEROUS as $prop) {
                    $globalSets = (bool) preg_match('/(?:^|;)\s*' . $prop . '\s*:/', $g[1]);
                    $weReset    = (bool) preg_match('/(?:^|;|\s)' . $prop . '\s*:/', $o[1]);
                    if ($globalSets && !$weReset) {
                        $problems[] = ".$cls — " . basename($rel) . " sets '$prop', "
                                    . '.zxdt .' . $cls . ' does not reset it';
                    }
                }
            }
        }

        $this->assertSame([], $problems,
            "CSS collision(s). A generic class name shared with a global utility inherits "
            . "every property we do not explicitly set:\n  - " . implode("\n  - ", $problems)
            . "\n\nEither reset the property under .zxdt, or rename the class.");
    }

    /** The two that were actually broken, pinned by name so they cannot come back. */
    public function test_row_and_modal_neutralise_bootstrap_explicitly(): void
    {
        $css = file_get_contents($this->root() . 'assets/css/doctemplates.css');

        preg_match('/\.zxdt \.row\s*\{([^}]*)\}/', $css, $row);
        $this->assertNotEmpty($row, '.zxdt .row rule not found');
        $this->assertMatchesRegularExpression('/margin\s*:/', $row[1],
            ".zxdt .row must set margin — bootstrap's .row pulls it 15px left, which "
            . 'sliced the first character off every left-column label');

        preg_match('/\.zxdt \.modal\s*\{([^}]*)\}/', $css, $modal);
        $this->assertNotEmpty($modal, '.zxdt .modal rule not found');
        foreach (['position', 'z-index'] as $p) {
            $this->assertMatchesRegularExpression('/' . $p . '\s*:/', $modal[1],
                ".zxdt .modal must set $p — bootstrap pins .modal to the viewport corner "
                . 'with position:fixed and z-index:1050');
        }
    }

    /**
     * The designer must live inside `.content-wrapper`, like every other view.
     *
     * It did not, and the symptom read as a z-index problem when it was not:
     * `#zxdt-root` was a direct child of `.wrapper`, a SIBLING of the fixed
     * header and the fixed sidebar. Both are out of flow, so nothing pushed the
     * designer down or across — it started at 0,0 and the panel chrome sat on
     * top of it. The designer's own topbar (Proof PDF, Publish, History)
     * rendered at y=0..52 underneath a 58px header and was unreachable, the
     * layers rail sat under the 248px sidebar, and the left tab strip
     * (Layers/Insert/Fields/Blocks/Content) was hidden entirely.
     */
    public function test_the_designer_view_sits_inside_the_panel_content_wrapper(): void
    {
        $view = file_get_contents(__DIR__ . '/../../application/views/doc_templates/index.php');

        $wrapAt = strpos($view, 'class="content-wrapper');
        $rootAt = strpos($view, 'id="zxdt-root"');

        $this->assertNotFalse($wrapAt,
            'doc_templates/index.php does not open a .content-wrapper. Every other view in '
            . 'this panel does, and it is what applies the fixed header and sidebar offsets.');
        $this->assertNotFalse($rootAt, '#zxdt-root not found');
        $this->assertLessThan($rootAt, $wrapAt,
            '.content-wrapper must OPEN before #zxdt-root, or the designer is not inside it');
        $this->assertStringContainsString('/.content-wrapper', $view,
            '.content-wrapper is opened but never closed');
    }

    /**
     * The designer sizes to the CONTENT AREA, not the window. A bare 100vh was
     * right when this was a standalone prototype and is 58px too tall inside
     * the panel — it pushed the status bar off the bottom of the screen.
     */
    public function test_the_designer_shell_excludes_the_panel_chrome_from_its_height(): void
    {
        $css = file_get_contents(__DIR__ . '/../../assets/css/doctemplates.css');

        preg_match('/\.zxdt \.app\{([^}]*)\}/', $css, $m);
        $this->assertNotEmpty($m, '.zxdt .app rule not found');
        $this->assertStringNotContainsString('height:100vh', $m[1],
            '.zxdt .app uses the full viewport height. Inside the panel that is taller than '
            . 'the content area by the fixed header, and the status bar falls off the screen.');
        $this->assertStringContainsString('--zx-chrome', $m[1],
            'the shell height must subtract the panel chrome via --zx-chrome');
    }
}
