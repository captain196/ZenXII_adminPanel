<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Time_helper — pure-function time-conversion utilities used across the
 * timetable subsystem. Static methods (no state, no init).
 *
 * Phase 5.5: extracted verbatim from Academic.php / Timetable_service
 * private helpers. Same behaviour, single source of truth.
 *
 * USAGE
 *   Time_helper::timeToMinutes('9:30AM')          → 570
 *   Time_helper::minutesToTime(570)               → '9:30AM'
 *   Time_helper::toAmpm('14:30')                  → '2:30PM'
 *   Time_helper::computePeriodTimes('9:00AM', 6, 45, [
 *       ['after_period' => 3, 'duration' => 15],
 *   ])
 *   → [
 *       ['start' => '9:00AM',  'end' => '9:45AM'],
 *       ['start' => '9:45AM',  'end' => '10:30AM'],
 *       ['start' => '10:30AM', 'end' => '11:15AM'],
 *       ['start' => '11:30AM', 'end' => '12:15PM'],   // recess after period 3
 *       ...
 *     ]
 */
class Time_helper
{
    /**
     * Accept both 24h "HH:mm" and 12h "h:mmAM/PM"; return minutes since midnight.
     */
    public static function timeToMinutes(string $time): int
    {
        $time = strtoupper(trim($time));
        if (preg_match('/^(\d{1,2}):(\d{2})\s*(AM|PM)$/', $time, $m)) {
            $h = (int) $m[1];
            $min = (int) $m[2];
            if ($m[3] === 'PM' && $h !== 12) $h += 12;
            if ($m[3] === 'AM' && $h === 12) $h = 0;
            return $h * 60 + $min;
        }
        if (strpos($time, ':') !== false) {
            $parts = explode(':', $time);
            return ((int) $parts[0] * 60) + (int) $parts[1];
        }
        return 0;
    }

    /**
     * Inverse of timeToMinutes — emit "h:mmAM/PM" form (matches Firestore storage).
     */
    public static function minutesToTime(int $minutes): string
    {
        $h    = intdiv($minutes, 60);
        $m    = $minutes % 60;
        $ampm = $h >= 12 ? 'PM' : 'AM';
        $h12  = $h % 12 ?: 12;
        return sprintf('%d:%02d%s', $h12, $m, $ampm);
    }

    /**
     * Convert a 24-hour "HH:mm" string to "h:mmAM/PM". Used when persisting
     * settings where the form posts 24h but Firestore stores AM/PM.
     */
    public static function toAmpm(string $time24): string
    {
        $dt = \DateTime::createFromFormat('H:i', trim($time24));
        return $dt ? $dt->format('g:iA') : $time24;
    }

    /**
     * Build per-period start/end times from settings:
     *   $startTime    — first period start (e.g., '9:00AM')
     *   $numPeriods   — total periods per day
     *   $periodLen    — minutes per period (e.g., 45.0)
     *   $recesses     — list of ['after_period' => N, 'duration' => mins]
     * Returns [{start, end}, ...] in display form.
     */
    public static function computePeriodTimes(string $startTime, int $numPeriods, float $periodLen, array $recesses): array
    {
        $times = [];
        $startMin = self::timeToMinutes($startTime);
        $currentMin = $startMin;

        // Build recess lookup: after_period => duration_minutes
        $recessAfter = [];
        foreach ($recesses as $r) {
            if (!is_array($r)) continue;
            $ap  = $r['after_period']  ?? $r['afterPeriod'] ?? null;
            $dur = (int) ($r['duration'] ?? $r['durationMin'] ?? 0);
            if ($ap !== null && $dur > 0) $recessAfter[(int) $ap] = $dur;
        }

        for ($i = 0; $i < $numPeriods; $i++) {
            $start = $currentMin;
            $end   = $currentMin + (int) $periodLen;
            $times[] = [
                'start' => self::minutesToTime($start),
                'end'   => self::minutesToTime($end),
            ];
            $currentMin = $end;

            $periodNum = $i + 1;
            if (isset($recessAfter[$periodNum])) {
                $currentMin += $recessAfter[$periodNum];
            }
        }
        return $times;
    }
}
