<?php
namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Production hardening programme (2026-08-19) — PAYROLL AUDIT TRAIL.
 *
 * The consolidated reconciliation found that Hr.php called log_audit()
 * ZERO times while owning every money-mutating payroll operation. What it
 * did have — `_log_payroll()` → `systemLogs` — is diagnostic telemetry:
 * free-form, not surfaced in any audit view, and not the collection the
 * rest of the ERP audits into. For a payroll system that is a material
 * compliance gap: no permanent record of who finalized, approved, paid,
 * unlocked or deleted a payroll run.
 *
 * NO NEW AUDIT SYSTEM WAS INTRODUCED. Every entry routes through the one
 * canonical mechanism — helpers/audit_helper.php's log_audit(), writing
 * the canonical `auditLogs` collection, already loaded for every
 * controller by MY_Controller::__construct(:295) and already used
 * throughout Staff.php. `_audit_payroll()` is a call-shape wrapper only.
 *
 * Design constraints held:
 *   • additive — no _log_payroll() call was removed or altered
 *   • non-blocking — a failed audit write can never fail a payroll write
 *   • run-level aggregates only; no per-employee salary breakdown, no bank
 *     or PII values. The one deliberate exception is the slip-regeneration
 *     before/after net, because that IS the audited change.
 *   • no RBAC change, no financial formula touched, no schema migration
 *
 * Source-regression tests (Firestore_service has no behavioral mock).
 */
class PayrollAuditTrailTest extends TestCase
{
    private string $src;

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
        $this->src = $out;
    }

    private function extractFunctionBody(string $src, int $offset): string
    {
        $openBrace = strpos($src, '{', $offset);
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
        $this->fail('Unbalanced braces.');
    }

    private function fn(string $name): string
    {
        $start = strpos($this->src, 'function ' . $name . '(');
        $this->assertNotFalse($start, "{$name}() must exist.");
        return $this->extractFunctionBody($this->src, $start);
    }

    // ── Every money-mutating endpoint is audited ─────────────────────

    /** @dataProvider moneyMutatingEndpoints */
    public function test_money_mutating_endpoint_emits_an_audit_entry(string $endpoint): void
    {
        $body = $this->fn($endpoint);
        $this->assertStringContainsString('$this->_audit_payroll(', $body,
            "{$endpoint}() mutates money or period state and must emit a canonical audit entry.");
    }

    public static function moneyMutatingEndpoints(): array
    {
        return [
            'generate_payroll'         => ['generate_payroll'],
            'confirm_lop'              => ['confirm_lop'],
            'finalize_payroll'         => ['finalize_payroll'],
            'approve_payroll'          => ['approve_payroll'],
            'mark_payroll_paid'        => ['mark_payroll_paid'],
            'delete_payroll_run'       => ['delete_payroll_run'],
            'unlock_payroll'           => ['unlock_payroll'],
            'regenerate_staff_payroll' => ['regenerate_staff_payroll'],
            'lock_payroll_month'       => ['lock_payroll_month'],
        ];
    }

    // ── Canonical mechanism, not a parallel one ──────────────────────

    public function test_audit_routes_through_the_canonical_log_audit_helper(): void
    {
        $body = $this->fn('_audit_payroll');
        $this->assertStringContainsString("log_audit('HR',", $body,
            'must call the canonical helper — no parallel audit store.');
        $this->assertStringContainsString("'entityType' => 'payrollRun'", $body);
    }

    public function test_no_parallel_audit_collection_was_introduced(): void
    {
        $body = $this->fn('_audit_payroll');
        // It must not write Firestore directly under any name.
        foreach (['->set(', '->update(', 'setEntity', 'firestoreSet', 'commitBatch'] as $w) {
            $this->assertStringNotContainsString($w, $body,
                "the wrapper must delegate to log_audit(), never write storage itself (found {$w}).");
        }
        $this->assertStringNotContainsString("'auditLogs'", $body,
            'the collection name belongs to the helper, not to this caller.');
    }

    public function test_audit_never_blocks_a_payroll_write(): void
    {
        $body = $this->fn('_audit_payroll');
        $this->assertStringContainsString("if (!function_exists('log_audit')) return;", $body);
        $this->assertStringContainsString('} catch (\Throwable $e) {', $body);
        $this->assertStringNotContainsString('json_error', $body,
            'a failed audit write must never abort the payroll operation it describes.');
    }

    // ── Additive: diagnostic logging preserved ───────────────────────

    /** @dataProvider diagnosticPreserved */
    public function test_existing_diagnostic_logging_was_not_replaced(string $endpoint, string $marker): void
    {
        $body = $this->fn($endpoint);
        $this->assertStringContainsString($marker, $body,
            "the pre-existing _log_payroll() diagnostic in {$endpoint}() must be preserved, not swapped for the audit entry.");
    }

    public static function diagnosticPreserved(): array
    {
        return [
            ['generate_payroll',   "_log_payroll('generated'"],
            ['finalize_payroll',   "_log_payroll('finalized'"],
            ['approve_payroll',    "_log_payroll('approved'"],
            ['delete_payroll_run', "_log_payroll('deleted'"],
            ['unlock_payroll',     "_log_payroll('unlocked'"],
        ];
    }

    // ── State transitions are recorded ───────────────────────────────

    public function test_status_transitions_record_both_from_and_to_state(): void
    {
        // fromStatus comes from the run doc via _audit_run_meta(); toStatus
        // is stated explicitly at each call site.
        $meta = $this->fn('_audit_run_meta');
        $this->assertStringContainsString("\$m['fromStatus']", $meta);

        foreach (['finalize_payroll', 'approve_payroll', 'unlock_payroll', 'delete_payroll_run'] as $fn) {
            $this->assertStringContainsString("'toStatus'", $this->fn($fn),
                "{$fn}() must record the resulting state.");
        }
    }

    public function test_period_is_recorded_so_an_entry_identifies_what_it_affected(): void
    {
        $meta = $this->fn('_audit_run_meta');
        $this->assertStringContainsString("\$m['month']", $meta);
        $this->assertStringContainsString("\$m['year']", $meta);
    }

    public function test_unlock_records_the_mandatory_reason(): void
    {
        // Unlocking reopens a frozen financial period — the reason is
        // already mandatory at this endpoint, so it must reach the audit.
        $body = $this->fn('unlock_payroll');
        $this->assertStringContainsString("'reason'     => \$reason,", $body);
    }

    public function test_lop_confirmation_is_audited_as_the_deduction_authorisation(): void
    {
        // finalize_payroll() refuses without this sign-off, which makes it
        // the on-the-record authorisation to deduct pay.
        $body = $this->fn('confirm_lop');
        $this->assertStringContainsString("'payroll_lop_confirmed'", $body);
        $this->assertStringContainsString("'lwpDeduction'", $body);
    }

    // ── Sensitivity discipline ───────────────────────────────────────

    public function test_audit_meta_carries_no_bank_or_identity_fields(): void
    {
        // Scan every _audit_payroll( ... ) call for PII/bank field names.
        $forbidden = ['accountNumber', 'ifsc', 'bankDetails', 'panNumber',
                      'aadhar', 'aadhaar', 'pfNumber', 'esiNumber', 'salaryDetails'];
        $offset = 0;
        $calls  = 0;
        while (($pos = strpos($this->src, '$this->_audit_payroll(', $offset)) !== false) {
            $calls++;
            $chunk = substr($this->src, $pos, 900);
            foreach ($forbidden as $f) {
                $this->assertStringNotContainsStringIgnoringCase($f, $chunk,
                    "audit meta must not carry '{$f}' — the audit trail is not a place for bank or identity values.");
            }
            $offset = $pos + 1;
        }
        $this->assertGreaterThanOrEqual(9, $calls, 'expected at least 9 audited payroll operations.');
    }

    public function test_run_level_meta_does_not_expand_into_per_employee_breakdown(): void
    {
        $meta = $this->fn('_audit_run_meta');
        // Aggregates only — no component-level salary fields.
        foreach (['basic', 'hra', 'allowance', 'deduction_breakdown'] as $f) {
            $this->assertStringNotContainsStringIgnoringCase($f, $meta);
        }
    }

    // ── Nothing financial or security-related was altered ────────────

    public function test_no_rbac_gate_was_changed(): void
    {
        foreach ([
            ['generate_payroll',         "'HR', 'manage'"],
            ['finalize_payroll',         "'HR', 'manage'"],
            ['approve_payroll',          "'HR', 'manage'"],
            ['mark_payroll_paid',        "'HR', 'manage'"],
            ['delete_payroll_run',       "'HR', 'manage'"],
            ['unlock_payroll',           "'HR', 'manage'"],
            ['regenerate_staff_payroll', "'HR', 'manage'"],
            ['lock_payroll_month',       "'HR', 'manage'"],
        ] as [$fn, $gate]) {
            $this->assertStringContainsString($gate, $this->fn($fn),
                "{$fn}() must keep its existing RBAC gate.");
        }
    }

    public function test_finalize_lop_gate_still_refuses_unconfirmed_deductions(): void
    {
        // The W3.2.3 safety gate must survive the audit additions.
        $body = $this->fn('finalize_payroll');
        $this->assertStringContainsString('Review and confirm loss of pay before finalizing.', $body);
        $this->assertStringContainsString('$sameDays', $body);
        $this->assertStringContainsString('$sameDed', $body);
    }

    public function test_status_preconditions_are_untouched(): void
    {
        $this->assertStringContainsString('Only Draft payroll runs can be finalized.', $this->fn('finalize_payroll'));
        $this->assertStringContainsString('Only Finalized runs can be approved.', $this->fn('approve_payroll'));
        $this->assertStringContainsString('Run is Finalized. Call /hr/unlock_payroll first.', $this->fn('regenerate_staff_payroll'));
    }
}
