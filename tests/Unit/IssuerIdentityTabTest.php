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

    /**
     * The body of one method, sliced to where the NEXT method begins.
     *
     * A fixed-width substr() was what broke the previous version of these
     * tests: the method grew past the window and a still-true assertion
     * started reporting a regression that had not happened.
     */
    private function methodBody(string $name): string
    {
        $at = strpos(self::$ctl, 'public function ' . $name);
        $this->assertNotFalse($at, "Method {$name}() not found.");
        $rest = substr(self::$ctl, $at + 10);
        $end  = preg_match('/\n    (?:public|private|protected) function /', $rest, $m, PREG_OFFSET_CAPTURE)
            ? $m[0][1] : strlen($rest);
        return substr(self::$ctl, $at, $end + 10);
    }

    /**
     * Changing the claim must clear what verified it.
     *
     * This used to grep a fixed-width slice of save_issuer_identity() for the
     * inline `claimMoved` logic. That pinned the test to one implementation in
     * one controller — which is exactly the problem, because THREE doors write
     * these keys and the other two never cleared anything. The decision now
     * lives in Issuer_identity::reconcile(), so the contract worth enforcing is
     * that the controller DELEGATES rather than deciding for itself.
     *
     * The behaviour itself is covered by IssuerReconcileTest.
     */
    public function test_the_controller_delegates_the_claim_decision(): void
    {
        $php = $this->methodBody('save_issuer_identity');

        $this->assertStringContainsString('Issuer_identity::reconcile(', $php,
            'The controller decides for itself instead of sharing one decision with the '
            . 'other doors that write these keys.');
        $this->assertStringNotContainsString('Issuer_identity::validate(', $php,
            'Calling validate() directly skips the claim-moved and level handling that '
            . 'reconcile() exists to guarantee.');
    }

    /** The write must not be a blind read-modify-write — BUG-028's shape. */
    public function test_the_issuer_write_is_lock_and_cas_guarded(): void
    {
        $php = $this->methodBody('save_issuer_identity');

        $this->assertStringContainsString("_config_lock_acquire('issuer_identity')", $php,
            'Two doors write these keys; without a lock the Profile tab can land between '
            . 'this read and this write.');
        /* The shape is an ASSIGNMENT, not an array literal — BUG-028's own
           verification notes record a probe that got this wrong once already. */
        $this->assertStringContainsString("\$ops[0]['precondition'] = ['updateTime' => \$updateTime];", $php,
            'Without a CAS precondition the stored level can describe an affiliation '
            . 'number the document no longer holds.');
        $this->assertStringContainsString('_config_lock_release', $php,
            'A lock that is not released in a finally block outlives the request.');
    }
}
