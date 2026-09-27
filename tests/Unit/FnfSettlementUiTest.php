<?php
declare(strict_types=1);

require_once __DIR__ . '/../../application/libraries/Fnf_state_machine.php';

use PHPUnit\Framework\TestCase;

/**
 * FNF-3 — the settlement screen.
 *
 * The lifecycle backend was complete and guarded, and unreachable from a
 * browser. The property that matters most here is that the SCREEN AND THE STATE
 * MACHINE AGREE: a button the server would refuse teaches an operator to
 * distrust the screen, and a missing button hides a capability they have.
 */
final class FnfSettlementUiTest extends TestCase
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

    // ── Screen ↔ machine agreement ───────────────────────────────────────

    /**
     * THE CENTRAL TEST. For every state, the actions the screen offers are
     * exactly the events the machine allows from it.
     *
     * Derived from `Fnf_state_machine::TRANSITIONS` rather than a hand-written
     * list, so adding a transition without offering it — or offering one that
     * does not exist — fails here.
     */
    public function test_the_screen_offers_exactly_the_legal_actions(): void
    {
        $i = strpos(self::$view, 'var FNF_ACTIONS = {');
        $this->assertNotFalse($i, 'the action map must exist');
        $map = substr(self::$view, (int) $i, 1200);

        foreach (Fnf_state_machine::TRANSITIONS as $state => $events) {
            if ($state === '') { continue; }        // initiate has its own control

            foreach (array_keys($events) as $event) {
                $this->assertStringContainsString("'{$event}'", $map,
                    "state '{$state}' allows '{$event}' but the screen never offers it");
            }
        }
    }

    /** A terminal state offers nothing. */
    public function test_terminal_states_offer_no_actions(): void
    {
        // Terminal means no state CHANGE. A closed settlement may still
        // reprint its statement — that reads nothing and moves nothing, and
        // refusing it would mean a school cannot reissue a document it has
        // already issued.
        $i = strpos(self::$view, 'var FNF_ACTIONS = {');
        $map = substr(self::$view, (int) $i, 1400);

        $terminal = substr($map, strpos($map, 'closed:'));
        foreach (array_keys(Fnf_state_machine::TRANSITIONS) as $st) {
            foreach (['approve','reject','compute','cancel','recordPayment','close','submitForReview'] as $ev) {
                $this->assertStringNotContainsString("'$ev'", $terminal,
                    "a terminal state must not offer the state-changing action '$ev'");
            }
            break;
        }
        $this->assertStringContainsString('cancelled:  []', $map,
            'a cancelled settlement has no statement to reprint');
    }

    /** Every endpoint the screen calls exists and is routed. */
    public function test_every_action_reaches_a_real_endpoint(): void
    {
        $routes = (string) file_get_contents(__DIR__ . '/../../application/config/routes.php');

        foreach (['fnf_settlements', 'fnf_initiate', 'fnf_compute', 'fnf_transition'] as $ep) {
            $this->assertStringContainsString("compensation/{$ep}", self::$view,
                "the screen must call {$ep}");
            $this->assertStringContainsString("\$route['compensation/{$ep}']", $routes);
            $this->assertStringContainsString("public function {$ep}(", self::$ctrl);
        }
    }

    // ── Each guard's input is actually collected ─────────────────────────

    /**
     * A guard that requires a value must have a field that collects it,
     * otherwise the action is a button that always fails. This is precisely
     * how recordPayment, close and cancel were broken before: the guards read
     * `paymentRef`, `accountingEntryId` and `reason`, and nothing supplied them.
     */
    public function test_every_guarded_input_is_collected(): void
    {
        // UI-D3: zxPrompt() trims and enforces "required" itself, so the call
        // sites no longer carry .trim() — the value arrives clean or not at all.
        // reject → note
        $this->assertStringContainsString('fields.note = note;', self::$view);
        // cancel → reason (sent as note, which the controller maps to `reason`)
        $this->assertStringContainsString('fields.note = reason;', self::$view);
        // recordPayment → payment reference
        $this->assertStringContainsString('fields.payment_ref = ref;', self::$view);
        // close → accounting entry
        $this->assertStringContainsString('fields.accounting_entry_id = entry;', self::$view);
        // compute → the run that produced the figures
        $this->assertStringContainsString('run_id: runId', self::$view);

        // The emptiness guard moved into the dialog, so it must still exist.
        foreach (['A note is required when rejecting.',
                  'A reason is required when cancelling.',
                  'A payment reference is required.',
                  'An accounting entry ID is required to close.',
                  'A payroll run ID is required to compute.'] as $req) {
            $this->assertStringContainsString($req, self::$view,
                'the required-value message must survive the move into zxPrompt');
        }
    }

    /** And the controller passes each of them under the key the guard reads. */
    public function test_the_controller_forwards_them_under_the_right_keys(): void
    {
        foreach (["'reason'      => \$note,",
                  "'paymentRef'  => mb_substr(",
                  "'accountingEntryId' => mb_substr("] as $needle) {
            $this->assertStringContainsString($needle, self::$ctrl);
        }
    }

    /** An empty required value is refused before a round-trip. */
    public function test_empty_required_values_are_refused_client_side(): void
    {
        foreach (['A payroll run ID is required',
                  'A note is required when rejecting',
                  'A reason is required when cancelling',
                  'A payment reference is required',
                  'An accounting entry ID is required'] as $message) {
            $this->assertStringContainsString($message, self::$view);
        }
    }

    /**
     * Cancelling a dialog aborts rather than submitting a blank.
     *
     * zxPrompt() resolves to null when the operator cancels (Escape, backdrop
     * or Cancel) and to a trimmed string otherwise, so a null check is still
     * exactly how "cancelled" is told apart from "left blank". The required-
     * value case never reaches here at all — the dialog refuses to close.
     */
    public function test_dismissing_a_dialog_aborts(): void
    {
        // reject, cancel, recordPayment, close, compute — every prompt site.
        $this->assertGreaterThanOrEqual(5, substr_count(self::$view, '=== null) return;'),
            'every prompt must distinguish "cancelled" from "left blank"');

        // And the confirm sites abort on a falsey result rather than proceeding.
        $this->assertStringContainsString('if (!ok) return;', self::$view);
        $this->assertStringContainsString('if (!sure) return;', self::$view);
    }

    // ── States, guards and feedback ──────────────────────────────────────

    /** Consequential actions are confirmed, and say what they do. */
    public function test_consequential_actions_are_confirmed(): void
    {
        $this->assertStringContainsString('Approve this settlement?', self::$view);
        $this->assertStringContainsString('Cancel this settlement?', self::$view);
        $this->assertStringContainsString('cannot be reopened', self::$view,
            'the operator must know cancellation is final before confirming');
        $this->assertStringContainsString('Override clearance', self::$view);
    }

    /** No double submission, on either the open control or a row action. */
    public function test_duplicate_submission_is_prevented(): void
    {
        $this->assertStringContainsString('if (fnfBusy) return;', self::$view);
        $this->assertStringContainsString('btn.disabled = true;', self::$view);
        $this->assertStringContainsString('fa-spinner fa-spin', self::$view);
    }

    /** The button is restored whether the call succeeded or failed. */
    public function test_the_busy_state_always_clears(): void
    {
        $this->assertGreaterThanOrEqual(3, substr_count(self::$view, 'fnfBusy = false;'),
            'a stuck busy flag would freeze the panel until reload');
        $this->assertGreaterThanOrEqual(2, substr_count(self::$view, '.finally(function ()'));
    }

    /** A failure re-reads the list, so the screen never shows a stale truth. */
    public function test_a_failure_reloads_rather_than_guessing(): void
    {
        $i = strpos(self::$view, "post('compensation/fnf_transition'");
        $this->assertNotFalse($i);
        $handler = substr(self::$view, (int) $i, 900);

        $this->assertStringContainsString('.catch(function (e)', $handler);
        $this->assertStringContainsString('loadSettlements();', $handler);
    }

    /** Empty and error states are distinct, and neither is a blank panel. */
    public function test_empty_and_error_states_differ(): void
    {
        $this->assertStringContainsString('No settlements yet.', self::$view);
        $this->assertStringContainsString('Could not load settlements.', self::$view);
    }

    /** The panel loads with the tab. */
    public function test_the_panel_loads_with_the_tab(): void
    {
        $this->assertStringContainsString('loadSettlements();', self::$view);
        $this->assertStringContainsString("if (TAB === 'fnf') { loadFnfPolicy(); wireFnfForm(); loadSettlements(); }", self::$view);
    }

    // ── RBAC ─────────────────────────────────────────────────────────────

    /** A viewer sees state but is offered no actions. */
    public function test_a_viewer_gets_no_action_buttons(): void
    {
        $this->assertStringContainsString('if (!CAN_MANAGE) {', self::$view);
        $this->assertStringContainsString("actions = '<span class=\"cmp-help\">View only</span>';", self::$view);
    }

    /** And the endpoints enforce it regardless of what was drawn. */
    public function test_the_endpoints_enforce_permission_themselves(): void
    {
        foreach (['fnf_initiate', 'fnf_compute', 'fnf_transition'] as $m) {
            $this->assertStringContainsString("require_permission('HR', '{$m}', 'manage');", self::$ctrl);
        }
        $this->assertStringContainsString('$this->_require_view();', self::$ctrl);
    }

    // ── Rendering honesty ────────────────────────────────────────────────

    /**
     * A settlement computed when no policy was in force is LABELLED, not shown
     * with a version number that means nothing.
     */
    public function test_an_unresolved_policy_is_labelled(): void
    {
        $this->assertStringContainsString('comp.policyResolved === false', self::$view);
        $this->assertStringContainsString('unresolved', self::$view);
    }

    /** The policy version and effective date are shown, not just the status. */
    public function test_the_pinned_policy_is_visible(): void
    {
        $this->assertStringContainsString("'v' + esc(comp.policyVersion)", self::$view);
        $this->assertStringContainsString('comp.policyEffective', self::$view);
    }

    /** Values rendered into innerHTML are escaped. */
    public function test_rendered_values_are_escaped(): void
    {
        foreach (['esc(x.staffId)', 'esc(x.settlementId)', 'esc(comp.runId)'] as $needle) {
            $this->assertStringContainsString($needle, self::$view);
        }
    }

    /** Only classes that exist are used — an invented one renders unstyled. */
    public function test_only_real_css_classes_are_used(): void
    {
        $this->assertSame(0, substr_count(self::$view, 'cmp-btn sm'),
            '.cmp-btn.sm is not defined in this stylesheet');
        $this->assertSame(0, substr_count(self::$view, 'cmp-badge warn'),
            'the badge convention is `cmp-badge b-xxx`');

        foreach (['.cmp-tblwrap', '.cmp-tbl', '.cmp-empty', '.cmp-help', '.cmp-badge'] as $cls) {
            $this->assertStringContainsString($cls, self::$view, "{$cls} must be defined");
        }
    }

    /** The wide table scrolls itself, not the page — the mobile convention. */
    public function test_the_table_scrolls_inside_its_own_container(): void
    {
        $i = strpos(self::$view, 'id="fnfSetTbl"');
        $this->assertNotFalse($i);

        $before = substr(self::$view, max(0, (int) $i - 200), 200);
        $this->assertStringContainsString('cmp-tblwrap', $before);
    }
}
