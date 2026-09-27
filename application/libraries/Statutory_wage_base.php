<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * `statutory_wage_base` — the labour-codes wage definition.
 *
 * PURE, PAISE-EXACT, AND DELIBERATELY NOT WIRED INTO PAYROLL YET.
 *
 * WHY THIS EXISTS
 * ---------------
 * India's four labour codes took effect 21 November 2025 and introduced ONE
 * standardised definition of "wages" across PF, ESI, gratuity, bonus and
 * overtime. The governing spec in this repository
 * (`docs/specs/hr-payroll-brief.md` §6.3) states the requirement directly:
 *
 *     "A `statutory_wage_base` computed per employee per period, distinct from
 *      `basic`, implementing the add-back."
 *     "All PF / gratuity / bonus / OT calculations consume `statutory_wage_base`,
 *      **never** raw `basic`."
 *
 * THE RULE — Code on Wages 2019, section 2(y)
 * -------------------------------------------
 * Wages are ALL REMUNERATION, and the definition names basic pay, dearness
 * allowance and retaining allowance as included. Clauses (a)–(i) are then
 * excluded: bonus, value of housing accommodation, employer contributions to
 * pension/provident fund, conveyance allowance and travelling concessions, sums
 * paid to defray special expenses, house rent allowance, overtime allowance,
 * commission, and gratuity / retrenchment compensation / other retirement
 * benefits / ex-gratia.
 *
 * FIRST PROVISO — the add-back:
 *
 *     where payments under the excluded clauses exceed ONE-HALF, "or such other
 *     percentage as may be notified by the Central Government", of all
 *     remuneration, the excess is added back into wages.
 *
 * That last clause is why the threshold is a PARAMETER here and not a literal.
 * The statute anticipates it changing by notification, so it belongs with rates
 * and ceilings in effective-dated config, not baked into a pure function.
 * (Corrected 2026-08-22 — it was previously hard-coded to one-half.)
 *
 * THE STRUCTURE IS EXCLUSION-LIST, NOT INCLUSION-LIST
 * ---------------------------------------------------
 * This is the substantive correction made on 2026-08-22, and it matters.
 *
 * Wages = all remuneration MINUS the named exclusions. So a component that
 * appears in no list is WAGES. The previous model summed two named lists and
 * took their sum as total remuneration, which gave an unlisted component a
 * weight of zero in the wage base AND in the denominator the add-back threshold
 * is measured against — understating the threshold test and therefore
 * UNDER-triggering the add-back. It failed in the direction that reduces
 * statutory liability.
 *
 * That was harmless for the legacy six-field structure, whose vocabulary is
 * closed and fully enumerated below. It would NOT have been harmless once
 * tenant-defined components reach the calculator, because `salaryComponents`
 * lets a tenant mint any code it likes. `computeFromComponents()` is the path
 * that must be used there, and it defaults an unclassified component to
 * INCLUDED — the fail-safe direction.
 *
 * WHY CLASSIFICATION IS THREE-STATE, NOT A BOOLEAN
 * ------------------------------------------------
 * A component can stand in one of three relationships to wages, and a boolean
 * cannot express the third:
 *
 *   INCLUDE      wages, and part of total remuneration.
 *                Basic, DA, retaining allowance, and — per the Supreme Court in
 *                *RPFC (II) West Bengal v. Vivekananda Vidyamandir* (2019) — any
 *                allowance paid "universally, uniformly, necessarily and
 *                ordinarily" to all employees. That case concerned an unaided
 *                SCHOOL paying a special allowance to teaching and non-teaching
 *                staff, which is why it governs this product's market directly.
 *
 *   CONDITIONAL  not wages by default, but IS remuneration — so it counts in the
 *                denominator and sits in the pool the add-back draws from.
 *                HRA, conveyance, overtime allowance: the s.2(y)(a)–(i) items.
 *
 *   EXCLUDE      not remuneration at all, so it counts in NEITHER the wage base
 *                nor the denominator. Reimbursement of actual expenses — the
 *                "sums paid to defray special expenses" of clause (e) — and the
 *                employer's own PF contribution.
 *
 * The distinction between CONDITIONAL and EXCLUDE is exactly the distinction
 * between "excluded from wages but still remuneration" and "not remuneration",
 * and collapsing them would silently shrink the add-back denominator again.
 *
 * Zoho Payroll ships the same three states, and its documentation notes the
 * setting "replaces the separate EPF and ESI options that were available
 * earlier" — because the codes define one wage for both. ZenX's separate
 * `pfApplicable` / `esiApplicable` booleans are the model that replaced.
 *
 * WHAT THIS CLASS DELIBERATELY DOES NOT DO
 * ----------------------------------------
 * It contains **no rate, ceiling or slab**, and now not even the threshold —
 * that is supplied by the caller from effective-dated config, which is where
 * the spec's "never invent rates, slabs or ceilings from memory" rule applies.
 *
 * It also does not decide which component is which. Classification is a
 * per-employer question of fact under the universality test, and belongs in the
 * tenant's component catalogue with CA sign-off — not in a shared library.
 *
 * IT IS NOT WIRED IN, ON PURPOSE
 * ------------------------------
 * `Hr::_compute_payslip()` computes PF on `effectiveBasic` and ESI on `gross`.
 * Moving them onto this base changes what every employee contributes and what
 * every school owes, and is a statutory compliance decision requiring CA
 * sign-off. Wiring is a separate, gated cutover behind
 * `USE_STATUTORY_WAGE_BASE`, which defaults false.
 *
 * See `docs/compliance/labour-codes-notes.md` for what must be re-verified with
 * a CA each financial year.
 */
class Statutory_wage_base
{
    // ── Classification vocabulary ────────────────────────────────────────

    /** Wages, and part of total remuneration. */
    const CLASS_INCLUDE = 'INCLUDE';

    /** Not wages by default, but IS remuneration — feeds the add-back pool. */
    const CLASS_CONDITIONAL = 'CONDITIONAL';

    /** Not remuneration at all — outside both the base and the denominator. */
    const CLASS_EXCLUDE = 'EXCLUDE';

    const CLASSIFICATIONS = [self::CLASS_INCLUDE, self::CLASS_CONDITIONAL, self::CLASS_EXCLUDE];

    /**
     * The default add-back threshold, as an exact fraction.
     *
     * One-half is what the first proviso currently states. It is expressed as a
     * numerator/denominator pair rather than a float so the halving stays exact
     * in integer paise, and it is overridable because the proviso says the
     * Central Government may notify another percentage.
     */
    const DEFAULT_THRESHOLD_NUM = 1;
    const DEFAULT_THRESHOLD_DEN = 2;

    // ── Legacy six-field vocabulary ──────────────────────────────────────

    /**
     * These two lists describe the CLOSED legacy structure shape only —
     * `_compute_payslip()`'s six earning fields plus retaining allowance. For
     * that shape the enumeration is exhaustive, which is why `compute()` is
     * still safe. They are NOT a general statement about components.
     */
    const INCLUDED = ['basic', 'da', 'retaining_allowance'];

    /**
     * Excluded-but-remuneration under the legacy shape.
     *
     * `other_allowances` sits here for continuity with the shipped engine, and
     * that placement is now known to be the wrong default under *Vivekananda
     * Vidyamandir*: a residual allowance paid to every member of staff is
     * wages. It is not silently changed here, because doing so would alter the
     * legacy path's answer. The component path is where the correct, per-tenant
     * classification is expressed, and it is the path the cutover uses.
     */
    const EXCLUDED = ['hra', 'conveyance', 'ta', 'medical', 'other_allowances', 'overtime_allowance'];

    /**
     * The STATUTORY DEFAULT classification for each shipped component code.
     *
     * These are defaults derived from section 2(y), not tenant policy. Each is
     * justified below, and each is overridable per tenant on the component
     * catalogue — because for two of them the correct answer is a question of
     * fact about how that particular school pays its staff.
     *
     * The reasoning is the same throughout: s.2(y) defines wages as ALL
     * REMUNERATION minus the exclusions named in clauses (a)–(i). So the
     * question for each component is "is it named in an exclusion clause?",
     * and if not, it is wages.
     *
     *   BASIC            INCLUDE      named in the inclusion limb of s.2(y)
     *   DA               INCLUDE      named in the inclusion limb; also named in
     *                                 EPF Act s.6 as part of the contribution base
     *   HRA              CONDITIONAL  named in clause (f) — excluded from wages,
     *                                 but it IS remuneration, so it counts in the
     *                                 add-back denominator
     *   TA               CONDITIONAL  clause (d), conveyance allowance and
     *                                 travelling concessions
     *   MEDICAL          INCLUDE      NOT named in any exclusion clause. A fixed
     *                                 medical ALLOWANCE is remuneration. Clause
     *                                 (e) covers "sums paid to defray special
     *                                 expenses", which is a REIMBURSEMENT of
     *                                 actuals — a different thing wearing a
     *                                 similar name. A tenant that actually runs
     *                                 reimbursement must reclassify to EXCLUDE.
     *                                 CA-2.
     *   OTHER_ALLOWANCES INCLUDE      NOT named in any exclusion clause, and this
     *                                 is the component *Vivekananda Vidyamandir*
     *                                 was decided about: a residual allowance paid
     *                                 to all staff is basic wages for PF. A tenant
     *                                 whose residual bucket is genuinely NOT paid
     *                                 universally must reclassify. CA-1.
     *
     * Deductions and employer contributions are not remuneration paid to the
     * employee and must never enter either side of the calculation. The
     * employer's own PF contribution is additionally named in clause (c).
     * They are mapped to EXCLUDE defensively, so that passing the whole
     * catalogue in cannot corrupt the base.
     *
     * NOTE ON THE TWO INCLUDE DEFAULTS. Defaulting MEDICAL and OTHER_ALLOWANCES
     * into wages RAISES contributions relative to today. That is deliberate and
     * it is the direction the statute and the case law point. It changes nothing
     * until `USE_STATUTORY_WAGE_BASE` is switched on, which is a decision the
     * owner takes with their CA — never a default that arrives silently.
     */
    const DEFAULT_CLASSIFICATION = [
        // Earnings
        'BASIC'            => self::CLASS_INCLUDE,
        'DA'               => self::CLASS_INCLUDE,
        'HRA'              => self::CLASS_CONDITIONAL,
        'TA'               => self::CLASS_CONDITIONAL,
        'MEDICAL'          => self::CLASS_INCLUDE,
        'OTHER_ALLOWANCES' => self::CLASS_INCLUDE,

        // Legacy structure field names, same components lower-cased.
        'basic'            => self::CLASS_INCLUDE,
        'da'               => self::CLASS_INCLUDE,
        'retaining_allowance' => self::CLASS_INCLUDE,
        'hra'              => self::CLASS_CONDITIONAL,
        'ta'               => self::CLASS_CONDITIONAL,
        'conveyance'       => self::CLASS_CONDITIONAL,
        'overtime_allowance' => self::CLASS_CONDITIONAL,
        'medical'          => self::CLASS_INCLUDE,
        'other_allowances' => self::CLASS_INCLUDE,

        // Not remuneration — never part of the base or the denominator.
        'PF_EMPLOYEE'      => self::CLASS_EXCLUDE,
        'ESI_EMPLOYEE'     => self::CLASS_EXCLUDE,
        'TDS'              => self::CLASS_EXCLUDE,
        'PROFESSIONAL_TAX' => self::CLASS_EXCLUDE,
        'OTHER_DEDUCTIONS' => self::CLASS_EXCLUDE,
        'PF_EMPLOYER'      => self::CLASS_EXCLUDE,
        'ESI_EMPLOYER'     => self::CLASS_EXCLUDE,
    ];

    /**
     * The classification to apply to one component.
     *
     * Precedence, and the reason for it:
     *
     *   1. An EXPLICIT value on the tenant's catalogue row. The universality
     *      test is a question of fact about a particular employer, so the
     *      tenant's own answer — made with their CA — outranks any default.
     *   2. The statutory default above.
     *   3. INCLUDE, for a component nobody has heard of. Under s.2(y) an
     *      unnamed component is wages, so this is the statute's own default
     *      rather than a convention chosen here.
     *
     * @param string     $code         component code or legacy field name
     * @param array|null $catalogueRow the tenant's row for it, if any
     * @return array{0:string,1:bool} [classification, wasExplicit]
     */
    public static function classify(string $code, ?array $catalogueRow = null, string $asOfDate = ''): array
    {
        // 1a. An EFFECTIVE-DATED schedule, resolved for the period.
        //
        // A CA determination is a statement about a point in time, not a
        // permanent truth: an allowance can genuinely become universal (or stop
        // being so) partway through a year. Without dating, correcting a
        // classification in September would silently reprice July — the exact
        // failure the statutory config schedule already exists to prevent.
        //
        // Resolved through the SAME `ed_select_for()` the statutory config and
        // salary assignments use. A second implementation would eventually
        // disagree, and the disagreement would surface as one period resolving
        // to one classification but a different statutory config.
        if (is_array($catalogueRow) && !empty($catalogueRow['wageClassificationSchedule'])
            && is_array($catalogueRow['wageClassificationSchedule']) && $asOfDate !== '') {

            self::loadEffectiveDating();
            $row = ed_select_for($catalogueRow['wageClassificationSchedule'], $asOfDate);

            if (is_array($row) && isset($row['classification'])) {
                $scheduled = strtoupper((string) $row['classification']);
                if (in_array($scheduled, self::CLASSIFICATIONS, true)) {
                    return [$scheduled, true];
                }
            }
        }

        // 1b. A flat, undated override. Still honoured — a tenant that has made
        // one determination and never changed it should not be forced to model
        // a schedule to express that.
        if (is_array($catalogueRow) && isset($catalogueRow['wageClassification'])) {
            $explicit = strtoupper((string) $catalogueRow['wageClassification']);
            if (in_array($explicit, self::CLASSIFICATIONS, true)) {
                return [$explicit, true];
            }
        }

        if (isset(self::DEFAULT_CLASSIFICATION[$code])) {
            return [self::DEFAULT_CLASSIFICATION[$code], false];
        }

        return [self::CLASS_INCLUDE, false];
    }

    /**
     * The full resolution record for one component — what was chosen, and why.
     *
     * `classify()` answers "which classification". This answers "which, from
     * where, decided by whom, effective when" — which is what an audit or a
     * payslip trace needs, and what makes a CA's determination reviewable
     * months later.
     */
    public static function classificationDetail(string $code, ?array $catalogueRow = null, string $asOfDate = ''): array
    {
        $scheduleRow = null;

        if (is_array($catalogueRow) && !empty($catalogueRow['wageClassificationSchedule'])
            && is_array($catalogueRow['wageClassificationSchedule']) && $asOfDate !== '') {
            self::loadEffectiveDating();
            $scheduleRow = ed_select_for($catalogueRow['wageClassificationSchedule'], $asOfDate);
        }

        list($class, $explicit) = self::classify($code, $catalogueRow, $asOfDate);

        return [
            'code'           => $code,
            'classification' => $class,
            'explicit'       => $explicit,
            'source'         => $scheduleRow !== null ? 'schedule'
                : ($explicit ? 'override' : 'statutory_default'),
            'effectiveFrom'  => is_array($scheduleRow) ? (string) ($scheduleRow['effectiveFrom'] ?? '') : '',
            'effectiveTo'    => is_array($scheduleRow) ? (string) ($scheduleRow['effectiveTo'] ?? '') : '',
            'reason'         => is_array($scheduleRow) ? (string) ($scheduleRow['reason'] ?? '')
                : (string) ($catalogueRow['wageClassificationReason'] ?? ''),
            'verifiedBy'     => is_array($scheduleRow) ? (string) ($scheduleRow['verifiedBy'] ?? '')
                : (string) ($catalogueRow['wageClassificationVerifiedBy'] ?? ''),
            'verifiedAt'     => is_array($scheduleRow) ? (string) ($scheduleRow['verifiedAt'] ?? '')
                : (string) ($catalogueRow['wageClassificationVerifiedAt'] ?? ''),
        ];
    }

    /** Load the shared effective-dating helper outside a CI request context too. */
    private static function loadEffectiveDating(): void
    {
        if (function_exists('ed_select_for')) return;

        $path = defined('APPPATH')
            ? APPPATH . 'helpers/effective_dating_helper.php'
            : __DIR__ . '/../helpers/effective_dating_helper.php';

        if (is_file($path)) require_once $path;
    }

    /** Rupees → exact paise. Mirrors Hr::_toPaise so both agree to the paise. */
    public static function toPaise($rupees): int
    {
        return (int) round(((float) $rupees) * 100.0);
    }

    /**
     * The wage base for one employee for one period, in PAISE, from a LEGACY
     * six-field salary structure.
     *
     * Unchanged in behaviour. Retained because the legacy structure vocabulary
     * is closed and fully enumerated above, so the inclusion-list weakness
     * cannot bite here — a legacy structure has no component this class has not
     * heard of.
     *
     * New code that has a classified component set should call
     * `computeFromComponents()` instead.
     *
     * @param array $structure salary structure in RUPEES, as stored
     * @param array $opts      ['thresholdNum' => int, 'thresholdDen' => int]
     * @return array{
     *   basePaise: int, includedPaise: int, excludedPaise: int,
     *   totalPaise: int, addBackPaise: int, addBackApplied: bool, trace: array
     * }
     */
    public static function compute(array $structure, array $opts = []): array
    {
        $components = [];

        foreach (self::INCLUDED as $k) {
            $components[] = [
                'code'           => $k,
                'amountPaise'    => self::toPaise($structure[$k] ?? 0),
                'classification' => self::CLASS_INCLUDE,
            ];
        }
        foreach (self::EXCLUDED as $k) {
            $components[] = [
                'code'           => $k,
                'amountPaise'    => self::toPaise($structure[$k] ?? 0),
                'classification' => self::CLASS_CONDITIONAL,
            ];
        }

        return self::computeFromComponents($components, $opts);
    }

    /**
     * The wage base from a CLASSIFIED COMPONENT SET — the canonical entry point.
     *
     * Each component is `['code' => string, 'amountPaise' => int,
     * 'classification' => INCLUDE|CONDITIONAL|EXCLUDE]`.
     *
     * An unrecognised or missing classification is treated as **INCLUDE**. That
     * is deliberate and it is the whole point of the exclusion-list correction:
     * under s.2(y) wages are all remuneration minus the NAMED exclusions, so a
     * component nobody has classified is wages until somebody says otherwise.
     * Defaulting it the other way would quietly shrink both the wage base and
     * the add-back denominator, which is the direction that under-states a
     * statutory liability. The unclassified codes are named in the trace so the
     * gap is visible rather than absorbed.
     *
     * @param array $components classified component set (amounts in PAISE)
     * @param array $opts       ['thresholdNum' => int, 'thresholdDen' => int]
     */
    public static function computeFromComponents(array $components, array $opts = []): array
    {
        $num = (int) ($opts['thresholdNum'] ?? self::DEFAULT_THRESHOLD_NUM);
        $den = (int) ($opts['thresholdDen'] ?? self::DEFAULT_THRESHOLD_DEN);
        if ($den <= 0 || $num < 0 || $num > $den) {
            $num = self::DEFAULT_THRESHOLD_NUM;
            $den = self::DEFAULT_THRESHOLD_DEN;
        }

        $included = 0;
        $conditional = 0;
        $outside = 0;
        $unclassified = [];
        $trace = [];

        foreach ($components as $c) {
            if (!is_array($c)) continue;

            $code   = (string) ($c['code'] ?? '');
            $amount = (int) ($c['amountPaise'] ?? 0);
            $raw    = isset($c['classification']) ? strtoupper((string) $c['classification']) : '';

            $class = in_array($raw, self::CLASSIFICATIONS, true) ? $raw : self::CLASS_INCLUDE;
            if ($class !== $raw) $unclassified[] = $code;

            if ($class === self::CLASS_INCLUDE) {
                $included += $amount;
            } elseif ($class === self::CLASS_CONDITIONAL) {
                $conditional += $amount;
            } else {
                $outside += $amount;
            }

            $trace[] = ['code' => $code, 'amountPaise' => $amount, 'classification' => $class];
        }

        // Total remuneration for the proviso: wages plus the excluded-but-
        // remuneration pot. Items classified EXCLUDE are not remuneration and
        // are deliberately outside this figure.
        $total = $included + $conditional;

        // The trigger is an EXACT integer comparison, with no rounding at all:
        //
        //     conditional > total × num / den     ⟺     conditional × den > total × num
        //
        // Corrected 2026-08-22. This previously rounded the threshold UP and
        // compared against that, and the comment claimed doing so kept the base
        // from being understated. It does the opposite. A higher threshold is
        // harder to exceed, so it produces a SMALLER add-back and a SMALLER wage
        // base. On a 3-paise total with a 2-paise excluded pot, 2 > 1.5 is true
        // and the proviso requires an add-back; rounding the threshold to 2
        // suppressed it entirely — in the employer's favour, which is the
        // direction that understates a statutory liability.
        //
        // The cross-multiplied form needs no rounding to decide the trigger, so
        // the question does not arise.
        $addBack = 0;
        if ($conditional * $den > $total * $num) {
            // The threshold itself is FLOORED, so the excess that comes back is
            // rounded UP by at most a paise. That is the fail-safe direction:
            // an employee is never short-changed by a rounding choice, and the
            // school over-contributes by at most one paise per employee period.
            $addBack = $conditional - intdiv($total * $num, $den);
        }

        // Reported for the trace only — never used to decide the trigger.
        $threshold = intdiv($total * $num, $den);

        return [
            'basePaise'        => $included + $addBack,
            'includedPaise'    => $included,
            'excludedPaise'    => $conditional,
            'outsidePaise'     => $outside,
            'totalPaise'       => $total,
            'thresholdPaise'   => $threshold,
            'addBackPaise'     => $addBack,
            'addBackApplied'   => $addBack > 0,
            'unclassified'     => array_values(array_unique($unclassified)),
            'trace'            => $trace,
        ];
    }

    /**
     * Turn a legacy six-field salary structure into a CLASSIFIED component set,
     * using the statutory defaults and any tenant overrides.
     *
     * This is the correct path for a legacy structure, and it is deliberately
     * NOT what `compute()` does.
     *
     * `compute()` is frozen legacy-compatible behaviour: it treats `medical` and
     * `other_allowances` as excluded, because that is what the shipping engine
     * does. This method applies `DEFAULT_CLASSIFICATION`, under which both are
     * wages — `medical` because a fixed allowance is not the reimbursement that
     * clause (e) excludes, and `other_allowances` because *Vivekananda
     * Vidyamandir* holds a universally-paid residual allowance to be basic
     * wages.
     *
     * The two therefore DISAGREE, on purpose. That disagreement is exactly the
     * exposure the parity harness is meant to quantify before anyone accepts it:
     * `compute()` is "what we pay today", this is "what the statute says", and
     * the delta between them is the number the owner and their CA need to see.
     *
     * @param array $structure salary structure in RUPEES, as stored
     * @param array $catalogue tenant catalogue rows keyed by component code
     */
    public static function classifyLegacyStructure(array $structure, array $catalogue = [], string $asOfDate = ''): array
    {
        $fields = array_merge(self::INCLUDED, self::EXCLUDED);
        $components = [];

        foreach ($fields as $field) {
            if (!array_key_exists($field, $structure)) continue;

            list($class,) = self::classify($field, $catalogue[$field] ?? null, $asOfDate);
            $components[] = [
                'code'           => $field,
                'amountPaise'    => self::toPaise($structure[$field]),
                'classification' => $class,
            ];
        }

        return $components;
    }

    /**
     * Does this structure trip the add-back?
     *
     * The spec asks for a validation warning "when a structure is created with
     * basic < 50% of remuneration", so a writer can flag it at entry time
     * rather than surprising someone on a payslip.
     */
    public static function needsAddBack(array $structure, array $opts = []): bool
    {
        return self::compute($structure, $opts)['addBackApplied'];
    }
}
