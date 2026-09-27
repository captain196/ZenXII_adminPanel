<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * UI-D3 — no native browser dialogs on the Staff Lifecycle screens.
 *
 * window.confirm() / prompt() / alert() render as "localhost says…": browser
 * chrome that cannot be themed, names the HOST rather than the product, and
 * blocks the whole tab so a slow page behind them looks frozen. Reported by
 * the operator while approving a real Full & Final settlement — Approve,
 * Record Payment and Close each opened one.
 *
 * There were 44 of them across seven screens. They are replaced by
 * assets/js/zx_dialog.js (zxConfirm / zxPrompt), which is themed, keyboard
 * accessible, restores focus, and — unlike the native ones — is PROMISE based,
 * so every call site had to become async. That async conversion is the risky
 * part, which is why this suite also checks it.
 */
final class NoNativeBrowserDialogsTest extends TestCase
{
    /** The Staff Lifecycle surface an administrator actually operates. */
    private const SCREENS = [
        'compensation/index.php',
        'hr/index.php',
        'edit_staff.php',
        'new_staff.php',
        'ats/index.php',
        'attendance/settings.php',
        'attendance/staff_regularization.php',
    ];

    private function src(string $rel): string
    {
        $p = __DIR__ . '/../../application/views/' . $rel;
        $this->assertFileExists($p);
        return (string) file_get_contents($p);
    }

    /** Strip HTML comments and JS line comments — prose may say "alert". */
    private function code(string $s): string
    {
        $s = preg_replace('/<!--.*?-->/s', '', $s);
        $s = preg_replace('~/\*.*?\*/~s', '', $s);
        $s = preg_replace('~^\s*//.*$~m', '', $s);
        return (string) $s;
    }

    /** DEFECT UI-D3: not one native dialog may remain. */
    public function test_no_screen_opens_a_native_browser_dialog(): void
    {
        $offenders = [];

        foreach (self::SCREENS as $rel) {
            $code = $this->code($this->src($rel));
            if (preg_match_all('~(?<![A-Za-z.$_])(confirm|prompt|alert)\s*\(~', $code, $m)) {
                $offenders[] = $rel . ' -> ' . implode(', ', array_unique($m[1]));
            }
        }

        $this->assertSame([], $offenders,
            "these render as \"localhost says…\" and cannot be themed:\n  "
            . implode("\n  ", $offenders));
    }

    /** Every screen that calls the themed dialogs must actually load them. */
    public function test_every_screen_loads_the_shared_dialog_helper(): void
    {
        foreach (self::SCREENS as $rel) {
            $s = $this->src($rel);
            if (strpos($s, 'zxConfirm') === false && strpos($s, 'zxPrompt') === false) {
                continue;   // screen has no dialogs at all
            }
            $this->assertStringContainsString('zx_dialog.js', $s,
                "{$rel} calls zxConfirm/zxPrompt but never loads the helper");
        }
    }

    /**
     * `await` outside an async function is a SyntaxError — and PHP lint cannot
     * see it, because it is JavaScript inside a .php view. Two automated passes
     * spliced "async" into the middle of function NAMES before this was caught,
     * so the corruption shape is asserted explicitly.
     */
    public function test_the_async_conversion_did_not_corrupt_identifiers(): void
    {
        foreach (self::SCREENS as $rel) {
            $s = $this->src($rel);

            $this->assertDoesNotMatchRegularExpression(
                '~function\s+[A-Za-z_$][A-Za-z0-9_$]*async\s~', $s,
                "{$rel}: 'async' was spliced into a function name");

            $this->assertDoesNotMatchRegularExpression(
                '~function\s+async\s+[A-Za-z_$]~', $s,
                "{$rel}: 'function async name()' is not valid syntax");
        }
    }

    /** The helper itself keeps the contract the call sites rely on. */
    public function test_the_helper_honours_its_contract(): void
    {
        $p = __DIR__ . '/../../assets/js/zx_dialog.js';
        $this->assertFileExists($p);
        $js = (string) file_get_contents($p);

        $this->assertStringContainsString('w.zxConfirm =', $js);
        $this->assertStringContainsString('w.zxPrompt  =', $js);

        // Cancel must be distinguishable from "left blank" — call sites check null.
        $this->assertStringContainsString("done(withInput ? null : false)", $js);

        // Required values are enforced inside the dialog, so call sites do not.
        $this->assertStringContainsString("opts.required !== false", $js);

        // Escape and backdrop both cancel.
        $this->assertStringContainsString("e.key === 'Escape'", $js);

        // NOT `.show` — Bootstrap 3 ships `.show{display:block!important}`,
        // which already un-centred the ATS modals once (UI-D1).
        $this->assertStringContainsString('zx-dlg-open', $js);
        $this->assertDoesNotMatchRegularExpression('~classList\.add\(\s*[\'"]show[\'"]\s*\)~', $js,
            'reusing .show collides with Bootstrap and breaks centring');
    }
}
