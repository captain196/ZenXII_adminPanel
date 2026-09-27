<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Salary_assignment_service — effective-dated per-employee compensation
 * (ZenX HRMS W2.3).
 *
 * WHAT THIS ADDS
 * --------------
 * Today an employee has ONE salary record that is overwritten on every change.
 * `_version` + a four-field `_prev` snapshot is an audit breadcrumb, not
 * history: after a mid-year revision, nobody can reconstruct what March was
 * paid from, and nobody can compute arrears because the old figures are gone.
 *
 * An assignment is dated. A revision SUPERSEDES rather than overwrites, so every
 * past period stays reconstructable — which is the prerequisite for arrears
 * (cap 49), for the payroll snapshot (W3), and for any audit that has to answer
 * "why was this person paid this in March".
 *
 * WHAT THIS IS NOT (W2.3 boundary)
 * --------------------------------
 * Nothing in the payroll path reads assignments. `_compute_payslip()`,
 * `generate_payroll()` and the legacy `salarySlips` structures are untouched,
 * no legacy record is rewritten, and the migration ships DRY-RUN ONLY. The
 * reader that lets payroll consume assignments is W2.4, behind a flag, after a
 * parity gate.
 *
 * READINESS IS A FEATURE, NOT A GAP
 * ---------------------------------
 * `readiness()` exists because "this employee has no salary assignment" must be
 * a VISIBLE SETUP REQUIREMENT that blocks payroll, never a silent zero. A
 * missing assignment is the single most expensive thing to discover after a run
 * rather than before it.
 *
 * MONEY: integer paise throughout (W1.2). Rates stay decimal percentages.
 * Ratio application reuses Statutory_config_service::applyRatio(), which is
 * runtime-proven identical to Hr::_applyRatio() — no third money policy.
 */
class Salary_assignment_service
{
    /** @var object Firestore_service, already school-scoped. */
    private $fs;
    /** @var string */
    private $actor = '';

    const COLLECTION = 'staffSalaryAssignments';

    const PAY_BASES = ['MONTHLY_FIXED', 'DAILY', 'HOURLY'];
    const STATUSES  = ['draft', 'active', 'superseded'];

    public function init($fs, string $actor = ''): void
    {
        $this->fs    = $fs;
        $this->actor = $actor;
        if (!function_exists('ed_select_for')) {
            require_once APPPATH . 'helpers/effective_dating_helper.php';
        }
    }

    /** Deterministic doc-id suffix: one assignment per staff per start date. */
    public static function entityId(string $staffId, string $effectiveFrom): string
    {
        return $staffId . '_' . $effectiveFrom;
    }

    // ─────────────────────────────────────────────────────────────────────
    //  Validation
    // ─────────────────────────────────────────────────────────────────────

    private static function isIntPaise($v): bool
    {
        if (is_int($v)) return true;
        return is_string($v) && ctype_digit($v);
    }

    /**
     * Validate an assignment payload.
     *
     * @param array $knownComponents code => component metadata, for line checks
     * @return array{0:array,1:array} [clean, errors]
     */
    public function validate(array $in, array $knownComponents = []): array
    {
        if (!function_exists('ed_is_iso_date')) {
            require_once APPPATH . 'helpers/effective_dating_helper.php';
        }
        $e = [];

        $staffId = trim((string) ($in['staffId'] ?? ''));
        if ($staffId === '') {
            $e[] = 'staffId is required.';
        } elseif (!preg_match('/^[A-Za-z0-9_-]{1,64}$/', $staffId)) {
            // It becomes part of the document id, so it must be path-safe.
            $e[] = 'staffId contains characters that are not allowed in a document id.';
        }

        $from = trim((string) ($in['effectiveFrom'] ?? ''));
        if (!ed_is_iso_date($from)) $e[] = 'effectiveFrom must be a real date in YYYY-MM-DD form.';

        $to = $in['effectiveTo'] ?? null;
        if ($to === '' || $to === 'null') $to = null;
        if ($to !== null) {
            $to = trim((string) $to);
            if (!ed_is_iso_date($to)) {
                $e[] = 'effectiveTo must be a real date in YYYY-MM-DD form, or null for open-ended.';
            } elseif ($from !== '' && $to < $from) {
                $e[] = 'effectiveTo must not precede effectiveFrom.';
            }
        }

        $payBasis = strtoupper(trim((string) ($in['payBasis'] ?? 'MONTHLY_FIXED')));
        if (!in_array($payBasis, self::PAY_BASES, true)) {
            $e[] = 'payBasis must be one of: ' . implode(', ', self::PAY_BASES) . '.';
        }
        // DAILY/HOURLY are modelled so the schema needs no later migration, but
        // wiring them means changing the proration arithmetic W1.2 certified,
        // and their only consumers (Retail) are deferred. Refuse rather than
        // store an assignment no calculator can honour.
        if ($payBasis === 'DAILY' || $payBasis === 'HOURLY') {
            $e[] = "payBasis {$payBasis} is modelled but not yet payable — only MONTHLY_FIXED is wired.";
        }

        $status = strtolower(trim((string) ($in['status'] ?? 'active')));
        if (!in_array($status, self::STATUSES, true)) {
            $e[] = 'status must be one of: ' . implode(', ', self::STATUSES) . '.';
        }

        // ── lines ──
        $linesIn = is_array($in['lines'] ?? null) ? $in['lines'] : [];
        $lines = [];
        $seenCodes = [];
        $balancingCount = 0;

        foreach ($linesIn as $i => $l) {
            if (!is_array($l)) { $e[] = "lines[{$i}] must be an object."; continue; }

            $code = strtoupper(trim((string) ($l['componentId'] ?? $l['code'] ?? '')));
            if ($code === '') { $e[] = "lines[{$i}] is missing componentId."; continue; }

            if (isset($seenCodes[$code])) { $e[] = "Component {$code} appears more than once."; continue; }
            $seenCodes[$code] = true;

            // A line may only reference a component that exists in the catalogue.
            // Otherwise an assignment could carry a component nothing can price.
            $meta = $knownComponents[$code] ?? null;
            if (!empty($knownComponents) && $meta === null) {
                $e[] = "Component {$code} is not in this school's component catalogue.";
                continue;
            }

            // The catalogue owns calcType/basis; the assignment supplies the
            // VALUE. Taking calcType from the payload would let one employee's
            // PF silently become a fixed amount.
            $calcType = (string) ($meta['calcType'] ?? $l['calcType'] ?? '');
            $basis    = (string) ($meta['basis'] ?? $l['basis'] ?? 'NONE');

            $rate   = array_key_exists('rate', $l) && $l['rate'] !== '' && $l['rate'] !== null ? $l['rate'] : null;
            $amount = array_key_exists('amountPaise', $l) && $l['amountPaise'] !== '' && $l['amountPaise'] !== null ? $l['amountPaise'] : null;

            if ($calcType === 'PERCENT') {
                if ($rate === null)                              $e[] = "lines[{$i}] ({$code}) is a PERCENT component and needs a rate.";
                elseif (!is_numeric($rate) || (float) $rate < 0 || (float) $rate > 100) $e[] = "lines[{$i}] ({$code}) rate must be between 0 and 100.";
                if ($amount !== null)                            $e[] = "lines[{$i}] ({$code}) is a PERCENT component and must not carry amountPaise.";
            } elseif ($calcType === 'FIXED') {
                if ($amount === null)          $e[] = "lines[{$i}] ({$code}) is a FIXED component and needs amountPaise.";
                elseif (!self::isIntPaise($amount)) $e[] = "lines[{$i}] ({$code}) amountPaise must be an integer number of paise (not rupees, not a decimal).";
                elseif ((int) $amount < 0)     $e[] = "lines[{$i}] ({$code}) amountPaise must not be negative.";
                if ($rate !== null)            $e[] = "lines[{$i}] ({$code}) is a FIXED component and must not carry a rate.";
            } elseif ($calcType === 'BALANCING') {
                $balancingCount++;
                if ($rate !== null || $amount !== null) {
                    $e[] = "lines[{$i}] ({$code}) is BALANCING — its value is the remainder of gross and must not be supplied.";
                }
            } elseif ($calcType === 'FORMULA') {
                $e[] = "lines[{$i}] ({$code}) is a FORMULA component, which has no evaluator yet.";
            } else {
                $e[] = "lines[{$i}] ({$code}) has an unknown calcType.";
            }

            $lines[] = [
                'componentId' => $code,
                'calcType'    => $calcType,
                'basis'       => $basis,
                'rate'        => $calcType === 'PERCENT' && $rate !== null ? (float) $rate : null,
                'amountPaise' => $calcType === 'FIXED' && $amount !== null ? (int) $amount : null,
            ];
        }

        // Two components both claiming "the remainder" is unresolvable.
        if ($balancingCount > 1) {
            $e[] = 'Only one BALANCING component is allowed — two cannot both absorb the remainder of gross.';
        }

        // ── gross / CTC ──
        $gross = $in['grossPaise'] ?? null;
        if ($gross === '' ) $gross = null;
        if ($gross !== null) {
            if (!self::isIntPaise($gross))  $e[] = 'grossPaise must be an integer number of paise.';
            elseif ((int) $gross < 0)       $e[] = 'grossPaise must not be negative.';
        }
        if ($balancingCount === 1 && $gross === null) {
            $e[] = 'A BALANCING component needs grossPaise — there is no remainder to compute without a total.';
        }

        $ctc = $in['ctcPaise'] ?? null;
        if ($ctc === '') $ctc = null;
        if ($ctc !== null && !self::isIntPaise($ctc)) $e[] = 'ctcPaise must be an integer number of paise.';

        $clean = [
            'staffId'       => $staffId,
            'effectiveFrom' => $from,
            'effectiveTo'   => $to,
            'payBasis'      => $payBasis,
            'status'        => $status,
            'templateId'    => ($in['templateId'] ?? null) ?: null,
            'payGrade'      => trim((string) ($in['payGrade'] ?? '')) ?: null,
            'lines'         => $lines,
            'grossPaise'    => $gross === null ? null : (int) $gross,
            'ctcPaise'      => $ctc === null ? null : (int) $ctc,
            'supersedesId'  => ($in['supersedesId'] ?? null) ?: null,
            'reason'        => trim((string) ($in['reason'] ?? '')),
        ];
        return [$clean, $e];
    }

    // ─────────────────────────────────────────────────────────────────────
    //  Resolution
    // ─────────────────────────────────────────────────────────────────────

    /** Every assignment for one employee, oldest first. */
    public function listForStaff(string $staffId): array
    {
        try {
            $rows = $this->fs->schoolWhere(self::COLLECTION, [['staffId', '==', $staffId]]);
        } catch (\Exception $ex) {
            log_message('error', "Salary_assignment_service::listForStaff({$staffId}) failed — " . $ex->getMessage());
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

    /**
     * The assignment in force for an employee on a date, or null.
     *
     * Null is a real answer — "this employee has no compensation configured for
     * this period" — and callers MUST treat it as a setup requirement that
     * blocks payroll, never as zero pay.
     */
    public function resolve(string $staffId, string $date): ?array
    {
        return ed_select_for($this->listForStaff($staffId), $date);
    }

    /**
     * Split gross across lines and return the per-component paise.
     *
     * Order is load-bearing and mirrors how the legacy calculator works:
     *   1. FIXED lines take their stated amount.
     *   2. PERCENT lines apply to their declared basis (BASIC or GROSS).
     *   3. The BALANCING line, if any, absorbs `gross − (everything above)`.
     *
     * Only EARNINGS participate in the gross split; deductions and employer
     * contributions are priced by the statutory engine and the calculator, not
     * here. Returns a `remainderPaise` so a caller can see an over-subscribed
     * structure rather than silently clamping it.
     *
     * @return array{components:array<string,int>,assignedPaise:int,remainderPaise:int,balanced:bool}
     */
    public static function splitGross(array $assignment, array $componentMeta): array
    {
        if (!class_exists('Statutory_config_service')) {
            require_once APPPATH . 'libraries/Statutory_config_service.php';
        }

        $gross  = (int) ($assignment['grossPaise'] ?? 0);
        $lines  = is_array($assignment['lines'] ?? null) ? $assignment['lines'] : [];
        $out    = [];
        $basicP = 0;

        // Pass 1 — fixed amounts. Basic is captured because PERCENT lines may
        // be based on it.
        foreach ($lines as $l) {
            $code = (string) ($l['componentId'] ?? '');
            $meta = $componentMeta[$code] ?? [];
            if (($meta['kind'] ?? 'earning') !== 'earning') continue;
            if (($l['calcType'] ?? '') !== 'FIXED') continue;
            $out[$code] = (int) ($l['amountPaise'] ?? 0);
            if ($code === 'BASIC') $basicP = $out[$code];
        }

        // Pass 2 — percentages, against their declared basis.
        foreach ($lines as $l) {
            $code = (string) ($l['componentId'] ?? '');
            $meta = $componentMeta[$code] ?? [];
            if (($meta['kind'] ?? 'earning') !== 'earning') continue;
            if (($l['calcType'] ?? '') !== 'PERCENT') continue;
            $base = ((string) ($l['basis'] ?? 'GROSS')) === 'BASIC' ? $basicP : $gross;
            $out[$code] = Statutory_config_service::applyRatio(
                $base, Statutory_config_service::pctToRatio((float) ($l['rate'] ?? 0))
            );
        }

        $assigned = array_sum($out);

        // Pass 3 — the remainder.
        $balancing = null;
        foreach ($lines as $l) {
            if (($l['calcType'] ?? '') === 'BALANCING') { $balancing = (string) $l['componentId']; break; }
        }
        $remainder = $gross - $assigned;
        if ($balancing !== null) {
            // Never negative: an over-subscribed structure is a configuration
            // error the caller must see, not something to paper over with a
            // negative allowance.
            $out[$balancing] = max(0, $remainder);
            $assigned += $out[$balancing];
            $remainder = $gross - $assigned;
        }

        return [
            'components'     => $out,
            'assignedPaise'  => $assigned,
            'remainderPaise' => $remainder,
            'balanced'       => $remainder === 0,
        ];
    }

    // ─────────────────────────────────────────────────────────────────────
    //  Readiness — a visible setup requirement, never a silent zero
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Which employees cannot be paid for a period, and why.
     *
     * This is the "missing configuration → visible setup requirement" contract:
     * payroll must be blocked with a NAMED list, not run with zeros. It answers
     * per employee rather than in aggregate, because "3 staff have no salary
     * structure" is not something an operator can act on.
     *
     * @param array $staff rows of ['staffId' => ..., 'name' => ...]
     * @return array{ready:array,blocked:array,summary:array}
     */
    public function readiness(array $staff, string $date, array $opts = []): array
    {
        // W2.5 (cap 41): payout-prerequisite checks. Separated from the
        // blocking checks because they are different in kind — a missing salary
        // assignment means the amount cannot be COMPUTED, while a missing bank
        // account means it cannot be PAID. Both must be visible before a run;
        // conflating them would either block payroll for a fixable data gap or
        // hide a gap that stops the transfer.
        // DEFAULT OFF, and that is a correctness decision rather than caution.
        // Bank/PAN/PF/ESI live in the server-only PII mirror, not on the staff
        // document a caller typically passes in (W2.2 discovery found 0 of each
        // on the main docs across every tenant). Defaulting this ON would
        // therefore report EVERY employee in EVERY tenant as missing a bank
        // account and block all payroll — a false positive at scale, and the
        // worst possible failure mode for a readiness check whose whole job is
        // to be trusted. The caller opts in once it supplies PII-merged rows.
        $checkPayout    = $opts['checkPayoutDetails'] ?? false;
        $requireBank    = $opts['requireBank']        ?? true;
        $requirePan     = $opts['requirePan']         ?? false;   // PAN matters once TDS is live
        $requirePf      = $opts['requirePf']          ?? false;   // only when PF applies
        $requireEsi     = $opts['requireEsi']         ?? false;   // only when ESI applies
        $grades         = $opts['gradeService']       ?? null;

        // READY-D1. Which compensation architecture is authoritative is a
        // DEPLOYMENT decision (USE_COMPONENT_COMPENSATION), not something a
        // readiness check may assume. Resolving component assignments while the
        // deployment still runs on legacy structures reports every employee in
        // the school as unpayable -- the same false-positive-at-scale failure
        // this method already guards against for payout details, and just as
        // untrustworthy. The caller injects the resolver for the mode actually
        // in force; the default keeps the component behaviour.
        $resolver       = $opts['resolver']           ?? null;
        $missingReason  = $opts['missingReason']      ?? 'No salary assignment is effective for this period.';

        $ready = [];
        $blocked = [];
        $warnings = [];

        foreach ($staff as $s) {
            $staffId = (string) ($s['staffId'] ?? $s['id'] ?? '');
            if ($staffId === '') continue;
            $name = (string) ($s['name'] ?? $s['Name'] ?? $staffId);

            $a = $resolver !== null ? $resolver($staffId, $date) : $this->resolve($staffId, $date);
            if ($a === null) {
                $blocked[] = ['staffId' => $staffId, 'name' => $name,
                              'reason' => $missingReason];
                continue;
            }
            if (($a['status'] ?? '') === 'draft') {
                $blocked[] = ['staffId' => $staffId, 'name' => $name,
                              'reason' => 'The effective salary assignment is still a draft.'];
                continue;
            }
            if (empty($a['lines'])) {
                $blocked[] = ['staffId' => $staffId, 'name' => $name,
                              'reason' => 'The effective salary assignment has no components.'];
                continue;
            }

            // ── Payout prerequisites — named, per employee, never aggregate ──
            if ($checkPayout) {
                $missing = [];
                if ($requireBank && !self::hasBankDetails($s))            $missing[] = 'bank account';
                if ($requirePan  && trim((string) ($s['panNumber'] ?? '')) === '')  $missing[] = 'PAN';
                if ($requirePf   && trim((string) ($s['pfNumber'] ?? '')) === '')   $missing[] = 'PF number';
                if ($requireEsi  && trim((string) ($s['esiNumber'] ?? '')) === '')  $missing[] = 'ESI number';

                if (!empty($missing)) {
                    $blocked[] = ['staffId' => $staffId, 'name' => $name,
                                  'reason' => 'Missing payout details: ' . implode(', ', $missing) . '.'];
                    continue;
                }
            }

            // ── Band breach is a WARNING, not a block ──
            // A deliberate exception (retention offer, hard-to-fill role) must
            // be recordable. Refusing it outright gets worked around by putting
            // people on the wrong grade, which is worse than a visible exception.
            if ($grades !== null) {
                $band = $grades->checkAssignmentBand($a);
                if (!empty($band['checked']) && empty($band['inBand'])) {
                    $warnings[] = ['staffId' => $staffId, 'name' => $name, 'reason' => $band['message']];
                } elseif (empty($band['checked']) && ($band['severity'] ?? '') === 'warning') {
                    $warnings[] = ['staffId' => $staffId, 'name' => $name, 'reason' => $band['message']];
                }
            }

            $ready[] = ['staffId' => $staffId, 'name' => $name, 'effectiveFrom' => $a['effectiveFrom'] ?? ''];
        }

        return [
            'ready'    => $ready,
            'blocked'  => $blocked,
            'warnings' => $warnings,
            'summary'  => [
                'total'    => count($ready) + count($blocked),
                'ready'    => count($ready),
                'blocked'  => count($blocked),
                'warnings' => count($warnings),
                // Warnings do NOT gate the run — only blockers do.
                'payrollEligible' => count($blocked) === 0 && count($ready) > 0,
            ],
        ];
    }

    /**
     * Does this staff row carry usable bank details?
     *
     * Tolerant of the several shapes the field has taken across the codebase's
     * history (nested `bankDetails` map, flat columns, camel and snake), because
     * a false "missing bank account" would block a payroll run for a person who
     * is actually payable — the most annoying possible false positive.
     */
    public static function hasBankDetails(array $s): bool
    {
        // W3.2.5 — the rule now lives in `payroll_readiness_helper.php`, which
        // payroll preflight can use WITHOUT naming this class (naming it would
        // trip the W2 guards that keep the compensation cutover un-wired).
        // Delegating keeps exactly one implementation, so this readiness
        // surface and payroll preflight can never disagree.
        require_once APPPATH . 'helpers/payroll_readiness_helper.php';
        return payroll_has_bank_details($s);
    }

    // ─────────────────────────────────────────────────────────────────────
    //  CTC derivation (W2.5)
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Cost to company = gross + employer contributions.
     *
     * Employer PF/ESI are PERCENTAGES on the assignment, so turning them into
     * money needs the same bases the calculator uses — PF on basic, ESI on
     * gross — which is exactly what the component catalogue declares. Reusing
     * `Statutory_config_service::applyRatio()` keeps this on the one money
     * policy rather than introducing arithmetic of its own.
     *
     * Statutory ceilings are deliberately NOT applied here: `_compute_payslip()`
     * does not apply them either, so a CTC that did would disagree with the
     * payslip it is meant to summarise. Ceiling-aware CTC arrives with the gate
     * that makes the calculator ceiling-aware.
     *
     * @return array{grossPaise:int,employerPaise:int,ctcPaise:int,breakdown:array<string,int>}
     */
    public static function deriveCtc(array $assignment, array $componentMeta): array
    {
        if (!class_exists('Statutory_config_service')) {
            require_once APPPATH . 'libraries/Statutory_config_service.php';
        }

        $split  = self::splitGross($assignment, $componentMeta);
        $gross  = (int) ($assignment['grossPaise'] ?? 0);
        if ($gross === 0) $gross = (int) $split['assignedPaise'];

        $basicP = (int) ($split['components']['BASIC'] ?? 0);

        $employer = 0;
        $breakdown = [];
        foreach (($assignment['lines'] ?? []) as $l) {
            $code = (string) ($l['componentId'] ?? '');
            $meta = $componentMeta[$code] ?? [];
            if (($meta['kind'] ?? '') !== 'employer_contribution') continue;
            if (($l['calcType'] ?? '') !== 'PERCENT') continue;

            $base = ((string) ($l['basis'] ?? 'GROSS')) === 'BASIC' ? $basicP : $gross;
            $amt  = Statutory_config_service::applyRatio(
                $base, Statutory_config_service::pctToRatio((float) ($l['rate'] ?? 0))
            );
            $breakdown[$code] = $amt;
            $employer += $amt;
        }

        return [
            'grossPaise'    => $gross,
            'employerPaise' => $employer,
            'ctcPaise'      => $gross + $employer,
            'breakdown'     => $breakdown,
        ];
    }

    // ─────────────────────────────────────────────────────────────────────
    //  Write
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Create or replace the assignment starting on `effectiveFrom`.
     *
     * Refuses any window overlapping an existing assignment for the same
     * employee — a revision must SUPERSEDE (close the old window, open a new
     * one), never silently coexist.
     *
     * @return array{0:bool,1:array}
     */
    public function save(array $in, array $knownComponents = []): array
    {
        [$clean, $errors] = $this->validate($in, $knownComponents);
        if (!empty($errors)) return [false, $errors];

        $existing = $this->listForStaff($clean['staffId']);
        $others = array_values(array_filter(
            $existing,
            fn($a) => (string) ($a['effectiveFrom'] ?? '') !== $clean['effectiveFrom']
        ));
        $conflicts = ed_find_overlaps(array_merge($others, [$clean]), 'assignment');
        if (!empty($conflicts)) return [false, $conflicts];

        $now = date('c');
        $prior = null;
        foreach ($existing as $a) {
            if ((string) ($a['effectiveFrom'] ?? '') === $clean['effectiveFrom']) { $prior = $a; break; }
        }
        $clean['createdAt'] = $prior['createdAt'] ?? $now;
        $clean['createdBy'] = $prior['createdBy'] ?? $this->actor;
        $clean['updatedAt'] = $now;
        $clean['updatedBy'] = $this->actor;

        try {
            $this->fs->setEntity(self::COLLECTION, self::entityId($clean['staffId'], $clean['effectiveFrom']), $clean);
        } catch (\Exception $ex) {
            log_message('error', 'Salary_assignment_service::save failed — ' . $ex->getMessage());
            return [false, ['Could not save the salary assignment. Please try again.']];
        }
        return [true, []];
    }

    // ─────────────────────────────────────────────────────────────────────
    //  Legacy mapping (DRY-RUN ONLY in W2.3)
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Map ONE legacy `salarySlips` structure onto an assignment payload.
     *
     * Pure and side-effect free so the migration can be inspected before it is
     * ever executed. The legacy record's thirteen fields map onto catalogue
     * component codes; rupee values become integer paise here, at the boundary,
     * exactly once.
     *
     * `effectiveFrom` is supplied by the caller rather than guessed: the legacy
     * record has `created_at`/`updated_at` but neither is a compensation
     * effective date, and inventing one would fabricate history.
     */
    public static function fromLegacyStructure(array $legacy, string $staffId, string $effectiveFrom): array
    {
        $toPaise = fn($rupees) => (int) round(((float) $rupees) * 100.0);

        $fixedMap = [
            'basic' => 'BASIC', 'hra' => 'HRA', 'da' => 'DA', 'ta' => 'TA',
            'medical' => 'MEDICAL', 'other_allowances' => 'OTHER_ALLOWANCES',
            'professional_tax' => 'PROFESSIONAL_TAX', 'other_deductions' => 'OTHER_DEDUCTIONS',
        ];
        $percentMap = [
            'pf_employee' => 'PF_EMPLOYEE', 'esi_employee' => 'ESI_EMPLOYEE', 'tds' => 'TDS',
            'pf_employer' => 'PF_EMPLOYER', 'esi_employer' => 'ESI_EMPLOYER',
        ];

        $lines = [];
        $grossP = 0;
        foreach ($fixedMap as $field => $code) {
            if (!array_key_exists($field, $legacy)) continue;
            $p = $toPaise($legacy[$field]);
            $lines[] = ['componentId' => $code, 'calcType' => 'FIXED', 'basis' => 'NONE',
                        'rate' => null, 'amountPaise' => $p];
            if (in_array($code, ['BASIC', 'HRA', 'DA', 'TA', 'MEDICAL', 'OTHER_ALLOWANCES'], true)) {
                $grossP += $p;
            }
        }
        foreach ($percentMap as $field => $code) {
            if (!array_key_exists($field, $legacy)) continue;
            $lines[] = ['componentId' => $code, 'calcType' => 'PERCENT',
                        'basis' => in_array($code, ['PF_EMPLOYEE', 'PF_EMPLOYER'], true) ? 'BASIC' : 'GROSS',
                        'rate' => (float) $legacy[$field], 'amountPaise' => null];
        }

        return [
            'staffId'       => $staffId,
            'effectiveFrom' => $effectiveFrom,
            'effectiveTo'   => null,
            'payBasis'      => 'MONTHLY_FIXED',
            'status'        => 'active',
            'lines'         => $lines,
            'grossPaise'    => $grossP,
            'reason'        => 'Migrated from the legacy salary structure (W2.3).',
        ];
    }
}
