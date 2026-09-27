<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * REC-D4 — the recruitment circular is an official notice, not a graphic.
 *
 * It was a gradient marketing card with a "We Are Hiring" pill and rounded
 * corners: unusable as the thing a school actually needs — a letterhead notice
 * to pin on a board, email as a PDF and file by reference number.
 *
 * It is now a document: letterhead (logo/monogram, address, contact,
 * affiliation), a reference number and date, a bordered particulars table,
 * a How to Apply block, and a signature block.
 *
 * Two properties matter more than the layout and are asserted here:
 *   - every field is escaped, and none is invented when the tenant has not
 *     published it;
 *   - the stylesheet is scoped, so injecting the poster into the admin modal
 *     cannot restyle the surrounding page.
 */
final class RecruitmentCircularDocumentTest extends TestCase
{
    private string $fn;

    protected function setUp(): void
    {
        $p = __DIR__ . '/../../application/controllers/Hr.php';
        $this->assertFileExists($p);
        $src = (string) file_get_contents($p);

        $i = strpos($src, 'private function _build_circular_poster(');
        $this->assertNotFalse($i, '_build_circular_poster() is gone');
        $this->assertTrue(
            (bool) preg_match('~\R    (?:private|public|protected) function ~', $src, $m, PREG_OFFSET_CAPTURE, $i + 60),
            'could not bound the function');
        $this->fn = substr($src, $i, $m[0][1] - $i);
    }

    /** DEFECT REC-D4a — the marketing-card idiom must not come back. */
    public function test_it_is_not_a_marketing_card(): void
    {
        foreach (['We Are Hiring', 'border-radius:16px', 'linear-gradient(135deg,#BC5A3C 0%'] as $relic) {
            $this->assertStringNotContainsString($relic, $this->fn,
                "the circular reverted to the marketing-card design ({$relic})");
        }
    }

    /** The structural parts that make it a notice rather than a poster. */
    public function test_it_carries_the_parts_of_an_official_notice(): void
    {
        foreach ([
            'Ref. No.'           => 'a circular must be filable by reference',
            'Recruitment Notice' => 'the notice needs a title block',
            'How to Apply'       => 'a notice must say how to respond',
            'Principal'          => 'an unsigned notice carries no authority',
            'zc-tbl'             => 'particulars belong in a bordered table',
        ] as $needle => $why) {
            $this->assertStringContainsString($needle, $this->fn, $why);
        }
    }

    /**
     * DEFECT REC-D4b — the poster is injected into the admin modal via
     * innerHTML. An unscoped <style> block would restyle the whole page.
     */
    public function test_the_stylesheet_is_scoped_to_the_circular(): void
    {
        $i = strpos($this->fn, '<style>');
        $this->assertNotFalse($i, 'no stylesheet');
        $css = substr($this->fn, $i, (int) strpos($this->fn, '</style>') - $i);

        preg_match_all('~^\s*([.:@][^{]*)\{~m', $css, $m);
        $this->assertNotEmpty($m[1]);

        foreach ($m[1] as $sel) {
            $sel = trim($sel);
            if (strpos($sel, '@') === 0) continue;            // at-rules
            $this->assertMatchesRegularExpression('~^\.zx-circ\b~', $sel,
                "selector '{$sel}' escapes the circular and would restyle the admin page");
        }

        // Page geometry belongs to the print window, not to an injected block.
        $this->assertStringNotContainsString('@page', $css,
            '@page here would alter printing of the whole admin page');
    }

    /** Print is the primary output: the dead link must not print as a button. */
    public function test_print_rules_exist_and_drop_the_link(): void
    {
        $this->assertStringContainsString('@media print', $this->fn);
        $i = strpos($this->fn, '@media print');
        $block = substr($this->fn, $i, 400);
        $this->assertStringContainsString('.zc-btn{display:none', str_replace(' ', '', $block),
            'a mailto button cannot be clicked on paper');
    }

    /** Every interpolated value must pass through the escaper. */
    public function test_tenant_and_job_text_is_escaped(): void
    {
        foreach (['$e($schoolName)', '$e($schEmail)', '$e($schPhone)', '$e($logo)'] as $call) {
            $this->assertStringContainsString($call, $this->fn,
                "{$call} is interpolated without escaping");
        }
        // The description is free text authored in the admin form.
        $this->assertMatchesRegularExpression('~zc-sec-b">\s*\'\s*\.\s*\$e\(\$desc\)~', $this->fn,
            'the job description is rendered unescaped');
    }

    /**
     * Nothing may be invented. An unpublished field is omitted; it is never
     * replaced with a placeholder address, phone number or affiliation.
     */
    public function test_missing_profile_fields_are_omitted_not_invented(): void
    {
        $this->assertDoesNotMatchRegularExpression(
            '~mailto:[a-z0-9._%-]+@[a-z0-9.-]+\.[a-z]{2,}~i', $this->fn,
            'a literal email address is baked into the circular');

        // No published channel -> no button at all.
        $this->assertMatchesRegularExpression(
            '~\$applyBtn\s*=\s*\'\';~', $this->fn,
            'with no contact channel the circular must draw no apply control');

        // The logo falls back to a monogram rather than a broken <img>.
        $this->assertStringContainsString('zc-mono', $this->fn,
            'a tenant with no logo would render a broken image');
    }
}
