<?php

use PHPUnit\Framework\TestCase;

if (!defined('BASEPATH')) define('BASEPATH', __DIR__);
if (!defined('APPPATH'))  define('APPPATH', __DIR__ . '/../../application/');
if (!function_exists('log_message')) { function log_message($l, $m) {} }

require_once __DIR__ . '/../../application/libraries/Fee_lifecycle.php';

/**
 * P5-0 — THE CANONICAL SPEC BUILDER MUST FAIL CLOSED.
 *
 * ── WHY THIS IS THE MOST DANGEROUS OF THE FAIL-OPEN READS ─────────────────
 * `buildAdmissionDemandSpecs()` pre-reads the student's existing demands. That
 * ONE set carries TWO safety properties at once:
 *
 *   1. duplicate suppression — do not re-create a charge that already exists;
 *   2. the BUG-075 paid-demand guard — do not re-emit a spec for a demand that
 *      already carries a payment.
 *
 * Specs are written with `merge = true`, so a blind rebuild re-emits specs for
 * PAID demands and resets `paidAmount = 0`, `balance = netAmount`,
 * `status = 'unpaid'` — un-paying settled money while the receipt and journal
 * survive and now disagree with the demand.
 *
 * Strictly worse than B-011, which could only over-bill an UNPAID demand.
 *
 * ── A FIX THAT LOOKED RIGHT AND WASN'T ────────────────────────────────────
 * The first attempt wrapped the `_demandsFor()` CALL SITE in a try/catch. That
 * is inert: `_demandsFor()` swallows the error one level down and returns `[]`,
 * so the outer catch never fires and the rebuild proceeds exactly as before.
 *
 * A source-shaped test ("a try/catch exists", "the guard is present") would
 * have reported that as fixed. These tests drive the real dependency and
 * failed, which is the only reason it was caught. That is the whole argument
 * for behavioural tests in one example.
 *
 * The real fix propagates the failure from the READ (`_demandsForStrict()`).
 */
class P5SpecBuilderFailClosedTest extends TestCase
{
    /** Build a real Fee_lifecycle with a scripted transaction double. */
    private function lifecycle($fsTxn): Fee_lifecycle
    {
        $l = (new ReflectionClass('Fee_lifecycle'))->newInstanceWithoutConstructor();
        (function () use ($fsTxn) {
            $this->fsTxn      = $fsTxn;
            $this->schoolName = 'SCH_TESTTENANT1';
            $this->session    = '2026-27';
        })->call($l);
        return $l;
    }

    /** A transaction whose STRICT read fails the way Firestore fails. */
    private function failingTxn(string $kind = 'exception')
    {
        return new class($kind) {
            private string $kind;
            public bool $chartWasRead = false;
            public function __construct(string $k) { $this->kind = $k; }
            public function demandsForStudentStrict(string $sid): array
            {
                if ($this->kind === 'error') throw new \Error('index CREATING');
                throw new \RuntimeException('DEADLINE_EXCEEDED');
            }
            public function demandsForStudent(string $sid): array { return []; }
            /** Reached only if the guard failed to stop execution. */
            public function canonicalHeadIdMap(): array { return []; }
            /** Reached only if the guard let execution continue. */
            public function readFeeStructureWithIds(string $c, string $s): array
            {
                $this->chartWasRead = true;
                return ['chart' => [], 'headIds' => [], 'headRefs' => []];
            }
        };
    }

    private function build(Fee_lifecycle $l): array
    {
        $m = new ReflectionMethod('Fee_lifecycle', 'buildAdmissionDemandSpecs');
        $m->setAccessible(true);
        return (array) $m->invoke($l, 'STU9001', 'Class 9th', 'Section A', false);
    }

    // ══ THE HEADLINE ═══════════════════════════════════════════════════════

    /** A failed read must produce NO specs — never a blind rebuild. */
    public function test_a_failed_existing_demand_read_produces_no_specs(): void
    {
        $out = $this->build($this->lifecycle($this->failingTxn()));

        $this->assertSame([], $out['specs'] ?? null,
            'zero specs is safe; a blind rebuild can un-pay settled money');
        $this->assertFalse($out['chartFound'] ?? true,
            'chartFound=false is the contract-preserving refusal callers already handle');
    }

    /** The refusal must be identifiable, not indistinguishable from "no chart". */
    public function test_the_refusal_is_labelled(): void
    {
        $out = $this->build($this->lifecycle($this->failingTxn()));

        $this->assertSame('existing_demand_read_failed', $out['refused'] ?? null,
            'a refusal that looks like "no fee chart" cannot be triaged');
    }

    /**
     * THE POINT — and the assertion that caught the ineffective first fix.
     * Nothing downstream may run; downstream is where paid demands get rewritten.
     */
    public function test_nothing_downstream_of_the_guard_executes(): void
    {
        $txn = $this->failingTxn();
        $this->build($this->lifecycle($txn));

        $this->assertFalse($txn->chartWasRead,
            'execution must stop AT the guard — the first attempted fix failed exactly here');
    }

    /** An Error, not just an Exception, must also refuse. */
    public function test_a_php_error_also_refuses(): void
    {
        $txn = $this->failingTxn('error');
        $out = $this->build($this->lifecycle($txn));

        $this->assertFalse($out['chartFound'] ?? true);
        $this->assertFalse($txn->chartWasRead);
    }

    /** The refusal keeps the full contract so callers need no isset() dance. */
    public function test_the_refusal_shape_matches_the_normal_contract(): void
    {
        $out = $this->build($this->lifecycle($this->failingTxn()));

        foreach (['specs', 'assigned', 'chartFound'] as $k) {
            $this->assertArrayHasKey($k, $out, "callers destructure '{$k}' unconditionally");
        }
        $this->assertSame([], $out['assigned']);
    }

    // ══ THE GUARD MUST NOT BE OVER-BROAD ═══════════════════════════════════

    /**
     * A student who genuinely has NO demands must proceed normally. Conflating
     * "empty" with "failed" would refuse every first-time admission.
     */
    public function test_an_empty_demand_set_is_not_treated_as_a_failure(): void
    {
        $txn = new class {
            public bool $chartWasRead = false;
            public function demandsForStudentStrict(string $sid): array { return []; }
            public function demandsForStudent(string $sid): array { return []; }
            public function canonicalHeadIdMap(): array { return []; }
            /** Reached only if the guard let execution continue. */
            public function readFeeStructureWithIds(string $c, string $s): array
            {
                $this->chartWasRead = true;
                return ['chart' => [], 'headIds' => [], 'headRefs' => []];
            }
        };

        $out = $this->build($this->lifecycle($txn));

        $this->assertArrayNotHasKey('refused', $out,
            'a brand-new student must not be refused — that would break every admission');
        $this->assertTrue($txn->chartWasRead,
            'the builder must proceed when the read genuinely succeeded');
    }

    // ══ THE STRICT READER IS ACTUALLY USED ═════════════════════════════════

    /**
     * Regression pin for the ineffective first fix: the builder must call the
     * STRICT reader. Calling the lenient `_demandsFor()` reinstates the swallow
     * one level down and the guard silently stops working.
     */
    public function test_the_builder_uses_the_strict_reader(): void
    {
        $txn = new class {
            public bool $strictCalled = false;
            public bool $lenientCalled = false;
            public function demandsForStudentStrict(string $sid): array
            {
                $this->strictCalled = true;
                return [];
            }
            public function demandsForStudent(string $sid): array
            {
                $this->lenientCalled = true;
                return [];
            }
            public function canonicalHeadIdMap(): array { return []; }
            public function readFeeStructureWithIds(string $c, string $s): array { return ['chart'=>[],'headIds'=>[],'headRefs'=>[]]; }
        };

        $this->build($this->lifecycle($txn));

        $this->assertTrue($txn->strictCalled,
            'the lenient reader swallows the failure and re-opens the hole');
        $this->assertFalse($txn->lenientCalled);
    }
}
