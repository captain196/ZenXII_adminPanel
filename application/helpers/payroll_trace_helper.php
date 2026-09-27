<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Payroll calculation trace assembly (ZenX HRMS W3.2.4).
 *
 * ASSEMBLES — NEVER COMPUTES
 * --------------------------
 * Every figure here is READ from the frozen payslip. Nothing is recalculated,
 * re-derived or re-rounded. `Hr::_compute_payslip()` remains the only thing
 * that prices a payslip; this turns what it already returned into an ordered
 * explanation of why the net pay is what it is.
 *
 * That is the whole design constraint. A trace that recomputed its own numbers
 * would eventually disagree with the payslip it claims to explain, and the
 * disagreement would surface as an auditor being told two different stories.
 * So the rule is absolute: if a value is not in the stored slip, the trace says
 * it is unavailable rather than working it out.
 *
 * WHY A HELPER
 * ------------
 * `Hr.php` extends `MY_Controller` and cannot load in the PHPUnit bootstrap.
 * A trace whose correctness cannot be tested is worth very little, so the
 * assembly lives here — the same move W1.3 made for `hr_next_id()` and W3.2.1
 * for the snapshot masks.
 *
 * FROZEN, NOT LIVE
 * ----------------
 * Input is the slip document alone. No collection is queried, so the trace
 * cannot drift when today's salary structure, statutory policy, attendance or
 * component catalogue change. A finalized payslip stays explainable.
 */

if (!function_exists('payroll_trace_money')) {
    /** Paise → rupees. The single presentation boundary; mirrors Hr::_toRupees. */
    function payroll_trace_money(?int $paise): ?float
    {
        return $paise === null ? null : $paise / 100;
    }
}

if (!function_exists('payroll_trace_consistency')) {
    /**
     * Does the trace agree with the payslip it explains?
     *
     * Checked at EXACT integer paise with no tolerance — a tolerance would hide
     * precisely the one-paisa drift this is meant to catch. Returns the checks
     * performed plus an overall verdict; an inconsistent trace is reported as
     * inconsistent rather than quietly presented as fact.
     */
    function payroll_trace_consistency(array $slip): array
    {
        $t     = is_array($slip['_calculation_trace'] ?? null) ? $slip['_calculation_trace'] : null;
        $paise = ($t && is_array($t['paise'] ?? null)) ? $t['paise'] : null;

        if ($paise === null) {
            return [
                'available' => false,
                'consistent' => null,
                'checks' => [],
                'reason' => 'No calculation trace was recorded for this payslip.',
            ];
        }

        $checks = [];

        // The W1.2 invariant, re-checked on the frozen numbers.
        $grossP = (int) ($paise['gross'] ?? 0);
        $netP   = (int) ($paise['net_pay'] ?? 0);
        $dedP   = (int) ($paise['total_deductions'] ?? 0);
        $checks[] = [
            'name'     => 'gross == net + deductions',
            'expected' => $grossP,
            'actual'   => $netP + $dedP,
            'passed'   => $grossP === ($netP + $dedP),
        ];

        // The deduction components must add up to the deduction total.
        $sumP = 0;
        foreach (['pf_employee', 'esi_employee', 'tds', 'professional_tax', 'other_deductions'] as $k) {
            $sumP += (int) ($paise[$k] ?? 0);
        }
        $checks[] = [
            'name'     => 'deduction components == total deductions',
            'expected' => $dedP,
            'actual'   => $sumP,
            'passed'   => $dedP === $sumP,
        ];

        // And the trace must agree with the rupee figures actually stored on
        // the slip — otherwise the trace explains a different payslip.
        foreach (['gross' => 'gross', 'net_pay' => 'net_pay', 'total_deductions' => 'total_deductions'] as $slipKey => $paiseKey) {
            if (!isset($slip[$slipKey])) continue;
            $storedP = (int) round(((float) $slip[$slipKey]) * 100);
            $checks[] = [
                'name'     => "trace {$paiseKey} == stored slip {$slipKey}",
                'expected' => $storedP,
                'actual'   => (int) ($paise[$paiseKey] ?? 0),
                'passed'   => $storedP === (int) ($paise[$paiseKey] ?? 0),
            ];
        }

        $allPassed = true;
        foreach ($checks as $c) { if (!$c['passed']) { $allPassed = false; break; } }

        return [
            'available'  => true,
            'consistent' => $allPassed,
            'checks'     => $checks,
            'reason'     => $allPassed ? '' : 'The trace does not reconcile with the stored payslip.',
        ];
    }
}

if (!function_exists('payroll_build_trace')) {
    /**
     * Build the ordered calculation chain for one payslip.
     *
     * @param array $slip The stored payslip document, frozen.
     * @return array{steps: array, consistency: array, evidence: array}
     */
    function payroll_build_trace(array $slip): array
    {
        $t        = is_array($slip['_calculation_trace'] ?? null) ? $slip['_calculation_trace'] : [];
        $paise    = is_array($t['paise'] ?? null)  ? $t['paise']  : [];
        $bases    = is_array($t['bases'] ?? null)  ? $t['bases']  : [];
        $attSnap  = is_array($slip['_attendance_snapshot'] ?? null) ? $slip['_attendance_snapshot'] : null;
        $salSnap  = is_array($slip['_salary_snapshot'] ?? null)     ? $slip['_salary_snapshot']     : null;
        $adjBlock = is_array($slip['_payroll_adjustments'] ?? null) ? $slip['_payroll_adjustments'] : null;

        $p = function (string $k) use ($paise) {
            return isset($paise[$k]) ? (int) $paise[$k] : null;
        };

        $steps = [];

        // ── A. Employee and employment window ──
        $win = is_array($slip['staff_window'] ?? null) ? $slip['staff_window'] : [];
        $steps[] = [
            'key'   => 'employment',
            'title' => 'Employee and employment window',
            'facts' => array_filter([
                'Employee'          => (string) ($slip['staff_name'] ?? $slip['staff_id'] ?? ''),
                'Department'        => (string) ($slip['department'] ?? ''),
                'Period'            => trim((string) ($slip['month'] ?? '') . ' ' . (string) ($slip['year'] ?? '')),
                'Joined'            => (string) ($win['joinDate'] ?? ''),
                'Exit'              => (string) ($win['exitDate'] ?? ''),
                'Employed from day' => $win['startDay'] ?? null,
                'Employed to day'   => $win['endDay'] ?? null,
            ], function ($v) { return $v !== null && $v !== ''; }),
        ];

        // ── B. Compensation, from the frozen structure ──
        $compFacts = [];
        if ($salSnap !== null) {
            foreach (['basic' => 'Basic', 'hra' => 'HRA', 'da' => 'DA', 'ta' => 'TA',
                      'medical' => 'Medical', 'other_allowances' => 'Other allowances'] as $k => $label) {
                if (isset($salSnap[$k]) && (float) $salSnap[$k] != 0.0) {
                    $compFacts[$label . ' (contracted)'] = (float) $salSnap[$k];
                }
            }
        }
        $steps[] = [
            'key'       => 'compensation',
            'title'     => 'Contracted compensation',
            'facts'     => $compFacts,
            'source'    => $salSnap !== null ? 'frozen salary snapshot' : null,
            'unavailable' => $salSnap === null
                ? 'No salary snapshot was recorded for this payslip, so the contracted structure cannot be shown from frozen evidence.'
                : null,
        ];

        // ── C. Attendance ──
        $attFacts = [
            'Working days'    => $slip['working_days'] ?? null,
            'Days worked'     => $slip['days_worked'] ?? null,
            'Days absent'     => $slip['days_absent'] ?? null,
            'Half days'       => $slip['half_days'] ?? null,
            'Paid leave days' => $slip['paid_leave_days'] ?? null,
            'LOP days'        => $slip['lwp_days'] ?? null,
        ];
        if ($attSnap !== null) {
            $attFacts['Attendance record'] = (string) ($attSnap['day_wise'] ?? '');
            $attFacts['Unmarked-day policy'] = (string) ($attSnap['vacant_treatment'] ?? '');
            if (function_exists('payroll_lwp_days_from_mask')) {
                $d = payroll_lwp_days_from_mask($attSnap['lwp_mask'] ?? null);
                if ($d['full']) $attFacts['LOP days (full)'] = implode(', ', $d['full']);
                if ($d['half']) $attFacts['LOP days (half)'] = implode(', ', $d['half']);
            }
        }
        $steps[] = [
            'key'         => 'attendance',
            'title'       => 'Attendance and loss of pay',
            'facts'       => array_filter($attFacts, function ($v) { return $v !== null && $v !== ''; }),
            'source'      => $attSnap !== null ? 'frozen attendance snapshot' : null,
            'unavailable' => $attSnap === null
                ? 'No attendance snapshot was recorded for this payslip (generated before the snapshot existed). Day-level evidence is not available and is not reconstructed.'
                : null,
        ];

        // ── D. Proration ──
        $steps[] = [
            'key'   => 'proration',
            'title' => 'Proration applied to earnings',
            'facts' => array_filter([
                'Absent fraction'  => $bases['absent_fraction'] ?? null,
                'Retention ratio'  => $bases['retention_ratio'] ?? null,
                'Deduction days'   => $slip['deduction_days'] ?? null,
                'Daily salary'     => $slip['daily_salary'] ?? null,
                'LOP deduction'    => $slip['lwp_deduction'] ?? null,
            ], function ($v) { return $v !== null && $v !== ''; }),
            'note' => 'Earnings are scaled by the retention ratio; flat deductions are not.',
        ];

        // ── E. Earned earnings ──
        //
        // A ZERO IS SHOWN whenever the contracted counterpart was non-zero.
        // Suppressing zeros hid the single most important line in the trace:
        // "contracted 14,000 → earned 0" is the whole explanation of a
        // fully-absent month, and dropping it left an empty step between the
        // contracted figures and a gross of 0, forcing the reader to infer the
        // proration rather than see it. Found in UAT.
        $earnFacts = [];
        foreach (['basic' => 'Basic', 'hra' => 'HRA', 'da' => 'DA', 'ta' => 'TA',
                  'medical' => 'Medical', 'other_allowances' => 'Other allowances'] as $k => $label) {
            $earned     = isset($slip[$k]) ? (float) $slip[$k] : null;
            $contracted = ($salSnap !== null && isset($salSnap[$k])) ? (float) $salSnap[$k] : 0.0;

            if ($earned === null) continue;
            // Show it if anything was earned, OR if something was contracted and
            // nothing was earned — the second case is the one that explains.
            if ($earned != 0.0 || $contracted != 0.0) {
                $earnFacts[$label . ' (earned)'] = $earned;
            }
        }
        if (!empty($slip['extra_worked_pay'])) {
            $earnFacts['Extra worked pay'] = (float) $slip['extra_worked_pay'];
        }
        $steps[] = ['key' => 'earnings', 'title' => 'Earnings after proration', 'facts' => $earnFacts];

        // ── F. Payroll-period adjustments ──
        if ($adjBlock !== null) {
            $adjFacts = ['Gross before adjustments' => $adjBlock['original_gross'] ?? null];
            foreach (($adjBlock['lines'] ?? []) as $line) {
                if (!is_array($line)) continue;
                $sign = (($line['kind'] ?? '') === 'earning') ? '+' : '−';
                $adjFacts[$sign . ' ' . ($line['label'] ?? $line['type'] ?? 'Adjustment')] = $line['amount'] ?? null;
            }
            $adjFacts['Gross after adjustments'] = $adjBlock['final_gross'] ?? null;
            $steps[] = [
                'key'    => 'adjustments',
                'title'  => 'Payroll-period adjustments',
                'facts'  => array_filter($adjFacts, function ($v) { return $v !== null; }),
                'note'   => 'Applied by ' . (string) ($adjBlock['applied_by'] ?? '?')
                            . '. An added earning also raises the ESI and TDS base.',
            ];
        }

        // ── G. Gross ──
        $steps[] = [
            'key'   => 'gross',
            'title' => 'Gross earnings',
            'facts' => array_filter(['Gross' => payroll_trace_money($p('gross'))],
                function ($v) { return $v !== null; }),
        ];

        // ── H. Statutory ──
        // Where policy is absent the trace says so. It never presents a rate as
        // though a policy had been configured.
        $statSource = (string) ($t['statutory_source'] ?? '');
        $statFacts  = [];
        if ($statSource !== '') {
            $statFacts['Policy source'] = $statSource === 'statutoryConfig'
                ? 'Configured statutory policy'
                : 'Salary structure rates (no statutory policy configured)';
        }
        $statFacts['PF base (earned basic)'] = payroll_trace_money($bases['pf_base_paise'] ?? null);
        $statFacts['PF rate %']              = $bases['pf_employee_pct'] ?? null;
        $statFacts['PF (employee)']          = payroll_trace_money($p('pf_employee'));
        $statFacts['ESI base (earned gross)']= payroll_trace_money($bases['esi_base_paise'] ?? null);
        $statFacts['ESI rate %']             = $bases['esi_employee_pct'] ?? null;
        $statFacts['ESI (employee)']         = payroll_trace_money($p('esi_employee'));
        $statFacts['TDS base (earned gross)']= payroll_trace_money($bases['tds_base_paise'] ?? null);
        $statFacts['TDS rate %']             = $bases['tds_pct'] ?? null;
        $statFacts['TDS']                    = payroll_trace_money($p('tds'));
        $statFacts['Professional tax']       = payroll_trace_money($p('professional_tax'));

        $steps[] = [
            'key'   => 'statutory',
            'title' => 'Statutory deductions',
            'facts' => array_filter($statFacts, function ($v) { return $v !== null; }),
            'note'  => $statSource === 'structure'
                ? 'No tenant statutory policy is configured, so no PF ceiling or ESI threshold was applied. '
                  . 'The rates come from the salary structure.'
                : null,
            'blocked' => $statSource === '' ? 'Statutory detail was not recorded for this payslip.' : null,
        ];

        // ── I. Other deductions ──
        $steps[] = [
            'key'   => 'other_deductions',
            'title' => 'Other deductions',
            'facts' => array_filter([
                'Other deductions' => payroll_trace_money($p('other_deductions')),
            ], function ($v) { return $v !== null; }),
        ];

        // Deferred amounts — deductions that exceeded earned gross and were NOT
        // taken. Shown because an employee asking "why was PF not deducted"
        // deserves the real answer.
        $def = is_array($t['deferred'] ?? null) ? $t['deferred'] : [];
        $defFacts = array_filter([
            'Other deductions not taken' => $def['other_deductions'] ?? null,
            'Professional tax not taken' => $def['professional_tax'] ?? null,
            'Statutory not taken'        => $def['statutory'] ?? null,
        ], function ($v) { return $v !== null && (float) $v != 0.0; });
        if ($defFacts) {
            $steps[] = [
                'key'   => 'deferred',
                'title' => 'Amounts not deducted',
                'facts' => $defFacts,
                'note'  => 'These exceeded the earnings available this period, so they were not deducted.',
            ];
        }

        // ── J. Net ──
        $steps[] = [
            'key'   => 'net',
            'title' => 'Net pay',
            'facts' => array_filter([
                'Gross'            => payroll_trace_money($p('gross')),
                'Total deductions' => payroll_trace_money($p('total_deductions')),
                'Net pay'          => payroll_trace_money($p('net_pay')),
            ], function ($v) { return $v !== null; }),
        ];

        // ── K. Employer cost ──
        $pfEr  = $p('pf_employer');
        $esiEr = $p('esi_employer');
        $grossP = $p('gross');
        $ctc = ($grossP === null) ? null : $grossP + (int) $pfEr + (int) $esiEr;
        $steps[] = [
            'key'   => 'employer_cost',
            'title' => 'Employer cost',
            'facts' => array_filter([
                'PF (employer)'  => payroll_trace_money($pfEr),
                'ESI (employer)' => payroll_trace_money($esiEr),
                'Cost to company'=> payroll_trace_money($ctc),
            ], function ($v) { return $v !== null; }),
            'note' => 'Employer contributions are a cost, not a deduction — they do not reduce net pay.',
        ];

        // ── Warnings the engine itself raised ──
        $warnings = is_array($t['warnings'] ?? null) ? $t['warnings'] : [];

        return [
            'steps'       => $steps,
            'warnings'    => $warnings,
            'consistency' => payroll_trace_consistency($slip),
            'evidence'    => [
                'salary_snapshot'     => $salSnap !== null,
                'attendance_snapshot' => $attSnap !== null,
                'adjustments'         => $adjBlock !== null,
                'calculation_trace'   => !empty($t),
                // Recorded as a known dependency, never faked: the statutory
                // policy in force cannot be frozen until W2 policy exists.
                'statutory_snapshot'  => false,
            ],
        ];
    }
}
