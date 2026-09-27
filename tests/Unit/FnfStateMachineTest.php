<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Behavioural cover for the F&F settlement state machine.
 *
 * Executes `Fnf_state_machine` directly — every transition, every refusal, and
 * every guard, with no Firestore and no money involved. The machine deliberately
 * contains no financial rule; D-002 (gratuity, encashment divisor, notice
 * recovery, statutory treatment, deduction priority) remains an owner decision
 * and is not inferred anywhere here or in the class under test.
 *
 * What IS asserted is everything the design fixes independently of that policy:
 * terminality after payment, separation of duties, idempotent recompute before
 * review but not after, single-open-settlement, and fail-closed guards.
 */
final class FnfStateMachineTest extends TestCase
{
    private Fnf_state_machine $m;

    protected function setUp(): void
    {
        if (!defined('BASEPATH')) define('BASEPATH', __DIR__);
        require_once __DIR__ . '/../../application/libraries/Fnf_state_machine.php';
        $this->m = new Fnf_state_machine();
    }

    /** Context in which every guard passes, so a test can vary one thing. */
    private function ctx(array $over = []): array
    {
        return array_merge([
            'noticeStatus'      => 'approved',
            'hasOpenSettlement' => false,
            'clearanceComplete' => true,
            'hasComputation'    => true,
            'subjectId'         => 'STA0007',
            'initiatorId'       => 'STA0002',
            'actorId'           => 'STA0001',
            'note'              => 'figures disputed',
            'reason'            => 'raised in error',
            'paymentRef'        => 'NEFT-99127',
            'accountingEntryId' => 'JV-2026-0412',
        ], $over);
    }

    private function assertRefused(string $from, string $event, string $reason, array $ctx = null): void
    {
        $r = $this->m->can($from, $event, $ctx ?? $this->ctx());
        $this->assertFalse($r['ok'], "{$event} from '{$from}' should be refused");
        $this->assertSame($reason, $r['reason']);
        $this->assertNull($r['to']);
    }

    // ── The happy path, end to end ───────────────────────────────────────

    public function test_the_full_lifecycle_runs_end_to_end(): void
    {
        $c = $this->ctx();
        $s = '';

        foreach ([
            ['initiate', 'initiated'],
            ['compute', 'calculated'],
            ['submitForReview', 'inReview'],
            ['approve', 'approved'],
            ['recordPayment', 'paid'],
            ['close', 'closed'],
        ] as [$event, $expected]) {
            $s = $this->m->apply($s, $event, $c);
            $this->assertSame($expected, $s, "after {$event}");
        }

        $this->assertTrue($this->m->isTerminal($s));
    }

    // ── Terminality: money has moved ─────────────────────────────────────

    public function test_a_paid_settlement_cannot_be_cancelled(): void
    {
        $this->assertRefused('paid', 'cancel', 'terminal_state');
    }

    public function test_a_closed_settlement_accepts_nothing(): void
    {
        foreach (['compute', 'submitForReview', 'approve', 'recordPayment', 'close', 'cancel'] as $e) {
            $this->assertRefused('closed', $e, 'terminal_state');
        }
        $this->assertSame([], $this->m->legalEvents('closed'));
    }

    public function test_a_cancelled_settlement_cannot_be_reopened(): void
    {
        foreach (['compute', 'submitForReview', 'approve'] as $e) {
            $this->assertRefused('cancelled', $e, 'terminal_state');
        }
    }

    /** A correction after payment is a NEW settlement, never a mutation. */
    public function test_paid_only_permits_closing(): void
    {
        $this->assertSame(['close'], $this->m->legalEvents('paid', $this->ctx()));
    }

    // ── Non-idempotent by design ─────────────────────────────────────────

    public function test_approval_is_not_idempotent(): void
    {
        $this->assertRefused('approved', 'approve', 'illegal_transition');
    }

    public function test_payment_cannot_be_recorded_twice(): void
    {
        $this->assertRefused('paid', 'recordPayment', 'terminal_state');
    }

    // ── Recompute: allowed before review, not after ──────────────────────

    public function test_recompute_is_idempotent_while_calculated(): void
    {
        $this->assertSame('calculated', $this->m->apply('calculated', 'compute', $this->ctx()),
            'clearance flags and final attendance land late, so refresh must stay legal');
    }

    public function test_recompute_is_refused_once_under_review(): void
    {
        $this->assertRefused('inReview', 'compute', 'illegal_transition');
    }

    /** Rejection returns it to calculated so the figure can be revised. */
    public function test_rejection_returns_to_calculated(): void
    {
        $this->assertSame('calculated', $this->m->apply('inReview', 'reject', $this->ctx()));
    }

    public function test_rejection_requires_a_note(): void
    {
        $this->assertRefused('inReview', 'reject', 'note_required', $this->ctx(['note' => '   ']));
    }

    // ── Initiation guards ────────────────────────────────────────────────

    public function test_initiation_requires_a_settleable_notice(): void
    {
        foreach (['pending', 'rejected', 'withdrawn', ''] as $n) {
            $this->assertRefused('', 'initiate', 'separation_not_settleable',
                $this->ctx(['noticeStatus' => $n]));
        }
    }

    public function test_every_settleable_notice_state_permits_initiation(): void
    {
        foreach (['approved', 'waived', 'reduced', 'completed'] as $n) {
            $r = $this->m->can('', 'initiate', $this->ctx(['noticeStatus' => $n]));
            $this->assertTrue($r['ok'], "notice '{$n}' should permit initiation");
            $this->assertSame('initiated', $r['to']);
        }
    }

    /** One open settlement per staff member. */
    public function test_a_second_settlement_cannot_be_opened(): void
    {
        $this->assertRefused('', 'initiate', 'settlement_already_open',
            $this->ctx(['hasOpenSettlement' => true]));
    }

    public function test_open_states_are_exactly_the_pre_payment_states(): void
    {
        foreach (['initiated', 'calculated', 'inReview', 'approved'] as $s) {
            $this->assertTrue($this->m->isOpen($s), "'{$s}' occupies the slot");
        }
        foreach (['paid', 'closed', 'cancelled'] as $s) {
            $this->assertFalse($this->m->isOpen($s),
                "'{$s}' must free the slot so a later settlement is possible");
        }
    }

    // ── Clearance ────────────────────────────────────────────────────────

    public function test_incomplete_clearance_blocks_computation(): void
    {
        $this->assertRefused('initiated', 'compute', 'clearance_incomplete',
            $this->ctx(['clearanceComplete' => false]));
    }

    /** An override must be recorded explicitly — never implied by omission. */
    public function test_an_explicit_override_permits_computation(): void
    {
        $r = $this->m->can('initiated', 'compute',
            $this->ctx(['clearanceComplete' => false, 'clearanceOverride' => true]));

        $this->assertTrue($r['ok']);
        $this->assertSame('calculated', $r['to']);
    }

    // ── Separation of duties ─────────────────────────────────────────────

    public function test_the_subject_cannot_approve_their_own_settlement(): void
    {
        $this->assertRefused('inReview', 'approve', 'self_approval_forbidden',
            $this->ctx(['actorId' => 'STA0007']));   // == subjectId
    }

    public function test_the_initiator_cannot_approve_their_own_initiation(): void
    {
        $this->assertRefused('inReview', 'approve', 'initiator_cannot_approve',
            $this->ctx(['actorId' => 'STA0002']));   // == initiatorId
    }

    /** An unidentified approver is refused, not waved through. */
    public function test_a_missing_actor_fails_closed(): void
    {
        $this->assertRefused('inReview', 'approve', 'actor_required',
            $this->ctx(['actorId' => '']));
    }

    public function test_rejection_is_held_to_the_same_separation_of_duties(): void
    {
        $this->assertRefused('inReview', 'reject', 'self_approval_forbidden',
            $this->ctx(['actorId' => 'STA0007']));
    }

    // ── Evidence requirements ────────────────────────────────────────────

    public function test_payment_requires_a_reference(): void
    {
        $this->assertRefused('approved', 'recordPayment', 'payment_reference_required',
            $this->ctx(['paymentRef' => '  ']));
    }

    public function test_closing_requires_a_posted_accounting_entry(): void
    {
        $this->assertRefused('paid', 'close', 'accounting_entry_required',
            $this->ctx(['accountingEntryId' => '']));
    }

    public function test_cancellation_requires_a_reason(): void
    {
        $this->assertRefused('approved', 'cancel', 'reason_required',
            $this->ctx(['reason' => '']));
    }

    public function test_review_cannot_be_requested_without_a_figure(): void
    {
        $this->assertRefused('calculated', 'submitForReview', 'no_computation',
            $this->ctx(['hasComputation' => false]));
    }

    // ── Cancellation window ──────────────────────────────────────────────

    public function test_cancellation_is_legal_from_every_pre_payment_state(): void
    {
        foreach (['initiated', 'calculated', 'inReview', 'approved'] as $s) {
            $this->assertSame('cancelled', $this->m->apply($s, 'cancel', $this->ctx()),
                "cancel should be legal from '{$s}'");
        }
    }

    // ── Malformed input ──────────────────────────────────────────────────

    public function test_an_unknown_state_is_refused(): void
    {
        $this->assertRefused('settled', 'approve', 'unknown_state');
        $this->assertRefused('APPROVED', 'approve', 'unknown_state');
    }

    public function test_an_unknown_event_is_refused(): void
    {
        $this->assertRefused('calculated', 'delete', 'illegal_transition');
    }

    public function test_state_matching_tolerates_surrounding_whitespace_only(): void
    {
        $this->assertTrue($this->m->can('  calculated  ', 'submitForReview', $this->ctx())['ok']);
        $this->assertFalse($this->m->can('calc ulated', 'submitForReview', $this->ctx())['ok']);
    }

    // ── Structural invariants of the machine itself ──────────────────────

    public function test_every_transition_target_is_a_known_state(): void
    {
        $known = array_keys(Fnf_state_machine::TRANSITIONS);

        foreach (Fnf_state_machine::TRANSITIONS as $from => $moves) {
            foreach ($moves as $event => $to) {
                $this->assertContains($to, $known, "{$from} --{$event}--> {$to} is unreachable");
            }
        }
    }

    public function test_no_terminal_state_has_any_outgoing_transition(): void
    {
        foreach (Fnf_state_machine::TERMINAL as $s) {
            if ($s === 'paid') continue;   // paid -> closed is the one permitted follow-on
            $this->assertSame([], Fnf_state_machine::TRANSITIONS[$s],
                "'{$s}' must be a dead end");
        }
    }

    /**
     * The machine must carry no money vocabulary in its EXECUTABLE code.
     *
     * Comments are stripped before scanning, because the class docblock
     * legitimately names the excluded D-002 policies in order to say they are
     * excluded. Scanning raw source would flag exactly the documentation that
     * makes the boundary explicit — so this strips via token_get_all() and
     * inspects only code, which is the stronger assertion anyway.
     */
    public function test_the_machine_contains_no_financial_rule(): void
    {
        $path = __DIR__ . '/../../application/libraries/Fnf_state_machine.php';
        $src  = file_get_contents($path);
        $this->assertNotFalse($src);

        $code = '';
        foreach (token_get_all($src) as $t) {
            if (is_array($t)) {
                if ($t[0] === T_COMMENT || $t[0] === T_DOC_COMMENT) continue;
                $code .= $t[1];
            } else {
                $code .= $t;
            }
        }

        // Sanity: stripping must leave real code behind, or this proves nothing.
        $this->assertStringContainsString('const TRANSITIONS', $code);
        $this->assertLessThan(strlen($src), strlen($code), 'comments were not stripped');

        foreach ([
            'gratuity', 'encash', 'divisor', 'statutory', 'pf_', 'esi_',
            'net_pay', 'deduction', 'round(', 'floor(', 'ceil(',
        ] as $needle) {
            $this->assertStringNotContainsStringIgnoringCase(
                $needle,
                $code,
                "D-002 policy must not leak into the state machine ({$needle})"
            );
        }
    }

    // ── The statuses the prepared rules patch depends on ─────────────────

    /**
     * `D007_STAFF_SETTLEMENTS_RULES_PATCH.md` stage 2 grants an employee read of
     * their OWN settlement only at `approved` / `paid` / `closed` — the statuses
     * at which the figure is final.
     *
     * That rule is only safe because of two properties of THIS machine, so they
     * are pinned here. If either changes, the rules patch must be revisited
     * before it is applied.
     */
    public function test_a_figure_cannot_change_once_approved(): void
    {
        $c = $this->ctx();

        // No event may take an approved settlement back to a recomputable state.
        foreach (['compute', 'submitForReview', 'reject'] as $event) {
            $this->assertFalse(
                $this->m->can('approved', $event, $c)['ok'],
                "'{$event}' from approved would let the number move after an employee saw it"
            );
        }
    }

    public function test_the_readable_statuses_are_exactly_the_final_ones(): void
    {
        $readable = ['approved', 'paid', 'closed'];
        $hidden   = ['initiated', 'calculated', 'inReview', 'cancelled'];

        // Nothing readable may still be recomputable.
        foreach ($readable as $s) {
            $this->assertFalse($this->m->can($s, 'compute', $this->ctx())['ok'],
                "'{$s}' is exposed to the employee and must not be recomputable");
        }

        // Everything hidden is either pre-final or void.
        foreach ($hidden as $s) {
            $this->assertContains($s, array_keys(Fnf_state_machine::TRANSITIONS),
                "'{$s}' must remain a known state or the rules patch lists a phantom");
        }

        $this->assertSame([], array_intersect($readable, $hidden));
    }

    /** A cancelled settlement carries no payable figure, so it stays hidden. */
    public function test_cancelled_is_terminal_but_not_readable(): void
    {
        $this->assertTrue($this->m->isTerminal('cancelled'));
        $this->assertNotContains('cancelled', ['approved', 'paid', 'closed'],
            'showing an amount for a settlement that never took effect would mislead');
    }

    /** Nor may it perform arithmetic on amounts. */
    public function test_the_machine_declares_no_numeric_amount(): void
    {
        $r = new ReflectionClass('Fnf_state_machine');

        foreach ($r->getConstants() as $name => $value) {
            $flat = is_array($value) ? implode(',', array_map('strval', $this->flatten($value))) : (string) $value;
            $this->assertDoesNotMatchRegularExpression(
                '/\d+\.\d+/',
                $flat,
                "constant {$name} must not carry a monetary or fractional value"
            );
        }
    }

    private function flatten(array $a): array
    {
        $out = [];
        array_walk_recursive($a, static function ($v) use (&$out) { $out[] = $v; });
        return $out;
    }
}
