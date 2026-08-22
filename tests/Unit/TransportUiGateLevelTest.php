<?php
/**
 * Transport UI gate level must not undershoot the server's (2026-08-19).
 *
 * WHY THIS TEST EXISTS. transport/index.php states its own convention:
 *
 *   "these flags exist so a view-only user never sees an enabled control that
 *    would 403 on click. Never treat them as the security boundary."
 *
 * The convention was not held. Eight controls that reach `manage`-level
 * endpoints were gated on `edit`: Add/Edit Route, Add/Edit Stop, Assign Student,
 * Bulk Assign, Change Route/Stop, and the SOS Respond/Resolve actions. An
 * edit-only Transport user saw a fully enabled UI, filled in a form, and got a
 * 403 on save — which teaches people to ignore errors.
 *
 * Security was never at risk: require_permission() on the server is the real
 * boundary and refused every one of those calls. This is about the UI telling
 * the truth about what the user can do.
 *
 * The test is deliberately narrow — it pins the specific controls that were
 * wrong, keyed to the endpoint each one reaches, rather than trying to infer
 * the whole button→endpoint graph from source.
 */

use PHPUnit\Framework\TestCase;

final class TransportUiGateLevelTest extends TestCase
{
    private const VIEW       = __DIR__ . '/../../application/views/transport/index.php';
    private const CONTROLLER = __DIR__ . '/../../application/controllers/Transport.php';
    private const SOS        = __DIR__ . '/../../application/controllers/Transport_sos.php';

    private function view(): string
    {
        $s = (string) file_get_contents(self::VIEW);
        $this->assertNotSame('', $s, 'transport/index.php unreadable');
        return $s;
    }

    /** The level require_permission() enforces for one action, from source. */
    private function serverLevel(string $file, string $action): string
    {
        $src = (string) file_get_contents($file);
        if (!preg_match("/require_permission\('Transport',\s*'" . preg_quote($action, '/')
                        . "',\s*'(view|edit|manage)'\)/", $src, $m)) {
            $this->fail("require_permission for action '{$action}' not found in " . basename($file));
        }
        return $m[1];
    }

    // ── the server side is what the UI must match ────────────────────────

    /** @dataProvider manageActions */
    public function test_action_is_still_manage_level_on_the_server(string $file, string $action): void
    {
        // If one of these is ever relaxed to 'edit', the UI expectation below
        // becomes wrong and this test says so rather than the UI silently
        // over-restricting.
        $this->assertSame('manage', $this->serverLevel($file, $action),
            "'{$action}' is no longer manage-level — revisit the UI gate for it");
    }

    public function manageActions(): array
    {
        return [
            'save_route'     => [self::CONTROLLER, 'save_route'],
            'save_stop'      => [self::CONTROLLER, 'save_stop'],
            'save_assignment'=> [self::CONTROLLER, 'save_assignment'],
            'bulk_assign'    => [self::CONTROLLER, 'bulk_assign'],
            'change_stop'    => [self::CONTROLLER, 'change_stop'],
            'change_route'   => [self::CONTROLLER, 'change_route'],
            'sos_respond'    => [self::SOS,        'sos_respond'],
            'sos_resolve'    => [self::SOS,        'sos_resolve'],
        ];
    }

    // ── the controls that reach them must gate on manage ─────────────────

    /** @dataProvider manageControls */
    public function test_control_gates_on_manage_not_edit(string $needle, string $reaches): void
    {
        $src = $this->view();
        $this->assertStringContainsString($needle, $src,
            "control for {$reaches} not found — was it renamed?");

        // Inspect ONLY this control's own markup. A wider window spills into the
        // NEXT button — which may legitimately be edit-gated — and the assertion
        // then fails on its neighbour. Cut at the control's closing </button>.
        $pos  = strpos($src, $needle);
        $tail = substr($src, $pos, 400);
        $end  = strpos($tail, '</button>');
        $frag = $end === false ? substr($tail, 0, 160) : substr($tail, 0, $end);

        $this->assertDoesNotMatchRegularExpression(
            '/EDIT_ATTR|\$can_edit/', $frag,
            "the control for {$reaches} gates on EDIT while the server requires MANAGE — "
            . 'an edit-only user gets an enabled control that 403s'
        );
        $this->assertMatchesRegularExpression(
            '/MANAGE_ATTR|\$can_manage/', $frag,
            "the control for {$reaches} carries no manage gate at all"
        );
    }

    public function manageControls(): array
    {
        return [
            'Add Route'      => ['TRN.openRouteModal()',  'save_route'],
            'Add Stop'       => ['TRN.openStopModal()',   'save_stop'],
            'Assign Student' => ['TRN.openAsnModal()',    'save_assignment'],
            'Bulk Assign'    => ['TRN.openBulkAssign()',  'bulk_assign'],
            'Edit Route'     => ["TRN.editRoute(\\''+rt.id", 'save_route'],
            'Edit Stop'      => ["TRN.editStop(\\''+s.id",   'save_stop'],
            'Change Route'   => ["'\\',\\'route\\')",         'change_route'],
            'Change Stop'    => ["'\\',\\'stop\\')",          'change_stop'],
            'SOS Respond'    => ["\\'respond\\')",            'sos_respond'],
            'SOS Resolve'    => ["\\'resolve\\')",            'sos_resolve'],
        ];
    }

    // ── the gate constants must keep meaning what their names imply ──────

    public function test_gate_constants_map_to_the_right_capability(): void
    {
        $src = $this->view();
        $this->assertMatchesRegularExpression('/var EDIT_ATTR\s*=\s*CAN_EDIT\s*\?/', $src);
        $this->assertMatchesRegularExpression('/var MANAGE_ATTR\s*=\s*CAN_MANAGE\s*\?/', $src);
        // DEL_ATTR is CAN_MANAGE despite the name — deletes are manage-level.
        // Pinned because the name invites someone to "fix" it to CAN_EDIT.
        $this->assertMatchesRegularExpression('/var DEL_ATTR\s*=\s*CAN_MANAGE\s*\?/', $src,
            'DEL_ATTR must stay CAN_MANAGE — delete endpoints are manage-level');
    }

    // ── the F-3 worklist follows the same rule ───────────────────────────

    public function test_request_worklist_actions_gate_on_manage(): void
    {
        $src = $this->view();
        foreach (['approve_transport_request' => 'TRN.openReqApprove',
                  'reject_transport_request'  => 'TRN.openReqReject'] as $action => $fn) {
            $this->assertSame('manage', $this->serverLevel(self::CONTROLLER, $action));
            $pos = strpos($src, $fn . "(\\'");
            $this->assertNotFalse($pos, "{$fn} control not found");
            $frag = substr($src, $pos, 200);
            $this->assertStringContainsString('MANAGE_ATTR', $frag,
                "{$action} control must gate on manage");
        }
    }
}
