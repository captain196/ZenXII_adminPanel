<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * REC-D3 — the circular's "Apply Now" looked like a button and did nothing.
 *
 * It was rendered as a styled <div> with no href, no onclick and no handler,
 * sitting above the line "Contact the school administration for application
 * details". Reported by the operator: "apply now button appears but it does
 * not open".
 *
 * There is no public job-application surface in this codebase — Admission_public
 * serves student admissions and Ats is admin-authenticated — so the CTA cannot
 * point at an internal apply route without one being invented. It now resolves
 * to the school's own published contact channel, and when the tenant has
 * published none, no button is drawn at all.
 *
 * The rule this pins: a control that cannot act must not look like one.
 */
final class CircularApplyCtaTest extends TestCase
{
    private string $src;

    protected function setUp(): void
    {
        $p = __DIR__ . '/../../application/controllers/Hr.php';
        $this->assertFileExists($p);
        $this->src = (string) file_get_contents($p);
    }

    private function builder(): string
    {
        $i = strpos($this->src, 'private function _build_circular_poster(');
        $this->assertNotFalse($i, '_build_circular_poster() is gone');
        $next = strpos($this->src, "\n    private function ", $i + 10);
        $end  = strpos($this->src, "\n    public function ", $i + 10);
        if ($next === false || ($end !== false && $end < $next)) $next = $end;
        return substr($this->src, $i, ($next === false ? strlen($this->src) : $next) - $i);
    }

    /**
     * DEFECT REC-D3 — the dead affordance must be gone. A bare "Apply Now"
     * inside a plain <div> is the exact shape that was reported.
     */
    public function test_apply_now_is_not_a_dead_div(): void
    {
        $b = $this->builder();

        $this->assertDoesNotMatchRegularExpression(
            '~<div[^>]*>\s*\n?\s*Apply Now\s*\n?\s*</div>~', $b,
            'Apply Now is a plain <div> again — it renders as a button but cannot act');
    }

    /** Whatever it renders, an actionable CTA must carry a real destination. */
    public function test_an_actionable_cta_carries_a_real_scheme(): void
    {
        $b = $this->builder();

        $this->assertMatchesRegularExpression('~href="mailto:~', $b,
            'no mailto: destination for the apply CTA');
        $this->assertMatchesRegularExpression('~href="tel:~', $b,
            'no tel: fallback when the tenant publishes only a phone number');
    }

    /**
     * The important half: with no published channel there is nothing to click,
     * so the button must not be drawn. Fail closed, visually.
     */
    public function test_no_button_is_drawn_without_a_published_channel(): void
    {
        $b = $this->builder();

        // Anchor on the apply CTA specifically — the builder has other
        // else-branches (e.g. the logo monogram fallback).
        $i = strpos($b, '$applyBody =');
        $this->assertNotFalse($i, 'the How-to-Apply block is gone');
        $cta = substr($b, $i, (int) strpos($b, '/* ── Letterhead', $i) - $i);
        $this->assertNotSame('', $cta);

        $j = strrpos($cta, '} else {');
        $this->assertNotFalse($j, 'the no-channel branch is gone');
        $branch = substr($cta, $j);

        $this->assertStringNotContainsString('Apply Now', $branch,
            'the no-channel branch still draws an Apply Now button that cannot act');
        $this->assertMatchesRegularExpression('~\$applyBtn\s*=\s*\'\';~', $branch,
            'the no-channel branch must leave the control empty');
        $this->assertStringContainsString('contact the school administration', $branch);
    }

    /** The destination is tenant data, never a hardcoded placeholder address. */
    public function test_the_contact_channel_comes_from_the_school_record(): void
    {
        $b = $this->builder();

        $this->assertMatchesRegularExpression(
            "~\\\$this->fs->get\('schools',\s*\\\$this->fs->schoolId\(\)\)~", $b,
            'the CTA must read the tenant\'s own published contact details');

        // No invented addresses.
        $this->assertDoesNotMatchRegularExpression(
            '~mailto:[a-z0-9._%-]+@[a-z0-9.-]+\.[a-z]{2,}~i', $b,
            'a literal email address is hardcoded into the poster');
    }

    /** Candidate-supplied and tenant-supplied text alike must be escaped. */
    public function test_the_contact_details_are_escaped(): void
    {
        $b = $this->builder();
        $this->assertMatchesRegularExpression('~\$e\(\$schEmail\)~', $b);
        $this->assertMatchesRegularExpression('~\$e\(\$schPhone\)~', $b);
    }
}
