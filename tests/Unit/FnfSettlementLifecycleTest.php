<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../application/libraries/Fnf_state_machine.php';

/**
 * FNF-2 — the settlement lifecycle, made reachable.
 *
 * WHAT ALREADY EXISTED: `Fnf_state_machine` — 7 states, 8 events, and every
 * guard the workflow needs. A policy service. A preview projection. A written
 * design specifying a `staffSettlements` document. All of it correct, and none
 * of it reachable: no routes, no persistence, no UI. The seventh instance in
 * this programme of capability built and way-in absent.
 *
 * WHY BUILDING IT IS ENGINEERING AND NOT A MONEY DECISION. The design is
 * explicit — "The settlement never computes money itself. It records WHICH run
 * produced the figures and freezes the inputs that run consumed." The money
 * comes from an off-cycle payroll run, which already exists (`runType`
 * monthly|offcycle, with the monthly-uniqueness guard already scoped to
 * monthly) and already applies the tenant's statutory config. The lifecycle
 * carries no business assumption, which is why the design says it can ship
 * first.
 */
final class FnfSettlementLifecycleTest extends TestCase
{
    private static string $ctrl = '';

    public static function setUpBeforeClass(): void
    {
        self::$ctrl = (string) file_get_contents(
            __DIR__ . '/../../application/controllers/Compensation.php');
    }

    public static function tearDownAfterClass(): void
    {
        self::$ctrl = '';
    }

    private function machine(): Fnf_state_machine
    {
        return new Fnf_state_machine();
    }

    // ── Reachability ─────────────────────────────────────────────────────

    /** The lifecycle now has endpoints and routes. */
    public function test_the_lifecycle_is_reachable(): void
    {
        foreach (['fnf_settlements', 'fnf_initiate', 'fnf_compute', 'fnf_transition'] as $m) {
            $this->assertStringContainsString("public function {$m}(", self::$ctrl);
        }

        $routes = (string) file_get_contents(__DIR__ . '/../../application/config/routes.php');
        foreach (['fnf_settlements', 'fnf_initiate', 'fnf_compute', 'fnf_transition'] as $r) {
            $this->assertStringContainsString("\$route['compensation/{$r}']", $routes);
        }
    }

    /**
     * ONE endpoint drives every transition. Six near-identical endpoints would
     * be six places to get RBAC, the compare-and-swap or the audit subtly
     * different from each other.
     */
    public function test_one_endpoint_drives_the_machine(): void
    {
        $this->assertStringContainsString('$can = $this->fnfsm->can((string) $doc[\'status\'], $event, $ctx);', self::$ctrl);
        $this->assertStringNotContainsString('public function fnf_approve(', self::$ctrl);
        $this->assertStringNotContainsString('public function fnf_reject(', self::$ctrl);
    }

    // ── The machine still owns what is legal ─────────────────────────────

    /** The controller asks the machine rather than re-deciding legality. */
    public function test_legality_is_decided_in_one_place(): void
    {
        $this->assertSame(3, substr_count(self::$ctrl, '$this->fnfsm->can('),
            'initiate, compute and transition must each consult the machine');
    }

    /** A terminal settlement refuses everything. */
    public function test_a_paid_settlement_cannot_be_changed(): void
    {
        $m = $this->machine();

        foreach ([Fnf_state_machine::E_APPROVE, Fnf_state_machine::E_COMPUTE,
                  Fnf_state_machine::E_CANCEL, Fnf_state_machine::E_REJECT] as $event) {
            $r = $m->can(Fnf_state_machine::S_PAID, $event, ['actorId' => 'a', 'note' => 'x']);
            $this->assertFalse($r['ok'], "{$event} must be refused on a paid settlement");
            $this->assertSame('terminal_state', $r['reason']);
        }
    }

    /** A second settlement cannot be opened while one is open. */
    public function test_only_one_settlement_may_be_open(): void
    {
        $m = $this->machine();

        $r = $m->can('', Fnf_state_machine::E_INITIATE, [
            'hasOpenSettlement' => true, 'noticeStatus' => 'approved',
        ]);
        $this->assertFalse($r['ok']);
        $this->assertSame('settlement_already_open', $r['reason']);
    }

    /** An unsettleable notice period blocks initiation. */
    public function test_an_unsettleable_notice_period_blocks_initiation(): void
    {
        $m = $this->machine();

        $r = $m->can('', Fnf_state_machine::E_INITIATE, [
            'hasOpenSettlement' => false, 'noticeStatus' => 'pending',
        ]);
        $this->assertFalse($r['ok']);
        $this->assertSame('separation_not_settleable', $r['reason']);
    }

    /** Clearance may be overridden — but only explicitly, never by omission. */
    public function test_clearance_is_required_unless_explicitly_overridden(): void
    {
        $m = $this->machine();

        $blocked = $m->can(Fnf_state_machine::S_INITIATED, Fnf_state_machine::E_COMPUTE, []);
        $this->assertFalse($blocked['ok']);
        $this->assertSame('clearance_incomplete', $blocked['reason']);

        $overridden = $m->can(Fnf_state_machine::S_INITIATED, Fnf_state_machine::E_COMPUTE,
            ['clearanceOverride' => true]);
        $this->assertTrue($overridden['ok']);
    }

    /** Review needs a computation — "approved" must mean approved figures. */
    public function test_review_requires_a_computation(): void
    {
        $m = $this->machine();

        $r = $m->can(Fnf_state_machine::S_CALCULATED, Fnf_state_machine::E_SUBMIT_REVIEW, []);
        $this->assertFalse($r['ok']);
        $this->assertSame('no_computation', $r['reason']);
    }

    /** Rejection requires a note. */
    public function test_rejection_requires_a_note(): void
    {
        $m = $this->machine();

        $r = $m->can(Fnf_state_machine::S_IN_REVIEW, Fnf_state_machine::E_REJECT,
            ['actorId' => 'admin-1', 'note' => '']);
        $this->assertFalse($r['ok']);
        $this->assertSame('note_required', $r['reason']);
    }

    // ── Separation of duties ─────────────────────────────────────────────

    /**
     * THE DEFECT I INTRODUCED AND CAUGHT. The machine reads `subjectId` and
     * `initiatorId`; my first version passed `initiatedById` and `approverId`.
     * With neither key present both comparisons see an empty string and pass —
     * the guard would have been INERT, and a reviewer could have approved their
     * own settlement. Exactly the shape this programme keeps finding.
     */
    public function test_the_controller_passes_the_keys_the_machine_reads(): void
    {
        $this->assertStringContainsString("'subjectId'   => (string) (\$doc['staffId'] ?? '')", self::$ctrl);
        $this->assertStringContainsString("'initiatorId' => (string) (\$doc['initiatedById'] ?? '')", self::$ctrl);

        // And the id is actually recorded at initiate, or there is nothing to
        // compare against.
        $this->assertStringContainsString("'initiatedById'   => (string) (\$this->admin_id ?? '')", self::$ctrl);
    }

    /** An employee cannot approve their own settlement. */
    public function test_self_approval_is_refused(): void
    {
        $m = $this->machine();

        $r = $m->can(Fnf_state_machine::S_IN_REVIEW, Fnf_state_machine::E_APPROVE, [
            'actorId' => 'uid-7', 'subjectId' => 'uid-7',
        ]);
        $this->assertFalse($r['ok']);
        $this->assertSame('self_approval_forbidden', $r['reason']);
    }

    /** Nor may whoever opened it also approve it. */
    public function test_the_initiator_cannot_approve(): void
    {
        $m = $this->machine();

        $r = $m->can(Fnf_state_machine::S_IN_REVIEW, Fnf_state_machine::E_APPROVE, [
            'actorId' => 'uid-hr', 'subjectId' => 'uid-leaver', 'initiatorId' => 'uid-hr',
        ]);
        $this->assertFalse($r['ok']);
        $this->assertSame('initiator_cannot_approve', $r['reason']);
    }

    /** A different approver succeeds. */
    public function test_a_second_approver_may_approve(): void
    {
        $m = $this->machine();

        $r = $m->can(Fnf_state_machine::S_IN_REVIEW, Fnf_state_machine::E_APPROVE, [
            'actorId' => 'uid-principal', 'subjectId' => 'uid-leaver', 'initiatorId' => 'uid-hr',
        ]);
        $this->assertTrue($r['ok']);
        $this->assertSame(Fnf_state_machine::S_APPROVED, $r['to']);
    }

    // ── Policy pinning: the effective-dating proof ───────────────────────

    /**
     * THE REQUIREMENT THIS PASS EXISTS TO PROVE. The policy is resolved as of
     * the LAST WORKING DAY, not today — a settlement for someone who left in
     * March must use March's terms even if the policy changed in April.
     */
    public function test_the_policy_is_pinned_to_the_last_working_day(): void
    {
        $i = strpos(self::$ctrl, 'public function fnf_compute');
        $this->assertNotFalse($i);
        $body = substr(self::$ctrl, (int) $i, 4000);

        $this->assertStringContainsString("\$asOfDate = (string) (\$doc['lastWorkingDay'] ?? '');", $body);
        $this->assertStringContainsString('$this->_fnf_policy_as_of($asOfDate)', $body,
            'resolving against today would reprice a March leaver on April terms');

        // And the resolved version is STORED on the settlement.
        $this->assertStringContainsString("'policyVersion'   => \$policyMeta['version'],", $body);
        $this->assertStringContainsString("'policyEffective' => \$policyMeta['effectiveFrom'],", $body);
    }

    /** A settlement computed when no policy was in force says so on its face. */
    public function test_an_unresolvable_policy_is_recorded_not_hidden(): void
    {
        $this->assertStringContainsString("'resolved'      => empty(\$resolved['_asOfUnavailable'])", self::$ctrl);
        $this->assertStringContainsString("'policyResolved'  => \$policyMeta['resolved'],", self::$ctrl);
    }

    /** The last working day is resolved from the notice record, never re-derived. */
    public function test_the_last_working_day_is_resolved_not_recomputed(): void
    {
        $this->assertStringContainsString("\$notice['actualLastWorkingDay']", self::$ctrl);
        $this->assertStringContainsString("\$notice['expectedLastWorkingDay']", self::$ctrl,
            'the notice document is the authority — two derivations would disagree');
    }

    // ── Inputs are frozen ────────────────────────────────────────────────

    /**
     * `inputsSnapshot` is frozen at compute time. Without it, an April edit to a
     * March attendance mark silently changes what was approved — and "approved"
     * would mean nothing.
     */
    public function test_the_inputs_are_frozen_at_compute_time(): void
    {
        $this->assertStringContainsString("'inputsSnapshot' => [", self::$ctrl);
        $this->assertStringContainsString("'frozenAt'       => \$now,", self::$ctrl);
    }

    /** The settlement records WHICH run produced the figures. */
    public function test_the_settlement_records_its_run(): void
    {
        $this->assertStringContainsString("'runId'          => \$runId,", self::$ctrl);
    }

    /**
     * And that run must be OFF-CYCLE. Attaching the monthly run would tie one
     * leaver's settlement to everybody else's payroll.
     */
    public function test_only_an_offcycle_run_may_compute_a_settlement(): void
    {
        $this->assertStringContainsString("!== 'offcycle'", self::$ctrl);
        $this->assertStringContainsString('must be computed from an OFF-CYCLE payroll run', self::$ctrl);
    }

    // ── Concurrency, tenancy, audit ──────────────────────────────────────

    /**
     * Two reviewers approving at once is the ordinary case in a school with a
     * Principal and an Admin. Without the compare-and-swap both would write and
     * the history would record one of them.
     */
    public function test_transitions_are_compare_and_swapped(): void
    {
        $this->assertStringContainsString("'precondition' => ['updateTime' => \$version],", self::$ctrl);
        $this->assertStringContainsString('PRECONDITION_CONFLICT', self::$ctrl);
        $this->assertStringContainsString('Someone else changed this settlement', self::$ctrl);
    }

    /** A settlement from another tenant reads as absent, never as forbidden. */
    public function test_cross_tenant_settlements_are_invisible(): void
    {
        $i = strpos(self::$ctrl, 'private function _fnf_load');
        $this->assertNotFalse($i);
        $body = substr(self::$ctrl, (int) $i, 700);

        $this->assertStringContainsString("!== (string) \$this->school_id) return null;", $body,
            'confirming a foreign settlement exists would itself be a leak');
    }

    /** The open-slot check fails CLOSED — an unreadable check must not open a second. */
    public function test_an_unreadable_open_slot_check_fails_closed(): void
    {
        $i = strpos(self::$ctrl, 'private function _fnf_context');
        $this->assertNotFalse($i);

        // Bound the window at the NEXT method rather than a fixed byte count.
        // A 1400-char slice silently stops covering the method the moment
        // anything is added above the code it checks — which is how this test
        // reported a fail-open that had never happened: the guard was intact,
        // the window had simply moved off it.
        $j = strpos(self::$ctrl, "\n    private function ", (int) $i + 10);
        if ($j === false) { $j = strpos(self::$ctrl, "\n    public function ", (int) $i + 10); }
        $body = substr(self::$ctrl, (int) $i, ($j === false ? 4000 : $j - (int) $i));

        $this->assertStringContainsString("\$ctx['hasOpenSettlement'] = true;", $body);
        $this->assertStringContainsString('Fail CLOSED', $body);
    }

    /** Every transition is audited with its target state. */
    public function test_every_transition_is_audited(): void
    {
        $this->assertStringContainsString("\$this->_fnf_audit(", self::$ctrl);
        $this->assertStringContainsString("'FNF_' . strtoupper(\$event)", self::$ctrl);
    }

    /** Status history accumulates rather than being overwritten. */
    public function test_status_history_accumulates(): void
    {
        $this->assertStringContainsString("\$hist = is_array(\$doc['statusHistory'] ?? null) ? \$doc['statusHistory'] : [];", self::$ctrl);
        $this->assertStringContainsString("\$patch['statusHistory'] = \$hist;", self::$ctrl);
    }

    // ── Refusals are actionable ──────────────────────────────────────────

    /**
     * Every refusal the machine can emit is turned into something an
     * administrator can act on. A queue that says `separation_not_settleable`
     * is a support ticket.
     */
    public function test_every_refusal_code_has_an_actionable_message(): void
    {
        $src = (string) file_get_contents(
            __DIR__ . '/../../application/libraries/Fnf_state_machine.php');

        // EVERY quoted snake_case literal in the guard body — not merely
        // `return 'x';`. An earlier version matched only the plain form and so
        // missed the ternary guards, which is exactly how three refusal codes
        // (payment reference, accounting entry, cancel reason) went unmapped
        // while this test reported full coverage.
        $g = strpos($src, 'private function guard(');
        $this->assertNotFalse($g);
        $guardBody = substr($src, (int) $g, 1800);

        // Only literals inside a RETURN. The guard body also names context keys
        // ($context['reason']) which are not refusal codes, and matching every
        // quoted literal made this demand a message for one of those.
        // Strip $context['...'] lookups FIRST: they sit inside the same return
        // statement as the code, and a non-greedy match grabs the key rather
        // than the refusal ('reason' instead of 'reason_required').
        $guardBody = preg_replace('~\$context\[[^\]]*\]~', 'CTX', $guardBody);

        preg_match_all("~return[^;]*?'([a-z][a-z_]{4,})'~", $guardBody, $m);
        $codes = array_values(array_unique($m[1]));

        $this->assertNotEmpty($codes);

        $unmapped = [];
        foreach ($codes as $c) {
            if (strpos(self::$ctrl, "case '{$c}':") === false) { $unmapped[] = $c; }
        }

        $this->assertSame([], $unmapped,
            'these refusals would reach an administrator as raw codes: ' . implode(', ', $unmapped));
    }
}
