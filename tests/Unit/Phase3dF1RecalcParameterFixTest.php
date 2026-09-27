<?php
namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * ══════════════════════════════════════════════════════════════════════════
 * P3D-F1 — the recalc engine must honour its own parameters
 * ══════════════════════════════════════════════════════════════════════════
 *
 * THE DEFECT (authorised for a narrow fix):
 *
 *   private function _recalculateStudentUnpaidDemands(
 *       string $studentId, array $feeChart, array $discountMap): array
 *   {
 *       $this->_require_post();
 *       $this->_require_role(…);
 *       $studentId   = trim($this->input->post('student_id') ?? '');   // :6899
 *       if ($studentId === '') return $this->json_error(…);            // :6901
 *       …
 *       $feeChart    = $this->_getClassFeeChart($class, $section);     // :6919
 *       $discountMap = $this->_getStudentDiscounts(…);                 // :6924
 *
 * A PRIVATE helper that takes three parameters threw away **all three** and
 * re-derived them from the current HTTP request.
 *
 * `save_updated_fees()` — the CLASS fee-chart save — loops the roster calling
 * this per student and posts no `student_id` at all. So the helper read '',
 * called json_error(), and json_error() ends in `exit` (MY_Controller:1546):
 * the request died on the FIRST student and every later student in the class
 * was silently never recalculated. The caller's `try` could not help, because
 * `exit` is not an exception.
 *
 * THE FIX: use the parameters. The caller already resolved them, already did
 * `_require_post()` / `_require_role()`, and is the only thing that knows which
 * student this iteration is about. A private helper must not re-read the
 * request, must not emit a response, and must not terminate the process.
 *
 * ── NO I/O ──────────────────────────────────────────────────────────────────
 * Source is read with comments STRIPPED, so no comment can satisfy an
 * assertion. No Firestore, no tenant, no writes.
 */
class Phase3dF1RecalcParameterFixTest extends TestCase
{
    private const FEES = __DIR__ . '/../../application/controllers/Fees.php';

    private function src(string $path): string
    {
        $out = '';
        foreach (token_get_all(file_get_contents($path)) as $t) {
            if (is_array($t)) {
                if (in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true)) continue;
                $out .= $t[1];
            } else { $out .= $t; }
        }
        return $out;
    }

    private function body(string $name): string
    {
        $s = $this->src(self::FEES);
        $start = strpos($s, "function {$name}(");
        $this->assertNotFalse($start, "{$name}() not found.");
        $end = strpos($s, 'function ', $start + strlen($name) + 12);
        return substr($s, $start, ($end === false ? strlen($s) : $end) - $start);
    }

    private function engine(): string
    {
        return preg_replace('/\s+/', '', $this->body('_recalculateStudentUnpaidDemands'));
    }

    // ── the three parameters must be honoured ────────────────────────────

    /** P3D-F1-01 — `$studentId` is no longer overwritten from the request. */
    public function test_F1_01_studentId_parameter_is_honoured(): void
    {
        $this->assertStringNotContainsString("\$studentId=trim(\$this->input->post('student_id')??'')", $this->engine(),
            'The helper must use the student its caller passed, not whatever happens to be in the '
            . 'POST of the current request. save_updated_fees() iterates a roster — the POST cannot '
            . 'possibly identify which student the current iteration is about.');
    }

    /** P3D-F1-02 — `$feeChart` is no longer re-derived. */
    public function test_F1_02_feeChart_parameter_is_honoured(): void
    {
        $this->assertStringNotContainsString('$feeChart=$this->_getClassFeeChart(', $this->engine(),
            'save_updated_fees() passes the FRESHLY SAVED chart. Re-reading it here would price '
            . 'against the stored copy and silently ignore the save that triggered this call.');
    }

    /** P3D-F1-03 — `$discountMap` is no longer re-derived. */
    public function test_F1_03_discountMap_parameter_is_honoured(): void
    {
        $this->assertStringNotContainsString('$discountMap=$this->_getStudentDiscounts(', $this->engine(),
            'The caller already built the discount map for THIS student; rebuilding it here both '
            . 'duplicates Firestore reads and re-introduces the wrong-student bug.');
    }

    // ── it must never terminate the request ──────────────────────────────

    /**
     * P3D-F1-04 — a private helper must not emit an HTTP response. `json_error()`
     * ends in `exit`, which is why this defect was fatal rather than merely wrong.
     */
    public function test_F1_04_helper_never_emits_a_response_or_exits(): void
    {
        $e = $this->engine();
        $this->assertStringNotContainsString('json_error', $e,
            'json_error() ends in exit (MY_Controller:1546). A helper called inside a per-student '
            . 'loop must return a value, not kill the request.');
        $this->assertStringNotContainsString('json_success', $e);
        $this->assertStringNotContainsString('exit', $e);
    }

    /** P3D-F1-05 — and it must not re-do the caller's authorisation. */
    public function test_F1_05_helper_does_not_re_read_the_request(): void
    {
        $e = $this->engine();
        $this->assertStringNotContainsString('_require_post(', $e,
            'Both public callers already gate on POST + role before they reach the loop.');
        $this->assertStringNotContainsString('_require_role(', $e);
        $this->assertStringNotContainsString('input->post(', $e,
            'A helper that reads the request is not reusable inside a loop — that is the whole defect.');
    }

    // ── fail-soft, per save_updated_fees()'s documented intent ───────────

    /**
     * P3D-F1-06 — an unusable input returns the neutral result shape rather than
     * aborting. `save_updated_fees()` documents this propagation step as
     * best-effort: "a propagation hiccup must never fail the request."
     */
    public function test_F1_06_unusable_input_returns_neutral_shape(): void
    {
        $e = $this->engine();
        $this->assertMatchesRegularExpression(
            "/if\(\\\$studentId===''\|\|\\\$feeChart===\[\]\)\{return\['updated'=>0,'preserved'=>0,'attempted'=>0\];\}/",
            $e,
            'A blank student or an empty chart must yield the neutral shape the callers already '
            . 'handle, keeping save_updated_fees() fail-soft as its comment promises.');
    }

    /** P3D-F1-07 — the return contract is unchanged. */
    public function test_F1_07_return_shape_unchanged(): void
    {
        $this->assertStringContainsString("return['updated'=>\$updated,'preserved'=>\$preserved,'attempted'=>count(\$pendingUpdates)];",
            $this->engine(),
            'Callers read updated/preserved/attempted — the contract must not move.');
    }

    // ── the callers ──────────────────────────────────────────────────────

    /**
     * P3D-F1-08 — the victim can now complete. `save_updated_fees()` still calls
     * the helper per student, and nothing in that path can terminate the request.
     */
    public function test_F1_08_save_updated_fees_can_process_the_whole_roster(): void
    {
        $m = preg_replace('/\s+/', '', $this->body('save_updated_fees'));

        $this->assertStringContainsString('foreach($rosteras$sid=>', $m,
            'The per-student loop is intact …');
        // UPDATED BY P5-K (2026-08-20). The propagation loop no longer passes a
        // chart and a legacy discount map to the legacy engine: it calls the
        // canonical recalculation, which resolves both itself through the ONE
        // pricing authority. The loop structure this test guards is unchanged.
        $this->assertStringContainsString('_recalculateStudentCanonical($sid,$class,$section)', $m,
            '… and passes the student and their class/section to the canonical authority.');
        $this->assertStringNotContainsString('student_id', $m,
            'It still posts no student_id, which is exactly why the helper must not look for one.');
    }

    /**
     * P3D-F1-09 — `recalculate_demands()` is unaffected: it already resolved and
     * passed the identical three values, so removing the helper's re-derivation
     * is a no-op for it. This is the flag-OFF equivalence argument for that
     * endpoint, stated as a test.
     */
    public function test_F1_09_recalculate_demands_is_behaviourally_unchanged(): void
    {
        $m = preg_replace('/\s+/', '', $this->body('recalculate_demands'));

        // UPDATED BY P5-H (2026-08-20). The last three needles described the
        // LEGACY recalculation this endpoint used to perform: its own chart
        // read, its own legacy discount map, and the legacy helper. It now
        // shares the canonical recalculation authority, so those three are gone
        // by design. The request-handling contract this test exists to pin —
        // POST-only, sanitised student id, profile resolution — is unchanged
        // and still asserted.
        foreach ([
            '_require_post()',
            "input->post('student_id')",
            'safe_path_segment($studentId,',
            '$this->fsTxn->getStudent($studentId)',
            '_recalculateStudentCanonical($studentId,$class,$section)',
        ] as $needle) {
            $this->assertStringContainsString($needle, $m,
                "recalculate_demands() still does its own {$needle} — it passes exactly what the "
                . 'helper used to recompute, so the values reaching the loop are identical.');
        }
    }

    /** P3D-F1-10 — `recalc_unpaid_discounts()` never used this helper and is untouched. */
    /**
     * UPDATED BY P5-E (2026-08-20). The P3D-F1 fix concerned
     * `_recalculateStudentUnpaidDemands()`; this test recorded that
     * `recalc_unpaid_discounts` was NOT a caller and kept its own legacy
     * discount lookup, so the fix could not reach it.
     *
     * The first half still holds and still matters. The second no longer does:
     * that endpoint now recomputes in place through the CANONICAL pipeline and
     * has no legacy discount lookup at all — a stronger separation than the one
     * originally asserted.
     */
    public function test_F1_10_recalc_unpaid_discounts_is_independent_of_this_fix(): void
    {
        $m = preg_replace('/\s+/', '', $this->body('recalc_unpaid_discounts'));

        $this->assertStringNotContainsString('_recalculateStudentUnpaidDemands', $m,
            'It has its own path and was never a caller — nothing about this fix can reach it.');
        $this->assertStringNotContainsString('_getStudentDiscounts(', $m,
            'P5-E: it no longer consults the legacy third authority at all.');
        $this->assertStringContainsString('_recalculateStudentCanonical', $m,
            'it recomputes in place through the canonical pipeline instead.');
    }

    // ── nothing else moved ───────────────────────────────────────────────

    /**
     * P3D-F1-11 — the pricing arithmetic inside the loop is untouched. This fix
     * was about WHICH student and WHICH chart, never about the money.
     */
    public function test_F1_11_pricing_arithmetic_unchanged(): void
    {
        $e = $this->engine();
        foreach ([
            "if((\$demand['status']??'')!=='unpaid'){\$preserved++;continue;}",
            "if(!empty(\$demand['concessionApplied'])){\$preserved++;continue;}",
            'if($newAmount<=0)continue;',
            'if($discountAmount>$newAmount)$discountAmount=$newAmount;',
            '$netAmount=round($newAmount-$discountAmount,2);',
        ] as $needle) {
            $this->assertStringContainsString($needle, $e,
                'The loop body must be byte-for-byte the same — no financial behaviour changes here.');
        }
    }

    /** P3D-F1-12 — P3D-F2 is deliberately still open; this fix did not touch it. */
    public function test_F1_12_P3D_F2_remains_open_and_untouched(): void
    {
        $this->assertStringNotContainsString('_scholarship', $this->engine(),
            'P3D-F2 (recalculation drops the scholarship that generation applied) is REGISTERED '
            . 'and deliberately NOT fixed here. Silently changing scholarship behaviour under cover '
            . 'of a parameter fix is exactly what the brief forbids.');
    }
}
