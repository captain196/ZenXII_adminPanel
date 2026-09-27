<?php
namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * ══════════════════════════════════════════════════════════════════════════
 * PHASE 3D — GOLDEN MASTER OF THE LEGACY FEE ENGINES (pre-routing)
 * ══════════════════════════════════════════════════════════════════════════
 *
 * The brief requires the current behaviour of the five endpoints to be captured
 * BEFORE they are routed through Fee_lifecycle, so that flag-OFF equivalence can
 * be proven rather than asserted.
 *
 * All five funnel into two shared engines:
 *
 *   _generateDemandsForMonth()            ← generate_demands_for_student,
 *                                           _processGenerationJob
 *   _recalculateStudentUnpaidDemands()    ← save_updated_fees,
 *                                           recalc_unpaid_discounts,
 *                                           recalculate_demands
 *
 * Characterising them turned up a defect that changes the routing plan, so this
 * file records the CURRENT contract including that defect. It is a
 * characterisation suite: it asserts what the code does today, not what it
 * ought to do. Where today's behaviour is wrong, the test says so in its name
 * and message so it cannot be mistaken for an endorsement.
 *
 * ── NO I/O ──────────────────────────────────────────────────────────────────
 * Source is read with comments STRIPPED, so no comment can satisfy an
 * assertion. No Firestore, no tenant, no writes.
 */
class Phase3dLegacyEngineGoldenMasterTest extends TestCase
{
    private const FEES = __DIR__ . '/../../application/controllers/Fees.php';
    private const MYC  = __DIR__ . '/../../application/core/MY_Controller.php';

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

    /** Body of a method, comments stripped, bounded by the next `function`. */
    private function body(string $path, string $name): string
    {
        $s = $this->src($path);
        $start = strpos($s, "function {$name}(");
        $this->assertNotFalse($start, "{$name}() not found.");
        $end = strpos($s, 'function ', $start + strlen($name) + 12);
        return substr($s, $start, ($end === false ? strlen($s) : $end) - $start);
    }

    // ══════════════════════════════════════════════════════════════════
    //  P3D-F1 — THE DEFECT: the recalc engine ignores its own parameter
    // ══════════════════════════════════════════════════════════════════

    /**
     * `_recalculateStudentUnpaidDemands(string $studentId, …)` is a PRIVATE
     * helper that receives the student as a parameter — and then immediately
     * throws that parameter away and re-reads it from the request:
     *
     *     $studentId = trim($this->input->post('student_id') ?? '');
     *
     * Every caller that passes a student OTHER than the one in the POST is
     * therefore silently operating on the wrong student, or on none.
     */
    public function test_P3D_F1_RETIRED_engine_now_honours_its_parameters(): void
    {
        // RETIRED BEHAVIOUR, replaced deliberately (operator-authorised 2026-08-17).
        //
        // This test used to assert that the engine OVERWROTE its $studentId
        // parameter from the request — the P3D-F1 defect. That defect is now
        // fixed, so asserting it would pin a bug the operator asked to remove.
        // Per the brief: "If an old test encodes deliberately retired behaviour,
        // document the reason and update it to the new approved contract."
        //
        // The full corrected contract is enforced by
        // Phase3dF1RecalcParameterFixTest (12 tests). This keeps the golden
        // master honest about what the engine does TODAY.
        $flat = preg_replace('/\s+/', '', $this->body(self::FEES, '_recalculateStudentUnpaidDemands'));

        $this->assertStringContainsString('string$studentId,array$feeChart,array$discountMap', $flat,
            'The engine still takes all three parameters …');
        $this->assertStringNotContainsString("\$studentId=trim(\$this->input->post('student_id')??'')", $flat,
            '… and no longer discards $studentId in favour of the request (P3D-F1 FIXED).');
    }

    /**
     * The consequence, traced to its end: an empty student id reaches
     * `json_error()`, and `json_error()` ends in `exit`. A `try` around the
     * call site cannot catch that — `exit` is not an exception.
     */
    public function test_P3D_F1_RETIRED_empty_student_id_no_longer_exits(): void
    {
        // RETIRED BEHAVIOUR (see the note above). Previously an empty student id
        // reached json_error() and json_error() ends in `exit`, killing the
        // whole request from inside a per-student loop.
        $flat = preg_replace('/\s+/', '', $this->body(self::FEES, '_recalculateStudentUnpaidDemands'));

        $this->assertStringNotContainsString('json_error', $flat,
            'The helper no longer emits a response …');
        $this->assertStringContainsString("if(\$studentId===''||\$feeChart===[]){return['updated'=>0,'preserved'=>0,'attempted'=>0];}", $flat,
            '… it returns the neutral shape instead, keeping save_updated_fees() fail-soft.');

        // json_error() itself is unchanged and still exits — that was never the
        // defect, and other callers depend on it.
        $this->assertStringContainsString('exit', $this->body(self::MYC, 'json_error'),
            'json_error() still terminates the request for the endpoints that legitimately use it.');
    }

    /**
     * The reachable victim: `save_updated_fees()` saves a CLASS fee chart and
     * then loops the roster calling the engine per student. It posts no
     * `student_id` at all, so the first iteration exits the request.
     */
    public function test_P3D_F1_save_updated_fees_is_the_reachable_victim(): void
    {
        $m = $this->body(self::FEES, 'save_updated_fees');

        // P5-K (2026-08-20): save_updated_fees is no longer "the reachable
        // victim" of the legacy engine — it calls the canonical recalculation
        // per student instead. The per-student loop and the absence of a posted
        // student_id (the actual subject of P3D-F1) are unchanged, and are what
        // the remaining assertions still pin.
        $this->assertStringContainsString('_recalculateStudentCanonical', $m,
            'save_updated_fees() now calls the CANONICAL recalculation per student …');
        $this->assertStringNotContainsString('_recalculateStudentUnpaidDemands', $m,
            '… and the legacy engine is no longer reachable from it at all.');
        $this->assertStringNotContainsString('student_id', $m,
            '… while posting no student_id of its own. The engine therefore reads '
            . '\'\'. BEFORE the P3D-F1 fix that exited the request on the FIRST student and every '
            . 'later student was silently never recalculated. AFTER the fix the helper uses the $sid '
            . 'the loop passes, so the roster completes — which is why this endpoint posting no '
            . 'student_id is now harmless rather than fatal.');

        // And the loop it dies inside is a per-student loop, so the damage is
        // proportional to roster size, not a single miss.
        $this->assertMatchesRegularExpression('/foreach\(\$rosteras\$sid=>/',
            preg_replace('/\s+/', '', $m),
            'The call sits inside the roster loop.');
    }

    /**
     * `recalculate_demands()` and `recalc_unpaid_discounts()` happen to work —
     * but only because they post the same student they pass. The engine is
     * still reading the request rather than its argument; they are correct by
     * coincidence, not by contract.
     */
    public function test_P3D_F1_other_callers_are_correct_only_by_coincidence(): void
    {
        foreach (['recalculate_demands', 'recalc_unpaid_discounts'] as $endpoint) {
            $m = $this->body(self::FEES, $endpoint);
            $this->assertStringContainsString("input->post('student_id')", preg_replace('/\s+/', '', $m),
                "{$endpoint}() posts a student_id, so the engine's re-read happens to agree with the "
                . 'argument it was given. That is coincidence — the parameter is still ignored.');
        }
    }

    // ══════════════════════════════════════════════════════════════════
    //  GOLDEN MASTER — the generation engine's current contract
    // ══════════════════════════════════════════════════════════════════

    /** Discount vocabulary: -1 means "fully exempt", a positive number is a fixed amount. */
    public function test_GM_discount_map_vocabulary(): void
    {
        $g = $this->body(self::FEES, '_getStudentDiscounts');
        $flat = preg_replace('/\s+/', '', $g);

        $this->assertStringContainsString('$discounts[$title]=-1;', $flat,
            'exemptedFees ⇒ the sentinel -1 (fully exempt).');
        $this->assertStringContainsString("\$discounts['_scholarship']", $flat,
            'scholarshipAwards ⇒ a single aggregate under the reserved key _scholarship.');
        $this->assertStringContainsString('$discounts[$title]=(float)$amount;', $flat,
            'discountHeads ⇒ a per-head fixed amount.');
    }

    /**
     * Scholarship is spread PROPORTIONALLY across the heads of the month being
     * generated — and the FULL award amount is used on every month.
     */
    public function test_GM_scholarship_is_spread_proportionally_every_month(): void
    {
        $m = preg_replace('/\s+/', '', $this->body(self::FEES, '_generateDemandsForMonth'));

        $this->assertStringContainsString('$proportion=$amount/$monthTotal;', $m);
        $this->assertStringContainsString('$discountAmount+=round($scholarshipTotal*$proportion,2);', $m,
            'The FULL award is apportioned within each month. Nothing divides it across the year, '
            . 'so a 5,000 award reduces every generated month by 5,000. Whether that is intended '
            . '("per month") or a 12x over-discount is the OPEN business question blocking Objective 2.');
    }

    /** A discount can never exceed the head, and never produces a negative net. */
    public function test_GM_discount_is_clamped_and_net_never_negative(): void
    {
        $m = preg_replace('/\s+/', '', $this->body(self::FEES, '_generateDemandsForMonth'));
        $this->assertStringContainsString('if($discountAmount>$amount){$discountAmount=$amount;}', $m);
        $this->assertStringContainsString('$netAmount=round($amount-$discountAmount,2);', $m,
            'Net is computed from the head GROSS every run — the property that makes repeated '
            . 'generation non-compounding.');
    }

    /** Fully-exempt heads are SKIPPED entirely — no demand row is written. */
    public function test_GM_fully_exempt_head_produces_no_demand(): void
    {
        $m = preg_replace('/\s+/', '', $this->body(self::FEES, '_generateDemandsForMonth'));
        $this->assertStringContainsString("if((\$discountMap[\$feeHead]??0)===-1){", $m,
            'The -1 sentinel drops the head rather than writing a zero-value demand.');
    }

    /** Idempotency: existing non-archived demands are deduped by period + canonical head. */
    public function test_GM_generation_is_deduped_by_period_and_canonical_head(): void
    {
        $m = preg_replace('/\s+/', '', $this->body(self::FEES, '_generateDemandsForMonth'));
        // UPDATED 2026-08-17 (Phase 2). The REQUIREMENT is unchanged — existing
        // non-archived demands seed a dedupe set, and the run is self-guarded.
        // The MECHANISM was deliberately retired: the boolean
        // `$haveKeys[key]=true` became `$haveKeys[key][$demandDocId]=true`, so
        // the guard can count DISTINCT DEMAND DOCUMENTS and tell 1 match from
        // 2+. Counting keys instead would have reported a conflict for a single
        // demand, which is indexed under both the canonical and the name shape.
        $this->assertStringContainsString('$haveKeys[$pk.\'|\'.$cid][$_did]=true;', $m,
            'Existing demands seed a dedupe set, keyed by demand document id …');
        $this->assertStringContainsString('$haveKeys[$dedupKey][$demandId]=true;', $m,
            '… which is also guarded within the run, so one call cannot create the same demand twice.');
        $this->assertStringContainsString('$matchCount>=2', $m,
            'Two or more demands for one occurrence must fail closed, never be silently reused.');
        $this->assertStringContainsString("(\$_d['status']??'')==='archived'", $m,
            'Archived demands are excluded from the dedupe set, so an archived head can be re-created.');
    }

    /** April additionally carries the Yearly Fees heads. */
    public function test_GM_april_merges_yearly_fees(): void
    {
        $m = preg_replace('/\s+/', '', $this->body(self::FEES, '_generateDemandsForMonth'));
        $this->assertStringContainsString("if(\$monthName==='April'){", $m);
        $this->assertStringContainsString('$monthFees=array_merge($monthFees,$yearlyFees);', $m);
    }

    // ══════════════════════════════════════════════════════════════════
    //  GOLDEN MASTER — the recalculation engine's current contract
    // ══════════════════════════════════════════════════════════════════

    /** The canonical-concession fence, and exactly what it protects. */
    public function test_GM_recalc_preserves_canonically_priced_demands(): void
    {
        $m = preg_replace('/\s+/', '', $this->body(self::FEES, '_recalculateStudentUnpaidDemands'));

        $this->assertStringContainsString("!empty(\$demand['concessionApplied'])", $m,
            'A demand priced by the canonical generator is PRESERVED, never re-derived from the '
            . 'legacy discount map — which is what stops the legacy path overcharging a '
            . 'concession student.');
        $this->assertStringNotContainsString('studentEntitlements', $m,
            'But the recalc engine has no way to APPLY an entitlement, so a demand generated at '
            . 'gross before the entitlement existed stays at gross forever.');
    }

    /** Recalculation never deletes a demand whose head vanished from the chart. */
    public function test_GM_recalc_leaves_removed_heads_alone(): void
    {
        $m = preg_replace('/\s+/', '', $this->body(self::FEES, '_recalculateStudentUnpaidDemands'));
        $this->assertStringContainsString('if($newAmount<=0)continue;', $m,
            'A head removed from the chart leaves its existing demand untouched rather than '
            . 'deleting billing history.');
    }

    // ══════════════════════════════════════════════════════════════════
    //  Routing feasibility — what the golden master says about Objective 3
    // ══════════════════════════════════════════════════════════════════

    /**
     * The legacy and canonical builders are NOT interchangeable. Recording the
     * differences here is what makes "flag-OFF equivalence" a real gate rather
     * than a hope.
     */
    public function test_ROUTING_legacy_and_canonical_builders_differ_materially(): void
    {
        $legacy    = preg_replace('/\s+/', '', $this->body(self::FEES, '_generateDemandsForMonth'));
        $canonical = preg_replace('/\s+/', '',
            $this->body(__DIR__ . '/../../application/libraries/Fee_lifecycle.php', 'buildAdmissionDemandSpecs'));

        // 1. Different discount vocabulary.
        $this->assertStringContainsString('_scholarship', $legacy);
        $this->assertStringNotContainsString('_scholarship', $canonical,
            'The canonical builder has no scholarship concept at all — routing would DROP '
            . 'scholarship pricing unless it is carried across, which is exactly why Objective 2 '
            . 'must be answered before Objective 3.');

        // 2. Different id generation.
        $this->assertStringContainsString('newDemandId()', $legacy);
        $this->assertStringContainsString('newDemandId()', $canonical);

        // 3. The canonical builder prices nothing itself — deductions are the
        //    generator's job, which is what makes ONE authority possible.
        $this->assertStringNotContainsString('$discountMap', $canonical,
            'The canonical builder emits GROSS specs and leaves every deduction to '
            . 'Fee_generation_service — the separation the routing depends on.');
    }

    /**
     * P3D-F2 — the two legacy engines disagree with EACH OTHER about scholarship.
     *
     * Generation apportions the scholarship across the month's heads;
     * recalculation reads only $discountMap[$feeHead] and never touches the
     * reserved `_scholarship` key. So generating a month applies the
     * scholarship, and recalculating that same month silently removes it —
     * the student's bill goes UP on a recalculation that changed nothing else.
     *
     * Registered, NOT fixed: a pre-existing divergence inside the legacy path,
     * and correcting it changes money.
     */
    public function test_P3D_F2_recalc_silently_drops_the_scholarship_that_generation_applied(): void
    {
        $gen = preg_replace('/\s+/', '', $this->body(self::FEES, '_generateDemandsForMonth'));
        $rec = preg_replace('/\s+/', '', $this->body(self::FEES, '_recalculateStudentUnpaidDemands'));

        $this->assertStringContainsString('_scholarship', $gen,
            'Generation applies the scholarship aggregate …');
        $this->assertStringNotContainsString('_scholarship', $rec,
            '… and recalculation does not, so a recalculated demand loses it. P3D-F2.');
        $this->assertStringContainsString('$discountMap[$feeHead]>0', $rec,
            'Recalc only ever reads a PER-HEAD discount, never the aggregate.');
    }
}