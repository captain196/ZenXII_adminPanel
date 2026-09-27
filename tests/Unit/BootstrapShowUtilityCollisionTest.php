<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * UI-D1 — Bootstrap's `.show` utility silently un-centres every modal.
 *
 * The admin shell loads Bootstrap 3.4.1 on every page, and Bootstrap ships:
 *
 *     .show { display: block !important; }
 *
 * Module CSS toggles its own overlays with the SAME class name:
 *
 *     .ats-modal-bg.show { display: flex; }
 *
 * `!important` cannot be outranked by specificity, so the backdrop becomes a
 * block, `align-items`/`justify-content` never apply, and the dialog parks
 * against the left edge of the viewport — reported from the ATS screen, but
 * present in ten views at once. Three modules had already worked around it
 * locally, which is how a systemic bug stays invisible: each fix looks like a
 * one-off.
 *
 * The rule asserted is simple and mechanical: if a view toggles `display:flex`
 * behind `.show`, it must say `!important`, because something else already did.
 */
final class BootstrapShowUtilityCollisionTest extends TestCase
{
    /** Every `.show { display: flex }` rule in the view layer. */
    private function flexShowRules(): array
    {
        $root  = __DIR__ . '/../../application/views';
        $found = [];

        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root));
        foreach ($it as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'php') continue;

            $src = (string) file_get_contents($file->getPathname());
            if (!preg_match_all('~[^{}]*\.show\s*\{\s*display\s*:\s*flex[^}]*\}~i', $src, $m)) {
                continue;
            }

            foreach ($m[0] as $rule) {
                $found[] = [
                    'file' => str_replace(DIRECTORY_SEPARATOR, '/', $file->getPathname()),
                    'rule' => trim(preg_replace('~\s+~', ' ', $rule)),
                ];
            }
        }
        return $found;
    }

    /** The premise: Bootstrap really does ship the conflicting utility. */
    public function test_bootstrap_ships_the_conflicting_utility(): void
    {
        $css = (string) file_get_contents(
            __DIR__ . '/../../tools/bower_components/bootstrap/dist/css/bootstrap.min.css');

        $this->assertStringContainsString('.show{display:block!important}', $css,
            'if Bootstrap no longer ships this, the whole workaround can be revisited');
    }

    /** And it is loaded on the admin pages that host these modals. */
    public function test_bootstrap_is_loaded_by_the_admin_shell(): void
    {
        $root  = __DIR__ . '/../../application/views/';
        $shell = '';
        foreach (['include/header.php', 'superadmin/include/sa_header.php'] as $c) {
            if (is_file($root . $c)) { $shell = (string) file_get_contents($root . $c); break; }
        }

        if ($shell === '') {
            $this->markTestSkipped('admin shell template not found at the expected paths');
        }

        $this->assertStringContainsString('bootstrap.min.css', $shell,
            'the collision only bites while Bootstrap is on the page');
    }

    /**
     * DEFECT UI-D1. Any unguarded rule renders its overlay left-aligned.
     */
    public function test_no_view_toggles_flex_behind_show_without_important(): void
    {
        $rules = $this->flexShowRules();

        $this->assertNotEmpty($rules,
            'no `.show{display:flex}` rules found at all — the scan is broken, not the code');

        $unguarded = [];
        foreach ($rules as $r) {
            if (stripos($r['rule'], '!important') === false) {
                $unguarded[] = basename(dirname($r['file'])) . '/' . basename($r['file'])
                    . '  ->  ' . $r['rule'];
            }
        }

        $this->assertSame([], $unguarded,
            "Bootstrap's `.show{display:block!important}` overrides these, so the element "
            . "renders as a block and any flex centring is silently discarded:\n  "
            . implode("\n  ", $unguarded));
    }
}
