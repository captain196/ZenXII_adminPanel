<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Statutory_config_service — effective-dated statutory configuration and
 * resolution (ZenX HRMS W2.2).
 *
 * WHAT THIS IS
 * ------------
 * The tenant's PF / ESI / PT / TDS policy, versioned by effective date, plus a
 * pure resolver that answers two questions:
 *
 *   1. WHICH configuration applies to a given payroll date?
 *   2. GIVEN a wage, what does that configuration produce?
 *
 * Today those rules live nowhere. `Hr::_compute_payslip()` multiplies an
 * operator-typed percentage by a base and stops — no ceiling, no eligibility
 * threshold, no slab, and no way to reconstruct what the policy was last March.
 * Its own comment says so: *"No statutory ceilings, eligibility thresholds,
 * slabs, tax engines… out of scope."*
 *
 * WHAT THIS IS NOT (W2.2 boundary)
 * --------------------------------
 * Nothing in the payroll path calls this. `_compute_payslip()`,
 * `generate_payroll()`, `Payroll.php` and `salarySlips` are untouched, and no
 * tenant configuration has been written. Wiring is W2.4, and it happens only
 * after the resolver is proven. This gate ships the model, the resolver and
 * its tests.
 *
 * MONEY (W1.2 contract)
 * ---------------------
 * Every monetary value — ceilings, thresholds, slab boundaries, slab amounts,
 * computed contributions — is integer paise. Rates stay decimal percentages;
 * a rate is not money and is never converted.
 *
 * Ratio application goes through `applyRatio()`, which is the SAME arithmetic
 * as `Hr::_applyRatio()`, deliberately identical rather than merely similar:
 * one multiplication, one `round()`, cast to int. `StatutoryEngineTest`
 * reflects into the private `Hr::_applyRatio()` and asserts the two agree
 * across a range of inputs, so this cannot silently become a second money
 * policy.
 */
class Statutory_config_service
{
    /** @var object Firestore_service, already school-scoped. */
    private $fs;
    /** @var string */
    private $actor = '';

    const COLLECTION = 'statutoryConfig';

    /** TDS modes. Only FLAT_PERCENT is implementable in W2.2 — see validate(). */
    const TDS_MODES = ['FLAT_PERCENT', 'SLAB'];

    const DATE_PATTERN = '/^\d{4}-\d{2}-\d{2}$/';

    public function init($fs, string $actor = ''): void
    {
        $this->fs    = $fs;
        $this->actor = $actor;
    }

    // ─────────────────────────────────────────────────────────────────────
    //  Money helper — mirrors Hr::_applyRatio() exactly
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Apply a ratio to an integer-paise amount.
     *
     * Byte-identical in behaviour to `Hr::_applyRatio()` (W1.2): a single
     * multiplication and a single controlled rounding. Reimplemented rather
     * than called because that method is private static on a controller; the
     * equivalence is pinned by a reflection test so the two can never diverge.
     */
    public static function applyRatio(int $paise, float $ratio): int
    {
        if ($paise === 0 || $ratio == 0.0) return 0;
        return (int) round($paise * $ratio);
    }

    /** Percentage → ratio. A rate is not money; it is never paise. */
    public static function pctToRatio(float $pct): float
    {
        return $pct / 100.0;
    }

    // ─────────────────────────────────────────────────────────────────────
    //  Validation
    // ─────────────────────────────────────────────────────────────────────

    private static function isIntPaise($v): bool
    {
        if (is_int($v)) return true;
        // A decimal here means someone passed rupees — the exact W1.2 regression.
        return is_string($v) && ctype_digit($v);
    }

    private static function isRate($v): bool
    {
        return is_numeric($v) && is_finite((float) $v) && (float) $v >= 0 && (float) $v <= 100;
    }

    /**
     * Validate a configuration payload.
     *
     * @return array{0:array,1:array} [clean, errors]
     */
    public function validate(array $in): array
    {
        $e = [];

        // ── effective dating ──
        $from = trim((string) ($in['effectiveFrom'] ?? ''));
        if (!preg_match(self::DATE_PATTERN, $from) || !self::isRealDate($from)) {
            $e[] = 'effectiveFrom must be a real date in YYYY-MM-DD form.';
        }
        $to = $in['effectiveTo'] ?? null;
        if ($to === '' || $to === 'null') $to = null;
        if ($to !== null) {
            $to = trim((string) $to);
            if (!preg_match(self::DATE_PATTERN, $to) || !self::isRealDate($to)) {
                $e[] = 'effectiveTo must be a real date in YYYY-MM-DD form, or null for open-ended.';
            } elseif ($from !== '' && $to < $from) {
                $e[] = 'effectiveTo must not precede effectiveFrom.';
            }
        }

        // ── PF ──
        $pfIn = is_array($in['pf'] ?? null) ? $in['pf'] : [];
        $pf = [
            'employeeRate'        => $pfIn['employeeRate'] ?? 0,
            'employerRate'        => $pfIn['employerRate'] ?? 0,
            'wageCeilingPaise'    => $pfIn['wageCeilingPaise'] ?? 0,
            'applyCeiling'        => !empty($pfIn['applyCeiling']),
            'eligibleAboveCeiling'=> !empty($pfIn['eligibleAboveCeiling']),
        ];
        if (!self::isRate($pf['employeeRate'])) $e[] = 'pf.employeeRate must be a percentage between 0 and 100.';
        if (!self::isRate($pf['employerRate'])) $e[] = 'pf.employerRate must be a percentage between 0 and 100.';
        if (!self::isIntPaise($pf['wageCeilingPaise'])) {
            $e[] = 'pf.wageCeilingPaise must be an integer number of paise (not rupees, not a decimal).';
        } elseif ((int) $pf['wageCeilingPaise'] < 0) {
            $e[] = 'pf.wageCeilingPaise must not be negative.';
        }
        if ($pf['applyCeiling'] && (int) $pf['wageCeilingPaise'] <= 0) {
            $e[] = 'pf.applyCeiling is set but no positive pf.wageCeilingPaise was supplied.';
        }
        $pf['employeeRate']     = (float) $pf['employeeRate'];
        $pf['employerRate']     = (float) $pf['employerRate'];
        $pf['wageCeilingPaise'] = (int) $pf['wageCeilingPaise'];

        // ── ESI ──
        $esiIn = is_array($in['esi'] ?? null) ? $in['esi'] : [];
        $esi = [
            'employeeRate'      => $esiIn['employeeRate'] ?? 0,
            'employerRate'      => $esiIn['employerRate'] ?? 0,
            'wageThresholdPaise'=> $esiIn['wageThresholdPaise'] ?? 0,
            'applicable'        => !empty($esiIn['applicable']),
        ];
        if (!self::isRate($esi['employeeRate'])) $e[] = 'esi.employeeRate must be a percentage between 0 and 100.';
        if (!self::isRate($esi['employerRate'])) $e[] = 'esi.employerRate must be a percentage between 0 and 100.';
        if (!self::isIntPaise($esi['wageThresholdPaise'])) {
            $e[] = 'esi.wageThresholdPaise must be an integer number of paise.';
        } elseif ((int) $esi['wageThresholdPaise'] < 0) {
            $e[] = 'esi.wageThresholdPaise must not be negative.';
        }
        if ($esi['applicable'] && (int) $esi['wageThresholdPaise'] <= 0) {
            $e[] = 'esi.applicable is set but no positive esi.wageThresholdPaise was supplied.';
        }
        $esi['employeeRate']       = (float) $esi['employeeRate'];
        $esi['employerRate']       = (float) $esi['employerRate'];
        $esi['wageThresholdPaise'] = (int) $esi['wageThresholdPaise'];

        // ── PT ──
        $ptIn = is_array($in['pt'] ?? null) ? $in['pt'] : [];
        // State is NEVER inferred. Discovery found it present on only half the
        // tenants and inconsistently cased, so it is operator-set or empty.
        $ptState = trim((string) ($ptIn['state'] ?? ''));
        $slabsIn = is_array($ptIn['slabs'] ?? null) ? $ptIn['slabs'] : [];
        $slabs = [];
        foreach ($slabsIn as $i => $s) {
            if (!is_array($s)) { $e[] = "pt.slabs[{$i}] must be an object."; continue; }
            $f = $s['fromPaise'] ?? 0;
            $t = array_key_exists('toPaise', $s) && $s['toPaise'] !== null && $s['toPaise'] !== '' ? $s['toPaise'] : null;
            $a = $s['amountPaise'] ?? 0;

            if (!self::isIntPaise($f))            { $e[] = "pt.slabs[{$i}].fromPaise must be integer paise."; continue; }
            if ($t !== null && !self::isIntPaise($t)) { $e[] = "pt.slabs[{$i}].toPaise must be integer paise or null."; continue; }
            if (!self::isIntPaise($a))            { $e[] = "pt.slabs[{$i}].amountPaise must be integer paise."; continue; }

            $f = (int) $f; $a = (int) $a; $t = $t === null ? null : (int) $t;
            if ($f < 0 || $a < 0 || ($t !== null && $t < 0)) { $e[] = "pt.slabs[{$i}] values must not be negative."; continue; }
            if ($t !== null && $t < $f) { $e[] = "pt.slabs[{$i}].toPaise must not precede fromPaise."; continue; }

            $slabs[] = ['fromPaise' => $f, 'toPaise' => $t, 'amountPaise' => $a];
        }
        // Order by band start, then reject overlaps and gaps-with-overlap. An
        // ambiguous slab table would make PT depend on array order.
        usort($slabs, fn($a, $b) => $a['fromPaise'] <=> $b['fromPaise']);
        $openEnded = 0;
        for ($i = 0; $i < count($slabs); $i++) {
            if ($slabs[$i]['toPaise'] === null) {
                $openEnded++;
                if ($i !== count($slabs) - 1) {
                    $e[] = 'pt.slabs: only the highest band may be open-ended.';
                }
            }
            if ($i > 0) {
                $prev = $slabs[$i - 1];
                if ($prev['toPaise'] === null || $slabs[$i]['fromPaise'] <= $prev['toPaise']) {
                    $e[] = 'pt.slabs overlap — bands must not intersect.';
                    break;
                }
            }
        }
        if ($openEnded > 1) $e[] = 'pt.slabs: at most one open-ended band is allowed.';

        $pt = ['state' => $ptState, 'slabs' => $slabs];

        // ── TDS ──
        $tdsIn  = is_array($in['tds'] ?? null) ? $in['tds'] : [];
        $tdsMode = strtoupper(trim((string) ($tdsIn['mode'] ?? 'FLAT_PERCENT')));
        if (!in_array($tdsMode, self::TDS_MODES, true)) {
            $e[] = 'tds.mode must be one of: ' . implode(', ', self::TDS_MODES) . '.';
        }
        // SLAB is in the model so the schema needs no later migration, but a
        // real Indian TDS engine needs regime selection, investment
        // declarations, exemptions and annual projection — none of which exist
        // and none of which belong to W2.2. Refuse rather than ship a
        // convincing-looking stub that computes the wrong tax.
        if ($tdsMode === 'SLAB') {
            $e[] = 'tds.mode SLAB is modelled but not implemented — a slab TDS engine '
                 . '(regime, declarations, exemptions, annual projection) is out of W2.2 scope.';
        }
        $tdsRate = $tdsIn['rate'] ?? 0;
        if ($tdsMode === 'FLAT_PERCENT' && !self::isRate($tdsRate)) {
            $e[] = 'tds.rate must be a percentage between 0 and 100.';
        }
        $tds = ['mode' => $tdsMode, 'rate' => (float) $tdsRate];

        // ── Add-back threshold (CA-4) ──
        //
        // MOVED HERE 2026-08-23. It previously lived only in
        // `config/hrms_statutory_flags.php`, which made it a DEVELOPER setting:
        // tenant-wide, undated, and changeable only by editing PHP. All three
        // are wrong for this value.
        //
        // The first proviso to section 2(y) of the Code on Wages says the
        // excluded-payments limit is "one-half, OR SUCH OTHER PERCENTAGE AS MAY
        // BE NOTIFIED by the Central Government". A notified change must apply
        // from its own date and must NOT reprice earlier periods — which is
        // exactly what this effective-dated configuration already guarantees for
        // rates and ceilings. Putting the threshold anywhere else would make it
        // the one statutory value that silently rewrites history.
        //
        // Expressed as an exact fraction rather than a percentage float so the
        // arithmetic stays exact in integer paise.
        $abIn = is_array($in['addBack'] ?? null) ? $in['addBack'] : [];
        $abNum = $abIn['thresholdNum'] ?? 1;
        $abDen = $abIn['thresholdDen'] ?? 2;

        if (!self::isIntLike($abNum) || !self::isIntLike($abDen)) {
            $e[] = 'addBack.thresholdNum and addBack.thresholdDen must be whole numbers.';
            $abNum = 1; $abDen = 2;
        } else {
            $abNum = (int) $abNum;
            $abDen = (int) $abDen;
            if ($abDen <= 0) {
                $e[] = 'addBack.thresholdDen must be greater than zero.';
                $abNum = 1; $abDen = 2;
            } elseif ($abNum < 0 || $abNum > $abDen) {
                $e[] = 'The add-back threshold must be between 0 and 1 — for example 1/2 for one-half.';
                $abNum = 1; $abDen = 2;
            }
        }

        $addBack = [
            'thresholdNum' => $abNum,
            'thresholdDen' => $abDen,
            // Free text so a CA can record WHICH notification set it. Without
            // that, a future reader sees a number with no provenance.
            'source'       => trim((string) ($abIn['source'] ?? '')),
        ];

        // ── Gratuity (CA-7) ──
        //
        // Modelled here rather than in a calculator so the values are dated,
        // per-tenant and CA-verifiable like every other statutory figure. No
        // gratuity is computed anywhere yet; this captures the policy so it CAN
        // be, without a calculator having to hard-code a single statutory number.
        //
        // THE FLOOR IS ENFORCED, GENEROSITY IS NOT. The Payment of Gratuity Act
        // makes gratuity payable after FIVE years of continuous service. A school
        // may choose to pay EARLIER — many do — but may not require longer, so a
        // configured eligibility above the floor is refused rather than stored.
        //
        // The death/disablement waiver is NOT a configurable axis: s.4(1) waives
        // the qualifying period unconditionally in those cases, so making it
        // switchable would let a school configure something unlawful.
        $grIn = is_array($in['gratuity'] ?? null) ? $in['gratuity'] : [];

        // The statutory floor itself is a seeded default carrying its source, not
        // a constant invented in code.
        $grYears = $grIn['eligibilityYears'] ?? self::GRATUITY_STATUTORY_YEARS;
        if (!self::isIntLike($grYears) || (int) $grYears < 0) {
            $e[] = 'gratuity.eligibilityYears must be a whole number of years.';
            $grYears = self::GRATUITY_STATUTORY_YEARS;
        } else {
            $grYears = (int) $grYears;
            if ($grYears > self::GRATUITY_STATUTORY_YEARS) {
                $e[] = 'gratuity.eligibilityYears cannot exceed the statutory '
                    . self::GRATUITY_STATUTORY_YEARS . ' years. A school may pay earlier, never later.';
                $grYears = self::GRATUITY_STATUTORY_YEARS;
            }
        }

        $grCap = $grIn['maxPayablePaise'] ?? 0;
        if (!self::isIntPaise($grCap) || (int) $grCap < 0) {
            $e[] = 'gratuity.maxPayablePaise must be a non-negative whole number of paise.';
            $grCap = 0;
        }

        // THE CA-7 QUESTION. Service spanning 21 Nov 2025 crosses a change in the
        // statutory wage definition, and the treatment was still being settled
        // through 2026. It starts UNSET and a calculator must refuse rather than
        // pick one, because picking one would invent somebody's entitlement.
        $grTransition = strtolower(trim((string) ($grIn['transitionTreatment'] ?? 'unset')));
        if (!in_array($grTransition, self::GRATUITY_TRANSITIONS, true)) {
            $e[] = 'gratuity.transitionTreatment must be one of: '
                . implode(', ', self::GRATUITY_TRANSITIONS) . '.';
            $grTransition = 'unset';
        }

        $gratuity = [
            'eligibilityYears'     => $grYears,
            'maxPayablePaise'      => (int) $grCap,
            'transitionTreatment'  => $grTransition,
            'source'               => trim((string) ($grIn['source'] ?? '')),
            // Recorded, not offered. s.4(1) waives the qualifying period on death
            // or disablement unconditionally.
            'waivedOnDeathOrDisablement' => true,
        ];

        $clean = [
            'effectiveFrom' => $from,
            'effectiveTo'   => $to,
            'pf'            => $pf,
            'esi'           => $esi,
            'pt'            => $pt,
            'tds'           => $tds,
            'addBack'       => $addBack,
            'gratuity'      => $gratuity,
        ];
        return [$clean, $e];
    }

    private static function isRealDate(string $d): bool
    {
        [$y, $m, $day] = array_pad(explode('-', $d), 3, '0');
        return checkdate((int) $m, (int) $day, (int) $y);
    }

    // ─────────────────────────────────────────────────────────────────────
    //  Effective dating
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Detect overlapping windows across a set of configurations.
     *
     * Ambiguity here is not cosmetic: two configurations covering one date
     * would make a payroll run's statutory basis depend on read order, and a
     * past period could resolve differently on re-read. Pure, so it can be
     * exercised without Firestore.
     *
     * @return array<int,string> human-readable conflicts (empty = unambiguous)
     */
    /**
     * The add-back threshold options for a resolved configuration (CA-4).
     *
     * PRECEDENCE, and why: a TENANT configuration wins, because it is
     * effective-dated and therefore cannot reprice a closed period. The PHP
     * fallback exists only for a tenant that has not configured statutory
     * policy at all — every tenant today — and is deliberately the LAST
     * resort rather than the source of truth.
     *
     * Returns the shape `Statutory_wage_base` already accepts, so neither
     * side has to know where the number came from.
     */
    /**
     * The statutory qualifying period, in years.
     *
     * Payment of Gratuity Act 1972, s.4(1). Seeded as a named constant rather
     * than scattered as a literal so there is exactly one place to re-verify
     * it with a CA, and so the validator can enforce it as a CEILING on what
     * a tenant may require.
     *
     * TODO: verify with CA each financial year.
     */
    const GRATUITY_STATUTORY_YEARS = 5;

    /**
     * How service spanning the 21 Nov 2025 wage-definition change is treated.
     *
     *   unset               nobody has decided — a calculator must refuse
     *   single_base         one wage base for the whole period of service
     *   split_at_transition service before and after the change valued separately
     */
    const GRATUITY_TRANSITIONS = ['unset', 'single_base', 'split_at_transition'];

    public static function addBackOptions(?array $cfg, int $fallbackNum = 1, int $fallbackDen = 2): array
    {
        $ab = is_array($cfg['addBack'] ?? null) ? $cfg['addBack'] : [];

        $num = $ab['thresholdNum'] ?? null;
        $den = $ab['thresholdDen'] ?? null;

        if (self::isIntLike($num) && self::isIntLike($den)
            && (int) $den > 0 && (int) $num >= 0 && (int) $num <= (int) $den) {
            return [
                'thresholdNum' => (int) $num,
                'thresholdDen' => (int) $den,
                'source'       => 'tenant',
            ];
        }

        return [
            'thresholdNum' => $fallbackNum,
            'thresholdDen' => $fallbackDen,
            'source'       => 'fallback',
        ];
    }

    /** Whole-number check that refuses '2.5' and true alike. */
    private static function isIntLike($v): bool
    {
        return is_int($v) || (is_string($v) && preg_match('/^-?\d+$/', $v) === 1)
            || (is_float($v) && floor($v) === $v);
    }

    public static function findOverlaps(array $configs): array
    {
        // W2.3: delegated to helpers/effective_dating_helper.php so statutory
        // configuration and salary assignments cannot answer "do these windows
        // overlap?" differently. Two implementations would eventually disagree,
        // and the disagreement would surface as a period resolving to one
        // statutory config but a different salary assignment.
        self::loadEffectiveDating();
        return ed_find_overlaps($configs, 'configuration');
    }

    /** Load the shared effective-dating helper outside a CI request context too. */
    private static function loadEffectiveDating(): void
    {
        if (function_exists('ed_select_for')) return;
        require_once APPPATH . 'helpers/effective_dating_helper.php';
    }

    /**
     * Pick the configuration in force on $date.
     *
     * Inclusive of both bounds; a null `effectiveTo` is open-ended. When
     * several match (which validation should have prevented), the LATEST
     * `effectiveFrom` wins — deterministic rather than read-order dependent.
     */
    public static function selectFor(array $configs, string $date): ?array
    {
        self::loadEffectiveDating();
        return ed_select_for($configs, $date);
    }

    // ─────────────────────────────────────────────────────────────────────
    //  Resolution — pure, given a configuration
    // ─────────────────────────────────────────────────────────────────────

    /**
     * PF contribution for a wage.
     *
     * `wagePaise` is the PF base — basic, per the W2.1 component metadata
     * (`PF_EMPLOYEE.basis = BASIC`). This function does not decide the base;
     * the component master does.
     *
     * Two independent switches, deliberately not conflated:
     *   • applyCeiling         — cap the base at the ceiling
     *   • eligibleAboveCeiling — whether someone earning ABOVE the ceiling is
     *                            covered at all. False models the "excluded
     *                            employee" case: no contribution, not a capped
     *                            one. Conflating the two is the classic PF bug.
     *
     * @return array{employeePaise:int,employerPaise:int,basePaise:int,covered:bool,reason:string}
     */
    public static function computePf(array $cfg, int $wagePaise): array
    {
        $pf = is_array($cfg['pf'] ?? null) ? $cfg['pf'] : [];
        $ceiling = (int) ($pf['wageCeilingPaise'] ?? 0);
        $apply   = !empty($pf['applyCeiling']);
        $eligibleAbove = !empty($pf['eligibleAboveCeiling']);

        if ($wagePaise <= 0) {
            return self::pfResult(0, 0, 0, false, 'zero wage');
        }
        if ($ceiling > 0 && $wagePaise > $ceiling && !$eligibleAbove) {
            return self::pfResult(0, 0, 0, false, 'wage above ceiling and not eligible above ceiling');
        }

        $base = ($apply && $ceiling > 0) ? min($wagePaise, $ceiling) : $wagePaise;

        return self::pfResult(
            self::applyRatio($base, self::pctToRatio((float) ($pf['employeeRate'] ?? 0))),
            self::applyRatio($base, self::pctToRatio((float) ($pf['employerRate'] ?? 0))),
            $base,
            true,
            $apply && $ceiling > 0 && $wagePaise > $ceiling ? 'capped at ceiling' : 'full wage'
        );
    }

    private static function pfResult(int $ee, int $er, int $base, bool $covered, string $reason): array
    {
        return ['employeePaise' => $ee, 'employerPaise' => $er,
                'basePaise' => $base, 'covered' => $covered, 'reason' => $reason];
    }

    /**
     * ESI contribution for a wage.
     *
     * ESI is a coverage test, not a cap: earn at or below the threshold and you
     * are covered on the FULL wage; earn above it and you are not covered at
     * all. Capping at the threshold — the intuitive-but-wrong reading, and the
     * mirror image of PF's ceiling — would silently under-deduct.
     *
     * @return array{employeePaise:int,employerPaise:int,basePaise:int,covered:bool,reason:string}
     */
    /**
     * Is ESI applicable at a given WORK LOCATION?
     *
     * FEATURE 2 (2026-08-22). ESI applicability is not a property of an
     * employer — it is a property of a PLACE. The scheme applies in areas the
     * appropriate government has notified as implemented, so a school with two
     * campuses can legitimately have one covered and the other not.
     *
     * ZenX modelled it as a single tenant-wide `esi.applicable` boolean, which
     * cannot express that. A multi-campus school had to choose between
     * over-deducting at the uncovered campus or under-deducting at the covered
     * one, and neither is lawful.
     *
     * WHAT IS LOCAL AND WHAT IS NATIONAL
     * ----------------------------------
     * Deliberately split, because they are different kinds of fact:
     *
     *   applicability   LOCAL   — depends on whether the area is implemented
     *   rates           NATIONAL — the same wherever the scheme applies
     *   wage threshold  NATIONAL — likewise
     *
     * So only applicability moves to the location. Copying rates onto every
     * location would invite them to drift apart with no legal basis for the
     * divergence, and would multiply the number of places a CA has to verify.
     *
     * PRECEDENCE
     * ----------
     *   1. An explicit determination on the LOCATION (`esiApplicable` true or
     *      false) wins — it is the more specific statement.
     *   2. Otherwise the tenant-wide setting stands.
     *
     * A location that has said nothing (`null`, or the field absent) INHERITS
     * rather than defaulting to false. Defaulting an unconfigured location to
     * "not applicable" would silently stop deducting ESI for everyone posted
     * there the moment locations were introduced — a change nobody asked for,
     * arriving as a side effect of a data model.
     *
     * @param array      $cfg         the effective statutory configuration
     * @param array|null $locationDoc the employee's work location, or null
     * @return array{applicable:bool, source:string, reason:string}
     */
    public static function resolveEsiApplicability(array $cfg, ?array $locationDoc = null): array
    {
        $tenantWide = !empty($cfg['esi']['applicable']);

        if (is_array($locationDoc) && array_key_exists('esiApplicable', $locationDoc)
            && $locationDoc['esiApplicable'] !== null && $locationDoc['esiApplicable'] !== '') {

            $local = (bool) $locationDoc['esiApplicable'];
            return [
                'applicable' => $local,
                'source'     => 'location',
                'reason'     => 'location ' . (string) ($locationDoc['name'] ?? ($locationDoc['locationId'] ?? '?'))
                    . ' is recorded as ' . ($local ? 'inside' : 'outside')
                    . ' an implemented area',
            ];
        }

        return [
            'applicable' => $tenantWide,
            'source'     => 'tenant',
            'reason'     => $locationDoc === null
                ? 'no work location on the employee; tenant-wide ESI setting applies'
                : 'location has no ESI determination; tenant-wide setting applies',
        ];
    }
    public static function computeEsi(array $cfg, int $wagePaise, ?bool $applicableOverride = null): array
    {
        $esi = is_array($cfg['esi'] ?? null) ? $cfg['esi'] : [];

        // Feature 2: applicability may be decided at the WORK LOCATION.
        // Null means "nobody overrode it", which is not the same as false.
        $applicable = $applicableOverride === null
            ? !empty($esi['applicable'])
            : $applicableOverride;

        if (!$applicable) {
            return self::pfResult(0, 0, 0, false, $applicableOverride === null
                ? 'ESI not applicable for this tenant'
                : 'ESI not applicable at this work location');
        }
        if ($wagePaise <= 0) {
            return self::pfResult(0, 0, 0, false, 'zero wage');
        }
        $threshold = (int) ($esi['wageThresholdPaise'] ?? 0);
        if ($threshold > 0 && $wagePaise > $threshold) {
            return self::pfResult(0, 0, 0, false, 'wage above ESI threshold — not covered');
        }
        return self::pfResult(
            self::applyRatio($wagePaise, self::pctToRatio((float) ($esi['employeeRate'] ?? 0))),
            self::applyRatio($wagePaise, self::pctToRatio((float) ($esi['employerRate'] ?? 0))),
            $wagePaise,
            true,
            'covered on full wage'
        );
    }

    /**
     * Professional tax for a wage — a flat amount per band, not a rate.
     *
     * Returns 0 when no slab matches, which is a real answer (a wage below the
     * lowest band pays nothing), not an error. `null` slab top = open-ended.
     *
     * @return array{amountPaise:int,slab:?array,matched:bool}
     */
    public static function computePt(array $cfg, int $wagePaise): array
    {
        $pt = is_array($cfg['pt'] ?? null) ? $cfg['pt'] : [];
        $slabs = is_array($pt['slabs'] ?? null) ? $pt['slabs'] : [];
        if ($wagePaise <= 0 || empty($slabs)) {
            return ['amountPaise' => 0, 'slab' => null, 'matched' => false];
        }
        foreach ($slabs as $s) {
            $from = (int) ($s['fromPaise'] ?? 0);
            $to   = array_key_exists('toPaise', $s) && $s['toPaise'] !== null ? (int) $s['toPaise'] : null;
            if ($wagePaise >= $from && ($to === null || $wagePaise <= $to)) {
                return ['amountPaise' => (int) ($s['amountPaise'] ?? 0), 'slab' => $s, 'matched' => true];
            }
        }
        return ['amountPaise' => 0, 'slab' => null, 'matched' => false];
    }

    /**
     * TDS for a wage — FLAT_PERCENT only.
     *
     * This reproduces what ZenX does today (a flat percent of gross) under an
     * explicit configuration instead of an operator-typed field. It is NOT an
     * Indian TDS engine, and `supported=false` is returned for any other mode
     * rather than guessing.
     *
     * @return array{amountPaise:int,mode:string,supported:bool,note:string}
     */
    public static function computeTds(array $cfg, int $wagePaise): array
    {
        $tds  = is_array($cfg['tds'] ?? null) ? $cfg['tds'] : [];
        $mode = strtoupper((string) ($tds['mode'] ?? 'FLAT_PERCENT'));

        if ($mode !== 'FLAT_PERCENT') {
            return ['amountPaise' => 0, 'mode' => $mode, 'supported' => false,
                    'note' => 'Only FLAT_PERCENT is implemented in W2.2; a slab/regime engine is a later phase.'];
        }
        if ($wagePaise <= 0) {
            return ['amountPaise' => 0, 'mode' => $mode, 'supported' => true, 'note' => 'zero wage'];
        }
        return [
            'amountPaise' => self::applyRatio($wagePaise, self::pctToRatio((float) ($tds['rate'] ?? 0))),
            'mode' => $mode, 'supported' => true,
            'note' => 'flat percent of the supplied base (parity with current ZenX behaviour)',
        ];
    }

    // ─────────────────────────────────────────────────────────────────────
    //  Persistence (tenant-scoped)
    // ─────────────────────────────────────────────────────────────────────

    /** All configurations for this tenant, oldest first. */
    public function listAll(): array
    {
        try {
            $rows = $this->fs->schoolWhere(self::COLLECTION, []);
        } catch (\Exception $ex) {
            log_message('error', 'Statutory_config_service::listAll failed — ' . $ex->getMessage());
            return [];
        }
        $out = [];
        foreach ($rows as $r) {
            $d = $r['data'] ?? null;
            if (is_array($d)) $out[] = $d;
        }
        usort($out, fn($a, $b) => strcmp((string) ($a['effectiveFrom'] ?? ''), (string) ($b['effectiveFrom'] ?? '')));
        return $out;
    }

    /** The configuration in force on $date for this tenant, or null. */
    public function resolve(string $date): ?array
    {
        if (!preg_match(self::DATE_PATTERN, $date)) return null;
        return self::selectFor($this->listAll(), $date);
    }

    /**
     * Create or replace the configuration starting on `effectiveFrom`.
     *
     * Refuses to write a configuration that would overlap an existing window —
     * an ambiguous statutory history is worse than a missing one.
     *
     * @return array{0:bool,1:array}
     */
    public function save(array $in): array
    {
        [$clean, $errors] = $this->validate($in);
        if (!empty($errors)) return [false, $errors];

        $existing = $this->listAll();
        $others = array_values(array_filter(
            $existing,
            fn($c) => (string) ($c['effectiveFrom'] ?? '') !== $clean['effectiveFrom']
        ));
        $conflicts = self::findOverlaps(array_merge($others, [$clean]));
        if (!empty($conflicts)) return [false, $conflicts];

        $now = date('c');
        $prior = null;
        foreach ($existing as $c) {
            if ((string) ($c['effectiveFrom'] ?? '') === $clean['effectiveFrom']) { $prior = $c; break; }
        }
        $clean['createdAt'] = $prior['createdAt'] ?? $now;
        $clean['createdBy'] = $prior['createdBy'] ?? $this->actor;
        $clean['updatedAt'] = $now;
        $clean['updatedBy'] = $this->actor;

        try {
            $this->fs->setEntity(self::COLLECTION, $clean['effectiveFrom'], $clean);
        } catch (\Exception $ex) {
            log_message('error', 'Statutory_config_service::save failed — ' . $ex->getMessage());
            return [false, ['Could not save the statutory configuration. Please try again.']];
        }
        return [true, []];
    }
}
