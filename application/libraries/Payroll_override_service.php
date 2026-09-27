<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Payroll-period override service (ZenX HRMS W3.2.2).
 *
 * WHAT AN OVERRIDE IS — AND IS NOT
 * --------------------------------
 * A payroll-period override is a ONE-PERIOD input to a single payroll run for a
 * single employee. It never touches the employee's permanent compensation, the
 * component master, a salary assignment, statutory policy, Attendance or Leave.
 * Those remain the authoritative sources; an override sits beside them for the
 * length of one run.
 *
 * NO OVERRIDE ARITHMETIC EXISTS HERE — DELIBERATELY
 * -------------------------------------------------
 * W2.7 already extended the certified engine with an `$extraComponents`
 * parameter: earnings join gross BEFORE the statutory block (so PF/ESI/TDS see
 * them on the same base as any other earning), deductions join the deduction
 * pool, and everything is integer paise through `_applyRatio()`. Nothing had
 * ever supplied that parameter.
 *
 * So this service does not compute money. It validates and stores an intent,
 * and `toExtraComponents()` hands it to the engine in the shape the engine
 * already accepts. There is one payroll engine, one money path, and the W1.2
 * paise contract is inherited rather than re-implemented — which is why
 * `gross == net + deductions` cannot be broken from here.
 *
 * STATUTORY IMPACT IS NOT A DESIGN CHOICE — IT IS OBSERVED
 * --------------------------------------------------------
 * The engine already decides what an extra component touches. This table
 * DOCUMENTS that behaviour; it does not invent it:
 *
 *   earning              → joins gross → affects ESI base, TDS base, and the
 *                          employer-ESI base (all are % of gross)
 *                        → does NOT affect the PF base (PF is % of earned
 *                          BASIC, and an override cannot alter basic)
 *                        → does NOT affect PT (flat, or slab on the structure)
 *   deduction            → reduces net only; affects no statutory base
 *   employer_contribution→ touches neither gross nor net; reported by CTC
 *
 * PRORATION defaults to FALSE for every supported type. A bonus is awarded for
 * a period, not earned per-day, so scaling it by attendance would silently pay
 * less than the amount an operator typed and approved. The engine supports both
 * (`prorated`), and the flag is stored per override so the choice is explicit
 * and auditable rather than implied.
 */
class Payroll_override_service
{
    /** Collection. NOT `salarySlips`: that collection's Firestore rule allows
     *  `resource.data.staffId == request.auth.uid`, which would make an
     *  admin-internal `reason` ("retention adjustment pending exit") readable by
     *  the employee it concerns. Overrides follow the `salaryComponents`
     *  precedent instead — server-only, because every write must pass the
     *  validation below and a direct client-SDK write would bypass it. */
    const COLLECTION = 'payrollOverrides';

    const STATUS_ACTIVE  = 'active';
    const STATUS_VOID    = 'void';

    /**
     * Supported adjustment types, and the engine `kind` each maps to.
     *
     * `taxable` records what the engine ACTUALLY does with the mapped kind, so
     * the two can never drift apart silently: every `earning` joins gross and is
     * therefore exposed to ESI/TDS. There is no non-taxable earning class in the
     * engine, which is precisely why REIMBURSEMENT is absent — see
     * `unsupportedTypes()`.
     */
    const TYPES = [
        'BONUS'              => ['kind' => 'earning',   'label' => 'Bonus',                'taxable' => true],
        'INCENTIVE'          => ['kind' => 'earning',   'label' => 'Incentive',            'taxable' => true],
        'ARREARS'            => ['kind' => 'earning',   'label' => 'Arrears',              'taxable' => true],
        'ONE_TIME_EARNING'   => ['kind' => 'earning',   'label' => 'One-time earning',     'taxable' => true],
        'ONE_TIME_DEDUCTION' => ['kind' => 'deduction', 'label' => 'One-time deduction',   'taxable' => false],
        'RECOVERY'           => ['kind' => 'deduction', 'label' => 'Recovery',             'taxable' => false],
        'CORRECTION_ADD'     => ['kind' => 'earning',   'label' => 'Correction (add)',     'taxable' => true],
        'CORRECTION_DEDUCT'  => ['kind' => 'deduction', 'label' => 'Correction (deduct)',  'taxable' => false],
    ];

    /** Runs whose overrides may still change. Anything else is frozen. */
    const EDITABLE_RUN_STATUSES = ['Draft'];

    const MAX_REASON  = 500;
    const MIN_REASON  = 10;
    /** ₹10,00,000 in paise. A sanity ceiling, not a policy: it catches a
     *  misplaced decimal (5000000 typed for 50000) before it reaches a journal.
     *  Not a business rule about how large a bonus may be. */
    const MAX_AMOUNT_PAISE = 100000000;

    /** @var object Firestore_service */
    private $fs;
    private string $actor = '';

    public function init($fs, string $actor = ''): void
    {
        $this->fs    = $fs;
        $this->actor = $actor;
    }

    /**
     * Types a real HRMS needs that this engine cannot yet price CORRECTLY.
     *
     * Listed rather than silently dropped, and rather than faked by booking them
     * as something they are not. Each names the engine capability it waits on.
     */
    public static function unsupportedTypes(): array
    {
        return [
            'REIMBURSEMENT' => 'Needs a NON-TAXABLE earning class. Every earning in the '
                . 'engine joins gross and is therefore exposed to ESI and TDS. Booking a '
                . 'reimbursement as a taxable earning would overstate both — a money error, '
                . 'not a cosmetic one. Requires an engine change; mapped to a later gate.',
            'PF_BASE_ADJUSTMENT' => 'The engine computes PF as a percentage of earned BASIC. '
                . 'A period override cannot alter basic, so no override can move the PF base. '
                . 'Requires the W2 component model; mapped to the compensation cutover.',
            'LOP_ADJUSTMENT' => 'LOP days are derived from Attendance and Leave, which are '
                . 'authoritative. Adjusting LOP from payroll would make payroll a second '
                . 'attendance system. The correct route is a correction in Attendance/Leave '
                . 'followed by regeneration.',
        ];
    }

    public static function isSupportedType(string $type): bool
    {
        return isset(self::TYPES[$type]);
    }

    /**
     * Deterministic document id: one override per (run, employee, type,
     * sequence). Passed in by the caller so a retry of the SAME create dedups
     * instead of silently producing a second bonus.
     */
    public static function buildOverrideId(string $runId, string $staffId, string $type, int $seq): string
    {
        return "OVR_{$runId}_{$staffId}_{$type}_{$seq}";
    }

    // ─────────────────────────────────────────────────────────────────────
    //  Validation
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Validate a create/update payload.
     *
     * Returns ['ok'=>bool, 'errors'=>string[], 'clean'=>array]. Money arrives as
     * rupees from the UI and is converted ONCE here, at the boundary, to integer
     * paise — the same single-conversion discipline W1.2 imposes on the
     * calculator. Nothing downstream re-parses the rupee value.
     */
    public function validate(array $in): array
    {
        $errors = [];

        $runId   = trim((string) ($in['run_id']   ?? ''));
        $staffId = trim((string) ($in['staff_id'] ?? ''));
        $type    = strtoupper(trim((string) ($in['type'] ?? '')));
        $reason  = trim((string) ($in['reason']   ?? ''));

        if ($runId === '')   $errors[] = 'Payroll run is required.';
        if ($staffId === '') $errors[] = 'Employee is required.';

        if ($type === '') {
            $errors[] = 'Adjustment type is required.';
        } elseif (!self::isSupportedType($type)) {
            $unsupported = self::unsupportedTypes();
            $errors[] = isset($unsupported[$type])
                ? "Adjustment type '{$type}' is not supported yet: " . $unsupported[$type]
                : "Unknown adjustment type '{$type}'.";
        }

        if (strlen($reason) < self::MIN_REASON) {
            $errors[] = 'A reason of at least ' . self::MIN_REASON . ' characters is required.';
        } elseif (strlen($reason) > self::MAX_REASON) {
            $errors[] = 'Reason must be at most ' . self::MAX_REASON . ' characters.';
        }

        // Amount: accept rupees, store paise. Reject anything that is not a
        // positive finite number — direction is carried by the TYPE, never by a
        // negative amount, so "-5000 bonus" cannot quietly become a deduction.
        $rawAmount = $in['amount'] ?? null;
        $amountPaise = 0;
        if ($rawAmount === null || $rawAmount === '' || !is_numeric($rawAmount)) {
            $errors[] = 'Amount is required and must be a number.';
        } else {
            $amt = (float) $rawAmount;
            if (!is_finite($amt) || $amt <= 0) {
                $errors[] = 'Amount must be greater than zero. Use the adjustment type to choose earning or deduction.';
            } else {
                $amountPaise = (int) round($amt * 100);
                if ($amountPaise <= 0) {
                    $errors[] = 'Amount is too small to represent (minimum ₹0.01).';
                } elseif ($amountPaise > self::MAX_AMOUNT_PAISE) {
                    $errors[] = 'Amount exceeds the ₹10,00,000 per-adjustment sanity limit. Split it or raise the limit deliberately.';
                }
            }
        }

        if (!empty($errors)) return ['ok' => false, 'errors' => $errors, 'clean' => []];

        return ['ok' => true, 'errors' => [], 'clean' => [
            'run_id'       => $runId,
            'staff_id'     => $staffId,
            'type'         => $type,
            'kind'         => self::TYPES[$type]['kind'],
            'taxable'      => self::TYPES[$type]['taxable'],
            'amount_paise' => $amountPaise,
            'reason'       => $reason,
            // Explicit and stored, never implied. See the class docblock.
            'prorated'     => !empty($in['prorated']),
        ]];
    }

    /** A run may only be adjusted while it is still editable. */
    public static function isRunEditable(?array $run): bool
    {
        if (!is_array($run)) return false;
        return in_array((string) ($run['status'] ?? ''), self::EDITABLE_RUN_STATUSES, true);
    }

    // ─────────────────────────────────────────────────────────────────────
    //  Engine hand-off
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Map stored overrides into the exact shape `Hr::_compute_payslip()` already
     * accepts for `$extraComponents`.
     *
     * Voided overrides are skipped rather than deleted, so the audit record of a
     * withdrawn adjustment survives.
     *
     * The `code` is the override id, not the type: two separate bonuses in one
     * period must appear as two lines in the payslip breakdown. Keying by type
     * would collapse them and lose one.
     */
    public static function toExtraComponents(array $overrides): array
    {
        $out = [];
        foreach ($overrides as $o) {
            if (!is_array($o)) continue;
            if ((string) ($o['status'] ?? self::STATUS_ACTIVE) !== self::STATUS_ACTIVE) continue;

            $amt = (int) ($o['amount_paise'] ?? 0);
            if ($amt <= 0) continue;

            $type = (string) ($o['type'] ?? '');
            if (!self::isSupportedType($type)) continue;   // fail closed

            $out[] = [
                'code'        => (string) ($o['override_id'] ?? $type),
                'kind'        => self::TYPES[$type]['kind'],
                'amountPaise' => $amt,
                'prorated'    => !empty($o['prorated']),
                // Carried for display/trace only; the engine ignores extra keys.
                'label'       => self::TYPES[$type]['label'],
                'type'        => $type,
            ];
        }
        return $out;
    }

    /**
     * Net effect in paise, for display and for the "original → adjustment →
     * final" presentation. Earnings positive, deductions negative.
     *
     * This is a SUMMARY of what the engine will do, never a substitute for it:
     * the payslip's figures always come from `_compute_payslip()`. Presented
     * separately so the UI never has to do payroll arithmetic of its own.
     */
    public static function summarise(array $overrides): array
    {
        $earnP = 0; $dedP = 0; $n = 0;
        foreach (self::toExtraComponents($overrides) as $xc) {
            if ($xc['kind'] === 'earning')        { $earnP += $xc['amountPaise']; $n++; }
            elseif ($xc['kind'] === 'deduction')  { $dedP  += $xc['amountPaise']; $n++; }
        }
        return [
            'count'              => $n,
            'earnings_paise'     => $earnP,
            'deductions_paise'   => $dedP,
            'net_effect_paise'   => $earnP - $dedP,
        ];
    }

    // ─────────────────────────────────────────────────────────────────────
    //  Persistence — every read and write tenant-scoped
    // ─────────────────────────────────────────────────────────────────────

    /**
     * All overrides for one run, in ONE query.
     *
     * Bulk by design: the review grid needs every employee's adjustments at
     * once, and a per-employee read would be an N+1 that grows with headcount —
     * the same trap `Compensation_reader::resolveMany()` exists to avoid.
     *
     * @return array<string,array> staffId → override rows
     */
    public function forRun(string $runId): array
    {
        $byStaff = [];
        try {
            $docs = $this->fs->schoolWhere(self::COLLECTION, [['run_id', '==', $runId]]);
            if (is_array($docs)) {
                foreach ($docs as $doc) {
                    $d = is_array($doc['data'] ?? null) ? $doc['data'] : $doc;
                    $sid = (string) ($d['staff_id'] ?? '');
                    if ($sid === '') continue;
                    $byStaff[$sid][] = $d;
                }
            }
        } catch (\Exception $e) {
            log_message('error', 'Payroll_override_service::forRun failed: ' . $e->getMessage());
        }
        return $byStaff;
    }

    /** Overrides for one employee in one run. */
    public function forStaff(string $runId, string $staffId): array
    {
        $all = $this->forRun($runId);
        return $all[$staffId] ?? [];
    }

    /**
     * Persist a validated override.
     *
     * The caller supplies the sequence so the id is deterministic and a retried
     * create dedups rather than producing a second adjustment. `create` is a
     * non-merge write so an existing id is never silently mutated by what the
     * caller believes is a fresh create.
     */
    public function create(array $clean, int $seq): array
    {
        $id  = self::buildOverrideId($clean['run_id'], $clean['staff_id'], $clean['type'], $seq);
        $now = date('c');

        $doc = array_merge($clean, [
            'override_id' => $id,
            'status'      => self::STATUS_ACTIVE,
            'created_by'  => $this->actor,
            'created_at'  => $now,
            'updated_by'  => $this->actor,
            'updated_at'  => $now,
            // Filled by the recalculation that applies it, so an auditor can see
            // the payslip figures this adjustment actually moved.
            'applied_at'      => '',
            'original_net'    => null,
            'final_net'       => null,
        ]);

        $this->fs->set(self::COLLECTION, $this->fs->docId($id), $doc, false);
        return $doc;
    }

    /**
     * Void an override. Never a hard delete: the audit trail of a withdrawn
     * adjustment is exactly the thing an auditor asks about, and deleting the
     * row destroys it.
     */
    public function void(string $overrideId, string $reason): void
    {
        $this->fs->set(self::COLLECTION, $this->fs->docId($overrideId), [
            'status'       => self::STATUS_VOID,
            'void_reason'  => $reason,
            'voided_by'    => $this->actor,
            'voided_at'    => date('c'),
            'updated_by'   => $this->actor,
            'updated_at'   => date('c'),
        ], true);
    }

    /** Record which recalculation applied this override, and its money effect. */
    public function markApplied(string $overrideId, float $originalNet, float $finalNet): void
    {
        $this->fs->set(self::COLLECTION, $this->fs->docId($overrideId), [
            'applied_at'   => date('c'),
            'original_net' => $originalNet,
            'final_net'    => $finalNet,
            'updated_at'   => date('c'),
        ], true);
    }
}
