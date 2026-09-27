<?php
namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Trail 2 (Work Schedule → Calendar → Attendance → Leave/LOP → Payroll),
 * Phase E, 2026-08-18.
 *
 * Prior audits (this same programme) confirmed COMP_OFF existed only as a
 * seeded leave-type row with days_per_year=0 — no earn/consume ledger, so
 * it could never behave as a genuine earned benefit. This adds the
 * lifecycle: earn (tied to the EXISTING 'W' extraWorked attendance mark,
 * never a new eligibility judgement), a separate ledger balance source
 * (never touching the standard allocated/carried/used formula any other
 * leave type uses), FIFO whole-credit consumption at approval, and release
 * on cancellation/write-failure rollback.
 *
 * Deliberately deferred: automatic expiry/forfeiture of unused credits —
 * an `expiresOn` field exists but nothing sweeps it. Discarding earned
 * time off without an explicit, tenant-confirmed policy is a financial-
 * consequence decision this phase must not invent.
 *
 * Source-regression tests (Firestore_service has no behavioral mock for
 * controller code).
 */
class CompOffLedgerTest extends TestCase
{
    private string $controllerSrc;

    protected function setUp(): void
    {
        $raw = file_get_contents(__DIR__ . '/../../application/controllers/Hr.php');
        $this->assertNotFalse($raw);
        $out = '';
        foreach (token_get_all($raw) as $token) {
            if (is_array($token)) {
                if ($token[0] === T_COMMENT || $token[0] === T_DOC_COMMENT) continue;
                $out .= $token[1];
            } else {
                $out .= $token;
            }
        }
        $this->controllerSrc = $out;
    }

    private function extractFunctionBody(string $src, int $functionKeywordOffset): string
    {
        $openBrace = strpos($src, '{', $functionKeywordOffset);
        $this->assertNotFalse($openBrace);
        $depth = 0;
        $len = strlen($src);
        for ($i = $openBrace; $i < $len; $i++) {
            if ($src[$i] === '{') $depth++;
            elseif ($src[$i] === '}') {
                $depth--;
                if ($depth === 0) return substr($src, $openBrace, $i - $openBrace + 1);
            }
        }
        $this->fail('Unbalanced braces while extracting function body.');
    }

    private function fnBody(string $name): string
    {
        $start = strpos($this->controllerSrc, "function {$name}(");
        $this->assertNotFalse($start, "function {$name}() not found");
        return $this->extractFunctionBody($this->controllerSrc, $start);
    }

    // ── Earn trigger reuses the existing 'W' fact, invents nothing ───────

    public function test_earn_requires_the_existing_w_extra_worked_mark(): void
    {
        $body = $this->fnBody('earn_comp_off');
        $this->assertStringContainsString("\$mark = is_array(\$attDoc) ? strtoupper((string) (\$attDoc['status'] ?? '')) : '';", $body);
        $this->assertStringContainsString("if (\$mark !== 'W') {", $body);
    }

    public function test_earn_refuses_a_future_date(): void
    {
        $body = $this->fnBody('earn_comp_off');
        $this->assertStringContainsString("if (\$date > date('Y-m-d')) {", $body);
    }

    public function test_earn_refuses_for_an_inactive_staff_member(): void
    {
        $body = $this->fnBody('earn_comp_off');
        $this->assertStringContainsString("if (strtolower(trim(\$rawStatus)) !== 'active') {", $body);
    }

    public function test_earn_is_idempotent_via_deterministic_doc_id(): void
    {
        $body = $this->fnBody('earn_comp_off');
        $this->assertStringContainsString('$docId = $this->_comp_off_ledger_doc_id($staffId, $date);', $body);
        $this->assertStringContainsString('already credited for this date', $body);
    }

    public function test_earn_uses_a_documented_technical_default_not_an_invented_rate(): void
    {
        $ledgerDocId = strpos($this->controllerSrc, 'private const COMP_OFF_DEFAULT_CREDIT_DAYS');
        $this->assertNotFalse($ledgerDocId);
        $this->assertStringContainsString('COMP_OFF_DEFAULT_CREDIT_DAYS = 1.0;', $this->controllerSrc);
    }

    // ── RBAC preserved — same gates as the rest of Leave ──────────────────

    public function test_earn_comp_off_uses_hr_edit_gate(): void
    {
        $body = $this->fnBody('earn_comp_off');
        $this->assertStringContainsString("_require_role(self::HR_ROLES, 'earn_comp_off', 'HR', 'edit')", $body);
    }

    public function test_get_comp_off_ledger_restricts_non_hr_callers_to_their_own_ledger(): void
    {
        $body = $this->fnBody('get_comp_off_ledger');
        $this->assertStringContainsString("if (!(function_exists('has_permission') && has_permission('HR', 'view'))) {", $body);
        $this->assertStringContainsString('$staffId = $ownId;', $body);
    }

    // ── Balance is a separate source, never the standard formula ─────────

    public function test_apply_leave_reads_comp_off_balance_from_the_ledger_not_the_standard_formula(): void
    {
        $body = $this->fnBody('apply_leave');
        $this->assertStringContainsString('if ($leaveCode === self::COMP_OFF_TYPE_CODE) {', $body);
        $this->assertStringContainsString('$currentBalance = $this->_comp_off_available_balance($staffId);', $body);
    }

    public function test_decide_leave_never_writes_comp_off_into_the_standard_balance_doc(): void
    {
        $body = $this->fnBody('decide_leave');
        $this->assertStringContainsString('if ($decision === \'Approved\' && $isCompOff) {', $body);
        $this->assertStringContainsString('$result = $this->_consumeCompOffLedger($staffId, $days);', $body);
        // The elseif branch (the only place _casLeaveBalance is called for a
        // decision) must be reachable only when NOT Comp-Off.
        $this->assertStringContainsString("} elseif (\$decision === 'Approved') {", $body);
    }

    // ── FIFO, whole-credit consumption; never double-spent ────────────────

    public function test_ledger_entries_are_consumed_oldest_first(): void
    {
        $body = $this->fnBody('_comp_off_available_entries');
        $this->assertStringContainsString("usort(\$entries, fn(\$a, \$b) => strcmp((string) (\$a['earnedDate'] ?? ''), (string) (\$b['earnedDate'] ?? '')));", $body);
    }

    public function test_consumption_is_cas_protected_per_credit_never_double_spent(): void
    {
        $body = $this->fnBody('_consumeCompOffLedger');
        $this->assertStringContainsString("'precondition' => ['updateTime' => \$updateTime],", $body);
        $this->assertStringContainsString('if (!$ok) continue;', $body);
    }

    public function test_shortfall_beyond_available_credit_becomes_lwp_like_every_other_type(): void
    {
        $body = $this->fnBody('_consumeCompOffLedger');
        // D-001 — the shortfall is now rounded to the paise-equivalent day
        // precision, because partial consumption can leave a fractional
        // remainder that float subtraction would otherwise fray.
        $this->assertStringContainsString('$lwp  = max(0.0, round($daysNeeded - $paid, 2));', $body);
    }

    // ── Release on rejection/cancellation/write-failure rollback ─────────

    public function test_write_failure_rollback_releases_comp_off_credits_not_the_standard_balance(): void
    {
        $body = $this->fnBody('decide_leave');
        $start = strpos($body, "if (!\$writeOk) {");
        $this->assertNotFalse($start);
        $rollbackChunk = substr($body, $start, 400);
        $this->assertStringContainsString('if ($decision === \'Approved\' && $isCompOff) {', $rollbackChunk);
        $this->assertStringContainsString('$this->_releaseCompOffLedger($compOffConsumedIds, $compOffTakes);', $rollbackChunk);
    }

    public function test_cancel_leave_releases_the_exact_ledger_ids_recorded_at_approval(): void
    {
        $body = $this->fnBody('cancel_leave');
        $this->assertStringContainsString("\$isCompOffCancel = strtoupper(trim((string) (\$request['type_code'] ?? ''))) === self::COMP_OFF_TYPE_CODE;", $body);
        $this->assertStringContainsString("\$ledgerIds = is_array(\$request['compOffLedgerIds'] ?? null) ? \$request['compOffLedgerIds'] : [];", $body);
        $this->assertStringContainsString('$this->_releaseCompOffLedger($ledgerIds, $ledgerTakes);', $body);
    }

    public function test_consumed_ledger_ids_are_persisted_onto_the_leave_request_doc(): void
    {
        $body = $this->fnBody('decide_leave');
        $this->assertStringContainsString("'compOffLedgerIds'   => \$compOffConsumedIds,", $body);

        // D-001 — the per-credit amounts are persisted alongside the ids, so a
        // later cancellation restores exactly what was taken from a partially
        // spent credit rather than the whole thing.
        $this->assertStringContainsString("'compOffLedgerTakes' => \$compOffTakes,", $body);
    }

    // ── Expiry: TENANT-CONFIGURED, disabled by default ──────────────────
    //
    // Updated 2026-08-21. Expiry was previously unimplemented, and these tests
    // asserted that absence so nobody invented a forfeiture policy. It is now
    // implemented as per-tenant configuration that DEFAULTS TO DISABLED, which
    // honours the original intent — no forfeiture is invented, the school
    // decides — while making the market-standard behaviour available.

    /**
     * Still no background sweep that marks credits 'expired'. Lapsing is a
     * READ-side filter, so a credit is never mutated by the passage of time
     * and a policy change cannot rewrite ledger history.
     */
    public function test_no_automatic_expiry_sweep_exists(): void
    {
        $this->assertStringNotContainsString('function _expire_comp_off', $this->controllerSrc);
        $this->assertStringNotContainsString("'status' => 'expired'", $this->controllerSrc);
    }

    public function test_expires_on_is_stamped_from_tenant_policy(): void
    {
        $body = $this->fnBody('earn_comp_off');

        $this->assertStringContainsString("'expiresOn'   => \$expiresOn,", $body,
            'the stamp now comes from the tenant policy rather than being hard-null');
        $this->assertStringContainsString("if (\$compOffPolicy['expiryDays'] > 0) {", $body,
            'and is only computed when a window is actually configured');
    }

    /** With nothing configured the stamp stays null — byte-identical to before. */
    public function test_expiry_defaults_to_disabled(): void
    {
        $body = $this->fnBody('earn_comp_off');

        $this->assertStringContainsString('$expiresOn = null;', $body,
            'an unconfigured tenant must still write a null stamp, so credits never lapse');
    }
}
