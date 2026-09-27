<?php

use PHPUnit\Framework\TestCase;

if (!class_exists('Fee_firestore_txn')) {
    require_once APPPATH . 'libraries/Fee_firestore_txn.php';
}

/**
 * ══════════════════════════════════════════════════════════════════════════
 *  ROLL-2 — TRANSPORT BILLING DID NOT ROLL OVER
 * ══════════════════════════════════════════════════════════════════════════
 *
 * `Transport_service::rolloverSession($from, $to)` does two things per student:
 *
 *     cancelSessionDemands($sid, $fromSession, 'Transport');
 *     createModuleFee($sid, 'Transport', [...]);
 *
 * The first derives its `periodKey` from the **passed** session, so it archives
 * the right demand. The second derived its period — and therefore the **demand
 * id** — from `$this->sessionYear`, the session the `Fee_lifecycle` instance
 * happened to be inited with.
 *
 * `Transport_rollover::__construct` inits it with `$this->session_year`: the
 * session the ADMIN is operating in, which during a year-end rollover is
 * normally the session being rolled OUT of.
 *
 * ── THE RESULT ────────────────────────────────────────────────────────────
 * Both calls then compute the SAME periodKey and therefore the same demand id.
 * The demand archived a moment earlier was immediately rewritten at full fare
 * with status 'unpaid' — and the new session received **no transport demand at
 * all**. Transport billing simply did not roll over, and last year's dues
 * reappeared.
 *
 * ── WHY IT WAS NOT ALWAYS BROKEN ──────────────────────────────────────────
 * It was silently correct when the admin had already switched to the new
 * session before running the rollover. Nothing validated that: `rolloverSession()`
 * takes both sessions as free-form POST parameters and compares neither against
 * its own `sessionYear`. Correctness depended on an unstated precondition, which
 * is why it could survive — half the time it worked.
 *
 * ── WHY A NEW Fee_lifecycle WOULD NOT HAVE FIXED IT ───────────────────────
 * Worth recording, because it is the obvious fix and it does not work.
 * `Fee_lifecycle::init()` reuses the shared `$CI->fsTxn` when one already
 * exists and only inits it when absent, so a freshly constructed lifecycle
 * still writes through a `Fee_firestore_txn` bound to the ORIGINAL session —
 * and `_buildDemandDoc()` stamps `'session' => $this->session` from that txn.
 * The target session has to be threaded through the payload.
 */
class TransportRolloverSessionTest extends TestCase
{
    private static string $lifecycle = '';
    private static string $transport = '';

    /** @var Fee_firestore_txn */
    private $txn;

    public static function setUpBeforeClass(): void
    {
        self::$lifecycle = (string) file_get_contents(APPPATH . 'libraries/Fee_lifecycle.php');
        self::$transport = (string) file_get_contents(APPPATH . 'libraries/Transport_service.php');
    }

    protected function setUp(): void
    {
        $this->txn = (new ReflectionClass('Fee_firestore_txn'))->newInstanceWithoutConstructor();

        // Bind the instance to 2026-27, the session an admin would be in while
        // rolling over into 2027-28.
        $r = new ReflectionProperty('Fee_firestore_txn', 'session');
        $r->setAccessible(true);
        $r->setValue($this->txn, '2026-27');
    }

    private function build(array $data): array
    {
        $m = new ReflectionMethod('Fee_firestore_txn', '_buildDemandDoc');
        $m->setAccessible(true);
        return $m->invoke($this->txn, 'DEM_TEST', $data);
    }

    private function payload(array $over = []): array
    {
        return array_merge([
            'studentId'   => 'STU0012',
            'feeHead'     => 'Transport Fee',
            'category'    => 'transport',
            'frequency'   => 'Annual',
            'period'      => 'April 2027',
            'periodKey'   => '2027-04',
            'grossAmount' => 12000.0,
            'netAmount'   => 12000.0,
        ], $over);
    }

    // ══ THE WRITE BOUNDARY ══════════════════════════════════════════════════

    /**
     * WOULD HAVE FAILED BEFORE: `'session' => $this->session` was
     * unconditional, so a demand could only ever be written into the session
     * the txn instance was inited with.
     */
    public function test_a_caller_can_write_a_demand_into_an_explicit_session(): void
    {
        $doc = $this->build($this->payload(['session' => '2027-28']));

        $this->assertSame('2027-28', $doc['session'],
            'the write boundary ignores an explicit target session, so a rollover cannot '
            . 'write into the session it is rolling INTO');
    }

    /**
     * Defaulted. Every existing caller passes no session (verified by search),
     * so every current write must be byte-identical.
     */
    public function test_a_caller_that_names_no_session_still_gets_the_instance_session(): void
    {
        $doc = $this->build($this->payload());

        $this->assertSame('2026-27', $doc['session'],
            'omitting the session no longer falls back to the instance session — every '
            . 'existing caller would start writing an empty session onto its demands');
    }

    /** An empty or whitespace session must fall back, not blank the field. */
    public function test_an_empty_session_falls_back_rather_than_blanking_the_field(): void
    {
        foreach (['', null] as $bad) {
            $doc = $this->build($this->payload(['session' => $bad]));

            $this->assertSame('2026-27', $doc['session'],
                'an empty target session was written through, leaving a demand with no '
                . 'session at all — invisible to every session-scoped query in the system');
        }
    }

    // ══ THE PERIOD, AND THEREFORE THE DEMAND ID ═════════════════════════════

    /**
     * The period is what makes the demand id differ between sessions. Deriving
     * it from the instance session is the actual defect; the stored `session`
     * field alone would not have prevented the collision.
     */
    public function test_the_module_period_is_derived_from_the_target_session(): void
    {
        $at = strpos(self::$lifecycle, 'public function createModuleFee');
        $this->assertNotFalse($at);
        $end  = strpos(self::$lifecycle, "\n    public function ", $at + 30);
        $body = substr(self::$lifecycle, $at, ($end !== false ? $end - $at : 20000));

        $this->assertStringContainsString('$year       = (int) substr($targetSession, 0, 4);', $body,
            'the module period is still derived from the instance session. During a rollover '
            . 'that produces the SAME periodKey as the demand just archived, so the new demand '
            . 'lands on the old id and the new session gets nothing.');

        $this->assertStringContainsString("\$targetSession = trim((string) (\$feeData['session'] ?? ''));", $body,
            'createModuleFee no longer accepts a target session');
        $this->assertStringContainsString("if (\$targetSession === '') \$targetSession = \$this->sessionYear;", $body,
            'the target session no longer defaults to the instance session, so every existing '
            . 'module caller would break');
    }

    /** The target session must reach the document, not just the period. */
    public function test_the_target_session_is_forwarded_to_the_write(): void
    {
        $at   = strpos(self::$lifecycle, 'public function createModuleFee');
        $end  = strpos(self::$lifecycle, "\n    public function ", $at + 30);
        $body = substr(self::$lifecycle, $at, ($end !== false ? $end - $at : 20000));

        $this->assertStringContainsString("'session'     => \$targetSession,", $body,
            'the demand is written without the target session, so its periodKey says one '
            . 'session and its session field says another');
    }

    // ══ NEVER SILENT ════════════════════════════════════════════════════════

    /**
     * Writing a charge into another session is right for a rollover and wrong
     * everywhere else, and the two are indistinguishable without a record.
     */
    public function test_a_cross_session_charge_is_reported(): void
    {
        $at   = strpos(self::$lifecycle, 'public function createModuleFee');
        $end  = strpos(self::$lifecycle, "\n    public function ", $at + 30);
        $body = substr(self::$lifecycle, $at, ($end !== false ? $end - $at : 20000));

        $this->assertStringContainsString('ACC_MODULE_FEE_CROSS_SESSION', $body,
            'a charge raised into a different session is silent. A stray session value in a '
            . 'module payload would move a charge to another year with nothing to grep for.');
        $this->assertStringContainsString('if ($targetSession !== $this->sessionYear) {', $body);
    }

    // ══ THE ROLLOVER USES IT ════════════════════════════════════════════════

    /** WOULD HAVE FAILED BEFORE: the rollover passed no session. */
    public function test_the_rollover_names_the_session_it_is_rolling_into(): void
    {
        $at = strpos(self::$transport, 'public function rolloverSession');
        $this->assertNotFalse($at, 'rolloverSession was renamed');

        $end  = strpos(self::$transport, "\n    public function ", $at + 30);
        $body = substr(self::$transport, $at, ($end !== false ? $end - $at : 40000));

        $this->assertStringContainsString("'session'    => \$toSession,", $body,
            'the rollover still raises the new-session transport charge without naming the '
            . 'target session, so it lands on the demand id it just archived');

        // And the cancel must still target the FROM session.
        $this->assertStringContainsString('cancelSessionDemands($sid, $fromSession', $body,
            'the rollover no longer cancels the outgoing session\'s demands by name');
    }

    /**
     * Order matters: cancel the old, then create the new. Reversed, the create
     * would be archived by the cancel that follows it.
     */
    public function test_the_old_session_is_cancelled_before_the_new_one_is_raised(): void
    {
        $at   = strpos(self::$transport, 'public function rolloverSession');
        $end  = strpos(self::$transport, "\n    public function ", $at + 30);
        $body = substr(self::$transport, $at, ($end !== false ? $end - $at : 40000));

        $cancelAt = strpos($body, 'cancelSessionDemands($sid, $fromSession');
        $createAt = strpos($body, "'session'    => \$toSession,");

        $this->assertNotFalse($cancelAt);
        $this->assertNotFalse($createAt);
        $this->assertLessThan($createAt, $cancelAt,
            'the new-session demand is raised before the old session is cancelled, so the '
            . 'cancel would archive the demand just created');
    }

    /**
     * A suspended assignment must not be re-billed by a rollover. G1 already
     * closed this; pinned because the session fix touches the same block and
     * the two must not be conflated.
     */
    public function test_a_suspended_assignment_is_still_not_rebilled_on_rollover(): void
    {
        $at   = strpos(self::$transport, 'public function rolloverSession');
        $end  = strpos(self::$transport, "\n    public function ", $at + 30);
        $body = substr(self::$transport, $at, ($end !== false ? $end - $at : 40000));

        $this->assertStringContainsString('if ($this->_isSuspended($row)) {', $body,
            'the suspended-assignment skip was lost, so a suspended student would silently '
            . 'start being billed again on rollover with zero operator action');
    }

    // ══ THE ANCHOR STILL TRAVELS ════════════════════════════════════════════

    /**
     * PRORATE-1 refreshes the proration anchor from `createModuleFee`. A
     * rollover writes a brand-new demand id, so there is no stale anchor to
     * inherit — but the anchor must still be written, or the first mid-year
     * route change in the new session prorates from nothing.
     */
    public function test_the_rollover_demand_still_carries_a_fresh_proration_anchor(): void
    {
        $at   = strpos(self::$lifecycle, 'public function createModuleFee');
        $end  = strpos(self::$lifecycle, "\n    public function ", $at + 30);
        $body = substr(self::$lifecycle, $at, ($end !== false ? $end - $at : 20000));

        $this->assertStringContainsString("'listGrossAmount'    => \$amount", $body,
            'PRORATE-1 regressed while adding the session override — a route change would '
            . 'again prorate from a stale list price');
    }
}
