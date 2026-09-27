<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * REC-D5 — a select default that matches no option.
 *
 * openApplicantModal() did `$('#applicantStatus').val('applied')` while the
 * options are title-case ("Applied"). jQuery finds no match, sets
 * selectedIndex = -1, and the control renders BLANK — the options are all
 * still there, so it only looks like a styling glitch when you click it.
 *
 * The consequence is not cosmetic: with nothing selected, .val() returns null
 * and the form posts an empty status. Server-side `?? 'Applied'` only fires on
 * a MISSING field, so an empty string went straight through and the applicant
 * was stored with status = ''.
 *
 * This test walks EVERY select in the HR view and checks that whatever the JS
 * pre-selects actually exists as an option — the class of bug, not one case.
 */
final class SelectDefaultsMatchOptionsTest extends TestCase
{
    private string $src;

    protected function setUp(): void
    {
        $p = __DIR__ . '/../../application/views/hr/index.php';
        $this->assertFileExists($p);
        $this->src = (string) file_get_contents($p);
    }

    /** id => [option values] for every <select id="..."> in the view. */
    private function selects(): array
    {
        preg_match_all(
            '~<select[^>]*\bid="([A-Za-z0-9_]+)"[^>]*>(.*?)</select>~s',
            $this->src, $m, PREG_SET_ORDER
        );

        $out = [];
        foreach ($m as $s) {
            preg_match_all('~<option[^>]*\bvalue="([^"]*)"~', $s[2], $o);
            $out[$s[1]] = $o[1];
        }
        return $out;
    }

    public function test_the_view_still_has_selects_to_check(): void
    {
        $this->assertNotEmpty($this->selects(), 'no <select> found — did the markup change shape?');
    }

    /**
     * DEFECT REC-D5 — every literal pre-selection must name a real option.
     * Only statically-known literals are checked; dynamic values (variables,
     * server data) cannot be resolved here.
     */
    public function test_every_literal_default_matches_a_real_option(): void
    {
        $selects = $this->selects();

        preg_match_all(
            '~\$\(\s*[\'"]#([A-Za-z0-9_]+)[\'"]\s*\)\s*\.val\(\s*[\'"]([^\'"]*)[\'"]\s*\)~',
            $this->src, $m, PREG_SET_ORDER
        );

        $bad = [];
        foreach ($m as $hit) {
            [$whole, $id, $value] = $hit;

            if (!isset($selects[$id])) continue;   // not a <select>
            if ($value === '') continue;           // deliberate "clear"

            // Options can be rendered by JS for some selects; if the markup
            // carries none, there is nothing static to verify against.
            if ($selects[$id] === []) continue;

            if (!in_array($value, $selects[$id], true)) {
                $bad[] = "#{$id}: .val('{$value}') matches no option ["
                       . implode(', ', $selects[$id]) . ']';
            }
        }

        $this->assertSame([], $bad,
            "A default that matches no option leaves the select with\n"
            . "selectedIndex = -1: it renders blank and .val() returns null,\n"
            . "so the form posts an empty value.\n  " . implode("\n  ", $bad));
    }

    /** The reported case, pinned by name so the regression is unambiguous. */
    public function test_the_applicant_status_defaults_to_applied(): void
    {
        $this->assertMatchesRegularExpression(
            "~\\\$\('#applicantStatus'\)\.val\('Applied'\)~", $this->src,
            'the applicant status default must match the title-case option');

        $selects = $this->selects();
        $this->assertArrayHasKey('applicantStatus', $selects);
        $this->assertContains('Applied', $selects['applicantStatus']);
    }

    /**
     * Server side: `?? 'Applied'` only catches a missing field. An empty
     * string must default too, or the applicant is stored statusless.
     */
    public function test_the_server_defaults_an_empty_status(): void
    {
        $c = (string) file_get_contents(__DIR__ . '/../../application/controllers/Hr.php');
        $i = strpos($c, 'public function save_applicant');
        $this->assertNotFalse($i, 'save_applicant() is gone');
        $body = substr($c, $i, 2000);

        $this->assertMatchesRegularExpression(
            "~if\s*\(\s*\\\$status\s*===\s*''\s*\)\s*\\\$status\s*=\s*'Applied';~", $body,
            'an empty posted status must fall back to Applied');
    }
}
