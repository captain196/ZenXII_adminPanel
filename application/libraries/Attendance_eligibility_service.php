<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Attendance_eligibility_service — PHASE B2.
 *
 * PURE, side-effect-free attendance-eligibility / shortage calculation.
 * The controller does the Firestore reads (canonical `attendanceSummary`) and
 * the policy load; this class only turns dayWise strings + a policy into a
 * derived result. The percentage is ALWAYS derived here — never stored as an
 * authoritative mutable field (a cache, if ever added, must be clearly derived
 * and invalidated).
 *
 * POLICY IS TENANT/BOARD-CONFIGURABLE, NOT HARDCODED. Research (CBSE Bye-Laws
 * 13/14 = 75% for X/XII condonable to 60% to a ~1-Jan cutoff over working days;
 * CISCE per-year to ~15-Feb; Maharashtra per-term) shows the threshold, floor,
 * window, cutoff and scope all vary by board — so all are policy fields with
 * safe defaults that match ZenX's existing %-formula ((P+T)/(P+A+L+T)).
 */
class Attendance_eligibility_service
{
    const S_ELIGIBLE  = 'eligible';    // >= minPercent
    const S_WARNING   = 'warning';     // >= condonableFloor and < minPercent (condonable shortfall)
    const S_SHORTAGE  = 'shortage';    // < condonableFloor
    const S_NA        = 'not_applicable'; // policy excludes this class, or zero working days

    /** Safe defaults — match existing ZenX %-semantics; no board hardcoding. */
    public static function defaults(): array
    {
        return [
            'minPercent'         => 75,     // eligibility threshold
            'condonableFloor'    => 60,     // shortfall condonable down to this
            'warningThreshold'   => 75,     // warn at/below this (defaults to minPercent)
            'includeTardyPresent'=> true,   // T counts toward numerator (existing formula)
            'leaveTreatment'     => 'denominator', // 'denominator' (L lowers %, existing) | 'excused' (L excluded from both) | 'present' (L counts present)
            'applicableClasses'  => [],     // [] = all classes; else list of classKey names (e.g. "Class 10th")
            'cutoffDate'         => '',      // '' = up to today/session end; else YYYY-MM-DD
            'boardLabel'         => '',      // informational (CBSE/CISCE/State/…)
        ];
    }

    /** Validate + normalize a proposed policy. Returns ['ok'=>bool,'errors'=>[],'policy'=>merged]. */
    public static function validate(array $in): array
    {
        $p = array_merge(self::defaults(), $in);
        $errors = [];

        $p['minPercent']       = (int) $p['minPercent'];
        $p['condonableFloor']  = (int) $p['condonableFloor'];
        $p['warningThreshold'] = (int) $p['warningThreshold'];
        if ($p['minPercent'] < 1 || $p['minPercent'] > 100)        $errors[] = 'minPercent must be 1-100.';
        if ($p['condonableFloor'] < 0 || $p['condonableFloor'] > 100) $errors[] = 'condonableFloor must be 0-100.';
        if ($p['condonableFloor'] > $p['minPercent'])              $errors[] = 'condonableFloor cannot exceed minPercent.';
        if ($p['warningThreshold'] < 1 || $p['warningThreshold'] > 100) $errors[] = 'warningThreshold must be 1-100.';

        $p['includeTardyPresent'] = filter_var($p['includeTardyPresent'], FILTER_VALIDATE_BOOLEAN);
        if (!in_array($p['leaveTreatment'], ['denominator', 'excused', 'present'], true)) {
            $errors[] = "leaveTreatment must be one of: denominator, excused, present.";
        }
        if (!is_array($p['applicableClasses'])) $errors[] = 'applicableClasses must be a list.';
        else $p['applicableClasses'] = array_values(array_filter(array_map(fn($c) => trim((string) $c), $p['applicableClasses']), fn($c) => $c !== ''));
        if ($p['cutoffDate'] !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $p['cutoffDate'])) {
            $errors[] = 'cutoffDate must be blank or YYYY-MM-DD.';
        }
        return ['ok' => empty($errors), 'errors' => $errors, 'policy' => $p];
    }

    /** Is a class in scope? (empty applicableClasses = all.) */
    public static function classInScope(string $classKey, array $policy): bool
    {
        $list = $policy['applicableClasses'] ?? [];
        if (!is_array($list) || empty($list)) return true;
        foreach ($list as $c) if (strcasecmp(trim((string) $c), trim($classKey)) === 0) return true;
        return false;
    }

    /**
     * Compute one student's eligibility from their per-month dayWise strings.
     *
     * @param array $monthsDayWise  [ 'YYYY-MM' => dayWiseString, ... ]  (canonical summaries)
     * @param array $policy         validated policy
     * @param string $classKey      the student's class (for scope check)
     * @return array metrics + status
     */
    public static function computeStudent(array $monthsDayWise, array $policy, string $classKey = ''): array
    {
        $p = array_merge(self::defaults(), $policy);
        $cut = ($p['cutoffDate'] !== '') ? (string) $p['cutoffDate'] : '';

        $present = $absent = $leave = $tardy = 0;
        foreach ($monthsDayWise as $mk => $dw) {
            if (!is_string($dw) || $dw === '') continue;
            if (!preg_match('/^(\d{4})-(\d{2})$/', (string) $mk, $m)) continue;
            $year = (int) $m[1]; $mon = (int) $m[2];
            for ($i = 0; $i < strlen($dw); $i++) {
                $day = $i + 1;
                if ($cut !== '') {
                    $d = sprintf('%04d-%02d-%02d', $year, $mon, $day);
                    if ($d > $cut) continue;   // beyond cutoff — not yet counted
                }
                switch ($dw[$i]) {
                    case 'P': $present++; break;
                    case 'A': $absent++;  break;
                    case 'L': $leave++;   break;
                    case 'T': $tardy++;   break;
                    // 'H' (holiday) and 'V' (unmarked / pre-admission) never enter the denominator
                }
            }
        }

        // Numerator / denominator per policy.
        $num = $present + ($p['includeTardyPresent'] ? $tardy : 0);
        switch ($p['leaveTreatment']) {
            case 'excused':  $den = $present + $absent + $tardy; break;                 // L excluded both sides
            case 'present':  $num += $leave; $den = $present + $absent + $leave + $tardy; break; // L counts present
            default:         $den = $present + $absent + $leave + $tardy;               // L in denominator (existing)
        }

        if (!self::classInScope($classKey, $p)) {
            return self::_result(self::S_NA, 0.0, $present, $absent, $leave, $tardy, $den, $num, $p, 'class not in eligibility scope');
        }
        if ($den <= 0) {
            return self::_result(self::S_NA, 0.0, $present, $absent, $leave, $tardy, 0, 0, $p, 'no working days yet');
        }

        $pct  = round($num / $den * 100, 1);
        $warn = (int) ($p['warningThreshold'] ?? $p['minPercent']);
        $note = '';
        // ELIG-WARN-1: warningThreshold is now honoured. Bands (top→bottom):
        //   pct >= max(minPercent, warningThreshold)         → ELIGIBLE
        //   minPercent <= pct < warningThreshold             → WARNING (eligible but approaching)
        //   condonableFloor <= pct < minPercent              → WARNING (condonable shortfall)
        //   pct < condonableFloor                            → SHORTAGE
        // Backward-compatible: when warningThreshold <= minPercent (the default,
        // both 75) the "approaching" band is empty and behaviour is unchanged.
        if ($pct < $p['condonableFloor']) {
            $status = self::S_SHORTAGE;
        } elseif ($pct < $p['minPercent']) {
            $status = self::S_WARNING;               // condonable shortfall
        } elseif ($pct < $warn) {
            $status = self::S_WARNING;               // eligible but approaching the warning threshold
            $note   = 'approaching shortage (below warning threshold)';
        } else {
            $status = self::S_ELIGIBLE;
        }

        return self::_result($status, $pct, $present, $absent, $leave, $tardy, $den, $num, $p, $note);
    }

    private static function _result(string $status, float $pct, int $pr, int $ab, int $lv, int $td, int $work, int $num, array $p, string $note): array
    {
        // shortfall in DAYS to reach minPercent (0 if already eligible / n/a)
        $needDays = 0;
        if ($work > 0 && $pct < $p['minPercent']) {
            // solve (num + x) / (work + x) >= min%  → x >= (min*work - 100*num)/(100 - min)
            $min = $p['minPercent'];
            if ($min < 100) $needDays = (int) ceil(max(0, ($min * $work - 100 * $num) / (100 - $min)));
        }
        return [
            'status'          => $status,
            'percentage'      => $pct,
            'present'         => $pr,
            'absent'          => $ab,
            'leave'           => $lv,
            'tardy'           => $td,
            'workingDays'     => $work,
            'requiredPercent' => $p['minPercent'],
            'condonableFloor' => $p['condonableFloor'],
            'daysToEligible'  => $needDays,
            'note'            => $note,
        ];
    }
}
