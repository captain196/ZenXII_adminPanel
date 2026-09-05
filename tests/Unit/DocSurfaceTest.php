<?php
namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Doc_template_service;

/**
 * T3 surface rows that are structurally checkable.
 *
 * Most of T3 is a judgement — is this understandable, does a clerk grasp "published but not
 * active". A machine cannot answer those and should not pretend to. But several rows are
 * factual: is every colour token themed, do the two dark-mode paths agree, is a declared
 * HTML `min` actually enforced anywhere. Those are checked here; the rest stay open and
 * honestly marked as needing eyes.
 */
class DocSurfaceTest extends TestCase
{
    private static string $css;
    private static string $js;

    public static function setUpBeforeClass(): void
    {
        if (!defined('BASEPATH')) {
            define('BASEPATH', __DIR__);
        }
        require_once __DIR__ . '/../../application/libraries/Doc_template_service.php';
        $root = dirname(__DIR__, 2);
        self::$css = (string) file_get_contents($root . '/assets/css/doctemplates.css');
        self::$js  = (string) file_get_contents($root . '/assets/js/doctemplates/designer.js');
    }

    /** @return array<string,string> */
    private function tokens(string $block): array
    {
        preg_match_all('/(--[a-z0-9-]+)\s*:/', $block, $m);
        return array_fill_keys($m[1], '');
    }

    private function blocks(): array
    {
        preg_match('/^\.zxdt\{(.*?)^\}/ms', self::$css, $b);
        preg_match('/@media \(prefers-color-scheme:dark\)\{(.*?)\n\}\n/s', self::$css, $a);
        preg_match('/:root\[data-theme="night"\] \.zxdt\{(.*?)\n\}/s', self::$css, $n);
        return [$b[1] ?? '', $a[1] ?? '', $n[1] ?? ''];
    }

    /* ================================================================== *
     *  T3-04 — theming
     * ================================================================== */

    /**
     * The OS-preference path and the manual toggle must define the SAME tokens.
     *
     * If they drift, the toggle disagrees with the system setting: a user who flips to
     * night gets a half-themed page, and only on some machines. The failure is invisible
     * to whoever wrote the CSS, because they only test one path.
     */
    public function test_the_two_dark_mode_paths_define_identical_tokens(): void
    {
        [, $auto, $manual] = $this->blocks();
        $this->assertNotSame('', $auto, 'the prefers-color-scheme block was not found');
        $this->assertNotSame('', $manual, 'the data-theme="night" block was not found');

        $diff = array_merge(
            array_diff_key($this->tokens($auto), $this->tokens($manual)),
            array_diff_key($this->tokens($manual), $this->tokens($auto))
        );
        $this->assertSame([], array_keys($diff),
            'the OS-preference and manual dark paths define different tokens: '
            . implode(', ', array_keys($diff)));
    }

    /**
     * Every UI colour is themed — except the two that must NOT be.
     *
     * `--page` and `--page-ink` are the DOCUMENT's colours: white paper, dark ink. A
     * certificate is printed on paper whatever the operator's UI theme, so re-theming them
     * would show a preview that cannot be printed. Only `--page-sh`, the shadow the paper
     * casts on the desk, follows the theme. This test exists so a future "fix" that themes
     * them fails here instead of shipping.
     */
    public function test_document_colours_are_deliberately_not_themed(): void
    {
        [$base, $auto,] = $this->blocks();
        $baseT = $this->tokens($base);
        $autoT = $this->tokens($auto);

        $unthemed = [];
        foreach (array_keys($baseT) as $t) {
            if (preg_match('/^--(font|r|ease|zx-chrome|sh)/', $t)) { continue; }   // not colours
            if (!isset($autoT[$t])) { $unthemed[] = $t; }
        }
        sort($unthemed);
        $this->assertSame(['--page', '--page-ink'], $unthemed,
            'the set of un-themed colour tokens changed. --page and --page-ink are paper and '
            . 'ink and must stay fixed; anything else appearing here is a theming gap');
        $this->assertArrayHasKey('--page-sh', $autoT,
            'the paper SHADOW must follow the theme even though the paper does not');
    }

    /* ================================================================== *
     *  T3-14 / T3-15 — a declared HTML min is not a constraint
     * ================================================================== */

    /**
     * An HTML `min` is a hint to the spinner. It does not survive a paste, a scripted
     * value, or a direct POST — and nothing downstream re-checked either of these.
     */
    public function test_the_column_width_minimum_is_enforced_in_the_handler(): void
    {
        $this->assertStringContainsString('Math.max(5', self::$js,
            'the column-width input declares min="5" and the handler enforced only the max, '
            . 'so a typed 0.1 collapsed the column');
    }

    public function test_the_type_size_minimum_is_enforced_in_the_handler(): void
    {
        $at = strpos(self::$js, 'sk==="sizePt"');
        $this->assertNotFalse($at, 'type size is not clamped client-side');
        $this->assertStringContainsString('Math.max(4', substr(self::$js, $at, 300));
    }

    /**
     * And bounded on the server, because the client is not a boundary.
     *
     * Below ~4pt a statutory field is PRESENT and unreadable — worse than absent, because
     * absent fails the contract check loudly while unreadable passes every gate and
     * reaches a family.
     */
    public function test_type_size_is_bounded_server_side(): void
    {
        $docs = ['documentTemplates' => ['SCH1_TPL1' => [
            'schoolId' => 'SCH1', 'templateId' => 'TPL1', 'docType' => 'bonafide',
            'status' => 'draft', 'version' => 1, 'lockVersion' => 0,
            'publishedVersion' => null, 'activeVersion' => null,
            'objects' => [], 'languages' => ['en'], 'defaultLanguage' => 'en',
        ]]];
        $svc = new Doc_template_service(['schoolId' => 'SCH1', 'store' => [
            'get' => fn($c, $i) => $docs[$c][$i] ?? null,
            'set' => fn() => true,
            'update' => function ($c, $i, $d) use (&$docs) { $docs[$c][$i] = array_merge($docs[$c][$i], $d); return true; },
            'exists' => fn() => true, 'query' => fn() => $docs['documentTemplates'],
            'delete' => null, 'commit' => null,
        ], 'audit' => fn() => null]);

        $svc->save('SCH1_TPL1', ['objects' => [
            ['id' => 'tiny',  'style' => ['sizePt' => 0.1]],
            ['id' => 'huge',  'style' => ['sizePt' => 99999]],
            ['id' => 'right', 'style' => ['sizePt' => 10]],
        ]], 0);

        $o = array_column($docs['documentTemplates']['SCH1_TPL1']['objects'], 'style', 'id');
        $this->assertGreaterThanOrEqual(4, $o['tiny']['sizePt'], 'a 0.1pt statutory field was stored');
        $this->assertLessThanOrEqual(400, $o['huge']['sizePt']);
        $this->assertSame(10, $o['right']['sizePt'], 'a legitimate size was altered');
    }

    /* ================================================================== *
     *  T3-02 — modal accessibility (fixed earlier this session)
     * ================================================================== */

    public function test_modals_announce_themselves_and_take_focus(): void
    {
        $at = strpos(self::$js, 'function modal(title, sub, body, foot, small)');
        $this->assertNotFalse($at);
        $body = substr(self::$js, $at, 1400);

        foreach (['role", "dialog', 'aria-modal', 'aria-labelledby'] as $needle) {
            $this->assertStringContainsString($needle, $body, "modal() no longer sets $needle");
        }
        $this->assertStringContainsString('.focus()', $body,
            'a dialog that does not take focus leaves the keyboard on the page behind it');
    }
}
