<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * The Issuer Identity tab — markup, wiring and the one-source rule.
 *
 * A tab is three things that must agree: markup with ids, JavaScript that reads
 * those ids, and a server endpoint that accepts what it posts. Each is fine on
 * its own and the feature is dead if any pair disagrees — which is exactly how
 * the compliance exclusions turned out to be saving nothing (L28).
 */
final class IssuerIdentityTabTest extends TestCase
{
    private static string $view;
    private static string $ctl;

    public static function setUpBeforeClass(): void
    {
        $v = @file_get_contents(__DIR__ . '/../../application/views/school_config/index.php');
        $c = @file_get_contents(__DIR__ . '/../../application/controllers/School_config.php');
        self::assertNotFalse($v, 'school_config view unreadable');
        self::assertNotFalse($c, 'School_config controller unreadable');
        self::$view = (string) $v;
        self::$ctl  = (string) $c;
    }

    private function pane(): string
    {
        $i = strpos(self::$view, '<div class="sc-pane" id="tab-issuer">');
        $this->assertNotFalse($i, 'the Issuer Identity pane is gone');
        $j = strpos(self::$view, '<!-- TAB: Board ', $i);
        return substr(self::$view, $i, $j - $i);
    }

    public function test_the_tab_is_reachable_from_the_strip(): void
    {
        $this->assertStringContainsString('data-tab="issuer"', self::$view,
            'The pane exists but no tab button opens it.');
    }

    /** An unbalanced insert silently swallows the panes below it. */
    public function test_the_pane_markup_is_balanced(): void
    {
        $body = preg_replace('/<!--.*?-->/s', '', $this->pane());
        $this->assertSame(
            preg_match_all('/<div\b/', $body),
            preg_match_all('/<\/div>/', $body),
            'Unbalanced <div> in the pane — every tab rendered after it would be nested inside this one.'
        );
        foreach (['select', 'button', 'label'] as $tag) {
            $this->assertSame(
                preg_match_all('/<' . $tag . '\b/', $body),
                preg_match_all('#</' . $tag . '>#', $body),
                "Unbalanced <$tag> in the pane."
            );
        }
    }

    public function test_no_duplicate_field_ids(): void
    {
        preg_match_all('/id="(ii_[a-z_]+)"/', $this->pane(), $m);
        $this->assertSame(count($m[1]), count(array_unique($m[1])),
            'Duplicate id in the pane — getElementById returns the first, so one field is unreachable.');
    }

    /** The wiring check: everything the script reads must exist. */
    public function test_every_id_the_script_reads_exists_in_the_markup(): void
    {
        preg_match_all('/id="(ii_[a-z_]+)"/', $this->pane(), $inMarkup);
        $at = strpos(self::$view, 'function renderIssuerIdentity');
        $this->assertNotFalse($at, 'renderIssuerIdentity() is gone');
        $js = substr(self::$view, $at, 6000);
        preg_match_all("/getElementById\('(ii_[a-z_]+)'\)/", $js, $inJs);

        $missing = array_values(array_diff(array_unique($inJs[1]), $inMarkup[1]));
        $this->assertSame([], $missing,
            "The script reads ids the markup does not define: " . implode(', ', $missing));
    }

    /** And everything it posts must be a field the endpoint accepts. */
    public function test_every_posted_field_is_read_by_the_endpoint(): void
    {
        $at = strpos(self::$view, 'function saveIssuerIdentity');
        $this->assertNotFalse($at, 'saveIssuerIdentity() is gone');
        $js = substr(self::$view, $at, 2200);
        preg_match_all('/^\s*([a-z_]+):\s*iiVal\(/m', $js, $posted);

        $at2 = strpos(self::$ctl, 'public function save_issuer_identity');
        $this->assertNotFalse($at2, 'save_issuer_identity() is gone');
        $php = substr(self::$ctl, $at2, 2600);

        foreach ($posted[1] as $field) {
            $this->assertStringContainsString("'$field'", $php,
                "The tab posts '$field' and the endpoint never reads it — the value would be "
                . 'accepted by the browser and silently dropped.');
        }
        $this->assertGreaterThanOrEqual(8, count($posted[1]), 'Far fewer posted fields than expected.');
    }

    /** The tab is hydrated, or it opens empty for a school that has data. */
    public function test_the_tab_is_hydrated_from_get_config(): void
    {
        $this->assertStringContainsString('renderIssuerIdentity(d.issuer_identity', self::$view);
        $this->assertStringContainsString("'issuer_identity' =>", self::$ctl,
            'get_config does not send issuer_identity, so the tab has nothing to render.');
    }

    /**
     * The board list is sent BY the server so the client cannot keep a second
     * copy that drifts — the same reasoning as the client/server merge-field
     * contract.
     */
    public function test_the_board_list_comes_from_the_server(): void
    {
        $at = strpos(self::$view, 'function renderIssuerIdentity');
        $js = substr(self::$view, $at, 1500);
        $this->assertStringContainsString('ii.boards', $js,
            'The tab builds its own board list instead of using the one the server sent.');
        $this->assertStringContainsString('boardCatalogue()', self::$ctl);
    }

    /** Changing the claim must clear what verified it. */
    public function test_editing_the_claim_clears_a_prior_verification(): void
    {
        $at = strpos(self::$ctl, 'public function save_issuer_identity');
        $php = substr(self::$ctl, $at, 3200);
        $this->assertStringContainsString('claimMoved', $php,
            'Editing the board or number leaves a verification badge attached to a claim '
            . 'nobody checked.');
    }
}
