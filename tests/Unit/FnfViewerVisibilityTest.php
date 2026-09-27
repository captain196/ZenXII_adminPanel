<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * UI-VIEW-1 — a view-only operator could not see F&F settlements at all.
 *
 * THE DEFECT. The whole Settlements card sat inside `<?php if ($canManage)`,
 * so an operator with HR *view* permission got no table, no rows, nothing —
 * while:
 *
 *   - Compensation::fnf_settlements() gates on _require_view(), i.e. the
 *     server was perfectly willing to serve them the list; and
 *   - renderSettlementRow() carries an explicit `if (!CAN_MANAGE)` branch that
 *     renders "View only" in the actions column — code written for a viewer
 *     that no viewer could ever reach.
 *
 * Three parts of the system disagreed about whether viewers may see this, and
 * the screen was the one that said no. It is the eighth instance in this
 * programme of the same shape: the capability exists, the way in does not.
 *
 * Found by rendering the real view with can_manage = false and driving it in a
 * headless browser, where the panel threw `Cannot read properties of null` —
 * because `#fnfSetTbl` was not in the document.
 *
 * THE FIX. Gate only the "Open settlement" control, which really is a manage
 * action. The list itself renders for everyone the endpoint would serve.
 */
final class FnfViewerVisibilityTest extends TestCase
{
    private static string $view = '';
    private static string $ctrl = '';

    public static function setUpBeforeClass(): void
    {
        $root = __DIR__ . '/../../';
        self::$view = (string) file_get_contents($root . 'application/views/compensation/index.php');
        self::$ctrl = (string) file_get_contents($root . 'application/controllers/Compensation.php');
    }

    public static function tearDownAfterClass(): void
    {
        self::$view = self::$ctrl = '';
    }

    /** Everything between the card's open and the table's close. */
    private static function settlementCard(): string
    {
        $i = strpos(self::$view, 'id="fnfSettleCard"');
        $j = strpos(self::$view, 'id="fnfSetTbl"', (int) $i);

        return substr(self::$view, (int) $i, ((int) $j) - ((int) $i));
    }

    // ── The property ─────────────────────────────────────────────────────

    /**
     * THE CENTRAL TEST. Between the card and the table there must be a
     * `endif` — i.e. whatever manage-only gate exists closes BEFORE the table,
     * so the table is not inside it.
     */
    public function test_the_settlements_table_is_not_behind_the_manage_gate(): void
    {
        $card = self::settlementCard();

        $opens  = substr_count($card, 'if ($canManage):');
        $closes = substr_count($card, 'endif;');

        $this->assertSame($opens, $closes,
            'a manage-only block opens before the settlements table and does not close before it — '
            . 'a view-only operator gets no table at all');
    }

    /** The open-settlement control IS manage-only — that part is correct. */
    public function test_opening_a_settlement_stays_manage_only(): void
    {
        $card = self::settlementCard();

        $gate = strpos($card, 'if ($canManage):');
        $btn  = strpos($card, 'id="fnfOpenBtn"');

        $this->assertNotFalse($gate, 'the open control must still be gated');
        $this->assertNotFalse($btn);
        $this->assertLessThan($btn, $gate, 'the gate must open before the button it protects');

        $endif = strpos($card, 'endif;', (int) $gate);
        $this->assertNotFalse($endif);
        $this->assertGreaterThan($btn, $endif, 'the gate must close after the button');
    }

    /** The server would have served them — that is why hiding it was wrong. */
    public function test_the_endpoint_only_requires_view_permission(): void
    {
        $i = strpos(self::$ctrl, 'public function fnf_settlements');
        $body = substr(self::$ctrl, (int) $i, 400);

        $this->assertStringContainsString('$this->_require_view();', $body,
            'if this ever hardens to manage, the screen should hide the list again');
    }

    /** And the row renderer has a branch written specifically for viewers. */
    public function test_the_row_renderer_has_a_viewer_branch(): void
    {
        $this->assertStringContainsString('if (!CAN_MANAGE) {', self::$view);
        $this->assertStringContainsString("actions = '<span class=\"cmp-help\">View only</span>';", self::$view);
    }

    /**
     * The template still balances. The fix moved an `endif` across a `</div>`;
     * getting that wrong yields an unclosed card for one of the two audiences,
     * which is exactly the kind of breakage a source test can catch cheaply.
     */
    public function test_the_template_conditionals_balance(): void
    {
        // Count EVERY template conditional, not just the canManage ones — the
        // file carries others, and comparing one kind of opening against all
        // closings reports a phantom imbalance.
        $this->assertSame(
            substr_count(self::$view, '<?php if ('),
            substr_count(self::$view, 'endif;'),
            'unbalanced if/endif — one audience will get malformed markup');
    }
}
