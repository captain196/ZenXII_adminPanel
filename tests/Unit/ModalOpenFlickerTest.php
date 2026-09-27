<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * UI-D5 — the modal flashed twice before settling.
 *
 * Root cause was not the markup or the JS. `--ease` is defined in
 * tools/css/header-inline.css as
 *
 *     --ease: .22s cubic-bezier(.4,0,.2,1);
 *
 * i.e. a DURATION plus a timing function, shaped for `transition: all
 * var(--ease)`. Dropped into an `animation` shorthand after an explicit
 * duration, its time token lands in the shorthand's SECOND time slot, which
 * is the delay:
 *
 *     animation: hrSlideUp .25s var(--ease)
 *   = animation: hrSlideUp .25s .22s cubic-bezier(.4,0,.2,1)
 *
 * With the default `animation-fill-mode: none`, nothing from the keyframes
 * applies during those 220ms, so the card painted at its computed style —
 * fully opaque, final position — the instant `.open` flipped display to flex.
 * When the delay elapsed the animation snapped to `from{opacity:0}` and the
 * card blinked out before fading in. Two flashes, then settle.
 *
 * These assertions pin the shape of the fix, not the pixels.
 */
final class ModalOpenFlickerTest extends TestCase
{
    private string $src;

    protected function setUp(): void
    {
        $p = __DIR__ . '/../../application/views/hr/index.php';
        $this->assertFileExists($p);
        $this->src = (string) file_get_contents($p);
    }

    /** Every `animation:` declaration in the file. */
    private function animations(): array
    {
        preg_match_all('~animation:\s*[^;}]+~', $this->src, $m);
        return $m[0];
    }

    /**
     * DEFECT UI-D5a — the delay trap. No animation shorthand may consume
     * var(--ease), because that variable smuggles a duration into the delay.
     */
    public function test_no_animation_shorthand_consumes_the_ease_variable(): void
    {
        $bad = array_values(array_filter(
            $this->animations(),
            static fn(string $a): bool => strpos($a, 'var(--ease)') !== false
        ));

        $this->assertSame([], $bad,
            "--ease is '.22s cubic-bezier(...)' — a duration plus a timing function.\n"
            . "In an animation shorthand that duration becomes the DELAY, and with\n"
            . "fill-mode:none the element paints in its final state until the delay\n"
            . "elapses, then snaps to the first keyframe. State the timing function\n"
            . "literally in animations. Offenders:\n  " . implode("\n  ", $bad));
    }

    /**
     * DEFECT UI-D5b — a fill mode is what stops the pre-animation frame from
     * being painted in the element's computed state.
     */
    public function test_the_modal_animations_declare_a_fill_mode(): void
    {
        foreach (['.hr-modal-overlay.open{', '.hr-modal-overlay.open .hr-modal{'] as $sel) {
            $i = strpos($this->src, $sel);
            $this->assertNotFalse($i, "rule {$sel} is gone");
            $rule = substr($this->src, $i, (int) strpos($this->src, '}', $i) - $i);

            $this->assertStringContainsString('animation:', $rule);
            $this->assertMatchesRegularExpression(
                '~animation:[^;}]*\b(backwards|both)\b~', $rule,
                "{$sel} animates an element that is revealed by a display change; "
                . "without fill-mode backwards it flashes in its final state first");
        }
    }

    /**
     * DEFECT UI-D5c — nested opacity animations multiply. The overlay owns
     * the fade; the card may only move.
     */
    public function test_the_card_does_not_animate_opacity_inside_the_fading_overlay(): void
    {
        $i = strpos($this->src, '.hr-modal-overlay.open .hr-modal{');
        $this->assertNotFalse($i);
        $rule = substr($this->src, $i, (int) strpos($this->src, '}', $i) - $i);

        preg_match('~animation:\s*([A-Za-z][\w-]*)~', $rule, $m);
        $this->assertNotEmpty($m[1] ?? '', 'card has no named animation');
        $name = $m[1];

        $k = strpos($this->src, '@keyframes ' . $name . '{');
        $this->assertNotFalse($k, "keyframes {$name} not defined");
        $frames = substr($this->src, $k, (int) strpos($this->src, '}}', $k) - $k);

        $this->assertStringNotContainsString('opacity', $frames,
            "@keyframes {$name} animates opacity inside an overlay that is also "
            . "animating opacity. The rendered value is the PRODUCT of both curves, "
            . "so the card lags dim and then surges when the parent pins to 1.");

        // It still has to actually animate something.
        $this->assertStringContainsString('transform', $frames);
    }

    /** Motion is a preference; honour it rather than animating regardless. */
    public function test_reduced_motion_is_honoured(): void
    {
        $this->assertMatchesRegularExpression(
            '~@media\(prefers-reduced-motion:reduce\)\{[^}]*\.hr-modal-overlay\.open[^}]*animation:\s*none~',
            $this->src);
    }

    /**
     * Documents the trap for the next reader by asserting it against the real
     * stylesheet: if --ease is ever redefined as timing-only, this fails and
     * whoever changed it learns why these rules avoid the variable.
     */
    public function test_the_ease_variable_still_carries_a_duration(): void
    {
        $p = __DIR__ . '/../../tools/css/header-inline.css';
        $this->assertFileExists($p);

        preg_match('~--ease:\s*([^;]+);~', (string) file_get_contents($p), $m);
        $this->assertNotEmpty($m[1] ?? '', '--ease is no longer defined');

        $this->assertMatchesRegularExpression(
            '~^\s*\.?\d~', $m[1],
            "--ease no longer starts with a duration. It is now safe in an "
            . "animation shorthand, so UI-D5's avoidance rule can be revisited.");
    }
}
