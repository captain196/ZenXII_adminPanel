<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

if (!defined('BASEPATH')) define('BASEPATH', __DIR__);
require_once __DIR__ . '/../../application/libraries/Salary_assignment_service.php';

/**
 * ZenX HRMS W3.2.5 — payroll scale (batched writes) and N-15 payout readiness.
 *
 * The two risks this gate creates:
 *   (a) a batched write storing a DIFFERENT document than a single write, so a
 *       payslip would render differently depending on run size;
 *   (b) preflight calling `readiness()` wholesale, which asserts a W2 salary
 *       assignment exists — and would therefore block every employee in every
 *       tenant while the component model is off.
 *
 * Both are asserted structurally here, because `Hr.php` cannot load in the
 * PHPUnit bootstrap. The reused payout logic itself is tested behaviourally.
 */
class PayrollScaleAndReadinessTest extends TestCase
{
    private function codeOnly(string $relPath): string
    {
        $src = (string) file_get_contents(__DIR__ . '/../../application/' . $relPath);
        $out = '';
        foreach (token_get_all($src) as $tok) {
            if (is_array($tok)) {
                if ($tok[0] === T_COMMENT || $tok[0] === T_DOC_COMMENT) continue;
                $out .= $tok[1];
            } else {
                $out .= $tok;
            }
        }
        return $out;
    }

    // ── N-15: reused payout logic, tested behaviourally ──────────────────

    public function test_bank_details_recognised_in_every_supported_shape(): void
    {
        $shapes = [
            ['bankDetails' => ['accountNumber'  => '12345']],
            ['bankDetails' => ['account_number' => '12345']],
            ['bankDetails' => ['accountNo'      => '12345']],
            ['bankDetails' => ['acNumber'       => '12345']],
            ['bankAccountNumber'   => '12345'],
            ['bank_account_number' => '12345'],
            ['accountNumber'       => '12345'],
            ['account_number'      => '12345'],
        ];
        foreach ($shapes as $i => $s) {
            $this->assertTrue(\Salary_assignment_service::hasBankDetails($s),
                "shape #{$i} must be recognised — this is why the rule is reused, not rewritten");
        }
    }

    public function test_missing_or_blank_bank_details_are_detected(): void
    {
        foreach ([[], ['bankDetails' => []], ['bankDetails' => ['accountNumber' => '   ']],
                  ['bankAccountNumber' => ''], ['unrelated' => 'x']] as $s) {
            $this->assertFalse(\Salary_assignment_service::hasBankDetails($s));
        }
    }

    /**
     * The trap this gate had to avoid. `readiness()` blocks on a missing W2
     * salary ASSIGNMENT — and the component model is off with an empty
     * collection, so using it in preflight would block everyone, everywhere.
     */
    public function test_readiness_would_block_everyone_today_which_is_why_preflight_does_not_use_it(): void
    {
        // Stub Firestore that returns no assignments — exactly the live state:
        // the component model is off and `staffSalaryAssignments` is empty.
        $fs = new class {
            public function schoolWhere($c, $w = []) { return []; }
            public function get($c, $d) { return null; }
            public function getEntity($c, $d) { return null; }
            public function docId($id) { return 'T_' . $id; }
            public function set($c, $d, $x, $m = false) { return true; }
        };

        $svc = new \Salary_assignment_service();
        $svc->init($fs);
        $r = $svc->readiness([['staffId' => 'STA0001', 'name' => 'Rahul Sharma']], '2026-07-31');

        $this->assertCount(0, $r['ready'], 'no assignments exist, so nobody is "ready"');
        $this->assertCount(1, $r['blocked']);
        $this->assertStringContainsString('salary assignment', strtolower($r['blocked'][0]['reason']));
    }

    public function test_preflight_reuses_the_payout_rule_and_not_the_assignment_gate(): void
    {
        $hr = $this->codeOnly('controllers/Hr.php');
        $start = strpos($hr, 'public function preflight_payroll');
        $this->assertNotFalse($start);
        $body = substr($hr, $start, 9000);

        // The shared predicate, not a re-implementation and not the
        // compensation service (naming that class here would trip the W2
        // cutover guards — the predicate was moved to a helper instead).
        $this->assertStringContainsString('payroll_has_bank_details(', $body,
            'the shared rule must be reused, not re-implemented');
        $this->assertStringNotContainsString('Salary_assignment_service', $body,
            'preflight must not name the compensation service');
        $this->assertStringNotContainsString('->readiness(', $body,
            'preflight must NOT call readiness() — it would block every employee '
            . 'while the component model is off');
    }

    public function test_bank_is_a_blocker_and_pan_is_a_warning(): void
    {
        $hr = $this->codeOnly('controllers/Hr.php');
        $start = strpos($hr, 'public function preflight_payroll');
        $body  = substr($hr, $start, 9000);

        $this->assertMatchesRegularExpression(
            "/Bank details missing.*?'severity'\s*=>\s*'blocker'|'severity'\s*=>\s*'blocker'.*?Bank details missing/s",
            $body, 'missing bank details must block — the employee cannot be paid');
        $this->assertMatchesRegularExpression(
            "/'severity'\s*=>\s*'warning'.*?PAN|PAN.*?'severity'\s*=>\s*'warning'/s",
            $body, 'PAN must warn, not block, until TDS is live');
    }

    public function test_preflight_reads_the_pii_mirror_for_payout_details(): void
    {
        // Bank/PAN live on the server-only PII mirror, not the main staff doc
        // (W2.2 discovery: 0 of each on main docs). Reading only the main doc
        // would report every employee as missing bank details.
        $hr = $this->codeOnly('controllers/Hr.php');
        $start = strpos($hr, 'public function preflight_payroll');
        $body  = substr($hr, $start, 9000);
        $this->assertStringContainsString('_apply_staff_private_map', $body);
    }

    // ── Batched slip writes ──────────────────────────────────────────────

    /**
     * The single most important assertion in this gate: every write path must
     * build the document with the SAME builder. Two builders would eventually
     * disagree on a field, and a payslip would then differ depending on which
     * path wrote it.
     *
     * COUNT RAISED 2 -> 3 (2026-08-19). This test previously pinned two callers,
     * the single-write and batch-write paths. Real UAT found a THIRD writer that
     * had never been counted: regenerate_staff_payroll() assembled its own
     * `$newSlip` and wrote it raw, so the canonical camelCase mirror the Teacher
     * app reads was never rewritten and survived from the original run. That is
     * precisely the divergence this test exists to prevent — it was simply
     * blind to that path. Routing regeneration through the builder makes the
     * count 3. The expectation is being CORRECTED, not relaxed: the invariant
     * ("one builder, used by every writer") is now enforced over strictly more
     * of the controller than before. See RegeneratedPayslipCanonicalMirrorTest.
     */
    public function test_all_write_paths_share_one_document_builder(): void
    {
        $hr = $this->codeOnly('controllers/Hr.php');

        $this->assertSame(1, substr_count($hr, 'private function _buildPayslipDoc'),
            'exactly one payslip document builder may exist');
        $this->assertSame(3, substr_count($hr, '$this->_buildPayslipDoc('),
            'single-write, batch-write and regeneration must all use it');
    }

    public function test_generation_writes_slips_in_batches_not_one_by_one(): void
    {
        $hr = $this->codeOnly('controllers/Hr.php');
        $start = strpos($hr, 'public function generate_payroll');
        $this->assertNotFalse($start);
        $body = substr($hr, $start, 45000);

        $this->assertStringContainsString('_fsSyncPayslipsBatch(', $body,
            'generation must use the batched write');
        $this->assertStringNotContainsString('foreach ($slips as $sid => $slip) {$this->_fsSyncPayslip(', $body,
            'the per-employee write loop must be gone');
    }

    public function test_batch_write_preserves_merge_semantics(): void
    {
        $hr = $this->codeOnly('controllers/Hr.php');
        $start = strpos($hr, 'private function _fsSyncPayslipsBatch');
        $body  = substr($hr, $start, 2000);

        $this->assertStringContainsString("batchWrite('salarySlips'", $body);
        $this->assertMatchesRegularExpression('/batchWrite\(.*?,\s*\$docs,\s*true\s*\)/s', $body,
            'merge must stay true — a non-merge batch would drop fields the '
            . 'single-write path preserved');
    }

    /**
     * A failed slip is an unpaid employee. The batch must surface which ones
     * failed, by name, not a count — and never swallow them.
     */
    public function test_failed_slips_are_reported_individually(): void
    {
        $hr = $this->codeOnly('controllers/Hr.php');
        $start = strpos($hr, 'private function _fsSyncPayslipsBatch');
        $body  = substr($hr, $start, 2000);

        $this->assertStringContainsString("'failed'", $body);
        $this->assertStringContainsString('PAYROLL_SLIP_BATCH', $body, 'batch outcome must be logged');

        $gen = substr($hr, strpos($hr, 'public function generate_payroll'), 45000);
        $this->assertStringContainsString("\$batchResult['failed']", $gen);
        $this->assertStringContainsString('Payslip write failed for', $gen,
            'each failed slip must produce its own named warning');
    }

    public function test_batch_chunking_stays_under_the_firestore_limit(): void
    {
        $fs = (string) file_get_contents(
            __DIR__ . '/../../application/libraries/Firestore_service.php');
        $this->assertMatchesRegularExpression('/\$maxChunk\s*=\s*(4[0-9]{2}|500)\s*;/', $fs,
            'chunk size must stay at or under Firestore\'s 500-per-request limit');
        $this->assertStringContainsString('500/req hard limit', $fs);
    }

    /** Batching must not have touched the certified money path. */
    public function test_batching_did_not_alter_the_money_contract(): void
    {
        $hr = $this->codeOnly('controllers/Hr.php');
        $start = strpos($hr, 'private function _fsSyncPayslipsBatch');
        $body  = substr($hr, $start, 2000);

        foreach (['_toPaise', '_toRupees', '_applyRatio', 'round(', 'net_pay ='] as $money) {
            $this->assertStringNotContainsString($money, $body,
                'the batch writer must move documents, never recompute money');
        }
    }

    /**
     * UAT DEFECT GUARD — found live, on the first real click.
     *
     * `MY_Controller::json_success()` does `array_merge(['status' => 'success'],
     * $data)`. A payload key named `status` therefore OVERWRITES the envelope,
     * so the response carries e.g. `"status":"Paid"` and every caller's
     * `r.status === 'success'` check fails.
     *
     * Three W3 endpoints shipped with exactly that collision — `payslip_trace`,
     * `lop_review` and `list_for_run`. All three returned correct data that no
     * client could read. Unit tests never caught it because they assert on the
     * builder functions, not on the response envelope.
     *
     * This guard fails if any W3 endpoint reintroduces the key.
     */
    public function test_no_endpoint_payload_shadows_the_json_success_envelope(): void
    {
        foreach (['controllers/Hr.php', 'controllers/Payroll_overrides.php'] as $f) {
            $src = $this->codeOnly($f);

            // A payload key literally named 'status' assigned from a slip/run
            // document is the exact shape of the defect.
            $this->assertDoesNotMatchRegularExpression(
                "/'status'\s*=>\s*\(string\)\s*\(\\\$(slip|run)\[/",
                $src,
                "{$f}: a payload key 'status' overwrites json_success()'s envelope — "
                . "use 'slip_status' / 'run_status' instead"
            );
        }

        // And the replacements must actually be present.
        $hr = $this->codeOnly('controllers/Hr.php');
        $this->assertStringContainsString("'slip_status'", $hr);
        $this->assertStringContainsString("'run_status'", $hr);
    }

    public function test_scale_harness_writes_nothing(): void
    {
        $src = $this->codeOnly('controllers/Hrms_w325_scale.php');

        // Asserted on the ability to reach Firestore at all, not on the names
        // of write methods: the harness legitimately PRINTS the string
        // "batchWrite" while explaining the measurement. Banning the word would
        // ban describing the thing being measured.
        foreach (['$this->fs', '->set(', '->delete(', 'commitBatch', 'firebase'] as $write) {
            $this->assertStringNotContainsString($write, $src,
                'the scale harness must have no way to write a document');
        }
    }
}
