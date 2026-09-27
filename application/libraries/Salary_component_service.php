<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Salary_component_service — the salary COMPONENT MASTER (ZenX HRMS W2.1).
 *
 * WHAT THIS IS
 * ------------
 * The tenant-scoped catalogue of pay components. Until now a "salary structure"
 * was thirteen hardcoded numeric columns on one document, so adding a component
 * meant editing four PHP sites. This turns components into DATA.
 *
 * WHAT THIS IS NOT (W2.1 boundary)
 * --------------------------------
 * Nothing reads this catalogue yet. `Hr::_compute_payslip()`, `generate_payroll()`
 * and the legacy `salarySlips` structure documents are all untouched, and there is
 * no reader, no flag and no migration. This gate is a pure addition: after it
 * lands, payroll behaves exactly as it did before. The reader
 * (W2.4) and the migration (W2.3) are separate, separately-reviewed gates.
 *
 * WHY THE SEED MATTERS MORE THAN THE SCHEMA
 * -----------------------------------------
 * The thirteen legacy fields carry semantics that exist ONLY inside the
 * calculator, never in the stored record:
 *
 *   • `pf_employee` / `esi_employee` / `tds` are PERCENTAGES;
 *     `professional_tax` / `other_deductions` are MONEY.
 *     Nothing in the document says which — the calculator knows by position.
 *   • PF is charged on the PRORATED basic, ESI and TDS on gross.
 *   • Earnings are scaled by the attendance retention ratio; professional tax
 *     and other deductions are NOT — they are flat whatever the attendance.
 *
 * Those facts were read out of `Hr::_compute_payslip()` line by line, not
 * inferred from field names, and the seed below encodes them explicitly so that
 * W2.2 (statutory engine) and W2.4 (reader) can rely on declared metadata
 * instead of re-deriving them. Getting this seed wrong would silently
 * mis-describe live payroll, so each entry carries the calculator's own rule.
 *
 * MONEY: amounts are integer paise (`amountPaise`), per the W1.2 contract.
 * A RATE IS NOT MONEY — `rate` stays a decimal percentage and is never
 * converted to paise. No rounding happens in this file at all.
 */
class Salary_component_service
{
    /** @var object Firestore_service, already school-scoped. */
    private $fs;
    /** @var string */
    private $actor = '';

    const COLLECTION = 'salaryComponents';

    const KINDS      = ['earning', 'deduction', 'employer_contribution'];

    /**
     * BALANCING (W2.3): the component that absorbs whatever is left of gross
     * after every other component resolves. It is how real CTC templates are
     * built — an operator types a Monthly Gross and one "special allowance"
     * line takes the remainder so the parts always sum exactly to the whole.
     * Without it, a structure either fails to reach gross or overshoots it, and
     * someone reconciles by hand.
     *
     * It carries NEITHER a rate NOR an amount: its value is derived at
     * resolution time, so storing one would be a second source of truth.
     * Added at schema level now because introducing a calcType later forces a
     * migration of every stored component and assignment line.
     */
    const CALC_TYPES = ['FIXED', 'PERCENT', 'FORMULA', 'BALANCING'];
    const BASES      = ['BASIC', 'GROSS', 'CTC', 'COMPONENT_SET', 'NONE'];
    const STATUTORY  = ['PF', 'ESI', 'PT', 'TDS'];

    /** Component codes are the document id suffix, so they must be path-safe. */
    const CODE_PATTERN = '/^[A-Z][A-Z0-9_]{1,39}$/';

    const MAX_NAME_LEN = 80;

    /**
     * Structural fields a systemLocked component may never change.
     *
     * The thirteen seeded components are DESCRIPTIONS of how the shipping
     * calculator already behaves, not policy knobs. Letting an operator flip
     * `pf_employee` from PERCENT to FIXED would make the catalogue lie about
     * live payroll. Name and displayOrder stay editable because they are
     * presentation only.
     */
    const LOCKED_FIELDS = [
        'code', 'kind', 'calcType', 'basis', 'basisComponents',
        'rate', 'amountPaise', 'taxable', 'pfApplicable', 'esiApplicable',
        'prorated', 'statutory', 'systemLocked',
    ];

    public function init($fs, string $actor = ''): void
    {
        $this->fs    = $fs;
        $this->actor = $actor;
    }

    // ─────────────────────────────────────────────────────────────────────
    //  Validation
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Validate an inbound component payload.
     *
     * @param array $in           raw input
     * @param bool  $isCreate     stricter on create (code immutable afterwards)
     * @param array $knownCodes   codes that already exist in this tenant, for
     *                            COMPONENT_SET basis resolution
     * @return array{0:array,1:array} [clean, errors]
     */
    public function validate(array $in, bool $isCreate, array $knownCodes = []): array
    {
        $e = [];

        // ── code ──
        $code = strtoupper(trim((string) ($in['code'] ?? '')));
        if ($code === '') {
            $e[] = 'Component code is required.';
        } elseif (!preg_match(self::CODE_PATTERN, $code)) {
            $e[] = 'Component code must be 2-40 chars, start with a letter, and use only A-Z, 0-9 and underscore.';
        }

        // ── name ──
        $name = trim((string) ($in['name'] ?? ''));
        if ($name === '') {
            $e[] = 'Component name is required.';
        } elseif (mb_strlen($name) > self::MAX_NAME_LEN) {
            $e[] = 'Component name exceeds ' . self::MAX_NAME_LEN . ' characters.';
        }

        // ── enums ──
        $kind = (string) ($in['kind'] ?? '');
        if (!in_array($kind, self::KINDS, true)) {
            $e[] = 'kind must be one of: ' . implode(', ', self::KINDS) . '.';
        }

        $calcType = strtoupper((string) ($in['calcType'] ?? ''));
        if (!in_array($calcType, self::CALC_TYPES, true)) {
            $e[] = 'calcType must be one of: ' . implode(', ', self::CALC_TYPES) . '.';
        }

        $basis = strtoupper((string) ($in['basis'] ?? 'NONE'));
        if (!in_array($basis, self::BASES, true)) {
            $e[] = 'basis must be one of: ' . implode(', ', self::BASES) . '.';
        }

        // FORMULA is part of the model so the schema does not need migrating
        // later, but no evaluator exists until W2.2. Storing one now would
        // create a component nothing can compute — reject rather than accept
        // data we cannot honour.
        if ($calcType === 'FORMULA') {
            $e[] = 'calcType FORMULA is modelled but has no evaluator yet — configuring one would '
                 . 'create a component nothing can compute.';
        }

        // ── the money/rate split: the whole point of the catalogue ──
        $rate        = array_key_exists('rate', $in) && $in['rate'] !== '' && $in['rate'] !== null ? $in['rate'] : null;
        $amountPaise = array_key_exists('amountPaise', $in) && $in['amountPaise'] !== '' && $in['amountPaise'] !== null ? $in['amountPaise'] : null;

        if ($calcType === 'PERCENT') {
            if ($rate === null) {
                $e[] = 'A PERCENT component requires `rate`.';
            } elseif (!is_numeric($rate) || !is_finite((float) $rate)) {
                $e[] = 'rate must be numeric.';
            } elseif ((float) $rate < 0 || (float) $rate > 100) {
                $e[] = 'rate must be between 0 and 100.';
            }
            if ($amountPaise !== null) {
                $e[] = 'A PERCENT component must not carry `amountPaise` — a rate is not money.';
            }
            if ($basis === 'NONE') {
                $e[] = 'A PERCENT component needs a basis to apply the rate to.';
            }
        } elseif ($calcType === 'FIXED') {
            if ($amountPaise === null) {
                $e[] = 'A FIXED component requires `amountPaise`.';
            } elseif (!is_int($amountPaise) && !ctype_digit((string) $amountPaise)) {
                // Integer paise only — a decimal here means someone passed rupees.
                $e[] = 'amountPaise must be an integer number of paise (not rupees, not a decimal).';
            } elseif ((int) $amountPaise < 0) {
                $e[] = 'amountPaise must not be negative.';
            }
            if ($rate !== null) {
                $e[] = 'A FIXED component must not carry `rate`.';
            }
        } elseif ($calcType === 'BALANCING') {
            // Its value is whatever is left of gross, derived at resolution
            // time. Storing a rate or an amount would create a second source of
            // truth that silently disagrees with the derived figure.
            if ($rate !== null)        $e[] = 'A BALANCING component must not carry `rate` — its value is derived from the remainder of gross.';
            if ($amountPaise !== null) $e[] = 'A BALANCING component must not carry `amountPaise` — its value is derived from the remainder of gross.';
            if ($kind !== 'earning')   $e[] = 'Only an earning can be BALANCING — the remainder of gross is pay, not a deduction.';
        }

        // ── basisComponents ──
        $basisComponents = $in['basisComponents'] ?? [];
        if (!is_array($basisComponents)) $basisComponents = [];
        $basisComponents = array_values(array_filter(array_map(
            fn($c) => strtoupper(trim((string) $c)),
            $basisComponents
        ), fn($c) => $c !== ''));

        if ($basis === 'COMPONENT_SET') {
            if (empty($basisComponents)) {
                $e[] = 'basis COMPONENT_SET requires at least one entry in basisComponents.';
            } elseif (!empty($knownCodes)) {
                foreach ($basisComponents as $bc) {
                    if (!in_array($bc, $knownCodes, true)) {
                        $e[] = "basisComponents references unknown component code '{$bc}'.";
                    }
                }
            }
            if (in_array($code, $basisComponents, true)) {
                $e[] = 'A component cannot reference itself in basisComponents.';
            }
        } elseif (!empty($basisComponents)) {
            $e[] = 'basisComponents is only valid when basis is COMPONENT_SET.';
        }

        // ── statutory ──
        $statutory = $in['statutory'] ?? null;
        if ($statutory === '' || $statutory === 'null') $statutory = null;
        if ($statutory !== null) {
            $statutory = strtoupper((string) $statutory);
            if (!in_array($statutory, self::STATUTORY, true)) {
                $e[] = 'statutory must be null or one of: ' . implode(', ', self::STATUTORY) . '.';
            }
        }

        // ── displayOrder ──
        $displayOrder = $in['displayOrder'] ?? 0;
        if (!is_int($displayOrder) && !ctype_digit((string) $displayOrder)) {
            $e[] = 'displayOrder must be a non-negative integer.';
            $displayOrder = 0;
        }
        $displayOrder = (int) $displayOrder;
        if ($displayOrder < 0) {
            $e[] = 'displayOrder must be a non-negative integer.';
        }

        // ── Statutory wage classification ──
        require_once __DIR__ . '/Statutory_wage_base.php';

        $wageClass = null;
        if (isset($in['wageClassification']) && trim((string) $in['wageClassification']) !== '') {
            $wageClass = strtoupper(trim((string) $in['wageClassification']));
            if (!in_array($wageClass, Statutory_wage_base::CLASSIFICATIONS, true)) {
                $e[] = 'wageClassification must be one of: '
                    . implode(', ', Statutory_wage_base::CLASSIFICATIONS) . '.';
                $wageClass = null;
            }
        }

        $wageReason     = trim((string) ($in['wageClassificationReason'] ?? ''));
        $wageVerifiedBy = trim((string) ($in['wageClassificationVerifiedBy'] ?? ''));
        $wageVerifiedAt = trim((string) ($in['wageClassificationVerifiedAt'] ?? ''));

        // A tenant override departs from the statutory default, so it must
        // say WHY. An unexplained reclassification is indistinguishable from
        // a mistake when somebody reviews it a year later.
        if ($wageClass !== null) {
            $statutoryDefault = Statutory_wage_base::DEFAULT_CLASSIFICATION[$code] ?? null;
            if ($statutoryDefault !== null && $wageClass !== $statutoryDefault && $wageReason === '') {
                $e[] = 'Overriding the statutory classification for ' . $code
                    . ' requires a reason — it departs from what the Code on Wages implies.';
            }
        }

        $wageSchedule = [];
        if (!empty($in['wageClassificationSchedule']) && is_array($in['wageClassificationSchedule'])) {
            require_once __DIR__ . '/../helpers/effective_dating_helper.php';

            foreach ($in['wageClassificationSchedule'] as $i => $row) {
                if (!is_array($row)) continue;
                $n = $i + 1;

                $c = strtoupper(trim((string) ($row['classification'] ?? '')));
                if (!in_array($c, Statutory_wage_base::CLASSIFICATIONS, true)) {
                    $e[] = "Schedule row {$n}: classification must be one of: "
                        . implode(', ', Statutory_wage_base::CLASSIFICATIONS) . '.';
                    continue;
                }

                $from = trim((string) ($row['effectiveFrom'] ?? ''));
                if (!ed_is_iso_date($from)) {
                    $e[] = "Schedule row {$n}: effectiveFrom must be an ISO date (YYYY-MM-DD).";
                    continue;
                }

                $to = $row['effectiveTo'] ?? null;
                $to = ($to === null || trim((string) $to) === '') ? null : trim((string) $to);
                if ($to !== null && !ed_is_iso_date($to)) {
                    $e[] = "Schedule row {$n}: effectiveTo must be an ISO date or empty.";
                    continue;
                }
                if ($to !== null && $to < $from) {
                    $e[] = "Schedule row {$n}: effectiveTo is before effectiveFrom.";
                    continue;
                }

                $wageSchedule[] = [
                    'classification' => $c,
                    'effectiveFrom'  => $from,
                    'effectiveTo'    => $to,
                    'reason'         => trim((string) ($row['reason'] ?? '')),
                    'verifiedBy'     => trim((string) ($row['verifiedBy'] ?? '')),
                    'verifiedAt'     => trim((string) ($row['verifiedAt'] ?? '')),
                ];
            }

            // Two rows covering one date make a payroll run's basis depend on
            // read order, and a CLOSED period could resolve differently on
            // re-read — destroying the reproducibility that dating exists for.
            $overlaps = ed_find_overlaps($wageSchedule, 'classification');
            foreach ($overlaps as $o) $e[] = 'Classification schedule: ' . $o;
        }

        $clean = [
            'code'            => $code,
            'name'            => $name,
            'kind'            => $kind,
            'calcType'        => $calcType,
            'basis'           => $basis,
            'basisComponents' => $basisComponents,
            'rate'            => $calcType === 'PERCENT' && $rate !== null ? (float) $rate : null,
            'amountPaise'     => $calcType === 'FIXED' && $amountPaise !== null ? (int) $amountPaise : null,
            'taxable'         => !empty($in['taxable']),
            'pfApplicable'    => !empty($in['pfApplicable']),
            'esiApplicable'   => !empty($in['esiApplicable']),
            'prorated'        => !empty($in['prorated']),

            // ── Statutory wage classification (Feature 1) ──
            //
            // DELIBERATELY NOT in LOCKED_FIELDS. `pfApplicable` is locked
            // because it describes what the shipped engine does and must not
            // drift. A wage classification is the opposite kind of fact: it
            // records a CA's determination under the universality test, and
            // that determination can legitimately change — an allowance can
            // become universal, or stop being so.
            //
            // Which is why a change is DATED rather than overwritten: see
            // `wageClassificationSchedule`. Overwriting would silently
            // reprice every past period the moment somebody corrected a
            // classification.
            'wageClassification'           => $wageClass,
            'wageClassificationReason'     => $wageReason,
            'wageClassificationVerifiedBy' => $wageVerifiedBy,
            'wageClassificationVerifiedAt' => $wageVerifiedAt,
            'wageClassificationSchedule'   => $wageSchedule,
            'statutory'       => $statutory,
            'displayOrder'    => $displayOrder,
            'active'          => array_key_exists('active', $in) ? !empty($in['active']) : true,
            // NEVER settable through the API — only the seed sets this.
            'systemLocked'    => false,
        ];

        return [$clean, $e];
    }

    // ─────────────────────────────────────────────────────────────────────
    //  Read
    // ─────────────────────────────────────────────────────────────────────

    /** List this tenant's catalogue. `schoolWhere()` injects the tenant predicate. */
    public function listAll(bool $activeOnly = false): array
    {
        try {
            $rows = $this->fs->schoolWhere(self::COLLECTION, []);
        } catch (\Exception $ex) {
            log_message('error', 'Salary_component_service::listAll failed — ' . $ex->getMessage());
            return [];
        }

        $out = [];
        foreach ($rows as $r) {
            $d = $r['data'] ?? null;
            if (!is_array($d)) continue;
            if ($activeOnly && empty($d['active'])) continue;
            $out[] = $d;
        }

        usort($out, function ($a, $b) {
            $d = ((int) ($a['displayOrder'] ?? 0)) <=> ((int) ($b['displayOrder'] ?? 0));
            return $d !== 0 ? $d : strcmp((string) ($a['code'] ?? ''), (string) ($b['code'] ?? ''));
        });
        return $out;
    }

    public function get(string $code): ?array
    {
        $code = strtoupper(trim($code));
        if (!preg_match(self::CODE_PATTERN, $code)) return null;
        try {
            $d = $this->fs->getEntity(self::COLLECTION, $code);
        } catch (\Exception $ex) {
            log_message('error', "Salary_component_service::get({$code}) failed — " . $ex->getMessage());
            return null;
        }
        return (is_array($d) && !empty($d)) ? $d : null;
    }

    /** Existing codes in this tenant — used for COMPONENT_SET validation. */
    public function knownCodes(): array
    {
        return array_values(array_filter(array_map(
            fn($c) => (string) ($c['code'] ?? ''),
            $this->listAll()
        )));
    }

    // ─────────────────────────────────────────────────────────────────────
    //  Write
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Create a component. The code IS the document-id suffix
     * (`salaryComponents/{schoolId}_{CODE}`), which makes duplicate codes
     * structurally impossible per tenant — but we still check explicitly so a
     * duplicate reports as a clear error rather than silently overwriting.
     *
     * @return array{0:bool,1:array} [ok, errors]
     */
    public function create(array $in): array
    {
        [$clean, $errors] = $this->validate($in, true, $this->knownCodes());
        if (!empty($errors)) return [false, $errors];

        if ($this->get($clean['code']) !== null) {
            return [false, ["A component with code '{$clean['code']}' already exists."]];
        }

        $now = date('c');
        $clean['createdAt'] = $now;
        $clean['createdBy'] = $this->actor;
        $clean['updatedAt'] = $now;
        $clean['updatedBy'] = $this->actor;

        try {
            $this->fs->setEntity(self::COLLECTION, $clean['code'], $clean);
        } catch (\Exception $ex) {
            log_message('error', "Salary_component_service::create({$clean['code']}) failed — " . $ex->getMessage());
            return [false, ['Could not save the component. Please try again.']];
        }
        return [true, []];
    }

    /**
     * Update a component.
     *
     * A systemLocked component accepts only `name` and `displayOrder`; any
     * attempt to alter its structure is refused, naming the offending field.
     */
    public function update(string $code, array $in): array
    {
        $existing = $this->get($code);
        if ($existing === null) return [false, ['Component not found.']];

        $locked = !empty($existing['systemLocked']);

        if ($locked) {
            $attempted = [];
            foreach (self::LOCKED_FIELDS as $f) {
                if (!array_key_exists($f, $in)) continue;
                $new = $in[$f];
                $old = $existing[$f] ?? null;
                if ($f === 'basisComponents') {
                    $new = is_array($new) ? array_map('strtoupper', $new) : [];
                    $old = is_array($old) ? $old : [];
                    if ($new != $old) $attempted[] = $f;
                    continue;
                }
                // Loose compare: form posts arrive as strings.
                if ((string) $new !== (string) $old) $attempted[] = $f;
            }
            if (!empty($attempted)) {
                return [false, [
                    'This is a system component describing existing payroll behaviour; '
                    . 'its structure cannot be changed (attempted: ' . implode(', ', $attempted) . ').',
                ]];
            }

            $patch = [];
            if (array_key_exists('name', $in)) {
                $name = trim((string) $in['name']);
                if ($name === '') return [false, ['Component name is required.']];
                if (mb_strlen($name) > self::MAX_NAME_LEN) return [false, ['Component name is too long.']];
                $patch['name'] = $name;
            }
            if (array_key_exists('displayOrder', $in)) {
                if (!is_int($in['displayOrder']) && !ctype_digit((string) $in['displayOrder'])) {
                    return [false, ['displayOrder must be a non-negative integer.']];
                }
                $patch['displayOrder'] = (int) $in['displayOrder'];
            }
            if (empty($patch)) return [false, ['Nothing to update.']];

            $patch['updatedAt'] = date('c');
            $patch['updatedBy'] = $this->actor;
            try {
                $this->fs->updateEntity(self::COLLECTION, $existing['code'], $patch);
            } catch (\Exception $ex) {
                log_message('error', "Salary_component_service::update({$code}) failed — " . $ex->getMessage());
                return [false, ['Could not update the component. Please try again.']];
            }
            return [true, []];
        }

        // Non-system component: full validation, but the code is immutable.
        $in['code'] = $existing['code'];
        [$clean, $errors] = $this->validate($in, false, $this->knownCodes());
        if (!empty($errors)) return [false, $errors];

        $clean['createdAt']    = $existing['createdAt'] ?? date('c');
        $clean['createdBy']    = $existing['createdBy'] ?? '';
        $clean['systemLocked'] = false;
        $clean['updatedAt']    = date('c');
        $clean['updatedBy']    = $this->actor;

        try {
            $this->fs->setEntity(self::COLLECTION, $clean['code'], $clean);
        } catch (\Exception $ex) {
            log_message('error', "Salary_component_service::update({$code}) failed — " . $ex->getMessage());
            return [false, ['Could not update the component. Please try again.']];
        }
        return [true, []];
    }

    /**
     * Deactivate a component. There is no delete: once W2.3 assignments
     * reference a component, deleting it would dangle those references, and a
     * catalogue entry is cheap. System components cannot be deactivated —
     * they describe behaviour the calculator performs unconditionally.
     */
    public function setActive(string $code, bool $active): array
    {
        $existing = $this->get($code);
        if ($existing === null) return [false, ['Component not found.']];

        if (!empty($existing['systemLocked']) && !$active) {
            return [false, ['System components cannot be deactivated — they describe live payroll behaviour.']];
        }

        try {
            $this->fs->updateEntity(self::COLLECTION, $existing['code'], [
                'active'    => $active,
                'updatedAt' => date('c'),
                'updatedBy' => $this->actor,
            ]);
        } catch (\Exception $ex) {
            log_message('error', "Salary_component_service::setActive({$code}) failed — " . $ex->getMessage());
            return [false, ['Could not update the component. Please try again.']];
        }
        return [true, []];
    }

    // ─────────────────────────────────────────────────────────────────────
    //  System seed
    // ─────────────────────────────────────────────────────────────────────

    /**
     * The thirteen system components, mirroring `Hr::_compute_payslip()` exactly.
     *
     * Every non-obvious value below is the calculator's actual rule, read from
     * its source rather than guessed from the field name:
     *
     *   • Earnings are FIXED amounts scaled by the attendance retention ratio
     *     (`_applyRatio($amountP, $retention)`) ⇒ prorated = true.
     *   • PF (employee + employer) is a PERCENT of the **prorated basic**
     *     (`_applyRatio($effectiveBasicP, $pct/100)`) ⇒ basis = BASIC.
     *   • ESI (both) and TDS are a PERCENT of **gross**
     *     (`_applyRatio($grossP, $pct/100)`) ⇒ basis = GROSS.
     *   • Professional tax and other deductions are FIXED money taken at face
     *     value (`_toPaise(...)`, no retention applied) ⇒ prorated = FALSE.
     *     This is the asymmetry most likely to be got wrong.
     *   • `pfApplicable` is true only for BASIC, because PF's base is basic.
     *     `esiApplicable` is true for every earning, because ESI's base is gross.
     *   • `taxable` is true for every earning because TDS is a flat percent of
     *     gross today. That is a statement about the CURRENT engine, not about
     *     Indian tax law; per-component taxability arrives with W2.2.
     *
     * Seeded amounts/rates are ZERO. The catalogue defines what a component IS;
     * per-employee values continue to live on the legacy structure until W2.3.
     */
    public static function systemComponents(): array
    {
        $earning = function (string $code, string $name, int $order, bool $pfApplicable) {
            return [
                'code' => $code, 'name' => $name, 'kind' => 'earning',
                'calcType' => 'FIXED', 'basis' => 'NONE', 'basisComponents' => [],
                'rate' => null, 'amountPaise' => 0,
                'taxable' => true, 'pfApplicable' => $pfApplicable, 'esiApplicable' => true,
                'prorated' => true, 'statutory' => null,
                'displayOrder' => $order, 'active' => true, 'systemLocked' => true,
            ];
        };

        $pct = function (string $code, string $name, string $kind, string $basis, ?string $statutory, int $order) {
            return [
                'code' => $code, 'name' => $name, 'kind' => $kind,
                'calcType' => 'PERCENT', 'basis' => $basis, 'basisComponents' => [],
                'rate' => 0.0, 'amountPaise' => null,
                'taxable' => false, 'pfApplicable' => false, 'esiApplicable' => false,
                // The rate applies to an already-prorated base, so the resulting
                // amount scales with attendance.
                'prorated' => true, 'statutory' => $statutory,
                'displayOrder' => $order, 'active' => true, 'systemLocked' => true,
            ];
        };

        $flat = function (string $code, string $name, ?string $statutory, int $order) {
            return [
                'code' => $code, 'name' => $name, 'kind' => 'deduction',
                'calcType' => 'FIXED', 'basis' => 'NONE', 'basisComponents' => [],
                'rate' => null, 'amountPaise' => 0,
                'taxable' => false, 'pfApplicable' => false, 'esiApplicable' => false,
                // NOT prorated — the calculator takes these at face value.
                'prorated' => false, 'statutory' => $statutory,
                'displayOrder' => $order, 'active' => true, 'systemLocked' => true,
            ];
        };

        return [
            // ── Earnings (6) ──
            $earning('BASIC',            'Basic',            10, true),
            $earning('HRA',              'House Rent Allowance', 20, false),
            $earning('DA',               'Dearness Allowance',   30, false),
            $earning('TA',               'Travel Allowance',     40, false),
            $earning('MEDICAL',          'Medical Allowance',    50, false),
            $earning('OTHER_ALLOWANCES', 'Other Allowances',     60, false),

            // ── Employee deductions (5) ──
            $pct('PF_EMPLOYEE',  'Provident Fund (Employee)', 'deduction', 'BASIC', 'PF',  110),
            $pct('ESI_EMPLOYEE', 'ESI (Employee)',            'deduction', 'GROSS', 'ESI', 120),
            $pct('TDS',          'TDS',                       'deduction', 'GROSS', 'TDS', 130),
            $flat('PROFESSIONAL_TAX', 'Professional Tax', 'PT',  140),
            $flat('OTHER_DEDUCTIONS', 'Other Deductions', null, 150),

            // ── Employer contributions (2) ──
            $pct('PF_EMPLOYER',  'Provident Fund (Employer)', 'employer_contribution', 'BASIC', 'PF',  210),
            $pct('ESI_EMPLOYER', 'ESI (Employer)',            'employer_contribution', 'GROSS', 'ESI', 220),
        ];
    }

    /**
     * Idempotently seed this tenant's catalogue.
     *
     * Existing components are LEFT ALONE — re-running never overwrites an
     * operator's edits to name/displayOrder, and never resurrects a component
     * that was legitimately changed. Returns a per-code report.
     */
    public function seedSystemComponents(): array
    {
        $report = ['created' => [], 'skipped' => [], 'failed' => []];
        $now = date('c');

        foreach (self::systemComponents() as $c) {
            if ($this->get($c['code']) !== null) {
                $report['skipped'][] = $c['code'];
                continue;
            }
            $c['createdAt'] = $now;
            $c['createdBy'] = $this->actor !== '' ? $this->actor : 'system:w2.1-seed';
            $c['updatedAt'] = $now;
            $c['updatedBy'] = $c['createdBy'];
            try {
                $this->fs->setEntity(self::COLLECTION, $c['code'], $c);
                $report['created'][] = $c['code'];
            } catch (\Exception $ex) {
                log_message('error', "Salary_component_service seed failed for {$c['code']} — " . $ex->getMessage());
                $report['failed'][] = $c['code'];
            }
        }
        return $report;
    }
}
